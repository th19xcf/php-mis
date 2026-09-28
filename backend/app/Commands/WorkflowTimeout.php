<?php

namespace App\Commands;

use App\Models\Mcommon;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * 流程超时扫描命令
 *
 * 扫描待处理审批任务（APPROVAL 类型），关联节点取超时规则 JSON，
 * 若任务创建时间 + 超时时长 < 当前时间，则向审批人发送 oa_message 提醒。
 *
 * 超时规则 JSON 格式（def_workflow_node.超时规则）：
 *   {"days": 2}          —— 超时 2 天
 *   {"hours": 48}        —— 超时 48 小时
 *   无规则或解析失败则跳过
 *
 * 幂等：同一任务每轮只发一次提醒（按 oa_message 去重，消息类型='workflow_timeout'）
 *
 * 用法：
 *   php spark workflow:timeout              # 扫描并发送提醒
 *   php spark workflow:timeout --dry-run   # 只出报告不发送
 *
 * 建议通过系统定时任务（cron）每 30 分钟执行一次。
 */
class WorkflowTimeout extends BaseCommand
{
    protected $group       = 'Workflow';
    protected $name        = 'workflow:timeout';
    protected $description = '扫描流程超时任务并向审批人发送提醒消息';
    protected $usage       = 'php spark workflow:timeout [--dry-run]';
    protected $arguments   = [];
    protected $options     = [
        '--dry-run' => '只输出报告不发送消息',
    ];

    public function run(array $params)
    {
        $dryRun = CLI::getOption('dry-run') !== null;
        $model = new Mcommon();
        $now = date('Y-m-d H:i:s');

        CLI::write('开始扫描超时审批任务，当前时间：' . $now, 'yellow');
        if ($dryRun) {
            CLI::write('【dry-run 模式】仅输出报告，不发送消息', 'light_yellow');
        }

        // 查询所有待处理审批任务（关联节点取超时规则）
        $sql = 'select t.`GUID` as `task_id`, t.`实例ID`, t.`节点编码`, t.`处理人`, t.`处理人姓名`,
                       t.`任务标题`, t.`创建时间`,
                       n.`超时规则`
                from `def_workflow_task` t
                left join `def_workflow_node` n on n.`节点编码` = t.`节点编码`
                  and n.`流程定义ID` = (select i.`流程定义ID` from `def_workflow_instance` i where i.`GUID` = t.`实例ID`)
                where t.`任务状态` = \'待处理\'
                  and t.`任务类型` = \'APPROVAL\'
                  and t.`删除标识` = \'0\'';
        $result = $model->select($sql);
        $tasks = $result ? ($result->getResultArray() ?: []) : [];

        CLI::write('待处理审批任务总数：' . count($tasks));

        $timeoutTasks = [];
        foreach ($tasks as $task) {
            $ruleJson = $task['超时规则'] ?? '';
            if (empty($ruleJson)) {
                continue;
            }

            $rule = json_decode($ruleJson, true);
            if (!is_array($rule)) {
                continue;
            }

            $threshold = $this->calculateThreshold($task['创建时间'] ?? '', $rule);
            if ($threshold === null) {
                continue;
            }

            if ($now < $threshold) {
                continue;
            }

            // 已超时：检查是否已发过提醒（防重复通知）
            if (!$dryRun) {
                $taskId = (int) $task['task_id'];
                $checkSql = sprintf(
                    'select count(*) as `cnt` from `oa_message`
                    where `消息类型`=%s and `关联ID`=%d and `删除标识`=%s',
                    $model->quote('workflow_timeout'),
                    $taskId,
                    $model->quote('0')
                );
                $checkResult = $model->select($checkSql);
                $row = $checkResult ? ($checkResult->getRowArray() ?: []) : [];
                if ((int) ($row['cnt'] ?? 0) > 0) {
                    continue;
                }
            }

            $timeoutTasks[] = $task;
        }

        CLI::write('超时任务数：' . count($timeoutTasks), 'red');

        if (empty($timeoutTasks)) {
            CLI::write('无超时任务，扫描完成', 'green');
            return;
        }

        $sent = 0;
        foreach ($timeoutTasks as $task) {
            $taskId = (int) $task['task_id'];
            $approver = $task['处理人'] ?? '';
            $approverName = $task['处理人姓名'] ?? '';
            $title = $task['任务标题'] ?? '审批任务';

            CLI::write(sprintf(
                '  超时：任务#%d | 审批人：%s(%s) | 标题：%s | 创建：%s',
                $taskId, $approverName, $approver, $title, $task['创建时间'] ?? ''
            ), 'light_red');

            if ($dryRun) {
                continue;
            }

            // 发送 oa_message 提醒
            $this->sendTimeoutMessage($model, $taskId, $approver, $approverName, $title);
            $sent++;
        }

        CLI::write(sprintf('扫描完成，发送提醒：%d 条', $dryRun ? 0 : $sent), 'green');
    }

    /**
     * 根据超时规则计算超时阈值时间
     *
     * @param string $createTime 任务创建时间
     * @param array $rule 超时规则 {"days":2} 或 {"hours":48}
     * @return string|null 阈值时间（Y-m-d H:i:s），解析失败返回 null
     */
    private function calculateThreshold(string $createTime, array $rule): ?string
    {
        $ts = strtotime($createTime);
        if ($ts === false) {
            return null;
        }

        if (isset($rule['days'])) {
            $ts += (int) $rule['days'] * 86400;
        } elseif (isset($rule['hours'])) {
            $ts += (int) $rule['hours'] * 3600;
        } elseif (isset($rule['minutes'])) {
            $ts += (int) $rule['minutes'] * 60;
        } else {
            return null;
        }

        return date('Y-m-d H:i:s', $ts);
    }

    /**
     * 向审批人发送超时提醒消息
     */
    private function sendTimeoutMessage(Mcommon $model, int $taskId, string $approver, string $approverName, string $title): void
    {
        $now = date('Y-m-d H:i:s');
        $content = '您有一个审批任务已超时，请尽快处理：' . $title;

        $sql = sprintf(
            'insert into `oa_message`
            (`消息类型`, `关联ID`, `接收人`, `接收人姓名`, `消息标题`, `消息内容`,
             `是否已读`, `发送时间`,
             `操作来源`, `操作人员`, `操作时间`,
             `创建人`, `创建时间`, `更新人`, `更新时间`, `删除标识`, `有效标识`)
            values (%s, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
            $model->quote('workflow_timeout'),
            $taskId,
            $model->quote($approver),
            $model->quote($approverName),
            $model->quote('审批超时提醒'),
            $model->quote($content),
            $model->quote('0'),
            $model->quote($now),
            $model->quote('SYSTEM'),
            $model->quote('SYSTEM'),
            $model->quote($now),
            $model->quote('SYSTEM'),
            $model->quote($now),
            $model->quote('SYSTEM'),
            $model->quote($now),
            $model->quote('0'),
            $model->quote('1')
        );
        $model->exec($sql);
    }
}
