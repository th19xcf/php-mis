<?php

namespace App\Services\Workbench;

use App\Libraries\AuthorizationService;
use App\Libraries\SessionUserContext;
use App\Models\Mcommon;

/**
 * 上下文服务类
 * 负责构建工作台上下文信息（用户授权、功能授权、查询配置等）
 */
class ContextService
{
    private Mcommon $model;
    private AuthorizationService $authorizationService;
    private SessionUserContext $userContext;

    public function __construct()
    {
        $this->model = new Mcommon();
        $this->authorizationService = new AuthorizationService();
        $this->userContext = new SessionUserContext();
    }

    /**
     * 构建工作台上下文
     *
     * @param string $functionCode 功能编码
     * @return array [context, definition]
     */
    public function buildWorkbenchContext(string $functionCode): array
    {
        $functionCode = trim($functionCode);
        if ($functionCode === '') {
            throw new \RuntimeException('功能编码不能为空');
        }

        log_message('debug', '[ContextService] 步骤1: requireLogin');
        $user = $this->userContext->requireLogin();
        $companyId = $user['companyId'];
        $userWorkId = $user['workId'];
        $userPassword = $user['password'];
        log_message('debug', '[ContextService] 步骤1完成: companyId=' . $companyId . ', userWorkId=' . $userWorkId);

        log_message('debug', '[ContextService] 步骤2: loadUserAuthorization');
        $userAuth = $this->loadUserAuthorization($companyId, $userWorkId, $userPassword);
        log_message('debug', '[ContextService] 步骤2完成');

        log_message('debug', '[ContextService] 步骤3: loadFunctionAuthorization');
        $functionAuth = $this->loadFunctionAuthorization($functionCode, $userAuth);
        log_message('debug', '[ContextService] 步骤3完成');

        log_message('debug', '[ContextService] 步骤4: loadQueryConfig');
        $queryConfig = $this->loadQueryConfig($functionCode, $userAuth['roleCodesRaw']);
        log_message('debug', '[ContextService] 步骤4完成');

        log_message('debug', '[ContextService] 步骤5: loadColumns');
        $columns = $this->loadColumns($functionCode);
        log_message('debug', '[ContextService] 步骤5完成: columns count=' . count($columns));

        if (!$queryConfig) {
            throw new \RuntimeException('功能未配置查询模块');
        }

        if (!$columns) {
            throw new \RuntimeException('功能未配置列信息');
        }

        $columnDefinitions = $this->buildColumnDefinitions($columns);

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
            'commentModule' => $queryConfig['commentModule'],
            'chartModule' => $queryConfig['chartModule'],
            'toolbar' => [
                'comment' => $functionAuth['commentAuth'],
                'add' => $functionAuth['addAuth'],
                'edit' => $functionAuth['modifyAuth'],
                'batchEdit' => $functionAuth['modifyAuth'],
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
            'columns' => $columns,
            'locationAuthzCond' => $functionAuth['locationAuthCond'],
            'deptAuthzCond' => $functionAuth['deptAuthCond'],
            'queryTable' => $queryConfig['queryTable']
        ];

        return [$context, $definition];
    }

    /**
     * 加载用户授权信息
     */
    private function loadUserAuthorization(string $companyId, string $userWorkId, string $userPassword): array
    {
        log_message('debug', '[loadUserAuthorization] 开始执行 SQL 查询');
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

        $row = $this->model->select($sql)->getRowArray();
        if (!$row) {
            throw new \RuntimeException('用户权限信息不存在');
        }

        $roleCodes = $this->splitCsv((string) ($row['角色编码'] ?? ''));

        $result = [
            'companyId' => $companyId,
            'userWorkId' => $userWorkId,
            'userPassword' => $userPassword,
            'roleCodes' => $roleCodes,
            'roleCodesQuoted' => $this->quoteList($roleCodes),
            'roleCodesRaw' => (string) ($row['角色编码'] ?? ''),
            'locationAuth' => $this->authorizationService->normalize((string) ($row['属地赋权'] ?? '')),
            'employeeRegion' => (string) ($row['员工属地'] ?? $companyId),
            'deptCodeAuth' => $this->splitCsv((string) ($row['部门编码赋权'] ?? '')),
            'deptNameAuth' => $this->authorizationService->normalize((string) ($row['部门全称赋权'] ?? '')),
            'employeeDeptName' => (string) ($row['员工部门全称'] ?? ''),
            'workIdAuth' => (string) ($row['工号限权'] ?? '0'),
            'debugAuth' => ($userPassword === $userWorkId . $userWorkId) || (string) ($row['调试赋权'] ?? '0') === '1',
            'upkeepAuth' => ($userPassword === $userWorkId . $userWorkId) || (string) ($row['维护赋权'] ?? '0') === '1'
        ];
        log_message('debug', '[loadUserAuthorization] 完成, roleCodes count=' . count($roleCodes));
        return $result;
    }

    /**
     * 加载功能授权信息
     */
    private function loadFunctionAuthorization(string $functionCode, array $userAuth): array
    {
        if ($userAuth['roleCodesQuoted'] === '') {
            throw new \RuntimeException('用户未配置角色');
        }

        log_message('debug', '[loadFunctionAuthorization] 开始执行 SQL 查询, functionCode=' . $functionCode);
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

        $row = $this->model->select($sql)->getRowArray();
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

        $result = [
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
        log_message('debug', '[loadFunctionAuthorization] 完成');
        return $result;
    }

    /**
     * 加载查询配置
     */
    private function loadQueryConfig(string $functionCode, string $userRole): array
    {
        log_message('debug', '[loadQueryConfig] 开始执行 SQL 查询, functionCode=' . $functionCode);
        $sql = sprintf(
            'select 
                查询模块,模块类型,字段模块,钻取模块,
                查询表名,数据表名,数据模式,
                查询条件,汇总条件,排序条件,初始条数,
                新增前处理模块,新增后处理模块,
                更新前处理模块,更新后处理模块,
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

        $row = $this->model->select($sql)->getRowArray();
        if (!$row) {
            return [];
        }

        $queryWhere = (string) ($row['查询条件'] ?? '');
        if ($queryWhere !== '' && strpos($queryWhere, '$角色') !== false) {
            $queryWhere = str_replace('$角色', $userRole, $queryWhere);
        }

        $result = [
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
            'beforeInsert' => (string) ($row['新增前处理模块'] ?? ''),
            'afterInsert' => (string) ($row['新增后处理模块'] ?? ''),
            'beforeUpdate' => (string) ($row['更新前处理模块'] ?? ''),
            'afterUpdate' => (string) ($row['更新后处理模块'] ?? ''),
            'commentModule' => (string) ($row['备注模块'] ?? ''),
            'importModule' => (string) ($row['导入模块'] ?? ''),
            'upkeepModule' => (string) ($row['数据整理模块'] ?? ''),
            'chartModule' => (string) ($row['图形模块'] ?? ''),
            'gridStyle' => (string) (($row['表样式'] ?? '') === '' ? '表样式_A' : $row['表样式'])
        ];
        log_message('debug', '[loadQueryConfig] 完成');
        return $result;
    }

    /**
     * 加载列配置
     */
    private function loadColumns(string $functionCode): array
    {
        log_message('debug', '[loadColumns] 开始执行 SQL 查询, functionCode=' . $functionCode);
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

        $result = $this->model->select($sql)->getResultArray();
        log_message('debug', '[loadColumns] 完成, 返回 ' . count($result) . ' 行');
        return $result;
    }

    /**
     * 构建条件定义
     */
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

    /**
     * 构建列定义
     */
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
            $items[] = [
                'field' => $title,
                'title' => $title,
                'type' => (string) ($column['列类型'] ?? '字符'),
                'width' => (int) (($column['列宽度'] ?? 0) > 0 ? $column['列宽度'] : max(strlen($title) * 16, 120)),
                'hidden' => false,
                'editable' => in_array((string) ($column['可修改'] ?? '0'), ['1', '2'], true),
                'required' => (string) ($column['不可为空'] ?? '0') === '1',
                'sortable' => true,
                'hintCondition' => (string) ($column['提示条件'] ?? ''),
                'hintStyle' => (string) ($column['提示样式设置'] ?? ''),
                'errorCondition' => (string) ($column['异常条件'] ?? ''),
                'errorStyle' => (string) ($column['异常样式设置'] ?? '')
            ];
        }

        return $items;
    }

    /**
     * 构建功能部门编码授权
     */
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

        $result = $this->model->select($sql);
        if ($result === false) {
            return '';
        }

        $deptCodes = array_column($result->getResultArray(), '部门编码赋权');
        if (empty($deptCodes)) {
            return '';
        }

        $conditions = array_map(fn($code) => sprintf('%s="%s"', $field, addslashes($code)), $deptCodes);
        return implode(' or ', $conditions);
    }

    /**
     * 构建功能部门全称授权
     */
    private function buildFunctionDeptNameAuth(string $functionCode, array $functionRow, array $userAuth): string
    {
        $field = (string) ($functionRow['部门全称字段'] ?? '');
        if ($field === '' || $userAuth['roleCodesQuoted'] === '') {
            return '';
        }

        $roleDeptNameAuth = $this->loadRoleDeptNameAuth($functionCode, $userAuth['roleCodesQuoted']);

        $resolvedAuth = $this->authorizationService->resolveDeptName(
            $userAuth['deptNameAuth'],
            $roleDeptNameAuth,
            $userAuth['employeeDeptName']
        );

        return $this->authorizationService->buildDeptNameCondition($field, $resolvedAuth, $userAuth['upkeepAuth']);
    }

    private function loadRoleDeptNameAuth(string $functionCode, string $roleCodesQuoted): string
    {
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
            $roleCodesQuoted,
            $this->quote($functionCode)
        );

        $results = $this->model->select($sql)->getResultArray();
        $values = [];
        foreach ($results as $row) {
            $value = trim((string) ($row['部门全称赋权'] ?? ''));
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return implode(',', array_values(array_unique($values)));
    }

    /**
     * 构建功能属地授权
     */
    private function buildFunctionLocationAuth(string $functionCode, array $functionRow, array $userAuth, string $deptCodeAuth, string $deptNameAuth): string
    {
        $field = (string) ($functionRow['属地字段'] ?? '');
        if ($field === '' || $userAuth['roleCodesQuoted'] === '') {
            return '';
        }

        if ($deptCodeAuth !== '' || $deptNameAuth !== '') {
            return '';
        }

        $roleLocationAuth = $this->loadRoleLocationAuth($functionCode, $userAuth['roleCodesQuoted']);

        $resolvedAuth = $this->authorizationService->resolve(
            $userAuth['locationAuth'],
            $roleLocationAuth,
            $userAuth['employeeRegion']
        );

        $this->storeLocationAuthToSession($functionCode, $resolvedAuth);

        return $this->authorizationService->buildCondition($field, $resolvedAuth, $userAuth['upkeepAuth']);
    }

    private function loadRoleLocationAuth(string $functionCode, string $roleCodesQuoted): string
    {
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
            $roleCodesQuoted,
            $this->quote($functionCode)
        );

        $results = $this->model->select($sql)->getResultArray();
        $values = [];
        foreach ($results as $row) {
            $value = trim((string) ($row['属地赋权'] ?? ''));
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return implode(',', array_values(array_unique($values)));
    }

    private function storeLocationAuthToSession(string $functionCode, string $resolvedAuth): void
    {
        $session = \Config\Services::session();
        $session->set([
            $functionCode . '-location_authz_resolved' => $resolvedAuth
        ]);
    }

    /**
     * 分割 CSV 字符串
     */
    private function splitCsv(string $value): array
    {
        if ($value === '') {
            return [];
        }
        return array_filter(array_map('trim', explode(',', $value)), fn($v) => $v !== '');
    }

    /**
     * 引用值
     */
    private function quote(string $value): string
    {
        return "'" . addslashes($value) . "'";
    }

    /**
     * 引用列表
     */
    private function quoteList(array $items): string
    {
        if (empty($items)) {
            return '';
        }
        return implode(',', array_map(fn($item) => $this->quote($item), $items));
    }

    /**
     * 执行数据整理存储过程
     *
     * @param string $dataUpkeep 存储过程名
     * @return bool 是否成功执行
     */
    public function executeUpkeep(string $dataUpkeep): bool
    {
        $model = new \App\Models\Mcommon();
        $result = $model->select(sprintf('call %s', $dataUpkeep));
        return $result !== false;
    }

    /**
     * 替换条件字符串中的工作台变量
     *
     * 支持占位符：
     *  - $属地授权 →  context['locationAuthzCond']
     *  - $部门授权 →  context['deptAuthzCond']
     *  - $查询表名 →  context['queryTable']
     *
     * @param string $condition 原始条件字符串
     * @param array $context 工作台上下文
     * @return string 替换后的条件字符串
     */
    public function replaceConditionVariables(string $condition, array $context): string
    {
        if (strpos($condition, '$属地授权') !== false) {
            $locationCond = (string) ($context['locationAuthzCond'] ?? '1=1');
            $condition = str_replace('$属地授权', $locationCond, $condition);
        }

        if (strpos($condition, '$部门授权') !== false) {
            $deptCond = (string) ($context['deptAuthzCond'] ?? '1=1');
            $condition = str_replace('$部门授权', $deptCond, $condition);
        }

        if (strpos($condition, '$查询表名') !== false) {
            $queryTable = (string) ($context['queryTable'] ?? '');
            $condition = str_replace('$查询表名', $queryTable, $condition);
        }

        return $condition;
    }
}
