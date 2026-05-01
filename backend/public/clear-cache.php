<?php
// 清除 OPcache
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPcache 已清除\n";
} else {
    echo "OPcache 不可用\n";
}

// 显示当前时间
echo "时间: " . date('Y-m-d H:i:s') . "\n";
