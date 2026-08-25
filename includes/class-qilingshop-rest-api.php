<?php
/**
 * REST API 接口类
 * 
 * 统一处理插件的所有 REST API 请求
 *
 * @package QilingShop
 * @since   2.0.7
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_REST_API {

    /**
     * 单例实例
     *
     * @var QilingShop_REST_API
     */
    private static $instance = null;

    /**
     * 命名空间
     */
    const NAMESPACE = 'qls/v1';

    /**
     * 获取单例实例
     *
     * @return QilingShop_REST_API
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 构造函数
     */
    private function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * 注册路由
     */
    public function register_routes() {
        // 支付宝异步通知
        register_rest_route(self::NAMESPACE, '/notify/alipay', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_alipay_notify'],
            'permission_callback' => '__return_true', // 开放接口，验证在回调内部进行
        ]);

        // 微信支付异步通知
        register_rest_route(self::NAMESPACE, '/notify/wechat', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_wechat_notify'],
            'permission_callback' => '__return_true', // 开放接口，验证在回调内部进行
        ]);

        // 微信小程序支付异步通知（独立于原微信支付）
        register_rest_route(self::NAMESPACE, '/notify/wechat-miniapp', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_wechat_miniapp_notify'],
            'permission_callback' => '__return_true',
        ]);

        // 易支付异步通知
        register_rest_route(self::NAMESPACE, '/notify/epay', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_epay_notify'],
            'permission_callback' => '__return_true',
        ]);

        // 虎皮椒 V3 异步通知
        register_rest_route(self::NAMESPACE, '/notify/xhpay', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_xhpay_notify'],
            'permission_callback' => '__return_true',
        ]);

        // PayPal Webhook 通知
        register_rest_route(self::NAMESPACE, '/notify/paypal', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_paypal_notify'],
            'permission_callback' => '__return_true',
        ]);

        // Stripe Webhook 通知
        register_rest_route(self::NAMESPACE, '/notify/stripe', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_stripe_notify'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * 处理支付宝回调
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_alipay_notify($request) {
        // 设置时区，保持与原文件一致
        date_default_timezone_set('Asia/Shanghai');
        
        $data = $request->get_body_params();
        
        // 如果是 JSON 格式（有些网关可能会转发 JSON）
        if (empty($data)) {
            $data = $request->get_json_params();
        }

        // 兼容原有的 POST 获取方式
        if (empty($data)) {
            $data = $_POST;
        }

        if (empty($data) || !isset($data['sign'])) {
            return new WP_REST_Response('fail', 200);
        }

        // 验证签名 (RSA2)
        $public_key = get_option('qilingshop_alipay_public_key');
        $sign = $data['sign'];
        // $sign_type = $data['sign_type'] ?? 'RSA2'; // 暂未使用，保留原逻辑
        
        // 移除不参与签名的字段
        $verify_data = $data;
        unset($verify_data['sign'], $verify_data['sign_type']);

        ksort($verify_data);
        $str = '';
        foreach ($verify_data as $k => $v) {
            if ($v !== '' && !is_array($v)) {
                $str .= "$k=$v&";
            }
        }
        $str = rtrim($str, '&');

        // RSA2验签
        $public_key_formatted = function_exists('qilingshop_format_public_key_pem')
            ? qilingshop_format_public_key_pem($public_key)
            : '';
        if ($public_key_formatted === '') {
            $public_key_formatted = "-----BEGIN PUBLIC KEY-----\n" . wordwrap((string) $public_key, 64, "\n", true) . "\n-----END PUBLIC KEY-----";
        }
        $res = openssl_pkey_get_public($public_key_formatted);

        if (!$res) {
            return new WP_REST_Response('fail', 200);
        }

        $verify = openssl_verify($str, base64_decode($sign), $res, OPENSSL_ALGO_SHA256);

        if ($verify !== 1) {
            return new WP_REST_Response('fail', 200);
        }

        // 验证交易状态
        $trade_status = $data['trade_status'] ?? '';
        if (!in_array($trade_status, ['TRADE_SUCCESS', 'TRADE_FINISHED'])) {
            return new WP_REST_Response('fail', 200);
        }

        // 校验商户维度，避免其它商户通知串单
        $expected_app_id = sanitize_text_field((string) get_option('qilingshop_alipay_app_id', ''));
        $notify_app_id = sanitize_text_field((string) ($data['app_id'] ?? ''));
        if ($expected_app_id !== '') {
            if ($notify_app_id === '' || !hash_equals($expected_app_id, $notify_app_id)) {
                return new WP_REST_Response('fail', 200);
            }
        }

        $expected_seller = sanitize_text_field((string) get_option('qilingshop_alipay_seller', ''));
        if ($expected_seller !== '') {
            $notify_seller = sanitize_text_field((string) ($data['seller_id'] ?? ($data['seller_email'] ?? '')));
            if ($notify_seller === '' || !hash_equals($expected_seller, $notify_seller)) {
                return new WP_REST_Response('fail', 200);
            }
        }

        // 获取订单信息
        $order_no = sanitize_text_field((string) ($data['out_trade_no'] ?? ''));
        $trade_no = sanitize_text_field((string) ($data['trade_no'] ?? ''));
        $total_amount = floatval($data['total_amount'] ?? ($data['total_fee'] ?? 0));
        if ($order_no === '' || $total_amount <= 0) {
            return new WP_REST_Response('fail', 200);
        }

        // 处理订单
        $result = $this->process_payment_success($order_no, $total_amount, 'alipay', $trade_no);
        if ($result !== 'success') {
            return new WP_REST_Response('fail', 200);
        }

        return new WP_REST_Response('success', 200);
    }

    /**
     * 处理微信支付回调
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_wechat_notify($request) {
        date_default_timezone_set('Asia/Shanghai');
        
        // 获取 XML 数据
        $xml = $request->get_body();
        
        if (empty($xml)) {
            return $this->wechat_response('FAIL', '数据为空');
        }

        // 解析XML
        libxml_use_internal_errors(true);
        $xml_obj = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        
        if ($xml_obj === false) {
            return $this->wechat_response('FAIL', 'XML解析失败');
        }
        
        $data = json_decode(json_encode($xml_obj), true);

        if (!$data || (isset($data['return_code']) && $data['return_code'] != 'SUCCESS')) {
            return $this->wechat_response('FAIL', '通信失败');
        }

        // 验证签名
        $key = get_option('qilingshop_wechat_key');
        $sign = isset($data['sign']) ? (string) $data['sign'] : '';
        if ($sign === '') {
            return $this->wechat_response('FAIL', '签名缺失');
        }

        if (!qilingshop_security()->verify_payment_sign($data, $sign, $key)) {
            return $this->wechat_response('FAIL', '签名错误');
        }

        // 校验商户维度，避免其它商户通知串单
        $notify_appid = sanitize_text_field((string) ($data['appid'] ?? ''));
        $notify_mchid = sanitize_text_field((string) ($data['mch_id'] ?? ''));
        $expected_appid = sanitize_text_field((string) get_option('qilingshop_wechat_appid', ''));
        $expected_mchid = sanitize_text_field((string) get_option('qilingshop_wechat_mchid', ''));
        if ($expected_appid !== '' && !hash_equals($expected_appid, $notify_appid)) {
            return $this->wechat_response('FAIL', '商户不匹配');
        }
        if ($expected_mchid !== '' && !hash_equals($expected_mchid, $notify_mchid)) {
            return $this->wechat_response('FAIL', '商户不匹配');
        }

        // 验证支付成功
        if (isset($data['result_code']) && $data['result_code'] != 'SUCCESS') {
            return $this->wechat_response('FAIL', '支付失败');
        }

        // 获取订单信息
        $order_no = sanitize_text_field((string) ($data['out_trade_no'] ?? ''));
        $transaction_id = sanitize_text_field((string) ($data['transaction_id'] ?? ''));
        $total_fee = floatval($data['total_fee'] ?? 0) / 100; // 分转元
        if ($order_no === '' || $total_fee <= 0) {
            return $this->wechat_response('FAIL', '参数错误');
        }

        // 处理订单
        $result = $this->process_payment_success($order_no, $total_fee, 'wechat', $transaction_id);

        if ($result === 'success') {
             return $this->wechat_response('SUCCESS', 'OK');
        } else {
             return $this->wechat_response('FAIL', $result);
        }
    }

    /**
     * 处理微信小程序支付回调。
     *
     * @param WP_REST_Request $request 请求对象
     * @return WP_REST_Response
     */
    public function handle_wechat_miniapp_notify($request) {
        if (class_exists('QilingShop_Miniapp') && method_exists('QilingShop_Miniapp', 'instance')) {
            $miniapp = QilingShop_Miniapp::instance();
            if ($miniapp && method_exists($miniapp, 'handle_wechat_miniapp_notify')) {
                return $miniapp->handle_wechat_miniapp_notify($request);
            }
        }

        return $this->wechat_response('FAIL', '微信小程序支付服务不可用');
    }

    /**
     * 处理易支付异步通知。
     *
     * @param WP_REST_Request $request 请求对象
     * @return WP_REST_Response
     */
    public function handle_epay_notify($request) {
        $data = array_merge((array) $request->get_query_params(), (array) $request->get_body_params());
        if (empty($data)) {
            $json = $request->get_json_params();
            if (is_array($json)) {
                $data = $json;
            }
        }

        if (empty($data['sign']) || empty($data['out_trade_no'])) {
            return new WP_REST_Response('fail', 200);
        }

        $key = sanitize_text_field((string) get_option('qilingshop_epay_key', ''));
        if ($key === '') {
            return new WP_REST_Response('fail', 200);
        }

        $expected_pid = sanitize_text_field((string) get_option('qilingshop_epay_pid', ''));
        $notify_pid = sanitize_text_field((string) ($data['pid'] ?? ''));
        if ($expected_pid !== '') {
            if ($notify_pid === '' || !hash_equals($expected_pid, $notify_pid)) {
                return new WP_REST_Response('fail', 200);
            }
        }

        $sign = sanitize_text_field((string) $data['sign']);
        $verify_sign = $this->build_epay_notify_sign($data, $key);
        if (!hash_equals(strtolower($sign), strtolower($verify_sign))) {
            return new WP_REST_Response('fail', 200);
        }

        $trade_status = strtoupper(sanitize_text_field((string) ($data['trade_status'] ?? '')));
        if (!in_array($trade_status, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
            return new WP_REST_Response('fail', 200);
        }

        $order_no = sanitize_text_field((string) $data['out_trade_no']);
        $amount = (float) ($data['money'] ?? ($data['total_fee'] ?? 0));
        $transaction_id = sanitize_text_field((string) ($data['trade_no'] ?? ''));
        if ($order_no === '' || $amount <= 0) {
            return new WP_REST_Response('fail', 200);
        }

        $result = $this->process_payment_success($order_no, $amount, 'epay', $transaction_id);
        return new WP_REST_Response($result === 'success' ? 'success' : 'fail', 200);
    }

    /**
     * 处理虎皮椒 V3 异步通知。
     *
     * @param WP_REST_Request $request 请求对象
     * @return WP_REST_Response
     */
    public function handle_xhpay_notify($request) {
        $data = (array) $request->get_body_params();
        if (empty($data)) {
            $json = $request->get_json_params();
            if (is_array($json)) {
                $data = $json;
            }
        }
        if (empty($data)) {
            $data = (array) $request->get_query_params();
        }

        if (!is_array($data) || empty($data['hash']) || empty($data['trade_order_id'])) {
            return new WP_REST_Response('fail', 200);
        }

        $plugin_id = sanitize_key((string) get_option('qilingshop_xhpay_plugin_id', 'qilingshop-xhpay3'));
        $plugins = sanitize_key((string) ($data['plugins'] ?? ''));
        if (
            $plugins !== ''
            && strpos($plugins, $plugin_id) !== 0
            && $plugins !== 'wechat'
            && $plugins !== 'alipay'
        ) {
            return new WP_REST_Response('fail', 200);
        }

        $method = 'alipay';
        if (strpos($plugins, 'wechat') !== false || sanitize_key((string) ($data['payment'] ?? '')) === 'wechat') {
            $method = 'wechat';
        }

        $appsecret = $method === 'wechat'
            ? sanitize_text_field((string) get_option('qilingshop_xhpay_appsecret_wechat', ''))
            : sanitize_text_field((string) get_option('qilingshop_xhpay_appsecret_alipay', ''));
        $expected_appid = $method === 'wechat'
            ? sanitize_text_field((string) get_option('qilingshop_xhpay_appid_wechat', ''))
            : sanitize_text_field((string) get_option('qilingshop_xhpay_appid_alipay', ''));

        if ($appsecret === '') {
            $appsecret = sanitize_text_field((string) get_option('qilingshop_xhpay_appsecret_alipay', ''));
        }
        if ($appsecret === '') {
            $appsecret = sanitize_text_field((string) get_option('qilingshop_xhpay_appsecret_wechat', ''));
        }
        if ($appsecret === '') {
            return new WP_REST_Response('fail', 200);
        }

        $notify_appid = sanitize_text_field((string) ($data['appid'] ?? ''));
        if ($expected_appid !== '') {
            if ($notify_appid === '' || !hash_equals($expected_appid, $notify_appid)) {
                return new WP_REST_Response('fail', 200);
            }
        }

        $verify_hash = $this->build_xhpay_notify_hash($data, $appsecret);
        if (!hash_equals(strtolower((string) $data['hash']), strtolower($verify_hash))) {
            return new WP_REST_Response('fail', 200);
        }

        $status = sanitize_text_field((string) ($data['status'] ?? ''));
        if ($status !== 'OD') {
            return new WP_REST_Response('fail', 200);
        }

        $order_no_raw = sanitize_text_field((string) $data['trade_order_id']);
        $order_no_parts = explode('@', $order_no_raw);
        $order_no = sanitize_text_field((string) $order_no_parts[0]);
        $amount = (float) ($data['total_fee'] ?? 0);
        $transaction_id = sanitize_text_field((string) ($data['open_order_id'] ?? ($data['transaction_id'] ?? '')));
        if ($order_no === '' || $amount <= 0) {
            return new WP_REST_Response('fail', 200);
        }

        $result = $this->process_payment_success($order_no, $amount, 'xhpay', $transaction_id);
        return new WP_REST_Response($result === 'success' ? 'success' : 'fail', 200);
    }

    /**
     * 处理 PayPal Webhook 通知（Orders v2）
     *
     * @param WP_REST_Request $request 请求对象
     * @return WP_REST_Response
     */
    public function handle_paypal_notify($request) {
        $payload = (string) $request->get_body();
        if ($payload === '') {
            return new WP_REST_Response('EMPTY_PAYLOAD', 400);
        }

        $event = json_decode($payload, true);
        if (!is_array($event) || empty($event['event_type'])) {
            return new WP_REST_Response('INVALID_EVENT', 400);
        }

        $paypal_file = QILINGSHOP_PATH . 'payment/class-payment-paypal.php';
        if (!class_exists('QilingShop_Payment_Paypal') && file_exists($paypal_file)) {
            require_once $paypal_file;
        }
        if (!class_exists('QilingShop_Payment_Paypal')) {
            return new WP_REST_Response('PAYPAL_GATEWAY_MISSING', 500);
        }

        $paypal = new QilingShop_Payment_Paypal();
        if (!$paypal->verify_webhook_signature($payload, $request->get_headers())) {
            return new WP_REST_Response('INVALID_SIGNATURE', 400);
        }

        $event_type = sanitize_text_field((string) $event['event_type']);

        // 用户完成授权后，服务端执行捕获，避免依赖前端同步返回。
        if ($event_type === 'CHECKOUT.ORDER.APPROVED') {
            $approved = $paypal->extract_order_approved_data($event);
            if (empty($approved['success']) || empty($approved['paypal_order_id'])) {
                return new WP_REST_Response('INVALID_APPROVED_EVENT', 400);
            }

            $captured = $paypal->capture_order(
                (string) $approved['paypal_order_id'],
                isset($approved['order_no']) ? (string) $approved['order_no'] : ''
            );
            if (empty($captured['success'])) {
                return new WP_REST_Response('CAPTURE_FAILED', 500);
            }

            $result = $this->process_payment_success(
                (string) $captured['order_no'],
                (float) $captured['amount'],
                'paypal',
                (string) $captured['transaction_id'],
                (string) $captured['currency']
            );

            if ($result === 'success') {
                return new WP_REST_Response('success', 200);
            }
            return new WP_REST_Response('PROCESS_FAILED:' . $result, 500);
        }

        // 捕获完成事件用于兜底，重复通知也可安全幂等处理。
        if ($event_type === 'PAYMENT.CAPTURE.COMPLETED') {
            $captured = $paypal->extract_capture_from_event($event);
            if (empty($captured['success'])) {
                return new WP_REST_Response('INVALID_CAPTURE_EVENT', 400);
            }

            $result = $this->process_payment_success(
                (string) $captured['order_no'],
                (float) $captured['amount'],
                'paypal',
                (string) $captured['transaction_id'],
                (string) $captured['currency']
            );

            if ($result === 'success') {
                return new WP_REST_Response('success', 200);
            }
            return new WP_REST_Response('PROCESS_FAILED:' . $result, 500);
        }

        return new WP_REST_Response('IGNORED', 200);
    }

    /**
     * 处理 Stripe Webhook 通知
     *
     * @param WP_REST_Request $request 请求对象
     * @return WP_REST_Response
     */
    public function handle_stripe_notify($request) {
        $payload = (string) $request->get_body();
        if ($payload === '') {
            return new WP_REST_Response('EMPTY_PAYLOAD', 400);
        }

        $signature = (string) $request->get_header('stripe-signature');
        $webhook_secret = (string) get_option('qilingshop_stripe_webhook_secret', '');
        if ($webhook_secret === '') {
            return new WP_REST_Response('WEBHOOK_SECRET_MISSING', 400);
        }

        if (!$this->verify_stripe_signature($payload, $signature, $webhook_secret)) {
            return new WP_REST_Response('INVALID_SIGNATURE', 400);
        }

        $event = json_decode($payload, true);
        if (!is_array($event) || empty($event['type'])) {
            return new WP_REST_Response('INVALID_EVENT', 400);
        }

        $type = (string) $event['type'];
        if (!in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            return new WP_REST_Response('IGNORED', 200);
        }

        $object = isset($event['data']['object']) && is_array($event['data']['object']) ? $event['data']['object'] : [];
        if (empty($object)) {
            return new WP_REST_Response('INVALID_OBJECT', 400);
        }

        $payment_status = isset($object['payment_status']) ? (string) $object['payment_status'] : '';
        if ($payment_status !== '' && $payment_status !== 'paid') {
            return new WP_REST_Response('UNPAID', 200);
        }

        $order_no = '';
        if (!empty($object['metadata']['order_no'])) {
            $order_no = sanitize_text_field((string) $object['metadata']['order_no']);
        }
        if ($order_no === '' && !empty($object['client_reference_id'])) {
            $order_no = sanitize_text_field((string) $object['client_reference_id']);
        }
        if ($order_no === '') {
            return new WP_REST_Response('ORDER_NO_MISSING', 200);
        }

        $transaction_id = !empty($object['payment_intent']) ? sanitize_text_field((string) $object['payment_intent']) : sanitize_text_field((string) ($object['id'] ?? ''));
        $currency = !empty($object['currency']) ? strtoupper(sanitize_text_field((string) $object['currency'])) : 'USD';
        $minor_amount = isset($object['amount_total']) ? (int) $object['amount_total'] : 0;
        $paid_amount = $this->stripe_minor_to_major($minor_amount, $currency);

        $result = $this->process_payment_success($order_no, $paid_amount, 'stripe', $transaction_id, $currency);
        if ($result === 'success') {
            return new WP_REST_Response('success', 200);
        }

        return new WP_REST_Response('PROCESS_FAILED:' . $result, 500);
    }

    /**
     * 返回微信格式的响应
     */
    private function wechat_response($code, $msg) {
        $xml = "<xml><return_code><![CDATA[{$code}]]></return_code><return_msg><![CDATA[{$msg}]]></return_msg></xml>";
        $response = new WP_REST_Response($xml, 200);
        $response->header('Content-Type', 'text/xml; charset=utf-8');
        return $response;
    }

    /**
     * 统一支付成功处理逻辑
     * 
     * 整合了原 payment/notify-*.php 中的 qilingshop_process_payment_success 函数逻辑
     */
    public function process_payment_success($order_no, $amount, $gateway, $transaction_id = '', $paid_currency = '') {
        global $wpdb;
        $db = QilingShop_Database::instance();

        // 1. 充值订单 (CZ开头)
        if (strpos($order_no, 'CZ') === 0) {
            $table = $db->get_table('recharge');
            $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE order_no = %s", $order_no));
            
            if (!$order) {
                return '订单不存在';
            }
            
            // 优先使用实付金额进行比对
            $expected_amount = floatval(isset($order->final_amount) && $order->final_amount > 0 ? $order->final_amount : $order->amount);
            $payment_snapshot = $this->extract_payment_amount_snapshot($order);
            if ($expected_amount > 0 && !$this->verify_paid_amount($expected_amount, $amount, $gateway, $paid_currency, $payment_snapshot)) {
                return '金额不匹配';
            }
            
            $completed = QilingShop_Recharge::instance()->complete($order_no, $transaction_id);
            return $completed ? 'success' : '订单处理失败';
        } 
        // 2. 实物/团购订单 (SHOP/TUAN开头)
        elseif ((strpos($order_no, 'SHOP') === 0 || strpos($order_no, 'TUAN') === 0) && function_exists('qls_shop_order')) {
            $order = qls_shop_order()->get_by_order_no($order_no);
            
            if (!$order) {
                return '订单不存在';
            }
            
            $expected_amount = floatval($order->final_amount);
            $payment_snapshot = $this->extract_payment_amount_snapshot($order);
            if ($expected_amount > 0 && !$this->verify_paid_amount($expected_amount, $amount, $gateway, $paid_currency, $payment_snapshot)) {
                return '金额不匹配';
            }
            
            $marked = qls_shop_order()->mark_paid($order_no, $transaction_id, $gateway);
            return $marked ? 'success' : '订单处理失败';
        } 
        // 3. 默认资源/积分订单
        else {
            $table = $db->get_table('orders');
            $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE order_no = %s", $order_no));
            
            if (!$order) {
                return '订单不存在';
            }
            
            $expected_amount = floatval($order->final_price > 0 ? $order->final_price : $order->price_rmb);
            $payment_snapshot = $this->extract_payment_amount_snapshot($order);
            if ($expected_amount > 0 && !$this->verify_paid_amount($expected_amount, $amount, $gateway, $paid_currency, $payment_snapshot)) {
                return '金额不匹配';
            }
            
            $marked = QilingShop_Order::instance()->mark_paid($order_no, $transaction_id, $gateway);
            return $marked ? 'success' : '订单处理失败';
        }
    }

    /**
     * 校验实付金额
     *
     * @param float  $expected_cny 订单期望人民币金额
     * @param float  $paid_amount  实际回调金额
     * @param string $gateway      支付网关
     * @param array  $payment_snapshot 下单时金额快照
     * @return bool
     */
    private function verify_paid_amount($expected_cny, $paid_amount, $gateway, $paid_currency = '', $payment_snapshot = []) {
        $expected_cny = (float) $expected_cny;
        $paid_amount = (float) $paid_amount;
        $paid_currency = strtoupper((string) $paid_currency);

        if ($gateway === 'paypal') {
            $snapshot_currency = strtoupper((string) ($payment_snapshot['settlement_currency'] ?? ''));
            $snapshot_expected = isset($payment_snapshot['expected_paid_amount'])
                ? round((float) $payment_snapshot['expected_paid_amount'], 2)
                : 0.0;
            if ($snapshot_currency === 'USD' && $snapshot_expected > 0) {
                if ($paid_currency !== '' && $paid_currency !== 'USD') {
                    return false;
                }
                return $this->amounts_equal($snapshot_expected, $paid_amount);
            }

            $snapshot_rate = isset($payment_snapshot['rate']) ? (float) $payment_snapshot['rate'] : 0.0;
            if ($snapshot_rate > 0) {
                if ($paid_currency !== '' && $paid_currency !== 'USD') {
                    return false;
                }
                $expected_usd = round($expected_cny / $snapshot_rate, 2);
                return $this->amounts_equal($expected_usd, $paid_amount);
            }

            $rate = (float) get_option('qilingshop_paypal_rate', 7);
            if ($rate <= 0) {
                $rate = 7;
            }
            $expected_usd = round($expected_cny / $rate, 2);
            return $this->amounts_equal($expected_usd, $paid_amount);
        }

        if ($gateway === 'stripe') {
            $snapshot_currency = strtoupper((string) ($payment_snapshot['settlement_currency'] ?? ''));
            $snapshot_expected = isset($payment_snapshot['expected_paid_amount'])
                ? round((float) $payment_snapshot['expected_paid_amount'], 2)
                : 0.0;
            if ($snapshot_currency !== '') {
                if ($paid_currency !== '' && $paid_currency !== $snapshot_currency) {
                    return false;
                }
                if ($snapshot_currency === 'CNY') {
                    return $this->amounts_equal($expected_cny, $paid_amount);
                }
                if ($snapshot_expected > 0) {
                    return $this->amounts_equal($snapshot_expected, $paid_amount);
                }

                $snapshot_rate = isset($payment_snapshot['rate']) ? (float) $payment_snapshot['rate'] : 0.0;
                if ($snapshot_rate > 0) {
                    $expected_foreign = round($expected_cny / $snapshot_rate, 2);
                    return $this->amounts_equal($expected_foreign, $paid_amount);
                }
            }

            $currency = $paid_currency !== '' ? $paid_currency : strtoupper((string) get_option('qilingshop_stripe_currency', 'USD'));
            if ($currency === 'CNY') {
                return $this->amounts_equal($expected_cny, $paid_amount);
            }

            $rate = (float) get_option('qilingshop_stripe_rate', 7);
            if ($rate <= 0) {
                $rate = 7;
            }
            $expected_foreign = round($expected_cny / $rate, 2);
            return $this->amounts_equal($expected_foreign, $paid_amount);
        }

        return $this->amounts_equal($expected_cny, $paid_amount);
    }

    /**
     * 使用最小货币单位比较，避免浮点误差和一分钱容差。
     */
    private function amounts_equal($expected, $paid) {
        return (int) round((float) $expected * 100) === (int) round((float) $paid * 100);
    }

    /**
     * 从订单支付元数据中提取金额快照。
     *
     * @param object|array $order 订单数据
     * @return array
     */
    private function extract_payment_amount_snapshot($order) {
        $meta = null;

        if (is_object($order) && isset($order->payment_channel_meta)) {
            $meta = $order->payment_channel_meta;
        } elseif (is_array($order) && array_key_exists('payment_channel_meta', $order)) {
            $meta = $order['payment_channel_meta'];
        }

        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        if (!is_array($meta)) {
            return [];
        }

        $snapshot = isset($meta['amount_snapshot']) && is_array($meta['amount_snapshot'])
            ? $meta['amount_snapshot']
            : $meta;

        if (!is_array($snapshot) || empty($snapshot['expected_paid_amount'])) {
            return [];
        }

        return $snapshot;
    }

    /**
     * 构造易支付通知签名。
     *
     * @param array  $params 通知参数
     * @param string $key    商户密钥
     * @return string
     */
    private function build_epay_notify_sign(array $params, $key) {
        ksort($params);
        $pairs = [];
        foreach ($params as $param_key => $value) {
            if ($param_key === 'sign' || $param_key === 'sign_type' || $value === '' || $value === null || is_array($value)) {
                continue;
            }
            $pairs[] = $param_key . '=' . $value;
        }

        return md5(implode('&', $pairs) . (string) $key);
    }

    /**
     * 构造虎皮椒通知哈希。
     *
     * @param array  $data   通知数据
     * @param string $secret 密钥
     * @return string
     */
    private function build_xhpay_notify_hash(array $data, $secret) {
        ksort($data);
        $pairs = [];
        foreach ($data as $key => $value) {
            if ($key === 'hash' || $value === '' || $value === null || is_array($value)) {
                continue;
            }
            $pairs[] = $key . '=' . $value;
        }

        return md5(implode('&', $pairs) . (string) $secret);
    }

    /**
     * 验证 Stripe Webhook 签名
     *
     * @param string $payload        原始请求体
     * @param string $signature      Stripe-Signature 请求头
     * @param string $webhook_secret Webhook 密钥
     * @return bool
     */
    private function verify_stripe_signature($payload, $signature, $webhook_secret) {
        if ($signature === '' || $webhook_secret === '') {
            return false;
        }

        $timestamp = 0;
        $v1_signatures = [];
        $parts = explode(',', $signature);
        foreach ($parts as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            if ($kv[0] === 't') {
                $timestamp = (int) $kv[1];
            } elseif ($kv[0] === 'v1') {
                $v1_signatures[] = $kv[1];
            }
        }

        if ($timestamp <= 0 || empty($v1_signatures)) {
            return false;
        }

        // 默认 5 分钟时效窗口
        if (abs(time() - $timestamp) > 300) {
            return false;
        }

        $signed_payload = $timestamp . '.' . $payload;
        $expected = hash_hmac('sha256', $signed_payload, $webhook_secret);

        foreach ($v1_signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stripe 最小货币单位转主单位
     *
     * @param int    $minor_amount 最小单位金额
     * @param string $currency     币种
     * @return float
     */
    private function stripe_minor_to_major($minor_amount, $currency) {
        $currency = strtolower((string) $currency);
        $zero_decimal = [
            'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga',
            'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf',
        ];

        if (in_array($currency, $zero_decimal, true)) {
            return (float) $minor_amount;
        }

        return (float) $minor_amount / 100;
    }
}
