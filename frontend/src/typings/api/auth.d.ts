declare namespace Api {
  /**
   * namespace Auth
   *
   * backend api module: "auth"
   */
  namespace Auth {
    interface LoginToken {
      token: string;
      refreshToken: string;
    }

    interface UserInfo {
      userId: string;
      userName: string;
      roles: string[];
      buttons: string[];
      /** 调试权限（从 JWT payload 读取），用于前端差异化错误展示 */
      debugEnabled?: boolean;
    }
  }
}
