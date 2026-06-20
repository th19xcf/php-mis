<?php

namespace App\Traits;

/**
 * 审计字段构建 Trait
 *
 * 为数据记录统一注入操作审计字段（操作记录、操作来源、操作人员、操作时间等），
 * 供 BaseApiController 及其子控制器复用，消除各控制器中重复的审计字段拼装逻辑。
 *
 * 使用要求：宿主类需提供 getUserWorkId(): string 方法。
 */
trait AuditFieldsTrait
{
    /**
     * 构建新增数据的审计字段
     *
     * @param array  $data     原始数据
     * @param string $operation 操作类型（默认"新增"）
     * @return array 注入审计字段后的数据
     */
    protected function buildInsertData(array $data, string $operation = '新增'): array
    {
        $data['操作记录'] = $operation;
        $data['操作来源'] = '页面' . $operation;
        $data['操作人员'] = $this->getUserWorkId();
        $data['开始操作时间'] = date('Y-m-d H:i:s');
        $data['操作时间'] = date('Y-m-d H:i:s');
        $data['有效标识'] = '1';
        $data['删除标识'] = '0';
        return $data;
    }

    /**
     * 构建修改数据的审计字段
     *
     * @param array  $data     原始数据
     * @param string $operation 操作类型（默认"修改"）
     * @return array 注入审计字段后的数据
     */
    protected function buildUpdateData(array $data, string $operation = '修改'): array
    {
        $data['操作记录'] = $operation;
        $data['操作来源'] = '页面' . $operation;
        $data['操作人员'] = $this->getUserWorkId();
        $data['操作时间'] = date('Y-m-d H:i:s');
        return $data;
    }

    /**
     * 构建删除操作的审计字段
     *
     * @return array 审计字段键值对
     */
    protected function buildDeleteData(): array
    {
        return [
            '操作记录' => '删除',
            '操作来源' => '页面删除',
            '操作人员' => $this->getUserWorkId(),
            '结束操作时间' => date('Y-m-d H:i:s'),
            '删除标识' => '1',
            '有效标识' => '0',
        ];
    }
}
