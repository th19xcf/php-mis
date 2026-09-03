<?php

namespace App\Services\Employee;

use App\Models\Mcommon;
use App\Services\Audit\AuditLogService;

/**
 * ee_onjob → ee_employment 双写镜像服务（阶段③②双写过渡期）
 *
 * 补齐 TrainApi 转入 / processResignation 离职 两条已双写主链路之外的缺口：
 *   - 在职记录编辑（页面 EmployeeService / 工作台 RecordEdit/BatchEdit）
 *     → ee_employment 活跃行就地 UPDATE（权威表当前值就地更新模式，不做 SCD2，
 *       对齐设计约定"避免 SCD2 宽表存储当前值"）
 *   - 在职记录删除（EmployeeApi::delete / 工作台删除）
 *     → ee_employment 活跃行同步软删
 *
 * 连接约定：Mcommon::getDb() 返回请求级共享连接（db_connect('btdc')），
 * 调用方已在事务内时，镜像语句自动加入同一事务（失败随事务回滚）；
 * 调用方无事务时镜像语句逐条自动提交（自身原子），语句间隙的极端不一致
 * 由 employment:reconcile 每日对账兜底。
 *
 * 定位键：候选人编码（自 ee_onjob 行快照取值；双写期两表同键，
 * uk_候选人编码_活跃 保证至多一条活跃行）。
 * 镜像缺失（无对应活跃权威行，迁移遗漏）不在此补建，交 reconcile 报告。
 */
class EmploymentMirrorService
{
    private Mcommon $model;

    /** 可镜像的 VARCHAR 业务列（排除定位键/技术列/审计列/入职次数派生列） */
    private const MIRROR_VARCHAR_COLUMNS = [
        '属地', '招聘渠道', '员工类别', '实习结束日期',
        '培训信息', '培训开始日期', '培训完成日期', '一阶段日期', '二阶段日期',
        '部门编码', '部门名称', '班组', '小组',
        '岗位名称', '岗位类型', '结算类型',
        '工号1', '工号2', '派遣公司', '备注',
        '员工阶段', '员工状态', '离职原因',
    ];

    /** DATE 列（ee_employment 侧严格模式：ee_onjob VARCHAR 空串须转 NULL） */
    private const MIRROR_DATE_COLUMNS = ['记录开始日期', '记录结束日期', '离职日期'];

    public function __construct()
    {
        $this->model = new Mcommon();
    }

    /**
     * 镜像编辑：ee_onjob 编辑（SCD2 或直接 UPDATE）后，
     * ee_employment 活跃权威行就地同步表单值
     *
     * @param array  $onjobOldRows ee_onjob 旧行快照（取 候选人编码 定位）
     * @param array  $newValues    表单新值（仅 可镜像列 ∩ 表单值 参与）
     * @param string $operator     操作人工号
     * @param string $source       操作来源（页面/工作台）
     * @return int 实际更新的权威行数
     */
    public function mirrorUpdate(array $onjobOldRows, array $newValues, string $operator, string $source): int
    {
        // 表单值 ∩ 可镜像列（guid/生效日期/操作字段等控制键自动排除）
        $applied = [];
        foreach ($newValues as $key => $value) {
            if (in_array($key, self::MIRROR_VARCHAR_COLUMNS, true)
                || in_array($key, self::MIRROR_DATE_COLUMNS, true)
            ) {
                $applied[$key] = (string) $value;
            }
        }
        if ($applied === []) {
            return 0;
        }

        $num = 0;
        $audit = new AuditLogService();
        $seen = [];

        foreach ($onjobOldRows as $oldRow) {
            $cand = trim((string) ($oldRow['候选人编码'] ?? ''));
            if ($cand === '' || isset($seen[$cand])) {
                continue;
            }
            $seen[$cand] = true;

            // 活跃权威行快照（调用方事务内时 FOR UPDATE 行锁防并发；
            // 绕过 Mcommon::select 请求级缓存，直连查询）
            $quotedCand = $this->model->quote($cand);
            $result = $this->model->getDb()->query(sprintf(
                'select * from ee_employment
                 where 候选人编码=%s and 有效标识="1" and 删除标识="0"
                 for update',
                $quotedCand
            ));
            $empOld = $result ? $result->getRowArray() : null;
            if (empty($empOld)) {
                continue; // 镜像缺失（迁移遗漏），交 reconcile 报告，不在此补建
            }

            $sets = [];
            foreach ($applied as $col => $val) {
                if (in_array($col, self::MIRROR_DATE_COLUMNS, true)) {
                    // DATE 列：空串转 NULL（严格模式拒绝零日期）
                    $sets[] = sprintf('`%s`=nullif(%s,"")', $col, $this->model->quote($val));
                } else {
                    $sets[] = sprintf('`%s`=%s', $col, $this->model->quote($val));
                }
            }
            $sets[] = sprintf('`操作记录`=%s', $this->model->quote('更新,镜像'));
            $sets[] = sprintf('`操作人员`=%s', $this->model->quote($operator));
            $sets[] = sprintf('`操作时间`=%s', $this->model->quote(date('Y-m-d H:i:s')));

            $sql = sprintf(
                'update ee_employment set %s
                 where 候选人编码=%s and 有效标识="1" and 删除标识="0"',
                implode(', ', $sets),
                $quotedCand
            );
            $affected = $this->model->exec($sql);
            if ($affected > 0) {
                $num += $affected;
                // hr_audit_log 字段级 diff（严格模式：同事务，失败随事务回滚）
                $audit->logUpdateDiff('ee_employment', [$empOld], $applied, $operator, $source);
            }
        }

        return $num;
    }

    /**
     * 镜像删除：ee_onjob 删除（软删/硬删）后，
     * ee_employment 活跃权威行同步软删
     *
     * 不写 记录结束日期——记录删除多为纠错而非雇佣结束
     * （DDL 约定：版本失效≠雇佣结束，时效由 结束操作时间+有效标识 承载）。
     *
     * @param array  $onjobOldRows ee_onjob 旧行快照（取 候选人编码 定位）
     * @param string $operator     操作人工号
     * @param string $source       操作来源（页面/工作台）
     * @return int 实际软删的权威行数
     */
    public function mirrorDelete(array $onjobOldRows, string $operator, string $source): int
    {
        $cands = [];
        foreach ($onjobOldRows as $oldRow) {
            $cand = trim((string) ($oldRow['候选人编码'] ?? ''));
            if ($cand !== '') {
                $cands[$cand] = true;
            }
        }
        if ($cands === []) {
            return 0;
        }

        $quotedList = implode(',', array_map(
            fn($c) => $this->model->quote($c),
            array_keys($cands)
        ));

        // 活跃权威行快照（审计定位键 + FOR UPDATE 防并发；绕过请求级缓存）
        $result = $this->model->getDb()->query(sprintf(
            'select * from ee_employment
             where 候选人编码 in (%s) and 有效标识="1" and 删除标识="0"
             for update',
            $quotedList
        ));
        $empOldRows = $result ? $result->getResultArray() : [];
        if ($empOldRows === []) {
            return 0;
        }

        $now = date('Y-m-d H:i:s');
        $sql = sprintf(
            'update ee_employment
             set 操作记录=%s, 删除标识="1", 有效标识="0",
                 结束操作时间=%s, 操作人员=%s, 操作时间=%s
             where 候选人编码 in (%s) and 有效标识="1" and 删除标识="0"',
            $this->model->quote('删除,镜像'),
            $this->model->quote($now),
            $this->model->quote($operator),
            $this->model->quote($now),
            $quotedList
        );
        $num = $this->model->exec($sql);

        // hr_audit_log 删除事件（严格模式：同事务，失败随事务回滚）
        if ($num > 0) {
            $audit = new AuditLogService();
            foreach ($empOldRows as $empOld) {
                $audit->logEvent([
                    '人员编码'   => (string) ($empOld['人员编码'] ?? ''),
                    '候选人编码' => (string) ($empOld['候选人编码'] ?? ''),
                    '表名'      => 'ee_employment',
                    '记录GUID'  => (int) ($empOld['GUID'] ?? 0),
                    '记录UUID'  => $empOld['UUID'] ?? null,
                    '操作类型'  => '删除',
                    '变更字段'  => '全部',
                    '原值'      => '删除前记录(镜像)',
                    '新值'      => null,
                    '操作人员'  => $operator,
                    '操作来源'  => $source,
                ]);
            }
        }

        return $num;
    }
}
