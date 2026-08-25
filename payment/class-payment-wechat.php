<?php
/**
 * 微信支付网关
 * 
 * @package QilingShop
 */

if (!defined('ABSPATH')) exit;

class QilingShop_Payment_Wechat {

    private $appid;
    private $mchid;
    private $key;
    private $client_cert;
    private $client_key;
    private $notify_url;

    public function __construct() {
        $this->appid  = get_option('qilingshop_wechat_appid');
        $this->mchid  = get_option('qilingshop_wechat_mchid');
        $this->key    = get_option('qilingshop_wechat_key');
        $this->client_cert = (string) get_option('qilingshop_wechat_client_cert', '');
        $this->client_key  = (string) get_option('qilingshop_wechat_client_key', '');
        $this->notify_url = function_exists('qilingshop_get_payment_notify_url')
            ? qilingshop_get_payment_notify_url('wechat')
            : home_url('?qilingshop_payment=notify&gateway=wechat');
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
            $raw_return_url = (string) $params['return_url'];
            if (function_exists('qilingshop_normalize_return_url')) {
                $return_url = qilingshop_normalize_return_url($raw_return_url);
            } elseif (function_exists('qilingshop_validate_return_url')) {
                $validated = qilingshop_validate_return_url($raw_return_url);
                $return_url = (strpos($validated, '/') === 0 && strpos($validated, '//') !== 0)
                    ? home_url($validated)
                    : esc_url_raw($validated);
            }
        }

        // 判断支付场景
        if ($this->is_wechat_browser()) {
            return $this->create_jsapi_pay($order_no, $amount, $subject, $return_url);
        } elseif (wp_is_mobile()) {
            return $this->create_h5_pay($order_no, $amount, $subject);
        } else {
            return $this->create_native_pay($order_no, $amount, $subject, $return_url);
        }
    }

    /**
     * 微信网页/公众号支付 v2 原路退款。
     *
     * @param array $params 退款参数
     * @return array
     */
    public function refund($params) {
        $order_no = sanitize_text_field((string) ($params['order_no'] ?? ''));
        $payment_no = sanitize_text_field((string) ($params['payment_no'] ?? ''));
        $refund_no = sanitize_text_field((string) ($params['refund_no'] ?? $order_no));
        $amount = round((float) ($params['amount'] ?? 0), 2);

        if ($this->appid === '' || $this->mchid === '' || $this->key === '') {
            return ['success' => false, 'message' => __('微信退款配置不完整', 'qilingshop')];
        }

        if ($refund_no === '') {
            return ['success' => false, 'message' => __('缺少退款单号', 'qilingshop')];
        }

        if ($payment_no === '' && $order_no === '') {
            return ['success' => false, 'message' => __('缺少微信交易号或商户订单号', 'qilingshop')];
        }

        if ($amount <= 0) {
            return [
                'success'             => true,
                'status'              => 'success',
                'gateway_refund_no'   => $refund_no,
                'refunded_amount'     => 0,
                'message'             => __('订单无需原路退回现金金额', 'qilingshop'),
                'gateway_refunded_at' => current_time('mysql'),
            ];
        }

        $client_cert = $this->get_refund_pem_contents($this->client_cert, __('微信商户 API 证书', 'qilingshop'));
        if (is_wp_error($client_cert)) {
            return ['success' => false, 'message' => $client_cert->get_error_message()];
        }

        $client_key = $this->get_refund_pem_contents($this->client_key, __('微信商户 API 私钥', 'qilingshop'));
        if (is_wp_error($client_key)) {
            return ['success' => false, 'message' => $client_key->get_error_message()];
        }

        $refund_fee = (int) round($amount * 100);
        $request = [
            'appid'         => $this->appid,
            'mch_id'        => $this->mchid,
            'nonce_str'     => md5(uniqid('qls_wx_refund_', true)),
            'out_refund_no' => $refund_no,
            'total_fee'     => $refund_fee,
            'refund_fee'    => $refund_fee,
        ];

        if ($payment_no !== '') {
            $request['transaction_id'] = $payment_no;
        } else {
            $request['out_trade_no'] = $order_no;
        }

        if (!empty($params['refund_reason'])) {
            $request['refund_desc'] = sanitize_text_field((string) $params['refund_reason']);
        }

        $request['sign'] = $this->generate_sign($request);
        $xml = $this->array_to_xml($request);

        $response = $this->http_post_with_cert('https://api.mch.weixin.qq.com/secapi/pay/refund', $xml, $client_cert, $client_key);
        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $result = $this->xml_to_array($response);
        if (($result['return_code'] ?? '') !== 'SUCCESS') {
            return [
                'success' => false,
                'status'  => 'failed',
                'message' => $result['return_msg'] ?? __('微信退款请求失败', 'qilingshop'),
                'raw'     => $result,
            ];
        }

        if (($result['result_code'] ?? '') !== 'SUCCESS') {
            return [
                'success' => false,
                'status'  => 'failed',
                'message' => $result['err_code_des'] ?? $result['return_msg'] ?? __('微信退款失败', 'qilingshop'),
                'raw'     => $result,
            ];
        }

        return [
            'success'             => true,
            'status'              => 'success',
            'gateway_refund_no'   => sanitize_text_field((string) ($result['refund_id'] ?? $refund_no)),
            'refunded_amount'     => isset($result['refund_fee']) ? ((float) $result['refund_fee'] / 100) : $amount,
            'message'             => __('微信原路退款成功', 'qilingshop'),
            'gateway_refunded_at' => current_time('mysql'),
            'raw'                 => $result,
        ];
    }

    /**
     * 扫码支付（电脑端）
     */
    private function create_native_pay($order_no, $amount, $subject, $return_url = '') {
        // Native 支付直接跳转到 wechat.php 处理
        // 避免在此处调用 unifiedorder 后 wechat.php 再次调用导致参数不一致报错
        $query_args = [
            'order'   => $order_no,
            'price'   => $amount,
            'subject' => $subject,
        ];
        if ($return_url !== '') {
            $query_args['redirect_url'] = $return_url;
        }

        return [
            'success' => true,
            'type'    => 'qrcode', // 虽然不直接返回二维码，但前端处理逻辑兼容
            'qrcode'  => '', // 留空，前端会跳转 pay_url
            'pay_url' => qilingshop_get_payment_entry_url('wechat', $query_args),
        ];
    }

    /**
     * H5支付（手机浏览器）
     */
    private function create_h5_pay($order_no, $amount, $subject) {
        if (!get_option('qilingshop_wechat_h5')) {
            return ['success' => false, 'message' => __('未启用H5支付', 'qilingshop')];
        }

        $total_fee = (int) round($amount * 100);
        $nonce_str = md5(uniqid());
        $spbill_create_ip = qilingshop_security()->get_client_ip();
        $scene_info = json_encode([
            'h5_info' => [
                'type'     => 'Wap',
                'wap_url'  => home_url(),
                'wap_name' => get_bloginfo('name'),
            ]
        ]);

        $params = [
            'appid'            => $this->appid,
            'body'             => $subject,
            'mch_id'           => $this->mchid,
            'nonce_str'        => $nonce_str,
            'notify_url'       => $this->notify_url,
            'out_trade_no'     => $order_no,
            'scene_info'       => $scene_info,
            'spbill_create_ip' => $spbill_create_ip,
            'total_fee'        => $total_fee,
            'trade_type'       => 'MWEB',
        ];

        $params['sign'] = $this->generate_sign($params);
        $xml = $this->array_to_xml($params);

        $response = $this->http_post('https://api.mch.weixin.qq.com/pay/unifiedorder', $xml);
        $result = $this->xml_to_array($response);

        if (isset($result['mweb_url'])) {
            return [
                'success' => true,
                'type'    => 'redirect',
                'pay_url' => $result['mweb_url'],
            ];
        }

        $error = $result['err_code_des'] ?? ($result['return_msg'] ?? __('H5支付请求失败', 'qilingshop'));
        return ['success' => false, 'message' => $error];
    }

    /**
     * JSAPI支付（微信内）
     */
    private function create_jsapi_pay($order_no, $amount, $subject, $return_url = '') {
        if (!get_option('qilingshop_wechat_jsapi')) {
            return ['success' => false, 'message' => __('未启用JSAPI支付', 'qilingshop')];
        }

        // JSAPI 需要 OAuth/openid 和支付页输出，统一交给已有 wechat.php 入口处理。
        // 该入口会按订单号识别虚拟资源/VIP/充值与实物商城订单，避免两套系统回跳规则分叉。
        $query_args = [
            'order'   => $order_no,
            'price'   => $amount,
            'subject' => $subject,
        ];
        if ($return_url !== '') {
            $query_args['redirect_url'] = $return_url;
        }

        return [
            'success' => true,
            'type'    => 'redirect',
            'pay_url' => qilingshop_get_payment_entry_url('wechat', $query_args),
        ];
    }

    /**
     * 验证回调
     */
    public function verify($data) {
        if (is_string($data)) {
            $data = $this->xml_to_array($data);
        }

        if (!isset($data['sign'])) {
            return false;
        }

        $sign = $data['sign'];
        unset($data['sign']);

        return $sign === $this->generate_sign($data);
    }

    /**
     * 生成签名
     */
    private function generate_sign($params) {
        ksort($params);
        $string = '';
        foreach ($params as $k => $v) {
            if ($k != 'sign' && $v !== '' && !is_array($v)) {
                $string .= "$k=$v&";
            }
        }
        $string .= "key=" . $this->key;
        return strtoupper(md5($string));
    }

    private function is_wechat_browser() {
        return strpos($_SERVER['HTTP_USER_AGENT'] ?? '', 'MicroMessenger') !== false;
    }

    private function array_to_xml($data) {
        $xml = '<xml>';
        foreach ($data as $k => $v) {
            $xml .= is_numeric($v) ? "<$k>$v</$k>" : "<$k><![CDATA[$v]]></$k>";
        }
        $xml .= '</xml>';
        return $xml;
    }

    private function xml_to_array($xml) {
        libxml_use_internal_errors(true);
        $obj = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        if ($obj === false) {
            return [];
        }
        return json_decode(json_encode($obj), true);
    }

    private function http_post($url, $data) {
        $ssl_verify = apply_filters('qilingshop_wechat_ssl_verify', true);
        $response = wp_remote_post($url, [
            'timeout'   => 30,
            'sslverify' => $ssl_verify ? true : false,
            'headers'   => [
                'Content-Type' => 'text/xml; charset=utf-8',
            ],
            'body'      => $data,
        ]);
        if (is_wp_error($response)) {
            if (function_exists('qilingshop_log')) {
                qilingshop_log('wechat_http_post_failed', 'error', [
                    'url'     => $url,
                    'message' => $response->get_error_message(),
                ]);
            }
            return '';
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($status_code < 200 || $status_code >= 300) {
            if (function_exists('qilingshop_log')) {
                qilingshop_log('wechat_http_post_invalid_response', 'error', [
                    'url'    => $url,
                    'status' => $status_code,
                ]);
            }
            return '';
        }

        return $body;
    }

    /**
     * 使用双向证书发起退款请求。
     *
     * @param string $url
     * @param string $data
     * @param string $client_cert
     * @param string $client_key
     * @return string|WP_Error
     */
    private function http_post_with_cert($url, $data, $client_cert, $client_key) {
        $cert_file = $this->write_temp_pem_file('qls_wechat_refund_cert_', $client_cert);
        if (is_wp_error($cert_file)) {
            return $cert_file;
        }

        $key_file = $this->write_temp_pem_file('qls_wechat_refund_key_', $client_key);
        if (is_wp_error($key_file)) {
            @unlink($cert_file);
            return $key_file;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSLCERT, $cert_file);
        curl_setopt($ch, CURLOPT_SSLKEY, $key_file);
        curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'PEM');
        curl_setopt($ch, CURLOPT_SSLKEYTYPE, 'PEM');
        $ssl_verify = apply_filters('qilingshop_wechat_ssl_verify', true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $ssl_verify ? true : false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $ssl_verify ? 2 : 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);

        @unlink($cert_file);
        @unlink($key_file);

        if ($response === false) {
            return new WP_Error('qilingshop_wechat_refund_http_failed', $curl_error !== '' ? $curl_error : __('微信退款请求失败', 'qilingshop'));
        }

        return $response;
    }

    /**
     * 读取退款证书内容，支持直接粘贴 PEM 或填写绝对路径/站内 URL。
     *
     * @param string $raw   原始配置
     * @param string $label 字段标签
     * @return string|WP_Error
     */
    private function get_refund_pem_contents($raw, $label) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return new WP_Error('qilingshop_wechat_refund_pem_empty', $label . ' ' . __('不能为空', 'qilingshop'));
        }

        $normalized = $this->normalize_refund_pem($raw);
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

                $contents = $this->normalize_refund_pem($contents);
                if (strpos($contents, '-----BEGIN') !== false) {
                    return $contents;
                }
            }
        }

        return new WP_Error('qilingshop_wechat_refund_pem_invalid', $label . ' ' . __('读取失败，请检查 PEM 内容或文件路径', 'qilingshop'));
    }

    /**
     * 规范化退款证书 PEM。
     *
     * @param string $pem
     * @return string
     */
    private function normalize_refund_pem($pem) {
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
     * 将 PEM 内容写入临时文件。
     *
     * @param string $prefix
     * @param string $contents
     * @return string|WP_Error
     */
    private function write_temp_pem_file($prefix, $contents) {
        $tmp_file = function_exists('wp_tempnam') ? wp_tempnam($prefix) : tempnam(sys_get_temp_dir(), $prefix);
        if (!$tmp_file) {
            return new WP_Error('qilingshop_wechat_refund_temp_file_failed', __('无法创建微信退款临时证书文件', 'qilingshop'));
        }

        $written = @file_put_contents($tmp_file, $contents);
        if ($written === false) {
            @unlink($tmp_file);
            return new WP_Error('qilingshop_wechat_refund_temp_write_failed', __('无法写入微信退款临时证书文件', 'qilingshop'));
        }

        return $tmp_file;
    }
}
