<?php

namespace App\Models;

use CodeIgniter\Model;

class Mcommon extends Model
{
    protected $DBGroup = 'btdc';

    private ?object $dbInstance = null;

    private function getDb(): object
    {
        if ($this->dbInstance === null) {
            $this->dbInstance = db_connect('btdc');
        }
        return $this->dbInstance;
    }

    public function select(string $sql)
    {
        $db = $this->getDb();
        return $db->query($sql);
    }

    /**
     * 执行带参数绑定的 SQL（INSERT / UPDATE / DELETE）
     *
     * CodeIgniter 的 Query Builder 会把数组中的标量依次替换到 SQL 中的 ? 占位符。
     * 注意：调用方需要使用 ? 占位符；此处兼容历史代码中遗留的 %s 占位符。
     *
     * @param string $sql SQL 语句
     * @param array  $bindings 绑定的参数数组
     * @return object|\CodeIgniter\Database\BaseResult
     */
    public function query(string $sql, array $bindings = [])
    {
        $db = $this->getDb();
        // 将历史遗留的 %s 占位符规范化为 ?，由驱动层完成安全绑定
        $normalized = preg_replace('/%s/', '?', $sql);
        return $db->query($normalized, $bindings);
    }

    /**
     * 返回上一次通过 query() / modify() 执行的 SQL 所影响的行数。
     *
     * 由于 Model 基类的 affectedRows() 会走 __call() 触发 Query Builder，
     * 而本类的写操作走的是原始 query()，需要直接从底层连接获取受影响行数。
     */
    public function affectedRows(): int
    {
        return (int) $this->getDb()->affectedRows();
    }

    public function exec(string $sql): int
    {
        $db = $this->getDb();
        $db->query($sql);
        return $db->affectedRows();
    }

    public function sql_log(string $option, string $func_id = '', string $info = ''): int
    {
        $session = \Config\Services::session();
        $user_name = $session->get('user_name');
        $user_workid = $session->get('user_workid');
        $log_switch = $session->get('log_switch');

        if (!$log_switch) {
            return 0;
        }

        $db = $this->getDb();

        $insert = sprintf(
            'insert into sys_sql_log (姓名,用户名,动作,功能编码,信息) values ("%s","%s","%s","%s","%s")',
            $user_name,
            $user_workid,
            $option,
            $func_id,
            $info
        );

        $db->query($insert);
        return $db->affectedRows();
    }

    public function quote(string $value): string
    {
        $db = $this->getDb();
        return $db->escape($value);
    }

}
