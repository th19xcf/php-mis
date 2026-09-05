<?php

namespace Tests\Unit;

use App\Libraries\SessionUserContext;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\FakeMcommon;
use Tests\Support\ResetsStaticState;
use Tests\Support\TestableBaseApiController;

/**
 * insertRecord / updateRecord / deleteRecord 特征测试（重构安全网）
 *
 * FakeMcommon 全链路（内存记录 SQL，不触库），仅使用非审计表 def_dept
 * （审计表走 AuditLogService 严格分支直构 new，不可注桩，冒烟人工覆盖）。
 * SQL 精确串与审计文案逐字锁定，Phase 3 抽取后应零改动通过。
 */
class BaseApiControllerRecordTest extends CIUnitTestCase
{
    use ResetsStaticState;

    private TestableBaseApiController $controller;
    private FakeMcommon $fake;

    /** def_dept 桩列（无 UUID 列的普通表） */
    private const DEPT_COLS = ['GUID', '部门编码', '部门名称'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = TestableBaseApiController::make();
        $this->fake = new FakeMcommon();
        $this->controller->setModel($this->fake);
        $this->controller->setTableColumns('def_dept', self::DEPT_COLS);
        SessionUserContext::setJwtUser((object) [
            'region'   => 'SZ',
            'workId'   => 'A001',
            'userName' => 'tester',
        ]);
    }

    protected function tearDown(): void
    {
        $this->resetStaticState();
        parent::tearDown();
    }

    // ---- insertRecord ----

    public function testInsertInvalidTableThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('非法表名: bad;table');
        $this->controller->insertRecordEx('bad;table', []);
    }

    public function testInsertSqlExactString(): void
    {
        $this->fake->nextExecAffected = 0; // 跳过审计分支，聚焦 INSERT SQL

        $affected = $this->controller->insertRecordEx('def_dept', [
            '部门编码' => 'D01',
            '部门名称' => 'Sales',
            '操作'     => 'x',      // 跳过
            '不存在列' => 'v',      // 表列过滤
        ]);

        $this->assertSame(0, $affected);
        $this->assertSame(
            ["INSERT INTO `def_dept` (`部门编码`,`部门名称`) VALUES ('D01','Sales')"],
            $this->fake->execSqlLog
        );
    }

    public function testInsertAutoUuidHexFormat(): void
    {
        $this->controller->setTableColumns('def_contract_master', ['GUID', 'UUID', '合同编号']);
        $this->fake->nextExecAffected = 0;

        $this->controller->insertRecordEx('def_contract_master', ['合同编号' => 'C001']);

        $this->assertCount(1, $this->fake->execSqlLog);
        $this->assertMatchesRegularExpression(
            "/^INSERT INTO `def_contract_master` \(`合同编号`,`UUID`\) VALUES \('C001',0x[0-9a-f]{32}\)$/",
            $this->fake->execSqlLog[0]
        );
    }

    public function testInsertAllFieldsFilteredReturnsZero(): void
    {
        $this->controller->setTableColumns('def_dept', ['GUID']);

        $affected = $this->controller->insertRecordEx('def_dept', ['不存在列' => 'x']);

        $this->assertSame(0, $affected);
        $this->assertSame([], $this->fake->execSqlLog);
    }

    public function testInsertAuditSqlWrittenWithJwtWorkId(): void
    {
        $this->fake->nextExecAffected = 1;
        $this->fake->nextInsertId = 42;

        $affected = $this->controller->insertRecordEx('def_dept', ['部门编码' => 'D01']);

        $this->assertSame(1, $affected);
        $this->assertCount(2, $this->fake->execSqlLog);
        $this->assertSame(
            "INSERT INTO def_audit_log (表名, 记录GUID, 记录UUID, 操作类型, 变更字段, 原值, 新值, 操作人员) "
            . "VALUES ('def_dept', '42', 0x00000000000000000000000000000000, '新增', '全部', NULL, '新增记录', 'A001')",
            $this->fake->execSqlLog[1]
        );
    }

    public function testInsertNoAuditWhenAffectedZero(): void
    {
        $this->fake->nextExecAffected = 0;

        $this->controller->insertRecordEx('def_dept', ['部门编码' => 'D01']);

        $this->assertCount(1, $this->fake->execSqlLog);
    }

    public function testInsertOperatorFallsBackToSystemWithoutJwt(): void
    {
        // 清除 JWT：writeAuditLog 内部 operator 解析异常吞噬后兜底 'system'
        $ref = new \ReflectionProperty(SessionUserContext::class, 'jwtUser');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        $this->fake->nextExecAffected = 1;
        $this->fake->nextInsertId = 7;

        $this->controller->insertRecordEx('def_dept', ['部门编码' => 'D01']);

        $this->assertCount(2, $this->fake->execSqlLog);
        $this->assertStringEndsWith(", '新增记录', 'system')", $this->fake->execSqlLog[1]);
    }

    // ---- updateRecord ----

    public function testUpdateInvalidTableThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('非法表名: `bad`');
        $this->controller->updateRecordEx('`bad`', ['部门编码' => 'D02'], 'GUID=1');
    }

    public function testUpdateEffectiveKeysSkipGuidOperationPersonAndEmpty(): void
    {
        $this->fake->nextExecAffected = 0; // 无审计；快照 SELECT 无预置结果 → 空数组

        $affected = $this->controller->updateRecordEx('def_dept', [
            'guid'    => '1',    // 跳过
            '操作'    => 'x',    // 跳过
            '人员'    => 'y',    // 跳过
            '空值列'  => '',     // 空串跳过
            '不存在列' => 'v',   // 表列过滤
            '部门编码' => 'D02',
        ], 'GUID=1');

        $this->assertSame(0, $affected);
        $this->assertSame(
            ["UPDATE `def_dept` SET `部门编码`='D02' WHERE GUID=1"],
            $this->fake->execSqlLog
        );
    }

    public function testUpdateSnapshotSelectSql(): void
    {
        $this->fake->nextExecAffected = 0;

        $this->controller->updateRecordEx('def_dept', ['部门编码' => 'D02'], 'GUID=1');

        // 快照 SELECT：GUID + 实际更新键（无 UUID 列不追加）
        $this->assertSame(
            ["SELECT `GUID`,`部门编码` FROM `def_dept` WHERE GUID=1"],
            $this->fake->selectSqlLog
        );
    }

    public function testUpdateDiffAuditPerChangedField(): void
    {
        $snapshotSql = "SELECT `GUID`,`部门编码`,`部门名称` FROM `def_dept` WHERE GUID=1";
        $this->fake->setSelectResult($snapshotSql, [
            ['GUID' => '1', '部门编码' => 'D01', '部门名称' => 'Old'],
        ]);
        $this->fake->nextExecAffected = 1;

        $affected = $this->controller->updateRecordEx('def_dept', [
            '部门编码' => 'D01',  // 未变化 → 不写审计
            '部门名称' => 'New',  // 变化 → 写审计
        ], 'GUID=1');

        $this->assertSame(1, $affected);
        $this->assertCount(2, $this->fake->execSqlLog);
        $this->assertSame(
            "INSERT INTO def_audit_log (表名, 记录GUID, 记录UUID, 操作类型, 变更字段, 原值, 新值, 操作人员) "
            . "VALUES ('def_dept', '1', 0x00000000000000000000000000000000, '更新', '部门名称', 'Old', 'New', 'A001')",
            $this->fake->execSqlLog[1]
        );
    }

    public function testUpdateEmptyStringValueSkipsFieldEntirely(): void
    {
        // 空串值在 effectiveUpdateKeys 阶段即被跳过：不产生 UPDATE，也谈不上 diff 审计
        $affected = $this->controller->updateRecordEx('def_dept', ['部门名称' => ''], 'GUID=1');

        $this->assertSame(0, $affected);
        $this->assertSame([], $this->fake->execSqlLog);
    }

    public function testUpdateNullOldValueWritesNullLiteral(): void
    {
        $snapshotSql = "SELECT `GUID`,`部门名称` FROM `def_dept` WHERE GUID=1";
        $this->fake->setSelectResult($snapshotSql, [
            ['GUID' => '1', '部门名称' => null],
        ]);
        $this->fake->nextExecAffected = 1;

        $this->controller->updateRecordEx('def_dept', ['部门名称' => 'New'], 'GUID=1');

        // 旧值 NULL → 审计 SQL 原值写 NULL 字面量（非字符串 'NULL'）
        $this->assertCount(2, $this->fake->execSqlLog);
        $this->assertMatchesRegularExpression(
            "/VALUES \('def_dept', '1', 0x[0-9a-f]{32}, '更新', '部门名称', NULL, 'New', 'A001'\)$/",
            $this->fake->execSqlLog[1]
        );
    }

    public function testUpdateSnapshotErrorStillUpdates(): void
    {
        $snapshotSql = "SELECT `GUID`,`部门编码` FROM `def_dept` WHERE GUID=1";
        $this->fake->setSelectError($snapshotSql, new \RuntimeException('boom'));
        $this->fake->nextExecAffected = 1;

        $affected = $this->controller->updateRecordEx('def_dept', ['部门编码' => 'D02'], 'GUID=1');

        // 快照失败被吞掉：照常 UPDATE、无审计
        $this->assertSame(1, $affected);
        $this->assertSame(
            ["UPDATE `def_dept` SET `部门编码`='D02' WHERE GUID=1"],
            $this->fake->execSqlLog
        );
        $this->assertCount(1, $this->controller->traceLog);
        $this->assertSame('error', $this->controller->traceLog[0]['level']);
        $this->assertSame(
            '审计日志读取旧值失败(update) table=def_dept: boom',
            $this->controller->traceLog[0]['message']
        );
    }

    public function testUpdateTruncatesValuesTo200Chars(): void
    {
        $snapshotSql = "SELECT `GUID`,`部门名称` FROM `def_dept` WHERE GUID=1";
        $this->fake->setSelectResult($snapshotSql, [
            ['GUID' => '1', '部门名称' => str_repeat('a', 250)],
        ]);
        $this->fake->nextExecAffected = 1;

        $this->controller->updateRecordEx('def_dept', ['部门名称' => str_repeat('b', 250)], 'GUID=1');

        $this->assertCount(2, $this->fake->execSqlLog);
        $auditSql = $this->fake->execSqlLog[1];
        // mb_substr 截断到 200 字符（匹配 def_audit_log varchar(200) 列宽）
        $this->assertStringContainsString("'" . str_repeat('a', 200) . "'", $auditSql);
        $this->assertStringContainsString("'" . str_repeat('b', 200) . "'", $auditSql);
        $this->assertStringNotContainsString(str_repeat('a', 201), $auditSql);
        $this->assertStringNotContainsString(str_repeat('b', 201), $auditSql);
    }

    public function testUpdateNoEffectiveKeysReturnsZero(): void
    {
        $affected = $this->controller->updateRecordEx('def_dept', [
            'guid' => '1',
            '操作' => 'x',
        ], 'GUID=1');

        $this->assertSame(0, $affected);
        $this->assertSame([], $this->fake->execSqlLog);
    }

    // ---- deleteRecord ----

    public function testDeleteInvalidTableThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('非法表名: drop table');
        $this->controller->deleteRecordEx('drop table', 'GUID=1');
    }

    public function testDeleteSoftDeleteUpdateSql(): void
    {
        $this->controller->setTableColumns('def_dept', [
            'GUID', '操作记录', '操作来源', '操作人员', '结束操作时间',
            '删除标识', '有效标识', '记录结束日期',
        ]);
        $snapshotSql = 'SELECT `GUID` FROM `def_dept` WHERE GUID=1';
        $this->fake->setSelectResult($snapshotSql, [['GUID' => '1']]);
        $this->fake->nextExecAffected = 1;

        $affected = $this->controller->deleteRecordEx('def_dept', 'GUID=1');

        $this->assertSame(1, $affected);
        // 快照有行 + affected>0 → 软删 UPDATE + 审计 INSERT 两条
        $this->assertCount(2, $this->fake->execSqlLog);
        $sql = $this->fake->execSqlLog[0];
        $this->assertStringStartsWith('UPDATE `def_dept` SET ', $sql);
        // 软删审计字段（AuditFieldsTrait::buildDeleteData）
        $this->assertStringContainsString("`操作记录`='删除'", $sql);
        $this->assertStringContainsString("`操作来源`='页面删除'", $sql);
        $this->assertStringContainsString("`操作人员`='A001'", $sql);
        $this->assertStringContainsString("`删除标识`='1'", $sql);
        $this->assertStringContainsString("`有效标识`='0'", $sql);
        // 记录结束日期 = 当日
        $this->assertStringContainsString("`记录结束日期`='" . date('Y-m-d') . "'", $sql);
        $this->assertStringEndsWith(' WHERE GUID=1', $sql);
    }

    public function testDeleteAuditSqlWritten(): void
    {
        $this->controller->setTableColumns('def_dept', [
            'GUID', '操作记录', '操作来源', '操作人员', '结束操作时间',
            '删除标识', '有效标识', '记录结束日期',
        ]);
        $snapshotSql = 'SELECT `GUID` FROM `def_dept` WHERE GUID=1';
        $this->fake->setSelectResult($snapshotSql, [['GUID' => '1']]);
        $this->fake->nextExecAffected = 1;

        $this->controller->deleteRecordEx('def_dept', 'GUID=1');

        $this->assertCount(2, $this->fake->execSqlLog);
        $this->assertSame(
            "INSERT INTO def_audit_log (表名, 记录GUID, 记录UUID, 操作类型, 变更字段, 原值, 新值, 操作人员) "
            . "VALUES ('def_dept', '1', 0x00000000000000000000000000000000, '删除', '全部', '删除前记录', NULL, 'A001')",
            $this->fake->execSqlLog[1]
        );
    }

    public function testDeleteWithoutEndDateColumnOmitsIt(): void
    {
        $this->controller->setTableColumns('def_dept', ['GUID', '删除标识', '有效标识']);
        $this->fake->nextExecAffected = 1;

        $this->controller->deleteRecordEx('def_dept', 'GUID=1');

        $this->assertStringNotContainsString('记录结束日期', $this->fake->execSqlLog[0]);
    }

    public function testDeleteAllAuditFieldsFilteredReturnsZero(): void
    {
        // 表只有 GUID 列：buildDeleteData 全部字段被过滤 + 无记录结束日期 → 0
        $this->controller->setTableColumns('def_dept', ['GUID']);

        $affected = $this->controller->deleteRecordEx('def_dept', 'GUID=1');

        $this->assertSame(0, $affected);
        $this->assertSame([], $this->fake->execSqlLog);
    }
}
