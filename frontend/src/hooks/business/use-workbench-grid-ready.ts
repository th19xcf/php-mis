import type { Ref } from 'vue';
import type { GridApi, GridReadyEvent } from 'ag-grid-community';
import type { WorkbenchStore } from './use-workbench-grid-state';

interface UseWorkbenchGridReadyOptions {
  gridApi: Ref<GridApi<Api.Workbench.QueryRecord> | null>;
  workbenchStore: WorkbenchStore;
  visibleFieldColumns: Ref<string[]>;
  pinTargetFields: Ref<string[]>;
  isRestoringColumnState: Ref<boolean>;
  page: Ref<number>;
  registerGridPersistenceListeners: () => void;
  getFunctionCode: () => string;
  getParams: () => string;
  /** 字段列选项，用于初始化可见列（页面第一次打开时） */
  fieldColumnOptions?: () => Array<{ label: string; value: string | number }>;
}

/**
 * 包装 gridReady 事件处理函数
 *  - 第一次 gridReady 时把可见列初始化为 fieldColumnOptions
 *  - 恢复筛选 / 恢复列状态 / 恢复固定列 / 恢复可见列
 *  - 注册持久化监听（filterChanged / sortChanged / columnResized ...）
 *  - 绑定 sortChanged → 跳回第 1 页 + page = 1
 *
 * 注意：useWorkbenchGridState 已经接管了 onActivated 时的状态恢复（基于 cache 命中）。
 * 本函数主要用于 gridReady 时（标签页第一次挂载）的初始恢复，与 gridState 互补，不重复。
 */
export function useWorkbenchGridReady(options: UseWorkbenchGridReadyOptions) {
  function applyCachedColumnState(api: GridApi<Api.Workbench.QueryRecord>) {
    const fnCode = options.getFunctionCode();
    const fnParams = options.getParams();

    const cachedColumnState = options.workbenchStore.getColumnState(fnCode, fnParams);
    if (!cachedColumnState || !Array.isArray(cachedColumnState) || cachedColumnState.length === 0) {
      return;
    }

    options.isRestoringColumnState.value = true;

    const cachedPinColumns = options.workbenchStore.getPinColumns(fnCode, fnParams);
    const pinColumnsArray = Array.from(cachedPinColumns);
    const mergedColumnState = cachedColumnState.map((col: any) => {
      // GUID 列恒隐藏：忽略缓存的 hide，强制隐藏
      if (
        String(col.colId || '')
          .trim()
          .toUpperCase() === 'GUID'
      ) {
        return { ...col, hide: true };
      }
      if (pinColumnsArray.includes(col.colId)) {
        return { ...col, pinned: 'left' };
      }
      return col;
    });

    if (!api || api.isDestroyed()) {
      options.isRestoringColumnState.value = false;
      return;
    }

    api.applyColumnState({ state: mergedColumnState, applyOrder: true });

    const cachedVisibleColumns = options.workbenchStore.getVisibleColumns(fnCode, fnParams);
    if (cachedVisibleColumns.length > 0) {
      options.visibleFieldColumns.value = cachedVisibleColumns;
    }

    if (cachedPinColumns.length > 0) {
      options.pinTargetFields.value = cachedPinColumns;
    }

    setTimeout(() => {
      options.isRestoringColumnState.value = false;
    }, 100);
  }

  function applyCachedFilter(api: GridApi<Api.Workbench.QueryRecord>) {
    const fnCode = options.getFunctionCode();
    const fnParams = options.getParams();
    const cachedFilterModel = options.workbenchStore.getFilterModel(fnCode, fnParams);
    if (!cachedFilterModel) return;
    setTimeout(() => {
      if (api && !api.isDestroyed()) {
        api.setFilterModel(cachedFilterModel);
      }
    }, 100);
  }

  function bindSortResetToFirstPage(api: GridApi<Api.Workbench.QueryRecord>) {
    api.addEventListener('sortChanged', () => {
      if (options.isRestoringColumnState.value) return;
      const currentPage = api.paginationGetCurrentPage();
      if (currentPage !== 0) {
        api.paginationGoToFirstPage();
        options.page.value = 1;
      }
    });
  }

  function handleGridReady(event: GridReadyEvent<Api.Workbench.QueryRecord>) {
    const api = event.api;
    options.gridApi.value = api;

    if (options.fieldColumnOptions) {
      options.visibleFieldColumns.value = options.fieldColumnOptions().map(item => String(item.value));
    }

    applyCachedFilter(api);
    setTimeout(() => applyCachedColumnState(api), 150);

    bindSortResetToFirstPage(api);
    options.registerGridPersistenceListeners();
  }

  return { handleGridReady };
}
