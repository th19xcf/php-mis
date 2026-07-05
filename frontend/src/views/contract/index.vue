<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue';
import { AgGridVue } from 'ag-grid-vue3';
import { AG_GRID_LOCALE_CN } from '@ag-grid-community/locale';
import { AllCommunityModule, ModuleRegistry, themeAlpine, type GridApi } from 'ag-grid-community';
import { useDialog, useMessage, useNotification } from 'naive-ui';
import { useThemeStore } from '@/store/modules/theme';
import { useContractStore } from '@/store/modules/contract';
import ContractForm from './components/ContractForm.vue';
import ContractApproval from './components/ContractApproval.vue';
import ContractSigning from './components/ContractSigning.vue';

ModuleRegistry.registerModules([AllCommunityModule]);

const themeStore = useThemeStore();
const isDarkMode = computed(() => themeStore.darkMode);

// 与 generic-query-workbench 完全一致的主题配置
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
const notification = useNotification();
const contractStore = useContractStore();

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
    localStorage.setItem('contract-splitter-width', String(leftWidth.value));
  }

  document.addEventListener('mousemove', onMouseMove);
  document.addEventListener('mouseup', onMouseUp);
}

const showFormModal = ref(false);
const showApprovalModal = ref(false);
const showSigningModal = ref(false);
const formMode = ref<'create' | 'edit'>('create');

const searchForm = ref({
  合同编号: '',
  合同名称: '',
  合同状态: '',
  合同类型: ''
});

const gridApi = ref<GridApi | null>(null);

const columnDefs = [
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
  { field: '合同名称', headerName: '合同名称', width: 200, minWidth: 150, filter: 'agTextColumnFilter' },
  { field: '甲方名称', headerName: '甲方', width: 150, minWidth: 120, filter: 'agTextColumnFilter' },
  { field: '乙方名称', headerName: '乙方', width: 150, minWidth: 120, filter: 'agTextColumnFilter' },
  { field: '合同金额', headerName: '金额', width: 120, minWidth: 100, filter: 'agNumberColumnFilter' },
  { field: '合同状态', headerName: '状态', width: 100, minWidth: 80, filter: 'agTextColumnFilter' },
  { field: '结束日期', headerName: '到期日期', width: 120, minWidth: 100, filter: 'agDateColumnFilter' }
];

const defaultColDef = {
  sortable: true,
  resizable: true,
  filter: true
};

const selectedRow = computed(() => contractStore.currentContract);
const contractFlow = computed(() => contractStore.contractFlow);
const pagination = computed(() => contractStore.pagination);
const stats = computed(() => contractStore.stats);

function onGridReady(params: { api: GridApi }) {
  gridApi.value = params.api;
}

function onRowClicked(event: { data: Api.Contract.ContractListItem }) {
  if (event.data) {
    contractStore.loadContractDetail(event.data.GUID);
    contractStore.loadContractFlow(event.data.GUID);
  }
}

function onSelectionChanged() {
  const selected = gridApi.value?.getSelectedRows();
  if (selected && selected.length > 0) {
    const guid = selected[0].GUID;
    try {
      contractStore.loadContractDetail(guid).then(() => {
        // loadContractDetail completed
      });
    } catch {
      // Error calling loadContractDetail
    }
    contractStore.loadContractFlow(guid);
  }
}

async function _handleSearch() {
  contractStore.setSearchParams(searchForm.value);
  contractStore.setPage(1);
  await contractStore.loadContractList();
}

function handleReset() {
  searchForm.value = {
    合同编号: '',
    合同名称: '',
    合同状态: '',
    合同类型: ''
  };
  contractStore.resetSearchParams();
  contractStore.setPage(1);

  if (gridApi.value) {
    gridApi.value.setFilterModel(null);
    gridApi.value.applyColumnState({
      state: columnDefs.map(col => ({
        colId: String(col.field),
        sort: null,
        pinned: null
      })),
      defaultState: { sort: null, pinned: null }
    });
  }

  contractStore.loadContractList();
  message.success('已重置到初始状态');
}

async function handleRefresh() {
  if (gridApi.value) {
    gridApi.value.deselectAll();
  }
  contractStore.clearCurrentContract();
  await contractStore.loadContractList();

  if (gridApi.value) {
    gridApi.value.refreshCells({ force: true });
  }

  message.success('已刷新');
}

function openCreateModal() {
  formMode.value = 'create';
  showFormModal.value = true;
}

function openEditModal() {
  if (!selectedRow.value) {
    message.warning('请先选择要编辑的合同');
    return;
  }
  if (selectedRow.value.合同状态 !== 'DRAFT' && selectedRow.value.合同状态 !== 'REJECTED') {
    message.warning('当前状态不允许编辑');
    return;
  }
  formMode.value = 'edit';
  showFormModal.value = true;
}

async function handleFormSubmit() {
  showFormModal.value = false;
  await contractStore.loadContractList();
  message.success(formMode.value === 'create' ? '创建合同成功' : '更新合同成功');
}

function handleDelete() {
  if (!selectedRow.value) {
    message.warning('请先选择要删除的合同');
    return;
  }
  if (selectedRow.value.合同状态 !== 'DRAFT' && selectedRow.value.合同状态 !== 'REJECTED') {
    message.warning('当前状态不允许删除');
    return;
  }

  dialog.warning({
    title: '确认删除',
    content: `确定要删除合同 "${selectedRow.value.合同名称}" 吗？`,
    positiveText: '确认',
    negativeText: '取消',
    onPositiveClick: async () => {
      const res = await contractStore.deleteContract(selectedRow.value!.GUID);
      if (res) {
        message.success('删除合同成功');
        contractStore.clearCurrentContract();
      }
    }
  });
}

function handleSubmit() {
  if (!selectedRow.value) {
    message.warning('请先选择要提交审核的合同');
    return;
  }
  if (selectedRow.value.合同状态 !== 'DRAFT' && selectedRow.value.合同状态 !== 'REJECTED') {
    message.warning('当前状态不允许提交审核');
    return;
  }

  dialog.warning({
    title: '确认提交',
    content: `确定要提交合同 "${selectedRow.value.合同名称}" 审核吗？`,
    positiveText: '确认',
    negativeText: '取消',
    onPositiveClick: async () => {
      const res = await contractStore.submitContract(selectedRow.value!.GUID);
      if (res) {
        message.success('提交审核成功');
        notification.success({
          content: '提交成功',
          duration: 3000
        });
      }
    }
  });
}

function openApprovalModal() {
  if (!selectedRow.value) {
    message.warning('请先选择要审核的合同');
    return;
  }
  if (selectedRow.value.合同状态 !== 'PENDING' && selectedRow.value.合同状态 !== 'APPROVING') {
    message.warning('当前状态不需要审核');
    return;
  }
  showApprovalModal.value = true;
}

async function handleApprovalSubmit() {
  showApprovalModal.value = false;
  await contractStore.loadContractList();
  message.success('审核操作成功');
}

function openSigningModal() {
  if (!selectedRow.value) {
    message.warning('请先选择要签署的合同');
    return;
  }
  if (selectedRow.value.合同状态 !== 'APPROVED' && selectedRow.value.合同状态 !== 'SIGNING') {
    message.warning('当前状态不允许签署');
    return;
  }
  showSigningModal.value = true;
}

async function handleSigningSubmit() {
  showSigningModal.value = false;
  await contractStore.loadContractList();
  message.success('签署成功');
}

function handleArchive() {
  if (!selectedRow.value) {
    message.warning('请先选择要归档的合同');
    return;
  }
  if (selectedRow.value.合同状态 !== 'SIGNED') {
    message.warning('当前状态不允许归档');
    return;
  }

  dialog.warning({
    title: '确认归档',
    content: `确定要归档合同 "${selectedRow.value.合同名称}" 吗？`,
    positiveText: '确认',
    negativeText: '取消',
    onPositiveClick: async () => {
      const res = await contractStore.archiveContract(selectedRow.value!.GUID);
      if (res) {
        message.success('归档成功');
      }
    }
  });
}

function getActionButtons() {
  if (!selectedRow.value) return [];

  const status = selectedRow.value.合同状态;
  const buttons = [];

  if (status === 'DRAFT' || status === 'REJECTED') {
    buttons.push({ label: '编辑', key: 'edit', type: 'primary' });
    buttons.push({ label: '删除', key: 'delete', type: 'error' });
    buttons.push({ label: '提交审核', key: 'submit', type: 'warning' });
  }

  if (status === 'PENDING' || status === 'APPROVING') {
    buttons.push({ label: '审核', key: 'approve', type: 'warning' });
  }

  if (status === 'APPROVED' || status === 'SIGNING') {
    buttons.push({ label: '签署', key: 'sign', type: 'success' });
  }

  if (status === 'SIGNED') {
    buttons.push({ label: '归档', key: 'archive', type: 'info' });
  }

  return buttons;
}

async function handleAction(key: string) {
  switch (key) {
    case 'edit':
      openEditModal();
      break;
    case 'delete':
      handleDelete();
      break;
    case 'submit':
      handleSubmit();
      break;
    case 'approve':
      openApprovalModal();
      break;
    case 'sign':
      openSigningModal();
      break;
    case 'archive':
      handleArchive();
      break;
  }
}

async function _handlePageChange(page: number) {
  contractStore.setPage(page);
  await contractStore.loadContractList();
}

async function _handlePageSizeChange(pageSize: number) {
  contractStore.setPageSize(pageSize);
  await contractStore.loadContractList();
}

onMounted(async () => {
  const savedWidth = localStorage.getItem('contract-splitter-width');
  if (savedWidth) {
    const width = Number(savedWidth);
    if (!Number.isNaN(width) && width >= minLeftWidth && width <= maxLeftWidth) {
      leftWidth.value = width;
    }
  }
  await Promise.all([
    contractStore.loadContractList(),
    contractStore.loadContractOptions(),
    contractStore.loadContractStats()
  ]);
});

watch(
  () => contractStore.contractList,
  () => {
    // Contract list changed
  },
  { immediate: true }
);
</script>

<template>
  <div class="contract-container" :class="{ 'system-dark': isDarkMode }">
    <div class="contract-panel contract-panel-left" :style="{ width: leftWidth + 'px' }">
      <div class="panel-header">
        <span class="text-lg font-600">合同列表</span>
        <NSpace>
          <NButton size="small" @click="handleRefresh">
            <template #icon>
              <icon-mdi-refresh />
            </template>
            刷新
          </NButton>
          <NButton size="small" @click="handleReset">重置</NButton>
          <NButton type="primary" size="small" @click="openCreateModal">
            <template #icon>
              <icon-mdi-plus />
            </template>
            新建合同
          </NButton>
        </NSpace>
      </div>

      <div class="grid-container">
        <AgGridVue
          :theme="gridTheme"
          class="contract-grid"
          :row-data="contractStore.contractList"
          :column-defs="columnDefs"
          :default-col-def="defaultColDef"
          :locale-text="AG_GRID_LOCALE_CN"
          :row-height="38"
          :header-height="40"
          :animate-rows="true"
          :pagination="true"
          :pagination-page-size="pagination.pageSize"
          :pagination-page-size-selector="[500, 1000, 2000]"
          :row-selection="{ mode: 'multiRow', checkboxes: true, headerCheckbox: true }"
          :selection-column-def="{
            width: 37,
            minWidth: 37,
            resizable: false,
            headerClass: 'selection-header-left',
            cellStyle: { display: 'flex', alignItems: 'center', justifyContent: 'center' },
            headerStyle: { display: 'flex', alignItems: 'center', justifyContent: 'center' }
          }"
          @grid-ready="onGridReady"
          @row-clicked="onRowClicked"
          @selection-changed="onSelectionChanged"
        />
      </div>
    </div>

    <div class="resize-splitter" :class="{ 'is-resizing': isResizing }" @mousedown="startResize">
      <div class="resize-line" />
    </div>

    <div class="contract-panel contract-panel-right">
      <div class="panel-header">
        <span class="text-lg font-600">合同详情</span>
        <NSpace v-if="selectedRow">
          <template v-for="btn in getActionButtons()" :key="btn.key">
            <NButton :type="btn.type as any" size="small" @click="handleAction(btn.key)">
              {{ btn.label }}
            </NButton>
          </template>
        </NSpace>
      </div>

      <div class="panel-content">
        <template v-if="selectedRow">
          <div class="stats-cards">
            <NCard size="small" class="stat-card">
              <div class="stat-label">合同总数</div>
              <div class="stat-value">{{ stats.总数 }}</div>
            </NCard>
            <NCard size="small" class="stat-card">
              <div class="stat-label">待审核</div>
              <div class="stat-value text-warning">{{ stats.待审核 }}</div>
            </NCard>
            <NCard size="small" class="stat-card">
              <div class="stat-label">已签署</div>
              <div class="stat-value text-success">{{ stats.已签署 }}</div>
            </NCard>
            <NCard size="small" class="stat-card">
              <div class="stat-label">即将到期</div>
              <div class="stat-value text-error">{{ stats.即将到期 }}</div>
            </NCard>
          </div>

          <NDivider>基本信息</NDivider>

          <NDescriptions bordered :column="2" size="small">
            <NDescriptionsItem label="合同编号">{{ selectedRow.合同编号 }}</NDescriptionsItem>
            <NDescriptionsItem label="合同名称" :span="2">{{ selectedRow.合同名称 }}</NDescriptionsItem>
            <NDescriptionsItem label="甲方名称">{{ selectedRow.甲方名称 }}</NDescriptionsItem>
            <NDescriptionsItem label="甲方联系人">{{ selectedRow.甲方联系人 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="乙方名称">{{ selectedRow.乙方名称 }}</NDescriptionsItem>
            <NDescriptionsItem label="乙方联系人">{{ selectedRow.乙方联系人 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="合同金额">
              {{ selectedRow.合同金额 ? `¥${selectedRow.合同金额.toLocaleString()}` : '-' }}
            </NDescriptionsItem>
            <NDescriptionsItem label="签订日期">{{ selectedRow.签订日期 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="开始日期">{{ selectedRow.开始日期 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="结束日期">{{ selectedRow.结束日期 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="付款方式">{{ selectedRow.付款方式 || '-' }}</NDescriptionsItem>
            <NDescriptionsItem label="合同状态" :span="2">
              <NTag :type="selectedRow.合同状态 === 'SIGNED' ? 'success' : 'default'" size="small">
                {{ selectedRow.合同状态 }}
              </NTag>
            </NDescriptionsItem>
            <NDescriptionsItem label="备注" :span="2">{{ selectedRow.备注 || '-' }}</NDescriptionsItem>
          </NDescriptions>

          <NDivider>审核历史</NDivider>

          <NTimeline v-if="contractFlow.length > 0">
            <NTimelineItem
              v-for="(flow, index) in contractFlow"
              :key="index"
              :type="flow.流程状态 === 'APPROVED' ? 'success' : flow.流程状态 === 'REJECTED' ? 'error' : 'info'"
              :title="flow.节点名称"
              :content="flow.审核意见 || ''"
              :time="flow.审核时间"
            >
              <template #header>
                <span>{{ flow.审核人姓名 || flow.审核人 }}</span>
              </template>
            </NTimelineItem>
          </NTimeline>
          <NEmpty v-else description="暂无审核记录" />
        </template>

        <NEmpty v-else description="请选择左侧合同查看详情" class="py-20" />
      </div>
    </div>

    <ContractForm v-model:show="showFormModal" :mode="formMode" @submit="handleFormSubmit" />

    <ContractApproval v-model:show="showApprovalModal" @submit="handleApprovalSubmit" />

    <ContractSigning v-model:show="showSigningModal" @submit="handleSigningSubmit" />
  </div>
</template>

<style scoped>
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

.panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  border-bottom: 1px solid #e8e8e8;
  flex-shrink: 0;
  background: #fafafa;
}

.panel-content {
  flex: 1;
  overflow-y: auto;
  padding: 16px;
  min-height: 0;
}

.grid-container {
  flex: 1;
  min-height: 400px;
  height: 100%;
}

.contract-grid {
  width: 100%;
  height: 100%;
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

.stats-cards {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
  margin-bottom: 16px;
}

.stat-card {
  text-align: center;
}

.stat-label {
  font-size: 12px;
  color: #666;
  margin-bottom: 4px;
}

.stat-value {
  font-size: 24px;
  font-weight: 600;
}

.text-warning {
  color: #faad14;
}

.text-success {
  color: #52c41a;
}

.text-error {
  color: #ff4d4f;
}

.system-dark .contract-panel {
  background: rgb(24, 24, 28);
  border-color: rgba(255, 255, 255, 0.09);
}

.system-dark .panel-header {
  background: rgb(36, 36, 40);
  border-color: rgba(255, 255, 255, 0.09);
}

.system-dark .panel-content {
  background: rgb(24, 24, 28);
}

/* Light mode grid styles - matching generic-query-workbench */
:deep(.contract-grid .ag-header-cell),
:deep(.contract-grid .ag-cell) {
  border-right: 1px dotted #c1ccc7 !important;
}

:deep(.contract-grid .ag-row),
:deep(.contract-grid .ag-header-row) {
  border-bottom: 1px dotted #c1ccc7 !important;
}

:deep(.contract-grid .ag-row-selected::before) {
  background-color: #b7d7f5 !important;
}

:deep(.contract-grid .ag-row-hover.ag-row-selected::before) {
  background-color: #c8e4ff !important;
  background-image: none !important;
}

:deep(.contract-grid .ag-cell-focus),
:deep(.contract-grid .ag-cell-range-selected) {
  border-right: 1px solid #2196f3 !important;
  border-left: 1px solid #2196f3 !important;
  border-top: 1px solid #2196f3 !important;
  border-bottom: 1px solid #2196f3 !important;
}

/* Dark mode grid styles - matching generic-query-workbench */
.system-dark :deep(.contract-grid .ag-root-wrapper),
.system-dark :deep(.contract-grid .ag-root),
.system-dark :deep(.contract-grid .ag-header),
.system-dark :deep(.contract-grid .ag-body),
.system-dark :deep(.contract-grid .ag-floating-bottom),
.system-dark :deep(.contract-grid .ag-floating-top) {
  background-color: rgb(var(--container-bg-color));
  color: rgb(var(--base-text-color));
}

.system-dark :deep(.contract-grid .ag-root-wrapper) {
  border-color: #2b3a49;
}

.system-dark :deep(.contract-grid .ag-header) {
  background-color: rgb(var(--container-bg-color));
  border-bottom-color: #2b3a49;
}

.system-dark :deep(.contract-grid .ag-row) {
  background-color: rgb(var(--container-bg-color));
  border-color: #2b3a49;
}

.system-dark :deep(.contract-grid .ag-row-even) {
  background-color: rgb(var(--container-bg-color));
}

.system-dark :deep(.contract-grid .ag-row-hover::before) {
  background-color: rgba(122, 167, 214, 0.18) !important;
}

/* 深色主题选中单元格的边框样式 */
.system-dark :deep(.contract-grid .ag-cell-focus),
.system-dark :deep(.contract-grid .ag-cell-range-selected) {
  border-right: 2px solid #64b5f6 !important;
  border-left: 2px solid #64b5f6 !important;
  border-top: 2px solid #64b5f6 !important;
  border-bottom: 2px solid #64b5f6 !important;
}

.system-dark :deep(.contract-grid .ag-cell),
.system-dark :deep(.contract-grid .ag-header-cell),
.system-dark :deep(.contract-grid .ag-header-cell-text),
.system-dark :deep(.contract-grid .ag-cell-value) {
  color: rgb(var(--base-text-color));
}

.system-dark :deep(.contract-grid .ag-header-cell .ag-icon),
.system-dark :deep(.contract-grid .ag-header-cell .ag-header-icon),
.system-dark :deep(.contract-grid .ag-header-cell-menu-button),
.system-dark :deep(.contract-grid .ag-header-cell-filter-button),
.system-dark :deep(.contract-grid .ag-header-cell-sortable .ag-sort-indicator-icon) {
  color: rgb(var(--base-text-color)) !important;
  opacity: 0.95;
}

.system-dark :deep(.contract-grid .ag-header-cell-menu-button:hover),
.system-dark :deep(.contract-grid .ag-header-cell-filter-button:hover),
.system-dark :deep(.contract-grid .ag-header-cell .ag-icon:hover) {
  color: #f3f8ff !important;
  opacity: 1;
}

.system-dark :deep(.contract-grid .ag-header-cell),
:deep(.contract-grid .ag-cell) {
  border-right: 1px dotted #4b5965 !important;
}

/* Checkbox styles - matching generic-query-workbench */
:deep(.contract-grid .ag-selection-checkbox),
:deep(.contract-grid .ag-checkbox-input-wrapper) {
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

:deep(.contract-grid .ag-selection-checkbox),
:deep(.contract-grid .ag-header-select-all) {
  width: 100%;
  height: 100%;
}

:deep(.contract-grid .ag-header-select-all) {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding-left: 0;
}

:deep(.contract-grid .selection-header-left .ag-header-cell-comp-wrapper) {
  width: 100%;
  justify-content: center !important;
  padding-left: 0 !important;
}

:deep(.contract-grid .selection-header-left .ag-header-select-all) {
  margin: 0 auto;
  width: auto !important;
}

:deep(.contract-grid .selection-header-left .ag-header-select-all .ag-selection-checkbox) {
  justify-content: center !important;
  width: auto !important;
  padding-left: 0 !important;
}

:deep(.contract-grid .selection-header-left .ag-header-cell-label) {
  width: 100% !important;
  justify-content: center !important;
  padding-left: 0 !important;
  gap: 0 !important;
}

:deep(.contract-grid .selection-header-left .ag-header-cell-label .ag-checkbox-input-wrapper) {
  margin-left: 0 !important;
  margin-right: 0 !important;
}

/* Final override: target AG Grid selection column directly. */
:deep(.contract-grid .ag-header-cell[col-id='ag-Grid-SelectionColumn']) {
  position: relative;
  padding-left: 0 !important;
  padding-right: 0 !important;
}

:deep(.contract-grid .ag-header-cell[col-id='ag-Grid-SelectionColumn'] .ag-header-cell-comp-wrapper) {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
}

:deep(.contract-grid .ag-header-cell[col-id='ag-Grid-SelectionColumn'] .ag-header-cell-label) {
  justify-content: center !important;
  padding-left: 0 !important;
}

:deep(.contract-grid .ag-header-cell[col-id='ag-Grid-SelectionColumn'] .ag-header-select-all) {
  position: absolute !important;
  left: 50% !important;
  top: 50% !important;
  transform: translate(-50%, -50%) !important;
  width: 16px !important;
  height: 16px !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  padding: 0 !important;
  margin: 0 !important;
}

:deep(.contract-grid .ag-header-cell[col-id='ag-Grid-SelectionColumn'] .ag-selection-checkbox),
:deep(.contract-grid .ag-header-cell[col-id='ag-Grid-SelectionColumn'] .ag-checkbox-input-wrapper) {
  margin: 0 !important;
  padding: 0 !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  position: absolute !important;
  left: 50% !important;
  top: 50% !important;
  transform: translate(-50%, -50%) !important;
}

:deep(.contract-grid .ag-cell[col-id='ag-Grid-SelectionColumn']) {
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  padding-left: 0 !important;
  padding-right: 0 !important;
}

:deep(.contract-grid .ag-cell[col-id='ag-Grid-SelectionColumn'] .ag-cell-wrapper) {
  display: flex;
  align-items: center;
  justify-content: center;
}

:deep(.contract-grid .ag-cell[col-id='ag-Grid-SelectionColumn'] .ag-selection-checkbox),
:deep(.contract-grid .ag-cell[col-id='ag-Grid-SelectionColumn'] .ag-checkbox-input-wrapper) {
  position: relative;
  width: 16px;
  height: 16px;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
}

/* Force checkbox wrapper to center */
:deep(.contract-grid .ag-cell[col-id='ag-Grid-SelectionColumn'] .ag-cell-wrapper .ag-selection-checkbox) {
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

/* Header checkbox centering */
:deep(.contract-grid .ag-header-cell[col-id='ag-Grid-SelectionColumn'] .ag-selection-checkbox) {
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  margin-left: auto !important;
  margin-right: auto !important;
}

:deep(
  .contract-grid .ag-header-cell[col-id='ag-Grid-SelectionColumn'] .ag-header-cell-comp-wrapper .ag-header-select-all
) {
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  width: 100% !important;
  height: 100% !important;
}

/* Checkbox appearance - matching generic-query-workbench */
:deep(.contract-grid .ag-checkbox-input-wrapper) {
  position: relative;
  width: 16px;
  height: 16px;
  border: 1px solid #95a6b8;
  border-radius: 2px;
  background-color: #ffffff;
  line-height: 16px;
}

:deep(.contract-grid .ag-checkbox-input-wrapper::before) {
  display: none !important;
}

:deep(.contract-grid .ag-checkbox-input-wrapper::after) {
  content: '';
  position: absolute;
  left: 50%;
  top: 50%;
  width: 0;
  height: 0;
  border: 0;
  transform: translate(-50%, -50%);
}

:deep(.contract-grid .ag-checkbox-input-wrapper.ag-checked) {
  border-color: #2a90e8;
  background-color: #2a90e8;
}

:deep(.contract-grid .ag-checkbox-input-wrapper.ag-checked::after) {
  content: '✓';
  width: auto;
  height: auto;
  color: #ffffff;
  font-size: 12px;
  font-weight: 700;
  line-height: 1;
  transform: translate(-50%, -56%);
}

:deep(.contract-grid .ag-checkbox-input-wrapper.ag-indeterminate) {
  border-color: #2a90e8;
  background-color: #2a90e8;
}

:deep(.contract-grid .ag-checkbox-input-wrapper.ag-indeterminate::after) {
  content: '';
  left: 50%;
  top: 50%;
  width: 8px;
  height: 2px;
  border: 0;
  background: #ffffff;
  transform: translate(-50%, -50%);
}

:deep(.contract-grid .ag-cell .ag-checkbox-input-wrapper.ag-indeterminate::after) {
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

:deep(.contract-grid .ag-cell-value),
:deep(.contract-grid .ag-header-cell-text) {
  display: inline-flex;
  align-items: center;
}

.system-dark :deep(.contract-grid .ag-row),
.system-dark :deep(.contract-grid .ag-header-row) {
  border-bottom: 1px dotted #4b5965 !important;
}

.system-dark :deep(.contract-grid .ag-row-selected::before) {
  background-color: #34516f !important;
}

.system-dark :deep(.contract-grid .ag-row-hover.ag-row-selected::before) {
  background-color: #406281 !important;
}

/* Dark mode checkbox styles */
.system-dark :deep(.contract-grid .ag-checkbox-input-wrapper) {
  border-color: #6f859b;
  background-color: rgb(var(--container-bg-color));
}

.system-dark :deep(.contract-grid .ag-checkbox-input-wrapper.ag-checked),
.system-dark :deep(.contract-grid .ag-checkbox-input-wrapper.ag-indeterminate) {
  border-color: #4ea4f3;
  background-color: #2f7fc5;
}

.system-dark :deep(.contract-grid .ag-body-horizontal-scroll),
.system-dark :deep(.contract-grid .ag-body-vertical-scroll),
.system-dark :deep(.contract-grid .ag-body-horizontal-scroll-viewport),
.system-dark :deep(.contract-grid .ag-body-vertical-scroll-viewport),
.system-dark :deep(.contract-grid .ag-body-horizontal-scroll-container),
.system-dark :deep(.contract-grid .ag-body-vertical-scroll-container) {
  background-color: rgb(var(--container-bg-color)) !important;
}

.system-dark :deep(.contract-grid .ag-body-horizontal-scroll-viewport),
.system-dark :deep(.contract-grid .ag-body-vertical-scroll-viewport) {
  scrollbar-color: #5e6f80 rgb(var(--container-bg-color));
}

.system-dark :deep(.contract-grid .ag-body-horizontal-scroll-viewport::-webkit-scrollbar),
.system-dark :deep(.contract-grid .ag-body-vertical-scroll-viewport::-webkit-scrollbar) {
  background-color: rgb(var(--container-bg-color));
}

.system-dark :deep(.contract-grid .ag-body-horizontal-scroll-viewport::-webkit-scrollbar-thumb),
.system-dark :deep(.contract-grid .ag-body-vertical-scroll-viewport::-webkit-scrollbar-thumb) {
  background-color: #5e6f80;
  border-radius: 8px;
}

/* Pagination styles - matching generic-query-workbench */
.system-dark :deep(.contract-grid .ag-paging-panel) {
  background-color: rgb(var(--container-bg-color));
  color: rgb(var(--base-text-color));
  border-top-color: #2b3a49;
}

.system-dark :deep(.contract-grid .ag-paging-button) {
  color: rgb(var(--base-text-color));
  background-color: #1b2a38;
  border: 1px solid #43576b;
  border-radius: 4px;
}

.system-dark :deep(.contract-grid .ag-paging-button:hover) {
  color: #f3f8ff;
  background-color: #243547;
  border-color: #5a7190;
}

.system-dark :deep(.contract-grid .ag-paging-button.ag-disabled),
.system-dark :deep(.contract-grid .ag-paging-button[aria-disabled='true']) {
  color: rgb(var(--base-text-color) / 0.45);
  background-color: #151f2a;
  border-color: #2f4152;
}

.system-dark :deep(.contract-grid .ag-paging-button .ag-icon) {
  color: inherit;
}

.system-dark :deep(.contract-grid .ag-picker-field-wrapper),
.system-dark :deep(.contract-grid .ag-select .ag-picker-field-wrapper),
.system-dark :deep(.contract-grid .ag-paging-page-size .ag-wrapper) {
  background-color: rgb(var(--container-bg-color));
  border-color: #43576b;
  color: rgb(var(--base-text-color));
}

.system-dark :deep(.contract-grid .ag-picker-field-display),
.system-dark :deep(.contract-grid .ag-picker-field-icon) {
  color: rgb(var(--base-text-color));
}

/* Filter panel styles */
.system-dark :deep(.contract-grid .ag-filter-body-wrapper) {
  background-color: rgb(var(--container-bg-color));
  color: rgb(var(--base-text-color));
}

.system-dark :deep(.contract-grid .ag-filter-condition) {
  background-color: rgb(var(--container-bg-color));
  color: rgb(var(--base-text-color));
}

.system-dark :deep(.contract-grid .ag-filter-select) {
  background-color: #1b2a38;
  border-color: #43576b;
  color: rgb(var(--base-text-color));
}

.system-dark :deep(.contract-grid .ag-filter-filter) {
  background-color: #1b2a38;
  border-color: #43576b;
  color: rgb(var(--base-text-color));
}

.system-dark :deep(.contract-grid .ag-filter-apply-panel) {
  background-color: rgb(var(--container-bg-color));
  border-top-color: #2b3a49;
}

.system-dark :deep(.contract-grid .ag-mini-filter) {
  background-color: #1b2a38;
  border-color: #43576b;
  color: rgb(var(--base-text-color));
}

.system-dark :deep(.contract-grid .ag-set-filter-list) {
  background-color: rgb(var(--container-bg-color));
}

.system-dark :deep(.contract-grid .ag-set-filter-item) {
  color: rgb(var(--base-text-color));
}

.system-dark :deep(.contract-grid .ag-set-filter-item:hover) {
  background-color: rgba(122, 167, 214, 0.18);
}

.system-dark :deep(.contract-grid .ag-set-filter-item.ag-selected) {
  background-color: #34516f;
}

/* Floating filter styles for dark mode */
.system-dark :deep(.contract-grid .ag-floating-filter) {
  background-color: rgb(var(--container-bg-color));
  border-top-color: #2b3a49;
}

.system-dark :deep(.contract-grid .ag-floating-filter-input) {
  background-color: #1b2a38;
  border-color: #43576b;
  color: rgb(var(--base-text-color));
}

.system-dark :deep(.contract-grid .ag-floating-filter-input::placeholder) {
  color: rgb(var(--base-text-color) / 0.5);
}

.system-dark :deep(.contract-grid .ag-floating-filter-button) {
  color: rgb(var(--base-text-color));
}

.system-dark :deep(.contract-grid .ag-floating-filter-button:hover) {
  color: #f3f8ff;
}

.system-dark :deep(.contract-grid input.ag-input-field-input) {
  background-color: #1b2a38;
  border-color: #43576b;
  color: rgb(var(--base-text-color));
}

.system-dark :deep(.contract-grid input.ag-input-field-input::placeholder) {
  color: rgb(var(--base-text-color) / 0.5);
}

.system-dark :deep(.contract-grid .ag-text-field-input) {
  background-color: #1b2a38;
  border-color: #43576b;
  color: rgb(var(--base-text-color));
}

.system-dark :deep(.contract-grid .ag-text-field-input::placeholder) {
  color: rgb(var(--base-text-color) / 0.5);
}
</style>
