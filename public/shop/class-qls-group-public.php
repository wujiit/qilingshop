<?php
/**
 * 团购前台处理类
 * 
 * 处理前台团购展示、AJAX 请求、短代码等
 *
 * @package QilingShop
 * @since   2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 团购前台类
 */
class QLS_Group_Public {

    /**
     * 单例实例
     * @var QLS_Group_Public
     */
    private static $instance = null;

    /**
     * 获取单例实例
     * 
     * @return QLS_Group_Public
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
        $this->init_hooks();
    }

    /**
     * 初始化钩子
     */
    private function init_hooks() {
        // 注册短代码
        add_shortcode('qls_group_center', [$this, 'shortcode_group_center']);
        add_shortcode('qls_group_detail', [$this, 'shortcode_group_detail']);
        add_shortcode('qls_my_groups', [$this, 'shortcode_my_groups']);
        
        // AJAX处理 - 发起拼团
        add_action('wp_ajax_qls_create_group_order', [$this, 'ajax_create_group_order']);
        add_action('wp_ajax_nopriv_qls_create_group_order', [$this, 'ajax_create_group_order']);
        
        // AJAX处理 - 参与拼团
        add_action('wp_ajax_qls_join_group', [$this, 'ajax_join_group']);
        add_action('wp_ajax_nopriv_qls_join_group', [$this, 'ajax_join_group']);

        add_action('wp_ajax_qls_group_checkout', [$this, 'ajax_group_checkout']);
        add_action('wp_ajax_nopriv_qls_group_checkout', [$this, 'ajax_group_checkout']);
        
        // AJAX处理 - 获取团列表
        add_action('wp_ajax_qls_get_product_groups', [$this, 'ajax_get_product_groups']);
        add_action('wp_ajax_nopriv_qls_get_product_groups', [$this, 'ajax_get_product_groups']);

        add_action('wp_ajax_qls_get_group_stock', [$this, 'ajax_get_group_stock']);
        add_action('wp_ajax_nopriv_qls_get_group_stock', [$this, 'ajax_get_group_stock']);
        
        // 加载前台资源
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * 公共读取接口防护（nonce + 频率限制）
     *
     * @param string $scope 限流作用域
     * @param string $nonce_action nonce action
     * @param int    $max 每时间窗最大请求数
     * @param int    $interval 时间窗（秒）
     * @return void
     */
    private function guard_public_read_request($scope, $nonce_action = 'qls_shop_nonce', $max = 120, $interval = 60) {
        $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, $nonce_action)) {
            wp_send_json_error(['message' => __('安全验证失败', 'qilingshop')], 403);
        }

        if (!function_exists('qilingshop_security')) {
            return;
        }

        $ip = qilingshop_security()->get_client_ip();
        $rate_key = 'qls_group_public_' . sanitize_key((string) $scope) . '_' . md5($ip);
        $allowed = qilingshop_security()->rate_limit($rate_key, (int) $max, (int) $interval);
        if (!$allowed) {
            wp_send_json_error(['message' => __('请求过于频繁，请稍后重试', 'qilingshop')], 429);
        }
    }

    /**
     * 团购订单在“下单扣库存”配置下，必须在订单项写入后立即预占库存。
     *
     * @param int $order_id 订单 ID。
     * @return bool
     */
    private function maybe_reduce_group_order_stock_on_create($order_id) {
        if (get_option('qls_shop_stock_reduce_on', 'order') !== 'order') {
            return true;
        }

        $order = qls_shop_order()->get((int) $order_id, true);
        if (!$order) {
            return false;
        }

        return qls_shop_order()->reduce_stock($order);
    }

    /**
     * 加载前台资源
     */
    public function enqueue_assets() {
        if (is_admin()) {
            return;
        }

        if (!$this->should_enqueue_assets()) {
            return;
        }

        // 团购样式（仅在拼团页/商品详情页/含拼团短码页面加载）
        wp_enqueue_style(
            'qls-group-buy',
            QILINGSHOP_URL . 'static/shop/css/group-buy.css',
            [],
            qilingshop_get_assets_version()
        );
    }

    /**
     * 是否需要加载团购前端资源
     *
     * @return bool
     */
    private function should_enqueue_assets() {
        // 1. 商品详情（团购价格模块位于商品详情页）
        if (get_query_var('qls_product')) {
            return true;
        }

        // 2. 团详情兜底（部分站点可能通过 query 传 group_id）
        $group_id_from_query = isset($_GET['group_id']) ? intval(wp_unslash($_GET['group_id'])) : 0;
        if ($group_id_from_query > 0) {
            return true;
        }

        // 3. 团购中心/团详情映射页面
        $current_id = (int) get_queried_object_id();
        if (!$current_id) {
            $current_id = (int) get_the_ID();
        }
        if (!$current_id) {
            global $post;
            if ($post instanceof WP_Post) {
                $current_id = (int) $post->ID;
            }
        }

        if ($current_id > 0) {
            $group_page_ids = [];
            foreach ([
                'qls_shop_page_group_center',
                'qls_shop_page_group-center',
                'qls_shop_page_group_detail',
                'qls_shop_page_group-detail',
            ] as $option_key) {
                $page_id = (int) get_option($option_key, 0);
                if ($page_id > 0) {
                    $group_page_ids[] = $page_id;
                }
            }
            $group_page_ids = array_values(array_unique($group_page_ids));
            if (in_array($current_id, $group_page_ids, true)) {
                return true;
            }
        }

        // 4. 兜底：任意页面只要用了团购 shortcodes 也加载
        if (is_singular()) {
            $post = get_queried_object();
            if (!($post instanceof WP_Post)) {
                $post = get_post();
            }
            if ($post instanceof WP_Post) {
                $content = (string) $post->post_content;
                if (
                    (function_exists('has_shortcode') && has_shortcode($content, 'qls_group_center')) ||
                    (function_exists('has_shortcode') && has_shortcode($content, 'qls_group_detail')) ||
                    (function_exists('has_shortcode') && has_shortcode($content, 'qls_my_groups'))
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 校验团购可用支付方式
     *
     * @param string $payment_method 支付方式
     * @return string
     */
    private function normalize_group_payment_method($payment_method) {
        $payment_method = sanitize_key((string) $payment_method);

        if ($payment_method === 'wechat_miniapp') {
            return '';
        }

        if ($payment_method === 'alipay_page') {
            $payment_method = 'alipay';
        }

        $allowed = [];
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

    // =========================================================================
    // 短代码
    // =========================================================================

    /**
     * 团购中心短代码
     */
    public function shortcode_group_center($atts) {
        if (is_admin()) {
            return '<div class="qls-shortcode-placeholder" style="padding:20px;background:#f0f0f1;text-align:center;border:1px dashed #ccc;">[qls_group_center] ' . __('团购中心', 'qilingshop') . '</div>';
        }

        add_filter('qiling_show_page_header', '__return_false');
        add_filter('qiling_show_sidebar', '__return_false');
        add_filter('developer_starter_load_sidebar_css', '__return_false');

        $atts = shortcode_atts([
            'per_page' => 12,
        ], $atts);
        
        $page = isset($_GET['gpage']) ? max(1, intval($_GET['gpage'])) : 1;
        
        // 获取团购商品列表
        $products = qls_group()->get_group_products([
            'per_page' => $atts['per_page'],
            'page'     => $page,
        ]);
        
        $total = qls_group()->get_group_products_count();
        $total_pages = ceil($total / $atts['per_page']);
        
        ob_start();
        include QILINGSHOP_PATH . 'templates/shop/group-center.php';
        return ob_get_clean();
    }

    /**
     * 团详情短代码
     */
    public function shortcode_group_detail($atts) {
        if (is_admin()) {
            return '<div class="qls-shortcode-placeholder" style="padding:20px;background:#f0f0f1;text-align:center;border:1px dashed #ccc;">[qls_group_detail] ' . __('团购详情', 'qilingshop') . '</div>';
        }

        add_filter('qiling_show_page_header', '__return_false');
        add_filter('qiling_show_sidebar', '__return_false');
        add_filter('developer_starter_load_sidebar_css', '__return_false');

        $atts = shortcode_atts([
            'id' => 0,
        ], $atts);
        
        $group_id = $atts['id'] ?: (isset($_GET['group_id']) ? intval($_GET['group_id']) : 0);
        
        // 未指定拼团编号时，查找用户最近的拼团
        if (!$group_id && is_user_logged_in()) {
            $user_groups = qls_group()->get_user_groups(get_current_user_id(), [
                'per_page' => 1,
                'page'     => 1
            ]);
            
            if (!empty($user_groups)) {
                $group_id = $user_groups[0]->id;
            }
        }

        $group = null;
        $product = null;
        
        if ($group_id) {
            $group = qls_group()->get_group($group_id, true);
            if ($group) {
                $product = qls_product()->get($group->product_id, true);
            }
        }
        
        ob_start();
        include QILINGSHOP_PATH . 'templates/shop/group-detail.php';
        return ob_get_clean();
    }

    /**
     * 我的拼团短代码
     */
    public function shortcode_my_groups($atts) {
        if (!is_user_logged_in()) {
            return '<div class="qls-notice qls-notice-warning">' . __('请先登录', 'qilingshop') . '</div>';
        }
        
        $atts = shortcode_atts([
            'per_page' => 10,
        ], $atts);
        
        $user_id = get_current_user_id();
        $page = isset($_GET['gpage']) ? max(1, intval($_GET['gpage'])) : 1;
        $status = isset($_GET['gstatus']) ? intval($_GET['gstatus']) : null;
        
        $groups = qls_group()->get_user_groups($user_id, [
            'status'   => $status,
            'per_page' => $atts['per_page'],
            'page'     => $page,
        ]);
        
        $total = qls_group()->get_user_groups_count($user_id, $status);
        $total_pages = ceil($total / $atts['per_page']);
        
        // 统计各状态数量
        $counts = [
            'all'     => qls_group()->get_user_groups_count($user_id),
            'pending' => qls_group()->get_user_groups_count($user_id, 0),
            'success' => qls_group()->get_user_groups_count($user_id, 1),
            'failed'  => qls_group()->get_user_groups_count($user_id, 2),
        ];
        
        ob_start();
        include QILINGSHOP_PATH . 'templates/shop/my-groups.php';
        return ob_get_clean();
    }

    // =========================================================================
    // AJAX Handlers
    // =========================================================================

    /**
     * AJAX: 创建团购订单（发起拼团）
     */
    public function ajax_create_group_order() {
        check_ajax_referer('qls_shop_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('请先登录', 'qilingshop')]);
        }
        
        $user_id = get_current_user_id();
        $product_id = intval($_POST['product_id'] ?? 0);
        $sku_id = intval($_POST['sku_id'] ?? 0);
        $quantity = max(1, intval($_POST['quantity'] ?? 1));
        
        // 验证商品和团购规则
        $product = qls_product()->get($product_id);
        if (!$product || $product->status != 1) {
            wp_send_json_error(['message' => __('商品不存在或已下架', 'qilingshop')]);
        }
        
        $rule = qls_group()->get_active_rule_by_product($product_id);
        if (!$rule) {
            wp_send_json_error(['message' => __('该商品未开启团购', 'qilingshop')]);
        }
        
        // 检查用户参团限制
        if ($rule->limit_per_user > 0) {
            $user_count = qls_group()->get_user_group_count($user_id, $product_id, $rule->id);
            if ($user_count >= $rule->limit_per_user) {
                wp_send_json_error(['message' => __('您已达到该商品的参团上限', 'qilingshop')]);
            }
        }
        
        // 验证SKU
        $sku = qls_product()->get_sku($sku_id);
        if (!$sku || $sku->product_id != $product_id) {
            wp_send_json_error(['message' => __('请选择商品规格', 'qilingshop')]);
        }
        
        // 检查库存。团购库存为 0 时使用商品 SKU 库存。
        if (qls_group()->rule_uses_product_stock($rule)) {
            if ((int) ($sku->stock ?? 0) < $quantity) {
                wp_send_json_error(['message' => __('商品库存不足', 'qilingshop')]);
            }
        } else {
            $group_stock = qls_group()->get_rule_stock($rule->id);
            if ($group_stock <= 0) {
                wp_send_json_error(['message' => __('团购已结束', 'qilingshop')]);
            }
            if ($group_stock < $quantity) {
                wp_send_json_error(['message' => __('团购库存不足', 'qilingshop')]);
            }
        }
        
        // 获取收货地址
        $address_id = intval($_POST['address_id'] ?? 0);
        $address = $this->get_user_address($user_id, $address_id);
        if (!$address && $product->product_type !== 'virtual') {
            wp_send_json_error(['message' => __('请选择收货地址', 'qilingshop')]);
        }
        
        // 计算金额（使用团购价）
        $item_total = $rule->group_price * $quantity;
        $shipping_item = (object) [
            'product' => $product,
            'sku' => $sku,
            'price' => (float) $rule->group_price,
            'quantity' => $quantity,
            'subtotal' => $item_total,
            'is_invalid' => false,
        ];
        $shipping_fee = qls_shipping()->calculate([$shipping_item], (array) $address);
        $final_amount = $item_total + $shipping_fee;
        
        // 创建订单
        $order_data = [
            'user_id'          => $user_id,
            'total_amount'     => $item_total,
            'shipping_fee'     => $shipping_fee,
            'discount_amount'  => 0,
            'points_used'      => 0,
            'final_amount'     => $final_amount,
            'status'           => 0, // 待支付
            'is_group_order'   => 1,
            'receiver_name'    => $address['name'] ?? '',
            'receiver_phone'   => $address['phone'] ?? '',
            'receiver_province'=> $address['province'] ?? '',
            'receiver_city'    => $address['city'] ?? '',
            'receiver_district'=> $address['district'] ?? '',
            'receiver_address' => $address['address'] ?? '',
            'buyer_remark'     => sanitize_textarea_field($_POST['remark'] ?? ''),
        ];
        
        $order_no = qls_shop_order()->create($order_data);
        if (!$order_no) {
            wp_send_json_error(['message' => __('创建订单失败', 'qilingshop')]);
        }
        
        // 获取订单ID
        $order = qls_shop_order()->get_by_order_no($order_no);
        if (!$order || empty($order->id)) {
            wp_send_json_error(['message' => __('订单读取失败', 'qilingshop')]);
        }
        
        // 添加订单商品
        $items = [[
            'product_id'    => $product_id,
            'sku_id'        => $sku_id,
            'product_title' => $product->title,
            'sku_attrs'     => $sku->attr_values,
            'image'         => is_array($product->main_image) ? ($product->main_image['url'] ?? '') : $product->main_image,
            'price'         => $rule->group_price,
            'quantity'      => $quantity,
            'total'         => $item_total,
        ]];
        // 先存储团购规则 ID，确保下单预占库存时能识别团购库存规则。
        qilingshop_set_group_order_data($order->id, [
            'rule_id'    => $rule->id,
            'is_leader'  => true,
            'group_id'   => 0, // 支付成功后创建
        ]);

        if (!qls_shop_order()->add_items($order->id, $items)) {
            qilingshop_clear_group_order_data((int) $order->id);
            qls_shop_order()->delete((int) $order->id);
            wp_send_json_error(['message' => __('订单商品写入失败', 'qilingshop')]);
        }

        if (!$this->maybe_reduce_group_order_stock_on_create((int) $order->id)) {
            qilingshop_clear_group_order_data((int) $order->id);
            qls_shop_order()->delete((int) $order->id);
            wp_send_json_error(['message' => __('库存扣减失败', 'qilingshop')]);
        }
        
        wp_send_json_success([
            'order_no'     => $order_no,
            'order_id'     => $order->id,
            'final_amount' => $final_amount,
            'redirect_url' => add_query_arg([
                'order_no' => $order_no,
                'type'     => 'group',
            ], qls_shop_public()->get_page_url('checkout')),
        ]);
    }

    /**
     * AJAX: 参与拼团
     */
    public function ajax_join_group() {
        check_ajax_referer('qls_shop_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('请先登录', 'qilingshop')]);
        }
        
        $user_id = get_current_user_id();
        $group_id = intval($_POST['group_id'] ?? 0);
        $sku_id = intval($_POST['sku_id'] ?? 0);
        $quantity = max(1, intval($_POST['quantity'] ?? 1));
        
        // 获取团信息
        $group = qls_group()->get_group($group_id);
        if (!$group) {
            wp_send_json_error(['message' => __('团购不存在', 'qilingshop')]);
        }
        
        // 验证状态
        if ($group->status != 0) {
            wp_send_json_error(['message' => __('该团已结束', 'qilingshop')]);
        }
        
        if (strtotime($group->expire_time) < current_time('timestamp')) {
            wp_send_json_error(['message' => __('该团已过期', 'qilingshop')]);
        }
        
        // 检查是否已参与
        if (qls_group()->is_member($group_id, $user_id)) {
            wp_send_json_error(['message' => __('您已参与此团', 'qilingshop')]);
        }
        
        // 获取商品和规则
        $product = qls_product()->get($group->product_id);
        $rule = qls_group()->get_rule($group->rule_id);
        if (!$product || !$rule || !qls_group()->is_rule_active($rule)) {
            wp_send_json_error(['message' => __('团购活动已结束', 'qilingshop')]);
        }
        
        // 验证SKU
        $sku = qls_product()->get_sku($sku_id);
        if (!$sku || $sku->product_id != $group->product_id) {
            wp_send_json_error(['message' => __('请选择商品规格', 'qilingshop')]);
        }
        
        // 检查库存。团购库存为 0 时使用商品 SKU 库存。
        if (qls_group()->rule_uses_product_stock($rule)) {
            if ((int) ($sku->stock ?? 0) < $quantity) {
                wp_send_json_error(['message' => __('商品库存不足', 'qilingshop')]);
            }
        } else {
            $group_stock = qls_group()->get_rule_stock($group->rule_id);
            if ($group_stock <= 0) {
                wp_send_json_error(['message' => __('团购已结束', 'qilingshop')]);
            }
            if ($group_stock < $quantity) {
                wp_send_json_error(['message' => __('团购库存不足', 'qilingshop')]);
            }
        }
        
        // 获取收货地址
        $address_id = intval($_POST['address_id'] ?? 0);
        $address = $this->get_user_address($user_id, $address_id);
        if (!$address && $product->product_type !== 'virtual') {
            wp_send_json_error(['message' => __('请选择收货地址', 'qilingshop')]);
        }
        
        // 计算金额
        $item_total = $group->group_price * $quantity;
        $shipping_item = (object) [
            'product' => $product,
            'sku' => $sku,
            'price' => (float) $group->group_price,
            'quantity' => $quantity,
            'subtotal' => $item_total,
            'is_invalid' => false,
        ];
        $shipping_fee = qls_shipping()->calculate([$shipping_item], (array) $address);
        $final_amount = $item_total + $shipping_fee;
        
        // 创建订单
        $order_data = [
            'user_id'          => $user_id,
            'total_amount'     => $item_total,
            'shipping_fee'     => $shipping_fee,
            'discount_amount'  => 0,
            'points_used'      => 0,
            'final_amount'     => $final_amount,
            'status'           => 0,
            'is_group_order'   => 1,
            'group_id'         => $group_id,
            'receiver_name'    => $address['name'] ?? '',
            'receiver_phone'   => $address['phone'] ?? '',
            'receiver_province'=> $address['province'] ?? '',
            'receiver_city'    => $address['city'] ?? '',
            'receiver_district'=> $address['district'] ?? '',
            'receiver_address' => $address['address'] ?? '',
            'buyer_remark'     => sanitize_textarea_field($_POST['remark'] ?? ''),
        ];
        
        $order_no = qls_shop_order()->create($order_data);
        if (!$order_no) {
            wp_send_json_error(['message' => __('创建订单失败', 'qilingshop')]);
        }
        
        $order = qls_shop_order()->get_by_order_no($order_no);
        if (!$order || empty($order->id)) {
            wp_send_json_error(['message' => __('订单读取失败', 'qilingshop')]);
        }
        
        // 添加订单商品
        $items = [[
            'product_id'    => $group->product_id,
            'sku_id'        => $sku_id,
            'product_title' => $product->title,
            'sku_attrs'     => $sku->attr_values,
            'image'         => is_array($product->main_image) ? ($product->main_image['url'] ?? '') : $product->main_image,
            'price'         => $group->group_price,
            'quantity'      => $quantity,
            'total'         => $item_total,
        ]];
        // 先存储团购信息，确保下单预占库存时能识别团购库存规则。
        qilingshop_set_group_order_data($order->id, [
            'rule_id'    => $group->rule_id,
            'is_leader'  => false,
            'group_id'   => $group_id,
        ]);

        if (!qls_shop_order()->add_items($order->id, $items)) {
            qilingshop_clear_group_order_data((int) $order->id);
            qls_shop_order()->delete((int) $order->id);
            wp_send_json_error(['message' => __('订单商品写入失败', 'qilingshop')]);
        }

        if (!$this->maybe_reduce_group_order_stock_on_create((int) $order->id)) {
            qilingshop_clear_group_order_data((int) $order->id);
            qls_shop_order()->delete((int) $order->id);
            wp_send_json_error(['message' => __('库存扣减失败', 'qilingshop')]);
        }
        
        wp_send_json_success([
            'order_no'     => $order_no,
            'order_id'     => $order->id,
            'final_amount' => $final_amount,
            'redirect_url' => add_query_arg([
                'order_no' => $order_no,
                'type'     => 'group_join',
            ], qls_shop_public()->get_page_url('checkout')),
        ]);
    }

    public function ajax_group_checkout() {
        check_ajax_referer('qls_shop_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('请先登录', 'qilingshop')]);
        }

        $order_no = sanitize_text_field(wp_unslash($_POST['order_no'] ?? ''));
        if (empty($order_no)) {
            wp_send_json_error(['message' => __('订单号无效', 'qilingshop')]);
        }

        $order = qls_shop_order()->get_by_order_no($order_no, true);
        if (!$order || intval($order->user_id) !== get_current_user_id() || empty($order->is_group_order)) {
            wp_send_json_error(['message' => __('订单不存在或无权限', 'qilingshop')]);
        }

        if (intval($order->status) !== 0) {
            wp_send_json_error(['message' => __('订单状态异常', 'qilingshop')]);
        }

        $payment_method = $this->normalize_group_payment_method(wp_unslash($_POST['payment_method'] ?? ''));
        if ($payment_method === '') {
            wp_send_json_error(['message' => __('请选择可用的支付方式', 'qilingshop')]);
        }

        $update_data = [
            'payment_method' => $payment_method,
        ];

        $receiver_name = sanitize_text_field(wp_unslash($_POST['receiver_name'] ?? ''));
        $receiver_phone = sanitize_text_field(wp_unslash($_POST['receiver_phone'] ?? ''));
        $receiver_province = sanitize_text_field(wp_unslash($_POST['receiver_province'] ?? ''));
        $receiver_city = sanitize_text_field(wp_unslash($_POST['receiver_city'] ?? ''));
        $receiver_district = sanitize_text_field(wp_unslash($_POST['receiver_district'] ?? ''));
        $receiver_address = sanitize_textarea_field(wp_unslash($_POST['receiver_address'] ?? ''));
        $buyer_remark = sanitize_textarea_field(wp_unslash($_POST['buyer_remark'] ?? ''));

        if ($receiver_name !== '') {
            $update_data['receiver_name'] = $receiver_name;
        }
        if ($receiver_phone !== '') {
            $update_data['receiver_phone'] = $receiver_phone;
        }
        if ($receiver_province !== '') {
            $update_data['receiver_province'] = $receiver_province;
        }
        if ($receiver_city !== '') {
            $update_data['receiver_city'] = $receiver_city;
        }
        if ($receiver_district !== '') {
            $update_data['receiver_district'] = $receiver_district;
        }
        if ($receiver_address !== '') {
            $update_data['receiver_address'] = $receiver_address;
        }
        if ($buyer_remark !== '') {
            $update_data['buyer_remark'] = $buyer_remark;
        }

        $updated = qls_shop_order()->update_contact_fields((int) $order->id, $update_data);
        if (!$updated) {
            wp_send_json_error(['message' => __('更新订单失败', 'qilingshop')]);
        }

        if (floatval($order->final_amount) <= 0) {
            $paid = qls_shop_order()->mark_paid($order_no, '', 'free');
            if (!$paid) {
                wp_send_json_error(['message' => __('订单自动完成失败，请稍后重试', 'qilingshop')]);
            }
            
            // Re-fetch order to get the group_id assigned during mark_paid (handle_group_order_paid)
            $order = qls_shop_order()->get_by_order_no($order_no);
            $redirect = '';
            
            if ($order && !empty($order->group_id)) {
                $redirect = qls_group_public()->get_group_detail_url($order->group_id);
            } else {
                $orders_page_id = get_option('qls_shop_page_orders', 0);
                $redirect = $orders_page_id ? get_permalink($orders_page_id) : qls_shop_public()->get_page_url('shop');
            }
            
            wp_send_json_success(['redirect' => $redirect, 'paid' => true]);
        }

        wp_send_json_success([
            'payment_url' => add_query_arg([
                'pay'   => 'shop',
                'order' => $order_no,
            ], home_url('/')),
        ]);
    }

    /**
     * AJAX: 获取商品的进行中团列表
     */
    public function ajax_get_product_groups() {
        $this->guard_public_read_request('get_product_groups');

        $product_id = intval($_GET['product_id'] ?? 0);
        $limit = min(20, max(1, intval($_GET['limit'] ?? 5)));
        
        if (!$product_id) {
            wp_send_json_error(['message' => __('参数错误', 'qilingshop')]);
        }
        
        $groups = qls_group()->get_active_groups_by_product($product_id, $limit);
        
        // 格式化数据
        $formatted = [];
        foreach ($groups as $group) {
            $formatted[] = [
                'id'             => $group->id,
                'leader_name'    => $group->leader_name,
                'leader_avatar'  => $group->leader_avatar,
                'current_size'   => $group->current_size,
                'target_size'    => $group->target_size,
                'remain_count'   => $group->remain_count,
                'remain_seconds' => $group->remain_seconds,
                'group_price'    => number_format($group->group_price, 2),
            ];
        }
        
        wp_send_json_success(['groups' => $formatted]);
    }

    public function ajax_get_group_stock() {
        check_ajax_referer('qls_shop_nonce', 'nonce');

        $rule_id = intval($_POST['rule_id'] ?? 0);
        $product_id = intval($_POST['product_id'] ?? 0);

        if ($rule_id > 0) {
            $rule = qls_group()->get_rule($rule_id);
        } elseif ($product_id > 0) {
            $rule = qls_group()->get_active_rule_by_product($product_id);
        } else {
            $rule = null;
        }

        if (!$rule) {
            wp_send_json_error(['message' => __('团购不存在', 'qilingshop')]);
        }

        $stock = qls_group()->get_rule_stock($rule->id);
        $activity_end_timestamp = !empty($rule->end_time) ? strtotime($rule->end_time) : 0;
        $activity_ended = $activity_end_timestamp > 0 && $activity_end_timestamp <= current_time('timestamp');
        wp_send_json_success([
            'rule_id'  => $rule->id,
            'stock'    => $stock,
            'is_ended' => $stock <= 0,
            'activity_end' => $activity_end_timestamp,
            'activity_ended' => $activity_ended
        ]);
    }

    /**
     * 获取用户收货地址
     */
    private function get_user_address($user_id, $address_id = 0) {
        global $wpdb;
        
        $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'user_addresses';
        
        if ($address_id > 0) {
            $address = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d AND user_id = %d",
                $address_id,
                $user_id
            ), ARRAY_A);
        } else {
            // 获取默认地址
            $address = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d ORDER BY is_default DESC, id DESC LIMIT 1",
                $user_id
            ), ARRAY_A);
        }
        
        return $address;
    }

    /**
     * 获取团购商品详情页URL
     */
    public function get_group_product_url($product) {
        return qls_shop_public()->get_product_url($product);
    }

    /**
     * 获取团详情页URL
     */
    public function get_group_detail_url($group_id = 0) {
        $url = function_exists('qls_shop_public') ? qls_shop_public()->get_page_url('group-detail') : '';
        if (empty($url)) {
            $url = home_url('/group-detail/');
        }
        
        if ($group_id > 0) {
            $url = add_query_arg('group_id', $group_id, $url);
        }
        
        return $url;
    }

    /**
     * 处理团购页面模板截获
     */
    public function handle_group_page() {
        // 团购中心页
        $center_page_id = get_option('qls_shop_page_group_center');
        if ($center_page_id && is_page($center_page_id)) {
            $per_page = 12; // 默认每页12个
            $page = isset($_GET['gpage']) ? max(1, intval($_GET['gpage'])) : 1;
            
            // 获取团购商品列表
            $products = qls_group()->get_group_products([
                'per_page' => $per_page,
                'page'     => $page,
            ]);
            
            $total = qls_group()->get_group_products_count();
            $total_pages = ceil($total / $per_page);
            
            qls_shop_public()->load_template('group-center', [
                'products' => $products,
                'total_pages' => $total_pages,
                'page' => $page
            ]);
            exit;
        }

        // 团详情页
        $detail_page_id = get_option('qls_shop_page_group_detail');
        // 同时支持 group_id 参数检查，确保是详情页意图
        if ($detail_page_id && is_page($detail_page_id)) {
            $group_id = isset($_GET['group_id']) ? intval($_GET['group_id']) : 0;
            
            // If no group ID specified, try to find user's latest active group
            if (!$group_id && is_user_logged_in()) {
                $user_groups = qls_group()->get_user_groups(get_current_user_id(), [
                    'per_page' => 1,
                    'page'     => 1
                ]);
                
                if (!empty($user_groups)) {
                    $group_id = $user_groups[0]->id;
                }
            }

            $group = null;
            $product = null;
            
            if ($group_id) {
                $group = qls_group()->get_group($group_id, true);
                if ($group) {
                    $product = qls_product()->get($group->product_id, true);
                }
            }
            
            qls_shop_public()->load_template('group-detail', [
                'group' => $group,
                'product' => $product,
                'group_id' => $group_id
            ]);
            exit;
        }
    }

    /**
     * 获取团购中心URL
     */
    public function get_group_center_url() {
        $page_id = get_option('qls_shop_page_group_center', 0);
        if ($page_id) {
            return get_permalink($page_id);
        }
        return home_url('/group-buy/');
    }
}

/**
 * 获取团购前台类实例
 * 
 * @return QLS_Group_Public
 */
function qls_group_public() {
    return QLS_Group_Public::instance();
}
