<?php

namespace App\Services\Workbench;

use App\Models\Mcommon;

/**
 * 图表服务类
 * 负责处理工作台图表相关的业务逻辑
 */
class ChartService
{
    private Mcommon $model;

    public function __construct()
    {
        $this->model = new Mcommon();
    }

    /**
     * 获取图表数据
     *
     * @param array $context 上下文信息
     * @param string $chartModule 图形模块
     * @return array 图形数据
     */
    public function getChartData(array $context, string $chartModule): array
    {
        $chartData = [];

        $results = $this->getChartConfigs($chartModule);

        foreach ($results as $row) {
            $chartItem = $this->buildChartItem($row);

            try {
                $dataSql = $this->buildChartQuerySql($row, $context);
                $dataResults = $this->executeChartQuery($dataSql);

                $chartItem['数据'] = $dataResults ?? [];
                $chartItem['SQL'] = $dataSql;

                $this->updateChartNameFromResults($chartItem, $row, $dataResults);
            } catch (\Throwable $e) {
                $errorMsg = $e->getMessage();
                log_message('error', '图形数据查询失败: ' . $errorMsg . ' SQL: ' . ($dataSql ?? 'N/A'));
                $chartItem['数据'] = [];
                $chartItem['错误'] = $errorMsg;
                $chartItem['SQL'] = $dataSql ?? '';
            }

            $this->addChartColumnConfigs($chartItem, $row);
            $chartData[] = $chartItem;
        }

        return $chartData;
    }

    /**
     * 获取图表配置
     *
     * @param string $chartModule 图形模块
     * @return array
     */
    private function getChartConfigs(string $chartModule): array
    {
        $sql = sprintf('
            select 图形模块,图形编号,图形名称,图形类型,
                取数方式,查询表名,查询字段,属地字段,查询条件,汇总条件,排序条件,记录条数,
                字段模块,页面布局,钻取模块,条件叠加,顺序
            from def_chart_config
            where 有效标识="1" and 图形模块="%s" and 顺序>0
            order by 图形模块,图形编号,顺序',
            $chartModule
        );

        $result = $this->model->select($sql);
        return $result ? $result->getResult() : [];
    }

    /**
     * 构建图表基础数据项
     *
     * @param object $row 配置行数据
     * @return array
     */
    private function buildChartItem(object $row): array
    {
        return [
            '图形模块' => $row->图形模块,
            '图形编号' => $row->图形编号,
            '图形名称' => $row->图形名称,
            '图形类型' => $row->图形类型,
            '取数方式' => $row->取数方式,
            '页面布局' => $row->页面布局,
            '字段模块' => $row->字段模块,
            '钻取模块' => $row->钻取模块,
            '数据' => []
        ];
    }

    /**
     * 构建图表查询 SQL
     *
     * @param object $row 配置行数据
     * @param array $context 上下文信息
     * @return string
     */
    private function buildChartQuerySql(object $row, array $context): string
    {
        if ($row->取数方式 === '存储过程') {
            return $this->buildStoredProcedureSql($row, $context);
        }

        return $this->buildStandardQuerySql($row, $context);
    }

    /**
     * 构建存储过程 SQL
     *
     * @param object $row 配置行数据
     * @param array $context 上下文信息
     * @return string
     */
    private function buildStoredProcedureSql(object $row, array $context): string
    {
        $dataSql = $row->查询表名;
        $dataSql = str_replace('$查询表名', sprintf('%s', $context['queryTable'] ?? ''), $dataSql);
        
        $deptNameAuth = $context['user']['deptNameAuth'] ?? '';
        $deptNameAuthJson = '"[\\"' . $deptNameAuth . '\\"]"';
        $dataSql = str_replace('$[部门全称赋权]', $deptNameAuthJson, $dataSql);

        if (strpos($dataSql, 'call ') !== 0) {
            $dataSql = 'call ' . $dataSql;
        }

        return $dataSql;
    }

    /**
     * 构建标准查询 SQL
     *
     * @param object $row 配置行数据
     * @param array $context 上下文信息
     * @return string
     */
    private function buildStandardQuerySql(object $row, array $context): string
    {
        $fields = $row->查询字段 ?? '*';
        $table = $row->查询表名;

        $whereParts = [];

        $deptAuthCond = $context['deptAuthzCond'] ?? '';
        if (!empty($deptAuthCond)) {
            $whereParts[] = $deptAuthCond;
        }

        $locationAuthCond = $context['locationAuthzCond'] ?? '';
        if (!empty($row->属地字段) && !empty($locationAuthCond)) {
            $whereParts[] = $locationAuthCond;
        }

        if (!empty($row->查询条件)) {
            $queryCond = $row->查询条件;
            $queryCond = $this->replaceConditionVariables($queryCond, $context);
            $whereParts[] = $queryCond;
        }

        if (count($whereParts) > 0) {
            $where = implode(' and ', $whereParts);
        } else {
            $where = '1=1';
        }

        $dataSql = sprintf('select %s, "%s^%s" as SID from %s where %s',
            $fields,
            $row->图形模块,
            $row->图形编号,
            $table,
            $where
        );

        if (!empty($row->汇总条件)) {
            $dataSql .= ' group by ' . $row->汇总条件;
        }

        if (!empty($row->排序条件)) {
            $dataSql .= ' order by ' . $row->排序条件;
        }

        if (!empty($row->记录条数)) {
            $dataSql .= ' limit ' . $row->记录条数;
        }

        return $dataSql;
    }

    /**
     * 替换条件变量
     *
     * @param string $condition 条件字符串
     * @param array $context 上下文信息
     * @return string 替换后的条件
     */
    private function replaceConditionVariables(string $condition, array $context): string
    {
        // 替换属地授权条件
        if (strpos($condition, '$属地授权') !== false) {
            $locationCond = $context['locationAuthzCond'] ?? '1=1';
            $condition = str_replace('$属地授权', $locationCond, $condition);
        }

        // 替换部门授权条件
        if (strpos($condition, '$部门授权') !== false) {
            $deptCond = $context['deptAuthzCond'] ?? '1=1';
            $condition = str_replace('$部门授权', $deptCond, $condition);
        }

        // 替换查询表名
        if (strpos($condition, '$查询表名') !== false) {
            $queryTable = $context['queryTable'] ?? '';
            $condition = str_replace('$查询表名', $queryTable, $condition);
        }

        return $condition;
    }

    /**
     * 执行图表查询
     *
     * @param string $dataSql SQL 语句
     * @return array
     */
    private function executeChartQuery(string $dataSql): array
    {
        $queryResult = $this->model->select($dataSql);
        if ($queryResult === false) {
            throw new \RuntimeException('数据库查询返回 false');
        }
        return $queryResult->getResult() ?? [];
    }

    /**
     * 从结果中更新图表名称
     *
     * @param array $chartItem 图表数据项
     * @param object $row 配置行数据
     * @param array $dataResults 查询结果
     */
    private function updateChartNameFromResults(array &$chartItem, object $row, array $dataResults): void
    {
        if ($row->取数方式 === '存储过程' && !empty($dataResults) && isset($dataResults[0]->图形名称)) {
            $chartItem['图形名称'] = $dataResults[0]->图形名称;
        }
    }

    /**
     * 添加图表列配置
     *
     * @param array $chartItem 图表数据项
     * @param object $row 配置行数据
     */
    private function addChartColumnConfigs(array &$chartItem, object $row): void
    {
        if (empty($row->字段模块)) {
            return;
        }

        $colSql = sprintf('
            select 字段模块,列名,字段名,坐标轴,图形类型
            from def_chart_column
            where 字段模块="%s" and 顺序>0
            order by 字段模块,顺序', 
            $row->字段模块
        );

        $colResults = $this->model->select($colSql)->getResult();
        $chartItem['字段'] = [];
        $chartItem['字段数'] = 0;
        
        foreach ($colResults as $colRow) {
            if (!array_key_exists($colRow->字段名, $chartItem['字段'])) {
                $chartItem['字段'][$colRow->字段名] = [];
            }
            $chartItem['字段数']++;
            $chartItem['字段'][$colRow->字段名]['列名'] = $colRow->列名;
            $chartItem['字段'][$colRow->字段名]['字段名'] = $colRow->字段名;
            $chartItem['字段'][$colRow->字段名]['坐标轴'] = $colRow->坐标轴;
            $chartItem['字段'][$colRow->字段名]['图形类型'] = $colRow->图形类型;
        }
    }
}

