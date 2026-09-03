<?php

namespace App\Services\Application;

use App\Exceptions\BusinessException;
use App\Models\Mcommon;

/**
 * 阶段流转字段映射配置化服务（def_stage_transfer 消费方）
 *
 * 职责：读 def_stage_transfer 配置，为阶段流转（邀约→面试 / 面试→培训 /
 * 培训→在职）动态生成 INSERT...SELECT 的业务字段映射 SQL，替代三个
 * transfer 控制器中的硬编码列清单。
 *
 * 与 ApplicationService 的分工：
 * - ApplicationService::STAGE_FLOW（代码常量）：状态机合法性（哪阶段能转哪阶段）
 * - def_stage_transfer（本服务消费）：流转字段映射（源列→目标列、固定值、表单参数）
 *
 * 配置行语义（源表+目标表定位一组映射，按 排序 列序）：
 * - 源列非空（普通列名）        → 复制源表列：`t1.源列` → 目标列
 *   （源表在 FROM 子句中以别名 t1 引用；t2.列名 形式的限定列原样透传，
 *   供培训→在职的 ee_store LEFT JOIN 取渠道字段）
 * - 源列 = "expr:SQL表达式"     → 原样使用表达式（如员工类别按渠道派生）
 * - 源列空 + 默认值 "@参数名"   → 流转表单参数：quote($formData[参数名])
 * - 源列空 + 默认值 其他非空    → 固定值：quote(默认值)
 * - 源列空 + 默认值 NULL        → 配置错误，抛异常（强制显式声明，防空值歧义）
 *
 * 系统审计列（操作记录/操作来源/操作人员/开始操作时间/有效标识/删除标识等）
 * 不走配置：由调用方以 $systemColumns 追加（列名 → SELECT 侧 SQL 片段，
 * 引号与转义由调用方控制），保持各流转的审计口径差异。
 *
 * 安全（列名/表达式进入 SQL 的防注入约束）：
 * - 表名走四表白名单；列名仅允许 中文字符/字母/数字/下划线（可带 tN. 限定）
 * - 目标列、t1 源列均须真实存在于对应表（information_schema 校验，
 *   配置漂移时快速失败而非生成坏 SQL）
 * - expr: 表达式为管理员配置（信任级别同 def_query_column.查询名），
 *   仅做黑名单拦截（分号/注释符），不解析语义
 * - 表单参数值一律 model->quote() 转义
 */
class StageTransferService
{
    /** 允许的流转表（源表/目标表白名单） */
    private const ALLOWED_TABLES = ['ee_store', 'ee_interview', 'ee_train', 'ee_onjob'];

    /** 列名合法字符：中文/字母/数字/下划线 */
    private const COL_NAME_PATTERN = '/^[A-Za-z0-9_\x{4e00}-\x{9fa5}]+$/u';

    private Mcommon $model;

    /** 表列清单缓存（本请求内）：table => [col => true] */
    private array $tableColumns = [];

    public function __construct()
    {
        $this->model = new Mcommon();
    }

    /**
     * 生成配置化流转 INSERT...SELECT 完整 SQL
     *
     * @param string $sourceTable    源表名（白名单内，如 ee_store）
     * @param string $targetTable    目标表名（白名单内，如 ee_interview）
     * @param string $fromClause     FROM 子句（源表须以别名 t1 出现；
     *                               可含 JOIN，被引用表别名 t2/t3…）
     * @param string $whereClause    WHERE 子句（含 guid 定位，建议限定 t1.）
     * @param array  $formData       流转表单参数（@参数名 引用的键）
     * @param array  $systemColumns  系统审计列：列名 => SELECT 侧 SQL 片段
     * @param string $insertKeyword  INSERT 语句风格（insert into，与既有代码一致）
     * @return string 完整 SQL
     * @throws BusinessException 配置缺失、列名/表名非法、配置行语义错误
     */
    public function buildInsertSelect(
        string $sourceTable,
        string $targetTable,
        string $fromClause,
        string $whereClause,
        array $formData,
        array $systemColumns = [],
        string $insertKeyword = 'insert into'
    ): string {
        $this->assertTableAllowed($sourceTable, '源表');
        $this->assertTableAllowed($targetTable, '目标表');

        $rows = $this->loadConfig($sourceTable, $targetTable);
        if (empty($rows)) {
            throw new BusinessException(sprintf(
                '阶段流转配置缺失:def_stage_transfer 无 %s → %s 映射行',
                $sourceTable,
                $targetTable
            ));
        }

        $insertCols = [];
        $selectExprs = [];

        foreach ($rows as $row) {
            $targetCol = (string) $row['目标列'];
            $sourceCol = trim((string) $row['源列']);
            $default = $row['默认值'];

            $this->assertValidColumnName($targetCol, '目标列');
            if (!$this->columnExists($targetTable, $targetCol)) {
                throw new BusinessException(sprintf(
                    '阶段流转配置漂移:目标表 %s 无列 %s',
                    $targetTable,
                    $targetCol
                ));
            }

            if ($sourceCol !== '') {
                $selectExprs[] = sprintf('%s as `%s`', $this->buildSourceExpr($sourceTable, $sourceCol), $targetCol);
            } elseif ($default !== null && str_starts_with($default, '@')) {
                $paramKey = substr($default, 1);
                $value = $formData[$paramKey] ?? '';
                $selectExprs[] = sprintf('%s as `%s`', $this->model->quote((string) $value), $targetCol);
            } elseif ($default !== null) {
                $selectExprs[] = sprintf('%s as `%s`', $this->model->quote((string) $default), $targetCol);
            } else {
                throw new BusinessException(sprintf(
                    '阶段流转配置错误:%s → %s 的目标列 %s 既无源列也无默认值/@参数',
                    $sourceTable,
                    $targetTable,
                    $targetCol
                ));
            }

            $insertCols[] = sprintf('`%s`', $targetCol);
        }

        // 系统审计列（调用方追加，片段原样拼接）
        foreach ($systemColumns as $sysCol => $sysExpr) {
            $this->assertValidColumnName((string) $sysCol, '系统列');
            $insertCols[] = sprintf('`%s`', $sysCol);
            $selectExprs[] = (string) $sysExpr;
        }

        return sprintf(
            '%s %s (%s) select %s from %s where %s',
            $insertKeyword,
            $targetTable,
            implode(',', $insertCols),
            implode(',', $selectExprs),
            $fromClause,
            $whereClause
        );
    }

    /**
     * 构建 SELECT 侧源表达式
     *
     * 三种形态：
     * - expr:开头   → 原样表达式（黑名单拦截分号/注释）
     * - tN.列名     → 限定列原样透传（JOIN 源，t1 校验真实存在）
     * - 普通列名    → 限定为 t1.列名，校验存在于源表
     */
    private function buildSourceExpr(string $sourceTable, string $sourceCol): string
    {
        if (str_starts_with($sourceCol, 'expr:')) {
            $expr = trim(substr($sourceCol, 5));
            if ($expr === '' || preg_match('/(;|--|\/\*|\*\/)/', $expr) === 1) {
                throw new BusinessException('阶段流转配置错误:非法 expr 表达式');
            }
            return $expr;
        }

        if (preg_match('/^t(\d+)\.([^.]+)$/u', $sourceCol, $m) === 1) {
            $col = $m[2];
            $this->assertValidColumnName($col, '限定源列');
            if ($m[1] === '1' && !$this->columnExists($sourceTable, $col)) {
                throw new BusinessException(sprintf(
                    '阶段流转配置漂移:源表 %s 无列 %s',
                    $sourceTable,
                    $col
                ));
            }
            return $sourceCol;
        }

        $this->assertValidColumnName($sourceCol, '源列');
        if (!$this->columnExists($sourceTable, $sourceCol)) {
            throw new BusinessException(sprintf(
                '阶段流转配置漂移:源表 %s 无列 %s',
                $sourceTable,
                $sourceCol
            ));
        }
        return sprintf('t1.%s', $sourceCol);
    }

    /**
     * 读映射配置（有效行，按 排序 升序）
     */
    private function loadConfig(string $sourceTable, string $targetTable): array
    {
        $sql = sprintf(
            'select 源列,目标列,默认值 from def_stage_transfer
             where 源表=%s and 目标表=%s and 有效标识="1"
             order by 排序',
            $this->model->quote($sourceTable),
            $this->model->quote($targetTable)
        );
        $rows = $this->model->select($sql)->getResultArray();
        return is_array($rows) ? $rows : [];
    }

    /**
     * 列真实存在性校验（information_schema，请求内缓存）
     */
    private function columnExists(string $table, string $column): bool
    {
        if (!isset($this->tableColumns[$table])) {
            $sql = sprintf(
                "select COLUMN_NAME from information_schema.COLUMNS
                 where TABLE_SCHEMA=database() and TABLE_NAME=%s",
                $this->model->quote($table)
            );
            $cols = [];
            foreach ($this->model->select($sql)->getResultArray() as $row) {
                $cols[(string) $row['COLUMN_NAME']] = true;
            }
            $this->tableColumns[$table] = $cols;
        }
        return isset($this->tableColumns[$table][$column]);
    }

    private function assertTableAllowed(string $table, string $label): void
    {
        if (!in_array($table, self::ALLOWED_TABLES, true)) {
            throw new BusinessException(sprintf('阶段流转配置错误:%s %s 不在白名单', $label, $table));
        }
    }

    private function assertValidColumnName(string $column, string $label): void
    {
        if ($column === '' || preg_match(self::COL_NAME_PATTERN, $column) !== 1) {
            throw new BusinessException(sprintf('阶段流转配置错误:%s名非法(%s)', $label, $column));
        }
    }
}
