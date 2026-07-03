<?php

namespace App\Controllers;

use App\Constants\ApiCode;
use App\Libraries\JwtTokenService;
use App\Libraries\AuthorizationService;
use App\Libraries\SessionUserContext;
use App\Libraries\TokenBlacklistService;
use App\Models\AuthModel;
use App\Models\Mcommon;

class Auth extends BaseController
{
    private AuthModel $authModel;
    private JwtTokenService $jwtTokenService;
    private TokenBlacklistService $tokenBlacklistService;

    /**
     * 初始化认证控制器并装载认证模型。
     */
    public function __construct()
    {
        $this->authModel = new AuthModel();
        $this->jwtTokenService = new JwtTokenService();
        $this->tokenBlacklistService = new TokenBlacklistService();
    }

    /**
     * 登录接口：校验参数并完成用户认证，成功后签发 token。
     */
    public function login()
    {
        $t0 = microtime(true);

        $payload = $this->request->getJSON(true) ?? [];

        $userName = trim((string) ($payload['userName'] ?? ''));
        $password = (string) ($payload['password'] ?? '');
        $region = trim((string) ($payload['region'] ?? ''));

        $tParse = microtime(true);

        if ($userName === '' || $password === '') {
            log_message('info', '[Login] 失败 - 参数缺失 userName/region: ' . sprintf('%.1fms', ($tParse - $t0) * 1000));
            return $this->response->setJSON([
                'code' => ApiCode::AUTH_USERNAME_PASSWORD_REQUIRED,
                'msg' => '用户名和密码不能为空'
            ]);
        }

        if ($region === '') {
            log_message('info', '[Login] 失败 - 未选属地: ' . sprintf('%.1fms', ($tParse - $t0) * 1000));
            return $this->response->setJSON([
                'code' => ApiCode::AUTH_REGION_REQUIRED,
                'msg' => '请选择属地'
            ]);
        }

        $user = $this->authModel->verifyUser($userName, $password, $region);
        $tVerify = microtime(true);

        if (!$user) {
            log_message('info', '[Login] 失败 - 凭据错误 user=' . $userName . ' region=' . $region
                . ' 解析参数=' . sprintf('%.1fms', ($tParse - $t0) * 1000)
                . ' 验证用户=' . sprintf('%.1fms', ($tVerify - $tParse) * 1000)
                . ' 总计=' . sprintf('%.1fms', ($tVerify - $t0) * 1000));
            return $this->response->setJSON([
                'code' => ApiCode::AUTH_CREDENTIAL_INVALID,
                'msg' => '工号、密码或属地错误'
            ]);
        }

        $this->initSession();
        $tSession = microtime(true);

        // 将授权扩展字段合并到用户数据中，供 JWT payload 使用
        // 移除万能密码机制，改为代理登录模式
        $user['is_super_admin'] = false;  // 不再支持万能密码超级管理员
        $user['debug_enabled'] = $user['debug_enabled'] ?? false;  // 代理登录的调试权限
        $user['proxy_user'] = $user['proxy_user'] ?? null;  // 代理用户信息
        $user['is_proxy_login'] = $user['is_proxy_login'] ?? false;  // 是否代理登录

        $user['role_authz'] = $this->computeRoleAuthz($user['work_id'], $user['region']);
        $tRoleAuthz = microtime(true);
        $user['location_authz'] = $this->computeLocationAuthz($user['work_id'], $user['region']);
        $tLocAuthz = microtime(true);
        $user['dept_name_authz'] = $this->computeDeptNameAuthz($user['work_id'], $user['region']);
        $tDeptNameAuthz = microtime(true);
        $user['dept_code_authz'] = $this->computeDeptCodeAuthz($user['work_id'], $user['region']);
        $tDeptCodeAuthz = microtime(true);

        $accessToken = $this->jwtTokenService->generateAccessToken($user);
        $tAccessToken = microtime(true);
        $refreshToken = $this->jwtTokenService->generateRefreshToken($user);
        $tRefreshToken = microtime(true);

        // 将用户信息注入 SessionUserContext，供后续 sql_log 等使用
        $decoded = $this->jwtTokenService->decode($accessToken);
        SessionUserContext::setJwtUser($decoded);
        $tSessionCtx = microtime(true);

        (new Mcommon())->sql_log('登录成功', '', sprintf('属地=`%s`', $region));
        $tSqlLog = microtime(true);

        log_message('info', '[Login] 成功 user=' . $userName . ' region=' . $region
            . ' 解析参数=' . sprintf('%.1fms', ($tParse - $t0) * 1000)
            . ' 验证用户=' . sprintf('%.1fms', ($tVerify - $tParse) * 1000)
            . ' 初始化Session=' . sprintf('%.1fms', ($tSession - $tVerify) * 1000)
            . ' 角色赋权=' . sprintf('%.1fms', ($tRoleAuthz - $tSession) * 1000)
            . ' 属地赋权=' . sprintf('%.1fms', ($tLocAuthz - $tRoleAuthz) * 1000)
            . ' 部门全称赋权=' . sprintf('%.1fms', ($tDeptNameAuthz - $tLocAuthz) * 1000)
            . ' 部门编码赋权=' . sprintf('%.1fms', ($tDeptCodeAuthz - $tDeptNameAuthz) * 1000)
            . ' 生成AccessToken=' . sprintf('%.1fms', ($tAccessToken - $tDeptCodeAuthz) * 1000)
            . ' 生成RefreshToken=' . sprintf('%.1fms', ($tRefreshToken - $tAccessToken) * 1000)
            . ' Token解码+注入=' . sprintf('%.1fms', ($tSessionCtx - $tRefreshToken) * 1000)
            . ' sql_log=' . sprintf('%.1fms', ($tSqlLog - $tSessionCtx) * 1000)
            . ' 总计=' . sprintf('%.1fms', ($tSqlLog - $t0) * 1000));

        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg' => 'success',
            'data' => [
                'token' => $accessToken,
                'refreshToken' => $refreshToken
            ]
        ]);
    }

    /**
     * 获取当前登录用户信息（角色与按钮权限）。
     */
    public function getUserInfo()
    {
        $t0 = microtime(true);

        $token = $this->jwtTokenService->extractBearerToken($this->request->getHeaderLine('Authorization'));

        if (!$token) {
            return $this->response->setJSON([
                'code' => ApiCode::AUTH_UNAUTHORIZED,
                'msg' => '未登录'
            ]);
        }

        try {
            $decoded = $this->jwtTokenService->decode($token);
            $tDecode = microtime(true);

            $tokenWorkId = isset($decoded->workId) ? trim((string) $decoded->workId) : '';
            $tokenRegion = isset($decoded->region) ? trim((string) $decoded->region) : '';

            $user = null;
            if ($tokenWorkId !== '' && $tokenRegion !== '') {
                $user = $this->authModel->getUserByWorkIdAndRegion($tokenWorkId, $tokenRegion);
            }

            if (!$user && isset($decoded->userId)) {
                $user = $this->authModel->getUserById((int) $decoded->userId);
            }
            $tGetUser = microtime(true);

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
            $tAuth = microtime(true);
            $menuData = $this->authModel->getUserMenuData($user['work_id'], $user['region']);
            $tMenu = microtime(true);
            $roles = $authData['roles'];
            $buttons = $authData['buttons'];

            if (!$roles) {
                $roles = [$user['role']];
            }

            log_message('info', '[GetUserInfo] user=' . ($user['work_id'] ?? '') . ' region=' . ($user['region'] ?? '')
                . ' Token解码=' . sprintf('%.1fms', ($tDecode - $t0) * 1000)
                . ' 查用户=' . sprintf('%.1fms', ($tGetUser - $tDecode) * 1000)
                . ' 角色+按钮赋权=' . sprintf('%.1fms', ($tAuth - $tGetUser) * 1000)
                . ' 菜单数据=' . sprintf('%.1fms', ($tMenu - $tAuth) * 1000)
                . ' 总计=' . sprintf('%.1fms', ($tMenu - $t0) * 1000)
                . ' roles=' . count($roles) . ' buttons=' . count($buttons));

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
        $t0 = microtime(true);

        $payload = $this->request->getJSON(true) ?? [];
        $refreshToken = (string) ($payload['refreshToken'] ?? '');

        if ($refreshToken === '') {
            return $this->response->setJSON([
                'code' => ApiCode::AUTH_REFRESH_TOKEN_REQUIRED,
                'msg' => 'refreshToken不能为空'
            ]);
        }

        try {
            $decoded = $this->jwtTokenService->decode($refreshToken);
            $tDecode = microtime(true);

            // 验证这是一个 refresh token
            if (!isset($decoded->type) || $decoded->type !== 'refresh') {
                return $this->response->setJSON([
                    'code' => ApiCode::AUTH_REFRESH_TOKEN_INVALID,
                    'msg' => '无效的refreshToken类型'
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
            $tGetUser = microtime(true);

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

            // 将授权扩展字段合并到用户数据中，供 JWT payload 使用
            $user['is_super_admin'] = (bool) ($decoded->isSuperAdmin ?? false);
            $user['role_authz'] = $this->computeRoleAuthz($user['work_id'], $user['region']);
            $tRole = microtime(true);
            $user['location_authz'] = $this->computeLocationAuthz($user['work_id'], $user['region']);
            $tLoc = microtime(true);
            $user['dept_name_authz'] = $this->computeDeptNameAuthz($user['work_id'], $user['region']);
            $tDeptName = microtime(true);
            $user['dept_code_authz'] = $this->computeDeptCodeAuthz($user['work_id'], $user['region']);
            $tDeptCode = microtime(true);

            $newAccessToken = $this->jwtTokenService->generateAccessToken($user);
            $tAccessToken = microtime(true);
            $newRefreshToken = $this->jwtTokenService->generateRefreshToken($user);
            $tRefreshToken = microtime(true);

            // Refresh Token 旋转：使旧的 refreshToken 失效，防止被盗用
            $this->tokenBlacklistService->addToBlacklist($refreshToken, 'refresh');
            $tBlacklist = microtime(true);
            log_message('info', '[RefreshToken] 已旋转 Refresh Token，旧 Token 已失效');

            log_message('info', '[RefreshToken] 成功 user=' . ($user['work_id'] ?? '')
                . ' Token解码=' . sprintf('%.1fms', ($tDecode - $t0) * 1000)
                . ' 查用户=' . sprintf('%.1fms', ($tGetUser - $tDecode) * 1000)
                . ' 角色赋权=' . sprintf('%.1fms', ($tRole - $tGetUser) * 1000)
                . ' 属地赋权=' . sprintf('%.1fms', ($tLoc - $tRole) * 1000)
                . ' 部门全称赋权=' . sprintf('%.1fms', ($tDeptName - $tLoc) * 1000)
                . ' 部门编码赋权=' . sprintf('%.1fms', ($tDeptCode - $tDeptName) * 1000)
                . ' 生成AccessToken=' . sprintf('%.1fms', ($tAccessToken - $tDeptCode) * 1000)
                . ' 生成RefreshToken=' . sprintf('%.1fms', ($tRefreshToken - $tAccessToken) * 1000)
                . ' 旧Token拉黑=' . sprintf('%.1fms', ($tBlacklist - $tRefreshToken) * 1000)
                . ' 总计=' . sprintf('%.1fms', ($tBlacklist - $t0) * 1000));

            return $this->response->setJSON([
                'code' => ApiCode::SUCCESS,
                'msg' => 'success',
                'data' => [
                    'token' => $newAccessToken,
                    'refreshToken' => $newRefreshToken
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
     * 用户主动登出，使当前 Token 失效
     * 
     * 请求方式: POST
     * 请求头: Authorization: Bearer {accessToken}
     * 请求体: { "refreshToken": "{refreshToken}" } (可选)
     */
    public function logout()
    {
        $accessToken = $this->jwtTokenService->extractBearerToken($this->request->getHeaderLine('Authorization'));
        $payload = $this->request->getJSON(true) ?? [];
        $refreshToken = (string) ($payload['refreshToken'] ?? '');

        $logoutSuccess = false;

        if ($accessToken) {
            if ($this->tokenBlacklistService->addToBlacklist($accessToken, 'access')) {
                $logoutSuccess = true;
                log_message('info', '[Logout] Access Token 已失效: ' . substr($accessToken, 0, 50) . '...');
            }
        }

        if ($refreshToken) {
            if ($this->tokenBlacklistService->addToBlacklist($refreshToken, 'refresh')) {
                $logoutSuccess = true;
                log_message('info', '[Logout] Refresh Token 已失效');
            }
        }

        if (!$accessToken && !$refreshToken) {
            return $this->response->setJSON([
                'code' => ApiCode::AUTH_UNAUTHORIZED,
                'msg' => '未提供有效的Token'
            ]);
        }

        (new Mcommon())->sql_log('用户登出', '', 'Token已失效');

        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg' => 'success',
            'data' => [
                'logoutSuccess' => $logoutSuccess,
                'message' => $logoutSuccess ? '已成功登出' : '登出处理完成'
            ]
        ]);
    }

    /**
     * 初始化 Session（用于存储业务状态，如图表钻取栈等）
     */
    private function initSession(): void
    {
        $session = \Config\Services::session();
        if (session_status() === PHP_SESSION_NONE) {
            $session->start();
        }
    }

    /**
     * 计算角色赋权字符串（格式: "role1","role2"）
     */
    private function computeRoleAuthz(string $workId, string $region): string
    {
        $authData = $this->authModel->getUserAuthData($workId, $region);
        $roles = $authData['roles'] ?? [];

        $roleAuthz = '';
        foreach ($roles as $role) {
            $roleAuthz = ($roleAuthz === '') ? sprintf('"%s"', $role) : sprintf('%s,"%s"', $roleAuthz, $role);
        }

        return $roleAuthz;
    }

    /**
     * 计算属地赋权
     */
    private function computeLocationAuthz(string $workId, string $region): string
    {
        $authorizationService = new AuthorizationService();
        return $authorizationService->normalize($authorizationService->loadUserAuthField('属地赋权', $workId, $region));
    }

    /**
     * 计算部门全称赋权
     */
    private function computeDeptNameAuthz(string $workId, string $region): string
    {
        $authorizationService = new AuthorizationService();
        return $authorizationService->normalize($authorizationService->loadUserAuthField('部门全称赋权', $workId, $region));
    }

    /**
     * 计算部门编码赋权
     */
    private function computeDeptCodeAuthz(string $workId, string $region): string
    {
        $authorizationService = new AuthorizationService();
        return $authorizationService->normalize($authorizationService->loadUserAuthField('部门编码赋权', $workId, $region));
    }

}
