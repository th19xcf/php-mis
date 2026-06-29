<?php

namespace App\Services;

use App\Models\Mcommon;

/**
 * 路由与菜单服务
 *
 * 负责用户权限查询、菜单树构建等业务逻辑。
 * 从 Route 控制器下沉，使控制器仅负责请求解析和响应封装。
 */
class RouteService
{
    private Mcommon $model;

    public function __construct()
    {
        $this->model = new Mcommon();
    }

    /**
     * 查询用户权限
     *
     * @param string $companyId 员工属地
     * @param string $userWorkid 工号
     * @return array{user_role: string, user_role_authz: string, user_location_authz: string, user_dept_code_authz: string, user_dept_name_authz: string, user_workid_authz: string, user_debug_authz: string, user_upkeep_authz: string, user_location: string, user_dept_code: string, user_dept_name: string}
     */
    public function loadUserPermissions(string $companyId, string $userWorkid): array
    {
        $sql = sprintf('
            select
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
                    replace(replace(属地赋权,"，",",")," ","") as  属地赋权,
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
            $this->model->quote($companyId), $this->model->quote($userWorkid));

        $results = $this->model->select($sql)->getResult();

        $permissions = [
            'user_role' => '',
            'user_role_authz' => '',
            'user_location_authz' => '',
            'user_dept_code_authz' => '',
            'user_dept_name_authz' => '',
            'user_workid_authz' => '',
            'user_debug_authz' => '',
            'user_upkeep_authz' => '',
            'user_location' => '',
            'user_dept_code' => '',
            'user_dept_name' => '',
        ];

        foreach ($results as $row) {
            $role_arr = explode(',', $row->角色编码);
            $role_arr = array_unique($role_arr);

            $user_role_authz = '';
            foreach ($role_arr as $role) {
                $user_role_authz = ($user_role_authz == '') ? sprintf('"%s"', $role) : sprintf('%s,"%s"', $user_role_authz, $role);
            }

            if (trim($row->属地赋权) == '') {
                $row->属地赋权 = $companyId;
            }
            $user_location_authz = $row->属地赋权;

            $user_dept_code_arr = ($row->部门编码赋权 == '') ? [] : explode(',', $row->部门编码赋权);
            $user_dept_code_arr = array_unique($user_dept_code_arr);

            $user_dept_code_authz = '';
            foreach ($user_dept_code_arr as $dept_code) {
                $user_dept_code_authz = ($user_dept_code_authz == '') ? sprintf('"%s"', $dept_code) : sprintf('%s,"%s"', $user_dept_code_authz, $dept_code);
            }

            $user_dept_name_arr = ($row->部门全称赋权 == '') ? [] : explode(',', $row->部门全称赋权);
            $user_dept_name_arr = array_unique($user_dept_name_arr);

            $user_dept_name_authz = '';
            foreach ($user_dept_name_arr as $dept_name) {
                $user_dept_name_authz = ($user_dept_name_authz == '') ? sprintf('"%s"', $dept_name) : sprintf('%s,"%s"', $user_dept_name_authz, $dept_name);
            }

            $permissions = [
                'user_role' => $row->角色编码,
                'user_role_authz' => $user_role_authz,
                'user_location_authz' => $user_location_authz,
                'user_dept_code_authz' => $user_dept_code_authz,
                'user_dept_name_authz' => $user_dept_name_authz,
                'user_workid_authz' => $row->工号限权,
                'user_debug_authz' => $row->调试赋权,
                'user_upkeep_authz' => $row->维护赋权,
                'user_location' => $row->员工属地,
                'user_dept_code' => $row->员工部门编码,
                'user_dept_name' => $row->员工部门全称,
            ];
        }

        return $permissions;
    }

    /**
     * 查询菜单数据并构建菜单树
     *
     * @param string $userRoleAuthz 角色赋权字符串（格式: "role1","role2"）
     * @return array 菜单列表
     */
    public function buildMenuList(string $userRoleAuthz): array
    {
        $sql = sprintf(
            'select
                t1.角色编码,t1.角色名称,
                t1.功能赋权,
                t1.备注授权,t1.新增授权,t1.修改授权,t1.删除授权,
                t1.维护授权,t1.整表授权,
                t1.导入授权,t1.导出授权,t1.工号限权,
                ifnull(t2.功能编码,"") as 功能编码,
                ifnull(t2.一级菜单,"") as 一级菜单,
                ifnull(t2.二级菜单,"") as 二级菜单,
                ifnull(t2.功能模块,"") as 功能模块,
                ifnull(t2.参数,"") as 参数,
                ifnull(t2.前端路由,"") as 前端路由,
                ifnull(t2.一级菜单顺序,999) as 一级菜单顺序,
                ifnull(t2.二级菜单顺序,999) as 二级菜单顺序,
                ifnull(t2.菜单显示,"") as 菜单显示,
                ifnull(t3.部门编码字段,"") as 部门编码字段,
                ifnull(t3.部门全称字段,"") as 部门全称字段,
                ifnull(t3.属地字段,"") as 属地字段
            from
            (
                select 角色编码,角色名称,功能编码赋权 as 功能赋权,
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
                where 有效标识="1" and 角色编码 in (%s)
                group by 角色编码,功能编码赋权
            ) as t1
            left join
            (
                select
                    功能编码,
                    ta.一级菜单,ta.二级菜单,
                    功能模块,参数,功能类型,模块名称,前端路由,
                    ifnull(tb.一级菜单顺序,999) as 一级菜单顺序,
                    二级菜单顺序,
                    菜单显示
                from
                (
                    select
                        功能编码,一级菜单,二级菜单,
                        功能模块,参数,功能类型,模块名称,前端路由,
                        菜单顺序 as 二级菜单顺序,菜单显示
                    from def_function
                    where 菜单顺序>0
                ) as ta
                left join
                (
                    select 一级菜单,顺序 as 一级菜单顺序
                    from def_menu_1
                    where 顺序>0
                ) as tb on ta.一级菜单=tb.一级菜单
                order by 一级菜单顺序,二级菜单顺序
            ) as t2 on t1.功能赋权=t2.功能编码
            left join
            (
                select 查询模块,部门编码字段,部门全称字段,属地字段
                from def_query_config
            ) as t3 on if(t2.功能类型="查询",t2.模块名称,"")=t3.查询模块
            group by t1.功能赋权
            order by t2.一级菜单顺序,t2.二级菜单顺序', $userRoleAuthz);

        $results = $this->model->select($sql)->getResult();

        $menuMap = [];

        foreach ($results as $row) {
            if ($row->菜单显示 != 1) {
                continue;
            }

            $routeName = $row->功能编码;

            if ($row->功能模块 === '' || $row->功能模块 === null) {
                $menuItem = [
                    'name' => $routeName,
                    'path' => '/menu-bridge',
                    'component' => 'view.menu-bridge',
                    'meta' => [
                        'title' => $row->二级菜单,
                        'icon' => 'mdi:menu',
                        'functionCode' => $row->功能编码,
                        'frontendRoute' => $row->前端路由
                    ]
                ];
            } else {
                $routePath = $row->功能编码;
                $menuItem = [
                    'name' => $routeName,
                    'path' => '/' . $routePath,
                    'component' => 'view.common',
                    'meta' => [
                        'title' => $row->二级菜单,
                        'icon' => 'mdi:menu',
                        'routePath' => $routePath,
                        'functionCode' => $row->功能编码
                    ]
                ];
            }

            if (!isset($menuMap[$row->一级菜单])) {
                $menuMap[$row->一级菜单] = [
                    'name' => $row->一级菜单,
                    'path' => '/' . $this->toPinyin($row->一级菜单),
                    'redirect' => '/home',
                    'component' => 'layout.base',
                    'meta' => [
                        'title' => $row->一级菜单,
                        'icon' => 'mdi:menu'
                    ],
                    'children' => []
                ];
            }

            $menuMap[$row->一级菜单]['children'][] = $menuItem;
        }

        return array_values($menuMap);
    }

    /**
     * 中文菜单名转拼音路径（基于固定映射表）
     */
    private function toPinyin(string $str): string
    {
        $pinyinMap = [
            '系统' => 'system',
            '管理' => 'manage',
            '查询' => 'query',
            '报表' => 'report',
            '数据' => 'data',
            '维护' => 'maintain',
            '用户' => 'user',
            '角色' => 'role',
            '权限' => 'auth',
            '日志' => 'log',
            '配置' => 'config',
            '业务' => 'biz'
        ];

        foreach ($pinyinMap as $key => $value) {
            if (strpos($str, $key) !== false) {
                return $value;
            }
        }

        return preg_replace('/[^\x{4e00}-\x{9fa5}]/u', '', $str);
    }
}
