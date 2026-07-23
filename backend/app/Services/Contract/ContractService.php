<?php

namespace App\Services\Contract;

use App\Models\Mcommon;
use App\Services\Workflow\WorkflowService;

class ContractService
{
    private Mcommon $model;
    private WorkflowService $workflowService;

    public function __construct()
    {
        $this->model = new Mcommon();
        $this->workflowService = new WorkflowService();
    }

    /**
     * 合同列表查询
     *
     * @param array $params 筛选条件
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @return array ['list' => array, 'total' => int, 'page' => int, 'pageSize' => int]
     */
    public function getList(array $params, int $page = 1, int $pageSize = 20): array
    {
        $offset = ($page - 1) * $pageSize;

        $useNewTable = $this->hasNewTableData();
        $tableName = $useNewTable ? '`def_contract_master_new`' : '`def_contract_master`';

        $where = ['`删除标识`=' . $this->model->quote('0'), '`有效标识`=' . $this->model->quote('1')];

        if (!empty($params['contractNo'])) {
            $where[] = '`合同编号`=' . $this->model->quote($params['contractNo']);
        }
        if (!empty($params['contractName'])) {
            $where[] = '`合同名称` like ' . $this->model->quote('%' . $params['contractName'] . '%');
        }
        if (!empty($params['contractType'])) {
            $where[] = '`合同类型`=' . $this->model->quote($params['contractType']);
        }
        if (!empty($params['contractStatus'])) {
            $where[] = '`合同状态`=' . $this->model->quote($params['contractStatus']);
        }
        if (!empty($params['partyA'])) {
            $where[] = '`甲方名称` like ' . $this->model->quote('%' . $params['partyA'] . '%');
        }
        if (!empty($params['partyB'])) {
            $where[] = '`乙方名称` like ' . $this->model->quote('%' . $params['partyB'] . '%');
        }
        if (!empty($params['signDateStart'])) {
            $where[] = '`签订日期` >= ' . $this->model->quote($params['signDateStart']);
        }
        if (!empty($params['signDateEnd'])) {
            $where[] = '`签订日期` <= ' . $this->model->quote($params['signDateEnd']);
        }
        if (!empty($params['creator'])) {
            $where[] = '`创建人`=' . $this->model->quote($params['creator']);
        }
        if (!empty($params['deptCode'])) {
            $where[] = '`所属部门编码`=' . $this->model->quote($params['deptCode']);
        }

        $whereSql = implode(' and ', $where);

        $countSql = sprintf(
            'select count(*) as `total` from %s where %s',
            $tableName,
            $whereSql
        );
        $result = $this->model->select($countSql);
        $row = $result ? ($result->getRowArray() ?: []) : [];
        $total = (int) ($row['total'] ?? 0);

        $listSql = sprintf(
            'select * from %s where %s order by `创建时间` desc limit %d offset %d',
            $tableName,
            $whereSql,
            $pageSize,
            $offset
        );
        $result = $this->model->select($listSql);
        $list = $result ? $result->getResultArray() : [];

        return [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ];
    }

    /**
     * 合同详情
     *
     * @param string $contractNo 合同编号
     * @return array|null 合同详情数组，不存在返回 null
     */
    public function getDetail(string $contractNo): ?array
    {
        $useNewTable = $this->hasNewTableData();

        if ($useNewTable) {
            $sql = sprintf(
                'select * from `def_contract_master_new` 
                where `合同编号`=%s and `删除标识`=%s and `有效标识`=%s limit 1',
                $this->model->quote($contractNo),
                $this->model->quote('0'),
                $this->model->quote('1')
            );
        } else {
            $sql = sprintf(
                'select * from `def_contract_master` 
                where `合同编号`=%s and `删除标识`=%s and `有效标识`=%s limit 1',
                $this->model->quote($contractNo),
                $this->model->quote('0'),
                $this->model->quote('1')
            );
        }

        $result = $this->model->select($sql);
        $master = $result ? ($result->getRowArray() ?: []) : [];
        if (empty($master)) {
            return null;
        }

        $parties = [];
        if ($useNewTable) {
            $partySql = sprintf(
                'select * from `def_contract_party` 
                where `合同编号`=%s order by `序号`',
                $this->model->quote($contractNo)
            );
            $partyResult = $this->model->select($partySql);
            $parties = $partyResult ? $partyResult->getResultArray() : [];
        } else {
            $parties = [
                ['角色' => '甲方', '名称' => $master['甲方名称'] ?? '', '联系人' => $master['甲方联系人'] ?? '', '电话' => $master['甲方电话'] ?? ''],
                ['角色' => '乙方', '名称' => $master['乙方名称'] ?? '', '联系人' => $master['乙方联系人'] ?? '', '电话' => $master['乙方电话'] ?? ''],
            ];
        }

        $docSql = sprintf(
            'select * from `def_contract_document` 
            where `合同编号`=%s and `删除标识`=%s 
            order by `创建时间` desc',
            $this->model->quote($contractNo),
            $this->model->quote('0')
        );
        $docResult = $this->model->select($docSql);
        $documents = $docResult ? $docResult->getResultArray() : [];

        $versionSql = sprintf(
            'select * from `def_contract_version` 
            where `合同编号`=%s 
            order by `版本号` desc',
            $this->model->quote($contractNo)
        );
        $versionResult = $this->model->select($versionSql);
        $versions = $versionResult ? $versionResult->getResultArray() : [];

        $master['parties'] = $parties;
        $master['documents'] = $documents;
        $master['versions'] = $versions;

        return $master;
    }

    /**
     * 创建合同
     *
     * @param array $data 合同数据
     * @param string $creator 创建人工号
     * @param string $creatorName 创建人姓名
     * @param string $deptCode 部门编码
     * @param string $deptName 部门名称
     * @return array ['合同编号' => string]
     * @throws \RuntimeException
     */
    public function createContract(array $data, string $creator, string $creatorName, string $deptCode = '', string $deptName = ''): array
    {
        if (empty($data['合同名称'])) {
            throw new \RuntimeException('合同名称不能为空');
        }
        if (empty($data['甲方名称'])) {
            throw new \RuntimeException('甲方名称不能为空');
        }
        if (empty($data['乙方名称'])) {
            throw new \RuntimeException('乙方名称不能为空');
        }

        $contractNo = $this->generateContractNo();
        $now = date('Y-m-d H:i:s');

        $fields = ['`合同编号`', '`合同名称`', '`合同类型`', '`合同状态`', '`甲方名称`', '`乙方名称`', '`签订日期`', '`生效日期`', '`到期日期`', '`合同金额`', '`付款方式`', '`所属部门编码`', '`所属部门名称`', '`创建人`', '`创建人姓名`', '`创建时间`', '`更新时间`', '`版本号`', '`删除标识`', '`有效标识`'];

        $values = [
            $this->model->quote($contractNo),
            $this->model->quote($data['合同名称'] ?? ''),
            $this->model->quote($data['合同类型'] ?? ''),
            $this->model->quote('DRAFT'),
            $this->model->quote($data['甲方名称'] ?? ''),
            $this->model->quote($data['乙方名称'] ?? ''),
            $this->model->quote($data['签订日期'] ?? ''),
            $this->model->quote($data['生效日期'] ?? ''),
            $this->model->quote($data['到期日期'] ?? ''),
            $this->model->quote($data['合同金额'] ?? '0'),
            $this->model->quote($data['付款方式'] ?? ''),
            $this->model->quote($deptCode),
            $this->model->quote($deptName),
            $this->model->quote($creator),
            $this->model->quote($creatorName),
            $this->model->quote($now),
            $this->model->quote($now),
            $this->model->quote('1'),
            $this->model->quote('0'),
            $this->model->quote('1'),
        ];

        $sql = sprintf(
            'insert into `def_contract_master_new` (%s) values (%s)',
            implode(', ', $fields),
            implode(', ', $values)
        );
        $this->model->exec($sql);

        $this->recordVersion($contractNo, 1, $creator, $creatorName, '创建合同');

        return ['合同编号' => $contractNo];
    }

    /**
     * 更新合同
     *
     * @param string $contractNo 合同编号
     * @param array $data 更新数据
     * @param string $operator 操作人工号
     * @return bool
     * @throws \RuntimeException
     */
    public function updateContract(string $contractNo, array $data, string $operator): bool
    {
        $contract = $this->getDetail($contractNo);
        if (!$contract) {
            throw new \RuntimeException('合同不存在');
        }

        $status = $contract['合同状态'] ?? '';
        if (!in_array($status, ['DRAFT', 'REJECTED'], true)) {
            throw new \RuntimeException('只有草稿或已驳回状态的合同可以修改');
        }

        $oldVersion = (int) ($contract['版本号'] ?? 1);
        $newVersion = $oldVersion + 1;
        $now = date('Y-m-d H:i:s');

        $updateFields = [];
        $allowedFields = ['合同名称', '合同类型', '甲方名称', '乙方名称', '签订日期', '生效日期', '到期日期', '合同金额', '付款方式', '合同内容'];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateFields[] = sprintf('`%s`=%s', $field, $this->model->quote((string) $data[$field]));
            }
        }

        if (empty($updateFields)) {
            return false;
        }

        $updateFields[] = sprintf('`版本号`=%s', $this->model->quote((string) $newVersion));
        $updateFields[] = sprintf('`更新时间`=%s', $this->model->quote($now));
        $updateFields[] = sprintf('`更新人`=%s', $this->model->quote($operator));

        $sql = sprintf(
            'update `def_contract_master_new` set %s where `合同编号`=%s',
            implode(', ', $updateFields),
            $this->model->quote($contractNo)
        );
        $affected = $this->model->exec($sql);

        if ($affected > 0) {
            $operatorName = $data['operatorName'] ?? $operator;
            $this->recordVersion($contractNo, $newVersion, $operator, $operatorName, '修改合同');
        }

        return $affected > 0;
    }

    /**
     * 删除合同
     *
     * @param string $contractNo 合同编号
     * @param string $operator 操作人工号
     * @return bool
     * @throws \RuntimeException
     */
    public function deleteContract(string $contractNo, string $operator): bool
    {
        $contract = $this->getDetail($contractNo);
        if (!$contract) {
            throw new \RuntimeException('合同不存在');
        }

        $status = $contract['合同状态'] ?? '';
        if (!in_array($status, ['DRAFT', 'REJECTED'], true)) {
            throw new \RuntimeException('只有草稿或已驳回状态的合同可以删除');
        }

        $now = date('Y-m-d H:i:s');
        $sql = sprintf(
            'update `def_contract_master_new` 
            set `删除标识`=%s, `有效标识`=%s, `更新时间`=%s, `更新人`=%s 
            where `合同编号`=%s',
            $this->model->quote('1'),
            $this->model->quote('0'),
            $this->model->quote($now),
            $this->model->quote($operator),
            $this->model->quote($contractNo)
        );
        $affected = $this->model->exec($sql);

        return $affected > 0;
    }

    /**
     * 提交审批
     *
     * @param string $contractNo 合同编号
     * @param string $sponsor 发起人工号
     * @param string $sponsorName 发起人姓名
     * @param string $workflowCode 流程编码
     * @return array ['instanceId' => int, 'tasks' => array]
     * @throws \RuntimeException
     */
    public function submitApproval(string $contractNo, string $sponsor, string $sponsorName, string $workflowCode = 'contract_approval'): array
    {
        $contract = $this->getDetail($contractNo);
        if (!$contract) {
            throw new \RuntimeException('合同不存在');
        }

        $status = $contract['合同状态'] ?? '';
        if (!in_array($status, ['DRAFT', 'REJECTED'], true)) {
            throw new \RuntimeException('只有草稿或已驳回状态的合同可以提交审批');
        }

        $businessTitle = $contract['合同名称'] ?? '';

        $result = $this->workflowService->startProcess(
            $workflowCode,
            $contractNo,
            $businessTitle,
            $sponsor,
            $sponsorName
        );

        $instanceId = $result['instanceId'] ?? 0;
        $tasks = $result['tasks'] ?? [];

        $now = date('Y-m-d H:i:s');
        $sql = sprintf(
            'update `def_contract_master_new` 
            set `合同状态`=%s, `流程实例ID`=%s, `更新时间`=%s 
            where `合同编号`=%s',
            $this->model->quote('PENDING'),
            $this->model->quote((string) $instanceId),
            $this->model->quote($now),
            $this->model->quote($contractNo)
        );
        $this->model->exec($sql);

        return [
            'instanceId' => $instanceId,
            'tasks' => $tasks,
        ];
    }

    /**
     * 审批处理
     *
     * @param int $taskId 任务ID
     * @param string $approver 审批人工号
     * @param string $approverName 审批人姓名
     * @param string $action 审批动作（APPROVE/REJECT）
     * @param string $opinion 审批意见
     * @return array
     * @throws \RuntimeException
     */
    public function handleApproval(int $taskId, string $approver, string $approverName, string $action, string $opinion = ''): array
    {
        $taskSql = sprintf(
            'select * from `def_workflow_task` where `ID`=%d limit 1',
            $taskId
        );
        $taskResult = $this->model->select($taskSql);
        $task = $taskResult ? ($taskResult->getRowArray() ?: []) : [];
        if (empty($task)) {
            throw new \RuntimeException('审批任务不存在');
        }

        $instanceId = (int) ($task['流程实例ID'] ?? 0);

        $instanceSql = sprintf(
            'select * from `def_workflow_instance` where `ID`=%d limit 1',
            $instanceId
        );
        $instanceResult = $this->model->select($instanceSql);
        $instance = $instanceResult ? ($instanceResult->getRowArray() ?: []) : [];
        if (empty($instance)) {
            throw new \RuntimeException('流程实例不存在');
        }

        $contractNo = $instance['业务ID'] ?? '';

        $result = $this->workflowService->approve(
            $taskId,
            $approver,
            $approverName,
            $opinion,
            $action
        );

        $now = date('Y-m-d H:i:s');
        $opinionSql = sprintf(
            'insert into `def_contract_approval_opinion` 
            (`合同编号`, `流程实例ID`, `任务ID`, `节点编码`, `节点名称`, 
             `审批人`, `审批人姓名`, `审批动作`, `审批意见`, `审批时间`)
            values (%s, %d, %d, %s, %s, %s, %s, %s, %s, %s)',
            $this->model->quote($contractNo),
            $instanceId,
            $taskId,
            $this->model->quote($task['节点编码'] ?? ''),
            $this->model->quote($task['节点名称'] ?? ''),
            $this->model->quote($approver),
            $this->model->quote($approverName),
            $this->model->quote($action),
            $this->model->quote($opinion),
            $this->model->quote($now)
        );
        $this->model->exec($opinionSql);

        $instanceStatus = $result['instanceStatus'] ?? '';
        if ($instanceStatus === 'COMPLETED') {
            $updateSql = sprintf(
                'update `def_contract_master_new` 
                set `合同状态`=%s, `更新时间`=%s 
                where `合同编号`=%s',
                $this->model->quote('APPROVED'),
                $this->model->quote($now),
                $this->model->quote($contractNo)
            );
            $this->model->exec($updateSql);
        } elseif ($instanceStatus === 'REJECTED') {
            $updateSql = sprintf(
                'update `def_contract_master_new` 
                set `合同状态`=%s, `更新时间`=%s 
                where `合同编号`=%s',
                $this->model->quote('REJECTED'),
                $this->model->quote($now),
                $this->model->quote($contractNo)
            );
            $this->model->exec($updateSql);
        }

        return $result;
    }

    /**
     * 合同统计
     *
     * @param array $filters 筛选条件
     * @return array 统计数据
     */
    public function getStats(array $filters = []): array
    {
        $useNewTable = $this->hasNewTableData();
        $tableName = $useNewTable ? '`def_contract_master_new`' : '`def_contract_master`';

        $where = ['`删除标识`=' . $this->model->quote('0'), '`有效标识`=' . $this->model->quote('1')];

        if (!empty($filters['deptCode'])) {
            $where[] = '`所属部门编码`=' . $this->model->quote($filters['deptCode']);
        }
        if (!empty($filters['creator'])) {
            $where[] = '`创建人`=' . $this->model->quote($filters['creator']);
        }

        $whereSql = implode(' and ', $where);

        $statusSql = sprintf(
            'select `合同状态`, count(*) as `cnt` 
            from %s 
            where %s 
            group by `合同状态`',
            $tableName,
            $whereSql
        );
        $statusResult = $this->model->select($statusSql);
        $statusRows = $statusResult ? $statusResult->getResultArray() : [];

        $statusCount = [
            'DRAFT' => 0,
            'PENDING' => 0,
            'APPROVED' => 0,
            'REJECTED' => 0,
        ];
        foreach ($statusRows as $row) {
            $status = $row['合同状态'] ?? '';
            $statusCount[$status] = (int) ($row['cnt'] ?? 0);
        }

        $expiringSql = sprintf(
            'select count(*) as `cnt` 
            from %s 
            where %s 
            and `到期日期` != %s 
            and `到期日期` <= date_add(curdate(), interval 30 day)
            and `到期日期` >= curdate()
            and `合同状态`=%s',
            $tableName,
            $whereSql,
            $this->model->quote(''),
            $this->model->quote('APPROVED')
        );
        $expiringResult = $this->model->select($expiringSql);
        $expiringRow = $expiringResult ? ($expiringResult->getRowArray() ?: []) : [];
        $expiringCount = (int) ($expiringRow['cnt'] ?? 0);

        $monthStart = date('Y-m-01');
        $newThisMonthSql = sprintf(
            'select count(*) as `cnt` 
            from %s 
            where %s 
            and `创建时间` >= %s',
            $tableName,
            $whereSql,
            $this->model->quote($monthStart)
        );
        $newResult = $this->model->select($newThisMonthSql);
        $newRow = $newResult ? ($newResult->getRowArray() ?: []) : [];
        $newThisMonth = (int) ($newRow['cnt'] ?? 0);

        return [
            'statusCount' => $statusCount,
            'expiringCount' => $expiringCount,
            'newThisMonth' => $newThisMonth,
        ];
    }

    /**
     * 生成合同编号
     *
     * @return string 合同编号
     */
    private function generateContractNo(): string
    {
        $dateStr = date('Ymd');
        $prefix = 'HT' . $dateStr;

        $sql = sprintf(
            'select `合同编号` from `def_contract_master_new` 
            where `合同编号` like %s 
            order by `合同编号` desc limit 1',
            $this->model->quote($prefix . '%')
        );
        $result = $this->model->select($sql);
        $row = $result ? ($result->getRowArray() ?: []) : [];

        $seq = 1;
        if (!empty($row['合同编号'])) {
            $lastNo = $row['合同编号'];
            $lastSeq = (int) substr($lastNo, -4);
            $seq = $lastSeq + 1;
        }

        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * 检查合同是否存在
     *
     * @param string $contractNo 合同编号
     * @return bool
     */
    public function contractExists(string $contractNo): bool
    {
        $useNewTable = $this->hasNewTableData();
        $tableName = $useNewTable ? '`def_contract_master_new`' : '`def_contract_master`';

        $sql = sprintf(
            'select count(*) as `cnt` from %s 
            where `合同编号`=%s and `删除标识`=%s and `有效标识`=%s',
            $tableName,
            $this->model->quote($contractNo),
            $this->model->quote('0'),
            $this->model->quote('1')
        );
        $result = $this->model->select($sql);
        $row = $result ? ($result->getRowArray() ?: []) : [];

        return ((int) ($row['cnt'] ?? 0)) > 0;
    }

    /**
     * 获取合同选项
     *
     * @param string $companyId 公司ID
     * @return array 选项数据
     */
    public function getOptions(string $companyId = 'ALL'): array
    {
        $typeSql = sprintf(
            'select `类型编码` as `value`, `类型名称` as `label` 
            from `def_contract_type` 
            where `有效标识`=%s 
            order by `排序`',
            $this->model->quote('1')
        );
        $typeResult = $this->model->select($typeSql);
        $contractTypes = $typeResult ? $typeResult->getResultArray() : [];

        $statusOptions = [
            ['value' => 'DRAFT', 'label' => '草稿'],
            ['value' => 'PENDING', 'label' => '审批中'],
            ['value' => 'APPROVED', 'label' => '已通过'],
            ['value' => 'REJECTED', 'label' => '已驳回'],
        ];

        $paymentOptions = [
            ['value' => '一次性付款', 'label' => '一次性付款'],
            ['value' => '分期付款', 'label' => '分期付款'],
            ['value' => '按进度付款', 'label' => '按进度付款'],
            ['value' => '月结', 'label' => '月结'],
            ['value' => '季结', 'label' => '季结'],
            ['value' => '年结', 'label' => '年结'],
        ];

        return [
            'contractTypes' => $contractTypes,
            'statusOptions' => $statusOptions,
            'paymentOptions' => $paymentOptions,
        ];
    }

    /**
     * 判断新表是否有数据
     *
     * @return bool
     */
    private function hasNewTableData(): bool
    {
        $sql = sprintf(
            'select count(*) as `cnt` from `def_contract_master_new` 
            where `删除标识`=%s and `有效标识`=%s limit 1',
            $this->model->quote('0'),
            $this->model->quote('1')
        );
        $result = $this->model->select($sql);
        $row = $result ? ($result->getRowArray() ?: []) : [];

        return ((int) ($row['cnt'] ?? 0)) > 0;
    }

    /**
     * 记录版本变更
     *
     * @param string $contractNo 合同编号
     * @param int $version 版本号
     * @param string $operator 操作人工号
     * @param string $operatorName 操作人姓名
     * @param string $changeDesc 变更说明
     * @return void
     */
    private function recordVersion(string $contractNo, int $version, string $operator, string $operatorName, string $changeDesc = ''): void
    {
        $now = date('Y-m-d H:i:s');
        $sql = sprintf(
            'insert into `def_contract_version` 
            (`合同编号`, `版本号`, `操作人`, `操作人姓名`, `变更说明`, `创建时间`)
            values (%s, %d, %s, %s, %s, %s)',
            $this->model->quote($contractNo),
            $version,
            $this->model->quote($operator),
            $this->model->quote($operatorName),
            $this->model->quote($changeDesc),
            $this->model->quote($now)
        );
        $this->model->exec($sql);
    }
}
