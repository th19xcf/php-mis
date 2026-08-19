<?php

namespace App\Controllers;

use App\Exceptions\AuthException;
use App\Exceptions\BusinessException;
use App\Exceptions\ValidationException;
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
        // 属地权限：与 2010 同源（走 ContextService，含部门授权优先、upkeepAuth）
        $locationAuthzCond = $this->resolveLocationAuthzCond('2045');
        if ($locationAuthzCond === null) {
            return $this->serverError('无法获取属地权限');
        }

        $data = $this->getService()->getEmployeeList($locationAuthzCond);
        $tree = $this->getService()->buildGroupedEmployeeTree($data);

        return $this->success($tree);
    }

    /**
     * 调试：打印左侧员工树加载的完整 SQL + 分段耗时
     *
     * 权限：与 pageMeta.toolbar.debugSql 同源（hasDebugSqlAuth）
     * 属地权限：与 tree() 同源、与 2010 完全一致
     * SQL 来源：EmployeeService::getEmployeeListSql（与 tree() 同一份 SQL）
     */
    public function debugTree()
    {
        if (! $this->hasDebugSqlAuth()) {
            return $this->serverError('无调试权限');
        }

        $totalStart = hrtime(true);

        // 1. 构建工作台上下文（与 tree() 同源，与 2010 完全一致）
        $contextStart = hrtime(true);
        try {
            [$context] = $this->getContextService()->buildWorkbenchContext('2045');
        } catch (AuthException | BusinessException | ValidationException $e) {
            log_message('error', '[EmployeeApi::debugTree] 构建上下文失败: ' . $e->getMessage());
            return $this->serverError('无法获取属地权限: ' . $e->getMessage());
        }
        $locationAuthzCond = (string) ($context['locationAuthzCond'] ?? '');
        if ($locationAuthzCond === '') {
            $locationAuthzCond = '1=1';
        }
        $userLocationAuth = (string) ($context['user']['locationAuth'] ?? '');
        $deptAuthzCond    = (string) ($context['deptAuthzCond'] ?? '');
        $contextEnd = hrtime(true);

        // 2. 构建 SQL（与 tree() 完全一致，复用 service 的 SQL 构造方法）
        $service = $this->getService();
        $sql = $service->getEmployeeListSql($locationAuthzCond);

        // 3. 执行查询
        $queryStart = hrtime(true);
        $results = $this->model->select($sql)->getResultArray();
        $queryEnd = hrtime(true);

        // 4. 构建树
        $buildStart = hrtime(true);
        $tree = $service->buildGroupedEmployeeTree($results);
        $buildEnd = hrtime(true);

        $totalEnd = hrtime(true);

        return $this->success([
            'sql'                     => $sql,
            'locationAuthzCondition'  => $locationAuthzCond,
            'userLocationAuth'        => $userLocationAuth,
            'deptAuthzCondition'      => $deptAuthzCond,
            'rowCount'                => count($results),
            'treeNodeCount'           => count($tree),
            'timing' => [
                'contextBuildMs' => round(($contextEnd - $contextStart) / 1e6, 2),
                'queryMs'        => round(($queryEnd - $queryStart) / 1e6, 2),
                'buildTreeMs'    => round(($buildEnd - $buildStart) / 1e6, 2),
                'totalMs'        => round(($totalEnd - $totalStart) / 1e6, 2),
            ],
        ]);
    }

    public function detail($guid = '')
    {
        if (empty($guid)) {
            $guid = $this->getGuidFromRequest();
        }

        if (empty($guid)) {
            return $this->paramError('人员GUID不能为空');
        }

        $selectFields = $this->buildDetailSelectFields('2045', 'ee_onjob');

        $sql = sprintf('
            select %s
            from ee_onjob
            where GUID="%s" and 有效标识="1" and 删除标识="0"',
            $selectFields,
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
        // 下拉选项过滤：与 2010 同源（FieldConfigService::getObjectOptions）
        // 用 userContext->getLocation()（员工属地单值），不再用 resolveLocationAuth 的合并赋权字符串
        $userLocation = $this->userContext->getLocation();
        $options = $this->getService()->getEmployeeOptions($userLocation);

        return $this->success($options);
    }
}
