<?php

namespace Tests\Unit;

use App\Libraries\MatchRelationProcessor;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * MatchRelationProcessor 特征测试
 *
 * 锁定 buildRelation / revokeRelation 依赖的纯逻辑：
 * - buildAToBUpdateData / buildBToAUpdateData（写入指令 → 更新数据）
 * - mergeTargetKeys / removeTargetKeys（旧版 key 回退与撤销 specific）
 * - collectNeededFields / computeMissingFields / isTargetCovered / collectTargetFields
 */
class MatchRelationProcessorTest extends CIUnitTestCase
{
    // ---- collectNeededFields / computeMissingFields ----

    public function testCollectNeededFields(): void
    {
        $writes = [
            'bToA' => [
                ['targetField' => '记账表ID', 'sourceType' => 'uuid', 'sourceTable' => '', 'sourceField' => ''],
            ],
            'aToB' => [
                ['targetField' => '银行流水号', 'sourceType' => 'field', 'sourceTable' => 'A', 'sourceField' => '流水号'],
                ['targetField' => '备注', 'sourceType' => 'field', 'sourceTable' => 'B', 'sourceField' => '说明'],
                ['targetField' => '状态', 'sourceType' => 'literal', 'sourceTable' => '', 'sourceField' => '已核对'],
            ],
        ];

        $needed = MatchRelationProcessor::collectNeededFields($writes);

        // A 侧：bToA targetField + aToB field/A 源
        $this->assertSame(['记账表ID', '流水号'], $needed['a']);
        // B 侧：逐条 aToB 指令（B 源字段 → targetField）顺序追加
        $this->assertSame(['银行流水号', '说明', '备注', '状态'], $needed['b']);
    }

    public function testComputeMissingFieldsDedupAndExisting(): void
    {
        $firstRow = ['记账表ID' => 'x', '无关字段' => 'y'];

        $missing = MatchRelationProcessor::computeMissingFields(
            ['记账表ID', '流水号', '流水号', ''],
            $firstRow
        );

        // 已存在键过滤、去重、空字段保留（与原 array_filter 语义一致）
        $this->assertSame(['流水号', ''], array_values($missing));
    }

    // ---- isTargetCovered ----

    public function testIsTargetCovered(): void
    {
        $writes = [['targetField' => '记账表ID'], ['targetField' => '备注']];

        $this->assertTrue(MatchRelationProcessor::isTargetCovered($writes, '记账表ID'));
        $this->assertFalse(MatchRelationProcessor::isTargetCovered($writes, '状态'));
        $this->assertFalse(MatchRelationProcessor::isTargetCovered([], '记账表ID'));
    }

    // ---- buildAToBUpdateData ----

    public function testBuildAToBFieldASourceJoinsAllAValues(): void
    {
        $aToBWrites = [[
            'targetField' => '银行流水号',
            'sourceType' => 'field',
            'sourceTable' => 'A',
            'sourceField' => '流水号',
        ]];
        $aKeys = ['a1', 'a2'];
        $aRecords = [
            'a1' => ['流水号' => 'S1'],
            'a2' => ['流水号' => 'S2'],
        ];

        $data = MatchRelationProcessor::buildAToBUpdateData($aToBWrites, $aKeys, $aRecords, [], 'b1');

        $this->assertSame(['银行流水号' => 'S1;S2'], $data);
    }

    public function testBuildAToBFieldASourceSkipsEmptyValues(): void
    {
        $aToBWrites = [[
            'targetField' => '银行流水号',
            'sourceType' => 'field',
            'sourceTable' => 'A',
            'sourceField' => '流水号',
        ]];
        $aRecords = [
            'a1' => ['流水号' => 'S1'],
            'a2' => ['流水号' => ''],
            'a3' => [],
        ];

        $data = MatchRelationProcessor::buildAToBUpdateData($aToBWrites, ['a1', 'a2', 'a3'], $aRecords, [], 'b1');

        $this->assertSame(['银行流水号' => 'S1'], $data);
    }

    public function testBuildAToBUuidSingleValue(): void
    {
        // aToB 的 uuid 源：单值（与 bToA 的逐条生成语义不同）
        $aToBWrites = [[
            'targetField' => '关联ID',
            'sourceType' => 'uuid',
            'sourceTable' => '',
            'sourceField' => '',
        ]];

        $data = MatchRelationProcessor::buildAToBUpdateData($aToBWrites, ['a1', 'a2'], ['a1' => [], 'a2' => []], [], 'b1');

        $this->assertCount(1, $data);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $data['关联ID']
        );
    }

    public function testBuildAToBLiteralAndBFieldTakeFirstARecord(): void
    {
        $aToBWrites = [
            ['targetField' => '状态', 'sourceType' => 'literal', 'sourceTable' => '', 'sourceField' => '已核对'],
            ['targetField' => 'B侧说明', 'sourceType' => 'field', 'sourceTable' => 'B', 'sourceField' => '说明'],
        ];
        $bRecords = ['b1' => ['说明' => '来自B']];

        $data = MatchRelationProcessor::buildAToBUpdateData($aToBWrites, ['a1'], ['a1' => []], $bRecords, 'b1');

        $this->assertSame(['状态' => '已核对', 'B侧说明' => '来自B'], $data);
    }

    // ---- buildBToAUpdateData ----

    public function testBuildBToAFieldBSourceJoinsAllBValues(): void
    {
        $bToAWrites = [[
            'targetField' => '备注',
            'sourceType' => 'field',
            'sourceTable' => 'B',
            'sourceField' => '说明',
        ]];
        $bKeys = ['b1', 'b2'];
        $bRecords = [
            'b1' => ['说明' => 'x'],
            'b2' => ['说明' => 'y'],
        ];

        $data = MatchRelationProcessor::buildBToAUpdateData($bToAWrites, $bKeys, $bRecords, [], 'a1');

        $this->assertSame(['备注' => 'x;y'], $data);
    }

    public function testBuildBToAUuidOnePerBRecord(): void
    {
        // bToA 的 uuid 源：按 B 记录数逐条生成后分号拼接
        $bToAWrites = [[
            'targetField' => '记账表ID',
            'sourceType' => 'uuid',
            'sourceTable' => '',
            'sourceField' => '',
        ]];

        $data = MatchRelationProcessor::buildBToAUpdateData($bToAWrites, ['b1', 'b2'], ['b1' => [], 'b2' => []], [], 'a1');

        $this->assertCount(1, $data);
        $parts = explode(';', $data['记账表ID']);
        $this->assertCount(2, $parts);
        $this->assertNotSame($parts[0], $parts[1]);
        foreach ($parts as $uuid) {
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $uuid
            );
        }
    }

    public function testBuildBToALiteralTakesARecordContext(): void
    {
        $bToAWrites = [
            ['targetField' => '状态', 'sourceType' => 'literal', 'sourceTable' => '', 'sourceField' => '已核对'],
            ['targetField' => 'A侧引用', 'sourceType' => 'field', 'sourceTable' => 'A', 'sourceField' => '流水号'],
        ];
        $aRecords = ['a1' => ['流水号' => 'S1']];

        $data = MatchRelationProcessor::buildBToAUpdateData($bToAWrites, ['b1'], ['b1' => []], $aRecords, 'a1');

        $this->assertSame(['状态' => '已核对', 'A侧引用' => 'S1'], $data);
    }

    // ---- mergeTargetKeys ----

    public function testMergeTargetKeysAppendsDedup(): void
    {
        $this->assertSame('k1,k2,k3', MatchRelationProcessor::mergeTargetKeys('k1', ['k2', 'k1', 'k3']));
    }

    public function testMergeTargetKeysEmptyCurrent(): void
    {
        $this->assertSame('k1,k2', MatchRelationProcessor::mergeTargetKeys('', ['k1', 'k2']));
    }

    // ---- removeTargetKeys ----

    public function testRemoveTargetKeysMixedSeparators(): void
    {
        // 兼容分号（写入指令）和逗号（旧版 key 回退）两种分隔符，输出统一分号
        $this->assertSame('k2;k3', MatchRelationProcessor::removeTargetKeys('k1;k2,k3', ['k1']));
    }

    public function testRemoveTargetKeysFiltersEmptySegments(): void
    {
        $this->assertSame('k2', MatchRelationProcessor::removeTargetKeys(' k1 ; ; k2 ,', ['k1']));
    }

    public function testRemoveTargetKeysAllRemoved(): void
    {
        $this->assertSame('', MatchRelationProcessor::removeTargetKeys('k1;k2', ['k1', 'k2']));
    }

    // ---- collectTargetFields ----

    public function testCollectTargetFieldsDedupKeepsOrder(): void
    {
        $writes = [
            ['targetField' => '备注'],
            ['targetField' => '记账表ID'],
            ['targetField' => '备注'],
        ];

        $this->assertSame(['备注', '记账表ID'], MatchRelationProcessor::collectTargetFields($writes));
    }
}
