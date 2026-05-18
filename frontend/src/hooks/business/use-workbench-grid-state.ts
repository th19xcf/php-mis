import { onActivated, onDeactivated } from 'vue';
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
    setPinColumns: (functionCode: string, params: string, pinColumns: string[]) => {
      rawWorkbenchStore.setPinColumns(functionCode, params, pinColumns, options.getCacheScopeKey());
    },
    getUIState: (functionCode: string, params: string) =>
      rawWorkbenchStore.getUIState(functionCode, params, options.getCacheScopeKey()),
    setUIState: (functionCode: string, params: string, uiState: any) =>
      rawWorkbenchStore.setUIState(functionCode, params, uiState, options.getCacheScopeKey())
  };

  // 用于防止重复触发恢复操作
  let isRestoringState = false;

  function restoreGridStateOnActivated() {
    const restoreStart = performance.now();
    console.log(`[🔄 grid-state] restoreGridStateOnActivated 开始, functionCode=${getFunctionCode()}, 时间: ${restoreStart.toFixed(1)}ms`);

    if (!options.gridApi.value || options.gridApi.value.isDestroyed()) {
      console.log(`[🔄 grid-state] restoreGridStateOnActivated 跳过: gridApi 不可用`);
      return;
    }

    // 防止重复触发
    if (isRestoringState) {
      console.log(`[🔄 grid-state] restoreGridStateOnActivated 跳过: 正在恢复中`);
      return;
    }
    isRestoringState = true;

    const functionCode = getFunctionCode();
    const params = getParams();

    // 第一步：恢复 UI 状态（立即执行，不阻塞）
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

    const hasFilter = cachedFilterModel && Object.keys(cachedFilterModel).length > 0;
    const hasColumnState = cachedColumnState && Array.isArray(cachedColumnState) && cachedColumnState.length > 0;
    const hasPageChange = cachedPage > 1 || cachedPageSize !== options.defaultPageSize;
    const hasSelection = cachedSelectedRows.length > 0;

    // 预更新列隐藏状态（不触发重绘）
    if (hasColumnState && options.pageMeta.value?.columns) {
      const colStateMap = new Map(cachedColumnState.map((c: any) => [c.colId, c]));
      options.pageMeta.value.columns = options.pageMeta.value.columns.map(column => {
        const colState = colStateMap.get(column.field);
        if (colState && colState.hide !== undefined) {
          return { ...column, hidden: colState.hide };
        }
        return column;
      });
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

    // 使用 requestAnimationFrame 分帧执行，避免阻塞主线程
    requestAnimationFrame(() => {
      console.log(`[🔄 grid-state] Frame1: 恢复筛选, hasFilter=${hasFilter}, 时间: ${performance.now().toFixed(1)}ms, 距开始: ${(performance.now() - restoreStart).toFixed(1)}ms`);
      if (!options.gridApi.value || options.gridApi.value.isDestroyed()) {
        isRestoringState = false;
        return;
      }

      // 第一帧：恢复筛选（优先级较高）
      if (hasFilter) {
        options.gridApi.value.setFilterModel(cachedFilterModel);
        options.isRestoringFilter.value = false;
      }

      // 第二帧：恢复列状态（可能触发重绘，延迟执行）
      requestAnimationFrame(() => {
        console.log(`[🔄 grid-state] Frame2: 恢复列状态, hasColumnState=${hasColumnState}, 时间: ${performance.now().toFixed(1)}ms, 距开始: ${(performance.now() - restoreStart).toFixed(1)}ms`);
        if (!options.gridApi.value || options.gridApi.value.isDestroyed()) {
          isRestoringState = false;
          return;
        }

        if (hasColumnState) {
          // 延迟应用列状态，避免与数据加载冲突
          setTimeout(() => {
            if (options.gridApi.value && !options.gridApi.value.isDestroyed()) {
              // 确保标志位正确设置，防止 columnPinned 事件覆盖
              options.isRestoringColumnState.value = true;

              // 获取固定列信息并合并到 columnState 中
              const cachedPinColumns = workbenchStore.getPinColumns(functionCode, params);
              // 将 Proxy 数组转换为普通数组
              const pinColumnsArray = Array.from(cachedPinColumns);

              // 将固定列信息合并到 columnState 中
              const mergedColumnState = cachedColumnState.map((col: any) => {
                if (pinColumnsArray.includes(col.colId)) {
                  return { ...col, pinned: 'left' };
                }
                return col;
              });

              options.gridApi.value.applyColumnState({ state: mergedColumnState, applyOrder: true });

              const cachedVisibleColumns = workbenchStore.getVisibleColumns(functionCode, params);
              if (cachedVisibleColumns.length > 0) {
                options.visibleFieldColumns.value = cachedVisibleColumns;
              }

              if (cachedPinColumns.length > 0) {
                options.pinTargetFields.value = cachedPinColumns;
              }

              // 延迟重置标志，确保所有列状态事件处理完毕
              setTimeout(() => {
                options.isRestoringColumnState.value = false;
              }, 100);
            }
          }, 50);
        }

        // 第三帧：恢复行选择（最低优先级）
        requestAnimationFrame(() => {
          console.log(`[🔄 grid-state] Frame3: 恢复行选择, hasSelection=${hasSelection}, 时间: ${performance.now().toFixed(1)}ms, 距开始: ${(performance.now() - restoreStart).toFixed(1)}ms`);
          if (!options.gridApi.value || options.gridApi.value.isDestroyed()) {
            isRestoringState = false;
            return;
          }

          // 恢复行选择 - 只恢复前10条选中记录，避免大数据量下的性能问题
          if (hasSelection && cachedSelectedRows.length <= 10) {
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
          }
          options.isRestoringSelection.value = false;

          // 延迟重置所有标志（注意：isRestoringColumnState 已在上面重置）
          setTimeout(() => {
            options.isRestoringPage.value = false;
            isRestoringState = false;
            console.log(`[🔄 grid-state] restoreGridStateOnActivated 完成, 总耗时: ${(performance.now() - restoreStart).toFixed(1)}ms`);
          }, 100);
        });
      });
    });
  }

  function persistGridStateOnDeactivated() {
    const deactStart = performance.now();
    const functionCode = getFunctionCode();
    console.log(`[🔄 grid-state] persistGridStateOnDeactivated 开始, functionCode=${functionCode}, 时间: ${deactStart.toFixed(1)}ms`);
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
        return !colDef.hide && colDef.field;
      })
      .map(col => col.getColDef().field as string)
      .filter((field): field is string => field !== undefined);
    workbenchStore.setVisibleColumns(functionCode, params, visibleCols);

    // 注意：不在这里保存 pinColumns，因为 getColumnState() 不包含 pinned 信息
    // pinColumns 已由 columnPinned 事件监听器正确保存
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

    api.addEventListener('columnPinned', (_event: any) => {
      if (options.isRestoringColumnState.value) {
        return;
      }
      if (capturedFunctionCode && options.gridApi.value) {
        // 使用 getColumnState() 获取固定列信息，而不是 getColumns()
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

  return {
    workbenchStore,
    registerGridPersistenceListeners
  };
}
