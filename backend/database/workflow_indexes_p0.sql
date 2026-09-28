-- =====================================================================
-- 流程管理模块 P0 索引优化
-- 背景：待办/已办/我的实例/时间线查询在数据量增长时出现全表扫描，
--      补充核心复合索引以提升查询性能。
-- 涉及表：def_workflow_instance / def_workflow_task / def_workflow_task_log
-- 执行方式：在 MySQL 客户端或 spark migrate 中执行
-- 回滚：ALTER TABLE ... DROP INDEX 对应索引名
-- =====================================================================

-- 1. def_workflow_instance：按发起人+实例状态查询（我的实例列表、待办统计）
ALTER TABLE `def_workflow_instance`
  ADD INDEX `idx_发起人_状态` (`发起人`, `实例状态`);

-- 2. def_workflow_task：按处理人+任务状态+删除标识查询（待办/已办列表核心查询）
ALTER TABLE `def_workflow_task`
  ADD INDEX `idx_处理人_状态_删除` (`处理人`, `任务状态`, `删除标识`);

-- 3. def_workflow_task_log：按实例ID+操作时间查询（流程时间线展示）
ALTER TABLE `def_workflow_task_log`
  ADD INDEX `idx_实例_时间` (`实例ID`, `操作时间`);
