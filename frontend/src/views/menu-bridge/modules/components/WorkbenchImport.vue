<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { NModal, NSpin, NSpace, NButton, NAlert, NDataTable, NRadioGroup, NRadio } from 'naive-ui';

const props = defineProps<{
  visible: boolean;
  loading: boolean;
  previewData: any[];
  error: string | null;
  success: { message: string } | null;
  previewColumns: any[];
  isDarkMode: boolean;
  /** 人员主档软命中行（非空时展示决策页，逐行选择挂接既有档案或新建） */
  softRows?: Api.Workbench.PersonSoftRow[];
}>();

const emit = defineEmits<{
  'update:visible': [value: boolean];
  triggerFileInput: [];
  downloadTemplate: [];
  reset: [];
  /** decisions: 行号 => 人员编码 | '__NEW__'，软命中决策页提交时携带 */
  confirm: [decisions?: Record<string, string>];
}>();

/** 前端决策值：确认新建新主档（与后端 PersonImportService::DECISION_NEW 一致） */
const DECISION_NEW = '__NEW__';

/** 软命中行决策 {seq: 人员编码 | '__NEW__'} */
const personDecisions = ref<Record<string, string>>({});

const isDecisionStage = computed(() => (props.softRows ?? []).length > 0);

// 软命中行变化（新一轮查重结果）时重置决策
watch(
  () => props.softRows,
  () => {
    personDecisions.value = {};
  }
);

/** 是否所有软命中行都已完成决策 */
const allDecided = computed(() => {
  const rows = props.softRows ?? [];
  return rows.every(row => personDecisions.value[String(row.seq)] !== undefined);
});

/** 未决策行数（按钮禁用提示） */
const undecidedCount = computed(() => {
  const rows = props.softRows ?? [];
  return rows.filter(row => personDecisions.value[String(row.seq)] === undefined).length;
});

function handleConfirm() {
  if (isDecisionStage.value) {
    if (!allDecided.value) return;
    emit('confirm', { ...personDecisions.value });
    return;
  }
  emit('confirm');
}

/** 放弃决策，返回预览页（父组件清空 softRows 后重新选择文件） */
function handleCancelDecision() {
  personDecisions.value = {};
  emit('reset');
}

function handleDrop(_e: DragEvent) {
  emit('triggerFileInput');
}
</script>

<template>
  <NModal
    :show="visible"
    preset="card"
    title="数据导入"
    class="w-900px"
    :mask-closable="false"
    @update:show="emit('update:visible', $event)"
  >
    <NSpin :show="loading">
      <NSpace vertical :size="16">
        <!-- 人员主档软命中决策页 -->
        <template v-if="isDecisionStage">
          <NAlert type="warning" :show-icon="true">
            检测到 {{ (softRows ?? []).length }} 行疑似重复人员主档（姓名+手机号码匹配），请逐行确认是挂接既有档案还是新建主档
          </NAlert>

          <div class="decision-list">
            <div
              v-for="row in softRows"
              :key="row.seq"
              class="decision-row"
              :class="{ 'decision-row-dark': isDarkMode }"
            >
              <div class="decision-row-head">
                <span class="decision-seq">第 {{ row.seq }} 行</span>
                <span class="decision-info">{{ row.name }} / {{ row.mobile }}<template v-if="row.idcard"> / {{ row.idcard }}</template></span>
              </div>
              <NRadioGroup v-model:value="personDecisions[String(row.seq)]" class="decision-radio-group">
                <div class="decision-candidates">
                  <div v-for="p in row.matches" :key="p.人员编码" class="decision-candidate">
                    <NRadio :value="p.人员编码">
                      挂接既有档案：{{ p.人员编码 }} {{ p.姓名 }}<template v-if="p.身份证号"> / {{ p.身份证号 }}</template><template v-if="p.属地"> / {{ p.属地 }}</template>
                    </NRadio>
                  </div>
                  <div class="decision-candidate">
                    <NRadio :value="DECISION_NEW">新建主档（确认与上述档案非同一人）</NRadio>
                  </div>
                </div>
              </NRadioGroup>
            </div>
          </div>
        </template>

        <!-- 文件上传/预览页 -->
        <template v-else>
          <div
            v-if="previewData.length === 0 && !success"
            class="import-upload-area"
            :class="{ 'import-upload-area-dark': isDarkMode }"
            @click="emit('triggerFileInput')"
            @dragover.prevent
            @drop="handleDrop"
          >
            <slot name="file-input" />
            <div class="import-upload-content">
              <div class="import-upload-icon">📁</div>
              <div class="import-upload-text">
                <div>点击或拖拽文件到此处上传</div>
                <div class="import-upload-hint">支持 .xlsx, .xls, .csv 格式</div>
              </div>
            </div>
          </div>

          <div v-if="previewData.length === 0 && !success" class="import-template-row">
            <NButton text type="primary" @click="emit('downloadTemplate')">📥 下载导入模板</NButton>
          </div>
        </template>

        <NAlert v-if="error" type="error" :show-icon="true">
          {{ error }}
        </NAlert>

        <div v-if="!isDecisionStage && previewData.length > 0 && !success">
          <div class="import-preview-header" :class="{ 'import-preview-header-dark': isDarkMode }">
            <span>数据预览</span>
            <span class="import-preview-count">共 {{ previewData.length }} 条数据</span>
          </div>
          <NDataTable
            class="import-preview-table"
            :data="previewData.slice(0, 10)"
            :columns="previewColumns"
            size="small"
            bordered
            :scroll-x="previewColumns.reduce((sum, col) => sum + (col.width || 100), 0)"
            :scroll-y="300"
            :pagination="false"
          />
          <div v-if="previewData.length > 10" class="import-preview-more">
            还有 {{ previewData.length - 10 }} 条数据未显示...
          </div>
        </div>

        <NAlert v-if="success" type="success" :show-icon="true">
          {{ success.message }}
        </NAlert>

        <NSpace justify="end">
          <NButton v-if="isDecisionStage" @click="handleCancelDecision">放弃并重新选择</NButton>
          <NButton v-else-if="previewData.length > 0 && !success" @click="emit('reset')">重新选择</NButton>
          <NButton @click="emit('update:visible', false)">关闭</NButton>
          <NButton
            v-if="isDecisionStage"
            type="primary"
            :disabled="loading || !allDecided"
            :title="allDecided ? '' : `还有 ${undecidedCount} 行未确认`"
            @click="handleConfirm"
          >
            确认导入
          </NButton>
          <NButton
            v-else-if="previewData.length > 0 && !success"
            type="primary"
            :disabled="loading"
            @click="handleConfirm"
          >
            确认导入
          </NButton>
        </NSpace>
      </NSpace>
    </NSpin>
  </NModal>
</template>

<style scoped>
.import-upload-area {
  border: 2px dashed #d9d9d9;
  border-radius: 8px;
  padding: 48px 24px;
  text-align: center;
  cursor: pointer;
  transition: all 0.3s;
  background: #fafafa;
}

.import-upload-area:hover {
  border-color: #40a9ff;
  background: #f0f5ff;
}

.import-upload-area-dark {
  border-color: #4b5965;
  background: #1f1f1f;
}

.import-upload-area-dark:hover {
  border-color: #4ea4f3;
  background: #2a2a2a;
}

.import-upload-content {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16px;
}

.import-upload-icon {
  font-size: 48px;
}

.import-upload-text {
  font-size: 16px;
  color: #262626;
}

.import-upload-area-dark .import-upload-text {
  color: #e0e0e0;
}

.import-upload-hint {
  font-size: 14px;
  color: #8c8c8c;
  margin-top: 8px;
}

.import-upload-area-dark .import-upload-hint {
  color: #a0a0a0;
}

.import-template-row {
  text-align: center;
  margin-top: -8px;
}

.import-preview-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
  font-weight: 600;
  color: #262626;
}

.import-preview-header-dark {
  color: #e0e0e0;
}

.import-preview-count {
  font-size: 14px;
  color: #8c8c8c;
  font-weight: normal;
}

.import-preview-table {
  width: 100%;
}

.import-preview-more {
  text-align: center;
  padding: 12px;
  color: #8c8c8c;
  font-size: 14px;
}

/* 人员主档软命中决策页 */
.decision-list {
  max-height: 480px;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding-right: 4px;
}

.decision-row {
  border: 1px solid #e8e8e8;
  border-radius: 6px;
  padding: 12px 16px;
  background: #fafafa;
}

.decision-row-dark {
  border-color: #3a3a3a;
  background: #1f1f1f;
}

.decision-row-head {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 8px;
}

.decision-seq {
  font-weight: 600;
  color: #d97706;
  white-space: nowrap;
}

.decision-info {
  color: #595959;
  font-size: 14px;
}

.decision-row-dark .decision-info {
  color: #b0b0b0;
}

.decision-radio-group {
  width: 100%;
}

.decision-candidates {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
</style>
