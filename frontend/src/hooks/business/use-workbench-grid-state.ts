import { onActivated, onDeactivated, onUnmounted } from 'vue';
import type { Ref } from 'vue';
import type { GridApi } from 'ag-grid-community';
import { useWorkbenchStore } from '@/store/modules/workbench';
import type { WorkbenchCacheItem } from '@/store/modules/workbench';

type ConditionOperator = 'contains' | 'equals' | 'startsWith';

interface WorkbenchMetaLike {
  functionCode?: string;
  params?: string;
}

/**
 * 封装版 WorkbenchStore 契约
 *
 * 与原始 Pinia store（useWorkbenchStore）的差异：
 * - 方法参数为 2 个（functionCode, params），scopeKey 已由 useWorkbenchGridState
 *   在内部通过 options.getCacheScopeKey() 自动注入，调用方无需关心
 * - 类型派生自 WorkbenchCacheItem，与 store 数据结构自动同步
 *
 * 该 interface 用于约束 useWorkbenchGridState 内部构造的 workbenchStore 对象，
 * 并被 use-workbench-data-loader / grid-ready / table-edit / state-reset 等
 * hook 作为入参类型。
 */
export interface WorkbenchStore {
  getCache: (functionCode: string, params: string) => WorkbenchCacheItem | undefined;
  setCache: (functionCode: string, params: string, data: Partial<WorkbenchCacheItem>) => void;
  clearCache: (functionCode: string, params: string) => void;
  getFilterModel: (functionCode: string, params: string) => WorkbenchCacheItem['filterModel'];
  setFilterModel: (functionCode: string, params: string, filterModel: WorkbenchCacheItem['filterModel']) => void;
  clearFilterModel: (functionCode: string, params: string) => void;
  getColumnState: (functionCode: string, params: string) => WorkbenchCacheItem['columnState'];
  setColumnState: (functionCode: string, params: string, columnState: WorkbenchCacheItem['columnState']) => void;
  clearColumnState: (functionCode: string, params: string) => void;
  getPage: (functionCode: string, params: string) => WorkbenchCacheItem['page'];
  setPage: (functionCode: string, params: string, currentPage: WorkbenchCacheItem['page']) => void;
  getPageSize: (functionCode: string, params: string) => WorkbenchCacheItem['pageSize'];
  setPageSize: (functionCode: string, params: string, currentPageSize: WorkbenchCacheItem['pageSize']) => void;
  getSelectedRows: (functionCode: string, params: string) => WorkbenchCacheItem['selectedRows'];
  setSelectedRows: (functionCode: string, params: string, selectedRows: WorkbenchCacheItem['selectedRows']) => void;
  getVisibleColumns: (functionCode: string, params: string) => WorkbenchCacheItem['visibleColumns'];
  setVisibleColumns: (functionCode: string, params: string, visibleColumns: WorkbenchCacheItem['visibleColumns']) => void;
  getPinColumns: (functionCode: string, params: string) => WorkbenchCacheItem['pinColumns'];
  setPinColumns: (functionCode: string, params: string, pinColumns: WorkbenchCacheItem['pinColumns']) => void;
  getUIState: (functionCode: string, params: string) => WorkbenchCacheItem['uiState'] | null;
  setUIState: (functionCode: string, params: string, uiState: Partial<WorkbenchCacheItem['uiState']>) => void;
}

interface WorkbenchGridStateOptions {
  getMeta: () => WorkbenchMetaLike;
  getCacheScopeKey: () => string;
  gridApi: Ref<GridApi<Api.Workbench.QueryRecord> | null>;
  pageMeta: Ref<Api.Workbench.PageMeta | null>;
  page: Ref<number>;
  pageSize: Ref<number>;
  defaultPageSize: number;
  conditionVisible: Ref<boolean>;
  fieldColumnVisible: Ref<boolean>;
  pinColumnVisible: Ref<boolean>;
  quickKeyword: Ref<string>;
  selectedField: Ref<string>;
  selectedOperator: Ref<ConditionOperator>;
  selectedValue: Ref<string>;
  visibleFieldColumns: Ref<string[]>;
  pinTargetFields: Ref<string[]>;
  isRestoringFilter: Ref<boolean>;
  isRestoringColumnState: Ref<boolean>;
  isRestoringSelection: Ref<boolean>;
  isRestoringPage: Ref<boolean>;
  isInitialLoading: Ref<boolean>;
  isGridShellVisible: () => boolean;
  hasSuspiciousNarrowColumnState: (columnState: any[]) => boolean;
}

export function useWorkbenchGridState(options: WorkbenchGridStateOptions) {
  const rawWorkbenchStore = useWorkbenchStore();

  function getFunctionCode() {
    return String(options.getMeta().functionCode || '').trim();
  }

  function getParams() {
    return String(options.getMeta().params || '').trim();
  }

  const workbenchStore: WorkbenchStore = {
    getCache: (functionCode: string, params: string) =>
      rawWorkbenchStore.getCache(functionCode, params, options.getCacheScopeKey()),
    setCache: (functionCode: string, params: string, data) =>
      rawWorkbenchStore.setCache(functionCode, params, data, options.getCacheScopeKey()),
    clearCache: (functionCode: string, params: string) =>
      rawWorkbenchStore.clearCache(functionCode, params, options.getCacheScopeKey()),
    getFilterModel: (functionCode: string, params: string) =>
      rawWorkbenchStore.getFilterModel(functionCode, params, options.getCacheScopeKey()),
    setFilterModel: (functionCode: string, params: string, filterModel) =>
      rawWorkbenchStore.setFilterModel(functionCode, params, filterModel, options.getCacheScopeKey()),
    clearFilterModel: (functionCode: string, params: string) =>
      rawWorkbenchStore.clearFilterModel(functionCode, params, options.getCacheScopeKey()),
    getColumnState: (functionCode: string, params: string) =>
      rawWorkbenchStore.getColumnState(functionCode, params, options.getCacheScopeKey()),
    setColumnState: (functionCode: string, params: string, columnState) =>
      rawWorkbenchStore.setColumnState(functionCode, params, columnState, options.getCacheScopeKey()),
    clearColumnState: (functionCode: string, params: string) =>
      rawWorkbenchStore.clearColumnState(functionCode, params, options.getCacheScopeKey()),
    getPage: (functionCode: string, params: string) =>
      rawWorkbenchStore.getPage(functionCode, params, options.getCacheScopeKey()),
    setPage: (functionCode: string, params: string, currentPage) =>
      rawWorkbenchStore.setPage(functionCode, params, currentPage, options.getCacheScopeKey()),
    getPageSize: (functionCode: string, params: string) =>
      rawWorkbenchStore.getPageSize(functionCode, params, options.getCacheScopeKey()),
    setPageSize: (functionCode: string, params: string, currentPageSize) =>
      rawWorkbenchStore.setPageSize(functionCode, params, currentPageSize, options.getCacheScopeKey()),
    getSelectedRows: (functionCode: string, params: string) =>
      rawWorkbenchStore.getSelectedRows(functionCode, params, options.getCacheScopeKey()),
    setSelectedRows: (functionCode: string, params: string, selectedRows) =>
      rawWorkbenchStore.setSelectedRows(functionCode, params, selectedRows, options.getCacheScopeKey()),
    getVisibleColumns: (functionCode: string, params: string) =>
      rawWorkbenchStore.getVisibleColumns(functionCode, params, options.getCacheScopeKey()),
    setVisibleColumns: (functionCode: string, params: string, visibleColumns) =>
      rawWorkbenchStore.setVisibleColumns(functionCode, params, visibleColumns, options.getCacheScopeKey()),
    getPinColumns: (functionCode: string, params: string) =>
      rawWorkbenchStore.getPinColumns(functionCode, params, options.getCacheScopeKey()),
    setPinColumns: (functionCode: string, params: string, pinColumns) => {
      rawWorkbenchStore.setPinColumns(functionCode, params, pinColumns, options.getCacheScopeKey());
    },
    getUIState: (functionCode: string, params: string) =>
      rawWorkbenchStore.getUIState(functionCode, params, options.getCacheScopeKey()),
    setUIState: (functionCode: string, params: string, uiState) =>
      rawWorkbenchStore.setUIState(functionCode, params, uiState, options.getCacheScopeKey())
  };

  let isRestoringState = false;
  let lastRestoreKey = '';

  function getRestoreKey() {
    return `${getFunctionCode()}_${getParams()}`;
  }

  function restoreSelection() {
    if (!options.gridApi.value || options.gridApi.value.isDestroyed()) {
      return;
    }

    const functionCode = getFunctionCode();
    const params = getParams();
    const cachedSelectedRows = workbenchStore.getSelectedRows(functionCode, params);

    if (!cachedSelectedRows.length || cachedSelectedRows.length > 10) {
      return;
    }

    options.isRestoringSelection.value = true;

    const rowsToRestore = cachedSelectedRows.slice(0, 10);
    const guidSet = new Set(rowsToRestore.filter((r: any) => r.GUID).map((r: any) => r.GUID));
    const idSet = new Set(rowsToRestore.filter((r: any) => r.id).map((r: any) => r.id));

    options.gridApi.value.forEachNode(node => {
      const rowData = node.data;
      if (!rowData) return;
      const isSelected = (rowData.GUID && guidSet.has(rowData.GUID)) || (rowData.id && idSet.has(rowData.id));
      if (isSelected) {
        node.setSelected(true);
      }
    });

    options.isRestoringSelection.value = false;
  }

  function restoreGridStateOnActivated() {
    const restoreStart = performance.now();
    const currentKey = getRestoreKey();

    if (!options.gridApi.value || options.gridApi.value.isDestroyed()) {
      return;
    }

    if (isRestoringState) {
      return;
    }

    // 每次激活都尝试恢复行选择状态（不受 lastRestoreKey 限制，
    // 避免首次激活时 lastRestoreKey 被设置后，切换标签页回来无法恢复新选中的行）
    restoreSelection();

    if (lastRestoreKey === currentKey) {
      return;
    }

    isRestoringState = true;
    lastRestoreKey = currentKey;

    const functionCode = getFunctionCode();
    const params = getParams();

    const cachedUIState = workbenchStore.getUIState(functionCode, params);
    if (cachedUIState) {
      options.conditionVisible.value = cachedUIState.conditionVisible;
      options.fieldColumnVisible.value = cachedUIState.fieldColumnVisible;
      options.pinColumnVisible.value = cachedUIState.pinColumnVisible;
      options.quickKeyword.value = cachedUIState.quickKeyword;
      options.selectedField.value = cachedUIState.selectedField;
      options.selectedOperator.value = cachedUIState.selectedOperator as ConditionOperator;
      options.selectedValue.value = cachedUIState.selectedValue;
    }

    const cachedFilterModel = workbenchStore.getFilterModel(functionCode, params);
    const cachedColumnState = workbenchStore.getColumnState(functionCode, params);
    const cachedPage = workbenchStore.getPage(functionCode, params);
    const cachedPageSize = workbenchStore.getPageSize(functionCode, params);

    const hasFilter = cachedFilterModel && Object.keys(cachedFilterModel).length > 0;
    const hasColumnState = cachedColumnState && Array.isArray(cachedColumnState) && cachedColumnState.length > 0;
    const hasPageChange = cachedPage > 1 || cachedPageSize !== options.defaultPageSize;

    if (hasColumnState && options.pageMeta.value?.columns) {
      const colStateMap = new Map(cachedColumnState.map((c: any) => [c.colId, c]));
      options.pageMeta.value.columns = options.pageMeta.value.columns.map(column => {
        // GUID 列恒隐藏：忽略缓存，恢复时强制 hidden=true
        const isGuidColumn =
          String(column.field || '')
            .trim()
            .toUpperCase() === 'GUID' ||
          String(column.title || '')
            .trim()
            .toUpperCase() === 'GUID';
        if (isGuidColumn) {
          return { ...column, hidden: true };
        }
        const colState = colStateMap.get(column.field);
        if (colState && colState.hide !== undefined) {
          return { ...column, hidden: colState.hide };
        }
        return column;
      });
    }

    if (hasFilter) {
      options.isRestoringFilter.value = true;
    }

    if (hasPageChange) {
      options.isRestoringPage.value = true;
      options.page.value = cachedPage;
      options.pageSize.value = cachedPageSize;
    }

    let pendingOperations = 0;
    let completedOperations = 0;

    const markOperationComplete = () => {
      completedOperations++;
      if (completedOperations >= pendingOperations) {
        finishRestore(restoreStart);
      }
    };

    const finishRestore = (_startTime: number) => {
      setTimeout(() => {
        options.isRestoringPage.value = false;
        options.isRestoringFilter.value = false;
        options.isRestoringSelection.value = false;
        options.isRestoringColumnState.value = false;
        isRestoringState = false;
      }, 100);
    };

    if (hasFilter) {
      pendingOperations++;
      queueMicrotask(() => {
        if (options.gridApi.value && !options.gridApi.value.isDestroyed()) {
          options.gridApi.value.setFilterModel(cachedFilterModel);
        }
        options.isRestoringFilter.value = false;
        markOperationComplete();
      });
    }

    if (hasColumnState) {
      pendingOperations++;
      queueMicrotask(() => {
        if (!options.gridApi.value || options.gridApi.value.isDestroyed()) {
          markOperationComplete();
          return;
        }

        const cachedPinColumns = workbenchStore.getPinColumns(functionCode, params);
        const pinColumnsArray = Array.from(cachedPinColumns);

        const mergedColumnState = cachedColumnState.map((col: any) => {
          if (pinColumnsArray.includes(col.colId)) {
            return { ...col, pinned: 'left' };
          }
          return col;
        });

        options.isRestoringColumnState.value = true;
        options.gridApi.value.applyColumnState({ state: mergedColumnState, applyOrder: true });

        const cachedVisibleColumns = workbenchStore.getVisibleColumns(functionCode, params);
        if (cachedVisibleColumns.length > 0) {
          options.visibleFieldColumns.value = cachedVisibleColumns;
        }

        if (cachedPinColumns.length > 0) {
          options.pinTargetFields.value = cachedPinColumns;
        }

        setTimeout(() => {
          options.isRestoringColumnState.value = false;
          markOperationComplete();
        }, 100);
      });
    }

    if (pendingOperations === 0) {
      finishRestore(restoreStart);
    }
  }

  function persistGridStateOnDeactivated() {
    const functionCode = getFunctionCode();
    const params = getParams();

    if (!functionCode) return;

    workbenchStore.setUIState(functionCode, params, {
      conditionVisible: options.conditionVisible.value,
      fieldColumnVisible: options.fieldColumnVisible.value,
      pinColumnVisible: options.pinColumnVisible.value,
      quickKeyword: options.quickKeyword.value,
      selectedField: options.selectedField.value,
      selectedOperator: options.selectedOperator.value,
      selectedValue: options.selectedValue.value
    });

    workbenchStore.setPage(functionCode, params, options.page.value);
    workbenchStore.setPageSize(functionCode, params, options.pageSize.value);

    if (!options.gridApi.value || options.gridApi.value.isDestroyed()) {
      return;
    }

    const selectedRows = options.gridApi.value.getSelectedRows();
    workbenchStore.setSelectedRows(functionCode, params, selectedRows);

    const columnState = options.gridApi.value.getColumnState();
    if (
      options.isGridShellVisible() &&
      !options.hasSuspiciousNarrowColumnState(columnState) &&
      columnState &&
      Array.isArray(columnState) &&
      columnState.length > 0
    ) {
      workbenchStore.setColumnState(functionCode, params, columnState);
    }

    const allColumns = options.gridApi.value.getColumns();
    if (!allColumns) {
      return;
    }

    const visibleCols = allColumns
      .filter(col => {
        const colDef = col.getColDef();
        const colId = col.getColId();
        // 包括有 field 的列，以及 checkbox 选择列
        return !colDef.hide && (colDef.field || colId === 'ag-Grid-SelectionColumn');
      })
      .map(col => {
        const colDef = col.getColDef();
        // 对于 checkbox 列使用 colId，否则使用 field
        return (colDef.field || col.getColId()) as string;
      })
      .filter((field): field is string => field !== undefined);
    workbenchStore.setVisibleColumns(functionCode, params, visibleCols);
  }

  function registerGridPersistenceListeners() {
    if (!options.gridApi.value) {
      return;
    }

    const capturedFunctionCode = getFunctionCode();
    const capturedParams = getParams();
    const api = options.gridApi.value;

    api.addEventListener('filterChanged', () => {
      if (options.isRestoringFilter.value) {
        return;
      }
      if (capturedFunctionCode && options.gridApi.value) {
        const currentFilterModel = options.gridApi.value.getFilterModel();
        if (currentFilterModel && Object.keys(currentFilterModel).length > 0) {
          workbenchStore.setFilterModel(capturedFunctionCode, capturedParams, currentFilterModel);
        }
      }
    });

    api.addEventListener('sortChanged', () => {
      if (options.isRestoringColumnState.value) {
        return;
      }
      if (capturedFunctionCode && options.gridApi.value) {
        const columnState = options.gridApi.value.getColumnState();
        if (columnState && Array.isArray(columnState) && columnState.length > 0) {
          workbenchStore.setColumnState(capturedFunctionCode, capturedParams, columnState);
        }
      }
    });

    api.addEventListener('columnResized', (colEvent: any) => {
      if (options.isRestoringColumnState.value || options.isInitialLoading.value) {
        return;
      }
      if (colEvent?.finished === false) {
        return;
      }
      const resizeSource = String(colEvent?.source || '');
      if (resizeSource === 'gridSizeChanged' || resizeSource === 'sizeColumnsToFit' || resizeSource === 'api') {
        return;
      }
      if (!options.isGridShellVisible()) {
        return;
      }
      if (capturedFunctionCode && options.gridApi.value) {
        const columnState = options.gridApi.value.getColumnState();
        if (options.hasSuspiciousNarrowColumnState(columnState)) {
          return;
        }
        const currentSortedCols = columnState.filter((col: any) => col.sort);
        const cachedColumnState = workbenchStore.getColumnState(capturedFunctionCode, capturedParams);
        const cachedSortedCols = cachedColumnState?.filter((col: any) => col.sort) || [];
        if (currentSortedCols.length === 0 && cachedSortedCols.length > 0) {
          return;
        }
        if (columnState && Array.isArray(columnState) && columnState.length > 0) {
          workbenchStore.setColumnState(capturedFunctionCode, capturedParams, columnState);
        }
      }
    });

    api.addEventListener('columnVisible', (_colEvent: any) => {
      if (options.isRestoringColumnState.value) {
        return;
      }
      if (capturedFunctionCode && options.gridApi.value) {
        const columnState = options.gridApi.value.getColumnState();
        if (columnState && Array.isArray(columnState) && columnState.length > 0) {
          workbenchStore.setColumnState(capturedFunctionCode, capturedParams, columnState);
        }

        const allColumns = options.gridApi.value.getColumns();
        if (allColumns) {
          const visibleCols = allColumns
            .filter(col => {
              const colDef = col.getColDef();
              const colId = col.getColId();
              return !colDef.hide && (colDef.field || colId === 'ag-Grid-SelectionColumn');
            })
            .map(col => {
              const colDef = col.getColDef();
              return (colDef.field || col.getColId()) as string;
            })
            .filter((field): field is string => field !== undefined);
          workbenchStore.setVisibleColumns(capturedFunctionCode, capturedParams, visibleCols);
        }
      }
    });

    api.addEventListener('dragStopped', () => {
      if (options.isRestoringColumnState.value) {
        return;
      }
      if (capturedFunctionCode && options.gridApi.value) {
        const columnState = options.gridApi.value.getColumnState();
        if (columnState && Array.isArray(columnState) && columnState.length > 0) {
          workbenchStore.setColumnState(capturedFunctionCode, capturedParams, columnState);
        }
      }
    });

    api.addEventListener('columnPinned', (_event: any) => {
      if (options.isRestoringColumnState.value) {
        return;
      }
      if (capturedFunctionCode && options.gridApi.value) {
        const columnState = options.gridApi.value.getColumnState();
        if (columnState && Array.isArray(columnState)) {
          const pinnedCols = columnState
            .filter((col: any) => col.pinned)
            .map((col: any) => col.colId)
            .filter((colId: string) => colId !== undefined);
          workbenchStore.setPinColumns(capturedFunctionCode, capturedParams, pinnedCols);
        }
      }
    });

    api.addEventListener('selectionChanged', () => {
      if (options.isRestoringSelection.value) {
        return;
      }
      if (capturedFunctionCode && options.gridApi.value) {
        const selectedRows = options.gridApi.value.getSelectedRows();
        workbenchStore.setSelectedRows(capturedFunctionCode, capturedParams, selectedRows);
      }
    });

    api.addEventListener('paginationChanged', () => {
      if (options.isRestoringPage.value) {
        return;
      }
      if (capturedFunctionCode && options.gridApi.value) {
        const currentPage = options.gridApi.value.paginationGetCurrentPage() + 1;
        const currentPageSize = options.gridApi.value.paginationGetPageSize();
        workbenchStore.setPage(capturedFunctionCode, capturedParams, currentPage);
        workbenchStore.setPageSize(capturedFunctionCode, capturedParams, currentPageSize);
      }
    });
  }

  onActivated(restoreGridStateOnActivated);
  onDeactivated(persistGridStateOnDeactivated);
  onUnmounted(persistGridStateOnDeactivated);

  return {
    workbenchStore,
    registerGridPersistenceListeners
  };
}
