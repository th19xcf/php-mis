<?php

namespace App\Controllers;

class DeptApi extends BaseApiController
{
    public function tree()
    {
        $deptAuthz = $this->getDeptAuthz();

        // 超管（无部门赋权）查看全部
        if ($deptAuthz === '') {
            $sql = '
                SELECT GUID, 部门编码, 部门名称, 部门级别, 上级部门编码, 负责人, 有无下级部门, 属地
                FROM def_dept
                WHERE 删除标识 = "0" AND 有效标识 = "1"
                ORDER BY 部门级别 ASC, IFNULL(顺序, 99999) ASC, CONVERT(部门名称 USING GBK) ASC
            ';
            $results = $this->model->select($sql)->getResultArray();
            return $this->success($this->buildOrgTree($results));
        }

        // 解析授权部门编码（兼容逗号/竖线分隔，去除引号）
        $codes = $this->parseDeptAuthz($deptAuthz);
        if (empty($codes)) {
            return $this->success([]);
        }

        // 路径物化过滤：授权部门本身 + 所有子孙
        // JOIN def_dept a 取授权部门路径，d.部门路径 = a.部门路径（自身）OR LIKE a.部门路径>>%（子孙）
        $quoted = array_map(fn($c) => $this->model->quote($c), $codes);
        $inList = implode(',', $quoted);

        $sql = sprintf('
            SELECT d.GUID, d.部门编码, d.部门名称, d.部门级别, d.上级部门编码, d.负责人, d.有无下级部门, d.属地
            FROM def_dept d
            JOIN def_dept a ON a.部门编码 IN (%s)
              AND (d.部门路径 = a.部门路径 OR d.部门路径 LIKE CONCAT(a.部门路径, ">>%%"))
            WHERE d.删除标识 = "0" AND d.有效标识 = "1"
            GROUP BY d.GUID
            ORDER BY d.部门级别 ASC, IFNULL(d.顺序, 99999) ASC, CONVERT(d.部门名称 USING GBK) ASC
        ', $inList);

        $results = $this->model->select($sql)->getResultArray();
        $tree = $this->buildOrgTree($results);

        return $this->success($tree);
    }

    /**
     * 解析部门编码赋权字符串为编码数组
     * 兼容逗号/竖线分隔，去除两侧引号与空白
     */
    private function parseDeptAuthz(string $authz): array
    {
        $authz = trim($authz);
        if ($authz === '') {
            return [];
        }
        // 兼容逗号与竖线两种分隔符
        $parts = preg_split('/[,|]/', $authz);
        $codes = [];
        foreach ($parts as $part) {
            $code = trim($part, " \t\n\r\0\x0B\"'");
            if ($code !== '') {
                $codes[] = $code;
            }
        }
        return array_values(array_unique($codes));
    }

    public function detail($guid = '')
    {
        if (empty($guid)) {
            $guid = $this->getGuidFromRequest();
        }

        if (empty($guid)) {
            return $this->paramError('部门GUID不能为空');
        }

        $guidInt = (int) $guid;
        if ($guidInt <= 0) {
            return $this->paramError('部门GUID不合法');
        }

        // 返回所有字段，配合 def_query_column 配置化渲染
        $sql = sprintf('
            SELECT * FROM def_dept
            WHERE GUID = %d AND 删除标识 = "0" AND 有效标识 = "1"
        ', $guidInt);

        $result = $this->model->select($sql)->getRowArray();

        if (!$result) {
            return $this->notFound('部门不存在');
        }

        // 剔除二进制 UUID 字段，避免 JSON 编码失败（Malformed UTF-8 characters）
        // UUID 字段供 def_audit_log 审计日志关联使用，不向前端返回
        unset($result['UUID']);

        return $this->success($result);
    }

    public function add()
    {
        $data = $this->getJsonInput();

        // parentCode 为前端专用字段（用于定位父节点），部门名称/部门类别为必填业务字段
        if ($error = $this->requireParam($data, 'parentCode')) {
            return $error;
        }
        if ($error = $this->requireParam($data, '部门名称')) {
            return $error;
        }
        if ($error = $this->requireParam($data, '部门类别')) {
            return $error;
        }

        // 校验 部门类别 在 def_object 字典中存在且有效
        $catCheckSql = sprintf(
            'SELECT 1 FROM def_object WHERE 对象名称="部门类别" AND 对象值=%s AND 有效标识="1"',
            $this->model->quote((string) $data['部门类别'])
        );
        if (!$this->model->select($catCheckSql)->getRowArray()) {
            return $this->businessError('部门类别不合法或已停用');
        }

        $parentCode = $data['parentCode'];

        // 查询上级部门，同时取 GUID 和 部门路径（用于维护物化路径）
        $parentSql = sprintf('
            SELECT GUID, 部门编码, 部门名称, 部门级别, 部门全称, 部门路径
            FROM def_dept
            WHERE 部门编码 = %s AND 删除标识 = "0" AND 有效标识 = "1"
        ', $this->model->quote((string) $parentCode));
        $parent = $this->model->select($parentSql)->getRowArray();

        if (!$parent) {
            return $this->notFound('上级部门不存在');
        }

        $childLevel = $parent['部门级别'] + 1;

        // 调用存储过程生成扁平部门编码（YW001/ZN001/ZC001），防并发重号
        $category = (string) $data['部门类别'];
        $db = $this->model->getDb();
        $db->query(sprintf('CALL sp_生成部门编码(%s, @new_code)', $db->escape($category)));
        $codeRow = $db->query('SELECT @new_code AS c')->getRowArray() ?: [];
        $newCode = (string) ($codeRow['c'] ?? '');

        if ($newCode === '') {
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
        $insertData['上级部门GUID'] = (int) $parent['GUID'];
        $insertData['是否末级部门'] = '1';  // 新增部门默认为末级
        $insertData['有无下级部门'] = '无';
        if (empty($insertData['记录开始日期'])) {
            $insertData['记录开始日期'] = date('Y-m-d');
        }

        // 注入审计字段（操作记录/操作来源/操作人员/开始操作时间/有效标识/删除标识）
        $insertData = $this->buildInsertData($insertData);

        $num = $this->insertRecord('def_dept', $insertData);

        if ($num > 0) {
            // 插入后回填 部门路径（物化路径 = 父路径 >> 新GUID）
            $newGuidRow = $this->model->select(sprintf(
                'SELECT GUID FROM def_dept WHERE 部门编码 = %s',
                $this->model->quote($newCode)
            ))->getRowArray();

            if ($newGuidRow && !empty($parent['部门路径'])) {
                $newPath = $parent['部门路径'] . '>>' . $newGuidRow['GUID'];
                $pathData = $this->buildUpdateData(['部门路径' => $newPath], '新增下级回填路径');
                $this->updateRecord('def_dept', $pathData, sprintf('GUID = %d', (int) $newGuidRow['GUID']));
            }

            // 上级部门标记为非末级（走 updateRecord 以写入 def_audit_log）
            $parentData = $this->buildUpdateData(['有无下级部门' => '有', '是否末级部门' => '0'], '新增下级');
            $this->updateRecord('def_dept', $parentData, sprintf('部门编码 = %s', $this->model->quote((string) $parentCode)));

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
        $guidInt = (int) $guid;
        if ($guidInt <= 0) {
            return $this->paramError('部门GUID不合法');
        }

        $oldSql = sprintf('
            SELECT * FROM def_dept WHERE GUID = %d AND 删除标识 = "0" AND 有效标识 = "1"
        ', $guidInt);
        $oldRecord = $this->model->select($oldSql)->getRowArray();

        if (!$oldRecord) {
            return $this->notFound('部门不存在');
        }

        // 从前端数据中提取业务字段（中文字段名），排除前端专用字段
        // 部门类别 不允许修改（def_query_column.可修改=0），修改会破坏与部门编码前缀的对应关系
        $updateData = [];
        foreach ($data as $key => $value) {
            if (in_array($key, ['guid', 'parentCode', '部门类别'], true)) {
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

        $num = $this->updateRecord('def_dept', $updateData, sprintf('GUID = %d', $guidInt));

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
        $guidInt = (int) $guid;
        if ($guidInt <= 0) {
            return $this->paramError('部门GUID不合法');
        }

        // 删除前先记录上级部门编码（软删除后查询条件会排除该行，提前取出最稳妥）
        $parentSql = sprintf('
            SELECT 上级部门编码 FROM def_dept WHERE GUID = %d
        ', $guidInt);
        $parentRow = $this->model->select($parentSql)->getRowArray();
        $parentCode = $parentRow['上级部门编码'] ?? '';

        $checkSql = sprintf('
            SELECT COUNT(*) as cnt FROM def_dept
            WHERE 上级部门编码 = (SELECT 部门编码 FROM def_dept WHERE GUID = %d)
                AND 删除标识 = "0" AND 有效标识 = "1"
        ', $guidInt);
        $checkResult = $this->model->select($checkSql)->getRowArray();

        if ($checkResult && $checkResult['cnt'] > 0) {
            return $this->businessError('该部门存在下级部门，不能删除');
        }

        $num = $this->deleteRecord('def_dept', sprintf('GUID = %d', $guidInt));

        if ($num > 0) {
            // 上级部门没有剩余有效下级时，回写「有无下级部门 = 无」
            if ($parentCode !== '') {
                $remainSql = sprintf('
                    SELECT COUNT(*) as cnt FROM def_dept
                    WHERE 上级部门编码 = %s AND 删除标识 = "0" AND 有效标识 = "1"
                ', $this->model->quote((string) $parentCode));
                $remainRow = $this->model->select($remainSql)->getRowArray();

                if ($remainRow && (int) $remainRow['cnt'] === 0) {
                    // 上级部门重置为末级（走 updateRecord 以写入 def_audit_log）
                    $resetData = $this->buildUpdateData(['有无下级部门' => '无', '是否末级部门' => '1'], '删除下级');
                    $this->updateRecord('def_dept', $resetData, sprintf('部门编码 = %s', $this->model->quote((string) $parentCode)));
                }
            }

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

    private function getParentFullName(string $parentCode): string
    {
        $sql = sprintf('
            SELECT 部门全称 FROM def_dept
            WHERE 部门编码 = %s AND 删除标识 = "0" AND 有效标识 = "1"
        ', $this->model->quote($parentCode));

        $result = $this->model->select($sql)->getRowArray();
        return $result['部门全称'] ?? '';
    }
}
