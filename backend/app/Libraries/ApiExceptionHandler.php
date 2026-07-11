<?php

namespace App\Libraries;

use App\Constants\ApiCode;
use App\Exceptions\AuthException;
use App\Exceptions\BusinessException;
use App\Exceptions\ValidationException;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\HTTP\CLIRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

class ApiExceptionHandler implements ExceptionHandlerInterface
{
    public function handle(
        Throwable $exception,
        RequestInterface $request,
        ResponseInterface $response,
        int $statusCode,
        int $exitCode,
    ): void {
        if ($request instanceof CLIRequest) {
            $this->handleCli($exception, $statusCode, $exitCode);
            return;
        }

        $this->handleApi($exception, $request, $response, $statusCode);
    }

    private function handleApi(
        Throwable $exception,
        RequestInterface $request,
        ResponseInterface $response,
        int $statusCode
    ): void {
        [$code, $msg] = $this->resolveException($exception);
        // 按异常语义映射 HTTP 状态码，让监控、网关、客户端能基于状态码识别失败类型
        $httpStatus = $this->resolveHttpStatus($exception, $statusCode);

        $traceId = $request->getHeaderLine('X-Request-Id') ?: 'trace-' . bin2hex(random_bytes(8));

        $logMsg = sprintf(
            '[%s] %s in %s:%d',
            $code,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        );
        log_message('error', $logMsg . "\n" . $exception->getTraceAsString());

        $isDev = env('CI_ENVIRONMENT') === 'development';

        $body = [
            'code' => $code,
            'msg' => $msg,
        ];

        if ($isDev) {
            $body['debug'] = [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => explode("\n", $exception->getTraceAsString()),
            ];
        }

        $response
            ->setStatusCode($httpStatus)
            ->setContentType('application/json', 'utf-8')
            ->setHeader('X-Request-Id', $traceId)
            ->setJSON($body)
            ->send();
    }

    /**
     * 根据异常类型映射 HTTP 状态码
     *
     * 业务码与 HTTP 语义对齐：
     * - AuthException（未登录/登录态失效）→ 401
     * - ValidationException（参数校验失败）→ 422
     * - BusinessException（业务规则失败）→ 400
     * - 框架已确定的 4xx（如 404 路由不存在）→ 保留原值
     * - 其他未识别异常 → 500
     */
    private function resolveHttpStatus(Throwable $exception, int $frameworkStatusCode): int
    {
        if ($exception instanceof AuthException) {
            return 401;
        }
        if ($exception instanceof ValidationException) {
            return 422;
        }
        if ($exception instanceof BusinessException) {
            return 400;
        }

        // 框架已确定的 4xx/5xx（如 PageNotFoundException→404）保留原值
        return $frameworkStatusCode >= 400 ? $frameworkStatusCode : 500;
    }

    private function handleCli(Throwable $exception, int $statusCode, int $exitCode): void
    {
        $output = "Error [{$statusCode}]: " . $exception->getMessage() . "\n";
        $output .= "File: " . $exception->getFile() . " (" . $exception->getLine() . ")\n";
        $output .= "\nStack trace:\n" . $exception->getTraceAsString() . "\n";

        fwrite(STDERR, $output);
        exit($exitCode);
    }

    private function resolveException(Throwable $exception): array
    {
        if ($exception instanceof AuthException) {
            $code = $exception->getCode() ?: ApiCode::AUTH_UNAUTHORIZED;
            return [$code, $exception->getMessage() ?: '未登录或登录已过期'];
        }

        if ($exception instanceof ValidationException) {
            $code = $exception->getCode() ?: ApiCode::PARAM_ERROR;
            return [$code, $exception->getMessage() ?: '参数错误'];
        }

        if ($exception instanceof BusinessException) {
            $code = $exception->getCode() ?: ApiCode::BUSINESS_ERROR;
            return [$code, $exception->getMessage() ?: '业务处理失败'];
        }

        $isDev = env('CI_ENVIRONMENT') === 'development';
        $msg = $isDev ? $exception->getMessage() : '服务器内部错误';

        return [ApiCode::SERVER_ERROR, $msg];
    }
}
