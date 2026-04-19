<?php

namespace App\Controllers;

use App\Models\AuthModel;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Auth extends BaseController
{
    private AuthModel $authModel;

    public function __construct()
    {
        $this->authModel = new AuthModel();
    }

    public function login()
    {
        $payload = $this->request->getJSON(true) ?? [];

        $userName = trim((string) ($payload['userName'] ?? ''));
        $password = (string) ($payload['password'] ?? '');

        if ($userName === '' || $password === '') {
            return $this->response->setJSON([
                'code' => '1001',
                'msg' => '用户名和密码不能为空'
            ]);
        }

        $user = $this->authModel->verifyUser($userName, $password);

        if (!$user) {
            return $this->response->setJSON([
                'code' => '1003',
                'msg' => '用户名或密码错误'
            ]);
        }

        $token = $this->generateToken($user);

        return $this->response->setJSON([
            'code' => '0000',
            'msg' => 'success',
            'data' => [
                'token' => $token,
                'refreshToken' => $token
            ]
        ]);
    }

    public function getUserInfo()
    {
        $token = $this->getBearerToken();

        if (!$token) {
            return $this->response->setJSON([
                'code' => '8888',
                'msg' => '未登录'
            ]);
        }

        try {
            $decoded = JWT::decode($token, new Key($this->getJwtSecret(), 'HS256'));
            $user = $this->authModel->getUserById((int) $decoded->userId);

            if (!$user) {
                return $this->response->setJSON([
                    'code' => '8888',
                    'msg' => '用户不存在'
                ]);
            }

            return $this->response->setJSON([
                'code' => '0000',
                'msg' => 'success',
                'data' => [
                    'userId' => (string) $user['id'],
                    'userName' => $user['user_name'],
                    'roles' => [$user['role']],
                    'buttons' => $user['buttons']
                ]
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'code' => '9999',
                'msg' => 'token无效或已过期'
            ]);
        }
    }

    public function refreshToken()
    {
        $payload = $this->request->getJSON(true) ?? [];
        $refreshToken = (string) ($payload['refreshToken'] ?? '');

        if ($refreshToken === '') {
            return $this->response->setJSON([
                'code' => '1007',
                'msg' => 'refreshToken不能为空'
            ]);
        }

        try {
            $decoded = JWT::decode($refreshToken, new Key($this->getJwtSecret(), 'HS256'));
            $user = $this->authModel->getUserById((int) $decoded->userId);

            if (!$user) {
                return $this->response->setJSON([
                    'code' => '8889',
                    'msg' => '用户不存在'
                ]);
            }

            $newToken = $this->generateToken($user);

            return $this->response->setJSON([
                'code' => '0000',
                'msg' => 'success',
                'data' => [
                    'token' => $newToken,
                    'refreshToken' => $newToken
                ]
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'code' => '8889',
                'msg' => 'refreshToken无效或已过期'
            ]);
        }
    }

    private function generateToken(array $user): string
    {
        $issuedAt = time();
        $expireAt = $issuedAt + 3600 * 24 * 7;

        $payload = [
            'iss' => 'mis-system',
            'aud' => 'mis-client',
            'iat' => $issuedAt,
            'exp' => $expireAt,
            'userId' => $user['id'],
            'userName' => $user['user_name'],
            'role' => $user['role']
        ];

        return JWT::encode($payload, $this->getJwtSecret(), 'HS256');
    }

    private function getBearerToken(): ?string
    {
        $header = $this->request->getHeaderLine('Authorization');

        if ($header === '' || strpos($header, 'Bearer ') !== 0) {
            return null;
        }

        return substr($header, 7);
    }

    private function getJwtSecret(): string
    {
        return getenv('JWT_SECRET') ?: 'mis-jwt-secret-key-dev-only-change-in-production';
    }
}
