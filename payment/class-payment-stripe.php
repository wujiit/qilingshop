<?php
/**
 * Stripe 支付网关（Checkout Session）
 *
 * @package QilingShop
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Payment_Stripe {

    /**
     * Stripe 密钥
     *
     * @var string
     */
    private $secret_key = '';

    /**
     * 结算币种
     *
     * @var string
     */
    private $currency = 'usd';

    /**
     * 人民币换汇汇率（CNY -> 外币）
     *
     * @var float
     */
    private $rate = 7.0;

    public function __construct() {
        $this->secret_key = (string) get_option('qilingshop_stripe_secret_key', '');
        $currency = sanitize_text_field((string) get_option('qilingshop_stripe_currency', 'usd'));
        $currency = strtolower($currency !== '' ? $currency : 'usd');
        $this->currency = preg_match('/^[a-z]{3}$/', $currency) ? $currency : 'usd';

        $rate = (float) get_option('qilingshop_stripe_rate', 7);
        $this->rate = $rate > 0 ? $rate : 7.0;
    }

    /**
     * 创建支付会话
     *
     * @param array $params 参数
     * @return array
     */
    public function create($params) {
        if (empty($this->secret_key)) {
            return [
                'success' => false,
                'message' => __('Stripe Secret Key 未配置', 'qilingshop'),
            ];
        }

        $order_no = isset($params['order_no']) ? sanitize_text_field((string) $params['order_no']) : '';
        $order_type = isset($params['order_type']) ? sanitize_key((string) $params['order_type']) : 'order';
        $amount_cny = isset($params['amount']) ? (float) $params['amount'] : 0.0;
        $subject = isset($params['subject']) ? wp_strip_all_tags((string) $params['subject']) : __('订单支付', 'qilingshop');
        $return_url = function_exists('qilingshop_normalize_return_url')
            ? qilingshop_normalize_return_url((string) ($params['return_url'] ?? ''))
            : '';
        if ($return_url === '') {
            $return_url = home_url('/');
        }

        if ($order_no === '' || $amount_cny <= 0) {
            return [
                'success' => false,
                'message' => __('Stripe 支付参数无效', 'qilingshop'),
            ];
        }

        $payment_snapshot = isset($params['payment_amount_snapshot']) && is_array($params['payment_amount_snapshot'])
            ? $params['payment_amount_snapshot']
            : [];
        $currency = $this->resolve_payment_currency($payment_snapshot);
        $amount_gateway = $this->resolve_gateway_amount($amount_cny, $currency, $payment_snapshot);
        $unit_amount = $this->to_minor_unit($amount_gateway, $currency);
        if ($unit_amount <= 0) {
            return [
                'success' => false,
                'message' => __('Stripe 支付金额无效', 'qilingshop'),
            ];
        }

        // Stripe 成功页支持带 session_id 占位符
        $success_url = add_query_arg(
            [
                'status' => 'success',
                'order_no' => $order_no,
                'session_id' => '{CHECKOUT_SESSION_ID}',
            ],
            $return_url
        );
        $cancel_url = add_query_arg(
            [
                'status' => 'cancel',
                'order_no' => $order_no,
            ],
            $return_url
        );

        $product_name = function_exists('mb_substr') ? mb_substr($subject, 0, 120) : substr($subject, 0, 120);

        $body = [
            'payment_method_types[0]' => 'card',
            'mode' => 'payment',
            'success_url' => $success_url,
            'cancel_url' => $cancel_url,
            'client_reference_id' => $order_no,
            'metadata[order_no]' => $order_no,
            'metadata[order_type]' => $order_type,
            'metadata[source]' => 'qilingshop',
            'line_items[0][quantity]' => 1,
            'line_items[0][price_data][currency]' => $currency,
            'line_items[0][price_data][unit_amount]' => $unit_amount,
            'line_items[0][price_data][product_data][name]' => $product_name,
            'payment_intent_data[metadata][order_no]' => $order_no,
            'payment_intent_data[metadata][order_type]' => $order_type,
            'payment_intent_data[metadata][source]' => 'qilingshop',
        ];

        $response = wp_remote_post(
            'https://api.stripe.com/v1/checkout/sessions',
            [
                'timeout' => 20,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->secret_key,
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                ],
                'body' => $body,
            ]
        );

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $raw_body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw_body, true);

        if ($http_code < 200 || $http_code >= 300 || !is_array($decoded)) {
            return [
                'success' => false,
                'message' => __('Stripe 接口请求失败', 'qilingshop'),
            ];
        }

        if (!empty($decoded['error']['message'])) {
            return [
                'success' => false,
                'message' => (string) $decoded['error']['message'],
            ];
        }

        if (empty($decoded['url'])) {
            return [
                'success' => false,
                'message' => __('Stripe 未返回可跳转地址', 'qilingshop'),
            ];
        }

        return [
            'success' => true,
            'type'    => 'redirect',
            'pay_url' => esc_url_raw((string) $decoded['url']),
            'session_id' => !empty($decoded['id']) ? sanitize_text_field((string) $decoded['id']) : '',
        ];
    }

    /**
     * 优先使用订单快照中的结算币种。
     *
     * @param array $payment_snapshot 支付金额快照
     * @return string
     */
    private function resolve_payment_currency($payment_snapshot = []) {
        if (is_array($payment_snapshot)) {
            $snapshot_currency = strtolower((string) ($payment_snapshot['settlement_currency'] ?? ''));
            if (preg_match('/^[a-z]{3}$/', $snapshot_currency)) {
                return $snapshot_currency;
            }
        }

        return $this->currency;
    }

    /**
     * 优先使用订单快照中的金额，保证创建支付和回调校验一致。
     *
     * @param float  $amount_cny       人民币金额
     * @param string $currency         目标币种
     * @param array  $payment_snapshot 支付金额快照
     * @return float
     */
    private function resolve_gateway_amount($amount_cny, $currency, $payment_snapshot = []) {
        if (is_array($payment_snapshot)) {
            $snapshot_currency = strtolower((string) ($payment_snapshot['settlement_currency'] ?? ''));
            $snapshot_amount = isset($payment_snapshot['expected_paid_amount'])
                ? round((float) $payment_snapshot['expected_paid_amount'], 2)
                : 0.0;

            if ($snapshot_currency === $currency && $snapshot_amount > 0) {
                return $snapshot_amount;
            }

            $snapshot_rate = isset($payment_snapshot['rate']) ? (float) $payment_snapshot['rate'] : 0.0;
            if ($currency !== 'cny' && $snapshot_rate > 0) {
                return round((float) $amount_cny / $snapshot_rate, 2);
            }
        }

        return $this->convert_amount_from_cny($amount_cny, $currency);
    }

    /**
     * CNY 转目标币种金额
     *
     * @param float  $amount_cny 人民币金额
     * @param string $currency   目标币种
     * @return float
     */
    private function convert_amount_from_cny($amount_cny, $currency) {
        $currency = strtolower((string) $currency);
        if ($currency === 'cny') {
            return round((float) $amount_cny, 2);
        }

        return round((float) $amount_cny / $this->rate, 2);
    }

    /**
     * 金额转最小货币单位
     *
     * @param float  $amount   金额
     * @param string $currency 币种
     * @return int
     */
    private function to_minor_unit($amount, $currency) {
        $currency = strtolower((string) $currency);
        $zero_decimal = [
            'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga',
            'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf',
        ];

        if (in_array($currency, $zero_decimal, true)) {
            return (int) round((float) $amount);
        }

        return (int) round((float) $amount * 100);
    }
}
