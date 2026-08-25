-- ============================================================
-- def_stage_transfer 阶段流转字段映射配置表
-- 阶段②A（浅方案）：建表 + 灌入现有三个 transfer 的字段映射存量
--
-- 定位（与 ApplicationService::STAGE_FLOW 的分工）：
-- - STAGE_FLOW（代码常量）：状态机合法性（哪一阶段允许转到哪一阶段）
-- - def_stage_transfer（本表）：INSERT...SELECT 的字段映射（源列→目标列、固定值）
--
-- 当前浅方案：本表仅作为映射文档与后续配置化改造的底稿，
-- 代码仍按硬编码 SQL 执行；阶段②后续再将三个 transfer 改为读表动态生成。
--
-- 执行前提：无依赖，可直接执行。幂等性：INSERT 使用 NOT EXISTS 防重。
-- 执行记录：
--   2026-08-25 首次创建（阶段②A）
-- ============================================================

CREATE TABLE IF NOT EXISTS `def_stage_transfer` (
  `GUID`      INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `源表`      VARCHAR(50)  NOT NULL COMMENT '源表名（如 ee_store）',
  `目标表`    VARCHAR(50)  NOT NULL COMMENT '目标表名（如 ee_interview）',
  `源列`      VARCHAR(100) NOT NULL DEFAULT '' COMMENT '源表列名；空=固定值/表单值行',
  `目标列`    VARCHAR(100) NOT NULL COMMENT '目标表列名',
  `默认值`    VARCHAR(200) NULL DEFAULT NULL COMMENT '目标列固定值（固定值行生效）',
  `排序`      INT NOT NULL DEFAULT 0 COMMENT '列顺序',
  `有效标识`  CHAR(1) NOT NULL DEFAULT '1' COMMENT '有效标识',
  PRIMARY KEY (`GUID`),
  UNIQUE KEY `uk_源目标列` (`源表`, `目标表`, `目标列`),
  KEY `idx_源表目标表` (`源表`, `目标表`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='阶段流转字段映射配置';

-- ============================================================
-- 存量映射数据（与三个 transfer 硬编码 SQL 一一对应）
-- 源列空 = 固定值（默认值列）或流转表单参数（默认值空）
-- ============================================================

-- ---------- 邀约 → 面试（InvitationApi::transfer）----------
INSERT INTO `def_stage_transfer` (`源表`, `目标表`, `源列`, `目标列`, `默认值`, `排序`)
SELECT 'ee_store', 'ee_interview', t.源列, t.目标列, t.默认值, t.排序
FROM (
  SELECT '候选人编码' AS 源列, '候选人编码' AS 目标列, NULL AS 默认值, 1 AS 排序
  UNION ALL SELECT '人员编码',   '人员编码',   NULL, 2
  UNION ALL SELECT '姓名',       '姓名',       NULL, 3
  UNION ALL SELECT '身份证号',   '身份证号',   NULL, 4
  UNION ALL SELECT '手机号码',   '手机号码',   NULL, 5
  UNION ALL SELECT '属地',       '属地',       NULL, 6
  UNION ALL SELECT '招聘渠道',   '招聘渠道',   NULL, 7
  UNION ALL SELECT '渠道类型',   '渠道类型',   NULL, 8
  UNION ALL SELECT '渠道名称',   '渠道名称',   NULL, 9
  UNION ALL SELECT '邀约业务',   '面试业务',   NULL, 10
  UNION ALL SELECT '邀约岗位',   '面试岗位',   NULL, 11
  UNION ALL SELECT '',           '一次面试日期', NULL, 12
  UNION ALL SELECT '',           '一次面试人',   NULL, 13
  UNION ALL SELECT '',           '一次面试结果', NULL, 14
  UNION ALL SELECT '',           '预约培训日期', NULL, 15
  UNION ALL SELECT '',           '邀约信息',   '通过', 16
) t
WHERE NOT EXISTS (
  SELECT 1 FROM `def_stage_transfer`
  WHERE 源表='ee_store' AND 目标表='ee_interview' AND 目标列=t.目标列
);

-- ---------- 面试 → 培训（InterviewApi::transfer）----------
INSERT INTO `def_stage_transfer` (`源表`, `目标表`, `源列`, `目标列`, `默认值`, `排序`)
SELECT 'ee_interview', 'ee_train', t.源列, t.目标列, t.默认值, t.排序
FROM (
  SELECT '候选人编码' AS 源列, '候选人编码' AS 目标列, NULL AS 默认值, 1 AS 排序
  UNION ALL SELECT '人员编码',   '人员编码',   NULL, 2
  UNION ALL SELECT '姓名',       '姓名',       NULL, 3
  UNION ALL SELECT '身份证号',   '身份证号',   NULL, 4
  UNION ALL SELECT '手机号码',   '手机号码',   NULL, 5
  UNION ALL SELECT '属地',       '属地',       NULL, 6
  UNION ALL SELECT '',           '培训业务',   NULL, 7
  UNION ALL SELECT '',           '培训状态',   '在培', 8
  UNION ALL SELECT '',           '培训批次',   NULL, 9
  UNION ALL SELECT '',           '培训老师',   NULL, 10
  UNION ALL SELECT '',           '培训开始日期', NULL, 11
  UNION ALL SELECT '',           '预计完成日期', NULL, 12
  UNION ALL SELECT '',           '面试信息',   '有', 13
) t
WHERE NOT EXISTS (
  SELECT 1 FROM `def_stage_transfer`
  WHERE 源表='ee_interview' AND 目标表='ee_train' AND 目标列=t.目标列
);

-- ---------- 培训 → 在职（TrainApi::transfer）----------
INSERT INTO `def_stage_transfer` (`源表`, `目标表`, `源列`, `目标列`, `默认值`, `排序`)
SELECT 'ee_train', 'ee_onjob', t.源列, t.目标列, t.默认值, t.排序
FROM (
  SELECT '候选人编码' AS 源列, '候选人编码' AS 目标列, NULL AS 默认值, 1 AS 排序
  UNION ALL SELECT '人员编码',   '人员编码',   NULL, 2
  UNION ALL SELECT '姓名',       '姓名',       NULL, 3
  UNION ALL SELECT '身份证号',   '身份证号',   NULL, 4
  UNION ALL SELECT '手机号码',   '手机号码',   NULL, 5
  UNION ALL SELECT '属地',       '属地',       NULL, 6
  UNION ALL SELECT '',           '入职次数',   NULL, 7
  UNION ALL SELECT '',           '招聘渠道',   NULL, 8
  UNION ALL SELECT '',           '员工类别',   NULL, 9
  UNION ALL SELECT '',           '实习结束日期', NULL, 10
  UNION ALL SELECT '',           '部门编码',   '', 11
  UNION ALL SELECT '',           '部门名称',   '', 12
  UNION ALL SELECT '',           '班组',       '', 13
  UNION ALL SELECT '',           '岗位名称',   '客服代表', 14
  UNION ALL SELECT '',           '岗位类型',   NULL, 15
  UNION ALL SELECT '',           '结算类型',   NULL, 16
  UNION ALL SELECT '',           '工号1',      '', 17
  UNION ALL SELECT '',           '工号2',      '', 18
  UNION ALL SELECT '',           '培训信息',   '有', 19
  UNION ALL SELECT '培训开始日期', '培训开始日期', NULL, 20
  UNION ALL SELECT '培训完成日期', '培训完成日期', NULL, 21
  UNION ALL SELECT '培训完成日期', '一阶段日期', NULL, 22
  UNION ALL SELECT '',           '二阶段日期', '', 23
  UNION ALL SELECT '',           '员工阶段',   '新人组', 24
  UNION ALL SELECT '',           '员工状态',   '在职', 25
  UNION ALL SELECT '',           '离职日期',   '', 26
  UNION ALL SELECT '',           '离职原因',   '', 27
  UNION ALL SELECT '',           '派遣公司',   '', 28
  UNION ALL SELECT '',           '记录开始日期', NULL, 29
  UNION ALL SELECT '',           '记录结束日期', '', 30
) t
WHERE NOT EXISTS (
  SELECT 1 FROM `def_stage_transfer`
  WHERE 源表='ee_train' AND 目标表='ee_onjob' AND 目标列=t.目标列
);
