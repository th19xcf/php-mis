<?php

namespace App\Services\Person;

use App\Models\Mcommon;

/**
 * 人员主档存量迁移服务（ee_store + ee_interview + ee_train + ee_onjob → hr_person）
 *
 * 四表有效未挂档行合并分组建档，回填业务表 人员编码：
 * - 分组归并：证件号为强键（跨表同人不同手机也归并）；无证件号行按姓名+手机归并
 * - 组间冲突：同姓名+手机但证件号不同 → 合并为一档，证件号取最新操作时间的行，其余记报告
 * - 与 hr_person 既有档查重（幂等重跑安全）：硬命中/唯一软命中挂接，多条软命中记冲突跳过
 * - 发号：按组内最早合法业务日期分桶，sp_生成人员编码 批量发段（与导入逻辑一致）
 * - 分批事务落地：批内发号 + INSERT hr_person + 业务表按 GUID 批量回填人员编码
 * - 业务表只更新 人员编码 列，不覆盖原审计字段；hr_person 审计 操作来源='存量迁移'
 * - hr_person.开始操作时间 取组内源表最早 操作时间（无合法值回退迁移执行时间）；操作时间 为迁移执行时间
 *
 * dryRun=true 时仅执行分组裁决与统计，不写库。
 */
class PersonMigrateService
{
    private Mcommon $model;

    /** 源表配置：表名 => [业务日期字段（发号分桶用）, 该表独有的身份扩展字段] */
    private const SOURCE_TABLES = [
        'ee_store'     => ['date' => '邀约日期',     'extra' => ['性别', '年龄', '学校', '专业', '学历', '现住址', '工作履历']],
        'ee_interview' => ['date' => '一次面试日期', 'extra' => []],
        'ee_train'     => ['date' => '培训开始日期', 'extra' => []],
        'ee_onjob'     => ['date' => '记录开始日期', 'extra' => []],
    ];

    /** 全部源表可能出现的身份扩展字段并集（表内缺失的列 SQL 中置 NULL 保持结构一致） */
    private const EXTRA_FIELDS = ['性别', '年龄', '学校', '专业', '学历', '现住址', '工作履历'];

    /** hr_person 身份字段 */
    private const PERSON_FIELDS = [
        '姓名', '身份证号', '手机号码', '性别', '年龄',
        '学校', '专业', '学历', '现住址', '工作履历', '属地',
    ];

    public function __construct()
    {
        $this->model = new Mcommon();
    }

    /**
     * 执行迁移
     *
     * @param bool     $dryRun   只出报告不写库
     * @param int      $batchSize 每批事务的组数
     * @param string   $operator 操作人员（写入 hr_person.操作人员）
     * @param callable|null $progress 进度回调 fn(int $done, int $total, string $stage): void
     * @return array 迁移报告（统计 + 冲突/无法建档明细）
     */
    public function migrate(bool $dryRun = true, int $batchSize = 1000, string $operator = 'system', ?callable $progress = null): array
    {
        $report = [
            'dryRun'              => $dryRun,
            'sourceRows'          => 0,
            'groupsTotal'         => 0,
            'created'             => 0,
            'attached'            => 0,
            'attachedRows'        => 0,
            'createdRows'         => 0,
            'unableRows'          => 0,   // 无法建档行（姓名/手机缺失且无证件号）
            'conflictIdcard'      => 0,   // 同姓名+手机不同证件号（已自动合并，明细在报告）
            'conflictIdcardName'  => 0,   // 同证件号不同姓名（脏数据，已按最新行取值）
            'conflictSoftMulti'   => 0,   // 与既有主档多条软命中（跳过待人工）
            'details'             => [
                'unable'         => [],  // [{src, guid, name, mobile, idcard, reason}]
                'idcardConflicts'=> [],  // [{name, mobile, kept, discarded: [...]}]
                'idcardNameConflicts' => [], // [{idcard, kept, discarded: [...]}]
                'softMultiConflicts'  => [], // [{name, mobile, matches: [...]}]
            ],
        ];

        // 1. 加载源行
        $rows = $this->loadSourceRows();
        $report['sourceRows'] = count($rows);

        // 2. 分组归并
        $groups = $this->groupAndMerge($rows, $report);
        unset($rows);
        $report['groupsTotal'] = count($groups);

        if ($progress) {
            $progress(0, count($groups), '分组完成');
        }

        // 3. 与既有主档查重分流
        $tasks = $this->resolveWithExisting($groups, $report);

        // 4. 统计预计回填行数（dry-run 与正式执行共用）
        $createdGroups = 0;
        $attachedGroups = 0;
        foreach ($tasks as $task) {
            if ($task['action'] === 'create') {
                $createdGroups++;
                $report['createdRows'] += count($task['rows']);
            } else {
                $attachedGroups++;
                $report['attachedRows'] += count($task['rows']);
            }
        }
        $report['createdGroups'] = $createdGroups;
        $report['attachedGroups'] = $attachedGroups;

        if ($dryRun) {
            $report['created'] = 0;
            $report['attached'] = 0;
            return $report;
        }

        // 5. 分批事务落地
        $chunks = array_chunk($tasks, max(1, $batchSize));
        $done = 0;
        foreach ($chunks as $chunk) {
            $result = $this->applyChunk($chunk, $operator);
            $report['created'] += $result['created'];
            $report['attached'] += $result['attached'];
            $done += count($chunk);
            if ($progress) {
                $progress($done, count($tasks), '落地');
            }
        }

        return $report;
    }

    /**
     * 加载各源表有效未挂档行
     *
     * @return array<int, array{src:string, guid:int, fields:array, biz_date:string, op_time:string}>
     */
    private function loadSourceRows(): array
    {
        $rows = [];
        foreach (self::SOURCE_TABLES as $table => $config) {
            // 按表实际持有的扩展字段取列，缺失的列置 NULL 保持结构一致
            $extra = ', ' . implode(', ', array_map(
                static fn($f) => in_array($f, $config['extra'], true)
                    ? sprintf('`%s`', $f)
                    : sprintf('NULL AS `%s`', $f),
                self::EXTRA_FIELDS
            ));
            $sql = sprintf(
                'SELECT GUID, `姓名`, `手机号码`, `身份证号`, `属地`%s, `%s` AS biz_date, `操作时间` AS op_time
                 FROM `%s`
                 WHERE 有效标识="1" AND 删除标识="0" AND (`人员编码` IS NULL OR `人员编码`="")',
                $extra,
                $config['date'],
                $table
            );
            foreach ($this->model->select($sql)->getResultArray() as $r) {
                $fields = [
                    '姓名'   => trim((string) ($r['姓名'] ?? '')),
                    '手机号码' => trim((string) ($r['手机号码'] ?? '')),
                    '身份证号' => trim((string) ($r['身份证号'] ?? '')),
                    '属地'   => trim((string) ($r['属地'] ?? '')),
                ];
                foreach (self::EXTRA_FIELDS as $f) {
                    $v = $r[$f] ?? null;
                    $fields[$f] = ($v === null || $v === '') ? null : trim((string) $v);
                }
                $rows[] = [
                    'src'      => $table,
                    'guid'     => (int) $r['GUID'],
                    'fields'   => $fields,
                    'biz_date' => trim((string) ($r['biz_date'] ?? '')),
                    'op_time'  => trim((string) ($r['op_time'] ?? '')),
                ];
            }
        }
        return $rows;
    }

    /**
     * 分组归并：证件号强键分组 → 组间按姓名+手机合并 → 无证件号行归并
     *
     * @return array<int, array{rows:array[], identity:array, biz_date:string, first_op_time:string}> 组列表
     */
    private function groupAndMerge(array $rows, array &$report): array
    {
        // 1. 行分类
        $idRows = [];   // 有证件号
        $nmRows = [];   // 无证件号、有姓名+手机
        foreach ($rows as $row) {
            $f = $row['fields'];
            if ($f['身份证号'] !== '') {
                $idRows[] = $row;
            } elseif ($f['姓名'] !== '' && $f['手机号码'] !== '') {
                $nmRows[] = $row;
            } else {
                $report['unableRows']++;
                $report['details']['unable'][] = [
                    'src'    => $row['src'],
                    'guid'   => $row['guid'],
                    'name'   => $f['姓名'],
                    'mobile' => $f['手机号码'],
                    'idcard' => $f['身份证号'],
                    'reason' => '姓名与手机号码缺失且无身份证号，无法建档',
                ];
            }
        }

        // 2. 证件号分组
        $idGroups = [];
        foreach ($idRows as $row) {
            $idGroups[$row['fields']['身份证号']][] = $row;
        }

        // 3. 组间归并：同姓名+手机出现在多个证件号组 → 合并（证件号冲突，记报告）
        //    3.1 建立 (name|mobile) => 组键列表 索引
        $nmIndex = [];
        foreach ($idGroups as $idcard => $groupRows) {
            $seen = [];
            foreach ($groupRows as $row) {
                $key = $row['fields']['姓名'] . '|' . $row['fields']['手机号码'];
                if ($row['fields']['姓名'] !== '' && $row['fields']['手机号码'] !== '' && !isset($seen[$key])) {
                    $seen[$key] = true;
                    $nmIndex[$key][] = $idcard;
                }
            }
        }
        // 3.2 归并（组键指向最终组键，逐级回溯）
        $mergeTarget = [];
        $finalKey = static function (string $k) use (&$mergeTarget): string {
            while (isset($mergeTarget[$k]) && $mergeTarget[$k] !== $k) {
                $k = $mergeTarget[$k];
            }
            return $k;
        };
        foreach ($nmIndex as $nmKey => $idcards) {
            if (count($idcards) <= 1) {
                continue;
            }
            // 冲突：同姓名+手机不同证件号，合并到第一个组
            $target = $finalKey($idcards[0]);
            foreach (array_slice($idcards, 1) as $idcard) {
                $src = $finalKey($idcard);
                if ($src !== $target) {
                    $mergeTarget[$src] = $target;
                }
            }
            // 记报告：合并组的证件号裁决在身份合并阶段统一处理（取最新行）
            $report['conflictIdcard']++;
        }
        unset($nmIndex);

        // 3.3 物理合并组
        $mergedGroups = [];
        foreach ($idGroups as $idcard => $groupRows) {
            $key = $finalKey($idcard);
            if (!isset($mergedGroups[$key])) {
                $mergedGroups[$key] = [];
            }
            foreach ($groupRows as $row) {
                $mergedGroups[$key][] = $row;
            }
        }
        unset($idGroups, $mergeTarget);

        // 4. 无证件号行归并：按姓名+手机找组（组内任一行同姓名+手机）
        //    重新建索引：nmKey => 组键（合并后的）
        $groupNmIndex = [];
        foreach ($mergedGroups as $key => $groupRows) {
            $seen = [];
            foreach ($groupRows as $row) {
                if ($row['fields']['姓名'] === '' || $row['fields']['手机号码'] === '') {
                    continue;
                }
                $nmKey = $row['fields']['姓名'] . '|' . $row['fields']['手机号码'];
                if (!isset($seen[$nmKey])) {
                    $seen[$nmKey] = true;
                    $groupNmIndex[$nmKey][] = $key;
                }
            }
        }
        foreach ($nmRows as $row) {
            $nmKey = $row['fields']['姓名'] . '|' . $row['fields']['手机号码'];
            if (isset($groupNmIndex[$nmKey])) {
                $mergedGroups[$groupNmIndex[$nmKey][0]][] = $row;
            } else {
                $mergedGroups['nm:' . $nmKey][] = $row;
                $groupNmIndex[$nmKey][] = 'nm:' . $nmKey;
            }
        }
        unset($nmRows, $groupNmIndex);

        // 5. 组身份合并：按操作时间倒序，字段取第一个非空；证件号冲突取最新
        $groups = [];
        foreach ($mergedGroups as $key => $groupRows) {
            // 操作时间倒序（空视为最旧）
            usort($groupRows, static function ($a, $b) {
                return strcmp($b['op_time'] ?: '', $a['op_time'] ?: '');
            });

            $identity = array_fill_keys(self::PERSON_FIELDS, null);
            $seenIdcards = [];
            $keptIdcard = null;
            foreach ($groupRows as $row) {
                // 字段取第一个非空（行已按操作时间倒序）
                foreach (self::PERSON_FIELDS as $fname) {
                    if ($identity[$fname] === null && $row['fields'][$fname] !== null && $row['fields'][$fname] !== '') {
                        $identity[$fname] = $row['fields'][$fname];
                    }
                }
                $ic = $row['fields']['身份证号'];
                if ($ic !== '' && !isset($seenIdcards[$ic])) {
                    $seenIdcards[$ic] = true;
                    if ($keptIdcard === null) {
                        $keptIdcard = $ic; // 最新操作时间行的证件号
                    }
                }
            }
            if ($keptIdcard !== null) {
                $identity['身份证号'] = $keptIdcard;
            }

            // 证件号冲突明细（合并组内多个证件号）
            if (count($seenIdcards) > 1) {
                $report['details']['idcardConflicts'][] = [
                    'name'      => $identity['姓名'],
                    'mobile'    => $identity['手机号码'],
                    'kept'      => $keptIdcard,
                    'discarded' => array_values(array_diff(array_keys($seenIdcards), [$keptIdcard])),
                ];
            }

            // 同证件号不同姓名（脏数据）：记报告
            $nameSet = [];
            foreach ($groupRows as $row) {
                if ($row['fields']['姓名'] !== '') {
                    $nameSet[$row['fields']['姓名']] = true;
                }
            }
            if (count($nameSet) > 1) {
                $report['conflictIdcardName']++;
                $report['details']['idcardNameConflicts'][] = [
                    'idcard'    => $keptIdcard ?? '',
                    'kept'      => $identity['姓名'],
                    'discarded' => array_values(array_diff(array_keys($nameSet), [$identity['姓名']])),
                ];
            }

            // 发号业务日期：组内最早合法日期
            $bizDate = '';
            foreach ($groupRows as $row) {
                $d = $row['biz_date'];
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                    if ($bizDate === '' || strcmp($d, $bizDate) < 0) {
                        $bizDate = $d;
                    }
                }
            }
            if ($bizDate === '') {
                $bizDate = date('Y-m-d');
            }

            // 开始操作时间：组内源表最早操作时间（空值/非法格式忽略）
            $firstOpTime = '';
            foreach ($groupRows as $row) {
                $t = $row['op_time'];
                if ($t !== ''
                    && preg_match('/^\d{4}-\d{2}-\d{2}([ ]\d{2}:\d{2}(:\d{2})?)?$/', $t)
                    && ($firstOpTime === '' || strcmp($t, $firstOpTime) < 0)) {
                    $firstOpTime = $t;
                }
            }

            $groups[] = [
                'rows'          => $groupRows,
                'identity'      => $identity,
                'biz_date'      => $bizDate,
                'first_op_time' => $firstOpTime,
            ];
        }

        return $groups;
    }

    /**
     * 与 hr_person 既有档查重分流：挂接 / 新建 / 冲突跳过
     *
     * @return array<int, array{action:'create'|'attach', identity:array, biz_date:string, first_op_time?:string, rows:array[], personCode?:string, person?:array}>
     */
    private function resolveWithExisting(array $groups, array &$report): array
    {
        // 1. 批量硬命中查询（证件号 IN）
        $idcards = [];
        foreach ($groups as $g) {
            if (($g['identity']['身份证号'] ?? '') !== '') {
                $idcards[] = $g['identity']['身份证号'];
            }
        }
        $hardById = [];
        if (!empty($idcards)) {
            $in = implode(',', array_map(fn($v) => $this->model->quote($v), array_values(array_unique($idcards))));
            $sql = "select 人员编码,姓名,身份证号,手机号码 from hr_person
                    where 身份证号 in ({$in}) and 有效标识='1' and 删除标识='0'
                      and (合并至='' or 合并至 is null)";
            foreach ($this->model->select($sql)->getResultArray() as $row) {
                $hardById[trim((string) $row['身份证号'])] = $row;
            }
        }

        // 2. 批量软命中查询（手机号 IN，PHP 内匹配姓名）
        $mobiles = [];
        foreach ($groups as $g) {
            $m = $g['identity']['手机号码'] ?? '';
            if ($m !== '') {
                $mobiles[] = $m;
            }
        }
        $softByMobile = [];
        if (!empty($mobiles)) {
            $in = implode(',', array_map(fn($v) => $this->model->quote($v), array_values(array_unique($mobiles))));
            $sql = "select 人员编码,姓名,身份证号,手机号码 from hr_person
                    where 手机号码 in ({$in}) and 有效标识='1' and 删除标识='0'
                      and (合并至='' or 合并至 is null)";
            foreach ($this->model->select($sql)->getResultArray() as $row) {
                $softByMobile[trim((string) $row['手机号码'])][] = $row;
            }
        }

        // 3. 逐组分流
        $tasks = [];
        foreach ($groups as $g) {
            $identity = $g['identity'];
            $idcard = $identity['身份证号'] ?? '';
            $name = $identity['姓名'] ?? '';
            $mobile = $identity['手机号码'] ?? '';

            // 硬命中 → 挂接
            if ($idcard !== '' && isset($hardById[$idcard])) {
                $tasks[] = [
                    'action'     => 'attach',
                    'identity'   => $identity,
                    'biz_date'   => $g['biz_date'],
                    'rows'       => $g['rows'],
                    'personCode' => (string) $hardById[$idcard]['人员编码'],
                    'person'     => $hardById[$idcard],
                ];
                continue;
            }

            // 软命中（姓名+手机）
            if ($name !== '' && $mobile !== '' && isset($softByMobile[$mobile])) {
                $matches = [];
                foreach ($softByMobile[$mobile] as $p) {
                    if (trim((string) $p['姓名']) === $name) {
                        $matches[] = $p;
                    }
                }
                if (count($matches) === 1) {
                    $tasks[] = [
                        'action'     => 'attach',
                        'identity'   => $identity,
                        'biz_date'   => $g['biz_date'],
                        'rows'       => $g['rows'],
                        'personCode' => (string) $matches[0]['人员编码'],
                        'person'     => $matches[0],
                    ];
                    continue;
                }
                if (count($matches) > 1) {
                    $report['conflictSoftMulti']++;
                    $report['details']['softMultiConflicts'][] = [
                        'name'    => $name,
                        'mobile'  => $mobile,
                        'matches' => array_map(static fn($m) => $m['人员编码'] . ' ' . $m['姓名'], $matches),
                    ];
                    continue; // 跳过待人工
                }
            }

            // 无命中 → 新建
            $tasks[] = [
                'action'        => 'create',
                'identity'      => $identity,
                'biz_date'      => $g['biz_date'],
                'first_op_time' => $g['first_op_time'],
                'rows'          => $g['rows'],
            ];
        }
        return $tasks;
    }

    /**
     * 批量生成人员编码起始号（按业务日期分桶，一次 CALL 发整段号）
     *
     * @return array{prefix: string, start: int}
     */
    private function batchGenerateCodes(int $count, string $bizDate): array
    {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $bizDate) ? $bizDate : date('Y-m-d');
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
     * 一个批次的事务落地：建档 + 回填 + 挂接组主档证件号回填
     *
     * @param array $tasks 本批任务（create/attach 混合）
     * @return array{created:int, attached:int}
     */
    private function applyChunk(array $tasks, string $operator): array
    {
        $created = 0;
        $attached = 0;
        $db = $this->model->getDb();
        $db->transStart();

        try {
            $now = date('Y-m-d H:i:s');

            // 1. 建档：按业务日期分桶批量发号（personCode 原地写回 $tasks，供第 2 步回填）
            $byDate = [];
            foreach ($tasks as $idx => $task) {
                if ($task['action'] !== 'create') {
                    continue;
                }
                $byDate[$task['biz_date']][] = $idx;
            }
            foreach ($byDate as $date => $indices) {
                $issue = $this->batchGenerateCodes(count($indices), $date);
                foreach ($indices as $i => $taskIdx) {
                    $code = $issue['prefix'] . str_pad((string) ($issue['start'] + $i), 3, '0', STR_PAD_LEFT);

                    $row = ['人员编码' => $code];
                    foreach (self::PERSON_FIELDS as $f) {
                        $v = $tasks[$taskIdx]['identity'][$f] ?? null;
                        if ($v !== null && $v !== '') {
                            $row[$f] = (string) $v;
                        }
                    }
                    $row['操作记录'] = '新增';
                    $row['操作来源'] = '存量迁移';
                    $row['操作人员'] = $operator;
                    // 开始操作时间：取组内源表最早操作时间，无合法值回退迁移执行时间
                    $row['开始操作时间'] = ($tasks[$taskIdx]['first_op_time'] ?? '') ?: $now;
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
                        throw new \RuntimeException('hr_person 建档失败: ' . $code);
                    }
                    $tasks[$taskIdx]['personCode'] = $code;
                    $created++;
                }
            }

            // 2. 业务表回填人员编码（按表分组批量 UPDATE，只更新人员编码列）
            $rowsByTable = [];
            foreach ($tasks as $task) {
                $code = $task['personCode'] ?? '';
                if ($code === '') {
                    continue;
                }
                foreach ($task['rows'] as $row) {
                    $rowsByTable[$row['src']][] = ['guid' => $row['guid'], 'code' => $code];
                }
            }
            foreach ($rowsByTable as $table => $items) {
                // 按 code 分组，一条 UPDATE 回填一组 GUID
                $guidsByCode = [];
                foreach ($items as $item) {
                    $guidsByCode[$item['code']][] = $item['guid'];
                }
                foreach ($guidsByCode as $code => $guids) {
                    $guidList = implode(',', array_map(static fn($g) => (int) $g, $guids));
                    $sql = sprintf(
                        'UPDATE `%s` SET `人员编码`=%s WHERE GUID IN (%s)',
                        $table,
                        $this->model->quote($code),
                        $guidList
                    );
                    if ($this->model->exec($sql) < 0) {
                        throw new \RuntimeException('业务表人员编码回填失败: ' . $table);
                    }
                }
            }

            // 3. 挂接组：主档证件号为空且组证件号非空时回填
            foreach ($tasks as $task) {
                if ($task['action'] !== 'attach') {
                    continue;
                }
                $idcard = $task['identity']['身份证号'] ?? '';
                $personIdcard = trim((string) ($task['person']['身份证号'] ?? ''));
                if ($idcard !== '' && $personIdcard === '') {
                    $sql = sprintf(
                        'UPDATE hr_person SET `身份证号`=%s, `操作记录`=%s, `操作来源`=%s, `操作人员`=%s, `操作时间`=%s
                         WHERE `人员编码`=%s AND (`身份证号`="" OR `身份证号` IS NULL) AND 有效标识="1" AND 删除标识="0"',
                        $this->model->quote($idcard),
                        $this->model->quote('迁移回填'),
                        $this->model->quote('存量迁移'),
                        $this->model->quote($operator),
                        $this->model->quote($now),
                        $this->model->quote($task['personCode'])
                    );
                    $this->model->exec($sql);
                }
                $attached++;
            }
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }

        $db->transComplete();
        if ($db->transStatus() === false) {
            throw new \RuntimeException('批次事务提交失败（已回滚）');
        }

        return ['created' => $created, 'attached' => $attached];
    }
}
