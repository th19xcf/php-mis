import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { TreeOption } from 'naive-ui';
import { fetchTrainTree, fetchTrainDetail, fetchTrainOptions } from '@/service/api';

export const useTrainStore = defineStore('train-store', () => {
  const treeData = ref<TreeOption[]>([]);
  const checkedKeys = ref<string[]>([]);
  const selectedGuids = ref<string[]>([]);
  const trainDetail = ref<Api.Train.TrainDetail | null>(null);
  const isLoaded = ref(false);
  const loading = ref(false);
  const expandedKeys = ref<string[]>([]);
  const options = ref<Api.Train.TrainOptions | null>(null);

  async function loadTreeData() {
    loading.value = true;
    const { data, error } = await fetchTrainTree();
    loading.value = false;

    if (!error && data) {
      treeData.value = convertToTreeOptions(data);
      isLoaded.value = true;
    }
  }

  async function loadTrainDetail(guid: string) {
    const { data } = await fetchTrainDetail(guid);
    if (data) {
      trainDetail.value = data;
    }
  }

  async function loadOptions() {
    if (options.value) return options.value;

    const { data } = await fetchTrainOptions();
    if (data) {
      options.value = data;
    }
    return options.value;
  }

  function clearSelection() {
    checkedKeys.value = [];
    selectedGuids.value = [];
    trainDetail.value = null;
  }

  function refreshTree() {
    isLoaded.value = false;
    treeData.value = [];
    checkedKeys.value = [];
    selectedGuids.value = [];
    return loadTreeData();
  }

  function setExpandedKeys(keys: string[]) {
    expandedKeys.value = keys;
  }

  function setCheckedKeys(keys: string[]) {
    checkedKeys.value = keys;
  }

  function setSelectedGuids(guids: string[]) {
    selectedGuids.value = guids;
  }

  return {
    treeData,
    checkedKeys,
    selectedGuids,
    trainDetail,
    isLoaded,
    loading,
    expandedKeys,
    options,
    loadTreeData,
    loadTrainDetail,
    loadOptions,
    clearSelection,
    refreshTree,
    setExpandedKeys,
    setCheckedKeys,
    setSelectedGuids
  };
});

function convertToTreeOptions(nodes: Api.Train.TrainTreeNode[]): TreeOption[] {
  return nodes.map(node => ({
    key: node.id,
    label: node.value,
    data: node,
    children: node.items && node.items.length > 0 ? convertToTreeOptions(node.items) : undefined
  }));
}