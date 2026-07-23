<?php

namespace App\Services\OnlyOffice;

use App\Models\Mcommon;

class OnlyOfficeService
{
    private Mcommon $model;
    private string $serverUrl;
    private string $jwtSecret;

    public function __construct()
    {
        $this->model = new Mcommon();
        $this->serverUrl = rtrim(env('onlyoffice.serverUrl', ''), '/');
        $this->jwtSecret = env('onlyoffice.jwtSecret', '');
    }

    /**
     * 生成 OnlyOffice 编辑器配置
     *
     * @param int $documentId 文档ID
     * @param string $userId 用户ID
     * @param string $userName 用户名
     * @param string $callbackUrl 回调地址
     * @return array 编辑器配置数组
     * @throws \RuntimeException
     */
    public function getEditorConfig(int $documentId, string $userId, string $userName, string $callbackUrl): array
    {
        $sql = sprintf(
            'select * from `def_contract_document` where `GUID`=%d and `删除标识`=%s limit 1',
            $documentId,
            $this->model->quote('0')
        );
        $result = $this->model->select($sql);
        $document = $result ? ($result->getRowArray() ?: []) : [];

        if (empty($document)) {
            throw new \RuntimeException('文档不存在');
        }

        $fileType = strtolower($document['文档格式'] ?? '');
        if (empty($fileType)) {
            $fileName = $document['文档名称'] ?? '';
            $ext = pathinfo($fileName, PATHINFO_EXTENSION);
            $fileType = strtolower($ext);
        }

        $documentKey = $document['文档密钥'] ?: $this->generateDocumentKey($documentId, (int) ($document['版本号'] ?? 1));

        $downloadUrl = $this->getDownloadUrl($documentId);

        $canEdit = ($document['是否在线编辑'] ?? '0') === '1';
        $mode = $canEdit ? 'edit' : 'view';

        $config = [
            'document' => [
                'fileType' => $fileType,
                'key' => $documentKey,
                'title' => $document['文档名称'] ?? '',
                'url' => $downloadUrl,
                'permissions' => [
                    'edit' => $canEdit,
                    'download' => true,
                    'print' => true,
                    'review' => $canEdit,
                    'comment' => $canEdit,
                    'fillForms' => $canEdit,
                    'modifyFilter' => $canEdit,
                    'modifyContentControl' => $canEdit,
                ],
            ],
            'editorConfig' => [
                'mode' => $mode,
                'lang' => 'zh-CN',
                'user' => [
                    'id' => $userId,
                    'name' => $userName,
                ],
                'callbackUrl' => $callbackUrl,
                'customization' => [
                    'autosave' => true,
                    'forcesave' => false,
                    'commentAuthorOnly' => false,
                    'comments' => true,
                    'compactHeader' => false,
                    'compactToolbar' => false,
                    'help' => true,
                    'hideRightMenu' => false,
                    'toolbarNoTabs' => false,
                    'zoom' => 100,
                ],
            ],
            'height' => '100%',
            'width' => '100%',
            'type' => 'desktop',
        ];

        if (!empty($this->jwtSecret)) {
            $config['token'] = $this->generateJwt($config);
        }

        $now = date('Y-m-d H:i:s');
        $updateSql = sprintf(
            'update `def_contract_document` set `编辑状态`=%s, `最后编辑人`=%s, `最后编辑时间`=%s where `GUID`=%d',
            $this->model->quote('EDITING'),
            $this->model->quote($userId),
            $this->model->quote($now),
            $documentId
        );
        $this->model->exec($updateSql);

        return $config;
    }

    /**
     * 生成 JWT 签名（HS256 算法）
     *
     * @param array $payload 载荷数据
     * @return string JWT token 字符串
     */
    public function generateJwt(array $payload): string
    {
        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT',
        ];

        $headerEncoded = $this->base64urlEncode(json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $payloadEncoded = $this->base64urlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $signature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $this->jwtSecret, true);
        $signatureEncoded = $this->base64urlEncode($signature);

        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }

    /**
     * 验证 JWT 签名
     *
     * @param string $token JWT token 字符串
     * @return array|null payload 数组，验证失败返回 null
     */
    public function verifyJwt(string $token): ?array
    {
        if (empty($token) || empty($this->jwtSecret)) {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

        $signature = $this->base64urlDecode($signatureEncoded);
        $expectedSignature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $this->jwtSecret, true);

        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        $payloadJson = $this->base64urlDecode($payloadEncoded);
        $payload = json_decode($payloadJson, true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * 处理 OnlyOffice 回调
     *
     * @param array $callbackData 回调数据
     * @param string $documentKey 文档密钥
     * @return array OnlyOffice 要求的响应格式 ['error' => 0]
     */
    public function handleCallback(array $callbackData, string $documentKey): array
    {
        $startTime = hrtime(true);
        $status = (int) ($callbackData['status'] ?? 0);
        $callbackToken = $callbackData['token'] ?? '';

        $documentId = 0;
        $userId = '';
        $fileUrl = '';
        $eventName = '';

        $docSql = sprintf(
            'select * from `def_contract_document` where `文档密钥`=%s and `删除标识`=%s limit 1',
            $this->model->quote($documentKey),
            $this->model->quote('0')
        );
        $docResult = $this->model->select($docSql);
        $document = $docResult ? ($docResult->getRowArray() ?: []) : [];
        $documentId = (int) ($document['GUID'] ?? 0);

        if (!empty($callbackToken) && !empty($this->jwtSecret)) {
            $payload = $this->verifyJwt($callbackToken);
            if ($payload === null) {
                $this->logCallback($documentKey, $documentId, $status, 'VERIFY_FAILED', $callbackData, '', '', 'FAILED', 'JWT 验证失败', $startTime);
                return ['error' => 1];
            }
            $callbackData = array_merge($callbackData, $payload);
        }

        $users = $callbackData['users'] ?? [];
        $userId = is_array($users) && !empty($users) ? ($users[0] ?? '') : '';
        $fileUrl = $callbackData['url'] ?? '';
        $actions = $callbackData['actions'] ?? [];
        $actionType = is_array($actions) && !empty($actions) ? ($actions[0]['type'] ?? '') : '';

        $eventMap = [
            0 => 'NOT_FOUND',
            1 => 'EDITING',
            2 => 'SAVE',
            3 => 'SAVE_ERROR',
            4 => 'CLOSED',
            5 => 'FORCE_SAVE',
            6 => 'FORCE_SAVE_RESULT',
            7 => 'FORCE_SAVE_ERROR',
        ];
        $eventName = $eventMap[$status] ?? 'UNKNOWN';

        try {
            switch ($status) {
                case 1:
                    $this->handleEditing($documentId, $userId);
                    break;
                case 2:
                    $this->handleSave($documentId, $fileUrl, $userId);
                    break;
                case 3:
                    $this->handleSaveError($documentId, $callbackData);
                    break;
                case 4:
                    $this->handleClose($documentId, $userId);
                    break;
                case 6:
                    $this->handleForceSave($documentId, $fileUrl, $userId);
                    break;
                case 7:
                    $this->handleForceSaveError($documentId, $callbackData);
                    break;
            }

            $this->logCallback($documentKey, $documentId, $status, $eventName, $callbackData, $userId, $actionType, 'SUCCESS', '', $startTime, $fileUrl);

            return ['error' => 0];
        } catch (\Throwable $e) {
            $this->logCallback($documentKey, $documentId, $status, $eventName, $callbackData, $userId, $actionType, 'FAILED', $e->getMessage(), $startTime, $fileUrl);
            return ['error' => 1];
        }
    }

    /**
     * 创建文档
     *
     * @param array $data 文档数据
     * @return int 文档 GUID
     * @throws \RuntimeException
     */
    public function createDocument(array $data): int
    {
        if (empty($data['合同编号'])) {
            throw new \RuntimeException('合同编号不能为空');
        }
        if (empty($data['文档名称'])) {
            throw new \RuntimeException('文档名称不能为空');
        }

        $documentNo = $this->generateDocumentNo();
        $now = date('Y-m-d H:i:s');
        $version = 1;
        $documentKey = '';

        $fields = [
            '`文档编号`',
            '`合同编号`',
            '`文档名称`',
            '`文档类型`',
            '`文档格式`',
            '`版本号`',
            '`最新版本`',
            '`是否在线编辑`',
            '`编辑状态`',
            '`备注`',
            '`创建人`',
            '`创建时间`',
            '`更新人`',
            '`更新时间`',
            '`删除标识`',
            '`有效标识`',
        ];

        $values = [
            $this->model->quote($documentNo),
            $this->model->quote($data['合同编号']),
            $this->model->quote($data['文档名称']),
            $this->model->quote($data['文档类型'] ?? 'MAIN'),
            $this->model->quote($data['文档格式'] ?? ''),
            $this->model->quote((string) $version),
            $this->model->quote('1'),
            $this->model->quote($data['是否在线编辑'] ?? '1'),
            $this->model->quote('IDLE'),
            $this->model->quote($data['备注'] ?? ''),
            $this->model->quote($data['创建人'] ?? ''),
            $this->model->quote($now),
            $this->model->quote($data['创建人'] ?? ''),
            $this->model->quote($now),
            $this->model->quote('0'),
            $this->model->quote('1'),
        ];

        $sql = sprintf(
            'insert into `def_contract_document` (%s) values (%s)',
            implode(', ', $fields),
            implode(', ', $values)
        );
        $this->model->exec($sql);

        $db = db_connect('btdc');
        $documentId = (int) $db->insertID();

        $documentKey = $this->generateDocumentKey($documentId, $version);
        $updateSql = sprintf(
            'update `def_contract_document` set `文档密钥`=%s where `GUID`=%d',
            $this->model->quote($documentKey),
            $documentId
        );
        $this->model->exec($updateSql);

        return $documentId;
    }

    /**
     * 上传文档文件
     *
     * @param int $documentId 文档ID
     * @param string $localFilePath 本地文件路径
     * @param string $fileName 原始文件名
     * @return bool
     * @throws \RuntimeException
     */
    public function uploadDocumentFile(int $documentId, string $localFilePath, string $fileName): bool
    {
        if (!file_exists($localFilePath)) {
            throw new \RuntimeException('上传文件不存在');
        }

        $sql = sprintf(
            'select * from `def_contract_document` where `GUID`=%d and `删除标识`=%s limit 1',
            $documentId,
            $this->model->quote('0')
        );
        $result = $this->model->select($sql);
        $document = $result ? ($result->getRowArray() ?: []) : [];

        if (empty($document)) {
            throw new \RuntimeException('文档不存在');
        }

        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $fileSize = filesize($localFilePath);
        $fileMd5 = md5_file($localFilePath);

        $storageDir = WRITEPATH . 'contract_docs';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        $version = (int) ($document['版本号'] ?? 1);
        $newFileName = $documentId . '_v' . $version . '.' . $fileExt;
        $destPath = $storageDir . DIRECTORY_SEPARATOR . $newFileName;

        if (!move_uploaded_file($localFilePath, $destPath)) {
            if (!copy($localFilePath, $destPath)) {
                throw new \RuntimeException('文件保存失败');
            }
        }

        $now = date('Y-m-d H:i:s');
        $relativePath = 'contract_docs/' . $newFileName;
        $documentKey = $this->generateDocumentKey($documentId, $version);

        $updateSql = sprintf(
            'update `def_contract_document` 
            set `文件路径`=%s, `文件大小`=%d, `文件MD5`=%s, `文档格式`=%s, `文档密钥`=%s, `更新时间`=%s 
            where `GUID`=%d',
            $this->model->quote($relativePath),
            $fileSize,
            $this->model->quote($fileMd5),
            $this->model->quote($fileExt),
            $this->model->quote($documentKey),
            $this->model->quote($now),
            $documentId
        );
        $this->model->exec($updateSql);

        return true;
    }

    /**
     * 获取文档下载URL
     *
     * @param int $documentId 文档ID
     * @return string 下载URL
     * @throws \RuntimeException
     */
    public function getDownloadUrl(int $documentId): string
    {
        $sql = sprintf(
            'select * from `def_contract_document` where `GUID`=%d and `删除标识`=%s limit 1',
            $documentId,
            $this->model->quote('0')
        );
        $result = $this->model->select($sql);
        $document = $result ? ($result->getRowArray() ?: []) : [];

        if (empty($document)) {
            throw new \RuntimeException('文档不存在');
        }

        $filePath = $document['文件路径'] ?? '';
        if (empty($filePath)) {
            throw new \RuntimeException('文档文件不存在');
        }

        $fullPath = WRITEPATH . $filePath;
        if (!file_exists($fullPath)) {
            throw new \RuntimeException('文档文件不存在');
        }

        return site_url('api/onlyoffice/download/' . $documentId . '?token=' . $this->generateDownloadToken($documentId));
    }

    /**
     * 生成文档密钥
     *
     * @param int $documentId 文档ID
     * @param int $version 版本号
     * @return string 文档密钥
     */
    public function generateDocumentKey(int $documentId, int $version): string
    {
        $data = $documentId . '_' . $version . '_' . time() . '_' . mt_rand(1000, 9999);
        return 'doc_' . $documentId . '_v' . $version . '_' . substr(md5($data), 0, 16);
    }

    /**
     * 下载回调文件
     *
     * @param string $url 文件下载URL
     * @return string 本地临时文件路径
     * @throws \RuntimeException
     */
    public function downloadCallbackFile(string $url): string
    {
        if (empty($url)) {
            throw new \RuntimeException('下载URL不能为空');
        }

        $tempDir = WRITEPATH . 'contract_docs' . DIRECTORY_SEPARATOR . 'temp';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $tempFile = $tempDir . DIRECTORY_SEPARATOR . 'callback_' . uniqid() . '.tmp';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $fileContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($fileContent === false || $httpCode !== 200) {
            throw new \RuntimeException('文件下载失败: ' . ($error ?: 'HTTP ' . $httpCode));
        }

        if (file_put_contents($tempFile, $fileContent) === false) {
            throw new \RuntimeException('临时文件保存失败');
        }

        return $tempFile;
    }

    /**
     * 更新文档版本
     *
     * @param int $documentId 文档ID
     * @param string $operator 操作人工号
     * @return int 新版本的文档 GUID
     * @throws \RuntimeException
     */
    public function bumpVersion(int $documentId, string $operator): int
    {
        $sql = sprintf(
            'select * from `def_contract_document` where `GUID`=%d and `删除标识`=%s limit 1',
            $documentId,
            $this->model->quote('0')
        );
        $result = $this->model->select($sql);
        $document = $result ? ($result->getRowArray() ?: []) : [];

        if (empty($document)) {
            throw new \RuntimeException('文档不存在');
        }

        $oldVersion = (int) ($document['版本号'] ?? 1);
        $newVersion = $oldVersion + 1;
        $now = date('Y-m-d H:i:s');

        $oldFilePath = $document['文件路径'] ?? '';
        $oldFullPath = WRITEPATH . $oldFilePath;

        $newFilePath = '';
        $newFileSize = 0;
        $newFileMd5 = '';
        $fileExt = $document['文档格式'] ?? '';

        if (!empty($oldFilePath) && file_exists($oldFullPath)) {
            $storageDir = WRITEPATH . 'contract_docs';
            $newFileName = $documentId . '_v' . $newVersion . '.' . $fileExt;
            $newRelativePath = 'contract_docs/' . $newFileName;
            $newFullPath = $storageDir . DIRECTORY_SEPARATOR . $newFileName;

            if (!copy($oldFullPath, $newFullPath)) {
                throw new \RuntimeException('版本文件复制失败');
            }

            $newFilePath = $newRelativePath;
            $newFileSize = filesize($newFullPath);
            $newFileMd5 = md5_file($newFullPath);
        }

        $updateOldSql = sprintf(
            'update `def_contract_document` set `最新版本`=%s where `GUID`=%d',
            $this->model->quote('0'),
            $documentId
        );
        $this->model->exec($updateOldSql);

        $documentNo = $this->generateDocumentNo();
        $newDocumentKey = $this->generateDocumentKey($documentId, $newVersion);

        $fields = [
            '`文档编号`',
            '`合同编号`',
            '`文档名称`',
            '`文档类型`',
            '`文档格式`',
            '`文件路径`',
            '`文件大小`',
            '`文件MD5`',
            '`版本号`',
            '`最新版本`',
            '`父版本ID`',
            '`文档密钥`',
            '`是否在线编辑`',
            '`编辑状态`',
            '`备注`',
            '`创建人`',
            '`创建时间`',
            '`更新人`',
            '`更新时间`',
            '`删除标识`',
            '`有效标识`',
        ];

        $values = [
            $this->model->quote($documentNo),
            $this->model->quote($document['合同编号'] ?? ''),
            $this->model->quote($document['文档名称'] ?? ''),
            $this->model->quote($document['文档类型'] ?? 'MAIN'),
            $this->model->quote($fileExt),
            $this->model->quote($newFilePath),
            $newFileSize,
            $this->model->quote($newFileMd5),
            $this->model->quote((string) $newVersion),
            $this->model->quote('1'),
            $documentId,
            $this->model->quote($newDocumentKey),
            $this->model->quote($document['是否在线编辑'] ?? '1'),
            $this->model->quote('IDLE'),
            $this->model->quote($document['备注'] ?? ''),
            $this->model->quote($operator),
            $this->model->quote($now),
            $this->model->quote($operator),
            $this->model->quote($now),
            $this->model->quote('0'),
            $this->model->quote('1'),
        ];

        $insertSql = sprintf(
            'insert into `def_contract_document` (%s) values (%s)',
            implode(', ', $fields),
            implode(', ', $values)
        );
        $this->model->exec($insertSql);

        $db = db_connect('btdc');
        $newDocumentId = (int) $db->insertID();

        return $newDocumentId;
    }

    /**
     * 生成文档编号
     *
     * @return string 文档编号
     */
    private function generateDocumentNo(): string
    {
        $dateStr = date('Ymd');
        $prefix = 'DOC' . $dateStr;

        $sql = sprintf(
            'select `文档编号` from `def_contract_document` 
            where `文档编号` like %s 
            order by `文档编号` desc limit 1',
            $this->model->quote($prefix . '%')
        );
        $result = $this->model->select($sql);
        $row = $result ? ($result->getRowArray() ?: []) : [];

        $seq = 1;
        if (!empty($row['文档编号'])) {
            $lastNo = $row['文档编号'];
            $lastSeq = (int) substr($lastNo, -4);
            $seq = $lastSeq + 1;
        }

        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * base64url 编码
     *
     * @param string $data 原始数据
     * @return string 编码后的数据
     */
    private function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * base64url 解码
     *
     * @param string $data 编码的数据
     * @return string 原始数据
     */
    private function base64urlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
    }

    /**
     * 生成下载 token
     *
     * @param int $documentId 文档ID
     * @return string token
     */
    private function generateDownloadToken(int $documentId): string
    {
        $payload = [
            'documentId' => $documentId,
            'exp' => time() + 3600,
            'iat' => time(),
        ];
        return $this->generateJwt($payload);
    }

    /**
     * 处理编辑中状态
     *
     * @param int $documentId 文档ID
     * @param string $userId 用户ID
     * @return void
     */
    private function handleEditing(int $documentId, string $userId): void
    {
        if ($documentId <= 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $sql = sprintf(
            'update `def_contract_document` 
            set `编辑状态`=%s, `最后编辑人`=%s, `最后编辑时间`=%s 
            where `GUID`=%d',
            $this->model->quote('EDITING'),
            $this->model->quote($userId),
            $this->model->quote($now),
            $documentId
        );
        $this->model->exec($sql);
    }

    /**
     * 处理文档保存
     *
     * @param int $documentId 文档ID
     * @param string $fileUrl 文件下载URL
     * @param string $userId 用户ID
     * @return void
     * @throws \RuntimeException
     */
    private function handleSave(int $documentId, string $fileUrl, string $userId): void
    {
        if ($documentId <= 0 || empty($fileUrl)) {
            return;
        }

        $tempFile = $this->downloadCallbackFile($fileUrl);

        try {
            $sql = sprintf(
                'select * from `def_contract_document` where `GUID`=%d and `删除标识`=%s limit 1',
                $documentId,
                $this->model->quote('0')
            );
            $result = $this->model->select($sql);
            $document = $result ? ($result->getRowArray() ?: []) : [];

            if (empty($document)) {
                throw new \RuntimeException('文档不存在');
            }

            $fileExt = $document['文档格式'] ?? 'docx';
            $version = (int) ($document['版本号'] ?? 1);
            $storageDir = WRITEPATH . 'contract_docs';
            if (!is_dir($storageDir)) {
                mkdir($storageDir, 0755, true);
            }

            $newFileName = $documentId . '_v' . $version . '_' . time() . '.' . $fileExt;
            $destPath = $storageDir . DIRECTORY_SEPARATOR . $newFileName;

            if (!rename($tempFile, $destPath)) {
                if (!copy($tempFile, $destPath)) {
                    throw new \RuntimeException('文件保存失败');
                }
                @unlink($tempFile);
            }

            $fileSize = filesize($destPath);
            $fileMd5 = md5_file($destPath);
            $relativePath = 'contract_docs/' . $newFileName;
            $now = date('Y-m-d H:i:s');
            $documentKey = $this->generateDocumentKey($documentId, $version);

            $oldPath = $document['文件路径'] ?? '';
            if (!empty($oldPath) && $oldPath !== $relativePath) {
                $oldFullPath = WRITEPATH . $oldPath;
                if (file_exists($oldFullPath)) {
                    @unlink($oldFullPath);
                }
            }

            $updateSql = sprintf(
                'update `def_contract_document` 
                set `文件路径`=%s, `文件大小`=%d, `文件MD5`=%s, `文档密钥`=%s, 
                    `编辑状态`=%s, `最后编辑人`=%s, `最后编辑时间`=%s, `更新时间`=%s 
                where `GUID`=%d',
                $this->model->quote($relativePath),
                $fileSize,
                $this->model->quote($fileMd5),
                $this->model->quote($documentKey),
                $this->model->quote('IDLE'),
                $this->model->quote($userId),
                $this->model->quote($now),
                $this->model->quote($now),
                $documentId
            );
            $this->model->exec($updateSql);
        } finally {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    /**
     * 处理保存错误
     *
     * @param int $documentId 文档ID
     * @param array $callbackData 回调数据
     * @return void
     */
    private function handleSaveError(int $documentId, array $callbackData): void
    {
        if ($documentId <= 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $sql = sprintf(
            'update `def_contract_document` 
            set `编辑状态`=%s, `最后编辑时间`=%s 
            where `GUID`=%d',
            $this->model->quote('IDLE'),
            $this->model->quote($now),
            $documentId
        );
        $this->model->exec($sql);

        log_message('error', '[OnlyOffice] 文档保存失败，文档ID: ' . $documentId . ', 数据: ' . json_encode($callbackData, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 处理文档关闭
     *
     * @param int $documentId 文档ID
     * @param string $userId 用户ID
     * @return void
     */
    private function handleClose(int $documentId, string $userId): void
    {
        if ($documentId <= 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $sql = sprintf(
            'update `def_contract_document` 
            set `编辑状态`=%s, `最后编辑人`=%s, `最后编辑时间`=%s 
            where `GUID`=%d',
            $this->model->quote('IDLE'),
            $this->model->quote($userId),
            $this->model->quote($now),
            $documentId
        );
        $this->model->exec($sql);
    }

    /**
     * 处理强制保存
     *
     * @param int $documentId 文档ID
     * @param string $fileUrl 文件下载URL
     * @param string $userId 用户ID
     * @return void
     * @throws \RuntimeException
     */
    private function handleForceSave(int $documentId, string $fileUrl, string $userId): void
    {
        $this->handleSave($documentId, $fileUrl, $userId);
    }

    /**
     * 处理强制保存错误
     *
     * @param int $documentId 文档ID
     * @param array $callbackData 回调数据
     * @return void
     */
    private function handleForceSaveError(int $documentId, array $callbackData): void
    {
        $this->handleSaveError($documentId, $callbackData);
    }

    /**
     * 记录回调日志
     *
     * @param string $documentKey 文档密钥
     * @param int $documentId 文档ID
     * @param int $status 回调状态码
     * @param string $eventName 事件名称
     * @param array $callbackData 回调数据
     * @param string $userId 用户ID
     * @param string $action 操作动作
     * @param string $processStatus 处理状态
     * @param string $processResult 处理结果
     * @param float $startTime 开始时间
     * @param string $fileUrl 文件URL
     * @return void
     */
    private function logCallback(
        string $documentKey,
        int $documentId,
        int $status,
        string $eventName,
        array $callbackData,
        string $userId,
        string $action,
        string $processStatus,
        string $processResult,
        float $startTime,
        string $fileUrl = ''
    ): void {
        $endTime = hrtime(true);
        $durationMs = (int) (($endTime - $startTime) / 1e6);
        $now = date('Y-m-d H:i:s');

        $callbackJson = json_encode($callbackData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ip = $this->getClientIp();
        $headers = $this->getRequestHeaders();
        $headersJson = json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $fields = [
            '`文档密钥`',
            '`文档ID`',
            '`回调类型`',
            '`回调事件`',
            '`回调数据`',
            '`用户ID`',
            '`操作动作`',
            '`文件URL`',
            '`处理状态`',
            '`处理结果`',
            '`处理耗时`',
            '`回调时间`',
            '`处理时间`',
            '`回调IP`',
            '`请求头`',
            '`创建时间`',
            '`删除标识`',
            '`有效标识`',
        ];

        $values = [
            $this->model->quote($documentKey),
            $documentId > 0 ? $documentId : 'null',
            $this->model->quote((string) $status),
            $this->model->quote($eventName),
            $this->model->quote($callbackJson),
            $this->model->quote($userId),
            $this->model->quote($action),
            $this->model->quote($fileUrl),
            $this->model->quote($processStatus),
            $this->model->quote($processResult),
            $durationMs,
            $this->model->quote($now),
            $this->model->quote($now),
            $this->model->quote($ip),
            $this->model->quote($headersJson),
            $this->model->quote($now),
            $this->model->quote('0'),
            $this->model->quote('1'),
        ];

        $sql = sprintf(
            'insert into `def_onlyoffice_callback_log` (%s) values (%s)',
            implode(', ', $fields),
            implode(', ', $values)
        );

        try {
            $this->model->exec($sql);
        } catch (\Throwable $e) {
            log_message('error', '[OnlyOffice] 回调日志记录失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取客户端IP
     *
     * @return string
     */
    private function getClientIp(): string
    {
        $ip = '';
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return trim($ip);
    }

    /**
     * 获取请求头
     *
     * @return array
     */
    private function getRequestHeaders(): array
    {
        $headers = [];
        if (function_exists('getallheaders')) {
            $headers = getallheaders() ?: [];
        } else {
            foreach ($_SERVER as $key => $value) {
                if (strpos($key, 'HTTP_') === 0) {
                    $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                    $headers[$headerName] = $value;
                }
            }
        }
        return $headers;
    }
}
