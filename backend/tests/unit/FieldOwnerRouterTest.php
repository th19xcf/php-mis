<?php

namespace Tests\Unit;

use App\Libraries\FieldOwnerRouter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * FieldOwnerRouter 直连测试
 *
 * Phase 2 抽取后直连新类静态方法，锁定写入路由分组契约
 * （控制器级路径已由 BaseApiControllerDetailFieldsTest 特征测试覆盖）。
 */
class FieldOwnerRouterTest extends CIUnitTestCase
{
    /** @var string[] warning 捕获（替代控制器 logTrace） */
    private array $warnings = [];

    private function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->warnings = [];
    }

    public function testRoutesByOwnerConfig(): void
    {
        $groups = FieldOwnerRouter::split(
            [['字段名' => '学校', '查询名' => '', '字段归属表' => 'hr_person']],
            'ee_store',
            [],
            ['学校' => '北大', '姓名' => '张三']
        );

        $this->assertSame([
            'ee_store' => ['姓名' => '张三'],
            'hr_person' => ['学校' => '北大'],
        ], $groups);
    }

    public function testDualRoutingByQueryNameAndFieldName(): void
    {
        $viewColumns = [['字段名' => '姓名', '查询名' => '员工姓名', '字段归属表' => 'hr_person']];

        $byFieldName = FieldOwnerRouter::split($viewColumns, 'ee_store', [], ['姓名' => '张三']);
        $byQueryName = FieldOwnerRouter::split($viewColumns, 'ee_store', [], ['员工姓名' => '张三']);

        $this->assertSame(['ee_store' => [], 'hr_person' => ['姓名' => '张三']], $byFieldName);
        $this->assertSame(['ee_store' => [], 'hr_person' => ['员工姓名' => '张三']], $byQueryName);
    }

    public function testDirtyOwnerFallsBackToMainTableWithWarning(): void
    {
        $groups = FieldOwnerRouter::split(
            [['字段名' => '姓名', '查询名' => '', '字段归属表' => 'hr_person,ee_store']],
            'ee_store',
            [],
            ['姓名' => '张三'],
            fn (string $m) => $this->warn($m)
        );

        $this->assertSame(['ee_store' => ['姓名' => '张三']], $groups);
        $this->assertSame(['[splitDataByFieldOwner] 非法字段归属表配置: hr_person,ee_store'], $this->warnings);
    }

    public function testDualWriteWhenMainTableHasSameColumn(): void
    {
        $groups = FieldOwnerRouter::split(
            [['字段名' => '姓名', '查询名' => '', '字段归属表' => 'hr_person']],
            'ee_store',
            ['姓名'],
            ['姓名' => '张三']
        );

        $this->assertSame([
            'ee_store' => ['姓名' => '张三'],
            'hr_person' => ['姓名' => '张三'],
        ], $groups);
    }

    public function testGuidAndOperationKeysSkipped(): void
    {
        $groups = FieldOwnerRouter::split([], 'ee_store', [], [
            'guid' => '123',
            '操作' => 'add',
            '姓名' => '张三',
        ]);

        $this->assertSame(['ee_store' => ['姓名' => '张三']], $groups);
    }

    public function testMainTableGroupAlwaysPresent(): void
    {
        $groups = FieldOwnerRouter::split(
            [['字段名' => '学校', '查询名' => '', '字段归属表' => 'hr_person']],
            'ee_store',
            [],
            ['学校' => '北大']
        );

        $this->assertArrayHasKey('ee_store', $groups);
        $this->assertSame([], $groups['ee_store']);
    }

    public function testNullWarnCallbackDoesNotCrashOnDirtyConfig(): void
    {
        $groups = FieldOwnerRouter::split(
            [['字段名' => '姓名', '查询名' => '', '字段归属表' => 'a,b']],
            'ee_store',
            [],
            ['姓名' => '张三']
        );

        $this->assertSame(['ee_store' => ['姓名' => '张三']], $groups);
    }
}
