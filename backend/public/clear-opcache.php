<?php
// 清除 PHP OPcache
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPcache cleared successfully\n";
} else {
    echo "OPcache not available\n";
}

// 显示当前时间
echo "Time: " . date('Y-m-d H:i:s') . "\n";
