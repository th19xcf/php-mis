<script lang="ts">
// 模块级加载锁，防止同一 functionCode 被多个组件实例重复加载
const _loadingLocks = new Map<string, boolean>();
</script>

<script setup lang="ts">
import { computed, ref, shallowRef, h, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';

import { AG_GRID_LOCALE_CN } from '@ag-grid-community/locale';
import {
  AllCommunityModule,
  ModuleRegistry,
  themeAlpine,
  type ColDef as _ColDef,
  type GridApi,
  type GridReadyEvent
} from 'ag-grid-community';
import { AgGridVue } from 'ag-grid-vue3';
import {
  NButton,
  NRadio,
  NRadioGroup,
  NForm,
  NFormItem,
  NSelect,
  NModal,
  NInput,
  NSpin,
  NAlert,
  NEmpty
} from 'naive-ui';
import * as XLSX from 'xlsx';

import {
  fetchWorkbenchPage as _fetchWorkbenchPage,
  fetchWorkbenchPageData,
  fetchWorkbenchDrill,
  fetchWorkbenchDebug
} from '@/service/api/workbench';
import { request } from '@/service/request';
import { useColorMark } from '@/hooks/business/use-color-mark';
import { useWorkbenchColumnSettings } from '@/hooks/business/use-workbench-column-settings';
import { useWorkbenchDelete } from '@/hooks/business/use-workbench-delete';
import { useWorkbenchEditForms } from '@/hooks/business/use-workbench-edit-forms';
import { useWorkbenchImport } from '@/hooks/business/use-workbench-import';
import { useWorkbenchPopupCascader } from '@/hooks/business/use-workbench-popup-cascader';
import { useToolbarScroll } from '@/hooks/business/use-toolbar-scroll';
import { useWorkbenchComment } from '@/hooks/business/use-workbench-comment';
import { useWorkbenchGridState } from '@/hooks/business/use-workbench-grid-state';
import { useWorkbenchChart } from '@/hooks/business/use-workbench-chart';
import { useWorkbenchTableEdit } from '@/hooks/business/use-workbench-table-edit';
import { useWorkbenchDataLoader } from '@/hooks/business/use-workbench-data-loader';
import { useThemeStore } from '@/store/modules/theme';
import { WORKBENCH_CONFIG } from '@/config/workbench';
import {
  WorkbenchImport,
  WorkbenchComment,
  WorkbenchAddForm,
  WorkbenchUpdateForm,
  WorkbenchPopupSelect
} from './components';

const router = useRouter();
const route = useRoute();

ModuleRegistry.registerModules([AllCommunityModule]);

interface MenuBridgeMeta {
  functionCode?: string;
  menu1?: string;
  menu2?: string;
  module?: string;
  params?: string;
}

type ConditionOperator = 'contains' | 'equals' | 'startsWith';
type QueryFilter = NonNullable<Api.Workbench.QueryPayload['filters']>[number];
type NotifyType = 'success' | 'error' | 'warning' | 'info';

function isGuidColumn(field: string, label: string) {
  return field.trim().toUpperCase() === 'GUID' || label.trim().toUpperCase() === 'GUID';
}

function msg(type: 'success' | 'error' | 'warning' | 'info', message: string, _data?: unknown) {
  window.$message?.[type](message);

  const prefix = `[${type.toUpperCase()}]`;

  switch (type) {
    case 'error':
      console.error(prefix, message);
      break;
    case 'warning':
      console.warn(prefix, message);
      break;
    case 'success':
      console.log(`%c${prefix}%c ${message}`, 'color: #52c41a; font-weight: bold;', '');
      break;
    case 'info':
      console.info(prefix, message);
      break;
    default:
      console.log(prefix, message);
  }
}

const props = defineProps<{
  meta: MenuBridgeMeta;
  nativeOnly?: boolean;
  dynamicLike?: boolean;
}>();

const { GRID_THEME } = WORKBENCH_CONFIG;

const lightGridTheme = themeAlpine.withParams(GRID_THEME.LIGHT);
const darkGridTheme = themeAlpine.withParams(GRID_THEME.DARK);

const themeStore = useThemeStore();
const isDarkMode = computed(() => themeStore.darkMode);
const activeGridTheme = computed(() => (isDarkMode.value ? darkGridTheme : lightGridTheme));

const PAGE_SIZE_OPTIONS = [500, 1000, 2000] as const;
const paginationPageSizeSelector = [...PAGE_SIZE_OPTIONS];

const useLegacyTabHint = ref(false);
const gridApi = ref<GridApi<Api.Workbench.QueryRecord> | null>(null);

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
const pageSize = ref(PAGE_SIZE_OPTIONS[0]);
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

// 颜色标注相关

// 批注相关

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
  reloadPage: () => loadPage(),
  notify: (type: NotifyType, message: string) => msg(type, message)
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
  notify: (type: NotifyType, message: string, data?: unknown) => msg(type, message, data)
});

// 工具栏滚动相关
const { toolbarScrollRef, showLeftArrow, showRightArrow, checkScrollPosition, scrollToolbar } = useToolbarScroll();
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

const defaultColDef = {
  width: 120,
  minWidth: 0,
  // 不设置 maxWidth，允许自适应到任意宽度
  resizable: true,
  editable: true,
  filter: true,
  filterParams: {
    maxNumConditions: 5
  }
};

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

// 分栏宽度调整相关状态
const leftPanelWidth = ref(55); // 左侧面板宽度百分比
const isResizing = ref(false);
const workbenchContentRef = ref<HTMLDivElement | null>(null);

// 开始拖动调整大小
function startResize(e: MouseEvent) {
  isResizing.value = true;
  document.body.style.cursor = 'col-resize';
  document.body.style.userSelect = 'none';

  const startX = e.clientX;
  const containerWidth = workbenchContentRef.value?.clientWidth || window.innerWidth;
  const startLeftWidth = leftPanelWidth.value;

  function handleMouseMove(moveEvent: MouseEvent) {
    if (!isResizing.value) return;

    const deltaX = moveEvent.clientX - startX;
    const deltaPercent = (deltaX / containerWidth) * 100;
    let newLeftWidth = startLeftWidth + deltaPercent;

    newLeftWidth = Math.max(15, Math.min(70, newLeftWidth));
    leftPanelWidth.value = newLeftWidth;

    chartResize();
  }

  function handleMouseUp() {
    isResizing.value = false;
    document.body.style.cursor = '';
    document.body.style.userSelect = '';
    document.removeEventListener('mousemove', handleMouseMove);
    document.removeEventListener('mouseup', handleMouseUp);

    const { STORAGE_KEYS } = WORKBENCH_CONFIG;
    localStorage.setItem(STORAGE_KEYS.LEFT_PANEL_WIDTH, String(leftPanelWidth.value));
  }

  document.addEventListener('mousemove', handleMouseMove);
  document.addEventListener('mouseup', handleMouseUp);
}

// 加载保存的布局状态
function _loadLayoutState() {
  const { STORAGE_KEYS } = WORKBENCH_CONFIG;
  const savedWidth = localStorage.getItem(STORAGE_KEYS.LEFT_PANEL_WIDTH);
  if (savedWidth) {
    leftPanelWidth.value = parseFloat(savedWidth);
  }
}

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
  notify: (type: NotifyType, message: string) => msg(type, message)
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
  defaultPageSize: PAGE_SIZE_OPTIONS[0],
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
  notify: (type: NotifyType, message: string) => msg(type, message),
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
  notify: (type: NotifyType, message: string) => msg(type, message)
});

const {
  chartVisible,
  chartLoading,
  chartOptions,
  setChartRef,
  handleOpenChart,
  resizeChart: chartResize
} = useWorkbenchChart({
  getFunctionCode: () => String(route.query.functionCode || route.meta?.functionCode || ''),
  notify: (type: NotifyType, message: string) => msg(type, message)
});

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
  notify: (type: NotifyType, message: string) => msg(type, message),
  loadPage,
  serverRows,
  pageMeta,
  colorMarkConfig,
  isDarkMode
});

// 性能计时工具
function _createTimer(label: string) {
  const start = performance.now();
  return {
    end: () => {
      const duration = performance.now() - start;
      console.log(`[性能计时] ${label}: ${duration.toFixed(2)}ms`);
      return duration;
    }
  };
}

/**
 * 使用分页 API 获取所有数据（用于导出等需要全量数据的场景）
 */
async function fetchAllRows(fnCode: string, filters: QueryFilter[], drillConditionSql?: string) {
  const allRows: Api.Workbench.QueryRecord[] = [];
  let current = 1;
  const size = 5000; // 每页 5000 条
  let hasMore = true;

  while (hasMore) {
    const result = await fetchWorkbenchPageData(fnCode, {
      current,
      size,
      fetchTotal: current === 1, // 只有第一页需要总数
      filters,
      drillCondition: drillConditionSql || undefined
    });

    if (result.error) {
      break;
    }

    allRows.push(...result.data.records);
    hasMore = result.data.hasMore;
    current++;

    // 如果数据量很大，让出时间片避免阻塞
    if (allRows.length % 10000 === 0) {
      await new Promise(resolve => setTimeout(resolve, 10));
    }
  }

  return allRows;
}

async function queryPage() {
  const fnCode = String(props.meta.functionCode || '').trim();
  if (!fnCode) {
    return;
  }

  const filters =
    selectedField.value && selectedValue.value.trim()
      ? [
          {
            fieldKey: selectedField.value,
            operator: selectedOperator.value,
            value: selectedValue.value.trim()
          }
        ]
      : [];

  loading.value = true;
  const allRows = await fetchAllRows(fnCode, filters);
  if (!allRows) {
    loading.value = false;
    return;
  }

  serverRows.value = allRows;
  total.value = allRows.length;
  page.value = 1;
  loading.value = false;
}

async function handleRefresh() {
  const fnCode = String(props.meta.functionCode || '').trim();
  const fnParams = String(props.meta.params || '').trim();

  // 清除 store 缓存，强制重新加载
  if (fnCode) {
    workbenchStore.clearCache(fnCode, fnParams);
  }

  // 重置所有查询条件到初始状态
  quickKeyword.value = '';
  selectedField.value = pageMeta.value?.conditions[0]?.fieldKey || '';
  selectedOperator.value = 'contains';
  selectedValue.value = '';

  // 重置颜色标注
  resetColorMarkState();

  // 重置字段选择（显示所有字段）
  visibleFieldColumns.value = fieldColumnOptions.value.map(item => String(item.value));
  if (gridApi.value) {
    const allColumnFields = fieldColumnOptions.value.map(item => String(item.value));
    gridApi.value.setColumnsVisible(allColumnFields, true);
  }

  // 重置固定列
  pinTargetFields.value = [];
  if (gridApi.value) {
    gridApi.value.applyColumnState({
      state: fieldColumnOptions.value.map(item => ({
        colId: String(item.value),
        pinned: null
      })),
      defaultState: { pinned: null }
    });
  }

  // 清除 AG Grid 排序
  if (gridApi.value) {
    gridApi.value.applyColumnState({
      state: fieldColumnOptions.value.map(item => ({
        colId: String(item.value),
        sort: null
      })),
      defaultState: { sort: null }
    });
  }

  // 清除 AG Grid 筛选条件
  if (gridApi.value) {
    gridApi.value.setFilterModel(null);
  }

  // 重置提示
  useLegacyTabHint.value = false;

  // 重新加载数据
  await loadPage();

  // 清除修改状态以清除样式
  tableModifiedRows.value.clear();
  modifiedRowsData.value.clear();

  msg('success', '已刷新并恢复到初始状态');
}

function handleReset() {
  // 1. 清除所有查询条件
  quickKeyword.value = '';
  selectedField.value = pageMeta.value?.conditions[0]?.fieldKey || '';
  selectedOperator.value = 'contains';
  selectedValue.value = '';

  // 3. 显示所有字段（取消隐藏）
  visibleFieldColumns.value = fieldColumnOptions.value.map(item => String(item.value));
  if (gridApi.value) {
    const allColumnFields = fieldColumnOptions.value.map(item => String(item.value));
    gridApi.value.setColumnsVisible(allColumnFields, true);
  }

  // 4. 取消固定列
  pinTargetFields.value = [];
  if (gridApi.value) {
    gridApi.value.applyColumnState({
      state: fieldColumnOptions.value.map(item => ({
        colId: String(item.value),
        pinned: null
      })),
      defaultState: { pinned: null }
    });
  }

  // 5. 清除排序
  if (gridApi.value) {
    gridApi.value.applyColumnState({
      state: fieldColumnOptions.value.map(item => ({
        colId: String(item.value),
        sort: null
      })),
      defaultState: { sort: null }
    });
  }

  // 6. 清除筛选
  if (gridApi.value) {
    gridApi.value.setFilterModel(null);
  }

  // 同时清除 store 中的筛选和排序缓存
  const resetFunctionCode = String(props.meta.functionCode || '').trim();
  const resetParams = String(props.meta.params || '').trim();
  if (resetFunctionCode) {
    workbenchStore.clearFilterModel(resetFunctionCode, resetParams);
    workbenchStore.clearColumnState(resetFunctionCode, resetParams);
  }

  // 清除颜色标注配置和修改状态
  resetColorMarkState();
  tableModifiedRows.value.clear();
  modifiedRowsData.value.clear();

  // 重置提示
  useLegacyTabHint.value = false;

  // 8. 显示提示
  msg('success', '已重置到初始状态');
}

function handleOpenCondition() {
  conditionVisible.value = true;
}

async function handleApplyCondition() {
  conditionVisible.value = false;
  await queryPage();
  msg('success', '已应用筛选条件');
}

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
  notify: (type: NotifyType, message: string) => msg(type, message)
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
  confirmPopupSelection
} = useWorkbenchPopupCascader({
  getFunctionCode: () => String(props.meta.functionCode || '').trim(),
  onConfirmSelection: (fieldName: string, value: string) => {
    setEditFieldValue(fieldName, value);
  },
  notifyError: (message: string) => {
    window.$message?.error(message);
    console.log('\x1b[31m[ERROR]\x1b[0m', message);
  }
});
// 获取字段选项
interface FieldOption {
  objectOptions?: Array<{ label: string; value: string }>;
}
function _getFieldOptions(field: FieldOption): Array<{ label: string; value: string }> {
  return field.objectOptions || [];
}

function handleExport() {
  if (!gridApi.value) {
    msg('warning', '表格未初始化，无法导出');
    return;
  }

  // 获取当前显示的所有数据（包括分页加载的所有数据）
  const rowData: any[] = [];
  gridApi.value.forEachNode(node => {
    rowData.push(node.data);
  });

  if (rowData.length === 0) {
    msg('warning', '当前没有数据可导出');
    return;
  }

  // 获取当前显示的列定义
  const columns = gridApi.value.getColumns() || [];
  const visibleColumns = columns.filter(col => {
    const colDef = col.getColDef();
    // 排除隐藏列和选择列
    return !colDef.hide && colDef.field && colDef.field !== '';
  });

  // 构建表头
  const headers = visibleColumns.map(col => {
    const colDef = col.getColDef();
    return colDef.headerName || colDef.field || '';
  });

  // 构建数据行
  const exportData = rowData.map(row => {
    const rowObj: Record<string, any> = {};
    visibleColumns.forEach(col => {
      const colDef = col.getColDef();
      const field = colDef.field;
      if (field) {
        const headerName = colDef.headerName || field;
        rowObj[headerName] = row[field] ?? '';
      }
    });
    return rowObj;
  });

  // 创建工作簿
  const wb = XLSX.utils.book_new();
  const ws = XLSX.utils.json_to_sheet(exportData, { header: headers });

  // 设置列宽
  const colWidths = headers.map(header => ({
    wch: Math.max(header.length * 2, 12)
  }));
  ws['!cols'] = colWidths;

  // 添加工作表到工作簿
  XLSX.utils.book_append_sheet(wb, ws, '数据');

  // 生成文件名
  const fnCode = props.meta?.functionCode || 'export';
  const timestamp = new Date().toISOString().slice(0, 19).replace(/:/g, '-');
  const filename = `${fnCode}_${timestamp}.xlsx`;

  // 下载文件
  XLSX.writeFile(wb, filename);

  msg('success', `成功导出 ${rowData.length} 条数据`);
}

async function handleDebug() {
  const fnCode = String(props.meta.functionCode || '').trim();
  if (!fnCode) {
    msg('error', '功能编码不能为空');
    return;
  }

  try {
    const payload: Api.Workbench.QueryPayload = {
      all: true,
      filters: []
    };

    const { data, error } = await fetchWorkbenchDebug(fnCode, payload);

    if (error) {
      msg('error', '获取调试信息失败');
      return;
    }

    console.group('🔍 调试信息 - ' + data.functionCode);
    console.log('📊 查询配置:');
    console.log('  - 查询表:', data.queryTable);
    console.log('  - 查询模式:', data.mode);
    console.log('  - WHERE 条件:', data.queryWhere || '(无)');
    console.log('  - GROUP BY:', data.queryGroup || '(无)');
    console.log('  - ORDER BY:', data.queryOrder || '(无)');

    console.log('\n📝 SELECT 部分:');
    data.selectParts.forEach((part, index) => {
      console.log(`  ${index + 1}. ${part}`);
    });

    console.log('\n🔧 WHERE 部分:');
    if (data.whereParts.length > 0) {
      data.whereParts.forEach((part, index) => {
        console.log(`  ${index + 1}. ${part}`);
      });
    } else {
      console.log('  (无)');
    }

    console.log('\n💻 SQL 语句:');
    console.log('  计数 SQL:', data.countSql || '(不适用)');
    console.log('  查询 SQL:', data.querySql);

    console.log('\n👤 用户权限:');
    console.log('  - 公司ID:', data.userAuth.companyId);
    console.log('  - 工号:', data.userAuth.userWorkId);
    console.log(
      '  - 角色编码:',
      Array.isArray(data.userAuth.roleCodes) ? data.userAuth.roleCodes.join(', ') : data.userAuth.roleCodes || '(无)'
    );
    console.log('  - 属地赋权:', data.userAuth.locationAuth);
    console.log(
      '  - 部门编码赋权:',
      Array.isArray(data.userAuth.deptCodeAuth)
        ? data.userAuth.deptCodeAuth.join(', ')
        : data.userAuth.deptCodeAuth || '(无)'
    );
    console.log(
      '  - 部门全称赋权:',
      Array.isArray(data.userAuth.deptNameAuth)
        ? data.userAuth.deptNameAuth.join(', ')
        : data.userAuth.deptNameAuth || '(无)'
    );
    console.log('  - 调试权限:', data.userAuth.debugAuth ? '有' : '无');

    console.log('\n⚙️ 功能权限:');
    console.log('  - 模块:', data.functionAuth.module);
    console.log('  - 参数:', data.functionAuth.params || '(无)');
    console.log('  - 部门权限条件:', data.functionAuth.deptAuthCond || '(无)');
    console.log('  - 属地权限条件:', data.functionAuth.locationAuthCond || '(无)');

    console.log('\n📋 字段映射:');
    console.table(data.columns);

    // 获取图表配置信息以获取图形名称
    let chartNames: string[] = [];
    if (data.chartModule) {
      try {
        const chartResponse = await request({
          url: `/workbench/chart/${fnCode}`
        });
        if (chartResponse.data?.charts && chartResponse.data.charts.length > 0) {
          chartNames = chartResponse.data.charts.map(
            (chart: any) => chart['图形名称'] || `图形 ${chartNames.length + 1}`
          );
        }
      } catch (chartError) {
        console.log('获取图表配置失败:', chartError);
      }
    }

    // 输出图形相关 SQL
    console.log('\n📈 图形 SQL:');
    console.log('chartModule:', data.chartModule);

    // 将占位符替换为真实值
    const replacePlaceholders = (sql: string): string => {
      let result = sql;
      // 原始 SQL 中已经有引号包裹，直接替换即可
      result = result.replace(/\$查询表名/g, data.queryTable);
      // 部门全称_ 参数类型是 JSON，需要转换为 JSON 数组字符串格式（带引号包裹）
      const deptNameAuth = Array.isArray(data.userAuth.deptNameAuth)
        ? data.userAuth.deptNameAuth
        : data.userAuth.deptNameAuth
          ? [data.userAuth.deptNameAuth]
          : [];
      const deptNameJson = JSON.stringify(deptNameAuth).replace(/"/g, '\\"');
      result = result.replace(/\$\[部门全称赋权\]/g, `"${deptNameJson}"`);
      return result;
    };

    // 输出替换后的 chartSql
    if (data.chartSql && Array.isArray(data.chartSql)) {
      const replacedChartSql = data.chartSql.map((chart: any) => ({
        ...chart,
        sql: chart.sql ? replacePlaceholders(chart.sql) : chart.sql
      }));
      console.log('chartSql (已替换占位符):', JSON.stringify(replacedChartSql, null, 2));
    } else {
      console.log('chartSql:', JSON.stringify(data.chartSql, null, 2));
    }

    // 输出图形配置信息到控制台
    console.log('\n========================================');
    console.log('📈 图形配置信息');
    console.log('========================================');
    console.log('chartModule:', data.chartModule);
    console.log('\n查询 SQL:');
    console.log(data.chartQuerySql || '(无)');
    console.log('\nchartSql 数组长度:', data.chartSql?.length || 0);

    // 输出完整的 chartSql 数据结构
    console.log('\nchartSql 完整数据:');
    console.log(JSON.stringify(data.chartSql, null, 2));

    interface ChartSqlItem {
      name?: string;
      图形名称?: string;
      sql?: string;
      error?: string;
    }
    if (data.chartSql && data.chartSql.length > 0) {
      console.log('\n图形 SQL 明细:');
      (data.chartSql as ChartSqlItem[]).forEach((chart: ChartSqlItem, index: number) => {
        console.log(`\n--- 图形 ${index + 1} ---`);
        console.log('名称:', chart['图形名称'] || chart.name || chartNames[index] || '未命名');
        console.log('SQL:', chart.sql ? replacePlaceholders(chart.sql) : '(无)');
        if (chart.error) {
          console.log('错误:', chart.error);
        }
      });
    } else {
      console.log('\n❌ 未查询到图形配置');
      console.log('请检查 def_chart_config 表中是否存在图形模块:', data.chartModule);
      console.log('或者检查表中是否有顺序>0 的有效记录');
    }
    console.log('========================================\n');

    if (data.chartSql && Array.isArray(data.chartSql) && data.chartSql.length > 0) {
      (data.chartSql as ChartSqlItem[]).forEach((chart: ChartSqlItem, index: number) => {
        console.log(`  图形 ${index + 1}: ${chart['图形名称'] || chart.name || chartNames[index] || '未命名'}`);
        console.log(`    SQL: ${chart.sql ? replacePlaceholders(chart.sql) : '(无)'}`);
        if (chart.error) {
          console.log(`    错误: ${chart.error}`);
        }
      });
    } else {
      console.log('  (无图形配置或chartModule为空)');
    }

    console.groupEnd();

    msg('success', '调试信息已输出到控制台');
  } catch (err) {
    msg('error', '获取调试信息失败');
    console.error('调试信息获取错误:', err);
  }
}

function handleDataDrill() {
  // 参考旧版 Vgrid_aggrid.php，必须先选择 1 条记录
  const selectedRows = gridApi.value?.getSelectedRows() || [];
  if (selectedRows.length === 0) {
    msg('warning', '请先选择要钻取的记录');
    return;
  }
  if (selectedRows.length > 1) {
    msg('warning', '只能选择 1 条记录');
    return;
  }

  const fnCode = String(props.meta.functionCode || '').trim();
  if (!fnCode) {
    msg('error', '功能编码不能为空');
    return;
  }

  const selectedRow = selectedRows[0];

  loading.value = true;
  fetchWorkbenchDrill(fnCode, {})
    .then(({ data, error }) => {
      loading.value = false;
      if (error) {
        msg('error', '获取钻取选项失败', error);
        return;
      }

      if (data.options && data.options.length > 0) {
        // 参考旧版：显示钻取选项（单选按钮）
        let dialogInstance: any = null;
        const options = data.options.map((opt: Api.Workbench.DrillOption, index: number) => ({
          label: opt.label,
          value: `${opt.functionCode}_${index}`,
          functionCode: opt.functionCode,
          module: opt.module || '',
          drillFields: opt.drillFields || '',
          drillCondition: opt.drillCondition || '',
          menu1: opt.menu1 || '',
          menu2: opt.menu2 || '',
          raw: opt
        }));

        // 使用 ref 存储选中的选项
        const selectedOption = ref<(typeof options)[0] | null>(options[0] || null);

        // 先定义 handleDrillConfirm 函数
        const handleDrillConfirm = (selectedOpt: (typeof options)[0]) => {
          // 参考旧版 Vgrid_aggrid.php 的钻取参数构建逻辑
          const drillItem = selectedOpt.raw;
          const drillFieldsStr = drillItem.drillFields || '';
          const sendObj: Record<string, any> = {};

          // 处理钻取字段：格式为 字段1;字段2;...
          const nlArr = drillFieldsStr.split(';').filter(f => f.trim());

          let hasValidField = false;

          for (const field of nlArr) {
            const trimmedField = field.trim();
            if (trimmedField && selectedRow[trimmedField] !== undefined && selectedRow[trimmedField] !== '') {
              sendObj[trimmedField] = selectedRow[trimmedField];
              hasValidField = true;
            }
          }

          if (!hasValidField) {
            msg('warning', '钻取字段为空，无法钻取');
            return;
          }

          sendObj['钻取字段'] = drillItem.drillFields || '';
          sendObj['钻取条件'] = drillItem.drillCondition || '';

          // 参考旧版：获取显示的列
          const visibleColumns: string[] = [];
          const columns = gridApi.value?.getColumns() || [];
          for (const col of columns) {
            if (col.getColId() === 'ag-Grid-SelectionColumn') continue;
            if (col.getColId() === '序号') continue;
            if (!col.isVisible()) continue;
            visibleColumns.push(col.getColId());
          }
          sendObj['字段选择'] = visibleColumns;

          // 参考旧版 parent.window.goto 跳转逻辑
          const targetFunctionCode = drillItem.functionCode;
          const targetModule = drillItem.module || '';
          const targetMenu1 = drillItem.menu1 || '';
          const targetMenu2 = drillItem.menu2 || '';

          // 跳转到目标功能页面，将所有钻取参数放到 query 中，避免 sessionStorage 时序问题
          router.push({
            path: `/menu-bridge`,
            query: {
              functionCode: targetFunctionCode,
              module: targetModule,
              menu1: targetMenu1,
              menu2: targetMenu2,
              params: JSON.stringify(sendObj)
            }
          });
        };

        // 定义 renderDrillDialogContent 函数（在 handleDrillConfirm 之后）
        // 使用 ref 存储选中值
        const drillSelectedValue = ref<string>(options[0]?.value || '');

        const renderDrillDialogContent = () => {
          // 处理单选按钮点击
          const handleRadioClick = (value: string) => {
            drillSelectedValue.value = value;
            selectedOption.value = options.find(opt => opt.value === value) || null;
          };

          return h('div', { style: { display: 'flex', flexDirection: 'column', minHeight: '250px' } }, [
            // 选项区域
            h(
              NRadioGroup,
              {
                value: drillSelectedValue.value,
                'onUpdate:value': (value: string) => {
                  handleRadioClick(value);
                },
                style: { flex: 1, overflow: 'auto', padding: '16px' }
              },
              {
                default: () =>
                  options.map(opt =>
                    h(
                      NRadio,
                      {
                        value: opt.value,
                        style: { display: 'flex', marginBottom: '8px', alignItems: 'center' }
                      },
                      { default: () => opt.label }
                    )
                  )
              }
            ),
            // 底部按钮区域
            h(
              'div',
              {
                style: { display: 'flex', justifyContent: 'flex-end', padding: '16px', borderTop: '1px solid #3d4f60' }
              },
              h(
                NButton,
                {
                  type: 'primary',
                  onClick: () => {
                    if (!selectedOption.value) {
                      msg('warning', '请选择钻取条件');
                      return;
                    }
                    // 关闭弹窗后再执行钻取确认
                    if (dialogInstance) {
                      dialogInstance.destroy();
                    }
                    handleDrillConfirm(selectedOption.value);
                  }
                },
                { default: () => '确定' }
              )
            )
          ]);
        };

        // 显示弹窗
        dialogInstance = window.$dialog?.info({
          title: '选择钻取条件',
          style: { width: '350px', minHeight: '300px' },
          content: renderDrillDialogContent
        });
      } else {
        const drillModule = data.debug?.drillModule || 'empty';
        const queryModule = data.debug?.queryModule || 'empty';

        if (drillModule && drillModule !== 'empty' && drillModule !== queryModule) {
          msg('warning', `钻取模块 [${drillModule}] 在 def_drill_config 表中未找到配置`);
        } else if (queryModule && queryModule !== 'empty') {
          msg('warning', `查询模块 [${queryModule}] 未配置钻取模块，且 def_drill_config 表中也无对应配置`);
        } else {
          msg('warning', '当前功能未配置钻取模块，请联系管理员');
        }
      }
    })
    .catch(err => {
      loading.value = false;
      msg('error', '钻取操作失败', err);
    });
}

function handleGridReady(event: GridReadyEvent<Api.Workbench.QueryRecord>) {
  gridApi.value = event.api;
  visibleFieldColumns.value = fieldColumnOptions.value.map(item => String(item.value));

  const fnCode = String(props.meta.functionCode || '').trim();
  const fnParams = String(props.meta.params || '').trim();

  // 恢复筛选条件
  const cachedFilterModel = workbenchStore.getFilterModel(fnCode, fnParams);
  if (cachedFilterModel) {
    setTimeout(() => {
      if (gridApi.value && !gridApi.value.isDestroyed()) {
        gridApi.value.setFilterModel(cachedFilterModel);
      }
    }, 100);
  }

  // 恢复列状态（包括排序、列宽、列顺序、固定列等）
  const cachedColumnState = workbenchStore.getColumnState(fnCode, fnParams);
  if (cachedColumnState && Array.isArray(cachedColumnState) && cachedColumnState.length > 0) {
    setTimeout(() => {
      if (gridApi.value && !gridApi.value.isDestroyed()) {
        isRestoringColumnState.value = true;

        // 合并固定列信息
        const cachedPinColumns = workbenchStore.getPinColumns(fnCode, fnParams);
        const pinColumnsArray = Array.from(cachedPinColumns);
        const mergedColumnState = cachedColumnState.map((col: any) => {
          if (pinColumnsArray.includes(col.colId)) {
            return { ...col, pinned: 'left' };
          }
          return col;
        });

        gridApi.value.applyColumnState({ state: mergedColumnState, applyOrder: true });

        // 恢复可见列
        const cachedVisibleColumns = workbenchStore.getVisibleColumns(fnCode, fnParams);
        if (cachedVisibleColumns.length > 0) {
          visibleFieldColumns.value = cachedVisibleColumns;
        }

        // 恢复固定列
        if (cachedPinColumns.length > 0) {
          pinTargetFields.value = cachedPinColumns;
        }

        setTimeout(() => {
          isRestoringColumnState.value = false;
        }, 100);
      }
    }, 150);
  }

  event.api.addEventListener('sortChanged', () => {
    if (isRestoringColumnState.value) {
      return;
    }
    const currentPage = event.api.paginationGetCurrentPage();
    if (currentPage !== 0) {
      event.api.paginationGoToFirstPage();
      page.value = 1;
    }
  });

  registerGridPersistenceListeners();
}
</script>

<template>
  <div class="generic-query-workbench" :class="{ 'system-dark': isDarkMode }">
    <NCard
      :bordered="false"
      :content-style="{ padding: '1px 10px' }"
      class="toolbar-card mb-2px rounded-12px shadow-sm"
    >
      <div class="flex items-center gap-12px">
        <!-- 左侧按钮区域 - 可横向滚动 -->
        <div class="flex items-center flex-1 min-w-0">
          <!-- 左箭头 -->
          <NButton
            v-if="showLeftArrow"
            quaternary
            circle
            size="small"
            class="scroll-arrow mr-8px"
            @click="scrollToolbar('left')"
          >
            <template #icon>
              <SvgIcon icon="material-symbols:chevron-left" />
            </template>
          </NButton>

          <!-- 按钮容器 -->
          <div
            ref="toolbarScrollRef"
            class="toolbar-scroll flex items-center gap-8px flex-nowrap overflow-x-hidden"
            @scroll="checkScrollPosition"
          >
            <NButton @click="handleRefresh">刷新</NButton>
            <NButton @click="handleReset">重置</NButton>
            <NButton @click="handleOpenPinColumn">固定列</NButton>
            <NButton @click="handleOpenFieldColumn">字段选择</NButton>
            <NButton @click="handleOpenCondition">条件面板</NButton>
            <NButton @click="handleDataDrill">数据钻取</NButton>
            <NButton v-if="pageMeta?.toolbar.comment" @click="handleOpenAddComment">添加批注</NButton>
            <NButton v-if="pageMeta?.toolbar.comment" @click="handleOpenViewComment">查看批注</NButton>
            <NButton v-if="hasColorMarkEnabledColumns" @click="handleOpenColorMark">颜色标注</NButton>
            <NButton v-if="hasChartEnabled" @click="() => handleOpenChart(pageMeta)">图形</NButton>
            <NButton v-if="pageMeta?.toolbar.add" @click="handleOpenAdd">新增</NButton>
            <NButton v-if="pageMeta?.toolbar.edit" :disabled="updateLoading" @click="handleOpenUpdate">
              单条修改
            </NButton>
            <NButton v-if="pageMeta?.toolbar.batchEdit" :disabled="batchUpdateLoading" @click="handleOpenBatchUpdate">
              多条修改
            </NButton>
            <NButton v-if="pageMeta?.toolbar.delete" :disabled="deleteLoading" @click="handleDelete">删除</NButton>
            <NButton
              v-if="pageMeta?.toolbar.tableEdit"
              :disabled="!hasTableModifications"
              @click="handleTableEditSubmit"
            >
              表级修改提交
            </NButton>
            <NButton v-if="pageMeta?.toolbar.import" @click="handleImport">导入</NButton>
            <NButton :disabled="!pageMeta?.toolbar.export" @click="handleExport">导出</NButton>
            <NButton v-if="pageMeta?.toolbar.debugSql" type="warning" class="debug-btn" @click="handleDebug">
              调试
            </NButton>
          </div>

          <!-- 右箭头 -->
          <NButton
            v-if="showRightArrow"
            quaternary
            circle
            size="small"
            class="scroll-arrow ml-8px"
            @click="scrollToolbar('right')"
          >
            <template #icon>
              <SvgIcon icon="material-symbols:chevron-right" />
            </template>
          </NButton>
        </div>

        <!-- 右侧搜索框和信息栏 - 固定 -->
        <div class="flex items-center gap-12px flex-shrink-0">
          <NInput v-model:value="quickKeyword" clearable placeholder="快速检索当前结果" class="w-280px" />
          <NTag type="success" size="small">{{ String(pageMeta?.functionCode || props.meta.functionCode || '') }}</NTag>
        </div>
      </div>
    </NCard>

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
      :class="{ 'chart-mode': chartVisible, resizing: isResizing }"
    >
      <!-- 左侧表格区域 -->
      <div class="table-area" :style="chartVisible ? { flex: `0 0 ${leftPanelWidth}%` } : {}">
        <NCard
          :bordered="false"
          :content-style="{ padding: '0' }"
          class="grid-card rounded-12px shadow-sm workbench-grid-card"
        >
          <div ref="gridShellRef" class="ag-theme-shell" :class="{ 'ag-theme-shell-dynamic': props.dynamicLike }">
            <div v-if="loading" class="grid-loading">
              <NSpin size="large" />
            </div>
            <AgGridVue
              :theme="activeGridTheme"
              :column-defs="gridColumns"
              :default-col-def="defaultColDef"
              :row-height="38"
              :header-height="40"
              :row-data="processedRows"
              :quick-filter-text="quickKeyword"
              :locale-text="AG_GRID_LOCALE_CN"
              :pagination="true"
              :pagination-page-size="pageSize"
              :pagination-page-size-selector="paginationPageSizeSelector"
              :row-selection="{ mode: 'multiRow', checkboxes: true, headerCheckbox: true }"
              :selection-column-def="{
                width: 37,
                minWidth: 37,
                resizable: false,
                headerClass: 'selection-header-left'
              }"
              :row-buffer="20"
              :suppress-column-virtualisation="false"
              :suppress-row-virtualisation="false"
              :animate-rows="false"
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

      <!-- 可拖动分隔条 -->
      <div
        v-if="chartVisible"
        class="resize-splitter"
        :class="{ 'is-resizing': isResizing }"
        title="拖动调整宽度"
        @mousedown="startResize"
      >
        <div class="resize-line" />
      </div>

      <!-- 右侧图形区域 -->
      <div v-show="chartVisible" class="chart-area" :style="{ flex: `0 0 ${100 - leftPanelWidth}%` }">
        <div class="chart-panel rounded-12px shadow-sm">
          <div class="chart-header">
            <span class="chart-title">图形展示</span>
            <NButton size="small" @click="chartVisible = false">关闭</NButton>
          </div>
          <div class="chart-container">
            <NSpin :show="chartLoading">
              <template v-if="chartOptions.length > 0">
                <div
                  v-for="(option, index) in chartOptions"
                  :key="index"
                  :ref="el => setChartRef(el as HTMLDivElement, index)"
                  :class="['chart-wrapper', option.chartLayout || 'box_1-1-1']"
                ></div>
              </template>
              <NEmpty v-else-if="!chartLoading" description="暂无图形数据" />
            </NSpin>
          </div>
        </div>
      </div>
    </div>

    <NModal
      v-model:show="pinColumnVisible"
      preset="card"
      title="固定列"
      class="w-420px pin-column-modal"
      :class="{ 'pin-column-modal-dark': isDarkMode }"
      :mask-closable="true"
    >
      <NSpace vertical :size="16">
        <div class="pin-column-select-panel">
          <div class="pin-column-actions">
            <NCheckbox
              :checked="pinTargetFields.length === 0"
              @update:checked="checked => checked && handleClearPinColumns()"
            >
              全不选
            </NCheckbox>
          </div>

          <NCheckboxGroup
            v-model:value="pinTargetFields"
            class="pin-column-group"
            @update:value="handlePinSelectionChange"
          >
            <NSpace vertical :size="10">
              <NCheckbox v-for="item in pinColumnOptions" :key="String(item.value)" :value="String(item.value)">
                {{ item.label }}
              </NCheckbox>
            </NSpace>
          </NCheckboxGroup>
        </div>
      </NSpace>
    </NModal>

    <NModal
      v-model:show="fieldColumnVisible"
      preset="card"
      title="字段选择"
      class="w-420px pin-column-modal"
      :class="{ 'pin-column-modal-dark': isDarkMode }"
      :mask-closable="true"
    >
      <NSpace vertical :size="16">
        <div class="pin-column-select-panel">
          <div class="pin-column-actions">
            <NCheckbox
              :checked="visibleFieldColumns.length === fieldColumnOptions.length && fieldColumnOptions.length > 0"
              @update:checked="checked => (checked ? handleSelectAllFieldColumns() : handleClearFieldColumns())"
            >
              全选
            </NCheckbox>
            <NCheckbox
              :checked="visibleFieldColumns.length === 0"
              @update:checked="checked => checked && handleClearFieldColumns()"
            >
              全不选
            </NCheckbox>
          </div>

          <NCheckboxGroup
            v-model:value="visibleFieldColumns"
            class="pin-column-group"
            @update:value="handleFieldSelectionChange"
          >
            <NSpace vertical :size="10">
              <NCheckbox v-for="item in fieldColumnOptions" :key="String(item.value)" :value="String(item.value)">
                {{ item.label }}
              </NCheckbox>
            </NSpace>
          </NCheckboxGroup>
        </div>
      </NSpace>
    </NModal>

    <NDrawer v-model:show="conditionVisible" :width="420" placement="right">
      <NDrawerContent title="条件面板" closable>
        <NSpace vertical :size="16">
          <NForm label-placement="top">
            <NFormItem label="字段">
              <NSelect
                v-model:value="selectedField"
                :options="filterableFields.map(field => ({ label: field, value: field }))"
              />
            </NFormItem>
            <NFormItem label="操作符">
              <NSelect
                v-model:value="selectedOperator"
                :options="[
                  { label: '包含', value: 'contains' },
                  { label: '等于', value: 'equals' },
                  { label: '前缀匹配', value: 'startsWith' }
                ]"
              />
            </NFormItem>
            <NFormItem label="取值">
              <NInput v-model:value="selectedValue" placeholder="输入筛选值" />
            </NFormItem>
          </NForm>

          <NAlert type="warning">
            当前已接到后端 JSON 协议；后续只需继续补齐新增、修改、删除、备注、钻取等动作接口。
          </NAlert>

          <NSpace justify="end">
            <NButton @click="conditionVisible = false">取消</NButton>
            <NButton type="primary" @click="handleApplyCondition">应用</NButton>
          </NSpace>
        </NSpace>
      </NDrawerContent>
    </NDrawer>

    <!-- 颜色标注弹窗 -->
    <NModal v-model:show="colorMarkVisible" preset="card" title="颜色标注设置" class="w-480px" :mask-closable="false">
      <NSpace vertical :size="16">
        <NForm label-placement="left" label-width="80">
          <NFormItem label="字段一">
            <NSelect v-model:value="colorMarkField1" :options="colorMarkEnabledColumns" />
          </NFormItem>
          <NFormItem label="比较符">
            <NSelect
              v-model:value="colorMarkOperator"
              :options="[
                { label: '大于', value: '大于' },
                { label: '小于', value: '小于' },
                { label: '等于', value: '等于' },
                { label: '大于等于', value: '大于等于' },
                { label: '小于等于', value: '小于等于' },
                { label: '不等于', value: '不等于' }
              ]"
            />
          </NFormItem>
          <NFormItem label="字段二">
            <NSelect v-model:value="colorMarkField2" :options="colorMarkEnabledColumns" />
          </NFormItem>
          <NFormItem label="颜色">
            <NSelect
              v-model:value="colorMarkColor"
              :options="[
                { label: '白底红字', value: '白底红字' },
                { label: '白底蓝字', value: '白底蓝字' },
                { label: '黄底红色', value: '黄底红色' }
              ]"
            />
          </NFormItem>
        </NForm>

        <NSpace justify="end">
          <NButton @click="colorMarkVisible = false">取消</NButton>
          <NButton @click="handleClearColorMark">清除</NButton>
          <NButton type="primary" @click="handleApplyColorMark">应用</NButton>
        </NSpace>
      </NSpace>
    </NModal>

    <!-- 批注弹窗 -->
    <WorkbenchComment
      v-model:add-visible="addCommentVisible"
      v-model:view-visible="viewCommentVisible"
      :loading="commentLoading"
      :fields="commentFields"
      :form-data="commentFormData"
      :list="commentList"
      :module-name="commentModuleName"
      :remark="commentRemark"
      :key-field-list="keyFieldList"
      :key-field-count="keyFieldCount"
      :is-dark-mode="isDarkMode"
      @update:remark="commentRemark = $event"
      @submit="handleSubmitComment"
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
      @confirm="confirmPopupSelection"
      @load-children="handleLoadCascaderChildren"
    />

    <!-- 新增弹窗 -->
    <WorkbenchAddForm
      v-model:visible="addVisible"
      :loading="addLoading"
      :error="addError"
      :success="addSuccess"
      :form-fields="addFormFields"
      :form-data="addFormData"
      @confirm="confirmAdd"
      @open-popup="handleOpenPopup"
    />

    <!-- 修改弹窗 -->
    <WorkbenchUpdateForm
      v-model:visible="updateVisible"
      :loading="updateLoading"
      :error="updateError"
      :success="updateSuccess"
      :form-fields="updateFormFields"
      :form-data="updateFormData"
      @confirm="confirmUpdate"
      @open-popup="handleOpenPopup"
    />

    <!-- 批量修改弹窗 -->
    <WorkbenchUpdateForm
      v-model:visible="batchUpdateVisible"
      :loading="batchUpdateLoading"
      :error="batchUpdateError"
      :success="batchUpdateSuccess"
      :form-fields="batchUpdateFormFields"
      :form-data="batchUpdateFormData"
      is-batch
      @confirm="confirmBatchUpdate"
      @open-popup="handleOpenPopup"
    />
  </div>
</template>

<style lang="scss" scoped>
@use './generic-query-workbench.scss';
</style>
