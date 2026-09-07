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

/** 批量删除 */
export function fetchTodoDelete(guids: (string | number)[]) {
  return request({ url: '/todo/delete', method: 'post', data: { guids } });
}

/** 待办详情 */
export function fetchTodoDetail(guid: string | number) {
  return request({ url: '/todo/detail', params: { guid } });
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
