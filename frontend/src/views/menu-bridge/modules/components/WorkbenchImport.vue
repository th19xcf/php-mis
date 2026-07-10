<script setup lang="ts">
import { NModal, NSpin, NSpace, NButton, NAlert, NDataTable } from 'naive-ui';

defineProps<{
  visible: boolean;
  loading: boolean;
  previewData: any[];
  error: string | null;
  success: { message: string } | null;
  previewColumns: any[];
  isDarkMode: boolean;
}>();

const emit = defineEmits<{
  'update:visible': [value: boolean];
  triggerFileInput: [];
  downloadTemplate: [];
  reset: [];
  confirm: [];
}>();

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

        <NAlert v-if="error" type="error" :show-icon="true">
          {{ error }}
        </NAlert>

        <div v-if="previewData.length > 0 && !success">
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
          <NButton v-if="previewData.length > 0 && !success" @click="emit('reset')">重新选择</NButton>
          <NButton @click="emit('update:visible', false)">关闭</NButton>
          <NButton
            v-if="previewData.length > 0 && !success"
            type="primary"
            :disabled="loading"
            @click="emit('confirm')"
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
</style>
