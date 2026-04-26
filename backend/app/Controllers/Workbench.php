<?php

namespace App\Controllers;

use App\Constants\ApiCode;
use App\Models\Mcommon;

class Workbench extends BaseController
{
    private Mcommon $common;

    public function __construct()
    {
        $this->common = new Mcommon();
    }

    public function page(string $functionCode = '')
    {
        try {
            $payload = [
                'current' => 1,
                'size' => 20,
                'filters' => []
            ];

            [$context, $definition] = $this->buildWorkbenchContext($functionCode);
            $records = $this->queryRecords($context, $payload);

            return $this->success([
                'meta' => $definition,
                'records' => $records['records'],
                'current' => $records['current'],
                'size' => $records['size'],
                'total' => $records['total']
            ]);
        } catch (\RuntimeException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->error('5001', '工作台初始化失败');
        }
    }

    public function query(string $functionCode = '')
    {
        try {
            $payload = $this->request->getJSON(true) ?? [];

            [$context] = $this->buildWorkbenchContext($functionCode);
            $records = $this->queryRecords($context, $payload);

            return $this->success($records);
        } catch (\RuntimeException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->error('5002', '工作台查询失败');
        }
    }

    private function buildWorkbenchContext(string $functionCode): array
    {
        $functionCode = trim($functionCode);
        if ($functionCode === '') {
            throw new \RuntimeException('功能编码不能为空');
        }

        $session = \Config\Services::session();
        $companyId = trim((string) $session->get('company_id'));
        $userWorkId = trim((string) $session->get('user_workid'));
        $userPassword = (string) $session->get('user_pswd');

        if ($companyId === '' || $userWorkId === '') {
            throw new \RuntimeException('登录态已失效，请重新登录');
        }

        $userAuth = $this->loadUserAuthorization($companyId, $userWorkId, $userPassword);
        $functionAuth = $this->loadFunctionAuthorization($functionCode, $userAuth);
        $queryConfig = $this->loadQueryConfig($functionCode, $userAuth['roleCodesRaw']);
        $columns = $this->loadColumns($functionCode);

        if (!$queryConfig) {
            throw new \RuntimeException('功能未配置查询模块');
        }

        if (!$columns) {
            throw new \RuntimeException('功能未配置列信息');
        }

        $columnDefinitions = $this->buildColumnDefinitions($columns);
        
        // 调试：检查返回的列定义
        $colorMarkCount = count(array_filter($columnDefinitions, fn($col) => $col['colorMarkEnabled'] ?? false));
        error_log("buildWorkbenchContext: Total columns: " . count($columnDefinitions) . ", Color mark enabled: {$colorMarkCount}");
        
        $definition = [
            'functionCode' => $functionCode,
            'title' => $functionAuth['menu2'],
            'menu1' => $functionAuth['menu1'],
            'menu2' => $functionAuth['menu2'],
            'module' => $functionAuth['module'],
            'params' => $functionAuth['params'],
            'mode' => $queryConfig['mode'],
            'queryModule' => $queryConfig['queryModule'],
            'fieldModule' => $queryConfig['fieldModule'],
            'toolbar' => [
                'comment' => $functionAuth['commentAuth'],
                'add' => $functionAuth['addAuth'],
                'edit' => $functionAuth['modifyAuth'],
                'delete' => $functionAuth['deleteAuth'],
                'import' => $functionAuth['importAuth'] && $queryConfig['importModule'] !== '',
                'export' => $functionAuth['exportAuth'],
                'tableEdit' => $functionAuth['tableAuth'],
                'debugSql' => $userAuth['debugAuth'],
                'upkeep' => $functionAuth['upkeepAuth'] && $queryConfig['upkeepModule'] !== ''
            ],
            'conditions' => $this->buildConditionDefinitions($columns),
            'columns' => $columnDefinitions,
            'supportsStoredProcedure' => $queryConfig['mode'] === '存储过程',
            'fallbackHint' => $queryConfig['mode'] === '存储过程'
                ? '当前功能为存储过程模式，Vue 工作台暂未接管执行链路，请先走旧页回退。'
                : ''
        ];

        $context = [
            'user' => $userAuth,
            'function' => $functionAuth,
            'query' => $queryConfig,
            'columns' => $columns
        ];

        return [$context, $definition];
    }

    private function loadUserAuthorization(string $companyId, string $userWorkId, string $userPassword): array
    {
        $sql = sprintf(
            'select 
                员工编号,姓名,工号,t1.角色组,
                case
                    when t1.角色组!="" and t1.角色编码="" and t2.角色组 is not null then t2.角色编码
                    when t1.角色组!="" and t1.角色编码!="" and t2.角色组 is not null then concat(t2.角色编码,",",t1.角色编码)
                    else t1.角色编码
                end as 角色编码,
                属地赋权,部门编码赋权,部门全称赋权,
                工号限权,调试赋权,维护赋权,
                员工属地,员工部门编码,员工部门全称
            from
            (
                select 
                    员工编号,姓名,工号,
                    角色组,replace(replace(角色编码,"，",",")," ","") as 角色编码,
                    replace(replace(属地赋权,"，",",")," ","") as 属地赋权,
                    replace(replace(部门编码赋权,"，",",")," ","") as 部门编码赋权,
                    replace(replace(部门全称赋权,"，",",")," ","") as 部门全称赋权,
                    工号限权,调试赋权,维护赋权,
                    员工属地,员工部门编码,员工部门全称
                from def_user
                where 有效标识="1" and 员工属地=%s and 工号=%s
                group by 员工属地,工号
            ) as t1
            left join
            (
                select 角色组,replace(replace(角色编码,"，",",")," ","") as 角色编码
                from def_role_group
                where 有效标识="1"
            ) as t2 on t1.角色组=t2.角色组',
            $this->quote($companyId),
            $this->quote($userWorkId)
        );

        $row = $this->common->select($sql)->getRowArray();
        if (!$row) {
            throw new \RuntimeException('用户权限信息不存在');
        }

        $roleCodes = $this->splitCsv((string) ($row['角色编码'] ?? ''));

        return [
            'companyId' => $companyId,
            'userWorkId' => $userWorkId,
            'userPassword' => $userPassword,
            'roleCodes' => $roleCodes,
            'roleCodesQuoted' => $this->quoteList($roleCodes),
            'roleCodesRaw' => (string) ($row['角色编码'] ?? ''),
            'locationAuth' => (string) (($row['属地赋权'] ?? '') === '' ? $companyId : $row['属地赋权']),
            'deptCodeAuth' => $this->splitCsv((string) ($row['部门编码赋权'] ?? '')),
            'deptNameAuth' => $this->splitCsv((string) ($row['部门全称赋权'] ?? '')),
            'workIdAuth' => (string) ($row['工号限权'] ?? '0'),
            'debugAuth' => ($userPassword === $userWorkId . $userWorkId) || (string) ($row['调试赋权'] ?? '0') === '1',
            'upkeepAuth' => ($userPassword === $userWorkId . $userWorkId) || (string) ($row['维护赋权'] ?? '0') === '1'
        ];
    }

    private function loadFunctionAuthorization(string $functionCode, array $userAuth): array
    {
        if ($userAuth['roleCodesQuoted'] === '') {
            throw new \RuntimeException('用户未配置角色');
        }

        $sql = sprintf(
            'select 
                t1.功能赋权,
                t1.备注授权,t1.新增授权,t1.修改授权,t1.删除授权,
                t1.维护授权,t1.整表授权,
                t1.导入授权,t1.导出授权,t1.工号限权,
                ifnull(t2.一级菜单,"") as 一级菜单,
                ifnull(t2.二级菜单,"") as 二级菜单,
                ifnull(t2.功能模块,"") as 功能模块,
                ifnull(t2.参数,"") as 参数,
                ifnull(t3.部门编码字段,"") as 部门编码字段,
                ifnull(t3.部门全称字段,"") as 部门全称字段,
                ifnull(t3.属地字段,"") as 属地字段
            from 
            (
                select 功能编码赋权 as 功能赋权,
                    max(备注授权) as 备注授权,
                    max(新增授权) as 新增授权,
                    max(修改授权) as 修改授权,
                    max(删除授权) as 删除授权,
                    max(维护授权) as 维护授权,
                    max(整表授权) as 整表授权,
                    max(导入授权) as 导入授权,
                    max(导出授权) as 导出授权,
                    min(工号限权) as 工号限权
                from view_role
                where 有效标识="1" and 角色编码 in (%s) and 功能编码赋权=%s
                group by 功能编码赋权
            ) as t1
            left join def_function as t2 on t1.功能赋权=t2.功能编码
            left join def_query_config as t3 on if(t2.功能类型="查询", t2.模块名称, "")=t3.查询模块',
            $userAuth['roleCodesQuoted'],
            $this->quote($functionCode)
        );

        $row = $this->common->select($sql)->getRowArray();
        if (!$row) {
            throw new \RuntimeException('当前账号无该功能访问权限');
        }

        $deptCodeAuth = $this->buildFunctionDeptCodeAuth($functionCode, $row, $userAuth);
        $deptNameAuth = $this->buildFunctionDeptNameAuth($functionCode, $row, $userAuth);
        $locationAuth = $this->buildFunctionLocationAuth($functionCode, $row, $userAuth, $deptCodeAuth, $deptNameAuth);

        $deptAuthCond = '';
        if ($deptCodeAuth !== '' && $deptNameAuth !== '') {
            $deptAuthCond = sprintf('(%s or %s)', $deptCodeAuth, $deptNameAuth);
        } elseif ($deptCodeAuth !== '') {
            $deptAuthCond = sprintf('(%s)', $deptCodeAuth);
        } elseif ($deptNameAuth !== '') {
            $deptAuthCond = sprintf('(%s)', $deptNameAuth);
        }

        if ($locationAuth !== '') {
            $locationAuth = sprintf('(%s)', $locationAuth);
        }

        return [
            'menu1' => (string) ($row['一级菜单'] ?? ''),
            'menu2' => (string) ($row['二级菜单'] ?? ''),
            'module' => (string) ($row['功能模块'] ?? ''),
            'params' => (string) ($row['参数'] ?? ''),
            'commentAuth' => (string) ($row['备注授权'] ?? '0') === '1',
            'addAuth' => (string) ($row['新增授权'] ?? '0') === '1',
            'modifyAuth' => in_array((string) ($row['修改授权'] ?? '0'), ['1', '2'], true),
            'deleteAuth' => (string) ($row['删除授权'] ?? '0') === '1',
            'upkeepAuth' => $userAuth['upkeepAuth'] || (string) ($row['维护授权'] ?? '0') === '1',
            'tableAuth' => (string) ($row['整表授权'] ?? '0') === '1',
            'importAuth' => (string) ($row['导入授权'] ?? '0') === '1',
            'exportAuth' => (string) ($row['导出授权'] ?? '0') === '1',
            'workIdAuth' => $userAuth['workIdAuth'] !== '0' ? $userAuth['workIdAuth'] : (string) ($row['工号限权'] ?? '0'),
            'deptAuthCond' => $deptAuthCond,
            'locationAuthCond' => $locationAuth
        ];
    }

    private function loadQueryConfig(string $functionCode, string $userRole): array
    {
        $sql = sprintf(
            'select 
                查询模块,模块类型,字段模块,钻取模块,
                查询表名,数据表名,数据模式,
                查询条件,汇总条件,排序条件,初始条数,
                数据整理模块,备注模块,导入模块,图形模块,表样式
            from def_query_config
            where 查询模块 in 
                (
                    select 模块名称 
                    from def_function
                    where 有效标识="1" and 功能编码=%s
                )',
            $this->quote($functionCode)
        );

        $row = $this->common->select($sql)->getRowArray();
        if (!$row) {
            return [];
        }

        $queryWhere = (string) ($row['查询条件'] ?? '');
        if ($queryWhere !== '' && strpos($queryWhere, '$角色') !== false) {
            $queryWhere = str_replace('$角色', $userRole, $queryWhere);
        }

        return [
            'queryModule' => (string) ($row['查询模块'] ?? ''),
            'drillModule' => (string) ($row['钻取模块'] ?? ''),
            'mode' => (string) ($row['模块类型'] ?? '数据查询'),
            'fieldModule' => (string) ($row['字段模块'] ?? ''),
            'queryTable' => (string) ($row['查询表名'] ?? ''),
            'dataTable' => (string) ($row['数据表名'] ?? ''),
            'dataModel' => (string) ($row['数据模式'] ?? ''),
            'queryWhere' => $queryWhere,
            'queryGroup' => (string) ($row['汇总条件'] ?? ''),
            'queryOrder' => (string) ($row['排序条件'] ?? ''),
            'resultCount' => (int) ($row['初始条数'] ?? 0),
            'commentModule' => (string) ($row['备注模块'] ?? ''),
            'importModule' => (string) ($row['导入模块'] ?? ''),
            'upkeepModule' => (string) ($row['数据整理模块'] ?? ''),
            'chartModule' => (string) ($row['图形模块'] ?? ''),
            'gridStyle' => (string) (($row['表样式'] ?? '') === '' ? '表样式_A' : $row['表样式'])
        ];
    }

    private function loadColumns(string $functionCode): array
    {
        $sql = sprintf(
            'select 功能编码,字段模块,部门编码字段,部门全称字段,
                工号字段,属地字段,
                列名,列类型,列宽度,字段名,查询名,
                赋值类型,对象,对象名称,对象表名,缺省值,主键,
                工号限权,可筛选,可汇总,可新增,可修改,不可为空,可颜色标注,
                提示条件,提示样式设置,异常条件,异常样式设置,字符转换,
                加密显示,列顺序
            from view_function
            where 功能编码=%s and 列顺序>0
            group by 列名
            order by 列顺序',
            $this->quote($functionCode)
        );

        return $this->common->select($sql)->getResultArray();
    }

    private function buildConditionDefinitions(array $columns): array
    {
        $conditions = [];

        foreach ($columns as $column) {
            $conditions[] = [
                'label' => (string) ($column['列名'] ?? ''),
                'fieldKey' => (string) ($column['列名'] ?? ''),
                'fieldName' => (string) ($column['字段名'] ?? ''),
                'queryName' => (string) ($column['查询名'] ?? ''),
                'type' => (string) ($column['列类型'] ?? '字符'),
                'required' => (string) ($column['不可为空'] ?? '0') === '1',
                'filterable' => true
            ];
        }

        return $conditions;
    }

    private function buildColumnDefinitions(array $columns): array
    {
        $items = [[
            'field' => '序号',
            'title' => '序号',
            'type' => '数值',
            'width' => 90,
            'hidden' => false,
            'editable' => false,
            'required' => false,
            'sortable' => true
        ]];

        foreach ($columns as $column) {
            $title = (string) ($column['列名'] ?? '');
            // 调试：检查可颜色标注字段
            $colorMarkValue = $column['可颜色标注'] ?? 'not_set';
            error_log("Column: {$title}, 可颜色标注: {$colorMarkValue}");
            $items[] = [
                'field' => $title,
                'title' => $title,
                'type' => (string) ($column['列类型'] ?? '字符'),
                'width' => (int) (($column['列宽度'] ?? 0) > 0 ? $column['列宽度'] : max(strlen($title) * 16, 120)),
                'hidden' => (string) ($column['主键'] ?? '0') === '1',
                'editable' => in_array((string) ($column['可修改'] ?? '0'), ['1', '2'], true),
                'required' => (string) ($column['不可为空'] ?? '0') === '1',
                'sortable' => true,
                // 提示和异常显示相关配置
                'hintCondition' => (string) ($column['提示条件'] ?? ''),
                'hintStyle' => (string) ($column['提示样式设置'] ?? ''),
                'errorCondition' => (string) ($column['异常条件'] ?? ''),
                'errorStyle' => (string) ($column['异常样式设置'] ?? ''),
                // 颜色标注相关配置
                'colorMarkEnabled' => (string) ($column['可颜色标注'] ?? '0') === '1'
            ];
        }

        return $items;
    }

    private function queryRecords(array $context, array $payload): array
    {
        $queryConfig = $context['query'];
        $functionAuth = $context['function'];
        $userAuth = $context['user'];
        $columns = $context['columns'];

        $fetchAll = filter_var($payload['all'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $current = max(1, (int) ($payload['current'] ?? 1));
        $size = max(1, min(200, (int) ($payload['size'] ?? 20)));

        if ($fetchAll) {
            $current = 1;
        }

        if ($queryConfig['mode'] === '存储过程') {
            return [
                'records' => [],
                'current' => $current,
                'size' => $size,
                'total' => 0
            ];
        }

        $columnMap = [];
        foreach ($columns as $column) {
            $columnMap[(string) ($column['列名'] ?? '')] = $column;
        }

        $selectParts = [];
        $hintErrorParts = []; // 提示和异常标记字段
        foreach ($columns as $column) {
            $alias = (string) ($column['列名'] ?? '');
            $queryName = (string) ($column['查询名'] ?? '');
            if ($alias === '' || $queryName === '') {
                continue;
            }

            if ((string) ($column['字符转换'] ?? '0') === '1') {
                $selectParts[] = sprintf("replace(replace(%s, '\"', '~~'), '\'', '~~') as `%s`", $queryName, $alias);
            } elseif ((string) ($column['加密显示'] ?? '0') === '1') {
                $selectParts[] = sprintf('"*" as `%s`', $alias);
            } elseif ((string) ($column['工号限权'] ?? '0') !== '0' && $functionAuth['workIdAuth'] !== '0' && (string) ($column['工号字段'] ?? '') !== '') {
                $selectParts[] = sprintf(
                    'if(%s=%s,%s,"-") as `%s`',
                    $column['工号字段'],
                    $this->quote($userAuth['userWorkId']),
                    $queryName,
                    $alias
                );
            } else {
                $selectParts[] = sprintf('%s as `%s`', $queryName, $alias);
            }

            // 添加提示和异常标记字段
            $hintCondition = trim((string) ($column['提示条件'] ?? ''));
            $errorCondition = trim((string) ($column['异常条件'] ?? ''));
            if ($hintCondition !== '') {
                $hintErrorParts[] = sprintf('if(%s,"1","0") as `提示^%s`', $hintCondition, $alias);
            }
            if ($errorCondition !== '') {
                $hintErrorParts[] = sprintf('if(%s,"1","0") as `异常^%s`', $errorCondition, $alias);
            }
        }

        // 合并提示和异常字段到 selectParts
        if (!empty($hintErrorParts)) {
            $selectParts = array_merge($selectParts, $hintErrorParts);
        }

        $whereParts = [];
        if ($queryConfig['queryWhere'] !== '') {
            $whereParts[] = $queryConfig['queryWhere'];
        }
        if ($functionAuth['deptAuthCond'] !== '') {
            $whereParts[] = $functionAuth['deptAuthCond'];
        }
        if ($functionAuth['locationAuthCond'] !== '') {
            $whereParts[] = $functionAuth['locationAuthCond'];
        }

        $filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];
        foreach ($filters as $filter) {
            if (!is_array($filter)) {
                continue;
            }
            $fieldKey = trim((string) ($filter['fieldKey'] ?? ''));
            $operator = trim((string) ($filter['operator'] ?? 'contains'));
            $value = trim((string) ($filter['value'] ?? ''));
            if ($fieldKey === '' || $value === '' || !isset($columnMap[$fieldKey])) {
                continue;
            }

            $fieldName = trim((string) ($columnMap[$fieldKey]['字段名'] ?? ''));
            if ($fieldName === '') {
                continue;
            }

            switch ($operator) {
                case 'equals':
                    $whereParts[] = sprintf('%s=%s', $fieldName, $this->quote($value));
                    break;
                case 'startsWith':
                    $whereParts[] = sprintf('%s like %s', $fieldName, $this->quote($value . '%'));
                    break;
                default:
                    $whereParts[] = sprintf('%s like %s', $fieldName, $this->quote('%' . $value . '%'));
                    break;
            }
        }

        // 处理钻取条件 SQL
        $drillCondition = trim((string) ($payload['drillCondition'] ?? ''));
        if ($drillCondition !== '') {
            $whereParts[] = $drillCondition;
        }

        $baseFromSql = sprintf(' from %s', $queryConfig['queryTable']);
        $whereSql = $whereParts ? ' where ' . implode(' and ', $whereParts) : '';
        $groupSql = $queryConfig['queryGroup'] !== '' ? ' group by ' . $queryConfig['queryGroup'] : '';
        $orderSql = $queryConfig['queryOrder'] !== '' ? ' order by ' . $queryConfig['queryOrder'] : '';
        $offset = ($current - 1) * $size;
        $total = 0;

        if ($fetchAll) {
            $querySql = sprintf(
                'select (@i:=@i+1) as 序号, %s%s, (select @i:=0) as xh%s%s%s',
                implode(',', $selectParts),
                $baseFromSql,
                $whereSql,
                $groupSql,
                $orderSql
            );
        } else {
            $countSql = sprintf('select count(1) as total from (select 1%s%s%s) as total_rows', $baseFromSql, $whereSql, $groupSql);
            $totalRow = $this->common->select($countSql)->getRowArray();
            $total = (int) ($totalRow['total'] ?? 0);

            $querySql = sprintf(
                'select (@i:=@i+1) as 序号, %s%s, (select @i:=%d) as xh%s%s%s limit %d offset %d',
                implode(',', $selectParts),
                $baseFromSql,
                $offset,
                $whereSql,
                $groupSql,
                $orderSql,
                $size,
                $offset
            );
        }

        // Debug: log the SQL query
        log_message('debug', 'Workbench query SQL: ' . $querySql);

        $rows = $this->common->select($querySql)->getResultArray();
        foreach ($rows as &$row) {
            if (isset($row['序号'])) {
                $row['序号'] = (int) $row['序号'];
            }
            foreach ($columns as $column) {
                $title = (string) ($column['列名'] ?? '');
                if ($title !== '' && array_key_exists($title, $row) && (string) ($column['列类型'] ?? '') === '数值' && is_numeric($row[$title])) {
                    $row[$title] = strpos((string) $row[$title], '.') === false ? (int) $row[$title] : (float) $row[$title];
                }
            }
        }

        if ($fetchAll) {
            $total = count($rows);
            $size = $total > 0 ? $total : $size;
        }

        return [
            'records' => $rows,
            'current' => $current,
            'size' => $size,
            'total' => $total
        ];
    }

    private function buildFunctionDeptCodeAuth(string $functionCode, array $functionRow, array $userAuth): string
    {
        $field = (string) ($functionRow['部门编码字段'] ?? '');
        if ($field === '' || $userAuth['roleCodesQuoted'] === '') {
            return '';
        }

        $sql = sprintf(
            'select substring_index(substring_index(编码赋权,",",t2.GUID+1),",",-1) as 部门编码赋权
            from
            (
                select GUID,replace(replace(部门编码赋权,"，",",")," ","") as 编码赋权
                from view_role
                where 有效标识="1" and 角色编码 in (%s) and 功能编码赋权=%s
            ) as t1
            inner join def_GUID as t2 on t2.GUID<(length(编码赋权)-length(replace(编码赋权,",",""))+1)
            group by 部门编码赋权
            order by 部门编码赋权',
            $userAuth['roleCodesQuoted'],
            $this->quote($functionCode)
        );

        $results = $this->common->select($sql)->getResultArray();
        $clauses = [];
        foreach ($results as $row) {
            $value = trim((string) ($row['部门编码赋权'] ?? ''));
            if ($value !== '') {
                $clauses[] = sprintf('left(%s,length("%s"))="%s"', $field, $value, $value);
            }
        }

        if ($clauses) {
            $expr = implode(' or ', $clauses);
            return $userAuth['upkeepAuth'] ? sprintf('%s or %s=""', $expr, $field) : $expr;
        }

        if ($userAuth['deptCodeAuth']) {
            return sprintf('instr(%s,%s)', $field, $this->quoteList($userAuth['deptCodeAuth']));
        }

        return '';
    }

    private function buildFunctionDeptNameAuth(string $functionCode, array $functionRow, array $userAuth): string
    {
        $field = (string) ($functionRow['部门全称字段'] ?? '');
        if ($field === '' || $userAuth['roleCodesQuoted'] === '') {
            return '';
        }

        $sql = sprintf(
            'select substring_index(substring_index(全称赋权,",",t2.GUID+1),",",-1) as 部门全称赋权
            from
            (
                select GUID,replace(replace(部门全称赋权,"，",",")," ","") as 全称赋权
                from view_role
                where 有效标识="1" and 角色编码 in (%s) and 功能编码赋权=%s
            ) as t1
            inner join def_GUID as t2 on t2.GUID<(length(全称赋权)-length(replace(全称赋权,",",""))+1)
            group by 部门全称赋权
            order by 部门全称赋权',
            $userAuth['roleCodesQuoted'],
            $this->quote($functionCode)
        );

        $results = $this->common->select($sql)->getResultArray();
        $clauses = [];
        foreach ($results as $row) {
            $value = trim((string) ($row['部门全称赋权'] ?? ''));
            if ($value !== '') {
                $clauses[] = sprintf('instr(%s,"%s")', $field, $value);
            }
        }

        if ($clauses) {
            $expr = implode(' or ', $clauses);
            return $userAuth['upkeepAuth'] ? sprintf('%s or %s=""', $expr, $field) : $expr;
        }

        if ($userAuth['deptNameAuth']) {
            return sprintf('instr(%s,%s)', $field, $this->quoteList($userAuth['deptNameAuth']));
        }

        return '';
    }

    private function buildFunctionLocationAuth(string $functionCode, array $functionRow, array $userAuth, string $deptCodeAuth, string $deptNameAuth): string
    {
        $field = (string) ($functionRow['属地字段'] ?? '');
        if ($field === '' || $userAuth['roleCodesQuoted'] === '') {
            return '';
        }

        $sql = sprintf(
            'select substring_index(substring_index(角色表属地,",",t2.GUID+1),",",-1) as 属地赋权
            from
            (
                select GUID,replace(replace(属地赋权,"，",",")," ","") as 角色表属地
                from view_role
                where 有效标识="1" and 角色编码 in (%s) and 功能编码赋权=%s
            ) as t1
            inner join def_GUID as t2 on t2.GUID<(length(角色表属地)-length(replace(角色表属地,",",""))+1)
            group by 属地赋权
            order by 属地赋权',
            $userAuth['roleCodesQuoted'],
            $this->quote($functionCode)
        );

        $results = $this->common->select($sql)->getResultArray();
        $clauses = [];
        foreach ($results as $row) {
            $value = trim((string) ($row['属地赋权'] ?? ''));
            if ($value !== '') {
                $clauses[] = sprintf('instr(%s,"%s")', $field, $value);
            }
        }

        if ($clauses) {
            $expr = implode(' or ', $clauses);
            return $userAuth['upkeepAuth'] ? sprintf('%s or %s=""', $expr, $field) : $expr;
        }

        if ($deptCodeAuth !== '' || $deptNameAuth !== '') {
            return '';
        }

        if ($userAuth['locationAuth'] !== '') {
            return sprintf('locate(%s,%s)>0', $field, $this->quote($userAuth['locationAuth']));
        }

        return '';
    }

    private function splitCsv(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', str_replace('，', ',', $value)));
        $parts = array_filter($parts, static fn(string $item): bool => $item !== '');

        return array_values(array_unique($parts));
    }

    private function quote(string $value): string
    {
        return sprintf("'%s'", str_replace(["\\", "'"], ["\\\\", "\\'"], $value));
    }

    private function quoteList(array $items): string
    {
        $quoted = [];
        foreach ($items as $item) {
            $value = trim((string) $item);
            if ($value !== '') {
                $quoted[] = $this->quote($value);
            }
        }

        return implode(',', array_values(array_unique($quoted)));
    }

    private function success(array $data)
    {
        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg' => 'success',
            'data' => $data
        ]);
    }

    private function error(string $code, string $message)
    {
        return $this->response->setJSON([
            'code' => $code,
            'msg' => $message
        ]);
    }

    public function drill(string $functionCode = '')
    {
        try {
            $payload = $this->request->getJSON(true) ?? [];

            [$context] = $this->buildWorkbenchContext($functionCode);
            
            $drillModule = $context['query']['drillModule'] ?? '';
            $queryModule = $context['query']['queryModule'] ?? '';
            
            // Debug info
            $debugInfo = [
                'functionCode' => $functionCode,
                'queryModule' => $queryModule,
                'drillModule' => $drillModule,
                'queryConfig' => $context['query'],
                'userAuthCount' => count($context['user'] ?? [])
            ];
            
            // 如果钻取模块为空，使用查询模块
            if (empty($drillModule)) {
                $drillModule = $queryModule;
                $debugInfo['drillModuleFallback'] = 'used queryModule as drillModule';
            }
            
            $drillOptions = $this->getDrillOptions($context, $payload, $drillModule);

            return $this->success([
                'options' => $drillOptions,
                'debug' => $debugInfo
            ]);
        } catch (\RuntimeException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->error('5003', '工作台钻取失败: ' . $e->getMessage());
        }
    }

    private function getDrillOptions(array $context, array $payload, string $drillModule): array
    {
        $functionAuth = $context['function'];
        $userAuth = $context['user'];

        if (empty($drillModule)) {
            return [];
        }

        $sql = sprintf(
            'select
                钻取模块,页面选项,t1.功能编码,钻取字段,钻取条件,
                if(t2.二级菜单 is null,"",if(t1.标签副名称="",t2.二级菜单,concat(t2.二级菜单,"-",t1.标签副名称))) as 标签名称,
                t2.功能模块,
                ifnull(t2.一级菜单,"") as menu1,
                ifnull(t2.二级菜单,"") as menu2
            from def_drill_config as t1
            left join def_function as t2 on t1.功能编码=t2.功能编码
            where 钻取模块=%s
            order by 顺序,convert(页面选项 using gbk)',
            $this->quote($drillModule)
        );

        $results = $this->common->select($sql)->getResultArray();
        $options = [];

        foreach ($results as $row) {
            $functionCode = (string) ($row['功能编码'] ?? '');
            if (empty($functionCode)) {
                continue;
            }

            $options[] = [
                'label' => (string) ($row['页面选项'] ?? $row['标签名称'] ?? ''),
                'value' => $functionCode,
                'functionCode' => $functionCode,
                'module' => (string) ($row['功能模块'] ?? ''),
                'drillFields' => (string) ($row['钻取字段'] ?? ''),
                'drillCondition' => (string) ($row['钻取条件'] ?? ''),
                'menu1' => (string) ($row['menu1'] ?? ''),
                'menu2' => (string) ($row['menu2'] ?? '')
            ];
        }

        return $options;
    }
}
