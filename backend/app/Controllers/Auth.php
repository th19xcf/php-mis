<?php

namespace App\Controllers;

use App\Constants\ApiCode;
use App\Models\AuthModel;
use App\Models\Mcommon;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Auth extends BaseController
{
    private AuthModel $authModel;

    /**
     * 初始化认证控制器并装载认证模型。
     */
    public function __construct()
    {
        $this->authModel = new AuthModel();
    }

    /**
     * 登录接口：校验参数并完成用户认证，成功后签发 token。
     */
    public function login()
    {
        $payload = $this->request->getJSON(true) ?? [];

        $userName = trim((string) ($payload['userName'] ?? ''));
        $password = (string) ($payload['password'] ?? '');
        $region = trim((string) ($payload['region'] ?? ''));

        if ($userName === '' || $password === '') {
            return $this->response->setJSON([
                'code' => ApiCode::AUTH_USERNAME_PASSWORD_REQUIRED,
                'msg' => '用户名和密码不能为空'
            ]);
        }

        if ($region === '') {
            return $this->response->setJSON([
                'code' => ApiCode::AUTH_REGION_REQUIRED,
                'msg' => '请选择属地'
            ]);
        }

        $user = null;

        if ($this->isLocalhostRequest() && $userName === 'debug' && $password === 'debug123') {
            $user = $this->buildLocalDebugUser($region);
        }

        if (!$user) {
            $user = $this->tryDebugLogin($userName, $password, $region);
        }

        if (!$user) {
            $user = $this->authModel->verifyUser($userName, $password, $region);
        }

        if (!$user) {
            return $this->response->setJSON([
                'code' => ApiCode::AUTH_CREDENTIAL_INVALID,
                'msg' => '工号、密码或属地错误'
            ]);
        }

        $this->storeLegacySession($user, $password, $region);
        (new Mcommon())->sql_log('登录成功', '', sprintf('属地=`%s`', $region));

        $token = $this->generateToken($user);

        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg' => 'success',
            'data' => [
                'token' => $token,
                'refreshToken' => $token
            ]
        ]);
    }

    /**
     * 获取当前登录用户信息（角色与按钮权限）。
     */
    public function getUserInfo()
    {
        $token = $this->getBearerToken();

        if (!$token) {
            return $this->response->setJSON([
                'code' => ApiCode::AUTH_UNAUTHORIZED,
                'msg' => '未登录'
            ]);
        }

        try {
            $decoded = JWT::decode($token, new Key($this->getJwtSecret(), 'HS256'));

            if (!empty($decoded->isDebug)) {
                return $this->response->setJSON([
                    'code' => ApiCode::SUCCESS,
                    'msg' => 'success',
                    'data' => $this->buildDebugUserInfo($decoded)
                ]);
            }

            $tokenWorkId = isset($decoded->workId) ? trim((string) $decoded->workId) : '';
            $tokenRegion = isset($decoded->region) ? trim((string) $decoded->region) : '';

            $user = null;
            if ($tokenWorkId !== '' && $tokenRegion !== '') {
                $user = $this->authModel->getUserByWorkIdAndRegion($tokenWorkId, $tokenRegion);
            }

            if (!$user && isset($decoded->userId)) {
                $user = $this->authModel->getUserById((int) $decoded->userId);
            }

            if (!$user) {
                return $this->response->setJSON([
                    'code' => ApiCode::AUTH_UNAUTHORIZED,
                    'msg' => '用户不存在'
                ]);
            }

            if ($tokenWorkId !== '' && $user['work_id'] !== $tokenWorkId) {
                return $this->response->setJSON([
                    'code' => ApiCode::AUTH_UNAUTHORIZED,
                    'msg' => '用户信息不匹配，请重新登录'
                ]);
            }

            $authData = $this->authModel->getUserAuthData($user['work_id'], $user['region']);
            $menuData = $this->authModel->getUserMenuData($user['work_id'], $user['region']);
            $roles = $authData['roles'];
            $buttons = $authData['buttons'];

            if (!$roles) {
                $roles = [$user['role']];
            }

            return $this->response->setJSON([
                'code' => ApiCode::SUCCESS,
                'msg' => 'success',
                'data' => [
                    'userId' => (string) $user['id'],
                    'userName' => $user['user_name'],
                    'roles' => $roles,
                    'buttons' => $buttons,
                    'region' => $user['region'],
                    'menuLevel1' => $menuData['level1'],
                    'menuLevel2' => $menuData['level2'],
                    'menus' => $menuData['menus']
                ]
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'code' => ApiCode::AUTH_TOKEN_EXPIRED,
                'msg' => 'token无效或已过期'
            ]);
        }
    }

    /**
     * 使用 refreshToken 续签 token。
     */
    public function refreshToken()
    {
        $payload = $this->request->getJSON(true) ?? [];
        $refreshToken = (string) ($payload['refreshToken'] ?? '');

        if ($refreshToken === '') {
            return $this->response->setJSON([
                'code' => ApiCode::AUTH_REFRESH_TOKEN_REQUIRED,
                'msg' => 'refreshToken不能为空'
            ]);
        }

        try {
            $decoded = JWT::decode($refreshToken, new Key($this->getJwtSecret(), 'HS256'));

            $tokenWorkId = isset($decoded->workId) ? trim((string) $decoded->workId) : '';
            $tokenRegion = isset($decoded->region) ? trim((string) $decoded->region) : '';

            $user = null;
            if ($tokenWorkId !== '' && $tokenRegion !== '') {
                $user = $this->authModel->getUserByWorkIdAndRegion($tokenWorkId, $tokenRegion);
            }

            if (!$user && isset($decoded->userId)) {
                $user = $this->authModel->getUserById((int) $decoded->userId);
            }

            if (!$user) {
                return $this->response->setJSON([
                    'code' => ApiCode::AUTH_REFRESH_TOKEN_INVALID,
                    'msg' => '用户不存在'
                ]);
            }

            if ($tokenWorkId !== '' && $user['work_id'] !== $tokenWorkId) {
                return $this->response->setJSON([
                    'code' => ApiCode::AUTH_REFRESH_TOKEN_INVALID,
                    'msg' => '用户信息不匹配，请重新登录'
                ]);
            }

            $newToken = $this->generateToken($user);

            return $this->response->setJSON([
                'code' => ApiCode::SUCCESS,
                'msg' => 'success',
                'data' => [
                    'token' => $newToken,
                    'refreshToken' => $newToken
                ]
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'code' => ApiCode::AUTH_REFRESH_TOKEN_INVALID,
                'msg' => 'refreshToken无效或已过期'
            ]);
        }
    }

    /**
     * 生成 JWT 访问令牌。
     */
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
            'role' => $user['role'],
            'region' => $user['region'],
            'workId' => $user['work_id']
        ];

        if (!empty($user['is_debug'])) {
            $payload['isDebug'] = 1;
            $payload['debugRoles'] = $user['debug_roles'] ?? [];
            $payload['debugButtons'] = $user['debug_buttons'] ?? [];
        }

        return JWT::encode($payload, $this->getJwtSecret(), 'HS256');
    }

    /**
     * 调试登录入口：支持环境开关和绑定真实账号权限。
     */
    private function tryDebugLogin(string $userName, string $password, string $region): ?array
    {
        $enabled = filter_var((string) env('AUTH_DEBUG_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
        $localhostFallback = $this->isLocalhostRequest()
            && $userName === 'debug'
            && $password === 'debug123';

        if (!$enabled && !$localhostFallback) {
            return null;
        }

        $debugUser = trim((string) env('AUTH_DEBUG_USER', 'debug'));
        $debugPass = (string) env('AUTH_DEBUG_PASS', 'debug123');
        $debugRegion = trim((string) env('AUTH_DEBUG_REGION', ''));

        if ($userName !== $debugUser || $password !== $debugPass) {
            return null;
        }

        if ($debugRegion !== '' && $region !== $debugRegion) {
            return null;
        }

        $bindWorkId = trim((string) env('AUTH_DEBUG_BIND_WORK_ID', ''));
        $bindUser = null;

        if ($bindWorkId !== '') {
            $bindUser = $this->authModel->getUserByWorkIdAndRegion($bindWorkId, $region);
        }

        $roles = $this->parseCsv((string) env('AUTH_DEBUG_ROLES', 'R_SUPER'));
        $buttons = $this->parseCsv((string) env('AUTH_DEBUG_BUTTONS', ''));

        if ($bindUser) {
            $authData = $this->authModel->getUserAuthData($bindUser['work_id'], $bindUser['region']);
            if (!empty($authData['roles'])) {
                $roles = $authData['roles'];
            }
            if (!empty($authData['buttons'])) {
                $buttons = $authData['buttons'];
            }
        }

        return [
            'id' => $bindUser['id'] ?? 0,
            'user_name' => $bindUser['user_name'] ?? 'Debug User',
            'work_id' => $bindUser['work_id'] ?? ($bindWorkId !== '' ? $bindWorkId : $debugUser),
            'role' => $roles[0] ?? 'R_SUPER',
            'region' => $region,
            'dept_code' => $bindUser['dept_code'] ?? '',
            'dept_name' => $bindUser['dept_name'] ?? '',
            'log_switch' => true,
            'buttons' => $buttons,
            'is_debug' => true,
            'debug_roles' => $roles,
            'debug_buttons' => $buttons
        ];
    }

    /**
     * 组装调试账号 getUserInfo 返回结构。
     *
     * @param object $decoded
     *
    * @return array{userId: string, userName: string, roles: array, buttons: array, region: string, menuLevel1: array, menuLevel2: array, menus: array}
     */
    private function buildDebugUserInfo(object $decoded): array
    {
        $workId = isset($decoded->workId) ? trim((string) $decoded->workId) : '';
        $region = isset($decoded->region) ? trim((string) $decoded->region) : '';

        $roles = [];
        $buttons = [];
        $menuData = [
            'level1' => [],
            'level2' => [],
            'menus' => []
        ];

        if ($workId !== '' && $region !== '') {
            $authData = $this->authModel->getUserAuthData($workId, $region);
            $roles = $authData['roles'];
            $buttons = $authData['buttons'];
            $menuData = $this->authModel->getUserMenuData($workId, $region);
        }

        if (!$roles) {
            $roles = $this->normalizeDecodedArray($decoded->debugRoles ?? []);
        }

        if (!$buttons) {
            $buttons = $this->normalizeDecodedArray($decoded->debugButtons ?? []);
        }

        if (!$roles) {
            $roles = ['R_SUPER'];
        }

        return [
            'userId' => isset($decoded->userId) ? (string) $decoded->userId : '0',
            'userName' => isset($decoded->userName) ? (string) $decoded->userName : 'Debug User',
            'roles' => $roles,
            'buttons' => $buttons,
            'region' => $region,
            'menuLevel1' => $menuData['level1'],
            'menuLevel2' => $menuData['level2'],
            'menus' => $menuData['menus']
        ];
    }

    /**
     * 规范化 token 中的数组或逗号字符串为去重字符串数组。
     *
     * @param mixed $value
     *
     * @return string[]
     */
    private function normalizeDecodedArray($value): array
    {
        if (is_array($value)) {
            $items = $value;
        } elseif ($value instanceof \Traversable) {
            $items = iterator_to_array($value, false);
        } elseif (is_string($value)) {
            $items = explode(',', $value);
        } else {
            $items = [];
        }

        $result = [];
        foreach ($items as $item) {
            $text = trim((string) $item);
            if ($text !== '') {
                $result[] = $text;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * 解析逗号分隔文本并返回去重后的权限编码数组。
     *
     * @param string $text
     *
     * @return string[]
     */
    private function parseCsv(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', $text));
        $parts = array_filter($parts, static fn(string $item): bool => $item !== '');

        return array_values(array_unique($parts));
    }

    /**
     * 构建本地调试账号的基础用户信息。
     */
    private function buildLocalDebugUser(string $region): array
    {
        $roles = $this->parseCsv((string) env('AUTH_DEBUG_ROLES', 'R_SUPER,R_ADMIN'));
        $buttons = $this->parseCsv((string) env('AUTH_DEBUG_BUTTONS', 'dashboard:view,system:user:add,system:user:edit'));

        return [
            'id' => 0,
            'user_name' => 'Debug User',
            'work_id' => 'debug',
            'role' => $roles[0] ?? 'R_SUPER',
            'region' => $region,
            'dept_code' => '',
            'dept_name' => '',
            'log_switch' => true,
            'buttons' => $buttons,
            'is_debug' => true,
            'debug_roles' => $roles,
            'debug_buttons' => $buttons
        ];
    }

    /**
     * 判断请求是否来自本机（localhost/127.0.0.1/::1）。
     */
    private function isLocalhostRequest(): bool
    {
        $host = strtolower((string) $this->request->getServer('HTTP_HOST'));
        $ip = (string) $this->request->getIPAddress();

        if ($host !== '' && str_contains($host, 'localhost')) {
            return true;
        }

        return in_array($ip, ['127.0.0.1', '::1'], true);
    }

    /**
     * 写入兼容旧系统的会话字段。
     */
    private function storeLegacySession(array $user, string $password, string $region): void
    {
        $session = \Config\Services::session();
        
        // 获取用户角色列表
        $authData = $this->authModel->getUserAuthData($user['work_id'], $user['region']);
        $roles = $authData['roles'] ?? [];
        
        // 构建角色编码字符串（用于SQL in条件）
        $userRoleAuthz = '';
        foreach ($roles as $role) {
            $userRoleAuthz = ($userRoleAuthz === '') ? sprintf('"%s"', $role) : sprintf('%s,"%s"', $userRoleAuthz, $role);
        }
        
        $session->set([
            'company_id' => $region,
            'user_id' => $user['id'],
            'user_workid' => $user['work_id'],
            'user_name' => $user['user_name'],
            'user_pswd' => $password,
            'user_location' => $user['region'],
            'user_dept_code' => $user['dept_code'],
            'user_dept_name' => $user['dept_name'],
            'log_switch' => $user['log_switch'],
            'user_role' => $userRoleAuthz,  // 用于权限检查
            'user_role_authz' => $userRoleAuthz  // 兼容旧版字段名
        ]);
    }

    /**
     * 从 Authorization 头中提取 Bearer Token。
     */
    private function getBearerToken(): ?string
    {
        $header = $this->request->getHeaderLine('Authorization');

        if ($header === '' || strpos($header, 'Bearer ') !== 0) {
            return null;
        }

        return substr($header, 7);
    }

    /**
     * 获取 JWT 签名密钥（优先环境变量）。
     */
    private function getJwtSecret(): string
    {
        return (string) env('JWT_SECRET', 'mis-jwt-secret-key-dev-only-change-in-production');
    }
}
