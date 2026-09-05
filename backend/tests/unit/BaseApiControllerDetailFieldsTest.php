<?php

namespace Tests\Unit;

use App\Exceptions\BusinessException;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\ResetsStaticState;
use Tests\Support\TestableBaseApiController;

/**
 * buildDetailSelectFields / buildDetailSelectFieldsMulti / splitDataByFieldOwner
 * 特征测试（重构安全网）
 *
 * 经 TestableBaseApiController 桩注入 view_function 配置与表列，
 * 不触库；异常文案与 warning 文案逐字锁定，Phase 2 抽取后应零改动通过。
 */
class BaseApiControllerDetailFieldsTest extends CIUnitTestCase
{
    use ResetsStaticState;

    private TestableBaseApiController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = TestableBaseApiController::make();
    }

    protected function tearDown(): void
    {
        $this->resetStaticState();
        parent::tearDown();
    }

    // ---- buildDetailSelectFields ----

    public function testEmptyConfigThrowsWithExactMessage(): void
    {
        $this->controller->setViewColumns([]);
        $this->controller->setTableColumns('ee_store', ['GUID', '姓名']);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage(
            '功能编码 2015 的 view_function 配置为空或字段均不匹配表 ee_store，请检查 def_query_column 配置并刷新缓存'
        );
        $this->controller->buildDetailSelectFieldsEx('2015', 'ee_store');
    }

    public function testAllFieldsMismatchTableThrowsSameMessage(): void
    {
        $this->controller->setViewColumns([
            ['字段名' => '不存在的列', '查询名' => ''],
        ]);
        $this->controller->setTableColumns('ee_store', ['GUID', '姓名']);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage(
            '功能编码 2015 的 view_function 配置为空或字段均不匹配表 ee_store，请检查 def_query_column 配置并刷新缓存'
        );
        $this->controller->buildDetailSelectFieldsEx('2015', 'ee_store');
    }

    public function testEmptyTableColumnsTreatedAsAllMismatch(): void
    {
        // SHOW COLUMNS 失败兜底返回 [] → tableColSet 为空 → 全部不匹配 → 抛配置异常
        $this->controller->setViewColumns([
            ['字段名' => '姓名', '查询名' => ''],
        ]);
        $this->controller->setTableColumns('ee_store', []);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage(
            '功能编码 2015 的 view_function 配置为空或字段均不匹配表 ee_store，请检查 def_query_column 配置并刷新缓存'
        );
        $this->controller->buildDetailSelectFieldsEx('2015', 'ee_store');
    }

    public function testQueryNameDiffersFromFieldNameAddsAlias(): void
    {
        $this->controller->setViewColumns([
            ['字段名' => '人员编码', '查询名' => '工号'],
        ]);
        $this->controller->setTableColumns('ee_store', ['人员编码']);

        $this->assertSame(
            '`人员编码` as `工号`',
            $this->controller->buildDetailSelectFieldsEx('2015', 'ee_store')
        );
    }

    public function testQueryNameEqualsFieldNameNoAlias(): void
    {
        $this->controller->setViewColumns([
            ['字段名' => '姓名', '查询名' => '姓名'],
        ]);
        $this->controller->setTableColumns('ee_store', ['姓名']);

        $this->assertSame(
            '`姓名`',
            $this->controller->buildDetailSelectFieldsEx('2015', 'ee_store')
        );
    }

    public function testEmptyQueryNameNoAlias(): void
    {
        $this->controller->setViewColumns([
            ['字段名' => '姓名', '查询名' => ''],
        ]);
        $this->controller->setTableColumns('ee_store', ['姓名']);

        $this->assertSame(
            '`姓名`',
            $this->controller->buildDetailSelectFieldsEx('2015', 'ee_store')
        );
    }

    public function testEmptyFieldNameSkipped(): void
    {
        $this->controller->setViewColumns([
            ['字段名' => '', '查询名' => '坏配置'],
            ['字段名' => '姓名', '查询名' => ''],
        ]);
        $this->controller->setTableColumns('ee_store', ['姓名']);

        $this->assertSame(
            '`姓名`',
            $this->controller->buildDetailSelectFieldsEx('2015', 'ee_store')
        );
    }

    public function testOutputOrderMatchesConfigOrder(): void
    {
        $this->controller->setViewColumns([
            ['字段名' => '手机号码', '查询名' => '手机'],
            ['字段名' => 'GUID', '查询名' => ''],
            ['字段名' => '姓名', '查询名' => ''],
        ]);
        $this->controller->setTableColumns('ee_store', ['GUID', '姓名', '手机号码']);

        $this->assertSame(
            '`手机号码` as `手机`,`GUID`,`姓名`',
            $this->controller->buildDetailSelectFieldsEx('2015', 'ee_store')
        );
    }

    public function testFieldsNotInTableFiltered(): void
    {
        $this->controller->setViewColumns([
            ['字段名' => '姓名', '查询名' => ''],
            ['字段名' => '表里没有', '查询名' => '没差别名'],
        ]);
        $this->controller->setTableColumns('ee_store', ['姓名']);

        $this->assertSame(
            '`姓名`',
            $this->controller->buildDetailSelectFieldsEx('2015', 'ee_store')
        );
    }

    // ---- buildDetailSelectFieldsMulti ----

    public function testMultiEmptyConfigThrowsWithExactMessage(): void
    {
        $this->controller->setViewColumns([]);
        $this->controller->setTableColumns('ee_employment', ['GUID']);
        $this->controller->setTableColumns('hr_person', ['人员编码']);

        // 注意：与单表版文案不同（无"或字段均不匹配表"段）
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage(
            '功能编码 2045 的 view_function 配置为空，请检查 def_query_column 配置并刷新缓存'
        );
        $this->controller->buildDetailSelectFieldsMultiEx('2045', ['e' => 'ee_employment', 'p' => 'hr_person']);
    }

    public function testMultiAllFieldNamesEmptyThrows(): void
    {
        $this->controller->setViewColumns([
            ['字段名' => '', '查询名' => '只有查询名'],
        ]);
        $this->controller->setTableColumns('ee_employment', ['GUID']);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage(
            '功能编码 2045 的 view_function 配置为空，请检查 def_query_column 配置并刷新缓存'
        );
        $this->controller->buildDetailSelectFieldsMultiEx('2045', ['e' => 'ee_employment']);
    }

    public function testMultiPrefixedOutputWithAlias(): void
    {
        $this->controller->setViewColumns([
            ['字段名' => '人员编码', '查询名' => '工号'],
        ]);
        $this->controller->setTableColumns('ee_employment', ['人员编码']);

        $this->assertSame(
            'e.`人员编码` as `工号`',
            $this->controller->buildDetailSelectFieldsMultiEx('2045', ['e' => 'ee_employment'])
        );
    }

    public function testMultiFieldNotInAnyTableEmitsEmptyPlaceholder(): void
    {
        // 已裁剪列（如 ee_onjob 删除的列）：空串占位保持出参形状
        $this->controller->setViewColumns([
            ['字段名' => '人员编码', '查询名' => ''],
            ['字段名' => '已裁剪列', '查询名' => '裁剪输出'],
        ]);
        $this->controller->setTableColumns('ee_employment', ['人员编码']);

        // 注意：multi 版与单表版不同——查询名==字段名时也带 as 后缀（保持出参形状）
        $this->assertSame(
            "e.`人员编码` as `人员编码`,'' as `裁剪输出`",
            $this->controller->buildDetailSelectFieldsMultiEx('2045', ['e' => 'ee_employment'])
        );
    }

    public function testMultiFirstAliasInIterationOrderWins(): void
    {
        // continue 2 语义：字段同时命中多表时，按 tables 数组迭代顺序取第一个别名
        $this->controller->setViewColumns([
            ['字段名' => '人员编码', '查询名' => ''],
        ]);
        $this->controller->setTableColumns('ee_employment', ['人员编码']);
        $this->controller->setTableColumns('hr_person', ['人员编码']);

        $this->assertSame(
            'e.`人员编码` as `人员编码`',
            $this->controller->buildDetailSelectFieldsMultiEx('2045', ['e' => 'ee_employment', 'p' => 'hr_person'])
        );
    }

    public function testMultiEmptyTableColumnsFallsThroughToOtherAlias(): void
    {
        // 某别名表列查询失败（[]）→ 继续检查后续别名
        $this->controller->setViewColumns([
            ['字段名' => '人员编码', '查询名' => ''],
        ]);
        $this->controller->setTableColumns('ee_employment', []);
        $this->controller->setTableColumns('hr_person', ['人员编码']);

        $this->assertSame(
            'p.`人员编码` as `人员编码`',
            $this->controller->buildDetailSelectFieldsMultiEx('2045', ['e' => 'ee_employment', 'p' => 'hr_person'])
        );
    }

    public function testMultiQueryNameEqualsFieldNameStillEmitsAliasSuffix(): void
    {
        $this->controller->setViewColumns([
            ['字段名' => '姓名', '查询名' => '姓名'],
        ]);
        $this->controller->setTableColumns('hr_person', ['姓名']);

        $this->assertSame(
            'p.`姓名` as `姓名`',
            $this->controller->buildDetailSelectFieldsMultiEx('2045', ['p' => 'hr_person'])
        );
    }

    // ---- splitDataByFieldOwner ----

    public function testSplitEmptyOwnerAllFieldsGoToMainTable(): void
    {
        $this->controller->setViewColumns([
            ['字段名' => '姓名', '查询名' => '', '字段归属表' => ''],
            ['字段名' => '手机号码', '查询名' => '', '字段归属表' => null],
        ]);
        $this->controller->setTableColumns('ee_store', ['姓名', '手机号码']);

        $groups = $this->controller->splitDataByFieldOwnerEx('2015', 'ee_store', [
            '姓名' => '张三',
            '手机号码' => '13800000000',
        ]);

        $this->assertSame([
            'ee_store' => ['姓名' => '张三', '手机号码' => '13800000000'],
        ], $groups);
    }

    public function testSplitFieldNameAndQueryNameDualRouting(): void
    {
        // 查询名与字段名都建立映射，兼容前端两种字段名
        $this->controller->setViewColumns([
            ['字段名' => '姓名', '查询名' => '员工姓名', '字段归属表' => 'hr_person'],
        ]);
        $this->controller->setTableColumns('ee_store', []);

        $byFieldName = $this->controller->splitDataByFieldOwnerEx('2015', 'ee_store', ['姓名' => '张三']);
        $byQueryName = $this->controller->splitDataByFieldOwnerEx('2015', 'ee_store', ['员工姓名' => '张三']);

        // 主表分组始终存在（初始空分组）
        $this->assertSame(['ee_store' => [], 'hr_person' => ['姓名' => '张三']], $byFieldName);
        $this->assertSame(['ee_store' => [], 'hr_person' => ['员工姓名' => '张三']], $byQueryName);
    }

    public function testSplitDirtyOwnerFallsBackToMainTableWithWarning(): void
    {
        // 逗号多值等脏配置：按主表处理并留痕
        $this->controller->setViewColumns([
            ['字段名' => '姓名', '查询名' => '', '字段归属表' => 'hr_person,ee_store'],
        ]);
        $this->controller->setTableColumns('ee_store', []);

        $groups = $this->controller->splitDataByFieldOwnerEx('2015', 'ee_store', ['姓名' => '张三']);

        $this->assertSame(['ee_store' => ['姓名' => '张三']], $groups);
        $this->assertCount(1, $this->controller->traceLog);
        $this->assertSame('warning', $this->controller->traceLog[0]['level']);
        $this->assertSame(
            '[splitDataByFieldOwner] 非法字段归属表配置: hr_person,ee_store',
            $this->controller->traceLog[0]['message']
        );
    }

    public function testSplitDualWriteWhenMainTableHasSameColumn(): void
    {
        // 归属 hr_person 且主表存在同名列 → 双写（过渡期存量查询依赖）
        $this->controller->setViewColumns([
            ['字段名' => '姓名', '查询名' => '', '字段归属表' => 'hr_person'],
        ]);
        $this->controller->setTableColumns('ee_store', ['姓名']);

        $groups = $this->controller->splitDataByFieldOwnerEx('2015', 'ee_store', ['姓名' => '张三']);

        $this->assertSame([
            'ee_store' => ['姓名' => '张三'],
            'hr_person' => ['姓名' => '张三'],
        ], $groups);
    }

    public function testSplitNoDualWriteWhenMainTableColumnMissing(): void
    {
        $this->controller->setViewColumns([
            ['字段名' => '学校', '查询名' => '', '字段归属表' => 'hr_person'],
        ]);
        $this->controller->setTableColumns('ee_store', ['姓名']);

        $groups = $this->controller->splitDataByFieldOwnerEx('2015', 'ee_store', ['学校' => '北大']);

        $this->assertSame([
            'ee_store' => [],
            'hr_person' => ['学校' => '北大'],
        ], $groups);
    }

    public function testSplitGuidAndOperationKeysSkipped(): void
    {
        $this->controller->setViewColumns([]);
        $this->controller->setTableColumns('ee_store', []);

        $groups = $this->controller->splitDataByFieldOwnerEx('2015', 'ee_store', [
            'guid' => '123',
            '操作' => 'add',
            '姓名' => '张三',
        ]);

        $this->assertSame(['ee_store' => ['姓名' => '张三']], $groups);
    }

    public function testSplitConfigReadErrorAllToMainTableWithWarning(): void
    {
        $this->controller->setViewColumnsError(new \RuntimeException('缓存爆炸'));
        $this->controller->setTableColumns('ee_store', []);

        $groups = $this->controller->splitDataByFieldOwnerEx('2015', 'ee_store', ['姓名' => '张三']);

        $this->assertSame(['ee_store' => ['姓名' => '张三']], $groups);
        $this->assertCount(1, $this->controller->traceLog);
        $this->assertSame('warning', $this->controller->traceLog[0]['level']);
        $this->assertSame(
            '[splitDataByFieldOwner] 字段归属配置读取失败，全部按主表处理: 缓存爆炸',
            $this->controller->traceLog[0]['message']
        );
    }

    public function testSplitUnknownInputFieldGoesToMainTable(): void
    {
        $this->controller->setViewColumns([
            ['字段名' => '姓名', '查询名' => '', '字段归属表' => 'hr_person'],
        ]);
        $this->controller->setTableColumns('ee_store', []);

        $groups = $this->controller->splitDataByFieldOwnerEx('2015', 'ee_store', [
            '姓名' => '张三',
            '配置外字段' => '值',
        ]);

        $this->assertSame([
            'ee_store' => ['配置外字段' => '值'],
            'hr_person' => ['姓名' => '张三'],
        ], $groups);
    }

    public function testSplitMainTableGroupAlwaysPresent(): void
    {
        $this->controller->setViewColumns([
            ['字段名' => '学校', '查询名' => '', '字段归属表' => 'hr_person'],
        ]);
        $this->controller->setTableColumns('ee_store', []);

        $groups = $this->controller->splitDataByFieldOwnerEx('2015', 'ee_store', ['学校' => '北大']);

        $this->assertArrayHasKey('ee_store', $groups);
        $this->assertSame([], $groups['ee_store']);
    }
}
