<script setup lang="ts">
import { ref, computed } from 'vue';
import { useContractStore } from '@/store/modules/contract';

defineProps<{
  show: boolean;
}>();

const emit = defineEmits<{
  (e: 'update:show', value: boolean): void;
  (e: 'submit'): void;
}>();

const contractStore = useContractStore();
const submitting = ref(false);

const formData = ref({
  签署公司: ''
});

const currentContract = computed(() => contractStore.currentContract);
const agreeTerms = ref(false);

async function handleSubmit() {
  if (!formData.value.签署公司.trim()) {
    return;
  }

  submitting.value = true;
  try {
    const res = await contractStore.signContract({
      GUID: currentContract.value!.GUID,
      签署公司: formData.value.签署公司
    });

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
    :title="`合同签署 - ${currentContract?.合同名称 || ''}`"
    class="w-120"
    :mask-closable="false"
    @update:show="val => emit('update:show', val)"
  >
    <NForm :model="formData" label-placement="left" label-width="100">
      <NFormItem label="合同编号">
        <NInput :value="currentContract?.合同编号" disabled />
      </NFormItem>
      <NFormItem label="甲方">
        <NInput :value="currentContract?.甲方名称" disabled />
      </NFormItem>
      <NFormItem label="乙方">
        <NInput :value="currentContract?.乙方名称" disabled />
      </NFormItem>
      <NFormItem label="合同金额">
        <NInput :value="currentContract?.合同金额 ? `¥${currentContract.合同金额.toLocaleString()}` : '-'" disabled />
      </NFormItem>

      <NDivider>签署信息</NDivider>

      <NFormItem label="签署公司" required>
        <NInput v-model:value="formData.签署公司" placeholder="请输入签署公司名称" />
      </NFormItem>

      <NFormItem>
        <NCheckbox v-model:checked="agreeTerms">我已阅读并同意《合同条款》和《签署须知》</NCheckbox>
      </NFormItem>
    </NForm>

    <template #footer>
      <NSpace justify="end">
        <NButton @click="handleCancel">取消</NButton>
        <NButton type="primary" :loading="submitting" :disabled="!agreeTerms" @click="handleSubmit">确认签署</NButton>
      </NSpace>
    </template>
  </NModal>
</template>
