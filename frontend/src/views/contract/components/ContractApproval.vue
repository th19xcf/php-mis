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
  审核意见: ''
});

const currentContract = computed(() => contractStore.currentContract);

async function handleApprove() {
  if (!formData.value.审核意见.trim()) {
    return;
  }

  submitting.value = true;
  try {
    const res = await contractStore.approveContract({
      GUID: currentContract.value!.GUID,
      审核意见: formData.value.审核意见
    });

    if (res) {
      emit('update:show', false);
      emit('submit');
    }
  } finally {
    submitting.value = false;
  }
}

async function handleReject() {
  if (!formData.value.审核意见.trim()) {
    return;
  }

  submitting.value = true;
  try {
    const res = await contractStore.rejectContract({
      GUID: currentContract.value!.GUID,
      审核意见: formData.value.审核意见
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
    :title="`合同审核 - ${currentContract?.合同名称 || ''}`"
    class="w-120"
    :mask-closable="false"
    @update:show="val => emit('update:show', val)"
  >
    <NForm :model="formData" label-placement="left" label-width="80">
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
      <NFormItem label="审核意见" required>
        <NInput v-model:value="formData.审核意见" type="textarea" placeholder="请输入审核意见" :rows="4" />
      </NFormItem>
    </NForm>

    <template #footer>
      <NSpace justify="end">
        <NButton @click="handleCancel">取消</NButton>
        <NButton type="error" :loading="submitting" @click="handleReject">拒绝</NButton>
        <NButton type="success" :loading="submitting" @click="handleApprove">审核通过</NButton>
      </NSpace>
    </template>
  </NModal>
</template>
