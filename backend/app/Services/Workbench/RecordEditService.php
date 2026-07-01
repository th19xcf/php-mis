<?php

namespace App\Services\Workbench;

use App\Models\Mcommon;

/**
 * 单条记录编辑服务类
 *
 * 负责工作台单条记录的新增、修改、删除操作，
 * 支持多种数据模式（直接操作/软删+流水）。
 * 从 EditService 中拆分而来。
 */
class RecordEditService
{
    private Mcommon $model;

    public function __construct()
    {
        $this->model = new Mcommon();
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
     * 根据数据构建 WHERE 条件（逗号分隔的复合主键）
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
                $conditions[] = sprintf('%s=%s', $key, $this->model->quote((string) $data[$key]));
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
        $keyStr = implode(',', array_map(fn($v) => $this->model->quote((string) $v), $keyValues));
        $where = sprintf('%s in (%s)', $primaryKey, $keyStr);

        $updates = [];
        foreach ($formData as $key => $value) {
            if ($key !== $primaryKey) {
                $updates[] = sprintf('`%s` = %s', $key, $this->model->quote((string) $value));
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
                        $values[] = $this->model->quote((string) $formData[$key]);
                    } else {
                        $fields[] = sprintf('`%s`', $key);
                        $values[] = $this->model->quote((string) $val);
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
     * 根据数据模式删除记录
     *
     * @param string $dataTable 数据表
     * @param string $dataModel 数据模式
     * @param string $primaryKey 主键字段
     * @param array $keyValues 主键值数组
     * @param string $userWorkid 用户工号
     * @param string $functionCode 功能编码
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
        $keyStr = implode(',', array_map(fn($v) => $this->model->quote((string) $v), $keyValues));
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
}
