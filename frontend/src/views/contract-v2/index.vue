<script setup lang="ts">
import { ref, onMounted, computed } from 'vue';
import { AgGridVue } from 'ag-grid-vue3';
import { AG_GRID_LOCALE_CN } from '@ag-grid-community/locale';
import { themeAlpine, type GridApi } from 'ag-grid-community';
import { useDialog, useMessage } from 'naive-ui';
import { useThemeStore } from '@/store/modules/theme';
import { useContractV2Store } from '@/store/modules/contract-v2';
import { fetchContractV2DownloadDocument } from '@/service/api/contract-v2';
import ContractV2Form from './components/ContractV2Form.vue';
import ContractV2Approval from './components/ContractV2Approval.vue';
import ContractV2FlowTimeline from './components/ContractV2FlowTimeline.vue';
import OnlyOfficeEditor from './components/OnlyOfficeEditor.vue';

const themeStore = useThemeStore();
const isDarkMode = computed(() => themeStore.darkMode);

// 与 contract V1 / generic-query-workbench 一致的主题配置
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

const dialog = useDialog();
const message = useMessage();
const contractV2Store = useContractV2Store();

// 左右分栏（同 V1）
const leftWidth = ref(800);
const minLeftWidth = 500;
const maxLeftWidth = 1000;
const isResizing = ref(false);

function startResize(e: MouseEvent) {
  isResizing.value = true;
  document.body.style.cursor = 'col-resize';
  document.body.style.userSelect = 'none';

  const startX = e.clientX;
  const startWidth = leftWidth.value;

  function onMouseMove(moveEvent: MouseEvent) {
    if (!isResizing.value) return;
    const delta = moveEvent.clientX - startX;
    const newWidth = Math.max(minLeftWidth, Math.min(maxLeftWidth, startWidth + delta));
    leftWidth.value = newWidth;
  }

  function onMouseUp() {
    isResizing.value = false;
    document.body.style.cursor = '';
    document.body.style.userSelect = '';
    document.removeEventListener('mousemove', onMouseMove);
    document.removeEventListener('mouseup', onMouseUp);
    localStorage.setItem('contract-v2-splitter-width', String(leftWidth.value));
  }

  document.addEventListener('mousemove', onMouseMove);
  document.addEventListener('mouseup', onMouseUp);
}

const activeTab = ref<'list' | 'pending' | 'done' | 'my'>('list');
const showFormModal = ref(false);
const showApprovalModal = ref(false);
const formMode = ref<'create' | 'edit'>('create');
const isEditMode = ref(false);

// OnlyOffice 编辑器
const showEditorModal = ref(false);
const editorDocId = ref(0);
const editorDocName = ref('');

// 弹窗拖拽与最大化
const isEditorMaximized = ref(false);
const editorModalPos = ref({ left: 0, top: 0 });
const isDragging = ref(false);
const dragStart = ref({ x: 0, y: 0, left: 0, top: 0 });
const editorModalRef = ref<HTMLDivElement | null>(null);

function handleOpenEditor(docId: number, docName: string) {
  editorDocId.value = docId;
  editorDocName.value = docName;
  showEditorModal.value = true;
  isEditorMaximized.value = false;
  // 下一帧计算居中位置
  requestAnimationFrame(() => {
    if (editorModalRef.value) {
      const rect = editorModalRef.value.getBoundingClientRect();
      editorModalPos.value = {
        left: (window.innerWidth - rect.width) / 2,
        top: (window.innerHeight - rect.height) / 2
      };
    }
  });
}

function handleCloseEditor() {
  showEditorModal.value = false;
  editorDocId.value = 0;
  editorDocName.value = '';
  isEditorMaximized.value = false;
  isDragging.value = false;
}

function toggleMaximize() {
  isEditorMaximized.value = !isEditorMaximized.value;
  if (!isEditorMaximized.value) {
    // 还原时重新居中
    requestAnimationFrame(() => {
      if (editorModalRef.value) {
        const rect = editorModalRef.value.getBoundingClientRect();
        editorModalPos.value = {
          left: Math.max(0, (window.innerWidth - rect.width) / 2),
          top: Math.max(0, (window.innerHeight - rect.height) / 2)
        };
      }
    });
  }
}

function onEditorHeaderMouseDown(e: MouseEvent) {
  if (isEditorMaximized.value) return;
  // 只响应左键，且排除按钮点击
  const target = e.target as HTMLElement;
  if (target.closest('.editor-modal-btn')) return;

  isDragging.value = true;
  dragStart.value = {
    x: e.clientX,
    y: e.clientY,
    left: editorModalPos.value.left,
    top: editorModalPos.value.top
  };

  document.addEventListener('mousemove', onEditorMouseMove);
  document.addEventListener('mouseup', onEditorMouseUp);
}

function onEditorMouseMove(e: MouseEvent) {
  if (!isDragging.value) return;
  const dx = e.clientX - dragStart.value.x;
  const dy = e.clientY - dragStart.value.y;
  editorModalPos.value = {
    left: Math.max(0, dragStart.value.left + dx),
    top: Math.max(0, dragStart.value.top + dy)
  };
}

function onEditorMouseUp() {
  isDragging.value = false;
  document.removeEventListener('mousemove', onEditorMouseMove);
  document.removeEventListener('mouseup', onEditorMouseUp);
}

const searchKeyword = ref('');

// 元数据驱动的条件面板（与通用工作台 WorkbenchConditionDrawer 完全对齐）
// 后端 def_query_column.可筛选=1 的列会出现在条件面板字段下拉中
type ConditionOperator = 'contains' | 'equals' | 'startsWith';
const conditionVisible = ref(false);
const selectedField = ref('');
const selectedOperator = ref<ConditionOperator>('contains');
const selectedValue = ref('');

// 条件面板字段下拉项（label 显示列名，value 用 fieldKey）
const conditionFieldOptions = computed(() => {
  return (contractV2Store.conditions || [])
    .filter(item => item.filterable)
    .map(item => ({ label: item.label || item.fieldKey, value: item.fieldKey }));
});

// 条件面板操作符下拉项（与通用工作台 WorkbenchConditionDrawer 保持一致）
const conditionOperatorOptions: Array<{ label: string; value: ConditionOperator }> = [
  { label: '包含', value: 'contains' },
  { label: '等于', value: 'equals' },
  { label: '前缀匹配', value: 'startsWith' }
];

// 是否有生效的筛选条件（用于在工具栏显示徽标）
const hasActiveFilter = computed(() => contractV2Store.activeFilters.length > 0);
const activeFilterSummary = computed(() => {
  const filters = contractV2Store.activeFilters;
  if (filters.length === 0) return '';
  return filters.map(f => `${f.fieldKey} ${f.operator} "${f.value}"`).join(' / ');
});

const gridApi = ref<GridApi | null>(null);
const inlineFormRef = ref<{ submit: () => void } | null>(null);
const selectedContract = ref<Api.ContractV2.ContractListItem | null>(null);

// 合同 V2 在 def_function 中对应的功能编码
// 当 def_function/def_query_column 中已配置该编码的列定义时，前端使用元数据驱动；
// 否则回退到下方 fallbackColumnDefs 硬编码列定义（保证渐进迁移期间功能不中断）
const CONTRACT_V2_FUNCTION_CODE = 'contract_v2_list';

// 兜底列定义：当后端 def_function/def_query_column 未配置合同 V2 列定义时使用
const fallbackColumnDefs: any[] = [
  {
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
  },
  { field: '合同编号', headerName: '合同编号', width: 150, minWidth: 120, filter: 'agTextColumnFilter' },
  { field: '合同名称', headerName: '合同名称', width: 220, minWidth: 150, filter: 'agTextColumnFilter' },
  { field: '甲方名称', headerName: '甲方', width: 150, minWidth: 120, filter: 'agTextColumnFilter' },
  { field: '乙方名称', headerName: '乙方', width: 150, minWidth: 120, filter: 'agTextColumnFilter' },
  {
    field: '合同金额',
    headerName: '金额',
    width: 120,
    minWidth: 100,
    filter: 'agNumberColumnFilter',
    type: '数值',
    cellStyle: { textAlign: 'right' },
    comparator: (valueA: any, valueB: any) => Number(valueA) - Number(valueB),
    valueFormatter: (params: any) => {
      const val = Number(params.value);
      if (isNaN(val)) return params.value;
      return val.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
  },
  { field: '合同状态', headerName: '状态', width: 100, minWidth: 80, filter: 'agTextColumnFilter' },
  { field: '所属部门名称', headerName: '所属部门', width: 120, minWidth: 100, filter: 'agTextColumnFilter' },
  { field: '签订日期', headerName: '签订日期', width: 120, minWidth: 100, filter: 'agDateColumnFilter' },
  { field: '结束日期', headerName: '到期日期', width: 120, minWidth: 100, filter: 'agDateColumnFilter' }
];

/**
 * 解析样式字符串为 CSS 对象
 * 格式: "color:red,background-color:#f7acbc,font-weight:bold"
 * 与通用工作台 use-workbench-table-edit.ts 中 parseStyleString 保持一致
 */
function parseStyleString(styleStr: string): Record<string, string> {
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
 */
function convertServerColumnToColDef(column: Api.ContractV2.ColumnMeta): any {
  const isGuidColumn =
    String(column.field || '').trim().toUpperCase() === 'GUID' ||
    String(column.title || '').trim().toUpperCase() === 'GUID';

  // column.type 可能含前后空格，trim 后再比较
  const isNumericColumn = (column.type || '').trim() === '数值';
  const numericBaseStyle: Record<string, string> | null = isNumericColumn
    ? { textAlign: 'right', justifyContent: 'flex-end' }
    : null;

  const definition: any = {
    field: column.field,
    headerName: column.title,
    hide: column.hidden || isGuidColumn,
    sortable: column.sortable,
    filter: true,
    resizable: true,
    width: column.width > 0 ? column.width : 120,
    minWidth: Math.min(column.width > 0 ? column.width : 120, 100)
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

// 从 store 读取元数据驱动的列定义
const serverColumnDefs = computed(() => contractV2Store.columnDefs);

// 实际生效的列定义：优先使用元数据驱动，为空时回退到硬编码
const columnDefs = computed<any[]>(() => {
  const serverCols = serverColumnDefs.value;
  if (!serverCols || serverCols.length === 0) {
    return fallbackColumnDefs;
  }
  // 后端列定义不含序号列时，前端自动补一个序号列（与硬编码行为一致）
  const hasSequence = serverCols.some(c => c.field === '序号' || c.field === 'rowIndex');
  const converted = serverCols.map(convertServerColumnToColDef);
  if (!hasSequence) {
    converted.unshift({
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
    });
  }
  return converted;
});

const defaultColDef = {
  sortable: true,
  resizable: true,
  filter: true
};

const columnTypes = {
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

const contractList = computed(() => contractV2Store.contractList);
const pagination = computed(() => contractV2Store.pagination);
const loading = computed(() => contractV2Store.loading);
const currentContract = computed(() => contractV2Store.currentContract);
const stats = computed(() => contractV2Store.stats);
const options = computed(() => contractV2Store.options);

function onGridReady(params: { api: GridApi }) {
  gridApi.value = params.api;
}

function onRowClicked(event: { data: Api.ContractV2.ContractListItem }) {
  if (event.data) {
    selectedContract.value = event.data;
    contractV2Store.loadContractDetail(event.data.合同编号);
  }
}

async function handleRefresh() {
  if (gridApi.value) {
    gridApi.value.deselectAll();
  }
  selectedContract.value = null;
  contractV2Store.resetCurrentContract();
  await contractV2Store.loadContractList();
  if (gridApi.value) {
    gridApi.value.refreshCells({ force: true });
  }
  message.success('已刷新');
}

/** 打开条件面板（与通用工作台 WorkbenchToolbar 的"条件面板"按钮对齐） */
function openCondition() {
  // 打开时回显当前已生效的筛选条件（取第一个，因为面板只支持单条件）
  const active = contractV2Store.activeFilters[0];
  if (active) {
    selectedField.value = active.fieldKey;
    selectedOperator.value = (active.operator as ConditionOperator) || 'contains';
    selectedValue.value = active.value;
  }
  conditionVisible.value = true;
}

/** 应用条件面板筛选（与通用工作台 handleApplyCondition 对齐） */
async function handleApplyCondition() {
  const field = selectedField.value;
  const operator = selectedOperator.value;
  const value = selectedValue.value.trim();

  if (field && value) {
    contractV2Store.setActiveFilters([{ fieldKey: field, operator, value }]);
  } else {
    // 字段或值空时清除筛选
    contractV2Store.setActiveFilters([]);
  }
  conditionVisible.value = false;
  contractV2Store.setPage(1);
  await contractV2Store.loadContractList();
  message.success(field && value ? '已应用筛选条件' : '已清除筛选条件');
}

/** 清除条件面板筛选 */
async function handleClearCondition() {
  selectedField.value = '';
  selectedOperator.value = 'contains';
  selectedValue.value = '';
  contractV2Store.clearActiveFilters();
  conditionVisible.value = false;
  contractV2Store.setPage(1);
  await contractV2Store.loadContractList();
  message.success('已清除筛选条件');
}

function handleCreate() {
  formMode.value = 'create';
  isEditMode.value = false;
  showFormModal.value = true;
}

function handleEdit() {
  if (!selectedContract.value) {
    message.warning('请先选择一条合同记录');
    return;
  }
  formMode.value = 'edit';
  isEditMode.value = true;
  showFormModal.value = true;
}

function handleDelete() {
  if (!selectedContract.value) {
    message.warning('请先选择一条合同记录');
    return;
  }
  dialog.warning({
    title: '确认删除',
    content: `确定要删除合同「${selectedContract.value.合同名称}」吗？`,
    positiveText: '确定',
    negativeText: '取消',
    onPositiveClick: async () => {
      await contractV2Store.deleteContract(selectedContract.value!.合同编号);
      message.success('删除成功');
      selectedContract.value = null;
    }
  });
}

function handleSubmit() {
  if (!selectedContract.value) {
    message.warning('请先选择一条合同记录');
    return;
  }
  dialog.warning({
    title: '确认提交审批',
    content: `确定要提交合同「${selectedContract.value.合同名称}」进入审批流程吗？`,
    positiveText: '确定',
    negativeText: '取消',
    onPositiveClick: async () => {
      await contractV2Store.submitApproval(selectedContract.value!.合同编号);
      message.success('提交成功');
    }
  });
}

async function handlePageChange(page: number) {
  contractV2Store.setPage(page);
  await contractV2Store.loadContractList();
}

async function handlePageSizeChange(pageSize: number) {
  contractV2Store.setPageSize(pageSize);
  await contractV2Store.loadContractList();
}

function handleTabChange(tab: string) {
  activeTab.value = tab as any;
  if (tab === 'pending') {
    contractV2Store.loadPendingTasks();
  } else if (tab === 'done') {
    contractV2Store.loadDoneTasks();
  } else if (tab === 'my') {
    contractV2Store.loadMyContracts();
  }
}

function handleApproval(task: Api.Workflow.WorkflowTask) {
  selectedContract.value = {
    合同编号: task.业务ID,
    合同名称: task.业务标题
  } as any;
  contractV2Store.loadContractDetail(task.业务ID);
  showApprovalModal.value = true;
}

function handleFormSuccess() {
  showFormModal.value = false;
  isEditMode.value = false;
  contractV2Store.loadContractList();
}

function handleCancelEdit() {
  isEditMode.value = false;
  showFormModal.value = false;
}

function handleSubmitInline() {
  inlineFormRef.value?.submit();
}



function handleApprovalSuccess() {
  showApprovalModal.value = false;
  if (activeTab.value === 'pending') {
    contractV2Store.loadPendingTasks();
  }
}

function getActionButtons() {
  if (!selectedContract.value) return [];
  const status = selectedContract.value.合同状态;
  const buttons: Array<{ label: string; key: string; type: string }> = [];
  if (status === 'DRAFT' || status === 'REJECTED') {
    buttons.push({ label: '编辑', key: 'edit', type: 'primary' });
    buttons.push({ label: '删除', key: 'delete', type: 'error' });
    buttons.push({ label: '提交审批', key: 'submit', type: 'warning' });
  }
  if (status === 'PENDING' || status === 'APPROVING') {
    buttons.push({ label: '审核', key: 'approve', type: 'warning' });
  }
  return buttons;
}

async function handleAction(key: string) {
  switch (key) {
    case 'edit':
      handleEdit();
      break;
    case 'delete':
      handleDelete();
      break;
    case 'submit':
      handleSubmit();
      break;
    case 'approve':
      showApprovalModal.value = true;
      break;
  }
}

function getStatusType(status: string): 'default' | 'success' | 'warning' | 'error' | 'info' {
  const map: Record<string, 'default' | 'success' | 'warning' | 'error' | 'info'> = {
    DRAFT: 'default',
    REJECTED: 'error',
    PENDING: 'warning',
    APPROVING: 'warning',
    APPROVED: 'info',
    SIGNING: 'info',
    SIGNED: 'success',
    ARCHIVED: 'success'
  };
  return map[status] || 'default';
}

// ── 附件相关辅助方法 ──
const editableExts = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];

function isEditableDoc(doc: Api.ContractV2.ContractDocument): boolean {
  const ext = (doc.文档格式 || '').toLowerCase();
  return editableExts.includes(ext);
}

function formatFileSize(bytes: number): string {
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
  return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
}

async function handleExportFile(doc: Api.ContractV2.ContractDocument) {
  try {
    const { blob, filename } = await fetchContractV2DownloadDocument(doc.GUID);
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
  } catch (e: any) {
    message.error(e?.message || '下载失败');
  }
}

const contractFiles = computed(() => {
  return (currentContract.value?.documents || []).filter(d => d.文档类型 === 'MAIN');
});

const approvalFiles = computed(() => {
  return (currentContract.value?.documents || []).filter(d => d.文档类型 === 'APPROVAL_FORM');
});

onMounted(async () => {
  const savedWidth = localStorage.getItem('contract-v2-splitter-width');
  if (savedWidth) {
    const width = Number(savedWidth);
    if (!Number.isNaN(width) && width >= minLeftWidth && width <= maxLeftWidth) {
      leftWidth.value = width;
    }
  }
  // 加载元数据驱动的列定义与查询条件（def_function/def_query_config/def_query_column）
  // 失败或返回空时由前端 fallbackColumnDefs 兜底，不阻塞主流程
  contractV2Store.loadColumnDefs(CONTRACT_V2_FUNCTION_CODE);
  contractV2Store.loadConditions(CONTRACT_V2_FUNCTION_CODE);
  contractV2Store.loadOptions();
  contractV2Store.loadStats();
  await contractV2Store.loadContractList();
});
</script>

<template>
  <div class="contract-container" :class="{ 'system-dark': isDarkMode }">
    <div class="contract-panel contract-panel-left" :style="{ width: leftWidth + 'px' }">
      <div class="panel-header">
        <span class="text-lg font-600">合同列表</span>
        <div class="stats-cards-inline">
          <div class="stat-item">
            <span class="stat-label">合同总数</span>
            <span class="stat-value">{{ stats.总数 }}</span>
          </div>
          <div class="stat-item">
            <span class="stat-label">审批中</span>
            <span class="stat-value text-warning">{{ stats.审批中 }}</span>
          </div>
          <div class="stat-item">
            <span class="stat-label">即将到期</span>
            <span class="stat-value text-error">{{ stats.即将到期 }}</span>
          </div>
        </div>
        <div class="header-actions">
          <NButton size="small" :type="hasActiveFilter ? 'warning' : 'default'" @click="openCondition">
            <template #icon>
              <icon-mdi-filter-variant />
            </template>
            条件面板
          </NButton>
          <NButton size="small" @click="handleRefresh">
            <template #icon>
              <icon-mdi-refresh />
            </template>
            刷新
          </NButton>
          <NButton type="primary" size="small" @click="handleCreate">
            <template #icon>
              <icon-mdi-plus />
            </template>
            新建合同
          </NButton>
        </div>
      </div>

      <!-- 生效筛选条件提示条（与通用工作台筛选状态可见化对齐） -->
      <div v-if="hasActiveFilter" class="active-filter-bar">
        <icon-mdi-filter-check class="active-filter-icon" />
        <span class="active-filter-text">当前筛选：{{ activeFilterSummary }}</span>
        <NButton size="tiny" quaternary type="error" @click="handleClearCondition">清除</NButton>
      </div>

      <!-- Tab 切换 -->
      <div class="tab-bar">
        <NTabs v-model:value="activeTab" type="line" animated @update:value="handleTabChange">
          <NTabPane name="list" tab="全部合同" />
          <NTabPane name="pending" tab="待我审批" />
          <NTabPane name="done" tab="我已审批" />
          <NTabPane name="my" tab="我发起的" />
        </NTabs>
        <NInput
          v-model:value="searchKeyword"
          placeholder="搜索合同编号、合同名称、甲方、乙方..."
          clearable
          class="search-input"
        >
          <template #prefix>
            <icon-mdi-magnify />
          </template>
        </NInput>
      </div>

      <!-- 列表/任务区 -->
      <div class="grid-container">
        <AgGridVue
          v-if="activeTab === 'list'"
          :theme="gridTheme"
          class="contract-grid"
          :row-data="contractList"
          :column-defs="columnDefs"
          :default-col-def="defaultColDef"
          :column-types="columnTypes"
          :locale-text="AG_GRID_LOCALE_CN"
          :row-height="38"
          :header-height="40"
          :animate-rows="true"
          :pagination="true"
          :pagination-page-size="pagination.pageSize"
          :pagination-page-size-selector="[200, 500, 1000]"
          :row-selection="{ mode: 'singleRow' }"
          :quick-filter-text="searchKeyword"
          @grid-ready="onGridReady"
          @row-clicked="onRowClicked"
        />
        <div v-else-if="activeTab === 'pending'" class="task-list">
          <div
            v-for="task in contractV2Store.pendingTasks"
            :key="task.任务ID"
            class="task-item"
            @click="handleApproval(task)"
          >
            <div class="task-header">
              <span class="task-title">{{ task.业务标题 }}</span>
              <NTag size="small" type="warning">{{ task.节点名称 }}</NTag>
            </div>
            <div class="task-info">
              <span>发起人：{{ task.发起人姓名 }}</span>
              <span>发起时间：{{ task.创建时间 }}</span>
            </div>
          </div>
          <NEmpty v-if="contractV2Store.pendingTasks.length === 0" description="暂无待办任务" class="py-20" />
        </div>
        <div v-else-if="activeTab === 'done'" class="task-list">
          <div v-for="task in contractV2Store.doneTasks" :key="task.任务ID" class="task-item done">
            <div class="task-header">
              <span class="task-title">{{ task.业务标题 }}</span>
              <NTag size="small" :type="task.处理结果 === 'APPROVE' ? 'success' : 'error'">
                {{ task.处理结果 === 'APPROVE' ? '同意' : '拒绝' }}
              </NTag>
            </div>
            <div class="task-info">
              <span>发起人：{{ task.发起人姓名 }}</span>
              <span>处理时间：{{ task.处理时间 }}</span>
            </div>
          </div>
          <NEmpty v-if="contractV2Store.doneTasks.length === 0" description="暂无已办任务" class="py-20" />
        </div>
        <div v-else-if="activeTab === 'my'" class="task-list">
          <div v-for="inst in contractV2Store.myContracts" :key="inst.GUID" class="task-item">
            <div class="task-header">
              <span class="task-title">{{ inst.业务标题 }}</span>
              <NTag
                size="small"
                :type="inst.实例状态 === 'COMPLETED' ? 'success' : inst.实例状态 === 'TERMINATED' ? 'error' : 'info'"
              >
                {{ inst.实例状态 === 'RUNNING' ? '运行中' : inst.实例状态 === 'COMPLETED' ? '已完成' : '已终止' }}
              </NTag>
            </div>
            <div class="task-info">
              <span>当前节点：{{ inst.当前节点编码 }}</span>
              <span>发起时间：{{ inst.发起时间 }}</span>
            </div>
          </div>
          <NEmpty v-if="contractV2Store.myContracts.length === 0" description="暂无发起的流程" class="py-20" />
        </div>
      </div>
    </div>

    <div class="resize-splitter" :class="{ 'is-resizing': isResizing }" @mousedown="startResize">
      <div class="resize-line" />
    </div>

    <div class="contract-panel contract-panel-right">
      <div class="panel-header">
        <span class="text-lg font-600">{{ isEditMode ? '编辑合同' : '合同详情' }}</span>
        <div class="header-actions" v-if="isEditMode">
          <NButton size="small" @click="handleCancelEdit">取消</NButton>
          <NButton type="primary" size="small" @click="handleSubmitInline">保存</NButton>
        </div>
        <div class="header-actions" v-else-if="selectedContract">
          <template v-for="btn in getActionButtons()" :key="btn.key">
            <NButton :type="btn.type as any" size="small" @click="handleAction(btn.key)">
              {{ btn.label }}
            </NButton>
          </template>
        </div>
      </div>

      <div class="panel-content">
        <!-- 编辑模式：内联表单 -->
        <template v-if="isEditMode && currentContract">
          <ContractV2Form
            ref="inlineFormRef"
            :visible="isEditMode"
            inline
            mode="edit"
            :contract="currentContract"
            @success="handleFormSuccess"
            @open-editor="handleOpenEditor"
          />
        </template>

        <!-- 查看模式：只读详情 -->
        <template v-else-if="currentContract">
          <NDivider>基本信息</NDivider>

          <NDescriptions bordered :column="2" size="small">
            <NDescriptionsItem label="合同编号">{{ currentContract.合同编号 }}</NDescriptionsItem>
            <NDescriptionsItem label="合同状态">
              <NTag :type="getStatusType(currentContract.合同状态)" size="small">
                {{ currentContract.合同状态 }}
              </NTag>
            </NDescriptionsItem>
            <NDescriptionsItem label="合同名称" :span="2">{{ currentContract.合同名称 }}</NDescriptionsItem>
            <NDescriptionsItem label="甲方名称">{{ currentContract.甲方名称 }}</NDescriptionsItem>
            <NDescriptionsItem label="甲方联系人">{{ currentContract.甲方联系人 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="乙方名称">{{ currentContract.乙方名称 }}</NDescriptionsItem>
            <NDescriptionsItem label="乙方联系人">{{ currentContract.乙方联系人 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="合同金额">
              {{ currentContract.合同金额 ? `¥${Number(currentContract.合同金额).toLocaleString('zh-CN')}` : '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="付款方式">{{ currentContract.付款方式 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="签订日期">{{ currentContract.签订日期 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="到期日期">{{ currentContract.结束日期 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="所属部门">{{ currentContract.所属部门名称 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="创建人">{{ currentContract.创建人姓名 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="备注" :span="2">{{ currentContract.备注 || '-' }}</NDescriptionsItem>
          </NDescriptions>

          <!-- 合同文件 -->
          <NDivider>合同文件</NDivider>
          <div v-if="contractFiles.length" class="detail-file-list">
            <div v-for="file in contractFiles" :key="file.GUID" class="detail-file-item">
              <span class="detail-file-name">{{ file.文档名称 }}</span>
              <span class="detail-file-size">{{ formatFileSize(file.文件大小) }}</span>
              <div class="detail-file-actions">
                <NButton size="tiny" quaternary type="primary" @click="handleExportFile(file)">导出</NButton>
              </div>
            </div>
          </div>
          <NEmpty v-else description="暂无合同文件" size="small" class="py-4" />

          <!-- 合同审批表 -->
          <NDivider>合同审批表</NDivider>
          <div v-if="approvalFiles.length" class="detail-file-list">
            <div v-for="file in approvalFiles" :key="file.GUID" class="detail-file-item">
              <span class="detail-file-name">{{ file.文档名称 }}</span>
              <span class="detail-file-size">{{ formatFileSize(file.文件大小) }}</span>
              <div class="detail-file-actions">
                <NButton size="tiny" quaternary type="primary" @click="handleExportFile(file)">导出</NButton>
              </div>
            </div>
          </div>
          <NEmpty v-else description="暂无审批表" size="small" class="py-4" />

          <NDivider>审批流程</NDivider>

          <ContractV2FlowTimeline v-if="currentContract.合同编号" :contract-no="currentContract.合同编号" />
        </template>

        <NEmpty v-else description="请选择左侧合同查看详情" class="py-20" />
      </div>
    </div>

    <!-- 合同表单弹窗（仅新建模式使用弹窗） -->
    <ContractV2Form
      v-if="!isEditMode"
      v-model:visible="showFormModal"
      :mode="formMode"
      :contract="currentContract"
      @success="handleFormSuccess"
      @open-editor="handleOpenEditor"
    />

    <!-- 审批弹窗 -->
    <ContractV2Approval
      v-model:visible="showApprovalModal"
      :contract="currentContract"
      @success="handleApprovalSuccess"
    />

    <!-- OnlyOffice 文档编辑器弹窗（可拖拽、可最大化） -->
    <Teleport to="body">
      <div
        v-if="showEditorModal"
        class="editor-modal-mask"
        @click.self="handleCloseEditor"
      >
        <div
          ref="editorModalRef"
          class="editor-modal"
          :class="{ maximized: isEditorMaximized, dragging: isDragging }"
          :style="isEditorMaximized ? {} : { left: editorModalPos.left + 'px', top: editorModalPos.top + 'px' }"
        >
          <div class="editor-modal-header" @mousedown="onEditorHeaderMouseDown">
            <span class="editor-modal-title">{{ editorDocName || '文档编辑' }}</span>
            <div class="editor-modal-actions">
              <button class="editor-modal-btn" :title="isEditorMaximized ? '还原' : '最大化'" @click="toggleMaximize">
                <icon-mdi-window-maximize v-if="!isEditorMaximized" />
                <icon-mdi-window-restore v-else />
              </button>
              <button class="editor-modal-btn close" title="关闭" @click="handleCloseEditor">
                <icon-mdi-close />
              </button>
            </div>
          </div>
          <div class="editor-modal-body">
            <OnlyOfficeEditor v-if="showEditorModal && editorDocId" :document-id="editorDocId" height="100%" />
          </div>
        </div>
      </div>
    </Teleport>

    <!-- 条件面板 Drawer（与通用工作台 WorkbenchConditionDrawer 完全对齐） -->
    <NDrawer v-model:show="conditionVisible" :width="420" placement="right">
      <NDrawerContent title="条件面板" closable>
        <NSpace vertical :size="16">
          <NForm label-placement="top">
            <NFormItem label="字段">
              <NSelect
                v-model:value="selectedField"
                :options="conditionFieldOptions"
                placeholder="请选择筛选字段"
                clearable
              />
            </NFormItem>
            <NFormItem label="操作符">
              <NSelect
                v-model:value="selectedOperator"
                :options="conditionOperatorOptions"
              />
            </NFormItem>
            <NFormItem label="取值">
              <NInput
                v-model:value="selectedValue"
                placeholder="输入筛选值"
                clearable
              />
            </NFormItem>
          </NForm>

          <NAlert v-if="conditionFieldOptions.length === 0" type="info">
            未配置可筛选字段。请在 def_query_column 中将需要筛选的列「可筛选」设为 1。
          </NAlert>

          <NSpace justify="end">
            <NButton @click="handleClearCondition">清除</NButton>
            <NButton @click="conditionVisible = false">取消</NButton>
            <NButton type="primary" @click="handleApplyCondition">应用</NButton>
          </NSpace>
        </NSpace>
      </NDrawerContent>
    </NDrawer>
  </div>
</template>

<style lang="scss" scoped>
@use '@/styles/scss/ag-grid-shared' as *;

.contract-container {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  overflow: hidden;
}

.contract-panel {
  position: relative;
  display: flex;
  flex-direction: column;
  height: 100%;
  background: #fff;
  border-radius: 8px;
  border: 1px solid #e8e8e8;
  overflow: hidden;
}

.contract-panel-left {
  flex-shrink: 0;
}

.contract-panel-right {
  flex: 1;
}

.system-dark .contract-panel {
  background: rgb(24, 24, 28);
  border-color: rgba(255, 255, 255, 0.09);
}

.system-dark .panel-content {
  background: rgb(24, 24, 28);
}

.system-dark .panel-header {
  background: rgb(36, 36, 40);
  border-color: rgba(255, 255, 255, 0.09);
}

.system-dark .panel-header .stats-cards-inline {
  border-left-color: rgba(255, 255, 255, 0.15);
}

.system-dark .panel-header .stats-cards-inline .stat-item .stat-label {
  color: #b0b0b0;
}

.panel-header {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 10px 16px;
  border-bottom: 1px solid #e8e8e8;
  flex-shrink: 0;
  background: #fafafa;
  box-sizing: border-box;

  .stats-cards-inline {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-left: 8px;
    padding-left: 16px;
    border-left: 1px solid #e0e0e0;

    .stat-item {
      display: flex;
      flex-direction: column;
      align-items: center;
      line-height: 1.2;

      .stat-label {
        font-size: 12px;
        color: #999;
      }

      .stat-value {
        font-size: 18px;
        font-weight: 600;
        color: #1890ff;
      }
    }
  }

  .header-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-left: auto;
  }
}

/* 生效筛选条件提示条（与通用工作台筛选状态可见化对齐） */
.active-filter-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 16px;
  background: #fffbe6;
  border-bottom: 1px solid #ffe58f;
  font-size: 12px;
  color: #614700;
  flex-shrink: 0;

  .active-filter-icon {
    font-size: 16px;
    color: #faad14;
    flex-shrink: 0;
  }

  .active-filter-text {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}

.system-dark .active-filter-bar {
  background: rgba(255, 197, 23, 0.12);
  border-bottom-color: rgba(255, 197, 23, 0.25);
  color: #ffd666;
}

.panel-content {
  flex: 1;
  overflow-y: auto;
  padding: 16px;
  min-height: 0;
}

.tab-bar {
  padding: 0 16px;
  flex-shrink: 0;
  border-bottom: 1px solid #f0f0f0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;

  :deep(.n-tabs) {
    flex: 1;
  }

  :deep(.n-tabs-tab) {
    padding: 8px 0;
  }

  .search-input {
    width: 240px;
    flex-shrink: 0;
  }
}

.grid-container {
  flex: 1;
  min-height: 400px;
  height: 100%;
  overflow: auto;
}

.contract-grid {
  --wb-grid-surface: transparent;
  --wb-grid-text: #1f2937;
  width: 100%;
  height: 100%;

  .system-dark & {
    --wb-grid-surface: rgb(var(--container-bg-color));
    --wb-grid-text: rgb(var(--base-text-color));
  }
}

.task-list {
  height: 100%;
  overflow-y: auto;
  padding: 8px 16px;

  .task-item {
    padding: 12px 16px;
    border-bottom: 1px solid #f0f0f0;
    cursor: pointer;
    transition: background 0.2s;
    border-radius: 6px;
    margin-bottom: 4px;

    &:hover {
      background: #fafafa;
    }

    &.done {
      cursor: default;
    }

    .task-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 8px;

      .task-title {
        font-size: 15px;
        font-weight: 500;
      }
    }

    .task-info {
      display: flex;
      gap: 16px;
      font-size: 13px;
      color: #666;
    }
  }
}

// 查看模式附件列表
.detail-file-list {
  display: flex;
  flex-direction: column;
  gap: 6px;

  .detail-file-item {
    display: flex;
    align-items: center;
    padding: 6px 10px;
    background: #fafafa;
    border-radius: 4px;
    gap: 10px;

    .detail-file-name {
      flex: 1;
      color: #333;
      font-size: 13px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .detail-file-size {
      color: #999;
      font-size: 12px;
      flex-shrink: 0;
    }

    .detail-file-actions {
      display: inline-flex;
      align-items: center;
      flex-shrink: 0;
      margin-left: auto;

      :deep(.n-button) {
        padding: 0 4px !important;
        margin: 0 !important;
        min-width: 0 !important;
        height: 20px;
        font-size: 12px;
      }

      :deep(.n-button + .n-button) {
        margin-left: 2px !important;
      }
    }
  }
}

.resize-splitter {
  width: 8px;
  cursor: col-resize;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background-color 0.2s;
  flex-shrink: 0;
}

.resize-splitter:hover {
  background-color: rgba(0, 0, 0, 0.04);
}

.resize-splitter.is-resizing {
  background-color: rgba(0, 0, 0, 0.08);
}

.resize-line {
  width: 2px;
  height: 24px;
  border-radius: 1px;
  background-color: #d9d9d9;
}

.editor-modal-body {
  flex: 1;
  min-height: 0;
  overflow: hidden;
}

/* ============ OnlyOffice 编辑器弹窗（可拖拽、可最大化） ============ */
.editor-modal-mask {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.45);
  z-index: 2000;
  display: flex;
  align-items: center;
  justify-content: center;
}

.editor-modal {
  position: fixed;
  width: 90%;
  max-width: 1200px;
  height: 85vh;
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 4px 24px rgba(0, 0, 0, 0.15);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  z-index: 2001;

  &.maximized {
    left: 0 !important;
    top: 0 !important;
    width: 100vw !important;
    height: 100vh !important;
    max-width: none !important;
    border-radius: 0;
  }

  &.dragging {
    user-select: none;
    cursor: move;
    opacity: 0.95;
  }
}

.editor-modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  background: #fafafa;
  border-bottom: 1px solid #e8e8e8;
  flex-shrink: 0;
  cursor: grab;

  &:active {
    cursor: grabbing;
  }

  .editor-modal-title {
    font-size: 15px;
    font-weight: 500;
    color: #333;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    padding-right: 16px;
    flex: 1;
  }

  .editor-modal-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
  }

  .editor-modal-btn {
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: none;
    background: transparent;
    border-radius: 4px;
    cursor: pointer;
    color: #666;
    font-size: 16px;
    transition: all 0.2s;

    &:hover {
      background: #e8e8e8;
      color: #333;
    }

    &.close:hover {
      background: #ff4d4f;
      color: #fff;
    }
  }
}

/* 暗黑模式 */
.system-dark {
  .editor-modal-mask {
    background: rgba(0, 0, 0, 0.65);
  }

  .editor-modal {
    background: rgb(36, 36, 40);
    box-shadow: 0 4px 24px rgba(0, 0, 0, 0.5);
  }

  .editor-modal-header {
    background: rgb(44, 44, 50);
    border-color: rgba(255, 255, 255, 0.09);

    .editor-modal-title {
      color: #e0e0e0;
    }

    .editor-modal-btn {
      color: #999;

      &:hover {
        background: rgba(255, 255, 255, 0.1);
        color: #e0e0e0;
      }

      &.close:hover {
        background: #ff4d4f;
        color: #fff;
      }
    }
  }
}

/* ============ ag-grid 共享样式 ============ */
@include ag-grid-base-layout('contract-grid');
@include ag-grid-cell-borders('contract-grid', #e8eef4, rgba(255, 255, 255, 0.06));
@include ag-grid-selection-column('contract-grid');
@include ag-grid-checkbox-theme('contract-grid');
@include ag-grid-cell-focus('contract-grid');
@include ag-grid-checkbox-dark('contract-grid');
@include ag-grid-base-dark('contract-grid');
@include ag-grid-controls-dark('contract-grid');

/* Light mode subtle zebra stripe */
:deep(.contract-grid .ag-row-even) {
  background-color: rgba(24, 144, 255, 0.02) !important;
}

/* Light mode row-selected highlight */
:deep(.contract-grid .ag-row-selected) {
  background-color: rgba(24, 144, 255, 0.08) !important;
}

:deep(.contract-grid .ag-row-selected .ag-cell) {
  background-color: rgba(24, 144, 255, 0.08) !important;
}

:deep(.contract-grid .ag-row-hover.ag-row-selected .ag-cell) {
  background-color: rgba(24, 144, 255, 0.12) !important;
}

/* Light mode header background */
:deep(.contract-grid .ag-header-row) {
  background-color: rgba(248, 250, 252, 0.95) !important;
}

:deep(.contract-grid .ag-header-cell) {
  background-color: rgba(248, 250, 252, 0.95) !important;
}

:deep(.contract-grid .ag-empty-cell) {
  height: 100% !important;
}

/* Dark mode enhanced colors */
.system-dark {
  :deep(.contract-grid .ag-header-row) {
    background-color: rgba(36, 44, 56, 0.95) !important;
  }

  :deep(.contract-grid .ag-header-cell) {
    background-color: rgba(36, 44, 56, 0.95) !important;
    border-color: rgba(255, 255, 255, 0.06) !important;
  }

  :deep(.contract-grid .ag-row-even) {
    background-color: rgba(255, 255, 255, 0.02) !important;
  }

  :deep(.contract-grid .ag-row-selected) {
    background-color: rgba(100, 181, 246, 0.1) !important;
  }

  :deep(.contract-grid .ag-row-selected .ag-cell) {
    background-color: rgba(100, 181, 246, 0.1) !important;
  }

  :deep(.contract-grid .ag-row-hover.ag-row-selected .ag-cell) {
    background-color: rgba(100, 181, 246, 0.15) !important;
  }

  :deep(.contract-grid .ag-header) {
    border-bottom-color: rgba(255, 255, 255, 0.08);
  }

  :deep(.contract-grid .ag-row) {
    border-color: rgba(255, 255, 255, 0.06);
  }
}

// 暗黑模式 - 补充样式
.system-dark {
  .tab-bar {
    border-color: rgba(255, 255, 255, 0.09);
  }

  .task-list .task-item {
    border-color: rgba(255, 255, 255, 0.09);

    &:hover {
      background: rgba(255, 255, 255, 0.03);
    }
  }

  // 查看模式附件列表 - 暗黑适配
  .detail-file-item {
    background: rgba(255, 255, 255, 0.05);

    .detail-file-name {
      color: rgba(255, 255, 255, 0.85);
    }

    .detail-file-size {
      color: #888;
    }
  }

  // 编辑模式（ContractV2Form 内联）附件列表 - 暗黑适配（穿透子组件 scoped）
  :deep(.inline-form .file-item) {
    background: rgba(255, 255, 255, 0.05);

    .file-name {
      color: rgba(255, 255, 255, 0.85);
    }

    .file-size {
      color: #888;
    }
  }

  :deep(.inline-form .upload-tip) {
    color: #888;
    background: rgba(255, 255, 255, 0.05);
    border-color: rgba(255, 255, 255, 0.15);
  }
}
</style>
