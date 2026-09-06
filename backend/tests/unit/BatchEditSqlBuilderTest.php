<?php

namespace Tests\Unit;

use App\Libraries\BatchEditSqlBuilder;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * BatchEditSqlBuilder 直测：服务层特征测试未覆盖的纯函数
 * （extractDiffData 走审计路径、filterHitKeyValues / updateFieldNames 边界）
 */
class BatchEditSqlBuilderTest extends CIUnitTestCase
{
    private function q(): callable
    {
        return fn(string $v): string => "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $v) . "'";
    }

    public function testExtractDiffDataExcludesPkAndSkipFields(): void
    {
        $row = [
            'GUID' => '1',
            '姓名' => '张三',
            '操作记录' => 'x',
            '操作来源' => 'y',
            '操作人员' => 'z',
            '操作时间' => 't',
            '结束操作时间' => 't2',
            '删除标识' => '0',
            '年龄' => 30,
        ];

        $diff = BatchEditSqlBuilder::extractDiffData($row, 'GUID', BatchEditSqlBuilder::SKIP_FIELDS);

        // 仅业务字段，值不转型（int 30 保持）
        $this->assertSame(['姓名' => '张三', '年龄' => 30], $diff);
    }

    public function testExtractDiffDataEmptyWhenOnlyControlFields(): void
    {
        $row = ['GUID' => '1', '操作记录' => 'x', '删除标识' => '0'];

        $this->assertSame([], BatchEditSqlBuilder::extractDiffData($row, 'GUID', BatchEditSqlBuilder::SKIP_FIELDS));
    }

    public function testFilterHitKeyValuesPreservesOrderAndSkipsMiss(): void
    {
        $originalRows = ['b' => ['主键' => 'b'], 'a' => ['主键' => 'a']];

        // 不去重（去重由上游 dedupeKeyValues 完成），仅按命中过滤、保持顺序
        $this->assertSame(
            ['a', 'b'],
            BatchEditSqlBuilder::filterHitKeyValues(['a', 'zz', 'b'], $originalRows)
        );
        $this->assertSame(
            ['a', 'a'],
            BatchEditSqlBuilder::filterHitKeyValues(['a', 'a'], $originalRows)
        );
    }

    public function testUpdateFieldNamesPreservesRowOrder(): void
    {
        $row = ['主键' => 'a', '字段B' => '1', '操作记录' => 'x', '字段A' => '2'];

        $this->assertSame(
            ['字段B', '字段A'],
            BatchEditSqlBuilder::updateFieldNames($row, '主键', BatchEditSqlBuilder::SKIP_FIELDS)
        );
    }

    public function testGroupRowsByUpdateFieldsSortsFieldSignature(): void
    {
        $rows = [
            ['主键' => 'a', '字段B' => '1', '字段A' => '2'],
            ['主键' => 'b', '字段A' => '3', '字段B' => '4'],
            ['主键' => 'c', '操作记录' => 'x'],
        ];

        $groups = BatchEditSqlBuilder::groupRowsByUpdateFields($rows, '主键', BatchEditSqlBuilder::SKIP_FIELDS);

        // 前两行字段集相同 → 同组（签名按排序字段）；第三行仅控制列 → 不入组
        $this->assertCount(1, $groups);
        $group = reset($groups);
        $this->assertSame(['字段A', '字段B'], $group['fields']);
        $this->assertCount(2, $group['rows']);
    }

    public function testBuildWhereFromPrimaryKeyComposite(): void
    {
        $where = BatchEditSqlBuilder::buildWhereFromPrimaryKey(
            ['pk1' => 'a b', 'pk2' => null, 'pk3' => 'c'],
            'pk1; pk2 ;pk3',
            $this->q()
        );

        // isset 语义：null 的 pk2 不进条件；trim 后的字段名
        $this->assertSame("pk1='a b' and pk3='c'", $where);
    }
}
