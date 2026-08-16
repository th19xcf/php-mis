import { useMessage as _useMessage } from 'naive-ui';

/**
 * 对 naive-ui 的 useMessage() 进行一层包装：
 *   每次调用 message.success / warning / error / info 时，
 *   除了显示到 UI 弹窗，额外在浏览器控制台输出一条对应级别的日志，
 *   便于用户在 F12 Console 中查看完整的弹窗历史和排查问题。
 *
 * 用法与原生 naive-ui 的 useMessage 完全一致：
 *   import { useMessageWithConsole } from '@/hooks/business/use-message-with-console';
 *   const message = useMessageWithConsole();
 *   message.error('内容');
 */
export function useMessageWithConsole(): ReturnType<typeof _useMessage> {
  const msg = _useMessage();

  const levels = ['success', 'warning', 'error', 'info'] as const;
  const consoleMap: Record<string, 'log' | 'warn' | 'error' | 'info'> = {
    success: 'log',
    warning: 'warn',
    error: 'error',
    info: 'info'
  };

  const wrapped: any = { ...msg };

  for (const level of levels) {
    const original = (msg as any)[level]?.bind(msg);
    if (typeof original !== 'function') continue;
    wrapped[level] = (content: unknown, ...rest: any[]) => {
      const contentStr = typeof content === 'string' ? content : JSON.stringify(content);
      const tag = `[NAIVE-UI][${level.toUpperCase()}]`;
      const method = consoleMap[level];
      (console as any)[method](`${tag} ${new Date().toLocaleTimeString()}  ${contentStr}`, ...rest);
      return original(content, ...rest);
    };
  }

  return wrapped;
}

/**
 * 默认导出，便于 import useMessage from '...' 的写法
 */
export default useMessageWithConsole;
