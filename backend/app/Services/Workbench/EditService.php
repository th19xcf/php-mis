<?php

namespace App\Services\Workbench;

use App\Models\Mcommon;

/**
 * 编辑服务类
 * 负责处理工作台编辑相关的业务逻辑（新增、修改、删除、批量操作等）
 */
class EditService
{
    private Mcommon $model;

    public function __construct()
    {
        $this->model = new Mcommon();
    }

    /**
     * 获取新增字段配置
     *
     * @param string $functionCode 功能编码
     * @param string $fieldModule 字段模块
     * @return array 字段配置
     */
    public function getAddFields(string $functionCode, ?string $fieldModule): array
    {
        try {
            if (empty($fieldModule)) {
                $fieldModule = $this->getFieldModule($functionCode);
            }

            log_message('info', "getAddFields: functionCode={$functionCode}, fieldModule={$fieldModule}");

            if (empty($fieldModule)) {
                log_message('warning', "getAddFields: fieldModule is empty for functionCode={$functionCode}");
                return ['fields' => []];
            }

            $sql = sprintf(
                'select
                    列名, 字段名, 列类型, 赋值类型, 对象, 缺省值, 不可为空, 可新增, 列顺序
                from view_function
                where 功能编码=%s and 列顺序>0 and 可新增="1"
                group by 列名
                order by 列顺序',
                $this->quote($functionCode)
            );

            log_message('info', "getAddFields SQL: {$sql}");

            $result = $this->model->select($sql);
            if ($result === false) {
                log_message('error', "getAddFields: query failed for functionCode={$functionCode}");
                return ['fields' => []];
            }

            $columns = $result->getResultArray();
            log_message('info', "getAddFields: found " . count($columns) . " columns");

            $fields = [];

            foreach ($columns as $col) {
                $field = [
                    'columnName' => $col['列名'],
                    'fieldName' => $col['字段名'],
                    'fieldType' => $col['列类型'] ?? '字符',
                    'required' => ($col['不可为空'] ?? '0') === '1',
                    'defaultValue' => $col['缺省值'] ?? '',
                    'objectName' => '',
                    'editable' => true
                ];

                $field['defaultValue'] = $this->processSystemDefaultValue($field['defaultValue']);

                $赋值类型 = $col['赋值类型'] ?? '';
                $对象 = $col['对象'] ?? '';

                if (strpos($赋值类型, '固定值') !== false && !empty($对象)) {
                    $field['objectName'] = $对象;
                    $field['objectOptions'] = $this->getObjectOptions($对象);
                }

                if (strpos($赋值类型, '弹窗') !== false && !empty($对象)) {
                    $field['inputType'] = 'popup';
                    $field['objectName'] = $对象;
                } else {
                    $field['inputType'] = 'text';
                }

                $fields[] = $field;
            }

            return ['fields' => $fields];
        } catch (\Throwable $e) {
            log_message('error', "getAddFields exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * 处理系统变量默认值
     *
     * @param string $defaultValue 默认值
     * @return string
     */
    private function processSystemDefaultValue(string $defaultValue): string
    {
        $session = \Config\Services::session();

        switch ($defaultValue) {
            case '$当日日期':
                return date('Y-m-d');
            case '$时间戳':
                return date('Y-m-d H:i:s');
            case '$工号':
                return $session->get('user_workid') ?? '';
            case '$属地':
                return $session->get('user_location') ?? '';
            default:
                return $defaultValue;
        }
    }

    /**
     * 获取字段模块
     *
     * @param string $functionCode 功能编码
     * @return string
     */
    private function getFieldModule(string $functionCode): string
    {
        $sql = sprintf(
            'select 字段模块 from def_query_config where 查询模块 in (
                select 模块名称 from def_function where 有效标识="1" and 功能编码=%s
            )',
            $this->quote($functionCode)
        );

        $result = $this->model->select($sql);
        if ($result !== false) {
            $row = $result->getRowArray();
            return $row['字段模块'] ?? '';
        }

        return '';
    }

    /**
     * 获取对象选项
     *
     * @param string $objectName 对象名称
     * @return array
     */
    public function getObjectOptions(string $objectName): array
    {
        try {
            $session = \Config\Services::session();
            $userLocation = $session->get('user_location') ?? '';

            $sql = sprintf(
                'select 对象值 from def_object where 对象名称=%s and (属地="" or locate(属地, %s))',
                $this->quote($objectName),
                $this->quote($userLocation)
            );

            $result = $this->model->select($sql);
            if ($result === false) {
                return [];
            }

            $options = [];
            $rows = $result->getResultArray();
            foreach ($rows as $row) {
                $options[] = [
                    'label' => $row['对象值'],
                    'value' => $row['对象值']
                ];
            }

            return $options;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 获取数据表配置
     *
     * @param string $functionCode 功能编码
     * @param object|null $session 会话对象
     * @return array
     */
    public function getDataTableConfig(string $functionCode, $session): array
    {
        $dataTable = $session->get($functionCode . '-data_table');
        $dataModel = $session->get($functionCode . '-data_model');
        $beforeInsert = $session->get($functionCode . '-before_insert');
        $afterInsert = $session->get($functionCode . '-after_insert');
        $primaryKey = $session->get($functionCode . '-primary_key');

        if (empty($dataTable)) {
            $sql = sprintf(
                'select 数据表名, 数据模式, 新增前处理模块, 新增后处理模块, 主键字段
                from def_query_config
                where 查询模块 in (
                    select 模块名称 from def_function where 功能编码="%s"
                )',
                $functionCode
            );

            $result = $this->model->select($sql);
            if ($result !== false) {
                $row = $result->getRowArray();
                $dataTable = $row['数据表名'] ?? '';
                $dataModel = $row['数据模式'] ?? '0';
                $beforeInsert = $row['新增前处理模块'] ?? '';
                $afterInsert = $row['新增后处理模块'] ?? '';
                $primaryKey = $row['主键字段'] ?? '';
            }
        }

        return [
            'dataTable' => $dataTable,
            'dataModel' => $dataModel,
            'beforeInsert' => $beforeInsert,
            'afterInsert' => $afterInsert,
            'primaryKey' => $primaryKey
        ];
    }

    /**
     * 执行新增前处理
     *
     * @param string $beforeInsert 前处理模块
     */
    public function executeBeforeInsert(string $beforeInsert): void
    {
        if (!empty($beforeInsert)) {
            $spSql = sprintf('call %s("新增前", "")', $beforeInsert);
            $this->model->select($spSql);
        }
    }

    /**
     * 执行新增后处理
     *
     * @param string $afterInsert 后处理模块
     * @param string $primaryKey 主键字段
     * @param array $data 数据
     */
    public function executeAfterInsert(string $afterInsert, string $primaryKey, array $data): void
    {
        if (!empty($afterInsert) && !empty($primaryKey)) {
            $keyStr = $this->buildWhereFromData($data, $primaryKey);
            $spSql = sprintf('call %s("新增", "%s")', $afterInsert, $keyStr);
            $this->model->select($spSql);
        }
    }

    /**
     * 执行更新前处理
     *
     * @param string $beforeUpdate 前处理模块
     * @param string $primaryKey 主键字段
     * @param array $data 数据
     */
    public function executeBeforeUpdate(string $beforeUpdate, string $primaryKey, array $data): void
    {
        if (!empty($beforeUpdate) && !empty($primaryKey)) {
            $keyStr = $this->buildWhereFromData($data, $primaryKey);
            $spSql = sprintf('call %s("更新前", "%s")', $beforeUpdate, $keyStr);
            $this->model->select($spSql);
        }
    }

    /**
     * 执行更新后处理
     *
     * @param string $afterUpdate 后处理模块
     * @param string $primaryKey 主键字段
     * @param array $data 数据
     */
    public function executeAfterUpdate(string $afterUpdate, string $primaryKey, array $data): void
    {
        if (!empty($afterUpdate) && !empty($primaryKey)) {
            $keyStr = $this->buildWhereFromData($data, $primaryKey);
            $spSql = sprintf('call %s("更新", "%s")', $afterUpdate, $keyStr);
            $this->model->select($spSql);
        }
    }

    /**
     * 根据数据构建 WHERE 条件
     *
     * @param array $data 数据
     * @param string $primaryKey 主键字段
     * @return string
     */
    public function buildWhereFromData(array $data, string $primaryKey): string
    {
        $keys = explode(',', $primaryKey);
        $conditions = [];

        foreach ($keys as $key) {
            $key = trim($key);
            if (isset($data[$key])) {
                $conditions[] = sprintf('%s="%s"', $key, addslashes($data[$key]));
            }
        }

        return implode(' and ', $conditions);
    }

    /**
     * 模式0新增（基础模式）
     *
     * @param string $dataTable 数据表
     * @param array $data 数据
     * @return int 影响行数
     */
    public function addRowMode0(string $dataTable, array $data): int
    {
        $fields = [];
        $values = [];

        foreach ($data as $key => $value) {
            if ($key === '序号' || $key === 'SID') {
                continue;
            }

            $fields[] = $key;
            $values[] = is_array($value) ? json_encode($value) : $value;
        }

        if (empty($fields)) {
            return 0;
        }

        $fieldList = implode(',', $fields);
        $placeholders = implode(',', array_fill(0, count($values), '%s'));
        $sql = sprintf('insert into %s (%s) values (%s)', $dataTable, $fieldList, $placeholders);

        $this->model->query($sql, $values);

        return $this->model->affectedRows();
    }

    /**
     * 模式1新增（带创建人）
     *
     * @param string $dataTable 数据表
     * @param array $data 数据
     * @param string $userWorkid 用户工号
     * @return int 影响行数
     */
    public function addRowMode1(string $dataTable, array $data, string $userWorkid): int
    {
        $fields = ['创建人工号', '创建人姓名'];
        $values = [$userWorkid, ''];

        foreach ($data as $key => $value) {
            if ($key === '序号' || $key === 'SID') {
                continue;
            }

            $fields[] = $key;
            $values[] = is_array($value) ? json_encode($value) : $value;
        }

        $fieldList = implode(',', $fields);
        $placeholders = implode(',', array_fill(0, count($values), '%s'));
        $sql = sprintf('insert into %s (%s) values (%s)', $dataTable, $fieldList, $placeholders);

        $this->model->query($sql, $values);

        return $this->model->affectedRows();
    }

    /**
     * 模式2新增（带创建人和创建时间）
     *
     * @param string $dataTable 数据表
     * @param array $data 数据
     * @param string $userWorkid 用户工号
     * @return int 影响行数
     */
    public function addRowMode2(string $dataTable, array $data, string $userWorkid): int
    {
        $fields = ['创建人工号', '创建人姓名', '创建时间'];
        $values = [$userWorkid, '', date('Y-m-d H:i:s')];

        foreach ($data as $key => $value) {
            if ($key === '序号' || $key === 'SID') {
                continue;
            }

            $fields[] = $key;
            $values[] = is_array($value) ? json_encode($value) : $value;
        }

        $fieldList = implode(',', $fields);
        $placeholders = implode(',', array_fill(0, count($values), '%s'));
        $sql = sprintf('insert into %s (%s) values (%s)', $dataTable, $fieldList, $placeholders);

        $this->model->query($sql, $values);

        return $this->model->affectedRows();
    }

    /**
     * 模式0更新
     *
     * @param string $dataTable 数据表
     * @param array $data 数据
     * @param array $keys 主键值
     * @return int 影响行数
     */
    public function updateRowMode0(string $dataTable, array $data, array $keys): int
    {
        $setParts = [];
        $values = [];

        foreach ($data as $key => $value) {
            if ($key === '序号' || $key === 'SID') {
                continue;
            }

            $setParts[] = sprintf('%s=%s', $key, '%s');
            $values[] = is_array($value) ? json_encode($value) : $value;
        }

        if (empty($setParts)) {
            return 0;
        }

        $whereParts = [];
        foreach ($keys as $key => $value) {
            $whereParts[] = sprintf('%s=%s', $key, '%s');
            $values[] = $value;
        }

        $sql = sprintf(
            'update %s set %s where %s',
            $dataTable,
            implode(',', $setParts),
            implode(' and ', $whereParts)
        );

        $this->model->query($sql, $values);

        return $this->model->affectedRows();
    }

    /**
     * 模式1更新（带修改人）
     *
     * @param string $dataTable 数据表
     * @param array $data 数据
     * @param array $keys 主键值
     * @param string $userWorkid 用户工号
     * @return int 影响行数
     */
    public function updateRowMode1(string $dataTable, array $data, array $keys, string $userWorkid): int
    {
        $setParts = ['修改人工号=%s', '修改人姓名=%s'];
        $values = [$userWorkid, ''];

        foreach ($data as $key => $value) {
            if ($key === '序号' || $key === 'SID') {
                continue;
            }

            $setParts[] = sprintf('%s=%s', $key, '%s');
            $values[] = is_array($value) ? json_encode($value) : $value;
        }

        $whereParts = [];
        foreach ($keys as $key => $value) {
            $whereParts[] = sprintf('%s=%s', $key, '%s');
            $values[] = $value;
        }

        $sql = sprintf(
            'update %s set %s where %s',
            $dataTable,
            implode(',', $setParts),
            implode(' and ', $whereParts)
        );

        $this->model->query($sql, $values);

        return $this->model->affectedRows();
    }

    /**
     * 模式2更新（带修改人和修改时间）
     *
     * @param string $dataTable 数据表
     * @param array $data 数据
     * @param array $keys 主键值
     * @param string $userWorkid 用户工号
     * @return int 影响行数
     */
    public function updateRowMode1WithTime(string $dataTable, array $data, array $keys, string $userWorkid): int
    {
        $setParts = ['修改人工号=%s', '修改人姓名=%s', '修改时间=%s'];
        $values = [$userWorkid, '', date('Y-m-d H:i:s')];

        foreach ($data as $key => $value) {
            if ($key === '序号' || $key === 'SID') {
                continue;
            }

            $setParts[] = sprintf('%s=%s', $key, '%s');
            $values[] = is_array($value) ? json_encode($value) : $value;
        }

        $whereParts = [];
        foreach ($keys as $key => $value) {
            $whereParts[] = sprintf('%s=%s', $key, '%s');
            $values[] = $value;
        }

        $sql = sprintf(
            'update %s set %s where %s',
            $dataTable,
            implode(',', $setParts),
            implode(' and ', $whereParts)
        );

        $this->model->query($sql, $values);

        return $this->model->affectedRows();
    }

    /**
     * 删除记录
     *
     * @param string $dataTable 数据表
     * @param array $keys 主键值
     * @param string|null $beforeDelete 删除前处理
     * @param string|null $afterDelete 删除后处理
     * @return int 影响行数
     */
    public function deleteRow(string $dataTable, array $keys, ?string $beforeDelete = null, ?string $afterDelete = null): int
    {
        if (!empty($beforeDelete)) {
            $keyStr = implode(' and ', array_map(
                fn($k, $v) => sprintf('%s="%s"', $k, addslashes($v)),
                array_keys($keys),
                array_values($keys)
            ));
            $spSql = sprintf('call %s("删除前", "%s")', $beforeDelete, $keyStr);
            $this->model->select($spSql);
        }

        $whereParts = [];
        $values = [];
        foreach ($keys as $key => $value) {
            $whereParts[] = sprintf('%s=%s', $key, '%s');
            $values[] = $value;
        }

        $sql = sprintf(
            'delete from %s where %s',
            $dataTable,
            implode(' and ', $whereParts)
        );

        $this->model->query($sql, $values);
        $affectedRows = $this->model->affectedRows();

        if (!empty($afterDelete) && $affectedRows > 0) {
            $keyStr = implode(' and ', array_map(
                fn($k, $v) => sprintf('%s="%s"', $k, addslashes($v)),
                array_keys($keys),
                array_values($keys)
            ));
            $spSql = sprintf('call %s("删除", "%s")', $afterDelete, $keyStr);
            $this->model->select($spSql);
        }

        return $affectedRows;
    }

    /**
     * 引用值
     *
     * @param string $value 要引用的值
     * @return string 引用后的值
     */
    private function quote(string $value): string
    {
        return "'" . addslashes($value) . "'";
    }
}
