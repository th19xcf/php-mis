<?php

namespace App\Controllers;

use App\Constants\ApiCode;
use App\Libraries\SessionUserContext;
use App\Models\Mcommon;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class StoreApi extends BaseController
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
            select 
                GUID,姓名,身份证号,性别,年龄,手机号码,
                学校,专业,现住址,属地,
                邀约结果,招聘渠道,邀约日期,邀约人,
                邀约业务,邀约岗位,预约面试日期,
                if(面试信息="","待面试",面试信息) as 面试信息
            from ee_store
            where %s and 有效标识="1" and 删除标识="0"
            order by 属地,field(邀约结果,"通过","未通过","考虑","拒绝","未邀约"),面试信息,招聘渠道,convert(姓名 using gbk)',
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
            select GUID,姓名,身份证号,手机号码,邀约次数,性别,年龄,
                学校,专业,现住址,工作履历,
                渠道类型,招聘渠道,渠道名称,
                属地,部门名称,邀约业务,邀约岗位,工作地点,
                邀约日期,邀约人,邀约结果,
                预约面试日期,面试信息,
                操作记录,操作来源,操作人员,开始操作时间,结束操作时间,操作时间
            from ee_store
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

        $userWorkid = $this->userContext->getSessionUser()['workId'] ?: 'system';

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
            'insert into ee_store (%s) values (%s)',
            implode(',', $fields),
            implode(',', $values)
        );

        $num = $this->model->exec($sql);

        if ($num > 0) {
            return $this->response->setJSON([
                'code' => ApiCode::SUCCESS,
                'msg' => '新增邀约信息成功',
                'data' => null
            ]);
        }

        return $this->response->setJSON([
            'code' => ApiCode::SERVER_ERROR,
            'msg' => '新增邀约信息失败',
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

        $userWorkid = $this->userContext->getSessionUser()['workId'] ?: 'system';

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
            'update ee_store set %s where GUID="%s"',
            implode(',', $updateFields),
            $guid
        );

        $num = $this->model->exec($sql);

        if ($num > 0) {
            return $this->response->setJSON([
                'code' => ApiCode::SUCCESS,
                'msg' => '修改邀约信息成功',
                'data' => null
            ]);
        }

        return $this->response->setJSON([
            'code' => ApiCode::SERVER_ERROR,
            'msg' => '修改邀约信息失败',
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
            update ee_store
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
                'msg' => '请选择要转入面试的人员',
                'data' => null
            ]);
        }

        if (empty($data['面试结果'])) {
            return $this->response->setJSON([
                'code' => ApiCode::PARAM_ERROR,
                'msg' => '面试结果不能为空',
                'data' => null
            ]);
        }

        $userWorkid = $this->userContext->getSessionUser()['workId'] ?: 'system';

        $guidStr = implode('","', array_map('addslashes', $data['guids']));

        $interview = '';
        switch ($data['面试结果']) {
            case '通过':
            case '未通过':
                $interview = '已面试';
                break;
            case '拒绝':
                $interview = '拒绝';
                break;
            case '未面试':
                $interview = '未面试';
                break;
            default:
                $interview = '待面试';
                break;
        }

        $sql = sprintf('
            update ee_store
            set 面试信息="%s",
                操作记录="更新,面试信息",操作来源="页面",操作人员="%s",
                结束操作时间="%s",操作时间="%s"
            where GUID in ("%s")',
            $interview,
            $userWorkid,
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
            $guidStr
        );

        $num = $this->model->exec($sql);

        if ($data['面试结果'] === '通过' || $data['面试结果'] === '未通过') {
            $sql = sprintf('
                insert into ee_interview (
                    姓名,身份证号,手机号码,属地,
                    招聘渠道,渠道类型,渠道名称,
                    面试业务,面试岗位,
                    一次面试日期,一次面试人,一次面试结果,
                    预约培训日期,邀约信息,
                    操作记录,操作来源,操作人员,开始操作时间,
                    有效标识,删除标识)
                select 姓名,身份证号,手机号码,属地,
                    招聘渠道,渠道类型,渠道名称,
                    邀约业务,邀约岗位,
                    "%s","%s","%s",
                    "%s","通过",
                    "邀约表转入","页面","%s","%s",
                    "1","0"
                from ee_store
                where GUID in ("%s")',
                $data['面试日期'] ?? '',
                $data['面试人'] ?? '',
                $data['面试结果'],
                $data['预约培训日期'] ?? '',
                $userWorkid,
                date('Y-m-d H:i:s'),
                $guidStr
            );

            $this->model->exec($sql);
        }

        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg' => sprintf('更新面试信息成功，更新 %d 条记录', $num),
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

        $channelSql = sprintf('
            select distinct 对象值 as value, 对象值 as label
            from def_object
            where 对象名称="招聘渠道" and 有效标识="1"
                and (属地="" or locate(属地,"%s"))
            order by convert(对象值 using gbk)',
            $locationAuthz
        );

        $regionResult = $this->model->select($regionSql)->getResultArray();
        $channelResult = $this->model->select($channelSql)->getResultArray();

        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg' => 'Success',
            'data' => [
                'region' => $regionResult,
                'channel' => $channelResult,
                'result' => [
                    ['value' => '通过', 'label' => '通过'],
                    ['value' => '未通过', 'label' => '未通过'],
                    ['value' => '考虑', 'label' => '考虑'],
                    ['value' => '拒绝', 'label' => '拒绝'],
                    ['value' => '未邀约', 'label' => '未邀约']
                ],
                'interviewResult' => [
                    ['value' => '通过', 'label' => '通过'],
                    ['value' => '未通过', 'label' => '未通过'],
                    ['value' => '考虑', 'label' => '考虑'],
                    ['value' => '拒绝', 'label' => '拒绝'],
                    ['value' => '未面试', 'label' => '未面试']
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
                'value' => sprintf('%s (%s)', $row['姓名'], $row['邀约日期']),
                'type' => 'person'
            ];

            $up1Id = sprintf('招聘渠道^%s^%s^%s^%s^%s', $row['属地'], $row['邀约结果'], $row['面试信息'], '', $row['招聘渠道']);
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
            $up2Id = sprintf('面试日期^%s^%s^%s^%s', $arr[1], $arr[2], $arr[3], $arr[4]);
            if (!isset($up2Arr[$up2Id])) {
                $up2Arr[$up2Id] = [
                    'id' => $up2Id,
                    'value' => '预约面试日期 ' . $arr[4],
                    'num' => 0,
                    'items' => [],
                    'type' => 'date'
                ];
            }
            $up2Arr[$up2Id]['num'] += $up1['num'];
            $up2Arr[$up2Id]['value'] = sprintf('预约面试日期 %s (%d人)', $arr[4], $up2Arr[$up2Id]['num']);
            $up2Arr[$up2Id]['items'][] = $up1;
        }

        foreach ($up2Arr as $up2) {
            $arr = explode('^', $up2['id']);
            $up3Id = sprintf('面试信息^%s^%s^%s', $arr[1], $arr[2], $arr[3]);
            if (!isset($up3Arr[$up3Id])) {
                $up3Arr[$up3Id] = [
                    'id' => $up3Id,
                    'value' => $arr[3],
                    'num' => 0,
                    'items' => [],
                    'type' => 'interview'
                ];
            }
            $up3Arr[$up3Id]['num'] += $up2['num'];
            $up3Arr[$up3Id]['value'] = sprintf('%s (%d人)', $arr[3], $up3Arr[$up3Id]['num']);
            $up3Arr[$up3Id]['items'][] = $up2;
        }

        foreach ($up3Arr as $up3) {
            $arr = explode('^', $up3['id']);
            $up4Id = sprintf('邀约结果^%s^%s', $arr[1], $arr[2]);
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
            'id' => '0级^邀约人员',
            'value' => '邀约人员',
            'items' => [],
            'type' => 'root'
        ];

        $csrNum = 0;
        foreach ($up5Arr as $up5) {
            $csrNum += $up5['num'];
            $csrArr['items'][] = $up5;
        }
        $csrArr['value'] = sprintf('邀约人员 (%d人)', $csrNum);

        return [$csrArr];
    }
}
