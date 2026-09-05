<?php

namespace Tests\Unit;

use App\Libraries\RecordSqlBuilder;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * RecordSqlBuilder 直连测试
 *
 * Phase 1 抽取后：由 BaseApiController Reflection 版特征测试改为直连静态方法
 * （用例与断言不变），锁定标识符校验与 UUIDv7 生成行为。
 */
class RecordSqlBuilderTest extends CIUnitTestCase
{
    // ---- isValidIdentifier ----

    public function testEmptyIdentifierIsInvalid(): void
    {
        $this->assertFalse(RecordSqlBuilder::isValidIdentifier(''));
    }

    public function testAsciiIdentifiersAreValid(): void
    {
        $this->assertTrue(RecordSqlBuilder::isValidIdentifier('abc'));
        $this->assertTrue(RecordSqlBuilder::isValidIdentifier('ab_c'));
        $this->assertTrue(RecordSqlBuilder::isValidIdentifier('_x'));
        $this->assertTrue(RecordSqlBuilder::isValidIdentifier('a1b2'));
    }

    public function testLeadingDigitIsInvalid(): void
    {
        $this->assertFalse(RecordSqlBuilder::isValidIdentifier('1abc'));
    }

    public function testSpaceIsInvalid(): void
    {
        $this->assertFalse(RecordSqlBuilder::isValidIdentifier('a b'));
    }

    public function testSqlInjectionCharsAreInvalid(): void
    {
        $this->assertFalse(RecordSqlBuilder::isValidIdentifier("a';drop"));
        $this->assertFalse(RecordSqlBuilder::isValidIdentifier('a--b'));
        $this->assertFalse(RecordSqlBuilder::isValidIdentifier('`x`'));
    }

    public function testDashIsInvalid(): void
    {
        $this->assertFalse(RecordSqlBuilder::isValidIdentifier('表-名'));
    }

    public function testChineseIdentifiersAreValid(): void
    {
        $this->assertTrue(RecordSqlBuilder::isValidIdentifier('部门编码'));
        $this->assertTrue(RecordSqlBuilder::isValidIdentifier('部门_1'));
        $this->assertTrue(RecordSqlBuilder::isValidIdentifier('记录结束日期'));
    }

    public function testMixedChineseAsciiIsValid(): void
    {
        $this->assertTrue(RecordSqlBuilder::isValidIdentifier('候选人code'));
    }

    public function testNumericOnlyAfterFirstCharIsValid(): void
    {
        $this->assertTrue(RecordSqlBuilder::isValidIdentifier('_123'));
    }

    // ---- generateUuidv7Binary ----

    public function testUuidIs16Bytes(): void
    {
        $this->assertSame(16, strlen(RecordSqlBuilder::generateUuidv7Binary()));
    }

    public function testUuidVersionIs7(): void
    {
        $u = RecordSqlBuilder::generateUuidv7Binary();
        // byte[6] 高 4 位 = 0b0111
        $this->assertSame(0x7, ord($u[6]) >> 4);
    }

    public function testUuidVariantIsRfc4122(): void
    {
        $u = RecordSqlBuilder::generateUuidv7Binary();
        // byte[8] 高 2 位 = 0b10
        $this->assertSame(0b10, ord($u[8]) >> 6);
    }

    public function testRandomSegmentDiffersBetweenCalls(): void
    {
        $a = RecordSqlBuilder::generateUuidv7Binary();
        $b = RecordSqlBuilder::generateUuidv7Binary();
        // 随机段（bytes 6-15）两次调用应不同（概率上 2^-80 碰撞，可视为确定性）
        $this->assertNotSame(substr($a, 6), substr($b, 6));
    }

    public function testTimestampSegmentMonotonicNonDecreasing(): void
    {
        $a = RecordSqlBuilder::generateUuidv7Binary();
        usleep(1000); // 1ms，确保毫秒时间戳推进
        $b = RecordSqlBuilder::generateUuidv7Binary();

        $tsA = hexdec(bin2hex(substr($a, 0, 6)));
        $tsB = hexdec(bin2hex(substr($b, 0, 6)));
        $this->assertGreaterThanOrEqual($tsA, $tsB);
    }

    public function testTimestampSegmentMatchesCurrentMillis(): void
    {
        $before = (int) (microtime(true) * 1000);
        $u = RecordSqlBuilder::generateUuidv7Binary();
        $after = (int) (microtime(true) * 1000);

        $ts = hexdec(bin2hex(substr($u, 0, 6)));
        $this->assertGreaterThanOrEqual($before - 5, $ts);
        $this->assertLessThanOrEqual($after + 5, $ts);
    }

    // ---- shouldAutoGenerateUuid ----

    public function testAutoUuidWhenTableHasUuidColumnAndDataMissingUuid(): void
    {
        $this->assertTrue(RecordSqlBuilder::shouldAutoGenerateUuid(['GUID', 'UUID'], ['姓名' => 'x']));
    }

    public function testNoAutoUuidWhenCallerProvidedUuid(): void
    {
        $this->assertFalse(RecordSqlBuilder::shouldAutoGenerateUuid(['GUID', 'UUID'], ['UUID' => '0x00']));
    }

    public function testNoAutoUuidWhenTableHasNoUuidColumn(): void
    {
        $this->assertFalse(RecordSqlBuilder::shouldAutoGenerateUuid(['GUID', '姓名'], ['姓名' => 'x']));
    }

    public function testNoAutoUuidWhenColumnsEmpty(): void
    {
        // 空列表 = SHOW COLUMNS 失败兜底，不自动生成（保持原 insertRecord 行为）
        $this->assertFalse(RecordSqlBuilder::shouldAutoGenerateUuid([], ['姓名' => 'x']));
    }

    public function testNoAutoUuidWhenCallerProvidesNullUuid(): void
    {
        // isset(null) = false 的反例：isset 对 null 返回 false，视为"未提供"→ 自动生成
        $this->assertTrue(RecordSqlBuilder::shouldAutoGenerateUuid(['UUID'], ['UUID' => null]));
    }

    // ---- computeEffectiveUpdateKeys ----

    /** 简单假 quote（夹具限定 ASCII 无引号，与真实 escape 输出一致） */
    private function quote(string $v): string
    {
        return "'" . $v . "'";
    }

    public function testEffectiveKeysSkipRulesAndOrder(): void
    {
        $keys = RecordSqlBuilder::computeEffectiveUpdateKeys([
            'guid'    => '1',
            '操作'    => 'x',
            '人员'    => 'y',
            '空值'    => '',
            '不存在列' => 'v',
            '部门编码' => 'D02',
            '1abc'    => '非法标识符',
        ], ['GUID', '部门编码']);

        $this->assertSame(['部门编码'], $keys);
    }

    // ---- buildInsertSql ----

    public function testBuildInsertSqlExact(): void
    {
        $sql = RecordSqlBuilder::buildInsertSql(
            'def_dept',
            ['部门编码' => 'D01', '部门名称' => 'Sales', '操作' => 'x', '不存在列' => 'v'],
            ['GUID', '部门编码', '部门名称'],
            null,
            fn (string $v) => $this->quote($v)
        );

        $this->assertSame(
            "INSERT INTO `def_dept` (`部门编码`,`部门名称`) VALUES ('D01','Sales')",
            $sql
        );
    }

    public function testBuildInsertSqlAppendsAutoUuid(): void
    {
        $uuid = str_repeat("\x01", 16);

        $sql = RecordSqlBuilder::buildInsertSql('t', ['a' => '1'], ['a', 'UUID'], $uuid, fn (string $v) => $this->quote($v));

        $this->assertSame("INSERT INTO `t` (`a`,`UUID`) VALUES ('1',0x01010101010101010101010101010101)", $sql);
    }

    public function testBuildInsertSqlReturnsNullWhenAllFiltered(): void
    {
        $this->assertNull(RecordSqlBuilder::buildInsertSql(
            't',
            ['不存在列' => 'x'],
            ['GUID'],
            null,
            fn (string $v) => $this->quote($v)
        ));
    }

    // ---- buildUpdateSql ----

    public function testBuildUpdateSqlExact(): void
    {
        $sql = RecordSqlBuilder::buildUpdateSql(
            'def_dept',
            ['部门编码' => 'D02', '部门名称' => 'New'],
            ['部门编码', '部门名称'],
            'GUID=1',
            fn (string $v) => $this->quote($v)
        );

        $this->assertSame("UPDATE `def_dept` SET `部门编码`='D02',`部门名称`='New' WHERE GUID=1", $sql);
    }

    // ---- buildSnapshotSelectSql ----

    public function testSnapshotSelectWithUuidAndKeys(): void
    {
        $sql = RecordSqlBuilder::buildSnapshotSelectSql(
            'def_dept',
            'GUID=1',
            ['GUID', 'UUID', '部门编码', '部门名称'],
            ['部门编码', '部门名称']
        );

        $this->assertSame('SELECT `GUID`,`UUID`,`部门编码`,`部门名称` FROM `def_dept` WHERE GUID=1', $sql);
    }

    public function testSnapshotSelectLocatorFilteredByColumnsAndDeduped(): void
    {
        // 定位列仅在表列存在时追加；与 keys 重复时不重复追加
        $sql = RecordSqlBuilder::buildSnapshotSelectSql(
            'ee_store',
            'GUID=1',
            ['GUID', '人员编码', '姓名'],
            ['姓名'],
            ['人员编码', '候选人编码'] // 候选人编码不在表列 → 不追加
        );

        $this->assertSame('SELECT `GUID`,`人员编码`,`姓名` FROM `ee_store` WHERE GUID=1', $sql);
    }

    // ---- buildSoftDeleteUpdateSql ----

    public function testSoftDeleteSqlWithEndDate(): void
    {
        $sql = RecordSqlBuilder::buildSoftDeleteUpdateSql(
            'def_dept',
            'GUID=1',
            ['删除标识' => '1', '有效标识' => '0', '不存在列' => 'x'],
            ['GUID', '删除标识', '有效标识', '记录结束日期'],
            fn (string $v) => $this->quote($v)
        );

        $this->assertSame(
            "UPDATE `def_dept` SET `删除标识`='1',`有效标识`='0',`记录结束日期`='" . date('Y-m-d') . "' WHERE GUID=1",
            $sql
        );
    }

    public function testSoftDeleteSqlOmitsEndDateWhenColumnMissing(): void
    {
        $sql = RecordSqlBuilder::buildSoftDeleteUpdateSql(
            'def_dept',
            'GUID=1',
            ['删除标识' => '1'],
            ['GUID', '删除标识'],
            fn (string $v) => $this->quote($v)
        );

        $this->assertStringNotContainsString('记录结束日期', $sql);
    }

    public function testSoftDeleteSqlReturnsNullWhenNothingWritable(): void
    {
        $this->assertNull(RecordSqlBuilder::buildSoftDeleteUpdateSql(
            'def_dept',
            'GUID=1',
            ['删除标识' => '1'],
            ['GUID'],
            fn (string $v) => $this->quote($v)
        ));
    }

    // ---- buildAuditInsertSql ----

    public function testAuditInsertSqlUuidPlaceholderAndNullLiterals(): void
    {
        $sql = RecordSqlBuilder::buildAuditInsertSql(
            'def_dept',
            '42',
            null,
            '新增',
            '全部',
            null,
            '新增记录',
            'A001',
            fn (string $v) => $this->quote($v)
        );

        $this->assertSame(
            "INSERT INTO def_audit_log (表名, 记录GUID, 记录UUID, 操作类型, 变更字段, 原值, 新值, 操作人员) "
            . "VALUES ('def_dept', '42', 0x00000000000000000000000000000000, '新增', '全部', NULL, '新增记录', 'A001')",
            $sql
        );
    }

    public function testAuditInsertSqlTruncatesTo200Chars(): void
    {
        $sql = RecordSqlBuilder::buildAuditInsertSql(
            't',
            '1',
            "\x01\x02",
            '更新',
            'f',
            str_repeat('a', 250),
            str_repeat('b', 250),
            'u',
            fn (string $v) => $this->quote($v)
        );

        $this->assertStringContainsString("'" . str_repeat('a', 200) . "'", $sql);
        $this->assertStringContainsString("'" . str_repeat('b', 200) . "'", $sql);
        $this->assertStringContainsString('0x0102', $sql);
    }

    // ---- collectUpdateAuditEntries ----

    public function testCollectEntriesSkipsUnchangedAndKeepsNull(): void
    {
        $entries = RecordSqlBuilder::collectUpdateAuditEntries(
            [
                ['GUID' => '1', '部门编码' => 'D01', '部门名称' => null, 'UUID' => "\xff"],
            ],
            ['部门编码' => 'D01', '部门名称' => 'New'],
            ['部门编码', '部门名称']
        );

        // 部门编码未变化跳过；部门名称 null → 'New' 保留 null 原值
        $this->assertSame([
            [
                'guid' => '1',
                'uuid' => "\xff",
                'field' => '部门名称',
                'old' => null,
                'new' => 'New',
            ],
        ], $entries);
    }

    public function testCollectEntriesEmptyStringAndNullTreatedEqual(): void
    {
        $entries = RecordSqlBuilder::collectUpdateAuditEntries(
            [['GUID' => '1', '备注' => '']],
            ['备注' => null],
            ['备注']
        );

        $this->assertSame([], $entries);
    }
}
