<?php

namespace App\Commands;

use App\Services\Person\PersonService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * 验证主档维护功能：tree/detail/update/merge（服务层直测）
 *
 * 用法：php spark person:archive-test
 */
class PersonArchiveTest extends BaseCommand
{
    protected $group       = 'Person';
    protected $name        = 'person:archive-test';
    protected $description = '验证主档维护功能（tree查询/update/merge合并，测试后自动恢复）';
    protected $usage       = 'php spark person:archive-test';

    public function run(array $params)
    {
        $model = new \App\Models\Mcommon();
        $service = new PersonService();
        $db = $model->getDb();
        $operator = 'archtest'; // ≤10 字符（hr_audit_log.操作人员 列宽）

        // 清理上次运行遗留（archive-test 被截断为 archive-tes 的残留行）
        $model->exec('DELETE FROM hr_audit_log WHERE 操作人员 IN ("archive-tes", "archtest")');
        $model->exec('DELETE FROM ee_store WHERE 候选人编码="TESTMERGE001"');
        $model->exec('DELETE FROM hr_person WHERE 身份证号 IN ("TEST000000000001", "TEST000000000002")');

        // ===== 1. tree 查询（模拟 PersonApi::tree 的 SQL）=====
        CLI::write('===== 1. 主档列表查询 =====', 'yellow');
        $sql = 'SELECT 人员编码, 姓名, 手机号码, 属地 FROM hr_person
                WHERE 有效标识="1" AND 删除标识="0" AND (合并至 IS NULL OR 合并至="")
                ORDER BY 属地, convert(姓名 using gbk) LIMIT 5';
        $rows = $model->select($sql)->getResultArray();
        CLI::write('  前 5 条主档:', 'light_green');
        foreach ($rows as $r) {
            CLI::write("    {$r['人员编码']} | {$r['姓名']} | {$r['手机号码']} | {$r['属地']}");
        }

        if (empty($rows)) {
            CLI::write('  (无数据)', 'light_red');
            return EXIT_ERROR;
        }

        $testPerson = $rows[0];
        $code = $testPerson['人员编码'];

        // ===== 2. update 测试 =====
        CLI::newLine();
        CLI::write("===== 2. update 测试（{$code} 属地 → 测试值）=====", 'yellow');
        $oldRow = $db->query(
            'SELECT 属地, 工作履历 FROM hr_person WHERE 人员编码=? LIMIT 1',
            [$code]
        )->getRowArray();
        $oldRegion = $oldRow['属地'] ?? '';

        $service->updatePersonFields($code, ['属地' => '测试属地'], $operator);
        $newRow = $db->query(
            'SELECT 属地 FROM hr_person WHERE 人员编码=? LIMIT 1',
            [$code]
        )->getRowArray();
        $updateOk = ($newRow['属地'] === '测试属地');
        CLI::write(
            $updateOk ? "  PASS: 属地已更新为 {$newRow['属地']}" : "  FAIL: 属地 = {$newRow['属地']}",
            $updateOk ? 'light_green' : 'light_red'
        );

        // 恢复
        $service->updatePersonFields($code, ['属地' => $oldRegion], $operator);
        $restored = $db->query('SELECT 属地 FROM hr_person WHERE 人员编码=? LIMIT 1', [$code])->getRowArray();
        CLI::write("  已恢复属地: {$restored['属地']}", 'dark_gray');

        // ===== 3. merge 测试（临时建档两条 → 合并 → 清理）=====
        CLI::newLine();
        CLI::write('===== 3. merge 测试（临时主档 A 合并到 B）=====', 'yellow');

        // 3.1 建临时主档 A（有身份证号）和 B
        $codeA = $service->createPerson(
            ['姓名' => '测试甲', '手机号码' => '13900000001', '身份证号' => 'TEST000000000001', '属地' => '测试属地'],
            $operator
        );
        $codeB = $service->createPerson(
            ['姓名' => '测试乙', '手机号码' => '13900000002', '身份证号' => 'TEST000000000002', '工作履历' => '测试履历B'],
            $operator
        );
        CLI::write("  建档 A={$codeA} B={$codeB}", 'light_green');

        // 3.2 在 ee_store 插一条引用 A 的临时行
        $model->exec(sprintf(
            'INSERT INTO ee_store (候选人编码, 人员编码, 姓名, 手机号码, 有效标识, 删除标识)
             VALUES ("TESTMERGE001", %s, "测试甲", "13900000001", "1", "0")',
            $model->quote($codeA)
        ));
        CLI::write('  已插入 ee_store 临时行引用 A', 'light_green');

        // 3.3 执行合并 A → B
        $affected = $service->mergePerson($codeA, $codeB, $operator);
        CLI::write("  mergePerson 返回: 下游 {$affected} 条联动", 'light_green');

        // 3.4 验证
        $aRow = $db->query('SELECT 有效标识, 合并至 FROM hr_person WHERE 人员编码=?', [$codeA])->getRowArray();
        $mergeOk1 = ($aRow['有效标识'] === '0' && $aRow['合并至'] === $codeB);
        CLI::write(
            $mergeOk1 ? "  PASS: A 已置无效, 合并至={$aRow['合并至']}" : "  FAIL: A 有效标识={$aRow['有效标识']} 合并至={$aRow['合并至']}",
            $mergeOk1 ? 'light_green' : 'light_red'
        );

        $storeRow = $db->query(
            'SELECT 人员编码 FROM ee_store WHERE 候选人编码="TESTMERGE001" LIMIT 1'
        )->getRowArray();
        $mergeOk2 = ($storeRow['人员编码'] === $codeB);
        CLI::write(
            $mergeOk2 ? "  PASS: ee_store 人员编码已联动为 {$storeRow['人员编码']}" : "  FAIL: ee_store 人员编码 = {$storeRow['人员编码']}",
            $mergeOk2 ? 'light_green' : 'light_red'
        );

        // 3.5 目标补齐验证：B 的工作履历应被 A 的空值跳过（B 有值）
        $bRow = $db->query('SELECT 工作履历 FROM hr_person WHERE 人员编码=?', [$codeB])->getRowArray();
        $mergeOk3 = ($bRow['工作履历'] === '测试履历B');
        CLI::write(
            $mergeOk3 ? "  PASS: B 工作履历保持原值（源非空但目标已有值不覆盖）" : "  FAIL: B 工作履历 = {$bRow['工作履历']}",
            $mergeOk3 ? 'light_green' : 'light_red'
        );

        // 3.6 审计日志验证
        $logCnt = (int) $db->query(
            'SELECT COUNT(*) AS cnt FROM hr_audit_log WHERE 操作人员="archtest" AND 操作来源="主档合并"'
        )->getRowArray()['cnt'];
        $logOk = ($logCnt >= 2); // 至少：源合并事件 + ee_store 联动事件
        CLI::write(
            $logOk ? "  PASS: hr_audit_log 合并事件 {$logCnt} 条" : "  FAIL: hr_audit_log 仅 {$logCnt} 条",
            $logOk ? 'light_green' : 'light_red'
        );

        // 3.7 清理测试数据
        $model->exec('DELETE FROM ee_store WHERE 候选人编码="TESTMERGE001"');
        $model->exec(sprintf('DELETE FROM hr_person WHERE 人员编码 IN (%s, %s)',
            $model->quote($codeA), $model->quote($codeB)));
        $model->exec('DELETE FROM hr_audit_log WHERE 操作人员="archtest"');
        CLI::write('  已清理测试数据（ee_store/hr_person/hr_audit_log）', 'dark_gray');

        // ===== 4. dedup 测试 =====
        CLI::newLine();
        CLI::write('===== 4. dedup 测试 =====', 'yellow');
        $dedup = $service->dedup($testPerson['姓名'], $testPerson['手机号码']);
        $dedupOk = in_array($dedup['level'], ['hard', 'soft'], true);
        CLI::write(
            $dedupOk ? "  PASS: level={$dedup['level']}, matches=" . count($dedup['matches']) : "  (level={$dedup['level']}，跳过)",
            $dedupOk ? 'light_green' : 'dark_gray'
        );

        CLI::newLine();
        $allPass = $updateOk && $mergeOk1 && $mergeOk2 && $mergeOk3 && $logOk;
        CLI::write($allPass ? 'ALL PASS' : 'VALIDATION FAILED',
            $allPass ? 'light_green' : 'light_red');

        return $allPass ? EXIT_SUCCESS : EXIT_ERROR;
    }
}

