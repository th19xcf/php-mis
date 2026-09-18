-- =========================================
-- 合同管理模块（V2）基础字典表
-- 说明：主表/文档/版本/审批意见等业务表
--       由 app/Database/Migrations 迁移管理
-- 更新日期: 2026-09-18（移除 V1 遗留表定义）
-- =========================================

-- 1. 合同参与方表
CREATE TABLE IF NOT EXISTS `def_contract_party` (
  `GUID` INT NOT NULL AUTO_INCREMENT COMMENT '主键',
  `合同编号` VARCHAR(50) NOT NULL COMMENT '合同编号',
  `参与方类型` VARCHAR(20) DEFAULT NULL COMMENT '类型(甲方/乙方/担保方等)',
  `参与方名称` VARCHAR(200) DEFAULT NULL COMMENT '名称',
  `参与方联系人` VARCHAR(100) DEFAULT NULL COMMENT '联系人',
  `参与方电话` VARCHAR(20) DEFAULT NULL COMMENT '电话',
  `参与方地址` VARCHAR(500) DEFAULT NULL COMMENT '地址',
  `备注` TEXT COMMENT '备注',
  `操作来源` VARCHAR(50) DEFAULT NULL COMMENT '操作来源',
  `操作人员` VARCHAR(50) DEFAULT NULL COMMENT '操作人员',
  `操作时间` DATETIME DEFAULT NULL COMMENT '操作时间',
  PRIMARY KEY (`GUID`),
  KEY `idx_合同编号` (`合同编号`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='合同参与方表';

-- 2. 合同类型表(数据字典)
CREATE TABLE IF NOT EXISTS `def_contract_type` (
  `GUID` INT NOT NULL AUTO_INCREMENT COMMENT '主键',
  `类型编码` VARCHAR(50) NOT NULL COMMENT '类型编码',
  `类型名称` VARCHAR(100) NOT NULL COMMENT '类型名称',
  `公司ID` VARCHAR(50) DEFAULT 'ALL' COMMENT '公司ID',
  `排序` INT DEFAULT 0 COMMENT '排序',
  `状态` VARCHAR(20) DEFAULT 'ACTIVE' COMMENT '状态',
  `创建人` VARCHAR(50) DEFAULT NULL COMMENT '创建人',
  `创建时间` DATETIME DEFAULT NULL COMMENT '创建时间',
  `删除标识` CHAR(1) DEFAULT '0' COMMENT '删除标识',
  `有效标识` CHAR(1) DEFAULT '1' COMMENT '有效标识',
  PRIMARY KEY (`GUID`),
  KEY `idx_公司ID` (`公司ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='合同类型表';

-- =========================================
-- 初始化合同类型数据
-- =========================================
INSERT INTO `def_contract_type` (`类型编码`, `类型名称`, `公司ID`, `排序`, `状态`) VALUES
('PURCHASE', '采购合同', 'ALL', 1, 'ACTIVE'),
('SALE', '销售合同', 'ALL', 2, 'ACTIVE'),
('LEASE', '租赁合同', 'ALL', 3, 'ACTIVE'),
('SERVICE', '服务合同', 'ALL', 4, 'ACTIVE'),
('LABOR', '劳动合同', 'ALL', 5, 'ACTIVE'),
('OTHER', '其他合同', 'ALL', 99, 'ACTIVE');

-- =========================================
-- 完成
-- =========================================
