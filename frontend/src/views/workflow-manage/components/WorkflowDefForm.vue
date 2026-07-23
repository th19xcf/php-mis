<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import { useMessage } from 'naive-ui';
import {
  fetchWorkflowDefinitionCreate,
  fetchWorkflowDefinitionUpdate
} from '@/service/api/workflow';

const props = defineProps<{
  visible: boolean;
  mode: 'create' | 'edit';
  definition: Record<string, any> | null;
}>();

const emit = defineEmits<{
  'update:visible': [value: boolean];
  success: [];
}>();

const message = useMessage();

const formData = ref({
  流程编码: '',
  流程名称: '',
  业务类型: 'CONTRACT',
  流程状态: 'DRAFT',
  流程描述: '',
  审批人配置: {} as Record<string, any>,
  超时规则: {} as Record<string, any>
});

const businessTypeOptions = [
  { value: 'CONTRACT', label: '合同' },
  { value: 'EMPLOYEE', label: '员工' },
  { value: 'LEAVE', label: '请假' }
];

const statusOptions = [
  { value: 'DRAFT', label: '草稿' },
  { value: 'ACTIVE', label: '启用' },
  { value: 'INACTIVE', label: '停用' }
];

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
          审批人配置: props.definition.审批人配置 || {},
          超时规则: props.definition.超时规则 || {}
        };
      } else {
        formData.value = {
          流程编码: '',
          流程名称: '',
          业务类型: 'CONTRACT',
          流程状态: 'DRAFT',
          流程描述: '',
          审批人配置: {},
          超时规则: {}
        };
      }
    }
  }
);

function handleClose() {
  emit('update:visible', false);
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
    if (props.mode === 'create') {
      await fetchWorkflowDefinitionCreate(formData.value);
      message.success('创建成功');
    } else {
      if (!props.definition) return;
      await fetchWorkflowDefinitionUpdate({
        defId: props.definition.GUID,
        ...formData.value
      });
      message.success('更新成功');
    }
    emit('success');
    emit('update:visible', false);
  } catch (e: any) {
    message.error(e?.message || '操作失败');
  }
}
</script>

<template>
  <div v-if="visible" class="modal-overlay" @click.self="handleClose">
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
          <div class="form-item">
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
        </div>
        <div class="notice">
          <p>提示：流程节点和连线配置请在流程设计器中完成。</p>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-default" @click="handleClose">取消</button>
        <button class="btn btn-primary" @click="handleSubmit">
          确定
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
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
  width: 560px;
  max-height: 80vh;
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
