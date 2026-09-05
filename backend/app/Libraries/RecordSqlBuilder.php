<?php

namespace App\Libraries;

/**
 * 记录级 SQL 构造器
 *
 * 从 BaseApiController 机械抽取的纯函数集（逻辑零变更）。
 * 当前种子：标识符校验 + UUIDv7 生成；
 * Phase 3 将扩展 INSERT/UPDATE/DELETE 的 SQL 与参数构造纯函数。
 */
class RecordSqlBuilder
{
    /**
     * 生成 UUIDv7（RFC 4122，16 字节二进制）
     *
     * UUIDv7 布局：
     *  - bytes[0-5]  (48 bit): Unix 时间戳（毫秒，big-endian）
     *  - bytes[6]    ( 4 bit): 版本 = 0111（7）
     *  - bytes[6-7]  (12 bit): 随机
     *  - bytes[8]    ( 2 bit): 变体 = 10
     *  - bytes[8-15] (62 bit): 随机
     *
     * @return string 16 字节二进制字符串（可直接写入 binary(16) 列）
     */
    public static function generateUuidv7Binary(): string
    {
        $tsMs = (int) (microtime(true) * 1000);

        // 48 bit 时间戳 → 6 字节 big-endian
        $timeBytes = '';
        for ($i = 5; $i >= 0; $i--) {
            $timeBytes .= chr(($tsMs >> ($i * 8)) & 0xFF);
        }

        // 10 字节随机数
        $randBytes = random_bytes(10);

        // byte[6] 高 4 位设为 0111（版本 7）
        $randBytes[0] = chr((ord($randBytes[0]) & 0x0F) | 0x70);

        // byte[8] 高 2 位设为 10（RFC 4122 变体）
        $randBytes[2] = chr((ord($randBytes[2]) & 0x3F) | 0x80);

        return $timeBytes . $randBytes;
    }

    /**
     * 判断是否需要为新增记录自动生成 UUID
     *
     * 条件：表字段列表非空 且 表存在 UUID 列 且 调用方未提供 UUID
     *
     * @param array $columns 表字段列表（getTableColumns 结果）
     * @param array $data    调用方输入数据
     */
    public static function shouldAutoGenerateUuid(array $columns, array $data): bool
    {
        return !empty($columns) && in_array('UUID', $columns, true) && !isset($data['UUID']);
    }

    /**
     * 校验 SQL 标识符（表名/字段名）合法性
     *
     * 允许：中文、英文字母、数字、下划线，首字符不能为数字
     * 阻止：SQL 注入特殊字符（引号、分号、空格、注释符等）
     *
     * @param string $identifier 待校验的表名或字段名
     * @return bool 合法返回 true
     */
    public static function isValidIdentifier(string $identifier): bool
    {
        if ($identifier === '') {
            return false;
        }
        // 允许中文(\p{Han})、字母、数字、下划线，首字符不能为数字
        return preg_match('/^[\p{Han}a-zA-Z_][\p{Han}a-zA-Z0-9_]*$/u', $identifier) === 1;
    }

    /**
     * 计算 UPDATE 实际会写入数据库的字段键列表
     *
     * 跳过规则（与原 updateRecord 内联逻辑逐条一致）：
     * - 键为 guid/操作/人员 → 跳过
     * - 非法标识符 → 跳过
     * - 值为空串 → 跳过
     * - 表列非空且不含该键 → 跳过
     *
     * @param array $data    调用方输入数据
     * @param array $columns 表字段列表（getTableColumns 结果）
     * @return string[] 实际写入键列表（保持输入顺序）
     */
    public static function computeEffectiveUpdateKeys(array $data, array $columns): array
    {
        $effectiveUpdateKeys = [];
        foreach ($data as $key => $value) {
            if (in_array($key, ['guid', '操作', '人员'])) continue;
            if (!self::isValidIdentifier($key)) continue;
            if ($value === '') continue;
            if (!empty($columns) && !in_array($key, $columns, true)) continue;
            $effectiveUpdateKeys[] = $key;
        }
        return $effectiveUpdateKeys;
    }

    /**
     * 构造 INSERT SQL（insertRecord 用）
     *
     * 跳过规则：'操作' 键、非法标识符、表列过滤；自动 UUID 以 0x+32hex 追加。
     *
     * @param string   $table    目标表名（已通过 isValidIdentifier）
     * @param array    $data     输入数据
     * @param array    $columns  表字段列表
     * @param string|null $autoUuid 自动生成的 UUIDv7（binary 16）；null = 不追加
     * @param callable $quote    转义回调 fn(string $value): string
     * @return string|null SQL；null = 全部字段被过滤（无可写字段）
     */
    public static function buildInsertSql(string $table, array $data, array $columns, ?string $autoUuid, callable $quote): ?string
    {
        $fields = [];
        $values = [];

        foreach ($data as $key => $value) {
            if ($key === '操作') continue;
            if (!self::isValidIdentifier($key)) continue;
            // 过滤掉表中不存在的字段（如老表无"操作时间"列）
            if (!empty($columns) && !in_array($key, $columns, true)) continue;
            $fields[] = sprintf('`%s`', $key);
            $values[] = $quote((string)$value);
        }

        // 追加自动生成的 UUID（binary(16) 用 0x 十六进制格式写入）
        if ($autoUuid !== null) {
            $fields[] = '`UUID`';
            $values[] = '0x' . bin2hex($autoUuid);
        }

        if (empty($fields)) {
            return null;
        }

        return sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(',', $fields),
            implode(',', $values)
        );
    }

    /**
     * 构造 UPDATE SQL（updateRecord 用）
     *
     * @param string   $table         目标表名（已通过 isValidIdentifier）
     * @param array    $data          输入数据
     * @param string[] $effectiveKeys 实际写入键列表（computeEffectiveUpdateKeys 结果）
     * @param string   $where         WHERE 子句（调用方构造）
     * @param callable $quote         转义回调
     */
    public static function buildUpdateSql(string $table, array $data, array $effectiveKeys, string $where, callable $quote): string
    {
        $updateFields = [];
        foreach ($effectiveKeys as $key) {
            $updateFields[] = sprintf('`%s`=%s', $key, $quote((string)$data[$key]));
        }

        return sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            $table,
            implode(',', $updateFields),
            $where
        );
    }

    /**
     * 构造旧值快照 SELECT SQL（updateRecord/deleteRecord 共用）
     *
     * 列组成：GUID + UUID（表存在该列时）+ $extraCols 定位列（人员审计表）+ $keys 受影响字段。
     *
     * @param string   $table     目标表名
     * @param string   $where     WHERE 子句
     * @param array    $columns   表字段列表
     * @param string[] $keys      追加的业务字段键（update 传 effectiveKeys，delete 传 []）
     * @param string[] $extraCols 定位列候选（如 ['人员编码','候选人编码']；仅在表列存在时追加）
     */
    public static function buildSnapshotSelectSql(string $table, string $where, array $columns, array $keys, array $extraCols = []): string
    {
        $selectCols = ['GUID'];
        if (in_array('UUID', $columns, true)) {
            $selectCols[] = 'UUID';
        }
        foreach ($extraCols as $locator) {
            if (in_array($locator, $columns, true) && !in_array($locator, $selectCols, true)) {
                $selectCols[] = $locator;
            }
        }
        foreach ($keys as $k) {
            if (!in_array($k, $selectCols, true)) {
                $selectCols[] = $k;
            }
        }
        $colList = implode(',', array_map(fn($c) => "`{$c}`", $selectCols));
        return "SELECT {$colList} FROM `{$table}` WHERE {$where}";
    }

    /**
     * 构造软删 UPDATE SQL（deleteRecord 用）
     *
     * 审计字段经表列过滤；表存在"记录结束日期"列（或表列未知）时追加当日日期。
     *
     * @param string   $table      目标表名
     * @param string   $where      WHERE 子句
     * @param array    $deleteData 软删审计字段（buildDeleteData 结果）
     * @param array    $columns    表字段列表
     * @param callable $quote      转义回调
     * @return string|null SQL；null = 无可写字段
     */
    public static function buildSoftDeleteUpdateSql(string $table, string $where, array $deleteData, array $columns, callable $quote): ?string
    {
        $updateFields = [];

        foreach ($deleteData as $key => $value) {
            if (!empty($columns) && !in_array($key, $columns, true)) continue;
            $updateFields[] = sprintf('`%s`=%s', $key, $quote($value));
        }

        // 记录结束日期：仅当表存在该列时才写入
        if (empty($columns) || in_array('记录结束日期', $columns, true)) {
            $updateFields[] = sprintf('`记录结束日期`=%s', $quote(date('Y-m-d')));
        }

        if (empty($updateFields)) {
            return null;
        }

        return sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            $table,
            implode(',', $updateFields),
            $where
        );
    }

    /**
     * 构造 def_audit_log INSERT SQL（writeAuditLog 用）
     *
     * - 记录UUID 为 NULL 时用 0x00×16 占位（满足 binary(16) NOT NULL 约束）
     * - 原值/新值截断到 200 字符（匹配 varchar(200) 列定义），NULL 写 NULL 字面量
     *
     * 拼接说明：CI4 MySQLi 的 $db->query(sql, binds) 走 Query Builder 预处理，
     * 对原生 INSERT 抛 "You must set the database table" 错误，
     * 故用 quote() + 0x 十六进制格式内联写入，兼容 binary(16) UUID。
     *
     * @param string      $table    业务表名
     * @param string      $pkGuid   业务记录 GUID（字符串形式）
     * @param string|null $pkUuid   业务记录 UUID（binary 16 字节）
     * @param string      $opType   操作类型（新增/更新/删除）
     * @param string      $field    变更字段名（INSERT/DELETE 用"全部"）
     * @param string|null $oldValue 原值
     * @param string|null $newValue 新值
     * @param string      $operator 操作人员
     * @param callable    $quote    转义回调
     */
    public static function buildAuditInsertSql(
        string $table,
        string $pkGuid,
        ?string $pkUuid,
        string $opType,
        string $field,
        ?string $oldValue,
        ?string $newValue,
        string $operator,
        callable $quote
    ): string {
        // UUID 兜底：NULL 时用 16 字节 0x00 占位（满足 binary(16) NOT NULL 约束）
        $uuidBin = $pkUuid ?? str_repeat("\x00", 16);

        // 原值/新值截断到 200 字符，NULL 保持 NULL 以写入 NULL（而非字符串 'NULL'）
        $oldValTrimmed = $oldValue !== null ? mb_substr((string)$oldValue, 0, 200) : null;
        $newValTrimmed = $newValue !== null ? mb_substr((string)$newValue, 0, 200) : null;

        return sprintf(
            "INSERT INTO def_audit_log (表名, 记录GUID, 记录UUID, 操作类型, 变更字段, 原值, 新值, 操作人员) "
          . "VALUES (%s, %s, 0x%s, %s, %s, %s, %s, %s)",
            $quote($table),
            $quote($pkGuid),
            bin2hex($uuidBin),
            $quote($opType),
            $quote($field),
            $oldValTrimmed !== null ? $quote($oldValTrimmed) : 'NULL',
            $newValTrimmed !== null ? $quote($newValTrimmed) : 'NULL',
            $quote($operator)
        );
    }

    /**
     * 收集 UPDATE 审计条目（updateRecord diff 环路）
     *
     * 值比对语义：NULL 与空串视为相同（(string) 强转比较）；
     * 变更条目携带原值/新值（null 保持 null，写 NULL 字面量）。
     *
     * @param array   $oldRows       旧值快照行集
     * @param array   $data          输入数据
     * @param string[] $effectiveKeys 实际写入键列表
     * @return array<int, array{guid: string, uuid: string|null, field: string, old: string|null, new: string|null}>
     */
    public static function collectUpdateAuditEntries(array $oldRows, array $data, array $effectiveKeys): array
    {
        $entries = [];
        foreach ($oldRows as $oldRow) {
            $rowGuid = (string)($oldRow['GUID'] ?? '');
            $rowUuid = $oldRow['UUID'] ?? null;

            foreach ($effectiveKeys as $field) {
                $oldVal = $oldRow[$field] ?? null;
                $newVal = $data[$field] ?? null;

                // 值未变化则跳过（NULL 与空串视为相同）
                if ((string)$oldVal === (string)$newVal) continue;

                $entries[] = [
                    'guid' => $rowGuid,
                    'uuid' => $rowUuid,
                    'field' => $field,
                    'old' => $oldVal !== null ? (string)$oldVal : null,
                    'new' => $newVal !== null ? (string)$newVal : null,
                ];
            }
        }
        return $entries;
    }
}
