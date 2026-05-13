<?php

namespace App\Controllers;

use App\Constants\ApiCode;
use App\Libraries\SessionUserContext;
use App\Models\Mcommon;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class EmployeeApi extends BaseController
{
    protected $model;
    private SessionUserContext $userContext;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->model = new Mcommon();
        $this->userContext = new SessionUserContext();
    }

    public function tree()
    {
        $menuId = $this->request->getGet('menu_id');
        $locationAuthzCond = $this->userContext->getSessionValue($menuId . '-location_authz_cond');
        
        if (empty($locationAuthzCond)) {
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
            $locationAuthzCond);

        $query = $this->model->select($sql);
        $results = $query->getResultArray();

        $tree = $this->buildTree($results);

        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg' => 'Success',
            'data' => $tree
        ]);
    }

    public function detail($guid = '')
    {
        if (empty($guid)) {
            $json = $this->request->getJSON(true);
            $guid = $json['guid'] ?? '';
        }

        if (empty($guid)) {
            return $this->response->setJSON([
                'code' => ApiCode::PARAM_ERROR,
                'msg' => '人员GUID不能为空',
                'data' => null
            ]);
        }

        $sql = sprintf('
            select 姓名,身份证号,属地,员工状态,
                培训开始日期,培训完成日期,
                一阶段日期,二阶段日期,
                岗位名称,岗位类型,结算类型,
                部门名称,班组,工号1,
                离职日期,离职原因
            from ee_onjob
            where GUID="%s" and 有效标识="1" and 删除标识="0"',
            $guid);

        $query = $this->model->select($sql);
        $result = $query->getRowArray();

        if (!$result) {
            return $this->response->setJSON([
                'code' => ApiCode::NOT_FOUND,
                'msg' => '人员不存在',
                'data' => null
            ]);
        }

        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg' => 'Success',
            'data' => $result
        ]);
    }

    public function update()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['guid'])) {
            return $this->response->setJSON([
                'code' => ApiCode::PARAM_ERROR,
                'msg' => '人员GUID不能为空',
                'data' => null
            ]);
        }

        $userWorkid = $this->userContext->getSessionUser()['workId'] ?: 'system';

        $guid = $data['guid'];
        $effectiveDate = $data['生效日期'] ?? date('Y-m-d');

        $oldSql = sprintf('
            select * from ee_onjob
            where GUID="%s" and 有效标识="1" and 删除标识="0"',
            $guid);
        $oldQuery = $this->model->select($oldSql);
        $oldRecord = $oldQuery->getRowArray();

        if (!$oldRecord) {
            return $this->response->setJSON([
                'code' => ApiCode::NOT_FOUND,
                'msg' => '人员不存在',
                'data' => null
            ]);
        }

        if (!empty($data['员工状态']) && $data['员工状态'] === '离职') {
            $sql = sprintf('
                update ee_onjob
                set 员工状态="%s",
                    离职日期="%s",
                    离职原因="%s",
                    记录结束日期=if(记录结束日期="","%s",记录结束日期)
                where concat(身份证号,入职次数) in
                    (
                        select concat(身份证号,入职次数)
                        from
                        (
                            select 身份证号,入职次数
                            from ee_onjob
                            where GUID="%s"
                        ) as ta
                    )
                    and 员工状态!="离职"',
                $data['员工状态'],
                $data['离职日期'] ?? '',
                $data['离职原因'] ?? '',
                $data['离职日期'] ?? '',
                $guid
            );

            $num = $this->model->exec($sql);

            return $this->response->setJSON([
                'code' => ApiCode::SUCCESS,
                'msg' => sprintf('处理离职信息成功，修改 %d 条记录', $num),
                'data' => null
            ]);
        }

        $updateStr = '';
        $insertFields = [];
        $insertValues = [];

        foreach ($data as $key => $value) {
            if (in_array($key, ['guid', '操作', '生效日期'])) continue;
            if ($value === '') continue;

            if ($oldRecord[$key] ?? '' !== $value) {
                $updateStr .= ($updateStr ? ',' : '') . $key;
                $insertFields[] = $key;
                $insertValues[] = sprintf('"%s"', addslashes($value));
            }
        }

        if (empty($updateStr)) {
            return $this->response->setJSON([
                'code' => ApiCode::SUCCESS,
                'msg' => '没有需要更新的字段',
                'data' => null
            ]);
        }

        $sqlInsert = sprintf('
            insert into ee_onjob (姓名,身份证号,手机号码,属地,入职次数,招聘渠道,
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
                离职日期,离职原因,派遣公司,"%s",
                "页面","%s","%s",
                "0","0","1"
            from ee_onjob
            where GUID="%s"',
            $effectiveDate,
            $userWorkid,
            date('Y-m-d H:i:s'),
            $guid
        );

        $sqlUpdate = sprintf('
            update ee_onjob
            set 操作记录="更新,%s",记录结束日期="%s",有效标识="0"
            where GUID="%s"',
            $updateStr,
            $effectiveDate,
            $guid
        );

        $this->model->exec($sqlInsert);
        $num = $this->model->exec($sqlUpdate);

        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg' => sprintf('修改成功，修改 %d 条记录', $num),
            'data' => null
        ]);
    }

    public function batchUpdate()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['guids']) || !is_array($data['guids'])) {
            return $this->response->setJSON([
                'code' => ApiCode::PARAM_ERROR,
                'msg' => '请选择要修改的人员',
                'data' => null
            ]);
        }

        $userWorkid = $this->userContext->getSessionUser()['workId'] ?: 'system';

        $guidStr = implode('","', array_map('addslashes', $data['guids']));
        $effectiveDate = $data['生效日期'] ?? date('Y-m-d');

        $updateFields = [];
        foreach ($data as $key => $value) {
            if (in_array($key, ['guids', '操作', '生效日期'])) continue;
            if ($value === '') continue;
            $updateFields[] = sprintf('%s="%s"', $key, addslashes($value));
        }

        if (empty($updateFields)) {
            return $this->response->setJSON([
                'code' => ApiCode::SUCCESS,
                'msg' => '没有需要更新的字段',
                'data' => null
            ]);
        }

        $sql = sprintf('
            update ee_onjob
            set %s,操作人员="%s",操作时间="%s"
            where GUID in ("%s")',
            implode(',', $updateFields),
            $userWorkid,
            date('Y-m-d H:i:s'),
            $guidStr
        );

        $num = $this->model->exec($sql);

        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg' => sprintf('批量修改成功，修改 %d 条记录', $num),
            'data' => null
        ]);
    }

    public function delete()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['guids']) || !is_array($data['guids'])) {
            return $this->response->setJSON([
                'code' => ApiCode::PARAM_ERROR,
                'msg' => '请选择要删除的人员',
                'data' => null
            ]);
        }

        $userWorkid = $this->userContext->getSessionUser()['workId'] ?: 'system';

        $guidStr = implode('","', array_map('addslashes', $data['guids']));

        $sql = sprintf('
            update ee_onjob
            set 操作记录="删除",记录结束日期="%s",
                操作来源="页面",操作人员="%s",
                结束操作时间="%s",
                删除标识="1",有效标识="0"
            where GUID in ("%s")',
            date('Y-m-d'),
            $userWorkid,
            date('Y-m-d H:i:s'),
            $guidStr
        );

        $num = $this->model->exec($sql);

        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg' => sprintf('删除成功，共删除 %d 条记录', $num),
            'data' => null
        ]);
    }

    public function options()
    {
        $locationAuthz = $this->userContext->getSessionUser()['locationAuthz'] ?: '';

        $regionSql = sprintf('
            select distinct 对象值 as value, 对象值 as label
            from def_object
            where 对象名称="属地" and 有效标识="1"
                and (属地="" or locate(属地,"%s"))
            order by convert(对象值 using gbk)',
            $locationAuthz
        );

        $regionResult = $this->model->select($regionSql)->getResultArray();

        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg' => 'Success',
            'data' => [
                'region' => $regionResult,
                'status' => [
                    ['value' => '在职', 'label' => '在职'],
                    ['value' => '离职', 'label' => '离职']
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
            ]
        ]);
    }

    private function buildTree(array $data): array
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
                'type' => 'person'
            ];

            $up1Id = sprintf('班组^%s^%s^%s^%s', $row['属地'], $row['员工状态'], $row['部门名称'], $row['班组']);
            if (!isset($up1Arr[$up1Id])) {
                $up1Arr[$up1Id] = [
                    'id' => $up1Id,
                    'value' => $row['班组'],
                    'num' => 0,
                    'items' => [],
                    'type' => 'team'
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
                    'type' => 'dept'
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
            'id' => '0级^入职人员',
            'value' => '入职人员',
            'items' => [],
            'type' => 'root'
        ];

        $csrNum = 0;
        foreach ($up4Arr as $up4) {
            $csrNum += $up4['num'];
            $csrArr['items'][] = $up4;
        }
        $csrArr['value'] = sprintf('入职人员 (%d人)', $csrNum);

        return [$csrArr];
    }
}
