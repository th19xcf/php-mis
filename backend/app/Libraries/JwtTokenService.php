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
}