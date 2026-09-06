<?php

namespace Tests\Unit;

use App\Libraries\ImportSqlBuilder;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ImportSqlBuilder 直连测试（纯函数，无需 DB / 测试替身）
 */
class ImportSqlBuilderTest extends CIUnitTestCase
{
    private \Closure $quote;

    protected function setUp(): void
    {
        parent::setUp();
        $this->quote = static fn (string $v): string => sprintf("'%s'", str_replace(["\\", "'"], ["\\\\", "\\'"], $v));
    }

    // ---- DDL ----

    public function testSimpleCreateTableSql(): void
    {
        $this->assertSame(
            'CREATE TABLE `tmp_x` (id int auto_increment primary key, data varchar(255))',
            ImportSqlBuilder::buildSimpleCreateTableSql('tmp_x')
        );
    }

    public function testCreateTableSqlFieldResolution(): void
    {
        $built = ImportSqlBuilder::buildCreateTableSql('tmp_t', [
            ['字段名' => 'f1', '字段长度' => 10],
            ['字段名' => '', '列名' => 'c2', '字段长度' => 5],        // fallback 列名
            ['字段名' => '', '列名' => '', '字段长度' => 5],          // 均空跳过
            ['字段名' => 'f3', '字段长度' => 0, '缺省值' => 'D'],      // 缺省值 quote
        ], $this->quote);

        $this->assertSame(
            'CREATE TABLE `tmp_t` (`f1` varchar(10) not null default "",'
            . '`c2` varchar(5) not null default "",'
            . '`f3` varchar(0) not null default \'D\')',
            $built['sql']
        );
        $this->assertSame(['f1', 'c2', 'f3'], $built['fieldNames']);
    }

    // ---- INSERT ----

    public function testDefaultValueMapSkipsEmpty(): void
    {
        $map = ImportSqlBuilder::buildDefaultValueMap([
            ['字段名' => 'f1', '缺省值' => 'D1'],
            ['字段名' => 'f2', '缺省值' => ''],   // 缺省值空 → 不入 map
            ['字段名' => '', '缺省值' => 'D3'],    // 字段名空 → 不入 map
        ]);

        $this->assertSame(['f1' => 'D1'], $map);
    }

    public function testDeriveInsertFieldsFiltersEmptyKey(): void
    {
        $this->assertSame(
            ['a', 'b'],
            ImportSqlBuilder::deriveInsertFields(['a' => 1, '' => 2, 'b' => 3])
        );
    }

    public function testInsertRowsSqlAppliesDefaultOnEmpty(): void
    {
        $sql = ImportSqlBuilder::buildInsertRowsSql(
            'tmp_t',
            ['f1', 'f2'],
            [
                ['f1' => 'a', 'f2' => 'x'],
                ['f1' => null, 'f2' => ''],           // null 与 '' 均触发缺省值
                ['f2' => 'y'],                        // f1 缺键 → '' → 缺省值
            ],
            ['f1' => 'DF1', 'f2' => 'DF2'],
            $this->quote
        );

        // f2 无缺省值配置场景外：仅 f1 有。此处两个都配置以验证 null/''/缺键三路
        $this->assertSame(
            'INSERT INTO `tmp_t` (`f1`, `f2`) VALUES (\'a\',\'x\'), (\'DF1\',\'DF2\'), (\'DF1\',\'y\')',
            $sql
        );
    }

    public function testInsertRowsSqlNoDefaultKeepsEmptyString(): void
    {
        $sql = ImportSqlBuilder::buildInsertRowsSql('tmp_t', ['f1'], [['f1' => '']], [], $this->quote);

        $this->assertSame('INSERT INTO `tmp_t` (`f1`) VALUES (\'\')', $sql);
    }

    // ---- 导入映射 ----

    public function testImportFieldMappingQueryNameAlias(): void
    {
        $mapping = ImportSqlBuilder::buildImportFieldMapping([
            ['字段名' => 'f1', '查询名' => 'q1'],   // 别名转换
            ['字段名' => 'f2', '查询名' => 'f2'],   // 同名 → 不加 as
            ['字段名' => 'f3', '查询名' => ''],     // 无查询名
            ['字段名' => '', '查询名' => 'q4'],     // 无字段名 → 跳过
            ['字段名' => 'f5'],                     // 缺查询名键
        ]);

        $this->assertSame(['`f1`', '`f2`', '`f3`', '`f5`'], $mapping['fieldNames']);
        $this->assertSame(['q1 as `f1`', '`f2`', '`f3`', '`f5`'], $mapping['selectParts']);
    }

    public function testImportFieldMappingEmptyFieldNameSkippedNotFallback(): void
    {
        // 原实现用 ?? 合并：字段名为空字符串（非 null）时不回退列名，直接跳过
        $mapping = ImportSqlBuilder::buildImportFieldMapping([
            ['字段名' => '', '列名' => 'c1', '查询名' => ''],
        ]);

        $this->assertSame([], $mapping['fieldNames']);
        $this->assertSame([], $mapping['selectParts']);
    }

    public function testImportFromTempSqlWithAndWithoutWhere(): void
    {
        $noWhere = ImportSqlBuilder::buildImportFromTempSql('t', 'tmp', ['`a`'], ['`a`'], '');
        $this->assertSame('INSERT INTO `t` (`a`) SELECT `a` FROM `tmp`', $noWhere);

        $withWhere = ImportSqlBuilder::buildImportFromTempSql('t', 'tmp', ['`a`'], ['q as `a`'], 'a>1');
        $this->assertSame('INSERT INTO `t` (`a`) SELECT q as `a` FROM `tmp` WHERE a>1', $withWhere);
    }

    // ---- 校验 SQL ----

    public function testFixedValueCheckSqlMatchesTemplate(): void
    {
        $sql = ImportSqlBuilder::buildFixedValueCheckSql('f1', 'tmp_t', 'OBJ', 'BJ');

        $this->assertStringNotContainsString('%s', $sql); // 模板占位符已全部填充
        $this->assertStringContainsString('select "f1" as 字段名, `f1` as 字段值', $sql);
        $this->assertStringContainsString('from `tmp_t`', $sql);
        $this->assertStringContainsString('where 对象名称="OBJ"', $sql);
        $this->assertStringContainsString('locate(属地,"BJ")', $sql);
        $this->assertStringStartsWith("\n", $sql); // 保留原模板首换行（SQL 文本逐字一致）
    }

    public function testConditionCheckSql(): void
    {
        $this->assertSame(
            'select "列一" as 字段名, `f1` as 字段值 from `tmp_t` where `f1` = "bad"',
            ImportSqlBuilder::buildConditionCheckSql('列一', 'f1', 'tmp_t', '`f1` = "bad"')
        );
    }

    public function testDateFetchSql(): void
    {
        $this->assertSame(
            'select "列一" as 字段名, `f1` as 字段值 from `tmp_t`',
            ImportSqlBuilder::buildDateFetchSql('列一', 'f1', 'tmp_t')
        );
    }

    // ---- 滤重 ----

    public function testParseDuplicateFields(): void
    {
        $this->assertSame(
            ['身份证号', '姓名'],
            ImportSqlBuilder::parseDuplicateFields('身份证号`,`姓名')
        );
        $this->assertSame([], ImportSqlBuilder::parseDuplicateFields(''));
        // 仅有分隔符 → 分隔出空片段被过滤
        $this->assertSame([], ImportSqlBuilder::parseDuplicateFields('`,`'));
    }

    public function testQuoteFieldList(): void
    {
        $this->assertSame('`a`, `b`', ImportSqlBuilder::quoteFieldList(['a', 'b']));
    }

    public function testTempFetchSql(): void
    {
        $this->assertSame(
            'select `a`, `b` from `tmp_x`',
            ImportSqlBuilder::buildTempFetchSql('`a`, `b`', 'tmp_x')
        );
    }

    public function testBuildTuplesSkipsNullFieldRow(): void
    {
        $tuples = ImportSqlBuilder::buildTuples([
            ['a' => 'x', 'b' => 'y'],
            ['a' => null, 'b' => 'y'],          // NULL → 整行跳过
            ['b' => 'z'],                        // a 缺键（null 语义）→ 跳过
            ['a' => 'p', 'b' => ''],             // 空串参与匹配
        ], ['a', 'b'], $this->quote);

        $this->assertSame(["('x','y')", "('p','')"], $tuples);
    }

    public function testDuplicateCheckSql(): void
    {
        $sql = ImportSqlBuilder::buildDuplicateCheckSql(
            'a`,`b',
            'data_t',
            '`a`, `b`',
            ["('x','y')", "('p','')"]
        );

        $this->assertSame(
            'select `a`,`b` from `data_t` where (`a`, `b`) in ((\'x\',\'y\'),(\'p\',\'\'))',
            $sql
        );
    }
}
