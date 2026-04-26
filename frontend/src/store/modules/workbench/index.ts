import { defineStore } from 'pinia';
import { ref } from 'vue';

export interface WorkbenchCacheItem {
  pageMeta: any;
  serverRows: any[];
  total: number;
  isDataLoaded: boolean;
  timestamp: number;
}

export const useWorkbenchStore = defineStore('workbench', () => {
  // 缓存每个功能编码的数据
  const cache = ref<Map<string, WorkbenchCacheItem>>(new Map());

  // 获取缓存数据
  function getCache(functionCode: string): WorkbenchCacheItem | undefined {
    return cache.value.get(functionCode);
  }

  // 设置缓存数据
  function setCache(functionCode: string, data: Partial<WorkbenchCacheItem>) {
    const existing = cache.value.get(functionCode);
    cache.value.set(functionCode, {
      pageMeta: data.pageMeta ?? existing?.pageMeta ?? null,
      serverRows: data.serverRows ?? existing?.serverRows ?? [],
      total: data.total ?? existing?.total ?? 0,
      isDataLoaded: data.isDataLoaded ?? existing?.isDataLoaded ?? false,
      timestamp: Date.now()
    });
  }

  // 清除指定功能的缓存
  function clearCache(functionCode: string) {
    cache.value.delete(functionCode);
  }

  // 清除所有缓存
  function clearAllCache() {
    cache.value.clear();
  }

  // 检查是否有缓存
  function hasCache(functionCode: string): boolean {
    return cache.value.has(functionCode);
  }

  return {
    cache,
    getCache,
    setCache,
    clearCache,
    clearAllCache,
    hasCache
  };
});
