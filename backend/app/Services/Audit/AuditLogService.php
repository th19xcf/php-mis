<?php

namespace App\Services\Audit;

use App\Models\Mcommon;
use RuntimeException;

/**
 * 人员审计日志服务（hr_audit_log 写入唯一入口）
 *
 * 阶段②B：人员模块六表的字段级变更轨迹（append-only，只 INSERT 不 UPDATE），
 * 回答"谁在什么时候把手机号从 A 改成了 B"（个保法合规要求）。
 *
 * 设计决策（2026-08-26 确认）：
 * - 严格模式：写日志失败抛异常，由调用方事务回滚业务写入
 *   （个保法优先：宁可不改，不可漏记）。无事务的调用方表现为请求报错。
 * - HR 表跳过 def_audit_log：六表只写 hr_audit_log（人员中心合规轨迹），
 *   通用审计（def_audit_log）不再重复记录（BaseApiController 三方法分流）。
 * - 暂不脱敏：身份证号/手机号原值入库，脱敏在展示层做。
 * - 批量路径（导入补建/流转自愈）写汇总行：宽松模式，失败仅记日志不阻断。
 *
 * 写入契约（对齐 def_audit_log）：
 * - 记录UUID：业务行含 UUID 列记实际值（binary 16），无则 0x00×16 占位
 * - 值比对：NULL 与空串视为相同（与 BaseApiController::updateRecord diff 语义一致）
 * - 只记变更列：值未变化的字段不写行，控制日志量
 */
class AuditLogService
{
    /** 纳入人员审计的表：写 hr_audit_log（严格模式）；其余表走 def_audit_log 通用审计 */
    public const AUDITED_TABLES = [
        'hr_person', 'ee_application',
        'ee_store', 'ee_interview', 'ee_train', 'ee_onjob',
    ];

    /** 定位键/技术列：参与日志定位，不作为 diff 字段 */
    private const LOCATOR_FIELDS = ['GUID', 'UUID', '人员编码', '候选人编码'];

    /** 原值/新值列宽（hr_audit_log.原值/新值 varchar(500)） */
    private const VALUE_WIDTH = 500;

    private Mcommon $model;

    public function __construct()
    {
        $this->model = new Mcommon();
    }

    /**
     * 是否为纳入人员审计的表
     */
    public function isAuditedTable(string $table): bool
    {
        return in_array($table, self::AUDITED_TABLES, true);
    }

    /**
     * 单事件行：新增/删除/流转/合并
     *
     * 严格模式：写失败抛 RuntimeException（调用方事务回滚）。
     *
     * @param array $entry 键值：
     *   人员编码/候选人编码（定位键，可空）、表名、记录GUID(int)、记录UUID(binary16|null)、
     *   操作类型（新增/修改/删除/流转/合并）、变更字段、原值/新值（string|null）、
     *   操作人员、操作来源
     */
    public function logEvent(array $entry): void
    {
        $uuidBin = $entry['记录UUID'] ?? null;
        if ($uuidBin === null || $uuidBin === '') {
            $uuidBin = str_repeat("\x00", 16);
        }

        $sql = sprintf(
            'INSERT INTO hr_audit_log (
                人员编码, 候选人编码, 表名, 记录GUID, 记录UUID,
                操作类型, 变更字段, 原值, 新值,
                操作人员, 操作来源, 操作时间
            ) VALUES (%s, %s, %s, %d, 0x%s, %s, %s, %s, %s, %s, %s, %s)',
            $this->model->quote(mb_substr((string) ($entry['人员编码'] ?? ''), 0, 15)),
            $this->model->quote(mb_substr((string) ($entry['候选人编码'] ?? ''), 0, 15)),
            $this->model->quote((string) ($entry['表名'] ?? '')),
            (int) ($entry['记录GUID'] ?? 0),
            bin2hex((string) $uuidBin),
            $this->model->quote(mb_substr((string) ($entry['操作类型'] ?? '修改'), 0, 10)),
            $this->model->quote(mb_substr((string) ($entry['变更字段'] ?? ''), 0, 50)),
            $this->valueExpr($entry['原值'] ?? null),
            $this->valueExpr($entry['新值'] ?? null),
            $this->model->quote(mb_substr((string) ($entry['操作人员'] ?? ''), 0, 10)),
            $this->model->quote(mb_substr((string) ($entry['操作来源'] ?? ''), 0, 50)),
            $this->model->quote(date('Y-m-d H:i:s'))
        );

        if ($this->model->exec($sql) <= 0) {
            // DBDebug=false 时 SQL 失败不抛异常，此处兜底检测（exec 返回 0/-1）
            throw new RuntimeException('hr_audit_log 事件日志写入失败');
        }
    }

    /**
     * 字段级 diff：修改场景（旧行快照 × 新值 → N 行）
     *
     * 严格模式：任一行写失败抛异常（调用方事务回滚）。
     *
     * 语义（与业务写入路径一致）：
     * - newData 中不在旧行快照里的字段跳过（空值跳过/非表列/控制字段——未写入即不审计）
     * - 值未变化跳过（NULL 与空串视为相同）
     * - 定位键/技术列（GUID/UUID/人员编码/候选人编码）只做定位不 diff
     *
     * @param string $table    业务表名
     * @param array  $oldRows  旧行快照数组（每行须含写入字段，定位键可选）
     * @param array  $newData  新值映射（字段名 => 值）
     * @param string $operator 操作人工号
     * @param string $source   操作来源（页面/工作台/页面修改等）
     * @return int 写入行数
     */
    public function logUpdateDiff(
        string $table,
        array $oldRows,
        array $newData,
        string $operator,
        string $source
    ): int {
        $count = 0;
        foreach ($oldRows as $oldRow) {
            $personCode = $this->pickLocator($newData, $oldRow, '人员编码');
            $candCode   = $this->pickLocator($newData, $oldRow, '候选人编码');

            foreach ($newData as $field => $newVal) {
                if (in_array((string) $field, self::LOCATOR_FIELDS, true)) {
                    continue; // 定位键/技术列不作为 diff 字段
                }
                if (!array_key_exists($field, $oldRow)) {
                    continue; // 未写入字段（空值跳过/非表列/控制字段）
                }
                $oldVal = $oldRow[$field];
                if ((string) $oldVal === (string) $newVal) {
                    continue; // 值未变化（NULL 与空串视为相同）
                }

                $this->logEvent([
                    '人员编码'   => $personCode,
                    '候选人编码' => $candCode,
                    '表名'      => $table,
                    '记录GUID'  => (int) ($oldRow['GUID'] ?? 0),
                    '记录UUID'  => $oldRow['UUID'] ?? null,
                    '操作类型'  => '修改',
                    '变更字段'  => (string) $field,
                    '原值'      => $oldVal !== null ? (string) $oldVal : null,
                    '新值'      => $newVal !== null ? (string) $newVal : null,
                    '操作人员'  => $operator,
                    '操作来源'  => $source,
                ]);
                $count++;
            }
        }
        return $count;
    }

    /**
     * 批量汇总行：导入补建/流转自愈等系统批量路径（宽松模式）
     *
     * 批量路径逐行写日志无操作者语义且量级失控，一行汇总留痕即可；
     * 失败仅记 log_message 不阻断业务（字段级路径仍为严格模式）。
     *
     * @param string $table    业务表名
     * @param string $operator 操作人工号
     * @param string $source   操作来源（导入/流转）
     * @param int    $count    本次批量处理行数
     * @param string $event    事件名（默认 批量补建）
     */
    public function logBatchSummary(
        string $table,
        string $operator,
        string $source,
        int $count,
        string $event = '批量补建'
    ): void {
        try {
            $this->logEvent([
                '表名'     => $table,
                '记录GUID' => 0,
                '操作类型' => '新增',
                '变更字段' => $event,
                '原值'     => null,
                '新值'     => sprintf('%d 行', $count),
                '操作人员' => $operator,
                '操作来源' => $source,
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[AuditLogService] 批量汇总日志写入失败: ' . $e->getMessage());
        }
    }

    /**
     * 定位键取值：新值优先（挂档回填场景），空则回退旧行值
     */
    private function pickLocator(array $newData, array $oldRow, string $key): string
    {
        $new = trim((string) ($newData[$key] ?? ''));
        if ($new !== '') {
            return $new;
        }
        return trim((string) ($oldRow[$key] ?? ''));
    }

    /**
     * 值表达式：NULL 写 NULL，其余截断 500 后引号包裹
     */
    private function valueExpr(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        return $this->model->quote(mb_substr((string) $value, 0, self::VALUE_WIDTH));
    }
}
