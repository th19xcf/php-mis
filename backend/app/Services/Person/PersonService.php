<?php

namespace App\Services\Person;

use App\Exceptions\BusinessException;
use App\Models\Mcommon;

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
        '姓名', '身份证号', '手机号码', '性别', '年龄',
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
        $sets = [
            sprintf('`操作记录`=%s', $this->model->quote('修改')),
            sprintf('`操作来源`=%s', $this->model->quote('页面修改')),
            sprintf('`操作人员`=%s', $this->model->quote($operator)),
            sprintf('`操作时间`=%s', $this->model->quote(date('Y-m-d H:i:s'))),
        ];

        foreach ($fields as $key => $value) {
            if (in_array($key, ['人员编码', '合并至', 'GUID'], true)) {
                continue; // 关键列不允许通过页面修改
            }
            if ($value === '') {
                continue; // 空值跳过，防误清空
            }
            $sets[] = sprintf('`%s`=%s', $key, $this->buildValue($key, $value));
        }

        $sql = sprintf(
            'UPDATE hr_person SET %s WHERE 人员编码=%s AND 有效标识="1" AND 删除标识="0"',
            implode(',', $sets),
            $this->model->quote($personCode)
        );
        return $this->model->exec($sql);
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
     * 构建列值：身份证号空值写 NULL（唯一索引语义：NULL 不判重，空串判重）
     */
    private function buildValue(string $key, mixed $value): string
    {
        if ($key === '身份证号' && ($value === '' || $value === null)) {
            return 'NULL';
        }
        return $this->model->quote((string) $value);
    }
}
