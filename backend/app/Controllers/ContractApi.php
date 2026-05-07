<?php

namespace App\Controllers;

use App\Constants\ApiCode;
use App\Models\Mcommon;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class ContractApi extends BaseController
{
    protected $model;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->model = new Mcommon();
    }

    /**
     * 获取合同列表（不分页）
     */
    public function list()
    {
        $where = '删除标识 = "0" AND 有效标识 = "1"';

        $sql = sprintf('
            SELECT GUID, 合同编号, 合同名称, 合同类型, 合同金额,
                   甲方名称, 乙方名称, 签订日期, 开始日期, 结束日期,
                   合同状态, 流程节点, 操作人员, 开始操作时间
            FROM def_contract_master
            WHERE %s
            ORDER BY 开始操作时间 DESC
        ', $where);

        $query = $this->model->select($sql);
        $results = $query->getResultArray();
        $total = count($results);

        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg' => 'Success',
            'data' => [
                'list' => $results,
                'total' => $total,
                'page' => 1,
                'pageSize' => $total
            ]
        ]);
    }

    /**
     * 获取合同详情
     */
    public function detail($guid = '')
    {
        if (empty($guid)) {
            $json = $this->request->getJSON(true);
            $guid = $json['guid'] ?? '';
        }

        if (empty($guid)) {
            return $this->response->setJSON([
                'code' => ApiCode::PARAM_ERROR,
                'msg' => '合同GUID不能为空',
                'data' => null
            ]);
        }

        $sql = sprintf('
            SELECT * FROM def_contract_master
            WHERE GUID = "%s" AND 删除标识 = "0" AND 有效标识 = "1"
        ', $guid);

        $query = $this->model->select($sql);
        $result = $query->getRowArray();

        if (!$result) {
            return $this->response->setJSON([
                'code' => ApiCode::NOT_FOUND,
                'msg' => '合同不存在',
                'data' => null
            ]);
        }

        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg' => 'Success',
            'data' => $result
        ]);
    }

    /**
     * 创建合同
     */
    public function create()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['合同名称'])) {
            return $this->response->setJSON([
                'code' => ApiCode::PARAM_ERROR,
                'msg' => '合同名称不能为空',
                'data' => null
            ]);
        }

        if (empty($data['甲方名称'])) {
            return $this->response->setJSON([
                'code' => ApiCode::PARAM_ERROR,
                'msg' => '甲方名称不能为空',
                'data' => null
            ]);
        }

        if (empty($data['乙方名称'])) {
            return $this->response->setJSON([
                'code' => ApiCode::PARAM_ERROR,
                'msg' => '乙方名称不能为空',
                'data' => null
            ]);
        }

        $session = \Config\Services::session();
        $userWorkid = $session->get('user_workid') ?? 'system';
        $userName = $session->get('user_name') ?? $userWorkid;

        $合同编号 = $this->generateContractNo();

        $insertSql = sprintf('
            INSERT INTO def_contract_master
                (合同编号, 合同名称, 合同类型, 合同金额,
                 甲方名称, 甲方联系人, 甲方电话,
                 乙方名称, 乙方联系人, 乙方电话,
                 签订日期, 开始日期, 结束日期,
                 付款方式, 付款节点, 备注,
                 合同状态, 流程节点,
                 合同模板ID, 版本号,
                 操作记录, 操作来源, 操作人员,
                 开始操作时间, 结束操作时间,
                 校验标识, 删除标识, 有效标识,
                 记录开始日期, 记录结束日期)
            VALUES ("%s", "%s", "%s", "%s",
                    "%s", "%s", "%s",
                    "%s", "%s", "%s",
                    "%s", "%s", "%s",
                    "%s", "%s", "%s",
                    "DRAFT", "CREATE",
                    "%s", 1,
                    "新增", "页面新增", "%s",
                    "%s", "",
                    "0", "0", "1",
                    "%s", "")
        ',
            $合同编号,
            $data['合同名称'],
            $data['合同类型'] ?? '',
            $data['合同金额'] ?? 0,
            $data['甲方名称'],
            $data['甲方联系人'] ?? '',
            $data['甲方电话'] ?? '',
            $data['乙方名称'],
            $data['乙方联系人'] ?? '',
            $data['乙方电话'] ?? '',
            $data['签订日期'] ?? date('Y-m-d'),
            $data['开始日期'] ?? '',
            $data['结束日期'] ?? '',
            $data['付款方式'] ?? '',
            $data['付款节点'] ?? '',
            $data['备注'] ?? '',
            $data['合同模板ID'] ?? '',
            $userWorkid,
            date('Y-m-d H:i:s'),
            $data['记录开始日期'] ?? date('Y-m-d')
        );

        $num = $this->model->exec($insertSql);

        if ($num > 0) {
            return $this->response->setJSON([
                'code' => ApiCode::SUCCESS,
                'msg' => '创建合同成功',
                'data' => ['合同编号' => $合同编号]
            ]);
        }

        return $this->response->setJSON([
            'code' => ApiCode::SERVER_ERROR,
            'msg' => '创建合同失败',
            'data' => null
        ]);
    }

    /**
     * 更新合同
     */
    public function update()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['GUID'])) {
            return $this->response->setJSON([
                'code' => ApiCode::PARAM_ERROR,
                'msg' => '合同GUID不能为空',
                'data' => null
            ]);
        }

        $session = \Config\Services::session();
        $userWorkid = $session->get('user_workid') ?? 'system';

        $guid = $data['GUID'];

        $oldSql = sprintf('
            SELECT * FROM def_contract_master
            WHERE GUID = "%s" AND 删除标识 = "0" AND 有效标识 = "1"
        ', $guid);
        $oldQuery = $this->model->select($oldSql);
        $oldRecord = $oldQuery->getRowArray();

        if (!$oldRecord) {
            return $this->response->setJSON([
                'code' => ApiCode::NOT_FOUND,
                'msg' => '合同不存在',
                'data' => null
            ]);
        }

        if ($oldRecord['合同状态'] !== 'DRAFT' && $oldRecord['合同状态'] !== 'REJECTED') {
            return $this->response->setJSON([
                'code' => ApiCode::BUSINESS_ERROR,
                'msg' => '当前状态不允许修改',
                'data' => null
            ]);
        }

        $updateFields = [];
        if (isset($data['合同名称']) && $data['合同名称'] !== $oldRecord['合同名称']) {
            $updateFields[] = sprintf('合同名称 = "%s"', $data['合同名称']);
        }
        if (isset($data['合同类型'])) {
            $updateFields[] = sprintf('合同类型 = "%s"', $data['合同类型']);
        }
        if (isset($data['合同金额'])) {
            $updateFields[] = sprintf('合同金额 = "%s"', $data['合同金额']);
        }
        if (isset($data['甲方名称'])) {
            $updateFields[] = sprintf('甲方名称 = "%s"', $data['甲方名称']);
        }
        if (isset($data['甲方联系人'])) {
            $updateFields[] = sprintf('甲方联系人 = "%s"', $data['甲方联系人']);
        }
        if (isset($data['甲方电话'])) {
            $updateFields[] = sprintf('甲方电话 = "%s"', $data['甲方电话']);
        }
        if (isset($data['乙方名称'])) {
            $updateFields[] = sprintf('乙方名称 = "%s"', $data['乙方名称']);
        }
        if (isset($data['乙方联系人'])) {
            $updateFields[] = sprintf('乙方联系人 = "%s"', $data['乙方联系人']);
        }
        if (isset($data['乙方电话'])) {
            $updateFields[] = sprintf('乙方电话 = "%s"', $data['乙方电话']);
        }
        if (isset($data['签订日期'])) {
            $updateFields[] = sprintf('签订日期 = "%s"', $data['签订日期']);
        }
        if (isset($data['开始日期'])) {
            $updateFields[] = sprintf('开始日期 = "%s"', $data['开始日期']);
        }
        if (isset($data['结束日期'])) {
            $updateFields[] = sprintf('结束日期 = "%s"', $data['结束日期']);
        }
        if (isset($data['付款方式'])) {
            $updateFields[] = sprintf('付款方式 = "%s"', $data['付款方式']);
        }
        if (isset($data['备注'])) {
            $updateFields[] = sprintf('备注 = "%s"', $data['备注']);
        }

        if (empty($updateFields)) {
            return $this->response->setJSON([
                'code' => ApiCode::SUCCESS,
                'msg' => '没有需要更新的字段',
                'data' => null
            ]);
        }

        $updateFields[] = sprintf('版本号 = 版本号 + 1');
        $updateFields[] = sprintf('操作记录 = "更新[%d]"', $oldRecord['版本号'] + 1);
        $updateFields[] = sprintf('操作来源 = "页面更新"');
        $updateFields[] = sprintf('操作人员 = "%s"', $userWorkid);
        $updateFields[] = sprintf('结束操作时间 = "%s"', date('Y-m-d H:i:s'));

        $updateSql = sprintf('
            UPDATE def_contract_master
            SET %s
            WHERE GUID = "%s"
        ', implode(', ', $updateFields), $guid);

        $num = $this->model->exec($updateSql);

        if ($num > 0) {
            return $this->response->setJSON([
                'code' => ApiCode::SUCCESS,
                'msg' => '更新合同成功',
                'data' => null
            ]);
        }

        return $this->response->setJSON([
            'code' => ApiCode::SERVER_ERROR,
            'msg' => '更新合同失败',
            'data' => null
        ]);
    }

    /**
     * 删除合同（软删除）
     */
    public function delete()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['GUID'])) {
            return $this->response->setJSON([
                'code' => ApiCode::PARAM_ERROR,
                'msg' => '合同GUID不能为空',
                'data' => null
            ]);
        }

        $guid = $data['GUID'];

        $checkSql = sprintf('
            SELECT 合同状态 FROM def_contract_master
            WHERE GUID = "%s" AND 删除标识 = "0" AND 有效标识 = "1"
        ', $guid);
        $checkQuery = $this->model->select($checkSql);
        $checkResult = $checkQuery->getRowArray();

        if (!$checkResult) {
            return $this->response->setJSON([
                'code' => ApiCode::NOT_FOUND,
                'msg' => '合同不存在',
                'data' => null
            ]);
        }

        if (!in_array($checkResult['合同状态'], ['DRAFT', 'REJECTED'])) {
            return $this->response->setJSON([
                'code' => ApiCode::BUSINESS_ERROR,
                'msg' => '当前状态不允许删除',
                'data' => null
            ]);
        }

        $session = \Config\Services::session();
        $userWorkid = $session->get('user_workid') ?? 'system';

        $deleteSql = sprintf('
            UPDATE def_contract_master
            SET 记录结束日期 = "%s",
                操作记录 = "删除",
                操作来源 = "页面",
                操作人员 = "%s",
                结束操作时间 = "%s",
                删除标识 = "1",
                有效标识 = "0"
            WHERE GUID = "%s"
        ', date('Y-m-d'), $userWorkid, date('Y-m-d H:i:s'), $guid);

        $num = $this->model->exec($deleteSql);

        if ($num > 0) {
            return $this->response->setJSON([
                'code' => ApiCode::SUCCESS,
                'msg' => '删除合同成功',
                'data' => null
            ]);
        }

        return $this->response->setJSON([
            'code' => ApiCode::SERVER_ERROR,
            'msg' => '删除合同失败',
            'data' => null
        ]);
    }

    /**
     * 提交审核
     */
    public function submit()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['GUID'])) {
            return $this->response->setJSON([
                'code' => ApiCode::PARAM_ERROR,
                'msg' => '合同GUID不能为空',
                'data' => null
            ]);
        }

        $guid = $data['GUID'];

        $checkSql = sprintf('
            SELECT 合同状态 FROM def_contract_master
            WHERE GUID = "%s" AND 删除标识 = "0" AND 有效标识 = "1"
        ', $guid);
        $checkQuery = $this->model->select($checkSql);
        $checkResult = $checkQuery->getRowArray();

        if (!$checkResult) {
            return $this->response->setJSON([
                'code' => ApiCode::NOT_FOUND,
                'msg' => '合同不存在',
                'data' => null
            ]);
        }

        if ($checkResult['合同状态'] !== 'DRAFT' && $checkResult['合同状态'] !== 'REJECTED') {
            return $this->response->setJSON([
                'code' => ApiCode::BUSINESS_ERROR,
                'msg' => '当前状态不允许提交审核',
                'data' => null
            ]);
        }

        $session = \Config\Services::session();
        $userWorkid = $session->get('user_workid') ?? 'system';
        $userName = $session->get('user_name') ?? $userWorkid;

        $updateSql = sprintf('
            UPDATE def_contract_master
            SET 合同状态 = "PENDING",
                流程节点 = "DEPT_APPROVAL",
                操作记录 = "提交审核",
                操作来源 = "页面",
                操作人员 = "%s",
                结束操作时间 = "%s"
            WHERE GUID = "%s"
        ', $userWorkid, date('Y-m-d H:i:s'), $guid);

        $this->model->exec($updateSql);

        $flowSql = sprintf('
            INSERT INTO def_contract_flow
                (合同编号, 流程类型, 流程状态, 节点名称,
                 审核人, 审核人姓名, 审核意见,
                 操作来源, 操作人员, 操作时间)
            SELECT 合同编号, "submit", "PENDING", "部门审核",
                   "%s", "%s", "提交审核",
                   "页面", "%s", "%s"
            FROM def_contract_master WHERE GUID = "%s"
        ', $userWorkid, $userName, $userWorkid, date('Y-m-d H:i:s'), $guid);

        $this->model->exec($flowSql);

        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg' => '提交审核成功',
            'data' => ['合同状态' => 'PENDING', '流程节点' => 'DEPT_APPROVAL']
        ]);
    }

    /**
     * 审核通过
     */
    public function approve()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['GUID'])) {
            return $this->response->setJSON([
                'code' => ApiCode::PARAM_ERROR,
                'msg' => '合同GUID不能为空',
                'data' => null
            ]);
        }

        if (!isset($data['审核意见'])) {
            return $this->response->setJSON([
                'code' => ApiCode::PARAM_ERROR,
                'msg' => '审核意见不能为空',
                'data' => null
            ]);
        }

        $guid = $data['GUID'];

        $checkSql = sprintf('
            SELECT 合同状态 FROM def_contract_master
            WHERE GUID = "%s" AND 删除标识 = "0" AND 有效标识 = "1"
        ', $guid);
        $checkQuery = $this->model->select($checkSql);
        $checkResult = $checkQuery->getRowArray();

        if (!$checkResult) {
            return $this->response->setJSON([
                'code' => ApiCode::NOT_FOUND,
                'msg' => '合同不存在',
                'data' => null
            ]);
        }

        if ($checkResult['合同状态'] !== 'PENDING' && $checkResult['合同状态'] !== 'APPROVING') {
            return $this->response->setJSON([
                'code' => ApiCode::BUSINESS_ERROR,
                'msg' => '当前状态不允许审核',
                'data' => null
            ]);
        }

        $session = \Config\Services::session();
        $userWorkid = $session->get('user_workid') ?? 'system';
        $userName = $session->get('user_name') ?? $userWorkid;

        $updateSql = sprintf('
            UPDATE def_contract_master
            SET 合同状态 = "APPROVED",
                流程节点 = "FINISH",
                操作记录 = "审核通过",
                操作来源 = "页面",
                操作人员 = "%s",
                结束操作时间 = "%s"
            WHERE GUID = "%s"
        ', $userWorkid, date('Y-m-d H:i:s'), $guid);

        $this->model->exec($updateSql);

        $flowSql = sprintf('
            INSERT INTO def_contract_flow
                (合同编号, 流程类型, 流程状态, 节点名称,
                 审核人, 审核人姓名, 审核意见,
                 操作来源, 操作人员, 操作时间)
            SELECT 合同编号, "approve", "APPROVED", "审核完成",
                   "%s", "%s", "%s",
                   "页面", "%s", "%s"
            FROM def_contract_master WHERE GUID = "%s"
        ', $userWorkid, $userName, $data['审核意见'], $userWorkid, date('Y-m-d H:i:s'), $guid);

        $this->model->exec($flowSql);

        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg' => '审核通过',
            'data' => ['合同状态' => 'APPROVED']
        ]);
    }

    /**
     * 审核拒绝
     */
    public function reject()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['GUID'])) {
            return $this->response->setJSON([
                'code' => ApiCode::PARAM_ERROR,
                'msg' => '合同GUID不能为空',
                'data' => null
            ]);
        }

        if (!isset($data['审核意见'])) {
            return $this->response->setJSON([
                'code' => ApiCode::PARAM_ERROR,
                'msg' => '审核意见不能为空',
                'data' => null
            ]);
        }

        $guid = $data['GUID'];

        $checkSql = sprintf('
            SELECT 合同状态 FROM def_contract_master
            WHERE GUID = "%s" AND 删除标识 = "0" AND 有效标识 = "1"
        ', $guid);
        $checkQuery = $this->model->select($checkSql);
        $checkResult = $checkQuery->getRowArray();

        if (!$checkResult) {
            return $this->response->setJSON([
                'code' => ApiCode::NOT_FOUND,
                'msg' => '合同不存在',
                'data' => null
            ]);
        }

        if ($checkResult['合同状态'] !== 'PENDING' && $checkResult['合同状态'] !== 'APPROVING') {
            return $this->response->setJSON([
                'code' => ApiCode::BUSINESS_ERROR,
                'msg' => '当前状态不允许审核',
                'data' => null
            ]);
        }

        $session = \Config\Services::session();
        $userWorkid = $session->get('user_workid') ?? 'system';
        $userName = $session->get('user_name') ?? $userWorkid;

        $updateSql = sprintf('
            UPDATE def_contract_master
            SET 合同状态 = "REJECTED",
                流程节点 = "REJECT",
                操作记录 = "审核拒绝",
                操作来源 = "页面",
                操作人员 = "%s",
                结束操作时间 = "%s"
            WHERE GUID = "%s"
        ', $userWorkid, date('Y-m-d H:i:s'), $guid);

        $this->model->exec($updateSql);

        $flowSql = sprintf('
            INSERT INTO def_contract_flow
                (合同编号, 流程类型, 流程状态, 节点名称,
                 审核人, 审核人姓名, 审核意见,
                 操作来源, 操作人员, 操作时间)
            SELECT 合同编号, "reject", "REJECTED", "审核拒绝",
                   "%s", "%s", "%s",
                   "页面", "%s", "%s"
            FROM def_contract_master WHERE GUID = "%s"
        ', $userWorkid, $userName, $data['审核意见'], $userWorkid, date('Y-m-d H:i:s'), $guid);

        $this->model->exec($flowSql);

        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg' => '审核拒绝',
            'data' => ['合同状态' => 'REJECTED']
        ]);
    }

    /**
     * 签署合同
     */
    public function sign()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['GUID'])) {
            return $this->response->setJSON([
                'code' => ApiCode::PARAM_ERROR,
                'msg' => '合同GUID不能为空',
                'data' => null
            ]);
        }

        $guid = $data['GUID'];

        $checkSql = sprintf('
            SELECT 合同状态 FROM def_contract_master
            WHERE GUID = "%s" AND 删除标识 = "0" AND 有效标识 = "1"
        ', $guid);
        $checkQuery = $this->model->select($checkSql);
        $checkResult = $checkQuery->getRowArray();

        if (!$checkResult) {
            return $this->response->setJSON([
                'code' => ApiCode::NOT_FOUND,
                'msg' => '合同不存在',
                'data' => null
            ]);
        }

        if ($checkResult['合同状态'] !== 'APPROVED' && $checkResult['合同状态'] !== 'SIGNING') {
            return $this->response->setJSON([
                'code' => ApiCode::BUSINESS_ERROR,
                'msg' => '当前状态不允许签署',
                'data' => null
            ]);
        }

        $session = \Config\Services::session();
        $userWorkid = $session->get('user_workid') ?? 'system';
        $userName = $session->get('user_name') ?? $userWorkid;

        $updateSql = sprintf('
            UPDATE def_contract_master
            SET 合同状态 = "SIGNED",
                流程节点 = "FINISH",
                操作记录 = "签署完成",
                操作来源 = "页面",
                操作人员 = "%s",
                结束操作时间 = "%s"
            WHERE GUID = "%s"
        ', $userWorkid, date('Y-m-d H:i:s'), $guid);

        $this->model->exec($updateSql);

        $signSql = sprintf('
            INSERT INTO def_contract_sign
                (合同编号, 签署人, 签署人姓名, 签署公司,
                 签署时间, 签署状态, 签署方式,
                 签署IP, 签署设备,
                 操作来源, 操作人员, 操作时间)
            SELECT 合同编号, "%s", "%s", "%s",
                   "%s", "SIGNED", "electronic",
                   "%s", "%s",
                   "页面", "%s", "%s"
            FROM def_contract_master WHERE GUID = "%s"
        ',
            $userWorkid,
            $userName,
            $data['签署公司'] ?? '',
            date('Y-m-d H:i:s'),
            $this->request->getIPAddress(),
            $this->request->getUserAgent()->getAgentString(),
            $userWorkid,
            date('Y-m-d H:i:s'),
            $guid
        );

        $this->model->exec($signSql);

        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg' => '签署成功',
            'data' => ['合同状态' => 'SIGNED']
        ]);
    }

    /**
     * 归档合同
     */
    public function archive()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['GUID'])) {
            return $this->response->setJSON([
                'code' => ApiCode::PARAM_ERROR,
                'msg' => '合同GUID不能为空',
                'data' => null
            ]);
        }

        $guid = $data['GUID'];

        $checkSql = sprintf('
            SELECT 合同状态 FROM def_contract_master
            WHERE GUID = "%s" AND 删除标识 = "0" AND 有效标识 = "1"
        ', $guid);
        $checkQuery = $this->model->select($checkSql);
        $checkResult = $checkQuery->getRowArray();

        if (!$checkResult) {
            return $this->response->setJSON([
                'code' => ApiCode::NOT_FOUND,
                'msg' => '合同不存在',
                'data' => null
            ]);
        }

        if ($checkResult['合同状态'] !== 'SIGNED') {
            return $this->response->setJSON([
                'code' => ApiCode::BUSINESS_ERROR,
                'msg' => '当前状态不允许归档',
                'data' => null
            ]);
        }

        $session = \Config\Services::session();
        $userWorkid = $session->get('user_workid') ?? 'system';

        $updateSql = sprintf('
            UPDATE def_contract_master
            SET 合同状态 = "ARCHIVED",
                流程节点 = "ARCHIVE",
                操作记录 = "归档完成",
                操作来源 = "页面",
                操作人员 = "%s",
                结束操作时间 = "%s"
            WHERE GUID = "%s"
        ', $userWorkid, date('Y-m-d H:i:s'), $guid);

        $num = $this->model->exec($updateSql);

        if ($num > 0) {
            return $this->response->setJSON([
                'code' => ApiCode::SUCCESS,
                'msg' => '归档成功',
                'data' => ['合同状态' => 'ARCHIVED']
            ]);
        }

        return $this->response->setJSON([
            'code' => ApiCode::SERVER_ERROR,
            'msg' => '归档失败',
            'data' => null
        ]);
    }

    /**
     * 获取选项数据
     */
    public function options()
    {
        $session = \Config\Services::session();
        $companyId = $session->get('company_id');

        $合同类型Sql = sprintf('
            SELECT DISTINCT 类型名称 as value, 类型名称 as label
            FROM def_contract_type
            WHERE 删除标识 = "0" AND 有效标识 = "1" AND (公司ID = "%s" OR 公司ID = "ALL")
            ORDER BY 类型名称
        ', $companyId);
        $合同类型Query = $this->model->select($合同类型Sql);
        $合同类型 = $合同类型Query->getResultArray();

        $合同状态 = [
            ['value' => 'DRAFT', 'label' => '草稿'],
            ['value' => 'PENDING', 'label' => '待审核'],
            ['value' => 'APPROVING', 'label' => '审核中'],
            ['value' => 'APPROVED', 'label' => '已审核'],
            ['value' => 'REJECTED', 'label' => '已拒绝'],
            ['value' => 'SIGNING', 'label' => '签署中'],
            ['value' => 'SIGNED', 'label' => '已签署'],
            ['value' => 'ARCHIVED', 'label' => '已归档'],
            ['value' => 'EXECUTING', 'label' => '执行中'],
            ['value' => 'TERMINATED', 'label' => '已终止'],
            ['value' => 'EXPIRED', 'label' => '已到期']
        ];

        $付款方式 = [
            ['value' => 'FULL', 'label' => '一次性付款'],
            ['value' => 'INSTALLMENT', 'label' => '分期付款'],
            ['value' => 'PREPAY', 'label' => '预付款'],
            ['value' => 'POSTPAY', 'label' => '后付款']
        ];

        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg' => 'Success',
            'data' => [
                '合同类型' => $合同类型,
                '合同状态' => $合同状态,
                '付款方式' => $付款方式
            ]
        ]);
    }

    /**
     * 获取流程历史
     */
    public function flow($guid = '')
    {
        if (empty($guid)) {
            $json = $this->request->getJSON(true);
            $guid = $json['guid'] ?? '';
        }

        if (empty($guid)) {
            return $this->response->setJSON([
                'code' => ApiCode::PARAM_ERROR,
                'msg' => '合同GUID不能为空',
                'data' => null
            ]);
        }

        $sql = sprintf('
            SELECT * FROM def_contract_flow
            WHERE 合同编号 = (SELECT 合同编号 FROM def_contract_master WHERE GUID = "%s")
            ORDER BY 操作时间 DESC
        ', $guid);

        $query = $this->model->select($sql);
        $results = $query->getResultArray();

        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg' => 'Success',
            'data' => $results
        ]);
    }

    /**
     * 获取统计
     */
    public function stats()
    {
        $session = \Config\Services::session();
        $companyId = $session->get('company_id');

        $where = sprintf('删除标识 = "0" AND 有效标识 = "1"');

        $stats = [];

        $totalSql = sprintf('SELECT COUNT(*) as total FROM def_contract_master WHERE %s', $where);
        $totalQuery = $this->model->select($totalSql);
        $stats['总数'] = $totalQuery->getRowArray()['total'] ?? 0;

        $pendingSql = sprintf('SELECT COUNT(*) as total FROM def_contract_master WHERE %s AND 合同状态 = "PENDING"', $where);
        $pendingQuery = $this->model->select($pendingSql);
        $stats['待审核'] = $pendingQuery->getRowArray()['total'] ?? 0;

        $approvedSql = sprintf('SELECT COUNT(*) as total FROM def_contract_master WHERE %s AND 合同状态 = "APPROVED"', $where);
        $approvedQuery = $this->model->select($approvedSql);
        $stats['已审核'] = $approvedQuery->getRowArray()['total'] ?? 0;

        $signedSql = sprintf('SELECT COUNT(*) as total FROM def_contract_master WHERE %s AND 合同状态 = "SIGNED"', $where);
        $signedQuery = $this->model->select($signedSql);
        $stats['已签署'] = $signedQuery->getRowArray()['total'] ?? 0;

        $expiringSql = sprintf('
            SELECT COUNT(*) as total FROM def_contract_master
            WHERE %s AND 合同状态 IN ("SIGNED", "ARCHIVED", "EXECUTING")
            AND 结束日期 >= CURDATE() AND 结束日期 <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ', $where);
        $expiringQuery = $this->model->select($expiringSql);
        $stats['即将到期'] = $expiringQuery->getRowArray()['total'] ?? 0;

        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg' => 'Success',
            'data' => $stats
        ]);
    }

    /**
     * 生成合同编号
     */
    private function generateContractNo(): string
    {
        $prefix = 'HT' . date('Ymd');
        $sql = sprintf('
            SELECT MAX(CAST(SUBSTRING(合同编号, 9) AS UNSIGNED)) as max_num
            FROM def_contract_master
            WHERE 合同编号 LIKE "%s%%"
        ', $prefix);

        $query = $this->model->select($sql);
        $result = $query->getRowArray();
        $maxNum = $result['max_num'] ?? 0;

        return $prefix . str_pad($maxNum + 1, 4, '0', STR_PAD_LEFT);
    }
}
