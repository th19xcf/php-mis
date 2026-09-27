<?php

namespace App\Services\Workflow;

use App\Models\Mcommon;

/**
 * 流程路由服务
 *
 * 根据 def_workflow_routing 表中配置的条件，为业务单据动态匹配对应的流程编码。
 *
 * 匹配条件支持两种写法（写入 `匹配条件` JSON 字段）：
 *
 * 1. 简单等值匹配：
 *    {"合同类型":"采购合同","合同分类":"标准"}
 *
 * 2. 操作符匹配（值用对象表示）：
 *    {
 *      "合同类型":"采购合同",
 *      "合同金额":{">=":100000,"<":5000000},
 *      "所属部门":{"IN":["D001","D002"]},
 *      "签订日期":{"between":["2026-01-01","2026-12-31"]}
 *    }
 *
 * 支持的操作符：=、==、!=、<>、>、>=、<、<=、in、between、like、isnull、notnull
 *
 * 多个键之间为 AND 关系；同业务类型下按 `优先级` 升序匹配，命中第一条即返回。
 * 若所有路由均未命中，则返回 `默认流程编码`（调用方传入）。
 */
class WorkflowRoutingService
{
    private Mcommon $model;

    public function __construct()
    {
        $this->model = new Mcommon();
    }

    /**
     * 根据业务上下文匹配流程编码
     *
     * @param string $businessType  业务类型，如 CONTRACT
     * @param array  $context       业务上下文（业务单据字段键值对）
     * @param string $defaultCode   未命中任何路由时的默认流程编码
     * @param bool   $strictMode    严格模式：未命中时抛异常而非返回默认值
     * @return string 流程编码
     * @throws \RuntimeException 仅在 strictMode=true 且未命中时抛出
     */
    public function resolve(
        string $businessType,
        array $context,
        string $defaultCode = '',
        bool $strictMode = false
    ): string {
        $routing = $this->findMatchedRouting($businessType, $context);

        if ($routing !== null) {
            return $routing['目标流程编码'];
        }

        if ($strictMode && $defaultCode === '') {
            throw new \RuntimeException(sprintf(
                '未找到业务类型 [%s] 的流程路由配置，且未提供默认流程编码',
                $businessType
            ));
        }

        return $defaultCode;
    }

    /**
     * 返回匹配到的整条路由记录（含目标流程编码、路由编码、路由名称等）
     * 便于调用方记录日志或写入流程实例的备注。
     */
    public function resolveRouting(
        string $businessType,
        array $context
    ): ?array {
        return $this->findMatchedRouting($businessType, $context);
    }

    /**
     * 查询某业务类型下所有启用的路由配置（按优先级升序）
     *
     * @return array<int, array>
     */
    public function listRoutings(string $businessType): array
    {
        $sql = sprintf(
            'select * from `def_workflow_routing`
            where `业务类型`=%s and `启用状态`=%s
              and `删除标识`=%s and `有效标识`=%s
            order by `优先级` asc, `GUID` asc',
            $this->model->quote($businessType),
            $this->model->quote('1'),
            $this->model->quote('0'),
            $this->model->quote('1')
        );
        $result = $this->model->select($sql);
        return $result ? ($result->getResultArray() ?: []) : [];
    }

    /**
     * 内部：按优先级顺序遍历路由，返回第一条匹配的路由记录
     */
    private function findMatchedRouting(string $businessType, array $context): ?array
    {
        $routings = $this->listRoutings($businessType);
        if (empty($routings)) {
            return null;
        }

        foreach ($routings as $routing) {
            $conditionJson = $routing['匹配条件'] ?? '';
            if ($conditionJson === '' || $conditionJson === null) {
                // 空条件视为通配（兜底路由，建议放在最低优先级）
                return $routing;
            }

            $conditions = json_decode($conditionJson, true);
            if (!is_array($conditions) || empty($conditions)) {
                // 条件解析失败，跳过该路由
                continue;
            }

            if (WorkflowConditionMatcher::matchAll($conditions, $context)) {
                return $routing;
            }
        }

        return null;
    }
}
