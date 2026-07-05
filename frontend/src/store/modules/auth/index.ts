import { computed, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { defineStore } from 'pinia';
import { useLoading } from '@sa/hooks';
import { fetchGetUserInfo, fetchLogin } from '@/service/api';
import { useRouterPush } from '@/hooks/common/router';
import { localStg } from '@/utils/storage';
import { SetupStoreId } from '@/enum';
import { $t } from '@/locales';
import { useRouteStore } from '../route';
import { useTabStore } from '../tab';
import { clearAuthStorage, clearWorkbenchCache, getToken } from './shared';

interface ResetStoreOptions {
  /** 是否清理业务缓存（globalTabs / lastLoginUserId）。主动退出时传 true，被动登出保持 false */
  clearBusinessCache?: boolean;
}

interface PerfStep {
  name: string;
  time: number;
}

function buildPerformanceTable(tag: string, info: string, steps: PerfStep[], t0: number): void {
  const total = steps[steps.length - 1].time - t0;

  const rows: {
    index: number;
    step: string;
    timestamp: string;
    duration: string;
    pct: string;
    rawDuration: number;
  }[] = [];

  let prevTime = t0;
  steps.forEach((step, idx) => {
    const duration = step.time - prevTime;
    const timestamp = (step.time - t0).toFixed(1);
    const pct = total > 0 ? ((duration / total) * 100).toFixed(1) : '0.0';

    rows.push({
      index: idx,
      step: step.name,
      timestamp: timestamp,
      duration: `+${duration.toFixed(1)}ms`,
      pct: `${pct}%`,
      rawDuration: duration
    });
    prevTime = step.time;
  });

  rows.push({
    index: rows.length,
    step: '总计',
    timestamp: total.toFixed(1),
    duration: `${total.toFixed(1)}ms`,
    pct: '100%',
    rawDuration: total
  });

  console.log(`%c[性能追踪] [${tag}] ${info} 总耗时: ${total.toFixed(2)}ms`, 'color: #3b82f6; font-weight: bold');

  const header = `%c${pad('(索引)', 8)} | ${pad('step', 20)} | ${pad('timestamp', 10)} | ${pad('duration', 10)} | ${pad('pct', 6)}`;
  console.log(header, 'color: #64748b;');

  console.log('%c' + '-'.repeat(60), 'color: #475569;');

  rows.forEach(row => {
    const line = `${pad(row.index.toString(), 8)} | ${pad(row.step, 20)} | ${pad(row.timestamp, 10)} | ${pad(row.duration, 10)} | ${pad(row.pct, 6)}`;
    console.log(line);
  });

  const sorted = [...rows].sort((a, b) => b.rawDuration - a.rawDuration).filter(r => r.rawDuration > 0.001);

  console.log('');
  console.log('%c耗时排行（从慢到快）', 'color: #ef4444; font-weight: bold');

  const maxBar = 50;
  const maxDur = sorted[0]?.rawDuration || 1;

  sorted.forEach((row, idx) => {
    const barLen = maxDur > 0 ? Math.max(1, Math.floor((row.rawDuration / maxDur) * maxBar)) : 1;
    const bar = '█'.repeat(barLen);
    const line = ` %d. ${pad(row.step, 20)} ${row.rawDuration.toFixed(2).padStart(8)}ms ${bar}`;
    console.log(line.replace('%d', (idx + 1).toString()));
  });
}

function pad(str: string, len: number): string {
  return str.padEnd(len);
}

export const useAuthStore = defineStore(SetupStoreId.Auth, () => {
  const route = useRoute();
  const routeStore = useRouteStore();
  const tabStore = useTabStore();
  const { toLogin, redirectFromLogin } = useRouterPush(false);
  const { loading: loginLoading, startLoading, endLoading } = useLoading();

  const token = ref('');

  const userInfo: Api.Auth.UserInfo = reactive({
    userId: '',
    userName: '',
    roles: [],
    buttons: []
  });

  /** is super role in static route */
  const isStaticSuper = computed(() => {
    const { VITE_AUTH_ROUTE_MODE, VITE_STATIC_SUPER_ROLE } = import.meta.env;

    return VITE_AUTH_ROUTE_MODE === 'static' && userInfo.roles.includes(VITE_STATIC_SUPER_ROLE);
  });

  /** Is login */
  const isLogin = computed(() => Boolean(token.value));

  /** Reset auth store */
  async function resetStore(options?: ResetStoreOptions) {
    const shouldClearBusinessCache = options?.clearBusinessCache === true;

    recordUserId();

    clearAuthStorage();

    // 主动退出时清理业务缓存（globalTabs / lastLoginUserId）
    // 被动登出（token 失效）保留，便于重新登录后恢复现场
    if (shouldClearBusinessCache) {
      clearWorkbenchCache();
    }

    token.value = '';
    userInfo.userId = '';
    userInfo.userName = '';
    userInfo.roles = [];
    userInfo.buttons = [];

    if (!route.meta.constant) {
      await toLogin();
    }

    tabStore.cacheTabs();
    routeStore.resetStore();
  }

  /** Record the user ID of the previous login session Used to compare with the current user ID on next login */
  function recordUserId() {
    if (!userInfo.userId) {
      return;
    }

    // Store current user ID locally for next login comparison
    localStg.set('lastLoginUserId', userInfo.userId);
  }

  /**
   * Check if current login user is different from previous login user If different, clear all tabs
   *
   * @returns {boolean} Whether to clear all tabs
   */
  function checkTabClear(): boolean {
    if (!userInfo.userId) {
      return false;
    }

    const lastLoginUserId = localStg.get('lastLoginUserId');

    // 换号登录：清理业务缓存（globalTabs / lastLoginUserId）+ 清空 Pinia tabs
    if (!lastLoginUserId || lastLoginUserId !== userInfo.userId) {
      clearWorkbenchCache();
      tabStore.clearTabs();
      return true;
    }

    localStg.remove('lastLoginUserId');
    return false;
  }

  /**
   * Login
   *
   * @param userName User name
   * @param password Password
   * @param region Region
   * @param [redirect=true] Whether to redirect after login. Default is `true`
   */
  async function login(userName: string, password: string, region?: string, redirect = true) {
    const t0 = performance.now();
    startLoading();

    const { data: loginToken, error } = await fetchLogin(userName, password, region);
    const tFetchLogin = performance.now();

    if (!error) {
      const pass = await loginByToken(loginToken);
      const tLoginByToken = performance.now();

      if (pass) {
        // Check if the tab needs to be cleared
        const isClear = checkTabClear();
        const tTabClear = performance.now();
        let needRedirect = redirect;

        if (isClear) {
          // If the tab needs to be cleared,it means we don't need to redirect.
          needRedirect = false;
        }
        await redirectFromLogin(needRedirect);
        const tRedirect = performance.now();

        window.$notification?.success({
          title: $t('page.login.common.loginSuccess'),
          content: $t('page.login.common.welcomeBack', { userName: userInfo.userName }),
          duration: 4500
        });

        const steps: PerfStep[] = [
          { name: 'fetchLogin', time: tFetchLogin },
          { name: 'loginByToken', time: tLoginByToken },
          { name: 'checkTabClear', time: tTabClear },
          { name: 'redirectFromLogin', time: tRedirect }
        ];
        buildPerformanceTable('Login', `user=${userName}`, steps, t0);
      }
    } else {
      resetStore();
    }

    endLoading();
  }

  async function loginByToken(loginToken: Api.Auth.LoginToken) {
    // 1. stored in the localStorage, the later requests need it in headers
    const t0 = performance.now();
    localStg.set('token', loginToken.token);
    localStg.set('refreshToken', loginToken.refreshToken);
    const tStorage = performance.now();

    // 2. get user info
    const pass = await getUserInfo();
    const tUserInfo = performance.now();

    if (pass) {
      token.value = loginToken.token;
      const steps: PerfStep[] = [
        { name: '存储token', time: tStorage },
        { name: 'getUserInfo', time: tUserInfo }
      ];
      buildPerformanceTable('loginByToken', '', steps, t0);
      return true;
    }

    return false;
  }

  async function getUserInfo() {
    const { data: info, error } = await fetchGetUserInfo();

    if (!error) {
      // update store
      Object.assign(userInfo, info);

      return true;
    }

    return false;
  }

  async function initUserInfo() {
    const maybeToken = getToken();

    if (maybeToken) {
      token.value = maybeToken;
      const pass = await getUserInfo();

      if (!pass) {
        resetStore();
      }
    }
  }

  return {
    token,
    userInfo,
    isStaticSuper,
    isLogin,
    loginLoading,
    resetStore,
    login,
    initUserInfo
  };
});
