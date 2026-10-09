import { request } from '../request';
import type { TodoCenterItem, TodoStats } from './oa-todo';

/** 卡片配置项 */
export interface WidgetConfig {
  卡片编码: string;
  卡片名称: string;
  显示顺序: number;
  显示标识: string;
  配置参数?: string | null;
}

/** 待办卡片数据 */
export interface TodoWidgetData {
  list: TodoCenterItem[];
  stats: TodoStats;
}

/** 获取当前用户的卡片配置 */
export function fetchDashboardWidgets() {
  return request<WidgetConfig[]>({ url: '/dashboard/widgets' });
}

/** 保存卡片显示/隐藏配置 */
export function saveDashboardWidgets(widgets: { 卡片编码: string; 显示标识: string; 显示顺序?: number }[]) {
  return request<null>({ url: '/dashboard/widgets', method: 'post', data: { widgets } });
}

/** 获取待办卡片数据 */
export function fetchDashboardTodo() {
  return request<TodoWidgetData>({ url: '/dashboard/todo' });
}
