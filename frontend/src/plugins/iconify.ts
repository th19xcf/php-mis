import { addCollection, _api } from '@iconify/vue';
import type { IconifyAPIModule } from '@iconify/vue';
import offlineIcons from './offline-icons-data.json';

/**
 * 注入项目内嵌的离线图标集合，并通过自定义 API module 完全阻断 iconify
 * 对外网（api.iconify.design / api.unisvg.com / api.simplesvg.com）的请求，
 * 避免在无外网/弱外网环境下登录或路由切换后被外网超时拖慢首屏。
 *
 * @iconify/vue 5.0.0 在模块加载时已经把默认 provider 注入到 configStorage['']，
 * 单纯 addAPIProvider('', { resources: [] }) 会被 createAPIConfig 直接拒绝。
 * 因此采用 setAPIModule 覆盖默认 API module 的方式：
 *   - prepare 永远返回空数组（不生成查询参数）
 *   - send 收到请求后立即以 "abort" 回调，iconify 内部会标记图标为 missing
 */
const NOOP_API_MODULE: IconifyAPIModule = {
  prepare: (_provider, _prefix, _icons) => [],
  send: (_host, _params, callback) => {
    setTimeout(() => callback('abort', 424), 0);
  }
};

export function setupIconifyOffline() {
  const { VITE_ICONIFY_URL } = import.meta.env;

  if (!VITE_ICONIFY_URL) {
    // 未配置内网代理：用 stub module 覆盖默认外网 API module，彻底不发外网请求
    _api.setAPIModule('', NOOP_API_MODULE);
  }
  // 如果配置了 VITE_ICONIFY_URL（内网 iconify 代理），保持默认行为让 iconify 自己请求

  // 注入扫描到的离线图标集合
  Object.values(offlineIcons).forEach(iconSet => {
    addCollection(iconSet as any);
  });
}

