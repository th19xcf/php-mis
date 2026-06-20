<?php

namespace App\Controllers\Workbench;

use App\Libraries\AuthorizationService;

/**
 * 工作台控制器共享能力
 *
 * 提供查询配置加载、主键解析、WHERE 条件构建等工作台特有逻辑，
 * 供 Workbench、WorkbenchImportController、WorkbenchEditController 等使用。
 * 响应封装（success/error）统一由 BaseApiController 提供。
 */
trait WorkbenchResponseTrait
{
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
            $this->model->quote($functionCode)
        );

        $result = $this->model->select($sql);
        if ($result !== false && ($row = $result->getRowArray()) && !empty($row['主键字段'])) {
            return $row['主键字段'];
        }

        $sql = sprintf('SHOW INDEX FROM %s WHERE Key_name = "PRIMARY"', $dataTable);
        $result = $this->model->select($sql);
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
                $conditions[] = sprintf('%s=%s', $key, $this->model->quote((string)$data[$key]));
            }
        }

        return implode(' and ', $conditions);
    }
}
