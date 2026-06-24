<?php

namespace App\Libraries;

use Config\TokenBlacklist;
use Predis\Client as PredisClient;
use Throwable;

/**
 * Token 黑名单服务
 *
 * P03 优化：原实现使用 JSON 文件 + flock 锁，每次鉴权请求都需文件 IO。
 * 优化后支持两种驱动：
 *   - file  : 保持向后兼容（默认），单实例场景使用
 *   - redis : 推荐生产环境使用，O(1) 查询，自动 TTL 过期，支持多实例共享
 *
 * 通过 Config\TokenBlacklist::$driver 选择驱动。
 *
 * 兼容性：
 *   - 公共方法签名 100% 保持不变（addToBlacklist/isBlacklisted/blacklistAllUserTokens/cleanup）
 *   - 文件驱动生成的 JSON 格式保持兼容
 *   - 切换驱动无需修改调用方代码
 */
class TokenBlacklistService
{
    private string $driver;
    private string $cacheDir;
    private array $redisConfig;
    private JwtTokenService $jwtTokenService;
    private ?PredisClient $redis = null;

    public function __construct(?TokenBlacklist $config = null)
    {
        $config = $config ?? config('TokenBlacklist');

        $this->driver        = $config->driver;
        $this->cacheDir      = $config->file['cacheDir'];
        $this->redisConfig   = $config->redis;
        $this->jwtTokenService = new JwtTokenService();

        if ($this->driver === 'file') {
            $this->ensureFileCacheDir();
        } else {
            $this->initRedis();
        }
    }

    /**
     * 将 Token 的 JTI 加入黑名单
     *
     * @param string $token 要失效的 Token
     * @param string $type  Token 类型 (access/refresh)
     * @return bool 是否成功加入（无效 Token 返回 false）
     */
    public function addToBlacklist(string $token, string $type = 'access'): bool
    {
        $jti = $this->jwtTokenService->extractJti($token);
        if (!$jti) {
            return false;
        }

        try {
            $decoded = $this->jwtTokenService->decode($token);
            $exp = $decoded->exp ?? time() + 7200;
        } catch (Throwable) {
            return false;
        }

        $ttl = max(1, $exp - time());

        if ($this->driver === 'redis') {
            return $this->addToBlacklistRedis($jti, $type, $ttl);
        }
        return $this->addToBlacklistFile($jti, $type, $exp);
    }

    /**
     * 检查 Token 是否在黑名单中
     *
     * @return bool true=已失效需拒绝；false=有效可放行
     */
    public function isBlacklisted(string $token, string $type = 'access'): bool
    {
        $jti = $this->jwtTokenService->extractJti($token);
        if (!$jti) {
            // 无法解析 JTI 的 token 视为失效（fail-closed）
            return true;
        }

        if ($this->driver === 'redis') {
            return $this->isBlacklistedRedis($jti, $type);
        }
        return $this->isBlacklistedFile($jti, $type);
    }

    /**
     * 将用户的所有 Token 加入黑名单（强制下线）
     *
     * @param int   $userId 用户ID
     * @param array $tokens 用户的所有有效 Token
     * @return int 成功加入黑名单的 Token 数量
     */
    public function blacklistAllUserTokens(int $userId, array $tokens): int
    {
        $count = 0;
        foreach ($tokens as $token) {
            if ($this->addToBlacklist($token, 'access')) {
                $count++;
            }
        }

        log_message('info', "[TokenBlacklist] 用户 {$userId} 的 {$count} 个 Token 已加入黑名单");

        return $count;
    }

    /**
     * 清理已过期的黑名单条目
     *
     * file 驱动：需要主动清理（删除 expired_at < now 的项）
     * redis 驱动：Redis EX TTL 自动过期，无需清理
     *
     * @return int 清理的条目数量
     */
    public function cleanup(): int
    {
        if ($this->driver === 'redis') {
            // Redis TTL 自动清理，此方法保留仅为向后兼容
            return 0;
        }
        return $this->cleanupFile();
    }

    /**
     * 获取当前驱动名称（用于监控/调试）
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    // ==================== Redis 驱动实现 ====================

    private function initRedis(): void
    {
        try {
            $this->redis = new PredisClient([
                'scheme'   => 'tcp',
                'host'     => $this->redisConfig['host'],
                'port'     => $this->redisConfig['port'],
                'password' => $this->redisConfig['password'] ?: null,
                'database' => $this->redisConfig['database'],
                'timeout'  => (float) $this->redisConfig['timeout'],
            ]);
            $this->redis->connect();
        } catch (Throwable $e) {
            // Redis 不可用时降级到 file 驱动，避免登录态完全失效
            log_message('error', '[TokenBlacklist] Redis 连接失败，降级到 file 驱动: ' . $e->getMessage());
            $this->driver = 'file';
            $this->ensureFileCacheDir();
        }
    }

    private function addToBlacklistRedis(string $jti, string $type, int $ttl): bool
    {
        if ($this->redis === null) {
            return false;
        }
        try {
            $key = $this->redisKey($jti, $type);
            $this->redis->setex($key, $ttl, '1');
            return true;
        } catch (Throwable $e) {
            log_message('error', '[TokenBlacklist] Redis SETEX 失败: ' . $e->getMessage());
            return false;
        }
    }

    private function isBlacklistedRedis(string $jti, string $type): bool
    {
        if ($this->redis === null) {
            return false;
        }
        try {
            return (bool) $this->redis->exists($this->redisKey($jti, $type));
        } catch (Throwable $e) {
            log_message('error', '[TokenBlacklist] Redis EXISTS 失败，按白名单放行: ' . $e->getMessage());
            // 降级放行：Redis 异常时不应阻塞所有请求
            return false;
        }
    }

    private function redisKey(string $jti, string $type): string
    {
        return $this->redisConfig['prefix'] . $type . ':' . $jti;
    }

    // ==================== File 驱动实现（兼容原逻辑） ====================

    private function ensureFileCacheDir(): void
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    private function getBlacklistFilePath(string $type): string
    {
        return $this->cacheDir . DIRECTORY_SEPARATOR . "blacklist_{$type}.json";
    }

    private function addToBlacklistFile(string $jti, string $type, int $exp): bool
    {
        $blacklistFile = $this->getBlacklistFilePath($type);
        $this->withFileLock($blacklistFile, function (array $blacklist) use ($jti, $exp): array {
            $blacklist[$jti] = [
                'expired_at'    => $exp,
                'blacklisted_at' => time(),
            ];
            return $blacklist;
        });

        log_message('info', "[TokenBlacklist] Token 已加入黑名单: {$type} - {$jti}");
        return true;
    }

    private function isBlacklistedFile(string $jti, string $type): bool
    {
        $blacklistFile = $this->getBlacklistFilePath($type);
        $blacklisted = false;

        $this->withFileLock($blacklistFile, function (array $blacklist) use ($jti, &$blacklisted): ?array {
            if (!isset($blacklist[$jti])) {
                $blacklisted = false;
                return null;
            }

            $entry = $blacklist[$jti];
            if (time() > $entry['expired_at']) {
                // 已过期，惰性删除
                unset($blacklist[$jti]);
                $blacklisted = false;
                return $blacklist;
            }

            $blacklisted = true;
            return null;
        });

        return $blacklisted;
    }

    private function cleanupFile(): int
    {
        $count = 0;
        $now = time();

        foreach (['access', 'refresh'] as $type) {
            $blacklistFile = $this->getBlacklistFilePath($type);
            if (!file_exists($blacklistFile)) {
                continue;
            }

            $removed = 0;
            $this->withFileLock($blacklistFile, function (array $blacklist) use ($now, &$removed): ?array {
                $updated = false;
                foreach ($blacklist as $jti => $entry) {
                    if ($now > $entry['expired_at']) {
                        unset($blacklist[$jti]);
                        $updated = true;
                        $removed++;
                    }
                }
                return $updated ? $blacklist : null;
            });

            $count += $removed;
        }

        if ($count > 0) {
            log_message('info', "[TokenBlacklist] 清理了 {$count} 个过期条目");
        }
        return $count;
    }

    /**
     * 在文件排他锁保护下执行读-改-写操作（消除并发竞态）
     *
     * @param string   $filePath 黑名单文件路径
     * @param callable $callback 接收当前黑名单数组，返回修改后的数组（写入）或 null（跳过写入）
     */
    private function withFileLock(string $filePath, callable $callback): void
    {
        $fp = fopen($filePath, 'c+');
        if ($fp === false) {
            log_message('error', "[TokenBlacklist] 无法打开黑名单文件: {$filePath}");
            return;
        }

        flock($fp, LOCK_EX);

        try {
            $content = '';
            while (!feof($fp)) {
                $chunk = fread($fp, 8192);
                if ($chunk === false) {
                    break;
                }
                $content .= $chunk;
            }

            $data = ($content === '') ? [] : json_decode($content, true);
            if (!is_array($data)) {
                $data = [];
            }

            $result = $callback($data);

            if ($result !== null) {
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($result, JSON_PRETTY_PRINT));
                fflush($fp);
            }
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
