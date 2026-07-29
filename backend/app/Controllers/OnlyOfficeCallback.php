<?php

namespace App\Controllers;

use App\Services\OnlyOffice\OnlyOfficeService;

class OnlyOfficeCallback extends BaseApiController
{
    private OnlyOfficeService $onlyOfficeService;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);

        $this->onlyOfficeService = new OnlyOfficeService();
    }

    public function index()
    {
        try {
            $callbackData = $this->getJsonInput();

            // 记录原始 callback 请求，便于排查
            log_message('debug', '[OnlyOfficeCallback::index] 原始请求: ' . json_encode($callbackData, JSON_UNESCAPED_UNICODE));

            // 获取 JWT token（Authorization header 或 body 中的 token 字段）
            $token = $this->request->getHeaderLine('Authorization');
            if (empty($token)) {
                $token = $callbackData['token'] ?? '';
            } else {
                if (stripos($token, 'Bearer ') === 0) {
                    $token = substr($token, 7);
                }
            }

            // JWT 启用时，OnlyOffice 将 payload（含 key/status/url）封装在 token 中
            // 必须先验证并解码 JWT，才能获取 documentKey 等字段
            if (!empty($token)) {
                $payload = $this->onlyOfficeService->verifyJwt($token);
                if ($payload !== null) {
                    // 合并解密后的 payload 到 callbackData（解密字段优先）
                    $callbackData = array_merge($callbackData, $payload);
                    $callbackData['token'] = $token;
                    log_message('debug', '[OnlyOfficeCallback::index] JWT 验证成功，已合并 payload');
                } else {
                    log_message('error', '[OnlyOfficeCallback::index] JWT 验证失败');
                }
            }

            $documentKey = $callbackData['key'] ?? '';
            if (empty($documentKey)) {
                $documentKey = $this->request->getGet('key') ?? '';
            }

            if (empty($documentKey)) {
                log_message('error', '[OnlyOfficeCallback::index] 缺少 documentKey');
                return $this->response->setJSON(['error' => 1]);
            }

            $result = $this->onlyOfficeService->handleCallback($callbackData, $documentKey);

            return $this->response->setJSON($result);
        } catch (\Throwable $e) {
            log_message('error', '[OnlyOfficeCallback::index] ' . $e->getMessage());
            return $this->response->setJSON(['error' => 1]);
        }
    }

    public function config()
    {
        $t0 = hrtime(true);
        $steps = [];

        try {
            $data = $this->getJsonInput();
            $documentId = (int) ($data['documentId'] ?? $this->request->getGet('documentId') ?? 0);
            $steps['解析参数'] = hrtime(true);

            if ($documentId <= 0) {
                return $this->paramError('documentId 不能为空');
            }

            try {
                $userId = $this->getUserWorkId();
                $userName = $this->getUserName();
            } catch (\Throwable $e) {
                log_message('debug', '[OnlyOfficeCallback::config] 使用默认用户，原因: ' . $e->getMessage());
                $userId = 'system';
                $userName = '系统用户';
            }
            $steps['获取用户信息'] = hrtime(true);

            $backendUrl = env('onlyoffice.backendUrl', '');
            if (!empty($backendUrl)) {
                $callbackUrl = rtrim($backendUrl, '/') . '/onlyoffice/callback';
            } else {
                $protocol = $this->request->getServer('HTTPS') === 'on' ? 'https' : 'http';
                $host = $this->request->getServer('HTTP_HOST');
                $callbackUrl = $protocol . '://' . $host . '/onlyoffice/callback';
            }
            $steps['构建callbackUrl'] = hrtime(true);

            $config = $this->onlyOfficeService->getEditorConfig($documentId, $userId, $userName, $callbackUrl);
            $steps['getEditorConfig'] = hrtime(true);

            $logMsg = $this->buildPerformanceTable('[OnlyOfficeCallback::config]', '成功', 'docId=' . $documentId, $steps, $t0);
            log_message('debug', $logMsg);

            return $this->success($config);
        } catch (\Throwable $e) {
            $steps['异常'] = hrtime(true);
            $logMsg = $this->buildPerformanceTable('[OnlyOfficeCallback::config]', '失败', 'docId=' . ($documentId ?? 0) . ' err=' . $e->getMessage(), $steps, $t0);
            log_message('error', $logMsg);
            log_message('error', '[OnlyOfficeCallback::config] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function download()
    {
        $t0 = hrtime(true);
        $steps = [];

        try {
            $documentId = (int) ($this->request->getGet('id') ?? $this->request->getGet('documentId') ?? 0);
            $token = $this->request->getGet('token') ?? '';
            $steps['解析参数'] = hrtime(true);

            log_message('debug', '[OnlyOfficeCallback::download] 请求到达 - documentId=' . $documentId . ', token=' . (empty($token) ? 'empty' : 'present') . ', IP=' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

            if ($documentId <= 0) {
                log_message('debug', '[OnlyOfficeCallback::download] 参数错误 - documentId=' . $documentId);
                return $this->paramError('documentId 不能为空');
            }

            if (!empty($token)) {
                $payload = $this->onlyOfficeService->verifyJwt($token);
                if ($payload === null || ((int) ($payload['documentId'] ?? 0)) !== $documentId) {
                    return $this->businessError('下载链接无效或已过期');
                }
            } else {
                try {
                    $userId = $this->getUserWorkId();
                    if (empty($userId)) {
                        return $this->businessError('请先登录');
                    }
                } catch (\Throwable $e) {
                    return $this->businessError('请先登录');
                }
            }
            $steps['鉴权'] = hrtime(true);

            $document = $this->getDocumentById($documentId);
            if (!$document) {
                log_message('debug', '[OnlyOfficeCallback::download] 文档不存在 - documentId=' . $documentId);
                return $this->notFound('文档不存在');
            }
            $steps['查询文档'] = hrtime(true);

            $filePath = WRITEPATH . ($document['文件路径'] ?? '');
            log_message('debug', '[OnlyOfficeCallback::download] 文件路径=' . $filePath . ', 文件存在=' . (file_exists($filePath) ? 'true' : 'false'));

            if (!file_exists($filePath) || !is_file($filePath)) {
                log_message('debug', '[OnlyOfficeCallback::download] 文件不存在 - filePath=' . $filePath);
                return $this->notFound('文档文件不存在');
            }

            $fileName = $document['文档名称'] ?? 'document';
            $fileExt = $document['文档格式'] ?? pathinfo($fileName, PATHINFO_EXTENSION);
            if (empty($fileExt)) {
                $fileExt = pathinfo($filePath, PATHINFO_EXTENSION);
            }

            $mimeType = $this->getMimeType($fileExt);
            $fileSize = filesize($filePath);
            $steps['准备文件信息'] = hrtime(true);

            log_message('debug', '[OnlyOfficeCallback::download] 准备返回文件 - fileName=' . $fileName . ', fileExt=' . $fileExt . ', mimeType=' . $mimeType . ', fileSize=' . $fileSize);

            $fileContent = file_get_contents($filePath);
            $steps['读取文件'] = hrtime(true);

            $logMsg = $this->buildPerformanceTable('[OnlyOfficeCallback::download]', '成功', 'docId=' . $documentId . ' size=' . $fileSize, $steps, $t0);
            log_message('debug', $logMsg);

            return $this->response
                ->setHeader('Content-Type', $mimeType)
                ->setHeader('Content-Disposition', 'attachment; filename="' . rawurlencode($fileName) . '"')
                ->setHeader('Content-Length', (string) $fileSize)
                ->setHeader('Accept-Ranges', 'bytes')
                ->setBody($fileContent);
        } catch (\Throwable $e) {
            $steps['异常'] = hrtime(true);
            $logMsg = $this->buildPerformanceTable('[OnlyOfficeCallback::download]', '失败', 'docId=' . ($documentId ?? 0) . ' err=' . $e->getMessage(), $steps, $t0);
            log_message('error', $logMsg);
            log_message('error', '[OnlyOfficeCallback::download] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    private function getDocumentById(int $documentId): ?array
    {
        $sql = sprintf(
            'select * from `def_contract_document` where `GUID`=%d and `删除标识`=%s limit 1',
            $documentId,
            '"0"'
        );

        $result = $this->model->select($sql);
        $document = $result ? ($result->getRowArray() ?: null) : null;

        return $document;
    }

    private function getMimeType(string $ext): string
    {
        $ext = strtolower($ext);
        $mimeTypes = [
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
        ];

        return $mimeTypes[$ext] ?? 'application/octet-stream';
    }
}
