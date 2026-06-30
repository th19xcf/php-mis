<?php

namespace App\Exceptions;

use App\Constants\ApiCode;

class AuthException extends \RuntimeException
{
    protected $code = ApiCode::AUTH_UNAUTHORIZED;
}
