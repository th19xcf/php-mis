<script setup lang="ts">
import { ref, onMounted, computed } from 'vue';
import { AgGridVue } from 'ag-grid-vue3';
import { AG_GRID_LOCALE_CN } from '@ag-grid-community/locale';
import { themeAlpine, type GridApi } from 'ag-grid-community';
import { useDialog, useMessage } from 'naive-ui';
import { useThemeStore } from '@/store/modules/theme';
import {
  fetchWorkflowDefinitionList,
  fetchWorkflowDefinitionDelete,
  fetchWorkflowDefinitionActivate,
  fetchWorkflowDefinitionDeactivate,
  fetchWorkflowDefinitionDetail,
  fetchWorkflowPendingTasks,
  fetchWorkflowDoneTasks,
  fetchWorkflowMyInstances,
  fetchWorkflowWithdraw
} from '@/service/api/workflow';
import WorkflowDefForm from './components/WorkflowDefForm.vue';
import WorkflowFlowTimeline from './components/WorkflowFlowTimeline.vue';

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

const dialog = useDialog();
const message = useMessage();

// 左右分栏(同 contract-v2)
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
    localStorage.setItem('workflow-manage-splitter-width', String(leftWidth.value));
  }

  document.addEventListener('mousemove', onMouseMove);
  document.addEventListener('mouseup', onMouseUp);
}

// Tab 切换(同 contract-v2 的 4 个 Tab)
const activeTab = ref<'list' | 'pending' | 'done' | 'my'>('list');

const showFormModal = ref(false);
const formMode = ref<'create' | 'edit'>('create');
const isEditMode = ref(false);
const inlineFormRef = ref<{ submit: () => void } | null>(null);

// 选中的流程定义 / 实例
const selectedDefinition = ref<any>(null);
const currentDefinition = ref<any>(null);
const currentInstanceId = ref(0);

// 列表数据
const definitionList = ref<any[]>([]);
const pendingTasks = ref<any[]>([]);
const doneTasks = ref<any[]>([]);
const myInstances = ref<any[]>([]);
const loading = ref(false);

// 统计卡片(同 contract-v2 的 stats-cards-inline)
const stats = ref({
  总数: 0,
  启用: 0,
  停用: 0,
  草稿: 0
});

// 筛选
const searchKeyword = ref('');
const searchForm = ref({
  workflowCode: '',
  workflowName: '',
  businessType: '',
  status: ''
});

const pagination = ref({
  page: 1,
  pageSize: 20,
  total: 0
});

const pendingPagination = ref({ page: 1, pageSize: 20, total: 0 });
const donePagination = ref({ page: 1, pageSize: 20, total: 0 });
const myPagination = ref({ page: 1, pageSize: 20, total: 0 });

const gridApi = ref<GridApi | null>(null);

const columnDefs: any[] = [
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
  { field: '流程编码', headerName: '流程编码', width: 150, minWidth: 120, filter: 'agTextColumnFilter' },
  { field: '流程名称', headerName: '流程名称', width: 200, minWidth: 150, filter: 'agTextColumnFilter' },
  { field: '业务类型', headerName: '业务类型', width: 100, minWidth: 80, filter: 'agTextColumnFilter' },
  { field: '版本号', headerName: '版本', width: 80, minWidth: 60, type: '数值', cellStyle: { textAlign: 'right' } },
  { field: '流程状态', headerName: '状态', width: 100, minWidth: 80, filter: 'agTextColumnFilter' },
  { field: '流程描述', headerName: '描述', width: 220, minWidth: 150, filter: 'agTextColumnFilter' },
  { field: '创建人', headerName: '创建人', width: 100, minWidth: 80, filter: 'agTextColumnFilter' },
  { field: '创建时间', headerName: '创建时间', width: 160, minWidth: 140, filter: 'agDateColumnFilter' }
];

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

const businessTypeOptions = [
  { label: '合同', value: 'CONTRACT' },
  { label: '员工', value: 'EMPLOYEE' },
  { label: '请假', value: 'LEAVE' }
];

const statusOptions = [
  { label: '草稿', value: 'DRAFT' },
  { label: '启用', value: 'ACTIVE' },
  { label: '停用', value: 'INACTIVE' }
];

const businessTypeTextMap: Record<string, string> = {
  CONTRACT: '合同',
  EMPLOYEE: '员工',
  LEAVE: '请假'
};

const statusTextMap: Record<string, string> = {
  DRAFT: '草稿',
  ACTIVE: '启用',
  INACTIVE: '停用'
};

const instanceStatusTextMap: Record<string, string> = {
  RUNNING: '运行中',
  COMPLETED: '已完成',
  TERMINATED: '已终止',
  SUSPENDED: '已挂起',
  PENDING_START: '待启动'
};

const taskStatusTextMap: Record<string, string> = {
  PENDING: '待处理',
  DONE: '已处理',
  WITHDRAWN: '已撤回',
  REJECTED: '已拒绝',
  SKIPPED: '已跳过'
};

function onGridReady(params: { api: GridApi }) {
  gridApi.value = params.api;
}

async function loadList() {
  loading.value = true;
  try {
    const result = await fetchWorkflowDefinitionList({
      ...searchForm.value,
      page: pagination.value.page,
      pageSize: pagination.value.pageSize
    });
    const data = (result as any)?.data || result;
    if (data && Array.isArray(data.list)) {
      definitionList.value = data.list;
      pagination.value.total = data.total || 0;
      updateStats(data.list);
    }
  } finally {
    loading.value = false;
  }
}

// 基于当前列表数据更新统计(简化处理,如需精确可单独请求统计接口)
function updateStats(list: any[]) {
  stats.value = {
    总数: pagination.value.total || list.length,
    启用: list.filter((item: any) => item.流程状态 === 'ACTIVE').length,
    停用: list.filter((item: any) => item.流程状态 === 'INACTIVE').length,
    草稿: list.filter((item: any) => item.流程状态 === 'DRAFT').length
  };
}

function handleSearch() {
  pagination.value.page = 1;
  loadList();
}

function handleReset() {
  searchForm.value = {
    workflowCode: '',
    workflowName: '',
    businessType: '',
    status: ''
  };
  handleSearch();
}

async function loadPendingTasks() {
  loading.value = true;
  try {
    const result = await fetchWorkflowPendingTasks({
      page: pendingPagination.value.page,
      pageSize: pendingPagination.value.pageSize
    });
    const data = (result as any)?.data || result;
    if (data && Array.isArray(data.list)) {
      pendingTasks.value = data.list;
      pendingPagination.value.total = data.total || 0;
    }
  } finally {
    loading.value = false;
  }
}

async function loadDoneTasks() {
  loading.value = true;
  try {
    const result = await fetchWorkflowDoneTasks({
      page: donePagination.value.page,
      pageSize: donePagination.value.pageSize
    });
    const data = (result as any)?.data || result;
    if (data && Array.isArray(data.list)) {
      doneTasks.value = data.list;
      donePagination.value.total = data.total || 0;
    }
  } finally {
    loading.value = false;
  }
}

async function loadMyInstances() {
  loading.value = true;
  try {
    const result = await fetchWorkflowMyInstances({
      page: myPagination.value.page,
      pageSize: myPagination.value.pageSize
    });
    const data = (result as any)?.data || result;
    if (data && Array.isArray(data.list)) {
      myInstances.value = data.list;
      myPagination.value.total = data.total || 0;
    }
  } finally {
    loading.value = false;
  }
}

function handleTabChange(tab: string) {
  activeTab.value = tab as any;
  if (tab === 'pending') {
    loadPendingTasks();
  } else if (tab === 'done') {
    loadDoneTasks();
  } else if (tab === 'my') {
    loadMyInstances();
  }
}

async function handleRefresh() {
  if (gridApi.value) {
    gridApi.value.deselectAll();
  }
  selectedDefinition.value = null;
  currentDefinition.value = null;
  currentInstanceId.value = 0;
  await loadList();
  if (gridApi.value) {
    gridApi.value.refreshCells({ force: true });
  }
  message.success('已刷新');
}

function handleCreate() {
  formMode.value = 'create';
  isEditMode.value = false;
  showFormModal.value = true;
}

async function handleEdit() {
  if (!selectedDefinition.value) {
    message.warning('请先选择一条流程定义');
    return;
  }
  // 加载完整详情(含节点/连线)
  try {
    const result = await fetchWorkflowDefinitionDetail(selectedDefinition.value.GUID);
    const data = (result as any)?.data || result;
    if (data) {
      currentDefinition.value = data;
    }
  } catch {
    // 详情加载失败时使用列表数据
    currentDefinition.value = { ...selectedDefinition.value };
  }
  formMode.value = 'edit';
  isEditMode.value = true;
}

function handleCancelEdit() {
  isEditMode.value = false;
  showFormModal.value = false;
}

function handleSubmitInline() {
  inlineFormRef.value?.submit();
}

function handleFormSuccess() {
  showFormModal.value = false;
  isEditMode.value = false;
  loadList();
}

function handleDelete() {
  if (!selectedDefinition.value) {
    message.warning('请先选择一条流程定义');
    return;
  }
  const def = selectedDefinition.value;
  dialog.warning({
    title: '确认删除',
    content: `确定要删除流程「${def.流程名称}」吗?`,
    positiveText: '确定',
    negativeText: '取消',
    onPositiveClick: async () => {
      await fetchWorkflowDefinitionDelete(def.GUID);
      message.success('删除成功');
      selectedDefinition.value = null;
      currentDefinition.value = null;
      loadList();
    }
  });
}

async function handleActivate() {
  if (!selectedDefinition.value) {
    message.warning('请先选择一条流程定义');
    return;
  }
  await fetchWorkflowDefinitionActivate(selectedDefinition.value.GUID);
  message.success('启用成功');
  loadList();
}

async function handleDeactivate() {
  if (!selectedDefinition.value) {
    message.warning('请先选择一条流程定义');
    return;
  }
  await fetchWorkflowDefinitionDeactivate(selectedDefinition.value.GUID);
  message.success('停用成功');
  loadList();
}

function onRowClicked(event: { data: any }) {
  if (event.data) {
    selectedDefinition.value = event.data;
    isEditMode.value = false;
    // 加载详情
    loadDefinitionDetail(event.data.GUID);
  }
}

async function loadDefinitionDetail(defId: number) {
  try {
    const result = await fetchWorkflowDefinitionDetail(defId);
    const data = (result as any)?.data || result;
    if (data) {
      currentDefinition.value = data;
    }
  } catch {
    currentDefinition.value = null;
  }
}

async function handleViewInstance(instanceId: number) {
  currentInstanceId.value = instanceId;
}

function handleWithdraw(instanceId: number) {
  dialog.warning({
    title: '确认撤回',
    content: '确定要撤回该流程实例吗?(仅当前节点未处理时可撤回)',
    positiveText: '确定',
    negativeText: '取消',
    onPositiveClick: async () => {
      try {
        await fetchWorkflowWithdraw(instanceId);
        message.success('撤回成功');
        loadMyInstances();
      } catch (e: any) {
        message.error(e?.message || '撤回失败');
      }
    }
  });
}

// 待办任务点击:查看流程实例时间线
function handlePendingTaskClick(task: any) {
  if (task.实例ID) {
    currentInstanceId.value = task.实例ID;
  }
}

function handlePageChange(page: number) {
  pagination.value.page = page;
  loadList();
}

function handlePageSizeChange(pageSize: number) {
  pagination.value.pageSize = pageSize;
  pagination.value.page = 1;
  loadList();
}

function getActionButtons() {
  if (!selectedDefinition.value) return [];
  const status = selectedDefinition.value.流程状态;
  const buttons: Array<{ label: string; key: string; type: any }> = [];
  if (status === 'DRAFT' || status === 'INACTIVE') {
    buttons.push({ label: '编辑', key: 'edit', type: 'primary' });
    buttons.push({ label: '启用', key: 'activate', type: 'success' });
    buttons.push({ label: '删除', key: 'delete', type: 'error' });
  } else if (status === 'ACTIVE') {
    buttons.push({ label: '编辑', key: 'edit', type: 'primary' });
    buttons.push({ label: '停用', key: 'deactivate', type: 'warning' });
  }
  return buttons;
}

async function handleAction(key: string) {
  switch (key) {
    case 'edit':
      await handleEdit();
      break;
    case 'delete':
      handleDelete();
      break;
    case 'activate':
      await handleActivate();
      break;
    case 'deactivate':
      await handleDeactivate();
      break;
  }
}

function getStatusType(status: string): 'default' | 'success' | 'warning' | 'error' | 'info' {
  const map: Record<string, 'default' | 'success' | 'warning' | 'error' | 'info'> = {
    DRAFT: 'default',
    ACTIVE: 'success',
    INACTIVE: 'warning'
  };
  return map[status] || 'default';
}

function getStatusText(status: string): string {
  return statusTextMap[status] || status;
}

function getBusinessTypeText(type: string): string {
  return businessTypeTextMap[type] || type;
}

function getInstanceStatusType(status: string): 'default' | 'success' | 'warning' | 'error' | 'info' {
  const map: Record<string, 'default' | 'success' | 'warning' | 'error' | 'info'> = {
    RUNNING: 'warning',
    COMPLETED: 'success',
    TERMINATED: 'error',
    SUSPENDED: 'info',
    PENDING_START: 'default'
  };
  return map[status] || 'default';
}

function getInstanceStatusText(status: string): string {
  return instanceStatusTextMap[status] || status;
}

function getTaskResultType(result?: string): 'default' | 'success' | 'warning' | 'error' | 'info' {
  if (!result) return 'default';
  return result === 'APPROVE' ? 'success' : 'error';
}

function getTaskResultText(result?: string): string {
  if (!result) return '-';
  return result === 'APPROVE' ? '同意' : '拒绝';
}

onMounted(async () => {
  const savedWidth = localStorage.getItem('workflow-manage-splitter-width');
  if (savedWidth) {
    const width = Number(savedWidth);
    if (!Number.isNaN(width) && width >= minLeftWidth && width <= maxLeftWidth) {
      leftWidth.value = width;
    }
  }
  await loadList();
});
</script>

<template>
  <div class="workflow-container" :class="{ 'system-dark': isDarkMode }">
    <div class="workflow-panel workflow-panel-left" :style="{ width: leftWidth + 'px' }">
      <div class="panel-header">
        <span class="text-lg font-600">工作流管理</span>
        <div class="stats-cards-inline">
          <div class="stat-item">
            <span class="stat-label">流程总数</span>
            <span class="stat-value">{{ stats.总数 }}</span>
          </div>
          <div class="stat-item">
            <span class="stat-label">启用</span>
            <span class="stat-value text-success">{{ stats.启用 }}</span>
          </div>
          <div class="stat-item">
            <span class="stat-label">草稿</span>
            <span class="stat-value text-warning">{{ stats.草稿 }}</span>
          </div>
        </div>
        <div class="header-actions">
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
            新建流程
          </NButton>
        </div>
      </div>

      <!-- Tab 切换 -->
      <div class="tab-bar">
        <NTabs v-model:value="activeTab" type="line" animated @update:value="handleTabChange">
          <NTabPane name="list" tab="全部流程" />
          <NTabPane name="pending" tab="待我审批" />
          <NTabPane name="done" tab="我已审批" />
          <NTabPane name="my" tab="我发起的" />
        </NTabs>
        <NInput
          v-if="activeTab === 'list'"
          v-model:value="searchKeyword"
          placeholder="搜索流程编码、流程名称..."
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
        <!-- 全部流程 Tab:ag-grid -->
        <AgGridVue
          v-if="activeTab === 'list'"
          :theme="gridTheme"
          class="workflow-grid"
          :row-data="definitionList"
          :column-defs="columnDefs"
          :default-col-def="defaultColDef"
          :column-types="columnTypes"
          :locale-text="AG_GRID_LOCALE_CN"
          :row-height="38"
          :header-height="40"
          :animate-rows="true"
          :pagination="true"
          :pagination-page-size="pagination.pageSize"
          :pagination-page-size-selector="[500, 1000, 2000]"
          :row-selection="{ mode: 'singleRow' }"
          :quick-filter-text="searchKeyword"
          @grid-ready="onGridReady"
          @row-clicked="onRowClicked"
        />

        <!-- 待我审批 Tab -->
        <div v-else-if="activeTab === 'pending'" class="task-list">
          <div
            v-for="task in pendingTasks"
            :key="task.任务ID"
            class="task-item"
            @click="handlePendingTaskClick(task)"
          >
            <div class="task-header">
              <span class="task-title">{{ task.业务标题 }}</span>
              <NTag size="small" type="warning">{{ task.节点名称 }}</NTag>
            </div>
            <div class="task-info">
              <span>发起人:{{ task.发起人姓名 }}</span>
              <span>发起时间:{{ task.创建时间 }}</span>
            </div>
          </div>
          <NEmpty v-if="pendingTasks.length === 0" description="暂无待办任务" class="py-20" />
        </div>

        <!-- 我已审批 Tab -->
        <div v-else-if="activeTab === 'done'" class="task-list">
          <div v-for="task in doneTasks" :key="task.任务ID" class="task-item done">
            <div class="task-header">
              <span class="task-title">{{ task.业务标题 }}</span>
              <NTag size="small" :type="getTaskResultType(task.处理结果)">
                {{ getTaskResultText(task.处理结果) }}
              </NTag>
            </div>
            <div class="task-info">
              <span>发起人:{{ task.发起人姓名 }}</span>
              <span>处理时间:{{ task.处理时间 }}</span>
            </div>
          </div>
          <NEmpty v-if="doneTasks.length === 0" description="暂无已办任务" class="py-20" />
        </div>

        <!-- 我发起的 Tab -->
        <div v-else-if="activeTab === 'my'" class="task-list">
          <div v-for="inst in myInstances" :key="inst.GUID" class="task-item">
            <div class="task-header">
              <span class="task-title">{{ inst.业务标题 }}</span>
              <NTag size="small" :type="getInstanceStatusType(inst.实例状态)">
                {{ getInstanceStatusText(inst.实例状态) }}
              </NTag>
            </div>
            <div class="task-info">
              <span>当前节点:{{ inst.当前节点编码 || '-' }}</span>
              <span>发起时间:{{ inst.发起时间 }}</span>
            </div>
            <div class="task-actions" v-if="inst.实例状态 === 'RUNNING'">
              <NButton size="tiny" type="warning" @click.stop="handleWithdraw(inst.GUID)">撤回</NButton>
              <NButton size="tiny" type="primary" @click.stop="handleViewInstance(inst.GUID)">查看流程</NButton>
            </div>
          </div>
          <NEmpty v-if="myInstances.length === 0" description="暂无发起的流程" class="py-20" />
        </div>
      </div>
    </div>

    <div class="resize-splitter" :class="{ 'is-resizing': isResizing }" @mousedown="startResize">
      <div class="resize-line" />
    </div>

    <div class="workflow-panel workflow-panel-right">
      <div class="panel-header">
        <span class="text-lg font-600">{{ isEditMode ? '编辑流程' : '流程详情' }}</span>
        <div class="header-actions" v-if="isEditMode">
          <NButton size="small" @click="handleCancelEdit">取消</NButton>
          <NButton type="primary" size="small" @click="handleSubmitInline">保存</NButton>
        </div>
        <div class="header-actions" v-else-if="selectedDefinition">
          <template v-for="btn in getActionButtons()" :key="btn.key">
            <NButton :type="btn.type" size="small" @click="handleAction(btn.key)">
              {{ btn.label }}
            </NButton>
          </template>
        </div>
      </div>

      <div class="panel-content">
        <!-- 编辑模式:内联表单 -->
        <template v-if="isEditMode && currentDefinition">
          <WorkflowDefForm
            ref="inlineFormRef"
            :visible="isEditMode"
            inline
            mode="edit"
            :definition="currentDefinition"
            @success="handleFormSuccess"
          />
        </template>

        <!-- 查看模式:只读详情 -->
        <template v-else-if="currentDefinition">
          <NDivider>基本信息</NDivider>

          <NDescriptions bordered :column="2" size="small">
            <NDescriptionsItem label="流程编码">{{ currentDefinition.流程编码 }}</NDescriptionsItem>
            <NDescriptionsItem label="流程状态">
              <NTag :type="getStatusType(currentDefinition.流程状态)" size="small">
                {{ getStatusText(currentDefinition.流程状态) }}
              </NTag>
            </NDescriptionsItem>
            <NDescriptionsItem label="流程名称" :span="2">{{ currentDefinition.流程名称 }}</NDescriptionsItem>
            <NDescriptionsItem label="业务类型">{{ getBusinessTypeText(currentDefinition.业务类型) }}</NDescriptionsItem>
            <NDescriptionsItem label="版本号">v{{ currentDefinition.版本号 }}</NDescriptionsItem>
            <NDescriptionsItem label="创建人">{{ currentDefinition.创建人 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="创建时间">{{ currentDefinition.创建时间 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="更新人">{{ currentDefinition.更新人 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="更新时间">{{ currentDefinition.更新时间 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="流程描述" :span="2">{{ currentDefinition.流程描述 || '-' }}</NDescriptionsItem>
          </NDescriptions>

          <!-- 流程节点配置 -->
          <NDivider>流程节点</NDivider>
          <div v-if="currentDefinition.nodes && currentDefinition.nodes.length" class="node-list">
            <div
              v-for="node in currentDefinition.nodes"
              :key="node.GUID"
              class="node-item"
            >
              <div class="node-header">
                <span class="node-name">{{ node.节点名称 }}</span>
                <NTag size="small" :type="node.节点类型 === 'START' ? 'success' : node.节点类型 === 'END' ? 'error' : 'info'">
                  {{ node.节点类型 }}
                </NTag>
              </div>
              <div class="node-info">
                <span>节点编码:{{ node.节点编码 }}</span>
                <span>排序:{{ node.排序 }}</span>
              </div>
            </div>
          </div>
          <NEmpty v-else description="暂无节点配置" size="small" class="py-4" />

          <!-- 流程连线配置 -->
          <NDivider v-if="currentDefinition.edges && currentDefinition.edges.length">流程连线</NDivider>
          <div v-if="currentDefinition.edges && currentDefinition.edges.length" class="edge-list">
            <div
              v-for="edge in currentDefinition.edges"
              :key="edge.GUID"
              class="edge-item"
            >
              <span class="edge-node">{{ edge.源节点编码 }}</span>
              <span class="edge-arrow">→</span>
              <span class="edge-node">{{ edge.目标节点编码 }}</span>
              <span v-if="edge.条件表达式" class="edge-condition">{{ edge.条件表达式 }}</span>
            </div>
          </div>

          <!-- 流程实例时间线(切换到待办/我发起的 Tab 时显示) -->
          <NDivider v-if="currentInstanceId">流程实例时间线</NDivider>
          <WorkflowFlowTimeline v-if="currentInstanceId" :instance-id="currentInstanceId" />
        </template>

        <NEmpty v-else description="请选择左侧流程查看详情" class="py-20" />
      </div>
    </div>

    <!-- 流程表单弹窗(仅新建模式使用弹窗) -->
    <WorkflowDefForm
      v-model:visible="showFormModal"
      :mode="formMode"
      :definition="currentDefinition"
      @success="handleFormSuccess"
    />
  </div>
</template>

<style lang="scss" scoped>
@use '@/styles/scss/ag-grid-shared' as *;

.workflow-container {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  overflow: hidden;
}

.workflow-panel {
  position: relative;
  display: flex;
  flex-direction: column;
  height: 100%;
  background: #fff;
  border-radius: 8px;
  border: 1px solid #e8e8e8;
  overflow: hidden;
}

.workflow-panel-left {
  flex-shrink: 0;
}

.workflow-panel-right {
  flex: 1;
}

.system-dark .workflow-panel {
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

        &.text-success {
          color: #52c41a;
        }

        &.text-warning {
          color: #faad14;
        }
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

.workflow-grid {
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

    .task-actions {
      display: flex;
      gap: 8px;
      margin-top: 8px;
    }
  }
}

// 节点列表
.node-list {
  display: flex;
  flex-direction: column;
  gap: 8px;

  .node-item {
    padding: 10px 12px;
    background: #fafafa;
    border-radius: 4px;
    border-left: 3px solid #1890ff;

    .node-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 6px;

      .node-name {
        font-size: 13px;
        font-weight: 500;
        color: #333;
      }
    }

    .node-info {
      display: flex;
      gap: 16px;
      font-size: 12px;
      color: #666;
    }
  }
}

// 连线列表
.edge-list {
  display: flex;
  flex-direction: column;
  gap: 6px;

  .edge-item {
    display: flex;
    align-items: center;
    padding: 8px 12px;
    background: #fafafa;
    border-radius: 4px;
    gap: 10px;
    font-size: 13px;

    .edge-node {
      color: #333;
      font-weight: 500;
    }

    .edge-arrow {
      color: #1890ff;
      font-weight: bold;
    }

    .edge-condition {
      margin-left: auto;
      padding: 2px 8px;
      background: #fff;
      border-radius: 3px;
      font-size: 12px;
      color: #666;
      max-width: 300px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
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

/* ============ ag-grid 共享样式 ============ */
@include ag-grid-base-layout('workflow-grid');
@include ag-grid-cell-borders('workflow-grid', #e8eef4, rgba(255, 255, 255, 0.06));
@include ag-grid-selection-column('workflow-grid');
@include ag-grid-checkbox-theme('workflow-grid');
@include ag-grid-cell-focus('workflow-grid');
@include ag-grid-checkbox-dark('workflow-grid');
@include ag-grid-base-dark('workflow-grid');
@include ag-grid-controls-dark('workflow-grid');

/* Light mode subtle zebra stripe */
:deep(.workflow-grid .ag-row-even) {
  background-color: rgba(24, 144, 255, 0.02) !important;
}

/* Light mode row-selected highlight */
:deep(.workflow-grid .ag-row-selected) {
  background-color: rgba(24, 144, 255, 0.08) !important;
}

:deep(.workflow-grid .ag-row-selected .ag-cell) {
  background-color: rgba(24, 144, 255, 0.08) !important;
}

:deep(.workflow-grid .ag-row-hover.ag-row-selected .ag-cell) {
  background-color: rgba(24, 144, 255, 0.12) !important;
}

/* Light mode header background */
:deep(.workflow-grid .ag-header-row) {
  background-color: rgba(248, 250, 252, 0.95) !important;
}

:deep(.workflow-grid .ag-header-cell) {
  background-color: rgba(248, 250, 252, 0.95) !important;
}

:deep(.workflow-grid .ag-empty-cell) {
  height: 100% !important;
}

/* Dark mode enhanced colors */
.system-dark {
  :deep(.workflow-grid .ag-header-row) {
    background-color: rgba(36, 44, 56, 0.95) !important;
  }

  :deep(.workflow-grid .ag-header-cell) {
    background-color: rgba(36, 44, 56, 0.95) !important;
    border-color: rgba(255, 255, 255, 0.06) !important;
  }

  :deep(.workflow-grid .ag-row-even) {
    background-color: rgba(255, 255, 255, 0.02) !important;
  }

  :deep(.workflow-grid .ag-row-selected) {
    background-color: rgba(100, 181, 246, 0.1) !important;
  }

  :deep(.workflow-grid .ag-row-selected .ag-cell) {
    background-color: rgba(100, 181, 246, 0.1) !important;
  }

  :deep(.workflow-grid .ag-row-hover.ag-row-selected .ag-cell) {
    background-color: rgba(100, 181, 246, 0.15) !important;
  }

  :deep(.workflow-grid .ag-header) {
    border-bottom-color: rgba(255, 255, 255, 0.08);
  }

  :deep(.workflow-grid .ag-row) {
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

  // 节点列表暗黑适配
  .node-item {
    background: rgba(255, 255, 255, 0.05);

    .node-header .node-name {
      color: #e0e0e0;
    }

    .node-info {
      color: #b0b0b0;
    }
  }

  // 连线列表暗黑适配
  .edge-item {
    background: rgba(255, 255, 255, 0.05);

    .edge-node {
      color: #e0e0e0;
    }

    .edge-condition {
      background: rgba(0, 0, 0, 0.2);
      color: #b0b0b0;
    }
  }
}
</style>
