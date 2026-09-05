<script setup lang="ts">
import { computed } from 'vue';
import { $t } from '@/locales';
import type { EmptyStatus } from './empty-state.vue';

defineOptions({ name: 'AgGridEmptyOverlay' });

/**
 * AG Grid 注册为 noRowsOverlayComponent 时，wrapper 会把
 * noRowsOverlayComponentParams 冻结后作为 params 属性注入；
 * 直接作为普通组件使用时，可不经 params 直接传 status / showRetry
 */
interface Props {
  params?: {
    status?: EmptyStatus;
    showRetry?: boolean;
  };
  status?: EmptyStatus;
  showRetry?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
  params: undefined,
  status: 'empty',
  showRetry: false
});

const emit = defineEmits<{
  (e: 'retry'): void;
}>();

const finalStatus = computed<EmptyStatus>(() => props.params?.status ?? props.status ?? 'empty');
const finalShowRetry = computed(() => props.params?.showRetry ?? props.showRetry ?? false);

const icon = computed(() => {
  if (finalStatus.value === 'noPermission') return '🔒';
  if (finalStatus.value === 'loadFailed') return '⚠';
  return '📭';
});

const title = computed(() => {
  if (finalStatus.value === 'noPermission') return $t('common.empty.noPermission');
  if (finalStatus.value === 'loadFailed') return $t('common.empty.loadFailed');
  return $t('common.empty.title');
});

const description = computed(() => {
  if (finalStatus.value === 'noPermission') return $t('common.empty.noPermissionDesc');
  if (finalStatus.value === 'loadFailed') return $t('common.empty.loadFailedDesc');
  return $t('common.empty.description');
});
</script>

<template>
  <div class="ag-empty-overlay">
    <div class="ag-empty-overlay-icon">{{ icon }}</div>
    <div class="ag-empty-overlay-title">{{ title }}</div>
    <div class="ag-empty-overlay-desc">{{ description }}</div>
    <NButton v-if="finalShowRetry || finalStatus === 'loadFailed'" type="primary" size="small" @click="emit('retry')">
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
