<?php

namespace App\Traits;

/**
 * 数据库事务统一封装
 *
 * 用法：类中 use TransactionTrait; 后将多步写操作包进 $this->withTransaction(function () { ... })。
 *
 * 连接一致性：本方法使用 db_connect('btdc')，与 Mcommon::getDb() 在同一请求内
 * 返回同一连接实例（CI4 连接缓存），因此闭包内经 Mcommon 的写操作自动纳入同一事务。
 *
 * 注意：事务内的实时读取应使用 Mcommon::query()（无缓存）；
 * 避免使用 Mcommon::select()（带请求级缓存，同请求重复调用会命中旧结果，
 * 对"读己之写"的事务语义不安全，如 last_insert_id、统计类查询）。
 */
trait TransactionTrait
{
    /**
     * 在数据库事务中执行回调
     *
     * - 回调抛出异常：回滚并原样抛出
     * - 回调正常返回但连接层事务状态为失败（SQL 静默失败）：回滚并抛出 RuntimeException
     * - 支持嵌套调用：CI4 事务使用 SAVEPOINT 处理嵌套，内层异常仅回滚内层操作
     *
     * @template T
     * @param callable():T $fn 业务闭包
     * @return T
     * @throws \Throwable
     */
    public function withTransaction(callable $fn)
    {
        $db = db_connect('btdc');
        $db->transStart();
        try {
            $result = $fn();
            if ($db->transStatus === false) {
                throw new \RuntimeException('数据库事务执行失败，已回滚');
            }
            $db->transComplete();
            return $result;
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }
    }
}
