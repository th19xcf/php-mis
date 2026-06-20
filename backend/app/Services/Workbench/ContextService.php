<?php

namespace App\Services\Workbench;

use App\Exceptions\AuthException;
use App\Exceptions\BusinessException;
use App\Exceptions\ValidationException;
use App\Libraries\AuthorizationService;
use App\Libraries\SessionUserContext;
use App\Models\Mcommon;
use CodeIgniter\Cache\CacheInterface;
use Config\Services;

/**
 * 上下文服务类
 *
 * 负责构建工作台上下文信息（用户授权、功能授权、查询配置等）。
 * 角色级授权查询已委托给 AuthorizationService::loadRoleAuthField，
 * 数据整理执行已迁至 Workbench 控制器，条件变量替换已迁至 ChartService。
 */
class ContextService
{
    private const CACHE_PREFIX = 'workbench_context_';
    private const CACHE_TTL_SECONDS = 300;

    private Mcommon $model;
    private AuthorizationService $authorizationService;
    private SessionUserContext $userContext;
    private CacheInterface $cache;

    public function __construct()
    {
        $this->model = new Mcommon();
        $this->authorizationService = new AuthorizationService();
        $this->userContext = new SessionUserContext();
        $this->cache = Services::cache();
    }

    /**
     * 构建工作台上下文
     *
     * @param string $functionCode 功能编码
     * @return array [context, definition]
     */
    public function buildWorkbenchContext(string $functionCode): array
    {
        $start = hrtime(true);
        $functionCode = trim($functionCode);
        if ($functionCode === '') {
            throw new ValidationException('功能编码不能为空');
        }

        log_message('debug', '[ContextService] 步骤1: requireLogin');
        $user = $this->userContext->requireLogin();
        $companyId = $user['companyId'];
        $userWorkId = $user['workId'];
        $isSuperAdmin = $user['isSuperAdmin'];
        log_message('debug', '[ContextService] 步骤1完成: companyId=' . $companyId . ', userWorkId=' . $userWorkId);

        // 缓存键基于 roleCodes + region，同角色用户共享缓存
        $cacheKey = $this->buildCacheKey($functionCode, $user['roleAuthz'], $companyId);
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached) && isset($cached['context'], $cached['definition'])) {
            $elapsed = (hrtime(true) - $start) / 1e6;
            log_message('debug', sprintf('[ContextService] 缓存命中: %s, 总耗时=%.2fms', $cacheKey, $elapsed));
            $context = $cached['context'];
            $definition = $cached['definition'];

            return [$context, $definition];
        }

        log_message('debug', '[ContextService] 缓存未命中，开始构建上下文: ' . $cacheKey);

        $t1 = hrtime(true);
        log_message('debug', '[ContextService] 步骤2: loadUserAuthorization');
        $userAuth = $this->loadUserAuthorization($companyId, $userWorkId, $isSuperAdmin);
        log_message('debug', sprintf('[ContextService] 步骤2完成: %.2fms', (hrtime(true) - $t1) / 1e6));

        $t2 = hrtime(true);
        log_message('debug', '[ContextService] 步骤3: loadFunctionAuthorization');
        $functionAuth = $this->loadFunctionAuthorization($functionCode, $userAuth);
        log_message('debug', sprintf('[ContextService] 步骤3完成: %.2fms', (hrtime(true) - $t2) / 1e6));

        $t3 = hrtime(true);
        log_message('debug', '[ContextService] 步骤4: loadQueryConfig');
        $queryConfig = $this->authorizationService->loadQueryConfig(
            $functionCode,
            $userAuth['roleCodesRaw'],
            static function (string $msg, string $level = 'debug'): void {
                log_message($level, $msg);
            }
        );
        log_message('debug', sprintf('[ContextService] 步骤4完成: %.2fms', (hrtime(true) - $t3) / 1e6));

        $t4 = hrtime(true);
        log_message('debug', '[ContextService] 步骤5: loadColumns');
        $columns = $this->loadColumns($functionCode);
        log_message('debug', sprintf('[ContextService] 步骤5完成: %.2fms, columns count=%d', (hrtime(true) - $t4) / 1e6, count($columns)));

        if (!$queryConfig) {
            throw new BusinessException('功能未配置查询模块');
        }

        if (!$columns) {
            throw new BusinessException('功能未配置列信息');
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

        $this->saveCache($cacheKey, $context, $definition);

        $elapsed = (hrtime(true) - $start) / 1e6;
        log_message('debug', sprintf('[ContextService] 上下文构建完成: %.2fms', $elapsed));

        return [$context, $definition];
    }

    /**
     * 构建缓存键
     *
     * 基于 functionCode + roleAuthz + region，同角色用户共享缓存。
     */
    private function buildCacheKey(string $functionCode, string $roleAuthz, string $region): string
    {
        return self::CACHE_PREFIX . md5($functionCode) . '_' . md5(implode('|', [$roleAuthz, $region]));
    }

    /**
     * 保存工作台上下文到缓存
     */
    private function saveCache(string $cacheKey, array $context, array $definition): void
    {
        $this->cache->save($cacheKey, [
            'context' => $context,
            'definition' => $definition,
            'cachedAt' => time(),
        ], self::CACHE_TTL_SECONDS);

        log_message('debug', '[ContextService] 上下文已缓存: ' . $cacheKey);
    }

    /**
     * 清除工作台上下文缓存
     *
     * - 三个参数均为空：清空当前缓存驱动的全部数据（慎用）。
     * - 仅提供 functionCode：清除该功能编码下所有角色/属地的缓存。
     * - 三个参数全部提供：精确删除单条缓存。
     *
     * @param string $functionCode 功能编码
     * @param string $roleAuthz    角色赋权字符串（逗号分隔）
     * @param string $region       属地/公司编码
     */
    public function clearCache(string $functionCode = '', string $roleAuthz = '', string $region = ''): void
    {
        if ($functionCode === '' && $roleAuthz === '' && $region === '') {
            $this->cache->clean();
            log_message('info', '[ContextService] 已清空全部缓存');
            return;
        }

        if ($functionCode !== '' && $roleAuthz === '' && $region === '') {
            $this->clearCacheByFunctionCode($functionCode);
            return;
        }

        $targetKey = $this->buildCacheKey($functionCode, $roleAuthz, $region);
        $this->cache->delete($targetKey);
        log_message('info', '[ContextService] 已清除工作台上下文缓存: ' . $targetKey);
    }

    /**
     * 清除指定功能编码下的所有工作台上下文缓存
     */
    public function clearCacheByFunctionCode(string $functionCode): void
    {
        $pattern = $this->buildFunctionCachePattern($functionCode);

        try {
            $this->cache->deleteMatching($pattern);
            log_message('info', '[ContextService] 已清除功能编码缓存: ' . $functionCode . ', pattern: ' . $pattern);
        } catch (\BadMethodCallException $e) {
            log_message('warning', '[ContextService] 当前缓存驱动不支持 deleteMatching，回退到全量清空');
            $this->cache->clean();
        }
    }

    private function buildFunctionCachePattern(string $functionCode): string
    {
        return self::CACHE_PREFIX . md5($functionCode) . '_*';
    }

    /**
     * 加载用户授权信息
     */
    private function loadUserAuthorization(string $companyId, string $userWorkId, bool $isSuperAdmin): array
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
            $this->model->quote($companyId),
            $this->model->quote($userWorkId)
        );

        $row = $this->model->select($sql)->getRowArray();
        if (!$row) {
            throw new AuthException('用户权限信息不存在');
        }

        $roleCodes = $this->splitCsv((string) ($row['角色编码'] ?? ''));

        $result = [
            'companyId' => $companyId,
            'userWorkId' => $userWorkId,
            'isSuperAdmin' => $isSuperAdmin,
            'roleCodes' => $roleCodes,
            'roleCodesQuoted' => $this->quoteList($roleCodes),
            'roleCodesRaw' => (string) ($row['角色编码'] ?? ''),
            'locationAuth' => $this->authorizationService->normalize((string) ($row['属地赋权'] ?? '')),
            'employeeRegion' => (string) ($row['员工属地'] ?? $companyId),
            'deptCodeAuth' => $this->splitCsv((string) ($row['部门编码赋权'] ?? '')),
            'deptNameAuth' => $this->authorizationService->normalize((string) ($row['部门全称赋权'] ?? '')),
            'employeeDeptName' => (string) ($row['员工部门全称'] ?? ''),
            'workIdAuth' => (string) ($row['工号限权'] ?? '0'),
            'debugAuth' => $isSuperAdmin || (string) ($row['调试赋权'] ?? '0') === '1',
            'upkeepAuth' => $isSuperAdmin || (string) ($row['维护赋权'] ?? '0') === '1'
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
            throw new AuthException('用户未配置角色');
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
            $this->model->quote($functionCode)
        );

        $row = $this->model->select($sql)->getRowArray();
        if (!$row) {
            throw new AuthException('当前账号无该功能访问权限');
        }

        // 一次查询获取 3 个角色级赋权字段，替代 3 次单独查询
        $roleAuthFields = $this->authorizationService->loadRoleAuthFields(
            $functionCode,
            [
                ['fieldName' => '部门编码赋权', 'aliasName' => '编码赋权'],
                ['fieldName' => '部门全称赋权', 'aliasName' => '全称赋权'],
                ['fieldName' => '属地赋权', 'aliasName' => '角色表属地'],
            ],
            $userAuth['roleCodesRaw']
        );

        $deptCodeAuth = $this->buildFunctionDeptCodeAuth($row, $userAuth, $roleAuthFields['部门编码赋权']);
        $deptNameAuth = $this->buildFunctionDeptNameAuth($functionCode, $row, $userAuth, $roleAuthFields['部门全称赋权']);
        $locationAuth = $this->buildFunctionLocationAuth($functionCode, $row, $userAuth, $deptCodeAuth, $deptNameAuth, $roleAuthFields['属地赋权']);

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
            $this->model->quote($functionCode)
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
     * 构建功能部门编码授权条件
     *
     * 使用预查询的部门编码赋权值，通过 buildExactMatchCondition 生成精确匹配 SQL 条件。
     */
    private function buildFunctionDeptCodeAuth(array $functionRow, array $userAuth, string $deptCodeAuthz): string
    {
        $field = (string) ($functionRow['部门编码字段'] ?? '');
        if ($field === '' || $userAuth['roleCodesQuoted'] === '') {
            return '';
        }

        return $this->authorizationService->buildExactMatchCondition($field, $deptCodeAuthz);
    }

    /**
     * 构建功能部门全称授权条件
     *
     * 使用预查询的部门全称赋权值，通过 resolve + buildDeptNameCondition 生成 SQL 条件。
     */
    private function buildFunctionDeptNameAuth(string $functionCode, array $functionRow, array $userAuth, string $deptNameAuthz): string
    {
        $field = (string) ($functionRow['部门全称字段'] ?? '');
        if ($field === '' || $userAuth['roleCodesQuoted'] === '') {
            return '';
        }

        $resolvedAuth = $this->authorizationService->resolveDeptName(
            $userAuth['deptNameAuth'],
            $deptNameAuthz,
            $userAuth['employeeDeptName']
        );

        return $this->authorizationService->buildDeptNameCondition($field, $resolvedAuth, $userAuth['upkeepAuth']);
    }

    /**
     * 构建功能属地授权条件
     *
     * 使用预查询的属地赋权值，通过 resolve + buildCondition 生成 SQL 条件。
     * 当部门级授权已存在时，属地授权条件为空（部门授权优先）。
     */
    private function buildFunctionLocationAuth(string $functionCode, array $functionRow, array $userAuth, string $deptCodeAuth, string $deptNameAuth, string $locationAuthz): string
    {
        $field = (string) ($functionRow['属地字段'] ?? '');
        if ($field === '' || $userAuth['roleCodesQuoted'] === '') {
            return '';
        }

        if ($deptCodeAuth !== '' || $deptNameAuth !== '') {
            return '';
        }

        $resolvedAuth = $this->authorizationService->resolve(
            $userAuth['locationAuth'],
            $locationAuthz,
            $userAuth['employeeRegion']
        );

        return $this->authorizationService->buildCondition($field, $resolvedAuth, $userAuth['upkeepAuth']);
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
     * 引用列表
     */
    private function quoteList(array $items): string
    {
        if (empty($items)) {
            return '';
        }
        return implode(',', array_map(fn($item) => $this->model->quote($item), $items));
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
    public static function replaceConditionVariables(string $condition, array $context): string
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
