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
        $jwtTokenService = new JwtTokenService();
        $tokenBlacklistService = new TokenBlacklistService();
        
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
            
            if ($tokenBlacklistService->isBlacklisted($token, $tokenType)) {
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