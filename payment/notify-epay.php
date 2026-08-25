<?php
/**
 * 易支付异步回调兼容入口。
 *
 * @package QilingShop
 */

if (!defined('ABSPATH')) {
    $wp_load_paths = [
        dirname(__FILE__, 5) . '/wp-load.php',
        dirname(__FILE__, 4) . '/wp-load.php',
        $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php',
    ];

    $wp_load = '';
    foreach ($wp_load_paths as $path) {
        if (file_exists($path)) {
            $wp_load = $path;
            break;
        }
    }

    if ($wp_load === '') {
        exit('fail');
    }

    require_once $wp_load;
}

if (!function_exists('qilingshop_is_authorized') || !qilingshop_is_authorized()) {
    exit('fail');
}

if (function_exists('qilingshop_dispatch_legacy_payment_notify')) {
    qilingshop_dispatch_legacy_payment_notify('epay');
}

exit('fail');
