<?php

namespace App\Services\Workbench;

use App\Models\Mcommon;

/**
 * 工作台 SQL 构造工具（纯静态方法）
 *
 * 抽出 QueryService 中的纯工具方法，便于在多个查询入口（queryTotalCount /
 * queryRecords / queryRecordsPaged / buildDebugQuery）之间消除重复，并使
 * QueryService 聚焦于"组装查询流程"而非"拼写 SQL 片段"。
 *
 * 设计原则：
 *  - 仅收录无状态、可独立测试的纯函数；
 *  - 不持有任何实例字段，所有依赖通过参数显式传递（如 Mcommon 用于 quote）；
 *  - 不引入 Builder 模式（实际复用面较窄，避免过度抽象）。
 */
class WorkbenchSqlHelper
{
    /**
     * 转义 LIKE 元字符：% _ \
     *
     * 转义后用户输入的"50%"会被当作字面量"50%"，不会匹配"50xx"。
     * 配合 ESCAPE '\\' 让 MySQL 知道反斜杠是转义符。
     */
    public static function escapeLikeWildcards(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    /**
     * 构建单条 WHERE 条件（fieldName OP value）
     *
     * - equals             -> fieldName = 'value'
     * - notEqual           -> fieldName != 'value'
     * - greaterThan        -> fieldName > 'value'
     * - greaterThanOrEqual -> fieldName >= 'value'
     * - lessThan           -> fieldName < 'value'
     * - lessThanOrEqual    -> fieldName <= 'value'
     * - isNull             -> fieldName IS NULL
     * - isNotNull          -> fieldName IS NOT NULL
     * - startsWith         -> fieldName like 'value%'
     * - endsWith           -> fieldName like '%value'
     * - 其他               -> fieldName like '%value%'
     */
    public static function buildSingleCondition(Mcommon $model, string $fieldName, string $operator, string $value): string
    {
        switch ($operator) {
            case 'equals':
                return sprintf('%s=%s', $fieldName, $model->quote($value));
            case 'notEqual':
                return sprintf('%s!=%s', $fieldName, $model->quote($value));
            case 'greaterThan':
                return sprintf('%s>%s', $fieldName, $model->quote($value));
            case 'greaterThanOrEqual':
                return sprintf('%s>=%s', $fieldName, $model->quote($value));
            case 'lessThan':
                return sprintf('%s<%s', $fieldName, $model->quote($value));
            case 'lessThanOrEqual':
                return sprintf('%s<=%s', $fieldName, $model->quote($value));
            case 'isNull':
                // ag-grid "空" 语义：NULL 或空字符串
                return sprintf('(%s IS NULL OR %s=%s)', $fieldName, $fieldName, $model->quote(''));
            case 'isNotNull':
                // ag-grid "非空" 语义：既非 NULL 也非空字符串
                return sprintf('(%s IS NOT NULL AND %s!=%s)', $fieldName, $fieldName, $model->quote(''));
            case 'startsWith':
                return sprintf('%s like %s', $fieldName, $model->quote($value . '%'));
            case 'endsWith':
                return sprintf('%s like %s', $fieldName, $model->quote('%' . $value));
            default:
                return sprintf('%s like %s', $fieldName, $model->quote('%' . $value . '%'));
        }
    }

    /**
     * 构建列映射：按"列名"和"字段名"双键索引同一份列配置
     *
     * 设计原因：ag-grid 列筛选的 colId 实际是"字段名"（SQL 字段），
     * 而工作台配置（条件面板/全局检索）通常以"列名"作为业务键。
     * 为兼容两种来源的 key，统一放入同一张映射。
     *
     * @param array $columns 列配置（来自 query_columns 表）
     * @return array 列名/字段名 -> 列配置
     */
    public static function buildColumnMap(array $columns): array
    {
        // 字段名映射：配置中的字段名 → 实际数据库列名
        $fieldAliasMap = [
            '操作人' => '操作人员',
        ];

        $columnMap = [];
        foreach ($columns as $column) {
            $columnName = (string) ($column['列名'] ?? '');
            $fieldName = (string) ($column['字段名'] ?? '');

            // 自动映射字段名
            if (isset($fieldAliasMap[$fieldName])) {
                $column['字段名'] = $fieldAliasMap[$fieldName];
                $fieldName = $fieldAliasMap[$fieldName];
            }

            if ($columnName !== '') {
                $columnMap[$columnName] = $column;
            }
            if ($fieldName !== '' && $fieldName !== $columnName) {
                $columnMap[$fieldName] = $column;
            }
        }
        return $columnMap;
    }

    /**
     * 构建跨字段快速检索的 OR LIKE 列表
     *
     * 只对文本类字段（列类型 = 字符 / 文本 / 空）参与检索，
     * 自动转义用户输入的 LIKE 元字符（% _ \），避免被当作通配符。
     *
     * @param array $columnMap 列定义数组（key=列名, value=列配置）
     * @param string $searchTerm 用户输入的关键词
     * @return array OR LIKE 片段数组
     */
    public static function buildGlobalSearchOrParts(Mcommon $model, array $columnMap, string $searchTerm): array
    {
        $escaped = self::escapeLikeWildcards($searchTerm);
        $likeValue = $model->quote('%' . $escaped . '%') . " ESCAPE '\\\\'";

        $orParts = [];
        foreach ($columnMap as $column) {
            $fieldName = trim((string) ($column['字段名'] ?? ''));
            if ($fieldName === '') {
                continue;
            }
            $fieldType = (string) ($column['列类型'] ?? '字符');
            if (!in_array($fieldType, ['字符', '文本', ''], true)) {
                continue;
            }
            $orParts[] = sprintf('%s like %s', $fieldName, $likeValue);
        }
        return $orParts;
    }
}
