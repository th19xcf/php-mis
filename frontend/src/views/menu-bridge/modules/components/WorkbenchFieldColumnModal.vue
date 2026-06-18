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
