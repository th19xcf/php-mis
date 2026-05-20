<?php

namespace App\Libraries;

class LocationAuthService
{
    /**
     * 将属地赋权字符串标准化：中文逗号转英文逗号、去除空白、去重。
     *
     * @param string $value 原始属地赋权值（可能含中文逗号、空格、重复项）
     * @return string 标准化后的逗号分隔字符串
     */
    public function normalize(string $value): string
    {
        if (trim($value) === '') {
            return '';
        }

        $normalized = str_replace('，', ',', $value);
        $parts = array_map('trim', explode(',', $normalized));
        $parts = array_filter($parts, static fn(string $item): bool => $item !== '');
        $parts = array_unique($parts);

        return implode(',', array_values($parts));
    }

    /**
     * 基于双重数据源解析属地赋权。
     *
     * 规则 1.1：当其中一个为空时，采用非空字段
     * 规则 1.2：当两个均不为空时，合并作为联合判定条件
     * 规则 1.3：当两个均为空时，降级使用员工属地
     *
     * @param string $userLocationAuth def_user.属地赋权（用户级）
     * @param string $roleLocationAuth view_role.属地赋权（角色-功能级）
     * @param string $employeeRegion   def_user.员工属地（兜底值）
     * @return string 解析后的属地赋权字符串（逗号分隔）
     */
    public function resolve(string $userLocationAuth, string $roleLocationAuth, string $employeeRegion): string
    {
        $userAuth = $this->normalize($userLocationAuth);
        $roleAuth = $this->normalize($roleLocationAuth);

        if ($userAuth !== '' && $roleAuth !== '') {
            return $this->normalize($userAuth . ',' . $roleAuth);
        }

        if ($userAuth !== '') {
            return $userAuth;
        }

        if ($roleAuth !== '') {
            return $roleAuth;
        }

        return $this->normalize($employeeRegion);
    }

    /**
     * 将解析后的属地赋权值拆分为数组。
     *
     * @param string $resolvedAuth resolve() 返回的标准化字符串
     * @return string[] 属地标识数组
     */
    public function split(string $resolvedAuth): array
    {
        if ($resolvedAuth === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', $resolvedAuth));
        $parts = array_filter($parts, static fn(string $item): bool => $item !== '');

        return array_values($parts);
    }

    /**
     * 构建属地赋权 SQL WHERE 条件片段。
     *
     * @param string $locationField 数据库属地字段名
     * @param string $resolvedAuth  resolve() 返回的标准化属地赋权字符串
     * @param bool   $upkeepAuth    是否具有维护权限（维护权限时允许空值记录）
     * @return string SQL WHERE 条件片段，无有效条件时返回空字符串
     */
    public function buildCondition(string $locationField, string $resolvedAuth, bool $upkeepAuth): string
    {
        if ($locationField === '' || $resolvedAuth === '') {
            return '';
        }

        $values = $this->split($resolvedAuth);
        $clauses = [];
        foreach ($values as $value) {
            $clauses[] = sprintf('instr(%s,"%s")', $locationField, $value);
        }

        if (!$clauses) {
            return '';
        }

        $expr = implode(' or ', $clauses);
        if ($upkeepAuth) {
            $expr .= sprintf(' or %s=""', $locationField);
        }

        return $expr;
    }
}
