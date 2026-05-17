<?php

namespace App\Controllers;

use App\Constants\ApiCode;
use App\Libraries\SessionUserContext;
use App\Models\Mcommon;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class BaseApiController extends BaseController
{
    protected Mcommon $model;
    protected SessionUserContext $userContext;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        $this->model = new Mcommon();
        $this->userContext = new SessionUserContext();
    }

    protected function success(mixed $data = null, string $msg = 'Success'): ResponseInterface
    {
        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg' => $msg,
            'data' => $data
        ]);
    }

    protected function error(int $code, string $msg, mixed $data = null): ResponseInterface
    {
        return $this->response->setJSON([
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
        return $this->userContext->getSessionUser()['workId'] ?? 'system';
    }

    protected function getUserName(): string
    {
        return $this->userContext->getSessionUser()['userName'] ?? 'system';
    }

    protected function getDeptAuthz(): string
    {
        return $this->userContext->getSessionUser()['deptAuthz'] ?? '';
    }

    protected function getLocationAuthz(): string
    {
        return $this->userContext->getSessionUser()['locationAuthz'] ?? '';
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

    protected function buildInsertData(array $data, string $operation = '新增'): array
    {
        $data['操作记录'] = $operation;
        $data['操作来源'] = '页面' . $operation;
        $data['操作人员'] = $this->getUserWorkId();
        $data['开始操作时间'] = date('Y-m-d H:i:s');
        $data['操作时间'] = date('Y-m-d H:i:s');
        $data['有效标识'] = '1';
        $data['删除标识'] = '0';
        return $data;
    }

    protected function buildUpdateData(array $data, string $operation = '修改'): array
    {
        $data['操作记录'] = $operation;
        $data['操作来源'] = '页面' . $operation;
        $data['操作人员'] = $this->getUserWorkId();
        $data['操作时间'] = date('Y-m-d H:i:s');
        return $data;
    }

    protected function buildDeleteData(): array
    {
        return [
            '操作记录' => '删除',
            '操作来源' => '页面删除',
            '操作人员' => $this->getUserWorkId(),
            '结束操作时间' => date('Y-m-d H:i:s'),
            '删除标识' => '1',
            '有效标识' => '0'
        ];
    }

    protected function insertRecord(string $table, array $data): int
    {
        $fields = [];
        $values = [];

        foreach ($data as $key => $value) {
            if ($key === '操作') continue;
            $fields[] = $key;
            $values[] = sprintf('"%s"', addslashes((string)$value));
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(',', $fields),
            implode(',', $values)
        );

        return $this->model->exec($sql);
    }

    protected function updateRecord(string $table, array $data, string $where): int
    {
        $updateFields = [];

        foreach ($data as $key => $value) {
            if (in_array($key, ['guid', '操作', '人员'])) continue;
            if ($value === '') continue;
            $updateFields[] = sprintf('%s="%s"', $key, addslashes((string)$value));
        }

        if (empty($updateFields)) {
            return 0;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $table,
            implode(',', $updateFields),
            $where
        );

        return $this->model->exec($sql);
    }

    protected function deleteRecord(string $table, string $where): int
    {
        $deleteData = $this->buildDeleteData();
        $updateFields = [];

        foreach ($deleteData as $key => $value) {
            $updateFields[] = sprintf('%s="%s"', $key, $value);
        }

        $sql = sprintf(
            'UPDATE %s SET %s, 记录结束日期="%s" WHERE %s',
            $table,
            implode(',', $updateFields),
            date('Y-m-d'),
            $where
        );

        return $this->model->exec($sql);
    }
}
