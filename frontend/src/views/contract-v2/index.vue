<script setup lang="ts">
import { ref, onMounted, onActivated, computed, watch } from 'vue';
import { AgGridVue } from 'ag-grid-vue3';
import { AG_GRID_LOCALE_CN } from '@ag-grid-community/locale';
import { useDialog } from 'naive-ui';
import { useContractV2Store } from '@/store/modules/contract-v2';
import {
  fetchContractV2List,
  fetchContractV2PendingTasks,
  fetchContractV2DoneTasks,
  fetchContractV2MyContracts,
  fetchContractV2DownloadDocument
} from '@/service/api/contract-v2';
import { useConfigDrivenGrid, useSplitter, useConditionPanel } from '@/hooks/business';
import { fetchWorkbenchPage } from '@/service/api/workbench';
import { useMessageWithConsole } from '@/hooks/business/use-message-with-console';
import ContractV2Form from './components/ContractV2Form.vue';
import ContractV2Approval from './components/ContractV2Approval.vue';
import ContractV2FlowTimeline from './components/ContractV2FlowTimeline.vue';
import OnlyOfficeEditor from './components/OnlyOfficeEditor.vue';

const dialog = useDialog();
const message = useMessageWithConsole();
const contractV2Store = useContractV2Store();

// 左右分栏（抽取为 useSplitter）
const { leftWidth, isResizing, startResize } = useSplitter({
  defaultWidth: 800,
  minWidth: 500,
  maxWidth: 1000,
  storageKey: 'contract-v2-splitter-width'
});

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

const inlineFormRef = ref<{ submit: () => void } | null>(null);
const selectedContract = ref<Api.ContractV2.ContractListItem | null>(null);

// 合同 V2 在 def_function 中对应的功能编码
// 必须与后端 def_function.功能编码 完全一致（值为 'contract_v2'），
// 否则 fetchWorkbenchPage 拉不到 def_query_column 配置
const CONTRACT_V2_FUNCTION_CODE = 'contract_v2';

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

// 列表 composable：抽取自原重复的 4-Tab 数据加载/分页/gridApi/主题等公共逻辑
// 列定义支持服务端元数据驱动（def_query_column）+ 兜底硬编码
// 数据层走 fetchContractV2* 直连 API（不走 store），store 仅负责详情/CRUD/审批/stats/options
const {
  // 主题
  isDarkMode,
  gridTheme,
  // 配置
  defaultColDef,
  columnTypes,
  // 列定义（由 serverColumnDefs + fallbackColumnDefs 合并）
  columnDefs,
  serverColumnDefs,
  setServerColumnDefs,
  // 状态
  activeTab,
  searchKeyword,
  searchForm,
  loading,
  // 数据
  listData: contractList,
  pendingData: pendingTasks,
  doneData: doneTasks,
  myData: myContracts,
  // 分页（仅主列表分页需在模板/脚本中直接使用；pending/done/my 的分页由 composable 内部管理）
  listPagination: pagination,
  // gridApi
  gridApi,
  onGridReady,
  // 加载方法（loadPending 供 handleApprovalSuccess 调用；loadDone/loadMy 由 composable 内部的 handleTabChange 调用）
  loadList,
  loadPending,
  // 事件
  handleTabChange,
  handleRefresh: _handleRefresh
} = useConfigDrivenGrid<any>({
  fetchList: fetchContractV2List as any,
  fetchPending: fetchContractV2PendingTasks as any,
  fetchDone: fetchContractV2DoneTasks as any,
  fetchMy: fetchContractV2MyContracts as any,
  fallbackColumnDefs,
  initialSearchForm: {
    contractNo: '',
    contractName: '',
    contractType: '',
    contractStatus: '',
    partyA: '',
    partyB: ''
  },
  defaultPageSize: 500,
  // fallbackColumnDefs 已自带序号列，无需再自动补
  prependSequenceColumn: false
});

// 仍由 store 管理的状态（详情/统计/选项等，与列表无关）
const currentContract = computed(() => contractV2Store.currentContract);
const stats = computed(() => contractV2Store.stats);
const options = computed(() => contractV2Store.options);

// 条件面板字段下拉项：优先用服务端元数据中的可筛选列，否则用 fallbackColumnDefs
const conditionFieldOptions = computed(() => {
  const serverCols = serverColumnDefs.value as any[];
  if (serverCols && serverCols.length > 0) {
    return serverCols
      .filter((item: any) => item.filterable !== false && item.field !== 'rowIndex' && item.field !== 'GUID')
      .map((item: any) => ({ label: item.title || item.field, value: item.field }));
  }
  // 兜底：从 fallbackColumnDefs 中提取可筛选列
  return fallbackColumnDefs
    .filter(c => c.filter !== false && c.field !== 'rowIndex')
    .map(c => ({ label: c.headerName || c.field, value: c.field }));
});

// 本地生效的筛选条件（store 中相应方法尚未实现，由 composable 本地管理）
const activeFilters = ref<Array<{ fieldKey: string; operator: 'contains' | 'equals' | 'startsWith'; value: string }>>([]);
const activeFiltersComputed = computed(() => activeFilters.value);

const {
  conditionVisible,
  selectedField,
  selectedOperator,
  selectedValue,
  conditionOperatorOptions,
  hasActiveFilter,
  activeFilterSummary,
  openCondition,
  handleApplyCondition: _handleApplyCondition,
  handleClearCondition: _handleClearCondition
} = useConditionPanel({
  fieldOptions: conditionFieldOptions,
  activeFilters: activeFiltersComputed,
  async onApply(filter) {
    if (filter) {
      activeFilters.value = [filter];
      // 将条件写入 searchForm 以便 fetchList 携带
      searchForm.value = { ...searchForm.value, [filter.fieldKey]: filter.value };
    } else {
      // 清除时移除所有条件字段
      const cleared = { ...searchForm.value };
      for (const f of activeFilters.value) {
        delete cleared[f.fieldKey];
      }
      activeFilters.value = [];
      searchForm.value = cleared;
    }
    pagination.value.page = 1;
    await loadList();
  }
});

async function handleApplyCondition() {
  await _handleApplyCondition();
  message.success(selectedField.value && selectedValue.value.trim() ? '已应用筛选条件' : '已清除筛选条件');
}

async function handleClearCondition() {
  await _handleClearCondition();
  message.success('已清除筛选条件');
}

function onRowClicked(event: { data: Api.ContractV2.ContractListItem }) {
  if (event.data) {
    selectedContract.value = event.data;
    contractV2Store.loadContractDetail(event.data.合同编号);
  }
}

// 本地 handleRefresh：在 composable 通用刷新基础上清空选中项并重置 store 详情
async function handleRefresh() {
  await _handleRefresh(() => {
    selectedContract.value = null;
    contractV2Store.resetCurrentContract();
  });
  message.success('已刷新');
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
  pagination.value.page = page;
  await loadList();
}

async function handlePageSizeChange(pageSize: number) {
  pagination.value.pageSize = pageSize;
  pagination.value.page = 1;
  await loadList();
}

// handleTabChange 已由 composable 提供（调用 loadPending/loadDone/loadMy）

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
  loadList();
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
    loadPending();
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

// 列定义配置是否已加载（避免 KeepAlive 场景下重复请求）
const columnConfigLoaded = ref(false);
const columnConfigLoading = ref(false);

// 拉取 def_query_config + def_query_column 配置，启用元数据驱动列定义；
// 与 match-data 的 init() 主动调 fetchMatchPage 模式一致。
// 失败或返回空时由 fallbackColumnDefs 兜底，不阻塞主流程。
async function loadColumnConfig() {
  if (columnConfigLoaded.value || columnConfigLoading.value) return;
  columnConfigLoading.value = true;
  try {
    console.log('[ContractV2] 开始加载 def_query_column 配置, functionCode=', CONTRACT_V2_FUNCTION_CODE);
    const res = await fetchWorkbenchPage(CONTRACT_V2_FUNCTION_CODE);
    const columns = (res as any)?.data?.meta?.columns || [];
    console.log('[ContractV2] def_query_column 返回列数:', columns.length, columns);
    console.log('[ContractV2] 列字段名:', columns.map((c: any) => c.field));
    if (columns.length > 0) {
      setServerColumnDefs(columns);
      columnConfigLoaded.value = true;
      console.log('[ContractV2] 已调用 setServerColumnDefs, serverColumnDefs 当前值:', columns.length, '列');
    } else {
      console.warn('[ContractV2] def_query_column 未配置列定义，使用 fallbackColumnDefs 兜底');
    }
  } catch (e) {
    console.warn('[ContractV2] 加载 def_query_column 失败，使用 fallbackColumnDefs 兜底', e);
  } finally {
    columnConfigLoading.value = false;
  }
}

onMounted(async () => {
  // splitter 宽度恢复已由 useSplitter 内部 onMounted 处理
  await loadColumnConfig();
  contractV2Store.loadOptions();
  contractV2Store.loadStats();
  await loadList();
  // 诊断：打印数据字段名，与列定义 field 对比
  if (contractList.value.length > 0) {
    console.log('[ContractV2] 数据字段名:', Object.keys(contractList.value[0]));
    console.log('[ContractV2] 第一行数据:', contractList.value[0]);
  } else {
    console.warn('[ContractV2] loadList 返回空数据');
  }
});

// KeepAlive 场景：切回标签页时如果配置未加载（首次访问被缓存、代码更新后热替换等），
// onMounted 不会再次触发，用 onActivated 兜底
onActivated(async () => {
  await loadColumnConfig();
});

// AG-Grid 在某些情况下不会自动响应 columnDefs 的变化（尤其是异步加载后），
// 手动调用 gridApi.setGridOption('columnDefs', newDefs) 确保列定义更新生效
watch(columnDefs, (newDefs) => {
  if (gridApi.value && newDefs.length > 0) {
    console.log('[ContractV2] watch columnDefs 触发, 列数:', newDefs.length, '调用 gridApi.setGridOption');
    gridApi.value.setGridOption('columnDefs', newDefs);
    // 确认 AG-Grid 实际生效的列定义
    const actualCols = gridApi.value.getColumns();
    console.log('[ContractV2] AG-Grid 实际生效列数:', actualCols?.length, actualCols?.map((c: any) => c.getColDef().field));
  }
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
            v-for="task in pendingTasks"
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
          <NEmpty v-if="pendingTasks.length === 0" description="暂无待办任务" class="py-20" />
        </div>
        <div v-else-if="activeTab === 'done'" class="task-list">
          <div v-for="task in doneTasks" :key="task.任务ID" class="task-item done">
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
          <NEmpty v-if="doneTasks.length === 0" description="暂无已办任务" class="py-20" />
        </div>
        <div v-else-if="activeTab === 'my'" class="task-list">
          <div v-for="inst in myContracts" :key="inst.GUID" class="task-item">
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
          <NEmpty v-if="myContracts.length === 0" description="暂无发起的流程" class="py-20" />
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
