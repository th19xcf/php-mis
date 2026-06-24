<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Token 黑名单存储配置
 *
 * 驱动选择：
 *   - 'file'  ：本地 JSON 文件 + 文件锁（单实例部署、QPS < 50）
 *   - 'redis' ：Redis SETEX（推荐，多实例部署必需）
 *
 * 通过环境变量 TOKEN_BLACKLIST_DRIVER 切换：
 *   .env 中设置 TOKEN_BLACKLIST_DRIVER = redis
 *
 * 默认值 'file' 保持向后兼容；建议生产环境显式设为 'redis'。
 */
class TokenBlacklist extends BaseConfig
{
    /**
     * 存储驱动：'file' | 'redis'
     */
    public string $driver = 'file';

    /**
     * 文件驱动配置（driver=file 时生效）
     *
     * @var array<string, string>
     */
    public array $file = [
        'cacheDir' => '', // 空值表示使用 WRITEPATH . 'cache/token_blacklist'
    ];

    /**
     * Redis 驱动配置（driver=redis 时生效）
     *
     * 与 Config\Cache::$redis 保持一致的字段命名，
     * 便于运维通过环境变量统一管理 Redis 连接。
     *
     * @var array<string, string|int>
     */
    public array $redis = [
        'host'     => '127.0.0.1',
        'port'     => 6379,
        'password' => null,
        'database' => 0,
        'timeout'  => 1.5,
        'prefix'   => 'mis:bl:', // Redis key 前缀，便于多业务共用 Redis
    ];

    public function __construct()
    {
        parent::__construct();

        $this->driver = strtolower((string) env('TOKEN_BLACKLIST_DRIVER', $this->driver));
        if (!in_array($this->driver, ['file', 'redis'], true)) {
            $this->driver = 'file';
        }

        // Redis 连接参数允许通过 env 覆盖（支持部署时不同环境的差异）
        $this->redis['host']     = (string) env('REDIS_BL_HOST',     (string) $this->redis['host']);
        $this->redis['port']     = (int)    env('REDIS_BL_PORT',     (int)    $this->redis['port']);
        $this->redis['password'] =          env('REDIS_BL_PASSWORD', $this->redis['password']);
        $this->redis['database'] = (int)    env('REDIS_BL_DB',        (int)    $this->redis['database']);
        $this->redis['prefix']   = (string) env('REDIS_BL_PREFIX',    (string) $this->redis['prefix']);

        if ($this->file['cacheDir'] === '') {
            $this->file['cacheDir'] = WRITEPATH . 'cache' . DIRECTORY_SEPARATOR . 'token_blacklist';
        }
    }
}
