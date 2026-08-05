import { ref, computed, type Ref, type ComputedRef } from 'vue';
import { themeAlpine, type GridApi } from 'ag-grid-community';
import { useThemeStore } from '@/store/modules/theme';

// ============ 类型定义 ============

/** 4 个 Tab 的标识 */
export type ListTabKey = 'list' | 'pending' | 'done' | 'my';

/** 后端返回的标准分页结构 */
export interface PagedResult<T = any> {
  list: T[];
  total: number;
  page?: number;
  pageSize?: number;
}

/** 通用 fetcher：接收查询参数，返回分页结果 */
export type ListFetcher<T = any> = (params: Record<string, any>) => Promise<{ data?: PagedResult<T> } | PagedResult<T>>;

/** 后端元数据列定义（与 def_query_column / view_function 对齐） */
export interface ServerColumnMeta {
  field: string;
  title: string;
  type?: string;
  width?: number;
  hidden?: boolean;
  sortable?: boolean;
  filterable?: boolean;
  canMerge?: boolean;
  hintCondition?: boolean;
  hintStyle?: string;
  errorCondition?: boolean;
  errorStyle?: string;
}

/** 条件面板的运算符 */
export type ConditionOperator = 'contains' | 'equals' | 'startsWith';

/** 已生效的筛选条件 */
export interface ActiveFilter {
  fieldKey: string;
  operator: ConditionOperator;
  value: string;
}

/** composable 选项 */
export interface UseConfigDrivenGridOptions<TItem = any> {
  /** 主列表 fetcher（对应 list Tab） */
  fetchList?: ListFetcher<TItem>;
  /** 待办 fetcher（对应 pending Tab） */
  fetchPending?: ListFetcher<TItem>;
  /** 已办 fetcher（对应 done Tab） */
  fetchDone?: ListFetcher<TItem>;
  /** 我发起的 fetcher（对应 my Tab） */
  fetchMy?: ListFetcher<TItem>;
  /** 主列表兜底列定义（无元数据时使用） */
  fallbackColumnDefs?: any[];
  /** 主列表的查询表单初始值 */
  initialSearchForm?: Record<string, any>;
  /** 默认每页条数 */
  defaultPageSize?: number;
  /** 是否自动在列前补序号列（默认 true） */
  prependSequenceColumn?: boolean;
  /** 主列表查询表单的 ref（外部受控）；不传则 composable 内部维护 */
  searchFormRef?: Ref<Record<string, any>>;
}

// ============ 公共常量与工厂 ============

/** 序号列定义（与原硬编码完全一致） */
export function createSequenceColumn(): any {
  return {
    field: 'rowIndex',
    headerName: '序号',
    width: 60,
    minWidth: 60,
    maxWidth: 60,
    resizable: false,
    sortable: false,
    filter: false,
    cellStyle: { textAlign: 'center', display: 'flex', alignItems: 'center', justifyContent: 'center' },
    valueGetter: (params: any) => (params.node ? params.node.rowIndex + 1 : 0)
  };
}

/** 默认列配置 */
export function createDefaultColDef() {
  return {
    sortable: true,
    resizable: true,
    filter: true
  };
}

/** 数值列类型定义（处理空值沉底 + 字符串数字） */
export function createNumericColumnType() {
  return {
    数值: {
      cellStyle: { textAlign: 'right' },
      filter: 'agNumberColumnFilter',
      comparator: (valueA: any, valueB: any) => {
        const numA = valueA === null || valueA === undefined || valueA === '' ? null : Number(valueA);
        const numB = valueB === null || valueB === undefined || valueB === '' ? null : Number(valueB);
        if (numA === null && numB === null) return 0;
        if (numA === null) return 1;
        if (numB === null) return -1;
        return numA - numB;
      }
    }
  };
}

/** 创建浅色 / 深色 / 计算属性 三件套 */
export function createGridThemes() {
  const themeStore = useThemeStore();
  const isDarkMode = computed(() => themeStore.darkMode);

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

  const gridTheme = computed(() => (isDarkMode.value ? darkGridTheme : lightGridTheme));

  return { isDarkMode, gridTheme, lightGridTheme, darkGridTheme };
}

// ============ 列定义工具 ============

/**
 * 解析样式字符串为 CSS 对象
 * 格式: "color:red,background-color:#f7acbc,font-weight:bold"
 * 与通用工作台 use-workbench-table-edit.ts 中 parseStyleString 保持一致
 */
export function parseStyleString(styleStr: string): Record<string, string> {
  if (!styleStr) return {};
  const styleObj: Record<string, string> = {};
  const items = styleStr.split(',');
  for (const item of items) {
    const [key, value] = item.split(':');
    if (key && value) {
      const camelKey = key.trim().replace(/-([a-z])/g, g => g[1].toUpperCase());
      styleObj[camelKey] = value.trim();
    }
  }
  return styleObj;
}

/**
 * 将后端 ColumnMeta 转换为 ag-grid ColDef
 * 参照通用工作台 use-workbench-table-edit.ts 第 103-242 行的转换逻辑：
 * - 数值列右对齐 + cellClass + agNumberColumnFilter + comparator（空值沉底）
 * - 提示/异常样式（行内字段 "提示^<field>" / "异常^<field>" 为 '1' 时应用）
 * - GUID 列隐藏
 * - 可合并列启用 spanRows
 * - 金额类列追加千分位 + 2 位小数格式化
 */
export function convertServerColumnToColDef(column: ServerColumnMeta): any {
  const isGuidColumn =
    String(column.field || '').trim().toUpperCase() === 'GUID' ||
    String(column.title || '').trim().toUpperCase() === 'GUID';

  // column.type 可能含前后空格，trim 后再比较
  const isNumericColumn = (column.type || '').trim() === '数值';
  const numericBaseStyle: Record<string, string> | null = isNumericColumn
    ? { textAlign: 'right', justifyContent: 'flex-end' }
    : null;

  const colWidth = typeof column.width === 'number' && column.width > 0 ? column.width : 120;
  const definition: any = {
    field: column.field,
    headerName: column.title,
    hide: column.hidden || isGuidColumn,
    sortable: column.sortable,
    filter: true,
    resizable: true,
    width: colWidth,
    minWidth: Math.min(colWidth, 100)
  };

  if (isNumericColumn) {
    definition.type = 'numericColumn';
    definition.cellClass = 'wb-numeric-cell';
    definition.filter = 'agNumberColumnFilter';
    definition.comparator = (valueA: any, valueB: any) => {
      const numA = valueA === null || valueA === undefined || valueA === '' ? null : Number(valueA);
      const numB = valueB === null || valueB === undefined || valueB === '' ? null : Number(valueB);
      if (numA === null && numB === null) return 0;
      if (numA === null) return 1;
      if (numB === null) return -1;
      return numA - numB;
    };
    // 金额类列保留千分位 + 2 位小数格式化（与原硬编码行为一致）
    if (column.field.includes('金额')) {
      definition.valueFormatter = (params: any) => {
        const val = Number(params.value);
        if (isNaN(val)) return params.value;
        return val.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      };
    }
  }

  if (column.canMerge) {
    definition.editable = false;
    definition.spanRows = (params: any) => {
      const { nodeA, nodeB } = params;
      if (!nodeA || !nodeB || !nodeA.data || !nodeB.data) return false;
      const normalize = (v: unknown) => (v === null || v === undefined || v === '' ? '' : v);
      return normalize(nodeA.data[column.field]) === normalize(nodeB.data[column.field]);
    };
  }

  // 提示/异常样式：行数据中 "提示^<field>" / "异常^<field>" 为 '1' 时触发
  definition.cellStyle = (params: any) => {
    const data = params.data || {};
    if (column.errorCondition) {
      const errorKey = `异常^${column.field}`;
      if (data[errorKey] === '1' || data[errorKey] === 1) {
        return { ...numericBaseStyle, ...parseStyleString(column.errorStyle || '') };
      }
    }
    if (column.hintCondition) {
      const hintKey = `提示^${column.field}`;
      if (data[hintKey] === '1' || data[hintKey] === 1) {
        return { ...numericBaseStyle, ...parseStyleString(column.hintStyle || '') };
      }
    }
    return numericBaseStyle;
  };

  return definition;
}

/**
 * 合并服务端列定义与兜底列定义
 * - 服务端为空 → 用 fallback
 * - 服务端无序号列 → 自动补一个
 */
export function mergeColumnDefs(
  serverCols: ServerColumnMeta[] | any[] | null | undefined,
  fallback: any[],
  prependSequence = true
): any[] {
  if (!serverCols || serverCols.length === 0) {
    return fallback;
  }
  const converted = serverCols.map((c: any) => convertServerColumnToColDef(c));
  if (prependSequence) {
    const hasSequence = converted.some(c => c.field === '序号' || c.field === 'rowIndex');
    if (!hasSequence) {
      converted.unshift(createSequenceColumn());
    }
  }
  return converted;
}

// ============ 主 composable ============

/**
 * 配置驱动的 AG-Grid 列表 composable
 *
 * 抽取自 workflow-manage 与 contract-v2 两个页面的公共逻辑：
 * 1. AG-Grid 主题与默认配置
 * 2. 列定义（支持服务端元数据驱动 + 兜底硬编码）
 * 3. 4-Tab 数据加载框架（list / pending / done / my）
 * 4. 分页状态与事件处理
 * 5. gridApi / 刷新 / Tab 切换 等通用方法
 *
 * 业务侧（动作按钮、详情面板、模态弹窗等）仍由各页面自行维护。
 */
export function useConfigDrivenGrid<TItem = any>(options: UseConfigDrivenGridOptions<TItem> = {}) {
  const {
    fetchList,
    fetchPending,
    fetchDone,
    fetchMy,
    fallbackColumnDefs = [],
    initialSearchForm = {},
    defaultPageSize = 20,
    prependSequenceColumn = true,
    searchFormRef
  } = options;

  // ── 主题与默认配置 ──
  const { isDarkMode, gridTheme } = createGridThemes();
  const defaultColDef = createDefaultColDef();
  const columnTypes = createNumericColumnType();

  // ── 列定义 ──
  const serverColumnDefs = ref<ServerColumnMeta[] | any[]>([]);
  const columnDefs: ComputedRef<any[]> = computed(() =>
    mergeColumnDefs(serverColumnDefs.value, fallbackColumnDefs, prependSequenceColumn)
  );

  /** 外部写入服务端列定义（通常来自 def_query_column / view_function 接口） */
  function setServerColumnDefs(cols: ServerColumnMeta[] | any[] | null | undefined) {
    serverColumnDefs.value = cols ?? [];
  }

  // ── 查询表单 ──
  const internalSearchForm = ref<Record<string, any>>({ ...initialSearchForm });
  const searchForm = searchFormRef ?? internalSearchForm;

  // ── Tab 状态 ──
  const activeTab = ref<ListTabKey>('list');
  const searchKeyword = ref('');

  // ── 4 套数据 + 分页 ──
  const loading = ref(false);

  const listData = ref<TItem[]>([]) as Ref<TItem[]>;
  const pendingData = ref<TItem[]>([]) as Ref<TItem[]>;
  const doneData = ref<TItem[]>([]) as Ref<TItem[]>;
  const myData = ref<TItem[]>([]) as Ref<TItem[]>;

  function createPagination(pageSize: number) {
    return ref({ page: 1, pageSize, total: 0 });
  }

  const listPagination = createPagination(defaultPageSize);
  const pendingPagination = createPagination(defaultPageSize);
  const donePagination = createPagination(defaultPageSize);
  const myPagination = createPagination(defaultPageSize);

  // ── gridApi ──
  const gridApi = ref<GridApi | null>(null);
  function onGridReady(params: { api: GridApi }) {
    gridApi.value = params.api;
  }

  // ── 通用响应解析 ──
  function extractPagedResult<T = TItem>(response: any): PagedResult<T> {
    const data = (response as any)?.data ?? response;
    if (data && Array.isArray(data.list)) {
      return { list: data.list, total: data.total || 0, page: data.page, pageSize: data.pageSize };
    }
    return { list: [], total: 0 };
  }

  // ── 4 个加载函数 ──

  async function loadList() {
    if (!fetchList) return;
    loading.value = true;
    try {
      const params = {
        ...searchForm.value,
        page: listPagination.value.page,
        pageSize: listPagination.value.pageSize
      };
      const result = await fetchList(params);
      const paged = extractPagedResult<TItem>(result);
      listData.value = paged.list;
      listPagination.value.total = paged.total;
    } finally {
      loading.value = false;
    }
  }

  async function loadPending() {
    if (!fetchPending) return;
    loading.value = true;
    try {
      const result = await fetchPending({
        page: pendingPagination.value.page,
        pageSize: pendingPagination.value.pageSize
      });
      const paged = extractPagedResult<TItem>(result);
      pendingData.value = paged.list;
      pendingPagination.value.total = paged.total;
    } finally {
      loading.value = false;
    }
  }

  async function loadDone() {
    if (!fetchDone) return;
    loading.value = true;
    try {
      const result = await fetchDone({
        page: donePagination.value.page,
        pageSize: donePagination.value.pageSize
      });
      const paged = extractPagedResult<TItem>(result);
      doneData.value = paged.list;
      donePagination.value.total = paged.total;
    } finally {
      loading.value = false;
    }
  }

  async function loadMy() {
    if (!fetchMy) return;
    loading.value = true;
    try {
      const result = await fetchMy({
        page: myPagination.value.page,
        pageSize: myPagination.value.pageSize
      });
      const paged = extractPagedResult<TItem>(result);
      myData.value = paged.list;
      myPagination.value.total = paged.total;
    } finally {
      loading.value = false;
    }
  }

  // ── Tab 切换 ──
  function handleTabChange(tab: ListTabKey) {
    activeTab.value = tab;
    if (tab === 'list') loadList();
    else if (tab === 'pending') loadPending();
    else if (tab === 'done') loadDone();
    else if (tab === 'my') loadMy();
  }

  // ── 分页事件 ──
  function makePageHandlers(pagination: Ref<{ page: number; pageSize: number; total: number }>, reload: () => Promise<void>) {
    return {
      handlePageChange(page: number) {
        pagination.value.page = page;
        reload();
      },
      handlePageSizeChange(pageSize: number) {
        pagination.value.pageSize = pageSize;
        pagination.value.page = 1;
        reload();
      }
    };
  }

  const listPageHandlers = makePageHandlers(listPagination, loadList);
  const pendingPageHandlers = makePageHandlers(pendingPagination, loadPending);
  const donePageHandlers = makePageHandlers(donePagination, loadDone);
  const myPageHandlers = makePageHandlers(myPagination, loadMy);

  // ── 刷新（清空选中 + 重新加载当前 Tab + 刷新 grid 单元格）──
  async function handleRefresh(onResetSelection?: () => void) {
    if (gridApi.value) {
      gridApi.value.deselectAll();
    }
    onResetSelection?.();
    if (activeTab.value === 'list') await loadList();
    else if (activeTab.value === 'pending') await loadPending();
    else if (activeTab.value === 'done') await loadDone();
    else if (activeTab.value === 'my') await loadMy();
    if (gridApi.value) {
      gridApi.value.refreshCells({ force: true });
    }
  }

  // ── 查询表单 ──
  function handleSearch() {
    listPagination.value.page = 1;
    loadList();
  }

  function handleResetSearch() {
    searchForm.value = { ...initialSearchForm };
    handleSearch();
  }

  return {
    // 主题
    isDarkMode,
    gridTheme,
    // 配置
    defaultColDef,
    columnTypes,
    // 列定义
    columnDefs,
    serverColumnDefs,
    setServerColumnDefs,
    // 状态
    activeTab,
    searchKeyword,
    searchForm,
    loading,
    // 数据
    listData,
    pendingData,
    doneData,
    myData,
    // 分页
    listPagination,
    pendingPagination,
    donePagination,
    myPagination,
    // gridApi
    gridApi,
    onGridReady,
    // 加载方法
    loadList,
    loadPending,
    loadDone,
    loadMy,
    // 事件
    handleTabChange,
    handleSearch,
    handleResetSearch,
    handleRefresh,
    // 分页事件（按 Tab 分别暴露）
    listPageHandlers,
    pendingPageHandlers,
    donePageHandlers,
    myPageHandlers
  };
}

// ============ 条件面板 composable（可选启用）============

export interface UseConditionPanelOptions {
  /** 字段下拉项来源（通常来自 def_query_column.filterable） */
  fieldOptions: ComputedRef<Array<{ label: string; value: string }>>;
  /** 应用筛选的回调 */
  onApply: (filter: ActiveFilter | null) => void | Promise<void>;
  /** 当前生效的筛选条件（用于回显） */
  activeFilters: ComputedRef<ActiveFilter[]>;
}

/**
 * 条件面板状态管理（与通用工作台 WorkbenchConditionDrawer 对齐）
 * contract-v2 已有此模式，抽取后便于后续其他模块复用
 */
export function useConditionPanel(options: UseConditionPanelOptions) {
  const conditionVisible = ref(false);
  const selectedField = ref('');
  const selectedOperator = ref<ConditionOperator>('contains');
  const selectedValue = ref('');

  const conditionOperatorOptions: Array<{ label: string; value: ConditionOperator }> = [
    { label: '包含', value: 'contains' },
    { label: '等于', value: 'equals' },
    { label: '前缀匹配', value: 'startsWith' }
  ];

  const hasActiveFilter = computed(() => options.activeFilters.value.length > 0);
  const activeFilterSummary = computed(() => {
    const filters = options.activeFilters.value;
    if (filters.length === 0) return '';
    return filters.map(f => `${f.fieldKey} ${f.operator} "${f.value}"`).join(' / ');
  });

  /** 打开面板时回显当前已生效的筛选条件（取第一个） */
  function openCondition() {
    const active = options.activeFilters.value[0];
    if (active) {
      selectedField.value = active.fieldKey;
      selectedOperator.value = active.operator || 'contains';
      selectedValue.value = active.value;
    }
    conditionVisible.value = true;
  }

  /** 应用筛选 */
  async function handleApplyCondition() {
    const field = selectedField.value;
    const operator = selectedOperator.value;
    const value = selectedValue.value.trim();
    if (field && value) {
      await options.onApply({ fieldKey: field, operator, value });
    } else {
      await options.onApply(null);
    }
    conditionVisible.value = false;
  }

  /** 清除筛选 */
  async function handleClearCondition() {
    selectedField.value = '';
    selectedOperator.value = 'contains';
    selectedValue.value = '';
    await options.onApply(null);
    conditionVisible.value = false;
  }

  return {
    conditionVisible,
    selectedField,
    selectedOperator,
    selectedValue,
    conditionOperatorOptions,
    hasActiveFilter,
    activeFilterSummary,
    openCondition,
    handleApplyCondition,
    handleClearCondition
  };
}
