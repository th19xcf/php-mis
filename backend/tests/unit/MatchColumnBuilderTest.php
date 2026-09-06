<?php

namespace Tests\Unit;

use App\Libraries\MatchColumnBuilder;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * MatchColumnBuilder 特征测试
 *
 * 锁定 view_function 列 → 前端表格列定义 / 匹配字段映射的抽取逻辑。
 */
class MatchColumnBuilderTest extends CIUnitTestCase
{
    // ---- buildModuleColumns ----

    public function testBuildModuleColumnsPrependsSeqColumn(): void
    {
        $columns = MatchColumnBuilder::buildModuleColumns([]);

        $this->assertCount(1, $columns);
        $this->assertSame([
            'field' => '序号',
            'title' => '序号',
            'type' => '数值',
            'width' => 90,
            'hidden' => false,
            'editable' => false,
            'sortable' => true,
            'original' => [],
        ], $columns[0]);
    }

    public function testBuildModuleColumnsMapsViewRows(): void
    {
        $rows = [
            ['列名' => '姓名', '查询名' => '员工姓名', '字段名' => 'name', '列类型' => '文本', '列宽度' => '120'],
            ['列名' => '', '查询名' => '', '字段名' => 'amount', '列类型' => '数值', '列宽度' => 100],
        ];

        $columns = MatchColumnBuilder::buildModuleColumns($rows);

        $this->assertCount(3, $columns);
        $this->assertSame('姓名', $columns[1]['field']);
        $this->assertSame('员工姓名', $columns[1]['title']);
        $this->assertSame('文本', $columns[1]['type']);
        $this->assertSame(120, $columns[1]['width']);
        $this->assertSame($rows[0], $columns[1]['original']);

        // 列名/查询名为空时回退字段名
        $this->assertSame('amount', $columns[2]['field']);
        $this->assertSame('amount', $columns[2]['title']);
        $this->assertSame(100, $columns[2]['width']);
    }

    // ---- extractMatchColumns ----

    public function testExtractMatchColumnsMapsAllTypes(): void
    {
        $rows = [
            ['可匹配' => '1', '字段名' => 'GUID'],
            ['可匹配' => '2', '字段名' => '名称'],
            ['可匹配' => '3', '字段名' => '金额'],
            ['可匹配' => '4', '字段名' => '目标'],
            ['可匹配' => '', '字段名' => '普通'],
        ];

        $this->assertSame([
            'key' => 'GUID',
            'label' => '名称',
            'amount' => '金额',
            'target' => '目标',
        ], MatchColumnBuilder::extractMatchColumns($rows));
    }

    public function testExtractMatchColumnsLaterRowOverrides(): void
    {
        // 同类多行时后者覆盖前者（与原循环一致，无 break）
        $rows = [
            ['可匹配' => '1', '字段名' => 'first'],
            ['可匹配' => '1', '字段名' => 'second'],
        ];

        $result = MatchColumnBuilder::extractMatchColumns($rows);

        $this->assertSame('second', $result['key']);
    }

    public function testExtractMatchColumnsSkipsEmptyFieldName(): void
    {
        $rows = [
            ['可匹配' => '1', '字段名' => ''],
            ['可匹配' => '4', '字段名' => 't'],
        ];

        $result = MatchColumnBuilder::extractMatchColumns($rows);

        $this->assertSame('', $result['key']);
        $this->assertSame('t', $result['target']);
    }
}
