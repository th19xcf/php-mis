<?php

namespace App\Models;

use App\Libraries\SessionUserContext;
use CodeIgniter\Model;

class Mcommon extends Model
{
    protected $DBGroup = 'btdc';

    private ?object $dbInstance = null;

    /**
     * 请求级 SQL 缓存（static 实现跨实例共享）
     *
     * 设为 static 的原因：18 个服务类各自 new Mcommon()，若为实例属性则
     * 同请求内 def_user/def_query_config 等元数据查询会被重复执行。
     * 设为 static 后，同请求内所有 Mcommon 实例共享同一份缓存。
     *
     * 跨请求安全性：PHP-FPM 每请求结束时所有 static 变量自动销毁，
     * 不会跨请求污染。CLI 模式下进程退出即清理。
     */
    private static array $requestCache = [];

    /**
     * 请求级 SQL 执行耗时追踪（static 跨实例共享）
     * 用于性能追踪，记录每条 SQL 的耗时
     */
    private static array $sqlTrace = [];

    /**
     * 获取并清空 SQL 执行耗时追踪数据
     */
    public static function getSqlTrace(): array
    {
        $trace = self::$sqlTrace;
        self::$sqlTrace = [];
        return $trace;
    }

    private function getDb(): object
    {
        if ($this->dbInstance === null) {
            $t0 = hrtime(true);
            $this->dbInstance = db_connect('btdc');
            $connectMs = (hrtime(true) - $t0) / 1e6;
            log_message('debug', sprintf('[Mcommon] 数据库连接耗时: %.2fms', $connectMs));

            // 记录数据库连接耗时到追踪数组（使用英文标签避免 HTTP Header 编码问题）
            self::$sqlTrace[] = [
                'sql' => '[DB Connect]',
                'ms' => round($connectMs, 2)
            ];

            // 设置查询超时（30秒），防止远端MySQL慢查询卡死PHP单线程服务器
            try {
                $t1 = hrtime(true);
                $this->dbInstance->query('SET SESSION max_execution_time = 30000');
                $setMaxMs = (hrtime(true) - $t1) / 1e6;
                log_message('debug', sprintf('[Mcommon] SET max_execution_time 耗时: %.2fms', $setMaxMs));

                // 记录 SET 命令耗时（仅当超过 10ms 时记录）
                if ($setMaxMs > 10) {
                    self::$sqlTrace[] = [
                        'sql' => '[SET max_execution_time]',
                        'ms' => round($setMaxMs, 2)
                    ];
                }
            } catch (\Throwable $e) {
                log_message('warning', '[Mcommon] 设置 max_execution_time 失败: ' . $e->getMessage());
            }
        }
        return $this->dbInstance;
    }

    public function select(string $sql)
    {
        $cacheKey = md5($sql);
        if (isset(self::$requestCache[$cacheKey])) {
            log_message('debug', '[Mcommon] 请求级缓存命中: ' . substr($sql, 0, 50) . '...');
            return self::$requestCache[$cacheKey];
        }

        $db = $this->getDb();
        $t0 = hrtime(true);
        $result = $db->query($sql);
        $queryMs = (hrtime(true) - $t0) / 1e6;

        // 只记录慢查询（>50ms）到追踪数组，避免 Header 过长
        if ($queryMs > 50) {
            // base64 编码 SQL 内容，避免 HTTP Header 中文编码问题
            $sqlPreview = substr($sql, 0, 200);
            self::$sqlTrace[] = [
                'sql' => base64_encode($sqlPreview),
                'ms' => round($queryMs, 2)
            ];
            log_message('warning', sprintf('[Mcommon] 慢查询 %.2fms: %s', $queryMs, $sqlPreview));
        } else {
            log_message('debug', sprintf('[Mcommon] SQL耗时: %.2fms | %s', $queryMs, substr($sql, 0, 80)));
        }

        self::$requestCache[$cacheKey] = $result;
        return $result;
    }

    /**
     * 执行带参数绑定的 SQL（INSERT / UPDATE / DELETE）
     *
     * CodeIgniter 的 Query Builder 会把数组中的标量依次替换到 SQL 中的 ? 占位符。
     * 注意：调用方需要使用 ? 占位符；此处兼容历史代码中遗留的 %s 占位符。
     *
     * @param string $sql SQL 语句
     * @param array  $bindings 绑定的参数数组
     * @return object|\CodeIgniter\Database\BaseResult
     */
    public function query(string $sql, array $bindings = [])
    {
        $db = $this->getDb();
        $normalized = preg_replace('/%s/', '?', $sql);
        return $db->query($normalized, $bindings);
    }

    /**
     * 执行 SQL 并返回结果（带请求级缓存，用于读操作）
     *
     * @param string $sql SQL 语句
     * @param array  $bindings 绑定的参数数组
     * @return object|\CodeIgniter\Database\BaseResult
     */
    public function queryCached(string $sql, array $bindings = [])
    {
        $cacheKey = md5($sql . json_encode($bindings));
        if (isset(self::$requestCache[$cacheKey])) {
            log_message('debug', '[Mcommon] 请求级缓存命中(queryCached): ' . substr($sql, 0, 50) . '...');
            return self::$requestCache[$cacheKey];
        }

        $db = $this->getDb();
        $normalized = preg_replace('/%s/', '?', $sql);
        $result = $db->query($normalized, $bindings);
        self::$requestCache[$cacheKey] = $result;
        return $result;
    }

    /**
     * 返回上一次通过 query() / modify() 执行的 SQL 所影响的行数。
     *
     * 由于 Model 基类的 affectedRows() 会走 __call() 触发 Query Builder，
     * 而本类的写操作走的是原始 query()，需要直接从底层连接获取受影响行数。
     */
    public function affectedRows(): int
    {
        return (int) $this->getDb()->affectedRows();
    }

    public function exec(string $sql): int
    {
        $db = $this->getDb();
        $db->query($sql);
        return $db->affectedRows();
    }

    /**
     * 写入业务审计日志
     *
     * $info 支持两种形式:
     *  - string: 兼容旧调用,原样写入(适用于登录/登出等简单场景)
     *  - array:  结构化数据,自动 JSON 编码(保留中文),便于后续查询/统计
     *            推荐结构: ['table'=>..., 'pk'=>..., 'pk_values'=>[...], 'fields'=>[...]]
     *
     * @param string       $option  动作描述(如 "修改[0]"、"删除[1]")
     * @param string       $func_id 功能编码
     * @param array|string $info    附加信息
     * @return int 影响行数(log_switch 关闭时返回 0)
     */
    public function sql_log(string $option, string $func_id = '', array|string $info = ''): int
    {
        $userContext = new SessionUserContext();
        $user = $userContext->getSessionUser();
        $user_name = $user['userName'] ?? '';
        $user_workid = $user['workId'] ?? '';
        $log_switch = $userContext->getLogSwitch();

        if (!$log_switch) {
            return 0;
        }

        // 结构化信息走 JSON 编码(保留中文/Unicode,不转义斜杠);字符串信息保持原样
        $infoEncoded = is_array($info)
            ? json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string) $info;

        $db = $this->getDb();

        $insert = sprintf(
            'insert into sys_sql_log (姓名,用户名,动作,功能编码,信息) values ("%s","%s","%s","%s","%s")',
            $db->escape($user_name),
            $db->escape($user_workid),
            $db->escape($option),
            $db->escape($func_id),
            $db->escape($infoEncoded)
        );

        $db->query($insert);
        return $db->affectedRows();
    }

    public function quote(string $value): string
    {
        $db = $this->getDb();
        return $db->escape($value);
    }

}
