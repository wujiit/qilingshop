<?php 
/**
 * 微信支付扫码页面
 * 
 * @package QilingShop
 */

// 返回地址将在 WordPress 加载后进行校验并写入 Cookie
// 兼容历史直连访问；标准入口由 WordPress rewrite/query var 加载时不再重复查找 wp-load.php。
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
        die('WordPress core files not found');
    }

    require_once $wp_load;
}
if (!function_exists('qilingshop_is_authorized') || !qilingshop_is_authorized()) {
    wp_die(esc_html__('启灵商城未授权，暂时无法使用支付功能。', 'qilingshop'));
}
header("Content-type: text/html; charset=utf-8");
date_default_timezone_set('Asia/Shanghai');

global $wpdb;
$db = QilingShop_Database::instance();

// 确保辅助函数已加载 (防御性编程，防止插件主文件因某种原因未加载 helpers.php)
if (!function_exists('qilingshop_is_wechat') || !function_exists('qilingshop_is_mobile') || !function_exists('qilingshop_prepare_return_cookie')) {
    $helpers_path = plugin_dir_path(__DIR__) . 'includes/helpers.php';
    if (file_exists($helpers_path)) {
        require_once $helpers_path;
    }
}

$poll_nonce = wp_create_nonce('qilingshop_ajax');
$payment_debug = (bool) apply_filters('qilingshop_payment_frontend_debug', defined('WP_DEBUG') && WP_DEBUG, 'wechat');

// 校验并保存返回地址
$raw_redirect = isset($_GET['redirect_url']) ? urldecode((string) wp_unslash($_GET['redirect_url'])) : '';
qilingshop_prepare_return_cookie($raw_redirect);
$safe_return_url = qilingshop_get_safe_return_url();

if (!function_exists('qilingshop_wechat_gateway_http_get_json')) {
    /**
     * 微信支付页发起 GET JSON 请求。
     *
     * @param string $url 请求地址
     * @return array|WP_Error
     */
    function qilingshop_wechat_gateway_http_get_json($url) {
        $ssl_verify = (bool) apply_filters('qilingshop_wechat_ssl_verify', true);
        $response = wp_remote_get($url, [
            'timeout'   => 20,
            'sslverify' => $ssl_verify,
        ]);
        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($status_code < 200 || $status_code >= 300 || $body === '') {
            return new WP_Error('qilingshop_wechat_gateway_http_get_invalid', __('微信接口请求失败', 'qilingshop'));
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return new WP_Error('qilingshop_wechat_gateway_http_get_decode_failed', __('微信接口返回格式异常', 'qilingshop'));
        }

        return $decoded;
    }
}

if (!function_exists('qilingshop_wechat_gateway_http_post_xml')) {
    /**
     * 微信支付页发起 XML POST 请求。
     *
     * @param string $url 请求地址
     * @param string $xml XML 内容
     * @return string|WP_Error
     */
    function qilingshop_wechat_gateway_http_post_xml($url, $xml) {
        $ssl_verify = (bool) apply_filters('qilingshop_wechat_ssl_verify', true);
        $response = wp_remote_post($url, [
            'timeout'   => 30,
            'sslverify' => $ssl_verify,
            'headers'   => [
                'Content-Type' => 'text/xml; charset=utf-8',
            ],
            'body'      => $xml,
        ]);
        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($status_code < 200 || $status_code >= 300 || $body === '') {
            return new WP_Error('qilingshop_wechat_gateway_http_post_invalid', __('微信支付接口请求失败', 'qilingshop'));
        }

        return $body;
    }
}

// 获取订单参数
$order_no = isset($_GET['order']) ? sanitize_text_field((string) wp_unslash($_GET['order'])) : '';
$requested_price = isset($_GET['price']) ? (float) wp_unslash($_GET['price']) : 0.0;
$subject = isset($_GET['subject']) ? sanitize_text_field((string) wp_unslash($_GET['subject'])) : get_bloginfo('name') . __('在线支付', 'qilingshop');

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
    // 强制设置充值标题
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

// 微信支付配置
$mch_id = get_option('qilingshop_wechat_mchid');
$app_id = get_option('qilingshop_wechat_appid');
$api_key = get_option('qilingshop_wechat_key');
$app_secret = get_option('qilingshop_wechat_secret'); // 需要 AppSecret 获取 openid
$notify_url = function_exists('qilingshop_get_payment_notify_url')
    ? qilingshop_get_payment_notify_url('wechat')
    : home_url('?qilingshop_payment=notify&gateway=wechat');
$ssl_verify = (bool) apply_filters('qilingshop_wechat_ssl_verify', true);
$client_ip = function_exists('qilingshop_security')
    ? qilingshop_security()->get_client_ip()
    : (isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string) $_SERVER['REMOTE_ADDR']) : '127.0.0.1');
if (!filter_var($client_ip, FILTER_VALIDATE_IP) || $client_ip === '0.0.0.0') {
    $client_ip = '127.0.0.1';
}

if (empty($mch_id) || empty($api_key) || empty($app_id)) {
    wp_die(esc_html__('微信支付未配置，请联系管理员', 'qilingshop'));
}

// 场景 1: 微信内支付 (JSAPI)
if (qilingshop_is_wechat() && !empty($app_secret)) {
    // 1. 获取 OpenID
    if (empty($_GET['code'])) {
        $current_url = function_exists('qilingshop_get_current_url') ? qilingshop_get_current_url() : home_url('/');
        $url = add_query_arg([
            'appid'         => sanitize_text_field((string) $app_id),
            'redirect_uri'  => $current_url,
            'response_type' => 'code',
            'scope'         => 'snsapi_base',
            'state'         => 'STATE',
        ], 'https://open.weixin.qq.com/connect/oauth2/authorize') . '#wechat_redirect';

        wp_redirect(esc_url_raw($url));
        exit;
    } else {
        $code = sanitize_text_field((string) wp_unslash($_GET['code']));
        $url = "https://api.weixin.qq.com/sns/oauth2/access_token?appid={$app_id}&secret={$app_secret}&code={$code}&grant_type=authorization_code";
        $res = qilingshop_wechat_gateway_http_get_json($url);
        if (is_wp_error($res)) {
            wp_die(esc_html($res->get_error_message()));
        }
        if (isset($res['openid'])) {
            $openid = $res['openid'];
        } else {
            wp_die(esc_html(sprintf(__('获取OpenID失败：%s', 'qilingshop'), $res['errmsg'] ?? __('未知错误', 'qilingshop'))));
        }
    }

    // 2. 统一下单 JSAPI
    $params = [
        'appid'            => $app_id,
        'mch_id'           => $mch_id,
        'nonce_str'        => md5(time() . rand(100, 999)),
        'body'             => $subject, // 之前已修复为强制硬编码，无需再次处理
        'out_trade_no'     => $order_no,
        'total_fee'        => (int) round($price * 100),
        'spbill_create_ip' => $client_ip ?: '127.0.0.1',
        'notify_url'       => $notify_url,
        'trade_type'       => 'JSAPI',
        'openid'           => $openid
    ];
    
    // 签名
    ksort($params);
    $str = '';
    foreach ($params as $k => $v) {
        if ($v !== '' && $k !== 'sign') $str .= "$k=$v&";
    }
    $str .= "key=" . $api_key;
    $params['sign'] = strtoupper(md5($str));
    
    // 请求 XML
    $xml = '<xml>';
    foreach ($params as $k => $v) $xml .= "<$k><![CDATA[$v]]></$k>";
    $xml .= '</xml>';

    $response = qilingshop_wechat_gateway_http_post_xml('https://api.mch.weixin.qq.com/pay/unifiedorder', $xml);
    if (is_wp_error($response)) {
        wp_die(esc_html($response->get_error_message()));
    }
    
    $result = (array)simplexml_load_string($response, 'SimpleXMLElement', LIBXML_NOCDATA);
    
    if (isset($result['return_code']) && $result['return_code'] == 'SUCCESS' && isset($result['result_code']) && $result['result_code'] == 'SUCCESS') {
        $prepay_id = $result['prepay_id'];
        
        // 生成 JSAPI 参数
        $js_params = [
            'appId'     => $app_id,
            'timeStamp' => (string)time(),
            'nonceStr'  => md5(time() . rand(100, 999)),
            'package'   => "prepay_id=$prepay_id",
            'signType'  => 'MD5'
        ];
        
        ksort($js_params);
        $str = '';
        foreach ($js_params as $k => $v) $str .= "$k=$v&";
        $str .= "key=" . $api_key;
        $js_params['paySign'] = strtoupper(md5($str));
        
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php esc_html_e('微信安全支付', 'qilingshop'); ?></title>
        </head>
        <body>
            <script>
            function onBridgeReady(){
               WeixinJSBridge.invoke(
                  'getBrandWCPayRequest', {
                     "appId": "<?php echo $js_params['appId']; ?>",
                     "timeStamp": "<?php echo $js_params['timeStamp']; ?>",
                     "nonceStr": "<?php echo $js_params['nonceStr']; ?>",
                     "package": "<?php echo $js_params['package']; ?>",
                     "signType": "<?php echo $js_params['signType']; ?>",
                     "paySign": "<?php echo $js_params['paySign']; ?>"
                  },
                  function(res){
                      if(res.err_msg == "get_brand_wcpay_request:ok" ) {
                          window.location.href = "<?php echo esc_js($safe_return_url); ?>";
                      } else if (res.err_msg == "get_brand_wcpay_request:cancel") {
                          alert("<?php echo esc_js(__('支付取消', 'qilingshop')); ?>");
                          history.go(-1);
                      } else {
                          alert("<?php echo esc_js(__('支付失败：', 'qilingshop')); ?>" + res.err_msg);
                      }
                  }
               );
            }
            if (typeof WeixinJSBridge == "undefined"){
               if( document.addEventListener ){
                   document.addEventListener('WeixinJSBridgeReady', onBridgeReady, false);
               }else if (document.attachEvent){
                   document.attachEvent('WeixinJSBridgeReady', onBridgeReady); 
                   document.attachEvent('onWeixinJSBridgeReady', onBridgeReady);
               }
            }else{
               onBridgeReady();
            }
            </script>
        </body>
        </html>
        <?php
        exit;
    } else {
        wp_die(esc_html(sprintf(__('JSAPI下单失败：%s', 'qilingshop'), $result['err_code_des'] ?? $result['return_msg'] ?? __('未知错误', 'qilingshop'))));
    }

// 场景 2: 手机浏览器 (H5/唤醒APP)
} elseif (qilingshop_is_mobile()) {
    $params = [
        'appid'            => $app_id,
        'mch_id'           => $mch_id,
        'nonce_str'        => md5(time() . rand(100, 999)),
        'body'             => $subject, // 直接使用，不需要转义
        'out_trade_no'     => $order_no,
        'total_fee'        => (int) round($price * 100),
        'spbill_create_ip' => $client_ip ?: '127.0.0.1',
        'notify_url'       => $notify_url,
        'trade_type'       => 'MWEB',
        'scene_info'       => json_encode(['h5_info' => ['type' => 'Wap', 'wap_url' => home_url(), 'wap_name' => get_bloginfo('name')]])
    ];
    
    // 签名
    ksort($params);
    $str = '';
    foreach ($params as $k => $v) {
        if ($v !== '' && $k !== 'sign') $str .= "$k=$v&";
    }
    $str .= "key=" . $api_key;
    $params['sign'] = strtoupper(md5($str));
    
    // 请求 XML
    $xml = '<xml>';
    foreach ($params as $k => $v) $xml .= "<$k><![CDATA[$v]]></$k>";
    $xml .= '</xml>';

    $response = qilingshop_wechat_gateway_http_post_xml('https://api.mch.weixin.qq.com/pay/unifiedorder', $xml);
    if (is_wp_error($response)) {
        wp_die(esc_html($response->get_error_message()));
    }
    
    $result = (array)simplexml_load_string($response, 'SimpleXMLElement', LIBXML_NOCDATA);
    
    if (isset($result['return_code']) && $result['return_code'] == 'SUCCESS' && isset($result['result_code']) && $result['result_code'] == 'SUCCESS') {
        $mweb_url = $result['mweb_url'];
        // 跳转到 H5 支付链接 (需配置 referer)
        header("Location: $mweb_url");
        exit;
    } else {
        wp_die(esc_html(sprintf(__('H5下单失败：%s', 'qilingshop'), $result['err_code_des'] ?? $result['return_msg'] ?? __('未知错误', 'qilingshop'))));
    }

// 场景 3: PC 扫码 (NATIVE)
} else {
    // 构建统一下单参数
    $params = [
        'appid'            => $app_id,
        'mch_id'           => $mch_id,
        'nonce_str'        => md5(time() . rand(100, 999)),
        'body'             => $subject,
        'out_trade_no'     => $order_no,
        'total_fee'        => (int) round($price * 100), // 分
        'spbill_create_ip' => $client_ip ?: '127.0.0.1',
        'notify_url'       => $notify_url,
        'trade_type'       => 'NATIVE',
    ];

    // 生成签名
    ksort($params);
    $str = '';
    foreach ($params as $k => $v) {
        if ($v !== '' && $k !== 'sign') {
            $str .= "$k=$v&";
        }
    }
    $str .= "key=" . $api_key;
    $params['sign'] = strtoupper(md5($str));

    // 构建XML
    $xml = '<xml>';
    foreach ($params as $k => $v) {
        $xml .= "<$k><![CDATA[$v]]></$k>";
    }
    $xml .= '</xml>';

    // 发送请求
    $response = qilingshop_wechat_gateway_http_post_xml('https://api.mch.weixin.qq.com/pay/unifiedorder', $xml);
    if (is_wp_error($response)) {
        wp_die(esc_html($response->get_error_message()));
    }

    // 解析结果
    $result = [];
    if ($response) {
        $result = (array)simplexml_load_string($response, 'SimpleXMLElement', LIBXML_NOCDATA);
    }

    if (isset($result['return_code']) && $result['return_code'] == 'SUCCESS' && isset($result['result_code']) && $result['result_code'] == 'SUCCESS') {
        $code_url = $result['code_url'];
    } else {
        wp_die(esc_html(sprintf(__('微信支付接口调用失败：%s', 'qilingshop'), $result['err_code_des'] ?? $result['return_msg'] ?? __('未知错误', 'qilingshop'))));
    }
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?php esc_html_e('微信扫码支付', 'qilingshop'); ?></title>
    <style>
        body{background:#f5f5f5;margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}
        .pay-box{width:400px;margin:100px auto;background:#fff;padding:30px;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,0.05);text-align:center}
        .pay-box h2{margin:0 0 20px;font-size:20px;color:#333;font-weight:normal}
        .pay-amount{font-size:36px;color:#333;font-weight:bold;margin-bottom:20px}
        .pay-amount span{font-size:18px;top:-10px;position:relative;margin-right:2px}
        .qr-code{position:relative;width:200px;height:200px;margin:0 auto 20px;}
        .qr-code img { width: 100%; height: 100%; display: block; }
        .pay-tip { color: #666; font-size: 14px; margin: 10px 0; }
        #time { color: #ff4d4f; font-size: 16px; margin: 15px 0; height: 24px; line-height: 24px;}
        #time span { color: #ff4d4f; font-weight: bold; }
        .expired { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 18px; border-radius: 4px; z-index: 10; cursor: not-allowed;}
    </style>
    <script src="<?php echo QILINGSHOP_URL; ?>static/js/jquery-3.7.1.min.js"></script>
</head>
<body>
    <div class="pay-box">
        <img src="<?php echo QILINGSHOP_URL; ?>static/img/wx.png" style="width:40px;height:40px;margin-bottom:10px;border-radius:50%;box-shadow:0 4px 10px rgba(0,0,0,0.1);background:#fff;padding:4px;">
        <h2><?php esc_html_e('微信扫码支付', 'qilingshop'); ?></h2>
        <div class="pay-amount"><span>¥</span><?php echo sprintf('%.2f', $price); ?></div>
        
        <div class="qr-code">
            <?php $qrcode_src = QILINGSHOP_URL . 'includes/qrcode.php?data=' . rawurlencode($code_url); ?>
            <img src="<?php echo esc_url($qrcode_src); ?>" alt="<?php esc_attr_e('二维码', 'qilingshop'); ?>">
        </div>
        
        <p class="pay-tip"><?php esc_html_e('请使用微信扫一扫支付', 'qilingshop'); ?></p>
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
<?php } ?>
