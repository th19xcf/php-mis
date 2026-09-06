<?php

namespace App\Libraries;

/**
 * 匹配关系处理器（纯函数集）
 *
 * 从 MatchApi 机械抽取的匹配标记与写入数据构造逻辑，不触 DB。
 * 事务编排、记录预读（WHERE pk IN 批量查询）、更新执行留在控制器。
 *
 * 语义要点（与原实现逐字一致，特征测试锁定）：
 * - A 侧标记：bToA 首条写入的 targetField 非空 → 已匹配
 * - B 侧标记：aToB 中首个 field/A 源写入的源/目标字段交叉引用
 * - aToB 写入：field/A 源收集全部 A 记录值以分号拼接；uuid/literal/B 字段取单值
 * - bToA 写入：field/B 源同上；uuid 源按 B 记录数逐条生成后拼接
 * - 目标键合并（旧版回退）：逗号分隔，保留既有顺序去重追加
 * - 目标键移除（撤销 specific）：兼容分号/逗号输入，输出统一分号并过滤空段
 */
class MatchRelationProcessor
{
    /**
     * 收集匹配标记所需的两侧字段（含重复，由调用方按行键过滤）
     *
     * @param array{aToB?: array, bToA?: array} $matchWrites 写入指令
     * @return array{a: string[], b: string[]}
     */
    public static function collectNeededFields(array $matchWrites): array
    {
        $bToAWrites = $matchWrites['bToA'] ?? [];
        $aToBWrites = $matchWrites['aToB'] ?? [];

        $aNeededFields = [];
        $bNeededFields = [];
        foreach ($bToAWrites as $w) {
            $aNeededFields[] = $w['targetField'];
        }
        foreach ($aToBWrites as $w) {
            if ($w['sourceType'] === 'field' && $w['sourceTable'] === 'A') {
                $aNeededFields[] = $w['sourceField'];
            }
            if ($w['sourceType'] === 'field' && $w['sourceTable'] === 'B') {
                $bNeededFields[] = $w['sourceField'];
            }
            $bNeededFields[] = $w['targetField'];
        }

        return ['a' => $aNeededFields, 'b' => $bNeededFields];
    }

    /**
     * 计算行集中缺失的字段（去重 + 首行不存在的键）
     *
     * @param string[] $neededFields 所需字段（可含重复）
     * @param array<string, mixed> $firstRow 行集首行（空行集传 []）
     * @return string[]
     */
    public static function computeMissingFields(array $neededFields, array $firstRow): array
    {
        return array_unique(array_filter($neededFields, static fn($f) => !array_key_exists($f, $firstRow)));
    }

    /**
     * 为 A/B 行集原地计算 __matched 标记
     *
     * @param array<int, array<string, mixed>> $aRows
     * @param array<int, array<string, mixed>> $bRows
     * @param array{aToB?: array, bToA?: array} $matchWrites
     */
    public static function markMatchedFlags(array &$aRows, array &$bRows, array $matchWrites): void
    {
        $bToAWrites = $matchWrites['bToA'] ?? [];
        $aToBWrites = $matchWrites['aToB'] ?? [];

        // A 侧标记：bToA 的 targetField 非空 → 已匹配
        $aMarkerField = null;
        if (!empty($bToAWrites)) {
            $aMarkerField = $bToAWrites[0]['targetField'];
        }

        if ($aMarkerField) {
            foreach ($aRows as &$row) {
                $row['__matched'] = !empty($row[$aMarkerField]);
            }
            unset($row);
        } else {
            foreach ($aRows as &$row) {
                $row['__matched'] = false;
            }
            unset($row);
        }

        // B 侧标记：通过 aToB 写入指令的源/目标字段交叉引用
        $aToBLink = null;
        foreach ($aToBWrites as $write) {
            if ($write['sourceType'] === 'field' && $write['sourceTable'] === 'A') {
                $aToBLink = $write;
                break;
            }
        }

        if ($aToBLink) {
            $aSourceField = $aToBLink['sourceField'];
            $bTargetField = $aToBLink['targetField'];

            $matchedAValues = [];
            foreach ($aRows as $aRow) {
                if (!empty($aRow['__matched'])) {
                    $val = (string) ($aRow[$aSourceField] ?? '');
                    if ($val !== '') {
                        $matchedAValues[$val] = true;
                    }
                }
            }

            foreach ($bRows as &$row) {
                $bTargetValue = (string) ($row[$bTargetField] ?? '');
                $row['__matched'] = $bTargetValue !== '' && isset($matchedAValues[$bTargetValue]);
            }
            unset($row);
        } else {
            foreach ($bRows as &$row) {
                $row['__matched'] = false;
            }
            unset($row);
        }
    }

    /**
     * 写入指令列表是否覆盖指定目标字段
     *
     * @param array<int, array{targetField: string}> $writes
     */
    public static function isTargetCovered(array $writes, string $targetField): bool
    {
        foreach ($writes as $write) {
            if ($write['targetField'] === $targetField) {
                return true;
            }
        }
        return false;
    }

    /**
     * 构造 aToB 写入的单条 B 记录更新数据
     *
     * field/A 源：收集全部 A 记录（按 aKeys 顺序）的非空源值，分号拼接；
     * 其余（uuid/literal/B 字段）：取首条 A 记录解析单值。
     *
     * @param array $aToBWrites aToB 写入指令
     * @param string[] $aKeys A 侧选中键
     * @param array<string, array> $aRecords 键 => A 记录（预读）
     * @param array<string, array> $bRecords 键 => B 记录（预读）
     * @param string $bKey 当前 B 记录键
     * @return array<string, string> 目标字段 => 值
     */
    public static function buildAToBUpdateData(array $aToBWrites, array $aKeys, array $aRecords, array $bRecords, string $bKey): array
    {
        $updateData = [];
        $bRecord = $bRecords[$bKey] ?? [];
        foreach ($aToBWrites as $write) {
            if ($write['sourceType'] === 'field' && $write['sourceTable'] === 'A') {
                // 收集所有 A 记录的源字段值，用英文分号拼接
                $values = [];
                foreach ($aKeys as $aKey) {
                    $aRecord = $aRecords[$aKey] ?? [];
                    $val = (string) ($aRecord[$write['sourceField']] ?? '');
                    if ($val !== '') {
                        $values[] = $val;
                    }
                }
                $value = implode(';', $values);
            } else {
                // uuid / literal / B 字段：单值
                $firstAKey = $aKeys[0] ?? '';
                $firstARecord = $aRecords[$firstAKey] ?? [];
                $value = MatchConfigParser::resolveWriteSourceValue($write, $firstARecord, $bRecord);
            }
            $updateData[$write['targetField']] = $value;
        }
        return $updateData;
    }

    /**
     * 构造 bToA 写入的单条 A 记录更新数据
     *
     * field/B 源：收集全部 B 记录（按 bKeys 顺序）的非空源值，分号拼接；
     * uuid 源：按 B 记录数逐条生成后拼接（与 aToB 的单值 uuid 语义不同）；
     * 其余（literal/A 字段）：取首条 B 记录解析单值。
     *
     * @param array $bToAWrites bToA 写入指令
     * @param string[] $bKeys B 侧选中键
     * @param array<string, array> $bRecords 键 => B 记录（预读）
     * @param array<string, array> $aRecords 键 => A 记录（预读）
     * @param string $aKey 当前 A 记录键
     * @return array<string, string> 目标字段 => 值
     */
    public static function buildBToAUpdateData(array $bToAWrites, array $bKeys, array $bRecords, array $aRecords, string $aKey): array
    {
        $updateData = [];
        $aRecord = $aRecords[$aKey] ?? [];
        foreach ($bToAWrites as $write) {
            if ($write['sourceType'] === 'field' && $write['sourceTable'] === 'B') {
                $values = [];
                foreach ($bKeys as $bKey) {
                    $bRecord = $bRecords[$bKey] ?? [];
                    $val = (string) ($bRecord[$write['sourceField']] ?? '');
                    if ($val !== '') {
                        $values[] = $val;
                    }
                }
                $value = implode(';', $values);
            } elseif ($write['sourceType'] === 'uuid') {
                $values = [];
                foreach ($bKeys as $_) {
                    $values[] = MatchConfigParser::generateUuid();
                }
                $value = implode(';', $values);
            } else {
                $firstBKey = $bKeys[0] ?? '';
                $firstBRecord = $bRecords[$firstBKey] ?? [];
                $value = MatchConfigParser::resolveWriteSourceValue($write, $aRecord, $firstBRecord);
            }
            $updateData[$write['targetField']] = $value;
        }
        return $updateData;
    }

    /**
     * 合并目标键（旧版 key 写入回退：逗号分隔，保留既有顺序，去重追加）
     *
     * @param string $currentTargets 当前目标串（空串视为无既有值）
     * @param string[] $keys 待追加的键
     */
    public static function mergeTargetKeys(string $currentTargets, array $keys): string
    {
        $targetArray = $currentTargets ? explode(',', $currentTargets) : [];
        $newTargets = array_unique(array_merge($targetArray, $keys));
        return implode(',', $newTargets);
    }

    /**
     * 移除目标键（撤销 specific 模式：兼容分号/逗号分隔输入，输出统一为分号并过滤空段）
     *
     * @param string $currentTargets 当前目标串
     * @param string[] $keys 待移除的键
     */
    public static function removeTargetKeys(string $currentTargets, array $keys): string
    {
        $targetArray = $currentTargets ? preg_split('/[;,]/', $currentTargets) : [];
        $targetArray = array_map('trim', $targetArray);
        $newTargets = array_diff($targetArray, $keys);
        return implode(';', array_filter($newTargets, static fn($v) => $v !== ''));
    }

    /**
     * 收集写入指令的目标字段（去重，保持首次出现顺序）
     *
     * @param array<int, array{targetField: string}> $writes
     * @return string[]
     */
    public static function collectTargetFields(array $writes): array
    {
        $fields = [];
        foreach ($writes as $write) {
            $fields[$write['targetField']] = true;
        }
        return array_keys($fields);
    }
}
