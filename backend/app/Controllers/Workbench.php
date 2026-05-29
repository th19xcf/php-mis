<?php

namespace App\Controllers;

use App\Constants\ApiCode;
use App\Libraries\AuthorizationService;
use App\Libraries\SessionUserContext;
use App\Models\Mcommon;

class Workbench extends BaseController
{
    private Mcommon $common;
    private SessionUserContext $userContext;
    private AuthorizationService $authorizationService;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->common = new Mcommon();
        $this->userContext = new SessionUserContext();
        $this->authorizationService = new AuthorizationService();
    }

    public function page(string $functionCode = '')
    {
        try {
            [$context, $definition] = $this->buildWorkbenchContext($functionCode);

            // 只返回元数据，不查询数据，由前端单独请求数据
            return $this->success([
                'meta' => $definition
            ]);
        } catch (\RuntimeException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->error('5001', '工作台初始化失败');
        }
    }

    public function query(string $functionCode = '')
    {
        try {
            $payload = $this->request->getJSON(true) ?? [];

            [$context] = $this->buildWorkbenchContext($functionCode);
            $records = $this->queryRecords($context, $payload);

            return $this->success($records);
        } catch (\RuntimeException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->error('5002', '工作台查询失败');
        }
    }

    /**
     * 分页查询工作台数据
     *
     * @param string $functionCode 功能编码
     * @return Response
     */
    public function queryPaged(string $functionCode = '')
    {
        try {
            $payload = $this->request->getJSON(true) ?? [];

            // 分页参数
            $current = max(1, (int) ($payload['current'] ?? 1));
            $size = max(1, min(5000, (int) ($payload['size'] ?? 1000))); // 最大 5000 条
            $offset = max(0, (int) ($payload['offset'] ?? (($current - 1) * $size)));
            $fetchTotal = filter_var($payload['fetchTotal'] ?? true, FILTER_VALIDATE_BOOLEAN);

            // 获取上下文
            [$context] = $this->buildWorkbenchContext($functionCode);

            // 查询总数量（可选，首次加载时需要）
            $total = 0;
            if ($fetchTotal) {
                $total = $this->queryTotalCount($context, $payload);
            }

            // 分页查询数据
            $records = $this->queryRecordsPaged($context, $payload, $current, $size, $offset);

            return $this->success([
                'records' => $records,
                'current' => $current,
                'size' => $size,
                'offset' => $offset,
                'total' => $total,
                'hasMore' => count($records) === $size // 是否还有更多数据
            ]);
        } catch (\RuntimeException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '分页查询失败: ' . $e->getMessage());
            return $this->error('5003', '分页查询失败: ' . $e->getMessage());
        }
    }

    /**
     * 查询总记录数
     *
     * @param array $context 上下文信息
     * @param array $payload 请求参数
     * @return int 总记录数
     */
    private function queryTotalCount(array $context, array $payload): int
    {
        $queryConfig = $context['query'];
        $functionAuth = $context['function'];
        $columns = $context['columns'];

        // 存储过程模式不支持分页查询
        if ($queryConfig['mode'] === '存储过程') {
            return 0;
        }

        // 构建列映射
        $columnMap = [];
        foreach ($columns as $column) {
            $columnMap[(string) ($column['列名'] ?? '')] = $column;
        }

        // 构建 WHERE 条件
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

        // 处理筛选条件
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

        // 处理钻取条件
        $drillCondition = trim((string) ($payload['drillCondition'] ?? ''));
        if ($drillCondition !== '') {
            $whereParts[] = $drillCondition;
        }

        $baseFromSql = sprintf(' from %s', $queryConfig['queryTable']);
        $whereSql = $whereParts ? ' where ' . implode(' and ', $whereParts) : '';
        $groupSql = $queryConfig['queryGroup'] !== '' ? ' group by ' . $queryConfig['queryGroup'] : '';

        // 查询总数量
        $countSql = sprintf('select count(1) as total from (select 1%s%s%s) as total_rows', $baseFromSql, $whereSql, $groupSql);
        $totalRow = $this->common->select($countSql)->getRowArray();

        return (int) ($totalRow['total'] ?? 0);
    }

    /**
     * 分页查询记录
     *
     * @param array $context 上下文信息
     * @param array $payload 请求参数
     * @param int $current 当前页码
     * @param int $size 每页大小
     * @return array 记录数组
     */
    private function queryRecordsPaged(array $context, array $payload, int $current, int $size, int $offset): array
    {
        $queryConfig = $context['query'];
        $functionAuth = $context['function'];
        $userAuth = $context['user'];
        $columns = $context['columns'];

        // 存储过程模式不支持分页查询
        if ($queryConfig['mode'] === '存储过程') {
            return [];
        }

        // 构建列映射
        $columnMap = [];
        foreach ($columns as $column) {
            $columnMap[(string) ($column['列名'] ?? '')] = $column;
        }

        // 构建 SELECT 字段
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

            // 添加提示和异常标记字段
            $hintCondition = trim((string) ($column['提示条件'] ?? ''));
            $errorCondition = trim((string) ($column['异常条件'] ?? ''));
            if ($hintCondition !== '') {
                $hintErrorParts[] = sprintf('if(%s,"1","0") as `提示^%s`', $hintCondition, $alias);
            }
            if ($errorCondition !== '') {
                $hintErrorParts[] = sprintf('if(%s,"1","0") as `异常^%s`', $errorCondition, $alias);
            }
        }

        // 合并提示和异常字段到 selectParts
        if (!empty($hintErrorParts)) {
            $selectParts = array_merge($selectParts, $hintErrorParts);
        }

        // 构建 WHERE 条件
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

        // 处理筛选条件
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

        // 处理钻取条件
        $drillCondition = trim((string) ($payload['drillCondition'] ?? ''));
        if ($drillCondition !== '') {
            $whereParts[] = $drillCondition;
        }

        $baseFromSql = sprintf(' from %s', $queryConfig['queryTable']);
        $whereSql = $whereParts ? ' where ' . implode(' and ', $whereParts) : '';
        $groupSql = $queryConfig['queryGroup'] !== '' ? ' group by ' . $queryConfig['queryGroup'] : '';
        $orderSql = $queryConfig['queryOrder'] !== '' ? ' order by ' . $queryConfig['queryOrder'] : '';
        // 构建分页查询 SQL
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

        // Debug: log the SQL query
        log_message('debug', 'Workbench paged query SQL: ' . $querySql);

        $rows = $this->common->select($querySql)->getResultArray();

        // 处理数据类型转换
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

    public function debug(string $functionCode = '')
    {
        try {
            $payload = $this->request->getJSON(true) ?? [];

            [$context, $definition] = $this->buildWorkbenchContext($functionCode);

            $queryConfig = $context['query'];
            $functionAuth = $context['function'];
            $userAuth = $context['user'];
            $columns = $context['columns'];
            
            // 调试日志
            log_message('debug', '[调试] functionCode: ' . $functionCode);
            log_message('debug', '[调试] definition chartModule: ' . ($definition['chartModule'] ?? '未定义'));
            log_message('debug', '[调试] context chartModule: ' . ($context['chartModule'] ?? '未定义'));

            $fetchAll = filter_var($payload['all'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $current = max(1, (int) ($payload['current'] ?? 1));
            $size = max(1, min(200, (int) ($payload['size'] ?? 20)));

            if ($fetchAll) {
                $current = 1;
            }

            $columnMap = [];
            foreach ($columns as $column) {
                $columnMap[(string) ($column['列名'] ?? '')] = $column;
            }

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

            $drillCondition = trim((string) ($payload['drillCondition'] ?? ''));
            if ($drillCondition !== '') {
                $whereParts[] = $drillCondition;
            }

            $baseFromSql = sprintf(' from %s', $queryConfig['queryTable']);
            $whereSql = $whereParts ? ' where ' . implode(' and ', $whereParts) : '';
            $groupSql = $queryConfig['queryGroup'] !== '' ? ' group by ' . $queryConfig['queryGroup'] : '';
            $orderSql = $queryConfig['queryOrder'] !== '' ? ' order by ' . $queryConfig['queryOrder'] : '';
            $offset = ($current - 1) * $size;

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

            // 获取图形 SQL 信息
            $chartSql = [];
            $chartModule = $definition['chartModule'] ?? '';
            $chartQuerySql = ''; // 存储查询 SQL
            
            // 调试日志
            log_message('debug', '[调试] chartModule: ' . $chartModule);
            log_message('debug', '[调试] context keys: ' . implode(', ', array_keys($context)));
            
            if (!empty($chartModule)) {
                try {
                    $chartQuerySql = sprintf(
                        'select 图形模块,图形编号,图形名称,图形类型,
                            取数方式,查询表名,查询字段,属地字段,查询条件,汇总条件,排序条件,记录条数
                        from def_chart_config
                        where 有效标识="1" and 图形模块="%s" and 顺序>0
                        order by 图形模块,图形编号,顺序',
                        $chartModule
                    );
                    log_message('debug', '[调试] 查询SQL: ' . $chartQuerySql);
                    
                    $queryResult = $this->common->select($chartQuerySql);
                    $chartConfigs = $queryResult->getResult();
                    
                    log_message('debug', '[调试] 查询结果数量: ' . count($chartConfigs));
                    
                    // 调试：输出第一条记录的字段
                    if (count($chartConfigs) > 0) {
                        $firstConfig = $chartConfigs[0];
                        log_message('debug', '[调试] 第一条记录 - 图形编号: ' . ($firstConfig->图形编号 ?? 'null'));
                        log_message('debug', '[调试] 第一条记录 - 取数方式: ' . ($firstConfig->取数方式 ?? 'null'));
                        log_message('debug', '[调试] 第一条记录 - 查询表名: ' . ($firstConfig->查询表名 ?? 'null'));
                    } else {
                        log_message('debug', '[调试] chartConfigs 为空数组，尝试使用 getRowArray()');
                        $rowArray = $queryResult->getRowArray();
                        log_message('debug', '[调试] getRowArray 结果: ' . json_encode($rowArray));
                    }
                    
                    foreach ($chartConfigs as $chartConfig) {
                        $chartItem = [
                            'name' => $chartConfig->图形名称,
                            'sql' => '',
                            'error' => ''
                        ];
                        
                        try {
                            $fetchMethod = $chartConfig->取数方式 ?? '';
                            $queryTable = $chartConfig->查询表名 ?? '';
                            $queryFields = $chartConfig->查询字段 ?? '';
                            $queryCond = $chartConfig->查询条件 ?? '';
                            $queryGroup = $chartConfig->汇总条件 ?? '';
                            $queryOrder = $chartConfig->排序条件 ?? '';
                            $queryLimit = $chartConfig->记录条数 ?? '';
                            
                            // 构建图形数据 SQL
                            if ($fetchMethod === '存储过程') {
                                $dataSql = $queryTable;
                            } elseif (!empty($queryTable)) {
                                $fields = !empty($queryFields) ? $queryFields : '*';
                                $dataSql = sprintf('select %s from %s', $fields, $queryTable);
                                if (!empty($queryCond)) {
                                    $dataSql .= sprintf(' where %s', $queryCond);
                                }
                                if (!empty($queryGroup)) {
                                    $dataSql .= sprintf(' group by %s', $queryGroup);
                                }
                                if (!empty($queryOrder)) {
                                    $dataSql .= sprintf(' order by %s', $queryOrder);
                                }
                                if (!empty($queryLimit) && is_numeric($queryLimit)) {
                                    $dataSql .= sprintf(' limit %d', (int) $queryLimit);
                                }
                            } else {
                                $dataSql = '';
                            }
                            
                            $chartItem['sql'] = $dataSql;
                        } catch (\Throwable $e) {
                            $chartItem['error'] = $e->getMessage();
                        }
                        
                        $chartSql[] = $chartItem;
                    }
                } catch (\Throwable $e) {
                    log_message('error', '获取图形SQL失败: ' . $e->getMessage());
                    log_message('error', '异常堆栈: ' . $e->getTraceAsString());
                    log_message('error', '查询SQL: ' . $chartQuerySql);
                }
            }

            return $this->success([
                'functionCode' => $functionCode,
                'queryTable' => $queryConfig['queryTable'],
                'queryWhere' => $queryConfig['queryWhere'],
                'queryGroup' => $queryConfig['queryGroup'],
                'queryOrder' => $queryConfig['queryOrder'],
                'mode' => $queryConfig['mode'],
                'selectParts' => $selectParts,
                'whereParts' => $whereParts,
                'countSql' => $countSql ?? null,
                'querySql' => $querySql,
                'chartModule' => $chartModule,
                'chartQuerySql' => $chartQuerySql,
                'chartSql' => $chartSql,
                'userAuth' => [
                    'companyId' => $userAuth['companyId'],
                    'userWorkId' => $userAuth['userWorkId'],
                    'roleCodes' => $userAuth['roleCodes'],
                    'locationAuth' => $userAuth['locationAuth'],
                    'deptCodeAuth' => $userAuth['deptCodeAuth'],
                    'deptNameAuth' => $userAuth['deptNameAuth'],
                    'debugAuth' => $userAuth['debugAuth']
                ],
                'functionAuth' => [
                    'module' => $functionAuth['module'],
                    'params' => $functionAuth['params'],
                    'deptAuthCond' => $functionAuth['deptAuthCond'],
                    'locationAuthCond' => $functionAuth['locationAuthCond']
                ],
                'columns' => array_map(function ($col) {
                    return [
                        '列名' => $col['列名'] ?? '',
                        '查询名' => $col['查询名'] ?? '',
                        '字段名' => $col['字段名'] ?? ''
                    ];
                }, $columns)
            ]);
        } catch (\RuntimeException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '获取调试信息失败: ' . $e->getMessage());
            log_message('error', '错误堆栈: ' . $e->getTraceAsString());
            return $this->error('5003', '获取调试信息失败: ' . $e->getMessage());
        }
    }

    private function buildWorkbenchContext(string $functionCode): array
    {
        $functionCode = trim($functionCode);
        if ($functionCode === '') {
            throw new \RuntimeException('功能编码不能为空');
        }

        $user = $this->userContext->requireLogin();
        $companyId = $user['companyId'];
        $userWorkId = $user['workId'];
        $userPassword = $user['password'];

        $userAuth = $this->loadUserAuthorization($companyId, $userWorkId, $userPassword);
        $functionAuth = $this->loadFunctionAuthorization($functionCode, $userAuth);
        $queryConfig = $this->loadQueryConfig($functionCode, $userAuth['roleCodesRaw']);
        $columns = $this->loadColumns($functionCode);

        if (!$queryConfig) {
            throw new \RuntimeException('功能未配置查询模块');
        }

        if (!$columns) {
            throw new \RuntimeException('功能未配置列信息');
        }

        $columnDefinitions = $this->buildColumnDefinitions($columns);

        $definition = [
            'functionCode' => $functionCode,
            'title' => $functionAuth['menu2'],
            'menu1' => $functionAuth['menu1'],
            'menu2' => $functionAuth['menu2'],
            'module' => $functionAuth['module'],
            'params' => $functionAuth['params'],
            'mode' => $queryConfig['mode'],
            'queryModule' => $queryConfig['queryModule'],
            'fieldModule' => $queryConfig['fieldModule'],
            'commentModule' => $queryConfig['commentModule'],
            'chartModule' => $queryConfig['chartModule'],
            'toolbar' => [
                'comment' => $functionAuth['commentAuth'],
                'add' => $functionAuth['addAuth'],
                'edit' => $functionAuth['modifyAuth'],
                'batchEdit' => $functionAuth['modifyAuth'],
                'delete' => $functionAuth['deleteAuth'],
                'import' => $functionAuth['importAuth'] && $queryConfig['importModule'] !== '',
                'export' => $functionAuth['exportAuth'],
                'tableEdit' => $functionAuth['tableAuth'],
                'debugSql' => $userAuth['debugAuth'],
                'upkeep' => $functionAuth['upkeepAuth'] && $queryConfig['upkeepModule'] !== ''
            ],
            'conditions' => $this->buildConditionDefinitions($columns),
            'columns' => $columnDefinitions,
            'supportsStoredProcedure' => $queryConfig['mode'] === '存储过程',
            'fallbackHint' => $queryConfig['mode'] === '存储过程'
                ? '当前功能为存储过程模式，Vue 工作台暂未接管执行链路，请先走旧页回退。'
                : ''
        ];

        $context = [
            'user' => $userAuth,
            'function' => $functionAuth,
            'query' => $queryConfig,
            'columns' => $columns,
            'locationAuthzCond' => $functionAuth['locationAuthCond'],
            'deptAuthzCond' => $functionAuth['deptAuthCond'],
            'queryTable' => $queryConfig['queryTable']
        ];

        return [$context, $definition];
    }

    private function loadUserAuthorization(string $companyId, string $userWorkId, string $userPassword): array
    {
        $sql = sprintf(
            'select 
                员工编号,姓名,工号,t1.角色组,
                case
                    when t1.角色组!="" and t1.角色编码="" and t2.角色组 is not null then t2.角色编码
                    when t1.角色组!="" and t1.角色编码!="" and t2.角色组 is not null then concat(t2.角色编码,",",t1.角色编码)
                    else t1.角色编码
                end as 角色编码,
                属地赋权,部门编码赋权,部门全称赋权,
                工号限权,调试赋权,维护赋权,
                员工属地,员工部门编码,员工部门全称
            from
            (
                select 
                    员工编号,姓名,工号,
                    角色组,replace(replace(角色编码,"，",",")," ","") as 角色编码,
                    replace(replace(属地赋权,"，",",")," ","") as 属地赋权,
                    replace(replace(部门编码赋权,"，",",")," ","") as 部门编码赋权,
                    replace(replace(部门全称赋权,"，",",")," ","") as 部门全称赋权,
                    工号限权,调试赋权,维护赋权,
                    员工属地,员工部门编码,员工部门全称
                from def_user
                where 有效标识="1" and 员工属地=%s and 工号=%s
                group by 员工属地,工号
            ) as t1
            left join
            (
                select 角色组,replace(replace(角色编码,"，",",")," ","") as 角色编码
                from def_role_group
                where 有效标识="1"
            ) as t2 on t1.角色组=t2.角色组',
            $this->quote($companyId),
            $this->quote($userWorkId)
        );

        $row = $this->common->select($sql)->getRowArray();
        if (!$row) {
            throw new \RuntimeException('用户权限信息不存在');
        }

        $roleCodes = $this->splitCsv((string) ($row['角色编码'] ?? ''));

        return [
            'companyId' => $companyId,
            'userWorkId' => $userWorkId,
            'userPassword' => $userPassword,
            'roleCodes' => $roleCodes,
            'roleCodesQuoted' => $this->quoteList($roleCodes),
            'roleCodesRaw' => (string) ($row['角色编码'] ?? ''),
            'locationAuth' => $this->authorizationService->normalize((string) ($row['属地赋权'] ?? '')),
            'employeeRegion' => (string) ($row['员工属地'] ?? $companyId),
            'deptCodeAuth' => $this->splitCsv((string) ($row['部门编码赋权'] ?? '')),
            'deptNameAuth' => $this->authorizationService->normalize((string) ($row['部门全称赋权'] ?? '')),
            'employeeDeptName' => (string) ($row['员工部门全称'] ?? ''),
            'workIdAuth' => (string) ($row['工号限权'] ?? '0'),
            'debugAuth' => ($userPassword === $userWorkId . $userWorkId) || (string) ($row['调试赋权'] ?? '0') === '1',
            'upkeepAuth' => ($userPassword === $userWorkId . $userWorkId) || (string) ($row['维护赋权'] ?? '0') === '1'
        ];
    }

    private function loadFunctionAuthorization(string $functionCode, array $userAuth): array
    {
        if ($userAuth['roleCodesQuoted'] === '') {
            throw new \RuntimeException('用户未配置角色');
        }

        $sql = sprintf(
            'select 
                t1.功能赋权,
                t1.备注授权,t1.新增授权,t1.修改授权,t1.删除授权,
                t1.维护授权,t1.整表授权,
                t1.导入授权,t1.导出授权,t1.工号限权,
                ifnull(t2.一级菜单,"") as 一级菜单,
                ifnull(t2.二级菜单,"") as 二级菜单,
                ifnull(t2.功能模块,"") as 功能模块,
                ifnull(t2.参数,"") as 参数,
                ifnull(t3.部门编码字段,"") as 部门编码字段,
                ifnull(t3.部门全称字段,"") as 部门全称字段,
                ifnull(t3.属地字段,"") as 属地字段
            from 
            (
                select 功能编码赋权 as 功能赋权,
                    max(备注授权) as 备注授权,
                    max(新增授权) as 新增授权,
                    max(修改授权) as 修改授权,
                    max(删除授权) as 删除授权,
                    max(维护授权) as 维护授权,
                    max(整表授权) as 整表授权,
                    max(导入授权) as 导入授权,
                    max(导出授权) as 导出授权,
                    min(工号限权) as 工号限权
                from view_role
                where 有效标识="1" and 角色编码 in (%s) and 功能编码赋权=%s
                group by 功能编码赋权
            ) as t1
            left join def_function as t2 on t1.功能赋权=t2.功能编码
            left join def_query_config as t3 on if(t2.功能类型="查询", t2.模块名称, "")=t3.查询模块',
            $userAuth['roleCodesQuoted'],
            $this->quote($functionCode)
        );

        $row = $this->common->select($sql)->getRowArray();
        if (!$row) {
            throw new \RuntimeException('当前账号无该功能访问权限');
        }

        $deptCodeAuth = $this->buildFunctionDeptCodeAuth($functionCode, $row, $userAuth);
        $deptNameAuth = $this->buildFunctionDeptNameAuth($functionCode, $row, $userAuth);
        $locationAuth = $this->buildFunctionLocationAuth($functionCode, $row, $userAuth, $deptCodeAuth, $deptNameAuth);

        $deptAuthCond = '';
        if ($deptCodeAuth !== '' && $deptNameAuth !== '') {
            $deptAuthCond = sprintf('(%s or %s)', $deptCodeAuth, $deptNameAuth);
        } elseif ($deptCodeAuth !== '') {
            $deptAuthCond = sprintf('(%s)', $deptCodeAuth);
        } elseif ($deptNameAuth !== '') {
            $deptAuthCond = sprintf('(%s)', $deptNameAuth);
        }

        if ($locationAuth !== '') {
            $locationAuth = sprintf('(%s)', $locationAuth);
        }

        return [
            'menu1' => (string) ($row['一级菜单'] ?? ''),
            'menu2' => (string) ($row['二级菜单'] ?? ''),
            'module' => (string) ($row['功能模块'] ?? ''),
            'params' => (string) ($row['参数'] ?? ''),
            'commentAuth' => (string) ($row['备注授权'] ?? '0') === '1',
            'addAuth' => (string) ($row['新增授权'] ?? '0') === '1',
            'modifyAuth' => in_array((string) ($row['修改授权'] ?? '0'), ['1', '2'], true),
            'deleteAuth' => (string) ($row['删除授权'] ?? '0') === '1',
            'upkeepAuth' => $userAuth['upkeepAuth'] || (string) ($row['维护授权'] ?? '0') === '1',
            'tableAuth' => (string) ($row['整表授权'] ?? '0') === '1',
            'importAuth' => (string) ($row['导入授权'] ?? '0') === '1',
            'exportAuth' => (string) ($row['导出授权'] ?? '0') === '1',
            'workIdAuth' => $userAuth['workIdAuth'] !== '0' ? $userAuth['workIdAuth'] : (string) ($row['工号限权'] ?? '0'),
            'deptAuthCond' => $deptAuthCond,
            'locationAuthCond' => $locationAuth
        ];
    }

    private function loadQueryConfig(string $functionCode, string $userRole): array
    {
        $sql = sprintf(
            'select 
                查询模块,模块类型,字段模块,钻取模块,
                查询表名,数据表名,数据模式,
                查询条件,汇总条件,排序条件,初始条数,
                数据整理模块,备注模块,导入模块,图形模块,表样式
            from def_query_config
            where 查询模块 in 
                (
                    select 模块名称 
                    from def_function
                    where 有效标识="1" and 功能编码=%s
                )',
            $this->quote($functionCode)
        );

        $row = $this->common->select($sql)->getRowArray();
        if (!$row) {
            return [];
        }

        $queryWhere = (string) ($row['查询条件'] ?? '');
        if ($queryWhere !== '' && strpos($queryWhere, '$角色') !== false) {
            $queryWhere = str_replace('$角色', $userRole, $queryWhere);
        }

        return [
            'queryModule' => (string) ($row['查询模块'] ?? ''),
            'drillModule' => (string) ($row['钻取模块'] ?? ''),
            'mode' => (string) ($row['模块类型'] ?? '数据查询'),
            'fieldModule' => (string) ($row['字段模块'] ?? ''),
            'queryTable' => (string) ($row['查询表名'] ?? ''),
            'dataTable' => (string) ($row['数据表名'] ?? ''),
            'dataModel' => (string) ($row['数据模式'] ?? ''),
            'queryWhere' => $queryWhere,
            'queryGroup' => (string) ($row['汇总条件'] ?? ''),
            'queryOrder' => (string) ($row['排序条件'] ?? ''),
            'resultCount' => (int) ($row['初始条数'] ?? 0),
            'commentModule' => (string) ($row['备注模块'] ?? ''),
            'importModule' => (string) ($row['导入模块'] ?? ''),
            'upkeepModule' => (string) ($row['数据整理模块'] ?? ''),
            'chartModule' => (string) ($row['图形模块'] ?? ''),
            'gridStyle' => (string) (($row['表样式'] ?? '') === '' ? '表样式_A' : $row['表样式'])
        ];
    }

    private function loadColumns(string $functionCode): array
    {
        $sql = sprintf(
            'select 功能编码,字段模块,部门编码字段,部门全称字段,
                工号字段,属地字段,
                列名,列类型,列宽度,字段名,查询名,
                赋值类型,对象,对象名称,对象表名,缺省值,主键,
                工号限权,可筛选,可汇总,可新增,可修改,不可为空,可颜色标注,
                提示条件,提示样式设置,异常条件,异常样式设置,字符转换,
                加密显示,列顺序
            from view_function
            where 功能编码=%s and 列顺序>0
            group by 列名
            order by 列顺序',
            $this->quote($functionCode)
        );

        return $this->common->select($sql)->getResultArray();
    }

    private function buildConditionDefinitions(array $columns): array
    {
        $conditions = [];

        foreach ($columns as $column) {
            $conditions[] = [
                'label' => (string) ($column['列名'] ?? ''),
                'fieldKey' => (string) ($column['列名'] ?? ''),
                'fieldName' => (string) ($column['字段名'] ?? ''),
                'queryName' => (string) ($column['查询名'] ?? ''),
                'type' => (string) ($column['列类型'] ?? '字符'),
                'required' => (string) ($column['不可为空'] ?? '0') === '1',
                'filterable' => true
            ];
        }

        return $conditions;
    }

    private function buildColumnDefinitions(array $columns): array
    {
        $items = [[
            'field' => '序号',
            'title' => '序号',
            'type' => '数值',
            'width' => 90,
            'hidden' => false,
            'editable' => false,
            'required' => false,
            'sortable' => true
        ]];

        foreach ($columns as $column) {
            $title = (string) ($column['列名'] ?? '');
            $items[] = [
                'field' => $title,
                'title' => $title,
                'type' => (string) ($column['列类型'] ?? '字符'),
                'width' => (int) (($column['列宽度'] ?? 0) > 0 ? $column['列宽度'] : max(strlen($title) * 16, 120)),
                'hidden' => (string) ($column['主键'] ?? '0') === '1',
                'editable' => in_array((string) ($column['可修改'] ?? '0'), ['1', '2'], true),
                'required' => (string) ($column['不可为空'] ?? '0') === '1',
                'sortable' => true,
                // 提示和异常显示相关配置
                'hintCondition' => (string) ($column['提示条件'] ?? ''),
                'hintStyle' => (string) ($column['提示样式设置'] ?? ''),
                'errorCondition' => (string) ($column['异常条件'] ?? ''),
                'errorStyle' => (string) ($column['异常样式设置'] ?? ''),
                // 颜色标注相关配置
                'colorMarkEnabled' => (string) ($column['可颜色标注'] ?? '0') === '1'
            ];
        }

        return $items;
    }

    private function queryRecords(array $context, array $payload): array
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

        $selectParts = [];
        $hintErrorParts = []; // 提示和异常标记字段
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

            // 添加提示和异常标记字段
            $hintCondition = trim((string) ($column['提示条件'] ?? ''));
            $errorCondition = trim((string) ($column['异常条件'] ?? ''));
            if ($hintCondition !== '') {
                $hintErrorParts[] = sprintf('if(%s,"1","0") as `提示^%s`', $hintCondition, $alias);
            }
            if ($errorCondition !== '') {
                $hintErrorParts[] = sprintf('if(%s,"1","0") as `异常^%s`', $errorCondition, $alias);
            }
        }

        // 合并提示和异常字段到 selectParts
        if (!empty($hintErrorParts)) {
            $selectParts = array_merge($selectParts, $hintErrorParts);
        }

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

        // 处理钻取条件 SQL
        $drillCondition = trim((string) ($payload['drillCondition'] ?? ''));
        if ($drillCondition !== '') {
            $whereParts[] = $drillCondition;
        }

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
            $totalRow = $this->common->select($countSql)->getRowArray();
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

        // Debug: log the SQL query
        log_message('debug', 'Workbench query SQL: ' . $querySql);

        $rows = $this->common->select($querySql)->getResultArray();
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

    private function buildFunctionDeptCodeAuth(string $functionCode, array $functionRow, array $userAuth): string
    {
        $field = (string) ($functionRow['部门编码字段'] ?? '');
        if ($field === '' || $userAuth['roleCodesQuoted'] === '') {
            return '';
        }

        $sql = sprintf(
            'select substring_index(substring_index(编码赋权,",",t2.GUID+1),",",-1) as 部门编码赋权
            from
            (
                select GUID,replace(replace(部门编码赋权,"，",",")," ","") as 编码赋权
                from view_role
                where 有效标识="1" and 角色编码 in (%s) and 功能编码赋权=%s
            ) as t1
            inner join def_GUID as t2 on t2.GUID<(length(编码赋权)-length(replace(编码赋权,",",""))+1)
            group by 部门编码赋权
            order by 部门编码赋权',
            $userAuth['roleCodesQuoted'],
            $this->quote($functionCode)
        );

        $results = $this->common->select($sql)->getResultArray();
        $clauses = [];
        foreach ($results as $row) {
            $value = trim((string) ($row['部门编码赋权'] ?? ''));
            if ($value !== '') {
                $clauses[] = sprintf('left(%s,length("%s"))="%s"', $field, $value, $value);
            }
        }

        if ($clauses) {
            $expr = implode(' or ', $clauses);
            return $userAuth['upkeepAuth'] ? sprintf('%s or %s=""', $expr, $field) : $expr;
        }

        if ($userAuth['deptCodeAuth']) {
            return sprintf('instr(%s,%s)', $field, $this->quoteList($userAuth['deptCodeAuth']));
        }

        return '';
    }

    private function buildFunctionDeptNameAuth(string $functionCode, array $functionRow, array $userAuth): string
    {
        $field = (string) ($functionRow['部门全称字段'] ?? '');
        if ($field === '' || $userAuth['roleCodesQuoted'] === '') {
            return '';
        }

        $roleDeptNameAuth = $this->loadRoleDeptNameAuth($functionCode, $userAuth['roleCodesQuoted']);

        $resolvedAuth = $this->authorizationService->resolveDeptName(
            $userAuth['deptNameAuth'],
            $roleDeptNameAuth,
            $userAuth['employeeDeptName']
        );

        return $this->authorizationService->buildDeptNameCondition($field, $resolvedAuth, $userAuth['upkeepAuth']);
    }

    private function loadRoleDeptNameAuth(string $functionCode, string $roleCodesQuoted): string
    {
        $sql = sprintf(
            'select substring_index(substring_index(全称赋权,",",t2.GUID+1),",",-1) as 部门全称赋权
            from
            (
                select GUID,replace(replace(部门全称赋权,"，",",")," ","") as 全称赋权
                from view_role
                where 有效标识="1" and 角色编码 in (%s) and 功能编码赋权=%s
            ) as t1
            inner join def_GUID as t2 on t2.GUID<(length(全称赋权)-length(replace(全称赋权,",",""))+1)
            group by 部门全称赋权
            order by 部门全称赋权',
            $roleCodesQuoted,
            $this->quote($functionCode)
        );

        $results = $this->common->select($sql)->getResultArray();
        $values = [];
        foreach ($results as $row) {
            $value = trim((string) ($row['部门全称赋权'] ?? ''));
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return implode(',', array_values(array_unique($values)));
    }

    private function buildFunctionLocationAuth(string $functionCode, array $functionRow, array $userAuth, string $deptCodeAuth, string $deptNameAuth): string
    {
        $field = (string) ($functionRow['属地字段'] ?? '');
        if ($field === '' || $userAuth['roleCodesQuoted'] === '') {
            return '';
        }

        if ($deptCodeAuth !== '' || $deptNameAuth !== '') {
            return '';
        }

        $roleLocationAuth = $this->loadRoleLocationAuth($functionCode, $userAuth['roleCodesQuoted']);

        $resolvedAuth = $this->authorizationService->resolve(
            $userAuth['locationAuth'],
            $roleLocationAuth,
            $userAuth['employeeRegion']
        );

        $this->storeLocationAuthToSession($functionCode, $resolvedAuth);

        return $this->authorizationService->buildCondition($field, $resolvedAuth, $userAuth['upkeepAuth']);
    }

    private function loadRoleLocationAuth(string $functionCode, string $roleCodesQuoted): string
    {
        $sql = sprintf(
            'select substring_index(substring_index(角色表属地,",",t2.GUID+1),",",-1) as 属地赋权
            from
            (
                select GUID,replace(replace(属地赋权,"，",",")," ","") as 角色表属地
                from view_role
                where 有效标识="1" and 角色编码 in (%s) and 功能编码赋权=%s
            ) as t1
            inner join def_GUID as t2 on t2.GUID<(length(角色表属地)-length(replace(角色表属地,",",""))+1)
            group by 属地赋权
            order by 属地赋权',
            $roleCodesQuoted,
            $this->quote($functionCode)
        );

        $results = $this->common->select($sql)->getResultArray();
        $values = [];
        foreach ($results as $row) {
            $value = trim((string) ($row['属地赋权'] ?? ''));
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return implode(',', array_values(array_unique($values)));
    }

    private function storeLocationAuthToSession(string $functionCode, string $resolvedAuth): void
    {
        $session = \Config\Services::session();
        $session->set([
            $functionCode . '-location_authz_resolved' => $resolvedAuth
        ]);
    }

    private function splitCsv(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', str_replace('，', ',', $value)));
        $parts = array_filter($parts, static fn(string $item): bool => $item !== '');

        return array_values(array_unique($parts));
    }

    private function quote(string $value): string
    {
        return sprintf("'%s'", str_replace(["\\", "'"], ["\\\\", "\\'"], $value));
    }

    private function quoteList(array $items): string
    {
        $quoted = [];
        foreach ($items as $item) {
            $value = trim((string) $item);
            if ($value !== '') {
                $quoted[] = $this->quote($value);
            }
        }

        return implode(',', array_values(array_unique($quoted)));
    }

    private function success(array $data)
    {
        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg' => 'success',
            'data' => $data
        ]);
    }

    private function error(string $code, string $message)
    {
        return $this->response->setJSON([
            'code' => $code,
            'msg' => $message
        ]);
    }

    public function drill(string $functionCode = '')
    {
        try {
            $payload = $this->request->getJSON(true) ?? [];

            [$context] = $this->buildWorkbenchContext($functionCode);
            
            $drillModule = $context['query']['drillModule'] ?? '';
            $queryModule = $context['query']['queryModule'] ?? '';
            
            // Debug info
            $debugInfo = [
                'functionCode' => $functionCode,
                'queryModule' => $queryModule,
                'drillModule' => $drillModule,
                'queryConfig' => $context['query'],
                'userAuthCount' => count($context['user'] ?? [])
            ];
            
            // 如果钻取模块为空，使用查询模块
            if (empty($drillModule)) {
                $drillModule = $queryModule;
                $debugInfo['drillModuleFallback'] = 'used queryModule as drillModule';
            }
            
            $drillOptions = $this->getDrillOptions($context, $payload, $drillModule);

            return $this->success([
                'options' => $drillOptions,
                'debug' => $debugInfo
            ]);
        } catch (\RuntimeException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->error('5003', '工作台钻取失败: ' . $e->getMessage());
        }
    }

    private function getDrillOptions(array $context, array $payload, string $drillModule): array
    {
        $functionAuth = $context['function'];
        $userAuth = $context['user'];

        if (empty($drillModule)) {
            return [];
        }

        $sql = sprintf(
            'select
                钻取模块,页面选项,t1.功能编码,钻取字段,钻取条件,
                if(t2.二级菜单 is null,"",if(t1.标签副名称="",t2.二级菜单,concat(t2.二级菜单,"-",t1.标签副名称))) as 标签名称,
                t2.功能模块,
                ifnull(t2.一级菜单,"") as menu1,
                ifnull(t2.二级菜单,"") as menu2
            from def_drill_config as t1
            left join def_function as t2 on t1.功能编码=t2.功能编码
            where 钻取模块=%s
            order by 顺序,convert(页面选项 using gbk)',
            $this->quote($drillModule)
        );

        $results = $this->common->select($sql)->getResultArray();
        $options = [];

        foreach ($results as $row) {
            $functionCode = (string) ($row['功能编码'] ?? '');
            if (empty($functionCode)) {
                continue;
            }

            $options[] = [
                'label' => (string) ($row['页面选项'] ?? $row['标签名称'] ?? ''),
                'value' => $functionCode,
                'functionCode' => $functionCode,
                'module' => (string) ($row['功能模块'] ?? ''),
                'drillFields' => (string) ($row['钻取字段'] ?? ''),
                'drillCondition' => (string) ($row['钻取条件'] ?? ''),
                'menu1' => (string) ($row['menu1'] ?? ''),
                'menu2' => (string) ($row['menu2'] ?? '')
            ];
        }

        return $options;
    }

    public function importColumns(string $functionCode = '')
    {
        try {
            $functionCode = trim($functionCode);
            if ($functionCode === '') {
                throw new \RuntimeException('功能编码不能为空');
            }

            // 获取导入模块
            $sql = sprintf(
                'select 导入模块 from def_query_config 
                where 查询模块 in (
                    select 模块名称 from def_function 
                    where 有效标识="1" and 功能编码=%s
                )',
                $this->quote($functionCode)
            );

            $query = $this->common->select($sql);
            if ($query === false) {
                log_message('error', '查询 def_query_config 失败');
                return $this->success(['columns' => []]);
            }

            $row = $query->getRowArray();
            $importModule = (string) ($row['导入模块'] ?? '');

            if ($importModule === '') {
                return $this->success(['columns' => []]);
            }

            // 获取导入列配置
            $sql = sprintf(
                'select 列名, 字段名, 查询名, 顺序, 字段类型, 校验类型, 导入类型
                from def_import_column 
                where 导入模块=%s
                order by 顺序',
                $this->quote($importModule)
            );

            $query = $this->common->select($sql);
            if ($query === false) {
                log_message('error', '查询 def_import_column 失败');
                return $this->success(['columns' => []]);
            }

            $results = $query->getResultArray();
            $columns = [];
            foreach ($results as $row) {
                $columns[] = [
                    'columnName' => (string) ($row['列名'] ?? ''),
                    'fieldName' => (string) ($row['字段名'] ?? ''),
                    'queryName' => (string) ($row['查询名'] ?? ''),
                    'columnOrder' => (int) ($row['顺序'] ?? 0),
                    'columnType' => (string) ($row['字段类型'] ?? ''),
                    'checkType' => (string) ($row['校验类型'] ?? ''),
                    'importType' => (string) ($row['导入类型'] ?? '')
                ];
            }

            return $this->success(['columns' => $columns]);
        } catch (\RuntimeException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '获取导入列配置失败: ' . $e->getMessage());
            return $this->error(ApiCode::SERVER_ERROR, '获取导入列配置失败');
        }
    }

    public function import(string $functionCode = '')
    {
        try {
            $functionCode = trim($functionCode);
            if ($functionCode === '') {
                throw new \RuntimeException('功能编码不能为空');
            }

            // 获取请求数据
            $payload = $this->request->getJSON(true) ?? [];
            $importData = $payload['data'] ?? [];
            $importConfig = $payload['config'] ?? [];

            if (empty($importData)) {
                throw new \RuntimeException('导入数据不能为空');
            }

            // 获取 session 信息
            $session = \Config\Services::session();
            $userWorkid = $session->get('user_workid') ?? 'system';
            $userLocation = $session->get('user_location') ?? ''; // 获取用户属地
            $menu1 = $session->get($functionCode.'-menu_1') ?? '';
            $menu2 = $session->get($functionCode.'-menu_2') ?? '';

            // 系统变量映射
            $systemVars = [
                '$时间戳' => date('Y-m-d H:i:s'),
                '$工号' => $userWorkid,
                '$属地' => $userLocation
            ];

            // 获取查询配置
            $queryConfig = $this->loadQueryConfig($functionCode, '');
            if (!$queryConfig || $queryConfig['dataTable'] === '') {
                throw new \RuntimeException('未找到数据表配置');
            }

            $dataTable = $queryConfig['dataTable'];
            $importModule = $queryConfig['importModule'];

            // 生成临时表名（与旧版保持一致）
            $tmpTableName = sprintf('tmp_%s_%s_%s_%s', $functionCode, $menu1, $menu2, $userWorkid);

            // 获取导入列配置
            $importColumns = [];
            if ($importModule !== '') {
                $sql = sprintf(
                    'select 列名, 字段名, 查询名, 顺序, 字段类型, 字段长度, 校验信息, 校验类型, 对象, 导入类型, 系统变量, 匹配标识
                    from def_import_column
                    where 导入模块=%s
                    order by 顺序',
                    $this->quote($importModule)
                );
                $query = $this->common->select($sql);
                if ($query !== false) {
                    $importColumns = $query->getResultArray();
                }
            }

            // 如果没有导入列配置，尝试从数据表结构获取
            if (empty($importColumns)) {
                $sql = sprintf('SHOW COLUMNS FROM %s', $dataTable);
                $query = $this->common->select($sql);
                if ($query !== false) {
                    $fields = $query->getResultArray();
                    foreach ($fields as $field) {
                        $importColumns[] = [
                            '列名' => $field['Field'],
                            '字段名' => $field['Field'],
                            '导入类型' => ($field['Null'] === 'NO' && $field['Default'] === null) ? '1' : '0'
                        ];
                    }
                }
            }

            // 构建字段映射
            $fieldMap = [];
            $requiredColumns = []; // 存储匹配标识=1的必填列
            foreach ($importColumns as $col) {
                $columnName = $col['列名'] ?? '';
                $fieldMap[$columnName] = [
                    'field' => $col['字段名'],
                    'fieldType' => $col['字段类型'] ?? '字符',
                    'fieldLength' => $col['字段长度'] ?? 255,
                    'required' => ($col['导入类型'] ?? '0') === '1',
                    'checkType' => $col['校验类型'] ?? '',
                    'checkInfo' => $col['校验信息'] ?? '',
                    'object' => $col['对象'] ?? '',
                    'systemVar' => $col['系统变量'] ?? '',
                    'matchFlag' => $col['匹配标识'] ?? '0'
                ];

                // 收集匹配标识=1的必填列
                if (($col['匹配标识'] ?? '0') === '1') {
                    $requiredColumns[] = $columnName;
                }
            }

            // 检查导入数据是否包含所有匹配标识=1的字段
            if (!empty($importData)) {
                $firstRow = $importData[0];
                $missingColumns = [];
                foreach ($requiredColumns as $reqCol) {
                    if (!array_key_exists($reqCol, $firstRow)) {
                        $missingColumns[] = $reqCol;
                    }
                }

                if (!empty($missingColumns)) {
                    return $this->success([
                        'success' => false,
                        'message' => sprintf('导入失败,缺少必须的字段"%s"', implode('","', $missingColumns)),
                        'total' => count($importData),
                        'successCount' => 0,
                        'errorCount' => count($importData),
                        'errors' => [['error' => sprintf('缺少必须的字段: %s', implode(', ', $missingColumns))]]
                    ]);
                }
            }

            // 验证数据
            $errors = [];
            $validData = [];
            foreach ($importData as $rowIndex => $row) {
                $rowErrors = [];
                $validRow = [];

                foreach ($fieldMap as $columnName => $config) {
                    $value = $row[$columnName] ?? '';
                    $fieldName = $config['field'];
                    $systemVar = $config['systemVar'] ?? '';

                    // 如果值为空且配置了系统变量，使用系统变量值
                    if (($value === '' || $value === null) && $systemVar !== '') {
                        if (isset($systemVars[$systemVar])) {
                            $value = $systemVars[$systemVar];
                        }
                    }

                    // 必填验证
                    if ($config['required'] && ($value === '' || $value === null)) {
                        $rowErrors[] = sprintf('字段 "%s" 不能为空', $columnName);
                    }

                    $validRow[$fieldName] = $value;
                }

                if (!empty($rowErrors)) {
                    $errors[] = [
                        'row' => $rowIndex + 1,
                        'errors' => $rowErrors,
                        'data' => $row
                    ];
                } else {
                    $validData[] = $validRow;
                }
            }

            // 如果有验证错误，返回错误信息
            if (!empty($errors)) {
                return $this->success([
                    'success' => false,
                    'message' => sprintf('验证失败，共 %d 行数据有误', count($errors)),
                    'total' => count($importData),
                    'successCount' => 0,
                    'errorCount' => count($errors),
                    'errors' => $errors
                ]);
            }

            // 创建临时表
            $this->createTempTable($tmpTableName, $importColumns);

            // 将数据插入临时表
            $insertResult = $this->insertToTempTable($tmpTableName, $validData);
            if ($insertResult === false) {
                $this->dropTempTable($tmpTableName);
                return $this->success([
                    'success' => false,
                    'message' => '导入失败：插入临时表失败',
                    'total' => count($importData),
                    'successCount' => 0,
                    'errorCount' => count($importData),
                    'errors' => [['error' => '插入临时表失败']]
                ]);
            }

            // 数据校验（固定值、条件、日期格式）
            $checkResult = $this->validateImportData($tmpTableName, $importColumns, $userLocation);
            if ($checkResult['hasError']) {
                $this->dropTempTable($tmpTableName);
                return $this->success([
                    'success' => false,
                    'message' => $checkResult['message'],
                    'total' => count($importData),
                    'successCount' => 0,
                    'errorCount' => count($importData),
                    'errors' => $checkResult['errors']
                ]);
            }

            // 滤重检查（如果配置了滤重字段）
            if ($importModule !== '') {
                $duplicateCheckResult = $this->checkDuplicateFields($importModule, $dataTable, $tmpTableName);
                if ($duplicateCheckResult['hasError']) {
                    $this->dropTempTable($tmpTableName);
                    return $this->success([
                        'success' => false,
                        'message' => $duplicateCheckResult['message'],
                        'total' => count($importData),
                        'successCount' => 0,
                        'errorCount' => count($importData),
                        'errors' => $duplicateCheckResult['errors']
                    ]);
                }
            }

            // 使用 INSERT INTO ... SELECT 从临时表导入正式表，应用查询名转换
            $insertResult = $this->importFromTempTable($dataTable, $tmpTableName, $importColumns);

            if ($insertResult['success']) {
                // 执行后处理模块（如果配置了）
                if ($importModule !== '') {
                    $this->executeAfterProcess($importModule);
                }

                // 删除临时表
                $this->dropTempTable($tmpTableName);
                return $this->success([
                    'success' => true,
                    'message' => sprintf('成功导入 %d 条数据', $insertResult['count']),
                    'total' => count($importData),
                    'successCount' => $insertResult['count'],
                    'errorCount' => 0,
                    'errors' => []
                ]);
            } else {
                // 保留临时表用于调试
                return $this->success([
                    'success' => false,
                    'message' => $insertResult['message'],
                    'total' => count($importData),
                    'successCount' => 0,
                    'errorCount' => $insertResult['count'],
                    'errors' => $insertResult['errors']
                ]);
            }
        } catch (\RuntimeException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '导入数据失败: ' . $e->getMessage());
            return $this->error(ApiCode::SERVER_ERROR, '导入数据失败');
        }
    }

    /**
     * 创建临时表
     */
    private function createTempTable(string $tableName, array $columns): bool
    {
        // 删除已存在的临时表
        $this->dropTempTable($tableName);

        // 如果没有列定义，使用默认字段
        if (empty($columns)) {
            $sql = sprintf('CREATE TABLE %s (id int auto_increment primary key, data varchar(255))', $tableName);
            $result = $this->common->exec($sql);
            return $result !== false;
        }

        // 构建字段定义
        $fieldDefs = [];
        foreach ($columns as $col) {
            $fieldName = $col['字段名'] ?? $col['列名'];
            $fieldLength = $col['字段长度'] ?? 255;
            $fieldDefs[] = sprintf('%s varchar(%s) not null default ""', $fieldName, $fieldLength);
        }

        $sql = sprintf('CREATE TABLE %s (%s)', $tableName, implode(',', $fieldDefs));
        $result = $this->common->exec($sql);

        return $result !== false;
    }

    /**
     * 删除临时表
     */
    private function dropTempTable(string $tableName): bool
    {
        $sql = sprintf('DROP TABLE IF EXISTS %s', $tableName);
        $result = $this->common->exec($sql);
        return $result !== false;
    }

    /**
     * 插入数据到临时表
     */
    private function insertToTempTable(string $tableName, array $data): bool
    {
        if (empty($data)) {
            return true;
        }

        // 使用批量插入
        $fields = array_keys($data[0]);
        $values = [];

        foreach ($data as $row) {
            $rowValues = [];
            foreach ($fields as $field) {
                $rowValues[] = $this->quote($row[$field] ?? '');
            }
            $values[] = '(' . implode(',', $rowValues) . ')';
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $tableName,
            implode(', ', $fields),
            implode(', ', $values)
        );

        $result = $this->common->exec($sql);
        return $result !== false;
    }

    /**
     * 使用事务方式插入数据
     */
    private function insertDataWithTransaction(string $tableName, array $data): array
    {
        $successCount = 0;
        $errors = [];

        try {
            $db = db_connect('btdc');
            $db->transStart();

            foreach ($data as $rowIndex => $row) {
                try {
                    $db->table($tableName)->insert($row);
                    $num = $db->affectedRows();
                    if ($num > 0) {
                        $successCount++;
                    } else {
                        $errors[] = [
                            'row' => $rowIndex + 1,
                            'error' => '插入失败，影响行数为0',
                            'data' => $row
                        ];
                    }
                } catch (\Throwable $e) {
                    $errors[] = [
                        'row' => $rowIndex + 1,
                        'error' => $e->getMessage(),
                        'data' => $row
                    ];
                }
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                return [
                    'success' => false,
                    'count' => count($errors),
                    'message' => sprintf('导入失败，%d 行数据插入出错，已回滚', count($errors)),
                    'errors' => $errors
                ];
            }

            if (empty($errors)) {
                return [
                    'success' => true,
                    'count' => $successCount,
                    'message' => sprintf('成功导入 %d 条数据', $successCount),
                    'errors' => []
                ];
            } else {
                return [
                    'success' => false,
                    'count' => count($errors),
                    'message' => sprintf('导入失败，%d 行数据插入出错', count($errors)),
                    'errors' => $errors
                ];
            }
        } catch (\Throwable $e) {
            log_message('error', '事务插入失败: ' . $e->getMessage());
            return [
                'success' => false,
                'count' => count($data),
                'message' => '导入失败：' . $e->getMessage(),
                'errors' => [['error' => $e->getMessage()]]
            ];
        }
    }

    /**
     * 从临时表导入数据到正式表，应用查询名中的转换
     */
    private function importFromTempTable(string $targetTable, string $tempTable, array $importColumns): array
    {
        try {
            $db = db_connect('btdc');
            $db->transStart();

            // 构建 INSERT INTO ... SELECT 语句
            $fieldNames = [];
            $selectParts = [];

            foreach ($importColumns as $col) {
                $fieldName = $col['字段名'] ?? $col['列名'] ?? '';
                $queryName = $col['查询名'] ?? '';

                if ($fieldName === '') {
                    continue;
                }

                $fieldNames[] = sprintf('`%s`', $fieldName);

                // 如果有查询名且与字段名不同，使用查询名作为转换
                if ($queryName !== '' && $queryName !== $fieldName) {
                    $selectParts[] = sprintf('%s as `%s`', $queryName, $fieldName);
                } else {
                    $selectParts[] = sprintf('`%s`', $fieldName);
                }
            }

            if (empty($fieldNames)) {
                return [
                    'success' => false,
                    'count' => 0,
                    'message' => '没有可导入的字段',
                    'errors' => []
                ];
            }

            // 执行 INSERT INTO ... SELECT
            $sql = sprintf(
                'INSERT INTO %s (%s) SELECT %s FROM %s',
                $targetTable,
                implode(', ', $fieldNames),
                implode(', ', $selectParts),
                $tempTable
            );

            $result = $db->query($sql);
            $affectedRows = $db->affectedRows();

            $db->transComplete();

            if ($result === false) {
                return [
                    'success' => false,
                    'count' => 0,
                    'message' => '导入失败：执行导入SQL失败',
                    'errors' => []
                ];
            }

            return [
                'success' => true,
                'count' => $affectedRows,
                'message' => sprintf('成功导入 %d 条数据', $affectedRows),
                'errors' => []
            ];
        } catch (\Throwable $e) {
            log_message('error', '从临时表导入失败: ' . $e->getMessage());
            return [
                'success' => false,
                'count' => 0,
                'message' => '导入失败：' . $e->getMessage(),
                'errors' => [['error' => $e->getMessage()]]
            ];
        }
    }

    /**
     * 校验导入数据（固定值、条件、日期格式）
     */
    private function validateImportData(string $tmpTableName, array $importColumns, string $userLocation): array
    {
        $errors = [];
        $userLocationAuthz = $userLocation ?: '';

        foreach ($importColumns as $col) {
            $columnName = $col['列名'] ?? '';
            $fieldName = $col['字段名'] ?? '';
            $checkType = $col['校验类型'] ?? '';
            $checkInfo = $col['校验信息'] ?? '';
            $object = $col['对象'] ?? '';

            if ($checkType === '' || $fieldName === '') {
                continue;
            }

            // 固定值校验
            if (strpos($checkType, '固定值') !== false && $object !== '') {
                $sql = sprintf('
                    select
                        t1.字段名 as 字段名,
                        t1.字段值 as 字段值,
                        ifnull(t2.对象值,"") as 对象值
                    from
                    (
                        select "%s" as 字段名, %s as 字段值
                        from %s
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

                $result = $this->common->select($sql);
                if ($result !== false) {
                    $errs = $result->getResultArray();
                    if (count($errs) != 0) {
                        $errArr = [];
                        foreach ($errs as $err) {
                            $errArr[] = $err['字段值'];
                        }
                        return [
                            'hasError' => true,
                            'message' => sprintf('导入失败,列"%s"有不符合固定值的记录 {"%s"}', $columnName, implode(',', $errArr)),
                            'errors' => $errs
                        ];
                    }
                }
            }

            // 条件校验
            if (strpos($checkType, '条件') !== false && $checkInfo !== '') {
                $sql = sprintf('
                    select "%s" as 字段名, %s as 字段值 from %s where %s
                ',
                    $columnName, $fieldName, $tmpTableName, $checkInfo);

                $result = $this->common->select($sql);
                if ($result !== false) {
                    $errs = $result->getResultArray();
                    if (count($errs) != 0) {
                        $errArr = [];
                        foreach ($errs as $err) {
                            $errArr[] = $err['字段值'];
                        }
                        return [
                            'hasError' => true,
                            'message' => sprintf('导入失败,列"%s"有不符合条件的记录 {"%s"}', $columnName, implode(',', $errArr)),
                            'errors' => $errs
                        ];
                    }
                }
            }

            // 日期格式校验
            if (strpos($checkType, '日期') !== false) {
                $sql = sprintf('
                    select "%s" as 字段名, %s as 字段值 from %s
                ',
                    $columnName, $fieldName, $tmpTableName);

                $result = $this->common->select($sql);
                if ($result !== false) {
                    $dates = $result->getResult();
                    foreach ($dates as $date) {
                        // 只判断非空值
                        if ($date->字段值 == '') continue;
                        // 匹配日期格式,YYYY-mm-dd
                        $parts = [];
                        if (preg_match("/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/", $date->字段值, $parts)) {
                            // 检测是否为日期
                            if (checkdate($parts[2], $parts[3], $parts[1]) == false) {
                                return [
                                    'hasError' => true,
                                    'message' => sprintf('导入失败,列"%s"有不符合的记录{"%s"},必须为YYYY-mm-dd (如2023-01-02) 格式', $columnName, $date->字段值),
                                    'errors' => [['字段值' => $date->字段值]]
                                ];
                            }
                        } else {
                            return [
                                'hasError' => true,
                                'message' => sprintf('导入失败,列"%s"有不符合的记录{"%s"},必须为YYYY-mm-dd (如2023-01-02) 格式', $columnName, $date->字段值),
                                'errors' => [['字段值' => $date->字段值]]
                            ];
                        }
                    }
                }
            }
        }

        return [
            'hasError' => false,
            'message' => '校验通过',
            'errors' => []
        ];
    }

    /**
     * 检查滤重字段是否有重复记录
     */
    private function checkDuplicateFields(string $importModule, string $dataTable, string $tmpTableName): array
    {
        try {
            // 查询 def_import_config 获取滤重字段
            $sql = sprintf(
                'select 滤重字段 from def_import_config where 导入模块=%s',
                $this->quote($importModule)
            );

            $result = $this->common->select($sql);
            if ($result === false) {
                return [
                    'hasError' => false,
                    'message' => '',
                    'errors' => []
                ];
            }

            $row = $result->getRowArray();
            if (!$row || empty($row['滤重字段'])) {
                return [
                    'hasError' => false,
                    'message' => '',
                    'errors' => []
                ];
            }

            $duplicateFields = $row['滤重字段'];

            // 检查临时表和正式表之间是否有重复记录
            $sql = sprintf(
                'select %s from %s where concat(%s) in (select concat(%s) from %s)',
                $duplicateFields,
                $dataTable,
                $duplicateFields,
                $duplicateFields,
                $tmpTableName
            );

            $result = $this->common->select($sql);
            if ($result === false) {
                return [
                    'hasError' => false,
                    'message' => '',
                    'errors' => []
                ];
            }

            $errs = $result->getResultArray();
            if (count($errs) > 0) {
                $errArr = [];
                foreach ($errs as $err) {
                    $str = '';
                    foreach ($err as $item) {
                        if ($str !== '') $str = $str . '^';
                        $str = $str . $item;
                    }
                    $errArr[] = $str;
                }

                return [
                    'hasError' => true,
                    'message' => sprintf('导入失败,滤重列"%s"有重复记录 {"%s"}', $duplicateFields, implode(',', $errArr)),
                    'errors' => $errs
                ];
            }

            return [
                'hasError' => false,
                'message' => '',
                'errors' => []
            ];
        } catch (\Throwable $e) {
            log_message('error', '滤重检查失败: ' . $e->getMessage());
            return [
                'hasError' => false,
                'message' => '',
                'errors' => []
            ];
        }
    }

    /**
     * 执行后处理模块
     */
    private function executeAfterProcess(string $importModule): void
    {
        try {
            // 查询 def_import_config 获取后处理模块
            $sql = sprintf(
                'select 后处理模块 from def_import_config where 导入模块=%s',
                $this->quote($importModule)
            );

            $result = $this->common->select($sql);
            if ($result === false) {
                return;
            }

            $row = $result->getRowArray();
            if (!$row || empty($row['后处理模块'])) {
                return;
            }

            $afterProcess = $row['后处理模块'];

            // 执行后处理存储过程
            $spSql = sprintf('call %s', $afterProcess);
            $this->common->select($spSql);
        } catch (\Throwable $e) {
            log_message('error', '执行后处理模块失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取新增字段配置
     */
    public function addFields(string $functionCode = '')
    {
        try {
            // 从 session 获取字段模块
            $session = \Config\Services::session();
            $fieldModule = $session->get($functionCode . '-field_module');

            if (empty($fieldModule)) {
                // 查询字段模块 - 使用与 buildWorkbenchContext 相同的方式
                $sql = sprintf(
                    'select 字段模块 from def_query_config where 查询模块 in (
                        select 模块名称 from def_function where 有效标识="1" and 功能编码=%s
                    )',
                    $this->quote($functionCode)
                );
                $result = $this->common->select($sql);
                if ($result !== false) {
                    $row = $result->getRowArray();
                    $fieldModule = $row['字段模块'] ?? '';
                }
            }

            if (empty($fieldModule)) {
                return $this->success(['fields' => []]);
            }

            // 查询可新增的字段 - 使用 view_function 视图，与旧版 Frame.php 保持一致
            // view_function 视图使用 "列顺序" 字段（不是"顺序"）
            $sql = sprintf(
                'select
                    列名, 字段名, 列类型, 赋值类型, 对象, 缺省值, 不可为空, 可新增, 列顺序
                from view_function
                where 功能编码=%s and 列顺序>0 and 可新增="1"
                group by 列名
                order by 列顺序',
                $this->quote($functionCode)
            );

            $result = $this->common->select($sql);
            if ($result === false) {
                return $this->success(['fields' => []]);
            }

            $columns = $result->getResultArray();
            $fields = [];

            foreach ($columns as $col) {
                $field = [
                    'columnName' => $col['列名'],
                    'fieldName' => $col['字段名'],
                    'fieldType' => $col['列类型'] ?? '字符',
                    'required' => ($col['不可为空'] ?? '0') === '1',
                    'defaultValue' => $col['缺省值'] ?? '',
                    'objectName' => '',
                    'editable' => true
                ];

                // 处理系统变量默认值
                if ($field['defaultValue'] === '$当日日期') {
                    $field['defaultValue'] = date('Y-m-d');
                } elseif ($field['defaultValue'] === '$时间戳') {
                    $field['defaultValue'] = date('Y-m-d H:i:s');
                } elseif ($field['defaultValue'] === '$工号') {
                    $field['defaultValue'] = $session->get('user_workid') ?? '';
                } elseif ($field['defaultValue'] === '$属地') {
                    $field['defaultValue'] = $session->get('user_location') ?? '';
                }

                // 处理赋值类型
                $赋值类型 = $col['赋值类型'] ?? '';
                $对象 = $col['对象'] ?? '';
                
                // 如果赋值类型包含"固定值"，则查询对象选项
                if (strpos($赋值类型, '固定值') !== false && !empty($对象)) {
                    $field['objectName'] = $对象;
                    $field['objectOptions'] = $this->getObjectOptions($对象);
                }
                
                // 如果赋值类型是"弹窗"，则标记为弹窗类型
                if (strpos($赋值类型, '弹窗') !== false && !empty($对象)) {
                    $field['inputType'] = 'popup';
                    $field['objectName'] = $对象;
                } else {
                    $field['inputType'] = 'text';
                }

                $fields[] = $field;
            }

            return $this->success(['fields' => $fields]);
        } catch (\Throwable $e) {
            log_message('error', '获取新增字段配置失败: ' . $e->getMessage());
            return $this->error('5001', '获取新增字段配置失败');
        }
    }

    /**
     * 获取详情显示字段配置
     */
    public function detailFields(string $functionCode = '')
    {
        try {
            // 从 session 获取字段模块
            $session = \Config\Services::session();
            $fieldModule = $session->get($functionCode . '-field_module');

            if (empty($fieldModule)) {
                $sql = sprintf(
                    'select 字段模块 from def_query_config where 查询模块 in (
                        select 模块名称 from def_function where 有效标识="1" and 功能编码=%s
                    )',
                    $this->quote($functionCode)
                );
                $result = $this->common->select($sql);
                if ($result !== false) {
                    $row = $result->getRowArray();
                    $fieldModule = $row['字段模块'] ?? '';
                }
            }

            if (empty($fieldModule)) {
                return $this->success(['fields' => []]);
            }

            // 查询可在详情中显示的字段
            $sql = sprintf(
                'select
                    列名, 字段名, 列类型, 列宽度, 可修改, 不可为空, 列顺序
                from view_function
                where 功能编码=%s and 列顺序>0
                group by 列名
                order by 列顺序',
                $this->quote($functionCode)
            );

            $result = $this->common->select($sql);
            if ($result === false) {
                return $this->success(['fields' => []]);
            }

            $columns = $result->getResultArray();
            $fields = [];

            foreach ($columns as $col) {
                $fields[] = [
                    'columnName' => $col['列名'],
                    'fieldName' => $col['字段名'],
                    'fieldType' => $col['列类型'] ?? '字符',
                    'width' => (int) (($col['列宽度'] ?? 0) > 0 ? $col['列宽度'] : max(strlen($col['列名'] ?? '') * 16, 120)),
                    'editable' => in_array((string) ($col['可修改'] ?? '0'), ['1', '2'], true),
                    'required' => (string) ($col['不可为空'] ?? '0') === '1'
                ];
            }

            return $this->success(['fields' => $fields]);
        } catch (\Throwable $e) {
            log_message('error', '获取详情字段配置失败: ' . $e->getMessage());
            return $this->error('5002', '获取详情字段配置失败');
        }
    }

    /**
     * 获取批量修改字段配置（可修改="2"）
     */
    public function batchEditFields(string $functionCode = '')
    {
        try {
            // 从 session 获取字段模块
            $session = \Config\Services::session();
            $fieldModule = $session->get($functionCode . '-field_module');

            if (empty($fieldModule)) {
                $sql = sprintf(
                    'select 字段模块 from def_query_config where 查询模块 in (
                        select 模块名称 from def_function where 有效标识="1" and 功能编码=%s
                    )',
                    $this->quote($functionCode)
                );
                $result = $this->common->select($sql);
                if ($result !== false) {
                    $row = $result->getRowArray();
                    $fieldModule = $row['字段模块'] ?? '';
                }
            }

            if (empty($fieldModule)) {
                return $this->success(['fields' => []]);
            }

            // 查询可批量修改的字段（可修改="2"）
            $sql = sprintf(
                'select
                    列名, 字段名, 列类型, 赋值类型, 对象, 缺省值, 不可为空, 列顺序
                from view_function
                where 功能编码=%s and 列顺序>0 and 可修改="2"
                group by 列名
                order by 列顺序',
                $this->quote($functionCode)
            );

            $result = $this->common->select($sql);
            if ($result === false) {
                return $this->success(['fields' => []]);
            }

            $columns = $result->getResultArray();
            $fields = [];

            foreach ($columns as $col) {
                $field = [
                    'columnName' => $col['列名'],
                    'fieldName' => $col['字段名'],
                    'fieldType' => $col['列类型'] ?? '字符',
                    'required' => (string) ($col['不可为空'] ?? '0') === '1',
                    'defaultValue' => $col['缺省值'] ?? '',
                    'objectName' => '',
                    'inputType' => 'text'
                ];

                // 处理系统变量默认值
                if ($field['defaultValue'] === '$当日日期') {
                    $field['defaultValue'] = date('Y-m-d');
                } elseif ($field['defaultValue'] === '$时间戳') {
                    $field['defaultValue'] = date('Y-m-d H:i:s');
                } elseif ($field['defaultValue'] === '$工号') {
                    $field['defaultValue'] = $session->get('user_workid') ?? '';
                } elseif ($field['defaultValue'] === '$属地') {
                    $field['defaultValue'] = $session->get('user_location') ?? '';
                }

                // 处理赋值类型
                $赋值类型 = $col['赋值类型'] ?? '';
                $对象 = $col['对象'] ?? '';

                // 如果赋值类型包含"固定值"，则查询对象选项
                if (strpos($赋值类型, '固定值') !== false && !empty($对象)) {
                    $field['objectName'] = $对象;
                    $field['objectOptions'] = $this->getObjectOptions($对象);
                }

                // 如果赋值类型是"弹窗"，则标记为弹窗类型
                if (strpos($赋值类型, '弹窗') !== false && !empty($对象)) {
                    $field['inputType'] = 'popup';
                    $field['objectName'] = $对象;
                }

                $fields[] = $field;
            }

            return $this->success(['fields' => $fields]);
        } catch (\Throwable $e) {
            log_message('error', '获取批量修改字段配置失败: ' . $e->getMessage());
            return $this->error('5003', '获取批量修改字段配置失败');
        }
    }

    /**
     * 新增记录
     */
    public function addRow(string $functionCode = '')
    {
        try {
            $request = $this->request->getJSON(true) ?? [];

            // 从 session 获取必要信息
            $session = \Config\Services::session();
            $dataTable = $session->get($functionCode . '-data_table');
            $dataModel = $session->get($functionCode . '-data_model');
            $beforeInsert = $session->get($functionCode . '-before_insert');
            $afterInsert = $session->get($functionCode . '-after_insert');
            $primaryKey = $session->get($functionCode . '-primary_key');

            // 如果 session 中没有，从数据库查询
            if (empty($dataTable)) {
                $sql = sprintf(
                    'select 数据表名, 数据模式, 新增前处理模块, 新增后处理模块, 主键字段
                    from def_query_config
                    where 查询模块 in (
                        select 模块名称 from def_function where 功能编码="%s"
                    )',
                    $functionCode
                );
                $result = $this->common->select($sql);
                if ($result !== false) {
                    $row = $result->getRowArray();
                    $dataTable = $row['数据表名'] ?? '';
                    $dataModel = $row['数据模式'] ?? '0';
                    $beforeInsert = $row['新增前处理模块'] ?? '';
                    $afterInsert = $row['新增后处理模块'] ?? '';
                    $primaryKey = $row['主键字段'] ?? '';
                }
            }

            if (empty($dataTable)) {
                return $this->error('5001', '新增失败：未找到数据表配置');
            }

            // 执行新增前处理
            if (!empty($beforeInsert)) {
                $spSql = sprintf('call %s("新增前", "")', $beforeInsert);
                $this->common->select($spSql);
            }

            // 根据数据模式执行不同的新增逻辑
            $num = 0;
            switch ($dataModel) {
                case '0':
                    $num = $this->addRowMode0($dataTable, $request);
                    break;
                case '1':
                    $num = $this->addRowMode1($dataTable, $request, $session->get('user_workid') ?? 'system');
                    break;
                case '2':
                    $num = $this->addRowMode2($dataTable, $request, $session->get('user_workid') ?? 'system');
                    break;
                default:
                    return $this->error('5001', sprintf('新增失败,数据模式[-%s-]错误', $dataModel));
            }

            // 执行新增后处理
            if (!empty($afterInsert) && !empty($primaryKey)) {
                $keyStr = $this->buildWhereFromData($request, $primaryKey);
                $spSql = sprintf('call %s("新增", "%s")', $afterInsert, $keyStr);
                $this->common->select($spSql);
            }

            return $this->success([
                'success' => true,
                'message' => sprintf('新增成功,新增 %d 条记录', $num)
            ]);
        } catch (\Throwable $e) {
            log_message('error', '新增记录失败: ' . $e->getMessage());
            return $this->error('5001', '新增失败');
        }
    }

    /**
     * 获取修改字段配置
     */
    public function updateFields(string $functionCode = '')
    {
        try {
            $functionCode = trim($functionCode);
            if ($functionCode === '') {
                return $this->error('5001', '功能编码不能为空');
            }

            // 获取请求数据
            $payload = $this->request->getJSON(true) ?? [];
            $keyValues = $payload['keys'] ?? [];

            if (empty($keyValues)) {
                return $this->error('5001', '未指定要修改的记录');
            }

            $session = \Config\Services::session();

            // 查询可修改的字段 - 使用 view_function 视图，与旧版 Frame.php 保持一致
            $sql = sprintf(
                'select
                    列名, 字段名, 列类型, 赋值类型, 对象, 缺省值, 不可为空, 可修改, 列顺序
                from view_function
                where 功能编码=%s and 列顺序>0 and (可修改="1" or 可修改="2")
                group by 列名
                order by 列顺序',
                $this->quote($functionCode)
            );

            $result = $this->common->select($sql);
            if ($result === false) {
                return $this->success(['fields' => [], 'currentData' => []]);
            }

            $columns = $result->getResultArray();
            $fields = [];

            foreach ($columns as $col) {
                $field = [
                    'columnName' => $col['列名'],
                    'fieldName' => $col['字段名'],
                    'fieldType' => $col['列类型'] ?? '字符',
                    'editorType' => $col['赋值类型'] ?? '',
                    'required' => ($col['不可为空'] ?? '0') === '1',
                    'readonly' => ($col['可修改'] ?? '0') === '2'  // 2表示只读
                ];
                $fields[] = $field;
            }

            // 获取主键字段
            $primaryKey = $session->get($functionCode . '-primary_key');
            if (empty($primaryKey)) {
                $sql = sprintf(
                    'SELECT 主键字段 FROM def_query_config
                    WHERE 查询模块 IN (
                        SELECT 模块名称 FROM def_function WHERE 功能编码 = %s
                    )',
                    $this->quote($functionCode)
                );
                $result = $this->common->select($sql);
                if ($result !== false && ($row = $result->getRowArray())) {
                    $primaryKey = $row['主键字段'] ?? '';
                }
            }

            // 获取当前记录数据
            $queryConfig = $this->loadQueryConfig($functionCode, '');
            $dataTable = $queryConfig['dataTable'] ?? '';
            $currentData = [];

            if (!empty($dataTable) && !empty($primaryKey)) {
                $keyStr = implode(',', array_map(fn($v) => sprintf("'%s'", addslashes($v)), $keyValues));
                $sql = sprintf(
                    'SELECT * FROM %s WHERE %s IN (%s) LIMIT 1',
                    $dataTable,
                    $primaryKey,
                    $keyStr
                );
                $result = $this->common->select($sql);
                if ($result !== false) {
                    $currentData = $result->getRowArray() ?: [];
                }
            }

            return $this->success([
                'fields' => $fields,
                'currentData' => $currentData
            ]);
        } catch (\Throwable $e) {
            log_message('error', '获取修改字段配置失败: ' . $e->getMessage());
            return $this->error('5001', '获取修改字段配置失败');
        }
    }

    /**
     * 修改记录
     */
    public function updateRow(string $functionCode = '')
    {
        try {
            $functionCode = trim($functionCode);
            if ($functionCode === '') {
                throw new \RuntimeException('功能编码不能为空');
            }

            // 获取请求数据
            $payload = $this->request->getJSON(true) ?? [];
            $keyValues = $payload['keys'] ?? [];
            $formData = $payload['data'] ?? [];

            if (empty($keyValues)) {
                return $this->error('5001', '修改失败：未指定要修改的记录');
            }

            // 获取 session 信息
            $session = \Config\Services::session();
            $userWorkid = $session->get('user_workid') ?? 'system';

            // 获取查询配置
            $queryConfig = $this->loadQueryConfig($functionCode, '');
            if (!$queryConfig || $queryConfig['dataTable'] === '') {
                return $this->error('5001', '修改失败：未找到数据表配置');
            }

            $dataTable = $queryConfig['dataTable'];
            $dataModel = $queryConfig['dataModel'];

            // 获取主键字段
            $primaryKey = $this->getPrimaryKey($functionCode, $queryConfig);
            if (empty($primaryKey)) {
                return $this->error('5001', '修改失败：未找到主键字段');
            }

            // 构建 where 条件
            $keyStr = implode(',', array_map(fn($v) => sprintf("'%s'", addslashes($v)), $keyValues));
            $where = sprintf('%s in (%s)', $primaryKey, $keyStr);

            // 构建更新字段
            $updates = [];
            foreach ($formData as $key => $value) {
                if ($key !== $primaryKey) {
                    $updates[] = sprintf('`%s` = "%s"', $key, addslashes($value));
                }
            }

            if (empty($updates)) {
                return $this->error('5001', '修改失败：没有要更新的字段');
            }

            // 根据数据模式执行不同的更新逻辑
            $num = 0;
            switch ($dataModel) {
                case '0':
                    // 模式0：直接更新
                    $sqlUpdate = sprintf(
                        'UPDATE %s SET %s WHERE %s',
                        $dataTable,
                        implode(', ', $updates),
                        $where
                    );
                    $this->common->sql_log('修改[0]', $functionCode, sprintf('表名=`%s`,主键=`%s`,值=`%s`', $dataTable, $primaryKey, $keyStr));
                    $num = $this->common->exec($sqlUpdate);
                    break;

                case '1':
                case '2':
                    // 模式1/2：标记修改（添加新记录，原记录标记删除）
                    // 先获取原始记录
                    $sqlSelect = sprintf('SELECT * FROM %s WHERE %s', $dataTable, $where);
                    $result = $this->common->select($sqlSelect);
                    if ($result === false) {
                        return $this->error('5001', '修改失败：无法获取原始记录');
                    }
                    $originalRow = $result->getRowArray();
                    if (empty($originalRow)) {
                        return $this->error('5001', '修改失败：原始记录不存在');
                    }

                    // 更新原始记录为无效
                    $sqlUpdateOld = sprintf(
                        'UPDATE %s SET 操作记录="修改",操作来源="工作台",操作人员="%s",操作时间="%s",结束操作时间="%s",删除标识="1",有效标识="0" WHERE %s',
                        $dataTable,
                        $userWorkid,
                        date('Y-m-d H:i:s'),
                        date('Y-m-d H:i:s'),
                        $where
                    );
                    $this->common->sql_log('修改[1-旧]', $functionCode, sprintf('表名=`%s`,主键=`%s`', $dataTable, $primaryKey));
                    $this->common->exec($sqlUpdateOld);

                    // 插入新记录
                    $fields = [];
                    $values = [];
                    foreach ($originalRow as $key => $val) {
                        if (isset($formData[$key])) {
                            $fields[] = sprintf('`%s`', $key);
                            $values[] = sprintf('"%s"', addslashes($formData[$key]));
                        } else {
                            $fields[] = sprintf('`%s`', $key);
                            $values[] = sprintf('"%s"', addslashes($val));
                        }
                    }
                    // 更新操作字段
                    $fields[] = '`操作记录`';
                    $values[] = '"新增"';
                    $fields[] = '`操作来源`';
                    $values[] = '"工作台"';
                    $fields[] = '`操作人员`';
                    $values[] = sprintf('"%s"', $userWorkid);
                    $fields[] = '`操作时间`';
                    $values[] = sprintf('"%s"', date('Y-m-d H:i:s'));
                    $fields[] = '`结束操作时间`';
                    $values[] = '"9999-12-31"';
                    $fields[] = '`删除标识`';
                    $values[] = '"0"';
                    $fields[] = '`有效标识`';
                    $values[] = '"1"';

                    $sqlInsert = sprintf(
                        'INSERT INTO %s (%s) VALUES (%s)',
                        $dataTable,
                        implode(', ', $fields),
                        implode(', ', $values)
                    );
                    $this->common->sql_log('修改[1-新]', $functionCode, sprintf('表名=`%s`', $dataTable));
                    $num = $this->common->exec($sqlInsert);
                    break;

                default:
                    return $this->error('5001', sprintf('修改失败,数据模式[-%s-]错误', $dataModel));
            }

            return $this->success([
                'success' => true,
                'message' => sprintf('修改成功,修改了 %d 条记录', $num),
                'updatedCount' => $num
            ]);
        } catch (\RuntimeException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '修改记录失败: ' . $e->getMessage());
            return $this->error('5001', '修改失败');
        }
    }

    /**
     * 批量修改记录（多条修改）
     * 将表单数据批量更新到所有选中的记录
     */
    public function batchUpdateRow(string $functionCode = '')
    {
        try {
            $functionCode = trim($functionCode);
            if ($functionCode === '') {
                throw new \RuntimeException('功能编码不能为空');
            }

            // 获取请求数据
            $payload = $this->request->getJSON(true) ?? [];
            $keyValues = $payload['keys'] ?? [];
            $formData = $payload['data'] ?? [];

            if (empty($keyValues)) {
                return $this->error('5001', '修改失败：未指定要修改的记录');
            }

            if (empty($formData)) {
                return $this->error('5001', '修改失败：没有要更新的字段');
            }

            // 获取 session 信息
            $session = \Config\Services::session();
            $userWorkid = $session->get('user_workid') ?? 'system';

            // 获取查询配置
            $queryConfig = $this->loadQueryConfig($functionCode, '');
            if (!$queryConfig || $queryConfig['dataTable'] === '') {
                return $this->error('5001', '修改失败：未找到数据表配置');
            }

            $dataTable = $queryConfig['dataTable'];
            $dataModel = $queryConfig['dataModel'];

            // 获取主键字段
            $primaryKey = $this->getPrimaryKey($functionCode, $queryConfig);
            if (empty($primaryKey)) {
                return $this->error('5001', '修改失败：未找到主键字段');
            }

            // 构建更新字段
            $updates = [];
            foreach ($formData as $key => $value) {
                if ($key !== $primaryKey) {
                    $updates[] = sprintf('`%s` = "%s"', $key, addslashes($value));
                }
            }

            if (empty($updates)) {
                return $this->error('5001', '修改失败：没有要更新的字段');
            }

            // 根据数据模式执行不同的更新逻辑
            $num = 0;
            switch ($dataModel) {
                case '0':
                    // 模式0：直接批量更新
                    foreach ($keyValues as $keyVal) {
                        $where = sprintf('%s = "%s"', $primaryKey, addslashes($keyVal));
                        $sqlUpdate = sprintf(
                            'UPDATE %s SET %s WHERE %s',
                            $dataTable,
                            implode(', ', $updates),
                            $where
                        );
                        $this->common->sql_log('批量修改[0]', $functionCode, sprintf('表名=`%s`,主键=`%s`,值=`%s`', $dataTable, $primaryKey, $keyVal));
                        $num += $this->common->exec($sqlUpdate);
                    }
                    break;

                case '1':
                case '2':
                    // 模式1/2：标记修改（每条记录都添加新版本）
                    foreach ($keyValues as $keyVal) {
                        $where = sprintf('%s = "%s"', $primaryKey, addslashes($keyVal));

                        // 先获取原始记录
                        $sqlSelect = sprintf('SELECT * FROM %s WHERE %s', $dataTable, $where);
                        $result = $this->common->select($sqlSelect);
                        if ($result === false) {
                            continue;
                        }
                        $originalRow = $result->getRowArray();
                        if (empty($originalRow)) {
                            continue;
                        }

                        // 更新原始记录为无效
                        $sqlUpdateOld = sprintf(
                            'UPDATE %s SET 操作记录="修改",操作来源="工作台",操作人员="%s",操作时间="%s",结束操作时间="%s",删除标识="1",有效标识="0" WHERE %s',
                            $dataTable,
                            $userWorkid,
                            date('Y-m-d H:i:s'),
                            date('Y-m-d H:i:s'),
                            $where
                        );
                        $this->common->sql_log('批量修改[1-旧]', $functionCode, sprintf('表名=`%s`,主键=`%s`', $dataTable, $primaryKey));
                        $this->common->exec($sqlUpdateOld);

                        // 插入新记录
                        $fields = [];
                        $values = [];
                        foreach ($originalRow as $key => $val) {
                            if (isset($formData[$key])) {
                                $fields[] = sprintf('`%s`', $key);
                                $values[] = sprintf('"%s"', addslashes($formData[$key]));
                            } else {
                                $fields[] = sprintf('`%s`', $key);
                                $values[] = sprintf('"%s"', addslashes($val));
                            }
                        }
                        // 更新操作字段
                        $fields[] = '`操作记录`';
                        $values[] = '"新增"';
                        $fields[] = '`操作来源`';
                        $values[] = '"工作台"';
                        $fields[] = '`操作人员`';
                        $values[] = sprintf('"%s"', $userWorkid);
                        $fields[] = '`操作时间`';
                        $values[] = sprintf('"%s"', date('Y-m-d H:i:s'));
                        $fields[] = '`结束操作时间`';
                        $values[] = '"9999-12-31"';
                        $fields[] = '`删除标识`';
                        $values[] = '"0"';
                        $fields[] = '`有效标识`';
                        $values[] = '"1"';

                        $sqlInsert = sprintf(
                            'INSERT INTO %s (%s) VALUES (%s)',
                            $dataTable,
                            implode(', ', $fields),
                            implode(', ', $values)
                        );
                        $this->common->sql_log('批量修改[1-新]', $functionCode, sprintf('表名=`%s`', $dataTable));
                        $num += $this->common->exec($sqlInsert);
                    }
                    break;

                default:
                    return $this->error('5001', sprintf('修改失败,数据模式[-%s-]错误', $dataModel));
            }

            return $this->success([
                'success' => true,
                'message' => sprintf('批量修改成功,修改了 %d 条记录', $num),
                'updatedCount' => $num
            ]);
        } catch (\RuntimeException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '批量修改记录失败: ' . $e->getMessage());
            return $this->error('5001', '批量修改失败');
        }
    }

    /**
     * 获取对象选项
     */
    private function getObjectOptions(string $objectName): array
    {
        try {
            $session = \Config\Services::session();
            $userLocation = $session->get('user_location') ?? '';

            $sql = sprintf(
                'select 对象值 from def_object where 对象名称=%s and (属地="" or locate(属地, %s))',
                $this->quote($objectName),
                $this->quote($userLocation)
            );

            $result = $this->common->select($sql);
            if ($result === false) {
                return [];
            }

            $options = [];
            $rows = $result->getResultArray();
            foreach ($rows as $row) {
                $options[] = [
                    'label' => $row['对象值'],
                    'value' => $row['对象值']
                ];
            }

            return $options;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 模式0新增：无额外字段
     */
    private function addRowMode0(string $dataTable, array $data): int
    {
        $fields = [];
        $values = [];

        foreach ($data as $key => $value) {
            $fields[] = sprintf('`%s`', $key);
            $values[] = sprintf('"%s"', addslashes($value));
        }

        if (empty($fields)) {
            return 0;
        }

        $sql = sprintf(
            'insert into %s (%s) values (%s)',
            $dataTable,
            implode(', ', $fields),
            implode(', ', $values)
        );

        $this->common->sql_log('新增[0]', '', sprintf('表名=`%s`', $dataTable));
        return $this->common->exec($sql);
    }

    /**
     * 模式1新增：有额外字段（原记录不变）
     */
    private function addRowMode1(string $dataTable, array $data, string $userWorkid): int
    {
        $fields = [];
        $values = [];

        foreach ($data as $key => $value) {
            $fields[] = sprintf('`%s`', $key);
            $values[] = sprintf('"%s"', addslashes($value));
        }

        // 添加额外字段
        $fields[] = '`操作记录`';
        $values[] = '"新增"';
        $fields[] = '`操作来源`';
        $values[] = '"工作台"';
        $fields[] = '`操作人员`';
        $values[] = sprintf('"%s"', $userWorkid);
        $fields[] = '`操作时间`';
        $values[] = sprintf('"%s"', date('Y-m-d H:i:s'));
        $fields[] = '`校验标识`';
        $values[] = '"0"';
        $fields[] = '`删除标识`';
        $values[] = '"0"';
        $fields[] = '`有效标识`';
        $values[] = '"1"';

        $sql = sprintf(
            'insert into %s (%s) values (%s)',
            $dataTable,
            implode(', ', $fields),
            implode(', ', $values)
        );

        $this->common->sql_log('新增[1]', '', sprintf('表名=`%s`', $dataTable));
        return $this->common->exec($sql);
    }

    /**
     * 模式2新增：有额外字段（流水账模式）
     */
    private function addRowMode2(string $dataTable, array $data, string $userWorkid): int
    {
        $fields = [];
        $values = [];

        foreach ($data as $key => $val) {
            $fields[] = sprintf('`%s`', $key);
            $values[] = sprintf('"%s"', addslashes($val));
        }

        // 添加额外字段
        $fields[] = '`操作记录`';
        $values[] = '"新增"';
        $fields[] = '`操作来源`';
        $values[] = '"工作台"';
        $fields[] = '`操作人员`';
        $values[] = sprintf('"%s"', $userWorkid);
        $fields[] = '`操作时间`';
        $values[] = sprintf('"%s"', date('Y-m-d H:i:s'));
        $fields[] = '`校验标识`';
        $values[] = '"0"';
        $fields[] = '`删除标识`';
        $values[] = '"0"';
        $fields[] = '`有效标识`';
        $values[] = '"1"';
        $fields[] = '`记录开始日期`';
        $values[] = sprintf('"%s"', date('Y-m-d'));
        $fields[] = '`记录结束日期`';
        $values[] = '"9999-12-31"';

        $sql = sprintf(
            'insert into %s (%s) values (%s)',
            $dataTable,
            implode(', ', $fields),
            implode(', ', $values)
        );

        $this->common->sql_log('新增[2]', '', sprintf('表名=`%s`', $dataTable));
        return $this->common->exec($sql);
    }

    /**
     * 根据数据和主键构建 where 条件
     */
    private function buildWhereFromData(array $data, string $primaryKey): string
    {
        $keys = explode(';', $primaryKey);
        $conditions = [];

        foreach ($keys as $key) {
            $key = trim($key);
            if (isset($data[$key])) {
                $conditions[] = sprintf('%s="%s"', $key, addslashes($data[$key]));
            }
        }

        return implode(' and ', $conditions);
    }

    /**
     * 删除记录
     */
    public function deleteRow(string $functionCode = '')
    {
        try {
            $functionCode = trim($functionCode);
            if ($functionCode === '') {
                throw new \RuntimeException('功能编码不能为空');
            }

            // 获取请求数据
            $payload = $this->request->getJSON(true) ?? [];
            $keyValues = $payload['keys'] ?? [];

            if (empty($keyValues)) {
                return $this->error('5001', '删除失败：未指定要删除的记录');
            }

            // 获取 session 信息
            $session = \Config\Services::session();
            $userWorkid = $session->get('user_workid') ?? 'system';

            // 获取查询配置
            $queryConfig = $this->loadQueryConfig($functionCode, '');
            if (!$queryConfig || $queryConfig['dataTable'] === '') {
                return $this->error('5001', '删除失败：未找到数据表配置');
            }

            $dataTable = $queryConfig['dataTable'];
            $dataModel = $queryConfig['dataModel'];

            // 获取主键字段
            $primaryKey = $this->getPrimaryKey($functionCode, $queryConfig);
            if (empty($primaryKey)) {
                return $this->error('5001', '删除失败：未找到主键字段');
            }

            // 构建 where 条件
            $keyStr = implode(',', array_map(fn($v) => sprintf("'%s'", addslashes($v)), $keyValues));
            $where = sprintf('%s in (%s)', $primaryKey, $keyStr);

            // 根据数据模式执行不同的删除逻辑
            $num = 0;
            switch ($dataModel) {
                case '0':
                    // 模式0：直接删除
                    $sqlDelete = sprintf('DELETE FROM %s WHERE %s', $dataTable, $where);
                    $this->common->sql_log('删除[0]', $functionCode, sprintf('表名=`%s`,主键=`%s`,值=`%s`', $dataTable, $primaryKey, $keyStr));
                    $num = $this->common->exec($sqlDelete);
                    break;

                case '1':
                case '2':
                    // 模式1/2：标记删除（更新删除标识和有效标识）
                    $sqlUpdate = sprintf(
                        'UPDATE %s SET 操作记录="删除",操作来源="工作台",操作人员="%s",操作时间="%s",结束操作时间="%s",删除标识="1",有效标识="0" WHERE %s',
                        $dataTable,
                        $userWorkid,
                        date('Y-m-d H:i:s'),
                        date('Y-m-d H:i:s'),
                        $where
                    );
                    $this->common->sql_log('删除[1]', $functionCode, sprintf('表名=`%s`,主键=`%s`,值=`%s`', $dataTable, $primaryKey, $keyStr));
                    $num = $this->common->exec($sqlUpdate);
                    break;

                default:
                    return $this->error('5001', sprintf('删除失败,数据模式[-%s-]错误', $dataModel));
            }

            return $this->success([
                'success' => true,
                'message' => sprintf('删除成功,删除了 %d 条记录', $num),
                'deletedCount' => $num
            ]);
        } catch (\RuntimeException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '删除记录失败: ' . $e->getMessage());
            return $this->error('5001', '删除失败');
        }
    }

    /**
     * 获取主键字段
     */
    private function getPrimaryKey(string $functionCode, array $queryConfig): string
    {
        // 优先从 session 获取
        $session = \Config\Services::session();
        $primaryKey = $session->get($functionCode . '-primary_key');

        if (!empty($primaryKey)) {
            return $primaryKey;
        }

        // 如果 session 中没有，从查询配置中获取
        // def_query_config 表中主键字段可能为空，需要从数据表结构推断
        $dataTable = $queryConfig['dataTable'] ?? '';
        if (empty($dataTable)) {
            return '';
        }

        // 尝试从 def_function 或 def_query_config 中获取主键配置
        $sql = sprintf(
            'SELECT t1.主键字段 FROM def_query_config t1
            INNER JOIN def_function t2 ON t2.模块名称 = t1.查询模块
            WHERE t2.功能编码 = %s',
            $this->quote($functionCode)
        );

        $result = $this->common->select($sql);
        if ($result !== false && ($row = $result->getRowArray()) && !empty($row['主键字段'])) {
            return $row['主键字段'];
        }

        // 如果还是没有配置，尝试从数据表结构获取（假设主键是 GUID 或 ID）
        $sql = sprintf('SHOW INDEX FROM %s WHERE Key_name = "PRIMARY"', $dataTable);
        $result = $this->common->select($sql);
        if ($result !== false && ($row = $result->getRowArray())) {
            return $row['Column_name'] ?? '';
        }

        return '';
    }

    /**
     * 获取弹窗数据
     */
    public function popupData(string $functionCode = '')
    {
        try {
            // 从查询参数获取对象名称
            $request = service('request');
            $objectName = $request->getGet('objectName');
            if ($objectName === null) {
                $objectName = '';
            }

            // 查询弹窗配置
            // 注意：前端传递的是"对象"字段的值（如"预算部门^全称"），不是"对象名称"
            $sql = sprintf(
                'select 对象, 对象名称, 对象表名
                from view_function
                where 赋值类型="弹窗" and 功能编码=%s and 对象=%s
                group by 对象',
                $this->quote($functionCode),
                $this->quote($objectName)
            );

            $result = $this->common->select($sql);
            if ($result === false) {
                return $this->error('5001', '未找到弹窗配置');
            }

            $row = $result->getRowArray();
            if (!$row) {
                return $this->error('5001', '未找到弹窗配置');
            }

            // 查询弹窗数据
            $objSql = sprintf(
                'select 对象名称, 本级编码, 本级名称, 本级全称, 本级级别名称, 本级级别,
                    上级编码, 上级名称, 上级全称, 上级级别名称, 最大级别, 本级初始值
                from %s
                order by 对象名称, 本级级别, 本级全称',
                $row['对象表名']
            );

            $objResult = $this->common->select($objSql);
            if ($objResult === false) {
                return $this->error('5001', '查询弹窗数据失败');
            }

            $objRows = $objResult->getResultArray();

            // 构建弹窗数据结构
            $popupGrid = [];
            $popupObj = [];

            foreach ($objRows as $objRow) {
                $levelName = $objRow['本级级别名称'];
                $parentName = $objRow['上级名称'];

                if (!isset($popupObj[$levelName])) {
                    $popupObj[$levelName] = [];
                    $popupObj[$levelName]['本级级别'] = $objRow['本级级别'];
                    $popupObj[$levelName]['本级初始值'] = $objRow['本级初始值'];
                    $popupObj[$levelName]['上级级别名称'] = $objRow['上级级别名称'];

                    // 前端 popup_grid 数据
                    $popupGrid[] = [
                        '表项' => $levelName,
                        '级别' => $objRow['本级级别'],
                        '取值' => $objRow['本级初始值']
                    ];
                }

                if (!isset($popupObj[$levelName][$parentName])) {
                    $popupObj[$levelName][$parentName] = [];
                }
                $popupObj[$levelName][$parentName][] = $objRow['本级名称'];
            }

            return $this->success([
                'popupGrid' => $popupGrid,
                'popupObj' => $popupObj,
                'maxLevel' => $objRows[0]['最大级别'] ?? 1
            ]);
        } catch (\Throwable $e) {
            log_message('error', '获取弹窗数据失败: ' . $e->getMessage());
            return $this->error('5001', '获取弹窗数据失败');
        }
    }

    /**
     * 获取弹窗级联级别配置
     * @param string $functionCode 功能编码
     * @return \CodeIgniter\HTTP\Response
     */
    public function popupLevels(string $functionCode = '')
    {
        try {
            $request = service('request');
            $objectName = $request->getGet('objectName');
            if ($objectName === null) {
                $objectName = '';
            }

            // 查询弹窗配置
            $sql = sprintf(
                'select 对象, 对象名称, 对象表名
                from view_function
                where 赋值类型="弹窗" and 功能编码=%s and 对象=%s
                group by 对象',
                $this->quote($functionCode),
                $this->quote($objectName)
            );

            $result = $this->common->select($sql);
            if ($result === false || !($row = $result->getRowArray())) {
                return $this->error('5001', '未找到弹窗配置');
            }

            // 查询级别配置
            $levelSql = sprintf(
                'select distinct 本级级别, 本级级别名称, 本级初始值, 最大级别
                from %s
                order by 本级级别',
                $row['对象表名']
            );

            $levelResult = $this->common->select($levelSql);
            if ($levelResult === false) {
                return $this->error('5001', '查询级别配置失败');
            }

            $levels = [];
            $maxLevel = 1;
            foreach ($levelResult->getResultArray() as $levelRow) {
                $levels[] = [
                    'name' => $levelRow['本级级别名称'],
                    'level' => (int)$levelRow['本级级别'],
                    'initialValue' => $levelRow['本级初始值']
                ];
                $maxLevel = (int)$levelRow['最大级别'];
            }

            return $this->success([
                'levels' => $levels,
                'maxLevel' => $maxLevel
            ]);
        } catch (\Throwable $e) {
            log_message('error', '获取弹窗级别配置失败: ' . $e->getMessage());
            return $this->error('5001', '获取弹窗级别配置失败');
        }
    }

    /**
     * 获取弹窗指定级别的数据（懒加载）
     * @param string $functionCode 功能编码
     * @return \CodeIgniter\HTTP\Response
     */
    public function popupLevelData(string $functionCode = '')
    {
        try {
            $request = service('request');
            $objectName = $request->getGet('objectName');
            $level = (int)($request->getGet('level') ?? 1);
            $parentCode = $request->getGet('parentCode') ?? '';

            if ($objectName === null) {
                $objectName = '';
            }

            // 查询弹窗配置
            $sql = sprintf(
                'select 对象, 对象名称, 对象表名
                from view_function
                where 赋值类型="弹窗" and 功能编码=%s and 对象=%s
                group by 对象',
                $this->quote($functionCode),
                $this->quote($objectName)
            );

            $result = $this->common->select($sql);
            if ($result === false || !($row = $result->getRowArray())) {
                return $this->error('5001', '未找到弹窗配置');
            }

            // 查询指定级别的数据
            if ($level === 1) {
                // 第一级：查询所有顶级节点
                $dataSql = sprintf(
                    'select 本级编码, 本级名称, 本级全称,
                        (select count(*) from %1$s as sub where sub.本级级别 = %2$d + 1 and sub.本级全称 like concat(main.本级全称, \'>>%%\')) as has_children
                    from %1$s as main
                    where main.本级级别 = %2$d
                    order by main.本级编码',
                    $row['对象表名'],
                    $level
                );
            } else {
                // 其他级别：根据父级名称查询（通过本级全称匹配）
                // 查询本级全称以"父级全称>>"开头的记录
                $dataSql = sprintf(
                    'select 本级编码, 本级名称, 本级全称,
                        (select count(*) from %1$s as sub where sub.本级级别 = %2$d + 1 and sub.本级全称 like concat(main.本级全称, \'>>%%\')) as has_children
                    from %1$s as main
                    where main.本级级别 = %2$d and main.本级全称 like %3$s
                    order by main.本级编码',
                    $row['对象表名'],
                    $level,
                    $this->quote($parentCode . '>>%')
                );
            }

            $dataResult = $this->common->select($dataSql);
            if ($dataResult === false) {
                return $this->error('5001', '查询级别数据失败');
            }

            $items = [];
            foreach ($dataResult->getResultArray() as $dataRow) {
                $items[] = [
                    'code' => $dataRow['本级编码'],
                    'name' => $dataRow['本级名称'],
                    'fullName' => $dataRow['本级全称'],
                    'hasChildren' => (int)$dataRow['has_children'] > 0
                ];
            }

            return $this->success([
                'items' => $items,
                'level' => $level
            ]);
        } catch (\Throwable $e) {
            log_message('error', '获取弹窗级别数据失败: ' . $e->getMessage());
            return $this->error('5001', '获取弹窗级别数据失败');
        }
    }

    /**
     * 表级修改提交
     * 直接在表格中修改数据后批量提交
     */
    public function tableEdit(string $functionCode = '')
    {
        try {
            $functionCode = trim($functionCode);
            if ($functionCode === '') {
                throw new \RuntimeException('功能编码不能为空');
            }

            // 获取请求数据（修改后的行数据数组）
            $rows = $this->request->getJSON(true) ?? [];
            if (empty($rows)) {
                return $this->error('5001', '没有要提交的修改数据');
            }

            // 获取 session 信息
            $session = \Config\Services::session();
            $userWorkid = $session->get('user_workid') ?? 'system';

            // 获取查询配置
            $queryConfig = $this->loadQueryConfig($functionCode, '');
            if (!$queryConfig || $queryConfig['dataTable'] === '') {
                return $this->error('5001', '修改失败：未找到数据表配置');
            }

            $dataTable = $queryConfig['dataTable'];
            $dataModel = $queryConfig['dataModel'];

            // 获取主键字段
            $primaryKey = $this->getPrimaryKey($functionCode, $queryConfig);
            if (empty($primaryKey)) {
                return $this->error('5001', '修改失败：未找到主键字段');
            }

            // 根据数据模式执行不同的更新逻辑
            $num = 0;
            switch ($dataModel) {
                case '0':
                    // 模式0：直接更新每条记录
                    foreach ($rows as $row) {
                        $where = $this->buildWhereFromData($row, $primaryKey);
                        if (empty($where)) {
                            continue;
                        }

                        // 构建更新字段（排除主键）
                        $updates = [];
                        foreach ($row as $key => $value) {
                            if ($key !== $primaryKey && !in_array($key, ['操作记录', '操作来源', '操作人员', '操作时间', '结束操作时间', '删除标识', '有效标识', '记录开始日期', '记录结束日期'])) {
                                $updates[] = sprintf('`%s` = "%s"', $key, addslashes($value));
                            }
                        }

                        if (empty($updates)) {
                            continue;
                        }

                        $sqlUpdate = sprintf(
                            'UPDATE %s SET %s WHERE %s',
                            $dataTable,
                            implode(', ', $updates),
                            $where
                        );
                        $this->common->sql_log('表级修改[0]', $functionCode, sprintf('表名=`%s`,主键=`%s`', $dataTable, $primaryKey));
                        $num += $this->common->exec($sqlUpdate);
                    }
                    break;

                case '1':
                case '2':
                    // 模式1/2：标记修改（每条记录都添加新版本）
                    foreach ($rows as $row) {
                        $where = $this->buildWhereFromData($row, $primaryKey);
                        if (empty($where)) {
                            continue;
                        }

                        // 先获取原始记录
                        $sqlSelect = sprintf('SELECT * FROM %s WHERE %s', $dataTable, $where);
                        $result = $this->common->select($sqlSelect);
                        if ($result === false) {
                            continue;
                        }
                        $originalRow = $result->getRowArray();
                        if (empty($originalRow)) {
                            continue;
                        }

                        // 更新原始记录为无效
                        $sqlUpdateOld = sprintf(
                            'UPDATE %s SET 操作记录="修改",操作来源="工作台",操作人员="%s",操作时间="%s",结束操作时间="%s",删除标识="1",有效标识="0" WHERE %s',
                            $dataTable,
                            $userWorkid,
                            date('Y-m-d H:i:s'),
                            date('Y-m-d H:i:s'),
                            $where
                        );
                        $this->common->sql_log('表级修改[1-旧]', $functionCode, sprintf('表名=`%s`,主键=`%s`', $dataTable, $primaryKey));
                        $this->common->exec($sqlUpdateOld);

                        // 插入新记录
                        $fields = [];
                        $values = [];
                        foreach ($originalRow as $key => $val) {
                            // 使用修改后的值，如果没有修改则使用原值
                            if (isset($row[$key]) && !in_array($key, ['操作记录', '操作来源', '操作人员', '操作时间', '结束操作时间', '删除标识', '有效标识', '记录开始日期', '记录结束日期'])) {
                                $fields[] = sprintf('`%s`', $key);
                                $values[] = sprintf('"%s"', addslashes($row[$key]));
                            } else if (!in_array($key, ['操作记录', '操作来源', '操作人员', '操作时间', '结束操作时间', '删除标识', '有效标识', '记录开始日期', '记录结束日期'])) {
                                $fields[] = sprintf('`%s`', $key);
                                $values[] = sprintf('"%s"', addslashes($val));
                            }
                        }

                        // 更新操作字段
                        $fields[] = '`操作记录`';
                        $values[] = '"新增"';
                        $fields[] = '`操作来源`';
                        $values[] = '"工作台"';
                        $fields[] = '`操作人员`';
                        $values[] = sprintf('"%s"', $userWorkid);
                        $fields[] = '`操作时间`';
                        $values[] = sprintf('"%s"', date('Y-m-d H:i:s'));
                        $fields[] = '`结束操作时间`';
                        $values[] = '"9999-12-31"';
                        $fields[] = '`删除标识`';
                        $values[] = '"0"';
                        $fields[] = '`有效标识`';
                        $values[] = '"1"';

                        if ($dataModel === '2') {
                            $fields[] = '`记录开始日期`';
                            $values[] = sprintf('"%s"', date('Y-m-d'));
                            $fields[] = '`记录结束日期`';
                            $values[] = '"9999-12-31"';
                        }

                        $sqlInsert = sprintf(
                            'INSERT INTO %s (%s) VALUES (%s)',
                            $dataTable,
                            implode(', ', $fields),
                            implode(', ', $values)
                        );
                        $this->common->sql_log('表级修改[1-新]', $functionCode, sprintf('表名=`%s`', $dataTable));
                        $num += $this->common->exec($sqlInsert);
                    }
                    break;

                default:
                    return $this->error('5001', sprintf('修改失败,数据模式[-%s-]错误', $dataModel));
            }

            return $this->success([
                'success' => true,
                'message' => sprintf('表级修改提交成功,修改了 %d 条记录', $num),
                'updatedCount' => $num
            ]);
        } catch (\RuntimeException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '表级修改提交失败: ' . $e->getMessage());
            return $this->error('5001', '表级修改提交失败');
        }
    }

    /**
     * 获取图形数据
     *
     * @param string $functionCode 功能编码
     * @return Response
     */
    public function chart(string $functionCode = '')
    {
        try {
            [$context, $definition] = $this->buildWorkbenchContext($functionCode);
            $chartModule = $definition['chartModule'] ?? '';

            if (empty($chartModule)) {
                return $this->error('4001', '当前功能未配置图形模块');
            }

            $chartData = $this->getChartData($context, $chartModule);

            return $this->success([
                'charts' => $chartData
            ]);
        } catch (\RuntimeException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '获取图形数据失败: ' . $e->getMessage());
            return $this->error('5001', '获取图形数据失败');
        }
    }

    /**
     * 查询图形数据
     *
     * @param array $context 上下文信息
     * @param string $chartModule 图形模块
     * @return array 图形数据
     */
    private function getChartData(array $context, string $chartModule): array
    {
        $model = new Mcommon();
        $chartData = [];

        // 查询图形配置
        $sql = sprintf('
            select 图形模块,图形编号,图形名称,图形类型,
                取数方式,查询表名,查询字段,属地字段,查询条件,汇总条件,排序条件,记录条数,
                字段模块,页面布局,钻取模块,条件叠加,顺序
            from def_chart_config
            where 有效标识="1" and 图形模块="%s" and 顺序>0
            order by 图形模块,图形编号,顺序',
            $chartModule
        );

        $results = $model->select($sql)->getResult();

        foreach ($results as $row) {
            $chartItem = [
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

            // 构建查询 SQL
            if ($row->取数方式 === '存储过程') {
                // 替换存储过程参数 - 查询表名包含完整的存储过程调用
                $dataSql = $row->查询表名;
                $dataSql = str_replace('$查询表名', sprintf('%s', $context['queryTable'] ?? ''), $dataSql);
                // 生成 JSON 格式的字符串："[\"公司\"]"
                $deptNameAuth = $context['user']['deptNameAuth'] ?? '';
                $deptNameAuthJson = '"[\\"' . $deptNameAuth . '\\"]"';
                $dataSql = str_replace('$[部门全称赋权]', $deptNameAuthJson, $dataSql);

                // 添加 call 关键字
                if (strpos($dataSql, 'call ') !== 0) {
                    $dataSql = 'call ' . $dataSql;
                }
            } else {
                // 构建标准查询 - 参考旧版 Frame.php 的逻辑
                $fields = $row->查询字段 ?? '*';
                $table = $row->查询表名;

                // 构建 WHERE 条件
                $whereParts = [];

                // 添加部门授权条件
                $deptAuthCond = $context['deptAuthzCond'] ?? '';
                if (!empty($deptAuthCond)) {
                    $whereParts[] = $deptAuthCond;
                }

                // 添加属地授权条件
                $locationAuthCond = $context['locationAuthzCond'] ?? '';
                if (!empty($row->属地字段) && !empty($locationAuthCond)) {
                    $whereParts[] = $locationAuthCond;
                }

                // 添加配置的查询条件
                if (!empty($row->查询条件)) {
                    $queryCond = $row->查询条件;
                    // 替换变量
                    $queryCond = $this->replaceConditionVariables($queryCond, $context);
                    $whereParts[] = $queryCond;
                }

                // 组合 WHERE 条件
                if (count($whereParts) > 0) {
                    $where = implode(' and ', $whereParts);
                } else {
                    $where = '1=1';
                }

                // 构建完整 SQL
                $dataSql = sprintf('select %s, "%s^%s" as SID from %s where %s',
                    $fields,
                    $row->图形模块,
                    $row->图形编号,
                    $table,
                    $where
                );

                // 添加 GROUP BY
                if (!empty($row->汇总条件)) {
                    $dataSql .= ' group by ' . $row->汇总条件;
                }

                // 添加 ORDER BY
                if (!empty($row->排序条件)) {
                    $dataSql .= ' order by ' . $row->排序条件;
                }

                // 添加 LIMIT
                if (!empty($row->记录条数)) {
                    $dataSql .= ' limit ' . $row->记录条数;
                }
            }

            try {
                $queryResult = $model->select($dataSql);
                if ($queryResult === false) {
                    throw new \RuntimeException('数据库查询返回 false');
                }
                $dataResults = $queryResult->getResult();
                $chartItem['数据'] = $dataResults ?? [];
                $chartItem['SQL'] = $dataSql;

                // 存储过程返回的结果中包含图形名称，需要从结果中读取
                if ($row->取数方式 === '存储过程' && !empty($dataResults) && isset($dataResults[0]->图形名称)) {
                    $chartItem['图形名称'] = $dataResults[0]->图形名称;
                }
            } catch (\Throwable $e) {
                $errorMsg = $e->getMessage();
                log_message('error', '图形数据查询失败: ' . $errorMsg . ' SQL: ' . $dataSql);
                $chartItem['数据'] = [];
                $chartItem['错误'] = $errorMsg;
                $chartItem['SQL'] = $dataSql;
            }

            if (!empty($row->字段模块)) {
                $colSql = sprintf('
                    select 字段模块,列名,字段名,坐标轴,图形类型
                    from def_chart_column
                    where 字段模块="%s" and 顺序>0
                    order by 字段模块,顺序', $row->字段模块);

                $colResults = $model->select($colSql)->getResult();
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

            $chartData[] = $chartItem;
        }

        return $chartData;
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
}
