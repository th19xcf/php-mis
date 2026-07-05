<?php

namespace Config;

use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    /*
     * public static function example($getShared = true)
     * {
     *     if ($getShared) {
     *         return static::getSharedInstance('example');
     *     }
     *
     *     return new \CodeIgniter\Example();
     * }
     */

    /**
     * JWT Token 服务（共享实例）
     *
     * JwtTokenService 无内部状态，但通过共享实例避免每次请求重复实例化。
     * JwtAuthFilter、Auth 控制器等统一通过 service('jwtTokenService') 获取。
     */
    public static function jwtTokenService(bool $getShared = true): \App\Libraries\JwtTokenService
    {
        if ($getShared) {
            return static::getSharedInstance('jwtTokenService');
        }

        return new \App\Libraries\JwtTokenService();
    }

    /**
     * Token 黑名单服务（共享实例）
     *
     * TokenBlacklistService 构造开销较大：
     *   - file 驱动：触发目录检查
     *   - redis 驱动：触发 Predis 连接
     * 通过共享实例确保单请求内仅构造一次，避免 JwtAuthFilter 每次 new。
     */
    public static function tokenBlacklistService(bool $getShared = true): \App\Libraries\TokenBlacklistService
    {
        if ($getShared) {
            return static::getSharedInstance('tokenBlacklistService');
        }

        return new \App\Libraries\TokenBlacklistService();
    }
}
