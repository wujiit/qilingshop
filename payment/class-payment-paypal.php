<?php
/**
 * PayPal 支付网关（Orders v2）
 * 
 * @package QilingShop
 */

if (!defined('ABSPATH')) exit;

class QilingShop_Payment_Paypal {

    /**
     * @var string
     */
    private $client_id = '';

    /**
     * @var string
     */
    private $client_secret = '';

    /**
     * @var string
     */
    private $webhook_id = '';

    /**
     * @var float
     */
    private $rate = 7.0;

    /**
     * @var bool
     */
    private $sandbox = false;

    /**
     * @var string
     */
    private $api_base = '';

    public function __construct() {
        $this->client_id = sanitize_text_field((string) get_option('qilingshop_paypal_client_id', ''));
        $this->client_secret = (string) get_option('qilingshop_paypal_client_secret', '');
        $this->webhook_id = sanitize_text_field((string) get_option('qilingshop_paypal_webhook_id', ''));

        $rate = (float) get_option('qilingshop_paypal_rate', 7);
        $this->rate = $rate > 0 ? $rate : 7.0;

        $this->sandbox = (bool) get_option('qilingshop_paypal_sandbox', false);
        $this->api_base = $this->sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    }

    /**
     * 创建支付
     */
    public function create($params) {
        if (!$this->has_valid_credentials()) {
            return [
                'success' => false,
                'message' => __('PayPal Client ID / Secret 未配置', 'qilingshop'),
            ];
        }

        $order_no = isset($params['order_no']) ? $this->sanitize_order_no($params['order_no']) : '';
        $amount = isset($params['amount']) ? (float) $params['amount'] : 0.0;
        $subject = isset($params['subject']) ? wp_strip_all_tags((string) $params['subject']) : __('订单支付', 'qilingshop');
        $subject = function_exists('mb_substr') ? mb_substr($subject, 0, 120) : substr($subject, 0, 120);

        if ($order_no === '' || $amount <= 0) {
            return [
                'success' => false,
                'message' => __('PayPal 支付参数无效', 'qilingshop'),
            ];
        }

        $payment_snapshot = isset($params['payment_amount_snapshot']) && is_array($params['payment_amount_snapshot'])
            ? $params['payment_amount_snapshot']
            : [];

        // 转换金额（人民币 -> 美元）
        $usd_amount = $this->resolve_paypal_amount($amount, $payment_snapshot);
        if ($usd_amount <= 0) {
            return [
                'success' => false,
                'message' => __('PayPal 支付金额无效', 'qilingshop'),
            ];
        }

        $frontend_return_url = function_exists('qilingshop_normalize_return_url')
            ? qilingshop_normalize_return_url((string) ($params['return_url'] ?? ''))
            : '';
        if ($frontend_return_url === '') {
            $frontend_return_url = home_url('/');
        }
        $frontend_cancel_url = function_exists('qilingshop_normalize_return_url')
            ? qilingshop_normalize_return_url((string) ($params['cancel_url'] ?? ''))
            : '';
        if ($frontend_cancel_url === '') {
            $frontend_cancel_url = $frontend_return_url;
        }

        $return_args = [
            'order_no'    => $order_no,
            'redirect_to' => $frontend_return_url,
        ];
        $cancel_args = [
            'order_no'    => $order_no,
            'status'      => 'cancel',
            'redirect_to' => $frontend_cancel_url,
        ];
        $return_url = qilingshop_get_payment_action_url('paypal_return', 'paypal', $return_args);
        $cancel_url = qilingshop_get_payment_action_url('paypal_return', 'paypal', $cancel_args);

        $access_token = $this->get_access_token();
        if ($access_token === '') {
            return [
                'success' => false,
                'message' => __('获取 PayPal Access Token 失败', 'qilingshop'),
            ];
        }

        $order_data = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'custom_id' => $order_no,
                'reference_id' => $order_no,
                'invoice_id' => $order_no,
                'description'  => $subject,
                'amount' => [
                    'currency_code' => 'USD',
                    'value' => sprintf('%.2f', $usd_amount),
                ],
            ]],
            'application_context' => [
                'return_url' => $return_url,
                'cancel_url' => $cancel_url,
                'user_action' => 'PAY_NOW',
            ],
        ];

        $request_id = 'qls-create-' . substr(md5($order_no . '|' . microtime(true)), 0, 24);
        $response = $this->api_request(
            'POST',
            '/v2/checkout/orders',
            $access_token,
            $order_data,
            [
                'PayPal-Request-Id' => $request_id,
            ]
        );

        if (!$response['success']) {
            return [
                'success' => false,
                'message' => $response['message'],
            ];
        }

        $result = $response['body'];

        if (!empty($result['id']) && !empty($result['links']) && is_array($result['links'])) {
            $approval_link = '';
            foreach ($result['links'] as $link) {
                if (!empty($link['rel']) && $link['rel'] === 'approve' && !empty($link['href'])) {
                    $approval_link = esc_url_raw((string) $link['href']);
                    break;
                }
            }

            if ($approval_link) {
                $this->set_order_mapping((string) $result['id'], $order_no);
                return [
                    'success' => true,
                    'type'    => 'redirect',
                    'pay_url' => $approval_link,
                    'order_id' => sanitize_text_field((string) $result['id']),
                ];
            }
        }

        return [
            'success' => false,
            'message' => __('PayPal 未返回可用支付链接', 'qilingshop'),
        ];
    }

    /**
     * 优先使用订单快照中的金额创建 PayPal 订单。
     *
     * @param float $amount_cny        人民币金额
     * @param array $payment_snapshot  支付金额快照
     * @return float
     */
    private function resolve_paypal_amount($amount_cny, $payment_snapshot = []) {
        if (is_array($payment_snapshot)) {
            $snapshot_currency = strtoupper((string) ($payment_snapshot['settlement_currency'] ?? ''));
            $snapshot_amount = isset($payment_snapshot['expected_paid_amount'])
                ? round((float) $payment_snapshot['expected_paid_amount'], 2)
                : 0.0;

            if ($snapshot_currency === 'USD' && $snapshot_amount > 0) {
                return $snapshot_amount;
            }

            $snapshot_rate = isset($payment_snapshot['rate']) ? (float) $payment_snapshot['rate'] : 0.0;
            if ($snapshot_rate > 0) {
                return round((float) $amount_cny / $snapshot_rate, 2);
            }
        }

        return round((float) $amount_cny / $this->rate, 2);
    }

    /**
     * 捕获已审批的 PayPal 订单
     */
    public function capture_order($paypal_order_id, $fallback_order_no = '') {
        $paypal_order_id = sanitize_text_field((string) $paypal_order_id);
        $fallback_order_no = $this->sanitize_order_no($fallback_order_no);

        if ($paypal_order_id === '') {
            return [
                'success' => false,
                'message' => __('PayPal 订单号为空', 'qilingshop'),
            ];
        }

        $access_token = $this->get_access_token();
        if ($access_token === '') {
            return [
                'success' => false,
                'message' => __('获取 PayPal Access Token 失败', 'qilingshop'),
            ];
        }

        $request_id = 'qls-capture-' . substr(md5($paypal_order_id . '|' . microtime(true)), 0, 24);
        $capture_response = $this->api_request(
            'POST',
            '/v2/checkout/orders/' . rawurlencode($paypal_order_id) . '/capture',
            $access_token,
            new stdClass(),
            [
                'PayPal-Request-Id' => $request_id,
            ]
        );

        if ($capture_response['success']) {
            $parsed = $this->extract_capture_from_order_response($capture_response['body'], $fallback_order_no);
            if (!empty($parsed['success'])) {
                return $parsed;
            }
        }

        // 已捕获或重复请求时，尝试查询订单详情兜底解析。
        $order_response = $this->api_request(
            'GET',
            '/v2/checkout/orders/' . rawurlencode($paypal_order_id),
            $access_token
        );
        if ($order_response['success']) {
            $parsed = $this->extract_capture_from_order_response($order_response['body'], $fallback_order_no);
            if (!empty($parsed['success'])) {
                return $parsed;
            }
        }

        return [
            'success' => false,
            'message' => $capture_response['message'],
        ];
    }

    /**
     * 验证 PayPal Webhook 签名
     */
    public function verify_webhook_signature($payload, $headers = []) {
        if (!$this->has_valid_credentials() || $this->webhook_id === '') {
            return false;
        }

        $event = json_decode((string) $payload, true);
        if (!is_array($event)) {
            return false;
        }

        $transmission_id = $this->get_header_value($headers, 'paypal-transmission-id');
        $transmission_time = $this->get_header_value($headers, 'paypal-transmission-time');
        $transmission_sig = $this->get_header_value($headers, 'paypal-transmission-sig');
        $cert_url = $this->get_header_value($headers, 'paypal-cert-url');
        $auth_algo = $this->get_header_value($headers, 'paypal-auth-algo');

        if ($transmission_id === '' || $transmission_time === '' || $transmission_sig === '' || $cert_url === '' || $auth_algo === '') {
            return false;
        }

        $access_token = $this->get_access_token();
        if ($access_token === '') {
            return false;
        }

        $verify_data = [
            'transmission_id' => $transmission_id,
            'transmission_time' => $transmission_time,
            'cert_url' => $cert_url,
            'auth_algo' => $auth_algo,
            'transmission_sig' => $transmission_sig,
            'webhook_id' => $this->webhook_id,
            'webhook_event' => $event,
        ];

        $response = $this->api_request(
            'POST',
            '/v1/notifications/verify-webhook-signature',
            $access_token,
            $verify_data
        );

        if (!$response['success']) {
            return false;
        }

        $status = strtoupper((string) ($response['body']['verification_status'] ?? ''));
        return $status === 'SUCCESS';
    }

    /**
     * 解析 CHECKOUT.ORDER.APPROVED 事件
     */
    public function extract_order_approved_data($event) {
        if (!is_array($event)) {
            return ['success' => false];
        }
        $resource = isset($event['resource']) && is_array($event['resource']) ? $event['resource'] : [];
        $paypal_order_id = sanitize_text_field((string) ($resource['id'] ?? ''));
        if ($paypal_order_id === '') {
            return ['success' => false];
        }

        $order_no = $this->extract_order_no_from_purchase_units($resource, '');
        if ($order_no === '') {
            $order_no = $this->get_order_mapping($paypal_order_id);
        }

        return [
            'success' => true,
            'paypal_order_id' => $paypal_order_id,
            'order_no' => $order_no,
        ];
    }

    /**
     * 解析 PAYMENT.CAPTURE.COMPLETED 事件
     */
    public function extract_capture_from_event($event) {
        if (!is_array($event)) {
            return ['success' => false];
        }

        $resource = isset($event['resource']) && is_array($event['resource']) ? $event['resource'] : [];
        $transaction_id = sanitize_text_field((string) ($resource['id'] ?? ''));
        $status = strtoupper((string) ($resource['status'] ?? ''));
        $amount = isset($resource['amount']['value']) ? (float) $resource['amount']['value'] : 0.0;
        $currency = strtoupper(sanitize_text_field((string) ($resource['amount']['currency_code'] ?? 'USD')));
        $paypal_order_id = sanitize_text_field((string) ($resource['supplementary_data']['related_ids']['order_id'] ?? ''));

        $order_no = $this->sanitize_order_no((string) ($resource['custom_id'] ?? ''));
        if ($order_no === '') {
            $order_no = $this->sanitize_order_no((string) ($resource['invoice_id'] ?? ''));
        }
        if ($order_no === '' && $paypal_order_id !== '') {
            $order_no = $this->get_order_mapping($paypal_order_id);
        }

        if ($transaction_id === '' || $order_no === '' || $amount <= 0 || ($status !== '' && $status !== 'COMPLETED')) {
            return ['success' => false];
        }

        return [
            'success' => true,
            'order_no' => $order_no,
            'transaction_id' => $transaction_id,
            'amount' => $amount,
            'currency' => $currency,
            'paypal_order_id' => $paypal_order_id,
            'status' => $status,
        ];
    }

    /**
     * 获取 Webhook ID（用于后台校验提示）
     */
    public function get_webhook_id() {
        return $this->webhook_id;
    }

    /**
     * 是否有有效 API 凭证
     */
    public function has_valid_credentials() {
        return $this->client_id !== '' && $this->client_secret !== '';
    }

    /**
     * 提取捕获结果
     */
    private function extract_capture_from_order_response($data, $fallback_order_no = '') {
        if (!is_array($data)) {
            return ['success' => false];
        }

        $paypal_order_id = sanitize_text_field((string) ($data['id'] ?? ''));
        $order_no = $this->extract_order_no_from_purchase_units($data, $fallback_order_no);
        if ($order_no === '' && $paypal_order_id !== '') {
            $order_no = $this->get_order_mapping($paypal_order_id);
        }
        if ($order_no === '') {
            $order_no = $this->sanitize_order_no($fallback_order_no);
        }

        $captures = $this->extract_captures_from_purchase_units($data);
        $selected_capture = [];

        foreach ($captures as $capture) {
            $status = strtoupper((string) ($capture['status'] ?? ''));
            if ($status === 'COMPLETED') {
                $selected_capture = $capture;
                break;
            }
        }

        if (empty($selected_capture) && !empty($captures)) {
            $selected_capture = (array) $captures[0];
        }

        if (empty($selected_capture)) {
            return [
                'success' => false,
                'message' => __('未找到 PayPal 捕获记录', 'qilingshop'),
            ];
        }

        $transaction_id = sanitize_text_field((string) ($selected_capture['id'] ?? ''));
        $status = strtoupper((string) ($selected_capture['status'] ?? ''));
        $amount = isset($selected_capture['amount']['value']) ? (float) $selected_capture['amount']['value'] : 0.0;
        $currency = strtoupper(sanitize_text_field((string) ($selected_capture['amount']['currency_code'] ?? 'USD')));

        if ($transaction_id === '' || $order_no === '' || $amount <= 0 || ($status !== '' && $status !== 'COMPLETED')) {
            return [
                'success' => false,
                'message' => __('PayPal 捕获数据不完整', 'qilingshop'),
            ];
        }

        return [
            'success' => true,
            'order_no' => $order_no,
            'transaction_id' => $transaction_id,
            'amount' => $amount,
            'currency' => $currency,
            'paypal_order_id' => $paypal_order_id,
            'status' => $status,
        ];
    }

    /**
     * 提取 purchase_units 下全部 captures
     */
    private function extract_captures_from_purchase_units($data) {
        $captures = [];
        $purchase_units = isset($data['purchase_units']) && is_array($data['purchase_units']) ? $data['purchase_units'] : [];

        foreach ($purchase_units as $unit) {
            if (!is_array($unit) || empty($unit['payments']['captures']) || !is_array($unit['payments']['captures'])) {
                continue;
            }
            foreach ($unit['payments']['captures'] as $capture) {
                if (is_array($capture)) {
                    $captures[] = $capture;
                }
            }
        }

        return $captures;
    }

    /**
     * 提取订单号
     */
    private function extract_order_no_from_purchase_units($data, $fallback_order_no = '') {
        $purchase_units = isset($data['purchase_units']) && is_array($data['purchase_units']) ? $data['purchase_units'] : [];
        foreach ($purchase_units as $unit) {
            if (!is_array($unit)) {
                continue;
            }
            $order_no = $this->sanitize_order_no((string) ($unit['custom_id'] ?? ''));
            if ($order_no === '') {
                $order_no = $this->sanitize_order_no((string) ($unit['reference_id'] ?? ''));
            }
            if ($order_no === '') {
                $order_no = $this->sanitize_order_no((string) ($unit['invoice_id'] ?? ''));
            }
            if ($order_no !== '') {
                return $order_no;
            }
        }
        return $this->sanitize_order_no($fallback_order_no);
    }

    /**
     * 获取 Access Token
     */
    private function get_access_token() {
        if (!$this->has_valid_credentials()) {
            return '';
        }

        $response = wp_remote_post(
            $this->api_base . '/v1/oauth2/token',
            [
                'timeout' => 20,
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($this->client_id . ':' . $this->client_secret),
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept' => 'application/json',
                ],
                'body' => [
                    'grant_type' => 'client_credentials',
                ],
            ]
        );

        if (is_wp_error($response)) {
            return '';
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($body) || empty($body['access_token'])) {
            return '';
        }

        return sanitize_text_field((string) $body['access_token']);
    }

    /**
     * 调用 PayPal API
     */
    private function api_request($method, $path, $access_token = '', $body = null, $extra_headers = []) {
        $headers = [
            'Accept' => 'application/json',
        ];

        if ($access_token !== '') {
            $headers['Authorization'] = 'Bearer ' . $access_token;
        }
        foreach ($extra_headers as $k => $v) {
            $headers[(string) $k] = (string) $v;
        }

        $args = [
            'method' => strtoupper((string) $method),
            'timeout' => 20,
            'headers' => $headers,
        ];

        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $args['headers'] = $headers;
            $args['body'] = is_string($body) ? $body : wp_json_encode($body);
        }

        $response = wp_remote_request($this->api_base . $path, $args);
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'code' => 0,
                'body' => [],
                'message' => $response->get_error_message(),
            ];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        if ($code < 200 || $code >= 300) {
            $message = __('PayPal 接口请求失败', 'qilingshop');
            if (!empty($decoded['message'])) {
                $message = sanitize_text_field((string) $decoded['message']);
            } elseif (!empty($decoded['error_description'])) {
                $message = sanitize_text_field((string) $decoded['error_description']);
            } elseif (!empty($decoded['name'])) {
                $message = sanitize_text_field((string) $decoded['name']);
            }

            return [
                'success' => false,
                'code' => $code,
                'body' => $decoded,
                'message' => $message,
            ];
        }

        return [
            'success' => true,
            'code' => $code,
            'body' => $decoded,
            'message' => '',
        ];
    }

    /**
     * 读取请求头（兼容 WP_REST_Request::get_headers() 的数组结构）
     */
    private function get_header_value($headers, $name) {
        $target = strtolower(str_replace('_', '-', (string) $name));
        if (!is_array($headers)) {
            return '';
        }
        foreach ($headers as $key => $value) {
            $normalized = strtolower(str_replace('_', '-', (string) $key));
            if ($normalized !== $target) {
                continue;
            }
            if (is_array($value)) {
                $first = reset($value);
                return is_string($first) ? trim($first) : '';
            }
            return is_string($value) ? trim($value) : '';
        }
        return '';
    }

    /**
     * 订单号白名单过滤
     */
    private function sanitize_order_no($value) {
        $value = sanitize_text_field((string) $value);
        return preg_replace('/[^A-Za-z0-9_-]/', '', $value);
    }

    /**
     * 保存 PayPal 订单映射（用于 Webhook 兜底还原业务订单号）
     */
    private function set_order_mapping($paypal_order_id, $order_no) {
        $paypal_order_id = sanitize_text_field((string) $paypal_order_id);
        $order_no = $this->sanitize_order_no($order_no);
        if ($paypal_order_id === '' || $order_no === '') {
            return;
        }
        set_transient('qilingshop_paypal_map_' . md5($paypal_order_id), $order_no, 7 * DAY_IN_SECONDS);
    }

    /**
     * 读取 PayPal 订单映射
     */
    private function get_order_mapping($paypal_order_id) {
        $paypal_order_id = sanitize_text_field((string) $paypal_order_id);
        if ($paypal_order_id === '') {
            return '';
        }
        $value = get_transient('qilingshop_paypal_map_' . md5($paypal_order_id));
        return $this->sanitize_order_no((string) $value);
    }
}
