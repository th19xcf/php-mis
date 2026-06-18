<script setup lang="ts">
import { computed } from 'vue';
import { NModal, NSpace, NForm, NFormItem, NSelect, NButton, type SelectOption } from 'naive-ui';

export type ColorMarkOperator = '大于' | '小于' | '等于' | '大于等于' | '小于等于' | '不等于';
export type ColorMarkColor = '白底红字' | '白底蓝字' | '黄底红色';

interface Props {
  visible: boolean;
  field1: string;
  operator: string;
  field2: string;
  color: string;
  enabledColumns: SelectOption[];
}

const props = defineProps<Props>();

const emit = defineEmits<{
  'update:visible': [boolean];
  'update:field1': [string];
  'update:operator': [string];
  'update:field2': [string];
  'update:color': [string];
  apply: [];
  clear: [];
}>();

const visibleRef = computed({
  get: () => props.visible,
  set: val => emit('update:visible', val)
});

const operatorOptions = [
  { label: '大于', value: '大于' },
  { label: '小于', value: '小于' },
  { label: '等于', value: '等于' },
  { label: '大于等于', value: '大于等于' },
  { label: '小于等于', value: '小于等于' },
  { label: '不等于', value: '不等于' }
];

const colorOptions = [
  { label: '白底红字', value: '白底红字' },
  { label: '白底蓝字', value: '白底蓝字' },
  { label: '黄底红色', value: '黄底红色' }
];
</script>

<template>
  <NModal v-model:show="visibleRef" preset="card" title="颜色标注设置" class="w-480px" :mask-closable="false">
    <NSpace vertical :size="16">
      <NForm label-placement="left" label-width="80">
        <NFormItem label="字段一">
          <NSelect :value="field1" :options="enabledColumns" @update:value="emit('update:field1', $event)" />
        </NFormItem>
        <NFormItem label="比较符">
          <NSelect :value="operator" :options="operatorOptions" @update:value="emit('update:operator', $event)" />
        </NFormItem>
        <NFormItem label="字段二">
          <NSelect :value="field2" :options="enabledColumns" @update:value="emit('update:field2', $event)" />
        </NFormItem>
        <NFormItem label="颜色">
          <NSelect :value="color" :options="colorOptions" @update:value="emit('update:color', $event)" />
        </NFormItem>
      </NForm>

      <NSpace justify="end">
        <NButton @click="emit('update:visible', false)">取消</NButton>
        <NButton @click="emit('clear')">清除</NButton>
        <NButton type="primary" @click="emit('apply')">应用</NButton>
      </NSpace>
    </NSpace>
  </NModal>
</template>
