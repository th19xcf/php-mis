<?php

namespace App\Libraries;

use App\Models\Mcommon;
use CodeIgniter\Cache\CacheInterface;
use Config\Services;

class MetadataCache
{
    private const CACHE_PREFIX = 'metadata_';

    private const TTL_DEF_QUERY_COLUMN = 3600;
    private const TTL_DEF_CHART_DRILL_CONFIG = 3600;
    private const TTL_DEF_QUERY_CONFIG = 7200;
    private const TTL_DEF_USER = 1800;

    private CacheInterface $cache;
    private Mcommon $model;
    private array $listeners = [];

    public function __construct()
    {
        $this->cache = Services::cache();
        $this->model = new Mcommon();
    }

    /**
     * 注册缓存失效事件监听器
     *
     * @param callable $listener 回调函数，接收参数：tableName, timestamp
     */
    public function onInvalidate(callable $listener): void
    {
        $this->listeners[] = $listener;
    }

    /**
     * 触发缓存失效事件
     */
    private function triggerInvalidate(string $tableName): void
    {
        $timestamp = time();
        foreach ($this->listeners as $listener) {
            try {
                $listener($tableName, $timestamp);
            } catch (\Throwable $e) {
                log_message('error', '[MetadataCache] 事件监听器执行失败: ' . $e->getMessage());
            }
        }
    }

    /**
     * 获取 def_query_column 表的弹窗配置映射
     *
     * @return array [列名/查询名/字段名 => 对象名]
     */
    public function getPopupColumnMap(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'popup_column_map';
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            log_message('debug', '[MetadataCache] getPopupColumnMap 缓存命中');
            return $cached;
        }

        $sql = 'select 对象, 对象表名, 查询名, 列名, 字段名
                from def_query_column
                where 赋值类型="弹窗"
                group by 对象';

        $result = $this->model->select($sql);
        if ($result === false) {
            return [];
        }

        $map = [];
        foreach ($result->getResultArray() as $row) {
            $object = $row['对象'] ?? '';
            $queryName = $row['查询名'] ?? '';
            $columnName = $row['列名'] ?? '';
            $fieldName = $row['字段名'] ?? '';
            if (!empty($object)) {
                if (!empty($queryName)) {
                    $map[$queryName] = $object;
                }
                if (!empty($columnName)) {
                    $map[$columnName] = $object;
                }
                if (!empty($fieldName)) {
                    $map[$fieldName] = $object;
                }
                $map[$object] = $object;
            }
        }

        $this->cache->save($cacheKey, $map, self::TTL_DEF_QUERY_COLUMN);
        log_message('info', '[MetadataCache] getPopupColumnMap 缓存写入, ' . count($map) . ' 条');

        return $map;
    }

    /**
     * 获取 def_query_column 表中指定对象的弹窗配置
     *
     * @param string $objectName 对象名称
     * @return array|null ['对象' => ..., '对象表名' => ...]
     */
    public function getPopupConfigByObject(string $objectName): ?array
    {
        $cacheKey = self::CACHE_PREFIX . 'popup_config_' . md5($objectName);
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            log_message('debug', '[MetadataCache] getPopupConfigByObject 缓存命中: ' . $objectName);
            return $cached;
        }

        $sql = sprintf(
            'select 对象, 对象表名
            from def_query_column
            where 赋值类型="弹窗" and 对象=%s
            group by 对象
            limit 1',
            $this->model->quote($objectName)
        );

        $result = $this->model->select($sql);
        if ($result === false) {
            return null;
        }

        $row = $result->getRowArray();
        if ($row === null) {
            return null;
        }

        $config = [
            '对象'     => $row['对象'],
            '对象名称' => $objectName,
            '对象表名' => $row['对象表名'],
        ];

        $this->cache->save($cacheKey, $config, self::TTL_DEF_QUERY_COLUMN);
        log_message('debug', '[MetadataCache] getPopupConfigByObject 缓存写入: ' . $objectName);

        return $config;
    }

    /**
     * 获取 def_chart_drill_config 表的钻取配置
     *
     * @param string $drillModule 钻取模块
     * @return array 钻取配置数组
     */
    public function getChartDrillConfig(string $drillModule): array
    {
        $cacheKey = self::CACHE_PREFIX . 'chart_drill_' . md5($drillModule);
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            log_message('debug', '[MetadataCache] getChartDrillConfig 缓存命中: ' . $drillModule);
            return $cached;
        }

        $sql = sprintf(
            'select 图形模块, 钻取模块, 钻取选项, 目标图形, 钻取条件, 显示字段
            from def_chart_drill_config
            where 钻取模块=%s',
            $this->model->quote($drillModule)
        );

        $result = $this->model->select($sql);
        if ($result === false) {
            return [];
        }

        $config = $result->getResultArray();
        $this->cache->save($cacheKey, $config, self::TTL_DEF_CHART_DRILL_CONFIG);
        log_message('debug', '[MetadataCache] getChartDrillConfig 缓存写入: ' . $drillModule . ', ' . count($config) . ' 条');

        return $config;
    }

    /**
     * 获取 def_query_config 表的查询配置
     *
     * @param string $functionCode 功能编码
     * @return array|null 查询配置
     */
    public function getQueryConfig(string $functionCode): ?array
    {
        $cacheKey = self::CACHE_PREFIX . 'query_config_' . md5($functionCode);
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            log_message('debug', '[MetadataCache] getQueryConfig 缓存命中: ' . $functionCode);
            return $cached;
        }

        $sql = sprintf(
            'select * from def_query_config where 功能编码=%s',
            $this->model->quote($functionCode)
        );

        $result = $this->model->select($sql);
        if ($result === false) {
            return null;
        }

        $config = $result->getRowArray();
        if ($config !== null) {
            $this->cache->save($cacheKey, $config, self::TTL_DEF_QUERY_CONFIG);
            log_message('debug', '[MetadataCache] getQueryConfig 缓存写入: ' . $functionCode);
        }

        return $config;
    }

    /**
     * 获取 def_user 表的用户信息
     *
     * @param string $workId 工号
     * @param string $region 属地
     * @return array|null 用户信息
     */
    public function getUserInfo(string $workId, string $region): ?array
    {
        $cacheKey = self::CACHE_PREFIX . 'user_info_' . md5($workId . $region);
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            log_message('debug', '[MetadataCache] getUserInfo 缓存命中: ' . $workId);
            return $cached;
        }

        $sql = sprintf(
            'select 员工编号,工号,姓名,员工属地,员工部门编码,员工部门全称,日志标识,调试赋权,维护赋权
            from def_user
            where 有效标识="1" and 员工属地=%s and 工号=%s',
            $this->model->quote($region),
            $this->model->quote($workId)
        );

        $result = $this->model->select($sql);
        if ($result === false) {
            return null;
        }

        $user = $result->getRowArray();
        if ($user !== null) {
            $this->cache->save($cacheKey, $user, self::TTL_DEF_USER);
            log_message('debug', '[MetadataCache] getUserInfo 缓存写入: ' . $workId);
        }

        return $user;
    }

    /**
     * 手动清除指定表的缓存（触发事件通知）
     *
     * @param string $tableName 表名（def_query_column, def_chart_drill_config, def_query_config, def_user）
     */
    public function invalidateTable(string $tableName): void
    {
        $tableName = strtolower(trim($tableName));

        switch ($tableName) {
            case 'def_query_column':
                $this->cache->delete(self::CACHE_PREFIX . 'popup_column_map');
                $this->deleteKeysByPattern(self::CACHE_PREFIX . 'popup_config_');
                log_message('info', '[MetadataCache] def_query_column 缓存已失效');
                break;

            case 'def_chart_drill_config':
                $this->deleteKeysByPattern(self::CACHE_PREFIX . 'chart_drill_');
                log_message('info', '[MetadataCache] def_chart_drill_config 缓存已失效');
                break;

            case 'def_query_config':
                $this->deleteKeysByPattern(self::CACHE_PREFIX . 'query_config_');
                log_message('info', '[MetadataCache] def_query_config 缓存已失效');
                break;

            case 'def_user':
                $this->deleteKeysByPattern(self::CACHE_PREFIX . 'user_info_');
                log_message('info', '[MetadataCache] def_user 缓存已失效');
                break;

            default:
                log_message('warning', '[MetadataCache] 无效的表名: ' . $tableName);
                return;
        }

        $this->triggerInvalidate($tableName);
    }

    /**
     * 清除所有元数据缓存（触发事件通知）
     */
    public function invalidateAll(): void
    {
        $this->deleteKeysByPattern(self::CACHE_PREFIX);
        log_message('info', '[MetadataCache] 所有元数据缓存已失效');
        $this->triggerInvalidate('all');
    }

    /**
     * 通过 Webhook 触发缓存失效（适用于外部系统通知）
     *
     * @param string $secret 验证密钥
     * @param string $tableName 表名
     * @return bool
     */
    public function triggerWebhook(string $secret, string $tableName): bool
    {
        $configSecret = env('cache.webhook_secret', '');
        if ($configSecret === '' || $secret !== $configSecret) {
            log_message('warning', '[MetadataCache] Webhook 验证失败');
            return false;
        }

        $this->invalidateTable($tableName);
        return true;
    }

    /**
     * 按模式删除缓存键
     */
    private function deleteKeysByPattern(string $pattern): void
    {
        try {
            $handler = $this->cache->handler();
            if (method_exists($handler, 'deleteMatching')) {
                $handler->deleteMatching($pattern . '*');
            } elseif (method_exists($handler, 'deleteAll')) {
                $handler->deleteAll();
            }
        } catch (\Throwable $e) {
            log_message('error', '[MetadataCache] deleteKeysByPattern 失败: ' . $e->getMessage());
        }
    }
}
