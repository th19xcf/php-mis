<?php

namespace App\Constants;

final class ApiCode
{
    private function __construct()
    {
    }

    public const SUCCESS = '0000';

    // Auth request validation
    public const AUTH_USERNAME_PASSWORD_REQUIRED = '1001';
    public const AUTH_REGION_REQUIRED = '1002';
    public const AUTH_CREDENTIAL_INVALID = '1003';
    public const AUTH_REFRESH_TOKEN_REQUIRED = '1007';

    // Auth session/token lifecycle
    public const AUTH_UNAUTHORIZED = '8888';
    public const AUTH_REFRESH_TOKEN_INVALID = '8889';
    public const AUTH_TOKEN_EXPIRED = '9999';
    // 已废弃：历史 token 失效的替代码，保留以兼容旧客户端
    public const AUTH_TOKEN_EXPIRED_ALT = '9998';
    public const AUTH_TOKEN_EXPIRED_LEGACY = '3333';

    // 兼容历史错误码：客户端 (≤1.x) 使用此码判定"未登录 / 会话失效"
    public const AUTH_TOKEN_REQUIRED_LEGACY = '4010';
    public const AUTH_SESSION_EXPIRED_LEGACY = '4011';

    // System errors
    public const PARAM_ERROR = '2001';
    public const NOT_FOUND = '2002';
    public const BUSINESS_ERROR = '2003';
    public const SERVER_ERROR = '5000';

    // Workbench module (5001-5099)
    public const WORKBENCH_PARAM_REQUIRED = '4001';
    public const WORKBENCH_TABLE_CONFIG_MISSING = '5001';
    public const WORKBENCH_QUERY_FAILED = '5002';
    public const WORKBENCH_PAGED_QUERY_FAILED = '5003';
    public const WORKBENCH_CHART_DRILL_FAILED = '5004';
    public const WORKBENCH_CHART_DRILL_RESET_FAILED = '5005';

    /** @var string[] */
    public const LOGOUT_CODES = [
        self::AUTH_UNAUTHORIZED,
        self::AUTH_REFRESH_TOKEN_INVALID,
    ];

    /** @var string[] */
    public const MODAL_LOGOUT_CODES = [
        '7777',
        '7778',
    ];

    /** @var string[] */
    public const EXPIRED_TOKEN_CODES = [
        self::AUTH_TOKEN_EXPIRED,
        self::AUTH_TOKEN_EXPIRED_ALT,
        self::AUTH_TOKEN_EXPIRED_LEGACY,
    ];
}
