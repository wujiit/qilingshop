<?php
/**
 * 虎皮椒 V3 支付网关
 *
 * @package QilingShop
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Payment_Xhpay {

    private $default_type = 'alipay';
    private $plugin_id = 'qilingshop-xhpay3';

    public function __construct() {
        $default_type = sanitize_key((string) get_option('qilingshop_xhpay_default_type', 'alipay'));
        if ($default_type === 'wechat') {
            $this->default_type = 'wechat';
        }

        $plugin_id = sanitize_key((string) get_option('qilingshop_xhpay_plugin_id', 'qilingshop-xhpay3'));
        if ($plugin_id !== '') {
            $this->plugin_id = $plugin_id;
        }
    }

    /**
     * 创建支付（统一转到 xhpay.php 入口）
     */
    public function create($params) {
        $order_no = sanitize_text_field((string) ($params['order_no'] ?? ''));
        $amount = (float) ($params['amount'] ?? 0);
        $subject = sanitize_text_field((string) ($params['subject'] ?? get_bloginfo('name')));

        if ($order_no === '' || $amount <= 0) {
            return [
                'success' => false,
                'message' => __('订单参数错误', 'qilingshop'),
            ];
        }

        $method = sanitize_key((string) ($params['method'] ?? ''));
        if ($method !== 'alipay' && $method !== 'wechat') {
            $method = $this->default_type;
        }

        $query_args = [
            'order'   => $order_no,
            'price'   => number_format($amount, 2, '.', ''),
            'subject' => $subject,
            'method'  => $method,
        ];

        $return_url = function_exists('qilingshop_normalize_return_url')
            ? qilingshop_normalize_return_url((string) ($params['return_url'] ?? ''))
            : '';
        if ($return_url !== '') {
            $query_args['redirect_url'] = $return_url;
        }

        return [
            'success' => true,
            'type'    => 'redirect',
            'pay_url' => qilingshop_get_payment_entry_url('xhpay', $query_args),
        ];
    }

    /**
     * 查单（用于兜底补单）
     */
    public function query($params) {
        $order_no = sanitize_text_field((string) ($params['order_no'] ?? ''));
        if ($order_no === '') {
            return ['success' => false, 'paid' => false];
        }

        $channels = ['alipay', 'wechat'];
        if ($this->default_type === 'wechat') {
            $channels = ['wechat', 'alipay'];
        }

        foreach ($channels as $channel) {
            $channel_conf = $this->get_channel_config($channel);
            if (empty($channel_conf['appid']) || empty($channel_conf['appsecret'])) {
                continue;
            }

            $result = $this->query_channel_order($order_no, $channel_conf);
            if (!empty($result['success']) && !empty($result['paid'])) {
                return $result;
            }

            if (!empty($result['success'])) {
                return $result;
            }
        }

        return ['success' => false, 'paid' => false];
    }

    /**
     * 验证回调（保留接口）
     */
    public function verify($data) {
        if (!is_array($data) || empty($data['hash'])) {
            return false;
        }

        $method = 'alipay';
        $plugins = sanitize_key((string) ($data['plugins'] ?? ''));
        if (strpos($plugins, 'wechat') !== false || sanitize_key((string) ($data['payment'] ?? '')) === 'wechat') {
            $method = 'wechat';
        }

        $conf = $this->get_channel_config($method);
        if (empty($conf['appsecret'])) {
            return false;
        }

        return hash_equals(
            strtolower((string) $data['hash']),
            strtolower($this->generate_hash($data, $conf['appsecret']))
        );
    }

    /**
     * 获取渠道配置
     */
    private function get_channel_config($channel) {
        $channel = $channel === 'wechat' ? 'wechat' : 'alipay';

        if ($channel === 'wechat') {
            return [
                'appid'     => sanitize_text_field((string) get_option('qilingshop_xhpay_appid_wechat', '')),
                'appsecret' => sanitize_text_field((string) get_option('qilingshop_xhpay_appsecret_wechat', '')),
                'api_url'   => esc_url_raw((string) get_option('qilingshop_xhpay_api_wechat', '')),
            ];
        }

        return [
            'appid'     => sanitize_text_field((string) get_option('qilingshop_xhpay_appid_alipay', '')),
            'appsecret' => sanitize_text_field((string) get_option('qilingshop_xhpay_appsecret_alipay', '')),
            'api_url'   => esc_url_raw((string) get_option('qilingshop_xhpay_api_alipay', '')),
        ];
    }

    /**
     * 查询单个渠道的订单状态
     */
    private function query_channel_order($order_no, $channel_conf) {
        $query_url = $this->build_query_url((string) ($channel_conf['api_url'] ?? ''));
        if ($query_url === '') {
            return ['success' => false, 'paid' => false];
        }

        $request = [
            'appid'           => (string) $channel_conf['appid'],
            'out_trade_order' => $order_no,
            'time'            => time(),
            'nonce_str'       => wp_generate_password(12, false),
        ];
        $request['hash'] = $this->generate_hash($request, (string) $channel_conf['appsecret']);

        $response = wp_remote_post($query_url, [
            'timeout'   => 20,
            'sslverify' => true,
            'body'      => $request,
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'paid' => false];
        }

        $body = wp_remote_retrieve_body($response);
        if ($body === '') {
            return ['success' => false, 'paid' => false];
        }

        $result = json_decode($body, true);
        if (!is_array($result)) {
            return ['success' => false, 'paid' => false];
        }

        if (isset($result['hash'])) {
            $hash = $this->generate_hash($result, (string) $channel_conf['appsecret']);
            if (!hash_equals(strtolower((string) $result['hash']), strtolower($hash))) {
                return ['success' => false, 'paid' => false];
            }
        }

        if (isset($result['errcode']) && (int) $result['errcode'] !== 0) {
            return ['success' => false, 'paid' => false];
        }

        $status = '';
        if (isset($result['data']) && is_array($result['data'])) {
            $status = sanitize_text_field((string) ($result['data']['status'] ?? ''));
        } else {
            $status = sanitize_text_field((string) ($result['status'] ?? ''));
        }

        $paid = ($status === 'OD' || strtolower($status) === 'complete');

        $total_fee = 0.0;
        if (isset($result['data']) && is_array($result['data']) && isset($result['data']['total_fee'])) {
            $total_fee = (float) $result['data']['total_fee'];
        } elseif (isset($result['total_fee'])) {
            $total_fee = (float) $result['total_fee'];
        }

        $transaction_id = '';
        if (isset($result['data']) && is_array($result['data'])) {
            $transaction_id = sanitize_text_field((string) ($result['data']['open_order_id'] ?? $result['data']['transaction_id'] ?? ''));
        } else {
            $transaction_id = sanitize_text_field((string) ($result['open_order_id'] ?? $result['transaction_id'] ?? ''));
        }

        return [
            'success'        => true,
            'paid'           => $paid,
            'status'         => $status,
            'total_amount'   => $total_fee,
            'transaction_id' => $transaction_id,
        ];
    }

    /**
     * 根据 do.html 推导 query.html
     */
    private function build_query_url($api_url) {
        $api_url = trim((string) $api_url);
        if ($api_url === '') {
            return 'https://api.xunhupay.com/payment/query.html';
        }

        if (strpos($api_url, 'query.html') !== false) {
            return $api_url;
        }

        if (strpos($api_url, 'do.html') !== false) {
            return str_replace('do.html', 'query.html', $api_url);
        }

        return untrailingslashit($api_url) . '/payment/query.html';
    }

    /**
     * 生成 V3 hash
     */
    private function generate_hash(array $data, $secret) {
        ksort($data);
        $pairs = [];
        foreach ($data as $key => $val) {
            if ($key === 'hash' || $val === '' || $val === null) {
                continue;
            }
            if (is_array($val)) {
                continue;
            }
            $pairs[] = $key . '=' . $val;
        }

        return md5(implode('&', $pairs) . (string) $secret);
    }
}
