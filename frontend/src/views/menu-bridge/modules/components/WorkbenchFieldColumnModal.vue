<script setup lang="ts">
import { computed } from 'vue';
import { NModal, NSpace, NCheckbox, NCheckboxGroup } from 'naive-ui';

interface CheckboxOption {
  label: string;
  value: string | number;
}

interface Props {
  visible: boolean;
  modelValue: string[];
  options: CheckboxOption[];
  isDarkMode?: boolean;
}

const props = defineProps<Props>();

const emit = defineEmits<{
  'update:visible': [boolean];
  'update:modelValue': [string[]];
  change: [string[]];
  clear: [];
  selectAll: [];
}>();

const visibleRef = computed({
  get: () => props.visible,
  set: val => emit('update:visible', val)
});

const isAllSelected = computed(() => props.modelValue.length === props.options.length && props.options.length > 0);

function handleChange(val: (string | number)[]) {
  const normalized = val.map(v => String(v));
  emit('update:modelValue', normalized);
  emit('change', normalized);
}
</script>

<template>
  <NModal
    v-model:show="visibleRef"
    preset="card"
    title="字段选择"
    class="w-420px pin-column-modal"
    :class="{ 'pin-column-modal-dark': isDarkMode }"
    :mask-closable="true"
  >
    <NSpace vertical :size="16">
      <div class="pin-column-select-panel">
        <div class="pin-column-actions">
          <NCheckbox
            :checked="isAllSelected"
            @update:checked="checked => (checked ? emit('selectAll') : emit('clear'))"
          >
            全选
          </NCheckbox>
          <NCheckbox :checked="modelValue.length === 0" @update:checked="checked => checked && emit('clear')">
            全不选
          </NCheckbox>
        </div>

        <div class="pin-column-divider" />

        <NCheckboxGroup :value="modelValue" class="pin-column-group" @update:value="handleChange">
          <NSpace vertical :size="10">
            <NCheckbox v-for="item in options" :key="String(item.value)" :value="String(item.value)">
              {{ item.label }}
            </NCheckbox>
          </NSpace>
        </NCheckboxGroup>
      </div>
    </NSpace>
  </NModal>
</template>

<style lang="scss">
$wb-dark-bg: #1b2a38;
$wb-dark-surface: #1f3042;
$wb-dark-border-light: #3d4f60;

// 弹窗通过 teleport 渲染到 body 外部，无法继承父组件 scoped 样式，
// 因此样式放在此处，使用 html.dark 作为暗色选择器以匹配全局暗色状态。
.pin-column-divider {
  height: 1px;
  background: #d4dce5;
  margin: 4px 0 10px;

  html.dark & {
    background: $wb-dark-border-light;
  }
}

.pin-column-group {
  background: #f5f7fa;

  .n-checkbox .n-checkbox__label {
    color: #334155;
  }

  html.dark & {
    background: $wb-dark-surface;
  }
}

.pin-column-select-panel {
  max-height: 320px;
  overflow: auto;
  border: 1px solid #d4dce5;
  border-radius: 8px;
  padding: 12px;
  background: #f5f7fa;

  // 亮色模式滚动条：使用浅色 thumb，避免深色条出现在浅色背景上
  &::-webkit-scrollbar {
    width: 8px;
  }

  &::-webkit-scrollbar-track {
    background: #f5f7fa;
  }

  &::-webkit-scrollbar-thumb {
    background-color: #c8d2dc;
    border-radius: 4px;
  }

  &::-webkit-scrollbar-thumb:hover {
    background-color: #a8b6c4;
  }

  html.dark & {
    border-color: $wb-dark-border-light;
    background: $wb-dark-surface;

    &::-webkit-scrollbar-track {
      background: $wb-dark-surface;
    }

    &::-webkit-scrollbar-thumb {
      background-color: #5e6f80;
    }

    &::-webkit-scrollbar-thumb:hover {
      background-color: #7d8d9e;
    }
  }
}

html.dark .pin-column-actions .n-checkbox .n-checkbox__label,
html.dark .pin-column-group .n-checkbox .n-checkbox__label {
  color: rgb(var(--base-text-color));
}

html.dark .pin-column-actions .n-checkbox .n-checkbox-box,
html.dark .pin-column-group .n-checkbox .n-checkbox-box {
  border-color: #7f95ac;
  background-color: $wb-dark-surface;
}

html.dark .pin-column-actions .n-checkbox.n-checkbox--checked .n-checkbox-box,
html.dark .pin-column-group .n-checkbox.n-checkbox--checked .n-checkbox-box {
  border-color: #4ea4f3;
  background-color: #2f7fc5;
}

html.dark .n-checkbox:not(.n-checkbox--disabled) .n-checkbox-box {
  border-color: #7f95ac !important;
  background-color: $wb-dark-surface !important;
}

html.dark .n-checkbox.n-checkbox--checked:not(.n-checkbox--disabled) .n-checkbox-box {
  border-color: #4ea4f3 !important;
  background-color: #2f7fc5 !important;
}
</style>
