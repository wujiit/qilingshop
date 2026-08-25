<?php
/**
 * 主题集成类 - 与启灵主题个人中心集成
 *
 * @package QilingShop
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Theme_Integration {

    /**
     * 单例实例
     */
    private static $instance = null;

    /**
     * Tab配置
     */
    private $tabs = [];

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // 挂载钩子
        add_filter('qiling_account_tabs', [$this, 'register_tabs'], 10, 2);
        add_action('qiling_account_tab_content', [$this, 'render_tab_content'], 10, 2);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_account_assets'], 20);
        add_action('qiling_header_actions', [$this, 'render_header_vip_icon']); // Add VIP Icon
        add_action('qiling_header_actions', [$this, 'render_header_cart_button']);
        add_action('qiling_user_dropdown_items', [$this, 'render_user_dropdown_shop_link']);
        
        // 强制全屏模板
        add_filter('template_include', [$this, 'force_fullscreen_template'], 99);
        // 控制页面头部显示
        add_filter('qiling_show_page_header', [$this, 'control_page_header'], 10, 2);
        
        // 添加自定义图标
        add_filter('qiling_account_icons', [$this, 'add_custom_icons']);

        // 前台装修集成 - 注册商城模块到主题的前台装修系统
        $this->init_frontend_builder_integration();
    }

    private function get_tabs() {
        if (!empty($this->tabs)) {
            return $this->tabs;
        }

        $this->tabs = [
            'qls-shop'   => ['icon' => 'shopping-cart', 'label' => $this->translate_after_init('积分中心')],
            'qls-vip'    => ['icon' => 'award', 'label' => $this->translate_after_init('VIP会员')],
            'qls-orders' => ['icon' => 'list', 'label' => $this->translate_after_init('积分订单')],
            'qls-invite' => ['icon' => 'users', 'label' => $this->translate_after_init('邀请记录')],
            'qls-points' => ['icon' => 'credit-card', 'label' => $this->translate_after_init('积分记录')],
        ];

        if (get_option('qilingshop_author_commission_enabled', false)) {
            $this->tabs['qls-commission'] = ['icon' => 'dollar-sign', 'label' => $this->translate_after_init('销售提成')];
        }

        if (get_option('qilingshop_withdraw_enabled', false)) {
            $this->tabs['qls-withdraw'] = ['icon' => 'list', 'label' => $this->translate_after_init('提现记录')];
        }

        return $this->tabs;
    }

    private function translate_after_init($text) {
        return (did_action('init') || doing_action('init')) ? __($text, 'qilingshop') : $text;
    }

    /**
     * 初始化前台装修集成
     *
     * 加载注册器并将商城模块桥接到主题的 Module_Manager
     */
    private function init_frontend_builder_integration() {
        $registrar_file = QILINGSHOP_PATH . 'includes/shop/frontend-builder/class-qls-fb-registrar.php';
        if (file_exists($registrar_file)) {
            require_once $registrar_file;
            QLS_FrontendBuilder_Registrar::init();
        }
    }

    /**
     * 添加自定义图标到主题
     */
    public function add_custom_icons($icons) {
        // 销售提成图标 (dollar-sign)
        $icons['dollar-sign'] = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>';
        return $icons;
    }

    /**
     * 获取主题个人中心标签页链接。
     *
     * @param string $tab 标签页标识。
     * @return string
     */
    private function get_account_tab_url($tab = '') {
        $tab = sanitize_key((string) $tab);

        if (function_exists('developer_starter_get_frontend_account_tab_url')) {
            return (string) developer_starter_get_frontend_account_tab_url($tab);
        }

        $pages = get_pages([
            'meta_key'   => '_wp_page_template',
            'meta_value' => 'templates/template-account.php',
            'number'     => 1,
        ]);
        if (!empty($pages)) {
            $url = get_permalink($pages[0]->ID);
            return $tab !== '' ? add_query_arg('tab', $tab, $url) : $url;
        }

        $fallback = function_exists('developer_starter_build_raw_home_url')
            ? developer_starter_build_raw_home_url('/user')
            : home_url('/user');
        return $tab !== '' ? add_query_arg('tab', $tab, $fallback) : $fallback;
    }

    /**
     * 注册标签页到主题个人中心
     */
    public function register_tabs($tabs, $user_id) {
        // 在 WooCommerce 标签之前插入积分商城标签。
        $new_tabs = [];
        $inserted = false;
        $qls_tabs = $this->get_tabs();

        foreach ($tabs as $key => $tab) {
            // 在 orders (WooCommerce订单) 或 security 之前插入
            if (!$inserted && ($key === 'orders' || $key === 'security')) {
                foreach ($qls_tabs as $qls_key => $qls_tab) {
                    $new_tabs[$qls_key] = $qls_tab;
                }
                $inserted = true;
            }
            $new_tabs[$key] = $tab;
        }

        // 如果还没插入(没有security/orders标签)，追加到末尾
        if (!$inserted) {
            foreach ($qls_tabs as $qls_key => $qls_tab) {
                $new_tabs[$qls_key] = $qls_tab;
            }
        }

        return $new_tabs;
    }

    /**
     * 渲染标签页内容
     */
    public function render_tab_content($active_tab, $user_id) {
        // 仅处理积分商城标签页。
        if (!array_key_exists($active_tab, $this->get_tabs())) {
            return;
        }

        // 根据标签加载对应模板
        $template_map = [
            'qls-shop'       => 'shop',
            'qls-vip'        => 'vip',
            'qls-orders'     => 'orders',
            'qls-invite'     => 'invite',
            'qls-points'     => 'points',
            'qls-commission' => 'commission',
            'qls-withdraw'   => 'withdraw',
        ];

        $template_name = $template_map[$active_tab] ?? '';
        if (!$template_name) {
            return;
        }

        $template_path = QILINGSHOP_PATH . 'templates/account/' . $template_name . '.php';

        if (file_exists($template_path)) {
            $account_style = sanitize_key((string) get_option('qilingshop_account_style', 'fresh'));
            if (!in_array($account_style, ['fresh', 'business', 'coral', 'emerald'], true)) {
                $account_style = 'fresh';
            }

            // 传递变量给模板
            $current_user = wp_get_current_user();
            echo '<div class="qls-account-skin qls-account-skin-' . esc_attr($account_style) . '">';
            include $template_path;
            echo '</div>';
        } else {
            echo '<div class="account-section"><p>' . esc_html__('模板文件不存在', 'qilingshop') . '</p></div>';
        }
    }

    /**
     * 在个人中心页面加载资源
     */
    public function enqueue_account_assets() {
        // 检查是否在个人中心页面
        if (!is_page_template('templates/template-account.php')) {
            return;
        }

        // 仅在积分商城标签页加载资源。
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : '';
        if (!array_key_exists($active_tab, $this->get_tabs()) && $active_tab !== '') {
            return;
        }

        // 加载CSS
        wp_enqueue_style(
            'qilingshop-account',
            QILINGSHOP_URL . 'static/css/qilingshop-account.css',
            [],
            qilingshop_get_assets_version()
        );

        // 加载JS
        wp_enqueue_script(
            'qilingshop-account',
            QILINGSHOP_URL . 'static/js/qilingshop-account.js',
            ['jquery'],
            qilingshop_get_assets_version(),
            true
        );

        wp_localize_script('qilingshop-account', 'qilingshopAccount', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('qilingshop_account'),
            'userId'  => get_current_user_id(),
            'i18n'    => [
                'loading'    => __('加载中...', 'qilingshop'),
                'loadFailed' => __('加载失败', 'qilingshop'),
                'copied'     => __('已复制', 'qilingshop'),
                'copyFailed' => __('复制失败', 'qilingshop'),
                'confirm'    => __('确定', 'qilingshop'),
                'cancel'     => __('取消', 'qilingshop'),
            ],
        ]);
    }

    /**
     * 渲染顶部 VIP 图标
     */
    public function render_header_vip_icon() {
        $vip_icon = get_option('qilingshop_vip_icon');
        
        // 如果未设置图标，则不显示
        if (empty($vip_icon)) {
            return;
        }

        // 确定链接目标：优先使用 VIP 落地页，其次使用个人中心 VIP 标签页
        $vip_page_id = get_option('qilingshop_page_vip_landing');
        if ($vip_page_id && get_post_status($vip_page_id) === 'publish') {
            $vip_url = get_permalink($vip_page_id);
        } else {
            $vip_url = $this->get_account_tab_url('qls-vip');
        }
        
        ?>
        <div class="header-vip qls-header-vip">
            <a href="<?php echo esc_url($vip_url); ?>" class="header-vip-btn" title="<?php esc_attr_e('VIP会员', 'qilingshop'); ?>">
                <?php echo qilingshop_render_icon($vip_icon, 'qls-header-vip-icon'); ?>
            </a>
        </div>
        <?php
    }

    /**
     * 渲染顶部购物车按钮
     */
    public function render_header_cart_button() {
        $cart_count = qls_cart()->get_count();
        $cart_url = qls_shop_public()->get_page_url('cart');

        // Check if cart icon should be hidden
        if (get_option('qls_shop_header_hide_cart_icon', false)) {
            return;
        }
        ?>
        <div class="header-cart qls-header-cart">
            <a href="<?php echo esc_url($cart_url); ?>" class="header-cart-btn" title="<?php esc_attr_e('购物车', 'qilingshop'); ?>">
                <?php 
                $custom_icon = get_option('qls_shop_header_cart_icon');
                if (!empty($custom_icon)) {
                    echo qilingshop_render_icon($custom_icon, 'qls-header-cart-icon');
                } else {
                    // Default SVG
                ?>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="9" cy="21" r="1"></circle>
                    <circle cx="20" cy="21" r="1"></circle>
                    <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                </svg>
                <?php } ?>
                <?php if ($cart_count > 0): ?>
                <span class="cart-count"><?php echo esc_html($cart_count); ?></span>
                <?php endif; ?>
            </a>
        </div>
        <?php
    }

    /**
     * 渲染用户下拉菜单商城入口
     */
    public function render_user_dropdown_shop_link() {
        if (!(bool) get_option('qls_shop_enabled', true)) {
            return;
        }

        $shop_center_url = qls_shop_public()->get_page_url('shop-center');
        if (!$shop_center_url) {
            $shop_center_url = $this->get_account_tab_url('qls-shop');
        }
        ?>
        <a href="<?php echo esc_url($shop_center_url); ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path>
                <line x1="3" y1="6" x2="21" y2="6"></line>
                <path d="M16 10a4 4 0 0 1-8 0"></path>
            </svg>
            <?php _e('商城中心', 'qilingshop'); ?>
        </a>
        <?php
    }

    /**
     * 强制使用全屏模板
     */
    public function force_fullscreen_template($template) {
        $shop_pages = [
             qls_shop_public()->get_page_id('shop'),
             qls_shop_public()->get_page_id('cart'),
             qls_shop_public()->get_page_id('checkout'),
             qls_shop_public()->get_page_id('orders'),
             qls_shop_public()->get_page_id('shop-center'),
             qls_shop_public()->get_page_id('coupon-center'),
             qls_shop_public()->get_page_id('group-center'),
             qls_shop_public()->get_page_id('group-detail'),
        ];
        
        // 过滤掉空值
        $shop_pages = array_filter($shop_pages);
        
        if (is_page($shop_pages) || is_post_type_archive('qls_product') || is_tax('qls_category')) {
             $fullscreen = locate_template('templates/template-fullscreen.php');
             if ($fullscreen) return $fullscreen;
        }

        // Additional check for shortcodes
        global $post;
        if ($post) {
            // 商城中心短代码
            if (has_shortcode($post->post_content, 'qls_shop_center')) {
                 $fullscreen = locate_template('templates/template-fullscreen.php');
                 if ($fullscreen) return $fullscreen;
            }
            // 优惠券中心短代码
            if (has_shortcode($post->post_content, 'qls_coupon_center')) {
                 $fullscreen = locate_template('templates/template-fullscreen.php');
                 if ($fullscreen) return $fullscreen;
            }
            if (has_shortcode($post->post_content, 'qls_group_center')) {
                 $fullscreen = locate_template('templates/template-fullscreen.php');
                 if ($fullscreen) return $fullscreen;
            }
            if (has_shortcode($post->post_content, 'qls_group_detail')) {
                 $fullscreen = locate_template('templates/template-fullscreen.php');
                 if ($fullscreen) return $fullscreen;
            }
        }
        
        return $template;
    }

    /**
     * 控制页面头部显示
     */
    public function control_page_header($show, $post_id) {
        // 商城首页不显示头部面包屑
        $shop_page_id = qls_shop_public()->get_page_id('shop');
        if ($shop_page_id && $post_id == $shop_page_id) {
            return false;
        }
        
        // 优惠券中心不显示头部面包屑
        $coupon_center_page_id = qls_shop_public()->get_page_id('coupon-center');
        if ($coupon_center_page_id && $post_id == $coupon_center_page_id) {
            return false;
        }

        $group_center_page_id = qls_shop_public()->get_page_id('group-center');
        if ($group_center_page_id && $post_id == $group_center_page_id) {
            return false;
        }

        $group_detail_page_id = qls_shop_public()->get_page_id('group-detail');
        if ($group_detail_page_id && $post_id == $group_detail_page_id) {
            return false;
        }
        
        // 检查是否包含优惠券中心短代码
        $post = get_post($post_id);
        if ($post && has_shortcode($post->post_content, 'qls_coupon_center')) {
            return false;
        }
        if ($post && has_shortcode($post->post_content, 'qls_group_center')) {
            return false;
        }
        if ($post && has_shortcode($post->post_content, 'qls_group_detail')) {
            return false;
        }
        
        return $show;
    }
}
