import type { Ref } from 'vue';
import type { GridApi } from 'ag-grid-community';
import type { WorkbenchStore } from './use-workbench-grid-state';

type NotifyType = 'success' | 'error' | 'warning' | 'info';

export interface UseWorkbenchStateResetOptions {
  gridApi: Ref<GridApi<Api.Workbench.QueryRecord> | null>;
  pageMeta: Ref<Api.Workbench.PageMeta | null>;
  workbenchStore: WorkbenchStore;
  fieldColumnOptions: Ref<Array<{ label: string; value: string | number }>>;
  visibleFieldColumns: Ref<string[]>;
  pinTargetFields: Ref<string[]>;
  quickKeyword: Ref<string>;
  selectedField: Ref<string>;
  selectedOperator: Ref<string>;
  selectedValue: Ref<string>;
  useLegacyTabHint: Ref<boolean>;
  /** 颜色标注 / 表级修改重置 */
  resetColorMarkState: () => void;
  tableModifiedRows: Ref<Set<string | number>>;
  modifiedRowsData: Ref<Map<string | number, any>>;
  /** 拉取数据（用于 refresh 之后 reload） */
  loadPage: () => Promise<void> | void;
  getFunctionCode: () => string;
  getParams: () => string;
  notify: (type: NotifyType, message: string) => void;
}

interface ClearGridStateHelpers {
  gridApi: Ref<GridApi<Api.Workbench.QueryRecord> | null>;
  fieldColumnOptions: Ref<Array<{ label: string; value: string | number }>>;
  visibleFieldColumns: Ref<string[]>;
  pinTargetFields: Ref<string[]>;
}

/**
 * 把"清筛选 / 清排序 / 取消固定列 / 显示所有字段 / 清除颜色标注 / 清除修改状态"
 * 抽成单一内部函数，再分别为 handleReset（不刷数据）与 handleRefresh（清缓存 + 刷数据）封装。
 *
 *  - handleReset：只重置 UI 状态 + 清除 store 中的筛选/排序缓存，保留后端数据缓存
 *  - handleRefresh：额外清后端数据缓存 + 调用 loadPage 重新加载
 */
export function useWorkbenchStateReset(options: UseWorkbenchStateResetOptions) {
  function applyColumnResetAll(helpers: ClearGridStateHelpers) {
    const { gridApi, fieldColumnOptions } = helpers;
    const fields = fieldColumnOptions.value.map(item => String(item.value));
    helpers.visibleFieldColumns.value = fields;

    if (!gridApi.value || gridApi.value.isDestroyed()) return;

    // 1. 显示所有字段
    gridApi.value.setColumnsVisible(fields, true);

    // 2. 取消固定列
    gridApi.value.applyColumnState({
      state: fields.map(item => ({ colId: String(item), pinned: null })),
      defaultState: { pinned: null }
    });

    // 3. 清除排序
    gridApi.value.applyColumnState({
      state: fields.map(item => ({ colId: String(item), sort: null })),
      defaultState: { sort: null }
    });

    // 4. 清除筛选
    gridApi.value.setFilterModel(null);
  }

  function clearQueryInputs() {
    options.quickKeyword.value = '';
    options.selectedField.value = options.pageMeta.value?.conditions?.[0]?.fieldKey || '';
    options.selectedOperator.value = 'contains';
    options.selectedValue.value = '';
  }

  function clearLocalModifications() {
    options.tableModifiedRows.value.clear();
    options.modifiedRowsData.value.clear();
    options.useLegacyTabHint.value = false;
  }

  function clearStoreCaches(functionCode: string, params: string) {
    if (!functionCode) return;
    options.workbenchStore.clearFilterModel(functionCode, params);
    options.workbenchStore.clearColumnState(functionCode, params);
  }

  async function handleReset() {
    clearQueryInputs();
    options.resetColorMarkState();
    applyColumnResetAll({
      gridApi: options.gridApi,
      fieldColumnOptions: options.fieldColumnOptions,
      visibleFieldColumns: options.visibleFieldColumns,
      pinTargetFields: options.pinTargetFields
    });
    options.pinTargetFields.value = [];

    const functionCode = options.getFunctionCode();
    const params = options.getParams();
    clearStoreCaches(functionCode, params);

    clearLocalModifications();
    options.notify('success', '已重置到初始状态');
  }

  async function handleRefresh() {
    const functionCode = options.getFunctionCode();
    const params = options.getParams();

    if (functionCode) {
      options.workbenchStore.clearCache(functionCode, params);
    }

    clearQueryInputs();
    options.resetColorMarkState();
    applyColumnResetAll({
      gridApi: options.gridApi,
      fieldColumnOptions: options.fieldColumnOptions,
      visibleFieldColumns: options.visibleFieldColumns,
      pinTargetFields: options.pinTargetFields
    });
    options.pinTargetFields.value = [];

    clearLocalModifications();

    await options.loadPage();
    options.notify('success', '已刷新并恢复到初始状态');
  }

  return {
    handleReset,
    handleRefresh
  };
}
