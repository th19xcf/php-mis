import { ref, watch, nextTick, computed, onActivated, onDeactivated, onMounted } from 'vue';
import * as echarts from 'echarts/core';
import { LineChart, BarChart, PieChart } from 'echarts/charts';
import { TitleComponent, TooltipComponent, LegendComponent, GridComponent, DatasetComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import type { ECharts } from 'echarts/core';
import { request } from '@/service/request';
import { WORKBENCH_CONFIG } from '@/config/workbench';
import { useThemeStore } from '@/store/modules/theme/index';

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

const darkThemeColors = {
  backgroundColor: 'transparent',
  textColor: '#e5e7eb',
  axisLineColor: '#4b5563',
  splitLineColor: 'rgba(255, 255, 255, 0.08)',
  legendTextColor: '#d1d5db',
  tooltipBgColor: 'rgba(31, 41, 55, 0.95)',
  tooltipTextColor: '#e5e7eb',
  tooltipBorderColor: '#4b5563'
};

const lightThemeColors = {
  backgroundColor: '#ffffff',
  textColor: '#1f2937',
  axisLineColor: '#6b7280',
  splitLineColor: '#d1d5db',
  legendTextColor: '#374151',
  tooltipBgColor: '#ffffff',
  tooltipTextColor: '#1f2937',
  tooltipBorderColor: '#e5e7eb'
};

function generateChartOption(chart: any, isDarkMode: boolean): any {
  const { CHART } = WORKBENCH_CONFIG;
  const chartData = chart['数据'] || [];
  const chartType = chart['图形类型'] || CHART.DEFAULT_TYPE;
  const chartName = chart['图形名称'] || CHART.DEFAULT_NAME;
  const fieldsConfig = chart['字段'] || {};
  const themeColors = isDarkMode ? darkThemeColors : lightThemeColors;

  if (chartData.length === 0) {
    return null;
  }

  if (chartType === 'pie') {
    const dataKeys = Object.keys(chartData[0]);
    const categoryKey = dataKeys[0];
    const valueKeys = dataKeys.slice(1).filter(key => {
      const val = chartData[0][key];
      return typeof val === 'number' || (typeof val === 'string' && !isNaN(Number(val)) && val !== '');
    });

    const pieData = (chartData as Record<string, any>[]).map((item: Record<string, any>) => ({
      name: item[categoryKey],
      value: Number(item[valueKeys[0]]) || 0,
      // 保留后端返回的所有原始字段（含 SID），用于图形钻取
      ...item
    }));

    return {
      backgroundColor: themeColors.backgroundColor,
      title: {
        text: chartName,
        left: 'center',
        textStyle: {
          color: themeColors.textColor
        }
      },
      tooltip: {
        trigger: 'item',
        formatter: '{a} <br/>{b}: {c} ({d}%)'
      },
      legend: {
        orient: 'vertical',
        left: 'left',
        textStyle: {
          color: themeColors.legendTextColor || themeColors.textColor
        }
      },
      series: [
        {
          name: chartName,
          type: 'pie',
          radius: '50%',
          data: pieData,
          emphasis: {
            itemStyle: {
              shadowBlur: 10,
              shadowOffsetX: 0,
              shadowColor: 'rgba(0, 0, 0, 0.5)'
            }
          }
        }
      ]
    };
  } else {
    const dem: any[] = [];
    const yAxis: any[] = [];
    let yLeft = false;
    let yRight = false;

    const parseValue = (val: any) => {
      if (typeof val === 'number') {
        return val;
      }
      if (typeof val === 'string') {
        const cleanVal = val.replace(/[%，,]/g, '');
        const num = Number(cleanVal);
        return isNaN(num) ? 0 : num;
      }
      return 0;
    };

    const dataKeys = Object.keys(chartData[0]);
    const categoryKey =
      dataKeys.find(key => {
        const val = chartData[0][key];
        if (typeof val === 'string') {
          const cleanVal = val.replace(/[%，,]/g, '');
          return isNaN(Number(cleanVal)) || cleanVal === '';
        }
        return false;
      }) || dataKeys[0];

    // 获取配置的字段名列表
    const configuredFieldNames = Object.keys(fieldsConfig);

    const valueKeys = dataKeys
      .filter(key => key !== categoryKey)
      .filter(key => key !== '图形编号')
      .filter(key => {
        // 如果有字段配置，只显示配置中的字段
        if (configuredFieldNames.length > 0) {
          return configuredFieldNames.includes(key);
        }
        // 如果没有字段配置，按原来的逻辑处理
        const val = chartData[0][key];
        if (typeof val === 'number') {
          return true;
        }
        if (typeof val === 'string') {
          const cleanVal = val.replace(/[%，,]/g, '');
          return !isNaN(Number(cleanVal)) && cleanVal !== '';
        }
        return false;
      });

    const xAxisData = (chartData as Record<string, any>[]).map(item => item[categoryKey]);

    const { AXIS_POSITION, CHART_TYPE } = WORKBENCH_CONFIG.CHART;

    for (const key of valueKeys) {
      const fieldConfig = fieldsConfig[key];
      const axisPosition = fieldConfig?.['坐标轴'] || AXIS_POSITION.LEFT;
      const fieldChartType = fieldConfig?.['图形类型'] || chartType;

      if (axisPosition === AXIS_POSITION.LEFT && !yLeft) {
        yAxis.push({ type: 'value', position: 'left' });
        yLeft = true;
      } else if (axisPosition === AXIS_POSITION.RIGHT && !yRight) {
        yAxis.push({
          type: 'value',
          position: 'right',
          axisLabel: { formatter: '{value}%' }
        });
        yRight = true;
      }

      const seriesItem: any = {
        name: key,
        type: fieldChartType === CHART_TYPE.BAR ? 'bar' : 'line',
        // 使用对象数组而非纯数值，以保留 SID 等原始字段供图形钻取
        data: (chartData as Record<string, any>[]).map((item: Record<string, any>) => ({
          ...item,
          // 显式覆盖 value 为标准化后的数值，避免字符串带逗号等情况
          value: parseValue(item[key])
        }))
      };

      if (fieldChartType !== CHART_TYPE.BAR) {
        seriesItem.smooth = true;
      }

      if (yAxis.length > 1) {
        seriesItem.yAxisIndex = axisPosition === AXIS_POSITION.RIGHT ? 1 : 0;
      }

      dem.push(seriesItem);
    }

    return {
      backgroundColor: themeColors.backgroundColor,
      title: {
        show: true,
        text: chartName,
        triggerEvent: true,
        textStyle: {
          color: themeColors.textColor
        }
      },
      legend: {
        bottom: 2,
        data: valueKeys,
        textStyle: {
          color: themeColors.legendTextColor || themeColors.textColor
        }
      },
      tooltip: {
        show: true,
        trigger: 'axis',
        axisPointer: {
          type: 'cross',
          crossStyle: {
            color: '#999'
          }
        },
        backgroundColor: themeColors.tooltipBgColor || 'rgba(255, 255, 255, 0.95)',
        textStyle: {
          color: themeColors.tooltipTextColor || '#1f2937'
        },
        borderColor: themeColors.tooltipBorderColor || '#e5e7eb',
        borderWidth: 1,
        padding: [10, 15],
        formatter: function (params: any) {
          let result = `<div style="font-weight: bold; margin-bottom: 8px;">${params[0].axisValue}</div>`;
          params.forEach((item: any) => {
            result += `<div style="display: flex; justify-content: space-between; margin: 4px 0;">
              <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: ${item.color}; margin-right: 8px;"></span>
              <span style="flex: 1;">${item.seriesName}</span>
              <span style="margin-left: 16px; font-weight: bold;">${item.value}</span>
            </div>`;
          });
          return result;
        }
      },
      toolbox: {
        feature: {
          dataview: { show: true },
          magicType: { show: true, type: ['line', 'bar', 'stack'] },
          restore: { show: true },
          saveAsImage: { show: true }
        }
      },
      dataset: {
        source: chartData
      },
      xAxis: {
        type: 'category',
        data: xAxisData,
        axisLine: {
          lineStyle: {
            color: themeColors.axisLineColor
          }
        },
        axisLabel: {
          color: themeColors.textColor
        },
        splitLine: {
          lineStyle: {
            color: themeColors.splitLineColor
          }
        }
      },
      yAxis:
        yAxis.length > 1
          ? yAxis.map((axis: any) => ({
              ...axis,
              axisLine: {
                lineStyle: {
                  color: themeColors.axisLineColor
                }
              },
              axisLabel: {
                color: themeColors.textColor
              },
              splitLine: {
                lineStyle: {
                  color: themeColors.splitLineColor
                }
              }
            }))
          : {
              ...yAxis[0],
              axisLine: {
                lineStyle: {
                  color: themeColors.axisLineColor
                }
              },
              axisLabel: {
                color: themeColors.textColor
              },
              splitLine: {
                lineStyle: {
                  color: themeColors.splitLineColor
                }
              }
            },
      series: dem,
      grid: {
        left: '2%',
        right: yRight ? '2%' : '2%',
        bottom: '10%',
        top: 80,
        containLabel: true
      }
    };
  }
}

function generateChartOptionsFromBackend(charts: any[], isDarkMode: boolean): any[] {
  if (!charts || charts.length === 0) {
    return [];
  }

  const options: any[] = [];
  for (const chart of charts) {
    if (chart['错误']) {
      options.push({ error: chart['错误'], SQL: chart['SQL'] });
    } else {
      const option = generateChartOption(chart, isDarkMode);
      if (option) {
        // 确保布局名称有 box_ 前缀
        let layout = chart['页面布局'] || 'box_1-1-1';
        if (!layout.startsWith('box_')) {
          layout = 'box_' + layout;
        }
        options.push({
          ...option,
          chartLayout: layout,
          chartCode: chart['图形编号'] || '',
          chartModule: chart['图形模块'] || '',
          drillModule: chart['钻取模块'] || ''
        });
      }
    }
  }
  return options;
}

/**
 * 构建"按图表标识(SID)索引的钻取选项"映射
 * 用于前端图形钻取：根据用户点击的数据点 SID 找到该图表可用的钻取选项
 *
 * @param charts 来自后端的原始 charts 数组
 * @param getDrillOptions 钻取选项获取函数（按钻取模块查找）
 * @returns Record<SID, DrillOption[]>
 */
export function buildChartDrillOptionMap(
  charts: any[],
  getDrillOptions: (drillModule: string) => Promise<Api.Workbench.DrillOption[] | null>
): Record<string, Api.Workbench.DrillOption[]> {
  const map: Record<string, Api.Workbench.DrillOption[]> = {};

  if (!charts || charts.length === 0) {
    return map;
  }

  // 收集所有图表的钻取模块（去重）
  const moduleToSid: Record<string, Set<string>> = {};
  for (const chart of charts) {
    const module = chart['钻取模块'];
    const code = chart['图形编号'];
    const moduleCode = chart['图形模块'];
    if (!module || !code || !moduleCode) continue;
    const sid = `${moduleCode}^${code}`;
    if (!moduleToSid[module]) {
      moduleToSid[module] = new Set();
    }
    moduleToSid[module].add(sid);
  }

  // 同步加载所有钻取选项
  const promises: Promise<void>[] = [];
  for (const [module, sids] of Object.entries(moduleToSid)) {
    promises.push(
      (async () => {
        const opts = await getDrillOptions(module);
        if (opts && opts.length > 0) {
          for (const sid of sids) {
            map[sid] = opts;
          }
        }
      })()
    );
  }

  // 注意：该函数在调用方应配合 await 一起使用；
  // 但为兼容同步调用场景，返回空 map 并通过 attachDrillOptions 异步填充
  return map;
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

  function logger(method: 'info' | 'warn' | 'error' | 'debug', message: string, data?: unknown) {
    const timestamp = new Date().toLocaleTimeString('zh-CN', { hour12: false });
    const prefix = `[${timestamp}] [CHART] [${method.toUpperCase()}]`;

    if (data !== undefined) {
      console.log(`${prefix} ${message}`, data);
    } else {
      console.log(`${prefix} ${message}`);
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
    logger('info', `========== useWorkbenchChart onMounted ==========`);
    logger(
      'info',
      `恢复状态: chartVisible=${chartVisible.value}, chartOptions=${chartOptions.value.length}, chartData=${chartData.value.length}`
    );

    // 只有当 chartVisible、chartOptions 和 chartData 都有有效值时，才重新初始化
    if (chartVisible.value && chartOptions.value.length > 0 && chartData.value.length > 0) {
      nextTick(() => {
        setTimeout(() => {
          const hasValidRefs = chartRefs.value.some(el => el && el.clientWidth > 0 && el.clientHeight > 0);
          if (hasValidRefs) {
            logger('info', `onMounted: 检测到完整缓存图表状态，自动重新初始化`);
            initCharts();
          } else {
            logger('warn', `onMounted: DOM 尺寸无效，延迟重试`);
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
      logger('info', `onMounted: 缓存数据不完整，不重新初始化图表`);
      // 如果缓存数据不完整，确保关闭图表显示
      if (chartVisible.value) {
        chartVisible.value = false;
        saveToCache();
        logger('info', `onMounted: 强制关闭 chartVisible`);
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
      logger('warn', `safeResize 异常（实例可能已被销毁）:`, e);
    }
  }

  async function handleOpenChart(pageMeta: Api.Workbench.PageMeta | null) {
    logger('info', `========== handleOpenChart 开始 ==========`);

    if (!pageMeta?.chartModule) {
      const warningMsg = '图形功能未配置';
      logger('warn', warningMsg);
      options.notify('warning', warningMsg);
      logger('info', `========== handleOpenChart 结束（未配置）==========`);
      return;
    }

    logger('info', `chartModule: ${pageMeta.chartModule}`);
    chartVisible.value = true;
    chartLoading.value = true;
    saveToCache();
    logger('debug', `设置 chartVisible=true, chartLoading=true`);

    try {
      const functionCode = options.getFunctionCode();
      logger('info', `functionCode: "${functionCode}"`);
      logger('info', `发起请求: /workbench/chart/${functionCode}`);

      const { data, error } = await request({
        url: `/workbench/chart/${functionCode}`
      });

      if (error) {
        const errorMsg = '获取图形数据失败';
        logger('error', `${errorMsg}:`, error);
        options.notify('error', errorMsg);
        chartLoading.value = false;
        logger('info', `========== handleOpenChart 结束（请求失败）==========`);
        return;
      }

      if (!data?.charts || data.charts.length === 0) {
        const warningMsg = '图形数据为空';
        logger('warn', warningMsg);
        options.notify('warning', warningMsg);
        chartLoading.value = false;
        logger('info', `========== handleOpenChart 结束（数据为空）==========`);
        return;
      }

      chartData.value = data.charts;
      logger('info', `获取到 ${data.charts.length} 个图形配置`);

      const errorCharts = data.charts.filter((chart: any) => chart['错误']);
      if (errorCharts.length > 0) {
        const errorMsg = `图形查询错误: ${errorCharts[0]['错误']}`;
        logger('error', errorMsg);
        logger('error', `SQL: ${errorCharts[0]['SQL']}`);
        options.notify('error', errorMsg);
        chartLoading.value = false;
        logger('info', `========== handleOpenChart 结束（查询错误）==========`);
        return;
      }

      logger('info', `生成图表配置`);
      chartOptions.value = generateChartOptionsFromBackend(data.charts, isDarkMode.value);
      logger('info', `图表配置生成成功，共 ${chartOptions.value.length} 个图表`);

      // 延迟初始化图表，确保 DOM 已更新且容器有正确尺寸
      await nextTick();
      let retryCount = 20;
      const tryInit = () => {
        const hasValidRefs = chartRefs.value.some(el => el && el.clientWidth > 0 && el.clientHeight > 0);
        if (hasValidRefs) {
          initCharts();
        } else if (retryCount-- > 0) {
          logger('warn', `DOM 尺寸无效，延迟 200ms 重试... 剩余重试次数: ${retryCount}`);
          setTimeout(tryInit, 200);
        } else {
          logger('error', `DOM 尺寸始终无效，放弃初始化`);
        }
      };
      tryInit();

      logger('info', `========== handleOpenChart 结束（成功）==========`);
    } catch (error) {
      const errorMsg = '加载图形失败';
      logger('error', `${errorMsg}:`, error);
      options.notify('error', errorMsg);
      logger('info', `========== handleOpenChart 结束（异常）==========`);
    } finally {
      chartLoading.value = false;
      logger('debug', `设置 chartLoading=false`);
    }
  }

  function initCharts() {
    logger('info', `========== initCharts 开始 ==========`);

    if (chartRefs.value.length === 0 || chartOptions.value.length === 0) {
      logger('warn', `chartRefs 或 chartOptions 为空，跳过初始化`);
      return;
    }

    disposeAllCharts();

    chartRefs.value.forEach((chartRef, index) => {
      if (!chartRef) {
        logger('warn', `图表容器 ${index} 为空`);
        return;
      }

      const option = chartOptions.value[index];
      if (!option || option.error) {
        const errMsg = option?.error
          ? `错误=${option.error}`
          : 'option 为 undefined（chartOptions 长度 < chartRefs 长度，可能为 drill 后布局切换）';
        logger('warn', `图表配置 ${index} ${errMsg}`);
        if (option?.error) {
          logger('warn', `图表配置 ${index} 完整内容:`, option);
        }
        return;
      }

      logger('info', `初始化图表 ${index + 1}，布局: ${option.chartLayout || 'box_1-1-1'}`);
      const chartInstance = echarts.init(chartRef);
      chartInstance.setOption(option);
      chartInstances.value[index] = chartInstance;

      // 绑定图表点击事件 - 用于图形钻取
      if (options.onChartClick) {
        chartInstance.on('click', params => {
          logger('debug', `图表点击事件触发, chartIndex=${index}`);
          try {
            options.onChartClick?.(params);
          } catch (e) {
            logger('error', `图表点击回调异常:`, e);
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

    logger('info', `========== initCharts 结束 ==========`);
  }

  function setChartRef(el: HTMLDivElement | null, index: number) {
    if (el) {
      chartRefs.value[index] = el;
    }
  }

  watch(chartVisible, async visible => {
    logger('info', `chartVisible 变化: ${visible}`);

    if (visible && chartOptions.value.length > 0) {
      logger('info', `开始初始化图表`);
      await nextTick();
      setTimeout(() => {
        const hasValidRefs = chartRefs.value.some(el => el && el.clientWidth > 0 && el.clientHeight > 0);
        if (hasValidRefs) {
          initCharts();
        } else {
          logger('warn', `DOM 尺寸无效，延迟 300ms 重试...`);
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
    logger('info', `主题变化: ${darkMode ? 'dark' : 'light'}`);

    if (chartVisible.value && chartData.value.length > 0) {
      logger('info', `重新生成图表配置以适配主题`);
      const newOptions = generateChartOptionsFromBackend(chartData.value, darkMode);

      if (chartInstances.value.length > 0) {
        logger('info', `更新现有图表实例`);
        newOptions.forEach((option, index) => {
          if (option && !option.error && chartInstances.value[index]) {
            chartInstances.value[index].setOption(option, true);
          }
        });
      } else {
        chartOptions.value = newOptions;
      }
    }
  });

  onActivated(() => {
    logger('info', `========== onActivated ==========`);
    logger(
      'info',
      `chartVisible: ${chartVisible.value}, chartOptions: ${chartOptions.value.length}, instances: ${chartInstances.value.length}, refs: ${chartRefs.value.filter(r => r).length}`
    );

    if (chartVisible.value && chartOptions.value.length > 0) {
      const tryResize = (retryCount: number) => {
        const validRefs = chartRefs.value.filter(el => el && el.clientWidth > 0 && el.clientHeight > 0);
        logger(
          'debug',
          `onActivated resize 重试 ${retryCount}, validRefs: ${validRefs.length}, instances: ${chartInstances.value.length}`
        );

        if (validRefs.length >= chartOptions.value.length) {
          if (chartInstances.value.length > 0) {
            chartInstances.value.forEach((instance, index) => {
              if (instance && validRefs[index]) {
                logger('info', `重新渲染图表 ${index}`);
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

              logger('info', `onActivated 创建新图表 ${index}`);
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
          logger('warn', `onActivated: 重试结束仍无法初始化`);
        }
      };
      tryResize(15);
    }
  });

  onDeactivated(() => {
    logger('info', `========== onDeactivated ==========`);
  });

  /**
   * 钻取后用新的图表数据替换当前图表
   * 由 useWorkbenchChartDrill 在收到后端钻取响应后调用
   */
  async function reloadChartsFromDrill(charts: any[]) {
    logger('info', `========== reloadChartsFromDrill 开始 ==========`);
    logger('info', `收到 ${charts.length} 个钻取图表`);

    if (!charts || charts.length === 0) {
      logger('warn', `钻取图表数据为空`);
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

    logger('info', `钻取图表配置生成完成, 共 ${chartOptions.value.length} 个`);

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

    logger('info', `========== reloadChartsFromDrill 结束 ==========`);
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
