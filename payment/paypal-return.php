<?php
/**
 * PayPal 同步回调页面（Orders v2）
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
        die('WordPress core files not found');
    }

    require_once $wp_load;
}
if (!function_exists('qilingshop_is_authorized') || !qilingshop_is_authorized()) {
    wp_die(esc_html__('启灵商城未授权，暂时无法处理支付结果。', 'qilingshop'));
}
header('Content-type: text/html; charset=utf-8');

// 确保辅助函数已加载
if (!function_exists('qilingshop_validate_return_url')) {
    $helpers_path = plugin_dir_path(__DIR__) . 'includes/helpers.php';
    if (file_exists($helpers_path)) {
        require_once $helpers_path;
    }
}

// 若传入 redirect_to，同步写入回跳 Cookie，确保回跳链路一致
if (function_exists('qilingshop_prepare_return_cookie') && isset($_GET['redirect_to'])) {
    $raw_redirect = (string) wp_unslash($_GET['redirect_to']);
    qilingshop_prepare_return_cookie($raw_redirect);
}

/**
 * 解析并校验最终跳转地址
 *
 * @return string
 */
function qilingshop_paypal_resolve_redirect_url() {
    // 优先使用创建订单时透传的 redirect_to 参数
    if (isset($_GET['redirect_to'])) {
        $raw = (string) wp_unslash($_GET['redirect_to']);
        $validated = qilingshop_validate_return_url($raw);
        if ($validated !== '') {
            return (strpos($validated, '/') === 0 && strpos($validated, '//') !== 0)
                ? home_url($validated)
                : esc_url_raw($validated);
        }
    }

    // 兼容旧流程的 cookie 回跳
    if (isset($_COOKIE['qilingshop_return'])) {
        $cookie_return = (string) wp_unslash($_COOKIE['qilingshop_return']);
        $validated = qilingshop_validate_return_url($cookie_return);
        if ($validated !== '') {
            return (strpos($validated, '/') === 0 && strpos($validated, '//') !== 0)
                ? home_url($validated)
                : esc_url_raw($validated);
        }
    }

    $option_return = (string) get_option('qilingshop_payment_return_url');
    $validated = qilingshop_validate_return_url($option_return);
    if ($validated !== '') {
        return (strpos($validated, '/') === 0 && strpos($validated, '//') !== 0)
            ? home_url($validated)
            : esc_url_raw($validated);
    }

    return home_url();
}

// 用户取消支付时直接回跳，不做捕获
$status = isset($_GET['status']) ? sanitize_text_field((string) wp_unslash($_GET['status'])) : '';
if ($status === 'cancel') {
    wp_safe_redirect(qilingshop_paypal_resolve_redirect_url());
    exit;
}

$paypal_order_id = isset($_GET['token']) ? sanitize_text_field((string) wp_unslash($_GET['token'])) : '';
$order_no = '';
if (isset($_GET['order_no'])) {
    $order_no = sanitize_text_field((string) wp_unslash($_GET['order_no']));
} elseif (isset($_GET['order'])) {
    $order_no = sanitize_text_field((string) wp_unslash($_GET['order']));
}

if ($paypal_order_id === '') {
    wp_die(esc_html__('PayPal 回调参数缺失', 'qilingshop'));
}

$paypal_file = QILINGSHOP_PATH . 'payment/class-payment-paypal.php';
if (!class_exists('QilingShop_Payment_Paypal') && file_exists($paypal_file)) {
    require_once $paypal_file;
}
if (!class_exists('QilingShop_Payment_Paypal')) {
    wp_die(esc_html__('PayPal 支付网关未加载', 'qilingshop'));
}

$rest_file = QILINGSHOP_PATH . 'includes/class-qilingshop-rest-api.php';
if (!class_exists('QilingShop_REST_API') && file_exists($rest_file)) {
    require_once $rest_file;
}
if (!class_exists('QilingShop_REST_API')) {
    wp_die(esc_html__('支付处理模块未加载', 'qilingshop'));
}

$paypal = new QilingShop_Payment_Paypal();
$captured = $paypal->capture_order($paypal_order_id, $order_no);
if (empty($captured['success'])) {
    $message = !empty($captured['message']) ? (string) $captured['message'] : __('PayPal 捕获失败', 'qilingshop');
    wp_die(esc_html($message));
}

$processed = QilingShop_REST_API::instance()->process_payment_success(
    (string) $captured['order_no'],
    (float) $captured['amount'],
    'paypal',
    (string) $captured['transaction_id'],
    (string) $captured['currency']
);

if ($processed !== 'success') {
    wp_die(esc_html((string) $processed));
}

wp_safe_redirect(qilingshop_paypal_resolve_redirect_url());
exit;
