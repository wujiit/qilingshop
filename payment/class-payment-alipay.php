<?php
/**
 * 支付宝支付网关 (仅RSA2签名)
 * 
 * 支持: 当面付(扫码支付)、电脑网站支付、H5支付
 * 
 * @package QilingShop
 */

if (!defined('ABSPATH')) exit;

class QilingShop_Payment_Alipay {

    private $app_id;
    private $private_key;
    private $public_key;
    private $notify_url;
    private $return_url;
    private $gateway = 'https://openapi.alipay.com/gateway.do';

    public function __construct() {
        $this->app_id      = get_option('qilingshop_alipay_app_id');
        $this->private_key = get_option('qilingshop_alipay_private_key');
        $this->public_key  = get_option('qilingshop_alipay_public_key');
        $this->notify_url  = function_exists('qilingshop_get_payment_notify_url')
            ? qilingshop_get_payment_notify_url('alipay')
            : home_url('?qilingshop_payment=notify&gateway=alipay');
        $this->return_url  = $this->normalize_return_url(get_option('qilingshop_payment_return_url'));
        if ($this->return_url === '') {
            $this->return_url = home_url();
        }
    }

    /**
     * 创建支付
     */
    public function create($params) {
        $order_no = $params['order_no'];
        $amount = $params['amount'];
        $subject = $params['subject'];
        $return_url = '';
        if (!empty($params['return_url'])) {
            $return_url = $this->normalize_return_url($params['return_url']);
        }
        if ($return_url === '') {
            $return_url = $this->return_url;
        }

        // 判断支付场景
        $method = $params['method'] ?? '';
        
        if ($method === 'f2f') {
            return $this->create_f2fpay($order_no, $amount, $subject, $return_url);
        } elseif ($method === 'page') {
            return $this->create_page_pay($order_no, $amount, $subject, $return_url);
        } elseif ($method === 'wap') {
            return $this->create_h5_pay($order_no, $amount, $subject, $return_url);
        }
        
        // 自动判断 (兼容旧逻辑)
        $f2fpay = get_option('qilingshop_alipay_f2fpay', true);
        $h5 = get_option('qilingshop_alipay_h5', false);

        if ($f2fpay && !wp_is_mobile()) {
            return $this->create_f2fpay($order_no, $amount, $subject, $return_url);
        } elseif ($h5 && wp_is_mobile()) {
            return $this->create_h5_pay($order_no, $amount, $subject, $return_url);
        } else {
            return $this->create_page_pay($order_no, $amount, $subject, $return_url);
        }
    }

    /**
     * 当面付（扫码支付）
     */
    private function create_f2fpay($order_no, $amount, $subject, $return_url = '') {
        $biz_content = [
            'out_trade_no' => $order_no,
            'total_amount' => sprintf('%.2f', $amount),
            'subject'      => $subject,
        ];

        $params = [
            'app_id'      => $this->app_id,
            'method'      => 'alipay.trade.precreate',
            'format'      => 'JSON',
            'charset'     => 'utf-8',
            'sign_type'   => 'RSA2',
            'timestamp'   => date('Y-m-d H:i:s'),
            'version'     => '1.0',
            'notify_url'  => $this->notify_url,
            'biz_content' => json_encode($biz_content, JSON_UNESCAPED_SLASHES),
        ];

        $params['sign'] = $this->generate_sign($params);

        $response = $this->http_post($this->gateway, http_build_query($params));
        $result = json_decode($response, true);

        if (isset($result['alipay_trade_precreate_response'])) {
            $res = $result['alipay_trade_precreate_response'];
            if ($res['code'] == '10000') {
                $query_args = [
                    'order'   => $order_no,
                    'price'   => $amount,
                    'subject' => $subject,
                ];
                if ($return_url !== '') {
                    $query_args['redirect_url'] = $return_url;
                }
                return [
                    'success'  => true,
                    'type'     => 'qrcode',
                    'qrcode'   => $res['qr_code'],
                    'order_no' => $order_no,
                    'pay_url'  => qilingshop_get_payment_entry_url('alipay', $query_args),
                ];
            }
            return ['success' => false, 'message' => $res['sub_msg'] ?? $res['msg'] ?? __('创建订单失败', 'qilingshop')];
        }

        return ['success' => false, 'message' => __('支付宝接口调用失败', 'qilingshop')];
    }

    /**
     * 电脑网站支付
     */
    private function create_page_pay($order_no, $amount, $subject, $return_url = '') {
        $return_url = $this->normalize_return_url($return_url);
        if ($return_url === '') {
            $return_url = $this->return_url;
        }

        $biz_content = [
            'out_trade_no' => $order_no,
            'total_amount' => sprintf('%.2f', $amount),
            'subject'      => $subject,
            'product_code' => 'FAST_INSTANT_TRADE_PAY',
        ];

        $params = [
            'app_id'      => $this->app_id,
            'method'      => 'alipay.trade.page.pay',
            'format'      => 'JSON',
            'charset'     => 'utf-8',
            'sign_type'   => 'RSA2',
            'timestamp'   => date('Y-m-d H:i:s'),
            'version'     => '1.0',
            'notify_url'  => $this->notify_url,
            'return_url'  => $return_url,
            'biz_content' => json_encode($biz_content, JSON_UNESCAPED_SLASHES),
        ];

        $params['sign'] = $this->generate_sign($params);

        $pay_url = $this->gateway . '?' . http_build_query($params);

        return [
            'success' => true,
            'type'    => 'redirect',
            'pay_url' => $pay_url,
        ];
    }

    /**
     * H5支付（手机网站支付）
     */
    private function create_h5_pay($order_no, $amount, $subject, $return_url = '') {
        $return_url = $this->normalize_return_url($return_url);
        if ($return_url === '') {
            $return_url = $this->return_url;
        }

        $biz_content = [
            'out_trade_no' => $order_no,
            'total_amount' => sprintf('%.2f', $amount),
            'subject'      => $subject,
            'product_code' => 'QUICK_WAP_WAY',
        ];

        $params = [
            'app_id'      => $this->app_id,
            'method'      => 'alipay.trade.wap.pay',
            'format'      => 'JSON',
            'charset'     => 'utf-8',
            'sign_type'   => 'RSA2',
            'timestamp'   => date('Y-m-d H:i:s'),
            'version'     => '1.0',
            'notify_url'  => $this->notify_url,
            'return_url'  => $return_url,
            'biz_content' => json_encode($biz_content, JSON_UNESCAPED_SLASHES),
        ];

        $params['sign'] = $this->generate_sign($params);

        $pay_url = $this->gateway . '?' . http_build_query($params);

        return [
            'success' => true,
            'type'    => 'redirect',
            'pay_url' => $pay_url,
        ];
    }

    /**
     * 规范化回跳地址，仅允许站内相对路径或同域绝对地址。
     *
     * @param string $raw_url
     * @return string
     */
    private function normalize_return_url($raw_url) {
        $raw_url = trim((string) $raw_url);
        if ($raw_url === '') {
            return '';
        }

        if (function_exists('qilingshop_normalize_return_url')) {
            return qilingshop_normalize_return_url($raw_url);
        }

        return '';
    }

    /**
     * 查询订单
     */
    public function query($params) {
        $order_no = $params['order_no'];

        $biz_content = [
            'out_trade_no' => $order_no,
        ];

        $query_params = [
            'app_id'      => $this->app_id,
            'method'      => 'alipay.trade.query',
            'format'      => 'JSON',
            'charset'     => 'utf-8',
            'sign_type'   => 'RSA2',
            'timestamp'   => date('Y-m-d H:i:s'),
            'version'     => '1.0',
            'biz_content' => json_encode($biz_content, JSON_UNESCAPED_SLASHES),
        ];

        $query_params['sign'] = $this->generate_sign($query_params);

        $response = $this->http_post($this->gateway, http_build_query($query_params));
        $result = json_decode($response, true);

        if (isset($result['alipay_trade_query_response'])) {
            $res = $result['alipay_trade_query_response'];
            if ($res['code'] == '10000') {
                return [
                    'success'        => true,
                    'status'         => $res['trade_status'],
                    'paid'           => in_array($res['trade_status'], ['TRADE_SUCCESS', 'TRADE_FINISHED']),
                    'transaction_id' => sanitize_text_field((string) ($res['trade_no'] ?? '')),
                    'total_amount'   => isset($res['total_amount']) ? (float) $res['total_amount'] : 0,
                ];
            }
        }

        return ['success' => false, 'paid' => false];
    }

    /**
     * 原路退款。
     *
     * @param array $params 退款参数
     * @return array
     */
    public function refund($params) {
        $order_no = sanitize_text_field((string) ($params['order_no'] ?? ''));
        $payment_no = sanitize_text_field((string) ($params['payment_no'] ?? ''));
        $refund_no = sanitize_text_field((string) ($params['refund_no'] ?? $order_no));
        $amount = round((float) ($params['amount'] ?? 0), 2);

        if ($this->app_id === '' || $this->private_key === '' || $this->public_key === '') {
            return ['success' => false, 'message' => __('支付宝退款配置不完整', 'qilingshop')];
        }

        if ($refund_no === '') {
            return ['success' => false, 'message' => __('缺少退款单号', 'qilingshop')];
        }

        if ($payment_no === '' && $order_no === '') {
            return ['success' => false, 'message' => __('缺少支付宝交易号或商户订单号', 'qilingshop')];
        }

        if ($amount <= 0) {
            return [
                'success'           => true,
                'status'            => 'success',
                'gateway_refund_no' => $refund_no,
                'refunded_amount'   => 0,
                'message'           => __('订单无需原路退回现金金额', 'qilingshop'),
                'gateway_refunded_at' => current_time('mysql'),
            ];
        }

        $biz_content = [
            'refund_amount' => sprintf('%.2f', $amount),
            'out_request_no'=> $refund_no,
        ];
        if ($payment_no !== '') {
            $biz_content['trade_no'] = $payment_no;
        }
        if ($order_no !== '') {
            $biz_content['out_trade_no'] = $order_no;
        }
        if (!empty($params['refund_reason'])) {
            $biz_content['refund_reason'] = sanitize_text_field((string) $params['refund_reason']);
        }

        $request_params = [
            'app_id'      => $this->app_id,
            'method'      => 'alipay.trade.refund',
            'format'      => 'JSON',
            'charset'     => 'utf-8',
            'sign_type'   => 'RSA2',
            'timestamp'   => date('Y-m-d H:i:s'),
            'version'     => '1.0',
            'biz_content' => wp_json_encode($biz_content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];

        $request_params['sign'] = $this->generate_sign($request_params);
        if ($request_params['sign'] === '') {
            return ['success' => false, 'message' => __('支付宝退款签名失败，请检查 RSA2 私钥格式', 'qilingshop')];
        }

        $response = $this->http_post($this->gateway, http_build_query($request_params));
        $result = json_decode((string) $response, true);

        if (!is_array($result) || !isset($result['alipay_trade_refund_response'])) {
            return [
                'success' => false,
                'status'  => 'failed',
                'message' => __('支付宝退款接口调用失败', 'qilingshop'),
                'raw'     => $response,
            ];
        }

        $res = $result['alipay_trade_refund_response'];
        if (($res['code'] ?? '') !== '10000') {
            return [
                'success' => false,
                'status'  => 'failed',
                'message' => $res['sub_msg'] ?? $res['msg'] ?? __('支付宝退款失败', 'qilingshop'),
                'raw'     => $res,
            ];
        }

        return [
            'success'             => true,
            'status'              => 'success',
            'gateway_refund_no'   => $refund_no,
            'refunded_amount'     => isset($res['refund_fee']) ? (float) $res['refund_fee'] : $amount,
            'message'             => __('支付宝原路退款成功', 'qilingshop'),
            'gateway_refunded_at' => current_time('mysql'),
            'raw'                 => $res,
        ];
    }

    /**
     * 验证回调
     */
    public function verify($data) {
        if (!isset($data['sign'])) {
            return false;
        }

        $sign = $data['sign'];
        unset($data['sign'], $data['sign_type']);

        ksort($data);
        $str = '';
        foreach ($data as $k => $v) {
            if ($v !== '' && !is_array($v)) {
                $str .= "$k=$v&";
            }
        }
        $str = rtrim($str, '&');

        return $this->verify_sign($str, $sign);
    }

    /**
     * RSA2签名
     */
    private function generate_sign($params) {
        ksort($params);
        $str = '';
        foreach ($params as $k => $v) {
            if ($v !== '' && !is_array($v) && $k !== 'sign') {
                $str .= "$k=$v&";
            }
        }
        $str = rtrim($str, '&');

        $private_key = "-----BEGIN RSA PRIVATE KEY-----\n" . 
                      wordwrap($this->private_key, 64, "\n", true) . 
                      "\n-----END RSA PRIVATE KEY-----";
        
        $res = openssl_pkey_get_private($private_key);
        if (!$res) {
            return '';
        }
        
        openssl_sign($str, $sign, $res, OPENSSL_ALGO_SHA256);
        return base64_encode($sign);
    }

    /**
     * 验证RSA2签名
     */
    private function verify_sign($str, $sign) {
        $public_key = function_exists('qilingshop_format_public_key_pem')
            ? qilingshop_format_public_key_pem($this->public_key)
            : '';
        if ($public_key === '') {
            $public_key = "-----BEGIN PUBLIC KEY-----\n"
                . wordwrap((string) $this->public_key, 64, "\n", true)
                . "\n-----END PUBLIC KEY-----";
        }

        $res = openssl_pkey_get_public($public_key);
        if (!$res) {
            return false;
        }
        
        return openssl_verify($str, base64_decode($sign), $res, OPENSSL_ALGO_SHA256) === 1;
    }

    private function http_post($url, $data) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $ssl_verify = apply_filters('qilingshop_alipay_ssl_verify', true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $ssl_verify ? true : false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $ssl_verify ? 2 : 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }
}
