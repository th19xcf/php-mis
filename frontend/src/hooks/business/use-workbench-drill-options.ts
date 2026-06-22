import { ref, watch, type Ref } from 'vue';
import { useRoute } from 'vue-router';
import { fetchWorkbenchDrill } from '@/service/api/workbench';

interface UseWorkbenchDrillOptionsOptions {
  chartData: Ref<any[]>;
}

/**
 * 工作台钻取选项缓存
 *  - 缓存按 functionCode（来自 route）隔离
 *  - 同一 functionCode 多次调用复用同一份缓存与同一份进行中的 Promise
 *  - watch chartData，首次拿到图形且未带图表级钻取选项时，预热页面级选项
 *  - watch functionCode 切换：清空旧缓存
 *
 * 与 useWorkbenchChartDrill 配套使用：图表点击时通过 getOptionsForChart(sid) 拿钻取选项。
 */
export function useWorkbenchDrillOptions(options: UseWorkbenchDrillOptionsOptions) {
  const route = useRoute();
  const drillOptionsCache = ref<Api.Workbench.DrillOption[] | null>(null);
  let loadPromise: Promise<Api.Workbench.DrillOption[] | null> | null = null;
  const loading = ref(false);

  function getCurrentFunctionCode(): string {
    return String(route.query.functionCode || route.meta?.functionCode || '');
  }

  async function load(force = false): Promise<Api.Workbench.DrillOption[] | null> {
    if (!force && drillOptionsCache.value !== null) {
      return drillOptionsCache.value;
    }
    if (loadPromise) {
      return loadPromise;
    }

    const functionCode = getCurrentFunctionCode();
    if (!functionCode) {
      return null;
    }

    loading.value = true;
    loadPromise = (async () => {
      try {
        const { data, error } = await fetchWorkbenchDrill(functionCode, {});
        if (error || !data) {
          drillOptionsCache.value = null;
          return null;
        }
        const opts = (data.options as Api.Workbench.DrillOption[]) || [];
        drillOptionsCache.value = opts;
        return opts;
      } catch {
        drillOptionsCache.value = null;
        return null;
      } finally {
        loadPromise = null;
        loading.value = false;
      }
    })();

    return loadPromise;
  }

  function getOptionsForChart(sid: string): Api.Workbench.DrillOption[] | null {
    if (!sid) return null;
    const [chartModule, chartCode] = sid.split('^');
    if (!chartCode) return null;

    // 1) 优先：dataItem.图形模块 + 图形编号 同时匹配
    let chart = options.chartData.value.find((c: any) => c['图形模块'] === chartModule && c['图形编号'] === chartCode);

    // 2) 兜底：仅按 图形编号 匹配
    if (!chart) {
      chart = options.chartData.value.find((c: any) => c['图形编号'] === chartCode);
    }

    if (!chart) {
      console.warn(
        `[CHART-DRILL] 未找到图表: SID=${sid} 解析 图形模块=${chartModule} 图形编号=${chartCode}, chartData.length=${options.chartData.value.length}`
      );
      console.warn(
        `[CHART-DRILL] chartData 图形模块列表: ${options.chartData.value.map((c: any) => c['图形模块']).join(', ')}`
      );
      return null;
    }

    const opts = (chart['钻取选项'] as Api.Workbench.DrillOption[]) || [];
    console.info(
      `[CHART-DRILL] 图表 图形模块=${chart['图形模块']} 图形编号=${chart['图形编号']} 钻取模块=${chart['钻取模块'] ?? '<空>'} 钻取选项数=${opts.length}`
    );
    if (opts.length === 0) {
      console.info(`[CHART-DRILL] 图表字段列表: ${Object.keys(chart).join(', ')}`);
    }
    // 只有图表级钻取选项存在时才返回；
    // 不使用页面级钻取选项作为兜底——表格级钻取（def_drill_config）用于表格行跳转，
    // 与图形钻取（def_chart_drill_config）是不同的配置体系，混用会导致弹出错误的钻取条件。
    if (opts.length > 0) return opts;
    return null;
  }

  // 首次拿到图形时，若所有图表都未带图表级钻取选项，触发页面级预热
  watch(
    options.chartData,
    async data => {
      if (!data || data.length === 0) return;
      const hasOwnOptions = data.some((c: any) => Array.isArray(c['钻取选项']) && c['钻取选项'].length > 0);
      if (!hasOwnOptions) {
        await load();
      }
    },
    { immediate: true }
  );

  // 切换 functionCode 时清空旧缓存
  watch(
    () => getCurrentFunctionCode(),
    (newCode, oldCode) => {
      if (newCode !== oldCode) {
        drillOptionsCache.value = null;
        loadPromise = null;
      }
    }
  );

  return {
    options: drillOptionsCache,
    loading,
    load,
    getOptionsForChart
  };
}
