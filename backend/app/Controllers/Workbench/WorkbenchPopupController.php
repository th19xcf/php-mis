<?php

namespace App\Controllers\Workbench;

use App\Constants\ApiCode;
use App\Controllers\BaseApiController;
use App\Services\Workbench\PopupService;

/**
 * 工作台弹窗控制器
 *
 * 负责处理弹窗数据查询相关接口，
 * 包括弹窗数据获取、级联级别配置、级别数据懒加载。
 */
class WorkbenchPopupController extends BaseApiController
{
    private PopupService $popupService;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ) {
        parent::initController($request, $response, $logger);
        $this->popupService = new PopupService();
    }

    /**
     * 截断错误信息，避免把整条 SQL 全部抛到前端
     */
    private function shortError(\Throwable $e, int $maxLen = 200): string
    {
        $msg = trim($e->getMessage());
        if ($msg === '') {
            $msg = get_class($e);
        }
        if (mb_strlen($msg) > $maxLen) {
            $msg = mb_substr($msg, 0, $maxLen) . '...(已截断)';
        }
        return $msg;
    }

    /**
     * 获取弹窗数据
     */
    public function popupData(string $functionCode = '')
    {
        try {
            $objectName = $this->request->getGet('objectName') ?? '';

            $data = $this->popupService->getPopupData($functionCode, $objectName);

            if (empty($data['popupGrid'])) {
                return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '未找到弹窗配置');
            }

            return $this->success($data);
        } catch (\Throwable $e) {
            $short = $this->shortError($e);
            log_message('error', '获取弹窗数据失败: ' . $e->getMessage());
            return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '获取弹窗数据失败: ' . $short);
        }
    }

    /**
     * 获取弹窗级联级别配置
     */
    public function popupLevels(string $functionCode = '')
    {
        try {
            $objectName = $this->request->getGet('objectName') ?? '';

            $data = $this->popupService->getPopupLevels($functionCode, $objectName);

            if (empty($data['levels'])) {
                return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '未找到弹窗配置');
            }

            return $this->success($data);
        } catch (\Throwable $e) {
            $short = $this->shortError($e);
            log_message('error', '获取弹窗级别配置失败: ' . $e->getMessage());
            return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '获取弹窗级别配置失败: ' . $short);
        }
    }

    /**
     * 获取弹窗指定级别的数据（懒加载）
     */
    public function popupLevelData(string $functionCode = '')
    {
        try {
            $objectName = $this->request->getGet('objectName') ?? '';
            $level = (int) ($this->request->getGet('level') ?? 1);
            $parentCode = $this->request->getGet('parentCode') ?? '';

            $data = $this->popupService->getPopupLevelData($functionCode, $objectName, $level, $parentCode);

            if (empty($data['items']) && $level === 1) {
                return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '未找到弹窗配置');
            }

            return $this->success($data);
        } catch (\Throwable $e) {
            $short = $this->shortError($e);
            log_message('error', '获取弹窗级别数据失败: ' . $e->getMessage());
            return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '获取弹窗级别数据失败: ' . $short);
        }
    }
}
