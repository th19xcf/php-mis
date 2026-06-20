<?php

namespace App\Traits;

/**
 * 图表列配置复用 Trait
 *
 * 提供 def_chart_column 表的批量查询与 chartItem['字段'] 填充能力，
 * 供 ChartService（初始图表）与 ChartDrillService（钻取图表）共享，
 * 避免两处 Service 各自维护几乎相同的实现。
 *
 * 使用前提：use 本 Trait 的类需声明 `private Mcommon $model` 属性。
 */
trait ChartColumnConfigTrait
{
    /**
     * 批量获取多个字段模块的列配置（避免 N+1 查询）
     *
     * 在 getChartData 中，若直接在 foreach 内逐个查询 def_chart_column，
     * 每个图表配置行都会触发一次独立 SQL。本方法用一次 IN 查询批量取回，
     * 在内存中按字段模块分组，将 N 次查询降为 1 次。
     *
     * @param array $fieldModules 字段模块列表（可含重复值，内部自动去重）
     * @return array 按字段模块分组的列配置 [字段模块 => [列配置行数组]]
     */
    private function getChartColumnConfigsBatch(array $fieldModules): array
    {
        $uniqueModules = array_values(array_unique(array_filter($fieldModules, fn($m) => !empty($m))));
        if (empty($uniqueModules)) {
            return [];
        }

        $quotedModules = implode(',', array_map(
            fn($m) => $this->model->quote((string) $m),
            $uniqueModules
        ));

        $sql = sprintf(
            'select 字段模块, 列名, 字段名, 坐标轴, 图形类型
             from def_chart_column
             where 字段模块 in (%s) and 顺序>0
             order by 字段模块, 顺序',
            $quotedModules
        );

        $result = $this->model->select($sql);
        $grouped = array_fill_keys($uniqueModules, []);
        if ($result !== false) {
            foreach ($result->getResult() as $row) {
                $module = (string) $row->字段模块;
                if (!isset($grouped[$module])) {
                    $grouped[$module] = [];
                }
                $grouped[$module][] = $row;
            }
        }
        return $grouped;
    }

    /**
     * 填充图表列配置到 chartItem（从预取的批量数据中取，不再查库）
     *
     * 从 def_chart_column 读取"字段模块"对应的列配置（坐标轴 / 图形类型），
     * 填充到 chartItem['字段']，使前端可以按字段渲染不同图形（折线/柱/饼等）。
     *
     * 例如：def_chart_column 中"完成率"字段 图形类型="折线图"，前端会用 line series 渲染；
     *      没有此配置时，前端 fallback 到默认柱图，导致钻取后图形类型与初始不一致。
     *
     * @param array $chartItem 图表数据项
     * @param object $row 配置行数据
     * @param array $columnConfigsMap 预取的列配置映射 [字段模块 => [列配置行数组]]
     */
    private function fillChartColumnConfigs(array &$chartItem, object $row, array $columnConfigsMap = []): void
    {
        if (empty($row->字段模块)) {
            return;
        }

        $colResults = $columnConfigsMap[(string) $row->字段模块] ?? [];
        $chartItem['字段'] = [];
        $chartItem['字段数'] = 0;

        foreach ($colResults as $colRow) {
            $fieldName = (string) ($colRow->字段名 ?? '');
            if ($fieldName === '' || !array_key_exists($fieldName, $chartItem['字段'])) {
                $chartItem['字段'][$fieldName] = [];
            }
            $chartItem['字段数']++;
            $chartItem['字段'][$fieldName]['列名']     = (string) ($colRow->列名 ?? '');
            $chartItem['字段'][$fieldName]['字段名']   = $fieldName;
            $chartItem['字段'][$fieldName]['坐标轴']   = (string) ($colRow->坐标轴 ?? '');
            $chartItem['字段'][$fieldName]['图形类型'] = (string) ($colRow->图形类型 ?? '');
        }
    }
}
