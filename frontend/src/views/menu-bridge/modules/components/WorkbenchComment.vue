<script setup lang="ts">
import { NSpin, NSpace, NButton, NEmpty, NInput } from 'naive-ui';

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
  isMaximized?: boolean;
}>();

const emit = defineEmits<{
  'update:remark': [value: string];
  submit: [];
  close: [];
  toggleMaximize: [];
}>();
</script>

<template>
  <div class="edit-panel" :class="{ 'edit-panel-dark': isDarkMode }">
    <div class="edit-header" :class="{ 'edit-header-dark': isDarkMode }">
      <span class="edit-title">
        <span class="title-text">{{ addVisible ? '添加批注' : '查看批注' }}</span>
        <span class="title-divider">|</span>
        <span class="title-sub">{{ addVisible ? '填写批注内容' : '查看历史批注' }}</span>
      </span>
      <div class="flex flex-row gap-8px">
        <NButton
          v-if="addVisible"
          type="primary"
          size="small"
          :disabled="loading"
          @click="emit('submit')"
        >
          确定
        </NButton>
        <NButton size="small" type="default" @click="emit('toggleMaximize')">
          {{ isMaximized ? '恢复' : '扩大' }}
        </NButton>
        <NButton size="small" @click="emit('close')">关闭</NButton>
      </div>
    </div>
    <div class="edit-container">
      <NSpin :show="loading">
        <NSpace vertical :size="16">
          <!-- 添加批注：表单区 -->
          <template v-if="addVisible">
            <div v-if="keyFieldCount > 0" class="comment-form-wrapper">
              <div
                class="comment-form-header"
                :class="{ 'comment-form-header-dark': isDarkMode }"
              >
                <div
                  class="comment-form-col comment-col-name"
                  :class="{ 'comment-form-col-dark': isDarkMode }"
                >
                  列名
                </div>
                <div
                  class="comment-form-col comment-col-type"
                  :class="{ 'comment-form-col-dark': isDarkMode }"
                >
                  列类型
                </div>
                <div class="comment-form-col comment-col-value" :class="{ 'comment-form-col-value-dark': isDarkMode }">
                  取值
                </div>
              </div>

              <div class="comment-form-body" :class="{ 'comment-form-body-dark': isDarkMode }">
                <div
                  v-for="(field, index) in keyFieldList"
                  :key="field.name"
                  class="comment-form-row"
                  :class="{
                    'comment-form-row-dark': isDarkMode,
                    'comment-form-row-last': index === keyFieldList.length - 1
                  }"
                >
                  <div
                    class="comment-form-col comment-col-name"
                    :class="{ 'comment-form-col-dark': isDarkMode }"
                  >
                    {{ field.comment || field.name }}
                  </div>
                  <div
                    class="comment-form-col comment-col-type"
                    :class="{ 'comment-form-col-dark': isDarkMode }"
                  >
                    {{ field.type }}
                  </div>
                  <div
                    class="comment-form-col comment-col-value"
                    :class="{ 'comment-form-col-value-dark': isDarkMode }"
                  >
                    <span
                      class="comment-key-field-value"
                      :class="{ 'comment-key-field-value-dark': isDarkMode }"
                    >
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
                :class="{ 'comment-form-header-dark': isDarkMode }"
              >
                <div
                  class="comment-form-col comment-col-name"
                  :class="{ 'comment-form-col-dark': isDarkMode }"
                >
                  备注模块
                </div>
                <div
                  class="comment-form-col comment-col-type"
                  :class="{ 'comment-form-col-dark': isDarkMode }"
                >
                  字符
                </div>
                <div
                  class="comment-form-col comment-col-value"
                  :class="{ 'comment-form-col-value-dark': isDarkMode }"
                >
                  <span :class="{ 'comment-form-col-value-dark': isDarkMode }">{{ moduleName }}</span>
                </div>
              </div>
            </div>

            <div class="comment-form-wrapper">
              <div
                class="comment-form-header"
                :class="{ 'comment-form-header-dark': isDarkMode }"
              >
                <div
                  class="comment-form-col comment-col-name"
                  :class="{ 'comment-form-col-dark': isDarkMode }"
                >
                  备注说明
                </div>
                <div
                  class="comment-form-col comment-col-type"
                  :class="{ 'comment-form-col-dark': isDarkMode }"
                >
                  文本
                </div>
                <div
                  class="comment-form-col comment-col-value"
                  :class="{ 'comment-form-col-value-dark': isDarkMode }"
                >
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
          </template>

          <!-- 查看批注：列表区 -->
          <template v-else-if="viewVisible">
            <div v-if="list.length > 0" class="comment-card-list">
              <div
                v-for="(item, index) in list"
                :key="index"
                class="comment-card"
                :class="{ 'comment-card-dark': isDarkMode }"
              >
                <div class="comment-card-header" :class="{ 'comment-card-header-dark': isDarkMode }">
                  <div class="comment-card-user" :class="{ 'comment-card-user-dark': isDarkMode }">
                    <span class="comment-card-user-icon">用户</span>
                    <span>{{ item.操作人员 || '未知用户' }}</span>
                  </div>
                  <div class="comment-card-time" :class="{ 'comment-card-time-dark': isDarkMode }">
                    {{ item.操作时间 || item.创建时间 || '-' }}
                  </div>
                </div>

                <div class="comment-card-content">
                  <div class="comment-card-label" :class="{ 'comment-card-label-dark': isDarkMode }">
                    备注说明
                  </div>
                  <div class="comment-card-text" :class="{ 'comment-card-text-dark': isDarkMode }">
                    {{ item.备注说明 || '无' }}
                  </div>
                </div>

                <div
                  class="comment-card-footer"
                  :class="{ 'comment-card-footer-dark': isDarkMode }"
                >
                  <div
                    v-for="field in fields.filter(f => f.isKeyField && item[f.name])"
                    :key="field.name"
                    class="comment-card-tag"
                    :class="{ 'comment-card-tag-dark': isDarkMode }"
                  >
                    <span
                      class="comment-card-tag-label"
                      :class="{ 'comment-card-tag-label-dark': isDarkMode }"
                    >{{ field.comment || field.name }}:</span>
                    <span
                      class="comment-card-tag-value"
                      :class="{ 'comment-card-tag-value-dark': isDarkMode }"
                      :title="String(item[field.name])"
                    >{{ item[field.name] }}</span>
                  </div>
                </div>
              </div>
            </div>
            <NEmpty v-else description="暂无批注记录" />
          </template>
        </NSpace>
      </NSpin>
    </div>
  </div>
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

.comment-form-header-dark {
  background-color: #1f1f1f !important;
  border-color: #4b5965 !important;
  color: #e0e0e0 !important;
}

.comment-form-body {
  border: 1px solid #d9d9d9;
  border-top: none;
}

.comment-form-body-dark {
  border-color: #4b5965 !important;
}

.comment-form-row {
  display: flex;
  border-bottom: 1px solid #d9d9d9;
}

.comment-form-row-last {
  border-bottom: none;
}

.comment-form-row-dark {
  border-bottom-color: #4b5965 !important;
}

.comment-form-col {
  padding: 8px 12px;
  display: flex;
  align-items: center;
}

.comment-form-col-dark {
  background-color: #1f1f1f !important;
  border-right-color: #4b5965 !important;
  color: #e0e0e0 !important;
}

.comment-form-col-value-dark {
  color: #e0e0e0 !important;
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

.comment-key-field-value-dark {
  color: #b0b0b0 !important;
}

.comment-card-list {
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

.comment-card-header-dark {
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

.comment-card-user-dark {
  color: #4ea4f3;
}

.comment-card-user-icon {
  font-size: 16px;
}

.comment-card-time {
  color: #8c8c8c;
  font-size: 13px;
}

.comment-card-time-dark {
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

.comment-card-label-dark {
  color: #a0a0a0;
}

.comment-card-text {
  font-size: 14px;
  color: #262626;
  line-height: 1.6;
  white-space: pre-wrap;
  word-break: break-all;
}

.comment-card-text-dark {
  color: #e0e0e0;
}

.comment-card-footer {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding-top: 12px;
  border-top: 1px dashed #f0f0f0;
}

.comment-card-footer-dark {
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

.comment-card-tag-dark {
  background: #2a2a2a;
}

.comment-card-tag-label {
  color: #8c8c8c;
  flex-shrink: 0;
}

.comment-card-tag-label-dark {
  color: #a0a0a0;
}

.comment-card-tag-value {
  color: #595959;
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.comment-card-tag-value-dark {
  color: #c0c0c0;
}
</style>
