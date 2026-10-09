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
      /** 用户部门编码（从 JWT payload 读取） */
      deptCode?: string;
      /** 用户部门名称（从 JWT payload 读取） */
      deptName?: string;
      /** 调试权限（从 JWT payload 读取），用于前端差异化错误展示 */
      debugEnabled?: boolean;
    }
  }
}
