-- ============================================================
-- ee_application 存量搬迁 SQL - 阶段①（方案C：瘦状态头）
--
-- 方案C 口径：
--   - ee_store 原样保留（邀约业务字段 + 实例级业务字段的权威源），
--     本脚本不搬任何业务字段，只构建状态头：
--     候选人编码 / 人员编码 / 当前阶段 / 邀约日期（冗余副本）
--   - 邀约拒绝判定所需的 面试信息 仍读 ee_store（JOIN 获取）
--
-- 前置条件：
--   1. 已执行 ee_application_tables.sql 建表（或手工建表并完成 ALTER 对齐）
--   2. 已执行 php spark candidate:backfill --execute（候选人编码已回填）
--   3. 已执行 php spark person:migrate --execute（人员编码已回填）
--
-- 执行方式：按部分顺序执行；第 0 部分为人工核对检查点，必须逐条
--           核对结果后再继续。第 1~4 部分全部幂等，可安全重跑。
--
-- 执行前提（2026-08-25 首次执行踩坑记录，重跑前必读）：
--   1. 会话级放宽严格模式后再执行第 1~4 部分：
--        SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION';
--      原因：源表存在"可解析但不存在"的脏日期（如 '2024-04-31'），
--      严格模式下 STR_TO_DATE/CAST 的 WARNING 会升级为 ERROR 阻断搬迁；
--      放宽后该类值落 '0000-00-00'，由 1.2 统一清理为 NULL。
--      仅影响当前会话，不改服务器全局配置。
--   2. 下游三表须有 idx_候选人编码（2026-08-25 已建，见 0.8），
--      否则第 3/4 部分关联 EXISTS 全表扫描，会触发 30s 查询超时。
--
-- 搬迁口径：
--   - 只搬 ee_store 当前有效行（有效标识='1' AND 删除标识='0'），
--     SCD2 历史版本行（有效标识='0'）与软删行留在 ee_store 原地归档
--   - 审计字段原样保留（快照保真），搬迁事实通过本脚本执行记录追溯；
--     校验标识 为死列不搬（目标表默认 '0'）
--   - 空候选人编码行无法成为实例（唯一键），由 0.3 预检暴露后先回填
--
-- DATE 类型归一化（ee_application 时间线列为 DATE，源表为 VARCHAR）：
--   源表日期存在多格式脏值（'2024/11/12'、'11/4/2024'、'2024年9月20日'、
--   '20221208'），统一经"日期归一化转换链"转换后再入库：
--     COALESCE(
--       STR_TO_DATE(NULLIF(<列>, ''), '%Y-%m-%d'),                      -- 标准与非零填充
--       STR_TO_DATE(NULLIF(<列>, ''), '%Y/%m/%d'),                      -- 斜杠分隔
--       STR_TO_DATE(NULLIF(<列>, ''), '%m/%d/%Y'),                      -- 美式 月/日/年
--       STR_TO_DATE(NULLIF(<列>, ''), '%Y%m%d'),                        -- 无分隔紧凑
--       STR_TO_DATE(REPLACE(REPLACE(NULLIF(<列>, ''), '年', '-'),
--                    '日', ''), '%Y-%m-%d')                             -- 中文年月日
--     )
--   全部格式均无法解析的脏值（如 '2024-04-32'、'12.8'）转为 NULL，
--   由 0.7 预检与 5.6 核验暴露后人工修源；不阻塞搬迁。
--
-- 数据量提示：单表百万行以上时，第 1 部分建议按邀约日期分批提交
-- ============================================================

-- ============================================================
-- 第 0 部分：前置检查（人工执行并核对结果，不写库）
-- ============================================================

-- 0.1 关联列结构确认
--     预期仅出现 候选人编码 各一行（当前代码 InterviewApi/TrainApi 转入
--     已统一写 候选人编码 列，原 初始编码/培训编码 已随表结构瘦身移除）。
--     若仍返回 初始编码/培训编码（旧结构库），需先统一列名后再继续。
SELECT TABLE_NAME, COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND ( (TABLE_NAME = 'ee_train'  AND COLUMN_NAME IN ('候选人编码', '初始编码'))
     OR (TABLE_NAME = 'ee_onjob'  AND COLUMN_NAME IN ('候选人编码', '培训编码')) )
ORDER BY TABLE_NAME, COLUMN_NAME;

-- 0.2 活跃行重复候选人编码（必须返回 0 行，否则搬迁唯一键冲突）
--     理论上 ee_store.uk_候选人编码 已保证全表唯一，此处防御性复核
--     （唯一键若未实际建上，历史版本行可能持重复码）。
SELECT 候选人编码, COUNT(*) AS c
FROM ee_store
WHERE 有效标识 = '1' AND 删除标识 = '0' AND 候选人编码 <> ''
GROUP BY 候选人编码
HAVING c > 1;

-- 0.3 活跃行空候选人编码统计（>0 时先跑 candidate:backfill）
SELECT COUNT(*) AS 空码行数
FROM ee_store
WHERE 有效标识 = '1' AND 删除标识 = '0'
  AND (候选人编码 = '' OR 候选人编码 IS NULL);

-- 0.4 活跃行人员编码未回填统计（>0 时先跑 person:migrate）
SELECT COUNT(*) AS 无主档链接行数
FROM ee_store
WHERE 有效标识 = '1' AND 删除标识 = '0'
  AND (人员编码 = '' OR 人员编码 IS NULL);

-- 0.5 下游表重复候选人编码（阶段①仅报告不治理，治理后方可在
--     阶段②/④建幂等唯一约束，见 ee_application_tables.sql 附注1）
SELECT 'ee_interview' AS 表名, 候选人编码, COUNT(*) AS c
FROM ee_interview
WHERE 有效标识 = '1' AND 删除标识 = '0' AND 候选人编码 <> ''
GROUP BY 候选人编码 HAVING c > 1
UNION ALL
SELECT 'ee_train', 候选人编码, COUNT(*)
FROM ee_train
WHERE 有效标识 = '1' AND 删除标识 = '0' AND 候选人编码 <> ''
GROUP BY 候选人编码 HAVING COUNT(*) > 1
UNION ALL
SELECT 'ee_onjob', 候选人编码, COUNT(*)
FROM ee_onjob
WHERE 有效标识 = '1' AND 删除标识 = '0' AND 候选人编码 <> ''
GROUP BY 候选人编码 HAVING COUNT(*) > 1;

-- 0.6 下游孤儿码统计（下游行持码但 ee_store 无对应活跃行：
--     继承错链/源头行已失效等，人工决定是否补建实例，阶段④前清零）
SELECT 'ee_interview' AS 表名, COUNT(*) AS 孤儿行数
FROM ee_interview i
WHERE i.有效标识 = '1' AND i.删除标识 = '0' AND IFNULL(i.候选人编码, '') <> ''
  AND NOT EXISTS (SELECT 1 FROM ee_store s
                  WHERE s.候选人编码 = i.候选人编码
                    AND s.有效标识 = '1' AND s.删除标识 = '0')
UNION ALL
SELECT 'ee_train', COUNT(*)
FROM ee_train t
WHERE t.有效标识 = '1' AND t.删除标识 = '0' AND IFNULL(t.候选人编码, '') <> ''
  AND NOT EXISTS (SELECT 1 FROM ee_store s
                  WHERE s.候选人编码 = t.候选人编码
                    AND s.有效标识 = '1' AND s.删除标识 = '0')
UNION ALL
SELECT 'ee_onjob', COUNT(*)
FROM ee_onjob o
WHERE o.有效标识 = '1' AND o.删除标识 = '0' AND IFNULL(o.候选人编码, '') <> ''
  AND NOT EXISTS (SELECT 1 FROM ee_store s
                  WHERE s.候选人编码 = o.候选人编码
                    AND s.有效标识 = '1' AND s.删除标识 = '0');

-- 0.7 源表日期全格式不可解析脏值（转换链后仍为 NULL 的行：
--     如 '2024-04-32'、'202305-05-'、'12.8'。不阻塞搬迁——
--     对应时间线字段置 NULL，人工修正源值后重跑第 1/2 部分补齐）
SELECT 'ee_store.邀约日期' AS 列, COUNT(*) AS 脏值行数
FROM ee_store
WHERE IFNULL(邀约日期, '') <> ''
  AND COALESCE(
        STR_TO_DATE(NULLIF(邀约日期, ''), '%Y-%m-%d'),
        STR_TO_DATE(NULLIF(邀约日期, ''), '%Y/%m/%d'),
        STR_TO_DATE(NULLIF(邀约日期, ''), '%m/%d/%Y'),
        STR_TO_DATE(NULLIF(邀约日期, ''), '%Y%m%d'),
        STR_TO_DATE(REPLACE(REPLACE(NULLIF(邀约日期, ''), '年', '-'), '日', ''), '%Y-%m-%d')
      ) IS NULL
UNION ALL
SELECT 'ee_interview.一次面试日期', COUNT(*)
FROM ee_interview
WHERE IFNULL(一次面试日期, '') <> ''
  AND COALESCE(
        STR_TO_DATE(NULLIF(一次面试日期, ''), '%Y-%m-%d'),
        STR_TO_DATE(NULLIF(一次面试日期, ''), '%Y/%m/%d'),
        STR_TO_DATE(NULLIF(一次面试日期, ''), '%m/%d/%Y'),
        STR_TO_DATE(NULLIF(一次面试日期, ''), '%Y%m%d'),
        STR_TO_DATE(REPLACE(REPLACE(NULLIF(一次面试日期, ''), '年', '-'), '日', ''), '%Y-%m-%d')
      ) IS NULL
UNION ALL
SELECT 'ee_train.培训开始日期', COUNT(*)
FROM ee_train
WHERE IFNULL(培训开始日期, '') <> ''
  AND COALESCE(
        STR_TO_DATE(NULLIF(培训开始日期, ''), '%Y-%m-%d'),
        STR_TO_DATE(NULLIF(培训开始日期, ''), '%Y/%m/%d'),
        STR_TO_DATE(NULLIF(培训开始日期, ''), '%m/%d/%Y'),
        STR_TO_DATE(NULLIF(培训开始日期, ''), '%Y%m%d'),
        STR_TO_DATE(REPLACE(REPLACE(NULLIF(培训开始日期, ''), '年', '-'), '日', ''), '%Y-%m-%d')
      ) IS NULL
UNION ALL
SELECT 'ee_onjob.记录开始日期', COUNT(*)
FROM ee_onjob
WHERE IFNULL(记录开始日期, '') <> ''
  AND COALESCE(
        STR_TO_DATE(NULLIF(记录开始日期, ''), '%Y-%m-%d'),
        STR_TO_DATE(NULLIF(记录开始日期, ''), '%Y/%m/%d'),
        STR_TO_DATE(NULLIF(记录开始日期, ''), '%m/%d/%Y'),
        STR_TO_DATE(NULLIF(记录开始日期, ''), '%Y%m%d'),
        STR_TO_DATE(REPLACE(REPLACE(NULLIF(记录开始日期, ''), '年', '-'), '日', ''), '%Y-%m-%d')
      ) IS NULL;

-- 0.8 下游表候选人编码索引（第 3/4 部分与对账命令的性能前提；
--     2026-08-25 已执行，已存在时报 1061 重复索引错误，属预期可忽略）
CREATE INDEX idx_候选人编码 ON ee_interview (候选人编码);
CREATE INDEX idx_候选人编码 ON ee_train (候选人编码);
CREATE INDEX idx_候选人编码 ON ee_onjob (候选人编码);

-- ============================================================
-- 第 1 部分：存量搬迁（幂等：NOT EXISTS 防重插）
--   只搬状态头 + 邀约日期冗余副本；业务字段不搬（方案C）。
--   NULLIF 包装空串→NULL（适配 DATETIME 空值列）；
--   邀约日期经转换链归一化为 DATE；
--   GUID/UUID 均由数据库自动生成（INSERT 不含这两列：
--   GUID 自增主键，UUID 表达式默认值 UUID_TO_BIN(UUID(),1) 逐行生成，
--   搬迁行与页面新增行的 UUID 生成路径完全一致）；
--   当前阶段先置默认'邀约'，第 3/4 部分再回填。
-- ============================================================
START TRANSACTION;

INSERT INTO ee_application (
  候选人编码, 人员编码, 当前阶段, 邀约日期,
  开始操作时间, 结束操作时间,
  操作记录, 操作来源, 操作人员, 操作时间,
  删除标识, 有效标识
)
SELECT
  s.候选人编码, IFNULL(NULLIF(s.人员编码, ''), ''), '邀约',
  COALESCE(
    STR_TO_DATE(NULLIF(s.邀约日期, ''), '%Y-%m-%d'),
    STR_TO_DATE(NULLIF(s.邀约日期, ''), '%Y/%m/%d'),
    STR_TO_DATE(NULLIF(s.邀约日期, ''), '%m/%d/%Y'),
    STR_TO_DATE(NULLIF(s.邀约日期, ''), '%Y%m%d'),
    STR_TO_DATE(REPLACE(REPLACE(NULLIF(s.邀约日期, ''), '年', '-'), '日', ''), '%Y-%m-%d')
  ),
  NULLIF(s.开始操作时间, ''), NULLIF(s.结束操作时间, ''),
  IFNULL(s.操作记录, ''), IFNULL(s.操作来源, ''), IFNULL(s.操作人员, ''),
  NULLIF(s.操作时间, ''),
  IFNULL(s.删除标识, '0'), IFNULL(s.有效标识, '1')
FROM ee_store s
WHERE s.有效标识 = '1' AND s.删除标识 = '0'
  AND s.候选人编码 <> '' AND s.候选人编码 IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM ee_application a
                  WHERE a.候选人编码 = s.候选人编码);

COMMIT;

-- 1.1 邀约日期补齐（幂等：源行邀约日期补录后同步冗余副本）
UPDATE ee_application a
JOIN ee_store s ON s.候选人编码 = a.候选人编码
  AND s.有效标识 = '1' AND s.删除标识 = '0'
SET a.邀约日期 = COALESCE(
      STR_TO_DATE(NULLIF(s.邀约日期, ''), '%Y-%m-%d'),
      STR_TO_DATE(NULLIF(s.邀约日期, ''), '%Y/%m/%d'),
      STR_TO_DATE(NULLIF(s.邀约日期, ''), '%m/%d/%Y'),
      STR_TO_DATE(NULLIF(s.邀约日期, ''), '%Y%m%d'),
      STR_TO_DATE(REPLACE(REPLACE(NULLIF(s.邀约日期, ''), '年', '-'), '日', ''), '%Y-%m-%d')
    )
WHERE a.邀约日期 IS NULL
  AND COALESCE(
        STR_TO_DATE(NULLIF(s.邀约日期, ''), '%Y-%m-%d'),
        STR_TO_DATE(NULLIF(s.邀约日期, ''), '%Y/%m/%d'),
        STR_TO_DATE(NULLIF(s.邀约日期, ''), '%m/%d/%Y'),
        STR_TO_DATE(NULLIF(s.邀约日期, ''), '%Y%m%d'),
        STR_TO_DATE(REPLACE(REPLACE(NULLIF(s.邀约日期, ''), '年', '-'), '日', ''), '%Y-%m-%d')
      ) IS NOT NULL;

-- 1.2 零日期清理（幂等）：放宽严格模式执行时，"可解析但不存在"的
--     脏日期（如 '2024-04-31'）会落 '0000-00-00'，统一归 NULL，
--     与"全格式不可解析脏值落 NULL"口径一致（由 5.6 暴露人工修源）
UPDATE ee_application
SET 邀约日期 = NULL
WHERE 邀约日期 = '0000-00-00';

UPDATE ee_application
SET 面试日期 = NULL WHERE 面试日期 = '0000-00-00';

UPDATE ee_application
SET 参培日期 = NULL WHERE 参培日期 = '0000-00-00';

UPDATE ee_application
SET 入职日期 = NULL WHERE 入职日期 = '0000-00-00';

UPDATE ee_application
SET 终止日期 = NULL WHERE 终止日期 = '0000-00-00';

-- ============================================================
-- 第 2 部分：阶段时间戳回填（全流程时间线，DATE 归一化转换链同第 1 部分）
-- ============================================================

-- 2.1 面试日期 = 首次一次面试日期
UPDATE ee_application a
JOIN (
  SELECT 候选人编码, MIN(COALESCE(
      STR_TO_DATE(NULLIF(一次面试日期, ''), '%Y-%m-%d'),
      STR_TO_DATE(NULLIF(一次面试日期, ''), '%Y/%m/%d'),
      STR_TO_DATE(NULLIF(一次面试日期, ''), '%m/%d/%Y'),
      STR_TO_DATE(NULLIF(一次面试日期, ''), '%Y%m%d'),
      STR_TO_DATE(REPLACE(REPLACE(NULLIF(一次面试日期, ''), '年', '-'), '日', ''), '%Y-%m-%d')
    )) AS d
  FROM ee_interview
  WHERE 有效标识 = '1' AND 删除标识 = '0' AND 候选人编码 <> ''
  GROUP BY 候选人编码
) i ON i.候选人编码 = a.候选人编码
SET a.面试日期 = i.d
WHERE a.面试日期 IS NULL AND i.d IS NOT NULL;

-- 2.2 参培日期 = 首次培训开始日期
--     （目标列命名为 参培日期，区别于源列 培训开始日期，消除同名不同义）
UPDATE ee_application a
JOIN (
  SELECT 候选人编码, MIN(COALESCE(
      STR_TO_DATE(NULLIF(培训开始日期, ''), '%Y-%m-%d'),
      STR_TO_DATE(NULLIF(培训开始日期, ''), '%Y/%m/%d'),
      STR_TO_DATE(NULLIF(培训开始日期, ''), '%m/%d/%Y'),
      STR_TO_DATE(NULLIF(培训开始日期, ''), '%Y%m%d'),
      STR_TO_DATE(REPLACE(REPLACE(NULLIF(培训开始日期, ''), '年', '-'), '日', ''), '%Y-%m-%d')
    )) AS d
  FROM ee_train
  WHERE 有效标识 = '1' AND 删除标识 = '0' AND 候选人编码 <> ''
  GROUP BY 候选人编码
) t ON t.候选人编码 = a.候选人编码
SET a.参培日期 = t.d
WHERE a.参培日期 IS NULL AND t.d IS NOT NULL;

-- 2.3 入职日期 = 首条雇佣记录开始日期
UPDATE ee_application a
JOIN (
  SELECT 候选人编码, MIN(COALESCE(
      STR_TO_DATE(NULLIF(记录开始日期, ''), '%Y-%m-%d'),
      STR_TO_DATE(NULLIF(记录开始日期, ''), '%Y/%m/%d'),
      STR_TO_DATE(NULLIF(记录开始日期, ''), '%m/%d/%Y'),
      STR_TO_DATE(NULLIF(记录开始日期, ''), '%Y%m%d'),
      STR_TO_DATE(REPLACE(REPLACE(NULLIF(记录开始日期, ''), '年', '-'), '日', ''), '%Y-%m-%d')
    )) AS d
  FROM ee_onjob
  WHERE 有效标识 = '1' AND 删除标识 = '0' AND 候选人编码 <> ''
  GROUP BY 候选人编码
) o ON o.候选人编码 = a.候选人编码
SET a.入职日期 = o.d
WHERE a.入职日期 IS NULL AND o.d IS NOT NULL;

-- ============================================================
-- 第 3 部分：当前阶段回填（自下而上：先入职、再培训、后面试）
--   判阶依据 = 下游表是否存在该候选人编码的有效记录
-- ============================================================

-- 3.1 入职：ee_onjob 存在有效记录
UPDATE ee_application a
SET a.当前阶段 = '入职'
WHERE EXISTS (SELECT 1 FROM ee_onjob o
              WHERE o.候选人编码 = a.候选人编码
                AND o.有效标识 = '1' AND o.删除标识 = '0');

-- 3.2 培训：ee_train 存在有效记录（未入职）
UPDATE ee_application a
SET a.当前阶段 = '培训'
WHERE a.当前阶段 IN ('邀约', '面试')
  AND EXISTS (SELECT 1 FROM ee_train t
              WHERE t.候选人编码 = a.候选人编码
                AND t.有效标识 = '1' AND t.删除标识 = '0');

-- 3.3 面试：ee_interview 存在有效记录（未培训/未入职）
UPDATE ee_application a
SET a.当前阶段 = '面试'
WHERE a.当前阶段 = '邀约'
  AND EXISTS (SELECT 1 FROM ee_interview i
              WHERE i.候选人编码 = a.候选人编码
                AND i.有效标识 = '1' AND i.删除标识 = '0');

-- ============================================================
-- 第 4 部分：终止判定（保守规则：仅落定有明确业务信号的行，
--            模糊场景保留末阶段，由对账脚本"长期在途"清单人工复核）
--   注意：方案C 下 面试信息 读 ee_store（JOIN 获取）
-- ============================================================

-- 4.1 邀约阶段终止：明确拒绝面试且从未进入面试
UPDATE ee_application a
JOIN ee_store s ON s.候选人编码 = a.候选人编码
  AND s.有效标识 = '1' AND s.删除标识 = '0'
SET a.当前阶段 = '终止', a.终止原因 = '邀约拒绝'
WHERE a.当前阶段 = '邀约'
  AND IFNULL(s.面试信息, '') = '拒绝'
  AND NOT EXISTS (SELECT 1 FROM ee_interview i
                  WHERE i.候选人编码 = a.候选人编码
                    AND i.有效标识 = '1' AND i.删除标识 = '0');

-- 4.2 面试阶段终止：一次面试未通过且未进入培训
--     （InvitationApi::transfer 仅在 面试结果=通过/未通过 时落 ee_interview，
--       故 一次面试结果='未通过' 值域可靠）
UPDATE ee_application a
SET a.当前阶段 = '终止', a.终止原因 = '面试未通过',
    a.终止日期 = (
      SELECT MAX(COALESCE(
          STR_TO_DATE(NULLIF(i.一次面试日期, ''), '%Y-%m-%d'),
          STR_TO_DATE(NULLIF(i.一次面试日期, ''), '%Y/%m/%d'),
          STR_TO_DATE(NULLIF(i.一次面试日期, ''), '%m/%d/%Y'),
          STR_TO_DATE(NULLIF(i.一次面试日期, ''), '%Y%m%d'),
          STR_TO_DATE(REPLACE(REPLACE(NULLIF(i.一次面试日期, ''), '年', '-'), '日', ''), '%Y-%m-%d')
        ))
      FROM ee_interview i
      WHERE i.候选人编码 = a.候选人编码
        AND i.有效标识 = '1' AND i.删除标识 = '0'
    )
WHERE a.当前阶段 = '面试'
  AND EXISTS (SELECT 1 FROM ee_interview i
              WHERE i.候选人编码 = a.候选人编码
                AND i.有效标识 = '1' AND i.删除标识 = '0'
                AND IFNULL(i.一次面试结果, '') = '未通过')
  AND NOT EXISTS (SELECT 1 FROM ee_train t
                  WHERE t.候选人编码 = a.候选人编码
                    AND t.有效标识 = '1' AND t.删除标识 = '0');

-- 4.3 培训阶段终止：培训离开（培训离开日期/原因非空，仅 TrainApi
--     离开分支写入，值域可靠）且未入职
UPDATE ee_application a
SET a.当前阶段 = '终止', a.终止原因 = '培训离开',
    a.终止日期 = (
      SELECT MAX(COALESCE(
          STR_TO_DATE(NULLIF(t.培训离开日期, ''), '%Y-%m-%d'),
          STR_TO_DATE(NULLIF(t.培训离开日期, ''), '%Y/%m/%d'),
          STR_TO_DATE(NULLIF(t.培训离开日期, ''), '%m/%d/%Y'),
          STR_TO_DATE(NULLIF(t.培训离开日期, ''), '%Y%m%d'),
          STR_TO_DATE(REPLACE(REPLACE(NULLIF(t.培训离开日期, ''), '年', '-'), '日', ''), '%Y-%m-%d')
        ))
      FROM ee_train t
      WHERE t.候选人编码 = a.候选人编码
        AND t.有效标识 = '1' AND t.删除标识 = '0'
    )
WHERE a.当前阶段 = '培训'
  AND EXISTS (SELECT 1 FROM ee_train t
              WHERE t.候选人编码 = a.候选人编码
                AND t.有效标识 = '1' AND t.删除标识 = '0'
                AND (IFNULL(t.培训离开日期, '') <> '' OR IFNULL(t.培训离开原因, '') <> ''))
  AND NOT EXISTS (SELECT 1 FROM ee_onjob o
                  WHERE o.候选人编码 = a.候选人编码
                    AND o.有效标识 = '1' AND o.删除标识 = '0');

-- ============================================================
-- 第 5 部分：迁移后核验（人工核对，不写库）
-- ============================================================

-- 5.1 行数对账（两数应相等；差额 = 0.3 暴露的空码行数）
SELECT
  (SELECT COUNT(*) FROM ee_store
    WHERE 有效标识 = '1' AND 删除标识 = '0' AND 候选人编码 <> '') AS 源活跃行数,
  (SELECT COUNT(*) FROM ee_application)                              AS 实例行数;

-- 5.2 阶段分布（人工 sanity check：入职+培训+面试+邀约+终止 ≈ 实例总数）
SELECT 当前阶段, COUNT(*) AS c
FROM ee_application
GROUP BY 当前阶段
ORDER BY c DESC;

-- 5.3 主档链接缺失（应 = 0.4 的数字，>0 需补跑 person:migrate 后重执本段 UPDATE）
SELECT COUNT(*) AS 无主档链接实例数
FROM ee_application
WHERE 人员编码 = '' OR 人员编码 IS NULL;

-- 5.4 邀约日期冗余副本一致性（应返回 0 行：实例与 ee_store 活跃行不一致；
--     源值经转换链归一化后与 DATE 副本比对，多格式源值不算差异）
SELECT a.候选人编码
FROM ee_application a
JOIN ee_store s ON s.候选人编码 = a.候选人编码
  AND s.有效标识 = '1' AND s.删除标识 = '0'
WHERE NOT (a.邀约日期 <=> COALESCE(
      STR_TO_DATE(NULLIF(s.邀约日期, ''), '%Y-%m-%d'),
      STR_TO_DATE(NULLIF(s.邀约日期, ''), '%Y/%m/%d'),
      STR_TO_DATE(NULLIF(s.邀约日期, ''), '%m/%d/%Y'),
      STR_TO_DATE(NULLIF(s.邀约日期, ''), '%Y%m%d'),
      STR_TO_DATE(REPLACE(REPLACE(NULLIF(s.邀约日期, ''), '年', '-'), '日', ''), '%Y-%m-%d')
    ));

-- 5.5 阶段时间戳覆盖率（入职实例应有入职日期等；
--     覆盖缺口主要来自 0.7 暴露的不可解析脏日期行）
SELECT
  SUM(当前阶段 IN ('面试','培训','入职','终止'))                    AS 应有面试日期数,
  SUM(CASE WHEN 当前阶段 IN ('面试','培训','入职','终止')
            AND 面试日期 IS NOT NULL THEN 1 ELSE 0 END)             AS 实有面试日期数,
  SUM(当前阶段 IN ('培训','入职'))                                   AS 应有参培日期数,
  SUM(CASE WHEN 当前阶段 IN ('培训','入职')
            AND 参培日期 IS NOT NULL THEN 1 ELSE 0 END)              AS 实有参培日期数,
  SUM(当前阶段 = '入职')                                             AS 应有入职日期数,
  SUM(CASE WHEN 当前阶段 = '入职' AND 入职日期 IS NOT NULL
            THEN 1 ELSE 0 END)                                      AS 实有入职日期数
FROM ee_application;

-- 5.6 时间线字段 NULL 但源值非空的实例（即转换链无法解析的脏日期落 NULL 的行，
--     应 ≈ 0.7 的脏值行数；人工修正源值后重跑第 1.1/2 部分补齐）
SELECT a.候选人编码, s.邀约日期 AS 邀约日期原值
FROM ee_application a
JOIN ee_store s ON s.候选人编码 = a.候选人编码
  AND s.有效标识 = '1' AND s.删除标识 = '0'
WHERE a.邀约日期 IS NULL
  AND IFNULL(s.邀约日期, '') <> '';

-- ============================================================
-- 执行记录
--   2026-08-25 首次执行完毕：
--     第 1 部分新增实例 5382 行（源活跃行全覆盖，缺失/多余均 0）
--     第 2 部分回填：面试日期 3524 / 参培日期 1969 / 入职日期 1335
--     第 3 部分回填：入职 1335 / 培训 634 / 面试 1555
--     第 4 部分终止：邀约拒绝 0 / 面试未通过 250 / 培训离开 537
--     阶段分布：邀约 1858 / 面试 1305 / 培训 97 / 入职 1335 / 终止 787
--     核验：5.1~5.3 全过；5.4 剩 1 行（测试行 C00000000001，
--           源值 '2024-04-31' 不可解析，副本已归 NULL）；
--           5.5 = 81（源行人员编码缺失，person:migrate 补跑后重执可清零）；
--           5.6 = 4（测试行 C00000000002~05，源值 '2024-04-32'~'35'）
--     application:reconcile 复核：①②④ 全部 0；
--           ⑤ 下游孤儿 23005（0.6 已知的存量治理项，阶段②处理）；
--           ⑥ = 81；⑦ 长期在途 3209（仅提示）
--     过程修复（已回写本文件）：执行前提 1（会话放宽严格模式）、
--           0.8（下游三表 idx_候选人编码）、1.2（零日期清理）
-- ============================================================
