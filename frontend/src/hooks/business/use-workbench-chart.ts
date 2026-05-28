import { ref, watch, nextTick } from 'vue';
import * as echarts from 'echarts/core';
import { LineChart, BarChart, PieChart } from 'echarts/charts';
import {
  TitleComponent,
  TooltipComponent,
  LegendComponent,
  GridComponent,
  DatasetComponent
} from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import type { ECharts } from 'echarts/core';
import { request } from '@/service/request';
import { WORKBENCH_CONFIG } from '@/config/workbench';

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
}

function generateChartOptionFromBackend(charts: any[]): any {
  if (!charts || charts.length === 0) {
    return null;
  }

  const { CHART } = WORKBENCH_CONFIG;
  const chart = charts[0];
  const chartData = chart['数据'] || [];
  const chartType = chart['图形类型'] || CHART.DEFAULT_TYPE;
  const chartName = chart['图形名称'] || CHART.DEFAULT_NAME;
  const fieldsConfig = chart['字段'] || {};

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
      value: Number(item[valueKeys[0]]) || 0
    }));

    return {
      title: {
        text: chartName,
        left: 'center'
      },
      tooltip: {
        trigger: 'item',
        formatter: '{a} <br/>{b}: {c} ({d}%)'
      },
      legend: {
        orient: 'vertical',
        left: 'left'
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
    const valueKeys = dataKeys.slice(1).filter(key => {
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
        data: (chartData as Record<string, any>[]).map((item: Record<string, any>) => parseValue(item[key]))
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
      title: {
        show: true,
        text: chartName,
        triggerEvent: true
      },
      legend: {
        bottom: 2,
        data: valueKeys
      },
      tooltip: {
        trigger: 'axis',
        axisPointer: { type: 'cross' }
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
      xAxis: { type: 'category' },
      yAxis: yAxis.length > 1 ? yAxis : yAxis[0],
      series: dem,
      grid: {
        left: '3%',
        right: yRight ? '12%' : '4%',
        bottom: '10%',
        top: 80
      }
    };
  }
}

export function useWorkbenchChart(options: UseWorkbenchChartOptions) {
  const chartVisible = ref(false);
  const chartLoading = ref(false);
  const chartData = ref<any[]>([]);
  const chartOption = ref<any>(null);
  const chartRef = ref<HTMLDivElement | null>(null);
  let chartInstance: ECharts | null = null;

  function logger(method: 'info' | 'warn' | 'error' | 'debug', message: string, data?: unknown) {
    const timestamp = new Date().toISOString().split('T')[1].split('.')[0];
    const prefix = `[${timestamp}] [CHART] [${method.toUpperCase()}]`;
    
    if (data !== undefined) {
      console.log(`${prefix} ${message}`, data);
    } else {
      console.log(`${prefix} ${message}`);
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

      const firstChart = data.charts[0];
      if (firstChart?.['错误']) {
        const errorMsg = `图形查询错误: ${firstChart['错误']}`;
        logger('error', errorMsg);
        logger('error', `SQL: ${firstChart['SQL']}`);
        options.notify('error', errorMsg);
        chartLoading.value = false;
        logger('info', `========== handleOpenChart 结束（查询错误）==========`);
        return;
      }

      logger('info', `生成图表配置`);
      chartOption.value = generateChartOptionFromBackend(data.charts);
      logger('info', `图表配置生成成功`);
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

  function initChart() {
    logger('info', `========== initChart 开始 ==========`);
    
    if (!chartRef.value || !chartOption.value) {
      logger('warn', `chartRef 或 chartOption 为空，跳过初始化`);
      return;
    }

    if (chartInstance) {
      logger('debug', `销毁旧的图表实例`);
      chartInstance.dispose();
    }

    logger('info', `初始化 ECharts 实例`);
    chartInstance = echarts.init(chartRef.value);
    chartInstance.setOption(chartOption.value);
    logger('info', `图表初始化成功`);

    const resizeHandler = () => {
      chartInstance?.resize();
    };
    window.addEventListener('resize', resizeHandler);
    logger('debug', `注册窗口 resize 事件监听`);

    const unwatch = watch(chartVisible, (v) => {
      if (!v) {
        window.removeEventListener('resize', resizeHandler);
        unwatch();
        logger('debug', `移除窗口 resize 事件监听`);
      }
    });
    
    logger('info', `========== initChart 结束 ==========`);
  }

  watch(chartVisible, async (visible) => {
    logger('info', `chartVisible 变化: ${visible}`);
    
    if (visible && chartOption.value) {
      logger('info', `开始初始化图表`);
      await nextTick();
      setTimeout(() => {
        if (chartRef.value) {
          const width = chartRef.value.clientWidth;
          const height = chartRef.value.clientHeight;
          logger('info', `DOM 尺寸: { width: ${width}, height: ${height} }`);

          if (width === 0 || height === 0) {
            logger('warn', `DOM 宽高为 0，延迟 300ms 重试...`);
            setTimeout(() => {
              if (chartRef.value && chartRef.value.clientWidth > 0 && chartRef.value.clientHeight > 0) {
                initChart();
              }
            }, 300);
            return;
          }

          initChart();
        }
      }, 200);
    } else if (!visible && chartInstance) {
      chartInstance.dispose();
      chartInstance = null;
    }
  });

  watch(chartOption, async (option) => {
    if (option && chartVisible.value && !chartInstance) {
      await nextTick();
      setTimeout(() => {
        if (chartRef.value) {
          const width = chartRef.value.clientWidth;
          const height = chartRef.value.clientHeight;
          console.log('[图形功能] chartOption 变化，DOM 尺寸:', { width, height });

          if (width > 0 && height > 0) {
            initChart();
          } else {
            setTimeout(() => {
              if (chartRef.value && chartRef.value.clientWidth > 0 && chartRef.value.clientHeight > 0) {
                initChart();
              }
            }, 300);
          }
        }
      }, 100);
    }
  });

  function resizeChart() {
    chartInstance?.resize();
  }

  return {
    chartVisible,
    chartLoading,
    chartData,
    chartOption,
    chartRef,
    handleOpenChart,
    resizeChart
  };
}