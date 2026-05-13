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

  function restoreGridStateOnActivated() {
    if (!options.gridApi.value || options.gridApi.value.isDestroyed()) {
      return;
    }

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
    if (cachedFilterModel && Object.keys(cachedFilterModel).length > 0) {
      options.isRestoringFilter.value = true;
      nextTick(() => {
        if (options.gridApi.value && !options.gridApi.value.isDestroyed()) {
          options.gridApi.value.setFilterModel(cachedFilterModel);
        }
        options.isRestoringFilter.value = false;
      });
    }

    const cachedColumnState = workbenchStore.getColumnState(functionCode, params);
    console.log('[onActivated] Restoring column state for:', functionCode, 'state count:', cachedColumnState?.length);
    if (cachedColumnState && Array.isArray(cachedColumnState) && cachedColumnState.length > 0) {
      console.log(
        '[onActivated] Column hide states:',
        cachedColumnState.map((c: any) => ({ colId: c.colId, hide: c.hide }))
      );
      const sortedColumns = cachedColumnState.filter((col: any) => col.sort);
      console.log('[onActivated] Sorted columns in cache:', sortedColumns);

      if (options.pageMeta.value?.columns) {
        console.log(
          '[onActivated] pageMeta columns fields:',
          options.pageMeta.value.columns.map(c => c.field)
        );
        console.log(
          '[onActivated] cachedColumnState colIds:',
          cachedColumnState.map((c: any) => ({ colId: c.colId, hide: c.hide }))
        );

        options.pageMeta.value.columns = options.pageMeta.value.columns.map(column => {
          const colState = cachedColumnState.find((c: any) => c.colId === column.field);
          if (colState && colState.hide !== undefined) {
            console.log(`[onActivated] Updating column ${column.field}: hidden = ${colState.hide}`);
            return { ...column, hidden: colState.hide };
          }
          return column;
        });
        console.log('[onActivated] Updated pageMeta columns hidden state');
      }

      options.isRestoringColumnState.value = true;
      nextTick(() => {
        if (options.gridApi.value && !options.gridApi.value.isDestroyed()) {
          console.log('[onActivated] Applying column state:', cachedColumnState);
          options.gridApi.value.applyColumnState({ state: cachedColumnState, applyOrder: true });
          const afterApply = options.gridApi.value.getColumnState();
          const afterSorted = afterApply.filter((col: any) => col.sort);
          console.log('[onActivated] Sorted columns after apply:', afterSorted);

          const cachedVisibleColumns = workbenchStore.getVisibleColumns(functionCode, params);
          if (cachedVisibleColumns.length > 0) {
            options.visibleFieldColumns.value = cachedVisibleColumns;
          }

          const cachedPinColumns = workbenchStore.getPinColumns(functionCode, params);
          if (cachedPinColumns.length > 0) {
            options.pinTargetFields.value = cachedPinColumns;
          }
        }

        setTimeout(() => {
          options.isRestoringColumnState.value = false;
          console.log('[onActivated] Column state restore completed for:', functionCode);
        }, 500);
      });
    }

    const cachedPage = workbenchStore.getPage(functionCode, params);
    const cachedPageSize = workbenchStore.getPageSize(functionCode, params);
    if (cachedPage > 1 || cachedPageSize !== options.defaultPageSize) {
      options.isRestoringPage.value = true;
      options.page.value = cachedPage;
      options.pageSize.value = cachedPageSize;
      nextTick(() => {
        options.isRestoringPage.value = false;
      });
    }

    const cachedSelectedRows = workbenchStore.getSelectedRows(functionCode, params);
    if (cachedSelectedRows.length > 0 && options.gridApi.value) {
      options.isRestoringSelection.value = true;
      nextTick(() => {
        if (options.gridApi.value && !options.gridApi.value.isDestroyed()) {
          options.gridApi.value.forEachNode(node => {
            const rowData = node.data;
            if (!rowData) return;
            const isSelected = cachedSelectedRows.some((cachedRow: any) => {
              return (rowData.GUID && cachedRow.GUID === rowData.GUID) || (rowData.id && cachedRow.id === rowData.id);
            });
            node.setSelected(isSelected);
          });
        }
        options.isRestoringSelection.value = false;
      });
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
    if (!options.isGridShellVisible()) {
      console.log('[onDeactivated] Skip saving column state, grid shell is hidden for:', functionCode);
    } else if (options.hasSuspiciousNarrowColumnState(columnState)) {
      console.log('[onDeactivated] Skip suspicious narrow column state for:', functionCode);
    } else if (columnState && Array.isArray(columnState) && columnState.length > 0) {
      console.log(
        '[onDeactivated] Saving column state for:',
        functionCode,
        columnState.map((c: any) => ({ colId: c.colId, hide: c.hide }))
      );
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
        console.log('[sortChanged] Saving column state for:', capturedFunctionCode, columnState);
        if (columnState && Array.isArray(columnState) && columnState.length > 0) {
          workbenchStore.setColumnState(capturedFunctionCode, capturedParams, columnState);
        }
      }
    });

    api.addEventListener('columnResized', (colEvent: any) => {
      if (options.isRestoringColumnState.value) {
        console.log('[columnResized] Skipping save, isRestoringColumnState is true');
        return;
      }
      if (options.isInitialLoading.value) {
        console.log('[columnResized] Skipping save, isInitialLoading is true');
        return;
      }
      if (colEvent?.finished === false) {
        return;
      }
      const resizeSource = String(colEvent?.source || '');
      if (resizeSource === 'gridSizeChanged' || resizeSource === 'sizeColumnsToFit' || resizeSource === 'api') {
        console.log('[columnResized] Skipping non-user resize source:', resizeSource);
        return;
      }
      if (!options.isGridShellVisible()) {
        console.log('[columnResized] Skipping save, grid shell is hidden');
        return;
      }
      if (capturedFunctionCode && options.gridApi.value) {
        const columnState = options.gridApi.value.getColumnState();
        if (options.hasSuspiciousNarrowColumnState(columnState)) {
          console.log('[columnResized] Skipping suspicious narrow column state for:', capturedFunctionCode);
          return;
        }
        const currentSortedCols = columnState.filter((col: any) => col.sort);
        const cachedColumnState = workbenchStore.getColumnState(capturedFunctionCode, capturedParams);
        const cachedSortedCols = cachedColumnState?.filter((col: any) => col.sort) || [];
        console.log(
          '[columnResized] Current sorted:',
          currentSortedCols.length,
          'Cached sorted:',
          cachedSortedCols.length,
          'for:',
          capturedFunctionCode
        );
        if (currentSortedCols.length === 0 && cachedSortedCols.length > 0) {
          console.log('[columnResized] Skipping save to preserve sort state for:', capturedFunctionCode);
          return;
        }
        console.log('[columnResized] Saving column state for:', capturedFunctionCode, columnState);
        if (columnState && Array.isArray(columnState) && columnState.length > 0) {
          workbenchStore.setColumnState(capturedFunctionCode, capturedParams, columnState);
        }
      }
    });

    api.addEventListener('columnVisible', (colEvent: any) => {
      if (options.isRestoringColumnState.value) {
        return;
      }
      console.log(
        '[columnVisible] Event triggered for:',
        capturedFunctionCode,
        'column:',
        colEvent.column?.getColDef()?.field,
        'visible:',
        colEvent.visible
      );
      if (capturedFunctionCode && options.gridApi.value) {
        const columnState = options.gridApi.value.getColumnState();
        if (columnState && Array.isArray(columnState) && columnState.length > 0) {
          console.log(
            '[columnVisible] Saving column state for:',
            capturedFunctionCode,
            columnState.map((c: any) => ({ colId: c.colId, hide: c.hide }))
          );
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
      console.log('[dragStopped] Event triggered for:', capturedFunctionCode);
      if (capturedFunctionCode && options.gridApi.value) {
        const columnState = options.gridApi.value.getColumnState();
        if (columnState && Array.isArray(columnState) && columnState.length > 0) {
          console.log(
            '[dragStopped] Saving column state for:',
            capturedFunctionCode,
            columnState.map((c: any) => ({ colId: c.colId, hide: c.hide }))
          );
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