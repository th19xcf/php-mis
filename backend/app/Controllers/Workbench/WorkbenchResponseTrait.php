<?php

namespace App\Controllers\Workbench;

use App\Constants\ApiCode;
use App\Models\Mcommon;

/**
 * 工作台控制器共享能力
 *
 * 提供响应封装、SQL 引用、查询配置加载等公用逻辑，
 * 供 Workbench、WorkbenchImportController、WorkbenchEditController 等使用。
 */
trait WorkbenchResponseTrait
{
    /** @var Mcommon */
    protected Mcommon $common;

    /**
     * 注入 Mcommon 实例（由使用方的 initController 调用）
     */
    protected function initWorkbenchTrait(Mcommon $common): void
    {
        $this->common = $common;
    }

    /**
     * 统一成功响应
     *
     * @param array $data
     * @return \CodeIgniter\HTTP\Response
     */
    protected function success(array $data)
    {
        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg'  => 'success',
            'data' => $data,
        ]);
    }

    /**
     * 统一失败响应
     *
     * @param string $code
     * @param string $message
     * @return \CodeIgniter\HTTP\Response
     */
    protected function error(string $code, string $message)
    {
        return $this->response->setJSON([
            'code' => $code,
            'msg'  => $message,
        ]);
    }

    /**
     * SQL 字符串值引用（addslashes + 单引号包裹）
     *
     * @param string $value
     * @return string
     */
    protected function quote(string $value): string
    {
        return sprintf("'%s'", str_replace(["\\", "'"], ["\\\\", "\\'"], $value));
    }

    /**
     * 加载工作台查询配置
     *
     * @param string $functionCode 功能编码
     * @param string $userRole     当前用户角色（用于替换 $角色 变量）
     * @return array
     */
    protected function loadQueryConfig(string $functionCode, string $userRole): array
    {
        $sql = sprintf(
            'select
                查询模块,模块类型,字段模块,钻取模块,
                查询表名,数据表名,数据模式,
                查询条件,汇总条件,排序条件,初始条数,
                新增前处理模块,新增后处理模块,
                更新前处理模块,更新后处理模块,
                数据整理模块,备注模块,导入模块,图形模块,表样式
            from def_query_config
            where 查询模块 in
                (
                    select 模块名称
                    from def_function
                    where 有效标识="1" and 功能编码=%s
                )',
            $this->quote($functionCode)
        );

        $result = $this->common->select($sql);
        if ($result === false) {
            return [];
        }
        $row = $result->getRowArray();
        if (!$row) {
            return [];
        }

        $queryWhere = (string) ($row['查询条件'] ?? '');
        if ($queryWhere !== '' && strpos($queryWhere, '$角色') !== false) {
            $queryWhere = str_replace('$角色', $userRole, $queryWhere);
        }

        return [
            'queryModule'   => (string) ($row['查询模块'] ?? ''),
            'drillModule'   => (string) ($row['钻取模块'] ?? ''),
            'mode'          => (string) ($row['模块类型'] ?? '数据查询'),
            'fieldModule'   => (string) ($row['字段模块'] ?? ''),
            'queryTable'    => (string) ($row['查询表名'] ?? ''),
            'dataTable'     => (string) ($row['数据表名'] ?? ''),
            'dataModel'     => (string) ($row['数据模式'] ?? ''),
            'queryWhere'    => $queryWhere,
            'queryGroup'    => (string) ($row['汇总条件'] ?? ''),
            'queryOrder'    => (string) ($row['排序条件'] ?? ''),
            'resultCount'   => (int) ($row['初始条数'] ?? 0),
            'beforeInsert'  => (string) ($row['新增前处理模块'] ?? ''),
            'afterInsert'   => (string) ($row['新增后处理模块'] ?? ''),
            'beforeUpdate'  => (string) ($row['更新前处理模块'] ?? ''),
            'afterUpdate'   => (string) ($row['更新后处理模块'] ?? ''),
            'commentModule' => (string) ($row['备注模块'] ?? ''),
            'importModule'  => (string) ($row['导入模块'] ?? ''),
            'upkeepModule'  => (string) ($row['数据整理模块'] ?? ''),
            'chartModule'   => (string) ($row['图形模块'] ?? ''),
            'gridStyle'     => (string) (($row['表样式'] ?? '') === '' ? '表样式_A' : $row['表样式']),
        ];
    }

    /**
     * 解析主键字段
     *
     * 优先从 session 取，其次从 def_query_config 取，最后从表结构推断。
     *
     * @param string $functionCode
     * @param array  $queryConfig
     * @return string
     */
    protected function getPrimaryKey(string $functionCode, array $queryConfig): string
    {
        $session = \Config\Services::session();
        $primaryKey = $session->get($functionCode . '-primary_key');

        if (!empty($primaryKey)) {
            return $primaryKey;
        }

        $dataTable = $queryConfig['dataTable'] ?? '';
        if (empty($dataTable)) {
            return '';
        }

        $sql = sprintf(
            'SELECT t1.主键字段 FROM def_query_config t1
            INNER JOIN def_function t2 ON t2.模块名称 = t1.查询模块
            WHERE t2.功能编码 = %s',
            $this->quote($functionCode)
        );

        $result = $this->common->select($sql);
        if ($result !== false && ($row = $result->getRowArray()) && !empty($row['主键字段'])) {
            return $row['主键字段'];
        }

        $sql = sprintf('SHOW INDEX FROM %s WHERE Key_name = "PRIMARY"', $dataTable);
        $result = $this->common->select($sql);
        if ($result !== false && ($row = $result->getRowArray())) {
            return $row['Column_name'] ?? '';
        }

        return '';
    }

    /**
     * 根据数据与主键构建 WHERE 条件（分号分隔的复合主键）
     *
     * @param array  $data
     * @param string $primaryKey
     * @return string
     */
    protected function buildWhereFromData(array $data, string $primaryKey): string
    {
        $keys = explode(';', $primaryKey);
        $conditions = [];

        foreach ($keys as $key) {
            $key = trim($key);
            if (isset($data[$key])) {
                $conditions[] = sprintf('%s="%s"', $key, addslashes($data[$key]));
            }
        }

        return implode(' and ', $conditions);
    }
}
