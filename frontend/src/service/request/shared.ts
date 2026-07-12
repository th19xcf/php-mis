import { useAuthStore } from '@/store/modules/auth';
import { localStg } from '@/utils/storage';
import { fetchRefreshToken } from '../api';
import type { RequestInstanceState } from './type';

export function getAuthorization() {
  const token = localStg.get('token');
  const Authorization = token ? `Bearer ${token}` : null;

  return Authorization;
}

/** refresh token */
async function handleRefreshToken() {
  const { resetStore } = useAuthStore();

  const rToken = localStg.get('refreshToken') || '';
  const { error, data } = await fetchRefreshToken(rToken);
  if (!error) {
    localStg.set('token', data.token);
    localStg.set('refreshToken', data.refreshToken);
    return true;
  }

  resetStore();

  return false;
}

export async function handleExpiredRequest(state: RequestInstanceState) {
  if (!state.refreshTokenPromise) {
    state.refreshTokenPromise = handleRefreshToken();
  }

  const success = await state.refreshTokenPromise;

  setTimeout(() => {
    state.refreshTokenPromise = null;
  }, 1000);

  return success;
}

export function showErrorMsg(state: RequestInstanceState, message: string, traceId?: string) {
  if (!state.errMsgStack?.length) {
    state.errMsgStack = [];
  }

  // 追加 traceId 便于用户上报错误，后端可据此快速定位请求链路
  const fullMessage = traceId && traceId !== 'unknown' ? `${message}\n[trace: ${traceId}]` : message;

  const isExist = state.errMsgStack.includes(fullMessage);

  if (!isExist) {
    state.errMsgStack.push(fullMessage);

    window.$message?.error(fullMessage, {
      onLeave: () => {
        state.errMsgStack = state.errMsgStack.filter(msg => msg !== fullMessage);

        setTimeout(() => {
          state.errMsgStack = [];
        }, 5000);
      }
    });
  }
}
