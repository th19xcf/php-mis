<?php

namespace App\Controllers;

use App\Constants\ApiCode;
use App\Models\Mcommon;

/**
 * 批注管理控制器
 * 实现添加批注和查看批注功能
 */
class Comment extends BaseController
{
    private Mcommon $common;

    public function __construct()
    {
        $this->common = new Mcommon();
    }

    /**
     * 获取批注配置信息
     * 
     * 先通过功能编码直接查询 def_comment_config，
     * 如果没有找到，则通过 def_query_config.备注模块 关联查询
     */
    private function getCommentConfig(string $functionCode): ?array
    {
        // 方式1：直接通过功能编码查询
        $sql = sprintf(
            'select 
                t1.备注模块,t1.备注表名,t1.功能编码,t1.原表字段,
                ifnull(t2.模块名称,"") as 模块名称,
                ifnull(t3.数据表名,"") as 数据表名
            from def_comment_config as t1
            left join def_function as t2 on t1.功能编码=t2.功能编码
            left join def_query_config as t3 on t2.模块名称=t3.查询模块
            where t1.功能编码=%s
            limit 1',
            $this->quote($functionCode)
        );

        $row = $this->common->select($sql)->getRowArray();
        if ($row && !empty($row['数据表名'])) {
            return $row;
        }

        // 方式2：通过 def_query_config.备注模块 关联查询
        $sql = sprintf(
            'select 
                t2.备注模块,t2.备注表名,t2.功能编码,t2.原表字段,
                t1.模块名称,
                t3.数据表名
            from def_function as t1
            inner join def_query_config as t3 on t1.模块名称=t3.查询模块
            left join def_comment_config as t2 on t3.备注模块=t2.备注模块
            where t1.功能编码=%s
            limit 1',
            $this->quote($functionCode)
        );

        $row = $this->common->select($sql)->getRowArray();
        return $row ?: null;
    }

    /**
     * 检查批注权限
     */
    private function checkCommentAuth(string $functionCode): bool
    {
        $session = \Config\Services::session();
        $userRole = trim((string) $session->get('user_role'));

        $sql = sprintf(
            'select max(备注授权) as 备注授权
            from view_role
            where 有效标识="1" and 角色编码 in (%s) and 功能编码赋权=%s',
            $userRole,
            $this->quote($functionCode)
        );

        $row = $this->common->select($sql)->getRowArray();
        return ($row['备注授权'] ?? '0') === '1';
    }

    /**
     * 获取批注列表
     * 
     * @param string $functionCode 功能编码
     */
    public function list(string $functionCode = '')
    {
        try {
            if ($functionCode === '') {
                return $this->error(ApiCode::PARAM_ERROR, '功能编码不能为空');
            }

            // 获取请求参数
            $payload = $this->request->getJSON(true) ?? [];
            $keyFields = $payload['keyFields'] ?? [];

            if (empty($keyFields)) {
                return $this->success(['records' => [], 'total' => 0]);
            }

            // 获取批注配置
            $config = $this->getCommentConfig($functionCode);
            if (!$config) {
                return $this->error(ApiCode::PARAM_ERROR, '该功能未配置批注模块');
            }

            $commentTable = $config['数据表名'];
            if (empty($commentTable)) {
                return $this->error(ApiCode::PARAM_ERROR, '批注表名未配置');
            }

            // 构建查询条件
            $whereConditions = [];
            foreach ($keyFields as $field => $value) {
                if (is_numeric($value)) {
                    $whereConditions[] = sprintf('%s=%s', $field, $value);
                } else {
                    $whereConditions[] = sprintf('%s="%s"', $field, $value);
                }
            }

            if (empty($whereConditions)) {
                return $this->success(['records' => [], 'total' => 0]);
            }

            $whereStr = implode(' and ', $whereConditions);

            // 查询批注列表
            $sql = sprintf(
                'select * from %s where %s order by id desc',
                $commentTable,
                $whereStr
            );

            $results = $this->common->select($sql)->getResultArray();

            return $this->success([
                'records' => $results,
                'total' => count($results)
            ]);
        } catch (\Throwable $e) {
            return $this->error(ApiCode::SERVER_ERROR, '获取批注列表失败：' . $e->getMessage());
        }
    }

    /**
     * 添加批注
     * 
     * @param string $functionCode 功能编码
     */
    public function add(string $functionCode = '')
    {
        try {
            if ($functionCode === '') {
                return $this->error(ApiCode::PARAM_ERROR, '功能编码不能为空');
            }

            // 检查权限
            if (!$this->checkCommentAuth($functionCode)) {
                return $this->error(ApiCode::AUTH_UNAUTHORIZED, '无批注权限');
            }

            // 获取请求参数
            $payload = $this->request->getJSON(true) ?? [];
            $keyFields = $payload['keyFields'] ?? [];
            $commentData = $payload['data'] ?? [];

            if (empty($keyFields)) {
                return $this->error(ApiCode::PARAM_ERROR, '关键字段不能为空');
            }

            if (empty($commentData)) {
                return $this->error(ApiCode::PARAM_ERROR, '批注内容不能为空');
            }

            // 获取批注配置
            $config = $this->getCommentConfig($functionCode);
            if (!$config) {
                return $this->error(ApiCode::PARAM_ERROR, '该功能未配置批注模块');
            }

            $commentTable = $config['数据表名'];
            if (empty($commentTable)) {
                return $this->error(ApiCode::PARAM_ERROR, '批注表名未配置');
            }

            // 获取当前用户信息
            $session = \Config\Services::session();
            $userWorkId = trim((string) $session->get('user_workid'));

            // 构建插入字段和值
            $fields = [];
            $values = [];

            // 添加关键字段
            foreach ($keyFields as $field => $value) {
                $fields[] = $field;
                if (is_numeric($value)) {
                    $values[] = $value;
                } else {
                    $values[] = sprintf('"%s"', $value);
                }
            }

            // 添加批注数据字段
            foreach ($commentData as $field => $value) {
                $fields[] = $field;
                if (is_numeric($value)) {
                    $values[] = $value;
                } else {
                    $values[] = sprintf('"%s"', $value);
                }
            }

            // 添加操作人员字段
            $fields[] = '操作人员';
            $values[] = sprintf('"%s"', $userWorkId);

            // 构建并执行插入SQL
            $sql = sprintf(
                'insert into %s (%s) values (%s)',
                $commentTable,
                implode(',', $fields),
                implode(',', $values)
            );

            $this->common->exec($sql);

            return $this->success(null, '添加批注成功');
        } catch (\Throwable $e) {
            return $this->error(ApiCode::SERVER_ERROR, '添加批注失败：' . $e->getMessage());
        }
    }

    /**
     * 获取批注字段配置
     * 
     * @param string $functionCode 功能编码
     */
    public function fields(string $functionCode = '')
    {
        try {
            if ($functionCode === '') {
                return $this->error(ApiCode::PARAM_ERROR, '功能编码不能为空');
            }

            // 获取批注配置
            $config = $this->getCommentConfig($functionCode);
            if (!$config) {
                return $this->success(['fields' => []]);
            }

            // 查询批注表的字段结构
            $commentTable = $config['数据表名'];
            if (empty($commentTable)) {
                return $this->success(['fields' => []]);
            }

            // 获取表字段信息
            $sql = sprintf(
                'show columns from %s',
                $commentTable
            );

            $results = $this->common->select($sql)->getResultArray();

            // 解析关键字段映射（字段名 -> 列名）
            $keyFieldMap = [];
            $keyFieldsStr = $config['原表字段'] ?? '';
            if ($keyFieldsStr !== '') {
                // 格式: "字段名1:列名1;字段名2:列名2" 或 "字段名1;字段名2"
                $pairs = explode(';', $keyFieldsStr);
                foreach ($pairs as $pair) {
                    $pair = trim($pair);
                    if ($pair === '') continue;
                    
                    if (strpos($pair, ':') !== false) {
                        // 格式: 字段名:列名
                        $parts = explode(':', $pair);
                        $keyFieldMap[trim($parts[0])] = trim($parts[1]);
                    } else {
                        // 格式: 字段名（字段名和列名相同）
                        $keyFieldMap[$pair] = $pair;
                    }
                }
            }

            // 过滤掉系统字段，但保留关键字段用于显示
            $excludeFields = ['id', '操作人员', '创建时间', '更新时间'];
            $fields = [];
            foreach ($results as $row) {
                $fieldName = $row['Field'];
                // 跳过系统字段
                if (in_array($fieldName, $excludeFields, true)) {
                    continue;
                }
                
                // 判断是否为关键字段
                $isKeyField = isset($keyFieldMap[$fieldName]);
                
                $fields[] = [
                    'name' => $fieldName,
                    'type' => $this->getFieldType($row['Type']),
                    'comment' => $row['Comment'] ?? $fieldName,
                    'isKeyField' => $isKeyField,
                    'sourceColumn' => $keyFieldMap[$fieldName] ?? ''
                ];
            }

            return $this->success([
                'fields' => $fields,
                'keyFields' => $config['原表字段'] ?? ''
            ]);
        } catch (\Throwable $e) {
            return $this->success(['fields' => []]);
        }
    }

    /**
     * 根据数据库类型返回简化的类型名称
     */
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
