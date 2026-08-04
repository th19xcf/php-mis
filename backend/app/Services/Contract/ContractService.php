<?php

namespace App\Services\Contract;

use App\Libraries\MetadataCache;
use App\Models\Mcommon;
use App\Services\Workflow\WorkflowService;
use App\Services\Workbench\WorkbenchSqlHelper;

class ContractService
{
    private Mcommon $model;
    private WorkflowService $workflowService;
    private MetadataCache $metadataCache;

    public function __construct()
    {
        $this->model = new Mcommon();
        $this->workflowService = new WorkflowService();
        $this->metadataCache = new MetadataCache();
    }

    /**
     * 根据合同类型解析审批流程编码（方案 C）
     *
     * 三层回退策略：
     * 1. 合同类型映射的流程编码非空 → 使用映射值
     * 2. 映射为空或合同类型不存在 → 回退到默认 'contract_approval'
     * 3. 由调用方（WorkflowService::startProcess）负责校验流程定义是否存在
     *
     * @param string $contractType 合同类型编码（def_contract_type.类型编码）
     * @return string 流程编码
     */
    private function resolveWorkflowCode(string $contractType): string
    {
        $defaultCode = 'contract_approval';

        if ($contractType === '') {
            return $defaultCode;
        }

        $sql = sprintf(
            'select `流程编码` from `def_contract_type`
            where `类型编码`=%s and `有效标识`=%s
            limit 1',
            $this->model->quote($contractType),
            $this->model->quote('1')
        );
        $result = $this->model->select($sql);
        $row = $result ? ($result->getRowArray() ?: []) : [];

        $mappedCode = trim((string) ($row['流程编码'] ?? ''));
        return $mappedCode !== '' ? $mappedCode : $defaultCode;
    }

    /**
     * 合同列表查询
     *
     * @param array $params 筛选条件（兼容旧版硬编码字段：contractNo/contractName/...）
     * @param int $page 页码
     * @param int $pageSize 每页条数
     * @param array $filters 元数据驱动的筛选条件数组，与通用工作台 filters 协议一致：
     *                       [{fieldKey, operator, value}, ...]
     *                       operator ∈ contains/equals/startsWith/endsWith/greaterThan/.../isNull/isNotNull
     * @return array ['list' => array, 'total' => int, 'page' => int, 'pageSize' => int]
     */
    public function getList(array $params, int $page = 1, int $pageSize = 20, array $filters = []): array
    {
        $offset = ($page - 1) * $pageSize;

        $useNewTable = $this->hasNewTableData();
        $tableName = $useNewTable ? '`def_contract_master_new`' : '`def_contract_master`';

        $where = ['`删除标识`=' . $this->model->quote('0'), '`有效标识`=' . $this->model->quote('1')];

        // 兼容旧版硬编码字段筛选（向后兼容，逐步迁移到 filters 数组）
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
            $where[] = '`所属部门`=' . $this->model->quote($params['deptCode']);
        }

        // 元数据驱动的 filters 数组（与通用工作台 QueryService::buildWhereConditions 对齐）
        // 仅支持单条件 {fieldKey, operator, value} 形态；fieldOrFilter / globalSearch 不在此处理
        if (!empty($filters)) {
            // 加载列映射（用于把 fieldKey 解析为 SQL 字段名）
            $columnMap = $this->buildContractColumnMap();

            foreach ($filters as $filter) {
                $fieldKey = (string) ($filter['fieldKey'] ?? '');
                $operator = (string) ($filter['operator'] ?? '');
                $value = (string) ($filter['value'] ?? '');

                if ($fieldKey === '' || !isset($columnMap[$fieldKey])) {
                    continue;
                }
                // isNull / isNotNull 不需要 value
                $isNullOp = in_array($operator, ['isNull', 'isNotNull'], true);
                if (!$isNullOp && $value === '') {
                    continue;
                }

                $fieldName = (string) ($columnMap[$fieldKey]['字段名'] ?? '');
                if ($fieldName === '') {
                    $fieldName = '`' . $fieldKey . '`';
                } else {
                    // 字段名已包含反引号则原样使用，否则补反引号
                    $fieldName = (strpos($fieldName, '`') === 0) ? $fieldName : '`' . $fieldName . '`';
                }

                $where[] = WorkbenchSqlHelper::buildSingleCondition($this->model, $fieldName, $operator, $value);
            }
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
                where `合同编号`=%s order by `GUID`',
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

        $fields = ['`合同编号`', '`合同名称`', '`合同类型`', '`合同状态`', '`甲方名称`', '`乙方名称`', '`签订日期`', '`开始日期`', '`结束日期`', '`合同金额`', '`付款方式`', '`所属部门`', '`所属部门名称`', '`创建人`', '`创建时间`', '`更新人`', '`更新时间`', '`版本号`', '`删除标识`', '`有效标识`'];

        $values = [
            $this->model->quote($contractNo),
            $this->model->quote($data['合同名称'] ?? ''),
            $this->model->quote($data['合同类型'] ?? ''),
            $this->model->quote('DRAFT'),
            $this->model->quote($data['甲方名称'] ?? ''),
            $this->model->quote($data['乙方名称'] ?? ''),
            empty($data['签订日期']) ? 'NULL' : $this->model->quote($data['签订日期']),
            empty($data['开始日期']) ? 'NULL' : $this->model->quote($data['开始日期']),
            empty($data['结束日期']) ? 'NULL' : $this->model->quote($data['结束日期']),
            $this->model->quote($data['合同金额'] ?? '0'),
            $this->model->quote($data['付款方式'] ?? ''),
            $this->model->quote($deptCode),
            $this->model->quote($deptName),
            $this->model->quote($creator),
            $this->model->quote($now),
            $this->model->quote($creator),
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
        $allowedFields = ['合同名称', '合同类型', '甲方名称', '乙方名称', '签订日期', '开始日期', '结束日期', '合同金额', '付款方式', '备注'];
        $dateFields = ['签订日期', '开始日期', '结束日期'];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                if (in_array($field, $dateFields, true)) {
                    $updateFields[] = sprintf('`%s`=%s', $field, empty($data[$field]) ? 'NULL' : $this->model->quote((string) $data[$field]));
                } else {
                    $updateFields[] = sprintf('`%s`=%s', $field, $this->model->quote((string) $data[$field]));
                }
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
     * 流程编码通过合同类型映射查询（方案 C）：
     * 1. 根据合同.合同类型 查 def_contract_type.流程编码
     * 2. 流程编码非空则使用，否则回退到默认 'contract_approval'
     *
     * @param string $contractNo 合同编号
     * @param string $sponsor 发起人工号
     * @param string $sponsorName 发起人姓名
     * @return array ['instanceId' => int, 'tasks' => array]
     * @throws \RuntimeException
     */
    public function submitApproval(string $contractNo, string $sponsor, string $sponsorName): array
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

        // 根据合同类型查询映射的流程编码
        $contractType = (string) ($contract['合同类型'] ?? '');
        $workflowCode = $this->resolveWorkflowCode($contractType);

        $result = $this->workflowService->startProcess(
            $workflowCode,
            'CONTRACT',
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
            $where[] = '`所属部门`=' . $this->model->quote($filters['deptCode']);
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
            and `结束日期` is not null
            and `结束日期` != 0
            and `结束日期` <= date_add(curdate(), interval 30 day)
            and `结束日期` >= curdate()
            and `合同状态`=%s',
            $tableName,
            $whereSql,
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
            'select `类型编码` as `value`, `类型名称` as `label`, `流程编码` as `workflowCode`
            from `def_contract_type`
            where `有效标识`=%s
            order by `排序号`',
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
            (`合同编号`, `版本号`, `操作来源`, `操作人员`, `变更说明`, `创建人`, `创建时间`)
            values (%s, %d, %s, %s, %s, %s, %s)',
            $this->model->quote($contractNo),
            $version,
            $this->model->quote('SYSTEM'),
            $this->model->quote($operator),
            $this->model->quote($changeDesc),
            $this->model->quote($operator),
            $this->model->quote($now)
        );
        $this->model->exec($sql);
    }

    /**
     * 上传合同文档
     *
     * @param string $contractNo 合同编号
     * @param \CodeIgniter\HTTP\Files\UploadedFile $file 上传的文件
     * @param string $docType 文档类型(MAIN/APPROVAL_FORM/ATTACHMENT/SUPPLEMENT)
     * @param string $docName 文档名称
     * @param string $creator 创建人工号
     * @param string $creatorName 创建人姓名
     * @return array 文档信息
     * @throws \RuntimeException
     */
    public function uploadDocument(string $contractNo, $file, string $docType, string $docName, string $creator, string $creatorName): array
    {
        if (!$this->contractExists($contractNo)) {
            throw new \RuntimeException('合同不存在');
        }

        if (!$file || !$file->isValid()) {
            throw new \RuntimeException('上传文件无效');
        }

        $allowedExts = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'pdf', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'zip', 'rar'];
        $fileExt = strtolower($file->getExtension());
        if (!in_array($fileExt, $allowedExts, true)) {
            throw new \RuntimeException('不支持的文件格式');
        }

        $maxSize = 50 * 1024 * 1024;
        if ($file->getSize() > $maxSize) {
            throw new \RuntimeException('文件大小不能超过50MB');
        }

        $storageDir = WRITEPATH . 'contract_docs';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        $now = date('Y-m-d H:i:s');
        $fileSize = $file->getSize();
        $originalName = $docName ?: $file->getName();
        $fileMd5 = md5_file($file->getTempName());

        $docNo = 'CDOC' . date('YmdHis') . rand(1000, 9999);

        // 可在线编辑的文件格式
        $editableExts = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];
        $canEditOnline = in_array($fileExt, $editableExts, true) ? '1' : '0';

        $fields = [
            '`文档编号`', '`合同编号`', '`文档名称`', '`文档类型`', '`文档格式`',
            '`文件大小`', '`文件MD5`', '`版本号`', '`最新版本`',
            '`是否在线编辑`', '`编辑状态`',
            '`创建人`', '`创建时间`', '`更新人`', '`更新时间`',
            '`删除标识`', '`有效标识`'
        ];

        $values = [
            $this->model->quote($docNo),
            $this->model->quote($contractNo),
            $this->model->quote($originalName),
            $this->model->quote($docType),
            $this->model->quote($fileExt),
            (int) $fileSize,
            $this->model->quote($fileMd5),
            1,
            $this->model->quote('1'),
            $this->model->quote($canEditOnline),
            $this->model->quote('IDLE'),
            $this->model->quote($creator),
            $this->model->quote($now),
            $this->model->quote($creator),
            $this->model->quote($now),
            $this->model->quote('0'),
            $this->model->quote('1'),
        ];

        $sql = sprintf(
            'insert into `def_contract_document` (%s) values (%s)',
            implode(', ', $fields),
            implode(', ', $values)
        );
        $this->model->exec($sql);

        $db = db_connect('btdc');
        $docId = (int) $db->insertID();

        $newFileName = $docId . '_v1.' . $fileExt;
        $destPath = $storageDir . DIRECTORY_SEPARATOR . $newFileName;
        $moved = $file->move($storageDir, $newFileName);

        // 检查 move 是否成功（move 返回 false 或文件不存在都视为失败）
        if ($moved === false || !file_exists($destPath)) {
            // 回滚已插入的数据库记录
            $rollbackSql = sprintf(
                'delete from `def_contract_document` where `GUID`=%d',
                $docId
            );
            $this->model->exec($rollbackSql);
            $errorMsg = '文件保存失败：' . ($file->getError() ?: '未知错误') . '，目标路径: ' . $destPath;
            log_message('error', '[ContractService::uploadDocument] ' . $errorMsg);
            throw new \RuntimeException($errorMsg);
        }

        $relativePath = 'contract_docs/' . $newFileName;

        $updateSql = sprintf(
            'update `def_contract_document` set `文件路径`=%s where `GUID`=%d',
            $this->model->quote($relativePath),
            $docId
        );
        $this->model->exec($updateSql);

        return [
            'GUID' => $docId,
            '文档编号' => $docNo,
            '文档名称' => $originalName,
            '文档类型' => $docType,
            '文档格式' => $fileExt,
            '文件大小' => $fileSize,
            '文件路径' => $relativePath,
            '版本号' => 1,
            '创建时间' => $now,
        ];
    }

    /**
     * 删除合同文档
     *
     * @param int $docId 文档ID
     * @param string $operator 操作人工号
     * @return bool
     * @throws \RuntimeException
     */
    public function deleteDocument(int $docId, string $operator): bool
    {
        $sql = sprintf(
            'select * from `def_contract_document` where `GUID`=%d and `删除标识`=%s limit 1',
            $docId,
            $this->model->quote('0')
        );
        $result = $this->model->select($sql);
        $doc = $result ? ($result->getRowArray() ?: []) : [];

        if (empty($doc)) {
            throw new \RuntimeException('文档不存在');
        }

        $now = date('Y-m-d H:i:s');
        $updateSql = sprintf(
            'update `def_contract_document` 
            set `删除标识`=%s, `更新人`=%s, `更新时间`=%s 
            where `GUID`=%d',
            $this->model->quote('1'),
            $this->model->quote($operator),
            $this->model->quote($now),
            $docId
        );
        $affected = $this->model->exec($updateSql);

        return $affected > 0;
    }

    /**
     * 获取文档下载地址
     *
     * @param int $docId 文档ID
     * @return array|null
     */
    public function getDocumentDownloadUrl(int $docId): ?array
    {
        $sql = sprintf(
            'select * from `def_contract_document` where `GUID`=%d and `删除标识`=%s limit 1',
            $docId,
            $this->model->quote('0')
        );
        $result = $this->model->select($sql);
        $doc = $result ? ($result->getRowArray() ?: []) : [];

        if (empty($doc) || empty($doc['文件路径'])) {
            return null;
        }

        $fileName = $doc['文档名称'] . '.' . ($doc['文档格式'] ?? '');
        $filePath = WRITEPATH . $doc['文件路径'];

        if (!file_exists($filePath)) {
            return null;
        }

        return [
            'docId' => $docId,
            'fileName' => $fileName,
            'fileSize' => (int) ($doc['文件大小'] ?? 0),
            'downloadUrl' => site_url('api/contractV2/downloadDocument/' . $docId),
        ];
    }

    /**
     * 获取合同 V2 列定义（基于 def_function/def_query_config/def_query_column 元数据）
     *
     * 通过 view_function 视图读取指定功能编码的列定义，并组装为与通用工作台
     * PageMeta.columns 完全一致的 ColumnMeta[] 结构，前端可直接复用通用工作台的
     * 列转换逻辑（数值列右对齐、comparator、提示/异常样式等）。
     *
     * 若 functionCode 为空或 view_function 中无对应配置，返回空数组，由前端回退到
     * 硬编码列定义（保证渐进迁移期间功能不中断）。
     *
     * @param string $functionCode 功能编码（如 'contract_v2_list'）
     * @return array {functionCode: string, columns: ColumnMeta[]}
     */
    public function getColumnDefinitions(string $functionCode): array
    {
        $functionCode = trim($functionCode);
        if ($functionCode === '') {
            return ['functionCode' => '', 'columns' => []];
        }

        $columns = $this->metadataCache->getViewFunctionColumns($functionCode);
        $items = [];

        foreach ($columns as $column) {
            $title = (string) ($column['列名'] ?? '');
            $items[] = [
                'field' => $title,
                'title' => $title,
                'type' => (string) ($column['列类型'] ?? '字符'),
                'width' => (int) (($column['列宽度'] ?? 0) > 0 ? $column['列宽度'] : max(strlen($title) * 16, 120)),
                'hidden' => false,
                'editable' => in_array((string) ($column['可修改'] ?? '0'), ['1', '2'], true),
                'required' => (string) ($column['不可为空'] ?? '0') === '1',
                'sortable' => true,
                'hintCondition' => (string) ($column['提示条件'] ?? ''),
                'hintStyle' => (string) ($column['提示样式设置'] ?? ''),
                'errorCondition' => (string) ($column['异常条件'] ?? ''),
                'errorStyle' => (string) ($column['异常样式设置'] ?? ''),
                'canMerge' => (string) ($column['可行合并'] ?? '0') === '1'
            ];
        }

        return [
            'functionCode' => $functionCode,
            'columns' => $items
        ];
    }

    /**
     * 获取合同 V2 查询条件元数据（基于 def_query_column.可筛选 字段）
     *
     * 与通用工作台 PageMeta.conditions 结构一致，前端收到非空 conditions 时使用配置驱动
     * 渲染条件面板；否则回退到前端硬编码筛选字段。
     *
     * 注意：通用工作台 ContextService::buildConditionDefinitions 对所有列硬编码 filterable=true，
     * 本方法则尊重 def_query_column.可筛选 字段——仅 可筛选=1 的列出现在条件面板中。
     *
     * @param string $functionCode 功能编码（如 'contract_v2_list'）
     * @return array {functionCode: string, conditions: ConditionMeta[]}
     */
    public function getQueryConditions(string $functionCode): array
    {
        $functionCode = trim($functionCode);
        if ($functionCode === '') {
            return ['functionCode' => '', 'conditions' => []];
        }

        $columns = $this->metadataCache->getViewFunctionColumns($functionCode);
        $conditions = [];

        foreach ($columns as $column) {
            // 仅 可筛选=1 的列才作为查询条件
            if ((string) ($column['可筛选'] ?? '0') !== '1') {
                continue;
            }

            $conditions[] = [
                'label' => (string) ($column['列名'] ?? ''),
                'fieldKey' => (string) ($column['列名'] ?? ''),
                'fieldName' => (string) ($column['字段名'] ?? ''),
                'queryName' => (string) ($column['查询名'] ?? ''),
                'type' => (string) ($column['列类型'] ?? '字符'),
                'required' => (string) ($column['不可为空'] ?? '0') === '1',
                'filterable' => true
            ];
        }

        return [
            'functionCode' => $functionCode,
            'conditions' => $conditions
        ];
    }

    /**
     * 构建合同 V2 列映射（列名/字段名 → 列配置）
     *
     * 复用通用工作台 WorkbenchSqlHelper::buildColumnMap 的双键索引逻辑，
     * 用于把 filters 中的 fieldKey（列名）解析为 SQL 字段名（字段名）。
     *
     * 优先从 def_function/def_query_column 元数据加载；若未配置则使用合同表的
     * 内置字段映射兜底（保证 filters 在无元数据配置时仍可工作）。
     *
     * @return array 列名/字段名 → 列配置（含 '字段名' 键）
     */
    private function buildContractColumnMap(): array
    {
        // 优先从元数据加载（与 columns/conditions 接口同源）
        $functionCode = 'contract_v2_list';
        $columns = $this->metadataCache->getViewFunctionColumns($functionCode);

        if (!empty($columns)) {
            return WorkbenchSqlHelper::buildColumnMap($columns);
        }

        // 兜底：合同表的内置字段映射（列名 → 字段名，二者同名则只列一次）
        // 字段名与 def_contract_master_new 表的列名一致
        $fallbackColumns = [
            ['列名' => '合同编号', '字段名' => '合同编号'],
            ['列名' => '合同名称', '字段名' => '合同名称'],
            ['列名' => '合同类型', '字段名' => '合同类型'],
            ['列名' => '合同状态', '字段名' => '合同状态'],
            ['列名' => '甲方名称', '字段名' => '甲方名称'],
            ['列名' => '乙方名称', '字段名' => '乙方名称'],
            ['列名' => '合同金额', '字段名' => '合同金额'],
            ['列名' => '签订日期', '字段名' => '签订日期'],
            ['列名' => '开始日期', '字段名' => '开始日期'],
            ['列名' => '结束日期', '字段名' => '结束日期'],
            ['列名' => '所属部门', '字段名' => '所属部门'],
            ['列名' => '所属部门名称', '字段名' => '所属部门名称'],
            ['列名' => '创建人', '字段名' => '创建人'],
            ['列名' => '创建人姓名', '字段名' => '创建人姓名'],
            ['列名' => '创建时间', '字段名' => '创建时间'],
        ];

        return WorkbenchSqlHelper::buildColumnMap($fallbackColumns);
    }
}
