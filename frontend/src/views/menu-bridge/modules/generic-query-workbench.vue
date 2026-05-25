<script lang="ts">
// 模块级加载锁，防止同一 functionCode 被多个组件实例重复加载
const loadingLocks = new Map<string, boolean>();
</script>

<script setup lang="ts">
import { computed, ref, shallowRef, h, onMounted, onActivated, onDeactivated, watch, nextTick } from 'vue';
import { useRouter, useRoute } from 'vue-router';

import { AG_GRID_LOCALE_CN } from '@ag-grid-community/locale';
import {
  AllCommunityModule,
  ModuleRegistry,
  themeAlpine,
  type ColDef,
  type GridApi,
  type GridReadyEvent
} from 'ag-grid-community';
import { AgGridVue } from 'ag-grid-vue3';
import { NButton, NRadio, NRadioGroup, NForm, NFormItem, NSelect, NModal, NInput, NSpin, NAlert, NEmpty } from 'naive-ui';
import * as XLSX from 'xlsx';
import * as echarts from 'echarts/core';
import { LineChart, BarChart, PieChart } from 'echarts/charts';
import {
  TitleComponent,
  TooltipComponent,
  LegendComponent,
  GridComponent,
  DatasetComponent
} from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';

// 注册 ECharts 组件
echarts.use([
  LineChart,
  BarChart,
  PieChart,
  TitleComponent,
  TooltipComponent,
  LegendComponent,
  GridComponent,
  DatasetComponent,
  CanvasRenderer
]);

import {
  fetchWorkbenchPage,
  fetchWorkbenchPageData,
  fetchWorkbenchDrill,
  submitTableEdit,
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
import { useThemeStore } from '@/store/modules/theme';
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

function isGuidColumn(field: string, label: string) {
  return field.trim().toUpperCase() === 'GUID' || label.trim().toUpperCase() === 'GUID';
}

function msg(type: 'success' | 'error' | 'warning' | 'info', message: string, _data?: unknown) {
  window.$message?.[type](message);
}

const props = defineProps<{
  meta: MenuBridgeMeta;
  nativeOnly?: boolean;
  dynamicLike?: boolean;
}>();

const lightGridTheme = themeAlpine.withParams({
  browserColorScheme: 'light',
  rowBorder: { style: 'dotted', width: 1, color: '#c1ccc7' },
  columnBorder: { style: 'dotted', width: 1, color: '#c1ccc7' },
  rangeSelectionBorderColor: '#2196F3',
  rangeSelectionBorderStyle: 'solid'
});

const darkGridTheme = themeAlpine.withParams({
  browserColorScheme: 'dark',
  rowBorder: { style: 'dotted', width: 1, color: '#4b5965' },
  columnBorder: { style: 'dotted', width: 1, color: '#4b5965' },
  rangeSelectionBorderColor: '#64B5F6',
  rangeSelectionBorderStyle: 'solid'
});

const themeStore = useThemeStore();
const isDarkMode = computed(() => themeStore.darkMode);
const activeGridTheme = computed(() => (isDarkMode.value ? darkGridTheme : lightGridTheme));

const loading = ref(false);
const pageMeta = ref<Api.Workbench.PageMeta | null>(null);
const serverRows = shallowRef<Api.Workbench.QueryRecord[]>([]);
const total = ref(0);
const page = ref(1);
const PAGE_SIZE_OPTIONS = [500, 1000, 2000] as const;
const paginationPageSizeSelector = [...PAGE_SIZE_OPTIONS];
const pageSize = ref<number>(PAGE_SIZE_OPTIONS[0]);

// 分片加载相关状态
const CHUNK_SIZE = 1000; // 每片 1000 条
const isChunkLoading = ref(false); // 是否正在分片加载
const loadedCount = ref(0); // 已加载数量
const totalCount = ref(0); // 总数量
const isInitialChunkLoaded = ref(false); // 首片是否已加载

const quickKeyword = ref('');
const conditionVisible = ref(false);
const selectedField = ref('');
const selectedOperator = ref<ConditionOperator>('contains');
const selectedValue = ref('');
const useLegacyTabHint = ref(false);
const gridApi = ref<GridApi<Api.Workbench.QueryRecord> | null>(null);

// 防止筛选恢复期间 filterChanged 覆盖筛选
const isRestoringFilter = ref(false);
// 防止排序恢复期间 sortChanged 覆盖排序
const isRestoringColumnState = ref(false);
// 防止行选择恢复期间触发保存
const isRestoringSelection = ref(false);
// 防止分页恢复期间触发保存
const isRestoringPage = ref(false);
// 防止初始加载期间的 columnResized 事件保存状态
const isInitialLoading = ref(false);

// 缓存数据，避免重复请求
const isDataLoaded = ref(false);

// 表级修改相关状态
const tableModifiedRows = ref<Set<string | number>>(new Set());
// 存储修改的字段和值（只包含修改的字段）
const modifiedRowsData = ref<Map<string | number, Record<string, any>>>(new Map());
// 存储原始行数据（用于获取主键）
const originalRowsData = ref<Map<string | number, Api.Workbench.QueryRecord>>(new Map());
const hasTableModifications = computed(() => tableModifiedRows.value.size > 0);
// 防止递归触发 cellValueChanged
const isRestoringCellValue = ref(false);

// 记录当前已加载的 functionCode 和 params，用于检测是否真的需要重新加载
const loadedFunctionCode = ref<string>('');
const loadedParams = ref<string>('');

// 加载数据的逻辑
function checkAndLoadData() {
  const currentFunctionCode = String(props.meta.functionCode || '');
  const currentParams = String(props.meta.params || '');
  const lockKey = `${currentFunctionCode}_${currentParams}`;
  const cached = workbenchStore.getCache(currentFunctionCode, currentParams);
  const isCacheComplete = cached && cached.isDataLoaded && cached.serverRows.length === cached.total;

  console.log(
    `[📋 checkAndLoadData] functionCode=${currentFunctionCode}, isDataLoaded=${isDataLoaded.value}, loadedFunctionCode=${loadedFunctionCode.value}`
  );

  // 如果 functionCode 为空，不加载数据
  if (!currentFunctionCode) {
    console.log('[📋 checkAndLoadData] functionCode 为空，跳过加载');
    return;
  }

  // 模块级加载锁：如果同一 functionCode+params 正在加载，跳过
  if (loadingLocks.get(lockKey) && !isCacheComplete) {
    console.log(`[📋 checkAndLoadData] ${lockKey} 正在加载中，跳过重复请求`);
    return;
  }

  // 只有当数据未加载，或者 functionCode/params 发生变化时才加载
  const shouldLoad =
    !isDataLoaded.value || currentFunctionCode !== loadedFunctionCode.value || currentParams !== loadedParams.value;
  console.log(
    `[📋 checkAndLoadData] 是否加载: ${shouldLoad}, isDataLoaded=${isDataLoaded.value}, loadedFC=${loadedFunctionCode.value}, 时间: ${performance.now().toFixed(1)}ms`
  );

  if (shouldLoad) {
    loadingLocks.set(lockKey, true);
    loadedFunctionCode.value = currentFunctionCode;
    loadedParams.value = currentParams;
    loadPage();
    isDataLoaded.value = true;
    // 加载完成后释放锁（loadPage 是同步的缓存恢复，异步的接口请求）
    setTimeout(() => {
      loadingLocks.delete(lockKey);
    }, 500);
  } else {
    console.log('[📋 checkAndLoadData] 数据已加载且未变化，跳过');
  }
}

// 生命周期钩子
onMounted(() => {
  const functionCode = String(props.meta.functionCode || '');
  const params = String(props.meta.params || '');
  console.log(
    `[🔄 onMounted] 组件挂载 functionCode=${functionCode}, params=${params}, 时间: ${performance.now().toFixed(1)}ms`
  );
  // 加载保存的布局状态
  loadLayoutState();
  checkAndLoadData();
});

onActivated(() => {
  const functionCode = String(props.meta.functionCode || '');
  console.log(
    `[🔄 onActivated] 组件激活 functionCode=${functionCode}, isDataLoaded=${isDataLoaded.value}, 时间: ${performance.now().toFixed(1)}ms`
  );
});

onDeactivated(() => {
  const functionCode = String(props.meta.functionCode || '');
  console.log(`[🔄 onDeactivated] 组件停用 functionCode=${functionCode}, 时间: ${performance.now().toFixed(1)}ms`);
});

// 监听 props.meta 的变化，处理钻取（同一组件内 params 变化）
watch(
  () => props.meta,
  (newMeta, oldMeta) => {
    const newFunctionCode = String(newMeta?.functionCode || '');
    const newParams = String(newMeta?.params || '');
    const oldFunctionCode = String(oldMeta?.functionCode || '');
    const oldParams = String(oldMeta?.params || '');

    // 只有当 functionCode 或 params 发生变化时才重新加载
    // 注意：由于 :key 的存在，切换标签页时组件会重新创建，不会触发这里的 watch
    // 这里的 watch 主要用于处理钻取（同一 functionCode 下 params 变化）
    if (newFunctionCode === oldFunctionCode && newParams !== oldParams) {
      console.log(`[🔍 钻取] functionCode=${newFunctionCode}, params 变化`);
      const drillTimer = createTimer('🔍 钻取总耗时');
      loadedFunctionCode.value = newFunctionCode;
      loadedParams.value = newParams;
      isDataLoaded.value = false;
      loadPage();
      isDataLoaded.value = true;
      drillTimer.end();
    }
  },
  { deep: true }
);

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
  notify: (type, message) => msg(type, message)
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
  notify: (type, message, data) => msg(type, message, data)
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

  const dataColumns = columnState.filter((col: any) => {
    const colId = String(col?.colId || '');
    return colId && colId !== 'ag-Grid-SelectionColumn' && !colId.startsWith('ag-Grid-');
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

/**
 * 解析样式字符串为 CSS 对象
 * 格式: "color:red,background-color:#f7acbc,font-weight:bold"
 */
function parseStyleString(styleStr: string): Record<string, string> {
  const defaultStyle = { color: 'red', fontWeight: 'bold', backgroundColor: '#f7acbc' };
  if (!styleStr) return defaultStyle;

  const styleObj: Record<string, string> = {};
  const items = styleStr.split(',');
  for (const item of items) {
    const [key, value] = item.split(':');
    if (key && value) {
      // 将 CSS 属性名转换为 camelCase
      const camelKey = key.trim().replace(/-([a-z])/g, g => g[1].toUpperCase());
      styleObj[camelKey] = value.trim();
    }
  }
  return Object.keys(styleObj).length > 0 ? styleObj : defaultStyle;
}

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

// 图形展示相关状态
const chartVisible = ref(false);
const chartLoading = ref(false);
const chartData = ref<any[]>([]);
const chartOption = ref<any>(null);

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

    // 限制最小和最大宽度
    newLeftWidth = Math.max(30, Math.min(70, newLeftWidth));
    leftPanelWidth.value = newLeftWidth;

    // 触发图表重绘
    if (chartInstance) {
      chartInstance.resize();
    }
  }

  function handleMouseUp() {
    isResizing.value = false;
    document.body.style.cursor = '';
    document.body.style.userSelect = '';
    document.removeEventListener('mousemove', handleMouseMove);
    document.removeEventListener('mouseup', handleMouseUp);

    // 保存布局状态到本地存储
    localStorage.setItem('workbench-left-panel-width', String(leftPanelWidth.value));
  }

  document.addEventListener('mousemove', handleMouseMove);
  document.addEventListener('mouseup', handleMouseUp);
}

// 加载保存的布局状态
function loadLayoutState() {
  const savedWidth = localStorage.getItem('workbench-left-panel-width');
  if (savedWidth) {
    leftPanelWidth.value = parseFloat(savedWidth);
  }
}

// 打开图形展示弹窗
async function handleOpenChart() {
  if (!pageMeta.value?.chartModule) {
    const warningMsg = '当前功能未配置图形模块';
    msg('warning', warningMsg);
    console.warn(`[图形功能] ${warningMsg}`);
    return;
  }

  chartVisible.value = true;
  chartLoading.value = true;

  try {
    // 调用后端 API 获取图形数据
    const functionCode = String(route.query.functionCode || route.meta?.functionCode || '');
    const { data, error } = await request({
      url: `/workbench/chart/${functionCode}`
    });

    if (error) {
      const errorMsg = '获取图形数据失败';
      msg('error', errorMsg);
      console.error(`[图形功能] ${errorMsg}:`, error);
      chartLoading.value = false;
      return;
    }

    if (!data?.charts || data.charts.length === 0) {
      const warningMsg = '暂无图形数据';
      msg('warning', warningMsg);
      console.warn(`[图形功能] ${warningMsg}`);
      chartLoading.value = false;
      return;
    }

    // 处理后端返回的图形数据
    chartData.value = data.charts;

    // 检查是否有错误信息
    const firstChart = data.charts[0];
    if (firstChart?.['错误']) {
      const errorMsg = `图形数据查询失败: ${firstChart['错误']}`;
      msg('error', errorMsg);
      console.error(`[图形功能] ${errorMsg}`);
      console.error(`[图形功能] SQL: ${firstChart['SQL']}`);
      chartLoading.value = false;
      return;
    }

    chartOption.value = generateChartOptionFromBackend(data.charts);
  } catch (error) {
    const errorMsg = '图形数据加载失败';
    msg('error', errorMsg);
    console.error(`[图形功能] ${errorMsg}:`, error);
  } finally {
    chartLoading.value = false;
  }
}

function generateChartOptionFromBackend(charts: any[]): any {
  if (!charts || charts.length === 0) {
    return null;
  }

  const chart = charts[0];
  const chartData = chart['数据'] || [];
  const chartType = chart['图形类型'] || 'line';
  const chartName = chart['图形名称'] || '数据图形';
  const fieldsConfig = chart['字段'] || {};

  if (chartData.length === 0) {
    return null;
  }

  if (chartType === 'pie') {
    const dataKeys = Object.keys(chartData[0]);
    const categoryKey = dataKeys[0];
    const valueKeys = dataKeys.slice(1).filter(key => {
      const val = chartData[0][key];
      return typeof val === 'number' || (typeof val === 'string' && !isNaN(Number(val)) && val !== '');
    });

    const pieData = chartData.map(item => ({
      name: item[categoryKey],
      value: Number(item[valueKeys[0]]) || 0
    }));

    return {
      title: {
        text: chartName,
        left: 'center'
      },
      tooltip: {
        trigger: 'item',
        formatter: '{a} <br/>{b}: {c} ({d}%)'
      },
      legend: {
        orient: 'vertical',
        left: 'left'
      },
      series: [
        {
          name: chartName,
          type: 'pie',
          radius: '50%',
          data: pieData,
          emphasis: {
            itemStyle: {
              shadowBlur: 10,
              shadowOffsetX: 0,
              shadowColor: 'rgba(0, 0, 0, 0.5)'
            }
          }
        }
      ]
    };
  } else {
    const dem: any[] = [];
    const yAxis: any[] = [];
    let yLeft = false;
    let yRight = false;

    const parseValue = (val: any) => {
      if (typeof val === 'number') {
        return val;
      }
      if (typeof val === 'string') {
        const cleanVal = val.replace(/[%，,]/g, '');
        const num = Number(cleanVal);
        return isNaN(num) ? 0 : num;
      }
      return 0;
    };

    const dataKeys = Object.keys(chartData[0]);
    const valueKeys = dataKeys.slice(1).filter(key => {
      const val = chartData[0][key];
      if (typeof val === 'number') {
        return true;
      }
      if (typeof val === 'string') {
        const cleanVal = val.replace(/[%，,]/g, '');
        return !isNaN(Number(cleanVal)) && cleanVal !== '';
      }
      return false;
    });

    for (const key of valueKeys) {
      const fieldConfig = fieldsConfig[key];
      const axisPosition = fieldConfig?.['坐标轴'] || 'Y轴（左侧）';
      const fieldChartType = fieldConfig?.['图形类型'] || chartType;

      if (axisPosition === 'Y轴（左侧）' && !yLeft) {
        yAxis.push({ type: 'value', position: 'left' });
        yLeft = true;
      } else if (axisPosition === 'Y轴（右侧）' && !yRight) {
        yAxis.push({ 
          type: 'value', 
          position: 'right',
          axisLabel: { formatter: '{value}%' }
        });
        yRight = true;
      }

      const seriesItem: any = {
        name: key,
        type: fieldChartType === '柱状图' ? 'bar' : 'line',
        data: chartData.map(item => parseValue(item[key]))
      };

      if (fieldChartType !== '柱状图') {
        seriesItem.smooth = true;
      }

      if (yAxis.length > 1) {
        seriesItem.yAxisIndex = axisPosition === 'Y轴（右侧）' ? 1 : 0;
      }

      dem.push(seriesItem);
    }

    return {
      title: {
        show: true,
        text: chartName,
        triggerEvent: true
      },
      legend: {
        bottom: 2,
        data: valueKeys
      },
      tooltip: {
        trigger: 'axis',
        axisPointer: { type: 'cross' }
      },
      toolbox: {
        feature: {
          dataview: { show: true },
          magicType: { show: true, type: ['line', 'bar', 'stack'] },
          restore: { show: true },
          saveAsImage: { show: true }
        }
      },
      dataset: {
        source: chartData
      },
      xAxis: { type: 'category' },
      yAxis: yAxis.length > 1 ? yAxis : yAxis[0],
      series: dem,
      grid: {
        left: '3%',
        right: yRight ? '12%' : '4%',
        bottom: '10%',
        top: 80
      }
    };
  }
}

// 图表相关引用和监听
const chartRef = ref<HTMLDivElement | null>(null);
let chartInstance: echarts.ECharts | null = null;

// 监听图表显示状态，初始化图表
watch(chartVisible, async (visible) => {
  if (visible && chartOption.value) {
    await nextTick();
    // 延迟初始化，确保 DOM 已经渲染且有正确的尺寸
    setTimeout(() => {
      if (chartRef.value) {
        // 检查 DOM 尺寸
        const width = chartRef.value.clientWidth;
        const height = chartRef.value.clientHeight;
        console.log('[图形功能] DOM 尺寸:', { width, height });

        if (width === 0 || height === 0) {
          console.warn('[图形功能] DOM 宽高为 0，延迟重试...');
          // 如果尺寸为 0，再延迟一段时间后重试
          setTimeout(() => {
            if (chartRef.value && chartRef.value.clientWidth > 0 && chartRef.value.clientHeight > 0) {
              initChart();
            }
          }, 300);
          return;
        }

        initChart();
      }
    }, 200);
  } else if (!visible && chartInstance) {
    // 关闭图形时清理
    chartInstance.dispose();
    chartInstance = null;
  }
});

// 初始化图表的函数
function initChart() {
  if (!chartRef.value || !chartOption.value) return;

  // 销毁旧实例
  if (chartInstance) {
    chartInstance.dispose();
  }

  // 创建新实例
  chartInstance = echarts.init(chartRef.value);
  chartInstance.setOption(chartOption.value);
  console.log('[图形功能] 图表初始化成功');

  // 监听窗口大小变化
  const resizeHandler = () => {
    chartInstance?.resize();
  };
  window.addEventListener('resize', resizeHandler);

  // 图形关闭时清理
  const unwatch = watch(chartVisible, (v) => {
    if (!v) {
      window.removeEventListener('resize', resizeHandler);
      unwatch();
    }
  });
}

// 监听 chartOption 变化，当图形区域已显示但数据刚加载完成时初始化图表
watch(chartOption, async (option) => {
  if (option && chartVisible.value && !chartInstance) {
    await nextTick();
    setTimeout(() => {
      if (chartRef.value) {
        const width = chartRef.value.clientWidth;
        const height = chartRef.value.clientHeight;
        console.log('[图形功能] chartOption 变化，DOM 尺寸:', { width, height });

        if (width > 0 && height > 0) {
          initChart();
        } else {
          setTimeout(() => {
            if (chartRef.value && chartRef.value.clientWidth > 0 && chartRef.value.clientHeight > 0) {
              initChart();
            }
          }, 300);
        }
      }
    }, 100);
  }
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
  notify: (type, message) => msg(type, message)
});

const gridColumns = computed<ColDef<Api.Workbench.QueryRecord>[]>(() => {
  return (pageMeta.value?.columns || []).map(column => {
    const headerClasses: string[] = [];

    if (column.editable) {
      // Keep legacy visual cue for editable columns.
      headerClasses.push('editable-column');
    }

    const definition: ColDef<Api.Workbench.QueryRecord> = {
      field: column.field,
      headerName: column.title,
      // 不使用后端返回的宽度，而是由前端自动调整
      hide: column.hidden,
      sortable: column.sortable,
      filter: true,
      resizable: true,
      headerClass: headerClasses
    };

    if (column.type === '数值') {
      definition.type = 'numericColumn';
    }

    // 添加提示、异常和颜色标注样式处理
    definition.cellStyle = (params: any) => {
      const field = column.field;
      const data = params.data || {};
      const rowIndex = params.rowIndex;

      // 优先检查是否是修改过的单元格
      const rowId = getRowId(data, rowIndex);
      const modifiedFields = modifiedRowsData.value.get(rowId);
      if (modifiedFields && field in modifiedFields) {
        // 修改过的单元格显示特殊背景色（根据主题模式）
        if (isDarkMode.value) {
          // Dark模式：使用深色背景，确保文字清晰可见
          return { backgroundColor: '#856404', color: '#ffffff', border: 'none', outline: 'none', boxShadow: 'none' };
        }
        // Light模式：使用淡黄色背景
        return { backgroundColor: '#fff3cd', color: '#000000', border: 'none', outline: 'none', boxShadow: 'none' };
      }

      // 优先检查颜色标注条件（用户主动设置的优先级最高）
      if (column.colorMarkEnabled && colorMarkConfig.value) {
        const { field1, operator, field2, style } = colorMarkConfig.value;
        // 处理当前列是字段一或字段二的情况
        if (field === field1 || field === field2) {
          const val1 = Number(data[field1]);
          const val2 = Number(data[field2]);
          let match = false;
          switch (operator) {
            case '大于':
              match = val1 > val2;
              break;
            case '小于':
              match = val1 < val2;
              break;
            case '等于':
              match = val1 === val2;
              break;
            case '大于等于':
              match = val1 >= val2;
              break;
            case '小于等于':
              match = val1 <= val2;
              break;
            case '不等于':
              match = val1 !== val2;
              break;
          }
          if (match) return style;
        }
      }

      // 然后检查异常条件
      if (column.errorCondition) {
        const errorKey = `异常^${field}`;
        if (data[errorKey] === '1' || data[errorKey] === 1) {
          return parseStyleString(column.errorStyle || '');
        }
      }

      // 最后检查提示条件
      if (column.hintCondition) {
        const hintKey = `提示^${field}`;
        if (data[hintKey] === '1' || data[hintKey] === 1) {
          return parseStyleString(column.hintStyle || '');
        }
      }

      return null;
    };

    return definition;
  });
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
      item => item.value === 'ag-Grid-SelectionColumn' || (item.value !== '' && item.value !== 'ag-Grid-ControlsColumn' && !isGuidColumn(item.value, item.label))
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

// 使用 shallowRef 存储处理后的数据，避免 Vue 深层响应式开销
const processedRows = shallowRef<Api.Workbench.QueryRecord[]>([]);

// 监听 serverRows 和修改的数据，更新 processedRows
watch(
  () => [serverRows.value, modifiedRowsData.value],
  () => {
    const rows = serverRows.value.map((row, index) => {
      const rowId = getRowId(row, index);
      if (modifiedRowsData.value.has(rowId)) {
        return { ...row, ...modifiedRowsData.value.get(rowId) };
      }
      return row;
    });
    processedRows.value = rows;

    // 直接更新 AG Grid 数据，不通过 Vue 响应式
    const api = gridApi.value;
    if (api && !api.isDestroyed()) {
      // 获取当前 AG Grid 中的行数
      const currentRowCount = api.getDisplayedRowCount();

      if (currentRowCount === 0 || rows.length <= currentRowCount) {
        // 首次加载或数据量减少：使用 setRowData 设置全部数据
        api.setGridOption('rowData', rows);
      } else {
        // 分片加载新增数据：使用 applyTransaction 添加新行
        const newRows = rows.slice(currentRowCount);
        if (newRows.length > 0) {
          api.applyTransaction({ add: newRows });
        }
      }
    }
  },
  { immediate: true, deep: false }
);

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
  notify: (type, message) => msg(type, message)
});

// 性能计时工具
function createTimer(label: string) {
  const start = performance.now();
  return {
    end: () => {
      const duration = performance.now() - start;
      console.log(`[性能计时] ${label}: ${duration.toFixed(2)}ms`);
      return duration;
    }
  };
}

async function loadPage() {
  const totalTimer = createTimer('loadPage 总耗时');
  const functionCode = String(props.meta.functionCode || '').trim();
  const params = String(props.meta.params || '').trim();
  if (!functionCode) {
    pageMeta.value = null;
    serverRows.value = [];
    total.value = 0;
    return;
  }

  // 清空表级修改记录
  tableModifiedRows.value.clear();
  modifiedRowsData.value.clear();
  originalRowsData.value.clear();

  // 设置初始加载标志，防止 columnResized 事件保存状态
  isInitialLoading.value = true;

  // 检查 store 缓存
  const cached = workbenchStore.getCache(functionCode, params);
  // 检查缓存是否完整（数据量是否等于总数）
  const isCacheComplete = cached && cached.isDataLoaded && cached.serverRows.length === cached.total;
  console.log(
    `[📋 loadPage] functionCode=${functionCode}, 缓存命中=${!!cached}, 缓存完整=${isCacheComplete}, 时间: ${performance.now().toFixed(1)}ms`
  );

  if (isCacheComplete) {
    console.log('[📦 缓存恢复] 数据量:', cached.serverRows.length);
    const cacheTimer = createTimer('📦 缓存恢复总耗时');

    // 步骤1: 恢复基本状态
    const step1Timer = createTimer('  [缓存-1] 恢复基本状态');
    pageMeta.value = cached.pageMeta;
    total.value = cached.total;
    totalCount.value = cached.total;
    loadedCount.value = cached.total;
    isInitialChunkLoaded.value = true;
    loading.value = false;
    loadedFunctionCode.value = functionCode;
    loadedParams.value = String(props.meta.params || '');
    isDataLoaded.value = true;
    step1Timer.end();

    // 步骤2: 恢复 UI 状态
    const step2Timer = createTimer('  [缓存-2] 恢复 UI 状态');
    const cachedUIState = workbenchStore.getUIState(functionCode, params);
    if (cachedUIState) {
      conditionVisible.value = cachedUIState.conditionVisible;
      fieldColumnVisible.value = cachedUIState.fieldColumnVisible;
      pinColumnVisible.value = cachedUIState.pinColumnVisible;
      quickKeyword.value = cachedUIState.quickKeyword;
      selectedField.value = cachedUIState.selectedField;
      selectedOperator.value = cachedUIState.selectedOperator as ConditionOperator;
      selectedValue.value = cachedUIState.selectedValue;
    }

    // 恢复分页状态
    const cachedPage = workbenchStore.getPage(functionCode, params);
    const cachedPageSize = workbenchStore.getPageSize(functionCode, params);
    if (cachedPage > 1 || cachedPageSize !== PAGE_SIZE_OPTIONS[0]) {
      isRestoringPage.value = true;
      page.value = cachedPage;
      pageSize.value = cachedPageSize;
    }
    step2Timer.end();

    // 步骤3: 恢复表格数据
    const step3Timer = createTimer('  [缓存-3] 恢复表格数据');
    // 大数据量时分片恢复，避免阻塞 UI
    if (cached.serverRows.length > CHUNK_SIZE) {
      console.log(`[📦 缓存恢复] 大数据量分片恢复: 先显示 ${CHUNK_SIZE} 条`);
      // 先显示第一片数据，让标签页立即响应
      const firstChunk = cached.serverRows.slice(0, CHUNK_SIZE);
      serverRows.value = firstChunk;
      step3Timer.end();

      // 在下一帧恢复剩余数据
      requestAnimationFrame(() => {
        const step4Timer = createTimer('  [缓存-4] 恢复剩余数据');
        // 使用 setTimeout 让出主线程
        setTimeout(() => {
          serverRows.value = cached.serverRows;
          isInitialLoading.value = false;
          step4Timer.end();
          cacheTimer.end();
          totalTimer.end();
          console.log('[📦 缓存恢复] ✅ 完成');
        }, 100);
      });
    } else {
      // 小数据量直接恢复
      serverRows.value = cached.serverRows;
      isInitialLoading.value = false;
      step3Timer.end();
      cacheTimer.end();
      totalTimer.end();
      console.log('[📦 缓存恢复] ✅ 完成（小数据量直接恢复）');
    }

    // 恢复行选择状态（延迟执行，不阻塞主流程）
    setTimeout(() => {
      const selectTimer = createTimer('  [缓存-5] 恢复行选择状态');
      const cachedSelectedRows = workbenchStore.getSelectedRows(functionCode, params);
      if (cachedSelectedRows.length > 0 && gridApi.value && !gridApi.value.isDestroyed()) {
        isRestoringSelection.value = true;
        // 只恢复前10条选中记录，避免大数据量下的性能问题
        const rowsToRestore = cachedSelectedRows.slice(0, 10);
        const guidSet = new Set(rowsToRestore.filter((r: any) => r.GUID).map((r: any) => r.GUID));
        const idSet = new Set(rowsToRestore.filter((r: any) => r.id).map((r: any) => r.id));

        gridApi.value.forEachNode(node => {
          const rowData = node.data;
          if (!rowData) return;
          const isSelected = (rowData.GUID && guidSet.has(rowData.GUID)) || (rowData.id && idSet.has(rowData.id));
          if (isSelected) {
            node.setSelected(true);
          }
        });
        isRestoringSelection.value = false;
      }
      // 重置分页恢复标志
      isRestoringPage.value = false;
      selectTimer.end();

      // 检查工具栏滚动状态（最低优先级）
      checkScrollPosition();
    }, 200);

    return;
  }

  loading.value = true;

  // 解析钻取参数（在请求前准备好，以便并行发送请求）
  const drillFilters: QueryFilter[] = [];
  let drillConditionSql = '';
  const drillParamsStr = String(props.meta.params || '').trim();
  if (drillParamsStr && drillParamsStr !== '') {
    try {
      const drillParams = JSON.parse(drillParamsStr);

      // 获取原始钻取条件 SQL（如：财务月份=`$本年度月份` or 工作预算月份=`$本年度月份`）
      const rawDrillCondition = drillParams['钻取条件'] || '';
      if (rawDrillCondition) {
        // 替换 SQL 变量为实际值
        drillConditionSql = rawDrillCondition;
        for (const [key, value] of Object.entries(drillParams)) {
          if (key === '钻取字段' || key === '钻取条件' || key === '字段选择') continue;
          const variable = `$${key}`;
          const valueStr = typeof value === 'string' ? value : JSON.stringify(value);
          drillConditionSql = drillConditionSql.replace(
            new RegExp(variable.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g'),
            valueStr
          );
        }
        // 将反引号替换为单引号（SQL 语法）
        drillConditionSql = drillConditionSql.replace(/`/g, "'");
      }

      // 从钻取参数中提取字段和值作为过滤条件（备用逻辑）
      const drillFieldsStr = drillParams['钻取字段'] || '';
      const nlArr = drillFieldsStr.split(';').filter((f: string) => f.trim());

      for (const field of nlArr) {
        const trimmedField = field.trim();
        if (trimmedField && drillParams[trimmedField] !== undefined && drillParams[trimmedField] !== '') {
          drillFilters.push({
            fieldKey: trimmedField,
            operator: 'equals',
            value: String(drillParams[trimmedField])
          });
        }
      }
    } catch {
      // 解析钻取参数失败，忽略
    }
  }

  // 优化：先只获取页面元数据和第一页数据，快速显示
  console.log('[性能] 开始加载，functionCode:', functionCode);
  const metaTimer = createTimer('获取页面元数据');
  const pageResult = await fetchWorkbenchPage(functionCode);
  metaTimer.end();

  if (pageResult.error) {
    const errorMsg = '工作台初始化失败';
    msg('error', errorMsg);
    console.error(`[工作台] ${errorMsg}:`, pageResult.error);
    loading.value = false;
    return;
  }

  const data = pageResult.data;
  pageMeta.value = data.meta;
  page.value = 1;
  pageSize.value = PAGE_SIZE_OPTIONS[0]; // 使用默认分页大小
  selectedField.value = data.meta.conditions[0]?.fieldKey || '';
  selectedValue.value = '';

  // 立即获取第一页数据（快速显示）
  const firstPageTimer = createTimer('获取第一页数据');
  const firstPageResult = await fetchWorkbenchPageData(functionCode, {
    current: 1,
    size: CHUNK_SIZE, // 1000条
    fetchTotal: true,
    filters: drillFilters,
    drillCondition: drillConditionSql || undefined
  });
  firstPageTimer.end();

  if (firstPageResult.error) {
    const errorMsg = '获取数据失败';
    msg('error', errorMsg);
    console.error(`[工作台] ${errorMsg}:`, firstPageResult.error);
    loading.value = false;
    return;
  }

  const firstPageData = firstPageResult.data;
  total.value = firstPageData.total;
  totalCount.value = firstPageData.total;

  // 立即显示第一页数据
  const renderTimer = createTimer('首屏渲染');
  serverRows.value = firstPageData.records;
  loadedCount.value = firstPageData.records.length;
  isInitialChunkLoaded.value = true;
  loading.value = false; // 主加载完成，显示首屏
  renderTimer.end();

  // 保存第一页到缓存
  workbenchStore.setCache(functionCode, params, {
    pageMeta: data.meta,
    serverRows: firstPageData.records,
    total: firstPageData.total,
    isDataLoaded: false // 标记为未完全加载
  });

  totalTimer.end();

  // 如果还有更多数据，在后台加载
  if (firstPageData.hasMore) {
    console.log('[性能] 开始后台加载剩余数据，总数:', firstPageData.total);
    isChunkLoading.value = true;

    // 延迟 500ms 后开始后台加载，确保首屏已渲染完成
    setTimeout(() => {
      loadRemainingData(functionCode, params, data.meta, drillFilters, drillConditionSql, firstPageData.total);
    }, 500);
  } else {
    // 数据量小于1000，已全部加载
    workbenchStore.setCache(functionCode, params, {
      pageMeta: data.meta,
      serverRows: firstPageData.records,
      total: firstPageData.total,
      isDataLoaded: true
    });
  }

  // 页面元数据加载完成后，检查工具栏滚动状态
  setTimeout(() => {
    checkScrollPosition();
  }, 100);

  // 数据加载完成后，调整列宽度
  setTimeout(() => {
    const api = gridApi.value;
    if (!api || api.isDestroyed()) return;

    const columnState = api.getColumnState();
    if (!columnState || !Array.isArray(columnState)) return;

    const allColIds = columnState
      .map((state: any) => state.colId)
      .filter((colId: string) => {
        if (colId === 'ag-Grid-SelectionColumn') return false;

        const column = api.getColumn(colId);
        if (column) {
          const def = column.getColDef();
          if (isGuidColumn(String(def.field || ''), String(def.headerName || def.field || ''))) {
            return false;
          }
        }

        return true;
      });

    if (allColIds.length > 0) {
      api.autoSizeColumns(allColIds, false);

      const maxWidth = 300;
      allColIds.forEach((colId: string) => {
        const column = api.getColumn(colId);
        if (column) {
          const currentWidth = column.getActualWidth();
          if (currentWidth > maxWidth) {
            api.setColumnWidths([{ key: colId, newWidth: maxWidth }]);
          }
        }
      });
    }
    // 重置初始加载标志
    isInitialLoading.value = false;
  }, 300);
}

/**
 * 后台加载剩余数据 - 优化版：并行加载
 */
async function loadRemainingData(
  functionCode: string,
  params: string,
  meta: Api.Workbench.PageMeta,
  filters: QueryFilter[],
  drillConditionSql: string,
  totalRecords: number
) {
  const bgTimer = createTimer('后台加载总耗时');
  const firstChunkSize = serverRows.value.length; // 首屏已加载的条数
  const remainingCount = totalRecords - firstChunkSize; // 剩余需要加载的条数

  if (remainingCount <= 0) {
    console.log('[性能] 数据已全部加载');
    bgTimer.end();
    return;
  }

  // 后台阶段使用更大分片减少请求次数；通过 offset 保证与首屏后续数据连续
  const PAGE_SIZE = 5000;

  // 滚动并发：避免“整批等待最慢请求”造成额外空转时间
  // 并发上限控制在 6，通常可显著缩短总耗时，同时避免对后端造成过高瞬时压力
  const CONCURRENT_REQUESTS = Math.max(3, Math.min(6, Math.ceil(remainingCount / PAGE_SIZE / 4)));

  const chunksNeeded = Math.ceil(remainingCount / PAGE_SIZE);
  const totalOffsets = Array.from({ length: chunksNeeded }, (_, i) => firstChunkSize + i * PAGE_SIZE);
  let nextChunkIndex = 0;
  let activeRequests = 0;
  let loadedRows = 0;

  // 保证按 offset 顺序拼接，避免并发返回顺序导致数据显示乱序
  const chunkRecordsMap = new Map<number, Api.Workbench.QueryRecord[]>();
  let nextMergeOffset = firstChunkSize;

  const mergeReadyPages = () => {
    const mergedRows: Api.Workbench.QueryRecord[] = [];
    while (chunkRecordsMap.has(nextMergeOffset)) {
      const rows = chunkRecordsMap.get(nextMergeOffset) || [];
      chunkRecordsMap.delete(nextMergeOffset);
      mergedRows.push(...rows);
      nextMergeOffset += PAGE_SIZE;
    }

    if (mergedRows.length > 0) {
      const updateTimer = createTimer('更新UI');
      serverRows.value = [...serverRows.value, ...mergedRows];
      updateTimer.end();
    }
  };

  await new Promise<void>(resolve => {
    const schedule = () => {
      while (activeRequests < CONCURRENT_REQUESTS && nextChunkIndex < totalOffsets.length) {
        const offset = totalOffsets[nextChunkIndex++];
        const current = Math.floor(offset / PAGE_SIZE) + 1;
        activeRequests += 1;
        const pageTimer = createTimer(`加载分片 offset=${offset}, size=${PAGE_SIZE}`);

        fetchWorkbenchPageData(functionCode, {
          current,
          size: PAGE_SIZE,
          offset,
          fetchTotal: false,
          filters,
          drillCondition: drillConditionSql || undefined
        })
          .then(result => {
            pageTimer.end();
            if (result.error) {
              console.error('[性能] 加载分片失败, offset=', offset, ', 错误:', result.error);
              chunkRecordsMap.set(offset, []);
              return;
            }

            const records = result.data.records;
            loadedRows += records.length;
            loadedCount.value = firstChunkSize + loadedRows;
            chunkRecordsMap.set(offset, records);
            mergeReadyPages();
          })
          .finally(async () => {
            activeRequests -= 1;

            // 页面隐藏时适度降载，减少后台无效抢占
            if (document.hidden) {
              await new Promise(r => setTimeout(r, 300));
            }

            if (nextChunkIndex >= totalOffsets.length && activeRequests === 0) {
              mergeReadyPages();
              resolve();
              return;
            }

            schedule();
          });
      }
    };

    schedule();
  });

  // 全部加载完成后，更新缓存为完整数据（serverRows 已经是完整数据）
  const cacheTimer = createTimer('更新缓存');
  workbenchStore.setCache(functionCode, params, {
    pageMeta: meta,
    serverRows: serverRows.value,
    total,
    isDataLoaded: true
  });
  cacheTimer.end();

  isChunkLoading.value = false;
  console.log('[性能] 后台加载完成，总数据量:', serverRows.value.length, '期望:', totalRecords);
  // 数据完整性校验
  if (serverRows.value.length !== totalRecords) {
    console.warn('[性能] ⚠️ 数据量不匹配！实际:', serverRows.value.length, '期望:', totalRecords);
  }
  bgTimer.end();
}

/**
 * 使用分页 API 获取所有数据（用于导出等需要全量数据的场景）
 */
async function fetchAllRows(functionCode: string, filters: QueryFilter[], drillConditionSql?: string) {
  const allRows: Api.Workbench.QueryRecord[] = [];
  let current = 1;
  const size = 5000; // 每页 5000 条
  let hasMore = true;

  while (hasMore) {
    const result = await fetchWorkbenchPageData(functionCode, {
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
  const functionCode = String(props.meta.functionCode || '').trim();
  if (!functionCode) {
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
  const allRows = await fetchAllRows(functionCode, filters);
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
  const functionCode = String(props.meta.functionCode || '').trim();
  const params = String(props.meta.params || '').trim();

  // 清除 store 缓存，强制重新加载
  if (functionCode) {
    workbenchStore.clearCache(functionCode, params);
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

  // 刷新表格
  if (gridApi.value) {
    gridApi.value.refreshCells({ force: true });
  }

  msg('success', '已刷新并恢复到初始状态');
}

function handleReset() {
  // 1. 清除所有查询条件
  quickKeyword.value = '';
  selectedField.value = pageMeta.value?.conditions[0]?.fieldKey || '';
  selectedOperator.value = 'contains';
  selectedValue.value = '';

  // 2. 清除颜色标注
  resetColorMarkState();

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

  // 7. 刷新表格以清除颜色标注样式
  if (gridApi.value) {
    gridApi.value.redrawRows();
  }

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
  notify: (type, message) => msg(type, message)
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
  onConfirmSelection: (fieldName, value) => {
    setEditFieldValue(fieldName, value);
  },
  notifyError: message => {
    window.$message?.error(message);
  }
});
// 获取字段选项
function _getFieldOptions(field: any): Array<{ label: string; value: string }> {
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
  const functionCode = props.meta?.functionCode || 'export';
  const timestamp = new Date().toISOString().slice(0, 19).replace(/:/g, '-');
  const filename = `${functionCode}_${timestamp}.xlsx`;

  // 下载文件
  XLSX.writeFile(wb, filename);

  msg('success', `成功导出 ${rowData.length} 条数据`);
}

async function handleDebug() {
  const functionCode = String(props.meta.functionCode || '').trim();
  if (!functionCode) {
    msg('error', '功能编码不能为空');
    return;
  }

  try {
    const payload: Api.Workbench.QueryPayload = {
      all: true,
      filters: []
    };

    const { data, error } = await fetchWorkbenchDebug(functionCode, payload);

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
    console.log('  - 角色编码:', Array.isArray(data.userAuth.roleCodes) ? data.userAuth.roleCodes.join(', ') : (data.userAuth.roleCodes || '(无)'));
    console.log('  - 属地赋权:', data.userAuth.locationAuth);
    console.log('  - 部门编码赋权:', Array.isArray(data.userAuth.deptCodeAuth) ? data.userAuth.deptCodeAuth.join(', ') : (data.userAuth.deptCodeAuth || '(无)'));
    console.log('  - 部门全称赋权:', Array.isArray(data.userAuth.deptNameAuth) ? data.userAuth.deptNameAuth.join(', ') : (data.userAuth.deptNameAuth || '(无)'));
    console.log('  - 调试权限:', data.userAuth.debugAuth ? '有' : '无');

    console.log('\n⚙️ 功能权限:');
    console.log('  - 模块:', data.functionAuth.module);
    console.log('  - 参数:', data.functionAuth.params || '(无)');
    console.log('  - 部门权限条件:', data.functionAuth.deptAuthCond || '(无)');
    console.log('  - 属地权限条件:', data.functionAuth.locationAuthCond || '(无)');

    console.log('\n📋 字段映射:');
    console.table(data.columns);

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

  const functionCode = String(props.meta.functionCode || '').trim();
  if (!functionCode) {
    msg('error', '功能编码不能为空');
    return;
  }

  const selectedRow = selectedRows[0];

  loading.value = true;
  fetchWorkbenchDrill(functionCode, {})
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

  const functionCode = String(props.meta.functionCode || '').trim();
  const params = String(props.meta.params || '').trim();

  // 恢复筛选条件
  const cachedFilterModel = workbenchStore.getFilterModel(functionCode, params);
  if (cachedFilterModel) {
    setTimeout(() => {
      if (gridApi.value && !gridApi.value.isDestroyed()) {
        gridApi.value.setFilterModel(cachedFilterModel);
      }
    }, 100);
  }

  // 恢复列状态（包括排序、列宽、列顺序、固定列等）
  const cachedColumnState = workbenchStore.getColumnState(functionCode, params);
  if (cachedColumnState && Array.isArray(cachedColumnState) && cachedColumnState.length > 0) {
    setTimeout(() => {
      if (gridApi.value && !gridApi.value.isDestroyed()) {
        isRestoringColumnState.value = true;

        // 合并固定列信息
        const cachedPinColumns = workbenchStore.getPinColumns(functionCode, params);
        const pinColumnsArray = Array.from(cachedPinColumns);
        const mergedColumnState = cachedColumnState.map((col: any) => {
          if (pinColumnsArray.includes(col.colId)) {
            return { ...col, pinned: 'left' };
          }
          return col;
        });

        gridApi.value.applyColumnState({ state: mergedColumnState, applyOrder: true });

        // 恢复可见列
        const cachedVisibleColumns = workbenchStore.getVisibleColumns(functionCode, params);
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

// 获取行的唯一标识（优先使用 GUID，其次使用 id，最后使用索引）
function getRowId(row: Api.Workbench.QueryRecord, index: number): string {
  if (row.GUID) return String(row.GUID);
  if (row.id) return String(row.id);
  if (row.ID) return String(row.ID);
  return String(index);
}

// 处理单元格值变化事件（表级修改）
function handleCellValueChanged(event: any) {
  // 如果正在恢复单元格值，跳过处理（防止递归）
  if (isRestoringCellValue.value) {
    return;
  }

  // 检查是否有整表修改权限
  if (!hasTableEditAuth.value) {
    // 只提示一次
    msg('warning', '数据在此处修改无效，请点击"单条修改"或"多条修改"按钮进行修改');
    // 恢复原值
    isRestoringCellValue.value = true;
    const rowNode = event.node;
    const originalValue = event.oldValue;
    const field = event.colDef.field;
    // 使用 setDataValue 恢复原始值
    rowNode.setDataValue(field, originalValue);
    // 延迟重置标志，确保事件处理完成
    setTimeout(() => {
      isRestoringCellValue.value = false;
    }, 0);
    return;
  }

  // 记录修改的行ID（使用数据中的 GUID 或 id）
  const rowData = event.data;
  const rowIndex = event.rowIndex;
  const rowId = getRowId(rowData, rowIndex);

  tableModifiedRows.value.add(rowId);

  // 保存原始行数据（用于获取主键）
  if (!originalRowsData.value.has(rowId)) {
    originalRowsData.value.set(rowId, { ...rowData });
  }

  // 只存储修改的字段和值
  const currentData = modifiedRowsData.value.get(rowId) || {};
  currentData[event.colDef.field] = event.newValue;
  modifiedRowsData.value.set(rowId, currentData);
}

// 处理表级修改提交
async function handleTableEditSubmit() {
  if (tableModifiedRows.value.size === 0) {
    msg('warning', '没有需要提交的修改');
    return;
  }

  const functionCode = String(props.meta.functionCode || '').trim();
  if (!functionCode) {
    msg('error', '功能编码不能为空');
    return;
  }

  // 收集修改的数据（只包含主键字段和修改的字段）
  const modifiedData: Api.Workbench.QueryRecord[] = [];
  tableModifiedRows.value.forEach(rowId => {
    const originalRow = originalRowsData.value.get(rowId);
    const modifiedFields = modifiedRowsData.value.get(rowId);

    if (originalRow && modifiedFields) {
      // 构建提交数据：主键字段 + 修改的字段
      const submitData: Record<string, any> = {};

      // 添加主键字段（GUID、id、ID）
      if (originalRow.GUID !== undefined) submitData.GUID = originalRow.GUID;
      if (originalRow.id !== undefined) submitData.id = originalRow.id;
      if (originalRow.ID !== undefined) submitData.ID = originalRow.ID;

      // 添加修改的字段（排除序号字段）
      Object.keys(modifiedFields).forEach(field => {
        // 排除序号字段（通常名为"序号"或以"序号"开头）
        if (field !== '序号' && !field.startsWith('序号')) {
          submitData[field] = modifiedFields[field];
        }
      });

      modifiedData.push(submitData);
    }
  });

  if (modifiedData.length === 0) {
    msg('warning', '没有需要提交的修改');
    return;
  }

  try {
    const { data, error } = await submitTableEdit(functionCode, modifiedData);
    if (error) {
      msg('error', error.message || '表级修改提交失败');
      return;
    }

    if (data?.success) {
      msg('success', data.message || '表级修改提交成功');
      // 清空修改记录
      tableModifiedRows.value.clear();
      modifiedRowsData.value.clear();
      originalRowsData.value.clear();
      // 刷新数据
      const currentParams = String(props.meta.params || '').trim();
      workbenchStore.clearCache(functionCode, currentParams);
      isDataLoaded.value = false;
      loadPage();
    } else {
      msg('error', data?.message || '表级修改提交失败');
    }
  } catch (e: any) {
    msg('error', e.message || '表级修改提交失败');
  }
}
</script>

<template>
  <div class="generic-query-workbench" :class="{ 'system-dark': isDarkMode }">
    <NCard
      :bordered="false"
      :content-style="{ padding: '8px 10px' }"
      class="toolbar-card mb-6px rounded-12px shadow-sm"
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
            <NButton v-if="hasChartEnabled" @click="handleOpenChart">图形</NButton>
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
      :class="{ 'chart-mode': chartVisible, 'resizing': isResizing }"
    >
      <!-- 左侧表格区域 -->
      <div
        class="table-area"
        :style="chartVisible ? { flex: `0 0 ${leftPanelWidth}%` } : {}"
      >
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
              @cell-value-changed="handleCellValueChanged"
            />
            <!-- 分片加载进度提示 -->
            <div v-if="isChunkLoading && !loading" class="chunk-loading-progress">
              <NSpin size="small" />
              <span class="progress-text">
                已加载 {{ loadedCount.toLocaleString() }} / {{ totalCount.toLocaleString() }} 条记录...
              </span>
            </div>
          </div>
        </NCard>
      </div>

      <!-- 可拖动分隔条 -->
      <div
        v-if="chartVisible"
        class="resize-handle"
        :class="{ 'active': isResizing }"
        @mousedown="startResize"
        title="拖动调整宽度"
      >
        <div class="resize-handle-line"></div>
        <div class="resize-handle-dots">
          <div class="resize-dot"></div>
          <div class="resize-dot"></div>
          <div class="resize-dot"></div>
        </div>
      </div>

      <!-- 右侧图形区域 -->
      <div
        v-show="chartVisible"
        class="chart-area"
        :style="{ flex: `0 0 ${100 - leftPanelWidth}%` }"
      >
        <NCard
          :bordered="false"
          :content-style="{ padding: '0' }"
          class="chart-card rounded-12px shadow-sm"
        >
          <div class="chart-header">
            <span class="chart-title">图形展示</span>
            <NButton size="small" @click="chartVisible = false">关闭</NButton>
          </div>
          <div class="chart-container">
            <NSpin :show="chartLoading">
              <div v-show="chartOption" ref="chartRef" class="chart-wrapper"></div>
              <NEmpty v-if="!chartOption && !chartLoading" description="暂无图形数据" />
            </NSpin>
          </div>
        </NCard>
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

<style scoped>
.generic-query-workbench {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
}

.system-dark.generic-query-workbench {
  --wb-dark-bg: rgb(var(--container-bg-color));
  background: var(--wb-dark-bg);
}

.toolbar-card,
.grid-card {
  background: #ffffff;
}

/* 工具栏滚动样式 */
.toolbar-scroll {
  flex: 1;
  min-width: 0;
  scrollbar-width: none; /* Firefox */
  -ms-overflow-style: none; /* IE and Edge */
}

.toolbar-scroll::-webkit-scrollbar {
  display: none; /* Chrome, Safari, Opera */
}

.scroll-arrow {
  flex-shrink: 0;
  color: var(--n-text-color);
  transition: opacity 0.2s;
}

.scroll-arrow:hover {
  color: var(--n-primary-color);
}

.ag-theme-shell {
  position: relative;
  height: 100%;
  min-height: 0;
}

.ag-theme-shell-dynamic {
  height: 100%;
  min-height: 0;
}

/* 批注表单样式 - 参考旧版 */
.comment-form-wrapper {
  /* 确保表头和数据之间无间隔 */
}

.comment-form-header {
  display: flex;
  background-color: #f5f5f5;
  border: 1px solid #d9d9d9;
  border-bottom: 1px solid #d9d9d9;
  font-weight: bold;
}

.comment-form-body {
  border: 1px solid #d9d9d9;
  border-top: none;
}

.comment-form-row {
  display: flex;
  border-bottom: 1px solid #d9d9d9;
}

.comment-form-row:last-child {
  border-bottom: none;
}

.comment-form-col {
  padding: 8px 12px;
  display: flex;
  align-items: center;
}

.comment-col-name {
  width: 120px;
  border-right: 1px solid #d9d9d9;
  background-color: #fafafa;
}

.comment-col-type {
  width: 80px;
  border-right: 1px solid #d9d9d9;
  background-color: #fafafa;
  justify-content: center;
}

.comment-col-value {
  flex: 1;
}

.comment-key-field-value {
  color: #666;
  font-style: italic;
}

/* 深色主题下的批注表单样式 - 使用 !important 确保覆盖 */
.system-dark .comment-form-header,
.system-dark .generic-query-workbench .comment-form-header {
  background-color: #1f1f1f !important;
  border-color: #4b5965 !important;
  color: #e0e0e0 !important;
}

.system-dark .comment-form-body,
.system-dark .generic-query-workbench .comment-form-body {
  border-color: #4b5965 !important;
}

.system-dark .comment-form-row,
.system-dark .generic-query-workbench .comment-form-row {
  border-bottom-color: #4b5965 !important;
}

.system-dark .comment-form-row:last-child,
.system-dark .generic-query-workbench .comment-form-row:last-child {
  border-bottom: none !important;
}

.system-dark .comment-col-name,
.system-dark .comment-col-type,
.system-dark .generic-query-workbench .comment-col-name,
.system-dark .generic-query-workbench .comment-col-type {
  background-color: #1f1f1f !important;
  border-right-color: #4b5965 !important;
  color: #e0e0e0 !important;
}

.system-dark .comment-col-value,
.system-dark .generic-query-workbench .comment-col-value {
  color: #e0e0e0 !important;
}

.system-dark .comment-key-field-value,
.system-dark .generic-query-workbench .comment-key-field-value {
  color: #b0b0b0 !important;
}

/* 批注弹窗深色主题 - 使用 :deep 穿透 */
:deep(.comment-modal-dark) .comment-form-header {
  background-color: #1f1f1f !important;
  border-color: #4b5965 !important;
  color: #e0e0e0 !important;
}

:deep(.comment-modal-dark) .comment-form-body {
  border-color: #4b5965 !important;
}

:deep(.comment-modal-dark) .comment-form-row {
  border-bottom-color: #4b5965 !important;
}

:deep(.comment-modal-dark) .comment-form-row:last-child {
  border-bottom: none !important;
}

:deep(.comment-modal-dark) .comment-col-name,
:deep(.comment-modal-dark) .comment-col-type {
  background-color: #1f1f1f !important;
  border-right-color: #4b5965 !important;
  color: #e0e0e0 !important;
}

:deep(.comment-modal-dark) .comment-col-value {
  color: #e0e0e0 !important;
}

:deep(.comment-modal-dark) .comment-key-field-value {
  color: #b0b0b0 !important;
}

.workbench-grid-card {
  min-height: 0;
  overflow: hidden;
  flex: 1;
}

.query-grid {
  --wb-grid-surface: transparent;
  --wb-grid-text: #1f2937;
  width: 100%;
  height: 100%;
}

.pin-column-select-panel {
  max-height: 320px;
  overflow: auto;
  border: 1px solid #d4dce5;
  border-radius: 8px;
  padding: 12px;
  background: #f7f9fc;
}

.pin-column-actions {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-bottom: 12px;
}

.pin-column-actions :deep(.n-checkbox .n-checkbox__label) {
  color: #334155;
}

.pin-column-group {
  padding-top: 8px;
  border-top: 1px solid #d4dce5;
}

.pin-column-group :deep(.n-checkbox .n-checkbox__label) {
  color: #334155;
}

:deep(.query-grid .ag-root-wrapper),
:deep(.query-grid .ag-root),
:deep(.query-grid .ag-header),
:deep(.query-grid .ag-body),
:deep(.query-grid .ag-floating-top),
:deep(.query-grid .ag-floating-bottom),
:deep(.query-grid .ag-row),
:deep(.query-grid .ag-row-odd),
:deep(.query-grid .ag-row-even),
:deep(.query-grid .ag-header-row) {
  background-color: var(--wb-grid-surface) !important;
}

:deep(.query-grid .ag-header-cell),
:deep(.query-grid .ag-cell),
:deep(.query-grid .ag-header-cell-text),
:deep(.query-grid .ag-cell-value) {
  color: var(--wb-grid-text);
}

/* 异常和提示单元格样式 - 使用属性选择器提高优先级 */
:deep(.query-grid .ag-cell[style*='color']),
:deep(.query-grid .ag-cell-value[style*='color']) {
  /* 允许 cellStyle 的样式生效 */
}

:deep(.query-grid .ag-cell-wrapper),
:deep(.query-grid .ag-header-cell-comp-wrapper) {
  height: 100%;
  align-items: center;
}

:deep(.query-grid .ag-selection-checkbox),
:deep(.query-grid .ag-checkbox-input-wrapper) {
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

:deep(.query-grid .ag-selection-checkbox),
:deep(.query-grid .ag-header-select-all) {
  width: 100%;
  height: 100%;
}

:deep(.query-grid .ag-header-select-all) {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding-left: 0;
}

:deep(.query-grid .selection-header-left .ag-header-cell-comp-wrapper) {
  width: 100%;
  justify-content: center !important;
  padding-left: 0 !important;
}

:deep(.query-grid .selection-header-left .ag-header-select-all) {
  margin: 0 auto;
  width: auto !important;
}

:deep(.query-grid .selection-header-left .ag-header-select-all .ag-selection-checkbox) {
  justify-content: center !important;
  width: auto !important;
  padding-left: 0 !important;
}

:deep(.query-grid .selection-header-left .ag-header-cell-label) {
  width: 100% !important;
  justify-content: center !important;
  padding-left: 0 !important;
  gap: 0 !important;
}

:deep(.query-grid .selection-header-left .ag-header-cell-label .ag-checkbox-input-wrapper) {
  margin-left: 0 !important;
  margin-right: 0 !important;
}

/* Final override: target AG Grid selection column directly. */
:deep(.query-grid .ag-header-cell[col-id='ag-Grid-SelectionColumn']) {
  position: relative;
  padding-left: 0 !important;
  padding-right: 0 !important;
}

:deep(.query-grid .ag-header-cell[col-id='ag-Grid-SelectionColumn'] .ag-header-cell-comp-wrapper) {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
}

:deep(.query-grid .ag-header-cell[col-id='ag-Grid-SelectionColumn'] .ag-header-cell-label) {
  justify-content: center !important;
  padding-left: 0 !important;
}

:deep(.query-grid .ag-header-cell[col-id='ag-Grid-SelectionColumn'] .ag-header-select-all) {
  position: absolute !important;
  left: 50% !important;
  top: 50% !important;
  transform: translate(-50%, -50%) !important;
  width: 16px !important;
  height: 16px !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
}

:deep(.query-grid .ag-header-cell[col-id='ag-Grid-SelectionColumn'] .ag-selection-checkbox),
:deep(.query-grid .ag-header-cell[col-id='ag-Grid-SelectionColumn'] .ag-checkbox-input-wrapper) {
  margin: 0 !important;
  padding: 0 !important;
}

:deep(.query-grid .ag-cell[col-id='ag-Grid-SelectionColumn']) {
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  padding-left: 0 !important;
  padding-right: 0 !important;
}

:deep(.query-grid .ag-cell[col-id='ag-Grid-SelectionColumn'] .ag-cell-wrapper) {
  display: flex;
  align-items: center;
  justify-content: center;
}

:deep(.query-grid .ag-cell[col-id='ag-Grid-SelectionColumn'] .ag-selection-checkbox),
:deep(.query-grid .ag-cell[col-id='ag-Grid-SelectionColumn'] .ag-checkbox-input-wrapper) {
  position: relative;
  width: 16px;
  height: 16px;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
}

/* Force checkbox wrapper to center */
:deep(.query-grid .ag-cell[col-id='ag-Grid-SelectionColumn'] .ag-cell-wrapper .ag-selection-checkbox) {
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  margin-left: auto !important;
  margin-right: auto !important;
  position: absolute;
  left: 50%;
  top: 50%;
  transform: translate(-50%, -50%);
}

:deep(.query-grid .ag-cell-value),
:deep(.query-grid .ag-header-cell-text) {
  display: inline-flex;
  align-items: center;
}

.grid-loading {
  position: absolute;
  inset: 0;
  z-index: 2;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(255, 255, 255, 0.7);
}

/* 左右分栏布局样式 */
.workbench-content {
  display: flex;
  gap: 0;
  height: calc(100vh - 200px);
  min-height: 0;
  position: relative;
}

.workbench-content.resizing {
  user-select: none;
}

.workbench-content .table-area {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  min-height: 0;
}

.workbench-content.chart-mode .table-area {
  flex: 0 0 55%;
}

.workbench-content .chart-area {
  min-width: 0;
  display: flex;
  flex-direction: column;
  min-height: 0;
}

/* 可拖动分隔条样式 */
.resize-handle {
  width: 12px;
  min-width: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: col-resize;
  background: transparent;
  position: relative;
  z-index: 10;
  transition: background 0.2s ease;
}

.resize-handle:hover,
.resize-handle.active {
  background: rgba(128, 128, 128, 0.1);
}

.resize-handle-line {
  width: 2px;
  height: 100%;
  background: #d9d9d9;
  transition: background 0.2s ease, width 0.2s ease;
}

.resize-handle:hover .resize-handle-line,
.resize-handle.active .resize-handle-line {
  background: #1890ff;
  width: 3px;
}

.resize-handle-dots {
  position: absolute;
  display: flex;
  flex-direction: column;
  gap: 3px;
  pointer-events: none;
}

.resize-dot {
  width: 4px;
  height: 4px;
  border-radius: 50%;
  background: #bfbfbf;
  transition: background 0.2s ease;
}

.resize-handle:hover .resize-dot,
.resize-handle.active .resize-dot {
  background: #1890ff;
}

/* 深色模式下的分隔条样式 */
.system-dark .resize-handle-line {
  background: #555;
}

.system-dark .resize-dot {
  background: #666;
}

.system-dark .resize-handle:hover .resize-handle-line,
.system-dark .resize-handle.active .resize-handle-line {
  background: #4a9eff;
}

.system-dark .resize-handle:hover .resize-dot,
.system-dark .resize-handle.active .resize-dot {
  background: #4a9eff;
}

.chart-card {
  height: 100%;
  display: flex;
  flex-direction: column;
  min-height: 0;
}

.chart-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 16px;
  border-bottom: 1px solid var(--n-border-color);
}

.chart-title {
  font-size: 16px;
  font-weight: 600;
}

.chart-container {
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
  padding: 12px;
}

.chart-container .n-spin {
  flex: 1;
  display: flex;
  flex-direction: column;
}

.chart-container :deep(.n-spin-container) {
  width: 100%;
  flex: 1;
  display: flex;
  flex-direction: column;
}

.chart-container :deep(.n-spin-content) {
  width: 100%;
  flex: 1;
  display: flex;
  flex-direction: column;
}

.chart-wrapper {
  width: 100%;
  flex: 1;
  min-height: 300px;
}

.system-dark .toolbar-card,
.system-dark .grid-card {
  background: var(--wb-dark-bg);
}

.pin-column-modal-dark .pin-column-select-panel {
  border-color: #3d4f60;
  background: #1b2a38;
}

.pin-column-modal-dark .pin-column-group {
  border-top-color: #3d4f60;
}

.pin-column-modal-dark .pin-column-actions :deep(.n-checkbox .n-checkbox__label) {
  color: rgb(var(--base-text-color));
}

.pin-column-modal-dark .pin-column-actions :deep(.n-checkbox .n-checkbox-box) {
  border-color: #7f95ac;
  background-color: #1f3042;
}

.pin-column-modal-dark .pin-column-actions :deep(.n-checkbox.n-checkbox--checked .n-checkbox-box) {
  border-color: #4ea4f3;
  background-color: #2f7fc5;
}

.pin-column-modal-dark .pin-column-group :deep(.n-checkbox .n-checkbox__label) {
  color: rgb(var(--base-text-color));
}

.pin-column-modal-dark .pin-column-group :deep(.n-checkbox .n-checkbox-box) {
  border-color: #7f95ac;
  background-color: #1f3042;
}

.pin-column-modal-dark .pin-column-group :deep(.n-checkbox.n-checkbox--checked .n-checkbox-box) {
  border-color: #4ea4f3;
  background-color: #2f7fc5;
}

.system-dark :deep(.toolbar-card),
.system-dark :deep(.grid-card) {
  --n-color: var(--wb-dark-bg);
  --n-color-embedded: var(--wb-dark-bg);
  --n-color-modal: var(--wb-dark-bg);
}

.system-dark :deep(.n-card),
.system-dark :deep(.n-card-header),
.system-dark :deep(.n-card-content),
.system-dark :deep(.n-card__content) {
  background: var(--wb-dark-bg) !important;
}

.system-dark :deep(.toolbar-card .n-card__content),
.system-dark :deep(.grid-card .n-card__content),
.system-dark :deep(.toolbar-card .n-card-content),
.system-dark :deep(.grid-card .n-card-content) {
  background: var(--wb-dark-bg);
}

.system-dark .query-grid {
  --wb-grid-surface: var(--wb-dark-bg);
  --wb-grid-text: rgb(var(--base-text-color));
  --ag-background-color: var(--wb-dark-bg);
  --ag-header-background-color: var(--wb-dark-bg);
  --ag-data-background-color: var(--wb-dark-bg);
  --ag-control-panel-background-color: var(--wb-dark-bg);
  --ag-panel-background-color: var(--wb-dark-bg);
  --ag-subheader-background-color: var(--wb-dark-bg);
  --ag-odd-row-background-color: var(--wb-dark-bg);
  --ag-foreground-color: var(--wb-grid-text);
  --ag-secondary-foreground-color: var(--wb-grid-text);
  --ag-border-color: #2b3a49;
  --ag-row-border-color: #2b3a49;
  --ag-input-background-color: #1b2a38;
  --ag-input-border-color: #43576b;
}

.system-dark .grid-loading {
  background: rgba(16, 22, 29, 0.75);
}

.system-dark :deep(.query-grid .ag-root-wrapper),
.system-dark :deep(.query-grid .ag-root),
.system-dark :deep(.query-grid .ag-header),
.system-dark :deep(.query-grid .ag-body),
.system-dark :deep(.query-grid .ag-floating-bottom),
.system-dark :deep(.query-grid .ag-floating-top) {
  background-color: var(--wb-dark-bg);
  color: var(--wb-grid-text);
}

.system-dark :deep(.query-grid .ag-root-wrapper) {
  border-color: #2b3a49;
}

.system-dark :deep(.query-grid .ag-paging-panel) {
  background-color: var(--wb-dark-bg);
  color: var(--wb-grid-text);
  border-top-color: #2b3a49;
}

.system-dark :deep(.query-grid .ag-paging-button) {
  color: var(--wb-grid-text);
  background-color: #1b2a38;
  border: 1px solid #43576b;
  border-radius: 4px;
}

.system-dark :deep(.query-grid .ag-paging-button:hover) {
  color: #f3f8ff;
  background-color: #243547;
  border-color: #5a7190;
}

.system-dark :deep(.query-grid .ag-paging-button.ag-disabled),
.system-dark :deep(.query-grid .ag-paging-button[aria-disabled='true']) {
  color: rgb(var(--base-text-color) / 0.45);
  background-color: #151f2a;
  border-color: #2f4152;
}

.system-dark :deep(.query-grid .ag-paging-button .ag-icon) {
  color: inherit;
}

.system-dark :deep(.query-grid .ag-picker-field-wrapper),
.system-dark :deep(.query-grid .ag-select .ag-picker-field-wrapper),
.system-dark :deep(.query-grid .ag-paging-page-size .ag-wrapper) {
  background-color: var(--wb-dark-bg);
  border-color: #43576b;
  color: var(--wb-grid-text);
}

.system-dark :deep(.query-grid .ag-picker-field-display),
.system-dark :deep(.query-grid .ag-picker-field-icon) {
  color: var(--wb-grid-text);
}

.system-dark :deep(.query-grid .ag-body-horizontal-scroll),
.system-dark :deep(.query-grid .ag-body-vertical-scroll),
.system-dark :deep(.query-grid .ag-body-horizontal-scroll-viewport),
.system-dark :deep(.query-grid .ag-body-vertical-scroll-viewport),
.system-dark :deep(.query-grid .ag-body-horizontal-scroll-container),
.system-dark :deep(.query-grid .ag-body-vertical-scroll-container) {
  background-color: var(--wb-dark-bg) !important;
}

.system-dark :deep(.query-grid .ag-body-horizontal-scroll-viewport),
.system-dark :deep(.query-grid .ag-body-vertical-scroll-viewport) {
  scrollbar-color: #5e6f80 var(--wb-dark-bg);
}

.system-dark :deep(.query-grid .ag-body-horizontal-scroll-viewport::-webkit-scrollbar),
.system-dark :deep(.query-grid .ag-body-vertical-scroll-viewport::-webkit-scrollbar) {
  background-color: var(--wb-dark-bg);
}

.system-dark :deep(.query-grid .ag-body-horizontal-scroll-viewport::-webkit-scrollbar-thumb),
.system-dark :deep(.query-grid .ag-body-vertical-scroll-viewport::-webkit-scrollbar-thumb) {
  background-color: #5e6f80;
  border-radius: 8px;
}

.system-dark :deep(.query-grid .ag-header-cell),
.system-dark :deep(.query-grid .ag-cell),
.system-dark :deep(.query-grid .ag-header-cell-text),
.system-dark :deep(.query-grid .ag-cell-value) {
  color: var(--wb-grid-text);
}

.system-dark :deep(.query-grid .ag-header-cell .ag-icon),
.system-dark :deep(.query-grid .ag-header-cell .ag-header-icon),
.system-dark :deep(.query-grid .ag-header-cell-menu-button),
.system-dark :deep(.query-grid .ag-header-cell-filter-button),
.system-dark :deep(.query-grid .ag-header-cell-sortable .ag-sort-indicator-icon) {
  color: var(--wb-grid-text) !important;
  opacity: 0.95;
}

.system-dark :deep(.query-grid .ag-header-cell-menu-button:hover),
.system-dark :deep(.query-grid .ag-header-cell-filter-button:hover),
.system-dark :deep(.query-grid .ag-header-cell .ag-icon:hover) {
  color: #f3f8ff !important;
  opacity: 1;
}

.system-dark :deep(.query-grid .ag-row-hover::before) {
  background-color: rgba(122, 167, 214, 0.18) !important;
}

/* Legacy visual hints from Vgrid_aggrid.php */
:deep(.ag-header-cell.editable-column .ag-header-cell-text) {
  text-decoration: underline;
  text-decoration-color: #1f8f63;
  text-decoration-thickness: 2px;
  font-weight: 700;
}

/* Force dotted row/column borders to match legacy Vgrid_aggrid.php */
:deep(.query-grid .ag-header-cell),
:deep(.query-grid .ag-cell) {
  border-right: 1px dotted #c1ccc7 !important;
}

/* 选中单元格的边框样式 - 修复右侧选中线缺失问题 */
:deep(.query-grid .ag-cell-focus),
:deep(.query-grid .ag-cell-range-selected) {
  border-right: 1px solid #2196f3 !important;
  border-left: 1px solid #2196f3 !important;
  border-top: 1px solid #2196f3 !important;
  border-bottom: 1px solid #2196f3 !important;
}

:deep(.query-grid .ag-row),
:deep(.query-grid .ag-header-row) {
  border-bottom: 1px dotted #c1ccc7 !important;
}

:deep(.ag-header-cell.pinned-header) {
  background-color: #e6f7ff !important;
}

/* Align checkbox visual with legacy grid style (square box + white check). */
:deep(.query-grid .ag-checkbox-input-wrapper) {
  position: relative;
  width: 16px;
  height: 16px;
  border: 1px solid #95a6b8;
  border-radius: 2px;
  background-color: #ffffff;
  line-height: 16px;
}

:deep(.query-grid .ag-checkbox-input-wrapper::before) {
  display: none !important;
}

:deep(.query-grid .ag-checkbox-input-wrapper::after) {
  content: '';
  position: absolute;
  left: 50%;
  top: 50%;
  width: 0;
  height: 0;
  border: 0;
  transform: translate(-50%, -50%);
}

:deep(.query-grid .ag-checkbox-input-wrapper.ag-checked) {
  border-color: #2a90e8;
  background-color: #2a90e8;
}

:deep(.query-grid .ag-checkbox-input-wrapper.ag-checked::after) {
  content: '✓';
  width: auto;
  height: auto;
  color: #ffffff;
  font-size: 12px;
  font-weight: 700;
  line-height: 1;
  transform: translate(-50%, -56%);
}

:deep(.query-grid .ag-checkbox-input-wrapper.ag-indeterminate) {
  border-color: #2a90e8;
  background-color: #2a90e8;
}

:deep(.query-grid .ag-checkbox-input-wrapper.ag-indeterminate::after) {
  content: '';
  left: 50%;
  top: 50%;
  width: 8px;
  height: 2px;
  border: 0;
  background: #ffffff;
  transform: translate(-50%, -50%);
}

:deep(.query-grid .ag-cell .ag-checkbox-input-wrapper.ag-indeterminate::after) {
  content: '✓';
  width: auto;
  height: auto;
  border: 0;
  background: transparent;
  color: #ffffff;
  font-size: 12px;
  font-weight: 700;
  line-height: 1;
  transform: translate(-50%, -56%);
}

/* Match legacy selected-row tint. */
:deep(.query-grid .ag-row-selected::before) {
  background-color: #b7d7f5 !important;
}

:deep(.query-grid .ag-row-hover.ag-row-selected::before) {
  background-color: #c8e4ff !important;
  background-image: none !important;
}

.system-dark :deep(.query-grid .ag-header-cell),
.system-dark :deep(.query-grid .ag-cell) {
  border-right: 1px dotted #4b5965 !important;
}

/* 深色主题选中单元格的边框样式 */
.system-dark :deep(.query-grid .ag-cell-focus),
.system-dark :deep(.query-grid .ag-cell-range-selected) {
  border-right: 2px solid #64b5f6 !important;
  border-left: 2px solid #64b5f6 !important;
  border-top: 2px solid #64b5f6 !important;
  border-bottom: 2px solid #64b5f6 !important;
}

.system-dark :deep(.query-grid .ag-row),
.system-dark :deep(.query-grid .ag-header-row) {
  border-bottom: 1px dotted #4b5965 !important;
}

.system-dark :deep(.ag-header-cell.pinned-header) {
  background-color: #1d3242 !important;
}

.system-dark :deep(.query-grid .ag-row-selected::before) {
  background-color: #34516f !important;
}

.system-dark :deep(.query-grid .ag-row-hover.ag-row-selected::before) {
  background-color: #406281 !important;
}

.system-dark :deep(.query-grid .ag-checkbox-input-wrapper) {
  border-color: #6f859b;
  background-color: var(--wb-dark-bg);
}

.system-dark :deep(.query-grid .ag-checkbox-input-wrapper.ag-checked),
.system-dark :deep(.query-grid .ag-checkbox-input-wrapper.ag-indeterminate) {
  border-color: #4ea4f3;
  background-color: #2f7fc5;
}

/* 卡片式批注列表样式 */
.comment-card-list {
  max-height: 500px;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.comment-card {
  background: #ffffff;
  border: 1px solid #e8e8e8;
  border-radius: 8px;
  padding: 16px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
  transition: all 0.2s ease;
}

.comment-card:hover {
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
  border-color: #d9d9d9;
}

.comment-card-dark {
  background: #1f1f1f;
  border-color: #4b5965;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
}

.comment-card-dark:hover {
  border-color: #5a6a7a;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
}

.comment-card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
  padding-bottom: 12px;
  border-bottom: 1px solid #f0f0f0;
}

.comment-card-dark .comment-card-header {
  border-bottom-color: #4b5965;
}

.comment-card-user {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 600;
  color: #1890ff;
  font-size: 14px;
}

.comment-card-dark .comment-card-user {
  color: #4ea4f3;
}

.comment-card-user-icon {
  font-size: 16px;
}

.comment-card-time {
  color: #8c8c8c;
  font-size: 13px;
}

.comment-card-dark .comment-card-time {
  color: #a0a0a0;
}

.comment-card-content {
  margin-bottom: 12px;
}

.comment-card-label {
  font-size: 12px;
  color: #8c8c8c;
  margin-bottom: 4px;
}

.comment-card-dark .comment-card-label {
  color: #a0a0a0;
}

.comment-card-text {
  font-size: 14px;
  color: #262626;
  line-height: 1.6;
  white-space: pre-wrap;
  word-break: break-all;
}

.comment-card-dark .comment-card-text {
  color: #e0e0e0;
}

.comment-card-footer {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding-top: 12px;
  border-top: 1px dashed #f0f0f0;
}

.comment-card-dark .comment-card-footer {
  border-top-color: #4b5965;
}

.comment-card-tag {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 10px;
  background: #f5f5f5;
  border-radius: 4px;
  font-size: 12px;
  max-width: 100%;
}

.comment-card-dark .comment-card-tag {
  background: #2a2a2a;
}

.comment-card-tag-label {
  color: #8c8c8c;
  flex-shrink: 0;
}

.comment-card-dark .comment-card-tag-label {
  color: #a0a0a0;
}

.comment-card-tag-value {
  color: #595959;
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.comment-card-dark .comment-card-tag-value {
  color: #c0c0c0;
}

/* 导入功能样式 */
.import-upload-area {
  border: 2px dashed #d9d9d9;
  border-radius: 8px;
  padding: 48px 24px;
  text-align: center;
  cursor: pointer;
  transition: all 0.3s;
  background: #fafafa;
}

.import-upload-area:hover {
  border-color: #40a9ff;
  background: #f0f5ff;
}

.import-upload-area-dark {
  border-color: #4b5965;
  background: #1f1f1f;
}

.import-upload-area-dark:hover {
  border-color: #4ea4f3;
  background: #2a2a2a;
}

.import-upload-content {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16px;
}

.import-upload-icon {
  font-size: 48px;
}

.import-upload-text {
  font-size: 16px;
  color: #262626;
}

.import-upload-area-dark .import-upload-text {
  color: #e0e0e0;
}

.import-upload-hint {
  font-size: 14px;
  color: #8c8c8c;
  margin-top: 8px;
}

.import-upload-area-dark .import-upload-hint {
  color: #a0a0a0;
}

.import-template-row {
  text-align: center;
  margin-top: -8px;
}

.import-preview-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
  font-weight: 600;
  color: #262626;
}

.import-preview-header-dark {
  color: #e0e0e0;
}

.import-preview-count {
  font-size: 14px;
  color: #8c8c8c;
  font-weight: normal;
}

.import-preview-table-wrapper {
  max-height: 300px;
  overflow: auto;
}

.import-preview-more {
  text-align: center;
  padding: 12px;
  color: #8c8c8c;
  font-size: 14px;
}

/* 新增表单样式 */
.add-form-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 16px;
}

@media (max-width: 768px) {
  .add-form-grid {
    grid-template-columns: 1fr;
  }
}

.add-form-grid :deep(.n-form-item) {
  margin-bottom: 0;
}

/* 弹窗选择样式 */
.popup-select-wrapper {
  display: flex;
  align-items: center;
  width: 100%;
}

.popup-input {
  flex: 1;
}

.popup-input :deep(.n-input__suffix) {
  padding-right: 4px;
}

.popup-levels {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.popup-levels :deep(.n-form-item) {
  margin-bottom: 0;
}

/* 懒加载级联选择样式 */
.popup-levels-hint {
  padding: 8px 0;
  font-size: 12px;
}

/* 调试按钮 light 模式适配 */
.debug-btn {
  --n-color: #e6a23c;
  --n-color-hover: #ebb563;
  --n-color-pressed: #cf9236;
  --n-text-color: #ffffff;
  --n-text-color-hover: #ffffff;
  --n-text-color-pressed: #ffffff;
  --n-border: 1px solid #e6a23c;
  --n-border-hover: 1px solid #ebb563;
  --n-border-pressed: 1px solid #cf9236;
}

.debug-btn:hover {
  --n-color: #ebb563;
}

.debug-btn:active {
  --n-color: #cf9236;
}

/* 深色主题下调试按钮样式保持原样 */
.system-dark .debug-btn {
  --n-color: rgba(230, 162, 60, 0.2);
  --n-color-hover: rgba(230, 162, 60, 0.3);
  --n-color-pressed: rgba(230, 162, 60, 0.35);
  --n-text-color: #e6a23c;
  --n-text-color-hover: #f0c78a;
  --n-text-color-pressed: #e6a23c;
  --n-border: 1px solid rgba(230, 162, 60, 0.5);
  --n-border-hover: 1px solid rgba(230, 162, 60, 0.7);
  --n-border-pressed: 1px solid rgba(230, 162, 60, 0.8);
}

/* 分片加载进度提示样式 */
.chunk-loading-progress {
  position: absolute;
  bottom: 50px;
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 16px;
  background: rgba(255, 255, 255, 0.95);
  border-radius: 20px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
  z-index: 100;
}

.chunk-loading-progress .progress-text {
  font-size: 13px;
  color: #666;
  white-space: nowrap;
}

/* 深色主题下的进度提示 */
.system-dark .chunk-loading-progress {
  background: rgba(45, 45, 48, 0.95);
}

.system-dark .chunk-loading-progress .progress-text {
  color: #ccc;
}
</style>
