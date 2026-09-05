<?php

namespace App\Libraries;

/**
 * 字段归属路由器（写入路由）
 *
 * 从 BaseApiController::splitDataByFieldOwner 机械抽取的纯函数（逻辑零变更）。
 * 配置读取与表列读取留在控制器（含异常吞噬），本类只做"配置行 + 输入数据 → 分组"的纯转换；
 * 原 logTrace warning 经 callable $warn 注入。
 */
class FieldOwnerRouter
{
    /**
     * 按字段归属表拆分输入数据
     *
     * 依据 def_query_column.字段归属表 配置（经 view_function 视图读取）：
     * - 字段归属表为空 → 归主表（兼容存量配置，零迁移）
     * - 字段归属表=表名（如 hr_person）→ 归该表
     * - 输入字段未出现在配置中 → 归主表
     * - 配置值含逗号等非法字符 → 视为脏配置，按主表处理并经 $warn 留痕
     *
     * 兼容双写：归属非主表的字段，若主表存在同名列，同时写入主表分组，
     * 保证存量单表查询（如 ee_store 列表/树查询）在主档过渡期继续可用。
     *
     * @param array          $viewColumns   view_function 行数组（含 字段名/查询名/字段归属表）
     * @param string         $mainTable     主表名（如 ee_store）
     * @param array          $mainTableCols 主表实际列名列表
     * @param array          $data          输入数据（字段名 => 值）
     * @param callable|null  $warn          warning 回调 fn(string $message): void
     * @return array<string, array> 表名 => 字段映射（至少含主表分组，可能为空）
     */
    public static function split(array $viewColumns, string $mainTable, array $mainTableCols, array $data, ?callable $warn = null): array
    {
        $groups = [$mainTable => []];
        $ownerMap = [];

        foreach ($viewColumns as $col) {
            $owner = trim((string) ($col['字段归属表'] ?? ''));
            if ($owner === '') {
                continue; // 空配置归主表，不进映射
            }
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $owner)) {
                // 逗号多值等脏配置：按主表处理并留痕
                if ($warn !== null) {
                    $warn("[splitDataByFieldOwner] 非法字段归属表配置: {$owner}");
                }
                continue;
            }
            // 查询名与字段名都建立映射，兼容前端两种字段名
            $queryName = (string) ($col['查询名'] ?? '');
            $fieldName = (string) ($col['字段名'] ?? '');
            if ($queryName !== '') {
                $ownerMap[$queryName] = $owner;
            }
            if ($fieldName !== '') {
                $ownerMap[$fieldName] = $owner;
            }
        }

        foreach ($data as $key => $value) {
            if ($key === 'guid' || $key === '操作') {
                continue;
            }
            $owner = $ownerMap[$key] ?? $mainTable;
            $groups[$owner][$key] = $value;
            // 兼容双写：主表有同名列时同步写入（过渡期存量查询依赖）
            if ($owner !== $mainTable && in_array($key, $mainTableCols, true)) {
                $groups[$mainTable][$key] = $value;
            }
        }

        return $groups;
    }
}
