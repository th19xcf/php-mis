<?php

namespace App\Services\Person;

use App\Exceptions\BusinessException;
use App\Models\Mcommon;
use App\Services\Audit\AuditLogService;

/**
 * 人员主档服务（hr_person）
 *
 * 负责自然人主档的查重、发号、建档、更新与挂档裁决。
 *
 * 人员编码：PK+YYYYMMDD+3位流水号，由 sp_生成人员编码 发号，
 * 走 def_seq 独立序列（与候选人编码 C 前缀序列互不干扰）。
 *
 * 查重三级（按优先级）：
 * - hard：证件号精确匹配（唯一索引保证），直接挂既有档
 * - soft：手机号+姓名匹配，疑似重复，需人工确认（挂既有 / 确认新建）
 * - none：无命中，新建主档
 *
 * 身份证号为空时写 NULL（非空串）：uk_id_card 唯一索引对 NULL 不判重、
 * 对空串判重，空串会导致第二个无证件号的人建档失败。
 */
class PersonService
{
    private Mcommon $model;

    /** hr_person 的身份字段清单（用于从存量业务行提取建档数据） */
    private const PERSON_FIELDS = [
        '姓名', '身份证号', '手机号码', '性别', '年龄', '出生日期',
        '学校', '专业', '学历', '现住址', '工作履历', '属地',
    ];

    public function __construct()
    {
        $this->model = new Mcommon();
    }

    /**
     * 查重：证件号精确 → 手机号+姓名软匹配
     *
     * @return array{level:string, matches:array, person:?array}
     *         level: hard|soft|none；hard 时 person 为命中的主档行
     */
    public function dedup(string $name, string $mobile, string $idcard = ''): array
    {
        // 1. 证件号精确匹配（硬命中）
        if ($idcard !== '') {
            $sql = sprintf(
                'select 人员编码,姓名,身份证号,手机号码,性别,属地
                 from hr_person
                 where 身份证号=%s and 有效标识="1" and 删除标识="0"
                   and (合并至="" or 合并至 is null)
                 limit 1',
                $this->model->quote($idcard)
            );
            $row = $this->model->select($sql)->getRowArray();
            if ($row) {
                return ['level' => 'hard', 'matches' => [$row], 'person' => $row];
            }
        }

        // 2. 手机号+姓名软匹配（疑似重复，需人工确认）
        if ($name !== '' && $mobile !== '') {
            $sql = sprintf(
                'select 人员编码,姓名,身份证号,手机号码,性别,属地
                 from hr_person
                 where 姓名=%s and 手机号码=%s and 有效标识="1" and 删除标识="0"
                   and (合并至="" or 合并至 is null)
                 limit 5',
                $this->model->quote($name),
                $this->model->quote($mobile)
            );
            $rows = $this->model->select($sql)->getResultArray();
            if (!empty($rows)) {
                return ['level' => 'soft', 'matches' => $rows, 'person' => null];
            }
        }

        return ['level' => 'none', 'matches' => [], 'person' => null];
    }

    /**
     * 生成人员编码（sp_生成人员编码，def_seq 独立序列）
     *
     * 直接走 getDb()->query() 而非 Mcommon::select()：
     * select() 有请求级结果缓存，同请求第二次发号会拿到缓存结果而不执行
     * 存储过程，导致重号。CALL 必须真正执行。
     *
     * @param string $bizDate 业务日期（邀约日期），空则用今天
     */
    public function generatePersonCode(string $bizDate = ''): string
    {
        $date = $bizDate ?: date('Y-m-d');
        // 严格校验日期格式，防 SQL 注入（存储过程 p_date 参数）
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }

        $db = $this->model->getDb();
        // 初始化会话变量（防残留）
        $db->query("SET @seq = 0, @prefix = ''");
        // 发号（LAST_INSERT_ID 防并发，按业务日期分桶）
        $db->query(sprintf("CALL sp_生成人员编码(1, '%s', @seq, @prefix)", $date));
        // 读取 OUT 参数（同一连接，@变量可见）
        $row = $db->query('SELECT @prefix AS p, @seq AS s')->getRowArray() ?: [];
        $prefix = (string) ($row['p'] ?? '');
        $seq = (int) ($row['s'] ?? 0);
        return $prefix . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }

    /**
     * 新建人员主档（须在调用方事务内执行）
     *
     * @param array  $personData 主档字段（已过滤为 hr_person 实际列）
     * @param string $operator   操作人工号
     * @param string $bizDate    发号业务日期
     * @return string 人员编码
     * @throws BusinessException 写入失败
     */
    public function createPerson(array $personData, string $operator, string $bizDate = ''): string
    {
        $code = $this->generatePersonCode($bizDate);

        $row = $personData;
        // 证件号派生性别：未提供或非法时自动补齐，避免新档继续留空
        $gender = $this->deriveGenderFromIdCard((string) ($row['身份证号'] ?? ''));
        if ($gender !== null && !in_array($row['性别'] ?? '', ['男', '女'], true)) {
            $row['性别'] = $gender;
        }
        // 证件号派生出生日期：未提供时自动补齐（权威值，覆盖收集阶段的自报年龄口径）
        if (empty($row['出生日期'])) {
            $birth = $this->deriveBirthFromIdCard((string) ($row['身份证号'] ?? ''));
            if ($birth !== null) {
                $row['出生日期'] = $birth;
            }
        }
        $row['人员编码'] = $code;
        $row['操作记录'] = '新增';
        $row['操作来源'] = '页面新增';
        $row['操作人员'] = $operator;
        $row['开始操作时间'] = date('Y-m-d H:i:s');
        $row['操作时间'] = date('Y-m-d H:i:s');
        $row['有效标识'] = '1';
        $row['删除标识'] = '0';

        $fields = array_map(fn($k) => sprintf('`%s`', $k), array_keys($row));
        $values = array_map(
            fn($k, $v) => $this->buildValue($k, $v),
            array_keys($row),
            array_values($row)
        );

        $sql = sprintf(
            'INSERT INTO hr_person (%s) VALUES (%s)',
            implode(',', $fields),
            implode(',', $values)
        );
        $affected = $this->model->exec($sql);
        if ($affected <= 0) {
            throw new BusinessException('人员主档创建失败');
        }

        // hr_audit_log（严格模式：同事务，失败由调用方回滚）
        $newRow = $this->model->select(
            sprintf('SELECT GUID, UUID FROM hr_person WHERE 人员编码=%s LIMIT 1', $this->model->quote($code))
        )->getRowArray() ?: [];
        (new AuditLogService())->logEvent([
            '人员编码'   => $code,
            '表名'      => 'hr_person',
            '记录GUID'  => (int) ($newRow['GUID'] ?? 0),
            '记录UUID'  => $newRow['UUID'] ?? null,
            '操作类型'  => '新增',
            '变更字段'  => '全部',
            '原值'      => null,
            '新值'      => '新增主档',
            '操作人员'  => $operator,
            '操作来源'  => '页面新增',
        ]);

        return $code;
    }

    /**
     * 更新人员主档字段（须在调用方事务内执行）
     *
     * 空值字段跳过（与 updateRecord 语义一致）；身份证号传 NULL 语义：
     * 传空串时不更新该列（避免把已有证件号清成空串撞唯一索引）。
     *
     * @return int 影响行数
     */
    public function updatePersonFields(string $personCode, array $fields, string $operator): int
    {
        // 旧行快照（审计 diff + 定位键；须在 UPDATE 前读）
        $oldRow = $this->findPersonByCode($personCode);

        $sets = [
            sprintf('`操作记录`=%s', $this->model->quote('修改')),
            sprintf('`操作来源`=%s', $this->model->quote('页面修改')),
            sprintf('`操作人员`=%s', $this->model->quote($operator)),
            sprintf('`操作时间`=%s', $this->model->quote(date('Y-m-d H:i:s'))),
        ];

        $updateData = []; // 实际写入字段（审计 diff 用，与 SET 严格一致）
        foreach ($fields as $key => $value) {
            if (in_array($key, ['人员编码', '合并至', 'GUID'], true)) {
                continue; // 关键列不允许通过页面修改
            }
            if ($value === '') {
                continue; // 空值跳过，防误清空
            }
            $sets[] = sprintf('`%s`=%s', $key, $this->buildValue($key, $value));
            $updateData[$key] = $value;
        }

        // 证件号派生性别：表单未显式提供性别、主档性别为空或非法时，
        // 从本次写入的证件号派生补齐（不覆盖人工录入的合法男/女值）
        if (isset($updateData['身份证号']) && !isset($updateData['性别'])) {
            $oldGender = trim((string) ($oldRow['性别'] ?? ''));
            $gender = $this->deriveGenderFromIdCard((string) $updateData['身份证号']);
            if ($gender !== null && ($oldGender === '' || !in_array($oldGender, ['男', '女'], true))) {
                $sets[] = sprintf('`性别`=%s', $this->model->quote($gender));
                $updateData['性别'] = $gender;
            }
        }

        // 证件号派生出生日期：表单未显式提供、主档出生日期为空时，
        // 从本次写入的证件号派生补齐（证件号为权威来源，优先于自报值口径）
        if (isset($updateData['身份证号']) && !isset($updateData['出生日期'])) {
            $oldBirth = trim((string) ($oldRow['出生日期'] ?? ''));
            if ($oldBirth === '') {
                $birth = $this->deriveBirthFromIdCard((string) $updateData['身份证号']);
                if ($birth !== null) {
                    $sets[] = sprintf('`出生日期`=%s', $this->model->quote($birth));
                    $updateData['出生日期'] = $birth;
                }
            }
        }

        $sql = sprintf(
            'UPDATE hr_person SET %s WHERE 人员编码=%s AND 有效标识="1" AND 删除标识="0"',
            implode(',', $sets),
            $this->model->quote($personCode)
        );
        $affected = $this->model->exec($sql);

        // hr_audit_log（严格模式：同事务，失败由调用方回滚）
        if ($affected > 0 && $oldRow !== null) {
            (new AuditLogService())->logUpdateDiff(
                'hr_person',
                [$oldRow],
                $updateData,
                $operator,
                '页面修改'
            );
        }

        return $affected;
    }

    /**
     * 按人员编码查询主档
     *
     * @return array|null 主档行，不存在返回 null
     */
    public function findPersonByCode(string $personCode): ?array
    {
        if ($personCode === '') {
            return null;
        }
        $sql = sprintf(
            'select * from hr_person where 人员编码=%s and 有效标识="1" and 删除标识="0" limit 1',
            $this->model->quote($personCode)
        );
        $row = $this->model->select($sql)->getRowArray();
        return $row ?: null;
    }

    /**
     * 确保业务行（如 ee_store 邀约行）已挂人员主档，返回人员编码
     *
     * 存量数据回填场景：行上人员编码为空时，按行内身份信息裁决——
     * - 硬命中（证件号）→ 挂既有档
     * - 软命中（手机+姓名）且唯一 → 挂既有档
     * - 无命中 → 用行内身份字段新建主档
     *
     * 注意：本方法会写库（可能新建主档），如需与业务表更新同事务，
     * 须在 transStart 之后调用。
     *
     * @param array  $bizRow    业务行（须含 姓名/身份证号/手机号码，可含其他身份字段）
     * @param string $operator  操作人工号
     * @param string $bizDate   发号业务日期
     * @return string 人员编码
     * @throws BusinessException 身份信息不足以建档
     */
    public function ensurePersonForStore(array $bizRow, string $operator, string $bizDate = ''): string
    {
        // 已挂档且档存在 → 直接返回
        $code = trim((string) ($bizRow['人员编码'] ?? ''));
        if ($code !== '' && $this->findPersonByCode($code) !== null) {
            return $code;
        }

        $name = trim((string) ($bizRow['姓名'] ?? ''));
        $mobile = trim((string) ($bizRow['手机号码'] ?? ''));
        $idcard = trim((string) ($bizRow['身份证号'] ?? ''));

        if ($name === '' || $mobile === '') {
            throw new BusinessException('人员主档建档要求姓名与手机号码不能为空');
        }

        $dedup = $this->dedup($name, $mobile, $idcard);
        if ($dedup['level'] === 'hard') {
            return (string) $dedup['person']['人员编码'];
        }
        if ($dedup['level'] === 'soft' && count($dedup['matches']) === 1) {
            // 唯一软命中：挂既有档（多条命中无法自动裁决，走新建，
            // 重复档由唯一索引/合并机制事后收口）
            return (string) $dedup['matches'][0]['人员编码'];
        }

        // 新建：从业务行提取身份字段
        $personData = [];
        foreach (self::PERSON_FIELDS as $f) {
            if (array_key_exists($f, $bizRow) && $bizRow[$f] !== null && $bizRow[$f] !== '') {
                $personData[$f] = (string) $bizRow[$f];
            }
        }
        return $this->createPerson($personData, $operator, $bizDate);
    }

    /**
     * 从阶段表编辑同步身份字段到 hr_person 主档（非空覆盖策略）
     *
     * 当用户在工作台编辑 ee_store/ee_interview/ee_train/ee_onjob 时，
     * 表单中属于 PERSON_FIELDS 的字段需同步回写 hr_person。
     * 空值跳过（不覆盖主档已有值），非空值覆盖（保持主档为最新）。
     *
     * @param string $personCode 人员编码（须非空）
     * @param array  $formData   表单数据（全量，方法内部按 PERSON_FIELDS 过滤）
     * @param string $operator   操作人工号
     * @return int 影响行数（0=无身份字段需同步或主档不存在）
     */
    public function syncPersonFromEdit(string $personCode, array $formData, string $operator): int
    {
        if ($personCode === '') {
            return 0;
        }

        // 过滤出属于 hr_person 的身份字段
        $personData = [];
        foreach (self::PERSON_FIELDS as $field) {
            if (array_key_exists($field, $formData) && $formData[$field] !== '') {
                $personData[$field] = $formData[$field];
            }
        }

        if (empty($personData)) {
            return 0;
        }

        // 确认主档存在（防止对已删除/已失效的主档执行更新）
        if ($this->findPersonByCode($personCode) === null) {
            return 0;
        }

        return $this->updatePersonFields($personCode, $personData, $operator);
    }

    /**
     * 重档合并：将源主档合并到目标主档
     *
     * 操作步骤（同一事务内）：
     * 1. 源主档有效行置无效：有效标识=0, 合并至=目标编码
     * 2. 下游四表（ee_store/ee_interview/ee_train/ee_onjob）有效行
     *    人员编码从源编码改为目标编码
     * 3. 记录审计日志
     *
     * @param string $sourceCode 源人员编码（被合并方）
     * @param string $targetCode 目标人员编码（合并保留方）
     * @param string $operator   操作人工号
     * @return int 受影响行数（下游四表更新总数）
     * @throws BusinessException 源/目标不存在、编码相同、目标已合并
     */
    public function mergePerson(string $sourceCode, string $targetCode, string $operator): int
    {
        if ($sourceCode === $targetCode) {
            throw new BusinessException('源人员编码与目标人员编码不能相同');
        }

        $source = $this->findPersonByCode($sourceCode);
        if ($source === null) {
            throw new BusinessException(sprintf('源人员主档不存在或已失效: %s', $sourceCode));
        }
        $target = $this->findPersonByCode($targetCode);
        if ($target === null) {
            throw new BusinessException(sprintf('目标人员主档不存在或已失效: %s', $targetCode));
        }

        $db = $this->model->getDb();
        $db->transStart();

        try {
            $now = date('Y-m-d H:i:s');

            // 1. 源主档置无效 + 标记合并至
            $this->model->exec(sprintf(
                'UPDATE hr_person SET 有效标识="0", 合并至=%s, 操作记录="合并", 操作来源="主档合并",
                 操作人员=%s, 操作时间=%s, 结束操作时间=%s
                 WHERE 人员编码=%s AND 有效标识="1" AND 删除标识="0"',
                $this->model->quote($targetCode),
                $this->model->quote($operator),
                $this->model->quote($now),
                $this->model->quote($now),
                $this->model->quote($sourceCode)
            ));

            // 审计：源主档合并事件
            (new AuditLogService())->logEvent([
                '人员编码'   => $sourceCode,
                '表名'      => 'hr_person',
                '记录GUID'  => (int) ($source['GUID'] ?? 0),
                '记录UUID'  => $source['UUID'] ?? null,
                '操作类型'  => '合并',
                '变更字段'  => '有效标识,合并至',
                '原值'      => '1,',
                '新值'      => sprintf('0,%s', $targetCode),
                '操作人员'  => $operator,
                '操作来源'  => '主档合并',
            ]);

            // 2. 下游四表：有效行人员编码从源改为目标
            $stageTables = ['ee_store', 'ee_interview', 'ee_train', 'ee_onjob'];
            $totalAffected = 0;
            foreach ($stageTables as $table) {
                $sql = sprintf(
                    'UPDATE `%s` SET 人员编码=%s
                     WHERE 人员编码=%s AND 有效标识="1" AND 删除标识="0"',
                    $table,
                    $this->model->quote($targetCode),
                    $this->model->quote($sourceCode)
                );
                $affected = $this->model->exec($sql);
                $totalAffected += $affected;

                if ($affected > 0) {
                    // 审计：下游表人员编码变更
                    (new AuditLogService())->logEvent([
                        '人员编码'   => $targetCode,
                        '候选人编码' => '',
                        '表名'      => $table,
                        '记录GUID'  => 0,
                        '操作类型'  => '合并联动',
                        '变更字段'  => '人员编码',
                        '原值'      => $sourceCode,
                        '新值'      => $targetCode,
                        '操作人员'  => $operator,
                        '操作来源'  => '主档合并',
                    ]);
                }
            }

            // 3. 目标主档：非空字段补齐（从源主档取值，空值跳过）
            $personData = [];
            foreach (self::PERSON_FIELDS as $field) {
                $sourceVal = trim((string) ($source[$field] ?? ''));
                $targetVal = trim((string) ($target[$field] ?? ''));
                if ($sourceVal !== '' && $targetVal === '') {
                    $personData[$field] = $sourceVal;
                }
            }
            if (!empty($personData)) {
                $this->updatePersonFields($targetCode, $personData, $operator);
            }
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }

        $db->transComplete();
        if ($db->transStatus() === false) {
            throw new BusinessException('重档合并失败:事务提交已回滚');
        }

        return $totalAffected;
    }

    /**
     * 构建列值：身份证号空值写 NULL（唯一索引语义：NULL 不判重，空串判重）
     */
    private function buildValue(string $key, mixed $value): string
    {
        if ($key === '身份证号' && ($value === '' || $value === null)) {
            return 'NULL';
        }
        return $this->model->quote((string) $value);
    }

    /**
     * 从身份证号派生性别（18 位第 17 位：奇=男，偶=女）
     *
     * 与 2026-09 存量回填口径一致；格式不合法（非 18 位或含异常字符）返回 null。
     */
    private function deriveGenderFromIdCard(string $idcard): ?string
    {
        $idcard = trim($idcard);
        if (!preg_match('/^\d{17}[\dXx]$/', $idcard)) {
            return null;
        }
        return (((int) substr($idcard, 16, 1)) % 2 === 1) ? '男' : '女';
    }

    /**
     * 从身份证号派生出生日期（第 7-14 位：YYYYMMDD）
     *
     * 日期需真实存在（checkdate 校验，月 01-12、日符合该月天数），
     * 与 2026-09 存量回填口径一致（含 1900-2099 年份段约束）；
     * 格式不合法或非真实日期（如 0651）返回 null。
     */
    private function deriveBirthFromIdCard(string $idcard): ?string
    {
        $idcard = trim($idcard);
        // 6位地址码 + 出生日期8位(年份限19xx/20xx、月01-12、日01-31) + 顺序码3位 + 校验位1位
        if (!preg_match('/^\d{6}(19|20)\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])\d{3}[\dXx]$/', $idcard)) {
            return null;
        }
        $y = (int) substr($idcard, 6, 4);
        $m = (int) substr($idcard, 10, 2);
        $d = (int) substr($idcard, 12, 2);
        if (!checkdate($m, $d, $y)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }
}
