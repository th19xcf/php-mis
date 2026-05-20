import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { TreeOption } from 'naive-ui';
import { fetchInterviewTree, fetchInterviewDetail, fetchInterviewOptions } from '@/service/api';

export const useInterviewStore = defineStore('interview-store', () => {
  const treeData = ref<TreeOption[]>([]);
  const checkedKeys = ref<string[]>([]);
  const selectedGuids = ref<string[]>([]);
  const interviewDetail = ref<Api.Interview.InterviewDetail | null>(null);
  const isLoaded = ref(false);
  const loading = ref(false);
  const expandedKeys = ref<string[]>([]);
  const options = ref<Api.Interview.InterviewOptions | null>(null);

  async function loadTreeData() {
    loading.value = true;
    const { data, error } = await fetchInterviewTree();
    loading.value = false;

    if (!error && data) {
      treeData.value = convertToTreeOptions(data);
      isLoaded.value = true;
    }
  }

  async function loadInterviewDetail(guid: string) {
    const { data } = await fetchInterviewDetail(guid);
    if (data) {
      interviewDetail.value = data;
    }
  }

  async function loadOptions() {
    if (options.value) return options.value;

    const { data } = await fetchInterviewOptions();
    if (data) {
      options.value = data;
    }
    return options.value;
  }

  function clearSelection() {
    checkedKeys.value = [];
    selectedGuids.value = [];
    interviewDetail.value = null;
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
    interviewDetail,
    isLoaded,
    loading,
    expandedKeys,
    options,
    loadTreeData,
    loadInterviewDetail,
    loadOptions,
    clearSelection,
    refreshTree,
    setExpandedKeys,
    setCheckedKeys,
    setSelectedGuids
  };
});

function convertToTreeOptions(nodes: Api.Interview.InterviewTreeNode[]): TreeOption[] {
  return nodes.map(node => ({
    key: node.id,
    label: node.value,
    data: node,
    children: node.items && node.items.length > 0 ? convertToTreeOptions(node.items) : undefined
  }));
}
