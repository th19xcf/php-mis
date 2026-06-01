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
        $ttl = max(0, $exp - time());

        $blacklistFile = $this->getBlacklistFilePath($type);
        $blacklist = $this->loadBlacklist($blacklistFile);
        
        $blacklist[$jti] = [
            'expired_at' => $exp,
            'blacklisted_at' => time()
        ];
        
        $this->saveBlacklist($blacklistFile, $blacklist);
        
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
        $blacklist = $this->loadBlacklist($blacklistFile);
        
        if (!isset($blacklist[$jti])) {
            return false;
        }

        $entry = $blacklist[$jti];
        
        if (time() > $entry['expired_at']) {
            unset($blacklist[$jti]);
            $this->saveBlacklist($blacklistFile, $blacklist);
            return false;
        }
        
        return true;
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
            
            $blacklist = $this->loadBlacklist($blacklistFile);
            $updated = false;
            
            foreach ($blacklist as $jti => $entry) {
                if ($now > $entry['expired_at']) {
                    unset($blacklist[$jti]);
                    $updated = true;
                    $count++;
                }
            }
            
            if ($updated) {
                $this->saveBlacklist($blacklistFile, $blacklist);
            }
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
     * 加载黑名单
     */
    private function loadBlacklist(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }
        
        $content = file_get_contents($filePath);
        
        if ($content === false || $content === '') {
            return [];
        }
        
        $data = json_decode($content, true);
        
        return is_array($data) ? $data : [];
    }

    /**
     * 保存黑名单
     */
    private function saveBlacklist(string $filePath, array $blacklist): void
    {
        file_put_contents($filePath, json_encode($blacklist, JSON_PRETTY_PRINT));
    }
}
