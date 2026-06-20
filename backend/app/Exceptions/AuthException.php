<?php

namespace App\Exceptions;

/**
 * 认证异常
 *
 * 用于登录态失效、权限不足、无功能访问权限等场景。
 * 控制器捕获后应返回 AUTH_UNAUTHORIZED 错误码。
 */
class AuthException extends \RuntimeException
{
}
