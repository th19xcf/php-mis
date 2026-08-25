-- ============================================================
-- ee_application 流程实例表 - 建表 DDL（方案C：瘦状态头）
--
-- 设计要点：
--   1. 定位 = 纯状态机头：只承载 当前阶段（状态机）、全流程时间线、
--      终止信息。邀约业务字段与实例级业务字段（招聘渠道/邀约岗位/
--      员工类别/属地等）长期保留在 ee_store，本表不镜像、不搬迁
--   2. 主键 = GUID（INT UNSIGNED 自增技术代理键），候选人编码加
--      唯一约束 uk_候选人编码 承载业务 1:1 语义——与通用工作台
--      "按自增 GUID 编辑/删除"惯例一致（RecordEditService），
--      全流程 JOIN 链仍统一走 候选人编码 一列
--   3. UUID = 行级稳定标识（对齐 def_dept/ee_base 既有模式与
--      def_audit_log.记录UUID 惯例）：UUID_TO_BIN(UUID(), 1) 时间换序
--      存储，表达式默认值逐行自动生成，INSERT 可省该列。定位 =
--      跨系统迁移匹配键（GUID 自增值换系统会重排，UUID 终身不变）
--      + 工作台审计行引用（BaseApiController 动态检测 UUID 列自动
--      记入 def_audit_log）；绝不作为 JOIN 键，不建索引
--      （反查场景出现时再补）
--   4. 日期类时间线字段用 DATE 类型（非 VARCHAR）：
--      源表（ee_store 等）日期列为 VARCHAR 且存在脏格式
--      （'2024年9月20日'、'11/4/2024'、'2024-04-32' 等），
--      搬迁时须经多格式归一化转换链（见 ee_application_migrate.sql）
--   5. 培训时间线列命名为 参培日期（非 培训开始日期）：
--      消除与 ee_train.培训开始日期 同名不同义的歧义
--   6. 邀约日期为本表唯一自 ee_store 同步的业务字段冗余（时间线首站），
--      使 邀约→面试→培训→入职 全流程时间线可单表出数；
--      渠道等其余业务字段报表按 候选人编码 1:1 索引 JOIN ee_store 获取
--   7. 姓名/身份证号/手机号码等自然人信息不入本表：
--      权威值在 hr_person，按 人员编码 关联获取
--   8. 审计列对齐 AuditFieldsTrait 惯例列名（开始操作时间/结束操作时间），
--      校验标识 为历史死列保留以对齐 ee_store 列惯例，新代码不读写
--   9. 字符集 utf8 与 ee_* 存量表家族一致（候选人编码 JOIN 无字符集
--      转换开销；与 hr_person 的 utf8mb4 交叉 JOIN 已验证可用）
--
-- 版本要求：MySQL 8.0.13+（表达式默认值）
--
-- 执行顺序：先本文件建表，再执行 ee_application_migrate.sql 存量搬迁
-- 幂等性：CREATE TABLE IF NOT EXISTS，可重复执行
--
-- 变更记录：
--   2026-08-25 库中表为手工创建，经 ALTER 对齐本结构：
--     采纳手工改动 = DATE 类型 / 参培日期改名 / GUID 主键 + 候选人编码唯一键
--     修复手工缺陷 = 删 UUID binary(16) 阻塞列（NOT NULL 无默认且无写入方）
--                   创建时间→开始操作时间（AuditFieldsTrait 惯例）
--                   补 结束操作时间 / uk_候选人编码 / 三个查询索引
--   2026-08-25（二）恢复 UUID 列（采纳"跨系统稳定标识"设计，修复原阻塞根因）：
--     binary(16) NOT NULL DEFAULT (UUID_TO_BIN(UUID(), 1))，逐行自动生成，
--     INSERT 省略该列即由数据库填值（搬迁 SQL 不含该列）
--     注意本服务器限制：ALTER ADD COLUMN 禁止表达式默认值（binlog 安全检查），
--     存量表落地路径 = ADD COLUMN NULL → UPDATE 回填 → MODIFY 定型 三步，
--     已按此路径执行完毕
-- ============================================================

-- ============================================================
-- 第 1 部分：流程实例表（瘦状态头）
-- ============================================================
CREATE TABLE IF NOT EXISTS `ee_application` (
  `GUID`           INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '技术代理键（自增主键，工作台按 GUID 编辑/删除，INSERT 由数据库生成）',
  `UUID`           BINARY(16)   NOT NULL DEFAULT (UUID_TO_BIN(UUID(), 1)) COMMENT '行级稳定标识（时间换序存储）：跨系统迁移 GUID 重排后仍可匹配；工作台审计 def_audit_log 自动捕获；非 JOIN 键——全链 JOIN 统一走 候选人编码',
  `候选人编码`      VARCHAR(15)  NOT NULL COMMENT '流程实例号（def_seq 发号，链路起点），唯一键承载与 ee_store.候选人编码 的 1:1 业务语义',
  `人员编码`        VARCHAR(15)  NOT NULL DEFAULT '' COMMENT '关联 hr_person.人员编码（自然人主档代理键，PersonMigrate 已回填）',
  `当前阶段`        VARCHAR(8)   NOT NULL DEFAULT '邀约' COMMENT '状态机：邀约/面试/培训/入职/终止（流转事务内更新）',

  -- 全流程时间线（邀约日期为 ee_store 冗余副本，其余自下游各阶段表 MIN 回填）
  `邀约日期`        DATE         NULL DEFAULT NULL COMMENT '邀约业务日期（自 ee_store 同步的冗余副本，时间线首站；发号分桶依据，空值=NULL）',
  `面试日期`        DATE         NULL DEFAULT NULL COMMENT '首次面试日期（自 ee_interview.一次面试日期 回填）',
  `参培日期`        DATE         NULL DEFAULT NULL COMMENT '首次培训开始日期（自 ee_train.培训开始日期 回填；命名区别于源列，消除同名不同义）',
  `入职日期`        DATE         NULL DEFAULT NULL COMMENT '首次入职日期（自 ee_onjob.记录开始日期 回填）',
  `终止日期`        DATE         NULL DEFAULT NULL COMMENT '流程终止日期（终止判定时回填）',
  `终止原因`        VARCHAR(100) NOT NULL DEFAULT '' COMMENT '终止原因（邀约拒绝/面试未通过/培训离开，非空即已终止）',

  -- 审计字段（对齐 ee_store 列惯例与 AuditFieldsTrait 写入列名）
  `开始操作时间`    DATETIME     NULL DEFAULT NULL COMMENT '记录创建时间',
  `结束操作时间`    DATETIME     NULL DEFAULT NULL COMMENT '记录最近一次流转/失效时间',
  `操作记录`        VARCHAR(100) NOT NULL DEFAULT '' COMMENT '操作记录',
  `操作来源`        VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '操作来源（页面/导入/存量搬迁）',
  `操作人员`        VARCHAR(10)  NOT NULL DEFAULT '' COMMENT '操作人工号',
  `操作时间`        DATETIME     NULL DEFAULT NULL COMMENT '最近操作时间',
  `校验标识`        CHAR(1)      NOT NULL DEFAULT '0' COMMENT '校验标识（历史死列，保留以对齐列惯例，新代码不读写）',
  `删除标识`        CHAR(1)      NOT NULL DEFAULT '0' COMMENT '删除标识（软删）',
  `有效标识`        CHAR(1)      NOT NULL DEFAULT '1' COMMENT '有效标识（当前有效=1）',

  PRIMARY KEY (`GUID`),
  UNIQUE KEY `uk_候选人编码` (`候选人编码`),
  KEY `idx_人员编码` (`人员编码`),
  KEY `idx_当前阶段` (`当前阶段`),
  KEY `idx_邀约日期` (`邀约日期`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8
  COMMENT='流程实例表（方案C瘦状态头：状态机+全流程时间线+终止信息；邀约业务及实例级字段长期保留于 ee_store，按候选人编码 1:1 关联）';

-- ============================================================
-- 第 2 部分：hr_person 主档微调
--
-- UUID（已于 2026-08-25 执行）：行级稳定标识，binary(16) NOT NULL
--   DEFAULT (UUID_TO_BIN(UUID(), 1))，存量 15288 行已逐行回填唯一值
--   （0 空值）。定位 = 跨系统迁移匹配 + 工作台审计行引用；
--   人员编码仍是唯一关联键，UUID 不参与 JOIN。
--   （本服务器 ALTER ADD COLUMN 禁表达式默认值，落地走了
--     ADD NULL → UPDATE 回填 → MODIFY 定型 三步）
--
-- 以下两列待执行（与阶段①无执行依赖）：
-- 合并至GUID：重复主档合并留痕（合并向导写入），不物理删除；
-- 出生日期：证件号派生回填（采集证件号后由主档服务补写）。
-- 已存在时 ALTER 报 1060 重复列错误，属预期，可忽略。
-- ============================================================
-- ALTER TABLE `hr_person`
--   ADD COLUMN `合并至GUID` VARCHAR(36) NULL DEFAULT NULL COMMENT '重复主档合并留痕，指向保留档的人员编码，不物理删除',
--   ADD COLUMN `出生日期` VARCHAR(20) NULL DEFAULT NULL COMMENT '出生日期（证件号派生回填）';

-- ============================================================
-- 附注1：下游表幂等唯一约束（暂缓，属阶段②/④工作）
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

-- ============================================================
-- 附注2：方案C 写路径约定（阶段②落地）
--
-- 新建实例（邀约录入/导入）时须在同一事务内：
--   1. INSERT ee_store（邀约业务字段，现状不变）
--   2. INSERT ee_application（候选人编码/人员编码/当前阶段='邀约'/邀约日期）
-- 落地前的过渡期，每日 application:reconcile 检查①（源行缺失实例）
-- 暴露未建实例的新增行，重跑 ee_application_migrate.sql 第 1 部分补齐（幂等）。
-- ============================================================

-- ============================================================
-- 第 3 部分：hr_audit_log 人员信息字段级审计日志表（已建表，写入逻辑阶段②落地）
--
-- 定位：
--   - 承载自然人主档/流程实例/阶段记录的"字段级变更轨迹"（before/after），
--     回答"谁在什么时候把手机号从 A 改成了 B"（个保法合规要求）
--   - 业务表的 操作时间/操作人员 快照只保留最后一次操作，
--     历史轨迹全部由本表追加记录（append-only，只 INSERT 不 UPDATE）
--   - 参照先例：permission_audit_log（2026-07-23-100201）
--
-- 写入时机（阶段②随写路径改造落地）：
--   - PersonService::updatePerson —— 字段差异逐列记录
--   - RecordEditService / BatchEditService —— 版本化编辑时记录变更列
--   - 阶段流转（候选人编码/当前阶段 变更）—— 事件型记录（变更字段='当前阶段'）
--   - 合并向导 —— 事件型记录（变更字段='合并至'）
--
-- 结构说明：
--   1. 双定位键（人员编码/候选人编码）：主档/实例轨迹直查免 JOIN 业务表
--   2. 行级定位（表名+记录GUID+记录UUID，对齐 def_audit_log 双引用模式）：
--      GUID 为自增主键，跨系统迁移会重排；UUID 为行级稳定标识，
--      业务表含 UUID 列时记录实际值（ee_application/hr_person/
--      ee_base/def_dept 已具备），无 UUID 列的表由写入方以 0x00×16
--      占位满足 NOT NULL（BaseApiController::writeAuditLog 同款契约）
--   3. 只记变更列（值未变化的列不写行），控制日志量
--   4. 原值/新值 VARCHAR(500)：身份证号/手机号等敏感字段写入前脱敏
--      （项目约定：敏感数据展示脱敏 + 查看权限控制）
--   5. 日志表不做软删/有效标识：append-only，误记只能反向冲正行
--   6. 大字段（工作履历等超长文本）变更只记"已变更"标记，不存全文，
--      避免 BLOB 化；如需全文对比走 SCD2 历史版本
--
-- 保留策略（与项目数据治理约定一致）：
--   - 员工相关记录长期留存；未录用候选人按 1~2 年策略清理
--     （清理脚本按 人员编码 关联 hr_person → ee_application 状态判定）
--
-- 变更记录：
--   2026-08-25 库中表为手工创建，经 ALTER 对齐本结构：
--     采纳手工改动 = 表名+记录GUID 行级定位（全库 GUID 均为 INT 自增，可通用定位）
--     修复手工缺陷 = 删 记录UUID binary(16) 阻塞列（存量业务表无 UUID 列可填）
--                   补 人员编码/候选人编码 双定位键（轨迹直查免 JOIN）
--                   变更字段 20→50 / 原值/新值 50→500（防截断）
--                   补三个查询索引
--   2026-08-25（二）恢复 记录UUID 列（对齐 def_audit_log 双引用模式）：
--     binary(16) NOT NULL 无默认值——写入方契约：业务表含 UUID 列记
--     实际值，无则 0x00×16 占位；此约束让漏写直接报错而非静默造假
-- ============================================================
CREATE TABLE IF NOT EXISTS `hr_audit_log` (
  `GUID`           INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键（自增）',
  `人员编码`        VARCHAR(15)  NOT NULL DEFAULT '' COMMENT '关联 hr_person.人员编码（自然人定位键，主档轨迹直查免 JOIN）',
  `候选人编码`      VARCHAR(15)  NOT NULL DEFAULT '' COMMENT '关联 ee_application.候选人编码（流程实例定位键，主档级变更留空）',
  `表名`           VARCHAR(50)  NOT NULL COMMENT '变更发生的业务表（hr_person/ee_application/ee_store/ee_interview/ee_train/ee_onjob）',
  `记录GUID`        INT UNSIGNED NOT NULL COMMENT '业务行定位（各业务表 INT 自增 GUID，跨系统迁移会重排）',
  `记录UUID`        BINARY(16)   NOT NULL COMMENT '业务行 UUID（业务表含 UUID 列时记录实际值，无则 0x00 占位——对齐 def_audit_log 写入契约，见 BaseApiController::writeAuditLog）',
  `操作类型`        VARCHAR(10)  NOT NULL COMMENT '新增/修改/删除/流转/合并',
  `变更字段`        VARCHAR(50)  NOT NULL COMMENT '变更字段列名（事件型记录填事件字段，如 当前阶段/合并至）',
  `原值`            VARCHAR(500) NULL DEFAULT NULL COMMENT '变更前值（敏感字段脱敏后写入；新增行为 NULL）',
  `新值`            VARCHAR(500) NULL DEFAULT NULL COMMENT '变更后值（敏感字段脱敏后写入；删除行为 NULL）',
  `操作人员`        VARCHAR(10)  NOT NULL DEFAULT '' COMMENT '操作人工号',
  `操作来源`        VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '操作来源（页面/导入/工作台/流转/存量搬迁）',
  `操作时间`        DATETIME     NOT NULL COMMENT '变更发生时间（与业务表事务同时写入）',

  PRIMARY KEY (`GUID`),
  KEY `idx_人员编码_操作时间` (`人员编码`, `操作时间`),
  KEY `idx_候选人编码` (`候选人编码`),
  KEY `idx_表名_记录GUID` (`表名`, `记录GUID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8
  COMMENT='人员信息字段级审计日志（append-only：主档/实例/阶段记录变更轨迹，敏感值脱敏，员工长期留存/未录用候选人按保留策略清理）';
