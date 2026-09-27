-- =============================================================================
-- A6 合同编号存储过程发号（防并发重号）
-- 创建时间：2026-09-26；经 backend/writable/ 临时脚本执行后存档
-- =============================================================================
-- 问题背景：
--   ContractService::generateContractNo 原为"查当日最大编号+1"写法：
--   并发创建合同时多个请求读到同一最大值，各自 +1 生成相同编号，
--   在主表唯一索引 uk_合同编号 上表现为第二个插入直接失败（重复键冲突）。
--
-- 发号机制（与 sp_生成候选人编码 同一模式）：
--   1. def_seq 按业务日期分桶（序列名='合同编号'，主键=序列名+日期）
--   2. INSERT IGNORE 确保当日序列行存在；当日行缺失时以主表当日既有最大序号播种
--      （序号自第 11 位起解析，兼容历史 3 位序号 HT20260501001 与现行 4 位序号
--       HT202607240001 两种格式，保证续号连续）
--   3. UPDATE def_seq SET 当前值 = LAST_INSERT_ID(当前值 + p_count)
--      → UPDATE 单语句原子（InnoDB 行锁串行化），并发各自等待
--      → LAST_INSERT_ID(expr) 写入连接级变量，各连接独立不串扰
--   4. 主表唯一索引 uk_合同编号（已存在）为最终防线
--
-- 调用约定（PHP 侧）：
--   必须走 Mcommon::getDb()->query() 直连执行 CALL；
--   Mcommon::select() 有请求级结果缓存，同请求第二次发号会拿到缓存而不执行
--   存储过程，导致重号（与 CandidateCodeService::generateOne 同理）
-- =============================================================================

DROP PROCEDURE IF EXISTS `sp_生成合同编号`;
DELIMITER $$
CREATE PROCEDURE `sp_生成合同编号`(
  IN  p_count      INT,             -- 本次需要的编号数量（当前场景=1）
  IN  p_date       DATE,           -- 业务日期（合同创建为当天）；NULL 则用今天
  OUT o_start_seq  BIGINT,         -- 返回起始序号（含）
  OUT o_prefix     VARCHAR(20)     -- 返回前缀，如 HT20260926
)
BEGIN
  DECLARE v_date    DATE;
  DECLARE v_prefix  VARCHAR(20);
  DECLARE v_seed    BIGINT;

  IF p_count <= 0 THEN
    SET o_start_seq = 0;
    SET o_prefix = '';
  ELSE
    SET v_date   = IFNULL(p_date, CURDATE());
    SET v_prefix = CONCAT('HT', DATE_FORMAT(v_date, '%Y%m%d'));

    -- 播种：当日序列行缺失时，以主表当日既有最大序号初始化
    --   （序号自第 11 位起：前缀 HT + 8 位日期共 10 位，兼容历史 3/4 位序号）
    SET v_seed = (
      SELECT COALESCE(MAX(CAST(SUBSTRING(`合同编号`, 11) AS UNSIGNED)), 0)
      FROM `def_contract_master_new`
      WHERE `合同编号` LIKE CONCAT(v_prefix, '%')
    );
    INSERT IGNORE INTO `def_seq`(`序列名`, `日期`, `当前值`)
    VALUES ('合同编号', v_date, v_seed);

    -- 原子自增 + 设置连接级 LAST_INSERT_ID（行锁串行化，并发各自等待）
    UPDATE `def_seq`
      SET `当前值` = LAST_INSERT_ID(`当前值` + p_count)
      WHERE `序列名` = '合同编号' AND `日期` = v_date;

    -- 读自己连接的 LAST_INSERT_ID，起始序号 = max - count + 1
    SET o_start_seq = LAST_INSERT_ID() - p_count + 1;
    SET o_prefix = v_prefix;
  END IF;
END$$
DELIMITER ;

-- =============================================================================
-- 验证（事务内调用后回滚，不消耗序列号、不留 def_seq 行）
-- =============================================================================
-- START TRANSACTION;
-- SET @seq = 0, @prefix = '';
-- CALL sp_生成合同编号(1, NULL, @seq, @prefix);
-- SELECT @prefix AS 前缀, @seq AS 起始序号;
-- ROLLBACK;
-- =============================================================================
-- 唯一索引说明：def_contract_master_new 已存在 uk_合同编号（唯一，含软删行），
-- 编号只增不复用，软删行保留编号不会与新号冲突，无需额外 DDL。
-- =============================================================================
