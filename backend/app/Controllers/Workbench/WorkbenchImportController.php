<?php

namespace App\Controllers\Workbench;

use App\Constants\ApiCode;
use App\Controllers\BaseApiController;
use App\Exceptions\AuthException;
use App\Exceptions\BusinessException;
use App\Exceptions\ValidationException;
use App\Services\Person\PersonImportService;
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

            $queryConfig = $this->getAuthorizationService()->loadQueryConfig($functionCode, '');
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

            return $this->success([
                'columns' => $result['columns'] ?? [],
                'headerRow' => $result['headerRow'] ?? 1,
                'dataRow' => $result['dataRow'] ?? 2
            ]);
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

            // 从前端获取 menu1/menu2，避免 session 初始化问题
            $menu1 = $payload['menu1'] ?? '';
            $menu2 = $payload['menu2'] ?? '';

            $systemVars = [
                '$时间戳' => date('Y-m-d H:i:s'),
                '$工号'   => $userWorkid,
                '$属地'   => $userLocation,
            ];

            // 2. 加载查询配置
            $queryConfig = $this->getAuthorizationService()->loadQueryConfig($functionCode, '');
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

            // 4.5 字段长度预校验（避免 insertToTempTable 时触发 Data too long）
            $lengthCheck = $this->importService->validateFieldLength($validData, $importColumns);
            if ($lengthCheck['hasError']) {
                return $this->success([
                    'success'      => false,
                    'message'      => $lengthCheck['message'],
                    'total'        => count($importData),
                    'successCount' => 0,
                    'errorCount'   => count($importData),
                    'errors'       => $lengthCheck['errors'],
                ]);
            }

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

            // 7.5 人员主档裁决（def_import_config.主档模块='person' 时启用，两阶段无状态）
            //     阶段一（无 decisions）：批量查重，软命中返回 needConfirm 由前端决策后重提；
            //     阶段二（带 decisions）：按决策挂接/新建，回填临时表 人员编码 列，
            //     importFromTempTable 检测到临时表额外列且目标表有该列时自动写入。
            //     主档字段清单来自 def_import_column.字段归属表='hr_person'（配置驱动，非硬编码）；
            //     发号业务日期来自 def_import_config.业务日期字段（邀约/面试/培训/在职各自配置）。
            $personModule = (string) ($importConfig['personModule'] ?? '');
            if ($personModule === 'person') {
                $personFields = $importConfig['personFields'] ?? [];
                $personDateField = (string) ($importConfig['personDateField'] ?? '邀约日期');
                // 防呆：启用主档裁决但归属配置缺失/不含查重必需字段，直接报配置错误而非建废档
                foreach (['姓名', '手机号码'] as $mustField) {
                    if (!in_array($mustField, $personFields, true)) {
                        return $this->success($this->importService->buildImportFailure(
                            $importData,
                            "导入配置错误：def_import_column 中字段「{$mustField}」未配置 字段归属表='hr_person'，无法进行人员主档查重"
                        ));
                    }
                }

                $personImportService = new PersonImportService();
                $hasDecisions = array_key_exists('decisions', $payload) && is_array($payload['decisions']);

                if (!$hasDecisions) {
                    // 阶段一：批量查重
                    $dedupResult = $personImportService->checkTempTableDedup($tmpTableName, $personFields, $personDateField);
                    if ($dedupResult['hasError']) {
                        $this->importService->dropTempTable($tmpTableName);
                        return $this->success([
                            'success'      => false,
                            'message'      => $dedupResult['message'],
                            'total'        => count($importData),
                            'successCount' => 0,
                            'errorCount'   => count($importData),
                            'errors'       => [['error' => $dedupResult['message']]],
                        ]);
                    }
                    if (!empty($dedupResult['softRows'])) {
                        // 两阶段无状态：删除临时表，阶段二重建后 _seq 与数据顺序天然对齐
                        $this->importService->dropTempTable($tmpTableName);
                        return $this->success([
                            'success'      => false,
                            'needConfirm'  => true,
                            'message'      => sprintf('存在 %d 行疑似重复人员主档，请逐行确认挂接既有档案或新建', count($dedupResult['softRows'])),
                            'softRows'     => $dedupResult['softRows'],
                            'total'        => count($importData),
                            'successCount' => 0,
                            'errorCount'   => 0,
                            'errors'       => [],
                        ]);
                    }
                    // 无软命中：硬命中自动挂档 + 无命中自动新建，直接落地
                    $applyResult = $personImportService->applyPersonToTempTable($tmpTableName, $personFields, [], $userWorkid, $personDateField);
                } else {
                    // 阶段二：按前端决策落地
                    $applyResult = $personImportService->applyPersonToTempTable($tmpTableName, $personFields, $payload['decisions'], $userWorkid, $personDateField);
                }

                if (!$applyResult['success']) {
                    $this->importService->dropTempTable($tmpTableName);
                    return $this->success([
                        'success'      => false,
                        'message'      => $applyResult['message'],
                        'total'        => count($importData),
                        'successCount' => 0,
                        'errorCount'   => count($importData),
                        'errors'       => [['error' => $applyResult['message']]],
                    ]);
                }
            }

            // 8. 导入前处理
            if ($importModule !== '') {
                $beforeProcessResult = $this->importService->executeBeforeProcess($importModule, $tmpTableName);
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

            // 9. 正式导入（INSERT INTO ... SELECT，支持 def_import_config.导入条件 过滤）
            $importResult = $this->importService->importFromTempTable(
                $dataTable, $tmpTableName, $importColumns, $importModule
            );
            log_message('debug', '[WorkbenchImport] importFromTempTable 结果: ' . json_encode($importResult, JSON_UNESCAPED_UNICODE));

            if ($importResult['success']) {
                if ($importModule !== '') {
                    log_message('debug', '[WorkbenchImport] 执行后处理: ' . $importModule);
                    $this->importService->executeAfterProcess($importModule);
                    log_message('debug', '[WorkbenchImport] 后处理完成');
                }
                // 调试：暂时保留临时表，便于排查"导入记录为空值"问题
                log_message('debug', '[WorkbenchImport] 保留临时表用于查错: ' . $tmpTableName);
                $response = $this->success([
                    'success'      => true,
                    'message'      => $importResult['message'],
                    'total'        => count($importData),
                    'successCount' => $importResult['count'],
                    'errorCount'   => 0,
                    'errors'       => [],
                ]);
                log_message('debug', '[WorkbenchImport] 响应体: ' . $response->getBody());
                return $response;
            }

            // 保留临时表用于调试
            log_message('debug', '[WorkbenchImport] 导入失败, 保留临时表, 返回: ' . json_encode($importResult, JSON_UNESCAPED_UNICODE));
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
            log_message('error', '导入数据失败: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            // 开发环境返回具体错误信息（含文件/行号/异常类型），便于排查
            // 生产环境保持笼统提示，避免泄露 SQL/文件路径等敏感信息
            if (env('CI_ENVIRONMENT') === 'development') {
                return $this->error(
                    ApiCode::SERVER_ERROR,
                    sprintf(
                        '导入数据失败：%s（%s:%d）',
                        $e->getMessage(),
                        basename($e->getFile()),
                        $e->getLine()
                    ),
                    ['exception' => get_class($e)]
                );
            }
            return $this->error(ApiCode::SERVER_ERROR, '导入数据失败');
        }
    }
}
