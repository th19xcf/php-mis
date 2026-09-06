<script setup lang="ts">
import { ref, computed, h, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { NTag, NButton, NSpace, NPopconfirm, NInput } from 'naive-ui';
import type { DataTableColumns } from 'naive-ui';
import {
  fetchTodoCenter,
  fetchTodoComplete,
  fetchTodoDelete,
  fetchTodoCreate,
  type TodoCenterItem,
  type TodoStats
} from '@/service/api/oa-todo';

defineOptions({ name: 'OaTodoCenter' });

const router = useRouter();

// ============ 数据 ============
const loading = ref(false);
const list = ref<TodoCenterItem[]>([]);
const stats = ref<TodoStats>({ all: 0, pending: 0, doing: 0, done: 0, overdue: 0 });

// 左栏分类
const activeCategory = ref<string>('all');
const categories = [
  { key: 'all', label: '全部', statKey: 'all' as const },
  { key: '待处理', label: '待处理', statKey: 'pending' as const },
  { key: '进行中', label: '进行中', statKey: 'doing' as const },
  { key: 'overdue', label: '已逾期', statKey: 'overdue' as const },
  { key: '已完成', label: '已完成', statKey: 'done' as const }
];

// 筛选
const sourceFilter = ref('');
const priorityFilter = ref('');
const keyword = ref('');

const sourceOptions = [
  { label: '全部来源', value: '' },
  { label: '手动', value: '手动' },
  { label: '会议', value: '会议' },
  { label: '工作流', value: '工作流' },
  { label: '合同', value: '合同' }
];
const priorityOptions = [
  { label: '全部优先级', value: '' },
  { label: '高', value: '高' },
  { label: '中', value: '中' },
  { label: '低', value: '低' }
];

// ============ 计算属性 ============
const filteredList = computed(() => {
  let result = list.value;

  // 左栏分类过滤
  if (activeCategory.value === 'overdue') {
    const today = new Date().toISOString().slice(0, 10);
    result = result.filter(
      item => item.dueDate && item.dueDate < today && item.status !== '已完成' && item.status !== '已取消'
    );
  } else if (activeCategory.value !== 'all') {
    result = result.filter(item => item.status === activeCategory.value);
  }

  return result;
});

// ============ 工具函数 ============
const sourceTagType = (source: string): 'default' | 'info' | 'success' | 'warning' => {
  const map: Record<string, 'default' | 'info' | 'success' | 'warning'> = {
    手动: 'default',
    会议: 'info',
    工作流: 'warning',
    合同: 'success'
  };
  return map[source] || 'default';
};

const priorityColor = (priority: string): string => {
  if (priority === '高') return '#ef4444';
  if (priority === '中') return '#f59e0b';
  return '#10b981';
};

const isOverdue = (item: TodoCenterItem): boolean => {
  if (!item.dueDate || item.status === '已完成' || item.status === '已取消') return false;
  const today = new Date().toISOString().slice(0, 10);
  return item.dueDate < today;
};

const overdueDays = (item: TodoCenterItem): number => {
  if (!item.dueDate) return 0;
  const today = new Date();
  const due = new Date(item.dueDate);
  const diff = Math.floor((today.getTime() - due.getTime()) / 86400000);
  return diff > 0 ? diff : 0;
};

const rowClassName = (row: TodoCenterItem): string => {
  if (row.status === '已完成') return 'todo-row-done';
  if (isOverdue(row)) return 'todo-row-overdue';
  return '';
};

// ============ 事件 ============
async function loadData() {
  loading.value = true;
  try {
    const params: Record<string, string> = {};
    if (sourceFilter.value) params.sourceType = sourceFilter.value;
    if (priorityFilter.value) params.priority = priorityFilter.value;
    if (keyword.value) params.keyword = keyword.value;
    if (activeCategory.value !== 'all' && activeCategory.value !== 'overdue') params.status = activeCategory.value;

    const res = await fetchTodoCenter(params);
    if (res.data) {
      list.value = res.data.list || [];
      stats.value = res.data.stats;
    }
  } finally {
    loading.value = false;
  }
}

async function handleComplete(item: TodoCenterItem) {
  await fetchTodoComplete({ guid: item.GUID });
  await loadData();
}

async function handleDelete(item: TodoCenterItem) {
  await fetchTodoDelete([item.GUID]);
  await loadData();
}

function handleCategoryChange(key: string) {
  activeCategory.value = key;
  loadData();
}

function handleWorkflowClick(item: TodoCenterItem) {
  if (item.todoType === 'workflow' && item.bizType === 'CONTRACT' && item.bizId) {
    router.push(`/contract-v2?businessId=${item.bizId}`);
  }
}

// ============ 快速创建弹窗 ============
const showCreateModal = ref(false);
const createForm = ref({
  待办标题: '',
  负责人: '',
  待办描述: '',
  截止日期: '',
  优先级: '中',
  来源类型: '手动'
});

async function handleCreate() {
  if (!createForm.value.待办标题 || !createForm.value.负责人) return;
  await fetchTodoCreate({
    待办标题: createForm.value.待办标题,
    负责人: createForm.value.负责人,
    待办描述: createForm.value.待办描述,
    截止日期: createForm.value.截止日期 || undefined,
    优先级: createForm.value.优先级,
    来源类型: createForm.value.来源类型
  });
  showCreateModal.value = false;
  createForm.value = { 待办标题: '', 负责人: '', 待办描述: '', 截止日期: '', 优先级: '中', 来源类型: '手动' };
  await loadData();
}

// ============ 列定义 ============
const columns = computed<DataTableColumns<TodoCenterItem>>(() => [
  {
    title: '标题',
    key: 'title',
    minWidth: 280,
    render(row) {
      const children = [
        h('span', { class: 'todo-title-text' }, row.title)
      ];
      if (row.sourceType) {
        children.unshift(
          h(NTag, { size: 'small', type: sourceTagType(row.sourceType), class: 'todo-source-tag' }, { default: () => row.sourceType })
        );
      }
      return h('div', { class: 'todo-title-cell' }, children);
    }
  },
  {
    title: '负责人',
    key: 'assignee',
    width: 90
  },
  {
    title: '优先级',
    key: 'priority',
    width: 70,
    render(row) {
      return h('span', { style: { color: priorityColor(row.priority), fontWeight: row.priority === '高' ? 'bold' : 'normal' } }, row.priority);
    }
  },
  {
    title: '截止日期',
    key: 'dueDate',
    width: 130,
    render(row) {
      if (!row.dueDate) return '-';
      if (isOverdue(row)) {
        return h('span', { class: 'todo-overdue-date' }, `${row.dueDate} 逾期${overdueDays(row)}天`);
      }
      return row.dueDate;
    }
  },
  {
    title: '状态',
    key: 'status',
    width: 90,
    render(row) {
      const typeMap: Record<string, 'default' | 'info' | 'success' | 'warning'> = {
        待处理: 'warning',
        进行中: 'info',
        已完成: 'success',
        已取消: 'default'
      };
      return h(NTag, { size: 'small', type: typeMap[row.status] || 'default', bordered: false }, { default: () => row.status });
    }
  },
  {
    title: '来源',
    key: 'sourceTitle',
    width: 140,
    render(row) {
      if (row.todoType === 'workflow' && row.bizId) {
        return h(
          NButton,
          { text: true, type: 'primary', onClick: () => handleWorkflowClick(row) },
          { default: () => row.sourceTitle || row.sourceType }
        );
      }
      return row.sourceTitle || '-';
    }
  },
  {
    title: '操作',
    key: 'actions',
    width: 130,
    fixed: 'right',
    render(row) {
      const buttons: any[] = [];
      if (row.todoType === 'task' && row.status !== '已完成' && row.status !== '已取消') {
        buttons.push(
          h(NButton, { size: 'small', type: 'primary', text: true, onClick: () => handleComplete(row) }, { default: () => '完成' })
        );
      }
      if (row.todoType === 'workflow') {
        buttons.push(
          h(NButton, { size: 'small', type: 'info', text: true, onClick: () => handleWorkflowClick(row) }, { default: () => '审批' })
        );
      }
      if (row.todoType === 'task') {
        buttons.push(
          h(
            NPopconfirm,
            { onPositiveClick: () => handleDelete(row) },
            {
              trigger: () => h(NButton, { size: 'small', type: 'error', text: true }, { default: () => '删除' }),
              default: () => '确认删除此待办？'
            }
          )
        );
      }
      return h(NSpace, { size: 'small' }, { default: () => buttons });
    }
  }
]);

onMounted(loadData);
</script>

<template>
  <div class="todo-center-page">
    <!-- 顶部标题栏 -->
    <div class="todo-header">
      <h2 class="todo-title">待办中心</h2>
      <NButton type="primary" @click="showCreateModal = true">
        + 新建待办
      </NButton>
    </div>

    <!-- 统计卡片 -->
    <div class="todo-stats">
      <div
        v-for="cat in categories"
        :key="cat.key"
        class="stat-card"
        :class="{ active: activeCategory === cat.key }"
        @click="handleCategoryChange(cat.key)"
      >
        <div class="stat-value" :class="{ overdue: cat.key === 'overdue' }">{{ stats[cat.statKey] }}</div>
        <div class="stat-label">{{ cat.label }}</div>
      </div>
    </div>

    <!-- 筛选条 -->
    <div class="todo-filters">
      <NSelect
        v-model:value="sourceFilter"
        :options="sourceOptions"
        size="small"
        style="width: 130px"
        @update:value="loadData"
      />
      <NSelect
        v-model:value="priorityFilter"
        :options="priorityOptions"
        size="small"
        style="width: 130px"
        @update:value="loadData"
      />
      <NInput
        v-model:value="keyword"
        size="small"
        placeholder="搜索标题/描述"
        style="width: 200px"
        clearable
        @update:value="loadData"
      />
    </div>

    <!-- 数据表格 -->
    <NDataTable
      :columns="columns"
      :data="filteredList"
      :loading="loading"
      :row-key="(row: TodoCenterItem) => `${row.todoType}-${row.GUID}`"
      :row-class-name="rowClassName"
      :scroll-x="900"
      size="small"
      :bordered="false"
    />

    <!-- 新建待办弹窗 -->
    <NModal v-model:show="showCreateModal" preset="card" title="新建待办" style="width: 500px">
      <NForm label-placement="left" :label-width="80">
        <NFormItem label="标题" required>
          <NInput v-model:value="createForm.待办标题" placeholder="待办标题" />
        </NFormItem>
        <NFormItem label="负责人" required>
          <NInput v-model:value="createForm.负责人" placeholder="负责人工号" />
        </NFormItem>
        <NFormItem label="描述">
          <NInput v-model:value="createForm.待办描述" type="textarea" :rows="2" placeholder="待办描述" />
        </NFormItem>
        <NFormItem label="截止日期">
          <NDatePicker v-model:formatted-value="createForm.截止日期" type="date" value-format="yyyy-MM-dd" style="width: 100%" />
        </NFormItem>
        <NFormItem label="优先级">
          <NSelect v-model:value="createForm.优先级" :options="priorityOptions.filter(o => o.value)" />
        </NFormItem>
        <NFormItem label="来源">
          <NSelect
            v-model:value="createForm.来源类型"
            :options="sourceOptions.filter(o => o.value).map(o => ({ label: o.label, value: o.value }))"
          />
        </NFormItem>
      </NForm>
      <template #footer>
        <NSpace justify="end">
          <NButton @click="showCreateModal = false">取消</NButton>
          <NButton type="primary" :disabled="!createForm.待办标题 || !createForm.负责人" @click="handleCreate">
            创建
          </NButton>
        </NSpace>
      </template>
    </NModal>
  </div>
</template>

<style scoped>
.todo-center-page {
  padding: 16px;
}

.todo-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}

.todo-title {
  margin: 0;
  font-size: 20px;
  font-weight: bold;
}

/* 统计卡片 */
.todo-stats {
  display: flex;
  gap: 12px;
  margin-bottom: 16px;
}

.stat-card {
  flex: 1;
  padding: 16px;
  border-radius: 8px;
  background: var(--card-color, #f9fafb);
  border: 1px solid var(--divider-color, #e5e7eb);
  cursor: pointer;
  transition: all 0.2s;
  text-align: center;
}

.stat-card:hover {
  border-color: #1677ff;
}

.stat-card.active {
  border-color: #1677ff;
  background: #e6f4ff;
}

.stat-value {
  font-size: 28px;
  font-weight: bold;
  color: #1f2937;
}

.stat-value.overdue {
  color: #ef4444;
}

.stat-label {
  font-size: 13px;
  color: #6b7280;
  margin-top: 4px;
}

/* 筛选条 */
.todo-filters {
  display: flex;
  gap: 12px;
  margin-bottom: 16px;
}

/* 行样式 */
:deep(.todo-row-overdue) {
  background: #fef2f2;
}

:deep(.todo-row-overdue:hover) {
  background: #fee2e2;
}

:deep(.todo-row-done) {
  background: #f9fafb;
  opacity: 0.6;
}

:deep(.todo-row-done .todo-title-text) {
  text-decoration: line-through;
  color: #9ca3af;
}

/* 来源标签 */
:deep(.todo-source-tag) {
  margin-right: 6px;
}

:deep(.todo-title-cell) {
  display: flex;
  align-items: center;
  gap: 4px;
}

:deep(.todo-overdue-date) {
  color: #ef4444;
  font-weight: bold;
}
</style>
