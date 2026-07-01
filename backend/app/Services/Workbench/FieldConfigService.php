<?php

namespace App\Services\Workbench;

use App\Libraries\MetadataCache;
use App\Libraries\SessionUserContext;
use App\Models\Mcommon;

/**
 * 字段配置服务类
 *
 * 负责工作台字段配置的加载与组装，包括新增字段、详情字段、
 * 批量编辑字段、修改字段、数据表配置、对象选项等。
 * 从 EditService 中拆分而来，遵循单一职责原则。
 */
class FieldConfigService
{
    private Mcommon $model;
    private MetadataCache $metadataCache;
    private SessionUserContext $userContext;

    public function __construct()
    {
        $this->model = new Mcommon();
        $this->metadataCache = new MetadataCache();
        $this->userContext = new SessionUserContext();
    }

    /**
     * 获取新增字段配置
     *
     * @param string $functionCode 功能编码
     * @param string|null $fieldModule 字段模块
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

            $popupColumnMap = $this->getPopupColumnMap();
            log_message('info', "getAddFields: popupColumnMap keys: " . json_encode(array_keys($popupColumnMap), JSON_UNESCAPED_UNICODE));

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

                if (strpos($赋值类型, '多选') !== false && !empty($对象)) {
                    $field['inputType'] = 'multiSelect';
                    $field['objectName'] = $对象;
                    $field['objectOptions'] = $optionsMap[$对象] ?? [];
                }

                if (strpos($赋值类型, '弹窗') !== false && !empty($对象)) {
                    $field['inputType'] = 'popup';
                    $field['objectName'] = $对象;
                }

                $columnName = $col['列名'] ?? '';
                $inPopupMap = isset($popupColumnMap[$columnName]);
                if ($inPopupMap) {
                    $field['inputType'] = 'popup';
                    $field['objectName'] = $popupColumnMap[$columnName];
                    log_message('info', "getAddFields: columnName={$columnName} found in popupColumnMap, objectName={$field['objectName']}");
                }

                if (empty($field['inputType'])) {
                    $field['inputType'] = 'text';
                }

                $fields[] = $field;
            }

            log_message('info', "getAddFields: returning " . count($fields) . " fields");
            foreach ($fields as $f) {
                if (($f['inputType'] ?? '') === 'popup') {
                    log_message('info', "  popup field: columnName={$f['columnName']}, objectName={$f['objectName']}");
                }
            }

            return ['fields' => $fields];
        } catch (\Throwable $e) {
            log_message('error', "getAddFields exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            throw $e;
        }
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

            $popupColumnMap = $this->getPopupColumnMap();

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

                if (strpos($赋值类型, '多选') !== false && !empty($对象)) {
                    $field['inputType']    = 'multiSelect';
                    $field['objectName']   = $对象;
                    $field['objectOptions'] = $optionsMap[$对象] ?? [];
                }

                if (strpos($赋值类型, '弹窗') !== false && !empty($对象)) {
                    $field['inputType']  = 'popup';
                    $field['objectName'] = $对象;
                }

                $columnName = $col['列名'] ?? '';
                if (!empty($columnName) && isset($popupColumnMap[$columnName])) {
                    $field['inputType'] = 'popup';
                    $field['objectName'] = $popupColumnMap[$columnName];
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

            $popupColumnMap = $this->getPopupColumnMap();

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
                    'objectName' => '',
                    'inputType'  => 'text',
                ];

                $赋值类型 = $col['赋值类型'] ?? '';
                $对象     = $col['对象']     ?? '';

                if (strpos($赋值类型, '固定值') !== false && !empty($对象)) {
                    $field['objectName']   = $对象;
                    $field['objectOptions'] = $optionsMap[$对象] ?? [];
                }

                if (strpos($赋值类型, '多选') !== false && !empty($对象)) {
                    $field['inputType']    = 'multiSelect';
                    $field['objectName']   = $对象;
                    $field['objectOptions'] = $optionsMap[$对象] ?? [];
                }

                if (strpos($赋值类型, '弹窗') !== false && !empty($对象)) {
                    $field['inputType']  = 'popup';
                    $field['objectName'] = $对象;
                }

                $columnName = $col['列名'] ?? '';
                if (!empty($columnName) && isset($popupColumnMap[$columnName])) {
                    $field['inputType'] = 'popup';
                    $field['objectName'] = $popupColumnMap[$columnName];
                }

                $fields[] = $field;
            }

            $currentData = [];
            if (!empty($dataTable) && !empty($primaryKey) && !empty($keyValues)) {
                $keyStr = implode(',', array_map(fn($v) => $this->model->quote((string) $v), $keyValues));
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
                    select 模块名称 from def_function where 功能编码=%s
                )',
                $this->model->quote($functionCode)
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
     * 获取对象选项
     *
     * @param string $objectName 对象名称
     * @return array
     */
    public function getObjectOptions(string $objectName): array
    {
        try {
            $userLocation = $this->userContext->getLocation();

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
     * 处理系统变量默认值
     *
     * @param string $defaultValue 默认值
     * @return string
     */
    private function processSystemDefaultValue(string $defaultValue): string
    {
        switch ($defaultValue) {
            case '$当日日期':
                return date('Y-m-d');
            case '$时间戳':
                return date('Y-m-d H:i:s');
            case '$工号':
                return $this->userContext->getWorkId();
            case '$属地':
                return $this->userContext->getLocation();
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
     * 批量获取多个对象的选项（避免 N+1 查询）
     *
     * @param array $objectNames 对象名称列表
     * @return array 按对象名分组的选项
     */
    private function getObjectOptionsBatch(array $objectNames): array
    {
        if (empty($objectNames)) {
            return [];
        }

        try {
            $uniqueNames = array_values(array_unique($objectNames));
            $userLocation = $this->userContext->getLocation();

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
     * 从 def_query_column 表获取弹窗配置映射（使用长缓存）
     *
     * @return array [列名/查询名/字段名 => 对象名]
     */
    private function getPopupColumnMap(): array
    {
        return $this->metadataCache->getPopupColumnMap();
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
