<?php
/**
 * 电商前台主类
 * 
 * 处理URL重写、资源加载等
 *
 * @package QilingShop
 * @since   2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_Shop_Public {

    /**
     * 单例实例
     */
    private static $instance = null;
    
    /**
     * 是否手动输出了 Header
     */
    private $manually_output_header = false;

    /**
     * 是否为虚拟页面 (商品详情、分类等)
     */
    private $is_virtual_page = false;

    /**
     * 商城页面键（slug 格式）
     *
     * @var string[]
     */
    private static $shop_page_keys = [
        'shop',
        'virtual-home',
        'cart',
        'checkout',
        'orders',
        'my-tickets',
        'shop-center',
        'all-products',
        'new-user-zone',
        'coupon-center',
        'group-center',
        'group-detail',
        'assist-center',
        'assist-detail',
        'my-assists',
        'my-downloads',
        'order-query',
    ];

    /**
     * 当前请求的商城页面识别缓存
     *
     * @var array
     */
    private $shop_page_context_cache = [
        'signature' => '',
        'page_key'  => null,
    ];

    /**
     * 页面配置（页面标识 -> option / shortcode）
     */
    private static $page_registry = [
        'shop' => [
            'options'   => ['qls_shop_page_shop'],
            'shortcode' => 'qls_shop',
        ],
        'virtual_home' => [
            'options'   => ['qls_shop_page_virtual_home'],
            'shortcode' => 'qls_shop_virtual_home',
        ],
        'cart' => [
            'options'   => ['qls_shop_page_cart'],
            'shortcode' => 'qls_cart',
        ],
        'checkout' => [
            'options'   => ['qls_shop_page_checkout'],
            'shortcode' => 'qls_checkout',
        ],
        'orders' => [
            'options'   => ['qls_shop_page_orders'],
            'shortcode' => 'qls_my_orders',
        ],
        'my_tickets' => [
            'options'   => ['qls_shop_page_my_tickets', 'qls_shop_page_my-tickets'],
            'shortcode' => 'qls_my_tickets',
        ],
        'shop_center' => [
            'options'   => ['qls_shop_page_center', 'qls_shop_page_shop-center'],
            'shortcode' => 'qls_shop_center',
        ],
        'all_products' => [
            'options'   => ['qls_shop_page_all_products'],
            'shortcode' => 'qls_all_products',
        ],
        'new_user_zone' => [
            'options'   => ['qls_shop_page_new_user_zone'],
            'shortcode' => 'qls_new_user_zone',
        ],
        'coupon_center' => [
            'options'   => ['qls_shop_page_coupon_center', 'qls_shop_page_coupon-center'],
            'shortcode' => 'qls_coupon_center',
        ],
        'group_center' => [
            'options'   => ['qls_shop_page_group_center', 'qls_shop_page_group-center'],
            'shortcode' => 'qls_group_center',
        ],
        'group_detail' => [
            'options'   => ['qls_shop_page_group_detail', 'qls_shop_page_group-detail'],
            'shortcode' => 'qls_group_detail',
        ],
        'assist_center' => [
            'options'   => ['qls_shop_page_assist_center', 'qls_shop_page_assist-center'],
            'shortcode' => 'qls_assist_center',
        ],
        'assist_detail' => [
            'options'   => ['qls_shop_page_assist_detail', 'qls_shop_page_assist-detail'],
            'shortcode' => 'qls_assist_detail',
        ],
        'my_assists' => [
            'options'   => ['qls_shop_page_my_assists', 'qls_shop_page_my-assists'],
            'shortcode' => 'qls_my_assists',
        ],
        'my_downloads' => [
            'options'   => ['qls_shop_page_my_downloads', 'qls_shop_page_my-downloads'],
            'shortcode' => 'qls_my_downloads',
        ],
        'order_query' => [
            'options'   => ['qls_shop_page_order_query', 'qilingshop_page_order_query'],
            'shortcode' => 'qilingshop_order_query',
        ],
    ];

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
        if (did_action('after_setup_theme')) {
            $this->register_multilingual_routes();
        } else {
            add_action('after_setup_theme', [$this, 'register_multilingual_routes'], 20);
        }
        add_action('init', [$this, 'register_rewrite_rules']);
        add_action('wp', [$this, 'mark_shop_no_cache_flags'], 0);
        add_action('send_headers', [$this, 'send_shop_no_cache_headers'], 0);
        add_filter('wp_headers', [$this, 'filter_shop_no_cache_headers'], 0);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles'], 100); // 延后执行，以便检测主题是否已加载资源
        add_action('wp', [$this, 'retry_pending_cart_merge'], 1);
        add_action('template_redirect', [$this, 'maybe_block_virtual_home_page'], 0);
        add_action('template_redirect', [$this, 'handle_product_page'], 0);
        add_action('template_redirect', [$this, 'handle_payment_redirect']);
        add_filter('redirect_canonical', [$this, 'disable_canonical_for_virtual_shop'], 0, 2);
        add_filter('query_vars', [$this, 'add_query_vars']);
        
        // 登录后合并购物车
        add_action('wp_login', [$this, 'merge_cart_on_login'], 10, 2);
        
        // AJAX：查询物流
        add_action('wp_ajax_qls_shop_get_logistics', [$this, 'ajax_get_logistics']);
        add_action('wp_ajax_nopriv_qls_shop_get_logistics', [$this, 'ajax_get_logistics']);
        
        // AJAX：筛选商品（搜索/排序/分类）
        add_action('wp_ajax_qls_shop_filter_products', [$this, 'ajax_filter_products']);
        add_action('wp_ajax_nopriv_qls_shop_filter_products', [$this, 'ajax_filter_products']);
        
        // SEO 钩子
        add_filter('pre_get_document_title', [$this, 'seo_title'], 20);
        add_action('wp_head', [$this, 'seo_meta_tags'], 5);
        
        // 主题兼容性
        add_filter('qiling_show_page_header', [$this, 'filter_theme_header'], 10, 2);
        add_filter('qiling_show_sidebar', [$this, 'filter_theme_sidebar'], 10, 2);
        add_filter('developer_starter_load_sidebar_css', [$this, 'filter_theme_sidebar_css']);
        add_filter('body_class', [$this, 'filter_shop_body_class']);
    }

    /**
     * AJAX：筛选商品
     */
    public function ajax_filter_products() {
        check_ajax_referer('qls_shop_nonce', 'nonce');
        
        $paged    = isset($_REQUEST['paged']) ? intval($_REQUEST['paged']) : 1;
        $sort     = isset($_REQUEST['sort']) ? sanitize_text_field($_REQUEST['sort']) : 'default';
        $category_slug = isset($_REQUEST['category']) ? sanitize_text_field($_REQUEST['category']) : '';
        $keyword  = isset($_REQUEST['keyword']) ? sanitize_text_field($_REQUEST['keyword']) : '';
        $points_param = isset($_REQUEST['points']) ? strtolower(trim(sanitize_text_field(wp_unslash($_REQUEST['points'])))) : '';
        $points_only = in_array($points_param, ['1', 'yes', 'true', 'on'], true);
        if (in_array($sort, ['points_desc', 'points_asc'], true)) {
            $points_only = true;
        }
        
        // 构建查询参数
        $args = [
            'status' => 1,
            'limit'  => 16,
            'offset' => ($paged - 1) * 16,
            'keyword'=> $keyword,
        ];

        // 排序
        switch ($sort) {
            case 'sales': 
                $args['orderby'] = 'sales_count';
                $args['order'] = 'DESC';
                break;
            case 'hot': 
                $args['is_hot'] = 1;
                break;
            case 'price_asc': 
                $args['orderby'] = 'min_price';
                $args['order'] = 'ASC';
                break;
            case 'price_desc': 
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
            case 'default':
            default:
                $args['orderby'] = 'created_at'; // or id
                $args['order'] = 'DESC';
                break;
        }

        // 分类
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
        $total_pages = ceil($total_products / $args['limit']);

        // 生成 HTML
        ob_start();
        if (!empty($products)) {
            // 渲染商品列表
            foreach ($products as $product) {
                $link = $this->get_product_url($product);
                $thumb = '';
                if (!empty($product->main_image)) {
                        if (is_array($product->main_image)) $thumb = $product->main_image['url'] ?? '';
                        elseif (is_string($product->main_image)) $thumb = $product->main_image;
                }
                if (empty($thumb) && !empty($product->gallery) && is_array($product->gallery)) {
                    $first = reset($product->gallery);
                    if (is_array($first)) $thumb = $first['url'] ?? '';
                    elseif (is_string($first)) $thumb = $first;
                }
                
                $min_price = $product->min_price;
                $max_price = $product->max_price;
                $price_html = ($max_price > $min_price) ? 
                    '<span class="curr">¥' . $min_price . '</span>' : 
                    '<span class="curr">¥' . $min_price . '</span>';
                $points_price_html = '';
                if (!empty($product->points_price) && floatval($product->points_price) > 0) {
                    $points_name = function_exists('qilingshop_get_points_name') ? qilingshop_get_points_name() : __('积分', 'qilingshop');
                    $points_price_html = '<div class="qls-product-points-price">' . sprintf(__('积分价 %1$s %2$s', 'qilingshop'), number_format_i18n(floatval($product->points_price), 0), esc_html($points_name)) . '</div>';
                }
                        
                ?>
                <div class="qls-product-card">
                    <div class="qls-product-image">
                        <a href="<?php echo esc_url($link); ?>">
                            <?php if ($product->is_hot): ?>
                            <span class="qls-badge qls-badge--hot"><?php esc_html_e('热卖', 'qilingshop'); ?></span>
                            <?php endif; ?>
                            <?php if ($thumb): ?>
                            <img src="<?php echo esc_url($thumb); ?>" alt="<?php echo esc_attr($product->title); ?>" loading="lazy">
                            <?php else: ?>
                            <div class="qls-image-placeholder"></div>
                            <?php endif; ?>
                        </a>
                    </div>
                    <div class="qls-product-info">
                        <h3 class="qls-product-title">
                            <a href="<?php echo esc_url($link); ?>"><?php echo esc_html($product->title); ?></a>
                        </h3>
                        <div class="qls-product-meta">
                            <div class="qls-product-price">
                <?php echo $price_html; ?>
                <?php echo $points_price_html; ?>
                            </div>
                            <div class="qls-product-sales">
                                <?php printf(__('销量 %d', 'qilingshop'), $product->sales_count); ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php
            }
        }
        $html = ob_get_clean();

        // 分页 HTML
        $pagination_html = '';
        if ($total_pages > 1) {
            $pagination_html = paginate_links([
                'base'    => '%_%',
                'format'  => '?paged=%#%',
                'current' => $paged,
                'total'   => $total_pages,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
                'type'      => 'list',
            ]);
        }
        
        // 生成页面标题
        $page_title = get_bloginfo('name');
        $sort_labels = [
            'latest'     => __('最新上架', 'qilingshop'),
            'sales'      => __('销量优先', 'qilingshop'),
            'hot'        => __('热门推荐', 'qilingshop'),
            'price_desc' => __('价格从高到低', 'qilingshop'),
            'price_asc'  => __('价格从低到高', 'qilingshop'),
            'points_asc' => __('积分从低到高', 'qilingshop'),
            'points_desc'=> __('积分从高到低', 'qilingshop'),
        ];

        $title_parts = [];
        
        if (isset($sort_labels[$sort])) {
            $title_parts[] = $sort_labels[$sort];
        }
        if ($points_only && !in_array($sort, ['points_desc', 'points_asc'], true)) {
            $title_parts[] = __('支持积分购买', 'qilingshop');
        }
        
        if ($current_category) {
            $title_parts[] = $current_category->name;
        } else {
            // 全部商品页如果不含分类，通常显示页面标题? 
            // 这里简单处理: Sort - Site Name
            // 或者 Sort - All Products - Site Name
            $all_products_page_id = get_option('qls_shop_page_all_products');
            if ($all_products_page_id) {
                $title_parts[] = get_the_title($all_products_page_id);
            }
        }
        
        if (!empty($title_parts)) {
            $page_title = implode(' - ', $title_parts) . ' - ' . $page_title;
        }

        wp_send_json_success([
            'html' => $html,
            'pagination' => $pagination_html,
            'count_html' => $points_only ? sprintf(__('共找到 %d 件支持积分购买的商品', 'qilingshop'), $total_products) : sprintf(__('共找到 %d 件商品', 'qilingshop'), $total_products),
            'page_title' => $page_title
        ]);
    }

    /**
     * AJAX：查询物流
     */
    public function ajax_get_logistics() {
        check_ajax_referer('qls_shop_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(__('请先登录', 'qilingshop'));
        }

        $company = isset($_POST['company']) ? sanitize_text_field($_POST['company']) : '';
        $number = isset($_POST['number']) ? sanitize_text_field($_POST['number']) : '';
        $order_no = isset($_POST['order_no']) ? sanitize_text_field($_POST['order_no']) : '';

        if (empty($company) || empty($number) || empty($order_no)) {
            wp_send_json_error(__('参数不完整', 'qilingshop'));
        }

        if (!class_exists('QLS_Shop_Logistics')) {
            require_once QILINGSHOP_PATH . 'includes/shop/class-qls-shop-logistics.php';
        }

        // 强制校验订单归属，避免通过运单号探测他人物流轨迹。
        $db = QLS_Shop_Database::instance();
        $table_orders = $db->get_table('orders');
        $wpdb = $db->get_wpdb();

        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_orders} WHERE order_no = %s AND user_id = %d LIMIT 1",
            $order_no,
            get_current_user_id()
        ));
        if (!$order) {
            wp_send_json_error(__('订单不存在或无权限', 'qilingshop'));
        }

        $matched = false;
        $matched_company = $company;
        if ((string) $order->tracking_no === (string) $number) {
            if (empty($order->shipping_company) || (string) $order->shipping_company === (string) $company) {
                $matched = true;
                $matched_company = !empty($order->shipping_company) ? (string) $order->shipping_company : $company;
            }
        }

        if (!$matched && function_exists('qls_shipment')) {
            $shipments = qls_shipment()->get_by_order((int) $order->id, false);
            foreach ($shipments as $shipment) {
                $shipment_number = (string) (!empty($shipment->tracking_no) ? $shipment->tracking_no : $shipment->waybill_no);
                if ($shipment_number === '' || $shipment_number !== (string) $number) {
                    continue;
                }

                if (!empty($shipment->shipping_company) && (string) $shipment->shipping_company !== (string) $company) {
                    continue;
                }

                $matched = true;
                $matched_company = !empty($shipment->shipping_company) ? (string) $shipment->shipping_company : $company;
                break;
            }
        }

        if (!$matched) {
            wp_send_json_error(__('物流信息不匹配', 'qilingshop'));
        }

        $phone = '';
        $phone = $order->receiver_phone;

        $trace = qls_shop_logistics()->get_trace($matched_company, $number, $phone);
        
        if (is_wp_error($trace)) {
            wp_send_json_error($trace->get_error_message());
        } else {
            wp_send_json_success($trace);
        }
    }

    /**
     * 注册重写规则
     */
    public function register_rewrite_rules() {
        $product_base = get_option('qls_shop_product_base', 'shop/product');
        $category_base = get_option('qls_shop_category_base', 'shop/category');

        // 商品详情页
        add_rewrite_rule(
            '^' . preg_quote($product_base) . '/([^/]+)/?$',
            'index.php?qls_product=$matches[1]',
            'top'
        );

        // 分类页
        add_rewrite_rule(
            '^' . preg_quote($category_base) . '/([^/]+)/?$',
            'index.php?qls_category=$matches[1]',
            'top'
        );

        // 分类分页
        add_rewrite_rule(
            '^' . preg_quote($category_base) . '/([^/]+)/page/([0-9]+)/?$',
            'index.php?qls_category=$matches[1]&paged=$matches[2]',
            'top'
        );

        // 全部商品页面重写规则（伪静态）
        $page_slug = $this->get_all_products_page_slug();
        if ($page_slug !== '') {
            // 规则：/qls-all-products/category/slug/sales/
            add_rewrite_rule(
                '^' . preg_quote($page_slug) . '/category/([^/]+)/(latest|sales|hot|price_desc|price_asc|points_desc|points_asc)/?$',
                'index.php?pagename=' . $page_slug . '&qls_category=$matches[1]&qls_sort=$matches[2]',
                'top'
            );

            // 规则：/qls-all-products/category/slug/
            add_rewrite_rule(
                '^' . preg_quote($page_slug) . '/category/([^/]+)/?$',
                'index.php?pagename=' . $page_slug . '&qls_category=$matches[1]',
                'top'
            );
            
            // 规则：/qls-all-products/sales/
            add_rewrite_rule(
                '^' . preg_quote($page_slug) . '/(latest|sales|hot|price_desc|price_asc|points_desc|points_asc)/?$',
                'index.php?pagename=' . $page_slug . '&qls_sort=$matches[1]',
                'top'
            );
        }
    }

    /**
     * 获取“全部商品”页面 slug（优先使用缓存 option，缺失时回填一次）。
     *
     * @return string
     */
    private function get_all_products_page_slug() {
        static $cached_slug = null;

        if ($cached_slug !== null) {
            return $cached_slug;
        }

        $cached_slug = sanitize_title((string) get_option('qls_shop_page_all_products_slug', ''));
        if ($cached_slug !== '') {
            return $cached_slug;
        }

        $page_id = (int) get_option('qls_shop_page_all_products', 0);
        if ($page_id <= 0) {
            $cached_slug = '';
            return $cached_slug;
        }

        $slug = sanitize_title((string) get_post_field('post_name', $page_id));
        if ($slug !== '') {
            update_option('qls_shop_page_all_products_slug', $slug);
        }

        $cached_slug = $slug;
        return $cached_slug;
    }

    /**
     * 同步“全部商品”页面 slug 到 option（供 rewrite 读取，避免每次 init 查页面）。
     *
     * @param int $page_id 页面 ID
     * @return void
     */
    private function sync_all_products_page_slug($page_id) {
        $page_id = (int) $page_id;
        if ($page_id <= 0) {
            delete_option('qls_shop_page_all_products_slug');
            return;
        }

        $slug = sanitize_title((string) get_post_field('post_name', $page_id));
        if ($slug === '') {
            delete_option('qls_shop_page_all_products_slug');
            return;
        }

        update_option('qls_shop_page_all_products_slug', $slug);
    }

    /**
     * 添加查询变量
     */
    public function add_query_vars($vars) {
        $vars[] = 'qls_product';
        $vars[] = 'qls_category';
        $vars[] = 'qls_sort';
        return $vars;
    }

    /**
     * 虚拟发卡首页是否启用。
     *
     * 兼容上一版“商城首页模式=虚拟发卡首页”的配置：新开关未保存时沿用旧值。
     *
     * @return bool
     */
    public function is_virtual_home_enabled() {
        $enabled = get_option('qls_shop_virtual_home_enabled', '__qls_missing__');
        if ($enabled === '__qls_missing__') {
            return sanitize_key((string) get_option('qls_shop_home_mode', 'decoration')) === 'virtual_card';
        }

        return (bool) $enabled;
    }

    /**
     * 关闭虚拟发卡首页后，专用页面和专用短代码页面均返回 404。
     *
     * @return void
     */
    public function maybe_block_virtual_home_page() {
        if (is_admin() || wp_doing_ajax() || $this->is_virtual_home_enabled()) {
            return;
        }

        if (!$this->is_virtual_home_request()) {
            return;
        }

        $this->handle_disabled_virtual_home_response('template_redirect');
    }

    /**
     * 统一处理已关闭的虚拟发卡首页访问。
     *
     * @param string $context 响应来源：template_redirect / shortcode。
     * @return string
     */
    public function handle_disabled_virtual_home_response($context = 'template_redirect') {
        $context = sanitize_key((string) $context);

        global $wp_query;
        if ($wp_query instanceof WP_Query) {
            $wp_query->set_404();
        }

        if (!headers_sent()) {
            status_header(404);
            nocache_headers();
        }

        /**
         * 短代码默认也按“关闭后不可访问”处理，避免动态渲染时返回 200。
         *
         * @param bool   $fail_closed 是否直接终止并返回 404。
         * @param string $context     响应来源。
         */
        $fail_closed = (bool) apply_filters('qls_shop_disabled_virtual_home_fail_closed', true, $context);

        if ($context !== 'shortcode' || $fail_closed) {
            if ($context !== 'shortcode') {
                $template = function_exists('get_404_template') ? get_404_template() : '';
                if ($template !== '') {
                    include $template;
                    exit;
                }
            }

            wp_die(
                esc_html__('虚拟发卡首页已关闭', 'qilingshop'),
                esc_html__('页面不可访问', 'qilingshop'),
                ['response' => 404]
            );
        }

        return '<div class="qls-notice warning" role="alert">' . esc_html__('虚拟发卡首页已关闭', 'qilingshop') . '</div>';
    }

    /**
     * 判断当前请求是否命中虚拟发卡首页。
     *
     * @return bool
     */
    private function is_virtual_home_request() {
        if ($this->get_current_shop_page_key() === 'virtual_home') {
            return true;
        }

        $current_id = $this->resolve_current_page_id();
        if ($current_id > 0 && $current_id === (int) get_option('qls_shop_page_virtual_home', 0)) {
            return true;
        }

        global $post;
        return $this->post_contains_virtual_home_shortcode($post);
    }

    /**
     * 判断页面内容是否包含虚拟发卡首页短代码。
     *
     * @param WP_Post|null $post 页面对象
     * @return bool
     */
    private function post_contains_virtual_home_shortcode($post) {
        if (!is_a($post, 'WP_Post')) {
            return false;
        }

        $content = (string) $post->post_content;
        if ($content === '') {
            return false;
        }

        if (has_shortcode($content, 'qls_shop_virtual_home')) {
            return true;
        }

        return has_shortcode($content, 'qls_shop')
            && (bool) preg_match('/\[qls_shop[^\]]*\bmode=(["\']?)virtual_card\1/i', $content);
    }

    /**
     * 处理商品页面
     */
    public function handle_product_page() {
        // 若当前为全部商品页，则接管页面渲染
        $all_products_id = get_option('qls_shop_page_all_products');
        if (is_page($all_products_id)) {
            // 使用自定义模板，绕过主题限制
            $this->is_virtual_page = true; // 标记为虚拟页面，防止资源误加载
            $this->load_template('all-products');
            exit;
        }

        $product_slug = get_query_var('qls_product');
        $category_slug = get_query_var('qls_category');

        if (!$product_slug && !$category_slug) {
            $fallback_match = $this->resolve_virtual_request_from_current_path();

            if (!empty($fallback_match['product_slug'])) {
                $product_slug = $fallback_match['product_slug'];
                $this->set_virtual_query_var('qls_product', $product_slug);
            } elseif (!empty($fallback_match['category_slug'])) {
                $category_slug = $fallback_match['category_slug'];
                $this->set_virtual_query_var('qls_category', $category_slug);
                if (!empty($fallback_match['paged'])) {
                    $this->set_virtual_query_var('paged', (int) $fallback_match['paged']);
                }
            }
        }

        if ($product_slug) {
            $product = qls_product()->get_by_slug($product_slug);
            
            if (!$product || $product->status != 1) {
                global $wp_query;
                $wp_query->set_404();
                status_header(404);
                return;
            }

            $this->mark_virtual_request_as_resolved();

            // 增加浏览量
            qls_product()->increment_view_count($product->id);

            // 标记为虚拟页面
            $this->is_virtual_page = true;
            // 商品虚拟详情页由商城插件统一输出 SEO，避免主题全局 SEO 描述重复
            $this->detach_theme_seo_meta_tags_for_virtual_shop();

            // 加载模板
            $this->load_template('product-single', ['product' => $product]);
            exit;
        }

        if ($category_slug) {
            $category = qls_category()->get_by_slug($category_slug);
            
            if (!$category || !$category->status) {
                global $wp_query;
                $wp_query->set_404();
                status_header(404);
                return;
            }

            $this->mark_virtual_request_as_resolved();
            $this->is_virtual_page = true;
            // 分类虚拟页的 meta 由商城插件输出，避免重复注入
            $this->detach_theme_seo_meta_tags_for_virtual_shop();

            $this->load_template('product-list', [
                'category' => $category,
                'is_category' => true,
            ]);
            exit;
        }
    }

    /**
     * 在商城虚拟页移除主题默认 SEO meta 输出，避免全局描述覆盖/重复
     */
    private function detach_theme_seo_meta_tags_for_virtual_shop() {
        $is_virtual_shop = (bool) get_query_var('qls_product') || (bool) get_query_var('qls_category');
        if (!$is_virtual_shop) {
            return;
        }

        if (empty($GLOBALS['wp_filter']['wp_head']) || !($GLOBALS['wp_filter']['wp_head'] instanceof WP_Hook)) {
            return;
        }

        $callbacks = $GLOBALS['wp_filter']['wp_head']->callbacks;
        foreach ($callbacks as $priority => $entries) {
            foreach ($entries as $entry) {
                $fn = $entry['function'] ?? null;
                if (!is_array($fn) || !isset($fn[0], $fn[1]) || !is_object($fn[0])) {
                    continue;
                }

                $class_name = get_class($fn[0]);
                $method = (string) $fn[1];
                $is_theme_seo_manager = $class_name === 'Developer_Starter\\SEO\\SEO_Manager'
                    || (bool) preg_match('/(^|\\\\)SEO_Manager$/', $class_name);
                $theme_seo_methods = ['output_meta_tags', 'output_schema', 'output_hreflang'];

                if ($is_theme_seo_manager && in_array($method, $theme_seo_methods, true)) {
                    remove_action('wp_head', $fn, (int) $priority);
                }
            }
        }
    }

    /**
     * 商品/分类虚拟路由命中时，关闭 WordPress canonical 跳转。
     *
     * 防止 rewrite 尚未刷新、多语言前缀包裹时被错误跳回首页。
     *
     * @param string|false $redirect_url 目标 canonical 地址。
     * @param string       $requested_url 当前请求地址。
     * @return string|false
     */
    public function disable_canonical_for_virtual_shop($redirect_url, $requested_url) {
        unset($requested_url);

        if (is_admin() || wp_doing_ajax()) {
            return $redirect_url;
        }

        if (get_query_var('qls_product') || get_query_var('qls_category')) {
            return false;
        }

        $match = $this->resolve_virtual_request_from_current_path();
        if (!empty($match['product_slug']) || !empty($match['category_slug'])) {
            return false;
        }

        return $redirect_url;
    }

    /**
     * 处理支付跳转
     */
    public function handle_payment_redirect() {
        if (isset($_GET['pay']) && $_GET['pay'] === 'shop' && isset($_GET['order'])) {
            $order_no = sanitize_text_field($_GET['order']);
            
            // 获取订单 (包含商品明细以生成标题)
            $order = qls_shop_order()->get_by_order_no($order_no, true);
            if (!$order) {
                wp_die(__('订单不存在', 'qilingshop'));
            }

            // 用户单仅允许订单本人继续支付，防止通过支付跳转参数越权拉起他人订单。
            $order_user_id = isset($order->user_id) ? (int) $order->user_id : 0;
            if ($order_user_id > 0) {
                if (!is_user_logged_in()) {
                    wp_die(__('请先登录订单所属账号后再支付', 'qilingshop'));
                }
                if ((int) get_current_user_id() !== $order_user_id) {
                    wp_die(__('无权操作该订单', 'qilingshop'));
                }
            }

            // 检查支付状态
            if ($order->status == 1) { // 已支付
                $redirect_url = $this->get_order_completion_redirect_url($order_no, $order);
                
                // Check if it's a group order (Flag OR legacy meta existence)
                $group_order_option = qilingshop_get_group_order_data($order->id);
                $is_group = !empty($order->is_group_order) || !empty($group_order_option);

                if ($is_group && $order_user_id > 0 && function_exists('qls_group_public')) {
                    $group_id = !empty($order->group_id) ? $order->group_id : 0;
                    
                    // 兜底方式一：查询数据库
                    if (empty($group_id)) {
                        global $wpdb;
                        $prefix = defined('QLS_SHOP_TABLE_PREFIX') ? QLS_SHOP_TABLE_PREFIX : 'qls_shop_';
                        $table_members = $wpdb->prefix . $prefix . 'group_members';
                        $group_id = $wpdb->get_var($wpdb->prepare("SELECT group_id FROM {$table_members} WHERE order_id = %d", $order->id));
                    }

                    // 兜底方式二：修复并发状态
                    if (empty($group_id) && $group_order_option) {
                        if (!empty($group_order_option['is_leader'])) {
                            // Leader: Create Group
                            $rule_id = $group_order_option['rule_id'];
                            $new_group_id = qls_group()->create_group($rule_id, $order->user_id, $order->id);
                            if ($new_group_id) {
                                $group_id = $new_group_id;
                            }
                        } else {
                            // Member: Join Group
                            $target_group_id = $group_order_option['group_id'];
                            $joined = qls_group()->join_group($target_group_id, $order->user_id, $order->id);
                            if (!empty($joined['success'])) {
                                $group_id = $target_group_id;
                            }
                        }

                        // If self-healing succeeded, update DB and cleanup
                        if (!empty($group_id)) {
                            global $wpdb;
                            $table_orders = $wpdb->prefix . (defined('QLS_SHOP_TABLE_PREFIX') ? QLS_SHOP_TABLE_PREFIX : 'qls_shop_') . 'orders';
                            $wpdb->update($table_orders, ['group_id' => $group_id, 'is_group_order' => 1], ['id' => $order->id]);
                            qilingshop_clear_group_order_data($order->id);
                        }
                    }

                    $redirect_url = qls_group_public()->get_group_detail_url($group_id);
                }
                
                wp_safe_redirect($redirect_url);
                exit;
            }

            // 扫码支付：直接进入扫码页，避免中间 create_payment 链路导致仅提交订单
            $payment_method = sanitize_key((string) ($order->payment_method ?? ''));
            if ($payment_method === 'alipay_qr') {
                $pay_url = qilingshop_get_payment_entry_url('alipay', [
                    'order'  => (string) $order->order_no,
                    'method' => 'f2f',
                ]);
                wp_redirect($pay_url);
                exit;
            }
            if ($payment_method === 'wechat') {
                $pay_url = qilingshop_get_payment_entry_url('wechat', [
                    'order' => (string) $order->order_no,
                ]);
                wp_redirect($pay_url);
                exit;
            }

            // 构建支付参数
            $gateway = $order->payment_method;
            $extra = [];

            if ($gateway === 'alipay_qr') {
                $gateway = 'alipay';
                $extra['method'] = 'f2f'; // 强制当面付
            } elseif ($gateway === 'alipay') {
                $extra['method'] = 'page'; // 强制电脑网站支付
            }

            // 生成订单标题
            $subject = get_option('qilingshop_fixed_order_title');
            if (empty($subject)) {
                 if (!empty($order->items)) {
                     $first_item = $order->items[0];
                     $subject = $first_item->product_title;
                     if (count($order->items) > 1) {
                         $subject .= ' ' . sprintf(__('等%d件商品', 'qilingshop'), count($order->items));
                     }
                 } else {
                     $subject = get_bloginfo('name') . ' - ' . __('商城订单', 'qilingshop');
                 }
            }
            $extra['subject'] = $subject;

            // 发起支付
            $order_type = !empty($order->is_group_order) ? 'group' : 'order';
            $result = QilingShop_Payment::instance()->create_payment(
                $order_type,
                $order->order_no,
                $order->final_amount,
                $gateway,
                $extra
            );

            if ($result['success']) {
                if (isset($result['type']) && $result['type'] === 'qrcode') {
                    // 二维码支付：跳转到标准支付入口，由统一分发加载兼容支付页。
                    if (!empty($result['pay_url'])) {
                        wp_redirect($result['pay_url']);
                        exit;
                    }
                } elseif (!empty($result['pay_url'])) {
                    // 跳转支付
                    wp_redirect($result['pay_url']);
                    exit;
                }
                
                wp_die(__('支付初始化失败，未获取到支付链接', 'qilingshop'));
            } else {
                wp_die($result['message']);
            }
        }
    }

    /**
     * 加载模板
     */
    public function load_template($template, $args = []) {
        extract($args);
        
        $theme_template = function_exists('locate_template')
            ? locate_template(['qilingshop/shop/' . $template . '.php'], false, false)
            : '';
        
        if ($theme_template !== '' && file_exists($theme_template)) {
            include $theme_template;
        } else {
            include QILINGSHOP_PATH . 'templates/shop/' . $template . '.php';
        }
    }

    /**
     * 判断当前是否为商城相关页面
     */
    public function is_shop_page() {
        if (is_admin()) {
            return false;
        }

        return $this->get_current_shop_page_key() !== '';
    }

    /**
     * 获取当前商城页面标识（用于按需加载静态资源）
     *
     * @return string
     */
    private function get_current_shop_page_key() {
        if (is_admin()) {
            return '';
        }

        $signature = $this->build_shop_page_context_signature();
        if (
            $this->shop_page_context_cache['signature'] === $signature
            && $this->shop_page_context_cache['page_key'] !== null
        ) {
            return (string) $this->shop_page_context_cache['page_key'];
        }

        $page_key = '';
        $current_id = $this->resolve_current_page_id();
        $all_products_page_id = (int) get_option('qls_shop_page_all_products', 0);

        // “全部商品”页带分类筛选时（/all-products/category/{slug}）也应视为 all_products，
        // 否则会误判为 category 导致页面专属样式未加载。
        if ($all_products_page_id > 0 && $current_id > 0 && $current_id === $all_products_page_id) {
            $page_key = 'all_products';
        } elseif (get_query_var('qls_product')) {
            $page_key = 'product';
        } elseif (get_query_var('qls_category')) {
            $page_key = 'category';
        } else {
            $page_key = $this->get_shop_page_key_from_shortcode_context($current_id);

            if ($page_key === '' && $current_id > 0) {
                foreach (self::$shop_page_keys as $shop_page_key) {
                    $page_id = $this->get_page_id($shop_page_key);
                    if ($page_id && $current_id === (int) $page_id) {
                        $page_key = str_replace('-', '_', (string) $shop_page_key);
                        break;
                    }
                }

                if ($page_key === '') {
                    $task_center_page_id = (int) get_option('qilingshop_task_center_page_id', 0);
                    if ($task_center_page_id > 0 && $current_id === $task_center_page_id) {
                        $page_key = 'task_center';
                    }
                }
            }
        }

        $this->shop_page_context_cache['signature'] = $signature;
        $this->shop_page_context_cache['page_key'] = $page_key;

        return $page_key;
    }

    /**
     * 从当前页面内容中的商城短代码识别商城上下文。
     *
     * 允许用户把 [qls_shop_virtual_home] 放在非专用登记页面上时，也加载商城样式、
     * 主题兼容覆盖和 body class。
     *
     * @param int $current_id 当前页面 ID。
     * @return string
     */
    private function get_shop_page_key_from_shortcode_context($current_id = 0) {
        $post_for_context = null;
        $current_id = (int) $current_id;
        if ($current_id > 0) {
            $post_for_context = get_post($current_id);
        }

        if (!is_a($post_for_context, 'WP_Post')) {
            global $post;
            if ($post instanceof WP_Post) {
                $post_for_context = $post;
            }
        }

        if (!is_a($post_for_context, 'WP_Post')) {
            return '';
        }

        if ($this->post_contains_virtual_home_shortcode($post_for_context)) {
            return 'virtual_home';
        }

        $content = (string) $post_for_context->post_content;
        if ($content === '') {
            return '';
        }

        foreach (self::$page_registry as $registry_key => $config) {
            if ($registry_key === 'virtual_home') {
                continue;
            }

            $shortcode = isset($config['shortcode']) ? sanitize_key((string) $config['shortcode']) : '';
            if ($shortcode !== '' && has_shortcode($content, $shortcode)) {
                return (string) $registry_key;
            }
        }

        return '';
    }

    /**
     * 构建商城页面识别签名（用于请求级缓存）
     *
     * @return string
     */
    private function build_shop_page_context_signature() {
        return implode('|', [
            (string) is_admin(),
            (string) get_query_var('qls_product'),
            (string) get_query_var('qls_category'),
            (string) $this->resolve_current_page_id(),
        ]);
    }

    /**
     * 获取当前上下文页面 ID
     *
     * @return int
     */
    private function resolve_current_page_id() {
        $current_id = (int) get_queried_object_id();
        if ($current_id <= 0) {
            $current_id = (int) get_the_ID();
        }
        if ($current_id <= 0) {
            global $post;
            if ($post instanceof WP_Post) {
                $current_id = (int) $post->ID;
            }
        }

        return $current_id > 0 ? $current_id : 0;
    }

    /**
     * 为商城页面标记防缓存常量（兼容缓存插件）
     *
     * @return void
     */
    public function mark_shop_no_cache_flags() {
        if (!$this->is_shop_page()) {
            return;
        }

        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (!defined('DONOTCACHEMINIFY')) {
            define('DONOTCACHEMINIFY', true);
        }
        if (!defined('DONOTCACHEDB')) {
            define('DONOTCACHEDB', true);
        }
        if (!defined('DONOTCACHEOBJECT')) {
            define('DONOTCACHEOBJECT', true);
        }
    }

    /**
     * 为商城页面发送防缓存响应头
     *
     * @return void
     */
    public function send_shop_no_cache_headers() {
        if (!$this->is_shop_page()) {
            return;
        }

        nocache_headers();
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0, s-maxage=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Accel-Expires: 0');
        header('Surrogate-Control: no-store');
        header('CDN-Cache-Control: no-store');
        header('Vary: Cookie, Authorization');
        header('X-QilingShop-No-Cache: 1');
    }

    /**
     * 统一修正商城页面头部（处理其他插件覆写情况）
     *
     * @param array $headers
     * @return array
     */
    public function filter_shop_no_cache_headers($headers) {
        if (!$this->is_shop_page()) {
            return $headers;
        }

        $headers['Cache-Control'] = 'private, no-store, no-cache, must-revalidate, max-age=0, s-maxage=0';
        $headers['Pragma'] = 'no-cache';
        $headers['Expires'] = '0';
        $headers['X-Accel-Expires'] = '0';
        $headers['Surrogate-Control'] = 'no-store';
        $headers['CDN-Cache-Control'] = 'no-store';
        $headers['X-QilingShop-No-Cache'] = '1';

        $vary_parts = ['Cookie', 'Authorization'];
        if (isset($headers['Vary']) && $headers['Vary'] !== '') {
            $existing = array_map('trim', explode(',', (string) $headers['Vary']));
            $vary_parts = array_merge($vary_parts, $existing);
        }
        $headers['Vary'] = implode(', ', array_unique(array_filter($vary_parts)));

        return $headers;
    }

    /**
     * 加载样式
     */
    public function enqueue_styles() {
        if (is_admin()) return;
        
        // 只在商城页面加载
        if (!$this->is_shop_page()) {
            return;
        }

        // 使用统一的插件版本获取函数，确保一键刷新功能生效
        $assets_version = function_exists('qilingshop_get_assets_version') ? qilingshop_get_assets_version() : (defined('QILINGSHOP_VERSION') ? QILINGSHOP_VERSION : '2.0.2');

        wp_enqueue_style(
            'qls-shop-modern',
            QILINGSHOP_URL . 'static/shop/css/shop-modern.css',
            [],
            $assets_version
        );

        global $post;
        $current_page_key = $this->get_current_shop_page_key();
        $is_virtual_home_context = $current_page_key === 'virtual_home'
            || $this->post_contains_virtual_home_shortcode($post);

        if ($is_virtual_home_context && $this->is_virtual_home_enabled()) {
            $virtual_home_version = (string) $assets_version;
            $virtual_home_css = QILINGSHOP_PATH . 'static/shop/css/shop-virtual-home.css';
            if (file_exists($virtual_home_css)) {
                $virtual_home_version .= '.' . (string) filemtime($virtual_home_css);
            }

            wp_enqueue_style(
                'qls-shop-virtual-home',
                QILINGSHOP_URL . 'static/shop/css/shop-virtual-home.css',
                ['qls-shop-modern'],
                $virtual_home_version
            );
        }

        $assist_page_keys = ['assist_center', 'assist_detail', 'my_assists'];
        if (in_array($current_page_key, $assist_page_keys, true)) {
            wp_enqueue_style(
                'qls-shop-assist',
                QILINGSHOP_URL . 'static/shop/css/shop-assist.css',
                ['qls-shop-modern'],
                $assets_version
            );
        }

        // 新人专项样式仅在新人专区和商品详情页按需加载（含商品详情中的新人价提示）
        if ($current_page_key === 'new_user_zone' || $current_page_key === 'product') {
            wp_enqueue_style(
                'qls-shop-new-user',
                QILINGSHOP_URL . 'static/shop/css/shop-new-user.css',
                ['qls-shop-modern'],
                $assets_version
            );
        }

        if ($current_page_key === 'all_products') {
            wp_enqueue_style(
                'qls-shop-all-products',
                QILINGSHOP_URL . 'static/shop/css/shop-all-products.css',
                ['qls-shop-modern'],
                $assets_version
            );
        }

        if ($current_page_key === 'checkout') {
            wp_enqueue_style(
                'qls-shop-coupon',
                QILINGSHOP_URL . 'static/shop/css/shop-coupon.css',
                ['qls-shop-modern'],
                $assets_version
            );
        }

        if ($current_page_key === 'coupon_center') {
            wp_enqueue_style(
                'qls-shop-coupon-center',
                QILINGSHOP_URL . 'static/shop/css/shop-coupon-center.css',
                ['qls-shop-modern'],
                $assets_version
            );
        }

        // 加载 Swiper
        // 逻辑重构：严格限制加载页面，并确保自定义CDN时版本号正确
        $should_load_swiper = false;

        // 1. 基础环境检查
        $is_valid_context = !$this->is_virtual_page 
                         && empty(get_query_var('qls_product')) 
                         && empty(get_query_var('qls_category')) 
                         && is_a($post, 'WP_Post');

        if ($is_valid_context) {
            // 2. 必须包含 [qls_shop] 简码
            if (!$is_virtual_home_context && has_shortcode($post->post_content, 'qls_shop')) {
                $should_load_swiper = true;
            }
        }

        if ($should_load_swiper) {
            // 默认配置
            $default_css = QILINGSHOP_URL . 'static/shop/css/swiper-bundle.min.css';
            $default_js  = QILINGSHOP_URL . 'static/shop/js/swiper-bundle.min.js';
            $default_js_path = QILINGSHOP_PATH . 'static/shop/js/swiper-bundle.min.js';
            $default_ver = file_exists($default_js_path)
                ? $assets_version . '.' . (string) filemtime($default_js_path)
                : $assets_version;
            
            $swiper_css = $default_css;
            $swiper_js  = $default_js;
            $swiper_ver = $default_ver;
    
            // 获取主题自定义配置
            if (function_exists('developer_starter_get_option')) {
                $custom_css = developer_starter_get_option('swiper_css_url', '');
                $custom_js  = developer_starter_get_option('swiper_js_url', '');

                if (!empty($custom_css)) $swiper_css = $custom_css;
                if (!empty($custom_js))  $swiper_js  = $custom_js;
            }

            // 强制使用自定义版本号，避免缓存问题
            if ($swiper_js !== $default_js || $swiper_css !== $default_css) {
                $swiper_ver = 'theme-custom';
            }

            $swiper_style_loaded = wp_style_is('swiper', 'enqueued') || wp_style_is('qiling-swiper', 'enqueued') || wp_style_is('qls-swiper', 'enqueued');
            $swiper_script_loaded = wp_script_is('swiper', 'enqueued') || wp_script_is('qiling-swiper', 'enqueued') || wp_script_is('qls-swiper', 'enqueued');

            // 执行加载
            if (!$swiper_style_loaded) {
                wp_enqueue_style('qls-swiper', $swiper_css, [], $swiper_ver);
            }
            if (!$swiper_script_loaded) {
                wp_enqueue_script('qls-swiper', $swiper_js, [], $swiper_ver, true);
            }
        }

        // 保持 WordPress 原生 jquery 句柄，避免与主题/其他插件的依赖关系冲突。
        if (!is_admin()) {
            wp_enqueue_script('jquery');
        }

        $frontend_script_version = (string) $assets_version;
        $frontend_script_path = QILINGSHOP_PATH . 'static/shop/js/shop-frontend.js';
        if (file_exists($frontend_script_path)) {
            $frontend_script_version .= '.' . (string) filemtime($frontend_script_path);
        }

        wp_enqueue_script(
            'qls-shop-frontend',
            QILINGSHOP_URL . 'static/shop/js/shop-frontend.js',
            ['jquery'],
            $frontend_script_version,
            true
        );

        $default_payment = 'alipay';
        if (class_exists('QilingShop_Payment')) {
            $enabled_gateways = array_keys(QilingShop_Payment::instance()->get_enabled_gateways());
            if (!empty($enabled_gateways)) {
                $enabled_gateways = array_values(array_map('sanitize_key', $enabled_gateways));
                $enabled_gateways = array_values(array_filter($enabled_gateways, function($gateway) {
                    return $gateway !== 'wechat_miniapp';
                }));
                if (in_array('alipay', $enabled_gateways, true)) {
                    $default_payment = 'alipay';
                } elseif (!empty($enabled_gateways)) {
                    $default_payment = (string) $enabled_gateways[0];
                }
            }
        }

        wp_localize_script('qls-shop-frontend', 'qlsShop', [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('qls_shop_nonce'),
            'homeUrl'   => $this->translate_frontend_internal_url(home_url('/')),
            'cartUrl'   => $this->get_page_url('cart'),
            'checkoutUrl' => $this->get_page_url('checkout'),
            'assistDetailUrl' => $this->get_page_url('assist-detail'),
            'defaultPayment' => $default_payment,
            'showStock' => (bool) get_option('qls_shop_show_stock', true),
            'isLoggedIn' => is_user_logged_in(),
            'priceLoginRequired' => (bool) get_option('qls_shop_price_login_required', false),
            'i18n'      => [
                'add_to_cart' => __('加入购物车', 'qilingshop'),
                'adding'      => __('添加中...', 'qilingshop'),
                'added'       => __('已添加', 'qilingshop'),
                'view_cart'   => __('查看购物车', 'qilingshop'),
                'error'       => __('操作失败', 'qilingshop'),
                'confirm_remove' => __('确定要移除吗？', 'qilingshop'),
                'loading'     => __('加载中...', 'qilingshop'),
                'load_failed' => __('加载失败', 'qilingshop'),
                'network_error' => __('网络错误，请重试', 'qilingshop'),
                'login_required' => __('请先登录', 'qilingshop'),
                'select_product_spec' => __('请选择商品规格', 'qilingshop'),
                'confirm_delete_title' => __('确认删除', 'qilingshop'),
                'confirm_remove_product' => __('确定要删除该商品吗？', 'qilingshop'),
                'confirm_remove_checkout' => __('确定要从购物车中删除该商品吗？', 'qilingshop'),
                'delete_failed' => __('删除失败', 'qilingshop'),
                'coupon_min_amount' => __('满{currency}{amount}可用', 'qilingshop'),
                'coupon_no_threshold' => __('无门槛', 'qilingshop'),
                'no_coupons' => __('暂无可用优惠券', 'qilingshop'),
                'enter_coupon_code' => __('请输入优惠码', 'qilingshop'),
                'validating' => __('验证中...', 'qilingshop'),
                'coupon_invalid' => __('优惠码无效', 'qilingshop'),
                'validation_failed' => __('验证失败，请重试', 'qilingshop'),
                'deleting' => __('删除中...', 'qilingshop'),
                'confirm_delete_button' => __('确认删除', 'qilingshop'),
                'assist_param_error' => __('活动参数错误', 'qilingshop'),
                'share_link_copied' => __('已复制分享链接', 'qilingshop'),
                'copy_failed_manual' => __('复制失败，请手动复制', 'qilingshop'),
                'or_points_price' => __('或 {points} {pointsName}', 'qilingshop'),
                'points_name' => __('积分', 'qilingshop'),
                'stock_pieces' => __('库存 {stock} 件', 'qilingshop'),
                'free_shipping' => __('免运费', 'qilingshop'),
                'confirm_title' => __('确认', 'qilingshop'),
                'confirm_action_message' => __('确定要执行此操作吗？', 'qilingshop'),
                'confirm_button' => __('确认', 'qilingshop'),
                'cancel_button' => __('取消', 'qilingshop'),
                'saving' => __('保存中...', 'qilingshop'),
                'save_failed' => __('保存失败', 'qilingshop'),
                'coupon_discount_display' => __('已优惠 ¥{amount}', 'qilingshop'),
                'virtual_no_shipping_address' => __('虚拟商品无需收货地址', 'qilingshop'),
                'select_payment_method' => __('请选择支付方式', 'qilingshop'),
                'enter_contact_name' => __('请填写联系人姓名', 'qilingshop'),
                'enter_phone_or_email' => __('请填写手机号码或邮箱地址', 'qilingshop'),
                'enter_query_password' => __('请设置4-64位订单查询密码', 'qilingshop'),
                'enter_shipping_info' => __('请填写完整的收货信息', 'qilingshop'),
                'submitting' => __('提交中...', 'qilingshop'),
                'group_ended' => __('团购已结束', 'qilingshop'),
                'ended' => __('已结束', 'qilingshop'),
                'user_login' => __('用户登录', 'qilingshop'),
                'processing' => __('处理中...', 'qilingshop'),
                'order_created' => __('创建订单成功', 'qilingshop'),
                'security_check_failed' => __('安全验证失败', 'qilingshop'),
                'no_products_found' => __('未找到相关商品', 'qilingshop'),
                'creating' => __('创建中...', 'qilingshop'),
                'assist_created' => __('已创建助力活动', 'qilingshop'),
                'create_failed_retry' => __('创建失败，请重试', 'qilingshop'),
                'assist_active_title' => __('已有进行中活动', 'qilingshop'),
                'go_view' => __('去查看', 'qilingshop'),
                'assisting' => __('助力中...', 'qilingshop'),
                'assist_success_cut' => __('助力成功，已减 ¥{amount}', 'qilingshop'),
                'assist_failed' => __('助力失败', 'qilingshop'),
                'creating_order' => __('创建订单中...', 'qilingshop'),
                'create_payment_order_failed' => __('创建支付订单失败', 'qilingshop'),
                'save_invoice_title' => __('保存发票信息', 'qilingshop'),
                'confirm_delete_invoice_title' => __('确定删除该发票信息吗？', 'qilingshop'),
                'enter_invoice_title' => __('请填写发票抬头', 'qilingshop'),
                'enter_invoice_tax_no' => __('企业抬头请填写税号', 'qilingshop'),
            ],
        ]);
    }

    /**
     * 已支付商城订单回到订单列表或游客订单查询页，不再经过独立成功页。
     */
    private function get_order_completion_redirect_url($order_no, $order) {
        $orders_url = $this->get_page_url('orders');
        if ($orders_url === '') {
            $orders_url = home_url('/');
        }

        $order_user_id = isset($order->user_id) ? (int) $order->user_id : 0;
        if ($order_user_id <= 0 && function_exists('qilingshop_get_order_query_page_url')) {
            return qilingshop_get_order_query_page_url($order_no, $orders_url);
        }

        return $orders_url;
    }

    public function get_page_url($page) {
        $page_id = $this->get_page_id($page);
        $url = $page_id ? get_permalink($page_id) : '';
        return $this->translate_frontend_internal_url($url);
    }

    /**
     * 获取页面 ID（优先按 shortcode 自动识别）
     */
    public function get_page_id($page) {
        $page = (string) $page;
        $registry_key = str_replace('-', '_', sanitize_key($page));
        static $resolved_ids = [];
        if (array_key_exists($registry_key, $resolved_ids)) {
            return (int) $resolved_ids[$registry_key];
        }
        $config = self::$page_registry[$registry_key] ?? null;

        if (!$config) {
            $fallback = (int) get_option('qls_shop_page_' . $page, 0);
            $resolved_ids[$registry_key] = $fallback > 0 ? $fallback : 0;
            return (int) $resolved_ids[$registry_key];
        }

        $options = isset($config['options']) && is_array($config['options']) ? $config['options'] : [];
        $shortcode = isset($config['shortcode']) ? (string) $config['shortcode'] : '';
        $page_id = 0;

        foreach ($options as $option_key) {
            $candidate = (int) get_option($option_key, 0);
            if ($candidate > 0 && get_post_status($candidate) === 'publish') {
                if ($shortcode !== '') {
                    $content = get_post_field('post_content', $candidate);
                    if (!is_string($content) || !has_shortcode($content, $shortcode)) {
                        continue;
                    }
                }
                $page_id = $candidate;
                break;
            }
        }

        // 未配置或配置失效时，按简码自动识别页面
        if ($page_id <= 0 && $shortcode !== '') {
            $page_id = $this->find_page_id_by_shortcode($shortcode);
        }

        if ($page_id > 0 && !empty($options)) {
            // 同步回主 option，避免后续重复查询
            update_option($options[0], $page_id);
            if ($registry_key === 'all_products') {
                $this->sync_all_products_page_slug($page_id);
            }
        }

        $resolved_ids[$registry_key] = $page_id > 0 ? $page_id : 0;
        return (int) $resolved_ids[$registry_key];
    }

    /**
     * 通过简码定位页面 ID（只查 page + publish）
     */
    private function find_page_id_by_shortcode($shortcode) {
        $shortcode = sanitize_key((string) $shortcode);
        if ($shortcode === '') {
            return 0;
        }

        global $wpdb;
        $like = '%[' . $wpdb->esc_like($shortcode) . '%';
        $sql = $wpdb->prepare(
            "SELECT ID
             FROM {$wpdb->posts}
             WHERE post_type = 'page'
               AND post_status = 'publish'
               AND post_content LIKE %s
             ORDER BY ID DESC
             LIMIT 50",
            $like
        );
        $candidate_ids = $wpdb->get_col($sql);
        if (empty($candidate_ids)) {
            return 0;
        }

        foreach ($candidate_ids as $candidate_id) {
            $candidate_id = (int) $candidate_id;
            if ($candidate_id <= 0) {
                continue;
            }
            $content = get_post_field('post_content', $candidate_id);
            if (is_string($content) && has_shortcode($content, $shortcode)) {
                return $candidate_id;
            }
        }

        return 0;
    }

    /**
     * 登录后合并购物车
     */
    public function merge_cart_on_login($user_login, $user) {
        $cookie_name = 'qls_cart_session';
        
        if (isset($_COOKIE[$cookie_name])) {
            $session_id = sanitize_text_field($_COOKIE[$cookie_name]);
            $merged = qls_cart()->merge_cart($user->ID, $session_id);
            if ($merged === false) {
                update_user_meta((int) $user->ID, '_qls_pending_cart_session', $session_id);
            } else {
                delete_user_meta((int) $user->ID, '_qls_pending_cart_session');
            }
        }
    }

    /**
     * 登录后延迟重试购物车合并
     *
     * @return void
     */
    public function retry_pending_cart_merge() {
        if (!is_user_logged_in()) {
            return;
        }

        $user_id = (int) get_current_user_id();
        if ($user_id <= 0) {
            return;
        }

        $session_id = (string) get_user_meta($user_id, '_qls_pending_cart_session', true);
        if ($session_id === '') {
            return;
        }

        $merged = qls_cart()->merge_cart($user_id, $session_id);
        if ($merged !== false) {
            delete_user_meta($user_id, '_qls_pending_cart_session');
        }
    }

    /**
     * 获取商城首页URL
     */
    public function get_shop_url() {
        return $this->get_page_url('shop');
    }

    /**
     * 将商城虚拟路由注册到主题多语言 URL Gateway。
     *
     * @return void
     */
    public function register_multilingual_routes() {
        if (!function_exists('developer_starter_register_frontend_route')) {
            return;
        }

        developer_starter_register_frontend_route('qilingshop.product', [
            'match' => function($path) {
                return $this->match_registered_product_route($path);
            },
            'url'   => function($context) {
                return $this->build_registered_product_route_url($context);
            },
        ]);

        developer_starter_register_frontend_route('qilingshop.category', [
            'match' => function($path) {
                return $this->match_registered_category_route($path);
            },
            'url'   => function($context) {
                return $this->build_registered_category_route_url($context);
            },
        ]);

        developer_starter_register_frontend_route('qilingshop.all_products_filter', [
            'match' => function($path) {
                return $this->match_registered_all_products_filter_route($path);
            },
            'url'   => function($context, $lang = null) {
                return $this->build_registered_all_products_filter_route_url($context, $lang);
            },
        ]);
    }

    /**
     * 获取商品URL
     */
    public function get_product_url($product) {
        $slug = is_object($product) ? $product->slug : $product;
        $slug = sanitize_title((string) $slug);
        if ($slug === '') {
            return '';
        }

        if (function_exists('developer_starter_get_frontend_route_url')) {
            $route_url = developer_starter_get_frontend_route_url('qilingshop.product', [
                'product_slug' => $slug,
            ]);
            if ($route_url !== '') {
                return (string) $route_url;
            }
        }

        $base = get_option('qls_shop_product_base', 'shop/product');
        $path = user_trailingslashit(trailingslashit(trim((string) $base, '/')) . $slug);
        $url = $this->build_internal_shop_route_url($path);
        return $this->translate_frontend_internal_url($url);
    }

    /**
     * 获取分类URL
     */
    public function get_category_url($category) {
        $slug = is_object($category) ? $category->slug : $category;
        $slug = sanitize_title((string) $slug);
        if ($slug === '') {
            return '';
        }

        if (function_exists('developer_starter_get_frontend_route_url')) {
            $route_url = developer_starter_get_frontend_route_url('qilingshop.category', [
                'category_slug' => $slug,
            ]);
            if ($route_url !== '') {
                return (string) $route_url;
            }
        }

        $base = get_option('qls_shop_category_base', 'shop/category');
        $path = user_trailingslashit(trailingslashit(trim((string) $base, '/')) . $slug);
        $url = $this->build_internal_shop_route_url($path);
        return $this->translate_frontend_internal_url($url);
    }

    /**
     * 构建商城内部原始 URL，不提前附加当前语言前缀。
     *
     * @param string $path 内部路径。
     * @return string
     */
    private function build_internal_shop_route_url($path) {
        $path = (string) $path;

        if (function_exists('developer_starter_build_raw_home_url')) {
            return (string) developer_starter_build_raw_home_url($path);
        }

        return home_url($path);
    }

    /**
     * 匹配注册表用的商品虚拟路由。
     *
     * @param string $path 请求路径。
     * @return array
     */
    private function match_registered_product_route($path) {
        $path = trim((string) $path, '/');
        $base = trim((string) get_option('qls_shop_product_base', 'shop/product'), '/');

        if ($path === '' || $base === '') {
            return [];
        }

        if (!preg_match('#^' . preg_quote($base, '#') . '/([^/]+)/?$#u', $path, $matches)) {
            return [];
        }

        $product_slug = sanitize_title(rawurldecode((string) $matches[1]));
        if ($product_slug === '') {
            return [];
        }

        return [
            'path'       => $base . '/' . $product_slug,
            'params'     => [
                'product_slug' => $product_slug,
            ],
            'query_vars' => [
                'qls_product' => $product_slug,
            ],
        ];
    }

    /**
     * 匹配注册表用的分类虚拟路由。
     *
     * @param string $path 请求路径。
     * @return array
     */
    private function match_registered_category_route($path) {
        $path = trim((string) $path, '/');
        $base = trim((string) get_option('qls_shop_category_base', 'shop/category'), '/');

        if ($path === '' || $base === '') {
            return [];
        }

        if (!preg_match('#^' . preg_quote($base, '#') . '/([^/]+)(?:/page/([0-9]+))?/?$#u', $path, $matches)) {
            return [];
        }

        $category_slug = sanitize_title(rawurldecode((string) $matches[1]));
        if ($category_slug === '') {
            return [];
        }

        $paged = isset($matches[2]) ? max(1, (int) $matches[2]) : 0;
        $route_path = $base . '/' . $category_slug;
        if ($paged > 1) {
            $route_path .= '/page/' . $paged;
        }

        $query_vars = [
            'qls_category' => $category_slug,
        ];
        if ($paged > 1) {
            $query_vars['paged'] = $paged;
        }

        return [
            'path'       => $route_path,
            'params'     => [
                'category_slug' => $category_slug,
                'paged'         => $paged,
            ],
            'query_vars' => $query_vars,
        ];
    }

    /**
     * 规范化全部商品筛选排序字段。
     *
     * @param string $sort 排序字段。
     * @return string
     */
    private function normalize_all_products_sort($sort) {
        $sort = sanitize_key((string) $sort);
        $allowed = ['default', 'latest', 'sales', 'hot', 'price_desc', 'price_asc', 'points_desc', 'points_asc'];

        return in_array($sort, $allowed, true) ? $sort : '';
    }

    /**
     * 获取“全部商品”虚拟路由可识别的基础 slug 列表（含翻译版本 slug）。
     *
     * @return string[]
     */
    private function get_all_products_route_base_slugs() {
        static $cached_slugs = null;
        if (is_array($cached_slugs)) {
            return $cached_slugs;
        }

        $slugs = [];
        $primary_slug = sanitize_title((string) $this->get_all_products_page_slug());
        if ($primary_slug !== '') {
            $slugs[] = $primary_slug;
        }

        $all_products_page_id = (int) $this->get_page_id('all-products');
        if ($all_products_page_id > 0) {
            $page_slug = sanitize_title((string) get_post_field('post_name', $all_products_page_id));
            if ($page_slug !== '') {
                $slugs[] = $page_slug;
            }

            if (defined('XB_AIFANYI_TRANSLATION_GROUP_META_KEY')) {
                $group = sanitize_text_field((string) get_post_meta($all_products_page_id, XB_AIFANYI_TRANSLATION_GROUP_META_KEY, true));
                if ($group !== '') {
                    $translation_ids = get_posts([
                        'post_type'      => 'page',
                        'post_status'    => 'publish',
                        'posts_per_page' => -1,
                        'fields'         => 'ids',
                        'meta_query'     => [
                            [
                                'key'   => XB_AIFANYI_TRANSLATION_GROUP_META_KEY,
                                'value' => $group,
                            ],
                        ],
                        'no_found_rows'  => true,
                        'suppress_filters' => false,
                    ]);

                    if (is_array($translation_ids)) {
                        foreach ($translation_ids as $translation_id) {
                            $translation_id = (int) $translation_id;
                            if ($translation_id <= 0) {
                                continue;
                            }
                            $translation_slug = sanitize_title((string) get_post_field('post_name', $translation_id));
                            if ($translation_slug !== '') {
                                $slugs[] = $translation_slug;
                            }
                        }
                    }
                }
            }
        }

        $cached_slugs = array_values(array_unique(array_filter($slugs)));
        return $cached_slugs;
    }

    /**
     * 匹配“全部商品”页筛选虚拟路由。
     *
     * 支持：
     * - /{all-products}/category/{slug}/
     * - /{all-products}/category/{slug}/{sort}/
     * - /{all-products}/{sort}/
     *
     * @param string $path 请求路径。
     * @return array
     */
    private function match_registered_all_products_filter_route($path) {
        $path = trim((string) $path, '/');
        if ($path === '') {
            return [];
        }

        $bases = $this->get_all_products_route_base_slugs();
        if (empty($bases)) {
            return [];
        }

        foreach ($bases as $base) {
            $base = trim((string) $base, '/');
            if ($base === '') {
                continue;
            }

            if (preg_match('#^' . preg_quote($base, '#') . '/category/([^/]+)(?:/(latest|sales|hot|price_desc|price_asc|points_desc|points_asc))?/?$#u', $path, $matches)) {
                $category_slug = sanitize_title(rawurldecode((string) $matches[1]));
                if ($category_slug === '') {
                    continue;
                }

                $sort = $this->normalize_all_products_sort((string) ($matches[2] ?? ''));
                $effective_sort = ($sort !== '' && !in_array($sort, ['default', 'latest'], true)) ? $sort : '';

                $route_path = $base . '/category/' . $category_slug;
                if ($effective_sort !== '') {
                    $route_path .= '/' . $effective_sort;
                }

                $query_vars = [
                    'pagename'     => $base,
                    'qls_category' => $category_slug,
                ];
                if ($effective_sort !== '') {
                    $query_vars['qls_sort'] = $effective_sort;
                }

                return [
                    'path'       => $route_path,
                    'params'     => [
                        'base_slug'     => $base,
                        'category_slug' => $category_slug,
                        'sort'          => $effective_sort,
                    ],
                    'query_vars' => $query_vars,
                ];
            }

            if (preg_match('#^' . preg_quote($base, '#') . '/(latest|sales|hot|price_desc|price_asc|points_desc|points_asc)/?$#u', $path, $matches)) {
                $sort = $this->normalize_all_products_sort((string) ($matches[1] ?? ''));
                $effective_sort = ($sort !== '' && !in_array($sort, ['default', 'latest'], true)) ? $sort : '';

                $route_path = $base;
                if ($effective_sort !== '') {
                    $route_path .= '/' . $effective_sort;
                }

                $query_vars = [
                    'pagename' => $base,
                ];
                if ($effective_sort !== '') {
                    $query_vars['qls_sort'] = $effective_sort;
                }

                return [
                    'path'       => $route_path,
                    'params'     => [
                        'base_slug' => $base,
                        'sort'      => $effective_sort,
                    ],
                    'query_vars' => $query_vars,
                ];
            }
        }

        return [];
    }

    /**
     * 构建注册表用的商品详情 URL。
     *
     * @param array $context 路由上下文。
     * @return string
     */
    private function build_registered_product_route_url($context) {
        $params = isset($context['params']) && is_array($context['params']) ? $context['params'] : [];
        $slug = sanitize_title((string) ($params['product_slug'] ?? ''));
        $base = trim((string) get_option('qls_shop_product_base', 'shop/product'), '/');

        if ($slug === '' || $base === '') {
            return '';
        }

        return $this->build_internal_shop_route_url(
            user_trailingslashit($base . '/' . $slug)
        );
    }

    /**
     * 构建注册表用的商品分类 URL。
     *
     * @param array $context 路由上下文。
     * @return string
     */
    private function build_registered_category_route_url($context) {
        $params = isset($context['params']) && is_array($context['params']) ? $context['params'] : [];
        $slug = sanitize_title((string) ($params['category_slug'] ?? ''));
        $paged = isset($params['paged']) ? max(0, (int) $params['paged']) : 0;
        $base = trim((string) get_option('qls_shop_category_base', 'shop/category'), '/');

        if ($slug === '' || $base === '') {
            return '';
        }

        $path = $base . '/' . $slug;
        if ($paged > 1) {
            $path .= '/page/' . $paged;
        }

        return $this->build_internal_shop_route_url( user_trailingslashit($path) );
    }

    /**
     * 构建“全部商品”筛选虚拟路由 URL。
     *
     * @param array       $context 路由上下文。
     * @param string|null $lang    目标语言。
     * @return string
     */
    private function build_registered_all_products_filter_route_url($context, $lang = null) {
        $params = isset($context['params']) && is_array($context['params']) ? $context['params'] : [];
        $category_slug = sanitize_title((string) ($params['category_slug'] ?? ''));
        $sort = $this->normalize_all_products_sort((string) ($params['sort'] ?? ''));
        if (in_array($sort, ['default', 'latest'], true)) {
            $sort = '';
        }

        $base_url = '';
        $all_products_page_id = (int) $this->get_page_id('all-products');
        if ($all_products_page_id > 0) {
            if (function_exists('developer_starter_get_post_url_for_frontend_lang')) {
                $base_url = (string) developer_starter_get_post_url_for_frontend_lang($all_products_page_id, $lang);
            } else {
                $base_url = (string) get_permalink($all_products_page_id);
            }
        }

        if ($base_url === '') {
            $base_slug = sanitize_title((string) ($params['base_slug'] ?? ''));
            if ($base_slug === '') {
                $bases = $this->get_all_products_route_base_slugs();
                $base_slug = !empty($bases) ? (string) $bases[0] : '';
            }
            if ($base_slug === '') {
                return '';
            }

            $base_url = $this->build_internal_shop_route_url(user_trailingslashit($base_slug));
        }

        $base_url = untrailingslashit((string) $base_url);
        if ($base_url === '') {
            return '';
        }

        $segments = [];
        if ($category_slug !== '') {
            $segments[] = 'category';
            $segments[] = $category_slug;
        }
        if ($sort !== '') {
            $segments[] = $sort;
        }

        if (!empty($segments)) {
            $base_url .= '/' . implode('/', $segments);
        }

        return user_trailingslashit($base_url);
    }

    /**
     * 多语言内容模式下，将商城内部链接切换到当前前台语言。
     *
     * @param string $url 原始站内地址。
     * @return string
     */
    private function translate_frontend_internal_url($url) {
        $url = (string) $url;

        if ($url === '' || is_admin()) {
            return $url;
        }

        if (function_exists('developer_starter_translate_internal_url_for_frontend_lang')) {
            return (string) developer_starter_translate_internal_url_for_frontend_lang($url);
        }

        return $url;
    }

    /**
     * 识别当前请求是否命中商城虚拟商品/分类路由。
     *
     * 兼容 rewrite 尚未刷新、以及多语言前缀包裹后的 URL。
     *
     * @return array{product_slug:string,category_slug:string,paged:int}
     */
    private function resolve_virtual_request_from_current_path() {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string) $_SERVER['REQUEST_URI']) : '';
        if ($request_uri === '') {
            return [
                'product_slug' => '',
                'category_slug' => '',
                'paged' => 0,
            ];
        }

        $path = (string) wp_parse_url($request_uri, PHP_URL_PATH);
        $path = $this->normalize_virtual_request_path($path);
        if ($path === '') {
            return [
                'product_slug' => '',
                'category_slug' => '',
                'paged' => 0,
            ];
        }

        $product_base = trim((string) get_option('qls_shop_product_base', 'shop/product'), '/');
        if ($product_base !== '' && preg_match('#^' . preg_quote($product_base, '#') . '/([^/]+)/?$#u', $path, $matches)) {
            return [
                'product_slug' => sanitize_title(rawurldecode((string) $matches[1])),
                'category_slug' => '',
                'paged' => 0,
            ];
        }

        $category_base = trim((string) get_option('qls_shop_category_base', 'shop/category'), '/');
        if ($category_base !== '' && preg_match('#^' . preg_quote($category_base, '#') . '/([^/]+)(?:/page/([0-9]+))?/?$#u', $path, $matches)) {
            return [
                'product_slug' => '',
                'category_slug' => sanitize_title(rawurldecode((string) $matches[1])),
                'paged' => isset($matches[2]) ? max(1, (int) $matches[2]) : 0,
            ];
        }

        return [
            'product_slug' => '',
            'category_slug' => '',
            'paged' => 0,
        ];
    }

    /**
     * 规范当前请求路径，移除站点子目录和多语言前缀。
     *
     * @param string $path 请求路径。
     * @return string
     */
    private function normalize_virtual_request_path($path) {
        $path = trim((string) $path, '/');
        if ($path === '') {
            return '';
        }

        $home_path = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
        $home_path = trim($home_path, '/');
        if ($home_path !== '') {
            if ($path === $home_path) {
                $path = '';
            } elseif (0 === strpos($path, $home_path . '/')) {
                $path = substr($path, strlen($home_path) + 1);
            }
        }

        if ($path === '') {
            return '';
        }

        if (function_exists('developer_starter_get_multilingual_languages')) {
            $codes = wp_list_pluck((array) developer_starter_get_multilingual_languages(), 'code');
            $codes = array_filter(array_map('sanitize_title', (array) $codes));
            if (!empty($codes)) {
                $segments = explode('/', $path);
                $first_segment = isset($segments[0]) ? sanitize_title((string) $segments[0]) : '';
                if ($first_segment !== '' && in_array($first_segment, $codes, true)) {
                    array_shift($segments);
                    $path = implode('/', $segments);
                }
            }
        }

        return trim((string) $path, '/');
    }

    /**
     * 为当前虚拟页补齐 query vars，兼容后续模板和 SEO 读取。
     *
     * @param string $key   Query var 名称。
     * @param mixed  $value Query var 值。
     * @return void
     */
    private function set_virtual_query_var($key, $value) {
        global $wp_query;

        set_query_var($key, $value);

        if (isset($wp_query) && $wp_query instanceof WP_Query) {
            $wp_query->query_vars[$key] = $value;
        }
    }

    /**
     * 标记当前虚拟请求已成功解析，避免被 canonical / 404 链路继续处理。
     *
     * @return void
     */
    private function mark_virtual_request_as_resolved() {
        global $wp_query;

        if (!isset($wp_query) || !($wp_query instanceof WP_Query)) {
            return;
        }

        $wp_query->is_404 = false;
        status_header(200);
    }

    /**
     * 获取商城通用头部
     */
    public function get_shop_header($title = '', $hide_header = false) {
        // 仅在未输出 Header 时调用
        if (!did_action('get_header')) {
            get_header();
            $this->manually_output_header = true;
        }
        
        echo '<div class="qls-shop-content-wrapper">';
    }

    /**
     * 获取商城通用底部
     */
    public function get_shop_footer() {
        echo '</div>';
        
        // 仅当当前实例输出了 header 时才补齐 footer。
        if ($this->manually_output_header) {
            get_footer();
        }
    }

    /**
     * 渲染商城服务展示区（与商品服务标签独立）
     *
     * @param string $position 展示位置: home_bottom|product_bottom
     * @return void
     */
    public function render_service_showcase($position = 'home_bottom') {
        $position = sanitize_key((string) $position);
        $allowed_positions = ['home_bottom', 'product_bottom'];
        if (!in_array($position, $allowed_positions, true)) {
            return;
        }

        $positions = get_option('qls_shop_service_showcase_positions', ['home_bottom']);
        if (!is_array($positions) || empty($positions)) {
            $positions = ['home_bottom'];
        }
        $positions = array_values(array_unique(array_map('sanitize_key', $positions)));
        if (!in_array($position, $positions, true)) {
            return;
        }

        $raw_items = get_option('qls_shop_service_showcase_items', []);
        if (!is_array($raw_items) || empty($raw_items)) {
            return;
        }

        $items = [];
        foreach ($raw_items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $icon = trim((string) ($item['icon'] ?? ''));
            $title = trim((string) ($item['title'] ?? ''));
            $desc = trim((string) ($item['desc'] ?? ''));
            if ($icon === '' && $title === '' && $desc === '') {
                continue;
            }
            $items[] = [
                'icon'  => $icon,
                'title' => $title,
                'desc'  => $desc,
            ];
            if (count($items) >= 12) {
                break;
            }
        }

        if (empty($items)) {
            return;
        }

        echo '<section class="qls-service-showcase qls-service-showcase--' . esc_attr($position) . '">';
        echo '<div class="qls-service-showcase-list">';
        foreach ($items as $item) {
            $icon = $item['icon'];
            $title = $item['title'];
            $desc = $item['desc'];

            echo '<div class="qls-service-showcase-item">';
            echo '<div class="qls-service-showcase-icon-wrap">';
            if ($icon !== '' && $this->is_valid_icon_url($icon)) {
                echo '<span class="qls-service-showcase-icon qls-service-showcase-icon--image" aria-hidden="true">';
                echo '<img src="' . esc_url($icon) . '" alt="" loading="lazy" decoding="async">';
                echo '</span>';
            } elseif ($icon !== '') {
                echo '<span class="qls-service-showcase-icon qls-service-showcase-icon--text" aria-hidden="true">' . esc_html($icon) . '</span>';
            } else {
                echo '<span class="qls-service-showcase-icon qls-service-showcase-icon--text" aria-hidden="true">•</span>';
            }
            echo '</div>';

            echo '<div class="qls-service-showcase-content">';
            if ($title !== '') {
                echo '<div class="qls-service-showcase-title">' . esc_html($title) . '</div>';
            }
            if ($desc !== '') {
                echo '<div class="qls-service-showcase-desc">' . esc_html($desc) . '</div>';
            }
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
        echo '</section>';
    }

    /**
     * 校验图标 URL
     *
     * @param string $url
     * @return bool
     */
    private function is_valid_icon_url($url) {
        $url = trim((string) $url);
        if ($url === '') {
            return false;
        }

        if (function_exists('wp_http_validate_url')) {
            return (bool) wp_http_validate_url($url);
        }

        return (bool) filter_var($url, FILTER_VALIDATE_URL);
    }

    /**
     * SEO 标题优化
     */
    public function seo_title($title) {
        // 处理全部商品页的标题 (via query vars)
        $current_sort = get_query_var('qls_sort');

        // 处理 GET 参数
        if (empty($current_sort) && isset($_GET['sort'])) {
            $current_sort = sanitize_text_field($_GET['sort']);
        }

        $sort_labels = [
            'latest'     => __('最新上架', 'qilingshop'),
            'sales'      => __('销量优先', 'qilingshop'),
            'hot'        => __('热门推荐', 'qilingshop'),
            'price_desc' => __('价格从高到低', 'qilingshop'),
            'price_asc'  => __('价格从低到高', 'qilingshop'),
            'points_asc' => __('积分从低到高', 'qilingshop'),
            'points_desc'=> __('积分从高到低', 'qilingshop'),
        ];
        
        $category_slug = get_query_var('qls_category');
        // 兜底
        if (empty($category_slug) && isset($_GET['category'])) {
            $category_slug = sanitize_text_field($_GET['category']);
        }

        if ($category_slug && !get_query_var('qls_product')) {
            $category = qls_category()->get_by_slug($category_slug);
            if ($category) {
                $page_title = $category->name;
                if ($current_sort && isset($sort_labels[$current_sort])) {
                    $page_title = $sort_labels[$current_sort] . ' - ' . $page_title;
                }
                return $page_title . ' - ' . get_bloginfo('name');
            }
        }
        
        // 处理全部商品主页标题
        if (is_page(get_option('qls_shop_page_all_products'))) {
             if ($current_sort && isset($sort_labels[$current_sort])) {
                 return $sort_labels[$current_sort] . ' - ' . get_the_title(get_option('qls_shop_page_all_products')) . ' - ' . get_bloginfo('name');
             }
        }

        return $title;
    }

    /**
     * SEO Meta 标签
     */
    public function seo_meta_tags() {
        $keywords = '';
        $description = '';

        $product_slug = get_query_var('qls_product');
        $category_slug = get_query_var('qls_category');

        if ($product_slug) {
            // 商品详情页在 product-single 模板中统一输出完整 SEO/OG 标签，避免重复注入
            return;
        }

        if ($category_slug) {
            $category = qls_category()->get_by_slug($category_slug);
            if ($category) {
                $description = wp_html_excerpt(
                    trim(wp_strip_all_tags((string) $category->description)),
                    120,
                    '...'
                );
                $keywords = isset($category->seo_keywords) ? $category->seo_keywords : '';
            }
        }

        if ($description) {
            echo '<meta name="description" content="' . esc_attr($description) . '" />' . "\n";
        }
        if ($keywords) {
            echo '<meta name="keywords" content="' . esc_attr($keywords) . '" />' . "\n";
        }
    }

    /**
     * 禁用商城页面的主题页头
     */
    public function filter_theme_header($show, $post_id) {
        if ($this->is_shop_page()) {
            return false;
        }

        return $show;
    }

    /**
     * 禁用商城页面的主题侧边栏
     */
    public function filter_theme_sidebar($show, $type) {
        if ($this->is_shop_page()) {
            return false;
        }

        return $show;
    }

    /**
     * 禁止商城页面加载侧边栏 CSS
     */
    public function filter_theme_sidebar_css($load) {
        if ($this->is_shop_page()) {
            return false;
        }
        return $load;
    }

    /**
     * 商城页面 Body Class（用于全屏兼容覆盖）
     *
     * @param array $classes
     * @return array
     */
    public function filter_shop_body_class($classes) {
        $current_page_key = $this->get_current_shop_page_key();
        if ($current_page_key === '') {
            return $classes;
        }

        $classes[] = 'qls-shop-page';
        $classes[] = 'qls-shop-fullscreen';
        $classes[] = 'qls-shop-' . str_replace('_', '-', $current_page_key) . '-page';
        return array_values(array_unique($classes));
    }
}

/**
 * 获取前台类实例
 */
function qls_shop_public() {
    return QLS_Shop_Public::instance();
}
