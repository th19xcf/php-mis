<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import { useMessage } from 'naive-ui';
import { useContractV2Store } from '@/store/modules/contract-v2';

const props = defineProps<{
  visible: boolean;
  mode: 'create' | 'edit';
  contract: Api.ContractV2.ContractDetail | null;
}>();

const emit = defineEmits<{
  'update:visible': [value: boolean];
  success: [];
}>();

const message = useMessage();
const contractV2Store = useContractV2Store();

const loading = computed(() => contractV2Store.loading);

const formData = ref({
  合同名称: '',
  合同类型: '',
  甲方名称: '',
  甲方联系人: '',
  甲方电话: '',
  乙方名称: '',
  乙方联系人: '',
  乙方电话: '',
  合同金额: 0,
  签订日期: '',
  开始日期: '',
  结束日期: '',
  付款方式: '',
  币别: 'CNY',
  汇率: 1,
  备注: ''
});

const rules = {
  合同名称: { required: true, message: '请输入合同名称' },
  甲方名称: { required: true, message: '请输入甲方名称' },
  乙方名称: { required: true, message: '请输入乙方名称' }
};

watch(
  () => props.visible,
  (val) => {
    if (val) {
      if (props.mode === 'edit' && props.contract) {
        formData.value = {
          合同名称: props.contract.合同名称 || '',
          合同类型: props.contract.合同类型 || '',
          甲方名称: props.contract.甲方名称 || '',
          甲方联系人: props.contract.甲方联系人 || '',
          甲方电话: props.contract.甲方电话 || '',
          乙方名称: props.contract.乙方名称 || '',
          乙方联系人: props.contract.乙方联系人 || '',
          乙方电话: props.contract.乙方电话 || '',
          合同金额: props.contract.合同金额 || 0,
          签订日期: props.contract.签订日期 || '',
          开始日期: props.contract.开始日期 || '',
          结束日期: props.contract.结束日期 || '',
          付款方式: props.contract.付款方式 || '',
          币别: props.contract.币别 || 'CNY',
          汇率: props.contract.汇率 || 1,
          备注: props.contract.备注 || ''
        };
      } else {
        formData.value = {
          合同名称: '',
          合同类型: '',
          甲方名称: '',
          甲方联系人: '',
          甲方电话: '',
          乙方名称: '',
          乙方联系人: '',
          乙方电话: '',
          合同金额: 0,
          签订日期: '',
          开始日期: '',
          结束日期: '',
          付款方式: '',
          币别: 'CNY',
          汇率: 1,
          备注: ''
        };
      }
    }
  }
);

function handleClose() {
  emit('update:visible', false);
}

async function handleSubmit() {
  if (!formData.value.合同名称) {
    message.error('请输入合同名称');
    return;
  }
  if (!formData.value.甲方名称) {
    message.error('请输入甲方名称');
    return;
  }
  if (!formData.value.乙方名称) {
    message.error('请输入乙方名称');
    return;
  }

  try {
    if (props.mode === 'create') {
      await contractV2Store.createContract(formData.value as any);
      message.success('创建成功');
    } else {
      if (!props.contract) return;
      await contractV2Store.updateContract({
        ...(formData.value as any),
        contractNo: props.contract.合同编号
      });
      message.success('更新成功');
    }
    emit('success');
    emit('update:visible', false);
  } catch (e: any) {
    message.error(e?.message || '操作失败');
  }
}

const options = computed(() => contractV2Store.options);
</script>

<template>
  <div v-if="visible" class="modal-overlay" @click.self="handleClose">
    <div class="modal-container">
      <div class="modal-header">
        <h3>{{ mode === 'create' ? '新建合同' : '编辑合同' }}</h3>
        <button class="close-btn" @click="handleClose">×</button>
      </div>
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-item">
            <label>合同名称 <span class="required">*</span></label>
            <input v-model="formData.合同名称" placeholder="请输入合同名称" />
          </div>
          <div class="form-item">
            <label>合同类型</label>
            <select v-model="formData.合同类型">
              <option value="">请选择</option>
              <option v-for="opt in options.合同类型" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </option>
            </select>
          </div>
          <div class="form-item">
            <label>甲方名称 <span class="required">*</span></label>
            <input v-model="formData.甲方名称" placeholder="请输入甲方名称" />
          </div>
          <div class="form-item">
            <label>甲方联系人</label>
            <input v-model="formData.甲方联系人" placeholder="请输入甲方联系人" />
          </div>
          <div class="form-item">
            <label>甲方电话</label>
            <input v-model="formData.甲方电话" placeholder="请输入甲方电话" />
          </div>
          <div class="form-item">
            <label>乙方名称 <span class="required">*</span></label>
            <input v-model="formData.乙方名称" placeholder="请输入乙方名称" />
          </div>
          <div class="form-item">
            <label>乙方联系人</label>
            <input v-model="formData.乙方联系人" placeholder="请输入乙方联系人" />
          </div>
          <div class="form-item">
            <label>乙方电话</label>
            <input v-model="formData.乙方电话" placeholder="请输入乙方电话" />
          </div>
          <div class="form-item">
            <label>合同金额</label>
            <input type="number" v-model.number="formData.合同金额" placeholder="请输入合同金额" />
          </div>
          <div class="form-item">
            <label>付款方式</label>
            <select v-model="formData.付款方式">
              <option value="">请选择</option>
              <option v-for="opt in options.付款方式" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </option>
            </select>
          </div>
          <div class="form-item">
            <label>币别</label>
            <select v-model="formData.币别">
              <option value="">请选择</option>
              <option v-for="opt in options.币别" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </option>
            </select>
          </div>
          <div class="form-item">
            <label>汇率</label>
            <input type="number" v-model.number="formData.汇率" step="0.0001" placeholder="请输入汇率" />
          </div>
          <div class="form-item">
            <label>签订日期</label>
            <input type="date" v-model="formData.签订日期" />
          </div>
          <div class="form-item">
            <label>开始日期</label>
            <input type="date" v-model="formData.开始日期" />
          </div>
          <div class="form-item">
            <label>结束日期</label>
            <input type="date" v-model="formData.结束日期" />
          </div>
          <div class="form-item full">
            <label>备注</label>
            <textarea v-model="formData.备注" rows="3" placeholder="请输入备注"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-default" @click="handleClose">取消</button>
        <button class="btn btn-primary" :disabled="loading" @click="handleSubmit">
          {{ loading ? '提交中...' : '确定' }}
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
  width: 720px;
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
    }

    textarea {
      resize: vertical;
      font-family: inherit;
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
    background: #1890ff;
    color: #fff;

    &:hover {
      background: #40a9ff;
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
