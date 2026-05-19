import { $t } from '@/locales';

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
  console.log(
    `[🔀 switchTab] 切换完成: ${tabSwitchLabel}, 当前动作用时: ${duration.toFixed(2)}ms, 总用时: ${duration.toFixed(2)}ms`
  );
  
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
