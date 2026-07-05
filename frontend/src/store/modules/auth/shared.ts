import { localStg } from '@/utils/storage';

/** Get token */
export function getToken() {
  return localStg.get('token') || '';
}

/** Clear auth storage */
export function clearAuthStorage() {
  localStg.remove('token');
  localStg.remove('refreshToken');
}

/**
 * 清理工作台相关的业务缓存（localStorage 持久化部分）
 * - globalTabs：标签页缓存
 * - lastLoginUserId：上次登录用户 ID（用于换号检测）
 *
 * 注意：workbench 的列状态/筛选/固定列等是 Pinia 内存 store，
 *       页面刷新或退出登录时 Pinia 实例自然销毁，无需手动清理。
 */
export function clearWorkbenchCache() {
  localStg.remove('globalTabs');
  localStg.remove('lastLoginUserId');
}
