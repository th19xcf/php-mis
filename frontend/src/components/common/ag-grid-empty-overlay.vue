<script setup lang="ts">
import { computed } from 'vue';
import { $t } from '@/locales';
import type { EmptyStatus } from './empty-state.vue';

defineOptions({ name: 'AgGridEmptyOverlay' });

interface Props {
  status?: EmptyStatus;
  showRetry?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
  status: 'empty',
  showRetry: false
});

const emit = defineEmits<{
  (e: 'retry'): void;
}>();

const icon = computed(() => {
  if (props.status === 'noPermission') return '🔒';
  if (props.status === 'loadFailed') return '⚠';
  return '📭';
});

const title = computed(() => {
  if (props.status === 'noPermission') return $t('common.empty.noPermission');
  if (props.status === 'loadFailed') return $t('common.empty.loadFailed');
  return $t('common.empty.title');
});

const description = computed(() => {
  if (props.status === 'noPermission') return $t('common.empty.noPermissionDesc');
  if (props.status === 'loadFailed') return $t('common.empty.loadFailedDesc');
  return $t('common.empty.description');
});
</script>

<template>
  <div class="ag-empty-overlay">
    <div class="ag-empty-overlay-icon">{{ icon }}</div>
    <div class="ag-empty-overlay-title">{{ title }}</div>
    <div class="ag-empty-overlay-desc">{{ description }}</div>
    <NButton v-if="showRetry || status === 'loadFailed'" type="primary" size="small" @click="emit('retry')">
      {{ $t('common.empty.retry') }}
    </NButton>
  </div>
</template>

<style scoped>
.ag-empty-overlay {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 24px;
}
.ag-empty-overlay-icon {
  font-size: 40px;
  line-height: 1;
  opacity: 0.7;
}
.ag-empty-overlay-title {
  font-size: 14px;
  color: var(--text-color-2);
}
.ag-empty-overlay-desc {
  font-size: 12px;
  color: var(--text-color-3);
  margin-bottom: 8px;
}
</style>
