<?php

namespace App\Services\Workbench;

use App\Models\Mcommon;
use App\Libraries\MetadataCache;

/**
 * 导入服务类
 * 负责处理工作台导入相关的业务逻辑
 */
class ImportService
{
    private Mcommon $model;
    private MetadataCache $metadataCache;

    public function __construct()
    {
        $this->model = new Mcommon();
        $this->metadataCache = new MetadataCache();
    }

    /**
     * 构造导入失败的标准响应数组
     *
     * @param array $importData 待导入数据
     * @param string $message 失败消息
     * @return array
     */
    public function buildImportFailure(array $importData, string $message): array
    {
        return [
            'success'      => false,
            'message'      => $message,
            'total'        => count($importData),
            'successCount' => 0,
            'errorCount'   => count($importData),
            'errors'       => [['error' => $message]],
        ];
    }

    /**
     * 获取导入列配置
     *
     * @param string $functionCode 功能编码
     * @return array 导入列配置
     */
    public function getImportColumns(string $functionCode): array
    {
        $config = $this->metadataCache->getQueryConfigByFunction($functionCode);
        if ($config === null) {
            log_message('error', '查询 def_query_config 失败');
            return ['columns' => [], 'headerRow' => 1, 'dataRow' => 2];
        }

        $importModule = (string) ($config['导入模块'] ?? '');

        $headerRow = 1;
        $dataRow = 2;

        if ($importModule !== '') {
            $sql = sprintf(
                'select 表头行, 数据行 from def_import_config where 导入模块=%s',
                $this->model->quote($importModule)
            );
            $query = $this->model->select($sql);
            if ($query !== false) {
                $configRow = $query->getRowArray();
                if ($configRow) {
                    $headerRow = (int) ($configRow['表头行'] ?? 1);
                    $dataRow = (int) ($configRow['数据行'] ?? 2);
                }
            }
        }

        if ($importModule === '') {
            return ['columns' => [], 'headerRow' => $headerRow, 'dataRow' => $dataRow];
        }

        $sql = sprintf(
            'select 列名, 字段名, 查询名, 顺序, 字段类型, 校验类型, 导入类型, 缺省值
            from def_import_column
            where 导入模块=%s
            order by 顺序',
            $this->model->quote($importModule)
        );

        $query = $this->model->select($sql);
        if ($query === false) {
            log_message('error', '查询 def_import_column 失败');
            return ['columns' => [], 'headerRow' => $headerRow, 'dataRow' => $dataRow];
        }

        $results = $query->getResultArray();
        $columns = [];
        foreach ($results as $row) {
            $columns[] = [
                'columnName' => (string) ($row['列名'] ?? ''),
                'fieldName' => (string) ($row['字段名'] ?? ''),
                'queryName' => (string) ($row['查询名'] ?? ''),
                'columnOrder' => (int) ($row['顺序'] ?? 0),
                'columnType' => (string) ($row['字段类型'] ?? ''),
                'checkType' => (string) ($row['校验类型'] ?? ''),
                'importType' => (string) ($row['导入类型'] ?? ''),
                'defaultValue' => (string) ($row['缺省值'] ?? '')
            ];
        }

        return ['columns' => $columns, 'importModule' => $importModule, 'headerRow' => $headerRow, 'dataRow' => $dataRow];
    }

    /**
     * 获取导入配置信息
     *
     * @param string $functionCode 功能编码
     * @param string $menu1 一级菜单
     * @param string $menu2 二级菜单
     * @param string $userWorkid 用户工号
     * @param string $dataTable 数据表
     * @param string $importModule 导入模块
     * @return array
     */
    public function getImportConfig(string $functionCode, string $menu1, string $menu2, string $userWorkid, string $dataTable, string $importModule): array
    {
        $importColumns = [];
        $headerRow = 1;
        $dataRow = 2;

        if ($importModule !== '') {
            $sql = sprintf(
                'select 列名, 字段名, 查询名, 顺序, 字段类型, 字段长度, 校验信息, 校验类型, 对象, 导入类型, 系统变量, 匹配标识, 缺省值
                from def_import_column
                where 导入模块=%s
                order by 顺序',
                $this->model->quote($importModule)
            );
            $query = $this->model->select($sql);
            if ($query !== false) {
                $importColumns = $query->getResultArray();
            }

            $sql = sprintf(
                'select 表头行, 数据行 from def_import_config where 导入模块=%s',
                $this->model->quote($importModule)
            );
            $query = $this->model->select($sql);
            if ($query !== false) {
                $row = $query->getRowArray();
                if ($row) {
                    $headerRow = (int) ($row['表头行'] ?? 1);
                    $dataRow = (int) ($row['数据行'] ?? 2);
                }
            }
        }

        if (empty($importColumns)) {
            $sql = sprintf('SHOW COLUMNS FROM %s', $dataTable);
            $query = $this->model->select($sql);
            if ($query !== false) {
                $fields = $query->getResultArray();
                foreach ($fields as $field) {
                    $importColumns[] = [
                        '列名' => $field['Field'],
                        '字段名' => $field['Field'],
                        '导入类型' => ($field['Null'] === 'NO' && $field['Default'] === null) ? '1' : '0'
                    ];
                }
            }
        }

        $tmpTableName = sprintf('tmp_%s_%s_%s_%s', $functionCode, $menu1, $menu2, $userWorkid);

        $fieldMap = [];
        $requiredColumns = [];
        foreach ($importColumns as $col) {
            $columnName = $col['列名'] ?? '';
            $fieldMap[$columnName] = [
                'field' => $col['字段名'],
                'fieldType' => $col['字段类型'] ?? '字符',
                'fieldLength' => $col['字段长度'] ?? 255,
                'required' => ($col['导入类型'] ?? '0') === '1',
                'checkType' => $col['校验类型'] ?? '',
                'checkInfo' => $col['校验信息'] ?? '',
                'object' => $col['对象'] ?? '',
                'systemVar' => $col['系统变量'] ?? '',
                'matchFlag' => $col['匹配标识'] ?? '0'
            ];

            if (($col['匹配标识'] ?? '0') === '1') {
                $requiredColumns[] = $columnName;
            }
        }

        return [
            'tmpTableName' => $tmpTableName,
            'importColumns' => $importColumns,
            'fieldMap' => $fieldMap,
            'requiredColumns' => $requiredColumns,
            'headerRow' => $headerRow,
            'dataRow' => $dataRow
        ];
    }

    /**
     * 验证导入数据
     *
     * @param array $importData 导入数据
     * @param array $fieldMap 字段映射
     * @param array $requiredColumns 必填列
     * @param array $systemVars 系统变量
     * @return array
     */
    public function validateImportData(array $importData, array $fieldMap, array $requiredColumns, array $systemVars): array
    {
        // 调试：对比 importData 的 key 和 fieldMap 的 key
        if (!empty($importData)) {
            log_message('debug', '[ImportService] validateImportData importData 第一行 key: ' . json_encode(array_keys($importData[0]), JSON_UNESCAPED_UNICODE));
            log_message('debug', '[ImportService] validateImportData importData 第一行数据: ' . json_encode($importData[0], JSON_UNESCAPED_UNICODE));
            log_message('debug', '[ImportService] validateImportData fieldMap key: ' . json_encode(array_keys($fieldMap), JSON_UNESCAPED_UNICODE));
        }

        if (!empty($importData)) {
            $firstRow = $importData[0];
            $missingColumns = [];
            foreach ($requiredColumns as $reqCol) {
                if (!array_key_exists($reqCol, $firstRow)) {
                    $missingColumns[] = $reqCol;
                }
            }

            if (!empty($missingColumns)) {
                return [
                    'hasError' => true,
                    'message' => sprintf('导入失败,缺少必须的字段"%s"', implode('","', $missingColumns)),
                    'errors' => [['error' => sprintf('缺少必须的字段: %s', implode(', ', $missingColumns))]]
                ];
            }
        }

        $errors = [];
        $validData = [];
        foreach ($importData as $rowIndex => $row) {
            $rowErrors = [];
            $validRow = [];

            foreach ($fieldMap as $columnName => $config) {
                $value = $row[$columnName] ?? '';
                $fieldName = $config['field'];
                $systemVar = $config['systemVar'] ?? '';

                if (($value === '' || $value === null) && $systemVar !== '') {
                    if (isset($systemVars[$systemVar])) {
                        $value = $systemVars[$systemVar];
                    }
                }

                if ($config['required'] && ($value === '' || $value === null)) {
                    $rowErrors[] = sprintf('字段 "%s" 不能为空', $columnName);
                }

                $validRow[$fieldName] = $value;
            }

            if (!empty($rowErrors)) {
                $errors[] = [
                    'row' => $rowIndex + 1,
                    'errors' => $rowErrors,
                    'data' => $row
                ];
            } else {
                $validData[] = $validRow;
            }
        }

        if (!empty($errors)) {
            return [
                'hasError' => true,
                'message' => sprintf('验证失败，共 %d 行数据有误', count($errors)),
                'errors' => $errors
            ];
        }

        return [
            'hasError' => false,
            'validData' => $validData,
            'errors' => []
        ];
    }

    /**
     * 预校验字段长度（避免 insertToTempTable 时触发 Data too long）
     *
     * 在写入临时表前逐行检查每个字段值长度是否超出 def_import_column.字段长度 配置，
     * 超长时返回行级错误，避免 MySQL 抛 "Data too long for column 'xxx' at row N"
     * 被控制器 Throwable catch 后仅返回笼统的"导入数据失败"。
     *
     * @param array $data          待写入的数据（每行为 [字段名 => 值]）
     * @param array $importColumns 导入列配置（含 字段名、字段长度、列名）
     * @return array ['hasError' => bool, 'message' => string, 'errors' => array]
     */
    public function validateFieldLength(array $data, array $importColumns): array
    {
        if (empty($data) || empty($importColumns)) {
            return ['hasError' => false, 'message' => '', 'errors' => []];
        }

        // 构建 字段名 => [字段长度, 列名] 映射
        $lengthMap = [];
        foreach ($importColumns as $col) {
            $fieldName = $col['字段名'] ?? '';
            if ($fieldName === '') {
                continue;
            }
            $fieldLength = isset($col['字段长度']) ? (int) $col['字段长度'] : 255;
            if ($fieldLength <= 0) {
                $fieldLength = 255;
            }
            $lengthMap[$fieldName] = [
                'length'    => $fieldLength,
                'columnName' => $col['列名'] ?? $fieldName,
            ];
        }

        $errors = [];
        foreach ($data as $idx => $row) {
            $rowNum = $idx + 1;
            foreach ($lengthMap as $fieldName => $cfg) {
                if (!array_key_exists($fieldName, $row)) {
                    continue;
                }
                $value = (string) ($row[$fieldName] ?? '');
                // 按字符数计算长度（与 MySQL varchar 语义一致，utf8mb4 一个汉字算 1 字符）
                $valueLen = mb_strlen($value, 'UTF-8');
                if ($valueLen > $cfg['length']) {
                    $errors[] = [
                        'row'       => $rowNum,
                        'field'     => $fieldName,
                        'column'    => $cfg['columnName'],
                        'length'    => $cfg['length'],
                        'actual'    => $valueLen,
                        'value'     => mb_substr($value, 0, 30, 'UTF-8'),
                        'error'     => sprintf(
                                    '第 %d 行 %s 字段超长：%d 字符 > %d 字符（值：%s）',
                                    $rowNum,
                                    $cfg['columnName'],
                                    $valueLen,
                                    $cfg['length'],
                                    mb_substr($value, 0, 30, 'UTF-8')
                                ),
                    ];
                }
            }
        }

        if (empty($errors)) {
            return ['hasError' => false, 'message' => '', 'errors' => []];
        }

        $message = sprintf('字段长度校验失败，共 %d 处超长', count($errors));
        return ['hasError' => true, 'message' => $message, 'errors' => $errors];
    }

    /**
     * 创建临时表
     *
     * @param string $tableName 表名
     * @param array $columns 列定义
     * @return bool
     */
    public function createTempTable(string $tableName, array $columns): bool
    {
        $this->dropTempTable($tableName);

        if (empty($columns)) {
            $sql = sprintf('CREATE TABLE `%s` (id int auto_increment primary key, data varchar(255))', $tableName);
            $result = $this->model->exec($sql);
            return $result !== false;
        }

        $fieldDefs = [];
        $fieldNamesForLog = [];
        foreach ($columns as $col) {
            $fieldName = $col['字段名'] ?? $col['列名'];
            $fieldNamesForLog[] = $fieldName;
            $fieldLength = $col['字段长度'] ?? 255;
            $defaultValue = (string) ($col['缺省值'] ?? '');
            if ($defaultValue !== '') {
                $fieldDefs[] = sprintf('`%s` varchar(%s) not null default %s', $fieldName, $fieldLength, $this->model->quote($defaultValue));
            } else {
                $fieldDefs[] = sprintf('`%s` varchar(%s) not null default ""', $fieldName, $fieldLength);
            }
        }

        $sql = sprintf('CREATE TABLE `%s` (%s)', $tableName, implode(',', $fieldDefs));
        log_message('debug', '[ImportService] createTempTable 字段名列表: ' . json_encode($fieldNamesForLog, JSON_UNESCAPED_UNICODE));
        log_message('debug', '[ImportService] createTempTable SQL: ' . $sql);

        $result = $this->model->exec($sql);
        log_message('debug', '[ImportService] createTempTable 结果: ' . var_export($result, true));

        return $result !== false;
    }

    /**
     * 删除临时表
     *
     * @param string $tableName 表名
     * @return bool
     */
    public function dropTempTable(string $tableName): bool
    {
        $sql = sprintf('DROP TABLE IF EXISTS `%s`', $tableName);
        $result = $this->model->exec($sql);
        return $result !== false;
    }

    /**
     * 插入数据到临时表
     *
     * 字段值为空时，若 def_import_column 中配置了 缺省值，则使用缺省值写入。
     *
     * @param string $tableName 表名
     * @param array $data 数据
     * @param array $importColumns 导入列配置（含 字段名、缺省值）
     * @return bool
     */
    public function insertToTempTable(string $tableName, array $data, array $importColumns = []): bool
    {
        if (empty($data)) {
            return true;
        }

        // 调试：记录 validData 的字段名和第一行数据
        log_message('debug', '[ImportService] insertToTempTable 表名: ' . $tableName);
        log_message('debug', '[ImportService] validData 字段名: ' . json_encode(array_keys($data[0]), JSON_UNESCAPED_UNICODE));
        log_message('debug', '[ImportService] validData 第一行数据: ' . json_encode($data[0], JSON_UNESCAPED_UNICODE));

        $defaultValueMap = [];
        foreach ($importColumns as $col) {
            $fieldName = $col['字段名'] ?? '';
            $defaultValue = (string) ($col['缺省值'] ?? '');
            if ($fieldName !== '' && $defaultValue !== '') {
                $defaultValueMap[$fieldName] = $defaultValue;
            }
        }

        $fields = array_keys($data[0]);
        $values = [];

        foreach ($data as $row) {
            $rowValues = [];
            foreach ($fields as $field) {
                $value = $row[$field] ?? '';
                if (($value === '' || $value === null) && isset($defaultValueMap[$field])) {
                    $value = $defaultValueMap[$field];
                }
                $rowValues[] = $this->model->quote((string) $value);
            }
            $values[] = '(' . implode(',', $rowValues) . ')';
        }

        $quotedFields = array_map(static fn($f) => '`' . $f . '`', $fields);

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES %s',
            $tableName,
            implode(', ', $quotedFields),
            implode(', ', $values)
        );

        log_message('debug', '[ImportService] insertToTempTable SQL: ' . substr($sql, 0, 500));

        $result = $this->model->exec($sql);
        log_message('debug', '[ImportService] insertToTempTable 结果: ' . var_export($result, true));
        return $result !== false;
    }

    /**
     * 校验临时表数据（固定值/条件/日期）
     *
     * @param string $tmpTableName 临时表名
     * @param array $importColumns 导入列配置
     * @param string $userLocation 用户属地
     * @return array ['hasError' => bool, 'message' => string, 'errors' => array]
     */
    public function validateImportDataByTable(string $tmpTableName, array $importColumns, string $userLocation): array
    {
        $userLocationAuthz = $userLocation ?: '';

        foreach ($importColumns as $col) {
            $columnName = $col['列名'] ?? '';
            $fieldName = $col['字段名'] ?? '';
            $checkType = $col['校验类型'] ?? '';
            $checkInfo = $col['校验信息'] ?? '';
            $object = $col['对象'] ?? '';

            if ($checkType === '' || $fieldName === '') {
                continue;
            }

            // 固定值校验
            if (strpos($checkType, '固定值') !== false && $object !== '') {
                $sql = sprintf('
                    select
                        t1.字段名 as 字段名,
                        t1.字段值 as 字段值,
                        ifnull(t2.对象值,"") as 对象值
                    from
                    (
                        select "%s" as 字段名, `%s` as 字段值
                        from `%s`
                        group by 字段值
                    ) as t1
                    left join
                    (
                        select 对象名称,对象值
                        from def_object
                        where 对象名称="%s"
                            and (属地="" or locate(属地,"%s"))
                    ) as t2 on t1.字段值=t2.对象值
                    where t2.对象值 is null and t1.字段值 != ""
                ',
                    $fieldName, $fieldName, $tmpTableName,
                    $object, $userLocationAuthz);

                $result = $this->model->select($sql);
                if ($result !== false) {
                    $errs = $result->getResultArray();
                    if (count($errs) != 0) {
                        $errArr = [];
                        foreach ($errs as $err) {
                            $errArr[] = $err['字段值'];
                        }
                        return [
                            'hasError' => true,
                            'message'  => sprintf('导入失败,列"%s"有不符合固定值的记录 {"%s"}', $columnName, implode(',', $errArr)),
                            'errors'   => $errs,
                        ];
                    }
                }
            }

            // 条件校验
            if (strpos($checkType, '条件') !== false && $checkInfo !== '') {
                $sql = sprintf(
                    'select "%s" as 字段名, `%s` as 字段值 from `%s` where %s',
                    $columnName, $fieldName, $tmpTableName, $checkInfo
                );

                $result = $this->model->select($sql);
                if ($result !== false) {
                    $errs = $result->getResultArray();
                    if (count($errs) != 0) {
                        $errArr = [];
                        foreach ($errs as $err) {
                            $errArr[] = $err['字段值'];
                        }
                        return [
                            'hasError' => true,
                            'message'  => sprintf('导入失败,列"%s"有不符合条件的记录 {"%s"}', $columnName, implode(',', $errArr)),
                            'errors'   => $errs,
                        ];
                    }
                }
            }

            // 日期格式校验
            if (strpos($checkType, '日期') !== false) {
                $sql = sprintf(
                    'select "%s" as 字段名, `%s` as 字段值 from `%s`',
                    $columnName, $fieldName, $tmpTableName
                );

                $result = $this->model->select($sql);
                if ($result !== false) {
                    $dates = $result->getResult();
                    foreach ($dates as $date) {
                        if ($date->字段值 == '') {
                            continue;
                        }
                        $parts = [];
                        if (preg_match("/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/", $date->字段值, $parts)) {
                            if (checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) == false) {
                                return [
                                    'hasError' => true,
                                    'message'  => sprintf('导入失败,列"%s"有不符合的记录{"%s"},必须为YYYY-mm-dd (如2023-01-02) 格式', $columnName, $date->字段值),
                                    'errors'   => [['字段值' => $date->字段值]],
                                ];
                            }
                        } else {
                            return [
                                'hasError' => true,
                                'message'  => sprintf('导入失败,列"%s"有不符合的记录{"%s"},必须为YYYY-mm-dd (如2023-01-02) 格式', $columnName, $date->字段值),
                                'errors'   => [['字段值' => $date->字段值]],
                            ];
                        }
                    }
                }
            }
        }

        return [
            'hasError' => false,
            'message'  => '校验通过',
            'errors'   => [],
        ];
    }

    /**
     * 检查滤重字段是否有重复记录
     *
     * @param string $importModule 导入模块
     * @param string $dataTable 数据表
     * @param string $tmpTableName 临时表名
     * @return array ['hasError' => bool, 'message' => string, 'errors' => array]
     */
    public function checkDuplicateFields(string $importModule, string $dataTable, string $tmpTableName): array
    {
        try {
            $sql = sprintf(
                'select 滤重字段 from def_import_config where 导入模块=%s',
                $this->model->quote($importModule)
            );

            $result = $this->model->select($sql);
            if ($result === false) {
                return ['hasError' => false, 'message' => '', 'errors' => []];
            }

            $row = $result->getRowArray();
            if (!$row || empty($row['滤重字段'])) {
                return ['hasError' => false, 'message' => '', 'errors' => []];
            }

            $duplicateFields = $row['滤重字段'];

            $sql = sprintf(
                'select `%s` from `%s` where concat(`%s`) in (select concat(`%s`) from `%s`)',
                $duplicateFields,
                $dataTable,
                $duplicateFields,
                $duplicateFields,
                $tmpTableName
            );

            $result = $this->model->select($sql);
            if ($result === false) {
                return ['hasError' => false, 'message' => '', 'errors' => []];
            }

            $errs = $result->getResultArray();
            if (count($errs) > 0) {
                $errArr = [];
                foreach ($errs as $err) {
                    $str = '';
                    foreach ($err as $item) {
                        if ($str !== '') {
                            $str = $str . '^';
                        }
                        $str = $str . $item;
                    }
                    $errArr[] = $str;
                }

                return [
                    'hasError' => true,
                    'message'  => sprintf('导入失败,滤重列"%s"有重复记录 {"%s"}', $duplicateFields, implode(',', $errArr)),
                    'errors'   => $errs,
                ];
            }

            return ['hasError' => false, 'message' => '', 'errors' => []];
        } catch (\Throwable $e) {
            log_message('error', '滤重检查失败: ' . $e->getMessage());
            return ['hasError' => false, 'message' => '', 'errors' => []];
        }
    }

    /**
     * 从临时表导入数据到正式表，应用查询名中的转换
     *
     * @param string $targetTable 目标表
     * @param string $tempTable 临时表
     * @param array $importColumns 导入列配置
     * @param string $importModule 导入模块（用于读取 def_import_config.导入条件）
     * @return array ['success' => bool, 'count' => int, 'message' => string, 'errors' => array]
     */
    public function importFromTempTable(string $targetTable, string $tempTable, array $importColumns, string $importModule = ''): array
    {
        try {
            $db = db_connect('btdc');
            $db->transStart();

            $fieldNames = [];
            $selectParts = [];

            foreach ($importColumns as $col) {
                $fieldName = $col['字段名'] ?? $col['列名'] ?? '';
                $queryName = $col['查询名'] ?? '';

                if ($fieldName === '') {
                    continue;
                }

                $fieldNames[] = sprintf('`%s`', $fieldName);

                if ($queryName !== '' && $queryName !== $fieldName) {
                    $selectParts[] = sprintf('%s as `%s`', $queryName, $fieldName);
                } else {
                    $selectParts[] = sprintf('`%s`', $fieldName);
                }
            }

            if (empty($fieldNames)) {
                return [
                    'success' => false,
                    'count'   => 0,
                    'message' => '没有可导入的字段',
                    'errors'  => [],
                ];
            }

            // 读取导入条件（SQL 片段，引用临时表字段，由管理员在 def_import_config 中维护）
            $whereClause = $this->getImportCondition($importModule);
            $whereSql = $whereClause !== '' ? ' WHERE ' . $whereClause : '';

            $sql = sprintf(
                'INSERT INTO `%s` (%s) SELECT %s FROM `%s`%s',
                $targetTable,
                implode(', ', $fieldNames),
                implode(', ', $selectParts),
                $tempTable,
                $whereSql
            );

            log_message('debug', '[ImportService] 导入SQL: ' . $sql);

            $result = $db->query($sql);
            log_message('debug', '[ImportService] SQL执行完成, result type: ' . (is_object($result) ? get_class($result) : gettype($result)));

            $affectedRows = $db->affectedRows();
            log_message('debug', '[ImportService] affectedRows: ' . $affectedRows);

            $db->transComplete();
            log_message('debug', '[ImportService] transComplete done');

            if ($result === false) {
                return [
                    'success' => false,
                    'count'   => 0,
                    'message' => '导入失败：执行导入SQL失败',
                    'errors'  => [['sql' => $sql]],
                ];
            }

            log_message('debug', '[ImportService] 返回 success=true, count=' . $affectedRows);
            return [
                'success' => true,
                'count'   => $affectedRows,
                'message' => sprintf('成功导入 %d 条数据', $affectedRows),
                'errors'  => [],
            ];
        } catch (\Throwable $e) {
            log_message('error', '从临时表导入失败: ' . $e->getMessage());
            return [
                'success' => false,
                'count'   => 0,
                'message' => '导入失败：' . $e->getMessage(),
                'errors'  => [['error' => $e->getMessage()]],
            ];
        }
    }

    /**
     * 执行导入前处理模块
     *
     * 支持在 def_import_config.前处理模块 中配置带参数的存储过程，例如：
     *   sp_公司_财务_银行收支明细_兴业_导入前处理($源表, @out)
     *   - $源表：占位符，会被替换为本次导入的临时表名
     *   - @out：可选，MySQL 用户变量，用于返回前处理执行后的消息
     *
     * @param string $importModule 导入模块
     * @param string $tmpTableName 本次导入临时表名
     * @return array ['success' => bool, 'message' => string]
     */
    public function executeBeforeProcess(string $importModule, string $tmpTableName): array
    {
        try {
            $sql = sprintf(
                'select 前处理模块 from def_import_config where 导入模块=%s',
                $this->model->quote($importModule)
            );

            $result = $this->model->select($sql);
            if ($result === false) {
                return ['success' => true, 'message' => ''];
            }

            $row = $result->getRowArray();
            if (!$row || empty($row['前处理模块'])) {
                return ['success' => true, 'message' => ''];
            }

            $beforeProcess = $row['前处理模块'];

            // 替换 $源表 为本次导入的临时表名字符串字面量。
            // 占位符两侧若已有单/双引号会一并去掉，然后用 model->quote 重新包裹，
            // 确保传给存储过程的是一个合法的 SQL 字符串值。
            $beforeProcess = preg_replace('/[\'"]?\$源表[\'"]?/', $this->model->quote($tmpTableName), $beforeProcess);

            // 兼容旧配置：如果配置里没写 call 前缀，自动补全
            if (strpos(ltrim($beforeProcess), 'call ') !== 0) {
                $beforeProcess = 'call ' . $beforeProcess;
            }

            // 若存储过程使用了 @out 输出参数，先初始化该会话变量
            $hasOutParam = strpos($beforeProcess, '@out') !== false;
            if ($hasOutParam) {
                $this->model->select("set @out = ''");
            }

            ob_start();
            try {
                $this->model->select($beforeProcess);
            } finally {
                ob_end_clean();
            }

            $message = '前处理执行成功';
            if ($hasOutParam) {
                $outResult = $this->model->select('select @out as out_message');
                if ($outResult) {
                    $outRow = $outResult->getRowArray();
                    $outMessage = $outRow['out_message'] ?? '';
                    if ($outMessage !== '') {
                        $message = (string) $outMessage;
                    }
                }
            }

            return ['success' => true, 'message' => $message];
        } catch (\Throwable $e) {
            log_message('error', '执行前处理模块失败: ' . $e->getMessage());
            return ['success' => false, 'message' => '执行前处理模块失败: ' . $e->getMessage()];
        }
    }

    /**
     * 执行导入后处理模块
     *
     * @param string $importModule 导入模块
     * @return void
     */
    public function executeAfterProcess(string $importModule): void
    {
        try {
            $sql = sprintf(
                'select 后处理模块 from def_import_config where 导入模块=%s',
                $this->model->quote($importModule)
            );

            $result = $this->model->select($sql);
            if ($result === false) {
                return;
            }

            $row = $result->getRowArray();
            if (!$row || empty($row['后处理模块'])) {
                return;
            }

            $afterProcess = $row['后处理模块'];
            $spSql = sprintf('call %s', $afterProcess);

            ob_start();
            try {
                $this->model->select($spSql);
            } finally {
                ob_end_clean();
            }
        } catch (\Throwable $e) {
            log_message('error', '执行后处理模块失败: ' . $e->getMessage());
        }
    }

    /**
     * 构建导入调试 SQL（不复用 importFromTempTable 的执行路径，
     * 仅生成 createTempTable/insertToTempTable/importFromTempTable 等"看一眼就知道会跑什么"的诊断数据）
     *
     * @param string $functionCode 功能编码
     * @param string $menu1 菜单1
     * @param string $menu2 菜单2
     * @param string $userWorkid 用户工号
     * @param string $dataTable 数据表
     * @param string $importModule 导入模块
     * @param array $sampleData 示例数据（可选）
     * @return array
     */
    public function buildDebugImport(
        string $functionCode,
        string $menu1 = '',
        string $menu2 = '',
        string $userWorkid = '',
        string $dataTable = '',
        string $importModule = '',
        array $sampleData = []
    ): array {
        try {
            $importConfig = $this->getImportConfig(
                $functionCode,
                $menu1,
                $menu2,
                $userWorkid,
                $dataTable,
                $importModule
            );
            $importColumns = $importConfig['importColumns'];
            $tmpTableName = $importConfig['tmpTableName'];

            $createTempTableSql = $this->buildCreateTempTableSql($tmpTableName, $importColumns);

            $insertToTempTableSql = '';
            if (!empty($sampleData)) {
                $insertToTempTableSql = $this->buildInsertToTempTableSql($tmpTableName, $sampleData, $importColumns);
            }

            $importFromTempTableSql = $this->buildImportFromTempTableSql($dataTable, $tmpTableName, $importColumns, $importModule);

            return [
                'success'               => true,
                'tmpTableName'          => $tmpTableName,
                'dataTable'             => $dataTable,
                'importModule'          => $importModule,
                'createTempTableSql'    => $createTempTableSql,
                'insertToTempTableSql'  => $insertToTempTableSql,
                'importFromTempTableSql'=> $importFromTempTableSql,
                'importColumns'         => $importColumns,
                'headerRow'             => $importConfig['headerRow'],
                'dataRow'               => $importConfig['dataRow'],
            ];
        } catch (\Throwable $e) {
            log_message('error', '构建导入调试 SQL 失败: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => '构建导入调试 SQL 失败: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * 构建创建临时表 SQL
     *
     * @param string $tableName 表名
     * @param array $columns 列配置
     * @return string
     */
    private function buildCreateTempTableSql(string $tableName, array $columns): string
    {
        if (empty($columns)) {
            return sprintf('CREATE TABLE `%s` (id int auto_increment primary key, data varchar(255))', $tableName);
        }

        $fieldDefs = [];
        foreach ($columns as $col) {
            $fieldName = $col['字段名'] ?? $col['列名'];
            $fieldLength = $col['字段长度'] ?? 255;
            $defaultValue = (string) ($col['缺省值'] ?? '');
            if ($defaultValue !== '') {
                $fieldDefs[] = sprintf('`%s` varchar(%s) not null default %s', $fieldName, $fieldLength, $this->model->quote($defaultValue));
            } else {
                $fieldDefs[] = sprintf('`%s` varchar(%s) not null default ""', $fieldName, $fieldLength);
            }
        }

        return sprintf('CREATE TABLE `%s` (%s)', $tableName, implode(',', $fieldDefs));
    }

    /**
     * 构建插入临时表 SQL
     *
     * @param string $tableName 表名
     * @param array $data 数据
     * @param array $importColumns 导入列配置
     * @return string
     */
    private function buildInsertToTempTableSql(string $tableName, array $data, array $importColumns): string
    {
        $fields = [];
        foreach ($importColumns as $col) {
            $fieldName = $col['字段名'] ?? $col['列名'] ?? '';
            if ($fieldName !== '') {
                $fields[] = sprintf('`%s`', $fieldName);
            }
        }

        if (empty($fields)) {
            return '';
        }

        $defaultValueMap = [];
        foreach ($importColumns as $col) {
            $fieldName = $col['字段名'] ?? '';
            $defaultValue = (string) ($col['缺省值'] ?? '');
            if ($fieldName !== '' && $defaultValue !== '') {
                $defaultValueMap[$fieldName] = $defaultValue;
            }
        }

        $values = [];
        foreach ($data as $row) {
            $rowValues = [];
            foreach ($fields as $field) {
                $fieldName = trim($field, '`');
                $value = $row[$fieldName] ?? '';
                if (($value === '' || $value === null) && isset($defaultValueMap[$fieldName])) {
                    $value = $defaultValueMap[$fieldName];
                }
                $rowValues[] = $this->model->quote((string) $value);
            }
            $values[] = '(' . implode(',', $rowValues) . ')';
        }

        if (empty($values)) {
            return '';
        }

        return sprintf('INSERT INTO `%s` (%s) VALUES %s', $tableName, implode(',', $fields), implode(',', $values));
    }

    /**
     * 构建从临时表导入正式表的 SQL
     *
     * @param string $targetTable 目标表
     * @param string $tempTable 临时表
     * @param array $importColumns 导入列配置
     * @param string $importModule 导入模块（用于读取 def_import_config.导入条件）
     * @return string
     */
    private function buildImportFromTempTableSql(string $targetTable, string $tempTable, array $importColumns, string $importModule = ''): string
    {
        $fieldNames = [];
        $selectParts = [];

        foreach ($importColumns as $col) {
            $fieldName = $col['字段名'] ?? $col['列名'] ?? '';
            $queryName = $col['查询名'] ?? '';

            if ($fieldName === '') {
                continue;
            }

            $fieldNames[] = sprintf('`%s`', $fieldName);

            if ($queryName !== '' && $queryName !== $fieldName) {
                $selectParts[] = sprintf('%s as `%s`', $queryName, $fieldName);
            } else {
                $selectParts[] = sprintf('`%s`', $fieldName);
            }
        }

        if (empty($fieldNames)) {
            return '-- 没有可导入的字段';
        }

        // 读取导入条件（与 importFromTempTable 保持一致，便于调试预览真实 SQL）
        $whereClause = $this->getImportCondition($importModule);
        $whereSql = $whereClause !== '' ? ' WHERE ' . $whereClause : '';

        return sprintf('INSERT INTO `%s` (%s) SELECT %s FROM `%s`%s',
            $targetTable,
            implode(', ', $fieldNames),
            implode(', ', $selectParts),
            $tempTable,
            $whereSql
        );
    }

    /**
     * 读取导入条件（def_import_config.导入条件 字段）
     *
     * 该字段为 SQL 片段，引用临时表字段，由管理员在 def_import_config 中维护。
     * 与 滤重字段/前处理模块/后处理模块 等字段处理方式一致，直接拼接使用。
     * 空值或查询失败时返回空字符串，保持原有行为不变。
     *
     * @param string $importModule 导入模块
     * @return string 导入条件 SQL 片段（不含 WHERE 关键字）
     */
    private function getImportCondition(string $importModule): string
    {
        if ($importModule === '') {
            return '';
        }

        try {
            $sql = sprintf(
                'select 导入条件 from def_import_config where 导入模块=%s',
                $this->model->quote($importModule)
            );

            $result = $this->model->select($sql);
            if ($result === false) {
                return '';
            }

            $row = $result->getRowArray();
            if (!$row) {
                return '';
            }

            $condition = trim((string) ($row['导入条件'] ?? ''));
            if ($condition !== '') {
                log_message('debug', sprintf('[ImportService] 命中导入条件: %s', $condition));
            }
            return $condition;
        } catch (\Throwable $e) {
            log_message('error', '读取导入条件失败: ' . $e->getMessage());
            return '';
        }
    }
}
