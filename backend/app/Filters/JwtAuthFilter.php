<?php

namespace App\Filters;

use App\Constants\ApiCode;
use App\Libraries\JwtTokenService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class JwtAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $jwtTokenService = new JwtTokenService();
        $token = $jwtTokenService->extractBearerToken($request->getHeaderLine('Authorization'));

        if ($token === null) {
            return service('response')->setJSON([
                'code' => ApiCode::AUTH_UNAUTHORIZED,
                'msg' => '未登录'
            ]);
        }

        try {
            $jwtTokenService->decode($token);
        } catch (\Throwable $e) {
            return service('response')->setJSON([
                'code' => ApiCode::AUTH_TOKEN_EXPIRED,
                'msg' => 'token无效或已过期'
            ]);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}