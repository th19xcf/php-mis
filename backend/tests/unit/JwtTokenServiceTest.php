<?php

namespace Tests\Unit;

use App\Libraries\JwtTokenService;
use CodeIgniter\Test\CIUnitTestCase;

class JwtTokenServiceTest extends CIUnitTestCase
{
    public function testExtractBearerToken(): void
    {
        $service = new JwtTokenService();

        $this->assertSame('abc123', $service->extractBearerToken('Bearer abc123'));
        $this->assertNull($service->extractBearerToken(''));
        $this->assertNull($service->extractBearerToken('Basic abc123'));
    }

    public function testEncodeAndDecodeToken(): void
    {
        $service = new JwtTokenService();
        $payload = [
            'userId' => 1,
            'userName' => 'tester',
            'iat' => time(),
            'exp' => time() + 3600
        ];

        $token = $service->encode($payload);
        $decoded = $service->decode($token);

        $this->assertSame(1, (int) $decoded->userId);
        $this->assertSame('tester', (string) $decoded->userName);
    }
}