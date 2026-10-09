<?php

namespace App\Controllers;

use App\Services\Contract\ContractService;
use App\Services\Workflow\WorkflowConstants;
use App\Services\Workflow\WorkflowService;

class ContractApi extends BaseApiController
{
    private ContractService $contractService;
    private WorkflowService $workflowService;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);

        $this->contractService = new ContractService();
        $this->workflowService = new WorkflowService();
    }

    public function list()
    {
        try {
            $params = $this->request->getGet() + ($this->request->getJSON(true) ?? []);
            $page = (int) ($params['page'] ?? 1);
            $pageSize = (int) ($params['pageSize'] ?? 20);

            unset($params['page'], $params['pageSize']);
            $params['deptAuthz'] = $this->getDeptAuthz();
            $params['deptNameAuthz'] = $this->getDeptNameAuthz();

            $result = $this->contractService->getList($params, $page, $pageSize);

            return $this->success($result);
        } catch (\Throwable $e) {
            log_message('error', '[ContractApi::list] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * 调试：打印合同列表 SQL + 部门赋权条件 + 分段耗时
     * 权限：hasDebugSqlAuth（与 pageMeta.toolbar.debugSql 同源）
     */
    public function debugList()
    {
        if (! $this->hasDebugSqlAuth()) {
            return $this->serverError('无调试权限');
        }

        $totalStart = hrtime(true);

        $params = $this->request->getGet() + ($this->request->getJSON(true) ?? []);
        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 20);
        unset($params['page'], $params['pageSize']);

        $deptAuthz = $this->getDeptAuthz();
        $deptNameAuthz = $this->getDeptNameAuthz();

        $tableName = '`def_contract_master_new`';
        $where = ['`删除标识`=' . $this->model->quote('0'), '`有效标识`=' . $this->model->quote('1')];

        if (!empty($params['contractNo'])) {
            $where[] = '`合同编号`=' . $this->model->quote($params['contractNo']);
        }
        if (!empty($params['contractName'])) {
            $where[] = '`合同名称` like ' . $this->model->quote('%' . $params['contractName'] . '%');
        }
        if (!empty($params['contractType'])) {
            $where[] = '`合同类型`=' . $this->model->quote($params['contractType']);
        }
        if (!empty($params['contractStatus'])) {
            $where[] = '`合同状态`=' . $this->model->quote($params['contractStatus']);
        }
        if (!empty($params['partyA'])) {
            $where[] = '`甲方名称` like ' . $this->model->quote('%' . $params['partyA'] . '%');
        }
        if (!empty($params['partyB'])) {
            $where[] = '`乙方名称` like ' . $this->model->quote('%' . $params['partyB'] . '%');
        }
        if (!empty($params['signDateStart'])) {
            $where[] = '`签订日期` >= ' . $this->model->quote($params['signDateStart']);
        }
        if (!empty($params['signDateEnd'])) {
            $where[] = '`签订日期` <= ' . $this->model->quote($params['signDateEnd']);
        }
        if (!empty($params['creator'])) {
            $where[] = '`创建人`=' . $this->model->quote($params['creator']);
        }
        if (!empty($params['deptCode'])) {
            $where[] = '`所属部门编码`=' . $this->model->quote($params['deptCode']);
        }
        if (!empty($deptAuthz)) {
            $deptCodes = array_filter(explode('|', $deptAuthz));
            if (!empty($deptCodes)) {
                $quoted = array_map(fn($code) => $this->model->quote($code), $deptCodes);
                $where[] = '`所属部门编码` in (' . implode(',', $quoted) . ')';
            }
        }
        if (!empty($deptNameAuthz)) {
            $deptNames = array_filter(explode('|', $deptNameAuthz));
            if (!empty($deptNames)) {
                $instrParts = array_map(fn($name) => sprintf('instr(`所属部门名称`, %s) > 0', $this->model->quote($name)), $deptNames);
                $where[] = '(' . implode(' or ', $instrParts) . ')';
            }
        }

        $whereSql = implode(' and ', $where);
        $offset = ($page - 1) * $pageSize;

        $countSql = sprintf('select count(*) as `total` from %s where %s', $tableName, $whereSql);
        $listSql = sprintf('select * from %s where %s order by `创建时间` desc limit %d offset %d', $tableName, $whereSql, $pageSize, $offset);

        $queryStart = hrtime(true);
        $result = $this->model->select($listSql);
        $list = $result ? $result->getResultArray() : [];
        $queryEnd = hrtime(true);

        $totalEnd = hrtime(true);

        return $this->success([
            'countSql' => $countSql,
            'listSql' => $listSql,
            'whereSql' => $whereSql,
            'deptAuthz' => $deptAuthz ?: '(空 → 不过滤)',
            'deptNameAuthz' => $deptNameAuthz ?: '(空 → 不过滤)',
            'rowCount' => count($list),
            'page' => $page,
            'pageSize' => $pageSize,
            'timing' => [
                'queryMs' => round(($queryEnd - $queryStart) / 1e6, 2),
                'totalMs' => round(($totalEnd - $totalStart) / 1e6, 2),
            ],
        ]);
    }

    public function detail()
    {
        try {
            $data = $this->getJsonInput();
            $contractNo = $data['contractNo'] ?? $data['guid'] ?? $this->request->getGet('contractNo') ?? $this->request->getGet('guid') ?? '';

            if (empty($contractNo)) {
                return $this->paramError('合同编号不能为空');
            }

            $result = $this->contractService->getDetail($contractNo);

            if (!$result) {
                return $this->notFound('合同不存在');
            }

            // 计算当前用户是否可审批：仅当合同状态=审批中 且 流程实例ID>0 时，
            // 查 def_workflow_task 是否存在 实例ID=流程实例ID AND 任务状态='待处理' AND 处理人=当前用户工号
            $result['canApprove'] = $this->computeCanApprove($result);

            return $this->success($result);
        } catch (\Throwable $e) {
            log_message('error', '[ContractApi::detail] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function create()
    {
        try {
            $data = $this->getJsonInput();

            if ($error = $this->requireParams($data, ['合同名称', '甲方名称', '乙方名称'])) {
                return $error;
            }

            $creator = $this->getUserWorkId();
            $creatorName = $this->getUserName();
            $deptCode = $this->userContext->getDeptCode();
            $deptName = $this->userContext->getDeptName();

            $result = $this->contractService->createContract($data, $creator, $creatorName, $deptCode, $deptName);

            return $this->success($result, '创建合同成功');
        } catch (\Throwable $e) {
            log_message('error', '[ContractApi::create] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function update()
    {
        try {
            $data = $this->getJsonInput();

            if ($error = $this->requireParam($data, 'contractNo')) {
                return $error;
            }

            $contractNo = $data['contractNo'];
            $operator = $this->getUserWorkId();

            $result = $this->contractService->updateContract($contractNo, $data, $operator);

            return $this->success(['updated' => $result], '更新合同成功');
        } catch (\Throwable $e) {
            log_message('error', '[ContractApi::update] ' . $e->getMessage());
            return $this->businessError($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            $data = $this->getJsonInput();

            if ($error = $this->requireParam($data, 'contractNo')) {
                return $error;
            }

            $contractNo = $data['contractNo'];
            $operator = $this->getUserWorkId();

            $result = $this->contractService->deleteContract($contractNo, $operator);

            return $this->success(['deleted' => $result], '删除合同成功');
        } catch (\Throwable $e) {
            log_message('error', '[ContractApi::delete] ' . $e->getMessage());
            return $this->businessError($e->getMessage());
        }
    }

    public function submit()
    {
        try {
            $data = $this->getJsonInput();

            if ($error = $this->requireParam($data, 'contractNo')) {
                return $error;
            }

            $contractNo = $data['contractNo'];
            // 留空走流程路由解析（def_workflow_routing），未命中时由服务层默认流程编码兜底
            $workflowCode = $data['workflowCode'] ?? '';
            $sponsor = $this->getUserWorkId();
            $sponsorName = $this->getUserName();

            $result = $this->contractService->submitApproval($contractNo, $sponsor, $sponsorName, $workflowCode);

            return $this->success($result, '提交审批成功');
        } catch (\Throwable $e) {
            log_message('error', '[ContractApi::submit] ' . $e->getMessage());
            return $this->businessError($e->getMessage());
        }
    }

    public function approve()
    {
        try {
            $data = $this->getJsonInput();

            if ($error = $this->requireParams($data, ['taskId', 'action'])) {
                return $error;
            }

            $taskId = (int) $data['taskId'];
            $action = (string) $data['action'];
            $opinion = $data['opinion'] ?? '';
            // 拒绝模式：terminate 终止（默认）/ sponsor 退回发起人 / previous 退回上一节点
            $rejectMode = (string) ($data['rejectMode'] ?? 'terminate');

            if (!in_array($action, ['同意', '拒绝'], true)) {
                return $this->paramError('action 参数无效');
            }
            if (!in_array($rejectMode, ['terminate', 'sponsor', 'previous'], true)) {
                return $this->paramError('rejectMode 参数无效');
            }

            $approver = $this->getUserWorkId();
            $approverName = $this->getUserName();

            $result = $this->contractService->handleApproval($taskId, $approver, $approverName, $action, $opinion, $rejectMode);

            return $this->success($result, '审批成功');
        } catch (\Throwable $e) {
            log_message('error', '[ContractApi::approve] ' . $e->getMessage());
            return $this->businessError($e->getMessage());
        }
    }

    /**
     * 撤回审批（仅发起人、审批未开始前；撤回后合同回置草稿可重新提交）
     */
    public function withdraw()
    {
        try {
            $data = $this->getJsonInput();

            if ($error = $this->requireParam($data, 'contractNo')) {
                return $error;
            }

            $contractNo = $data['contractNo'];
            $sponsor = $this->getUserWorkId();
            $sponsorName = $this->getUserName();

            $result = $this->contractService->withdrawApproval($contractNo, $sponsor, $sponsorName);

            return $this->success($result, '撤回审批成功，合同已恢复为草稿');
        } catch (\Throwable $e) {
            log_message('error', '[ContractApi::withdraw] ' . $e->getMessage());
            return $this->businessError($e->getMessage());
        }
    }

    public function stats()
    {
        try {
            $filters = $this->request->getGet() + ($this->request->getJSON(true) ?? []);
            $filters['deptAuthz'] = $this->getDeptAuthz();
            $filters['deptNameAuthz'] = $this->getDeptNameAuthz();

            $result = $this->contractService->getStats($filters);

            return $this->success($result);
        } catch (\Throwable $e) {
            log_message('error', '[ContractApi::stats] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function options()
    {
        try {
            $companyId = $this->userContext->getSessionUser()['companyId'] ?? 'ALL';

            $result = $this->contractService->getOptions($companyId);

            return $this->success($result);
        } catch (\Throwable $e) {
            log_message('error', '[ContractApi::options] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function pendingTasks()
    {
        try {
            $params = $this->request->getGet() + ($this->request->getJSON(true) ?? []);
            $page = (int) ($params['page'] ?? 1);
            $pageSize = (int) ($params['pageSize'] ?? 20);

            $approver = $this->getUserWorkId();

            $result = $this->workflowService->getPendingTasks($approver, $page, $pageSize, WorkflowConstants::BUSINESS_TYPE_CONTRACT);

            return $this->success($result);
        } catch (\Throwable $e) {
            log_message('error', '[ContractApi::pendingTasks] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function doneTasks()
    {
        try {
            $params = $this->request->getGet() + ($this->request->getJSON(true) ?? []);
            $page = (int) ($params['page'] ?? 1);
            $pageSize = (int) ($params['pageSize'] ?? 20);

            $approver = $this->getUserWorkId();

            $result = $this->workflowService->getDoneTasks($approver, $page, $pageSize, WorkflowConstants::BUSINESS_TYPE_CONTRACT);

            return $this->success($result);
        } catch (\Throwable $e) {
            log_message('error', '[ContractApi::doneTasks] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function myContracts()
    {
        try {
            $params = $this->request->getGet() + ($this->request->getJSON(true) ?? []);
            $page = (int) ($params['page'] ?? 1);
            $pageSize = (int) ($params['pageSize'] ?? 20);

            $sponsor = $this->getUserWorkId();

            $result = $this->workflowService->getMyInstances($sponsor, $page, $pageSize, WorkflowConstants::BUSINESS_TYPE_CONTRACT);

            return $this->success($result);
        } catch (\Throwable $e) {
            log_message('error', '[ContractApi::myContracts] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function flowDetail()
    {
        try {
            $data = $this->getJsonInput();
            $instanceId = (int) ($data['instanceId'] ?? $this->request->getGet('instanceId') ?? 0);

            if ($instanceId <= 0) {
                return $this->paramError('instanceId 不能为空');
            }

            $result = $this->workflowService->getInstanceDetail($instanceId);

            if (empty($result)) {
                return $this->notFound('流程实例不存在');
            }

            return $this->success($result);
        } catch (\Throwable $e) {
            log_message('error', '[ContractApi::flowDetail] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function uploadDocument()
    {
        try {
            $contractNo = $this->request->getPost('contractNo') ?? '';
            $docType = $this->request->getPost('docType') ?? 'MAIN';
            $docName = $this->request->getPost('docName') ?? '';

            if (empty($contractNo)) {
                return $this->paramError('合同编号不能为空');
            }

            $file = $this->request->getFile('file');
            if (!$file || !$file->isValid()) {
                return $this->paramError('请上传有效的文件');
            }

            $allowedTypes = ['MAIN', 'APPROVAL_FORM', 'ATTACHMENT', 'SUPPLEMENT'];
            if (!in_array($docType, $allowedTypes, true)) {
                return $this->paramError('文档类型无效');
            }

            $creator = $this->getUserWorkId();
            $creatorName = $this->getUserName();

            $result = $this->contractService->uploadDocument($contractNo, $file, $docType, $docName, $creator, $creatorName);

            return $this->success($result, '上传成功');
        } catch (\Throwable $e) {
            log_message('error', '[ContractApi::uploadDocument] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function deleteDocument()
    {
        try {
            $data = $this->getJsonInput();
            $docId = (int) ($data['docId'] ?? 0);

            if ($docId <= 0) {
                return $this->paramError('文档ID不能为空');
            }

            $operator = $this->getUserWorkId();
            $result = $this->contractService->deleteDocument($docId, $operator);

            return $this->success(['deleted' => $result], '删除成功');
        } catch (\Throwable $e) {
            log_message('error', '[ContractApi::deleteDocument] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function downloadDocument($docId = null)
    {
        try {
            $docId = (int) ($docId ?? $this->request->getGet('docId') ?? 0);

            if ($docId <= 0) {
                return $this->paramError('文档ID不能为空');
            }

            $sql = sprintf(
                'select * from `def_contract_document` where `GUID`=%d and `删除标识`=%s limit 1',
                $docId,
                $this->model->quote('0')
            );
            $result = $this->model->select($sql);
            $doc = $result ? ($result->getRowArray() ?: []) : [];

            if (empty($doc) || empty($doc['文件路径'])) {
                return $this->notFound('文档不存在');
            }

            $filePath = WRITEPATH . $doc['文件路径'];
            if (!file_exists($filePath)) {
                return $this->notFound('文件不存在');
            }

            $safeFileName = $doc['文档名称'] . '.' . ($doc['文档格式'] ?? '');

            return $this->response
                ->download($filePath, null)
                ->setFileName($safeFileName);
        } catch (\Throwable $e) {
            log_message('error', '[ContractApi::downloadDocument] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * 计算当前用户是否可审批该合同
     * 仅当合同状态=审批中 且 流程实例ID>0 时，查询 def_workflow_task
     * 是否存在 实例ID=流程实例ID AND 任务状态='待处理' AND 处理人=当前用户工号 的任务
     */
    private function computeCanApprove(array $contract): bool
    {
        $status = $contract['合同状态'] ?? '';
        $instanceId = (int) ($contract['流程实例ID'] ?? 0);
        if ($status !== '审批中' || $instanceId <= 0) {
            return false;
        }

        $workId = $this->getUserWorkId();
        if ($workId === '') {
            return false;
        }

        $sql = sprintf(
            'select count(*) as cnt from `def_workflow_task`
             where `实例ID`=%s and `任务状态`=%s and `处理人`=%s
             and `删除标识`=%s and `有效标识`=%s limit 1',
            $instanceId,
            $this->model->quote('待处理'),
            $this->model->quote($workId),
            $this->model->quote('0'),
            $this->model->quote('1')
        );
        $result = $this->model->select($sql);
        $row = $result ? ($result->getRowArray() ?: []) : [];
        return (int) ($row['cnt'] ?? 0) > 0;
    }
}
