<?php

namespace App\Commands;

use App\Services\Person\PersonMigrateService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * 人员主档存量迁移命令
 *
 * ee_store + ee_interview + ee_train + ee_onjob 有效未挂档行合并分组建档至 hr_person，
 * 并回填业务表 人员编码。幂等可重跑（已挂档行自动跳过）。
 *
 * 用法：
 *   php spark person:migrate                    # dry-run 只出报告
 *   php spark person:migrate --execute          # 正式执行
 *   php spark person:migrate --execute --batch 1000 --operator system
 *
 * 报告文件输出至 writable/logs/person_migrate_report_*.json
 */
class PersonMigrate extends BaseCommand
{
    protected $group       = 'Person';
    protected $name        = 'person:migrate';
    protected $description = '存量迁移：ee_store/ee_interview/ee_train/ee_onjob 合并建档至 hr_person 并回填人员编码';
    protected $usage       = 'php spark person:migrate [--execute] [--batch 1000] [--operator system]';
    protected $arguments   = [];
    protected $options     = [
        '--execute' => '正式执行写库（缺省为 dry-run 只出报告）',
        '--batch'   => '每批事务的组数（默认 1000）',
        '--operator' => '操作人员工号（写入 hr_person.操作人员，默认 system）',
    ];

    public function run(array $params)
    {
        $dryRun   = CLI::getOption('execute') === null;
        $batch    = max(1, (int) (CLI::getOption('batch') ?? 1000));
        $operator = (string) (CLI::getOption('operator') ?? 'system');

        CLI::write('人员主档存量迁移（ee_store + ee_interview + ee_train + ee_onjob → hr_person）', 'yellow');
        CLI::write('模式: ' . ($dryRun ? 'DRY-RUN（不写库）' : '正式执行'), $dryRun ? 'light_blue' : 'red');
        CLI::write("批次: {$batch} 组/事务    操作人员: {$operator}");
        CLI::newLine();

        $service = new PersonMigrateService();

        $progress = static function (int $done, int $total, string $stage) {
            if ($total > 0) {
                CLI::write(sprintf('[%s] %d / %d', $stage, $done, $total));
            }
        };

        try {
            $report = $service->migrate($dryRun, $batch, $operator, $progress);
        } catch (\Throwable $e) {
            CLI::error('迁移失败: ' . $e->getMessage());
            CLI::error($e->getFile() . ':' . $e->getLine());
            return EXIT_ERROR;
        }

        // 输出摘要
        CLI::newLine();
        CLI::write('========== 迁移报告 ==========', 'yellow');
        CLI::write("源行数（各源表有效未挂档）: {$report['sourceRows']}");
        CLI::write("分组数（独立人员）:       {$report['groupsTotal']}");
        if ($dryRun) {
            CLI::write("预计新建主档: {$report['createdGroups']} 组 / {$report['createdRows']} 行回填");
            CLI::write("预计挂接主档: {$report['attachedGroups']} 组 / {$report['attachedRows']} 行回填");
        } else {
            CLI::write("实际新建主档: {$report['created']} 组");
            CLI::write("实际挂接主档: {$report['attached']} 组");
            CLI::write("实际回填行数: " . ($report['createdRows'] + $report['attachedRows']));
        }
        CLI::newLine();
        CLI::write("无法建档行:       {$report['unableRows']}", 'light_red');
        CLI::write("证件号冲突组:     {$report['conflictIdcard']}（已自动合并，取最新证件号）", 'light_red');
        CLI::write("证件号-姓名冲突:  {$report['conflictIdcardName']}（脏数据，已按最新姓名）", 'light_red');
        CLI::write("多条软命中组:     {$report['conflictSoftMulti']}（跳过待人工）", 'light_red');

        // 报告文件
        $logDir = WRITEPATH . 'logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        $file = $logDir . '/person_migrate_report_' . date('Ymd_His') . ($dryRun ? '_dryrun' : '') . '.json';
        file_put_contents($file, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        CLI::newLine();
        CLI::write("报告明细已写入: {$file}", 'light_green');

        if ($dryRun) {
            CLI::newLine();
            CLI::write('确认无误后执行: php spark person:migrate --execute', 'yellow');
        }

        return EXIT_SUCCESS;
    }
}
