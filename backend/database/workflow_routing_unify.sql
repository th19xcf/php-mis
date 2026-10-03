-- =============================================================================
-- A7 路由业务类型与匹配值口径统一（DDL + 存量数据修正）
-- 执行时间：2026-09-26；经 backend/writable/ 临时脚本执行后存档
-- =============================================================================
-- 问题背景（摸底结论，2026-09-26）：
--   1) def_workflow_definition.业务类型 列默认值为 'CONTRACT'，与存量数据 '合同'
--      口径不一致；路由解析（ContractService::submitApproval）入参业务类型为 '合同'，
--      新建流程定义若沿用列默认值将导致路由按业务类型过滤时匹配不上
--   2) def_workflow_routing 存量 3 条路由的 匹配条件 中 合同类型 用了类型名称
--      （"采购合同"/"销售合同"），而主表 def_contract_master_new.合同类型 实际存
--      类型编码（PURCHASE/SALES，来源 def_contract_type.类型编码）→ 路由永不命中，
--      恒走服务层兜底
--   3) 路由 GUID=1/2 的 目标流程编码（contract_purchase_high_approval /
--      contract_approval）在 def_workflow_definition 中不存在（库中现有启用流程仅
--      '合同审批流程'、'招聘合同审批流程'），命中后会抛"未找到启用的流程定义"
--   4) 主表 GUID=16（HT202607240001）合同类型误存名称 '销售合同'，应为编码 'SALES'
-- =============================================================================

-- 1. 业务类型默认值口径修正：'CONTRACT' → '合同'（仅改默认值，不动类型/索引/存量）
ALTER TABLE `def_workflow_definition` ALTER COLUMN `业务类型` SET DEFAULT '合同';

-- 2. 路由匹配条件改编码口径；GUID=1/2 无效目标改指现有启用流程 '合同审批流程'
--    （金额分桶结构保留，将来创建专属流程后仅需改 目标流程编码）
UPDATE `def_workflow_routing`
SET `匹配条件` = JSON_OBJECT('合同类型', 'PURCHASE', '合同金额', JSON_OBJECT('>=', 1000000)),
    `目标流程编码` = '合同审批流程',
    `更新人` = 'system', `更新时间` = NOW()
WHERE `GUID` = 1;

UPDATE `def_workflow_routing`
SET `匹配条件` = JSON_OBJECT('合同类型', 'PURCHASE', '合同金额', JSON_OBJECT('<', 1000000)),
    `目标流程编码` = '合同审批流程',
    `更新人` = 'system', `更新时间` = NOW()
WHERE `GUID` = 2;

-- GUID=3 目标 '合同审批流程' 本已有效，仅改匹配口径
UPDATE `def_workflow_routing`
SET `匹配条件` = JSON_OBJECT('合同类型', 'SALES'),
    `更新人` = 'system', `更新时间` = NOW()
WHERE `GUID` = 3;

-- 3. 主表脏数据修正：合同类型名称 '销售合同' → 编码 'SALES'
--    （带原值条件，防止重复执行时误改）
UPDATE `def_contract_master_new`
SET `合同类型` = 'SALES',
    `更新人` = 'system', `更新时间` = NOW()
WHERE `GUID` = 16 AND `合同类型` = '销售合同';

-- =============================================================================
-- 2026-10-02 追加：类型编码全量中文化后路由口径同步
--   def_contract_type.类型编码 已统一改为与 类型名称 一致（采购合同/销售合同/服务合同
--   /劳动合同/租赁合同/借款合同/其他合同），def_contract_master_new.合同类型 也已
--   同步迁移为中文，故 def_workflow_routing.匹配条件 必须改用中文口径才能命中。
--   下面的 UPDATE 在 2026-10-02 已通过临时 PHP 脚本执行（事务提交），此处存档备查。
--   口径约束已同步写入 workflow_routing_examples.sql 第 5-13 行说明。
-- =============================================================================
-- UPDATE `def_workflow_routing`
-- SET `匹配条件` = JSON_OBJECT('合同类型', '采购合同', '合同金额', JSON_OBJECT('>=', 1000000)),
--     `更新人` = 'system', `更新时间` = NOW()
-- WHERE `GUID` = 1 AND `删除标识` = '0';
--
-- UPDATE `def_workflow_routing`
-- SET `匹配条件` = JSON_OBJECT('合同类型', '采购合同', '合同金额', JSON_OBJECT('<', 1000000)),
--     `更新人` = 'system', `更新时间` = NOW()
-- WHERE `GUID` = 2 AND `删除标识` = '0';
--
-- UPDATE `def_workflow_routing`
-- SET `匹配条件` = JSON_OBJECT('合同类型', '销售合同'),
--     `更新人` = 'system', `更新时间` = NOW()
-- WHERE `GUID` = 3 AND `删除标识` = '0';
