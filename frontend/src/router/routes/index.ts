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

/** create routes when the auth route mode is static */
export function createStaticRoutes() {
  const constantRoutes: ElegantRoute[] = [];

  const authRoutes: ElegantRoute[] = [];

  [...customRoutes, ...generatedRoutes].forEach(item => {
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
      personnel: 'mdi:account-heart',
      income: 'mdi:cash',
      analysis: 'mdi:chart-line',
      'permission-demo': 'mdi:shield-account'
    };
    // 兼容中文 title
    const zhTitleIconMap: Record<string, string> = {
      系统管理: 'mdi:cog-outline',
      管理信息: 'mdi:database',
      人员管理: 'mdi:account-heart',
      收入成本: 'mdi:cash',
      经营分析: 'mdi:chart-line'
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

    // 隐藏通用页面、动态菜单、权限演示菜单
    if (route.name === 'common' || route.name === 'menu-bridge' || route.name === 'permission-demo') {
      route.meta = {
        ...route.meta,
        title: route.meta?.title || String(route.name),
        hideInMenu: true
      };
    }

    // 隐藏与后端动态菜单重复的静态路由（合同管理、人员管理）
    if (route.name === 'contract' || route.name === 'personnel') {
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
