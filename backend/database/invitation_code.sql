-- ============================================================
-- 邀约编码生成 - 混合方案（存储过程 + def_seq 序列表）
-- 关联链：ee_store.邀约编码 → ee_interview.邀约编码 → ee_train.初始编码 → ee_base.初始编码
--
-- 两条入库路径共用同一发号核心：
--   1. 页面新增（单条）：InvitationApi::add() 内 CALL sp_生成邀约编码(1, @seq, @prefix)
--   2. Excel 导入（批量）：def_import_config.前处理模块 = sp_邀约_导入前处理($源表, @out)
--      ImportService::executeBeforeProcess 会自动替换 $源表 为临时表名
--
-- 并发安全：def_seq 表 INSERT ... ON DUPLICATE KEY UPDATE 原子自增，无需 FOR UPDATE
-- 失败不回收：导入事务失败时 def_seq 已自增不回退（编码不重用，保证流程实例可追溯）
-- ============================================================

-- ============================================================
-- 第 1 部分：字段增补（如尚未存在）
-- ============================================================

-- ee_store 增加 邀约编码（流程实例级关联键源头）
ALTER TABLE `ee_store`
  ADD COLUMN `邀约编码` VARCHAR(14) NOT NULL DEFAULT ''
    COMMENT '流程实例级关联键源头（邀约新增时生成，贯穿面试→培训→主档）' AFTER `GUID`,
  ADD UNIQUE KEY `uk_邀约编码` (`邀约编码`),
  ADD INDEX `idx_邀约编码` (`邀约编码`);

-- ee_interview 增加 邀约编码（承接 ee_store.邀约编码，跨表串联）
ALTER TABLE `ee_interview`
  ADD COLUMN `邀约编码` VARCHAR(14) NOT NULL DEFAULT ''
    COMMENT '承接 ee_store.邀约编码，流程实例级关联键' AFTER `GUID`,
  ADD INDEX `idx_邀约编码` (`邀约编码`);

-- ee_train 增加 初始编码（承接 ee_interview.邀约编码，流程实例级关联键延续）
ALTER TABLE `ee_train`
  ADD COLUMN `初始编码` VARCHAR(14) NOT NULL DEFAULT ''
    COMMENT '承接 ee_interview.邀约编码，流程实例级关联键（贯穿至 ee_onjob.培训编码）' AFTER `GUID`,
  ADD INDEX `idx_初始编码` (`初始编码`);

-- ee_onjob 增加 培训编码（承接 ee_train.初始编码，反查来源培训记录）
ALTER TABLE `ee_onjob`
  ADD COLUMN `培训编码` VARCHAR(14) NOT NULL DEFAULT ''
    COMMENT '承接 ee_train.初始编码，反查来源培训记录' AFTER `GUID`,
  ADD INDEX `idx_培训编码` (`培训编码`);

-- ============================================================
-- 第 2 部分：通用发号序列表
-- ============================================================
CREATE TABLE IF NOT EXISTS `def_seq` (
  `序列名` VARCHAR(50) NOT NULL COMMENT '业务序列名，如 邀约编码/初始编码/员工编码',
  `当前值` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '已发放的最大序号',
  `更新时间` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`序列名`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='通用发号序列表（原子自增防并发重号）';

-- ============================================================
-- 第 3 部分：发号核心存储过程（页面新增 + 导入前处理 都调用）
-- ============================================================
DROP PROCEDURE IF EXISTS `sp_生成邀约编码`;
DELIMITER $$
CREATE PROCEDURE `sp_生成邀约编码`(
  IN  p_count    INT,             -- 本次需要的编码数量（页面新增=1，批量导入=N）
  OUT o_start_seq BIGINT,         -- 返回起始序号（含）
  OUT o_prefix   VARCHAR(20)      -- 返回前缀，如 YY20260818
)
BEGIN
  DECLARE v_today DATE DEFAULT CURDATE();

  IF p_count <= 0 THEN
    SET o_start_seq = 0;
    SET o_prefix = '';
  ELSE
    -- 原子自增：INSERT ... ON DUPLICATE KEY UPDATE 天然防并发重号
    INSERT INTO `def_seq`(`序列名`, `当前值`)
    VALUES ('邀约编码', p_count)
    ON DUPLICATE KEY UPDATE `当前值` = `当前值` + VALUES(`当前值`);

    -- 回读本次起始序号（已自增后的最大值 - 本次数量 + 1）
    SELECT `当前值` - p_count + 1 INTO o_start_seq
      FROM `def_seq`
      WHERE `序列名` = '邀约编码';

    SET o_prefix = CONCAT('YY', DATE_FORMAT(v_today, '%Y%m%d'));
  END IF;
END$$
DELIMITER ;

-- ============================================================
-- 第 4 部分：导入前处理存储过程（配置到 def_import_config.前处理模块）
-- 用法：在 def_import_config.前处理模块 填
--       sp_邀约_导入前处理($源表, @out)
-- ImportService::executeBeforeProcess 会自动：
--   - 替换 $源表 为临时表名字符串字面量
--   - 读取 @out 会话变量作为执行消息回传
-- ============================================================
DROP PROCEDURE IF EXISTS `sp_邀约_导入前处理`;
DELIMITER $$
CREATE PROCEDURE `sp_邀约_导入前处理`(
  IN  p_src VARCHAR(100),         -- 临时表名（由 ImportService 替换 $源表 注入）
  OUT o_msg VARCHAR(200)          -- 输出消息（通过 @out 会话变量回传）
)
BEGIN
  DECLARE v_cnt    INT DEFAULT 0;
  DECLARE v_start  BIGINT;
  DECLARE v_prefix VARCHAR(20);

  -- 统计临时表待导入行数
  SET @cnt_sql = CONCAT('SELECT COUNT(*) INTO @cnt FROM ', p_src);
  PREPARE s_cnt FROM @cnt_sql;
  EXECUTE s_cnt;
  DEALLOCATE PREPARE s_cnt;
  SET v_cnt = IFNULL(@cnt, 0);

  IF v_cnt = 0 THEN
    SET o_msg = '临时表无数据，跳过发号';
  ELSE
    -- 批量发 N 个号（一次原子自增，不逐行 SELECT MAX）
    CALL sp_生成邀约编码(v_cnt, v_start, v_prefix);

    -- 用会话变量 @i 做行号，给临时表.邀约编码 批量赋值
    -- LPAD 保证 4 位补零；@i 从 v_start 开始递增
    SET @i = v_start - 1;
    SET @upd_sql = CONCAT(
      'UPDATE ', p_src,
      ' SET `邀约编码` = CONCAT(''', v_prefix, ''', LPAD((@i := @i + 1), 4, ''0''))'
    );
    PREPARE s_upd FROM @upd_sql;
    EXECUTE s_upd;
    DEALLOCATE PREPARE s_upd;

    SET o_msg = CONCAT('已批量赋号 ', v_cnt, ' 条，前缀 ', v_prefix);
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
