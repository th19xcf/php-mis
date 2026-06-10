<?php

namespace App\Libraries;

use App\Models\Mcommon;
use App\Libraries\SessionUserContext;

class AuthorizationService
{
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

    public function resolve(string $userAuth, string $roleAuth, string $fallback): string
    {
        $user = $this->normalize($userAuth);
        $role = $this->normalize($roleAuth);

        if ($this->isUnlimited($user)) {
            return '不限';
        }

        if ($user !== '' && $role !== '') {
            return $this->intersect($user, $role);
        }

        if ($user !== '') {
            return $user;
        }

        if ($role !== '') {
            return $role;
        }

        return $this->normalize($fallback);
    }

    public function resolveDeptName(string $userDeptNameAuth, string $roleDeptNameAuth, string $employeeDeptName): string
    {
        return $this->resolve($userDeptNameAuth, $roleDeptNameAuth, $employeeDeptName);
    }

    public function split(string $resolvedAuth): array
    {
        if ($resolvedAuth === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', $resolvedAuth));
        $parts = array_filter($parts, static fn(string $item): bool => $item !== '');

        return array_values($parts);
    }

    public function buildCondition(string $field, string $resolvedAuth, bool $upkeepAuth): string
    {
        if ($field === '' || $resolvedAuth === '' || $this->isUnlimited($resolvedAuth)) {
            return '';
        }

        $values = $this->split($resolvedAuth);
        $clauses = [];
        foreach ($values as $value) {
            $clauses[] = sprintf('instr(%s,"%s")', $field, $value);
        }

        if (!$clauses) {
            return '';
        }

        $expr = implode(' or ', $clauses);
        if ($upkeepAuth) {
            $expr .= sprintf(' or %s=""', $field);
        }

        return $expr;
    }

    public function buildDeptNameCondition(string $field, string $resolvedAuth, bool $upkeepAuth): string
    {
        return $this->buildCondition($field, $resolvedAuth, $upkeepAuth);
    }

    /**
     * 加载 def_user 上"按人员/属地"的赋权字段（如 属地赋权 / 部门全称赋权）。
     *
     * 默认从 SessionUserContext 取 workId 与 region；
     * 显式传入 $workId / $region 时优先使用参数值（用于登录流程尚未写入 session 的场景）。
     *
     * @param string $fieldName   def_user 上的字段名（也是返回结果数组的键）
     * @param string|null $workId  工号；为空时从 session 取
     * @param string|null $region  员工属地；为空时从 session 取
     * @return string              字段值（已做 中文逗号 → ASCII 逗号 + 去空格 规范化）
     */
    public function loadUserAuthField(string $fieldName, ?string $workId = null, ?string $region = null): string
    {
        if ($workId === null || $region === null) {
            $sessionUser = (new SessionUserContext())->getSessionUser();
            if ($workId === null) {
                $workId = (string) ($sessionUser['workId'] ?? '');
            }
            if ($region === null) {
                $region = (string) ($sessionUser['location'] ?? '');
            }
        }

        if ($workId === '' || $region === '') {
            return '';
        }

        $model = new Mcommon();
        $sql = sprintf(
            'select replace(replace(%s,"，",",")," ","") as %s from def_user where 有效标识="1" and 员工属地=%s and 工号=%s',
            $fieldName, $fieldName,
            $model->quote($region),
            $model->quote($workId)
        );

        $row = $model->select($sql)->getRowArray();
        return (string) ($row[$fieldName] ?? '');
    }

    private function isUnlimited(string $value): bool
    {
        return trim($value) === '不限';
    }

    private function intersect(string $a, string $b): string
    {
        $partsA = $this->split($a);
        $partsB = $this->split($b);
        $common = array_values(array_intersect($partsA, $partsB));
        return implode(',', $common);
    }
}
