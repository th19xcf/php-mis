/**
 * 菜单桥接模块共享类型定义
 *
 * 涵盖路由入参（MenuBridgeMeta）和工作台条件运算符（ConditionOperator）。
 * 由 src/views/menu-bridge/modules 下所有文件共享使用。
 */

/** 路由 / 父组件传入工作台实例的入参结构 */
export interface MenuBridgeMeta {
  /** 功能点编码 */
  functionCode?: string;
  /** 一级菜单 */
  menu1?: string;
  /** 二级菜单 */
  menu2?: string;
  /** 后端模块名 */
  module?: string;
  /** 自定义参数 */
  params?: string;
}

/** 条件过滤运算符 */
export type ConditionOperator = 'contains' | 'equals' | 'startsWith';
