<?php

namespace App\Services\Workflow;

use App\Models\Mcommon;
use App\Traits\TransactionTrait;

class WorkflowService
{
    use TransactionTrait;

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
            $this->model->quote('启用')
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

        // 实例插入与首节点任务生成纳入同一事务：任一步失败整体回滚，避免产生无任务的孤儿实例
        return $this->withTransaction(function () use ($defId, $definition, $businessType, $businessId, $businessTitle, $sponsor, $sponsorName, $variablesJson, $nextNodeCode, $startNodeCode, $variables) {
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
                $this->model->quote('运行中'),
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

            // last_insert_id 必须走无缓存查询（Mcommon::select 有请求级缓存，同请求重复调用会返回旧值）
            $result = $this->model->query('select last_insert_id() as `id`');
            $row = $result ? ($result->getRowArray() ?: []) : [];
            $instanceId = (int) ($row['id'] ?? 0);
            if ($instanceId <= 0) {
                throw new \RuntimeException('创建流程实例失败');
            }

            // 自 START 起推进：抄送(CC)节点生成抄送任务后自动越过，停驻在首个审批节点
            $advance = $this->advanceChain($instanceId, $defId, $startNodeCode, $variables, $sponsor);

            return [
                'instanceId' => $instanceId,
                'currentNode' => $advance['currentNode'],
                'instanceStatus' => $advance['instanceStatus'],
                'tasks' => $advance['tasks'],
            ];
        });
    }

    public function approve(
        int $taskId,
        string $approver,
        string $approverName,
        string $opinion,
        string $action = '同意'
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
        if (($task['任务状态'] ?? '') !== '待处理') {
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
        if (($instance['实例状态'] ?? '') !== '运行中') {
            throw new \RuntimeException('流程不是运行中状态');
        }

        // 写入阶段整体纳入事务；任务采用条件更新抢占（仅"待处理"状态可被处理），
        // 防止或签节点下两审批人并发处理导致下一节点任务重复生成
        return $this->withTransaction(function () use ($taskId, $approver, $approverName, $opinion, $action, $instance, $instanceId, $nodeCode) {
            $now = date('Y-m-d H:i:s');
            $actionResult = ($action === '同意') ? '同意' : '拒绝';

            $sql = sprintf(
                'update `def_workflow_task`
                set `任务状态`=%s, `处理结果`=%s, `处理意见`=%s,
                    `处理时间`=%s, `操作来源`=%s, `操作人员`=%s, `操作时间`=%s,
                    `更新人`=%s, `更新时间`=%s
                where `GUID`=%d and `任务状态`=%s',
                $this->model->quote('已处理'),
                $this->model->quote($actionResult),
                $this->model->quote($opinion),
                $this->model->quote($now),
                $this->model->quote('SYSTEM'),
                $this->model->quote($approver),
                $this->model->quote($now),
                $this->model->quote($approver),
                $this->model->quote($now),
                $taskId,
                $this->model->quote('待处理')
            );
            if ($this->model->exec($sql) === 0) {
                // 并发抢占失败：任务已被其他请求处理或已撤回
                throw new \RuntimeException('任务已被处理或已撤回，请刷新待办列表');
            }

            $this->addTaskLog($taskId, $instanceId, $nodeCode, $approver, $approverName, $actionResult, $opinion);

            $newTasks = [];
            $instanceStatus = $instance['实例状态'];

            if ($action === '拒绝') {
                $sql = sprintf(
                    'update `def_workflow_instance`
                    set `实例状态`=%s, `结束时间`=%s, `更新人`=%s, `更新时间`=%s
                    where `GUID`=%d',
                    $this->model->quote('已终止'),
                    $this->model->quote($now),
                    $this->model->quote($approver),
                    $this->model->quote($now),
                    $instanceId
                );
                $this->model->exec($sql);
                $instanceStatus = '已终止';
            } else {
                $nodeApproved = $this->checkNodeApproved($instanceId, $nodeCode);
                if ($nodeApproved) {
                    $variables = json_decode($instance['流程变量'] ?? '[]', true) ?: [];
                    // 自当前节点续推：抄送(CC)节点生成抄送任务后自动越过，停驻在下一审批节点
                    $advance = $this->advanceChain(
                        $instanceId,
                        (int) $instance['流程定义ID'],
                        $nodeCode,
                        $variables,
                        $approver
                    );
                    $newTasks = $advance['tasks'];
                    if ($advance['end']) {
                        $instanceStatus = '已完成';
                    }
                }
            }

            return [
                'instanceId' => $instanceId,
                'instanceStatus' => $instanceStatus,
                'newTasks' => $newTasks,
            ];
        });
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
            $this->model->quote('待处理'),
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
            $this->model->quote('待处理'),
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
            $this->model->quote('已处理'),
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
            $this->model->quote('已处理'),
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
        if (($instance['实例状态'] ?? '') !== '运行中') {
            throw new \RuntimeException('只有运行中的流程可以撤回');
        }

        // 已有审批人处理过的流程不可撤回（对齐主流审批语义：仅审批未开始前可撤回）
        // 仅统计审批类任务：抄送(CC)任务的已读回执不阻止撤回
        $sql = sprintf(
            'select count(*) as `cnt`
            from `def_workflow_task`
            where `实例ID`=%d and `删除标识`=%s and `任务类型`=%s and `任务状态`=%s',
            $instanceId,
            $this->model->quote('0'),
            $this->model->quote('APPROVAL'),
            $this->model->quote('已处理')
        );
        $result = $this->model->query($sql);
        $row = $result ? ($result->getRowArray() ?: []) : [];
        if ((int) ($row['cnt'] ?? 0) > 0) {
            throw new \RuntimeException('流程已有审批人处理，无法撤回');
        }

        // 撤回写操作（任务作废 + 实例终止 + 流水）纳入同一事务
        return $this->withTransaction(function () use ($instanceId, $sponsor, $instance) {
            $now = date('Y-m-d H:i:s');

            $sql = sprintf(
                'update `def_workflow_task`
                set `任务状态`=%s, `更新人`=%s, `更新时间`=%s
                where `实例ID`=%d and `任务状态`=%s and `删除标识`=%s',
                $this->model->quote('已撤回'),
                $this->model->quote($sponsor),
                $this->model->quote($now),
                $instanceId,
                $this->model->quote('待处理'),
                $this->model->quote('0')
            );
            $this->model->exec($sql);

            $sql = sprintf(
                'update `def_workflow_instance`
                set `实例状态`=%s, `更新人`=%s, `更新时间`=%s
                where `GUID`=%d',
                $this->model->quote('已终止'),
                $this->model->quote($sponsor),
                $this->model->quote($now),
                $instanceId
            );
            $this->model->exec($sql);

            $this->addTaskLog(0, $instanceId, '', $sponsor, $instance['发起人姓名'] ?? '', '撤回', '发起人撤回');

            return true;
        });
    }

    /**
     * 抄送任务已读确认：仅 CC 类型且待处理的任务可操作。
     * 条件更新抢占防并发重复确认；抄送不阻塞流程推进（推进判定只统计 APPROVAL 任务）。
     */
    public function ackCcTask(int $taskId, string $operator, string $operatorName): bool
    {
        $sql = sprintf(
            'select * from `def_workflow_task` where `GUID`=%d limit 1',
            $taskId
        );
        $result = $this->model->select($sql);
        $task = $result ? ($result->getRowArray() ?: []) : [];
        if (empty($task)) {
            throw new \RuntimeException('任务不存在');
        }
        if (($task['任务类型'] ?? '') !== 'CC') {
            throw new \RuntimeException('仅抄送任务支持已读操作');
        }
        if (($task['任务状态'] ?? '') !== '待处理') {
            throw new \RuntimeException('抄送任务已确认过已读');
        }
        if (($task['处理人'] ?? '') !== $operator) {
            throw new \RuntimeException('无权处理此任务');
        }

        return $this->withTransaction(function () use ($taskId, $task, $operator, $operatorName) {
            $now = date('Y-m-d H:i:s');

            $sql = sprintf(
                'update `def_workflow_task`
                set `任务状态`=%s, `处理结果`=%s, `处理时间`=%s,
                    `操作来源`=%s, `操作人员`=%s, `操作时间`=%s,
                    `更新人`=%s, `更新时间`=%s
                where `GUID`=%d and `任务状态`=%s',
                $this->model->quote('已处理'),
                $this->model->quote('已读'),
                $this->model->quote($now),
                $this->model->quote('SYSTEM'),
                $this->model->quote($operator),
                $this->model->quote($now),
                $this->model->quote($operator),
                $this->model->quote($now),
                $taskId,
                $this->model->quote('待处理')
            );
            if ($this->model->exec($sql) === 0) {
                // 并发抢占失败：任务已被其他请求确认或已随流程撤回作废
                throw new \RuntimeException('任务已被处理或已撤回，请刷新待办列表');
            }

            $this->addTaskLog(
                $taskId,
                (int) $task['实例ID'],
                $task['节点编码'] ?? '',
                $operator,
                $operatorName,
                '抄送',
                '抄送已读'
            );

            return true;
        });
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
                $this->model->quote('待处理'),
                $this->model->quote('SYSTEM'),
                $this->model->quote($sponsor),
                $this->model->quote($now),
                $this->model->quote($sponsor),
                $this->model->quote($now),
                $this->model->quote($sponsor),
                $this->model->quote($now)
            );
            $this->model->exec($sql);

            // last_insert_id 必须走无缓存查询：select 有请求级缓存，
            // 多审批人节点循环生成任务时第二次循环会命中缓存返回上一个任务ID
            $result = $this->model->query('select last_insert_id() as `id`');
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
                    'status' => '待处理',
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
            $targetNode = $edge['目标节点编码'] ?? '';
            $conditionJson = $edge['匹配条件'] ?? '';

            // 无匹配条件的边视为默认流转（多分支场景下兜底）
            if ($conditionJson === '' || $conditionJson === null) {
                $defaultEdge = $targetNode;
                continue;
            }

            $conditions = json_decode($conditionJson, true);
            if (!is_array($conditions) || empty($conditions)) {
                // 条件解析失败，跳过该边
                continue;
            }

            // 结构化条件走安全匹配器（无 eval，防表达式注入）
            if (WorkflowConditionMatcher::matchAll($conditions, $variables)) {
                return $targetNode;
            }
        }

        return $defaultEdge;
    }

    /**
     * 自 fromNodeCode 起沿流程边推进（fromNodeCode 为已完成节点、START 节点或已越过的 CC 节点）：
     * - 抄送(CC)节点：生成抄送任务后自动续推，不阻塞流程
     * - 审批节点（含未识别类型，一律按阻塞语义）：生成审批任务后停驻
     * - 抵达 END（或无边可走）：实例置为已完成
     * 本方法只应在事务闭包内调用（startProcess / approve），环路保护上限 50 跳。
     * 返回 ['end' => bool, 'currentNode' => string, 'instanceStatus' => string, 'tasks' => array]
     */
    private function advanceChain(int $instanceId, int $defId, string $fromNodeCode, array $variables, string $operator): array
    {
        $now = date('Y-m-d H:i:s');
        $allTasks = [];
        $from = $fromNodeCode;

        for ($i = 0; $i < 50; $i++) {
            $next = $this->findNextNode($defId, $from, $variables);

            if (!$next || $next === 'END') {
                $sql = sprintf(
                    'update `def_workflow_instance`
                    set `实例状态`=%s, `当前节点编码`=%s, `结束时间`=%s,
                        `更新人`=%s, `更新时间`=%s
                    where `GUID`=%d',
                    $this->model->quote('已完成'),
                    $this->model->quote('END'),
                    $this->model->quote($now),
                    $this->model->quote($operator),
                    $this->model->quote($now),
                    $instanceId
                );
                $this->model->exec($sql);
                return ['end' => true, 'currentNode' => 'END', 'instanceStatus' => '已完成', 'tasks' => $allTasks];
            }

            $sql = sprintf(
                'update `def_workflow_instance`
                set `当前节点编码`=%s, `更新人`=%s, `更新时间`=%s
                where `GUID`=%d',
                $this->model->quote($next),
                $this->model->quote($operator),
                $this->model->quote($now),
                $instanceId
            );
            $this->model->exec($sql);

            if ($this->getNodeType($defId, $next) === 'CC') {
                // 抄送节点不阻塞推进：生成抄送任务后继续找下一节点
                $ccTasks = $this->createTasksForNode($instanceId, $next);
                $allTasks = array_merge($allTasks, $ccTasks);
                $from = $next;
                continue;
            }

            // 审批节点：生成任务后停驻，等待审批人处理
            $newTasks = $this->createTasksForNode($instanceId, $next);
            $allTasks = array_merge($allTasks, $newTasks);
            return ['end' => false, 'currentNode' => $next, 'instanceStatus' => '运行中', 'tasks' => $allTasks];
        }

        throw new \RuntimeException('流程推进链路过深（超过50跳），疑似流程定义存在环路');
    }

    /**
     * 查询节点类型（节点不存在时返回空串，调用方按阻塞语义处理）
     */
    private function getNodeType(int $defId, string $nodeCode): string
    {
        $sql = sprintf(
            'select `节点类型` from `def_workflow_node`
            where `流程定义ID`=%d and `节点编码`=%s and `删除标识`=%s limit 1',
            $defId,
            $this->model->quote($nodeCode),
            $this->model->quote('0')
        );
        $result = $this->model->select($sql);
        $row = $result ? ($result->getRowArray() ?: []) : [];
        return $row['节点类型'] ?? '';
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

    /**
     * SUPERIOR 审批人解析：发起人所在部门的负责人
     *
     * 解析链：def_user.员工部门编码 → def_dept.负责人 → def_user（工号/姓名双口径匹配，需有效）
     * 任一环节缺失直接抛异常（事务回滚、流程发起/推进整体失败）：
     * 禁止回落到 admin 等兜底账号，避免审批任务被静默路由到无关人员
     * 注：负责人若即发起人本人，按原样返回（部门负责人自审场景由流程定义规避）
     *
     * @param string $sponsor 发起人工号
     * @return array 审批人列表（单元素：['work_id' => 工号, 'user_name' => 姓名]）
     * @throws \RuntimeException
     */
    private function getApproversBySuperior(string $sponsor): array
    {
        if (!$sponsor) {
            throw new \RuntimeException('发起人为空，无法解析部门负责人（SUPERIOR）审批人');
        }

        $sql = sprintf(
            'select `员工部门编码` from `def_user`
            where `工号`=%s and `有效标识`=%s limit 1',
            $this->model->quote($sponsor),
            $this->model->quote('1')
        );
        $result = $this->model->select($sql);
        $row = $result ? ($result->getRowArray() ?: []) : [];
        if (empty($row)) {
            throw new \RuntimeException('发起人不是有效用户，无法解析部门负责人（SUPERIOR）审批人：' . $sponsor);
        }
        $deptCode = $row['员工部门编码'] ?? '';
        if ($deptCode === '') {
            throw new \RuntimeException('发起人未配置员工部门编码，无法解析部门负责人（SUPERIOR）审批人：' . $sponsor);
        }

        $sql = sprintf(
            'select `部门名称`, `负责人` from `def_dept`
            where `部门编码`=%s and `有效标识`=%s and `删除标识`=%s limit 1',
            $this->model->quote($deptCode),
            $this->model->quote('1'),
            $this->model->quote('0')
        );
        $result = $this->model->select($sql);
        $dept = $result ? ($result->getRowArray() ?: []) : [];
        if (empty($dept)) {
            throw new \RuntimeException('发起人部门不存在或已失效，无法解析部门负责人（SUPERIOR）审批人：' . $deptCode);
        }
        $deptName = $dept['部门名称'] ?? $deptCode;
        $head = trim((string) ($dept['负责人'] ?? ''));
        if ($head === '') {
            throw new \RuntimeException('部门「' . $deptName . '」未配置负责人，无法解析 SUPERIOR 审批人');
        }

        // def_dept.负责人 存姓名；def_user 中工号与姓名基本同值，按工号/姓名双口径匹配并去重
        $sql = sprintf(
            'select distinct `工号` as `work_id`, `姓名` as `user_name`
            from `def_user`
            where (`工号`=%s or `姓名`=%s) and `有效标识`=%s',
            $this->model->quote($head),
            $this->model->quote($head),
            $this->model->quote('1')
        );
        $result = $this->model->select($sql);
        $heads = $result ? $result->getResultArray() : [];
        if (empty($heads)) {
            throw new \RuntimeException('部门「' . $deptName . '」的负责人「' . $head . '」不是有效用户，无法解析 SUPERIOR 审批人');
        }
        if (count($heads) > 1) {
            throw new \RuntimeException('部门「' . $deptName . '」的负责人「' . $head . '」匹配到多个有效用户，存在歧义，无法解析 SUPERIOR 审批人');
        }

        return $heads;
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

        // 仅统计 APPROVAL 任务：抄送(CC)任务不参与节点推进判定（CC 非阻塞）
        $sql = sprintf(
            'select
                sum(case when `任务状态`=%s then 1 else 0 end) as `pending_count`,
                sum(case when `任务状态`=%s and `处理结果`=%s then 1 else 0 end) as `approve_count`,
                sum(case when `任务状态`=%s and `处理结果`=%s then 1 else 0 end) as `reject_count`,
                count(*) as `total_count`
            from `def_workflow_task`
            where `实例ID`=%d and `节点编码`=%s and `删除标识`=%s and `任务类型`=%s',
            $this->model->quote('待处理'),
            $this->model->quote('已处理'),
            $this->model->quote('同意'),
            $this->model->quote('已处理'),
            $this->model->quote('拒绝'),
            $instanceId,
            $this->model->quote($nodeCode),
            $this->model->quote('0'),
            $this->model->quote('APPROVAL')
        );
        // 统计读必须走无缓存查询：本方法在 approve 事务内调用，需读到同事务刚写入的任务状态
        $result = $this->model->query($sql);
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
