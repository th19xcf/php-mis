<?php

namespace App\Services\Person;

use App\Models\Mcommon;

/**
 * 人员主档导入服务（邀约导入接入人员主档）
 *
 * 在通用工作台导入流程中（临时表校验通过后、正式导入前），
 * 对临时表数据做人员主档批量裁决：
 *
 * 阶段一（checkTempTableDedup）：批量查重
 * - 给临时表加 _seq 自增行号列（与前端 importData 数组顺序对齐，索引+1）
 * - 证件号批量 IN 查 hr_person（硬命中）+ 手机号批量 IN 查（PHP 内匹配姓名，软命中）
 * - 批内分组去重：同一次导入中同一人（同证件号或同姓名+手机）只裁决一次
 * - 返回软命中行明细（行号/行数据/候选档案），由前端弹决策页确认
 *
 * 阶段二（applyPersonToTempTable）：按决策落地
 * - 每组按决策挂接（指定人员编码）或新建（批量发号 + 批量建档）
 * - 无决策的组自动裁决：硬命中挂档 / 唯一软命中挂档 / 无命中新建
 * - 给临时表加 人员编码 列并按组回填
 * - importFromTempTable 检测到临时表额外列且目标表有该列时自动写入（已有机制）
 *
 * 两阶段无状态：阶段一仅返回行号级软命中清单，阶段二重新提交完整数据+决策，
 * 临时表由导入流程重建，行号与数据顺序天然对齐，无跨请求状态依赖。
 *
 * 主档字段清单由调用方传入（来自 def_import_column.字段归属表='hr_person'，
 * 配置驱动），与页面新增路径（def_query_column.字段归属表）同语义。
 */
class PersonImportService
{
    private Mcommon $model;

    /** 前端决策值：确认新建新主档 */
    public const DECISION_NEW = '__NEW__';

    public function __construct()
    {
        $this->model = new Mcommon();
    }

    /**
     * 确保临时表有 _seq 自增行号列（与 importData 数组顺序对齐）
     *
     * createTempTable 建表无主键，INSERT 按数组顺序；
     * ALTER ADD AUTO_INCREMENT PRIMARY KEY 按当前行顺序（=插入顺序）编号，
     * 故 _seq = importData 索引 + 1。
     */
    private function ensureSeqColumn(string $tmpTable): bool
    {
        $db = $this->model->getDb();
        $sql = sprintf('ALTER TABLE `%s` ADD COLUMN `_seq` INT NOT NULL AUTO_INCREMENT PRIMARY KEY', $tmpTable);
        try {
            $db->query($sql);
            return true;
        } catch (\Throwable $e) {
            // 1060/1068: 列/主键已存在（同请求重复调用），视为成功
            // CI4 对 DDL 失败直接抛 DatabaseException，须捕获后取错误码判断
            $errno = (int) ($db->error()['code'] ?? 0);
            return in_array($errno, [1060, 1068], true);
        }
    }

    /**
     * 读取临时表全部数据行（含行号与身份字段）
     *
     * 按临时表实际列过滤请求字段：导入模板不必含全部 PERSON_FIELDS
     * （如邀约模板无"学历"/"工作履历"），缺列自动跳过而非 SQL 报错。
     *
     * @return array<int, array<string, mixed>> 以 _seq 为键的行映射
     */
    private function loadTempRows(string $tmpTable, array $fields): array
    {
        // 防注入：临时表名由系统生成（tmp_功能码_菜单_工号），仍做白名单字符校验
        if (!preg_match('/^[A-Za-z0-9_]+$/', $tmpTable)) {
            return [];
        }
        $db = $this->model->getDb();
        $colsResult = $db->query(sprintf('SHOW COLUMNS FROM `%s`', $tmpTable));
        $actualCols = [];
        if ($colsResult) {
            foreach ($colsResult->getResultArray() as $c) {
                $actualCols[] = (string) ($c['Field'] ?? '');
            }
        }
        $fields = array_values(array_intersect($fields, $actualCols));

        $quoted = array_map(static fn($f) => sprintf('`%s`', $f), $fields);
        $sql = sprintf('SELECT `_seq`%s FROM `%s`', $quoted ? ', ' . implode(', ', $quoted) : '', $tmpTable);
        $rows = $this->model->select($sql)->getResultArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['_seq']] = $row;
        }
        ksort($map);
        return $map;
    }

    /**
     * 批内分组：同一人（同证件号优先，其次同姓名+手机号）归为一组
     *
     * 导入模板允许同一人多次邀约（滤重字段=手机号码+邀约次数），
     * 同组共享一次主档裁决，避免一批内重复建档。
     *
     * 姓名或手机号为空的行不参与分组（主档建档要求姓名+手机必填，
     * 与手工新增一致；此类行多为 Excel 噪声行，被导入条件过滤不进目标表）。
     *
     * @return array<string, array<int, array>> 组键 => [_seq => 行数据]
     */
    private function groupRows(array $rows): array
    {
        $groups = [];
        foreach ($rows as $seq => $row) {
            $name = trim((string) ($row['姓名'] ?? ''));
            $mobile = trim((string) ($row['手机号码'] ?? ''));
            $idcard = trim((string) ($row['身份证号'] ?? ''));

            // 空姓名或空手机号：不建档、不挂档（人员编码列留空）
            if ($name === '' || $mobile === '') {
                continue;
            }

            $key = $idcard !== '' ? 'id:' . $idcard : 'nm:' . $name . '|' . $mobile;
            $groups[$key][$seq] = $row;
        }
        return $groups;
    }

    /**
     * 批量查询主档候选：证件号硬命中 + 手机号软命中
     *
     * @return array{hardById: array<string, array>, softByMobile: array<string, array>}
     *         hardById: 证件号 => 主档行；softByMobile: 手机号 => 主档行列表（PHP 内再匹配姓名）
     */
    private function loadPersonCandidates(array $rows): array
    {
        $idcards = [];
        $mobiles = [];
        foreach ($rows as $row) {
            $idcard = trim((string) ($row['身份证号'] ?? ''));
            $mobile = trim((string) ($row['手机号码'] ?? ''));
            if ($idcard !== '') {
                $idcards[] = $idcard;
            }
            if ($mobile !== '') {
                $mobiles[] = $mobile;
            }
        }
        $idcards = array_values(array_unique($idcards));
        $mobiles = array_values(array_unique($mobiles));

        $hardById = [];
        $softByMobile = [];

        if (!empty($idcards)) {
            $in = implode(',', array_map(fn($v) => $this->model->quote($v), $idcards));
            $sql = "select 人员编码,姓名,身份证号,手机号码,性别,属地 from hr_person
                    where 身份证号 in ({$in}) and 有效标识='1' and 删除标识='0'
                      and (合并至='' or 合并至 is null)";
            foreach ($this->model->select($sql)->getResultArray() as $row) {
                $hardById[trim((string) $row['身份证号'])] = $row;
            }
        }

        if (!empty($mobiles)) {
            $in = implode(',', array_map(fn($v) => $this->model->quote($v), $mobiles));
            $sql = "select 人员编码,姓名,身份证号,手机号码,性别,属地 from hr_person
                    where 手机号码 in ({$in}) and 有效标识='1' and 删除标识='0'
                      and (合并至='' or 合并至 is null)";
            foreach ($this->model->select($sql)->getResultArray() as $row) {
                $softByMobile[trim((string) $row['手机号码'])][] = $row;
            }
        }

        return ['hardById' => $hardById, 'softByMobile' => $softByMobile];
    }

    /**
     * 阶段一：批量查重，返回软命中行清单（供前端决策页展示）
     *
     * @param string $tmpTable     临时表名
     * @param array  $personFields 主档字段清单（def_import_column.字段归属表='hr_person'）
     * @param string $dateField    业务日期字段（def_import_config.业务日期字段，发号分桶用）
     * @return array{hasError: bool, message: string, softRows: array}
     *         softRows: [{seq, name, mobile, idcard, matches: [候选主档行]}]
     */
    public function checkTempTableDedup(string $tmpTable, array $personFields, string $dateField = '邀约日期'): array
    {
        try {
            if (!$this->ensureSeqColumn($tmpTable)) {
                return ['hasError' => true, 'message' => '临时表加行号列失败', 'softRows' => []];
            }

            // 查重/分组必需字段（姓名、手机号码、身份证号）与主档字段清单、业务日期合并读取（去重；
            // 日期字段模板缺列时由 loadTempRows 实际列过滤自动跳过，发号回退当天）
            $rows = $this->loadTempRows($tmpTable, array_values(array_unique(array_merge(
                ['姓名', '手机号码', '身份证号', $dateField],
                $personFields
            ))));

            // 基本校验：主档裁决要求姓名+手机号码（与手工新增一致）
            foreach ($rows as $seq => $row) {
                $name = trim((string) ($row['姓名'] ?? ''));
                $mobile = trim((string) ($row['手机号码'] ?? ''));
                // 导入条件 姓名!='' 已过滤空姓名行；此处仅拦手机号缺失
                if ($name !== '' && $mobile === '') {
                    return [
                        'hasError' => true,
                        'message' => sprintf('第 %d 行（%s）手机号码为空，人员主档建档要求姓名与手机号码不能为空', $seq, $name),
                        'softRows' => [],
                    ];
                }
            }

            $candidates = $this->loadPersonCandidates($rows);
            $groups = $this->groupRows($rows);

            $softRows = [];
            $handledSeqs = [];
            foreach ($groups as $rowsOfGroup) {
                $first = reset($rowsOfGroup);
                $name = trim((string) ($first['姓名'] ?? ''));
                $mobile = trim((string) ($first['手机号码'] ?? ''));
                $idcard = trim((string) ($first['身份证号'] ?? ''));

                // 硬命中：整组直接挂档，无需决策
                if ($idcard !== '' && isset($candidates['hardById'][$idcard])) {
                    continue;
                }

                // 软命中：姓名+手机匹配
                $matches = [];
                if ($name !== '' && $mobile !== '' && isset($candidates['softByMobile'][$mobile])) {
                    foreach ($candidates['softByMobile'][$mobile] as $p) {
                        if (trim((string) $p['姓名']) === $name) {
                            $matches[] = $p;
                        }
                    }
                }

                if (!empty($matches)) {
                    // 组内每个行号都返回（前端逐行决策展示），matches 共享
                    foreach (array_keys($rowsOfGroup) as $seq) {
                        $softRows[] = [
                            'seq'     => $seq,
                            'name'    => $name,
                            'mobile'  => $mobile,
                            'idcard'  => $idcard,
                            'matches' => $matches,
                        ];
                        $handledSeqs[] = $seq;
                    }
                }
                // 无命中：整组自动新建，无需决策
            }

            return ['hasError' => false, 'message' => '', 'softRows' => $softRows];
        } catch (\Throwable $e) {
            log_message('error', '[PersonImportService] 批量查重失败: ' . $e->getMessage());
            return ['hasError' => true, 'message' => '人员主档批量查重失败: ' . $e->getMessage(), 'softRows' => []];
        }
    }

    /**
     * 过滤 hr_person 实际存在的字段（防 字段归属表 配置了主档不存在的列）
     */
    private function filterPersonColumns(array $personFields): array
    {
        static $personCols = null;
        if ($personCols === null) {
            $personCols = [];
            $result = $this->model->select('SHOW COLUMNS FROM `hr_person`')->getResultArray();
            foreach ($result as $col) {
                $personCols[] = (string) ($col['Field'] ?? '');
            }
        }
        return array_values(array_intersect($personFields, $personCols));
    }

    /**
     * 批量生成人员编码起始号（按业务日期分组，一次 CALL 发整段号）
     *
     * @return array{prefix: string, start: int}
     */
    private function batchGenerateCodes(int $count, string $bizDate): array
    {
        $date = $bizDate ?: date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        // 走 getDb()->query() 而非 Mcommon::select()（select 有请求级缓存，CALL 须真正执行）
        $db = $this->model->getDb();
        $db->query("SET @seq = 0, @prefix = ''");
        $db->query(sprintf("CALL sp_生成人员编码(%d, '%s', @seq, @prefix)", $count, $date));
        $row = $db->query('SELECT @prefix AS p, @seq AS s')->getRowArray() ?: [];
        return [
            'prefix' => (string) ($row['p'] ?? ''),
            'start'  => (int) ($row['s'] ?? 0),
        ];
    }

    /**
     * 阶段二：按决策落地主档裁决，给临时表回填人员编码列
     *
     * @param string $tmpTable     临时表名
     * @param array  $personFields 主档字段清单（def_import_column.字段归属表='hr_person'）
     * @param array  $decisions    行号决策 {seq: 人员编码 | '__NEW__'}（前端决策页提交）
     * @param string $operator     操作人工号
     * @param string $dateField    业务日期字段（def_import_config.业务日期字段，发号分桶用）
     * @return array{success: bool, message: string, created: int, attached: int}
     */
    public function applyPersonToTempTable(string $tmpTable, array $personFields, array $decisions, string $operator, string $dateField = '邀约日期'): array
    {
        try {
            if (!$this->ensureSeqColumn($tmpTable)) {
                return ['success' => false, 'message' => '临时表加行号列失败', 'created' => 0, 'attached' => 0];
            }

            $rows = $this->loadTempRows($tmpTable, array_values(array_unique(array_merge(
                ['姓名', '手机号码', '身份证号', $dateField],
                $personFields
            ))));
            if (empty($rows)) {
                return ['success' => false, 'message' => '临时表无数据', 'created' => 0, 'attached' => 0];
            }

            // 防配置错误：过滤 hr_person 实际不存在的列（与页面新增路径 getTableColumns 过滤一致）
            $personFields = $this->filterPersonColumns($personFields);

            $candidates = $this->loadPersonCandidates($rows);
            $groups = $this->groupRows($rows);

            // 逐组裁决，产出：组人员编码（已知）或 待新建组
            $groupCodes = [];     // 组键 => 人员编码（挂接/决策）
            $createGroups = [];   // 组键 => 组行（待新建）
            foreach ($groups as $key => $rowsOfGroup) {
                $first = reset($rowsOfGroup);
                $name = trim((string) ($first['姓名'] ?? ''));
                $mobile = trim((string) ($first['手机号码'] ?? ''));
                $idcard = trim((string) ($first['身份证号'] ?? ''));

                // 1. 前端显式决策优先（组内任一行号有决策即生效）
                foreach (array_keys($rowsOfGroup) as $seq) {
                    $decision = trim((string) ($decisions[(string) $seq] ?? $decisions[$seq] ?? ''));
                    if ($decision === '') {
                        continue;
                    }
                    if ($decision === self::DECISION_NEW) {
                        $createGroups[$key] = $rowsOfGroup;
                    } else {
                        $groupCodes[$key] = $decision;
                    }
                    continue 2;
                }

                // 2. 硬命中（证件号）自动挂档
                if ($idcard !== '' && isset($candidates['hardById'][$idcard])) {
                    $groupCodes[$key] = (string) $candidates['hardById'][$idcard]['人员编码'];
                    continue;
                }

                // 3. 软命中（姓名+手机）自动挂唯一命中档
                $matches = [];
                if ($name !== '' && $mobile !== '' && isset($candidates['softByMobile'][$mobile])) {
                    foreach ($candidates['softByMobile'][$mobile] as $p) {
                        if (trim((string) $p['姓名']) === $name) {
                            $matches[] = $p;
                        }
                    }
                }
                if (count($matches) === 1) {
                    $groupCodes[$key] = (string) $matches[0]['人员编码'];
                    continue;
                }
                if (count($matches) > 1) {
                    // 多条软命中且无决策：理论上阶段一已拦截，兜底报错防误挂
                    return [
                        'success' => false,
                        'message' => sprintf('%s（%s）存在多个疑似主档，请逐行确认后重试', $name, $mobile),
                        'created' => 0,
                        'attached' => 0,
                    ];
                }

                // 4. 无命中：新建
                $createGroups[$key] = $rowsOfGroup;
            }

            $created = 0;
            $attached = 0;

            $db = $this->model->getDb();
            $db->transStart();

            try {
                // 5. 批量建档：按业务日期分桶发号（与 sp_候选人编码_导入前处理 同模式）
                if (!empty($createGroups)) {
                    $byDate = [];
                    foreach ($createGroups as $key => $rowsOfGroup) {
                        $first = reset($rowsOfGroup);
                        $date = trim((string) ($first[$dateField] ?? ''));
                        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                            $date = date('Y-m-d');
                        }
                        $byDate[$date][] = $key;
                    }

                    $now = date('Y-m-d H:i:s');
                    foreach ($byDate as $date => $keys) {
                        $codes = [];
                        $issue = $this->batchGenerateCodes(count($keys), $date);
                        for ($i = 0; $i < count($keys); $i++) {
                            $codes[] = $issue['prefix'] . str_pad((string) ($issue['start'] + $i), 3, '0', STR_PAD_LEFT);
                        }

                        foreach ($keys as $idx => $key) {
                            $first = reset($createGroups[$key]);
                            $row = ['人员编码' => $codes[$idx]];
                            foreach ($personFields as $f) {
                                if (array_key_exists($f, $first) && $first[$f] !== null && trim((string) $first[$f]) !== '') {
                                    $row[$f] = trim((string) $first[$f]);
                                }
                            }
                            $row['操作记录'] = '新增';
                            $row['操作来源'] = '批量导入';
                            $row['操作人员'] = $operator;
                            $row['开始操作时间'] = $now;
                            $row['操作时间'] = $now;
                            $row['有效标识'] = '1';
                            $row['删除标识'] = '0';

                            $fields = array_map(static fn($k) => sprintf('`%s`', $k), array_keys($row));
                            $values = array_map(
                                fn($k, $v) => ($k === '身份证号' && ($v === '' || $v === null)) ? 'NULL' : $this->model->quote((string) $v),
                                array_keys($row),
                                array_values($row)
                            );
                            $sql = sprintf('INSERT INTO hr_person (%s) VALUES (%s)', implode(',', $fields), implode(',', $values));
                            if ($this->model->exec($sql) <= 0) {
                                throw new \RuntimeException('人员主档批量建档失败');
                            }
                            $groupCodes[$key] = $codes[$idx];
                            $created++;
                        }
                    }
                }

                // 6. 临时表加人员编码列并按组回填
                try {
                    $db->query(sprintf('ALTER TABLE `%s` ADD COLUMN `人员编码` VARCHAR(14) NOT NULL DEFAULT ""', $tmpTable));
                } catch (\Throwable $e) {
                    // 1060: 列已存在（同请求重复落地），忽略继续回填
                    if ((int) ($db->error()['code'] ?? 0) !== 1060) {
                        throw $e;
                    }
                }
                foreach ($groups as $key => $rowsOfGroup) {
                    $code = $groupCodes[$key] ?? '';
                    if ($code === '') {
                        continue;
                    }
                    $seqs = implode(',', array_map(static fn($s) => (int) $s, array_keys($rowsOfGroup)));
                    $sql = sprintf('UPDATE `%s` SET `人员编码`=%s WHERE `_seq` IN (%s)', $tmpTable, $this->model->quote($code), $seqs);
                    if ($this->model->exec($sql) < 0) {
                        throw new \RuntimeException('临时表人员编码回填失败');
                    }
                    if (!isset($createGroups[$key])) {
                        $attached++;
                    }
                }

                // 7. 挂接组回填主档证件号（行内证件号非空且主档证件号为空时）
                $attachedCodeToIdcard = [];
                foreach ($groups as $key => $rowsOfGroup) {
                    if (isset($createGroups[$key])) {
                        continue; // 新建组建档时已带证件号
                    }
                    $first = reset($rowsOfGroup);
                    $idcard = trim((string) ($first['身份证号'] ?? ''));
                    $code = $groupCodes[$key] ?? '';
                    if ($idcard !== '' && $code !== '') {
                        $attachedCodeToIdcard[$code] = $idcard;
                    }
                }
                foreach ($attachedCodeToIdcard as $code => $idcard) {
                    $sql = sprintf(
                        'UPDATE hr_person SET `身份证号`=%s, `操作记录`=%s, `操作来源`=%s, `操作人员`=%s, `操作时间`=%s
                         WHERE `人员编码`=%s AND (`身份证号`="" OR `身份证号` IS NULL) AND 有效标识="1" AND 删除标识="0"',
                        $this->model->quote($idcard),
                        $this->model->quote('导入回填'),
                        $this->model->quote('批量导入'),
                        $this->model->quote($operator),
                        $this->model->quote(date('Y-m-d H:i:s')),
                        $this->model->quote($code)
                    );
                    $this->model->exec($sql);
                }
            } catch (\Throwable $e) {
                $db->transRollback();
                log_message('error', '[PersonImportService] 主档落地事务回滚: ' . $e->getMessage());
                return ['success' => false, 'message' => '人员主档落地失败: ' . $e->getMessage(), 'created' => 0, 'attached' => 0];
            }

            $db->transComplete();
            if ($db->transStatus() === false) {
                return ['success' => false, 'message' => '人员主档落地失败（事务已回滚）', 'created' => 0, 'attached' => 0];
            }

            return [
                'success' => true,
                'message' => sprintf('主档裁决完成：新建 %d 档，挂接 %d 档', $created, $attached),
                'created' => $created,
                'attached' => $attached,
            ];
        } catch (\Throwable $e) {
            log_message('error', '[PersonImportService] 主档落地失败: ' . $e->getMessage());
            return ['success' => false, 'message' => '人员主档落地失败: ' . $e->getMessage(), 'created' => 0, 'attached' => 0];
        }
    }
}
