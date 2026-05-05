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

    // System errors
    public const PARAM_ERROR = '2001';
    public const NOT_FOUND = '2002';
    public const BUSINESS_ERROR = '2003';
    public const SERVER_ERROR = '5000';

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
        '9998',
        '3333',
    ];
}
