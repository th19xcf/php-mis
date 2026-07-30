<script setup lang="ts">
import { ref, onMounted, computed } from 'vue';
import { AgGridVue } from 'ag-grid-vue3';
import { AG_GRID_LOCALE_CN } from '@ag-grid-community/locale';
import { AllCommunityModule, ModuleRegistry, themeAlpine, type GridApi } from 'ag-grid-community';
import { useDialog, useMessage } from 'naive-ui';
import { useThemeStore } from '@/store/modules/theme';
import { useContractV2Store } from '@/store/modules/contract-v2';
import ContractV2Form from './components/ContractV2Form.vue';
import ContractV2Approval from './components/ContractV2Approval.vue';
import ContractV2FlowTimeline from './components/ContractV2FlowTimeline.vue';
import OnlyOfficeEditor from './components/OnlyOfficeEditor.vue';

ModuleRegistry.registerModules([AllCommunityModule]);

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

// OnlyOffice 编辑器
const showEditorModal = ref(false);
const editorDocId = ref(0);
const editorDocName = ref('');

const searchKeyword = ref('');

const gridApi = ref<GridApi | null>(null);
const selectedContract = ref<Api.ContractV2.ContractListItem | null>(null);

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
    suppressQuickFilter: true,
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

function handleCreate() {
  formMode.value = 'create';
  showFormModal.value = true;
}

function handleEdit() {
  if (!selectedContract.value) {
    message.warning('请先选择一条合同记录');
    return;
  }
  formMode.value = 'edit';
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
  contractV2Store.loadContractList();
}

function handleOpenEditor(docId: number, docName: string) {
  editorDocId.value = docId;
  editorDocName.value = docName;
  showEditorModal.value = true;
}

function handleCloseEditor() {
  showEditorModal.value = false;
  editorDocId.value = 0;
  editorDocName.value = '';
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

onMounted(async () => {
  const savedWidth = localStorage.getItem('contract-v2-splitter-width');
  if (savedWidth) {
    const width = Number(savedWidth);
    if (!Number.isNaN(width) && width >= minLeftWidth && width <= maxLeftWidth) {
      leftWidth.value = width;
    }
  }
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
          :pagination-page-size-selector="[500, 1000, 2000]"
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
        <span class="text-lg font-600">合同详情</span>
        <NSpace v-if="selectedContract">
          <template v-for="btn in getActionButtons()" :key="btn.key">
            <NButton :type="btn.type as any" size="small" @click="handleAction(btn.key)">
              {{ btn.label }}
            </NButton>
          </template>
        </NSpace>
      </div>

      <div class="panel-content">
        <template v-if="currentContract">
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

          <NDivider>审批流程</NDivider>

          <ContractV2FlowTimeline v-if="currentContract.合同编号" :contract-no="currentContract.合同编号" />
        </template>

        <NEmpty v-else description="请选择左侧合同查看详情" class="py-20" />
      </div>
    </div>

    <!-- 合同表单（含上传附件、审批表、OnlyOffice 编辑入口） -->
    <ContractV2Form
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

    <!-- OnlyOffice 文档编辑器弹窗 -->
    <NModal
      v-model:show="showEditorModal"
      preset="card"
      :title="editorDocName || '文档编辑'"
      :style="{ width: '90%', maxWidth: '1200px', height: '85vh' }"
      :mask-closable="false"
      @after-leave="handleCloseEditor"
    >
      <div class="editor-modal-body">
        <OnlyOfficeEditor v-if="showEditorModal && editorDocId" :document-id="editorDocId" height="100%" />
      </div>
    </NModal>
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
  height: calc(85vh - 110px);
  min-height: 400px;
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
}
</style>
