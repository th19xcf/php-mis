<?php

namespace App\Commands;

use App\Services\Employee\EmploymentMigrateService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * ee_employment 雇佣记录表存量迁移命令（阶段③第①步）
 *
 * ee_onjob 有效行（有效标识='1' AND 删除标识='0'）迁入 ee_employment，
 * 入职次数按人员编码派生（不再信任 ee_onjob 手填值）。
 * SCD2 失效版本行不迁移，随 ee_onjob 原地归档（五步路径第⑤步）。
 *
 * 三路分流：A 直迁（人员编码非空）/ B 补挂（身份证号命中主档，同事务回填
 * ee_onjob.人员编码）/ C 无法归并（证件号空或无主档命中，提示先跑 person:migrate）。
 *
 * 用法：
 *   php spark employment:migrate                          # dry-run 只出报告
 *   php spark employment:migrate --execute                # 正式执行迁入
 *   php spark employment:migrate --execute --fix-stage    # 迁入后修复状态失真
 *   php spark employment:migrate --execute --batch 1000 --operator system
 *
 * --fix-stage：ee_application 当前阶段=入职 但 ee_employment 员工状态=离职 的
 * 失真实例，经 ApplicationService::transferStage 流转为 终止（逐码独立事务，
 * 失败跳过不阻断）。dry-run 下仅预检计数不执行。
 *
 * 幂等可重跑：迁入按 (人员编码+候选人编码) NOT EXISTS 跳过；fixStage 对已
 * 终止实例抛异常捕获后计入 skipped。
 *
 * 报告文件输出至 writable/logs/employment_migrate_report_*.json
 */
class EmploymentMigrate extends BaseCommand
{
    protected $group       = 'Person';
    protected $name        = 'employment:migrate';
    protected $description = '存量迁移：ee_onjob 有效行迁入 ee_employment（派生入职次数，幂等可重跑）';
    protected $usage       = 'php spark employment:migrate [--execute] [--fix-stage] [--batch 1000] [--operator system]';
    protected $arguments   = [];
    protected $options     = [
        '--execute'   => '正式执行写库（缺省为 dry-run 只出报告）',
        '--fix-stage' => '修复状态失真（实例=入职 但雇佣记录已离职 → 流转终止）',
        '--batch'     => '每批事务的行数（默认 1000）',
        '--operator'  => '操作人员工号（写入审计字段，默认 system）',
    ];

    public function run(array $params)
    {
        $dryRun   = CLI::getOption('execute') === null;
        $fixStage = CLI::getOption('fix-stage') !== null;
        $batch    = max(1, (int) (CLI::getOption('batch') ?? 1000));
        $operator = (string) (CLI::getOption('operator') ?? 'system');

        CLI::write('雇佣记录表存量迁移（ee_onjob 有效行 → ee_employment）', 'yellow');
        CLI::write('模式: ' . ($dryRun ? 'DRY-RUN（不写库）' : '正式执行'), $dryRun ? 'light_blue' : 'red');
        CLI::write('状态失真修复: ' . ($fixStage ? ($dryRun ? '预检（不执行）' : '执行') : '关闭'));
        CLI::write("批次: {$batch} 行/事务    操作人员: {$operator}");
        CLI::newLine();

        $service = new EmploymentMigrateService();

        $progress = static function (int $done, int $total, string $stage) {
            if ($total > 0) {
                CLI::write(sprintf('[%s] %d / %d', $stage, $done, $total));
            }
        };

        try {
            $report = $service->migrate($dryRun, $batch, $operator, $progress, $fixStage);
        } catch (\Throwable $e) {
            CLI::error('迁移失败: ' . $e->getMessage());
            CLI::error($e->getFile() . ':' . $e->getLine());
            return EXIT_ERROR;
        }

        // 输出摘要
        CLI::newLine();
        CLI::write('========== 迁移报告 ==========', 'yellow');
        CLI::write("源行数（ee_onjob 有效行）:  {$report['sourceRows']}");
        CLI::write("A 直迁（人员编码非空）:     {$report['directRows']}");
        CLI::write("B 补挂（证件号命中主档）:   {$report['attachRows']}");
        CLI::write("C 无法归并（先跑 person:migrate）: {$report['unableRows']}", 'light_red');
        if ($dryRun) {
            CLI::write("预计迁入: {$report['expectedMigrate']} 行");
        } else {
            CLI::write("实际迁入: {$report['migrated']} 行");
            CLI::write("回填后源表人员编码非空行数: {$report['backfilled']}");
        }
        CLI::newLine();
        CLI::write("多人多次雇佣（一人多链）: {$report['multiChainPersons']} 人", 'light_blue');
        CLI::write("派生入职次数 vs 手填值差异: {$report['seqMismatch']} 行（以派生值为准，仅报告暴露）", 'light_yellow');
        $dateFails = array_filter($report['dateParseFailures'], static fn($c) => $c > 0);
        if (!empty($dateFails)) {
            CLI::write('日期解析失败（迁入后落 NULL）: ' . json_encode($dateFails, JSON_UNESCAPED_UNICODE), 'light_red');
        } else {
            CLI::write('日期解析失败: 0 行', 'light_green');
        }

        if ($fixStage) {
            CLI::newLine();
            $fs = $report['fixStage'];
            if ($dryRun) {
                CLI::write("状态失真预检: {$fs['skipped']} 行（--execute --fix-stage 修复）", 'light_yellow');
            } else {
                CLI::write("状态失真修复: 已流转 {$fs['applied']} 条 / 跳过 {$fs['skipped']} 条");
                if (!empty($fs['rollbackSql'])) {
                    CLI::write('回滚模板已写入报告 JSON（人工回退用）', 'light_yellow');
                }
            }
        }

        // 报告文件
        $logDir = WRITEPATH . 'logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        $file = $logDir . '/employment_migrate_report_' . date('Ymd_His') . ($dryRun ? '_dryrun' : '') . '.json';
        file_put_contents($file, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        CLI::newLine();
        CLI::write("报告明细已写入: {$file}", 'light_green');

        if ($dryRun) {
            CLI::newLine();
            CLI::write('确认无误后执行: php spark employment:migrate --execute [--fix-stage]', 'yellow');
        }

        return EXIT_SUCCESS;
    }
}
