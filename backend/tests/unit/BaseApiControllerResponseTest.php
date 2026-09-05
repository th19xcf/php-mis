<?php

namespace Tests\Unit;

use App\Libraries\SessionUserContext;
use App\Models\Mcommon;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\ResetsStaticState;
use Tests\Support\TestableBaseApiController;

/**
 * BaseApiController 响应族特征测试（重构安全网）
 *
 * 锁定 success/error 族、参数校验族、请求解析族、用户上下文委托的当前行为。
 * 全程无数据库：success() 中 Mcommon::getSqlTrace() 为静态数组读，无副作用。
 */
class BaseApiControllerResponseTest extends CIUnitTestCase
{
    use ResetsStaticState;

    protected function tearDown(): void
    {
        $this->resetStaticState();
        parent::tearDown();
    }

    private function makeController(?string $body = null, array $headers = []): TestableBaseApiController
    {
        return TestableBaseApiController::make($body, $headers);
    }

    // ---- success() ----

    public function testSuccessBasicJsonShape(): void
    {
        $c = $this->makeController(null, ['X-Request-Id' => 'trace-fixed12345678']);

        $response = $c->successEx(['k' => 'v']);

        $body = json_decode($response->getJSON(), true);
        $this->assertSame('0000', $body['code']);
        $this->assertSame('Success', $body['msg']);
        $this->assertSame(['k' => 'v'], $body['data']);
        // 透传请求头 traceId
        $this->assertSame('trace-fixed12345678', $response->getHeaderLine('X-Request-Id'));
    }

    public function testSuccessCustomMsgAndNullData(): void
    {
        $c = $this->makeController();

        $body = json_decode($c->successEx(null, '自定义消息')->getJSON(), true);

        $this->assertSame('0000', $body['code']);
        $this->assertSame('自定义消息', $body['msg']);
        $this->assertNull($body['data']);
    }

    public function testSuccessAutoGeneratesTraceIdWhenHeaderMissing(): void
    {
        $c = $this->makeController();

        $response = $c->successEx();

        $traceId = $response->getHeaderLine('X-Request-Id');
        $this->assertMatchesRegularExpression('/^trace-[0-9a-f]{16}$/', $traceId);
    }

    public function testSuccessOutputsServerTimeMsWhenElapsedPositive(): void
    {
        $c = $this->makeController();

        $response = $c->successEx(null, 'Success', 12.3456);

        $this->assertSame('12.35', $response->getHeaderLine('X-Server-Time-Ms'));
    }

    public function testSuccessOmitsServerTimeMsWhenElapsedZero(): void
    {
        $c = $this->makeController();

        $response = $c->successEx();

        $this->assertSame('', $response->getHeaderLine('X-Server-Time-Ms'));
    }

    public function testSuccessOutputsServerTraceInTestingEnvironment(): void
    {
        // phpunit bootstrap 设 ENVIRONMENT=testing（非 production），serverTrace 非空即输出
        $c = $this->makeController();
        $c->setServerTraceEx(['db' => 1.23]);

        $response = $c->successEx();

        $expected = json_encode(['db' => 1.23], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertSame($expected, $response->getHeaderLine('X-Server-Trace'));
    }

    public function testSuccessOmitsServerTraceWhenEmpty(): void
    {
        $c = $this->makeController();

        $response = $c->successEx();

        $this->assertSame('', $response->getHeaderLine('X-Server-Trace'));
    }

    public function testSuccessMergesSqlTraceAndDrainsStaticBuffer(): void
    {
        // 预置 Mcommon::$sqlTrace（私有静态）——模拟慢查询记录
        $ref = new \ReflectionProperty(Mcommon::class, 'sqlTrace');
        $ref->setAccessible(true);
        $ref->setValue(null, [['sql' => base64_encode('SELECT 1'), 'ms' => 88.8]]);

        $c = $this->makeController();
        $response = $c->successEx();

        $traceJson = $response->getHeaderLine('X-Server-Trace');
        $this->assertStringContainsString('sqlTrace', $traceJson);
        $this->assertStringContainsString('88.8', $traceJson);
        // getSqlTrace() 消费语义：读取即清空
        $this->assertSame([], Mcommon::getSqlTrace());
    }

    public function testSuccessSqlTraceMergedIntoServerTraceState(): void
    {
        $ref = new \ReflectionProperty(Mcommon::class, 'sqlTrace');
        $ref->setAccessible(true);
        $ref->setValue(null, [['sql' => base64_encode('SELECT 2'), 'ms' => 60.0]]);

        $c = $this->makeController();
        $c->setServerTraceEx(['step1' => 5.0]);
        $c->successEx();

        // serverTrace 状态被合并（后续日志/追踪可复用）
        $state = $c->getServerTraceState();
        $this->assertSame(5.0, $state['step1']);
        $this->assertArrayHasKey('sqlTrace', $state);
    }

    // ---- error 族 ----

    public function testErrorUsesGivenCode(): void
    {
        $c = $this->makeController(null, ['X-Request-Id' => 'trace-err000000001']);

        $response = $c->errorEx('4321', '失败', ['detail' => 'x']);

        $body = json_decode($response->getJSON(), true);
        $this->assertSame('4321', $body['code']);
        $this->assertSame('失败', $body['msg']);
        $this->assertSame(['detail' => 'x'], $body['data']);
        $this->assertSame('trace-err000000001', $response->getHeaderLine('X-Request-Id'));
    }

    public function testParamErrorCodeAndMsg(): void
    {
        $body = json_decode($this->makeController()->paramErrorEx('参数不合法')->getJSON(), true);
        $this->assertSame('2001', $body['code']);
        $this->assertSame('参数不合法', $body['msg']);
        $this->assertNull($body['data']);
    }

    public function testNotFoundCode(): void
    {
        $body = json_decode($this->makeController()->notFoundEx('未找到')->getJSON(), true);
        $this->assertSame('2002', $body['code']);
    }

    public function testServerErrorCode(): void
    {
        $body = json_decode($this->makeController()->serverErrorEx('服务器异常')->getJSON(), true);
        $this->assertSame('5000', $body['code']);
    }

    public function testBusinessErrorCode(): void
    {
        $body = json_decode($this->makeController()->businessErrorEx('业务规则不满足')->getJSON(), true);
        $this->assertSame('2003', $body['code']);
    }

    // ---- requireParam 族 ----

    public function testRequireParamReturnsNullWhenPresent(): void
    {
        $c = $this->makeController();
        $this->assertNull($c->requireParamEx(['name' => 'x'], 'name'));
    }

    public function testRequireParamReturnsResponseWhenMissing(): void
    {
        $c = $this->makeController();

        $response = $c->requireParamEx(['other' => 'x'], 'name');

        $this->assertInstanceOf(\CodeIgniter\HTTP\ResponseInterface::class, $response);
        $body = json_decode($response->getJSON(), true);
        $this->assertSame('2001', $body['code']);
        $this->assertSame('name不能为空', $body['msg']);
    }

    public function testRequireParamTreatsEmptyStringAsMissing(): void
    {
        $c = $this->makeController();
        $this->assertNotNull($c->requireParamEx(['name' => ''], 'name'));
    }

    public function testRequireParamsChecksAllAndStopsAtFirstMissing(): void
    {
        $c = $this->makeController();

        $this->assertNull($c->requireParamsEx(['a' => '1', 'b' => '2'], ['a', 'b']));

        $response = $c->requireParamsEx(['a' => '1'], ['a', 'b']);
        $body = json_decode($response->getJSON(), true);
        $this->assertSame('2001', $body['code']);
        $this->assertSame('b不能为空', $body['msg']);
    }

    // ---- 请求解析族 ----

    public function testGetJsonInputParsesBody(): void
    {
        $c = $this->makeController('{"guid":"abc","n":3}');
        $this->assertSame(['guid' => 'abc', 'n' => 3], $c->getJsonInputEx());
    }

    public function testGetJsonInputReturnsEmptyArrayOnEmptyBody(): void
    {
        $c = $this->makeController(null);
        $this->assertSame([], $c->getJsonInputEx());
    }

    public function testGetGuidFromRequestReturnsGuid(): void
    {
        $c = $this->makeController('{"guid":"g-123"}');
        $this->assertSame('g-123', $c->getGuidFromRequestEx());
    }

    public function testGetGuidFromRequestDefaultsToEmptyString(): void
    {
        $c = $this->makeController('{"other":1}');
        $this->assertSame('', $c->getGuidFromRequestEx());
    }

    // ---- 用户上下文委托 ----

    public function testUserAccessorsDelegateToSessionUserContext(): void
    {
        SessionUserContext::setJwtUser((object) [
            'region'        => 'HB',
            'workId'        => 'W999',
            'userName'      => '张三',
            'deptCodeAuthz' => 'D1|D2',
        ]);

        $c = $this->makeController();

        $this->assertSame('W999', $c->getUserWorkIdEx());
        $this->assertSame('张三', $c->getUserNameEx());
        $this->assertSame('D1|D2', $c->getDeptAuthzEx());
    }
}
