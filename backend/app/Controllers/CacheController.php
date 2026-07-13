<?php

namespace App\Controllers;

use App\Constants\ApiCode;
use App\Libraries\MetadataCache;
use App\Libraries\SessionUserContext;
use App\Services\Workbench\ContextService;
use CodeIgniter\Controller;

class CacheController extends Controller
{
    private MetadataCache $metadataCache;
    private SessionUserContext $userContext;
    private ContextService $contextService;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ) {
        parent::initController($request, $response, $logger);
        $this->metadataCache = new MetadataCache();
        $this->userContext = new SessionUserContext();
        $this->contextService = new ContextService();
    }

    /**
     * 清除指定表的元数据缓存（联动清除所有工作台上下文缓存）
     *
     * @return \CodeIgniter\HTTP\JSONResponse
     */
    public function invalidateTable()
    {
        try {
            $user = $this->userContext->requireLogin();

            $tableName = $this->request->getPost('tableName');
            if (empty($tableName)) {
                return $this->fail('表名不能为空');
            }

            $validTables = ['def_query_column', 'def_chart_drill_config', 'def_query_config', 'def_user', 'def_function'];
            if (!in_array($tableName, $validTables)) {
                return $this->fail('无效的表名，支持的表：' . implode(', ', $validTables));
            }

            $this->metadataCache->invalidateTable($tableName);

            $contextCleared = $this->contextService->clearCache();

            log_message('info', sprintf(
                '[CacheController] 用户 %s(%s) 清除了表 %s 的缓存，上下文缓存已联动清除',
                $user['userName'],
                $user['workId'],
                $tableName
            ));

            return $this->success('缓存已失效', [
                'tableName' => $tableName,
                'contextCleared' => $contextCleared,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[CacheController] invalidateTable 失败: ' . $e->getMessage());
            return $this->fail('操作失败: ' . $e->getMessage());
        }
    }

    /**
     * 清除所有缓存（元数据 + 工作台上下文）
     *
     * @return \CodeIgniter\HTTP\JSONResponse
     */
    public function invalidateAll()
    {
        try {
            $user = $this->userContext->requireLogin();

            $this->metadataCache->invalidateAll();
            $contextCleared = $this->contextService->clearCache();

            log_message('info', sprintf(
                '[CacheController] 用户 %s(%s) 清除了所有缓存（元数据 + 上下文）',
                $user['userName'],
                $user['workId']
            ));

            return $this->success('所有缓存已失效', [
                'contextCleared' => $contextCleared,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[CacheController] invalidateAll 失败: ' . $e->getMessage());
            return $this->fail('操作失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取当前缓存状态
     *
     * @return \CodeIgniter\HTTP\JSONResponse
     */
    public function status()
    {
        try {
            $this->userContext->requireLogin();

            $status = [
                'cachePrefix' => 'metadata_',
                'supportedTables' => ['def_query_column', 'def_chart_drill_config', 'def_query_config', 'def_user', 'def_function'],
                'ttlSeconds' => [
                    'def_query_column' => 3600,
                    'def_chart_drill_config' => 3600,
                    'def_query_config' => 7200,
                    'def_user' => 1800,
                    'def_function' => 7200,
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ];

            return $this->success('查询成功', $status);
        } catch (\Throwable $e) {
            log_message('error', '[CacheController] status 失败: ' . $e->getMessage());
            return $this->fail('操作失败: ' . $e->getMessage());
        }
    }

    /**
     * 成功响应（遵循全站标准结构 {code, msg, data}）
     */
    private function success(string $message, array $data = []): \CodeIgniter\HTTP\Response
    {
        return $this->response->setJSON([
            'code' => ApiCode::SUCCESS,
            'msg' => $message,
            'data' => $data
        ]);
    }

    /**
     * 失败响应（遵循全站标准结构 {code, msg}）
     */
    private function fail(string $message): \CodeIgniter\HTTP\Response
    {
        return $this->response->setJSON([
            'code' => ApiCode::BUSINESS_ERROR,
            'msg' => $message,
        ]);
    }
}
