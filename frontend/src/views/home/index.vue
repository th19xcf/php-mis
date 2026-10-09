<script setup lang="ts">
import { ref, computed, onMounted, type Component } from 'vue';
import { useAppStore } from '@/store/modules/app';
import { useAuthStore } from '@/store/modules/auth';
import { fetchDashboardWidgets, saveDashboardWidgets, type WidgetConfig } from '@/service/api/dashboard';
import TodoWidget from './modules/todo-widget.vue';

const appStore = useAppStore();
const authStore = useAuthStore();

const gap = computed(() => (appStore.isMobile ? 0 : 16));

const widgets = ref<WidgetConfig[]>([]);
const showConfig = ref(false);
const saving = ref(false);
const greeting = computed(() => {
  const h = new Date().getHours();
  if (h < 6) return '凌晨好';
  if (h < 9) return '早上好';
  if (h < 12) return '上午好';
  if (h < 14) return '中午好';
  if (h < 18) return '下午好';
  return '晚上好';
});

const componentMap: Record<string, Component> = {
  todo: TodoWidget
};

const visibleWidgets = computed(() => widgets.value.filter(w => w['显示标识'] === '1').sort((a, b) => a['显示顺序'] - b['显示顺序']));

const defaultWidgets: WidgetConfig[] = [
  { 卡片编码: 'todo', 卡片名称: '我的待办', 显示顺序: 1, 显示标识: '1' },
  { 卡片编码: 'quick_entry', 卡片名称: '快捷入口', 显示顺序: 2, 显示标识: '0' },
  { 卡片编码: 'contract_stats', 卡片名称: '合同统计', 显示顺序: 3, 显示标识: '0' },
  { 卡片编码: 'recruitment_funnel', 卡片名称: '招聘漏斗', 显示顺序: 4, 显示标识: '0' }
];

async function loadWidgets() {
  const { data, error } = await fetchDashboardWidgets();
  if (error || !data) {
    console.warn('[Home] loadWidgets failed, using defaults', error);
    widgets.value = defaultWidgets;
    return;
  }
  widgets.value = data;
}

async function handleSaveConfig() {
  saving.value = true;
  const payload = widgets.value.map(w => ({
    卡片编码: w['卡片编码'],
    显示标识: w['显示标识'],
    显示顺序: w['显示顺序']
  }));
  const { error } = await saveDashboardWidgets(payload);
  saving.value = false;
  if (error) {
    return;
  }
  showConfig.value = false;
  await loadWidgets();
}

function toggleWidget(code: string) {
  const w = widgets.value.find(item => item['卡片编码'] === code);
  if (w) {
    w['显示标识'] = w['显示标识'] === '1' ? '0' : '1';
  }
}

onMounted(loadWidgets);
</script>

<template>
  <NSpace vertical :size="16">
    <NCard :bordered="false" class="card-wrapper home-header">
      <div class="header-content">
        <div class="header-left">
          <h3 class="greeting-text">
            {{ greeting }}，{{ authStore.userInfo.userName }}
          </h3>
          <p class="sub-text">{{ visibleWidgets.length }} 个卡片已启用</p>
        </div>
        <div class="header-right">
          <NButton quaternary size="small" @click="showConfig = true">
            <template #icon>
              <SvgIcon icon="mdi:apps" />
            </template>
            自定义卡片
          </NButton>
        </div>
      </div>
    </NCard>

    <template v-for="w in visibleWidgets" :key="w['卡片编码']">
      <component
        :is="componentMap[w['卡片编码']]"
        v-if="componentMap[w['卡片编码']]"
      />
    </template>

    <NCard v-if="visibleWidgets.length === 0" :bordered="false" class="card-wrapper">
      <NEmpty description="暂无启用的卡片，点击右上角「自定义卡片」添加" />
    </NCard>

    <NDrawer v-model:show="showConfig" :width="380" placement="right">
      <NDrawerContent title="自定义首页卡片" closable>
        <div class="config-list">
          <div v-for="w in widgets" :key="w['卡片编码']" class="config-item">
            <div class="config-item-info">
              <span class="config-item-name">{{ w['卡片名称'] }}</span>
              <NTag v-if="w['卡片编码'] === 'todo'" size="tiny" type="info" :bordered="false">已上线</NTag>
              <NTag v-else size="tiny" type="default" :bordered="false">敬请期待</NTag>
            </div>
            <NSwitch
              :value="w['显示标识'] === '1'"
              :disabled="w['卡片编码'] !== 'todo'"
              @update:value="toggleWidget(w['卡片编码'])"
            />
          </div>
        </div>
        <div class="config-tip">
          目前已上线「我的待办」卡片，更多卡片持续开发中...
        </div>
        <template #footer>
          <NSpace justify="end">
            <NButton @click="showConfig = false">取消</NButton>
            <NButton type="primary" :loading="saving" @click="handleSaveConfig">保存</NButton>
          </NSpace>
        </template>
      </NDrawerContent>
    </NDrawer>
  </NSpace>
</template>

<style scoped>
.home-header :deep(.n-card__content) {
  padding: 16px 20px;
}

.header-content {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.header-left {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.greeting-text {
  font-size: 18px;
  font-weight: 600;
  margin: 0;
}

.sub-text {
  font-size: 13px;
  color: #999;
  margin: 0;
}

.header-right {
  flex-shrink: 0;
}

.config-list {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.config-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  border-radius: 8px;
  background: rgba(0, 0, 0, 0.02);
}

.config-item-info {
  display: flex;
  align-items: center;
  gap: 8px;
}

.config-item-name {
  font-size: 14px;
  font-weight: 500;
}

.config-tip {
  margin-top: 16px;
  padding: 12px 16px;
  font-size: 13px;
  color: #999;
  border-radius: 8px;
  background: rgba(0, 0, 0, 0.02);
}

html.dark .config-item,
html.dark .config-tip {
  background: rgba(255, 255, 255, 0.03);
}

html.dark .sub-text,
html.dark .config-tip {
  color: rgba(255, 255, 255, 0.45);
}
</style>
