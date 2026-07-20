<?php

namespace App\Libraries;

use App\Models\Mcommon;
use CodeIgniter\Cache\CacheInterface;
use Config\Services;

class MetadataCache
{
    private const CACHE_PREFIX = 'metadata_';
    private const INDEX_PREFIX = 'metadata_index_';

    private const TTL_DEF_QUERY_COLUMN = 3600;
    private const TTL_DEF_CHART_DRILL_CONFIG = 3600;
    private const TTL_DEF_QUERY_CONFIG = 7200;
    private const TTL_DEF_USER = 1800;
    private const TTL_DEF_FUNCTION = 7200;
    private const TTL_VIEW_FUNCTION = 3600;
    // 索引 TTL 远大于数据 TTL，避免索引过早失效导致清理时漏删
    // 索引中残留已过期的数据键不影响正确性（delete 时 cache->get 返回 false）
    private const INDEX_TTL_SECONDS = 86400;

    /**
     * 表名 → 该表对应的 cacheKey 前缀列表
     *
     * 用于 invalidateTable 时反查需要清理的索引归属。
     * 注意：popup_column_map 是无 hash 的固定键，单独列出。
     * 扩展覆盖全部 16 张配置表 + view_function，与 RecordEditService/BatchEditService 的监控清单对齐。
     */
    private const TABLE_KEY_PREFIXES = [
        'def_query_column'        => ['metadata_popup_column_map', 'metadata_popup_config_'],
        'def_chart_drill_config'  => ['metadata_chart_drill_'],
        'def_query_config'        => ['metadata_query_config_', 'metadata_query_config_by_fn_', 'metadata_primary_key_'],
        'def_user'                => ['metadata_user_info_', 'metadata_user_auth_'],
        'def_function'            => ['metadata_function_', 'metadata_function_by_module_', 'metadata_query_config_by_fn_', 'metadata_primary_key_'],
        'view_function'           => ['metadata_view_function_'],
        // 以下表原先无对应缓存键前缀，现补充空数组以使 invalidateTable 不再拒绝它们
        // （这些表的数据通过 ContextCacheService 的上下文缓存间接缓存，主动失效时由 ContextService.clearCache 联动处理）
        'def_chart_config'        => [],
        'def_chart_chart_column'  => [],
        'def_role_group'          => [],
        'def_role'                => [],
        'def_function_group'      => [],
        'def_drill_config'        => [],
        'def_import_config'       => [],
        'def_import_column'       => [],
        'def_comment_config'      => [],
        'def_object'              => [],
        'def_match_config'        => [],
        // def_config_table 自身：用于配置表清单维护，需联动清除指纹监控清单缓存
        'def_config_table'        => [],
    ];

    private CacheInterface $cache;
    private Mcommon $model;
    private array $listeners = [];
    private ?ConfigTableFingerprint $fingerprintService = null;

    public function __construct()
    {
        $this->cache = Services::cache();
        $this->model = new Mcommon();
    }

    /**
     * 获取指纹服务（懒加载）
     */
    private function getFingerprintService(): ConfigTableFingerprint
    {
        return $this->fingerprintService ??= new ConfigTableFingerprint();
    }

    /**
     * 带指纹校验的缓存读取
     *
     * 方案 C：先读缓存（同时拿到数据和指纹），再校验指纹是否与当前表一致。
     * - 缓存未命中 → 返回 null（由调用方查 DB 重建）
     * - 缓存命中但指纹不一致 → 删除旧缓存返回 null（触发重建）
     * - 缓存命中且指纹一致 → 返回数据
     *
     * @param string $cacheKey   缓存键
     * @param string $tableName  关联的配置表名（用于指纹校验）
     * @return array|null 命中且指纹一致返回数据数组，否则返回 null
     */
    private function getWithFingerprint(string $cacheKey, string $tableName): ?array
    {
        $cached = $this->cache->get($cacheKey);
        if (!is_array($cached) || !isset($cached['__data'])) {
            return null;
        }

        // 缓存命中，校验指纹
        $cachedFp = (string) ($cached['__fp'] ?? '');
        if (!$this->getFingerprintService()->isValid($tableName, $cachedFp)) {
            log_message('debug', sprintf(
                '[MetadataCache] 指纹校验失败，缓存失效: table=%s, key=%s',
                $tableName,
                $cacheKey
            ));
            $this->cache->delete($cacheKey);
            return null;
        }

        return $cached['__data'];
    }

    /**
     * 带指纹的缓存写入
     *
     * 将数据和表指纹一起打包存储，供读取时校验。
     *
     * @param string $cacheKey   缓存键
     * @param array  $data       业务数据
     * @param string $tableName  关联的配置表名
     * @param int    $ttl        缓存 TTL（秒）
     */
    private function saveWithFingerprint(string $cacheKey, array $data, string $tableName, int $ttl): void
    {
        $fingerprint = $this->getFingerprintService()->getFingerprint($tableName);

        $this->cache->save($cacheKey, [
            '__data' => $data,
            '__fp' => $fingerprint,
            '__fpTable' => $tableName,
            '__cachedAt' => time(),
        ], $ttl);

        $this->addToIndex($tableName, $cacheKey);
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
        $cached = $this->getWithFingerprint($cacheKey, 'def_query_column');
        if ($cached !== null) {
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

        $this->saveWithFingerprint($cacheKey, $map, 'def_query_column', self::TTL_DEF_QUERY_COLUMN);
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
        $cached = $this->getWithFingerprint($cacheKey, 'def_query_column');
        if ($cached !== null) {
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

        $this->saveWithFingerprint($cacheKey, $config, 'def_query_column', self::TTL_DEF_QUERY_COLUMN);
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
        $cached = $this->getWithFingerprint($cacheKey, 'def_chart_drill_config');
        if ($cached !== null) {
            log_message('debug', '[MetadataCache] getChartDrillConfig 缓存命中: ' . $drillModule);
            return $cached;
        }

        $sql = sprintf(
            'select 图形模块, 钻取模块, 钻取选项, 钻取字段, 钻取条件
            from def_chart_drill_config
            where 钻取模块=%s',
            $this->model->quote($drillModule)
        );

        $result = $this->model->select($sql);
        if ($result === false) {
            return [];
        }

        $config = $result->getResultArray();
        $this->saveWithFingerprint($cacheKey, $config, 'def_chart_drill_config', self::TTL_DEF_CHART_DRILL_CONFIG);
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
        $cached = $this->getWithFingerprint($cacheKey, 'def_query_config');
        if ($cached !== null) {
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
            $this->saveWithFingerprint($cacheKey, $config, 'def_query_config', self::TTL_DEF_QUERY_CONFIG);
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
        $cached = $this->getWithFingerprint($cacheKey, 'def_user');
        if ($cached !== null) {
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
            $this->saveWithFingerprint($cacheKey, $user, 'def_user', self::TTL_DEF_USER);
            log_message('debug', '[MetadataCache] getUserInfo 缓存写入: ' . $workId);
        }

        return $user;
    }

    /**
     * 获取用户完整授权信息（含角色组合并，对齐 ContextService.loadUserAuthorization 的 SQL）
     *
     * 与 getUserInfo 的差异：
     * - LEFT JOIN def_role_group 合并角色编码（t1+t2 CASE WHEN 三重逻辑）
     * - 包含赋权字段：属地赋权、部门编码赋权、部门全称赋权、工号限权
     * - CSV 清洗：replace(replace(...,"，",",")," ","")
     *
     * 缓存键：user_auth_{md5(workId+region)}，独立于 getUserInfo 的 user_info_* 键。
     *
     * @param string $workId  工号
     * @param string $region  属地（companyId）
     * @return array|null     单行授权信息，字段对齐 ContextService 原 SQL
     */
    public function getUserAuthorization(string $workId, string $region): ?array
    {
        $cacheKey = self::CACHE_PREFIX . 'user_auth_' . md5($workId . $region);
        $cached = $this->getWithFingerprint($cacheKey, 'def_user');
        if ($cached !== null) {
            log_message('debug', '[MetadataCache] getUserAuthorization 缓存命中: ' . $workId);
            return $cached;
        }

        $sql = sprintf(
            'select
                员工编号,姓名,工号,t1.角色组,
                case
                    when t1.角色组!="" and t1.角色编码="" and t2.角色组 is not null then t2.角色编码
                    when t1.角色组!="" and t1.角色编码!="" and t2.角色组 is not null then concat(t2.角色编码,",",t1.角色编码)
                    else t1.角色编码
                end as 角色编码,
                属地赋权,部门编码赋权,部门全称赋权,
                工号限权,调试赋权,维护赋权,
                员工属地,员工部门编码,员工部门全称
            from
            (
                select
                    员工编号,姓名,工号,
                    角色组,replace(replace(角色编码,"，",",")," ","") as 角色编码,
                    replace(replace(属地赋权,"，",",")," ","") as 属地赋权,
                    replace(replace(部门编码赋权,"，",",")," ","") as 部门编码赋权,
                    replace(replace(部门全称赋权,"，",",")," ","") as 部门全称赋权,
                    工号限权,调试赋权,维护赋权,
                    员工属地,员工部门编码,员工部门全称
                from def_user
                where 有效标识="1" and 员工属地=%s and 工号=%s
                group by 员工属地,工号
            ) as t1
            left join
            (
                select 角色组,replace(replace(角色编码,"，",",")," ","") as 角色编码
                from def_role_group
                where 有效标识="1"
            ) as t2 on t1.角色组=t2.角色组',
            $this->model->quote($region),
            $this->model->quote($workId)
        );

        $result = $this->model->select($sql);
        if ($result === false) {
            return null;
        }

        $row = $result->getRowArray();
        if ($row !== null) {
            $this->saveWithFingerprint($cacheKey, $row, 'def_user', self::TTL_DEF_USER);
            log_message('debug', '[MetadataCache] getUserAuthorization 缓存写入: ' . $workId);
        }

        return $row;
    }

    /**
     * 通过 def_function 关联获取 def_query_config 配置（对齐 AuthorizationService.loadQueryConfig 的 SQL）
     *
     * 与 getQueryConfig 的差异：
     * - 查询条件：查询模块 in (select 模块名称 from def_function where 功能编码=X)
     *   原因：def_query_config 的 功能编码 字段可能为空或不匹配，需通过 def_function 中转
     * - 字段范围：21 个明确字段（非 select *）
     *
     * 缓存键：query_config_by_fn_{md5(functionCode)}，独立于 getQueryConfig 的 query_config_* 键。
     *
     * @param string $functionCode 功能编码
     * @return array|null 单行配置（原始字段，未做 $角色 替换）
     */
    public function getQueryConfigByFunction(string $functionCode): ?array
    {
        $cacheKey = self::CACHE_PREFIX . 'query_config_by_fn_' . md5($functionCode);
        $cached = $this->getWithFingerprint($cacheKey, 'def_query_config');
        if ($cached !== null) {
            log_message('debug', '[MetadataCache] getQueryConfigByFunction 缓存命中: ' . $functionCode);
            return $cached;
        }

        $sql = sprintf(
            'select
                查询模块,模块类型,字段模块,钻取模块,
                查询表名,数据表名,数据模式,
                查询条件,汇总条件,排序条件,初始条数,
                新增前处理模块,新增后处理模块,
                更新前处理模块,更新后处理模块,
                数据整理模块,备注模块,导入模块,图形模块,表样式,主键字段,显示序号
            from def_query_config
            where 查询模块 in
                (
                    select 模块名称
                    from def_function
                    where 有效标识="1" and 功能编码=%s
                )',
            $this->model->quote($functionCode)
        );

        $result = $this->model->select($sql);
        if ($result === false) {
            return null;
        }

        $row = $result->getRowArray();
        if ($row !== null) {
            $this->saveWithFingerprint($cacheKey, $row, 'def_query_config', self::TTL_DEF_QUERY_CONFIG);
            log_message('debug', '[MetadataCache] getQueryConfigByFunction 缓存写入: ' . $functionCode);
        }

        return $row;
    }

    /**
     * 解析功能对应数据表的主键字段（跨用户共享缓存）
     *
     * 解析顺序：
     * 1. 命中缓存 → 直接返回
     * 2. 查 def_query_config.主键字段（通过 def_function 关联）
     * 3. 回退 SHOW INDEX FROM {dataTable} WHERE Key_name='PRIMARY'
     *
     * 与原 ContextService.resolvePrimaryKey / WorkbenchResponseTrait.getPrimaryKey
     * 逻辑完全一致，但缓存从 session 迁移到 MetadataCache（TTL 86400s），
     * 跨用户共享，消除每个新用户首次访问触发的 SHOW INDEX 调用。
     *
     * 复合主键以 ";" 分隔（如 "工号;姓名"）。
     *
     * @param string $functionCode 功能编码
     * @param string $dataTable    数据表名（用于 SHOW INDEX 回退）
     * @return string 主键字段名（空字符串表示未识别）
     */
    public function getPrimaryKey(string $functionCode, string $dataTable = ''): string
    {
        $cacheKey = self::CACHE_PREFIX . 'primary_key_' . md5($functionCode);
        $cached = $this->cache->get($cacheKey);
        // getPrimaryKey 返回字符串，无法用 getWithFingerprint（要求数组）
        // 这里采用内联指纹校验：缓存格式为 ['__data' => pk, '__fp' => fingerprint]
        if (is_array($cached) && isset($cached['__data']) && is_string($cached['__data']) && $cached['__data'] !== '') {
            $cachedFp = (string) ($cached['__fp'] ?? '');
            if ($this->getFingerprintService()->isValid('def_query_config', $cachedFp)) {
                log_message('debug', '[MetadataCache] getPrimaryKey 缓存命中: ' . $functionCode);
                return $cached['__data'];
            }
            log_message('debug', '[MetadataCache] getPrimaryKey 指纹校验失败，重建: ' . $functionCode);
            $this->cache->delete($cacheKey);
        }

        // 1. 优先查 def_query_config.主键字段
        $sql = sprintf(
            'SELECT t1.主键字段 FROM def_query_config t1
            INNER JOIN def_function t2 ON t2.模块名称 = t1.查询模块
            WHERE t2.功能编码 = %s',
            $this->model->quote($functionCode)
        );
        $result = $this->model->select($sql);
        if ($result !== false && ($row = $result->getRowArray()) && !empty($row['主键字段'])) {
            $primaryKey = (string) $row['主键字段'];
            $this->savePrimaryKeyWithFingerprint($cacheKey, $primaryKey);
            log_message('debug', '[MetadataCache] getPrimaryKey 缓存写入(def_query_config): ' . $functionCode);
            return $primaryKey;
        }

        // 2. 回退 SHOW INDEX FROM
        if (empty($dataTable)) {
            return '';
        }
        $sql = sprintf('SHOW INDEX FROM %s WHERE Key_name = "PRIMARY"', $dataTable);
        $result = $this->model->select($sql);
        if ($result !== false && ($row = $result->getRowArray())) {
            $primaryKey = (string) ($row['Column_name'] ?? '');
            if ($primaryKey !== '') {
                $this->savePrimaryKeyWithFingerprint($cacheKey, $primaryKey);
                log_message('debug', '[MetadataCache] getPrimaryKey 缓存写入(SHOW INDEX): ' . $functionCode);
            }
            return $primaryKey;
        }

        return '';
    }

    /**
     * 保存主键缓存（字符串值）并附带表指纹
     *
     * getPrimaryKey 返回类型为 string，无法直接用 saveWithFingerprint（要求数组），
     * 这里单独封装字符串版本的指纹写入。
     */
    private function savePrimaryKeyWithFingerprint(string $cacheKey, string $primaryKey): void
    {
        $fingerprint = $this->getFingerprintService()->getFingerprint('def_query_config');

        $this->cache->save($cacheKey, [
            '__data' => $primaryKey,
            '__fp' => $fingerprint,
            '__fpTable' => 'def_query_config',
            '__cachedAt' => time(),
        ], self::INDEX_TTL_SECONDS);

        $this->addToIndex('def_query_config', $cacheKey);
    }

    /**
     * 获取 def_function 单行配置（跨用户共享缓存）
     *
     * 缓存 def_function 表中按功能编码查询的完整行数据。
     * 各服务通过此方法统一读取，避免多处直接 SQL 查询 def_function。
     *
     * @param string $functionCode 功能编码
     * @return array|null 单行配置（原始字段）
     */
    public function getFunctionConfig(string $functionCode): ?array
    {
        $cacheKey = self::CACHE_PREFIX . 'function_' . md5($functionCode);
        $cached = $this->getWithFingerprint($cacheKey, 'def_function');
        if ($cached !== null) {
            log_message('debug', '[MetadataCache] getFunctionConfig 缓存命中: ' . $functionCode);
            return $cached;
        }

        $sql = sprintf(
            'select * from def_function where 有效标识="1" and 功能编码=%s limit 1',
            $this->model->quote($functionCode)
        );

        $result = $this->model->select($sql);
        if ($result === false) {
            return null;
        }

        $row = $result->getRowArray();
        if ($row !== null) {
            $this->saveWithFingerprint($cacheKey, $row, 'def_function', self::TTL_DEF_FUNCTION);
            log_message('debug', '[MetadataCache] getFunctionConfig 缓存写入: ' . $functionCode);
        }

        return $row;
    }

    /**
     * 通过模块名称反查 def_function 配置（跨用户共享缓存）
     *
     * 用于 MatchApi 等需要通过 模块名称 反查 功能编码 的场景。
     *
     * @param string $moduleName 模块名称
     * @return array|null 单行配置（含 功能编码、模块名称 等全字段）
     */
    public function getFunctionConfigByModule(string $moduleName): ?array
    {
        $cacheKey = self::CACHE_PREFIX . 'function_by_module_' . md5($moduleName);
        $cached = $this->getWithFingerprint($cacheKey, 'def_function');
        if ($cached !== null) {
            log_message('debug', '[MetadataCache] getFunctionConfigByModule 缓存命中: ' . $moduleName);
            return $cached;
        }

        $sql = sprintf(
            'select * from def_function where 有效标识="1" and 模块名称=%s order by 功能编码 limit 1',
            $this->model->quote($moduleName)
        );

        $result = $this->model->select($sql);
        if ($result === false) {
            return null;
        }

        $row = $result->getRowArray();
        if ($row !== null) {
            $this->saveWithFingerprint($cacheKey, $row, 'def_function', self::TTL_DEF_FUNCTION);
            log_message('debug', '[MetadataCache] getFunctionConfigByModule 缓存写入: ' . $moduleName);
        }

        return $row;
    }

    /**
     * 获取 view_function 列定义（跨用户共享缓存）
     *
     * 缓存 view_function 视图中按功能编码查询的全量列定义（所有字段、列顺序>0、group by 列名）。
     * 各服务在 PHP 端从返回结果中筛选所需字段，避免多处独立查询同一视图。
     *
     * @param string $functionCode 功能编码
     * @return array 列定义数组（每行为 view_function 的完整字段）
     */
    public function getViewFunctionColumns(string $functionCode): array
    {
        $cacheKey = self::CACHE_PREFIX . 'view_function_' . md5($functionCode);
        $cached = $this->getWithFingerprint($cacheKey, 'view_function');
        if ($cached !== null) {
            log_message('debug', '[MetadataCache] getViewFunctionColumns 缓存命中: ' . $functionCode);
            return $cached;
        }

        $sql = sprintf(
            'select 功能编码,字段模块,部门编码字段,部门全称字段,
                工号字段,属地字段,
                列名,列类型,列宽度,字段名,查询名,
                赋值类型,对象,对象名称,对象表名,缺省值,主键,
                工号限权,可筛选,可汇总,可新增,可修改,不可为空,可颜色标注,
                提示条件,提示样式设置,异常条件,异常样式设置,字符转换,
                加密显示,列顺序,可匹配,可行合并
            from view_function
            where 功能编码=%s and 列顺序>0
            group by 列名
            order by 列顺序',
            $this->model->quote($functionCode)
        );

        $result = $this->model->select($sql);
        $rows = ($result !== false) ? $result->getResultArray() : [];

        $this->saveWithFingerprint($cacheKey, $rows, 'view_function', self::TTL_VIEW_FUNCTION);
        log_message('debug', '[MetadataCache] getViewFunctionColumns 缓存写入: ' . $functionCode . ' (' . count($rows) . ' rows)');

        return $rows;
    }

    /**
     * 手动清除指定表的缓存（触发事件通知）
     *
     * 优先通过反向索引精准删除该表对应的所有 cacheKey；
     * 索引缺失时尝试 deleteMatching（仅 Redis/Memcached 支持）；
     * FileHandler 不支持 deleteMatching 时仅记录 warning，不再退化为 deleteAll 全清，
     * 避免单表变更引发全站冷启动（对齐 ContextCacheService 的设计）。
     *
     * @param string $tableName 表名（def_query_column, def_chart_drill_config, def_query_config, def_user）
     */
    public function invalidateTable(string $tableName): void
    {
        $tableName = strtolower(trim($tableName));

        if (!isset(self::TABLE_KEY_PREFIXES[$tableName])) {
            log_message('warning', '[MetadataCache] 无效的表名: ' . $tableName);
            return;
        }

        $deletedCount = $this->deleteByTableIndex($tableName);

        // 固定键（popup_column_map 无 hash，不在索引中，需单独删除）
        if ($tableName === 'def_query_column') {
            $this->cache->delete(self::CACHE_PREFIX . 'popup_column_map');
        }

        // 联动清除该表的指纹缓存（避免下次读取时指纹仍为旧值导致校验通过错误缓存）
        $this->getFingerprintService()->invalidate($tableName);

        // def_config_table 被修改时，同步清除监控表清单缓存
        // （下次访问时从 DB 重新读取最新的监控表清单）
        if ($tableName === 'def_config_table') {
            $this->getFingerprintService()->invalidateMonitoredTablesCache();
            log_message('info', '[MetadataCache] def_config_table 变更，已联动清除监控表清单缓存');
        }

        log_message('info', sprintf(
            '[MetadataCache] %s 缓存已失效，删除 %d 条',
            $tableName,
            $deletedCount
        ));

        $this->triggerInvalidate($tableName);
    }

    /**
     * 清除所有元数据缓存（触发事件通知）
     *
     * 全量清理场景可接受 clean()：用户主动触发，非单表变更的副作用。
     */
    public function invalidateAll(): void
    {
        $this->cache->clean();
        // 全量清理时同步清除所有指纹缓存，避免下次读取命中旧指纹
        $this->getFingerprintService()->invalidateAll();
        log_message('info', '[MetadataCache] 所有元数据缓存已失效（全量清理）');
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
     * 将 cacheKey 登记到对应表的反向索引
     *
     * 索引键格式：metadata_index_{tableName}
     * 索引值为该表下所有 cacheKey 的数组（去重）。
     */
    private function addToIndex(string $tableName, string $cacheKey): void
    {
        $indexKey = self::INDEX_PREFIX . $tableName;
        $keys = $this->cache->get($indexKey);
        $keys = is_array($keys) ? $keys : [];

        if (!in_array($cacheKey, $keys, true)) {
            $keys[] = $cacheKey;
            $this->cache->save($indexKey, $keys, self::INDEX_TTL_SECONDS);
        }
    }

    /**
     * 按表索引精准删除该表对应的所有 cacheKey
     *
     * 优先走反向索引逐条 delete；
     * 索引缺失时尝试 deleteMatching（仅 Redis/Memcached 支持）；
     * FileHandler 不支持 deleteMatching 时仅 warning，不退化为 deleteAll。
     *
     * @return int 实际删除的缓存条数（索引命中时统计；索引缺失走 deleteMatching 时返回 0）
     */
    private function deleteByTableIndex(string $tableName): int
    {
        $indexKey = self::INDEX_PREFIX . $tableName;
        $keys = $this->cache->get($indexKey);

        if (is_array($keys) && !empty($keys)) {
            $count = 0;
            foreach ($keys as $key) {
                if ($this->cache->delete($key)) {
                    $count++;
                }
            }
            $this->cache->delete($indexKey);
            return $count;
        }

        // 索引缺失兜底：尝试 deleteMatching（仅 Redis/Memcached 有效）
        $prefixes = self::TABLE_KEY_PREFIXES[$tableName] ?? [];
        try {
            $handler = $this->cache->handler();
            if (method_exists($handler, 'deleteMatching')) {
                foreach ($prefixes as $prefix) {
                    $handler->deleteMatching($prefix . '*');
                }
                log_message('info', sprintf(
                    '[MetadataCache] 索引缺失，已通过 deleteMatching 清除: table=%s',
                    $tableName
                ));
            } else {
                log_message('warning', sprintf(
                    '[MetadataCache] 索引缺失且驱动不支持 deleteMatching，本次无键可删（避免全清）: table=%s',
                    $tableName
                ));
            }
        } catch (\Throwable $e) {
            log_message('error', '[MetadataCache] deleteMatching 失败: ' . $e->getMessage());
        }

        return 0;
    }
}
