<?php

namespace App\Libraries;

use App\Exceptions\BusinessException;

/**
 * detail() SELECT 字段列表构造器
 *
 * 从 BaseApiController::buildDetailSelectFields / buildDetailSelectFieldsMulti
 * 机械抽取的纯函数（逻辑与异常文案零变更）。
 * 配置读取（getViewFunctionColumns）与表列读取（getTableColumns）留在控制器，
 * 本类只做"配置行 + 表列 → SELECT 片段"的纯转换。
 */
class DetailSelectFieldBuilder
{
    /**
     * 构建单表 SELECT 字段列表（配置驱动，无兜底）
     *
     * 1. 配置字段与目标表实际列交叉验证（防配置错误导致 SQL 报错）
     * 2. 查询名≠字段名时加别名，否则裸字段
     * 3. 配置为空或全部不匹配时抛 BusinessException，直接暴露配置问题
     *
     * @param string $functionCode 功能编码（如 '2015'），仅用于异常文案
     * @param string $table        目标表名（如 'ee_store'），仅用于异常文案
     * @param array  $columns      view_function 行数组（含 字段名/查询名）
     * @param array  $tableCols    目标表实际列名列表
     * @return string 反引号包裹的逗号分隔字段列表，如 `GUID`,`候选人编码`,`姓名`
     * @throws BusinessException 配置为空或字段均不匹配时
     */
    public static function buildSelectFields(string $functionCode, string $table, array $columns, array $tableCols): string
    {
        $tableColSet = $tableCols ? array_flip($tableCols) : [];

        $parts = [];
        foreach ($columns as $col) {
            $fieldName = (string) ($col['字段名'] ?? '');
            if ($fieldName === '' || !isset($tableColSet[$fieldName])) {
                continue;
            }
            $queryName = (string) ($col['查询名'] ?? '');
            if ($queryName !== '' && $queryName !== $fieldName) {
                $parts[] = "`{$fieldName}` as `{$queryName}`";
            } else {
                $parts[] = "`{$fieldName}`";
            }
        }

        if (empty($parts)) {
            throw new BusinessException(
                "功能编码 {$functionCode} 的 view_function 配置为空或字段均不匹配表 {$table}，请检查 def_query_column 配置并刷新缓存"
            );
        }

        return implode(',', $parts);
    }

    /**
     * 构建多表 JOIN SELECT 字段列表（配置驱动）
     *
     * buildSelectFields 的多表版本（阶段③读切换：主表窄化后
     * 个人信息列在 JOIN 表，如 ee_employment + hr_person）：
     * 1. 配置字段按「别名 → 表实际列」逐一定位归属，加别名前缀
     * 2. 不在任何表的配置字段（已裁剪列）以空串占位，保持 API 出参形状
     * 3. 配置为空时抛 BusinessException（文案与单表版不同）
     *
     * @param string $functionCode 功能编码，仅用于异常文案
     * @param array  $columns      view_function 行数组（含 字段名/查询名）
     * @param array  $tableCols    [别名 => 表实际列名列表]
     * @return string 逗号分隔的带别名前缀字段列表
     * @throws BusinessException 配置为空时
     */
    public static function buildSelectFieldsMulti(string $functionCode, array $columns, array $tableCols): string
    {
        $tableColMap = [];
        foreach ($tableCols as $alias => $cols) {
            $tableColMap[$alias] = $cols ? array_flip($cols) : [];
        }

        $parts = [];
        foreach ($columns as $col) {
            $fieldName = (string) ($col['字段名'] ?? '');
            if ($fieldName === '') {
                continue;
            }
            $queryName = (string) ($col['查询名'] ?? '');
            $output = ($queryName !== '' && $queryName !== $fieldName) ? $queryName : $fieldName;

            foreach ($tableColMap as $alias => $colSet) {
                if (isset($colSet[$fieldName])) {
                    $parts[] = "{$alias}.`{$fieldName}` as `{$output}`";
                    continue 2;
                }
            }
            // 配置字段不在任何表（如 ee_onjob 裁剪列）：空串占位保持出参形状
            $parts[] = sprintf("'' as `%s`", $output);
        }

        if (empty($parts)) {
            throw new BusinessException(
                "功能编码 {$functionCode} 的 view_function 配置为空，请检查 def_query_column 配置并刷新缓存"
            );
        }

        return implode(',', $parts);
    }
}
