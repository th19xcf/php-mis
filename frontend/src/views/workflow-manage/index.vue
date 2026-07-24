<script setup lang="ts">
import { ref, onMounted, computed } from 'vue';
import { AgGridVue } from 'ag-grid-vue3';
import { AG_GRID_LOCALE_CN } from '@ag-grid-community/locale';
import { AllCommunityModule, ModuleRegistry, themeAlpine, type GridApi } from 'ag-grid-community';
import { useDialog, useMessage } from 'naive-ui';
import { useThemeStore } from '@/store/modules/theme';
import {
  fetchWorkflowDefinitionList,
  fetchWorkflowDefinitionDelete,
  fetchWorkflowDefinitionActivate,
  fetchWorkflowDefinitionDeactivate
} from '@/service/api/workflow';
import WorkflowDefForm from './components/WorkflowDefForm.vue';

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

const showFormModal = ref(false);
const formMode = ref<'create' | 'edit'>('create');
const currentDef = ref<any>(null);
const gridApi = ref<GridApi | null>(null);

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

const definitionList = ref<any[]>([]);
const loading = ref(false);

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
  { field: '业务类型', headerName: '业务类型', width: 120, minWidth: 100, filter: 'agTextColumnFilter' },
  { field: '版本号', headerName: '版本', width: 80, minWidth: 60, type: '数值', cellStyle: { textAlign: 'right' } },
  { field: '流程状态', headerName: '状态', width: 100, minWidth: 80, filter: 'agTextColumnFilter' },
  { field: '流程描述', headerName: '描述', width: 200, minWidth: 150, filter: 'agTextColumnFilter' },
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
  { value: 'CONTRACT', label: '合同' },
  { value: 'EMPLOYEE', label: '员工' },
  { value: 'LEAVE', label: '请假' }
];

const statusOptions = [
  { value: 'DRAFT', label: '草稿' },
  { value: 'ACTIVE', label: '启用' },
  { value: 'INACTIVE', label: '停用' }
];

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
    }
  } finally {
    loading.value = false;
  }
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

function handleCreate() {
  formMode.value = 'create';
  currentDef.value = null;
  showFormModal.value = true;
}

function handleEdit() {
  const selected = gridApi.value?.getSelectedRows();
  if (!selected || selected.length === 0) {
    message.warning('请先选择一条流程定义');
    return;
  }
  formMode.value = 'edit';
  currentDef.value = selected[0];
  showFormModal.value = true;
}

function handleDelete() {
  const selected = gridApi.value?.getSelectedRows();
  if (!selected || selected.length === 0) {
    message.warning('请先选择一条流程定义');
    return;
  }
  const def = selected[0];
  dialog.warning({
    title: '确认删除',
    content: `确定要删除流程「${def.流程名称}」吗？`,
    positiveText: '确定',
    negativeText: '取消',
    onPositiveClick: async () => {
      await fetchWorkflowDefinitionDelete(def.GUID);
      message.success('删除成功');
      loadList();
    }
  });
}

async function handleActivate() {
  const selected = gridApi.value?.getSelectedRows();
  if (!selected || selected.length === 0) {
    message.warning('请先选择一条流程定义');
    return;
  }
  const def = selected[0];
  await fetchWorkflowDefinitionActivate(def.GUID);
  message.success('启用成功');
  loadList();
}

async function handleDeactivate() {
  const selected = gridApi.value?.getSelectedRows();
  if (!selected || selected.length === 0) {
    message.warning('请先选择一条流程定义');
    return;
  }
  const def = selected[0];
  await fetchWorkflowDefinitionDeactivate(def.GUID);
  message.success('停用成功');
  loadList();
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

function handleFormSuccess() {
  showFormModal.value = false;
  loadList();
}

onMounted(() => {
  loadList();
});
</script>

<template>
  <div class="workflow-manage-page" :class="{ 'system-dark': isDarkMode }">
    <div class="page-header">
      <h2>工作流管理</h2>
    </div>

    <div class="content">
      <div class="search-bar">
        <div class="search-form">
          <div class="form-item">
            <label>流程编码</label>
            <input v-model="searchForm.workflowCode" placeholder="请输入流程编码" />
          </div>
          <div class="form-item">
            <label>流程名称</label>
            <input v-model="searchForm.workflowName" placeholder="请输入流程名称" />
          </div>
          <div class="form-item">
            <label>业务类型</label>
            <select v-model="searchForm.businessType">
              <option value="">全部</option>
              <option v-for="opt in businessTypeOptions" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </option>
            </select>
          </div>
          <div class="form-item">
            <label>状态</label>
            <select v-model="searchForm.status">
              <option value="">全部</option>
              <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </option>
            </select>
          </div>
        </div>
        <div class="search-actions">
          <button class="btn btn-primary" @click="handleSearch">查询</button>
          <button class="btn btn-default" @click="handleReset">重置</button>
        </div>
      </div>

      <div class="toolbar">
        <div class="actions">
          <button class="btn btn-primary" @click="handleCreate">新建流程</button>
          <button class="btn btn-default" @click="handleEdit">编辑</button>
          <button class="btn btn-success" @click="handleActivate">启用</button>
          <button class="btn btn-warning" @click="handleDeactivate">停用</button>
          <button class="btn btn-danger" @click="handleDelete">删除</button>
        </div>
      </div>

      <div class="grid-container">
        <AgGridVue
          class="ag-grid-custom"
          :class="gridTheme"
          :columnDefs="columnDefs"
          :defaultColDef="defaultColDef"
          :columnTypes="columnTypes"
          :rowData="definitionList"
          :localeText="AG_GRID_LOCALE_CN"
          :pagination="false"
          :rowSelection="{ mode: 'singleRow' }"
          @grid-ready="onGridReady"
        />
      </div>

      <div class="pagination">
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

    <WorkflowDefForm
      v-model:visible="showFormModal"
      :mode="formMode"
      :definition="currentDef"
      @success="handleFormSuccess"
    />
  </div>
</template>

<style scoped lang="scss">
.workflow-manage-page {
  padding: 20px;
  height: 100%;
  display: flex;
  flex-direction: column;

  .page-header {
    margin-bottom: 20px;

    h2 {
      margin: 0;
      font-size: 20px;
      font-weight: 600;
    }
  }

  .content {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: 0;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    padding: 16px;
  }

  // Dark mode overrides
  &.system-dark {
    --wb-dark-bg: rgb(var(--container-bg-color));

    .content {
      background: var(--wb-dark-bg);
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
    }

    .search-bar {
      .form-item {
        label {
          color: #b0b0b0;
        }

        input,
        select {
          background: var(--wb-dark-bg);
          border-color: rgba(255, 255, 255, 0.15);
          color: #e0e0e0;

          &::placeholder {
            color: #888;
          }

          &:focus {
            border-color: #64b5f6;
          }
        }
      }
    }

    .btn-default {
      background: var(--wb-dark-bg);
      color: #e0e0e0;
      border-color: rgba(255, 255, 255, 0.15);

      &:hover {
        border-color: #64b5f6;
        color: #64b5f6;
      }
    }

    .toolbar {
      border-bottom-color: rgba(255, 255, 255, 0.09);
    }

    .pagination {
      border-top-color: rgba(255, 255, 255, 0.09);
      color: #b0b0b0;

      button {
        background: var(--wb-dark-bg);
        border-color: rgba(255, 255, 255, 0.15);
        color: #e0e0e0;

        &:hover:not(:disabled) {
          border-color: #64b5f6;
          color: #64b5f6;
        }
      }

      select {
        background: var(--wb-dark-bg);
        border-color: rgba(255, 255, 255, 0.15);
        color: #e0e0e0;
      }
    }

    // Child component dark mode overrides
    :deep(.modal-container) {
      background: var(--wb-dark-bg);
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4);

      .modal-header {
        border-bottom-color: rgba(255, 255, 255, 0.09);

        h3 {
          color: #e0e0e0;
        }

        .close-btn {
          color: #888;

          &:hover {
            color: #e0e0e0;
          }
        }
      }

      .modal-body {
        .form-item {
          label {
            color: #b0b0b0;

            .required {
              color: #ff7875;
            }
          }

          input,
          select,
          textarea {
            background: var(--wb-dark-bg);
            border-color: rgba(255, 255, 255, 0.15);
            color: #e0e0e0;

            &::placeholder {
              color: #888;
            }

            &:focus {
              border-color: #64b5f6;
            }

            &:disabled {
              background: rgba(255, 255, 255, 0.05);
            }
          }
        }

        .notice {
          background: rgba(250, 173, 20, 0.1);
          border-color: rgba(250, 173, 20, 0.25);

          p {
            color: #ffc53d;
          }
        }
      }

      .modal-footer {
        border-top-color: rgba(255, 255, 255, 0.09);
      }

      .btn-default {
        background: var(--wb-dark-bg);
        color: #e0e0e0;
        border-color: rgba(255, 255, 255, 0.15);

        &:hover {
          border-color: #64b5f6;
          color: #64b5f6;
        }
      }
    }
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

    &.btn-success {
      background: #52c41a;
      color: #fff;

      &:hover {
        background: #73d13d;
      }
    }

    &.btn-warning {
      background: #faad14;
      color: #fff;

      &:hover {
        background: #ffc53d;
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
}
</style>
