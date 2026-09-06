<?php

namespace Tests\Support;

use App\Controllers\MatchApi;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use Config\App;
use Config\Services;

/**
 * MatchApi 测试替身
 *
 * - make() 静态工厂：构造请求上下文并手动 initController（同 TestableBaseApiController 模式）
 * - MatchApi 的待测方法均为 private（子类不可见），统一经 Reflection 调用
 * - applyMatchedFlag 特征测试传完整行集（所需字段全部在行内），补查分支不触发，不连库
 */
class TestableMatchApi extends MatchApi
{
    public static function make(): self
    {
        $config = new App();
        $uri = new SiteURI($config, '/');
        $agent = new UserAgent();
        $request = new IncomingRequest($config, $uri, '', $agent);
        $response = new Response($config);
        $logger = Services::logger();

        $controller = new self();
        $controller->initController($request, $response, $logger);
        return $controller;
    }

    // ---- private 方法 Reflection 委托（特征测试入口）----
    // 注：parseWriteInstructions / resolveWriteSourceValue / generateUuid
    // 已抽取到 MatchConfigParser（直调静态方法测试），不再经控制器反射。

    public function buildAuthConditionWithAndEx(
        string $field,
        string $userAuth,
        string $roleAuth,
        callable $buildFunc,
        bool $upkeepAuth
    ): string {
        return self::callPrivate($this, 'buildAuthConditionWithAnd', [$field, $userAuth, $roleAuth, $buildFunc, $upkeepAuth]);
    }

    /**
     * @param array<int, array<string, mixed>> $aRows
     * @param array<int, array<string, mixed>> $bRows
     */
    public function applyMatchedFlagEx(
        array &$aRows,
        array &$bRows,
        array $matchWrites,
        string $aTable,
        string $bTable,
        array $matchKeyFields
    ): void {
        self::callPrivate($this, 'applyMatchedFlag', [&$aRows, &$bRows, $matchWrites, $aTable, $bTable, $matchKeyFields]);
    }

    /**
     * Reflection 调用 private 方法（引用参数需以引用元素传入 $args）
     * @param array<int, mixed> $args
     */
    private static function callPrivate(object $obj, string $method, array $args): mixed
    {
        $m = new \ReflectionMethod($obj, $method);
        $m->setAccessible(true);
        return $m->invokeArgs($obj, $args);
    }
}
