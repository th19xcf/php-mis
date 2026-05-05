import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { TreeOption } from 'naive-ui';
import { fetchStoreTree, fetchStoreDetail, fetchStoreOptions } from '@/service/api';

export const useStoreStore = defineStore('store-store', () => {
  const treeData = ref<TreeOption[]>([]);
  const selectedGuids = ref<string[]>([]);
  const storeDetail = ref<Api.Store.StoreDetail | null>(null);
  const isLoaded = ref(false);
  const loading = ref(false);
  const expandedKeys = ref<string[]>([]);
  const options = ref<Api.Store.StoreOptions | null>(null);

  async function loadTreeData() {
    loading.value = true;
    const { data, error } = await fetchStoreTree();
    loading.value = false;

    if (!error && data) {
      treeData.value = convertToTreeOptions(data);
      isLoaded.value = true;
    }
  }

  async function loadStoreDetail(guid: string) {
    const { data } = await fetchStoreDetail(guid);
    if (data) {
      storeDetail.value = data;
    }
  }

  async function loadOptions() {
    if (options.value) return options.value;

    const { data } = await fetchStoreOptions();
    if (data) {
      options.value = data;
    }
    return options.value;
  }

  function clearSelection() {
    selectedGuids.value = [];
    storeDetail.value = null;
  }

  function refreshTree() {
    isLoaded.value = false;
    treeData.value = [];
    return loadTreeData();
  }

  function setExpandedKeys(keys: string[]) {
    expandedKeys.value = keys;
  }

  function setSelectedGuids(guids: string[]) {
    selectedGuids.value = guids;
  }

  return {
    treeData,
    selectedGuids,
    storeDetail,
    isLoaded,
    loading,
    expandedKeys,
    options,
    loadTreeData,
    loadStoreDetail,
    loadOptions,
    clearSelection,
    refreshTree,
    setExpandedKeys,
    setSelectedGuids
  };
});

function convertToTreeOptions(nodes: Api.Store.StoreTreeNode[]): TreeOption[] {
  return nodes.map(node => ({
    key: node.id,
    label: node.value,
    data: node,
    children: node.items && node.items.length > 0 ? convertToTreeOptions(node.items) : undefined
  }));
}
