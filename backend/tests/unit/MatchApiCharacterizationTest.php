<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\TestableMatchApi;

/**
 * MatchApi 特征测试（拆分后保留在控制器的逻辑）
 *
 * - buildAuthConditionWithAnd（保留在控制器）
 * - applyMatchedFlag（保留为编排，标记计算已抽取到 MatchRelationProcessor）
 *
 * 注：parseWriteInstructions / resolveWriteSourceValue / generateUuid 的用例
 * 已迁至 MatchConfigParserTest（机械搬移，断言不变）；
 * 库方法行为另见 MatchRelationProcessorTest / MatchColumnBuilderTest。
 */
class MatchApiCharacterizationTest extends CIUnitTestCase
{
    private TestableMatchApi $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = TestableMatchApi::make();
    }

    // ---- buildAuthConditionWithAnd ----

    public function testAuthAndEmptyFieldReturnsEmpty(): void
    {
        $this->assertSame('', $this->api->buildAuthConditionWithAndEx('', 'u', 'r', fn () => 'C', false));
    }

    public function testAuthAndUserOnly(): void
    {
        $result = $this->api->buildAuthConditionWithAndEx('f', 'u1', '', fn (string $f, string $a) => "C($f=$a)", false);
        $this->assertSame('(C(f=u1))', $result);
    }

    public function testAuthAndRoleOnly(): void
    {
        $result = $this->api->buildAuthConditionWithAndEx('f', '', 'r1', fn (string $f, string $a) => "C($f=$a)", false);
        $this->assertSame('(C(f=r1))', $result);
    }

    public function testAuthAndBothJoinedWithAnd(): void
    {
        $result = $this->api->buildAuthConditionWithAndEx('f', 'u1', 'r1', fn (string $f, string $a) => "C($f=$a)", false);
        $this->assertSame('(C(f=u1)) AND (C(f=r1))', $result);
    }

    public function testAuthAndSkipsEmptyCondResults(): void
    {
        // buildFunc 对 userAuth 返回 ''（如赋权值无法解析）→ 只保留 role 条件
        $result = $this->api->buildAuthConditionWithAndEx(
            'f',
            'u1',
            'r1',
            fn (string $f, string $a) => $a === 'u1' ? '' : "C($a)",
            false
        );
        $this->assertSame('(C(r1))', $result);
    }

    public function testAuthAndAllEmptyReturnsEmpty(): void
    {
        $result = $this->api->buildAuthConditionWithAndEx('f', 'u1', 'r1', fn () => '', false);
        $this->assertSame('', $result);
    }

    public function testAuthAndPassesUpkeepToBuildFunc(): void
    {
        $seen = [];
        $this->api->buildAuthConditionWithAndEx(
            'f',
            'u1',
            'r1',
            function (string $f, string $a, bool $u) use (&$seen) {
                $seen[] = [$f, $a, $u];
                return 'C';
            },
            true
        );

        $this->assertSame([['f', 'u1', true], ['f', 'r1', true]], $seen);
    }

    // ---- applyMatchedFlag（传完整行集，补查分支不触发）----

    public function testMatchedFlagAMarkerNonEmpty(): void
    {
        $aRows = [
            ['GUID' => '1', '记账表ID' => 'u1'],
            ['GUID' => '2', '记账表ID' => ''],
        ];
        $bRows = [];
        $writes = ['bToA' => [['targetField' => '记账表ID']], 'aToB' => []];

        $this->api->applyMatchedFlagEx($aRows, $bRows, $writes, '', '', []);

        $this->assertTrue($aRows[0]['__matched']);
        $this->assertFalse($aRows[1]['__matched']);
    }

    public function testMatchedFlagNoBToAAllAFalse(): void
    {
        $aRows = [['GUID' => '1', 'x' => 'y']];
        $bRows = [];
        $writes = ['bToA' => [], 'aToB' => []];

        $this->api->applyMatchedFlagEx($aRows, $bRows, $writes, '', '', []);

        $this->assertFalse($aRows[0]['__matched']);
    }

    public function testMatchedFlagBViaAToBLink(): void
    {
        $aRows = [
            ['GUID' => '1', '记账表ID' => 'u1', '流水号' => 'S1'],
            ['GUID' => '2', '记账表ID' => '', '流水号' => 'S2'],
        ];
        $bRows = [
            ['GUID' => 'b1', '银行流水号' => 'S1'],
            ['GUID' => 'b2', '银行流水号' => 'S2'],
            ['GUID' => 'b3', '银行流水号' => ''],
        ];
        $writes = [
            'bToA' => [['targetField' => '记账表ID']],
            'aToB' => [[
                'targetField' => '银行流水号',
                'sourceType' => 'field',
                'sourceTable' => 'A',
                'sourceField' => '流水号',
            ]],
        ];

        $this->api->applyMatchedFlagEx($aRows, $bRows, $writes, '', '', []);

        $this->assertTrue($aRows[0]['__matched']);
        $this->assertFalse($aRows[1]['__matched']);
        $this->assertTrue($bRows[0]['__matched']);
        $this->assertFalse($bRows[1]['__matched']);
        $this->assertFalse($bRows[2]['__matched']);
    }

    public function testMatchedFlagNoAToBLinkAllBFalse(): void
    {
        $aRows = [['GUID' => '1', '记账表ID' => 'u1']];
        $bRows = [['GUID' => 'b1', '银行流水号' => 'S1']];
        // aToB 存在但无 field/A 源（literal）→ 无交叉引用链
        $writes = [
            'bToA' => [['targetField' => '记账表ID']],
            'aToB' => [['targetField' => '状态', 'sourceType' => 'literal', 'sourceTable' => '', 'sourceField' => '已核对']],
        ];

        $this->api->applyMatchedFlagEx($aRows, $bRows, $writes, '', '', []);

        $this->assertTrue($aRows[0]['__matched']);
        $this->assertFalse($bRows[0]['__matched']);
    }

    public function testMatchedFlagMatchedAWithEmptySourceNotLinked(): void
    {
        $aRows = [['GUID' => '1', '记账表ID' => 'u1', '流水号' => '']];
        $bRows = [['GUID' => 'b1', '银行流水号' => 'S1']];
        $writes = [
            'bToA' => [['targetField' => '记账表ID']],
            'aToB' => [[
                'targetField' => '银行流水号',
                'sourceType' => 'field',
                'sourceTable' => 'A',
                'sourceField' => '流水号',
            ]],
        ];

        $this->api->applyMatchedFlagEx($aRows, $bRows, $writes, '', '', []);

        $this->assertTrue($aRows[0]['__matched']);
        $this->assertFalse($bRows[0]['__matched']); // 已匹配 A 的源值为空 → 不建立关联
    }

    public function testMatchedFlagEmptyRowsNoError(): void
    {
        $aRows = [];
        $bRows = [['GUID' => 'b1']];

        $this->api->applyMatchedFlagEx($aRows, $bRows, ['bToA' => [['targetField' => 'x']], 'aToB' => []], '', '', []);

        $this->assertFalse($bRows[0]['__matched']);
    }
}
