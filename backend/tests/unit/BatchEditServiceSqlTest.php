<?php

namespace Tests\Unit;

use App\Exceptions\BusinessException;
use App\Services\Workbench\BatchEditService;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\FakeMcommon;

/**
 * BatchEditService SQL 构造路径特征测试（FakeMcommon，不触真库）
 *
 * 锁定现状行为：batchUpdateRowsByModel / tableEditByModel 生成的 SQL 逐字锁定
 * （时间戳以正则 + 实际值回填处理），作为后续抽取 BatchEditSqlBuilder 的安全网。
 *
 * 测试表 tmp_batch_edit_t：非审计表、非阶段表、非 def_* 配置表 → 纯 SQL 路径，
 * 不触发 AuditLogService 日志写入 / PersonService 同步 / 缓存失效副作用。
 */
class BatchEditServiceSqlTest extends CIUnitTestCase
{
    private const TABLE = 'tmp_batch_edit_t';

    private BatchEditService $service;
    private FakeMcommon $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BatchEditService();
        $this->fake = new FakeMcommon();

        $prop = new \ReflectionProperty(BatchEditService::class, 'model');
        $prop->setAccessible(true);
        $prop->setValue($this->service, $this->fake);
    }

    /** 从实际 SQL 中提取首个时间戳（UPDATE 的 ="ts" 或 INSERT 的 'ts'），供断言回填 */
    private function extractNow(string $sql): string
    {
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $sql, 'SQL 中应含时间戳');
        preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $sql, $m);
        return $m[1];
    }

    // ---- batchUpdateRowsByModel：模式 0（直接批量 UPDATE）----

    public function testBatchUpdateMode0ExactSql(): void
    {
        $num = $this->service->batchUpdateRowsByModel(
            self::TABLE,
            '0',
            '主键',
            ['a', 'b', 'a'],
            ['姓名' => '张三', '年龄' => '30'],
            'u1',
            '3001'
        );

        $this->assertSame(1, $num);
        $this->assertCount(1, $this->fake->execSqlLog);
        $this->assertSame(
            "UPDATE tmp_batch_edit_t SET `姓名` = '张三', `年龄` = '30' WHERE `主键` IN ('a','b')",
            $this->fake->execSqlLog[0]
        );
    }

    public function testBatchUpdateMode0PrimaryKeyInFormExcluded(): void
    {
        // formData 含主键键 → 不进 SET；其余为空 → 0 条更新，不发 SQL
        $num = $this->service->batchUpdateRowsByModel(
            self::TABLE,
            '0',
            '主键',
            ['a'],
            ['主键' => 'a'],
            'u1',
            '3001'
        );

        $this->assertSame(0, $num);
        $this->assertSame([], $this->fake->execSqlLog);
    }

    public function testBatchUpdateInvalidModelReturnsMinusOne(): void
    {
        $num = $this->service->batchUpdateRowsByModel(self::TABLE, '9', '主键', ['a'], ['f' => 'v'], 'u1', '3001');

        $this->assertSame(-1, $num);
        $this->assertSame([], $this->fake->execSqlLog);
    }

    // ---- batchUpdateRowsByModel：模式 1/2（流水版本化）----

    public function testBatchUpdateMode1ExactSqls(): void
    {
        $selectSql = "SELECT * FROM tmp_batch_edit_t WHERE `主键` IN ('a','b') FOR UPDATE";
        $this->fake->setSelectResult($selectSql, [
            ['主键' => 'a', '字段1' => 'old1', '字段2' => 'old2'],
            ['主键' => 'b', '字段1' => 'oldb', '字段2' => 'old2b'],
        ]);

        $num = $this->service->batchUpdateRowsByModel(
            self::TABLE,
            '1',
            '主键',
            ['a', 'b', 'a'],
            ['字段1' => 'new1'],
            'u1',
            '3001'
        );

        $this->assertSame(1, $num);
        $this->assertCount(2, $this->fake->execSqlLog);

        // 置无效 UPDATE：时间戳与 INSERT 内同源
        $this->assertMatchesRegularExpression(
            '/^UPDATE tmp_batch_edit_t SET 操作记录="修改",操作来源="工作台",操作人员="u1",'
            . '操作时间="(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})",结束操作时间="\1",'
            . '删除标识="1",有效标识="0" WHERE `主键` IN \(\'a\',\'b\'\)$/',
            $this->fake->execSqlLog[0]
        );
        $now = $this->extractNow($this->fake->execSqlLog[0]);

        // 多值 INSERT：原行打底（跳过主键）+ formData 覆盖（array_key_exists 语义）+ 审计字段强制覆盖
        $this->assertSame(
            "INSERT INTO tmp_batch_edit_t (`字段1`, `字段2`, `操作记录`, `操作来源`, `操作人员`, `操作时间`, `结束操作时间`, `删除标识`, `有效标识`) VALUES "
            . "('new1', 'old2', '新增', '工作台', 'u1', '{$now}', '', '0', '1'), "
            . "('new1', 'old2b', '新增', '工作台', 'u1', '{$now}', '', '0', '1')",
            $this->fake->execSqlLog[1]
        );
    }

    public function testBatchUpdateMode1SkipsUnhitKeys(): void
    {
        // 预取只命中 zz，请求 a → hitKeyValues 空 → 0，不发任何 exec
        $selectSql = "SELECT * FROM tmp_batch_edit_t WHERE `主键` IN ('a') FOR UPDATE";
        $this->fake->setSelectResult($selectSql, [
            ['主键' => 'zz', '字段1' => 'old'],
        ]);

        $num = $this->service->batchUpdateRowsByModel(
            self::TABLE,
            '1',
            '主键',
            ['a'],
            ['字段1' => 'new'],
            'u1',
            '3001'
        );

        $this->assertSame(0, $num);
        $this->assertSame([], $this->fake->execSqlLog);
    }

    public function testBatchUpdateMode1SelectFailureThrows(): void
    {
        $this->fake->forceSelectReturn = false;

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('批量修改失败:预取原始记录失败(表=tmp_batch_edit_t)');

        $this->service->batchUpdateRowsByModel(self::TABLE, '1', '主键', ['a'], ['f' => 'v'], 'u1', '3001');
    }

    public function testBatchUpdateMode1NullOverrideWritesEmptyString(): void
    {
        // 流水模式合并语义：formData 键存在（array_key_exists）且值为 null → (string)null = ''
        $selectSql = "SELECT * FROM tmp_batch_edit_t WHERE `主键` IN ('a') FOR UPDATE";
        $this->fake->setSelectResult($selectSql, [
            ['主键' => 'a', '字段1' => 'old1'],
        ]);

        $this->service->batchUpdateRowsByModel(
            self::TABLE,
            '1',
            '主键',
            ['a'],
            ['字段1' => null],
            'u1',
            '3001'
        );

        $now = $this->extractNow($this->fake->execSqlLog[0]);
        $this->assertSame(
            "INSERT INTO tmp_batch_edit_t (`字段1`, `操作记录`, `操作来源`, `操作人员`, `操作时间`, `结束操作时间`, `删除标识`, `有效标识`) VALUES "
            . "('', '新增', '工作台', 'u1', '{$now}', '', '0', '1')",
            $this->fake->execSqlLog[1]
        );
    }

    // ---- tableEditByModel：入口校验 ----

    public function testTableEditEmptyRows(): void
    {
        $result = $this->service->tableEditByModel(self::TABLE, '0', '主键', [], 'u1', '3001');

        $this->assertSame(['success' => false, 'count' => 0, 'message' => '没有要提交的修改数据'], $result);
    }

    public function testTableEditMissingPrimaryKeyField(): void
    {
        $result = $this->service->tableEditByModel(
            self::TABLE,
            '0',
            '主键',
            [['字段1' => 'x']],
            'u1',
            '3001'
        );

        $this->assertFalse($result['success']);
        $this->assertSame(
            '表级修改失败:payload 中缺少主键字段 [主键],无法定位待修改记录',
            $result['message']
        );
    }

    public function testTableEditCompositePkPartialMissing(): void
    {
        // 复合主键只提供 pk1 → 缺 pk2
        $result = $this->service->tableEditByModel(
            self::TABLE,
            '0',
            'pk1;pk2',
            [['pk1' => 'a', '字段1' => 'x']],
            'u1',
            '3001'
        );

        $this->assertFalse($result['success']);
        $this->assertSame(
            '表级修改失败:payload 中缺少主键字段 [pk2],无法定位待修改记录',
            $result['message']
        );
    }

    public function testTableEditInvalidModel(): void
    {
        $result = $this->service->tableEditByModel(
            self::TABLE,
            '9',
            '主键',
            [['主键' => 'a', '字段1' => 'x']],
            'u1',
            '3001'
        );

        $this->assertSame(['success' => false, 'count' => 0, 'message' => '修改失败,数据模式[-9-]错误'], $result);
    }

    // ---- tableEditByModel：模式 0 单条 UPDATE ----

    public function testTableEditMode0SingleRowExactSql(): void
    {
        $result = $this->service->tableEditByModel(
            self::TABLE,
            '0',
            '主键',
            [['主键' => 'a', '字段1' => 'v1']],
            'u1',
            '3001'
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['count']);
        $this->assertSame(
            "UPDATE tmp_batch_edit_t SET `字段1` = 'v1' WHERE 主键='a'",
            $this->fake->execSqlLog[0]
        );
    }

    public function testTableEditMode0CompositePkWhere(): void
    {
        // WHERE 由 buildWhereFromPrimaryKey 构造：无反引号、= 无空格、and 连接
        // 既有行为：SET 排除键比较的是整串 'pk1;pk2'，复合主键的各字段本身仍进 SET（写回原值，无害）
        $result = $this->service->tableEditByModel(
            self::TABLE,
            '0',
            'pk1;pk2',
            [['pk1' => 'a', 'pk2' => 'b', '字段1' => 'v1']],
            'u1',
            '3001'
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            "UPDATE tmp_batch_edit_t SET `pk1` = 'a', `pk2` = 'b', `字段1` = 'v1' WHERE pk1='a' and pk2='b'",
            $this->fake->execSqlLog[0]
        );
    }

    public function testTableEditMode0OnlyAuditFieldsNoSql(): void
    {
        // 行内只有主键 + 控制列 → 无更新字段 → 不入组 → 0 条
        $result = $this->service->tableEditByModel(
            self::TABLE,
            '0',
            '主键',
            [['主键' => 'a', '操作记录' => 'x', '删除标识' => '0']],
            'u1',
            '3001'
        );

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['count']);
        $this->assertSame('表级修改提交成功,修改了 0 条记录', $result['message']);
        $this->assertSame([], $this->fake->execSqlLog);
    }

    // ---- tableEditByModel：模式 0 CASE WHEN 批量 ----

    public function testTableEditMode0CaseWhenExactSql(): void
    {
        $result = $this->service->tableEditByModel(
            self::TABLE,
            '0',
            '主键',
            [
                ['主键' => 'a', '字段1' => 'x1', '字段2' => 'y1'],
                ['主键' => 'b', '字段1' => 'x2', '字段2' => 'y2'],
            ],
            'u1',
            '3001'
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['count']);
        $this->assertSame(
            "UPDATE tmp_batch_edit_t SET "
            . "`字段1` = CASE WHEN `主键` = 'a' THEN 'x1' WHEN `主键` = 'b' THEN 'x2' ELSE `字段1` END, "
            . "`字段2` = CASE WHEN `主键` = 'a' THEN 'y1' WHEN `主键` = 'b' THEN 'y2' ELSE `字段2` END "
            . "WHERE `主键` IN ('a','b')",
            $this->fake->execSqlLog[0]
        );
    }

    public function testTableEditMode0DifferentFieldSetsSplitGroups(): void
    {
        // 两行字段集不同 → 两组，各走单条 UPDATE
        $result = $this->service->tableEditByModel(
            self::TABLE,
            '0',
            '主键',
            [
                ['主键' => 'a', '字段1' => 'x1'],
                ['主键' => 'b', '字段2' => 'y2'],
            ],
            'u1',
            '3001'
        );

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['count']);
        $this->assertCount(2, $this->fake->execSqlLog);
        $this->assertSame("UPDATE tmp_batch_edit_t SET `字段1` = 'x1' WHERE 主键='a'", $this->fake->execSqlLog[0]);
        $this->assertSame("UPDATE tmp_batch_edit_t SET `字段2` = 'y2' WHERE 主键='b'", $this->fake->execSqlLog[1]);
    }

    // ---- tableEditByModel：模式 1/2（表级流水）----

    public function testTableEditMode1ExactSqls(): void
    {
        $selectSql = "SELECT * FROM tmp_batch_edit_t WHERE `主键` IN ('a')";
        $this->fake->setSelectResult($selectSql, [
            ['主键' => 'a', '字段1' => 'old1', '字段2' => 'old2'],
        ]);

        $result = $this->service->tableEditByModel(
            self::TABLE,
            '1',
            '主键',
            [['主键' => 'a', '字段1' => 'new1']],
            'u1',
            '3001'
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['count']);
        $this->assertCount(2, $this->fake->execSqlLog);

        $this->assertMatchesRegularExpression(
            '/^UPDATE tmp_batch_edit_t SET 操作记录="修改",操作来源="工作台",操作人员="u1",'
            . '操作时间="(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})",结束操作时间="\1",'
            . '删除标识="1",有效标识="0" WHERE `主键` IN \(\'a\'\)$/',
            $this->fake->execSqlLog[0]
        );
        $now = $this->extractNow($this->fake->execSqlLog[1]);

        // 原行打底（跳过主键与控制列）+ 提交行覆盖（isset 语义）+ 审计字段追加
        $this->assertSame(
            "INSERT INTO tmp_batch_edit_t (`字段1`, `字段2`, `操作记录`, `操作来源`, `操作人员`, `操作时间`, `结束操作时间`, `删除标识`, `有效标识`) VALUES "
            . "('new1', 'old2', '新增', '工作台', 'u1', '{$now}', '', '0', '1')",
            $this->fake->execSqlLog[1]
        );
    }

    public function testTableEditMode1NullRowValueKeepsOriginal(): void
    {
        // 表级流水合并语义：提交行键值为 null（isset=false）→ 保留原值（与批量流水的 array_key_exists 语义相反）
        $selectSql = "SELECT * FROM tmp_batch_edit_t WHERE `主键` IN ('a')";
        $this->fake->setSelectResult($selectSql, [
            ['主键' => 'a', '字段1' => 'old1'],
        ]);

        $this->service->tableEditByModel(
            self::TABLE,
            '1',
            '主键',
            [['主键' => 'a', '字段1' => null]],
            'u1',
            '3001'
        );

        $now = $this->extractNow($this->fake->execSqlLog[1]);
        $this->assertStringContainsString(
            "(`字段1`, `操作记录`, `操作来源`, `操作人员`, `操作时间`, `结束操作时间`, `删除标识`, `有效标识`) VALUES "
            . "('old1', '新增', '工作台', 'u1', '{$now}', '', '0', '1')",
            $this->fake->execSqlLog[1]
        );
    }

    public function testTableEditMode1EmptyPkCaughtByUpfrontCheck(): void
    {
        // 主键值全空字符串 → 前置主键校验先拦截（"缺少有效的主键值"分支在其后，实际不可达）
        $result = $this->service->tableEditByModel(
            self::TABLE,
            '1',
            '主键',
            [['主键' => '', '字段1' => 'x']],
            'u1',
            '3001'
        );

        $this->assertFalse($result['success']);
        $this->assertSame(
            '表级修改失败:payload 中缺少主键字段 [主键],无法定位待修改记录',
            $result['message']
        );
        $this->assertSame([], $this->fake->execSqlLog);
    }

    public function testTableEditMode1UnhitRowNotReinserted(): void
    {
        // 提交行主键未命中预取 → 只置无效不重插；num=0 → success=false
        $selectSql = "SELECT * FROM tmp_batch_edit_t WHERE `主键` IN ('a')";
        $this->fake->setSelectResult($selectSql, [
            ['主键' => 'zz', '字段1' => 'old'],
        ]);

        $result = $this->service->tableEditByModel(
            self::TABLE,
            '1',
            '主键',
            [['主键' => 'a', '字段1' => 'new']],
            'u1',
            '3001'
        );

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['count']);
        // 仅置无效 UPDATE，无 INSERT
        $this->assertCount(1, $this->fake->execSqlLog);
        $this->assertStringStartsWith('UPDATE tmp_batch_edit_t SET 操作记录="修改"', $this->fake->execSqlLog[0]);
    }

    public function testTableEditMode1SelectFailure(): void
    {
        $this->fake->forceSelectReturn = false;

        $result = $this->service->tableEditByModel(
            self::TABLE,
            '1',
            '主键',
            [['主键' => 'a', '字段1' => 'x']],
            'u1',
            '3001'
        );

        $this->assertFalse($result['success']);
        $this->assertSame('批量查询原始记录失败', $result['message']);
    }
}
