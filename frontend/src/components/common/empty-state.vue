<script setup lang="ts">
import { computed } from 'vue';
import { $t } from '@/locales';

defineOptions({ name: 'EmptyState' });

export type EmptyStatus = 'empty' | 'noPermission' | 'loadFailed';

interface Props {
  /**
   * 状态类型
   * - empty: 暂无数据
   * - noPermission: 暂无权限
   * - loadFailed: 加载失败
   */
  status?: EmptyStatus;
  /** 自定义标题，覆盖默认 */
  title?: string;
  /** 自定义描述，覆盖默认 */
  description?: string;
  /** 尺寸 */
  size?: 'small' | 'medium' | 'large' | 'huge';
  /** 是否显示重试按钮（仅 loadFailed 状态默认显示） */
  showRetry?: boolean;
  /** 重试按钮文案 */
  retryText?: string;
  /** 图标 */
  icon?: string;
}

const props = withDefaults(defineProps<Props>(), {
  status: 'empty',
  title: undefined,
  description: undefined,
  size: 'medium',
  showRetry: undefined,
  retryText: undefined,
  icon: undefined
});

const emit = defineEmits<{
  (e: 'retry'): void;
}>();

const finalTitle = computed(() => {
  if (props.title) return props.title;
  if (props.status === 'noPermission') return $t('common.empty.noPermission');
  if (props.status === 'loadFailed') return $t('common.empty.loadFailed');
  return $t('common.empty.title');
});

const finalDescription = computed(() => {
  if (props.description !== undefined) return props.description;
  if (props.status === 'noPermission') return $t('common.empty.noPermissionDesc');
  if (props.status === 'loadFailed') return $t('common.empty.loadFailedDesc');
  return $t('common.empty.description');
});

const finalShowRetry = computed(() => {
  if (props.showRetry !== undefined) return props.showRetry;
  return props.status === 'loadFailed';
});

const finalRetryText = computed(() => props.retryText ?? $t('common.empty.retry'));

const finalIcon = computed(() => {
  if (props.icon) return props.icon;
  if (props.status === 'noPermission') return '🔒';
  if (props.status === 'loadFailed') return '⚠';
  return '📭';
});
</script>

<template>
  <div class="empty-state">
    <NEmpty :size="size" :description="finalDescription">
      <template #icon>
        <span class="empty-state-icon">{{ finalIcon }}</span>
      </template>
      <template #default>
        <div class="empty-state-title">{{ finalTitle }}</div>
        <div v-if="finalShowRetry" class="empty-state-action">
          <NButton type="primary" size="small" @click="emit('retry')">
            {{ finalRetryText }}
          </NButton>
        </div>
        <slot />
      </template>
    </NEmpty>
  </div>
</template>

<style scoped>
.empty-state {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  padding: 24px 16px;
}
.empty-state-icon {
  font-size: 48px;
  line-height: 1;
  opacity: 0.7;
}
.empty-state-title {
  font-size: 14px;
  color: var(--text-color-2);
  margin-bottom: 12px;
}
.empty-state-action {
  margin-top: 8px;
}
</style>
