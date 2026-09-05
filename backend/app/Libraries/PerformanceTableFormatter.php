<?php

namespace App\Libraries;

/**
 * 性能追踪表格日志格式化器
 *
 * 从 BaseApiController::buildPerformanceTable 机械抽取（逻辑零变更），
 * 纯字符串转换，无 DB / 无请求态依赖。
 */
class PerformanceTableFormatter
{
    /**
     * 构建性能追踪表格日志
     *
     * @param string $tag 标签（如 [Login]、[QueryPaged]）
     * @param string $status 状态（成功/失败）
     * @param string $info 附加信息（user=xxx functionCode=xxx）
     * @param array $steps 步骤数组：['步骤名' => 时间戳(hrtime(true)或microtime(true))]
     * @param float|int $t0 起始时间戳
     */
    public static function format(string $tag, string $status, string $info, array $steps, float|int $t0): string
    {
        $total = (end($steps) - $t0) / 1e6;
        if ($total < 0.001) $total = 0.001;

        $rows = [];
        $prevTime = $t0;
        $index = 0;

        foreach ($steps as $stepName => $currTime) {
            $duration = ($currTime - $prevTime) / 1e6;
            $timestamp = sprintf('%.1f', ($currTime - $t0) / 1e6);
            $pct = $total > 0 ? ($duration / $total) * 100 : 0;

            $rows[] = [
                'index' => $index,
                'step' => $stepName,
                'timestamp' => $timestamp,
                'duration' => sprintf('%.1fms', $duration),
                'pct' => sprintf('%.1f%%', $pct),
                'raw_duration' => $duration
            ];
            $prevTime = $currTime;
            $index++;
        }

        $logLines = [];
        $logLines[] = sprintf('%s %s %s 总耗时: %.2fms', $tag, $info, $status, $total);
        $logLines[] = sprintf('%-8s | %-20s | %-10s | %-10s | %-6s', '(索引)', 'step', 'timestamp', 'duration', 'pct');
        $logLines[] = str_repeat('-', 60);

        foreach ($rows as $row) {
            $logLines[] = sprintf('%-8s | %-20s | %-10s | %-10s | %-6s',
                $row['index'],
                $row['step'],
                $row['timestamp'],
                $row['duration'],
                $row['pct']
            );
        }

        usort($rows, function ($a, $b) {
            return $b['raw_duration'] <=> $a['raw_duration'];
        });

        $maxDuration = $rows[0]['raw_duration'] ?? 0;

        $logLines[] = '';
        $logLines[] = '耗时排行（从慢到快）';
        $maxBar = 50;
        $rank = 1;
        foreach ($rows as $row) {
            if ($row['raw_duration'] < 0.001) continue;
            $barLen = $maxDuration > 0 ? (int) ($row['raw_duration'] / $maxDuration * $maxBar) : 0;
            $barLen = max($barLen, 1);
            $bar = str_repeat('█', $barLen);
            $logLines[] = sprintf(' %d. %-20s %9.1fms %s', $rank, $row['step'], $row['raw_duration'], $bar);
            $rank++;
        }

        return implode("\n", $logLines);
    }
}
