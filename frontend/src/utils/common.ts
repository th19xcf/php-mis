import { $t } from '@/locales';
import { logger } from '@/utils/logger';

let tabSwitchStartTime = 0;
let tabSwitchLabel = '';

export function setTabSwitchStartTime(time: number, label: string) {
  tabSwitchStartTime = time;
  tabSwitchLabel = label;
}

export function recordTabSwitchEnd() {
  if (!tabSwitchStartTime) {
    return;
  }

  const endTime = performance.now();
  const duration = endTime - tabSwitchStartTime;
  logger.info(`[🔀 switchTab] 点击标签页: ${tabSwitchLabel}, 用时: ${duration.toFixed(2)}ms`);

  tabSwitchStartTime = 0;
}

/**
 * Transform record to option
 *
 * @example
 *   ```ts
 *   const record = {
 *     key1: 'label1',
 *     key2: 'label2'
 *   };
 *   const options = transformRecordToOption(record);
 *   // [
 *   //   { value: 'key1', label: 'label1' },
 *   //   { value: 'key2', label: 'label2' }
 *   // ]
 *   ```;
 *
 * @param record
 */
export function transformRecordToOption<T extends Record<string, string>>(record: T) {
  return Object.entries(record).map(([value, label]) => ({
    value,
    label
  })) as CommonType.Option<keyof T, T[keyof T]>[];
}

/**
 * Translate options
 *
 * @param options
 */
export function translateOptions(options: CommonType.Option<string, App.I18n.I18nKey>[]) {
  return options.map(option => ({
    ...option,
    label: $t(option.label)
  }));
}

/**
 * Toggle html class
 *
 * @param className
 */
export function toggleHtmlClass(className: string) {
  function add() {
    document.documentElement.classList.add(className);
  }

  function remove() {
    document.documentElement.classList.remove(className);
  }

  return {
    add,
    remove
  };
}

/** def_object 下拉选项（可携带上级对象信息实现级联联动） */
export interface LinkedSelectOption {
  label: string;
  value: string;
  /** 上级对象名称（def_object.上级对象名称，对应控制字段的 objectName） */
  parentName?: string;
  /** 上级对象值（def_object.上级对象值，须等于控制字段当前值才可见） */
  parentValue?: string;
  /** 兼容 naive-ui SelectMixedOption 及其它选项扩展字段 */
  [key: string]: any;
}

/** 可参与联动的字段形态（通用工作台表单 / 邀约管理页字段均满足） */
export interface LinkableField {
  objectName?: string;
  objectOptions?: LinkedSelectOption[];
  [key: string]: any;
}

/**
 * def_object 上级对象级联：按控制字段当前值过滤子级选项
 *
 * 选项带 parentName 时，在字段列表中查找 objectName === parentName 的控制字段，
 * 仅保留 parentValue 匹配其当前值（或未声明 parentValue）的选项；
 * 普通下拉（选项无 parentName）原样返回，行为不变。
 *
 * @param field 当前渲染的下拉字段
 * @param fields 同一表单的全部字段配置
 * @param formData 表单数据（取控制字段当前值）
 * @param keyOf 从字段取表单键（通用工作台为 fieldName，邀约管理页为 columnName）
 */
export function filterLinkedOptions(
  field: LinkableField,
  fields: LinkableField[],
  formData: Record<string, any>,
  keyOf: (f: LinkableField) => string
): LinkedSelectOption[] {
  const options = field.objectOptions || [];
  const firstParent = options.find(o => o.parentName);
  if (!firstParent) return options;

  const controlField = fields.find(f => f.objectName === firstParent.parentName);
  if (!controlField) return options;

  const controlValue = formData[keyOf(controlField)];
  return options.filter(o => !o.parentValue || o.parentValue === controlValue);
}

/**
 * def_object 上级对象级联：控制字段变更后清空子级失效值
 *
 * changedKey 为某下拉字段的控制字段时，检查该下拉当前值是否仍在
 * 新控制值下的合法选项中，不在则置空。返回处理后的表单副本。
 *
 * @param fields 同一表单的全部字段配置
 * @param formData 变更前的表单数据
 * @param changedKey 本次变更的表单键（须为控制字段才触发清空）
 * @param changedValue 本次变更后的控制字段值
 * @param keyOf 从字段取表单键
 */
export function clearLinkedOptions(
  fields: LinkableField[],
  formData: Record<string, any>,
  changedKey: string,
  changedValue: any,
  keyOf: (f: LinkableField) => string
): Record<string, any> {
  const next = { ...formData };
  for (const field of fields) {
    const options = field.objectOptions || [];
    const firstParent = options.find(o => o.parentName);
    if (!firstParent) continue;

    const controlField = fields.find(f => f.objectName === firstParent.parentName);
    if (!controlField || keyOf(controlField) !== changedKey) continue;

    const key = keyOf(field);
    const current = next[key];
    if (
      current !== '' &&
      current != null &&
      !options.some(o => o.value === current && (!o.parentValue || o.parentValue === changedValue))
    ) {
      next[key] = '';
    }
  }
  return next;
}
