<?php

namespace App\Controllers;

use App\Libraries\MetadataCache;

class Comment extends BaseApiController
{
    public function list(string $functionCode = '')
    {
        try {
            if ($functionCode === '') {
                return $this->paramError('功能编码不能为空');
            }

            $payload = $this->getJsonInput();
            $keyFields = $payload['keyFields'] ?? [];

            if (empty($keyFields)) {
                return $this->success(['records' => [], 'total' => 0]);
            }

            $config = $this->getCommentConfig($functionCode);
            if (!$config) {
                return $this->paramError('该功能未配置批注模块');
            }

            $commentTable = $config['数据表名'];

            if (empty($commentTable)) {
                return $this->paramError('批注表名未配置，请检查 def_comment_config 或 def_query_config.备注模块 配置');
            }

            $tableExists = $this->model->select(sprintf('show tables like "%s"', $commentTable));
            if ($tableExists === false || empty($tableExists->getResultArray())) {
                return $this->serverError("批注表 '{$commentTable}' 不存在");
            }

            $columnsQuery = $this->model->select(sprintf('show columns from %s', $commentTable));
            if ($columnsQuery === false) {
                return $this->serverError("无法获取批注表 '{$commentTable}' 的结构");
            }

            $tableColumns = $columnsQuery->getResultArray();
            $validFields = array_map(fn($col) => $col['Field'] ?? $col['field'] ?? '', $tableColumns);

            $invalidFields = [];
            foreach ($keyFields as $field => $value) {
                if (!in_array($field, $validFields, true)) {
                    $invalidFields[] = $field;
                }
            }

            if (!empty($invalidFields)) {
                return $this->paramError("关键字段 " . implode(', ', $invalidFields) . " 不存在于批注表 {$commentTable}");
            }

            $whereConditions = [];
            foreach ($keyFields as $field => $value) {
                if (is_numeric($value)) {
                    $whereConditions[] = sprintf('%s=%s', $field, $value);
                } else {
                    // 使用 quote() 转义用户输入值，防止 SQL 注入
                    // quote() 返回值已包含引号（如 'value'），无需再用 sprintf 包裹
                    $whereConditions[] = sprintf('%s=%s', $field, $this->model->quote((string) $value));
                }
            }

            if (empty($whereConditions)) {
                return $this->success(['records' => [], 'total' => 0]);
            }

            $whereStr = implode(' and ', $whereConditions);

            $orderBy = '';
            if (in_array('操作时间', $validFields, true)) {
                $orderBy = ' order by 操作时间 desc';
            } elseif (in_array('创建时间', $validFields, true)) {
                $orderBy = ' order by 创建时间 desc';
            }

            $sql = sprintf('select * from %s where %s%s', $commentTable, $whereStr, $orderBy);

            $query = $this->model->select($sql);
            if ($query === false) {
                return $this->serverError('查询批注列表失败');
            }

            $results = $query->getResultArray();

            return $this->success(['records' => $results, 'total' => count($results)]);
        } catch (\Throwable $e) {
            log_message('error', 'Comment::list - ' . $e->getMessage());
            return $this->serverError('获取批注列表失败：' . $e->getMessage());
        }
    }

    public function add(string $functionCode = '')
    {
        try {
            if ($functionCode === '') {
                return $this->paramError('功能编码不能为空');
            }

            if (!$this->checkCommentAuth($functionCode)) {
                return $this->error(403, '无批注权限');
            }

            $payload = $this->getJsonInput();
            $keyFields = $payload['keyFields'] ?? [];
            $commentData = $payload['data'] ?? [];

            if (empty($keyFields)) {
                return $this->paramError('关键字段不能为空');
            }

            if (empty($commentData)) {
                return $this->paramError('批注内容不能为空');
            }

            $config = $this->getCommentConfig($functionCode);
            if (!$config) {
                return $this->paramError('该功能未配置批注模块');
            }

            $commentTable = $config['数据表名'];
            if (empty($commentTable)) {
                return $this->paramError('批注表名未配置');
            }

            $tableColumns = $this->model->select(sprintf('show columns from %s', $commentTable))->getResultArray();
            $validFields = [];
            foreach ($tableColumns as $col) {
                $fieldName = $col['Field'] ?? $col['field'] ?? '';
                if ($fieldName) {
                    $validFields[] = $fieldName;
                }
            }

            $userWorkId = $this->getUserWorkId();

            $fields = [];
            $values = [];

            foreach ($keyFields as $field => $value) {
                if (!in_array($field, $validFields, true)) {
                    continue;
                }
                $fields[] = $field;
                // 使用 quote() 转义用户输入值，防止 SQL 注入
                $values[] = is_numeric($value) ? $value : $this->model->quote((string) $value);
            }

            foreach ($commentData as $field => $value) {
                if (!in_array($field, $validFields, true)) {
                    continue;
                }
                $fields[] = $field;
                $values[] = is_numeric($value) ? $value : $this->model->quote((string) $value);
            }

            $fields[] = '操作人员';
            $values[] = $this->model->quote((string) $userWorkId);

            $sql = sprintf(
                'insert into %s (%s) values (%s)',
                $commentTable,
                implode(',', $fields),
                implode(',', $values)
            );

            $this->model->exec($sql);

            return $this->success(null, '添加批注成功');
        } catch (\Throwable $e) {
            log_message('error', 'Comment::add - ' . $e->getMessage());
            return $this->serverError('添加批注失败：' . $e->getMessage());
        }
    }

    public function fields(string $functionCode = '')
    {
        try {
            if ($functionCode === '') {
                return $this->paramError('功能编码不能为空');
            }

            $config = $this->getCommentConfig($functionCode);
            if (!$config) {
                return $this->success(['fields' => []]);
            }

            $commentTable = $config['数据表名'] ?? '';
            if (empty($commentTable)) {
                return $this->success(['fields' => []]);
            }

            try {
                $results = $this->model->select(sprintf('show columns from %s', $commentTable))->getResultArray();
            } catch (\Throwable $e) {
                return $this->success(['fields' => []]);
            }

            $keyFieldMap = [];
            $keyFieldsStr = $config['原表字段'] ?? '';

            if ($keyFieldsStr !== '') {
                $pairs = explode(';', $keyFieldsStr);
                foreach ($pairs as $pair) {
                    $pair = trim($pair);
                    if ($pair === '') continue;

                    if (strpos($pair, ':') !== false) {
                        $parts = explode(':', $pair);
                        $keyFieldMap[trim($parts[0])] = trim($parts[1]);
                    } else {
                        $keyFieldMap[$pair] = $pair;
                    }
                }
            }

            if (empty($keyFieldMap)) {
                $systemFields = ['id', '操作人员', '创建时间', '更新时间'];
                foreach ($results as $row) {
                    $fieldName = $row['Field'] ?? $row['field'] ?? '';
                    if ($fieldName && !in_array($fieldName, $systemFields, true)) {
                        $keyFieldMap[$fieldName] = $fieldName;
                        break;
                    }
                }
            }

            $excludeFields = ['id', '操作人员', '创建时间', '更新时间'];
            $fields = [];
            foreach ($results as $row) {
                $fieldName = $row['Field'] ?? $row['field'] ?? '';
                if (empty($fieldName)) {
                    continue;
                }

                if (in_array($fieldName, $excludeFields, true)) {
                    continue;
                }

                $isKeyField = isset($keyFieldMap[$fieldName]);

                $fieldType = $row['Type'] ?? $row['type'] ?? 'varchar';
                $fieldComment = $row['Comment'] ?? $row['comment'] ?? $fieldName;

                $fields[] = [
                    'name' => $fieldName,
                    'type' => $this->getFieldType($fieldType),
                    'comment' => $fieldComment,
                    'isKeyField' => $isKeyField,
                    'sourceColumn' => $keyFieldMap[$fieldName] ?? ''
                ];
            }

            $returnKeyFields = $config['原表字段'] ?? '';
            if (empty($returnKeyFields) && !empty($keyFieldMap)) {
                $pairs = [];
                foreach ($keyFieldMap as $fieldName => $sourceColumn) {
                    if ($fieldName === $sourceColumn) {
                        $pairs[] = $fieldName;
                    } else {
                        $pairs[] = "{$fieldName}:{$sourceColumn}";
                    }
                }
                $returnKeyFields = implode(';', $pairs);
            }

            return $this->success(['fields' => $fields, 'keyFields' => $returnKeyFields]);
        } catch (\Throwable $e) {
            log_message('error', 'Comment::fields - ' . $e->getMessage());
            return $this->success(['fields' => []]);
        }
    }

    private function getCommentConfig(string $functionCode): ?array
    {
        $metadataCache = new MetadataCache();

        // 1. 从缓存获取 def_function 和 def_query_config 数据
        $funcRow = $metadataCache->getFunctionConfig($functionCode);
        $moduleName = $funcRow['模块名称'] ?? '';

        $configRow = $metadataCache->getQueryConfigByFunction($functionCode);
        $remarkModule = $configRow['备注模块'] ?? '';
        $dataTable = $configRow['数据表名'] ?? '';

        // 2. 直接查 def_comment_config（单表，无 JOIN）
        $sql = sprintf(
            'select 备注模块,备注表名,功能编码,原表字段
            from def_comment_config
            where 功能编码=%s
            limit 1',
            $this->model->quote($functionCode)
        );
        $row = $this->model->select($sql)->getRowArray();

        if ($row && !empty($dataTable)) {
            $row['模块名称'] = $moduleName;
            $row['数据表名'] = $dataTable;
            return $row;
        }

        // 3. 回退：通过备注模块间接查 def_comment_config
        if (!empty($remarkModule)) {
            $sql = sprintf(
                'select 备注模块,备注表名,功能编码,原表字段
                from def_comment_config
                where 备注模块=%s
                limit 1',
                $this->model->quote($remarkModule)
            );
            $row = $this->model->select($sql)->getRowArray();
            if ($row) {
                $row['模块名称'] = $moduleName;
                $row['数据表名'] = $row['备注表名'] ?? '';
                return $row;
            }
        }

        return null;
    }

    private function checkCommentAuth(string $functionCode): bool
    {
        $userRole = $this->userContext->getSessionUser()['role'];

        if (empty($userRole)) {
            return false;
        }

        $sql = sprintf(
            'select max(备注授权) as 备注授权
            from view_role
            where 有效标识="1" and 角色编码 in (%s) and 功能编码赋权="%s"',
            $userRole,
            $functionCode
        );

        try {
            $row = $this->model->select($sql)->getRowArray();
            return ($row['备注授权'] ?? '0') === '1';
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function getFieldType(string $dbType): string
    {
        $dbType = strtolower($dbType);
        if (strpos($dbType, 'int') !== false || strpos($dbType, 'decimal') !== false || strpos($dbType, 'float') !== false || strpos($dbType, 'double') !== false) {
            return '数值';
        }
        if (strpos($dbType, 'date') !== false || strpos($dbType, 'time') !== false) {
            return '日期';
        }
        return '字符';
    }
}
