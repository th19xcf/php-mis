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
                $this->model->quote($functionCode)
            );

            log_message('info', "getAddFields SQL: {$sql}");

            $result = $this->model->select($sql);
            if ($result === false) {
                log_message('error', "getAddFields: query failed for functionCode={$functionCode}");
                return ['fields' => []];
            }

            $columns = $result->getResultArray();
            log_message('info', "getAddFields: found " . count($columns) . " columns");

            // 批量预取所有"固定值/多选"列的对象选项，避免循环内 N+1 查询。
            $objectNames = [];
            foreach ($columns as $col) {
                $赋值类型 = $col['赋值类型'] ?? '';
                $对象 = $col['对象'] ?? '';
                if (!empty($对象)
                    && (strpos($赋值类型, '固定值') !== false || strpos($赋值类型, '多选') !== false)
                ) {
                    $objectNames[] = $对象;
                }
            }
            $optionsMap = $this->getObjectOptionsBatch($objectNames);

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
                    $field['objectOptions'] = $optionsMap[$对象] ?? [];
                }

                // 多选：从 def_object 取值，前端渲染为 multiple NSelect，
                // 最终值以 "," 分隔写入字段。
                // 注意：多选与固定值是互斥语义，如果同时出现以多选为准。
                if (strpos($赋值类型, '多选') !== false && !empty($对象)) {
                    $field['inputType'] = 'multiSelect';
                    $field['objectName'] = $对象;
                    $field['objectOptions'] = $optionsMap[$对象] ?? [];
                }

                if (strpos($赋值类型, '弹窗') !== false && !empty($对象)) {
                    $field['inputType'] = 'popup';
                    $field['objectName'] = $对象;
                } elseif (empty($field['inputType'])) {
                    // 只有当 inputType 还没被多选分支设过时才回退到 text，
                    // 避免被覆盖回 text。
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
            $this->model->quote($functionCode)
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
                $this->model->quote($objectName),
                $this->model->quote($userLocation)
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
     * 批量获取多个对象的选项（避免 N+1 查询）
     *
     * 在 getAddFields / getBatchEditFields / getUpdateFields 中，
     * 若直接在 foreach 内调用 getObjectOptions()，每个"固定值/多选"列都会
     * 触发一次独立 SQL 查询 def_object 表。本方法用一次 IN 查询批量取回，
     * 在内存中按对象名分组，将 N 次查询降为 1 次。
     *
     * @param array $objectNames 对象名称列表（可含重复值，内部自动去重）
     * @return array 按对象名分组的选项 [对象名 => [['label' => x, 'value' => x], ...]]
     */
    private function getObjectOptionsBatch(array $objectNames): array
    {
        if (empty($objectNames)) {
            return [];
        }

        try {
            $uniqueNames = array_values(array_unique($objectNames));
            $session = \Config\Services::session();
            $userLocation = $session->get('user_location') ?? '';

            $quotedNames = implode(',', array_map(
                fn($name) => $this->model->quote($name),
                $uniqueNames
            ));

            $sql = sprintf(
                'select 对象名称, 对象值 from def_object
                 where 对象名称 in (%s)
                 and (属地="" or locate(属地, %s))',
                $quotedNames,
                $this->model->quote($userLocation)
            );

            $result = $this->model->select($sql);
            if ($result === false) {
                return array_fill_keys($uniqueNames, []);
            }

            $grouped = [];
            foreach ($result->getResultArray() as $row) {
                $name = $row['对象名称'];
                $value = $row['对象值'];
                $grouped[$name][] = [
                    'label' => $value,
                    'value' => $value,
                ];
            }

            // 为请求过的每个对象名都补一个键（即使没数据也是空数组），
            // 避免调用方需要做 isset 判断。
            foreach ($uniqueNames as $name) {
                if (!isset($grouped[$name])) {
                    $grouped[$name] = [];
                }
            }

            return $grouped;
        } catch (\Throwable $e) {
            log_message('error', 'getObjectOptionsBatch 失败: ' . $e->getMessage());
            return array_fill_keys(array_values(array_unique($objectNames)), []);
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
     * 获取详情显示字段配置
     *
     * @param string $functionCode 功能编码
     * @param string|null $fieldModule 字段模块
     * @return array ['fields' => array]
     */
    public function getDetailFields(string $functionCode, ?string $fieldModule): array
    {
        try {
            if (empty($fieldModule)) {
                $fieldModule = $this->getFieldModule($functionCode);
            }

            if (empty($fieldModule)) {
                return ['fields' => []];
            }

            $sql = sprintf(
                'select
                    列名, 字段名, 列类型, 列宽度, 可修改, 不可为空, 列顺序
                from view_function
                where 功能编码=%s and 列顺序>0
                group by 列名
                order by 列顺序',
                $this->model->quote($functionCode)
            );

            $result = $this->model->select($sql);
            if ($result === false) {
                return ['fields' => []];
            }

            $columns = $result->getResultArray();
            $fields = [];

            foreach ($columns as $col) {
                $fields[] = [
                    'columnName' => $col['列名'],
                    'fieldName'  => $col['字段名'],
                    'fieldType'  => $col['列类型'] ?? '字符',
                    'width'      => (int) (($col['列宽度'] ?? 0) > 0 ? $col['列宽度'] : max(strlen((string) ($col['列名'] ?? '')) * 16, 120)),
                    'editable'   => in_array((string) ($col['可修改'] ?? '0'), ['1', '2'], true),
                    'required'   => (string) ($col['不可为空'] ?? '0') === '1',
                ];
            }

            return ['fields' => $fields];
        } catch (\Throwable $e) {
            log_message('error', 'getDetailFields 失败: ' . $e->getMessage());
            return ['fields' => []];
        }
    }

    /**
     * 获取批量修改字段配置（可修改="2"）
     *
     * @param string $functionCode 功能编码
     * @param string|null $fieldModule 字段模块
     * @return array ['fields' => array]
     */
    public function getBatchEditFields(string $functionCode, ?string $fieldModule): array
    {
        try {
            if (empty($fieldModule)) {
                $fieldModule = $this->getFieldModule($functionCode);
            }

            if (empty($fieldModule)) {
                return ['fields' => []];
            }

            $sql = sprintf(
                'select
                    列名, 字段名, 列类型, 赋值类型, 对象, 缺省值, 不可为空, 列顺序
                from view_function
                where 功能编码=%s and 列顺序>0 and 可修改="2"
                group by 列名
                order by 列顺序',
                $this->model->quote($functionCode)
            );

            $result = $this->model->select($sql);
            if ($result === false) {
                return ['fields' => []];
            }

            $columns = $result->getResultArray();
            $fields = [];

            // 批量预取所有"固定值/多选"列的对象选项，避免循环内 N+1 查询。
            $objectNames = [];
            foreach ($columns as $col) {
                $赋值类型 = $col['赋值类型'] ?? '';
                $对象 = $col['对象'] ?? '';
                if (!empty($对象)
                    && (strpos($赋值类型, '固定值') !== false || strpos($赋值类型, '多选') !== false)
                ) {
                    $objectNames[] = $对象;
                }
            }
            $optionsMap = $this->getObjectOptionsBatch($objectNames);

            foreach ($columns as $col) {
                $field = [
                    'columnName'   => $col['列名'],
                    'fieldName'    => $col['字段名'],
                    'fieldType'    => $col['列类型'] ?? '字符',
                    'required'     => (string) ($col['不可为空'] ?? '0') === '1',
                    'defaultValue' => $col['缺省值'] ?? '',
                    'objectName'   => '',
                    'inputType'    => 'text',
                ];

                // 处理系统变量默认值
                $defaultValue = $field['defaultValue'];
                if ($defaultValue === '$当日日期') {
                    $field['defaultValue'] = date('Y-m-d');
                } elseif ($defaultValue === '$时间戳') {
                    $field['defaultValue'] = date('Y-m-d H:i:s');
                } elseif ($defaultValue === '$工号') {
                    $field['defaultValue'] = $this->getSessionVar('user_workid');
                } elseif ($defaultValue === '$属地') {
                    $field['defaultValue'] = $this->getSessionVar('user_location');
                }

                $赋值类型 = $col['赋值类型'] ?? '';
                $对象 = $col['对象'] ?? '';

                if (strpos($赋值类型, '固定值') !== false && !empty($对象)) {
                    $field['objectName']   = $对象;
                    $field['objectOptions'] = $optionsMap[$对象] ?? [];
                }

                // 多选：从 def_object 取值，前端渲染为 multiple NSelect，
                // 最终值以 "," 分隔写入字段。
                if (strpos($赋值类型, '多选') !== false && !empty($对象)) {
                    $field['inputType']    = 'multiSelect';
                    $field['objectName']   = $对象;
                    $field['objectOptions'] = $optionsMap[$对象] ?? [];
                }

                if (strpos($赋值类型, '弹窗') !== false && !empty($对象)) {
                    $field['inputType']  = 'popup';
                    $field['objectName'] = $对象;
                }

                $fields[] = $field;
            }

            return ['fields' => $fields];
        } catch (\Throwable $e) {
            log_message('error', 'getBatchEditFields 失败: ' . $e->getMessage());
            return ['fields' => []];
        }
    }

    /**
     * 获取修改字段配置（可修改="1" 或 "2"），并查询指定记录当前数据
     *
     * @param string $functionCode 功能编码
     * @param array $keyValues 主键值数组
     * @param string $primaryKey 主键字段
     * @param string $dataTable 数据表
     * @return array ['fields' => array, 'currentData' => array]
     */
    public function getUpdateFields(string $functionCode, array $keyValues, string $primaryKey, string $dataTable): array
    {
        try {
            $sql = sprintf(
                'select
                    列名, 字段名, 列类型, 赋值类型, 对象, 缺省值, 不可为空, 可修改, 列顺序
                from view_function
                where 功能编码=%s and 列顺序>0 and (可修改="1" or 可修改="2")
                group by 列名
                order by 列顺序',
                $this->model->quote($functionCode)
            );

            $result = $this->model->select($sql);
            if ($result === false) {
                return ['fields' => [], 'currentData' => []];
            }

            $columns = $result->getResultArray();
            $fields = [];

            // 批量预取所有"固定值/多选"列的对象选项，避免循环内 N+1 查询。
            $objectNames = [];
            foreach ($columns as $col) {
                $赋值类型 = $col['赋值类型'] ?? '';
                $对象     = $col['对象']     ?? '';
                if (!empty($对象)
                    && (strpos($赋值类型, '固定值') !== false || strpos($赋值类型, '多选') !== false)
                ) {
                    $objectNames[] = $对象;
                }
            }
            $optionsMap = $this->getObjectOptionsBatch($objectNames);

            foreach ($columns as $col) {
                $field = [
                    'columnName' => $col['列名'],
                    'fieldName'  => $col['字段名'],
                    'fieldType'  => $col['列类型'] ?? '字符',
                    'editorType' => $col['赋值类型'] ?? '',
                    'required'   => (string) ($col['不可为空'] ?? '0') === '1',
                    'readonly'   => (string) ($col['可修改'] ?? '0') === '2',
                ];

                // 多选 / 固定值 都从 def_object 拉选项，editorType 保持原样
                // （更新/批量表单会通过 editorType 字符串来分支渲染）。
                $赋值类型 = $col['赋值类型'] ?? '';
                $对象     = $col['对象']     ?? '';
                if (!empty($对象)
                    && (strpos($赋值类型, '固定值') !== false || strpos($赋值类型, '多选') !== false)
                ) {
                    $field['objectName']   = $对象;
                    $field['objectOptions'] = $optionsMap[$对象] ?? [];
                }

                $fields[] = $field;
            }

            $currentData = [];
            if (!empty($dataTable) && !empty($primaryKey) && !empty($keyValues)) {
                $keyStr = implode(',', array_map(fn($v) => sprintf("'%s'", addslashes((string) $v)), $keyValues));
                $sql = sprintf(
                    'SELECT * FROM %s WHERE %s IN (%s) LIMIT 1',
                    $dataTable,
                    $primaryKey,
                    $keyStr
                );
                $result = $this->model->select($sql);
                if ($result !== false) {
                    $currentData = $result->getRowArray() ?: [];
                }
            }

            return [
                'fields'      => $fields,
                'currentData' => $currentData,
            ];
        } catch (\Throwable $e) {
            log_message('error', 'getUpdateFields 失败: ' . $e->getMessage());
            return ['fields' => [], 'currentData' => []];
        }
    }

    /**
     * 根据数据模式修改记录
     *
     * @param string $dataTable 数据表
     * @param string $dataModel 数据模式 (0=直接update; 1/2=软删+插新流水)
     * @param string $primaryKey 主键字段
     * @param array $keyValues 主键值数组
     * @param array $formData 表单数据
     * @param string $userWorkid 用户工号
     * @param string $functionCode 功能编码
     * @return int 影响行数
     */
    public function updateRowByModel(
        string $dataTable,
        string $dataModel,
        string $primaryKey,
        array $keyValues,
        array $formData,
        string $userWorkid,
        string $functionCode
    ): int {
        $keyStr = implode(',', array_map(fn($v) => sprintf("'%s'", addslashes((string) $v)), $keyValues));
        $where = sprintf('%s in (%s)', $primaryKey, $keyStr);

        $updates = [];
        foreach ($formData as $key => $value) {
            if ($key !== $primaryKey) {
                $updates[] = sprintf('`%s` = "%s"', $key, addslashes((string) $value));
            }
        }

        if (empty($updates)) {
            return 0;
        }

        switch ($dataModel) {
            case '0':
                $sql = sprintf(
                    'UPDATE %s SET %s WHERE %s',
                    $dataTable,
                    implode(', ', $updates),
                    $where
                );
                $this->model->sql_log('修改[0]', $functionCode, sprintf('表名=`%s`,主键=`%s`,值=`%s`', $dataTable, $primaryKey, $keyStr));
                return $this->model->exec($sql);

            case '1':
            case '2':
                $sqlSelect = sprintf('SELECT * FROM %s WHERE %s', $dataTable, $where);
                $result = $this->model->select($sqlSelect);
                if ($result === false) {
                    return 0;
                }
                $originalRow = $result->getRowArray();
                if (empty($originalRow)) {
                    return 0;
                }

                $sqlUpdateOld = sprintf(
                    'UPDATE %s SET 操作记录="修改",操作来源="工作台",操作人员="%s",操作时间="%s",结束操作时间="%s",删除标识="1",有效标识="0" WHERE %s',
                    $dataTable,
                    $userWorkid,
                    date('Y-m-d H:i:s'),
                    date('Y-m-d H:i:s'),
                    $where
                );
                $this->model->sql_log('修改[1-旧]', $functionCode, sprintf('表名=`%s`,主键=`%s`', $dataTable, $primaryKey));
                $this->model->exec($sqlUpdateOld);

                $fields = [];
                $values = [];
                foreach ($originalRow as $key => $val) {
                    if (array_key_exists($key, $formData)) {
                        $fields[] = sprintf('`%s`', $key);
                        $values[] = sprintf('"%s"', addslashes((string) $formData[$key]));
                    } else {
                        $fields[] = sprintf('`%s`', $key);
                        $values[] = sprintf('"%s"', addslashes((string) $val));
                    }
                }
                $fields[] = '`操作记录`';
                $values[] = '"新增"';
                $fields[] = '`操作来源`';
                $values[] = '"工作台"';
                $fields[] = '`操作人员`';
                $values[] = sprintf('"%s"', $userWorkid);
                $fields[] = '`操作时间`';
                $values[] = sprintf('"%s"', date('Y-m-d H:i:s'));
                $fields[] = '`结束操作时间`';
                $values[] = '"9999-12-31"';
                $fields[] = '`删除标识`';
                $values[] = '"0"';
                $fields[] = '`有效标识`';
                $values[] = '"1"';

                $sqlInsert = sprintf(
                    'INSERT INTO %s (%s) VALUES (%s)',
                    $dataTable,
                    implode(', ', $fields),
                    implode(', ', $values)
                );
                $this->model->sql_log('修改[1-新]', $functionCode, sprintf('表名=`%s`', $dataTable));
                return $this->model->exec($sqlInsert);

            default:
                return -1;
        }
    }

    /**
     * 批量修改多条记录
     *
     * @return int 影响行数（失败的数据模式返回 -1）
     */
    public function batchUpdateRowsByModel(
        string $dataTable,
        string $dataModel,
        string $primaryKey,
        array $keyValues,
        array $formData,
        string $userWorkid,
        string $functionCode
    ): int {
        $updates = [];
        foreach ($formData as $key => $value) {
            if ($key !== $primaryKey) {
                $updates[] = sprintf('`%s` = "%s"', $key, addslashes((string) $value));
            }
        }

        if (empty($updates)) {
            return 0;
        }

        $num = 0;
        switch ($dataModel) {
            case '0':
                foreach ($keyValues as $keyVal) {
                    $where = sprintf('%s = "%s"', $primaryKey, addslashes((string) $keyVal));
                    $sql = sprintf(
                        'UPDATE %s SET %s WHERE %s',
                        $dataTable,
                        implode(', ', $updates),
                        $where
                    );
                    $this->model->sql_log('批量修改[0]', $functionCode, sprintf('表名=`%s`,主键=`%s`,值=`%s`', $dataTable, $primaryKey, (string) $keyVal));
                    $num += $this->model->exec($sql);
                }
                return $num;

            case '1':
            case '2':
                foreach ($keyValues as $keyVal) {
                    $where = sprintf('%s = "%s"', $primaryKey, addslashes((string) $keyVal));

                    $sqlSelect = sprintf('SELECT * FROM %s WHERE %s', $dataTable, $where);
                    $result = $this->model->select($sqlSelect);
                    if ($result === false) {
                        continue;
                    }
                    $originalRow = $result->getRowArray();
                    if (empty($originalRow)) {
                        continue;
                    }

                    $sqlUpdateOld = sprintf(
                        'UPDATE %s SET 操作记录="修改",操作来源="工作台",操作人员="%s",操作时间="%s",结束操作时间="%s",删除标识="1",有效标识="0" WHERE %s',
                        $dataTable,
                        $userWorkid,
                        date('Y-m-d H:i:s'),
                        date('Y-m-d H:i:s'),
                        $where
                    );
                    $this->model->sql_log('批量修改[1-旧]', $functionCode, sprintf('表名=`%s`,主键=`%s`', $dataTable, $primaryKey));
                    $this->model->exec($sqlUpdateOld);

                    $fields = [];
                    $values = [];
                    foreach ($originalRow as $key => $val) {
                        if (array_key_exists($key, $formData)) {
                            $fields[] = sprintf('`%s`', $key);
                            $values[] = sprintf('"%s"', addslashes((string) $formData[$key]));
                        } else {
                            $fields[] = sprintf('`%s`', $key);
                            $values[] = sprintf('"%s"', addslashes((string) $val));
                        }
                    }
                    $fields[] = '`操作记录`';
                    $values[] = '"新增"';
                    $fields[] = '`操作来源`';
                    $values[] = '"工作台"';
                    $fields[] = '`操作人员`';
                    $values[] = sprintf('"%s"', $userWorkid);
                    $fields[] = '`操作时间`';
                    $values[] = sprintf('"%s"', date('Y-m-d H:i:s'));
                    $fields[] = '`结束操作时间`';
                    $values[] = '"9999-12-31"';
                    $fields[] = '`删除标识`';
                    $values[] = '"0"';
                    $fields[] = '`有效标识`';
                    $values[] = '"1"';

                    $sqlInsert = sprintf(
                        'INSERT INTO %s (%s) VALUES (%s)',
                        $dataTable,
                        implode(', ', $fields),
                        implode(', ', $values)
                    );
                    $this->model->sql_log('批量修改[1-新]', $functionCode, sprintf('表名=`%s`', $dataTable));
                    $num += $this->model->exec($sqlInsert);
                }
                return $num;

            default:
                return -1;
        }
    }

    /**
     * 根据数据模式删除记录
     *
     * @return int 影响行数（失败的数据模式返回 -1）
     */
    public function deleteRowByModel(
        string $dataTable,
        string $dataModel,
        string $primaryKey,
        array $keyValues,
        string $userWorkid,
        string $functionCode
    ): int {
        $keyStr = implode(',', array_map(fn($v) => sprintf("'%s'", addslashes((string) $v)), $keyValues));
        $where = sprintf('%s in (%s)', $primaryKey, $keyStr);

        switch ($dataModel) {
            case '0':
                $sql = sprintf('DELETE FROM %s WHERE %s', $dataTable, $where);
                $this->model->sql_log('删除[0]', $functionCode, sprintf('表名=`%s`,主键=`%s`,值=`%s`', $dataTable, $primaryKey, $keyStr));
                return $this->model->exec($sql);

            case '1':
            case '2':
                $sql = sprintf(
                    'UPDATE %s SET 操作记录="删除",操作来源="工作台",操作人员="%s",操作时间="%s",结束操作时间="%s",删除标识="1",有效标识="0" WHERE %s',
                    $dataTable,
                    $userWorkid,
                    date('Y-m-d H:i:s'),
                    date('Y-m-d H:i:s'),
                    $where
                );
                $this->model->sql_log('删除[1]', $functionCode, sprintf('表名=`%s`,主键=`%s`,值=`%s`', $dataTable, $primaryKey, $keyStr));
                return $this->model->exec($sql);

            default:
                return -1;
        }
    }

    /**
     * 表级批量修改（按行提交，按字段分组；单条走 UPDATE，多条走 CASE WHEN 批量更新）
     *
     * @param string $dataTable 数据表
     * @param string $dataModel 数据模式 (0=直接 update/case-when；1/2=软删+插新流水)
     * @param string $primaryKey 主键字段
     * @param array $rows 待修改的多行数据
     * @param string $userWorkid 用户工号
     * @param string $functionCode 功能编码
     * @return array ['success' => bool, 'count' => int, 'message' => string]
     */
    public function tableEditByModel(
        string $dataTable,
        string $dataModel,
        string $primaryKey,
        array $rows,
        string $userWorkid,
        string $functionCode
    ): array {
        if (empty($rows)) {
            return ['success' => false, 'count' => 0, 'message' => '没有要提交的修改数据'];
        }

        $skipFields = ['操作记录', '操作来源', '操作人员', '操作时间', '结束操作时间', '删除标识', '有效标识', '记录开始日期', '记录结束日期'];

        $num = 0;
        switch ($dataModel) {
            case '0':
                // 按 updateFields 分组
                $updateGroups = [];
                foreach ($rows as $row) {
                    $updateFields = [];
                    foreach ($row as $key => $value) {
                        if ($key !== $primaryKey && !in_array($key, $skipFields, true)) {
                            $updateFields[] = $key;
                        }
                    }
                    if (empty($updateFields)) {
                        continue;
                    }
                    sort($updateFields);
                    $groupKey = implode('|', $updateFields);

                    if (!isset($updateGroups[$groupKey])) {
                        $updateGroups[$groupKey] = [
                            'fields' => $updateFields,
                            'rows'   => [],
                        ];
                    }
                    $updateGroups[$groupKey]['rows'][] = $row;
                }

                foreach ($updateGroups as $group) {
                    $updateFields = $group['fields'];
                    $groupRows = $group['rows'];

                    if (count($groupRows) === 1) {
                        $row = $groupRows[0];
                        $where = $this->buildWhereFromPrimaryKey($row, $primaryKey);
                        if (empty($where)) {
                            continue;
                        }

                        $updates = [];
                        foreach ($row as $key => $value) {
                            if ($key !== $primaryKey && !in_array($key, $skipFields, true)) {
                                $updates[] = sprintf('`%s` = "%s"', $key, addslashes((string) $value));
                            }
                        }

                        $sql = sprintf('UPDATE %s SET %s WHERE %s', $dataTable, implode(', ', $updates), $where);
                        $this->model->sql_log('表级修改[0]', $functionCode, sprintf('表名=`%s`,主键=`%s`', $dataTable, $primaryKey));
                        $num += $this->model->exec($sql);
                    } else {
                        $caseStatements = [];
                        $primaryKeyValues = [];

                        foreach ($updateFields as $field) {
                            $caseParts = [];
                            foreach ($groupRows as $row) {
                                $pkValue = addslashes((string) ($row[$primaryKey] ?? ''));
                                $fieldValue = addslashes((string) ($row[$field] ?? ''));
                                $caseParts[] = sprintf('WHEN `%s` = "%s" THEN "%s"', $primaryKey, $pkValue, $fieldValue);
                                $primaryKeyValues[] = $pkValue;
                            }
                            $caseStatements[] = sprintf('`%s` = CASE %s ELSE `%s` END', $field, implode(' ', $caseParts), $field);
                        }

                        $primaryKeyValues = array_unique($primaryKeyValues);
                        $whereIn = sprintf('`%s` IN ("%s")', $primaryKey, implode('","', $primaryKeyValues));

                        $sql = sprintf(
                            'UPDATE %s SET %s WHERE %s',
                            $dataTable,
                            implode(', ', $caseStatements),
                            $whereIn
                        );

                        $this->model->sql_log('表级修改[0]', $functionCode, sprintf('表名=`%s`,主键=`%s`,批量数=%d', $dataTable, $primaryKey, count($groupRows)));
                        $num += $this->model->exec($sql);
                    }
                }
                return ['success' => true, 'count' => $num, 'message' => sprintf('表级修改提交成功,修改了 %d 条记录', $num)];

            case '1':
            case '2':
                $primaryKeyValues = [];
                $validRows = [];
                foreach ($rows as $row) {
                    $where = $this->buildWhereFromPrimaryKey($row, $primaryKey);
                    if (empty($where)) {
                        continue;
                    }
                    $primaryKeyValues[] = addslashes((string) ($row[$primaryKey] ?? ''));
                    $validRows[] = $row;
                }

                if (empty($validRows)) {
                    return ['success' => true, 'count' => 0, 'message' => '表级修改提交成功,修改了 0 条记录'];
                }

                $whereIn = sprintf('`%s` IN ("%s")', $primaryKey, implode('","', $primaryKeyValues));
                $sqlSelect = sprintf('SELECT * FROM %s WHERE %s', $dataTable, $whereIn);
                $result = $this->model->select($sqlSelect);
                if ($result === false) {
                    return ['success' => false, 'count' => 0, 'message' => '批量查询原始记录失败'];
                }

                $originalRows = [];
                foreach ($result->getResultArray() as $row) {
                    $originalRows[$row[$primaryKey]] = $row;
                }

                $sqlUpdateOld = sprintf(
                    'UPDATE %s SET 操作记录="修改",操作来源="工作台",操作人员="%s",操作时间="%s",结束操作时间="%s",删除标识="1",有效标识="0" WHERE %s',
                    $dataTable,
                    $userWorkid,
                    date('Y-m-d H:i:s'),
                    date('Y-m-d H:i:s'),
                    $whereIn
                );
                $this->model->sql_log('表级修改[1-旧]', $functionCode, sprintf('表名=`%s`,批量数=%d', $dataTable, count($validRows)));
                $this->model->exec($sqlUpdateOld);

                $insertValuesList = [];
                foreach ($validRows as $row) {
                    $pkValue = $row[$primaryKey];
                    if (!isset($originalRows[$pkValue])) {
                        continue;
                    }

                    $originalRow = $originalRows[$pkValue];
                    $fields = [];
                    $values = [];

                    foreach ($originalRow as $key => $val) {
                        if (isset($row[$key]) && !in_array($key, $skipFields, true)) {
                            $fields[] = sprintf('`%s`', $key);
                            $values[] = sprintf('"%s"', addslashes((string) $row[$key]));
                        } elseif (!in_array($key, $skipFields, true)) {
                            $fields[] = sprintf('`%s`', $key);
                            $values[] = sprintf('"%s"', addslashes((string) $val));
                        }
                    }

                    $fields[] = '`操作记录`';
                    $values[] = '"新增"';
                    $fields[] = '`操作来源`';
                    $values[] = '"工作台"';
                    $fields[] = '`操作人员`';
                    $values[] = sprintf('"%s"', $userWorkid);
                    $fields[] = '`操作时间`';
                    $values[] = sprintf('"%s"', date('Y-m-d H:i:s'));
                    $fields[] = '`结束操作时间`';
                    $values[] = '"9999-12-31"';
                    $fields[] = '`删除标识`';
                    $values[] = '"0"';
                    $fields[] = '`有效标识`';
                    $values[] = '"1"';

                    $insertValuesList[] = '(' . implode(', ', $values) . ')';
                }

                if (!empty($insertValuesList)) {
                    $allFields = [];
                    if (!empty($validRows)) {
                        $firstPk = $validRows[0][$primaryKey];
                        if (isset($originalRows[$firstPk])) {
                            foreach ($originalRows[$firstPk] as $key => $val) {
                                if (!in_array($key, $skipFields, true)) {
                                    $allFields[] = sprintf('`%s`', $key);
                                }
                            }
                        }
                    }
                    $allFields = array_merge($allFields, ['`操作记录`', '`操作来源`', '`操作人员`', '`操作时间`', '`结束操作时间`', '`删除标识`', '`有效标识`']);

                    $sqlInsert = sprintf(
                        'INSERT INTO %s (%s) VALUES %s',
                        $dataTable,
                        implode(', ', $allFields),
                        implode(', ', $insertValuesList)
                    );
                    $this->model->sql_log('表级修改[1-新]', $functionCode, sprintf('表名=`%s`,批量数=%d', $dataTable, count($insertValuesList)));
                    $num += $this->model->exec($sqlInsert);
                }

                return ['success' => true, 'count' => $num, 'message' => sprintf('表级修改提交成功,修改了 %d 条记录', $num)];

            default:
                return ['success' => false, 'count' => 0, 'message' => sprintf('修改失败,数据模式[-%s-]错误', $dataModel)];
        }
    }

    /**
     * 根据数据行与主键构建 WHERE 条件（分号分隔的复合主键）
     *
     * @param array $data
     * @param string $primaryKey
     * @return string
     */
    private function buildWhereFromPrimaryKey(array $data, string $primaryKey): string
    {
        $keys = explode(';', $primaryKey);
        $conditions = [];

        foreach ($keys as $key) {
            $key = trim($key);
            if (isset($data[$key])) {
                $conditions[] = sprintf('%s="%s"', $key, addslashes((string) $data[$key]));
            }
        }

        return implode(' and ', $conditions);
    }

    /**
     * 读取 session 变量
     */
    private function getSessionVar(string $key): string
    {
        $session = \Config\Services::session();
        return (string) ($session->get($key) ?? '');
    }
}
