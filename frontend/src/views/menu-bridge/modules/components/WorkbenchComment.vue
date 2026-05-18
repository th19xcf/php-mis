<script setup lang="ts">
import { NModal, NSpin, NSpace, NButton, NEmpty, NInput } from 'naive-ui';

defineProps<{
  addVisible: boolean;
  viewVisible: boolean;
  loading: boolean;
  fields: any[];
  formData: Record<string, any>;
  list: any[];
  moduleName: string;
  remark: string;
  keyFieldList: any[];
  keyFieldCount: number;
  isDarkMode: boolean;
}>();

const emit = defineEmits<{
  'update:addVisible': [value: boolean];
  'update:viewVisible': [value: boolean];
  'update:remark': [value: string];
  submit: [];
}>();
</script>

<template>
  <NModal
    :show="addVisible"
    preset="card"
    title="添加批注"
    class="w-600px"
    :class="{ 'comment-modal-dark': isDarkMode }"
    :mask-closable="false"
    @update:show="emit('update:addVisible', $event)"
  >
    <NSpin :show="loading">
      <NSpace vertical :size="16">
        <div v-if="keyFieldCount > 0" class="comment-form-wrapper">
          <div
            class="comment-form-header"
            :style="isDarkMode ? { backgroundColor: '#1f1f1f', borderColor: '#4b5965', color: '#e0e0e0' } : {}"
          >
            <div
              class="comment-form-col comment-col-name"
              :style="isDarkMode ? { backgroundColor: '#1f1f1f', borderRightColor: '#4b5965', color: '#e0e0e0' } : {}"
            >
              列名
            </div>
            <div
              class="comment-form-col comment-col-type"
              :style="isDarkMode ? { backgroundColor: '#1f1f1f', borderRightColor: '#4b5965', color: '#e0e0e0' } : {}"
            >
              列类型
            </div>
            <div class="comment-form-col comment-col-value" :style="isDarkMode ? { color: '#e0e0e0' } : {}">取值</div>
          </div>

          <div class="comment-form-body" :style="isDarkMode ? { borderColor: '#4b5965' } : {}">
            <div
              v-for="(field, index) in keyFieldList"
              :key="field.name"
              class="comment-form-row"
              :style="
                isDarkMode
                  ? {
                      borderBottomColor: '#4b5965',
                      borderBottom: index === keyFieldList.length - 1 ? 'none' : '1px solid #4b5965'
                    }
                  : {}
              "
            >
              <div
                class="comment-form-col comment-col-name"
                :style="isDarkMode ? { backgroundColor: '#1f1f1f', borderRightColor: '#4b5965', color: '#e0e0e0' } : {}"
              >
                {{ field.comment || field.name }}
              </div>
              <div
                class="comment-form-col comment-col-type"
                :style="isDarkMode ? { backgroundColor: '#1f1f1f', borderRightColor: '#4b5965', color: '#e0e0e0' } : {}"
              >
                {{ field.type }}
              </div>
              <div class="comment-form-col comment-col-value" :style="isDarkMode ? { color: '#e0e0e0' } : {}">
                <span class="comment-key-field-value" :style="isDarkMode ? { color: '#b0b0b0' } : {}">
                  {{ formData[field.name] }}
                </span>
              </div>
            </div>
          </div>
        </div>

        <NEmpty v-else description="该功能未配置批注模块" />

        <div class="comment-form-wrapper">
          <div
            class="comment-form-header"
            :style="isDarkMode ? { backgroundColor: '#1f1f1f', borderColor: '#4b5965', color: '#e0e0e0' } : {}"
          >
            <div
              class="comment-form-col comment-col-name"
              :style="isDarkMode ? { backgroundColor: '#1f1f1f', borderRightColor: '#4b5965', color: '#e0e0e0' } : {}"
            >
              备注模块
            </div>
            <div
              class="comment-form-col comment-col-type"
              :style="isDarkMode ? { backgroundColor: '#1f1f1f', borderRightColor: '#4b5965', color: '#e0e0e0' } : {}"
            >
              字符
            </div>
            <div class="comment-form-col comment-col-value" :style="isDarkMode ? { color: '#e0e0e0' } : {}">
              <span :style="isDarkMode ? { color: '#e0e0e0' } : {}">{{ moduleName }}</span>
            </div>
          </div>
        </div>

        <div class="comment-form-wrapper">
          <div
            class="comment-form-header"
            :style="isDarkMode ? { backgroundColor: '#1f1f1f', borderColor: '#4b5965', color: '#e0e0e0' } : {}"
          >
            <div
              class="comment-form-col comment-col-name"
              :style="isDarkMode ? { backgroundColor: '#1f1f1f', borderRightColor: '#4b5965', color: '#e0e0e0' } : {}"
            >
              备注说明
            </div>
            <div
              class="comment-form-col comment-col-type"
              :style="isDarkMode ? { backgroundColor: '#1f1f1f', borderRightColor: '#4b5965', color: '#e0e0e0' } : {}"
            >
              文本
            </div>
            <div class="comment-form-col comment-col-value" :style="isDarkMode ? { color: '#e0e0e0' } : {}">
              <NInput
                :value="remark"
                type="textarea"
                placeholder="请输入备注说明"
                :rows="3"
                @update:value="emit('update:remark', $event)"
              />
            </div>
          </div>
        </div>

        <NSpace justify="end">
          <NButton @click="emit('update:addVisible', false)">取消</NButton>
          <NButton type="primary" :loading="loading" @click="emit('submit')">确定</NButton>
        </NSpace>
      </NSpace>
    </NSpin>
  </NModal>

  <NModal
    :show="viewVisible"
    preset="card"
    title="查看批注"
    class="w-800px"
    :mask-closable="false"
    @update:show="emit('update:viewVisible', $event)"
  >
    <NSpin :show="loading">
      <NSpace vertical :size="16">
        <div v-if="list.length > 0" class="comment-card-list">
          <div
            v-for="(item, index) in list"
            :key="index"
            class="comment-card"
            :class="{ 'comment-card-dark': isDarkMode }"
          >
            <div class="comment-card-header">
              <div class="comment-card-user">
                <span class="comment-card-user-icon">👤</span>
                <span>{{ item.操作人员 || '未知用户' }}</span>
              </div>
              <div class="comment-card-time">
                {{ item.操作时间 || item.创建时间 || '-' }}
              </div>
            </div>

            <div class="comment-card-content">
              <div class="comment-card-label">备注说明</div>
              <div class="comment-card-text">{{ item.备注说明 || '无' }}</div>
            </div>

            <div class="comment-card-footer">
              <div
                v-for="field in fields.filter(f => f.isKeyField && item[f.name])"
                :key="field.name"
                class="comment-card-tag"
              >
                <span class="comment-card-tag-label">{{ field.comment || field.name }}:</span>
                <span class="comment-card-tag-value" :title="String(item[field.name])">{{ item[field.name] }}</span>
              </div>
            </div>
          </div>
        </div>
        <NEmpty v-else description="暂无批注记录" />

        <NSpace justify="end">
          <NButton @click="emit('update:viewVisible', false)">关闭</NButton>
        </NSpace>
      </NSpace>
    </NSpin>
  </NModal>
</template>

<style scoped>
.comment-form-wrapper {
}

.comment-form-header {
  display: flex;
  background-color: #f5f5f5;
  border: 1px solid #d9d9d9;
  border-bottom: 1px solid #d9d9d9;
  font-weight: bold;
}

.comment-form-body {
  border: 1px solid #d9d9d9;
  border-top: none;
}

.comment-form-row {
  display: flex;
  border-bottom: 1px solid #d9d9d9;
}

.comment-form-row:last-child {
  border-bottom: none;
}

.comment-form-col {
  padding: 8px 12px;
  display: flex;
  align-items: center;
}

.comment-col-name {
  width: 120px;
  border-right: 1px solid #d9d9d9;
  background-color: #fafafa;
}

.comment-col-type {
  width: 80px;
  border-right: 1px solid #d9d9d9;
  background-color: #fafafa;
  justify-content: center;
}

.comment-col-value {
  flex: 1;
}

.comment-key-field-value {
  color: #666;
  font-style: italic;
}

.comment-card-list {
  max-height: 500px;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.comment-card {
  background: #ffffff;
  border: 1px solid #e8e8e8;
  border-radius: 8px;
  padding: 16px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
  transition: all 0.2s ease;
}

.comment-card:hover {
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
  border-color: #d9d9d9;
}

.comment-card-dark {
  background: #1f1f1f;
  border-color: #4b5965;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
}

.comment-card-dark:hover {
  border-color: #5a6a7a;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
}

.comment-card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
  padding-bottom: 12px;
  border-bottom: 1px solid #f0f0f0;
}

.comment-card-dark .comment-card-header {
  border-bottom-color: #4b5965;
}

.comment-card-user {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 600;
  color: #1890ff;
  font-size: 14px;
}

.comment-card-dark .comment-card-user {
  color: #4ea4f3;
}

.comment-card-user-icon {
  font-size: 16px;
}

.comment-card-time {
  color: #8c8c8c;
  font-size: 13px;
}

.comment-card-dark .comment-card-time {
  color: #a0a0a0;
}

.comment-card-content {
  margin-bottom: 12px;
}

.comment-card-label {
  font-size: 12px;
  color: #8c8c8c;
  margin-bottom: 4px;
}

.comment-card-dark .comment-card-label {
  color: #a0a0a0;
}

.comment-card-text {
  font-size: 14px;
  color: #262626;
  line-height: 1.6;
  white-space: pre-wrap;
  word-break: break-all;
}

.comment-card-dark .comment-card-text {
  color: #e0e0e0;
}

.comment-card-footer {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding-top: 12px;
  border-top: 1px dashed #f0f0f0;
}

.comment-card-dark .comment-card-footer {
  border-top-color: #4b5965;
}

.comment-card-tag {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 10px;
  background: #f5f5f5;
  border-radius: 4px;
  font-size: 12px;
  max-width: 100%;
}

.comment-card-dark .comment-card-tag {
  background: #2a2a2a;
}

.comment-card-tag-label {
  color: #8c8c8c;
  flex-shrink: 0;
}

.comment-card-dark .comment-card-tag-label {
  color: #a0a0a0;
}

.comment-card-tag-value {
  color: #595959;
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.comment-card-dark .comment-card-tag-value {
  color: #c0c0c0;
}
</style>
