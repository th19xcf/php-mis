-- ============================================================
-- ee_employment 雇佣记录表 - 建表 DDL（阶段③：ee_onjob 升级为 ee_employment）
--
-- 设计要点：
--   1. 定位 = 雇佣史权威表：一行 = 某人（hr_person.人员编码）的一段雇佣
--      关系，1人:N条记录；替代 ee_onjob 的"流程链末端阶段表"定位
--      （ee_onjob 键=候选人编码，一人多次入职散在多条流程链下无法聚合）
--   2. 入职次数 = 派生列（INT）：服务端按人员编码下已有活跃记录数+1
--      派生（窗口函数 ROW_NUMBER），禁止人工传入——替代 ee_onjob 的
--      前端手填入职次数（存量实测 1次6574行/2次91行/3次1行，无校验）
--   3. 生命周期日期列用 DATE 类型（ee_onjob 源列为 VARCHAR 存在脏格式，
--      搬迁经多格式归一化转换链，见 EmploymentMigrateService）：
--      - 记录开始日期 = 雇佣开始（入职日期）；SCD2 新版本行复制旧行原值
--        不漂移（修复 ee_onjob 被"生效日期"覆盖漂移的既有缺陷）
--      - 记录结束日期 = 雇佣结束；NULL=在职（目标规格）。SCD2 版本失效
--        不写此列（版本失效≠雇佣结束），版本时效由 结束操作时间+有效标识 承载
--      - 离职日期 = 离职事实日期
--   4. 个人信息不入本表：姓名/身份证号/手机号码权威值在 hr_person，
--      按 人员编码 关联获取（对齐 ee_application 设计约定）
--   5. 列裁剪（相对 ee_onjob，2026-09-03 生产库实测全空的列不迁入）：
--      不迁入 = 姓名/身份证号/手机号码（主档权威）、员工编码（链式编码
--      淘汰）、三阶段日期/四阶段日期/正式期日期（0 行有值）、小组/派遣公司
--      （0 行有值，列保留待业务启用时再加）；保留备注（7 行有值）
--   6. 入阶快照列保留 VARCHAR 原样快照（实习结束日期/培训开始日期/
--      培训完成日期/一阶段日期/二阶段日期）：入阶时点冻结值，仅
--      记录开始/结束/离职 三个生命周期日期升级为 DATE
--   7. 函数唯一索引复用 ee_active_unique_indexes.sql 既有模式：
--      - uk_候选人编码_活跃：同一候选人编码至多一条活跃雇佣记录
--        （流转幂等硬兜底，重复提交 1062 整事务回滚）
--      - uk_人员编码_次数_活跃：同人同序号至多一条活跃记录（派生序号
--        防重硬兜底）；SCD2 版本对（有效标识='0'）落 NULL 不判重
--      （MySQL 8.0.13+；若 CREATE TABLE 内联函数索引触发本服务器
--        binlog 安全限制，退路 = 建表后 ALTER TABLE ADD UNIQUE KEY，
--        同 ee_active_unique_indexes.sql 执行路径）
--   8. SCD2/审计列对齐 ee_onjob 列惯例；时间列升级 DATETIME
--      （ee_application 先例），搬迁经 STR_TO_DATE 归一化，脏值落 NULL
--
-- 五步路径（2026-09-03 计划确认）：①建表+存量迁移（本文件+migrate 命令）
--   ②双写过渡（TrainApi 双写 / processResignation 双关 / reconcile 对账）
--   ③读切换 ④写切换（同一部署窗口）⑤归档 ee_onjob（RENAME，不物理删除）
--
-- 存量迁移决策（2026-09-03 用户确认）：ee_onjob 11464 条 SCD2 失效版本行
--   不迁移，随旧表归档；仅迁 有效标识='1' AND 删除标识='0' 的 6666 行
--   （其中 1335 行已离职、57 人双链）
--
-- 版本要求：MySQL 8.0.13+（表达式默认值/函数索引）
--
-- 幂等性：CREATE TABLE IF NOT EXISTS + 配置 INSERT NOT EXISTS 防重，可重复执行
--
-- 变更记录：
--   2026-09-03 首次创建（阶段③第①步）
-- ============================================================

-- ============================================================
-- 第 1 部分：雇佣记录表
-- ============================================================
CREATE TABLE IF NOT EXISTS `ee_employment` (
  `GUID`           INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '技术代理键（自增主键，页面/工作台按 GUID 编辑/删除，INSERT 由数据库生成）',
  `UUID`           BINARY(16)   NOT NULL DEFAULT (UUID_TO_BIN(UUID(), 1)) COMMENT '行级稳定标识（时间换序存储）：跨系统迁移匹配键 + 审计行引用；非 JOIN 键——主档关联统一走 人员编码',

  `人员编码`        VARCHAR(15)  NOT NULL COMMENT '关联 hr_person.人员编码（自然人主档代理键）：1人:N条雇佣记录的锚点',
  `候选人编码`      VARCHAR(15)  NOT NULL DEFAULT '' COMMENT '流程链引用（该段雇佣来自哪次应聘实例，ee_application.候选人编码）',
  `入职次数`        INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '派生列：该人雇佣记录序号（服务端按人员编码下活跃记录数+1 派生，禁止人工传入）',

  -- 入阶快照（入职时点冻结，VARCHAR 原样快照）
  `属地`           VARCHAR(10)  NOT NULL DEFAULT '' COMMENT '入阶属地快照（继承自 ee_train）',
  `招聘渠道`        VARCHAR(10)  NOT NULL DEFAULT '' COMMENT '入阶招聘渠道快照（流转时自 ee_store 取）',
  `员工类别`        VARCHAR(10)  NOT NULL DEFAULT '' COMMENT '员工类别快照（按招聘渠道派生：校招=未毕业学生/其他=合同制员工）',
  `实习结束日期`    VARCHAR(10)  NOT NULL DEFAULT '' COMMENT '实习结束日期快照（学籍属性，流转时自 ee_interview 取）',
  `培训信息`        VARCHAR(10)  NOT NULL DEFAULT '' COMMENT '培训信息（有/无）',
  `培训开始日期`    VARCHAR(10)  NOT NULL DEFAULT '' COMMENT '培训开始日期快照',
  `培训完成日期`    VARCHAR(10)  NOT NULL DEFAULT '' COMMENT '培训完成日期快照',
  `一阶段日期`      VARCHAR(10)  NOT NULL DEFAULT '' COMMENT '一阶段日期（=培训完成日期，阶段跟踪起点）',
  `二阶段日期`      VARCHAR(10)  NOT NULL DEFAULT '' COMMENT '二阶段日期',

  -- 雇佣业务字段
  `部门编码`        VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '部门编码',
  `部门名称`        VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '部门名称',
  `班组`           VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '班组',
  `小组`           VARCHAR(10)  NOT NULL DEFAULT '' COMMENT '小组',
  `岗位名称`        VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '岗位名称',
  `岗位类型`        VARCHAR(10)  NOT NULL DEFAULT '' COMMENT '岗位类型',
  `结算类型`        VARCHAR(10)  NOT NULL DEFAULT '' COMMENT '结算类型',
  `工号1`          VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '工号1',
  `工号2`          VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '工号2',
  `派遣公司`        VARCHAR(10)  NOT NULL DEFAULT '' COMMENT '派遣公司',
  `备注`           VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '备注',

  -- 在职阶段跟踪（可更新）
  `员工阶段`        VARCHAR(10)  NOT NULL DEFAULT '' COMMENT '员工阶段（新人组等，随在职周期更新）',
  `员工状态`        VARCHAR(10)  NOT NULL DEFAULT '' COMMENT '员工状态（在职/离职）',

  -- 生命周期日期（DATE；NULL=未发生）
  `记录开始日期`    DATE         NULL DEFAULT NULL COMMENT '雇佣开始日期（=入职日期；SCD2 新版本行复制旧行原值不漂移）',
  `记录结束日期`    DATE         NULL DEFAULT NULL COMMENT '雇佣结束日期（NULL=在职；离职时=离职日期；SCD2 版本失效不写此列）',
  `离职日期`        DATE         NULL DEFAULT NULL COMMENT '离职日期',
  `离职原因`        VARCHAR(100) NOT NULL DEFAULT '' COMMENT '离职原因',

  -- 审计字段（对齐 ee_onjob 列惯例；时间列升级 DATETIME）
  `操作记录`        VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '操作记录',
  `操作来源`        VARCHAR(30)  NOT NULL DEFAULT '' COMMENT '操作来源（页面/导入/流转/存量搬迁）',
  `操作人员`        VARCHAR(10)  NOT NULL DEFAULT '' COMMENT '操作人工号',
  `开始操作时间`    DATETIME     NULL DEFAULT NULL COMMENT '记录创建时间',
  `结束操作时间`    DATETIME     NULL DEFAULT NULL COMMENT '记录最近一次失效/流转时间',
  `操作时间`        DATETIME     NULL DEFAULT NULL COMMENT '最近操作时间',
  `校验标识`        VARCHAR(2)   NOT NULL DEFAULT '0' COMMENT '校验标识（历史死列，保留以对齐列惯例，新代码不读写）',
  `删除标识`        VARCHAR(2)   NOT NULL DEFAULT '0' COMMENT '删除标识（软删）',
  `有效标识`        VARCHAR(2)   NOT NULL DEFAULT '1' COMMENT '有效标识（当前有效=1）',

  PRIMARY KEY (`GUID`),
  UNIQUE KEY `uk_候选人编码_活跃` ((CASE WHEN `有效标识` = '1' AND `删除标识` = '0' THEN NULLIF(`候选人编码`, '') END)),
  UNIQUE KEY `uk_人员编码_次数_活跃` (`人员编码`, (CASE WHEN `有效标识` = '1' AND `删除标识` = '0' THEN `入职次数` END)),
  KEY `idx_人员编码` (`人员编码`),
  KEY `idx_候选人编码` (`候选人编码`),
  KEY `idx_记录开始日期` (`记录开始日期`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8
  COMMENT='雇佣记录表（阶段③：1人N条雇佣记录，挂 hr_person.人员编码；入职次数派生；NULL记录结束日期=在职；离职=关闭记录+状态机流转）';

-- ============================================================
-- 第 2 部分：def_stage_transfer 新增 培训→雇佣记录 映射（阶段③双写）
--
-- 自既有 ee_train→ee_onjob 30 行裁剪：
--   去掉 姓名/身份证号/手机号码（主档权威，不入 ee_employment）
--   去掉 入职次数（TrainApi 以 systemColumns 注入派生表达式）
--   去掉 离职日期/离职原因/记录结束日期/二阶段日期（新入职行由 DDL
--     默认值承担：DATE NULL / ''，流转时不写）
-- ============================================================
INSERT INTO `def_stage_transfer` (`源表`, `目标表`, `源列`, `目标列`, `默认值`, `排序`)
SELECT 'ee_train', 'ee_employment', t.源列, t.目标列, t.默认值, t.排序
FROM (
  SELECT '候选人编码' AS 源列, '候选人编码' AS 目标列, NULL AS 默认值, 1 AS 排序
  UNION ALL SELECT '人员编码',   '人员编码',   NULL, 2
  UNION ALL SELECT '属地',       '属地',       NULL, 3
  UNION ALL SELECT 'expr:IFNULL(t2.招聘渠道,"")', '招聘渠道', NULL, 4
  UNION ALL SELECT 'expr:IF(t2.招聘渠道="校招","未毕业学生","合同制员工")', '员工类别', NULL, 5
  UNION ALL SELECT 'expr:IFNULL(t3.实习结束日期,"")', '实习结束日期', NULL, 6
  UNION ALL SELECT '',           '部门编码',   '', 7
  UNION ALL SELECT '',           '部门名称',   '', 8
  UNION ALL SELECT '',           '班组',       '', 9
  UNION ALL SELECT '',           '岗位名称',   '客服代表', 10
  UNION ALL SELECT '',           '岗位类型',   '@岗位类型', 11
  UNION ALL SELECT '',           '结算类型',   '@结算类型', 12
  UNION ALL SELECT '',           '工号1',      '', 13
  UNION ALL SELECT '',           '工号2',      '', 14
  UNION ALL SELECT '',           '培训信息',   '有', 15
  UNION ALL SELECT '培训开始日期', '培训开始日期', NULL, 16
  UNION ALL SELECT '培训完成日期', '培训完成日期', NULL, 17
  UNION ALL SELECT '培训完成日期', '一阶段日期', NULL, 18
  UNION ALL SELECT '',           '员工阶段',   '新人组', 19
  UNION ALL SELECT '',           '员工状态',   '在职', 20
  UNION ALL SELECT '',           '派遣公司',   '', 21
  UNION ALL SELECT '',           '记录开始日期', '@培训结束日期', 22
) t
WHERE NOT EXISTS (
  SELECT 1 FROM `def_stage_transfer`
  WHERE 源表='ee_train' AND 目标表='ee_employment' AND 目标列=t.目标列
);

-- ============================================================
-- 第 3 部分：ee_train→ee_onjob 入职次数配置行下线（阶段③双写前提）
--
-- ee_onjob 降级为镜像表：入职次数改由 TrainApi 以 systemColumns 注入
-- 镜像表达式（自 ee_employment 权威行取值）。本配置行必须与②代码
-- 同窗口执行，否则与 systemColumns 重复注入导致 Column specified twice。
-- 执行留档：UPDATE 返回受影响行数应为 1（重复执行为 0，幂等）。
-- ============================================================
UPDATE `def_stage_transfer`
SET `有效标识` = '0'
WHERE `源表` = 'ee_train' AND `目标表` = 'ee_onjob' AND `目标列` = '入职次数';
