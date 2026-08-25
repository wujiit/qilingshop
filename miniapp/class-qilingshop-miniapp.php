<?php
/**
 * 小程序联动支持（独立目录，便于后续排查/扩展）
 *
 * 说明：
 * - 本文件只负责 qilingshop 与 qilingminiapp 的联动。
 * - 不改原有商城下单/支付主流程，避免影响网页端。
 *
 * @package QilingShop
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Miniapp {

    /**
     * 单例实例
     *
     * @var QilingShop_Miniapp|null
     */
    private static $instance = null;

    /**
     * 获取单例
     *
     * @return QilingShop_Miniapp
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 构造：注册联动过滤器
     */
    private function __construct() {
        // 让小程序插件读取商城总开关，实现“小程序一键关闭商城入口”。
        add_filter('qilingminiapp_shop_enabled', [$this, 'filter_miniapp_shop_enabled'], 10, 2);
    }

    /**
     * 根据商城配置过滤小程序商城开关。
     *
     * @param bool  $enabled 当前小程序侧的开关结果
     * @param array $context 上下文（保留扩展）
     * @return bool
     */
    public function filter_miniapp_shop_enabled($enabled, $context = []) {
        if (!$enabled) {
            return false;
        }

        // 对齐商城后台“启用商城”总开关：关闭后小程序端也应隐藏商城入口。
        $shop_enabled = (bool) get_option('qls_shop_enabled', true);
        if (!$shop_enabled) {
            return false;
        }

        return true;
    }

    /**
     * 创建微信小程序支付参数。
     *
     * @param object $order  订单对象
     * @param string $openid 小程序 openid
     * @return array|WP_Error
     */
    public function create_wechat_miniapp_payment($order, $openid) {
        if (!is_object($order)) {
            return new WP_Error('qilingshop_wechat_miniapp_order_invalid', '订单不存在', ['status' => 404]);
        }

        $config = $this->get_wechat_miniapp_payment_config();
        if (is_wp_error($config)) {
            return $config;
        }

        $order_no = isset($order->order_no) ? sanitize_text_field((string) $order->order_no) : '';
        $amount = isset($order->final_amount) ? (float) $order->final_amount : 0;
        $openid = sanitize_text_field((string) $openid);
        if ($order_no === '' || $amount <= 0 || $openid === '') {
            return new WP_Error('qilingshop_wechat_miniapp_pay_invalid', '订单或支付参数异常，无法发起小程序支付', ['status' => 400]);
        }

        $body = '商城订单支付';
        if (isset($order->items) && is_array($order->items) && !empty($order->items[0]) && is_object($order->items[0])) {
            $title = sanitize_text_field((string) ($order->items[0]->product_title ?? ''));
            if ($title !== '') {
                $body = function_exists('mb_substr') ? mb_substr($title, 0, 40) : substr($title, 0, 40);
            }
        }

        $prepay_id = $this->request_wechat_miniapp_prepay($config, $order_no, $amount, $openid, $body);
        if (is_wp_error($prepay_id)) {
            return $prepay_id;
        }

        if ($this->is_wechat_miniapp_v3($config)) {
            $pay = $this->build_wechat_miniapp_v3_pay_params($config, $prepay_id);
        } else {
            $pay = [
                'appId' => $config['appid'],
                'timeStamp' => (string) time(),
                'nonceStr' => wp_generate_password(16, false, false),
                'package' => 'prepay_id=' . $prepay_id,
                'signType' => 'MD5',
            ];
            $pay['paySign'] = $this->build_wechat_pay_sign($pay, $config['key']);
        }

        if (is_wp_error($pay)) {
            return $pay;
        }

        return [
            'method' => 'wechat_miniapp',
            'provider' => 'wxpay',
            'version' => $config['pay_type'],
            'order_no' => $order_no,
            'prepay_id' => $prepay_id,
            'params' => $pay,
        ];
    }

    /**
     * 处理微信小程序支付回调。
     *
     * @param WP_REST_Request $request 请求对象
     * @return WP_REST_Response
     */
    public function handle_wechat_miniapp_notify($request) {
        $pay_type = sanitize_key((string) get_option('qilingshop_wechat_miniapp_pay_type', 'v2'));
        if (!in_array($pay_type, ['v2', 'v3'], true)) {
            $pay_type = 'v2';
        }

        $config = $this->get_wechat_miniapp_payment_config();
        if (is_wp_error($config)) {
            return $pay_type === 'v3'
                ? $this->wechat_v3_response('FAIL', $config->get_error_message())
                : $this->wechat_response('FAIL', $config->get_error_message());
        }

        if ($this->is_wechat_miniapp_v3($config)) {
            return $this->handle_wechat_miniapp_v3_notify($request, $config);
        }

        return $this->handle_wechat_miniapp_v2_notify($request, $config);
    }

    /**
     * 发起微信小程序原路退款。
     *
     * @param object $order   订单对象
     * @param array  $context 退款上下文
     * @return array|WP_Error
     */
    public function refund_wechat_miniapp_payment($order, $context = []) {
        if (!is_object($order)) {
            return new WP_Error('qilingshop_wechat_miniapp_refund_order_invalid', '订单不存在', ['status' => 404]);
        }

        $context = is_array($context) ? $context : [];
        $refund_no = sanitize_text_field((string) ($context['refund_no'] ?? ''));
        if ($refund_no === '') {
            return new WP_Error('qilingshop_wechat_miniapp_refund_no_missing', '缺少退款单号', ['status' => 400]);
        }

        $refund_amount = isset($context['refunded_amount']) ? (float) $context['refunded_amount'] : (float) ($order->final_amount ?? 0);
        if ($refund_amount <= 0) {
            return [
                'success'             => true,
                'status'              => 'success',
                'gateway_refund_no'   => $refund_no,
                'refunded_amount'     => 0,
                'message'             => '订单无需原路退回现金金额',
                'gateway_refunded_at' => current_time('mysql'),
                'raw'                 => ['version' => $this->resolve_wechat_miniapp_refund_version($order, $context)],
            ];
        }

        $pay_type = $this->resolve_wechat_miniapp_refund_version($order, $context);
        $config = $this->get_wechat_miniapp_payment_config($pay_type);
        if (is_wp_error($config)) {
            return $config;
        }

        if ($this->is_wechat_miniapp_v3($config)) {
            return $this->request_wechat_miniapp_v3_refund($config, $order, $context, $refund_amount);
        }

        return $this->request_wechat_miniapp_v2_refund($config, $order, $context, $refund_amount);
    }

    /**
     * 获取微信小程序支付配置。
     *
     * @param string $forced_pay_type 强制指定支付版本
     * @return array|WP_Error
     */
    private function get_wechat_miniapp_payment_config($forced_pay_type = '') {
        if (!(bool) get_option('qilingshop_wechat_miniapp_enabled', false)) {
            return new WP_Error('qilingshop_wechat_miniapp_disabled', '微信小程序支付未开启', ['status' => 403]);
        }

        $pay_type = sanitize_key((string) $forced_pay_type);
        if (!in_array($pay_type, ['v2', 'v3'], true)) {
            $pay_type = sanitize_key((string) get_option('qilingshop_wechat_miniapp_pay_type', 'v2'));
        }
        if (!in_array($pay_type, ['v2', 'v3'], true)) {
            $pay_type = 'v2';
        }

        $config = [
            'pay_type' => $pay_type,
            'appid' => sanitize_text_field((string) get_option('qilingshop_wechat_miniapp_appid', '')),
            'mchid' => sanitize_text_field((string) get_option('qilingshop_wechat_miniapp_mchid', '')),
            'key' => sanitize_text_field((string) get_option('qilingshop_wechat_miniapp_key', '')),
            'key_v3' => sanitize_text_field((string) get_option('qilingshop_wechat_miniapp_key_v3', '')),
            'serial_no' => sanitize_text_field((string) get_option('qilingshop_wechat_miniapp_serial_no', '')),
            'client_cert' => (string) get_option('qilingshop_wechat_miniapp_client_cert', ''),
            'client_key' => (string) get_option('qilingshop_wechat_miniapp_client_key', ''),
            'public_key_id' => sanitize_text_field((string) get_option('qilingshop_wechat_miniapp_public_key_id', '')),
            'public_key_pem' => (string) get_option('qilingshop_wechat_miniapp_public_key_pem', ''),
            'transfer_scene_id' => sanitize_text_field((string) get_option('qilingshop_wechat_miniapp_transfer_scene_id', '')),
            'notify_url' => esc_url_raw(rest_url('qls/v1/notify/wechat-miniapp')),
        ];

        $missing = [];
        if ($config['appid'] === '') {
            $missing[] = '小程序 AppID';
        }
        if ($config['mchid'] === '') {
            $missing[] = '商户号(MCHID)';
        }

        if ($this->is_wechat_miniapp_v3($config)) {
            if (!function_exists('openssl_sign') || !function_exists('openssl_verify') || !function_exists('openssl_decrypt')) {
                return new WP_Error('qilingshop_wechat_miniapp_openssl_missing', '当前 PHP 环境未启用 OpenSSL，无法使用微信小程序支付 v3', ['status' => 500]);
            }
            if ($config['key_v3'] === '') {
                $missing[] = 'APIv3 密钥';
            }
            if ($config['serial_no'] === '') {
                $missing[] = '商户证书序列号';
            }
            if (trim($config['client_cert']) === '') {
                $missing[] = '商户 API 证书';
            }
            if (trim($config['client_key']) === '') {
                $missing[] = '商户 API 私钥';
            }
            if ($config['public_key_id'] === '') {
                $missing[] = '微信支付平台公钥 ID';
            }
            if (trim($config['public_key_pem']) === '') {
                $missing[] = '微信支付平台公钥 PEM';
            }
        } elseif ($config['key'] === '') {
            $missing[] = '商户支付密钥(KEY)';
        }

        if (!empty($missing)) {
            return new WP_Error(
                'qilingshop_wechat_miniapp_config_missing',
                '微信小程序支付配置不完整：' . implode('、', $missing),
                ['status' => 500]
            );
        }

        return $config;
    }

    /**
     * 是否为微信小程序支付 v3。
     *
     * @param array $config 配置
     * @return bool
     */
    private function is_wechat_miniapp_v3($config) {
        return isset($config['pay_type']) && $config['pay_type'] === 'v3';
    }

    /**
     * 解析订单对应的小程序支付版本。
     *
     * @param object $order   订单对象
     * @param array  $context 退款上下文
     * @return string
     */
    private function resolve_wechat_miniapp_refund_version($order, $context = []) {
        $context = is_array($context) ? $context : [];
        $version = sanitize_key((string) ($context['payment_channel_version'] ?? ''));
        if (in_array($version, ['v2', 'v3'], true)) {
            return $version;
        }

        $version = sanitize_key((string) ($order->payment_channel_version ?? ''));
        if (in_array($version, ['v2', 'v3'], true)) {
            return $version;
        }

        $meta = $context['payment_channel_meta'] ?? ($order->payment_channel_meta ?? null);
        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $meta = $decoded;
            }
        }

        if (is_array($meta)) {
            $version = sanitize_key((string) ($meta['version'] ?? ''));
            if (in_array($version, ['v2', 'v3'], true)) {
                return $version;
            }
        }

        $version = sanitize_key((string) get_option('qilingshop_wechat_miniapp_pay_type', 'v2'));
        return in_array($version, ['v2', 'v3'], true) ? $version : 'v2';
    }

    /**
     * 调用微信统一下单。
     *
     * @param array  $config   支付配置
     * @param string $order_no 订单号
     * @param float  $amount   金额
     * @param string $openid   小程序 openid
     * @param string $body     商品描述
     * @return string|WP_Error
     */
    private function request_wechat_miniapp_prepay($config, $order_no, $amount, $openid, $body) {
        if ($this->is_wechat_miniapp_v3($config)) {
            return $this->request_wechat_miniapp_v3_prepay($config, $order_no, $amount, $openid, $body);
        }

        return $this->request_wechat_miniapp_v2_prepay($config, $order_no, $amount, $openid, $body);
    }

    /**
     * 调用微信小程序退款 v2。
     *
     * @param array  $config        支付配置
     * @param object $order         订单对象
     * @param array  $context       退款上下文
     * @param float  $refund_amount 退款金额
     * @return array|WP_Error
     */
    private function request_wechat_miniapp_v2_refund($config, $order, $context, $refund_amount) {
        $order_no = sanitize_text_field((string) ($order->order_no ?? ''));
        $transaction_id = sanitize_text_field((string) ($order->payment_no ?? ''));
        $refund_no = sanitize_text_field((string) ($context['refund_no'] ?? ''));
        $total_fee = (int) round((float) ($order->final_amount ?? 0) * 100);
        $refund_fee = (int) round((float) $refund_amount * 100);

        if ($order_no === '' || $refund_no === '') {
            return new WP_Error('qilingshop_wechat_miniapp_refund_params_invalid', '小程序退款参数不完整', ['status' => 400]);
        }

        if ($total_fee <= 0 || $refund_fee <= 0) {
            return new WP_Error('qilingshop_wechat_miniapp_refund_amount_invalid', '小程序退款金额异常', ['status' => 400]);
        }

        $client_cert = $this->get_wechat_miniapp_pem_contents($config['client_cert'], '商户 API 证书');
        if (is_wp_error($client_cert)) {
            return $client_cert;
        }

        $client_key = $this->get_wechat_miniapp_pem_contents($config['client_key'], '商户 API 私钥');
        if (is_wp_error($client_key)) {
            return $client_key;
        }

        $params = [
            'appid' => $config['appid'],
            'mch_id' => $config['mchid'],
            'nonce_str' => wp_generate_password(24, false, false),
            'out_refund_no' => $refund_no,
            'total_fee' => $total_fee,
            'refund_fee' => $refund_fee,
        ];

        if ($transaction_id !== '') {
            $params['transaction_id'] = $transaction_id;
        } else {
            $params['out_trade_no'] = $order_no;
        }

        $params['sign'] = $this->build_wechat_pay_sign($params, $config['key']);

        $response = $this->post_wechat_miniapp_v2_with_cert(
            'https://api.mch.weixin.qq.com/secapi/pay/refund',
            $this->to_wechat_xml($params),
            $client_cert,
            $client_key
        );
        if (is_wp_error($response)) {
            return $response;
        }

        $result = $this->parse_wechat_xml($response);
        if (empty($result)) {
            return new WP_Error('qilingshop_wechat_miniapp_refund_parse_failed', '微信小程序退款响应解析失败', ['status' => 502]);
        }

        if (strtoupper((string) ($result['return_code'] ?? '')) !== 'SUCCESS') {
            return new WP_Error(
                'qilingshop_wechat_miniapp_refund_return_failed',
                (string) ($result['return_msg'] ?? '微信小程序退款通信失败'),
                ['status' => 502, 'wechat' => $result]
            );
        }

        if (strtoupper((string) ($result['result_code'] ?? '')) !== 'SUCCESS') {
            $message = (string) ($result['err_code_des'] ?? ($result['return_msg'] ?? '微信小程序退款失败'));
            return new WP_Error(
                'qilingshop_wechat_miniapp_refund_result_failed',
                $message,
                ['status' => 502, 'wechat' => $result]
            );
        }

        return [
            'success'             => true,
            'status'              => 'success',
            'gateway_refund_no'   => sanitize_text_field((string) ($result['refund_id'] ?? $refund_no)),
            'refunded_amount'     => isset($result['refund_fee']) ? ((float) $result['refund_fee'] / 100) : (float) $refund_amount,
            'message'             => '微信小程序原路退款成功',
            'gateway_refunded_at' => current_time('mysql'),
            'raw'                 => array_merge($result, ['version' => 'v2']),
        ];
    }

    /**
     * 调用微信小程序退款 v3。
     *
     * @param array  $config        支付配置
     * @param object $order         订单对象
     * @param array  $context       退款上下文
     * @param float  $refund_amount 退款金额
     * @return array|WP_Error
     */
    private function request_wechat_miniapp_v3_refund($config, $order, $context, $refund_amount) {
        $order_no = sanitize_text_field((string) ($order->order_no ?? ''));
        $transaction_id = sanitize_text_field((string) ($order->payment_no ?? ''));
        $refund_no = sanitize_text_field((string) ($context['refund_no'] ?? ''));
        $total_fee = (int) round((float) ($order->final_amount ?? 0) * 100);
        $refund_fee = (int) round((float) $refund_amount * 100);

        if ($order_no === '' || $refund_no === '') {
            return new WP_Error('qilingshop_wechat_miniapp_v3_refund_params_invalid', '小程序退款参数不完整', ['status' => 400]);
        }

        if ($total_fee <= 0 || $refund_fee <= 0) {
            return new WP_Error('qilingshop_wechat_miniapp_v3_refund_amount_invalid', '小程序退款金额异常', ['status' => 400]);
        }

        $request_data = [
            'out_refund_no' => $refund_no,
            'amount' => [
                'refund' => $refund_fee,
                'total' => $total_fee,
                'currency' => 'CNY',
            ],
        ];

        if ($transaction_id !== '') {
            $request_data['transaction_id'] = $transaction_id;
        } else {
            $request_data['out_trade_no'] = $order_no;
        }

        $request_body = wp_json_encode($request_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $path = '/v3/refund/domestic/refunds';
        $authorization = $this->build_wechat_miniapp_v3_authorization('POST', $path, $request_body, $config);
        if (is_wp_error($authorization)) {
            return $authorization;
        }

        $response = wp_remote_post(
            'https://api.mch.weixin.qq.com' . $path,
            [
                'timeout' => 20,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json; charset=utf-8',
                    'Authorization' => $authorization,
                    'Wechatpay-Serial' => $config['serial_no'],
                ],
                'body' => $request_body,
                'sslverify' => (bool) apply_filters('qilingshop_wechat_miniapp_ssl_verify', true),
            ]
        );

        if (is_wp_error($response)) {
            return new WP_Error('qilingshop_wechat_miniapp_v3_refund_remote_failed', $response->get_error_message(), ['status' => 502]);
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $result = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($result)) {
            $result = [];
        }

        if ($http_code < 200 || $http_code >= 300) {
            $message = (string) ($result['message'] ?? $result['code'] ?? '微信小程序退款 v3 请求失败');
            return new WP_Error(
                'qilingshop_wechat_miniapp_v3_refund_http_failed',
                $message,
                ['status' => 502, 'wechat' => $result]
            );
        }

        return [
            'success'             => true,
            'status'              => sanitize_key((string) ($result['status'] ?? 'success')),
            'gateway_refund_no'   => sanitize_text_field((string) ($result['refund_id'] ?? $refund_no)),
            'refunded_amount'     => isset($result['amount']['refund']) ? ((float) $result['amount']['refund'] / 100) : (float) $refund_amount,
            'message'             => '微信小程序原路退款成功',
            'gateway_refunded_at' => current_time('mysql'),
            'raw'                 => array_merge($result, ['version' => 'v3']),
        ];
    }

    /**
     * 调用微信统一下单 v2。
     *
     * @param array  $config   支付配置
     * @param string $order_no 订单号
     * @param float  $amount   金额
     * @param string $openid   小程序 openid
     * @param string $body     商品描述
     * @return string|WP_Error
     */
    private function request_wechat_miniapp_v2_prepay($config, $order_no, $amount, $openid, $body) {
        $total_fee = (int) round((float) $amount * 100);
        if ($total_fee <= 0) {
            return new WP_Error('qilingshop_wechat_miniapp_amount_invalid', '支付金额必须大于 0', ['status' => 400]);
        }

        $params = [
            'appid' => $config['appid'],
            'mch_id' => $config['mchid'],
            'nonce_str' => wp_generate_password(24, false, false),
            'body' => $body !== '' ? $body : '商城订单支付',
            'out_trade_no' => $order_no,
            'total_fee' => $total_fee,
            'spbill_create_ip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string) $_SERVER['REMOTE_ADDR']) : '127.0.0.1',
            'notify_url' => $config['notify_url'],
            'trade_type' => 'JSAPI',
            'openid' => $openid,
        ];
        $params['sign'] = $this->build_wechat_pay_sign($params, $config['key']);

        $response = wp_remote_post(
            'https://api.mch.weixin.qq.com/pay/unifiedorder',
            [
                'timeout' => 20,
                'headers' => ['Content-Type' => 'text/xml; charset=utf-8'],
                'body' => $this->to_wechat_xml($params),
                'sslverify' => (bool) apply_filters('qilingshop_wechat_miniapp_ssl_verify', true),
            ]
        );

        if (is_wp_error($response)) {
            return new WP_Error('qilingshop_wechat_miniapp_remote_failed', $response->get_error_message(), ['status' => 502]);
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        if ($http_code < 200 || $http_code >= 300) {
            return new WP_Error('qilingshop_wechat_miniapp_http_failed', '微信小程序支付请求失败', ['status' => 502]);
        }

        $result = $this->parse_wechat_xml(wp_remote_retrieve_body($response));
        if (empty($result)) {
            return new WP_Error('qilingshop_wechat_miniapp_parse_failed', '微信小程序支付响应解析失败', ['status' => 502]);
        }

        if (strtoupper((string) ($result['return_code'] ?? '')) !== 'SUCCESS') {
            return new WP_Error('qilingshop_wechat_miniapp_return_failed', (string) ($result['return_msg'] ?? '微信通信失败'), ['status' => 502, 'wechat' => $result]);
        }

        if (strtoupper((string) ($result['result_code'] ?? '')) !== 'SUCCESS') {
            $message = (string) ($result['err_code_des'] ?? ($result['return_msg'] ?? '统一下单失败'));
            return new WP_Error('qilingshop_wechat_miniapp_result_failed', $message, ['status' => 502, 'wechat' => $result]);
        }

        $prepay_id = sanitize_text_field((string) ($result['prepay_id'] ?? ''));
        if ($prepay_id === '') {
            return new WP_Error('qilingshop_wechat_miniapp_prepay_missing', '微信预支付单号缺失', ['status' => 502, 'wechat' => $result]);
        }

        return $prepay_id;
    }

    /**
     * 调用微信统一下单 v3。
     *
     * @param array  $config   支付配置
     * @param string $order_no 订单号
     * @param float  $amount   金额
     * @param string $openid   小程序 openid
     * @param string $body     商品描述
     * @return string|WP_Error
     */
    private function request_wechat_miniapp_v3_prepay($config, $order_no, $amount, $openid, $body) {
        $total_fee = (int) round((float) $amount * 100);
        if ($total_fee <= 0) {
            return new WP_Error('qilingshop_wechat_miniapp_amount_invalid', '支付金额必须大于 0', ['status' => 400]);
        }

        $request_body = wp_json_encode([
            'appid' => $config['appid'],
            'mchid' => $config['mchid'],
            'description' => $body !== '' ? $body : '商城订单支付',
            'out_trade_no' => $order_no,
            'notify_url' => $config['notify_url'],
            'amount' => [
                'total' => $total_fee,
                'currency' => 'CNY',
            ],
            'payer' => [
                'openid' => $openid,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $path = '/v3/pay/transactions/jsapi';
        $authorization = $this->build_wechat_miniapp_v3_authorization('POST', $path, $request_body, $config);
        if (is_wp_error($authorization)) {
            return $authorization;
        }

        $response = wp_remote_post(
            'https://api.mch.weixin.qq.com' . $path,
            [
                'timeout' => 20,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json; charset=utf-8',
                    'Authorization' => $authorization,
                    'Wechatpay-Serial' => $config['serial_no'],
                ],
                'body' => $request_body,
                'sslverify' => (bool) apply_filters('qilingshop_wechat_miniapp_ssl_verify', true),
            ]
        );

        if (is_wp_error($response)) {
            return new WP_Error('qilingshop_wechat_miniapp_v3_remote_failed', $response->get_error_message(), ['status' => 502]);
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $result = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($result)) {
            $result = [];
        }

        if ($http_code < 200 || $http_code >= 300) {
            $message = (string) ($result['message'] ?? $result['code'] ?? '微信小程序支付 v3 请求失败');
            return new WP_Error('qilingshop_wechat_miniapp_v3_http_failed', $message, ['status' => 502, 'wechat' => $result]);
        }

        $prepay_id = sanitize_text_field((string) ($result['prepay_id'] ?? ''));
        if ($prepay_id === '') {
            return new WP_Error('qilingshop_wechat_miniapp_v3_prepay_missing', '微信小程序支付 v3 预支付单号缺失', ['status' => 502, 'wechat' => $result]);
        }

        return $prepay_id;
    }

    /**
     * 构造微信小程序 v3 唤起参数。
     *
     * @param array  $config    支付配置
     * @param string $prepay_id 预支付单号
     * @return array|WP_Error
     */
    private function build_wechat_miniapp_v3_pay_params($config, $prepay_id) {
        $time_stamp = (string) time();
        $nonce_str = wp_generate_password(16, false, false);
        $package = 'prepay_id=' . $prepay_id;
        $message = $config['appid'] . "\n" . $time_stamp . "\n" . $nonce_str . "\n" . $package . "\n";
        $signature = $this->sign_wechat_miniapp_v3_message($message, $config);
        if (is_wp_error($signature)) {
            return $signature;
        }

        return [
            'appId' => $config['appid'],
            'timeStamp' => $time_stamp,
            'nonceStr' => $nonce_str,
            'package' => $package,
            'signType' => 'RSA',
            'paySign' => $signature,
        ];
    }

    /**
     * 构造微信支付 v3 Authorization 头。
     *
     * @param string $method HTTP 方法
     * @param string $path   请求路径
     * @param string $body   请求体
     * @param array  $config 支付配置
     * @return string|WP_Error
     */
    private function build_wechat_miniapp_v3_authorization($method, $path, $body, $config) {
        $timestamp = (string) time();
        $nonce_str = wp_generate_password(32, false, false);
        $message = strtoupper((string) $method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce_str . "\n" . $body . "\n";
        $signature = $this->sign_wechat_miniapp_v3_message($message, $config);
        if (is_wp_error($signature)) {
            return $signature;
        }

        return sprintf(
            'WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",timestamp="%s",serial_no="%s",signature="%s"',
            $config['mchid'],
            $nonce_str,
            $timestamp,
            $config['serial_no'],
            $signature
        );
    }

    /**
     * 使用商户私钥对消息签名。
     *
     * @param string $message 消息体
     * @param array  $config  支付配置
     * @return string|WP_Error
     */
    private function sign_wechat_miniapp_v3_message($message, $config) {
        $private_key = $this->get_wechat_miniapp_pem_contents($config['client_key'], '商户 API 私钥');
        if (is_wp_error($private_key)) {
            return $private_key;
        }

        $signature = '';
        $signed = openssl_sign($message, $signature, $private_key, OPENSSL_ALGO_SHA256);
        if (!$signed || $signature === '') {
            return new WP_Error('qilingshop_wechat_miniapp_v3_sign_failed', '微信小程序支付 v3 签名失败', ['status' => 500]);
        }

        return base64_encode($signature);
    }

    /**
     * 处理微信小程序支付 v2 回调。
     *
     * @param WP_REST_Request $request 请求对象
     * @param array           $config  支付配置
     * @return WP_REST_Response
     */
    private function handle_wechat_miniapp_v2_notify($request, $config) {
        $xml = method_exists($request, 'get_body') ? (string) $request->get_body() : '';
        if ($xml === '') {
            return $this->wechat_response('FAIL', '数据为空');
        }

        $data = $this->parse_wechat_xml($xml);
        if (empty($data)) {
            return $this->wechat_response('FAIL', 'XML解析失败');
        }

        if (isset($data['return_code']) && strtoupper((string) $data['return_code']) !== 'SUCCESS') {
            return $this->wechat_response('FAIL', '通信失败');
        }

        $sign = isset($data['sign']) ? (string) $data['sign'] : '';
        if ($sign === '' || !hash_equals($this->build_wechat_pay_sign($data, $config['key']), strtoupper($sign))) {
            return $this->wechat_response('FAIL', '签名错误');
        }

        $notify_appid = sanitize_text_field((string) ($data['appid'] ?? ''));
        $notify_mchid = sanitize_text_field((string) ($data['mch_id'] ?? ''));
        if (!hash_equals($config['appid'], $notify_appid) || !hash_equals($config['mchid'], $notify_mchid)) {
            return $this->wechat_response('FAIL', '小程序商户不匹配');
        }

        if (isset($data['result_code']) && strtoupper((string) ($data['result_code'] ?? '')) !== 'SUCCESS') {
            return $this->wechat_response('FAIL', '支付失败');
        }

        $order_no = sanitize_text_field((string) ($data['out_trade_no'] ?? ''));
        $transaction_id = sanitize_text_field((string) ($data['transaction_id'] ?? ''));
        $paid_amount = (float) ($data['total_fee'] ?? 0) / 100;
        if ($order_no === '' || $paid_amount <= 0) {
            return $this->wechat_response('FAIL', '参数错误');
        }

        $result = $this->process_wechat_miniapp_payment_success($order_no, $paid_amount, $transaction_id);

        return $result === 'success'
            ? $this->wechat_response('SUCCESS', 'OK')
            : $this->wechat_response('FAIL', $result);
    }

    /**
     * 处理微信小程序支付 v3 回调。
     *
     * @param WP_REST_Request $request 请求对象
     * @param array           $config  支付配置
     * @return WP_REST_Response
     */
    private function handle_wechat_miniapp_v3_notify($request, $config) {
        $payload = method_exists($request, 'get_body') ? (string) $request->get_body() : '';
        if ($payload === '') {
            return $this->wechat_v3_response('FAIL', '数据为空');
        }

        $verified = $this->verify_wechat_miniapp_v3_notify($request, $payload, $config);
        if (is_wp_error($verified)) {
            return $this->wechat_v3_response('FAIL', $verified->get_error_message());
        }

        $notify = json_decode($payload, true);
        if (!is_array($notify)) {
            return $this->wechat_v3_response('FAIL', 'JSON解析失败');
        }

        if (($notify['event_type'] ?? '') !== 'TRANSACTION.SUCCESS') {
            return $this->wechat_v3_response('SUCCESS', 'IGNORE');
        }

        $resource = isset($notify['resource']) && is_array($notify['resource']) ? $notify['resource'] : [];
        $decrypted = $this->decrypt_wechat_miniapp_v3_resource($resource, $config);
        if (is_wp_error($decrypted)) {
            return $this->wechat_v3_response('FAIL', $decrypted->get_error_message());
        }

        $notify_appid = sanitize_text_field((string) ($decrypted['appid'] ?? ''));
        $notify_mchid = sanitize_text_field((string) ($decrypted['mchid'] ?? ''));
        if (!hash_equals($config['appid'], $notify_appid) || !hash_equals($config['mchid'], $notify_mchid)) {
            return $this->wechat_v3_response('FAIL', '小程序商户不匹配');
        }

        if (isset($decrypted['trade_state']) && strtoupper((string) $decrypted['trade_state']) !== 'SUCCESS') {
            return $this->wechat_v3_response('FAIL', '支付失败');
        }

        $order_no = sanitize_text_field((string) ($decrypted['out_trade_no'] ?? ''));
        $transaction_id = sanitize_text_field((string) ($decrypted['transaction_id'] ?? ''));
        $paid_amount = isset($decrypted['amount']['total']) ? (float) $decrypted['amount']['total'] / 100 : 0;
        if ($order_no === '' || $paid_amount <= 0) {
            return $this->wechat_v3_response('FAIL', '参数错误');
        }

        $result = $this->process_wechat_miniapp_payment_success($order_no, $paid_amount, $transaction_id);
        return $result === 'success'
            ? $this->wechat_v3_response('SUCCESS', 'OK')
            : $this->wechat_v3_response('FAIL', $result);
    }

    /**
     * 验证微信支付 v3 回调签名。
     *
     * @param WP_REST_Request $request 请求对象
     * @param string          $payload 原始请求体
     * @param array           $config  支付配置
     * @return true|WP_Error
     */
    private function verify_wechat_miniapp_v3_notify($request, $payload, $config) {
        $signature = $this->get_request_header($request, 'wechatpay-signature');
        $timestamp = $this->get_request_header($request, 'wechatpay-timestamp');
        $nonce = $this->get_request_header($request, 'wechatpay-nonce');
        $serial = $this->get_request_header($request, 'wechatpay-serial');

        if ($signature === '' || $timestamp === '' || $nonce === '' || $serial === '') {
            return new WP_Error('qilingshop_wechat_miniapp_v3_notify_header_missing', '微信小程序支付 v3 回调请求头不完整', ['status' => 400]);
        }

        if (!preg_match('/^\d{10}$/', $timestamp)) {
            return new WP_Error('qilingshop_wechat_miniapp_v3_notify_timestamp_invalid', '微信小程序支付 v3 回调时间戳格式错误', ['status' => 400]);
        }

        $timestamp_int = (int) $timestamp;
        $tolerance = (int) apply_filters('qilingshop_wechat_miniapp_v3_notify_tolerance', 300, $request);
        if ($tolerance > 0 && abs(time() - $timestamp_int) > $tolerance) {
            return new WP_Error('qilingshop_wechat_miniapp_v3_notify_expired', '微信小程序支付 v3 回调已过期', ['status' => 400]);
        }

        if ($config['public_key_id'] !== '' && !hash_equals($config['public_key_id'], $serial)) {
            return new WP_Error('qilingshop_wechat_miniapp_v3_notify_serial_mismatch', '微信支付平台公钥 ID 不匹配', ['status' => 400]);
        }

        $public_key = $this->get_wechat_miniapp_pem_contents($config['public_key_pem'], '微信支付平台公钥 PEM');
        if (is_wp_error($public_key)) {
            return $public_key;
        }

        $decoded_signature = base64_decode($signature, true);
        if ($decoded_signature === false || $decoded_signature === '') {
            return new WP_Error('qilingshop_wechat_miniapp_v3_notify_signature_invalid', '微信小程序支付 v3 回调签名格式错误', ['status' => 400]);
        }

        $message = $timestamp . "\n" . $nonce . "\n" . $payload . "\n";
        $verified = openssl_verify($message, $decoded_signature, $public_key, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            return new WP_Error('qilingshop_wechat_miniapp_v3_notify_verify_failed', '微信小程序支付 v3 回调验签失败', ['status' => 400]);
        }

        return true;
    }

    /**
     * 解密微信支付 v3 回调资源。
     *
     * @param array $resource 资源数据
     * @param array $config   支付配置
     * @return array|WP_Error
     */
    private function decrypt_wechat_miniapp_v3_resource($resource, $config) {
        $ciphertext = (string) ($resource['ciphertext'] ?? '');
        $nonce = (string) ($resource['nonce'] ?? '');
        $associated_data = (string) ($resource['associated_data'] ?? '');
        if ($ciphertext === '' || $nonce === '') {
            return new WP_Error('qilingshop_wechat_miniapp_v3_resource_invalid', '微信小程序支付 v3 回调数据不完整', ['status' => 400]);
        }

        $decoded = base64_decode($ciphertext, true);
        if ($decoded === false || strlen($decoded) <= 16) {
            return new WP_Error('qilingshop_wechat_miniapp_v3_resource_decode_failed', '微信小程序支付 v3 回调密文异常', ['status' => 400]);
        }

        $auth_tag = substr($decoded, -16);
        $cipher_raw = substr($decoded, 0, -16);
        $plain = openssl_decrypt($cipher_raw, 'aes-256-gcm', $config['key_v3'], OPENSSL_RAW_DATA, $nonce, $auth_tag, $associated_data);
        if ($plain === false || $plain === '') {
            return new WP_Error('qilingshop_wechat_miniapp_v3_resource_decrypt_failed', '微信小程序支付 v3 回调解密失败', ['status' => 400]);
        }

        $data = json_decode($plain, true);
        if (!is_array($data)) {
            return new WP_Error('qilingshop_wechat_miniapp_v3_resource_parse_failed', '微信小程序支付 v3 回调解密结果解析失败', ['status' => 400]);
        }

        return $data;
    }

    /**
     * 获取请求头。
     *
     * @param WP_REST_Request $request 请求对象
     * @param string          $name    请求头名称
     * @return string
     */
    private function get_request_header($request, $name) {
        if (!is_object($request) || !method_exists($request, 'get_header')) {
            return '';
        }

        $value = (string) $request->get_header($name);
        if ($value === '') {
            $value = (string) $request->get_header(str_replace('-', '_', $name));
        }

        return trim($value);
    }

    /**
     * 获取 PEM 内容，兼容直接粘贴、绝对路径和站内 URL。
     *
     * @param string $raw   原始值
     * @param string $label 字段名称
     * @return string|WP_Error
     */
    private function get_wechat_miniapp_pem_contents($raw, $label) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return new WP_Error('qilingshop_wechat_miniapp_pem_empty', $label . ' 不能为空', ['status' => 500]);
        }

        $normalized = $this->normalize_wechat_miniapp_pem($raw);
        if (strpos($normalized, '-----BEGIN') !== false) {
            return $normalized;
        }

        $candidates = [$raw];
        if (preg_match('#^https?://#i', $raw)) {
            $path = (string) wp_parse_url($raw, PHP_URL_PATH);
            if ($path !== '') {
                $candidates[] = $path;
            }
        }

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }

            $paths = [$candidate];
            if (defined('ABSPATH')) {
                $paths[] = trailingslashit(ABSPATH) . ltrim($candidate, '/');
            }

            foreach ($paths as $path) {
                if (!is_string($path) || $path === '' || !file_exists($path) || !is_readable($path)) {
                    continue;
                }

                $contents = @file_get_contents($path);
                if ($contents === false || trim((string) $contents) === '') {
                    continue;
                }

                $contents = $this->normalize_wechat_miniapp_pem($contents);
                if (strpos($contents, '-----BEGIN') !== false) {
                    return $contents;
                }
            }
        }

        return new WP_Error('qilingshop_wechat_miniapp_pem_invalid', $label . ' 读取失败，请检查 PEM 内容或文件路径', ['status' => 500]);
    }

    /**
     * 规范化 PEM 内容。
     *
     * @param string $pem PEM 内容
     * @return string
     */
    private function normalize_wechat_miniapp_pem($pem) {
        $pem = trim((string) $pem);
        if ($pem === '') {
            return '';
        }

        $pem = str_replace(["\r\n", "\r"], "\n", $pem);
        if (strpos($pem, '\n') !== false && strpos($pem, "\n") === false) {
            $pem = str_replace('\n', "\n", $pem);
        }

        return trim($pem) . "\n";
    }

    /**
     * 使用双向证书向微信 v2 退款接口发起请求。
     *
     * @param string $url         请求地址
     * @param string $xml         XML 请求体
     * @param string $client_cert 商户 API 证书内容
     * @param string $client_key  商户 API 私钥内容
     * @return string|WP_Error
     */
    private function post_wechat_miniapp_v2_with_cert($url, $xml, $client_cert, $client_key) {
        if (!function_exists('curl_init')) {
            return new WP_Error('qilingshop_wechat_miniapp_refund_curl_missing', '当前 PHP 环境未启用 cURL，无法发起微信小程序退款', ['status' => 500]);
        }

        $cert_file = $this->write_wechat_miniapp_temp_pem_file('qls_miniapp_refund_cert_', $client_cert);
        if (is_wp_error($cert_file)) {
            return $cert_file;
        }

        $key_file = $this->write_wechat_miniapp_temp_pem_file('qls_miniapp_refund_key_', $client_key);
        if (is_wp_error($key_file)) {
            @unlink($cert_file);
            return $key_file;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSLCERT, $cert_file);
        curl_setopt($ch, CURLOPT_SSLKEY, $key_file);
        curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'PEM');
        curl_setopt($ch, CURLOPT_SSLKEYTYPE, 'PEM');
        $ssl_verify = (bool) apply_filters('qilingshop_wechat_miniapp_ssl_verify', true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $ssl_verify ? true : false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $ssl_verify ? 2 : 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);

        @unlink($cert_file);
        @unlink($key_file);

        if ($response === false) {
            return new WP_Error(
                'qilingshop_wechat_miniapp_refund_http_failed',
                $curl_error !== '' ? $curl_error : '微信小程序退款请求失败',
                ['status' => 502]
            );
        }

        return $response;
    }

    /**
     * 写入微信退款 PEM 临时文件。
     *
     * @param string $prefix   文件前缀
     * @param string $contents PEM 内容
     * @return string|WP_Error
     */
    private function write_wechat_miniapp_temp_pem_file($prefix, $contents) {
        $tmp_file = function_exists('wp_tempnam') ? wp_tempnam($prefix) : tempnam(sys_get_temp_dir(), $prefix);
        if (!$tmp_file) {
            return new WP_Error('qilingshop_wechat_miniapp_refund_temp_file_failed', '无法创建微信小程序退款临时证书文件', ['status' => 500]);
        }

        $written = @file_put_contents($tmp_file, $contents);
        if ($written === false) {
            @unlink($tmp_file);
            return new WP_Error('qilingshop_wechat_miniapp_refund_temp_write_failed', '无法写入微信小程序退款临时证书文件', ['status' => 500]);
        }

        return $tmp_file;
    }

    /**
     * 处理支付成功。
     *
     * @param string $order_no       订单号
     * @param float  $paid_amount    实付金额
     * @param string $transaction_id 微信交易号
     * @return string
     */
    private function process_wechat_miniapp_payment_success($order_no, $paid_amount, $transaction_id) {
        if (!function_exists('qls_shop_order')) {
            return '商城订单服务不可用';
        }

        $order = qls_shop_order()->get_by_order_no($order_no);
        if (!$order) {
            return '订单不存在';
        }

        $expected_amount = isset($order->final_amount) ? (float) $order->final_amount : 0;
        if ($expected_amount > 0 && (int) round($expected_amount * 100) !== (int) round((float) $paid_amount * 100)) {
            return '金额不匹配';
        }

        $pay_type = sanitize_key((string) get_option('qilingshop_wechat_miniapp_pay_type', 'v2'));
        if (!in_array($pay_type, ['v2', 'v3'], true)) {
            $pay_type = 'v2';
        }

        $marked = qls_shop_order()->mark_paid($order_no, $transaction_id, 'wechat_miniapp', [
            'payment_channel_version' => $pay_type,
            'payment_channel_meta'    => [
                'source'  => 'wechat_miniapp',
                'version' => $pay_type,
            ],
        ]);
        return $marked ? 'success' : '订单处理失败';
    }

    /**
     * 微信签名。
     *
     * @param array  $params 参数
     * @param string $key    商户密钥
     * @return string
     */
    private function build_wechat_pay_sign($params, $key) {
        ksort($params);
        $pairs = [];
        foreach ((array) $params as $k => $v) {
            if ($k === 'sign' || $v === '' || $v === null || is_array($v)) {
                continue;
            }
            $pairs[] = $k . '=' . $v;
        }

        $pairs[] = 'key=' . $key;
        return strtoupper(md5(implode('&', $pairs)));
    }

    /**
     * 转换微信 XML。
     *
     * @param array $data 数据
     * @return string
     */
    private function to_wechat_xml($data) {
        $xml = '<xml>';
        foreach ((array) $data as $key => $value) {
            $name = sanitize_key((string) $key);
            if ($name === '') {
                continue;
            }
            if (is_numeric($value)) {
                $xml .= '<' . $name . '>' . $value . '</' . $name . '>';
            } else {
                $xml .= '<' . $name . '><![CDATA[' . (string) $value . ']]></' . $name . '>';
            }
        }
        $xml .= '</xml>';

        return $xml;
    }

    /**
     * 解析微信 XML。
     *
     * @param string $xml XML 字符串
     * @return array
     */
    private function parse_wechat_xml($xml) {
        $xml = trim((string) $xml);
        if ($xml === '') {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $object = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($object === false) {
            return [];
        }

        $data = json_decode(wp_json_encode($object), true);
        return is_array($data) ? $data : [];
    }

    /**
     * 微信回调响应。
     *
     * @param string $code 返回码
     * @param string $msg  返回消息
     * @return WP_REST_Response
     */
    private function wechat_response($code, $msg) {
        $xml = '<xml><return_code><![CDATA[' . $code . ']]></return_code><return_msg><![CDATA[' . $msg . ']]></return_msg></xml>';
        $response = new WP_REST_Response($xml, 200);
        $response->header('Content-Type', 'text/xml; charset=utf-8');

        return $response;
    }

    /**
     * 微信支付 v3 回调响应。
     *
     * @param string $code 返回码
     * @param string $msg  返回消息
     * @return WP_REST_Response
     */
    private function wechat_v3_response($code, $msg) {
        $status = strtoupper((string) $code) === 'SUCCESS' ? 200 : 500;
        $response = new WP_REST_Response(
            wp_json_encode([
                'code' => $code,
                'message' => $msg,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status
        );
        $response->header('Content-Type', 'application/json; charset=utf-8');

        return $response;
    }
}
