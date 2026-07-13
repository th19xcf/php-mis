import { request } from '../request';

export function invalidateCacheByTable(tableName: string) {
  return request<{
    tableName: string;
    contextCleared: number;
    timestamp: string;
  }>({
    url: '/cache/invalidate-table',
    method: 'post',
    data: { tableName },
    skipAuthError: true
  });
}

export function invalidateAllCache() {
  return request<{
    contextCleared: number;
    timestamp: string;
  }>({
    url: '/cache/invalidate-all',
    method: 'post',
    skipAuthError: true
  });
}

export function fetchCacheStatus() {
  return request<{
    cachePrefix: string;
    supportedTables: string[];
    ttlSeconds: Record<string, number>;
    timestamp: string;
  }>({
    url: '/cache/status'
  });
}
