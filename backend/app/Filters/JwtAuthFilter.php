<?php

namespace App\Filters;

use App\Constants\ApiCode;
use App\Libraries\JwtTokenService;
use App\Libraries\SessionUserContext;
use App\Libraries\TokenBlacklistService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class JwtAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        // 优化：通过 Services 共享实例避免每请求重复构造（file 驱动触发目录检查，redis 驱动触发 Predis 连接）
        $jwtTokenService = service('jwtTokenService');
        $tokenBlacklistService = service('tokenBlacklistService');

        $token = $jwtTokenService->extractBearerToken($request->getHeaderLine('Authorization'));

        if ($token === null) {
            return service('response')->setJSON([
                'code' => ApiCode::AUTH_UNAUTHORIZED,
                'msg' => '未登录'
            ]);
        }

        try {
            $decoded = $jwtTokenService->decode($token);

            $tokenType = $decoded->type ?? 'access';

            // 优化：传入已解码的 jti，避免 isBlacklisted 内部二次 decode（原实现每请求解码 2 次）
            if ($tokenBlacklistService->isBlacklisted($token, $tokenType, $decoded->jti ?? null)) {
                return service('response')->setJSON([
                    'code' => ApiCode::AUTH_TOKEN_EXPIRED,
                    'msg' => 'token已失效，请重新登录'
                ]);
            }
        } catch (\Throwable $e) {
            return service('response')->setJSON([
                'code' => ApiCode::AUTH_TOKEN_EXPIRED,
                'msg' => 'token无效或已过期'
            ]);
        }

        // JWT 验证通过，将用户信息注入 SessionUserContext
        SessionUserContext::setJwtUser($decoded);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}