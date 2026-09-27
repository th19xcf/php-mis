<?php

namespace App\Services\Workflow;

/**
 * 工作流公共常量
 *
 * 集中管理业务类型与默认流程编码等统一口径，
 * 避免字面量散落各处导致口径漂移（如 'CONTRACT' 与 '合同' 混用、
 * 匹配条件用类型名称而主表存类型编码等历史问题）
 */
class WorkflowConstants
{
    /**
     * 业务类型：合同
     *
     * def_workflow_definition.业务类型 / def_workflow_routing.业务类型 /
     * def_workflow_instance.业务类型 三处统一使用中文口径 '合同'
     */
    public const BUSINESS_TYPE_CONTRACT = '合同';

    /**
     * 合同默认审批流程编码
     *
     * def_workflow_definition 中实际存在的启用流程；
     * 路由未命中时兜底使用（指向不存在的流程编码会导致 startProcess 抛异常）
     */
    public const CONTRACT_DEFAULT_WORKFLOW_CODE = '合同审批流程';
}
