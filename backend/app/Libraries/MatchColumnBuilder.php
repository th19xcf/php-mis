<?php

namespace App\Libraries;

/**
 * 匹配页列定义构建器（纯函数集）
 *
 * 从 MatchApi 机械抽取的 view_function 列 → 前端表格列定义 / 匹配字段映射逻辑。
 * 列配置读取（resolveFunctionCodeByModule + getViewFunctionColumns）留在控制器。
 */
class MatchColumnBuilder
{
    /**
     * 构建 A/B 模块表格列定义（序号列打头 + view_function 逐列映射）
     *
     * @param array<int, array<string, mixed>> $rows view_function 列定义行集
     * @return array<int, array<string, mixed>>
     */
    public static function buildModuleColumns(array $rows): array
    {
        $columns = [[
            'field' => '序号',
            'title' => '序号',
            'type' => '数值',
            'width' => 90,
            'hidden' => false,
            'editable' => false,
            'sortable' => true,
            'original' => []
        ]];
        foreach ($rows as $row) {
            $title = (string) ($row['列名'] ?? '');
            $columns[] = [
                'field' => $title !== '' ? $title : (string) ($row['字段名'] ?? ''),
                'title' => (string) ($row['查询名'] ?? '') !== '' ? (string) $row['查询名'] : ($title !== '' ? $title : (string) ($row['字段名'] ?? '')),
                'type' => $row['列类型'] ?? '',
                'width' => intval($row['列宽度'] ?? 0),
                'hidden' => false,
                'editable' => false,
                'sortable' => true,
                'original' => $row
            ];
        }

        return $columns;
    }

    /**
     * 从 view_function 列提取匹配字段（可匹配 1/2/3/4 → key/label/amount/target）
     *
     * 同类多行时后者覆盖前者（与原循环一致，无 break）。
     *
     * @param array<int, array<string, mixed>> $rows view_function 列定义行集
     * @return array{key: string, label: string, amount: string, target: string}
     */
    public static function extractMatchColumns(array $rows): array
    {
        $result = ['key' => '', 'label' => '', 'amount' => '', 'target' => ''];

        foreach ($rows as $col) {
            $matchType = (string) ($col['可匹配'] ?? '');
            $fieldName = (string) ($col['字段名'] ?? '');
            if ($fieldName === '') {
                continue;
            }
            switch ($matchType) {
                case '1':
                    $result['key'] = $fieldName;
                    break;
                case '2':
                    $result['label'] = $fieldName;
                    break;
                case '3':
                    $result['amount'] = $fieldName;
                    break;
                case '4':
                    $result['target'] = $fieldName;
                    break;
            }
        }

        return $result;
    }
}
