<script setup lang="ts">
import { computed } from 'vue';
import { NDrawer, NDrawerContent, NSpace, NForm, NFormItem, NSelect, NInput, NAlert, NButton } from 'naive-ui';
import type { ConditionOperator } from '@/typings/menu-bridge';

interface Props {
  visible: boolean;
  selectedField: string;
  selectedOperator: ConditionOperator;
  selectedValue: string;
  filterableFields: string[];
}

const props = defineProps<Props>();

const emit = defineEmits<{
  'update:visible': [boolean];
  'update:selectedField': [string];
  'update:selectedOperator': [ConditionOperator];
  'update:selectedValue': [string];
  apply: [];
}>();

const visibleRef = computed({
  get: () => props.visible,
  set: val => emit('update:visible', val)
});

const fieldOptions = computed(() => props.filterableFields.map(field => ({ label: field, value: field })));

const operatorOptions = [
  { label: '包含', value: 'contains' as ConditionOperator },
  { label: '等于', value: 'equals' as ConditionOperator },
  { label: '前缀匹配', value: 'startsWith' as ConditionOperator }
];
</script>

<template>
  <NDrawer v-model:show="visibleRef" :width="420" placement="right">
    <NDrawerContent title="条件面板" closable>
      <NSpace vertical :size="16">
        <NForm label-placement="top">
          <NFormItem label="字段">
            <NSelect
              :value="selectedField"
              :options="fieldOptions"
              @update:value="emit('update:selectedField', $event)"
            />
          </NFormItem>
          <NFormItem label="操作符">
            <NSelect
              :value="selectedOperator"
              :options="operatorOptions"
              @update:value="emit('update:selectedOperator', $event)"
            />
          </NFormItem>
          <NFormItem label="取值">
            <NInput
              :value="selectedValue"
              placeholder="输入筛选值"
              @update:value="emit('update:selectedValue', $event)"
            />
          </NFormItem>
        </NForm>

        <NAlert type="warning">
          当前已接到后端 JSON 协议；后续只需继续补齐新增、修改、删除、备注、钻取等动作接口。
        </NAlert>

        <NSpace justify="end">
          <NButton @click="emit('update:visible', false)">取消</NButton>
          <NButton type="primary" @click="emit('apply')">应用</NButton>
        </NSpace>
      </NSpace>
    </NDrawerContent>
  </NDrawer>
</template>
