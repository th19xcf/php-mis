import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { TreeOption } from 'naive-ui';
import { fetchStoreTree, fetchStoreDetail, fetchStoreOptions } from '@/service/api';
import type { AddField } from '@/typings/api/workbench';

export const useStoreStore = defineStore('store-store', () => {
  const treeData = ref<TreeOption[]>([]);
  const checkedKeys = ref<string[]>([]);
  const selectedGuids = ref<string[]>([]);
  const storeDetail = ref<Api.Store.StoreDetail | null>(null);
  const isLoaded = ref(false);
  const loading = ref(false);
  const expandedKeys = ref<string[]>([]);
  const options = ref<Api.Store.StoreOptions | null>(null);
  // 多条修改模式状态
  const isBatchEditMode = ref(false);
  const batchEditForm = ref<Record<string, any>>({});
  const batchEditFields = ref<AddField[]>([]);
  // 新增模式状态
  const isAddingMode = ref(false);
  const addFormDynamic = ref<Record<string, any>>({});
  const addFields = ref<AddField[]>([]);

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
    checkedKeys.value = [];
    selectedGuids.value = [];
    storeDetail.value = null;
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

  function setBatchEditMode(mode: boolean) {
    isBatchEditMode.value = mode;
  }

  function setBatchEditForm(form: Record<string, any>) {
    batchEditForm.value = form;
  }

  function setBatchEditFields(fields: AddField[]) {
    batchEditFields.value = fields;
  }

  function clearBatchEditState() {
    isBatchEditMode.value = false;
    batchEditForm.value = {};
    batchEditFields.value = [];
  }

  function setAddingMode(mode: boolean) {
    isAddingMode.value = mode;
  }

  function setAddFormDynamic(form: Record<string, any>) {
    addFormDynamic.value = form;
  }

  function setAddFields(fields: AddField[]) {
    addFields.value = fields;
  }

  function clearAddState() {
    isAddingMode.value = false;
    addFormDynamic.value = {};
    addFields.value = [];
  }

  return {
    treeData,
    checkedKeys,
    selectedGuids,
    storeDetail,
    isLoaded,
    loading,
    expandedKeys,
    options,
    isBatchEditMode,
    batchEditForm,
    batchEditFields,
    isAddingMode,
    addFormDynamic,
    addFields,
    loadTreeData,
    loadStoreDetail,
    loadOptions,
    clearSelection,
    refreshTree,
    setExpandedKeys,
    setCheckedKeys,
    setSelectedGuids,
    setBatchEditMode,
    setBatchEditForm,
    setBatchEditFields,
    clearBatchEditState,
    setAddingMode,
    setAddFormDynamic,
    setAddFields,
    clearAddState
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
