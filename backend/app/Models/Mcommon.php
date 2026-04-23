<?php

/* v2.4.1.1.202510081330, from home */

namespace App\Models;
use CodeIgniter\Model;

class Mcommon extends Model
{
    //+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=
    // 通用查询
    //+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=
    public function select($sql)
    {
        $db = db_connect('btdc');
        $query = $db->query($sql);
        $db->close();

        return $query;
    }

    //+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=
    // 通用更新
    //+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=
    public function modify($sql)
    {
        $db = db_connect('btdc');

        $db->query($sql);
        $num = $db->affectedRows();

        $db->close();

        return $num;
    }

    //+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=
    // 通用插入
    //+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=
    public function add($table, $data, $fld_arr)
    {
        $db = db_connect('btdc');

        foreach ($data as $arr)
        {
            if (!array_diff($arr, $fld_arr)) continue;  //表头
            $arr = array_combine($fld_arr, $arr);  //修改键名
            $db->table($table)->insert($arr);
            $num = $db->affectedRows();
        }

        $db->close();

        return $num;
    }

    //+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=
    // 使用事务方式插入
    //+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=
    public function add_by_trans($table, $data, $col_arr, $fld_arr)
    {
        $db = db_connect('btdc');

        $db->transStart();

        $num = 0;
        foreach ($data as $arr)
        {
            $db->table($table)->insert($arr);
            $num = $num + $db->affectedRows();
        }

        $db->transComplete();

        if ($this->db->transStatus() == false)
        {
            log_message('error', '事务执行错误');
            return -1;
        }

        $db->close();

        return $num;
    }

    //+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=
    // 通用执行sql
    //+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=
    public function exec($sql)
    {
        $db = db_connect('btdc');

        $db->query($sql);
        $num = $db->affectedRows();

        $db->close();

        return $num;
    }

    //+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=
    // 导入前处理模块,存储过程调用
    //+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=
    public function import_before_sp($sp, &$param = null)
    {
        $db = db_connect('btdc');
        $query = $db->query($sp);
        $out = $db->query('select @out')->getResultArray();
        if (count($out)>0)
        {
            $param = current($out[0]);
        }

        $db->close();

        return $query;
    }

    //+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=
    // 读出字段
    //+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=
    public function get_fields($table_name)
    {
        $db = db_connect('btdc');
        $fields = $db->getFieldNames($table_name);
        $db->close();

        return $fields;
    }

    //+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=
    // 通用日志sql
    //+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=
    public function sql_log($option, $func_id='', $info='')
    {
        $session = \Config\Services::session();
        $user_name = $session->get('user_name');
        $user_workid = $session->get('user_workid');
        $log_switch = $session->get('log_switch');

        if (!$log_switch) return 0; // 不写日志

        $db = db_connect('btdc');

        $insert = sprintf('
            insert into sys_sql_log
            (姓名,用户名,动作,功能编码,信息)
            values ("%s","%s","%s","%s","%s")',
            $user_name, $user_workid, $option, $func_id, $info);

        $db->query($insert);
        $num = $db->affectedRows();

        $db->close();

        return $num;
    }
}
