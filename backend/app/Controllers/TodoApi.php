<?php

namespace App\Controllers;

use App\Services\Oa\TodoService;
use App\Services\Oa\MeetingService;
use App\Services\Oa\MessageService;

/**
 * 待办事项 API
 *
 * 路由组：/todo
 *   GET  /todo/center       待办中心（合并任务待办 + 审批待办）
 *   GET  /todo/stats        统计卡计数（Header 角标用）
 *   POST /todo/create       手动新建待办
 *   POST /todo/update       修改待办
 *   POST /todo/complete     标记完成
 *   POST /todo/start        开始（待处理→进行中）
 *   POST /todo/cancel       取消
 *   POST /todo/reopen       重新打开
 *   POST /todo/urge         催办
 *   POST /todo/reassign     转办
 *   POST /todo/delete       批量删除（软删）
 *   POST /todo/toggle-pin   置顶/取消置顶
 *   GET  /todo/detail       待办详情
 *   GET  /todo/subtasks     子任务列表
 *   GET  /todo/comments     评论列表
 *   POST /todo/comment      添加评论
 *   POST /todo/upload       上传附件
 *   GET  /todo/download     下载附件
 *   GET  /todo/options      下拉选项
 *   GET  /todo/user-options 人员选择（负责人/转办）
 *   GET  /todo/dept-tree    部门树
 *   GET  /todo/logs         操作流水
 *   GET  /todo/messages       我的站内消息
 *   GET  /todo/messages-count 未读消息数
 *   POST /todo/messages-read  标记已读（批量/全部）
 *   POST /todo/remind-cron    定时提醒扫描（计划任务调用，token 保护）
 */
class TodoApi extends BaseApiController
{
    private TodoService $todoService;
    private MeetingService $meetingService;
    private MessageService $messageService;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->todoService = new TodoService();
        $this->meetingService = new MeetingService();
        $this->messageService = new MessageService();
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
     * 开始待办（待处理 → 进行中）
     * POST /todo/start
     */
    public function start()
    {
        try {
            $data = $this->getJsonInput();
            if ($error = $this->requireParam($data, 'guid')) {
                return $error;
            }
            $affected = $this->todoService->startTodo((string) $data['guid'], $this->getUserWorkId());
            if ($affected === -1) {
                return $this->notFound('待办不存在');
            }
            if ($affected === -3) {
                return $this->businessError('仅待处理状态可开始');
            }
            return $this->success(['started' => true], '已开始');
        } catch (\App\Exceptions\BusinessException $e) {
            return $this->businessError($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '[TodoApi::start] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * 取消待办
     * POST /todo/cancel
     */
    public function cancel()
    {
        try {
            $data = $this->getJsonInput();
            if ($error = $this->requireParam($data, 'guid')) {
                return $error;
            }
            $reason = (string) ($data['取消原因'] ?? '');
            $affected = $this->todoService->cancelTodo((string) $data['guid'], $reason, $this->getUserWorkId());
            if ($affected === -1) {
                return $this->notFound('待办不存在');
            }
            if ($affected === -3) {
                return $this->businessError('已结束的待办不能取消');
            }
            return $this->success(['cancelled' => true], '已取消');
        } catch (\App\Exceptions\BusinessException $e) {
            return $this->businessError($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '[TodoApi::cancel] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * 重新打开（已完成/已取消 → 进行中）
     * POST /todo/reopen
     */
    public function reopen()
    {
        try {
            $data = $this->getJsonInput();
            if ($error = $this->requireParam($data, 'guid')) {
                return $error;
            }
            $affected = $this->todoService->reopenTodo((string) $data['guid'], $this->getUserWorkId());
            if ($affected === -1) {
                return $this->notFound('待办不存在');
            }
            if ($affected === -3) {
                return $this->businessError('仅已完成/已取消的待办可重新打开');
            }
            return $this->success(['reopened' => true], '已重新打开');
        } catch (\App\Exceptions\BusinessException $e) {
            return $this->businessError($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '[TodoApi::reopen] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * 催办（记流水，预留消息推送）
     * POST /todo/urge
     */
    public function urge()
    {
        try {
            $data = $this->getJsonInput();
            if ($error = $this->requireParam($data, 'guid')) {
                return $error;
            }
            $affected = $this->todoService->urgeTodo((string) $data['guid'], $this->getUserWorkId());
            if ($affected === -1) {
                return $this->notFound('待办不存在');
            }
            return $this->success(['urged' => true], '已催办');
        } catch (\App\Exceptions\BusinessException $e) {
            return $this->businessError($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '[TodoApi::urge] ' . $e->getMessage());
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
            // 新负责人支持数组（多选）或逗号分隔字符串
            $newAssignee = is_array($data['新负责人'])
                ? implode(',', $data['新负责人'])
                : (string) $data['新负责人'];
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
     * 操作流水（详情时间线用）
     * GET /todo/logs?guid=xxx
     */
    public function logs()
    {
        try {
            $guid = trim((string) ($this->request->getGet('guid') ?? ''));
            if ($guid === '') {
                return $this->paramError('guid 不能为空');
            }
            $logs = $this->todoService->getLogs($guid);
            return $this->success(['list' => $logs]);
        } catch (\App\Exceptions\BusinessException $e) {
            return $this->businessError($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '[TodoApi::logs] ' . $e->getMessage());
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

    // ============================================================
    // 站内消息
    // ============================================================

    /**
     * 我的站内消息
     * GET /todo/messages?page=1&pageSize=20&unreadOnly=1
     */
    public function messages()
    {
        try {
            $page = (int) ($this->request->getGet('page') ?? 1);
            $pageSize = (int) ($this->request->getGet('pageSize') ?? 20);
            $unreadOnly = $this->request->getGet('unreadOnly') === '1';
            $result = $this->messageService->list($this->getUserWorkId(), $unreadOnly, $page, $pageSize);
            return $this->success($result);
        } catch (\Throwable $e) {
            log_message('error', '[TodoApi::messages] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * 未读消息数（Header 角标轮询）
     * GET /todo/messages-count
     */
    public function messagesCount()
    {
        try {
            return $this->success(['count' => $this->messageService->unreadCount($this->getUserWorkId())]);
        } catch (\Throwable $e) {
            log_message('error', '[TodoApi::messagesCount] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * 标记已读
     * POST /todo/messages-read  body: {ids: [1,2]} 或 {all: true}
     */
    public function messagesRead()
    {
        try {
            $data = $this->getJsonInput();
            if (!empty($data['all'])) {
                $affected = $this->messageService->markRead($this->getUserWorkId(), null);
                return $this->success(['affected' => $affected], '已全部标记已读');
            }
            $ids = $data['ids'] ?? [];
            if (!is_array($ids) || $ids === []) {
                return $this->paramError('ids 不能为空');
            }
            $affected = $this->messageService->markRead($this->getUserWorkId(), $ids);
            return $this->success(['affected' => $affected]);
        } catch (\App\Exceptions\BusinessException $e) {
            return $this->businessError($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '[TodoApi::messagesRead] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    // ============================================================
    // 置顶 / 子任务 / 评论
    // ============================================================

    /**
     * 置顶/取消置顶
     * POST /todo/toggle-pin
     */
    public function togglePin()
    {
        try {
            $data = $this->getJsonInput();
            if ($error = $this->requireParam($data, 'guid')) {
                return $error;
            }
            $result = $this->todoService->togglePin((string) $data['guid'], $this->getUserWorkId());
            return $this->success($result, $result['pinned'] ? '已置顶' : '已取消置顶');
        } catch (\App\Exceptions\BusinessException $e) {
            return $this->businessError($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '[TodoApi::togglePin] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * 子任务列表
     * GET /todo/subtasks?guid=xxx
     */
    public function subtasks()
    {
        try {
            $guid = (string) ($this->request->getGet('guid') ?? '');
            if ($guid === '') {
                return $this->paramError('guid 不能为空');
            }
            return $this->success($this->todoService->getSubtasks($guid));
        } catch (\Throwable $e) {
            log_message('error', '[TodoApi::subtasks] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * 评论列表
     * GET /todo/comments?guid=xxx
     */
    public function comments()
    {
        try {
            $guid = (string) ($this->request->getGet('guid') ?? '');
            if ($guid === '') {
                return $this->paramError('guid 不能为空');
            }
            return $this->success($this->todoService->getComments($guid));
        } catch (\Throwable $e) {
            log_message('error', '[TodoApi::comments] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * 添加评论
     * POST /todo/comment  body: {guid, content}
     */
    public function comment()
    {
        try {
            $data = $this->getJsonInput();
            if ($error = $this->requireParam($data, 'guid')) {
                return $error;
            }
            $content = trim((string) ($data['content'] ?? ''));
            $comment = $this->todoService->addComment((string) $data['guid'], $content, $this->getUserWorkId());
            return $this->success($comment, '评论成功');
        } catch (\App\Exceptions\BusinessException $e) {
            return $this->businessError($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '[TodoApi::comment] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    // ============================================================
    // 附件
    // ============================================================

    /**
     * 上传附件
     * POST /todo/upload  form-data: file
     * 存储：writable/uploads/todo/YYYYMM/随机串.扩展名
     */
    public function upload()
    {
        try {
            $file = $this->request->getFile('file');
            if (!$file || !$file->isValid()) {
                return $this->paramError('请上传有效的文件');
            }
            if ($file->getSizeByUnit('mb') > 20) {
                return $this->paramError('文件不能超过 20MB');
            }

            $dir = WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR . 'todo' . DIRECTORY_SEPARATOR . date('Ym');
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $stored = $file->getRandomName();
            $file->move($dir, $stored);

            return $this->success([
                'name' => $file->getClientName(),
                'file' => date('Ym') . '/' . $stored,
                'size' => $file->getSizeByUnit('kb'),
            ], '上传成功');
        } catch (\Throwable $e) {
            log_message('error', '[TodoApi::upload] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * 下载附件
     * GET /todo/download?file=YYYYMM/xxx.ext&name=原始文件名
     */
    public function download()
    {
        try {
            $file = (string) ($this->request->getGet('file') ?? '');
            $name = (string) ($this->request->getGet('name') ?? '');
            if ($file === '' || preg_match('/[^A-Za-z0-9\/.\-_]/', $file)) {
                return $this->paramError('文件参数无效');
            }
            $path = WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR . 'todo' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
            if (!is_file($path)) {
                return $this->notFound('文件不存在');
            }
            return $this->response->download($path, null)->setFileName($name !== '' ? $name : basename($file));
        } catch (\Throwable $e) {
            log_message('error', '[TodoApi::download] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    // ============================================================
    // 定时任务
    // ============================================================

    /**
     * 到期/逾期提醒扫描（计划任务调用）
     * POST /todo/remind-cron?token=xxx
     * token 取 .env 中 CRON_TOKEN，未配置时仅允许 CLI 执行
     */
    public function remindCron()
    {
        try {
            $token = (string) ($this->request->getGet('token') ?? '');
            $expected = (string) (getenv('CRON_TOKEN') ?: '');
            if (!is_cli() && ($expected === '' || !hash_equals($expected, $token))) {
                return $this->businessError('无权访问');
            }
            $result = $this->todoService->scanReminders();
            log_message('info', sprintf('[TodoApi::remindCron] due=%d overdue=%d', $result['due'], $result['overdue']));
            return $this->success($result);
        } catch (\Throwable $e) {
            log_message('error', '[TodoApi::remindCron] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }
}
