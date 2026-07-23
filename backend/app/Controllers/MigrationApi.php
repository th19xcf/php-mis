<?php

namespace App\Controllers;

class MigrationApi extends BaseApiController
{
    /**
     * 执行数据库迁移
     * 通过 Web API 调用，避免 CLI 环境下 .env 加载问题
     */
    public function run()
    {
        try {
            $dbConfig = config('Database');
            $btdc = $dbConfig->btdc;

            $debug = [
                'config_hostname' => $btdc['hostname'],
                'config_port' => $btdc['port'],
                'config_database' => $btdc['database'],
            ];

            // 测试数据库连接
            $db = \Config\Database::connect('btdc', false);
            $db->initialize();
            $debug['ci4_connect'] = 'OK';

            // 创建 MigrationRunner，传入 'btdc' 字符串确保 group 和 db 都使用 btdc
            $config = config('Migrations');
            $runner = new \CodeIgniter\Database\MigrationRunner($config, 'btdc');
            $result = $runner->latest('btdc');

            if ($result) {
                return $this->success([
                    'migrated' => true,
                    'debug' => $debug,
                    'messages' => $runner->getCliMessages() ?? []
                ], '迁移执行成功');
            }

            return $this->success([
                'migrated' => false,
                'debug' => $debug,
                'messages' => []
            ], '没有需要执行的迁移');
        } catch (\Throwable $e) {
            log_message('error', '[MigrationApi::run] ' . $e->getMessage());
            return $this->serverError($e->getMessage() . ' | debug: ' . json_encode($debug ?? []));
        }
    }

    /**
     * 查看迁移状态
     */
    public function status()
    {
        try {
            $config = config('Migrations');
            $runner = new \CodeIgniter\Database\MigrationRunner($config, 'btdc');
            $migrations = $runner->findMigrations();

            $list = [];
            foreach ($migrations as $migration) {
                $list[] = [
                    'version' => $migration->version,
                    'name' => $migration->name,
                    'namespace' => $migration->namespace,
                ];
            }

            // 查询已执行的迁移记录
            $db = \Config\Database::connect('btdc', false);
            $executed = [];
            try {
                $rows = $db->query("SELECT version, batch FROM migrations ORDER BY version")->getResultArray();
                foreach ($rows as $row) {
                    $executed[] = ['version' => $row['version'], 'batch' => $row['batch']];
                }
            } catch (\Throwable $e) {
                // migrations 表可能不存在
            }

            return $this->success([
                'total' => count($list),
                'executed' => count($executed),
                'migrations' => $list,
                'executed_records' => $executed,
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[MigrationApi::status] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }
}
