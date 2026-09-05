import { WORKBENCH_CONFIG } from '@/config/workbench';

/**
 * 检测列状态是否"可疑地窄"：数据列中宽度 <=80 的占比 >=70%。
 *
 * 用于识别异常列状态（如恢复缓存的列宽全部塌缩），
 * 排除 selection 列与 ag-grid 内置前缀列（ag-Grid-xxx），
 * 仅统计数据列。
 */
export function hasSuspiciousNarrowColumnState(columnState: any[]): boolean {
  if (!Array.isArray(columnState) || columnState.length === 0) return false;

  const { COLUMN_IDS } = WORKBENCH_CONFIG;
  const dataColumns = columnState.filter((col: any) => {
    const colId = String(col?.colId || '');
    return colId && colId !== COLUMN_IDS.SELECTION && !colId.startsWith(COLUMN_IDS.PREFIX);
  });

  if (dataColumns.length === 0) return false;

  const narrowCount = dataColumns.filter((col: any) => {
    const width = Number(col?.width || 0);
    return Number.isFinite(width) && width > 0 && width <= 80;
  }).length;

  return narrowCount / dataColumns.length >= 0.7;
}
