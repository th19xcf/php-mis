import { h } from 'vue';
import type { TreeOption } from 'naive-ui';

/**
 * 人员页树节点图标渲染器工厂。
 * 各页面传入 type -> icon 的映射即可得到 NTree 用的 renderPrefix 回调。
 *
 * @example
 *   const renderPrefix = usePersonnelTreeIcon({
 *     root: '👥', region: '🏢', person: '👤'
 *   });
 */
export function usePersonnelTreeIcon(iconMap: Record<string, string>) {
  return function renderPrefix({ option }: { option: TreeOption }) {
    const data = (option.data || {}) as { type?: string };
    const icon = data.type ? iconMap[data.type] : undefined;
    return h('span', { class: 'mr-1' }, icon || '📄');
  };
}
