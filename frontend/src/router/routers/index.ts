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
    const route = { ...item };

    if (route.name === 'permission-demo') {
      route.meta = {
        ...route.meta,
        i18nKey: 'route.permission-demo',
        icon: 'mdi:shield-account',
        order: 2,
        roles: ['R_ADMIN']
      };
    }

    if (route.name === 'system') {
      route.meta = {
        ...route.meta,
        i18nKey: 'route.system',
        icon: 'mdi:cog-outline',
        order: 3,
        roles: ['R_ADMIN']
      };
    }

    if (route.name === 'system_user') {
      route.meta = {
        ...route.meta,
        i18nKey: 'route.system_user',
        icon: 'mdi:account-multiple-outline',
        order: 1,
        roles: ['R_ADMIN']
      };
    }

    if (route.name === 'system_role') {
      route.meta = {
        ...route.meta,
        i18nKey: 'route.system_role',
        icon: 'mdi:badge-account-outline',
        order: 2,
        roles: ['R_SUPER', 'R_ADMIN']
      };
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
