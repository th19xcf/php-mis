<script lang="ts">
// 模块级加载锁，防止同一 functionCode 被多个组件实例重复加载
const _loadingLocks = new Map<string, boolean>();
</script>

<script setup lang="ts">
import { computed, ref, shallowRef, h, watch, onMounted, nextTick } from 'vue';
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
  fetchWorkbenchDebug,
  executeUpkeep
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
import { useWorkbenchChartDrill } from '@/hooks/business/use-workbench-chart-drill';
import { useWorkbenchTableEdit } from '@/hooks/business/use-workbench-table-edit';
import { useWorkbenchDataLoader } from '@/hooks/business/use-workbench-data-loader';
import { useThemeStore } from '@/store/modules/theme';
import { WORKBENCH_CONFIG } from '@/config/workbench';
import { logger } from '@/utils/logger';
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
      logger.info(`%c${prefix}%c ${message}`, 'color: #52c41a; font-weight: bold;', '');
      break;
    case 'info':
      console.info(prefix, message);
      break;
    default:
      logger.info(prefix, message);
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

// 图表最大化状态
const chartMaximized = ref(false);

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

onMounted(() => {
  _loadLayoutState();
});

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

// 钻取选项缓存：按功能编码缓存，钻取图形对话框使用 /workbench/drill 接口（页面级）
// 图形钻取对话框直接使用 chart.钻取选项（图表级，来自 def_chart_drill_config），无需缓存
const drillOptionsCache = ref<Api.Workbench.DrillOption[] | null>(null);
let drillOptionsLoadPromise: Promise<Api.Workbench.DrillOption[] | null> | null = null;

/**
 * 加载当前功能编码下的钻取选项（页面级，参考旧版 Frame.php 中 def_drill_config.钻取模块 过滤逻辑）
 * 同一 functionCode 多次调用复用同一份缓存与同一份进行中的 Promise
 */
async function loadDrillOptions(force = false): Promise<Api.Workbench.DrillOption[] | null> {
  if (!force && drillOptionsCache.value !== null) {
    return drillOptionsCache.value;
  }
  if (drillOptionsLoadPromise) {
    return drillOptionsLoadPromise;
  }

  const currentFunctionCode = String(route.query.functionCode || route.meta?.functionCode || '');
  if (!currentFunctionCode) {
    return null;
  }

  drillOptionsLoadPromise = (async () => {
    try {
      const { data, error } = await fetchWorkbenchDrill(currentFunctionCode, {});
      if (error || !data) {
        drillOptionsCache.value = null;
        return null;
      }
      const opts = (data.options as Api.Workbench.DrillOption[]) || [];
      drillOptionsCache.value = opts;
      return opts;
    } catch {
      drillOptionsCache.value = null;
      return null;
    } finally {
      drillOptionsLoadPromise = null;
    }
  })();

  return drillOptionsLoadPromise;
}

/**
 * 取得指定图表的钻取选项（图表级，来自 def_chart_drill_config）
 * 这是 Vgrid_aggrid.php 中 chart_drill 对话框使用的数据源
 *
 * 重要：dataItem.图形模块（逻辑模块，如 "公司_101"）与 chart.图形模块（SQL/SP 模块名，如 "sp_..."）
 * 是不同的字段；SP 在产数据点时会用自己的逻辑模块写 图形模块 和 SID。
 * 所以查找时优先按 图形编号 匹配，再用 图形模块 做兜底校验。
 */
function getChartOwnDrillOptions(sid: string): Api.Workbench.DrillOption[] | null {
  if (!sid) return null;
  const [chartModule, chartCode] = sid.split('^');
  if (!chartCode) return null;

  // 1) 优先：dataItem.图形模块 + 图形编号 同时匹配
  let chart = chartData.value.find((c: any) => c['图形模块'] === chartModule && c['图形编号'] === chartCode);

  // 2) 兜底：仅按 图形编号 匹配（解决 dataItem.图形模块 与 chart.图形模块 不一致的情况）
  if (!chart) {
    chart = chartData.value.find((c: any) => c['图形编号'] === chartCode);
  }

  if (!chart) {
    console.warn(
      `[CHART-DRILL] 未找到图表: SID=${sid} 解析 图形模块=${chartModule} 图形编号=${chartCode}, chartData.length=${chartData.value.length}`
    );
    console.warn(`[CHART-DRILL] chartData 图形模块列表: ${chartData.value.map((c: any) => c['图形模块']).join(', ')}`);
    return null;
  }

  const opts = (chart['钻取选项'] as Api.Workbench.DrillOption[]) || [];
  console.info(
    `[CHART-DRILL] 图表 图形模块=${chart['图形模块']} 图形编号=${chart['图形编号']} 钻取模块=${chart['钻取模块'] ?? '<空>'} 钻取选项数=${opts.length}`
  );
  if (opts.length === 0) {
    console.info(`[CHART-DRILL] 图表字段列表: ${Object.keys(chart).join(', ')}`);
  }
  return opts.length > 0 ? opts : null;
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
  notify: (type: NotifyType, message: string) => msg(type, message),
  onChartClick: (clickParams: any) => {
    // 图表点击事件转发给图形钻取 hook
    if (drillLevel.value >= 1) {
      // 钻取状态下点击，提示用户先返回
      msg('info', '当前为钻取结果，如需继续钻取请先点击"初始图形"');
      return;
    }
    handleChartClick(clickParams);
  }
});

const { drillLevel, isDrilled, handleChartClick, resetDrill } = useWorkbenchChartDrill({
  getFunctionCode: () => String(route.query.functionCode || route.meta?.functionCode || ''),
  getDrillOptionsForChart: (sid: string) => {
    // 图形钻取对话框使用图表自身的钻取选项（chart.钻取选项，源自 def_chart_drill_config）
    // 与旧版 Vgrid_aggrid.php::chart_drill 中的 chart_data[chartModule][chartCode]['钻取模块'] 等价
    const ownOpts = getChartOwnDrillOptions(sid);
    if (ownOpts) {
      return ownOpts;
    }
    // 兜底：若图表未携带钻取选项，则使用页面级钻取选项（来自 def_drill_config）
    if (drillOptionsCache.value === null && !drillOptionsLoadPromise) {
      void loadDrillOptions();
    }
    return drillOptionsCache.value;
  },
  notify: (type: NotifyType, message: string) => msg(type, message),
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

// 页面级钻取选项预热（兜底场景使用）
watch(
  chartData,
  async data => {
    if (!data || data.length === 0) return;
    // 仅在图表未配置图表级钻取选项时预热页面级选项
    const hasOwnOptions = data.some((c: any) => Array.isArray(c['钻取选项']) && c['钻取选项'].length > 0);
    if (!hasOwnOptions) {
      await loadDrillOptions();
    }
  },
  { immediate: true }
);

// 切换功能编码时清空旧缓存
watch(
  () => String(route.query.functionCode || route.meta?.functionCode || ''),
  (newCode, oldCode) => {
    if (newCode !== oldCode) {
      drillOptionsCache.value = null;
      drillOptionsLoadPromise = null;
    }
  }
);

/** 钻取后重新加载图表数据 */
// 注：图表重新渲染由 useWorkbenchChart 内部的 reloadChartsFromDrill 处理
// 实际调用在 useWorkbenchChartDrill 的 onDrillChartsUpdated 回调中

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

watch(leftPanelWidth, () => {
  if (chartVisible.value) {
    nextTick(() => chartResize());
  }
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
      logger.info(`[性能计时] ${label}: ${duration.toFixed(2)}ms`);
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
    logger.info('\x1b[31m[ERROR]\x1b[0m', message);
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

    logger.groupStart('🔍 调试信息 - ' + data.functionCode);
    logger.info('📊 查询配置:');
    logger.info('  - 查询表:', data.queryTable);
    logger.info('  - 查询模式:', data.mode);
    logger.info('  - WHERE 条件:', data.queryWhere || '(无)');
    logger.info('  - GROUP BY:', data.queryGroup || '(无)');
    logger.info('  - ORDER BY:', data.queryOrder || '(无)');

    logger.info('\n📝 SELECT 部分:');
    data.selectParts.forEach((part, index) => {
      logger.info(`  ${index + 1}. ${part}`);
    });

    logger.info('\n🔧 WHERE 部分:');
    if (data.whereParts.length > 0) {
      data.whereParts.forEach((part, index) => {
        logger.info(`  ${index + 1}. ${part}`);
      });
    } else {
      logger.info('  (无)');
    }

    logger.info('\n💻 SQL 语句:');
    logger.info('  计数 SQL:', data.countSql || '(不适用)');
    logger.info('  查询 SQL:', data.querySql);

    logger.info('\n👤 用户权限:');
    logger.info('  - 公司ID:', data.userAuth.companyId);
    logger.info('  - 工号:', data.userAuth.userWorkId);
    logger.info(
      '  - 角色编码:',
      Array.isArray(data.userAuth.roleCodes) ? data.userAuth.roleCodes.join(', ') : data.userAuth.roleCodes || '(无)'
    );
    logger.info('  - 属地赋权:', data.userAuth.locationAuth);
    logger.info(
      '  - 部门编码赋权:',
      Array.isArray(data.userAuth.deptCodeAuth)
        ? data.userAuth.deptCodeAuth.join(', ')
        : data.userAuth.deptCodeAuth || '(无)'
    );
    logger.info(
      '  - 部门全称赋权:',
      Array.isArray(data.userAuth.deptNameAuth)
        ? data.userAuth.deptNameAuth.join(', ')
        : data.userAuth.deptNameAuth || '(无)'
    );
    logger.info('  - 调试权限:', data.userAuth.debugAuth ? '有' : '无');

    logger.info('\n⚙️ 功能权限:');
    logger.info('  - 模块:', data.functionAuth.module);
    logger.info('  - 参数:', data.functionAuth.params || '(无)');
    logger.info('  - 部门权限条件:', data.functionAuth.deptAuthCond || '(无)');
    logger.info('  - 属地权限条件:', data.functionAuth.locationAuthCond || '(无)');

    logger.info('\n📋 字段映射:');
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
        logger.info('获取图表配置失败:', chartError);
      }
    }

    // 输出图形相关 SQL
    logger.info('\n📈 图形 SQL:');
    logger.info('chartModule:', data.chartModule);

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
      logger.info('chartSql (已替换占位符):', JSON.stringify(replacedChartSql, null, 2));
    } else {
      logger.info('chartSql:', JSON.stringify(data.chartSql, null, 2));
    }

    // 输出图形配置信息到控制台
    logger.info('\n========================================');
    logger.info('📈 图形配置信息');
    logger.info('========================================');
    logger.info('chartModule:', data.chartModule);
    logger.info('\n查询 SQL:');
    logger.info(data.chartQuerySql || '(无)');
    logger.info('\nchartSql 数组长度:', data.chartSql?.length || 0);

    // 输出完整的 chartSql 数据结构
    logger.info('\nchartSql 完整数据:');
    logger.info(JSON.stringify(data.chartSql, null, 2));

    interface ChartSqlItem {
      name?: string;
      图形名称?: string;
      图形编号?: string;
      sql?: string;
      error?: string;
    }
    if (data.chartSql && data.chartSql.length > 0) {
      logger.info('\n图形 SQL 明细:');
      (data.chartSql as ChartSqlItem[]).forEach((chart: ChartSqlItem, index: number) => {
        logger.info(`\n--- 图形 ${index + 1} ---`);
        logger.info('名称:', chart['图形名称'] || chart.name || chartNames[index] || '未命名');
        logger.info('SQL:', chart.sql ? replacePlaceholders(chart.sql) : '(无)');
        if (chart.error) {
          logger.info('错误:', chart.error);
        }
      });

      // 输出每个图形的钻取信息（来源：def_chart_drill_config）
      logger.info('\n图形钻取配置明细:');
      let chartFullList: any[] = [];
      try {
        const drillChartResponse = await request({
          url: `/workbench/chart/${fnCode}`
        });
        chartFullList = drillChartResponse.data?.charts || [];
      } catch (e) {
        logger.info('  重新获取图形数据失败:', e);
      }
      (data.chartSql as ChartSqlItem[]).forEach((chart: ChartSqlItem, index: number) => {
        const matched =
          chartFullList.find(
            (c: any) => c['图形名称'] === (chart['图形名称'] || chart.name) || c['图形编号'] === chart['图形编号']
          ) ||
          chartFullList[index] ||
          {};
        const drillModule = matched['钻取模块'] ?? '<空>';
        const drillOptions = Array.isArray(matched['钻取选项']) ? matched['钻取选项'] : [];

        logger.info(
          `\n  --- 图形 ${index + 1}: ${chart['图形名称'] || chart.name || chartNames[index] || '未命名'} ---`
        );
        logger.info(`    图形模块: ${matched['图形模块'] ?? '<空>'}`);
        logger.info(`    图形编号: ${matched['图形编号'] ?? '<空>'}`);
        logger.info(`    钻取模块 (def_chart_config): ${drillModule}`);
        logger.info(`    钻取选项数 (def_chart_drill_config): ${drillOptions.length}`);

        if (drillOptions.length === 0) {
          if (drillModule === '<空>') {
            logger.info('    ⚠️ def_chart_config.钻取模块 为空，未发起 def_chart_drill_config 查询');
          } else {
            logger.info(`    ⚠️ def_chart_drill_config 中无 钻取模块=${drillModule} 且 顺序>0 的记录`);
          }
        } else {
          console.table(
            drillOptions.map((o: any) => ({
              钻取选项: o.label,
              图形模块: o.chartModule,
              钻取模块: o.module,
              钻取字段: o.drillFields || '(无)',
              钻取条件: o.drillCondition || '(无)'
            }))
          );
        }
      });
    } else {
      logger.info('\n❌ 未查询到图形配置');
      logger.info('请检查 def_chart_config 表中是否存在图形模块:', data.chartModule);
      logger.info('或者检查表中是否有顺序>0 的有效记录');
    }
    logger.info('========================================\n');

    if (data.chartSql && Array.isArray(data.chartSql) && data.chartSql.length > 0) {
      (data.chartSql as ChartSqlItem[]).forEach((chart: ChartSqlItem, index: number) => {
        logger.info(`  图形 ${index + 1}: ${chart['图形名称'] || chart.name || chartNames[index] || '未命名'}`);
        logger.info(`    SQL: ${chart.sql ? replacePlaceholders(chart.sql) : '(无)'}`);
        if (chart.error) {
          logger.info(`    错误: ${chart.error}`);
        }
      });
    } else {
      logger.info('  (无图形配置或chartModule为空)');
    }

    logger.groupEnd();

    msg('success', '调试信息已输出到控制台');
  } catch (err) {
    msg('error', '获取调试信息失败');
    console.error('调试信息获取错误:', err);
  }
}

/**
 * 图形区调试：在控制台输出当前图形数据（初始图形 / 钻取图形）的相关信息
 *  - 输出每个图形的：图形模块 / 图形编号 / 名称 / SQL / 错误 / 钻取选项
 *  - 输出 ECharts DOM 信息（容器尺寸）
 *  - 调后端 /workbench/debug 拉取后端 session 快照，对比输出
 *  - 与全局 handleDebug 互补：handleDebug 走 debug 接口取后端配置快照；
 *    本方法走前端运行时状态 + 后端 session 状态，便于排查钻取链路问题
 */
async function handleChartDebug() {
  const fnCode = String(props.meta.functionCode || '').trim();
  const charts = chartData.value || [];
  const currentRoute = route;

  logger.groupStart(
    `📈 图形调试 - functionCode=${fnCode} | 钻取级别=${drillLevel.value} (${isDrilled.value ? '钻取' : '初始'})`
  );

  // 1. 路由与上下文
  logger.info('🧭 路由上下文:');
  logger.info('  - 路径:', currentRoute.path);
  logger.info('  - 名称:', String(currentRoute.name || ''));
  logger.info('  - query:', JSON.parse(JSON.stringify(currentRoute.query || {})));
  logger.info('  - meta.functionCode:', String(currentRoute.meta?.functionCode || ''));
  //logger.info('  - props.meta:', JSON.parse(JSON.stringify(props.meta || {})));

  // 2. 页面 meta 完整信息
  logger.info('\n📋 页面 pageMeta（来自后端 /workbench/page）:');
  logger.info('  - chartModule:', pageMeta.value?.chartModule || '<空>');
  logger.info('  - queryModule:', pageMeta.value?.queryModule || '<空>');
  logger.info('  - fieldModule:', pageMeta.value?.fieldModule || '<空>');
  logger.info('  - commentModule:', pageMeta.value?.commentModule || '<空>');
  logger.info('  - mode:', pageMeta.value?.mode || '<空>');
  logger.info('  - supportsStoredProcedure:', pageMeta.value?.supportsStoredProcedure);
  logger.info('  - toolbar:', JSON.parse(JSON.stringify(pageMeta.value?.toolbar || {})));
  //logger.info('  - 完整 pageMeta:', JSON.parse(JSON.stringify(pageMeta.value || {})));

  // 3. 钻取状态 + 工具栏权限
  logger.info('\n🔍 钻取状态:');
  logger.info('  - drillLevel:', drillLevel.value);
  logger.info('  - isDrilled:', isDrilled.value);
  logger.info('  - chartVisible:', chartVisible.value);
  logger.info('  - chartLoading:', chartLoading.value);
  logger.info('  - chartMaximized:', chartMaximized.value);

  if (charts.length === 0) {
    logger.info('\n⚠️ 当前未加载任何图形（请先点击"图形"按钮打开）');
    logger.groupEnd();
    msg('warning', '当前未加载图形数据');
    return;
  }

  // 4. chartData 详细
  logger.info(`\n📊 chartData 明细（${charts.length} 项）:`);
  charts.forEach((chart: any, index: number) => {
    const chartModule = chart['图形模块'] ?? '<空>';
    const chartCode = chart['图形编号'] ?? '<空>';
    const chartName = chart['图形名称'] ?? '<空>';
    const fetchMode = chart['取数方式'] ?? '<空>';
    const sql = chart['SQL'] ?? '';
    const error = chart['错误'];
    const dataRows = Array.isArray(chart['数据']) ? chart['数据'] : [];
    const drillOptions = Array.isArray(chart['钻取选项']) ? chart['钻取选项'] : [];

    logger.groupStart(`📊 图形 ${index + 1}: ${chartName} [${chartModule}^${chartCode}]`);
    logger.info('基础信息:');
    logger.info('  - 图形模块:', chartModule);
    logger.info('  - 图形编号:', chartCode);
    logger.info('  - 图形名称:', chartName);
    logger.info('  - 取数方式:', fetchMode);
    logger.info('  - 钻取模块:', chart['钻取模块'] ?? '<空>');
    logger.info('  - 字段模块:', chart['字段模块'] ?? '<空>');
    logger.info('  - 页面布局:', chart['页面布局'] ?? '<空>');
    logger.info('  - 图形类型:', chart['图形类型'] ?? '<空>');
    logger.info('  - SID 模板:', chart['SID'] ?? '<空>');
    logger.info('  - 数据条数:', dataRows.length);

    if (sql) {
      logger.info('SQL:');
      logger.info(sql);
    } else {
      logger.info('SQL: (空)');
    }
    if (error) {
      logger.info('❌ 错误:', error);
    }
    if (dataRows.length > 0) {
      logger.info('数据条数:', dataRows.length);
    } else {
      logger.info('数据: (空)');
    }

    if (drillOptions.length === 0) {
      logger.info('⚠️ 钻取选项: 无');
    } else {
      logger.info(`钻取选项数: ${drillOptions.length}`);
      logger.info('钻取选项完整列表:');
      console.table(
        drillOptions.map((o: any) => ({
          钻取选项: o.label ?? o['钻取选项'] ?? '<空>',
          图形模块: o.chartModule ?? o['图形模块'] ?? '<空>',
          钻取模块: o.module ?? o['钻取模块'] ?? o.functionCode ?? '<空>',
          钻取字段: o.drillFields ?? o['钻取字段'] ?? '(无)',
          钻取条件: o.drillCondition ?? o['钻取条件'] ?? '(无)',
          value: o.value ?? '<空>'
        }))
      );
    }
    logger.groupEnd();
  });

  // 5. 后端调试快照（拉取 /workbench/debug）
  logger.info('\n🛰️ 拉取后端调试快照 /workbench/debug ...');
  try {
    const payload: Api.Workbench.QueryPayload = { all: true, filters: [] };
    const { data, error } = await fetchWorkbenchDebug(fnCode, payload);
    if (error || !data) {
      logger.info('  ❌ 拉取失败:', error);
    } else {
      logger.info('  ✅ 后端 pageMeta 快照:');
      logger.info('    - functionCode:', data.functionCode);
      logger.info('    - queryTable:', data.queryTable);
      logger.info('    - chartModule:', data.chartModule);
      logger.info('    - chartQuerySql:', data.chartQuerySql);
      logger.info('    - chartSql 长度:', data.chartSql?.length || 0);
      logger.info('    - queryMode:', data.mode);
      logger.info('    - 完整后端响应:', JSON.parse(JSON.stringify(data)));

      // 6. 钻取 session 状态（关键：queryTable / chartSql 反映后端 SP 占位符是否已替换）
      if (Array.isArray(data.chartSql) && data.chartSql.length > 0) {
        logger.info('\n🗂️ 后端 chartSql 明细:');
        (data.chartSql as any[]).forEach((cs: any, i: number) => {
          logger.groupStart(`  chartSql[${i}]`);
          logger.info('    名称:', cs['图形名称'] || cs.name);
          logger.info('    编号:', cs['图形编号']);
          logger.info('    SQL:', cs.sql);
          if (cs.error) logger.info('    错误:', cs.error);
          logger.groupEnd();
        });

        // 检查 SQL 中是否还包含未替换的占位符
        const placeholderPattern = /\$\{?[\u4e00-\u9fa5A-Za-z_]+\}?/g;
        const hasUnreplaced = data.chartSql.some((cs: any) => cs.sql && placeholderPattern.test(cs.sql));
        if (hasUnreplaced) {
          logger.info('  ⚠️ 检测到 SQL 中可能存在未替换的占位符（$查询表名 / $[部门全称赋权] 等）');
        }
      }
    }
  } catch (e) {
    logger.info('  ❌ 异常:', e);
  }

  // 7. 完整 chartData 原始 JSON（独立子组，单独可折叠）
  logger.groupStart(`📦 完整 chartData（JSON） — ${charts.length} 项 [点击展开]`);
  try {
    // 关键：chartData 来自响应式 ref，元素可能是 Vue Proxy / 包含函数 / Symbol / 循环引用，
    // 直接 JSON.stringify 会抛 "Converting circular structure to JSON"，导致整段静默。
    // 这里做一次深拷贝解包后再 stringify。
    const safeCharts = JSON.parse(JSON.stringify(charts));
    logger.info(JSON.stringify(safeCharts, null, 2));
  } catch (e) {
    logger.info('JSON.stringify 失败（可能是循环引用 / 函数 / Symbol），回退输出结构:');
    logger.info('  - 错误:', e);
    logger.info('  - charts.length:', charts.length);
    charts.forEach((c: any, i: number) => {
      logger.info(`  [${i}] keys:`, Object.keys(c || {}));
      const dataRows = Array.isArray(c?.['数据']) ? c['数据'] : [];
      logger.info(
        `       数据条数: ${dataRows.length}, 数据 keys (首行):`,
        dataRows[0] ? Object.keys(dataRows[0]) : []
      );
    });
  }
  logger.groupEnd();

  logger.groupEnd();
  msg('success', '图形调试信息已输出到控制台');
}

async function handleUpkeep() {
  const fnCode = String(props.meta.functionCode || '').trim();
  if (!fnCode) {
    msg('error', '功能编码不能为空');
    return;
  }

  try {
    loading.value = true;
    const { data, error } = await executeUpkeep(fnCode);
    loading.value = false;

    if (error) {
      msg('error', '执行数据整理失败', error);
      return;
    }

    if (data?.success) {
      msg('success', data.message || '数据整理执行成功');
      // 执行成功后刷新数据
      await loadPage();
    } else {
      msg('error', data?.message || '执行数据整理失败');
    }
  } catch (err) {
    loading.value = false;
    msg('error', '执行数据整理失败');
    console.error('数据整理执行错误:', err);
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
            <NButton v-if="pageMeta?.toolbar.upkeep" @click="handleUpkeep">数据整理</NButton>
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
      :class="{ 'chart-mode': chartVisible && !chartMaximized, resizing: isResizing }"
    >
      <!-- 左侧表格区域 -->
      <div
        v-show="!chartMaximized"
        class="table-area"
        :style="chartVisible && !chartMaximized ? { flex: `0 0 ${leftPanelWidth}%` } : {}"
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

      <!-- 可拖动分隔条 -->
      <div
        v-if="chartVisible && !chartMaximized"
        class="resize-splitter"
        :class="{ 'is-resizing': isResizing }"
        title="拖动调整宽度"
        @mousedown="startResize"
      >
        <div class="resize-line" />
      </div>

      <!-- 右侧图形区域 -->
      <div
        v-show="chartVisible"
        class="chart-area"
        :style="{ flex: chartMaximized ? '1' : `0 0 ${100 - leftPanelWidth}%` }"
      >
        <div class="chart-panel rounded-12px shadow-sm">
          <div class="chart-header">
            <span class="chart-title">
              <span class="title-text">图形展示</span>
              <span class="title-divider">|</span>
              <span class="drill-badge">{{ isDrilled ? `钻取第 ${drillLevel} 级` : '初始图形' }}</span>
            </span>
            <div class="flex flex-row gap-8px">
              <NButton v-if="isDrilled" size="small" type="primary" @click="handleResetDrill">初始图形</NButton>
              <NButton v-else size="small" type="default" @click="handleOpenChart(pageMeta)">刷新</NButton>
              <NButton size="small" type="default" @click="chartMaximized = !chartMaximized">
                {{ chartMaximized ? '恢复' : '扩大' }}
              </NButton>
              <NButton v-if="pageMeta?.toolbar.debugSql" size="small" type="warning" @click="handleChartDebug">
                调试
              </NButton>
              <NButton
                size="small"
                @click="
                  () => {
                    chartMaximized = false;
                    chartVisible = false;
                  }
                "
              >
                关闭
              </NButton>
            </div>
          </div>
          <div class="chart-container">
            <NSpin :show="chartLoading">
              <template v-if="chartOptions.length > 0">
                <div
                  v-for="(option, index) in chartOptions"
                  :key="index"
                  :ref="el => setChartRef(el as HTMLDivElement, index)"
                  class="chart-wrapper"
                  :class="[option.chartLayout || 'box_1-1-1']"
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
      @update:form-data="addFormData = $event"
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
      @update:form-data="updateFormData = $event"
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
      @update:form-data="batchUpdateFormData = $event"
      @confirm="confirmBatchUpdate"
      @open-popup="handleOpenPopup"
    />
  </div>
</template>

<style lang="scss" scoped>
@use './generic-query-workbench.scss';
</style>
