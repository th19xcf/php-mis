<?php

namespace App\Services\Oa;

use App\Exceptions\BusinessException;
use App\Models\Mcommon;

/**
 * 待办事项服务（oa_todo）
 *
 * 设计要点：
 *   1. 待办全部手动创建（含来源类型="会议"时也由人工填写，不依赖 MeetingService）
 *   2. 待办中心（3030）聚合两类待办：
 *      - 任务待办：oa_todo（负责人/指派人 = 当前用户）
 *      - 审批待办：def_workflow_task（处理人 = 当前用户，任务状态=PENDING）
 *   3. 审计八列由本服务统一写入（对齐 AuditFieldsTrait 列名惯例）
 */
class TodoService
{
    private Mcommon $model;
    private MessageService $messageService;

    /** 优先级选项 */
    public const PRIORITY_LEVELS = ['高', '中', '低'];

    /** 高优先级任务逾期提醒间隔（小时）：紧急任务高频续催，中/低优先级仍为每天一次 */
    public const URGENT_OVERDUE_INTERVAL_HOURS = 4;

    /** 待办状态选项 */
    public const TODO_STATUSES = ['待处理', '进行中', '已完成', '已取消'];

    /** 来源类型选项 */
    public const SOURCE_TYPES = ['手动', '会议', '工作流', '合同'];

    /** 重复规则选项 */
    public const REPEAT_RULES = ['每天', '每周', '每月'];

    public function __construct()
    {
        $this->model = new Mcommon();
        $this->messageService = new MessageService();
    }

    // ============================================================
    // 待办中心聚合查询
    // ============================================================

    /**
     * 待办中心主数据（合并任务待办 + 审批待办）
     *
     * @param string $workId 当前用户工号
     * @param array  $params 筛选：status/sourceType/priority/keyword
     * @return array{list:array, stats:array}
     */
    public function getCenterData(string $workId, array $params = []): array
    {
        $statusFilter = trim((string) ($params['status'] ?? ''));
        $sourceFilter = trim((string) ($params['sourceType'] ?? ''));
        $priorityFilter = trim((string) ($params['priority'] ?? ''));
        $keyword = trim((string) ($params['keyword'] ?? ''));

        // 1. 任务待办（oa_todo）——负责人（支持多个，逗号分隔）或指派人=我；子任务不在中心列表显示
        $taskWhere = ['有效标识="1"', '删除标识="0"', '(父GUID is null or 父GUID=0)'];
        $taskWhere[] = sprintf('(FIND_IN_SET(%s, 负责人)>0 or 指派人=%s)', $this->model->quote($workId), $this->model->quote($workId));

        if ($keyword !== '') {
            $kw = $this->model->quote('%' . $keyword . '%');
            $taskWhere[] = sprintf('(待办标题 like %s or 待办描述 like %s or 来源摘要 like %s)', $kw, $kw, $kw);
        }
        if ($sourceFilter !== '' && $sourceFilter !== '工作流') {
            $taskWhere[] = '来源类型=' . $this->model->quote($sourceFilter);
        }
        if ($priorityFilter !== '') {
            $taskWhere[] = '优先级=' . $this->model->quote($priorityFilter);
        }
        if ($statusFilter !== '' && in_array($statusFilter, self::TODO_STATUSES, true)) {
            $taskWhere[] = '待办状态=' . $this->model->quote($statusFilter);
        }

        $taskWhereSql = implode(' and ', $taskWhere);
        $taskSql = sprintf(
            'select "task" as todoType, GUID, 待办标题 as title, 待办描述 as description,
                    负责人 as assignee, 指派人 as assigner, 截止日期 as dueDate,
                    优先级 as priority, 待办状态 as status,
                    来源类型 as sourceType, 来源摘要 as sourceTitle, 来源GUID as sourceGuid,
                    完成时间 as completedAt, 完成说明 as completedNote,
                    关联人员编码 as personCode,
                    置顶标识 as pinned, 重复规则 as repeatRule, 附件 as attachments,
                    开始操作时间 as createdAt, 操作时间 as updatedAt,
                    "" as bizType, "" as bizId, "" as instanceId, "" as nodeCode
             from oa_todo
             where %s',
            $taskWhereSql
        );

        // 2. 审批待办（def_workflow_task）——处理人=我 且 PENDING
        $wfWhere = ['t.处理人=' . $this->model->quote($workId), 't.任务状态=' . $this->model->quote('PENDING'), 't.删除标识=' . $this->model->quote('0')];

        if ($keyword !== '') {
            $kw = $this->model->quote('%' . $keyword . '%');
            $wfWhere[] = sprintf('(i.业务标题 like %s or d.流程名称 like %s)', $kw, $kw);
        }
        if ($sourceFilter !== '' && $sourceFilter !== '工作流') {
            // 审批待办只匹配"工作流"来源，其他来源筛选时不返回审批待办
            $wfWhere[] = '1=0';
        }
        if ($statusFilter !== '' && $statusFilter !== '待处理') {
            // 审批待办只有"待处理"状态，其他状态筛选时不返回
            $wfWhere[] = '1=0';
        }
        if ($priorityFilter !== '' && $priorityFilter !== '中') {
            $wfWhere[] = '1=0';
        }

        $wfWhereSql = implode(' and ', $wfWhere);
        $wfSql = sprintf(
            'select "workflow" as todoType, t.GUID, i.业务标题 as title, "" as description,
                    t.处理人 as assignee, i.发起人 as assigner, null as dueDate,
                    "中" as priority, "待处理" as status,
                    "工作流" as sourceType, d.流程名称 as sourceTitle, t.实例ID as sourceGuid,
                    null as completedAt, "" as completedNote,
                    "" as personCode,
                    "0" as pinned, null as repeatRule, null as attachments,
                    t.创建时间 as createdAt, t.更新时间 as updatedAt,
                    i.业务类型 as bizType, i.业务ID as bizId, i.GUID as instanceId, t.节点编码 as nodeCode
             from def_workflow_task t
             inner join def_workflow_instance i on i.GUID = t.实例ID
             inner join def_workflow_definition d on d.GUID = i.流程定义ID
             where %s',
            $wfWhereSql
        );

        // 3. UNION ALL 合并查询（置顶优先，其次创建时间倒序）
        $unionSql = sprintf('(%s) union all (%s) order by pinned desc, createdAt desc', $taskSql, $wfSql);
        $result = $this->model->select($unionSql);
        $list = $result ? $result->getResultArray() : [];

        // 4. 统计卡计数（基于全量数据，不受筛选影响）
        $stats = $this->getStats($workId);

        return ['list' => $list, 'stats' => $stats];
    }

    /**
     * 统计卡计数（全部/待处理/进行中/已逾期/已完成）
     */
    public function getStats(string $workId): array
    {
        $today = date('Y-m-d');
        $w = sprintf('有效标识="1" and 删除标识="0" and (父GUID is null or 父GUID=0) and (FIND_IN_SET(%s, 负责人)>0 or 指派人=%s)', $this->model->quote($workId), $this->model->quote($workId));

        $sql = sprintf(
            'select
                count(*) as all_count,
                sum(case when 待办状态="待处理" then 1 else 0 end) as pending,
                sum(case when 待办状态="进行中" then 1 else 0 end) as doing,
                sum(case when 待办状态="已完成" then 1 else 0 end) as done,
                sum(case when 待办状态!="已完成" and 待办状态!="已取消" and 截止日期 is not null and 截止日期<%s then 1 else 0 end) as overdue
             from oa_todo where %s',
            $this->model->quote($today),
            $w
        );
        $result = $this->model->select($sql);
        $row = $result ? ($result->getRowArray() ?: []) : [];

        // 加上审批待办的 pending 计数
        $wfPending = $this->model->select(sprintf(
            'select count(*) as cnt from def_workflow_task where 处理人=%s and 任务状态=%s and 删除标识="0"',
            $this->model->quote($workId),
            $this->model->quote('PENDING')
        ));
        $wfRow = $wfPending ? ($wfPending->getRowArray() ?: []) : [];
        $wfPendingCount = (int) ($wfRow['cnt'] ?? 0);

        $pending = (int) ($row['pending'] ?? 0) + $wfPendingCount;

        return [
            'all' => (int) ($row['all_count'] ?? 0) + $wfPendingCount,
            'pending' => $pending,
            'doing' => (int) ($row['doing'] ?? 0),
            'done' => (int) ($row['done'] ?? 0),
            'overdue' => (int) ($row['overdue'] ?? 0),
        ];
    }

    // ============================================================
    // 手动 CRUD
    // ============================================================

    // ============================================================
    // 操作流水（oa_todo_log）
    // ============================================================

    /**
     * 写入操作流水
     *
     * @param int    $todoGuid 待办GUID
     * @param string $action   动作：新增/修改/完成/转办/删除/催办
     * @param string $operator 操作人工号
     * @param array  $changes  变更明细（字段名 => [前值, 后值]）
     * @param string $note     备注（完成说明/转办去向等）
     */
    private function insertLog(int $todoGuid, string $action, string $operator, array $changes = [], string $note = ''): void
    {
        try {
            $detail = $changes === [] ? null : json_encode(
                array_map(
                    static fn($c) => ['field' => $c[0], 'from' => $c[1], 'to' => $c[2]],
                    array_values($changes)
                ),
                JSON_UNESCAPED_UNICODE
            );
            $this->insertRow('oa_todo_log', [
                '待办GUID' => $todoGuid,
                '动作' => $action,
                '操作人' => $operator,
                '操作人姓名' => $this->fetchUserName($operator),
                '变更明细' => $detail,
                '备注' => $note !== '' ? $note : null,
                '操作时间' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 流水写入失败不阻断主流程
            log_message('error', '[TodoService::insertLog] ' . $e->getMessage());
        }
    }

    /**
     * 查询工号对应姓名（def_user）
     */
    private function fetchUserName(string $workId): ?string
    {
        $row = $this->model->select(sprintf(
            'select 姓名 from def_user where 工号=%s and 有效标识="1" limit 1',
            $this->model->quote($workId)
        ))->getRowArray();
        return $row ? (string) $row['姓名'] : null;
    }

    /**
     * 待办操作流水查询（详情时间线用）
     *
     * @param string $guid 待办GUID
     * @return array 流水列表（时间正序）
     */
    public function getLogs(string $guid): array
    {
        $guid = trim($guid);
        if ($guid === '' || !ctype_digit($guid)) {
            throw new BusinessException('待办GUID无效');
        }
        $rows = $this->model->select(sprintf(
            'select GUID, 动作 as action, 操作人 as operator, 操作人姓名 as operatorName,
                    变更明细 as changes, 备注 as note, 操作时间 as operatedAt
             from oa_todo_log where 待办GUID=%s order by GUID desc limit 200',
            $this->model->quote($guid)
        ))->getResultArray();
        foreach ($rows as &$r) {
            $r['changes'] = $r['changes'] ? json_decode((string) $r['changes'], true) : null;
        }
        return $rows;
    }

    /**
     * 规范化负责人字段（支持多个工号，逗号分隔存储）
     *
     * @param string|array $value 单个工号 / 工号数组 / 逗号分隔字符串
     * @return string 去重后的逗号分隔工号串（为空时返回 ''）
     */
    private function normalizeAssignees($value): string
    {
        $ids = is_array($value) ? $value : explode(',', (string) $value);
        $ids = array_map(static fn($v) => trim((string) $v), $ids ?: []);
        $ids = array_values(array_unique(array_filter($ids, static fn($v) => $v !== '')));
        return implode(',', $ids);
    }

    /**
     * 创建待办
     *
     * @param array  $data 待办字段：待办标题(必填)/负责人(必填,支持多个)/截止日期/优先级/待办描述/来源类型/指派人
     * @param string $operator 操作人工号
     * @return int 新待办 GUID
     * @throws BusinessException 校验失败
     */
    public function createTodo(array $data, string $operator): int
    {
        $title = trim((string) ($data['待办标题'] ?? ''));
        if ($title === '') {
            throw new BusinessException('待办标题不能为空');
        }
        if (mb_strlen($title) > 200) {
            throw new BusinessException('待办标题不能超过 200 字');
        }

        $assignee = $this->normalizeAssignees($data['负责人'] ?? '');
        if ($assignee === '') {
            throw new BusinessException('负责人不能为空');
        }

        $priority = trim((string) ($data['优先级'] ?? ''));
        if ($priority === '') {
            $priority = '中';
        }
        if (!in_array($priority, self::PRIORITY_LEVELS, true)) {
            throw new BusinessException('优先级无效，可选：' . implode('/', self::PRIORITY_LEVELS));
        }

        $sourceType = trim((string) ($data['来源类型'] ?? ''));
        if ($sourceType === '') {
            $sourceType = '手动';
        }
        if (!in_array($sourceType, self::SOURCE_TYPES, true)) {
            throw new BusinessException('来源类型无效，可选：' . implode('/', self::SOURCE_TYPES));
        }

        $status = trim((string) ($data['待办状态'] ?? ''));
        if ($status === '') {
            $status = '待处理';
        }
        if (!in_array($status, self::TODO_STATUSES, true)) {
            throw new BusinessException('待办状态无效，可选：' . implode('/', self::TODO_STATUSES));
        }

        $assigner = trim((string) ($data['指派人'] ?? ''));
        if ($assigner === '') {
            $assigner = $operator;
        }

        $dueDate = trim((string) ($data['截止日期'] ?? ''));
        if ($dueDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            throw new BusinessException('截止日期格式无效，应为 YYYY-MM-DD');
        }

        $repeatRule = trim((string) ($data['重复规则'] ?? ''));
        if ($repeatRule !== '' && !in_array($repeatRule, self::REPEAT_RULES, true)) {
            throw new BusinessException('重复规则无效，可选：' . implode('/', self::REPEAT_RULES));
        }

        $parentGuid = trim((string) ($data['父GUID'] ?? ''));
        if ($parentGuid !== '' && !ctype_digit($parentGuid)) {
            throw new BusinessException('父待办GUID无效');
        }

        $attachments = $data['附件'] ?? null;
        if (is_array($attachments)) {
            $attachments = json_encode($attachments, JSON_UNESCAPED_UNICODE);
        } else {
            $attachments = trim((string) $attachments);
        }

        $now = date('Y-m-d H:i:s');
        $row = [
            '待办标题' => $title,
            '待办描述' => $this->valOrNull($data['待办描述'] ?? ''),
            '来源类型' => $sourceType,
            '来源摘要' => trim((string) ($data['来源摘要'] ?? '')),
            '指派人' => $assigner,
            '负责人' => $assignee,
            '截止日期' => $dueDate !== '' ? $dueDate : null,
            '优先级' => $priority,
            '重复规则' => $repeatRule !== '' ? $repeatRule : null,
            '附件' => $attachments !== '' ? $attachments : null,
            '待办状态' => $status,
            '关联人员编码' => trim((string) ($data['关联人员编码'] ?? '')),
            '开始操作时间' => $now,
            '操作记录' => '新增',
            '操作来源' => '页面新增',
            '操作人员' => $operator,
            '操作时间' => $now,
            '删除标识' => '0',
            '有效标识' => '1',
        ];

        // 来源GUID（可选，手动填写的关联）
        if (!empty($data['来源GUID'])) {
            $row['来源GUID'] = (int) $data['来源GUID'];
        }

        // 父GUID（子任务挂靠）
        if ($parentGuid !== '') {
            $row['父GUID'] = (int) $parentGuid;
        }

        $this->insertRow('oa_todo', $row);

        $db = $this->model->getDb();
        $guid = (int) $db->insertID();
        if ($guid <= 0) {
            throw new BusinessException('待办创建失败');
        }
        $this->insertLog($guid, '新增', $operator, [], sprintf('负责人: %s', $assignee));

        // 通知负责人（排除操作人自己；子任务不重复通知）
        if ($parentGuid === '') {
            $receivers = array_diff(explode(',', $assignee), [$operator]);
            $this->messageService->sendBatch(
                $receivers,
                '新待办',
                sprintf('您有一个新待办：%s', $title),
                sprintf('优先级：%s%s，指派人：%s', $priority, $dueDate !== '' ? '，截止：' . $dueDate : '', $this->fetchUserName($assigner) ?? $assigner),
                $guid
            );
        }

        return $guid;
    }

    /**
     * 修改待办
     *
     * @param string $guid 待办GUID
     * @param array  $data 可修改字段
     * @param string $operator 操作人工号
     * @return int 影响行数（-1=不存在）
     */
    public function updateTodo(string $guid, array $data, string $operator): int
    {
        $guid = trim($guid);
        if ($guid === '' || !ctype_digit($guid)) {
            throw new BusinessException('待办GUID无效');
        }

        $old = $this->model->select(sprintf(
            'select * from oa_todo where GUID=%s and 有效标识="1" and 删除标识="0"',
            $this->model->quote($guid)
        ))->getRowArray();
        if (!$old) {
            return -1;
        }

        $now = date('Y-m-d H:i:s');
        $sets = [
            sprintf('操作记录=%s', $this->model->quote('修改')),
            sprintf('操作来源=%s', $this->model->quote('页面修改')),
            sprintf('操作人员=%s', $this->model->quote($operator)),
            sprintf('操作时间=%s', $this->model->quote($now)),
        ];

        $fieldMap = [
            '待办标题' => fn($v) => $this->assertTitle((string) $v),
            '待办描述' => fn($v) => $this->valOrNull($v),
            '来源类型' => fn($v) => $this->model->quote($this->assertOption((string) $v, self::SOURCE_TYPES, '来源类型')),
            '来源摘要' => fn($v) => $this->model->quote(trim((string) $v)),
            '指派人' => fn($v) => $this->model->quote(trim((string) $v)),
            '负责人' => function ($v) {
                $ids = $this->normalizeAssignees($v);
                if ($ids === '') {
                    throw new BusinessException('负责人不能为空');
                }
                return $this->model->quote($ids);
            },
            '截止日期' => fn($v) => $this->valOrNull($v),
            '优先级' => fn($v) => $this->model->quote($this->assertOption((string) $v, self::PRIORITY_LEVELS, '优先级')),
            '重复规则' => function ($v) {
                $v = trim((string) $v);
                if ($v !== '' && !in_array($v, self::REPEAT_RULES, true)) {
                    throw new BusinessException('重复规则无效');
                }
                return $this->valOrNull($v);
            },
            '待办状态' => fn($v) => $this->model->quote($this->assertOption((string) $v, self::TODO_STATUSES, '待办状态')),
            '关联人员编码' => fn($v) => $this->model->quote(trim((string) $v)),
        ];

        // 附件：数组转 JSON 存储
        if (array_key_exists('附件', $data)) {
            $att = $data['附件'];
            if (is_array($att)) {
                $sets[] = sprintf('`附件`=%s', $this->model->quote(json_encode($att, JSON_UNESCAPED_UNICODE)));
            } else {
                $sets[] = sprintf('`附件`=%s', $this->valOrNull($att));
            }
        }

        foreach ($fieldMap as $field => $converter) {
            if (array_key_exists($field, $data)) {
                $sets[] = sprintf('`%s`=%s', $field, $converter($data[$field]));
            }
        }

        // 完成说明单独处理
        if (array_key_exists('完成说明', $data)) {
            $sets[] = sprintf('`完成说明`=%s', $this->valOrNull($data['完成说明']));
        }

        // 字段级变更对比（用于流水明细）
        $changes = [];
        foreach ($fieldMap as $field => $_) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $before = trim((string) ($old[$field] ?? ''));
            $afterRaw = $data[$field];
            $after = is_array($afterRaw) ? implode(',', $afterRaw) : trim((string) $afterRaw);
            if ($before !== $after) {
                $changes[] = [$field, $before !== '' ? $before : '空', $after !== '' ? $after : '空'];
            }
        }

        $sql = sprintf(
            'update oa_todo set %s where GUID=%s and 有效标识="1" and 删除标识="0"',
            implode(', ', $sets),
            $this->model->quote($guid)
        );
        $this->model->exec($sql);

        if ($changes !== []) {
            $this->insertLog((int) $guid, '修改', $operator, $changes);
        }

        return (int) $this->model->getDb()->affectedRows();
    }

    /**
     * 标记完成
     *
     * @param string $guid 待办GUID
     * @param string $completedNote 完成说明
     * @param string $operator 操作人工号
     * @return int 影响行数（-1=不存在，-2=已完成）
     */
    public function completeTodo(string $guid, string $completedNote, string $operator): int
    {
        $guid = trim($guid);
        if ($guid === '' || !ctype_digit($guid)) {
            throw new BusinessException('待办GUID无效');
        }

        $row = $this->model->select(sprintf(
            'select GUID, 待办标题, 待办状态, 负责人, 指派人, 截止日期, 优先级, 重复规则, 父GUID
             from oa_todo where GUID=%s and 有效标识="1" and 删除标识="0"',
            $this->model->quote($guid)
        ))->getRowArray();
        if (!$row) {
            return -1;
        }
        if (((string) $row['待办状态']) === '已完成') {
            return -2;
        }

        $now = date('Y-m-d H:i:s');
        $this->model->exec(sprintf(
            'update oa_todo set 待办状态="已完成", 完成时间=%s, 完成说明=%s,
                操作记录="完成", 操作来源="页面完成", 操作人员=%s, 操作时间=%s
             where GUID=%s and 有效标识="1" and 删除标识="0"',
            $this->model->quote($now),
            $this->valOrNull($completedNote),
            $this->model->quote($operator),
            $this->model->quote($now),
            $this->model->quote($guid)
        ));

        $this->insertLog((int) $guid, '完成', $operator, [], $completedNote);

        // 通知指派人（负责人完成 → 指派人知晓；排除操作人自己）
        $assigner = trim((string) ($row['指派人'] ?? ''));
        if ($assigner !== '' && $assigner !== $operator) {
            $this->messageService->send(
                $assigner,
                '已完成',
                sprintf('待办已完成：%s', (string) $row['待办标题']),
                sprintf('由 %s 标记完成%s', $this->fetchUserName($operator) ?? $operator, $completedNote !== '' ? '，说明：' . $completedNote : ''),
                (int) $guid
            );
        }

        // 周期任务：自动生成下一期（截止日期按规则顺延）
        $repeatRule = trim((string) ($row['重复规则'] ?? ''));
        if ($repeatRule !== '') {
            $this->createNextPeriod((array) $row, $repeatRule, $operator);
        }

        return (int) $this->model->getDb()->affectedRows();
    }

    /**
     * 周期任务生成下一期（复制当前任务，截止日期顺延）
     */
    private function createNextPeriod(array $row, string $repeatRule, string $operator): void
    {
        try {
            $due = trim((string) ($row['截止日期'] ?? ''));
            $nextDue = '';
            if ($due !== '' && ($ts = strtotime($due)) !== false) {
                $nextDue = match ($repeatRule) {
                    '每天' => date('Y-m-d', strtotime('+1 day', $ts)),
                    '每周' => date('Y-m-d', strtotime('+7 days', $ts)),
                    '每月' => date('Y-m-d', strtotime('+1 month', $ts)),
                    default => '',
                };
            }

            $now = date('Y-m-d H:i:s');
            $next = [
                '待办标题' => (string) $row['待办标题'],
                '待办描述' => $this->valOrNull($row['待办描述'] ?? ''),
                '来源类型' => (string) ($row['来源类型'] ?? '手动'),
                '来源摘要' => trim((string) ($row['来源摘要'] ?? '')),
                '指派人' => (string) ($row['指派人'] ?? $operator),
                '负责人' => (string) $row['负责人'],
                '截止日期' => $nextDue !== '' ? $nextDue : null,
                '优先级' => (string) ($row['优先级'] ?? '中'),
                '重复规则' => $repeatRule,
                '待办状态' => '待处理',
                '开始操作时间' => $now,
                '操作记录' => '新增',
                '操作来源' => '周期任务自动生成',
                '操作人员' => $operator,
                '操作时间' => $now,
                '删除标识' => '0',
                '有效标识' => '1',
            ];
            if (!empty($row['父GUID'])) {
                $next['父GUID'] = (int) $row['父GUID'];
            }
            $this->insertRow('oa_todo', $next);

            $newGuid = (int) $this->model->getDb()->insertID();
            if ($newGuid > 0) {
                $this->insertLog($newGuid, '新增', $operator, [['重复规则', '空', $repeatRule]], sprintf('周期任务自动生成（%s），截止：%s', $repeatRule, $nextDue ?: '未设置'));
            }
        } catch (\Throwable $e) {
            log_message('error', '[TodoService::createNextPeriod] ' . $e->getMessage());
        }
    }

    /**
     * 开始待办（待处理 → 进行中）
     *
     * @return int 影响行数（-1=不存在，-3=状态不允许）
     */
    public function startTodo(string $guid, string $operator): int
    {
        return $this->changeStatus($guid, ['待处理'], '进行中', '开始', '开始进行', $operator);
    }

    /**
     * 取消待办（→ 已取消，需填原因）
     *
     * @return int 影响行数（-1=不存在，-3=状态不允许）
     */
    public function cancelTodo(string $guid, string $reason, string $operator): int
    {
        $reason = trim($reason);
        return $this->changeStatus($guid, ['待处理', '进行中'], '已取消', '取消', '取消待办', $operator, $reason);
    }

    /**
     * 重新打开（已完成/已取消 → 进行中）
     *
     * @return int 影响行数（-1=不存在，-3=状态不允许）
     */
    public function reopenTodo(string $guid, string $operator): int
    {
        return $this->changeStatus($guid, ['已完成', '已取消'], '进行中', '重新打开', '重新打开待办', $operator);
    }

    /**
     * 催办（不改状态，仅记流水；预留消息推送挂点）
     *
     * @return int 影响行数（-1=不存在）
     */
    public function urgeTodo(string $guid, string $operator): int
    {
        $guid = trim($guid);
        if ($guid === '' || !ctype_digit($guid)) {
            throw new BusinessException('待办GUID无效');
        }
        $row = $this->model->select(sprintf(
            'select GUID, 待办标题, 负责人, 待办状态 from oa_todo where GUID=%s and 有效标识="1" and 删除标识="0"',
            $this->model->quote($guid)
        ))->getRowArray();
        if (!$row) {
            return -1;
        }
        if (((string) $row['待办状态']) === '已完成' || ((string) $row['待办状态']) === '已取消') {
            throw new BusinessException('待办已结束，无需催办');
        }

        $this->insertLog((int) $guid, '催办', $operator, [], sprintf('催办负责人: %s', (string) $row['负责人']));

        // 站内消息触达负责人（排除催办人自己）
        $operatorName = $this->fetchUserName($operator) ?? $operator;
        $receivers = array_diff(array_filter(explode(',', (string) $row['负责人'])), [$operator]);
        $this->messageService->sendBatch(
            $receivers,
            '催办',
            sprintf('【催办】%s 请尽快处理：%s', $operatorName, (string) $row['待办标题']),
            '',
            (int) $guid
        );

        return 1;
    }

    /**
     * 状态流转通用方法（带前置状态校验与流水）
     *
     * @param string        $guid       待办GUID
     * @param array|null    $fromStates 允许的前置状态列表（null=不限）
     * @param string        $toState    目标状态
     * @param string        $action     流水动作
     * @param string        $opRecord   主表操作记录
     * @param string        $operator   操作人
     * @param string        $note       流水备注
     * @return int 影响行数（-1=不存在，-3=状态不允许）
     */
    private function changeStatus(
        string $guid,
        ?array $fromStates,
        string $toState,
        string $action,
        string $opRecord,
        string $operator,
        string $note = ''
    ): int {
        $guid = trim($guid);
        if ($guid === '' || !ctype_digit($guid)) {
            throw new BusinessException('待办GUID无效');
        }
        $row = $this->model->select(sprintf(
            'select GUID, 待办标题, 待办状态, 负责人 from oa_todo where GUID=%s and 有效标识="1" and 删除标识="0"',
            $this->model->quote($guid)
        ))->getRowArray();
        if (!$row) {
            return -1;
        }
        $current = (string) $row['待办状态'];

        // 前置状态校验
        if ($fromStates !== null && !in_array($current, $fromStates, true)) {
            return -3;
        }

        $changes = [['待办状态', $current, $toState]];
        $now = date('Y-m-d H:i:s');
        $this->model->exec(sprintf(
            'update oa_todo set 待办状态=%s, 操作记录=%s, 操作来源=%s, 操作人员=%s, 操作时间=%s
             where GUID=%s and 有效标识="1" and 删除标识="0"',
            $this->model->quote($toState),
            $this->model->quote($opRecord),
            $this->model->quote('页面' . $action),
            $this->model->quote($operator),
            $this->model->quote($now),
            $this->model->quote($guid)
        ));

        $this->insertLog((int) $guid, $action, $operator, $changes, $note);

        // 取消/重新打开通知负责人（排除操作人自己）
        $msgType = match ($action) {
            '取消' => '已取消',
            '重新打开' => '新待办',
            default => null,
        };
        if ($msgType !== null) {
            $operatorName = $this->fetchUserName($operator) ?? $operator;
            $receivers = array_diff(array_filter(explode(',', (string) $row['负责人'])), [$operator]);
            $title = sprintf(
                '%s%s：%s',
                $operatorName,
                $action === '取消' ? ' 取消了待办' : ' 重新打开了待办',
                (string) $row['待办标题']
            );
            if ($note !== '') {
                $title .= '，原因：' . $note;
            }
            $this->messageService->sendBatch($receivers, $msgType, $title, '', (int) $guid);
        }

        return (int) $this->model->getDb()->affectedRows();
    }

    /**
     * 转办（修改负责人，支持多个）
     *
     * @param string       $guid 待办GUID
     * @param string|array $newAssignee 新负责人工号（单个/数组/逗号分隔）
     * @param string       $operator 操作人工号
     * @return int 影响行数（-1=不存在）
     */
    public function reassign(string $guid, string|array $newAssignee, string $operator): int
    {
        $guid = trim($guid);
        if ($guid === '' || !ctype_digit($guid)) {
            throw new BusinessException('待办GUID无效');
        }
        $newAssignee = $this->normalizeAssignees($newAssignee);
        if ($newAssignee === '') {
            throw new BusinessException('新负责人不能为空');
        }

        $row = $this->model->select(sprintf(
            'select GUID, 负责人 from oa_todo where GUID=%s and 有效标识="1" and 删除标识="0"',
            $this->model->quote($guid)
        ))->getRowArray();
        if (!$row) {
            return -1;
        }

        $oldAssignee = trim((string) ($row['负责人'] ?? ''));

        $now = date('Y-m-d H:i:s');
        $this->model->exec(sprintf(
            'update oa_todo set 负责人=%s, 操作记录="转办", 操作来源="页面转办", 操作人员=%s, 操作时间=%s
             where GUID=%s and 有效标识="1" and 删除标识="0"',
            $this->model->quote($newAssignee),
            $this->model->quote($operator),
            $this->model->quote($now),
            $this->model->quote($guid)
        ));

        $changes = $oldAssignee === $newAssignee ? [] : [['负责人', $oldAssignee !== '' ? $oldAssignee : '空', $newAssignee]];
        $this->insertLog((int) $guid, '转办', $operator, $changes, sprintf('转办给: %s', $newAssignee));

        // 通知新负责人（排除转办人自己）
        $todoTitle = $this->model->select(sprintf(
            'select 待办标题, 截止日期 from oa_todo where GUID=%s',
            $this->model->quote($guid)
        ))->getRowArray();
        $operatorName = $this->fetchUserName($operator) ?? $operator;
        $due = $todoTitle ? trim((string) ($todoTitle['截止日期'] ?? '')) : '';
        $this->messageService->sendBatch(
            array_diff(explode(',', $newAssignee), [$operator]),
            '转办',
            sprintf('%s 将待办转办给您：%s', $operatorName, $todoTitle ? (string) $todoTitle['待办标题'] : ''),
            $due !== '' ? '截止日期：' . $due : '',
            (int) $guid
        );

        return (int) $this->model->getDb()->affectedRows();
    }

    /**
     * 批量删除（软删）
     *
     * @param array  $guids 待办GUID列表
     * @param string $operator 操作人工号
     * @return int 删除数
     */
    public function deleteTodos(array $guids, string $operator): int
    {
        $guids = array_values(array_filter(array_map(
            fn($g) => trim((string) $g),
            $guids
        ), fn($g) => $g !== '' && ctype_digit($g)));
        if ($guids === []) {
            throw new BusinessException('请选择要删除的待办');
        }

        $in = implode(',', array_map(fn($g) => $this->model->quote($g), $guids));
        $now = date('Y-m-d H:i:s');

        $this->model->exec(sprintf(
            'update oa_todo set 有效标识="0", 删除标识="1", 操作记录="删除", 操作来源="页面删除", 操作人员=%s, 操作时间=%s
             where GUID in (%s) and 有效标识="1" and 删除标识="0"',
            $this->model->quote($operator),
            $this->model->quote($now),
            $in
        ));

        // 每条待办分别记流水
        foreach ($guids as $g) {
            $this->insertLog((int) $g, '删除', $operator);
        }

        return (int) $this->model->getDb()->affectedRows();
    }

    /**
     * 获取待办详情
     *
     * @param string $guid 待办GUID
     * @return array|null
     */
    public function getDetail(string $guid): ?array
    {
        $guid = trim($guid);
        if ($guid === '' || !ctype_digit($guid)) {
            return null;
        }

        $row = $this->model->select(sprintf(
            'select "task" as todoType, GUID, 待办标题 as title, 待办描述 as description,
                    负责人 as assignee, 指派人 as assigner, 截止日期 as dueDate,
                    优先级 as priority, 待办状态 as status,
                    来源类型 as sourceType, 来源摘要 as sourceTitle, 来源GUID as sourceGuid,
                    完成时间 as completedAt, 完成说明 as completedNote,
                    关联人员编码 as personCode,
                    置顶标识 as pinned, 重复规则 as repeatRule, 附件 as attachments, 父GUID as parentGuid,
                    开始操作时间 as createdAt, 操作时间 as updatedAt,
                    "" as bizType, "" as bizId, "" as instanceId, "" as nodeCode
             from oa_todo
             where GUID=%s and 有效标识="1" and 删除标识="0"',
            $this->model->quote($guid)
        ))->getRowArray();

        return $row ?: null;
    }

    // ============================================================
    // 选项
    // ============================================================

    public function getOptions(): array
    {
        return [
            '优先级' => self::PRIORITY_LEVELS,
            '待办状态' => self::TODO_STATUSES,
            '来源类型' => self::SOURCE_TYPES,
        ];
    }

    // ============================================================
    // 内部工具
    // ============================================================

    private function assertTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            throw new BusinessException('待办标题不能为空');
        }
        if (mb_strlen($title) > 200) {
            throw new BusinessException('待办标题不能超过 200 字');
        }
        return $this->model->quote($title);
    }

    private function assertOption(string $value, array $options, string $label): string
    {
        $value = trim($value);
        if (!in_array($value, $options, true)) {
            throw new BusinessException($label . '无效，可选：' . implode('/', $options));
        }
        return $value;
    }

    /** 空值转 NULL 字面量，非空转 quote 值 */
    private function valOrNull(mixed $value): string
    {
        $s = trim((string) ($value ?? ''));
        return $s === '' ? 'NULL' : $this->model->quote($s);
    }

    /**
     * 置顶/取消置顶
     *
     * @return array{pinned:bool}
     */
    public function togglePin(string $guid, string $operator): array
    {
        $guid = trim($guid);
        if ($guid === '' || !ctype_digit($guid)) {
            throw new BusinessException('待办GUID无效');
        }
        $row = $this->model->select(sprintf(
            'select GUID, 置顶标识 from oa_todo where GUID=%s and 有效标识="1" and 删除标识="0"',
            $this->model->quote($guid)
        ))->getRowArray();
        if (!$row) {
            throw new BusinessException('待办不存在');
        }
        $newVal = ((string) $row['置顶标识']) === '1' ? '0' : '1';
        $this->model->exec(sprintf(
            'update oa_todo set 置顶标识=%s where GUID=%s and 有效标识="1" and 删除标识="0"',
            $this->model->quote($newVal),
            $this->model->quote($guid)
        ));
        $this->insertLog((int) $guid, $newVal === '1' ? '置顶' : '取消置顶', $operator);
        return ['pinned' => $newVal === '1'];
    }

    /**
     * 子任务列表（详情弹窗用）
     */
    public function getSubtasks(string $guid): array
    {
        $guid = trim($guid);
        if ($guid === '' || !ctype_digit($guid)) {
            throw new BusinessException('待办GUID无效');
        }
        $rows = $this->model->select(sprintf(
            'select GUID, 待办标题 as title, 待办状态 as status, 负责人 as assignee, 截止日期 as dueDate,
                    优先级 as priority, 完成时间 as completedAt
             from oa_todo where 父GUID=%s and 有效标识="1" and 删除标识="0" order by GUID',
            $this->model->quote($guid)
        ))->getResultArray();
        $total = count($rows);
        $done = count(array_filter($rows, fn($r) => $r['status'] === '已完成'));
        return ['list' => $rows, 'total' => $total, 'done' => $done];
    }

    /**
     * 待办评论列表
     */
    public function getComments(string $guid): array
    {
        $guid = trim($guid);
        if ($guid === '' || !ctype_digit($guid)) {
            throw new BusinessException('待办GUID无效');
        }
        return $this->model->select(sprintf(
            'select GUID, 评论人 as author, 评论人姓名 as authorName, 评论内容 as content, 创建时间 as createdAt
             from oa_todo_comment where 待办GUID=%s order by GUID',
            $this->model->quote($guid)
        ))->getResultArray();
    }

    /**
     * 添加评论（通知负责人/指派人，排除评论人自己）
     *
     * @return array 新评论
     */
    public function addComment(string $guid, string $content, string $operator): array
    {
        $guid = trim($guid);
        if ($guid === '' || !ctype_digit($guid)) {
            throw new BusinessException('待办GUID无效');
        }
        $content = trim($content);
        if ($content === '') {
            throw new BusinessException('评论内容不能为空');
        }
        if (mb_strlen($content) > 1000) {
            throw new BusinessException('评论内容不能超过1000字');
        }

        $todo = $this->model->select(sprintf(
            'select GUID, 待办标题, 负责人, 指派人 from oa_todo where GUID=%s and 有效标识="1" and 删除标识="0"',
            $this->model->quote($guid)
        ))->getRowArray();
        if (!$todo) {
            throw new BusinessException('待办不存在');
        }

        $now = date('Y-m-d H:i:s');
        $authorName = $this->fetchUserName($operator);
        $this->insertRow('oa_todo_comment', [
            '待办GUID' => (int) $guid,
            '评论人' => $operator,
            '评论人姓名' => $authorName,
            '评论内容' => $content,
            '创建时间' => $now,
        ]);
        $commentId = (int) $this->model->getDb()->insertID();

        // 通知负责人与指派人（排除评论人自己）
        $receivers = array_unique(array_merge(
            array_filter(explode(',', (string) $todo['负责人'])),
            [$todo['指派人'] ?? '']
        ));
        $this->messageService->sendBatch(
            array_diff($receivers, [$operator]),
            '评论',
            sprintf('%s 评论了待办「%s」：%s', $authorName ?? $operator, (string) $todo['待办标题'], mb_substr($content, 0, 80)),
            '',
            (int) $guid
        );

        return [
            'GUID' => $commentId,
            'author' => $operator,
            'authorName' => $authorName,
            'content' => $content,
            'createdAt' => $now,
        ];
    }

    /**
     * 到期/逾期提醒扫描（定时任务调用，幂等：中/低优先级同一天同待办只提醒一次，
     * 高优先级逾期按 URGENT_OVERDUE_INTERVAL_HOURS 间隔续催）
     *
     * @return array{due:int, overdue:int} 发送的提醒条数
     */
    public function scanReminders(): array
    {
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $sentDue = 0;
        $sentOverdue = 0;

        // 明天到期（提前一天提醒）
        $dueRows = $this->model->select(sprintf(
            'select GUID, 待办标题, 负责人, 截止日期 from oa_todo
             where 有效标识="1" and 删除标识="0" and 待办状态 in ("待处理","进行中")
               and 截止日期=%s and 父GUID is null',
            $this->model->quote($tomorrow)
        ))->getResultArray();
        foreach ($dueRows as $r) {
            if (!$this->hasRemindedToday((int) $r['GUID'], '到期提醒')) {
                $this->messageService->sendBatch(
                    array_filter(explode(',', (string) $r['负责人'])),
                    '到期提醒',
                    sprintf('待办明天到期：%s', (string) $r['待办标题']),
                    sprintf('截止日期：%s', (string) $r['截止日期']),
                    (int) $r['GUID']
                );
                $sentDue++;
            }
        }

        // 今天已逾期（高优先级按间隔续催，中/低优先级每天一次）
        $overdueRows = $this->model->select(sprintf(
            'select GUID, 待办标题, 负责人, 截止日期, 优先级 from oa_todo
             where 有效标识="1" and 删除标识="0" and 待办状态 in ("待处理","进行中")
               and 截止日期<%s and 截止日期 is not null and 父GUID is null',
            $this->model->quote($today)
        ))->getResultArray();
        foreach ($overdueRows as $r) {
            $reminded = (string) $r['优先级'] === '高'
                ? $this->hasRemindedSince((int) $r['GUID'], '逾期提醒', $this->urgentIntervalSince())
                : $this->hasRemindedToday((int) $r['GUID'], '逾期提醒');
            if (!$reminded) {
                $this->messageService->sendBatch(
                    array_filter(explode(',', (string) $r['负责人'])),
                    '逾期提醒',
                    sprintf('待办已逾期：%s', (string) $r['待办标题']),
                    sprintf('截止日期：%s', (string) $r['截止日期']),
                    (int) $r['GUID']
                );
                $sentOverdue++;
            }
        }

        return ['due' => $sentDue, 'overdue' => $sentOverdue];
    }

    /**
     * 今天是否已发过某待办的某类提醒（幂等防重）
     */
    private function hasRemindedToday(int $todoGuid, string $type): bool
    {
        return $this->hasRemindedSince($todoGuid, $type, date('Y-m-d 00:00:00'));
    }

    /**
     * 自给定时间以来是否已发过某待办的某类提醒（高优先级间隔防重）
     *
     * 注意：走 query()（无请求级缓存）——幂等判断必须读实时数据，
     * 避免 select() 的同 SQL 请求级缓存在同一请求/长进程内返回陈旧空结果。
     */
    private function hasRemindedSince(int $todoGuid, string $type, string $since): bool
    {
        $row = $this->model->query(
            'select GUID from oa_message where 关联待办GUID=? and 消息类型=? and 创建时间>=? limit 1',
            [$todoGuid, $type, $since]
        )->getRowArray();
        return $row !== null;
    }

    /**
     * 高优先级续催间隔的起点时间
     */
    private function urgentIntervalSince(): string
    {
        return date('Y-m-d H:i:s', strtotime(sprintf('-%d hours', self::URGENT_OVERDUE_INTERVAL_HOURS)));
    }

    /**
     * 紧急（高优先级）逾期待办即时检测
     *
     * 供铃铛未读数轮询、待办中心查询调用：负责人在线时无需等定时扫描，
     * 名下高优先级待办已逾期且距上次提醒超过续催间隔即立即补发（幂等），
     * 实现"紧急即时、不紧急定时"的双轨提醒。
     *
     * @param string $workId 当前用户工号
     * @return int 本次补发的提醒条数
     */
    public function checkUrgentOverdue(string $workId): int
    {
        $workId = trim($workId);
        if ($workId === '') {
            return 0;
        }

        $rows = $this->model->select(sprintf(
            'select GUID, 待办标题, 负责人, 截止日期 from oa_todo
             where 有效标识="1" and 删除标识="0" and 待办状态 in ("待处理","进行中")
               and 优先级="高" and 截止日期<%s and 截止日期 is not null and 父GUID is null
               and FIND_IN_SET(%s, 负责人)>0',
            $this->model->quote(date('Y-m-d')),
            $this->model->quote($workId)
        ))->getResultArray();

        $sent = 0;
        foreach ($rows as $r) {
            if (!$this->hasRemindedSince((int) $r['GUID'], '逾期提醒', $this->urgentIntervalSince())) {
                $this->messageService->sendBatch(
                    array_filter(explode(',', (string) $r['负责人'])),
                    '逾期提醒',
                    sprintf('待办已逾期：%s', (string) $r['待办标题']),
                    sprintf('截止日期：%s', (string) $r['截止日期']),
                    (int) $r['GUID']
                );
                $sent++;
            }
        }
        return $sent;
    }

    private function insertRow(string $table, array $row): void
    {
        $fields = array_map(fn($k) => sprintf('`%s`', $k), array_keys($row));
        $values = array_map(
            fn($v) => is_int($v) ? (string) $v : ($v === null ? 'NULL' : $this->model->quote((string) $v)),
            array_values($row)
        );
        $this->model->exec(sprintf(
            'insert into %s (%s) values (%s)',
            $table,
            implode(',', $fields),
            implode(',', $values)
        ));
    }
}
