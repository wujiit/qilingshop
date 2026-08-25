<?php
/**
 * 电商短代码
 * 
 * 注册商城相关短代码
 *
 * @package QilingShop
 * @since   2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_Shop_Shortcodes {

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
        add_shortcode('qls_shop', [$this, 'render_shop']);
        add_shortcode('qls_shop_virtual_home', [$this, 'render_virtual_home']);
        add_shortcode('qls_products', [$this, 'render_products']);
        add_shortcode('qls_all_products', [$this, 'render_all_products']); // New Independent Page
        add_shortcode('qls_new_user_zone', [$this, 'render_new_user_zone']);
        add_shortcode('qls_cart', [$this, 'render_cart']);
        add_shortcode('qls_checkout', [$this, 'render_checkout']);
        add_shortcode('qls_my_orders', [$this, 'render_my_orders']);
        add_shortcode('qls_my_downloads', [$this, 'render_my_downloads']);
        add_shortcode('qls_my_tickets', [$this, 'render_my_tickets']);
        add_shortcode('qls_shop_center', [$this, 'render_shop_center']);
        add_shortcode('qls_assist_center', [$this, 'render_assist_center']);
        add_shortcode('qls_assist_detail', [$this, 'render_assist_detail']);
        add_shortcode('qls_my_assists', [$this, 'render_my_assists']);
    }

    /**
     * 商城首页
     */
    public function render_shop($atts) {
        // Prevent rendering in admin (causes Admin Bar to disappear)
        if (is_admin()) {
            return '<div class="qls-shortcode-placeholder" style="padding:20px;background:#f0f0f1;text-align:center;border:1px dashed #ccc;">[qls_shop] ' . __('商城首页', 'qilingshop') . '</div>';
        }

        $atts = shortcode_atts([
            'limit' => 12,
            'mode'  => '',
            'style' => '',
        ], $atts);

        $mode = sanitize_key((string) ($atts['mode'] ?? ''));
        if ($mode !== 'virtual_card' && !get_option('qls_shop_enabled', true)) {
            return '<div class="qls-shop-maintenance" role="alert" style="min-height:360px;display:flex;align-items:center;justify-content:center;padding:48px 16px;box-sizing:border-box;"><div style="max-width:520px;width:100%;padding:36px 28px;text-align:center;background:#fff;border:1px solid rgba(15,23,42,.08);border-radius:20px;box-shadow:0 18px 50px rgba(15,23,42,.10);"><div style="width:56px;height:56px;margin:0 auto 18px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#eef2ff,#f8fafc);color:#4f46e5;font-size:26px;">!</div><h2 style="margin:0 0 10px;font-size:24px;line-height:1.3;color:#111827;font-weight:700;">' . esc_html__('商城维护中', 'qilingshop') . '</h2><p style="margin:0;color:#6b7280;font-size:15px;line-height:1.7;">' . esc_html__('实物商城暂时关闭，请稍后再来。', 'qilingshop') . '</p></div></div>';
        }

        ob_start();
        
        // 如果是查看全部商品模式
        if (isset($_GET['view']) && $_GET['view'] === 'products') {
            qls_shop_public()->load_template('product-list', $atts);
        } else {
            if ($mode === 'virtual_card') {
                echo $this->render_virtual_home($atts);
            } else {
                qls_shop_public()->load_template('shop-home', $atts);
            }
        }
        
        return ob_get_clean();
    }

    /**
     * 虚拟发卡首页
     */
    public function render_virtual_home($atts) {
        if (is_admin() && !wp_doing_ajax()) {
            return '<div class="qls-shortcode-placeholder" style="padding:20px;background:#f0f0f1;text-align:center;border:1px dashed #ccc;">[qls_shop_virtual_home] ' . __('虚拟发卡首页', 'qilingshop') . '</div>';
        }

        $virtual_home_enabled = true;
        if (function_exists('qls_shop_public') && method_exists(qls_shop_public(), 'is_virtual_home_enabled')) {
            $virtual_home_enabled = qls_shop_public()->is_virtual_home_enabled();
        } else {
            $virtual_home_enabled = (bool) get_option('qls_shop_virtual_home_enabled', false);
        }

        if (!$virtual_home_enabled) {
            if (function_exists('qls_shop_public') && method_exists(qls_shop_public(), 'handle_disabled_virtual_home_response')) {
                return qls_shop_public()->handle_disabled_virtual_home_response('shortcode');
            }

            if (!headers_sent()) {
                status_header(404);
                nocache_headers();
            }
            wp_die(
                esc_html__('虚拟发卡首页已关闭', 'qilingshop'),
                esc_html__('页面不可访问', 'qilingshop'),
                ['response' => 404]
            );
        }

        $atts = shortcode_atts([
            'limit' => 24,
            'style' => '',
        ], $atts);

        ob_start();
        qls_shop_public()->load_template('shop-virtual-home', $atts);
        return ob_get_clean();
    }

    /**
     * 商品列表
     */
    public function render_products($atts) {
        $atts = shortcode_atts([
            'category' => '',
            'limit'    => 12,
            'columns'  => 4,
            'orderby'  => 'id',
            'order'    => 'DESC',
            'points_only' => '',
        ], $atts);

        $args = [
            'status'   => 1,
            'limit'    => intval($atts['limit']),
            'orderby'  => $atts['orderby'],
            'order'    => $atts['order'],
        ];

        $points_only = in_array(strtolower(trim((string) $atts['points_only'])), ['1', 'yes', 'true', 'on'], true);
        if ($points_only) {
            $args['points_payable'] = 1;
            if ($args['orderby'] === 'id') {
                $args['orderby'] = 'points_price';
                $args['order'] = 'ASC';
            }
        }

        if (!empty($atts['category'])) {
            $category = qls_category()->get_by_slug($atts['category']);
            if ($category) {
                $args['category_id'] = $category->id;
            }
        }

        $products = qls_product()->get_list($args);

        ob_start();
        qls_shop_public()->load_template('partials/product-grid', [
            'products' => $products,
            'columns'  => intval($atts['columns']),
        ]);
        return ob_get_clean();
    }

    /**
     * 全部商品页 (独立页面)
     */
    public function render_all_products($atts) {
        if (is_admin()) {
            return '<div class="qls-shortcode-placeholder" style="padding:20px;background:#f0f0f1;text-align:center;border:1px dashed #ccc;">[qls_all_products] ' . __('全部商品', 'qilingshop') . '</div>';
        }

        $atts = shortcode_atts([
            'limit' => 16,
            'points_only' => '',
        ], $atts);

        // 获取筛选参数 (From Query Vars first, then GET)
        $qb_sort = get_query_var('qls_sort');
        $sort = $qb_sort ? $qb_sort : (isset($_GET['sort']) ? sanitize_text_field($_GET['sort']) : 'latest');
        
        $qb_category = get_query_var('qls_category');
        $category_slug = $qb_category ? $qb_category : (isset($_GET['category']) ? sanitize_text_field($_GET['category']) : '');
        
        $keyword = isset($_GET['keyword']) ? sanitize_text_field($_GET['keyword']) : '';
        $paged = max(1, get_query_var('paged') ? get_query_var('paged') : (get_query_var('page') ? get_query_var('page') : 1));
        $points_only = in_array(strtolower(trim((string) $atts['points_only'])), ['1', 'yes', 'true', 'on'], true)
            || (isset($_GET['points']) && sanitize_text_field(wp_unslash($_GET['points'])) !== '0');
        if (in_array($sort, ['points_desc', 'points_asc'], true)) {
            $points_only = true;
        }

        // 构建查询参数
        $args = [
            'status' => 1,
            'limit'  => intval($atts['limit']),
            'offset' => ($paged - 1) * intval($atts['limit']),
            'keyword'=> $keyword,
        ];

        // 排序逻辑
        switch ($sort) {
            case 'sales': // 销量优先
                $args['orderby'] = 'sales_count';
                $args['order'] = 'DESC';
                break;
            case 'hot': // 热门优先
                $args['is_hot'] = 1;
                // $args['orderby'] = 'sales_count'; // 热门也通常按销量或日期排序
                break;
            case 'price_asc': // 价格低到高
                $args['orderby'] = 'min_price';
                $args['order'] = 'ASC';
                break;
            case 'price_desc': // 价格高到低
                $args['orderby'] = 'min_price';
                $args['order'] = 'DESC';
                break;
            case 'points_asc':
                $args['orderby'] = 'points_price';
                $args['order'] = 'ASC';
                break;
            case 'points_desc':
                $args['orderby'] = 'points_price';
                $args['order'] = 'DESC';
                break;
            case 'latest':
            default:
                $args['orderby'] = 'created_at'; // 或 id
                $args['order'] = 'DESC';
                break;
        }

        // 分类处理
        $current_category = null;
        if ($category_slug) {
            $current_category = qls_category()->get_by_slug($category_slug);
            if ($current_category) {
                $args['category_id'] = $current_category->id;
            }
        }

        if ($points_only) {
            $args['points_payable'] = 1;
        }

        // 获取商品
        $products = qls_product()->get_list($args);
        $total_products = qls_product()->get_count($args);
        $total_pages = ceil($total_products / $atts['limit']);

        // 加载模板
        ob_start();
        qls_shop_public()->load_template('all-products', [
            'products'         => $products,
            'current_category' => $current_category,
            'sort'             => $sort,
            'paged'            => $paged,
            'total_pages'      => $total_pages,
            'total_products'   => $total_products,
            'points_only'      => $points_only
        ]);
        return ob_get_clean();
    }

    /**
     * 新人专项页
     */
    public function render_new_user_zone($atts) {
        if (is_admin()) {
            return '<div class="qls-shortcode-placeholder" style="padding:20px;background:#f0f0f1;text-align:center;border:1px dashed #ccc;">[qls_new_user_zone] ' . __('新人专区', 'qilingshop') . '</div>';
        }

        $atts = shortcode_atts([
            'limit' => 12,
            'mode'  => '',
            'style' => '',
        ], $atts);

        $limit = max(1, (int) $atts['limit']);
        $paged = max(1, (int) (
            get_query_var('paged')
                ? get_query_var('paged')
                : (get_query_var('page') ? get_query_var('page') : 1)
        ));
        if (isset($_GET['paged'])) {
            $paged = max(1, (int) $_GET['paged']);
        }

        $args = [
            'status' => 1,
            'new_user_special' => 1,
            'orderby' => 'created_at',
            'order' => 'DESC',
            'limit' => $limit,
            'offset' => ($paged - 1) * $limit,
        ];

        $products = qls_product()->get_list($args);
        $total = qls_product()->get_count($args);
        $total_pages = (int) ceil($total / $limit);

        $current_user_id = (int) get_current_user_id();
        $is_eligible = $current_user_id > 0 ? qls_product()->is_user_eligible_for_new_user_special($current_user_id) : false;

        ob_start();
        qls_shop_public()->load_template('new-user-zone', [
            'products' => $products,
            'total' => $total,
            'paged' => $paged,
            'total_pages' => $total_pages,
            'limit' => $limit,
            'is_eligible' => $is_eligible,
            'is_logged_in' => is_user_logged_in(),
        ]);
        return ob_get_clean();
    }

    /**
     * 购物车
     */
    public function render_cart($atts) {
        if (is_admin()) {
            return '<div class="qls-shortcode-placeholder" style="padding:20px;background:#f0f0f1;text-align:center;border:1px dashed #ccc;">[qls_cart] ' . __('购物车', 'qilingshop') . '</div>';
        }

        ob_start();
        qls_shop_public()->load_template('cart');
        return ob_get_clean();
    }

    /**
     * 结账页面
     */
    public function render_checkout($atts) {
        if (is_admin()) {
            return '<div class="qls-shortcode-placeholder" style="padding:20px;background:#f0f0f1;text-align:center;border:1px dashed #ccc;">[qls_checkout] ' . __('结账页面', 'qilingshop') . '</div>';
        }

        $guest_order_enabled = function_exists('qls_shop_is_guest_order_enabled')
            ? qls_shop_is_guest_order_enabled()
            : (bool) get_option('qls_shop_cart_guest_enabled', true);

        if (!is_user_logged_in() && !$guest_order_enabled) {
            return '<div class="qls-notice warning">' . 
                   sprintf(__('请<a href="%s">登录</a>后继续结账', 'qilingshop'), wp_login_url(get_permalink())) . 
                   '</div>';
        }

        ob_start();
        qls_shop_public()->load_template('checkout');
        return ob_get_clean();
    }

    /**
     * 我的订单
     */
    public function render_my_orders($atts) {
        if (is_admin()) {
            return '<div class="qls-shortcode-placeholder" style="padding:20px;background:#f0f0f1;text-align:center;border:1px dashed #ccc;">[qls_my_orders] ' . __('我的订单', 'qilingshop') . '</div>';
        }

        if (!is_user_logged_in()) {
            return '<div class="qls-notice warning">' . 
                   sprintf(__('请<a href="%s">登录</a>后查看订单', 'qilingshop'), wp_login_url(get_permalink())) . 
                   '</div>';
        }

        $atts = shortcode_atts([
            'limit' => 10,
        ], $atts);

        $user_id = get_current_user_id();
        // 使用订单专属页码参数，避免与 WordPress 页面分页和商品 AJAX 分页冲突。
        $paged = isset($_GET['qls_orders_page']) ? max(1, (int) $_GET['qls_orders_page']) : 1;
        if (!isset($_GET['qls_orders_page']) && isset($_GET['paged'])) {
            $paged = max(1, (int) $_GET['paged']);
        }
        $status = isset($_GET['status']) && $_GET['status'] !== '' ? (int) $_GET['status'] : '';

        $orders = qls_shop_order()->get_user_orders($user_id, [
            'status' => $status,
            'limit'  => intval($atts['limit']),
            'offset' => ($paged - 1) * intval($atts['limit']),
        ]);

        $count_where = [];
        if ($status !== '') {
            $count_where['status'] = $status;
        }
        $total = qls_shop_order()->get_user_orders_count($user_id, $count_where);

        $refunds = [];
        $invoices = [];
        if (!empty($orders) && function_exists('qls_shop_refund')) {
            $order_ids = array_map(function($order) {
                return (int) $order->id;
            }, $orders);
            $refunds = qls_shop_refund()->get_by_orders($order_ids);
        }
        if (!empty($orders) && function_exists('qls_invoice')) {
            $order_ids = isset($order_ids) ? $order_ids : array_map(function($order) {
                return (int) $order->id;
            }, $orders);
            $invoices = qls_invoice()->get_by_orders($order_ids);
        }
        $invoice_titles = function_exists('qls_invoice') ? qls_invoice()->get_titles($user_id) : [];

        ob_start();
        qls_shop_public()->load_template('my-orders', [
            'orders' => $orders,
            'total'  => $total,
            'paged'  => $paged,
            'limit'  => intval($atts['limit']),
            'refunds' => $refunds,
            'invoices' => $invoices,
            'invoice_titles' => $invoice_titles,
        ]);
        return ob_get_clean();
    }

    /**
     * 售后工单。
     */
    public function render_my_tickets($atts) {
        if (is_admin()) {
            return '<div class="qls-shortcode-placeholder" style="padding:20px;background:#f0f0f1;text-align:center;border:1px dashed #ccc;">[qls_my_tickets] ' . __('售后工单', 'qilingshop') . '</div>';
        }

        if (!is_user_logged_in()) {
            return '<div class="qls-notice warning">' .
                   sprintf(__('请<a href="%s">登录</a>后查看工单', 'qilingshop'), wp_login_url(get_permalink())) .
                   '</div>';
        }

        if (!function_exists('qls_shop_ticket')) {
            return '<div class="qls-notice warning">' . __('工单模块不可用', 'qilingshop') . '</div>';
        }

        $atts = shortcode_atts([
            'limit' => 10,
        ], $atts);

        $ticket_manager = qls_shop_ticket();
        $user_id = get_current_user_id();
        $notice = '';
        $error = '';
        $handled_ticket_id = 0;

        $handled = $this->handle_ticket_frontend_action($ticket_manager, $user_id);
        if (!empty($handled['notice'])) {
            $notice = (string) $handled['notice'];
        }
        if (!empty($handled['error'])) {
            $error = (string) $handled['error'];
        }
        if (!empty($handled['ticket_id'])) {
            $handled_ticket_id = absint($handled['ticket_id']);
        }

        $ticket_base_url = qls_shop_public()->get_page_url('my-tickets');
        if ($ticket_base_url === '') {
            $ticket_base_url = get_permalink();
        }

        $view_ticket_id = $handled_ticket_id > 0
            ? $handled_ticket_id
            : (isset($_GET['ticket_id']) ? absint($_GET['ticket_id']) : 0);

        $current_ticket = null;
        $current_messages = [];
        $current_order = null;

        if ($view_ticket_id > 0) {
            $current_ticket = $ticket_manager->get_user_ticket($view_ticket_id, $user_id);
            if ($current_ticket) {
                $current_messages = $ticket_manager->get_messages($view_ticket_id, false);
                $current_order = !empty($current_ticket->order_id)
                    ? $ticket_manager->get_order_context((int) $current_ticket->order_id, $user_id)
                    : null;
            } elseif ($error === '') {
                $error = __('工单不存在或无权查看。', 'qilingshop');
            }
        }

        $status = isset($_GET['status']) && $_GET['status'] !== '' ? (int) $_GET['status'] : '';
        if ($status !== '') {
            $available_statuses = $ticket_manager->get_statuses();
            if (!isset($available_statuses[$status])) {
                $status = '';
            }
        }

        $paged = max(1, (int) (
            get_query_var('paged')
                ? get_query_var('paged')
                : (get_query_var('page') ? get_query_var('page') : 1)
        ));
        if (isset($_GET['paged'])) {
            $paged = max(1, (int) $_GET['paged']);
        }

        $limit = max(1, (int) $atts['limit']);
        $tickets = $ticket_manager->get_list([
            'user_id' => $user_id,
            'status'  => $status,
            'limit'   => $limit,
            'offset'  => ($paged - 1) * $limit,
        ]);
        $total = $ticket_manager->get_count([
            'user_id' => $user_id,
            'status'  => $status,
        ]);
        $counts = $ticket_manager->get_status_counts([
            'user_id' => $user_id,
        ]);
        $order_options = $ticket_manager->get_recent_orders_for_user($user_id, 20);
        $selected_order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qls_ticket_action']) && sanitize_key(wp_unslash($_POST['qls_ticket_action'])) === 'create') {
            $selected_order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : $selected_order_id;
        }

        ob_start();
        qls_shop_public()->load_template('my-tickets', [
            'ticket_manager'    => $ticket_manager,
            'tickets'           => $tickets,
            'total'             => $total,
            'counts'            => $counts,
            'paged'             => $paged,
            'limit'             => $limit,
            'status'            => $status,
            'notice'            => $notice,
            'error'             => $error,
            'current_ticket'    => $current_ticket,
            'current_messages'  => $current_messages,
            'current_order'     => $current_order,
            'order_options'     => $order_options,
            'selected_order_id' => $selected_order_id,
            'ticket_base_url'   => $ticket_base_url,
        ]);
        return ob_get_clean();
    }

    /**
     * 处理前台工单提交。
     *
     * @param QLS_Shop_Ticket $ticket_manager 工单服务。
     * @param int             $user_id        用户 ID。
     * @return array
     */
    private function handle_ticket_frontend_action($ticket_manager, $user_id) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['qls_ticket_action'])) {
            return [];
        }

        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, 'qls_ticket_frontend_action')) {
            return ['error' => __('安全验证失败，请刷新后重试。', 'qilingshop')];
        }

        $action = sanitize_key(wp_unslash($_POST['qls_ticket_action']));

        if ($action === 'create') {
            $attachments = $ticket_manager->collect_uploaded_attachments('ticket_attachments', $user_id, [
                'source' => 'frontend',
                'action' => 'create',
            ]);

            if (is_wp_error($attachments)) {
                return ['error' => $attachments->get_error_message()];
            }

            $result = $ticket_manager->create_ticket($user_id, [
                'type'        => isset($_POST['type']) ? wp_unslash($_POST['type']) : 'other',
                'title'       => isset($_POST['title']) ? wp_unslash($_POST['title']) : '',
                'content'     => isset($_POST['content']) ? wp_unslash($_POST['content']) : '',
                'order_id'    => isset($_POST['order_id']) ? absint($_POST['order_id']) : 0,
                'product_id'  => isset($_POST['product_id']) ? absint($_POST['product_id']) : 0,
                'resource_id' => isset($_POST['resource_id']) ? absint($_POST['resource_id']) : 0,
                'card_id'     => isset($_POST['card_id']) ? absint($_POST['card_id']) : 0,
                'attachments'  => $attachments,
            ]);

            if (is_wp_error($result)) {
                return ['error' => $result->get_error_message()];
            }

            return [
                'notice'    => __('工单已提交，我们会尽快处理。', 'qilingshop'),
                'ticket_id' => (int) $result,
            ];
        }

        if ($action === 'reply') {
            $ticket_id = isset($_POST['ticket_id']) ? absint($_POST['ticket_id']) : 0;
            $message = isset($_POST['message']) ? wp_unslash($_POST['message']) : '';
            $attachments = $ticket_manager->collect_uploaded_attachments('ticket_attachments', $user_id, [
                'source'    => 'frontend',
                'action'    => 'reply',
                'ticket_id' => $ticket_id,
            ]);

            if (is_wp_error($attachments)) {
                return [
                    'error'     => $attachments->get_error_message(),
                    'ticket_id' => $ticket_id,
                ];
            }

            $result = $ticket_manager->reply_ticket($ticket_id, $user_id, $message, $attachments);

            if (is_wp_error($result)) {
                return [
                    'error'     => $result->get_error_message(),
                    'ticket_id' => $ticket_id,
                ];
            }

            return [
                'notice'    => __('回复已提交。', 'qilingshop'),
                'ticket_id' => $ticket_id,
            ];
        }

        return ['error' => __('无效的工单操作。', 'qilingshop')];
    }

    /**
     * 我的下载（仅虚拟商品订单）。
     */
    public function render_my_downloads($atts) {
        if (is_admin()) {
            return '<div class="qls-shortcode-placeholder" style="padding:20px;background:#f0f0f1;text-align:center;border:1px dashed #ccc;">[qls_my_downloads] ' . __('我的下载', 'qilingshop') . '</div>';
        }

        if (!is_user_logged_in()) {
            return '<div class="qls-notice warning">' .
                   sprintf(__('请<a href="%s">登录</a>后查看下载内容', 'qilingshop'), wp_login_url(get_permalink())) .
                   '</div>';
        }

        $atts = shortcode_atts([
            'limit' => 10,
        ], $atts);

        $limit = max(1, (int) $atts['limit']);
        $user_id = get_current_user_id();
        $paged = max(1, (int) (
            get_query_var('paged')
                ? get_query_var('paged')
                : (get_query_var('page') ? get_query_var('page') : 1)
        ));
        if (isset($_GET['paged'])) {
            $paged = max(1, (int) $_GET['paged']);
        }

        $orders = qls_shop_order()->get_user_virtual_orders($user_id, [
            'limit'  => $limit,
            'offset' => ($paged - 1) * $limit,
        ]);
        $total = qls_shop_order()->get_user_virtual_orders_count($user_id);
        $invoices = [];
        if (!empty($orders) && function_exists('qls_invoice')) {
            $order_ids = array_map(function($order) {
                return (int) $order->id;
            }, $orders);
            $invoices = qls_invoice()->get_by_orders($order_ids);
        }

        ob_start();
        qls_shop_public()->load_template('my-downloads', [
            'orders'           => $orders,
            'total'            => $total,
            'paged'            => $paged,
            'limit'            => $limit,
            'invoices'         => $invoices,
            'orders_page_url'  => qls_shop_public()->get_page_url('orders'),
        ]);
        return ob_get_clean();
    }

    /**
     * 商城个人中心
     */
    public function render_shop_center($atts) {
        if (is_admin()) {
            return '<div class="qls-shortcode-placeholder" style="padding:20px;background:#f0f0f1;text-align:center;border:1px dashed #ccc;">[qls_shop_center] ' . __('商城中心', 'qilingshop') . '</div>';
        }

        if (!is_user_logged_in()) {
            return '<div class="qls-notice warning">' . 
                   sprintf(__('请<a href="%s">登录</a>后查看个人中心', 'qilingshop'), wp_login_url(get_permalink())) . 
                   '</div>';
        }

        $user_id = get_current_user_id();
        $db = QLS_Shop_Database::instance();
        
        // 获取有效消费统计：只统计已付款、已发货、已完成订单。
        $paid_statuses = [
            QLS_Shop_Order::STATUS_PAID,
            QLS_Shop_Order::STATUS_SHIPPED,
            QLS_Shop_Order::STATUS_COMPLETED,
        ];
        $total_orders = qls_shop_order()->get_user_orders_count($user_id, [
            'status' => $paid_statuses,
        ]);

        $total_spent = $db->sum('orders', 'final_amount', [
            'user_id' => $user_id,
            'status'  => $paid_statuses,
        ]);

        // 获取地址列表
        $addresses = $db->get_results('user_addresses', [
            'where'   => ['user_id' => $user_id],
            'orderby' => 'is_default',
            'order'   => 'DESC',
        ]);
        $invoice_titles = function_exists('qls_invoice') ? qls_invoice()->get_titles($user_id) : [];

        ob_start();
        qls_shop_public()->load_template('shop-center', [
            'user_id'      => $user_id,
            'total_orders' => $total_orders,
            'total_spent'  => $total_spent,
            'addresses'    => $addresses,
            'invoice_titles' => $invoice_titles,
        ]);
        return ob_get_clean();
    }

    /**
     * 助力中心
     */
    public function render_assist_center($atts) {
        if (is_admin()) {
            return '<div class="qls-shortcode-placeholder" style="padding:20px;background:#f0f0f1;text-align:center;border:1px dashed #ccc;">[qls_assist_center] ' . __('好友助力中心', 'qilingshop') . '</div>';
        }

        $atts = shortcode_atts([
            'limit' => 20,
        ], $atts);

        // 前台大厅兜底：按时间窗口执行到期维护，避免每次访问都触发维护扫描。
        qls_assist()->maybe_process_expirations([
            'activities_limit' => 200,
            'campaigns_limit'  => 200,
        ]);

        $activities = qls_assist()->get_activities([
            'status' => QLS_Assist::ACTIVITY_ENABLED,
            'product_status' => 1,
            'limit' => max(1, (int) $atts['limit']),
            'offset' => 0,
        ]);

        $my_campaigns = [];
        if (is_user_logged_in()) {
            $my_campaigns = qls_assist()->get_campaigns([
                'user_id' => get_current_user_id(),
                'limit' => 20,
                'offset' => 0,
            ]);
        }

        ob_start();
        qls_shop_public()->load_template('assist-center', [
            'activities'  => $activities,
            'my_campaigns'=> $my_campaigns,
        ]);
        return ob_get_clean();
    }

    /**
     * 助力详情
     */
    public function render_assist_detail($atts) {
        if (is_admin()) {
            return '<div class="qls-shortcode-placeholder" style="padding:20px;background:#f0f0f1;text-align:center;border:1px dashed #ccc;">[qls_assist_detail] ' . __('助力详情', 'qilingshop') . '</div>';
        }

        qls_assist()->maybe_process_expirations([
            'activities_limit' => 0,
            'campaigns_limit'  => 200,
        ]);

        $campaign_id = isset($_GET['campaign_id']) ? intval($_GET['campaign_id']) : 0;
        $share_code = isset($_GET['share']) ? sanitize_text_field(wp_unslash($_GET['share'])) : '';
        if ($share_code === '' && isset($_GET['code'])) {
            $share_code = sanitize_text_field(wp_unslash($_GET['code']));
        }

        $campaign = null;
        if ($share_code !== '') {
            $campaign = qls_assist()->get_campaign_by_share_code($share_code);
        } elseif ($campaign_id > 0) {
            $campaign = qls_assist()->get_campaign($campaign_id, true);
        }

        if (!$campaign && is_user_logged_in()) {
            $user_id = get_current_user_id();
            $preferred = [
                QLS_Assist::CAMPAIGN_ONGOING,
                QLS_Assist::CAMPAIGN_READY,
                QLS_Assist::CAMPAIGN_ORDER_PENDING,
            ];
            foreach ($preferred as $status) {
                $items = qls_assist()->get_campaigns([
                    'user_id' => $user_id,
                    'status' => $status,
                    'limit' => 1,
                    'offset' => 0,
                ]);
                if (!empty($items[0]->id)) {
                    $campaign = qls_assist()->get_campaign((int) $items[0]->id, true);
                    break;
                }
            }
            if (!$campaign) {
                $items = qls_assist()->get_campaigns([
                    'user_id' => $user_id,
                    'limit' => 1,
                    'offset' => 0,
                ]);
                if (!empty($items[0]->id)) {
                    $campaign = qls_assist()->get_campaign((int) $items[0]->id, true);
                }
            }
        }

        if (!$campaign) {
            $my_assists_url = qls_shop_public()->get_page_url('my-assists');
            if (is_user_logged_in() && !empty($my_assists_url)) {
                return '<div class="qls-notice warning">' .
                    sprintf(__('暂无可展示的助力活动，可先前往<a href="%s">我的助力</a>查看', 'qilingshop'), esc_url($my_assists_url)) .
                    '</div>';
            }
            return '<div class="qls-notice warning">' . __('助力活动不存在或已失效', 'qilingshop') . '</div>';
        }

        $helper_logs = qls_assist()->get_helper_logs((int) $campaign->id, 100);

        $current_user = get_current_user_id();
        $is_owner = $current_user > 0 && (int) $campaign->user_id === (int) $current_user;
        $can_help = $current_user > 0 && !$is_owner && (int) $campaign->status === QLS_Assist::CAMPAIGN_ONGOING;
        $show_login_help = $current_user <= 0 && (int) $campaign->status === QLS_Assist::CAMPAIGN_ONGOING;
        $can_pay = $is_owner && in_array((int) $campaign->status, [QLS_Assist::CAMPAIGN_READY, QLS_Assist::CAMPAIGN_ORDER_PENDING], true);

        $share_url = add_query_arg('share', rawurlencode((string) $campaign->share_code), qls_shop_public()->get_page_url('assist-detail'));
        $login_help_url = wp_login_url($share_url ?: get_permalink());

        ob_start();
        qls_shop_public()->load_template('assist-detail', [
            'campaign'    => $campaign,
            'helper_logs' => $helper_logs,
            'can_help'    => $can_help,
            'show_login_help' => $show_login_help,
            'login_help_url' => $login_help_url,
            'can_pay'     => $can_pay,
            'is_owner'    => $is_owner,
            'share_url'   => $share_url,
        ]);
        return ob_get_clean();
    }

    /**
     * 我的助力
     */
    public function render_my_assists($atts) {
        if (is_admin()) {
            return '<div class="qls-shortcode-placeholder" style="padding:20px;background:#f0f0f1;text-align:center;border:1px dashed #ccc;">[qls_my_assists] ' . __('我的助力', 'qilingshop') . '</div>';
        }

        if (!is_user_logged_in()) {
            return '<div class="qls-notice warning">' .
                   sprintf(__('请<a href="%s">登录</a>后查看助力记录', 'qilingshop'), wp_login_url(get_permalink())) .
                   '</div>';
        }

        $atts = shortcode_atts([
            'limit' => 20,
        ], $atts);

        qls_assist()->maybe_process_expirations([
            'activities_limit' => 0,
            'campaigns_limit'  => 200,
        ]);

        $status = isset($_GET['status']) && $_GET['status'] !== '' ? intval($_GET['status']) : '';
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $limit = max(1, (int) $atts['limit']);

        $campaigns = qls_assist()->get_campaigns([
            'user_id' => get_current_user_id(),
            'status' => $status === '' ? '' : $status,
            'limit' => $limit,
            'offset' => ($paged - 1) * $limit,
        ]);

        $total = qls_assist()->get_campaigns_count([
            'user_id' => get_current_user_id(),
            'status' => $status === '' ? '' : $status,
        ]);

        ob_start();
        qls_shop_public()->load_template('my-assists', [
            'campaigns' => $campaigns,
            'total' => $total,
            'paged' => $paged,
            'limit' => $limit,
            'status' => $status,
        ]);
        return ob_get_clean();
    }
}

/**
 * 初始化短代码
 */
function qls_shop_shortcodes() {
    return QLS_Shop_Shortcodes::instance();
}
