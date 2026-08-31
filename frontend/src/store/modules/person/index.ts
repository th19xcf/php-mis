import { defineStore } from 'pinia';
import { ref } from 'vue';
import { fetchPersonTree, fetchPersonDetail, fetchPersonOptions } from '@/service/api';
import { usePersonnelTreeStore } from '@/hooks/business/use-personnel-tree-store';

/**
 * 人员主档 (2060) 状态管理。
 *
 * 布局与邀约 (2015) 一致：左树（属地 → 发码年 → 发码月 → 人员叶子，年月取自人员编码）
 * + 右详情 / 新增 / 多条修改。合并 / 查重作为详情按钮区的动作保留。
 * 渠道层级已取消：渠道是招聘事件属性，主档不承载（权威数据在阶段表）。
 */
export const usePersonStore = defineStore('person-store', () => {
  // 2060 首次加载：工作台 meta / 字段配置 / 下拉 / 树 任何一项可能暂时未就绪，
  // 初始化阶段若失败，不在 naive-ui 弹错误 toast（仍会在 dev console 输出），
  // 避免用户一进 2060 就满屏红弹。用户主动操作（新增/修改/删除/合并按钮）
  // 的请求仍保持 toast（不在此处包装即可生效）。
  // 另外把 skipAuthError 也带上：预加载阶段若后端返回 401/8888，通常是旧 PHP 进程
  // 或代理转发异常导致的伪鉴权失败，误 resetStore 会把后面并发请求连锁打挂。
  const silentTree    = (extra: Record<string, any> = {}) =>
    fetchPersonTree({ skipErrorToast: true, skipAuthError: true, ...extra });
  const silentOptions = (extra: Record<string, any> = {}) =>
    fetchPersonOptions({ skipErrorToast: true, skipAuthError: true, ...extra });
  const tree = usePersonnelTreeStore(silentTree, silentOptions);

  // 详情（按 GUID 加载的人员主档行）
  const personDetail = ref<Api.Person.PersonDetail | null>(null);

  // 新增模式
  const isAddingMode = ref(false);
  const addFormDynamic = ref<Record<string, any>>({});
  const addFields = ref<Api.Workbench.AddField[]>([]);

  // 多条修改模式
  const isBatchEditMode = ref(false);
  const batchEditForm = ref<Record<string, any>>({});
  const batchEditFields = ref<Api.Workbench.AddField[]>([]);

  async function loadPersonDetail(guid: string) {
    const { data } = await fetchPersonDetail(guid);
    if (data) {
      personDetail.value = data;
    }
  }

  function clearSelection() {
    tree.clearSelection();
    personDetail.value = null;
  }

  // 新增模式 setter
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

  // 批量修改 setter
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

  return {
    treeData: tree.treeData,
    checkedKeys: tree.checkedKeys,
    selectedGuids: tree.selectedGuids,
    expandedKeys: tree.expandedKeys,
    searchKeyword: tree.searchKeyword,
    options: tree.options,
    isLoaded: tree.isLoaded,
    loading: tree.loading,
    personDetail,
    isAddingMode,
    addFormDynamic,
    addFields,
    isBatchEditMode,
    batchEditForm,
    batchEditFields,
    loadTreeData: tree.loadTreeData,
    loadOptions: tree.loadOptions,
    clearSelection,
    refreshTree: tree.refreshTree,
    setExpandedKeys: tree.setExpandedKeys,
    setCheckedKeys: tree.setCheckedKeys,
    setSelectedGuids: tree.setSelectedGuids,
    loadPersonDetail,
    setAddingMode,
    setAddFormDynamic,
    setAddFields,
    clearAddState,
    setBatchEditMode,
    setBatchEditForm,
    setBatchEditFields,
    clearBatchEditState
  };
});
