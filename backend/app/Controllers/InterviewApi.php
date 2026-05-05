<?php

namespace App\Controllers;

use App\Constants\ApiCode;
use App\Models\Mcommon;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class InterviewApi extends BaseController
{
    protected $model;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->model = new Mcommon();
    }

    public function tree()
    {
        $session = \Config\Services::session();
        $menuId = $this->request->getGet('menu_id');
        $locationAuthzCond = $session->get($menuId . '-location_authz_cond');
        
        if (empty($locationAuthzCond)) {
            $locationAuthzCond = '1=1';
        }

        $sql = sprintf('
            select GUID,姓名,身份证号,手机号码,属地,
                if(mod(substr(身份证号,17,1),2)=0,"女","男") as 性别,
                招聘渠道,一次面试结果 as 面试结果,
                if(参培信息="","待参培",参培信息) as 参培信息,
                一次面试日期 as 面试日期,预约培训日期
            from ee_interview
            where %s and 有效标识="1" and 删除标识="0"
            order by 属地,field(面试结果,"未面试","通过","未通过"),
                field(参培信息,"待参培","已参培","未参培"),
                招聘渠道,预约培训日期 desc,convert(姓名 using gbk)',
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
            select GUID,姓名,身份证号,手机号码,属地,
                招聘渠道,渠道类型,渠道名称,信息来源,实习结束日期,
                面试业务,面试岗位,一次面试日期 as 面试日期,
                一次面试结果 as 面试结果,一次面试人 as 面试人,
                预约培训日期,住宿,备注说明,参培信息,
                操作记录,操作来源,操作人员,开始操作时间,结束操作时间,操作时间
            from ee_interview
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

    public function add()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['姓名'])) {
            return $this->response->setJSON([
                'code' => ApiCode::PARAM_ERROR,
                'msg' => '姓名不能为空',
                'data' => null
            ]);
        }

        $session = \Config\Services::session();
        $userWorkid = $session->get('user_workid') ?? 'system';

        $fields = [];
        $values = [];

        $data['操作记录'] = '新增';
        $data['操作来源'] = '页面新增';
        $data['操作人员'] = $userWorkid;
        $data['开始操作时间'] = date('Y-m-d H:i:s');
        $data['操作时间'] = date('Y-m-d H:i:s');
        $data['有效标识'] = '1';
        $data['删除标识'] = '0';

        foreach ($data as $key => $value) {
            if ($key === '操作') continue;
            $fields[] = $key;
            $values[] = sprintf('"%s"', addslashes($value));
        }

        $sql = sprintf(
            'insert into ee_interview (%s) values (%s)',
            implode(',', $fields),
            implode(',', $values)
        );

        $num = $this->model->exec($sql);

        if ($num > 0) {
            return $this->response->setJSON([
                'code' => ApiCode::SUCCESS,
                'msg' => '新增面试信息成功',
                'data' => null
            ]);
        }

        return $this->response->setJSON([
            'code' => ApiCode::SERVER_ERROR,
            'msg' => '新增面试信息失败',
            'data' => null
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

        $session = \Config\Services::session();
        $userWorkid = $session->get('user_workid') ?? 'system';

        $guid = $data['guid'];
        $updateFields = [];

        $data['操作记录'] = '修改';
        $data['操作来源'] = '页面修改';
        $data['操作人员'] = $userWorkid;
        $data['操作时间'] = date('Y-m-d H:i:s');

        foreach ($data as $key => $value) {
            if (in_array($key, ['guid', '操作', '人员'])) continue;
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

        $sql = sprintf(
            'update ee_interview set %s where GUID="%s"',
            implode(',', $updateFields),
            $guid
        );

        $num = $this->model->exec($sql);

        if ($num > 0) {
            return $this->response->setJSON([
                'code' => ApiCode::SUCCESS,
                'msg' => '修改面试信息成功',
                'data' => null
            ]);
        }

        return $this->response->setJSON([
            'code' => ApiCode::SERVER_ERROR,
            'msg' => '修改面试信息失败',
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

        $session = \Config\Services::session();
        $userWorkid = $session->get('user_workid') ?? 'system';

        $guidStr = implode('","', array_map('addslashes', $data['guids']));

        $sql = sprintf('
            update ee_interview
            set 操作记录="删除",操作来源="页面删除",操作人员="%s",
                结束操作时间="%s",操作时间="%s",
                删除标识="1",有效标识="0"
            where GUID in ("%s")',
            $userWorkid,
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
            $guidStr
        );

        $num = $this->model->exec($sql);

        if ($num > 0) {
            return $this->response->setJSON([
                'code' => ApiCode::SUCCESS,
                'msg' => sprintf('删除成功，共删除 %d 条记录', $num),
                'data' => null
            ]);
        }

        return $this->response->setJSON([
            'code' => ApiCode::SERVER_ERROR,
            'msg' => '删除失败',
            'data' => null
        ]);
    }

    public function transfer()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['guids']) || !is_array($data['guids'])) {
            return $this->response->setJSON([
                'code' => ApiCode::PARAM_ERROR,
                'msg' => '请选择要转入培训的人员',
                'data' => null
            ]);
        }

        if (empty($data['参培信息'])) {
            return $this->response->setJSON([
                'code' => ApiCode::PARAM_ERROR,
                'msg' => '参培信息不能为空',
                'data' => null
            ]);
        }

        $session = \Config\Services::session();
        $userWorkid = $session->get('user_workid') ?? 'system';

        $guidStr = implode('","', array_map('addslashes', $data['guids']));

        $sql = sprintf('
            update ee_interview
            set 参培信息="%s",
                操作记录="更新,参培信息",操作来源="页面",操作人员="%s",
                结束操作时间="%s",操作时间="%s"
            where GUID in ("%s")',
            $data['参培信息'],
            $userWorkid,
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
            $guidStr
        );

        $num = $this->model->exec($sql);

        if ($data['参培信息'] === '已参培') {
            $trainStatus = '在培';
            $startTime = date('Y-m-d H:i:s');

            $sql = sprintf('
                insert into ee_train (
                    姓名,身份证号,手机号码,属地,
                    培训业务,培训状态,
                    培训批次,培训老师,
                    培训开始日期,预计完成日期,
                    面试信息,
                    操作记录,操作来源,操作人员,开始操作时间,
                    有效标识,删除标识)
                select 姓名,身份证号,手机号码,属地,
                    "%s" as 培训业务,"%s" as 培训状态,
                    "%s" as 培训批次,"%s" as 培训老师,
                    "%s" as 培训开始日期,"%s" as 预计完成日期,
                    "有" as 面试信息,
                    "面试表转入","页面","%s","%s",
                    "1","0"
                from ee_interview
                where GUID in ("%s")',
                $data['培训业务'] ?? '',
                $trainStatus,
                $data['培训批次'] ?? '',
                $data['培训老师'] ?? '',
                $data['培训开始日期'] ?? '',
                $data['预计完成日期'] ?? '',
                $userWorkid,
                $startTime,
                $guidStr
            );

            $this->model->exec($sql);
        }

        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg' => sprintf('更新参培信息成功，更新 %d 条记录', $num),
            'data' => null
        ]);
    }

    public function options()
    {
        $session = \Config\Services::session();
        $locationAuthz = $session->get('user_location_authz') ?: '';

        $regionSql = sprintf('
            select distinct 对象值 as value, 对象值 as label
            from def_object
            where 对象名称="属地" and 有效标识="1"
                and (属地="" or locate(属地,"%s"))
            order by convert(对象值 using gbk)',
            $locationAuthz
        );

        $channelSql = sprintf('
            select distinct 对象值 as value, 对象值 as label
            from def_object
            where 对象名称="招聘渠道" and 有效标识="1"
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
        $channelResult = $this->model->select($channelSql)->getResultArray();
        $trainBizResult = $this->model->select($trainBizSql)->getResultArray();

        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg' => 'Success',
            'data' => [
                'region' => $regionResult,
                'channel' => $channelResult,
                'trainBiz' => $trainBizResult,
                'interviewResult' => [
                    ['value' => '通过', 'label' => '通过'],
                    ['value' => '未通过', 'label' => '未通过'],
                    ['value' => '考虑', 'label' => '考虑'],
                    ['value' => '拒绝', 'label' => '拒绝'],
                    ['value' => '未面试', 'label' => '未面试']
                ],
                'trainStatus' => [
                    ['value' => '已参培', 'label' => '已参培'],
                    ['value' => '未参培', 'label' => '未参培']
                ]
            ]
        ]);
    }

    private function buildTree(array $data): array
    {
        $up5Arr = [];
        $up4Arr = [];
        $up3Arr = [];
        $up2Arr = [];
        $up1Arr = [];

        foreach ($data as $row) {
            $eeArr = [
                'id' => sprintf('人员^%s^%s', $row['GUID'], $row['姓名']),
                'guid' => $row['GUID'],
                'name' => $row['姓名'],
                'value' => sprintf('%s (%s)', $row['姓名'], $row['面试日期']),
                'type' => 'person'
            ];

            $up1Id = sprintf('招聘渠道^%s^%s^%s^%s^%s', $row['属地'], $row['面试结果'], $row['参培信息'], $row['预约培训日期'], $row['招聘渠道']);
            if (!isset($up1Arr[$up1Id])) {
                $up1Arr[$up1Id] = [
                    'id' => $up1Id,
                    'value' => $row['招聘渠道'],
                    'num' => 0,
                    'items' => [],
                    'type' => 'channel'
                ];
            }
            $up1Arr[$up1Id]['num'] = count($up1Arr[$up1Id]['items']) + 1;
            $up1Arr[$up1Id]['value'] = sprintf('%s (%d人)', $row['招聘渠道'], $up1Arr[$up1Id]['num']);
            $up1Arr[$up1Id]['items'][] = $eeArr;
        }

        foreach ($up1Arr as $up1) {
            $arr = explode('^', $up1['id']);
            $up2Id = sprintf('培训日期^%s^%s^%s^%s', $arr[1], $arr[2], $arr[3], $arr[4]);
            if (!isset($up2Arr[$up2Id])) {
                $up2Arr[$up2Id] = [
                    'id' => $up2Id,
                    'value' => '预约培训日期 ' . $arr[4],
                    'num' => 0,
                    'items' => [],
                    'type' => 'date'
                ];
            }
            $up2Arr[$up2Id]['num'] += $up1['num'];
            $up2Arr[$up2Id]['value'] = sprintf('预约培训日期 %s (%d人)', $arr[4], $up2Arr[$up2Id]['num']);
            $up2Arr[$up2Id]['items'][] = $up1;
        }

        foreach ($up2Arr as $up2) {
            $arr = explode('^', $up2['id']);
            $up3Id = sprintf('参培信息^%s^%s^%s', $arr[1], $arr[2], $arr[3]);
            if (!isset($up3Arr[$up3Id])) {
                $up3Arr[$up3Id] = [
                    'id' => $up3Id,
                    'value' => $arr[3],
                    'num' => 0,
                    'items' => [],
                    'type' => 'train'
                ];
            }
            $up3Arr[$up3Id]['num'] += $up2['num'];
            $up3Arr[$up3Id]['value'] = sprintf('%s (%d人)', $arr[3], $up3Arr[$up3Id]['num']);
            $up3Arr[$up3Id]['items'][] = $up2;
        }

        foreach ($up3Arr as $up3) {
            $arr = explode('^', $up3['id']);
            $up4Id = sprintf('面试结果^%s^%s', $arr[1], $arr[2]);
            if (!isset($up4Arr[$up4Id])) {
                $up4Arr[$up4Id] = [
                    'id' => $up4Id,
                    'value' => $arr[2],
                    'num' => 0,
                    'items' => [],
                    'type' => 'result'
                ];
            }
            $up4Arr[$up4Id]['num'] += $up3['num'];
            $up4Arr[$up4Id]['value'] = sprintf('%s (%d人)', $arr[2], $up4Arr[$up4Id]['num']);
            $up4Arr[$up4Id]['items'][] = $up3;
        }

        foreach ($up4Arr as $up4) {
            $arr = explode('^', $up4['id']);
            $up5Id = sprintf('属地^%s', $arr[1]);
            if (!isset($up5Arr[$up5Id])) {
                $up5Arr[$up5Id] = [
                    'id' => $up5Id,
                    'value' => $arr[1],
                    'num' => 0,
                    'items' => [],
                    'type' => 'region'
                ];
            }
            $up5Arr[$up5Id]['num'] += $up4['num'];
            $up5Arr[$up5Id]['value'] = sprintf('%s (%d人)', $arr[1], $up5Arr[$up5Id]['num']);
            $up5Arr[$up5Id]['items'][] = $up4;
        }

        $csrArr = [
            'id' => '0级^面试人员',
            'value' => '面试人员',
            'items' => [],
            'type' => 'root'
        ];

        $csrNum = 0;
        foreach ($up5Arr as $up5) {
            $csrNum += $up5['num'];
            $csrArr['items'][] = $up5;
        }
        $csrArr['value'] = sprintf('面试人员 (%d人)', $csrNum);

        return [$csrArr];
    }
}
