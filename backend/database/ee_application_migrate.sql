-- ============================================================
-- ee_application 存量搬迁 SQL - 阶段①
--
-- 前置条件：
--   1. 已执行 ee_application_tables.sql 建表
--   2. 已执行 php spark candidate:backfill --execute（候选人编码已回填）
--   3. 已执行 php spark person:migrate --execute（人员编码已回填）
--
-- 执行方式：按部分顺序执行；第 0 部分为人工核对检查点，必须逐条
--           核对结果后再继续。第 1~5 部分全部幂等，可安全重跑。
--
-- 搬迁口径：
--   - 只搬 ee_store 当前有效行（有效标识='1' AND 删除标识='0'），
--     SCD2 历史版本行（有效标识='0'）与软删行留在 ee_store 原地归档
--   - 审计字段原样保留（快照保真），搬迁事实通过本脚本执行记录追溯
--   - 空候选人编码行无法成为实例（主键），由 0.3 预检暴露后先回填
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

-- 0.2 活跃行重复候选人编码（必须返回 0 行，否则搬迁主键冲突）
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
--     阶段②/④建幂等唯一约束，见 ee_application_tables.sql 附注）
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

-- ============================================================
-- 第 1 部分：存量搬迁（幂等：NOT EXISTS 防重插）
--   只搬当前有效行；NULLIF 包装空串→NULL（适配日期/DATETIME 空值列）；
--   GUID 由 UUID() 生成；当前阶段先置默认'邀约'，第 4/5 部分再回填。
-- ============================================================
START TRANSACTION;

INSERT INTO ee_application (
  GUID, 候选人编码, 人员编码, 当前阶段,
  邀约业务, 邀约岗位, 邀约日期, 邀约次数, 面试信息,
  招聘渠道, 渠道类型, 渠道名称, 员工类别, 属地,
  姓名, 身份证号, 手机号码,
  操作记录, 操作来源, 操作人员,
  操作时间, 开始操作时间, 结束操作时间,
  校验标识, 删除标识, 有效标识
)
SELECT
  UUID(), s.候选人编码, IFNULL(NULLIF(s.人员编码, ''), ''), '邀约',
  IFNULL(s.邀约业务, ''), IFNULL(s.邀约岗位, ''),
  NULLIF(s.邀约日期, ''), IFNULL(s.邀约次数, ''), IFNULL(s.面试信息, ''),
  IFNULL(s.招聘渠道, ''), IFNULL(s.渠道类型, ''), IFNULL(s.渠道名称, ''),
  CASE
    WHEN IFNULL(s.招聘渠道, '') = '校招'  THEN '未毕业学生'
    WHEN IFNULL(s.招聘渠道, '') <> ''     THEN '合同制员工'
    ELSE ''
  END,
  IFNULL(s.属地, ''),
  IFNULL(s.姓名, ''), NULLIF(s.身份证号, ''), IFNULL(s.手机号码, ''),
  IFNULL(s.操作记录, ''), IFNULL(s.操作来源, ''), IFNULL(s.操作人员, ''),
  NULLIF(s.操作时间, ''), NULLIF(s.开始操作时间, ''), NULLIF(s.结束操作时间, ''),
  IFNULL(s.校验标识, '0'), IFNULL(s.删除标识, '0'), IFNULL(s.有效标识, '1')
FROM ee_store s
WHERE s.有效标识 = '1' AND s.删除标识 = '0'
  AND s.候选人编码 <> '' AND s.候选人编码 IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM ee_application a
                  WHERE a.候选人编码 = s.候选人编码);

COMMIT;

-- ============================================================
-- 第 2 部分：实例字段补全
-- ============================================================

-- 2.1 实习结束日期：自 ee_interview 承接（取该实例名下最新非空值；
--     ee_store 无此列，ee_onjob 转入时亦从 ee_interview 取值，口径一致）
UPDATE ee_application a
JOIN (
  SELECT 候选人编码, MAX(NULLIF(实习结束日期, '')) AS d
  FROM ee_interview
  WHERE 有效标识 = '1' AND 删除标识 = '0' AND 候选人编码 <> ''
  GROUP BY 候选人编码
) i ON i.候选人编码 = a.候选人编码
SET a.实习结束日期 = i.d
WHERE (a.实习结束日期 = '' OR a.实习结束日期 IS NULL)
  AND i.d IS NOT NULL;

-- ============================================================
-- 第 3 部分：阶段时间戳回填（全流程时间线）
-- ============================================================

-- 3.1 面试日期 = 首次一次面试日期
UPDATE ee_application a
JOIN (
  SELECT 候选人编码, MIN(NULLIF(一次面试日期, '')) AS d
  FROM ee_interview
  WHERE 有效标识 = '1' AND 删除标识 = '0' AND 候选人编码 <> ''
  GROUP BY 候选人编码
) i ON i.候选人编码 = a.候选人编码
SET a.面试日期 = i.d
WHERE (a.面试日期 = '' OR a.面试日期 IS NULL) AND i.d IS NOT NULL;

-- 3.2 培训开始日期 = 首次培训开始日期
UPDATE ee_application a
JOIN (
  SELECT 候选人编码, MIN(NULLIF(培训开始日期, '')) AS d
  FROM ee_train
  WHERE 有效标识 = '1' AND 删除标识 = '0' AND 候选人编码 <> ''
  GROUP BY 候选人编码
) t ON t.候选人编码 = a.候选人编码
SET a.培训开始日期 = t.d
WHERE (a.培训开始日期 = '' OR a.培训开始日期 IS NULL) AND t.d IS NOT NULL;

-- 3.3 入职日期 = 首条雇佣记录开始日期
UPDATE ee_application a
JOIN (
  SELECT 候选人编码, MIN(NULLIF(记录开始日期, '')) AS d
  FROM ee_onjob
  WHERE 有效标识 = '1' AND 删除标识 = '0' AND 候选人编码 <> ''
  GROUP BY 候选人编码
) o ON o.候选人编码 = a.候选人编码
SET a.入职日期 = o.d
WHERE (a.入职日期 = '' OR a.入职日期 IS NULL) AND o.d IS NOT NULL;

-- ============================================================
-- 第 4 部分：当前阶段回填（自下而上：先入职、再培训、后面试）
--   判阶依据 = 下游表是否存在该候选人编码的有效记录
-- ============================================================

-- 4.1 入职：ee_onjob 存在有效记录
UPDATE ee_application a
SET a.当前阶段 = '入职'
WHERE EXISTS (SELECT 1 FROM ee_onjob o
              WHERE o.候选人编码 = a.候选人编码
                AND o.有效标识 = '1' AND o.删除标识 = '0');

-- 4.2 培训：ee_train 存在有效记录（未入职）
UPDATE ee_application a
SET a.当前阶段 = '培训'
WHERE a.当前阶段 IN ('邀约', '面试')
  AND EXISTS (SELECT 1 FROM ee_train t
              WHERE t.候选人编码 = a.候选人编码
                AND t.有效标识 = '1' AND t.删除标识 = '0');

-- 4.3 面试：ee_interview 存在有效记录（未培训/未入职）
UPDATE ee_application a
SET a.当前阶段 = '面试'
WHERE a.当前阶段 = '邀约'
  AND EXISTS (SELECT 1 FROM ee_interview i
              WHERE i.候选人编码 = a.候选人编码
                AND i.有效标识 = '1' AND i.删除标识 = '0');

-- ============================================================
-- 第 5 部分：终止判定（保守规则：仅落定有明确业务信号的行，
--            模糊场景保留末阶段，由对账脚本"长期在途"清单人工复核）
-- ============================================================

-- 5.1 邀约阶段终止：明确拒绝面试且从未进入面试
UPDATE ee_application a
SET a.当前阶段 = '终止', a.终止原因 = '邀约拒绝'
WHERE a.当前阶段 = '邀约'
  AND IFNULL(a.面试信息, '') = '拒绝'
  AND NOT EXISTS (SELECT 1 FROM ee_interview i
                  WHERE i.候选人编码 = a.候选人编码
                    AND i.有效标识 = '1' AND i.删除标识 = '0');

-- 5.2 面试阶段终止：一次面试未通过且未进入培训
--     （InvitationApi::transfer 仅在 面试结果=通过/未通过 时落 ee_interview，
--       故 一次面试结果='未通过' 值域可靠）
UPDATE ee_application a
SET a.当前阶段 = '终止', a.终止原因 = '面试未通过',
    a.终止日期 = (
      SELECT MAX(NULLIF(i.一次面试日期, ''))
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

-- 5.3 培训阶段终止：培训离开（培训离开日期/原因非空，仅 TrainApi
--     离开分支写入，值域可靠）且未入职
UPDATE ee_application a
SET a.当前阶段 = '终止', a.终止原因 = '培训离开',
    a.终止日期 = (
      SELECT MAX(NULLIF(t.培训离开日期, ''))
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
-- 第 6 部分：迁移后核验（人工核对，不写库）
-- ============================================================

-- 6.1 行数对账（两数应相等；差额 = 0.3 暴露的空码行数）
SELECT
  (SELECT COUNT(*) FROM ee_store
    WHERE 有效标识 = '1' AND 删除标识 = '0' AND 候选人编码 <> '') AS 源活跃行数,
  (SELECT COUNT(*) FROM ee_application)                              AS 实例行数;

-- 6.2 阶段分布（人工 sanity check：入职+培训+面试+邀约+终止 ≈ 实例总数）
SELECT 当前阶段, COUNT(*) AS c
FROM ee_application
GROUP BY 当前阶段
ORDER BY c DESC;

-- 6.3 主档链接缺失（应 = 0.4 的数字，>0 需补跑 person:migrate 后重执本段 UPDATE）
SELECT COUNT(*) AS 无主档链接实例数
FROM ee_application
WHERE 人员编码 = '' OR 人员编码 IS NULL;

-- 6.4 阶段时间戳覆盖率（入职实例应有入职日期等）
SELECT
  SUM(当前阶段 IN ('面试','培训','入职','终止'))                    AS 应有面试日期数,
  SUM(CASE WHEN 当前阶段 IN ('面试','培训','入职','终止')
            AND 面试日期 IS NOT NULL THEN 1 ELSE 0 END)             AS 实有面试日期数,
  SUM(当前阶段 IN ('培训','入职'))                                   AS 应有培训日期数,
  SUM(CASE WHEN 当前阶段 IN ('培训','入职')
            AND 培训开始日期 IS NOT NULL THEN 1 ELSE 0 END)          AS 实有培训日期数,
  SUM(当前阶段 = '入职')                                             AS 应有入职日期数,
  SUM(CASE WHEN 当前阶段 = '入职' AND 入职日期 IS NOT NULL
            THEN 1 ELSE 0 END)                                      AS 实有入职日期数
FROM ee_application;
