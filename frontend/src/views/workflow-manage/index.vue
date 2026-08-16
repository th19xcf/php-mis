<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue';
import { AgGridVue } from 'ag-grid-vue3';
import { AG_GRID_LOCALE_CN } from '@ag-grid-community/locale';
import { useDialog } from 'naive-ui';
import {
  fetchWorkflowDefinitionList,
  fetchWorkflowDefinitionDelete,
  fetchWorkflowDefinitionActivate,
  fetchWorkflowDefinitionDeactivate,
  fetchWorkflowDefinitionDetail,
  fetchWorkflowPendingTasks,
  fetchWorkflowDoneTasks,
  fetchWorkflowMyInstances,
  fetchWorkflowWithdraw,
  fetchWorkflowNodeList,
  fetchWorkflowNodeDelete,
  fetchWorkflowNodeSort,
  fetchWorkflowEdgeList,
  fetchWorkflowEdgeDelete
} from '@/service/api/workflow';
import { useConfigDrivenGrid, useSplitter } from '@/hooks/business';
import { useMessageWithConsole } from '@/hooks/business/use-message-with-console';
import WorkflowDefForm from './components/WorkflowDefForm.vue';
import WorkflowNodeForm from './components/WorkflowNodeForm.vue';
import WorkflowEdgeForm from './components/WorkflowEdgeForm.vue';
import WorkflowFlowTimeline from './components/WorkflowFlowTimeline.vue';

const dialog = useDialog();
const message = useMessageWithConsole();

// 左右分栏（抽取为 useSplitter）
const { leftWidth, isResizing, startResize } = useSplitter({
  defaultWidth: 800,
  minWidth: 500,
  maxWidth: 1000,
  storageKey: 'workflow-manage-splitter-width'
});

const showFormModal = ref(false);
const formMode = ref<'create' | 'edit'>('create');
const isEditMode = ref(false);
const inlineFormRef = ref<{ submit: () => void } | null>(null);

// 选中的流程定义 / 实例
const selectedDefinition = ref<any>(null);
const currentDefinition = ref<any>(null);
const currentInstanceId = ref(0);

// 节点/连线管理状态
const showNodeForm = ref(false);
const nodeFormMode = ref<'create' | 'edit'>('create');
const editingNode = ref<any>(null);
const showEdgeForm = ref(false);
const edgeFormMode = ref<'create' | 'edit'>('create');
const editingEdge = ref<any>(null);
const nodesForEdge = ref<any[]>([]); // 连线表单的节点下拉数据

// 统计卡片（基于当前列表数据计算）
const stats = ref({
  总数: 0,
  启用: 0,
  停用: 0,
  草稿: 0
});

// 列表 composable：抽取自原重复的 4-Tab 数据加载/分页/gridApi/主题等公共逻辑
// 当前 workflow-manage 暂未接入 def_query_column 元数据驱动，仍使用 fallbackColumnDefs 兜底
const {
  // 主题
  isDarkMode,
  gridTheme,
  // 配置
  defaultColDef,
  columnTypes,
  // 列定义（由 serverColumnDefs + fallbackColumnDefs 合并；当前 serverColumnDefs 为空 → 直接用 fallback）
  columnDefs,
  // 状态
  activeTab,
  searchKeyword,
  searchForm,
  loading,
  // 数据
  listData: definitionList,
  pendingData: pendingTasks,
  doneData: doneTasks,
  myData: myInstances,
  // 分页
  listPagination: pagination,
  pendingPagination,
  donePagination,
  myPagination,
  // gridApi
  gridApi,
  onGridReady,
  // 加载方法
  loadList,
  loadPending: loadPendingTasks,
  loadDone: loadDoneTasks,
  loadMy: loadMyInstances,
  // 事件
  handleTabChange,
  handleRefresh: _handleRefresh
} = useConfigDrivenGrid<any>({
  fetchList: fetchWorkflowDefinitionList as any,
  fetchPending: fetchWorkflowPendingTasks as any,
  fetchDone: fetchWorkflowDoneTasks as any,
  fetchMy: fetchWorkflowMyInstances as any,
  fallbackColumnDefs: [
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
  ],
  initialSearchForm: {
    workflowCode: '',
    workflowName: '',
    businessType: '',
    status: ''
  },
  defaultPageSize: 200,
  // workflow-manage 的 fallbackColumnDefs 已自带序号列，无需再自动补
  prependSequenceColumn: false
});

// workflow-manage 特有：列表数据变化时同步更新统计卡片
// 用 watch 监听 definitionList，避免在 loadList / handleRefresh / Tab 切换等多处显式调用
watch(definitionList, (list) => updateStats(list));

function updateStats(list: any[]) {
  stats.value = {
    总数: pagination.value.total || list.length,
    启用: list.filter((item: any) => item.流程状态 === 'ACTIVE').length,
    停用: list.filter((item: any) => item.流程状态 === 'INACTIVE').length,
    草稿: list.filter((item: any) => item.流程状态 === 'DRAFT').length
  };
}

// 本地 handleRefresh：在 composable 通用刷新基础上清空选中项，并提示用户
async function handleRefresh() {
  await _handleRefresh(() => {
    selectedDefinition.value = null;
    currentDefinition.value = null;
    currentInstanceId.value = 0;
  });
  message.success('已刷新');
}

// 本地 handleSearch / handleReset：保留原命名（composable 提供 handleSearch / handleResetSearch，
// 这里改写为对 pagination.page=1 后调用 loadList，行为与原版一致）
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

function handlePageChange(page: number) {
  pagination.value.page = page;
  loadList();
}

function handlePageSizeChange(pageSize: number) {
  pagination.value.pageSize = pageSize;
  pagination.value.page = 1;
  loadList();
}

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

// ============ 节点管理 ============

function handleAddNode() {
  if (!currentDefinition.value) return;
  nodeFormMode.value = 'create';
  editingNode.value = null;
  showNodeForm.value = true;
}

function handleEditNode(node: any) {
  nodeFormMode.value = 'edit';
  editingNode.value = node;
  showNodeForm.value = true;
}

function handleNodeFormSuccess() {
  showNodeForm.value = false;
  if (currentDefinition.value) {
    refreshNodesAndEdges(currentDefinition.value.GUID);
  }
}

async function refreshNodesAndEdges(defId: number) {
  try {
    const [nodeRes, edgeRes] = await Promise.all([
      fetchWorkflowNodeList(defId),
      fetchWorkflowEdgeList(defId)
    ]);
    const nodeList = (nodeRes as any)?.data?.list || (nodeRes as any)?.list || [];
    const edgeList = (edgeRes as any)?.data?.list || (edgeRes as any)?.list || [];
    if (currentDefinition.value) {
      currentDefinition.value = {
        ...currentDefinition.value,
        nodes: nodeList,
        edges: edgeList
      };
    }
  } catch (e: any) {
    message.error(e?.message || '刷新节点/连线失败');
  }
}

function handleDeleteNode(node: any) {
  dialog.warning({
    title: '确认删除',
    content: `确定要删除节点「${node.节点名称}」吗?关联的连线也会一并删除。`,
    positiveText: '确定',
    negativeText: '取消',
    onPositiveClick: async () => {
      try {
        await fetchWorkflowNodeDelete(node.GUID);
        message.success('删除成功');
        if (currentDefinition.value) {
          refreshNodesAndEdges(currentDefinition.value.GUID);
        }
      } catch (e: any) {
        message.error(e?.message || '删除失败');
      }
    }
  });
}

async function handleMoveNode(node: any, direction: 'up' | 'down') {
  if (!currentDefinition.value?.nodes) return;
  const nodes = [...currentDefinition.value.nodes].sort(
    (a: any, b: any) => (Number(a.排序) || 0) - (Number(b.排序) || 0)
  );
  const idx = nodes.findIndex((n: any) => n.GUID === node.GUID);
  if (idx < 0) return;
  const swapIdx = direction === 'up' ? idx - 1 : idx + 1;
  if (swapIdx < 0 || swapIdx >= nodes.length) return;

  // 交换两节点位置
  [nodes[idx], nodes[swapIdx]] = [nodes[swapIdx], nodes[idx]];

  try {
    await fetchWorkflowNodeSort(nodes.map((n: any) => n.GUID));
    if (currentDefinition.value) {
      await refreshNodesAndEdges(currentDefinition.value.GUID);
    }
    message.success('排序已更新');
  } catch (e: any) {
    message.error(e?.message || '排序失败');
  }
}

// ============ 连线管理 ============

function handleAddEdge() {
  if (!currentDefinition.value) return;
  if (!currentDefinition.value.nodes || currentDefinition.value.nodes.length < 2) {
    message.warning('请先创建至少 2 个节点');
    return;
  }
  edgeFormMode.value = 'create';
  editingEdge.value = null;
  nodesForEdge.value = currentDefinition.value.nodes;
  showEdgeForm.value = true;
}

function handleEditEdge(edge: any) {
  edgeFormMode.value = 'edit';
  editingEdge.value = edge;
  nodesForEdge.value = currentDefinition.value?.nodes || [];
  showEdgeForm.value = true;
}

function handleEdgeFormSuccess() {
  showEdgeForm.value = false;
  if (currentDefinition.value) {
    refreshNodesAndEdges(currentDefinition.value.GUID);
  }
}

function handleDeleteEdge(edge: any) {
  dialog.warning({
    title: '确认删除',
    content: `确定要删除连线「${edge.源节点编码} → ${edge.目标节点编码}」吗?`,
    positiveText: '确定',
    negativeText: '取消',
    onPositiveClick: async () => {
      try {
        await fetchWorkflowEdgeDelete(edge.GUID);
        message.success('删除成功');
        if (currentDefinition.value) {
          refreshNodesAndEdges(currentDefinition.value.GUID);
        }
      } catch (e: any) {
        message.error(e?.message || '删除失败');
      }
    }
  });
}

// 节点类型展示辅助
const nodeTypeTextMap: Record<string, string> = {
  START: '开始',
  APPROVAL: '审批',
  CC: '抄送',
  END: '结束'
};

const approverTypeTextMap: Record<string, string> = {
  ROLE: '角色',
  DEPT: '部门',
  SUPERIOR: '上级',
  ASSIGN: '指定人',
  SPONSOR: '发起人'
};

const signModeTextMap: Record<string, string> = {
  OR: '或签',
  AND: '会签'
};

function getNodeTypeText(type: string): string {
  return nodeTypeTextMap[type] || type || '-';
}

function getApproverTypeText(type?: string): string {
  if (!type) return '-';
  return approverTypeTextMap[type] || type;
}

function getSignModeText(mode?: string): string {
  if (!mode) return '-';
  return signModeTextMap[mode] || mode;
}

function getApproverConfigPreview(node: any): string {
  if (!node.审批人类型) return '-';
  if (!node.审批人配置) return '(空)';
  const cfg = node.审批人配置;
  // 字符串形式
  if (typeof cfg === 'string') {
    return cfg.length > 40 ? cfg.slice(0, 40) + '...' : cfg;
  }
  try {
    const s = JSON.stringify(cfg);
    return s.length > 40 ? s.slice(0, 40) + '...' : s;
  } catch {
    return String(cfg);
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
  // splitter 宽度恢复已由 useSplitter 内部 onMounted 处理
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
          :pagination-page-size-selector="[200, 500, 1000]"
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
          <NDivider>
            <div class="section-header">
              <span>流程节点</span>
              <NButton size="tiny" type="primary" @click="handleAddNode">
                <template #icon><icon-mdi-plus /></template>
                新增节点
              </NButton>
            </div>
          </NDivider>

          <div v-if="currentDefinition.nodes && currentDefinition.nodes.length" class="node-list">
            <div
              v-for="(node, idx) in currentDefinition.nodes"
              :key="node.GUID"
              class="node-item"
            >
              <div class="node-header">
                <div class="node-title">
                  <NTag size="small" :type="node.节点类型 === 'START' ? 'success' : node.节点类型 === 'END' ? 'error' : node.节点类型 === 'CC' ? 'warning' : 'info'">
                    {{ getNodeTypeText(node.节点类型) }}
                  </NTag>
                  <span class="node-name">{{ node.节点名称 }}</span>
                  <span class="node-code">({{ node.节点编码 }})</span>
                </div>
                <div class="node-actions">
                  <NButton size="tiny" quaternary @click="handleMoveNode(node, 'up')" :disabled="idx === 0">
                    <icon-mdi-arrow-up />
                  </NButton>
                  <NButton size="tiny" quaternary @click="handleMoveNode(node, 'down')" :disabled="idx === currentDefinition.nodes.length - 1">
                    <icon-mdi-arrow-down />
                  </NButton>
                  <NButton size="tiny" quaternary type="primary" @click="handleEditNode(node)">编辑</NButton>
                  <NButton size="tiny" quaternary type="error" @click="handleDeleteNode(node)">删除</NButton>
                </div>
              </div>
              <div class="node-info">
                <span>审批人类型:<b>{{ getApproverTypeText(node.审批人类型) }}</b></span>
                <span>审批模式:<b>{{ getSignModeText(node.会签或签) }}</b></span>
                <span>超时:{{ node.超时天数 || 0 }}天 ({{ node.超时处理 }})</span>
              </div>
              <div class="node-config" v-if="node.审批人类型">
                <span class="config-label">审批人配置:</span>
                <code class="config-value">{{ getApproverConfigPreview(node) }}</code>
              </div>
            </div>
          </div>
          <NEmpty v-else description="暂无节点,请点击「新增节点」配置审批流程" size="small" class="py-4" />

          <!-- 流程连线配置 -->
          <NDivider>
            <div class="section-header">
              <span>流程连线</span>
              <NButton size="tiny" type="primary" @click="handleAddEdge">
                <template #icon><icon-mdi-plus /></template>
                新增连线
              </NButton>
            </div>
          </NDivider>

          <div v-if="currentDefinition.edges && currentDefinition.edges.length" class="edge-list">
            <div
              v-for="edge in currentDefinition.edges"
              :key="edge.GUID"
              class="edge-item"
            >
              <div class="edge-flow">
                <span class="edge-node">{{ edge.源节点编码 }}</span>
                <span class="edge-arrow">→</span>
                <span class="edge-node">{{ edge.目标节点编码 }}</span>
              </div>
              <div class="edge-condition-wrap" v-if="edge.条件表达式 || edge.条件描述">
                <NTag v-if="edge.条件表达式" size="small" type="info">条件:{{ edge.条件表达式 }}</NTag>
                <span v-if="edge.条件描述" class="edge-desc">{{ edge.条件描述 }}</span>
              </div>
              <div class="edge-actions">
                <NButton size="tiny" quaternary type="primary" @click="handleEditEdge(edge)">编辑</NButton>
                <NButton size="tiny" quaternary type="error" @click="handleDeleteEdge(edge)">删除</NButton>
              </div>
            </div>
          </div>
          <NEmpty v-else description="暂无连线,请点击「新增连线」连接节点" size="small" class="py-4" />

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

    <!-- 节点表单弹窗 -->
    <WorkflowNodeForm
      v-model:visible="showNodeForm"
      :mode="nodeFormMode"
      :def-id="currentDefinition?.GUID || 0"
      :business-type="currentDefinition?.业务类型 || ''"
      :node="editingNode"
      @success="handleNodeFormSuccess"
    />

    <!-- 连线表单弹窗 -->
    <WorkflowEdgeForm
      v-model:visible="showEdgeForm"
      :mode="edgeFormMode"
      :def-id="currentDefinition?.GUID || 0"
      :edge="editingEdge"
      :nodes="nodesForEdge"
      @success="handleEdgeFormSuccess"
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

// 区块标题(节点/连线区块)
.section-header {
  display: flex;
  align-items: center;
  gap: 12px;

  span {
    font-size: 14px;
    font-weight: 500;
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
      margin-bottom: 8px;

      .node-title {
        display: flex;
        align-items: center;
        gap: 8px;
      }

      .node-name {
        font-size: 14px;
        font-weight: 600;
        color: #333;
      }

      .node-code {
        font-size: 12px;
        color: #999;
        font-family: 'Consolas', 'Monaco', monospace;
      }

      .node-actions {
        display: flex;
        gap: 2px;
      }
    }

    .node-info {
      display: flex;
      gap: 16px;
      flex-wrap: wrap;
      font-size: 12px;
      color: #666;

      b {
        color: #1890ff;
        font-weight: 500;
        margin-left: 2px;
      }
    }

    .node-config {
      margin-top: 6px;
      padding: 6px 8px;
      background: #fff;
      border-radius: 3px;
      font-size: 12px;
      display: flex;
      align-items: center;
      gap: 6px;

      .config-label {
        color: #999;
      }

      .config-value {
        color: #d48806;
        font-family: 'Consolas', 'Monaco', monospace;
        word-break: break-all;
      }
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
    flex-wrap: wrap;
    align-items: center;
    padding: 8px 12px;
    background: #fafafa;
    border-radius: 4px;
    gap: 10px;
    font-size: 13px;

    .edge-flow {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .edge-node {
      color: #333;
      font-weight: 500;
      font-family: 'Consolas', 'Monaco', monospace;
    }

    .edge-arrow {
      color: #1890ff;
      font-weight: bold;
    }

    .edge-condition-wrap {
      display: flex;
      align-items: center;
      gap: 8px;

      .edge-desc {
        font-size: 12px;
        color: #666;
      }
    }

    .edge-actions {
      margin-left: auto;
      display: flex;
      gap: 2px;
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

    .node-header {
      .node-name {
        color: #e0e0e0;
      }

      .node-code {
        color: #888;
      }
    }

    .node-info {
      color: #b0b0b0;
    }

    .node-config {
      background: rgba(0, 0, 0, 0.2);

      .config-label {
        color: #888;
      }

      .config-value {
        color: #faad14;
      }
    }
  }

  // 连线列表暗黑适配
  .edge-item {
    background: rgba(255, 255, 255, 0.05);

    .edge-node {
      color: #e0e0e0;
    }

    .edge-condition-wrap .edge-desc {
      color: #b0b0b0;
    }
  }
}
</style>
