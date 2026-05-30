import { defineStore } from 'pinia';
import { ref } from 'vue';
import { fetchEmployeeTree, fetchEmployeeDetail, fetchEmployeeOptions } from '@/service/api';
import type { TreeOption } from 'naive-ui';

export const useEmployeeStore = defineStore('employee', () => {
  const treeData = ref<TreeOption[]>([]);
  const checkedKeys = ref<string[]>([]);
  const selectedGuids = ref<string[]>([]);
  const expandedKeys = ref<string[]>([]);
  const employeeDetail = ref<Api.Employee.EmployeeDetail | null>(null);
  const options = ref<Api.Employee.EmployeeOptions | null>(null);

  async function fetchTree() {
    const { data } = await fetchEmployeeTree();
    if (data) {
      treeData.value = convertToTreeOptions(data);
    }
  }

  async function fetchDetail(guid: string) {
    const { data } = await fetchEmployeeDetail(guid);
    if (data) {
      employeeDetail.value = data;
    }
  }

  async function fetchOptions() {
    const { data } = await fetchEmployeeOptions();
    if (data) {
      options.value = data;
    }
  }

  function setCheckedKeys(keys: string[]) {
    checkedKeys.value = keys;
  }

  function setSelectedGuids(guids: string[]) {
    selectedGuids.value = guids;
  }

  function setExpandedKeys(keys: string[]) {
    expandedKeys.value = keys;
  }

  function setEmployeeDetail(detail: Api.Employee.EmployeeDetail | null) {
    employeeDetail.value = detail;
  }

  function convertToTreeOptions(nodes: Api.Employee.EmployeeTreeNode[]): TreeOption[] {
    return nodes.map(n => ({
      key: n.id,
      label: n.value,
      data: n,
      children: n.items?.length ? convertToTreeOptions(n.items) : undefined
    }));
  }

  return {
    treeData,
    checkedKeys,
    selectedGuids,
    expandedKeys,
    employeeDetail,
    options,
    fetchTree,
    fetchDetail,
    fetchOptions,
    setCheckedKeys,
    setSelectedGuids,
    setExpandedKeys,
    setEmployeeDetail
  };
});
