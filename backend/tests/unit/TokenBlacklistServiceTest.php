<?php

namespace Tests\Unit;

use App\Libraries\JwtTokenService;
use App\Libraries\TokenBlacklistService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\TokenBlacklist;

/**
 * TokenBlacklistService 单元测试
 *
 * 覆盖 file / redis 两种驱动的关键路径。
 * redis 驱动通过 REDIS_TEST_HOST 环境变量连接；未配置或连接失败时跳过。
 */
class TokenBlacklistServiceTest extends CIUnitTestCase
{
    private string $tempCacheDir;
    private string $originalEnvDriver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempCacheDir = WRITEPATH . 'cache' . DIRECTORY_SEPARATOR . 'test_bl_' . bin2hex(random_bytes(4));
        $this->originalEnvDriver = (string) (getenv('TOKEN_BLACKLIST_DRIVER') ?: 'file');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempCacheDir);
        $this->cleanupBlacklistFiles();
        putenv('TOKEN_BLACKLIST_DRIVER=' . $this->originalEnvDriver);
        parent::tearDown();
    }

    // ==================== File 驱动测试 ====================

    public function testFileDriverAddAndCheck(): void
    {
        $service = $this->makeFileService();
        $token = $this->makeToken();

        $this->assertFalse($service->isBlacklisted($token, 'access'));
        $this->assertTrue($service->addToBlacklist($token, 'access'));
        $this->assertTrue($service->isBlacklisted($token, 'access'));
    }

    public function testFileDriverReturnsFalseForInvalidToken(): void
    {
        $service = $this->makeFileService();

        // 非 JWT 字符串
        $this->assertFalse($service->addToBlacklist('not-a-jwt-token', 'access'));
        // 解析不出 JTI 的视为已失效
        $this->assertTrue($service->isBlacklisted('not-a-jwt-token', 'access'));
    }

    public function testFileDriverExpiredEntryLazilyRemoved(): void
    {
        $service = $this->makeFileService();

        // 先用有效 token 提取 jti（避免 token 自身过期导致 extractJti 失败）
        $validToken = $this->makeToken();
        $jti = (new JwtTokenService())->extractJti($validToken);

        // 手动写入一个已过期的同 JTI 条目
        $blacklistFile = $this->tempCacheDir . DIRECTORY_SEPARATOR . 'blacklist_access.json';
        file_put_contents($blacklistFile, json_encode([
            $jti => ['expired_at' => time() - 100, 'blacklisted_at' => time() - 200],
        ]));

        $this->assertFalse(
            $service->isBlacklisted($validToken, 'access'),
            '已过期条目应被惰性删除并视为有效'
        );
    }

    public function testFileDriverCleanupRemovesExpired(): void
    {
        $service = $this->makeFileService();
        $blacklistFile = $this->tempCacheDir . DIRECTORY_SEPARATOR . 'blacklist_access.json';

        file_put_contents($blacklistFile, json_encode([
            'old_jti_1' => ['expired_at' => time() - 100, 'blacklisted_at' => time() - 200],
            'old_jti_2' => ['expired_at' => time() - 50,  'blacklisted_at' => time() - 150],
        ]));

        $removed = $service->cleanup();
        $this->assertSame(2, $removed);

        $remaining = json_decode(file_get_contents($blacklistFile), true);
        $this->assertEmpty($remaining);
    }

    public function testFileDriverBlacklistAllUserTokens(): void
    {
        $service = $this->makeFileService();
        $token1 = $this->makeToken();
        $token2 = $this->makeToken();

        $count = $service->blacklistAllUserTokens(10086, [$token1, $token2]);
        $this->assertSame(2, $count);
        $this->assertTrue($service->isBlacklisted($token1, 'access'));
        $this->assertTrue($service->isBlacklisted($token2, 'access'));
    }

    public function testFileDriverIsolatesAccessAndRefresh(): void
    {
        $service = $this->makeFileService();
        $accessToken = $this->makeToken(['type' => 'access']);
        $refreshToken = $this->makeToken(['type' => 'refresh']);

        $service->addToBlacklist($accessToken, 'access');
        $this->assertTrue($service->isBlacklisted($accessToken, 'access'));
        $this->assertFalse($service->isBlacklisted($refreshToken, 'refresh'));
    }

    public function testGetDriverReturnsConfiguredValue(): void
    {
        $fileService = $this->makeFileService();
        $this->assertSame('file', $fileService->getDriver());
    }

    // ==================== Redis 驱动测试（条件执行） ====================

    public function testRedisDriverAddAndCheck(): void
    {
        if (!$this->canConnectRedis()) {
            $this->markTestSkipped('Redis not available at ' . $this->getRedisHost());
        }

        $service = $this->makeRedisService();
        $token = $this->makeToken();
        $jti = (new JwtTokenService())->extractJti($token);

        try {
            $this->assertFalse($service->isBlacklisted($token, 'access'));
            $this->assertTrue($service->addToBlacklist($token, 'access'));
            $this->assertTrue($service->isBlacklisted($token, 'access'));
        } finally {
            $this->cleanupRedisJti($jti, 'access');
        }
    }

    public function testRedisDriverTtlAutoExpires(): void
    {
        if (!$this->canConnectRedis()) {
            $this->markTestSkipped('Redis not available');
        }

        $service = $this->makeRedisService();
        // 1 秒后过期的 token
        $token = $this->makeToken(['exp' => time() + 1]);
        $jti = (new JwtTokenService())->extractJti($token);

        try {
            $this->assertTrue($service->addToBlacklist($token, 'access'));
            $this->assertTrue($service->isBlacklisted($token, 'access'));

            sleep(2);
            $this->assertFalse($service->isBlacklisted($token, 'access'), 'TTL 到期后应自动失效');
        } finally {
            $this->cleanupRedisJti($jti, 'access');
        }
    }

    public function testRedisDriverCleanupIsNoop(): void
    {
        if (!$this->canConnectRedis()) {
            $this->markTestSkipped('Redis not available');
        }
        $service = $this->makeRedisService();
        $this->assertSame(0, $service->cleanup(), 'Redis 驱动下 cleanup 应为 no-op');
    }

    // ==================== 辅助方法 ====================

    private function makeFileService(): TokenBlacklistService
    {
        $config = new TokenBlacklist();
        $config->driver = 'file';
        $config->file['cacheDir'] = $this->tempCacheDir;
        return new TokenBlacklistService($config);
    }

    private function makeRedisService(): TokenBlacklistService
    {
        $config = new TokenBlacklist();
        $config->driver = 'redis';
        $config->redis = [
            'host'     => $this->getRedisHost(),
            'port'     => (int) (getenv('REDIS_TEST_PORT') ?: 6379),
            'password' => getenv('REDIS_TEST_PASSWORD') ?: null,
            'database' => (int) (getenv('REDIS_TEST_DB') ?: 15), // 用 DB15 隔离测试
            'timeout'  => 1.0,
            'prefix'   => 'test_bl:',
        ];
        return new TokenBlacklistService($config);
    }

    private function makeToken(array $overrides = []): string
    {
        $payload = array_merge([
            'userId'   => 'test-user',
            'userName' => 'tester',
            'iat'      => time(),
            'exp'      => time() + 3600,
            'jti'      => bin2hex(random_bytes(8)),
            'type'     => 'access',
        ], $overrides);

        return (new JwtTokenService())->encode($payload);
    }

    private function canConnectRedis(): bool
    {
        $host = $this->getRedisHost();
        $port = (int) (getenv('REDIS_TEST_PORT') ?: 6379);
        $fp = @fsockopen($host, $port, $errno, $errstr, 0.5);
        if ($fp === false) {
            return false;
        }
        fclose($fp);
        return true;
    }

    private function getRedisHost(): string
    {
        return (string) (getenv('REDIS_TEST_HOST') ?: '127.0.0.1');
    }

    private function cleanupRedisJti(string $jti, string $type): void
    {
        try {
            $config = new TokenBlacklist();
            $client = new \Predis\Client([
                'scheme' => 'tcp',
                'host'   => $this->getRedisHost(),
                'port'   => (int) (getenv('REDIS_TEST_PORT') ?: 6379),
                'database' => (int) (getenv('REDIS_TEST_DB') ?: 15),
            ]);
            $client->del(['test_bl:' . $type . ':' . $jti]);
        } catch (\Throwable) {
            // 清理失败不影响测试结果
        }
    }

    private function cleanupBlacklistFiles(): void
    {
        $files = glob($this->tempCacheDir . DIRECTORY_SEPARATOR . 'blacklist_*.json') ?: [];
        foreach ($files as $f) {
            @unlink($f);
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
