<script setup lang="ts">
import { NButton, NInput, NTag, NCard } from 'naive-ui';
import SvgIcon from '@/components/custom/svg-icon.vue';

defineProps<{
  showLeftArrow: boolean;
  showRightArrow: boolean;
  quickKeyword: string;
  functionCode: string;
  pageMeta: any;
  hasTableModifications: boolean;
  hasColorMarkEnabledColumns: boolean;
  updateLoading: boolean;
  batchUpdateLoading: boolean;
  deleteLoading: boolean;
}>();

const emit = defineEmits<{
  'update:quickKeyword': [value: string];
  scrollLeft: [];
  scrollRight: [];
  refresh: [];
  reset: [];
  openPinColumn: [];
  openFieldColumn: [];
  openCondition: [];
  dataDrill: [];
  openAddComment: [];
  openViewComment: [];
  openColorMark: [];
  openAdd: [];
  openUpdate: [];
  openBatchUpdate: [];
  delete: [];
  tableEditSubmit: [];
  handleImport: [];
  handleExport: [];
  handleDebug: [];
  checkScrollPosition: [];
}>();

defineExpose({
  checkScrollPosition: () => emit('checkScrollPosition')
});
</script>

<template>
  <NCard :bordered="false" :content-style="{ padding: '8px 10px' }" class="toolbar-card mb-6px rounded-12px shadow-sm">
    <div class="flex items-center gap-12px">
      <div class="flex items-center flex-1 min-w-0">
        <NButton
          v-if="showLeftArrow"
          quaternary
          circle
          size="small"
          class="scroll-arrow mr-8px"
          @click="emit('scrollLeft')"
        >
          <template #icon>
            <SvgIcon icon="material-symbols:chevron-left" />
          </template>
        </NButton>

        <div
          class="toolbar-scroll flex items-center gap-8px flex-nowrap overflow-x-hidden"
          @scroll="emit('checkScrollPosition')"
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
          <NButton v-if="pageMeta?.toolbar.import" @click="emit('handleImport')">导入</NButton>
          <NButton :disabled="!pageMeta?.toolbar.export" @click="emit('handleExport')">导出</NButton>
          <NButton v-if="pageMeta?.toolbar.debugSql" type="warning" class="debug-btn" @click="emit('handleDebug')">
            调试
          </NButton>
        </div>

        <NButton
          v-if="showRightArrow"
          quaternary
          circle
          size="small"
          class="scroll-arrow ml-8px"
          @click="emit('scrollRight')"
        >
          <template #icon>
            <SvgIcon icon="material-symbols:chevron-right" />
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
