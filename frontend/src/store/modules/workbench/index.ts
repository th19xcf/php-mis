import { defineStore } from 'pinia';
import { ref } from 'vue';

export interface WorkbenchCacheItem {
  pageMeta: any;
  serverRows: any[];
  total: number;
  isDataLoaded: boolean;
  filterModel: any;
  columnState: any;
  // 新增状态字段
  page: number;
  pageSize: number;
  selectedRows: any[];
  visibleColumns: string[];
  pinColumns: string[];
  // UI 状态
  uiState: {
    conditionVisible: boolean;
    fieldColumnVisible: boolean;
    pinColumnVisible: boolean;
    quickKeyword: string;
    selectedField: string;
    selectedOperator: string;
    selectedValue: string;
  };
  timestamp: number;
}

export const useWorkbenchStore = defineStore('workbench', () => {
  // 缓存每个标签作用域 + 功能编码 + 参数组合的数据
  const cache = ref<Map<string, WorkbenchCacheItem>>(new Map());

  function getCacheKey(functionCode: string, params: string, scopeKey = ''): string {
    const normalizedScopeKey = scopeKey.trim();
    return normalizedScopeKey ? `${normalizedScopeKey}::${functionCode}_${params}` : `${functionCode}_${params}`;
  }

  // 获取缓存数据
  function getCache(functionCode: string, params: string, scopeKey = ''): WorkbenchCacheItem | undefined {
    return cache.value.get(getCacheKey(functionCode, params, scopeKey));
  }

  // 设置缓存数据
  function setCache(functionCode: string, params: string, data: Partial<WorkbenchCacheItem>, scopeKey = '') {
    const key = getCacheKey(functionCode, params, scopeKey);
    const existing = cache.value.get(key);
    cache.value.set(key, {
      pageMeta: data.pageMeta ?? existing?.pageMeta ?? null,
      serverRows: data.serverRows ?? existing?.serverRows ?? [],
      total: data.total ?? existing?.total ?? 0,
      isDataLoaded: data.isDataLoaded ?? existing?.isDataLoaded ?? false,
      filterModel: data.filterModel ?? existing?.filterModel ?? null,
      columnState: data.columnState ?? existing?.columnState ?? null,
      // 新增字段
      page: data.page ?? existing?.page ?? 1,
      pageSize: data.pageSize ?? existing?.pageSize ?? 500,
      selectedRows: data.selectedRows ?? existing?.selectedRows ?? [],
      visibleColumns: data.visibleColumns ?? existing?.visibleColumns ?? [],
      pinColumns: data.pinColumns ?? existing?.pinColumns ?? [],
      uiState: data.uiState ?? existing?.uiState ?? {
        conditionVisible: false,
        fieldColumnVisible: false,
        pinColumnVisible: false,
        quickKeyword: '',
        selectedField: '',
        selectedOperator: 'contains',
        selectedValue: ''
      },
      timestamp: Date.now()
    });
  }

  // 清除指定功能的缓存
  function clearCache(functionCode: string, params: string, scopeKey = '') {
    cache.value.delete(getCacheKey(functionCode, params, scopeKey));
  }

  // 清除所有缓存
  function clearAllCache() {
    cache.value.clear();
  }

  // 检查是否有缓存
  function hasCache(functionCode: string, params: string, scopeKey = ''): boolean {
    return cache.value.has(getCacheKey(functionCode, params, scopeKey));
  }

  function getFilterModel(functionCode: string, params: string, scopeKey = ''): any {
    return cache.value.get(getCacheKey(functionCode, params, scopeKey))?.filterModel ?? null;
  }

  function setFilterModel(functionCode: string, params: string, filterModel: any, scopeKey = '') {
    if (!filterModel || Object.keys(filterModel).length === 0) {
      return;
    }
    const key = getCacheKey(functionCode, params, scopeKey);
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
        columnState: null,
        page: 1,
        pageSize: 500,
        selectedRows: [],
        visibleColumns: [],
        pinColumns: [],
        uiState: {
          conditionVisible: false,
          fieldColumnVisible: false,
          pinColumnVisible: false,
          quickKeyword: '',
          selectedField: '',
          selectedOperator: 'contains',
          selectedValue: ''
        },
        timestamp: Date.now()
      });
    }
  }

  function clearFilterModel(functionCode: string, params: string, scopeKey = '') {
    const key = getCacheKey(functionCode, params, scopeKey);
    const existing = cache.value.get(key);
    if (existing) {
      existing.filterModel = null;
      existing.timestamp = Date.now();
    }
  }

  function getColumnState(functionCode: string, params: string, scopeKey = ''): any {
    return cache.value.get(getCacheKey(functionCode, params, scopeKey))?.columnState ?? null;
  }

  function setColumnState(functionCode: string, params: string, columnState: any, scopeKey = '') {
    if (!columnState) {
      return;
    }
    const key = getCacheKey(functionCode, params, scopeKey);
    const existing = cache.value.get(key);
    if (existing) {
      existing.columnState = columnState;
      existing.timestamp = Date.now();
    } else {
      cache.value.set(key, {
        pageMeta: null,
        serverRows: [],
        total: 0,
        isDataLoaded: false,
        filterModel: null,
        columnState,
        page: 1,
        pageSize: 500,
        selectedRows: [],
        visibleColumns: [],
        pinColumns: [],
        uiState: {
          conditionVisible: false,
          fieldColumnVisible: false,
          pinColumnVisible: false,
          quickKeyword: '',
          selectedField: '',
          selectedOperator: 'contains',
          selectedValue: ''
        },
        timestamp: Date.now()
      });
    }
  }

  function clearColumnState(functionCode: string, params: string, scopeKey = '') {
    const key = getCacheKey(functionCode, params, scopeKey);
    const existing = cache.value.get(key);
    if (existing) {
      existing.columnState = null;
      existing.timestamp = Date.now();
    }
  }

  // 分页状态
  function getPage(functionCode: string, params: string, scopeKey = ''): number {
    return cache.value.get(getCacheKey(functionCode, params, scopeKey))?.page ?? 1;
  }

  function setPage(functionCode: string, params: string, page: number, scopeKey = '') {
    const key = getCacheKey(functionCode, params, scopeKey);
    const existing = cache.value.get(key);
    if (existing) {
      existing.page = page;
      existing.timestamp = Date.now();
    } else {
      cache.value.set(key, {
        pageMeta: null,
        serverRows: [],
        total: 0,
        isDataLoaded: false,
        filterModel: null,
        columnState: null,
        page,
        pageSize: 500,
        selectedRows: [],
        visibleColumns: [],
        pinColumns: [],
        uiState: {
          conditionVisible: false,
          fieldColumnVisible: false,
          pinColumnVisible: false,
          quickKeyword: '',
          selectedField: '',
          selectedOperator: 'contains',
          selectedValue: ''
        },
        timestamp: Date.now()
      });
    }
  }

  function getPageSize(functionCode: string, params: string, scopeKey = ''): number {
    return cache.value.get(getCacheKey(functionCode, params, scopeKey))?.pageSize ?? 500;
  }

  function setPageSize(functionCode: string, params: string, pageSize: number, scopeKey = '') {
    const key = getCacheKey(functionCode, params, scopeKey);
    const existing = cache.value.get(key);
    if (existing) {
      existing.pageSize = pageSize;
      existing.timestamp = Date.now();
    } else {
      cache.value.set(key, {
        pageMeta: null,
        serverRows: [],
        total: 0,
        isDataLoaded: false,
        filterModel: null,
        columnState: null,
        page: 1,
        pageSize,
        selectedRows: [],
        visibleColumns: [],
        pinColumns: [],
        uiState: {
          conditionVisible: false,
          fieldColumnVisible: false,
          pinColumnVisible: false,
          quickKeyword: '',
          selectedField: '',
          selectedOperator: 'contains',
          selectedValue: ''
        },
        timestamp: Date.now()
      });
    }
  }

  // 行选择状态
  function getSelectedRows(functionCode: string, params: string, scopeKey = ''): any[] {
    return cache.value.get(getCacheKey(functionCode, params, scopeKey))?.selectedRows ?? [];
  }

  function setSelectedRows(functionCode: string, params: string, selectedRows: any[], scopeKey = '') {
    const key = getCacheKey(functionCode, params, scopeKey);
    const existing = cache.value.get(key);
    if (existing) {
      existing.selectedRows = selectedRows;
      existing.timestamp = Date.now();
    } else {
      cache.value.set(key, {
        pageMeta: null,
        serverRows: [],
        total: 0,
        isDataLoaded: false,
        filterModel: null,
        columnState: null,
        page: 1,
        pageSize: 500,
        selectedRows,
        visibleColumns: [],
        pinColumns: [],
        uiState: {
          conditionVisible: false,
          fieldColumnVisible: false,
          pinColumnVisible: false,
          quickKeyword: '',
          selectedField: '',
          selectedOperator: 'contains',
          selectedValue: ''
        },
        timestamp: Date.now()
      });
    }
  }

  // 字段选择状态
  function getVisibleColumns(functionCode: string, params: string, scopeKey = ''): string[] {
    return cache.value.get(getCacheKey(functionCode, params, scopeKey))?.visibleColumns ?? [];
  }

  function setVisibleColumns(functionCode: string, params: string, visibleColumns: string[], scopeKey = '') {
    const key = getCacheKey(functionCode, params, scopeKey);
    const existing = cache.value.get(key);
    if (existing) {
      existing.visibleColumns = visibleColumns;
      existing.timestamp = Date.now();
    } else {
      cache.value.set(key, {
        pageMeta: null,
        serverRows: [],
        total: 0,
        isDataLoaded: false,
        filterModel: null,
        columnState: null,
        page: 1,
        pageSize: 500,
        selectedRows: [],
        visibleColumns,
        pinColumns: [],
        uiState: {
          conditionVisible: false,
          fieldColumnVisible: false,
          pinColumnVisible: false,
          quickKeyword: '',
          selectedField: '',
          selectedOperator: 'contains',
          selectedValue: ''
        },
        timestamp: Date.now()
      });
    }
  }

  // 固定列状态
  function getPinColumns(functionCode: string, params: string, scopeKey = ''): string[] {
    return cache.value.get(getCacheKey(functionCode, params, scopeKey))?.pinColumns ?? [];
  }

  function setPinColumns(functionCode: string, params: string, pinColumns: string[], scopeKey = '') {
    const key = getCacheKey(functionCode, params, scopeKey);
    const existing = cache.value.get(key);
    if (existing) {
      existing.pinColumns = pinColumns;
      existing.timestamp = Date.now();
    } else {
      cache.value.set(key, {
        pageMeta: null,
        serverRows: [],
        total: 0,
        isDataLoaded: false,
        filterModel: null,
        columnState: null,
        page: 1,
        pageSize: 500,
        selectedRows: [],
        visibleColumns: [],
        pinColumns,
        uiState: {
          conditionVisible: false,
          fieldColumnVisible: false,
          pinColumnVisible: false,
          quickKeyword: '',
          selectedField: '',
          selectedOperator: 'contains',
          selectedValue: ''
        },
        timestamp: Date.now()
      });
    }
  }

  // UI 状态
  function getUIState(functionCode: string, params: string, scopeKey = ''): WorkbenchCacheItem['uiState'] | null {
    return cache.value.get(getCacheKey(functionCode, params, scopeKey))?.uiState ?? null;
  }

  function setUIState(functionCode: string, params: string, uiState: Partial<WorkbenchCacheItem['uiState']>, scopeKey = '') {
    const key = getCacheKey(functionCode, params, scopeKey);
    const existing = cache.value.get(key);
    if (existing) {
      existing.uiState = { ...existing.uiState, ...uiState };
      existing.timestamp = Date.now();
    } else {
      cache.value.set(key, {
        pageMeta: null,
        serverRows: [],
        total: 0,
        isDataLoaded: false,
        filterModel: null,
        columnState: null,
        page: 1,
        pageSize: 500,
        selectedRows: [],
        visibleColumns: [],
        pinColumns: [],
        uiState: {
          conditionVisible: false,
          fieldColumnVisible: false,
          pinColumnVisible: false,
          quickKeyword: '',
          selectedField: '',
          selectedOperator: 'contains',
          selectedValue: '',
          ...uiState
        },
        timestamp: Date.now()
      });
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
    clearFilterModel,
    getColumnState,
    setColumnState,
    clearColumnState,
    getPage,
    setPage,
    getPageSize,
    setPageSize,
    getSelectedRows,
    setSelectedRows,
    getVisibleColumns,
    setVisibleColumns,
    getPinColumns,
    setPinColumns,
    getUIState,
    setUIState
  };
});
