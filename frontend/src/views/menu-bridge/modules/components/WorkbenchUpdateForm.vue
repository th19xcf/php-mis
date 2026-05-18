<script setup lang="ts">
import {
  NModal,
  NSpin,
  NSpace,
  NButton,
  NAlert,
  NForm,
  NFormItem,
  NInput,
  NInputNumber,
  NSelect,
  NDatePicker
} from 'naive-ui';

const props = defineProps<{
  visible: boolean;
  loading: boolean;
  error: string | null;
  success: string | null;
  formFields: any[];
  formData: Record<string, any>;
  isBatch?: boolean;
}>();

const emit = defineEmits<{
  'update:visible': [value: boolean];
  'update:formData': [value: Record<string, any>];
  confirm: [];
  openPopup: [field: any];
}>();

function handleFieldChange(fieldName: string, value: any) {
  emit('update:formData', { ...props.formData, [fieldName]: value });
}

function getFieldOptions(field: any) {
  return field.objectOptions || [];
}

const title = props.isBatch ? '批量修改记录' : '修改记录';
const confirmText = props.isBatch ? '确认批量修改' : '确认修改';
</script>

<template>
  <NModal
    :show="visible"
    preset="card"
    :title="title"
    class="w-800px"
    :mask-closable="false"
    @update:show="emit('update:visible', $event)"
  >
    <NSpin :show="loading">
      <NSpace vertical :size="16">
        <NAlert v-if="error" type="error" :show-icon="true">
          {{ error }}
        </NAlert>

        <NAlert v-if="success" type="success" :show-icon="true">
          {{ success }}
        </NAlert>

        <NAlert v-if="isBatch" type="info" :show-icon="true">请输入要修改的字段值，这些值将应用到所有选中的记录</NAlert>

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
                  v-if="field.editorType === '下拉框' || field.fieldType === '选项'"
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
        <div v-else-if="!error && !loading" class="text-center text-gray-400 py-8">暂无可修改的字段</div>

        <NSpace justify="end">
          <NButton @click="emit('update:visible', false)">关闭</NButton>
          <NButton v-if="!success" type="primary" :disabled="loading" @click="emit('confirm')">
            {{ confirmText }}
          </NButton>
        </NSpace>
      </NSpace>
    </NSpin>
  </NModal>
</template>

<style scoped>
.form-container {
  max-height: 60vh;
  overflow-y: auto;
}

.form-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 16px;
}

@media (max-width: 768px) {
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
</style>
