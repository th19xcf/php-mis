<?php

namespace Tests\Support;

use App\Controllers\BaseApiController;
use App\Libraries\MetadataCache;
use App\Models\Mcommon;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use Config\App;
use Config\Services;

/**
 * BaseApiController 测试替身
 *
 * - 桩注入 view_function 配置与表列（覆写 protected 读取点，不触库）
 * - logTrace 捕获到内存（测试断言 warning 文案，不写日志文件）
 * - 暴露 protected 方法供特征测试直调（private 方法请用 Reflection）
 * - make() 静态工厂：构造带自定义 body/header 的请求上下文并手动 initController
 */
class TestableBaseApiController extends BaseApiController
{
    /** @var array<int, array{level: string, message: string}> logTrace 捕获记录 */
    public array $traceLog = [];

    /** @var array<int, array<string, mixed>> view_function 行数组桩 */
    private array $stubViewColumns = [];

    /** @var array<string, array<int, string>> 表名 => 列名列表桩 */
    private array $stubTableColumns = [];

    /** 配置读取时抛出的异常桩（splitDataByFieldOwner 异常分支用） */
    private ?\Throwable $viewColumnsError = null;

    /**
     * 构造测试控制器实例（等效一次 HTTP 请求的初始化）
     *
     * @param string|null $body   请求 body 字符串（JSON 文本）；null = 空 body
     * @param array<string, string> $headers 请求头（如 ['X-Request-Id' => 'trace-xxx']）
     */
    public static function make(?string $body = null, array $headers = []): self
    {
        $config = new App();
        $uri = new SiteURI($config, '/');
        $agent = new UserAgent();
        // body 非 'php://input' 时 IncomingRequest 直接采用该字符串，getJSON(true) 可解析
        $request = new IncomingRequest($config, $uri, $body ?? '', $agent);
        foreach ($headers as $name => $value) {
            $request->setHeader($name, $value);
        }

        $response = new Response($config);
        $logger = Services::logger();

        $controller = new self();
        $controller->initController($request, $response, $logger);
        return $controller;
    }

    // ---- 桩配置 ----

    /** @param array<int, array<string, mixed>> $rows view_function 行数组 */
    public function setViewColumns(array $rows): void
    {
        $this->stubViewColumns = $rows;
    }

    public function setViewColumnsError(\Throwable $e): void
    {
        $this->viewColumnsError = $e;
    }

    public function setTableColumns(string $table, array $cols): void
    {
        $this->stubTableColumns[$table] = $cols;
    }

    public function setModel(Mcommon $model): void
    {
        $this->model = $model;
    }

    // ---- 覆写：桩注入与捕获 ----

    protected function getMetadataCache(): MetadataCache
    {
        return new StubMetadataCache($this->stubViewColumns, $this->viewColumnsError);
    }

    protected function getTableColumns(string $table): array
    {
        if (array_key_exists($table, $this->stubTableColumns)) {
            return $this->stubTableColumns[$table];
        }
        return parent::getTableColumns($table);
    }

    protected function logTrace(string $level, string $message): void
    {
        $this->traceLog[] = ['level' => $level, 'message' => $message];
    }

    // ---- protected 方法公开委托（特征测试入口）----

    public function successEx(mixed $data = null, string $msg = 'Success', float $serverElapsedMs = 0.0): \CodeIgniter\HTTP\ResponseInterface
    {
        return $this->success($data, $msg, $serverElapsedMs);
    }

    public function errorEx(string $code, string $msg, mixed $data = null): \CodeIgniter\HTTP\ResponseInterface
    {
        return $this->error($code, $msg, $data);
    }

    public function paramErrorEx(string $msg): \CodeIgniter\HTTP\ResponseInterface
    {
        return $this->paramError($msg);
    }

    public function notFoundEx(string $msg): \CodeIgniter\HTTP\ResponseInterface
    {
        return $this->notFound($msg);
    }

    public function serverErrorEx(string $msg): \CodeIgniter\HTTP\ResponseInterface
    {
        return $this->serverError($msg);
    }

    public function businessErrorEx(string $msg): \CodeIgniter\HTTP\ResponseInterface
    {
        return $this->businessError($msg);
    }

    public function requireParamEx(array $data, string $param): ?\CodeIgniter\HTTP\ResponseInterface
    {
        return $this->requireParam($data, $param);
    }

    public function requireParamsEx(array $data, array $params): ?\CodeIgniter\HTTP\ResponseInterface
    {
        return $this->requireParams($data, $params);
    }

    public function getJsonInputEx(): array
    {
        return $this->getJsonInput();
    }

    public function getGuidFromRequestEx(): string
    {
        return $this->getGuidFromRequest();
    }

    public function getUserWorkIdEx(): string
    {
        return $this->getUserWorkId();
    }

    public function getUserNameEx(): string
    {
        return $this->getUserName();
    }

    public function getDeptAuthzEx(): string
    {
        return $this->getDeptAuthz();
    }

    public function setServerTraceEx(array $trace): void
    {
        $this->setServerTrace($trace);
    }

    public function addServerTraceEx(string $key, float $ms): void
    {
        $this->addServerTrace($key, $ms);
    }

    public function getServerTraceState(): array
    {
        return $this->serverTrace;
    }

    public function buildPerformanceTableEx(string $tag, string $status, string $info, array $steps, float|int $t0): string
    {
        return $this->buildPerformanceTable($tag, $status, $info, $steps, $t0);
    }

    public function buildDetailSelectFieldsEx(string $functionCode, string $table): string
    {
        return $this->buildDetailSelectFields($functionCode, $table);
    }

    public function buildDetailSelectFieldsMultiEx(string $functionCode, array $tables): string
    {
        return $this->buildDetailSelectFieldsMulti($functionCode, $tables);
    }

    public function splitDataByFieldOwnerEx(string $functionCode, string $mainTable, array $data): array
    {
        return $this->splitDataByFieldOwner($functionCode, $mainTable, $data);
    }

    public function insertRecordEx(string $table, array $data): int
    {
        return $this->insertRecord($table, $data);
    }

    public function updateRecordEx(string $table, array $data, string $where): int
    {
        return $this->updateRecord($table, $data, $where);
    }

    public function deleteRecordEx(string $table, string $where): int
    {
        return $this->deleteRecord($table, $where);
    }
}

/**
 * MetadataCache 测试替身：跳过父类构造（不创建 cache 服务与 Mcommon），
 * getViewFunctionColumns 返回预置行集或抛预置异常
 */
class StubMetadataCache extends MetadataCache
{
    public function __construct(
        private readonly array $viewColumns,
        private readonly ?\Throwable $error = null
    ) {
        // 有意跳过父类构造（测试桩不触碰缓存服务与数据库模型）
    }

    public function getViewFunctionColumns(string $functionCode): array
    {
        if ($this->error !== null) {
            throw $this->error;
        }
        return $this->viewColumns;
    }
}
