<?php

namespace App\Libraries;

class TokenBlacklistService
{
    private string $cacheDir;
    private JwtTokenService $jwtTokenService;

    public function __construct()
    {
        $this->jwtTokenService = new JwtTokenService();
        $this->cacheDir = WRITEPATH . 'cache' . DIRECTORY_SEPARATOR . 'token_blacklist';
        
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * 将 Token 的 JTI 加入黑名单
     *
     * @param string $token 要失效的 Token
     * @param string $type Token 类型 (access/refresh)
     */
    public function addToBlacklist(string $token, string $type = 'access'): bool
    {
        $jti = $this->jwtTokenService->extractJti($token);

        if (!$jti) {
            return false;
        }

        $decoded = $this->jwtTokenService->decode($token);
        $exp = $decoded->exp ?? time() + 7200;

        $blacklistFile = $this->getBlacklistFilePath($type);

        $this->withFileLock($blacklistFile, function (array $blacklist) use ($jti, $exp): array {
            $blacklist[$jti] = [
                'expired_at' => $exp,
                'blacklisted_at' => time()
            ];
            return $blacklist;
        });

        log_message('info', "[TokenBlacklist] Token 已加入黑名单: {$type} - {$jti}");

        return true;
    }

    /**
     * 检查 Token 是否在黑名单中
     *
     * @param string $token 要检查的 Token
     * @param string $type Token 类型 (access/refresh)
     * @return bool
     */
    public function isBlacklisted(string $token, string $type = 'access'): bool
    {
        $jti = $this->jwtTokenService->extractJti($token);

        if (!$jti) {
            return true;
        }

        $blacklistFile = $this->getBlacklistFilePath($type);
        $blacklisted = false;

        $this->withFileLock($blacklistFile, function (array $blacklist) use ($jti, &$blacklisted): ?array {
            if (!isset($blacklist[$jti])) {
                $blacklisted = false;
                return null;
            }

            $entry = $blacklist[$jti];

            if (time() > $entry['expired_at']) {
                unset($blacklist[$jti]);
                $blacklisted = false;
                return $blacklist;
            }

            $blacklisted = true;
            return null;
        });

        return $blacklisted;
    }

    /**
     * 将用户的所有 Token 加入黑名单（强制下线）
     *
     * @param int $userId 用户ID
     * @param array $tokens 用户的所有有效 Token
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
     */
    public function cleanup(): int
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
     * 获取黑名单文件路径
     */
    private function getBlacklistFilePath(string $type): string
    {
        return $this->cacheDir . DIRECTORY_SEPARATOR . "blacklist_{$type}.json";
    }

    /**
     * 在文件排他锁保护下执行读-改-写操作
     *
     * 使用 fopen + flock(LOCK_EX) 包裹整个读取-修改-写入过程，
     * 消除高并发场景下"读-改-写"竞态导致黑名单条目丢失的问题。
     *
     * @param string   $filePath  黑名单文件路径
     * @param callable $callback  接收当前黑名单数组，返回修改后的数组（写入）或 null（跳过写入）
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
