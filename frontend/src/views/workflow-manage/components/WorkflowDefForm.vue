<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import {  } from 'naive-ui';
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
  业务类型: 'CONTRACT',
  流程状态: 'DRAFT',
  流程描述: '',
  审批人配置: '' as string,
  超时规则: '' as string
});

const businessTypeOptions = [
  { label: '合同', value: 'CONTRACT' },
  { label: '员工', value: 'EMPLOYEE' },
  { label: '请假', value: 'LEAVE' }
];

const statusOptions = [
  { label: '草稿', value: 'DRAFT' },
  { label: '启用', value: 'ACTIVE' },
  { label: '停用', value: 'INACTIVE' }
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
          业务类型: props.definition.业务类型 || 'CONTRACT',
          流程状态: props.definition.流程状态 || 'DRAFT',
          流程描述: props.definition.流程描述 || '',
          审批人配置: parseJsonObject(props.definition.审批人配置),
          超时规则: parseJsonObject(props.definition.超时规则)
        };
      } else {
        formData.value = {
          流程编码: '',
          流程名称: '',
          业务类型: 'CONTRACT',
          流程状态: 'DRAFT',
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
  <div v-else-if="!inline && visible" class="modal-overlay" @click.self="handleClose">
    <div class="modal-container">
      <div class="modal-header">
        <h3>{{ mode === 'create' ? '新建流程' : '编辑流程' }}</h3>
        <button class="close-btn" @click="handleClose">×</button>
      </div>
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-item">
            <label>流程编码 <span class="required">*</span></label>
            <input v-model="formData.流程编码" placeholder="请输入流程编码" :disabled="mode === 'edit'" />
          </div>
          <div class="form-item">
            <label>流程名称 <span class="required">*</span></label>
            <input v-model="formData.流程名称" placeholder="请输入流程名称" />
          </div>
          <div class="form-item">
            <label>业务类型</label>
            <select v-model="formData.业务类型">
              <option v-for="opt in businessTypeOptions" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </option>
            </select>
          </div>
          <div class="form-item" v-if="mode === 'create'">
            <label>流程状态</label>
            <select v-model="formData.流程状态">
              <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </option>
            </select>
          </div>
          <div class="form-item full">
            <label>流程描述</label>
            <textarea v-model="formData.流程描述" rows="3" placeholder="请输入流程描述"></textarea>
          </div>
          <div class="form-item full">
            <label>审批人配置 (JSON)</label>
            <textarea
              v-model="formData.审批人配置"
              rows="6"
              placeholder='例如:{"nodes":[{"code":"start","name":"开始"}]}'
              class="json-textarea"
            ></textarea>
          </div>
          <div class="form-item full">
            <label>超时规则 (JSON)</label>
            <textarea
              v-model="formData.超时规则"
              rows="4"
              placeholder='例如:{"timeoutMinutes":1440,"action":"NOTIFY"}'
              class="json-textarea"
            ></textarea>
          </div>
        </div>
        <div class="notice">
          <p>提示:流程节点和连线配置请在流程设计器中完成。</p>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-default" @click="handleClose">取消</button>
        <button class="btn btn-primary" @click="handleSubmit">确定</button>
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
.inline-form {
  padding: 0;
}

// 表格化布局(参照 ContractV2Form 的 edit-table)
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

.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.modal-container {
  width: 600px;
  max-height: 85vh;
  background: #fff;
  border-radius: 8px;
  display: flex;
  flex-direction: column;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 20px;
  border-bottom: 1px solid #f0f0f0;

  h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
  }

  .close-btn {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #999;
    line-height: 1;

    &:hover {
      color: #333;
    }
  }
}

.modal-body {
  flex: 1;
  overflow-y: auto;
  padding: 20px;
}

.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;

  .form-item {
    display: flex;
    flex-direction: column;
    gap: 6px;

    &.full {
      grid-column: span 2;
    }

    label {
      font-size: 13px;
      color: #666;

      .required {
        color: #ff4d4f;
      }
    }

    input,
    select,
    textarea {
      padding: 8px 12px;
      border: 1px solid #d9d9d9;
      border-radius: 4px;
      font-size: 14px;
      outline: none;
      transition: border-color 0.2s;

      &:focus {
        border-color: #1890ff;
      }

      &:disabled {
        background: #f5f5f5;
        cursor: not-allowed;
      }
    }

    textarea {
      resize: vertical;
      font-family: inherit;

      &.json-textarea {
        font-family: 'Consolas', 'Monaco', monospace;
        font-size: 12px;
      }
    }
  }
}

.notice {
  margin-top: 16px;
  padding: 12px 16px;
  background: #fffbe6;
  border-radius: 4px;
  border: 1px solid #ffe58f;

  p {
    margin: 0;
    font-size: 13px;
    color: #d48806;
  }
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  padding: 16px 20px;
  border-top: 1px solid #f0f0f0;
}

.btn {
  padding: 8px 20px;
  border-radius: 4px;
  font-size: 14px;
  cursor: pointer;
  border: none;
  transition: all 0.2s;

  &.btn-primary {
    background: #1890ff;
    color: #fff;

    &:hover {
      background: #40a9ff;
    }
  }

  &.btn-default {
    background: #fff;
    color: #333;
    border: 1px solid #d9d9d9;

    &:hover {
      border-color: #1890ff;
      color: #1890ff;
    }
  }
}
</style>
