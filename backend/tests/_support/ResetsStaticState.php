<?php

namespace Tests\Support;

use App\Controllers\BaseApiController;
use App\Libraries\SessionUserContext;

/**
 * 跨用例静态态复位：
 * - SessionUserContext::$jwtUser（setJwtUser 不接受 null，公开 API 无法清空）
 * - BaseApiController::$tableColumnsCache（请求级静态缓存跨用例残留）
 * - CI4 服务容器
 */
trait ResetsStaticState
{
    protected function resetStaticState(): void
    {
        $jwt = new \ReflectionProperty(SessionUserContext::class, 'jwtUser');
        $jwt->setAccessible(true);
        $jwt->setValue(null, null);

        $cache = new \ReflectionProperty(BaseApiController::class, 'tableColumnsCache');
        $cache->setAccessible(true);
        $cache->setValue(null, []);

        \Config\Services::reset();
    }
}
