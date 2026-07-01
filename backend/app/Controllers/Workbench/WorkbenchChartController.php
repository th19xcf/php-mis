<?php

namespace App\Controllers\Workbench;

use App\Constants\ApiCode;
use App\Controllers\BaseApiController;
use App\Exceptions\AuthException;
use App\Exceptions\BusinessException;
use App\Exceptions\ValidationException;
use App\Services\Workbench\ChartService;
use App\Services\Workbench\ChartDrillService;
use App\Services\Workbench\ContextService;

/**
 * 工作台图表控制器
 *
 * 负责处理图表数据查询与钻取相关接口，
 * 包括图表数据获取、图形钻取、退出钻取。
 */
class WorkbenchChartController extends BaseApiController
{
    use WorkbenchResponseTrait;

    private ChartService $chartService;
    private ChartDrillService $chartDrillService;
    private ContextService $contextService;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ) {
        parent::initController($request, $response, $logger);
        $this->chartService = new ChartService();
        $this->chartDrillService = new ChartDrillService();
        $this->contextService = new ContextService();
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
     *
     * 支持多级钻取：
     *  - 请求 payload[0].钻取级别 = 当前级别（0=初始；1=第一级钻取中；2=第二级钻取中 ...）
     *  - ChartDrillService 通过 session 累加各级钻取条件
     *  - 响应中 drillLevel = 当前级别 + 1，作为前端新的钻取级别
     *  - 关闭图形 / 退出钻取 时需调用 chartDrillReset 清空 session
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
}
