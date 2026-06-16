<?php

namespace App\Services\Workbench;

use App\Models\Mcommon;

/**
 * 导入服务类
 * 负责处理工作台导入相关的业务逻辑
 */
class ImportService
{
    private Mcommon $model;

    public function __construct()
    {
        $this->model = new Mcommon();
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
        $sql = sprintf(
            'select 导入模块 from def_query_config 
            where 查询模块 in (
                select 模块名称 from def_function 
                where 有效标识="1" and 功能编码=%s
            )',
            $this->quote($functionCode)
        );

        $query = $this->model->select($sql);
        if ($query === false) {
            log_message('error', '查询 def_query_config 失败');
            return ['columns' => []];
        }

        $row = $query->getRowArray();
        $importModule = (string) ($row['导入模块'] ?? '');

        if ($importModule === '') {
            return ['columns' => []];
        }

        $sql = sprintf(
            'select 列名, 字段名, 查询名, 顺序, 字段类型, 校验类型, 导入类型, 缺省值
            from def_import_column
            where 导入模块=%s
            order by 顺序',
            $this->quote($importModule)
        );

        $query = $this->model->select($sql);
        if ($query === false) {
            log_message('error', '查询 def_import_column 失败');
            return ['columns' => []];
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

        return ['columns' => $columns, 'importModule' => $importModule];
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

        if ($importModule !== '') {
            $sql = sprintf(
                'select 列名, 字段名, 查询名, 顺序, 字段类型, 字段长度, 校验信息, 校验类型, 对象, 导入类型, 系统变量, 匹配标识, 缺省值
                from def_import_column
                where 导入模块=%s
                order by 顺序',
                $this->quote($importModule)
            );
            $query = $this->model->select($sql);
            if ($query !== false) {
                $importColumns = $query->getResultArray();
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
            'requiredColumns' => $requiredColumns
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
            $sql = sprintf('CREATE TABLE %s (id int auto_increment primary key, data varchar(255))', $tableName);
            $result = $this->model->exec($sql);
            return $result !== false;
        }

        $fieldDefs = [];
        foreach ($columns as $col) {
            $fieldName = $col['字段名'] ?? $col['列名'];
            $fieldLength = $col['字段长度'] ?? 255;
            $defaultValue = (string) ($col['缺省值'] ?? '');
            if ($defaultValue !== '') {
                $fieldDefs[] = sprintf('%s varchar(%s) not null default %s', $fieldName, $fieldLength, $this->quote($defaultValue));
            } else {
                $fieldDefs[] = sprintf('%s varchar(%s) not null default ""', $fieldName, $fieldLength);
            }
        }

        $sql = sprintf('CREATE TABLE %s (%s)', $tableName, implode(',', $fieldDefs));
        $result = $this->model->exec($sql);

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
        $sql = sprintf('DROP TABLE IF EXISTS %s', $tableName);
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
                $rowValues[] = $this->quote((string) $value);
            }
            $values[] = '(' . implode(',', $rowValues) . ')';
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $tableName,
            implode(', ', $fields),
            implode(', ', $values)
        );

        $result = $this->model->exec($sql);
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
                        select "%s" as 字段名, %s as 字段值
                        from %s
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
                    'select "%s" as 字段名, %s as 字段值 from %s where %s',
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
                    'select "%s" as 字段名, %s as 字段值 from %s',
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
                $this->quote($importModule)
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
                'select %s from %s where concat(%s) in (select concat(%s) from %s)',
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
     * @return array ['success' => bool, 'count' => int, 'message' => string, 'errors' => array]
     */
    public function importFromTempTable(string $targetTable, string $tempTable, array $importColumns): array
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

            $sql = sprintf(
                'INSERT INTO %s (%s) SELECT %s FROM %s',
                $targetTable,
                implode(', ', $fieldNames),
                implode(', ', $selectParts),
                $tempTable
            );

            $result = $db->query($sql);
            $affectedRows = $db->affectedRows();

            $db->transComplete();

            if ($result === false) {
                return [
                    'success' => false,
                    'count'   => 0,
                    'message' => '导入失败：执行导入SQL失败',
                    'errors'  => [['sql' => $sql]],
                ];
            }

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
                $this->quote($importModule)
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
            $this->model->select($spSql);
        } catch (\Throwable $e) {
            log_message('error', '执行后处理模块失败: ' . $e->getMessage());
        }
    }

    /**
     * 引用值
     *
     * @param string $value 要引用的值
     * @return string
     */
    private function quote(string $value): string
    {
        return "'" . addslashes($value) . "'";
    }
}
