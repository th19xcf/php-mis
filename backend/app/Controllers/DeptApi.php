<?php

namespace App\Controllers;

use App\Constants\ApiCode;
use App\Libraries\SessionUserContext;
use App\Models\Mcommon;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class DeptApi extends BaseController
{
    protected $model;
    private SessionUserContext $userContext;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->model = new Mcommon();
        $this->userContext = new SessionUserContext();
    }

    /**
     * 获取部门树形结构
     */
    public function tree()
    {
        $deptAuthz = $this->userContext->getSessionUser()['deptAuthz'] ?: '';

        $sql = sprintf('
            SELECT GUID, 部门编码, 部门名称, 部门级别, 上级部门编码, 负责人, 有无下级部门, 属地
            FROM def_dept
            WHERE 删除标识 = "0" AND 有效标识 = "1"
                AND LEFT(部门编码, LENGTH("%s")) = "%s"
            ORDER BY 部门级别 ASC, 部门编码 ASC
        ', $deptAuthz, $deptAuthz);

        $query = $this->model->select($sql);
        $results = $query->getResultArray();

        // 构建树形结构
        $tree = $this->buildTree($results);

        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg' => 'Success',
            'data' => $tree
        ]);
    }

    /**
     * 获取部门详情
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
                'msg' => '部门GUID不能为空',
                'data' => null
            ]);
        }

        $sql = sprintf('
            SELECT GUID, 部门编码, 部门名称, 部门全称, 部门级别, 负责人,
                上级部门编码, 有无下级部门, 属地,
                预算表部门全称, 报表部门全称,
                记录开始日期, 记录结束日期
            FROM def_dept
            WHERE GUID = "%s" AND 删除标识 = "0" AND 有效标识 = "1"
        ', $guid);

        $query = $this->model->select($sql);
        $result = $query->getRowArray();

        if (!$result) {
            return $this->response->setJSON([
                'code' => ApiCode::NOT_FOUND,
                'msg' => '部门不存在',
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
     * 新增下级部门
     */
    public function add()
    {
        $data = $this->request->getJSON(true);

        // 校验必填项
        if (empty($data['parentCode'])) {
            return $this->response->setJSON([
                'code' => ApiCode::PARAM_ERROR,
                'msg' => '上级部门编码不能为空',
                'data' => null
            ]);
        }
        if (empty($data['deptName'])) {
            return $this->response->setJSON([
                'code' => ApiCode::PARAM_ERROR,
                'msg' => '部门名称不能为空',
                'data' => null
            ]);
        }

        $userWorkid = $this->userContext->getSessionUser()['workId'] ?: 'system';

        // 查询上级部门信息
        $parentSql = sprintf('
            SELECT 部门编码, 部门名称, 部门级别, 部门全称
            FROM def_dept
            WHERE 部门编码 = "%s" AND 删除标识 = "0" AND 有效标识 = "1"
        ', $data['parentCode']);
        $parentQuery = $this->model->select($parentSql);
        $parent = $parentQuery->getRowArray();

        if (!$parent) {
            return $this->response->setJSON([
                'code' => ApiCode::NOT_FOUND,
                'msg' => '上级部门不存在',
                'data' => null
            ]);
        }

        // 生成新部门编码
        $childLevel = $parent['部门级别'] + 1;
        $newCode = $this->generateDeptCode($data['parentCode']);

        if (!$newCode) {
            return $this->response->setJSON([
                'code' => ApiCode::SERVER_ERROR,
                'msg' => '生成部门编码失败',
                'data' => null
            ]);
        }

        // 构建部门全称
        $fullName = $parent['部门全称'] ? $parent['部门全称'] . '>>' . $data['deptName'] : $data['deptName'];

        $insertSql = sprintf('
            INSERT INTO def_dept 
                (部门编码, 部门名称, 部门全称, 部门级别,
                上级部门编码, 有无下级部门, 负责人, 属地, 预算表部门全称,
                记录开始日期, 记录结束日期,
                操作记录, 操作来源, 操作人员,
                开始操作时间, 结束操作时间,
                校验标识, 删除标识, 有效标识) 
            VALUES ("%s", "%s", "%s", %d,
                "%s", "无", "%s", "%s", "%s",
                "%s", "",
                "新增", "页面新增", "%s",
                "%s", "",
                "0", "0", "1")
        ',
            $newCode,
            $data['deptName'],
            $fullName,
            $childLevel,
            $data['parentCode'],
            $data['leader'] ?? '',
            $data['region'] ?? '',
            $data['budgetFullName'] ?? '',
            $data['effectiveDate'] ?? date('Y-m-d'),
            $userWorkid,
            date('Y-m-d H:i:s')
        );

        $num = $this->model->exec($insertSql);

        if ($num > 0) {
            // 更新上级部门的有无下级部门标识
            $updateParentSql = sprintf('
                UPDATE def_dept
                SET 有无下级部门 = "有"
                WHERE 部门编码 = "%s"
            ', $data['parentCode']);
            $this->model->exec($updateParentSql);

            return $this->response->setJSON([
                'code' => ApiCode::SUCCESS,
                'msg' => '新增部门成功',
                'data' => ['deptCode' => $newCode]
            ]);
        }

        return $this->response->setJSON([
            'code' => ApiCode::SERVER_ERROR,
            'msg' => '新增部门失败',
            'data' => null
        ]);
    }

    /**
     * 修改部门信息
     */
    public function update()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['guid'])) {
            return $this->response->setJSON([
                'code' => ApiCode::PARAM_ERROR,
                'msg' => '部门GUID不能为空',
                'data' => null
            ]);
        }

        $userWorkid = $this->userContext->getSessionUser()['workId'] ?: 'system';

        $guid = $data['guid'];
        $effectiveDate = $data['effectiveDate'] ?? date('Y-m-d');

        // 查询原记录
        $oldSql = sprintf('
            SELECT * FROM def_dept
            WHERE GUID = "%s" AND 删除标识 = "0" AND 有效标识 = "1"
        ', $guid);
        $oldQuery = $this->model->select($oldSql);
        $oldRecord = $oldQuery->getRowArray();

        if (!$oldRecord) {
            return $this->response->setJSON([
                'code' => ApiCode::NOT_FOUND,
                'msg' => '部门不存在',
                'data' => null
            ]);
        }

        // 构建更新字段
        $updateFields = [];
        if (isset($data['deptName']) && $data['deptName'] !== $oldRecord['部门名称']) {
            $updateFields[] = sprintf('部门名称 = "%s"', $data['deptName']);
            // 更新全称
            $parentFullName = $this->getParentFullName($oldRecord['上级部门编码']);
            $newFullName = $parentFullName ? $parentFullName . '>>' . $data['deptName'] : $data['deptName'];
            $updateFields[] = sprintf('部门全称 = "%s"', $newFullName);
        }
        if (isset($data['leader'])) {
            $updateFields[] = sprintf('负责人 = "%s"', $data['leader']);
        }
        if (isset($data['region'])) {
            $updateFields[] = sprintf('属地 = "%s"', $data['region']);
        }
        if (isset($data['budgetFullName'])) {
            $updateFields[] = sprintf('预算表部门全称 = "%s"', $data['budgetFullName']);
        }
        if (isset($data['hasChildren'])) {
            $updateFields[] = sprintf('有无下级部门 = "%s"', $data['hasChildren']);
        }

        if (empty($updateFields)) {
            return $this->response->setJSON([
                'code' => ApiCode::SUCCESS,
                'msg' => '没有需要更新的字段',
                'data' => null
            ]);
        }

        $updateFields[] = sprintf('操作记录 = "更新[2]"');
        $updateFields[] = sprintf('操作来源 = "页面更新"');
        $updateFields[] = sprintf('操作人员 = "%s"', $userWorkid);
        $updateFields[] = sprintf('结束操作时间 = "%s"', date('Y-m-d H:i:s'));

        $updateSql = sprintf('
            UPDATE def_dept
            SET %s
            WHERE GUID = "%s"
        ', implode(', ', $updateFields), $guid);

        $num = $this->model->exec($updateSql);

        if ($num > 0) {
            return $this->response->setJSON([
                'code' => ApiCode::SUCCESS,
                'msg' => '修改部门信息成功',
                'data' => null
            ]);
        }

        return $this->response->setJSON([
            'code' => ApiCode::SERVER_ERROR,
            'msg' => '修改部门信息失败',
            'data' => null
        ]);
    }

    /**
     * 删除部门（逻辑删除）
     */
    public function delete()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['guid'])) {
            return $this->response->setJSON([
                'code' => ApiCode::PARAM_ERROR,
                'msg' => '部门GUID不能为空',
                'data' => null
            ]);
        }

        $guid = $data['guid'];

        // 检查是否有下级部门
        $checkSql = sprintf('
            SELECT COUNT(*) as cnt
            FROM def_dept
            WHERE 上级部门编码 = (SELECT 部门编码 FROM def_dept WHERE GUID = "%s")
                AND 删除标识 = "0" AND 有效标识 = "1"
        ', $guid);
        $checkQuery = $this->model->select($checkSql);
        $checkResult = $checkQuery->getRowArray();

        if ($checkResult && $checkResult['cnt'] > 0) {
            return $this->response->setJSON([
                'code' => ApiCode::BUSINESS_ERROR,
                'msg' => '该部门存在下级部门，不能删除',
                'data' => null
            ]);
        }

        $userWorkid = $this->userContext->getSessionUser()['workId'] ?: 'system';

        $deleteSql = sprintf('
            UPDATE def_dept
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
                'msg' => '删除部门成功',
                'data' => null
            ]);
        }

        return $this->response->setJSON([
            'code' => ApiCode::SERVER_ERROR,
            'msg' => '删除部门失败',
            'data' => null
        ]);
    }

    /**
     * 获取部门编码选项（用于下拉选择）
     */
    public function options()
    {
        $sql = '
            SELECT GUID as value, 部门名称 as label, 部门编码 as code, 部门级别 as level
            FROM def_dept
            WHERE 删除标识 = "0" AND 有效标识 = "1"
            ORDER BY 部门编码 ASC
        ';

        $query = $this->model->select($sql);
        $results = $query->getResultArray();

        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg' => 'Success',
            'data' => $results
        ]);
    }

    //-------------------------
    // 私有辅助方法
    //-------------------------

    /**
     * 构建树形结构
     */
    private function buildTree(array $data, string $parentCode = ''): array
    {
        $tree = [];

        foreach ($data as $item) {
            if ($item['上级部门编码'] === $parentCode) {
                $node = [
                    'guid' => $item['GUID'],
                    'deptCode' => $item['部门编码'],
                    'deptName' => $item['部门名称'],
                    'level' => (int)$item['部门级别'],
                    'parentCode' => $item['上级部门编码'],
                    'leader' => $item['负责人'],
                    'hasChildren' => $item['有无下级部门'],
                    'region' => $item['属地'],
                    'children' => []
                ];

                $children = $this->buildTree($data, $item['部门编码']);
                if (!empty($children)) {
                    $node['children'] = $children;
                }

                $tree[] = $node;
            }
        }

        return $tree;
    }

    /**
     * 生成新部门编码
     */
    private function generateDeptCode(string $parentCode): ?string
    {
        // 查询当前父部门下最大的子部门编码
        $sql = sprintf('
            SELECT MAX(CAST(SUBSTRING_INDEX(部门编码, "-", -1) AS UNSIGNED)) as max_num
            FROM def_dept
            WHERE 上级部门编码 = "%s" AND 删除标识 = "0"
        ', $parentCode);

        $query = $this->model->select($sql);
        $result = $query->getRowArray();

        $maxNum = $result['max_num'] ?? 0;
        $newNum = $maxNum + 1;

        return $parentCode . '-' . $newNum;
    }

    /**
     * 获取上级部门全称
     */
    private function getParentFullName(string $parentCode): string
    {
        $sql = sprintf('
            SELECT 部门全称
            FROM def_dept
            WHERE 部门编码 = "%s" AND 删除标识 = "0" AND 有效标识 = "1"
        ', $parentCode);

        $query = $this->model->select($sql);
        $result = $query->getRowArray();

        return $result['部门全称'] ?? '';
    }
}
