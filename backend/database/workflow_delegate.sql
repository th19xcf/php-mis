-- =====================================================================
-- 流程委托代理表
-- 用途：审批人休假/出差期间，将其审批任务自动转交给代理人处理
-- resolveApprovers 解析审批人时，对每个审批人查当前有效委托配置，命中则替换为代理人
-- =====================================================================

CREATE TABLE IF NOT EXISTS `def_workflow_delegate` (
  `GUID` INT NOT NULL AUTO_INCREMENT COMMENT '主键',
  `委托人` VARCHAR(20) NOT NULL COMMENT '委托人工号',
  `委托人姓名` VARCHAR(50) DEFAULT NULL COMMENT '委托人姓名',
  `代理人` VARCHAR(20) NOT NULL COMMENT '代理人工号',
  `代理人姓名` VARCHAR(50) DEFAULT NULL COMMENT '代理人姓名',
  `开始时间` DATETIME NOT NULL COMMENT '委托生效开始时间',
  `结束时间` DATETIME NOT NULL COMMENT '委托生效结束时间',
  `备注` VARCHAR(500) DEFAULT NULL COMMENT '备注（如出差/休假）',
  `删除标识` CHAR(1) DEFAULT '0' COMMENT '0有效 1删除',
  `有效标识` CHAR(1) DEFAULT '1' COMMENT '1有效 0无效',
  `操作来源` VARCHAR(50) DEFAULT NULL COMMENT '操作来源',
  `操作人员` VARCHAR(20) DEFAULT NULL COMMENT '操作人员',
  `操作时间` DATETIME DEFAULT NULL COMMENT '操作时间',
  `创建人` VARCHAR(20) DEFAULT NULL COMMENT '创建人',
  `创建时间` DATETIME DEFAULT NULL COMMENT '创建时间',
  `更新人` VARCHAR(20) DEFAULT NULL COMMENT '更新人',
  `更新时间` DATETIME DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`GUID`),
  INDEX `idx_委托人_时间` (`委托人`, `开始时间`, `结束时间`),
  INDEX `idx_代理人` (`代理人`),
  INDEX `idx_删除标识` (`删除标识`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='流程委托代理配置';
