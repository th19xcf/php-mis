<?php

namespace Tests\Support;

use CodeIgniter\Cache\CacheInterface;

/**
 * Cache 测试替身：内存键值存储，记录全部 save 调用，不触达文件/Redis。
 *
 * 用于锁定"SQL 失败不得写缓存"等缓存写入行为。
 */
class FakeCache implements CacheInterface
{
    /** @var array<string, mixed> 键 => 值（内存存储） */
    public array $store = [];

    /** @var array<int, array{key: string, ttl: int}> 全部 save 调用（按顺序） */
    public array $saves = [];

    /** @var array<string, int> 键 => 剩余 TTL（getMetaData 断言用） */
    private array $ttls = [];

    public function initialize(): void
    {
    }

    public function get(string $key): mixed
    {
        return $this->store[$key] ?? null;
    }

    public function save(string $key, mixed $value, int $ttl = 60): bool
    {
        $this->store[$key] = $value;
        $this->ttls[$key] = $ttl;
        $this->saves[] = ['key' => $key, 'ttl' => $ttl];
        return true;
    }

    public function remember(string $key, int $ttl, \Closure $callback): mixed
    {
        $value = $this->get($key);
        if ($value !== null) {
            return $value;
        }
        $value = $callback();
        $this->save($key, $value, $ttl);
        return $value;
    }

    public function delete(string $key): bool
    {
        $existed = array_key_exists($key, $this->store);
        unset($this->store[$key], $this->ttls[$key]);
        return $existed;
    }

    public function deleteMatching(string $pattern): int
    {
        return 0;
    }

    public function increment(string $key, int $offset = 1): bool|int
    {
        return false;
    }

    public function decrement(string $key, int $offset = 1): bool|int
    {
        return false;
    }

    public function clean(): bool
    {
        $this->store = [];
        $this->ttls = [];
        return true;
    }

    public function getCacheInfo(): array|false|object|null
    {
        return null;
    }

    public function getMetaData(string $key): ?array
    {
        if (!array_key_exists($key, $this->store)) {
            return null;
        }
        return ['ttl' => $this->ttls[$key] ?? 0];
    }

    public function isSupported(): bool
    {
        return true;
    }
}
