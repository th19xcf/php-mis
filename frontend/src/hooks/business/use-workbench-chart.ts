import { ref, watch, nextTick, computed, onActivated, onDeactivated, onMounted } from 'vue';
import * as echarts from 'echarts/core';
import { LineChart, BarChart, PieChart } from 'echarts/charts';
import { TitleComponent, TooltipComponent, LegendComponent, GridComponent, DatasetComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import type { ECharts } from 'echarts/core';
import { request } from '@/service/request';
import { useThemeStore } from '@/store/modules/theme/index';
import { logger } from '@/utils/logger';
import { generateChartOptionsFromBackend } from './use-workbench-chart-option-builder';

const _chartStateCache = new Map<string, { visible: boolean; options: any[]; data: any[] }>();

echarts.use([
  LineChart,
  BarChart,
  PieChart,
  TitleComponent,
  TooltipComponent,
  LegendComponent,
  GridComponent,
  DatasetComponent,
  CanvasRenderer
]);

interface UseWorkbenchChartOptions {
  getFunctionCode: () => string;
  notify: (type: 'success' | 'error' | 'warning' | 'info', message: string) => void;
  /** 可选：图表点击事件回调（用于图形钻取） */
  onChartClick?: (params: any) => void;
  /** 可选：钻取状态下用于查找钻取选项的接口 */
  getDrillOptionsForChart?: (sid: string) => Api.Workbench.DrillOption[] | null;
}

export function useWorkbenchChart(options: UseWorkbenchChartOptions) {
  const cacheKey = options.getFunctionCode();

  const cached = _chartStateCache.get(cacheKey);
  const chartVisible = ref(cached?.visible ?? false);
  const chartLoading = ref(false);
  const chartData = ref<any[]>(cached?.data ?? []);
  const chartOptions = ref<any[]>(cached?.options ?? []);
  const chartRefs = ref<HTMLDivElement[]>([]);
  const chartInstances = ref<ECharts[]>([]);
  const resizeHandlers = ref<(() => void)[]>([]);
  const resizeObservers = ref<ResizeObserver[]>([]);

  const themeStore = useThemeStore();
  const isDarkMode = computed(() => themeStore.darkMode);

  function saveToCache() {
    _chartStateCache.set(cacheKey, {
      visible: chartVisible.value,
      options: chartOptions.value,
      data: chartData.value
    });
  }

  function log(method: 'info' | 'warn' | 'error' | 'debug', message: string, data?: unknown) {
    const timestamp = new Date().toLocaleTimeString('zh-CN', { hour12: false });
    const prefix = `[${timestamp}] [CHART] [${method.toUpperCase()}]`;

    if (data !== undefined) {
      logger.info(`${prefix} ${message}`, data);
    } else {
      logger.info(`${prefix} ${message}`);
    }
  }

  watch(
    [chartOptions, chartData],
    () => {
      if (chartVisible.value && chartOptions.value.length > 0) {
        saveToCache();
      }
    },
    { deep: true }
  );

  onMounted(() => {
    log('info', `========== useWorkbenchChart onMounted ==========`);
    log('info',
      `恢复状态: chartVisible=${chartVisible.value}, chartOptions=${chartOptions.value.length}, chartData=${chartData.value.length}`
    );

    // 只有当 chartVisible、chartOptions 和 chartData 都有有效值时，才重新初始化
    if (chartVisible.value && chartOptions.value.length > 0 && chartData.value.length > 0) {
      nextTick(() => {
        setTimeout(() => {
          const hasValidRefs = chartRefs.value.some(el => el && el.clientWidth > 0 && el.clientHeight > 0);
          if (hasValidRefs) {
            log('info', `onMounted: 检测到完整缓存图表状态，自动重新初始化`);
            initCharts();
          } else {
            log('warn', `onMounted: DOM 尺寸无效，延迟重试`);
            let retryCount = 10;
            const tryInit = () => {
              const valid = chartRefs.value.some(el => el && el.clientWidth > 0 && el.clientHeight > 0);
              if (valid) {
                initCharts();
              } else if (retryCount-- > 0) {
                requestAnimationFrame(() => setTimeout(tryInit, 200));
              }
            };
            tryInit();
          }
        }, 200);
      });
    } else {
      log('info', `onMounted: 缓存数据不完整，不重新初始化图表`);
      // 如果缓存数据不完整，确保关闭图表显示
      if (chartVisible.value) {
        chartVisible.value = false;
        saveToCache();
        log('info', `onMounted: 强制关闭 chartVisible`);
      }
    }
  });

  function disposeAllCharts() {
    chartInstances.value.forEach(instance => {
      if (instance) {
        instance.dispose();
      }
    });
    chartInstances.value = [];
    resizeHandlers.value.forEach(handler => {
      window.removeEventListener('resize', handler);
    });
    resizeHandlers.value = [];
    resizeObservers.value.forEach(observer => {
      observer.disconnect();
    });
    resizeObservers.value = [];
  }

  /**
   * 安全调用 ECharts 实例的 resize
   * - 避免在实例已 dispose 后调用导致 [ECharts] Instance ... has been disposed 警告
   * - 用于 setTimeout / window resize / ResizeObserver 异步回调
   */
  function safeResize(instance: ECharts | null | undefined): void {
    if (!instance) return;
    try {
      if (typeof instance.isDisposed === 'function' && instance.isDisposed()) {
        return;
      }
      instance.resize();
    } catch (e) {
      log('warn', `safeResize 异常（实例可能已被销毁）:`, e);
    }
  }

  async function handleOpenChart(pageMeta: Api.Workbench.PageMeta | null) {
    log('info', `========== handleOpenChart 开始 ==========`);

    if (!pageMeta?.chartModule) {
      const warningMsg = '图形功能未配置';
      log('warn', warningMsg);
      options.notify('warning', warningMsg);
      log('info', `========== handleOpenChart 结束（未配置）==========`);
      return;
    }

    log('info', `chartModule: ${pageMeta.chartModule}`);
    chartVisible.value = true;
    chartLoading.value = true;
    saveToCache();
    log('debug', `设置 chartVisible=true, chartLoading=true`);

    try {
      const functionCode = options.getFunctionCode();
      log('info', `functionCode: "${functionCode}"`);
      log('info', `发起请求: /workbench/chart/${functionCode}`);

      const { data, error } = await request({
        url: `/workbench/chart/${functionCode}`
      });

      if (error) {
        const errorMsg = '获取图形数据失败';
        log('error', `${errorMsg}:`, error);
        options.notify('error', errorMsg);
        chartLoading.value = false;
        log('info', `========== handleOpenChart 结束（请求失败）==========`);
        return;
      }

      if (!data?.charts || data.charts.length === 0) {
        const warningMsg = '图形数据为空';
        log('warn', warningMsg);
        options.notify('warning', warningMsg);
        chartLoading.value = false;
        log('info', `========== handleOpenChart 结束（数据为空）==========`);
        return;
      }

      chartData.value = data.charts;
      log('info', `获取到 ${data.charts.length} 个图形配置`);

      const errorCharts = data.charts.filter((chart: any) => chart['错误']);
      if (errorCharts.length > 0) {
        const errorMsg = `图形查询错误: ${errorCharts[0]['错误']}`;
        log('error', errorMsg);
        log('error', `SQL: ${errorCharts[0]['SQL']}`);
        options.notify('error', errorMsg);
        chartLoading.value = false;
        log('info', `========== handleOpenChart 结束（查询错误）==========`);
        return;
      }

      log('info', `生成图表配置`);
      chartOptions.value = generateChartOptionsFromBackend(data.charts, isDarkMode.value);
      log('info', `图表配置生成成功，共 ${chartOptions.value.length} 个图表`);

      // 延迟初始化图表，确保 DOM 已更新且容器有正确尺寸
      await nextTick();
      let retryCount = 20;
      const tryInit = () => {
        const hasValidRefs = chartRefs.value.some(el => el && el.clientWidth > 0 && el.clientHeight > 0);
        if (hasValidRefs) {
          initCharts();
        } else if (retryCount-- > 0) {
          log('warn', `DOM 尺寸无效，延迟 200ms 重试... 剩余重试次数: ${retryCount}`);
          setTimeout(tryInit, 200);
        } else {
          log('error', `DOM 尺寸始终无效，放弃初始化`);
        }
      };
      tryInit();

      log('info', `========== handleOpenChart 结束（成功）==========`);
    } catch (error) {
      const errorMsg = '加载图形失败';
      log('error', `${errorMsg}:`, error);
      options.notify('error', errorMsg);
      log('info', `========== handleOpenChart 结束（异常）==========`);
    } finally {
      chartLoading.value = false;
      log('debug', `设置 chartLoading=false`);
    }
  }

  function initCharts() {
    log('info', `========== initCharts 开始 ==========`);

    if (chartRefs.value.length === 0 || chartOptions.value.length === 0) {
      log('warn', `chartRefs 或 chartOptions 为空，跳过初始化`);
      return;
    }

    disposeAllCharts();

    chartRefs.value.forEach((chartRef, index) => {
      if (!chartRef) {
        log('warn', `图表容器 ${index} 为空`);
        return;
      }

      const option = chartOptions.value[index];
      if (!option || option.error) {
        const errMsg = option?.error
          ? `错误=${option.error}`
          : 'option 为 undefined（chartOptions 长度 < chartRefs 长度，可能为 drill 后布局切换）';
        log('warn', `图表配置 ${index} ${errMsg}`);
        if (option?.error) {
          log('warn', `图表配置 ${index} 完整内容:`, option);
        }
        return;
      }

      log('info', `初始化图表 ${index + 1}，布局: ${option.chartLayout || 'box_1-1-1'}`);
      const chartInstance = echarts.init(chartRef);
      chartInstance.setOption(option);
      chartInstances.value[index] = chartInstance;

      // 绑定图表点击事件 - 用于图形钻取
      if (options.onChartClick) {
        chartInstance.on('click', params => {
          log('debug', `图表点击事件触发, chartIndex=${index}`);
          try {
            options.onChartClick?.(params);
          } catch (e) {
            log('error', `图表点击回调异常:`, e);
          }
        });
      }

      // 延迟调用 resize，确保容器尺寸正确
      // 注意：setTimeout 内仍可能引用已 dispose 的实例（例如用户快速钻取时
      // disposeAllCharts 发生在 setTimeout 触发之前），必须用 isDisposed() 兜底
      setTimeout(() => {
        safeResize(chartInstance);
      }, 100);

      const resizeHandler = () => {
        safeResize(chartInstance);
      };
      window.addEventListener('resize', resizeHandler);
      resizeHandlers.value[index] = resizeHandler;

      const resizeObserver = new ResizeObserver(() => {
        safeResize(chartInstance);
      });
      resizeObserver.observe(chartRef);
      resizeObservers.value[index] = resizeObserver;
    });

    log('info', `========== initCharts 结束 ==========`);
  }

  function setChartRef(el: HTMLDivElement | null, index: number) {
    if (el) {
      chartRefs.value[index] = el;
    }
  }

  watch(chartVisible, async visible => {
    log('info', `chartVisible 变化: ${visible}`);

    if (visible && chartOptions.value.length > 0) {
      log('info', `开始初始化图表`);
      await nextTick();
      setTimeout(() => {
        const hasValidRefs = chartRefs.value.some(el => el && el.clientWidth > 0 && el.clientHeight > 0);
        if (hasValidRefs) {
          initCharts();
        } else {
          log('warn', `DOM 尺寸无效，延迟 300ms 重试...`);
          setTimeout(() => {
            const validRefs = chartRefs.value.some(el => el && el.clientWidth > 0 && el.clientHeight > 0);
            if (validRefs) {
              initCharts();
            }
          }, 300);
        }
      }, 200);
    } else if (!visible) {
      disposeAllCharts();
      // 关闭图形时，清除缓存的数据和选项，避免切换标签页后重新显示
      chartData.value = [];
      chartOptions.value = [];
      saveToCache();
    }
  });

  watch(chartOptions, async chartOpts => {
    if (chartOpts.length > 0 && chartVisible.value && chartInstances.value.length === 0) {
      await nextTick();
      setTimeout(() => {
        const hasValidRefs = chartRefs.value.some(el => el && el.clientWidth > 0 && el.clientHeight > 0);
        if (hasValidRefs) {
          initCharts();
        } else {
          setTimeout(() => {
            const validRefs = chartRefs.value.some(el => el && el.clientWidth > 0 && el.clientHeight > 0);
            if (validRefs) {
              initCharts();
            }
          }, 300);
        }
      }, 200);
    }
  });

  function resizeChart() {
    chartInstances.value.forEach(instance => {
      instance?.resize();
    });
  }

  watch(isDarkMode, async darkMode => {
    log('info', `主题变化: ${darkMode ? 'dark' : 'light'}`);

    if (chartVisible.value && chartData.value.length > 0) {
      log('info', `重新生成图表配置以适配主题`);
      const newOptions = generateChartOptionsFromBackend(chartData.value, darkMode);

      // 修复：setOption(option, true) 不会完全清空 ECharts 内部状态，
      // 特别是 extraCssText / backgroundColor / borderColor 等样式字段
      // 会残留旧主题的值，导致切换主题后 tooltip 看不见。
      // 改为销毁现有实例、走 watch(chartOptions) 的完整重建流程。
      disposeAllCharts();
      chartOptions.value = newOptions;
    }
  });

  onActivated(() => {
    log('info', `========== onActivated ==========`);
    log('info',
      `chartVisible: ${chartVisible.value}, chartOptions: ${chartOptions.value.length}, instances: ${chartInstances.value.length}, refs: ${chartRefs.value.filter(r => r).length}`
    );

    if (chartVisible.value && chartOptions.value.length > 0) {
      const tryResize = (retryCount: number) => {
        const validRefs = chartRefs.value.filter(el => el && el.clientWidth > 0 && el.clientHeight > 0);
        log('debug',
          `onActivated resize 重试 ${retryCount}, validRefs: ${validRefs.length}, instances: ${chartInstances.value.length}`
        );

        if (validRefs.length >= chartOptions.value.length) {
          if (chartInstances.value.length > 0) {
            chartInstances.value.forEach((instance, index) => {
              if (instance && validRefs[index]) {
                log('info', `重新渲染图表 ${index}`);
                const option = chartOptions.value[index];
                if (option) {
                  instance.setOption(option, true);
                  instance.resize();
                }
              }
            });
          } else {
            chartRefs.value.forEach((chartRef, index) => {
              if (!chartRef) return;
              const option = chartOptions.value[index];
              if (!option || option.error) return;

              log('info', `onActivated 创建新图表 ${index}`);
              const chartInstance = echarts.init(chartRef);
              chartInstance.setOption(option);
              chartInstances.value[index] = chartInstance;

              const resizeHandler = () => safeResize(chartInstance);
              window.addEventListener('resize', resizeHandler);
              resizeHandlers.value[index] = resizeHandler;

              const observer = new ResizeObserver(() => safeResize(chartInstance));
              observer.observe(chartRef);
              resizeObservers.value[index] = observer;
            });
          }
        } else if (retryCount > 0) {
          requestAnimationFrame(() => {
            setTimeout(() => tryResize(retryCount - 1), 200);
          });
        } else {
          log('warn', `onActivated: 重试结束仍无法初始化`);
        }
      };
      tryResize(15);
    }
  });

  onDeactivated(() => {
    log('info', `========== onDeactivated ==========`);
  });

  /**
   * 钻取后用新的图表数据替换当前图表
   * 由 useWorkbenchChartDrill 在收到后端钻取响应后调用
   */
  async function reloadChartsFromDrill(charts: any[]) {
    log('info', `========== reloadChartsFromDrill 开始 ==========`);
    log('info', `收到 ${charts.length} 个钻取图表`);

    if (!charts || charts.length === 0) {
      log('warn', `钻取图表数据为空`);
      return;
    }

    // 先把旧图表实例全部销毁并清空 refs，避免重渲染时残留
    disposeAllCharts();
    chartRefs.value = [];
    chartInstances.value = [];
    resizeHandlers.value = [];
    resizeObservers.value.forEach(o => o.disconnect());
    resizeObservers.value = [];

    // 更新图表数据
    chartData.value = charts;
    chartOptions.value = generateChartOptionsFromBackend(charts, isDarkMode.value);

    log('info', `钻取图表配置生成完成, 共 ${chartOptions.value.length} 个`);

    // 等待 DOM 更新后重新初始化
    await nextTick();
    setTimeout(() => {
      const hasValidRefs = chartRefs.value.some(el => el && el.clientWidth > 0 && el.clientHeight > 0);
      if (hasValidRefs) {
        initCharts();
      } else {
        let retryCount = 10;
        const tryInit = () => {
          const valid = chartRefs.value.some(el => el && el.clientWidth > 0 && el.clientHeight > 0);
          if (valid) {
            initCharts();
          } else if (retryCount-- > 0) {
            requestAnimationFrame(() => setTimeout(tryInit, 200));
          }
        };
        tryInit();
      }
    }, 200);

    log('info', `========== reloadChartsFromDrill 结束 ==========`);
  }

  return {
    chartVisible,
    chartLoading,
    chartData,
    chartOptions,
    setChartRef,
    handleOpenChart,
    resizeChart,
    /** 钻取后用新图表数据重新渲染 */
    reloadChartsFromDrill
  };
}
