import { defineStore } from 'pinia';
import { ref } from 'vue';
import { fetchEmployeeTree, fetchEmployeeDetail, fetchEmployeeOptions } from '@/service/api';
import { usePersonnelTreeStore } from '@/hooks/business/use-personnel-tree-store';

export const useEmployeeStore = defineStore('employee', () => {
  const tree = usePersonnelTreeStore(fetchEmployeeTree, fetchEmployeeOptions);

  const employeeDetail = ref<Api.Employee.EmployeeDetail | null>(null);

  async function loadEmployeeDetail(guid: string) {
    const { data } = await fetchEmployeeDetail(guid);
    if (data) {
      employeeDetail.value = data;
    }
  }

  function setEmployeeDetail(detail: Api.Employee.EmployeeDetail | null) {
    employeeDetail.value = detail;
  }

  // 覆盖 composable 的 clearSelection：额外清空 detail
  function clearSelection() {
    tree.clearSelection();
    employeeDetail.value = null;
  }

  return {
    treeData: tree.treeData,
    checkedKeys: tree.checkedKeys,
    selectedGuids: tree.selectedGuids,
    expandedKeys: tree.expandedKeys,
    options: tree.options,
    isLoaded: tree.isLoaded,
    loading: tree.loading,
    employeeDetail,
    loadTreeData: tree.loadTreeData,
    loadOptions: tree.loadOptions,
    clearSelection,
    refreshTree: tree.refreshTree,
    setExpandedKeys: tree.setExpandedKeys,
    setCheckedKeys: tree.setCheckedKeys,
    setSelectedGuids: tree.setSelectedGuids,
    loadEmployeeDetail,
    setEmployeeDetail
  };
});
