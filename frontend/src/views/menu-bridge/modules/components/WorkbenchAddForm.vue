<script setup lang="ts">
import { computed } from 'vue';
import { NSpin, NSpace, NButton, NAlert, NForm, NFormItem, NInput, NInputNumber, NSelect, NDatePicker } from 'naive-ui';

const props = defineProps<{
  loading: boolean;
  error: string | null;
  success: string | null;
  formFields: any[];
  formData: Record<string, any>;
  isDarkMode?: boolean;
  isMaximized?: boolean;
}>();

const emit = defineEmits<{
  'update:formData': [value: Record<string, any>];
  confirm: [];
  openPopup: [field: any];
  close: [];
  toggleMaximize: [];
  addSample: [];
}>();

function handleFieldChange(fieldName: string, value: any) {
  emit('update:formData', { ...props.formData, [fieldName]: value });
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

const dark = computed(() => !!props.isDarkMode);
</script>

<template>
  <div class="edit-panel" :class="{ 'edit-panel-dark': dark }">
    <div class="edit-header" :class="{ 'edit-header-dark': dark }">
      <span class="edit-title">
        <span class="title-text">新增</span>
        <span class="title-divider">|</span>
        <span class="title-sub">填写新记录</span>
      </span>
      <div class="flex flex-row gap-8px">
        <NButton v-if="!success" type="default" size="small" @click="emit('addSample')">添加样本数据</NButton>
        <NButton v-if="!success" type="primary" size="small" :disabled="loading" @click="emit('confirm')">
          确认新增
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

          <div v-if="!success">
            <div class="form-field-count" :class="[dark ? 'form-field-count-dark' : '']">
              字段数量: {{ formFields.length }}
            </div>
            <NForm :model="formData" label-placement="left" label-width="120px">
              <div class="add-form-grid">
                <NFormItem
                  v-for="field in formFields"
                  :key="field.fieldName"
                  :label="field.columnName"
                  :required="field.required"
                >
                  <div v-if="field.inputType === 'popup'" class="popup-select-wrapper">
                    <NInput
                      :value="formData[field.fieldName]"
                      :placeholder="`请选择${field.columnName}`"
                      readonly
                      class="popup-input"
                    >
                      <template #suffix>
                        <NButton text type="primary" @click="emit('openPopup', field)">
                          <template #icon>
                            <span class="iconify" data-icon="mdi:magnify"></span>
                          </template>
                          选择
                        </NButton>
                      </template>
                    </NInput>
                  </div>
                  <NSelect
                    v-else-if="field.inputType === 'multiSelect'"
                    :value="getMultiSelectValue(field.fieldName)"
                    :options="field.objectOptions || []"
                    :placeholder="`请选择${field.columnName}（可多选）`"
                    multiple
                    clearable
                    @update:value="handleMultiFieldChange(field.fieldName, $event)"
                  />
                  <NSelect
                    v-else-if="field.objectName && field.objectName !== '' && field.inputType !== 'popup'"
                    :value="formData[field.fieldName]"
                    :options="field.objectOptions || []"
                    :placeholder="`请选择${field.columnName}`"
                    clearable
                    @update:value="handleFieldChange(field.fieldName, $event)"
                  />
                  <NDatePicker
                    v-else-if="field.fieldType === '日期'"
                    :formatted-value="formData[field.fieldName]"
                    value-format="yyyy-MM-dd"
                    type="date"
                    :placeholder="`请选择${field.columnName}`"
                    clearable
                    @update:formatted-value="handleFieldChange(field.fieldName, $event)"
                  />
                  <NInputNumber
                    v-else-if="field.fieldType === '数值'"
                    :value="formData[field.fieldName]"
                    :placeholder="`请输入${field.columnName}`"
                    clearable
                    @update:value="handleFieldChange(field.fieldName, $event)"
                  />
                  <NInput
                    v-else
                    :value="formData[field.fieldName]"
                    :placeholder="`请输入${field.columnName}`"
                    clearable
                    @update:value="handleFieldChange(field.fieldName, $event)"
                  />
                </NFormItem>
              </div>
            </NForm>
          </div>
        </NSpace>
      </NSpin>
    </div>
  </div>
</template>

<style scoped>
.add-form-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 16px;
}

@media (max-width: 1100px) {
  .add-form-grid {
    grid-template-columns: 1fr;
  }
}

.add-form-grid :deep(.n-form-item) {
  margin-bottom: 0;
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

.form-field-count {
  margin-bottom: 10px;
  color: #666;
}

.form-field-count-dark {
  color: #b0b0b0;
}
</style>
