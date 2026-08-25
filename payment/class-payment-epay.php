<?php
/**
 * 易支付网关
 *
 * @package QilingShop
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Payment_Epay {

    private $default_type = 'alipay';

    public function __construct() {
        $default_type = sanitize_key((string) get_option('qilingshop_epay_default_type', 'alipay'));
        if (in_array($default_type, ['alipay', 'wxpay', 'qqpay'], true)) {
            $this->default_type = $default_type;
        }
    }

    /**
     * 创建支付（统一转到 epay.php 入口）
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
        if (!in_array($method, ['alipay', 'wxpay', 'qqpay'], true)) {
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
            'pay_url' => qilingshop_get_payment_entry_url('epay', $query_args),
        ];
    }

    /**
     * 查单（多数易支付实现不统一，默认不支持）
     */
    public function query($params) {
        return [
            'success' => false,
            'paid'    => false,
            'message' => __('当前易支付通道未实现查单', 'qilingshop'),
        ];
    }
}
