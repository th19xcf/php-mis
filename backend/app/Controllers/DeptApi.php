<?php

namespace App\Controllers;

class DeptApi extends BaseApiController
{
    public function tree()
    {
        $deptAuthz = $this->getDeptAuthz();

        $sql = sprintf('
            SELECT GUID, 部门编码, 部门名称, 部门级别, 上级部门编码, 负责人, 有无下级部门, 属地
            FROM def_dept
            WHERE 删除标识 = "0" AND 有效标识 = "1"
                AND LEFT(部门编码, LENGTH("%s")) = "%s"
            ORDER BY 部门级别 ASC, 部门编码 ASC
        ', $deptAuthz, $deptAuthz);

        $results = $this->model->select($sql)->getResultArray();
        $tree = $this->buildOrgTree($results);

        return $this->success($tree);
    }

    public function detail($guid = '')
    {
        if (empty($guid)) {
            $guid = $this->getGuidFromRequest();
        }

        if (empty($guid)) {
            return $this->paramError('部门GUID不能为空');
        }

        // 返回所有字段，配合 def_query_column 配置化渲染
        $sql = sprintf('
            SELECT * FROM def_dept
            WHERE GUID = "%s" AND 删除标识 = "0" AND 有效标识 = "1"
        ', $guid);

        $result = $this->model->select($sql)->getRowArray();

        if (!$result) {
            return $this->notFound('部门不存在');
        }

        return $this->success($result);
    }

    public function add()
    {
        $data = $this->getJsonInput();

        // parentCode 为前端专用字段（用于定位父节点），部门名称为必填业务字段
        if ($error = $this->requireParam($data, 'parentCode')) {
            return $error;
        }
        if ($error = $this->requireParam($data, '部门名称')) {
            return $error;
        }

        $parentCode = $data['parentCode'];

        $parentSql = sprintf('
            SELECT 部门编码, 部门名称, 部门级别, 部门全称
            FROM def_dept
            WHERE 部门编码 = "%s" AND 删除标识 = "0" AND 有效标识 = "1"
        ', $parentCode);
        $parent = $this->model->select($parentSql)->getRowArray();

        if (!$parent) {
            return $this->notFound('上级部门不存在');
        }

        $childLevel = $parent['部门级别'] + 1;
        $newCode = $this->generateDeptCode($parentCode);

        if (!$newCode) {
            return $this->serverError('生成部门编码失败');
        }

        $deptName = $data['部门名称'];
        $fullName = $parent['部门全称'] ? $parent['部门全称'] . '>>' . $deptName : $deptName;

        // 从前端数据中提取业务字段（中文字段名），排除前端专用字段
        $insertData = [];
        foreach ($data as $key => $value) {
            if (in_array($key, ['parentCode', 'guid'], true)) {
                continue;
            }
            $insertData[$key] = $value;
        }

        // 注入业务生成字段
        $insertData['部门编码'] = $newCode;
        $insertData['部门全称'] = $fullName;
        $insertData['部门级别'] = $childLevel;
        $insertData['上级部门编码'] = $parentCode;
        $insertData['有无下级部门'] = '无';
        if (empty($insertData['记录开始日期'])) {
            $insertData['记录开始日期'] = date('Y-m-d');
        }

        // 注入审计字段（操作记录/操作来源/操作人员/开始操作时间/有效标识/删除标识）
        $insertData = $this->buildInsertData($insertData);

        $num = $this->insertRecord('def_dept', $insertData);

        if ($num > 0) {
            $updateParentSql = sprintf('
                UPDATE def_dept SET 有无下级部门 = "有" WHERE 部门编码 = "%s"
            ', $parentCode);
            $this->model->exec($updateParentSql);

            return $this->success(['deptCode' => $newCode], '新增部门成功');
        }

        return $this->serverError('新增部门失败');
    }

    public function update()
    {
        $data = $this->getJsonInput();

        if ($error = $this->requireParam($data, 'guid')) {
            return $error;
        }

        $guid = $data['guid'];

        $oldSql = sprintf('
            SELECT * FROM def_dept WHERE GUID = "%s" AND 删除标识 = "0" AND 有效标识 = "1"
        ', $guid);
        $oldRecord = $this->model->select($oldSql)->getRowArray();

        if (!$oldRecord) {
            return $this->notFound('部门不存在');
        }

        // 从前端数据中提取业务字段（中文字段名），排除前端专用字段
        $updateData = [];
        foreach ($data as $key => $value) {
            if (in_array($key, ['guid', 'parentCode'], true)) {
                continue;
            }
            $updateData[$key] = $value;
        }

        // 业务逻辑：修改部门名称时同步更新部门全称
        if (isset($updateData['部门名称']) && $updateData['部门名称'] !== $oldRecord['部门名称']) {
            $parentFullName = $this->getParentFullName($oldRecord['上级部门编码']);
            $newFullName = $parentFullName ? $parentFullName . '>>' . $updateData['部门名称'] : $updateData['部门名称'];
            $updateData['部门全称'] = $newFullName;
        }

        if (empty($updateData)) {
            return $this->success(null, '没有需要更新的字段');
        }

        // 注入审计字段
        $updateData = $this->buildUpdateData($updateData, '更新[2]');

        $num = $this->updateRecord('def_dept', $updateData, sprintf('GUID = "%s"', $guid));

        if ($num > 0) {
            return $this->success(null, '修改部门信息成功');
        }

        return $this->serverError('修改部门信息失败');
    }

    public function delete()
    {
        $data = $this->getJsonInput();

        if ($error = $this->requireParam($data, 'guid')) {
            return $error;
        }

        $guid = $data['guid'];

        $checkSql = sprintf('
            SELECT COUNT(*) as cnt FROM def_dept
            WHERE 上级部门编码 = (SELECT 部门编码 FROM def_dept WHERE GUID = "%s")
                AND 删除标识 = "0" AND 有效标识 = "1"
        ', $guid);
        $checkResult = $this->model->select($checkSql)->getRowArray();

        if ($checkResult && $checkResult['cnt'] > 0) {
            return $this->businessError('该部门存在下级部门，不能删除');
        }

        $num = $this->deleteRecord('def_dept', sprintf('GUID = "%s"', $guid));

        if ($num > 0) {
            return $this->success(null, '删除部门成功');
        }

        return $this->serverError('删除部门失败');
    }

    public function options()
    {
        $deptSql = '
            SELECT GUID as value, 部门名称 as label, 部门编码 as code, 部门级别 as level
            FROM def_dept
            WHERE 删除标识 = "0" AND 有效标识 = "1"
            ORDER BY 部门编码 ASC
        ';

        $deptResult = $this->model->select($deptSql)->getResultArray();

        $regionSql = '
            SELECT DISTINCT 对象值 as value, 对象值 as label
            FROM def_object
            WHERE 对象名称 = "属地" AND 有效标识 = "1"
            ORDER BY CONVERT(对象值 USING GBK)
        ';

        $regionResult = $this->model->select($regionSql)->getResultArray();

        return $this->success([
            'dept' => $deptResult,
            'region' => $regionResult
        ]);
    }

    /**
     * 构建组织架构树（递归父子关系）。
     *
     * 算法：从 parentCode=''（顶级）开始，递归收集 上级部门编码 === 当前父编码 的子节点。
     * 与 buildGrouped*Tree 系列（多级桶聚合）不同：这里依赖 部门编码 ↔ 上级部门编码 字段。
     *
     * @param array $data       部门数据（含 部门编码 / 上级部门编码 / 部门名称 等字段）
     * @param string $parentCode 当前递归的父部门编码（首次传空字符串）
     * @return array 树形结构，每个节点含 guid/deptCode/deptName/level/parentCode/leader/hasChildren/region/children
     */
    private function buildOrgTree(array $data, string $parentCode = ''): array
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

                $children = $this->buildOrgTree($data, $item['部门编码']);
                if (!empty($children)) {
                    $node['children'] = $children;
                }

                $tree[] = $node;
            }
        }

        return $tree;
    }

    private function generateDeptCode(string $parentCode): ?string
    {
        $sql = sprintf('
            SELECT MAX(CAST(SUBSTRING_INDEX(部门编码, "-", -1) AS UNSIGNED)) as max_num
            FROM def_dept
            WHERE 上级部门编码 = "%s" AND 删除标识 = "0"
        ', $parentCode);

        $result = $this->model->select($sql)->getRowArray();
        $maxNum = $result['max_num'] ?? 0;

        return $parentCode . '-' . ($maxNum + 1);
    }

    private function getParentFullName(string $parentCode): string
    {
        $sql = sprintf('
            SELECT 部门全称 FROM def_dept
            WHERE 部门编码 = "%s" AND 删除标识 = "0" AND 有效标识 = "1"
        ', $parentCode);

        $result = $this->model->select($sql)->getRowArray();
        return $result['部门全称'] ?? '';
    }
}
