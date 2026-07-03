<?php

namespace App\Controllers\Workbench;

use App\Constants\ApiCode;
use App\Controllers\BaseApiController;
use App\Exceptions\AuthException;
use App\Exceptions\BusinessException;
use App\Exceptions\ValidationException;
use App\Services\Workbench\ImportService;

/**
 * 工作台导入控制器
 *
 * 负责处理工作台数据导入（Excel/CSV 批量导入）相关接口。
 * 所有业务逻辑均下沉至 App\Services\Workbench\ImportService，
 * 本控制器仅负责请求/响应编排。
 */
class WorkbenchImportController extends BaseApiController
{
    use WorkbenchResponseTrait;

    private ImportService $importService;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ) {
        parent::initController($request, $response, $logger);
        $this->importService = new ImportService();
    }

    /**
     * 获取导入调试 SQL
     */
    public function importDebug(string $functionCode = '')
    {
        try {
            $functionCode = trim($functionCode);
            if ($functionCode === '') {
                throw new ValidationException('功能编码不能为空');
            }

            $payload = $this->request->getJSON(true) ?? [];
            $menu1 = $payload['menu1'] ?? '';
            $menu2 = $payload['menu2'] ?? '';
            $userWorkid = $payload['userWorkid'] ?? '';
            $sampleData = $payload['sampleData'] ?? [];

            $queryConfig = $this->loadQueryConfig($functionCode, '');
            $dataTable = $queryConfig['dataTable'] ?? '';
            $importModule = $queryConfig['importModule'] ?? '';

            if ($dataTable === '') {
                return $this->success([
                    'success' => true,
                    'message' => '当前功能未配置数据表，无导入调试信息',
                    'dataTable' => '',
                    'importModule' => $importModule,
                    'tmpTableName' => '',
                    'importColumns' => [],
                    'createTempTableSql' => '',
                    'insertToTempTableSql' => '',
                    'importFromTempTableSql' => ''
                ]);
            }

            $debugResult = $this->importService->buildDebugImport(
                $functionCode,
                $menu1,
                $menu2,
                $userWorkid,
                $dataTable,
                $importModule,
                $sampleData
            );

            return $this->success($debugResult);
        } catch (AuthException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (ValidationException $e) {
            return $this->error(ApiCode::PARAM_ERROR, $e->getMessage());
        } catch (BusinessException $e) {
            return $this->error(ApiCode::BUSINESS_ERROR, $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '获取导入调试 SQL 失败: ' . $e->getMessage());
            return $this->error(ApiCode::SERVER_ERROR, '获取导入调试 SQL 失败');
        }
    }

    /**
     * 获取导入列配置
     */
    public function importColumns(string $functionCode = '')
    {
        try {
            $functionCode = trim($functionCode);
            if ($functionCode === '') {
                throw new ValidationException('功能编码不能为空');
            }

            $result = $this->importService->getImportColumns($functionCode);

            return $this->success(['columns' => $result['columns'] ?? []]);
        } catch (AuthException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (ValidationException $e) {
            return $this->error(ApiCode::PARAM_ERROR, $e->getMessage());
        } catch (BusinessException $e) {
            return $this->error(ApiCode::BUSINESS_ERROR, $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '获取导入列配置失败: ' . $e->getMessage());
            return $this->error(ApiCode::SERVER_ERROR, '获取导入列配置失败');
        }
    }

    /**
     * 执行导入
     */
    public function import(string $functionCode = '')
    {
        try {
            $functionCode = trim($functionCode);
            if ($functionCode === '') {
                throw new ValidationException('功能编码不能为空');
            }

            // 1. 解析请求
            $payload = $this->request->getJSON(true) ?? [];
            $importData = $payload['data'] ?? [];
            if (empty($importData)) {
                throw new ValidationException('导入数据不能为空');
            }

            $userWorkid   = $this->userContext->getWorkId();
            $userLocation = $this->userContext->getLocation();
            $session = \Config\Services::session();
            $menu1 = $session->get($functionCode . '-menu_1') ?? '';
            $menu2 = $session->get($functionCode . '-menu_2') ?? '';

            $systemVars = [
                '$时间戳' => date('Y-m-d H:i:s'),
                '$工号'   => $userWorkid,
                '$属地'   => $userLocation,
            ];

            // 2. 加载查询配置
            $queryConfig = $this->loadQueryConfig($functionCode, '');
            if (!$queryConfig || ($queryConfig['dataTable'] ?? '') === '') {
                throw new BusinessException('未找到数据表配置');
            }
            $dataTable    = $queryConfig['dataTable'];
            $importModule = $queryConfig['importModule'] ?? '';

            // 3. 调 Service 拿导入列配置 + 字段映射 + 必填列
            $importConfig = $this->importService->getImportConfig(
                $functionCode,
                $menu1,
                $menu2,
                $userWorkid,
                $dataTable,
                $importModule
            );
            $importColumns   = $importConfig['importColumns'];
            $fieldMap        = $importConfig['fieldMap'];
            $requiredColumns = $importConfig['requiredColumns'];
            $tmpTableName    = $importConfig['tmpTableName'];

            // 4. 行级预校验（firstRow 缺失检查 + 行必填 + 系统变量填充）
            $rowCheck = $this->importService->validateImportData(
                $importData, $fieldMap, $requiredColumns, $systemVars
            );
            if ($rowCheck['hasError']) {
                return $this->success([
                    'success'      => false,
                    'message'      => $rowCheck['message'],
                    'total'        => count($importData),
                    'successCount' => 0,
                    'errorCount'   => count($importData),
                    'errors'       => $rowCheck['errors'] ?? [],
                ]);
            }
            $validData = $rowCheck['validData'];

            // 5. 写临时表
            if (!$this->importService->createTempTable($tmpTableName, $importColumns)) {
                return $this->success($this->importService->buildImportFailure($importData, '导入失败：创建临时表失败'));
            }
            if (!$this->importService->insertToTempTable($tmpTableName, $validData, $importColumns)) {
                $this->importService->dropTempTable($tmpTableName);
                return $this->success($this->importService->buildImportFailure($importData, '导入失败：插入临时表失败'));
            }

            // 6. 表级二次校验（固定值 / 条件 / 日期）
            $tableCheck = $this->importService->validateImportDataByTable(
                $tmpTableName, $importColumns, $userLocation
            );
            if ($tableCheck['hasError']) {
                $this->importService->dropTempTable($tmpTableName);
                return $this->success([
                    'success'      => false,
                    'message'      => $tableCheck['message'],
                    'total'        => count($importData),
                    'successCount' => 0,
                    'errorCount'   => count($importData),
                    'errors'       => $tableCheck['errors'] ?? [],
                ]);
            }

            // 7. 滤重检查
            if ($importModule !== '') {
                $dupCheck = $this->importService->checkDuplicateFields(
                    $importModule, $dataTable, $tmpTableName
                );
                if ($dupCheck['hasError']) {
                    $this->importService->dropTempTable($tmpTableName);
                    return $this->success([
                        'success'      => false,
                        'message'      => $dupCheck['message'],
                        'total'        => count($importData),
                        'successCount' => 0,
                        'errorCount'   => count($importData),
                        'errors'       => $dupCheck['errors'] ?? [],
                    ]);
                }
            }

            // 8. 导入前处理
            if ($importModule !== '') {
                $beforeProcessResult = $this->importService->executeBeforeProcess($importModule);
                if (!$beforeProcessResult['success']) {
                    $this->importService->dropTempTable($tmpTableName);
                    return $this->success([
                        'success'      => false,
                        'message'      => $beforeProcessResult['message'],
                        'total'        => count($importData),
                        'successCount' => 0,
                        'errorCount'   => count($importData),
                        'errors'       => [['error' => $beforeProcessResult['message']]],
                    ]);
                }
            }

            // 9. 正式导入（INSERT INTO ... SELECT）
            $importResult = $this->importService->importFromTempTable(
                $dataTable, $tmpTableName, $importColumns
            );

            if ($importResult['success']) {
                if ($importModule !== '') {
                    $this->importService->executeAfterProcess($importModule);
                }
                $this->importService->dropTempTable($tmpTableName);
                return $this->success([
                    'success'      => true,
                    'message'      => $importResult['message'],
                    'total'        => count($importData),
                    'successCount' => $importResult['count'],
                    'errorCount'   => 0,
                    'errors'       => [],
                ]);
            }

            // 保留临时表用于调试
            return $this->success([
                'success'      => false,
                'message'      => $importResult['message'],
                'total'        => count($importData),
                'successCount' => 0,
                'errorCount'   => $importResult['count'],
                'errors'       => $importResult['errors'] ?? [],
            ]);
        } catch (AuthException $e) {
            return $this->error(ApiCode::AUTH_UNAUTHORIZED, $e->getMessage());
        } catch (ValidationException $e) {
            return $this->error(ApiCode::PARAM_ERROR, $e->getMessage());
        } catch (BusinessException $e) {
            return $this->error(ApiCode::BUSINESS_ERROR, $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '导入数据失败: ' . $e->getMessage());
            return $this->error(ApiCode::SERVER_ERROR, '导入数据失败');
        }
    }
}
