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
     * 支持多级钻取（无状态实现）：
     *  - 请求 payload[0].钻取级别 = 当前级别（0=初始；1=第一级钻取中；2=第二级钻取中 ...）
     *  - 请求 payload[3] = drillContext { condStr, titleStr }（前端持有并回传，替代 session 累加）
     *  - 响应中 drillLevel = 当前级别 + 1，作为前端新的钻取级别
     *  - 响应中 drillContext = 叠加本次钻取条件后的新状态，前端持有用于下次请求回传
     *  - 关闭图形 / 退出钻取由前端清空本地状态即可，无需后端接口
     */
    public function chartDrill(string $functionCode = '')
    {
        try {
            $payload = $this->request->getJSON(true) ?? [];

            $drillLevel = isset($payload[0]['钻取级别']) ? (int) $payload[0]['钻取级别'] : 0;

            $result = $this->chartDrillService->performChartDrill($functionCode, $payload);

            return $this->success([
                'charts'       => $result['charts'],
                'drillLevel'   => $drillLevel + 1,
                'drillContext' => $result['drillContext'],
                'message'      => '钻取成功',
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
}
