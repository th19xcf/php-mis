<?php

namespace Tests\Unit;

use App\Libraries\MatchConfigParser;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * MatchConfigParser 特征测试
 *
 * 用例自 MatchApiCharacterizationTest 机械迁入（parseWriteInstructions /
 * resolveWriteSourceValue / generateUuid 抽取到本库，断言不变），
 * 另补 parseMatchConditions / parseCalcFields 纯函数用例。
 */
class MatchConfigParserTest extends CIUnitTestCase
{
    // ---- parseMatchConditions ----

    public function testParseConditionsEmptyRaw(): void
    {
        $this->assertSame([], MatchConfigParser::parseMatchConditions(''));
    }

    public function testParseConditionsSingle(): void
    {
        $result = MatchConfigParser::parseMatchConditions('A.贷方金额=B.财务计收金额');

        $this->assertSame([[
            'aField' => '贷方金额',
            'bField' => '财务计收金额',
            'text' => 'A.贷方金额=B.财务计收金额',
        ]], $result);
    }

    public function testParseConditionsMultipleSkipsEmptyAndMalformed(): void
    {
        $result = MatchConfigParser::parseMatchConditions(' A.a=B.x ; ; B.x=A.y ; A.b=B.z ');

        $this->assertCount(2, $result);
        $this->assertSame('a', $result[0]['aField']);
        $this->assertSame('z', $result[1]['bField']);
        $this->assertSame('A.b=B.z', $result[1]['text']); // text 为 trim 后片段
    }

    public function testParseConditionsSpacesAroundEqualsNotMatched(): void
    {
        // 现状行为：正则要求 =B. 连续，等号两侧带空格的片段不解析
        $this->assertSame([], MatchConfigParser::parseMatchConditions('A.b = B.z'));
    }

    // ---- parseWriteInstructions ----

    public function testParseWritesEmptyRaw(): void
    {
        $this->assertSame([], MatchConfigParser::parseWriteInstructions('', 'A'));
    }

    public function testParseWritesUuidSource(): void
    {
        $result = MatchConfigParser::parseWriteInstructions('A.记账表ID=uuid', 'A');

        $this->assertSame([[
            'targetField' => '记账表ID',
            'sourceType' => 'uuid',
            'sourceTable' => '',
            'sourceField' => '',
            'text' => 'A.记账表ID=uuid',
        ]], $result);
    }

    public function testParseWritesUuidSourceCaseInsensitive(): void
    {
        $result = MatchConfigParser::parseWriteInstructions('A.f=UUID', 'A');
        $this->assertSame('uuid', $result[0]['sourceType']);
    }

    public function testParseWritesFieldFromA(): void
    {
        $result = MatchConfigParser::parseWriteInstructions('B.银行流水号=A.银行唯一流水号', 'B');

        $this->assertSame([
            'targetField' => '银行流水号',
            'sourceType' => 'field',
            'sourceTable' => 'A',
            'sourceField' => '银行唯一流水号',
            'text' => 'B.银行流水号=A.银行唯一流水号',
        ], $result[0]);
    }

    public function testParseWritesFieldFromB(): void
    {
        $result = MatchConfigParser::parseWriteInstructions('B.备注=B.说明', 'B');

        $this->assertSame('field', $result[0]['sourceType']);
        $this->assertSame('B', $result[0]['sourceTable']);
        $this->assertSame('说明', $result[0]['sourceField']);
    }

    public function testParseWritesLiteral(): void
    {
        $result = MatchConfigParser::parseWriteInstructions('A.状态=已核对', 'A');

        $this->assertSame([
            'targetField' => '状态',
            'sourceType' => 'literal',
            'sourceTable' => '',
            'sourceField' => '已核对',
            'text' => 'A.状态=已核对',
        ], $result[0]);
    }

    public function testParseWritesMultiPartSkipsEmpty(): void
    {
        $result = MatchConfigParser::parseWriteInstructions(' A.a=A.x ; ; A.b=uuid ;', 'A');

        $this->assertCount(2, $result);
        $this->assertSame('a', $result[0]['targetField']);
        $this->assertSame('b', $result[1]['targetField']);
        $this->assertSame('A.a=A.x', $result[0]['text']); // text 为 trim 后片段
    }

    public function testParseWritesPrefixMismatchSkipped(): void
    {
        // 目标前缀 A，但指令以 B. 开头 → 不匹配正则，跳过
        $this->assertSame([], MatchConfigParser::parseWriteInstructions('B.x=A.y', 'A'));
    }

    // ---- resolveWriteSourceValue ----

    public function testResolveSourceUuidFormat(): void
    {
        $value = MatchConfigParser::resolveWriteSourceValue(
            ['sourceType' => 'uuid', 'sourceTable' => '', 'sourceField' => ''],
            [],
            []
        );

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $value
        );
    }

    public function testResolveSourceFieldA(): void
    {
        $value = MatchConfigParser::resolveWriteSourceValue(
            ['sourceType' => 'field', 'sourceTable' => 'A', 'sourceField' => '金额'],
            ['金额' => '100.5'],
            ['金额' => '200']
        );
        $this->assertSame('100.5', $value);
    }

    public function testResolveSourceFieldAMissingKeyReturnsEmpty(): void
    {
        $value = MatchConfigParser::resolveWriteSourceValue(
            ['sourceType' => 'field', 'sourceTable' => 'A', 'sourceField' => '金额'],
            [],
            []
        );
        $this->assertSame('', $value);
    }

    public function testResolveSourceFieldB(): void
    {
        $value = MatchConfigParser::resolveWriteSourceValue(
            ['sourceType' => 'field', 'sourceTable' => 'B', 'sourceField' => '金额'],
            ['金额' => '100.5'],
            ['金额' => '200']
        );
        $this->assertSame('200', $value);
    }

    public function testResolveSourceLiteralReturnsSourceField(): void
    {
        $value = MatchConfigParser::resolveWriteSourceValue(
            ['sourceType' => 'literal', 'sourceTable' => '', 'sourceField' => '已核对'],
            [],
            []
        );
        $this->assertSame('已核对', $value);
    }

    public function testResolveSourceFieldUnknownTableFallsToLiteral(): void
    {
        // 现状行为：field 类型但 sourceTable 非 A/B → 落到 literal 返回 sourceField
        $value = MatchConfigParser::resolveWriteSourceValue(
            ['sourceType' => 'field', 'sourceTable' => 'C', 'sourceField' => 'X'],
            ['X' => 'a'],
            ['X' => 'b']
        );
        $this->assertSame('X', $value);
    }

    // ---- generateUuid ----

    public function testGenerateUuidUnique(): void
    {
        $this->assertNotSame(MatchConfigParser::generateUuid(), MatchConfigParser::generateUuid());
    }

    // ---- parseCalcFields ----

    public function testParseCalcFieldsEmptyRaw(): void
    {
        $this->assertSame([], MatchConfigParser::parseCalcFields(''));
    }

    public function testParseCalcFieldsSkipsEmptyParts(): void
    {
        $this->assertSame(
            ['金额', '日期'],
            MatchConfigParser::parseCalcFields(' 金额 , , 日期 ,')
        );
    }
}
