<?php

namespace App\Controllers;

use App\Constants\ApiCode;
use App\Libraries\AuthorizationService;
use App\Libraries\SessionUserContext;
use App\Models\Mcommon;
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

    protected array $serverTrace = [];

    private ?AuthorizationService $authService = null;

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

        $fields = [];
        $values = [];

        foreach ($data as $key => $value) {
            if ($key === '操作') continue;
            if (!$this->isValidIdentifier($key)) continue;
            $fields[] = sprintf('`%s`', $key);
            $values[] = $this->model->quote((string)$value);
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

        return $this->model->exec($sql);
    }

    protected function updateRecord(string $table, array $data, string $where): int
    {
        if (!$this->isValidIdentifier($table)) {
            throw new \InvalidArgumentException("非法表名: {$table}");
        }

        $updateFields = [];

        foreach ($data as $key => $value) {
            if (in_array($key, ['guid', '操作', '人员'])) continue;
            if (!$this->isValidIdentifier($key)) continue;
            if ($value === '') continue;
            $updateFields[] = sprintf('`%s`=%s', $key, $this->model->quote((string)$value));
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

        return $this->model->exec($sql);
    }

    protected function deleteRecord(string $table, string $where): int
    {
        if (!$this->isValidIdentifier($table)) {
            throw new \InvalidArgumentException("非法表名: {$table}");
        }

        $deleteData = $this->buildDeleteData();
        $updateFields = [];

        foreach ($deleteData as $key => $value) {
            $updateFields[] = sprintf('`%s`=%s', $key, $this->model->quote($value));
        }

        $sql = sprintf(
            'UPDATE `%s` SET %s, `记录结束日期`=%s WHERE %s',
            $table,
            implode(',', $updateFields),
            $this->model->quote(date('Y-m-d')),
            $where
        );

        return $this->model->exec($sql);
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
