<?php

namespace App\Services\Application;

use App\Models\Mcommon;
use App\Services\Audit\AuditLogService;
use RuntimeException;

/**
 * 流程实例服务（ee_application 写路径唯一入口）
 *
 * 阶段②A 落地：所有业务写路径在事务内同步维护实例表，对账从事后补数转为纯监控。
 *
 * 三个入口：
 * - createInstance：页面新增邀约（InvitationApi::add 事务内调用，双 INSERT 闭环）
 * - syncFromStore：邀约导入完成后批量补建（幂等，reconcile 兜底）
 * - transferStage：阶段流转状态机（三个 transfer 事务内调用）
 *
 * 状态机（硬编码于 STAGE_FLOW，配置表 def_stage_transfer 只管字段映射）：
 *   邀约 → 面试 | 终止(邀约拒绝)
 *   面试 → 培训 | 终止(面试未通过)
 *   培训 → 入职 | 终止(培训离开)
 *   入职 → 终止(离职)          ← 阶段③ ee_employment 落地后启用
 *   终态实例仅允许管理端复活，不走本服务
 *
 * 幂等与安全：
 * - createInstance/transferStage 遇已存在行自动跳过或补建，不报错（兼容重试）
 * - transferStage 的 UPDATE 带 当前阶段=:fromStage 条件，重复提交 0 行生效
 * - 非法状态转换抛 RuntimeException，由调用方回滚整个事务（连阶段表 INSERT 一起回滚）
 */
class ApplicationService
{
    /** 状态机转换表：from => [to, ...] */
    private const STAGE_FLOW = [
        '邀约' => ['面试', '终止'],
        '面试' => ['培训', '终止'],
        '培训' => ['入职', '终止'],
        '入职' => ['终止'],
    ];

    /** 终态（不允许再流转） */
    private const STAGE_FINAL = '终止';

    private Mcommon $model;

    public function __construct()
    {
        $this->model = new Mcommon();
    }

    /**
     * 新建流程实例（页面新增邀约，事务内调用）
     *
     * NOT EXISTS 幂等：实例已存在则跳过返回 false（兼容重试，不报错）。
     *
     * @param string $candidateCode 候选人编码（实例主键）
     * @param string $personCode    人员编码（可能为空——仅姓名无法建档的邀约）
     * @param string $invitationDate 邀约日期（YYYY-MM-DD，时间线首站）
     * @param string $operator      操作人工号
     * @return bool 是否新建（false=已存在跳过）
     */
    public function createInstance(
        string $candidateCode,
        string $personCode,
        string $invitationDate,
        string $operator
    ): bool {
        if ($candidateCode === '') {
            throw new RuntimeException('候选人编码不能为空，无法创建流程实例');
        }

        $db = $this->model->getDb();
        $now = date('Y-m-d H:i:s');

        $sql = sprintf(
            'INSERT INTO ee_application (
                候选人编码, 人员编码, 当前阶段, 邀约日期,
                操作记录, 操作来源, 操作人员, 开始操作时间, 操作时间,
                有效标识, 删除标识
            )
            SELECT %s, %s, "邀约", %s,
                "新增,实例", "页面", %s, %s, %s,
                "1", "0"
            FROM DUAL
            WHERE NOT EXISTS (
                SELECT 1 FROM ee_application WHERE 候选人编码 = %s
            )',
            $db->escape($candidateCode),
            $db->escape($personCode),
            $db->escape($invitationDate !== '' ? $invitationDate : null),
            $db->escape($operator),
            $db->escape($now),
            $db->escape($now),
            $db->escape($candidateCode)
        );

        $this->model->exec($sql);
        $inserted = $db->affectedRows() > 0;

        // hr_audit_log 事件行（严格模式：同事务，失败由调用方回滚）
        if ($inserted) {
            $newRow = $this->model->select(
                sprintf(
                    'SELECT GUID, UUID FROM ee_application WHERE 候选人编码 = %s LIMIT 1',
                    $db->escape($candidateCode)
                )
            )->getRowArray() ?: [];
            (new AuditLogService())->logEvent([
                '候选人编码' => $candidateCode,
                '人员编码'   => $personCode,
                '表名'      => 'ee_application',
                '记录GUID'  => (int) ($newRow['GUID'] ?? 0),
                '记录UUID'  => $newRow['UUID'] ?? null,
                '操作类型'  => '新增',
                '变更字段'  => '全部',
                '原值'      => null,
                '新值'      => '新建实例',
                '操作人员'  => $operator,
                '操作来源'  => '页面',
            ]);
        }

        return $inserted;
    }

    /**
     * 从 ee_store 批量补建实例（邀约导入后调用 / reconcile 修复）
     *
     * 与 ee_application_migrate.sql 第 1 部分同构：源活跃行 NOT EXISTS 幂等插入。
     * 独立短事务（调用方事务外执行），失败记日志不阻断导入结果返回，reconcile 兜底。
     *
     * @param string $operator 操作人工号（导入场景由 ImportService 透传，空则记 system）
     * @return int 新建实例行数
     */
    public function syncFromStore(string $operator = ''): int
    {
        $db = $this->model->getDb();
        $now = date('Y-m-d H:i:s');
        $op = $operator !== '' ? $operator : 'system';

        $sql = sprintf(
            'INSERT INTO ee_application (
                候选人编码, 人员编码, 当前阶段, 邀约日期,
                操作记录, 操作来源, 操作人员, 开始操作时间, 操作时间,
                有效标识, 删除标识
            )
            SELECT s.候选人编码, s.人员编码, "邀约",
                NULLIF(s.邀约日期, ""),
                "新增,实例", "导入", %s, %s, %s,
                "1", "0"
            FROM ee_store s
            WHERE s.有效标识 = "1" AND s.删除标识 = "0"
                AND IFNULL(s.候选人编码, "") <> ""
                AND NOT EXISTS (
                    SELECT 1 FROM ee_application a WHERE a.候选人编码 = s.候选人编码
                )',
            $db->escape($op),
            $db->escape($now),
            $db->escape($now)
        );

        $this->model->exec($sql);
        $count = $db->affectedRows();

        // hr_audit_log 批量汇总行（宽松模式：失败仅记日志不阻断，导入主流程外）
        if ($count > 0) {
            (new AuditLogService())->logBatchSummary(
                'ee_application',
                $op,
                '导入',
                $count,
                '批量补建实例'
            );
        }

        return $count;
    }

    /**
     * 阶段流转状态机（三个 transfer 事务内调用）
     *
     * 行为：
     * 1. 查出候选人编码对应的实例当前阶段，校验状态机合法性（非法抛异常回滚）
     * 2. 实例缺失的编码自动补建（SELECT ee_store 信息 INSERT，再流转）——
     *    兼容历史数据/并发窗口，不报错
     * 3. 批量 UPDATE：当前阶段 + 时间戳（COALESCE 保留首次）+ 终止信息
     *
     * @param array  $candidateCodes 候选人编码数组（transfer 由 guids 换算而来）
     * @param string $toStage        目标阶段：面试/培训/入职/终止
     * @param string $operator       操作人工号
     * @param array  $context        流转上下文：
     *                               - 面试日期/参培日期/入职日期：阶段时间戳（可空，空则不更新）
     *                               - 终止原因/终止日期：toStage=终止 时写入
     * @return int 实际更新行数（含自动补建后流转的行）
     * @throws RuntimeException 非法状态转换 / 候选人编码不存在于 ee_store
     */
    public function transferStage(
        array $candidateCodes,
        string $toStage,
        string $operator,
        array $context = []
    ): int {
        $candidateCodes = array_values(array_unique(array_filter(
            array_map('strval', $candidateCodes),
            fn($v) => $v !== ''
        )));

        if (empty($candidateCodes)) {
            return 0;
        }

        if (!in_array($toStage, ['面试', '培训', '入职', '终止'], true)) {
            throw new RuntimeException("非法目标阶段：{$toStage}");
        }

        $db = $this->model->getDb();
        $now = date('Y-m-d H:i:s');

        // 1. 实例缺失的编码自动补建（源=ee_store，幂等）
        $this->backfillMissing($candidateCodes, $operator);

        // 2. 查询实例当前阶段，校验状态机（含审计定位列：GUID/UUID/人员编码）
        $quoted = implode(',', array_map(
            fn($v) => $db->escape($v),
            $candidateCodes
        ));
        $rows = $this->model->select(
            "SELECT 候选人编码, 人员编码, 当前阶段, GUID, UUID FROM ee_application
             WHERE 候选人编码 IN ({$quoted})"
        )->getResultArray();

        if (count($rows) !== count($candidateCodes)) {
            // 补建后仍缺失 = ee_store 也无此码（数据异常，拒绝流转）
            $missing = array_diff(
                $candidateCodes,
                array_column($rows, '候选人编码')
            );
            throw new RuntimeException(
                '以下候选人编码在 ee_store 与 ee_application 中均不存在：'
                . implode(',', array_slice($missing, 0, 5))
            );
        }

        // 3. 状态机校验（每组 from → to 必须在 STAGE_FLOW 白名单内）
        $fromStages = array_unique(array_column($rows, '当前阶段'));
        foreach ($fromStages as $fromStage) {
            if ($fromStage === self::STAGE_FINAL) {
                throw new RuntimeException('实例已终止，不允许再流转');
            }
            $allowed = self::STAGE_FLOW[$fromStage] ?? [];
            if (!in_array($toStage, $allowed, true)) {
                throw new RuntimeException(
                    "非法状态转换：{$fromStage} → {$toStage}（允许：" . implode('/', $allowed) . '）'
                );
            }
        }

        // 4. 构造 UPDATE（时间戳 COALESCE 保留首次；终止信息仅在 toStage=终止 写入）
        $sets = [
            sprintf('当前阶段 = %s', $db->escape($toStage)),
        ];

        $timestampMap = [
            '面试' => '面试日期',
            '培训' => '参培日期',
            '入职' => '入职日期',
        ];
        if (isset($timestampMap[$toStage])) {
            $tsValue = trim((string) ($context[$timestampMap[$toStage]] ?? ''));
            if ($tsValue !== '') {
                $col = $timestampMap[$toStage];
                $sets[] = sprintf(
                    '%s = COALESCE(%s, %s)',
                    $col,
                    $col,
                    $db->escape($tsValue)
                );
            }
        }

        if ($toStage === self::STAGE_FINAL) {
            $reason = trim((string) ($context['终止原因'] ?? ''));
            $endDate = trim((string) ($context['终止日期'] ?? ''));
            $sets[] = sprintf('终止原因 = %s', $db->escape($reason !== '' ? $reason : '未说明'));
            if ($endDate !== '') {
                $sets[] = sprintf('终止日期 = COALESCE(终止日期, %s)', $db->escape($endDate));
            }
        }

        $sets[] = sprintf('操作记录 = "阶段流转,%s"', $db->escape($toStage));
        $sets[] = sprintf('操作人员 = %s', $db->escape($operator));
        $sets[] = sprintf('操作时间 = %s', $db->escape($now));

        // WHERE 带当前阶段条件（fromStage 集合内）：
        // 重复提交/并发窗口下二次执行 0 行生效，天然幂等
        $quotedFrom = implode(',', array_map(
            fn($v) => $db->escape($v),
            $fromStages
        ));

        $sql = sprintf(
            'UPDATE ee_application SET %s WHERE 候选人编码 IN (%s) AND 当前阶段 IN (%s)',
            implode(', ', $sets),
            $quoted,
            $quotedFrom
        );

        $affected = $this->model->exec($sql);

        // hr_audit_log 流转事件行（严格模式：同事务，失败由调用方回滚）
        // 逐实例记一行：变更字段=当前阶段，原值/新值=from→to（DDL 附注写入时机约定）
        if ($affected > 0) {
            $audit = new AuditLogService();
            foreach ($rows as $row) {
                $audit->logEvent([
                    '候选人编码' => (string) $row['候选人编码'],
                    '人员编码'   => (string) ($row['人员编码'] ?? ''),
                    '表名'      => 'ee_application',
                    '记录GUID'  => (int) ($row['GUID'] ?? 0),
                    '记录UUID'  => $row['UUID'] ?? null,
                    '操作类型'  => '流转',
                    '变更字段'  => '当前阶段',
                    '原值'      => (string) $row['当前阶段'],
                    '新值'      => $toStage,
                    '操作人员'  => $operator,
                    '操作来源'  => '流转',
                ]);
            }
        }

        return $affected;
    }

    /**
     * 补建缺失实例（源=ee_store，幂等）
     *
     * transfer 遇到无实例的候选人编码（历史数据/搬迁前新增）时自动补，
     * 补建为"邀约"阶段，随后正常走状态机流转。
     *
     * hr_audit_log 批量汇总行（宽松模式）：流转自愈属系统批量路径，
     * 一行汇总留痕，失败仅记日志不阻断流转事务。
     */
    private function backfillMissing(array $candidateCodes, string $operator): void
    {
        $db = $this->model->getDb();
        $now = date('Y-m-d H:i:s');

        $quoted = implode(',', array_map(
            fn($v) => $db->escape($v),
            $candidateCodes
        ));

        $sql = sprintf(
            'INSERT INTO ee_application (
                候选人编码, 人员编码, 当前阶段, 邀约日期,
                操作记录, 操作来源, 操作人员, 开始操作时间, 操作时间,
                有效标识, 删除标识
            )
            SELECT s.候选人编码, s.人员编码, "邀约", NULLIF(s.邀约日期, ""),
                "补建,实例", "流转", %s, %s, %s,
                "1", "0"
            FROM ee_store s
            WHERE s.候选人编码 IN (%s)
                AND NOT EXISTS (
                    SELECT 1 FROM ee_application a WHERE a.候选人编码 = s.候选人编码
                )',
            $db->escape($operator),
            $db->escape($now),
            $db->escape($now),
            $quoted
        );

        $this->model->exec($sql);
        $count = $db->affectedRows();

        if ($count > 0) {
            (new AuditLogService())->logBatchSummary(
                'ee_application',
                $operator,
                '流转',
                $count,
                '流转自愈补建'
            );
        }
    }
}
