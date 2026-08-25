<?php
/**
 * 商城真实前台页面管理器。
 *
 * 统一按固定短代码识别页面，避免标题、slug 或保存的 page_id 变更后重复创建。
 *
 * @package QilingShop
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_Shop_Page_Manager {
    /**
     * 激活和修复时不复用的页面状态。
     *
     * @return string[]
     */
    private static function ignored_statuses() {
        return ['trash', 'auto-draft', 'inherit'];
    }

    /**
     * VIP 落地页定义。
     *
     * @return array
     */
    public static function get_vip_landing_page_definition() {
        return [
            'key'       => 'vip_landing',
            'title'     => __('VIP会员介绍', 'qilingshop'),
            'slug'      => 'vip-introduction',
            'shortcode' => 'qilingshop_vip_landing',
            'content'   => '[qilingshop_vip_landing]',
            'options'   => ['qilingshop_page_vip_landing'],
        ];
    }

    /**
     * 订单查询页定义。
     *
     * @return array
     */
    public static function get_order_query_page_definition() {
        return [
            'key'       => 'order_query',
            'title'     => __('订单查询', 'qilingshop'),
            'slug'      => 'qls-order-query',
            'shortcode' => 'qilingshop_order_query',
            'content'   => '[qilingshop_order_query]',
            'options'   => ['qls_shop_page_order_query', 'qilingshop_page_order_query'],
        ];
    }

    /**
     * 虚拟发卡首页定义。
     *
     * 该页面受独立开关控制，不作为关闭状态下的必建默认页。
     *
     * @return array
     */
    public static function get_virtual_home_page_definition() {
        return [
            'key'       => 'virtual_home',
            'title'     => __('虚拟发卡', 'qilingshop'),
            'slug'      => 'qls-card-shop',
            'shortcode' => 'qls_shop_virtual_home',
            'content'   => '[qls_shop_virtual_home]',
            'options'   => ['qls_shop_page_virtual_home'],
        ];
    }

    /**
     * 虚拟发卡首页是否参与自动创建/自动修复。
     *
     * @return bool
     */
    private static function should_auto_manage_virtual_home_page() {
        $enabled = get_option('qls_shop_virtual_home_enabled', '__qls_missing__');
        if ($enabled === '__qls_missing__') {
            return sanitize_key((string) get_option('qls_shop_home_mode', 'decoration')) === 'virtual_card';
        }

        return (bool) $enabled;
    }

    /**
     * 任务中心页定义。
     *
     * @return array
     */
    public static function get_task_center_page_definition() {
        return [
            'key'       => 'task_center',
            'title'     => __('任务中心', 'qilingshop'),
            'slug'      => 'qilingshop-task-center',
            'shortcode' => 'qilingshop_task_center',
            'content'   => '[qilingshop_task_center]',
            'options'   => ['qilingshop_task_center_page_id'],
        ];
    }

    /**
     * 商城模块会真实创建的默认页面定义。
     *
     * 不包含商品详情、分类页等虚拟前台页面。
     *
     * @return array<string,array>
     */
    public static function get_default_shop_page_definitions() {
        $definitions = [
            'shop' => [
                'key'       => 'shop',
                'title'     => __('商城', 'qilingshop'),
                'slug'      => 'qls-shop',
                'shortcode' => 'qls_shop',
                'content'   => '[qls_shop]',
                'options'   => ['qls_shop_page_shop'],
            ],
            'cart' => [
                'key'       => 'cart',
                'title'     => __('购物车', 'qilingshop'),
                'slug'      => 'qls-cart',
                'shortcode' => 'qls_cart',
                'content'   => '[qls_cart]',
                'options'   => ['qls_shop_page_cart'],
            ],
            'checkout' => [
                'key'       => 'checkout',
                'title'     => __('结账', 'qilingshop'),
                'slug'      => 'qls-checkout',
                'shortcode' => 'qls_checkout',
                'content'   => '[qls_checkout]',
                'options'   => ['qls_shop_page_checkout'],
            ],
            'orders' => [
                'key'       => 'orders',
                'title'     => __('我的订单', 'qilingshop'),
                'slug'      => 'qls-my-orders',
                'shortcode' => 'qls_my_orders',
                'content'   => '[qls_my_orders]',
                'options'   => ['qls_shop_page_orders'],
            ],
            'my_tickets' => [
                'key'       => 'my_tickets',
                'title'     => __('售后工单', 'qilingshop'),
                'slug'      => 'qls-my-tickets',
                'shortcode' => 'qls_my_tickets',
                'content'   => '[qls_my_tickets]',
                'options'   => ['qls_shop_page_my_tickets', 'qls_shop_page_my-tickets'],
            ],
            'center' => [
                'key'       => 'center',
                'title'     => __('商城中心', 'qilingshop'),
                'slug'      => 'qls-shop-center',
                'shortcode' => 'qls_shop_center',
                'content'   => '[qls_shop_center]',
                'options'   => ['qls_shop_page_center', 'qls_shop_page_shop-center'],
            ],
            'all_products' => [
                'key'       => 'all_products',
                'title'     => __('全部商品', 'qilingshop'),
                'slug'      => 'qls-all-products',
                'shortcode' => 'qls_all_products',
                'content'   => '[qls_all_products]',
                'options'   => ['qls_shop_page_all_products'],
            ],
            'new_user_zone' => [
                'key'       => 'new_user_zone',
                'title'     => __('新人专区', 'qilingshop'),
                'slug'      => 'qls-new-user-zone',
                'shortcode' => 'qls_new_user_zone',
                'content'   => '[qls_new_user_zone]',
                'options'   => ['qls_shop_page_new_user_zone'],
            ],
            'coupon_center' => [
                'key'       => 'coupon_center',
                'title'     => __('优惠券中心', 'qilingshop'),
                'slug'      => 'qls-coupon-center',
                'shortcode' => 'qls_coupon_center',
                'content'   => '[qls_coupon_center]',
                'options'   => ['qls_shop_page_coupon_center', 'qls_shop_page_coupon-center'],
            ],
            'group_center' => [
                'key'       => 'group_center',
                'title'     => __('团购中心', 'qilingshop'),
                'slug'      => 'qls-group-center',
                'shortcode' => 'qls_group_center',
                'content'   => '[qls_group_center]',
                'options'   => ['qls_shop_page_group_center', 'qls_shop_page_group-center'],
            ],
            'group_detail' => [
                'key'       => 'group_detail',
                'title'     => __('团购详情', 'qilingshop'),
                'slug'      => 'qls-group-detail',
                'shortcode' => 'qls_group_detail',
                'content'   => '[qls_group_detail]',
                'options'   => ['qls_shop_page_group_detail', 'qls_shop_page_group-detail'],
            ],
            'assist_center' => [
                'key'       => 'assist_center',
                'title'     => __('好友助力中心', 'qilingshop'),
                'slug'      => 'qls-assist-center',
                'shortcode' => 'qls_assist_center',
                'content'   => '[qls_assist_center]',
                'options'   => ['qls_shop_page_assist_center', 'qls_shop_page_assist-center'],
            ],
            'assist_detail' => [
                'key'       => 'assist_detail',
                'title'     => __('助力详情', 'qilingshop'),
                'slug'      => 'qls-assist-detail',
                'shortcode' => 'qls_assist_detail',
                'content'   => '[qls_assist_detail]',
                'options'   => ['qls_shop_page_assist_detail', 'qls_shop_page_assist-detail'],
            ],
            'my_assists' => [
                'key'       => 'my_assists',
                'title'     => __('我的助力', 'qilingshop'),
                'slug'      => 'qls-my-assists',
                'shortcode' => 'qls_my_assists',
                'content'   => '[qls_my_assists]',
                'options'   => ['qls_shop_page_my_assists', 'qls_shop_page_my-assists'],
            ],
            'my_downloads' => [
                'key'       => 'my_downloads',
                'title'     => __('我的下载', 'qilingshop'),
                'slug'      => 'qls-my-downloads',
                'shortcode' => 'qls_my_downloads',
                'content'   => '[qls_my_downloads]',
                'options'   => ['qls_shop_page_my_downloads', 'qls_shop_page_my-downloads'],
            ],
            'order_query' => self::get_order_query_page_definition(),
        ];

        if (self::should_auto_manage_virtual_home_page()) {
            $definitions = array_slice($definitions, 0, 1, true)
                + ['virtual_home' => self::get_virtual_home_page_definition()]
                + array_slice($definitions, 1, null, true);
        }

        return $definitions;
    }

    /**
     * 获取单个商城默认页面定义。
     *
     * @param string $key 页面键名。
     * @return array|null
     */
    public static function get_default_shop_page_definition($key) {
        $key = sanitize_key((string) $key);
        if ($key === 'virtual_home') {
            return self::get_virtual_home_page_definition();
        }

        $definitions = self::get_default_shop_page_definitions();

        return isset($definitions[$key]) ? $definitions[$key] : null;
    }

    /**
     * 批量确保页面存在。
     *
     * @param array $definitions 页面定义列表。
     * @return array
     */
    public static function ensure_pages($definitions) {
        $results = [];

        if (!is_array($definitions)) {
            return $results;
        }

        foreach ($definitions as $key => $definition) {
            $result_key = is_string($key) ? $key : (isset($definition['key']) ? (string) $definition['key'] : (string) $key);
            $results[$result_key] = self::ensure_page($definition);
        }

        return $results;
    }

    /**
     * 确保一个真实前台页面存在。
     *
     * @param array $definition 页面定义。
     * @return array{id:int,title:string,created:bool,reused:bool,error:string}
     */
    public static function ensure_page($definition) {
        $definition = self::normalize_definition($definition);
        $result = [
            'id'      => 0,
            'title'   => isset($definition['title']) ? (string) $definition['title'] : '',
            'created' => false,
            'reused'  => false,
            'error'   => '',
        ];

        if (empty($definition['shortcode']) || empty($definition['title'])) {
            $result['error'] = 'invalid_definition';
            return $result;
        }

        $page_id = self::get_valid_page_id_from_options($definition['options'], $definition['shortcode']);

        if ($page_id <= 0) {
            $page_id = self::find_page_id_by_shortcode($definition['shortcode']);
        }

        if ($page_id > 0) {
            self::update_page_options($definition, $page_id);
            $result['id'] = $page_id;
            $result['reused'] = true;
            return $result;
        }

        $page_id = wp_insert_post([
            'post_title'     => $definition['title'],
            'post_name'      => $definition['slug'],
            'post_content'   => $definition['content'],
            'post_status'    => 'publish',
            'post_type'      => 'page',
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ], true);

        if (!$page_id || is_wp_error($page_id)) {
            $result['error'] = is_wp_error($page_id) ? $page_id->get_error_message() : 'insert_failed';
            return $result;
        }

        $page_id = (int) $page_id;
        self::update_page_options($definition, $page_id);

        $result['id'] = $page_id;
        $result['created'] = true;

        return $result;
    }

    /**
     * 页面定义的保存 option 是否仍指向有效页面。
     *
     * @param array $definition 页面定义。
     * @return bool
     */
    public static function has_valid_page($definition) {
        $definition = self::normalize_definition($definition);
        if (empty($definition['shortcode'])) {
            return false;
        }

        $page_id = self::get_valid_page_id_from_options($definition['options'], $definition['shortcode']);
        if ($page_id <= 0) {
            return false;
        }

        self::update_page_options($definition, $page_id);
        return true;
    }

    /**
     * 页面定义列表的保存 option 是否都仍有效。
     *
     * @param array $definitions 页面定义列表。
     * @return bool
     */
    public static function all_pages_have_valid_options($definitions) {
        if (!is_array($definitions)) {
            return false;
        }

        foreach ($definitions as $definition) {
            if (!self::has_valid_page($definition)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 从保存的 option 中找有效页面。
     *
     * @param string[] $options   option keys。
     * @param string   $shortcode 短代码 tag。
     * @return int
     */
    public static function get_valid_page_id_from_options($options, $shortcode) {
        $options = is_array($options) ? $options : [];

        foreach ($options as $option_key) {
            $page_id = (int) get_option((string) $option_key, 0);
            if ($page_id > 0 && self::is_valid_page_for_shortcode($page_id, $shortcode)) {
                return $page_id;
            }
        }

        return 0;
    }

    /**
     * 按短代码扫描非回收站页面。
     *
     * @param string $shortcode 短代码 tag。
     * @return int
     */
    public static function find_page_id_by_shortcode($shortcode) {
        $shortcode = sanitize_key((string) $shortcode);
        if ($shortcode === '') {
            return 0;
        }

        global $wpdb;
        if (!$wpdb || empty($wpdb->posts)) {
            return 0;
        }

        $like = '%[' . $wpdb->esc_like($shortcode) . '%';
        $sql = $wpdb->prepare(
            "SELECT ID
             FROM {$wpdb->posts}
             WHERE post_type = 'page'
               AND post_status NOT IN ('trash', 'auto-draft', 'inherit')
               AND post_content LIKE %s
             ORDER BY CASE WHEN post_status = 'publish' THEN 0 ELSE 1 END, ID ASC
             LIMIT 100",
            $like
        );
        $candidate_ids = $wpdb->get_col($sql);

        if (empty($candidate_ids)) {
            return 0;
        }

        foreach ($candidate_ids as $candidate_id) {
            $candidate_id = (int) $candidate_id;
            if ($candidate_id > 0 && self::is_valid_page_for_shortcode($candidate_id, $shortcode)) {
                return $candidate_id;
            }
        }

        return 0;
    }

    /**
     * 判断页面是否有效且包含指定短代码。
     *
     * @param int    $page_id   页面 ID。
     * @param string $shortcode 短代码 tag。
     * @return bool
     */
    public static function is_valid_page_for_shortcode($page_id, $shortcode) {
        $page_id = (int) $page_id;
        $shortcode = sanitize_key((string) $shortcode);

        if ($page_id <= 0 || $shortcode === '') {
            return false;
        }

        $page = get_post($page_id);
        if (!($page instanceof WP_Post) || $page->post_type !== 'page') {
            return false;
        }

        if (in_array((string) $page->post_status, self::ignored_statuses(), true)) {
            return false;
        }

        return self::content_has_shortcode((string) $page->post_content, $shortcode);
    }

    /**
     * 不依赖 shortcode 是否已注册，精确判断内容中是否包含指定短代码 tag。
     *
     * @param string $content   页面内容。
     * @param string $shortcode 短代码 tag。
     * @return bool
     */
    public static function content_has_shortcode($content, $shortcode) {
        $content = (string) $content;
        $shortcode = sanitize_key((string) $shortcode);

        if ($content === '' || $shortcode === '' || strpos($content, '[' . $shortcode) === false) {
            return false;
        }

        if (function_exists('get_shortcode_regex')) {
            $pattern = '/' . get_shortcode_regex([$shortcode]) . '/';
            if (@preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    if (isset($match[2]) && $match[2] === $shortcode) {
                        return true;
                    }
                }
            }
        }

        return (bool) preg_match('/\[' . preg_quote($shortcode, '/') . '(?:[\s\]\/=]|$)/', $content);
    }

    /**
     * 标准化页面定义。
     *
     * @param array $definition 页面定义。
     * @return array
     */
    private static function normalize_definition($definition) {
        $definition = is_array($definition) ? $definition : [];
        $shortcode = isset($definition['shortcode']) ? sanitize_key((string) $definition['shortcode']) : '';
        $title = isset($definition['title']) ? (string) $definition['title'] : '';
        $slug = isset($definition['slug']) ? sanitize_title((string) $definition['slug']) : '';
        $content = isset($definition['content']) ? (string) $definition['content'] : '';

        if ($content === '' && $shortcode !== '') {
            $content = '[' . $shortcode . ']';
        }

        if ($slug === '' && $title !== '') {
            $slug = sanitize_title($title);
        }

        $options = [];
        if (isset($definition['options']) && is_array($definition['options'])) {
            $options = $definition['options'];
        } elseif (!empty($definition['option'])) {
            $options = [(string) $definition['option']];
        }

        $options = array_values(array_unique(array_filter(array_map('sanitize_key', $options))));

        return [
            'key'       => isset($definition['key']) ? sanitize_key((string) $definition['key']) : '',
            'title'     => $title,
            'slug'      => $slug,
            'shortcode' => $shortcode,
            'content'   => $content,
            'options'   => $options,
        ];
    }

    /**
     * 回写页面 ID 到所有相关 option。
     *
     * @param array $definition 页面定义。
     * @param int   $page_id    页面 ID。
     * @return void
     */
    private static function update_page_options($definition, $page_id) {
        $page_id = (int) $page_id;
        if ($page_id <= 0 || empty($definition['options'])) {
            return;
        }

        foreach ($definition['options'] as $option_key) {
            update_option((string) $option_key, $page_id);
        }

        if (in_array('qls_shop_page_all_products', $definition['options'], true)) {
            self::sync_all_products_page_slug($page_id);
        }
    }

    /**
     * 同步“全部商品”真实页面 slug，供 rewrite 读取。
     *
     * @param int $page_id 页面 ID。
     * @return void
     */
    private static function sync_all_products_page_slug($page_id) {
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
}
