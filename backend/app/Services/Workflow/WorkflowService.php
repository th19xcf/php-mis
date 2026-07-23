<?php

namespace App\Services\Workflow;

use App\Models\Mcommon;

class WorkflowService
{
    private Mcommon $model;

    public function __construct()
    {
        $this->model = new Mcommon();
    }

    public function startProcess(
        string $workflowCode,
        string $businessType,
        string $businessId,
        string $businessTitle,
        string $sponsor,
        string $sponsorName,
        array $variables = []
    ): array {
        $sql = sprintf(
            'select * from `def_workflow_definition`
            where `流程编码`=%s and `流程状态`=%s
            order by `版本号` desc limit 1',
            $this->model->quote($workflowCode),
            $this->model->quote('ACTIVE')
        );
        $result = $this->model->select($sql);
        $definition = $result ? ($result->getRowArray() ?: []) : [];
        if (empty($definition)) {
            throw new \RuntimeException('未找到启用的流程定义：' . $workflowCode);
        }

        $defId = (int) $definition['GUID'];

        $sql = sprintf(
            'select * from `def_workflow_node`
            where `流程定义ID`=%d
            order by `排序`',
            $defId
        );
        $result = $this->model->select($sql);
        $nodes = $result ? $result->getResultArray() : [];

        $startNode = null;
        foreach ($nodes as $node) {
            if (($node['节点类型'] ?? '') === 'START') {
                $startNode = $node;
                break;
            }
        }
        if (!$startNode) {
            throw new \RuntimeException('流程定义缺少 START 节点');
        }

        $startNodeCode = $startNode['节点编码'];
        $nextNodeCode = $this->findNextNode($defId, $startNodeCode, $variables);
        if (!$nextNodeCode) {
            throw new \RuntimeException('无法找到第一个审批节点');
        }

        $sponsorDept = '';
        $sql = sprintf(
            'select `员工部门编码`, `员工部门全称` from `def_user`
            where `工号`=%s and `有效标识`=%s limit 1',
            $this->model->quote($sponsor),
            $this->model->quote('1')
        );
        $result = $this->model->select($sql);
        $user = $result ? ($result->getRowArray() ?: []) : [];
        if (!empty($user)) {
            $sponsorDept = $user['员工部门编码'] ?? '';
        }

        $variablesJson = json_encode($variables, JSON_UNESCAPED_UNICODE);
        $now = date('Y-m-d H:i:s');

        $sql = sprintf(
            'insert into `def_workflow_instance`
            (`流程定义ID`, `流程版本`, `业务类型`, `业务ID`, `业务标题`,
             `实例状态`, `当前节点编码`, `发起人`, `发起人姓名`,
             `发起时间`, `流程变量`,
             `操作来源`, `操作人员`, `操作时间`,
             `创建人`, `创建时间`, `更新人`, `更新时间`)
            values (%d, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
            $defId,
            (int) ($definition['版本号'] ?? 1),
            $this->model->quote($businessType),
            $this->model->quote($businessId),
            $this->model->quote($businessTitle),
            $this->model->quote('RUNNING'),
            $this->model->quote($nextNodeCode),
            $this->model->quote($sponsor),
            $this->model->quote($sponsorName),
            $this->model->quote($now),
            $this->model->quote($variablesJson),
            $this->model->quote('SYSTEM'),
            $this->model->quote($sponsor),
            $this->model->quote($now),
            $this->model->quote($sponsor),
            $this->model->quote($now),
            $this->model->quote($sponsor),
            $this->model->quote($now)
        );
        $this->model->exec($sql);

        $sql = 'select last_insert_id() as `id`';
        $result = $this->model->select($sql);
        $row = $result ? ($result->getRowArray() ?: []) : [];
        $instanceId = (int) ($row['id'] ?? 0);
        if ($instanceId <= 0) {
            throw new \RuntimeException('创建流程实例失败');
        }

        $tasks = $this->createTasksForNode($instanceId, $nextNodeCode);

        return [
            'instanceId' => $instanceId,
            'currentNode' => $nextNodeCode,
            'tasks' => $tasks,
        ];
    }

    public function approve(
        int $taskId,
        string $approver,
        string $approverName,
        string $opinion,
        string $action = 'APPROVE'
    ): array {
        $sql = sprintf(
            'select * from `def_workflow_task`
            where `GUID`=%d limit 1',
            $taskId
        );
        $result = $this->model->select($sql);
        $task = $result ? ($result->getRowArray() ?: []) : [];
        if (empty($task)) {
            throw new \RuntimeException('任务不存在');
        }
        if (($task['任务状态'] ?? '') !== 'PENDING') {
            throw new \RuntimeException('任务状态不是待处理');
        }
        if (($task['处理人'] ?? '') !== $approver) {
            throw new \RuntimeException('无权处理此任务');
        }

        $instanceId = (int) $task['实例ID'];
        $nodeCode = $task['节点编码'] ?? '';

        $sql = sprintf(
            'select * from `def_workflow_instance`
            where `GUID`=%d limit 1',
            $instanceId
        );
        $result = $this->model->select($sql);
        $instance = $result ? ($result->getRowArray() ?: []) : [];
        if (empty($instance)) {
            throw new \RuntimeException('流程实例不存在');
        }
        if (($instance['实例状态'] ?? '') !== 'RUNNING') {
            throw new \RuntimeException('流程不是运行中状态');
        }

        $now = date('Y-m-d H:i:s');
        $actionResult = ($action === 'APPROVE') ? 'APPROVE' : 'REJECT';

        $sql = sprintf(
            'update `def_workflow_task`
            set `任务状态`=%s, `处理结果`=%s, `处理意见`=%s,
                `处理时间`=%s, `操作来源`=%s, `操作人员`=%s, `操作时间`=%s,
                `更新人`=%s, `更新时间`=%s
            where `GUID`=%d',
            $this->model->quote('DONE'),
            $this->model->quote($actionResult),
            $this->model->quote($opinion),
            $this->model->quote($now),
            $this->model->quote('SYSTEM'),
            $this->model->quote($approver),
            $this->model->quote($now),
            $this->model->quote($approver),
            $this->model->quote($now),
            $taskId
        );
        $this->model->exec($sql);

        $this->addTaskLog($taskId, $instanceId, $nodeCode, $approver, $approverName, $actionResult, $opinion);

        $newTasks = [];
        $instanceStatus = $instance['实例状态'];

        if ($action === 'REJECT') {
            $sql = sprintf(
                'update `def_workflow_instance`
                set `实例状态`=%s, `结束时间`=%s, `更新人`=%s, `更新时间`=%s
                where `GUID`=%d',
                $this->model->quote('TERMINATED'),
                $this->model->quote($now),
                $this->model->quote($approver),
                $this->model->quote($now),
                $instanceId
            );
            $this->model->exec($sql);
            $instanceStatus = 'TERMINATED';
        } else {
            $nodeApproved = $this->checkNodeApproved($instanceId, $nodeCode);
            if ($nodeApproved) {
                $variables = json_decode($instance['流程变量'] ?? '[]', true) ?: [];
                $nextNodeCode = $this->findNextNode(
                    (int) $instance['流程定义ID'],
                    $nodeCode,
                    $variables
                );

                if (!$nextNodeCode || $nextNodeCode === 'END') {
                    $sql = sprintf(
                        'update `def_workflow_instance`
                        set `实例状态`=%s, `当前节点编码`=%s, `结束时间`=%s,
                            `更新人`=%s, `更新时间`=%s
                        where `GUID`=%d',
                        $this->model->quote('COMPLETED'),
                        $this->model->quote('END'),
                        $this->model->quote($now),
                        $this->model->quote($approver),
                        $this->model->quote($now),
                        $instanceId
                    );
                    $this->model->exec($sql);
                    $instanceStatus = 'COMPLETED';
                } else {
                    $sql = sprintf(
                        'update `def_workflow_instance`
                        set `当前节点编码`=%s, `更新人`=%s, `更新时间`=%s
                        where `GUID`=%d',
                        $this->model->quote($nextNodeCode),
                        $this->model->quote($approver),
                        $this->model->quote($now),
                        $instanceId
                    );
                    $this->model->exec($sql);
                    $newTasks = $this->createTasksForNode($instanceId, $nextNodeCode);
                }
            }
        }

        return [
            'instanceId' => $instanceId,
            'instanceStatus' => $instanceStatus,
            'newTasks' => $newTasks,
        ];
    }

    public function getPendingTasks(string $approver, int $page = 1, int $pageSize = 20): array
    {
        $offset = ($page - 1) * $pageSize;

        $countSql = sprintf(
            'select count(*) as `total`
            from `def_workflow_task` t
            inner join `def_workflow_instance` i on t.`实例ID` = i.`GUID`
            where t.`处理人`=%s and t.`任务状态`=%s and t.`删除标识`=%s',
            $this->model->quote($approver),
            $this->model->quote('PENDING'),
            $this->model->quote('0')
        );
        $result = $this->model->select($countSql);
        $row = $result ? ($result->getRowArray() ?: []) : [];
        $total = (int) ($row['total'] ?? 0);

        $listSql = sprintf(
            'select t.`GUID` as `任务ID`, t.`节点编码`, t.`节点名称`, t.`处理人`,
                   t.`处理人姓名`, t.`任务状态`, t.`创建时间`, t.`任务类型`,
                   i.`GUID` as `实例ID`, i.`业务类型`,
                   i.`业务ID`, i.`业务标题`, i.`发起人`, i.`发起人姓名`,
                   i.`实例状态`
            from `def_workflow_task` t
            inner join `def_workflow_instance` i on t.`实例ID` = i.`GUID`
            where t.`处理人`=%s and t.`任务状态`=%s and t.`删除标识`=%s
            order by t.`创建时间` desc
            limit %d offset %d',
            $this->model->quote($approver),
            $this->model->quote('PENDING'),
            $this->model->quote('0'),
            $pageSize,
            $offset
        );
        $result = $this->model->select($listSql);
        $list = $result ? $result->getResultArray() : [];

        return [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ];
    }

    public function getDoneTasks(string $approver, int $page = 1, int $pageSize = 20): array
    {
        $offset = ($page - 1) * $pageSize;

        $countSql = sprintf(
            'select count(*) as `total`
            from `def_workflow_task` t
            inner join `def_workflow_instance` i on t.`实例ID` = i.`GUID`
            where t.`处理人`=%s and t.`任务状态`=%s and t.`删除标识`=%s',
            $this->model->quote($approver),
            $this->model->quote('DONE'),
            $this->model->quote('0')
        );
        $result = $this->model->select($countSql);
        $row = $result ? ($result->getRowArray() ?: []) : [];
        $total = (int) ($row['total'] ?? 0);

        $listSql = sprintf(
            'select t.`GUID` as `任务ID`, t.`节点编码`, t.`节点名称`, t.`处理人`,
                   t.`处理人姓名`, t.`任务状态`, t.`处理结果`, t.`处理意见`,
                   t.`创建时间`, t.`处理时间`, t.`任务类型`,
                   i.`GUID` as `实例ID`, i.`业务类型`,
                   i.`业务ID`, i.`业务标题`, i.`发起人`, i.`发起人姓名`,
                   i.`实例状态`
            from `def_workflow_task` t
            inner join `def_workflow_instance` i on t.`实例ID` = i.`GUID`
            where t.`处理人`=%s and t.`任务状态`=%s and t.`删除标识`=%s
            order by t.`处理时间` desc
            limit %d offset %d',
            $this->model->quote($approver),
            $this->model->quote('DONE'),
            $this->model->quote('0'),
            $pageSize,
            $offset
        );
        $result = $this->model->select($listSql);
        $list = $result ? $result->getResultArray() : [];

        return [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ];
    }

    public function getMyInstances(string $sponsor, int $page = 1, int $pageSize = 20): array
    {
        $offset = ($page - 1) * $pageSize;

        $countSql = sprintf(
            'select count(*) as `total`
            from `def_workflow_instance`
            where `发起人`=%s and `删除标识`=%s',
            $this->model->quote($sponsor),
            $this->model->quote('0')
        );
        $result = $this->model->select($countSql);
        $row = $result ? ($result->getRowArray() ?: []) : [];
        $total = (int) ($row['total'] ?? 0);

        $listSql = sprintf(
            'select `GUID`, `业务类型`, `业务ID`, `业务标题`,
                   `发起人`, `发起人姓名`, `实例状态`, `当前节点编码`,
                   `创建时间`, `结束时间`, `发起时间`
            from `def_workflow_instance`
            where `发起人`=%s and `删除标识`=%s
            order by `创建时间` desc
            limit %d offset %d',
            $this->model->quote($sponsor),
            $this->model->quote('0'),
            $pageSize,
            $offset
        );
        $result = $this->model->select($listSql);
        $list = $result ? $result->getResultArray() : [];

        return [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ];
    }

    public function getInstanceDetail(int $instanceId): array
    {
        $sql = sprintf(
            'select * from `def_workflow_instance` where `GUID`=%d limit 1',
            $instanceId
        );
        $result = $this->model->select($sql);
        $instance = $result ? ($result->getRowArray() ?: []) : [];
        if (empty($instance)) {
            return [];
        }

        $sql = sprintf(
            'select * from `def_workflow_task`
            where `实例ID`=%d and `删除标识`=%s
            order by `创建时间` asc',
            $instanceId,
            $this->model->quote('0')
        );
        $result = $this->model->select($sql);
        $tasks = $result ? $result->getResultArray() : [];

        $sql = sprintf(
            'select * from `def_workflow_task_log`
            where `实例ID`=%d
            order by `操作时间` asc',
            $instanceId
        );
        $result = $this->model->select($sql);
        $logs = $result ? $result->getResultArray() : [];

        $timeline = [];
        foreach ($logs as $log) {
            $timeline[] = [
                'taskId' => $log['任务ID'] ?? null,
                'nodeCode' => $log['节点编码'] ?? '',
                'operator' => $log['操作人'] ?? '',
                'operatorName' => $log['操作人姓名'] ?? '',
                'action' => $log['动作类型'] ?? '',
                'remark' => $log['备注'] ?? '',
                'time' => $log['操作时间'] ?? '',
                'ip' => $log['操作IP'] ?? '',
            ];
        }

        $instance['tasks'] = $tasks;
        $instance['timeline'] = $timeline;
        $instance['variables'] = json_decode($instance['流程变量'] ?? '[]', true) ?: [];

        return $instance;
    }

    public function withdraw(int $instanceId, string $sponsor): bool
    {
        $sql = sprintf(
            'select * from `def_workflow_instance` where `GUID`=%d limit 1',
            $instanceId
        );
        $result = $this->model->select($sql);
        $instance = $result ? ($result->getRowArray() ?: []) : [];
        if (empty($instance)) {
            throw new \RuntimeException('流程实例不存在');
        }
        if (($instance['发起人'] ?? '') !== $sponsor) {
            throw new \RuntimeException('只有发起人可以撤回');
        }
        if (($instance['实例状态'] ?? '') !== 'RUNNING') {
            throw new \RuntimeException('只有运行中的流程可以撤回');
        }

        $now = date('Y-m-d H:i:s');

        $sql = sprintf(
            'update `def_workflow_task`
            set `任务状态`=%s, `更新人`=%s, `更新时间`=%s
            where `实例ID`=%d and `任务状态`=%s and `删除标识`=%s',
            $this->model->quote('WITHDRAWN'),
            $this->model->quote($sponsor),
            $this->model->quote($now),
            $instanceId,
            $this->model->quote('PENDING'),
            $this->model->quote('0')
        );
        $this->model->exec($sql);

        $sql = sprintf(
            'update `def_workflow_instance`
            set `实例状态`=%s, `更新人`=%s, `更新时间`=%s
            where `GUID`=%d',
            $this->model->quote('TERMINATED'),
            $this->model->quote($sponsor),
            $this->model->quote($now),
            $instanceId
        );
        $this->model->exec($sql);

        $this->addTaskLog(0, $instanceId, '', $sponsor, $instance['发起人姓名'] ?? '', 'WITHDRAW', '发起人撤回');

        return true;
    }

    private function createTasksForNode(int $instanceId, string $nodeCode): array
    {
        $sql = sprintf(
            'select i.`流程定义ID`, i.`发起人`, i.`发起人姓名`
            from `def_workflow_instance` i
            where i.`GUID`=%d limit 1',
            $instanceId
        );
        $result = $this->model->select($sql);
        $instance = $result ? ($result->getRowArray() ?: []) : [];
        if (empty($instance)) {
            return [];
        }

        $defId = (int) $instance['流程定义ID'];
        $sponsor = $instance['发起人'] ?? '';
        $sponsorName = $instance['发起人姓名'] ?? '';

        $sql = sprintf(
            'select * from `def_workflow_node`
            where `流程定义ID`=%d and `节点编码`=%s and `删除标识`=%s limit 1',
            $defId,
            $this->model->quote($nodeCode),
            $this->model->quote('0')
        );
        $result = $this->model->select($sql);
        $node = $result ? ($result->getRowArray() ?: []) : [];
        if (empty($node)) {
            return [];
        }

        $nodeType = $node['节点类型'] ?? '';
        if ($nodeType === 'END' || $nodeType === 'START') {
            return [];
        }

        $sponsorDept = '';
        if ($sponsor) {
            $sql = sprintf(
                'select `员工部门编码` from `def_user`
                where `工号`=%s and `有效标识`=%s limit 1',
                $this->model->quote($sponsor),
                $this->model->quote('1')
            );
            $result = $this->model->select($sql);
            $u = $result ? ($result->getRowArray() ?: []) : [];
            $sponsorDept = $u['员工部门编码'] ?? '';
        }

        $approvers = $this->resolveApprovers($node, $sponsor, $sponsorName, $sponsorDept);
        if (empty($approvers)) {
            return [];
        }

        $now = date('Y-m-d H:i:s');
        $tasks = [];
        $taskType = $nodeType === 'CC' ? 'CC' : 'APPROVAL';

        foreach ($approvers as $approver) {
            $workId = $approver['work_id'] ?? '';
            $userName = $approver['user_name'] ?? '';
            if (!$workId) {
                continue;
            }

            $sql = sprintf(
                'insert into `def_workflow_task`
                (`实例ID`, `节点编码`, `节点名称`, `任务类型`,
                 `处理人`, `处理人姓名`, `任务状态`,
                 `操作来源`, `操作人员`, `操作时间`,
                 `创建人`, `创建时间`, `更新人`, `更新时间`)
                values (%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
                $instanceId,
                $this->model->quote($nodeCode),
                $this->model->quote($node['节点名称'] ?? ''),
                $this->model->quote($taskType),
                $this->model->quote($workId),
                $this->model->quote($userName),
                $this->model->quote('PENDING'),
                $this->model->quote('SYSTEM'),
                $this->model->quote($sponsor),
                $this->model->quote($now),
                $this->model->quote($sponsor),
                $this->model->quote($now),
                $this->model->quote($sponsor),
                $this->model->quote($now)
            );
            $this->model->exec($sql);

            $sql = 'select last_insert_id() as `id`';
            $result = $this->model->select($sql);
            $row = $result ? ($result->getRowArray() ?: []) : [];
            $taskId = (int) ($row['id'] ?? 0);

            if ($taskId > 0) {
                $tasks[] = [
                    'taskId' => $taskId,
                    'instanceId' => $instanceId,
                    'nodeCode' => $nodeCode,
                    'nodeName' => $node['节点名称'] ?? '',
                    'taskType' => $taskType,
                    'approver' => $workId,
                    'approverName' => $userName,
                    'status' => 'PENDING',
                ];
            }
        }

        return $tasks;
    }

    private function findNextNode(int $workflowDefId, string $currentNodeCode, array $variables): ?string
    {
        $sql = sprintf(
            'select * from `def_workflow_edge`
            where `流程定义ID`=%d and `源节点编码`=%s and `删除标识`=%s
            order by `排序` asc',
            $workflowDefId,
            $this->model->quote($currentNodeCode),
            $this->model->quote('0')
        );
        $result = $this->model->select($sql);
        $edges = $result ? $result->getResultArray() : [];
        if (empty($edges)) {
            return null;
        }

        $defaultEdge = null;
        foreach ($edges as $edge) {
            $condition = $edge['条件表达式'] ?? '';
            $targetNode = $edge['目标节点编码'] ?? '';

            if ($condition === '' || $condition === null) {
                $defaultEdge = $targetNode;
                continue;
            }

            $matched = $this->evaluateCondition($condition, $variables);
            if ($matched) {
                return $targetNode;
            }
        }

        return $defaultEdge;
    }

    private function evaluateCondition(string $condition, array $variables): bool
    {
        if ($condition === '') {
            return true;
        }

        $expr = trim($condition);
        foreach ($variables as $key => $value) {
            if (is_string($value)) {
                $quoted = '"' . addslashes($value) . '"';
                $expr = str_replace('${' . $key . '}', $quoted, $expr);
            } elseif (is_numeric($value)) {
                $expr = str_replace('${' . $key . '}', (string) $value, $expr);
            } elseif (is_bool($value)) {
                $expr = str_replace('${' . $key . '}', $value ? 'true' : 'false', $expr);
            }
        }

        if (preg_match('/^[\w\s\d\.\+\-\*\/\(\)\'\"=!<>&|%>=<,]+$/u', $expr)) {
            try {
                $result = @eval('return (' . $expr . ');');
                return (bool) $result;
            } catch (\Throwable $e) {
                return false;
            }
        }

        return false;
    }

    private function resolveApprovers(
        array $nodeConfig,
        string $sponsor,
        string $sponsorName,
        string $deptCode
    ): array {
        $approverType = $nodeConfig['审批人类型'] ?? '';
        $approverConfig = $nodeConfig['审批人配置'] ?? '';
        $approvers = [];

        switch ($approverType) {
            case 'ROLE':
                $approvers = $this->getApproversByRole($approverConfig);
                break;
            case 'DEPT':
                $dept = $approverConfig ?: $deptCode;
                $approvers = $this->getApproversByDept($dept);
                break;
            case 'SUPERIOR':
                $approvers = $this->getApproversBySuperior($sponsor);
                break;
            case 'ASSIGN':
                $approvers = $this->getApproversByAssign($approverConfig);
                break;
            case 'SPONSOR':
                $approvers[] = [
                    'work_id' => $sponsor,
                    'user_name' => $sponsorName,
                ];
                break;
            default:
                break;
        }

        return $approvers;
    }

    private function getApproversByRole(string $roleConfig): array
    {
        if (!$roleConfig) {
            return [];
        }

        $roles = json_decode($roleConfig, true);
        if (!is_array($roles)) {
            $roles = array_filter(array_map('trim', explode(',', $roleConfig)));
        }
        if (empty($roles)) {
            return [];
        }

        $quotedRoles = implode(',', array_map(
            fn($r) => $this->model->quote(trim($r)),
            $roles
        ));

        $sql = sprintf(
            'select distinct u.`工号` as `work_id`, u.`姓名` as `user_name`
            from `def_user` u
            inner join `def_role_group` rg on find_in_set(rg.`角色组`, u.`角色组`) > 0
            where u.`有效标识`=%s and rg.`有效标识`=%s
            and rg.`角色编码` in (%s)
            union
            select distinct u.`工号` as `work_id`, u.`姓名` as `user_name`
            from `def_user` u
            where u.`有效标识`=%s
            and find_in_set(u.`角色编码`, %s) > 0',
            $this->model->quote('1'),
            $this->model->quote('1'),
            $quotedRoles,
            $this->model->quote('1'),
            $quotedRoles
        );

        $result = $this->model->select($sql);
        $rows = $result ? $result->getResultArray() : [];

        $seen = [];
        $approvers = [];
        foreach ($rows as $row) {
            $wid = $row['work_id'] ?? '';
            if ($wid && !isset($seen[$wid])) {
                $seen[$wid] = true;
                $approvers[] = $row;
            }
        }

        return $approvers;
    }

    private function getApproversByDept(string $deptCode): array
    {
        if (!$deptCode) {
            return [];
        }

        $sql = sprintf(
            'select `工号` as `work_id`, `姓名` as `user_name`
            from `def_user`
            where `员工部门编码`=%s and `有效标识`=%s',
            $this->model->quote($deptCode),
            $this->model->quote('1')
        );
        $result = $this->model->select($sql);
        return $result ? $result->getResultArray() : [];
    }

    private function getApproversBySuperior(string $sponsor): array
    {
        if (!$sponsor) {
            return [];
        }

        $sql = sprintf(
            'select `姓名` from `def_user` where `工号`=%s and `有效标识`=%s limit 1',
            $this->model->quote($sponsor),
            $this->model->quote('1')
        );
        $result = $this->model->select($sql);
        $row = $result ? ($result->getRowArray() ?: []) : [];
        if (empty($row)) {
            return [];
        }

        return [
            [
                'work_id' => 'admin',
                'user_name' => '系统管理员',
            ]
        ];
    }

    private function getApproversByAssign(string $assignConfig): array
    {
        if (!$assignConfig) {
            return [];
        }

        $workIds = json_decode($assignConfig, true);
        if (!is_array($workIds)) {
            $workIds = array_filter(array_map('trim', explode(',', $assignConfig)));
        }
        if (empty($workIds)) {
            return [];
        }

        $quotedIds = implode(',', array_map(
            fn($id) => $this->model->quote(trim($id)),
            $workIds
        ));

        $sql = sprintf(
            'select `工号` as `work_id`, `姓名` as `user_name`
            from `def_user`
            where `工号` in (%s) and `有效标识`=%s',
            $quotedIds,
            $this->model->quote('1')
        );
        $result = $this->model->select($sql);
        return $result ? $result->getResultArray() : [];
    }

    private function checkNodeApproved(int $instanceId, string $nodeCode): bool
    {
        $sql = sprintf(
            'select n.`会签或签`
            from `def_workflow_node` n
            inner join `def_workflow_instance` i on n.`流程定义ID` = i.`流程定义ID`
            where i.`GUID`=%d and n.`节点编码`=%s and n.`删除标识`=%s limit 1',
            $instanceId,
            $this->model->quote($nodeCode),
            $this->model->quote('0')
        );
        $result = $this->model->select($sql);
        $node = $result ? ($result->getRowArray() ?: []) : [];
        $approvalMode = $node['会签或签'] ?? 'OR';

        $sql = sprintf(
            'select
                sum(case when `任务状态`=%s then 1 else 0 end) as `pending_count`,
                sum(case when `任务状态`=%s and `处理结果`=%s then 1 else 0 end) as `approve_count`,
                sum(case when `任务状态`=%s and `处理结果`=%s then 1 else 0 end) as `reject_count`,
                count(*) as `total_count`
            from `def_workflow_task`
            where `实例ID`=%d and `节点编码`=%s and `删除标识`=%s',
            $this->model->quote('PENDING'),
            $this->model->quote('DONE'),
            $this->model->quote('APPROVE'),
            $this->model->quote('DONE'),
            $this->model->quote('REJECT'),
            $instanceId,
            $this->model->quote($nodeCode),
            $this->model->quote('0')
        );
        $result = $this->model->select($sql);
        $stats = $result ? ($result->getRowArray() ?: []) : [];

        $pendingCount = (int) ($stats['pending_count'] ?? 0);
        $approveCount = (int) ($stats['approve_count'] ?? 0);
        $totalCount = (int) ($stats['total_count'] ?? 0);

        if ($totalCount === 0) {
            return true;
        }

        if ($approvalMode === 'AND') {
            return $pendingCount === 0 && $approveCount === $totalCount;
        } else {
            return $approveCount > 0;
        }
    }

    private function addTaskLog(
        int $taskId,
        int $instanceId,
        string $nodeCode,
        string $operator,
        string $operatorName,
        string $action,
        string $opinion
    ): void {
        $now = date('Y-m-d H:i:s');

        $sql = sprintf(
            'insert into `def_workflow_task_log`
            (`实例ID`, `任务ID`, `节点编码`, `动作类型`,
             `操作人`, `操作人姓名`, `操作时间`,
             `备注`,
             `操作来源`, `操作人员`, `创建人`, `创建时间`)
            values (%d, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
            $instanceId,
            $taskId,
            $this->model->quote($nodeCode),
            $this->model->quote($action),
            $this->model->quote($operator),
            $this->model->quote($operatorName),
            $this->model->quote($now),
            $this->model->quote($opinion),
            $this->model->quote('SYSTEM'),
            $this->model->quote($operator),
            $this->model->quote($operator),
            $this->model->quote($now)
        );
        $this->model->exec($sql);
    }
}
