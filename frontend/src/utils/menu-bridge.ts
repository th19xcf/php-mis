/**
 * 菜单桥接模块工具函数
 *
 * 提供列名 / 字段名识别等纯函数能力。
 */

/**
 * 判断列是否为 GUID 列（不区分大小写，比较字段名或表头名）
 *
 * @param field 字段名（field）
 * @param label 表头名（title / headerName）
 * @returns 是否为 GUID 列
 */
export function isGuidColumn(field: string, label: string): boolean {
  return field.trim().toUpperCase() === 'GUID' || label.trim().toUpperCase() === 'GUID';
}
