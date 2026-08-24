-- ============================================================
-- ee_application 流程实例表 - 阶段①建表 DDL
--
-- 设计要点：
--   1. 主键 = 候选人编码（def_seq 发号 C+YYYYMMDD+3位序号），流程实例号
--      沿用既有候选人编码链，不新引入发号序列
--   2. 保留 GUID 列：兼容通用工作台按 GUID 编辑/删除的惯例，
--      阶段③读切换（def_query_config.queryTable 改指本表）时零适配
--   3. 实例头字段（招聘渠道/邀约业务/邀约岗位等）与 ee_store 同名同义搬迁，
--      阶段③切换时 def_query_column 展示配置无需改字段名
--   4. 姓名/身份证号/手机号码为入阶快照（权威值在 hr_person，
--      通过 人员编码 列关联），仅作历史时点还原与过渡期单表查询兼容
--   5. 日期类业务字段用 VARCHAR(20)：源表历史值可能为空串/NULL，
--      字符串承接零转换失败风险（'YYYY-MM-DD' 字典序即时间序）
--   6. 列宽取宽松值避免搬迁截断，上线稳定后可按实际数据分布收窄
--
-- 版本要求：MySQL 8.0+（本脚本未用函数索引/表达式默认值，8.0 任意小版本可执行）
--
-- 执行顺序：先本文件建表，再执行 ee_application_migrate.sql 存量搬迁
-- 幂等性：CREATE TABLE IF NOT EXISTS，可重复执行
-- ============================================================

-- ============================================================
-- 第 1 部分：流程实例表
-- ============================================================
CREATE TABLE IF NOT EXISTS `ee_application` (
  `GUID`           CHAR(36)     NOT NULL COMMENT '通用唯一标识（兼容通用工作台按 GUID 编辑/删除惯例，搬迁时 UUID() 生成）',
  `候选人编码`      VARCHAR(14)  NOT NULL COMMENT '流程实例号（def_seq 发号，链路起点），主键',
  `人员编码`        VARCHAR(36)  NOT NULL DEFAULT '' COMMENT '关联 hr_person.人员编码（自然人主档代理键，PersonMigrate 已回填）',
  `当前阶段`        VARCHAR(8)   NOT NULL DEFAULT '邀约' COMMENT '状态机：邀约/面试/培训/入职/终止（流转事务内更新）',

  -- 实例头字段（承 ee_store 同名列，阶段③读切换时展示配置零改动）
  `邀约业务`        VARCHAR(100) NOT NULL DEFAULT '' COMMENT '邀约业务（实例头）',
  `邀约岗位`        VARCHAR(100) NOT NULL DEFAULT '' COMMENT '邀约岗位（实例头，应聘岗位语义）',
  `邀约日期`        VARCHAR(20)  NULL DEFAULT NULL COMMENT '邀约业务日期（发号分桶依据，空值=NULL）',
  `邀约次数`        VARCHAR(10)  NOT NULL DEFAULT '' COMMENT '本实例在该人员应聘历史中的序号（PersonMigrate 重算口径，兼容历史空串）',
  `面试信息`        VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '邀约阶段的面试状态（待面试/已面试/拒绝/未面试），承 ee_store 同名列',
  `招聘渠道`        VARCHAR(100) NOT NULL DEFAULT '' COMMENT '招聘渠道（实例级，随应聘批次变化）',
  `渠道类型`        VARCHAR(100) NOT NULL DEFAULT '' COMMENT '渠道类型（实例级）',
  `渠道名称`        VARCHAR(100) NOT NULL DEFAULT '' COMMENT '渠道名称（实例级）',
  `员工类别`        VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '员工类别（渠道派生：校招→未毕业学生，社招→合同制员工）',
  `实习结束日期`    VARCHAR(20)  NULL DEFAULT NULL COMMENT '学籍属性，随应聘批次走（实例级，搬迁时自 ee_interview 承接）',
  `属地`            VARCHAR(100) NOT NULL DEFAULT '' COMMENT '入阶属地快照',

  -- 入阶身份快照（权威值在 hr_person，本处仅供历史时点还原与过渡期兼容）
  `姓名`            VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '入阶姓名快照',
  `身份证号`        VARCHAR(18)  NULL DEFAULT NULL COMMENT '入阶证件号快照（空值=NULL，与主档证件号空值语义一致）',
  `手机号码`        VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '入阶手机号快照',

  -- 阶段时间戳（全流程时间线追溯，搬迁时自下游各阶段表回填）
  `面试日期`        VARCHAR(20)  NULL DEFAULT NULL COMMENT '首次面试日期（自 ee_interview.一次面试日期 回填）',
  `培训开始日期`    VARCHAR(20)  NULL DEFAULT NULL COMMENT '首次培训开始日期（自 ee_train 回填）',
  `入职日期`        VARCHAR(20)  NULL DEFAULT NULL COMMENT '首次入职日期（自 ee_onjob.记录开始日期 回填）',
  `终止日期`        VARCHAR(20)  NULL DEFAULT NULL COMMENT '流程终止日期（终止判定时回填）',
  `终止原因`        VARCHAR(100) NOT NULL DEFAULT '' COMMENT '终止原因（邀约拒绝/面试未通过/培训离开，非空即已终止）',

  -- 审计字段（对齐 ee_store 列惯例）
  `操作记录`        VARCHAR(200) NOT NULL DEFAULT '' COMMENT '操作记录',
  `操作来源`        VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '操作来源（页面/导入/存量搬迁）',
  `操作人员`        VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '操作人工号',
  `操作时间`        DATETIME     NULL DEFAULT NULL COMMENT '最近操作时间',
  `开始操作时间`    DATETIME     NULL DEFAULT NULL COMMENT '记录创建时间',
  `结束操作时间`    DATETIME     NULL DEFAULT NULL COMMENT '记录最近一次流转/失效时间',
  `校验标识`        CHAR(1)      NOT NULL DEFAULT '0' COMMENT '校验标识',
  `删除标识`        CHAR(1)      NOT NULL DEFAULT '0' COMMENT '删除标识（软删）',
  `有效标识`        CHAR(1)      NOT NULL DEFAULT '1' COMMENT '有效标识（当前有效=1）',

  PRIMARY KEY (`候选人编码`),
  UNIQUE KEY `uk_GUID` (`GUID`),
  KEY `idx_人员编码` (`人员编码`),
  KEY `idx_当前阶段` (`当前阶段`),
  KEY `idx_邀约日期` (`邀约日期`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='流程实例表（阶段①：吸收 ee_store 实例级字段，阶段流转状态机载体，一人可多实例并行）';

-- ============================================================
-- 第 2 部分（可选）：hr_person 主档微调
--
-- 与阶段①无执行依赖，可并入本脚本或单独执行。
-- 合并至GUID：重复主档合并留痕（合并向导写入），不物理删除；
-- 出生日期：证件号派生回填（采集证件号后由主档服务补写）。
-- 已存在时 ALTER 报 1060 重复列错误，属预期，可忽略。
-- ============================================================
-- ALTER TABLE `hr_person`
--   ADD COLUMN `合并至GUID` VARCHAR(36) NULL DEFAULT NULL COMMENT '重复主档合并留痕，指向保留档的人员编码，不物理删除',
--   ADD COLUMN `出生日期` VARCHAR(20) NULL DEFAULT NULL COMMENT '出生日期（证件号派生回填）';

-- ============================================================
-- 附注：下游表幂等唯一约束（暂缓，属阶段②/④工作）
--
-- ee_interview / ee_train / ee_onjob 的 候选人编码 唯一约束可保证
-- 转入幂等（重复提交被数据库拒绝），但须先完成存量重复码治理
-- （重复码检查见 ee_application_migrate.sql 第 0.5 节），故本阶段
-- 仅预检不建约束。治理完成后执行：
--
--   ALTER TABLE ee_interview ADD UNIQUE KEY uk_候选人编码 ((NULLIF(候选人编码, '')));
--   ALTER TABLE ee_train     ADD UNIQUE KEY uk_候选人编码 ((NULLIF(候选人编码, '')));
--   ALTER TABLE ee_onjob    ADD UNIQUE KEY uk_候选人编码 ((NULLIF(候选人编码, '')));
--
-- （函数索引要求 MySQL 8.0.13+；NULLIF 容忍存量空串）
-- ============================================================
