<?php

namespace App\Controllers;

use App\Services\Employee\EmployeeService;

class EmployeeApi extends BaseApiController
{
    private ?EmployeeService $service = null;

    private function getService(): EmployeeService
    {
        return $this->service ??= new EmployeeService();
    }

    public function tree()
    {
        $service = $this->getAuthorizationService();
        $resolvedAuth = $service->resolveLocationAuth('2045');
        $locationAuthzCond = $service->buildCondition('属地', $resolvedAuth, false);

        $data = $this->getService()->getEmployeeList($locationAuthzCond);
        $tree = $this->getService()->buildGroupedEmployeeTree($data);

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

        $result = $this->getService()->getEmployeeDetail($guid);

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
        $effectiveDate = $data['生效日期'] ?? date('Y-m-d');

        $service = $this->getService();

        // 离职处理
        if (!empty($data['员工状态']) && $data['员工状态'] === '离职') {
            $num = $service->processResignation($guid, $data);
            return $this->success(null, sprintf('处理离职信息成功，修改 %d 条记录', $num));
        }

        // 普通更新（审计流水模式）
        $num = $service->updateEmployee($guid, $data, $effectiveDate, $this->getUserWorkId());

        if ($num === -1) {
            return $this->notFound('人员不存在');
        }

        if ($num === 0) {
            return $this->success(null, '没有需要更新的字段');
        }

        return $this->success(null, sprintf('修改成功，修改 %d 条记录', $num));
    }

    public function batchUpdate()
    {
        $data = $this->getJsonInput();

        if (empty($data['guids']) || !is_array($data['guids'])) {
            return $this->paramError('请选择要修改的人员');
        }

        $num = $this->getService()->batchUpdateEmployees(
            $data['guids'],
            $data,
            $this->getUserWorkId()
        );

        if ($num === 0) {
            return $this->success(null, '没有需要更新的字段');
        }

        return $this->success(null, sprintf('批量修改成功，修改 %d 条记录', $num));
    }

    public function delete()
    {
        $data = $this->getJsonInput();

        if (empty($data['guids']) || !is_array($data['guids'])) {
            return $this->paramError('请选择要删除的人员');
        }

        $guidStr = implode(',', array_map(
            fn($v) => $this->model->quote((string) $v),
            $data['guids']
        ));
        $num = $this->deleteRecord('ee_onjob', sprintf('GUID in (%s)', $guidStr));

        if ($num > 0) {
            return $this->success(null, sprintf('删除成功，共删除 %d 条记录', $num));
        }

        return $this->serverError('删除失败');
    }

    public function options()
    {
        $resolvedAuth = $this->getAuthorizationService()->resolveLocationAuth('2045');
        $options = $this->getService()->getEmployeeOptions($resolvedAuth);

        return $this->success($options);
    }
}
