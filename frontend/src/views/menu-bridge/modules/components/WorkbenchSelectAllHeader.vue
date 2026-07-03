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
 *      - 当前是"全不选"或"部分选中" → 全选所有过滤后可见行
 *      - 当前是"全部选中" → 全不选
 *  - 仅作用于过滤后可见的行（被列筛选/快速检索过滤掉的行不参与全选）
 *  - 仅作用于已加载到 grid 的行（不含尚未分片拉取的远端数据）
 */
const props = defineProps<{
  params: IHeaderParams;
}>();

// 用一个递增 tick 触发 computed 重算（ag-grid API 不在 Vue 响应式系统内）
const tick = ref(0);

const allCount = computed(() => {
  void tick.value;
  let count = 0;
  props.params.api.forEachNodeAfterFilter(() => {
    count += 1;
  });
  return count;
});

const selectedCount = computed(() => {
  void tick.value;
  let count = 0;
  props.params.api.forEachNodeAfterFilter(node => {
    if (node.isSelected()) {
      count += 1;
    }
  });
  return count;
});

const headerState = computed<'checked' | 'unchecked' | 'indeterminate'>(() => {
  if (allCount.value === 0) return 'unchecked';
  if (selectedCount.value === 0) return 'unchecked';
  if (selectedCount.value >= allCount.value) return 'checked';
  return 'indeterminate';
});

function handleWrapperClick(e: MouseEvent) {
  e.stopPropagation();
  if (headerState.value === 'checked') {
    props.params.api.deselectAll();
  } else {
    props.params.api.deselectAll();
    props.params.api.forEachNodeAfterFilter(node => node.setSelected(true, false));
  }
}

// 监听 grid 的选择 / 数据 / 过滤变化，刷新 tick 触发 computed 重算
let removeSelectionListener: (() => void) | null = null;
onMounted(() => {
  const handler = () => {
    tick.value += 1;
  };
  props.params.api.addEventListener('selectionChanged', handler);
  props.params.api.addEventListener('modelUpdated', handler);
  props.params.api.addEventListener('filterChanged', handler);
  removeSelectionListener = () => {
    props.params.api.removeEventListener('selectionChanged', handler);
    props.params.api.removeEventListener('modelUpdated', handler);
    props.params.api.removeEventListener('filterChanged', handler);
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
