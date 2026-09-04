<?php

namespace App\Libraries;

use App\Models\Mcommon;
use CodeIgniter\Cache\CacheInterface;
use Config\Services;

/**
 * 配置表指纹服务（方案 C：惰性校验）
 *
 * 表指纹由两部分组成："{UPDATE_TIME}:{CHECKSUM}"
 *  - UPDATE_TIME（information_schema.TABLES）：只随 DDL 变化，捕获结构变更
 *  - CHECKSUM（CHECKSUM TABLE 语句）：随数据内容变化，捕获 DML 修改
 *
 * 必须组合 CHECKSUM 的原因：本库（MySQL 8）的 UPDATE_TIME 不随 DML 更新，
 * 仅靠 UPDATE_TIME 无法感知配置数据的增删改（含绕过应用层的直接 SQL 修改）。
 *
 * 视图（如 view_function）的 UPDATE_TIME 恒为 NULL、CHECKSUM 返回 NULL，
 * 无法直接指纹，调用方应改为指纹其基表。
 *
 * 工作流程：
 *  1. 缓存写入时：调用 getFingerprint(s) 获取当前指纹，随数据一起存入缓存
 *  2. 缓存读取时：再查一次当前指纹，与缓存中的指纹比对
 *     - 相同 → 返回缓存（省去完整 SQL 查询）
 *     - 不同 → 失效缓存，查 DB 重建
 *
 * 性能特征：
 *  - 批量指纹 = 1 次 information_schema 查询 + 1 次多表 CHECKSUM 语句（全表扫描）
 *  - 配置表均为小表（最大 def_query_column 约 3 千行，CHECKSUM 约 24ms）
 *  - 指纹结果在进程内缓存 10 秒 + 缓存驱动缓存 10 秒，摊薄扫描成本
 */
class ConfigTableFingerprint
{
    /** 指纹缓存键前缀（独立于业务缓存，避免被 invalidateTable 误删） */
    private const FP_CACHE_PREFIX = 'config_fp_';
    private const FP_CACHE_TTL = 10;

    /** 监控表清单缓存键（独立键，避免与单表指纹缓存混淆） */
    private const MONITORED_TABLES_CACHE_KEY = 'config_fp_monitored_tables';
    private const MONITORED_TABLES_CACHE_TTL = 3600;

    /** 进程内指纹缓存（避免同一请求内重复查 information_schema） */
    private static array $localCache = [];

    /** 进程内监控表清单缓存 */
    private static ?array $monitoredTablesCache = null;

    /** 默认监控清单（def_config_table 表不可用时的兜底） */
    public const DEFAULT_MONITORED_TABLES = [
        'def_query_column',
        'def_query_config',
        'def_function',
        'def_user',
        'def_chart_config',
        'def_chart_chart_column',
        'def_chart_drill_config',
        'def_role_group',
        'def_role',
        'def_function_group',
        'def_drill_config',
        'def_import_config',
        'def_import_column',
        'def_comment_config',
        'def_object',
        'def_match_config',
        // 视图也纳入监控（view_function 依赖 def_query_column + def_function）
        'view_function',
    ];

    /** 向后兼容：保留 MONITORED_TABLES 常量，指向默认清单 */
    public const MONITORED_TABLES = self::DEFAULT_MONITORED_TABLES;

    private CacheInterface $cache;
    private Mcommon $model;
    private ?string $databaseName = null;

    public function __construct()
    {
        $this->cache = Services::cache();
        $this->model = new Mcommon();
    }

    /**
     * 获取被监控的配置表清单（从 def_config_table 表读取，带指纹校验）
     *
     * def_config_table 自身也纳入指纹监控，确保绕过应用层的直接 SQL 修改
     * （如 DBA 手工增删记录、改有效标识）能被准实时感知。
     *
     * 优先级：
     *  1. 进程内缓存（同一请求内复用，不校验指纹——PHP-FPM 单请求内表不会变）
     *  2. 缓存驱动（3600s TTL，读取时校验 def_config_table 指纹）
     *  3. 查 def_config_table WHERE 有效标识="1"
     *  4. 查询失败回退到 DEFAULT_MONITORED_TABLES
     *
     * @return array 表名列表（已小写化、去空白）
     */
    public function getMonitoredTables(): array
    {
        // 1. 进程内缓存（同请求内不校验指纹，因为 PHP-FPM 单请求内 def_config_table 不会变）
        if (self::$monitoredTablesCache !== null) {
            return self::$monitoredTablesCache;
        }

        // 2. 缓存驱动（带 def_config_table 指纹校验）
        $cached = $this->cache->get(self::MONITORED_TABLES_CACHE_KEY);
        if (is_array($cached) && isset($cached['tables']) && !empty($cached['tables'])) {
            $cachedFp = (string) ($cached['fp'] ?? '');
            $currentFp = $this->getFingerprint('def_config_table');
            if ($cachedFp !== '' && $cachedFp === $currentFp) {
                self::$monitoredTablesCache = $cached['tables'];
                return $cached['tables'];
            }
            // 指纹不一致，说明 def_config_table 已被修改，继续重新读取
            log_message('debug', '[ConfigTableFingerprint] def_config_table 指纹变更，重新读取监控清单');
        }

        // 3. 查 def_config_table
        $tables = $this->queryMonitoredTablesFromDB();

        // 4. 查询失败回退
        if (empty($tables)) {
            log_message('warning', '[ConfigTableFingerprint] def_config_table 查询失败或为空，回退到默认清单');
            $tables = self::DEFAULT_MONITORED_TABLES;
        }

        // 写入缓存（含 def_config_table 的当前指纹，供下次读取校验）
        $currentFp = $this->getFingerprint('def_config_table');
        $this->cache->save(self::MONITORED_TABLES_CACHE_KEY, [
            'tables' => $tables,
            'fp' => $currentFp,
        ], self::MONITORED_TABLES_CACHE_TTL);
        self::$monitoredTablesCache = $tables;

        return $tables;
    }

    /**
     * 从 def_config_table 表读取监控表清单
     *
     * @return array 表名列表（小写），查询失败返回空数组
     */
    private function queryMonitoredTablesFromDB(): array
    {
        try {
            $sql = 'SELECT 表名 FROM def_config_table WHERE 有效标识="1"';
            $result = $this->model->select($sql);
            if ($result === false) {
                return [];
            }

            $tables = [];
            foreach ($result->getResultArray() as $row) {
                $name = strtolower(trim((string) ($row['表名'] ?? '')));
                if ($name !== '') {
                    $tables[] = $name;
                }
            }
            return $tables;
        } catch (\Throwable $e) {
            log_message('error', '[ConfigTableFingerprint] 读取 def_config_table 失败: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 清除监控表清单缓存（def_config_table 被修改后调用）
     */
    public function invalidateMonitoredTablesCache(): void
    {
        $this->cache->delete(self::MONITORED_TABLES_CACHE_KEY);
        self::$monitoredTablesCache = null;
    }

    /**
     * 获取表的当前指纹
     *
     * 优先级：
     *  1. 进程内缓存（60 秒有效期，同一请求内复用）
     *  2. 缓存驱动（60 秒有效期，跨请求复用）
     *  3. 查 information_schema.TABLES.UPDATE_TIME
     *
     * @param string $tableName 表名
     * @return string 指纹值（UPDATE_TIME 的字符串形式，未找到返回空字符串）
     */
    public function getFingerprint(string $tableName): string
    {
        $tableName = strtolower(trim($tableName));
        if ($tableName === '') {
            return '';
        }

        // 1. 进程内缓存
        if (isset(self::$localCache[$tableName])) {
            return self::$localCache[$tableName];
        }

        // 2. 缓存驱动
        $cacheKey = self::FP_CACHE_PREFIX . $tableName;
        $cached = $this->cache->get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            self::$localCache[$tableName] = $cached;
            return $cached;
        }

        // 3. 查 information_schema
        $fp = $this->queryFingerprintFromDB($tableName);

        // 写入缓存（即使为空也写，避免频繁查 information_schema）
        $this->cache->save($cacheKey, $fp, self::FP_CACHE_TTL);
        self::$localCache[$tableName] = $fp;

        return $fp;
    }

    /**
     * 批量获取多张表的指纹（一次 information_schema 查询 + 一次多表 CHECKSUM 语句）
     *
     * @param array $tableNames 表名列表
     * @return array [tableName => fingerprint] 映射
     */
    public function getFingerprints(array $tableNames): array
    {
        $result = [];
        $needQuery = [];

        foreach ($tableNames as $name) {
            $name = strtolower(trim($name));
            if ($name === '') {
                continue;
            }
            // 进程内缓存
            if (isset(self::$localCache[$name])) {
                $result[$name] = self::$localCache[$name];
                continue;
            }
            // 缓存驱动
            $cached = $this->cache->get(self::FP_CACHE_PREFIX . $name);
            if (is_string($cached) && $cached !== '') {
                self::$localCache[$name] = $cached;
                $result[$name] = $cached;
                continue;
            }
            $needQuery[] = $name;
        }

        if (empty($needQuery)) {
            return $result;
        }

        // 批量查 information_schema
        $fps = $this->queryFingerprintsFromDB($needQuery);
        foreach ($needQuery as $name) {
            $fp = $fps[$name] ?? '';
            $this->cache->save(self::FP_CACHE_PREFIX . $name, $fp, self::FP_CACHE_TTL);
            self::$localCache[$name] = $fp;
            $result[$name] = $fp;
        }

        return $result;
    }

    /**
     * 校验缓存中的多表指纹是否与当前表指纹全部一致
     *
     * 缓存项依赖多张配置表时（如 user_auth 缓存 JOIN 了 def_user + def_role_group），
     * 任一依赖表指纹缺失或与当前值不一致即视为缓存失效。
     *
     * @param array $tableNames        依赖表名列表
     * @param array $cachedFingerprints 缓存中存储的指纹映射 [tableName => fingerprint]
     * @return bool true=全部一致（缓存有效），false=任一不一致或缺失（需重建）
     */
    public function isValidMultiple(array $tableNames, array $cachedFingerprints): bool
    {
        if (empty($tableNames) || empty($cachedFingerprints)) {
            // 无依赖表或缓存无指纹（可能是旧版本写入的），保守视为无效，触发重建
            return false;
        }

        $currentFingerprints = $this->getFingerprints($tableNames);

        foreach ($tableNames as $tableName) {
            $tableName = strtolower(trim($tableName));
            $cached = (string) ($cachedFingerprints[$tableName] ?? '');
            if ($cached === '') {
                return false;
            }
            if ($cached !== (string) ($currentFingerprints[$tableName] ?? '')) {
                return false;
            }
        }

        return true;
    }

    /**
     * 清除指纹缓存（配置表被主动失效时调用，避免指纹缓存延迟）
     *
     * @param string $tableName 表名
     */
    public function invalidate(string $tableName): void
    {
        $tableName = strtolower(trim($tableName));
        $this->cache->delete(self::FP_CACHE_PREFIX . $tableName);
        unset(self::$localCache[$tableName]);
    }

    /**
     * 清除所有指纹缓存
     */
    public function invalidateAll(): void
    {
        foreach ($this->getMonitoredTables() as $table) {
            $this->cache->delete(self::FP_CACHE_PREFIX . $table);
            unset(self::$localCache[$table]);
        }
        // 同步清除监控表清单缓存（def_config_table 可能也被修改）
        $this->invalidateMonitoredTablesCache();
    }

    /**
     * 查询单表指纹（UPDATE_TIME + CHECKSUM）
     */
    private function queryFingerprintFromDB(string $tableName): string
    {
        $fps = $this->queryFingerprintsFromDB([$tableName]);
        return $fps[$tableName] ?? '';
    }

    /**
     * 批量查询多表指纹（UPDATE_TIME + CHECKSUM）
     *
     * 指纹格式："{UPDATE_TIME}:{CHECKSUM}"
     *  - 一次 information_schema 查询获取全部表的 UPDATE_TIME 和 TABLE_TYPE
     *  - 一次多表 CHECKSUM 语句获取基表的校验和（视图返回 NULL，跳过）
     *
     * @param array $tableNames 表名列表
     * @return array [tableName => fingerprint]
     */
    private function queryFingerprintsFromDB(array $tableNames): array
    {
        $dbName = $this->getDatabaseName();
        if ($dbName === '' || empty($tableNames)) {
            return [];
        }

        $quotedNames = array_map(
            fn($n) => $this->model->quote($n),
            $tableNames
        );
        $nameList = implode(',', $quotedNames);

        $sql = sprintf(
            'SELECT TABLE_NAME, TABLE_TYPE, UPDATE_TIME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME IN (%s)',
            $this->model->quote($dbName),
            $nameList
        );

        $result = $this->model->select($sql);
        if ($result === false) {
            return [];
        }

        $fps = [];
        $baseTables = [];
        foreach ($result->getResultArray() as $row) {
            $name = strtolower((string) ($row['TABLE_NAME'] ?? ''));
            $updateTime = $row['UPDATE_TIME'] ?? null;
            $fps[$name] = $updateTime !== null ? (string) $updateTime : '';
            if (strpos((string) ($row['TABLE_TYPE'] ?? ''), 'VIEW') === false) {
                $baseTables[] = $name;
            }
        }

        // 基表批量计算 CHECKSUM（一条语句，全表扫描小表）
        $checksums = $this->queryTableChecksums($baseTables);
        foreach ($checksums as $name => $checksum) {
            $fps[$name] = ($fps[$name] ?? '') . ':' . $checksum;
        }

        return $fps;
    }

    /**
     * 批量计算基表校验和（一条多表 CHECKSUM 语句）
     *
     * @param array $tableNames 基表名列表（视图不支持，调用方已过滤）
     * @return array [tableName => checksum]，查询失败返回空数组
     */
    private function queryTableChecksums(array $tableNames): array
    {
        if (empty($tableNames)) {
            return [];
        }

        // 表名白名单校验（仅允许标识符字符，防注入）
        $quotedNames = [];
        foreach ($tableNames as $name) {
            if (!preg_match('/^[a-z0-9_]+$/', $name)) {
                log_message('warning', '[ConfigTableFingerprint] 非法表名，跳过 CHECKSUM: ' . $name);
                continue;
            }
            $quotedNames[] = '`' . $name . '`';
        }
        if (empty($quotedNames)) {
            return [];
        }

        try {
            $sql = 'CHECKSUM TABLE ' . implode(', ', $quotedNames);
            $result = $this->model->select($sql);
            if ($result === false) {
                log_message('warning', '[ConfigTableFingerprint] CHECKSUM 语句执行失败');
                return [];
            }

            $checksums = [];
            foreach ($result->getResultArray() as $row) {
                // Table 列格式为 "dbname.tablename"
                $fullName = (string) ($row['Table'] ?? '');
                $name = strtolower(substr($fullName, (int) strrpos($fullName, '.') + 1));
                $checksum = $row['Checksum'] ?? null;
                if ($name !== '' && $checksum !== null) {
                    $checksums[$name] = (string) $checksum;
                }
            }
            return $checksums;
        } catch (\Throwable $e) {
            log_message('error', '[ConfigTableFingerprint] CHECKSUM 执行异常: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 获取当前数据库名（带进程内缓存）
     */
    private function getDatabaseName(): string
    {
        if ($this->databaseName !== null) {
            return $this->databaseName;
        }

        $sql = 'SELECT DATABASE() AS db';
        $result = $this->model->select($sql);
        if ($result === false) {
            $this->databaseName = '';
            return '';
        }

        $row = $result->getRowArray();
        $this->databaseName = (string) ($row['db'] ?? '');
        return $this->databaseName;
    }
}
