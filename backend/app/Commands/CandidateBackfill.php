<?php

namespace App\Commands;

use App\Services\Person\CandidateCodeService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * 候选人编码存量回填命令
 *
 * 四表（ee_store/ee_interview/ee_train/ee_onjob）空码行回填 候选人编码：
 * - ee_store：邀约是链路起点，逐日期桶发新码；同时全量重算邀约次数
 *   （同人员编码按 [邀约日期, GUID] 排序 1..n，修正人工填写断裂）
 * - ee_interview/ee_train/ee_onjob：同人员编码继承上游最近业务日期行的码；
 *   无匹配（直接起点/日期矛盾/无人员编码/无日期）走发码兜底
 *
 * 幂等可重跑（已有码行自动跳过）。
 *
 * 用法：
 *   php spark candidate:backfill              # dry-run 只出报告（不写库、不消耗序列号）
 *   php spark candidate:backfill --execute    # 正式执行
 *
 * 报告文件（含日期矛盾明细样本）输出至 writable/logs/candidate_backfill_report_*.json
 */
class CandidateBackfill extends BaseCommand
{
    protected $group       = 'Person';
    protected $name        = 'candidate:backfill';
    protected $description = '存量回填：四表空码行补发候选人编码（链路继承+兜底发码），ee_store 同时重算邀约次数';
    protected $usage       = 'php spark candidate:backfill [--execute]';
    protected $arguments   = [];
    protected $options     = [
        '--execute' => '正式执行写库（缺省为 dry-run 只出报告，不消耗序列号）',
    ];

    public function run(array $params)
    {
        $dryRun = CLI::getOption('execute') === null;

        CLI::write('候选人编码存量回填（ee_store 起点发码 → 面试/培训/在职 链路继承 + 兜底发码）', 'yellow');
        CLI::write('模式: ' . ($dryRun ? 'DRY-RUN（不写库、不消耗序列号）' : '正式执行'), $dryRun ? 'light_blue' : 'red');
        CLI::newLine();

        $service = new CandidateCodeService();

        $progress = static function (string $stage): void {
            CLI::write('  → ' . $stage);
        };

        try {
            $report = $service->backfill($dryRun, $progress);
        } catch (\Throwable $e) {
            CLI::error('回填失败: ' . $e->getMessage());
            CLI::error($e->getFile() . ':' . $e->getLine());
            return EXIT_ERROR;
        }

        // 输出摘要
        CLI::newLine();
        CLI::write('========== 回填报告 ==========', 'yellow');

        $store = $report['store'];
        $word = $dryRun ? '预计' : '实际';
        CLI::write('[ee_store 邀约]');
        CLI::write("  有效行: {$store['total']}    待发码: {$store['needCode']}");
        CLI::write("  {$word}发码: {$store['codeAssigned']}    {$word}重算邀约次数: {$store['countRecalced']}");
        CLI::write("  无人员编码: {$store['noPerson']}    无邀约日期: {$store['undated']}", 'light_red');

        $labels = [
            'interview' => 'ee_interview 面试',
            'train'     => 'ee_train 培训',
            'onjob'     => 'ee_onjob 在职',
        ];
        foreach ($labels as $key => $label) {
            $r = $report[$key];
            CLI::newLine();
            CLI::write("[{$label}]");
            CLI::write("  有效行: {$r['total']}    待回填: {$r['needCode']}");
            CLI::write("  {$word}继承上游: {$r['inherited']}    {$word}兜底发码: {$r['newCode']}");
            CLI::write("  日期矛盾（不强行继承，兜底发码+记报告）: {$r['dateConflict']}", 'light_red');
            CLI::write("  无人员编码: {$r['noPerson']}    无业务日期: {$r['undated']}", 'light_red');
        }

        // 报告文件
        $logDir = WRITEPATH . 'logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        $file = $logDir . '/candidate_backfill_report_' . date('Ymd_His') . ($dryRun ? '_dryrun' : '') . '.json';
        file_put_contents($file, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        CLI::newLine();
        CLI::write("报告明细（含日期矛盾样本）已写入: {$file}", 'light_green');

        if ($dryRun) {
            CLI::newLine();
            CLI::write('确认无误后执行: php spark candidate:backfill --execute', 'yellow');
        }

        return EXIT_SUCCESS;
    }
}
