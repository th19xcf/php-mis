import { fetchImportDebug, fetchWorkbenchDebug } from '@/service/api/workbench';
import { logger } from '@/utils/logger';

type NotifyType = 'success' | 'error' | 'warning' | 'info';

interface UseWorkbenchPageDebugOptions {
  getFunctionCode: () => string;
  notify: (type: NotifyType, message: string) => void;
}

/**
 * 工作台「页面」调试：调后端 /workbench/debug 拉取后端 SQL 快照并打印到控制台。
 *  - 输出 queryTable / queryWhere / queryGroup / queryOrder / selectParts / whereParts / countSql / querySql
 *  - 输出 userAuth / functionAuth / columns / chartModule / chartSql
 *  - 对 chartSql 中的 $查询表名 / $[部门全称赋权] 等占位符做替换后再次输出
 */
export function useWorkbenchPageDebug(options: UseWorkbenchPageDebugOptions) {
  function replacePlaceholders(sql: string, data: Api.Workbench.DebugData): string {
    let result = sql;
    result = result.replace(/\$查询表名/g, data.queryTable);
    const deptNameAuth = Array.isArray(data.userAuth.deptNameAuth)
      ? data.userAuth.deptNameAuth
      : data.userAuth.deptNameAuth
        ? [data.userAuth.deptNameAuth]
        : [];
    const deptNameJson = JSON.stringify(deptNameAuth).replace(/"/g, '\\"');
    result = result.replace(/\$\[部门全称赋权\]/g, `"${deptNameJson}"`);
    return result;
  }

  function printUserAuth(data: Api.Workbench.DebugData) {
    logger.info('  - 公司ID:', data.userAuth.companyId);
    logger.info('  - 工号:', data.userAuth.userWorkId);
    logger.info(
      '  - 角色编码:',
      Array.isArray(data.userAuth.roleCodes) ? data.userAuth.roleCodes.join(', ') : data.userAuth.roleCodes || '(无)'
    );
    logger.info('  - 属地赋权:', data.userAuth.locationAuth);
    logger.info(
      '  - 部门编码赋权:',
      Array.isArray(data.userAuth.deptCodeAuth)
        ? data.userAuth.deptCodeAuth.join(', ')
        : data.userAuth.deptCodeAuth || '(无)'
    );
    logger.info(
      '  - 部门全称赋权:',
      Array.isArray(data.userAuth.deptNameAuth)
        ? data.userAuth.deptNameAuth.join(', ')
        : data.userAuth.deptNameAuth || '(无)'
    );
    logger.info('  - 调试权限:', data.userAuth.debugAuth ? '有' : '无');
  }

  function printFunctionAuth(data: Api.Workbench.DebugData) {
    logger.info('  - 模块:', data.functionAuth.module);
    logger.info('  - 参数:', data.functionAuth.params || '(无)');
    logger.info('  - 部门权限条件:', data.functionAuth.deptAuthCond || '(无)');
    logger.info('  - 属地权限条件:', data.functionAuth.locationAuthCond || '(无)');
  }

  async function handleDebug() {
    const functionCode = options.getFunctionCode();
    if (!functionCode) {
      options.notify('error', '功能编码不能为空');
      return;
    }

    try {
      const payload: Api.Workbench.QueryPayload = { all: true, filters: [] };
      const { data, error } = await fetchWorkbenchDebug(functionCode, payload);
      if (error || !data) {
        options.notify('error', '获取调试信息失败');
        return;
      }

      logger.groupStart('🔍 调试信息 - ' + data.functionCode);
      logger.info('📊 查询配置:');
      logger.info('  - 查询表:', data.queryTable);
      logger.info('  - 查询模式:', data.mode);
      logger.info('  - WHERE 条件:', data.queryWhere || '(无)');
      logger.info('  - GROUP BY:', data.queryGroup || '(无)');
      logger.info('  - ORDER BY:', data.queryOrder || '(无)');

      logger.info('\n📝 SELECT 部分:');
      data.selectParts.forEach((part, index) => {
        logger.info(`  ${index + 1}. ${part}`);
      });

      logger.info('\n🔧 WHERE 部分:');
      if (data.whereParts.length > 0) {
        data.whereParts.forEach((part, index) => {
          logger.info(`  ${index + 1}. ${part}`);
        });
      } else {
        logger.info('  (无)');
      }

      logger.info('\n💻 SQL 语句:');
      logger.info('  计数 SQL:', data.countSql || '(不适用)');
      logger.info('  查询 SQL:', data.querySql);

      logger.info('\n👤 用户权限:');
      printUserAuth(data);

      logger.info('\n⚙️ 功能权限:');
      printFunctionAuth(data);

      logger.info('\n📋 字段映射:');
      // eslint-disable-next-line no-console
      console.table(data.columns);

      logger.info('\n📈 图形 SQL:');
      logger.info('chartModule:', data.chartModule);

      if (data.chartSql && Array.isArray(data.chartSql)) {
        const replacedChartSql = data.chartSql.map((chart: any) => ({
          ...chart,
          sql: chart.sql ? replacePlaceholders(chart.sql, data) : chart.sql
        }));
        logger.info('chartSql (已替换占位符):', JSON.stringify(replacedChartSql, null, 2));
      } else {
        logger.info('chartSql:', JSON.stringify(data.chartSql, null, 2));
      }

      logger.info('\n========================================');
      logger.info('🛠️ 数据整理 / 📦 导入 / 💬 备注 模块');
      logger.info('========================================');
      logger.info('  - 数据整理模块:', data.upkeepModule || '(未配置)');
      logger.info('  - 数据整理 SQL:', data.upkeepSql || '(无)');
      logger.info('  - 导入模块:', data.importModule || '(未配置)');
      logger.info('  - 备注模块:', data.commentModule || '(未配置)');
      logger.info('========================================\n');

      logger.info('========================================');
      logger.info('📈 图形配置信息');
      logger.info('========================================');
      logger.info('chartModule:', data.chartModule);
      logger.info('\n查询 SQL:');
      logger.info(data.chartQuerySql || '(无)');
      logger.info('\nchartSql 数组长度:', data.chartSql?.length || 0);

      logger.info('\nchartSql 完整数据:');
      logger.info(JSON.stringify(data.chartSql, null, 2));

      if (data.chartSql && data.chartSql.length > 0) {
        logger.info('\n图形 SQL 明细:');
        (data.chartSql as any[]).forEach((chart, index) => {
          logger.info(`\n--- 图形 ${index + 1} ---`);
          logger.info('名称:', chart['图形名称'] || chart.name || '未命名');
          logger.info('SQL:', chart.sql ? replacePlaceholders(chart.sql, data) : '(无)');
          if (chart.error) {
            logger.info('错误:', chart.error);
          }
        });
      } else {
        logger.info('\n❌ 未查询到图形配置');
        logger.info('请检查 def_chart_config 表中是否存在图形模块:', data.chartModule);
        logger.info('或者检查表中是否有顺序>0 的有效记录');
      }
      logger.info('========================================\n');

      logger.groupEnd();
      options.notify('success', '调试信息已输出到控制台');

      // 导入调试 SQL（不依赖具体样本数据，仅展示结构与配置）
      await handleImportDebug(functionCode);
    } catch (err) {
      options.notify('error', '获取调试信息失败');
      console.error('调试信息获取错误:', err);
    }
  }

  /**
   * 工作台「导入」调试：调后端 /workbench/import-debug 拉取导入相关 SQL 快照并打印到控制台。
   *  - 输出 tmpTableName / dataTable / importModule
   *  - 输出 createTempTableSql（建临时表）
   *  - 输出 insertToTempTableSql（写临时表，含 缺省值 逻辑，可选）
   *  - 输出 importFromTempTableSql（从临时表导入正式表，INSERT ... SELECT）
   *  - 输出 importColumns（导入列配置）
   */
  async function handleImportDebug(functionCode: string) {
    try {
      const { data, error } = await fetchImportDebug(functionCode, {});
      if (error || !data) {
        options.notify('error', '获取导入调试信息失败');
        return;
      }
      if (!data.success) {
        options.notify('error', data.message || '获取导入调试信息失败');
        return;
      }

      logger.groupStart('📥 导入调试 - ' + functionCode);
      logger.info('🏷️  基本信息:');
      logger.info('  - 数据表:', data.dataTable || '(无)');
      logger.info('  - 导入模块:', data.importModule || '(无)');
      logger.info('  - 临时表名:', data.tmpTableName || '(无)');

      logger.info('\n📋 导入列配置:');
      if (data.importColumns && data.importColumns.length > 0) {
        // 后端 ImportService::getImportConfig 返回的 importColumns 数组使用中文 key
        // （列名 / 字段名 / 查询名 / 顺序 / 字段类型 / 校验类型 / 校验信息 / 导入类型 / 缺省值 / 对象 / 系统变量 / 匹配标识）
        // eslint-disable-next-line no-console
        console.table(
          data.importColumns.map((c: any) => ({
            列名: c['列名'] ?? '',
            字段名: c['字段名'] ?? '',
            查询名: c['查询名'] ?? '',
            顺序: c['顺序'] ?? '',
            字段类型: c['字段类型'] ?? '',
            校验类型: c['校验类型'] ?? '',
            校验信息: c['校验信息'] ?? '',
            导入类型: c['导入类型'] ?? '',
            缺省值: c['缺省值'] ?? '',
            系统变量: c['系统变量'] ?? '',
            匹配标识: c['匹配标识'] ?? ''
          }))
        );
      } else {
        logger.info('  (无)');
      }

      logger.info('\n💻 导入相关 SQL:');
      logger.info('  ① 创建临时表 SQL:');
      logger.info(data.createTempTableSql || '(无)');
      logger.info('\n  ② 插入临时表 SQL（需要 sampleData 时才返回）:');
      logger.info(data.insertToTempTableSql || '(无样本数据，未生成)');
      logger.info('\n  ③ 从临时表导入正式表 SQL（INSERT ... SELECT）:');
      logger.info(data.importFromTempTableSql || '(无)');
      logger.info('========================================\n');

      logger.groupEnd();
      options.notify('success', '导入调试 SQL 已输出到控制台');
    } catch (err) {
      options.notify('error', '获取导入调试信息失败');
      console.error('导入调试信息获取错误:', err);
    }
  }

  return { handleDebug };
}
