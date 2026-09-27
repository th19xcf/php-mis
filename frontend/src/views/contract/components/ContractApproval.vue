<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { } from 'naive-ui';
import { useContractStore } from '@/store/modules/contract';
import { useMessageWithConsole } from '@/hooks/business/use-message-with-console';

const props = defineProps<{
  visible: boolean;
  contract: Api.Contract.ContractDetail | null;
}>();

const emit = defineEmits<{
  'update:visible': [value: boolean];
  success: [];
}>();

const message = useMessageWithConsole();
const contractStore = useContractStore();

const loading = computed(() => contractStore.loading);

const formData = ref({
  action: '同意' as '同意' | '拒绝',
  opinion: '',
  rejectMode: 'terminate' as 'terminate' | 'sponsor' | 'previous'
});

const pendingTask = computed(() => {
  const tasks = contractStore.pendingTasks;
  if (!props.contract) return null;
  return tasks.find(t => t.业务ID === props.contract?.合同编号) || null;
});

/** 退回发起人任务：由发起人处理，语义为"重新提交"（流程从被退回节点续走） */
const isReturnTask = computed(() => pendingTask.value?.任务类型 === 'RETURN');

// 弹窗打开时拉取最新待办：store.pendingTasks 此前无页面级加载入口，
// 且驳回退回会实时改变待办归属（退回发起人后 RETURN 任务归发起人）
watch(
  () => props.visible,
  visible => {
    if (visible) {
      contractStore.loadPendingTasks();
    }
  }
);

const rejectModeOptions = [
  { value: 'terminate', label: '终止流程' },
  { value: 'sponsor', label: '退回发起人修改' },
  { value: 'previous', label: '退回上一审批节点' }
] as const;

function handleClose() {
  emit('update:visible', false);
  formData.value = { action: '同意', opinion: '', rejectMode: 'terminate' };
}

async function handleSubmit() {
  if (!pendingTask.value) {
    message.error('未找到待审批任务');
    return;
  }

  try {
    // 退回任务按"重新提交"处理（action 固定同意）；拒绝时携带驳回模式
    const action = isReturnTask.value ? '同意' : formData.value.action;
    const rejectMode = action === '拒绝' ? formData.value.rejectMode : 'terminate';
    await contractStore.handleApproval(pendingTask.value.任务ID, action, formData.value.opinion, rejectMode);
    if (isReturnTask.value) {
      message.success('已重新提交，流程从被退回节点续走');
    } else {
      message.success(action === '同意' ? '审批通过' : '已拒绝');
    }
    emit('success');
    emit('update:visible', false);
  } catch (e: any) {
    message.error(e?.message || '审批失败');
  }
}
</script>

<template>
  <div v-if="visible" class="modal-overlay" @click.self="handleClose">
    <div class="modal-container">
      <div class="modal-header">
        <h3>{{ isReturnTask ? '重新提交审批' : '审批合同' }}</h3>
        <button class="close-btn" @click="handleClose">×</button>
      </div>
      <div class="modal-body">
        <div v-if="contract" class="contract-info">
          <div class="info-row">
            <label>合同名称</label>
            <span>{{ contract.合同名称 }}</span>
          </div>
          <div class="info-row">
            <label>合同编号</label>
            <span>{{ contract.合同编号 }}</span>
          </div>
          <div class="info-row">
            <label>甲方</label>
            <span>{{ contract.甲方名称 }}</span>
          </div>
          <div class="info-row">
            <label>乙方</label>
            <span>{{ contract.乙方名称 }}</span>
          </div>
          <div class="info-row">
            <label>合同金额</label>
            <span class="amount">{{ Number(contract.合同金额).toLocaleString('zh-CN') }}</span>
          </div>
        </div>

        <div v-if="isReturnTask" class="return-tip">
          合同已被退回，请确认修改无误后重新提交；提交后流程将从被退回的节点继续审批。
        </div>

        <div class="form-section">
          <div v-if="!isReturnTask" class="form-item">
            <label>审批意见</label>
            <div class="action-radio">
              <label class="radio-item">
                <input type="radio" v-model="formData.action" value="同意" />
                <span>同意</span>
              </label>
              <label class="radio-item">
                <input type="radio" v-model="formData.action" value="拒绝" />
                <span>拒绝</span>
              </label>
            </div>
          </div>
          <div v-if="!isReturnTask && formData.action === '拒绝'" class="form-item">
            <label>驳回方式</label>
            <div class="action-radio reject-mode">
              <label
                v-for="opt in rejectModeOptions"
                :key="opt.value"
                class="radio-item"
              >
                <input type="radio" v-model="formData.rejectMode" :value="opt.value" />
                <span>{{ opt.label }}</span>
              </label>
            </div>
          </div>
          <div class="form-item">
            <label>{{ isReturnTask ? '提交说明' : '意见说明' }}</label>
            <textarea
              v-model="formData.opinion"
              rows="4"
              :placeholder="isReturnTask ? '请输入重新提交说明（可选）' : '请输入审批意见（可选）'"
            ></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-default" @click="handleClose">取消</button>
        <button
          v-if="!isReturnTask"
          class="btn"
          :class="formData.action === '同意' ? 'btn-primary' : 'btn-danger'"
          :disabled="loading"
          @click="handleSubmit"
        >
          {{ loading ? '提交中...' : formData.action === '同意' ? '同意' : '拒绝' }}
        </button>
        <button
          v-else
          class="btn btn-primary"
          :disabled="loading"
          @click="handleSubmit"
        >
          {{ loading ? '提交中...' : '重新提交' }}
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
  width: 520px;
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

.contract-info {
  padding: 16px;
  background: #fafafa;
  border-radius: 6px;
  margin-bottom: 20px;

  .info-row {
    display: flex;
    padding: 6px 0;

    label {
      width: 80px;
      color: #999;
      font-size: 13px;
      flex-shrink: 0;
    }

    span {
      flex: 1;
      color: #333;
      font-size: 14px;

      &.amount {
        color: #1890ff;
        font-weight: 600;
      }
    }
  }
}

.return-tip {
  padding: 10px 16px;
  background: #fff7e6;
  border: 1px solid #ffd591;
  border-radius: 6px;
  color: #ad6800;
  font-size: 13px;
  line-height: 1.6;
  margin-bottom: 20px;
}

.form-section {
  .form-item {
    margin-bottom: 16px;

    label {
      display: block;
      font-size: 13px;
      color: #666;
      margin-bottom: 8px;
    }

    .action-radio {
      display: flex;
      gap: 24px;

      &.reject-mode {
        flex-direction: column;
        gap: 10px;
        align-items: flex-start;
      }

      .radio-item {
        display: flex;
        align-items: center;
        gap: 6px;
        cursor: pointer;
        font-size: 14px;

        input {
          cursor: pointer;
        }
      }
    }

    textarea {
      width: 100%;
      padding: 8px 12px;
      border: 1px solid #d9d9d9;
      border-radius: 4px;
      font-size: 14px;
      outline: none;
      resize: vertical;
      font-family: inherit;
      box-sizing: border-box;

      &:focus {
        border-color: #1890ff;
      }
    }
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
    background: #52c41a;
    color: #fff;

    &:hover {
      background: #73d13d;
    }

    &:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }
  }

  &.btn-danger {
    background: #ff4d4f;
    color: #fff;

    &:hover {
      background: #ff7875;
    }

    &:disabled {
      opacity: 0.5;
      cursor: not-allowed;
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
