<?php

namespace App\Controllers;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;
use App\Models\Mcommon;

class Route extends BaseController
{
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
    }

    //+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=
    // API: 获取用户菜单路由
    //+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=
    public function getUserRoutes()
    {
        log_message('error', 'getUserRoutes() called');

        // 验证Authorization头
        $authHeader = $this->request->getHeaderLine('Authorization');
        if (empty($authHeader)) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        }

        log_message('error', 'Authorization header: ' . ($authHeader ?: 'empty'));

        if (empty($authHeader)) {
            return $this->response->setJSON([
                'code' => '4010',
                'msg' => 'Token required',
                'data' => null
            ]);
        }

        // 从session中取出数据
        $session = \Config\Services::session();
        $company_id = $session->get('company_id');
        $user_workid = $session->get('user_workid');
        $user_pswd = $session->get('user_pswd');

        log_message('error', 'Session data - company_id: ' . ($company_id ?: 'empty') . ', user_workid: ' . ($user_workid ?: 'empty'));

        if (empty($company_id) || empty($user_workid)) {
            return $this->response->setJSON([
                'code' => '4011',
                'msg' => 'Session expired, please login again',
                'data' => null
            ]);
        }

        try {
            $model = new Mcommon();

            // 读出用户对应的角色
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
                    where 有效标识="1" and 员工属地="%s" and 工号="%s"
                    group by 员工属地,工号
                ) as t1
                left join
                (
                    select 角色组,replace(replace(角色编码,"，",",")," ","") as 角色编码
                    from def_role_group
                    where 有效标识="1"
                ) as t2 on t1.角色组=t2.角色组',
                $company_id, $user_workid);

            $query = $model->select($sql);
            $results = $query->getResult();

            $user_role_authz = '';

            foreach ($results as $row)
            {
                // 角色
                $role_arr = explode(',', $row->角色编码);
                $role_arr = array_unique($role_arr);

                $user_role_authz = '';
                foreach ($role_arr as $role)
                {
                    $user_role_authz = ($user_role_authz == '') ? sprintf('"%s"', $role) : sprintf('%s,"%s"', $user_role_authz, $role);
                }

                // 个人属地赋权
                if ($row->属地赋权 == '')
                {
                    $row->属地赋权 = $company_id;
                }
                $user_location_authz = $row->属地赋权;

                // 部门编码赋权
                $user_dept_code_arr = ($row->部门编码赋权 == '') ? [] : explode(',', $row->部门编码赋权);
                $user_dept_code_arr = array_unique($user_dept_code_arr);

                $user_dept_code_authz = '';
                foreach ($user_dept_code_arr as $dept_code)
                {
                    $user_dept_code_authz = ($user_dept_code_authz == '') ? sprintf('"%s"', $dept_code) : sprintf('%s,"%s"', $user_dept_code_authz, $dept_code);
                }

                // 部门全称赋权
                $user_dept_name_arr = ($row->部门全称赋权 == '') ? [] : explode(',', $row->部门全称赋权);
                $user_dept_name_arr = array_unique($user_dept_name_arr);

                $user_dept_name_authz = '';
                foreach ($user_dept_name_arr as $dept_name)
                {
                    $user_dept_name_authz = ($user_dept_name_authz == '') ? sprintf('"%s"', $dept_name) : sprintf('%s,"%s"', $user_dept_name_authz, $dept_name);
                }

                // 存入session
                $session_arr = [];
                $session_arr['user_role'] = $row->角色编码;
                $session_arr['user_role_authz'] = $user_role_authz;
                $session_arr['user_location_authz'] = $user_location_authz;
                $session_arr['user_dept_code_authz'] = $user_dept_code_authz;
                $session_arr['user_dept_name_authz'] = $user_dept_name_authz;
                $session_arr['user_workid_authz'] = $row->工号限权;
                $session_arr['user_debug_authz'] = ($user_pswd == $user_workid.$user_workid) ? '1' : $row->调试赋权;
                $session_arr['user_upkeep_authz'] = ($user_pswd == $user_workid.$user_workid) ? '1' : $row->维护赋权;
                $session_arr['user_location'] = $row->员工属地;
                $session_arr['user_dept_code'] = $row->员工部门编码;
                $session_arr['user_dept_name'] = $row->员工部门全称;

                $session = \Config\Services::session();
                $session->set($session_arr);
            }

            // 从session中取出数据
            $session = \Config\Services::session();
            $user_upkeep_authz = $session->get('user_upkeep_authz');
            $user_role_authz = $session->get('user_role_authz');
            $user_workid_authz = $session->get('user_workid_authz');

            // 读出角色对应的功能赋权
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
                        功能模块,参数,功能类型,模块名称,
                        ifnull(tb.一级菜单顺序,999) as 一级菜单顺序,
                        二级菜单顺序,
                        菜单显示
                    from
                    (
                        select
                            功能编码,一级菜单,二级菜单,
                            功能模块,参数,功能类型,模块名称,
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
                order by t2.一级菜单顺序,t2.二级菜单顺序', $user_role_authz);

            $query = $model->select($sql);
            $results = $query->getResult();

            log_message('error', 'SQL results count: ' . count($results));

            $function_authz_arr = [];
            $menuMap = [];

            foreach ($results as $row)
            {
                // 功能访问权限
                $function_authz_arr[$row->功能赋权] = $row->功能赋权;

                // 显示标志不等于1,不生成菜单
                if ($row->菜单显示 != 1)
                {
                    continue;
                }

                // 生成路由名称（用于前端导航）
                // 使用功能编码作为唯一路由名称
                $routeName = $row->功能编码;
                
                // 如果功能模块为空，表示使用原生 Vue 组件（如合同管理）
                // 否则使用通用查询工作台
                if ($row->功能模块 === '' || $row->功能模块 === null) {
                    $menuItem = [
                        'name' => $routeName,
                        'path' => '/menu-bridge',
                        'component' => 'view.menu-bridge',
                        'meta' => [
                            'title' => $row->二级菜单,
                            'icon' => 'mdi:menu',
                            'functionCode' => $row->功能编码
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

                // 存储一级菜单信息
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

                // 添加子菜单
                $menuMap[$row->一级菜单]['children'][] = $menuItem;
            }

            // 按顺序排列一级菜单（保持数据库返回的顺序）
            $menuList = array_values($menuMap);

            log_message('error', 'Menu list count: ' . count($menuList));

            // 存入session
            $session_arr = [];
            $session_arr['function_authz'] = $function_authz_arr;
            $session = \Config\Services::session();
            $session->set($session_arr);

            log_message('error', 'Returning menu data');

            return $this->response->setJSON([
                'code' => '0000',
                'msg' => 'Success',
                'data' => [
                    'routes' => $menuList,
                    'home' => 'home'
                ]
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Exception in getUserRoutes: ' . $e->getMessage());
            return $this->response->setJSON([
                'code' => '5000',
                'msg' => 'Error: ' . $e->getMessage(),
                'data' => null
            ]);
        }
    }

    //+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=
    // 汉字转拼音（简单实现）
    //+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=
    private function toPinyin($str)
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

    //+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=
    // API: 获取常量路由
    //+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=
    public function getConstantRoutes()
    {
        return $this->response->setJSON([
            'code' => '0000',
            'msg' => 'Success',
            'data' => [
                [
                    'name' => 'root',
                    'path' => '/',
                    'redirect' => '/home',
                    'meta' => [
                        'title' => 'Root'
                    ]
                ],
                [
                    'name' => 'login',
                    'path' => '/login',
                    'component' => 'layout.blank$view.login',
                    'meta' => [
                        'title' => '登录',
                        'constant' => true,
                        'keepAlive' => false
                    ]
                ],
                [
                    'name' => '403',
                    'path' => '/403',
                    'component' => 'view.403',
                    'meta' => [
                        'title' => '403',
                        'constant' => true
                    ]
                ],
                [
                    'name' => '404',
                    'path' => '/404',
                    'component' => 'view.404',
                    'meta' => [
                        'title' => '404',
                        'constant' => true
                    ]
                ],
                [
                    'name' => '500',
                    'path' => '/500',
                    'component' => 'view.500',
                    'meta' => [
                        'title' => '500',
                        'constant' => true
                    ]
                ]
            ]
        ]);
    }

    //+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=
    // API: 检查路由是否存在
    //+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=+=
    public function isRouteExist()
    {
        $routeName = $this->request->getGet('routeName');

        if (empty($routeName)) {
            return $this->response->setJSON([
                'code' => '4000',
                'msg' => 'Invalid request',
                'data' => false
            ]);
        }

        return $this->response->setJSON([
            'code' => '0000',
            'msg' => 'Success',
            'data' => true
        ]);
    }
}
