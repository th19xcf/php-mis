import { ref, computed, type Ref } from 'vue';
import { h } from 'vue';
import { NRadio, NRadioGroup, NButton } from 'naive-ui';
import { fetchWorkbenchChartDrill } from '@/service/api/workbench';
import { logger } from '@/utils/logger';

type MessageType = 'success' | 'error' | 'warning' | 'info';

interface UseWorkbenchChartDrillOptions {
  getFunctionCode: () => string;
  /** 数据点钻取选项集合，按 SID 索引 */
  getDrillOptionsForChart: (sid: string) => Api.Workbench.DrillOption[] | null;
  notify: (type: MessageType, message: string, data?: unknown) => void;
  loading: Ref<boolean>;
  /** 钻取状态变化回调（用于触发图表刷新） */
  onDrillChartsUpdated: (charts: any[]) => void;
  /** 是否处于暗色模式 */
  isDarkMode: Ref<boolean>;
}

/**
 * 钻取上下文（前端持有并回传后端，替代旧版 session 累加）
 *
 * - condStr：多级钻取条件累加串（字段1^值1;字段2^值2;...）
 * - titleStr：多级钻取副标题累加串（值1,值2,...）
 *
 * 每次钻取请求时回传给后端，后端叠加本次钻取条件后返回新的 drillContext，
 * 前端持有用于下次请求回传。退出钻取仅在前端清空本地状态即可，无需后端接口。
 */
interface DrillContext {
  condStr: string;
  titleStr: string;
}

const EMPTY_DRILL_CONTEXT: DrillContext = { condStr: '', titleStr: '' };

/**
 * 图形钻取组合式函数
 *
 * 完整复刻旧版 Vgrid_aggrid.php 中 chart_drill 流程：
 *   1. 图表点击 → 解析 SID → 弹出钻取选项
 *   2. 用户选择钻取选项 → 调用后端 /workbench/chart-drill
 *   3. 后端返回下一级钻取图形数据 + 新的 drillContext → 前端重新渲染
 *   4. 支持"返回初始图形"按钮（前端清空 drillContext + drillLevel 即可）
 *
 * 与旧版的差异：钻取状态由前端持有，后端无状态。
 *  - 多端并发钻取互不干扰（每个前端实例持有独立 drillContext）
 *  - 消除 session 文件锁竞争
 */
export function useWorkbenchChartDrill(options: UseWorkbenchChartDrillOptions) {
  // 当前钻取级别，0=初始，1=第一级钻取...
  const drillLevel = ref(0);
  // 钻取上下文（前端持有并回传后端，替代 session 累加）
  const drillContext = ref<DrillContext>({ ...EMPTY_DRILL_CONTEXT });
  // 是否处于钻取状态
  const isDrilled = computed(() => drillLevel.value > 0);

  function log(method: 'info' | 'warn' | 'error' | 'debug', message: string, data?: unknown) {
    const timestamp = new Date().toLocaleTimeString('zh-CN', { hour12: false });
    const prefix = `[${timestamp}] [CHART-DRILL] [${method.toUpperCase()}]`;
    if (data !== undefined) {
      logger.info(`${prefix} ${message}`, data);
    } else {
      logger.info(`${prefix} ${message}`);
    }
  }

  /**
   * 处理图表点击事件
   *
   * 多级钻取说明：
   *  - 不在前端硬性限制级别数；钻取层数由后端 def_chart_drill_config 中各级目标图形的 钻取选项 决定
   *  - 当前图形的钻取选项由 options.getDrillOptionsForChart(sid) 动态返回（来源：当前展示的 chartData）
   *  - 若当前图形未配置钻取选项，会提示"当前图形未配置钻取选项"
   *
   * @param params ECharts click 回调参数
   */
  function handleChartClick(params: any) {
    log('info', `========== handleChartClick 开始 ==========`);
    log('info', `drillLevel=${drillLevel.value}, dataItem=${JSON.stringify(params?.data || {})}`);

    if (!params || !params.data) {
      log('warn', `点击数据为空`);
      return;
    }

    const dataItem = params.data as Record<string, any>;
    // 旧版：SID 形如 "图形模块^图形编号"
    const sid: string = dataItem['SID'] || '';
    if (!sid) {
      log('warn', `数据点缺少 SID 字段`);
      options.notify('warning', '当前数据点不支持钻取');
      return;
    }

    // 取出该图表可用的钻取选项
    const drillOpts = options.getDrillOptionsForChart(sid);
    if (!drillOpts || drillOpts.length === 0) {
      log('info', `该数据点无钻取配置`);
      options.notify('info', '当前图形未配置钻取选项');
      return;
    }

    log('info', `找到 ${drillOpts.length} 个钻取选项:`, drillOpts);

    showDrillOptionsDialog(drillOpts, dataItem);
  }

  /**
   * 弹出钻取选项选择对话框
   */
  function showDrillOptionsDialog(drillOpts: Api.Workbench.DrillOption[], dataItem: Record<string, any>) {
    // 构造前端钻取选项：value = "option^chart_module^drill_module"
    const choices = drillOpts.map((opt, idx) => ({
      id: `${opt.functionCode}_${idx}`,
      label: opt.label,
      value: buildDrillOptionValue(opt),
      raw: opt
    }));

    log('debug', `弹出钻取选项对话框，选项数=${choices.length}`);

    const selectedValue = ref<string>(choices[0]?.value || '');

    const handleConfirm = async () => {
      if (!selectedValue.value) {
        options.notify('warning', '请选择钻取条件');
        return;
      }

      // 关闭对话框
      if (dialogInstance) {
        dialogInstance.destroy();
      }

      await executeChartDrill(selectedValue.value, dataItem);
    };

    const renderContent = () =>
      h('div', { style: { display: 'flex', flexDirection: 'column', minHeight: '200px' } }, [
        h(
          NRadioGroup,
          {
            value: selectedValue.value,
            'onUpdate:value': (val: string) => {
              selectedValue.value = val;
            },
            style: { flex: 1, overflow: 'auto', padding: '16px' }
          },
          {
            default: () =>
              choices.map(opt =>
                h(
                  NRadio,
                  {
                    value: opt.value,
                    style: { display: 'flex', marginBottom: '8px', alignItems: 'center' }
                  },
                  { default: () => opt.label }
                )
              )
          }
        ),
        h(
          'div',
          {
            style: {
              display: 'flex',
              justifyContent: 'flex-end',
              padding: '16px',
              borderTop: '1px solid #3d4f60'
            }
          },
          h(
            NButton,
            {
              type: 'primary',
              onClick: handleConfirm
            },
            { default: () => '确定' }
          )
        )
      ]);

    let dialogInstance: any = null;
    dialogInstance = window.$dialog?.info({
      title: '选择钻取条件',
      style: { width: '380px', minHeight: '260px' },
      content: renderContent
    });
  }

  /**
   * 执行图形钻取
   *
   * 多级钻取（无状态实现）：
   *  - 请求体 payload[3] = drillContext { condStr, titleStr }（前端持有并回传）
   *  - 后端叠加本次钻取条件后返回新的 drillContext，前端持有用于下次请求回传
   *  - 响应中 drillLevel = 当前级别 + 1，作为前端新的钻取级别
   *  - 退出钻取由前端清空 drillContext + drillLevel，无需后端 reset 接口
   */
  async function executeChartDrill(optionValue: string, dataItem: Record<string, any>) {
    const functionCode = options.getFunctionCode();
    if (!functionCode) {
      options.notify('error', '功能编码不能为空');
      return;
    }

    log('info', `执行钻取: optionValue=${optionValue}, currentLevel=${drillLevel.value}, functionCode=${functionCode}`);

    options.loading.value = true;

    try {
      // 把 drillContext 作为 payload[3] 回传后端（替代 session 累加）
      const payload = [
        { 钻取级别: drillLevel.value },
        { 钻取选项: optionValue },
        dataItem,
        drillContext.value
      ];

      const { data, error } = await fetchWorkbenchChartDrill(functionCode, payload);

      if (error) {
        log('error', `钻取失败:`, error);
        options.notify('error', '图形钻取失败', error);
        return;
      }

      if (!data || !data.charts) {
        log('warn', `钻取返回数据为空`);
        options.notify('warning', '钻取结果为空');
        return;
      }

      // 优先采用后端返回的 drillLevel；兜底：前端 drillLevel + 1
      const newLevel = typeof data.drillLevel === 'number' ? data.drillLevel : drillLevel.value + 1;
      drillLevel.value = newLevel;

      // 更新钻取上下文（后端叠加本次钻取条件后的新状态，前端持有用于下次请求回传）
      if (data.drillContext) {
        drillContext.value = { ...data.drillContext };
      }

      log('info', `钻取成功, 新级别=${drillLevel.value}, 返回图表数=${data.charts.length}`);

      // 通知外部更新图表
      options.onDrillChartsUpdated(data.charts);
      options.notify('success', `已钻取至第 ${drillLevel.value} 级`);
    } catch (err) {
      log('error', `钻取异常:`, err);
      options.notify('error', '图形钻取异常', err);
    } finally {
      options.loading.value = false;
    }
  }

  /**
   * 返回初始图形
   *
   * 后端已无状态（drillContext 由前端持有），仅需清空本地状态。
   * 实际的"重新加载初始图形"由调用方在 resetDrill 后调 handleOpenChart 完成。
   */
  async function resetDrill() {
    log('info', `重置钻取状态`);
    drillLevel.value = 0;
    drillContext.value = { ...EMPTY_DRILL_CONTEXT };
    log('info', `钻取状态已重置`);
    options.notify('success', '已返回初始图形');
  }

  /**
   * 静默重置钻取状态
   *
   * 与 resetDrill 的区别：
   *  - 不显示成功 / 失败通知（适用于关闭图形时的自动清理）
   *  - 不切换外部 loading 状态
   *  - 前端状态立即归零，保证头部徽章 `钻取第 N 级 / 初始图形` 在关闭瞬间反映正确状态
   *
   * 后端无状态，无需任何网络请求。
   */
  async function silentResetDrill(): Promise<void> {
    log('info', `静默重置钻取状态`);
    drillLevel.value = 0;
    drillContext.value = { ...EMPTY_DRILL_CONTEXT };
  }

  return {
    drillLevel,
    drillContext,
    isDrilled,
    handleChartClick,
    resetDrill,
    silentResetDrill
  };
}

/**
 * 构造钻取选项 value
 * 格式：option^chart_module^drill_module
 */
function buildDrillOptionValue(opt: Api.Workbench.DrillOption): string {
  // 选项 value 格式：钻取选项^图形模块^钻取模块
  //   - 图形模块：来自 def_chart_drill_config.图形模块（即 opt.chartModule）
  //     绝不能用 dataItem.图形模块（SP 产出的逻辑模块，如 "公司_101"），二者对不上
  //   - 钻取模块：来自 def_chart_drill_config.钻取模块（即 opt.module）
  const chartModule = opt.chartModule || '';
  const drillModule = opt.module || opt.functionCode;
  return `${opt.label}^${chartModule}^${drillModule}`;
}
