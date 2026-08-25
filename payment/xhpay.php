<?php
/**
 * 虎皮椒 V3 支付入口
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

if (!function_exists('qilingshop_xhpay_generate_hash')) {
    function qilingshop_xhpay_generate_hash(array $data, $secret) {
        ksort($data);
        $pairs = [];
        foreach ($data as $key => $val) {
            if ($key === 'hash' || $val === '' || $val === null || is_array($val)) {
                continue;
            }
            $pairs[] = $key . '=' . $val;
        }
        return md5(implode('&', $pairs) . (string) $secret);
    }
}

if (!function_exists('qilingshop_xhpay_is_mobile_client')) {
    function qilingshop_xhpay_is_mobile_client() {
        if (function_exists('qilingshop_is_mobile')) {
            return qilingshop_is_mobile();
        }
        return wp_is_mobile();
    }
}

if (!function_exists('qilingshop_xhpay_is_wechat_app')) {
    function qilingshop_xhpay_is_wechat_app() {
        $ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        return strpos($ua, 'micromessenger') !== false;
    }
}

if (!function_exists('qilingshop_xhpay_is_image_url')) {
    function qilingshop_xhpay_is_image_url($url) {
        if (!is_string($url) || $url === '') {
            return false;
        }
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'], true);
    }
}

$raw_redirect = isset($_GET['redirect_url']) ? urldecode((string) wp_unslash($_GET['redirect_url'])) : '';
$validated_redirect = qilingshop_prepare_return_cookie($raw_redirect);
$safe_return_url = function_exists('qilingshop_normalize_return_url')
    ? qilingshop_normalize_return_url($validated_redirect)
    : '';

$poll_nonce = wp_create_nonce('qilingshop_ajax');
$order_no = isset($_GET['order']) ? sanitize_text_field((string) wp_unslash($_GET['order'])) : '';
$requested_price = isset($_GET['price']) ? (float) wp_unslash($_GET['price']) : 0.0;
$subject = isset($_GET['subject']) ? sanitize_text_field((string) wp_unslash($_GET['subject'])) : (get_bloginfo('name') . __('在线支付', 'qilingshop'));
$method = isset($_GET['method']) ? sanitize_key((string) wp_unslash($_GET['method'])) : '';
if (!in_array($method, ['alipay', 'wechat'], true)) {
    $method = sanitize_key((string) get_option('qilingshop_xhpay_default_type', 'alipay'));
    if (!in_array($method, ['alipay', 'wechat'], true)) {
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
$is_group_order = !empty($order_info->is_group_order);
$is_guest_shop_order = ($order_type === 'shop_order' && isset($order_info->user_id) && (int) $order_info->user_id <= 0);
$shop_completion_redirect_url = '';
if ($order_type === 'shop_order' && function_exists('qls_shop_public')) {
    $shop_completion_redirect_url = qls_shop_public()->get_page_url('orders');
    if ($is_guest_shop_order && function_exists('qilingshop_get_order_query_page_url')) {
        $shop_completion_redirect_url = qilingshop_get_order_query_page_url($order_no, $shop_completion_redirect_url);
    }
}
$configured_return_url = function_exists('qilingshop_normalize_return_url')
    ? qilingshop_normalize_return_url(get_option('qilingshop_payment_return_url'))
    : '';
$poll_token = wp_create_nonce('qilingshop_poll_' . $order_no);

if ($method === 'wechat') {
    $appid = sanitize_text_field((string) get_option('qilingshop_xhpay_appid_wechat', ''));
    $appsecret = sanitize_text_field((string) get_option('qilingshop_xhpay_appsecret_wechat', ''));
    $api_url = esc_url_raw((string) get_option('qilingshop_xhpay_api_wechat', ''));
} else {
    $appid = sanitize_text_field((string) get_option('qilingshop_xhpay_appid_alipay', ''));
    $appsecret = sanitize_text_field((string) get_option('qilingshop_xhpay_appsecret_alipay', ''));
    $api_url = esc_url_raw((string) get_option('qilingshop_xhpay_api_alipay', ''));
}

if ($appid === '' || $appsecret === '') {
    wp_die(__('虎皮椒 V3 未配置，请联系管理员', 'qilingshop'));
}

if ($api_url === '') {
    $api_url = 'https://api.xunhupay.com/payment/do.html';
}

$plugin_id = sanitize_key((string) get_option('qilingshop_xhpay_plugin_id', 'qilingshop-xhpay3'));
if ($plugin_id === '') {
    $plugin_id = 'qilingshop-xhpay3';
}

$notify_url = function_exists('qilingshop_get_payment_notify_url')
    ? qilingshop_get_payment_notify_url('xhpay')
    : home_url('?qilingshop_payment=notify&gateway=xhpay');
$return_url = $safe_return_url !== '' ? $safe_return_url : QilingShop_Payment::instance()->get_return_url($order_type, $order_no);
$callback_url = $return_url;
$is_mobile = qilingshop_xhpay_is_mobile_client() ? 'Y' : 'N';

$request_data = [
    'version'        => '1.1',
    'lang'           => 'zh-cn',
    'plugins'        => $plugin_id . '-' . $method,
    'appid'          => $appid,
    'trade_order_id' => $order_no,
    'payment'        => $method,
    'is_app'         => $is_mobile,
    'total_fee'      => number_format($price, 2, '.', ''),
    'title'          => $subject,
    'description'    => '',
    'time'           => time(),
    'notify_url'     => $notify_url,
    'return_url'     => $return_url,
    'callback_url'   => $callback_url,
    'nonce_str'      => wp_generate_password(12, false),
];

if ($method === 'wechat' && $is_mobile === 'Y' && qilingshop_xhpay_is_wechat_app()) {
    $request_data['type'] = 'WAP';
    $request_data['wap_url'] = home_url('/');
    $request_data['wap_name'] = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
}

$request_data['hash'] = qilingshop_xhpay_generate_hash($request_data, $appsecret);

$response = wp_remote_post($api_url, [
    'timeout'   => 30,
    'sslverify' => true,
    'headers'   => ['Content-Type' => 'application/json; charset=utf-8'],
    'body'      => wp_json_encode($request_data),
]);

if (is_wp_error($response)) {
    wp_die(__('虎皮椒请求失败：', 'qilingshop') . $response->get_error_message());
}

$response_body = wp_remote_retrieve_body($response);
$result = json_decode($response_body, true);
if (!is_array($result)) {
    wp_die(__('虎皮椒返回数据异常', 'qilingshop'));
}

if (isset($result['hash'])) {
    $verify_hash = qilingshop_xhpay_generate_hash($result, $appsecret);
    if (!hash_equals(strtolower((string) $result['hash']), strtolower($verify_hash))) {
        wp_die(__('虎皮椒返回签名校验失败', 'qilingshop'));
    }
}

$errcode = isset($result['errcode']) ? (int) $result['errcode'] : 0;
if ($errcode !== 0) {
    $message = sanitize_text_field((string) ($result['errmsg'] ?? __('下单失败', 'qilingshop')));
    wp_die(esc_html($message));
}

$pay_url = esc_url_raw((string) ($result['url'] ?? ''));
$qrcode_raw = trim((string) ($result['url_qrcode'] ?? ''));

if ($is_mobile === 'Y' && $pay_url !== '') {
    wp_safe_redirect($pay_url);
    exit;
}

$local_qr_payload = '';
if ($pay_url !== '') {
    $local_qr_payload = $pay_url;
} elseif ($qrcode_raw !== '' && !qilingshop_xhpay_is_image_url($qrcode_raw)) {
    $local_qr_payload = $qrcode_raw;
}

if ($local_qr_payload === '') {
    wp_die(__('支付网关未返回可本地生成二维码的数据', 'qilingshop'));
}

$qrcode_src = QILINGSHOP_URL . 'includes/qrcode.php?data=' . rawurlencode($local_qr_payload);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $method === 'wechat' ? esc_html__('虎皮椒微信支付', 'qilingshop') : esc_html__('虎皮椒支付宝支付', 'qilingshop'); ?></title>
    <style>
        body{background:#f5f5f5;margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}
        .pay-box{width:400px;margin:80px auto;background:#fff;padding:30px;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,0.05);text-align:center}
        .pay-box h2{margin:0 0 20px;font-size:20px;color:#333;font-weight:normal}
        .pay-amount{font-size:36px;color:#333;font-weight:bold;margin-bottom:20px}
        .pay-amount span{font-size:18px;top:-10px;position:relative;margin-right:2px}
        .qr-code{position:relative;width:220px;height:220px;margin:0 auto 20px}
        .qr-code img{width:100%;height:100%;display:block}
        .pay-tip{color:#666;font-size:14px;margin:10px 0}
        #time{color:#ff4d4f;font-size:16px;margin:15px 0;height:24px;line-height:24px}
        #time span{font-weight:bold}
        .expired{position:absolute;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px;border-radius:4px;z-index:10}
    </style>
    <script src="<?php echo esc_url(QILINGSHOP_URL . 'static/js/jquery-3.7.1.min.js'); ?>"></script>
</head>
<body>
    <div class="pay-box">
        <img src="<?php echo esc_url($method === 'wechat' ? QILINGSHOP_URL . 'static/img/wx.png' : QILINGSHOP_URL . 'static/img/zfb.png'); ?>" style="width:40px;height:40px;margin-bottom:10px;border-radius:50%;box-shadow:0 4px 10px rgba(0,0,0,0.1);background:#fff;padding:4px;" alt="">
        <h2><?php echo $method === 'wechat' ? esc_html__('虎皮椒微信支付', 'qilingshop') : esc_html__('虎皮椒支付宝支付', 'qilingshop'); ?></h2>
        <div class="pay-amount"><span>¥</span><?php echo esc_html(number_format($price, 2)); ?></div>
        <div class="qr-code">
            <img src="<?php echo esc_url($qrcode_src); ?>" alt="<?php esc_attr_e('支付二维码', 'qilingshop'); ?>">
        </div>
        <p class="pay-tip"><?php echo $method === 'wechat' ? esc_html__('请使用微信扫一扫支付', 'qilingshop') : esc_html__('请使用支付宝扫一扫支付', 'qilingshop'); ?></p>
        <p class="pay-tip"><?php esc_html_e('支付完成后请等待5秒左右', 'qilingshop'); ?></p>
        <p id="time"></p>
    </div>

    <script>
        var qlsPayI18n = {
            countdown: '<?php echo esc_js(__('支付倒计时：', 'qilingshop')); ?>',
            minute: '<?php echo esc_js(__('分', 'qilingshop')); ?>',
            second: '<?php echo esc_js(__('秒', 'qilingshop')); ?>',
            expired: '<?php echo esc_js(__('二维码已过期', 'qilingshop')); ?>'
        };

        var qlsOrderCheck = setInterval(function() {
            jQuery.ajax({
                type: 'POST',
                url: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
                data: {
                    action: 'qilingshop_check_order',
                    order_no: '<?php echo esc_js($order_no); ?>',
                    type: '<?php echo esc_js($order_type); ?>',
                    gateway: 'xhpay',
                    nonce: '<?php echo esc_js($poll_nonce); ?>',
                    poll_token: '<?php echo esc_js($poll_token); ?>'
                },
                dataType: 'json',
                success: function(res) {
                    if (res && res.success && res.data && res.data.paid) {
                        clearInterval(qlsOrderCheck);
                        clearInterval(qlsCountdown);
                        <?php if ($is_group_order && !$is_guest_shop_order && function_exists('qls_group_public')): ?>
                        location.href = "<?php echo esc_js(qls_group_public()->get_group_detail_url(intval($order_info->group_id ?? 0))); ?>";
                        <?php elseif ($shop_completion_redirect_url !== ''): ?>
                        location.href = "<?php echo esc_js($shop_completion_redirect_url); ?>";
                        <?php elseif (!empty($safe_return_url)): ?>
                        location.href = "<?php echo esc_js($safe_return_url); ?>";
                        <?php elseif ($configured_return_url !== ''): ?>
                        location.href = "<?php echo esc_js($configured_return_url); ?>";
                        <?php else: ?>
                        location.href = "<?php echo esc_js(home_url('/')); ?>";
                        <?php endif; ?>
                    }
                }
            });
        }, 5000);

        var m = 5, s = 0;
        var timer = document.getElementById('time');
        qlsCountdownTick();
        var qlsCountdown = setInterval(qlsCountdownTick, 1000);

        function qlsCountdownTick() {
            timer.innerHTML = qlsPayI18n.countdown + "<span>0" + m + qlsPayI18n.minute + (s < 10 ? '0' : '') + s + qlsPayI18n.second + "</span>";
            if (m === 0 && s === 0) {
                clearInterval(qlsOrderCheck);
                clearInterval(qlsCountdown);
                jQuery('<div/>', { 'class': 'expired', text: qlsPayI18n.expired }).appendTo('.qr-code');
                return;
            }
            if (s > 0) {
                s--;
            } else {
                m--;
                s = 59;
            }
        }
    </script>
</body>
</html>
