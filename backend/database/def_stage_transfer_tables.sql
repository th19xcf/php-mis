-- ============================================================
-- def_stage_transfer 阶段流转字段映射配置表
-- 阶段②B（配置化消费）：建表 + 映射数据（已由 StageTransferService 消费）
--
-- 定位（与 ApplicationService::STAGE_FLOW 的分工）：
-- - STAGE_FLOW（代码常量）：状态机合法性（哪一阶段允许转到哪一阶段）
-- - def_stage_transfer（本表）：INSERT...SELECT 的字段映射
--   由 App\Services\Application\StageTransferService 运行时读取动态拼列，
--   三个 transfer（InvitationApi/InterviewApi/TrainApi）的业务字段映射
--   全部走本表，改映射只需改配置无需发版。
--
-- 配置行语义（源表+目标表定位一组映射，按 排序 列序）：
-- - 源列非空（普通列名）    → 复制源表列 t1.源列 → 目标列
-- - 源列 'tN.列名'          → JOIN 源限定列（t2=ee_store 渠道, t3=ee_interview 学籍）
-- - 源列 'expr:SQL表达式'   → 原样表达式（如员工类别按渠道派生、IFNULL 兜底）
-- - 源列空 + 默认值 '@参数' → 流转表单参数（默认值为参数键，如 @面试日期）
-- - 源列空 + 默认值 其他    → 固定值
-- - 源列空 + 默认值 NULL    → 非法配置（服务抛异常快速失败）
--
-- 系统审计列（操作记录/操作来源/操作人员/开始操作时间/有效标识/删除标识等）
-- 不入本表：由各 transfer 控制器代码侧追加（保持各流转审计口径差异）。
--
-- 列安全：表名白名单（ee_store/ee_interview/ee_train/ee_onjob），
-- 列名正则校验 + information_schema 真实存在性校验，expr 黑名单拦截
-- 分号/注释符，表单参数值一律 escape 转义。
--
-- 执行前提：无依赖，可直接执行。幂等性：INSERT 使用 NOT EXISTS 防重。
-- 执行记录：
--   2026-08-25 首次创建（阶段②A 浅方案，仅作映射底稿）
--   2026-09-03 升级配置语义并接入 StageTransferService 消费（阶段②B）：
--     ① 表单参数行改 '@参数' 显式语法
--     ② 培训→在职 招聘渠道/实习结束日期/员工类别 改 t2/t3/expr 源
--        （修正既有缺陷：原硬编码从 ee_store 取 实习结束日期，该列
--         实际只在 ee_interview，培训→在职路径此前从未成功执行）
-- ============================================================

CREATE TABLE IF NOT EXISTS `def_stage_transfer` (
  `GUID`      INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `源表`      VARCHAR(50)  NOT NULL COMMENT '源表名（如 ee_store）',
  `目标表`    VARCHAR(50)  NOT NULL COMMENT '目标表名（如 ee_interview）',
  `源列`      VARCHAR(100) NOT NULL DEFAULT '' COMMENT '源表列名；空=固定值/表单参数行',
  `目标列`    VARCHAR(100) NOT NULL COMMENT '目标表列名',
  `默认值`    VARCHAR(200) NULL DEFAULT NULL COMMENT '固定值，或 @表单参数名',
  `排序`      INT NOT NULL DEFAULT 0 COMMENT '列顺序',
  `有效标识`  CHAR(1) NOT NULL DEFAULT '1' COMMENT '有效标识',
  PRIMARY KEY (`GUID`),
  UNIQUE KEY `uk_源目标列` (`源表`, `目标表`, `目标列`),
  KEY `idx_源表目标表` (`源表`, `目标表`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='阶段流转字段映射配置';

-- ============================================================
-- 映射数据（与三个 transfer 行为一致，StageTransferService 消费）
-- FROM 子句约定：t1=源表；培训→在职另有 t2(ee_store 渠道)/t3(ee_interview 学籍)
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
  UNION ALL SELECT '',           '一次面试日期', '@面试日期', 12
  UNION ALL SELECT '',           '一次面试人',   '@面试人', 13
  UNION ALL SELECT '',           '一次面试结果', '@面试结果', 14
  UNION ALL SELECT '',           '预约培训日期', '@预约培训日期', 15
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
  UNION ALL SELECT '',           '培训业务',   '@培训业务', 7
  UNION ALL SELECT '',           '培训状态',   '在培', 8
  UNION ALL SELECT '',           '培训批次',   '@培训批次', 9
  UNION ALL SELECT '',           '培训老师',   '@培训老师', 10
  UNION ALL SELECT '',           '培训开始日期', '@培训开始日期', 11
  UNION ALL SELECT '',           '预计完成日期', '@预计完成日期', 12
  UNION ALL SELECT '',           '面试信息',   '有', 13
) t
WHERE NOT EXISTS (
  SELECT 1 FROM `def_stage_transfer`
  WHERE 源表='ee_interview' AND 目标表='ee_train' AND 目标列=t.目标列
);

-- ---------- 培训 → 在职（TrainApi::transfer）----------
-- t2 = ee_store 活跃行（招聘渠道）；t3 = ee_interview 活跃行（实习结束日期，学籍属性）
INSERT INTO `def_stage_transfer` (`源表`, `目标表`, `源列`, `目标列`, `默认值`, `排序`)
SELECT 'ee_train', 'ee_onjob', t.源列, t.目标列, t.默认值, t.排序
FROM (
  SELECT '候选人编码' AS 源列, '候选人编码' AS 目标列, NULL AS 默认值, 1 AS 排序
  UNION ALL SELECT '人员编码',   '人员编码',   NULL, 2
  UNION ALL SELECT '姓名',       '姓名',       NULL, 3
  UNION ALL SELECT '身份证号',   '身份证号',   NULL, 4
  UNION ALL SELECT '手机号码',   '手机号码',   NULL, 5
  UNION ALL SELECT '属地',       '属地',       NULL, 6
  UNION ALL SELECT '',           '入职次数',   '@入职次数', 7
  UNION ALL SELECT 'expr:IFNULL(t2.招聘渠道,"")', '招聘渠道', NULL, 8
  UNION ALL SELECT 'expr:IF(t2.招聘渠道="校招","未毕业学生","合同制员工")', '员工类别', NULL, 9
  UNION ALL SELECT 'expr:IFNULL(t3.实习结束日期,"")', '实习结束日期', NULL, 10
  UNION ALL SELECT '',           '部门编码',   '', 11
  UNION ALL SELECT '',           '部门名称',   '', 12
  UNION ALL SELECT '',           '班组',       '', 13
  UNION ALL SELECT '',           '岗位名称',   '客服代表', 14
  UNION ALL SELECT '',           '岗位类型',   '@岗位类型', 15
  UNION ALL SELECT '',           '结算类型',   '@结算类型', 16
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
  UNION ALL SELECT '',           '记录开始日期', '@培训结束日期', 29
  UNION ALL SELECT '',           '记录结束日期', '', 30
) t
WHERE NOT EXISTS (
  SELECT 1 FROM `def_stage_transfer`
  WHERE 源表='ee_train' AND 目标表='ee_onjob' AND 目标列=t.目标列
);
