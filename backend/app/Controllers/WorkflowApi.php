<?php

namespace App\Controllers;

use App\Services\Workflow\WorkflowService;

class WorkflowApi extends BaseApiController
{
    private WorkflowService $workflowService;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);

        $this->workflowService = new WorkflowService();
    }

    public function definitionList()
    {
        try {
            $params = $this->request->getGet() + ($this->request->getJSON(true) ?? []);
            $page = (int) ($params['page'] ?? 1);
            $pageSize = (int) ($params['pageSize'] ?? 20);

            $where = ['`删除标识`=' . $this->model->quote('0')];

            if (!empty($params['businessType'])) {
                $where[] = '`业务类型`=' . $this->model->quote($params['businessType']);
            }
            if (!empty($params['workflowCode'])) {
                $where[] = '`流程编码` like ' . $this->model->quote('%' . $params['workflowCode'] . '%');
            }
            if (!empty($params['workflowName'])) {
                $where[] = '`流程名称` like ' . $this->model->quote('%' . $params['workflowName'] . '%');
            }
            if (!empty($params['status'])) {
                $where[] = '`流程状态`=' . $this->model->quote($params['status']);
            }

            $whereSql = implode(' and ', $where);
            $offset = ($page - 1) * $pageSize;

            $countSql = sprintf(
                'select count(*) as `total` from `def_workflow_definition` where %s',
                $whereSql
            );
            $result = $this->model->select($countSql);
            $row = $result ? ($result->getRowArray() ?: []) : [];
            $total = (int) ($row['total'] ?? 0);

            $listSql = sprintf(
                'select * from `def_workflow_definition` where %s order by `创建时间` desc limit %d offset %d',
                $whereSql,
                $pageSize,
                $offset
            );
            $result = $this->model->select($listSql);
            $list = $result ? $result->getResultArray() : [];

            return $this->success([
                'list' => $list,
                'total' => $total,
                'page' => $page,
                'pageSize' => $pageSize,
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[WorkflowApi::definitionList] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function definitionDetail()
    {
        try {
            $data = $this->getJsonInput();
            $defId = (int) ($data['defId'] ?? $this->request->getGet('defId') ?? 0);

            if ($defId <= 0) {
                return $this->paramError('defId 不能为空');
            }

            $sql = sprintf(
                'select * from `def_workflow_definition` where `GUID`=%d and `删除标识`=%s limit 1',
                $defId,
                $this->model->quote('0')
            );
            $result = $this->model->select($sql);
            $definition = $result ? ($result->getRowArray() ?: []) : [];

            if (empty($definition)) {
                return $this->notFound('流程定义不存在');
            }

            $sql = sprintf(
                'select * from `def_workflow_node`
                where `流程定义ID`=%d and `删除标识`=%s
                order by `排序`',
                $defId,
                $this->model->quote('0')
            );
            $result = $this->model->select($sql);
            $nodes = $result ? $result->getResultArray() : [];

            $sql = sprintf(
                'select * from `def_workflow_edge`
                where `流程定义ID`=%d and `删除标识`=%s
                order by `GUID`',
                $defId,
                $this->model->quote('0')
            );
            $result = $this->model->select($sql);
            $edges = $result ? $result->getResultArray() : [];

            $definition['nodes'] = $nodes;
            $definition['edges'] = $edges;

            return $this->success($definition);
        } catch (\Throwable $e) {
            log_message('error', '[WorkflowApi::definitionDetail] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function definitionCreate()
    {
        try {
            $data = $this->getJsonInput();

            if ($error = $this->requireParams($data, ['流程编码', '流程名称', '业务类型'])) {
                return $error;
            }

            $now = date('Y-m-d H:i:s');
            $operator = $this->getUserWorkId();
            $operatorName = $this->getUserName();

            $sql = sprintf(
                'select count(*) as `cnt` from `def_workflow_definition`
                where `流程编码`=%s and `删除标识`=%s',
                $this->model->quote($data['流程编码']),
                $this->model->quote('0')
            );
            $result = $this->model->select($sql);
            $row = $result ? ($result->getRowArray() ?: []) : [];
            $maxVersion = (int) ($row['cnt'] ?? 0);
            $newVersion = $maxVersion + 1;

            $approvalConfig = !empty($data['审批人配置'])
                ? json_encode($data['审批人配置'], JSON_UNESCAPED_UNICODE)
                : null;
            $timeoutRules = !empty($data['超时规则'])
                ? json_encode($data['超时规则'], JSON_UNESCAPED_UNICODE)
                : null;

            $sql = sprintf(
                'insert into `def_workflow_definition`
                (`流程编码`, `流程名称`, `业务类型`, `版本号`, `流程状态`, `流程描述`,
                 `审批人配置`, `超时规则`,
                 `操作来源`, `操作人员`, `操作时间`,
                 `创建人`, `创建时间`, `更新人`, `更新时间`)
                values (%s, %s, %s, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
                $this->model->quote($data['流程编码']),
                $this->model->quote($data['流程名称']),
                $this->model->quote($data['业务类型']),
                $newVersion,
                $this->model->quote($data['流程状态'] ?? 'DRAFT'),
                $this->model->quote($data['流程描述'] ?? ''),
                $approvalConfig ? $this->model->quote($approvalConfig) : 'null',
                $timeoutRules ? $this->model->quote($timeoutRules) : 'null',
                $this->model->quote('WEB'),
                $this->model->quote($operator),
                $this->model->quote($now),
                $this->model->quote($operator),
                $this->model->quote($now),
                $this->model->quote($operator),
                $this->model->quote($now)
            );
            $this->model->exec($sql);

            $sql = 'select last_insert_id() as `id`';
            $result = $this->model->select($sql);
            $row = $result ? ($result->getRowArray() ?: []) : [];
            $defId = (int) ($row['id'] ?? 0);

            return $this->success(['defId' => $defId, 'version' => $newVersion], '创建流程定义成功');
        } catch (\Throwable $e) {
            log_message('error', '[WorkflowApi::definitionCreate] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function definitionUpdate()
    {
        try {
            $data = $this->getJsonInput();

            if ($error = $this->requireParam($data, 'defId')) {
                return $error;
            }

            $defId = (int) $data['defId'];
            $now = date('Y-m-d H:i:s');
            $operator = $this->getUserWorkId();

            $updates = [];
            $allowedFields = ['流程名称', '流程描述', '审批人配置', '超时规则'];

            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $value = $data[$field];
                    if (is_array($value)) {
                        $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                    }
                    $updates[] = sprintf('`%s`=%s', $field, $this->model->quote($value));
                }
            }

            if (empty($updates)) {
                return $this->paramError('没有需要更新的字段');
            }

            $updates[] = sprintf('`更新人`=%s', $this->model->quote($operator));
            $updates[] = sprintf('`更新时间`=%s', $this->model->quote($now));
            $updates[] = sprintf('`操作人员`=%s', $this->model->quote($operator));
            $updates[] = sprintf('`操作时间`=%s', $this->model->quote($now));

            $sql = sprintf(
                'update `def_workflow_definition` set %s where `GUID`=%d',
                implode(', ', $updates),
                $defId
            );
            $this->model->exec($sql);

            return $this->success(['updated' => true], '更新流程定义成功');
        } catch (\Throwable $e) {
            log_message('error', '[WorkflowApi::definitionUpdate] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function definitionDelete()
    {
        try {
            $data = $this->getJsonInput();

            if ($error = $this->requireParam($data, 'defId')) {
                return $error;
            }

            $defId = (int) $data['defId'];
            $now = date('Y-m-d H:i:s');
            $operator = $this->getUserWorkId();

            $sql = sprintf(
                'update `def_workflow_definition`
                set `删除标识`=%s, `更新人`=%s, `更新时间`=%s
                where `GUID`=%d',
                $this->model->quote('1'),
                $this->model->quote($operator),
                $this->model->quote($now),
                $defId
            );
            $this->model->exec($sql);

            return $this->success(['deleted' => true], '删除流程定义成功');
        } catch (\Throwable $e) {
            log_message('error', '[WorkflowApi::definitionDelete] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function definitionActivate()
    {
        try {
            $data = $this->getJsonInput();

            if ($error = $this->requireParam($data, 'defId')) {
                return $error;
            }

            $defId = (int) $data['defId'];
            $now = date('Y-m-d H:i:s');
            $operator = $this->getUserWorkId();

            $sql = sprintf(
                'select * from `def_workflow_definition` where `GUID`=%d limit 1',
                $defId
            );
            $result = $this->model->select($sql);
            $definition = $result ? ($result->getRowArray() ?: []) : [];
            if (empty($definition)) {
                return $this->notFound('流程定义不存在');
            }

            $workflowCode = $definition['流程编码'];

            $sql = sprintf(
                'update `def_workflow_definition`
                set `流程状态`=%s, `更新人`=%s, `更新时间`=%s
                where `流程编码`=%s and `流程状态`=%s and `删除标识`=%s',
                $this->model->quote('INACTIVE'),
                $this->model->quote($operator),
                $this->model->quote($now),
                $this->model->quote($workflowCode),
                $this->model->quote('ACTIVE'),
                $this->model->quote('0')
            );
            $this->model->exec($sql);

            $sql = sprintf(
                'update `def_workflow_definition`
                set `流程状态`=%s, `更新人`=%s, `更新时间`=%s
                where `GUID`=%d',
                $this->model->quote('ACTIVE'),
                $this->model->quote($operator),
                $this->model->quote($now),
                $defId
            );
            $this->model->exec($sql);

            return $this->success(['activated' => true], '启用流程成功');
        } catch (\Throwable $e) {
            log_message('error', '[WorkflowApi::definitionActivate] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function definitionDeactivate()
    {
        try {
            $data = $this->getJsonInput();

            if ($error = $this->requireParam($data, 'defId')) {
                return $error;
            }

            $defId = (int) $data['defId'];
            $now = date('Y-m-d H:i:s');
            $operator = $this->getUserWorkId();

            $sql = sprintf(
                'update `def_workflow_definition`
                set `流程状态`=%s, `更新人`=%s, `更新时间`=%s
                where `GUID`=%d',
                $this->model->quote('INACTIVE'),
                $this->model->quote($operator),
                $this->model->quote($now),
                $defId
            );
            $this->model->exec($sql);

            return $this->success(['deactivated' => true], '停用流程成功');
        } catch (\Throwable $e) {
            log_message('error', '[WorkflowApi::definitionDeactivate] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function instanceList()
    {
        try {
            $params = $this->request->getGet() + ($this->request->getJSON(true) ?? []);
            $page = (int) ($params['page'] ?? 1);
            $pageSize = (int) ($params['pageSize'] ?? 20);

            $where = ['i.`删除标识`=' . $this->model->quote('0')];

            if (!empty($params['businessType'])) {
                $where[] = 'i.`业务类型`=' . $this->model->quote($params['businessType']);
            }
            if (!empty($params['businessId'])) {
                $where[] = 'i.`业务ID`=' . $this->model->quote($params['businessId']);
            }
            if (!empty($params['instanceStatus'])) {
                $where[] = 'i.`实例状态`=' . $this->model->quote($params['instanceStatus']);
            }
            if (!empty($params['sponsor'])) {
                $where[] = 'i.`发起人`=' . $this->model->quote($params['sponsor']);
            }
            if (!empty($params['workflowCode'])) {
                $where[] = 'd.`流程编码`=' . $this->model->quote($params['workflowCode']);
            }

            $whereSql = implode(' and ', $where);
            $offset = ($page - 1) * $pageSize;

            $countSql = sprintf(
                'select count(*) as `total`
                from `def_workflow_instance` i
                left join `def_workflow_definition` d on i.`流程定义ID` = d.`GUID`
                where %s',
                $whereSql
            );
            $result = $this->model->select($countSql);
            $row = $result ? ($result->getRowArray() ?: []) : [];
            $total = (int) ($row['total'] ?? 0);

            $listSql = sprintf(
                'select i.`GUID`, i.`流程定义ID`, i.`流程版本`, i.`业务类型`, i.`业务ID`,
                       i.`业务标题`, i.`实例状态`, i.`当前节点编码`, i.`发起人`,
                       i.`发起人姓名`, i.`发起时间`, i.`创建时间`, i.`结束时间`,
                       d.`流程编码`, d.`流程名称`
                from `def_workflow_instance` i
                left join `def_workflow_definition` d on i.`流程定义ID` = d.`GUID`
                where %s
                order by i.`创建时间` desc
                limit %d offset %d',
                $whereSql,
                $pageSize,
                $offset
            );
            $result = $this->model->select($listSql);
            $list = $result ? $result->getResultArray() : [];

            return $this->success([
                'list' => $list,
                'total' => $total,
                'page' => $page,
                'pageSize' => $pageSize,
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[WorkflowApi::instanceList] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function instanceDetail()
    {
        try {
            $instanceId = (int) ($this->request->getGet('instanceId') ?? 0);
            $data = $this->getJsonInput();
            if (!empty($data['instanceId'])) {
                $instanceId = (int) $data['instanceId'];
            }

            if ($instanceId <= 0) {
                return $this->paramError('instanceId 不能为空');
            }

            $result = $this->workflowService->getInstanceDetail($instanceId);

            if (empty($result)) {
                return $this->notFound('流程实例不存在');
            }

            return $this->success($result);
        } catch (\Throwable $e) {
            log_message('error', '[WorkflowApi::instanceDetail] ' . $e->getMessage());
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
            log_message('error', '[WorkflowApi::pendingTasks] ' . $e->getMessage());
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
            log_message('error', '[WorkflowApi::doneTasks] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function myInstances()
    {
        try {
            $params = $this->request->getGet() + ($this->request->getJSON(true) ?? []);
            $page = (int) ($params['page'] ?? 1);
            $pageSize = (int) ($params['pageSize'] ?? 20);

            $sponsor = $this->getUserWorkId();

            $result = $this->workflowService->getMyInstances($sponsor, $page, $pageSize);

            return $this->success($result);
        } catch (\Throwable $e) {
            log_message('error', '[WorkflowApi::myInstances] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function withdraw()
    {
        try {
            $data = $this->getJsonInput();

            if ($error = $this->requireParam($data, 'instanceId')) {
                return $error;
            }

            $instanceId = (int) $data['instanceId'];
            $sponsor = $this->getUserWorkId();

            $result = $this->workflowService->withdraw($instanceId, $sponsor);

            return $this->success(['withdrawn' => $result], '撤回成功');
        } catch (\Throwable $e) {
            log_message('error', '[WorkflowApi::withdraw] ' . $e->getMessage());
            return $this->businessError($e->getMessage());
        }
    }
}
