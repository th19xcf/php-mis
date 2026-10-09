<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useMessage } from 'naive-ui';
import { fetchDashboardTodo, type TodoWidgetData } from '@/service/api/dashboard';
import type { TodoCenterItem } from '@/service/api/oa-todo';

defineOptions({ name: 'TodoWidget' });

const router = useRouter();
const message = useMessage();

const loading = ref(false);
const data = ref<TodoWidgetData>({ list: [], stats: { all: 0, pending: 0, doing: 0, done: 0, overdue: 0 } });

async function loadData() {
  loading.value = true;
  const { data: res, error } = await fetchDashboardTodo();
  loading.value = false;
  if (error) {
    message.error(error.message || '加载待办失败');
    return;
  }
  if (res) data.value = res;
}

function handleClick(item: TodoCenterItem) {
  if (item.todoType === 'workflow' && item.bizType === 'contract' && item.bizId) {
    router.push({ path: '/menu-bridge', query: { functionCode: 'contract', frontendRoute: 'contract', businessId: item.bizId } });
  } else {
    router.push({ path: '/menu-bridge', query: { functionCode: 'oa-todo-center', frontendRoute: 'oa-todo-center' } });
  }
}

function goAll() {
  router.push({ path: '/menu-bridge', query: { functionCode: 'oa-todo-center', frontendRoute: 'oa-todo-center' } });
}

function formatPriority(priority: string): { label: string; type: 'error' | 'warning' | 'info' } {
  switch (priority) {
    case '高': return { label: '紧急', type: 'error' };
    case '中': return { label: '一般', type: 'warning' };
    default: return { label: priority || '普通', type: 'info' };
  }
}

function formatTime(time: string | null): string {
  if (!time) return '';
  return time.slice(5, 16);
}

onMounted(loadData);
</script>

<template>
  <NCard title="我的待办" :bordered="false" size="small" segmented class="card-wrapper todo-widget">
    <template #header-extra>
      <NSpace :size="12" align="center">
        <NBadge :value="data.stats.pending" :max="99" show-zero type="warning">
          <NTag size="small" :bordered="false" type="warning">待处理</NTag>
        </NBadge>
        <NBadge :value="data.stats.overdue" :max="99" show-zero type="error">
          <NTag size="small" :bordered="false" type="error">逾期</NTag>
        </NBadge>
        <a class="view-all" @click="goAll">查看全部</a>
      </NSpace>
    </template>

    <NSpin :show="loading">
      <div v-if="data.list.length === 0 && !loading" class="empty-state">
        <NEmpty description="暂无待办" />
      </div>
      <div v-else class="todo-list">
        <div
          v-for="item in data.list"
          :key="item.GUID"
          class="todo-item"
          @click="handleClick(item)"
        >
          <div class="todo-item-main">
            <div class="todo-item-header">
              <NTag
                size="tiny"
                :type="item.todoType === 'workflow' ? 'info' : 'default'"
                :bordered="false"
              >
                {{ item.todoType === 'workflow' ? '审批' : '任务' }}
              </NTag>
              <span class="todo-title">{{ item.title }}</span>
            </div>
            <div class="todo-item-meta">
              <span v-if="item.assigner" class="meta-text">来自: {{ item.assigner }}</span>
              <span v-if="item.dueDate" class="meta-text">{{ formatTime(item.dueDate) }}</span>
              <NTag
                v-if="item.priority"
                size="tiny"
                :type="formatPriority(item.priority).type"
                :bordered="false"
                round
              >
                {{ formatPriority(item.priority).label }}
              </NTag>
            </div>
          </div>
          <div class="todo-item-arrow">
            <SvgIcon icon="mdi:chevron-right" class="text-20px" />
          </div>
        </div>
      </div>
    </NSpin>
  </NCard>
</template>

<style scoped>
.todo-widget :deep(.n-card-header__main) {
  font-weight: 600;
  font-size: 16px;
}

.view-all {
  color: var(--primary-color, #1890ff);
  cursor: pointer;
  font-size: 13px;
  white-space: nowrap;
}

.view-all:hover {
  opacity: 0.8;
}

.empty-state {
  padding: 32px 0;
  display: flex;
  justify-content: center;
}

.todo-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.todo-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 12px;
  border-radius: 8px;
  background: rgba(0, 0, 0, 0.02);
  cursor: pointer;
  transition: background 0.2s;
}

.todo-item:hover {
  background: rgba(24, 144, 255, 0.06);
}

.todo-item-main {
  flex: 1;
  min-width: 0;
}

.todo-item-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 4px;
}

.todo-title {
  font-size: 14px;
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.todo-item-meta {
  display: flex;
  align-items: center;
  gap: 12px;
}

.meta-text {
  font-size: 12px;
  color: #999;
}

.todo-item-arrow {
  flex-shrink: 0;
  color: #ccc;
}

html.dark .todo-item {
  background: rgba(255, 255, 255, 0.03);
}

html.dark .todo-item:hover {
  background: rgba(255, 255, 255, 0.06);
}

html.dark .meta-text {
  color: rgba(255, 255, 255, 0.45);
}

html.dark .todo-item-arrow {
  color: rgba(255, 255, 255, 0.25);
}
</style>
