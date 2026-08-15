import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { TreeOption } from 'naive-ui';
import { fetchDeptTree, fetchDeptDetail } from '@/service/api';

export const useDeptStore = defineStore('dept-store', () => {
  const treeData = ref<TreeOption[]>([]);
  const selectedGuid = ref<string>('');
  const deptDetail = ref<Api.Dept.DeptDetail | null>(null);
  const isLoaded = ref(false);
  const loading = ref(false);
  const expandedKeys = ref<string[]>([]);
  const isAddingMode = ref(false);
  const isEditingMode = ref(false);

  async function loadTreeData() {
    if (isLoaded.value) return;

    loading.value = true;
    const { data, error } = await fetchDeptTree();
    loading.value = false;

    if (!error && data) {
      treeData.value = convertToTreeOptions(data);
      isLoaded.value = true;
    }
  }

  async function loadDeptDetail(guid: string) {
    selectedGuid.value = guid;
    const { data } = await fetchDeptDetail(guid);
    if (data) {
      deptDetail.value = data;
    }
  }

  function clearSelection() {
    selectedGuid.value = '';
    deptDetail.value = null;
  }

  function refreshTree() {
    isLoaded.value = false;
    treeData.value = [];
    return loadTreeData();
  }

  function setExpandedKeys(keys: string[]) {
    expandedKeys.value = keys;
  }

  function setAddingMode(value: boolean) {
    isAddingMode.value = value;
  }

  function clearAddState() {
    isAddingMode.value = false;
  }

  function setEditingMode(value: boolean) {
    isEditingMode.value = value;
  }

  function clearEditState() {
    isEditingMode.value = false;
  }

  return {
    treeData,
    selectedGuid,
    deptDetail,
    isLoaded,
    loading,
    expandedKeys,
    isAddingMode,
    isEditingMode,
    loadTreeData,
    loadDeptDetail,
    clearSelection,
    refreshTree,
    setExpandedKeys,
    setAddingMode,
    clearAddState,
    setEditingMode,
    clearEditState
  };
});

function convertToTreeOptions(nodes: Api.Dept.DeptTreeNode[]): TreeOption[] {
  return nodes.map(node => ({
    key: node.guid,
    label: `${node.deptName} (${node.deptCode})`,
    data: node,
    children: node.children && node.children.length > 0 ? convertToTreeOptions(node.children) : undefined
  }));
}
