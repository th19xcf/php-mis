<?php

namespace App\Libraries;

use App\Exceptions\AuthException;

class SessionUserContext
{
    private static ?object $jwtUser = null;

    /**
     * 由 JwtAuthFilter 调用，注入当前请求的 JWT 用户数据
     */
    public static function setJwtUser(object $user): void
    {
        self::$jwtUser = $user;
    }

    public function getSessionValue(string $key, $default = null)
    {
        $value = \Config\Services::session()->get($key);

        return $value ?? $default;
    }

    public function getSessionUser(): array
    {
        // 优先从 JWT 获取用户信息
        if (self::$jwtUser !== null) {
            return $this->mapJwtUserToSessionFormat(self::$jwtUser);
        }

        // 兜底：从 Session 获取
        return $this->readFromSession();
    }

    public function requireLogin(): array
    {
        $user = $this->getSessionUser();

        if ($user['companyId'] === '' || $user['workId'] === '') {
            throw new AuthException('登录态已失效，请重新登录');
        }

        return $user;
    }

    /**
     * 获取当前用户工号
     */
    public function getWorkId(): string
    {
        return $this->getSessionUser()['workId'] ?? 'system';
    }

    /**
     * 获取当前用户姓名
     */
    public function getUserName(): string
    {
        return $this->getSessionUser()['userName'] ?? 'system';
    }

    /**
     * 获取当前用户部门赋权
     */
    public function getDeptAuthz(): string
    {
        return $this->getSessionUser()['deptAuthz'] ?? '';
    }

    /**
     * 获取当前用户属地赋权
     */
    public function getLocationAuthz(): string
    {
        return $this->getSessionUser()['locationAuthz'] ?? '';
    }

    /**
     * 将 JWT payload 映射为与 Session 一致的用户信息格式
     */
    private function mapJwtUserToSessionFormat(object $jwt): array
    {
        return [
            'companyId' => trim((string) ($jwt->region ?? '')),
            'userId' => trim((string) ($jwt->userId ?? '')),
            'workId' => trim((string) ($jwt->workId ?? '')),
            'userName' => trim((string) ($jwt->userName ?? '')),
            'isSuperAdmin' => false,  // 不再支持万能密码超级管理员
            'debugEnabled' => (bool) ($jwt->debugEnabled ?? false),  // 代理登录调试权限
            'proxyUser' => $jwt->proxyUser ?? null,  // 代理用户信息
            'isProxyLogin' => (bool) ($jwt->isProxyLogin ?? false),  // 是否代理登录
            'location' => trim((string) ($jwt->region ?? '')),
            'deptCode' => trim((string) ($jwt->deptCode ?? '')),
            'deptName' => trim((string) ($jwt->deptName ?? '')),
            'role' => trim((string) ($jwt->roleAuthz ?? $jwt->role ?? '')),
            'roleAuthz' => trim((string) ($jwt->roleAuthz ?? '')),
            'deptAuthz' => trim((string) ($jwt->deptCodeAuthz ?? '')),
            'locationAuthz' => trim((string) ($jwt->locationAuthz ?? '')),
        ];
    }

    /**
     * 从 Session 读取用户信息（兜底逻辑）
     */
    private function readFromSession(): array
    {
        $session = \Config\Services::session();

        return [
            'companyId' => trim((string) $session->get('company_id')),
            'userId' => trim((string) $session->get('user_id')),
            'workId' => trim((string) $session->get('user_workid')),
            'userName' => trim((string) $session->get('user_name')),
            'isSuperAdmin' => false,  // 不再支持万能密码超级管理员
            'debugEnabled' => (bool) $session->get('debug_enabled'),  // 代理登录调试权限
            'proxyUser' => $session->get('proxy_user'),  // 代理用户信息
            'isProxyLogin' => (bool) $session->get('is_proxy_login'),  // 是否代理登录
            'location' => trim((string) $session->get('user_location')),
            'deptCode' => trim((string) $session->get('user_dept_code')),
            'deptName' => trim((string) $session->get('user_dept_name')),
            'role' => trim((string) $session->get('user_role')),
            'roleAuthz' => trim((string) $session->get('user_role_authz')),
            'deptAuthz' => trim((string) $session->get('user_dept_code_authz')),
            'locationAuthz' => trim((string) $session->get('user_location_authz')),
        ];
    }
}
