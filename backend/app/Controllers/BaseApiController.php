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

    private ?AuthorizationService $authService = null;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->model = new Mcommon();
        $this->userContext = new SessionUserContext();

        // 从请求头获取 traceId，前端未传则自动生成
        $this->traceId = $request->getHeaderLine('X-Request-Id') ?: 'trace-' . bin2hex(random_bytes(8));
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

    protected function getLocationAuthz(): string
    {
        return $this->userContext->getLocationAuthz();
    }

    protected function resolveLocationAuthz(string $functionCode): string
    {
        $user = $this->userContext->getSessionUser();
        $employeeRegion = (string) ($user['location'] ?? '');

        $service = $this->getAuthorizationService();
        $userLocationAuth = $service->loadUserAuthField('属地赋权');
        $roleLocationAuth = $service->loadRoleAuthField(
            $functionCode,
            '属地赋权',
            '角色表属地',
            (string) ($user['roleAuthz'] ?? '')
        );

        return $service->resolve($userLocationAuth, $roleLocationAuth, $employeeRegion);
    }

    protected function buildLocationCondition(string $field, string $resolvedAuth, bool $upkeepAuth = false): string
    {
        return $this->getAuthorizationService()->buildCondition($field, $resolvedAuth, $upkeepAuth);
    }

    protected function resolveDeptNameAuthz(string $functionCode): string
    {
        $user = $this->userContext->getSessionUser();
        $employeeDeptName = (string) ($user['deptName'] ?? '');

        $service = $this->getAuthorizationService();
        $userDeptNameAuth = $service->loadUserAuthField('部门全称赋权');
        $roleDeptNameAuth = $service->loadRoleAuthField(
            $functionCode,
            '部门全称赋权',
            '全称赋权',
            (string) ($user['roleAuthz'] ?? '')
        );

        return $service->resolveDeptName($userDeptNameAuth, $roleDeptNameAuth, $employeeDeptName);
    }

    protected function buildDeptNameCondition(string $field, string $resolvedAuth, bool $upkeepAuth = false): string
    {
        return $this->getAuthorizationService()->buildDeptNameCondition($field, $resolvedAuth, $upkeepAuth);
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
