<?php

namespace Tests\Unit;

use App\Services\Workbench\ImportService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ImportService 纯函数特征测试（不触 DB）
 *
 * 锁定现状行为：validateImportData / validateFieldLength / buildImportFailure
 * 的返回结构、错误文案、边界语义，作为后续机械抽取的安全网。
 */
class ImportServiceValidationTest extends CIUnitTestCase
{
    private ImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ImportService();
    }

    // ---- buildImportFailure ----

    public function testBuildImportFailureShape(): void
    {
        $result = $this->service->buildImportFailure([['a' => 1], ['a' => 2]], 'boom');

        $this->assertSame([
            'success'      => false,
            'message'      => 'boom',
            'total'        => 2,
            'successCount' => 0,
            'errorCount'   => 2,
            'errors'       => [['error' => 'boom']],
        ], $result);
    }

    // ---- validateImportData ----

    public function testValidateMissingRequiredColumn(): void
    {
        $result = $this->service->validateImportData(
            [['colA' => 'x']],
            ['colA' => ['field' => 'f1', 'required' => false, 'systemVar' => '']],
            ['colB'],
            []
        );

        $this->assertTrue($result['hasError']);
        $this->assertSame('导入失败,缺少必须的字段"colB"', $result['message']);
        $this->assertSame([['error' => '缺少必须的字段: colB']], $result['errors']);
    }

    public function testValidateRequiredEmptyValueReportsRow(): void
    {
        $fieldMap = [
            'colA' => ['field' => 'f1', 'required' => true, 'systemVar' => ''],
            'colB' => ['field' => 'f2', 'required' => false, 'systemVar' => ''],
        ];
        $result = $this->service->validateImportData(
            [['colA' => 'ok', 'colB' => 'x'], ['colA' => '', 'colB' => 'y']],
            $fieldMap,
            [],
            []
        );

        $this->assertTrue($result['hasError']);
        $this->assertSame('验证失败，共 1 行数据有误', $result['message']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame(2, $result['errors'][0]['row']);
        $this->assertSame(['字段 "colA" 不能为空'], $result['errors'][0]['errors']);
        $this->assertSame(['colA' => '', 'colB' => 'y'], $result['errors'][0]['data']);
    }

    public function testValidateSystemVarFillsEmptyValue(): void
    {
        $fieldMap = [
            'colA' => ['field' => 'f1', 'required' => false, 'systemVar' => 'SV1'],
            'colB' => ['field' => 'f2', 'required' => true, 'systemVar' => 'SV_NONE'],
        ];
        $result = $this->service->validateImportData(
            [['colA' => '', 'colB' => 'keep']],
            $fieldMap,
            [],
            ['SV1' => 'sys-a']
        );

        // systemVar 命中 → 空值被系统变量替换；SV_NONE 未配置 → 保持空值（colB required 由原值满足）
        $this->assertFalse($result['hasError']);
        $this->assertSame([['f1' => 'sys-a', 'f2' => 'keep']], $result['validData']);
    }

    public function testValidateSystemVarNotAppliedToNonEmptyValue(): void
    {
        $fieldMap = [
            'colA' => ['field' => 'f1', 'required' => false, 'systemVar' => 'SV1'],
        ];
        $result = $this->service->validateImportData(
            [['colA' => 'raw']],
            $fieldMap,
            [],
            ['SV1' => 'sys-a']
        );

        $this->assertFalse($result['hasError']);
        $this->assertSame([['f1' => 'raw']], $result['validData']);
    }

    public function testValidateMapsColumnNameToFieldName(): void
    {
        $fieldMap = [
            '列一' => ['field' => '字段一', 'required' => false, 'systemVar' => ''],
            '列二' => ['field' => '字段二', 'required' => false, 'systemVar' => ''],
        ];
        $result = $this->service->validateImportData(
            [['列一' => 'a', '列二' => 'b']],
            $fieldMap,
            [],
            []
        );

        $this->assertFalse($result['hasError']);
        $this->assertSame([['字段一' => 'a', '字段二' => 'b']], $result['validData']);
    }

    public function testValidateMissingRowKeyTreatedAsEmpty(): void
    {
        $fieldMap = [
            'colA' => ['field' => 'f1', 'required' => false, 'systemVar' => ''],
        ];
        $result = $this->service->validateImportData(
            [['other' => 'x']],
            $fieldMap,
            [],
            []
        );

        $this->assertFalse($result['hasError']);
        $this->assertSame([['f1' => '']], $result['validData']);
    }

    // ---- validateFieldLength ----

    public function testValidateFieldLengthPass(): void
    {
        $columns = [
            ['字段名' => 'f1', '字段长度' => 5, '列名' => '列一'],
        ];
        $result = $this->service->validateFieldLength([['f1' => 'abc']], $columns);

        $this->assertFalse($result['hasError']);
        $this->assertSame('', $result['message']);
        $this->assertSame([], $result['errors']);
    }

    public function testValidateFieldLengthOverlongReportsRowDetail(): void
    {
        $columns = [
            ['字段名' => 'f1', '字段长度' => 3, '列名' => '列一'],
        ];
        $result = $this->service->validateFieldLength([['f1' => 'abcd']], $columns);

        $this->assertTrue($result['hasError']);
        $this->assertSame('字段长度校验失败，共 1 处超长', $result['message']);
        $err = $result['errors'][0];
        $this->assertSame(1, $err['row']);
        $this->assertSame('f1', $err['field']);
        $this->assertSame('列一', $err['column']);
        $this->assertSame(3, $err['length']);
        $this->assertSame(4, $err['actual']);
        $this->assertSame('abcd', $err['value']);
        $this->assertSame('第 1 行 列一 字段超长：4 字符 > 3 字符（值：abcd）', $err['error']);
    }

    public function testValidateFieldLengthCountsCharsNotBytes(): void
    {
        // utf8mb4 varchar 语义：一个汉字算 1 字符
        $columns = [
            ['字段名' => 'f1', '字段长度' => 2, '列名' => '列一'],
        ];
        $result = $this->service->validateFieldLength([['f1' => '姓名字']], $columns);

        $this->assertTrue($result['hasError']);
        $this->assertSame(3, $result['errors'][0]['actual']);
    }

    public function testValidateFieldLengthDefaultsTo255(): void
    {
        $columns = [
            ['字段名' => 'f1', '列名' => '列一'],                      // 未配置 → 255
            ['字段名' => 'f2', '字段长度' => 0, '列名' => '列二'],      // <=0 → 255
            ['字段名' => 'f3', '字段长度' => -1, '列名' => '列三'],     // <=0 → 255
        ];
        $value = str_repeat('a', 254);
        $result = $this->service->validateFieldLength(
            [['f1' => $value, 'f2' => $value, 'f3' => $value]],
            $columns
        );

        $this->assertFalse($result['hasError']);
    }

    public function testValidateFieldLengthSkipsFieldsNotInRow(): void
    {
        $columns = [
            ['字段名' => 'f1', '字段长度' => 2, '列名' => '列一'],
        ];
        $result = $this->service->validateFieldLength([['other' => 'xxxxx']], $columns);

        $this->assertFalse($result['hasError']);
    }

    public function testValidateFieldLengthEmptyInputsNoError(): void
    {
        $this->assertFalse($this->service->validateFieldLength([], [['字段名' => 'f1']])['hasError']);
        $this->assertFalse($this->service->validateFieldLength([['f1' => 'x']], [])['hasError']);
    }

    public function testValidateFieldLengthValuePreviewTruncatedTo30(): void
    {
        $columns = [
            ['字段名' => 'f1', '字段长度' => 3, '列名' => '列一'],
        ];
        $long = str_repeat('x', 50);
        $result = $this->service->validateFieldLength([['f1' => $long]], $columns);

        $this->assertSame(str_repeat('x', 30), $result['errors'][0]['value']);
    }
}
