import type { AxiosResponse } from 'axios';
import { BACKEND_ERROR_CODE, createFlatRequest, createRequest } from '@sa/axios';
import { useAuthStore } from '@/store/modules/auth';
import { localStg } from '@/utils/storage';
import { getServiceBaseURL } from '@/utils/service';
import { $t } from '@/locales';
import { SERVICE_CODE_CONFIG } from '@/constants/service-code';
import { getAuthorization, handleExpiredRequest, showErrorMsg } from './shared';
import type { RequestInstanceState } from './type';
import { markTrace } from '@/utils/performance-trace';

const isHttpProxy = import.meta.env.DEV && import.meta.env.VITE_HTTP_PROXY === 'Y';
const { baseURL, otherBaseURL } = getServiceBaseURL(import.meta.env, isHttpProxy);
const apifoxToken = import.meta.env.VITE_APIFOX_TOKEN?.trim();
const defaultHeaders = apifoxToken ? { apifoxToken } : {};

export const request = createFlatRequest(
  {
    baseURL,
    headers: defaultHeaders,
    timeout: 60000 // 60秒超时（MySQL冷连接时首次请求可能较慢）
  },
  {
    defaultState: {
      errMsgStack: [],
      refreshTokenPromise: null
    } as RequestInstanceState,
    transform(response: AxiosResponse<App.Service.Response<any>>) {
      return response.data.data;
    },
    async onRequest(config) {
      const Authorization = getAuthorization();

      // 生成并透传 traceId，便于前后端日志串联
      const traceId = `trace-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
      Object.assign(config.headers, { Authorization, 'X-Request-Id': traceId });

      // 将 traceId 暂存到 config 上，供后续错误处理使用
      (config as any).__traceId = traceId;

      // 性能追踪：标记 API 请求发起
      const url = config.url || '';
      markTrace(`API请求发起: ${url}`);

      return config;
    },
    isBackendSuccess(response) {
      // when the backend response code is "0000"(default), it means the request is success
      // to change this logic by yourself, you can modify the `VITE_SERVICE_SUCCESS_CODE` in `.env` file
      const isSuccess = String(response.data.code) === SERVICE_CODE_CONFIG.successCode;

      // 性能追踪：标记 API 响应接收 + 后端分段耗时
      if (isSuccess) {
        const url = response.config?.url || '';
        markTrace(`API响应接收: ${url}`);

        const serverTrace = response.headers?.['x-server-trace'];
        if (serverTrace) {
          try {
            const traceData = typeof serverTrace === 'string' ? JSON.parse(serverTrace) : serverTrace;
            const labelMap: Record<string, string> = {
              requireLogin: '用户认证',
              contextBuild: '上下文构建',
              contextCacheHit: '上下文缓存',
              loadUserAuthorization: '用户授权查询',
              loadFunctionAuthorization: '功能授权查询',
              loadQueryConfig: '查询配置加载',
              loadColumns: '列配置加载',
              queryTotal: 'COUNT统计',
              queryRecords: '数据查询',
              total: '服务端总耗时'
            };
            Object.entries(traceData as Record<string, number | boolean | Array<{sql: string; ms: number}>>).forEach(([key, value]) => {
              const label = labelMap[key] || key;
              if (typeof value === 'number') {
                markTrace(`  ↳ ${label}: ${value.toFixed(2)}ms`);
              } else if (typeof value === 'boolean') {
                markTrace(`  ↳ ${label}: ${value ? '命中' : '未命中'}`);
              } else if (key === 'sqlTrace' && Array.isArray(value)) {
                markTrace(`  ↳ SQL执行追踪 (${value.length}条):`);
                value.forEach((item, idx) => {
                  // base64 解码 UTF-8 字符串
                  let sqlText = item.sql;
                  try {
                    const binary = atob(item.sql);
                    const bytes = new Uint8Array(binary.length);
                    for (let i = 0; i < binary.length; i++) {
                      bytes[i] = binary.charCodeAt(i);
                    }
                    sqlText = new TextDecoder('utf-8').decode(bytes);
                  } catch {
                    // 解码失败则使用原始值
                  }
                  const sqlPreview = sqlText.replace(/\s+/g, ' ').slice(0, 80);
                  markTrace(`    ${idx + 1}. ${item.ms.toFixed(2)}ms | ${sqlPreview}...`);
                });
              }
            });
          } catch {
            // 解析失败忽略
          }
        }
      }

      return isSuccess;
    },
    async onBackendFail(response, instance) {
      const authStore = useAuthStore();
      const responseCode = String(response.data.code);

      // 写接口（add/update/delete/batch/submit/upkeep/reset/import）已在 config
      // 上设置 `skipAuthError: true`，表示允许后端把业务校验错误复用
      // logout/modalLogout 业务码而不强制登出。详见
      // src/service/api/workbench.ts 顶部说明。
      const skipAuthError =
        (response.config as unknown as { skipAuthError?: boolean } | undefined)?.skipAuthError === true;
      if (skipAuthError) {
        return null;
      }

      function handleLogout() {
        authStore.resetStore();
      }

      function logoutAndCleanup() {
        handleLogout();
        window.removeEventListener('beforeunload', handleLogout);

        request.state.errMsgStack = request.state.errMsgStack.filter(msg => msg !== response.data.msg);
      }

      // when the backend response code is in `logoutCodes`, it means the user will be logged out and redirected to login page
      if (SERVICE_CODE_CONFIG.logoutCodes.includes(responseCode)) {
        handleLogout();
        return null;
      }

      // when the backend response code is in `modalLogoutCodes`, it means the user will be logged out by displaying a modal
      if (
        SERVICE_CODE_CONFIG.modalLogoutCodes.includes(responseCode) &&
        !request.state.errMsgStack?.includes(response.data.msg)
      ) {
        request.state.errMsgStack = [...(request.state.errMsgStack || []), response.data.msg];

        // prevent the user from refreshing the page
        window.addEventListener('beforeunload', handleLogout);

        window.$dialog?.error({
          title: $t('common.error'),
          content: response.data.msg,
          positiveText: $t('common.confirm'),
          maskClosable: false,
          closeOnEsc: false,
          onPositiveClick() {
            logoutAndCleanup();
          },
          onClose() {
            logoutAndCleanup();
          }
        });

        return null;
      }

      // when the backend response code is in `expiredTokenCodes`, it means the token is expired, and refresh token
      // the api `refreshToken` can not return error code in `expiredTokenCodes`, otherwise it will be a dead loop, should return `logoutCodes` or `modalLogoutCodes`
      if (SERVICE_CODE_CONFIG.expiredTokenCodes.includes(responseCode)) {
        const success = await handleExpiredRequest(request.state);
        if (success) {
          const Authorization = getAuthorization();
          Object.assign(response.config.headers, { Authorization });

          return instance.request(response.config) as Promise<AxiosResponse>;
        }
      }

      return null;
    },
    onError(error) {
      // when the request is fail, you can show error message

      let message = error.message;
      let backendErrorCode = '';

      // 获取 traceId：优先从请求 config 取，其次从响应头取
      const traceId = (error.config as any)?.__traceId || error.response?.headers?.['x-request-id'] || 'unknown';

      // get backend error message and code
      if (error.code === BACKEND_ERROR_CODE) {
        message = error.response?.data?.msg || message;
        backendErrorCode = String(error.response?.data?.code || '');
      }

      // the error message is displayed in the modal
      if (SERVICE_CODE_CONFIG.modalLogoutCodes.includes(backendErrorCode)) {
        return;
      }

      // when the token is expired, refresh token and retry request, so no need to show error message
      if (SERVICE_CODE_CONFIG.expiredTokenCodes.includes(backendErrorCode)) {
        return;
      }

      // 开发环境打印 traceId，便于前后端日志串联定位
      if (import.meta.env.DEV) {
        // eslint-disable-next-line no-console
        console.error(`[TraceId: ${traceId}] 请求错误: ${message}`);
      }

      showErrorMsg(request.state, message);
    }
  }
);

export const demoRequest = createRequest(
  {
    baseURL: otherBaseURL.demo
  },
  {
    transform(response: AxiosResponse<App.Service.DemoResponse>) {
      return response.data.result;
    },
    async onRequest(config) {
      const { headers } = config;

      // set token
      const token = localStg.get('token');
      const Authorization = token ? `Bearer ${token}` : null;
      Object.assign(headers, { Authorization });

      return config;
    },
    isBackendSuccess(response) {
      // when the backend response code is "200", it means the request is success
      // you can change this logic by yourself
      return response.data.status === '200';
    },
    async onBackendFail(_response) {
      // when the backend response code is not "200", it means the request is fail
      // for example: the token is expired, refresh token and retry request
    },
    onError(error) {
      // when the request is fail, you can show error message

      let message = error.message;

      // show backend error message
      if (error.code === BACKEND_ERROR_CODE) {
        message = error.response?.data?.message || message;
      }

      window.$message?.error(message);
    }
  }
);
