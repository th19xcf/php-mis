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

        $columnMap = [];
        foreach ($columns as $column) {
            $columnMap[(string) ($column['列名'] ?? '')] = $column;
        }

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

        $columnMap = [];
        foreach ($columns as $column) {
            $columnMap[(string) ($column['列名'] ?? '')] = $column;
        }

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
                'select (@i:=@i+1) as 序号, %s%s, (select @i:=0) as xh%s%s%s',
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
                'select (@i:=@i+1) as 序号, %s%s, (select @i:=%d) as xh%s%s%s limit %d offset %d',
                implode(',', $selectParts),
                $baseFromSql,
                $offset,
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

        $columnMap = [];
        foreach ($columns as $column) {
            $columnMap[(string) ($column['列名'] ?? '')] = $column;
        }

        $selectParts = $this->buildSelectParts($columns, $functionAuth, $userAuth);
        $whereParts = $this->buildWhereConditions($queryConfig, $functionAuth, $payload, $columnMap);
        $this->addDrillCondition($whereParts, $payload);

        $baseFromSql = sprintf(' from %s', $queryConfig['queryTable']);
        $whereSql = $whereParts ? ' where ' . implode(' and ', $whereParts) : '';
        $groupSql = $queryConfig['queryGroup'] !== '' ? ' group by ' . $queryConfig['queryGroup'] : '';
        $orderSql = $queryConfig['queryOrder'] !== '' ? ' order by ' . $queryConfig['queryOrder'] : '';

        $querySql = sprintf(
            'select (@i:=@i+1) as 序号, %s%s, (select @i:=%d) as xh%s%s%s limit %d offset %d',
            implode(',', $selectParts),
            $baseFromSql,
            $offset,
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
                    $this->quote($userAuth['userWorkId']),
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

            switch ($operator) {
                case 'equals':
                    $whereParts[] = sprintf('%s=%s', $fieldName, $this->quote($value));
                    break;
                case 'startsWith':
                    $whereParts[] = sprintf('%s like %s', $fieldName, $this->quote($value . '%'));
                    break;
                default:
                    $whereParts[] = sprintf('%s like %s', $fieldName, $this->quote('%' . $value . '%'));
                    break;
            }
        }

        return $whereParts;
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
            if (isset($row['序号'])) {
                $row['序号'] = (int) $row['序号'];
            }
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
     * 引用值
     *
     * @param string $value 要引用的值
     * @return string 引用后的值
     */
    private function quote(string $value): string
    {
        return "'" . addslashes($value) . "'";
    }
}
