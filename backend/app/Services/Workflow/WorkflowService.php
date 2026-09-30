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
            if (($node['节点类型'] ?? '') === '开始') {
                $startNode = $node;
                break;
            }
        }
        if (!$startNode) {
            throw new \RuntimeException('流程定义缺少开始节点');
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

    /**
     * 审批处理
     *
     * @param int $taskId 任务ID
     * @param string $approver 审批人工号
     * @param string $approverName 审批人姓名
     * @param string $opinion 审批意见
     * @param string $action 审批动作（同意/拒绝）
     * @param string $rejectMode 拒绝模式：
     *   - terminate 终止流程（默认，历史行为：实例置已终止，整单终结）
     *   - sponsor   退回发起人：当前节点生成 RETURN 任务给发起人，实例保持运行中；
     *               发起人修改后处理该任务（重新提交）即从当前节点续走
     *   - previous  退回上一审批节点：实例回退至最近一次同意动作所在节点并重建审批任务；
     *               首个审批节点拒绝时无前序节点，自动回落为退回发起人
     * @return array ['instanceId', 'instanceStatus', 'newTasks', 'rejectMode'?, 'resubmitted'?]
     */
    public function approve(
        int $taskId,
        string $approver,
        string $approverName,
        string $opinion,
        string $action = '同意',
        string $rejectMode = 'terminate'
    ): array {
        if (!in_array($rejectMode, ['terminate', 'sponsor', 'previous'], true)) {
            throw new \RuntimeException('无效的拒绝模式：' . $rejectMode);
        }

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

        $taskType = $task['任务类型'] ?? '';
        if ($action === '拒绝') {
            // 抄送任务是通知性质，拒绝语义不适用，防止误终止/误退回流程
            if ($taskType === '抄送') {
                throw new \RuntimeException('抄送任务不支持驳回');
            }
            // 退回任务仅支持发起人重新提交，不存在再次拒绝
            if ($taskType === '退回') {
                throw new \RuntimeException('退回任务仅支持重新提交');
            }
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
        return $this->withTransaction(function () use ($taskId, $approver, $approverName, $opinion, $action, $rejectMode, $task, $taskType, $instance, $instanceId, $nodeCode) {
            $now = date('Y-m-d H:i:s');
            // 退回任务的处理语义是"重新提交"，处理结果与流水动作均按此口径记录
            $actionResult = ($taskType === '退回') ? '重新提交' : (($action === '同意') ? '同意' : '拒绝');

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
            $result = [];

            if ($taskType === 'RETURN') {
                // 退回任务重新提交：作废当前节点旧轮审批任务后重建新一轮，实例保持运行中并停留当前节点
                // （createTasksForNode 内部会作废旧轮任务，防止新旧两轮混合统计导致节点永远无法通过）
                $newTasks = $this->createTasksForNode($instanceId, $nodeCode);
                $result['resubmitted'] = true;
            } elseif ($action === '拒绝') {
                if ($rejectMode === 'terminate') {
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
                    // 退回模式：实例保持运行中，流程不终结
                    $modeUsed = $rejectMode;
                    $returnNodeCode = $nodeCode;

                    if ($rejectMode === 'previous') {
                        $prevNodeCode = $this->findLastApprovedNode($instanceId);
                        if ($prevNodeCode === null) {
                            // 首个审批节点拒绝，无前序审批节点，回落为退回发起人
                            $modeUsed = 'sponsor';
                        } else {
                            $returnNodeCode = $prevNodeCode;
                        }
                    }

                    // 作废当前节点其余待处理审批任务：
                    // 会签场景下其他审批人的待办若保留，其后续同意会误触发节点推进判定
                    $this->voidNodePendingTasks($instanceId, $nodeCode, $taskId, $approver, $now);

                    if ($modeUsed === 'sponsor') {
                        // 退回发起人：当前节点生成 RETURN 任务，发起人修改后重新提交即从当前节点续走
                        $this->createReturnTask($instanceId, $nodeCode, $task['节点名称'] ?? '', $instance, $approver, $now);
                    } else {
                        // 退回上一节点：实例当前节点回退，上一节点重建审批任务（旧轮任务一并作废）
                        $sql = sprintf(
                            'update `def_workflow_instance`
                            set `当前节点编码`=%s, `更新人`=%s, `更新时间`=%s
                            where `GUID`=%d',
                            $this->model->quote($returnNodeCode),
                            $this->model->quote($approver),
                            $this->model->quote($now),
                            $instanceId
                        );
                        $this->model->exec($sql);
                        $newTasks = $this->createTasksForNode($instanceId, $returnNodeCode);
                    }

                    $instanceStatus = '运行中';
                    $result['rejectMode'] = $modeUsed;

                    // 退回路由流水：与拒绝动作分条记录，时间线上可区分"审批人拒绝"与"流程退回去向"
                    $this->addTaskLog(
                        $taskId,
                        $instanceId,
                        $nodeCode,
                        $approver,
                        $approverName,
                        '退回',
                        $modeUsed === 'sponsor' ? '退回发起人修改' : '退回上一节点：' . $returnNodeCode
                    );
                }
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

            return $result + [
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
            $this->model->quote('审批'),
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
        if (($task['任务类型'] ?? '') !== '抄送') {
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
        if ($nodeType === '结束' || $nodeType === '开始') {
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
        $taskType = $nodeType === '抄送' ? '抄送' : '审批';

        // 节点重入（退回重审 / 重新提交 / 流程环回）时作废该节点旧轮审批任务，开启新一轮：
        // 若旧轮的"已处理-拒绝/同意"记录保留在统计口径内，会签节点将永远无法满足通过条件
        if ($taskType === '审批') {
            $sql = sprintf(
                'update `def_workflow_task`
                set `任务状态`=%s, `更新人`=%s, `更新时间`=%s
                where `实例ID`=%d and `节点编码`=%s and `任务类型`=%s
                  and `任务状态` in (%s, %s) and `删除标识`=%s',
                $this->model->quote('已作废'),
                $this->model->quote($sponsor),
                $this->model->quote($now),
                $instanceId,
                $this->model->quote($nodeCode),
                $this->model->quote('审批'),
                $this->model->quote('待处理'),
                $this->model->quote('已处理'),
                $this->model->quote('0')
            );
            $this->model->exec($sql);
        }

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

    /**
     * 查询实例最近一次"同意"动作所在的节点编码（退回上一节点的定位依据）
     *
     * 从事务内调用，走无缓存查询保证读到同事务刚写入的流水；
     * 首个审批节点拒绝时无前序同意记录，返回 null（调用方回落为退回发起人）
     */
    private function findLastApprovedNode(int $instanceId): ?string
    {
        $sql = sprintf(
            'select `节点编码` from `def_workflow_task_log`
            where `实例ID`=%d and `动作类型`=%s and `节点编码` is not null and `节点编码` != %s
            order by `GUID` desc limit 1',
            $instanceId,
            $this->model->quote('同意'),
            $this->model->quote('')
        );
        $result = $this->model->query($sql);
        $row = $result ? ($result->getRowArray() ?: []) : [];
        $nodeCode = $row['节点编码'] ?? null;
        return ($nodeCode === null || $nodeCode === '') ? null : (string) $nodeCode;
    }

    /**
     * 作废节点上除当前任务外的其余待处理审批任务
     *
     * 驳回退回时调用：会签场景下其他审批人的待办若保留，
     * 其后续同意会基于残留任务误触发节点推进判定
     */
    private function voidNodePendingTasks(int $instanceId, string $nodeCode, int $excludeTaskId, string $operator, string $now): void
    {
        $sql = sprintf(
            'update `def_workflow_task`
            set `任务状态`=%s, `更新人`=%s, `更新时间`=%s
            where `实例ID`=%d and `节点编码`=%s and `任务类型`=%s
              and `任务状态`=%s and `删除标识`=%s and `GUID`<>%d',
            $this->model->quote('已作废'),
            $this->model->quote($operator),
            $this->model->quote($now),
            $instanceId,
            $this->model->quote($nodeCode),
            $this->model->quote('审批'),
            $this->model->quote('待处理'),
            $this->model->quote('0'),
            $excludeTaskId
        );
        $this->model->exec($sql);
    }

    /**
     * 生成退回发起人任务（任务类型 RETURN，处理人为流程发起人）
     *
     * 发起人在待办中心处理该任务（重新提交）后，流程从被退回节点续走
     */
    private function createReturnTask(int $instanceId, string $nodeCode, string $nodeName, array $instance, string $operator, string $now): void
    {
        $sql = sprintf(
            'insert into `def_workflow_task`
            (`实例ID`, `节点编码`, `节点名称`, `任务类型`,
             `处理人`, `处理人姓名`, `任务状态`,
             `操作来源`, `操作人员`, `操作时间`,
             `创建人`, `创建时间`, `更新人`, `更新时间`)
            values (%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
            $instanceId,
            $this->model->quote($nodeCode),
            $this->model->quote($nodeName),
            $this->model->quote('退回'),
            $this->model->quote($instance['发起人'] ?? ''),
            $this->model->quote($instance['发起人姓名'] ?? ''),
            $this->model->quote('待处理'),
            $this->model->quote('SYSTEM'),
            $this->model->quote($operator),
            $this->model->quote($now),
            $this->model->quote($operator),
            $this->model->quote($now),
            $this->model->quote($operator),
            $this->model->quote($now)
        );
        $this->model->exec($sql);
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

            if (!$next || $next === '结束') {
                $sql = sprintf(
                    'update `def_workflow_instance`
                    set `实例状态`=%s, `当前节点编码`=%s, `结束时间`=%s,
                        `更新人`=%s, `更新时间`=%s
                    where `GUID`=%d',
                    $this->model->quote('已完成'),
                    $this->model->quote('结束'),
                    $this->model->quote($now),
                    $this->model->quote($operator),
                    $this->model->quote($now),
                    $instanceId
                );
                $this->model->exec($sql);
                return ['end' => true, 'currentNode' => '结束', 'instanceStatus' => '已完成', 'tasks' => $allTasks];
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

            if ($this->getNodeType($defId, $next) === '抄送') {
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
            case '角色':
                $approvers = $this->getApproversByRole($approverConfig);
                break;
            case '部门':
                $dept = $approverConfig ?: $deptCode;
                $approvers = $this->getApproversByDept($dept);
                break;
            case '上级':
                $approvers = $this->getApproversBySuperior($sponsor);
                break;
            case '指定人':
                $approvers = $this->getApproversByAssign($approverConfig);
                break;
            case '发起人':
                $approvers[] = [
                    'work_id' => $sponsor,
                    'user_name' => $sponsorName,
                ];
                break;
            default:
                break;
        }

        // 委托代理：解析出审批人后，对每个审批人查当前有效委托配置，命中则替换为代理人
        // 避免审批人休假时流程停滞；批量查询防 N+1
        $approvers = $this->applyDelegation($approvers);

        return $approvers;
    }

    /**
     * 委托代理替换：批量查当前有效委托配置，将委托人替换为代理人
     *
     * 同一委托人若有多条有效配置，取优先级最高（GUID 最小）的一条；
     * 代理人不可与委托人为同一人（防循环）。
     *
     * @param array $approvers 原始审批人列表
     * @return array 替换后的审批人列表
     */
    private function applyDelegation(array $approvers): array
    {
        if (empty($approvers)) {
            return $approvers;
        }

        $workIds = array_filter(array_map(fn ($a) => $a['work_id'] ?? '', $approvers));
        if (empty($workIds)) {
            return $approvers;
        }

        $now = date('Y-m-d H:i:s');
        $quotedIds = implode(',', array_map(fn ($id) => $this->model->quote($id), $workIds));

        $sql = sprintf(
            'select `委托人`, `代理人`, `代理人姓名`
            from `def_workflow_delegate`
            where `委托人` in (%s) and `开始时间` <= %s and `结束时间` >= %s
              and `删除标识`=%s and `有效标识`=%s
            order by `GUID` asc',
            $quotedIds,
            $this->model->quote($now),
            $this->model->quote($now),
            $this->model->quote('0'),
            $this->model->quote('1')
        );
        $result = $this->model->select($sql);
        $delegations = $result ? ($result->getResultArray() ?: []) : [];

        if (empty($delegations)) {
            return $approvers;
        }

        // 同一委托人取第一条（GUID 最小，优先级最高）
        $map = [];
        foreach ($delegations as $d) {
            $principal = $d['委托人'] ?? '';
            if (!isset($map[$principal])) {
                $map[$principal] = $d;
            }
        }

        foreach ($approvers as &$approver) {
            $wid = $approver['work_id'] ?? '';
            if (isset($map[$wid])) {
                $delegateWorkId = $map[$wid]['代理人'] ?? '';
                // 防循环：代理人不可与委托人为同一人
                if ($delegateWorkId !== '' && $delegateWorkId !== $wid) {
                    $approver['work_id'] = $delegateWorkId;
                    $approver['user_name'] = $map[$wid]['代理人姓名'] ?? '';
                    $approver['delegated_from'] = $wid;
                }
            }
        }
        unset($approver);

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
        $approvalMode = $node['会签或签'] ?? '或签';

        // 仅统计 APPROVAL 任务：抄送(CC)任务不参与节点推进判定（CC 非阻塞）
        // total_count 仅计活跃任务（待处理/已处理）：退回重审后旧轮任务已置"已作废"，
        // 若计入总数，会签节点会因旧轮拒绝记录永远无法满足通过条件
        $sql = sprintf(
            'select
                sum(case when `任务状态`=%s then 1 else 0 end) as `pending_count`,
                sum(case when `任务状态`=%s and `处理结果`=%s then 1 else 0 end) as `approve_count`,
                sum(case when `任务状态`=%s and `处理结果`=%s then 1 else 0 end) as `reject_count`,
                sum(case when `任务状态` in (%s, %s) then 1 else 0 end) as `total_count`
            from `def_workflow_task`
            where `实例ID`=%d and `节点编码`=%s and `删除标识`=%s and `任务类型`=%s',
            $this->model->quote('待处理'),
            $this->model->quote('已处理'),
            $this->model->quote('同意'),
            $this->model->quote('已处理'),
            $this->model->quote('拒绝'),
            $this->model->quote('待处理'),
            $this->model->quote('已处理'),
            $instanceId,
            $this->model->quote($nodeCode),
            $this->model->quote('0'),
            $this->model->quote('审批')
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

        if ($approvalMode === '会签') {
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

    /**
     * 加签：当前审批人在审批过程中增加其他审批人共同审批
     *
     * 加签后原任务保持待处理，新增一条 APPROVAL 任务（加签父任务ID 指向原任务），
     * checkNodeApproved 会自动统计加签任务（同节点 APPROVAL），会签节点需全部同意、或签任一同意。
     *
     * @param int $taskId 原任务ID
     * @param string $approver 当前操作人（原任务处理人）
     * @param string $approverName 当前操作人姓名
     * @param string $signWorkId 加签目标人工号
     * @param string $signName 加签目标人姓名
     * @return array ['addedTaskId' => int]
     * @throws \RuntimeException
     */
    public function addSign(int $taskId, string $approver, string $approverName, string $signWorkId, string $signName): array
    {
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
            throw new \RuntimeException('无权操作此任务');
        }
        if (($task['任务类型'] ?? '') !== '审批') {
            throw new \RuntimeException('仅审批任务可加签');
        }

        $instanceId = (int) $task['实例ID'];
        $nodeCode = $task['节点编码'] ?? '';

        // 校验流程运行中
        $sql = sprintf(
            'select `实例状态` from `def_workflow_instance`
            where `GUID`=%d limit 1',
            $instanceId
        );
        $result = $this->model->select($sql);
        $instance = $result ? ($result->getRowArray() ?: []) : [];
        if (empty($instance) || ($instance['实例状态'] ?? '') !== '运行中') {
            throw new \RuntimeException('流程不是运行中状态');
        }

        // 防重复加签：同一父任务同一加签人只能有一条待处理的加签任务
        $sql = sprintf(
            'select count(*) as `cnt` from `def_workflow_task`
            where `加签父任务ID`=%d and `处理人`=%s and `任务状态`=%s and `删除标识`=%s',
            $taskId,
            $this->model->quote($signWorkId),
            $this->model->quote('待处理'),
            $this->model->quote('0')
        );
        $result = $this->model->select($sql);
        $row = $result ? ($result->getRowArray() ?: []) : [];
        if ((int) ($row['cnt'] ?? 0) > 0) {
            throw new \RuntimeException('该审批人已被加签，请勿重复操作');
        }

        return $this->withTransaction(function () use ($taskId, $instanceId, $nodeCode, $approver, $approverName, $signWorkId, $signName, $task) {
            $now = date('Y-m-d H:i:s');

            $sql = sprintf(
                'insert into `def_workflow_task`
                (`实例ID`, `节点编码`, `任务类型`, `处理人`, `处理人姓名`,
                 `任务状态`, `加签父任务ID`, `任务标题`,
                 `操作来源`, `操作人员`, `操作时间`,
                 `创建人`, `创建时间`, `更新人`, `更新时间`, `删除标识`, `有效标识`)
                values (%d, %s, %s, %s, %s, %s, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
                $instanceId,
                $this->model->quote($nodeCode),
                $this->model->quote('审批'),
                $this->model->quote($signWorkId),
                $this->model->quote($signName),
                $this->model->quote('待处理'),
                $taskId,
                $this->model->quote($task['任务标题'] ?? ''),
                $this->model->quote('SYSTEM'),
                $this->model->quote($approver),
                $this->model->quote($now),
                $this->model->quote($approver),
                $this->model->quote($now),
                $this->model->quote($approver),
                $this->model->quote($now),
                $this->model->quote('0'),
                $this->model->quote('1')
            );
            $this->model->exec($sql);

            $result = $this->model->query('select last_insert_id() as `id`');
            $row = $result ? ($result->getRowArray() ?: []) : [];
            $newTaskId = (int) ($row['id'] ?? 0);

            $this->addTaskLog($taskId, $instanceId, $nodeCode, $approver, $approverName, '加签', '加签给：' . $signName . '(' . $signWorkId . ')');

            return ['addedTaskId' => $newTaskId];
        });
    }

    /**
     * 转签：当前审批人将任务转给他人处理（不新增任务，直接改处理人）
     *
     * @param int $taskId 原任务ID
     * @param string $approver 当前操作人（原任务处理人）
     * @param string $approverName 当前操作人姓名
     * @param string $targetWorkId 转签目标人工号
     * @param string $targetName 转签目标人姓名
     * @return array ['transferred' => bool]
     * @throws \RuntimeException
     */
    public function transfer(int $taskId, string $approver, string $approverName, string $targetWorkId, string $targetName): array
    {
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
            throw new \RuntimeException('无权操作此任务');
        }
        if (($task['任务类型'] ?? '') !== '审批') {
            throw new \RuntimeException('仅审批任务可转签');
        }

        $instanceId = (int) $task['实例ID'];
        $nodeCode = $task['节点编码'] ?? '';

        // 校验流程运行中
        $sql = sprintf(
            'select `实例状态` from `def_workflow_instance`
            where `GUID`=%d limit 1',
            $instanceId
        );
        $result = $this->model->select($sql);
        $instance = $result ? ($result->getRowArray() ?: []) : [];
        if (empty($instance) || ($instance['实例状态'] ?? '') !== '运行中') {
            throw new \RuntimeException('流程不是运行中状态');
        }

        return $this->withTransaction(function () use ($taskId, $instanceId, $nodeCode, $approver, $approverName, $targetWorkId, $targetName, $task) {
            $now = date('Y-m-d H:i:s');

            $sql = sprintf(
                'update `def_workflow_task`
                set `处理人`=%s, `处理人姓名`=%s,
                    `转签源任务ID`=`GUID`,
                    `更新人`=%s, `更新时间`=%s, `操作人员`=%s, `操作时间`=%s
                where `GUID`=%d and `任务状态`=%s',
                $this->model->quote($targetWorkId),
                $this->model->quote($targetName),
                $this->model->quote($approver),
                $this->model->quote($now),
                $this->model->quote($approver),
                $this->model->quote($now),
                $taskId,
                $this->model->quote('待处理')
            );
            if ($this->model->exec($sql) === 0) {
                throw new \RuntimeException('任务已被处理或已撤回，请刷新待办列表');
            }

            $this->addTaskLog($taskId, $instanceId, $nodeCode, $approver, $approverName, '转签', '转签给：' . $targetName . '(' . $targetWorkId . ')');

            return ['transferred' => true];
        });
    }
}
