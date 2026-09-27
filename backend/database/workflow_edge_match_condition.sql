-- ============================================================
-- A1：流程连线条件安全化（下线 eval）
-- 变更：def_workflow_edge 新增 `匹配条件` JSON 列，替代 `条件表达式` 文本列
-- 背景：原条件表达式走 WorkflowService::evaluateCondition 的 eval 执行，
--       存在表达式注入风险；改为结构化 JSON 条件，由 WorkflowConditionMatcher 安全匹配。
-- 存量：def_workflow_edge.条件表达式 存量 0 条（2026-09-26 前置检查确认），无需数据迁移。
-- 关联：def_workflow_routing.匹配条件 同为 json 类型，两处共用同一匹配器。
-- ============================================================

ALTER TABLE `def_workflow_edge`
  ADD COLUMN `匹配条件` json DEFAULT NULL COMMENT '匹配条件(JSON键值对，支持操作符，例如 {"合同金额":{">=":100000}}；为空表示默认流转)' AFTER `条件表达式`;

-- 说明：`条件表达式` 列保留不删除（历史列，代码已不再读写）；
--       匹配条件 JSON 格式与 def_workflow_routing.匹配条件 一致：
--       1) 简单等值：{"合同类型":"采购合同"}
--       2) 操作符：{"合同金额":{">=":100000,"<":5000000},"所属部门":{"IN":["D001","D002"]}}
--       支持操作符：=、==、!=、<>、>、>=、<、<=、in、between、like、isnull、notnull
