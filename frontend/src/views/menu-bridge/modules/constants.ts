/**
 * 工作台模块共享常量
 *
 * 主组件及其子组件的运行时常量集中点。
 * 注：与 src/config/workbench.ts 中的全局配置区别在于：
 *   - config/workbench.ts：跨模块、面向部署/后端协议
 *   - 本文件：仅 menu-bridge 模块内部使用、与 ag-grid 强耦合
 */

/**
 * ag-grid 默认列定义
 *
 * 不设置 maxWidth，允许列自适应到任意宽度
 * resizable: true 启用列宽拖拽
 * filter: true 启用 ag-grid 内置筛选
 */
export const DEFAULT_COL_DEF = {
  width: 120,
  minWidth: 0,
  resizable: true,
  editable: true,
  filter: true,
  filterParams: {
    maxNumConditions: 5
  }
} as const;
