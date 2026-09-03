<?php

namespace App\Commands;

use App\Models\Mcommon;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * 雇佣记录表每日对账命令（阶段③②双写过渡期）
 *
 * ee_onjob（镜像表）↔ ee_employment（权威表）一致性核验，只报告不改数：
 *   1. 镜像行缺失：ee_onjob 有效行无对应 ee_employment 行（双写漏写/迁移遗漏）
 *      —— 关联键 人员编码+候选人编码（与 EmploymentMigrateService 幂等键一致）
 *   2. 权威孤儿：ee_employment 活跃行无对应 ee_onjob 有效行
 *      （双写期 >0 异常；阶段④写切换后新行不再镜像，>0 属预期）
 *   3. 字段级 diff：共享业务字段逐一比对（VARCHAR 类 NULL/空串视为相等；
 *      生命周期日期 ee_onjob 侧经多格式归一化后与 DATE 列比对）
 *   4. 序号完整性：ee_employment.入职次数 vs 重算 ROW_NUMBER（权威表自身健康度）
 *   5. 生命周期规格：在职行 记录结束/离职日期 应为 NULL；离职行缺离职日期仅提示
 *   6. 主档孤儿：ee_employment.人员编码 无对应 hr_person（数据链断裂）
 *
 * 差异修复方式：
 *   - 镜像行缺失（迁移遗漏的历史行）：重跑 php spark employment:migrate --execute（幂等）
 *   - 字段差异/序号漂移：人工核实后修正，或以 ee_employment 权威值回写 ee_onjob
 *   - 主档孤儿：人工核实人员编码（hr_person 是否被误合并/失效）
 *
 * 用法：
 *   php spark employment:reconcile
 *   php spark employment:reconcile --sample 50
 *
 * 退出码：发现差异（检查 1~4、6-在职、及孤儿任一计数 > 0）返回 EXIT_ERROR 供计划任务告警；
 *         离职行缺离职日期（脏数据）仅提示不计入。
 *
 * 每日调度示例：
 *   Linux   crontab:  20 2 * * * cd /path/to/backend && php spark employment:reconcile >> writable/logs/reconcile_cron.log 2>&1
 *   Windows 计划任务: schtasks /Create /TN "MIS雇佣记录对账" /SC DAILY /ST 02:20
 *                     /TR "cmd /c cd /d e:\code\php\mis\backend && php spark employment:reconcile >> writable\logs\reconcile_cron.log 2>&1"
 *
 * 报告文件：writable/logs/employment_reconcile_YYYYMMDD_Hisss.json
 */
class EmploymentReconcile extends BaseCommand
{
    protected $group       = 'Person';
    protected $name        = 'employment:reconcile';
    protected $description = '每日对账：ee_onjob ↔ ee_employment 双写一致性核验（只报告不改数）';
    protected $usage       = 'php spark employment:reconcile [--sample 20]';
    protected $arguments   = [];
    protected $options     = [
        '--sample' => '每类差异输出的样本条数（默认 20）',
    ];

    /** 共享 VARCHAR 业务字段（两表同名，NULL 与空串视为相等） */
    private const COMPARE_VARCHAR_FIELDS = [
        '员工状态', '部门编码', '部门名称', '班组', '小组',
        '岗位名称', '岗位类型', '结算类型', '工号1', '工号2',
        '派遣公司', '备注', '员工阶段', '离职原因',
        '属地', '招聘渠道', '员工类别',
    ];

    /** 共享生命周期日期字段（ee_onjob VARCHAR → 归一化 vs ee_employment DATE） */
    private const COMPARE_DATE_FIELDS = [
        '记录开始日期', '记录结束日期', '离职日期',
    ];

    private Mcommon $model;
    private int $sampleLimit;

    public function run(array $params)
    {
        $this->model       = new Mcommon();
        $this->sampleLimit = max(1, (int) (CLI::getOption('sample') ?? 20));
        $startedAt         = microtime(true);

        CLI::write('雇佣记录表每日对账（ee_onjob ↔ ee_employment，双写过渡期）', 'yellow');
        CLI::newLine();

        $report = [
            'generatedAt' => date('Y-m-d H:i:s'),
            'checks'      => [],
            'diffCount'   => 0,
        ];

        try {
            $report['checks']['mirrorMissing'] = $this->checkMirrorMissing();
            $report['checks']['empOrphan']     = $this->checkEmpOrphan();
            $report['checks']['fieldDiff']     = $this->checkFieldDiff();
            $report['checks']['seqIntegrity']  = $this->checkSeqIntegrity();
            $report['checks']['lifecycle']     = $this->checkLifecycle();
            $report['checks']['personOrphan']  = $this->checkPersonOrphan();
        } catch (\Throwable $e) {
            CLI::error('对账执行失败: ' . $e->getMessage());
            CLI::error($e->getFile() . ':' . $e->getLine());
            return EXIT_ERROR;
        }

        // 差异合计（离职行缺离职日期属脏数据，仅提示不计入）
        $report['diffCount'] =
            $report['checks']['mirrorMissing']['count']
            + $report['checks']['empOrphan']['count']
            + array_sum(array_column($report['checks']['fieldDiff'], 'count'))
            + $report['checks']['seqIntegrity']['count']
            + $report['checks']['lifecycle']['activeViolated']
            + $report['checks']['personOrphan']['count'];
        $report['durationMs'] = (int) round((microtime(true) - $startedAt) * 1000);

        // ---------- CLI 摘要 ----------
        CLI::write('========== 对账摘要 ==========', 'yellow');
        $this->writeCheckLine('① 镜像行缺失（onjob 有 / employment 无）', $report['checks']['mirrorMissing']['count'],
            '（重跑 employment:migrate --execute 可补）');
        $this->writeCheckLine('② 权威孤儿（employment 有 / onjob 无）', $report['checks']['empOrphan']['count'],
            '（双写期>0 异常；阶段④写切换后属预期）');
        $this->writeCheckLine('③ 字段差异', $report['checks']['fieldDiff']);
        $this->writeCheckLine('④ 派生序号漂移', $report['checks']['seqIntegrity']['count']);

        $lc = $report['checks']['lifecycle'];
        CLI::write(
            sprintf('⑤ 生命周期规格: 在职行异常 %d（应无结束/离职日期），离职行缺离职日期 %d（仅提示）',
                $lc['activeViolated'], $lc['resignedMissingDate']),
            $lc['activeViolated'] > 0 ? 'light_red' : 'light_green'
        );

        $this->writeCheckLine('⑥ 主档孤儿', $report['checks']['personOrphan']['count']);
        CLI::newLine();
        CLI::write('差异合计: ' . $report['diffCount'], $report['diffCount'] > 0 ? 'light_red' : 'light_green');

        // ---------- 报告文件 ----------
        $logDir = WRITEPATH . 'logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        $file = $logDir . '/employment_reconcile_' . date('Ymd_His') . '.json';
        file_put_contents($file, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        CLI::write("报告明细已写入: {$file}", 'light_green');

        return $report['diffCount'] > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }

    // ------------------------------------------------------------
    // 检查项
    // ------------------------------------------------------------

    /**
     * ① 镜像行缺失：ee_onjob 有效行无对应 ee_employment 行
     *    关联键 人员编码+候选人编码（迁移幂等键口径）
     */
    private function checkMirrorMissing(): array
    {
        $sql = 'SELECT o.GUID, o.人员编码 AS person, o.候选人编码 AS cand, o.姓名 AS name
                FROM ee_onjob o
                WHERE o.有效标识 = "1" AND o.删除标识 = "0"
                  AND IFNULL(o.人员编码, "") <> ""
                  AND NOT EXISTS (SELECT 1 FROM ee_employment e
                                  WHERE e.人员编码 = o.人员编码
                                    AND e.候选人编码 = IFNULL(o.候选人编码, ""))';

        return [
            'count'   => $this->countRows($sql),
            'samples' => $this->sampleRows($sql . ' LIMIT ' . $this->sampleLimit),
        ];
    }

    /**
     * ② 权威孤儿：ee_employment 活跃行无对应 ee_onjob 有效行
     */
    private function checkEmpOrphan(): array
    {
        $sql = 'SELECT e.GUID, e.人员编码 AS person, e.候选人编码 AS cand
                FROM ee_employment e
                WHERE e.有效标识 = "1" AND e.删除标识 = "0"
                  AND NOT EXISTS (SELECT 1 FROM ee_onjob o
                                  WHERE o.人员编码 = e.人员编码
                                    AND o.候选人编码 = e.候选人编码
                                    AND o.有效标识 = "1" AND o.删除标识 = "0")';

        return [
            'count'   => $this->countRows($sql),
            'samples' => $this->sampleRows($sql . ' LIMIT ' . $this->sampleLimit),
        ];
    }

    /**
     * ③ 字段级 diff：
     *    - VARCHAR 字段：NULL 与空串视为相等（两侧 NULLIF 归一后 <=> 比对）
     *    - 日期字段：ee_onjob 侧多格式归一化后与 ee_employment DATE 比对
     */
    private function checkFieldDiff(): array
    {
        $result     = [];
        $joinClause = 'FROM ee_onjob o
                JOIN ee_employment e
                  ON e.人员编码 = o.人员编码
                 AND e.候选人编码 = IFNULL(o.候选人编码, "")
                WHERE o.有效标识 = "1" AND o.删除标识 = "0"';

        foreach (self::COMPARE_VARCHAR_FIELDS as $field) {
            $sql = "SELECT o.人员编码 AS person, o.候选人编码 AS cand,
                           o.`{$field}` AS onjobValue, e.`{$field}` AS empValue
                    {$joinClause}
                      AND NOT (NULLIF(o.`{$field}`, \"\") <=> NULLIF(e.`{$field}`, \"\"))";

            $count = $this->countRows($sql);
            if ($count > 0) {
                $result[$field] = [
                    'count'   => $count,
                    'samples' => $this->sampleRows($sql . ' LIMIT ' . $this->sampleLimit),
                ];
            }
        }

        foreach (self::COMPARE_DATE_FIELDS as $field) {
            $norm = $this->dateNormExpr($field);
            $sql  = "SELECT o.人员编码 AS person, o.候选人编码 AS cand,
                           o.`{$field}` AS onjobValue, e.`{$field}` AS empValue
                    {$joinClause}
                      AND NOT ({$norm} <=> e.`{$field}`)";

            $count = $this->countRows($sql);
            if ($count > 0) {
                $result[$field] = [
                    'count'   => $count,
                    'samples' => $this->sampleRows($sql . ' LIMIT ' . $this->sampleLimit),
                ];
            }
        }

        // 入职次数（镜像自派生值，ee_employment 为权威）
        $sql = "SELECT o.人员编码 AS person, o.候选人编码 AS cand,
                       o.入职次数 AS onjobValue, e.入职次数 AS empValue
                {$joinClause}
                  AND o.入职次数 <> e.入职次数";
        $count = $this->countRows($sql);
        if ($count > 0) {
            $result['入职次数'] = [
                'count'   => $count,
                'samples' => $this->sampleRows($sql . ' LIMIT ' . $this->sampleLimit),
            ];
        }

        return $result;
    }

    /**
     * ④ 派生序号完整性：入职次数 vs 重算 ROW_NUMBER
     *    口径与 EmploymentMigrateService（人员编码 → 记录开始日期 → GUID 序）一致
     */
    private function checkSeqIntegrity(): array
    {
        $sql = 'SELECT x.GUID, x.人员编码 AS person, x.候选人编码 AS cand,
                       x.入职次数 AS storedSeq, x.expectedSeq
                FROM (
                    SELECT e.GUID, e.人员编码, e.候选人编码, e.入职次数,
                           ROW_NUMBER() OVER (PARTITION BY e.人员编码
                                              ORDER BY e.记录开始日期, e.GUID) AS expectedSeq
                    FROM ee_employment e
                    WHERE e.有效标识 = "1" AND e.删除标识 = "0"
                ) x
                WHERE x.入职次数 <> x.expectedSeq';

        return [
            'count'   => $this->countRows($sql),
            'samples' => $this->sampleRows($sql . ' LIMIT ' . $this->sampleLimit),
        ];
    }

    /**
     * ⑤ 生命周期规格：
     *    - 在职行：记录结束日期/离职日期 应为 NULL（NULL=在职 目标规格）
     *    - 离职行：离职日期 应非空（缺值为脏数据，仅提示不计差异——
     *      存量迁移保留 NULL 1 行，见迁移报告 dateParseFailures/unable 口径）
     */
    private function checkLifecycle(): array
    {
        $activeSql = 'SELECT e.GUID, e.人员编码 AS person, e.候选人编码 AS cand
                FROM ee_employment e
                WHERE e.有效标识 = "1" AND e.删除标识 = "0"
                  AND e.员工状态 = "在职"
                  AND (e.记录结束日期 IS NOT NULL OR e.离职日期 IS NOT NULL)';

        $resignedSql = 'SELECT e.GUID, e.人员编码 AS person, e.候选人编码 AS cand
                FROM ee_employment e
                WHERE e.有效标识 = "1" AND e.删除标识 = "0"
                  AND e.员工状态 = "离职"
                  AND e.离职日期 IS NULL';

        return [
            'activeViolated'    => $this->countRows($activeSql),
            'activeSamples'     => $this->sampleRows($activeSql . ' LIMIT ' . $this->sampleLimit),
            'resignedMissingDate' => $this->countRows($resignedSql),
            'resignedSamples'   => $this->sampleRows($resignedSql . ' LIMIT ' . $this->sampleLimit),
        ];
    }

    /**
     * ⑥ 主档孤儿：ee_employment.人员编码 无对应 hr_person
     */
    private function checkPersonOrphan(): array
    {
        $sql = 'SELECT e.GUID, e.人员编码 AS person
                FROM ee_employment e
                WHERE NOT EXISTS (SELECT 1 FROM hr_person p
                                  WHERE p.人员编码 = e.人员编码)';

        return [
            'count'   => $this->countRows($sql),
            'samples' => $this->sampleRows($sql . ' LIMIT ' . $this->sampleLimit),
        ];
    }

    // ------------------------------------------------------------
    // 辅助方法
    // ------------------------------------------------------------

    /** ee_onjob VARCHAR 多格式日期 → DATE 归一化（口径同迁移服务） */
    private function dateNormExpr(string $field): string
    {
        $v = "NULLIF(o.`{$field}`, '')";

        return "COALESCE(STR_TO_DATE({$v}, '%Y-%m-%d'), STR_TO_DATE({$v}, '%Y/%m/%d'), "
            . "STR_TO_DATE({$v}, '%m/%d/%Y'), STR_TO_DATE({$v}, '%Y%m%d'), "
            . "STR_TO_DATE(REPLACE(REPLACE({$v}, '年', '-'), '日', ''), '%Y-%m-%d'))";
    }

    private function countRows(string $sql): int
    {
        $row = $this->model->select('SELECT COUNT(*) AS c FROM (' . $sql . ') AS t')->getRowArray();

        return (int) ($row['c'] ?? 0);
    }

    private function sampleRows(string $sql): array
    {
        return $this->model->select($sql)->getResultArray();
    }

    /** CLI 摘要行输出 */
    private function writeCheckLine(string $label, $result, string $note = ''): void
    {
        if (is_int($result)) {
            $count  = $result;
            $detail = '';
        } else {
            $count = array_sum(array_column($result, 'count'));
            $parts = [];
            foreach ($result as $key => $item) {
                $parts[] = "{$key}={$item['count']}";
            }
            $detail = $parts === [] ? '' : '（' . implode(', ', $parts) . '）';
        }

        $color = $count > 0 ? 'light_red' : 'light_green';
        $line  = sprintf('%s: %d%s', $label, $count, $detail);
        if ($note !== '' && $count > 0) {
            $line .= ' ' . $note;
        }
        CLI::write($line, $color);
    }
}
