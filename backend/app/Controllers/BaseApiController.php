<?php

namespace App\Controllers;

use App\Constants\ApiCode;
use App\Exceptions\AuthException;
use App\Exceptions\BusinessException;
use App\Exceptions\ValidationException;
use App\Libraries\AuthorizationService;
use App\Libraries\DetailSelectFieldBuilder;
use App\Libraries\FieldOwnerRouter;
use App\Libraries\MetadataCache;
use App\Libraries\PerformanceTableFormatter;
use App\Libraries\RecordSqlBuilder;
use App\Libraries\SessionUserContext;
use App\Models\Mcommon;
use App\Services\Audit\AuditLogService;
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
     * 构建 detail() 的 SELECT 字段列表（配置驱动，无兜底）
     *
     * 读配置 + 读表列后委托 DetailSelectFieldBuilder::buildSelectFields；
     * 字段构造逻辑与异常文案见该类（Phase 2 机械抽取，行为零变更）。
     *
     * @param string $functionCode 功能编码（如 '2015'）
     * @param string $table        目标表名（如 'ee_store'）
     * @return string 反引号包裹的逗号分隔字段列表，如 `GUID`,`候选人编码`,`姓名`
     * @throws BusinessException 配置为空或字段均不匹配时
     */
    protected function buildDetailSelectFields(string $functionCode, string $table): string
    {
        return DetailSelectFieldBuilder::buildSelectFields(
            $functionCode,
            $table,
            $this->getMetadataCache()->getViewFunctionColumns($functionCode),
            $this->getTableColumns($table)
        );
    }

    /**
     * 构建 detail() 的多表 JOIN SELECT 字段列表（配置驱动）
     *
     * 读配置 + 逐别名读表列后委托 DetailSelectFieldBuilder::buildSelectFieldsMulti；
     * 字段构造逻辑与异常文案见该类（Phase 2 机械抽取，行为零变更）。
     *
     * @param string $functionCode 功能编码
     * @param array  $tables       [别名 => 表名]，如 ['e' => 'ee_employment', 'p' => 'hr_person']
     * @return string 逗号分隔的带别名前缀字段列表
     * @throws BusinessException 配置为空时
     */
    protected function buildDetailSelectFieldsMulti(string $functionCode, array $tables): string
    {
        $tableCols = [];
        foreach ($tables as $alias => $table) {
            $tableCols[$alias] = $this->getTableColumns($table);
        }

        return DetailSelectFieldBuilder::buildSelectFieldsMulti(
            $functionCode,
            $this->getMetadataCache()->getViewFunctionColumns($functionCode),
            $tableCols
        );
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
        return PerformanceTableFormatter::format($tag, $status, $info, $steps, $t0);
    }

    protected function insertRecord(string $table, array $data): int
    {
        if (!RecordSqlBuilder::isValidIdentifier($table)) {
            throw new \InvalidArgumentException("非法表名: {$table}");
        }

        $columns = $this->getTableColumns($table);

        // 当表存在 UUID 列且调用方未提供 UUID 时，自动生成 UUIDv7
        $autoUuid = RecordSqlBuilder::shouldAutoGenerateUuid($columns, $data)
            ? RecordSqlBuilder::generateUuidv7Binary()
            : null;

        // 校验→构造→执行（SQL 构造逻辑见 RecordSqlBuilder）
        $sql = RecordSqlBuilder::buildInsertSql($table, $data, $columns, $autoUuid, fn (string $v) => $this->model->quote($v));
        if ($sql === null) {
            return 0;
        }

        $affected = $this->model->exec($sql);

        // 写入审计日志
        if ($affected > 0) {
            $db = $this->model->getDb();
            $newGuid = (string) $db->insertID();

            $auditLog = new AuditLogService();
            if ($auditLog->isAuditedTable($table)) {
                // 人员审计（严格模式：失败抛异常，调用方事务回滚；
                // 人员六表不再写 def_audit_log，由 hr_audit_log 承载合规轨迹）
                $auditLog->logEvent([
                    '人员编码'   => (string) ($data['人员编码'] ?? ''),
                    '候选人编码' => (string) ($data['候选人编码'] ?? ''),
                    '表名'      => $table,
                    '记录GUID'  => (int) $newGuid,
                    '记录UUID'  => $autoUuid,
                    '操作类型'  => '新增',
                    '变更字段'  => '全部',
                    '原值'      => null,
                    '新值'      => '新增记录',
                    '操作人员'  => $this->getUserWorkId() ?: 'system',
                    '操作来源'  => '页面',
                ]);
            } else {
                try {
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
        }

        return $affected;
    }

    protected function updateRecord(string $table, array $data, string $where): int
    {
        if (!RecordSqlBuilder::isValidIdentifier($table)) {
            throw new \InvalidArgumentException("非法表名: {$table}");
        }

        $columns = $this->getTableColumns($table);
        $effectiveUpdateKeys = RecordSqlBuilder::computeEffectiveUpdateKeys($data, $columns);

        if (empty($effectiveUpdateKeys)) {
            return 0;
        }

        $quote = fn (string $v) => $this->model->quote($v);

        // === 写入前：读取旧值快照（GUID/UUID/受影响字段；人员审计表含定位键） ===
        $isAuditedTable = (new AuditLogService())->isAuditedTable($table);
        $oldRows = [];
        try {
            $locatorCols = $isAuditedTable ? ['人员编码', '候选人编码'] : [];
            $snapshotSql = RecordSqlBuilder::buildSnapshotSelectSql($table, $where, $columns, $effectiveUpdateKeys, $locatorCols);
            $oldRows = $this->model->select($snapshotSql)->getResultArray() ?: [];
        } catch (\Throwable $e) {
            $this->logTrace('error', "审计日志读取旧值失败(update) table={$table}: " . $e->getMessage());
        }

        // === 执行原 update ===
        $sql = RecordSqlBuilder::buildUpdateSql($table, $data, $effectiveUpdateKeys, $where, $quote);
        $affected = $this->model->exec($sql);

        // === 写入后：按字段对比，写审计日志 ===
        if ($affected > 0 && !empty($oldRows)) {
            if ($isAuditedTable) {
                // 人员审计（严格模式：失败抛异常，调用方事务回滚；
                // 人员六表不再写 def_audit_log，由 hr_audit_log 承载合规轨迹）
                (new AuditLogService())->logUpdateDiff(
                    $table,
                    $oldRows,
                    $data,
                    $this->getUserWorkId() ?: 'system',
                    '页面'
                );
            } else {
                try {
                    foreach (RecordSqlBuilder::collectUpdateAuditEntries($oldRows, $data, $effectiveUpdateKeys) as $entry) {
                        $this->writeAuditLog(
                            $table,
                            $entry['guid'],
                            $entry['uuid'],
                            '更新',
                            $entry['field'],
                            $entry['old'],
                            $entry['new']
                        );
                    }
                } catch (\Throwable $e) {
                    $this->logTrace('error', "审计日志写入失败(update) table={$table}: " . $e->getMessage());
                }
            }
        }

        return $affected;
    }

    protected function deleteRecord(string $table, string $where): int
    {
        if (!RecordSqlBuilder::isValidIdentifier($table)) {
            throw new \InvalidArgumentException("非法表名: {$table}");
        }

        $columns = $this->getTableColumns($table);

        // === 删除前：读取整行快照（GUID/UUID；人员审计表含定位键） ===
        $isAuditedTable = (new AuditLogService())->isAuditedTable($table);
        $oldRows = [];
        try {
            $locatorCols = $isAuditedTable ? ['人员编码', '候选人编码'] : [];
            $snapshotSql = RecordSqlBuilder::buildSnapshotSelectSql($table, $where, $columns, [], $locatorCols);
            $oldRows = $this->model->select($snapshotSql)->getResultArray() ?: [];
        } catch (\Throwable $e) {
            $this->logTrace('error', "审计日志读取旧值失败(delete) table={$table}: " . $e->getMessage());
        }

        // 软删 UPDATE（审计字段过滤与记录结束日期逻辑见 RecordSqlBuilder）
        $sql = RecordSqlBuilder::buildSoftDeleteUpdateSql(
            $table,
            $where,
            $this->buildDeleteData(),
            $columns,
            fn (string $v) => $this->model->quote($v)
        );
        if ($sql === null) {
            return 0;
        }

        $affected = $this->model->exec($sql);

        // === 写入审计日志 ===
        if ($affected > 0 && !empty($oldRows)) {
            if ($isAuditedTable) {
                // 人员审计（严格模式：失败抛异常，调用方事务回滚；
                // 人员六表不再写 def_audit_log，由 hr_audit_log 承载合规轨迹）
                $auditLog = new AuditLogService();
                foreach ($oldRows as $oldRow) {
                    $auditLog->logEvent([
                        '人员编码'   => (string) ($oldRow['人员编码'] ?? ''),
                        '候选人编码' => (string) ($oldRow['候选人编码'] ?? ''),
                        '表名'      => $table,
                        '记录GUID'  => (int) ($oldRow['GUID'] ?? 0),
                        '记录UUID'  => $oldRow['UUID'] ?? null,
                        '操作类型'  => '删除',
                        '变更字段'  => '全部',
                        '原值'      => '删除前记录',
                        '新值'      => null,
                        '操作人员'  => $this->getUserWorkId() ?: 'system',
                        '操作来源'  => '页面',
                    ]);
                }
            } else {
                try {
                    foreach ($oldRows as $oldRow) {
                        $this->writeAuditLog(
                            $table,
                            (string)($oldRow['GUID'] ?? ''),
                            $oldRow['UUID'] ?? null,
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
        }

        return $affected;
    }

    /**
     * 写入审计日志到 def_audit_log
     *
     * 设计原则：
     *  - 失败时只记 log，不抛异常（避免审计拖垮主业务）
     *  - SQL 构造（UUID 占位 / 值截断 / NULL 字面量）见 RecordSqlBuilder::buildAuditInsertSql
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

        $this->model->exec(RecordSqlBuilder::buildAuditInsertSql(
            $table,
            $pkGuid,
            $pkUuid,
            $opType,
            $field,
            $oldValue,
            $newValue,
            $operator,
            fn (string $v) => $this->model->quote($v)
        ));
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
     * 按字段归属表拆分输入数据（写入路由）
     *
     * 读配置（异常吞噬 + warning 留痕）+ 读主表列后委托 FieldOwnerRouter::split；
     * 分组与双写兼容逻辑见该类（Phase 2 机械抽取，行为零变更）。
     *
     * @param string $functionCode 功能编码（如 2015）
     * @param string $mainTable    主表名（如 ee_store）
     * @param array  $data         输入数据（字段名 => 值）
     * @return array<string, array> 表名 => 字段映射（至少含主表分组）
     */
    protected function splitDataByFieldOwner(string $functionCode, string $mainTable, array $data): array
    {
        try {
            $viewColumns = $this->getMetadataCache()->getViewFunctionColumns($functionCode);
        } catch (\Throwable $e) {
            $this->logTrace('warning', '[splitDataByFieldOwner] 字段归属配置读取失败，全部按主表处理: ' . $e->getMessage());
            $viewColumns = [];
        }

        return FieldOwnerRouter::split(
            $viewColumns,
            $mainTable,
            $this->getTableColumns($mainTable),
            $data,
            fn (string $message) => $this->logTrace('warning', $message)
        );
    }

}
