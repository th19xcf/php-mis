<?php

namespace App\Libraries;

use App\Models\Mcommon;
use App\Libraries\SessionUserContext;
use App\Libraries\MetadataCache;

class AuthorizationService
{
    private Mcommon $model;
    private MetadataCache $metadataCache;

    public function __construct()
    {
        $this->model = new Mcommon();
        $this->metadataCache = new MetadataCache();
    }

    public function normalize(string $value): string
    {
        if (trim($value) === '') {
            return '';
        }

        $normalized = str_replace('，', ',', $value);
        $parts = array_map('trim', explode(',', $normalized));
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
            return $user . '|' . $role;
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

        return array_values($parts);
    }

    public function buildCondition(string $field, string $resolvedAuth, bool $upkeepAuth): string
    {
        if ($field === '' || $resolvedAuth === '' || $this->isUnlimited($resolvedAuth)) {
            return '';
        }

        $andParts = explode('|', $resolvedAuth);
        $conditions = [];

        foreach ($andParts as $part) {
            $values = $this->split($part);
            $clauses = [];
            $hasEmptyValue = false;
            foreach ($values as $value) {
                if ($value === '' || $value === '""') {
                    $hasEmptyValue = true;
                    $clauses[] = sprintf('%s=""', $field);
                } else {
                    $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
                    $clauses[] = sprintf('%s like "%s%%" escape char(92)', $field, $escaped);
                }
            }

            if (!$clauses) {
                continue;
            }

            $expr = implode(' or ', $clauses);
            if ($upkeepAuth && !$hasEmptyValue) {
                $expr .= sprintf(' or %s=""', $field);
            }
            $conditions[] = '(' . $expr . ')';
        }

        if (empty($conditions)) {
            return '';
        }

        return implode(' AND ', $conditions);
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
        $result = $this->loadUserAuthFields([$fieldName], $workId, $region);
        return $result[$fieldName] ?? '';
    }

    /**
     * 批量加载 def_user 上多个赋权字段（一次 SQL 查询取多个字段）。
     *
     * 与 loadUserAuthField 相同的数据源，但支持一次查询获取多个字段值，
     * 避免对同一 workId + region 组合重复查询 def_user。
     * 使用一次 SQL 查出所有字段，PHP 端分别做规范化处理。
     *
     * @param string[] $fieldNames def_user 上的字段名列表
     * @param string|null $workId  工号；为空时从 session 取
     * @param string|null $region  员工属地；为空时从 session 取
     * @return array<string, string> 以 fieldName 为键、规范化后的赋权值为值的字典
     */
    public function loadUserAuthFields(array $fieldNames, ?string $workId = null, ?string $region = null): array
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

        $fieldNames = array_values(array_filter(array_map('trim', $fieldNames)));
        $emptyResult = array_fill_keys($fieldNames, '');

        if ($workId === '' || $region === '' || empty($fieldNames)) {
            return $emptyResult;
        }

        $selectFields = [];
        foreach ($fieldNames as $fn) {
            $selectFields[] = sprintf(
                'replace(replace(%s,"，",",")," ","") as %s',
                $fn, $fn
            );
        }

        $sql = sprintf(
            'select %s from def_user where 有效标识="1" and 员工属地=%s and 工号=%s',
            implode(', ', $selectFields),
            $this->model->quote($region),
            $this->model->quote($workId)
        );

        $row = $this->model->select($sql)->getRowArray();
        if (!$row) {
            return $emptyResult;
        }

        $output = [];
        foreach ($fieldNames as $fn) {
            $raw = (string) ($row[$fn] ?? '');
            $output[$fn] = $this->normalize($raw);
        }

        return $output;
    }

    /**
     * 批量加载角色表上的多个赋权字段（一次查询取多个字段）。
     *
     * 与 loadRoleAuthField 相同的数据源，但支持一次查询获取多个字段值，
     * 避免对同一 functionCode + roleAuthz 组合重复查询 view_role。
     * 使用一次 SQL 查出所有字段，PHP 端拆分逗号分隔值并去重。
     *
     * @param string $functionCode 功能编码
     * @param string[] $fieldDefs  字段定义列表，每项为 ['fieldName' => string, 'aliasName' => string]
     * @param string $roleAuthz    角色编码列表（逗号分隔）
     * @return array<string, string> 以 fieldName 为键、规范化后的赋权值为值的字典
     */
    public function loadRoleAuthFields(string $functionCode, array $fieldDefs, string $roleAuthz): array
    {
        $roleAuthz = trim($roleAuthz);
        $emptyResult = array_fill_keys(array_column($fieldDefs, 'fieldName'), '');

        if ($roleAuthz === '' || $functionCode === '' || empty($fieldDefs)) {
            return $emptyResult;
        }

        // 安全：将角色编码逐个 quote 后再拼入 IN 子句
        $roleParts = array_map(
            fn($r) => $this->model->quote(trim($r)),
            explode(',', $roleAuthz)
        );
        $roleList = implode(',', $roleParts);

        // 一次查出所有需要的字段
        $selectFields = [];
        $fieldNames = [];
        foreach ($fieldDefs as $def) {
            $fn = $def['fieldName'];
            $fieldNames[] = $fn;
            // group_concat 使用实际列名(fieldName)，输出别名也用 fieldName
            $selectFields[] = sprintf(
                'replace(replace(group_concat(%s separator ","),",",",")," ","") as %s',
                $fn, $fn
            );
        }

        $sql = sprintf(
            'select %s
            from view_role
            where 有效标识="1" and 角色编码 in (%s) and 功能编码赋权=%s',
            implode(', ', $selectFields),
            $roleList,
            $this->model->quote($functionCode)
        );

        $result = $this->model->select($sql);
        if ($result === false) {
            return $emptyResult;
        }

        $row = $result->getRowArray();
        if (!$row) {
            return $emptyResult;
        }

        // PHP 端拆分逗号分隔值并去重
        $output = [];
        foreach ($fieldNames as $fn) {
            $raw = (string) ($row[$fn] ?? '');
            $output[$fn] = $this->normalize($raw);
        }

        return $output;
    }

    /**
     * 获取每个角色编码对应的部门全称赋权列表（用于调试输出）
     *
     * 从 view_role 表查询指定功能编码下、每个角色的"部门全称赋权"字段值，
     * 返回一个数组，key 是角色编码，value 是该角色的部门全称赋权（规范化后）。
     *
     * @param string $functionCode 功能编码
     * @param string $roleAuthz    角色编码列表（逗号分隔）
     * @return array<string, string> 角色编码 → 部门全称赋权 的映射
     */
    public function getRoleDeptNameAuthzList(string $functionCode, string $roleAuthz): array
    {
        $roleAuthz = trim($roleAuthz);
        if ($roleAuthz === '' || $functionCode === '') {
            return [];
        }

        // 安全：将角色编码逐个 quote 后再拼入 IN 子句
        $roleParts = array_map(
            fn($r) => $this->model->quote(trim($r)),
            explode(',', $roleAuthz)
        );
        $roleList = implode(',', $roleParts);

        $sql = sprintf(
            'select 角色编码, replace(replace(部门全称赋权,"，",",")," ","") as 部门全称赋权
            from view_role
            where 有效标识="1" and 角色编码 in (%s) and 功能编码赋权=%s',
            $roleList,
            $this->model->quote($functionCode)
        );

        $result = $this->model->select($sql);
        if ($result === false) {
            return [];
        }

        $rows = $result->getResultArray();
        $output = [];
        foreach ($rows as $row) {
            $roleCode = (string) ($row['角色编码'] ?? '');
            $deptNameAuthz = $this->normalize((string) ($row['部门全称赋权'] ?? ''));
            if ($roleCode !== '') {
                $output[$roleCode] = $deptNameAuthz;
            }
        }

        return $output;
    }

    /**
     * 加载角色表上的赋权字段（如 属地赋权 / 部门全称赋权）。
     *
     * 从 view_role 表查询指定功能编码下、当前用户角色对应的赋权字段值，
     * 使用 def_GUID 辅助表对逗号分隔的值进行拆行，最终去重并返回。
     *
     * @param string $functionCode 功能编码
     * @param string $fieldName    字段名（如 "属地赋权"、"部门全称赋权"）
     * @param string $aliasName    SQL 别名（如 "角色表属地"、"全称赋权"）
     * @param string $roleAuthz    角色编码列表（逗号分隔，来自 session）
     * @return string              规范化后的赋权值（逗号分隔），无数据返回空字符串
     */
    public function loadRoleAuthField(string $functionCode, string $fieldName, string $aliasName, string $roleAuthz): string
    {
        $roleAuthz = trim($roleAuthz);
        if ($roleAuthz === '' || $functionCode === '') {
            return '';
        }

        // 安全：将角色编码逐个 quote 后再拼入 IN 子句，防止 SQL 注入
        $roleParts = array_map(
            fn($r) => $this->model->quote(trim($r)),
            explode(',', $roleAuthz)
        );
        $roleList = implode(',', $roleParts);

        $sql = sprintf(
            'select substring_index(substring_index(%s,",",t2.GUID+1),",",-1) as %s
            from
            (
                select GUID,replace(replace(%s,"，",",")," ","") as %s
                from view_role
                where 有效标识="1" and 角色编码 in (%s) and 功能编码赋权=%s
            ) as t1
            inner join def_GUID as t2 on t2.GUID<(length(%s)-length(replace(%s,",",""))+1)
            group by %s
            order by %s',
            $aliasName, $fieldName,
            $fieldName, $aliasName,
            $roleList, $this->model->quote($functionCode),
            $aliasName, $aliasName,
            $fieldName, $fieldName
        );

        $result = $this->model->select($sql);
        if ($result === false) {
            return '';
        }

        $values = [];
        foreach ($result->getResultArray() as $row) {
            $value = trim((string) ($row[$fieldName] ?? ''));
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return implode(',', array_values(array_unique($values)));
    }

    /**
     * 加载工作台查询配置（统一入口）。
     *
     * 取代 WorkbenchResponseTrait::loadQueryConfig 与 ContextService::loadQueryConfig
     * 的两份重复实现，统一从 def_query_config 读取 21 字段配置并替换 $角色 变量。
     *
     * 与原实现的差异：
     * - 统一使用 Mcommon::quote 替代 WorkbenchResponseTrait 手写 str_replace（更安全）
     * - 保留 trait 版本更严格的 false check
     * - 通过 $logger 回调参数保留 ContextService 原本的 log_message 行为
     * - trait 与 service 都不再各自实现，调用方升级到本方法即可
     *
     * @param string $functionCode 功能编码
     * @param string $userRole     当前用户角色（用于替换 $角色 变量）
     * @param \Closure|null $logger 可选日志回调：function(string $msg, string $level): void
     * @return array 配置字典（21 字段），无数据时返回 []
     */
    public function loadQueryConfig(string $functionCode, string $userRole, ?\Closure $logger = null): array
    {
        if ($logger !== null) {
            $logger('[loadQueryConfig] 开始加载查询配置, functionCode=' . $functionCode, 'debug');
        }

        // 通过 MetadataCache 获取配置（命中缓存时跳过 SQL），
        // SQL 逻辑与原实现一致：通过 def_function 关联查询 def_query_config
        $row = $this->metadataCache->getQueryConfigByFunction($functionCode);
        if (!$row) {
            return [];
        }

        $queryWhere = (string) ($row['查询条件'] ?? '');
        if ($queryWhere !== '' && strpos($queryWhere, '$角色') !== false) {
            $queryWhere = str_replace('$角色', $userRole, $queryWhere);
        }

        $config = [
            'queryModule'   => (string) ($row['查询模块'] ?? ''),
            'drillModule'   => (string) ($row['钻取模块'] ?? ''),
            'mode'          => (string) ($row['模块类型'] ?? '数据查询'),
            'fieldModule'   => (string) ($row['字段模块'] ?? ''),
            'queryTable'    => (string) ($row['查询表名'] ?? ''),
            'dataTable'     => (string) ($row['数据表名'] ?? ''),
            'dataModel'     => (string) ($row['数据模式'] ?? ''),
            'queryWhere'    => $queryWhere,
            'queryGroup'    => (string) ($row['汇总条件'] ?? ''),
            'queryOrder'    => (string) ($row['排序条件'] ?? ''),
            'resultCount'   => (int) ($row['初始条数'] ?? 0),
            'beforeInsert'  => (string) ($row['新增前处理模块'] ?? ''),
            'afterInsert'   => (string) ($row['新增后处理模块'] ?? ''),
            'beforeUpdate'  => (string) ($row['更新前处理模块'] ?? ''),
            'afterUpdate'   => (string) ($row['更新后处理模块'] ?? ''),
            'commentModule' => (string) ($row['备注模块'] ?? ''),
            'importModule'  => (string) ($row['导入模块'] ?? ''),
            'upkeepModule'  => (string) ($row['数据整理模块'] ?? ''),
            'chartModule'   => (string) ($row['图形模块'] ?? ''),
            'gridStyle'     => (string) (($row['表样式'] ?? '') === '' ? '表样式_A' : $row['表样式']),
        ];

        if ($logger !== null) {
            $logger('[loadQueryConfig] 完成', 'debug');
        }

        return $config;
    }

    /**
     * 构建精确匹配条件（field='val1' or field='val2'）
     *
     * @param string $field       字段名
     * @param string $resolvedAuth 逗号分隔的授权值
     * @return string SQL 条件表达式，无数据返回空字符串
     */
    public function buildExactMatchCondition(string $field, string $resolvedAuth): string
    {
        if ($field === '' || $resolvedAuth === '') {
            return '';
        }

        $values = $this->split($resolvedAuth);
        if (empty($values)) {
            return '';
        }

        $clauses = array_map(
            fn(string $value): string => sprintf('%s=%s', $field, $this->model->quote($value)),
            $values
        );

        return implode(' or ', $clauses);
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
