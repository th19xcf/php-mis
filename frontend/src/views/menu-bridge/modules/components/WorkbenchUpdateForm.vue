<script setup lang="ts">
import { computed } from 'vue';
import { NSpin, NSpace, NButton, NAlert, NForm, NFormItem, NInput, NInputNumber, NSelect, NDatePicker } from 'naive-ui';

const props = defineProps<{
  loading: boolean;
  error: string | null;
  success: string | null;
  formFields: any[];
  formData: Record<string, any>;
  isBatch?: boolean;
  isDarkMode?: boolean;
  isMaximized?: boolean;
}>();

const emit = defineEmits<{
  'update:formData': [value: Record<string, any>];
  confirm: [];
  openPopup: [field: any];
  close: [];
  toggleMaximize: [];
}>();

function handleFieldChange(fieldName: string, value: any) {
  emit('update:formData', { ...props.formData, [fieldName]: value });
}

function getFieldOptions(field: any) {
  return field.objectOptions || [];
}

/**
 * 多选字段：formData 里存的是以 "," 分隔的字符串，
 * 渲染 NSelect multiple 时需要解析为数组；变更时再 join 回去。
 */
function getMultiSelectValue(fieldName: string): string[] {
  const raw = props.formData[fieldName];
  if (raw == null || raw === '') return [];
  return String(raw)
    .split(',')
    .map(s => s.trim())
    .filter(Boolean);
}

function handleMultiFieldChange(fieldName: string, value: string[] | null | undefined) {
  const parts = (value || [])
    .map(s => (s == null ? '' : String(s).trim()))
    .filter(Boolean);
  handleFieldChange(fieldName, parts.join(','));
}

const title = computed(() => (props.isBatch ? '多条修改' : '单条修改'));
const confirmText = computed(() => (props.isBatch ? '确认批量修改' : '确认修改'));
const titleSub = computed(() => (props.isBatch ? '批量修改选中的记录' : '修改单条记录'));
const dark = computed(() => !!props.isDarkMode);
</script>

<template>
  <div class="edit-panel" :class="{ 'edit-panel-dark': dark }">
    <div class="edit-header" :class="{ 'edit-header-dark': dark }">
      <span class="edit-title">
        <span class="title-text">{{ title }}</span>
        <span class="title-divider">|</span>
        <span class="title-sub">{{ titleSub }}</span>
      </span>
      <div class="flex flex-row gap-8px">
        <NButton v-if="!success" type="primary" size="small" :disabled="loading" @click="emit('confirm')">
          {{ confirmText }}
        </NButton>
        <NButton v-if="!success" type="default" size="small" @click="emit('toggleMaximize')">
          {{ isMaximized ? '恢复' : '扩大' }}
        </NButton>
        <NButton size="small" @click="emit('close')">关闭</NButton>
      </div>
    </div>
    <div class="edit-container">
      <NSpin :show="loading">
        <NSpace vertical :size="16">
          <NAlert v-if="error" type="error" :show-icon="true">
            {{ error }}
          </NAlert>

          <NAlert v-if="success" type="success" :show-icon="true">
            {{ success }}
          </NAlert>

          <NAlert v-if="isBatch" type="info" :show-icon="true">
            请输入要修改的字段值，这些值将应用到所有选中的记录
          </NAlert>

          <div v-if="formFields.length > 0" class="form-container">
            <NForm label-placement="left" label-width="auto" :model="formData">
              <div class="form-grid">
                <NFormItem
                  v-for="field in formFields"
                  :key="field.fieldName"
                  :label="field.columnName"
                  :path="field.fieldName"
                  :required="field.required"
                >
                  <NSelect
                    v-if="field.editorType === '多选'"
                    :value="getMultiSelectValue(field.fieldName)"
                    :options="getFieldOptions(field)"
                    :placeholder="`请选择${field.columnName}（可多选）`"
                    :readonly="field.readonly"
                    multiple
                    clearable
                    @update:value="handleMultiFieldChange(field.fieldName, $event)"
                  />
                  <NSelect
                    v-else-if="field.editorType === '下拉框' || field.fieldType === '选项'"
                    :value="formData[field.fieldName]"
                    :options="getFieldOptions(field)"
                    :placeholder="`请选择${field.columnName}`"
                    :readonly="field.readonly"
                    clearable
                    @update:value="handleFieldChange(field.fieldName, $event)"
                  />
                  <NDatePicker
                    v-else-if="field.editorType === '日期时间' || field.fieldType === '日期时间'"
                    :formatted-value="formData[field.fieldName]"
                    value-format="yyyy-MM-dd HH:mm:ss"
                    type="datetime"
                    :placeholder="`请选择${field.columnName}`"
                    :show-time="true"
                    :readonly="field.readonly"
                    clearable
                    @update:formatted-value="handleFieldChange(field.fieldName, $event)"
                  />
                  <NDatePicker
                    v-else-if="field.fieldType === '日期'"
                    :formatted-value="formData[field.fieldName]"
                    value-format="yyyy-MM-dd"
                    type="date"
                    :placeholder="`请选择${field.columnName}`"
                    :readonly="field.readonly"
                    clearable
                    @update:formatted-value="handleFieldChange(field.fieldName, $event)"
                  />
                  <NInputNumber
                    v-else-if="field.fieldType === '数值'"
                    :value="formData[field.fieldName]"
                    :placeholder="`请输入${field.columnName}`"
                    :readonly="field.readonly"
                    clearable
                    @update:value="handleFieldChange(field.fieldName, $event)"
                  />
                  <div v-else-if="field.editorType === '弹窗选择'" class="popup-select-wrapper">
                    <NInput
                      :value="formData[field.fieldName] || ''"
                      :placeholder="`请选择${field.columnName}`"
                      readonly
                      class="popup-input"
                    >
                      <template #suffix>
                        <NButton text type="primary" @click="emit('openPopup', field)">选择</NButton>
                      </template>
                    </NInput>
                  </div>
                  <NInput
                    v-else
                    :value="formData[field.fieldName]"
                    :placeholder="`请输入${field.columnName}`"
                    :readonly="field.readonly"
                    clearable
                    @update:value="handleFieldChange(field.fieldName, $event)"
                  />
                </NFormItem>
              </div>
            </NForm>
          </div>
          <div v-else-if="!error && !loading" class="empty-fields" :class="[dark ? 'empty-fields-dark' : '']">
            暂无可修改的字段
          </div>
        </NSpace>
      </NSpin>
    </div>
  </div>
</template>

<style scoped>
.form-container {
  max-height: calc(100vh - 320px);
  overflow-y: auto;
}

.form-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 16px;
}

@media (max-width: 1100px) {
  .form-grid {
    grid-template-columns: 1fr;
  }
}

.popup-select-wrapper {
  display: flex;
  align-items: center;
  width: 100%;
}

.popup-input {
  flex: 1;
}

.popup-input :deep(.n-input__suffix) {
  padding-right: 4px;
}

.empty-fields {
  text-align: center;
  color: #999;
  padding: 32px 0;
}

.empty-fields-dark {
  color: #b0b0b0;
}
</style>
