<?php

namespace App\Controllers;

use App\Services\Oa\TodoService;
use App\Services\Oa\MeetingService;

/**
 * 待办事项 API
 *
 * 路由组：/todo
 *   GET  /todo/center       待办中心（合并任务待办 + 审批待办）
 *   GET  /todo/stats        统计卡计数（Header 角标用）
 *   POST /todo/create       手动新建待办
 *   POST /todo/update       修改待办
 *   POST /todo/complete     标记完成
 *   POST /todo/reassign     转办
 *   POST /todo/delete       批量删除（软删）
 *   GET  /todo/detail       待办详情
 *   GET  /todo/options      下拉选项
 *   GET  /todo/user-options 人员选择（负责人/转办）
 */
class TodoApi extends BaseApiController
{
    private TodoService $todoService;
    private MeetingService $meetingService;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->todoService = new TodoService();
        $this->meetingService = new MeetingService();
    }

    /**
     * 待办中心主数据
     * GET/POST /todo/center
     * 参数：status / sourceType / priority / keyword
     */
    public function center()
    {
        try {
            $params = $this->request->getGet() + ($this->request->getJSON(true) ?? []);
            $workId = $this->getUserWorkId();

            $result = $this->todoService->getCenterData($workId, $params);

            return $this->success($result);
        } catch (\Throwable $e) {
            log_message('error', '[TodoApi::center] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * 统计卡计数（Header 角标用）
     * GET /todo/stats
     */
    public function stats()
    {
        try {
            $workId = $this->getUserWorkId();
            $stats = $this->todoService->getStats($workId);

            return $this->success($stats);
        } catch (\Throwable $e) {
            log_message('error', '[TodoApi::stats] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * 手动新建待办
     * POST /todo/create
     */
    public function create()
    {
        try {
            $data = $this->getJsonInput();

            if ($error = $this->requireParams($data, ['待办标题', '负责人'])) {
                return $error;
            }

            $operator = $this->getUserWorkId();
            $guid = $this->todoService->createTodo($data, $operator);

            return $this->success(['guid' => $guid], '待办创建成功');
        } catch (\App\Exceptions\BusinessException $e) {
            return $this->businessError($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '[TodoApi::create] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * 修改待办
     * POST /todo/update
     * 参数：guid + 可修改字段
     */
    public function update()
    {
        try {
            $data = $this->getJsonInput();

            if ($error = $this->requireParam($data, 'guid')) {
                return $error;
            }

            $guid = (string) $data['guid'];
            $operator = $this->getUserWorkId();
            $affected = $this->todoService->updateTodo($guid, $data, $operator);

            if ($affected === -1) {
                return $this->notFound('待办不存在');
            }

            return $this->success(['updated' => true], '修改成功');
        } catch (\App\Exceptions\BusinessException $e) {
            return $this->businessError($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '[TodoApi::update] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * 标记完成
     * POST /todo/complete
     * 参数：guid / 完成说明
     */
    public function complete()
    {
        try {
            $data = $this->getJsonInput();

            if ($error = $this->requireParam($data, 'guid')) {
                return $error;
            }

            $guid = (string) $data['guid'];
            $note = (string) ($data['完成说明'] ?? '');
            $operator = $this->getUserWorkId();
            $affected = $this->todoService->completeTodo($guid, $note, $operator);

            if ($affected === -1) {
                return $this->notFound('待办不存在');
            }
            if ($affected === -2) {
                return $this->businessError('该待办已完成');
            }

            return $this->success(['completed' => true], '已完成');
        } catch (\App\Exceptions\BusinessException $e) {
            return $this->businessError($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '[TodoApi::complete] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * 转办
     * POST /todo/reassign
     * 参数：guid / 新负责人
     */
    public function reassign()
    {
        try {
            $data = $this->getJsonInput();

            if ($error = $this->requireParams($data, ['guid', '新负责人'])) {
                return $error;
            }

            $guid = (string) $data['guid'];
            $newAssignee = (string) $data['新负责人'];
            $operator = $this->getUserWorkId();
            $affected = $this->todoService->reassign($guid, $newAssignee, $operator);

            if ($affected === -1) {
                return $this->notFound('待办不存在');
            }

            return $this->success(['reassigned' => true], '转办成功');
        } catch (\App\Exceptions\BusinessException $e) {
            return $this->businessError($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '[TodoApi::reassign] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * 批量删除（软删）
     * POST /todo/delete
     * 参数：guids (array)
     */
    public function delete()
    {
        try {
            $data = $this->getJsonInput();

            $guids = $data['guids'] ?? [];
            if (!is_array($guids) || count($guids) === 0) {
                return $this->paramError('guids 不能为空');
            }

            $operator = $this->getUserWorkId();
            $deleted = $this->todoService->deleteTodos($guids, $operator);

            return $this->success(['deleted' => $deleted], "已删除 {$deleted} 条");
        } catch (\App\Exceptions\BusinessException $e) {
            return $this->businessError($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '[TodoApi::delete] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * 待办详情
     * GET /todo/detail?guid=xxx
     */
    public function detail()
    {
        try {
            $guid = (string) ($this->request->getGet('guid') ?? '');

            if ($guid === '') {
                $data = $this->getJsonInput();
                $guid = (string) ($data['guid'] ?? '');
            }

            if ($guid === '') {
                return $this->paramError('guid 不能为空');
            }

            $detail = $this->todoService->getDetail($guid);

            if ($detail === null) {
                return $this->notFound('待办不存在');
            }

            return $this->success($detail);
        } catch (\Throwable $e) {
            log_message('error', '[TodoApi::detail] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * 下拉选项
     * GET /todo/options
     */
    public function options()
    {
        try {
            $options = $this->todoService->getOptions();

            return $this->success($options);
        } catch (\Throwable $e) {
            log_message('error', '[TodoApi::options] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * 人员选择（负责人/转办）
     * GET /todo/user-options?keyword=xxx&deptCode=xxx
     * 复用 MeetingService::getUserOptions，查 def_user 返回 [{工号, 姓名, 员工部门编码, 员工部门全称}]
     */
    public function userOptions()
    {
        try {
            $keyword = (string) ($this->request->getGet('keyword') ?? '');
            $deptCode = (string) ($this->request->getGet('deptCode') ?? '');
            $users = $this->meetingService->getUserOptions($keyword, $deptCode);

            return $this->success($users);
        } catch (\Throwable $e) {
            log_message('error', '[TodoApi::userOptions] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * 部门树
     * GET /todo/dept-tree
     * 复用 MeetingService::getDeptTree，返回 def_dept 树形结构
     */
    public function deptTree()
    {
        try {
            $tree = $this->meetingService->getDeptTree();
            return $this->success($tree);
        } catch (\Throwable $e) {
            log_message('error', '[TodoApi::deptTree] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }
}
