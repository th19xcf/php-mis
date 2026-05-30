<?php

namespace App\Controllers;

use App\Constants\ApiCode;
use App\Libraries\AuthorizationService;
use App\Libraries\SessionUserContext;
use App\Models\Mcommon;
use App\Services\Workbench\ChartService;
use App\Services\Workbench\DrillService;
use App\Services\Workbench\QueryService;
use App\Services\Workbench\EditService;
use App\Services\Workbench\ImportService;
use App\Services\Workbench\ContextService;

class Workbench extends BaseController
{
    private Mcommon $common;
    private SessionUserContext $userContext;
    private AuthorizationService $authorizationService;
    private ChartService $chartService;
    private DrillService $drillService;
    private QueryService $queryService;
    private EditService $editService;
    private ImportService $importService;
    private ContextService $contextService;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->common = new Mcommon();
        $this->userContext = new SessionUserContext();
        $this->authorizationService = new AuthorizationService();
        $this->chartService = new ChartService();
        $this->drillService = new DrillService();
        $this->queryService = new QueryService();
        $this->editService = new EditService();
        $this->importService = new ImportService();
        $this->contextService = new ContextService();
    }

    public function page(string $functionCode = '')
    {
        try {
            log_message('debug', '[Workbench::page] 开始处理，functionCode: ' . $functionCode);
            [$context, $definition] = $this->contextService->buildWorkbenchContext($functionCode);
            log_message('debug', '[Workbench::page] 上下文构建成功');

            // 只返回元数据，不查询数据，由前端单独请求数据
            return $this->success([
                'meta' => $definition
            ]);
        } catch (\RuntimeException $e) {
            log_message('error', '[Workbench::page] RuntimeException: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '[Workbench::page] Throwable: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
            return $this->error('5001', $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
        }
    }

    public function query(string $functionCode = '')
    {
        try {
            $payload = $this->request->getJSON(true) ?? [];

            [$context] = $this->contextService->buildWorkbenchContext($functionCode);
            $records = $this->queryService->queryRecords($context, $payload);

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
            $size = max(1, min(5000, (int) ($payload['size'] ?? 1000)));
            $offset = max(0, (int) ($payload['offset'] ?? (($current - 1) * $size)));
            $fetchTotal = filter_var($payload['fetchTotal'] ?? true, FILTER_VALIDATE_BOOLEAN);

            // 获取上下文
            [$context] = $this->contextService->buildWorkbenchContext($functionCode);

            // 查询总数量（可选，首次加载时需要）
            $total = 0;
            if ($fetchTotal) {
                $total = $this->queryService->queryTotalCount($context, $payload);
            }

            // 分页查询数据
            $records = $this->queryService->queryRecordsPaged($context, $payload, $current, $size, $offset);

            return $this->success([
                'records' => $records,
                'current' => $current,
                'size' => $size,
                'offset' => $offset,
                'total' => $total,
                'hasMore' => count($records) === $size
            ]);
        } catch (\RuntimeException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '分页查询失败: ' . $e->getMessage());
            return $this->error('5003', '分页查询失败: ' . $e->getMessage());
        }
    }

    public function debug(string $functionCode = '')
    {
        try {
            $payload = $this->request->getJSON(true) ?? [];

            [$context, $definition] = $this->contextService->buildWorkbenchContext($functionCode);

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

            [$context] = $this->contextService->buildWorkbenchContext($functionCode);
            
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
            
            $drillOptions = $this->drillService->getDrillOptions($context, $payload, $drillModule);

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

    public function importColumns(string $functionCode = '')
    {
        try {
            $functionCode = trim($functionCode);
            if ($functionCode === '') {
                throw new \RuntimeException('功能编码不能为空');
            }

            $result = $this->importService->getImportColumns($functionCode);

            return $this->success(['columns' => $result['columns'] ?? []]);
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
            $session = \Config\Services::session();
            $fieldModule = $session->get($functionCode . '-field_module');

            $result = $this->editService->getAddFields($functionCode, $fieldModule);

            return $this->success($result);
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
            $session = \Config\Services::session();

            $config = $this->editService->getDataTableConfig($functionCode, $session);

            if (empty($config['dataTable'])) {
                return $this->error('5001', '新增失败：未找到数据表配置');
            }

            $this->editService->executeBeforeInsert($config['beforeInsert']);

            $num = 0;
            switch ($config['dataModel']) {
                case '0':
                    $num = $this->editService->addRowMode0($config['dataTable'], $request);
                    break;
                case '1':
                    $num = $this->editService->addRowMode1($config['dataTable'], $request, $session->get('user_workid') ?? 'system');
                    break;
                case '2':
                    $num = $this->editService->addRowMode2($config['dataTable'], $request, $session->get('user_workid') ?? 'system');
                    break;
                default:
                    return $this->error('5001', sprintf('新增失败,数据模式[-%s-]错误', $config['dataModel']));
            }

            $this->editService->executeAfterInsert($config['afterInsert'], $config['primaryKey'], $request);

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

        $result = $this->common->select($sql);
        if ($result === false) {
            return [];
        }
        $row = $result->getRowArray();
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
            log_message('info', '[tableEdit] 开始处理，functionCode=' . $functionCode);
            $functionCode = trim($functionCode);
            if ($functionCode === '') {
                throw new \RuntimeException('功能编码不能为空');
            }

            // 获取请求数据（修改后的行数据数组）
            $rows = $this->request->getJSON(true) ?? [];
            log_message('info', '[tableEdit] 收到修改数据，行数=' . count($rows));
            if (empty($rows)) {
                return $this->error('5001', '没有要提交的修改数据');
            }

            // 获取 session 信息
            $session = \Config\Services::session();
            $userWorkid = $session->get('user_workid') ?? 'system';

            // 获取查询配置
            log_message('debug', '[tableEdit] 开始加载查询配置');
            $queryConfig = $this->loadQueryConfig($functionCode, '');
            log_message('debug', '[tableEdit] 查询配置加载结果: ' . json_encode($queryConfig, JSON_UNESCAPED_UNICODE));
            if (!$queryConfig || $queryConfig['dataTable'] === '') {
                log_message('error', '[tableEdit] 未找到数据表配置，queryConfig=' . json_encode($queryConfig));
                return $this->error('5001', '修改失败：未找到数据表配置');
            }

            $dataTable = $queryConfig['dataTable'];
            $dataModel = $queryConfig['dataModel'];
            log_message('info', '[tableEdit] 数据表=' . $dataTable . ', 数据模式=' . $dataModel);

            // 获取主键字段
            $primaryKey = $this->getPrimaryKey($functionCode, $queryConfig);
            log_message('info', '[tableEdit] 主键字段=' . $primaryKey);
            if (empty($primaryKey)) {
                log_message('error', '[tableEdit] 未找到主键字段');
                return $this->error('5001', '修改失败：未找到主键字段');
            }

            // 根据数据模式执行不同的更新逻辑
            $num = 0;
            switch ($dataModel) {
                case '0':
                    // 模式0：直接更新（批量优化）
                    log_message('info', '[tableEdit] 开始模式0更新，记录数=' . count($rows));
                    
                    // 按更新字段分组进行批量更新
                    $updateGroups = [];
                    foreach ($rows as $index => $row) {
                        log_message('debug', '[tableEdit] 处理第' . ($index + 1) . '条记录: ' . json_encode($row));
                        
                        // 收集更新字段
                        $updateFields = [];
                        foreach ($row as $key => $value) {
                            if ($key !== $primaryKey && !in_array($key, ['操作记录', '操作来源', '操作人员', '操作时间', '结束操作时间', '删除标识', '有效标识', '记录开始日期', '记录结束日期'])) {
                                $updateFields[] = $key;
                            }
                        }
                        
                        if (empty($updateFields)) {
                            log_message('warn', '[tableEdit] 第' . ($index + 1) . '条记录没有需要更新的字段，跳过');
                            continue;
                        }
                        
                        // 按相同的更新字段分组
                        sort($updateFields);
                        $groupKey = implode('|', $updateFields);
                        
                        if (!isset($updateGroups[$groupKey])) {
                            $updateGroups[$groupKey] = [
                                'fields' => $updateFields,
                                'rows' => []
                            ];
                        }
                        $updateGroups[$groupKey]['rows'][] = $row;
                    }
                    
                    log_message('info', '[tableEdit] 分组完成，共' . count($updateGroups) . '个更新组');
                    
                    // 对每个组进行批量更新
                    foreach ($updateGroups as $groupKey => $group) {
                        $updateFields = $group['fields'];
                        $groupRows = $group['rows'];
                        
                        if (count($groupRows) === 1) {
                            // 单条记录，使用原方式
                            $row = $groupRows[0];
                            $where = $this->buildWhereFromData($row, $primaryKey);
                            if (empty($where)) continue;
                            
                            $updates = [];
                            foreach ($row as $key => $value) {
                                if ($key !== $primaryKey && !in_array($key, ['操作记录', '操作来源', '操作人员', '操作时间', '结束操作时间', '删除标识', '有效标识', '记录开始日期', '记录结束日期'])) {
                                    $updates[] = sprintf('`%s` = "%s"', $key, addslashes($value));
                                }
                            }
                            
                            $sqlUpdate = sprintf('UPDATE %s SET %s WHERE %s', $dataTable, implode(', ', $updates), $where);
                            log_message('debug', '[tableEdit] 单条更新SQL: ' . $sqlUpdate);
                            $this->common->sql_log('表级修改[0]', $functionCode, sprintf('表名=`%s`,主键=`%s`', $dataTable, $primaryKey));
                            $affected = $this->common->exec($sqlUpdate);
                            $num += $affected;
                        } else {
                            // 多条记录，使用 CASE WHEN 批量更新
                            log_message('info', '[tableEdit] 开始批量更新，组内记录数=' . count($groupRows));
                            
                            $caseStatements = [];
                            $primaryKeyValues = [];
                            
                            foreach ($updateFields as $field) {
                                $caseParts = [];
                                foreach ($groupRows as $row) {
                                    $pkValue = addslashes($row[$primaryKey]);
                                    $fieldValue = addslashes($row[$field]);
                                    $caseParts[] = sprintf('WHEN `%s` = "%s" THEN "%s"', $primaryKey, $pkValue, $fieldValue);
                                    $primaryKeyValues[] = $pkValue;
                                }
                                $caseStatements[] = sprintf('`%s` = CASE %s ELSE `%s` END', $field, implode(' ', $caseParts), $field);
                            }
                            
                            $primaryKeyValues = array_unique($primaryKeyValues);
                            $whereIn = sprintf('`%s` IN ("%s")', $primaryKey, implode('","', $primaryKeyValues));
                            
                            $sqlUpdate = sprintf(
                                'UPDATE %s SET %s WHERE %s',
                                $dataTable,
                                implode(', ', $caseStatements),
                                $whereIn
                            );
                            
                            log_message('debug', '[tableEdit] 批量更新SQL: ' . $sqlUpdate);
                            $this->common->sql_log('表级修改[0]', $functionCode, sprintf('表名=`%s`,主键=`%s`,批量数=%d', $dataTable, $primaryKey, count($groupRows)));
                            
                            $affected = $this->common->exec($sqlUpdate);
                            log_message('info', '[tableEdit] 批量更新影响行数=' . $affected);
                            $num += $affected;
                        }
                    }
                    break;

                case '1':
                case '2':
                    // 模式1/2：标记修改（批量优化）
                    log_message('info', '[tableEdit] 开始模式' . $dataModel . '更新，记录数=' . count($rows));
                    
                    // 收集所有主键值
                    $primaryKeyValues = [];
                    $validRows = [];
                    foreach ($rows as $index => $row) {
                        $where = $this->buildWhereFromData($row, $primaryKey);
                        if (empty($where)) {
                            log_message('warn', '[tableEdit] 第' . ($index + 1) . '条记录WHERE条件为空，跳过');
                            continue;
                        }
                        $primaryKeyValues[] = addslashes($row[$primaryKey]);
                        $validRows[] = $row;
                    }
                    
                    if (empty($validRows)) {
                        log_message('warn', '[tableEdit] 没有有效的记录需要修改');
                        break;
                    }
                    
                    log_message('info', '[tableEdit] 有效记录数=' . count($validRows));
                    
                    // 1. 批量查询所有原始记录
                    $whereIn = sprintf('`%s` IN ("%s")', $primaryKey, implode('","', $primaryKeyValues));
                    $sqlSelect = sprintf('SELECT * FROM %s WHERE %s', $dataTable, $whereIn);
                    log_message('debug', '[tableEdit] 批量查询原始记录SQL: ' . $sqlSelect);
                    $result = $this->common->select($sqlSelect);
                    
                    if ($result === false) {
                        log_message('error', '[tableEdit] 批量查询原始记录失败');
                        break;
                    }
                    
                    $originalRows = [];
                    $resultArray = $result->getResultArray();
                    foreach ($resultArray as $row) {
                        $originalRows[$row[$primaryKey]] = $row;
                    }
                    
                    log_message('info', '[tableEdit] 成功获取原始记录数=' . count($originalRows));
                    
                    // 2. 批量更新旧记录为无效
                    $sqlUpdateOld = sprintf(
                        'UPDATE %s SET 操作记录="修改",操作来源="工作台",操作人员="%s",操作时间="%s",结束操作时间="%s",删除标识="1",有效标识="0" WHERE %s',
                        $dataTable,
                        $userWorkid,
                        date('Y-m-d H:i:s'),
                        date('Y-m-d H:i:s'),
                        $whereIn
                    );
                    log_message('debug', '[tableEdit] 批量更新旧记录SQL: ' . $sqlUpdateOld);
                    $this->common->sql_log('表级修改[1-旧]', $functionCode, sprintf('表名=`%s`,批量数=%d', $dataTable, count($validRows)));
                    $this->common->exec($sqlUpdateOld);
                    
                    // 3. 批量插入新记录
                    $insertFields = [];
                    $insertValuesList = [];
                    $firstRow = true;
                    
                    foreach ($validRows as $row) {
                        $pkValue = $row[$primaryKey];
                        if (!isset($originalRows[$pkValue])) {
                            log_message('warn', '[tableEdit] 找不到原始记录，主键=' . $pkValue);
                            continue;
                        }
                        
                        $originalRow = $originalRows[$pkValue];
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
                        
                        if ($firstRow) {
                            $insertFields = $fields;
                            $firstRow = false;
                        }
                        
                        $insertValuesList[] = '(' . implode(', ', $values) . ')';
                    }
                    
                    if (!empty($insertValuesList)) {
                        $sqlInsert = sprintf(
                            'INSERT INTO %s (%s) VALUES %s',
                            $dataTable,
                            implode(', ', $insertFields),
                            implode(', ', $insertValuesList)
                        );
                        log_message('debug', '[tableEdit] 批量插入新记录SQL: ' . $sqlInsert);
                        $this->common->sql_log('表级修改[1-新]', $functionCode, sprintf('表名=`%s`,批量数=%d', $dataTable, count($insertValuesList)));
                        $affected = $this->common->exec($sqlInsert);
                        log_message('info', '[tableEdit] 批量插入影响行数=' . $affected);
                        $num += $affected;
                    }
                    break;

                default:
                    log_message('error', '[tableEdit] 未知的数据模式: ' . $dataModel);
                    return $this->error('5001', sprintf('修改失败,数据模式[-%s-]错误', $dataModel));
            }

            log_message('info', '[tableEdit] 完成，共修改' . $num . '条记录');
            return $this->success([
                'success' => true,
                'message' => sprintf('表级修改提交成功,修改了 %d 条记录', $num),
                'updatedCount' => $num
            ]);
        } catch (\RuntimeException $e) {
            log_message('error', '[tableEdit] 运行时异常: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '[tableEdit] 表级修改提交失败: ' . $e->getMessage());
            log_message('error', '[tableEdit] 异常堆栈: ' . $e->getTraceAsString());
            return $this->error('5001', '表级修改提交失败: ' . $e->getMessage());
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
            [$context, $definition] = $this->contextService->buildWorkbenchContext($functionCode);
            $chartModule = $definition['chartModule'] ?? '';

            if (empty($chartModule)) {
                return $this->error('4001', '当前功能未配置图形模块');
            }

            $chartData = $this->chartService->getChartData($context, $chartModule);

            return $this->success([
                'charts' => $chartData
            ]);
        } catch (\RuntimeException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '获取图形数据失败: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return $this->error('5001', '获取图形数据失败');
        }
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
