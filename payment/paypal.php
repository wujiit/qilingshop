<?php
/**
 * PayPal 支付入口兼容文件（仅 Orders v2）
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
    wp_die(esc_html__('启灵商城未授权，暂时无法使用支付功能。', 'qilingshop'));
}
header('Content-type: text/html; charset=utf-8');

global $wpdb;
$db = QilingShop_Database::instance();

$order_no = isset($_GET['order']) ? sanitize_text_field((string) wp_unslash($_GET['order'])) : '';
$requested_price = isset($_GET['price']) ? (float) wp_unslash($_GET['price']) : 0.0;
$subject = isset($_GET['subject'])
    ? sanitize_text_field((string) wp_unslash($_GET['subject']))
    : (get_bloginfo('name') . __('在线支付', 'qilingshop'));

if ($order_no === '') {
    wp_die(esc_html__('订单参数错误', 'qilingshop'));
}

$fixed_title = sanitize_text_field((string) get_option('qilingshop_fixed_order_title'));
if ($fixed_title !== '') {
    $subject = $fixed_title;
}

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
    $order_type = 'order';
}

$order_info = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE order_no = %s", $order_no));
if (!$order_info) {
    wp_die(esc_html__('订单不存在', 'qilingshop'));
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
    wp_die(esc_html__('订单金额异常', 'qilingshop'));
}
if ($requested_price > 0 && (int) round($requested_price * 100) !== (int) round($expected_price * 100)) {
    wp_die(esc_html__('订单金额校验失败', 'qilingshop'));
}

if (!class_exists('QilingShop_Payment_Paypal')) {
    require_once QILINGSHOP_PATH . 'payment/class-payment-paypal.php';
}

$return_url = home_url('/');
if (class_exists('QilingShop_Payment')) {
    $return_url = QilingShop_Payment::instance()->get_return_url($order_type, $order_no);
}

$result = (new QilingShop_Payment_Paypal())->create([
    'order_no' => $order_no,
    'order_type' => $order_type,
    'amount' => $expected_price,
    'subject' => $subject,
    'return_url' => $return_url,
]);

if (!empty($result['success']) && !empty($result['pay_url'])) {
    wp_safe_redirect(esc_url_raw((string) $result['pay_url']));
    exit;
}

$message = !empty($result['message']) ? (string) $result['message'] : __('创建 PayPal 订单失败', 'qilingshop');
wp_die(esc_html($message));
