-- ============================================================
-- 候选人编码生成 - 混合方案（LAST_INSERT_ID 防并发 + 按业务日期分桶）
--
-- 设计要点：
--   1. 编码格式：C + YYYYMMDD + 3位顺序号，示例 C20260818001
--   2. 日期来源：ee_store.邀约日期（业务日期，非录入日期）
--      历史数据录入/导入不及时时，编码日期与业务日期一致，时序正确
--   3. 并发安全：用 LAST_INSERT_ID(expr) 技巧，连接级变量天然隔离
--      多人同时新增/导入不重号（UPDATE 单语句原子 + 行锁串行化）
--   4. 失败不回收：事务回滚时 def_seq 已自增不回退，编码不重用保证可追溯
--
-- 关联链：ee_store.候选人编码 → ee_interview.候选人编码 → ee_train.初始编码 → ee_onjob.培训编码
-- 两条入库路径共用同一发号核心：
--   1. 页面新增（单条）：InvitationApi::add() 调用 generateCandidateCode(1, 邀约日期)
--   2. Excel 导入（批量）：def_import_config.前处理模块 = sp_邀约_导入前处理($源表, @out)
-- ============================================================

-- ============================================================
-- 第 1 部分：字段增补（如尚未存在）
-- ============================================================

-- ee_store 增加 候选人编码（流程实例级关联键源头）
ALTER TABLE `ee_store`
  ADD COLUMN `候选人编码` VARCHAR(14) NOT NULL DEFAULT ''
    COMMENT '流程实例级关联键源头（邀约新增时按邀约日期生成，贯穿面试→培训→主档）' AFTER `GUID`,
  ADD UNIQUE KEY `uk_候选人编码` (`候选人编码`),
  ADD INDEX `idx_候选人编码` (`候选人编码`);

-- ee_interview 增加 候选人编码（承接 ee_store.候选人编码，跨表串联）
ALTER TABLE `ee_interview`
  ADD COLUMN `候选人编码` VARCHAR(14) NOT NULL DEFAULT ''
    COMMENT '承接 ee_store.候选人编码，流程实例级关联键' AFTER `GUID`,
  ADD INDEX `idx_候选人编码` (`候选人编码`);

-- ee_train 增加 初始编码（承接 ee_interview.候选人编码，流程实例级关联键延续）
ALTER TABLE `ee_train`
  ADD COLUMN `初始编码` VARCHAR(14) NOT NULL DEFAULT ''
    COMMENT '承接 ee_interview.候选人编码，流程实例级关联键（贯穿至 ee_onjob.培训编码）' AFTER `GUID`,
  ADD INDEX `idx_初始编码` (`初始编码`);

-- ee_onjob 增加 培训编码（承接 ee_train.初始编码，反查来源培训记录）
ALTER TABLE `ee_onjob`
  ADD COLUMN `培训编码` VARCHAR(14) NOT NULL DEFAULT ''
    COMMENT '承接 ee_train.初始编码，反查来源培训记录' AFTER `GUID`,
  ADD INDEX `idx_培训编码` (`培训编码`);

-- ============================================================
-- 第 2 部分：通用发号序列表（按业务日期分桶）
-- ============================================================
CREATE TABLE IF NOT EXISTS `def_seq` (
  `序列名` VARCHAR(50) NOT NULL COMMENT '业务序列名，如 候选人编码/初始编码',
  `日期` DATE NOT NULL COMMENT '业务日期（按邀约日期分桶发号，每业务日一行）',
  `当前值` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '该业务日已发放的最大序号',
  `更新时间` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`序列名`, `日期`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='通用发号序列表（按业务日期分桶 + LAST_INSERT_ID 防并发）';

-- ============================================================
-- 第 3 部分：发号核心存储过程（页面新增 + 导入前处理 都调用）
--
-- 防并发原理（LAST_INSERT_ID 技巧）：
--   UPDATE def_seq SET 当前值 = LAST_INSERT_ID(当前值 + p_count)
--     → UPDATE 是单语句原子（InnoDB 行锁串行化）
--     → LAST_INSERT_ID(expr) 写入连接级变量，各连接独立，不跨连接串扰
--   SET o_start_seq = LAST_INSERT_ID() - p_count + 1
--     → 读自己连接的变量，即使其他连接已进一步自增也不影响
-- ============================================================
DROP PROCEDURE IF EXISTS `sp_生成候选人编码`;
DELIMITER $$
CREATE PROCEDURE `sp_生成候选人编码`(
  IN  p_count      INT,             -- 本次需要的编码数量（页面新增=1，批量导入=N）
  IN  p_date        DATE,            -- 业务日期（ee_store.邀约日期）；NULL 则用今天
  OUT o_start_seq  BIGINT,          -- 返回起始序号（含）
  OUT o_prefix     VARCHAR(20)      -- 返回前缀，如 C20260818
)
BEGIN
  DECLARE v_date DATE;

  IF p_count <= 0 THEN
    SET o_start_seq = 0;
    SET o_prefix = '';
  ELSE
    SET v_date = IFNULL(p_date, CURDATE());

    -- 1. 确保该业务日期的序列行存在（不存在则插入 0，存在则忽略）
    INSERT IGNORE INTO `def_seq`(`序列名`, `日期`, `当前值`)
    VALUES ('候选人编码', v_date, 0);

    -- 2. 原子自增 + 设置连接级 LAST_INSERT_ID
    --    UPDATE 是单语句原子（InnoDB 行锁串行化），多人并发时各自等待
    --    LAST_INSERT_ID(expr) 写入当前连接的变量，其他连接读不到也影响不了
    UPDATE `def_seq`
      SET `当前值` = LAST_INSERT_ID(`当前值` + p_count)
      WHERE `序列名` = '候选人编码' AND `日期` = v_date;

    -- 3. 读自己连接的 LAST_INSERT_ID（连接级变量，autocommit 模式也安全）
    --    返回自增后的最大值，起始序号 = max - count + 1
    SET o_start_seq = LAST_INSERT_ID() - p_count + 1;
    SET o_prefix = CONCAT('C', DATE_FORMAT(v_date, '%Y%m%d'));
  END IF;
END$$
DELIMITER ;

-- ============================================================
-- 第 4 部分：导入前处理存储过程（配置到 def_import_config.前处理模块）
--
-- 用法：在 def_import_config.前处理模块 填
--       sp_邀约_导入前处理($源表, @out)
-- ImportService::executeBeforeProcess 会自动：
--   - 替换 $源表 为临时表名字符串字面量
--   - 读取 @out 会话变量作为执行消息回传
--
-- 实现策略：
--   临时表每行的邀约日期可能不同（历史数据导入场景），用游标按邀约日期分组：
--   1. 先把临时表按邀约日期分组统计，存到临时分组表 _date_groups
--   2. 游标遍历各邀约日期，分别调用 sp_生成候选人编码 取起始序号
--   3. 对每个日期用 UPDATE + 用户变量 @i 给该日期的行批量赋值
--   4. 整体并发安全：各日期 def_seq 行独立 + LAST_INSERT_ID 连接级隔离
-- ============================================================
DROP PROCEDURE IF EXISTS `sp_邀约_导入前处理`;
DELIMITER $$
CREATE PROCEDURE `sp_邀约_导入前处理`(
  IN  p_src VARCHAR(100),           -- 临时表名（由 ImportService 替换 $源表 注入）
  OUT o_msg VARCHAR(200)            -- 输出消息（通过 @out 会话变量回传）
)
BEGIN
  DECLARE v_done INT DEFAULT 0;
  DECLARE v_date DATE;
  DECLARE v_cnt INT;
  DECLARE v_start BIGINT;
  DECLARE v_prefix VARCHAR(20);
  DECLARE v_total INT DEFAULT 0;
  DECLARE v_dates INT DEFAULT 0;

  -- 游标：遍历各邀约日期及行数（从临时分组表读取，见下方填充）
  DECLARE cur CURSOR FOR SELECT grp_date, grp_cnt FROM _date_groups;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;
  -- 忽略 1060 错误（候选人编码列已存在时 ALTER 会报 Duplicate column，被忽略）
  DECLARE CONTINUE HANDLER FOR 1060 BEGIN END;

  -- 0. 确保临时表有 候选人编码 列（系统生成字段，不在导入列配置里）
  --    若已存在则 ALTER 报 1060 被 CONTINUE HANDLER 忽略，不影响后续逻辑
  --    若不存在则新增 VARCHAR(14) 列，供后续 UPDATE 赋值
  SET @alter_sql = CONCAT('ALTER TABLE ', p_src, ' ADD COLUMN `候选人编码` VARCHAR(14) DEFAULT ""');
  PREPARE s_alt FROM @alter_sql;
  EXECUTE s_alt;
  DEALLOCATE PREPARE s_alt;

  -- 1. 创建临时分组表（存储各邀约日期及行数）
  DROP TEMPORARY TABLE IF EXISTS _date_groups;
  CREATE TEMPORARY TABLE _date_groups (
    grp_date DATE PRIMARY KEY,
    grp_cnt INT NOT NULL
  );

  -- 2. 从源临时表按邀约日期分组统计（动态 SQL，因 p_src 是参数）
  --    IFNULL 处理空邀约日期，空值归到今天
  SET @ins_sql = CONCAT(
    'INSERT INTO _date_groups(grp_date, grp_cnt) ',
    'SELECT IFNULL(`邀约日期`, CURDATE()), COUNT(*) FROM ', p_src,
    ' GROUP BY IFNULL(`邀约日期`, CURDATE())'
  );
  PREPARE s_ins FROM @ins_sql;
  EXECUTE s_ins;
  DEALLOCATE PREPARE s_ins;

  SELECT COUNT(*) INTO v_dates FROM _date_groups;
  IF v_dates = 0 THEN
    SET o_msg = '临时表无数据，跳过发号';
    DROP TEMPORARY TABLE IF EXISTS _date_groups;
  ELSE
    -- 3. 游标遍历各邀约日期
    OPEN cur;
    read_loop: LOOP
      FETCH cur INTO v_date, v_cnt;
      IF v_done THEN LEAVE read_loop; END IF;

      -- 3.1 对该日期发 v_cnt 个号（LAST_INSERT_ID 防并发，行锁串行化）
      CALL sp_生成候选人编码(v_cnt, v_date, v_start, v_prefix);

      -- 3.2 给源临时表中该邀约日期的行批量赋值
      --    用会话变量 @i 做行号，从 v_start 开始递增
      --    同一日期内的多行不重号；跨日期通过 def_seq 防并发
      SET @i = v_start - 1;
      SET @upd_sql = CONCAT(
        'UPDATE ', p_src,
        ' SET `候选人编码` = CONCAT(''', v_prefix, ''', LPAD((@i := @i + 1), 3, ''0'')) ',
        'WHERE IFNULL(`邀约日期`, CURDATE()) = ''', v_date, ''''
      );
      PREPARE s_upd FROM @upd_sql;
      EXECUTE s_upd;
      DEALLOCATE PREPARE s_upd;

      SET v_total = v_total + v_cnt;
    END LOOP;
    CLOSE cur;

    DROP TEMPORARY TABLE IF EXISTS _date_groups;
    SET o_msg = CONCAT('已批量赋号 ', v_total, ' 条，涉及 ', v_dates, ' 个业务日期');
  END IF;
END$$
DELIMITER ;

-- ============================================================
-- 第 5 部分：配置导入模块的前处理（按实际导入模块名调整 WHERE 条件后执行）
-- ============================================================
-- 把邀约功能码（2015）对应的 def_import_config.前处理模块 设为 sp_邀约_导入前处理
-- 注意：导入模块名需与 def_import_config 实际配置一致，下方为示例
--
-- UPDATE `def_import_config`
--   SET `前处理模块` = 'sp_邀约_导入前处理($源表, @out)'
--   WHERE `导入模块` = '邀约_导入';
