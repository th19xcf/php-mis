<?php

namespace App\Libraries;

/**
 * 批量编辑 SQL 构造器（纯函数集）
 *
 * 从 BatchEditService 机械抽取的 SQL 拼装与行合并逻辑，不持有状态、不触 DB。
 *
 * 约定：
 * - 转义统一通过 $quote callable 注入（Mcommon::quote），保持可测试性
 * - 各方法的 SQL 文本与抽取前逐字一致，特征测试（BatchEditServiceSqlTest）锁定
 * - SKIP_FIELDS 为流水/审计控制列清单，SET 构造与 diff 字段提取共用
 */
class BatchEditSqlBuilder
{
    /** 流水/审计控制列：不参与业务字段更新与 diff */
    public const SKIP_FIELDS = ['操作记录', '操作来源', '操作人员', '操作时间', '结束操作时间', '删除标识'];

    /**
     * SET 片段列表（排除主键与控制列）
     *
     * 注意：主键排除按整串比较（复合主键 'pk1;pk2' 时各字段仍进 SET，写回原值，无害）
     *
     * @param callable(string):string $quote
     * @return array<int, string>
     */
    public static function buildSetClauses(array $data, string $primaryKey, array $skipFields, callable $quote): array
    {
        $updates = [];
        foreach ($data as $key => $value) {
            if ($key !== $primaryKey && !in_array($key, $skipFields, true)) {
                $updates[] = sprintf('`%s` = %s', $key, $quote((string) $value));
            }
        }
        return $updates;
    }

    /**
     * 主键值去重并转义（保持首次出现顺序）
     *
     * @param array<int, mixed> $keyValues
     * @param callable(string):string $quote
     * @return array{raw: string[], quoted: string[]}
     */
    public static function dedupeKeyValues(array $keyValues, callable $quote): array
    {
        $rawKeyValues = [];
        $quotedKeyValues = [];
        foreach ($keyValues as $keyVal) {
            $raw = (string) $keyVal;
            if (!in_array($raw, $rawKeyValues, true)) {
                $rawKeyValues[] = $raw;
                $quotedKeyValues[] = $quote($raw);
            }
        }
        return ['raw' => $rawKeyValues, 'quoted' => $quotedKeyValues];
    }

    /**
     * 主键 IN 条件（无空格分隔，与原实现一致）
     *
     * @param array<int, string> $quotedValues 已转义的主键值
     */
    public static function buildWhereIn(string $primaryKey, array $quotedValues): string
    {
        return sprintf('`%s` IN (%s)', $primaryKey, implode(',', $quotedValues));
    }

    /**
     * 前置校验：payload 中缺失（全行无非空值）的主键字段清单
     *
     * @return array<int, string> 缺失的主键字段名
     */
    public static function findMissingPrimaryKeyFields(array $rows, string $primaryKey): array
    {
        $primaryKeyFields = array_map('trim', explode(';', $primaryKey));
        $missingKeys = [];
        foreach ($primaryKeyFields as $pk) {
            $has = false;
            foreach ($rows as $row) {
                if (array_key_exists($pk, $row) && $row[$pk] !== '' && $row[$pk] !== null) {
                    $has = true;
                    break;
                }
            }
            if (!$has) {
                $missingKeys[] = $pk;
            }
        }
        return $missingKeys;
    }

    /**
     * 行的可更新字段名（排除主键整串与控制列，保持行内顺序）
     *
     * @return array<int, string>
     */
    public static function updateFieldNames(array $row, string $primaryKey, array $skipFields): array
    {
        $fields = [];
        foreach ($row as $key => $value) {
            if ($key !== $primaryKey && !in_array($key, $skipFields, true)) {
                $fields[] = $key;
            }
        }
        return $fields;
    }

    /**
     * 表级模式 0 行分组：按排序后的更新字段集签名分组（同组走 CASE WHEN 批量）
     *
     * @return array<string, array{fields: string[], rows: array[]}> groupKey => 分组
     */
    public static function groupRowsByUpdateFields(array $rows, string $primaryKey, array $skipFields): array
    {
        $updateGroups = [];
        foreach ($rows as $row) {
            $updateFields = self::updateFieldNames($row, $primaryKey, $skipFields);
            if (empty($updateFields)) {
                continue;
            }
            sort($updateFields);
            $groupKey = implode('|', $updateFields);

            if (!isset($updateGroups[$groupKey])) {
                $updateGroups[$groupKey] = [
                    'fields' => $updateFields,
                    'rows'   => [],
                ];
            }
            $updateGroups[$groupKey]['rows'][] = $row;
        }
        return $updateGroups;
    }

    /**
     * CASE WHEN 批量 UPDATE SQL（同组多行，按主键分支取值）
     *
     * @param array<int, array> $groupRows 同字段集的多行
     * @param array<int, string> $updateFields 更新字段（已排序）
     * @param callable(string):string $quote
     */
    public static function buildCaseWhenUpdateSql(
        string $dataTable,
        array $groupRows,
        array $updateFields,
        string $primaryKey,
        callable $quote
    ): string {
        $caseStatements = [];
        $primaryKeyValues = [];

        foreach ($updateFields as $field) {
            $caseParts = [];
            foreach ($groupRows as $row) {
                $pkValue = $quote((string) ($row[$primaryKey] ?? ''));
                $fieldValue = $quote((string) ($row[$field] ?? ''));
                $caseParts[] = sprintf('WHEN `%s` = %s THEN %s', $primaryKey, $pkValue, $fieldValue);
                $primaryKeyValues[] = $pkValue;
            }
            $caseStatements[] = sprintf('`%s` = CASE %s ELSE `%s` END', $field, implode(' ', $caseParts), $field);
        }

        $primaryKeyValues = array_unique($primaryKeyValues);
        $whereIn = sprintf('`%s` IN (%s)', $primaryKey, implode(',', $primaryKeyValues));

        return sprintf(
            'UPDATE %s SET %s WHERE %s',
            $dataTable,
            implode(', ', $caseStatements),
            $whereIn
        );
    }

    /**
     * 流水旧记录批量置无效 UPDATE（操作/结束操作时间同源 $now）
     */
    public static function buildFlowInvalidationUpdateSql(
        string $dataTable,
        string $userWorkid,
        string $now,
        string $whereIn
    ): string {
        return sprintf(
            'UPDATE %s SET 操作记录="修改",操作来源="工作台",操作人员="%s",操作时间="%s",结束操作时间="%s",删除标识="1",有效标识="0" WHERE %s',
            $dataTable,
            $userWorkid,
            $now,
            $now,
            $whereIn
        );
    }

    /**
     * 流水版本新行（批量修改路径）
     *
     * 原行打底（跳过自增主键）-> formData 覆盖（array_key_exists 语义：null 也覆盖为 ''）
     * -> 审计字段强制覆盖。关联数组保证每列只出现一次，避免 "Column specified twice"。
     */
    public static function buildFlowVersionedRow(
        array $originalRow,
        array $formData,
        string $userWorkid,
        string $now,
        string $primaryKey
    ): array {
        $newRow = [];
        foreach ($originalRow as $key => $val) {
            if ($key === $primaryKey) {
                continue; // 主键为自增列，由数据库生成新值
            }
            $newRow[$key] = array_key_exists($key, $formData)
                ? (string) $formData[$key]
                : (string) $val;
        }
        $newRow['操作记录'] = '新增';
        $newRow['操作来源'] = '工作台';
        $newRow['操作人员'] = $userWorkid;
        $newRow['操作时间'] = $now;
        $newRow['结束操作时间'] = ''; // 有效记录留空，置失效时才写操作时间
        $newRow['删除标识'] = '0';
        $newRow['有效标识'] = '1';
        return $newRow;
    }

    /**
     * 流水版本新行（表级修改路径）
     *
     * 原行打底（跳过自增主键与控制列）-> 提交行覆盖（isset 语义：null 保留原值，
     * 与批量路径的 array_key_exists 语义相反）-> 审计字段追加。
     */
    public static function buildTableVersionedRow(
        array $originalRow,
        array $row,
        string $userWorkid,
        string $now,
        string $primaryKey,
        array $skipFields
    ): array {
        $newRow = [];
        foreach ($originalRow as $key => $val) {
            if ($key === $primaryKey || in_array($key, $skipFields, true)) {
                continue;
            }
            $newRow[$key] = isset($row[$key]) ? (string) $row[$key] : (string) $val;
        }
        $newRow['操作记录'] = '新增';
        $newRow['操作来源'] = '工作台';
        $newRow['操作人员'] = $userWorkid;
        $newRow['操作时间'] = $now;
        $newRow['结束操作时间'] = ''; // 有效记录留空，置失效时才写操作时间
        $newRow['删除标识'] = '0';
        $newRow['有效标识'] = '1';
        return $newRow;
    }

    /**
     * 多值 INSERT SQL（列模板取首行键序）
     *
     * @param array<int, array> $newRows 已合并完成的新版本行（非空）
     * @param callable(string):string $quote
     */
    public static function buildMultiRowInsertSql(string $dataTable, array $newRows, callable $quote): string
    {
        $rowTemplate = null;
        $insertValuesList = [];
        foreach ($newRows as $newRow) {
            if ($rowTemplate === null) {
                $rowTemplate = array_keys($newRow);
            }
            $insertValuesList[] = '(' . implode(', ', array_map(
                fn($v) => $quote((string) $v),
                array_values($newRow)
            )) . ')';
        }

        return sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $dataTable,
            implode(', ', array_map(fn($k) => sprintf('`%s`', $k), $rowTemplate)),
            implode(', ', $insertValuesList)
        );
    }

    /**
     * 根据数据行与主键构建 WHERE 条件（分号分隔的复合主键；无反引号、= 无空格、and 连接）
     *
     * @param callable(string):string $quote
     */
    public static function buildWhereFromPrimaryKey(array $data, string $primaryKey, callable $quote): string
    {
        $keys = explode(';', $primaryKey);
        $conditions = [];

        foreach ($keys as $key) {
            $key = trim($key);
            if (isset($data[$key])) {
                $conditions[] = sprintf('%s=%s', $key, $quote((string) $data[$key]));
            }
        }

        return implode(' and ', $conditions);
    }

    /**
     * 过滤实际命中的主键值（预取结果中存在）
     *
     * @param array<int, string> $rawKeyValues 去重后的主键原始值
     * @param array<string, array> $originalRows 主键值 => 原始行
     * @return array<int, string> 命中的主键原始值
     */
    public static function filterHitKeyValues(array $rawKeyValues, array $originalRows): array
    {
        $hitKeyValues = [];
        foreach ($rawKeyValues as $raw) {
            if (isset($originalRows[$raw])) {
                $hitKeyValues[] = $raw;
            }
        }
        return $hitKeyValues;
    }

    /**
     * 提取 diff 字段（排除主键与控制列，保留原值不转型）
     *
     * @return array<string, mixed> 字段名 => 新值
     */
    public static function extractDiffData(array $row, string $primaryKey, array $skipFields): array
    {
        $diffData = [];
        foreach ($row as $key => $value) {
            if ($key === $primaryKey || in_array($key, $skipFields, true)) {
                continue;
            }
            $diffData[$key] = $value;
        }
        return $diffData;
    }
}
