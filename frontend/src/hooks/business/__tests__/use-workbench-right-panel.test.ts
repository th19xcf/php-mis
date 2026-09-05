import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { computed, nextTick, ref } from 'vue';
import { useWorkbenchRightPanelStore } from '@/store/modules/workbench-right-panel';
import { useWorkbenchRightPanel } from '../use-workbench-right-panel';

/** 构造 composable 全量入参（默认值） */
function makeOptions() {
  return {
    functionCode: computed(() => '2025'),
    params: computed(() => ''),
    addVisible: ref(false),
    addFormData: ref<Record<string, any>>({}),
    addFormFields: ref<any[]>([]),
    updateVisible: ref(false),
    updateFormData: ref<Record<string, any>>({}),
    updateFormFields: ref<any[]>([]),
    batchUpdateVisible: ref(false),
    batchUpdateFormData: ref<Record<string, any>>({}),
    batchUpdateFormFields: ref<any[]>([]),
    addDirty: ref(false),
    updateDirty: ref(false),
    batchUpdateDirty: ref(false),
    addCommentVisible: ref(false),
    viewCommentVisible: ref(false),
    commentFormData: ref<Record<string, any>>({}),
    commentRemark: ref(''),
    commentFields: ref<any[]>([]),
    commentList: ref<any[]>([])
  };
}

describe('useWorkbenchRightPanel（右栏状态机）', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  afterEach(() => {
    // 清理 window.$dialog mock
    (window as any).$dialog = undefined;
  });

  it('初始状态：rightPanelMode 为 null，rightPanelVisible 为 false', () => {
    const opts = makeOptions();
    const { rightPanelMode, rightPanelVisible } = useWorkbenchRightPanel(opts);
    expect(rightPanelMode.value).toBeNull();
    expect(rightPanelVisible.value).toBe(false);
  });

  it('rightPanelVisible 随 rightPanelMode 联动', () => {
    const opts = makeOptions();
    const { rightPanelMode, rightPanelVisible } = useWorkbenchRightPanel(opts);
    rightPanelMode.value = 'add';
    expect(rightPanelVisible.value).toBe(true);
    rightPanelMode.value = null;
    expect(rightPanelVisible.value).toBe(false);
  });

  it('持久化 round-trip：refs 变更后 watch 写入 store，15 字段逐一一致', async () => {
    const opts = makeOptions();
    const { rightPanelMode } = useWorkbenchRightPanel(opts);

    // 修改全部被监听的 ref（15 字段 + mode）
    rightPanelMode.value = 'add';
    opts.addVisible.value = true;
    opts.addFormData.value = { 姓名: '张三' };
    opts.addFormFields.value = [{ fieldName: '姓名' }];
    opts.updateVisible.value = true;
    opts.updateFormData.value = { 年龄: 25 };
    opts.updateFormFields.value = [{ fieldName: '年龄' }];
    opts.batchUpdateVisible.value = true;
    opts.batchUpdateFormData.value = { 部门: 'A' };
    opts.batchUpdateFormFields.value = [{ fieldName: '部门' }];
    opts.addCommentVisible.value = true;
    opts.viewCommentVisible.value = true;
    opts.commentFormData.value = { 备注: 'r' };
    opts.commentRemark.value = '备注内容';
    opts.commentFields.value = [{ field: 'f' }];
    opts.commentList.value = [{ id: 1 }];

    await nextTick();

    const store = useWorkbenchRightPanelStore();
    const saved = store.getState('2025', '');
    expect(saved).toBeDefined();
    expect(saved!.rightPanelMode).toBe('add');
    expect(saved!.addVisible).toBe(true);
    expect(saved!.addFormData).toEqual({ 姓名: '张三' });
    expect(saved!.addFormFields).toEqual([{ fieldName: '姓名' }]);
    expect(saved!.updateVisible).toBe(true);
    expect(saved!.updateFormData).toEqual({ 年龄: 25 });
    expect(saved!.updateFormFields).toEqual([{ fieldName: '年龄' }]);
    expect(saved!.batchUpdateVisible).toBe(true);
    expect(saved!.batchUpdateFormData).toEqual({ 部门: 'A' });
    expect(saved!.batchUpdateFormFields).toEqual([{ fieldName: '部门' }]);
    expect(saved!.addCommentVisible).toBe(true);
    expect(saved!.viewCommentVisible).toBe(true);
    expect(saved!.commentFormData).toEqual({ 备注: 'r' });
    expect(saved!.commentRemark).toBe('备注内容');
    expect(saved!.commentFields).toEqual([{ field: 'f' }]);
    expect(saved!.commentList).toEqual([{ id: 1 }]);
  });

  it('functionCode 为空时 watch 不写入 store', async () => {
    const opts = makeOptions();
    opts.functionCode = computed(() => '');
    useWorkbenchRightPanel(opts);

    opts.addVisible.value = true;
    await nextTick();

    const store = useWorkbenchRightPanelStore();
    expect(store.getState('', '')).toBeUndefined();
  });

  it('恢复：store 中存在同 key 状态时，refs 被覆盖', () => {
    const store = useWorkbenchRightPanelStore();
    store.setState('2025', '', {
      rightPanelMode: 'add',
      addVisible: true,
      addFormData: { 姓名: '李四' }
    });

    const opts = makeOptions();
    const { rightPanelMode } = useWorkbenchRightPanel(opts);

    expect(rightPanelMode.value).toBe('add');
    expect(opts.addVisible.value).toBe(true);
    expect(opts.addFormData.value).toEqual({ 姓名: '李四' });
  });

  it('恢复：store 无缓存时，refs 保持默认值（首次进入不被污染）', () => {
    const opts = makeOptions();
    const { rightPanelMode } = useWorkbenchRightPanel(opts);
    expect(rightPanelMode.value).toBeNull();
    expect(opts.addVisible.value).toBe(false);
  });

  it('缓存按 (functionCode, params) 隔离：不同 key 互不干扰', () => {
    const store = useWorkbenchRightPanelStore();
    store.setState('2025', 'a', { rightPanelMode: 'update' });

    const opts = makeOptions();
    opts.params = computed(() => 'b');
    const { rightPanelMode } = useWorkbenchRightPanel(opts);
    expect(rightPanelMode.value).toBeNull();
  });

  describe('confirmDiscardIfDirty（未保存修改确认）', () => {
    it('无 dirty 时直接返回 true（不弹窗）', async () => {
      const opts = makeOptions();
      const { rightPanelMode, confirmDiscardIfDirty } = useWorkbenchRightPanel(opts);
      rightPanelMode.value = 'add';
      opts.addDirty.value = false;
      await expect(confirmDiscardIfDirty()).resolves.toBe(true);
    });

    it('mode 与表单不对应时（如 chart 模式）直接返回 true', async () => {
      const opts = makeOptions();
      const { rightPanelMode, confirmDiscardIfDirty } = useWorkbenchRightPanel(opts);
      rightPanelMode.value = 'chart';
      opts.addDirty.value = true; // chart 模式不检查表单 dirty
      await expect(confirmDiscardIfDirty()).resolves.toBe(true);
    });

    it.each([
      { mode: 'add' as const, dirtyKey: 'addDirty' as const },
      { mode: 'update' as const, dirtyKey: 'updateDirty' as const },
      { mode: 'batch' as const, dirtyKey: 'batchUpdateDirty' as const }
    ])('dirty 的 $mode 模式弹确认框：确认离开返回 true', async ({ mode, dirtyKey }) => {
      const opts = makeOptions();
      const { rightPanelMode, confirmDiscardIfDirty } = useWorkbenchRightPanel(opts);
      rightPanelMode.value = mode;
      opts[dirtyKey].value = true;

      let dialogOptions: any;
      (window as any).$dialog = {
        warning: (o: any) => {
          dialogOptions = o;
        }
      };

      const promise = confirmDiscardIfDirty();
      dialogOptions.onPositiveClick();
      await expect(promise).resolves.toBe(true);
    });

    it('dirty 弹窗点取消返回 false', async () => {
      const opts = makeOptions();
      const { rightPanelMode, confirmDiscardIfDirty } = useWorkbenchRightPanel(opts);
      rightPanelMode.value = 'add';
      opts.addDirty.value = true;

      let dialogOptions: any;
      (window as any).$dialog = {
        warning: (o: any) => {
          dialogOptions = o;
        }
      };

      const promise = confirmDiscardIfDirty();
      dialogOptions.onNegativeClick();
      await expect(promise).resolves.toBe(false);
    });

    it('遮罩点击与关闭同样视为取消（返回 false）', async () => {
      const opts = makeOptions();
      const { rightPanelMode, confirmDiscardIfDirty } = useWorkbenchRightPanel(opts);
      rightPanelMode.value = 'update';
      opts.updateDirty.value = true;

      let dialogOptions: any;
      (window as any).$dialog = {
        warning: (o: any) => {
          dialogOptions = o;
        }
      };

      const promise = confirmDiscardIfDirty();
      dialogOptions.onMaskClick();
      await expect(promise).resolves.toBe(false);
    });

    it('window.$dialog 未注册时不抛异常（Promise 悬挂，与线上行为一致）', async () => {
      const opts = makeOptions();
      const { rightPanelMode, confirmDiscardIfDirty } = useWorkbenchRightPanel(opts);
      rightPanelMode.value = 'add';
      opts.addDirty.value = true;
      (window as any).$dialog = undefined;

      const promise = confirmDiscardIfDirty();
      // 50ms 内未 resolve 视为悬挂（optional chaining 下无弹窗回调来源）
      const result = await Promise.race([promise, new Promise(r => setTimeout(() => r('pending'), 50))]);
      expect(result).toBe('pending');
    });
  });
});
