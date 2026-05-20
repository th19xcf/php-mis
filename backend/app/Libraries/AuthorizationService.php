<?php

namespace App\Libraries;

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

        if ($user !== '' && $role !== '') {
            return $this->normalize($user . ',' . $role);
        }

        if ($user !== '') {
            return $user;
        }

        if ($role !== '') {
            return $role;
        }

        return $this->normalize($fallback);
    }

    public function resolveLocation(string $userLocationAuth, string $roleLocationAuth, string $employeeRegion): string
    {
        return $this->resolve($userLocationAuth, $roleLocationAuth, $employeeRegion);
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
        if ($field === '' || $resolvedAuth === '') {
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
}
