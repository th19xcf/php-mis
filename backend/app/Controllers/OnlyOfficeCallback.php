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

            $token = $this->request->getHeaderLine('Authorization');
            if (empty($token)) {
                $token = $callbackData['token'] ?? '';
            } else {
                if (stripos($token, 'Bearer ') === 0) {
                    $token = substr($token, 7);
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

            if (!empty($token)) {
                $callbackData['token'] = $token;
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
        try {
            $data = $this->getJsonInput();
            $documentId = (int) ($data['documentId'] ?? $this->request->getGet('documentId') ?? 0);

            if ($documentId <= 0) {
                return $this->paramError('documentId 不能为空');
            }

            $userId = $this->getUserWorkId();
            $userName = $this->getUserName();

            $callbackUrl = site_url('onlyoffice/callback');

            $config = $this->onlyOfficeService->getEditorConfig($documentId, $userId, $userName, $callbackUrl);

            return $this->success($config);
        } catch (\Throwable $e) {
            log_message('error', '[OnlyOfficeCallback::config] ' . $e->getMessage());
            return $this->serverError($e->getMessage());
        }
    }

    public function download()
    {
        try {
            $documentId = (int) ($this->request->getGet('documentId') ?? 0);
            $token = $this->request->getGet('token') ?? '';

            if ($documentId <= 0) {
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

            $document = $this->getDocumentById($documentId);
            if (!$document) {
                return $this->notFound('文档不存在');
            }

            $filePath = WRITEPATH . ($document['文件路径'] ?? '');
            if (!file_exists($filePath) || !is_file($filePath)) {
                return $this->notFound('文档文件不存在');
            }

            $fileName = $document['文档名称'] ?? 'document';
            $fileExt = $document['文档格式'] ?? pathinfo($fileName, PATHINFO_EXTENSION);
            if (empty($fileExt)) {
                $fileExt = pathinfo($filePath, PATHINFO_EXTENSION);
            }

            $mimeType = $this->getMimeType($fileExt);
            $fileSize = filesize($filePath);

            return $this->response
                ->setHeader('Content-Type', $mimeType)
                ->setHeader('Content-Disposition', 'attachment; filename="' . rawurlencode($fileName) . '"')
                ->setHeader('Content-Length', (string) $fileSize)
                ->setHeader('Accept-Ranges', 'bytes')
                ->setBody(file_get_contents($filePath));
        } catch (\Throwable $e) {
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
