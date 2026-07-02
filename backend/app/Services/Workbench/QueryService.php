<?php

namespace App\Services\Workbench;

use App\Models\Mcommon;

/**
 * 查询服务类
 * 负责处理工作台查询相关的业务逻辑
 */
class QueryService
{
    private Mcommon $model;

    public function __construct()
    {
        $this->model = new Mcommon();
    }

    /**
     * 查询总记录数
     *
     * @param array $context 上下文信息
     * @param array $payload 请求参数
     * @return int 总记录数
     */
    public function queryTotalCount(array $context, array $payload): int
    {
        $queryConfig = $context['query'];
        $functionAuth = $context['function'];
        $columns = $context['columns'];

        if ($queryConfig['mode'] === '存储过程') {
            return 0;
        }

        $columnMap = $this->buildColumnMap($columns);

        $whereParts = $this->buildWhereConditions($queryConfig, $functionAuth, $payload, $columnMap);
        $this->addDrillCondition($whereParts, $payload);

        $baseFromSql = sprintf(' from %s', $queryConfig['queryTable']);
        $whereSql = $whereParts ? ' where ' . implode(' and ', $whereParts) : '';
        $groupSql = $queryConfig['queryGroup'] !== '' ? ' group by ' . $queryConfig['queryGroup'] : '';

        $countSql = sprintf('select count(1) as total from (select 1%s%s%s) as total_rows', $baseFromSql, $whereSql, $groupSql);
        $totalRow = $this->model->select($countSql)->getRowArray();

        return (int) ($totalRow['total'] ?? 0);
    }

    /**
     * 查询记录
     *
     * @param array $context 上下文信息
     * @param array $payload 请求参数
     * @return array 查询结果
     */
    public function queryRecords(array $context, array $payload): array
    {
        $queryConfig = $context['query'];
        $functionAuth = $context['function'];
        $userAuth = $context['user'];
        $columns = $context['columns'];

        $fetchAll = filter_var($payload['all'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $current = max(1, (int) ($payload['current'] ?? 1));
        $size = max(1, min(200, (int) ($payload['size'] ?? 20)));

        if ($fetchAll) {
            $current = 1;
        }

        if ($queryConfig['mode'] === '存储过程') {
            return [
                'records' => [],
                'current' => $current,
                'size' => $size,
                'total' => 0
            ];
        }

        $columnMap = $this->buildColumnMap($columns);

        $selectParts = $this->buildSelectParts($columns, $functionAuth, $userAuth);
        $whereParts = $this->buildWhereConditions($queryConfig, $functionAuth, $payload, $columnMap);
        $this->addDrillCondition($whereParts, $payload);

        $baseFromSql = sprintf(' from %s', $queryConfig['queryTable']);
        $whereSql = $whereParts ? ' where ' . implode(' and ', $whereParts) : '';
        $groupSql = $queryConfig['queryGroup'] !== '' ? ' group by ' . $queryConfig['queryGroup'] : '';
        $orderSql = $queryConfig['queryOrder'] !== '' ? ' order by ' . $queryConfig['queryOrder'] : '';
        $offset = ($current - 1) * $size;
        $total = 0;

        if ($fetchAll) {
            $querySql = sprintf(
                'select %s%s%s%s%s',
                implode(',', $selectParts),
                $baseFromSql,
                $whereSql,
                $groupSql,
                $orderSql
            );
        } else {
            $countSql = sprintf('select count(1) as total from (select 1%s%s%s) as total_rows', $baseFromSql, $whereSql, $groupSql);
            $totalRow = $this->model->select($countSql)->getRowArray();
            $total = (int) ($totalRow['total'] ?? 0);

            $querySql = sprintf(
                'select %s%s%s%s%s limit %d offset %d',
                implode(',', $selectParts),
                $baseFromSql,
                $whereSql,
                $groupSql,
                $orderSql,
                $size,
                $offset
            );
        }

        log_message('debug', 'Workbench query SQL: ' . $querySql);

        $rows = $this->model->select($querySql)->getResultArray();
        $rows = $this->processDataTypes($rows, $columns);

        if ($fetchAll) {
            $total = count($rows);
            $size = $total > 0 ? $total : $size;
        }

        return [
            'records' => $rows,
            'current' => $current,
            'size' => $size,
            'total' => $total
        ];
    }

    /**
     * 分页查询记录
     *
     * @param array $context 上下文信息
     * @param array $payload 请求参数
     * @param int $current 当前页码
     * @param int $size 每页大小
     * @param int $offset 偏移量
     * @return array 记录数组
     */
    public function queryRecordsPaged(array $context, array $payload, int $current, int $size, int $offset): array
    {
        $queryConfig = $context['query'];
        $functionAuth = $context['function'];
        $userAuth = $context['user'];
        $columns = $context['columns'];

        if ($queryConfig['mode'] === '存储过程') {
            return [];
        }

        $columnMap = $this->buildColumnMap($columns);

        $selectParts = $this->buildSelectParts($columns, $functionAuth, $userAuth);
        $whereParts = $this->buildWhereConditions($queryConfig, $functionAuth, $payload, $columnMap);
        $this->addDrillCondition($whereParts, $payload);

        $baseFromSql = sprintf(' from %s', $queryConfig['queryTable']);
        $whereSql = $whereParts ? ' where ' . implode(' and ', $whereParts) : '';
        $groupSql = $queryConfig['queryGroup'] !== '' ? ' group by ' . $queryConfig['queryGroup'] : '';
        $orderSql = $queryConfig['queryOrder'] !== '' ? ' order by ' . $queryConfig['queryOrder'] : '';

        $querySql = sprintf(
            'select %s%s%s%s%s limit %d offset %d',
            implode(',', $selectParts),
            $baseFromSql,
            $whereSql,
            $groupSql,
            $orderSql,
            $size,
            $offset
        );

        log_message('debug', 'Workbench paged query SQL: ' . $querySql);

        $rows = $this->model->select($querySql)->getResultArray();

        return $this->processDataTypes($rows, $columns);
    }

    /**
     * 构建 SELECT 部分
     *
     * @param array $columns 列配置
     * @param array $functionAuth 功能权限
     * @param array $userAuth 用户权限
     * @return array
     */
    private function buildSelectParts(array $columns, array $functionAuth, array $userAuth): array
    {
        $selectParts = [];
        $hintErrorParts = [];

        foreach ($columns as $column) {
            $alias = (string) ($column['列名'] ?? '');
            $queryName = (string) ($column['查询名'] ?? '');
            if ($alias === '' || $queryName === '') {
                continue;
            }

            if ((string) ($column['字符转换'] ?? '0') === '1') {
                $selectParts[] = sprintf("replace(replace(%s, '\"', '~~'), '\'', '~~') as `%s`", $queryName, $alias);
            } elseif ((string) ($column['加密显示'] ?? '0') === '1') {
                $selectParts[] = sprintf('"*" as `%s`', $alias);
            } elseif ((string) ($column['工号限权'] ?? '0') !== '0' && $functionAuth['workIdAuth'] !== '0' && (string) ($column['工号字段'] ?? '') !== '') {
                $selectParts[] = sprintf(
                    'if(%s=%s,%s,"-") as `%s`',
                    $column['工号字段'],
                    $this->model->quote($userAuth['userWorkId']),
                    $queryName,
                    $alias
                );
            } else {
                $selectParts[] = sprintf('%s as `%s`', $queryName, $alias);
            }

            $hintCondition = trim((string) ($column['提示条件'] ?? ''));
            $errorCondition = trim((string) ($column['异常条件'] ?? ''));
            if ($hintCondition !== '') {
                $hintErrorParts[] = sprintf('if(%s,"1","0") as `提示^%s`', $hintCondition, $alias);
            }
            if ($errorCondition !== '') {
                $hintErrorParts[] = sprintf('if(%s,"1","0") as `异常^%s`', $errorCondition, $alias);
            }
        }

        if (!empty($hintErrorParts)) {
            $selectParts = array_merge($selectParts, $hintErrorParts);
        }

        return $selectParts;
    }

    /**
     * 构建 WHERE 条件
     *
     * @param array $queryConfig 查询配置
     * @param array $functionAuth 功能权限
     * @param array $payload 请求参数
     * @param array $columnMap 列映射
     * @return array
     */
    private function buildWhereConditions(array $queryConfig, array $functionAuth, array $payload, array $columnMap): array
    {
        $whereParts = [];

        if ($queryConfig['queryWhere'] !== '') {
            $whereParts[] = $queryConfig['queryWhere'];
        }
        if ($functionAuth['deptAuthCond'] !== '') {
            $whereParts[] = $functionAuth['deptAuthCond'];
        }
        if ($functionAuth['locationAuthCond'] !== '') {
            $whereParts[] = $functionAuth['locationAuthCond'];
        }

        $filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];
        foreach ($filters as $filter) {
            if (!is_array($filter)) {
                continue;
            }

            // 跨字段快速检索（对应前端 ag-grid quickFilterText / 工具栏"金"等场景）
            // 与条件面板 fieldKey 筛选并存，二者都会拼到 WHERE 上（AND 关系）
            if (isset($filter['globalSearch'])) {
                $searchTerm = trim((string) $filter['globalSearch']);
                if ($searchTerm !== '') {
                    $orParts = $this->buildGlobalSearchOrParts($columnMap, $searchTerm);
                    if (!empty($orParts)) {
                        $whereParts[] = '(' . implode(' or ', $orParts) . ')';
                    }
                }
                continue;
            }

            // 单字段 OR 组合（对应 ag-grid 列筛选 operator=OR 场景）
            // 形如：{ fieldOrFilter: { fieldKey: '工号', conditions: [
            //     { operator: 'contains', value: '金凯龙' },
            //     { operator: 'contains', value: '总经理' }
            // ] } }
            if (isset($filter['fieldOrFilter']) && is_array($filter['fieldOrFilter'])) {
                $this->appendFieldOrFilter($whereParts, $columnMap, $filter['fieldOrFilter']);
                continue;
            }

            $fieldKey = trim((string) ($filter['fieldKey'] ?? ''));
            $operator = trim((string) ($filter['operator'] ?? 'contains'));
            $value = trim((string) ($filter['value'] ?? ''));
            if ($fieldKey === '' || $value === '' || !isset($columnMap[$fieldKey])) {
                continue;
            }

            $fieldName = trim((string) ($columnMap[$fieldKey]['字段名'] ?? ''));
            if ($fieldName === '') {
                continue;
            }

            $whereParts[] = $this->buildSingleCondition($fieldName, $operator, $value);
        }

        return $whereParts;
    }

    /**
     * 追加单字段 OR 组合的 WHERE 片段。
     *
     * - fieldKey 不在 columnMap 中：跳过整组（不抛错，避免破坏导出）
     * - 任一 condition 字段名为空：跳过该 condition
     * - 至少一条 condition 有效：才生成 (cond1 OR cond2 OR ...) 包裹的片段
     */
    private function appendFieldOrFilter(array &$whereParts, array $columnMap, array $fieldOrFilter): void
    {
        $fieldKey = trim((string) ($fieldOrFilter['fieldKey'] ?? ''));
        if ($fieldKey === '' || !isset($columnMap[$fieldKey])) {
            return;
        }
        $fieldName = trim((string) ($columnMap[$fieldKey]['字段名'] ?? ''));
        if ($fieldName === '') {
            return;
        }

        $conditions = is_array($fieldOrFilter['conditions'] ?? null) ? $fieldOrFilter['conditions'] : [];
        $orParts = [];
        foreach ($conditions as $cond) {
            if (!is_array($cond)) {
                continue;
            }
            $op = trim((string) ($cond['operator'] ?? 'contains'));
            $val = trim((string) ($cond['value'] ?? ''));
            if ($val === '') {
                continue;
            }
            $orParts[] = $this->buildSingleCondition($fieldName, $op, $val);
        }

        if (!empty($orParts)) {
            $whereParts[] = '(' . implode(' or ', $orParts) . ')';
        }
    }

    /**
     * 构建单条 WHERE 条件（fieldName OP value）
     *
     * - equals    -> fieldName = 'value'
     * - startsWith-> fieldName like 'value%'
     * - endsWith  -> fieldName like '%value'
     * - 其他      -> fieldName like '%value%'
     */
    private function buildSingleCondition(string $fieldName, string $operator, string $value): string
    {
        switch ($operator) {
            case 'equals':
                return sprintf('%s=%s', $fieldName, $this->model->quote($value));
            case 'startsWith':
                return sprintf('%s like %s', $fieldName, $this->model->quote($value . '%'));
            case 'endsWith':
                return sprintf('%s like %s', $fieldName, $this->model->quote('%' . $value));
            default:
                return sprintf('%s like %s', $fieldName, $this->model->quote('%' . $value . '%'));
        }
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
    private function buildGlobalSearchOrParts(array $columnMap, string $searchTerm): array
    {
        $escaped = $this->escapeLikeWildcards($searchTerm);
        $likeValue = $this->model->quote('%' . $escaped . '%') . " ESCAPE '\\\\'";

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

    /**
     * 转义 LIKE 元字符：% _ \
     *
     * 转义后用户输入的"50%"会被当作字面量"50%"，不会匹配"50xx"。
     * 同时使用 ESCAPE '\\' 让 MySQL 知道反斜杠是转义符。
     */
    private function escapeLikeWildcards(string $value): string
    {
        return addcslashes($value, '%_\\');
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
    private function buildColumnMap(array $columns): array
    {
        $columnMap = [];
        foreach ($columns as $column) {
            $columnName = (string) ($column['列名'] ?? '');
            $fieldName = (string) ($column['字段名'] ?? '');
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
     * 添加钻取条件
     *
     * @param array $whereParts WHERE 条件数组
     * @param array $payload 请求参数
     */
    private function addDrillCondition(array &$whereParts, array $payload): void
    {
        $drillCondition = trim((string) ($payload['drillCondition'] ?? ''));
        if ($drillCondition !== '') {
            $whereParts[] = $drillCondition;
        }
    }

    /**
     * 处理数据类型转换
     *
     * @param array $rows 数据行
     * @param array $columns 列配置
     * @return array
     */
    private function processDataTypes(array $rows, array $columns): array
    {
        foreach ($rows as &$row) {
            foreach ($columns as $column) {
                $title = (string) ($column['列名'] ?? '');
                if ($title !== '' && array_key_exists($title, $row) && (string) ($column['列类型'] ?? '') === '数值' && is_numeric($row[$title])) {
                    $row[$title] = strpos((string) $row[$title], '.') === false ? (int) $row[$title] : (float) $row[$title];
                }
            }
        }

        return $rows;
    }

    /**
     * 构造工作台调试用 SQL（不复用 queryRecords / queryRecordsPaged 的执行路径，
     * 仅生成 selectParts/whereParts/countSql/querySql 等"看一眼就知道会跑什么"的诊断数据）
     *
     * @param array $context  工作台上下文
     * @param array $payload  请求参数（filters / drillCondition / all / current / size）
     * @param array $columns  列配置
     * @param bool  $fetchAll 是否全量（影响是否带 limit / offset）
     * @param int   $current  页码
     * @param int   $size     每页条数
     * @return array{
     *     selectParts: array, whereParts: array, countSql: ?string, querySql: string,
     *     queryTable: string, queryWhere: string, queryGroup: string, queryOrder: string, mode: string
     * }
     */
    public function buildDebugQuery(
        array $context,
        array $payload,
        array $columns,
        bool $fetchAll = false,
        int $current = 1,
        int $size = 20
    ): array {
        $queryConfig = $context['query'] ?? [];
        $functionAuth = $context['function'] ?? [];
        $userAuth = $context['user'] ?? [];

        $columnMap = $this->buildColumnMap($columns);

        $selectParts = $this->buildSelectParts($columns, $functionAuth, $userAuth);
        $whereParts = $this->buildWhereConditions($queryConfig, $functionAuth, $payload, $columnMap);
        $this->addDrillCondition($whereParts, $payload);

        $queryTable = (string) ($queryConfig['queryTable'] ?? '');
        $queryWhere = (string) ($queryConfig['queryWhere'] ?? '');
        $queryGroup = (string) ($queryConfig['queryGroup'] ?? '');
        $queryOrder = (string) ($queryConfig['queryOrder'] ?? '');
        $mode = (string) ($queryConfig['mode'] ?? '');

        $baseFromSql = $queryTable !== '' ? sprintf(' from %s', $queryTable) : '';
        $whereSql = !empty($whereParts) ? ' where ' . implode(' and ', $whereParts) : '';
        $groupSql = $queryGroup !== '' ? ' group by ' . $queryGroup : '';
        $orderSql = $queryOrder !== '' ? ' order by ' . $queryOrder : '';
        $offset = ($current - 1) * $size;

        $countSql = null;
        $querySql = '';

        if ($fetchAll) {
            $querySql = sprintf(
                'select %s%s%s%s%s',
                implode(',', $selectParts),
                $baseFromSql,
                $whereSql,
                $groupSql,
                $orderSql
            );
        } else {
            $countSql = sprintf(
                'select count(1) as total from (select 1%s%s%s) as total_rows',
                $baseFromSql,
                $whereSql,
                $groupSql
            );
            $querySql = sprintf(
                'select %s%s%s%s%s limit %d offset %d',
                implode(',', $selectParts),
                $baseFromSql,
                $whereSql,
                $groupSql,
                $orderSql,
                $size,
                $offset
            );
        }

        return [
            'selectParts' => $selectParts,
            'whereParts'  => $whereParts,
            'countSql'    => $countSql,
            'querySql'    => $querySql,
            'queryTable'  => $queryTable,
            'queryWhere'  => $queryWhere,
            'queryGroup'  => $queryGroup,
            'queryOrder'  => $queryOrder,
            'mode'        => $mode,
        ];
    }
}
