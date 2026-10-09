<?php

namespace App\Controllers;

use App\Services\Oa\TodoService;

/**
 * 首页工作台 API
 *
 * 路由组：/dashboard
 *   GET  /dashboard/widgets  获取当前用户的卡片配置
 *   POST /dashboard/widgets  保存卡片显示/隐藏配置
 *   GET  /dashboard/todo     获取待办卡片数据
 */
class DashboardApi extends BaseApiController
{
    private TodoService $todoService;

    /** 系统内置卡片注册表 */
    private const WIDGET_REGISTRY = [
        ['卡片编码' => 'todo',           '卡片名称' => '我的待办',     '显示顺序' => 1, '显示标识' => '1'],
        ['卡片编码' => 'quick_entry',    '卡片名称' => '快捷入口',     '显示顺序' => 2, '显示标识' => '1'],
        ['卡片编码' => 'contract_stats',  '卡片名称' => '合同统计',     '显示顺序' => 3, '显示标识' => '0'],
        ['卡片编码' => 'recruitment_funnel', '卡片名称' => '招聘漏斗', '显示顺序' => 4, '显示标识' => '0'],
    ];

    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->todoService = new TodoService();
    }

    /**
     * 获取当前用户的卡片配置
     * GET /dashboard/widgets
     */
    public function widgets()
    {
        try {
            $workId = $this->getUserWorkId();

            $sql = sprintf(
                'SELECT `卡片编码`,`卡片名称`,`显示顺序`,`显示标识`,`配置参数`
                FROM `def_dashboard_widget`
                WHERE `工号`=%s
                ORDER BY `显示顺序` ASC',
                $this->model->quote($workId)
            );
            $rows = $this->model->select($sql)->getResultArray();

            if (empty($rows)) {
                return $this->success(self::WIDGET_REGISTRY);
            }

            $result = array_map(function ($row) {
                return [
                    '卡片编码' => $row['卡片编码'],
                    '卡片名称' => $row['卡片名称'],
                    '显示顺序' => (int) $row['显示顺序'],
                    '显示标识' => $row['显示标识'],
                    '配置参数' => $row['配置参数'] ?? null,
                ];
            }, $rows);

            return $this->success($result);
        } catch (\Throwable $e) {
            log_message('error', '[DashboardApi::widgets] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * 保存卡片显示/隐藏配置
     * POST /dashboard/widgets
     * Body: { widgets: [{ 卡片编码, 显示标识 }, ...] }
     */
    public function saveWidgets()
    {
        try {
            $data = $this->getJsonInput();
            $widgets = $data['widgets'] ?? [];
            if (empty($widgets)) {
                return $this->paramError('widgets不能为空');
            }

            $workId = $this->getUserWorkId();
            $now = date('Y-m-d H:i:s');

            $registry = array_column(self::WIDGET_REGISTRY, null, '卡片编码');

            foreach ($widgets as $widget) {
                $code = $widget['卡片编码'] ?? '';
                $visible = $widget['显示标识'] ?? '1';
                if (!isset($registry[$code])) {
                    continue;
                }

                $name = $this->model->quote($registry[$code]['卡片名称']);
                $sortOrder = (int) ($widget['显示顺序'] ?? $registry[$code]['显示顺序']);

                $sql = sprintf(
                    'INSERT INTO `def_dashboard_widget` (`工号`,`卡片编码`,`卡片名称`,`显示顺序`,`显示标识`,`创建时间`,`更新时间`)
                    VALUES (%s, %s, %s, %d, %s, %s, %s)
                    ON DUPLICATE KEY UPDATE `显示标识`=VALUES(`显示标识`), `显示顺序`=VALUES(`显示顺序`), `更新时间`=VALUES(`更新时间`)',
                    $this->model->quote($workId),
                    $this->model->quote($code),
                    $name,
                    $sortOrder,
                    $this->model->quote($visible),
                    $this->model->quote($now),
                    $this->model->quote($now)
                );
                $this->model->exec($sql);
            }

            return $this->success(null, '保存成功');
        } catch (\Throwable $e) {
            log_message('error', '[DashboardApi::saveWidgets] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * 获取待办卡片数据（未完成待办列表，最多 8 条）
     * GET /dashboard/todo
     */
    public function todo()
    {
        try {
            $workId = $this->getUserWorkId();

            $result = $this->todoService->getCenterData($workId, ['status' => '待处理']);

            $list = $result['list'] ?? [];
            $list = array_slice($list, 0, 8);

            return $this->success([
                'list' => $list,
                'stats' => $result['stats'] ?? ['all' => 0, 'pending' => 0, 'doing' => 0, 'done' => 0, 'overdue' => 0],
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[DashboardApi::todo] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }
}
