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
            // UI 主题：default | dark | light | auto，前端由 isDarkMode 推导
            $uiTheme = (string) ($data['uiTheme'] ?? $this->request->getGet('uiTheme') ?? 'default');
            $allowedThemes = ['default', 'dark', 'light', 'auto'];
            if (!in_array($uiTheme, $allowedThemes, true)) {
                $uiTheme = 'default';
            }
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

            $config = $this->onlyOfficeService->getEditorConfig($documentId, $userId, $userName, $callbackUrl, $uiTheme);
            $steps['getEditorConfig'] = hrtime(true);
            log_message('debug', '[OnlyOfficeCallback::config] customization=' . json_encode($config['editorConfig']['customization'] ?? [], JSON_UNESCAPED_UNICODE));

            $logMsg = $this->buildPerformanceTable('[OnlyOfficeCallback::config]', '成功', 'docId=' . $documentId . ' uiTheme=' . $uiTheme, $steps, $t0);
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

            $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            log_message('info', '[OnlyOfficeCallback::download] 请求到达 - documentId=' . $documentId . ', token=' . (empty($token) ? 'empty' : 'present') . ', IP=' . $clientIp . ', UA=' . $userAgent);

            if ($documentId <= 0) {
                log_message('error', '[OnlyOfficeCallback::download] 参数错误 - documentId=' . $documentId . ', IP=' . $clientIp);
                return $this->response->setStatusCode(400)->setBody('Bad Request: invalid documentId');
            }

            // Token 鉴权（OnlyOffice 服务器下载文件时使用）
            if (!empty($token)) {
                $payload = $this->onlyOfficeService->verifyJwt($token);
                if ($payload === null) {
                    log_message('error', '[OnlyOfficeCallback::download] Token 验证失败 - documentId=' . $documentId . ', IP=' . $clientIp);
                    return $this->response->setStatusCode(403)->setBody('Forbidden: invalid token');
                }
                if (((int) ($payload['documentId'] ?? 0)) !== $documentId) {
                    log_message('error', '[OnlyOfficeCallback::download] Token documentId 不匹配 - tokenDocId=' . ($payload['documentId'] ?? 'null') . ', requested=' . $documentId . ', IP=' . $clientIp);
                    return $this->response->setStatusCode(403)->setBody('Forbidden: token documentId mismatch');
                }
                // 检查 token 是否过期
                $exp = (int) ($payload['exp'] ?? 0);
                if ($exp > 0 && $exp < time()) {
                    log_message('error', '[OnlyOfficeCallback::download] Token 已过期 - documentId=' . $documentId . ', exp=' . date('Y-m-d H:i:s', $exp) . ', IP=' . $clientIp);
                    return $this->response->setStatusCode(403)->setBody('Forbidden: token expired');
                }
            } else {
                // 无 token 时走用户登录态（浏览器直接下载时使用）
                try {
                    $userId = $this->getUserWorkId();
                    if (empty($userId)) {
                        log_message('error', '[OnlyOfficeCallback::download] 未登录 - IP=' . $clientIp);
                        return $this->response->setStatusCode(401)->setBody('Unauthorized: please login');
                    }
                } catch (\Throwable $e) {
                    log_message('error', '[OnlyOfficeCallback::download] 获取用户信息失败 - ' . $e->getMessage() . ', IP=' . $clientIp);
                    return $this->response->setStatusCode(401)->setBody('Unauthorized: please login');
                }
            }
            $steps['鉴权'] = hrtime(true);

            $document = $this->getDocumentById($documentId);
            if (!$document) {
                log_message('error', '[OnlyOfficeCallback::download] 文档不存在 - documentId=' . $documentId . ', IP=' . $clientIp);
                return $this->response->setStatusCode(404)->setBody('Not Found: document not found');
            }
            $steps['查询文档'] = hrtime(true);

            $filePath = WRITEPATH . ($document['文件路径'] ?? '');
            log_message('debug', '[OnlyOfficeCallback::download] 文件路径=' . $filePath . ', 文件存在=' . (file_exists($filePath) ? 'true' : 'false'));

            if (!file_exists($filePath) || !is_file($filePath)) {
                log_message('error', '[OnlyOfficeCallback::download] 文件不存在 - filePath=' . $filePath . ', documentId=' . $documentId);
                return $this->response->setStatusCode(404)->setBody('Not Found: file not found');
            }

            $fileName = $document['文档名称'] ?? 'document';
            $fileExt = $document['文档格式'] ?? pathinfo($fileName, PATHINFO_EXTENSION);
            if (empty($fileExt)) {
                $fileExt = pathinfo($filePath, PATHINFO_EXTENSION);
            }

            $mimeType = $this->getMimeType($fileExt);
            $fileSize = filesize($filePath);
            $steps['准备文件信息'] = hrtime(true);

            log_message('info', '[OnlyOfficeCallback::download] 准备返回文件 - fileName=' . $fileName . ', fileExt=' . $fileExt . ', mimeType=' . $mimeType . ', fileSize=' . $fileSize . ', IP=' . $clientIp);

            // 处理 Range 请求（OnlyOffice 可能使用 Range 请求大文件）
            $range = $this->request->getServer('HTTP_RANGE') ?? '';
            if (!empty($range) && preg_match('/bytes=(\d+)-(\d*)?/', $range, $matches)) {
                $start = (int) $matches[1];
                $end = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : $fileSize - 1;
                $end = min($end, $fileSize - 1);

                if ($start > $end || $start >= $fileSize) {
                    return $this->response->setStatusCode(416)->setHeader('Content-Range', 'bytes */' . $fileSize)->setBody('');
                }

                $length = $end - $start + 1;
                $fileContent = file_get_contents($filePath, false, null, $start, $length);
                $steps['读取文件(Range)'] = hrtime(true);

                $logMsg = $this->buildPerformanceTable('[OnlyOfficeCallback::download]', '成功(Range)', 'docId=' . $documentId . ' start=' . $start . ' end=' . $end . ' size=' . $length, $steps, $t0);
                log_message('info', $logMsg);

                return $this->response
                    ->setStatusCode(206)
                    ->setHeader('Content-Type', $mimeType)
                    ->setHeader('Content-Disposition', 'inline; filename*=UTF-8\'\'' . rawurlencode($fileName . '.' . $fileExt))
                    ->setHeader('Content-Length', (string) $length)
                    ->setHeader('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $fileSize)
                    ->setHeader('Accept-Ranges', 'bytes')
                    ->setBody($fileContent);
            }

            $fileContent = file_get_contents($filePath);
            $steps['读取文件'] = hrtime(true);

            $logMsg = $this->buildPerformanceTable('[OnlyOfficeCallback::download]', '成功', 'docId=' . $documentId . ' size=' . $fileSize, $steps, $t0);
            log_message('info', $logMsg);

            return $this->response
                ->setStatusCode(200)
                ->setHeader('Content-Type', $mimeType)
                ->setHeader('Content-Disposition', 'inline; filename*=UTF-8\'\'' . rawurlencode($fileName . '.' . $fileExt))
                ->setHeader('Content-Length', (string) $fileSize)
                ->setHeader('Accept-Ranges', 'bytes')
                ->setBody($fileContent);
        } catch (\Throwable $e) {
            $steps['异常'] = hrtime(true);
            $logMsg = $this->buildPerformanceTable('[OnlyOfficeCallback::download]', '失败', 'docId=' . ($documentId ?? 0) . ' err=' . $e->getMessage(), $steps, $t0);
            log_message('error', $logMsg);
            log_message('error', '[OnlyOfficeCallback::download] 异常: ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setBody('Internal Server Error');
        }
    }

    private function getDocumentById(int $documentId): ?array
    {
        $sql = sprintf(
            'select * from `def_contract_document` where `GUID`=%d and `删除标识`=%s limit 1',
            $documentId,
            $this->model->quote('0')
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

    /**
     * 获取合同文档修改时间线
     * GET /onlyoffice/timeline?contractNo=xxx
     */
    public function timeline()
    {
        $t0 = hrtime(true);
        $steps = [];

        try {
            $contractNo = $this->request->getGet('contractNo') ?? '';
            $steps['解析参数'] = hrtime(true);

            if (empty($contractNo)) {
                return $this->paramError('contractNo 不能为空');
            }

            $timeline = $this->onlyOfficeService->getDocumentTimeline($contractNo);
            $steps['查询时间线'] = hrtime(true);

            $logMsg = $this->buildPerformanceTable(
                '[OnlyOfficeCallback::timeline]',
                '成功',
                'contractNo=' . $contractNo . ' count=' . count($timeline),
                $steps,
                $t0
            );
            log_message('debug', $logMsg);

            return $this->success($timeline);
        } catch (\Throwable $e) {
            $steps['异常'] = hrtime(true);
            $logMsg = $this->buildPerformanceTable(
                '[OnlyOfficeCallback::timeline]',
                '失败',
                'contractNo=' . ($contractNo ?? '') . ' err=' . $e->getMessage(),
                $steps,
                $t0
            );
            log_message('error', $logMsg);
            return $this->serverError($e->getMessage());
        }
    }
}
