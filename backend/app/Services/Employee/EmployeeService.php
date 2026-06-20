<?php

namespace App\Services\Employee;

use App\Models\Mcommon;

/**
 * 员工服务类
 *
 * 处理员工相关的业务逻辑：查询、更新、离职、批量操作、树构建等。
 * 从 EmployeeApi 控制器抽取，修复 SQL 注入并增加事务保护。
 */
class EmployeeService
{
    private Mcommon $model;

    /**
     * ee_onjob 表允许更新的字段白名单
     */
    private const ALLOWED_UPDATE_FIELDS = [
        '姓名', '身份证号', '手机号码', '属地', '入职次数', '招聘渠道',
        '员工类别', '实习结束日期', '部门编码', '部门名称', '班组', '小组',
        '岗位名称', '岗位类型', '结算类型',
        '工号1', '工号2',
        '培训信息', '培训开始日期', '培训完成日期',
        '一阶段日期', '二阶段日期', '员工阶段', '员工状态',
        '离职日期', '离职原因', '派遣公司', '记录开始日期',
    ];

    public function __construct()
    {
        $this->model = new Mcommon();
    }

    /**
     * 查询人员列表（带属地权限过滤）
     *
     * @param string $locationAuthzCond 属地权限 WHERE 条件
     * @return array 人员数据列表
     */
    public function getEmployeeList(string $locationAuthzCond): array
    {
        if ($locationAuthzCond === '') {
            $locationAuthzCond = '1=1';
        }

        $sql = sprintf('
            select GUID,姓名,工号1 as 工号,属地,员工状态,
                部门名称,if(班组="","未分班组",班组) as 班组,
                岗位名称,岗位类型,结算类型,培训完成日期,
                floor(datediff(if(离职日期="",curdate(),离职日期),一阶段日期)/30) as 在岗月数
            from ee_onjob
            where %s and 有效标识="1" and 删除标识="0"
            order by 属地,员工状态,
                convert(部门名称 using gbk),
                convert(班组 using gbk),
                convert(姓名 using gbk)',
            $locationAuthzCond
        );

        $result = $this->model->select($sql);
        return $result ? $result->getResultArray() : [];
    }

    /**
     * 查询人员详情
     *
     * @param string $guid 人员 GUID
     * @return array 人员详情数据，不存在返回空数组
     */
    public function getEmployeeDetail(string $guid): array
    {
        $sql = sprintf(
            'select GUID,姓名,身份证号,属地,员工状态,
                培训开始日期,培训完成日期,
                一阶段日期,二阶段日期,
                岗位名称,岗位类型,结算类型,
                部门名称,班组,工号1,
                离职日期,离职原因
            from ee_onjob
            where GUID=%s and 有效标识="1" and 删除标识="0"',
            $this->model->quote($guid)
        );

        $result = $this->model->select($sql);
        return $result ? ($result->getRowArray() ?: []) : [];
    }

    /**
     * 处理离职（带事务保护）
     *
     * @param string $guid 人员 GUID
     * @param array  $data 离职数据（员工状态、离职日期、离职原因）
     * @return int 影响行数
     */
    public function processResignation(string $guid, array $data): int
    {
        $db = db_connect('btdc');
        $db->transStart();

        try {
            $sql = sprintf(
                'update ee_onjob
                set 员工状态=%s,
                    离职日期=%s,
                    离职原因=%s,
                    记录结束日期=if(记录结束日期="",%s,记录结束日期)
                where concat(身份证号,入职次数) in
                    (
                        select concat(身份证号,入职次数)
                        from
                        (
                            select 身份证号,入职次数
                            from ee_onjob
                            where GUID=%s
                        ) as ta
                    )
                    and 员工状态!="离职"',
                $this->model->quote($data['员工状态'] ?? ''),
                $this->model->quote($data['离职日期'] ?? ''),
                $this->model->quote($data['离职原因'] ?? ''),
                $this->model->quote($data['离职日期'] ?? ''),
                $this->model->quote($guid)
            );

            $db->query($sql);
            $num = $db->affectedRows();

            $db->transComplete();
            return $num;
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }
    }

    /**
     * 更新人员信息（审计流水模式：插新 + 软删旧，带事务保护）
     *
     * @param string $guid          人员 GUID
     * @param array  $data          表单数据
     * @param string $effectiveDate 生效日期
     * @param string $userWorkId    操作人员工号
     * @return int 影响行数（-1=人员不存在，0=无变更，>0=成功）
     */
    public function updateEmployee(string $guid, array $data, string $effectiveDate, string $userWorkId): int
    {
        $oldRecord = $this->getEmployeeDetail($guid);
        if (empty($oldRecord)) {
            return -1;
        }

        // 检测变更字段（白名单校验）
        $updateStr = $this->detectChangedFields($oldRecord, $data);
        if ($updateStr === '') {
            return 0;
        }

        $db = db_connect('btdc');
        $db->transStart();

        try {
            // 插入新记录（复制旧记录 + 替换审计字段）
            $sqlInsert = sprintf(
                'insert into ee_onjob (姓名,身份证号,手机号码,属地,入职次数,招聘渠道,
                    员工类别,实习结束日期,部门编码,部门名称,班组,小组,
                    岗位名称,岗位类型,结算类型,
                    工号1,工号2,
                    培训信息,培训开始日期,培训完成日期,
                    一阶段日期,二阶段日期,员工阶段,员工状态,
                    离职日期,离职原因,派遣公司,记录开始日期,
                    操作来源,操作人员,开始操作时间,
                    校验标识,删除标识,有效标识)
                select 姓名,身份证号,手机号码,属地,入职次数,招聘渠道,
                    员工类别,实习结束日期,部门编码,部门名称,班组,小组,
                    岗位名称,岗位类型,结算类型,
                    工号1,工号2,
                    培训信息,培训开始日期,培训完成日期,
                    一阶段日期,二阶段日期,员工阶段,员工状态,
                    离职日期,离职原因,派遣公司,%s,
                    "页面",%s,%s,
                    "0","0","1"
                from ee_onjob
                where GUID=%s',
                $this->model->quote($effectiveDate),
                $this->model->quote($userWorkId),
                $this->model->quote(date('Y-m-d H:i:s')),
                $this->model->quote($guid)
            );
            $db->query($sqlInsert);

            // 软删旧记录
            $sqlUpdate = sprintf(
                'update ee_onjob
                set 操作记录=%s,记录结束日期=%s,有效标识="0"
                where GUID=%s',
                $this->model->quote('更新,' . $updateStr),
                $this->model->quote($effectiveDate),
                $this->model->quote($guid)
            );
            $db->query($sqlUpdate);
            $num = $db->affectedRows();

            $db->transComplete();
            return $num;
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }
    }

    /**
     * 批量修改人员信息
     *
     * @param array  $guids      GUID 列表
     * @param array  $data       表单数据
     * @param string $userWorkId 操作人员工号
     * @return int 影响行数
     */
    public function batchUpdateEmployees(array $guids, array $data, string $userWorkId): int
    {
        $guidStr = implode(',', array_map(
            fn($v) => $this->model->quote((string) $v),
            $guids
        ));

        $updateFields = [];
        foreach ($data as $key => $value) {
            if (in_array($key, ['guids', '操作', '生效日期'])) continue;
            if (!$this->isValidFieldName($key)) continue;
            if ($value === '') continue;
            $updateFields[] = sprintf('`%s`=%s', $key, $this->model->quote((string) $value));
        }

        if (empty($updateFields)) {
            return 0;
        }

        $sql = sprintf(
            'update ee_onjob
            set %s,操作人员=%s,操作时间=%s
            where GUID in (%s)',
            implode(',', $updateFields),
            $this->model->quote($userWorkId),
            $this->model->quote(date('Y-m-d H:i:s')),
            $guidStr
        );

        return $this->model->exec($sql);
    }

    /**
     * 获取人员相关下拉选项
     *
     * @param string $locationAuthz 属地权限值
     * @return array 选项数据
     */
    public function getEmployeeOptions(string $locationAuthz): array
    {
        $regionSql = sprintf(
            'select distinct 对象值 as value, 对象值 as label
            from def_object
            where 对象名称="属地" and 有效标识="1"
                and (属地="" or locate(属地,%s))
            order by convert(对象值 using gbk)',
            $this->model->quote($locationAuthz)
        );

        $regionResult = $this->model->select($regionSql);
        $region = $regionResult ? $regionResult->getResultArray() : [];

        return [
            'region' => $region,
            'status' => [
                ['value' => '在职', 'label' => '在职'],
                ['value' => '离职', 'label' => '离职'],
            ],
            'positionType' => [
                ['value' => '生产岗', 'label' => '生产岗'],
                ['value' => '职能岗', 'label' => '职能岗'],
                ['value' => '管理岗', 'label' => '管理岗'],
            ],
            'settlementType' => [
                ['value' => '按量结算', 'label' => '按量结算'],
                ['value' => '按席结算', 'label' => '按席结算'],
                ['value' => '无结算', 'label' => '无结算'],
            ],
        ];
    }

    /**
     * 构建人员分组聚合树（多级桶聚合）
     *
     * 按 (属地, 员工状态, 部门名称, 班组) 4 个字段做多级桶聚合，每层统计 num 和 items。
     *
     * @param array $data 人员数据
     * @return array 聚合后的多级树
     */
    public function buildGroupedEmployeeTree(array $data): array
    {
        $up4Arr = [];
        $up3Arr = [];
        $up2Arr = [];
        $up1Arr = [];

        foreach ($data as $row) {
            $eeArr = [
                'id' => sprintf('人员^%s^%s', $row['GUID'], $row['姓名']),
                'guid' => $row['GUID'],
                'name' => $row['姓名'],
                'value' => sprintf('%s (%s,%d月)', $row['姓名'], $row['岗位名称'], $row['在岗月数']),
                'type' => 'person',
            ];

            $up1Id = sprintf('班组^%s^%s^%s^%s', $row['属地'], $row['员工状态'], $row['部门名称'], $row['班组']);
            if (!isset($up1Arr[$up1Id])) {
                $up1Arr[$up1Id] = [
                    'id' => $up1Id,
                    'value' => $row['班组'],
                    'num' => 0,
                    'items' => [],
                    'type' => 'team',
                ];
            }
            $up1Arr[$up1Id]['num'] = count($up1Arr[$up1Id]['items']) + 1;
            $up1Arr[$up1Id]['value'] = sprintf('%s (%d人)', $row['班组'], $up1Arr[$up1Id]['num']);
            $up1Arr[$up1Id]['items'][] = $eeArr;
        }

        foreach ($up1Arr as $up1) {
            $arr = explode('^', $up1['id']);
            $up2Id = sprintf('部门^%s^%s^%s', $arr[1], $arr[2], $arr[3]);
            if (!isset($up2Arr[$up2Id])) {
                $up2Arr[$up2Id] = [
                    'id' => $up2Id,
                    'value' => $arr[3],
                    'num' => 0,
                    'items' => [],
                    'type' => 'dept',
                ];
            }
            $up2Arr[$up2Id]['num'] += $up1['num'];
            $up2Arr[$up2Id]['value'] = sprintf('%s (%d人)', $arr[3], $up2Arr[$up2Id]['num']);
            $up2Arr[$up2Id]['items'][] = $up1;
        }

        foreach ($up2Arr as $up2) {
            $arr = explode('^', $up2['id']);
            $up3Id = sprintf('员工状态^%s^%s', $arr[1], $arr[2]);
            if (!isset($up3Arr[$up3Id])) {
                $up3Arr[$up3Id] = [
                    'id' => $up3Id,
                    'value' => $arr[2],
                    'num' => 0,
                    'items' => [],
                    'type' => 'status',
                ];
            }
            $up3Arr[$up3Id]['num'] += $up2['num'];
            $up3Arr[$up3Id]['value'] = sprintf('%s (%d人)', $arr[2], $up3Arr[$up3Id]['num']);
            $up3Arr[$up3Id]['items'][] = $up2;
        }

        foreach ($up3Arr as $up3) {
            $arr = explode('^', $up3['id']);
            $up4Id = sprintf('属地^%s', $arr[1]);
            if (!isset($up4Arr[$up4Id])) {
                $up4Arr[$up4Id] = [
                    'id' => $up4Id,
                    'value' => $arr[1],
                    'num' => 0,
                    'items' => [],
                    'type' => 'region',
                ];
            }
            $up4Arr[$up4Id]['num'] += $up3['num'];
            $up4Arr[$up4Id]['value'] = sprintf('%s (%d人)', $arr[1], $up4Arr[$up4Id]['num']);
            $up4Arr[$up4Id]['items'][] = $up3;
        }

        $csrArr = [
            'id' => '0级^入职人员',
            'value' => '入职人员',
            'items' => [],
            'type' => 'root',
        ];

        $csrNum = 0;
        foreach ($up4Arr as $up4) {
            $csrNum += $up4['num'];
            $csrArr['items'][] = $up4;
        }
        $csrArr['value'] = sprintf('入职人员 (%d人)', $csrNum);

        return [$csrArr];
    }

    /**
     * 检测变更字段（白名单校验，返回变更字段名的逗号分隔字符串）
     *
     * @param array $oldRecord 旧记录
     * @param array $data      新数据
     * @return string 变更字段名列表（逗号分隔），无变更返回空字符串
     */
    private function detectChangedFields(array $oldRecord, array $data): string
    {
        $skipKeys = ['guid', '操作', '生效日期'];
        $updateStr = '';

        foreach ($data as $key => $value) {
            if (in_array($key, $skipKeys)) continue;
            if (!$this->isValidFieldName($key)) continue;
            if ($value === '') continue;

            if (($oldRecord[$key] ?? '') !== $value) {
                $updateStr .= ($updateStr ? ',' : '') . $key;
            }
        }

        return $updateStr;
    }

    /**
     * 校验字段名是否在白名单中
     *
     * @param string $fieldName 字段名
     * @return bool 合法返回 true
     */
    private function isValidFieldName(string $fieldName): bool
    {
        return in_array($fieldName, self::ALLOWED_UPDATE_FIELDS, true);
    }
}
