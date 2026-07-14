<?php

namespace App\Controllers;

class TrainApi extends BaseApiController
{
    public function tree()
    {
        $service = $this->getAuthorizationService();
        $resolvedAuth = $service->resolveLocationAuth('2035');
        $locationAuthzCond = $service->buildCondition('属地', $resolvedAuth, false);
        if ($locationAuthzCond === '') {
            $locationAuthzCond = '1=1';
        }

        $sql = sprintf('
            select GUID,姓名,身份证号,手机号码,属地,
                if(instr(培训状态,"在培"),"在培",培训状态) as 培训状态,培训批次,
                concat("培训师_",if(培训老师="","待补充",培训老师)) as 培训老师,
                培训开始日期,预计完成日期,培训完成日期,
                培训离开日期,培训离开原因
            from ee_train
            where %s and 有效标识="1" and 删除标识="0"
            order by if(instr(培训状态,"在培"),"在培",培训状态),
                培训老师,培训开始日期 desc,convert(姓名 using gbk)',
            $locationAuthzCond);

        $results = $this->model->select($sql)->getResultArray();
        $tree = $this->buildGroupedTrainTree($results);

        return $this->success($tree);
    }

    public function detail($guid = '')
    {
        if (empty($guid)) {
            $guid = $this->getGuidFromRequest();
        }

        if (empty($guid)) {
            return $this->paramError('人员GUID不能为空');
        }

        $sql = sprintf('
            select GUID,姓名,身份证号,手机号码,属地,
                培训业务,培训状态,培训批次,培训老师,
                培训开始日期,预计完成日期,
                培训完成日期,培训离开日期,
                培训离开原因,培训天数
            from ee_train
            where GUID="%s" and 有效标识="1" and 删除标识="0"',
            $guid);

        $result = $this->model->select($sql)->getRowArray();

        if (!$result) {
            return $this->notFound('人员不存在');
        }

        return $this->success($result);
    }

    public function update()
    {
        $data = $this->getJsonInput();

        if ($error = $this->requireParam($data, 'guid')) {
            return $error;
        }

        $guid = $data['guid'];
        $data = $this->buildUpdateData($data);
        $num = $this->updateRecord('ee_train', $data, sprintf('GUID="%s"', $guid));

        if ($num > 0) {
            return $this->success(null, '修改培训信息成功');
        }

        return $this->success(null, '没有需要更新的字段');
    }

    public function batchUpdate()
    {
        $data = $this->getJsonInput();

        if (empty($data['guids']) || !is_array($data['guids'])) {
            return $this->paramError('请选择要修改的人员');
        }

        $guidStr = implode(',', array_map(fn($v) => $this->model->quote((string)$v), $data['guids']));
        $updateFields = [];

        $data['操作记录'] = '批量修改';
        $data['操作来源'] = '页面修改';
        $data['操作人员'] = $this->getUserWorkId();
        $data['操作时间'] = date('Y-m-d H:i:s');

        foreach ($data as $key => $value) {
            if (in_array($key, ['guids', '操作', '人员'])) continue;
            if ($value === '') continue;
            $updateFields[] = sprintf('%s=%s', $key, $this->model->quote((string)$value));
        }

        if (empty($updateFields)) {
            return $this->success(null, '没有需要更新的字段');
        }

        $sql = sprintf(
            'update ee_train set %s where GUID in (%s)',
            implode(',', $updateFields),
            $guidStr
        );

        $num = $this->model->exec($sql);

        if ($num > 0) {
            return $this->success(null, sprintf('批量修改成功，修改 %d 条记录', $num));
        }

        return $this->serverError('批量修改失败');
    }

    public function delete()
    {
        $data = $this->getJsonInput();

        if (empty($data['guids']) || !is_array($data['guids'])) {
            return $this->paramError('请选择要删除的人员');
        }

        $guidStr = implode(',', array_map(fn($v) => $this->model->quote((string)$v), $data['guids']));
        $num = $this->deleteRecord('ee_train', sprintf('GUID in (%s)', $guidStr));

        if ($num > 0) {
            return $this->success(null, sprintf('删除成功，共删除 %d 条记录', $num));
        }

        return $this->serverError('删除失败');
    }

    public function transfer()
    {
        $data = $this->getJsonInput();

        if (empty($data['guids']) || !is_array($data['guids'])) {
            return $this->paramError('请选择要转入在职的人员');
        }

        if (empty($data['培训状态'])) {
            return $this->paramError('培训状态不能为空');
        }

        $guidStr = implode(',', array_map(fn($v) => $this->model->quote((string)$v), $data['guids']));
        $endTime = date('Y-m-d H:i:s');
        $startTime = date('Y-m-d H:i:s');

        if ($data['培训状态'] === '通过') {
            $sql = sprintf('
                update ee_train
                set 培训状态="%s",培训完成日期="%s",
                    结束操作时间="%s",操作时间="%s",操作人员="%s" 
                where GUID in (%s)',
                $data['培训状态'],
                $data['培训结束日期'] ?? '',
                $endTime,
                $endTime,
                $this->getUserWorkId(),
                $guidStr
            );

            $num = $this->model->exec($sql);

            $sql = sprintf('
                insert into ee_onjob (
                    姓名,身份证号,手机号码,属地,入职次数,
                    招聘渠道,
                    员工类别,
                    实习结束日期,
                    部门编码,部门名称,班组,
                    岗位名称,岗位类型,
                    结算类型,
                    工号1,工号2,
                    培训信息,培训开始日期,培训完成日期,
                    一阶段日期,二阶段日期,
                    员工阶段,员工状态,
                    离职日期,离职原因,
                    派遣公司,
                    记录开始日期,记录结束日期,
                    操作来源,操作人员,
                    开始操作时间,结束操作时间,
                    校验标识,删除标识,有效标识)
                select 
                    t1.姓名,t1.身份证号,t1.手机号码,t1.属地,%d,
                    t2.招聘渠道,
                    if(t2.招聘渠道="校招","未毕业学生","合同制员工") as 员工类别,
                    t2.实习结束日期,
                    "" as 部门编码,"" as 部门名称,"" as 班组,
                    "客服代表" as 岗位名称,"%s" as 岗位类型,
                    "%s" as 结算类型,
                    "" as 工号1,"" as 工号2,
                    "有" as 培训信息,培训开始日期,培训完成日期,
                    培训完成日期 as 一阶段日期,"" as 二阶段日期,
                    "新人组" as 员工阶段,"在职" as 员工状态,
                    "" as 离职日期,"" as 离职原因,
                    "" as 派遣公司,
                    "%s" as 记录开始日期,"" as 记录结束日期,
                    "培训表转入" as 操作来源,"%s" as 操作人员,
                    "%s" as 开始操作时间,"" as 结束操作时间,
                    "0" as 校验标识,"0" as 删除标识,"1" as 有效标识
                from
                (
                    select GUID,姓名,身份证号,手机号码,属地,培训业务,培训状态,
                        培训批次,培训老师,培训开始日期,预计完成日期,
                        培训完成日期,培训离开日期,培训离开原因,面试信息
                    from ee_train
                    where GUID in (%s)
                ) as t1
                left join
                (
                    select 姓名,身份证号,招聘渠道,实习结束日期
                    from ee_interview
                    group by 身份证号
                ) as t2
                on t1.身份证号=t2.身份证号',
                (int)($data['入职次数'] ?? 1),
                $data['岗位类型'] ?? '',
                $data['结算类型'] ?? '',
                $data['培训结束日期'] ?? '',
                $this->getUserWorkId(),
                $startTime,
                $guidStr
            );

            $this->model->exec($sql);
        } else {
            $sql = sprintf('
                update ee_train
                set 培训状态="%s",培训离开日期="%s",培训离开原因="%s",
                    结束操作时间="%s",操作时间="%s",操作人员="%s" 
                where GUID in (%s)',
                $data['培训状态'],
                $data['培训结束日期'] ?? '',
                $data['培训离开原因'] ?? '',
                $endTime,
                $endTime,
                $this->getUserWorkId(),
                $guidStr
            );

            $num = $this->model->exec($sql);
        }

        return $this->success(null, sprintf('更新培训状态成功，更新 %d 条记录', $num ?? 0));
    }

    public function options()
    {
        $resolvedAuth = $this->getAuthorizationService()->resolveLocationAuth('2035');
        $locationAuthz = $resolvedAuth;

        $regionSql = sprintf('
            select distinct 对象值 as value, 对象值 as label
            from def_object
            where 对象名称="属地" and 有效标识="1"
                and (属地="" or locate(属地,"%s"))
            order by convert(对象值 using gbk)',
            $locationAuthz
        );

        $trainBizSql = sprintf('
            select distinct 对象值 as value, 对象值 as label
            from def_object
            where 对象名称="培训业务" and 有效标识="1"
                and (属地="" or locate(属地,"%s"))
            order by convert(对象值 using gbk)',
            $locationAuthz
        );

        $regionResult = $this->model->select($regionSql)->getResultArray();
        $trainBizResult = $this->model->select($trainBizSql)->getResultArray();

        return $this->success([
            'region' => $regionResult,
            'trainBiz' => $trainBizResult,
            'trainStatus' => [
                ['value' => '通过', 'label' => '通过'],
                ['value' => '未通过', 'label' => '未通过'],
                ['value' => '离开', 'label' => '离开'],
                ['value' => '淘汰', 'label' => '淘汰'],
                ['value' => '转期', 'label' => '转期']
            ],
            'positionType' => [
                ['value' => '生产岗', 'label' => '生产岗'],
                ['value' => '职能岗', 'label' => '职能岗'],
                ['value' => '管理岗', 'label' => '管理岗']
            ],
            'settlementType' => [
                ['value' => '按量结算', 'label' => '按量结算'],
                ['value' => '按席结算', 'label' => '按席结算'],
                ['value' => '无结算', 'label' => '无结算']
            ]
        ]);
    }

    /**
     * 构建培训记录分组聚合树（多级桶聚合）。
     *
     * 算法：按 (培训开始日期, 培训老师, 培训状态, 属地) 4 个字段做多级桶聚合。
     * 与 buildOrgTree（递归父子）不同：这里不依赖父级编码，是顺序分组聚合。
     *
     * @param array $data 培训数据（含 GUID/姓名/属地/培训开始日期/预计完成日期/培训老师/培训状态）
     * @return array 聚合后的多级树
     */
    private function buildGroupedTrainTree(array $data): array
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
                'value' => $row['姓名'],
                'type' => 'person'
            ];

            $up1Id = sprintf('培训开始日期^%s^%s^%s^培训开始日期 (%s)', 
                $row['属地'], $row['培训状态'], $row['培训老师'], $row['培训开始日期']);
            if (!isset($up1Arr[$up1Id])) {
                $up1Arr[$up1Id] = [
                    'id' => $up1Id,
                    'value' => sprintf('%s 至 %s', $row['培训开始日期'], $row['预计完成日期']),
                    'num' => 0,
                    'items' => [],
                    'type' => 'date'
                ];
            }
            $up1Arr[$up1Id]['num'] = count($up1Arr[$up1Id]['items']) + 1;
            $up1Arr[$up1Id]['value'] = sprintf('%s 至 %s (%d人)', 
                $row['培训开始日期'], $row['预计完成日期'], $up1Arr[$up1Id]['num']);
            $up1Arr[$up1Id]['items'][] = $eeArr;
        }

        foreach ($up1Arr as $up1) {
            $arr = explode('^', $up1['id']);
            $up2Id = sprintf('培训老师^%s^%s^%s', $arr[1], $arr[2], $arr[3]);
            if (!isset($up2Arr[$up2Id])) {
                $up2Arr[$up2Id] = [
                    'id' => $up2Id,
                    'value' => $arr[3],
                    'num' => 0,
                    'items' => [],
                    'type' => 'teacher'
                ];
            }
            $up2Arr[$up2Id]['num'] += $up1['num'];
            $up2Arr[$up2Id]['value'] = sprintf('%s (%d人)', $arr[3], $up2Arr[$up2Id]['num']);
            $up2Arr[$up2Id]['items'][] = $up1;
        }

        foreach ($up2Arr as $up2) {
            $arr = explode('^', $up2['id']);
            $up3Id = sprintf('培训状态^%s^%s', $arr[1], $arr[2]);
            if (!isset($up3Arr[$up3Id])) {
                $up3Arr[$up3Id] = [
                    'id' => $up3Id,
                    'value' => $arr[2],
                    'num' => 0,
                    'items' => [],
                    'type' => 'status'
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
                    'type' => 'region'
                ];
            }
            $up4Arr[$up4Id]['num'] += $up3['num'];
            $up4Arr[$up4Id]['value'] = sprintf('%s (%d人)', $arr[1], $up4Arr[$up4Id]['num']);
            $up4Arr[$up4Id]['items'][] = $up3;
        }

        $csrArr = [
            'id' => '0级^培训人员',
            'value' => '培训人员',
            'items' => [],
            'type' => 'root'
        ];

        $csrNum = 0;
        foreach ($up4Arr as $up4) {
            $csrNum += $up4['num'];
            $csrArr['items'][] = $up4;
        }
        $csrArr['value'] = sprintf('培训人员 (%d人)', $csrNum);

        return [$csrArr];
    }
}
