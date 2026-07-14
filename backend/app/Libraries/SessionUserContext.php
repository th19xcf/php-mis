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
        if (self::$jwtUser !== null) {
            return $this->mapJwtUserToSessionFormat(self::$jwtUser);
        }

        throw new AuthException('登录态已失效，请重新登录');
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
        return $this->getSessionUser()['workId'] ?? '';
    }

    /**
     * 获取当前用户姓名
     */
    public function getUserName(): string
    {
        return $this->getSessionUser()['userName'] ?? '';
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
     * 获取当前用户属地
     */
    public function getLocation(): string
    {
        return $this->getSessionUser()['location'] ?? '';
    }

    /**
     * 获取当前用户部门编码
     */
    public function getDeptCode(): string
    {
        return $this->getSessionUser()['deptCode'] ?? '';
    }

    /**
     * 获取当前用户部门名称
     */
    public function getDeptName(): string
    {
        return $this->getSessionUser()['deptName'] ?? '';
    }

    /**
     * 获取当前用户日志开关
     */
    public function getLogSwitch(): bool
    {
        return (bool) ($this->getSessionUser()['logSwitch'] ?? true);
    }

    /**
     * 当前用户是否开启调试权限（JWT payload debugEnabled）
     *
     * 用于控制 X-Server-Trace 等含敏感信息（SQL 结构）的诊断输出
     */
    public function isDebugEnabled(): bool
    {
        try {
            return (bool) ($this->getSessionUser()['debugEnabled'] ?? false);
        } catch (AuthException $e) {
            // 未登录场景（如登录接口本身），保守返回 false
            return false;
        }
    }

    /**
     * 将 JWT payload 映射为统一的用户信息格式
     */
    private function mapJwtUserToSessionFormat(object $jwt): array
    {
        return [
            'companyId' => trim((string) ($jwt->region ?? '')),
            'userId' => trim((string) ($jwt->userId ?? '')),
            'workId' => trim((string) ($jwt->workId ?? '')),
            'userName' => trim((string) ($jwt->userName ?? '')),
            'isSuperAdmin' => false,
            'debugEnabled' => (bool) ($jwt->debugEnabled ?? false),
            'proxyUser' => $jwt->proxyUser ?? null,
            'isProxyLogin' => (bool) ($jwt->isProxyLogin ?? false),
            'location' => trim((string) ($jwt->region ?? '')),
            'deptCode' => trim((string) ($jwt->deptCode ?? '')),
            'deptName' => trim((string) ($jwt->deptName ?? '')),
            'role' => trim((string) ($jwt->roleAuthz ?? $jwt->role ?? '')),
            'roleAuthz' => trim((string) ($jwt->roleAuthz ?? '')),
            'deptAuthz' => trim((string) ($jwt->deptCodeAuthz ?? '')),
            'locationAuthz' => trim((string) ($jwt->locationAuthz ?? '')),
            'logSwitch' => (bool) ($jwt->logSwitch ?? true),
        ];
    }
}
