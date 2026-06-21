import { defineStore } from 'pinia';
import { ref } from 'vue';
import { fetchInterviewTree, fetchInterviewDetail, fetchInterviewOptions } from '@/service/api';
import { usePersonnelTreeStore } from '@/hooks/business/use-personnel-tree-store';

export const useInterviewStore = defineStore('interview-store', () => {
  const tree = usePersonnelTreeStore(fetchInterviewTree, fetchInterviewOptions);

  const interviewDetail = ref<Api.Interview.InterviewDetail | null>(null);

  async function loadInterviewDetail(guid: string) {
    const { data } = await fetchInterviewDetail(guid);
    if (data) {
      interviewDetail.value = data;
    }
  }

  // 覆盖 composable 的 clearSelection：额外清空 detail
  function clearSelection() {
    tree.clearSelection();
    interviewDetail.value = null;
  }

  return {
    treeData: tree.treeData,
    checkedKeys: tree.checkedKeys,
    selectedGuids: tree.selectedGuids,
    expandedKeys: tree.expandedKeys,
    searchKeyword: tree.searchKeyword,
    options: tree.options,
    isLoaded: tree.isLoaded,
    loading: tree.loading,
    interviewDetail,
    loadTreeData: tree.loadTreeData,
    loadOptions: tree.loadOptions,
    clearSelection,
    refreshTree: tree.refreshTree,
    setExpandedKeys: tree.setExpandedKeys,
    setCheckedKeys: tree.setCheckedKeys,
    setSelectedGuids: tree.setSelectedGuids,
    loadInterviewDetail
  };
});
