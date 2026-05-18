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
</script>

<template>
  <NModal
    :show="visible"
    preset="card"
    title="新增记录"
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

        <div v-if="!success">
          <div style="margin-bottom: 10px; color: #666">字段数量: {{ formFields.length }}</div>
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

        <NSpace justify="end">
          <NButton @click="emit('update:visible', false)">关闭</NButton>
          <NButton v-if="!success" type="primary" :disabled="loading" @click="emit('confirm')">确认新增</NButton>
        </NSpace>
      </NSpace>
    </NSpin>
  </NModal>
</template>

<style scoped>
.add-form-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 16px;
}

@media (max-width: 768px) {
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
</style>
