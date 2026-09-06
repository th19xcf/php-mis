<?php

namespace App\Libraries;

/**
 * 导入 SQL 构造器（纯函数集）
 *
 * 从 ImportService 机械抽取的 SQL 拼装逻辑，不持有状态、不触 DB。
 * 执行路径（createTempTable/insertToTempTable/...）与调试路径（buildDebugImport）
 * 共用本类，消除双份维护导致的漂移（历史上调试版曾缺少空字段名防护、
 * INSERT 列取值口径与执行版不一致）。
 *
 * 约定：
 * - 转义统一通过 $quote callable 注入（Mcommon::quote），保持可测试性
 * - 各方法的 SQL 文本与抽取前逐字一致，特征测试（ImportServiceSqlTest）锁定
 */
class ImportSqlBuilder
{
    // ---- 临时表 DDL ----

    /**
     * 无列配置时的简单临时表 DDL
     */
    public static function buildSimpleCreateTableSql(string $tableName): string
    {
        return sprintf('CREATE TABLE `%s` (id int auto_increment primary key, data varchar(255))', $tableName);
    }

    /**
     * 临时表 DDL（执行语义）
     *
     * 字段名优先，为空时 fallback 列名，均空则跳过（防 Incorrect column name ''）
     *
     * @param array $columns 列配置（含 字段名/列名/字段长度/缺省值）
     * @param callable(string):string $quote
     * @return array{sql: string, fieldNames: string[]} SQL 与实际建表字段名（日志用）
     */
    public static function buildCreateTableSql(string $tableName, array $columns, callable $quote): array
    {
        $fieldDefs = [];
        $fieldNames = [];
        foreach ($columns as $col) {
            $fieldName = (string) ($col['字段名'] ?? '');
            if ($fieldName === '') {
                $fieldName = (string) ($col['列名'] ?? '');
            }
            if ($fieldName === '') {
                continue;
            }
            $fieldNames[] = $fieldName;
            $fieldLength = $col['字段长度'] ?? 255;
            $defaultValue = (string) ($col['缺省值'] ?? '');
            if ($defaultValue !== '') {
                $fieldDefs[] = sprintf('`%s` varchar(%s) not null default %s', $fieldName, $fieldLength, $quote($defaultValue));
            } else {
                $fieldDefs[] = sprintf('`%s` varchar(%s) not null default ""', $fieldName, $fieldLength);
            }
        }

        return [
            'sql' => sprintf('CREATE TABLE `%s` (%s)', $tableName, implode(',', $fieldDefs)),
            'fieldNames' => $fieldNames,
        ];
    }

    // ---- 临时表 INSERT ----

    /**
     * 缺省值映射：字段名 => 缺省值（仅两者均非空）
     *
     * @param array $importColumns 列配置
     * @return array<string, string>
     */
    public static function buildDefaultValueMap(array $importColumns): array
    {
        $map = [];
        foreach ($importColumns as $col) {
            $fieldName = $col['字段名'] ?? '';
            $defaultValue = (string) ($col['缺省值'] ?? '');
            if ($fieldName !== '' && $defaultValue !== '') {
                $map[$fieldName] = $defaultValue;
            }
        }
        return $map;
    }

    /**
     * 从数据首行提取 INSERT 字段（空字段名剔除）
     *
     * @param array $firstRow 数据首行
     * @return string[]
     */
    public static function deriveInsertFields(array $firstRow): array
    {
        return array_values(array_filter(array_keys($firstRow), static fn ($f) => $f !== ''));
    }

    /**
     * 临时表批量 INSERT（执行语义：字段取自数据首行 key）
     *
     * 空值（''/null）且配置了缺省值 → 写缺省值
     *
     * @param string[] $fields 字段列表
     * @param array $data 数据行集
     * @param array<string, string> $defaultValueMap
     * @param callable(string):string $quote
     */
    public static function buildInsertRowsSql(string $tableName, array $fields, array $data, array $defaultValueMap, callable $quote): string
    {
        $values = [];
        foreach ($data as $row) {
            $rowValues = [];
            foreach ($fields as $field) {
                $value = $row[$field] ?? '';
                if (($value === '' || $value === null) && isset($defaultValueMap[$field])) {
                    $value = $defaultValueMap[$field];
                }
                $rowValues[] = $quote((string) $value);
            }
            $values[] = '(' . implode(',', $rowValues) . ')';
        }

        $quotedFields = array_map(static fn ($f) => '`' . $f . '`', $fields);

        return sprintf(
            'INSERT INTO `%s` (%s) VALUES %s',
            $tableName,
            implode(', ', $quotedFields),
            implode(', ', $values)
        );
    }

    // ---- 临时表 → 正式表导入 ----

    /**
     * 字段映射环路（执行与调试共享）：查询名转换
     *
     * @param array $importColumns 列配置
     * @return array{fieldNames: string[], selectParts: string[]}
     */
    public static function buildImportFieldMapping(array $importColumns): array
    {
        $fieldNames = [];
        $selectParts = [];

        foreach ($importColumns as $col) {
            $fieldName = $col['字段名'] ?? $col['列名'] ?? '';
            $queryName = $col['查询名'] ?? '';

            if ($fieldName === '') {
                continue;
            }

            $fieldNames[] = sprintf('`%s`', $fieldName);

            if ($queryName !== '' && $queryName !== $fieldName) {
                $selectParts[] = sprintf('%s as `%s`', $queryName, $fieldName);
            } else {
                $selectParts[] = sprintf('`%s`', $fieldName);
            }
        }

        return ['fieldNames' => $fieldNames, 'selectParts' => $selectParts];
    }

    /**
     * INSERT ... SELECT 导入 SQL
     *
     * @param string[] $fieldNames 反引号包裹的 INSERT 列
     * @param string[] $selectParts SELECT 片段（含别名转换）
     * @param string $whereClause 导入条件（空串则无 WHERE）
     */
    public static function buildImportFromTempSql(string $targetTable, string $tempTable, array $fieldNames, array $selectParts, string $whereClause): string
    {
        $whereSql = $whereClause !== '' ? ' WHERE ' . $whereClause : '';

        return sprintf(
            'INSERT INTO `%s` (%s) SELECT %s FROM `%s`%s',
            $targetTable,
            implode(', ', $fieldNames),
            implode(', ', $selectParts),
            $tempTable,
            $whereSql
        );
    }

    // ---- 临时表数据校验 ----

    /**
     * 固定值校验 SQL（def_object 字典联查，属地过滤）
     */
    public static function buildFixedValueCheckSql(string $fieldName, string $tmpTableName, string $object, string $userLocationAuthz): string
    {
        return sprintf('
                    select
                        t1.字段名 as 字段名,
                        t1.字段值 as 字段值,
                        ifnull(t2.对象值,"") as 对象值
                    from
                    (
                        select "%s" as 字段名, `%s` as 字段值
                        from `%s`
                        group by 字段值
                    ) as t1
                    left join
                    (
                        select 对象名称,对象值
                        from def_object
                        where 对象名称="%s"
                            and (属地="" or locate(属地,"%s"))
                    ) as t2 on t1.字段值=t2.对象值
                    where t2.对象值 is null and t1.字段值 != ""
                ',
            $fieldName, $fieldName, $tmpTableName,
            $object, $userLocationAuthz);
    }

    /**
     * 条件校验 SQL（校验信息为 SQL 片段）
     */
    public static function buildConditionCheckSql(string $columnName, string $fieldName, string $tmpTableName, string $checkInfo): string
    {
        return sprintf(
            'select "%s" as 字段名, `%s` as 字段值 from `%s` where %s',
            $columnName, $fieldName, $tmpTableName, $checkInfo
        );
    }

    /**
     * 日期校验取数 SQL（PHP 端逐行正则 + checkdate）
     */
    public static function buildDateFetchSql(string $columnName, string $fieldName, string $tmpTableName): string
    {
        return sprintf(
            'select "%s" as 字段名, `%s` as 字段值 from `%s`',
            $columnName, $fieldName, $tmpTableName
        );
    }

    // ---- 滤重检查 ----

    /**
     * 解析滤重字段配置（"`,`" 分隔，如 "身份证号`,`姓名"）
     *
     * @return string[] 非空字段列表
     */
    public static function parseDuplicateFields(string $duplicateFields): array
    {
        $fieldList = array_map('trim', explode('`,`', $duplicateFields));
        return array_values(array_filter($fieldList, static fn ($f) => $f !== ''));
    }

    /**
     * 反引号包裹字段列表（", " 连接）
     *
     * @param string[] $fieldList
     */
    public static function quoteFieldList(array $fieldList): string
    {
        return implode(', ', array_map(static fn ($f) => sprintf('`%s`', $f), $fieldList));
    }

    /**
     * 临时表取数 SQL（仅本次导入批次）
     */
    public static function buildTempFetchSql(string $quotedFieldList, string $tmpTableName): string
    {
        return sprintf('select %s from `%s`', $quotedFieldList, $tmpTableName);
    }

    /**
     * 行构造子元组：任一字段为 NULL 则整行跳过（与原 concat 语义一致）
     *
     * @param array $rows 临时表行集
     * @param string[] $fieldList
     * @param callable(string):string $quote
     * @return string[] 如 ["('a','b')"]
     */
    public static function buildTuples(array $rows, array $fieldList, callable $quote): array
    {
        $tuples = [];
        foreach ($rows as $row) {
            $tupleParts = [];
            $hasNull = false;
            foreach ($fieldList as $f) {
                $val = $row[$f] ?? null;
                if ($val === null) {
                    $hasNull = true;
                    break;
                }
                $tupleParts[] = $quote((string) $val);
            }
            if ($hasNull) {
                continue;
            }
            $tuples[] = '(' . implode(',', $tupleParts) . ')';
        }
        return $tuples;
    }

    /**
     * 主表重复检查 SQL（行构造子 IN，裸列比较可走复合索引）
     *
     * @param string[] $tuples
     */
    public static function buildDuplicateCheckSql(string $duplicateFields, string $dataTable, string $quotedFieldList, array $tuples): string
    {
        return sprintf(
            'select `%s` from `%s` where (%s) in (%s)',
            $duplicateFields,
            $dataTable,
            $quotedFieldList,
            implode(',', $tuples)
        );
    }
}
