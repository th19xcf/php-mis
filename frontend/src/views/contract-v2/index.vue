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

ModuleRegistry.registerModules([AllCommunityModule]);

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
const contractV2Store = useContractV2Store();

const activeTab = ref<'list' | 'pending' | 'done' | 'my'>('list');
const showFormModal = ref(false);
const showApprovalModal = ref(false);
const formMode = ref<'create' | 'edit'>('create');

const searchForm = ref({
  contractNo: '',
  contractName: '',
  contractType: '',
  contractStatus: '',
  partyA: '',
  partyB: ''
});

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

function handleSearch() {
  contractV2Store.setSearchParams(searchForm.value);
  contractV2Store.setPage(1);
  contractV2Store.loadContractList();
}

function handleReset() {
  searchForm.value = {
    contractNo: '',
    contractName: '',
    contractType: '',
    contractStatus: '',
    partyA: '',
    partyB: ''
  };
  handleSearch();
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

function handlePageChange(page: number) {
  contractV2Store.setPage(page);
  contractV2Store.loadContractList();
}

function handlePageSizeChange(pageSize: number) {
  contractV2Store.setPageSize(pageSize);
  contractV2Store.loadContractList();
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

function handleApprovalSuccess() {
  showApprovalModal.value = false;
  if (activeTab.value === 'pending') {
    contractV2Store.loadPendingTasks();
  }
}

onMounted(() => {
  contractV2Store.loadOptions();
  contractV2Store.loadStats();
  contractV2Store.loadContractList();
});
</script>

<template>
  <div class="contract-v2-page">
    <div class="page-header">
      <h2>合同管理 V2</h2>
      <div class="stats-cards">
        <div class="stat-card">
          <span class="stat-label">总数</span>
          <span class="stat-value">{{ stats.总数 }}</span>
        </div>
        <div class="stat-card">
          <span class="stat-label">审批中</span>
          <span class="stat-value">{{ stats.审批中 }}</span>
        </div>
        <div class="stat-card">
          <span class="stat-label">即将到期</span>
          <span class="stat-value">{{ stats.即将到期 }}</span>
        </div>
      </div>
    </div>

    <div class="content-wrapper">
      <div class="left-panel">
        <div class="search-bar">
          <div class="search-form">
            <div class="form-item">
              <label>合同编号</label>
              <input v-model="searchForm.contractNo" placeholder="请输入合同编号" />
            </div>
            <div class="form-item">
              <label>合同名称</label>
              <input v-model="searchForm.contractName" placeholder="请输入合同名称" />
            </div>
            <div class="form-item">
              <label>合同状态</label>
              <select v-model="searchForm.contractStatus">
                <option value="">全部</option>
                <option v-for="opt in options.合同状态" :key="opt.value" :value="opt.value">
                  {{ opt.label }}
                </option>
              </select>
            </div>
            <div class="form-item">
              <label>甲方</label>
              <input v-model="searchForm.partyA" placeholder="请输入甲方名称" />
            </div>
            <div class="form-item">
              <label>乙方</label>
              <input v-model="searchForm.partyB" placeholder="请输入乙方名称" />
            </div>
          </div>
          <div class="search-actions">
            <button class="btn btn-primary" @click="handleSearch">查询</button>
            <button class="btn btn-default" @click="handleReset">重置</button>
          </div>
        </div>

        <div class="toolbar">
          <div class="tabs">
            <div
              class="tab-item"
              :class="{ active: activeTab === 'list' }"
              @click="handleTabChange('list')"
            >
              全部合同
            </div>
            <div
              class="tab-item"
              :class="{ active: activeTab === 'pending' }"
              @click="handleTabChange('pending')"
            >
              待我审批
            </div>
            <div
              class="tab-item"
              :class="{ active: activeTab === 'done' }"
              @click="handleTabChange('done')"
            >
              我已审批
            </div>
            <div
              class="tab-item"
              :class="{ active: activeTab === 'my' }"
              @click="handleTabChange('my')"
            >
              我发起的
            </div>
          </div>
          <div class="actions">
            <button v-if="activeTab === 'list'" class="btn btn-primary" @click="handleCreate">
              新建合同
            </button>
            <button v-if="activeTab === 'list'" class="btn btn-default" @click="handleEdit">
              编辑
            </button>
            <button v-if="activeTab === 'list'" class="btn btn-default" @click="handleSubmit">
              提交审批
            </button>
            <button v-if="activeTab === 'list'" class="btn btn-danger" @click="handleDelete">
              删除
            </button>
          </div>
        </div>

        <div class="grid-container">
          <AgGridVue
            v-if="activeTab === 'list'"
            class="ag-grid-custom"
            :class="gridTheme"
            :columnDefs="columnDefs"
            :defaultColDef="defaultColDef"
            :columnTypes="columnTypes"
            :rowData="contractList"
            :localeText="AG_GRID_LOCALE_CN"
            :pagination="false"
            :rowSelection="{ type: 'single' } as any"
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
                <span class="task-node">{{ task.节点名称 }}</span>
              </div>
              <div class="task-info">
                <span>发起人：{{ task.发起人姓名 }}</span>
                <span>发起时间：{{ task.创建时间 }}</span>
              </div>
            </div>
            <div v-if="contractV2Store.pendingTasks.length === 0" class="empty-tip">
              暂无待办任务
            </div>
          </div>
          <div v-else-if="activeTab === 'done'" class="task-list">
            <div
              v-for="task in contractV2Store.doneTasks"
              :key="task.任务ID"
              class="task-item done"
            >
              <div class="task-header">
                <span class="task-title">{{ task.业务标题 }}</span>
                <span class="task-node">{{ task.节点名称 }}</span>
              </div>
              <div class="task-info">
                <span>发起人：{{ task.发起人姓名 }}</span>
                <span>处理时间：{{ task.处理时间 }}</span>
                <span class="task-result" :class="task.处理结果">
                  {{ task.处理结果 === 'APPROVE' ? '同意' : '拒绝' }}
                </span>
              </div>
            </div>
            <div v-if="contractV2Store.doneTasks.length === 0" class="empty-tip">
              暂无已办任务
            </div>
          </div>
          <div v-else-if="activeTab === 'my'" class="task-list">
            <div
              v-for="inst in contractV2Store.myContracts"
              :key="inst.GUID"
              class="task-item"
            >
              <div class="task-header">
                <span class="task-title">{{ inst.业务标题 }}</span>
                <span class="task-status" :class="inst.实例状态">
                  {{ inst.实例状态 === 'RUNNING' ? '运行中' : inst.实例状态 === 'COMPLETED' ? '已完成' : '已终止' }}
                </span>
              </div>
              <div class="task-info">
                <span>当前节点：{{ inst.当前节点编码 }}</span>
                <span>发起时间：{{ inst.发起时间 }}</span>
              </div>
            </div>
            <div v-if="contractV2Store.myContracts.length === 0" class="empty-tip">
              暂无发起的流程
            </div>
          </div>
        </div>

        <div v-if="activeTab === 'list'" class="pagination">
          <span>共 {{ pagination.total }} 条</span>
          <select :value="pagination.pageSize" @change="handlePageSizeChange(Number(($event.target as HTMLSelectElement).value))">
            <option :value="10">10条/页</option>
            <option :value="20">20条/页</option>
            <option :value="50">50条/页</option>
          </select>
          <div class="page-buttons">
            <button :disabled="pagination.page <= 1" @click="handlePageChange(pagination.page - 1)">
              上一页
            </button>
            <span class="current-page">{{ pagination.page }}</span>
            <button
              :disabled="pagination.page * pagination.pageSize >= pagination.total"
              @click="handlePageChange(pagination.page + 1)"
            >
              下一页
            </button>
          </div>
        </div>
      </div>

      <div class="right-panel">
        <div v-if="currentContract" class="detail-panel">
          <div class="detail-header">
            <h3>{{ currentContract.合同名称 }}</h3>
            <span class="status-badge" :class="currentContract.合同状态">
              {{ currentContract.合同状态 }}
            </span>
          </div>
          <div class="detail-content">
            <div class="detail-row">
              <div class="detail-item">
                <label>合同编号</label>
                <span>{{ currentContract.合同编号 }}</span>
              </div>
              <div class="detail-item">
                <label>合同类型</label>
                <span>{{ currentContract.合同类型 }}</span>
              </div>
            </div>
            <div class="detail-row">
              <div class="detail-item">
                <label>甲方</label>
                <span>{{ currentContract.甲方名称 }}</span>
              </div>
              <div class="detail-item">
                <label>乙方</label>
                <span>{{ currentContract.乙方名称 }}</span>
              </div>
            </div>
            <div class="detail-row">
              <div class="detail-item">
                <label>合同金额</label>
                <span class="amount">{{ Number(currentContract.合同金额).toLocaleString('zh-CN') }}</span>
              </div>
              <div class="detail-item">
                <label>付款方式</label>
                <span>{{ currentContract.付款方式 }}</span>
              </div>
            </div>
            <div class="detail-row">
              <div class="detail-item">
                <label>签订日期</label>
                <span>{{ currentContract.签订日期 }}</span>
              </div>
              <div class="detail-item">
                <label>到期日期</label>
                <span>{{ currentContract.结束日期 }}</span>
              </div>
            </div>
            <div class="detail-row">
              <div class="detail-item">
                <label>所属部门</label>
                <span>{{ currentContract.所属部门名称 }}</span>
              </div>
              <div class="detail-item">
                <label>创建人</label>
                <span>{{ currentContract.创建人姓名 }}</span>
              </div>
            </div>
            <div class="detail-row full">
              <div class="detail-item">
                <label>备注</label>
                <span class="remark">{{ currentContract.备注 }}</span>
              </div>
            </div>
          </div>

          <div class="flow-section">
            <h4>审批流程</h4>
            <ContractV2FlowTimeline v-if="currentContract.合同编号" :contract-no="currentContract.合同编号" />
          </div>
        </div>
        <div v-else class="empty-detail">
          <p>请选择一条合同记录查看详情</p>
        </div>
      </div>
    </div>

    <ContractV2Form
      v-model:visible="showFormModal"
      :mode="formMode"
      :contract="currentContract"
      @success="handleFormSuccess"
    />

    <ContractV2Approval
      v-model:visible="showApprovalModal"
      :contract="currentContract"
      @success="handleApprovalSuccess"
    />
  </div>
</template>

<style scoped lang="scss">
.contract-v2-page {
  padding: 20px;
  height: 100%;
  display: flex;
  flex-direction: column;

  .page-header {
    margin-bottom: 20px;

    h2 {
      margin: 0 0 16px 0;
      font-size: 20px;
      font-weight: 600;
    }

    .stats-cards {
      display: flex;
      gap: 16px;

      .stat-card {
        flex: 1;
        max-width: 200px;
        padding: 16px;
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        display: flex;
        flex-direction: column;
        gap: 8px;

        .stat-label {
          font-size: 14px;
          color: #666;
        }

        .stat-value {
          font-size: 24px;
          font-weight: 600;
          color: #1890ff;
        }
      }
    }
  }

  .content-wrapper {
    flex: 1;
    display: flex;
    gap: 20px;
    min-height: 0;
  }

  .left-panel {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-width: 0;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    padding: 16px;
  }

  .right-panel {
    width: 400px;
    flex-shrink: 0;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    overflow-y: auto;
  }

  .search-bar {
    margin-bottom: 16px;

    .search-form {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      margin-bottom: 12px;

      .form-item {
        display: flex;
        flex-direction: column;
        gap: 4px;
        min-width: 160px;

        label {
          font-size: 12px;
          color: #666;
        }

        input,
        select {
          padding: 6px 10px;
          border: 1px solid #d9d9d9;
          border-radius: 4px;
          font-size: 14px;
          outline: none;

          &:focus {
            border-color: #1890ff;
          }
        }
      }
    }

    .search-actions {
      display: flex;
      gap: 8px;
    }
  }

  .btn {
    padding: 6px 16px;
    border-radius: 4px;
    font-size: 14px;
    cursor: pointer;
    border: none;
    transition: all 0.2s;

    &.btn-primary {
      background: #1890ff;
      color: #fff;

      &:hover {
        background: #40a9ff;
      }
    }

    &.btn-default {
      background: #fff;
      color: #333;
      border: 1px solid #d9d9d9;

      &:hover {
        border-color: #1890ff;
        color: #1890ff;
      }
    }

    &.btn-danger {
      background: #ff4d4f;
      color: #fff;

      &:hover {
        background: #ff7875;
      }
    }

    &:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }
  }

  .toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    padding-bottom: 12px;
    border-bottom: 1px solid #f0f0f0;

    .tabs {
      display: flex;
      gap: 4px;

      .tab-item {
        padding: 8px 16px;
        cursor: pointer;
        border-radius: 4px;
        font-size: 14px;
        transition: all 0.2s;

        &.active {
          background: #e6f7ff;
          color: #1890ff;
          font-weight: 500;
        }

        &:hover:not(.active) {
          background: #f5f5f5;
        }
      }
    }

    .actions {
      display: flex;
      gap: 8px;
    }
  }

  .grid-container {
    flex: 1;
    min-height: 0;
  }

  .ag-grid-custom {
    width: 100%;
    height: 100%;
  }

  .task-list {
    height: 100%;
    overflow-y: auto;

    .task-item {
      padding: 16px;
      border-bottom: 1px solid #f0f0f0;
      cursor: pointer;
      transition: background 0.2s;

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

        .task-node {
          font-size: 12px;
          padding: 2px 8px;
          background: #e6f7ff;
          color: #1890ff;
          border-radius: 4px;
        }

        .task-status {
          font-size: 12px;
          padding: 2px 8px;
          border-radius: 4px;

          &.RUNNING {
            background: #e6f7ff;
            color: #1890ff;
          }

          &.COMPLETED {
            background: #f6ffed;
            color: #52c41a;
          }

          &.TERMINATED {
            background: #fff2f0;
            color: #ff4d4f;
          }
        }

        .task-result {
          font-size: 12px;
          padding: 2px 8px;
          border-radius: 4px;

          &.APPROVE {
            background: #f6ffed;
            color: #52c41a;
          }

          &.REJECT {
            background: #fff2f0;
            color: #ff4d4f;
          }
        }
      }

      .task-info {
        display: flex;
        gap: 16px;
        font-size: 13px;
        color: #666;
      }
    }

    .empty-tip {
      text-align: center;
      padding: 40px;
      color: #999;
    }
  }

  .pagination {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
    padding-top: 12px;
    border-top: 1px solid #f0f0f0;
    font-size: 14px;

    .page-buttons {
      display: flex;
      gap: 4px;
      align-items: center;

      .current-page {
        padding: 0 12px;
        font-weight: 500;
      }
    }

    button {
      padding: 4px 12px;
      border: 1px solid #d9d9d9;
      background: #fff;
      border-radius: 4px;
      cursor: pointer;

      &:hover:not(:disabled) {
        border-color: #1890ff;
        color: #1890ff;
      }

      &:disabled {
        opacity: 0.5;
        cursor: not-allowed;
      }
    }

    select {
      padding: 4px 8px;
      border: 1px solid #d9d9d9;
      border-radius: 4px;
    }
  }

  .detail-panel {
    padding: 20px;

    .detail-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 20px;
      padding-bottom: 16px;
      border-bottom: 1px solid #f0f0f0;

      h3 {
        margin: 0;
        font-size: 18px;
        font-weight: 600;
        flex: 1;
      }

      .status-badge {
        padding: 4px 12px;
        border-radius: 4px;
        font-size: 12px;
        background: #e6f7ff;
        color: #1890ff;
        flex-shrink: 0;
      }
    }

    .detail-content {
      .detail-row {
        display: flex;
        gap: 16px;
        margin-bottom: 16px;

        &.full {
          .detail-item {
            flex: 1;
          }
        }
      }

      .detail-item {
        flex: 1;
        min-width: 0;

        label {
          display: block;
          font-size: 12px;
          color: #999;
          margin-bottom: 4px;
        }

        span {
          font-size: 14px;
          color: #333;
          word-break: break-all;

          &.amount {
            color: #1890ff;
            font-weight: 600;
          }

          &.remark {
            display: block;
            white-space: pre-wrap;
            line-height: 1.6;
          }
        }
      }
    }

    .flow-section {
      margin-top: 24px;
      padding-top: 20px;
      border-top: 1px solid #f0f0f0;

      h4 {
        margin: 0 0 16px 0;
        font-size: 15px;
        font-weight: 600;
      }
    }
  }

  .empty-detail {
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #999;
  }
}
</style>
