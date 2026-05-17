<?php

namespace App\Controllers;

class ContractApi extends BaseApiController
{
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

        $results = $this->model->select($sql)->getResultArray();
        $total = count($results);

        return $this->success([
            'list' => $results,
            'total' => $total,
            'page' => 1,
            'pageSize' => $total
        ]);
    }

    public function detail($guid = '')
    {
        if (empty($guid)) {
            $guid = $this->getGuidFromRequest();
        }

        if (empty($guid)) {
            return $this->paramError('合同GUID不能为空');
        }

        $sql = sprintf('
            SELECT * FROM def_contract_master
            WHERE GUID = "%s" AND 删除标识 = "0" AND 有效标识 = "1"
        ', $guid);

        $result = $this->model->select($sql)->getRowArray();

        if (!$result) {
            return $this->notFound('合同不存在');
        }

        return $this->success($result);
    }

    public function create()
    {
        $data = $this->getJsonInput();

        if ($error = $this->requireParams($data, ['合同名称', '甲方名称', '乙方名称'])) {
            return $error;
        }

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
            $this->getUserWorkId(),
            date('Y-m-d H:i:s'),
            $data['记录开始日期'] ?? date('Y-m-d')
        );

        $num = $this->model->exec($insertSql);

        if ($num > 0) {
            return $this->success(['合同编号' => $合同编号], '创建合同成功');
        }

        return $this->serverError('创建合同失败');
    }

    public function update()
    {
        $data = $this->getJsonInput();

        if ($error = $this->requireParam($data, 'GUID')) {
            return $error;
        }

        $guid = $data['GUID'];

        $oldSql = sprintf('
            SELECT * FROM def_contract_master
            WHERE GUID = "%s" AND 删除标识 = "0" AND 有效标识 = "1"
        ', $guid);
        $oldRecord = $this->model->select($oldSql)->getRowArray();

        if (!$oldRecord) {
            return $this->notFound('合同不存在');
        }

        if ($oldRecord['合同状态'] !== 'DRAFT' && $oldRecord['合同状态'] !== 'REJECTED') {
            return $this->businessError('当前状态不允许修改');
        }

        $updateFields = [];
        $fieldMappings = [
            '合同名称', '合同类型', '合同金额',
            '甲方名称', '甲方联系人', '甲方电话',
            '乙方名称', '乙方联系人', '乙方电话',
            '签订日期', '开始日期', '结束日期',
            '付款方式', '备注'
        ];

        foreach ($fieldMappings as $field) {
            if (isset($data[$field])) {
                $updateFields[] = sprintf('%s = "%s"', $field, addslashes($data[$field]));
            }
        }

        if (empty($updateFields)) {
            return $this->success(null, '没有需要更新的字段');
        }

        $updateFields[] = '版本号 = 版本号 + 1';
        $updateFields[] = sprintf('操作记录 = "更新[%d]"', $oldRecord['版本号'] + 1);
        $updateFields[] = '操作来源 = "页面更新"';
        $updateFields[] = sprintf('操作人员 = "%s"', $this->getUserWorkId());
        $updateFields[] = sprintf('结束操作时间 = "%s"', date('Y-m-d H:i:s'));

        $updateSql = sprintf('
            UPDATE def_contract_master SET %s WHERE GUID = "%s"
        ', implode(', ', $updateFields), $guid);

        $num = $this->model->exec($updateSql);

        if ($num > 0) {
            return $this->success(null, '更新合同成功');
        }

        return $this->serverError('更新合同失败');
    }

    public function delete()
    {
        $data = $this->getJsonInput();

        if ($error = $this->requireParam($data, 'GUID')) {
            return $error;
        }

        $guid = $data['GUID'];

        $checkResult = $this->getContractStatus($guid);

        if (!$checkResult) {
            return $this->notFound('合同不存在');
        }

        if (!in_array($checkResult['合同状态'], ['DRAFT', 'REJECTED'])) {
            return $this->businessError('当前状态不允许删除');
        }

        $num = $this->deleteRecord('def_contract_master', sprintf('GUID = "%s"', $guid));

        if ($num > 0) {
            return $this->success(null, '删除合同成功');
        }

        return $this->serverError('删除合同失败');
    }

    public function submit()
    {
        $data = $this->getJsonInput();

        if ($error = $this->requireParam($data, 'GUID')) {
            return $error;
        }

        $guid = $data['GUID'];
        $checkResult = $this->getContractStatus($guid);

        if (!$checkResult) {
            return $this->notFound('合同不存在');
        }

        if ($checkResult['合同状态'] !== 'DRAFT' && $checkResult['合同状态'] !== 'REJECTED') {
            return $this->businessError('当前状态不允许提交审核');
        }

        $this->updateContractStatus($guid, 'PENDING', 'DEPT_APPROVAL', '提交审核');

        $this->insertFlowRecord($guid, 'submit', 'PENDING', '部门审核', '提交审核');

        return $this->success(['合同状态' => 'PENDING', '流程节点' => 'DEPT_APPROVAL'], '提交审核成功');
    }

    public function approve()
    {
        $data = $this->getJsonInput();

        if ($error = $this->requireParam($data, 'GUID')) {
            return $error;
        }

        if (!isset($data['审核意见'])) {
            return $this->paramError('审核意见不能为空');
        }

        $guid = $data['GUID'];
        $checkResult = $this->getContractStatus($guid);

        if (!$checkResult) {
            return $this->notFound('合同不存在');
        }

        if ($checkResult['合同状态'] !== 'PENDING' && $checkResult['合同状态'] !== 'APPROVING') {
            return $this->businessError('当前状态不允许审核');
        }

        $this->updateContractStatus($guid, 'APPROVED', 'FINISH', '审核通过');

        $this->insertFlowRecord($guid, 'approve', 'APPROVED', '审核完成', $data['审核意见']);

        return $this->success(['合同状态' => 'APPROVED'], '审核通过');
    }

    public function reject()
    {
        $data = $this->getJsonInput();

        if ($error = $this->requireParam($data, 'GUID')) {
            return $error;
        }

        if (!isset($data['审核意见'])) {
            return $this->paramError('审核意见不能为空');
        }

        $guid = $data['GUID'];
        $checkResult = $this->getContractStatus($guid);

        if (!$checkResult) {
            return $this->notFound('合同不存在');
        }

        if ($checkResult['合同状态'] !== 'PENDING' && $checkResult['合同状态'] !== 'APPROVING') {
            return $this->businessError('当前状态不允许审核');
        }

        $this->updateContractStatus($guid, 'REJECTED', 'REJECT', '审核拒绝');

        $this->insertFlowRecord($guid, 'reject', 'REJECTED', '审核拒绝', $data['审核意见']);

        return $this->success(['合同状态' => 'REJECTED'], '审核拒绝');
    }

    public function sign()
    {
        $data = $this->getJsonInput();

        if ($error = $this->requireParam($data, 'GUID')) {
            return $error;
        }

        $guid = $data['GUID'];
        $checkResult = $this->getContractStatus($guid);

        if (!$checkResult) {
            return $this->notFound('合同不存在');
        }

        if ($checkResult['合同状态'] !== 'APPROVED' && $checkResult['合同状态'] !== 'SIGNING') {
            return $this->businessError('当前状态不允许签署');
        }

        $this->updateContractStatus($guid, 'SIGNED', 'FINISH', '签署完成');

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
            $this->getUserWorkId(),
            $this->getUserName(),
            $data['签署公司'] ?? '',
            date('Y-m-d H:i:s'),
            $this->request->getIPAddress(),
            $this->request->getUserAgent()->getAgentString(),
            $this->getUserWorkId(),
            date('Y-m-d H:i:s'),
            $guid
        );

        $this->model->exec($signSql);

        return $this->success(['合同状态' => 'SIGNED'], '签署成功');
    }

    public function archive()
    {
        $data = $this->getJsonInput();

        if ($error = $this->requireParam($data, 'GUID')) {
            return $error;
        }

        $guid = $data['GUID'];
        $checkResult = $this->getContractStatus($guid);

        if (!$checkResult) {
            return $this->notFound('合同不存在');
        }

        if ($checkResult['合同状态'] !== 'SIGNED') {
            return $this->businessError('当前状态不允许归档');
        }

        $num = $this->updateContractStatus($guid, 'ARCHIVED', 'ARCHIVE', '归档完成');

        if ($num > 0) {
            return $this->success(['合同状态' => 'ARCHIVED'], '归档成功');
        }

        return $this->serverError('归档失败');
    }

    public function options()
    {
        $companyId = $this->userContext->getSessionUser()['companyId'];

        $合同类型Sql = sprintf('
            SELECT DISTINCT 类型名称 as value, 类型名称 as label
            FROM def_contract_type
            WHERE 删除标识 = "0" AND 有效标识 = "1" AND (公司ID = "%s" OR 公司ID = "ALL")
            ORDER BY 类型名称
        ', $companyId);
        $合同类型 = $this->model->select($合同类型Sql)->getResultArray();

        return $this->success([
            '合同类型' => $合同类型,
            '合同状态' => [
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
            ],
            '付款方式' => [
                ['value' => 'FULL', 'label' => '一次性付款'],
                ['value' => 'INSTALLMENT', 'label' => '分期付款'],
                ['value' => 'PREPAY', 'label' => '预付款'],
                ['value' => 'POSTPAY', 'label' => '后付款']
            ]
        ]);
    }

    public function flow($guid = '')
    {
        if (empty($guid)) {
            $json = $this->request->getJSON(true);
            $guid = $json['guid'] ?? $this->request->getGet('guid') ?? '';
        }

        if (empty($guid)) {
            return $this->paramError('合同GUID不能为空');
        }

        $sql = sprintf('
            SELECT * FROM def_contract_flow
            WHERE 合同编号 = (SELECT 合同编号 FROM def_contract_master WHERE GUID = "%s")
            ORDER BY 操作时间 DESC
        ', $guid);

        $results = $this->model->select($sql)->getResultArray();

        return $this->success($results);
    }

    public function stats()
    {
        $where = '删除标识 = "0" AND 有效标识 = "1"';

        $stats = [];

        $totalSql = sprintf('SELECT COUNT(*) as total FROM def_contract_master WHERE %s', $where);
        $stats['总数'] = $this->model->select($totalSql)->getRowArray()['total'] ?? 0;

        $pendingSql = sprintf('SELECT COUNT(*) as total FROM def_contract_master WHERE %s AND 合同状态 = "PENDING"', $where);
        $stats['待审核'] = $this->model->select($pendingSql)->getRowArray()['total'] ?? 0;

        $approvedSql = sprintf('SELECT COUNT(*) as total FROM def_contract_master WHERE %s AND 合同状态 = "APPROVED"', $where);
        $stats['已审核'] = $this->model->select($approvedSql)->getRowArray()['total'] ?? 0;

        $signedSql = sprintf('SELECT COUNT(*) as total FROM def_contract_master WHERE %s AND 合同状态 = "SIGNED"', $where);
        $stats['已签署'] = $this->model->select($signedSql)->getRowArray()['total'] ?? 0;

        $expiringSql = sprintf('
            SELECT COUNT(*) as total FROM def_contract_master
            WHERE %s AND 合同状态 IN ("SIGNED", "ARCHIVED", "EXECUTING")
            AND 结束日期 >= CURDATE() AND 结束日期 <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ', $where);
        $stats['即将到期'] = $this->model->select($expiringSql)->getRowArray()['total'] ?? 0;

        return $this->success($stats);
    }

    private function generateContractNo(): string
    {
        $prefix = 'HT' . date('Ymd');
        $sql = sprintf('
            SELECT MAX(CAST(SUBSTRING(合同编号, 9) AS UNSIGNED)) as max_num
            FROM def_contract_master
            WHERE 合同编号 LIKE "%s%%"
        ', $prefix);

        $result = $this->model->select($sql)->getRowArray();
        $maxNum = $result['max_num'] ?? 0;

        return $prefix . str_pad($maxNum + 1, 4, '0', STR_PAD_LEFT);
    }

    private function getContractStatus(string $guid): ?array
    {
        $sql = sprintf('
            SELECT 合同状态 FROM def_contract_master
            WHERE GUID = "%s" AND 删除标识 = "0" AND 有效标识 = "1"
        ', $guid);

        return $this->model->select($sql)->getRowArray();
    }

    private function updateContractStatus(string $guid, string $status, string $node, string $record): int
    {
        $sql = sprintf('
            UPDATE def_contract_master
            SET 合同状态 = "%s",
                流程节点 = "%s",
                操作记录 = "%s",
                操作来源 = "页面",
                操作人员 = "%s",
                结束操作时间 = "%s"
            WHERE GUID = "%s"
        ', $status, $node, $record, $this->getUserWorkId(), date('Y-m-d H:i:s'), $guid);

        return $this->model->exec($sql);
    }

    private function insertFlowRecord(string $guid, string $type, string $status, string $nodeName, string $opinion = ''): void
    {
        $sql = sprintf('
            INSERT INTO def_contract_flow
                (合同编号, 流程类型, 流程状态, 节点名称,
                 审核人, 审核人姓名, 审核意见,
                 操作来源, 操作人员, 操作时间)
            SELECT 合同编号, "%s", "%s", "%s",
                   "%s", "%s", "%s",
                   "页面", "%s", "%s"
            FROM def_contract_master WHERE GUID = "%s"
        ', $type, $status, $nodeName, $this->getUserWorkId(), $this->getUserName(), $opinion, $this->getUserWorkId(), date('Y-m-d H:i:s'), $guid);

        $this->model->exec($sql);
    }
}
