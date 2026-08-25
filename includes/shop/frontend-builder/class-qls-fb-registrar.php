<?php
/**
 * 前台装修模块注册器
 *
 * 检测主题的 Module_Manager 是否可用，
 * 加载所有适配器文件并注册到主题的模块系统中，
 * 使商城模块出现在前台装修面板的模块库里。
 *
 * @package QilingShop
 * @since   2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_FrontendBuilder_Registrar {

    /**
     * 是否已初始化
     *
     * @var bool
     */
    private static $initialized = false;
    /**
     * @var bool
     */
    private static $modules_registered = false;

    /**
     * 初始化入口
     *
     * 应在 init 钩子或 after_setup_theme 之后调用
     */
    public static function init() {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        // 检测主题的 Module_Manager 是否可用
        if (!class_exists('Developer_Starter\\Modules\\Module_Manager')) {
            return;
        }

        // AJAX 场景走 init；前台页面场景走 wp（此时可安全判断 is_singular/get_queried_object_id）。
        add_action('init', [__CLASS__, 'register_modules'], 20);
        add_action('wp', [__CLASS__, 'register_modules'], 20);

        // 前台装修模式下确保商城 CSS 被加载
        add_action('wp_enqueue_scripts', [__CLASS__, 'maybe_enqueue_shop_assets'], 20);
    }

    /**
     * 注册所有商城适配器模块到主题的 Module_Manager
     */
    public static function register_modules() {
        if (self::$modules_registered) {
            return;
        }

        if (!self::should_register_modules_for_current_request()) {
            return;
        }

        $manager = \Developer_Starter\Modules\Module_Manager::get_instance();

        // 先确保主题默认模块已注册，避免仅注册商城模块导致主题模块库被覆盖/缺失。
        if (method_exists($manager, 'register_default_modules')) {
            $manager->register_default_modules();
        } elseif (method_exists($manager, 'get_all_modules')) {
            $manager->get_all_modules();
        }

        // 加载适配器基类
        $base_dir = QILINGSHOP_PATH . 'includes/shop/frontend-builder/';
        require_once $base_dir . 'class-qls-fb-adapter-base.php';

        // 适配器文件 => 类名 映射
        $adapters = [
            'class-qls-fb-product-list.php'   => 'QLS_FB_Product_List',
            'class-qls-fb-hero-carousel.php'  => 'QLS_FB_Hero_Carousel',
            'class-qls-fb-category-nav.php'   => 'QLS_FB_Category_Nav',
            'class-qls-fb-coupon.php'         => 'QLS_FB_Coupon',
            'class-qls-fb-group.php'          => 'QLS_FB_Group',
            'class-qls-fb-assist.php'         => 'QLS_FB_Assist',
            'class-qls-fb-new-user-zone.php'  => 'QLS_FB_New_User_Zone',
        ];

        foreach ($adapters as $file => $class_name) {
            $file_path = $base_dir . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
                if (class_exists($class_name)) {
                    $manager->register_module(new $class_name());
                }
            }
        }

        self::$modules_registered = true;
    }

    /**
     * 在前台装修模式下加载商城所需的 CSS 资源
     *
     * 确保模块在装修预览时正确渲染样式
     */
    public static function maybe_enqueue_shop_assets() {
        // is_builder_mode 检查 URL 参数 qiling_builder=1
        if (!isset($_GET['qiling_builder'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $version = defined('QILINGSHOP_VERSION') ? QILINGSHOP_VERSION : '2.0.0';

        // 商城基础样式
        $shop_css_path = QILINGSHOP_PATH . 'static/shop/css/shop-public.css';
        $shop_css_ver = file_exists($shop_css_path) ? $version . '.' . filemtime($shop_css_path) : $version;
        wp_enqueue_style('qls-shop-public', QILINGSHOP_URL . 'static/shop/css/shop-public.css', [], $shop_css_ver);

        // 确保 Swiper 库在装修模式下可用（轮播模块需要）
        $swiper_style_loaded = wp_style_is('swiper', 'enqueued') || wp_style_is('qiling-swiper', 'enqueued') || wp_style_is('qls-swiper', 'enqueued');
        $swiper_script_loaded = wp_script_is('swiper', 'enqueued') || wp_script_is('qiling-swiper', 'enqueued') || wp_script_is('qls-swiper', 'enqueued');

        if (!$swiper_style_loaded || !$swiper_script_loaded) {
            $swiper_js_path = QILINGSHOP_PATH . 'static/shop/js/swiper-bundle.min.js';
            $swiper_ver = file_exists($swiper_js_path) ? $version . '.' . filemtime($swiper_js_path) : $version;

            if (!$swiper_style_loaded) {
                wp_enqueue_style('qls-swiper', QILINGSHOP_URL . 'static/shop/css/swiper-bundle.min.css', [], $swiper_ver);
            }
            if (!$swiper_script_loaded) {
                wp_enqueue_script('qls-swiper', QILINGSHOP_URL . 'static/shop/js/swiper-bundle.min.js', [], $swiper_ver, true);
            }
        }
    }

    /**
     * 仅在“启灵主题前台装修 + 积分商城页面上下文”下注册商城适配模块。
     *
     * @return bool
     */
    private static function should_register_modules_for_current_request() {
        $is_ajax = function_exists('wp_doing_ajax') && wp_doing_ajax();
        if ($is_ajax) {
            $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash((string) $_REQUEST['action'])) : '';
            $allowed_actions = [
                'qiling_frontend_builder_get_schema',
                'qiling_frontend_builder_render_module_preview',
                'qiling_frontend_builder_render_preview',
                'qiling_frontend_builder_save_modules',
            ];
            if (!in_array($action, $allowed_actions, true)) {
                return false;
            }

            $post_id = isset($_REQUEST['post_id']) ? absint(wp_unslash((string) $_REQUEST['post_id'])) : 0;
            if ($post_id <= 0) {
                return false;
            }

            return self::is_qilingshop_builder_page($post_id);
        }

        // 后台普通请求（含页面编辑）不注册，避免污染主题模块面板。
        if (is_admin()) {
            return false;
        }

        $builder_flag = isset($_GET['qiling_builder']) ? sanitize_text_field(wp_unslash((string) $_GET['qiling_builder'])) : '';
        if ($builder_flag !== '1') {
            return false;
        }

        if (!function_exists('is_singular') || !is_singular('page')) {
            return false;
        }

        $post_id = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
        if ($post_id <= 0) {
            return false;
        }

        return self::is_qilingshop_builder_page($post_id);
    }

    /**
     * 当前页面是否为启灵积分商城装修上下文页面。
     *
     * @param int $post_id 页面ID
     * @return bool
     */
    private static function is_qilingshop_builder_page($post_id) {
        $post_id = absint($post_id);
        if ($post_id <= 0) {
            return false;
        }

        if (self::is_virtual_home_decoration_blocked($post_id)) {
            return false;
        }

        if (isset($_GET['qls_shop_builder'])) {
            $force_shop_builder = sanitize_text_field(wp_unslash((string) $_GET['qls_shop_builder']));
            if ($force_shop_builder === '1') {
                return true;
            }
        }

        $layout = self::normalize_layout_value(get_post_meta($post_id, '_qls_shop_layout', true));
        if (is_array($layout) && !empty($layout)) {
            return true;
        }

        foreach (self::get_shop_page_option_keys() as $opt_key) {
            if ((int) get_option($opt_key, 0) === $post_id) {
                return true;
            }
        }

        if (self::is_registered_shop_page_context($post_id)) {
            return true;
        }

        $post = get_post($post_id);
        if (!($post instanceof \WP_Post)) {
            return false;
        }

        $content = (string) $post->post_content;
        if ($content === '') {
            return false;
        }

        return self::post_has_shop_shortcodes($content);
    }

    /**
     * 虚拟发卡首页使用专用模板，不进入商城装修上下文。
     *
     * @param int $post_id 页面ID
     * @return bool
     */
    private static function is_virtual_home_decoration_blocked($post_id) {
        $post_id = absint($post_id);
        if ($post_id <= 0) {
            return false;
        }

        if ($post_id === (int) get_option('qls_shop_page_virtual_home', 0)) {
            return true;
        }

        $post = get_post($post_id);
        if (!($post instanceof \WP_Post)) {
            return false;
        }

        $content = (string) $post->post_content;
        if (has_shortcode($content, 'qls_shop_virtual_home')) {
            return true;
        }

        return has_shortcode($content, 'qls_shop')
            && (bool) preg_match('/\[qls_shop[^\]]*\bmode=(["\']?)virtual_card\1/i', $content);
    }

    /**
     * 通过商城前台页面注册表判断当前页面是否属于商城上下文。
     *
     * @param int $post_id 页面ID
     * @return bool
     */
    private static function is_registered_shop_page_context($post_id) {
        $post_id = absint($post_id);
        if ($post_id <= 0) {
            return false;
        }

        $shop_public = null;
        if (function_exists('qls_shop_public')) {
            $shop_public = qls_shop_public();
        } elseif (class_exists('QLS_Shop_Public') && method_exists('QLS_Shop_Public', 'instance')) {
            $shop_public = QLS_Shop_Public::instance();
        }

        if (!is_object($shop_public) || !method_exists($shop_public, 'get_page_id')) {
            return false;
        }

        $page_keys = [
            'shop',
            'cart',
            'checkout',
            'orders',
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

        foreach ($page_keys as $page_key) {
            if ((int) $shop_public->get_page_id($page_key) === $post_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * 规范化装修布局值（支持数组/序列化字符串/JSON）。
     *
     * @param mixed $layout 原始布局值
     * @return array|mixed
     */
    private static function normalize_layout_value($layout) {
        if (is_array($layout)) {
            return $layout;
        }

        if (is_string($layout)) {
            $raw = trim($layout);
            if ($raw === '') {
                return [];
            }

            if (function_exists('is_serialized') && is_serialized($raw)) {
                $unserialized = maybe_unserialize($raw);
                if (is_array($unserialized)) {
                    return $unserialized;
                }
            }

            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $layout;
    }

    /**
     * 与商城后台装修一致的页面绑定 option keys。
     *
     * @return array<int,string>
     */
    private static function get_shop_page_option_keys() {
        return [
            'qls_shop_page_shop',
            'qls_shop_page_cart',
            'qls_shop_page_checkout',
            'qls_shop_page_orders',
            'qls_shop_page_center',
            'qls_shop_page_shop-center',
            'qls_shop_page_all_products',
            'qls_shop_page_coupon_center',
            'qls_shop_page_coupon-center',
            'qls_shop_page_group_center',
            'qls_shop_page_group-center',
            'qls_shop_page_group_detail',
            'qls_shop_page_group-detail',
            'qls_shop_page_assist_center',
            'qls_shop_page_assist-center',
            'qls_shop_page_assist_detail',
            'qls_shop_page_assist-detail',
            'qls_shop_page_my_assists',
            'qls_shop_page_my-assists',
            'qls_shop_page_my_downloads',
            'qls_shop_page_my-downloads',
            'qls_shop_page_new_user_zone',
            'qls_shop_page_order_query',
            'qilingshop_page_order_query',
        ];
    }

    /**
     * 与商城后台装修一致的短代码识别。
     *
     * @param string $content 页面内容
     * @return bool
     */
    private static function post_has_shop_shortcodes($content) {
        $shortcodes = [
            'qls_shop',
            'qls_products',
            'qls_cart',
            'qls_checkout',
            'qls_my_orders',
            'qls_my_groups',
            'qls_shop_center',
            'qls_all_products',
            'qls_coupon_center',
            'qls_group_center',
            'qls_group_detail',
            'qls_assist_center',
            'qls_assist_detail',
            'qls_my_assists',
            'qls_my_downloads',
            'qls_new_user_zone',
            'qilingshop_order_query',
        ];

        foreach ($shortcodes as $shortcode) {
            if (has_shortcode((string) $content, $shortcode)) {
                return true;
            }
        }

        return false;
    }
}
