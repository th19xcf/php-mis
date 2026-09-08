<script setup lang="ts">
import { ref, computed, onMounted, onActivated } from 'vue';
import { useRouter } from 'vue-router';
import { AgGridVue } from 'ag-grid-vue3';
import { AG_GRID_LOCALE_CN } from '@ag-grid-community/locale';
import type { GridApi } from 'ag-grid-community';
import { NTag, NButton, NSpace, NInput, NSelect, NModal, NForm, NFormItem, NDatePicker, NDescriptions, NDescriptionsItem, NTimeline, NTimelineItem, NSpin, NDivider, NDropdown, NTabs, NTabPane, NEmpty } from 'naive-ui';
import type { DropdownOption } from 'naive-ui';
import { useDialog } from 'naive-ui';
import {
  fetchTodoCenter,
  fetchTodoComplete,
  fetchTodoDelete,
  fetchTodoCreate,
  fetchTodoUpdate,
  fetchTodoReassign,
  fetchTodoDetail,
  fetchTodoLogs,
  fetchTodoStart,
  fetchTodoCancel,
  fetchTodoReopen,
  fetchTodoUrge,
  fetchTodoTogglePin,
  fetchTodoSubtasks,
  fetchTodoComments,
  fetchTodoAddComment,
  fetchTodoUpload,
  fetchTodoOptions,
  fetchTodoUserOptions,
  type TodoCenterItem,
  type TodoStats,
  type TodoUserOption,
  type TodoLogItem
} from '@/service/api/oa-todo';
import { useMessageWithConsole } from '@/hooks/business/use-message-with-console';
import { useSplitter } from '@/hooks/business';
import {
  createGridThemes,
  createDefaultColDef,
  createNumericColumnType,
  createSequenceColumn
} from '@/hooks/business/use-config-driven-grid';
import UserPicker from '@/components/custom/user-picker.vue';

defineOptions({ name: 'OaTodoCenter' });

const router = useRouter();
const message = useMessageWithConsole();
const dialog = useDialog();

// 左右分栏（与合同管理 v2 一致）
const { leftWidth, isResizing, startResize } = useSplitter({
  defaultWidth: 760,
  minWidth: 480,
  maxWidth: 1200,
  storageKey: 'todo-center-splitter-width'
});

// AG-Grid 主题与默认配置（与合同管理 v2 的合同列表一致）
const { isDarkMode, gridTheme } = createGridThemes();
const defaultColDef = createDefaultColDef();
const columnTypes = createNumericColumnType();

const gridApi = ref<GridApi | null>(null);
function onGridReady(params: { api: GridApi }) {
  gridApi.value = params.api;
}

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
const keyword = ref('');

// 下拉选项（从接口获取，供新建/编辑表单使用）
const sourceOptions = ref([{ label: '手动', value: '手动' }]);
const priorityOptions = ref([{ label: '高', value: '高' }, { label: '中', value: '中' }, { label: '低', value: '低' }]);
const statusOptions = ref<string[]>(['待处理', '进行中', '已完成', '已取消']);

// 批量选择（AG-Grid 复选框多选，仅任务待办可选）
const selectedTaskCount = ref(0);
const rowSelection = {
  mode: 'multiRow',
  checkboxes: true,
  headerCheckbox: true,
  enableClickSelection: false,
  isRowSelectable: (node: any) => node.data?.todoType === 'task'
} as any;

function onSelectionChanged() {
  selectedTaskCount.value = (gridApi.value?.getSelectedRows() || []).filter(r => r.todoType === 'task').length;
}

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
/** HTML 转义：cellRenderer 以 HTML 字符串输出，防止待办标题等用户输入注入 */
function escapeHtml(value: unknown): string {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

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

const rowKey = (row: TodoCenterItem) => `${row.todoType}-${row.GUID}`;

// 当前选中行（右侧详情高亮）
const selectedKey = ref('');

// AG-Grid 行标识：数据刷新后按 id 复用行，保留勾选与滚动位置
function getRowId(params: any): string {
  return rowKey(params.data);
}

// 行样式：选中高亮 / 已完成置灰 / 逾期标红
function getRowClass(params: any): string {
  const row = params.data as TodoCenterItem;
  if (!row) return '';
  const classes: string[] = [];
  if (rowKey(row) === selectedKey.value) classes.push('todo-row-selected');
  if (row.status === '已完成') classes.push('todo-row-done');
  else if (isOverdue(row)) classes.push('todo-row-overdue');
  return classes.join(' ');
}

// 行点击：任务待办 → 右侧详情；审批待办 → 跳转审批
function onRowClicked(event: any) {
  if (event.data) handleViewDetail(event.data);
}

// ============ 数据加载 ============
// silent=true 为静默刷新（切回标签页场景）：已有数据时不显示进度圈，
// 后台刷新完成后原位更新；列表为空时仍显示加载态。
async function loadData(silent = false) {
  if (!silent || list.value.length === 0) {
    loading.value = true;
  }
  try {
    const params: Record<string, string> = {};
    // keyword 走 AG-Grid quick filter 本地快速过滤，不再请求后端
    if (activeCategory.value !== 'all' && activeCategory.value !== 'overdue') params.status = activeCategory.value;

    const res = await fetchTodoCenter(params);
    if (res.data) {
      list.value = res.data.list || [];
      stats.value = res.data.stats;
    }
    // 列表变化后同步刷新右侧详情（完成/转办/置顶等操作后保持一致）
    refreshDetail();
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
      sourceOptions.value = (res.data.来源类型 || []).map((v: string) => ({ label: v, value: v }));
      priorityOptions.value = (res.data.优先级 || []).map((v: string) => ({ label: v, value: v }));
      if (res.data.待办状态?.length) statusOptions.value = res.data.待办状态;
    }
  } catch {
    // 接口失败时使用默认硬编码选项
    sourceOptions.value = [
      { label: '手动', value: '手动' },
      { label: '会议', value: '会议' },
      { label: '工作流', value: '工作流' },
      { label: '合同', value: '合同' }
    ];
    priorityOptions.value = [
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

// ============ 事件：开始 / 取消 / 重新打开 / 催办 ============
async function handleStart(item: TodoCenterItem) {
  try {
    await fetchTodoStart(item.GUID);
    message.success('已开始');
    await loadData();
  } catch (e: any) {
    message.error(e?.message || '操作失败');
  }
}

const showCancelModal = ref(false);
const cancelForm = ref({ guid: '' as string | number, reason: '' });

function handleCancel(item: TodoCenterItem) {
  cancelForm.value = { guid: item.GUID, reason: '' };
  showCancelModal.value = true;
}

async function handleCancelSubmit() {
  try {
    await fetchTodoCancel(cancelForm.value.guid, cancelForm.value.reason.trim());
    message.success('已取消');
    showCancelModal.value = false;
    await loadData();
  } catch (e: any) {
    message.error(e?.message || '操作失败');
  }
}

async function handleReopen(item: TodoCenterItem) {
  try {
    await fetchTodoReopen(item.GUID);
    message.success('已重新打开');
    await loadData();
  } catch (e: any) {
    message.error(e?.message || '操作失败');
  }
}

async function handleUrge(item: TodoCenterItem) {
  try {
    await fetchTodoUrge(item.GUID);
    message.success('已催办');
  } catch (e: any) {
    message.error(e?.message || '操作失败');
  }
}

async function handleTogglePin(item: TodoCenterItem) {
  try {
    const res: any = await fetchTodoTogglePin(item.GUID);
    message.success(res?.message || '操作成功');
    await loadData();
  } catch (e: any) {
    message.error(e?.message || '操作失败');
  }
}

// 复制新建：以现有待办内容预填新建表单
function handleCopyCreate(item: TodoCenterItem) {
  isEditMode.value = false;
  createForm.value = {
    guid: '',
    待办标题: item.title,
    负责人: item.assignee,
    待办描述: item.description || '',
    截止日期: item.dueDate || null,
    优先级: item.priority,
    来源类型: item.sourceType,
    重复规则: item.repeatRule || '',
    附件: parseAttachments(item.attachments)
  };
  showCreateModal.value = true;
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
  const guids = (gridApi.value?.getSelectedRows() || [])
    .filter(r => r.todoType === 'task')
    .map(r => r.GUID);

  if (guids.length === 0) {
    message.warning('请先选择要删除的任务待办');
    return;
  }
  try {
    await fetchTodoDelete(guids);
    message.success(`已删除 ${guids.length} 条`);
    gridApi.value?.deselectAll();
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
const repeatRuleOptions = [
  { label: '不重复', value: '' },
  { label: '每天', value: '每天' },
  { label: '每周', value: '每周' },
  { label: '每月', value: '每月' }
];

interface AttachmentMeta {
  name: string;
  file: string;
}

const createForm = ref({
  guid: '' as string | number,
  待办标题: '',
  负责人: '',
  待办描述: '',
  截止日期: null as string | null,
  优先级: '中',
  来源类型: '手动',
  重复规则: '',
  附件: [] as AttachmentMeta[]
});
const uploading = ref(false);
const fileInputRef = ref<HTMLInputElement | null>(null);

function triggerFileSelect() {
  if (!uploading.value) fileInputRef.value?.click();
}

function parseAttachments(json: string | null | undefined): AttachmentMeta[] {
  if (!json) return [];
  try {
    const arr = JSON.parse(json);
    return Array.isArray(arr) ? arr : [];
  } catch {
    return [];
  }
}

function handleFileSelect(e: Event) {
  const input = e.target as HTMLInputElement;
  const file = input.files?.[0];
  if (!file) return;
  if (file.size > 20 * 1024 * 1024) {
    message.error('文件不能超过 20MB');
    input.value = '';
    return;
  }
  uploading.value = true;
  fetchTodoUpload(file)
    .then((res: any) => {
      if (res?.data) {
        createForm.value.附件.push({ name: res.data.name, file: res.data.file });
      }
    })
    .catch((err: any) => message.error(err?.message || '上传失败'))
    .finally(() => {
      uploading.value = false;
      input.value = '';
    });
}

function removeAttachment(index: number) {
  createForm.value.附件.splice(index, 1);
}

function downloadAttachment(file: string, name: string) {
  const base = (import.meta.env.VITE_SERVICE_BASE_URL as string | undefined) || '';
  window.open(`${base}/todo/download?file=${encodeURIComponent(file)}&name=${encodeURIComponent(name)}`, '_blank');
}

function openCreateModal() {
  isEditMode.value = false;
  createForm.value = {
    guid: '',
    待办标题: '',
    负责人: '',
    待办描述: '',
    截止日期: null,
    优先级: '中',
    来源类型: '手动',
    重复规则: '',
    附件: []
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
    来源类型: item.sourceType,
    重复规则: item.repeatRule || '',
    附件: parseAttachments(item.attachments)
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
      来源类型: createForm.value.来源类型,
      重复规则: createForm.value.重复规则 || undefined,
      附件: createForm.value.附件.length > 0 ? createForm.value.附件 : undefined
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

// ============ 事件：详情（右侧面板） ============
const detailData = ref<TodoCenterItem | null>(null);
const detailLogs = ref<TodoLogItem[]>([]);
const logsLoading = ref(false);

// 子任务
interface SubtaskItem {
  GUID: number;
  title: string;
  status: string;
  assignee: string;
  dueDate: string | null;
  priority: string;
  completedAt: string | null;
}
const detailSubtasks = ref<SubtaskItem[]>([]);
const subtaskInput = ref('');

// 评论
interface CommentItem {
  GUID: number;
  author: string;
  authorName: string | null;
  content: string;
  createdAt: string;
}
const detailComments = ref<CommentItem[]>([]);
const commentInput = ref('');
const commentSubmitting = ref(false);

// 加载详情附加数据：流水 / 子任务 / 评论
async function loadDetailExtras(guid: string | number) {
  logsLoading.value = true;
  try {
    const [logRes, subRes, cmtRes] = await Promise.allSettled([
      fetchTodoLogs(guid),
      fetchTodoSubtasks(guid),
      fetchTodoComments(guid)
    ]);
    if (logRes.status === 'fulfilled' && logRes.value.data) detailLogs.value = logRes.value.data.list || [];
    if (subRes.status === 'fulfilled' && subRes.value.data) detailSubtasks.value = subRes.value.data.list || [];
    if (cmtRes.status === 'fulfilled' && cmtRes.value.data) detailComments.value = (cmtRes.value.data as any) || [];
  } finally {
    logsLoading.value = false;
  }
}

// 选中待办：右侧面板展示详情（审批待办跳转审批页）
async function handleViewDetail(item: TodoCenterItem) {
  if (item.todoType !== 'task') {
    handleWorkflowClick(item);
    return;
  }
  try {
    const res = await fetchTodoDetail(item.GUID);
    if (res.data) {
      detailData.value = res.data as TodoCenterItem;
      selectedKey.value = rowKey(item);
      // selectedKey 变化需要重绘行才能刷新选中高亮（AG-Grid 不会自动响应外部 ref）
      gridApi.value?.redrawRows();
      detailLogs.value = [];
      detailSubtasks.value = [];
      detailComments.value = [];
      subtaskInput.value = '';
      commentInput.value = '';
      loadDetailExtras(item.GUID);
    }
  } catch (e: any) {
    message.error(e?.message || '获取详情失败');
  }
}

// 列表刷新后同步刷新右侧详情（记录被删除/状态变更时保持一致）
async function refreshDetail() {
  const current = detailData.value;
  if (!current) return;
  try {
    const res = await fetchTodoDetail(current.GUID);
    if (res.data) {
      detailData.value = res.data as TodoCenterItem;
      loadDetailExtras(current.GUID);
    } else {
      clearDetail();
    }
  } catch {
    // 忽略（记录可能已被删除）
  }
}

function clearDetail() {
  detailData.value = null;
  selectedKey.value = '';
  gridApi.value?.redrawRows();
  detailLogs.value = [];
  detailSubtasks.value = [];
  detailComments.value = [];
}

// 添加子任务（负责人默认同父任务）
async function handleAddSubtask() {
  if (!detailData.value) return;
  const title = subtaskInput.value.trim();
  if (!title) {
    message.warning('请输入子任务标题');
    return;
  }
  try {
    await fetchTodoCreate({
      待办标题: title,
      负责人: detailData.value.assignee,
      优先级: detailData.value.priority,
      来源类型: '手动',
      父GUID: detailData.value.GUID
    });
    message.success('子任务已添加');
    subtaskInput.value = '';
    const res = await fetchTodoSubtasks(detailData.value.GUID);
    if (res.data) detailSubtasks.value = res.data.list || [];
  } catch (e: any) {
    message.error(e?.message || '添加失败');
  }
}

// 子任务快捷完成
async function handleSubtaskComplete(sub: SubtaskItem) {
  try {
    await fetchTodoComplete({ guid: sub.GUID });
    message.success('子任务已完成');
    if (detailData.value) {
      const res = await fetchTodoSubtasks(detailData.value.GUID);
      if (res.data) detailSubtasks.value = res.data.list || [];
    }
  } catch (e: any) {
    message.error(e?.message || '操作失败');
  }
}

// 提交评论
async function handleAddComment() {
  if (!detailData.value) return;
  const content = commentInput.value.trim();
  if (!content) {
    message.warning('请输入评论内容');
    return;
  }
  commentSubmitting.value = true;
  try {
    await fetchTodoAddComment(detailData.value.GUID, content);
    commentInput.value = '';
    const res = await fetchTodoComments(detailData.value.GUID);
    if (res.data) detailComments.value = (res.data as any) || [];
  } catch (e: any) {
    message.error(e?.message || '评论失败');
  } finally {
    commentSubmitting.value = false;
  }
}

const subtaskDoneCount = computed(() => detailSubtasks.value.filter(s => s.status === '已完成').length);

// 右侧详情头部操作按钮（更多菜单）
const detailMoreOptions = computed<DropdownOption[]>(() => {
  const d = detailData.value;
  if (!d || d.todoType !== 'task') return [];
  const isDone = d.status === '已完成' || d.status === '已取消';
  const options: DropdownOption[] = [{ label: '复制新建', key: 'copy' }];
  if (!isDone) {
    options.push(
      { label: '转办', key: 'reassign' },
      { label: '催办', key: 'urge' },
      { label: '取消', key: 'cancel' }
    );
  } else {
    options.push({ label: '重新打开', key: 'reopen' });
  }
  options.push({ label: d.pinned === '1' ? '取消置顶' : '置顶', key: 'pin' });
  options.push({ label: '删除', key: 'delete', props: { style: 'color: rgb(var(--error-color))' } });
  return options;
});

// 右侧详情操作分发（复用行操作逻辑）
function detailAction(key: string) {
  if (!detailData.value) return;
  handleRowAction(key, detailData.value);
}

// 时间线：动作类型与图标颜色
const actionTypeMap: Record<string, 'success' | 'info' | 'warning' | 'error' | 'default'> = {
  新增: 'success',
  修改: 'info',
  开始: 'info',
  完成: 'success',
  转办: 'warning',
  删除: 'error',
  催办: 'warning',
  取消: 'error',
  重新打开: 'info'
};

function renderLogContent(log: TodoLogItem): string {
  const who = log.operatorName || log.operator;
  let text = `${who} ${log.action}了该待办`;
  if (log.changes && log.changes.length > 0) {
    const parts = log.changes.map(c => `${c.field}: ${c.from} → ${c.to}`);
    text += `（${parts.join('；')}）`;
  }
  if (log.note) {
    text += `，${log.note}`;
  }
  return text;
}

// ============ 列定义（AG-Grid，与合同管理 v2 的合同列表一致） ============
/** 状态标签配色（对应原 NTag 的 type 映射） */
const todoStatusColors: Record<string, { color: string; bg: string }> = {
  待处理: { color: '#d97706', bg: 'rgba(245, 158, 11, 0.14)' },
  进行中: { color: '#2563eb', bg: 'rgba(59, 130, 246, 0.12)' },
  已完成: { color: '#16a34a', bg: 'rgba(34, 197, 94, 0.14)' },
  已取消: { color: '#6b7280', bg: 'rgba(107, 114, 128, 0.12)' }
};

/** 来源标签配色（对应原 NTag 的 type 映射） */
const todoSourceColors: Record<string, string> = {
  手动: '#6b7280',
  会议: '#2563eb',
  工作流: '#d97706',
  合同: '#16a34a'
};

const columnDefs: any[] = [
  createSequenceColumn(),
  {
    field: 'title',
    headerName: '标题',
    flex: 1,
    minWidth: 260,
    resizable: true,
    filter: 'agTextColumnFilter',
    cellRenderer: (params: any) => {
      const row = params.data as TodoCenterItem;
      if (!row) return '';
      const parts: string[] = [];
      if (row.pinned === '1') parts.push('<span class="todo-pin-icon" title="已置顶">📌</span>');
      if (row.sourceType) {
        const color = todoSourceColors[row.sourceType] || '#6b7280';
        parts.push(
          `<span class="todo-source-tag" style="color:${color};background:${color}1f;">${escapeHtml(row.sourceType)}</span>`
        );
      }
      parts.push(`<span class="todo-title-text">${escapeHtml(row.title)}</span>`);
      if (row.repeatRule) parts.push(`<span class="todo-repeat-tag">🔁${escapeHtml(row.repeatRule)}</span>`);
      return `<span class="todo-title-cell">${parts.join('')}</span>`;
    }
  },
  {
    field: 'assignee',
    headerName: '负责人',
    width: 150,
    minWidth: 110,
    resizable: true,
    filter: 'agTextColumnFilter',
    valueGetter: (params: any) => (params.data ? getUserName(params.data.assignee) : '')
  },
  {
    field: 'priority',
    headerName: '优先级',
    width: 90,
    minWidth: 70,
    resizable: true,
    filter: 'agTextColumnFilter',
    cellRenderer: (params: any) => {
      const p = params.value;
      if (!p) return '';
      const color = p === '高' ? '#ef4444' : p === '中' ? '#f59e0b' : '#10b981';
      return `<span style="color:${color};font-weight:${p === '高' ? 600 : 400}">${escapeHtml(p)}</span>`;
    }
  },
  {
    field: 'dueDate',
    headerName: '截止日期',
    width: 150,
    minWidth: 120,
    resizable: true,
    filter: 'agTextColumnFilter',
    cellRenderer: (params: any) => {
      const row = params.data as TodoCenterItem;
      if (!row || !row.dueDate) return '<span style="color:#9ca3af">-</span>';
      if (isOverdue(row)) {
        return `<span class="todo-overdue-date">${escapeHtml(row.dueDate)} 逾期${overdueDays(row)}天</span>`;
      }
      return escapeHtml(row.dueDate);
    }
  },
  {
    field: 'status',
    headerName: '状态',
    width: 100,
    minWidth: 80,
    resizable: true,
    filter: 'agTextColumnFilter',
    cellRenderer: (params: any) => {
      const s = params.value;
      if (!s) return '';
      const c = todoStatusColors[s] || { color: '#6b7280', bg: 'rgba(107, 114, 128, 0.12)' };
      return `<span class="todo-status-tag" style="color:${c.color};background:${c.bg}">${escapeHtml(s)}</span>`;
    }
  },
  {
    field: 'sourceTitle',
    headerName: '来源',
    width: 150,
    minWidth: 110,
    resizable: true,
    filter: 'agTextColumnFilter',
    valueGetter: (params: any) => {
      const row = params.data as TodoCenterItem;
      if (!row) return '';
      // 审批待办点击行即跳转审批（onRowClicked），来源列仅作展示
      return row.sourceTitle || row.sourceType || '-';
    }
  }
];

// 更多下拉操作分发
function handleRowAction(key: string, row: TodoCenterItem) {
  switch (key) {
    case 'detail':
      handleViewDetail(row);
      break;
    case 'copy':
      handleCopyCreate(row);
      break;
    case 'reassign':
      handleReassign(row);
      break;
    case 'urge':
      handleUrge(row);
      break;
    case 'cancel':
      handleCancel(row);
      break;
    case 'reopen':
      handleReopen(row);
      break;
    case 'pin':
      handleTogglePin(row);
      break;
    case 'delete':
      dialog.warning({
        title: '删除确认',
        content: `确认删除待办「${row.title}」？`,
        positiveText: '删除',
        negativeText: '取消',
        onPositiveClick: () => handleDelete(row)
      });
      break;
    default:
      break;
  }
}

// 预载人员映射（表格/详情负责人显示姓名）
async function loadUserMap() {
  try {
    const res = await fetchTodoUserOptions('', '');
    if (res.data) {
      res.data.forEach(u => selectedUserMap.value.set(u.工号, u));
      selectedUserMap.value = new Map(selectedUserMap.value);
      // 人员映射就绪后刷新单元格，让负责人列从工号变为姓名
      gridApi.value?.refreshCells({ force: true });
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

// KeepAlive 场景：切回标签页时 onMounted 不会再次触发，用 onActivated 刷新。
// 首次挂载时 activated 与 mounted 同帧触发，跳过以避免双重请求；
// 此后每次切回标签页刷新列表与统计（选项、用户映射为慢变参考数据，不重复拉取）。
let isFirstActivation = true;
onActivated(() => {
  if (isFirstActivation) {
    isFirstActivation = false;
    return;
  }
  loadData(true);
});
</script>

<template>
  <div class="todo-container" :class="{ 'system-dark': isDarkMode }">
    <!-- 左侧：待办列表 -->
    <div class="todo-panel todo-panel-left" :style="{ width: leftWidth + 'px', maxWidth: 'calc(100% - 320px)' }">
      <div class="panel-header">
        <span class="panel-title">待办列表</span>
        <div class="header-actions">
          <NButton size="small" :disabled="selectedTaskCount === 0" @click="handleBatchDelete">
            批量删除
          </NButton>
          <NButton type="primary" size="small" @click="openCreateModal">
            + 新建待办
          </NButton>
        </div>
      </div>

      <!-- 分类 Tab（含计数） -->
      <div class="tab-bar">
        <NTabs v-model:value="activeCategory" type="line" @update:value="handleCategoryChange">
          <NTabPane
            v-for="cat in categories"
            :key="cat.key"
            :name="cat.key"
            :tab="`${cat.label} ${stats[cat.statKey]}`"
          />
        </NTabs>
      </div>

      <!-- 表格上方工具栏：搜索框 -->
      <div class="grid-toolbar">
        <NInput
          v-model:value="keyword"
          size="small"
          placeholder="搜索标题/负责人/状态..."
          clearable
          class="search-input"
        >
          <template #suffix>
            <NButton text size="small">
              <template #icon>
                <icon-mdi-magnify />
              </template>
            </NButton>
          </template>
        </NInput>
      </div>

      <!-- 列表（AG-Grid，与合同管理 v2 的合同列表一致） -->
      <div class="grid-container">
        <AgGridVue
          class="todo-grid"
          :theme="gridTheme"
          :row-data="filteredList"
          :column-defs="columnDefs"
          :default-col-def="defaultColDef"
          :column-types="columnTypes"
          :locale-text="AG_GRID_LOCALE_CN"
          :row-height="38"
          :header-height="40"
          :animate-rows="true"
          :loading="loading"
          :pagination="true"
          :pagination-page-size="50"
          :pagination-page-size-selector="[50, 100, 200]"
          :row-selection="rowSelection"
          :quick-filter-text="keyword"
          :get-row-id="getRowId"
          :get-row-class="getRowClass"
          @grid-ready="onGridReady"
          @row-clicked="onRowClicked"
          @selection-changed="onSelectionChanged"
        />
      </div>
    </div>

    <!-- 拖拽分隔条 -->
    <div class="resize-splitter" :class="{ 'is-resizing': isResizing }" @mousedown="startResize">
      <div class="resize-line" />
    </div>

    <!-- 右侧：待办详情 -->
    <div class="todo-panel todo-panel-right">
      <div class="panel-header">
        <span class="panel-title">待办详情</span>
        <div v-if="detailData && detailData.todoType === 'task'" class="header-actions">
          <NButton
            v-if="detailData.status === '待处理'"
            size="small"
            type="primary"
            @click="handleStart(detailData)"
          >开始</NButton>
          <NButton
            v-else-if="detailData.status === '进行中'"
            size="small"
            type="primary"
            @click="handleComplete(detailData)"
          >完成</NButton>
          <NButton size="small" @click="handleEdit(detailData)">编辑</NButton>
          <NDropdown :options="detailMoreOptions" trigger="click" @select="detailAction">
            <NButton size="small">更多</NButton>
          </NDropdown>
        </div>
      </div>

      <div class="panel-content">
        <NEmpty v-if="!detailData" description="请选择左侧待办查看详情" class="detail-empty" />
        <template v-else>
          <NDescriptions label-placement="left" :column="2" bordered size="small">
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
            <NDescriptionsItem v-if="detailData.repeatRule" label="重复规则" :span="2">🔁 {{ detailData.repeatRule }}（完成时自动生成下一期）</NDescriptionsItem>
            <NDescriptionsItem label="描述" :span="2">{{ detailData.description || '-' }}</NDescriptionsItem>
            <NDescriptionsItem v-if="parseAttachments(detailData.attachments).length > 0" label="附件" :span="2">
              <div class="detail-attach-list">
                <a
                  v-for="att in parseAttachments(detailData.attachments)"
                  :key="att.file"
                  class="detail-attach-link"
                  @click="downloadAttachment(att.file, att.name)"
                >📎 {{ att.name }}</a>
              </div>
            </NDescriptionsItem>
          </NDescriptions>

          <!-- 子任务 + 评论 + 处理流水（仅任务待办） -->
          <template v-if="detailData.todoType === 'task'">
            <NDivider title-placement="left" style="margin: 16px 0 8px">
              子任务（{{ subtaskDoneCount }}/{{ detailSubtasks.length }}）
            </NDivider>
            <div class="subtask-list">
              <div v-if="detailSubtasks.length === 0" class="subtask-empty">暂无子任务</div>
              <div v-for="sub in detailSubtasks" :key="sub.GUID" class="subtask-item">
                <NTag size="small" :type="sub.status === '已完成' ? 'success' : sub.status === '进行中' ? 'info' : 'default'">
                  {{ sub.status }}
                </NTag>
                <span class="subtask-title" :class="{ done: sub.status === '已完成' }">{{ sub.title }}</span>
                <NButton
                  v-if="sub.status !== '已完成' && sub.status !== '已取消'"
                  text
                  type="primary"
                  size="small"
                  @click="handleSubtaskComplete(sub)"
                >完成</NButton>
              </div>
            </div>
            <div class="subtask-add">
              <NInput v-model:value="subtaskInput" size="small" placeholder="添加子任务，回车提交" @keyup.enter="handleAddSubtask" />
              <NButton size="small" type="primary" @click="handleAddSubtask">添加</NButton>
            </div>

            <NDivider title-placement="left" style="margin: 16px 0 8px">评论（{{ detailComments.length }}）</NDivider>
            <div class="comment-list">
              <div v-if="detailComments.length === 0" class="subtask-empty">暂无评论</div>
              <div v-for="cmt in detailComments" :key="cmt.GUID" class="comment-item">
                <div class="comment-head">
                  <span class="comment-author">{{ cmt.authorName || cmt.author }}</span>
                  <span class="comment-time">{{ cmt.createdAt }}</span>
                </div>
                <div class="comment-content">{{ cmt.content }}</div>
              </div>
            </div>
            <div class="comment-add">
              <NInput v-model:value="commentInput" type="textarea" :rows="2" placeholder="发表评论（通知负责人与指派人）" />
              <NButton size="small" type="primary" :loading="commentSubmitting" :disabled="!commentInput.trim()" style="margin-top: 6px" @click="handleAddComment">
                发表评论
              </NButton>
            </div>

            <NDivider title-placement="left" style="margin: 16px 0 8px">处理流水</NDivider>
            <NSpin :show="logsLoading" size="small">
              <NTimeline v-if="detailLogs.length > 0" style="padding: 4px 4px 0">
                <NTimelineItem
                  v-for="log in detailLogs"
                  :key="log.GUID"
                  :type="actionTypeMap[log.action] || 'default'"
                  :title="`${log.operatorName || log.operator} · ${log.action}`"
                  :content="renderLogContent(log)"
                  :time="log.operatedAt"
                />
              </NTimeline>
              <div v-else-if="!logsLoading" style="padding: 8px 4px; color: rgb(var(--base-text-color) / 0.55); font-size: 13px">
                暂无流水记录
              </div>
            </NSpin>
          </template>
        </template>
      </div>
    </div>

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
          <NSelect v-model:value="createForm.优先级" :options="priorityOptions" />
        </NFormItem>
        <NFormItem label="来源">
          <NSelect v-model:value="createForm.来源类型" :options="sourceOptions" />
        </NFormItem>
        <NFormItem label="重复">
          <NSelect v-model:value="createForm.重复规则" :options="repeatRuleOptions" />
        </NFormItem>
        <NFormItem label="附件">
          <div class="attach-area">
            <div v-for="(att, i) in createForm.附件" :key="att.file" class="attach-item">
              <span class="attach-name">📎 {{ att.name }}</span>
              <NButton text type="error" size="small" @click="removeAttachment(i)">删除</NButton>
            </div>
            <input ref="fileInputRef" type="file" style="display: none" @change="handleFileSelect" />
            <NButton size="small" :loading="uploading" @click="triggerFileSelect">{{ uploading ? '上传中...' : '+ 添加附件' }}</NButton>
          </div>
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

    <!-- 取消待办弹窗 -->
    <NModal v-model:show="showCancelModal" preset="card" title="取消待办" style="width: 420px">
      <NForm label-placement="left" :label-width="80">
        <NFormItem label="取消原因">
          <NInput v-model:value="cancelForm.reason" type="textarea" :rows="3" placeholder="可选，填写取消原因" />
        </NFormItem>
      </NForm>
      <template #footer>
        <NSpace justify="end">
          <NButton @click="showCancelModal = false">再想想</NButton>
          <NButton type="error" @click="handleCancelSubmit">确认取消</NButton>
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

<style lang="scss" scoped>
@use '@/styles/scss/ag-grid-shared' as *;

/* ============ 左右分栏容器 ============
   经 menu-bridge 桥接渲染（def_function.前端路由 = oa-todo-center），
   bridge-content-region 提供position:relative 定位上下文，绝对定位铺满该区域 */
.todo-container {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  overflow: hidden;
}

.todo-panel {
  position: relative;
  display: flex;
  flex-direction: column;
  height: 100%;
  background: #fff;
  border: 1px solid #e8e8e8;
  border-radius: 8px;
  overflow: hidden;
}

/* 暗色模式（与 contract-v2 一致） */
.system-dark .todo-panel {
  background: rgb(24, 24, 28);
  border-color: rgba(255, 255, 255, 0.09);
}

.todo-panel-left {
  flex-shrink: 0;
}

.todo-panel-right {
  flex: 1;
  min-width: 0;
}

/* 面板头部（与 contract-v2 一致：#fafafa 底 + 16px 间距，按钮组右靠） */
.panel-header {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 10px 16px;
  border-bottom: 1px solid #e8e8e8;
  background: #fafafa;
  flex-shrink: 0;
  box-sizing: border-box;
}

.system-dark .panel-header {
  background: rgb(36, 36, 40);
  border-color: rgba(255, 255, 255, 0.09);
}

.panel-title {
  font-size: 16px;
  font-weight: 600;
  color: rgb(var(--base-text-color));
}

.header-actions {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-left: auto;
}

/* Tab 栏（分类 + 计数，与 contract-v2 一致：16px 内边距 + 底部分隔线） */
.tab-bar {
  padding: 0 16px;
  flex-shrink: 0;
  border-bottom: 1px solid #f0f0f0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.system-dark .tab-bar {
  border-bottom-color: rgba(255, 255, 255, 0.09);
}

.tab-bar :deep(.n-tabs) {
  flex: 1;
  min-width: 0;
}

.tab-bar :deep(.n-tabs-tab) {
  padding: 8px 0;
}

/* 表格上方工具栏（搜索框，与 contract-v2 一致） */
.grid-toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 16px;
  flex-shrink: 0;

  .search-input {
    flex: 1;
    min-width: 240px;
  }
}

/* 列表区（AG-Grid 铺满面板，与 contract-v2 一致） */
.grid-container {
  flex: 1;
  min-height: 400px;
  height: 100%;
  overflow: auto;
}

/* AG-Grid 主题变量与共享样式（与 contract-v2 的合同列表一致） */
.todo-grid {
  --wb-grid-surface: transparent;
  --wb-grid-text: #1f2937;
  width: 100%;
  height: 100%;

  .system-dark & {
    --wb-grid-surface: rgb(var(--container-bg-color));
    --wb-grid-text: rgb(var(--base-text-color));
  }
}

@include ag-grid-base-layout('todo-grid');
@include ag-grid-cell-borders('todo-grid', #e8eef4, rgba(255, 255, 255, 0.06));
@include ag-grid-selection-column('todo-grid');
@include ag-grid-checkbox-theme('todo-grid');
@include ag-grid-cell-focus('todo-grid');
@include ag-grid-checkbox-dark('todo-grid');
@include ag-grid-base-dark('todo-grid');
@include ag-grid-controls-dark('todo-grid');

/* 拖拽分隔条 */
.resize-splitter {
  width: 8px;
  cursor: col-resize;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  transition: background-color 0.2s;
}

.resize-splitter:hover,
.resize-splitter.is-resizing {
  background-color: rgb(var(--primary-color) / 0.12);
}

.resize-line {
  width: 2px;
  height: 24px;
  border-radius: 1px;
  background-color: rgb(var(--base-text-color) / 0.25);
}

/* 详情面板内容 */
.panel-content {
  flex: 1;
  overflow-y: auto;
  padding: 16px;
  min-height: 0;
}

.detail-empty {
  margin-top: 120px;
}

/* 行样式（AG-Grid 行类，由 getRowClass 注入） */
:deep(.todo-grid .ag-row.todo-row-overdue) {
  background-color: rgb(var(--error-color) / 0.08) !important;
}

:deep(.todo-grid .ag-row.todo-row-overdue.ag-row-hover) {
  background-color: rgb(var(--error-color) / 0.14) !important;
}

:deep(.todo-grid .ag-row.todo-row-done) {
  background-color: rgb(var(--base-text-color) / 0.04) !important;
  opacity: 0.62;
}

:deep(.todo-grid .ag-row.todo-row-done .todo-title-text) {
  text-decoration: line-through;
  color: rgb(var(--base-text-color) / 0.45);
}

:deep(.todo-grid .ag-row.todo-row-selected) {
  background-color: rgb(var(--primary-color) / 0.1) !important;
}

:deep(.todo-grid .ag-row.todo-row-selected.ag-row-hover) {
  background-color: rgb(var(--primary-color) / 0.14) !important;
}

/* 单元格内容（cellRenderer 注入的 HTML 类） */
:deep(.todo-grid .todo-title-cell) {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  overflow: hidden;
}

:deep(.todo-grid .todo-title-text) {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

:deep(.todo-grid .todo-source-tag) {
  flex-shrink: 0;
  padding: 0 6px;
  border-radius: 3px;
  font-size: 12px;
  line-height: 18px;
}

:deep(.todo-grid .todo-repeat-tag) {
  flex-shrink: 0;
  font-size: 12px;
}

:deep(.todo-grid .todo-pin-icon) {
  font-size: 12px;
  line-height: 1;
  flex-shrink: 0;
}

:deep(.todo-grid .todo-status-tag) {
  display: inline-block;
  padding: 0 8px;
  border-radius: 9px;
  font-size: 12px;
  line-height: 18px;
}

:deep(.todo-grid .todo-overdue-date) {
  color: rgb(var(--error-color));
  font-weight: 600;
}

/* 附件（表单） */
.attach-area {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.attach-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 4px 8px;
  border-radius: 4px;
  background: rgb(var(--primary-color) / 0.06);
}

.attach-name {
  font-size: 13px;
  color: rgb(var(--base-text-color));
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* 详情附件链接 */
.detail-attach-list {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.detail-attach-link {
  color: rgb(var(--primary-color));
  cursor: pointer;
  font-size: 13px;
}

.detail-attach-link:hover {
  text-decoration: underline;
}

/* 子任务 */
.subtask-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-bottom: 8px;
}

.subtask-empty {
  font-size: 13px;
  color: rgb(var(--base-text-color) / 0.45);
  padding: 4px 0;
}

.subtask-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 8px;
  border-radius: 6px;
  background: rgb(var(--base-text-color) / 0.04);
}

.subtask-title {
  flex: 1;
  font-size: 13px;
  color: rgb(var(--base-text-color));
}

.subtask-title.done {
  text-decoration: line-through;
  color: rgb(var(--base-text-color) / 0.45);
}

.subtask-add {
  display: flex;
  gap: 8px;
}

/* 评论 */
.comment-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-bottom: 8px;
  max-height: 200px;
  overflow-y: auto;
}

.comment-item {
  padding: 8px 10px;
  border-radius: 6px;
  background: rgb(var(--base-text-color) / 0.04);
}

.comment-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 4px;
}

.comment-author {
  font-size: 13px;
  font-weight: 600;
  color: rgb(var(--base-text-color));
}

.comment-time {
  font-size: 12px;
  color: rgb(var(--base-text-color) / 0.45);
}

.comment-content {
  font-size: 13px;
  color: rgb(var(--base-text-color) / 0.85);
  line-height: 1.5;
  word-break: break-all;
}
</style>
