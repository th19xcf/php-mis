<?php

namespace App\Controllers\Workbench;

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
     * 通过 BaseApiController::getAuthorizationService() 获取请求内单例，
     * 避免每次调用新建实例。
     *
     * @param string $functionCode 功能编码
     * @param string $userRole     当前用户角色（用于替换 $角色 变量）
     * @return array
     */
    protected function loadQueryConfig(string $functionCode, string $userRole): array
    {
        return $this->getAuthorizationService()->loadQueryConfig($functionCode, $userRole);
    }

    /**
     * 解析主键字段
     *
     * 通过 MetadataCache::getPrimaryKey 获取（跨用户共享缓存，TTL 86400s），
     * 命中缓存时跳过 SQL，消除每个新用户首次访问触发的 SHOW INDEX 调用。
     *
     * @param string $functionCode
     * @param array  $queryConfig
     * @return string
     */
    protected function getPrimaryKey(string $functionCode, array $queryConfig): string
    {
        $dataTable = $queryConfig['dataTable'] ?? '';
        return (new \App\Libraries\MetadataCache())->getPrimaryKey($functionCode, $dataTable);
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
