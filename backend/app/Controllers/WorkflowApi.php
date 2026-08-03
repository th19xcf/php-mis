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

    // ============ 节点(Node)CRUD ============

    public function nodeList()
    {
        try {
            $data = $this->getJsonInput();
            $defId = (int) ($data['defId'] ?? $this->request->getGet('defId') ?? 0);

            if ($defId <= 0) {
                return $this->paramError('defId 不能为空');
            }

            $sql = sprintf(
                'select * from `def_workflow_node`
                where `流程定义ID`=%d and `删除标识`=%s
                order by `排序`',
                $defId,
                $this->model->quote('0')
            );
            $result = $this->model->select($sql);
            $list = $result ? $result->getResultArray() : [];

            return $this->success(['list' => $list, 'total' => count($list)]);
        } catch (\Throwable $e) {
            log_message('error', '[WorkflowApi::nodeList] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function nodeCreate()
    {
        try {
            $data = $this->getJsonInput();

            if ($error = $this->requireParams($data, ['流程定义ID', '节点编码', '节点名称', '节点类型'])) {
                return $error;
            }

            $defId = (int) $data['流程定义ID'];
            $nodeCode = (string) $data['节点编码'];

            // 校验流程定义存在
            $sql = sprintf(
                'select `GUID` from `def_workflow_definition`
                where `GUID`=%d and `删除标识`=%s limit 1',
                $defId,
                $this->model->quote('0')
            );
            $result = $this->model->select($sql);
            if (!$result || !$result->getRowArray()) {
                return $this->notFound('流程定义不存在');
            }

            // 校验节点编码唯一(含已逻辑删除,因数据库唯一索引未含删除标识)
            $sql = sprintf(
                'select `GUID` from `def_workflow_node`
                where `流程定义ID`=%d and `节点编码`=%s and `删除标识`=%s limit 1',
                $defId,
                $this->model->quote($nodeCode),
                $this->model->quote('0')
            );
            $result = $this->model->select($sql);
            if ($result && $result->getRowArray()) {
                return $this->businessError('节点编码已存在:' . $nodeCode);
            }

            $approverType = $data['审批人类型'] ?? null;
            $approverConfig = $data['审批人配置'] ?? null;
            if (!empty($approverConfig) && !is_string($approverConfig)) {
                $approverConfig = json_encode($approverConfig, JSON_UNESCAPED_UNICODE);
            }

            // 计算排序:默认追加到末尾
            $sort = (int) ($data['排序'] ?? 0);
            if ($sort <= 0) {
                $sql = sprintf(
                    'select coalesce(max(`排序`),0) as `max_sort` from `def_workflow_node`
                    where `流程定义ID`=%d and `删除标识`=%s',
                    $defId,
                    $this->model->quote('0')
                );
                $result = $this->model->select($sql);
                $row = $result ? ($result->getRowArray() ?: []) : [];
                $sort = (int) ($row['max_sort'] ?? 0) + 1;
            }

            $now = date('Y-m-d H:i:s');
            $operator = $this->getUserWorkId();

            $sql = sprintf(
                'insert into `def_workflow_node`
                (`流程定义ID`, `节点编码`, `节点名称`, `节点类型`,
                 `审批人类型`, `审批人配置`, `会签或签`,
                 `超时天数`, `超时处理`,
                 `排序`, `操作来源`, `操作人员`, `操作时间`,
                 `创建人`, `创建时间`, `更新人`, `更新时间`)
                values (%d, %s, %s, %s, %s, %s, %s, %d, %s, %d, %s, %s, %s, %s, %s, %s, %s)',
                $defId,
                $this->model->quote($nodeCode),
                $this->model->quote((string) $data['节点名称']),
                $this->model->quote((string) $data['节点类型']),
                $approverType ? $this->model->quote((string) $approverType) : 'null',
                $approverConfig ? $this->model->quote($approverConfig) : 'null',
                $this->model->quote((string) ($data['会签或签'] ?? 'OR')),
                (int) ($data['超时天数'] ?? 0),
                $this->model->quote((string) ($data['超时处理'] ?? 'NOTIFY')),
                $sort,
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
            $nodeId = (int) ($row['id'] ?? 0);

            return $this->success(['nodeId' => $nodeId, 'sort' => $sort], '创建节点成功');
        } catch (\Throwable $e) {
            log_message('error', '[WorkflowApi::nodeCreate] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function nodeUpdate()
    {
        try {
            $data = $this->getJsonInput();

            if ($error = $this->requireParam($data, 'nodeId')) {
                return $error;
            }

            $nodeId = (int) $data['nodeId'];
            $now = date('Y-m-d H:i:s');
            $operator = $this->getUserWorkId();

            // 检查节点存在
            $sql = sprintf(
                'select `GUID`, `流程定义ID`, `节点编码` from `def_workflow_node`
                where `GUID`=%d and `删除标识`=%s limit 1',
                $nodeId,
                $this->model->quote('0')
            );
            $result = $this->model->select($sql);
            $node = $result ? ($result->getRowArray() ?: []) : [];
            if (empty($node)) {
                return $this->notFound('节点不存在');
            }

            // 若修改节点编码,校验新编码不重复
            if (isset($data['节点编码']) && $data['节点编码'] !== $node['节点编码']) {
                $sql = sprintf(
                    'select `GUID` from `def_workflow_node`
                    where `流程定义ID`=%d and `节点编码`=%s and `删除标识`=%s and `GUID`<>%d limit 1',
                    (int) $node['流程定义ID'],
                    $this->model->quote((string) $data['节点编码']),
                    $this->model->quote('0'),
                    $nodeId
                );
                $result = $this->model->select($sql);
                if ($result && $result->getRowArray()) {
                    return $this->businessError('节点编码已存在:' . $data['节点编码']);
                }
            }

            $updates = [];
            $allowedFields = ['节点编码', '节点名称', '节点类型', '审批人类型', '审批人配置', '会签或签', '超时天数', '超时处理', '排序'];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $value = $data[$field];
                    if ($field === '审批人配置' && !empty($value) && !is_string($value)) {
                        $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                    }
                    if ($value === null) {
                        $updates[] = sprintf('`%s`=null', $field);
                    } else {
                        $updates[] = sprintf('`%s`=%s', $field, $this->model->quote((string) $value));
                    }
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
                'update `def_workflow_node` set %s where `GUID`=%d',
                implode(', ', $updates),
                $nodeId
            );
            $this->model->exec($sql);

            return $this->success(['updated' => true], '更新节点成功');
        } catch (\Throwable $e) {
            log_message('error', '[WorkflowApi::nodeUpdate] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function nodeDelete()
    {
        try {
            $data = $this->getJsonInput();

            if ($error = $this->requireParam($data, 'nodeId')) {
                return $error;
            }

            $nodeId = (int) $data['nodeId'];
            $now = date('Y-m-d H:i:s');
            $operator = $this->getUserWorkId();

            // 检查节点存在并取得节点编码,用于级联清理连线
            $sql = sprintf(
                'select `GUID`, `流程定义ID`, `节点编码` from `def_workflow_node`
                where `GUID`=%d and `删除标识`=%s limit 1',
                $nodeId,
                $this->model->quote('0')
            );
            $result = $this->model->select($sql);
            $node = $result ? ($result->getRowArray() ?: []) : [];
            if (empty($node)) {
                return $this->notFound('节点不存在');
            }

            // 逻辑删除节点
            $sql = sprintf(
                'update `def_workflow_node`
                set `删除标识`=%s, `更新人`=%s, `更新时间`=%s, `操作人员`=%s, `操作时间`=%s
                where `GUID`=%d',
                $this->model->quote('1'),
                $this->model->quote($operator),
                $this->model->quote($now),
                $this->model->quote($operator),
                $this->model->quote($now),
                $nodeId
            );
            $this->model->exec($sql);

            // 级联逻辑删除关联连线(源或目标为本节点)
            $sql = sprintf(
                'update `def_workflow_edge`
                set `删除标识`=%s, `更新人`=%s, `更新时间`=%s, `操作人员`=%s, `操作时间`=%s
                where `流程定义ID`=%d and (`源节点编码`=%s or `目标节点编码`=%s) and `删除标识`=%s',
                $this->model->quote('1'),
                $this->model->quote($operator),
                $this->model->quote($now),
                $this->model->quote($operator),
                $this->model->quote($now),
                (int) $node['流程定义ID'],
                $this->model->quote((string) $node['节点编码']),
                $this->model->quote((string) $node['节点编码']),
                $this->model->quote('0')
            );
            $this->model->exec($sql);

            return $this->success(['deleted' => true], '删除节点成功');
        } catch (\Throwable $e) {
            log_message('error', '[WorkflowApi::nodeDelete] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function nodeSort()
    {
        try {
            $data = $this->getJsonInput();

            if ($error = $this->requireParam($data, 'nodeIds')) {
                return $error;
            }

            $nodeIds = $data['nodeIds'];
            if (!is_array($nodeIds) || count($nodeIds) === 0) {
                return $this->paramError('nodeIds 必须为非空数组');
            }

            $now = date('Y-m-d H:i:s');
            $operator = $this->getUserWorkId();

            // 逐条更新排序号(数据量小,无需事务)
            foreach ($nodeIds as $idx => $nodeId) {
                $sql = sprintf(
                    'update `def_workflow_node`
                    set `排序`=%d, `更新人`=%s, `更新时间`=%s, `操作人员`=%s, `操作时间`=%s
                    where `GUID`=%d and `删除标识`=%s',
                    $idx + 1,
                    $this->model->quote($operator),
                    $this->model->quote($now),
                    $this->model->quote($operator),
                    $this->model->quote($now),
                    (int) $nodeId,
                    $this->model->quote('0')
                );
                $this->model->exec($sql);
            }

            return $this->success(['sorted' => true], '排序成功');
        } catch (\Throwable $e) {
            log_message('error', '[WorkflowApi::nodeSort] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    // ============ 连线(Edge)CRUD ============

    public function edgeList()
    {
        try {
            $data = $this->getJsonInput();
            $defId = (int) ($data['defId'] ?? $this->request->getGet('defId') ?? 0);

            if ($defId <= 0) {
                return $this->paramError('defId 不能为空');
            }

            $sql = sprintf(
                'select * from `def_workflow_edge`
                where `流程定义ID`=%d and `删除标识`=%s
                order by `排序`, `GUID`',
                $defId,
                $this->model->quote('0')
            );
            $result = $this->model->select($sql);
            $list = $result ? $result->getResultArray() : [];

            return $this->success(['list' => $list, 'total' => count($list)]);
        } catch (\Throwable $e) {
            log_message('error', '[WorkflowApi::edgeList] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function edgeCreate()
    {
        try {
            $data = $this->getJsonInput();

            if ($error = $this->requireParams($data, ['流程定义ID', '源节点编码', '目标节点编码'])) {
                return $error;
            }

            $defId = (int) $data['流程定义ID'];
            $sourceCode = (string) $data['源节点编码'];
            $targetCode = (string) $data['目标节点编码'];

            // 校验源/目标节点存在
            foreach ([$sourceCode => '源节点', $targetCode => '目标节点'] as $code => $label) {
                $sql = sprintf(
                    'select `GUID` from `def_workflow_node`
                    where `流程定义ID`=%d and `节点编码`=%s and `删除标识`=%s limit 1',
                    $defId,
                    $this->model->quote($code),
                    $this->model->quote('0')
                );
                $result = $this->model->select($sql);
                if (!$result || !$result->getRowArray()) {
                    return $this->businessError($label . '不存在:' . $code);
                }
            }

            // 校验连线不重复
            $sql = sprintf(
                'select `GUID` from `def_workflow_edge`
                where `流程定义ID`=%d and `源节点编码`=%s and `目标节点编码`=%s and `删除标识`=%s limit 1',
                $defId,
                $this->model->quote($sourceCode),
                $this->model->quote($targetCode),
                $this->model->quote('0')
            );
            $result = $this->model->select($sql);
            if ($result && $result->getRowArray()) {
                return $this->businessError('连线已存在:' . $sourceCode . ' -> ' . $targetCode);
            }

            // 计算排序
            $sort = (int) ($data['排序'] ?? 0);
            if ($sort <= 0) {
                $sql = sprintf(
                    'select coalesce(max(`排序`),0) as `max_sort` from `def_workflow_edge`
                    where `流程定义ID`=%d and `删除标识`=%s',
                    $defId,
                    $this->model->quote('0')
                );
                $result = $this->model->select($sql);
                $row = $result ? ($result->getRowArray() ?: []) : [];
                $sort = (int) ($row['max_sort'] ?? 0) + 1;
            }

            $now = date('Y-m-d H:i:s');
            $operator = $this->getUserWorkId();

            $sql = sprintf(
                'insert into `def_workflow_edge`
                (`流程定义ID`, `源节点编码`, `目标节点编码`, `条件表达式`, `条件描述`,
                 `排序`, `操作来源`, `操作人员`, `操作时间`,
                 `创建人`, `创建时间`, `更新人`, `更新时间`)
                values (%d, %s, %s, %s, %s, %d, %s, %s, %s, %s, %s, %s, %s)',
                $defId,
                $this->model->quote($sourceCode),
                $this->model->quote($targetCode),
                !empty($data['条件表达式']) ? $this->model->quote((string) $data['条件表达式']) : 'null',
                !empty($data['条件描述']) ? $this->model->quote((string) $data['条件描述']) : 'null',
                $sort,
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
            $edgeId = (int) ($row['id'] ?? 0);

            return $this->success(['edgeId' => $edgeId, 'sort' => $sort], '创建连线成功');
        } catch (\Throwable $e) {
            log_message('error', '[WorkflowApi::edgeCreate] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function edgeUpdate()
    {
        try {
            $data = $this->getJsonInput();

            if ($error = $this->requireParam($data, 'edgeId')) {
                return $error;
            }

            $edgeId = (int) $data['edgeId'];
            $now = date('Y-m-d H:i:s');
            $operator = $this->getUserWorkId();

            // 检查连线存在
            $sql = sprintf(
                'select `GUID`, `流程定义ID`, `源节点编码`, `目标节点编码` from `def_workflow_edge`
                where `GUID`=%d and `删除标识`=%s limit 1',
                $edgeId,
                $this->model->quote('0')
            );
            $result = $this->model->select($sql);
            $edge = $result ? ($result->getRowArray() ?: []) : [];
            if (empty($edge)) {
                return $this->notFound('连线不存在');
            }

            $defId = (int) $edge['流程定义ID'];
            $newSource = (string) ($data['源节点编码'] ?? $edge['源节点编码']);
            $newTarget = (string) ($data['目标节点编码'] ?? $edge['目标节点编码']);

            // 校验源/目标节点存在
            foreach ([$newSource => '源节点', $newTarget => '目标节点'] as $code => $label) {
                $sql = sprintf(
                    'select `GUID` from `def_workflow_node`
                    where `流程定义ID`=%d and `节点编码`=%s and `删除标识`=%s limit 1',
                    $defId,
                    $this->model->quote($code),
                    $this->model->quote('0')
                );
                $result = $this->model->select($sql);
                if (!$result || !$result->getRowArray()) {
                    return $this->businessError($label . '不存在:' . $code);
                }
            }

            // 校验连线不重复(排除自身)
            $sql = sprintf(
                'select `GUID` from `def_workflow_edge`
                where `流程定义ID`=%d and `源节点编码`=%s and `目标节点编码`=%s and `删除标识`=%s and `GUID`<>%d limit 1',
                $defId,
                $this->model->quote($newSource),
                $this->model->quote($newTarget),
                $this->model->quote('0'),
                $edgeId
            );
            $result = $this->model->select($sql);
            if ($result && $result->getRowArray()) {
                return $this->businessError('连线已存在:' . $newSource . ' -> ' . $newTarget);
            }

            $updates = [];
            $allowedFields = ['源节点编码', '目标节点编码', '条件表达式', '条件描述', '排序'];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $value = $data[$field];
                    if ($value === null || $value === '') {
                        $updates[] = sprintf('`%s`=null', $field);
                    } else {
                        $updates[] = sprintf('`%s`=%s', $field, $this->model->quote((string) $value));
                    }
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
                'update `def_workflow_edge` set %s where `GUID`=%d',
                implode(', ', $updates),
                $edgeId
            );
            $this->model->exec($sql);

            return $this->success(['updated' => true], '更新连线成功');
        } catch (\Throwable $e) {
            log_message('error', '[WorkflowApi::edgeUpdate] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function edgeDelete()
    {
        try {
            $data = $this->getJsonInput();

            if ($error = $this->requireParam($data, 'edgeId')) {
                return $error;
            }

            $edgeId = (int) $data['edgeId'];
            $now = date('Y-m-d H:i:s');
            $operator = $this->getUserWorkId();

            $sql = sprintf(
                'update `def_workflow_edge`
                set `删除标识`=%s, `更新人`=%s, `更新时间`=%s, `操作人员`=%s, `操作时间`=%s
                where `GUID`=%d and `删除标识`=%s',
                $this->model->quote('1'),
                $this->model->quote($operator),
                $this->model->quote($now),
                $this->model->quote($operator),
                $this->model->quote($now),
                $edgeId,
                $this->model->quote('0')
            );
            $this->model->exec($sql);

            return $this->success(['deleted' => true], '删除连线成功');
        } catch (\Throwable $e) {
            log_message('error', '[WorkflowApi::edgeDelete] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    // ============ 节点模板(NodeTemplate)CRUD ============

    public function templateList()
    {
        try {
            $data = $this->getJsonInput();
            $businessType = $data['businessType'] ?? $this->request->getGet('businessType') ?? '';
            $keyword = $data['keyword'] ?? $this->request->getGet('keyword') ?? '';

            $where = ['`删除标识`=' . $this->model->quote('0')];
            $params = [];

            if (!empty($businessType)) {
                // 适用业务类型为逗号分隔字段,使用 FIND_IN_SET 或 LIKE
                $where[] = sprintf(
                    '(`适用业务类型` IS NULL OR `适用业务类型`=%s OR FIND_IN_SET(%s, `适用业务类型`) > 0)',
                    $this->model->quote(''),
                    $this->model->quote($businessType)
                );
            }

            if (!empty($keyword)) {
                $where[] = sprintf(
                    '(`模板编码` LIKE %s OR `模板名称` LIKE %s)',
                    $this->model->quote('%' . $keyword . '%'),
                    $this->model->quote('%' . $keyword . '%')
                );
            }

            $sql = sprintf(
                'select * from `def_workflow_node_template`
                where %s
                order by `GUID` desc',
                implode(' and ', $where)
            );
            $result = $this->model->select($sql);
            $list = $result ? $result->getResultArray() : [];

            return $this->success(['list' => $list, 'total' => count($list)]);
        } catch (\Throwable $e) {
            log_message('error', '[WorkflowApi::templateList] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function templateCreate()
    {
        try {
            $data = $this->getJsonInput();

            if ($error = $this->requireParams($data, ['模板编码', '模板名称', '节点类型'])) {
                return $error;
            }

            $templateCode = (string) $data['模板编码'];

            // 校验模板编码唯一
            $sql = sprintf(
                'select `GUID` from `def_workflow_node_template`
                where `模板编码`=%s and `删除标识`=%s limit 1',
                $this->model->quote($templateCode),
                $this->model->quote('0')
            );
            $result = $this->model->select($sql);
            if ($result && $result->getRowArray()) {
                return $this->businessError('模板编码已存在:' . $templateCode);
            }

            $approverType = $data['审批人类型'] ?? null;
            $approverConfig = $data['审批人配置'] ?? null;
            if (!empty($approverConfig) && !is_string($approverConfig)) {
                $approverConfig = json_encode($approverConfig, JSON_UNESCAPED_UNICODE);
            }

            $now = date('Y-m-d H:i:s');
            $operator = $this->getUserWorkId();

            $sql = sprintf(
                'insert into `def_workflow_node_template`
                (`模板编码`, `模板名称`, `节点类型`,
                 `审批人类型`, `审批人配置`, `会签或签`,
                 `超时天数`, `超时处理`,
                 `适用业务类型`, `模板说明`,
                 `操作来源`, `操作人员`, `操作时间`,
                 `创建人`, `创建时间`, `更新人`, `更新时间`)
                values (%s, %s, %s, %s, %s, %s, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
                $this->model->quote($templateCode),
                $this->model->quote((string) $data['模板名称']),
                $this->model->quote((string) $data['节点类型']),
                $approverType ? $this->model->quote((string) $approverType) : 'null',
                $approverConfig ? $this->model->quote($approverConfig) : 'null',
                $this->model->quote((string) ($data['会签或签'] ?? 'OR')),
                (int) ($data['超时天数'] ?? 0),
                $this->model->quote((string) ($data['超时处理'] ?? 'NOTIFY')),
                !empty($data['适用业务类型']) ? $this->model->quote((string) $data['适用业务类型']) : 'null',
                !empty($data['模板说明']) ? $this->model->quote((string) $data['模板说明']) : 'null',
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
            $templateId = (int) ($row['id'] ?? 0);

            return $this->success(['templateId' => $templateId], '创建模板成功');
        } catch (\Throwable $e) {
            log_message('error', '[WorkflowApi::templateCreate] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function templateUpdate()
    {
        try {
            $data = $this->getJsonInput();

            if ($error = $this->requireParam($data, 'templateId')) {
                return $error;
            }

            $templateId = (int) $data['templateId'];
            $now = date('Y-m-d H:i:s');
            $operator = $this->getUserWorkId();

            // 检查模板存在
            $sql = sprintf(
                'select `GUID`, `模板编码` from `def_workflow_node_template`
                where `GUID`=%d and `删除标识`=%s limit 1',
                $templateId,
                $this->model->quote('0')
            );
            $result = $this->model->select($sql);
            $template = $result ? ($result->getRowArray() ?: []) : [];
            if (empty($template)) {
                return $this->notFound('模板不存在');
            }

            // 若修改模板编码,校验不重复
            if (isset($data['模板编码']) && $data['模板编码'] !== $template['模板编码']) {
                $sql = sprintf(
                    'select `GUID` from `def_workflow_node_template`
                    where `模板编码`=%s and `删除标识`=%s and `GUID`<>%d limit 1',
                    $this->model->quote((string) $data['模板编码']),
                    $this->model->quote('0'),
                    $templateId
                );
                $result = $this->model->select($sql);
                if ($result && $result->getRowArray()) {
                    return $this->businessError('模板编码已存在:' . $data['模板编码']);
                }
            }

            $updates = [];
            $allowedFields = ['模板编码', '模板名称', '节点类型', '审批人类型', '审批人配置', '会签或签', '超时天数', '超时处理', '适用业务类型', '模板说明'];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $value = $data[$field];
                    if ($field === '审批人配置' && !empty($value) && !is_string($value)) {
                        $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                    }
                    if ($value === null || $value === '') {
                        $updates[] = sprintf('`%s`=null', $field);
                    } else {
                        $updates[] = sprintf('`%s`=%s', $field, $this->model->quote((string) $value));
                    }
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
                'update `def_workflow_node_template` set %s where `GUID`=%d',
                implode(', ', $updates),
                $templateId
            );
            $this->model->exec($sql);

            return $this->success(['updated' => true], '更新模板成功');
        } catch (\Throwable $e) {
            log_message('error', '[WorkflowApi::templateUpdate] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function templateDelete()
    {
        try {
            $data = $this->getJsonInput();

            if ($error = $this->requireParam($data, 'templateId')) {
                return $error;
            }

            $templateId = (int) $data['templateId'];
            $now = date('Y-m-d H:i:s');
            $operator = $this->getUserWorkId();

            $sql = sprintf(
                'update `def_workflow_node_template`
                set `删除标识`=%s, `更新人`=%s, `更新时间`=%s, `操作人员`=%s, `操作时间`=%s
                where `GUID`=%d and `删除标识`=%s',
                $this->model->quote('1'),
                $this->model->quote($operator),
                $this->model->quote($now),
                $this->model->quote($operator),
                $this->model->quote($now),
                $templateId,
                $this->model->quote('0')
            );
            $this->model->exec($sql);

            return $this->success(['deleted' => true], '删除模板成功');
        } catch (\Throwable $e) {
            log_message('error', '[WorkflowApi::templateDelete] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }
}
