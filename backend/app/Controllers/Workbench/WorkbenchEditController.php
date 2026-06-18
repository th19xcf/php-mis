<?php

namespace App\Controllers\Workbench;

use App\Constants\ApiCode;
use App\Controllers\BaseController;
use App\Services\Workbench\EditService;

/**
 * 工作台编辑控制器
 *
 * 负责处理工作台字段配置查询与记录增删改相关接口。
 * 所有业务逻辑均下沉至 App\Services\Workbench\EditService，
 * 本控制器仅负责请求/响应编排。
 */
class WorkbenchEditController extends BaseController
{
    use WorkbenchResponseTrait;

    private EditService $editService;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ) {
        parent::initController($request, $response, $logger);
        $this->initWorkbenchTrait(new \App\Models\Mcommon());
        $this->editService = new EditService();
    }

    /**
     * 获取新增字段配置
     */
    public function addFields(string $functionCode = '')
    {
        try {
            $session = \Config\Services::session();
            $fieldModule = $session->get($functionCode . '-field_module');

            log_message('info', "[addFields] 开始处理 - 功能编码: {$functionCode}, 字段模块: " . ($fieldModule ?: '未设置'));

            $result = $this->editService->getAddFields($functionCode, $fieldModule);

            log_message('info', "[addFields] 处理成功 - 功能编码: {$functionCode}, 返回字段数: " . count($result['fields'] ?? []));

            return $this->success($result);
        } catch (\Throwable $e) {
            log_message('error', '[addFields] 获取新增字段配置失败 - 功能编码: ' . $functionCode);
            log_message('error', '[addFields] 异常信息: ' . $e->getMessage());
            log_message('error', '[addFields] 异常位置: ' . $e->getFile() . ':' . $e->getLine());
            log_message('error', '[addFields] 堆栈跟踪: ' . $e->getTraceAsString());

            $errorMsg = ENVIRONMENT === 'development'
                ? $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'
                : '获取新增字段配置失败';

            return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, $errorMsg);
        }
    }

    /**
     * 获取详情显示字段配置
     */
    public function detailFields(string $functionCode = '')
    {
        try {
            $session = \Config\Services::session();
            $fieldModule = $session->get($functionCode . '-field_module');

            $result = $this->editService->getDetailFields($functionCode, $fieldModule);

            return $this->success($result);
        } catch (\Throwable $e) {
            log_message('error', '获取详情字段配置失败: ' . $e->getMessage());
            return $this->error(ApiCode::WORKBENCH_QUERY_FAILED, '获取详情字段配置失败');
        }
    }

    /**
     * 获取批量修改字段配置（可修改="2"）
     */
    public function batchEditFields(string $functionCode = '')
    {
        try {
            $session = \Config\Services::session();
            $fieldModule = $session->get($functionCode . '-field_module');

            $result = $this->editService->getBatchEditFields($functionCode, $fieldModule);

            return $this->success($result);
        } catch (\Throwable $e) {
            log_message('error', '获取批量修改字段配置失败: ' . $e->getMessage());
            return $this->error(ApiCode::WORKBENCH_PAGED_QUERY_FAILED, '获取批量修改字段配置失败');
        }
    }

    /**
     * 新增记录
     */
    public function addRow(string $functionCode = '')
    {
        try {
            $request = $this->request->getJSON(true) ?? [];
            $session = \Config\Services::session();

            $config = $this->editService->getDataTableConfig($functionCode, $session);

            if (empty($config['dataTable'])) {
                return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '新增失败：未找到数据表配置');
            }

            $this->editService->executeBeforeInsert($config['beforeInsert']);

            $num = 0;
            switch ($config['dataModel']) {
                case '0':
                    $num = $this->editService->addRowMode0($config['dataTable'], $request);
                    break;
                case '1':
                    $num = $this->editService->addRowMode1($config['dataTable'], $request, $session->get('user_workid') ?? 'system');
                    break;
                case '2':
                    $num = $this->editService->addRowMode2($config['dataTable'], $request, $session->get('user_workid') ?? 'system');
                    break;
                default:
                    return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, sprintf('新增失败,数据模式[-%s-]错误', $config['dataModel']));
            }

            $this->editService->executeAfterInsert($config['afterInsert'], $config['primaryKey'], $request);

            return $this->success([
                'success' => true,
                'message' => sprintf('新增成功,新增 %d 条记录', $num),
            ]);
        } catch (\Throwable $e) {
            log_message('error', '新增记录失败: ' . $e->getMessage());
            log_message('error', '新增记录堆栈: ' . $e->getTraceAsString());
            $errorMsg = ENVIRONMENT === 'development'
                ? sprintf('新增失败: %s', $e->getMessage())
                : '新增失败';
            return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, $errorMsg);
        }
    }

    /**
     * 获取修改字段配置
     */
    public function updateFields(string $functionCode = '')
    {
        try {
            $functionCode = trim($functionCode);
            if ($functionCode === '') {
                return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '功能编码不能为空');
            }

            $payload = $this->request->getJSON(true) ?? [];
            $keyValues = $payload['keys'] ?? [];

            if (empty($keyValues)) {
                return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '未指定要修改的记录');
            }

            $queryConfig = $this->loadQueryConfig($functionCode, '');
            $dataTable  = $queryConfig['dataTable'] ?? '';
            $primaryKey = $this->getPrimaryKey($functionCode, $queryConfig);

            $result = $this->editService->getUpdateFields(
                $functionCode,
                $keyValues,
                $primaryKey,
                $dataTable
            );

            return $this->success($result);
        } catch (\Throwable $e) {
            log_message('error', '获取修改字段配置失败: ' . $e->getMessage());
            return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '获取修改字段配置失败');
        }
    }

    /**
     * 修改记录
     */
    public function updateRow(string $functionCode = '')
    {
        try {
            $functionCode = trim($functionCode);
            if ($functionCode === '') {
                throw new \RuntimeException('功能编码不能为空');
            }

            $payload = $this->request->getJSON(true) ?? [];
            $keyValues = $payload['keys'] ?? [];
            $formData  = $payload['data'] ?? [];

            if (empty($keyValues)) {
                return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '修改失败：未指定要修改的记录');
            }

            $session = \Config\Services::session();
            $userWorkid = $session->get('user_workid') ?? 'system';

            $queryConfig = $this->loadQueryConfig($functionCode, '');
            if (!$queryConfig || ($queryConfig['dataTable'] ?? '') === '') {
                return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '修改失败：未找到数据表配置');
            }
            $dataTable = $queryConfig['dataTable'];
            $dataModel = $queryConfig['dataModel'] ?? '0';

            $primaryKey = $this->getPrimaryKey($functionCode, $queryConfig);
            if (empty($primaryKey)) {
                return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '修改失败：未找到主键字段');
            }

            $num = $this->editService->updateRowByModel(
                $dataTable, $dataModel, $primaryKey, $keyValues, $formData, $userWorkid, $functionCode
            );

            if ($num < 0) {
                return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, sprintf('修改失败,数据模式[-%s-]错误', $dataModel));
            }

            return $this->success([
                'success'      => true,
                'message'      => sprintf('修改成功,修改了 %d 条记录', $num),
                'updatedCount' => $num,
            ]);
        } catch (\RuntimeException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '修改记录失败: ' . $e->getMessage());
            return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '修改失败');
        }
    }

    /**
     * 批量修改记录
     */
    public function batchUpdateRow(string $functionCode = '')
    {
        try {
            $functionCode = trim($functionCode);
            if ($functionCode === '') {
                throw new \RuntimeException('功能编码不能为空');
            }

            $payload = $this->request->getJSON(true) ?? [];
            $keyValues = $payload['keys'] ?? [];
            $formData  = $payload['data'] ?? [];

            if (empty($keyValues)) {
                return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '修改失败：未指定要修改的记录');
            }
            if (empty($formData)) {
                return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '修改失败：没有要更新的字段');
            }

            $session = \Config\Services::session();
            $userWorkid = $session->get('user_workid') ?? 'system';

            $queryConfig = $this->loadQueryConfig($functionCode, '');
            if (!$queryConfig || ($queryConfig['dataTable'] ?? '') === '') {
                return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '修改失败：未找到数据表配置');
            }
            $dataTable = $queryConfig['dataTable'];
            $dataModel = $queryConfig['dataModel'] ?? '0';

            $primaryKey = $this->getPrimaryKey($functionCode, $queryConfig);
            if (empty($primaryKey)) {
                return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '修改失败：未找到主键字段');
            }

            $num = $this->editService->batchUpdateRowsByModel(
                $dataTable, $dataModel, $primaryKey, $keyValues, $formData, $userWorkid, $functionCode
            );

            if ($num < 0) {
                return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, sprintf('修改失败,数据模式[-%s-]错误', $dataModel));
            }

            return $this->success([
                'success'      => true,
                'message'      => sprintf('批量修改成功,修改了 %d 条记录', $num),
                'updatedCount' => $num,
            ]);
        } catch (\RuntimeException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '批量修改记录失败: ' . $e->getMessage());
            return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '批量修改失败');
        }
    }

    /**
     * 删除记录
     */
    public function deleteRow(string $functionCode = '')
    {
        try {
            $functionCode = trim($functionCode);
            if ($functionCode === '') {
                throw new \RuntimeException('功能编码不能为空');
            }

            $payload = $this->request->getJSON(true) ?? [];
            $keyValues = $payload['keys'] ?? [];

            if (empty($keyValues)) {
                return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '删除失败：未指定要删除的记录');
            }

            $session = \Config\Services::session();
            $userWorkid = $session->get('user_workid') ?? 'system';

            $queryConfig = $this->loadQueryConfig($functionCode, '');
            if (!$queryConfig || ($queryConfig['dataTable'] ?? '') === '') {
                return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '删除失败：未找到数据表配置');
            }
            $dataTable = $queryConfig['dataTable'];
            $dataModel = $queryConfig['dataModel'] ?? '0';

            $primaryKey = $this->getPrimaryKey($functionCode, $queryConfig);
            if (empty($primaryKey)) {
                return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '删除失败：未找到主键字段');
            }

            $num = $this->editService->deleteRowByModel(
                $dataTable, $dataModel, $primaryKey, $keyValues, $userWorkid, $functionCode
            );

            if ($num < 0) {
                return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, sprintf('删除失败,数据模式[-%s-]错误', $dataModel));
            }

            return $this->success([
                'success'      => true,
                'message'      => sprintf('删除成功,删除了 %d 条记录', $num),
                'deletedCount' => $num,
            ]);
        } catch (\RuntimeException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '删除记录失败: ' . $e->getMessage());
            return $this->error(ApiCode::WORKBENCH_TABLE_CONFIG_MISSING, '删除失败');
        }
    }
}
