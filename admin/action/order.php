<?php
/**
 * 订单状态检查接口（兼容层）
 *
 * @deprecated 统一改为 admin-ajax.php?action=qilingshop_check_order
 *
 * @package QilingShop
 */

if (!function_exists('qilingshop_legacy_order_die_handler')) {
    /**
     * 捕获 wp_die，方便兼容层读取 AJAX JSON 输出。
     *
     * @param mixed $message 错误信息
     * @return void
     * @throws RuntimeException 中断当前流程
     */
    function qilingshop_legacy_order_die_handler($message = '') {
        if (is_scalar($message) && $message !== '') {
            echo (string) $message;
        }
        throw new RuntimeException('qilingshop_legacy_order_stop');
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo '0';
    exit;
}

$legacy_do = isset($_POST['do']) ? (string) $_POST['do'] : '';
$legacy_nonce = isset($_POST['token']) ? (string) $_POST['token'] : '';
if ($legacy_do !== 'checkOrder' || $legacy_nonce === '') {
    echo '0';
    exit;
}

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) ? (string) $_SERVER['DOCUMENT_ROOT'] : '';
$wp_load_paths = [
    dirname(__FILE__, 5) . '/wp-load.php',
    dirname(__FILE__, 6) . '/wp-load.php',
    $doc_root !== '' ? $doc_root . '/wp-load.php' : '',
    $doc_root !== '' ? dirname($doc_root) . '/wp-load.php' : '',
];

$wp_load = '';
foreach ($wp_load_paths as $path) {
    if ($path !== '' && file_exists($path)) {
        $wp_load = $path;
        break;
    }
}

if ($wp_load === '') {
    echo '0';
    exit;
}

require_once $wp_load;

if (!function_exists('qilingshop_is_authorized') || !qilingshop_is_authorized()) {
    echo '0';
    exit;
}

if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', true);
}

if (!has_action('wp_ajax_nopriv_qilingshop_check_order') && class_exists('QilingShop_Ajax')) {
    QilingShop_Ajax::instance();
}

$_POST['action'] = 'qilingshop_check_order';
$_POST['nonce'] = $legacy_nonce;
$_POST['order_no'] = sanitize_text_field(wp_unslash($_POST['order_no'] ?? ''));
$_POST['type'] = sanitize_key(wp_unslash($_POST['type'] ?? 'order'));
$_POST['poll_token'] = sanitize_text_field(wp_unslash($_POST['poll_token'] ?? ''));
$_POST['gateway'] = sanitize_key(wp_unslash($_POST['gateway'] ?? ''));
$_REQUEST = array_merge($_REQUEST, $_POST);

$die_handler_filter = static function () {
    return 'qilingshop_legacy_order_die_handler';
};

add_filter('wp_die_handler', $die_handler_filter, 9999);
add_filter('wp_die_ajax_handler', $die_handler_filter, 9999);

ob_start();
try {
    if (is_user_logged_in()) {
        do_action('wp_ajax_qilingshop_check_order');
    } else {
        do_action('wp_ajax_nopriv_qilingshop_check_order');
    }
} catch (RuntimeException $exception) {
    if ($exception->getMessage() !== 'qilingshop_legacy_order_stop') {
        ob_end_clean();
        remove_filter('wp_die_handler', $die_handler_filter, 9999);
        remove_filter('wp_die_ajax_handler', $die_handler_filter, 9999);
        echo '0';
        exit;
    }
}
$raw_response = trim((string) ob_get_clean());

remove_filter('wp_die_handler', $die_handler_filter, 9999);
remove_filter('wp_die_ajax_handler', $die_handler_filter, 9999);

$payload = json_decode($raw_response, true);
if (!is_array($payload)) {
    $json_start = strpos($raw_response, '{');
    if ($json_start !== false) {
        $payload = json_decode(substr($raw_response, $json_start), true);
    }
}

$is_paid = is_array($payload)
    && !empty($payload['success'])
    && !empty($payload['data'])
    && !empty($payload['data']['paid']);

echo $is_paid ? '1' : '0';
exit;
