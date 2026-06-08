<script lang="ts">
// 模块级加载锁，防止同一 functionCode 被多个组件实例重复加载
const _loadingLocks = new Map<string, boolean>();
</script>

<script setup lang="ts">
import { computed, ref, shallowRef, watch } from 'vue';
import { useRoute } from 'vue-router';

import { AG_GRID_LOCALE_CN } from '@ag-grid-community/locale';
import {
  AllCommunityModule,
  ModuleRegistry,
  themeAlpine,
  type GridApi
} from 'ag-grid-community';
import { AgGridVue } from 'ag-grid-vue3';
import {
  NButton,
  NForm,
  NFormItem,
  NSelect,
  NModal,
  NInput,
  NSpin,
  NAlert,
  NEmpty
} from 'naive-ui';

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
import { useWorkbenchExport } from '@/hooks/business/use-workbench-export';
import { useWorkbenchDrillDialog } from '@/hooks/business/use-workbench-drill-dialog';
import { useWorkbenchPageDebug } from '@/hooks/business/use-workbench-page-debug';
import { useWorkbenchChartDebug } from '@/hooks/business/use-workbench-chart-debug';
import { useWorkbenchPanelResize } from '@/hooks/business/use-workbench-panel-resize';
import { useWorkbenchStateReset } from '@/hooks/business/use-workbench-state-reset';
import { useWorkbenchDrillOptions } from '@/hooks/business/use-workbench-drill-options';
import { useWorkbenchUpkeep } from '@/hooks/business/use-workbench-upkeep';
import { useWorkbenchDataFetchAll } from '@/hooks/business/use-workbench-data-fetch-all';
import { useWorkbenchGridReady } from '@/hooks/business/use-workbench-grid-ready';
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
 
type _QueryFilter = NonNullable<Api.Workbench.QueryPayload['filters']>[number];
type NotifyType = 'success' | 'error' | 'warning' | 'info';

function isGuidColumn(field: string, label: string) {
  return field.trim().toUpperCase() === 'GUID' || label.trim().toUpperCase() === 'GUID';
}

function msg(type: NotifyType, message: string, _data?: unknown) {
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

const { GRID_THEME, STORAGE_KEYS } = WORKBENCH_CONFIG;

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

// 图表区图表自适应
const workbenchContentRef = ref<HTMLDivElement | null>(null);
const chartMaximized = ref(false);

// 图表 resize 在分栏拖动 / 最大化时复用
function handlePanelResize() {
  if (chartVisible.value) {
    chartResize();
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
    const ownOpts = getDrillOptionsForChart(sid);
    if (ownOpts) {
      return ownOpts;
    }
    // 兜底：使用页面级钻取选项（来自 def_drill_config）
    return drillOptions.value ?? null;
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

// 钻取选项缓存（页面级 + 图表级）
const { options: drillOptions, getOptionsForChart: getDrillOptionsForChart } = useWorkbenchDrillOptions({
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
  notify: (type: NotifyType, message: string) => msg(type, message),
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
  notify: (type: NotifyType, message: string) => msg(type, message)
});

// 钻取选项对话框（数据行 → 跳转新功能页）
const { openDataDrill } = useWorkbenchDrillDialog({
  gridApi,
  getFunctionCode: () => String(props.meta.functionCode || '').trim(),
  loading,
  notify: (type: NotifyType, message: string, data?: unknown) => msg(type, message, data)
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
  notify: (type: NotifyType, message: string) => msg(type, message)
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
  notify: (type: NotifyType, message: string) => msg(type, message)
});

// 数据整理
const { handleUpkeep } = useWorkbenchUpkeep({
  getFunctionCode: () => String(props.meta.functionCode || '').trim(),
  loading,
  loadPage,
  notify: (type: NotifyType, message: string) => msg(type, message)
});

// 页面级 / 图表级 调试
const { handleDebug } = useWorkbenchPageDebug({
  getFunctionCode: () => String(props.meta.functionCode || '').trim(),
  notify: (type: NotifyType, message: string) => msg(type, message)
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
  notify: (type: NotifyType, message: string) => msg(type, message)
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

function handleOpenCondition() {
  conditionVisible.value = true;
}

async function handleApplyCondition() {
  conditionVisible.value = false;
  await queryPage();
  msg('success', '已应用筛选条件');
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
            <NButton @click="openDataDrill">数据钻取</NButton>
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
                  :ref="el => setChartRef(el as any, index)"
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
