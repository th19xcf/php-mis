<?php

namespace App\Services\Workflow;

/**
 * 流程条件安全匹配器（无 eval）
 *
 * 供流程连线（def_workflow_edge.匹配条件）与流程路由（def_workflow_routing.匹配条件）
 * 共用的 JSON 结构化条件匹配逻辑，替代原 evaluateCondition 基于 eval 的实现（防表达式注入）。
 *
 * 条件 JSON 支持两种写法（多键之间为 AND 关系）：
 *
 * 1. 简单等值匹配：
 *    {"合同类型":"采购合同","合同分类":"标准"}
 *
 * 2. 操作符匹配（值用 [操作符 => 操作数] 对象表示）：
 *    {
 *      "合同类型":"采购合同",
 *      "合同金额":{">=":100000,"<":5000000},
 *      "所属部门":{"IN":["D001","D002"]},
 *      "签订日期":{"between":["2026-01-01","2026-12-31"]}
 *    }
 *
 * 支持的操作符：=、==、!=、<>、>、>=、<、<=、in、between、like、isnull、notnull
 */
class WorkflowConditionMatcher
{
    /**
     * 所有条件均需满足（AND 关系）
     *
     * @param array $conditions 条件 JSON 解析结果（键为变量名）
     * @param array $context     变量上下文（流程变量 / 业务上下文键值对）
     */
    public static function matchAll(array $conditions, array $context): bool
    {
        foreach ($conditions as $field => $rule) {
            $value = $context[$field] ?? null;

            if (!self::matchSingle($rule, $value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 单字段匹配：支持标量直接比较或操作符对象
     *
     * @param mixed $rule  规则（标量或 [操作符 => 操作数] 数组）
     * @param mixed $value 上下文中该字段的值
     */
    private static function matchSingle($rule, $value): bool
    {
        // 标量直接相等比较
        if (is_scalar($rule) || $rule === null) {
            return self::castCompare($value) === self::castCompare($rule);
        }

        if (is_array($rule)) {
            foreach ($rule as $op => $operand) {
                // 数字键数组：视为 IN 集合
                if (is_int($op)) {
                    return self::opIn($value, $rule);
                }

                if (!self::applyOperator(is_string($op) ? strtolower($op) : '', $value, $operand)) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }

    /**
     * 操作符执行
     *
     * @param string $op       小写操作符
     * @param mixed   $value    上下文值
     * @param mixed   $operand  操作数
     */
    private static function applyOperator(string $op, $value, $operand): bool
    {
        switch ($op) {
            case '=':
            case '==':
                return self::castCompare($value) === self::castCompare($operand);

            case '!=':
            case '<>':
                return self::castCompare($value) !== self::castCompare($operand);

            case '>':
                return self::toNumber($value) > self::toNumber($operand);

            case '>=':
                return self::toNumber($value) >= self::toNumber($operand);

            case '<':
                return self::toNumber($value) < self::toNumber($operand);

            case '<=':
                return self::toNumber($value) <= self::toNumber($operand);

            case 'in':
                return self::opIn($value, $operand);

            case 'between':
                return self::opBetween($value, $operand);

            case 'like':
                return self::opLike($value, $operand);

            case 'isnull':
                return $value === null || $value === '';

            case 'notnull':
                return $value !== null && $value !== '';

            default:
                // 未知操作符视为不匹配
                return false;
        }
    }

    /**
     * IN 集合匹配（统一类型转换后严格比较）
     */
    private static function opIn($value, $operand): bool
    {
        if (!is_array($operand)) {
            $operand = [$operand];
        }
        $normalized = array_map(fn ($v) => self::castCompare($v), $operand);
        return in_array(self::castCompare($value), $normalized, true);
    }

    /**
     * 区间匹配（含边界，数值比较）
     */
    private static function opBetween($value, $operand): bool
    {
        if (!is_array($operand) || count($operand) < 2) {
            return false;
        }
        $num = self::toNumber($value);
        $min = self::toNumber($operand[0]);
        $max = self::toNumber($operand[1]);
        return $num >= $min && $num <= $max;
    }

    /**
     * LIKE 匹配（% 与 _ 转为正则通配，整体锚定）
     */
    private static function opLike($value, $operand): bool
    {
        if ($value === null) {
            return false;
        }
        $pattern = str_replace(['%', '_'], ['.*?', '.'], preg_quote((string) $operand, '/'));
        return (bool) preg_match('/^' . $pattern . '$/u', (string) $value);
    }

    /**
     * 标量比较时的统一类型转换（数字字符串 -> 数字，避免 "100" != 100）
     */
    private static function castCompare($value)
    {
        if (is_string($value) && is_numeric($value)) {
            return strpos($value, '.') !== false ? (float) $value : (int) $value;
        }
        return $value;
    }

    private static function toNumber($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }
        return (float) $value;
    }
}
