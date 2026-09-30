<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import { NModal, NForm, NFormItem, NInput, NSelect, NSpace, NButton } from 'naive-ui';
import { useMessageWithConsole } from '@/hooks/business/use-message-with-console';
import {
  fetchWorkflowDefinitionCreate,
  fetchWorkflowDefinitionUpdate
} from '@/service/api/workflow';

const props = defineProps<{
  visible: boolean;
  mode: 'create' | 'edit';
  definition: Record<string, any> | null;
  inline?: boolean;
}>();

const emit = defineEmits<{
  'update:visible': [value: boolean];
  success: [];
}>();

const message = useMessageWithConsole();

const formData = ref({
  流程编码: '',
  流程名称: '',
  业务类型: '合同',
  流程状态: '草稿',
  流程描述: '',
  审批人配置: '' as string,
  超时规则: '' as string
});

const businessTypeOptions = [
  { label: '合同', value: '合同' },
  { label: '员工', value: '员工' },
  { label: '请假', value: '请假' }
];

const statusOptions = [
  { label: '草稿', value: '草稿' },
  { label: '启用', value: '启用' },
  { label: '停用', value: '停用' }
];

function parseJsonObject(value: any): string {
  if (!value) return '';
  if (typeof value === 'string') return value;
  try {
    return JSON.stringify(value, null, 2);
  } catch {
    return '';
  }
}

watch(
  () => props.visible,
  (val) => {
    if (val) {
      if (props.mode === 'edit' && props.definition) {
        formData.value = {
          流程编码: props.definition.流程编码 || '',
          流程名称: props.definition.流程名称 || '',
          业务类型: props.definition.业务类型 || '合同',
          流程状态: props.definition.流程状态 || '草稿',
          流程描述: props.definition.流程描述 || '',
          审批人配置: parseJsonObject(props.definition.审批人配置),
          超时规则: parseJsonObject(props.definition.超时规则)
        };
      } else {
        formData.value = {
          流程编码: '',
          流程名称: '',
          业务类型: '合同',
          流程状态: '草稿',
          流程描述: '',
          审批人配置: '',
          超时规则: ''
        };
      }
    }
  },
  { immediate: true }
);

function handleClose() {
  emit('update:visible', false);
}

function buildPayload() {
  const payload: Record<string, any> = {
    流程编码: formData.value.流程编码,
    流程名称: formData.value.流程名称,
    业务类型: formData.value.业务类型,
    流程描述: formData.value.流程描述
  };

  // 流程状态仅在新建时传递,编辑时由启用/停用接口控制
  if (props.mode === 'create') {
    payload.流程状态 = formData.value.流程状态;
  }

  // 审批人配置: 非空时解析为对象
  if (formData.value.审批人配置.trim()) {
    try {
      payload.审批人配置 = JSON.parse(formData.value.审批人配置);
    } catch {
      throw new Error('审批人配置 JSON 格式错误');
    }
  }

  // 超时规则: 非空时解析为对象
  if (formData.value.超时规则.trim()) {
    try {
      payload.超时规则 = JSON.parse(formData.value.超时规则);
    } catch {
      throw new Error('超时规则 JSON 格式错误');
    }
  }

  return payload;
}

async function handleSubmit() {
  if (!formData.value.流程编码) {
    message.error('请输入流程编码');
    return;
  }
  if (!formData.value.流程名称) {
    message.error('请输入流程名称');
    return;
  }

  try {
    const payload = buildPayload();
    if (props.mode === 'create') {
      await fetchWorkflowDefinitionCreate(payload);
      message.success('创建成功');
    } else {
      if (!props.definition) return;
      await fetchWorkflowDefinitionUpdate({
        defId: props.definition.GUID,
        ...payload
      });
      message.success('更新成功');
    }
    emit('success');
    emit('update:visible', false);
  } catch (e: any) {
    message.error(e?.message || '操作失败');
  }
}

// 暴露 submit 方法供父组件内联调用
defineExpose({
  submit: handleSubmit
});
</script>

<template>
  <!-- 内联模式(右侧面板直接编辑,表格化布局) -->
  <div v-if="inline && visible" class="inline-form">
    <div class="edit-table">
      <div class="edit-row edit-head">
        <div class="edit-cell edit-cell-name">列名</div>
        <div class="edit-cell edit-cell-value">列值</div>
      </div>
      <div class="edit-row">
        <div class="edit-cell edit-cell-name">流程编码<span class="required-mark">*</span></div>
        <div class="edit-cell edit-cell-value">
          <NInput v-model:value="formData.流程编码" placeholder="请输入流程编码" size="small" :disabled="mode === 'edit'" />
        </div>
      </div>
      <div class="edit-row">
        <div class="edit-cell edit-cell-name">流程名称<span class="required-mark">*</span></div>
        <div class="edit-cell edit-cell-value">
          <NInput v-model:value="formData.流程名称" placeholder="请输入流程名称" size="small" />
        </div>
      </div>
      <div class="edit-row">
        <div class="edit-cell edit-cell-name">业务类型</div>
        <div class="edit-cell edit-cell-value">
          <NSelect v-model:value="formData.业务类型" :options="businessTypeOptions" size="small" />
        </div>
      </div>
      <div class="edit-row" v-if="mode === 'create'">
        <div class="edit-cell edit-cell-name">流程状态</div>
        <div class="edit-cell edit-cell-value">
          <NSelect v-model:value="formData.流程状态" :options="statusOptions" size="small" />
        </div>
      </div>
      <div class="edit-row">
        <div class="edit-cell edit-cell-name">流程描述</div>
        <div class="edit-cell edit-cell-value">
          <NInput v-model:value="formData.流程描述" type="textarea" :rows="2" placeholder="请输入流程描述" size="small" />
        </div>
      </div>
      <div class="edit-row">
        <div class="edit-cell edit-cell-name">审批人配置</div>
        <div class="edit-cell edit-cell-value">
          <NInput
            v-model:value="formData.审批人配置"
            type="textarea"
            :rows="6"
            placeholder='JSON 格式,例如:{"nodes":[{"code":"start","name":"开始"}]}'
            size="small"
            class="json-input"
          />
        </div>
      </div>
      <div class="edit-row">
        <div class="edit-cell edit-cell-name">超时规则</div>
        <div class="edit-cell edit-cell-value">
          <NInput
            v-model:value="formData.超时规则"
            type="textarea"
            :rows="4"
            placeholder='JSON 格式,例如:{"timeoutMinutes":1440,"action":"NOTIFY"}'
            size="small"
            class="json-input"
          />
        </div>
      </div>
    </div>
  </div>

  <!-- 弹窗模式(新建流程) -->
  <NModal
    v-else-if="!inline && visible"
    :show="visible"
    preset="card"
    :title="mode === 'create' ? '新建流程' : '编辑流程'"
    :mask-closable="false"
    @update:show="val => !val && handleClose()"
    style="width: 560px"
  >
    <NForm label-placement="left" :label-width="90">
      <NFormItem label="流程编码" required>
        <NInput v-model:value="formData.流程编码" placeholder="请输入流程编码" :disabled="mode === 'edit'" />
      </NFormItem>
      <NFormItem label="流程名称" required>
        <NInput v-model:value="formData.流程名称" placeholder="请输入流程名称" />
      </NFormItem>
      <NFormItem label="业务类型">
        <NSelect v-model:value="formData.业务类型" :options="businessTypeOptions" />
      </NFormItem>
      <NFormItem v-if="mode === 'create'" label="流程状态">
        <NSelect v-model:value="formData.流程状态" :options="statusOptions" />
      </NFormItem>
      <NFormItem label="流程描述">
        <NInput v-model:value="formData.流程描述" type="textarea" :rows="2" placeholder="请输入流程描述" />
      </NFormItem>
      <NFormItem label="审批人配置">
        <NInput
          v-model:value="formData.审批人配置"
          type="textarea"
          :rows="5"
          placeholder='JSON 格式,例如:{"nodes":[{"code":"start","name":"开始"}]}'
          class="json-textarea"
        />
      </NFormItem>
      <NFormItem label="超时规则">
        <NInput
          v-model:value="formData.超时规则"
          type="textarea"
          :rows="4"
          placeholder='JSON 格式,例如:{"timeoutMinutes":1440,"action":"NOTIFY"}'
          class="json-textarea"
        />
      </NFormItem>
    </NForm>
    <div class="modal-notice">
      提示:流程节点和连线配置请在流程设计器中完成。
    </div>
    <template #footer>
      <NSpace justify="end">
        <NButton @click="handleClose">取消</NButton>
        <NButton type="primary" @click="handleSubmit">确定</NButton>
      </NSpace>
    </template>
  </NModal>
</template>

<style scoped lang="scss">
.inline-form {
  padding: 0;
}

// 表格化布局(参照 ContractForm 的 edit-table)
.edit-table {
  display: flex;
  flex-direction: column;
  border: 1px solid #e8e8e8;
  border-radius: 4px;
  overflow: hidden;
  font-size: 13px;

  .edit-row {
    display: flex;
    align-items: stretch;
    border-bottom: 1px solid #e8e8e8;
    color: #333;

    &:last-child {
      border-bottom: none;
    }

    &.edit-head {
      font-weight: 500;
      color: #333;
    }
  }

  .edit-cell {
    padding: 10px 12px;
    display: flex;
    align-items: center;
    min-height: 38px;
    line-height: 1.4;
    color: inherit;
  }

  .edit-cell-name {
    width: 120px;
    flex-shrink: 0;
    border-right: 1px solid #e8e8e8;
    color: #333;
  }

  .edit-cell-value {
    flex: 1;
    background: transparent;
    color: inherit;

    :deep(.n-input),
    :deep(.n-select),
    :deep(.n-date-picker),
    :deep(.n-input-number) {
      width: 100%;
    }

    .json-input :deep(textarea) {
      font-family: 'Consolas', 'Monaco', monospace;
      font-size: 12px;
    }
  }

  .required-mark {
    color: #ff4d4f;
    margin-left: 2px;
  }
}

// 暗黑模式适配
.system-dark .edit-table {
  border-color: rgba(255, 255, 255, 0.09);

  .edit-row {
    border-color: rgba(255, 255, 255, 0.09);
    color: #e0e0e0;

    &.edit-head {
      color: #e0e0e0;
    }
  }

  .edit-cell-name {
    border-color: rgba(255, 255, 255, 0.09);
    color: #e0e0e0;
  }
}

// 弹窗提示条（对齐 naive-ui 风格）
.modal-notice {
  margin-top: 4px;
  padding: 8px 12px;
  background: rgb(255, 251, 230);
  border-radius: 4px;
  border: 1px solid rgb(255, 229, 143);
  font-size: 13px;
  color: rgb(212, 136, 6);
}

// JSON 文本框等宽字体（应用于 NInput textarea）
.json-textarea :deep(textarea) {
  font-family: 'Consolas', 'Monaco', monospace;
  font-size: 12px;
}
</style>
