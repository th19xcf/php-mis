import { defineStore } from 'pinia';
import { ref } from 'vue';

export interface WorkbenchCacheItem {
  pageMeta: any;
  serverRows: any[];
  total: number;
  isDataLoaded: boolean;
  filterModel: any;
  timestamp: number;
}

export const useWorkbenchStore = defineStore('workbench', () => {
  // 缓存每个功能编码+参数组合的数据
  // 使用 functionCode_params 作为缓存键，区分同一功能不同参数的场景
  const cache = ref<Map<string, WorkbenchCacheItem>>(new Map());

  function getCacheKey(functionCode: string, params: string): string {
    return `${functionCode}_${params}`;
  }

  // 获取缓存数据
  function getCache(functionCode: string, params: string): WorkbenchCacheItem | undefined {
    return cache.value.get(getCacheKey(functionCode, params));
  }

  // 设置缓存数据
  function setCache(functionCode: string, params: string, data: Partial<WorkbenchCacheItem>) {
    const key = getCacheKey(functionCode, params);
    const existing = cache.value.get(key);
    cache.value.set(key, {
      pageMeta: data.pageMeta ?? existing?.pageMeta ?? null,
      serverRows: data.serverRows ?? existing?.serverRows ?? [],
      total: data.total ?? existing?.total ?? 0,
      isDataLoaded: data.isDataLoaded ?? existing?.isDataLoaded ?? false,
      filterModel: data.filterModel ?? existing?.filterModel ?? null,
      timestamp: Date.now()
    });
  }

  // 清除指定功能的缓存
  function clearCache(functionCode: string, params: string) {
    cache.value.delete(getCacheKey(functionCode, params));
  }

  // 清除所有缓存
  function clearAllCache() {
    cache.value.clear();
  }

  // 检查是否有缓存
  function hasCache(functionCode: string, params: string): boolean {
    return cache.value.has(getCacheKey(functionCode, params));
  }

  function getFilterModel(functionCode: string, params: string): any {
    return cache.value.get(getCacheKey(functionCode, params))?.filterModel ?? null;
  }

  function setFilterModel(functionCode: string, params: string, filterModel: any) {
    if (!filterModel || Object.keys(filterModel).length === 0) {
      return;
    }
    const key = getCacheKey(functionCode, params);
    const existing = cache.value.get(key);
    if (existing) {
      existing.filterModel = filterModel;
      existing.timestamp = Date.now();
    } else {
      cache.value.set(key, {
        pageMeta: null,
        serverRows: [],
        total: 0,
        isDataLoaded: false,
        filterModel,
        timestamp: Date.now()
      });
    }
  }

  function clearFilterModel(functionCode: string, params: string) {
    const key = getCacheKey(functionCode, params);
    const existing = cache.value.get(key);
    if (existing) {
      existing.filterModel = null;
      existing.timestamp = Date.now();
    }
  }

  return {
    cache,
    getCache,
    setCache,
    clearCache,
    clearAllCache,
    hasCache,
    getFilterModel,
    setFilterModel,
    clearFilterModel
  };
});
