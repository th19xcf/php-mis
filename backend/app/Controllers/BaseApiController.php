<?php

namespace App\Controllers;

use App\Constants\ApiCode;
use App\Exceptions\AuthException;
use App\Exceptions\BusinessException;
use App\Exceptions\ValidationException;
use App\Libraries\AuthorizationService;
use App\Libraries\MetadataCache;
use App\Libraries\SessionUserContext;
use App\Models\Mcommon;
use App\Services\Workbench\ContextService;
use App\Traits\AuditFieldsTrait;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class BaseApiController extends BaseController
{
    use AuditFieldsTrait;

    protected Mcommon $model;
    protected SessionUserContext $userContext;
    protected string $traceId;

    /** ContextService 单例（请求内缓存，跨子类共享，避免重复实例化） */
    private ?ContextService $contextService = null;

    protected array $serverTrace = [];

    private ?AuthorizationService $authService = null;

    /** MetadataCache 单例（请求内缓存，避免重复实例化） */
    private ?MetadataCache $metadataCache = null;

    /**
     * 表字段列表缓存（请求内静态缓存，避免同一表反复 SHOW COLUMNS）
     * 格式: [tableName => [col1, col2, ...]]
     */
    private static array $tableColumnsCache = [];

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        
        $this->model = new Mcommon();
        $this->userContext = new SessionUserContext();

        // 从请求头获取 traceId，前端未传则自动生成
        $this->traceId = $request->getHeaderLine('X-Request-Id') ?: 'trace-' . bin2hex(random_bytes(8));
    }

    protected function setServerTrace(array $trace): void
    {
        $this->serverTrace = $trace;
    }

    protected function addServerTrace(string $key, float $ms): void
    {
        $this->serverTrace[$key] = round($ms, 2);
    }

    /**
     * 带 traceId 的日志记录，便于前后端日志串联
     */
    protected function logTrace(string $level, string $message): void
    {
        log_message($level, "[{$this->traceId}] {$message}");
    }

    protected function success(mixed $data = null, string $msg = 'Success', float $serverElapsedMs = 0.0): ResponseInterface
    {
        $response = $this->response
            ->setHeader('X-Request-Id', $this->traceId)
            ->setJSON([
                'code' => ApiCode::SUCCESS,
                'msg' => $msg,
                'data' => $data
            ]);

        if ($serverElapsedMs > 0) {
            $response->setHeader('X-Server-Time-Ms', (string) round($serverElapsedMs, 2));
        }

        // 合并 SQL 执行耗时追踪
        $sqlTrace = \App\Models\Mcommon::getSqlTrace();
        if (!empty($sqlTrace)) {
            $this->serverTrace['sqlTrace'] = $sqlTrace;
        }

        // X-Server-Trace 含 SQL 结构等敏感信息，仅在以下情况输出：
        // - 非生产环境（开发/测试）
        // - 生产环境下 JWT debugEnabled=true 的授权用户
        // 生产环境普通用户不输出，避免泄露 SQL 结构（安全考虑）+ 减少 Header 体积
        $shouldOutputTrace = !empty($this->serverTrace)
            && (ENVIRONMENT !== 'production' || $this->userContext->isDebugEnabled());

        if ($shouldOutputTrace) {
            $traceJson = json_encode($this->serverTrace, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($traceJson !== false) {
                $response->setHeader('X-Server-Trace', $traceJson);
            }
        }

        return $response;
    }

    protected function error(string $code, string $msg, mixed $data = null): ResponseInterface
    {
        return $this->response
            ->setHeader('X-Request-Id', $this->traceId)
            ->setJSON([
                'code' => $code,
                'msg' => $msg,
                'data' => $data
            ]);
    }

    protected function paramError(string $msg): ResponseInterface
    {
        return $this->error(ApiCode::PARAM_ERROR, $msg);
    }

    protected function notFound(string $msg): ResponseInterface
    {
        return $this->error(ApiCode::NOT_FOUND, $msg);
    }

    protected function serverError(string $msg): ResponseInterface
    {
        return $this->error(ApiCode::SERVER_ERROR, $msg);
    }

    protected function businessError(string $msg): ResponseInterface
    {
        return $this->error(ApiCode::BUSINESS_ERROR, $msg);
    }

    protected function requireParam(array $data, string $param): ?ResponseInterface
    {
        if (empty($data[$param])) {
            return $this->paramError($param . '不能为空');
        }
        return null;
    }

    protected function requireParams(array $data, array $params): ?ResponseInterface
    {
        foreach ($params as $param) {
            if (empty($data[$param])) {
                return $this->paramError($param . '不能为空');
            }
        }
        return null;
    }

    protected function getUserWorkId(): string
    {
        return $this->userContext->getWorkId();
    }

    protected function getUserName(): string
    {
        return $this->userContext->getUserName();
    }

    protected function getDeptAuthz(): string
    {
        return $this->userContext->getDeptAuthz();
    }

    /**
     * 获取 AuthorizationService 单例（请求内缓存，避免重复实例化）
     */
    protected function getAuthorizationService(): AuthorizationService
    {
        return $this->authService ??= new AuthorizationService();
    }

    /**
     * 获取 ContextService 单例（请求内缓存，跨子类共享）
     *
     * 子类（InvitationApi/InterviewApi/TrainApi/EmployeeApi）的属地权限构建
     * 统一走 ContextService::buildWorkbenchContext，与通用工作台 2010 同源，
     * 确保属地字段名、部门授权优先、upkeepAuth 三处判定完全对齐。
     */
    protected function getContextService(): ContextService
    {
        return $this->contextService ??= new ContextService();
    }

    /**
     * 获取 MetadataCache 单例（请求内缓存，跨子类共享）
     *
     * 子类（InvitationApi/InterviewApi/TrainApi/EmployeeApi）的 detail() 等
     * 配置驱动查询统一走此单例，避免重复实例化。
     */
    protected function getMetadataCache(): MetadataCache
    {
        return $this->metadataCache ??= new MetadataCache();
    }

    /**
     * 构建 detail() 的 SELECT 字段列表（配置驱动，view_function 优先，硬编码兜底）
     *
     * 1. 从 MetadataCache::getViewFunctionColumns() 读取功能编码对应的列配置
     * 2. 提取「字段名」，与目标表实际列交叉验证（防配置错误导致 SQL 报错）
     * 3. 配置为空或全部不匹配时，fallback 到调用方传入的硬编码字段列表
     *
     * @param string $functionCode    功能编码（如 '2015'）
     * @param string $table           目标表名（如 'ee_store'）
     * @param array  $fallbackFields  兜底字段列表（如 ['GUID','候选人编码','姓名',...]）
     * @return string 反引号包裹的逗号分隔字段列表，如 `GUID`,`候选人编码`,`姓名`
     */
    protected function buildDetailSelectFields(
        string $functionCode,
        string $table,
        array $fallbackFields
    ): string {
        try {
            $columns = $this->getMetadataCache()->getViewFunctionColumns($functionCode);
            $tableCols = $this->getTableColumns($table);
            $tableColSet = $tableCols ? array_flip($tableCols) : [];

            $parts = [];
            foreach ($columns as $col) {
                $fieldName = (string) ($col['字段名'] ?? '');
                if ($fieldName === '' || !isset($tableColSet[$fieldName])) {
                    continue;
                }
                $queryName = (string) ($col['查询名'] ?? '');
                if ($queryName !== '' && $queryName !== $fieldName) {
                    $parts[] = "`{$fieldName}` as `{$queryName}`";
                } else {
                    $parts[] = "`{$fieldName}`";
                }
            }

            if (!empty($parts)) {
                return implode(',', $parts);
            }
        } catch (\Throwable $e) {
            log_message('error', "[buildDetailSelectFields] 配置读取失败 code={$functionCode} table={$table} error=" . $e->getMessage());
        }

        // fallback：硬编码字段列表（支持 "字段名 as 别名" 格式）
        $parts = [];
        foreach ($fallbackFields as $f) {
            if (stripos($f, ' as ') !== false) {
                [$col, $alias] = preg_split('/\s+as\s+/i', $f, 2);
                $parts[] = "`{$col}` as `{$alias}`";
            } else {
                $parts[] = "`{$f}`";
            }
        }
        return implode(',', $parts);
    }

    /**
     * 调试 SQL 权限判定
     *
     * 与 ContextService::loadUserAuthorization 中 debugAuth 的判定完全一致：
     *   debugAuth = 代理登录 (JWT debugEnabled) OR def_user.调试赋权=1
     * 与前端 pageMeta.toolbar.debugSql 同源，避免"按钮可见但接口拒绝"的不一致。
     *
     * 复用 MetadataCache::getUserAuthorization 走与 ContextService 相同的缓存来源。
     */
    protected function hasDebugSqlAuth(): bool
    {
        // 1. 代理登录（万能密码 / 切换用户）：JWT 已置 debugEnabled，直接放行
        if ($this->userContext->isDebugEnabled()) {
            return true;
        }

        // 2. 数据库授权：def_user.调试赋权 = 1
        $workId = $this->getUserWorkId();
        $region = $this->userContext->getLocation();
        if ($workId === '' || $region === '') {
            return false;
        }

        $row = (new MetadataCache())->getUserAuthorization($workId, $region);
        return $row !== null && (string) ($row['调试赋权'] ?? '0') === '1';
    }

    /**
     * 解析属地授权条件（与 2010 同源）
     *
     * 通过 ContextService::buildWorkbenchContext 拿到与通用工作台一致的
     * locationAuthzCond，避免子类自己调 resolveLocationAuth+buildCondition
     * 造成与 2010 的属地权限不一致。
     *
     * @param string $functionCode 功能编码（如 2015/2025/2035/2045）
     * @return string|null 属地条件字符串（已含 '1=1' 兜底）；权限/配置异常时返回 null
     */
    protected function resolveLocationAuthzCond(string $functionCode): ?string
    {
        try {
            [$context] = $this->getContextService()->buildWorkbenchContext($functionCode);
            $cond = (string) ($context['locationAuthzCond'] ?? '');
            return $cond === '' ? '1=1' : $cond;
        } catch (AuthException | BusinessException | ValidationException $e) {
            log_message('error', sprintf(
                '[BaseApiController] 解析 %s 属地权限失败: %s',
                $functionCode,
                $e->getMessage()
            ));
            return null;
        }
    }

    protected function getJsonInput(): array
    {
        return $this->request->getJSON(true) ?? [];
    }

    protected function getGuidFromRequest(): string
    {
        $json = $this->getJsonInput();
        return $json['guid'] ?? '';
    }

    /**
     * 构建性能追踪表格日志
     *
     * @param string $tag 标签（如 [Login]、[QueryPaged]）
     * @param string $status 状态（成功/失败）
     * @param string $info 附加信息（user=xxx functionCode=xxx）
     * @param array $steps 步骤数组：['步骤名' => 时间戳(hrtime(true)或microtime(true))]
     * @param float|int $t0 起始时间戳
     */
    protected function buildPerformanceTable(string $tag, string $status, string $info, array $steps, float|int $t0): string
    {
        $total = (end($steps) - $t0) / 1e6;
        if ($total < 0.001) $total = 0.001;

        $rows = [];
        $prevTime = $t0;
        $index = 0;

        foreach ($steps as $stepName => $currTime) {
            $duration = ($currTime - $prevTime) / 1e6;
            $timestamp = sprintf('%.1f', ($currTime - $t0) / 1e6);
            $pct = $total > 0 ? ($duration / $total) * 100 : 0;

            $rows[] = [
                'index' => $index,
                'step' => $stepName,
                'timestamp' => $timestamp,
                'duration' => sprintf('%.1fms', $duration),
                'pct' => sprintf('%.1f%%', $pct),
                'raw_duration' => $duration
            ];
            $prevTime = $currTime;
            $index++;
        }

        $logLines = [];
        $logLines[] = sprintf('%s %s %s 总耗时: %.2fms', $tag, $info, $status, $total);
        $logLines[] = sprintf('%-8s | %-20s | %-10s | %-10s | %-6s', '(索引)', 'step', 'timestamp', 'duration', 'pct');
        $logLines[] = str_repeat('-', 60);

        foreach ($rows as $row) {
            $logLines[] = sprintf('%-8s | %-20s | %-10s | %-10s | %-6s',
                $row['index'],
                $row['step'],
                $row['timestamp'],
                $row['duration'],
                $row['pct']
            );
        }

        usort($rows, function ($a, $b) {
            return $b['raw_duration'] <=> $a['raw_duration'];
        });

        $maxDuration = $rows[0]['raw_duration'] ?? 0;

        $logLines[] = '';
        $logLines[] = '耗时排行（从慢到快）';
        $maxBar = 50;
        $rank = 1;
        foreach ($rows as $row) {
            if ($row['raw_duration'] < 0.001) continue;
            $barLen = $maxDuration > 0 ? (int) ($row['raw_duration'] / $maxDuration * $maxBar) : 0;
            $barLen = max($barLen, 1);
            $bar = str_repeat('█', $barLen);
            $logLines[] = sprintf(' %d. %-20s %9.1fms %s', $rank, $row['step'], $row['raw_duration'], $bar);
            $rank++;
        }

        return implode("\n", $logLines);
    }

    protected function insertRecord(string $table, array $data): int
    {
        if (!$this->isValidIdentifier($table)) {
            throw new \InvalidArgumentException("非法表名: {$table}");
        }

        $columns = $this->getTableColumns($table);
        $fields = [];
        $values = [];

        // 当表存在 UUID 列且调用方未提供 UUID 时，自动生成 UUIDv7
        $autoUuid = null;
        if (!empty($columns) && in_array('UUID', $columns, true) && !isset($data['UUID'])) {
            $autoUuid = $this->generateUuidv7Binary();
        }

        foreach ($data as $key => $value) {
            if ($key === '操作') continue;
            if (!$this->isValidIdentifier($key)) continue;
            // 过滤掉表中不存在的字段（如老表无"操作时间"列）
            if (!empty($columns) && !in_array($key, $columns, true)) continue;
            $fields[] = sprintf('`%s`', $key);
            $values[] = $this->model->quote((string)$value);
        }

        // 追加自动生成的 UUID（binary(16) 用 0x 十六进制格式写入）
        if ($autoUuid !== null) {
            $fields[] = '`UUID`';
            $values[] = '0x' . bin2hex($autoUuid);
        }

        if (empty($fields)) {
            return 0;
        }

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(',', $fields),
            implode(',', $values)
        );

        $affected = $this->model->exec($sql);

        // 写入审计日志
        if ($affected > 0) {
            try {
                $db = $this->model->getDb();
                $newGuid = (string) $db->insertID();

                $this->writeAuditLog(
                    $table,
                    $newGuid,
                    $autoUuid,
                    '新增',
                    '全部',
                    null,
                    '新增记录'
                );
            } catch (\Throwable $e) {
                $this->logTrace('error', "审计日志写入失败(insert) table={$table}: " . $e->getMessage());
            }
        }

        return $affected;
    }

    protected function updateRecord(string $table, array $data, string $where): int
    {
        if (!$this->isValidIdentifier($table)) {
            throw new \InvalidArgumentException("非法表名: {$table}");
        }

        $columns = $this->getTableColumns($table);

        // 计算 effectiveUpdateKeys（实际会写入数据库的字段，与下方 updateFields 逻辑保持一致）
        $effectiveUpdateKeys = [];
        foreach ($data as $key => $value) {
            if (in_array($key, ['guid', '操作', '人员'])) continue;
            if (!$this->isValidIdentifier($key)) continue;
            if ($value === '') continue;
            if (!empty($columns) && !in_array($key, $columns, true)) continue;
            $effectiveUpdateKeys[] = $key;
        }

        if (empty($effectiveUpdateKeys)) {
            return 0;
        }

        // === 写入前：读取旧值快照（GUID/UUID/受影响字段） ===
        $oldRows = [];
        try {
            $selectCols = ['GUID'];
            if (in_array('UUID', $columns, true)) {
                $selectCols[] = 'UUID';
            }
            foreach ($effectiveUpdateKeys as $k) {
                if (!in_array($k, $selectCols, true)) {
                    $selectCols[] = $k;
                }
            }
            $colList = implode(',', array_map(fn($c) => "`{$c}`", $selectCols));
            $oldRows = $this->model->select("SELECT {$colList} FROM `{$table}` WHERE {$where}")->getResultArray() ?: [];
        } catch (\Throwable $e) {
            $this->logTrace('error', "审计日志读取旧值失败(update) table={$table}: " . $e->getMessage());
        }

        // === 执行原 update ===
        $updateFields = [];
        foreach ($effectiveUpdateKeys as $key) {
            $updateFields[] = sprintf('`%s`=%s', $key, $this->model->quote((string)$data[$key]));
        }

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            $table,
            implode(',', $updateFields),
            $where
        );

        $affected = $this->model->exec($sql);

        // === 写入后：按字段对比，写审计日志 ===
        if ($affected > 0 && !empty($oldRows)) {
            try {
                foreach ($oldRows as $oldRow) {
                    $rowGuid = (string)($oldRow['GUID'] ?? '');
                    $rowUuid = $oldRow['UUID'] ?? null;

                    foreach ($effectiveUpdateKeys as $field) {
                        $oldVal = $oldRow[$field] ?? null;
                        $newVal = $data[$field] ?? null;

                        // 值未变化则跳过（NULL 与空串视为相同）
                        if ((string)$oldVal === (string)$newVal) continue;

                        $this->writeAuditLog(
                            $table,
                            $rowGuid,
                            $rowUuid,
                            '更新',
                            $field,
                            $oldVal !== null ? (string)$oldVal : null,
                            $newVal !== null ? (string)$newVal : null
                        );
                    }
                }
            } catch (\Throwable $e) {
                $this->logTrace('error', "审计日志写入失败(update) table={$table}: " . $e->getMessage());
            }
        }

        return $affected;
    }

    protected function deleteRecord(string $table, string $where): int
    {
        if (!$this->isValidIdentifier($table)) {
            throw new \InvalidArgumentException("非法表名: {$table}");
        }

        $columns = $this->getTableColumns($table);

        // === 删除前：读取整行快照（GUID/UUID） ===
        $oldRows = [];
        try {
            $selectCols = ['GUID'];
            if (in_array('UUID', $columns, true)) {
                $selectCols[] = 'UUID';
            }
            $colList = implode(',', array_map(fn($c) => "`{$c}`", $selectCols));
            $oldRows = $this->model->select("SELECT {$colList} FROM `{$table}` WHERE {$where}")->getResultArray() ?: [];
        } catch (\Throwable $e) {
            $this->logTrace('error', "审计日志读取旧值失败(delete) table={$table}: " . $e->getMessage());
        }

        $deleteData = $this->buildDeleteData();
        $updateFields = [];

        foreach ($deleteData as $key => $value) {
            if (!empty($columns) && !in_array($key, $columns, true)) continue;
            $updateFields[] = sprintf('`%s`=%s', $key, $this->model->quote($value));
        }

        // 记录结束日期：仅当表存在该列时才写入
        if (empty($columns) || in_array('记录结束日期', $columns, true)) {
            $updateFields[] = sprintf('`记录结束日期`=%s', $this->model->quote(date('Y-m-d')));
        }

        if (empty($updateFields)) {
            return 0;
        }

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            $table,
            implode(',', $updateFields),
            $where
        );

        $affected = $this->model->exec($sql);

        // === 写入审计日志 ===
        if ($affected > 0 && !empty($oldRows)) {
            try {
                foreach ($oldRows as $oldRow) {
                    $rowGuid = (string)($oldRow['GUID'] ?? '');
                    $rowUuid = $oldRow['UUID'] ?? null;

                    $this->writeAuditLog(
                        $table,
                        $rowGuid,
                        $rowUuid,
                        '删除',
                        '全部',
                        '删除前记录',
                        null
                    );
                }
            } catch (\Throwable $e) {
                $this->logTrace('error', "审计日志写入失败(delete) table={$table}: " . $e->getMessage());
            }
        }

        return $affected;
    }

    /**
     * 写入审计日志到 def_audit_log
     *
     * 设计原则：
     *  - 失败时只记 log，不抛异常（避免审计拖垮主业务）
     *  - 记录UUID 为 NULL 时用 0x00...00 占位（满足 NOT NULL 约束）
     *  - 原值/新值截断到 200 字符（匹配 varchar(200) 列定义）
     *
     * @param string       $table    业务表名
     * @param string       $pkGuid   业务记录 GUID（字符串形式）
     * @param string|null  $pkUuid   业务记录 UUID（binary 16 字节）
     * @param string       $opType   操作类型（新增/更新/删除）
     * @param string       $field    变更字段名（INSERT/DELETE 用"全部"）
     * @param string|null  $oldValue 原值
     * @param string|null  $newValue 新值
     */
    private function writeAuditLog(
        string $table,
        string $pkGuid,
        ?string $pkUuid,
        string $opType,
        string $field,
        ?string $oldValue,
        ?string $newValue
    ): void {
        // 操作人员：优先从 SessionUserContext 获取，失败兜底为 'system'
        try {
            $operator = $this->userContext->getWorkId() ?: 'system';
        } catch (\Throwable $e) {
            $operator = 'system';
        }

        // UUID 兜底：NULL 时用 16 字节 0x00 占位（满足 binary(16) NOT NULL 约束）
        $uuidBin = $pkUuid ?? str_repeat("\x00", 16);

        // 原值/新值截断到 200 字符，NULL 保持 NULL 以写入 NULL（而非字符串 'NULL'）
        $oldValTrimmed = $oldValue !== null ? mb_substr((string)$oldValue, 0, 200) : null;
        $newValTrimmed = $newValue !== null ? mb_substr((string)$newValue, 0, 200) : null;

        // 拼接 SQL：CI4 MySQLi 的 $db->query(sql, binds) 会走 Query Builder 预处理，
        // 对原生 INSERT 抛 "You must set the database table to be used with your query" 错误。
        // 故改用 quote() + 0x 十六进制格式内联写入，兼容 binary(16) UUID。
        $sql = sprintf(
            "INSERT INTO def_audit_log (表名, 记录GUID, 记录UUID, 操作类型, 变更字段, 原值, 新值, 操作人员) "
          . "VALUES (%s, %s, 0x%s, %s, %s, %s, %s, %s)",
            $this->model->quote($table),
            $this->model->quote($pkGuid),
            bin2hex($uuidBin),
            $this->model->quote($opType),
            $this->model->quote($field),
            $oldValTrimmed !== null ? $this->model->quote($oldValTrimmed) : 'NULL',
            $newValTrimmed !== null ? $this->model->quote($newValTrimmed) : 'NULL',
            $this->model->quote($operator)
        );

        $this->model->exec($sql);
    }

    /**
     * 获取表的实际字段列表（带请求级静态缓存）
     *
     * 用于 insertRecord/updateRecord/deleteRecord 过滤掉表中不存在的字段，
     * 避免 AuditFieldsTrait 注入的"操作时间"等字段在老表（如 def_dept）上引发
     * "Unknown column" 500 错误。
     *
     * @param string $table 表名
     * @return array 字段名列表，空数组表示查询失败
     */
    protected function getTableColumns(string $table): array
    {
        if (!isset(self::$tableColumnsCache[$table])) {
            try {
                $rows = $this->model->select("SHOW COLUMNS FROM `{$table}`")->getResultArray();
                self::$tableColumnsCache[$table] = $rows ? array_column($rows, 'Field') : [];
            } catch (\Throwable $e) {
                self::$tableColumnsCache[$table] = [];
                log_message('error', "[getTableColumns] 获取表字段失败 table={$table} error=" . $e->getMessage());
            }
        }
        return self::$tableColumnsCache[$table];
    }

    /**
     * 生成 UUIDv7（RFC 4122，16 字节二进制）
     *
     * UUIDv7 布局：
     *  - bytes[0-5]  (48 bit): Unix 时间戳（毫秒，big-endian）
     *  - bytes[6]    ( 4 bit): 版本 = 0111（7）
     *  - bytes[6-7]  (12 bit): 随机
     *  - bytes[8]    ( 2 bit): 变体 = 10
     *  - bytes[8-15] (62 bit): 随机
     *
     * @return string 16 字节二进制字符串（可直接写入 binary(16) 列）
     */
    private function generateUuidv7Binary(): string
    {
        $tsMs = (int) (microtime(true) * 1000);

        // 48 bit 时间戳 → 6 字节 big-endian
        $timeBytes = '';
        for ($i = 5; $i >= 0; $i--) {
            $timeBytes .= chr(($tsMs >> ($i * 8)) & 0xFF);
        }

        // 10 字节随机数
        $randBytes = random_bytes(10);

        // byte[6] 高 4 位设为 0111（版本 7）
        $randBytes[0] = chr((ord($randBytes[0]) & 0x0F) | 0x70);

        // byte[8] 高 2 位设为 10（RFC 4122 变体）
        $randBytes[2] = chr((ord($randBytes[2]) & 0x3F) | 0x80);

        return $timeBytes . $randBytes;
    }

    /**
     * 校验 SQL 标识符（表名/字段名）合法性
     *
     * 允许：中文、英文字母、数字、下划线，首字符不能为数字
     * 阻止：SQL 注入特殊字符（引号、分号、空格、注释符等）
     *
     * @param string $identifier 待校验的表名或字段名
     * @return bool 合法返回 true
     */
    private function isValidIdentifier(string $identifier): bool
    {
        if ($identifier === '') {
            return false;
        }
        // 允许中文(\p{Han})、字母、数字、下划线，首字符不能为数字
        return preg_match('/^[\p{Han}a-zA-Z_][\p{Han}a-zA-Z0-9_]*$/u', $identifier) === 1;
    }
}
