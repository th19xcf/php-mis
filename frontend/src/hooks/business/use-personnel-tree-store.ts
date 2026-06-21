import { ref } from 'vue';
import type { TreeOption } from 'naive-ui';

/**
 * 递归把后端树节点（id / value / items）转成 naive-ui 用的 TreeOption。
 */
function convertToTreeOptions<T extends { id: string; value: string; items?: T[] }>(nodes: T[]): TreeOption[] {
  return nodes.map(n => ({
    key: n.id,
    label: n.value,
    data: n,
    children: n.items?.length ? convertToTreeOptions(n.items) : undefined
  }));
}

/**
 * personnel 模块（interview / train / invitation）tree store 公共能力。
 *
 * 取代三个 store 中完全重复的部分：
 *   treeData / checkedKeys / selectedGuids / expandedKeys / options /
 *   isLoaded / loading / loadTreeData / loadOptions（带缓存）/
 *   refreshTree / setExpandedKeys / setCheckedKeys / setSelectedGuids
 *
 * 调用方保留各自 detail 状态与 detail 加载方法（业务类型不同）。
 * 如需 clearSelection 包含 detail 清空，store 层覆盖 clearSelection 即可。
 *
 * 范例（interview store）：
 * ```ts
 * const tree = usePersonnelTreeStore(fetchInterviewTree, fetchInterviewOptions);
 * const interviewDetail = ref<Api.Interview.InterviewDetail | null>(null);
 * async function loadInterviewDetail(guid: string) { ... }
 * function clearSelection() {
 *   tree.clearSelection();
 *   interviewDetail.value = null;
 * }
 * return { ...tree, interviewDetail, loadInterviewDetail, clearSelection };
 * ```
 */
export function usePersonnelTreeStore<TNode extends { id: string; value: string; items?: TNode[] }, TOptions>(
  fetchTree: () => Promise<{ data: TNode[] | null; error?: unknown }>,
  fetchOptions: () => Promise<{ data: TOptions | null }>
) {
  const treeData = ref<TreeOption[]>([]);
  const checkedKeys = ref<string[]>([]);
  const selectedGuids = ref<string[]>([]);
  const expandedKeys = ref<string[]>([]);
  const searchKeyword = ref('');
  const options = ref<TOptions | null>(null);
  const isLoaded = ref(false);
  const loading = ref(false);

  async function loadTreeData() {
    loading.value = true;
    const { data, error } = await fetchTree();
    loading.value = false;

    if (!error && data) {
      treeData.value = convertToTreeOptions(data);
      isLoaded.value = true;
      // 首次加载时保持折叠（expandedKeys 为空即可）
    }
  }

  async function loadOptions() {
    if (options.value) return options.value;

    const { data } = await fetchOptions();
    if (data) {
      options.value = data;
    }
    return options.value;
  }

  function clearSelection() {
    checkedKeys.value = [];
    selectedGuids.value = [];
  }

  async function refreshTree() {
    isLoaded.value = false;
    treeData.value = [];
    checkedKeys.value = [];
    selectedGuids.value = [];
    expandedKeys.value = [];
    return loadTreeData();
  }

  function setExpandedKeys(keys: string[]) {
    expandedKeys.value = keys;
  }

  function setCheckedKeys(keys: string[]) {
    checkedKeys.value = keys;
  }

  function setSelectedGuids(guids: string[]) {
    selectedGuids.value = guids;
  }

  return {
    treeData,
    checkedKeys,
    selectedGuids,
    expandedKeys,
    searchKeyword,
    options,
    isLoaded,
    loading,
    loadTreeData,
    loadOptions,
    clearSelection,
    refreshTree,
    setExpandedKeys,
    setCheckedKeys,
    setSelectedGuids
  };
}
