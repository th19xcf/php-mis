<?php

namespace App\Exceptions;

use App\Constants\ApiCode;

class BusinessException extends \RuntimeException
{
    protected $code = ApiCode::BUSINESS_ERROR;
}
