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

    /** 优先级选项 */
    public const PRIORITY_LEVELS = ['高', '中', '低'];

    /** 待办状态选项 */
    public const TODO_STATUSES = ['待处理', '进行中', '已完成', '已取消'];

    /** 来源类型选项 */
    public const SOURCE_TYPES = ['手动', '会议', '工作流', '合同'];

    public function __construct()
    {
        $this->model = new Mcommon();
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

        // 1. 任务待办（oa_todo）——负责人或指派人=我
        $taskWhere = ['有效标识="1"', '删除标识="0"'];
        $taskWhere[] = sprintf('(负责人=%s or 指派人=%s)', $this->model->quote($workId), $this->model->quote($workId));

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
                    开始操作时间 as createdAt, 操作时间 as updatedAt
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
                    t.创建时间 as createdAt, t.更新时间 as updatedAt,
                    i.业务类型 as bizType, i.业务ID as bizId, i.GUID as instanceId, t.节点编码 as nodeCode
             from def_workflow_task t
             inner join def_workflow_instance i on i.GUID = t.实例ID
             inner join def_workflow_definition d on d.GUID = i.流程定义ID
             where %s',
            $wfWhereSql
        );

        // 3. UNION ALL 合并查询
        $unionSql = sprintf('(%s) union all (%s) order by createdAt desc', $taskSql, $wfSql);
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
        $w = sprintf('有效标识="1" and 删除标识="0" and (负责人=%s or 指派人=%s)', $this->model->quote($workId), $this->model->quote($workId));

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

    /**
     * 新建待办
     *
     * @param array  $data 待办字段：待办标题(必填)/负责人(必填)/截止日期/优先级/待办描述/来源类型/指派人
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

        $assignee = trim((string) ($data['负责人'] ?? ''));
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

        $this->insertRow('oa_todo', $row);

        $db = $this->model->getDb();
        $guid = (int) $db->insertID();
        if ($guid <= 0) {
            throw new BusinessException('待办创建失败');
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
            'select GUID,待办标题,待办状态 from oa_todo where GUID=%s and 有效标识="1" and 删除标识="0"',
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
            '负责人' => fn($v) => $this->model->quote(trim((string) $v)),
            '截止日期' => fn($v) => $this->valOrNull($v),
            '优先级' => fn($v) => $this->model->quote($this->assertOption((string) $v, self::PRIORITY_LEVELS, '优先级')),
            '待办状态' => fn($v) => $this->model->quote($this->assertOption((string) $v, self::TODO_STATUSES, '待办状态')),
            '关联人员编码' => fn($v) => $this->model->quote(trim((string) $v)),
        ];

        foreach ($fieldMap as $field => $converter) {
            if (array_key_exists($field, $data)) {
                $sets[] = sprintf('`%s`=%s', $field, $converter($data[$field]));
            }
        }

        // 完成说明单独处理
        if (array_key_exists('完成说明', $data)) {
            $sets[] = sprintf('`完成说明`=%s', $this->valOrNull($data['完成说明']));
        }

        $sql = sprintf(
            'update oa_todo set %s where GUID=%s and 有效标识="1" and 删除标识="0"',
            implode(', ', $sets),
            $this->model->quote($guid)
        );
        $this->model->exec($sql);

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
            'select GUID,待办状态 from oa_todo where GUID=%s and 有效标识="1" and 删除标识="0"',
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

        return (int) $this->model->getDb()->affectedRows();
    }

    /**
     * 转办（修改负责人）
     *
     * @param string $guid 待办GUID
     * @param string $newAssignee 新负责人工号
     * @param string $operator 操作人工号
     * @return int 影响行数（-1=不存在）
     */
    public function reassign(string $guid, string $newAssignee, string $operator): int
    {
        $guid = trim($guid);
        if ($guid === '' || !ctype_digit($guid)) {
            throw new BusinessException('待办GUID无效');
        }
        $newAssignee = trim($newAssignee);
        if ($newAssignee === '') {
            throw new BusinessException('新负责人不能为空');
        }

        $row = $this->model->select(sprintf(
            'select GUID from oa_todo where GUID=%s and 有效标识="1" and 删除标识="0"',
            $this->model->quote($guid)
        ))->getRowArray();
        if (!$row) {
            return -1;
        }

        $now = date('Y-m-d H:i:s');
        $this->model->exec(sprintf(
            'update oa_todo set 负责人=%s, 操作记录="转办", 操作来源="页面转办", 操作人员=%s, 操作时间=%s
             where GUID=%s and 有效标识="1" and 删除标识="0"',
            $this->model->quote($newAssignee),
            $this->model->quote($operator),
            $this->model->quote($now),
            $this->model->quote($guid)
        ));

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
            'select GUID,待办标题,待办描述,来源类型,来源摘要,来源GUID,指派人,负责人,
                    截止日期,提醒时间,优先级,待办状态,完成时间,完成说明,父级GUID,关联人员编码,
                    开始操作时间,操作记录,操作来源,操作人员,操作时间
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

    /** 行插入（字段名反引号包裹，值均已 quote/为字面量） */
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
