import { computed, type ComputedRef, type Ref } from 'vue';

interface UseWorkbenchPageMetaFlagsOptions {
  pageMeta: Ref<Api.Workbench.PageMeta | null>;
}

/**
 * 工作台页面元数据（pageMeta）派生的布尔/列表标记。
 *
 * 这些标记用于控制工具栏按钮显隐、initial grid properties 等，
 * 全部为 pageMeta 的纯计算，不涉及请求与 UI 状态。
 */
export function useWorkbenchPageMetaFlags(options: UseWorkbenchPageMetaFlagsOptions) {
  const { pageMeta } = options;

  // 是否有整表修改权限
  const hasTableEditAuth = computed(() => pageMeta.value?.toolbar.tableEdit === true);

  // 可颜色标注的列
  const colorMarkEnabledColumns = computed(() => {
    return (pageMeta.value?.columns || [])
      .filter(column => column.colorMarkEnabled)
      .map(column => ({ label: column.title || column.field, value: column.field }));
  });

  // 是否有可颜色标注的列
  const hasColorMarkEnabledColumns = computed(() => colorMarkEnabledColumns.value.length > 0);

  // 是否存在可行合并列：决定是否启用 ag-grid cellSpan（initial property，创建后不可变更）
  const hasMergeableColumns = computed(() => {
    const columns = pageMeta.value?.columns || [];
    return columns.some(column => column.canMerge === true);
  });

  // grid 是否就绪（pageMeta 已加载），用于确保 initial properties 在创建时就正确
  const gridReady = computed(() => !!pageMeta.value);

  // 是否有图形模块配置
  const hasChartEnabled = computed(() => !!pageMeta.value?.chartModule && pageMeta.value.chartModule !== '');

  return {
    hasTableEditAuth,
    colorMarkEnabledColumns,
    hasColorMarkEnabledColumns,
    hasMergeableColumns,
    gridReady,
    hasChartEnabled
  };
}

export type PageMetaFlags = ReturnType<typeof useWorkbenchPageMetaFlags>;
