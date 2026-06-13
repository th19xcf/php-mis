import { defineStore } from 'pinia';
import { ref } from 'vue';

/**
 * 工作台右栏（新增 / 单条修改 / 多条修改 / 添加批注 / 查看批注 / 图形）状态持久化 store
 *
 * 背景：
 * - 右栏状态原本只存在于 GenericQueryWorkbench 内部 ref，切走标签页 / 切回路由 /
 *   任何导致组件被销毁重建的场景都会丢失。
 * - 把这些状态独立抽到 Pinia store，按 (functionCode, params) 维度缓存，
 *   组件 mount 时从 store 读回，watch 监听变更实时写回。
 * - 这样无论 workbench 是否被 KeepAlive 缓存、是否被销毁重建，右栏视图都能完整恢复。
 */

export type RightPanelMode = 'chart' | 'add' | 'update' | 'batch' | 'comment' | null;

export interface WorkbenchRightPanelState {
  rightPanelMode: RightPanelMode;
  addVisible: boolean;
  addFormData: Record<string, any>;
  addFormFields: any[];
  updateVisible: boolean;
  updateFormData: Record<string, any>;
  updateFormFields: any[];
  batchUpdateVisible: boolean;
  batchUpdateFormData: Record<string, any>;
  batchUpdateFormFields: any[];
  addCommentVisible: boolean;
  viewCommentVisible: boolean;
  commentFormData: Record<string, any>;
  commentRemark: string;
  commentFields: any[];
  commentList: any[];
  /** 时间戳，用于可能的过期清理 */
  timestamp: number;
}

export const useWorkbenchRightPanelStore = defineStore('workbench-right-panel', () => {
  // 缓存键 -> 状态
  const stateByKey = ref<Map<string, WorkbenchRightPanelState>>(new Map());

  function getKey(functionCode: string, params: string): string {
    return `${functionCode}::${params}`;
  }

  function getState(functionCode: string, params: string): WorkbenchRightPanelState | undefined {
    return stateByKey.value.get(getKey(functionCode, params));
  }

  function setState(functionCode: string, params: string, state: Partial<WorkbenchRightPanelState>) {
    const key = getKey(functionCode, params);
    const existing = stateByKey.value.get(key);
    const merged: WorkbenchRightPanelState = {
      rightPanelMode: state.rightPanelMode ?? existing?.rightPanelMode ?? null,
      addVisible: state.addVisible ?? existing?.addVisible ?? false,
      addFormData: state.addFormData ?? existing?.addFormData ?? {},
      addFormFields: state.addFormFields ?? existing?.addFormFields ?? [],
      updateVisible: state.updateVisible ?? existing?.updateVisible ?? false,
      updateFormData: state.updateFormData ?? existing?.updateFormData ?? {},
      updateFormFields: state.updateFormFields ?? existing?.updateFormFields ?? [],
      batchUpdateVisible: state.batchUpdateVisible ?? existing?.batchUpdateVisible ?? false,
      batchUpdateFormData: state.batchUpdateFormData ?? existing?.batchUpdateFormData ?? {},
      batchUpdateFormFields: state.batchUpdateFormFields ?? existing?.batchUpdateFormFields ?? [],
      addCommentVisible: state.addCommentVisible ?? existing?.addCommentVisible ?? false,
      viewCommentVisible: state.viewCommentVisible ?? existing?.viewCommentVisible ?? false,
      commentFormData: state.commentFormData ?? existing?.commentFormData ?? {},
      commentRemark: state.commentRemark ?? existing?.commentRemark ?? '',
      commentFields: state.commentFields ?? existing?.commentFields ?? [],
      commentList: state.commentList ?? existing?.commentList ?? [],
      timestamp: Date.now()
    };
    stateByKey.value.set(key, merged);
  }

  function clearState(functionCode: string, params: string) {
    stateByKey.value.delete(getKey(functionCode, params));
  }

  function clearAll() {
    stateByKey.value.clear();
  }

  return {
    stateByKey,
    getState,
    setState,
    clearState,
    clearAll
  };
});
