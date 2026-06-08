import type { Ref } from 'vue';
import { useRoute } from 'vue-router';
import { fetchWorkbenchDebug } from '@/service/api/workbench';
import { logger } from '@/utils/logger';

type NotifyType = 'success' | 'error' | 'warning' | 'info';

interface UseWorkbenchChartDebugOptions {
  getFunctionCode: () => string;
  chartData: Ref<any[]>;
  drillLevel: Ref<number>;
  isDrilled: Ref<boolean>;
  chartVisible: Ref<boolean>;
  chartLoading: Ref<boolean>;
  chartMaximized: Ref<boolean>;
  pageMeta: Ref<Api.Workbench.PageMeta | null>;
  notify: (type: NotifyType, message: string) => void;
}

/**
 * 工作台「图表」调试：输出运行时 chartData + 后端 /workbench/debug 快照，便于排查钻取链路。
 *  - 输出每个图形的：图形模块 / 图形编号 / 名称 / SQL / 错误 / 钻取选项
 *  - 输出 ECharts DOM 信息
 *  - 调后端 /workbench/debug 拉取 session 快照，对比输出
 */
export function useWorkbenchChartDebug(options: UseWorkbenchChartDebugOptions) {
  const route = useRoute();

  function printRouteContext() {
    const currentRoute = route;
    logger.info('🧭 路由上下文:');
    logger.info('  - 路径:', currentRoute.path);
    logger.info('  - 名称:', String(currentRoute.name || ''));
    logger.info('  - query:', JSON.parse(JSON.stringify(currentRoute.query || {})));
    logger.info('  - meta.functionCode:', String(currentRoute.meta?.functionCode || ''));
  }

  function printPageMeta() {
    const meta = options.pageMeta.value;
    logger.info('\n📋 页面 pageMeta（来自后端 /workbench/page）:');
    logger.info('  - chartModule:', meta?.chartModule || '<空>');
    logger.info('  - queryModule:', meta?.queryModule || '<空>');
    logger.info('  - fieldModule:', meta?.fieldModule || '<空>');
    logger.info('  - commentModule:', meta?.commentModule || '<空>');
    logger.info('  - mode:', meta?.mode || '<空>');
    logger.info('  - supportsStoredProcedure:', meta?.supportsStoredProcedure);
    logger.info('  - toolbar:', JSON.parse(JSON.stringify(meta?.toolbar || {})));
  }

  function printDrillStatus() {
    logger.info('\n🔍 钻取状态:');
    logger.info('  - drillLevel:', options.drillLevel.value);
    logger.info('  - isDrilled:', options.isDrilled.value);
    logger.info('  - chartVisible:', options.chartVisible.value);
    logger.info('  - chartLoading:', options.chartLoading.value);
    logger.info('  - chartMaximized:', options.chartMaximized.value);
  }

  function printChartDetail(chart: any, index: number) {
    const chartModule = chart['图形模块'] ?? '<空>';
    const chartCode = chart['图形编号'] ?? '<空>';
    const chartName = chart['图形名称'] ?? '<空>';
    const fetchMode = chart['取数方式'] ?? '<空>';
    const sql = chart['SQL'] ?? '';
    const error = chart['错误'];
    const dataRows = Array.isArray(chart['数据']) ? chart['数据'] : [];
    const drillOptions = Array.isArray(chart['钻取选项']) ? chart['钻取选项'] : [];

    logger.groupStart(`📊 图形 ${index + 1}: ${chartName} [${chartModule}^${chartCode}]`);
    logger.info('基础信息:');
    logger.info('  - 图形模块:', chartModule);
    logger.info('  - 图形编号:', chartCode);
    logger.info('  - 图形名称:', chartName);
    logger.info('  - 取数方式:', fetchMode);
    logger.info('  - 钻取模块:', chart['钻取模块'] ?? '<空>');
    logger.info('  - 字段模块:', chart['字段模块'] ?? '<空>');
    logger.info('  - 页面布局:', chart['页面布局'] ?? '<空>');
    logger.info('  - 图形类型:', chart['图形类型'] ?? '<空>');
    logger.info('  - SID 模板:', chart['SID'] ?? '<空>');
    logger.info('  - 数据条数:', dataRows.length);

    if (sql) {
      logger.info('SQL:');
      logger.info(sql);
    } else {
      logger.info('SQL: (空)');
    }
    if (error) {
      logger.info('❌ 错误:', error);
    }
    if (dataRows.length > 0) {
      logger.info('数据条数:', dataRows.length);
    } else {
      logger.info('数据: (空)');
    }

    if (drillOptions.length === 0) {
      logger.info('⚠️ 钻取选项: 无');
    } else {
      logger.info(`钻取选项数: ${drillOptions.length}`);
      logger.info('钻取选项完整列表:');
      // eslint-disable-next-line no-console
      console.table(
        drillOptions.map((o: any) => ({
          钻取选项: o.label ?? o['钻取选项'] ?? '<空>',
          图形模块: o.chartModule ?? o['图形模块'] ?? '<空>',
          钻取模块: o.module ?? o['钻取模块'] ?? o.functionCode ?? '<空>',
          钻取字段: o.drillFields ?? o['钻取字段'] ?? '(无)',
          钻取条件: o.drillCondition ?? o['钻取条件'] ?? '(无)',
          value: o.value ?? '<空>'
        }))
      );
    }
    logger.groupEnd();
  }

  async function fetchDebugSnapshot(functionCode: string) {
    try {
      const payload: Api.Workbench.QueryPayload = { all: true, filters: [] };
      const { data, error } = await fetchWorkbenchDebug(functionCode, payload);
      if (error || !data) {
        logger.info('  ❌ 拉取失败:', error);
        return null;
      }
      logger.info('  ✅ 后端 pageMeta 快照:');
      logger.info('    - functionCode:', data.functionCode);
      logger.info('    - queryTable:', data.queryTable);
      logger.info('    - chartModule:', data.chartModule);
      logger.info('    - chartQuerySql:', data.chartQuerySql);
      logger.info('    - chartSql 长度:', data.chartSql?.length || 0);
      logger.info('    - queryMode:', data.mode);
      logger.info('    - 完整后端响应:', JSON.parse(JSON.stringify(data)));
      return data;
    } catch (e) {
      logger.info('  ❌ 异常:', e);
      return null;
    }
  }

  function printChartSqlUnreplacedHint(debugData: Api.Workbench.DebugData | null) {
    if (!debugData?.chartSql) return;
    if (!Array.isArray(debugData.chartSql) || debugData.chartSql.length === 0) return;
    logger.info('\n🗂️ 后端 chartSql 明细:');
    debugData.chartSql.forEach((cs: any, i: number) => {
      logger.groupStart(`  chartSql[${i}]`);
      logger.info('    名称:', cs['图形名称'] || cs.name);
      logger.info('    编号:', cs['图形编号']);
      logger.info('    SQL:', cs.sql);
      if (cs.error) logger.info('    错误:', cs.error);
      logger.groupEnd();
    });

    const placeholderPattern = /\$\{?[\u4e00-\u9fa5A-Za-z_]+\}?/g;
    const hasUnreplaced = debugData.chartSql.some((cs: any) => cs.sql && placeholderPattern.test(cs.sql));
    if (hasUnreplaced) {
      logger.info('  ⚠️ 检测到 SQL 中可能存在未替换的占位符（$查询表名 / $[部门全称赋权] 等）');
    }
  }

  function printRawChartData(charts: any[]) {
    logger.groupStart(`📦 完整 chartData（JSON） — ${charts.length} 项 [点击展开]`);
    try {
      const safeCharts = JSON.parse(JSON.stringify(charts));
      logger.info(JSON.stringify(safeCharts, null, 2));
    } catch (e) {
      logger.info('JSON.stringify 失败（可能是循环引用 / 函数 / Symbol），回退输出结构:');
      logger.info('  - 错误:', e);
      logger.info('  - charts.length:', charts.length);
      charts.forEach((c: any, i: number) => {
        logger.info(`  [${i}] keys:`, Object.keys(c || {}));
        const dataRows = Array.isArray(c?.['数据']) ? c['数据'] : [];
        logger.info(
          `       数据条数: ${dataRows.length}, 数据 keys (首行):`,
          dataRows[0] ? Object.keys(dataRows[0]) : []
        );
      });
    }
    logger.groupEnd();
  }

  async function handleChartDebug() {
    const functionCode = options.getFunctionCode();
    const charts = options.chartData.value || [];

    logger.groupStart(
      `📈 图形调试 - functionCode=${functionCode} | 钻取级别=${options.drillLevel.value} (${options.isDrilled.value ? '钻取' : '初始'})`
    );

    printRouteContext();
    printPageMeta();
    printDrillStatus();

    if (charts.length === 0) {
      logger.info('\n⚠️ 当前未加载任何图形（请先点击"图形"按钮打开）');
      logger.groupEnd();
      options.notify('warning', '当前未加载图形数据');
      return;
    }

    logger.info(`\n📊 chartData 明细（${charts.length} 项）:`);
    charts.forEach((chart, index) => printChartDetail(chart, index));

    logger.info('\n🛰️ 拉取后端调试快照 /workbench/debug ...');
    const debugData = await fetchDebugSnapshot(functionCode || '');
    printChartSqlUnreplacedHint(debugData);

    printRawChartData(charts);
    logger.groupEnd();
    options.notify('success', '图形调试信息已输出到控制台');
  }

  return { handleChartDebug };
}
