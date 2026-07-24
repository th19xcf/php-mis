<?php

namespace App\Controllers;

use App\Services\Contract\ContractService;
use App\Services\Workflow\WorkflowService;

class ContractV2Api extends BaseApiController
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

            $result = $this->contractService->getList($params, $page, $pageSize);

            return $this->success($result);
        } catch (\Throwable $e) {
            log_message('error', '[ContractV2Api::list] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
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

            return $this->success($result);
        } catch (\Throwable $e) {
            log_message('error', '[ContractV2Api::detail] ' . $e->getMessage());
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
            log_message('error', '[ContractV2Api::create] ' . $e->getMessage());
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
            log_message('error', '[ContractV2Api::update] ' . $e->getMessage());
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
            log_message('error', '[ContractV2Api::delete] ' . $e->getMessage());
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
            $workflowCode = $data['workflowCode'] ?? 'contract_approval';
            $sponsor = $this->getUserWorkId();
            $sponsorName = $this->getUserName();

            $result = $this->contractService->submitApproval($contractNo, $sponsor, $sponsorName, $workflowCode);

            return $this->success($result, '提交审批成功');
        } catch (\Throwable $e) {
            log_message('error', '[ContractV2Api::submit] ' . $e->getMessage());
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
            $action = strtoupper($data['action']);
            $opinion = $data['opinion'] ?? '';

            if (!in_array($action, ['APPROVE', 'REJECT'], true)) {
                return $this->paramError('action 参数无效');
            }

            $approver = $this->getUserWorkId();
            $approverName = $this->getUserName();

            $result = $this->contractService->handleApproval($taskId, $approver, $approverName, $action, $opinion);

            return $this->success($result, '审批成功');
        } catch (\Throwable $e) {
            log_message('error', '[ContractV2Api::approve] ' . $e->getMessage());
            return $this->businessError($e->getMessage());
        }
    }

    public function stats()
    {
        try {
            $filters = $this->request->getGet() + ($this->request->getJSON(true) ?? []);

            $result = $this->contractService->getStats($filters);

            return $this->success($result);
        } catch (\Throwable $e) {
            log_message('error', '[ContractV2Api::stats] ' . $e->getMessage());
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
            log_message('error', '[ContractV2Api::options] ' . $e->getMessage());
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

            $result = $this->workflowService->getPendingTasks($approver, $page, $pageSize);

            return $this->success($result);
        } catch (\Throwable $e) {
            log_message('error', '[ContractV2Api::pendingTasks] ' . $e->getMessage());
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

            $result = $this->workflowService->getDoneTasks($approver, $page, $pageSize);

            return $this->success($result);
        } catch (\Throwable $e) {
            log_message('error', '[ContractV2Api::doneTasks] ' . $e->getMessage());
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

            $result = $this->workflowService->getMyInstances($sponsor, $page, $pageSize);

            return $this->success($result);
        } catch (\Throwable $e) {
            log_message('error', '[ContractV2Api::myContracts] ' . $e->getMessage());
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
            log_message('error', '[ContractV2Api::flowDetail] ' . $e->getMessage());
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
            log_message('error', '[ContractV2Api::uploadDocument] ' . $e->getMessage());
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
            log_message('error', '[ContractV2Api::deleteDocument] ' . $e->getMessage());
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

            $fileName = $doc['文档名称'] . '.' . ($doc['文档格式'] ?? '');
            $fileSize = filesize($filePath);

            return $this->response
                ->setHeader('Content-Type', 'application/octet-stream')
                ->setHeader('Content-Disposition', 'attachment; filename="' . rawurlencode($fileName) . '"')
                ->setHeader('Content-Length', (string) $fileSize)
                ->setBody(file_get_contents($filePath));
        } catch (\Throwable $e) {
            log_message('error', '[ContractV2Api::downloadDocument] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }
}
