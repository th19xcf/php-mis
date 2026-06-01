import { request } from '../request';

/**
 * Login
 *
 * @param userName User name
 * @param password Password
 * @param region Region
 */
export function fetchLogin(userName: string, password: string, region?: string) {
  return request<Api.Auth.LoginToken>({
    url: '/auth/login',
    method: 'post',
    data: {
      userName,
      password,
      region
    }
  });
}

/** Get user info */
export function fetchGetUserInfo() {
  return request<Api.Auth.UserInfo>({ url: '/auth/getUserInfo' });
}

/**
 * Refresh token
 *
 * @param refreshToken Refresh token
 */
export function fetchRefreshToken(refreshToken: string) {
  return request<Api.Auth.LoginToken>({
    url: '/auth/refreshToken',
    method: 'post',
    data: {
      refreshToken
    }
  });
}

/**
 * Logout
 * Invalidate current access token and refresh token
 *
 * @param refreshToken Refresh token (optional, will invalidate if provided)
 */
export function fetchLogout(refreshToken?: string) {
  return request<{ logoutSuccess: boolean; message: string }>({
    url: '/auth/logout',
    method: 'post',
    data: {
      ...(refreshToken && { refreshToken })
    }
  });
}

/**
 * return custom backend error
 *
 * @param code error code
 * @param msg error message
 */
export function fetchCustomBackendError(code: string, msg: string) {
  return request({ url: '/auth/error', params: { code, msg } });
}
