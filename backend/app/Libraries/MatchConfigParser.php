<?php

namespace App\Libraries;

/**
 * 匹配配置解析器（纯函数集）
 *
 * 从 MatchApi 机械抽取的 def_match_config 字符串解析与写入指令求值逻辑，
 * 不持有状态、不触 DB。配置读取（getMatchConfigRow 等）留在控制器。
 *
 * 配置格式约定：
 * - 匹配条件：A.<A表字段>=B.<B表字段>，多条用英文分号分隔
 * - 写入指令：<目标表前缀>.<目标字段>=<source>，多条用分号分隔；
 *   source 为 A.<字段> / B.<字段> / uuid（不区分大小写）/ 字面量
 * - 计算字段：字段名列表，英文逗号分隔
 */
class MatchConfigParser
{
    /**
     * 解析匹配条件字符串
     *
     * @param string $rawConditions 原始字符串（如 "A.贷方金额=B.财务计收金额;A.对方名称=B.对方名称"）
     * @return array<int, array{aField: string, bField: string, text: string}>
     */
    public static function parseMatchConditions(string $rawConditions): array
    {
        if ($rawConditions === '') {
            return [];
        }

        $parts = array_map('trim', explode(';', $rawConditions));
        $result = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            // 解析 A.<aField>=B.<bField>
            if (preg_match('/^A\.(.+?)=B\.(.+)$/', $part, $m)) {
                $result[] = [
                    'aField' => trim($m[1]),
                    'bField' => trim($m[2]),
                    'text' => $part,
                ];
            }
        }

        return $result;
    }

    /**
     * 解析写入指令字符串
     *
     * @param string $raw 原始字符串（如 "A.记账表ID=uuid;A.备注=B.说明"）
     * @param string $targetPrefix 目标表前缀（A 或 B），不匹配前缀的片段跳过
     * @return array<int, array{targetField: string, sourceType: string, sourceTable: string, sourceField: string, text: string}>
     */
    public static function parseWriteInstructions(string $raw, string $targetPrefix): array
    {
        if ($raw === '') {
            return [];
        }

        $parts = array_map('trim', explode(';', $raw));
        $result = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            // 格式：<targetPrefix>.<targetField>=<source>
            if (preg_match('/^' . preg_quote($targetPrefix, '/') . '\.(.+?)=(.+)$/', $part, $m)) {
                $targetField = trim($m[1]);
                $source = trim($m[2]);

                $sourceType = 'literal';
                $sourceTable = '';
                $sourceField = '';

                if (preg_match('/^A\.(.+)$/', $source, $sm)) {
                    $sourceType = 'field';
                    $sourceTable = 'A';
                    $sourceField = trim($sm[1]);
                } elseif (preg_match('/^B\.(.+)$/', $source, $sm)) {
                    $sourceType = 'field';
                    $sourceTable = 'B';
                    $sourceField = trim($sm[1]);
                } elseif (strtolower($source) === 'uuid') {
                    $sourceType = 'uuid';
                } else {
                    $sourceType = 'literal';
                    $sourceField = $source;
                }

                $result[] = [
                    'targetField' => $targetField,
                    'sourceType' => $sourceType,
                    'sourceTable' => $sourceTable,
                    'sourceField' => $sourceField,
                    'text' => $part,
                ];
            }
        }

        return $result;
    }

    /**
     * 解析计算字段列表（英文逗号分隔，空片段过滤）
     *
     * @return string[]
     */
    public static function parseCalcFields(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $parts = array_map('trim', explode(',', $raw));
        $parts = array_filter($parts, static fn($v) => $v !== '');
        return array_values($parts);
    }

    /**
     * 生成 UUID v4
     */
    public static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * 根据写入指令解析源值
     *
     * @param array $write 写入指令（parseWriteInstructions 的单项）
     * @param array $aRecord A 表记录
     * @param array $bRecord B 表记录
     * @return string 源值（uuid 类型每次调用生成新值）
     */
    public static function resolveWriteSourceValue(array $write, array $aRecord, array $bRecord): string
    {
        if ($write['sourceType'] === 'uuid') {
            return self::generateUuid();
        }
        if ($write['sourceType'] === 'field') {
            if ($write['sourceTable'] === 'A') {
                return (string) ($aRecord[$write['sourceField']] ?? '');
            }
            if ($write['sourceTable'] === 'B') {
                return (string) ($bRecord[$write['sourceField']] ?? '');
            }
        }
        // literal
        return $write['sourceField'];
    }
}
