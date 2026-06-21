import { defineStore } from 'pinia';
import { ref } from 'vue';
import { fetchTrainTree, fetchTrainDetail, fetchTrainOptions } from '@/service/api';
import { usePersonnelTreeStore } from '@/hooks/business/use-personnel-tree-store';

export const useTrainStore = defineStore('train-store', () => {
  const tree = usePersonnelTreeStore(fetchTrainTree, fetchTrainOptions);

  const trainDetail = ref<Api.Train.TrainDetail | null>(null);

  async function loadTrainDetail(guid: string) {
    const { data } = await fetchTrainDetail(guid);
    if (data) {
      trainDetail.value = data;
    }
  }

  // 覆盖 composable 的 clearSelection：额外清空 detail
  function clearSelection() {
    tree.clearSelection();
    trainDetail.value = null;
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
    trainDetail,
    loadTreeData: tree.loadTreeData,
    loadOptions: tree.loadOptions,
    clearSelection,
    refreshTree: tree.refreshTree,
    setExpandedKeys: tree.setExpandedKeys,
    setCheckedKeys: tree.setCheckedKeys,
    setSelectedGuids: tree.setSelectedGuids,
    loadTrainDetail
  };
});
