<?php
/**
 * 支付宝网页支付（常规付）页面
 *
 * @package QilingShop
 */

// 兼容历史直连访问；标准入口由 WordPress rewrite/query var 加载时不再重复查找 wp-load.php。
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

    if (empty($wp_load)) {
        die('WordPress core files not found');
    }

    require_once $wp_load;
}
if (!function_exists('qilingshop_is_authorized') || !qilingshop_is_authorized()) {
    wp_die(esc_html__('启灵商城未授权，暂时无法使用支付功能。', 'qilingshop'));
}
header('Content-type: text/html; charset=utf-8');
date_default_timezone_set('Asia/Shanghai');

// 确保辅助函数可用
if (!function_exists('qilingshop_prepare_return_cookie')) {
    $helpers_path = plugin_dir_path(__DIR__) . 'includes/helpers.php';
    if (file_exists($helpers_path)) {
        require_once $helpers_path;
    }
}

// 校验并保存返回地址
$raw_redirect = isset($_GET['redirect_url']) ? urldecode((string) wp_unslash($_GET['redirect_url'])) : '';
$safe_return_url = home_url();
if (function_exists('qilingshop_prepare_return_cookie') && function_exists('qilingshop_get_safe_return_url')) {
    qilingshop_prepare_return_cookie($raw_redirect);
    $safe_return_url = qilingshop_get_safe_return_url();
}

global $wpdb;
$db = QilingShop_Database::instance();

// 获取订单参数
$order_no = isset($_GET['order']) ? sanitize_text_field((string) wp_unslash($_GET['order'])) : '';
$requested_price = isset($_GET['price']) ? (float) wp_unslash($_GET['price']) : 0.0;
$subject = isset($_GET['subject']) ? sanitize_text_field((string) wp_unslash($_GET['subject'])) : __('在线支付', 'qilingshop');

$fixed_title = get_option('qilingshop_fixed_order_title');
if (!empty($fixed_title)) {
    $subject = $fixed_title;
}

if (empty($order_no) || !preg_match('/^[A-Z0-9]{8,64}$/', $order_no)) {
    wp_die(esc_html__('订单参数错误', 'qilingshop'));
}

// 判断订单类型并获取订单信息
if (strpos($order_no, 'CZ') === 0) {
    $table = $db->get_table('recharge');
    $order_type = 'recharge';
    if (empty($fixed_title)) {
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

$expected_price = 0;
if (strpos($order_no, 'CZ') === 0) {
    $expected_price = (isset($order_info->final_amount) && (float) $order_info->final_amount > 0)
        ? (float) $order_info->final_amount
        : (float) $order_info->amount;
} elseif (strpos($order_no, 'SHOP') === 0 || strpos($order_no, 'TUAN') === 0) {
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

$price = $expected_price;

// 支付宝配置
$app_id = get_option('qilingshop_alipay_app_id');
$private_key = get_option('qilingshop_alipay_private_key');
$notify_url = function_exists('qilingshop_get_payment_notify_url')
    ? qilingshop_get_payment_notify_url('alipay')
    : home_url('?qilingshop_payment=notify&gateway=alipay');
$configured_return_url = function_exists('qilingshop_normalize_return_url')
    ? qilingshop_normalize_return_url(get_option('qilingshop_payment_return_url'))
    : '';
$return_url = !empty($safe_return_url) ? $safe_return_url : ($configured_return_url !== '' ? $configured_return_url : home_url());

if (empty($app_id) || empty($private_key)) {
    wp_die(esc_html__('支付宝未配置，请联系管理员', 'qilingshop'));
}

// 构建业务参数
$biz_content = json_encode([
    'out_trade_no' => $order_no,
    'total_amount' => sprintf('%.2f', $price),
    'subject'      => $subject,
    'product_code' => 'FAST_INSTANT_TRADE_PAY',
], JSON_UNESCAPED_UNICODE);

// 构建请求参数
$params = [
    'app_id'      => $app_id,
    'method'      => 'alipay.trade.page.pay',
    'format'      => 'JSON',
    'charset'     => 'utf-8',
    'sign_type'   => 'RSA2',
    'timestamp'   => date('Y-m-d H:i:s'),
    'version'     => '1.0',
    'notify_url'  => $notify_url,
    'return_url'  => $return_url,
    'biz_content' => $biz_content,
];

// 生成签名
ksort($params);
$str = '';
foreach ($params as $k => $v) {
    if ($v !== '' && !is_array($v) && $k !== 'sign') {
        $str .= "$k=$v&";
    }
}
$str = rtrim($str, '&');

$private_key_pem = "-----BEGIN RSA PRIVATE KEY-----\n"
    . wordwrap($private_key, 64, "\n", true)
    . "\n-----END RSA PRIVATE KEY-----";

$res = openssl_pkey_get_private($private_key_pem);
if (!$res) {
    wp_die(esc_html__('私钥格式错误', 'qilingshop'));
}

openssl_sign($str, $sign, $res, OPENSSL_ALGO_SHA256);
$params['sign'] = base64_encode($sign);

// 构建支付URL
$gateway = 'https://openapi.alipay.com/gateway.do';
$pay_url = $gateway . '?' . http_build_query($params);

// 直接跳转
wp_redirect($pay_url);
exit;
