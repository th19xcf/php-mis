/**
 * 工作台图表 option 构造器
 *
 * 从 use-workbench-chart 拆分出的纯函数模块，负责：
 * - 单个图表 option 构造（pie / bar+line 混合）
 * - 后端原始 charts 数组 → ECharts option 数组
 * - 钻取选项映射（按 SID 索引）
 *
 * 与主 hook 解耦：纯函数不依赖 Vue 响应式，单独可测试。
 */
import { WORKBENCH_CONFIG } from '@/config/workbench';
import { getChartThemeColors } from './use-workbench-chart-theme';

/**
 * 生成单个图表的 ECharts option
 * @param chart 后端返回的单个图表配置
 * @param isDarkMode 是否深色主题
 * @returns ECharts option 对象；data 为空时返回 null
 */
export function generateChartOption(chart: any, isDarkMode: boolean): any {
  const { CHART, CHART: { CHART_TYPE } } = WORKBENCH_CONFIG;
  const chartData = chart['数据'] || [];
  const chartType = chart['图形类型'] || CHART.DEFAULT_TYPE;
  const chartName = chart['图形名称'] || CHART.DEFAULT_NAME;
  const fieldsConfig = chart['字段'] || {};
  const themeColors = getChartThemeColors(isDarkMode);

  if (chartData.length === 0) {
    return null;
  }

  // 图形类型归一化：兼容 def_chart_config.图形类型 使用中文（饼图/折线图/柱状图）
  // 或英文（pie/line/bar）两种写法。CHART_TYPE 在 src/config/workbench.ts 中
  // 定义为中文枚举值，数据库历史数据全部为中文。
  const normalizedType =
    chartType === CHART_TYPE.PIE || String(chartType).toLowerCase() === 'pie'
      ? 'pie'
      : chartType === CHART_TYPE.BAR || String(chartType).toLowerCase() === 'bar'
        ? 'bar'
        : chartType === CHART_TYPE.LINE || String(chartType).toLowerCase() === 'line'
          ? 'line'
          : String(chartType).toLowerCase();

  if (normalizedType === 'pie') {
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

    const { AXIS_POSITION } = WORKBENCH_CONFIG.CHART;

    for (const key of valueKeys) {
      const fieldConfig = fieldsConfig[key];
      const axisPosition = fieldConfig?.['坐标轴'] || AXIS_POSITION.LEFT;
      // 字段级图形类型同样需要中文 → ECharts type 归一化：
      //   饼图/柱状图/折线图 → pie/bar/line
      const rawFieldType = fieldConfig?.['图形类型'] || normalizedType;
      const fieldChartType =
        rawFieldType === CHART_TYPE.PIE || String(rawFieldType).toLowerCase() === 'pie'
          ? 'pie'
          : rawFieldType === CHART_TYPE.BAR || String(rawFieldType).toLowerCase() === 'bar'
            ? 'bar'
            : rawFieldType === CHART_TYPE.LINE || String(rawFieldType).toLowerCase() === 'line'
              ? 'line'
              : String(rawFieldType).toLowerCase();

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

      if (fieldChartType !== 'bar') {
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
        // 通过 extraCssText 注入 box-shadow：setOption 会覆盖默认主题的 tooltip 样式，
        // 这里手动补回阴影，避免 light 模式下白底白图表背景下信息框"消失"
        extraCssText: themeColors.tooltipBoxShadow
          ? `box-shadow: ${themeColors.tooltipBoxShadow};`
          : undefined,
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

/**
 * 后端原始 charts 数组 → ECharts option 数组
 * - 错误图表以 { error, SQL } 形式保留，调用方负责显示
 * - 补充 chartLayout / chartCode / chartModule / drillModule 元数据供钻取使用
 */
export function generateChartOptionsFromBackend(charts: any[], isDarkMode: boolean): any[] {
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
