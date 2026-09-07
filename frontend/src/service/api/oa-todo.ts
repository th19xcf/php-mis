import { request } from '../request';

/** 待办中心合并列表项 */
export interface TodoCenterItem {
  todoType: 'task' | 'workflow';
  GUID: number;
  title: string;
  description: string;
  assignee: string;
  assigner: string;
  dueDate: string | null;
  priority: string;
  status: string;
  sourceType: string;
  sourceTitle: string;
  sourceGuid: number | null;
  completedAt: string | null;
  completedNote: string;
  personCode: string;
  createdAt: string;
  updatedAt: string;
  /** 置顶标识（0/1，审批待办恒为0） */
  pinned?: string;
  /** 重复规则（每天/每周/每月） */
  repeatRule?: string | null;
  /** 附件 JSON 串（[{name,file}]） */
  attachments?: string | null;
  /** 审批待办特有 */
  bizType?: string;
  bizId?: string;
  instanceId?: number;
  nodeCode?: string;
}

/** 统计卡计数 */
export interface TodoStats {
  all: number;
  pending: number;
  doing: number;
  done: number;
  overdue: number;
}

/** 待办中心返回 */
export interface TodoCenterResult {
  list: TodoCenterItem[];
  stats: TodoStats;
}

/** 待办中心主数据 */
export function fetchTodoCenter(params?: {
  status?: string;
  sourceType?: string;
  priority?: string;
  keyword?: string;
}) {
  return request<TodoCenterResult>({ url: '/todo/center', params });
}

/** 统计卡计数（Header 角标用） */
export function fetchTodoStats() {
  return request<TodoStats>({ url: '/todo/stats' });
}

/** 附件元数据 */
export interface TodoAttachment {
  name: string;
  file: string;
}

/** 手动新建待办 */
export function fetchTodoCreate(data: {
  待办标题: string;
  负责人: string;
  待办描述?: string;
  来源类型?: string;
  来源摘要?: string;
  指派人?: string;
  截止日期?: string;
  优先级?: string;
  待办状态?: string;
  关联人员编码?: string;
  重复规则?: string;
  父GUID?: string | number;
  附件?: TodoAttachment[];
}) {
  return request({ url: '/todo/create', method: 'post', data });
}

/** 修改待办 */
export function fetchTodoUpdate(data: {
  guid: string | number;
  待办标题?: string;
  待办描述?: string;
  来源类型?: string;
  来源摘要?: string;
  指派人?: string;
  负责人?: string;
  截止日期?: string;
  优先级?: string;
  待办状态?: string;
  完成说明?: string;
  关联人员编码?: string;
  重复规则?: string;
  附件?: TodoAttachment[];
}) {
  return request({ url: '/todo/update', method: 'post', data });
}

/** 标记完成 */
export function fetchTodoComplete(data: { guid: string | number; 完成说明?: string }) {
  return request({ url: '/todo/complete', method: 'post', data });
}

/** 转办 */
export function fetchTodoReassign(data: { guid: string | number; 新负责人: string }) {
  return request({ url: '/todo/reassign', method: 'post', data });
}

/** 开始待办（待处理 → 进行中） */
export function fetchTodoStart(guid: string | number) {
  return request({ url: '/todo/start', method: 'post', data: { guid } });
}

/** 取消待办 */
export function fetchTodoCancel(guid: string | number, 取消原因 = '') {
  return request({ url: '/todo/cancel', method: 'post', data: { guid, 取消原因 } });
}

/** 重新打开（已完成/已取消 → 进行中） */
export function fetchTodoReopen(guid: string | number) {
  return request({ url: '/todo/reopen', method: 'post', data: { guid } });
}

/** 催办 */
export function fetchTodoUrge(guid: string | number) {
  return request({ url: '/todo/urge', method: 'post', data: { guid } });
}

/** 置顶/取消置顶 */
export function fetchTodoTogglePin(guid: string | number) {
  return request({ url: '/todo/toggle-pin', method: 'post', data: { guid } });
}

/** 子任务列表 */
export function fetchTodoSubtasks(guid: string | number) {
  return request({ url: '/todo/subtasks', method: 'get', params: { guid } });
}

/** 评论列表 */
export function fetchTodoComments(guid: string | number) {
  return request({ url: '/todo/comments', method: 'get', params: { guid } });
}

/** 添加评论 */
export function fetchTodoAddComment(guid: string | number, content: string) {
  return request({ url: '/todo/comment', method: 'post', data: { guid, content } });
}

/** 上传附件（form-data） */
export function fetchTodoUpload(file: File) {
  const formData = new FormData();
  formData.append('file', file);
  return request({ url: '/todo/upload', method: 'post', data: formData });
}

// ============ 站内消息 ============

export interface MessageItem {
  GUID: number;
  type: string;
  title: string;
  content: string | null;
  todoGuid: number | null;
  isRead: string;
  readAt: string | null;
  createdAt: string;
}

/** 我的站内消息 */
export interface MessageListResult {
  list: MessageItem[];
  total: number;
  unread: number;
}

export function fetchMessages(params?: { page?: number; pageSize?: number; unreadOnly?: boolean }) {
  return request<MessageListResult>({
    url: '/todo/messages',
    method: 'get',
    params: { page: 1, pageSize: 20, ...params, unreadOnly: params?.unreadOnly ? 1 : undefined }
  });
}

/** 未读消息数 */
export function fetchMessagesCount() {
  return request<{ count: number }>({ url: '/todo/messages-count', method: 'get' });
}

/** 标记已读（ids 数组或全部） */
export function fetchMessagesRead(payload: { ids?: number[]; all?: boolean }) {
  return request({ url: '/todo/messages-read', method: 'post', data: payload });
}

/** 批量删除 */
export function fetchTodoDelete(guids: (string | number)[]) {
  return request({ url: '/todo/delete', method: 'post', data: { guids } });
}

/** 待办详情 */
export function fetchTodoDetail(guid: string | number) {
  return request({ url: '/todo/detail', params: { guid } });
}

/** 操作流水条目（详情时间线） */
export interface TodoLogItem {
  GUID: number;
  action: string;
  operator: string;
  operatorName: string | null;
  changes: { field: string; from: string; to: string }[] | null;
  note: string | null;
  operatedAt: string;
}

/** 操作流水 */
export function fetchTodoLogs(guid: string | number) {
  return request<{ list: TodoLogItem[] }>({ url: '/todo/logs', params: { guid } });
}

/** 下拉选项 */
export function fetchTodoOptions() {
  return request<{ 优先级: string[]; 待办状态: string[]; 来源类型: string[] }>({ url: '/todo/options' });
}

/** 人员选择（负责人/转办） */
export interface TodoUserOption {
  工号: string;
  姓名: string;
  员工部门编码: string;
  员工部门全称: string;
}

/** 部门树节点 */
export interface DeptTreeNode {
  部门编码: string;
  部门名称: string;
  部门全称: string;
  部门级别: number;
  children?: DeptTreeNode[];
}

export function fetchTodoUserOptions(keyword = '', deptCode = '') {
  return request<TodoUserOption[]>({ url: '/todo/user-options', params: { keyword, deptCode } });
}

/** 部门树 */
export function fetchTodoDeptTree() {
  return request<DeptTreeNode[]>({ url: '/todo/dept-tree' });
}
