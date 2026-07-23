import { defineStore } from 'pinia';
import { ref } from 'vue';
import {
  fetchContractV2List,
  fetchContractV2Detail,
  fetchContractV2Create,
  fetchContractV2Update,
  fetchContractV2Delete,
  fetchContractV2Submit,
  fetchContractV2Approve,
  fetchContractV2Stats,
  fetchContractV2Options,
  fetchContractV2PendingTasks,
  fetchContractV2DoneTasks,
  fetchContractV2MyContracts,
  fetchContractV2FlowDetail
} from '@/service/api/contract-v2';

export const useContractV2Store = defineStore('contract-v2-store', () => {
  const contractList = ref<Api.ContractV2.ContractListItem[]>([]);
  const currentContract = ref<Api.ContractV2.ContractDetail | null>(null);
  const loading = ref(false);
  const pagination = ref({
    page: 1,
    pageSize: 20,
    total: 0
  });
  const searchParams = ref({
    contractNo: '',
    contractName: '',
    contractType: '',
    contractStatus: '',
    partyA: '',
    partyB: ''
  });
  const options = ref<Api.ContractV2.ContractOptions>({
    合同类型: [],
    合同状态: [],
    付款方式: [],
    币别: []
  });
  const stats = ref<Api.ContractV2.ContractStats>({
    总数: 0,
    草稿: 0,
    审批中: 0,
    已通过: 0,
    已拒绝: 0,
    执行中: 0,
    已归档: 0,
    即将到期: 0
  });

  const pendingTasks = ref<Api.Workflow.WorkflowTask[]>([]);
  const pendingTasksPagination = ref({ page: 1, pageSize: 20, total: 0 });

  const doneTasks = ref<Api.Workflow.WorkflowTask[]>([]);
  const doneTasksPagination = ref({ page: 1, pageSize: 20, total: 0 });

  const myContracts = ref<Api.Workflow.WorkflowInstance[]>([]);
  const myContractsPagination = ref({ page: 1, pageSize: 20, total: 0 });

  const currentFlowDetail = ref<Api.Workflow.WorkflowInstance | null>(null);

  async function loadContractList(params?: typeof searchParams.value) {
    loading.value = true;
    try {
      const queryParams = params || searchParams.value;
      const response = await fetchContractV2List({
        ...queryParams,
        page: pagination.value.page,
        pageSize: pagination.value.pageSize
      });
      const data = (response as any)?.data || response;
      if (data && Array.isArray(data.list)) {
        contractList.value = data.list;
        pagination.value.total = data.total || 0;
      }
    } finally {
      loading.value = false;
    }
  }

  async function loadContractDetail(contractNo: string) {
    loading.value = true;
    try {
      const result = await fetchContractV2Detail(contractNo);
      const data = (result as any)?.data || (result as any);
      if (data) {
        currentContract.value = data as Api.ContractV2.ContractDetail;
      }
      return data;
    } finally {
      loading.value = false;
    }
  }

  async function createContract(data: Api.ContractV2.ContractCreateParams) {
    loading.value = true;
    try {
      const res = await fetchContractV2Create(data);
      if (res) {
        await loadContractList();
      }
      return res;
    } finally {
      loading.value = false;
    }
  }

  async function updateContract(data: Api.ContractV2.ContractUpdateParams) {
    loading.value = true;
    try {
      const res = await fetchContractV2Update(data);
      if (res) {
        await loadContractList();
        if (currentContract.value && currentContract.value.合同编号 === data.contractNo) {
          await loadContractDetail(data.contractNo);
        }
      }
      return res;
    } finally {
      loading.value = false;
    }
  }

  async function deleteContract(contractNo: string) {
    loading.value = true;
    try {
      const res = await fetchContractV2Delete(contractNo);
      if (res) {
        await loadContractList();
      }
      return res;
    } finally {
      loading.value = false;
    }
  }

  async function submitApproval(contractNo: string, workflowCode = 'contract_approval') {
    loading.value = true;
    try {
      const res = await fetchContractV2Submit(contractNo, workflowCode);
      if (res) {
        await loadContractList();
        if (currentContract.value && currentContract.value.合同编号 === contractNo) {
          await loadContractDetail(contractNo);
        }
      }
      return res;
    } finally {
      loading.value = false;
    }
  }

  async function handleApproval(taskId: number, action: 'APPROVE' | 'REJECT', opinion = '') {
    loading.value = true;
    try {
      const res = await fetchContractV2Approve({ taskId, action, opinion });
      if (res) {
        await loadPendingTasks();
      }
      return res;
    } finally {
      loading.value = false;
    }
  }

  async function loadStats(filters?: Record<string, any>) {
    try {
      const result = await fetchContractV2Stats(filters);
      const data = (result as any)?.data || (result as any);
      if (data) {
        stats.value = data as Api.ContractV2.ContractStats;
      }
    } catch {
      // Error loading stats
    }
  }

  async function loadOptions() {
    try {
      const result = await fetchContractV2Options();
      const data = (result as any)?.data || (result as any);
      if (data) {
        options.value = data as Api.ContractV2.ContractOptions;
      }
    } catch {
      // Error loading options
    }
  }

  async function loadPendingTasks(page = 1, pageSize = 20) {
    loading.value = true;
    try {
      const result = await fetchContractV2PendingTasks({ page, pageSize });
      const data = (result as any)?.data || (result as any);
      if (data && Array.isArray(data.list)) {
        pendingTasks.value = data.list;
        pendingTasksPagination.value = {
          page: data.page || page,
          pageSize: data.pageSize || pageSize,
          total: data.total || 0
        };
      }
    } finally {
      loading.value = false;
    }
  }

  async function loadDoneTasks(page = 1, pageSize = 20) {
    loading.value = true;
    try {
      const result = await fetchContractV2DoneTasks({ page, pageSize });
      const data = (result as any)?.data || (result as any);
      if (data && Array.isArray(data.list)) {
        doneTasks.value = data.list;
        doneTasksPagination.value = {
          page: data.page || page,
          pageSize: data.pageSize || pageSize,
          total: data.total || 0
        };
      }
    } finally {
      loading.value = false;
    }
  }

  async function loadMyContracts(page = 1, pageSize = 20) {
    loading.value = true;
    try {
      const result = await fetchContractV2MyContracts({ page, pageSize });
      const data = (result as any)?.data || (result as any);
      if (data && Array.isArray(data.list)) {
        myContracts.value = data.list;
        myContractsPagination.value = {
          page: data.page || page,
          pageSize: data.pageSize || pageSize,
          total: data.total || 0
        };
      }
    } finally {
      loading.value = false;
    }
  }

  async function loadFlowDetail(instanceId: number) {
    loading.value = true;
    try {
      const result = await fetchContractV2FlowDetail(instanceId);
      const data = (result as any)?.data || (result as any);
      if (data) {
        currentFlowDetail.value = data as Api.Workflow.WorkflowInstance;
      }
      return data;
    } finally {
      loading.value = false;
    }
  }

  function setPage(page: number) {
    pagination.value.page = page;
  }

  function setPageSize(pageSize: number) {
    pagination.value.pageSize = pageSize;
    pagination.value.page = 1;
  }

  function setSearchParams(params: Partial<typeof searchParams.value>) {
    searchParams.value = { ...searchParams.value, ...params };
  }

  function resetCurrentContract() {
    currentContract.value = null;
  }

  return {
    contractList,
    currentContract,
    loading,
    pagination,
    searchParams,
    options,
    stats,
    pendingTasks,
    pendingTasksPagination,
    doneTasks,
    doneTasksPagination,
    myContracts,
    myContractsPagination,
    currentFlowDetail,
    loadContractList,
    loadContractDetail,
    createContract,
    updateContract,
    deleteContract,
    submitApproval,
    handleApproval,
    loadStats,
    loadOptions,
    loadPendingTasks,
    loadDoneTasks,
    loadMyContracts,
    loadFlowDetail,
    setPage,
    setPageSize,
    setSearchParams,
    resetCurrentContract
  };
});
