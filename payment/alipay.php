<?php 
/**
 * 支付宝当面付（扫码支付）页面
 * 
 * @package QilingShop
 */

// 返回地址将在 WordPress 加载后进行校验并写入 Cookie

// 兼容历史直连访问；标准入口由 WordPress rewrite/query var 加载时不再重复查找 wp-load.php。
if (!defined('ABSPATH')) {
    $wp_load_paths = [
        dirname(__FILE__, 5) . '/wp-load.php',           // 标准结构: wp-content/plugins/qilingshop/payment/
        dirname(__FILE__, 4) . '/wp-load.php',           // 自定义结构
        $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php',      // 文档根目录
        dirname($_SERVER['DOCUMENT_ROOT']) . '/wp-load.php', // 父目录
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
header("Content-type: text/html; charset=utf-8");
date_default_timezone_set('Asia/Shanghai');

// 确保辅助函数已加载
if (!function_exists('qilingshop_prepare_return_cookie')) {
    $helpers_path = plugin_dir_path(__DIR__) . 'includes/helpers.php';
    if (file_exists($helpers_path)) {
        require_once $helpers_path;
    }
}

// 校验并保存返回地址
$raw_redirect = isset($_GET['redirect_url']) ? urldecode((string) wp_unslash($_GET['redirect_url'])) : '';
qilingshop_prepare_return_cookie($raw_redirect);
$safe_return_url = qilingshop_get_safe_return_url();

$poll_nonce = wp_create_nonce('qilingshop_ajax');
$payment_debug = (bool) apply_filters('qilingshop_payment_frontend_debug', defined('WP_DEBUG') && WP_DEBUG, 'alipay');

global $wpdb;
$db = QilingShop_Database::instance();

// 获取订单参数
$order_no = isset($_GET['order']) ? sanitize_text_field((string) wp_unslash($_GET['order'])) : '';
$requested_price = isset($_GET['price']) ? (float) wp_unslash($_GET['price']) : 0.0;
$subject = isset($_GET['subject']) ? sanitize_text_field((string) wp_unslash($_GET['subject'])) : 'Online Payment';

// 使用固定标题（如果设置了）
$fixed_title = get_option('qilingshop_fixed_order_title');
if (!empty($fixed_title)) {
    $subject = $fixed_title;
}

if (empty($order_no)) {
    wp_die(esc_html__('订单参数错误', 'qilingshop'));
}

$poll_token = wp_create_nonce('qilingshop_poll_' . $order_no);

// 判断订单类型并获取订单信息
if (strpos($order_no, 'CZ') === 0) {
    $table = $db->get_table('recharge');
    $order_type = 'recharge';
    // 固定充值标题，避免请求标题影响签名
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

$recharge_return_url = function_exists('developer_starter_get_frontend_account_tab_url')
    ? developer_starter_get_frontend_account_tab_url('qls-shop')
    : add_query_arg(
        'tab',
        'qls-shop',
        function_exists('developer_starter_build_raw_home_url')
            ? developer_starter_build_raw_home_url('/user')
            : home_url('/user')
    );
if ($order_type === 'recharge') {
    if (function_exists('developer_starter_get_frontend_account_tab_url')) {
        $recharge_return_url = developer_starter_get_frontend_account_tab_url('qls-shop');
    } else {
        $account_page_id = (int) get_option('developer_starter_account_page_id', 0);
        if ($account_page_id > 0 && get_post_status($account_page_id) === 'publish') {
            $recharge_return_url = add_query_arg('tab', 'qls-shop', get_permalink($account_page_id));
        } else {
            $account_pages = get_pages([
                'meta_key'   => '_wp_page_template',
                'meta_value' => 'templates/template-account.php',
                'number'     => 1,
            ]);
            if (!empty($account_pages)) {
                $recharge_return_url = add_query_arg('tab', 'qls-shop', get_permalink($account_pages[0]->ID));
            }
        }
    }
}

// 支持在同一入口切换支付宝支付方式：f2f（扫码）/page（网页）
$pay_method = isset($_GET['method']) ? sanitize_key((string) wp_unslash($_GET['method'])) : '';
if ($pay_method === 'page' || $pay_method === 'wap') {
    $pay_order_type = 'order';
    if ($order_type === 'recharge') {
        $pay_order_type = 'recharge';
    } elseif ($order_type === 'shop_order') {
        $pay_order_type = $is_group_order ? 'group' : 'shop';
    }

    $extra = [
        'subject' => $subject,
        'method'  => $pay_method,
    ];

    if (!empty($safe_return_url)) {
        $extra['return_url'] = $safe_return_url;
    }

    $page_payment = QilingShop_Payment::instance()->create_payment(
        $pay_order_type,
        $order_no,
        $price,
        'alipay',
        $extra
    );

    if (!empty($page_payment['success']) && !empty($page_payment['pay_url'])) {
        wp_redirect($page_payment['pay_url']);
        exit;
    }

    wp_die(!empty($page_payment['message']) ? esc_html($page_payment['message']) : esc_html__('支付宝支付初始化失败', 'qilingshop'));
}

// 支付宝配置
$app_id = get_option('qilingshop_alipay_app_id');
$private_key = get_option('qilingshop_alipay_private_key');
$notify_url = function_exists('qilingshop_get_payment_notify_url')
    ? qilingshop_get_payment_notify_url('alipay')
    : home_url('?qilingshop_payment=notify&gateway=alipay');

if (empty($app_id) || empty($private_key)) {
    wp_die(esc_html__('支付宝未配置，请联系管理员', 'qilingshop'));
}

// 构建当面付请求参数
$biz_content = json_encode([
    'out_trade_no' => $order_no,
    'total_amount' => sprintf('%.2f', $price),
    'subject'      => $subject,
    'body'         => $subject,
], JSON_UNESCAPED_SLASHES); // 保留中文转义，避免签名串不一致

$params = [
    'app_id'      => $app_id,
    'method'      => 'alipay.trade.precreate',
    'format'      => 'JSON',
    'charset'     => 'utf-8',
    'sign_type'   => 'RSA2',
    'timestamp'   => date('Y-m-d H:i:s'),
    'version'     => '1.0',
    'notify_url'  => $notify_url,
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

$private_key_pem = "-----BEGIN RSA PRIVATE KEY-----\n" . 
    wordwrap($private_key, 64, "\n", true) . 
    "\n-----END RSA PRIVATE KEY-----";

$res = openssl_pkey_get_private($private_key_pem);
if (!$res) {
    wp_die(esc_html__('私钥格式错误，请检查支付宝配置', 'qilingshop'));
}

openssl_sign($str, $sign, $res, OPENSSL_ALGO_SHA256);
$params['sign'] = base64_encode($sign);

// 调用支付宝接口
$gateway = 'https://openapi.alipay.com/gateway.do';
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $gateway);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$response = curl_exec($ch);
$curl_errno = curl_errno($ch);
$curl_error = curl_error($ch);
curl_close($ch);

$result = json_decode($response, true);
// 调试日志：记录原始响应，解决 json_decode 为 null 的问题
if (defined('WP_DEBUG') && WP_DEBUG && empty($result)) {
    error_log('[QilingShop Alipay Debug] Curl Error: ' . $curl_error);
    error_log('[QilingShop Alipay Debug] Raw response (Base64): ' . base64_encode($response));
    error_log('[QilingShop Alipay Debug] Raw response (String): ' . $response);
}
$qr_code = '';
$error_msg = '';

if ($curl_errno > 0) {
    $error_msg = 'CURL Error: ' . $curl_error;
} else if (isset($result['alipay_trade_precreate_response'])) {
    $res_data = $result['alipay_trade_precreate_response'];
    if ($res_data['code'] == '10000' && !empty($res_data['qr_code'])) {
        $qr_code = $res_data['qr_code'];
    } else {
        $error_msg = sprintf(
            '%s (%s)',
            $res_data['sub_msg'] ?? $res_data['msg'] ?? __('创建订单失败', 'qilingshop'),
            $res_data['sub_code'] ?? $res_data['code'] ?? 'UNKNOWN'
        );
    }
} else {
    $error_msg = __('支付宝接口调用失败', 'qilingshop');
    // 调试模式下显示更多信息
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $error_msg .= ': ' . json_encode($result, JSON_UNESCAPED_UNICODE);
    }
}

if (empty($qr_code)) {
    wp_die(esc_html(sprintf(__('支付宝当面付创建失败：%s', 'qilingshop'), $error_msg)));
}

// 成功，显示支付页面
?>
<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="content-type" content="text/html;charset=utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title><?php echo esc_html(sprintf(__('支付宝支付 - %s', 'qilingshop'), get_bloginfo('name'))); ?></title>
    <link rel="shortcut icon" href="<?php echo esc_attr(get_site_icon_url()); ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Microsoft YaHei", sans-serif; background: linear-gradient(135deg, #1890ff 0%, #36cfc9 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .pay-box { background: #fff; border-radius: 16px; padding: 40px; max-width: 400px; width: 90%; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,0.15); }
        .pay-logo { width: 120px; margin-bottom: 20px; }
        .pay-amount { font-size: 36px; color: #1890ff; font-weight: bold; margin: 20px 0; }
        .pay-amount span { font-size: 18px; }
        .qr-code { padding: 20px; background: #f5f5f5; border-radius: 12px; margin: 20px 0; position: relative; }
        .qr-code img { width: 200px; height: 200px; }
        .pay-tip { color: #666; font-size: 14px; margin: 10px 0; }
        #time { color: #ff4d4f; font-size: 16px; margin: 15px 0; }
        #time span { color: #ff4d4f; font-weight: bold; }
        .wap-btn { display: inline-block; background: #1890ff; color: #fff; padding: 12px 40px; border-radius: 25px; text-decoration: none; font-size: 16px; margin-top: 15px; }
        .wap-btn:hover { background: #40a9ff; }
        .expired { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 18px; border-radius: 12px; z-index: 10; }
    </style>
    <script src="<?php echo QILINGSHOP_URL; ?>static/js/jquery-3.7.1.min.js"></script>
</head>
<body>
    <div class="pay-box">
        <div style="font-size:48px;color:#1890ff">💳</div>
        <h2><?php esc_html_e('支付宝扫码支付', 'qilingshop'); ?></h2>
        <div class="pay-amount"><span>¥</span><?php echo sprintf('%.2f', $price); ?></div>
        
        <div class="qr-code">
            <?php $qrcode_src = QILINGSHOP_URL . 'includes/qrcode.php?data=' . rawurlencode($qr_code); ?>
            <img src="<?php echo esc_url($qrcode_src); ?>" alt="<?php esc_attr_e('二维码', 'qilingshop'); ?>">
        </div>
        
        <p class="pay-tip"><?php esc_html_e('请使用支付宝扫码支付', 'qilingshop'); ?></p>
        <p class="pay-tip"><?php esc_html_e('支付完成后请等待5秒左右', 'qilingshop'); ?></p>
        <p id="time"></p>
        
        <?php if (wp_is_mobile()): ?>
        <a href="<?php echo esc_attr($qr_code); ?>" class="wap-btn" id="qls-wap-link" target="_blank"><?php esc_html_e('启动支付宝应用支付', 'qilingshop'); ?></a>
        <?php endif; ?>
    </div>

    <script>
        <?php if (wp_is_mobile()): ?>
        jQuery(function(){ jQuery("#qls-wap-link").find("span").trigger("click"); });
        <?php endif; ?>
        
        var qlsPayI18n = {
            countdown: '<?php echo esc_js(__('支付倒计时：', 'qilingshop')); ?>',
            minute: '<?php echo esc_js(__('分', 'qilingshop')); ?>',
            second: '<?php echo esc_js(__('秒', 'qilingshop')); ?>',
            expired: '<?php echo esc_js(__('二维码已过期', 'qilingshop')); ?>'
        };

        var qlsPayDebug = <?php echo wp_json_encode($payment_debug); ?>;
        function qlsPayDebugLog() {
            var logger = window.console;
            if (!qlsPayDebug || !logger || typeof logger.log !== 'function') {
                return;
            }
            logger.log.apply(logger, arguments);
        }

        // 订单状态轮询
        var qlsOrderCheck = setInterval(function() {
            jQuery.ajax({  
                type: 'POST',  
                url: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',  
                data: {
                    action: 'qilingshop_check_order',
                    order_no: '<?php echo esc_js($order_no); ?>',
                    type: '<?php echo $order_type; ?>',
                    gateway: 'alipay',
                    nonce: '<?php echo esc_js($poll_nonce); ?>',
                    poll_token: '<?php echo esc_js($poll_token); ?>'
                },  
                dataType: 'json',
                success: function(res){  
                    if (res && res.success && res.data && res.data.paid) {
                        clearInterval(qlsOrderCheck);
                        clearInterval(qlsCountdown);
                        <?php if ($is_group_order && !$is_guest_shop_order && function_exists('qls_group_public')): ?>
                        <?php $group_id = intval($order_info->group_id ?? 0); ?>
                        location.href = "<?php echo esc_js(qls_group_public()->get_group_detail_url($group_id)); ?>";
                        <?php elseif ($shop_completion_redirect_url !== ''): ?>
                        location.href = "<?php echo esc_js($shop_completion_redirect_url); ?>";
                        <?php elseif ($order_type === 'recharge'): ?>
                        location.href = "<?php echo esc_js($recharge_return_url); ?>";
                        <?php elseif (!empty($safe_return_url)): ?>
                        location.href = "<?php echo esc_js($safe_return_url); ?>";
                        <?php elseif ($configured_return_url !== ''): ?>
                        location.href = "<?php echo esc_js($configured_return_url); ?>";
                        <?php else: ?>
                        location.href = "<?php echo esc_js(home_url()); ?>";
                        <?php endif; ?>
                    }  
	                },
	                error: function(XMLHttpRequest, textStatus, errorThrown){
	                    qlsPayDebugLog('Check order error: ' + errorThrown);
	                }
	            });
        }, 5000);

        // 倒计时
        var m = 5, s = 0;  
        var Timer = document.getElementById("time");
        qlsPayCountdown();
        var qlsCountdown = setInterval(function(){ qlsPayCountdown() }, 1000);
        
        function qlsPayCountdown() {
            Timer.innerHTML = qlsPayI18n.countdown + "<span>0" + m + qlsPayI18n.minute + (s < 10 ? '0' : '') + s + qlsPayI18n.second + "</span>";
            if (m == 0 && s == 0) {
                clearInterval(qlsOrderCheck);
                clearInterval(qlsCountdown);
                jQuery('<div/>', { 'class': 'expired', text: qlsPayI18n.expired }).appendTo(".qr-code");
            } else if (m >= 0) {
                if (s > 0) {
                    s--;
                } else if (s == 0) {
                    m--;
                    s = 59;
                }
            }
        }
    </script>
</body>
</html>
