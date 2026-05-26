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
            'type' => 'access',
            'userId' => $user['id'],
            'userName' => $user['user_name'],
            'role' => $user['role'],
            'region' => $user['region'],
            'workId' => $user['work_id']
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
            'type' => 'refresh',
            'userId' => $user['id'],
            'userName' => $user['user_name'],
            'role' => $user['role'],
            'region' => $user['region'],
            'workId' => $user['work_id']
        ];

        return $this->encode($payload);
    }
}