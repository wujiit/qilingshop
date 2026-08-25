<?php
/**
 * Stripe 支付入口
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

    if (empty($wp_load)) {
        die('WordPress load failed');
    }

    require_once $wp_load;
}

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('qilingshop_is_authorized') || !qilingshop_is_authorized()) {
    wp_die(esc_html__('启灵商城未授权，暂时无法使用支付功能。', 'qilingshop'));
}

if (!function_exists('qilingshop_normalize_return_url')) {
    $helpers_path = plugin_dir_path(__DIR__) . 'includes/helpers.php';
    if (file_exists($helpers_path)) {
        require_once $helpers_path;
    }
}

$order_no = isset($_GET['order']) ? sanitize_text_field(wp_unslash($_GET['order'])) : '';
if ($order_no === '' || !preg_match('/^[A-Z0-9]{8,64}$/', $order_no)) {
    wp_die(__('订单号无效', 'qilingshop'));
}

$subject = isset($_GET['subject']) ? sanitize_text_field(wp_unslash($_GET['subject'])) : '';
if ($subject === '') {
    $subject = get_option('qilingshop_fixed_order_title', '');
}
if ($subject === '') {
    $subject = get_bloginfo('name');
}

$redirect_url = '';
if (isset($_GET['redirect_url'])) {
    $redirect_url = function_exists('qilingshop_normalize_return_url')
        ? qilingshop_normalize_return_url(wp_unslash((string) $_GET['redirect_url']))
        : '';
}
$order_type = '';
$amount = 0.0;

global $wpdb;

if (strpos($order_no, 'CZ') === 0) {
    $order_type = 'recharge';
    $table = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'recharge';
    $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE order_no = %s", $order_no));
    if (!$order) {
        wp_die(__('充值订单不存在', 'qilingshop'));
    }
    $amount = isset($order->final_amount) && (float) $order->final_amount > 0 ? (float) $order->final_amount : (float) $order->amount;
} elseif ((strpos($order_no, 'SHOP') === 0 || strpos($order_no, 'TUAN') === 0) && function_exists('qls_shop_order')) {
    $order_type = strpos($order_no, 'TUAN') === 0 ? 'group' : 'shop';
    $order = qls_shop_order()->get_by_order_no($order_no);
    if (!$order) {
        wp_die(__('商城订单不存在', 'qilingshop'));
    }
    $amount = (float) $order->final_amount;
} else {
    $order_type = 'order';
    $table = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'orders';
    $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE order_no = %s", $order_no));
    if (!$order) {
        wp_die(__('订单不存在', 'qilingshop'));
    }
    $amount = (float) (isset($order->final_price) && (float) $order->final_price > 0 ? $order->final_price : $order->price_rmb);
}

if (function_exists('qilingshop_enforce_payment_order_access')) {
    $access_result = qilingshop_enforce_payment_order_access($order, $order_type);
    if (is_wp_error($access_result)) {
        wp_die(esc_html($access_result->get_error_message()));
    }
}

if ($amount <= 0) {
    wp_die(__('订单金额无效', 'qilingshop'));
}

$return_url = '';
if (!empty($redirect_url)) {
    $return_url = $redirect_url;
}
if ($return_url === '') {
    $return_url = QilingShop_Payment::instance()->get_return_url($order_type, $order_no);
}

$payment = QilingShop_Payment::instance()->create_payment(
    $order_type,
    $order_no,
    $amount,
    'stripe',
    [
        'subject'    => $subject,
        'return_url' => $return_url,
    ]
);

if (!empty($payment['success']) && !empty($payment['pay_url'])) {
    wp_redirect($payment['pay_url']);
    exit;
}

$message = !empty($payment['message']) ? $payment['message'] : __('Stripe 支付初始化失败', 'qilingshop');
wp_die(esc_html($message));
