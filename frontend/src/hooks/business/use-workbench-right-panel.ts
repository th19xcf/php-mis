import { computed, ref, watch, type ComputedRef, type Ref } from 'vue';
import { useWorkbenchRightPanelStore, type RightPanelMode } from '@/store/modules/workbench-right-panel';

interface UseWorkbenchRightPanelOptions {
  functionCode: ComputedRef<string>;
  params: ComputedRef<string>;
  // 编辑表单状态（来自 useWorkbenchEditForms）
  addVisible: Ref<boolean>;
  addFormData: Ref<Record<string, any>>;
  addFormFields: Ref<any[]>;
  updateVisible: Ref<boolean>;
  updateFormData: Ref<Record<string, any>>;
  updateFormFields: Ref<any[]>;
  batchUpdateVisible: Ref<boolean>;
  batchUpdateFormData: Ref<Record<string, any>>;
  batchUpdateFormFields: Ref<any[]>;
  addDirty: Ref<boolean>;
  updateDirty: Ref<boolean>;
  batchUpdateDirty: Ref<boolean>;
  // 批注状态（来自 useWorkbenchComment）
  addCommentVisible: Ref<boolean>;
  viewCommentVisible: Ref<boolean>;
  commentFormData: Ref<Record<string, any>>;
  commentRemark: Ref<string>;
  commentFields: Ref<any[]>;
  commentList: Ref<any[]>;
}

/**
 * 工作台右栏状态机：面板模式互斥协调 + 未保存修改确认 + 状态持久化。
 *
 * 右侧面板模式：null / 'chart' / 'add' / 'update' / 'batch' / 'comment'，
 * 用于协调 chart 与 新增/单条修改/多条修改/添加批注/查看批注 互斥占据右侧分栏。
 *
 * 状态持久化：组件 mount / activate 时从 store 读回，watch 实时写回，
 * 保证无论组件是否被 KeepAlive 缓存、是否被销毁重建，右栏视图都能完整恢复。
 */
export function useWorkbenchRightPanel(options: UseWorkbenchRightPanelOptions) {
  const {
    functionCode,
    params,
    addVisible,
    addFormData,
    addFormFields,
    updateVisible,
    updateFormData,
    updateFormFields,
    batchUpdateVisible,
    batchUpdateFormData,
    batchUpdateFormFields,
    addDirty,
    updateDirty,
    batchUpdateDirty,
    addCommentVisible,
    viewCommentVisible,
    commentFormData,
    commentRemark,
    commentFields,
    commentList
  } = options;

  const rightPanelMode = ref<RightPanelMode>(null);
  const rightPanelVisible = computed(() => rightPanelMode.value !== null);

  // 当前右侧表单有未保存修改时，弹确认框；返回是否可继续（true=可离开/切换，false=取消）
  async function confirmDiscardIfDirty(): Promise<boolean> {
    const mode = rightPanelMode.value;
    const dirty =
      (mode === 'add' && addDirty.value) ||
      (mode === 'update' && updateDirty.value) ||
      (mode === 'batch' && batchUpdateDirty.value);
    if (!dirty) return true;
    return new Promise<boolean>(resolve => {
      window.$dialog?.warning({
        title: '未保存的修改',
        content: '当前表单有未保存的修改，是否确认离开？',
        positiveText: '离开',
        negativeText: '取消',
        onPositiveClick: () => resolve(true),
        onNegativeClick: () => resolve(false),
        onMaskClick: () => resolve(false),
        onClose: () => resolve(false)
      });
    });
  }

  const rightPanelStore = useWorkbenchRightPanelStore();

  /**
   * 从 store 读回右栏状态，覆盖本地 ref 的初始默认值
   * - 用于：组件被 KeepAlive 卸载后重新 mount（setup 中 composables 已用默认值初始化）
   * - 仅在 store 中存在该 functionCode::params 的缓存时才覆盖，避免污染首次进入的场景
   */
  function restoreRightPanelStateFromStore() {
    const saved = rightPanelStore.getState(functionCode.value, params.value);
    if (!saved) return;

    // 用 store 中的状态覆盖 composables 初始化出来的默认值
    if (saved.rightPanelMode !== undefined) rightPanelMode.value = saved.rightPanelMode;
    if (saved.addVisible !== undefined) addVisible.value = saved.addVisible;
    if (saved.addFormData !== undefined) addFormData.value = { ...saved.addFormData };
    if (saved.addFormFields !== undefined) addFormFields.value = [...saved.addFormFields];
    if (saved.updateVisible !== undefined) updateVisible.value = saved.updateVisible;
    if (saved.updateFormData !== undefined) updateFormData.value = { ...saved.updateFormData };
    if (saved.updateFormFields !== undefined) updateFormFields.value = [...saved.updateFormFields];
    if (saved.batchUpdateVisible !== undefined) batchUpdateVisible.value = saved.batchUpdateVisible;
    if (saved.batchUpdateFormData !== undefined) batchUpdateFormData.value = { ...saved.batchUpdateFormData };
    if (saved.batchUpdateFormFields !== undefined) batchUpdateFormFields.value = [...saved.batchUpdateFormFields];
    if (saved.addCommentVisible !== undefined) addCommentVisible.value = saved.addCommentVisible;
    if (saved.viewCommentVisible !== undefined) viewCommentVisible.value = saved.viewCommentVisible;
    if (saved.commentFormData !== undefined) commentFormData.value = { ...saved.commentFormData };
    if (saved.commentRemark !== undefined) commentRemark.value = saved.commentRemark;
    if (saved.commentFields !== undefined) commentFields.value = [...saved.commentFields];
    if (saved.commentList !== undefined) commentList.value = [...saved.commentList];
  }

  /**
   * 把当前右栏状态写回 store
   * - 监听任何相关 ref 变化都会调用本函数
   */
  function persistRightPanelStateToStore() {
    rightPanelStore.setState(functionCode.value, params.value, {
      rightPanelMode: rightPanelMode.value,
      addVisible: addVisible.value,
      addFormData: addFormData.value,
      addFormFields: addFormFields.value,
      updateVisible: updateVisible.value,
      updateFormData: updateFormData.value,
      updateFormFields: updateFormFields.value,
      batchUpdateVisible: batchUpdateVisible.value,
      batchUpdateFormData: batchUpdateFormData.value,
      batchUpdateFormFields: batchUpdateFormFields.value,
      addCommentVisible: addCommentVisible.value,
      viewCommentVisible: viewCommentVisible.value,
      commentFormData: commentFormData.value,
      commentRemark: commentRemark.value,
      commentFields: commentFields.value,
      commentList: commentList.value
    });
  }

  // 首次 setup：从 store 恢复（如果之前切走标签页保存过）
  restoreRightPanelStateFromStore();

  // 实时监听所有右栏相关 ref 变化，写回 store
  watch(
    [
      rightPanelMode,
      addVisible,
      addFormData,
      addFormFields,
      updateVisible,
      updateFormData,
      updateFormFields,
      batchUpdateVisible,
      batchUpdateFormData,
      batchUpdateFormFields,
      addCommentVisible,
      viewCommentVisible,
      commentFormData,
      commentRemark,
      commentFields,
      commentList
    ],
    () => {
      // functionCode / params 尚未就绪时不写入（极端情况）
      if (!functionCode.value) return;
      persistRightPanelStateToStore();
    },
    { deep: true }
  );

  return {
    rightPanelMode,
    rightPanelVisible,
    confirmDiscardIfDirty,
    restoreRightPanelStateFromStore
  };
}
