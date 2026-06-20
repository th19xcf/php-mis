<?php

namespace App\Controllers;

use App\Constants\ApiCode;
use App\Controllers\Workbench\WorkbenchResponseTrait;
use App\Exceptions\AuthException;
use App\Exceptions\BusinessException;
use App\Exceptions\ValidationException;
use App\Libraries\AuthorizationService;
use App\Services\Workbench\ChartService;
use App\Services\Workbench\ChartDrillService;
use App\Services\Workbench\DrillService;
use App\Services\Workbench\EditService;
use App\Services\Workbench\PopupService;
use App\Services\Workbench\QueryService;
use App\Services\Workbench\ContextService;

/**
 * 工作台主控制器
 *
 * 负责页面/查询/调试/钻取/弹窗/图表/数据整理等编排型接口。
 * 导入相关接口迁出至 Workbench\WorkbenchImportController；
 * 字段配置与记录增删改迁出至 Workbench\WorkbenchEditController。
 */
class Workbench extends BaseApiController
{
    use WorkbenchResponseTrait;

    private AuthorizationService $authorizationService;
    private ChartService $chartService;
    private ChartDrillService $chartDrillService;
    private DrillService $drillService;
    private EditService $editService;
    private PopupService $popupService;
    private QueryService $queryService;
    private ContextService $contextService;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->authorizationService = new AuthorizationService();
        $this->chartService = new ChartService();
        $this->chartDrillService = new ChartDrillService();
        $this->drillService = new DrillService();
        $this->editService = new EditService();
        $this->popupService = new PopupService();
        $this->queryService = new QueryService();
        $this->contextService = new ContextService();
    }

    public function page(string $functionCode = '')
    {
        $start = hrtime(true);
        try {
            log_message('debug', '[Workbench::page] 开始处理，functionCode: ' . $functionCode);
            [$context, $definition] = $this->contextService->buildWorkbenchContext($functionCode);
            log_message('debug', '[Workbench::page] 上下文构建成功');

            $serverMs = (hrtime(true) - $start) / 1e6;
            log_message('debug', sprintf('[Workbench::page] 服务端处理完成: %.2fms', $serverMs));

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

            [$context] = $this->contextService->buildWorkbenchContext($functionCode);

            $total = 0;
            if ($fetchTotal) {
                $total = $this->queryService->queryTotalCount($context, $payload);
            }

            $records = $this->queryService->queryRecordsPaged($context, $payload, $current, $size, $offset);

            $serverMs = (hrtime(true) - $start) / 1e6;
            log_message('debug', sprintf('[Workbench::queryPaged] 服务端处理完成: %.2fms, total=%d, records=%d', $serverMs, $total, count($records)));

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
            $userPassword = $user['password'];

            // 仅允许本人缓存清理；系统维护身份可执行功能级/全量清理
            $canMaintain = ($userPassword === $userWorkId . $userWorkId);

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

            $this->contextService->clearCache($functionCode, $userWorkId, $companyId);
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
     * 获取弹窗数据
     */
    public function popupData(string $functionCode = '')
    {
        try {
            $request = service('request');
            $objectName = $request->getGet('objectName') ?? '';

            $data = $this->popupService->getPopupData($functionCode, $objectName);

            if (empty($data['popupGrid'])) {
                return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '未找到弹窗配置');
            }

            return $this->success($data);
        } catch (\Throwable $e) {
            log_message('error', '获取弹窗数据失败: ' . $e->getMessage());
            return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '获取弹窗数据失败');
        }
    }

    /**
     * 获取弹窗级联级别配置
     */
    public function popupLevels(string $functionCode = '')
    {
        try {
            $request = service('request');
            $objectName = $request->getGet('objectName') ?? '';

            $data = $this->popupService->getPopupLevels($functionCode, $objectName);

            if (empty($data['levels'])) {
                return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '未找到弹窗配置');
            }

            return $this->success($data);
        } catch (\Throwable $e) {
            log_message('error', '获取弹窗级别配置失败: ' . $e->getMessage());
            return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '获取弹窗级别配置失败');
        }
    }

    /**
     * 获取弹窗指定级别的数据（懒加载）
     */
    public function popupLevelData(string $functionCode = '')
    {
        try {
            $request = service('request');
            $objectName = $request->getGet('objectName') ?? '';
            $level = (int) ($request->getGet('level') ?? 1);
            $parentCode = $request->getGet('parentCode') ?? '';

            $data = $this->popupService->getPopupLevelData($functionCode, $objectName, $level, $parentCode);

            if (empty($data['items']) && $level === 1) {
                return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '未找到弹窗配置');
            }

            return $this->success($data);
        } catch (\Throwable $e) {
            log_message('error', '获取弹窗级别数据失败: ' . $e->getMessage());
            return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '获取弹窗级别数据失败');
        }
    }


    /**
     * 表级修改提交
     */
    /**
     * 表级批量修改（按行提交，按字段分组；单条走 UPDATE，多条走 CASE WHEN 批量更新）
     */
    public function tableEdit(string $functionCode = '')
    {
        try {
            $functionCode = trim($functionCode);
            if ($functionCode === '') {
                throw new ValidationException('功能编码不能为空');
            }

            $rows = $this->request->getJSON(true) ?? [];
            if (empty($rows)) {
                return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '没有要提交的修改数据');
            }

            $session = \Config\Services::session();
            $userWorkid = $session->get('user_workid') ?? 'system';

            $queryConfig = $this->loadQueryConfig($functionCode, '');
            if (!$queryConfig || ($queryConfig['dataTable'] ?? '') === '') {
                return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '修改失败：未找到数据表配置');
            }
            $dataTable = $queryConfig['dataTable'];
            $dataModel = $queryConfig['dataModel'] ?? '0';

            $primaryKey = $this->getPrimaryKey($functionCode, $queryConfig);
            if (empty($primaryKey)) {
                return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '修改失败：未找到主键字段');
            }

            $result = $this->editService->tableEditByModel(
                $dataTable, $dataModel, $primaryKey, $rows, $userWorkid, $functionCode
            );

            if (!$result['success']) {
                return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, $result['message']);
            }

            return $this->success([
                'success'      => true,
                'message'      => $result['message'],
                'updatedCount' => $result['count'],
            ]);
        } catch (AuthException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (ValidationException $e) {
            return $this->error(ApiCode::PARAM_ERROR, $e->getMessage());
        } catch (BusinessException $e) {
            return $this->error(ApiCode::BUSINESS_ERROR, $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '表级修改提交失败: ' . $e->getMessage());
            return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '表级修改提交失败: ' . $e->getMessage());
        }
    }


    /**
     * 获取图形数据
     */
    public function chart(string $functionCode = '')
    {
        try {
            [$context, $definition] = $this->contextService->buildWorkbenchContext($functionCode);
            $chartModule = $definition['chartModule'] ?? '';

            if (empty($chartModule)) {
                return $this->error(ApiCode::WORKBENCH_PARAM_REQUIRED, '当前功能未配置图形模块');
            }

            $chartData = $this->chartService->getChartData($context, $chartModule);

            return $this->success([
                'charts' => $chartData,
            ]);
        } catch (AuthException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (ValidationException $e) {
            return $this->error(ApiCode::PARAM_ERROR, $e->getMessage());
        } catch (BusinessException $e) {
            return $this->error(ApiCode::BUSINESS_ERROR, $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '获取图形数据失败: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '获取图形数据失败');
        }
    }

    /**
     * 图形钻取
     */
    public function chartDrill(string $functionCode = '')
    {
        try {
            $payload = $this->request->getJSON(true) ?? [];

            $drillLevel = isset($payload[0]['钻取级别']) ? (int) $payload[0]['钻取级别'] : 0;

            $charts = $this->chartDrillService->performChartDrill($functionCode, $payload);

            return $this->success([
                'charts'     => $charts,
                'drillLevel' => $drillLevel + 1,
                'message'    => '钻取成功',
            ]);
        } catch (AuthException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (ValidationException $e) {
            return $this->error(ApiCode::PARAM_ERROR, $e->getMessage());
        } catch (BusinessException $e) {
            return $this->error(ApiCode::BUSINESS_ERROR, $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '图形钻取失败: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return $this->error(ApiCode::WORKBENCH_CHART_DRILL_FAILED, '图形钻取失败: ' . $e->getMessage());
        }
    }

    /**
     * 退出图形钻取
     */
    public function chartDrillReset(string $functionCode = '')
    {
        try {
            $session = \Config\Services::session();
            $menuId = $session->get('menu_id') ?: $functionCode;

            $session->remove($menuId . '-chart_drill_arr');

            $sessionData = $_SESSION ?? [];
            foreach (array_keys($sessionData) as $key) {
                if (strpos((string) $key, (string) $menuId) === 0
                    && (strpos((string) $key, '-chart_drill_cond_str') !== false
                        || strpos((string) $key, '-chart_drill_title_str') !== false)) {
                    $session->remove($key);
                }
            }

            return $this->success([
                'message' => '已退出钻取',
            ]);
        } catch (\Throwable $e) {
            log_message('error', '重置图形钻取失败: ' . $e->getMessage());
            return $this->error(ApiCode::WORKBENCH_CHART_DRILL_RESET_FAILED, '重置图形钻取失败');
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

            $this->contextService->executeUpkeep($dataUpkeep);

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
}
