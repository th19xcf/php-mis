<?php

namespace App\Libraries;

use CodeIgniter\Cache\CacheInterface;
use Config\Services;

/**
 * 工作台上下文缓存服务
 *
 * 独立负责 workbench_context_* 缓存的读写与失效，从 ContextService 拆出。
 *
 * 设计要点（P0 修复）：
 * 原先 clearCacheByFunctionCode 在 FileHandler 驱动下因不支持 deleteMatching
 * 会退化为 cache->clean() 全量清空，导致单功能元数据变更引发全站冷启动。
 *
 * 本类通过"反向索引"方案精准定位每个 functionCode 下的所有缓存键：
 *  - 写缓存时同步把 cacheKey 登记到 workbench_context_index_{md5(functionCode)}
 *  - 按功能清除时通过索引逐个 delete，无需 deleteMatching
 *  - 索引本身也存于缓存，TTL 较长（24h），用 array 去重
 *
 * 该方案对 Redis / Memcached / FileHandler 均有效，消除驱动差异。
 */
class ContextCacheService
{
    private const CACHE_PREFIX = 'workbench_context_';
    private const INDEX_PREFIX = 'workbench_context_index_';
    private const CACHE_TTL_SECONDS = 1800;
    private const INDEX_TTL_SECONDS = 86400;

    private CacheInterface $cache;

    public function __construct()
    {
        $this->cache = Services::cache();
    }

    /**
     * 基于 functionCode + roleAuthz + region + isSuperAdmin 生成缓存键
     *
     * isSuperAdmin 必须纳入键空间：万能密码（工号+工号）登录时
     * isSuperAdmin=true，userAuth.debugAuth 会被强制置为 true；
     * 同用户之前用普通密码登录时 isSuperAdmin=false，缓存里
     * debugSql=false。两者会通过 userAuth.debugAuth → definition.toolbar.debugSql
     * 产生不同结果，必须分别缓存，否则超管身份命中旧缓存看不到"调试"按钮。
     */
    public function buildCacheKey(string $functionCode, string $roleAuthz, string $region, bool $isSuperAdmin = false): string
    {
        return self::CACHE_PREFIX . md5($functionCode) . '_' . md5(implode('|', [$roleAuthz, $region, $isSuperAdmin ? '1' : '0']));
    }

    /**
     * 读取缓存
     *
     * @return array|null 命中返回 [context, definition, ...]，未命中返回 null
     */
    public function get(string $cacheKey): ?array
    {
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached) && isset($cached['context'], $cached['definition'])) {
            return $cached;
        }
        return null;
    }

    /**
     * 写入缓存并同步更新反向索引
     */
    public function save(string $cacheKey, array $context, array $definition): void
    {
        $this->cache->save($cacheKey, [
            'context' => $context,
            'definition' => $definition,
            'cachedAt' => time(),
        ], self::CACHE_TTL_SECONDS);

        $this->addToIndex($cacheKey);
    }

    /**
     * 删除单个缓存键（同步从索引中移除）
     */
    public function delete(string $cacheKey): void
    {
        $this->cache->delete($cacheKey);
        $this->removeFromIndex($cacheKey);
    }

    /**
     * 清除工作台上下文缓存（外部调用入口）
     *
     * - 三个参数均为空：清空当前缓存驱动的全部数据（用于"全量清理"，可接受 clean()）。
     * - 仅提供 functionCode：清除该功能编码下所有角色/属地的缓存（精准删除）。
     * - 三个参数全部提供：精确删除单条缓存。
     *
     * @param string $functionCode 功能编码
     * @param string $roleAuthz    角色赋权字符串（逗号分隔）
     * @param string $region       属地/公司编码
     * @return int 实际删除的缓存条数（-1 表示全量清理无法统计）
     */
    public function clear(string $functionCode = '', string $roleAuthz = '', string $region = ''): int
    {
        if ($functionCode === '' && $roleAuthz === '' && $region === '') {
            $this->cache->clean();
            log_message('info', '[ContextCacheService] 已清空全部缓存（用户主动全量清理）');
            return -1;
        }

        if ($functionCode !== '' && $roleAuthz === '' && $region === '') {
            return $this->deleteByFunctionCode($functionCode);
        }

        $targetKey = $this->buildCacheKey($functionCode, $roleAuthz, $region);
        $this->delete($targetKey);
        log_message('info', '[ContextCacheService] 已清除单条缓存: ' . $targetKey);
        return 1;
    }

    /**
     * 按功能编码精准清除所有相关缓存
     *
     * 优先使用反向索引逐条 delete；索引缺失时回退到 deleteMatching（Redis/Memcached 支持）。
     * FileHandler 不支持 deleteMatching 时不再退化为 clean()，而是仅清空索引能定位的键，
     * 避免单功能变更引发全站冷启动。
     *
     * @return int 删除的缓存条数
     */
    public function deleteByFunctionCode(string $functionCode): int
    {
        $indexKey = $this->buildIndexKey($functionCode);
        $keys = $this->cache->get($indexKey);

        $count = 0;
        if (is_array($keys) && !empty($keys)) {
            foreach ($keys as $key) {
                $this->cache->delete($key);
                $count++;
            }
            $this->cache->delete($indexKey);
            log_message('info', sprintf(
                '[ContextCacheService] 已按功能编码精准清除缓存: functionCode=%s, count=%d',
                $functionCode,
                $count
            ));
            return $count;
        }

        // 索引缺失兜底：尝试 deleteMatching（仅 Redis/Memcached 有效）
        $pattern = self::CACHE_PREFIX . md5($functionCode) . '_*';
        try {
            $handler = $this->cache->handler();
            if (method_exists($handler, 'deleteMatching')) {
                $handler->deleteMatching($pattern);
                log_message('info', '[ContextCacheService] 索引缺失，已通过 deleteMatching 清除: ' . $pattern);
            } else {
                log_message('warning', sprintf(
                    '[ContextCacheService] 索引缺失且驱动不支持 deleteMatching，本次无键可删（避免全清）: functionCode=%s',
                    $functionCode
                ));
            }
        } catch (\Throwable $e) {
            log_message('error', '[ContextCacheService] deleteMatching 失败: ' . $e->getMessage());
        }

        return $count;
    }

    /**
     * 构建功能编码对应的索引键
     */
    private function buildIndexKey(string $functionCode): string
    {
        return self::INDEX_PREFIX . md5($functionCode);
    }

    /**
     * 将 cacheKey 登记到对应 functionCode 的反向索引
     *
     * cacheKey 格式：workbench_context_{md5(functionCode)}_{md5(roleAuthz|region|isSuper)}
     */
    private function addToIndex(string $cacheKey): void
    {
        $funcHash = $this->extractFunctionHash($cacheKey);
        if ($funcHash === null) {
            return;
        }

        $indexKey = self::INDEX_PREFIX . $funcHash;
        $keys = $this->cache->get($indexKey);
        $keys = is_array($keys) ? $keys : [];

        if (!in_array($cacheKey, $keys, true)) {
            $keys[] = $cacheKey;
            $this->cache->save($indexKey, $keys, self::INDEX_TTL_SECONDS);
        }
    }

    /**
     * 从索引中移除指定 cacheKey
     */
    private function removeFromIndex(string $cacheKey): void
    {
        $funcHash = $this->extractFunctionHash($cacheKey);
        if ($funcHash === null) {
            return;
        }

        $indexKey = self::INDEX_PREFIX . $funcHash;
        $keys = $this->cache->get($indexKey);
        if (!is_array($keys)) {
            return;
        }

        $keys = array_values(array_filter($keys, fn($k) => $k !== $cacheKey));
        if (empty($keys)) {
            $this->cache->delete($indexKey);
        } else {
            $this->cache->save($indexKey, $keys, self::INDEX_TTL_SECONDS);
        }
    }

    /**
     * 从 cacheKey 中提取 functionCode 的 md5 hash 段
     */
    private function extractFunctionHash(string $cacheKey): ?string
    {
        if (preg_match('/^' . preg_quote(self::CACHE_PREFIX, '/') . '([a-f0-9]{32})_/', $cacheKey, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
