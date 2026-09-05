<?php

namespace Tests\Unit;

use App\Libraries\SessionUserContext;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

class SessionUserContextTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        // setJwtUser 不接受 null（公开 API 无法清空），静态态需 Reflection 复位，防跨用例污染
        $ref = new \ReflectionProperty(SessionUserContext::class, 'jwtUser');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        Services::reset();
        parent::tearDown();
    }

    public function testRequireLoginThrowsWhenSessionMissing(): void
    {
        $this->expectException(\RuntimeException::class);

        $context = new SessionUserContext();
        $context->requireLogin();
    }

    public function testRequireLoginThrowsWhenJwtWorkIdEmpty(): void
    {
        // JWT 注入后 workId 为空：仍视为登录态失效（requireLogin 的防御分支）
        $this->expectException(\RuntimeException::class);

        SessionUserContext::setJwtUser((object) [
            'region' => 'SZ',
            'workId' => '',
        ]);

        $context = new SessionUserContext();
        $context->requireLogin();
    }

    public function testGetSessionUserReturnsNormalizedFields(): void
    {
        SessionUserContext::setJwtUser((object) [
            'region'        => 'SZ',
            'userId'        => '1',
            'workId'        => 'A001',
            'userName'      => 'tester',
            'debugEnabled'  => true,
            'deptCode'      => 'D01',
            'deptName'      => '研发部',
            'roleAuthz'     => 'R_ADMIN',
            'deptCodeAuthz' => 'D01|D02',
            'locationAuthz' => 'SZ|BJ',
            'logSwitch'     => false,
        ]);

        $context = new SessionUserContext();
        $user = $context->requireLogin();

        $this->assertSame('SZ', $user['companyId']);
        $this->assertSame('1', $user['userId']);
        $this->assertSame('A001', $user['workId']);
        $this->assertSame('tester', $user['userName']);
        $this->assertSame('R_ADMIN', $user['role']);
        $this->assertSame('R_ADMIN', $user['roleAuthz']);
        $this->assertSame('D01|D02', $user['deptAuthz']);
        $this->assertSame('SZ|BJ', $user['locationAuthz']);
        $this->assertSame('SZ', $user['location']);
        $this->assertSame('D01', $user['deptCode']);
        $this->assertSame('研发部', $user['deptName']);
        $this->assertTrue($user['debugEnabled']);
        $this->assertFalse($user['logSwitch']);
        $this->assertFalse($user['isSuperAdmin']);
        $this->assertNull($user['proxyUser']);
        $this->assertFalse($user['isProxyLogin']);
    }

    public function testRoleFallsBackToJwtRoleWhenRoleAuthzMissing(): void
    {
        // roleAuthz 缺省时回退 $jwt->role（映射表达式 $jwt->roleAuthz ?? $jwt->role）
        SessionUserContext::setJwtUser((object) [
            'region' => 'SZ',
            'workId' => 'A001',
            'role'   => 'R_FALLBACK',
        ]);

        $user = (new SessionUserContext())->requireLogin();

        $this->assertSame('R_FALLBACK', $user['role']);
        $this->assertSame('', $user['roleAuthz']);
    }

    public function testFieldDefaultsWhenJwtPayloadMinimal(): void
    {
        SessionUserContext::setJwtUser((object) [
            'region' => 'SZ',
            'workId' => 'A001',
        ]);

        $user = (new SessionUserContext())->requireLogin();

        $this->assertSame('', $user['userName']);
        $this->assertSame('', $user['deptAuthz']);
        $this->assertSame('', $user['locationAuthz']);
        $this->assertFalse($user['debugEnabled']);
        $this->assertTrue($user['logSwitch']);   // logSwitch 缺省 true
    }

    public function testAccessorsReadFromJwtUser(): void
    {
        SessionUserContext::setJwtUser((object) [
            'region'   => 'HB',
            'workId'   => 'B002',
            'userName' => 'someone',
            'deptCode' => 'D99',
        ]);

        $context = new SessionUserContext();

        $this->assertSame('B002', $context->getWorkId());
        $this->assertSame('someone', $context->getUserName());
        $this->assertSame('HB', $context->getLocation());
        $this->assertSame('D99', $context->getDeptCode());
        $this->assertSame('', $context->getDeptAuthz());
    }

    public function testIsDebugEnabledReturnsFalseWhenNotLoggedIn(): void
    {
        // 未登录场景（如登录接口本身）：保守返回 false，不抛异常
        $context = new SessionUserContext();
        $this->assertFalse($context->isDebugEnabled());
    }

    public function testIsDebugEnabledFollowsJwtPayload(): void
    {
        SessionUserContext::setJwtUser((object) [
            'region'       => 'SZ',
            'workId'       => 'A001',
            'debugEnabled' => true,
        ]);
        $this->assertTrue((new SessionUserContext())->isDebugEnabled());
    }
}
