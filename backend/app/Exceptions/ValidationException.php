<?php

namespace App\Exceptions;

use App\Constants\ApiCode;

class ValidationException extends \RuntimeException
{
    protected $code = ApiCode::PARAM_ERROR;
}
