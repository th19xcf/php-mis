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
        log_message('debug', "Comment::getCommentConfig 方式1查询结果: " . json_encode($row));
        if ($row && !empty($row['数据表名'])) {
            return $row;
        }

        // 方式2：通过 def_query_config.备注模块 关联查询
        $sql = sprintf(
            'select 
                t2.备注模块,t2.备注表名,t2.功能编码,t2.原表字段,
                t1.模块名称,
                t2.备注表名 as 数据表名
            from def_function as t1
            inner join def_query_config as t3 on t1.模块名称=t3.查询模块
            left join def_comment_config as t2 on t3.备注模块=t2.备注模块
            where t1.功能编码=%s
            limit 1',
            $this->quote($functionCode)
        );

        $row = $this->common->select($sql)->getRowArray();
        
        log_message('debug', "Comment::getCommentConfig 方式2查询结果: " . json_encode($row));
        
        return $row ?: null;
    }

    /**
     * 检查批注权限
     */
    private function checkCommentAuth(string $functionCode): bool
    {
        $session = \Config\Services::session();
        $userRole = trim((string) $session->get('user_role'));

        // 如果没有用户角色，返回无权限
        if (empty($userRole)) {
            log_message('warning', "Comment::checkCommentAuth - 用户角色为空");
            return false;
        }

        $sql = sprintf(
            'select max(备注授权) as 备注授权
            from view_role
            where 有效标识="1" and 角色编码 in (%s) and 功能编码赋权=%s',
            $userRole,
            $this->quote($functionCode)
        );

        try {
            $row = $this->common->select($sql)->getRowArray();
            return ($row['备注授权'] ?? '0') === '1';
        } catch (\Throwable $e) {
            log_message('error', "Comment::checkCommentAuth - SQL错误: " . $e->getMessage());
            return false;
        }
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
                return $this->response->setJSON([
                    'code' => ApiCode::PARAM_ERROR,
                    'msg' => '功能编码不能为空'
                ]);
            }

            // 获取请求参数
            $payload = $this->request->getJSON(true) ?? [];
            $keyFields = $payload['keyFields'] ?? [];

            if (empty($keyFields)) {
                return $this->response->setJSON([
                    'code' => ApiCode::SUCCESS,
                    'msg' => 'success',
                    'data' => ['records' => [], 'total' => 0]
                ]);
            }

            // 获取批注配置
            $config = $this->getCommentConfig($functionCode);
            if (!$config) {
                return $this->response->setJSON([
                    'code' => ApiCode::PARAM_ERROR,
                    'msg' => '该功能未配置批注模块'
                ]);
            }

            $commentTable = $config['数据表名'];
            if (empty($commentTable)) {
                return $this->response->setJSON([
                    'code' => ApiCode::PARAM_ERROR,
                    'msg' => '批注表名未配置'
                ]);
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
                return $this->response->setJSON([
                    'code' => ApiCode::SUCCESS,
                    'msg' => 'success',
                    'data' => ['records' => [], 'total' => 0]
                ]);
            }

            $whereStr = implode(' and ', $whereConditions);

            // 查询批注列表
            $sql = sprintf(
                'select * from %s where %s order by id desc',
                $commentTable,
                $whereStr
            );

            $results = $this->common->select($sql)->getResultArray();

            return $this->response->setJSON([
                'code' => ApiCode::SUCCESS,
                'msg' => 'success',
                'data' => [
                    'records' => $results,
                    'total' => count($results)
                ]
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'code' => ApiCode::SERVER_ERROR,
                'msg' => '获取批注列表失败：' . $e->getMessage()
            ]);
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
                return $this->response->setJSON([
                    'code' => ApiCode::PARAM_ERROR,
                    'msg' => '功能编码不能为空'
                ]);
            }

            // 检查权限
            if (!$this->checkCommentAuth($functionCode)) {
                return $this->response->setJSON([
                    'code' => ApiCode::AUTH_UNAUTHORIZED,
                    'msg' => '无批注权限'
                ]);
            }

            // 获取请求参数
            $payload = $this->request->getJSON(true) ?? [];
            $keyFields = $payload['keyFields'] ?? [];
            $commentData = $payload['data'] ?? [];

            if (empty($keyFields)) {
                return $this->response->setJSON([
                    'code' => ApiCode::PARAM_ERROR,
                    'msg' => '关键字段不能为空'
                ]);
            }

            if (empty($commentData)) {
                return $this->response->setJSON([
                    'code' => ApiCode::PARAM_ERROR,
                    'msg' => '批注内容不能为空'
                ]);
            }

            // 获取批注配置
            $config = $this->getCommentConfig($functionCode);
            if (!$config) {
                return $this->response->setJSON([
                    'code' => ApiCode::PARAM_ERROR,
                    'msg' => '该功能未配置批注模块'
                ]);
            }

            $commentTable = $config['数据表名'];
            if (empty($commentTable)) {
                return $this->response->setJSON([
                    'code' => ApiCode::PARAM_ERROR,
                    'msg' => '批注表名未配置'
                ]);
            }

            // 获取表结构，验证字段是否存在
            $sql = sprintf('show columns from %s', $commentTable);
            $tableColumns = $this->common->select($sql)->getResultArray();
            $validFields = [];
            foreach ($tableColumns as $col) {
                $fieldName = $col['Field'] ?? $col['field'] ?? '';
                if ($fieldName) {
                    $validFields[] = $fieldName;
                }
            }
            log_message('debug', "Comment::add - 表 {$commentTable} 的有效字段: " . json_encode($validFields));

            // 获取当前用户信息
            $session = \Config\Services::session();
            $userWorkId = trim((string) $session->get('user_workid'));

            // 构建插入字段和值
            $fields = [];
            $values = [];

            // 添加关键字段（验证字段是否存在）
            log_message('debug', "Comment::add - 接收到的关键字段: " . json_encode($keyFields));
            foreach ($keyFields as $field => $value) {
                if (!in_array($field, $validFields, true)) {
                    log_message('warning', "Comment::add - 字段 {$field} 不存在于表 {$commentTable}");
                    continue;
                }
                $fields[] = $field;
                if (is_numeric($value)) {
                    $values[] = $value;
                } else {
                    $values[] = sprintf('"%s"', $value);
                }
                log_message('debug', "Comment::add - 添加关键字段 {$field} = {$value}");
            }

            // 添加批注数据字段（验证字段是否存在）
            foreach ($commentData as $field => $value) {
                if (!in_array($field, $validFields, true)) {
                    log_message('warning', "Comment::add - 字段 {$field} 不存在于表 {$commentTable}");
                    continue;
                }
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

            log_message('debug', "Comment::add - 执行SQL: {$sql}");

            try {
                $this->common->exec($sql);
            } catch (\Throwable $e) {
                log_message('error', "Comment::add - SQL执行失败: " . $e->getMessage());
                log_message('error', "Comment::add - SQL语句: {$sql}");
                throw $e;
            }

            return $this->response->setJSON([
                'code' => ApiCode::SUCCESS,
                'msg' => '添加批注成功'
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Comment::add - 异常: ' . $e->getMessage());
            return $this->response->setJSON([
                'code' => ApiCode::SERVER_ERROR,
                'msg' => '添加批注失败：' . $e->getMessage()
            ]);
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
                return $this->response->setJSON([
                    'code' => ApiCode::PARAM_ERROR,
                    'msg' => '功能编码不能为空'
                ]);
            }

            // 获取批注配置
            $config = $this->getCommentConfig($functionCode);
            if (!$config) {
                log_message('debug', "Comment::fields - 未找到功能编码 {$functionCode} 的批注配置");
                return $this->response->setJSON([
                    'code' => ApiCode::SUCCESS,
                    'msg' => 'success',
                    'data' => ['fields' => []]
                ]);
            }

            // 查询批注表的字段结构
            $commentTable = $config['数据表名'] ?? '';
            if (empty($commentTable)) {
                log_message('debug', "Comment::fields - 功能编码 {$functionCode} 的数据表名为空");
                return $this->response->setJSON([
                    'code' => ApiCode::SUCCESS,
                    'msg' => 'success',
                    'data' => ['fields' => []]
                ]);
            }

            log_message('debug', "Comment::fields - 查询表 {$commentTable} 的字段结构");

            // 获取表字段信息
            $sql = sprintf(
                'show columns from %s',
                $commentTable
            );

            try {
                $results = $this->common->select($sql)->getResultArray();
                log_message('debug', "Comment::fields - 表字段查询结果: " . json_encode($results));
            } catch (\Throwable $e) {
                log_message('error', "Comment::fields - 查询表字段失败: " . $e->getMessage());
                return $this->response->setJSON([
                    'code' => ApiCode::SUCCESS,
                    'msg' => 'success',
                    'data' => ['fields' => []]
                ]);
            }

            // 解析关键字段映射（字段名 -> 列名）
            $keyFieldMap = [];
            $keyFieldsStr = $config['原表字段'] ?? '';
            log_message('debug', "Comment::fields - 原表字段配置: {$keyFieldsStr}");
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
            log_message('debug', "Comment::fields - 解析后的关键字段映射: " . json_encode($keyFieldMap));

            // 过滤掉系统字段，但保留关键字段用于显示
            $excludeFields = ['id', '操作人员', '创建时间', '更新时间'];
            $fields = [];
            foreach ($results as $row) {
                // 处理字段名大小写（MySQL 返回的可能是大写或小写）
                $fieldName = $row['Field'] ?? $row['field'] ?? '';
                if (empty($fieldName)) {
                    continue;
                }
                
                // 跳过系统字段
                if (in_array($fieldName, $excludeFields, true)) {
                    continue;
                }
                
                // 判断是否为关键字段
                $isKeyField = isset($keyFieldMap[$fieldName]);
                
                // 获取字段类型和注释（处理大小写）
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
            
            log_message('debug', "Comment::fields - 处理后的字段列表: " . json_encode($fields));

            return $this->response->setJSON([
                'code' => ApiCode::SUCCESS,
                'msg' => 'success',
                'data' => [
                    'fields' => $fields,
                    'keyFields' => $config['原表字段'] ?? ''
                ]
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Comment::fields - 异常: ' . $e->getMessage());
            return $this->response->setJSON([
                'code' => ApiCode::SUCCESS,
                'msg' => 'success',
                'data' => ['fields' => []]
            ]);
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

    /**
     * 辅助方法：转义字符串
     */
    private function quote(string $value): string
    {
        return '"' . $value . '"';
    }
}
