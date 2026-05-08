<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import { useContractStore } from '@/store/modules/contract';

const props = defineProps<{
  show: boolean;
  mode: 'create' | 'edit';
}>();

const emit = defineEmits<{
  (e: 'update:show', value: boolean): void;
  (e: 'submit'): void;
}>();

const contractStore = useContractStore();
const submitting = ref(false);

const formRef = ref();
const formData = ref({
  GUID: 0,
  合同名称: '',
  合同类型: '',
  甲方名称: '',
  甲方联系人: '',
  甲方电话: '',
  乙方名称: '',
  乙方联系人: '',
  乙方电话: '',
  合同金额: undefined as number | undefined,
  签订日期: null as string | null,
  开始日期: null as string | null,
  结束日期: null as string | null,
  付款方式: '',
  备注: ''
});

const rules = {
  合同名称: [{ required: true, message: '请输入合同名称', trigger: 'blur' }],
  甲方名称: [{ required: true, message: '请输入甲方名称', trigger: 'blur' }],
  乙方名称: [{ required: true, message: '请输入乙方名称', trigger: 'blur' }]
};

const options = computed(() => contractStore.options);

watch(
  () => props.show,
  async val => {
    if (val) {
      if (props.mode === 'edit' && contractStore.currentContract) {
        const c = contractStore.currentContract;
        formData.value = {
          GUID: c.GUID,
          合同名称: c.合同名称,
          合同类型: c.合同类型,
          甲方名称: c.甲方名称,
          甲方联系人: c.甲方联系人,
          甲方电话: c.甲方电话,
          乙方名称: c.乙方名称,
          乙方联系人: c.乙方联系人,
          乙方电话: c.乙方电话,
          合同金额: c.合同金额,
          签订日期: c.签订日期,
          开始日期: c.开始日期,
          结束日期: c.结束日期,
          付款方式: c.付款方式,
          备注: c.备注
        };
      } else {
        formData.value = {
          GUID: 0,
          合同名称: '',
          合同类型: '',
          甲方名称: '',
          甲方联系人: '',
          甲方电话: '',
          乙方名称: '',
          乙方联系人: '',
          乙方电话: '',
          合同金额: undefined,
          签订日期: null,
          开始日期: null,
          结束日期: null,
          付款方式: '',
          备注: ''
        };
      }
    }
  }
);

async function handleSubmit() {
  try {
    await formRef.value?.validate();
  } catch {
    return;
  }

  submitting.value = true;
  try {
    let res;
    if (props.mode === 'create') {
      res = await contractStore.createContract(formData.value);
    } else {
      res = await contractStore.updateContract(formData.value as Api.Contract.ContractUpdateParams);
    }

    if (res) {
      emit('update:show', false);
      emit('submit');
    }
  } finally {
    submitting.value = false;
  }
}

function handleCancel() {
  emit('update:show', false);
}
</script>

<template>
  <NModal
    :show="show"
    preset="card"
    :title="mode === 'create' ? '新建合同' : '编辑合同'"
    class="w-200"
    :mask-closable="false"
    @update:show="val => emit('update:show', val)"
  >
    <NForm
      ref="formRef"
      :model="formData"
      :rules="rules"
      label-placement="left"
      label-width="100"
      require-mark-placement="right-hanging"
    >
      <NFormItem label="合同名称" path="合同名称">
        <NInput v-model:value="formData.合同名称" placeholder="请输入合同名称" />
      </NFormItem>
      <NFormItem label="合同类型" path="合同类型">
        <NSelect v-model:value="formData.合同类型" :options="options.合同类型" placeholder="请选择合同类型" clearable />
      </NFormItem>

      <NDivider>甲方信息</NDivider>

      <NFormItem label="甲方名称" path="甲方名称">
        <NInput v-model:value="formData.甲方名称" placeholder="请输入甲方名称" />
      </NFormItem>
      <NFormItem label="甲方联系人">
        <NInput v-model:value="formData.甲方联系人" placeholder="请输入甲方联系人" />
      </NFormItem>
      <NFormItem label="甲方电话">
        <NInput v-model:value="formData.甲方电话" placeholder="请输入甲方电话" />
      </NFormItem>

      <NDivider>乙方信息</NDivider>

      <NFormItem label="乙方名称" path="乙方名称">
        <NInput v-model:value="formData.乙方名称" placeholder="请输入乙方名称" />
      </NFormItem>
      <NFormItem label="乙方联系人">
        <NInput v-model:value="formData.乙方联系人" placeholder="请输入乙方联系人" />
      </NFormItem>
      <NFormItem label="乙方电话">
        <NInput v-model:value="formData.乙方电话" placeholder="请输入乙方电话" />
      </NFormItem>

      <NDivider>合同信息</NDivider>

      <NFormItem label="合同金额">
        <NInputNumber
          v-model:value="formData.合同金额"
          placeholder="请输入合同金额"
          :min="0"
          :precision="2"
          class="w-full"
        />
      </NFormItem>
      <NFormItem label="签订日期">
        <NDatePicker
          v-model:formatted-value="formData.签订日期"
          value-format="yyyy-MM-dd"
          type="date"
          class="w-full"
          clearable
        />
      </NFormItem>
      <NFormItem label="开始日期">
        <NDatePicker
          v-model:formatted-value="formData.开始日期"
          value-format="yyyy-MM-dd"
          type="date"
          class="w-full"
          clearable
        />
      </NFormItem>
      <NFormItem label="结束日期">
        <NDatePicker
          v-model:formatted-value="formData.结束日期"
          value-format="yyyy-MM-dd"
          type="date"
          class="w-full"
          clearable
        />
      </NFormItem>
      <NFormItem label="付款方式">
        <NSelect v-model:value="formData.付款方式" :options="options.付款方式" placeholder="请选择付款方式" clearable />
      </NFormItem>
      <NFormItem label="备注">
        <NInput v-model:value="formData.备注" type="textarea" placeholder="请输入备注" :rows="3" />
      </NFormItem>
    </NForm>

    <template #footer>
      <NSpace justify="end">
        <NButton @click="handleCancel">取消</NButton>
        <NButton type="primary" :loading="submitting" @click="handleSubmit">确认</NButton>
      </NSpace>
    </template>
  </NModal>
</template>
