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

    public function modify(string $sql): int
    {
        $db = $this->getDb();
        $db->query($sql);
        return $db->affectedRows();
    }

    public function add(string $table, array $data, array $fld_arr): int
    {
        $db = $this->getDb();
        $num = 0;

        foreach ($data as $arr) {
            if (!array_diff($arr, $fld_arr)) {
                continue;
            }
            $arr = array_combine($fld_arr, $arr);
            $db->table($table)->insert($arr);
            $num = $db->affectedRows();
        }

        return $num;
    }

    public function add_by_trans(string $table, array $data, array $col_arr, array $fld_arr): int
    {
        $db = $this->getDb();

        $db->transStart();

        $num = 0;
        foreach ($data as $arr) {
            $db->table($table)->insert($arr);
            $num += $db->affectedRows();
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            log_message('error', '事务执行错误');
            return -1;
        }

        return $num;
    }

    public function exec(string $sql): int
    {
        $db = $this->getDb();
        $db->query($sql);
        return $db->affectedRows();
    }

    public function import_before_sp(string $sp, ?string &$param = null): object
    {
        $db = $this->getDb();
        $query = $db->query($sp);
        $out = $db->query('select @out')->getResultArray();
        if (count($out) > 0) {
            $param = current($out[0]);
        }

        return $query;
    }

    public function get_fields(string $table_name): array
    {
        $db = $this->getDb();
        return $db->getFieldNames($table_name);
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

    public function getLastQuery(): ?string
    {
        $db = $this->getDb();
        return (string) $db->getLastQuery();
    }
}
