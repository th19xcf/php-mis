<?php

namespace App\Commands;

use App\Models\Mcommon;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * 流程实例每日对账命令
 *
 * 方案C（瘦状态头）：ee_application 只存状态机+时间线+终止信息，
 * 邀约及实例级业务字段长期保留在 ee_store（按候选人编码 1:1 关联），
 * 本对账为常态化核验（写路径双 INSERT 落地前尤需每日执行）：
 *   1. 行覆盖：ee_store 活跃行缺失实例 / 实例无对应活跃源行（双向）
 *   2. 字段级：共享字段（邀约日期冗余副本）比对（NULL 与空串视为相等）
 *   3. 阶段一致性：按下游表重算期望阶段，与存储的当前阶段比对
 *      （口径与 ee_application_migrate.sql 第 3/4 部分完全一致）
 *   4. 下游孤儿：ee_interview/ee_train/ee_onjob 中候选人编码无对应实例的有效行
 *   5. 主档链接：实例人员编码为空（应先跑 person:migrate）
 *   6. 长期在途：邀约/面试/培训阶段且邀约日期早于 N 天（--stale-days，默认 90，仅提示不计数）
 *
 * 对账只报告不改数。差异修复方式：
 *   - 行缺失 / 字段差异：人工核对源行后修正，或重跑 ee_application_migrate.sql 对应段落（幂等）
 *   - 阶段不一致：重跑 migrate 第 3/4 部分（幂等），重跑后仍不一致的行人工核实
 *
 * 用法：
 *   php spark application:reconcile
 *   php spark application:reconcile --sample 50 --stale-days 120
 *
 * 退出码：发现差异（检查 1~5 任一计数 > 0）返回 EXIT_ERROR 供计划任务告警；
 *         长期在途（检查 6）不计入差异。
 *
 * 每日调度示例：
 *   Linux   crontab:  10 2 * * * cd /path/to/backend && php spark application:reconcile >> writable/logs/reconcile_cron.log 2>&1
 *   Windows 计划任务: schtasks /Create /TN "MIS流程实例对账" /SC DAILY /ST 02:10
 *                     /TR "cmd /c cd /d e:\code\php\mis\backend && php spark application:reconcile >> writable\logs\reconcile_cron.log 2>&1"
 *
 * 报告文件：writable/logs/application_reconcile_YYYYMMDD_Hisss.json
 */
class ApplicationReconcile extends BaseCommand
{
    protected $group       = 'Person';
    protected $name        = 'application:reconcile';
    protected $description = '每日对账：ee_store ↔ ee_application 行/字段/阶段一致性核验（只报告不改数）';
    protected $usage       = 'php spark application:reconcile [--sample 20] [--stale-days 90]';
    protected $arguments   = [];
    protected $options     = [
        '--sample'     => '每类差异输出的样本条数（默认 20）',
        '--stale-days' => '长期在途判定天数（默认 90，仅提示不计数）',
    ];

    /** 与 ee_application 共享、需逐字段比对的核心列（均两表同名；方案C 仅剩邀约日期冗余副本） */
    private const COMPARE_FIELDS = [
        '邀约日期',
    ];

    /**
     * 源表（VARCHAR 多格式日期）→ DATE 归一化转换链
     * 与 ee_application_migrate.sql 第 1 部分口径完全一致：
     * 标准格式 / 斜杠 / 美式 月-日-年 / 无分隔紧凑 / 中文年月日
     */
    private function dateNormExpr(string $field): string
    {
        $v = "NULLIF(s.`{$field}`, '')";

        return "COALESCE(STR_TO_DATE({$v}, '%Y-%m-%d'), STR_TO_DATE({$v}, '%Y/%m/%d'), "
            . "STR_TO_DATE({$v}, '%m/%d/%Y'), STR_TO_DATE({$v}, '%Y%m%d'), "
            . "STR_TO_DATE(REPLACE(REPLACE({$v}, '年', '-'), '日', ''), '%Y-%m-%d'))";
    }

    private Mcommon $model;
    private int $sampleLimit;

    public function run(array $params)
    {
        $this->model       = new Mcommon();
        $this->sampleLimit = max(1, (int) (CLI::getOption('sample') ?? 20));
        $staleDays         = max(1, (int) (CLI::getOption('stale-days') ?? 90));
        $startedAt         = microtime(true);

        CLI::write('流程实例每日对账（ee_store ↔ ee_application）', 'yellow');
        CLI::newLine();

        $report = [
            'generatedAt' => date('Y-m-d H:i:s'),
            'staleDays'   => $staleDays,
            'checks'      => [],
            'diffCount'   => 0,
        ];

        try {
            $report['checks']['storeMissing']      = $this->checkStoreMissing();
            $report['checks']['appOrphan']         = $this->checkAppOrphan();
            $report['checks']['fieldDiff']         = $this->checkFieldDiff();
            $report['checks']['stageMismatch']     = $this->checkStageMismatch();
            $report['checks']['downstreamOrphan']  = $this->checkDownstreamOrphan();
            $report['checks']['personLinkMissing'] = $this->checkPersonLinkMissing();
            $report['checks']['staleInProgress']   = $this->checkStaleInProgress($staleDays);
        } catch (\Throwable $e) {
            CLI::error('对账执行失败: ' . $e->getMessage());
            CLI::error($e->getFile() . ':' . $e->getLine());
            return EXIT_ERROR;
        }

        // 差异合计（长期在途不计入）
        $report['diffCount'] =
            $report['checks']['storeMissing']['count']
            + $report['checks']['appOrphan']['count']
            + array_sum(array_column($report['checks']['fieldDiff'], 'count'))
            + $report['checks']['stageMismatch']['count']
            + array_sum(array_column($report['checks']['downstreamOrphan'], 'count'))
            + $report['checks']['personLinkMissing']['count'];
        $report['durationMs'] = (int) round((microtime(true) - $startedAt) * 1000);

        // ---------- CLI 摘要 ----------
        CLI::write('========== 对账摘要 ==========', 'yellow');
        $this->writeCheckLine('① 源行缺失实例', $report['checks']['storeMissing']['count']);
        $this->writeCheckLine('② 实例无对应源行', $report['checks']['appOrphan']['count'],
            '（阶段④写切换后新实例不再镜像 ee_store，>0 属预期）');
        $this->writeCheckLine('③ 字段差异', $report['checks']['fieldDiff']);
        $this->writeCheckLine('④ 阶段不一致', $report['checks']['stageMismatch']['count']);
        $this->writeCheckLine('⑤ 下游孤儿码', $report['checks']['downstreamOrphan']);
        $this->writeCheckLine('⑥ 主档链接缺失', $report['checks']['personLinkMissing']['count']);
        CLI::write(sprintf('⑦ 长期在途（>%d天，仅提示）: %d', $staleDays, $report['checks']['staleInProgress']['count']), 'light_blue');
        CLI::newLine();
        CLI::write('差异合计: ' . $report['diffCount'], $report['diffCount'] > 0 ? 'light_red' : 'light_green');

        // ---------- 报告文件 ----------
        $logDir = WRITEPATH . 'logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        $file = $logDir . '/application_reconcile_' . date('Ymd_His') . '.json';
        file_put_contents($file, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        CLI::write("报告明细已写入: {$file}", 'light_green');

        return $report['diffCount'] > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }

    /**
     * ① ee_store 活跃行缺失实例（搬迁遗漏 / 新增未镜像）
     */
    private function checkStoreMissing(): array
    {
        $sql = 'SELECT s.候选人编码 AS code, s.姓名 AS name
                FROM ee_store s
                WHERE s.有效标识 = "1" AND s.删除标识 = "0" AND s.候选人编码 <> ""
                  AND NOT EXISTS (SELECT 1 FROM ee_application a
                                  WHERE a.候选人编码 = s.候选人编码)';

        return [
            'count'   => $this->countRows($sql),
            'samples' => $this->sampleRows($sql . ' LIMIT ' . $this->sampleLimit),
        ];
    }

    /**
     * ② 实例无对应 ee_store 活跃行（搬迁后源行被失效/删除）
     */
    private function checkAppOrphan(): array
    {
        $sql = 'SELECT a.候选人编码 AS code
                FROM ee_application a
                WHERE NOT EXISTS (SELECT 1 FROM ee_store s
                                  WHERE s.候选人编码 = a.候选人编码
                                    AND s.有效标识 = "1" AND s.删除标识 = "0")';

        return [
            'count'   => $this->countRows($sql),
            'samples' => $this->sampleRows($sql . ' LIMIT ' . $this->sampleLimit),
        ];
    }

    /**
     * ③ 共享字段逐一比对（NULL 与空串视为相等）
     *    邀约日期为 DATE 列，源值经归一化转换链后比对（多格式源值不算差异）
     */
    private function checkFieldDiff(): array
    {
        $result = [];

        foreach (self::COMPARE_FIELDS as $field) {
            $norm = $this->dateNormExpr($field);
            $sql = "SELECT s.候选人编码 AS code,
                           s.`{$field}` AS storeValue, a.`{$field}` AS appValue
                    FROM ee_store s
                    JOIN ee_application a ON a.候选人编码 = s.候选人编码
                    WHERE s.有效标识 = '1' AND s.删除标识 = '0' AND s.候选人编码 <> ''
                      AND NOT (a.`{$field}` <=> {$norm})";

            $count = $this->countRows($sql);
            if ($count > 0) {
                $result[$field] = [
                    'count'   => $count,
                    'samples' => $this->sampleRows($sql . ' LIMIT ' . $this->sampleLimit),
                ];
            }
        }

        return $result;
    }

    /**
     * ④ 阶段一致性：按下游表重算期望阶段
     *    口径与 ee_application_migrate.sql 第 4/5 部分一致：
     *    入职(onjob) > 培训(train，离开则终止) > 面试(interview，未通过则终止)
     *    > 邀约(拒绝则终止)，下游优先于终止判定
     */
    private function checkStageMismatch(): array
    {
        $expectedCase = $this->buildExpectedStageSql();

        // 注意：别名不可用 stored（MySQL 保留字，STORED 生成列关键字）
        $sql = "SELECT x.code, x.storedStage, x.expectedStage
                FROM (
                    SELECT a.候选人编码 AS code, a.当前阶段 AS storedStage, ({$expectedCase}) AS expectedStage
                    FROM ee_application a
                ) x
                WHERE x.storedStage <> x.expectedStage";

        return [
            'count'   => $this->countRows($sql),
            'samples' => $this->sampleRows($sql . ' LIMIT ' . $this->sampleLimit),
        ];
    }

    /**
     * 期望阶段重算 SQL（嵌入 checkStageMismatch）
     * 方案C：面试信息 不在 ee_application，改读 ee_store 活跃行
     */
    private function buildExpectedStageSql(): string
    {
        $onjob   = '(SELECT 1 FROM ee_onjob o
                     WHERE o.候选人编码 = a.候选人编码
                       AND o.有效标识 = "1" AND o.删除标识 = "0")';
        $train   = '(SELECT 1 FROM ee_train t
                     WHERE t.候选人编码 = a.候选人编码
                       AND t.有效标识 = "1" AND t.删除标识 = "0")';
        $trainGo = '(SELECT 1 FROM ee_train t2
                     WHERE t2.候选人编码 = a.候选人编码
                       AND t2.有效标识 = "1" AND t2.删除标识 = "0"
                       AND (IFNULL(t2.培训离开日期, "") <> "" OR IFNULL(t2.培训离开原因, "") <> ""))';
        $itv     = '(SELECT 1 FROM ee_interview i
                     WHERE i.候选人编码 = a.候选人编码
                       AND i.有效标识 = "1" AND i.删除标识 = "0")';
        $itvFail = '(SELECT 1 FROM ee_interview i2
                     WHERE i2.候选人编码 = a.候选人编码
                       AND i2.有效标识 = "1" AND i2.删除标识 = "0"
                       AND IFNULL(i2.一次面试结果, "") = "未通过")';
        $refuse  = '(SELECT 1 FROM ee_store s
                     WHERE s.候选人编码 = a.候选人编码
                       AND s.有效标识 = "1" AND s.删除标识 = "0"
                       AND IFNULL(s.面试信息, "") = "拒绝")';

        return "CASE
                    WHEN EXISTS {$onjob} THEN '入职'
                    WHEN EXISTS {$train} THEN
                        CASE WHEN EXISTS {$trainGo} THEN '终止' ELSE '培训' END
                    WHEN EXISTS {$itv} THEN
                        CASE WHEN EXISTS {$itvFail} THEN '终止' ELSE '面试' END
                    WHEN EXISTS {$refuse} THEN '终止'
                    ELSE '邀约'
                END";
    }

    /**
     * ⑤ 下游孤儿码：下游有效行的候选人编码无对应实例
     */
    private function checkDownstreamOrphan(): array
    {
        $tables  = ['ee_interview', 'ee_train', 'ee_onjob'];
        $result  = [];

        foreach ($tables as $table) {
            $sql = "SELECT t.候选人编码 AS code
                    FROM {$table} t
                    WHERE t.有效标识 = '1' AND t.删除标识 = '0' AND IFNULL(t.候选人编码, '') <> ''
                      AND NOT EXISTS (SELECT 1 FROM ee_application a
                                      WHERE a.候选人编码 = t.候选人编码)";

            $count = $this->countRows($sql);
            if ($count > 0) {
                $result[$table] = [
                    'count'   => $count,
                    'samples' => $this->sampleRows($sql . ' LIMIT ' . $this->sampleLimit),
                ];
            }
        }

        return $result;
    }

    /**
     * ⑥ 主档链接缺失：实例人员编码为空
     */
    private function checkPersonLinkMissing(): array
    {
        $sql = 'SELECT a.候选人编码 AS code
                FROM ee_application a
                WHERE a.人员编码 = "" OR a.人员编码 IS NULL';

        return [
            'count'   => $this->countRows($sql),
            'samples' => $this->sampleRows($sql . ' LIMIT ' . $this->sampleLimit),
        ];
    }

    /**
     * ⑦ 长期在途：仍处邀约/面试/培训阶段且邀约日期早于 N 天（仅提示；
     *    邀约日期为 DATE 列，直接比较）
     */
    private function checkStaleInProgress(int $staleDays): array
    {
        $sql = "SELECT a.候选人编码 AS code, a.当前阶段 AS stage, a.邀约日期 AS inviteDate
                FROM ee_application a
                WHERE a.当前阶段 IN ('邀约', '面试', '培训')
                  AND a.邀约日期 IS NOT NULL
                  AND a.邀约日期 < DATE_SUB(CURDATE(), INTERVAL {$staleDays} DAY)";

        return [
            'count'   => $this->countRows($sql),
            'samples' => $this->sampleRows($sql . ' LIMIT ' . $this->sampleLimit),
        ];
    }

    // ------------------------------------------------------------
    // 辅助方法
    // ------------------------------------------------------------

    private function countRows(string $sql): int
    {
        $row = $this->model->select('SELECT COUNT(*) AS c FROM (' . $sql . ') AS t')->getRowArray();

        return (int) ($row['c'] ?? 0);
    }

    private function sampleRows(string $sql): array
    {
        return $this->model->select($sql)->getResultArray();
    }

    /**
     * CLI 摘要行输出
     *
     * @param string $label 检查项名称
     * @param int|array $result 计数或 [表/字段 => ['count' => n]] 结构
     * @param string $note 附加说明（仅 0 差异时展示亦可）
     */
    private function writeCheckLine(string $label, $result, string $note = ''): void
    {
        if (is_int($result)) {
            $count = $result;
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
