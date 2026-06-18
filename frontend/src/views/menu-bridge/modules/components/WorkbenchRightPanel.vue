<script setup lang="ts">
import { computed } from 'vue';
import { NButton, NSpin, NEmpty } from 'naive-ui';

import WorkbenchAddForm from './WorkbenchAddForm.vue';
import WorkbenchUpdateForm from './WorkbenchUpdateForm.vue';
import WorkbenchComment from './WorkbenchComment.vue';

/**
 * 右侧面板模式
 * - chart：图形展示
 * - add：新增记录
 * - update：单条修改
 * - batch：多条修改
 * - comment：批注（添加 / 查看）
 */
type RightPanelMode = 'chart' | 'add' | 'update' | 'batch' | 'comment' | null;

interface Props {
  /** 当前激活的面板模式 */
  mode: RightPanelMode;
  isDarkMode: boolean;
  /** 左侧面板宽度百分比（用于 flex 分配） */
  activeLeftWidth: number;
  /** 是否正在拖动分隔条 */
  anyRightPanelResizing: boolean;
  /** 图形面板是否最大化 */
  chartMaximized: boolean;
  /** 编辑面板是否最大化 */
  editPanelMaximized: boolean;

  // —— 图形分支 ——
  chartOptions: any[];
  chartLoading: boolean;
  isDrilled: boolean;
  drillLevel: number;
  /** 是否显示图形调试按钮 */
  hasChartDebug: boolean;

  // —— 新增分支 ——
  addVisible: boolean;
  addLoading: boolean;
  addError: string;
  addSuccess: string;
  addFormFields: any[];
  addFormData: Record<string, any>;

  // —— 单条修改分支 ——
  updateVisible: boolean;
  updateLoading: boolean;
  updateError: string;
  updateSuccess: string;
  updateFormFields: any[];
  updateFormData: Record<string, any>;

  // —— 多条修改分支 ——
  batchUpdateVisible: boolean;
  batchUpdateLoading: boolean;
  batchUpdateError: string;
  batchUpdateSuccess: string;
  batchUpdateFormFields: any[];
  batchUpdateFormData: Record<string, any>;

  // —— 批注分支 ——
  addCommentVisible: boolean;
  viewCommentVisible: boolean;
  commentLoading: boolean;
  commentFields: any[];
  commentFormData: Record<string, any>;
  commentList: any[];
  commentModuleName: string;
  commentRemark: string;
  keyFieldList: any[];
  keyFieldCount: number;
}

const props = defineProps<Props>();

const emit = defineEmits<{
  'update:chartMaximized': [boolean];
  'update:editPanelMaximized': [boolean];
  'update:addFormData': [Record<string, any>];
  'update:updateFormData': [Record<string, any>];
  'update:batchUpdateFormData': [Record<string, any>];
  'update:commentRemark': [string];
  close: [];
  refreshChart: [];
  resetDrill: [];
  chartDebug: [];
  setChartRef: [el: any, index: number];
  confirmAdd: [];
  confirmUpdate: [];
  confirmBatchUpdate: [];
  submitComment: [];
  openPopup: [field: any];
  clearAdd: [];
  clearUpdate: [];
  clearBatch: [];
  clearComment: [];
  addSample: [];
  splitterMousedown: [MouseEvent];
}>();

const visible = computed(() => props.mode !== null);

/**
 * 右侧面板宽度样式
 * - 最大化时占满整行
 * - 否则按 activeLeftWidth 分配剩余宽度
 */
const rightPanelFlex = computed(() => {
  const isChartMax = props.chartMaximized && props.mode === 'chart';
  const isEditMax = props.editPanelMaximized && props.mode !== 'chart';
  if (isChartMax || isEditMax) return '1';
  return `0 0 ${100 - props.activeLeftWidth}%`;
});
</script>

<template>
  <!-- 可拖动分隔条（chart 与 edit 模式共用） -->
  <div
    v-if="visible && !chartMaximized && !editPanelMaximized"
    class="resize-splitter"
    :class="{ 'is-resizing': anyRightPanelResizing }"
    title="拖动调整宽度"
    @mousedown="emit('splitterMousedown', $event)"
  >
    <div class="resize-line" />
  </div>

  <!-- 右侧分栏：chart / 新增 / 单条修改 / 多条修改 / 批注 互斥 -->
  <div v-show="visible" class="chart-area" :style="{ flex: rightPanelFlex }">
    <!-- 图形模式 -->
    <div v-if="mode === 'chart'" class="chart-panel rounded-12px shadow-sm">
      <div class="chart-header">
        <span class="chart-title">
          <span class="title-text">图形展示</span>
          <span class="title-divider">|</span>
          <span class="drill-badge">{{ isDrilled ? `钻取第 ${drillLevel} 级` : '初始图形' }}</span>
        </span>
        <div class="flex flex-row gap-8px">
          <NButton v-if="isDrilled" size="small" type="primary" @click="emit('resetDrill')">初始图形</NButton>
          <NButton v-else size="small" type="default" @click="emit('refreshChart')">刷新</NButton>
          <NButton size="small" type="default" @click="emit('update:chartMaximized', !chartMaximized)">
            {{ chartMaximized ? '恢复' : '扩大' }}
          </NButton>
          <NButton v-if="hasChartDebug" size="small" type="warning" @click="emit('chartDebug')">调试</NButton>
          <NButton size="small" @click="emit('close')">关闭</NButton>
        </div>
      </div>
      <div class="chart-container">
        <NSpin :show="chartLoading">
          <template v-if="chartOptions.length > 0">
            <div
              v-for="(option, index) in chartOptions"
              :key="index"
              :ref="el => emit('setChartRef', el as any, index)"
              class="chart-wrapper"
              :class="[option.chartLayout || 'box_1-1-1']"
            ></div>
          </template>
          <NEmpty v-else-if="!chartLoading" description="暂无图形数据" />
        </NSpin>
      </div>
    </div>

    <!-- 新增模式 -->
    <WorkbenchAddForm
      v-else-if="mode === 'add' && addVisible"
      :loading="addLoading"
      :error="addError"
      :success="addSuccess"
      :form-fields="addFormFields"
      :form-data="addFormData"
      :is-dark-mode="isDarkMode"
      :is-maximized="editPanelMaximized"
      @update:form-data="emit('update:addFormData', $event)"
      @confirm="emit('confirmAdd')"
      @open-popup="emit('openPopup', $event)"
      @close="emit('clearAdd')"
      @toggle-maximize="emit('update:editPanelMaximized', !editPanelMaximized)"
      @add-sample="emit('addSample')"
    />

    <!-- 单条修改模式 -->
    <WorkbenchUpdateForm
      v-else-if="mode === 'update' && updateVisible"
      :loading="updateLoading"
      :error="updateError"
      :success="updateSuccess"
      :form-fields="updateFormFields"
      :form-data="updateFormData"
      :is-dark-mode="isDarkMode"
      :is-maximized="editPanelMaximized"
      @update:form-data="emit('update:updateFormData', $event)"
      @confirm="emit('confirmUpdate')"
      @open-popup="emit('openPopup', $event)"
      @close="emit('clearUpdate')"
      @toggle-maximize="emit('update:editPanelMaximized', !editPanelMaximized)"
    />

    <!-- 多条修改模式 -->
    <WorkbenchUpdateForm
      v-else-if="mode === 'batch' && batchUpdateVisible"
      :is-batch="true"
      :loading="batchUpdateLoading"
      :error="batchUpdateError"
      :success="batchUpdateSuccess"
      :form-fields="batchUpdateFormFields"
      :form-data="batchUpdateFormData"
      :is-dark-mode="isDarkMode"
      :is-maximized="editPanelMaximized"
      @update:form-data="emit('update:batchUpdateFormData', $event)"
      @confirm="emit('confirmBatchUpdate')"
      @open-popup="emit('openPopup', $event)"
      @close="emit('clearBatch')"
      @toggle-maximize="emit('update:editPanelMaximized', !editPanelMaximized)"
    />

    <!-- 批注模式（添加 / 查看 互斥） -->
    <WorkbenchComment
      v-else-if="mode === 'comment' && (addCommentVisible || viewCommentVisible)"
      :add-visible="addCommentVisible"
      :view-visible="viewCommentVisible"
      :loading="commentLoading"
      :fields="commentFields"
      :form-data="commentFormData"
      :list="commentList"
      :module-name="commentModuleName"
      :remark="commentRemark"
      :key-field-list="keyFieldList"
      :key-field-count="keyFieldCount"
      :is-dark-mode="isDarkMode"
      :is-maximized="editPanelMaximized"
      @update:remark="emit('update:commentRemark', $event)"
      @submit="emit('submitComment')"
      @close="emit('clearComment')"
      @toggle-maximize="emit('update:editPanelMaximized', !editPanelMaximized)"
    />
  </div>
</template>

<style lang="scss">
@use '../styles/workbench-right-panel.scss';
</style>
