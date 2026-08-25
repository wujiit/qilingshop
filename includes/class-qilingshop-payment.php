<?php
/**
 * 支付网关管理类
 *
 * @package QilingShop
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Payment {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'register_rewrite_rules']);
        add_action('init', [$this, 'handle_callback']);
        add_filter('query_vars', [$this, 'add_query_vars']);
        add_action('template_redirect', [$this, 'handle_standard_entry'], 0);
    }

    /**
     * 注册标准支付入口 rewrite。
     *
     * Query var 入口不依赖 rewrite 刷新；rewrite 作为更干净的可选入口。
     *
     * @return void
     */
    public function register_rewrite_rules() {
        add_rewrite_rule(
            '^qilingshop-payment/paypal/return/?$',
            'index.php?qilingshop_payment=paypal_return&gateway=paypal',
            'top'
        );
        add_rewrite_rule(
            '^qilingshop-payment/([a-z0-9_-]+)/?$',
            'index.php?qilingshop_payment=entry&gateway=$matches[1]',
            'top'
        );
    }

    /**
     * 注册公开 query var。
     *
     * @param array $vars
     * @return array
     */
    public function add_query_vars($vars) {
        $vars[] = 'qilingshop_payment';
        $vars[] = 'gateway';
        return array_values(array_unique($vars));
    }

    /**
     * 获取可用的支付网关
     */
    public function get_gateways() {
        $gateways = [
            'alipay' => [
                'name' => __('支付宝', 'qilingshop'),
                'icon' => 'zfb.png',
                'enabled' => (bool) get_option('qilingshop_alipay_enabled'),
            ],
            'wechat' => [
                'name' => __('微信支付', 'qilingshop'),
                'icon' => 'wx.png',
                'enabled' => (bool) get_option('qilingshop_wechat_enabled'),
            ],
            'xhpay' => [
                'name' => __('虎皮椒 V3', 'qilingshop'),
                'icon' => 'zfb.png',
                'enabled' => (bool) get_option('qilingshop_xhpay_enabled'),
            ],
            'epay' => [
                'name' => __('易支付', 'qilingshop'),
                'icon' => 'zfb.png',
                'enabled' => (bool) get_option('qilingshop_epay_enabled'),
            ],
            'paypal' => [
                'name' => __('PayPal', 'qilingshop'),
                'icon' => 'paypal.png',
                'enabled' => (bool) get_option('qilingshop_paypal_enabled'),
            ],
            'stripe' => [
                'name' => __('Stripe', 'qilingshop'),
                'icon' => 'stripe.png',
                'enabled' => (bool) get_option('qilingshop_stripe_enabled'),
            ],
        ];

        return apply_filters('qilingshop_payment_gateways', $gateways);
    }

    /**
     * 获取启用的支付网关
     */
    public function get_enabled_gateways() {
        $gateways = $this->get_gateways();
        return array_filter($gateways, function($gateway) {
            return $gateway['enabled'];
        });
    }

    /**
     * 创建支付
     */
    public function create_payment($order_type, $order_no, $amount, $gateway, $extra = []) {
        $gateway = $this->normalize_gateway($gateway);
        if ($gateway === '') {
            return [
                'success' => false,
                'message' => __('支付方式不可用', 'qilingshop'),
            ];
        }

        $gateway_config = $this->get_gateway_config($gateway);

        if (!$gateway_config) {
            return [
                'success' => false,
                'message' => __('支付方式不可用', 'qilingshop'),
            ];
        }

        // 构建支付参数
        $subject = get_option('qilingshop_fixed_order_title');
        if (empty($subject)) {
            $site_name = get_bloginfo('name');
            $subject = $site_name . ' - ' . ($order_type === 'recharge' ? __('充值', 'qilingshop') : __('订单', 'qilingshop'));
        }

        $params = [
            'order_no'    => $order_no,
            'order_type'  => $order_type,
            'amount'      => $amount,
            'subject'     => $subject,
            'notify_url'  => $this->get_notify_url($gateway),
            'return_url'  => $this->get_return_url($order_type, $order_no),
        ];

        $params = array_merge($params, $extra);

        $payment_snapshot = $this->build_payment_amount_snapshot($gateway, $amount);
        if (!empty($payment_snapshot)) {
            $params['payment_amount_snapshot'] = $payment_snapshot;
            $this->persist_payment_amount_snapshot($order_type, $order_no, $gateway, $payment_snapshot);
        }

        // 调用具体网关
        $result = $this->call_gateway($gateway, 'create', $params);

        if ($result['success']) {
            do_action('qilingshop_payment_created', $order_no, $gateway, $amount);
        }

        return $result;
    }

    /**
     * 为外币支付网关构造下单时金额快照。
     *
     * @param string $gateway 支付网关
     * @param float  $amount  人民币金额
     * @return array
     */
    private function build_payment_amount_snapshot($gateway, $amount) {
        $gateway = $this->normalize_gateway($gateway);
        $amount = round((float) $amount, 2);
        if ($amount <= 0) {
            return [];
        }

        if ($gateway === 'paypal') {
            $rate = (float) get_option('qilingshop_paypal_rate', 7);
            if ($rate <= 0) {
                $rate = 7.0;
            }

            return [
                'gateway'                => 'paypal',
                'base_currency'          => 'CNY',
                'settlement_currency'    => 'USD',
                'rate'                   => round($rate, 6),
                'expected_paid_amount'   => round($amount / $rate, 2),
                'expected_base_amount'   => $amount,
                'snapshot_created_at'    => current_time('mysql'),
            ];
        }

        if ($gateway === 'stripe') {
            $currency = sanitize_text_field((string) get_option('qilingshop_stripe_currency', 'usd'));
            $currency = strtoupper($currency !== '' ? $currency : 'USD');
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                $currency = 'USD';
            }

            $snapshot = [
                'gateway'             => 'stripe',
                'base_currency'       => 'CNY',
                'settlement_currency' => $currency,
                'expected_base_amount' => $amount,
                'snapshot_created_at' => current_time('mysql'),
            ];

            if ($currency === 'CNY') {
                $snapshot['rate'] = 1.0;
                $snapshot['expected_paid_amount'] = $amount;
                return $snapshot;
            }

            $rate = (float) get_option('qilingshop_stripe_rate', 7);
            if ($rate <= 0) {
                $rate = 7.0;
            }

            $snapshot['rate'] = round($rate, 6);
            $snapshot['expected_paid_amount'] = round($amount / $rate, 2);

            return $snapshot;
        }

        return [];
    }

    /**
     * 持久化支付金额快照，供回调校验使用。
     *
     * @param string $order_type 订单类型
     * @param string $order_no   订单号
     * @param string $gateway    支付网关
     * @param array  $snapshot   快照数据
     * @return void
     */
    private function persist_payment_amount_snapshot($order_type, $order_no, $gateway, $snapshot) {
        global $wpdb;

        if (!$wpdb || !is_array($snapshot) || empty($snapshot)) {
            return;
        }

        $target = $this->resolve_payment_snapshot_target($order_type, $order_no);
        if (empty($target['table']) || empty($target['type']) || $order_no === '') {
            return;
        }

        if (!$this->table_has_column($target['table'], 'payment_channel_meta')) {
            return;
        }

        $existing_meta = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT payment_channel_meta FROM {$target['table']} WHERE order_no = %s LIMIT 1",
                $order_no
            )
        );

        $merged_meta = $this->merge_payment_channel_meta($existing_meta, $gateway, $snapshot);
        if (empty($merged_meta)) {
            return;
        }

        $wpdb->update(
            $target['table'],
            ['payment_channel_meta' => wp_json_encode($merged_meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            ['order_no' => $order_no],
            ['%s'],
            ['%s']
        );
    }

    /**
     * 解析支付快照需要写入的数据表。
     *
     * @param string $order_type 订单类型
     * @param string $order_no   订单号
     * @return array
     */
    private function resolve_payment_snapshot_target($order_type, $order_no) {
        global $wpdb;

        if (!$wpdb || !isset($wpdb->prefix)) {
            return [];
        }

        $order_type = sanitize_key((string) $order_type);
        $order_no = sanitize_text_field((string) $order_no);

        if ($order_no === '') {
            return [];
        }

        if ($order_type === 'recharge' || strpos($order_no, 'CZ') === 0) {
            return [
                'type'  => 'recharge',
                'table' => $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'recharge',
            ];
        }

        if (
            strpos($order_no, 'SHOP') === 0 ||
            strpos($order_no, 'TUAN') === 0 ||
            in_array($order_type, ['shop', 'group', 'group_join', 'shop_order'], true)
        ) {
            $prefix = defined('QLS_SHOP_TABLE_PREFIX') ? QLS_SHOP_TABLE_PREFIX : 'qls_shop_';
            return [
                'type'  => 'shop',
                'table' => $wpdb->prefix . $prefix . 'orders',
            ];
        }

        return [
            'type'  => 'order',
            'table' => $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'orders',
        ];
    }

    /**
     * 合并已有支付渠道元数据，避免覆盖其他链路写入的信息。
     *
     * @param mixed  $existing_meta 已有元数据
     * @param string $gateway       支付网关
     * @param array  $snapshot      金额快照
     * @return array
     */
    private function merge_payment_channel_meta($existing_meta, $gateway, $snapshot) {
        $meta = [];

        if (is_string($existing_meta) && $existing_meta !== '') {
            $decoded = json_decode($existing_meta, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        } elseif (is_array($existing_meta)) {
            $meta = $existing_meta;
        }

        $meta['amount_snapshot'] = array_merge(
            is_array($meta['amount_snapshot'] ?? null) ? $meta['amount_snapshot'] : [],
            $snapshot,
            ['gateway' => $gateway]
        );

        return $meta;
    }

    /**
     * 检查数据表字段是否存在，避免升级窗口期触发 SQL 错误。
     *
     * @param string $table  数据表名
     * @param string $column 字段名
     * @return bool
     */
    private function table_has_column($table, $column) {
        global $wpdb;
        static $cache = [];

        $table = (string) $table;
        $column = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $column);
        if ($table === '' || $column === '' || !$wpdb) {
            return false;
        }

        $cache_key = $table . ':' . $column;
        if (array_key_exists($cache_key, $cache)) {
            return $cache[$cache_key];
        }

        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
        $cache[$cache_key] = !empty($columns);

        return $cache[$cache_key];
    }

    /**
     * 获取网关配置
     */
    public function get_gateway_config($gateway) {
        $gateway = $this->normalize_gateway($gateway);
        if ($gateway === '') {
            return null;
        }

        $config = [];

        switch ($gateway) {
            case 'alipay':
                $config = [
                    'app_id'      => get_option('qilingshop_alipay_app_id'),
                    'private_key' => get_option('qilingshop_alipay_private_key'),
                    'public_key'  => get_option('qilingshop_alipay_public_key'),
                ];
                break;

            case 'wechat':
                $config = [
                    'app_id'     => get_option('qilingshop_wechat_appid'),
                    'mch_id'     => get_option('qilingshop_wechat_mchid'),
                    'api_key'    => get_option('qilingshop_wechat_key'),
                ];
                break;

            case 'xhpay':
                $config = [
                    'default_type'      => get_option('qilingshop_xhpay_default_type', 'alipay'),
                    'appid_wechat'      => get_option('qilingshop_xhpay_appid_wechat'),
                    'appsecret_wechat'  => get_option('qilingshop_xhpay_appsecret_wechat'),
                    'api_wechat'        => get_option('qilingshop_xhpay_api_wechat'),
                    'appid_alipay'      => get_option('qilingshop_xhpay_appid_alipay'),
                    'appsecret_alipay'  => get_option('qilingshop_xhpay_appsecret_alipay'),
                    'api_alipay'        => get_option('qilingshop_xhpay_api_alipay'),
                    'plugin_id'         => get_option('qilingshop_xhpay_plugin_id', 'qilingshop-xhpay3'),
                ];
                break;

            case 'epay':
                $config = [
                    'pid'          => get_option('qilingshop_epay_pid'),
                    'key'          => get_option('qilingshop_epay_key'),
                    'api_url'      => get_option('qilingshop_epay_api_url'),
                    'default_type' => get_option('qilingshop_epay_default_type', 'alipay'),
                ];
                break;

            case 'paypal':
                $config = [
                    'client_id'     => get_option('qilingshop_paypal_client_id'),
                    'client_secret' => get_option('qilingshop_paypal_client_secret'),
                    'sandbox'       => (bool) get_option('qilingshop_paypal_sandbox'),
                    'webhook_id'    => get_option('qilingshop_paypal_webhook_id'),
                ];
                break;

            case 'stripe':
                $config = [
                    'publishable_key' => get_option('qilingshop_stripe_publishable_key'),
                    'secret_key'      => get_option('qilingshop_stripe_secret_key'),
                    'webhook_secret'  => get_option('qilingshop_stripe_webhook_secret'),
                    'currency'        => get_option('qilingshop_stripe_currency', 'usd'),
                    'rate'            => (float) get_option('qilingshop_stripe_rate', 7),
                ];
                break;
        }

        $config = apply_filters('qilingshop_gateway_config_' . $gateway, $config);

        // 验证配置有效性
        if (!empty($config['app_id'])) {
            return $config; // Alipay, WeChat
        }
        if (!empty($config['client_id']) && !empty($config['client_secret'])) {
            return $config; // PayPal REST
        }
        if (!empty($config['secret_key'])) {
            return $config; // Stripe
        }
        if (!empty($config['pid']) && !empty($config['key']) && !empty($config['api_url'])) {
            return $config; // Epay
        }
        if (
            (!empty($config['appid_alipay']) && !empty($config['appsecret_alipay'])) ||
            (!empty($config['appid_wechat']) && !empty($config['appsecret_wechat']))
        ) {
            return $config; // Xhpay
        }
        
        return null;
    }

    /**
     * 调用支付网关
     */
    private function call_gateway($gateway, $action, $params) {
        // 标准化网关名称，避免动态加载任意文件
        $gateway_name = $this->normalize_gateway($gateway);
        if ($gateway_name === '') {
            return [
                'success' => false,
                'message' => __('支付网关不受支持', 'qilingshop'),
            ];
        }
        
        $file = QILINGSHOP_PATH . 'payment/class-payment-' . $gateway_name . '.php';

        if (!file_exists($file)) {
            return [
                'success' => false,
                'message' => __('支付网关文件不存在', 'qilingshop') . ': ' . $gateway_name,
            ];
        }

        require_once $file;

        $class_name = 'QilingShop_Payment_' . ucfirst($gateway_name);
        
        if (!class_exists($class_name)) {
            return [
                'success' => false,
                'message' => __('支付网关类不存在', 'qilingshop') . ': ' . $class_name,
            ];
        }

        $instance = new $class_name();

        if (!method_exists($instance, $action)) {
            return [
                'success' => false,
                'message' => __('不支持的操作', 'qilingshop'),
            ];
        }

        return $instance->$action($params);
    }



    /**
     * 获取回调 URL
     */
    public function get_notify_url($gateway) {
        $gateway = $this->normalize_gateway($gateway);
        if ($gateway === '') {
            return home_url('/');
        }

        if (function_exists('qilingshop_get_payment_notify_url')) {
            return qilingshop_get_payment_notify_url($gateway);
        }

        return add_query_arg([
            'qilingshop_payment' => 'notify',
            'gateway'            => $gateway,
        ], home_url('/'));
    }

    /**
     * 获取返回 URL
     */
    public function get_return_url($order_type, $order_no) {
        $url = function_exists('qilingshop_normalize_return_url')
            ? qilingshop_normalize_return_url(get_option('qilingshop_payment_return_url'))
            : '';

        if ($url === '') {
            $url = home_url('/');
        }

        return add_query_arg([
            'qilingshop_payment' => 'return',
            'order_no' => $order_no,
            'type' => $order_type,
        ], $url);
    }

    /**
     * 处理支付回调
     */
    public function handle_callback() {
        if (!isset($_GET['qilingshop_payment'])) {
            return;
        }

        $action = sanitize_key((string) wp_unslash($_GET['qilingshop_payment']));
        $gateway = isset($_GET['gateway']) ? sanitize_key((string) wp_unslash($_GET['gateway'])) : '';

        if ($action === 'notify' && $gateway) {
            $this->handle_notify($gateway);
        } elseif ($action === 'paypal_return') {
            $this->handle_paypal_return();
        } elseif ($action === 'return') {
            $this->handle_return();
        }
    }

    /**
     * 处理标准 query var / rewrite 支付入口。
     *
     * @return void
     */
    public function handle_standard_entry() {
        $action = sanitize_key((string) get_query_var('qilingshop_payment'));
        if ($action === '' && isset($_GET['qilingshop_payment'])) {
            $action = sanitize_key((string) wp_unslash($_GET['qilingshop_payment']));
        }

        if ($action === 'entry') {
            $gateway = sanitize_key((string) get_query_var('gateway'));
            if ($gateway === '' && isset($_GET['gateway'])) {
                $gateway = sanitize_key((string) wp_unslash($_GET['gateway']));
            }
            $this->include_payment_entry($gateway);
        }

        if ($action === 'paypal_return') {
            $this->handle_paypal_return();
        }
    }

    /**
     * 加载支付页兼容实现。
     *
     * @param string $gateway 网关标识。
     * @return void
     */
    private function include_payment_entry($gateway) {
        $gateway = $this->normalize_gateway($gateway);
        $files = [
            'alipay' => 'alipay.php',
            'wechat' => 'wechat.php',
            'epay'   => 'epay.php',
            'xhpay'  => 'xhpay.php',
            'paypal' => 'paypal.php',
            'stripe' => 'stripe.php',
        ];

        if ($gateway === 'alipay_page') {
            $files[$gateway] = 'alipay_page.php';
        }

        if (!isset($files[$gateway])) {
            wp_die(esc_html__('支付方式不可用', 'qilingshop'));
        }

        $file = QILINGSHOP_PATH . 'payment/' . $files[$gateway];
        if (!file_exists($file)) {
            wp_die(esc_html__('支付入口不存在', 'qilingshop'));
        }

        require $file;
        exit;
    }

    /**
     * 处理 PayPal 同步返回。
     *
     * @return void
     */
    private function handle_paypal_return() {
        $file = QILINGSHOP_PATH . 'payment/paypal-return.php';
        if (!file_exists($file)) {
            wp_die(esc_html__('PayPal 返回处理入口不存在', 'qilingshop'));
        }

        require $file;
        exit;
    }

    /**
     * 处理异步通知
     * 
     * @deprecated 保留为兼容入口，实际校验与业务处理统一委托给 REST 处理器
     */
    private function handle_notify($gateway) {
        $gateway = strtolower($gateway);
        $gateway = str_replace(['wxpay', 'weixin'], 'wechat', $gateway);
        if (!preg_match('/^[a-z0-9_-]+$/', $gateway)) {
            exit('FAIL: 支付网关无效');
        }

        do_action('qilingshop_payment_callback_received', $gateway, $_REQUEST);

        if (function_exists('qilingshop_dispatch_legacy_payment_notify')) {
            qilingshop_dispatch_legacy_payment_notify($gateway);
        }

        $notify_file = QILINGSHOP_PATH . 'payment/notify-' . $gateway . '.php';
        if (file_exists($notify_file)) {
            require_once $notify_file;
            exit;
        }

        exit('FAIL: 支付网关无效');
    }

    /**
     * 处理同步返回
     */
    private function handle_return() {
        $order_no = isset($_GET['order_no']) ? sanitize_text_field((string) wp_unslash($_GET['order_no'])) : '';
        if (empty($order_no) && isset($_GET['order'])) {
            $order_no = sanitize_text_field((string) wp_unslash($_GET['order']));
        }
        if (empty($order_no) && isset($_GET['out_trade_no'])) {
            $order_no = sanitize_text_field((string) wp_unslash($_GET['out_trade_no']));
        }
        $type = isset($_GET['type']) ? sanitize_text_field((string) wp_unslash($_GET['type'])) : '';

        if (empty($order_no)) {
            wp_safe_redirect(home_url());
            exit;
        }

        $order = null;
        if (function_exists('qls_shop_order')) {
            $order = qls_shop_order()->get_by_order_no($order_no);
        }

        $shop_orders_url = function_exists('qls_shop_public')
            ? qls_shop_public()->get_page_url('orders')
            : home_url('/user-center/orders/');
        $is_guest_shop_order = (!empty($order) && isset($order->user_id) && (int) $order->user_id <= 0);
        $shop_guest_redirect_url = $shop_orders_url;
        if ($is_guest_shop_order && function_exists('qilingshop_get_order_query_page_url')) {
            $shop_guest_redirect_url = qilingshop_get_order_query_page_url($order_no, $shop_orders_url);
        }

        $is_group_type = ($type === 'group' || $type === 'group_join');
        if (!$is_group_type && strpos($order_no, 'TUAN') === 0) {
            $is_group_type = true;
        }
        if ($is_group_type || (!empty($order) && !empty($order->is_group_order))) {
            $group_id = 0;
            if (!empty($order->group_id)) {
                $group_id = intval($order->group_id);
            }
            if (!$group_id && !empty($order->id)) {
                $group_info = qilingshop_get_group_order_data($order->id);
                if (!empty($group_info['group_id'])) {
                    $group_id = intval($group_info['group_id']);
                }
            }
            if (!$group_id && !empty($order->id)) {
                global $wpdb;
                $prefix = defined('QLS_SHOP_TABLE_PREFIX') ? QLS_SHOP_TABLE_PREFIX : 'qls_shop_';
                $members_table = $wpdb->prefix . $prefix . 'group_members';
                $group_id = intval($wpdb->get_var($wpdb->prepare(
                    "SELECT group_id FROM {$members_table} WHERE order_id = %d LIMIT 1",
                    $order->id
                )));
            }

            if ($is_guest_shop_order) {
                $redirect_url = $shop_guest_redirect_url;
            } elseif (function_exists('qls_group_public')) {
                $redirect_url = qls_group_public()->get_group_detail_url($group_id);
            } else {
                $redirect_url = $shop_orders_url;
            }
        } elseif ($type === 'recharge' || strpos($order_no, 'CZ') === 0) {
            $redirect_url = apply_filters('qilingshop_payment_success_redirect', home_url('/user-center/recharge/'), $order_no);
        } elseif ($type === 'vip' || strpos($order_no, 'VIP') === 0) {
            $redirect_url = $this->get_account_tab_url('qls-vip');
        } elseif ($type === 'shop' || $type === 'shop_order' || strpos($order_no, 'SHOP') === 0 || strpos($order_no, 'TUAN') === 0) {
            $redirect_url = $is_guest_shop_order ? $shop_guest_redirect_url : $shop_orders_url;
        } else {
            $redirect_url = apply_filters('qilingshop_payment_success_redirect', home_url('/user-center/orders/'), $order_no);
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * 获取个人中心标签页地址
     *
     * @param string $tab 标签页标识
     * @return string
     */
    private function get_account_tab_url($tab = '') {
        if (function_exists('developer_starter_get_frontend_account_tab_url')) {
            return (string) developer_starter_get_frontend_account_tab_url($tab);
        }

        $account_url = '';

        $account_page_id = (int) get_option('developer_starter_account_page_id', 0);
        if ($account_page_id > 0 && get_post_status($account_page_id) === 'publish') {
            $account_url = get_permalink($account_page_id);
        }

        if ($account_url === '') {
            $pages = get_pages([
                'meta_key'   => '_wp_page_template',
                'meta_value' => 'templates/template-account.php',
                'number'     => 1,
            ]);
            if (!empty($pages)) {
                $account_url = get_permalink($pages[0]->ID);
            }
        }

        if ($account_url === '') {
            $account_url = function_exists('developer_starter_build_raw_home_url')
                ? developer_starter_build_raw_home_url('/user')
                : home_url('/user');
        }

        $tab = sanitize_key((string) $tab);
        if ($tab !== '') {
            return add_query_arg('tab', $tab, $account_url);
        }

        return $account_url;
    }

    /**
     * 查询支付状态
     */
    public function query_payment($order_no, $gateway) {
        $gateway = $this->normalize_gateway($gateway);
        if ($gateway === '') {
            return [
                'success' => false,
                'message' => __('支付网关不受支持', 'qilingshop'),
            ];
        }

        return $this->call_gateway($gateway, 'query', ['order_no' => $order_no]);
    }

    /**
     * 发起退款
     *
     * @param string $gateway 支付网关
     * @param array  $params  退款参数
     * @return array
     */
    public function refund_payment($gateway, $params = []) {
        $gateway = $this->normalize_gateway($gateway);
        if ($gateway === '') {
            return $this->normalize_refund_result([
                'success' => false,
                'message' => __('支付网关不受支持', 'qilingshop'),
            ], $gateway, $params);
        }

        $params = is_array($params) ? $params : [];
        $result = $this->call_gateway($gateway, 'refund', $params);

        return $this->normalize_refund_result($result, $gateway, $params);
    }

    /**
     * 统一归一化退款结果，便于服务层持久化退款流水。
     *
     * @param mixed  $result  网关返回
     * @param string $gateway 网关标识
     * @param array  $params  请求参数
     * @return array
     */
    private function normalize_refund_result($result, $gateway, $params = []) {
        if ($result instanceof WP_Error) {
            $result = [
                'success' => false,
                'message' => $result->get_error_message(),
                'code'    => $result->get_error_code(),
                'raw'     => $result->get_error_data(),
            ];
        }

        if (!is_array($result)) {
            $result = [
                'success' => false,
                'message' => __('退款返回结果异常', 'qilingshop'),
                'raw'     => $result,
            ];
        }

        $success = !empty($result['success']);
        $status = sanitize_key((string) ($result['status'] ?? ($success ? 'success' : 'failed')));
        if ($status === '') {
            $status = $success ? 'success' : 'failed';
        }

        $message = isset($result['message']) ? (string) $result['message'] : '';
        if ($message === '') {
            $message = $success ? __('退款已提交', 'qilingshop') : __('退款失败', 'qilingshop');
        }

        $gateway_refund_no = sanitize_text_field((string) ($result['gateway_refund_no'] ?? $result['refund_no'] ?? ($params['refund_no'] ?? '')));
        $refunded_amount = isset($result['refunded_amount']) ? (float) $result['refunded_amount'] : (isset($params['amount']) ? (float) $params['amount'] : 0);

        return array_merge($result, [
            'success'           => $success,
            'gateway'           => $gateway,
            'message'           => $message,
            'status'            => $status,
            'gateway_refund_no' => $gateway_refund_no,
            'refunded_amount'   => round($refunded_amount, 2),
            'raw'               => $result['raw'] ?? $result,
        ]);
    }

    /**
     * 获取商城退款验收自检信息。
     *
     * @return array
     */
    public function get_shop_refund_diagnostics() {
        $current_mode = sanitize_key((string) get_option('qilingshop_shop_refund_mode', 'withdrawable_balance'));
        if (!in_array($current_mode, ['withdrawable_balance', 'gateway'], true)) {
            $current_mode = 'withdrawable_balance';
        }

        $channels = [];

        $balance_status = class_exists('QilingShop_Points') ? 'ready' : 'blocked';
        $balance_notes = [
            __('保留原有退款路径，适合不支持原路退款的历史订单或第三方渠道订单。', 'qilingshop'),
        ];
        if ($current_mode !== 'withdrawable_balance') {
            $balance_notes[] = __('当前后台退款方式不是“退回可提现余额”，验收前需要先切换。', 'qilingshop');
            if ($balance_status === 'ready') {
                $balance_status = 'warning';
            }
        }
        $channels[] = $this->build_shop_refund_diagnostic_item([
            'key'           => 'withdrawable_balance',
            'label'         => __('退回可提现余额', 'qilingshop'),
            'description'   => __('验证原有退款能力是否保持正常。', 'qilingshop'),
            'required_mode' => 'withdrawable_balance',
            'status'        => $balance_status,
            'missing'       => class_exists('QilingShop_Points') ? [] : [__('积分资产服务未加载', 'qilingshop')],
            'notes'         => $balance_notes,
            'steps'         => [
                __('把商城退款方式切到“退回可提现余额”。', 'qilingshop'),
                __('创建一笔已支付商城订单并提交售后，后台审核通过后确认退款。', 'qilingshop'),
                __('核对用户可提现余额增加，售后页出现“退款完成”和最近日志。', 'qilingshop'),
            ],
        ]);

        $alipay_missing = [];
        if (!(bool) get_option('qilingshop_alipay_enabled', false)) {
            $alipay_missing[] = __('支付宝支付入口未启用', 'qilingshop');
        }
        if (sanitize_text_field((string) get_option('qilingshop_alipay_app_id', '')) === '') {
            $alipay_missing[] = __('应用 AppID', 'qilingshop');
        }
        if (trim((string) get_option('qilingshop_alipay_private_key', '')) === '') {
            $alipay_missing[] = __('应用私钥', 'qilingshop');
        }
        if (trim((string) get_option('qilingshop_alipay_public_key', '')) === '') {
            $alipay_missing[] = __('支付宝公钥', 'qilingshop');
        }
        $alipay_status = empty($alipay_missing) ? 'ready' : 'warning';
        $alipay_notes = [];
        if ($current_mode !== 'gateway') {
            $alipay_notes[] = __('当前后台退款方式不是“原路退回”，验收前需要先切换。', 'qilingshop');
            if ($alipay_status === 'ready') {
                $alipay_status = 'warning';
            }
        }
        $channels[] = $this->build_shop_refund_diagnostic_item([
            'key'           => 'alipay',
            'label'         => __('支付宝原路退款', 'qilingshop'),
            'description'   => __('验证支付宝支付订单能否走原路退回。', 'qilingshop'),
            'required_mode' => 'gateway',
            'status'        => $alipay_status,
            'missing'       => $alipay_missing,
            'notes'         => $alipay_notes,
            'steps'         => [
                __('把商城退款方式切到“原路退回”。', 'qilingshop'),
                __('用支付宝创建一笔已支付商城订单并提交售后。', 'qilingshop'),
                __('后台确认退款后，核对网关退款单号、原路状态和最近日志。', 'qilingshop'),
            ],
        ]);

        $wechat_missing = [];
        if (!(bool) get_option('qilingshop_wechat_enabled', false)) {
            $wechat_missing[] = __('网页/公众号微信支付入口未启用', 'qilingshop');
        }
        if (sanitize_text_field((string) get_option('qilingshop_wechat_mchid', '')) === '') {
            $wechat_missing[] = __('商户号(MCHID)', 'qilingshop');
        }
        if (sanitize_text_field((string) get_option('qilingshop_wechat_appid', '')) === '') {
            $wechat_missing[] = __('AppID', 'qilingshop');
        }
        if (sanitize_text_field((string) get_option('qilingshop_wechat_key', '')) === '') {
            $wechat_missing[] = __('API 密钥', 'qilingshop');
        }
        if (trim((string) get_option('qilingshop_wechat_client_cert', '')) === '') {
            $wechat_missing[] = __('商户 API 证书', 'qilingshop');
        }
        if (trim((string) get_option('qilingshop_wechat_client_key', '')) === '') {
            $wechat_missing[] = __('商户 API 私钥', 'qilingshop');
        }
        $wechat_status = empty($wechat_missing) ? 'ready' : 'warning';
        $wechat_notes = [];
        if ($current_mode !== 'gateway') {
            $wechat_notes[] = __('当前后台退款方式不是“原路退回”，验收前需要先切换。', 'qilingshop');
            if ($wechat_status === 'ready') {
                $wechat_status = 'warning';
            }
        }
        $channels[] = $this->build_shop_refund_diagnostic_item([
            'key'           => 'wechat',
            'label'         => __('网页/公众号微信原路退款', 'qilingshop'),
            'description'   => __('验证网页端微信支付订单能否走 v2 退款。', 'qilingshop'),
            'required_mode' => 'gateway',
            'status'        => $wechat_status,
            'missing'       => $wechat_missing,
            'notes'         => $wechat_notes,
            'steps'         => [
                __('把商城退款方式切到“原路退回”。', 'qilingshop'),
                __('用网页/公众号微信创建一笔已支付商城订单并提交售后。', 'qilingshop'),
                __('后台确认退款后，核对网关退款单号、原路状态和最近日志。', 'qilingshop'),
            ],
        ]);

        $miniapp_enabled = (bool) get_option('qilingshop_wechat_miniapp_enabled', false);
        $miniapp_current_type = sanitize_key((string) get_option('qilingshop_wechat_miniapp_pay_type', 'v2'));
        if (!in_array($miniapp_current_type, ['v2', 'v3'], true)) {
            $miniapp_current_type = 'v2';
        }

        $miniapp_v2_missing = [];
        if (!$miniapp_enabled) {
            $miniapp_v2_missing[] = __('微信小程序支付入口未启用', 'qilingshop');
        }
        if (sanitize_text_field((string) get_option('qilingshop_wechat_miniapp_appid', '')) === '') {
            $miniapp_v2_missing[] = __('小程序 AppID', 'qilingshop');
        }
        if (sanitize_text_field((string) get_option('qilingshop_wechat_miniapp_mchid', '')) === '') {
            $miniapp_v2_missing[] = __('商户号(MCHID)', 'qilingshop');
        }
        if (sanitize_text_field((string) get_option('qilingshop_wechat_miniapp_key', '')) === '') {
            $miniapp_v2_missing[] = __('商户支付密钥(KEY)', 'qilingshop');
        }
        if (trim((string) get_option('qilingshop_wechat_miniapp_client_cert', '')) === '') {
            $miniapp_v2_missing[] = __('商户 API 证书', 'qilingshop');
        }
        if (trim((string) get_option('qilingshop_wechat_miniapp_client_key', '')) === '') {
            $miniapp_v2_missing[] = __('商户 API 私钥', 'qilingshop');
        }
        $miniapp_v2_status = empty($miniapp_v2_missing) ? 'ready' : 'warning';
        $miniapp_v2_notes = [];
        if ($current_mode !== 'gateway') {
            $miniapp_v2_notes[] = __('当前后台退款方式不是“原路退回”，验收前需要先切换。', 'qilingshop');
            if ($miniapp_v2_status === 'ready') {
                $miniapp_v2_status = 'warning';
            }
        }
        if ($miniapp_current_type !== 'v2') {
            $miniapp_v2_notes[] = __('当前小程序支付接口版本不是 v2，如要新建订单验收 v2，请先切换到 v2。', 'qilingshop');
            if ($miniapp_v2_status === 'ready') {
                $miniapp_v2_status = 'warning';
            }
        }
        $channels[] = $this->build_shop_refund_diagnostic_item([
            'key'           => 'wechat_miniapp_v2',
            'label'         => __('微信小程序原路退款 V2', 'qilingshop'),
            'description'   => __('验证小程序 v2 支付订单能否走双向证书退款。', 'qilingshop'),
            'required_mode' => 'gateway',
            'status'        => $miniapp_v2_status,
            'missing'       => $miniapp_v2_missing,
            'notes'         => $miniapp_v2_notes,
            'steps'         => [
                __('把商城退款方式切到“原路退回”，并把小程序支付接口版本切到 v2。', 'qilingshop'),
                __('用微信小程序创建一笔已支付商城订单并提交售后。', 'qilingshop'),
                __('后台确认退款后，核对网关退款单号、原路状态和最近日志。', 'qilingshop'),
            ],
        ]);

        $miniapp_v3_missing = [];
        $miniapp_v3_status = 'ready';
        if (!$miniapp_enabled) {
            $miniapp_v3_missing[] = __('微信小程序支付入口未启用', 'qilingshop');
        }
        if (sanitize_text_field((string) get_option('qilingshop_wechat_miniapp_appid', '')) === '') {
            $miniapp_v3_missing[] = __('小程序 AppID', 'qilingshop');
        }
        if (sanitize_text_field((string) get_option('qilingshop_wechat_miniapp_mchid', '')) === '') {
            $miniapp_v3_missing[] = __('商户号(MCHID)', 'qilingshop');
        }
        if (sanitize_text_field((string) get_option('qilingshop_wechat_miniapp_key_v3', '')) === '') {
            $miniapp_v3_missing[] = __('APIv3 密钥', 'qilingshop');
        }
        if (sanitize_text_field((string) get_option('qilingshop_wechat_miniapp_serial_no', '')) === '') {
            $miniapp_v3_missing[] = __('商户证书序列号', 'qilingshop');
        }
        if (trim((string) get_option('qilingshop_wechat_miniapp_client_cert', '')) === '') {
            $miniapp_v3_missing[] = __('商户 API 证书', 'qilingshop');
        }
        if (trim((string) get_option('qilingshop_wechat_miniapp_client_key', '')) === '') {
            $miniapp_v3_missing[] = __('商户 API 私钥', 'qilingshop');
        }
        if (sanitize_text_field((string) get_option('qilingshop_wechat_miniapp_public_key_id', '')) === '') {
            $miniapp_v3_missing[] = __('微信支付平台公钥 ID', 'qilingshop');
        }
        if (trim((string) get_option('qilingshop_wechat_miniapp_public_key_pem', '')) === '') {
            $miniapp_v3_missing[] = __('微信支付平台公钥 PEM', 'qilingshop');
        }
        if (!function_exists('openssl_sign') || !function_exists('openssl_verify') || !function_exists('openssl_decrypt')) {
            $miniapp_v3_status = 'blocked';
            $miniapp_v3_missing[] = __('当前 PHP 环境未启用完整 OpenSSL 能力', 'qilingshop');
        } elseif (!empty($miniapp_v3_missing)) {
            $miniapp_v3_status = 'warning';
        }
        $miniapp_v3_notes = [];
        if ($current_mode !== 'gateway') {
            $miniapp_v3_notes[] = __('当前后台退款方式不是“原路退回”，验收前需要先切换。', 'qilingshop');
            if ($miniapp_v3_status === 'ready') {
                $miniapp_v3_status = 'warning';
            }
        }
        if ($miniapp_current_type !== 'v3') {
            $miniapp_v3_notes[] = __('当前小程序支付接口版本不是 v3，如要新建订单验收 v3，请先切换到 v3。', 'qilingshop');
            if ($miniapp_v3_status === 'ready') {
                $miniapp_v3_status = 'warning';
            }
        }
        $channels[] = $this->build_shop_refund_diagnostic_item([
            'key'           => 'wechat_miniapp_v3',
            'label'         => __('微信小程序原路退款 V3', 'qilingshop'),
            'description'   => __('验证小程序 v3 支付订单能否走 APIv3 退款。', 'qilingshop'),
            'required_mode' => 'gateway',
            'status'        => $miniapp_v3_status,
            'missing'       => $miniapp_v3_missing,
            'notes'         => $miniapp_v3_notes,
            'steps'         => [
                __('把商城退款方式切到“原路退回”，并把小程序支付接口版本切到 v3。', 'qilingshop'),
                __('用微信小程序创建一笔已支付商城订单并提交售后。', 'qilingshop'),
                __('后台确认退款后，核对网关退款单号、原路状态和最近日志。', 'qilingshop'),
            ],
        ]);

        $summary = [
            'ready'   => 0,
            'warning' => 0,
            'blocked' => 0,
        ];
        foreach ($channels as $channel) {
            $status = $channel['status'];
            if (!isset($summary[$status])) {
                $summary[$status] = 0;
            }
            $summary[$status]++;
        }

        return [
            'generated_at'        => current_time('mysql'),
            'current_mode'        => $current_mode,
            'current_mode_label'  => $this->get_shop_refund_mode_label($current_mode),
            'channels'            => $channels,
            'summary'             => $summary,
            'refunds_page_url'    => admin_url('admin.php?page=qls-shop-refunds'),
        ];
    }

    /**
     * 标准化单条退款验收项。
     *
     * @param array $args
     * @return array
     */
    private function build_shop_refund_diagnostic_item($args = []) {
        $defaults = [
            'key'           => '',
            'label'         => '',
            'description'   => '',
            'required_mode' => 'gateway',
            'status'        => 'warning',
            'missing'       => [],
            'notes'         => [],
            'steps'         => [],
        ];
        $item = wp_parse_args($args, $defaults);

        $item['key'] = sanitize_key((string) $item['key']);
        $item['label'] = sanitize_text_field((string) $item['label']);
        $item['description'] = sanitize_text_field((string) $item['description']);
        $item['required_mode'] = sanitize_key((string) $item['required_mode']);
        if (!in_array($item['required_mode'], ['withdrawable_balance', 'gateway'], true)) {
            $item['required_mode'] = 'gateway';
        }

        $item['status'] = sanitize_key((string) $item['status']);
        if (!in_array($item['status'], ['ready', 'warning', 'blocked'], true)) {
            $item['status'] = 'warning';
        }

        $item['status_label'] = $this->get_shop_refund_diagnostic_status_label($item['status']);
        $item['required_mode_label'] = $this->get_shop_refund_mode_label($item['required_mode']);
        $item['missing'] = array_values(array_filter(array_map('sanitize_text_field', (array) $item['missing'])));
        $item['notes'] = array_values(array_filter(array_map('sanitize_text_field', (array) $item['notes'])));
        $item['steps'] = array_values(array_filter(array_map('sanitize_text_field', (array) $item['steps'])));

        return $item;
    }

    /**
     * 退款模式文本。
     *
     * @param string $mode
     * @return string
     */
    private function get_shop_refund_mode_label($mode) {
        return $mode === 'gateway'
            ? __('原路退回', 'qilingshop')
            : __('退回可提现余额', 'qilingshop');
    }

    /**
     * 退款验收状态文本。
     *
     * @param string $status
     * @return string
     */
    private function get_shop_refund_diagnostic_status_label($status) {
        $labels = [
            'ready'   => __('就绪', 'qilingshop'),
            'warning' => __('待完善', 'qilingshop'),
            'blocked' => __('环境异常', 'qilingshop'),
        ];

        $status = sanitize_key((string) $status);
        return $labels[$status] ?? __('待完善', 'qilingshop');
    }

    /**
     * 判断网关是否已启用
     *
     * @param string $gateway 网关标识
     * @return bool
     */
    public function is_gateway_enabled($gateway) {
        $gateway = $this->normalize_gateway($gateway);
        if ($gateway === '') {
            return false;
        }

        $enabled = $this->get_enabled_gateways();
        return isset($enabled[$gateway]);
    }

    /**
     * 获取支付入口文件对应的网关标识
     *
     * @param string $gateway 网关标识
     * @return string
     */
    public function get_gateway_entry_slug($gateway) {
        return $this->normalize_gateway($gateway);
    }

    /**
     * 统一网关别名，限制在受支持的网关集合
     *
     * @param string $gateway 原始网关值
     * @return string
     */
    private function normalize_gateway($gateway) {
        $gateway = sanitize_key((string) $gateway);

        if ($gateway === 'wxpay' || $gateway === 'weixin') {
            $gateway = 'wechat';
        }

        // 历史兼容：扫码/网页支付宝统一走 alipay.php
        if ($gateway === 'alipay_qr' || $gateway === 'alipay_page') {
            $gateway = 'alipay';
        }

        $allowed = apply_filters('qilingshop_allowed_gateway_keys', ['alipay', 'wechat', 'xhpay', 'epay', 'paypal', 'stripe']);
        if (!is_array($allowed)) {
            $allowed = ['alipay', 'wechat', 'xhpay', 'epay', 'paypal', 'stripe'];
        }
        $allowed = array_map('sanitize_key', $allowed);
        if (!in_array($gateway, $allowed, true)) {
            return '';
        }

        return $gateway;
    }
}

/**
 * 渲染商城退款验收自检面板。
 *
 * @return void
 */
function qilingshop_render_shop_refund_diagnostics_panel() {
    if (!class_exists('QilingShop_Payment')) {
        return;
    }

    $diagnostics = QilingShop_Payment::instance()->get_shop_refund_diagnostics();
    if (!is_array($diagnostics) || empty($diagnostics['channels'])) {
        return;
    }

    $summary = $diagnostics['summary'] ?? [];
    $current_mode_label = sanitize_text_field((string) ($diagnostics['current_mode_label'] ?? ''));
    $generated_at = sanitize_text_field((string) ($diagnostics['generated_at'] ?? ''));
    $refunds_page_url = esc_url((string) ($diagnostics['refunds_page_url'] ?? admin_url('admin.php?page=qls-shop-refunds')));
    ?>
    <h2><?php _e('退款验收自检', 'qilingshop'); ?></h2>
    <div class="notice notice-info inline">
        <p>
            <strong><?php _e('说明：', 'qilingshop'); ?></strong>
            <?php printf(
                esc_html__('当前商城退款方式为“%1$s”。此面板只做静态配置检查，不会真实发起退款；请结合“售后退款”页面做最终验收。生成时间：%2$s', 'qilingshop'),
                $current_mode_label,
                $generated_at
            ); ?>
        </p>
        <p>
            <?php
            printf(
                esc_html__('当前结果：就绪 %1$d 项，待完善 %2$d 项，环境异常 %3$d 项。', 'qilingshop'),
                (int) ($summary['ready'] ?? 0),
                (int) ($summary['warning'] ?? 0),
                (int) ($summary['blocked'] ?? 0)
            );
            ?>
            <a href="<?php echo $refunds_page_url; ?>"><?php _e('前往售后退款页', 'qilingshop'); ?></a>
        </p>
    </div>

    <table class="wp-list-table qls-ui-table widefat striped">
        <thead>
            <tr>
                <th><?php _e('验收场景', 'qilingshop'); ?></th>
                <th><?php _e('状态', 'qilingshop'); ?></th>
                <th><?php _e('后台模式要求', 'qilingshop'); ?></th>
                <th><?php _e('待完善项 / 说明', 'qilingshop'); ?></th>
                <th><?php _e('建议验收动作', 'qilingshop'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($diagnostics['channels'] as $channel): ?>
            <?php
            $row_notes = [];
            foreach ((array) ($channel['missing'] ?? []) as $text) {
                $row_notes[] = __('缺少：', 'qilingshop') . $text;
            }
            foreach ((array) ($channel['notes'] ?? []) as $text) {
                $row_notes[] = $text;
            }
            ?>
            <tr>
                <td>
                    <strong><?php echo esc_html((string) ($channel['label'] ?? '')); ?></strong>
                    <?php if (!empty($channel['description'])): ?>
                    <div class="description"><?php echo esc_html((string) $channel['description']); ?></div>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html((string) ($channel['status_label'] ?? '')); ?></td>
                <td><?php echo esc_html((string) ($channel['required_mode_label'] ?? '')); ?></td>
                <td>
                    <?php if (empty($row_notes)): ?>
                    <span class="description"><?php _e('无额外说明', 'qilingshop'); ?></span>
                    <?php else: ?>
                    <?php foreach ($row_notes as $text): ?>
                    <div><?php echo esc_html($text); ?></div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php foreach ((array) ($channel['steps'] ?? []) as $index => $step): ?>
                    <div><?php echo esc_html(($index + 1) . '. ' . $step); ?></div>
                    <?php endforeach; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}
