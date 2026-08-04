import type { CustomRoute, ElegantConstRoute, ElegantRoute } from '@elegant-router/types';
import { generatedRoutes } from '../elegant/routes';
import { layouts, views } from '../elegant/imports';
import { transformElegantRoutesToVueRoutes } from '../elegant/transform';

/**
 * custom routes
 *
 * @link https://github.com/soybeanjs/elegant-router?tab=readme-ov-file#custom-route
 */
const customRoutes: CustomRoute[] = [];

/**
 * 后端驱动的业务路由清单
 *
 * 这些路由对应的页面组件存在于 src/views/ 下，但不再注册为独立路由，
 * 而是统一通过 menu-bridge 组件加载（由后端 def_function 表的
 * 「前端路由」字段驱动 menu-bridge 内部的 nativeComponentMap 分发）。
 * menu-bridge 提供 .bridge-content-region 作为 position:relative 定位上下文。
 */
const backendDrivenRouteNames = new Set<string>([
  'contract',
  'contract-v2',
  'match-data',
  'workflow-manage',
  'personnel'
]);

/** create routes when the auth route mode is static */
export function createStaticRoutes() {
  const constantRoutes: ElegantRoute[] = [];

  const authRoutes: ElegantRoute[] = [];

  [...customRoutes, ...generatedRoutes].forEach(item => {
    // 后端驱动的业务路由：跳过，不注册到 vue-router，由 createBackendMenuRoutes 驱动
    if (backendDrivenRouteNames.has(item.name)) {
      return;
    }

    const route: ElegantRoute = {
      ...item,
      meta: {
        ...item.meta,
        title: item.meta?.title || String(item.name)
      }
    };

    // 一级菜单图标补充
    const menuIconMap: Record<string, string> = {
      system: 'mdi:cog-outline',
      info: 'mdi:database',
      income: 'mdi:cash',
      analysis: 'mdi:chart-line',
      'permission-demo': 'mdi:shield-account'
    };
    // 兼容中文 title
    const zhTitleIconMap: Record<string, string> = {
      数据匹配: 'mdi:merge',
      首页: 'mdi:monitor-dashboard',
      系统管理: 'mdi:cog-outline',
      管理信息: 'mdi:database',
      人员管理: 'mdi:account-heart',
      收入成本: 'mdi:cash',
      经营分析: 'mdi:chart-line',
      合同管理: 'mdi:file-sign',
      房产租赁: 'mdi:home-variant-outline',
      财务管理: 'mdi:wallet'
    };
    if (route.name && menuIconMap[route.name]) {
      if (!route.meta) {
        route.meta = {
          title: String(route.name)
        };
      }
      route.meta.icon = menuIconMap[route.name];
    } else if (route.meta?.title && zhTitleIconMap[route.meta.title]) {
      route.meta.icon = zhTitleIconMap[route.meta.title];
    }

    // 隐藏通用页面、动态菜单宿主、权限演示菜单（辅助路由，非业务菜单）
    if (route.name === 'common' || route.name === 'menu-bridge' || route.name === 'permission-demo') {
      route.meta = {
        ...route.meta,
        title: route.meta?.title || String(route.name),
        hideInMenu: true
      };
    }

    if (route.name === 'system') {
      route.meta = {
        ...route.meta,
        title: route.meta?.title || 'system',
        i18nKey: 'route.system',
        icon: 'mdi:cog-outline',
        order: 3,
        roles: ['R_ADMIN']
      };

      route.children?.forEach(child => {
        child.meta = {
          ...child.meta,
          title: child.meta?.title || String(child.name)
        };

        if (child.name === 'system_user') {
          child.meta = {
            ...child.meta,
            title: child.meta.title || 'system_user',
            i18nKey: 'route.system_user',
            icon: 'mdi:account-multiple-outline',
            order: 1,
            roles: ['R_ADMIN']
          };
        }

        if (child.name === 'system_role') {
          child.meta = {
            ...child.meta,
            title: child.meta.title || 'system_role',
            i18nKey: 'route.system_role',
            icon: 'mdi:badge-account-outline',
            order: 2,
            roles: ['R_SUPER', 'R_ADMIN']
          };
        }
      });
    }

    if (route.meta?.constant) {
      constantRoutes.push(route);
    } else {
      authRoutes.push(route);
    }
  });

  return {
    constantRoutes,
    authRoutes
  };
}

/**
 * Get auth vue routes
 *
 * @param routes Elegant routes
 */
export function getAuthVueRoutes(routes: ElegantConstRoute[]) {
  return transformElegantRoutesToVueRoutes(routes, layouts, views);
}
