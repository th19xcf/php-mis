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
            'select 列名, 字段名, 查询名, 顺序, 字段类型, 校验类型, 导入类型
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
                'importType' => (string) ($row['导入类型'] ?? '')
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
                'select 列名, 字段名, 查询名, 顺序, 字段类型, 字段长度, 校验信息, 校验类型, 对象, 导入类型, 系统变量, 匹配标识
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
            $fieldDefs[] = sprintf('%s varchar(%s) not null default ""', $fieldName, $fieldLength);
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
     * @param string $tableName 表名
     * @param array $data 数据
     * @return bool
     */
    public function insertToTempTable(string $tableName, array $data): bool
    {
        if (empty($data)) {
            return true;
        }

        $fields = array_keys($data[0]);
        $values = [];

        foreach ($data as $row) {
            $rowValues = [];
            foreach ($fields as $field) {
                $rowValues[] = $this->quote($row[$field] ?? '');
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
