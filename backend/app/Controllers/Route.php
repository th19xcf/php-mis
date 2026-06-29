<?php

namespace App\Controllers;

use App\Exceptions\AuthException;
use App\Services\RouteService;

class Route extends BaseApiController
{
    private RouteService $routeService;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ) {
        parent::initController($request, $response, $logger);
        $this->routeService = new RouteService();
    }

    public function getUserRoutes()
    {
        $authHeader = $this->request->getHeaderLine('Authorization');
        if (empty($authHeader)) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        }

        if (empty($authHeader)) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, 'Token required');
        }

        try {
            $user = $this->userContext->requireLogin();
        } catch (AuthException $e) {
            return $this->error(ApiCode::AUTH_TOKEN_EXPIRED, 'Session expired, please login again');
        }

        try {
            // 1. 查询用户权限
            $permissions = $this->routeService->loadUserPermissions(
                $user['companyId'],
                $user['workId']
            );

            // 2. 构建菜单树
            $menuList = $this->routeService->buildMenuList($permissions['user_role_authz'] ?? '');

            return $this->success([
                'routes' => $menuList,
                'home' => 'home'
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Exception in getUserRoutes: ' . $e->getMessage());
            return $this->serverError('Error: ' . $e->getMessage());
        }
    }

    public function getConstantRoutes()
    {
        return $this->success([
            [
                'name' => 'root',
                'path' => '/',
                'redirect' => '/home',
                'meta' => ['title' => 'Root']
            ],
            [
                'name' => 'login',
                'path' => '/login',
                'component' => 'layout.blank$view.login',
                'meta' => ['title' => '登录', 'constant' => true, 'keepAlive' => false]
            ],
            [
                'name' => '403',
                'path' => '/403',
                'component' => 'view.403',
                'meta' => ['title' => '403', 'constant' => true]
            ],
            [
                'name' => '404',
                'path' => '/404',
                'component' => 'view.404',
                'meta' => ['title' => '404', 'constant' => true]
            ],
            [
                'name' => '500',
                'path' => '/500',
                'component' => 'view.500',
                'meta' => ['title' => '500', 'constant' => true]
            ]
        ]);
    }

    public function isRouteExist()
    {
        $routeName = $this->request->getGet('routeName');

        if (empty($routeName)) {
            return $this->error(4000, 'Invalid request');
        }

        return $this->success(true);
    }
}
