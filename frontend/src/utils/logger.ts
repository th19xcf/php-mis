/**
 * 统一日志工具
 *
 * 设计目标：
 * 1. 开发环境（import.meta.env.DEV === true）打印带前缀的彩色日志，便于阅读
 * 2. 生产环境（prod build）通过 Vite esbuild.drop 把 console.* 整段消除
 * 3. logger.error 永远保留（用于线上 Sentry 接入或现场排错）
 *
 * 约定：
 * - 业务调试输出 → logger.info / logger.debug
 * - 用户可感知的错误 → logger.error
 * - 警告（如请求被取消）→ logger.warn
 *
 * ⚠️ 不要在此文件中引入额外依赖，避免循环依赖与 bundle 体积膨胀
 */

const isDev = import.meta.env.DEV;

function formatPrefix(tag: string, color: string): readonly [string, string] {
  return [`%c[${tag}]`, `color:${color};font-weight:bold;`];
}

export const logger = {
  /** 一般信息，开发环境可见 */
  info(msg: string, ...args: unknown[]): void {
    if (isDev) {
      // eslint-disable-next-line no-console
      console.log(...formatPrefix('INFO', '#52c41a'), msg, ...args);
    }
  },

  /** 调试信息，比 info 更啰嗦，开发环境可见 */
  debug(msg: string, ...args: unknown[]): void {
    if (isDev) {
      // eslint-disable-next-line no-console
      console.debug(...formatPrefix('DEBUG', '#1677ff'), msg, ...args);
    }
  },

  /** 警告（可恢复），dev 可见 */
  warn(msg: string, ...args: unknown[]): void {
    if (isDev) {
      // eslint-disable-next-line no-console
      console.warn(...formatPrefix('WARN', '#faad14'), msg, ...args);
    }
  },

  /** 错误，不可恢复，所有环境保留（接入 Sentry 后改为上报） */
  error(msg: string, ...args: unknown[]): void {
    // eslint-disable-next-line no-console
    console.error(...formatPrefix('ERROR', '#f5222d'), msg, ...args);
  },

  /** 分组开始（替代 console.group / console.groupCollapsed） */
  groupStart(label?: string, _collapsed = false): void {
    if (isDev) {
      // eslint-disable-next-line no-console
      console.group(label);
    }
  },

  /** 分组结束（替代 console.groupEnd） */
  groupEnd(): void {
    if (isDev) {
      // eslint-disable-next-line no-console
      console.groupEnd();
    }
  }
};

export default logger;
