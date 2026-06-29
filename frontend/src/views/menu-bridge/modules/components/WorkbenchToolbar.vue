<script setup lang="ts">
import { nextTick, onMounted, onUnmounted, ref, computed } from 'vue';
import { NButton, NInput, NTag, NCard, NDropdown } from 'naive-ui';
import SvgIcon from '@/components/custom/svg-icon.vue';

defineProps<{
  quickKeyword: string;
  functionCode: string;
  pageMeta: any;
  hasTableModifications: boolean;
  hasColorMarkEnabledColumns: boolean;
  hasChartEnabled: boolean;
  updateLoading: boolean;
  batchUpdateLoading: boolean;
  deleteLoading: boolean;
}>();

const emit = defineEmits<{
  'update:quickKeyword': [value: string];
  refresh: [];
  reset: [];
  openPinColumn: [];
  openFieldColumn: [];
  openCondition: [];
  dataDrill: [];
  openAddComment: [];
  openViewComment: [];
  openColorMark: [];
  openChart: [];
  openAdd: [];
  openUpdate: [];
  openBatchUpdate: [];
  delete: [];
  tableEditSubmit: [];
  handleImport: [];
  handleExport: [exportAll: boolean];
  handleDebug: [];
  upkeep: [];
}>();

const exportAll = ref<string>('true');

const exportOptions = [
  { label: '导出全部', key: 'true' },
  { label: '导出筛选', key: 'false' }
];

function handleExport() {
  emit('handleExport', exportAll.value === 'true');
}

// 在子组件内部管理滚动状态，ref 指向自己的 DOM
const toolbarScrollRef = ref<HTMLDivElement | null>(null);
const showLeftArrow = ref(false);
const showRightArrow = ref(false);
let resizeObserver: ResizeObserver | null = null;

function checkScrollPosition() {
  nextTick(() => {
    if (!toolbarScrollRef.value) return;
    const { scrollWidth, clientWidth } = toolbarScrollRef.value;
    const hasOverflow = scrollWidth > clientWidth;
    // 溢出时同时显示左右箭头，方便用户发现两侧都有未显示内容
    showLeftArrow.value = hasOverflow;
    showRightArrow.value = hasOverflow;
  });
}

function scrollToolbar(direction: 'left' | 'right') {
  if (!toolbarScrollRef.value) return;
  const scrollAmount = 200;
  const target =
    direction === 'left'
      ? toolbarScrollRef.value.scrollLeft - scrollAmount
      : toolbarScrollRef.value.scrollLeft + scrollAmount;
  toolbarScrollRef.value.scrollTo({ left: target, behavior: 'smooth' });
}

onMounted(() => {
  setTimeout(() => checkScrollPosition(), 100);
  window.addEventListener('resize', checkScrollPosition);
  if (toolbarScrollRef.value && typeof ResizeObserver !== 'undefined') {
    resizeObserver = new ResizeObserver(checkScrollPosition);
    resizeObserver.observe(toolbarScrollRef.value);
  }
});

onUnmounted(() => {
  window.removeEventListener('resize', checkScrollPosition);
  resizeObserver?.disconnect();
  resizeObserver = null;
});

defineExpose({ checkScrollPosition });
</script>

<template>
  <NCard :bordered="false" :content-style="{ padding: '8px 10px' }" class="toolbar-card mb-6px rounded-12px shadow-sm">
    <div class="flex items-center gap-12px">
      <div class="flex items-center flex-1 min-w-0">
        <NButton
          v-show="showLeftArrow"
          quaternary
          circle
          size="small"
          class="scroll-arrow mr-8px"
          @click="scrollToolbar('left')"
        >
          <template #icon>
            <SvgIcon icon="ant-design:left-outlined" />
          </template>
        </NButton>

        <div
          ref="toolbarScrollRef"
          class="toolbar-scroll flex items-center gap-8px flex-nowrap overflow-x-hidden"
          @scroll="checkScrollPosition"
        >
          <NButton @click="emit('refresh')">刷新</NButton>
          <NButton @click="emit('reset')">重置</NButton>
          <NButton @click="emit('openPinColumn')">固定列</NButton>
          <NButton @click="emit('openFieldColumn')">字段选择</NButton>
          <NButton @click="emit('openCondition')">条件面板</NButton>
          <NButton @click="emit('dataDrill')">数据钻取</NButton>
          <NButton v-if="pageMeta?.toolbar.comment" @click="emit('openAddComment')">添加批注</NButton>
          <NButton v-if="pageMeta?.toolbar.comment" @click="emit('openViewComment')">查看批注</NButton>
          <NButton v-if="hasColorMarkEnabledColumns" @click="emit('openColorMark')">颜色标注</NButton>
          <NButton v-if="hasChartEnabled" @click="emit('openChart')">图形</NButton>
          <NButton v-if="pageMeta?.toolbar.add" @click="emit('openAdd')">新增</NButton>
          <NButton v-if="pageMeta?.toolbar.edit" :disabled="updateLoading" @click="emit('openUpdate')">
            单条修改
          </NButton>
          <NButton v-if="pageMeta?.toolbar.batchEdit" :disabled="batchUpdateLoading" @click="emit('openBatchUpdate')">
            多条修改
          </NButton>
          <NButton v-if="pageMeta?.toolbar.delete" :disabled="deleteLoading" @click="emit('delete')">删除</NButton>
          <NButton
            v-if="pageMeta?.toolbar.tableEdit"
            :disabled="!hasTableModifications"
            @click="emit('tableEditSubmit')"
          >
            表级修改提交
          </NButton>
          <NButton v-if="pageMeta?.toolbar.upkeep" @click="emit('upkeep')">数据整理</NButton>
          <NButton v-if="pageMeta?.toolbar.import" @click="emit('handleImport')">导入</NButton>
          <NDropdown
            :options="exportOptions"
            @select="(key: string) => exportAll = key"
          >
            <NButton :disabled="!pageMeta?.toolbar.export">
              {{ exportAll === 'true' ? '导出全部' : '导出筛选' }}
              <SvgIcon icon="ant-design:down-outlined" class="ml-4px" />
            </NButton>
          </NDropdown>
          <NButton
            :disabled="!pageMeta?.toolbar.export"
            @click="handleExport"
          >
            导出
          </NButton>
          <NButton v-if="pageMeta?.toolbar.debugSql" type="warning" class="debug-btn" @click="emit('handleDebug')">
            调试
          </NButton>
        </div>

        <NButton
          v-show="showRightArrow"
          quaternary
          circle
          size="small"
          class="scroll-arrow ml-8px"
          @click="scrollToolbar('right')"
        >
          <template #icon>
            <SvgIcon icon="ant-design:right-outlined" />
          </template>
        </NButton>
      </div>

      <div class="flex items-center gap-12px flex-shrink-0">
        <NInput
          :value="quickKeyword"
          clearable
          placeholder="快速检索当前结果"
          class="w-280px"
          @update:value="emit('update:quickKeyword', $event)"
        />
        <NTag type="success" size="small">{{ functionCode }}</NTag>
      </div>
    </div>
  </NCard>
</template>

<style scoped>
.toolbar-scroll {
  flex: 1;
  min-width: 0;
  scrollbar-width: none;
  -ms-overflow-style: none;
}

.toolbar-scroll::-webkit-scrollbar {
  display: none;
}

.scroll-arrow {
  flex-shrink: 0;
  color: var(--n-text-color);
  transition: opacity 0.2s;
}

.scroll-arrow:hover {
  color: var(--n-primary-color);
}

.debug-btn {
  --n-color: #e6a23c;
  --n-color-hover: #ebb563;
  --n-color-pressed: #cf9236;
  --n-text-color: #ffffff;
  --n-text-color-hover: #ffffff;
  --n-text-color-pressed: #ffffff;
  --n-border: 1px solid #e6a23c;
  --n-border-hover: 1px solid #ebb563;
  --n-border-pressed: 1px solid #cf9236;
}

.debug-btn:hover {
  --n-color: #ebb563;
}

.debug-btn:active {
  --n-color: #cf9236;
}
</style>
