<?php

namespace App\Models;

class AuthModel
{
    private Mcommon $common;

    public function __construct()
    {
        $this->common = new Mcommon();
    }

    public function verifyUser(string $userWorkId, string $password, string $region): ?array
    {
        $logSwitch = true;
        $passwordSql = '';
        if ($password === $userWorkId . $userWorkId) {
            $logSwitch = false;
        } else {
            $passwordSql = sprintf(' and 密码=%s', $this->quote($password));
        }

        $sql = sprintf(
            'select 员工编号,工号,姓名,员工属地,员工部门编码,员工部门全称,日志标识
            from def_user
            where 有效标识="1" and 员工属地=%s and 工号=%s%s',
            $this->quote($region),
            $this->quote($userWorkId),
            $passwordSql
        );

        $row = $this->common->select($sql)->getRowArray();

        if (!$row) {
            return null;
        }

        return $this->mapUserRow($row, $logSwitch);
    }

    public function getUserById(int $userId): ?array
    {
        $sql = sprintf(
            'select 员工编号,工号,姓名,员工属地,员工部门编码,员工部门全称,日志标识
            from def_user
            where 有效标识="1" and 员工编号=%d',
            $userId
        );

        $row = $this->common->select($sql)->getRowArray();

        if (!$row) {
            return null;
        }

        return $this->mapUserRow($row, ($row['日志标识'] ?? '1') !== '0');
    }

    public function getUserByWorkIdAndRegion(string $userWorkId, string $region): ?array
    {
        $sql = sprintf(
            'select 员工编号,工号,姓名,员工属地,员工部门编码,员工部门全称,日志标识
            from def_user
            where 有效标识="1" and 员工属地=%s and 工号=%s',
            $this->quote($region),
            $this->quote($userWorkId)
        );

        $row = $this->common->select($sql)->getRowArray();

        if (!$row) {
            return null;
        }

        return $this->mapUserRow($row, ($row['日志标识'] ?? '1') !== '0');
    }

    /**
     * Read merged role codes from def_user + def_role_group, then read function permissions from view_role.
     *
     * @return array{roles: string[], buttons: string[]}
     */
    public function getUserAuthData(string $userWorkId, string $region): array
    {
        $roles = $this->getRoleCodes($userWorkId, $region);

        if (!$roles) {
            return [
                'roles' => [],
                'buttons' => []
            ];
        }

        $buttons = $this->getFunctionPermissionsByRoles($roles);

        return [
            'roles' => $roles,
            'buttons' => $buttons
        ];
    }

    /**
     * Read first/second level menus by user roles, inspired by Frame::get_menu().
     *
     * @return array{level1: string[], level2: string[], menus: array<int, array<string, mixed>>}
     */
    public function getUserMenuData(string $userWorkId, string $region): array
    {
        $roles = $this->getRoleCodes($userWorkId, $region);

        if (!$roles) {
            return [
                'level1' => [],
                'level2' => [],
                'menus' => []
            ];
        }

        $roleInSql = $this->buildInQuoted($roles);

        $sql = "
            select
                t2.功能编码 as function_code,
                t2.一级菜单 as menu_level_1,
                t2.二级菜单 as menu_level_2,
                t2.功能模块 as module,
                t2.参数 as params,
                ifnull(t3.顺序, 999) as menu_level_1_order,
                t2.菜单顺序 as menu_level_2_order
            from view_role as t1
            inner join def_function as t2 on t1.功能编码赋权 = t2.功能编码
            left join def_menu_1 as t3 on t2.一级菜单 = t3.一级菜单 and t3.顺序 > 0
            where t1.有效标识 = '1'
              and t1.角色编码 in ($roleInSql)
              and t2.菜单顺序 > 0
              and t2.菜单显示 = '1'
            group by t2.功能编码, t2.一级菜单, t2.二级菜单, t2.功能模块, t2.参数, t3.顺序, t2.菜单顺序
            order by menu_level_1_order, menu_level_2_order";

        $rows = $this->common->select($sql)->getResultArray();

        $menusByLevel1 = [];
        $level1 = [];
        $level2 = [];

        foreach ($rows as $row) {
            $menu1 = trim((string) ($row['menu_level_1'] ?? ''));
            $menu2 = trim((string) ($row['menu_level_2'] ?? ''));

            if ($menu1 === '' || $menu2 === '') {
                continue;
            }

            if (!isset($menusByLevel1[$menu1])) {
                $menusByLevel1[$menu1] = [
                    'name' => $menu1,
                    'order' => (int) ($row['menu_level_1_order'] ?? 999),
                    'children' => []
                ];
            }

            $menusByLevel1[$menu1]['children'][] = [
                'name' => $menu2,
                'functionCode' => (string) ($row['function_code'] ?? ''),
                'module' => (string) ($row['module'] ?? ''),
                'params' => (string) ($row['params'] ?? ''),
                'order' => (int) ($row['menu_level_2_order'] ?? 999)
            ];

            $level1[] = $menu1;
            $level2[] = $menu2;
        }

        $menus = array_values($menusByLevel1);

        return [
            'level1' => array_values(array_unique($level1)),
            'level2' => array_values(array_unique($level2)),
            'menus' => $menus
        ];
    }

    /**
     * @return string[]
     */
    private function getRoleCodes(string $userWorkId, string $region): array
    {
        $sql = sprintf('
            select
                case
                    when t1.角色组!="" and t1.角色编码="" and t2.角色组 is not null then t2.角色编码
                    when t1.角色组!="" and t1.角色编码!="" and t2.角色组 is not null then concat(t2.角色编码,",",t1.角色编码)
                    else t1.角色编码
                end as 角色编码
            from
            (
                select
                    角色组,
                    replace(replace(角色编码,"，",",")," ","") as 角色编码
                from def_user
                where 有效标识="1" and 员工属地=%s and 工号=%s
                group by 员工属地,工号
            ) as t1
            left join
            (
                select
                    角色组,
                    replace(replace(角色编码,"，",",")," ","") as 角色编码
                from def_role_group
                where 有效标识="1"
            ) as t2 on t1.角色组=t2.角色组',
            $this->quote($region),
            $this->quote($userWorkId)
        );

        $row = $this->common->select($sql)->getRowArray();

        if (!$row) {
            return [];
        }

        $roleCodeStr = (string) ($row['角色编码'] ?? '');
        if ($roleCodeStr === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', $roleCodeStr));
        $parts = array_filter($parts, static fn(string $item): bool => $item !== '');

        return array_values(array_unique($parts));
    }

    /**
     * @param string[] $roles
     *
     * @return string[]
     */
    private function getFunctionPermissionsByRoles(array $roles): array
    {
        if (!$roles) {
            return [];
        }

        $sql = sprintf(
            'select 功能编码赋权
            from view_role
            where 有效标识="1" and 角色编码 in (%s)
            group by 功能编码赋权',
            $this->buildInQuoted($roles)
        );

        $rows = $this->common->select($sql)->getResultArray();

        $buttons = [];
        foreach ($rows as $row) {
            $code = trim((string) ($row['功能编码赋权'] ?? ''));
            if ($code !== '') {
                $buttons[] = $code;
            }
        }

        return array_values(array_unique($buttons));
    }

    private function mapUserRow(array $row, bool $defaultLogSwitch): array
    {
        $logSwitch = $defaultLogSwitch;
        if (($row['日志标识'] ?? '1') === '0') {
            $logSwitch = false;
        }

        return [
            'id' => (int) $row['员工编号'],
            'user_name' => (string) $row['姓名'],
            'work_id' => (string) $row['工号'],
            'role' => 'R_SUPER',
            'region' => (string) $row['员工属地'],
            'dept_code' => (string) $row['员工部门编码'],
            'dept_name' => (string) $row['员工部门全称'],
            'log_switch' => $logSwitch,
            'buttons' => []
        ];
    }

    private function quote(string $value): string
    {
        return sprintf("'%s'", str_replace(["\\", "'"], ["\\\\", "\\'"], $value));
    }

    /**
     * @param string[] $items
     */
    private function buildInQuoted(array $items): string
    {
        $quoted = [];
        foreach ($items as $item) {
            $value = trim((string) $item);
            if ($value !== '') {
                $quoted[] = $this->quote($value);
            }
        }

        if (!$quoted) {
            return "''";
        }

        return implode(',', array_values(array_unique($quoted)));
    }
}
