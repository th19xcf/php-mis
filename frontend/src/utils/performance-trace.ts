/**
 * 菜单点击 → 页面渲染 全链路性能追踪工具
 *
 * 使用方式：
 * 1. 菜单点击时调用 startTrace() 获取 sessionId
 * 2. 各环节调用 markTrace(sessionId, '环节名称') 标记时间戳
 * 3. 渲染完成时调用 endTrace(sessionId) 输出汇总表
 *
 * 所有时间戳基于 performance.now()，精确到毫秒级。
 * 仅在开发环境输出，生产环境自动跳过。
 */

const isDev = import.meta.env.DEV;

interface TraceMark {
  label: string;
  time: number;
}

interface TraceSession {
  sessionId: string;
  marks: TraceMark[];
  startTime: number;
  meta: Record<string, string>;
}

// 全局会话存储，key = sessionId
const sessions = new Map<string, TraceSession>();

// 当前活跃的 sessionId，用于跨文件传递（避免每次都传参）
let currentSessionId: string | null = null;

/**
 * 开始一次性能追踪
 * @param tag 可选标签，如菜单名或 functionCode
 * @returns sessionId
 */
export function startTrace(tag = ''): string {
  if (!isDev) return '';

  const sessionId = `perf_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;
  const startTime = performance.now();

  sessions.set(sessionId, {
    sessionId,
    marks: [{ label: '菜单点击', time: startTime }],
    startTime,
    meta: tag ? { tag } : {}
  });

  currentSessionId = sessionId;
  return sessionId;
}

/**
 * 标记一个性能节点
 * @param sessionId 会话ID，不传则使用当前活跃会话
 * @param label 节点名称
 * @param meta 附加信息
 */
export function markTrace(label: string, meta?: Record<string, string>): void;
export function markTrace(sessionId: string, label: string, meta?: Record<string, string>): void;
export function markTrace(arg1: string, arg2?: string | Record<string, string>, arg3?: Record<string, string>): void {
  if (!isDev) return;

  // 重载处理
  let sessionId: string | null;
  let label: string;
  let meta: Record<string, string> | undefined;

  if (arg2 === undefined || typeof arg2 === 'object') {
    // markTrace(label, meta?) 模式
    sessionId = currentSessionId;
    label = arg1;
    meta = arg2 as Record<string, string> | undefined;
  } else {
    // markTrace(sessionId, label, meta?) 模式
    sessionId = arg1;
    label = arg2;
    meta = arg3;
  }

  if (!sessionId) return;

  const session = sessions.get(sessionId);
  if (!session) return;

  session.marks.push({ label, time: performance.now() });
  if (meta) {
    session.meta = { ...session.meta, ...meta };
  }
}

/**
 * 结束追踪并输出汇总表到控制台
 * @param sessionId 会话ID，不传则使用当前活跃会话
 */
export function endTrace(sessionId?: string | null): void {
  if (!isDev) return;

  const sid = sessionId || currentSessionId;
  if (!sid) return;

  const session = sessions.get(sid);
  if (!session) return;

  const endTime = performance.now();
  const totalDuration = endTime - session.startTime;

  // 构建汇总表
  const rows: Array<{ step: string; timestamp: string; duration: string; pct: string }> = [];
  let prevTime = session.startTime;

  for (const mark of session.marks) {
    const stepDuration = mark.time - prevTime;
    const pct = totalDuration > 0 ? `${((stepDuration / totalDuration) * 100).toFixed(1)}%` : '0%';

    rows.push({
      step: mark.label,
      timestamp: mark.time.toFixed(2),
      duration: `+${stepDuration.toFixed(2)}ms`,
      pct
    });

    prevTime = mark.time;
  }

  // 添加总计行
  rows.push({
    step: '总计',
    timestamp: endTime.toFixed(2),
    duration: `${totalDuration.toFixed(2)}ms`,
    pct: '100%'
  });

  // 控制台输出
  const c = globalThis.console;
  const tag = session.meta.tag ? ` [${session.meta.tag}]` : '';
  const functionCode = session.meta.functionCode ? ` ${session.meta.functionCode}` : '';

  c.groupCollapsed(
    `%c[性能追踪]${functionCode}${tag} 总耗时: ${totalDuration.toFixed(2)}ms`,
    'color:#1677ff;font-weight:bold;font-size:13px;'
  );

  // 输出详细表格
  c.table(rows);

  // 输出关键耗时排行
  const durations = session.marks
    .map((mark, i) => ({
      step: mark.label,
      ms: +(mark.time - (i === 0 ? session.startTime : session.marks[i - 1].time)).toFixed(2)
    }))
    .filter(item => item.ms > 0)
    .sort((a, b) => b.ms - a.ms);

  c.log('%c耗时排行（从慢到快）', 'color:#fa8c16;font-weight:bold;');
  durations.forEach((item, i) => {
    const bar = '█'.repeat(Math.min(40, Math.ceil(item.ms / 10)));
    const color = item.ms > 500 ? '#f5222d' : item.ms > 200 ? '#faad14' : '#52c41a';
    c.log(`%c  ${i + 1}. ${item.step.padEnd(20)} ${item.ms.toFixed(2).padStart(10)}ms ${bar}`, `color:${color};`);
  });

  // 输出元信息
  if (Object.keys(session.meta).length > 0) {
    c.log('%c附加信息', 'color:#722ed1;font-weight:bold;', session.meta);
  }

  c.groupEnd();

  // 清理
  sessions.delete(sid);
  if (currentSessionId === sid) {
    currentSessionId = null;
  }
}

/**
 * 获取当前活跃的 sessionId
 */
export function getCurrentTraceId(): string | null {
  return currentSessionId;
}

/**
 * 设置当前活跃的 sessionId（用于跨文件传递）
 */
export function setCurrentTraceId(id: string | null): void {
  currentSessionId = id;
}

/**
 * 绑定 sessionId 到一次异步操作
 * 在异步操作开始前调用，结束后自动恢复之前的 sessionId
 */
export function bindTraceId(id: string | null): () => void {
  const prev = currentSessionId;
  currentSessionId = id;
  return () => {
    currentSessionId = prev;
  };
}
