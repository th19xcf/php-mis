<?php

namespace Tests\Unit;

use App\Exceptions\BusinessException;
use App\Libraries\DetailSelectFieldBuilder;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * DetailSelectFieldBuilder 直连测试
 *
 * Phase 2 抽取后直连新类静态方法，锁定 SELECT 片段构造契约
 * （控制器级路径已由 BaseApiControllerDetailFieldsTest 特征测试覆盖）。
 */
class DetailSelectFieldBuilderTest extends CIUnitTestCase
{
    // ---- buildSelectFields（单表） ----

    public function testSingleAliasWhenQueryNameDiffers(): void
    {
        $this->assertSame(
            '`人员编码` as `工号`',
            DetailSelectFieldBuilder::buildSelectFields('2015', 'ee_store', [
                ['字段名' => '人员编码', '查询名' => '工号'],
            ], ['人员编码'])
        );
    }

    public function testSingleNoAliasWhenQueryNameEmptyOrEqual(): void
    {
        $this->assertSame(
            '`姓名`,`手机号码`',
            DetailSelectFieldBuilder::buildSelectFields('2015', 'ee_store', [
                ['字段名' => '姓名', '查询名' => ''],
                ['字段名' => '手机号码', '查询名' => '手机号码'],
            ], ['姓名', '手机号码'])
        );
    }

    public function testSingleFiltersEmptyAndMismatchedFields(): void
    {
        $this->assertSame(
            '`姓名`',
            DetailSelectFieldBuilder::buildSelectFields('2015', 'ee_store', [
                ['字段名' => '', '查询名' => '坏配置'],
                ['字段名' => '姓名', '查询名' => ''],
                ['字段名' => '不存在', '查询名' => ''],
            ], ['姓名'])
        );
    }

    public function testSingleEmptyPartsThrowsWithTableInMessage(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage(
            '功能编码 2015 的 view_function 配置为空或字段均不匹配表 ee_store，请检查 def_query_column 配置并刷新缓存'
        );
        DetailSelectFieldBuilder::buildSelectFields('2015', 'ee_store', [
            ['字段名' => '不存在', '查询名' => ''],
        ], ['姓名']);
    }

    // ---- buildSelectFieldsMulti（多表） ----

    public function testMultiPrefixedWithAliasAlwaysEmitted(): void
    {
        // 与单表版差异：查询名==字段名也带 as 后缀
        $this->assertSame(
            'e.`人员编码` as `工号`,e.`姓名` as `姓名`',
            DetailSelectFieldBuilder::buildSelectFieldsMulti('2045', [
                ['字段名' => '人员编码', '查询名' => '工号'],
                ['字段名' => '姓名', '查询名' => ''],
            ], ['e' => ['人员编码', '姓名']])
        );
    }

    public function testMultiPlaceholderForFieldsNotInAnyTable(): void
    {
        $this->assertSame(
            "e.`人员编码` as `人员编码`,'' as `裁剪输出`",
            DetailSelectFieldBuilder::buildSelectFieldsMulti('2045', [
                ['字段名' => '人员编码', '查询名' => ''],
                ['字段名' => '已裁剪列', '查询名' => '裁剪输出'],
            ], ['e' => ['人员编码']])
        );
    }

    public function testMultiFirstAliasIterationOrderWins(): void
    {
        $this->assertSame(
            'e.`人员编码` as `人员编码`',
            DetailSelectFieldBuilder::buildSelectFieldsMulti('2045', [
                ['字段名' => '人员编码', '查询名' => ''],
            ], ['e' => ['人员编码'], 'p' => ['人员编码']])
        );
    }

    public function testMultiEmptyPartsThrowsWithoutTableInMessage(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage(
            '功能编码 2045 的 view_function 配置为空，请检查 def_query_column 配置并刷新缓存'
        );
        DetailSelectFieldBuilder::buildSelectFieldsMulti('2045', [
            ['字段名' => '', '查询名' => '只有查询名'],
        ], ['e' => ['人员编码']]);
    }
}
