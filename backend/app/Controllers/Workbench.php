<?php

namespace App\Controllers;

use App\Constants\ApiCode;
use App\Exceptions\AuthException;
use App\Exceptions\BusinessException;
use App\Exceptions\ValidationException;
use App\Services\Workbench\ChartService;
use App\Services\Workbench\DrillService;
use App\Services\Workbench\EditService;
use App\Services\Workbench\ExportService;
use App\Services\Workbench\QueryService;
use App\Services\Workbench\ContextService;

/**
 * 工作台主控制器
 *
 * 负责页面/查询/调试/钻取/数据整理/导出等核心接口。
 *
 * 子模块已按职责拆分至独立控制器：
 *  - 编辑相关（字段配置、增删改、表级编辑）→ Workbench\WorkbenchEditController
 *  - 导入相关 → Workbench\WorkbenchImportController
 *  - 图表相关 → Workbench\WorkbenchChartController
 *  - 弹窗相关 → Workbench\WorkbenchPopupController
 */
class Workbench extends BaseApiController
{
    private ChartService $chartService;
    private DrillService $drillService;
    private EditService $editService;
    private ExportService $exportService;
    private QueryService $queryService;
    private ContextService $contextService;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->chartService = new ChartService();
        $this->drillService = new DrillService();
        $this->editService = new EditService();
        $this->exportService = new ExportService();
        $this->queryService = new QueryService();
        $this->contextService = new ContextService();
    }

    public function page(string $functionCode = '')
    {
        $start = hrtime(true);
        try {
            [$context, $definition, $trace] = $this->contextService->buildWorkbenchContext($functionCode);
            $tContextDone = hrtime(true);

            $serverMs = (hrtime(true) - $start) / 1e6;

            $steps = [
                'buildContext' => $tContextDone,
            ];
            $logMsg = $this->buildPerformanceTable('[Page]', '成功',
                'functionCode=' . $functionCode,
                $steps, $start);
            log_message('info', $logMsg);

            $trace['total'] = round($serverMs, 2);
            $this->setServerTrace($trace);

            return $this->success([
                'meta' => $definition,
            ], 'Success', $serverMs);
        } catch (AuthException $e) {
            log_message('error', '[Workbench::page] RuntimeException: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (ValidationException $e) {
            return $this->error(ApiCode::PARAM_ERROR, $e->getMessage());
        } catch (BusinessException $e) {
            return $this->error(ApiCode::BUSINESS_ERROR, $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '[Workbench::page] Throwable: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
            return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
        }
    }

    /**
     * 合并接口：一次返回页面元数据 + 首屏分页数据
     *
     * 性能优化：替代原来前端并行调用 /page 和 /queryPaged 两个接口，
     * 在单线程 PHP 服务器下会串行排队，多等一次框架启动（~450ms）
     * + 一次上下文构建（~120ms）。合并后只走一次框架启动和上下文构建，
     * 预计节省约 500~600ms。
     */
    public function pageWithData(string $functionCode = '')
    {
        $start = hrtime(true);
        try {
            $payload = $this->request->getJSON(true) ?? [];

            $current = max(1, (int) ($payload['current'] ?? 1));
            $size = max(1, min(5000, (int) ($payload['size'] ?? 1000)));
            $offset = max(0, (int) ($payload['offset'] ?? (($current - 1) * $size)));
            $fetchTotal = filter_var($payload['fetchTotal'] ?? true, FILTER_VALIDATE_BOOLEAN);

            [$context, $definition, $trace] = $this->contextService->buildWorkbenchContext($functionCode);
            $tContextDone = hrtime(true);

            $total = 0;
            $tCountDone = $tContextDone;
            if ($fetchTotal) {
                $tCount = hrtime(true);
                $total = $this->queryService->queryTotalCount($context, $payload);
                $tCountDone = hrtime(true);
                $trace['queryTotal'] = round(($tCountDone - $tCount) / 1e6, 2);
            }

            $tQuery = hrtime(true);
            $records = $this->queryService->queryRecordsPaged($context, $payload, $current, $size, $offset);
            $tQueryDone = hrtime(true);
            $trace['queryRecords'] = round(($tQueryDone - $tQuery) / 1e6, 2);

            $serverMs = (hrtime(true) - $start) / 1e6;

            $steps = [
                'buildContext' => $tContextDone,
            ];
            if ($fetchTotal) {
                $steps['queryTotal'] = $tCountDone;
            }
            $steps['queryRecords'] = $tQueryDone;

            $logMsg = $this->buildPerformanceTable('[PageWithData]', '成功',
                'functionCode=' . $functionCode . ' total=' . $total . ' records=' . count($records),
                $steps, $start);
            log_message('info', $logMsg);

            $trace['total'] = round($serverMs, 2);
            $this->setServerTrace($trace);

            return $this->success([
                'meta'    => $definition,
                'records' => $records,
                'current' => $current,
                'size'    => $size,
                'offset'  => $offset,
                'total'   => $total,
                'hasMore' => count($records) === $size,
            ], 'Success', $serverMs);
        } catch (AuthException $e) {
            log_message('error', '[Workbench::pageWithData] AuthException: ' . $e->getMessage());
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (ValidationException $e) {
            return $this->error(ApiCode::PARAM_ERROR, $e->getMessage());
        } catch (BusinessException $e) {
            return $this->error(ApiCode::BUSINESS_ERROR, $e->getMessage());
        } catch (\Throwable $e) {
            $detail = $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
            log_message('error', '[Workbench::pageWithData] Throwable: ' . $detail);
            return $this->error(ApiCode::WORKBENCH_PAGED_QUERY_FAILED, '页面初始化失败: ' . $detail);
        }
    }

    public function query(string $functionCode = '')
    {
        try {
            $payload = $this->request->getJSON(true) ?? [];

            [$context] = $this->contextService->buildWorkbenchContext($functionCode);
            $records = $this->queryService->queryRecords($context, $payload);

            return $this->success($records);
        } catch (AuthException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (ValidationException $e) {
            return $this->error(ApiCode::PARAM_ERROR, $e->getMessage());
        } catch (BusinessException $e) {
            return $this->error(ApiCode::BUSINESS_ERROR, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->error(ApiCode::WORKBENCH_QUERY_FAILED, '工作台查询失败');
        }
    }

    /**
     * 分页查询工作台数据
     */
    public function queryPaged(string $functionCode = '')
    {
        $start = hrtime(true);
        try {
            $payload = $this->request->getJSON(true) ?? [];

            $current = max(1, (int) ($payload['current'] ?? 1));
            $size = max(1, min(5000, (int) ($payload['size'] ?? 1000)));
            $offset = max(0, (int) ($payload['offset'] ?? (($current - 1) * $size)));
            $fetchTotal = filter_var($payload['fetchTotal'] ?? true, FILTER_VALIDATE_BOOLEAN);

            [$context, , $trace] = $this->contextService->buildWorkbenchContext($functionCode);
            $tContextDone = hrtime(true);

            $total = 0;
            $tCountDone = $tContextDone;
            if ($fetchTotal) {
                $tCount = hrtime(true);
                $total = $this->queryService->queryTotalCount($context, $payload);
                $tCountDone = hrtime(true);
                $trace['queryTotal'] = round(($tCountDone - $tCount) / 1e6, 2);
            }

            $tQuery = hrtime(true);
            $records = $this->queryService->queryRecordsPaged($context, $payload, $current, $size, $offset);
            $tQueryDone = hrtime(true);
            $trace['queryRecords'] = round(($tQueryDone - $tQuery) / 1e6, 2);

            $serverMs = (hrtime(true) - $start) / 1e6;

            $steps = [
                'buildContext' => $tContextDone,
            ];
            if ($fetchTotal) {
                $steps['queryTotal'] = $tCountDone;
            }
            $steps['queryRecords'] = $tQueryDone;

            $logMsg = $this->buildPerformanceTable('[QueryPaged]', '成功',
                'functionCode=' . $functionCode . ' total=' . $total . ' records=' . count($records),
                $steps, $start);
            log_message('info', $logMsg);

            log_message('debug', sprintf('[Workbench::queryPaged] 服务端处理完成: %.2fms, total=%d, records=%d', $serverMs, $total, count($records)));

            $trace['total'] = round($serverMs, 2);
            $this->setServerTrace($trace);

            return $this->success([
                'records'  => $records,
                'current'  => $current,
                'size'     => $size,
                'offset'   => $offset,
                'total'    => $total,
                'hasMore'  => count($records) === $size,
            ], 'Success', $serverMs);
        } catch (AuthException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (ValidationException $e) {
            return $this->error(ApiCode::PARAM_ERROR, $e->getMessage());
        } catch (BusinessException $e) {
            return $this->error(ApiCode::BUSINESS_ERROR, $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '分页查询失败: ' . $e->getMessage());
            return $this->error(ApiCode::WORKBENCH_PAGED_QUERY_FAILED, '分页查询失败: ' . $e->getMessage());
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

            $fetchAll = filter_var($payload['all'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $current = max(1, (int) ($payload['current'] ?? 1));
            $size = max(1, min(200, (int) ($payload['size'] ?? 20)));

            if ($fetchAll) {
                $current = 1;
            }

            $debug = $this->queryService->buildDebugQuery(
                $context, $payload, $columns, $fetchAll, $current, $size
            );

            $chartSql = [];
            $chartModule = $definition['chartModule'] ?? '';
            if (!empty($chartModule)) {
                $chartSql = $this->chartService->buildChartQueriesForDebug($chartModule);
            }

            // 获取每个角色编码对应的部门全称赋权（用于调试输出）
            $roleDeptNameAuthzList = $this->getAuthorizationService()->getRoleDeptNameAuthzList(
                $functionCode,
                $userAuth['roleCodesRaw']
            );

            return $this->success([
                'functionCode'  => $functionCode,
                'queryTable'    => $debug['queryTable'],
                'queryWhere'    => $debug['queryWhere'],
                'queryGroup'    => $debug['queryGroup'],
                'queryOrder'    => $debug['queryOrder'],
                'mode'          => $debug['mode'],
                'selectParts'   => $debug['selectParts'],
                'whereParts'    => $debug['whereParts'],
                'countSql'      => $debug['countSql'],
                'querySql'      => $debug['querySql'],
                'chartModule'   => $chartModule,
                'chartSql'      => $chartSql,
                'upkeepModule'  => (string) ($queryConfig['upkeepModule'] ?? ''),
                'upkeepSql'     => (string) ($queryConfig['upkeepModule'] ?? '') !== ''
                    ? sprintf('call %s', $queryConfig['upkeepModule'])
                    : '',
                'importModule'  => (string) ($queryConfig['importModule'] ?? ''),
                'commentModule' => (string) ($queryConfig['commentModule'] ?? ''),
                'userAuth'      => [
                    'companyId'      => $userAuth['companyId'],
                    'userWorkId'     => $userAuth['userWorkId'],
                    'roleCodes'      => $userAuth['roleCodes'],
                    'locationAuth'   => $userAuth['locationAuth'],
                    'deptCodeAuth'   => $userAuth['deptCodeAuth'],
                    'deptNameAuth'   => $userAuth['deptNameAuth'],
                    'debugAuth'      => $userAuth['debugAuth'],
                ],
                // 每个角色编码对应的部门全称赋权（用于调试输出）
                'roleDeptNameAuthzList' => $roleDeptNameAuthzList,
                'functionAuth'  => [
                    'module'             => $functionAuth['module'],
                    'params'             => $functionAuth['params'],
                    'deptAuthCond'       => $functionAuth['deptAuthCond'],
                    'locationAuthCond'   => $functionAuth['locationAuthCond'],
                ],
                'columns'       => array_map(function ($col) {
                    return [
                        '列名'   => $col['列名'] ?? '',
                        '查询名' => $col['查询名'] ?? '',
                        '字段名' => $col['字段名'] ?? '',
                    ];
                }, $columns),
            ]);
        } catch (AuthException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (ValidationException $e) {
            return $this->error(ApiCode::PARAM_ERROR, $e->getMessage());
        } catch (BusinessException $e) {
            return $this->error(ApiCode::BUSINESS_ERROR, $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '获取调试信息失败: ' . $e->getMessage());
            log_message('error', '错误堆栈: ' . $e->getTraceAsString());
            return $this->error(ApiCode::WORKBENCH_PAGED_QUERY_FAILED, '获取调试信息失败: ' . $e->getMessage());
        }
    }

    public function drill(string $functionCode = '')
    {
        try {
            $payload = $this->request->getJSON(true) ?? [];

            [$context] = $this->contextService->buildWorkbenchContext($functionCode);

            $drillModule = $context['query']['drillModule'] ?? '';
            $queryModule = $context['query']['queryModule'] ?? '';

            $debugInfo = [
                'functionCode'  => $functionCode,
                'queryModule'   => $queryModule,
                'drillModule'   => $drillModule,
                'queryConfig'   => $context['query'],
                'userAuthCount' => count($context['user'] ?? []),
            ];

            if (empty($drillModule)) {
                $drillModule = $queryModule;
                $debugInfo['drillModuleFallback'] = 'used queryModule as drillModule';
            }

            $drillOptions = $this->drillService->getDrillOptions($context, $payload, $drillModule);

            return $this->success([
                'options' => $drillOptions,
                'debug'   => $debugInfo,
            ]);
        } catch (AuthException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (ValidationException $e) {
            return $this->error(ApiCode::PARAM_ERROR, $e->getMessage());
        } catch (BusinessException $e) {
            return $this->error(ApiCode::BUSINESS_ERROR, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->error(ApiCode::WORKBENCH_PAGED_QUERY_FAILED, '工作台钻取失败: ' . $e->getMessage());
        }
    }

    /**
     * 清除工作台上下文缓存
     *
     * 供管理员/维护人员在调整权限、字段、查询配置后手动刷新缓存。
     */
    public function clearContextCache(string $functionCode = '')
    {
        try {
            $functionCode = trim($functionCode);
            $payload = $this->request->getJSON(true) ?? [];
            $scope = (string) ($payload['scope'] ?? 'self');

            $user = $this->userContext->requireLogin();
            $companyId = $user['companyId'];
            $userWorkId = $user['workId'];

            $canMaintain = $user['isSuperAdmin'];

            if ($scope === 'all') {
                if (!$canMaintain) {
                    return $this->error(ApiCode::AUTH_UNAUTHORIZED, '无权执行全量缓存清理');
                }
                $this->contextService->clearCache();
                return $this->success(['cleared' => true, 'scope' => 'all']);
            }

            if ($functionCode === '') {
                return $this->error(ApiCode::WORKBENCH_PARAM_REQUIRED, '功能编码不能为空');
            }

            if ($scope === 'function') {
                if (!$canMaintain) {
                    return $this->error(ApiCode::AUTH_UNAUTHORIZED, '无权执行功能级缓存清理');
                }
                $this->contextService->clearCacheByFunctionCode($functionCode);
                return $this->success([
                    'cleared' => true,
                    'scope' => 'function',
                    'functionCode' => $functionCode,
                ]);
            }

            $this->contextService->clearCache($functionCode, $user['roleAuthz'], $companyId);
            return $this->success([
                'cleared' => true,
                'scope' => 'self',
                'functionCode' => $functionCode,
            ]);
        } catch (AuthException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (ValidationException $e) {
            return $this->error(ApiCode::PARAM_ERROR, $e->getMessage());
        } catch (BusinessException $e) {
            return $this->error(ApiCode::BUSINESS_ERROR, $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '清除工作台上下文缓存失败: ' . $e->getMessage());
            return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '清除工作台上下文缓存失败');
        }
    }

    /**
     * 执行数据整理
     */
    public function upkeep(string $functionCode = '')
    {
        try {
            [$context] = $this->contextService->buildWorkbenchContext($functionCode);

            $queryConfig = $context['query'];
            $dataUpkeep = $queryConfig['upkeepModule'] ?? '';

            if (empty($dataUpkeep)) {
                return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '未配置数据整理模块');
            }

            $model = new \App\Models\Mcommon();
            $model->select(sprintf('call %s', $dataUpkeep));

            return $this->success([
                'success' => true,
                'message' => '数据整理执行成功',
            ]);
        } catch (AuthException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (ValidationException $e) {
            return $this->error(ApiCode::PARAM_ERROR, $e->getMessage());
        } catch (BusinessException $e) {
            return $this->error(ApiCode::BUSINESS_ERROR, $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '执行数据整理失败: ' . $e->getMessage());
            return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '执行数据整理失败: ' . $e->getMessage());
        }
    }

    /**
     * 数据导出
     *
     * @param string $functionCode 功能编码
     * @return \CodeIgniter\HTTP\JSONResponse|\CodeIgniter\HTTP\ResponseInterface
     */
    public function export(string $functionCode = '')
    {
        try {
            $payload = $this->request->getJSON(true) ?? [];

            $format = strtolower(trim($payload['format'] ?? 'xlsx'));
            if (!in_array($format, ['xlsx', 'csv'])) {
                throw new ValidationException('不支持的导出格式，仅支持 xlsx 和 csv');
            }

            $allData = filter_var($payload['allData'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $selectedColumns = $payload['columns'] ?? [];

            [$context, $definition] = $this->contextService->buildWorkbenchContext($functionCode);

            $queryConfig = $context['query'];
            $columns = $context['columns'];

            if ($queryConfig['mode'] === '存储过程') {
                throw new BusinessException('存储过程模式暂不支持导出');
            }

            if (!empty($selectedColumns)) {
                $columns = array_filter($columns, function ($col) use ($selectedColumns) {
                    $colName = $col['列名'] ?? $col['字段名'] ?? '';
                    return in_array($colName, $selectedColumns);
                });
            }

            $columns = array_values($columns);

            if (empty($columns)) {
                throw new ValidationException('没有可导出的列');
            }

            // 分批流式导出：先查询总数，再分批拉取数据写入文件，
            // 避免一次性加载全部记录到内存导致 OOM。
            // 之前 size=50000 + all=true 一次性取全部行，5万行即可能占用 GB 级内存。
            $total = $this->queryService->queryTotalCount($context, $payload);

            if ($total === 0) {
                throw new BusinessException('没有数据可导出');
            }

            log_message('info', sprintf('[Workbench::export] 开始流式导出: functionCode=%s, format=%s, total=%d', $functionCode, $format, $total));

            // 分批拉取数据的回调，每批 1000 行
            $fetchRecords = function (int $offset, int $size) use ($context, $payload) {
                return $this->queryService->queryRecordsPaged($context, $payload, 1, $size, $offset);
            };

            if ($format === 'csv') {
                $filePath = $this->exportService->exportToCsvBatched($columns, $fetchRecords, 1000);
            } else {
                $filePath = $this->exportService->exportToExcelBatched($columns, $fetchRecords, $functionCode, 1000);
            }

            $filename = basename($filePath);

            log_message('info', sprintf('[Workbench::export] 导出成功: functionCode=%s, format=%s, total=%d', $functionCode, $format, $total));

            $this->outputRawFile($filePath, $filename);
            exit;
        } catch (AuthException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (ValidationException $e) {
            return $this->error(ApiCode::PARAM_ERROR, $e->getMessage());
        } catch (BusinessException $e) {
            return $this->error(ApiCode::BUSINESS_ERROR, $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '导出失败: ' . $e->getMessage());
            return $this->error(ApiCode::WORKBENCH_QUERY_FAILED, '导出失败: ' . $e->getMessage());
        }
    }

    private function outputRawFile(string $filePath, string $filename): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeTypes = [
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls'  => 'application/vnd.ms-excel',
            'csv'  => 'text/csv; charset=utf-8',
        ];
        $mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';

        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('Content-Transfer-Encoding: binary');
        header('Content-Length: ' . filesize($filePath));
        header('Pragma: no-cache');
        header('Expires: 0');

        readfile($filePath);
    }

    /**
     * 获取导出状态（异步导出时使用）
     */
    public function exportStatus(string $taskId = '')
    {
        try {
            $taskId = trim($taskId);
            if (empty($taskId)) {
                throw new ValidationException('任务ID不能为空');
            }

            $session = \Config\Services::session();
            $exportTasks = $session->get('export_tasks') ?? [];

            if (!isset($exportTasks[$taskId])) {
                throw new BusinessException('任务不存在');
            }

            $task = $exportTasks[$taskId];

            return $this->success([
                'taskId' => $taskId,
                'status' => $task['status'],
                'progress' => $task['progress'] ?? 0,
                'message' => $task['message'] ?? '',
                'filePath' => $task['filePath'] ?? '',
                'createdAt' => $task['createdAt'] ?? '',
            ]);
        } catch (ValidationException $e) {
            return $this->error(ApiCode::PARAM_ERROR, $e->getMessage());
        } catch (BusinessException $e) {
            return $this->error(ApiCode::BUSINESS_ERROR, $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '获取导出状态失败: ' . $e->getMessage());
            return $this->error(ApiCode::WORKBENCH_QUERY_FAILED, '获取导出状态失败');
        }
    }
}
