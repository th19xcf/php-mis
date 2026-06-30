<?php

namespace Config;

use App\Libraries\ApiExceptionHandler;
use CodeIgniter\Config\BaseConfig;
use CodeIgniter\Debug\ExceptionHandler;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use Psr\Log\LogLevel;
use Throwable;

class Exceptions extends BaseConfig
{
    public bool $log = true;

    public array $ignoreCodes = [404];

    public string $errorViewPath = APPPATH . 'Views/errors';

    public array $sensitiveDataInTrace = [
        'server',
        'password',
        'secret',
        'token',
        'authorization',
        'cookie',
    ];

    public bool $logDeprecations = true;

    public string $deprecationLogLevel = LogLevel::WARNING;

    public function handler(int $statusCode, Throwable $exception): ExceptionHandlerInterface
    {
        if (is_cli()) {
            return new ExceptionHandler($this);
        }

        return new ApiExceptionHandler();
    }
}
