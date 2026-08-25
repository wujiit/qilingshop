<?php
/**
 * 易支付入口
 *
 * @package QilingShop
 */

if (!defined('ABSPATH')) {
    $wp_load_paths = [
        dirname(__FILE__, 5) . '/wp-load.php',
        dirname(__FILE__, 4) . '/wp-load.php',
        $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php',
        dirname($_SERVER['DOCUMENT_ROOT']) . '/wp-load.php',
    ];

    $wp_load = '';
    foreach ($wp_load_paths as $path) {
        if (file_exists($path)) {
            $wp_load = $path;
            break;
        }
    }

    if ($wp_load === '') {
        die('WordPress core files not found');
    }

    require_once $wp_load;
}
if (!function_exists('qilingshop_is_authorized') || !qilingshop_is_authorized()) {
    wp_die(esc_html__('启灵商城未授权，暂时无法使用支付功能。', 'qilingshop'));
}
header('Content-type: text/html; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

if (!function_exists('qilingshop_prepare_return_cookie')) {
    $helpers_path = plugin_dir_path(__DIR__) . 'includes/helpers.php';
    if (file_exists($helpers_path)) {
        require_once $helpers_path;
    }
}

if (!function_exists('qilingshop_validate_return_url')) {
    function qilingshop_validate_return_url($raw_url) {
        $raw_url = is_string($raw_url) ? trim($raw_url) : '';
        if ($raw_url === '') {
            return '';
        }
        if (strpos($raw_url, '/') === 0 && strpos($raw_url, '//') !== 0) {
            return $raw_url;
        }

        $parsed = wp_parse_url($raw_url);
        $home = wp_parse_url(home_url('/'));
        $scheme = isset($parsed['scheme']) ? strtolower((string) $parsed['scheme']) : '';
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        $target_host = isset($parsed['host']) ? strtolower((string) $parsed['host']) : '';
        $home_host = isset($home['host']) ? strtolower((string) $home['host']) : '';
        return ($target_host !== '' && $home_host !== '' && $target_host === $home_host) ? $raw_url : '';
    }
}

if (!function_exists('qilingshop_normalize_return_url')) {
    function qilingshop_normalize_return_url($raw_url, $fallback_url = '') {
        $validated = qilingshop_validate_return_url($raw_url);
        if ($validated === '' && $fallback_url !== '') {
            $validated = qilingshop_validate_return_url($fallback_url);
        }
        if ($validated === '') {
            return '';
        }
        return (strpos($validated, '/') === 0 && strpos($validated, '//') !== 0)
            ? home_url($validated)
            : esc_url_raw($validated);
    }
}

if (!function_exists('qilingshop_prepare_return_cookie')) {
    function qilingshop_prepare_return_cookie($redirect_url) {
        $redirect_url = qilingshop_validate_return_url($redirect_url);
        if ($redirect_url === '') {
            setcookie('qilingshop_return', '', time() - 3600, '/');
            unset($_COOKIE['qilingshop_return']);
            return '';
        }
        setcookie('qilingshop_return', $redirect_url, 0, '/');
        $_COOKIE['qilingshop_return'] = $redirect_url;
        return $redirect_url;
    }
}

if (!function_exists('qilingshop_get_safe_return_url')) {
    function qilingshop_get_safe_return_url() {
        $raw = isset($_COOKIE['qilingshop_return']) ? wp_unslash((string) $_COOKIE['qilingshop_return']) : '';
        return qilingshop_normalize_return_url($raw);
    }
}

if (!function_exists('qilingshop_epay_generate_sign')) {
    function qilingshop_epay_generate_sign(array $params, $key) {
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            if ($k === 'sign' || $k === 'sign_type' || $v === '' || $v === null || is_array($v)) {
                continue;
            }
            $pairs[] = $k . '=' . $v;
        }
        return md5(implode('&', $pairs) . (string) $key);
    }
}

if (!function_exists('qilingshop_epay_client_ip')) {
    function qilingshop_epay_client_ip() {
        if (function_exists('qilingshop_security')) {
            return (string) qilingshop_security()->get_client_ip();
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }
}

$raw_redirect = isset($_GET['redirect_url']) ? urldecode((string) wp_unslash($_GET['redirect_url'])) : '';
$validated_redirect = qilingshop_prepare_return_cookie($raw_redirect);
$safe_return_url = function_exists('qilingshop_normalize_return_url')
    ? qilingshop_normalize_return_url($validated_redirect)
    : '';

$order_no = isset($_GET['order']) ? sanitize_text_field((string) wp_unslash($_GET['order'])) : '';
$requested_price = isset($_GET['price']) ? (float) wp_unslash($_GET['price']) : 0.0;
$subject = isset($_GET['subject']) ? sanitize_text_field((string) wp_unslash($_GET['subject'])) : (get_bloginfo('name') . __('在线支付', 'qilingshop'));
$method = isset($_GET['method']) ? sanitize_key((string) wp_unslash($_GET['method'])) : '';
if (!in_array($method, ['alipay', 'wxpay', 'qqpay'], true)) {
    $method = sanitize_key((string) get_option('qilingshop_epay_default_type', 'alipay'));
    if (!in_array($method, ['alipay', 'wxpay', 'qqpay'], true)) {
        $method = 'alipay';
    }
}

if ($order_no === '') {
    wp_die(__('订单参数错误', 'qilingshop'));
}

$fixed_title = sanitize_text_field((string) get_option('qilingshop_fixed_order_title', ''));
if ($fixed_title !== '') {
    $subject = $fixed_title;
}

global $wpdb;
$db = QilingShop_Database::instance();
$order_type = 'order';

if (strpos($order_no, 'CZ') === 0) {
    $table = $db->get_table('recharge');
    $order_type = 'recharge';
    if ($fixed_title === '') {
        $subject = __('积分充值', 'qilingshop');
    }
} elseif ((strpos($order_no, 'SHOP') === 0 || strpos($order_no, 'TUAN') === 0) && function_exists('qls_shop_db')) {
    $table = qls_shop_db()->get_table('orders');
    $order_type = 'shop_order';
} else {
    $table = $db->get_table('orders');
}

$order_info = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE order_no = %s", $order_no));
if (!$order_info) {
    wp_die(__('订单不存在', 'qilingshop'));
}

if (function_exists('qilingshop_enforce_payment_order_access')) {
    $access_result = qilingshop_enforce_payment_order_access($order_info, $order_type);
    if (is_wp_error($access_result)) {
        wp_die(esc_html($access_result->get_error_message()));
    }
}

$expected_price = 0.0;
if ($order_type === 'recharge') {
    $expected_price = (isset($order_info->final_amount) && (float) $order_info->final_amount > 0)
        ? (float) $order_info->final_amount
        : (float) $order_info->amount;
} elseif ($order_type === 'shop_order') {
    $expected_price = (float) $order_info->final_amount;
} else {
    $expected_price = ((float) $order_info->final_price > 0)
        ? (float) $order_info->final_price
        : (float) $order_info->price_rmb;
}

if ($expected_price <= 0) {
    wp_die(__('订单金额异常', 'qilingshop'));
}

if ($requested_price > 0 && (int) round($requested_price * 100) !== (int) round($expected_price * 100)) {
    wp_die(__('订单金额校验失败', 'qilingshop'));
}

$price = $expected_price;
$pid = sanitize_text_field((string) get_option('qilingshop_epay_pid', ''));
$key = sanitize_text_field((string) get_option('qilingshop_epay_key', ''));
$api_url = trim((string) get_option('qilingshop_epay_api_url', ''));

if ($pid === '' || $key === '' || $api_url === '') {
    wp_die(__('易支付未配置，请联系管理员', 'qilingshop'));
}

$api_url = untrailingslashit($api_url) . '/';
$notify_url = function_exists('qilingshop_get_payment_notify_url')
    ? qilingshop_get_payment_notify_url('epay')
    : home_url('?qilingshop_payment=notify&gateway=epay');
$return_url = $safe_return_url !== '' ? $safe_return_url : QilingShop_Payment::instance()->get_return_url($order_type, $order_no);

$params = [
    'pid'          => $pid,
    'type'         => $method,
    'notify_url'   => $notify_url,
    'return_url'   => $return_url,
    'out_trade_no' => $order_no,
    'name'         => $subject,
    'money'        => number_format($price, 2, '.', ''),
    'timestamp'    => time(),
    'clientip'     => qilingshop_epay_client_ip(),
];
$params['sign'] = qilingshop_epay_generate_sign($params, $key);
$params['sign_type'] = 'MD5';

$submit_url = $api_url . 'submit.php?' . http_build_query($params);
wp_safe_redirect($submit_url);
exit;
