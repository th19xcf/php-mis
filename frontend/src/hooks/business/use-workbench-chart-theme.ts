/**
 * 工作台图表主题色配置
 *
 * 集中管理 dark / light 模式下的图表与 tooltip 配色。
 * 与 use-workbench-chart 解耦后，便于单独调整和复用。
 */

export interface ChartThemeColors {
  backgroundColor: string;
  textColor: string;
  axisLineColor: string;
  splitLineColor: string;
  legendTextColor: string;
  tooltipBgColor: string;
  tooltipTextColor: string;
  tooltipBorderColor: string;
  /**
   * 透传给 ECharts tooltip 的 extraCssText（box-shadow）。
   * 仅 light 模式有值；dark 模式置为 undefined，避免深色阴影被深色背景"吃掉"。
   */
  tooltipBoxShadow?: string;
}

const darkThemeColors: ChartThemeColors = {
  backgroundColor: 'transparent',
  textColor: '#e5e7eb',
  axisLineColor: '#4b5563',
  splitLineColor: 'rgba(255, 255, 255, 0.08)',
  legendTextColor: '#d1d5db',
  tooltipBgColor: 'rgba(31, 41, 55, 0.95)',
  tooltipTextColor: '#e5e7eb',
  tooltipBorderColor: '#4b5563',
  tooltipBoxShadow: undefined
  // dark 模式不设置 tooltipBoxShadow：tooltip 自身已是 0.95 alpha 深色半透明，
  // 再叠加深色阴影会被深色背景"吃掉"，反而让信息框视觉上消失
};

const lightThemeColors: ChartThemeColors = {
  // 修复：light 模式图表背景用极浅灰（#f8fafc），视觉上接近白色但与白色 tooltip 有微小对比度。
  // 之前用 transparent 让父容器透出，但父容器本身就是白色/近白色，导致白底白底问题。
  backgroundColor: '#f8fafc',
  textColor: '#1f2937',
  axisLineColor: '#6b7280',
  splitLineColor: 'rgba(0, 0, 0, 0.08)',
  legendTextColor: '#374151',
  tooltipBgColor: '#ffffff',
  tooltipTextColor: '#1f2937',
  tooltipBorderColor: 'rgba(0, 0, 0, 0.15)',
  // 增强阴影：让白色 tooltip 在浅灰背景上有明显立体感
  tooltipBoxShadow: '0 2px 12px rgba(0, 0, 0, 0.18)'
};

/**
 * 根据主题模式返回图表配色
 * @param isDarkMode true=深色主题，false=浅色主题
 */
export function getChartThemeColors(isDarkMode: boolean): ChartThemeColors {
  return isDarkMode ? darkThemeColors : lightThemeColors;
}
