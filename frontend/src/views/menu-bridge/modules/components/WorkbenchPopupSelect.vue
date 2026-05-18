<script setup lang="ts">
import { NModal, NSpin, NSpace, NButton, NFormItem, NCascader, NEmpty, NText } from 'naive-ui';

defineProps<{
  visible: boolean;
  loading: boolean;
  field: any;
  selectedValue: string | null;
  cascaderOptions: any[];
  levels: any[];
  maxLevel: number;
}>();

const emit = defineEmits<{
  'update:visible': [value: boolean];
  'update:selectedValue': [value: string | null, option: any];
  confirm: [];
  loadChildren: [node: any];
}>();

function handleLoadChildren(node: any) {
  emit('loadChildren', node);
  return Promise.resolve();
}

function handleValueChange(value: string | null, option: any) {
  emit('update:selectedValue', value, option);
}
</script>

<template>
  <NModal
    :show="visible"
    preset="card"
    :title="field?.columnName || '选择'"
    class="w-600px"
    :mask-closable="false"
    @update:show="emit('update:visible', $event)"
  >
    <NSpin :show="loading">
      <NSpace vertical :size="16">
        <NFormItem label="选择路径">
          <NCascader
            :value="selectedValue"
            :options="cascaderOptions"
            :on-load="handleLoadChildren"
            remote
            expand-trigger="click"
            placeholder="请选择"
            clearable
            @update:value="handleValueChange"
          />
        </NFormItem>

        <div v-if="levels.length" class="popup-levels-hint">
          <NText depth="3">
            共 {{ maxLevel }} 级：
            <span v-for="(level, index) in levels" :key="level.level">
              {{ level.name }}
              <span v-if="index < levels.length - 1">→</span>
            </span>
          </NText>
        </div>
        <NEmpty v-else description="暂无数据" />

        <NSpace justify="end">
          <NButton @click="emit('update:visible', false)">取消</NButton>
          <NButton type="primary" :disabled="!selectedValue" @click="emit('confirm')">确认</NButton>
        </NSpace>
      </NSpace>
    </NSpin>
  </NModal>
</template>

<style scoped>
.popup-levels-hint {
  padding: 8px 0;
  font-size: 12px;
}
</style>
