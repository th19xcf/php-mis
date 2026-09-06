<?php

namespace Tests\Unit;

use App\Services\Workbench\ImportService;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\FakeMcommon;

/**
 * ImportService SQL 构造路径特征测试（FakeMcommon，不触真库）
 *
 * 锁定现状行为：createTempTable / insertToTempTable / validateImportDataByTable /
 * checkDuplicateFields 生成的 SQL 逐字锁定，作为后续抽取 ImportSqlBuilder 的安全网。
 */
class ImportServiceSqlTest extends CIUnitTestCase
{
    private ImportService $service;
    private FakeMcommon $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ImportService();
        $this->fake = new FakeMcommon();

        // Reflection 注入 FakeMcommon（ImportService::$model 为 private）
        $prop = new \ReflectionProperty(ImportService::class, 'model');
        $prop->setAccessible(true);
        $prop->setValue($this->service, $this->fake);
    }

    // ---- createTempTable ----

    public function testCreateTempTableDropsFirst(): void
    {
        $this->service->createTempTable('tmp_t', []);

        $this->assertSame('DROP TABLE IF EXISTS `tmp_t`', $this->fake->execSqlLog[0]);
    }

    public function testCreateTempTableEmptyColumnsSimpleTable(): void
    {
        $result = $this->service->createTempTable('tmp_t', []);

        $this->assertTrue($result);
        $this->assertSame(
            'CREATE TABLE `tmp_t` (id int auto_increment primary key, data varchar(255))',
            $this->fake->execSqlLog[1]
        );
    }

    public function testCreateTempTableSqlExact(): void
    {
        $columns = [
            ['字段名' => 'f1', '字段长度' => 10, '缺省值' => ''],
            ['字段名' => 'f2', '字段长度' => 20, '缺省值' => 'DFT'],
            // 字段名空 → fallback 列名
            ['字段名' => '', '列名' => 'c3', '字段长度' => 5, '缺省值' => ''],
            // 字段名与列名均空 → 跳过
            ['字段名' => '', '列名' => '', '字段长度' => 5, '缺省值' => ''],
            // 缺省值需 quote
            ['字段名' => 'f4', '字段长度' => 8, '缺省值' => 'a b'],
        ];

        $result = $this->service->createTempTable('tmp_t', $columns);

        $this->assertTrue($result);
        $this->assertSame(
            'CREATE TABLE `tmp_t` (`f1` varchar(10) not null default "",'
            . '`f2` varchar(20) not null default \'DFT\','
            . '`c3` varchar(5) not null default "",'
            . '`f4` varchar(8) not null default \'a b\')',
            $this->fake->execSqlLog[1]
        );
    }

    public function testCreateTempTableDefaultLengthIs255(): void
    {
        $columns = [
            ['字段名' => 'f1'], // 无长度配置 → 255
        ];
        $this->service->createTempTable('tmp_t', $columns);

        $this->assertStringContainsString('`f1` varchar(255) not null default ""', $this->fake->execSqlLog[1]);
    }

    // ---- insertToTempTable ----

    public function testInsertToTempTableEmptyDataNoop(): void
    {
        $this->assertTrue($this->service->insertToTempTable('tmp_t', [], []));
        $this->assertSame([], $this->fake->execSqlLog);
    }

    public function testInsertToTempTableSqlExact(): void
    {
        $columns = [
            ['字段名' => 'f1', '缺省值' => ''],
            ['字段名' => 'f2', '缺省值' => 'DFT'],
            ['字段名' => 'f3', '缺省值' => ''], // 数据中无该字段 → 空值落 DFT?（不在 fields 中则不出现在 SQL）
        ];
        $data = [
            ['f1' => 'a', 'f2' => 'x'],
            ['f1' => 'b', 'f2' => ''],  // f2 空 → 缺省值 DFT
        ];

        $result = $this->service->insertToTempTable('tmp_t', $data, $columns);

        $this->assertTrue($result);
        // fields 取自数据首行 key（f1,f2）；执行版分隔符：fields ', '、rowValues ','、rows ', '
        $this->assertSame(
            'INSERT INTO `tmp_t` (`f1`, `f2`) VALUES (\'a\',\'x\'), (\'b\',\'DFT\')',
            $this->fake->execSqlLog[0]
        );
    }

    // ---- validateImportDataByTable ----

    public function testValidateByTableFixedValueViolation(): void
    {
        $columns = [
            ['列名' => '列一', '字段名' => 'f1', '校验类型' => '固定值', '校验信息' => '', '对象' => 'OBJ'],
        ];
        $sql = sprintf('
                    select
                        t1.字段名 as 字段名,
                        t1.字段值 as 字段值,
                        ifnull(t2.对象值,"") as 对象值
                    from
                    (
                        select "%s" as 字段名, `%s` as 字段值
                        from `%s`
                        group by 字段值
                    ) as t1
                    left join
                    (
                        select 对象名称,对象值
                        from def_object
                        where 对象名称="%s"
                            and (属地="" or locate(属地,"%s"))
                    ) as t2 on t1.字段值=t2.对象值
                    where t2.对象值 is null and t1.字段值 != ""
                ', 'f1', 'f1', 'tmp_t', 'OBJ', 'BJ');
        $this->fake->setSelectResult($sql, [['字段名' => 'f1', '字段值' => 'bad', '对象值' => '']]);

        $result = $this->service->validateImportDataByTable('tmp_t', $columns, 'BJ');

        $this->assertTrue($result['hasError']);
        $this->assertSame('导入失败,列"列一"有不符合固定值的记录 {"bad"}', $result['message']);
    }

    public function testValidateByTableFixedValuePass(): void
    {
        $columns = [
            ['列名' => '列一', '字段名' => 'f1', '校验类型' => '固定值', '校验信息' => '', '对象' => 'OBJ'],
        ];
        $sql = sprintf('
                    select
                        t1.字段名 as 字段名,
                        t1.字段值 as 字段值,
                        ifnull(t2.对象值,"") as 对象值
                    from
                    (
                        select "%s" as 字段名, `%s` as 字段值
                        from `%s`
                        group by 字段值
                    ) as t1
                    left join
                    (
                        select 对象名称,对象值
                        from def_object
                        where 对象名称="%s"
                            and (属地="" or locate(属地,"%s"))
                    ) as t2 on t1.字段值=t2.对象值
                    where t2.对象值 is null and t1.字段值 != ""
                ', 'f1', 'f1', 'tmp_t', 'OBJ', 'BJ');
        $this->fake->setSelectResult($sql, []);

        $result = $this->service->validateImportDataByTable('tmp_t', $columns, 'BJ');

        $this->assertFalse($result['hasError']);
        $this->assertSame('校验通过', $result['message']);
    }

    public function testValidateByTableConditionViolation(): void
    {
        $columns = [
            ['列名' => '列二', '字段名' => 'f2', '校验类型' => '条件', '校验信息' => '`f2` = "bad"', '对象' => ''],
        ];
        $sql = 'select "列二" as 字段名, `f2` as 字段值 from `tmp_t` where `f2` = "bad"';
        $this->fake->setSelectResult($sql, [['字段名' => '列二', '字段值' => 'bad']]);

        $result = $this->service->validateImportDataByTable('tmp_t', $columns, '');

        $this->assertTrue($result['hasError']);
        $this->assertSame('导入失败,列"列二"有不符合条件的记录 {"bad"}', $result['message']);
    }

    public function testValidateByTableDateInvalidFormat(): void
    {
        $columns = [
            ['列名' => '列三', '字段名' => 'f3', '校验类型' => '日期', '校验信息' => '', '对象' => ''],
        ];
        $sql = 'select "列三" as 字段名, `f3` as 字段值 from `tmp_t`';
        $this->fake->setSelectResult($sql, [['字段名' => '列三', '字段值' => '2023/01/02']]);

        $result = $this->service->validateImportDataByTable('tmp_t', $columns, '');

        $this->assertTrue($result['hasError']);
        $this->assertSame(
            '导入失败,列"列三"有不符合的记录{"2023/01/02"},必须为YYYY-mm-dd (如2023-01-02) 格式',
            $result['message']
        );
    }

    public function testValidateByTableDateImpossibleDate(): void
    {
        $columns = [
            ['列名' => '列三', '字段名' => 'f3', '校验类型' => '日期', '校验信息' => '', '对象' => ''],
        ];
        $sql = 'select "列三" as 字段名, `f3` as 字段值 from `tmp_t`';
        $this->fake->setSelectResult($sql, [['字段名' => '列三', '字段值' => '2023-02-30']]);

        $result = $this->service->validateImportDataByTable('tmp_t', $columns, '');

        $this->assertTrue($result['hasError']);
    }

    public function testValidateByTableDatePassAndEmptySkipped(): void
    {
        $columns = [
            ['列名' => '列三', '字段名' => 'f3', '校验类型' => '日期', '校验信息' => '', '对象' => ''],
        ];
        $sql = 'select "列三" as 字段名, `f3` as 字段值 from `tmp_t`';
        $this->fake->setSelectResult($sql, [
            ['字段名' => '列三', '字段值' => ''],
            ['字段名' => '列三', '字段值' => '2023-01-02'],
        ]);

        $result = $this->service->validateImportDataByTable('tmp_t', $columns, '');

        $this->assertFalse($result['hasError']);
    }

    public function testValidateByTableSkipsColumnsWithoutCheckTypeOrField(): void
    {
        $columns = [
            ['列名' => '列一', '字段名' => 'f1', '校验类型' => '', '校验信息' => '', '对象' => ''],
            ['列名' => '列二', '字段名' => '', '校验类型' => '固定值', '校验信息' => '', '对象' => 'OBJ'],
        ];
        $result = $this->service->validateImportDataByTable('tmp_t', $columns, '');

        $this->assertFalse($result['hasError']);
        $this->assertSame([], $this->fake->selectSqlLog);
    }

    // ---- checkDuplicateFields ----

    public function testCheckDuplicateNoConfigPass(): void
    {
        $sql = 'select 滤重字段 from def_import_config where 导入模块=\'MOD\'';
        $this->fake->setSelectResult($sql, [['滤重字段' => '']]);

        $result = $this->service->checkDuplicateFields('MOD', 'data_t', 'tmp_t');

        $this->assertFalse($result['hasError']);
        $this->assertCount(1, $this->fake->selectSqlLog);
    }

    public function testCheckDuplicateViolationMessageAndSql(): void
    {
        // 配置读取
        $cfgSql = 'select 滤重字段 from def_import_config where 导入模块=\'MOD\'';
        $this->fake->setSelectResult($cfgSql, [['滤重字段' => '身份证号`,`姓名']]);

        // 临时表读取（字段以 "`,`" 分隔解析为两字段）
        $tmpSql = 'select `身份证号`, `姓名` from `tmp_t`';
        $this->fake->setSelectResult($tmpSql, [
            ['身份证号' => 'id1', '姓名' => 'n1'],
            ['身份证号' => 'id2', '姓名' => 'n2'],
        ]);

        // 主表 tuple IN 查询（select 列为配置原值；tuple 为 ('a','b')，整组再包一层括号）
        $dupSql = 'select `身份证号`,`姓名` from `data_t` where (`身份证号`, `姓名`) in ((\'id1\',\'n1\'),(\'id2\',\'n2\'))';
        $this->fake->setSelectResult($dupSql, [
            ['身份证号' => 'id1', '姓名' => 'n1'],
        ]);

        $result = $this->service->checkDuplicateFields('MOD', 'data_t', 'tmp_t');

        $this->assertTrue($result['hasError']);
        $this->assertSame(
            '导入失败,滤重列"身份证号`,`姓名"有重复记录 {"id1^n1"}',
            $result['message']
        );
        // SQL 发出顺序：配置 → 临时表 → 主表
        $this->assertSame($cfgSql, $this->fake->selectSqlLog[0]);
        $this->assertSame($tmpSql, $this->fake->selectSqlLog[1]);
        $this->assertSame($dupSql, $this->fake->selectSqlLog[2]);
    }

    public function testCheckDuplicateNullFieldRowExcludedFromTuples(): void
    {
        $cfgSql = 'select 滤重字段 from def_import_config where 导入模块=\'MOD\'';
        $this->fake->setSelectResult($cfgSql, [['滤重字段' => '身份证号`,`姓名']]);
        $this->fake->setSelectResult(
            'select `身份证号`, `姓名` from `tmp_t`',
            [
                ['身份证号' => 'id1', '姓名' => 'n1'],
                ['身份证号' => null, '姓名' => 'n2'],  // NULL → 不参与匹配
            ]
        );
        $dupSql = 'select `身份证号`,`姓名` from `data_t` where (`身份证号`, `姓名`) in ((\'id1\',\'n1\'))';
        $this->fake->setSelectResult($dupSql, []);

        $result = $this->service->checkDuplicateFields('MOD', 'data_t', 'tmp_t');

        $this->assertFalse($result['hasError']);
        $this->assertSame($dupSql, $this->fake->selectSqlLog[2]);
    }

    public function testCheckDuplicateAllNullNoMainQuery(): void
    {
        $cfgSql = 'select 滤重字段 from def_import_config where 导入模块=\'MOD\'';
        $this->fake->setSelectResult($cfgSql, [['滤重字段' => '身份证号`,`姓名']]);
        $this->fake->setSelectResult('select `身份证号`, `姓名` from `tmp_t`', [
            ['身份证号' => null, '姓名' => null],
        ]);

        $result = $this->service->checkDuplicateFields('MOD', 'data_t', 'tmp_t');

        $this->assertFalse($result['hasError']);
        // 全 NULL → tuples 空 → 不发主表查询
        $this->assertCount(2, $this->fake->selectSqlLog);
    }

    public function testCheckDuplicateSwallowsException(): void
    {
        $cfgSql = 'select 滤重字段 from def_import_config where 导入模块=\'MOD\'';
        $this->fake->setSelectError($cfgSql, new \RuntimeException('db down'));

        $result = $this->service->checkDuplicateFields('MOD', 'data_t', 'tmp_t');

        $this->assertFalse($result['hasError']);
    }

    // ---- dropTempTable ----

    public function testDropTempTableSql(): void
    {
        $this->service->dropTempTable('tmp_x');
        $this->assertSame('DROP TABLE IF EXISTS `tmp_x`', $this->fake->execSqlLog[0]);
    }
}
