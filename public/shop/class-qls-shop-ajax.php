<?php
/**
 * 电商 AJAX 处理器
 * 
 * 处理购物车、订单等AJAX请求
 *
 * @package QilingShop
 * @since   2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_Shop_Ajax {

    /**
     * 单例实例
     */
    private static $instance = null;

    /**
     * 获取单例实例
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
        // 购物车
        add_action('wp_ajax_qls_add_to_cart', [$this, 'add_to_cart']);
        add_action('wp_ajax_nopriv_qls_add_to_cart', [$this, 'add_to_cart']);
        
        add_action('wp_ajax_qls_update_cart', [$this, 'update_cart']);
        add_action('wp_ajax_nopriv_qls_update_cart', [$this, 'update_cart']);
        
        add_action('wp_ajax_qls_remove_from_cart', [$this, 'remove_from_cart']);
        add_action('wp_ajax_nopriv_qls_remove_from_cart', [$this, 'remove_from_cart']);
        
        add_action('wp_ajax_qls_get_cart', [$this, 'get_cart']);
        add_action('wp_ajax_nopriv_qls_get_cart', [$this, 'get_cart']);
        
        add_action('wp_ajax_qls_get_cart_count', [$this, 'get_cart_count']);
        add_action('wp_ajax_nopriv_qls_get_cart_count', [$this, 'get_cart_count']);

        // 结账
        add_action('wp_ajax_qls_checkout', [$this, 'checkout']);
        add_action('wp_ajax_nopriv_qls_checkout', [$this, 'checkout']);
        
        // 计算运费
        add_action('wp_ajax_qls_calculate_shipping', [$this, 'calculate_shipping']);
        add_action('wp_ajax_nopriv_qls_calculate_shipping', [$this, 'calculate_shipping']);

        // 地址管理
        add_action('wp_ajax_qls_save_address', [$this, 'save_address']);
        add_action('wp_ajax_qls_get_addresses', [$this, 'get_addresses']);
        add_action('wp_ajax_qls_delete_address', [$this, 'delete_address']);

        // 获取SKU信息
        add_action('wp_ajax_qls_get_sku', [$this, 'get_sku']);
        add_action('wp_ajax_nopriv_qls_get_sku', [$this, 'get_sku']);

        // 订单管理
        add_action('wp_ajax_qls_cancel_order', [$this, 'cancel_order']);
        add_action('wp_ajax_qls_shop_confirm_receive', [$this, 'confirm_receive']);

        // 售后退款
        add_action('wp_ajax_qls_apply_refund', [$this, 'apply_refund']);
        add_action('wp_ajax_qls_cancel_refund', [$this, 'cancel_refund']);
        add_action('wp_ajax_qls_submit_refund_return', [$this, 'submit_refund_return']);
        add_action('wp_ajax_qls_upload_refund_image', [$this, 'upload_refund_image']);

        // 发票
        add_action('wp_ajax_qls_apply_invoice', [$this, 'apply_invoice']);
        add_action('wp_ajax_qls_cancel_invoice', [$this, 'cancel_invoice']);
        add_action('wp_ajax_qls_save_invoice_title', [$this, 'save_invoice_title']);
        add_action('wp_ajax_qls_delete_invoice_title', [$this, 'delete_invoice_title']);
        add_action('wp_ajax_qls_set_default_invoice_title', [$this, 'set_default_invoice_title']);

        // 好友助力
        add_action('wp_ajax_qls_assist_create_campaign', [$this, 'assist_create_campaign']);
        add_action('wp_ajax_nopriv_qls_assist_create_campaign', [$this, 'assist_create_campaign']);
        add_action('wp_ajax_qls_assist_help_campaign', [$this, 'assist_help_campaign']);
        add_action('wp_ajax_nopriv_qls_assist_help_campaign', [$this, 'assist_help_campaign']);
        add_action('wp_ajax_qls_assist_create_order', [$this, 'assist_create_order']);
        add_action('wp_ajax_nopriv_qls_assist_create_order', [$this, 'assist_create_order']);
    }

    /**
     * 验证Nonce
     */
    private function verify_nonce() {
        $nonce = sanitize_text_field((string) $this->get_post_scalar('nonce', ''));
        if (!wp_verify_nonce($nonce, 'qls_shop_nonce')) {
            wp_send_json_error(['message' => __('安全验证失败', 'qilingshop')]);
        }
    }

    /**
     * 公共读取接口防护（nonce + 频率限制）
     *
     * @param string $scope 限流作用域
     * @param int    $max 每时间窗最大请求数
     * @param int    $interval 时间窗（秒）
     * @return void
     */
    private function guard_public_read_request($scope, $max = 120, $interval = 60) {
        $nonce_value = isset($_REQUEST['nonce']) ? wp_unslash($_REQUEST['nonce']) : '';
        $nonce = is_scalar($nonce_value) ? sanitize_text_field((string) $nonce_value) : '';
        if (!wp_verify_nonce($nonce, 'qls_shop_nonce')) {
            wp_send_json_error(['message' => __('安全验证失败', 'qilingshop')], 403);
        }

        if (!function_exists('qilingshop_security')) {
            return;
        }

        $ip = qilingshop_security()->get_client_ip();
        $rate_key = 'qls_public_read_' . sanitize_key((string) $scope) . '_' . md5($ip);
        $allowed = qilingshop_security()->rate_limit($rate_key, (int) $max, (int) $interval);
        if (!$allowed) {
            wp_send_json_error(['message' => __('请求过于频繁，请稍后重试', 'qilingshop')], 429);
        }
    }

    private function acquire_named_lock($lock_name, $timeout = 5) {
        global $wpdb;

        $lock_name = sanitize_key((string) $lock_name);
        if ($lock_name === '') {
            return false;
        }

        $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, (int) $timeout));
        return (int) $result === 1;
    }

    private function release_named_lock($lock_name) {
        global $wpdb;

        $lock_name = sanitize_key((string) $lock_name);
        if ($lock_name === '') {
            return;
        }

        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
    }

    private function build_user_address_lock_name($user_id) {
        return 'qlsaddr_' . md5((string) absint($user_id));
    }

    /**
     * 获取下单限流主体（用户优先，其次游客，其次 IP）
     *
     * @return string
     */
    private function get_order_rate_actor_key() {
        $user_id = (int) get_current_user_id();
        if ($user_id > 0) {
            return 'u' . $user_id;
        }

        if (class_exists('QilingShop_Guest')) {
            $guest_id = (string) QilingShop_Guest::instance()->get_guest_id();
            if ($guest_id !== '') {
                return 'g' . substr(md5($guest_id), 0, 16);
            }
        }

        $ip = '';
        if (function_exists('qilingshop_security')) {
            $ip = (string) qilingshop_security()->get_client_ip();
        }
        if ($ip === '' && isset($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field((string) wp_unslash($_SERVER['REMOTE_ADDR']));
        }

        return 'ip' . substr(md5($ip !== '' ? $ip : '0.0.0.0'), 0, 16);
    }

    /**
     * 下单写接口限流
     *
     * @param string $scope 作用域
     * @param array  $context 附加上下文
     * @param int    $max 时间窗内最大次数
     * @param int    $interval 时间窗（秒）
     * @return void
     */
    private function guard_order_write_request($scope, $context = [], $max = 6, $interval = 60) {
        if (!function_exists('qilingshop_security')) {
            return;
        }

        $scope = sanitize_key((string) $scope);
        if ($scope === '') {
            $scope = 'default';
        }

        $normalized_context = [];
        if (is_array($context)) {
            foreach ($context as $key => $value) {
                $context_key = sanitize_key((string) $key);
                if ($context_key === '') {
                    continue;
                }
                if (is_array($value) || is_object($value)) {
                    continue;
                }
                $normalized_context[$context_key] = (string) $value;
            }
        }
        ksort($normalized_context);
        $context_hash = md5(wp_json_encode($normalized_context));

        $actor_key = $this->get_order_rate_actor_key();
        $max = (int) apply_filters('qls_shop_order_write_rate_limit_max', (int) $max, $scope, $normalized_context, $actor_key);
        $interval = (int) apply_filters('qls_shop_order_write_rate_limit_interval', (int) $interval, $scope, $normalized_context, $actor_key);

        if ($max <= 0 || $interval <= 0) {
            return;
        }

        $rate_key = 'qls_shop_order_write:' . $scope . ':' . $actor_key . ':' . $context_hash;
        $allowed = qilingshop_security()->rate_limit($rate_key, $max, $interval);
        if (!$allowed) {
            wp_send_json_error(['message' => __('请求过于频繁，请稍后重试', 'qilingshop')], 429);
        }
    }

    /**
     * 读取标量 POST 字段，避免数组/对象继续进入业务层。
     *
     * @param string $key 字段名
     * @param mixed  $default 默认值
     * @return mixed
     */
    private function get_post_scalar($key, $default = '') {
        if (!isset($_POST[$key])) {
            return $default;
        }

        $value = wp_unslash($_POST[$key]);
        if (is_array($value) || is_object($value)) {
            return $default;
        }

        return $value;
    }

    /**
     * 获取运费计算需要的白名单地址数据。
     *
     * @return array
     */
    private function get_sanitized_shipping_request_data() {
        return [
            'address_id'         => absint($this->get_post_scalar('address_id', 0)),
            'receiver_name'      => sanitize_text_field((string) $this->get_post_scalar('receiver_name', '')),
            'receiver_phone'     => sanitize_text_field((string) $this->get_post_scalar('receiver_phone', '')),
            'receiver_province'  => sanitize_text_field((string) $this->get_post_scalar('receiver_province', '')),
            'receiver_city'      => sanitize_text_field((string) $this->get_post_scalar('receiver_city', '')),
            'receiver_district'  => sanitize_text_field((string) $this->get_post_scalar('receiver_district', '')),
            'receiver_address'   => sanitize_textarea_field((string) $this->get_post_scalar('receiver_address', '')),
        ];
    }

    /**
     * 清洗 SKU 规格匹配参数。
     *
     * @param mixed $raw_values 原始规格数组
     * @return array
     */
    private function sanitize_sku_attr_values($raw_values) {
        if (!is_array($raw_values)) {
            return [];
        }

        $raw_values = wp_unslash($raw_values);
        $attr_values = [];

        foreach ($raw_values as $name => $value) {
            if (is_array($value) || is_object($value) || !is_scalar($value)) {
                continue;
            }

            $name = sanitize_text_field(wp_unslash((string) $name));
            if ($name === '') {
                continue;
            }

            $attr_values[$name] = sanitize_text_field((string) $value);
        }

        return $attr_values;
    }

    /**
     * 校验结账支付方式（支持支付宝扫码别名）
     *
     * @param string $payment_method 支付方式
     * @return string
     */
    private function normalize_checkout_payment_method($payment_method) {
        $payment_method = sanitize_key((string) $payment_method);

        if ($payment_method === 'wechat_miniapp') {
            return '';
        }

        if ($payment_method === 'alipay_page') {
            $payment_method = 'alipay';
        }

        $allowed = [];
        if (get_option('qls_shop_points_enabled', true)) {
            $allowed[] = 'points';
        }
        if (class_exists('QilingShop_Payment')) {
            $enabled = array_keys(QilingShop_Payment::instance()->get_enabled_gateways());
            foreach ($enabled as $gateway) {
                $gateway = sanitize_key((string) $gateway);
                if ($gateway !== 'wechat_miniapp') {
                    $allowed[] = $gateway;
                }
            }
            if (get_option('qilingshop_alipay_enabled') && get_option('qilingshop_alipay_f2fpay')) {
                $allowed[] = 'alipay_qr';
            }
        }

        $allowed = array_values(array_unique($allowed));
        return in_array($payment_method, $allowed, true) ? $payment_method : '';
    }

    /**
     * 构建商城结账支付链接（优先直接拉起网关）
     *
     * @param object $order 订单对象（建议包含 items）
     * @return array{success:bool,payment_url:string,message:string}
     */
    private function create_checkout_payment_url($order) {
        if (!$order || empty($order->order_no)) {
            return [
                'success' => false,
                'payment_url' => '',
                'message' => __('订单不存在', 'qilingshop'),
            ];
        }

        if (!class_exists('QilingShop_Payment')) {
            return [
                'success' => false,
                'payment_url' => '',
                'message' => __('支付服务不可用', 'qilingshop'),
            ];
        }

        $gateway = sanitize_key((string) ($order->payment_method ?? ''));

        // 扫码支付：直接进入插件扫码页，避免中间链路导致仅下单不拉起支付
        if ($gateway === 'alipay_qr') {
            return [
                'success' => true,
                'payment_url' => esc_url_raw(qilingshop_get_payment_entry_url('alipay', [
                    'order'  => (string) $order->order_no,
                    'method' => 'f2f',
                ])),
                'message' => '',
            ];
        }
        if ($gateway === 'wechat') {
            return [
                'success' => true,
                'payment_url' => esc_url_raw(qilingshop_get_payment_entry_url('wechat', [
                    'order' => (string) $order->order_no,
                ])),
                'message' => '',
            ];
        }

        $extra = [];

        if ($gateway === 'alipay') {
            $extra['method'] = 'page';
        }

        $subject = get_option('qilingshop_fixed_order_title');
        if (empty($subject)) {
            if (!empty($order->items) && !empty($order->items[0]->product_title)) {
                $subject = (string) $order->items[0]->product_title;
                if (count($order->items) > 1) {
                    $subject .= ' ' . sprintf(__('等%d件商品', 'qilingshop'), count($order->items));
                }
            } else {
                $subject = get_bloginfo('name') . ' - ' . __('商城订单', 'qilingshop');
            }
        }
        $extra['subject'] = $subject;

        $order_type = !empty($order->is_group_order) ? 'group' : 'shop';
        $payment = QilingShop_Payment::instance()->create_payment(
            $order_type,
            (string) $order->order_no,
            (float) $order->final_amount,
            $gateway,
            $extra
        );

        if (!empty($payment['success']) && !empty($payment['pay_url'])) {
            return [
                'success' => true,
                'payment_url' => esc_url_raw((string) $payment['pay_url']),
                'message' => '',
            ];
        }

        return [
            'success' => false,
            'payment_url' => '',
            'message' => isset($payment['message']) ? (string) $payment['message'] : __('支付初始化失败', 'qilingshop'),
        ];
    }

    /**
     * 添加到购物车
     */
    public function add_to_cart() {
        $this->verify_nonce();

        $product_id = intval($_POST['product_id'] ?? 0);
        $sku_id = intval($_POST['sku_id'] ?? 0);
        $quantity = intval($_POST['quantity'] ?? 1);

        if (!$product_id || !$sku_id || $quantity < 1) {
            wp_send_json_error(['message' => __('参数错误', 'qilingshop')]);
        }

        $result = qls_cart()->add($product_id, $sku_id, $quantity);

        if ($result['success']) {
            $result['cart_count'] = qls_cart()->get_count();
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * 更新购物车
     */
    public function update_cart() {
        $this->verify_nonce();

        $item_id = intval($_POST['item_id'] ?? 0);
        $quantity = intval($_POST['quantity'] ?? 0);

        if (!$item_id) {
            wp_send_json_error(['message' => __('参数错误', 'qilingshop')]);
        }

        $result = qls_cart()->update($item_id, $quantity);

        if ($result['success']) {
            $totals = qls_cart()->get_totals();
            $result['totals'] = $totals;
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * 从购物车移除
     */
    public function remove_from_cart() {
        $this->verify_nonce();

        $item_id = intval($_POST['item_id'] ?? 0);

        if (!$item_id) {
            wp_send_json_error(['message' => __('参数错误', 'qilingshop')]);
        }

        $result = qls_cart()->remove($item_id);

        if ($result['success']) {
            $totals = qls_cart()->get_totals();
            $result['totals'] = $totals;
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * 获取购物车
     */
    public function get_cart() {
        $this->verify_nonce();

        $items = qls_cart()->get_items();
        $totals = qls_cart()->get_totals();

        wp_send_json_success([
            'items'  => $items,
            'totals' => $totals,
        ]);
    }

    /**
     * 获取购物车数量
     */
    public function get_cart_count() {
        $this->verify_nonce();
        wp_send_json_success([
            'count' => qls_cart()->get_count(),
        ]);
    }

    /**
     * 结账
     */
    public function checkout() {
        $this->verify_nonce();

        // 后端强制校验游客下单开关，避免仅靠前端页面拦截被绕过
        $guest_order_enabled = function_exists('qls_shop_is_guest_order_enabled')
            ? qls_shop_is_guest_order_enabled()
            : (bool) get_option('qls_shop_cart_guest_enabled', true);

        if (!is_user_logged_in() && !$guest_order_enabled) {
            wp_send_json_error(['message' => __('请先登录后继续结账', 'qilingshop')]);
        }

        $this->guard_order_write_request('checkout', [], 6, 60);

        // 检测购物车是否为纯虚拟商品
        $cart = qls_cart();
        $items = $cart->get_items();
        $is_virtual_only = true;
        $has_virtual_card_item = false;
        
        foreach ($items as $item) {
            $product_id = (int) ($item->product_id ?? 0);
            $product = isset($item->product) && is_object($item->product) ? $item->product : ($product_id ? qls_product()->get($product_id) : null);

            if (!$product || !qls_product()->is_virtual($product)) {
                $is_virtual_only = false;
                break;
            }

            if (sanitize_key((string) ($product->virtual_type ?? '')) === 'card') {
                $has_virtual_card_item = true;
            }
        }

        // 获取结账数据 - 根据是否虚拟订单读取不同字段
        if ($is_virtual_only) {
            // 虚拟订单：使用联系人信息字段
            $contact_name = sanitize_text_field(wp_unslash($_POST['receiver_name'] ?? ''));
            $contact_phone = sanitize_text_field(wp_unslash($_POST['receiver_phone'] ?? ''));
            $contact_email = sanitize_email(wp_unslash($_POST['receiver_email'] ?? ''));
            $guest_query_password = trim(sanitize_text_field(wp_unslash($_POST['guest_query_password'] ?? '')));
            $guest_query_password_required = !is_user_logged_in()
                && $has_virtual_card_item
                && (bool) get_option('qls_shop_guest_query_password_enabled', false);
            
            $checkout_data = [
                'receiver_name'     => $contact_name,
                'receiver_phone'    => $contact_phone,
                'receiver_email'    => $contact_email,
                'receiver_province' => '',
                'receiver_city'     => '',
                'receiver_district' => '',
                'receiver_address'  => $contact_email ? sprintf(__('邮箱：%s', 'qilingshop'), $contact_email) : __('虚拟商品', 'qilingshop'),
                'buyer_remark'      => sanitize_textarea_field(wp_unslash($_POST['buyer_remark'] ?? '')),
                'points_used'       => intval($_POST['points_used'] ?? 0),
                'coupon_claim_id'   => intval($_POST['coupon_claim_id'] ?? 0),
                'payment_method'    => sanitize_text_field(wp_unslash($_POST['payment_method'] ?? '')),
                'is_virtual_only'   => true,
            ];

            if ($guest_query_password_required) {
                $password_length = function_exists('mb_strlen') ? mb_strlen($guest_query_password) : strlen($guest_query_password);
                if ($password_length < 4 || $password_length > 64) {
                    wp_send_json_error(['message' => __('请设置4-64位订单查询密码', 'qilingshop')]);
                }
                $checkout_data['guest_query_password'] = $guest_query_password;
            }
            
            // 验证虚拟订单联系信息
            if (empty($contact_name)) {
                wp_send_json_error(['message' => __('请填写联系人姓名', 'qilingshop')]);
            }
            if (empty($contact_phone) && empty($contact_email)) {
                wp_send_json_error(['message' => __('请填写手机号码或邮箱地址', 'qilingshop')]);
            }
        } else {
            // 实物订单：使用完整收货地址
            $checkout_data = [
                'receiver_name'     => sanitize_text_field(wp_unslash($_POST['receiver_name'] ?? '')),
                'receiver_phone'    => sanitize_text_field(wp_unslash($_POST['receiver_phone'] ?? '')),
                'receiver_province' => sanitize_text_field(wp_unslash($_POST['receiver_province'] ?? '')),
                'receiver_city'     => sanitize_text_field(wp_unslash($_POST['receiver_city'] ?? '')),
                'receiver_district' => sanitize_text_field(wp_unslash($_POST['receiver_district'] ?? '')),
                'receiver_address'  => sanitize_textarea_field(wp_unslash($_POST['receiver_address'] ?? '')),
                'buyer_remark'      => sanitize_textarea_field(wp_unslash($_POST['buyer_remark'] ?? '')),
                'points_used'       => intval($_POST['points_used'] ?? 0),
                'coupon_claim_id'   => intval($_POST['coupon_claim_id'] ?? 0),
                'payment_method'    => sanitize_text_field(wp_unslash($_POST['payment_method'] ?? '')),
                'is_virtual_only'   => false,
            ];

            // 验证实物订单收货信息
            // 严格验证手机号（简单的格式检查）
            if (!preg_match('/^1[3-9]\d{9}$/', $checkout_data['receiver_phone'])) {
                 wp_send_json_error(['message' => __('手机号格式不正确', 'qilingshop')]);
            }

            // 验证必填字段
            if (empty($checkout_data['receiver_name']) || empty($checkout_data['receiver_phone']) || empty($checkout_data['receiver_address'])) {
                wp_send_json_error(['message' => __('请填写完整的收货信息', 'qilingshop')]);
            }
        }

        // 验证支付方式
        $checkout_data['payment_method'] = $this->normalize_checkout_payment_method($checkout_data['payment_method'] ?? '');
        if ($checkout_data['payment_method'] === '') {
            wp_send_json_error(['message' => __('请选择可用的支付方式', 'qilingshop')]);
        }

        // 积分支付：强制后端按SKU积分价计算，忽略前端传值，避免被篡改
        $is_points_payment = ($checkout_data['payment_method'] === 'points');
        if ($is_points_payment) {
            if (!is_user_logged_in()) {
                wp_send_json_error(['message' => __('请先登录后使用积分支付', 'qilingshop')]);
            }
            if (!get_option('qls_shop_points_enabled', true)) {
                wp_send_json_error(['message' => __('积分支付未开启', 'qilingshop')]);
            }

            $total_points_amount = 0;
            foreach ($items as $item) {
                $points_price = isset($item->sku->points_price) ? (float) $item->sku->points_price : 0;
                if ($points_price <= 0) {
                    wp_send_json_error(['message' => __('当前商品不支持积分支付', 'qilingshop')]);
                }
                $total_points_amount += $points_price * (int) $item->quantity;
            }
            $checkout_data['points_used'] = max(0, round($total_points_amount, 2));
        } else {
            // 实物商城现金支付不走“积分抵扣+现金混合支付”，统一按纯现金网关处理
            $checkout_data['points_used'] = 0;
        }

        // 验证积分余额
        if ($checkout_data['points_used'] > 0) {
            if (!is_user_logged_in()) {
                wp_send_json_error(['message' => __('请先登录后使用积分', 'qilingshop')]);
            }
            if (!get_option('qls_shop_points_enabled', true)) {
                wp_send_json_error(['message' => __('积分支付未开启', 'qilingshop')]);
            }
            $balance = QilingShop_Points::instance()->get_balance(get_current_user_id());
            if ($checkout_data['points_used'] > $balance) {
                wp_send_json_error(['message' => __('积分不足', 'qilingshop')]);
            }
        }

        // 处理保存地址
        if (isset($_POST['save_address']) && $_POST['save_address'] == 1 && is_user_logged_in()) {
            $address_data = [
                'user_id'   => get_current_user_id(),
                'name'      => $checkout_data['receiver_name'],
                'phone'     => $checkout_data['receiver_phone'],
                'province'  => $checkout_data['receiver_province'],
                'city'      => $checkout_data['receiver_city'],
                'district'  => $checkout_data['receiver_district'],
                'address'   => $checkout_data['receiver_address'],
                'is_default'=> 1, // 总是设为默认
            ];
            
            $db = QLS_Shop_Database::instance();
            $lock_name = $this->build_user_address_lock_name((int) get_current_user_id());

            if (!$this->acquire_named_lock($lock_name, 5)) {
                wp_send_json_error(['message' => __('地址保存处理中，请稍后重试', 'qilingshop')]);
            }

            try {
                $wpdb = $db->get_wpdb();
                $wpdb->query('START TRANSACTION');

                if (!empty($address_data['is_default'])) {
                    $reset_default = $db->update('user_addresses', ['is_default' => 0], ['user_id' => get_current_user_id()]);
                    if ($reset_default === false) {
                        throw new Exception(__('地址保存失败，请稍后重试', 'qilingshop'));
                    }
                }

                $saved_address_id = $db->insert('user_addresses', $address_data);
                if (!$saved_address_id) {
                    throw new Exception(__('地址保存失败，请稍后重试', 'qilingshop'));
                }

                $wpdb->query('COMMIT');
            } catch (Exception $e) {
                $db->get_wpdb()->query('ROLLBACK');
                wp_send_json_error(['message' => $e->getMessage() ?: __('地址保存失败，请稍后重试', 'qilingshop')]);
            } finally {
                $this->release_named_lock($lock_name);
            }
        }

        // 创建订单
        $result = qls_shop_order()->create_from_cart($checkout_data);

        if ($result['success']) {
            $order = qls_shop_order()->get_by_order_no($result['order_no'], true);
            if (!$order) {
                wp_send_json_error(['message' => __('订单创建成功，但读取订单失败', 'qilingshop')]);
            }
            
            // 0 元订单（积分全额支付 / 优惠券全额抵扣）直接完成并跳转到订单中心
            if ((float) $order->final_amount <= 0) {
                $paid = qls_shop_order()->mark_paid($result['order_no'], '', 'free');
                if (!$paid) {
                    wp_send_json_error(['message' => __('订单自动完成失败，请稍后重试', 'qilingshop')]);
                }
                // 跳转到我的订单中心
                $orders_page_id = get_option('qls_shop_page_orders', 0);
                if ($orders_page_id) {
                    $result['redirect'] = get_permalink($orders_page_id);
                } else {
                    // 备用：跳转到商城首页
                    $result['redirect'] = qls_shop_public()->get_page_url('shop');
                }
                $result['paid'] = true;
            } else {
                // 现金网关：结账页提交后直接拉起支付，不再仅提交到订单中心
                $result['order'] = $order;
                $payment_result = $this->create_checkout_payment_url($order);
                if (!empty($payment_result['success']) && !empty($payment_result['payment_url'])) {
                    $result['payment_url'] = $payment_result['payment_url'];
                } else {
                    $result['payment_url'] = add_query_arg([
                        'pay'   => 'shop',
                        'order' => $result['order_no'],
                    ], home_url('/'));
                    if (!empty($payment_result['message'])) {
                        $result['payment_init_message'] = $payment_result['message'];
                    }
                }
                // 兼容旧前端（先读 redirect 再读 payment_url）：现金网关统一跳支付链接
                if (!empty($result['payment_url'])) {
                    $result['redirect'] = $result['payment_url'];
                }
            }
            
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * 计算运费
     */
    public function calculate_shipping() {
        $this->verify_nonce();

        $items = qls_cart()->get_items();
        $shipping_data = $this->get_sanitized_shipping_request_data();
        $shipping_fee = qls_shipping()->calculate($items, $shipping_data);

        wp_send_json_success([
            'shipping_fee' => $shipping_fee,
        ]);
    }

    /**
     * 保存地址
     */
    public function save_address() {
        $this->verify_nonce();

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('请先登录', 'qilingshop')]);
        }

        $db = QLS_Shop_Database::instance();
        $user_id = get_current_user_id();
        $lock_name = $this->build_user_address_lock_name($user_id);

        if (!$this->acquire_named_lock($lock_name, 5)) {
            wp_send_json_error(['message' => __('地址保存处理中，请勿重复提交', 'qilingshop')]);
        }

        try {
            $wpdb = $db->get_wpdb();
            $wpdb->query('START TRANSACTION');
            $data = [
                'user_id'  => $user_id,
                'name'     => sanitize_text_field($_POST['name'] ?? ''),
                'phone'    => sanitize_text_field($_POST['phone'] ?? ''),
                'province' => sanitize_text_field($_POST['province'] ?? ''),
                'city'     => sanitize_text_field($_POST['city'] ?? ''),
                'district' => sanitize_text_field($_POST['district'] ?? ''),
                'address'  => sanitize_text_field($_POST['address'] ?? ''),
                'is_default' => intval($_POST['is_default'] ?? 0),
            ];

            // 如果设为默认，取消其他默认
            if ($data['is_default']) {
                $reset_default = $db->update('user_addresses', ['is_default' => 0], ['user_id' => $user_id]);
                if ($reset_default === false) {
                    throw new Exception(__('保存失败，请稍后重试', 'qilingshop'));
                }
            }

            $address_id = intval($_POST['address_id'] ?? 0);

            if ($address_id > 0) {
                $existing = $db->get_row('user_addresses', ['id' => $address_id, 'user_id' => $user_id]);
                if (!$existing) {
                    throw new Exception(__('地址不存在', 'qilingshop'));
                }

                $updated = $db->update('user_addresses', $data, ['id' => $address_id, 'user_id' => $user_id]);
                if ($updated === false) {
                    throw new Exception(__('保存失败，请稍后重试', 'qilingshop'));
                }
            } else {
                $count = $db->count('user_addresses', ['user_id' => $user_id]);
                if ((int) $count === 0) {
                    $data['is_default'] = 1;
                }
                $address_id = $db->insert('user_addresses', $data);
                if (!$address_id) {
                    throw new Exception(__('保存失败，请稍后重试', 'qilingshop'));
                }
            }
            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $db->get_wpdb()->query('ROLLBACK');
            wp_send_json_error(['message' => $e->getMessage() ?: __('保存失败，请稍后重试', 'qilingshop')]);
        } finally {
            $this->release_named_lock($lock_name);
        }

        wp_send_json_success([
            'message'    => __('保存成功', 'qilingshop'),
            'address_id' => $address_id,
        ]);
    }

    /**
     * 获取地址列表
     */
    public function get_addresses() {
        $this->verify_nonce();
        
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('请先登录', 'qilingshop')]);
        }

        $db = QLS_Shop_Database::instance();
        $addresses = $db->get_results('user_addresses', [
            'where'   => ['user_id' => get_current_user_id()],
            'orderby' => 'is_default',
            'order'   => 'DESC',
        ]);

        wp_send_json_success(['addresses' => $addresses]);
    }

    /**
     * 删除地址
     */
    public function delete_address() {
        $this->verify_nonce();

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('请先登录', 'qilingshop')]);
        }

        $address_id = intval($_POST['address_id'] ?? 0);

        if (!$address_id) {
            wp_send_json_error(['message' => __('参数错误', 'qilingshop')]);
        }

        $db = QLS_Shop_Database::instance();
        $lock_name = $this->build_user_address_lock_name((int) get_current_user_id());
        if (!$this->acquire_named_lock($lock_name, 5)) {
            wp_send_json_error(['message' => __('地址处理中，请勿重复提交', 'qilingshop')]);
        }

        try {
            $deleted = $db->delete('user_addresses', ['id' => $address_id, 'user_id' => get_current_user_id()]);
            if ($deleted === false || (int) $deleted < 1) {
                wp_send_json_error(['message' => __('删除失败，请稍后重试', 'qilingshop')]);
            }
        } finally {
            $this->release_named_lock($lock_name);
        }

        wp_send_json_success(['message' => __('删除成功', 'qilingshop')]);
    }

    /**
     * 获取SKU信息
     */
    public function get_sku() {
        $this->guard_public_read_request('get_sku', 120, 60);

        $product_id = absint($this->get_post_scalar('product_id', 0));
        $attr_values = $this->sanitize_sku_attr_values($_POST['attr_values'] ?? []);

        if (!$product_id) {
            wp_send_json_error(['message' => __('参数错误', 'qilingshop')]);
        }

        // 根据规格值匹配SKU
        $product = qls_product()->get($product_id);
        $current_user_id = is_user_logged_in() ? (int) get_current_user_id() : 0;
        $skus = qls_product()->get_skus($product_id);
        $matched_sku = null;
        $partial_matches = []; // 部分匹配的SKU列表

        foreach ($skus as $sku) {
            $sku_attrs = $sku->attr_values ?: [];
            
            if (empty($attr_values) && $sku->is_default) {
                $matched_sku = $sku;
                break;
            }

            if (empty($attr_values)) {
                // 如果没有提供规格值，且不是默认SKU，则将其加入部分匹配以计算总区间
                if (!$sku->is_default) {
                    $partial_matches[] = $sku;
                }
                continue;
            }

            // 检查是否所有提交的属性都匹配
            $partial_match = true;
            foreach ($attr_values as $name => $value) {
                if (!isset($sku_attrs[$name]) || $sku_attrs[$name] != $value) {
                    $partial_match = false;
                    break;
                }
            }

            if ($partial_match) {
                // 完全匹配（属性数量相同）
                if (count($sku_attrs) === count($attr_values)) {
                    $matched_sku = $sku;
                    break;
                }
                // 部分匹配（用户选择的属性都匹配，但SKU有更多属性）
                $partial_matches[] = $sku;
            }
        }

        if ($matched_sku) {
            $effective_price = qls_product()->get_effective_sku_price($product ?: $product_id, $matched_sku, $current_user_id);
            $price_hidden = !is_user_logged_in() && get_option('qls_shop_price_login_required', false);

            // 精确匹配 - 返回单个SKU价格
            wp_send_json_success([
                'sku' => [
                    'id'           => $matched_sku->id,
                    'sku_code'     => $matched_sku->sku_code,
                    'price'        => $price_hidden ? 0 : $matched_sku->price,
                    'sale_price'   => $price_hidden ? 0 : $matched_sku->sale_price,
                    'base_price'   => $price_hidden ? 0 : (isset($effective_price['base_price']) ? (float) $effective_price['base_price'] : (float) $matched_sku->price),
                    'effective_price' => $price_hidden ? 0 : (isset($effective_price['price']) ? (float) $effective_price['price'] : (float) $matched_sku->price),
                    'is_vip_price' => !$price_hidden && !empty($effective_price['is_vip_price']),
                    'vip_price'    => $price_hidden ? 0 : (isset($effective_price['vip_price']) ? (float) $effective_price['vip_price'] : 0),
                    'vip_level_id' => isset($effective_price['vip_level_id']) ? (int) $effective_price['vip_level_id'] : 0,
                    'price_source' => isset($effective_price['price_source']) ? (string) $effective_price['price_source'] : 'base',
                    'points_price' => $price_hidden ? 0 : $matched_sku->points_price,
                    'stock'        => $matched_sku->stock,
                    'image'        => $matched_sku->image,
                ],
            ]);
        } elseif (!empty($partial_matches)) {
            // 部分匹配 - 返回价格区间
            $price_hidden = !is_user_logged_in() && get_option('qls_shop_price_login_required', false);
            $min_price = PHP_FLOAT_MAX;
            $max_price = 0;
            $total_stock = 0;
            $has_sale = false;
            $has_vip_price = false;

            foreach ($partial_matches as $sku) {
                $effective_price = qls_product()->get_effective_sku_price($product ?: $product_id, $sku, $current_user_id);
                $actual_price = isset($effective_price['price'])
                    ? (float) $effective_price['price']
                    : (($sku->sale_price > 0 && $sku->sale_price < $sku->price) ? $sku->sale_price : $sku->price);
                
                if ($actual_price < $min_price) $min_price = $actual_price;
                if ($actual_price > $max_price) $max_price = $actual_price;
                
                $total_stock += $sku->stock;
                
                if ($sku->sale_price > 0 && $sku->sale_price < $sku->price) {
                    $has_sale = true;
                }
                if (!empty($effective_price['is_vip_price'])) {
                    $has_vip_price = true;
                }
            }

            wp_send_json_success([
                'price_range' => [
                    'min_price'   => $price_hidden ? 0 : $min_price,
                    'max_price'   => $price_hidden ? 0 : $max_price,
                    'is_range'    => ($min_price != $max_price),
                    'has_sale'    => $has_sale,
                    'has_vip_price' => $has_vip_price,
                    'total_stock' => $total_stock,
                ],
            ]);
        } else {
            wp_send_json_error(['message' => __('规格不存在', 'qilingshop')]);
        }
    }

    /**
     * 取消订单
     */
    public function cancel_order() {
        $this->verify_nonce();
        
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('请先登录', 'qilingshop')]);
        }

        $order_id = intval($_POST['order_id'] ?? 0);
        if (!$order_id) {
            wp_send_json_error(['message' => __('参数错误', 'qilingshop')]);
        }

        $order = qls_shop_order()->get($order_id);
        if (!$order || $order->user_id != get_current_user_id()) {
             wp_send_json_error(['message' => __('没有权限', 'qilingshop')]);
        }

        if ($order->status != QLS_Shop_Order::STATUS_PENDING) {
             wp_send_json_error(['message' => __('该订单当前无法取消', 'qilingshop')]);
        }

        if (qls_shop_order()->cancel($order_id, __('用户取消', 'qilingshop'))) {
            wp_send_json_success(['message' => __('订单已取消', 'qilingshop')]);
        } else {
            wp_send_json_error(['message' => __('取消失败', 'qilingshop')]);
        }
    }

    /**
     * 确认收货
     */
    public function confirm_receive() {
        $this->verify_nonce();
        
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('请先登录', 'qilingshop')]);
        }

        $order_no = sanitize_text_field($_POST['order_no'] ?? '');
        if (!$order_no) {
            wp_send_json_error(['message' => __('参数错误', 'qilingshop')]);
        }

        $order = qls_shop_order()->get_by_order_no($order_no);
        if (!$order || $order->user_id != get_current_user_id()) {
             wp_send_json_error(['message' => __('没有权限', 'qilingshop')]);
        }

        if ($order->status != QLS_Shop_Order::STATUS_SHIPPED) {
             wp_send_json_error(['message' => __('该订单当前无法确认收货', 'qilingshop')]);
        }

        if (qls_shop_order()->complete($order->id)) {
            wp_send_json_success(['message' => __('已确认收货', 'qilingshop')]);
        } else {
            wp_send_json_error(['message' => __('操作失败', 'qilingshop')]);
        }
    }

    /**
     * 助力：创建活动实例
     */
    public function assist_create_campaign() {
        $this->verify_nonce();

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('请先登录', 'qilingshop')]);
        }

        $activity_id = intval($_POST['activity_id'] ?? 0);
        $created = qls_assist()->create_campaign($activity_id, get_current_user_id());
        if (is_wp_error($created)) {
            $extra = $created->get_error_data();
            wp_send_json_error([
                'message' => $created->get_error_message(),
                'data'    => is_array($extra) ? $extra : [],
            ]);
        }

        $detail_url = qls_shop_public()->get_page_url('assist-detail');
        if (!empty($detail_url)) {
            $detail_url = add_query_arg('share', rawurlencode((string) $created->share_code), $detail_url);
        }

        wp_send_json_success([
            'message' => __('助力活动已创建', 'qilingshop'),
            'campaign_id' => (int) $created->id,
            'share_code' => (string) $created->share_code,
            'redirect_url' => $detail_url,
        ]);
    }

    /**
     * 助力：好友帮砍
     */
    public function assist_help_campaign() {
        $this->verify_nonce();

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('请先登录', 'qilingshop')]);
        }

        $campaign_id = intval($_POST['campaign_id'] ?? 0);
        $result = qls_assist()->help_campaign($campaign_id, get_current_user_id());
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $campaign = qls_assist()->get_campaign($campaign_id, true);
        wp_send_json_success([
            'message' => __('助力成功', 'qilingshop'),
            'cut_amount' => (float) $result['cut_amount'],
            'new_price' => (float) $result['new_price'],
            'help_count' => (int) $result['help_count'],
            'status' => (int) ($campaign->status ?? $result['status']),
            'status_text' => qls_assist()->get_campaign_status_text((int) ($campaign->status ?? $result['status'])),
        ]);
    }

    /**
     * 助力：创建差额支付订单
     */
    public function assist_create_order() {
        $this->verify_nonce();

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('请先登录', 'qilingshop')]);
        }

        $campaign_id = intval($_POST['campaign_id'] ?? 0);
        $this->guard_order_write_request('assist_create_order', [], 6, 60);
        $checkout = [
            'payment_method'    => sanitize_text_field(wp_unslash($_POST['payment_method'] ?? '')),
            'receiver_name'     => sanitize_text_field(wp_unslash($_POST['receiver_name'] ?? '')),
            'receiver_phone'    => sanitize_text_field(wp_unslash($_POST['receiver_phone'] ?? '')),
            'receiver_province' => sanitize_text_field(wp_unslash($_POST['receiver_province'] ?? '')),
            'receiver_city'     => sanitize_text_field(wp_unslash($_POST['receiver_city'] ?? '')),
            'receiver_district' => sanitize_text_field(wp_unslash($_POST['receiver_district'] ?? '')),
            'receiver_address'  => sanitize_textarea_field(wp_unslash($_POST['receiver_address'] ?? '')),
            'buyer_remark'      => sanitize_textarea_field(wp_unslash($_POST['buyer_remark'] ?? '')),
        ];
        $checkout['payment_method'] = $this->normalize_checkout_payment_method($checkout['payment_method']);
        $campaign = qls_assist()->get_campaign($campaign_id, true);
        $requires_payment = !$campaign || (float) ($campaign->current_price ?? 0) > 0;
        if ($requires_payment && $checkout['payment_method'] === '') {
            wp_send_json_error(['message' => __('请选择可用的支付方式', 'qilingshop')]);
        }

        $result = qls_assist()->create_campaign_order($campaign_id, get_current_user_id(), $checkout);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => !empty($result['reused']) ? __('已存在待支付订单', 'qilingshop') : __('支付订单创建成功', 'qilingshop'),
            'order_id' => (int) $result['order_id'],
            'order_no' => (string) $result['order_no'],
            'payment_url' => (string) $result['payment_url'],
            'paid' => !empty($result['paid']),
            'reused' => !empty($result['reused']),
        ]);
    }

    /**
     * 申请退款
     */
    public function apply_refund() {
        $this->verify_nonce();

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('请先登录', 'qilingshop')]);
        }

        $order_id = intval($_POST['order_id'] ?? 0);
        $reason = sanitize_textarea_field(wp_unslash($_POST['reason'] ?? ''));
        $images = isset($_POST['images']) && is_array($_POST['images']) ? array_map('esc_url_raw', wp_unslash($_POST['images'])) : [];

        if (!$order_id) {
            wp_send_json_error(['message' => __('参数错误', 'qilingshop')]);
        }

        if (!class_exists('QLS_Shop_Refund')) {
            require_once QILINGSHOP_PATH . 'includes/shop/class-qls-shop-refund.php';
        }

        $result = qls_shop_refund()->request_refund($order_id, get_current_user_id(), $reason, $images);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'refund_id' => (int) $result,
            'message' => __('退款申请已提交', 'qilingshop'),
        ]);
    }

    /**
     * 撤销退款申请
     */
    public function cancel_refund() {
        $this->verify_nonce();

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('请先登录', 'qilingshop')]);
        }

        $refund_id = intval($_POST['refund_id'] ?? 0);
        if (!$refund_id) {
            wp_send_json_error(['message' => __('参数错误', 'qilingshop')]);
        }

        if (!class_exists('QLS_Shop_Refund')) {
            require_once QILINGSHOP_PATH . 'includes/shop/class-qls-shop-refund.php';
        }

        $result = qls_shop_refund()->cancel_refund($refund_id, get_current_user_id());
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => __('已撤销退款申请', 'qilingshop')]);
    }

    /**
     * 申请订单发票。
     */
    public function apply_invoice() {
        $this->verify_nonce();

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('请先登录', 'qilingshop')]);
        }

        if (!get_option('qls_shop_invoice_enabled', true)) {
            wp_send_json_error(['message' => __('发票功能暂未开启', 'qilingshop')]);
        }

        $order_id = intval($_POST['order_id'] ?? 0);
        if (!$order_id || !function_exists('qls_invoice')) {
            wp_send_json_error(['message' => __('参数错误', 'qilingshop')]);
        }

        $this->guard_order_write_request('invoice', ['order_id' => $order_id], 6, 60);

        $data = [
            'invoice_type' => sanitize_key(wp_unslash($_POST['invoice_type'] ?? 'electronic')),
            'title_type'   => sanitize_key(wp_unslash($_POST['title_type'] ?? 'personal')),
            'title'        => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
            'tax_no'       => sanitize_text_field(wp_unslash($_POST['tax_no'] ?? '')),
            'email'        => sanitize_email(wp_unslash($_POST['email'] ?? '')),
            'phone'        => sanitize_text_field(wp_unslash($_POST['phone'] ?? '')),
        ];

        $invoice_id = qls_invoice()->request($order_id, get_current_user_id(), $data);
        if (is_wp_error($invoice_id)) {
            wp_send_json_error(['message' => $invoice_id->get_error_message()]);
        }

        wp_send_json_success([
            'invoice_id' => (int) $invoice_id,
            'message'    => __('发票申请已提交', 'qilingshop'),
        ]);
    }

    /**
     * 撤销订单发票申请。
     */
    public function cancel_invoice() {
        $this->verify_nonce();

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('请先登录', 'qilingshop')]);
        }

        $invoice_id = intval($_POST['invoice_id'] ?? 0);
        if (!$invoice_id || !function_exists('qls_invoice')) {
            wp_send_json_error(['message' => __('参数错误', 'qilingshop')]);
        }

        $this->guard_order_write_request('invoice_cancel', ['invoice_id' => $invoice_id], 8, 60);

        $result = qls_invoice()->cancel($invoice_id, get_current_user_id());
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => __('已撤销发票申请', 'qilingshop')]);
    }

    /**
     * 保存常用发票抬头。
     */
    public function save_invoice_title() {
        $this->verify_nonce();

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('请先登录', 'qilingshop')]);
        }

        if (!get_option('qls_shop_invoice_enabled', true)) {
            wp_send_json_error(['message' => __('发票功能暂未开启', 'qilingshop')]);
        }

        if (!function_exists('qls_invoice')) {
            wp_send_json_error(['message' => __('参数错误', 'qilingshop')]);
        }

        $data = [
            'id'                 => intval($this->get_post_scalar('title_id', 0)),
            'title_type'         => sanitize_key((string) $this->get_post_scalar('title_type', 'personal')),
            'title'              => sanitize_text_field((string) $this->get_post_scalar('title', '')),
            'tax_no'             => sanitize_text_field((string) $this->get_post_scalar('tax_no', '')),
            'bank_name'          => sanitize_text_field((string) $this->get_post_scalar('bank_name', '')),
            'bank_account'       => sanitize_text_field((string) $this->get_post_scalar('bank_account', '')),
            'registered_address' => sanitize_text_field((string) $this->get_post_scalar('registered_address', '')),
            'registered_phone'   => sanitize_text_field((string) $this->get_post_scalar('registered_phone', '')),
            'email'              => sanitize_email((string) $this->get_post_scalar('email', '')),
            'is_default'         => $this->get_post_scalar('is_default', '') !== '' ? 1 : 0,
        ];

        $title_id = qls_invoice()->save_title(get_current_user_id(), $data);
        if (is_wp_error($title_id)) {
            wp_send_json_error(['message' => $title_id->get_error_message()]);
        }

        wp_send_json_success([
            'message'  => __('发票信息已保存', 'qilingshop'),
            'title_id' => (int) $title_id,
        ]);
    }

    /**
     * 删除常用发票抬头。
     */
    public function delete_invoice_title() {
        $this->verify_nonce();

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('请先登录', 'qilingshop')]);
        }

        if (!get_option('qls_shop_invoice_enabled', true)) {
            wp_send_json_error(['message' => __('发票功能暂未开启', 'qilingshop')]);
        }

        $title_id = intval($this->get_post_scalar('title_id', 0));
        if (!$title_id || !function_exists('qls_invoice')) {
            wp_send_json_error(['message' => __('参数错误', 'qilingshop')]);
        }

        $result = qls_invoice()->delete_title($title_id, get_current_user_id());
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => __('发票信息已删除', 'qilingshop')]);
    }

    /**
     * 设置默认常用发票抬头。
     */
    public function set_default_invoice_title() {
        $this->verify_nonce();

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('请先登录', 'qilingshop')]);
        }

        if (!get_option('qls_shop_invoice_enabled', true)) {
            wp_send_json_error(['message' => __('发票功能暂未开启', 'qilingshop')]);
        }

        $title_id = intval($this->get_post_scalar('title_id', 0));
        if (!$title_id || !function_exists('qls_invoice')) {
            wp_send_json_error(['message' => __('参数错误', 'qilingshop')]);
        }

        $result = qls_invoice()->set_default_title($title_id, get_current_user_id());
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => __('默认发票信息已更新', 'qilingshop')]);
    }

    /**
     * 提交退货物流。
     */
    public function submit_refund_return() {
        $this->verify_nonce();

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('请先登录', 'qilingshop')]);
        }

        $refund_id = intval($_POST['refund_id'] ?? 0);
        $shipping_company = sanitize_text_field(wp_unslash($_POST['shipping_company'] ?? ''));
        $tracking_no = sanitize_text_field(wp_unslash($_POST['tracking_no'] ?? ''));

        if (!$refund_id) {
            wp_send_json_error(['message' => __('参数错误', 'qilingshop')]);
        }

        if (!class_exists('QLS_Shop_Refund')) {
            require_once QILINGSHOP_PATH . 'includes/shop/class-qls-shop-refund.php';
        }

        $result = qls_shop_refund()->submit_return_logistics($refund_id, get_current_user_id(), $shipping_company, $tracking_no);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => __('退货物流已提交', 'qilingshop')]);
    }

    /**
     * 上传售后凭证图片。
     */
    public function upload_refund_image() {
        $this->verify_nonce();

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('请先登录', 'qilingshop')]);
        }

        $order_id_raw = $_POST['order_id'] ?? 0;
        $order_id = is_scalar($order_id_raw) ? absint(wp_unslash($order_id_raw)) : 0;
        $user_id = get_current_user_id();
        $order = $this->validate_refund_image_upload_order($order_id, $user_id);
        if (is_wp_error($order)) {
            wp_send_json_error(['message' => $order->get_error_message()]);
        }

        if (empty($_FILES['image'])) {
            wp_send_json_error(['message' => __('请选择图片', 'qilingshop')]);
        }

        $file = $_FILES['image'];
        if (!is_array($file) || empty($file['tmp_name']) || is_array($file['tmp_name'])) {
            wp_send_json_error(['message' => __('请选择图片', 'qilingshop')]);
        }

        if (!empty($file['error'])) {
            wp_send_json_error(['message' => __('图片上传失败，请重试', 'qilingshop')]);
        }

        $file_size = isset($file['size']) ? (int) $file['size'] : 0;
        if ($file_size <= 0) {
            wp_send_json_error(['message' => __('无效的图片文件', 'qilingshop')]);
        }

        $upload_count_key = $this->get_refund_image_upload_count_key($order_id, $user_id);
        $uploaded_count = (int) get_transient($upload_count_key);
        if ($uploaded_count >= 6) {
            wp_send_json_error(['message' => __('每个订单最多上传6张售后凭证', 'qilingshop')]);
        }

        $max_size = 2 * 1024 * 1024;
        if ($file_size > $max_size) {
            wp_send_json_error(['message' => __('图片大小不能超过 2MB', 'qilingshop')]);
        }

        $allowed_types = ['image/jpeg', 'image/png'];
        $allowed_extensions = ['jpg', 'jpeg', 'png'];

        $real_mime = '';
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $real_mime = $finfo->file($file['tmp_name']);
        } elseif (function_exists('mime_content_type')) {
            $real_mime = mime_content_type($file['tmp_name']);
        }

        if ($real_mime && !in_array($real_mime, $allowed_types, true)) {
            wp_send_json_error(['message' => __('只支持 JPG、PNG 格式图片', 'qilingshop')]);
        }

        $file_name = isset($file['name']) && is_scalar($file['name']) ? sanitize_file_name((string) $file['name']) : '';
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_extensions, true)) {
            wp_send_json_error(['message' => __('文件扩展名不合法', 'qilingshop')]);
        }

        $image_info = @getimagesize($file['tmp_name']);
        if ($image_info === false || !in_array($image_info['mime'], $allowed_types, true)) {
            wp_send_json_error(['message' => __('无效的图片文件', 'qilingshop')]);
        }

        $file_type = wp_check_filetype_and_ext($file['tmp_name'], $file_name, [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
        ]);
        if (empty($file_type['ext']) || empty($file_type['type']) || !in_array($file_type['type'], $allowed_types, true)) {
            wp_send_json_error(['message' => __('只支持 JPG、PNG 格式图片', 'qilingshop')]);
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachment_id = media_handle_upload('image', 0);
        if (is_wp_error($attachment_id)) {
            wp_send_json_error(['message' => $attachment_id->get_error_message()]);
        }

        update_post_meta($attachment_id, '_qls_refund_order_id', $order_id);
        update_post_meta($attachment_id, '_qls_refund_user_id', $user_id);
        set_transient($upload_count_key, $uploaded_count + 1, DAY_IN_SECONDS);

        wp_send_json_success([
            'url'           => wp_get_attachment_url($attachment_id),
            'attachment_id' => (int) $attachment_id,
        ]);
    }

    /**
     * 校验售后凭证上传所属订单。
     *
     * @param int $order_id 订单 ID。
     * @param int $user_id 用户 ID。
     * @return object|WP_Error
     */
    private function validate_refund_image_upload_order($order_id, $user_id) {
        $order_id = absint($order_id);
        $user_id = absint($user_id);
        if (!$order_id || !$user_id) {
            return new WP_Error('invalid_order', __('请先选择售后订单', 'qilingshop'));
        }

        if (!class_exists('QLS_Shop_Refund')) {
            require_once QILINGSHOP_PATH . 'includes/shop/class-qls-shop-refund.php';
        }

        $order = qls_shop_order()->get($order_id);
        if (!$order) {
            return new WP_Error('order_not_found', __('订单不存在', 'qilingshop'));
        }

        if ((int) $order->user_id !== $user_id) {
            return new WP_Error('no_permission', __('无权操作该订单', 'qilingshop'));
        }

        if (!empty($order->is_group_order)) {
            return new WP_Error('group_order_not_supported', __('团购订单暂不支持售后退款', 'qilingshop'));
        }

        $latest_refund = qls_shop_refund()->get_by_order($order_id);
        if ($latest_refund && in_array((int) $latest_refund->status, [
            QLS_Shop_Refund::STATUS_PENDING,
            QLS_Shop_Refund::STATUS_APPROVED,
            QLS_Shop_Refund::STATUS_RETURNED,
            QLS_Shop_Refund::STATUS_RECEIVED,
        ], true)) {
            return new WP_Error('active_refund_exists', __('该订单已存在售后申请', 'qilingshop'));
        }

        if (!in_array((int) $order->status, [
            QLS_Shop_Order::STATUS_PAID,
            QLS_Shop_Order::STATUS_SHIPPED,
            QLS_Shop_Order::STATUS_COMPLETED,
        ], true)) {
            return new WP_Error('invalid_order_status', __('该订单暂不能上传售后凭证', 'qilingshop'));
        }

        return $order;
    }

    /**
     * 生成售后凭证上传计数键。
     *
     * @param int $order_id 订单 ID。
     * @param int $user_id 用户 ID。
     * @return string
     */
    private function get_refund_image_upload_count_key($order_id, $user_id) {
        $latest_refund = qls_shop_refund()->get_by_order((int) $order_id);
        $refund_version = $latest_refund ? (int) $latest_refund->id : 0;

        return 'qls_refund_images_' . md5(absint($user_id) . '|' . absint($order_id) . '|' . $refund_version);
    }
}

/**
 * 初始化AJAX处理器
 */
function qls_shop_ajax() {
    return QLS_Shop_Ajax::instance();
}
