<?php

namespace App\Controllers\Workbench;

use App\Constants\ApiCode;
use App\Libraries\AuthorizationService;
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
     * 加载工作台查询配置（统一委托到 AuthorizationService::loadQueryConfig）。
     *
     * 注意：不缓存 AuthorizationService 实例于 trait 属性中 —— 因为 Workbench.php
     * 已声明 private AuthorizationService $authorizationService 属性，trait 再声明
     * 会因"nullable vs non-nullable"产生 Fatal error。每次调用新建 AuthorizationService
     * 是安全的：Mcommon 内部通过 getDb() 缓存 db 连接，CI 的 db_connect('btdc') 是
     * 全局共享，所以多个 AuthorizationService 实例共享同一个 DB 连接。
     *
     * @param string $functionCode 功能编码
     * @param string $userRole     当前用户角色（用于替换 $角色 变量）
     * @return array
     */
    protected function loadQueryConfig(string $functionCode, string $userRole): array
    {
        return (new AuthorizationService())->loadQueryConfig($functionCode, $userRole);
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
