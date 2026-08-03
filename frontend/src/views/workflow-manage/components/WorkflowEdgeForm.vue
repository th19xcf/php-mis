<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import { useMessage } from 'naive-ui';
import {
  fetchWorkflowEdgeCreate,
  fetchWorkflowEdgeUpdate
} from '@/service/api/workflow';

const props = defineProps<{
  visible: boolean;
  mode: 'create' | 'edit';
  defId: number;
  edge: Record<string, any> | null;
  nodes: Array<Record<string, any>>; // 同流程下的全部节点,用于下拉选择
}>();

const emit = defineEmits<{
  'update:visible': [value: boolean];
  success: [];
}>();

const message = useMessage();

const formData = ref({
  源节点编码: '',
  目标节点编码: '',
  条件表达式: '',
  条件描述: '',
  排序: 0
});

// 节点选项(显示编码+名称)
const nodeOptions = computed(() => {
  if (!props.nodes || props.nodes.length === 0) return [];
  return props.nodes.map((n) => ({
    label: `${n.节点编码} (${n.节点名称 || '-'})`,
    value: n.节点编码
  }));
});

watch(
  () => props.visible,
  (val) => {
    if (!val) return;
    if (props.mode === 'edit' && props.edge) {
      formData.value = {
        源节点编码: props.edge.源节点编码 || '',
        目标节点编码: props.edge.目标节点编码 || '',
        条件表达式: props.edge.条件表达式 || '',
        条件描述: props.edge.条件描述 || '',
        排序: Number(props.edge.排序) || 0
      };
    } else {
      formData.value = {
        源节点编码: '',
        目标节点编码: '',
        条件表达式: '',
        条件描述: '',
        排序: 0
      };
    }
  },
  { immediate: true }
);

function handleClose() {
  emit('update:visible', false);
}

async function handleSubmit() {
  if (!formData.value.源节点编码) {
    message.error('请选择源节点');
    return;
  }
  if (!formData.value.目标节点编码) {
    message.error('请选择目标节点');
    return;
  }
  if (formData.value.源节点编码 === formData.value.目标节点编码) {
    message.error('源节点和目标节点不能相同');
    return;
  }

  try {
    const payload: Record<string, any> = {
      源节点编码: formData.value.源节点编码,
      目标节点编码: formData.value.目标节点编码,
      条件表达式: formData.value.条件表达式.trim() || null,
      条件描述: formData.value.条件描述.trim() || null,
      排序: Number(formData.value.排序) || 0
    };

    if (props.mode === 'create') {
      await fetchWorkflowEdgeCreate({ 流程定义ID: props.defId, ...payload } as any);
      message.success('连线创建成功');
    } else if (props.edge) {
      await fetchWorkflowEdgeUpdate({ edgeId: props.edge.GUID, ...payload } as any);
      message.success('连线更新成功');
    }
    emit('success');
    emit('update:visible', false);
  } catch (e: any) {
    message.error(e?.message || '操作失败');
  }
}
</script>

<template>
  <NModal
    :show="visible"
    preset="card"
    :title="mode === 'create' ? '新增连线' : '编辑连线'"
    style="width: 560px"
    :bordered="false"
    size="huge"
    @update:show="(v: boolean) => emit('update:visible', v)"
  >
    <NSpace vertical :size="16">
      <NGrid :cols="2" :x-gap="16" :y-gap="12" responsive="screen">
        <NGi>
          <div class="form-item">
            <label class="form-label">源节点 <span class="required">*</span></label>
            <NSelect
              v-model:value="formData.源节点编码"
              :options="nodeOptions"
              placeholder="选择源节点"
              :disabled="nodeOptions.length === 0"
              filterable
            />
          </div>
        </NGi>
        <NGi>
          <div class="form-item">
            <label class="form-label">目标节点 <span class="required">*</span></label>
            <NSelect
              v-model:value="formData.目标节点编码"
              :options="nodeOptions"
              placeholder="选择目标节点"
              :disabled="nodeOptions.length === 0"
              filterable
            />
          </div>
        </NGi>
        <NGi>
          <div class="form-item">
            <label class="form-label">排序</label>
            <NInputNumber
              v-model:value="formData.排序"
              :min="0"
              placeholder="0 表示自动追加"
              style="width: 100%"
            />
          </div>
        </NGi>
      </NGrid>

      <div class="form-item">
        <label class="form-label">条件表达式</label>
        <NInput
          v-model:value="formData.条件表达式"
          placeholder="如:amount > 1000000(留空表示默认流转)"
        />
        <div class="form-tip">
          <NIcon size="14"><icon-mdi-information-outline /></NIcon>
          <span>多分支场景按排序先后匹配,留空表示无条件默认流转</span>
        </div>
      </div>

      <div class="form-item">
        <label class="form-label">条件描述</label>
        <NInput v-model:value="formData.条件描述" placeholder="如:金额大于100万" />
      </div>
    </NSpace>

    <template #footer>
      <NSpace justify="end">
        <NButton @click="handleClose">取消</NButton>
        <NButton type="primary" @click="handleSubmit">确定</NButton>
      </NSpace>
    </template>
  </NModal>
</template>

<style scoped lang="scss">
.form-item {
  display: flex;
  flex-direction: column;
  gap: 6px;

  .form-label {
    font-size: 13px;
    color: #666;

    .required {
      color: #ff4d4f;
    }
  }

  .form-tip {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    color: #999;
    line-height: 1.4;
  }
}

.system-dark .form-item {
  .form-label {
    color: #b0b0b0;
  }

  .form-tip {
    color: #888;
  }
}
</style>
