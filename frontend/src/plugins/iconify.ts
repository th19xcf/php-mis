import { addAPIProvider, addCollection } from '@iconify/vue';

/** Setup the iconify offline */
export function setupIconifyOffline() {
  const { VITE_ICONIFY_URL } = import.meta.env;

  if (VITE_ICONIFY_URL) {
    addAPIProvider('', { resources: [VITE_ICONIFY_URL] });
  }

  // 异步加载常用图标集到本地存储，避免远程 API（api.unisvg.com 等）超时
  // 加载完成后 Iconify 会自动用本地数据渲染，不再发网络请求
  import('@iconify/json/json/mdi.json').then(icons => addCollection((icons as any).default || icons));
  import('@iconify/json/json/ant-design.json').then(icons => addCollection((icons as any).default || icons));
}
