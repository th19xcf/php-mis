<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import type { IHeaderParams } from 'ag-grid-community';

/**
 * 工作表格选列表头组件
 *  - 顶部 checkbox 实现"全选 / 全不选"切换
 *  - 视觉复用 ag-grid 自身的 .ag-checkbox-input-wrapper 样式类（与行 checkbox 视觉完全一致）
 *  - 状态：
 *      - 全不选 → 空心
 *      - 部分选中 → "-"（indeterminate）
 *      - 全部选中 → 实心
 *  - 点击：
 *      - 当前是"全不选"或"部分选中" → 全选所有已加载行
 *      - 当前是"全部选中" → 全不选
 *  - 仅作用于 gridApi 当前可见/已加载的节点（不含尚未分片拉取的远端数据）
 */
const props = defineProps<{
  params: IHeaderParams;
}>();

// 用一个递增 tick 触发 computed 重算（ag-grid API 不在 Vue 响应式系统内）
const tick = ref(0);

const allCount = computed(() => {
  // 依赖 tick：每次 grid 选择/数据变化都重新统计
  void tick.value;
  let count = 0;
  props.params.api.forEachNode(() => {
    count += 1;
  });
  return count;
});

const selectedCount = computed(() => {
  void tick.value;
  return props.params.api.getSelectedRows().length;
});

const headerState = computed<'checked' | 'unchecked' | 'indeterminate'>(() => {
  if (allCount.value === 0) return 'unchecked';
  if (selectedCount.value === 0) return 'unchecked';
  if (selectedCount.value >= allCount.value) return 'checked';
  return 'indeterminate';
});

function handleWrapperClick(e: MouseEvent) {
  // 阻止列头点击默认行为（排序 / 菜单等）
  e.stopPropagation();
  if (headerState.value === 'checked') {
    props.params.api.deselectAll();
  } else {
    props.params.api.selectAll();
  }
}

// 监听 grid 的选择 / 数据变化，刷新 tick 触发 computed 重算
let removeSelectionListener: (() => void) | null = null;
onMounted(() => {
  const handler = () => {
    tick.value += 1;
  };
  props.params.api.addEventListener('selectionChanged', handler);
  props.params.api.addEventListener('modelUpdated', handler);
  removeSelectionListener = () => {
    props.params.api.removeEventListener('selectionChanged', handler);
    props.params.api.removeEventListener('modelUpdated', handler);
  };
});

onBeforeUnmount(() => {
  removeSelectionListener?.();
});
</script>

<template>
  <div class="workbench-select-all-header" @click="handleWrapperClick">
    <!--
      复用 ag-grid 自身的 .ag-checkbox-input-wrapper / .ag-checked / .ag-indeterminate 样式
      使其视觉与原 ag-grid 全选 checkbox 完全一致（蓝色方框 + 白色 ✓/—）
    -->
    <div
      class="ag-checkbox-input-wrapper"
      :class="{
        'ag-checked': headerState === 'checked',
        'ag-indeterminate': headerState === 'indeterminate'
      }"
      :aria-label="headerState === 'checked' ? '取消全选' : '全选'"
      role="checkbox"
    />
  </div>
</template>

<style scoped>
.workbench-select-all-header {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  cursor: pointer;
  user-select: none;
}
</style>
