import { defineStore } from 'pinia';
import { ref } from 'vue';
import { fetchInvitationTree, fetchInvitationDetail, fetchInvitationOptions } from '@/service/api';
import { usePersonnelTreeStore } from '@/hooks/business/use-personnel-tree-store';

export const useInvitationStore = defineStore('invitation-store', () => {
  const tree = usePersonnelTreeStore(fetchInvitationTree, fetchInvitationOptions);

  const invitationDetail = ref<Api.Invitation.InvitationDetail | null>(null);
  // 多条修改模式状态
  const isBatchEditMode = ref(false);
  const batchEditForm = ref<Record<string, any>>({});
  const batchEditFields = ref<Api.Workbench.AddField[]>([]);
  // 新增模式状态
  const isAddingMode = ref(false);
  const addFormDynamic = ref<Record<string, any>>({});
  const addFields = ref<Api.Workbench.AddField[]>([]);

  async function loadInvitationDetail(guid: string) {
    const { data } = await fetchInvitationDetail(guid);
    if (data) {
      invitationDetail.value = data;
    }
  }

  // 覆盖 composable 的 clearSelection：额外清空 detail
  function clearSelection() {
    tree.clearSelection();
    invitationDetail.value = null;
  }

  function setBatchEditMode(mode: boolean) {
    isBatchEditMode.value = mode;
  }

  function setBatchEditForm(form: Record<string, any>) {
    batchEditForm.value = form;
  }

  function setBatchEditFields(fields: Api.Workbench.AddField[]) {
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

  function setAddFields(fields: Api.Workbench.AddField[]) {
    addFields.value = fields;
  }

  function clearAddState() {
    isAddingMode.value = false;
    addFormDynamic.value = {};
    addFields.value = [];
  }

  return {
    treeData: tree.treeData,
    checkedKeys: tree.checkedKeys,
    selectedGuids: tree.selectedGuids,
    expandedKeys: tree.expandedKeys,
    options: tree.options,
    isLoaded: tree.isLoaded,
    loading: tree.loading,
    invitationDetail,
    isBatchEditMode,
    batchEditForm,
    batchEditFields,
    isAddingMode,
    addFormDynamic,
    addFields,
    loadTreeData: tree.loadTreeData,
    loadOptions: tree.loadOptions,
    clearSelection,
    refreshTree: tree.refreshTree,
    setExpandedKeys: tree.setExpandedKeys,
    setCheckedKeys: tree.setCheckedKeys,
    setSelectedGuids: tree.setSelectedGuids,
    loadInvitationDetail,
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
