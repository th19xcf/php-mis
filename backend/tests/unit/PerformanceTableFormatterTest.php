<?php

namespace Tests\Unit;

use App\Libraries\PerformanceTableFormatter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * PerformanceTableFormatter 直连测试
 *
 * Phase 1 抽取后直连新类静态方法（用例与断言与
 * BaseApiControllerPerformanceTest 特征测试同夹具），
 * 逐字锁定性能追踪表格日志的输出格式。
 */
class PerformanceTableFormatterTest extends CIUnitTestCase
{
    private function fmtRow(int|string $index, string $step, string $ts, string $dur, string $pct): string
    {
        return sprintf('%-8s | %-20s | %-10s | %-10s | %-6s', $index, $step, $ts, $dur, $pct);
    }

    public function testMultiStepOutputExactLines(): void
    {
        $t0 = 1_000_000_000;
        $steps = [
            'start'  => $t0,
            'query'  => $t0 + 2_000_000,   // +2ms
            'render' => $t0 + 5_000_000,   // +3ms（相对上一步）
        ];

        $out = PerformanceTableFormatter::format('[Test]', '成功', 'user=xx', $steps, $t0);
        $lines = explode("\n", $out);

        // 首行：tag info status 总耗时
        $this->assertSame('[Test] user=xx 成功 总耗时: 5.00ms', $lines[0]);
        // 表头
        $this->assertSame(
            sprintf('%-8s | %-20s | %-10s | %-10s | %-6s', '(索引)', 'step', 'timestamp', 'duration', 'pct'),
            $lines[1]
        );
        // 60 字符分隔行
        $this->assertSame(str_repeat('-', 60), $lines[2]);
        // 数据行：timestamp 相对 t0、duration 为链式差值、pct 占比
        $this->assertSame($this->fmtRow(0, 'start', '0.0', '0.0ms', '0.0%'), $lines[3]);
        $this->assertSame($this->fmtRow(1, 'query', '2.0', '2.0ms', '40.0%'), $lines[4]);
        $this->assertSame($this->fmtRow(2, 'render', '5.0', '3.0ms', '60.0%'), $lines[5]);
        // 空行 + 排行标题
        $this->assertSame('', $lines[6]);
        $this->assertSame('耗时排行（从慢到快）', $lines[7]);
        // 排行：降序，rank 从 1，条长按占比（render 满条 50、query 2/3*50=33）
        $this->assertSame(sprintf(' %d. %-20s %9.1fms %s', 1, 'render', 3.0, str_repeat('█', 50)), $lines[8]);
        $this->assertSame(sprintf(' %d. %-20s %9.1fms %s', 2, 'query', 2.0, str_repeat('█', 33)), $lines[9]);
        $this->assertCount(10, $lines);
    }

    public function testEmptyStepsClampsNegativeTotalToMinimum(): void
    {
        // end([]) = false，false - t0 数值化为负 → total 钳到 0.001 → 显示 0.00ms
        $out = PerformanceTableFormatter::format('[T]', '成功', 'info', [], 1_000_000_000);
        $lines = explode("\n", $out);

        $this->assertSame('[T] info 成功 总耗时: 0.00ms', $lines[0]);
        $this->assertSame('', $lines[3]);
        $this->assertSame('耗时排行（从慢到快）', $lines[4]);
        $this->assertCount(5, $lines);
    }

    public function testTotalBelowOneMicrosecondClamped(): void
    {
        $t0 = 1_000_000_000;
        $steps = ['only' => $t0 + 500]; // 0.0005ms

        $out = PerformanceTableFormatter::format('[T]', '成功', 'i', $steps, $t0);

        // total 钳到 0.001ms → %.2f 显示 0.00ms；单步 duration 0.0005 < 0.001 不入排行
        $this->assertStringContainsString('总耗时: 0.00ms', $out);
        $this->assertStringContainsString('耗时排行（从慢到快）', $out);
        // 排行区无条目（唯一步骤低于阈值）：3 头 + 1 数据 + 空行 + 标题 = 6 行
        $this->assertCount(6, explode("\n", $out));
    }

    public function testStepsBelowThresholdExcludedFromRanking(): void
    {
        $t0 = 1_000_000_000;
        $steps = [
            'zero' => $t0,                        // duration 0 → 不入排行
            'main' => $t0 + 5_000_000,            // 5ms
        ];

        $out = PerformanceTableFormatter::format('[T]', '成功', 'i', $steps, $t0);
        $lines = explode("\n", $out);

        // 行结构：0首行/1表头/2分隔/3zero/4main/5空行/6标题/7排行条目
        $ranking = array_slice($lines, 7);
        $this->assertSame(sprintf(' %d. %-20s %9.1fms %s', 1, 'main', 5.0, str_repeat('█', 50)), $ranking[0]);
        $this->assertCount(1, $ranking);
    }

    public function testBarLengthMinimumOneForTinySteps(): void
    {
        $t0 = 0;
        $steps = [
            'tiny' => 1_000,               // 0.001ms（恰好达到阈值，入排行）
            'huge' => 1_000_000_000,       // ~1000ms
        ];

        $out = PerformanceTableFormatter::format('[T]', '成功', 'i', $steps, $t0);
        $lines = explode("\n", $out);

        // 行结构：0首行/1表头/2分隔/3tiny/4huge/5空行/6标题/7huge(rank1)/8tiny(rank2)
        // huge rank1 满条；tiny rank2 条长 (int)(0.001/999.999*50)=0 → max(0,1)=1
        $this->assertSame(sprintf(' %d. %-20s %9.1fms %s', 2, 'tiny', 0.0, str_repeat('█', 1)), $lines[8]);
    }

    public function testFullWidthBlockCharacterUsedForBars(): void
    {
        $t0 = 0;
        $steps = ['only' => $t0 + 1_000_000];

        $out = PerformanceTableFormatter::format('[T]', '成功', 'i', $steps, $t0);

        $this->assertStringContainsString('█', $out);
    }

    public function testSingleStepRowAndRanking(): void
    {
        $t0 = 5_000_000_000;
        $steps = ['solo' => $t0 + 1_000_000]; // 1ms

        $out = PerformanceTableFormatter::format('[Tag]', '失败', 'fc=2025', $steps, $t0);
        $lines = explode("\n", $out);

        $this->assertSame('[Tag] fc=2025 失败 总耗时: 1.00ms', $lines[0]);
        $this->assertSame($this->fmtRow(0, 'solo', '1.0', '1.0ms', '100.0%'), $lines[3]);
        $this->assertSame(sprintf(' %d. %-20s %9.1fms %s', 1, 'solo', 1.0, str_repeat('█', 50)), $lines[6]);
    }
}
