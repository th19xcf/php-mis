import { nextTick, onActivated, onDeactivated } from 'vue';
import type { Ref } from 'vue';
import type { GridApi } from 'ag-grid-community';
import { useWorkbenchStore } from '@/store/modules/workbench';

type ConditionOperator = 'contains' | 'equals' | 'startsWith';

interface WorkbenchMetaLike {
  functionCode?: string;
  params?: string;
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

  const workbenchStore = {
    getCache: (functionCode: string, params: string) =>
      rawWorkbenchStore.getCache(functionCode, params, options.getCacheScopeKey()),
    setCache: (functionCode: string, params: string, data: Partial<any>) =>
      rawWorkbenchStore.setCache(functionCode, params, data, options.getCacheScopeKey()),
    clearCache: (functionCode: string, params: string) =>
      rawWorkbenchStore.clearCache(functionCode, params, options.getCacheScopeKey()),
    getFilterModel: (functionCode: string, params: string) =>
      rawWorkbenchStore.getFilterModel(functionCode, params, options.getCacheScopeKey()),
    setFilterModel: (functionCode: string, params: string, filterModel: any) =>
      rawWorkbenchStore.setFilterModel(functionCode, params, filterModel, options.getCacheScopeKey()),
    clearFilterModel: (functionCode: string, params: string) =>
      rawWorkbenchStore.clearFilterModel(functionCode, params, options.getCacheScopeKey()),
    getColumnState: (functionCode: string, params: string) =>
      rawWorkbenchStore.getColumnState(functionCode, params, options.getCacheScopeKey()),
    setColumnState: (functionCode: string, params: string, columnState: any) =>
      rawWorkbenchStore.setColumnState(functionCode, params, columnState, options.getCacheScopeKey()),
    clearColumnState: (functionCode: string, params: string) =>
      rawWorkbenchStore.clearColumnState(functionCode, params, options.getCacheScopeKey()),
    getPage: (functionCode: string, params: string) =>
      rawWorkbenchStore.getPage(functionCode, params, options.getCacheScopeKey()),
    setPage: (functionCode: string, params: string, currentPage: number) =>
      rawWorkbenchStore.setPage(functionCode, params, currentPage, options.getCacheScopeKey()),
    getPageSize: (functionCode: string, params: string) =>
      rawWorkbenchStore.getPageSize(functionCode, params, options.getCacheScopeKey()),
    setPageSize: (functionCode: string, params: string, currentPageSize: number) =>
      rawWorkbenchStore.setPageSize(functionCode, params, currentPageSize, options.getCacheScopeKey()),
    getSelectedRows: (functionCode: string, params: string) =>
      rawWorkbenchStore.getSelectedRows(functionCode, params, options.getCacheScopeKey()),
    setSelectedRows: (functionCode: string, params: string, selectedRows: any[]) =>
      rawWorkbenchStore.setSelectedRows(functionCode, params, selectedRows, options.getCacheScopeKey()),
    getVisibleColumns: (functionCode: string, params: string) =>
      rawWorkbenchStore.getVisibleColumns(functionCode, params, options.getCacheScopeKey()),
    setVisibleColumns: (functionCode: string, params: string, visibleColumns: string[]) =>
      rawWorkbenchStore.setVisibleColumns(functionCode, params, visibleColumns, options.getCacheScopeKey()),
    getPinColumns: (functionCode: string, params: string) =>
      rawWorkbenchStore.getPinColumns(functionCode, params, options.getCacheScopeKey()),
    setPinColumns: (functionCode: string, params: string, pinColumns: string[]) =>
      rawWorkbenchStore.setPinColumns(functionCode, params, pinColumns, options.getCacheScopeKey()),
    getUIState: (functionCode: string, params: string) =>
      rawWorkbenchStore.getUIState(functionCode, params, options.getCacheScopeKey()),
    setUIState: (functionCode: string, params: string, uiState: any) =>
      rawWorkbenchStore.setUIState(functionCode, params, uiState, options.getCacheScopeKey())
  };

  // 用于防止重复触发恢复操作
  let isRestoringState = false;

  function restoreGridStateOnActivated() {
    if (!options.gridApi.value || options.gridApi.value.isDestroyed()) {
      return;
    }

    // 防止重复触发
    if (isRestoringState) {
      return;
    }
    isRestoringState = true;

    const functionCode = getFunctionCode();
    const params = getParams();

    console.log('[onActivated] Restoring grid state from cache for:', functionCode);

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
    const cachedSelectedRows = workbenchStore.getSelectedRows(functionCode, params);

    // 合并所有需要 nextTick 的操作到一个回调中
    const hasFilter = cachedFilterModel && Object.keys(cachedFilterModel).length > 0;
    const hasColumnState = cachedColumnState && Array.isArray(cachedColumnState) && cachedColumnState.length > 0;
    const hasPageChange = cachedPage > 1 || cachedPageSize !== options.defaultPageSize;
    const hasSelection = cachedSelectedRows.length > 0;

    if (hasColumnState) {
      if (options.pageMeta.value?.columns) {
        // 使用 Map 优化查找性能 O(n) -> O(1)
        const colStateMap = new Map(cachedColumnState.map((c: any) => [c.colId, c]));
        options.pageMeta.value.columns = options.pageMeta.value.columns.map(column => {
          const colState = colStateMap.get(column.field);
          if (colState && colState.hide !== undefined) {
            return { ...column, hidden: colState.hide };
          }
          return column;
        });
      }
      options.isRestoringColumnState.value = true;
    }

    if (hasFilter) {
      options.isRestoringFilter.value = true;
    }

    if (hasPageChange) {
      options.isRestoringPage.value = true;
      options.page.value = cachedPage;
      options.pageSize.value = cachedPageSize;
    }

    if (hasSelection) {
      options.isRestoringSelection.value = true;
    }

    // 合并为一个 nextTick 回调，减少渲染次数
    nextTick(() => {
      if (!options.gridApi.value || options.gridApi.value.isDestroyed()) {
        isRestoringState = false;
        return;
      }

      // 恢复筛选
      if (hasFilter) {
        options.gridApi.value.setFilterModel(cachedFilterModel);
        options.isRestoringFilter.value = false;
      }

      // 恢复列状态
      if (hasColumnState) {
        options.gridApi.value.applyColumnState({ state: cachedColumnState, applyOrder: true });

        const cachedVisibleColumns = workbenchStore.getVisibleColumns(functionCode, params);
        if (cachedVisibleColumns.length > 0) {
          options.visibleFieldColumns.value = cachedVisibleColumns;
        }

        const cachedPinColumns = workbenchStore.getPinColumns(functionCode, params);
        if (cachedPinColumns.length > 0) {
          options.pinTargetFields.value = cachedPinColumns;
        }
      }

      // 恢复行选择 - 跳过大数据量的恢复以避免性能问题
      if (hasSelection && cachedSelectedRows.length <= 10) {
        // 只恢复前10条选中记录
        const rowsToRestore = cachedSelectedRows.slice(0, 10);
        // 使用 Set 优化查找性能
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
      }
      options.isRestoringSelection.value = false;

      // 延迟重置标志
      setTimeout(() => {
        options.isRestoringColumnState.value = false;
        options.isRestoringPage.value = false;
        isRestoringState = false;
      }, 100);
    });
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
    if (options.isGridShellVisible() && !options.hasSuspiciousNarrowColumnState(columnState) && columnState && Array.isArray(columnState) && columnState.length > 0) {
      workbenchStore.setColumnState(functionCode, params, columnState);
    }

    const allColumns = options.gridApi.value.getColumns();
    if (!allColumns) {
      return;
    }

    const visibleCols = allColumns
      .filter(col => {
        const colDef = col.getColDef();
        return !colDef.hide && colDef.field;
      })
      .map(col => col.getColDef().field as string)
      .filter((field): field is string => field !== undefined);
    workbenchStore.setVisibleColumns(functionCode, params, visibleCols);

    const pinnedCols = allColumns
      .filter(col => {
        const colDef = col.getColDef();
        return colDef.pinned && colDef.field;
      })
      .map(col => col.getColDef().field as string)
      .filter((field): field is string => field !== undefined);
    workbenchStore.setPinColumns(functionCode, params, pinnedCols);
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

    api.addEventListener('columnVisible', (colEvent: any) => {
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
              return !colDef.hide && colDef.field;
            })
            .map(col => col.getColDef().field as string)
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

    api.addEventListener('columnPinned', () => {
      if (options.isRestoringColumnState.value) {
        return;
      }
      if (capturedFunctionCode && options.gridApi.value) {
        const allColumns = options.gridApi.value.getColumns();
        if (allColumns) {
          const pinnedCols = allColumns
            .filter(col => {
              const colDef = col.getColDef();
              return colDef.pinned && colDef.field;
            })
            .map(col => col.getColDef().field as string)
            .filter((field): field is string => field !== undefined);
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

  return {
    workbenchStore,
    registerGridPersistenceListeners
  };
}