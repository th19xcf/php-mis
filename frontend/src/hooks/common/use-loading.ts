import { computed, reactive, ref, type ComputedRef, type Ref } from 'vue';
import { $t } from '@/locales';

export interface UseLoadingReturn {
  /** 任意 key 处于 loading 时为 true */
  loading: ComputedRef<boolean>;
  /** 全局 loading 是否显示（默认 follow loading.value） */
  visible: ComputedRef<boolean>;
  /** loading 文案 */
  text: Ref<string>;
  /** 指定 key 是否 loading */
  isLoading: (key?: string) => boolean;
  /** 开启指定 key 的 loading（默认 key 为 default） */
  start: (key?: string) => void;
  /** 关闭指定 key 的 loading */
  stop: (key?: string) => void;
  /** 重置所有 key */
  reset: () => void;
  /**
   * 包裹异步函数，自动 start/stop
   * @example const save = loading.run(async () => await api.save())
   */
  run: <T>(fn: () => Promise<T>, key?: string) => Promise<T>;
  /**
   * 包裹异步函数并联动 window.$loadingBar
   */
  runWithBar: <T>(fn: () => Promise<T>, barText?: string) => Promise<T>;
}

/**
 * 统一 loading 状态。
 *
 * - 支持多 key 并行（按 key 维度独立计数）；
 * - start/stop 必须配对，多个 start 共享一个 key 时会等到对应数量 stop 后才真正结束；
 * - run() 自动 start/stop，异常时也会 stop；
 * - runWithBar() 额外联动全局 $loadingBar，用于跨页面的"保存中"指示。
 */
export function useLoading(): UseLoadingReturn {
  const counters = reactive<Record<string, number>>({});
  const text = ref<string>($t('common.loading'));

  function isLoading(key: string = 'default'): boolean {
    return (counters[key] ?? 0) > 0;
  }

  function start(key: string = 'default'): void {
    counters[key] = (counters[key] ?? 0) + 1;
  }

  function stop(key: string = 'default'): void {
    if (!counters[key]) return;
    counters[key] -= 1;
    if (counters[key] <= 0) {
      delete counters[key];
    }
  }

  function reset(): void {
    for (const k of Object.keys(counters)) {
      delete counters[k];
    }
  }

  async function run<T>(fn: () => Promise<T>, key: string = 'default'): Promise<T> {
    start(key);
    try {
      return await fn();
    } finally {
      stop(key);
    }
  }

  async function runWithBar<T>(fn: () => Promise<T>, barText?: string): Promise<T> {
    const prev = text.value;
    if (barText) text.value = barText;
    window.$loadingBar?.start();
    try {
      return await run(fn, 'default');
    } finally {
      window.$loadingBar?.finish();
      text.value = prev;
    }
  }

  const loading = computed(() => Object.keys(counters).length > 0);
  const visible = computed(() => loading.value);

  return {
    loading,
    visible,
    text,
    isLoading,
    start,
    stop,
    reset,
    run,
    runWithBar
  };
}
