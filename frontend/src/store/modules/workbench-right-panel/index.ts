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
    // 关键：用 'field' in state 严格判断字段是否被显式提供，
    // 避免 `state.x ?? existing?.x` 把合法 null / false / [] / {} 误回退到旧值。
    // 历史 bug：rightPanelMode 关闭视窗时显式传 null，被 ?? 错误回退到 existing 的 'add'，
    // 导致"关闭标签页后再打开标签，右栏容器残留为空白壳子"。
    // 说明：state 是 Partial<T>，TS 没法通过 'field' in state 把 state.field 收窄成非 undefined，
    // 但运行时只要 key 存在就一定有值（即便值是合法的 null/false/[]/{}），这里用 ! 断言。
    const has = <K extends keyof WorkbenchRightPanelState>(k: K) => k in state;
    const pick = <K extends keyof WorkbenchRightPanelState>(k: K) => (state as WorkbenchRightPanelState)[k];
    const merged: WorkbenchRightPanelState = {
      rightPanelMode: has('rightPanelMode') ? pick('rightPanelMode') : (existing?.rightPanelMode ?? null),
      addVisible: has('addVisible') ? pick('addVisible') : (existing?.addVisible ?? false),
      addFormData: has('addFormData') ? pick('addFormData') : (existing?.addFormData ?? {}),
      addFormFields: has('addFormFields') ? pick('addFormFields') : (existing?.addFormFields ?? []),
      updateVisible: has('updateVisible') ? pick('updateVisible') : (existing?.updateVisible ?? false),
      updateFormData: has('updateFormData') ? pick('updateFormData') : (existing?.updateFormData ?? {}),
      updateFormFields: has('updateFormFields') ? pick('updateFormFields') : (existing?.updateFormFields ?? []),
      batchUpdateVisible: has('batchUpdateVisible') ? pick('batchUpdateVisible') : (existing?.batchUpdateVisible ?? false),
      batchUpdateFormData: has('batchUpdateFormData') ? pick('batchUpdateFormData') : (existing?.batchUpdateFormData ?? {}),
      batchUpdateFormFields: has('batchUpdateFormFields') ? pick('batchUpdateFormFields') : (existing?.batchUpdateFormFields ?? []),
      addCommentVisible: has('addCommentVisible') ? pick('addCommentVisible') : (existing?.addCommentVisible ?? false),
      viewCommentVisible: has('viewCommentVisible') ? pick('viewCommentVisible') : (existing?.viewCommentVisible ?? false),
      commentFormData: has('commentFormData') ? pick('commentFormData') : (existing?.commentFormData ?? {}),
      commentRemark: has('commentRemark') ? pick('commentRemark') : (existing?.commentRemark ?? ''),
      commentFields: has('commentFields') ? pick('commentFields') : (existing?.commentFields ?? []),
      commentList: has('commentList') ? pick('commentList') : (existing?.commentList ?? []),
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
