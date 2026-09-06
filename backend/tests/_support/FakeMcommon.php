<?php

namespace Tests\Support;

use App\Models\Mcommon;

/**
 * Mcommon 测试替身：内存记录 SQL，不触达任何真实数据库。
 *
 * 背景：CI4 4.7 的 db_connect() 仅创建连接对象（lazy 建连），
 * 真实建连发生在首次 query——本类覆写全部数据访问方法，确保测试路径永不建连。
 *
 * 安全边界（绊网设计）：
 * - query()/queryCached() 直接抛异常：特征测试只允许走 select/exec/quote 路径，
 *   任何漏网到这两个方法的调用都视为测试缺陷，而非静默连库
 * - 夹具值限定 ASCII 且不含引号/反斜杠，使假 quote 与真实 MySQLi escape 输出一致
 */
class FakeMcommon extends Mcommon
{
    /** @var string[] 全部 exec SQL（按调用顺序） */
    public array $execSqlLog = [];

    /** @var string[] 全部 select SQL（按调用顺序） */
    public array $selectSqlLog = [];

    /** exec() 的返回值（默认 1 = 影响一行） */
    public int $nextExecAffected = 1;

    /** getDb()->insertID() 的返回值 */
    public int $nextInsertId = 0;

    /** @var array<string, array<int, array<string, mixed>>> select SQL 精确匹配 => 预置行集 */
    private array $selectResults = [];

    /** @var array<string, \Throwable> select SQL 精确匹配 => 抛出的异常 */
    private array $selectErrors = [];

    /** select() 强制返回值：false = 模拟 SQL 失败；FakeResult = 固定行集；null = 正常查预置结果 */
    public mixed $forceSelectReturn = null;

    public function select(string $sql)
    {
        if (isset($this->selectErrors[$sql])) {
            throw $this->selectErrors[$sql];
        }
        $this->selectSqlLog[] = $sql;
        if ($this->forceSelectReturn !== null) {
            return $this->forceSelectReturn;
        }
        return new FakeResult($this->selectResults[$sql] ?? []);
    }

    public function exec(string $sql): int
    {
        $this->execSqlLog[] = $sql;
        return $this->nextExecAffected;
    }

    public function quote(string $value): string
    {
        // 确定性假实现：对 ASCII 无引号夹具与真实 escape 输出一致
        return sprintf("'%s'", str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $value));
    }

    public function affectedRows(): int
    {
        return $this->nextExecAffected;
    }

    public function sql_log(string $option, string $func_id = '', array|string $info = ''): int
    {
        return 0;
    }

    public function query(string $sql, array $bindings = [])
    {
        throw new \RuntimeException('FakeMcommon 绊网: 测试路径不允许走 query(): ' . $sql);
    }

    public function queryCached(string $sql, array $bindings = [])
    {
        throw new \RuntimeException('FakeMcommon 绊网: 测试路径不允许走 queryCached(): ' . $sql);
    }

    public function getDb(): object
    {
        return new FakeConnection($this->nextInsertId, $this->nextExecAffected);
    }

    // ---- 桩配置 ----

    /**
     * 预置某条 select SQL 的返回行集（精确匹配）
     *
     * @param string $sql 预期被查询的 SQL
     * @param array<int, array<string, mixed>> $rows
     */
    public function setSelectResult(string $sql, array $rows): void
    {
        $this->selectResults[$sql] = $rows;
    }

    /**
     * 预置某条 select SQL 抛出异常（精确匹配），用于测试异常吞噬分支
     */
    public function setSelectError(string $sql, \Throwable $e): void
    {
        $this->selectErrors[$sql] = $e;
    }
}

/**
 * 查询结果替身：仅需 getResultArray/getRowArray 两个消费面
 */
class FakeResult
{
    /** @param array<int, array<string, mixed>> $rows */
    public function __construct(private readonly array $rows)
    {
    }

    public function getResultArray(): array
    {
        return $this->rows;
    }

    /**
     * 对象数组消费面（如 ImportService 日期校验分支的 $date->字段值）
     *
     * @return array<int, object>
     */
    public function getResult(): array
    {
        return array_map(static fn (array $row) => (object) $row, $this->rows);
    }

    public function getRowArray(): array
    {
        return $this->rows[0] ?? [];
    }
}

/**
 * 连接替身：暴露 insertID/affectedRows 两个消费面 + 事务控制面（流水模式批量编辑用）
 */
class FakeConnection
{
    public bool $transStatusReturn = true;

    public bool $rolledBack = false;

    public function __construct(
        private readonly int $insertId,
        private readonly int $affectedRows
    ) {
    }

    public function insertID(): int
    {
        return $this->insertId;
    }

    public function affectedRows(): int
    {
        return $this->affectedRows;
    }

    public function transStart(): void
    {
    }

    public function transComplete(): void
    {
    }

    public function transRollback(): void
    {
        $this->rolledBack = true;
    }

    public function transStatus(): bool
    {
        return $this->transStatusReturn;
    }
}
