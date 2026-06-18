/**
 * 工作台通知 composable
 *
 * 集中封装 window.$message（naive-ui 全局消息）+ 控制台日志的输出，
 * 原本散落在 generic-query-workbench.vue 的 msg() 函数与此处的 NotifyType 类型。
 *
 * 使用：
 * ```ts
 * const { notify } = useWorkbenchNotify();
 * notify('success', '保存成功');
 * notify('error', '操作失败');
 * ```
 *
 * 行为：
 * - success / error / warning / info 四种类型
 * - 统一走 window.$message（naive-ui），组件树外也能弹出
 * - error 走 console.error，warning 走 console.warn，其余走 console.info / logger
 */

import { logger } from '@/utils/logger';

export type NotifyType = 'success' | 'error' | 'warning' | 'info';

export function useWorkbenchNotify() {
  function notify(type: NotifyType, message: string, _data?: unknown) {
    window.$message?.[type](message);

    const prefix = `[${type.toUpperCase()}]`;

    switch (type) {
      case 'error':
        console.error(prefix, message);
        break;
      case 'warning':
        console.warn(prefix, message);
        break;
      case 'success':
        logger.info(
          `%c${prefix}%c ${message}`,
          'color: #52c41a; font-weight: bold;',
          ''
        );
        break;
      case 'info':
        console.info(prefix, message);
        break;
      default:
        logger.info(prefix, message);
    }
  }

  return { notify };
}
