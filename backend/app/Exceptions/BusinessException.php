<?php

namespace App\Exceptions;

/**
 * 业务逻辑异常
 *
 * 用于功能配置缺失、数据表未找到、数据库查询失败等业务场景。
 * 控制器捕获后应返回 BUSINESS_ERROR 错误码。
 */
class BusinessException extends \RuntimeException
{
}
