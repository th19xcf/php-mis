<?php

namespace App\Libraries;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtTokenService
{
    public function encode(array $payload): string
    {
        return JWT::encode($payload, $this->getSecret(), 'HS256');
    }

    public function decode(string $token): object
    {
        return JWT::decode($token, new Key($this->getSecret(), 'HS256'));
    }

    public function extractBearerToken(string $authorizationHeader): ?string
    {
        if ($authorizationHeader === '' || strpos($authorizationHeader, 'Bearer ') !== 0) {
            return null;
        }

        return substr($authorizationHeader, 7);
    }

    public function getSecret(): string
    {
        return (string) env('JWT_SECRET', 'mis-jwt-secret-key-dev-only-change-in-production');
    }

    /**
     * 生成唯一的 JTI (JWT ID)
     */
    public function generateJti(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * 生成访问令牌 (Access Token)
     * 有效期较短，用于日常 API 访问
     */
    public function generateAccessToken(array $user): string
    {
        $issuedAt = time();
        $expireAt = $issuedAt + 3600 * 2; // 2小时

        $payload = [
            'iss' => 'mis-system',
            'aud' => 'mis-client',
            'iat' => $issuedAt,
            'exp' => $expireAt,
            'jti' => $this->generateJti(),
            'type' => 'access',
            'userId' => $user['id'],
            'userName' => $user['user_name'],
            'role' => $user['role'],
            'region' => $user['region'],
            'workId' => $user['work_id'],
            'deptCode' => $user['dept_code'] ?? '',
            'deptName' => $user['dept_name'] ?? '',
            'isSuperAdmin' => false,  // 不再支持万能密码超级管理员
            'debugEnabled' => $user['debug_enabled'] ?? false,  // 代理登录调试权限
            'proxyUser' => $user['proxy_user'] ?? null,  // 代理用户信息
            'isProxyLogin' => $user['is_proxy_login'] ?? false,  // 是否代理登录
            'roleAuthz' => $user['role_authz'] ?? '',
            'locationAuthz' => $user['location_authz'] ?? '',
            'deptNameAuthz' => $user['dept_name_authz'] ?? '',
            'deptCodeAuthz' => $user['dept_code_authz'] ?? '',
        ];

        return $this->encode($payload);
    }

    /**
     * 生成刷新令牌 (Refresh Token)
     * 有效期较长，用于刷新访问令牌
     */
    public function generateRefreshToken(array $user): string
    {
        $issuedAt = time();
        $expireAt = $issuedAt + 3600 * 24 * 7; // 7天

        $payload = [
            'iss' => 'mis-system',
            'aud' => 'mis-client',
            'iat' => $issuedAt,
            'exp' => $expireAt,
            'jti' => $this->generateJti(),
            'type' => 'refresh',
            'userId' => $user['id'],
            'userName' => $user['user_name'],
            'role' => $user['role'],
            'region' => $user['region'],
            'workId' => $user['work_id'],
            'deptCode' => $user['dept_code'] ?? '',
            'deptName' => $user['dept_name'] ?? '',
            'isSuperAdmin' => false,  // 不再支持万能密码超级管理员
            'debugEnabled' => $user['debug_enabled'] ?? false,  // 代理登录调试权限
            'proxyUser' => $user['proxy_user'] ?? null,  // 代理用户信息
            'isProxyLogin' => $user['is_proxy_login'] ?? false,  // 是否代理登录
            'roleAuthz' => $user['role_authz'] ?? '',
            'locationAuthz' => $user['location_authz'] ?? '',
            'deptNameAuthz' => $user['dept_name_authz'] ?? '',
            'deptCodeAuthz' => $user['dept_code_authz'] ?? '',
        ];

        return $this->encode($payload);
    }

    /**
     * 从 Token 中提取 JTI
     */
    public function extractJti(string $token): ?string
    {
        try {
            $decoded = $this->decode($token);
            return $decoded->jti ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}