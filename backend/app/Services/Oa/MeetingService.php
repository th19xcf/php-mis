<?php

namespace App\Services\Oa;

use App\Exceptions\BusinessException;
use App\Models\Mcommon;

/**
 * 会议纪要服务（oa_meeting / oa_attendee）
 *
 * 主流会议纪要产品（飞书/钉钉/腾讯会议）的核心闭环：
 *   建会（主题/时间/地点/参会人）→ 开会 → 记录纪要 → 提交定稿（锁定）
 *   → 行动项转待办（负责人/截止日期）→ 跟进闭环
 *
 * 本阶段（阶段1+2）落地：纯文本纪要 + 行动项转 oa_todo；
 * 富文本/模板/提醒推送属于阶段3。
 *
 * 设计约定：
 *   1. 参会人（oa_attendee）为 1:N 子表，随会议主表事务整组替换（无独立编辑入口）
 *   2. 纪要状态 0=草稿 1=已提交：提交后纪要与参会人锁定，仅 会议状态 可继续流转
 *   3. 行动项转待办：oa_todo.来源类型='会议' + 来源GUID + 来源摘要（列表免 JOIN）
 *   4. 审计八列由本服务统一写入（对齐 AuditFieldsTrait 列名惯例）
 */
class MeetingService
{
    private Mcommon $model;

    /** 会议类型选项 */
    public const MEETING_TYPES = ['例会', '专题会', '面试评估会', '其他'];

    /** 会议状态选项 */
    public const MEETING_STATUSES = ['待召开', '进行中', '已结束'];

    /** 参会角色选项 */
    public const ATTENDEE_ROLES = ['主持', '记录', '参会'];

    /** 出席状态选项 */
    public const ATTEND_STATUSES = ['出席', '请假', '缺席', '待确认'];

    public function __construct()
    {
        $this->model = new Mcommon();
    }

    // ============================================================
    // 查询
    // ============================================================

    /**
     * 分页列表（3010 会议纪要页主数据源）
     *
     * @param array $params 筛选：keyword/会议类型/会议状态/纪要状态/开始时间起/开始时间止
     * @return array{list:array, total:int, page:int, pageSize:int}
     */
    public function getList(array $params, int $page = 1, int $pageSize = 20): array
    {
        $page = max(1, $page);
        $pageSize = min(500, max(1, $pageSize));
        $offset = ($page - 1) * $pageSize;

        $where = ['有效标识="1"', '删除标识="0"'];

        $keyword = trim((string) ($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $kw = $this->model->quote('%' . $keyword . '%');
            $where[] = sprintf('(会议主题 like %s or 会议地点 like %s)', $kw, $kw);
        }
        foreach (['会议类型', '会议状态', '纪要状态'] as $field) {
            $v = trim((string) ($params[$field] ?? ''));
            if ($v !== '') {
                $where[] = sprintf('%s=%s', $field, $this->model->quote($v));
            }
        }
        $startFrom = trim((string) ($params['开始时间起'] ?? $params['startFrom'] ?? ''));
        if ($startFrom !== '') {
            $where[] = '开始时间 >= ' . $this->model->quote($startFrom);
        }
        $startTo = trim((string) ($params['开始时间止'] ?? $params['startTo'] ?? ''));
        if ($startTo !== '') {
            $where[] = '开始时间 <= ' . $this->model->quote($startTo . ' 23:59:59');
        }
        $whereSql = implode(' and ', $where);

        $countRow = $this->model->select(
            "select count(*) as cnt from oa_meeting where {$whereSql}"
        )->getRowArray();
        $total = (int) ($countRow['cnt'] ?? 0);

        $sql = sprintf(
            'select GUID,会议主题,会议类型,开始时间,结束时间,会议地点,组织人,记录人,
                    会议状态,纪要状态,关联人员编码,操作时间,操作人员
             from oa_meeting
             where %s
             order by if(isnull(开始时间),1,0), 开始时间 desc, GUID desc
             limit %d offset %d',
            $whereSql,
            $pageSize,
            $offset
        );
        $list = $this->model->select($sql)->getResultArray();

        return ['list' => $list, 'total' => $total, 'page' => $page, 'pageSize' => $pageSize];
    }

    /**
     * 会议详情（含参会人与关联行动项）
     *
     * @return array|null {meeting:array, attendees:array, todos:array}
     */
    public function getDetail(string $guid): ?array
    {
        $guid = trim($guid);
        if ($guid === '' || !ctype_digit($guid)) {
            return null;
        }

        $meeting = $this->model->select(sprintf(
            'select GUID,会议主题,会议类型,开始时间,结束时间,会议地点,组织人,记录人,
                    会议状态,纪要状态,会议纪要,关联人员编码,开始操作时间,操作时间,操作人员
             from oa_meeting
             where GUID=%s and 有效标识="1" and 删除标识="0"',
            $this->model->quote($guid)
        ))->getRowArray();

        if (!$meeting) {
            return null;
        }

        $attendees = $this->model->select(sprintf(
            'select GUID,参会人,参会人姓名,参会角色,出席状态
             from oa_attendee
             where 会议GUID=%s and 有效标识="1" and 删除标识="0"
             order by field(参会角色,"主持","记录","参会"), 参会人',
            $this->model->quote($guid)
        ))->getResultArray();

        $todos = $this->model->select(sprintf(
            'select GUID,待办标题,负责人,截止日期,优先级,待办状态,完成时间
             from oa_todo
             where 来源类型="会议" and 来源GUID=%s and 有效标识="1" and 删除标识="0"
             order by if(isnull(截止日期),1,0), 截止日期, GUID desc',
            $this->model->quote($guid)
        ))->getResultArray();

        return ['meeting' => $meeting, 'attendees' => $attendees, 'todos' => $todos];
    }

    // ============================================================
    // 新增 / 修改 / 删除
    // ============================================================

    /**
     * 新建会议（事务：主表 + 参会人整组写入）
     *
     * @param array  $data     会议字段 + attendees: [{参会人, 参会人姓名, 参会角色, 出席状态}]
     * @param string $operator 操作人工号
     * @return int 新会议 GUID
     * @throws BusinessException 校验失败或写入失败
     */
    public function createMeeting(array $data, string $operator): int
    {
        $subject = trim((string) ($data['会议主题'] ?? ''));
        if ($subject === '') {
            throw new BusinessException('会议主题不能为空');
        }
        if (mb_strlen($subject) > 200) {
            throw new BusinessException('会议主题不能超过 200 字');
        }

        $type = trim((string) ($data['会议类型'] ?? ''));
        if ($type === '') {
            $type = '例会';
        }
        if (!in_array($type, self::MEETING_TYPES, true)) {
            throw new BusinessException('会议类型无效，可选：' . implode('/', self::MEETING_TYPES));
        }

        $status = trim((string) ($data['会议状态'] ?? ''));
        if ($status === '') {
            $status = '待召开';
        }
        if (!in_array($status, self::MEETING_STATUSES, true)) {
            throw new BusinessException('会议状态无效，可选：' . implode('/', self::MEETING_STATUSES));
        }

        $this->assertDateTime('开始时间', (string) ($data['开始时间'] ?? ''));
        $this->assertDateTime('结束时间', (string) ($data['结束时间'] ?? ''));

        $organizer = trim((string) ($data['组织人'] ?? ''));
        if ($organizer === '') {
            $organizer = $operator;
        }
        $recorder = trim((string) ($data['记录人'] ?? ''));

        $attendees = $this->normalizeAttendees((array) ($data['attendees'] ?? []));

        $now = date('Y-m-d H:i:s');
        $row = [
            '会议主题'     => $subject,
            '会议类型'     => $type,
            '开始时间'     => $this->valOrNull($data['开始时间'] ?? ''),
            '结束时间'     => $this->valOrNull($data['结束时间'] ?? ''),
            '会议地点'     => trim((string) ($data['会议地点'] ?? '')),
            '组织人'       => $organizer,
            '记录人'       => $recorder,
            '会议状态'     => $status,
            '纪要状态'     => '0',
            '会议纪要'     => $this->valOrNull($data['会议纪要'] ?? ''),
            '关联人员编码' => trim((string) ($data['关联人员编码'] ?? '')),
            '开始操作时间' => $now,
            '操作记录'     => '新增',
            '操作来源'     => '页面新增',
            '操作人员'     => $operator,
            '操作时间'     => $now,
            '删除标识'     => '0',
            '有效标识'     => '1',
        ];

        $db = $this->model->getDb();
        $db->transBegin();
        try {
            $this->insertRow('oa_meeting', $row);
            $meetingGuid = (int) $db->insertID();
            if ($meetingGuid <= 0) {
                throw new BusinessException('会议创建失败');
            }

            foreach ($attendees as $att) {
                $this->insertRow('oa_attendee', $att + [
                    '会议GUID'     => $meetingGuid,
                    '开始操作时间' => $now,
                    '操作记录'     => '新增',
                    '操作来源'     => '页面新增',
                    '操作人员'     => $operator,
                    '操作时间'     => $now,
                    '删除标识'     => '0',
                    '有效标识'     => '1',
                ]);
            }

            $db->transCommit();
            return $meetingGuid;
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }
    }

    /**
     * 修改会议（事务：主表字段 + 参会人整组替换）
     *
     * 纪要已提交（纪要状态='1'）后：会议纪要/参会人锁定，仅允许流转 会议状态；
     * 字段级锁定在阶段2保持简单——提交后修改仅接受 会议状态 字段。
     *
     * @return int 影响行数（0=无变更，-1=会议不存在）
     */
    public function updateMeeting(string $guid, array $data, string $operator): int
    {
        $guid = trim($guid);
        if ($guid === '' || !ctype_digit($guid)) {
            throw new BusinessException('会议GUID无效');
        }

        $old = $this->model->select(sprintf(
            'select GUID,会议主题,会议类型,开始时间,结束时间,会议地点,组织人,记录人,
                    会议状态,纪要状态,会议纪要,关联人员编码
             from oa_meeting where GUID=%s and 有效标识="1" and 删除标识="0"',
            $this->model->quote($guid)
        ))->getRowArray();
        if (!$old) {
            return -1;
        }

        $locked = ((string) $old['纪要状态']) === '1';
        $now = date('Y-m-d H:i:s');

        $sets = [
            sprintf('操作记录=%s', $this->model->quote('修改')),
            sprintf('操作来源=%s', $this->model->quote('页面修改')),
            sprintf('操作人员=%s', $this->model->quote($operator)),
            sprintf('操作时间=%s', $this->model->quote($now)),
        ];

        if (!$locked) {
            $fieldMap = [
                '会议主题'     => fn($v) => $this->assertSubject((string) $v),
                '会议类型'     => fn($v) => $this->assertOption((string) $v, self::MEETING_TYPES, '会议类型'),
                '开始时间'     => fn($v) => $this->valOrNull($v),
                '结束时间'     => fn($v) => $this->valOrNull($v),
                '会议地点'     => fn($v) => $this->model->quote(trim((string) $v)),
                '组织人'       => fn($v) => $this->model->quote(trim((string) $v)),
                '记录人'       => fn($v) => $this->model->quote(trim((string) $v)),
                '会议纪要'     => fn($v) => $this->valOrNull($v),
                '关联人员编码' => fn($v) => $this->model->quote(trim((string) $v)),
            ];
            foreach ($fieldMap as $field => $converter) {
                if (array_key_exists($field, $data)) {
                    $sets[] = sprintf('`%s`=%s', $field, $converter($data[$field]));
                }
            }
            // 会议状态任何阶段都可流转
            if (array_key_exists('会议状态', $data)) {
                $sets[] = sprintf('`会议状态`=%s', $this->model->quote(
                    $this->assertOption((string) $data['会议状态'], self::MEETING_STATUSES, '会议状态')
                ));
            }
        } else {
            // 定稿后：除 会议状态（与定位键）外一律拒绝，参会人单列文案
            if (array_key_exists('attendees', $data)) {
                throw new BusinessException('纪要已提交定稿，参会人不可修改');
            }
            $bizKeys = array_diff(array_keys($data), ['GUID', 'guid', '会议状态']);
            if ($bizKeys !== []) {
                throw new BusinessException('纪要已提交定稿，仅允许流转会议状态');
            }
            if (array_key_exists('会议状态', $data)) {
                $sets[] = sprintf('`会议状态`=%s', $this->model->quote(
                    $this->assertOption((string) $data['会议状态'], self::MEETING_STATUSES, '会议状态')
                ));
            }
        }

        $db = $this->model->getDb();
        $db->transBegin();
        try {
            $affected = $db->query(sprintf(
                'update oa_meeting set %s where GUID=%s and 有效标识="1" and 删除标识="0"',
                implode(', ', $sets),
                $this->model->quote($guid)
            )) ? (int) $db->affectedRows() : 0;

            // 参会人整组替换（仅草稿态）
            if (!$locked && array_key_exists('attendees', $data)) {
                $attendees = $this->normalizeAttendees((array) ($data['attendees'] ?? []));
                $db->query(sprintf(
                    'update oa_attendee set 有效标识="0", 删除标识="1", 操作记录="随会议替换", 操作人员=%s, 操作时间=%s
                     where 会议GUID=%s and 有效标识="1" and 删除标识="0"',
                    $this->model->quote($operator),
                    $this->model->quote($now),
                    $this->model->quote($guid)
                ));
                foreach ($attendees as $att) {
                    $this->insertRow('oa_attendee', $att + [
                        '会议GUID'     => (int) $guid,
                        '开始操作时间' => $now,
                        '操作记录'     => '修改',
                        '操作来源'     => '页面修改',
                        '操作人员'     => $operator,
                        '操作时间'     => $now,
                        '删除标识'     => '0',
                        '有效标识'     => '1',
                    ]);
                }
            }

            $db->transCommit();
            return $affected;
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }
    }

    /**
     * 批量删除会议（软删，参会人随主表软删；关联待办保留由 TodoService 守护）
     *
     * @return int 删除会议数
     */
    public function deleteMeetings(array $guids, string $operator): int
    {
        $guids = array_values(array_filter(array_map(
            fn($g) => trim((string) $g),
            $guids
        ), fn($g) => $g !== '' && ctype_digit($g)));
        if ($guids === []) {
            throw new BusinessException('请选择要删除的会议');
        }

        $in = implode(',', array_map(fn($g) => $this->model->quote($g), $guids));
        $now = date('Y-m-d H:i:s');

        $db = $this->model->getDb();
        $db->transBegin();
        try {
            $db->query(sprintf(
                'update oa_meeting set 有效标识="0", 删除标识="1", 操作记录="删除", 操作来源="页面删除", 操作人员=%s, 操作时间=%s
                 where GUID in (%s) and 有效标识="1" and 删除标识="0"',
                $this->model->quote($operator),
                $this->model->quote($now),
                $in
            ));
            $affected = (int) $db->affectedRows();

            $db->query(sprintf(
                'update oa_attendee set 有效标识="0", 删除标识="1", 操作记录="随会议删除", 操作人员=%s, 操作时间=%s
                 where 会议GUID in (%s) and 有效标识="1" and 删除标识="0"',
                $this->model->quote($operator),
                $this->model->quote($now),
                $in
            ));

            $db->transCommit();
            return $affected;
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }
    }

    // ============================================================
    // 纪要定稿 / 行动项转待办
    // ============================================================

    /**
     * 纪要提交定稿（纪要状态 0→1，提交后纪要与参会人锁定）
     *
     * @return int 影响行数（-1=不存在，-2=已提交过）
     */
    public function submitMinutes(string $guid, string $operator): int
    {
        $guid = trim($guid);
        if ($guid === '' || !ctype_digit($guid)) {
            throw new BusinessException('会议GUID无效');
        }

        $row = $this->model->select(sprintf(
            'select GUID,纪要状态,会议纪要 from oa_meeting
             where GUID=%s and 有效标识="1" and 删除标识="0"',
            $this->model->quote($guid)
        ))->getRowArray();
        if (!$row) {
            return -1;
        }
        if (((string) $row['纪要状态']) === '1') {
            return -2;
        }
        if (trim((string) ($row['会议纪要'] ?? '')) === '') {
            throw new BusinessException('纪要正文为空，请先填写会议纪要');
        }

        $now = date('Y-m-d H:i:s');
        $affected = $this->model->exec(sprintf(
            'update oa_meeting set 纪要状态="1", 操作记录="纪要定稿", 操作来源="页面定稿", 操作人员=%s, 操作时间=%s
             where GUID=%s and 纪要状态="0" and 有效标识="1" and 删除标识="0"',
            $this->model->quote($operator),
            $this->model->quote($now),
            $this->model->quote($guid)
        ));

        return $affected > 0 ? $affected : -2;
    }

    /**
     * 行动项转待办（oa_todo，来源类型='会议'）
     *
     * @param array  $todoData 待办字段：待办标题(必填)/负责人(必填)/截止日期/优先级/待办描述
     * @return int 新待办 GUID
     */
    public function createTodoFromMeeting(string $guid, array $todoData, string $operator): int
    {
        $guid = trim($guid);
        if ($guid === '' || !ctype_digit($guid)) {
            throw new BusinessException('会议GUID无效');
        }

        $meeting = $this->model->select(sprintf(
            'select GUID,会议主题 from oa_meeting
             where GUID=%s and 有效标识="1" and 删除标识="0"',
            $this->model->quote($guid)
        ))->getRowArray();
        if (!$meeting) {
            throw new BusinessException('会议不存在或已删除');
        }

        $todoData['来源类型'] = '会议';
        $todoData['来源GUID'] = (int) $guid;
        $todoData['来源摘要'] = (string) $meeting['会议主题'];
        if (trim((string) ($todoData['指派人'] ?? '')) === '') {
            $todoData['指派人'] = $operator;
        }

        return (new TodoService())->createTodo($todoData, $operator);
    }

    // ============================================================
    // 选项 / 人员选择
    // ============================================================

    /**
     * 下拉选项（会议类型/状态、参会角色、出席状态）
     */
    public function getOptions(): array
    {
        return [
            '会议类型' => self::MEETING_TYPES,
            '会议状态' => self::MEETING_STATUSES,
            '参会角色' => self::ATTENDEE_ROLES,
            '出席状态' => self::ATTEND_STATUSES,
            '优先级'   => TodoService::PRIORITY_LEVELS,
            '待办状态' => TodoService::TODO_STATUSES,
        ];
    }

    /**
     * 参会人选择数据（def_user，关键字过滤工号/姓名）
     *
     * @return array [{工号, 姓名}]
     */
    public function getUserOptions(string $keyword = '', int $limit = 50): array
    {
        $keyword = trim($keyword);
        $limit = min(100, max(1, $limit));

        $where = ['有效标识="1"'];
        if ($keyword !== '') {
            $kw = $this->model->quote('%' . $keyword . '%');
            $where[] = sprintf('(工号 like %s or 姓名 like %s)', $kw, $kw);
        }

        return $this->model->select(sprintf(
            'select 工号, 姓名 from def_user where %s group by 工号, 姓名 order by 工号 limit %d',
            implode(' and ', $where),
            $limit
        ))->getResultArray();
    }

    // ============================================================
    // 内部工具
    // ============================================================

    /** 参会人清单归一化与校验（去空、校验角色/出席状态、姓名冗余允许前端传入） */
    private function normalizeAttendees(array $attendees): array
    {
        $result = [];
        foreach ($attendees as $att) {
            if (!is_array($att)) {
                continue;
            }
            $workId = trim((string) ($att['参会人'] ?? $att['工号'] ?? ''));
            if ($workId === '') {
                continue;
            }
            $role = trim((string) ($att['参会角色'] ?? ''));
            if ($role === '') {
                $role = '参会';
            }
            if (!in_array($role, self::ATTENDEE_ROLES, true)) {
                throw new BusinessException('参会角色无效：' . $role);
            }
            $attendStatus = trim((string) ($att['出席状态'] ?? ''));
            if ($attendStatus === '') {
                $attendStatus = '待确认';
            }
            if (!in_array($attendStatus, self::ATTEND_STATUSES, true)) {
                throw new BusinessException('出席状态无效：' . $attendStatus);
            }
            $result[] = [
                '参会人'     => $workId,
                '参会人姓名' => trim((string) ($att['参会人姓名'] ?? $att['姓名'] ?? '')),
                '参会角色'   => $role,
                '出席状态'   => $attendStatus,
            ];
        }

        // 同一会议工号去重（后到覆盖）
        $dedup = [];
        foreach ($result as $att) {
            $dedup[$att['参会人']] = $att;
        }
        return array_values($dedup);
    }

    /** 主题校验（返回 quote 后值，供 SET 子句直接使用） */
    private function assertSubject(string $subject): string
    {
        $subject = trim($subject);
        if ($subject === '') {
            throw new BusinessException('会议主题不能为空');
        }
        if (mb_strlen($subject) > 200) {
            throw new BusinessException('会议主题不能超过 200 字');
        }
        return $this->model->quote($subject);
    }

    /** 枚举校验（返回原值，供 SET 子句 quote 使用） */
    private function assertOption(string $value, array $options, string $label): string
    {
        $value = trim($value);
        if (!in_array($value, $options, true)) {
            throw new BusinessException($label . '无效，可选：' . implode('/', $options));
        }
        return $value;
    }

    /** 日期时间格式校验（Y-m-d H:i:s 或 Y-m-d） */
    private function assertDateTime(string $label, string $value): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $value)) {
            throw new BusinessException($label . '格式无效，应为日期或日期时间');
        }
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
            fn($v) => is_int($v) ? (string) $v : $this->model->quote((string) $v),
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
