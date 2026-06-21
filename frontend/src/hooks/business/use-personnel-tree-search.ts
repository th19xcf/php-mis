import { ref, watch, type ComputedRef, type Ref } from 'vue';
import type { TreeOption } from 'naive-ui';

interface UsePersonnelTreeSearchOptions {
  /** 外部持久化的 searchKeyword（如 Pinia store），不传则使用组件内部 ref */
  searchKeyword?: Ref<string>;
  /** 外部持久化的 expandedKeys（如 Pinia store），不传则使用组件内部 ref */
  expandedKeys?: Ref<string[]>;
}

/**
 * 人员页统一用的「左侧树 + 搜索 + 过滤 + 展开」状态管理。
 *
 * 输入：源树数据（响应式 computed 或 ref）。
 * 输出：searchKeyword / filteredTreeData / expandedKeys 三个 ref，
 *      以及 handleSearch / clearSearch / handleExpandedKeysChange 三个回调，
 *      还有 filterTreeData 工具函数（供页面复用于自定义过滤场景）。
 *
 * 可通过 options 传入外部 ref（如 Pinia store 的 searchKeyword / expandedKeys），
 * 使状态在组件销毁后仍可持久化。
 *
 * 自动行为：
 *  - watch(searchKeyword)：根据输入实时过滤
 *  - watch(sourceTree)：当源树变化且未搜索时，同步过滤树
 */
export function usePersonnelTreeSearch(
  sourceTree: ComputedRef<TreeOption[]> | Ref<TreeOption[]>,
  options: UsePersonnelTreeSearchOptions = {}
) {
  const searchKeyword = options.searchKeyword ?? ref('');
  const filteredTreeData = ref<TreeOption[]>([]) as Ref<TreeOption[]>;
  const expandedKeys = options.expandedKeys ?? ref<string[]>([]);

  /**
   * 递归过滤树节点，返回过滤后的节点列表与应展开的 key 列表。
   */
  function filterTreeData(nodes: TreeOption[], keyword: string): { nodes: TreeOption[]; expanded: string[] } {
    const expanded: string[] = [];
    const lowerKeyword = keyword.toLowerCase();

    function filterNode(node: TreeOption): TreeOption | null {
      const label = (node.label as string) || '';
      const match = label.toLowerCase().includes(lowerKeyword);

      const filteredChildren: TreeOption[] = [];
      if (node.children) {
        for (const child of node.children as TreeOption[]) {
          const filtered = filterNode(child);
          if (filtered) {
            filteredChildren.push(filtered);
          }
        }
      }

      if (match || filteredChildren.length > 0) {
        if (filteredChildren.length > 0) {
          expanded.push(node.key as string);
        }
        return {
          ...node,
          children: filteredChildren.length > 0 ? filteredChildren : node.children
        };
      }

      return null;
    }

    const filtered = nodes.map(node => filterNode(node)).filter((n): n is TreeOption => n !== null);
    return { nodes: filtered, expanded };
  }

  function handleSearch() {
    if (!searchKeyword.value.trim()) {
      filteredTreeData.value = sourceTree.value;
      expandedKeys.value = [];
      return;
    }

    const { nodes, expanded } = filterTreeData(sourceTree.value, searchKeyword.value);
    filteredTreeData.value = nodes;
    expandedKeys.value = expanded;
  }

  function clearSearch() {
    searchKeyword.value = '';
    filteredTreeData.value = sourceTree.value;
    expandedKeys.value = [];
  }

  function handleExpandedKeysChange(keys: string[]) {
    expandedKeys.value = keys;
  }

  // 实时根据输入过滤（immediate 确保组件重建时能恢复搜索状态）
  watch(
    searchKeyword,
    newValue => {
      if (!newValue.trim()) {
        filteredTreeData.value = sourceTree.value;
        // 不清空 expandedKeys，保留用户之前的展开状态（组件重建时从 store 恢复）
      } else {
        const { nodes, expanded } = filterTreeData(sourceTree.value, newValue);
        filteredTreeData.value = nodes;
        expandedKeys.value = expanded;
      }
    },
    { immediate: true }
  );

  // 源树变化时，若未在搜索中，同步过滤树（immediate 确保组件重建时能从 store 恢复已有数据）
  watch(
    () => sourceTree.value,
    newData => {
      if (!searchKeyword.value.trim()) {
        filteredTreeData.value = newData;
      }
    },
    { immediate: true }
  );

  return {
    searchKeyword,
    filteredTreeData,
    expandedKeys,
    handleSearch,
    clearSearch,
    handleExpandedKeysChange,
    filterTreeData
  };
}
