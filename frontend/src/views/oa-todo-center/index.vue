<script setup lang="ts">
import { ref, computed, h, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { NTag, NButton, NSpace, NPopconfirm, NInput, NSelect, NDataTable, NModal, NForm, NFormItem, NDatePicker, NDescriptions, NDescriptionsItem } from 'naive-ui';
import type { DataTableColumns } from 'naive-ui';
import {
  fetchTodoCenter,
  fetchTodoComplete,
  fetchTodoDelete,
  fetchTodoCreate,
  fetchTodoUpdate,
  fetchTodoReassign,
  fetchTodoDetail,
  fetchTodoOptions,
  fetchTodoUserOptions,
  type TodoCenterItem,
  type TodoStats,
  type TodoUserOption
} from '@/service/api/oa-todo';
import { useMessageWithConsole } from '@/hooks/business/use-message-with-console';
import UserPicker from '@/components/custom/user-picker.vue';

defineOptions({ name: 'OaTodoCenter' });

const router = useRouter();
const message = useMessageWithConsole();

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

// 下拉选项（从接口获取）
const sourceOptions = ref([{ label: '全部来源', value: '' }]);
const priorityOptions = ref([{ label: '全部优先级', value: '' }]);
const statusOptions = ref<string[]>(['待处理', '进行中', '已完成', '已取消']);

// 批量选择
const checkedRowKeys = ref<(string | number)[]>([]);

// 人员选择器（UserPicker）
const showUserPicker = ref(false);
const userPickerTitle = ref('选择负责人');
const userPickerMultiple = ref(false);
const userPickerValue = ref<string[]>([]);
// 当前正在选择负责人的目标：'create' | 'reassign'
const userPickerTarget = ref<'create' | 'reassign'>('create');

// 已选人员信息（用于显示姓名）
const selectedUserMap = ref<Map<string, TodoUserOption>>(new Map());

function openUserPicker(target: 'create' | 'reassign') {
  userPickerTarget.value = target;
  userPickerMultiple.value = true;
  if (target === 'create') {
    userPickerTitle.value = '选择负责人';
    userPickerValue.value = createForm.value.负责人 ? createForm.value.负责人.split(',') : [];
  } else {
    userPickerTitle.value = '选择新负责人';
    userPickerValue.value = reassignForm.value.新负责人 ? reassignForm.value.新负责人.split(',') : [];
  }
  showUserPicker.value = true;
}

function handleUserPickerConfirm(users: TodoUserOption[]) {
  // 缓存用户信息（用于显示姓名）
  users.forEach(u => selectedUserMap.value.set(u.工号, u));
  selectedUserMap.value = new Map(selectedUserMap.value);

  const ids = users.map(u => u.工号).join(',');
  if (userPickerTarget.value === 'create') {
    createForm.value.负责人 = ids;
  } else {
    reassignForm.value.新负责人 = ids;
  }
}

// 显示负责人名称（支持逗号分隔的多个工号 -> 姓名）
function getUserName(workIds: string): string {
  if (!workIds) return '';
  return workIds
    .split(',')
    .filter(Boolean)
    .map(id => {
      const user = selectedUserMap.value.get(id);
      return user ? `${user.姓名}（${id}）` : id;
    })
    .join('、');
}

// ============ 计算属性 ============
const filteredList = computed(() => {
  let result = list.value;

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

const rowKey = (row: TodoCenterItem) => `${row.todoType}-${row.GUID}`;

// ============ 数据加载 ============
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
  } catch (e: any) {
    message.error(e?.message || '加载失败');
  } finally {
    loading.value = false;
  }
}

async function loadOptions() {
  try {
    const res = await fetchTodoOptions();
    if (res.data) {
      sourceOptions.value = [{ label: '全部来源', value: '' }, ...(res.data.来源类型 || []).map((v: string) => ({ label: v, value: v }))];
      priorityOptions.value = [{ label: '全部优先级', value: '' }, ...(res.data.优先级 || []).map((v: string) => ({ label: v, value: v }))];
      if (res.data.待办状态?.length) statusOptions.value = res.data.待办状态;
    }
  } catch {
    // 接口失败时使用默认硬编码选项
    sourceOptions.value = [
      { label: '全部来源', value: '' },
      { label: '手动', value: '手动' },
      { label: '会议', value: '会议' },
      { label: '工作流', value: '工作流' },
      { label: '合同', value: '合同' }
    ];
    priorityOptions.value = [
      { label: '全部优先级', value: '' },
      { label: '高', value: '高' },
      { label: '中', value: '中' },
      { label: '低', value: '低' }
    ];
  }
}

// ============ 事件：完成 ============
const showCompleteModal = ref(false);
const completeForm = ref({ guid: '' as string | number, note: '' });

function handleComplete(item: TodoCenterItem) {
  completeForm.value = { guid: item.GUID, note: '' };
  showCompleteModal.value = true;
}

async function handleCompleteSubmit() {
  try {
    await fetchTodoComplete({ guid: completeForm.value.guid, 完成说明: completeForm.value.note });
    message.success('已完成');
    showCompleteModal.value = false;
    await loadData();
  } catch (e: any) {
    message.error(e?.message || '操作失败');
  }
}

// ============ 事件：删除 ============
async function handleDelete(item: TodoCenterItem) {
  try {
    await fetchTodoDelete([item.GUID]);
    message.success('删除成功');
    await loadData();
  } catch (e: any) {
    message.error(e?.message || '删除失败');
  }
}

async function handleBatchDelete() {
  if (checkedRowKeys.value.length === 0) {
    message.warning('请先选择要删除的待办');
    return;
  }
  const guids = checkedRowKeys.value
    .map(k => {
      const item = list.value.find(r => rowKey(r) === k);
      return item?.todoType === 'task' ? item.GUID : null;
    })
    .filter((g): g is number => g !== null);

  if (guids.length === 0) {
    message.warning('所选待办中无可删除的任务待办');
    return;
  }
  try {
    await fetchTodoDelete(guids);
    message.success(`已删除 ${guids.length} 条`);
    checkedRowKeys.value = [];
    await loadData();
  } catch (e: any) {
    message.error(e?.message || '删除失败');
  }
}

// ============ 事件：分类切换 ============
function handleCategoryChange(key: string) {
  activeCategory.value = key;
  loadData();
}

// ============ 事件：审批跳转 ============
function handleWorkflowClick(item: TodoCenterItem) {
  if (item.todoType === 'workflow' && item.bizType === 'CONTRACT' && item.bizId) {
    router.push(`/contract-v2?businessId=${item.bizId}`);
  } else {
    message.info('该审批类型暂不支持跳转');
  }
}

// ============ 事件：新建 / 编辑 ============
const showCreateModal = ref(false);
const isEditMode = ref(false);
const submitting = ref(false);
const createForm = ref({
  guid: '' as string | number,
  待办标题: '',
  负责人: '',
  待办描述: '',
  截止日期: null as string | null,
  优先级: '中',
  来源类型: '手动'
});

function openCreateModal() {
  isEditMode.value = false;
  createForm.value = {
    guid: '',
    待办标题: '',
    负责人: '',
    待办描述: '',
    截止日期: null,
    优先级: '中',
    来源类型: '手动'
  };
  showCreateModal.value = true;
}

function handleEdit(item: TodoCenterItem) {
  isEditMode.value = true;
  createForm.value = {
    guid: item.GUID,
    待办标题: item.title,
    负责人: item.assignee,
    待办描述: item.description || '',
    截止日期: item.dueDate || null,
    优先级: item.priority,
    来源类型: item.sourceType
  };
  showCreateModal.value = true;
}

async function handleCreateSubmit() {
  if (!createForm.value.待办标题.trim()) {
    message.warning('请输入待办标题');
    return;
  }
  if (!createForm.value.负责人) {
    message.warning('请选择负责人');
    return;
  }

  submitting.value = true;
  try {
    const data = {
      待办标题: createForm.value.待办标题,
      负责人: createForm.value.负责人,
      待办描述: createForm.value.待办描述 || undefined,
      截止日期: createForm.value.截止日期 || undefined,
      优先级: createForm.value.优先级,
      来源类型: createForm.value.来源类型
    };

    if (isEditMode.value) {
      await fetchTodoUpdate({ guid: createForm.value.guid, ...data });
      message.success('修改成功');
    } else {
      await fetchTodoCreate(data);
      message.success('创建成功');
    }
    showCreateModal.value = false;
    await loadData();
  } catch (e: any) {
    message.error(e?.message || '操作失败');
  } finally {
    submitting.value = false;
  }
}

// ============ 事件：转办 ============
const showReassignModal = ref(false);
const reassignForm = ref({ guid: '' as string | number, 新负责人: '' });

function handleReassign(item: TodoCenterItem) {
  reassignForm.value = { guid: item.GUID, 新负责人: '' };
  showReassignModal.value = true;
}

async function handleReassignSubmit() {
  if (!reassignForm.value.新负责人) {
    message.warning('请选择新负责人');
    return;
  }
  try {
    await fetchTodoReassign({ guid: reassignForm.value.guid, 新负责人: reassignForm.value.新负责人 });
    message.success('转办成功');
    showReassignModal.value = false;
    await loadData();
  } catch (e: any) {
    message.error(e?.message || '转办失败');
  }
}

// ============ 事件：详情 ============
const showDetailModal = ref(false);
const detailData = ref<TodoCenterItem | null>(null);

async function handleViewDetail(item: TodoCenterItem) {
  try {
    const res = await fetchTodoDetail(item.GUID);
    if (res.data) {
      detailData.value = res.data as TodoCenterItem;
      showDetailModal.value = true;
    }
  } catch (e: any) {
    message.error(e?.message || '获取详情失败');
  }
}

// ============ 列定义 ============
const columns = computed<DataTableColumns<TodoCenterItem>>(() => [
  {
    type: 'selection',
    width: 40,
    disabled: (row: TodoCenterItem) => row.todoType !== 'task'
  },
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
    width: 130,
    render(row) {
      if (!row.assignee) return '-';
      return getUserName(row.assignee);
    }
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
      return row.sourceTitle || row.sourceType || '-';
    }
  },
  {
    title: '操作',
    key: 'actions',
    width: 240,
    fixed: 'right',
    render(row) {
      const buttons: any[] = [];
      const isTask = row.todoType === 'task';
      const isDone = row.status === '已完成' || row.status === '已取消';

      if (isTask && !isDone) {
        buttons.push(
          h(NButton, { size: 'small', type: 'primary', text: true, onClick: () => handleComplete(row) }, { default: () => '完成' })
        );
      }
      if (row.todoType === 'workflow') {
        buttons.push(
          h(NButton, { size: 'small', type: 'info', text: true, onClick: () => handleWorkflowClick(row) }, { default: () => '审批' })
        );
      }
      if (isTask) {
        buttons.push(
          h(NButton, { size: 'small', type: 'default', text: true, onClick: () => handleEdit(row) }, { default: () => '编辑' })
        );
      }
      if (isTask && !isDone) {
        buttons.push(
          h(NButton, { size: 'small', type: 'warning', text: true, onClick: () => handleReassign(row) }, { default: () => '转办' })
        );
      }
      buttons.push(
        h(NButton, { size: 'small', type: 'info', text: true, onClick: () => handleViewDetail(row) }, { default: () => '详情' })
      );
      if (isTask) {
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

// 预载人员映射（表格/详情负责人显示姓名）
async function loadUserMap() {
  try {
    const res = await fetchTodoUserOptions('', '');
    if (res.data) {
      res.data.forEach(u => selectedUserMap.value.set(u.工号, u));
      selectedUserMap.value = new Map(selectedUserMap.value);
    }
  } catch {
    /* 加载失败时显示工号 */
  }
}

onMounted(() => {
  loadOptions();
  loadUserMap();
  loadData();
});

watch(keyword, (val) => {
  if (val === '') loadData();
});
</script>

<template>
  <div class="todo-center-page">
    <!-- 顶部标题栏 -->
    <div class="todo-header">
      <h2 class="todo-title">待办中心</h2>
      <NSpace>
        <NButton type="default" :disabled="checkedRowKeys.length === 0" @click="handleBatchDelete">
          批量删除
        </NButton>
        <NButton type="primary" @click="openCreateModal">
          + 新建待办
        </NButton>
      </NSpace>
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
      :row-key="rowKey"
      :row-class-name="rowClassName"
      :checked-row-keys="checkedRowKeys"
      @update:checked-row-keys="(keys) => (checkedRowKeys = keys)"
      :scroll-x="1100"
      size="small"
      :bordered="false"
    />

    <!-- 新建 / 编辑待办弹窗 -->
    <NModal v-model:show="showCreateModal" preset="card" :title="isEditMode ? '编辑待办' : '新建待办'" style="width: 500px">
      <NForm label-placement="left" :label-width="80">
        <NFormItem label="标题" required>
          <NInput v-model:value="createForm.待办标题" placeholder="待办标题" />
        </NFormItem>
        <NFormItem label="负责人" required>
          <NInput
            :value="createForm.负责人 ? getUserName(createForm.负责人) : ''"
            placeholder="点击选择负责人"
            readonly
            @click="openUserPicker('create')"
          />
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
          <NButton type="primary" :loading="submitting" :disabled="!createForm.待办标题 || !createForm.负责人" @click="handleCreateSubmit">
            {{ isEditMode ? '保存' : '创建' }}
          </NButton>
        </NSpace>
      </template>
    </NModal>

    <!-- 完成说明弹窗 -->
    <NModal v-model:show="showCompleteModal" preset="card" title="标记完成" style="width: 420px">
      <NForm label-placement="left" :label-width="80">
        <NFormItem label="完成说明">
          <NInput v-model:value="completeForm.note" type="textarea" :rows="3" placeholder="可选，填写完成说明" />
        </NFormItem>
      </NForm>
      <template #footer>
        <NSpace justify="end">
          <NButton @click="showCompleteModal = false">取消</NButton>
          <NButton type="primary" @click="handleCompleteSubmit">确认完成</NButton>
        </NSpace>
      </template>
    </NModal>

    <!-- 转办弹窗 -->
    <NModal v-model:show="showReassignModal" preset="card" title="转办待办" style="width: 420px">
      <NForm label-placement="left" :label-width="80">
        <NFormItem label="新负责人" required>
          <NInput
            :value="reassignForm.新负责人 ? getUserName(reassignForm.新负责人) : ''"
            placeholder="点击选择新负责人"
            readonly
            @click="openUserPicker('reassign')"
          />
        </NFormItem>
      </NForm>
      <template #footer>
        <NSpace justify="end">
          <NButton @click="showReassignModal = false">取消</NButton>
          <NButton type="primary" :disabled="!reassignForm.新负责人" @click="handleReassignSubmit">确认转办</NButton>
        </NSpace>
      </template>
    </NModal>

    <!-- 详情弹窗 -->
    <NModal v-model:show="showDetailModal" preset="card" title="待办详情" style="width: 600px">
      <NDescriptions v-if="detailData" label-placement="left" :column="2" bordered size="small">
        <NDescriptionsItem label="标题" :span="2">{{ detailData.title }}</NDescriptionsItem>
        <NDescriptionsItem label="类型">
          {{ detailData.todoType === 'workflow' ? '审批待办' : '任务待办' }}
        </NDescriptionsItem>
        <NDescriptionsItem label="状态">{{ detailData.status }}</NDescriptionsItem>
        <NDescriptionsItem label="负责人">{{ detailData.assignee ? getUserName(detailData.assignee) : '-' }}</NDescriptionsItem>
        <NDescriptionsItem label="指派人">{{ detailData.assigner ? getUserName(detailData.assigner) : '-' }}</NDescriptionsItem>
        <NDescriptionsItem label="优先级">{{ detailData.priority }}</NDescriptionsItem>
        <NDescriptionsItem label="截止日期">{{ detailData.dueDate || '-' }}</NDescriptionsItem>
        <NDescriptionsItem label="来源类型">{{ detailData.sourceType }}</NDescriptionsItem>
        <NDescriptionsItem label="来源摘要">{{ detailData.sourceTitle || detailData.sourceType || '-' }}</NDescriptionsItem>
        <NDescriptionsItem label="创建时间">{{ detailData.createdAt }}</NDescriptionsItem>
        <NDescriptionsItem label="更新时间">{{ detailData.updatedAt }}</NDescriptionsItem>
        <NDescriptionsItem v-if="detailData.completedAt" label="完成时间" :span="2">{{ detailData.completedAt }}</NDescriptionsItem>
        <NDescriptionsItem v-if="detailData.completedNote" label="完成说明" :span="2">{{ detailData.completedNote }}</NDescriptionsItem>
        <NDescriptionsItem label="描述" :span="2">{{ detailData.description || '-' }}</NDescriptionsItem>
      </NDescriptions>
      <template #footer>
        <NSpace justify="end">
          <NButton type="primary" @click="showDetailModal = false">关闭</NButton>
        </NSpace>
      </template>
    </NModal>

    <!-- 人员选择器 -->
    <UserPicker
      v-model:show="showUserPicker"
      v-model="userPickerValue"
      :multiple="userPickerMultiple"
      :title="userPickerTitle"
      @confirm="handleUserPickerConfirm"
    />
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
  background: rgb(var(--container-bg-color));
  border: 1px solid rgb(var(--base-text-color) / 0.15);
  cursor: pointer;
  transition: all 0.2s;
  text-align: center;
}

.stat-card:hover {
  border-color: rgb(var(--primary-color));
}

.stat-card.active {
  border-color: rgb(var(--primary-color));
  background: rgb(var(--primary-color) / 0.12);
}

.stat-value {
  font-size: 28px;
  font-weight: bold;
  color: rgb(var(--base-text-color));
}

.stat-value.overdue {
  color: rgb(var(--error-color));
}

.stat-label {
  font-size: 13px;
  color: rgb(var(--base-text-color) / 0.55);
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
  background: rgb(var(--error-color) / 0.08);
}

:deep(.todo-row-overdue:hover) {
  background: rgb(var(--error-color) / 0.14);
}

:deep(.todo-row-done) {
  background: rgb(var(--base-text-color) / 0.04);
  opacity: 0.6;
}

:deep(.todo-row-done .todo-title-text) {
  text-decoration: line-through;
  color: rgb(var(--base-text-color) / 0.45);
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
  color: rgb(var(--error-color));
  font-weight: bold;
}
</style>
