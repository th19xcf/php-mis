-- ============================================================================
-- 阶段②C-1：候选人编码活跃行唯一约束（函数唯一索引）
-- 适用表：ee_store / ee_interview / ee_train / ee_onjob
-- ============================================================================
-- 目标：
--   1. 流转幂等的数据库级兜底——同一候选人编码在同一阶段表至多一条活跃记录，
--      重复流转 / 重复导入 / 并发提交被硬拦截（errno 1062 Duplicate entry）
--   2. ee_store 作为流程链源头首次获得唯一性保护（此前仅有 PRIMARY KEY(GUID)，
--      候选人编码列完全无索引，导入并发下无任何防重）
--
-- 机制（CASE 表达式索引）：
--   仅 有效标识='1' AND 删除标识='0' 的行参与唯一判定；
--   失效 / 软删 / 空码 / NULL 一律落 NULL（MySQL 唯一索引允许多 NULL，不参与判重）
--
-- 与 SCD2 版本机制的兼容性（8.0.22 临时表实测 6 场景全通过，2026-08-26）：
--   ① 同码版本对（1活跃+1失效）共存              PASS
--   ② 活跃行撞码被拦截（errno=1062）             PASS
--   ③ 空串×2 + NULL×1 共存（44 行空码不受阻）   PASS
--   ④ 软删行（删除标识='1'）同码                 PASS
--   ⑤ SCD2 全流程（UPDATE旧行失效→INSERT新行同码）PASS ← RecordEditService 顺序
--   ⑥ 多条失效版本堆积（onjob 1.17 万行历史）    PASS
--
-- 执行注意：
--   * 函数索引内部实现为隐藏虚拟列，ALTER 走 COPY 算法：执行期间该表读不阻塞、
--     写阻塞。各表 ≤1.9 万行，单表秒级完成；建议低峰逐表执行，勿并发
--   * 幂等性：重复执行报 "Duplicate key name 'uk_候选人编码_活跃'" 属预期，忽略即可
--   * 环境依赖：MySQL >= 8.0.13（函数索引支持）；实测服务器 8.0.22
--
-- 配套代码缺陷（2026-08-26 勘察发现，需另行修复）：
--   EmployeeService::updateEmployee 的 SCD2 为"先 INSERT 新行、后 UPDATE 旧行失效"
--   顺序（与 RecordEditService 的正确顺序相反），且 INSERT 列缺失 候选人编码/
--   人员编码（新版本行断链）。当前因新行码为 NULL 恰好不触发本索引；修复时须
--   同步调整为"先 UPDATE 后 INSERT"并补齐两列，否则补列后每次编辑将撞本索引
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 第 0 部分：预检（以下查询必须全部返回 0 才可执行第 1 部分）
-- ---------------------------------------------------------------------------

-- 0.1 活跃行重复码（2026-08-26 实测：四表全 0）
SELECT 'ee_store' AS 表名, COUNT(*) AS 活跃重复组数 FROM (
    SELECT 候选人编码 FROM ee_store
    WHERE 有效标识='1' AND 删除标识='0' AND IFNULL(候选人编码,'')<>''
    GROUP BY 候选人编码 HAVING COUNT(*)>1
) x
UNION ALL
SELECT 'ee_interview', COUNT(*) FROM (
    SELECT 候选人编码 FROM ee_interview
    WHERE 有效标识='1' AND 删除标识='0' AND IFNULL(候选人编码,'')<>''
    GROUP BY 候选人编码 HAVING COUNT(*)>1
) x
UNION ALL
SELECT 'ee_train', COUNT(*) FROM (
    SELECT 候选人编码 FROM ee_train
    WHERE 有效标识='1' AND 删除标识='0' AND IFNULL(候选人编码,'')<>''
    GROUP BY 候选人编码 HAVING COUNT(*)>1
) x
UNION ALL
SELECT 'ee_onjob', COUNT(*) FROM (
    SELECT 候选人编码 FROM ee_onjob
    WHERE 有效标识='1' AND 删除标识='0' AND IFNULL(候选人编码,'')<>''
    GROUP BY 候选人编码 HAVING COUNT(*)>1
) x;

-- 0.2 流转一致性（活跃下游行不得超前实例阶段；防状态机放行后 INSERT 撞键）
--     2026-08-26 实测：三表全 0
SELECT 'interview超前(实例仍邀约)' AS 检查项, COUNT(*) AS 行数 FROM ee_interview d
JOIN ee_application a ON a.候选人编码=d.候选人编码
WHERE d.有效标识='1' AND d.删除标识='0' AND a.当前阶段='邀约'
UNION ALL
SELECT 'train超前(实例未到培训)', COUNT(*) FROM ee_train d
JOIN ee_application a ON a.候选人编码=d.候选人编码
WHERE d.有效标识='1' AND d.删除标识='0' AND a.当前阶段 IN ('邀约','面试')
UNION ALL
SELECT 'onjob超前(实例未到入职)', COUNT(*) FROM ee_onjob d
JOIN ee_application a ON a.候选人编码=d.候选人编码
WHERE d.有效标识='1' AND d.删除标识='0' AND a.当前阶段 IN ('邀约','面试','培训');

-- ---------------------------------------------------------------------------
-- 第 1 部分：建函数唯一索引（低峰期逐表执行）
-- ---------------------------------------------------------------------------

-- 1.1 ee_store（流程链源头，此前无任何码索引）
ALTER TABLE ee_store
    ADD UNIQUE KEY uk_候选人编码_活跃 (
        (CASE WHEN `有效标识`='1' AND `删除标识`='0' THEN NULLIF(`候选人编码`,'') END)
    );

-- 1.2 ee_interview
ALTER TABLE ee_interview
    ADD UNIQUE KEY uk_候选人编码_活跃 (
        (CASE WHEN `有效标识`='1' AND `删除标识`='0' THEN NULLIF(`候选人编码`,'') END)
    );

-- 1.3 ee_train
ALTER TABLE ee_train
    ADD UNIQUE KEY uk_候选人编码_活跃 (
        (CASE WHEN `有效标识`='1' AND `删除标识`='0' THEN NULLIF(`候选人编码`,'') END)
    );

-- 1.4 ee_onjob
ALTER TABLE ee_onjob
    ADD UNIQUE KEY uk_候选人编码_活跃 (
        (CASE WHEN `有效标识`='1' AND `删除标识`='0' THEN NULLIF(`候选人编码`,'') END)
    );

-- ---------------------------------------------------------------------------
-- 第 2 部分：核验
-- ---------------------------------------------------------------------------

-- 2.1 索引就位确认（应返回 4 行，NON_UNIQUE 均为 0）
SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=DATABASE() AND INDEX_NAME='uk_候选人编码_活跃'
ORDER BY TABLE_NAME;

-- 2.2 撞码拦截自测（可选，需手工执行并观察报错）
--     预期：Duplicate entry 'Cxxxxxxxxxxxx' for key 'ee_interview.uk_候选人编码_活跃'
--     插入被数据库拒绝，无需回滚；确认报错后即通过
-- INSERT INTO ee_interview (候选人编码, 有效标识, 删除标识, 姓名)
-- SELECT 候选人编码, '1', '0', 姓名 FROM ee_interview
-- WHERE 有效标识='1' AND 删除标识='0' AND IFNULL(候选人编码,'')<>''
-- LIMIT 1;

-- 2.3 SCD2 编辑回归（可选，页面操作）
--     工作台编辑任一 interview/train/onjob 记录并保存 → 应正常成功
--     （版本对：旧行转失效 + 新行接管候选人编码，场景⑤已实测）
