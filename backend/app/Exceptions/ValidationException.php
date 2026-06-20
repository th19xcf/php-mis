<?php

namespace App\Exceptions;

/**
 * 参数校验异常
 *
 * 用于必填参数缺失、参数格式错误等场景。
 * 控制器捕获后应返回 PARAM_ERROR 错误码。
 */
class ValidationException extends \RuntimeException
{
}
