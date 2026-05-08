import { defineStore } from 'pinia';
import { ref } from 'vue';
import {
  fetchContractList,
  fetchContractDetail,
  fetchContractCreate,
  fetchContractUpdate,
  fetchContractDelete,
  fetchContractSubmit,
  fetchContractApprove,
  fetchContractReject,
  fetchContractSign,
  fetchContractArchive,
  fetchContractOptions,
  fetchContractStats,
  fetchContractFlow
} from '@/service/api/contract';

export const useContractStore = defineStore('contract-store', () => {
  const contractList = ref<Api.Contract.ContractListItem[]>([]);
  const currentContract = ref<Api.Contract.ContractDetail | null>(null);
  const contractFlow = ref<Api.Contract.ContractFlowRecord[]>([]);
  const loading = ref(false);
  const pagination = ref({
    page: 1,
    pageSize: 500,
    total: 0
  });
  const searchParams = ref({
    合同编号: '',
    合同名称: '',
    合同状态: '',
    合同类型: ''
  });
  const options = ref<Api.Contract.ContractOptions>({
    合同类型: [],
    合同状态: [],
    付款方式: []
  });
  const stats = ref<Api.Contract.ContractStats>({
    总数: 0,
    待审核: 0,
    已审核: 0,
    已签署: 0,
    即将到期: 0
  });

  async function loadContractList(params?: typeof searchParams.value) {
    loading.value = true;
    try {
      const queryParams = params || searchParams.value;
      console.log('Fetching contract list with params:', queryParams);
      const response = await fetchContractList(queryParams);
      console.log('Raw response:', JSON.stringify(response, null, 2));
      // Handle both transformed data and raw response
      const data = response?.data || response;
      console.log('Extracted data:', JSON.stringify(data, null, 2));
      if (data && Array.isArray(data.list)) {
        contractList.value = data.list;
        pagination.value.total = data.total || 0;
        console.log('Contract list set, length:', contractList.value.length);
      } else {
        console.error('Invalid response format, data:', data);
        console.error('Invalid response format, list:', data?.list);
      }
    } catch (error) {
      console.error('Error loading contract list:', error);
    } finally {
      loading.value = false;
    }
  }

  async function loadContractDetail(guid: number) {
    console.log('[ContractStore] loadContractDetail START, guid:', guid);
    loading.value = true;
    try {
      console.log('[ContractStore] loadContractDetail called with guid:', guid);
      const result = await fetchContractDetail(guid);
      console.log('[ContractStore] fetchContractDetail raw result:', result);

      // Extract actual data from response - result may be wrapped {data: {...}, error: null}
      const data = (result as any)?.data || (result as any);
      console.log('[ContractStore] extracted data:', data);

      if (data) {
        currentContract.value = data as Api.Contract.ContractDetail;
        console.log('[ContractStore] currentContract.value set:', currentContract.value);
      }
      return data;
    } catch (error) {
      console.error('[ContractStore] loadContractDetail error:', error);
      throw error;
    } finally {
      loading.value = false;
    }
  }

  async function createContract(data: Api.Contract.ContractCreateParams) {
    loading.value = true;
    try {
      const res = await fetchContractCreate(data);
      if (res) {
        await loadContractList();
      }
      return res;
    } finally {
      loading.value = false;
    }
  }

  async function updateContract(data: Api.Contract.ContractUpdateParams) {
    loading.value = true;
    try {
      const res = await fetchContractUpdate(data);
      if (res) {
        if (currentContract.value && currentContract.value.GUID === data.GUID) {
          await loadContractDetail(data.GUID);
        }
        await loadContractList();
      }
      return res;
    } finally {
      loading.value = false;
    }
  }

  async function deleteContract(guid: number) {
    loading.value = true;
    try {
      const res = await fetchContractDelete(guid);
      if (res) {
        await loadContractList();
      }
      return res;
    } finally {
      loading.value = false;
    }
  }

  async function submitContract(guid: number) {
    loading.value = true;
    try {
      const res = await fetchContractSubmit(guid);
      if (res) {
        await loadContractDetail(guid);
        await loadContractFlow(guid);
      }
      return res;
    } finally {
      loading.value = false;
    }
  }

  async function approveContract(data: Api.Contract.ContractApproveParams) {
    loading.value = true;
    try {
      const res = await fetchContractApprove(data);
      if (res) {
        await loadContractDetail(data.GUID);
        await loadContractFlow(data.GUID);
      }
      return res;
    } finally {
      loading.value = false;
    }
  }

  async function rejectContract(data: Api.Contract.ContractRejectParams) {
    loading.value = true;
    try {
      const res = await fetchContractReject(data);
      if (res) {
        await loadContractDetail(data.GUID);
        await loadContractFlow(data.GUID);
      }
      return res;
    } finally {
      loading.value = false;
    }
  }

  async function signContract(data: Api.Contract.ContractSignParams) {
    loading.value = true;
    try {
      const res = await fetchContractSign(data);
      if (res) {
        await loadContractDetail(data.GUID);
        await loadContractFlow(data.GUID);
      }
      return res;
    } finally {
      loading.value = false;
    }
  }

  async function archiveContract(guid: number) {
    loading.value = true;
    try {
      const res = await fetchContractArchive(guid);
      if (res) {
        await loadContractDetail(guid);
        await loadContractFlow(guid);
      }
      return res;
    } finally {
      loading.value = false;
    }
  }

  async function loadContractOptions() {
    const data = (await fetchContractOptions()) as unknown as Api.Contract.ContractOptions;
    if (data) {
      options.value = data;
    }
  }

  async function loadContractStats() {
    console.log('[ContractStore] loadContractStats called');
    const result = await fetchContractStats();
    console.log('[ContractStore] fetchContractStats raw result:', result);

    // Extract actual data from response
    const data = (result as any)?.data || (result as any);
    console.log('[ContractStore] loadContractStats extracted data:', data);

    if (data) {
      stats.value = data as Api.Contract.ContractStats;
      console.log('[ContractStore] stats.value set:', stats.value);
    }
  }

  async function loadContractFlow(guid: number) {
    const data = (await fetchContractFlow(guid)) as unknown as Api.Contract.ContractFlowRecord[];
    if (data) {
      contractFlow.value = data;
    }
  }

  function setPage(page: number) {
    pagination.value.page = page;
  }

  function setPageSize(pageSize: number) {
    pagination.value.pageSize = pageSize;
  }

  function setSearchParams(params: typeof searchParams.value) {
    searchParams.value = params;
  }

  function resetSearchParams() {
    searchParams.value = {
      合同编号: '',
      合同名称: '',
      合同状态: '',
      合同类型: ''
    };
  }

  function clearCurrentContract() {
    currentContract.value = null;
    contractFlow.value = [];
  }

  return {
    contractList,
    currentContract,
    contractFlow,
    loading,
    pagination,
    searchParams,
    options,
    stats,
    loadContractList,
    loadContractDetail,
    createContract,
    updateContract,
    deleteContract,
    submitContract,
    approveContract,
    rejectContract,
    signContract,
    archiveContract,
    loadContractOptions,
    loadContractStats,
    loadContractFlow,
    setPage,
    setPageSize,
    setSearchParams,
    resetSearchParams,
    clearCurrentContract
  };
});
