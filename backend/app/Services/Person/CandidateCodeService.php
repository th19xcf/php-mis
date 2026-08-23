<?php

namespace App\Services\Person;

use App\Models\Mcommon;

/**
 * 候选人编码服务
 *
 * 候选人编码 = 流程实例标识（C+YYYYMMDD+3位序号），标识"某次邀约链路"：
 * - 发码时机：链路起点（邀约新增/导入、直接面试、直接培训等）发新码
 * - 流转继承：邀约→面试→培训→在职 沿链路继承同一码
 * - 邀约次数：人工录入（表单必填，前端经 /invitation/stats 提示建议值），不自动计算
 *
 * 发号核心：sp_生成候选人编码（def_seq 按业务日期分桶 + LAST_INSERT_ID 防并发）。
 *
 * 与 sp_邀约_导入前处理 共用同一发号核心：Excel 导入走前处理 SP 批量发号，
 * 页面新增走本服务 generateOne()，存量回填补全走 backfill()。
 */
class CandidateCodeService
{
    private Mcommon $model;

    public function __construct()
    {
        $this->model = new Mcommon();
    }

    /**
     * 生成单个候选人编码（页面新增）
     *
     * 直接走 getDb()->query() 而非 Mcommon::select()：
     * select() 有请求级结果缓存，同请求第二次发号会拿到缓存结果而不执行
     * 存储过程，导致重号。CALL 必须真正执行（与 PersonService::generatePersonCode 同理）。
     *
     * @param string $bizDate 业务日期（邀约日期/一次面试日期等），空则用今天
     */
    public function generateOne(string $bizDate = ''): string
    {
        $date = $bizDate ?: date('Y-m-d');
        // 严格校验日期格式，防 SQL 注入（存储过程 p_date 参数）
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }

        $db = $this->model->getDb();
        // 初始化会话变量（防残留）
        $db->query("SET @seq = 0, @prefix = ''");
        // 发号（LAST_INSERT_ID 防并发，按业务日期分桶）
        $db->query(sprintf("CALL sp_生成候选人编码(1, '%s', @seq, @prefix)", $date));
        // 读取 OUT 参数（同一连接，@变量可见）
        $row = $db->query('SELECT @prefix AS p, @seq AS s')->getRowArray() ?: [];
        $prefix = (string) ($row['p'] ?? '');
        $seq = (int) ($row['s'] ?? 0);
        return $prefix . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }

    /**
     * 候选人编码存量回填（幂等可重跑，已回填行自动跳过）
     *
     * 规则（链路继承，按阶段顺序执行）：
     * - ee_store：邀约是链路起点，每行发新码；同时全量重算邀约次数
     *   （同人员编码按 [邀约日期, GUID] 排序 1..n，修正人工填写断裂）
     * - ee_interview：同人员编码下取 邀约日期 <= 一次面试日期 的最近邀约行继承；
     *   无匹配（直接面试起点 / 日期矛盾）发新码，日期矛盾行记报告
     * - ee_train：同人员编码下取 一次面试日期 <= 培训开始日期 的最近面试行继承；
     *   无匹配发新码（直接培训起点 / 日期矛盾记报告）
     * - ee_onjob：同人员编码下取 培训开始日期 <= 记录开始日期 的最近培训行继承；
     *   无匹配发新码（直接入职起点 / 日期矛盾记报告）
     *
     * 无人员编码的行无法参与继承（发码兜底，日期分桶用业务日期），记报告。
     *
     * @param bool          $dryRun   true 只出报告不写库（不消耗序列号）
     * @param callable|null $progress 进度回调 fn(string $stage): void
     * @return array 回填报告（统计 + 日期矛盾明细样本）
     */
    public function backfill(bool $dryRun = true, ?callable $progress = null): array
    {
        return [
            'dryRun'     => $dryRun,
            'store'      => $this->backfillStore($dryRun, $progress),
            'interview'  => $this->backfillInherit('ee_interview', 'ee_store', '邀约日期', '一次面试日期', $dryRun, $progress),
            'train'      => $this->backfillInherit('ee_train', 'ee_interview', '一次面试日期', '培训开始日期', $dryRun, $progress),
            'onjob'      => $this->backfillInherit('ee_onjob', 'ee_train', '培训开始日期', '记录开始日期', $dryRun, $progress),
        ];
    }

    /**
     * ee_store 回填：发码（起点规则）+ 邀约次数全量重算
     */
    private function backfillStore(bool $dryRun, ?callable $progress): array
    {
        $stats = $this->tableStats('ee_store');
        $result = [
            'total'          => $stats['total'],
            'needCode'       => $stats['emptyCode'],
            'noPerson'       => $stats['noPerson'],
            'undated'        => $stats['undated'],
            'codeAssigned'   => 0,
            'countRecalced'  => 0,
        ];

        if ($progress) {
            $progress('ee_store：重算邀约次数');
        }

        // 1. 邀约次数全量重算（同人员编码按 [邀约日期, GUID] 排序 1..n；
        //    有日期行在前、无日期行在后；无人员编码行不动，记入 noPerson）
        if (!$dryRun) {
            $sql = '
                UPDATE ee_store s
                JOIN (
                    SELECT GUID,
                           ROW_NUMBER() OVER (
                               PARTITION BY 人员编码
                               ORDER BY (邀约日期 IS NULL OR 邀约日期 = ""), 邀约日期, GUID
                           ) AS rn
                    FROM ee_store
                    WHERE 有效标识 = "1" AND 删除标识 = "0"
                      AND 人员编码 <> "" AND 人员编码 IS NOT NULL
                ) r ON s.GUID = r.GUID
                SET s.邀约次数 = r.rn';
            $result['countRecalced'] = $this->model->exec($sql);
        } else {
            // dry-run：按 UPDATE 同口径统计将重算的行数
            $row = $this->model->select(
                'SELECT COUNT(*) AS c FROM ee_store
                 WHERE 有效标识 = "1" AND 删除标识 = "0"
                   AND 人员编码 <> "" AND 人员编码 IS NOT NULL'
            )->getRowArray();
            $result['countRecalced'] = (int) ($row['c'] ?? 0);
        }

        if ($progress) {
            $progress('ee_store：批量发码');
        }

        // 2. 批量发码（按业务日期分桶，逐桶 CALL 发段 + 变量递增赋值）
        $result['codeAssigned'] = $this->assignCodesByDateBucket(
            'ee_store',
            '邀约日期',
            $dryRun,
            $progress
        );

        return $result;
    }

    /**
     * 面试/培训/在职 回填：继承上游码 + 发码兜底
     *
     * @param string $targetTable 目标表（待回填）
     * @param string $sourceTable 上游表（继承来源）
     * @param string $sourceDate  上游表业务日期字段
     * @param string $targetDate  目标表业务日期字段
     */
    private function backfillInherit(
        string $targetTable,
        string $sourceTable,
        string $sourceDate,
        string $targetDate,
        bool $dryRun,
        ?callable $progress
    ): array {
        $stats = $this->tableStats($targetTable);
        $result = [
            'total'           => $stats['total'],
            'needCode'        => $stats['emptyCode'],
            'noPerson'        => $stats['noPerson'],
            'undated'         => $stats['undated'],
            'inherited'       => 0,
            'dateConflict'    => 0,
            'newCode'         => 0,
            'conflictSamples' => [],
        ];

        if ($progress) {
            $progress("{$targetTable}：继承上游 {$sourceTable} 候选人编码");
        }

        // 1. 继承：同人员编码下，上游日期 <= 目标日期 的最近一行（日期次近，GUID 破并列）
        $inheritSql = sprintf(
            'UPDATE `%s` t
             JOIN (
                 SELECT t2.GUID,
                        (SELECT s.候选人编码 FROM `%s` s
                         WHERE s.人员编码 = t2.人员编码
                           AND s.有效标识 = "1" AND s.删除标识 = "0"
                           AND s.候选人编码 <> "" AND s.候选人编码 IS NOT NULL
                           AND s.`%s` <> "" AND s.`%s` IS NOT NULL
                           AND s.`%s` <= t2.`%s`
                         ORDER BY s.`%s` DESC, s.GUID DESC
                         LIMIT 1) AS inherited_code
                 FROM `%s` t2
                 WHERE t2.有效标识 = "1" AND t2.删除标识 = "0"
                   AND (t2.候选人编码 = "" OR t2.候选人编码 IS NULL)
                   AND t2.人员编码 <> "" AND t2.人员编码 IS NOT NULL
                   AND t2.`%s` <> "" AND t2.`%s` IS NOT NULL
             ) x ON t.GUID = x.GUID
             SET t.候选人编码 = x.inherited_code
             WHERE x.inherited_code IS NOT NULL',
            $targetTable,
            $sourceTable,
            $sourceDate, $sourceDate, $sourceDate, $targetDate,
            $sourceDate,
            $targetTable,
            $targetDate, $targetDate
        );

        // 日期矛盾：有同人员编码的上游行，但上游日期全部晚于目标日期（链路时序倒挂）
        // 不强行继承（会错链），走发码兜底，明细记报告供人工复核
        $conflictCountSql = sprintf(
            'SELECT COUNT(*) AS c FROM `%s` t
             WHERE t.有效标识 = "1" AND t.删除标识 = "0"
               AND (t.候选人编码 = "" OR t.候选人编码 IS NULL)
               AND t.人员编码 <> "" AND t.人员编码 IS NOT NULL
               AND EXISTS (SELECT 1 FROM `%s` s
                           WHERE s.人员编码 = t.人员编码
                             AND s.有效标识 = "1" AND s.删除标识 = "0")
               AND NOT EXISTS (SELECT 1 FROM `%s` s2
                               WHERE s2.人员编码 = t.人员编码
                                 AND s2.有效标识 = "1" AND s2.删除标识 = "0"
                                 AND s2.`%s` <> "" AND s2.`%s` IS NOT NULL
                                 AND s2.`%s` <= t.`%s`)',
            $targetTable,
            $sourceTable,
            $sourceTable,
            $sourceDate, $sourceDate, $sourceDate, $targetDate
        );
        $row = $this->model->select($conflictCountSql)->getRowArray();
        $result['dateConflict'] = (int) ($row['c'] ?? 0);

        $conflictSampleSql = sprintf(
            'SELECT t.GUID, t.姓名, t.人员编码, t.`%s` AS target_date
             FROM `%s` t
             WHERE t.有效标识 = "1" AND t.删除标识 = "0"
               AND (t.候选人编码 = "" OR t.候选人编码 IS NULL)
               AND t.人员编码 <> "" AND t.人员编码 IS NOT NULL
               AND EXISTS (SELECT 1 FROM `%s` s
                           WHERE s.人员编码 = t.人员编码
                             AND s.有效标识 = "1" AND s.删除标识 = "0")
               AND NOT EXISTS (SELECT 1 FROM `%s` s2
                               WHERE s2.人员编码 = t.人员编码
                                 AND s2.有效标识 = "1" AND s2.删除标识 = "0"
                                 AND s2.`%s` <> "" AND s2.`%s` IS NOT NULL
                                 AND s2.`%s` <= t.`%s`)
             LIMIT 20',
            $targetDate,
            $targetTable,
            $sourceTable,
            $sourceTable,
            $sourceDate, $sourceDate, $sourceDate, $targetDate
        );
        $result['conflictSamples'] = $this->model->select($conflictSampleSql)->getResultArray();

        if ($dryRun) {
            // dry-run 统计口径：正式执行时上游表已先行发码（backfill 按阶段顺序），
            // 故不要求上游当前已有码（否则下游继承数恒为 0）
            $countSql = sprintf(
                'SELECT COUNT(*) AS c FROM `%s` t
                 WHERE t.有效标识 = "1" AND t.删除标识 = "0"
                   AND (t.候选人编码 = "" OR t.候选人编码 IS NULL)
                   AND t.人员编码 <> "" AND t.人员编码 IS NOT NULL
                   AND t.`%s` <> "" AND t.`%s` IS NOT NULL
                   AND EXISTS (SELECT 1 FROM `%s` s
                               WHERE s.人员编码 = t.人员编码
                                 AND s.有效标识 = "1" AND s.删除标识 = "0"
                                 AND s.`%s` <> "" AND s.`%s` IS NOT NULL
                                 AND s.`%s` <= t.`%s`)',
                $targetTable,
                $targetDate, $targetDate,
                $sourceTable,
                $sourceDate, $sourceDate, $sourceDate, $targetDate
            );
            $row = $this->model->select($countSql)->getRowArray();
            $result['inherited'] = (int) ($row['c'] ?? 0);
            // 发码兜底 = 待回填 - 继承（直接起点 / 日期矛盾 / 无人员编码 / 无日期）
            $result['newCode'] = max(0, $result['needCode'] - $result['inherited']);
            return $result;
        }

        $result['inherited'] = $this->model->exec($inheritSql);

        if ($progress) {
            $progress("{$targetTable}：发码兜底（直接起点/日期矛盾/无人员编码/无日期）");
        }

        // 2. 发码兜底：继承后仍空码的行（直接起点、日期矛盾、无人员编码、无日期）
        $result['newCode'] = $this->assignCodesByDateBucket(
            $targetTable,
            $targetDate,
            false,
            $progress
        );

        return $result;
    }

    /**
     * 按业务日期分桶批量发码（与 sp_邀约_导入前处理 同一核心）
     *
     * 逐桶：CALL sp_生成候选人编码(桶行数, 桶日期) 取起始号段 →
     * 会话变量递增 UPDATE 赋值（桶内不重号，桶间由 def_seq 防并发）。
     * dry-run 时不调 SP（不消耗序列号），仅返回待发码行数。
     *
     * @return int 发码行数（dry-run 为待发码行数）
     */
    private function assignCodesByDateBucket(string $table, string $dateField, bool $dryRun, ?callable $progress): int
    {
        $bucketSql = sprintf(
            'SELECT IFNULL(NULLIF(`%s`, ""), CURDATE()) AS d, COUNT(*) AS c
             FROM `%s`
             WHERE 有效标识 = "1" AND 删除标识 = "0"
               AND (候选人编码 = "" OR 候选人编码 IS NULL)
             GROUP BY IFNULL(NULLIF(`%s`, ""), CURDATE())',
            $dateField,
            $table,
            $dateField
        );
        $buckets = $this->model->select($bucketSql)->getResultArray();
        $total = 0;
        foreach ($buckets as $bucket) {
            $total += (int) $bucket['c'];
        }

        if ($dryRun || $total === 0) {
            return $total;
        }

        $db = $this->model->getDb();
        foreach ($buckets as $bucket) {
            $date = (string) $bucket['d'];
            $count = (int) $bucket['c'];
            if ($count <= 0) {
                continue;
            }
            if ($progress) {
                $progress("{$table} 发码：{$date} × {$count}");
            }

            $db->transStart();
            try {
                // 发整段号（LAST_INSERT_ID 防并发）
                // sp_生成候选人编码 返回段首号：分配区间 [seq, seq+count-1]，def_seq.当前值=段尾号
                $db->query("SET @seq = 0, @prefix = ''");
                $db->query(sprintf("CALL sp_生成候选人编码(%d, '%s', @seq, @prefix)", $count, $date));
                $row = $db->query('SELECT @prefix AS p, @seq AS s')->getRowArray() ?: [];
                $prefix = (string) ($row['p'] ?? '');
                $seq = (int) ($row['s'] ?? 0);
                if ($prefix === '' || $seq < 1) {
                    throw new \RuntimeException("sp_生成候选人编码 返回异常: prefix={$prefix} seq={$seq} count={$count}");
                }

                // 桶内递增赋值（行序任意，仅保证不重号）
                // @i 从段首号-1 起步：首个赋值 = seq，末个 = seq+count-1（与实测段首号语义一致）
                $db->query(sprintf('SET @i = %d', $seq - 1));
                $updateSql = sprintf(
                    'UPDATE `%s`
                     SET 候选人编码 = CONCAT("%s", LPAD((@i := @i + 1), 3, "0"))
                     WHERE 有效标识 = "1" AND 删除标识 = "0"
                       AND (候选人编码 = "" OR 候选人编码 IS NULL)
                       AND IFNULL(NULLIF(`%s`, ""), CURDATE()) = "%s"',
                    $table,
                    $prefix,
                    $dateField,
                    $date
                );
                $db->query($updateSql);
            } catch (\Throwable $e) {
                $db->transRollback();
                throw $e;
            }
            $db->transComplete();
            if ($db->transStatus() === false) {
                throw new \RuntimeException("{$table} 日期 {$date} 桶发码事务失败");
            }
        }

        return $total;
    }

    /**
     * 表统计：有效行数、空码行数、无人员编码行数、无业务日期行数
     */
    private function tableStats(string $table): array
    {
        $dateField = $this->defaultDateField($table);
        $base = sprintf('FROM `%s` WHERE 有效标识 = "1" AND 删除标识 = "0"', $table);
        $stats = ['total' => 0, 'emptyCode' => 0, 'noPerson' => 0, 'undated' => 0];
        $stats['total'] = (int) ($this->model->select("SELECT COUNT(*) c {$base}")->getRowArray()['c'] ?? 0);
        $stats['emptyCode'] = (int) ($this->model->select(
            "SELECT COUNT(*) c {$base} AND (候选人编码 = '' OR 候选人编码 IS NULL)"
        )->getRowArray()['c'] ?? 0);
        $stats['noPerson'] = (int) ($this->model->select(
            "SELECT COUNT(*) c {$base} AND (人员编码 = '' OR 人员编码 IS NULL)"
        )->getRowArray()['c'] ?? 0);
        $stats['undated'] = (int) ($this->model->select(
            sprintf("SELECT COUNT(*) c {$base} AND (`%s` = '' OR `%s` IS NULL)", $dateField, $dateField)
        )->getRowArray()['c'] ?? 0);
        return $stats;
    }

    private function defaultDateField(string $table): string
    {
        return match ($table) {
            'ee_store'     => '邀约日期',
            'ee_interview' => '一次面试日期',
            'ee_train'     => '培训开始日期',
            'ee_onjob'     => '记录开始日期',
            default        => '操作时间',
        };
    }
}
