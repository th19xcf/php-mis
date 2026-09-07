-- =========================================
-- OA 模块数据库表结构（待办中心 / 会议纪要）
-- 创建日期: 2026-09-07
-- 说明: 对应 app/Services/Oa/TodoService.php 和 MeetingService.php
-- =========================================

-- 1. 待办事项表
CREATE TABLE IF NOT EXISTS `oa_todo` (
  `GUID` INT NOT NULL AUTO_INCREMENT COMMENT '主键',
  `待办标题` VARCHAR(200) NOT NULL COMMENT '待办标题',
  `待办描述` TEXT DEFAULT NULL COMMENT '待办描述',
  `来源类型` VARCHAR(20) DEFAULT '手动' COMMENT '来源类型(手动/会议/工作流/合同)',
  `来源摘要` VARCHAR(200) DEFAULT NULL COMMENT '来源摘要(列表免JOIN)',
  `来源GUID` INT DEFAULT NULL COMMENT '来源GUID(关联会议/流程实例)',
  `指派人` VARCHAR(50) DEFAULT NULL COMMENT '指派人(工号)',
  `负责人` VARCHAR(500) NOT NULL COMMENT '负责人(工号,多个以逗号分隔)',
  `截止日期` DATE DEFAULT NULL COMMENT '截止日期',
  `提醒时间` DATETIME DEFAULT NULL COMMENT '提醒时间',
  `优先级` VARCHAR(10) DEFAULT '中' COMMENT '优先级(高/中/低)',
  `待办状态` VARCHAR(10) DEFAULT '待处理' COMMENT '待办状态(待处理/进行中/已完成/已取消)',
  `完成时间` DATETIME DEFAULT NULL COMMENT '完成时间',
  `完成说明` VARCHAR(500) DEFAULT NULL COMMENT '完成说明',
  `父级GUID` INT DEFAULT NULL COMMENT '父级GUID(支持子任务)',
  `关联人员编码` VARCHAR(20) DEFAULT NULL COMMENT '关联人员编码',
  `操作记录` VARCHAR(200) DEFAULT NULL COMMENT '操作记录',
  `操作来源` VARCHAR(50) DEFAULT NULL COMMENT '操作来源',
  `操作人员` VARCHAR(50) DEFAULT NULL COMMENT '操作人员',
  `开始操作时间` DATETIME DEFAULT NULL COMMENT '开始操作时间',
  `操作时间` DATETIME DEFAULT NULL COMMENT '操作时间',
  `删除标识` CHAR(1) DEFAULT '0' COMMENT '删除标识',
  `有效标识` CHAR(1) DEFAULT '1' COMMENT '有效标识',
  PRIMARY KEY (`GUID`),
  KEY `idx_负责人` (`负责人`),
  KEY `idx_指派人` (`指派人`),
  KEY `idx_待办状态` (`待办状态`),
  KEY `idx_来源类型_来源GUID` (`来源类型`, `来源GUID`),
  KEY `idx_截止日期` (`截止日期`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='待办事项表';

-- 2. 会议纪要主表
CREATE TABLE IF NOT EXISTS `oa_meeting` (
  `GUID` INT NOT NULL AUTO_INCREMENT COMMENT '主键',
  `会议主题` VARCHAR(200) NOT NULL COMMENT '会议主题',
  `会议类型` VARCHAR(20) DEFAULT '例会' COMMENT '会议类型(例会/专题会/面试评估会/其他)',
  `开始时间` DATETIME DEFAULT NULL COMMENT '开始时间',
  `结束时间` DATETIME DEFAULT NULL COMMENT '结束时间',
  `会议地点` VARCHAR(200) DEFAULT NULL COMMENT '会议地点',
  `组织人` VARCHAR(50) DEFAULT NULL COMMENT '组织人(工号)',
  `记录人` VARCHAR(50) DEFAULT NULL COMMENT '记录人(工号)',
  `会议状态` VARCHAR(10) DEFAULT '待召开' COMMENT '会议状态(待召开/进行中/已结束)',
  `纪要状态` CHAR(1) DEFAULT '0' COMMENT '纪要状态(0=草稿,1=已提交定稿)',
  `会议纪要` TEXT DEFAULT NULL COMMENT '会议纪要正文',
  `关联人员编码` VARCHAR(20) DEFAULT NULL COMMENT '关联人员编码',
  `操作记录` VARCHAR(200) DEFAULT NULL COMMENT '操作记录',
  `操作来源` VARCHAR(50) DEFAULT NULL COMMENT '操作来源',
  `操作人员` VARCHAR(50) DEFAULT NULL COMMENT '操作人员',
  `开始操作时间` DATETIME DEFAULT NULL COMMENT '开始操作时间',
  `操作时间` DATETIME DEFAULT NULL COMMENT '操作时间',
  `删除标识` CHAR(1) DEFAULT '0' COMMENT '删除标识',
  `有效标识` CHAR(1) DEFAULT '1' COMMENT '有效标识',
  PRIMARY KEY (`GUID`),
  KEY `idx_会议状态` (`会议状态`),
  KEY `idx_开始时间` (`开始时间`),
  KEY `idx_纪要状态` (`纪要状态`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='会议纪要主表';

-- 3. 会议参会人表
CREATE TABLE IF NOT EXISTS `oa_attendee` (
  `GUID` INT NOT NULL AUTO_INCREMENT COMMENT '主键',
  `会议GUID` INT NOT NULL COMMENT '会议GUID',
  `参会人` VARCHAR(50) NOT NULL COMMENT '参会人(工号)',
  `参会人姓名` VARCHAR(50) DEFAULT NULL COMMENT '参会人姓名',
  `参会角色` VARCHAR(10) DEFAULT '参会' COMMENT '参会角色(主持/记录/参会)',
  `出席状态` VARCHAR(10) DEFAULT '待确认' COMMENT '出席状态(出席/请假/缺席/待确认)',
  `操作记录` VARCHAR(200) DEFAULT NULL COMMENT '操作记录',
  `操作来源` VARCHAR(50) DEFAULT NULL COMMENT '操作来源',
  `操作人员` VARCHAR(50) DEFAULT NULL COMMENT '操作人员',
  `开始操作时间` DATETIME DEFAULT NULL COMMENT '开始操作时间',
  `操作时间` DATETIME DEFAULT NULL COMMENT '操作时间',
  `删除标识` CHAR(1) DEFAULT '0' COMMENT '删除标识',
  `有效标识` CHAR(1) DEFAULT '1' COMMENT '有效标识',
  PRIMARY KEY (`GUID`),
  KEY `idx_会议GUID` (`会议GUID`),
  KEY `idx_参会人` (`参会人`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='会议参会人表';
