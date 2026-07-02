<script lang="ts">
// 模块级加载锁，防止同一 functionCode 被多个组件实例重复加载
const _loadingLocks = new Map<string, boolean>();
</script>

<script setup lang="ts">
import { computed, onActivated, onMounted, ref, shallowRef, watch } from 'vue';
import { useRoute } from 'vue-router';

import { AG_GRID_LOCALE_CN } from '@ag-grid-community/locale';
import { AllCommunityModule, ModuleRegistry, themeAlpine, type GridApi } from 'ag-grid-community';
import { NSpin, NAlert } from 'naive-ui';
import { AgGridVue } from 'ag-grid-vue3';

import { useColorMark } from '@/hooks/business/use-color-mark';
import { useWorkbenchColumnSettings } from '@/hooks/business/use-workbench-column-settings';
import { useWorkbenchDelete } from '@/hooks/business/use-workbench-delete';
import { useWorkbenchEditForms } from '@/hooks/business/use-workbench-edit-forms';
import { useWorkbenchImport } from '@/hooks/business/use-workbench-import';
import { useWorkbenchPopupCascader } from '@/hooks/business/use-workbench-popup-cascader';
import { useWorkbenchComment } from '@/hooks/business/use-workbench-comment';
import { useWorkbenchGridState } from '@/hooks/business/use-workbench-grid-state';
import { useWorkbenchChart } from '@/hooks/business/use-workbench-chart';
import { useWorkbenchChartDrill } from '@/hooks/business/use-workbench-chart-drill';
import { useWorkbenchTableEdit } from '@/hooks/business/use-workbench-table-edit';
import { useWorkbenchDataLoader } from '@/hooks/business/use-workbench-data-loader';
import { collectColumnFilters, useWorkbenchExport } from '@/hooks/business/use-workbench-export';
import { useWorkbenchDrillDialog } from '@/hooks/business/use-workbench-drill-dialog';
import { useWorkbenchPageDebug } from '@/hooks/business/use-workbench-page-debug';
import { useWorkbenchChartDebug } from '@/hooks/business/use-workbench-chart-debug';
import { useWorkbenchPanelResize } from '@/hooks/business/use-workbench-panel-resize';
import { useWorkbenchStateReset } from '@/hooks/business/use-workbench-state-reset';
import { useWorkbenchDrillOptions } from '@/hooks/business/use-workbench-drill-options';
import { useWorkbenchUpkeep } from '@/hooks/business/use-workbench-upkeep';
import { useWorkbenchDataFetchAll } from '@/hooks/business/use-workbench-data-fetch-all';
import { useWorkbenchGridReady } from '@/hooks/business/use-workbench-grid-ready';
import { useWorkbenchNotify } from '@/hooks/business/use-workbench-notify';
import { useThemeStore } from '@/store/modules/theme';
import { useWorkbenchRightPanelStore } from '@/store/modules/workbench-right-panel';
import { WORKBENCH_CONFIG } from '@/config/workbench';
import { logger } from '@/utils/logger';
import { markTrace } from '@/utils/performance-trace';
import { isGuidColumn } from '@/utils/menu-bridge';
import type { MenuBridgeMeta, ConditionOperator } from '@/typings/menu-bridge';
import { DEFAULT_COL_DEF } from './constants';
import {
  WorkbenchToolbar,
  WorkbenchImport,
  WorkbenchPopupSelect,
  WorkbenchSelectAllHeader,
  WorkbenchPinColumnModal,
  WorkbenchFieldColumnModal,
  WorkbenchConditionDrawer,
  WorkbenchColorMarkModal,
  WorkbenchRightPanel
} from './components';

const route = useRoute();

ModuleRegistry.registerModules([AllCommunityModule]);

type _QueryFilter = NonNullable<Api.Workbench.QueryPayload['filters']>[number];

const { notify } = useWorkbenchNotify();

const props = defineProps<{
  meta: MenuBridgeMeta;
  nativeOnly?: boolean;
  dynamicLike?: boolean;
}>();

const { GRID_THEME, STORAGE_KEYS, PAGINATION } = WORKBENCH_CONFIG;

const lightGridTheme = themeAlpine.withParams(GRID_THEME.LIGHT);
const darkGridTheme = themeAlpine.withParams(GRID_THEME.DARK);

const themeStore = useThemeStore();
const isDarkMode = computed(() => themeStore.darkMode);
const activeGridTheme = computed(() => (isDarkMode.value ? darkGridTheme : lightGridTheme));

const paginationPageSizeSelector = [...PAGINATION.PAGE_SIZE_OPTIONS];

const useLegacyTabHint = ref(false);
const gridApi = ref<GridApi<Api.Workbench.QueryRecord> | null>(null);

// 右侧面板模式：null / 'chart' / 'add' / 'update' / 'batch' / 'comment'
// 用于协调 chart 与 新增/单条修改/多条修改/添加批注/查看批注 互斥占据右侧分栏
type RightPanelMode = 'chart' | 'add' | 'update' | 'batch' | 'comment' | null;
const rightPanelMode = ref<RightPanelMode>(null);
const rightPanelVisible = computed(() => rightPanelMode.value !== null);

// 防止筛选恢复期间 filterChanged 覆盖筛选
const isRestoringFilter = ref(false);
// 防止排序恢复期间 sortChanged 覆盖排序
const isRestoringColumnState = ref(false);

const functionCode = computed(() => String(props.meta.functionCode || ''));
const params = computed(() => String(props.meta.params || ''));

// 先创建需要的状态（用于解决循环依赖）
const pageMeta = ref<Api.Workbench.PageMeta | null>(null);
const serverRows = shallowRef<Api.Workbench.QueryRecord[]>([]);
const total = ref(0);
const totalCount = ref(0);
const loadedCount = ref(0);
const loading = ref(false);
const isInitialChunkLoaded = ref(false);
const isChunkLoading = ref(false);
const isInitialLoading = ref(true);
const isRestoringPage = ref(false);
const isRestoringSelection = ref(false);
const page = ref(1);
const pageSize = ref(PAGINATION.PAGE_SIZE_OPTIONS[0]);
const selectedField = ref('');
const selectedOperator = ref<ConditionOperator>('contains');
const selectedValue = ref('');
const conditionVisible = ref(false);
const quickKeyword = ref('');

const isDataLoaded = ref(false);
const loadedFunctionCode = ref<string>('');
const loadedParams = ref<string>('');

let loadPage: () => Promise<void> = () => Promise.resolve();

// 监听主题模式变化，刷新单元格样式
watch(
  () => isDarkMode.value,
  () => {
    // 主题切换时刷新所有单元格
    if (gridApi.value && !gridApi.value.isDestroyed()) {
      gridApi.value.refreshCells({ force: true });
    }
  }
);

const {
  importVisible,
  importLoading,
  importPreviewData,
  importError,
  importSuccess,
  fileInputRef,
  importPreviewColumns,
  handleImport,
  triggerFileInput,
  handleFileSelect,
  handleDrop: _handleDrop,
  confirmImport,
  downloadImportTemplate,
  resetImportPreview
} = useWorkbenchImport({
  gridApi,
  getFunctionCode: () => String(props.meta.functionCode || '').trim(),
  getParams: () => params.value,
  reloadPage: () => loadPage(),
  clearCache: (fc, p) => workbenchStore.clearCache(fc, p),
  notify
});

const {
  addCommentVisible,
  viewCommentVisible,
  commentFields,
  commentList,
  commentFormData,
  commentLoading,
  commentModuleName,
  commentRemark,
  keyFieldList,
  keyFieldCount,
  handleOpenAddComment,
  handleOpenViewComment,
  handleSubmitComment
} = useWorkbenchComment({
  gridApi,
  getFunctionCode: () => String(props.meta.functionCode || '').trim(),
  getCommentModuleName: () => pageMeta.value?.commentModule || String(props.meta.functionCode || '').trim(),
  notify
});

// 工具栏滚动相关：状态由 WorkbenchToolbar 内部管理，父组件通过 ref 转发
const toolbarRef = ref<{ checkScrollPosition: () => void } | null>(null);
const checkScrollPosition = () => toolbarRef.value?.checkScrollPosition();
const gridShellRef = ref<HTMLDivElement | null>(null);

function isGridShellVisible() {
  if (!gridShellRef.value) return false;
  return gridShellRef.value.offsetWidth > 0 && gridShellRef.value.offsetHeight > 0;
}

function hasSuspiciousNarrowColumnState(columnState: any[]) {
  if (!Array.isArray(columnState) || columnState.length === 0) return false;

  const { COLUMN_IDS } = WORKBENCH_CONFIG;
  const dataColumns = columnState.filter((col: any) => {
    const colId = String(col?.colId || '');
    return colId && colId !== COLUMN_IDS.SELECTION && !colId.startsWith(COLUMN_IDS.PREFIX);
  });

  if (dataColumns.length === 0) return false;

  const narrowCount = dataColumns.filter((col: any) => {
    const width = Number(col?.width || 0);
    return Number.isFinite(width) && width > 0 && width <= 80;
  }).length;

  return narrowCount / dataColumns.length >= 0.7;
}

// 是否有整表修改权限
const hasTableEditAuth = computed(() => pageMeta.value?.toolbar.tableEdit === true);

// 可颜色标注的列
const colorMarkEnabledColumns = computed(() => {
  return (pageMeta.value?.columns || [])
    .filter(column => column.colorMarkEnabled)
    .map(column => ({ label: column.title || column.field, value: column.field }));
});

// 是否有可颜色标注的列
const hasColorMarkEnabledColumns = computed(() => colorMarkEnabledColumns.value.length > 0);

// 是否有图形模块配置
const hasChartEnabled = computed(() => !!pageMeta.value?.chartModule && pageMeta.value.chartModule !== '');

// 图表区图表自适应
const workbenchContentRef = ref<HTMLDivElement | null>(null);
const chartMaximized = ref(false);
// 编辑面板（新增 / 单条修改 / 多条修改）独立的"扩大/恢复"状态，
// 与图形面板的 chartMaximized 解耦，互不影响
const editPanelMaximized = ref(false);

// 图表 resize 在分栏拖动 / 最大化时复用
function handlePanelResize() {
  if (chartVisible.value) {
    chartResize();
  }
}

// 分栏拖动条入口：把 MouseEvent 显式透传给对应模式的 startResize
// （不能用内联三目表达式，Vue 3 不会自动给表达式结果注入 $event）
function handleSplitterMouseDown(e: MouseEvent) {
  if (rightPanelMode.value === 'chart') {
    startResize(e);
  } else {
    startEditResize(e);
  }
}

const { leftPanelWidth, isResizing, startResize } = useWorkbenchPanelResize({
  containerRef: workbenchContentRef,
  defaultPercent: 55,
  minPercent: 15,
  maxPercent: 70,
  storageKey: STORAGE_KEYS.LEFT_PANEL_WIDTH,
  onResize: handlePanelResize
});

const {
  leftPanelWidth: editLeftWidth,
  isResizing: editIsResizing,
  startResize: startEditResize
} = useWorkbenchPanelResize({
  containerRef: workbenchContentRef,
  defaultPercent: 55,
  minPercent: 15,
  maxPercent: 70,
  storageKey: STORAGE_KEYS.EDIT_PANEL_WIDTH,
  onResize: () => {
    // 编辑面板内容为表单，无图表 resize 需求
  }
});

const anyRightPanelResizing = computed(() => isResizing.value || editIsResizing.value);
const activeLeftWidth = computed(() => (rightPanelMode.value === 'chart' ? leftPanelWidth.value : editLeftWidth.value));

const {
  colorMarkVisible,
  colorMarkField1,
  colorMarkOperator,
  colorMarkField2,
  colorMarkColor,
  colorMarkConfig,
  resetColorMarkState,
  handleOpenColorMark,
  handleApplyColorMark,
  handleClearColorMark
} = useColorMark({
  colorMarkEnabledColumns,
  gridApi,
  notify
});

const filterableFields = computed(() => {
  return (pageMeta.value?.conditions || []).filter(item => item.filterable).map(item => item.fieldKey);
});

const pinColumnOptions = computed(() => {
  const columns = gridApi.value?.getColumns() ?? [];

  if (columns.length > 0) {
    return columns
      .map(column => {
        const colDef = column.getColDef();
        const field = String(colDef.field || '');
        const headerName = String(colDef.headerName || field);

        return {
          label: headerName,
          value: field
        };
      })
      .filter(item => item.value !== '' && !isGuidColumn(item.value, item.label));
  }

  return (pageMeta.value?.columns || [])
    .filter(column => column.field !== '')
    .map(column => ({ label: column.title || column.field, value: column.field }))
    .filter(item => !isGuidColumn(String(item.value), String(item.label)));
});

const fieldColumnOptions = computed(() => {
  const columns = gridApi.value?.getColumns() ?? [];
  const checkboxOption = { label: '选择框', value: 'ag-Grid-SelectionColumn' };

  if (columns.length > 0) {
    const mapped = columns.map(column => {
      const colDef = column.getColDef();
      const colId = column.getColId();
      const field = String(colDef.field || '');
      const headerName = String(colDef.headerName || field);

      // 对于 checkbox 选择列，使用 colId 作为 value
      if (colId === 'ag-Grid-SelectionColumn') {
        return {
          label: '选择框',
          value: colId
        };
      }

      return {
        label: headerName,
        value: field
      };
    });

    const result = mapped.filter(
      item =>
        item.value === 'ag-Grid-SelectionColumn' ||
        (item.value !== '' && item.value !== 'ag-Grid-ControlsColumn' && !isGuidColumn(item.value, item.label))
    );

    // 确保始终包含 checkbox 选项
    const hasCheckbox = result.some(item => item.value === 'ag-Grid-SelectionColumn');
    if (!hasCheckbox) {
      result.unshift(checkboxOption);
    }

    return result;
  }

  const result = (pageMeta.value?.columns || [])
    .filter(column => column.field !== '')
    .map(column => ({ label: column.title || column.field, value: column.field }))
    .filter(item => !isGuidColumn(String(item.value), String(item.label)));

  // 表格始终有 checkbox 选择列，默认添加
  result.unshift(checkboxOption);

  return result;
});

const {
  fieldColumnVisible,
  visibleFieldColumns,
  pinColumnVisible,
  pinTargetFields,
  handleOpenFieldColumn,
  handleSelectAllFieldColumns,
  handleClearFieldColumns,
  handleFieldSelectionChange,
  handleOpenPinColumn,
  handleClearPinColumns,
  handlePinSelectionChange
} = useWorkbenchColumnSettings({
  gridApi,
  fieldColumnOptions,
  pinColumnOptions
});

function getCacheScopeKey() {
  // cacheScopeKey 已经包含了 functionCode 和 params，所以这里只返回空字符串
  // 让 getCacheKey 使用 functionCode 和 params 作为缓存键
  return '';
}

const { workbenchStore, registerGridPersistenceListeners } = useWorkbenchGridState({
  getMeta: () => props.meta,
  getCacheScopeKey,
  gridApi,
  pageMeta,
  page,
  pageSize,
  defaultPageSize: PAGINATION.PAGE_SIZE_OPTIONS[0],
  conditionVisible,
  fieldColumnVisible,
  pinColumnVisible,
  quickKeyword,
  selectedField,
  selectedOperator,
  selectedValue,
  visibleFieldColumns,
  pinTargetFields,
  isRestoringFilter,
  isRestoringColumnState,
  isRestoringSelection,
  isRestoringPage,
  isInitialLoading,
  isGridShellVisible,
  hasSuspiciousNarrowColumnState
});

const {
  loadPage: loadPageFromLoader,
  isDataLoaded: isDataLoadedFromLoader,
  loadedFunctionCode: loadedFunctionCodeFromLoader,
  loadedParams: loadedParamsFromLoader
} = useWorkbenchDataLoader({
  gridApi,
  functionCode,
  params,
  workbenchStore,
  notify,
  checkScrollPosition,
  pageMeta,
  serverRows,
  total,
  totalCount,
  loadedCount,
  loading,
  isInitialChunkLoaded,
  isChunkLoading,
  isInitialLoading,
  isRestoringPage,
  isRestoringSelection,
  page,
  pageSize,
  selectedField,
  selectedOperator,
  selectedValue,
  conditionVisible,
  quickKeyword
});

loadPage = loadPageFromLoader;
isDataLoaded.value = isDataLoadedFromLoader.value;
loadedFunctionCode.value = loadedFunctionCodeFromLoader.value;
loadedParams.value = loadedParamsFromLoader.value;

const {
  addVisible,
  addLoading,
  addFormData,
  addFormFields,
  addError,
  addSuccess,
  updateVisible,
  updateLoading,
  updateError,
  updateSuccess,
  updateFormData,
  updateFormFields,
  batchUpdateVisible,
  batchUpdateLoading,
  batchUpdateError,
  batchUpdateSuccess,
  batchUpdateFormData,
  batchUpdateFormFields,
  handleOpenAdd,
  confirmAdd,
  handleOpenUpdate,
  confirmUpdate,
  handleOpenBatchUpdate,
  confirmBatchUpdate,
  setEditFieldValue
} = useWorkbenchEditForms({
  gridApi,
  getFunctionCode: () => String(props.meta.functionCode || '').trim(),
  refreshAfterMutation: () => {
    const currentFunctionCode = String(props.meta.functionCode || '').trim();
    const currentParams = String(props.meta.params || '').trim();
    workbenchStore.clearCache(currentFunctionCode, currentParams);
    isDataLoaded.value = false;
    loadPage();
  },
  notify
});

// —— 右栏状态持久化：组件 mount / activate 时从 store 读回，watch 实时写回 ——
const rightPanelStore = useWorkbenchRightPanelStore();

/**
 * 从 store 读回右栏状态，覆盖本地 ref 的初始默认值
 * - 用于：组件被 KeepAlive 卸载后重新 mount（setup 中 composables 已用默认值初始化）
 * - 仅在 store 中存在该 functionCode::params 的缓存时才覆盖，避免污染首次进入的场景
 */
function restoreRightPanelStateFromStore() {
  const saved = rightPanelStore.getState(functionCode.value, params.value);
  if (!saved) return;

  // 用 store 中的状态覆盖 composables 初始化出来的默认值
  if (saved.rightPanelMode !== undefined) rightPanelMode.value = saved.rightPanelMode;
  if (saved.addVisible !== undefined) addVisible.value = saved.addVisible;
  if (saved.addFormData !== undefined) addFormData.value = { ...saved.addFormData };
  if (saved.addFormFields !== undefined) addFormFields.value = [...saved.addFormFields];
  if (saved.updateVisible !== undefined) updateVisible.value = saved.updateVisible;
  if (saved.updateFormData !== undefined) updateFormData.value = { ...saved.updateFormData };
  if (saved.updateFormFields !== undefined) updateFormFields.value = [...saved.updateFormFields];
  if (saved.batchUpdateVisible !== undefined) batchUpdateVisible.value = saved.batchUpdateVisible;
  if (saved.batchUpdateFormData !== undefined) batchUpdateFormData.value = { ...saved.batchUpdateFormData };
  if (saved.batchUpdateFormFields !== undefined) batchUpdateFormFields.value = [...saved.batchUpdateFormFields];
  if (saved.addCommentVisible !== undefined) addCommentVisible.value = saved.addCommentVisible;
  if (saved.viewCommentVisible !== undefined) viewCommentVisible.value = saved.viewCommentVisible;
  if (saved.commentFormData !== undefined) commentFormData.value = { ...saved.commentFormData };
  if (saved.commentRemark !== undefined) commentRemark.value = saved.commentRemark;
  if (saved.commentFields !== undefined) commentFields.value = [...saved.commentFields];
  if (saved.commentList !== undefined) commentList.value = [...saved.commentList];
}

/**
 * 把当前右栏状态写回 store
 * - 监听任何相关 ref 变化都会调用本函数
 */
function persistRightPanelStateToStore() {
  rightPanelStore.setState(functionCode.value, params.value, {
    rightPanelMode: rightPanelMode.value,
    addVisible: addVisible.value,
    addFormData: addFormData.value,
    addFormFields: addFormFields.value,
    updateVisible: updateVisible.value,
    updateFormData: updateFormData.value,
    updateFormFields: updateFormFields.value,
    batchUpdateVisible: batchUpdateVisible.value,
    batchUpdateFormData: batchUpdateFormData.value,
    batchUpdateFormFields: batchUpdateFormFields.value,
    addCommentVisible: addCommentVisible.value,
    viewCommentVisible: viewCommentVisible.value,
    commentFormData: commentFormData.value,
    commentRemark: commentRemark.value,
    commentFields: commentFields.value,
    commentList: commentList.value
  });
}

// 首次 setup：从 store 恢复（如果之前切走标签页保存过）
restoreRightPanelStateFromStore();

// 实时监听所有右栏相关 ref 变化，写回 store
watch(
  [
    rightPanelMode,
    addVisible,
    addFormData,
    addFormFields,
    updateVisible,
    updateFormData,
    updateFormFields,
    batchUpdateVisible,
    batchUpdateFormData,
    batchUpdateFormFields,
    addCommentVisible,
    viewCommentVisible,
    commentFormData,
    commentRemark,
    commentFields,
    commentList
  ],
  () => {
    // functionCode / params 尚未就绪时不写入（极端情况）
    if (!functionCode.value) return;
    persistRightPanelStateToStore();
  },
  { deep: true }
);

// 兜底：mount / activate 钩子再尝试恢复一次（覆盖 cache key 在 setup 之后才稳定的情况）
onMounted(() => {
  markTrace('组件挂载完成');
  restoreRightPanelStateFromStore();
});
onActivated(() => {
  markTrace('组件激活完成');
  restoreRightPanelStateFromStore();
});

// —— 右侧面板协调：chart / 新增 / 单条修改 / 多条修改 互斥 ——
// 关闭"新增/单条/多条"表单：clearAddPanel / clearUpdatePanel / clearBatchUpdatePanel
function clearAddPanel() {
  addVisible.value = false;
  addError.value = '';
  addSuccess.value = '';
}
function clearUpdatePanel() {
  updateVisible.value = false;
  updateError.value = '';
  updateSuccess.value = '';
}
function clearBatchUpdatePanel() {
  batchUpdateVisible.value = false;
  batchUpdateError.value = '';
  batchUpdateSuccess.value = '';
}

// 「添加样本数据」：把表格勾选的 1 行数据合并到 addFormData
//   - 0 行 / 多行：提示，不修改表单
//   - 1 行：只覆盖表单中存在的字段（按 formFields.fieldName 匹配），保留用户已输入的其它字段
function handleAddSample() {
  if (rightPanelMode.value !== 'add' || !addVisible.value) {
    notify('warning', '请先打开新增视图');
    return;
  }
  const selectedRows = gridApi.value?.getSelectedRows() || [];
  if (selectedRows.length === 0) {
    notify('warning', '请先在表格中勾选一行作为样本数据');
    return;
  }
  if (selectedRows.length > 1) {
    notify('warning', '只能选择一行作为样本数据');
    return;
  }

  const sample = selectedRows[0] as Record<string, any>;
  const merged: Record<string, any> = { ...addFormData.value };
  let matchedCount = 0;
  for (const field of addFormFields.value) {
    const fieldName = field.fieldName as string;
    if (!fieldName) continue;
    if (Object.prototype.hasOwnProperty.call(sample, fieldName)) {
      merged[fieldName] = sample[fieldName];
      matchedCount += 1;
    }
  }

  if (matchedCount === 0) {
    notify('warning', '所选行与表单字段不匹配，未填入任何字段');
    return;
  }

  addFormData.value = merged;
  notify('success', `已从样本行填入 ${matchedCount} 个字段`);
}
function clearCommentPanel() {
  addCommentVisible.value = false;
  viewCommentVisible.value = false;
}

async function handleOpenAddPanel() {
  // 关闭其它右侧面板
  clearUpdatePanel();
  clearBatchUpdatePanel();
  clearCommentPanel();
  if (chartVisible.value) {
    chartVisible.value = false;
    chartMaximized.value = false;
  }
  rightPanelMode.value = 'add';
  await handleOpenAdd();
}

async function handleOpenUpdatePanel() {
  clearAddPanel();
  clearBatchUpdatePanel();
  clearCommentPanel();
  if (chartVisible.value) {
    chartVisible.value = false;
    chartMaximized.value = false;
  }
  // 校验选中的记录数与原行为一致
  const selectedRows = gridApi.value?.getSelectedRows() || [];
  if (selectedRows.length === 0) {
    notify('warning', '请先选择要修改的记录');
    return;
  }
  if (selectedRows.length > 1) {
    notify('warning', '修改操作只能选择一条记录');
    return;
  }
  rightPanelMode.value = 'update';
  await handleOpenUpdate();
  // handleOpenUpdate 内部可能因为无法取主键而未设置 updateVisible，此时回退 mode
  if (!updateVisible.value) {
    rightPanelMode.value = null;
  }
}

async function handleOpenBatchUpdatePanel() {
  clearAddPanel();
  clearUpdatePanel();
  clearCommentPanel();
  if (chartVisible.value) {
    chartVisible.value = false;
    chartMaximized.value = false;
  }
  const selectedRows = gridApi.value?.getSelectedRows() || [];
  if (selectedRows.length === 0) {
    notify('warning', '请先选择要修改的记录');
    return;
  }
  rightPanelMode.value = 'batch';
  await handleOpenBatchUpdate();
  if (!batchUpdateVisible.value) {
    rightPanelMode.value = null;
  }
}

async function handleOpenAddCommentPanel() {
  // 关闭其它右侧面板
  clearAddPanel();
  clearUpdatePanel();
  clearBatchUpdatePanel();
  if (chartVisible.value) {
    chartVisible.value = false;
    chartMaximized.value = false;
  }
  // 先打开右侧分栏，模式置为 'comment'
  rightPanelMode.value = 'comment';
  // 校验选中的记录数与原行为一致
  const selectedRows = gridApi.value?.getSelectedRows() || [];
  if (selectedRows.length === 0) {
    notify('warning', '请先选择要添加批注的记录');
    rightPanelMode.value = null;
    return;
  }
  if (selectedRows.length > 1) {
    notify('warning', '只能选择一条记录');
    rightPanelMode.value = null;
    return;
  }
  await handleOpenAddComment();
  // handleOpenAddComment 内部可能因为取主键失败而未设置 addCommentVisible，此时回退 mode
  if (!addCommentVisible.value) {
    rightPanelMode.value = null;
  }
}

async function handleOpenViewCommentPanel() {
  // 关闭其它右侧面板
  clearAddPanel();
  clearUpdatePanel();
  clearBatchUpdatePanel();
  if (chartVisible.value) {
    chartVisible.value = false;
    chartMaximized.value = false;
  }
  // 先打开右侧分栏，模式置为 'comment'
  rightPanelMode.value = 'comment';
  const selectedRows = gridApi.value?.getSelectedRows() || [];
  if (selectedRows.length === 0) {
    notify('warning', '请先选择要查看批注的记录');
    rightPanelMode.value = null;
    return;
  }
  if (selectedRows.length > 1) {
    notify('warning', '只能选择一条记录');
    rightPanelMode.value = null;
    return;
  }
  await handleOpenViewComment();
  if (!viewCommentVisible.value) {
    rightPanelMode.value = null;
  }
}

function handleCloseRightPanel() {
  rightPanelMode.value = null;
  clearAddPanel();
  clearUpdatePanel();
  clearBatchUpdatePanel();
  clearCommentPanel();
  if (chartVisible.value) {
    chartVisible.value = false;
    chartMaximized.value = false;
    // 关闭图形时静默重置钻取状态：
    // 1) 前端 drillLevel 立即归零，避免下次打开仍显示"钻取第 N 级"徽章
    // 2) 同步清理后端 session 钻取栈（chart_drill_arr / cond / title），
    //    防止用户重新钻取时叠加错误的条件
    void silentResetDrill();
  }
}

const {
  chartVisible,
  chartLoading,
  chartData,
  chartOptions,
  setChartRef,
  handleOpenChart,
  resizeChart: chartResize,
  reloadChartsFromDrill
} = useWorkbenchChart({
  getFunctionCode: () => String(route.query.functionCode || route.meta?.functionCode || ''),
  notify,
  onChartClick: (clickParams: any) => {
    // 图表点击事件转发给图形钻取 hook
    // 不在前端硬性限制钻取级别：钻取层级数由后端 def_chart_drill_config 配置决定，
    // 即"下一级目标图形"上是否再带 钻取选项；缺钻取选项的图形点击会由 hook 内部
    // 提示"当前图形未配置钻取选项"或静默 noop。
    handleChartClick(clickParams);
  }
});

async function handleOpenChartPanel() {
  if (!pageMeta.value) return;
  // 关闭右侧编辑面板
  clearAddPanel();
  clearUpdatePanel();
  clearBatchUpdatePanel();
  clearCommentPanel();
  rightPanelMode.value = 'chart';
  await handleOpenChart(pageMeta.value);
}

// 监听表单 visible：visible 转 false 时同步清空右侧面板 mode
// （success 自动关闭 / 用户点击关闭按钮 都会触发）
watch(addVisible, val => {
  if (!val && rightPanelMode.value === 'add') rightPanelMode.value = null;
});
watch(updateVisible, val => {
  if (!val && rightPanelMode.value === 'update') rightPanelMode.value = null;
});
watch(batchUpdateVisible, val => {
  if (!val && rightPanelMode.value === 'batch') rightPanelMode.value = null;
});
// 监听批注 visible：add/view 任一为 true 时保持 comment 模式，
// 二者均为 false 且当前是 comment 模式时同步清空
watch([addCommentVisible, viewCommentVisible], ([addVal, viewVal]) => {
  if (rightPanelMode.value === 'comment' && !addVal && !viewVal) {
    rightPanelMode.value = null;
  }
});
// 监听 chart 关闭（原有"关闭"按钮 / handleCloseRightPanel 都会改 chartVisible）
watch(chartVisible, val => {
  if (!val && rightPanelMode.value === 'chart') rightPanelMode.value = null;
});

const { drillLevel, isDrilled, handleChartClick, resetDrill, silentResetDrill } = useWorkbenchChartDrill({
  getFunctionCode: () => String(route.query.functionCode || route.meta?.functionCode || ''),
  getDrillOptionsForChart: (sid: string) => {
    // 图形钻取对话框使用图表自身的钻取选项（chart.钻取选项，源自 def_chart_drill_config）
    // 与旧版 Vgrid_aggrid.php::chart_drill 中的 chart_data[chartModule][chartCode]['钻取模块'] 等价
    // 不使用页面级钻取选项作为兜底——表格级钻取（def_drill_config）用于表格行跳转，
    // 与图形钻取（def_chart_drill_config）是不同的配置体系，混用会导致弹出错误的钻取条件。
    return getDrillOptionsForChart(sid);
  },
  notify,
  loading: ref(false), // 图形钻取 loading 与其他操作解耦
  onDrillChartsUpdated: async (charts: any[]) => {
    // 用钻取结果重新渲染图表
    await reloadChartsFromDrill(charts);
  },
  isDarkMode,
  regenerateOptionsFromCharts: (charts: any[]) => {
    // 实际重新渲染由 useWorkbenchChart 内部完成
    void charts;
  }
});

// 钻取选项缓存（页面级 + 图表级）
const { options: _drillOptions, getOptionsForChart: getDrillOptionsForChart } = useWorkbenchDrillOptions({
  chartData
});

/**
 * 重置图形钻取：先清空后端 session 中的钻取状态，再重新加载初始图形
 */
async function handleResetDrill() {
  if (isDrilled.value) {
    await resetDrill();
  }
  // 重新打开图形以加载初始数据
  if (pageMeta.value) {
    await handleOpenChart(pageMeta.value);
  }
}

const {
  tableModifiedRows,
  modifiedRowsData,
  hasTableModifications,
  processedRows,
  gridColumns,
  handleCellValueChanged,
  handleTableEditSubmit
} = useWorkbenchTableEdit({
  gridApi,
  getFunctionCode: () => String(props.meta.functionCode || '').trim(),
  getParams: () => String(props.meta.params || '').trim(),
  workbenchStore,
  notify,
  loadPage,
  serverRows,
  pageMeta,
  colorMarkConfig,
  isDarkMode
});

// 导出（XLSX）
const { handleExport } = useWorkbenchExport({
  gridApi,
  getFunctionCode: () => String(props.meta.functionCode || '').trim(),
  notify,
  getFilters: () => {
    // 组合：ag-grid 列筛选 + 条件面板筛选 + 工具栏快速检索
    // 让"导出筛选"与页面显示完全对齐：
    //   - 列筛选（每列 floating filter/menu filter）
    //   - 条件面板（fieldKey / operator / value 结构化筛选）
    //   - 工具栏快速检索（跨所有文本列）
    const filters: any[] = [];

    // 1. ag-grid 列筛选（最高优先级，先收集）
    if (gridApi.value && !gridApi.value.isDestroyed()) {
      filters.push(...collectColumnFilters(gridApi.value));
    }

    // 2. 条件面板筛选
    if (selectedField.value && selectedValue.value.trim()) {
      filters.push({
        fieldKey: selectedField.value,
        operator: selectedOperator.value,
        value: selectedValue.value.trim()
      });
    }

    // 3. 工具栏快速检索
    if (quickKeyword.value && quickKeyword.value.trim()) {
      filters.push({
        globalSearch: quickKeyword.value.trim()
      });
    }

    return filters;
  }
});

// 钻取选项对话框（数据行 → 跳转新功能页）
const { openDataDrill } = useWorkbenchDrillDialog({
  gridApi,
  getFunctionCode: () => String(props.meta.functionCode || '').trim(),
  loading,
  notify
});

// 全量数据加载 + 条件筛选应用
const { queryPage } = useWorkbenchDataFetchAll({
  getFunctionCode: () => String(props.meta.functionCode || '').trim(),
  selectedField,
  selectedOperator,
  selectedValue,
  page,
  total,
  serverRows,
  loading,
  notify
});

// 状态重置（handleReset / handleRefresh）
const { handleReset, handleRefresh } = useWorkbenchStateReset({
  gridApi,
  pageMeta,
  workbenchStore,
  fieldColumnOptions,
  visibleFieldColumns,
  pinTargetFields,
  quickKeyword,
  selectedField,
  selectedOperator,
  selectedValue,
  useLegacyTabHint,
  resetColorMarkState,
  tableModifiedRows,
  modifiedRowsData,
  loadPage,
  getFunctionCode: () => String(props.meta.functionCode || '').trim(),
  getParams: () => String(props.meta.params || '').trim(),
  notify
});

// 数据整理
const { handleUpkeep } = useWorkbenchUpkeep({
  getFunctionCode: () => String(props.meta.functionCode || '').trim(),
  loading,
  loadPage,
  notify
});

// 页面级 / 图表级 调试
const { handleDebug } = useWorkbenchPageDebug({
  getFunctionCode: () => String(props.meta.functionCode || '').trim(),
  notify
});
const { handleChartDebug } = useWorkbenchChartDebug({
  getFunctionCode: () => String(props.meta.functionCode || '').trim(),
  chartData,
  drillLevel,
  isDrilled,
  chartVisible,
  chartLoading,
  chartMaximized,
  pageMeta,
  notify
});

const { deleteLoading, handleDelete } = useWorkbenchDelete({
  gridApi,
  getFunctionCode: () => String(props.meta.functionCode || '').trim(),
  refreshAfterMutation: () => {
    const currentFunctionCode = String(props.meta.functionCode || '').trim();
    const currentParams = String(props.meta.params || '').trim();
    workbenchStore.clearCache(currentFunctionCode, currentParams);
    isDataLoaded.value = false;
    loadPage();
  },
  notify
});

const {
  popupVisible,
  popupLoading,
  popupField,
  popupLevels,
  popupMaxLevel,
  popupCascaderOptions,
  popupSelectedValue,
  handleOpenPopup,
  handleLoadCascaderChildren,
  handleCascaderValueChange,
  replacePopupSelection,
  appendPopupSelection
} = useWorkbenchPopupCascader({
  getFunctionCode: () => String(props.meta.functionCode || '').trim(),
  /**
   * 「添加」模式需要拿当前活动表单中该字段的原值做拼接。
   * 三类表单（新增 / 单条修改 / 多条修改）由 rightPanelMode 区分。
   * 注：setEditFieldValue 会同时写三类表单，理论上三者一致，但
   * 出于稳健性，仍按当前面板读对应 ref。
   */
  getCurrentValue: (fieldName: string) => {
    if (rightPanelMode.value === 'add') {
      return String(addFormData.value[fieldName] ?? '');
    }
    if (rightPanelMode.value === 'update') {
      return String(updateFormData.value[fieldName] ?? '');
    }
    if (rightPanelMode.value === 'batch') {
      return String(batchUpdateFormData.value[fieldName] ?? '');
    }
    return '';
  },
  onConfirmSelection: (fieldName: string, value: string, _mode: 'replace' | 'append') => {
    // 替换/添加的最终值已由 composable 算好（添加模式包含去重和 "," 拼接），
    // 这里只负责把值落到三个表单上。
    setEditFieldValue(fieldName, value);
  },
  notifyError: (message: string) => {
    window.$message?.error(message);
    logger.info('\x1b[31m[ERROR]\x1b[0m', message);
  }
});

function handleOpenCondition() {
  conditionVisible.value = true;
}

async function handleApplyCondition() {
  conditionVisible.value = false;
  await queryPage();
  notify('success', '已应用筛选条件');
}

// gridReady 处理（恢复列状态 / 筛选 / 排序 / 注册持久化监听）
const { handleGridReady } = useWorkbenchGridReady({
  gridApi,
  workbenchStore,
  visibleFieldColumns,
  pinTargetFields,
  isRestoringColumnState,
  page,
  registerGridPersistenceListeners,
  getFunctionCode: () => String(props.meta.functionCode || '').trim(),
  getParams: () => String(props.meta.params || '').trim(),
  fieldColumnOptions: () => fieldColumnOptions.value
});
</script>

<template>
  <div class="generic-query-workbench" :class="{ 'system-dark': isDarkMode }">
    <WorkbenchToolbar
      ref="toolbarRef"
      v-model:quick-keyword="quickKeyword"
      :function-code="String(pageMeta?.functionCode || props.meta.functionCode || '')"
      :page-meta="pageMeta"
      :has-table-modifications="hasTableModifications"
      :has-color-mark-enabled-columns="hasColorMarkEnabledColumns"
      :has-chart-enabled="hasChartEnabled"
      :update-loading="updateLoading"
      :batch-update-loading="batchUpdateLoading"
      :delete-loading="deleteLoading"
      @refresh="handleRefresh"
      @reset="handleReset"
      @open-pin-column="handleOpenPinColumn"
      @open-field-column="handleOpenFieldColumn"
      @open-condition="handleOpenCondition"
      @data-drill="openDataDrill"
      @open-add-comment="handleOpenAddCommentPanel"
      @open-view-comment="handleOpenViewCommentPanel"
      @open-color-mark="handleOpenColorMark"
      @open-chart="handleOpenChartPanel"
      @open-add="handleOpenAddPanel"
      @open-update="handleOpenUpdatePanel"
      @open-batch-update="handleOpenBatchUpdatePanel"
      @delete="handleDelete"
      @table-edit-submit="handleTableEditSubmit"
      @handle-import="handleImport"
      @handle-export="(exportAll: boolean) => handleExport({ exportAll })"
      @handle-debug="handleDebug"
      @upkeep="handleUpkeep"
    />

    <NAlert v-if="pageMeta?.fallbackHint" type="warning" class="mb-6px">
      {{ pageMeta.fallbackHint }}
    </NAlert>

    <NAlert v-if="useLegacyTabHint && !props.nativeOnly" type="info" class="mb-6px">
      表格、工具栏、条件面板已切到 Vue
      原生协议页；批量修改、备注、图形钻取、导出等深层能力建议暂时继续走“旧页回退”标签，直到对应动作接口补齐。
    </NAlert>

    <div
      ref="workbenchContentRef"
      class="workbench-content"
      :class="{
        'right-panel-mode': rightPanelVisible && !chartMaximized && !editPanelMaximized,
        'chart-mode': rightPanelMode === 'chart' && !chartMaximized,
        'edit-mode':
          (rightPanelMode === 'add' ||
            rightPanelMode === 'update' ||
            rightPanelMode === 'batch' ||
            rightPanelMode === 'comment') &&
          !chartMaximized &&
          !editPanelMaximized,
        resizing: anyRightPanelResizing
      }"
    >
      <!-- 左侧表格区域 -->
      <div
        v-show="!chartMaximized && !editPanelMaximized"
        class="table-area"
        :style="rightPanelVisible && !chartMaximized && !editPanelMaximized ? { flex: `0 0 ${activeLeftWidth}%` } : {}"
      >
        <NCard
          :bordered="false"
          :content-style="{ padding: '0' }"
          class="grid-card rounded-12px shadow-sm workbench-grid-card"
        >
          <div ref="gridShellRef" class="ag-theme-shell" :class="{ 'ag-theme-shell-dynamic': props.dynamicLike }">
            <div v-if="loading" class="grid-loading">
              <NSpin size="large" />
              <span class="loading-text">正在加载数据，请稍候...</span>
            </div>
            <AgGridVue
              :theme="activeGridTheme"
              :column-defs="gridColumns"
              :default-col-def="DEFAULT_COL_DEF"
              :row-height="35"
              :header-height="40"
              :row-data="processedRows"
              :quick-filter-text="quickKeyword"
              :locale-text="AG_GRID_LOCALE_CN"
              :pagination="true"
              :pagination-page-size="pageSize"
              :pagination-page-size-selector="paginationPageSizeSelector"
              :row-selection="{ mode: 'multiRow', checkboxes: true, headerCheckbox: false, selectAll: 'filtered' }"
              :selection-column-def="{
                width: 37,
                minWidth: 37,
                resizable: false,
                headerClass: 'selection-header-left',
                headerComponent: WorkbenchSelectAllHeader
              }"
              :row-buffer="20"
              :suppress-column-virtualisation="false"
              :suppress-row-virtualisation="false"
              :animate-rows="false"
              overlay-no-rows-template="<span style='padding: 20px; display: block; text-align: center;'>无数据</span>"
              overlay-loading-template="<span style='padding: 20px; display: block; text-align: center;'>正在加载数据，请稍候...</span>"
              class="query-grid"
              @grid-ready="handleGridReady"
              @cell-value-changed="(e: any) => handleCellValueChanged(e, hasTableEditAuth)"
            />
            <!-- 分片加载进度提示 -->
            <div v-if="isChunkLoading && !loading" class="chunk-loading-progress">
              <NSpin size="small" />
              <span class="progress-text">
                已加载 {{ loadedCount.toLocaleString() }} / {{ totalCount.toLocaleString() }} 条记录 ({{
                  ((loadedCount / totalCount) * 100).toFixed(1)
                }}%)
              </span>
            </div>
          </div>
        </NCard>
      </div>

      <!-- 可拖动分隔条 + 右侧分栏：chart / 新增 / 单条修改 / 多条修改 / 批注 互斥 -->
      <WorkbenchRightPanel
        :mode="rightPanelMode"
        :is-dark-mode="isDarkMode"
        :active-left-width="activeLeftWidth"
        :any-right-panel-resizing="anyRightPanelResizing"
        :chart-maximized="chartMaximized"
        :edit-panel-maximized="editPanelMaximized"
        :chart-options="chartOptions"
        :chart-loading="chartLoading"
        :is-drilled="isDrilled"
        :drill-level="drillLevel"
        :has-chart-debug="!!pageMeta?.toolbar.debugSql"
        :add-visible="addVisible"
        :add-loading="addLoading"
        :add-error="addError"
        :add-success="addSuccess"
        :add-form-fields="addFormFields"
        :add-form-data="addFormData"
        :update-visible="updateVisible"
        :update-loading="updateLoading"
        :update-error="updateError"
        :update-success="updateSuccess"
        :update-form-fields="updateFormFields"
        :update-form-data="updateFormData"
        :batch-update-visible="batchUpdateVisible"
        :batch-update-loading="batchUpdateLoading"
        :batch-update-error="batchUpdateError"
        :batch-update-success="batchUpdateSuccess"
        :batch-update-form-fields="batchUpdateFormFields"
        :batch-update-form-data="batchUpdateFormData"
        :add-comment-visible="addCommentVisible"
        :view-comment-visible="viewCommentVisible"
        :comment-loading="commentLoading"
        :comment-fields="commentFields"
        :comment-form-data="commentFormData"
        :comment-list="commentList"
        :comment-module-name="commentModuleName"
        :comment-remark="commentRemark"
        :key-field-list="keyFieldList"
        :key-field-count="keyFieldCount"
        @update:chart-maximized="chartMaximized = $event"
        @update:edit-panel-maximized="editPanelMaximized = $event"
        @update:add-form-data="addFormData = $event"
        @update:update-form-data="updateFormData = $event"
        @update:batch-update-form-data="batchUpdateFormData = $event"
        @update:comment-remark="commentRemark = $event"
        @close="handleCloseRightPanel"
        @refresh-chart="pageMeta && handleOpenChart(pageMeta)"
        @reset-drill="handleResetDrill"
        @chart-debug="handleChartDebug"
        @set-chart-ref="setChartRef"
        @confirm-add="confirmAdd"
        @confirm-update="confirmUpdate"
        @confirm-batch-update="confirmBatchUpdate"
        @submit-comment="handleSubmitComment"
        @open-popup="handleOpenPopup"
        @clear-add="clearAddPanel"
        @clear-update="clearUpdatePanel"
        @clear-batch="clearBatchUpdatePanel"
        @clear-comment="clearCommentPanel"
        @add-sample="handleAddSample"
        @splitter-mousedown="handleSplitterMouseDown"
      />
    </div>

    <WorkbenchPinColumnModal
      v-model:visible="pinColumnVisible"
      v-model:model-value="pinTargetFields"
      :options="pinColumnOptions"
      :is-dark-mode="isDarkMode"
      @change="handlePinSelectionChange"
      @clear="handleClearPinColumns"
    />

    <WorkbenchFieldColumnModal
      v-model:visible="fieldColumnVisible"
      v-model:model-value="visibleFieldColumns"
      :options="fieldColumnOptions"
      :is-dark-mode="isDarkMode"
      @change="handleFieldSelectionChange"
      @clear="handleClearFieldColumns"
      @select-all="handleSelectAllFieldColumns"
    />

    <WorkbenchConditionDrawer
      v-model:visible="conditionVisible"
      v-model:selected-field="selectedField"
      v-model:selected-operator="selectedOperator"
      v-model:selected-value="selectedValue"
      :filterable-fields="filterableFields"
      @apply="handleApplyCondition"
    />

    <!-- 颜色标注弹窗 -->
    <WorkbenchColorMarkModal
      v-model:visible="colorMarkVisible"
      v-model:field1="colorMarkField1"
      v-model:operator="colorMarkOperator"
      v-model:field2="colorMarkField2"
      v-model:color="colorMarkColor"
      :enabled-columns="colorMarkEnabledColumns"
      @apply="handleApplyColorMark"
      @clear="handleClearColorMark"
    />

    <!-- 导入弹窗 -->
    <WorkbenchImport
      v-model:visible="importVisible"
      :loading="importLoading"
      :preview-data="importPreviewData"
      :error="importError"
      :success="importSuccess"
      :preview-columns="importPreviewColumns"
      :is-dark-mode="isDarkMode"
      @trigger-file-input="triggerFileInput"
      @download-template="downloadImportTemplate"
      @reset="resetImportPreview"
      @confirm="confirmImport"
    >
      <template #file-input>
        <input
          ref="fileInputRef"
          type="file"
          accept=".xlsx,.xls,.csv"
          style="display: none"
          @change="handleFileSelect"
        />
      </template>
    </WorkbenchImport>

    <!-- 弹窗选择对话框（懒加载级联选择） -->
    <WorkbenchPopupSelect
      v-model:visible="popupVisible"
      :loading="popupLoading"
      :field="popupField"
      :selected-value="popupSelectedValue"
      :cascader-options="popupCascaderOptions"
      :levels="popupLevels"
      :max-level="popupMaxLevel"
      @update:selected-value="handleCascaderValueChange"
      @replace="replacePopupSelection"
      @append="appendPopupSelection"
      @load-children="handleLoadCascaderChildren"
    />

    <!-- 新增 / 单条修改 / 多条修改 已改为右侧分栏渲染，详见 workbench-content.chart-area -->
  </div>
</template>

<style lang="scss" scoped>
@use './generic-query-workbench.scss';
</style>
