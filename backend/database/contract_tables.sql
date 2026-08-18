-- =========================================
-- 合同管理模块数据库表结构
-- 创建日期: 2026-05-06
-- =========================================

-- 1. 合同主表
CREATE TABLE IF NOT EXISTS `def_contract_master` (
  `GUID` INT NOT NULL AUTO_INCREMENT COMMENT '主键',
  `合同编号` VARCHAR(50) NOT NULL COMMENT '合同编号' UNIQUE,
  `合同名称` VARCHAR(200) NOT NULL COMMENT '合同名称',
  `合同类型` VARCHAR(50) DEFAULT NULL COMMENT '合同类型',
  `合同金额` DECIMAL(18,2) DEFAULT 0.00 COMMENT '合同金额',
  `甲方名称` VARCHAR(200) DEFAULT NULL COMMENT '甲方名称',
  `甲方联系人` VARCHAR(100) DEFAULT NULL COMMENT '甲方联系人',
  `甲方电话` VARCHAR(20) DEFAULT NULL COMMENT '甲方电话',
  `乙方名称` VARCHAR(200) DEFAULT NULL COMMENT '乙方名称',
  `乙方联系人` VARCHAR(100) DEFAULT NULL COMMENT '乙方联系人',
  `乙方电话` VARCHAR(20) DEFAULT NULL COMMENT '乙方电话',
  `签订日期` DATE DEFAULT NULL COMMENT '签订日期',
  `开始日期` DATE DEFAULT NULL COMMENT '开始日期',
  `结束日期` DATE DEFAULT NULL COMMENT '结束日期',
  `付款方式` VARCHAR(50) DEFAULT NULL COMMENT '付款方式',
  `备注` TEXT COMMENT '备注',
  `合同状态` VARCHAR(20) NOT NULL DEFAULT 'DRAFT' COMMENT '合同状态',
  `当前流程节点` VARCHAR(50) DEFAULT NULL COMMENT '当前流程节点',
  `版本号` INT DEFAULT 1 COMMENT '版本号',
  `操作记录` VARCHAR(200) DEFAULT NULL COMMENT '操作记录',
  `操作来源` VARCHAR(50) DEFAULT NULL COMMENT '操作来源',
  `操作人员` VARCHAR(50) DEFAULT NULL COMMENT '操作人员',
  `开始操作时间` DATETIME DEFAULT NULL COMMENT '开始操作时间',
  `结束操作时间` DATETIME DEFAULT NULL COMMENT '结束操作时间',
  `校验标识` CHAR(1) DEFAULT '0' COMMENT '校验标识',
  `删除标识` CHAR(1) DEFAULT '0' COMMENT '删除标识',
  `有效标识` CHAR(1) DEFAULT '1' COMMENT '有效标识',
  `记录开始日期` DATE DEFAULT NULL COMMENT '记录开始日期',
  `记录结束日期` DATE DEFAULT NULL COMMENT '记录结束日期',
  `创建人` VARCHAR(50) DEFAULT NULL COMMENT '创建人',
  `创建时间` DATETIME DEFAULT NULL COMMENT '创建时间',
  `更新人` VARCHAR(50) DEFAULT NULL COMMENT '更新人',
  `更新时间` DATETIME DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`GUID`),
  UNIQUE KEY `uk_合同编号` (`合同编号`),
  KEY `idx_合同状态` (`合同状态`),
  KEY `idx_创建时间` (`创建时间`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='合同主表';

-- 2. 合同流程表
CREATE TABLE IF NOT EXISTS `def_contract_flow` (
  `GUID` INT NOT NULL AUTO_INCREMENT COMMENT '主键',
  `合同编号` VARCHAR(50) NOT NULL COMMENT '合同编号',
  `流程类型` VARCHAR(20) DEFAULT NULL COMMENT '流程类型(submit/approve/reject/sign/archive)',
  `流程状态` VARCHAR(20) DEFAULT NULL COMMENT '流程状态',
  `节点名称` VARCHAR(50) DEFAULT NULL COMMENT '节点名称',
  `审核人` VARCHAR(50) DEFAULT NULL COMMENT '审核人',
  `审核人姓名` VARCHAR(100) DEFAULT NULL COMMENT '审核人姓名',
  `审核时间` DATETIME DEFAULT NULL COMMENT '审核时间',
  `审核意见` TEXT COMMENT '审核意见',
  `附件` VARCHAR(500) DEFAULT NULL COMMENT '附件路径',
  `操作来源` VARCHAR(50) DEFAULT NULL COMMENT '操作来源',
  `操作人员` VARCHAR(50) DEFAULT NULL COMMENT '操作人员',
  `操作时间` DATETIME DEFAULT NULL COMMENT '操作时间',
  PRIMARY KEY (`GUID`),
  KEY `idx_合同编号` (`合同编号`),
  KEY `idx_操作时间` (`操作时间`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='合同流程表';

-- 3. 合同签署记录表
CREATE TABLE IF NOT EXISTS `def_contract_sign` (
  `GUID` INT NOT NULL AUTO_INCREMENT COMMENT '主键',
  `合同编号` VARCHAR(50) NOT NULL COMMENT '合同编号',
  `签署人` VARCHAR(50) DEFAULT NULL COMMENT '签署人',
  `签署人姓名` VARCHAR(100) DEFAULT NULL COMMENT '签署人姓名',
  `签署公司` VARCHAR(200) DEFAULT NULL COMMENT '签署公司',
  `签署时间` DATETIME DEFAULT NULL COMMENT '签署时间',
  `签署状态` VARCHAR(20) DEFAULT NULL COMMENT '签署状态(pending/signed/refused)',
  `签署方式` VARCHAR(20) DEFAULT NULL COMMENT '签署方式(electronic/manual)',
  `签名图片` VARCHAR(500) DEFAULT NULL COMMENT '签名图片路径',
  `签署IP` VARCHAR(50) DEFAULT NULL COMMENT 'IP地址',
  `签署设备` VARCHAR(100) DEFAULT NULL COMMENT '设备信息',
  `操作来源` VARCHAR(50) DEFAULT NULL COMMENT '操作来源',
  `操作人员` VARCHAR(50) DEFAULT NULL COMMENT '操作人员',
  `操作时间` DATETIME DEFAULT NULL COMMENT '操作时间',
  PRIMARY KEY (`GUID`),
  KEY `idx_合同编号` (`合同编号`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='合同签署记录表';

-- 4. 合同提醒表
CREATE TABLE IF NOT EXISTS `def_contract_reminder` (
  `GUID` INT NOT NULL AUTO_INCREMENT COMMENT '主键',
  `合同编号` VARCHAR(50) NOT NULL COMMENT '合同编号',
  `提醒类型` VARCHAR(20) DEFAULT NULL COMMENT '提醒类型(expire/renew/payment)',
  `提醒日期` DATE DEFAULT NULL COMMENT '提醒日期',
  `提前天数` INT DEFAULT NULL COMMENT '提前天数',
  `提醒状态` VARCHAR(20) DEFAULT NULL COMMENT '状态',
  `提醒人` VARCHAR(50) DEFAULT NULL COMMENT '提醒人',
  `是否已发送` TINYINT DEFAULT 0 COMMENT '是否已发送',
  `发送时间` DATETIME DEFAULT NULL COMMENT '发送时间',
  `操作来源` VARCHAR(50) DEFAULT NULL COMMENT '操作来源',
  `操作人员` VARCHAR(50) DEFAULT NULL COMMENT '操作人员',
  `操作时间` DATETIME DEFAULT NULL COMMENT '操作时间',
  PRIMARY KEY (`GUID`),
  KEY `idx_合同编号` (`合同编号`),
  KEY `idx_提醒日期` (`提醒日期`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='合同提醒表';

-- 5. 合同参与方表
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='合同参与方表';

-- 6. 合同附件表
CREATE TABLE IF NOT EXISTS `def_contract_attachment` (
  `GUID` INT NOT NULL AUTO_INCREMENT COMMENT '主键',
  `合同编号` VARCHAR(50) NOT NULL COMMENT '合同编号',
  `文件名称` VARCHAR(200) DEFAULT NULL COMMENT '原始文件名',
  `文件路径` VARCHAR(500) DEFAULT NULL COMMENT '存储路径',
  `文件大小` BIGINT DEFAULT NULL COMMENT '文件大小(字节)',
  `文件类型` VARCHAR(50) DEFAULT NULL COMMENT 'MIME类型',
  `上传人` VARCHAR(50) DEFAULT NULL COMMENT '上传人',
  `上传时间` DATETIME DEFAULT NULL COMMENT '上传时间',
  `备注` VARCHAR(500) DEFAULT NULL COMMENT '备注',
  `操作来源` VARCHAR(50) DEFAULT NULL COMMENT '操作来源',
  `操作人员` VARCHAR(50) DEFAULT NULL COMMENT '操作人员',
  `操作时间` DATETIME DEFAULT NULL COMMENT '操作时间',
  PRIMARY KEY (`GUID`),
  KEY `idx_合同编号` (`合同编号`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='合同附件表';

-- 7. 合同模板表
CREATE TABLE IF NOT EXISTS `def_contract_template` (
  `GUID` INT NOT NULL AUTO_INCREMENT COMMENT '主键',
  `模板编码` VARCHAR(50) NOT NULL COMMENT '模板编码',
  `模板名称` VARCHAR(200) DEFAULT NULL COMMENT '模板名称',
  `模板分类` VARCHAR(50) DEFAULT NULL COMMENT '分类',
  `模板内容` TEXT COMMENT '富文本内容',
  `条款库` TEXT COMMENT '条款JSON',
  `状态` VARCHAR(20) DEFAULT NULL COMMENT '状态',
  `创建人` VARCHAR(50) DEFAULT NULL COMMENT '创建人',
  `创建时间` DATETIME DEFAULT NULL COMMENT '创建时间',
  `操作来源` VARCHAR(50) DEFAULT NULL COMMENT '操作来源',
  `操作人员` VARCHAR(50) DEFAULT NULL COMMENT '操作人员',
  `操作时间` DATETIME DEFAULT NULL COMMENT '操作时间',
  PRIMARY KEY (`GUID`),
  UNIQUE KEY `uk_模板编码` (`模板编码`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='合同模板表';

-- 8. 合同类型表(数据字典)
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='合同类型表';

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
