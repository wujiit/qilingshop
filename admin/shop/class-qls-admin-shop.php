<?php
/**
 * 电商后台管理主类
 * 
 * 注册后台菜单和资源
 *
 * @package QilingShop
 * @since   2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_Admin_Shop {

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
        add_action('admin_menu', [$this, 'add_menu'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_dashboard_setup', [$this, 'register_dashboard_widget']);
        add_action('admin_init', [$this, 'check_flush_rewrite']);
        add_filter('admin_body_class', [$this, 'filter_admin_body_class']);
        
        // AJAX: 一键创建页面
        add_action('wp_ajax_qls_create_shop_pages', [$this, 'ajax_create_pages']);
        
        // AJAX: 订单详情
        add_action('wp_ajax_qls_shop_get_order_details', [$this, 'ajax_get_order_details']);
        add_action('wp_ajax_qls_shop_generate_waybill', [$this, 'ajax_generate_waybill']);
        add_action('wp_ajax_qls_shop_update_receiver_info', [$this, 'ajax_update_receiver_info']);
        add_action('wp_ajax_qls_shop_delete_sku', [$this, 'ajax_delete_sku']);
        add_action('wp_ajax_qls_assist_search_products', [$this, 'ajax_assist_search_products']);
        add_action('wp_ajax_qls_render_builder_item', [$this, 'ajax_render_builder_item']);
        
        // Meta Boxes
        add_action('add_meta_boxes', [$this, 'register_meta_boxes'], 10, 2);
        add_action('save_post', [$this, 'save_decoration_meta']);
        
        // 检查版本更新
        add_action('admin_init', [$this, 'check_version']);
        
        // 处理导出
        add_action('admin_init', [$this, 'handle_export']);
        add_action('admin_init', [$this, 'handle_waybill_print']);
    }

    /**
     * 注册 WordPress 后台首页的小型经营概览。
     */
    public function register_dashboard_widget() {
        if (!current_user_can('manage_options')) {
            return;
        }

        wp_add_dashboard_widget(
            'qilingshop_dashboard_widget',
            __('启灵商城概览', 'qilingshop'),
            [$this, 'render_dashboard_widget']
        );
    }

    /**
     * 渲染 WordPress 仪表盘小组件。
     */
    public function render_dashboard_widget() {
        $shop_db = QLS_Shop_Database::instance();
        $db = QilingShop_Database::instance();
        $wpdb = $shop_db->get_wpdb();
        $table_shop_orders = $shop_db->get_table('orders');
        $table_resource_orders = $db->get_table('orders');
        $table_recharge = $db->get_table('recharge');
        $today_start = current_time('Y-m-d') . ' 00:00:00';
        $today_end = date('Y-m-d H:i:s', strtotime($today_start . ' +1 day'));

        $shop_stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(CASE WHEN status >= 1 THEN 1 END) AS paid_orders,
                COUNT(CASE WHEN status = 0 THEN 1 END) AS pending_orders,
                COALESCE(SUM(CASE WHEN status >= 1 THEN final_amount ELSE 0 END), 0) AS revenue_total,
                COALESCE(SUM(CASE WHEN status >= 1 AND created_at >= %s AND created_at < %s THEN final_amount ELSE 0 END), 0) AS revenue_today
             FROM {$table_shop_orders}",
            $today_start,
            $today_end
        ));

        $resource_stats = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS paid_orders,
                    COALESCE(SUM(final_price), 0) AS revenue_total,
                    COALESCE(SUM(CASE WHEN paid_at >= %s AND paid_at < %s THEN final_price ELSE 0 END), 0) AS revenue_today
             FROM {$table_resource_orders}
             WHERE status = 1",
            $today_start,
            $today_end
        ));
        $recharge_stats = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS paid_orders,
                    COALESCE(SUM(amount), 0) AS revenue_total,
                    COALESCE(SUM(CASE WHEN paid_at >= %s AND paid_at < %s THEN amount ELSE 0 END), 0) AS revenue_today
             FROM {$table_recharge}
             WHERE status = 1",
            $today_start,
            $today_end
        ));

        $shop_stats = $shop_stats ?: (object) [
            'paid_orders' => 0,
            'pending_orders' => 0,
            'revenue_total' => 0,
            'revenue_today' => 0,
        ];
        $resource_stats = $resource_stats ?: (object) ['paid_orders' => 0, 'revenue_total' => 0, 'revenue_today' => 0];
        $recharge_stats = $recharge_stats ?: (object) ['paid_orders' => 0, 'revenue_total' => 0, 'revenue_today' => 0];

        $stats = (object) [
            'paid_orders' => (int) $shop_stats->paid_orders + (int) $resource_stats->paid_orders + (int) $recharge_stats->paid_orders,
            'pending_orders' => (int) $shop_stats->pending_orders,
            'revenue_total' => (float) $shop_stats->revenue_total + (float) $resource_stats->revenue_total + (float) $recharge_stats->revenue_total,
            'revenue_today' => (float) $shop_stats->revenue_today + (float) $resource_stats->revenue_today + (float) $recharge_stats->revenue_today,
        ];

        $shop_url = admin_url('admin.php?page=qls-shop');
        ?>
        <div class="qls-dashboard-widget">
            <div class="qls-dashboard-widget-grid">
                <div class="qls-dashboard-widget-stat">
                    <span class="qls-dashboard-widget-label"><?php esc_html_e('累计收入', 'qilingshop'); ?></span>
                    <strong><?php echo esc_html('¥' . number_format((float) $stats->revenue_total, 2)); ?></strong>
                </div>
                <div class="qls-dashboard-widget-stat">
                    <span class="qls-dashboard-widget-label"><?php esc_html_e('今日收入', 'qilingshop'); ?></span>
                    <strong><?php echo esc_html('¥' . number_format((float) $stats->revenue_today, 2)); ?></strong>
                </div>
                <div class="qls-dashboard-widget-stat">
                    <span class="qls-dashboard-widget-label"><?php esc_html_e('有效订单', 'qilingshop'); ?></span>
                    <strong><?php echo esc_html(number_format((int) $stats->paid_orders)); ?></strong>
                </div>
                <div class="qls-dashboard-widget-stat is-warning">
                    <span class="qls-dashboard-widget-label"><?php esc_html_e('待处理订单', 'qilingshop'); ?></span>
                    <strong><?php echo esc_html(number_format((int) $stats->pending_orders)); ?></strong>
                </div>
            </div>
            <div class="qls-dashboard-widget-footer">
                <span><?php esc_html_e('数据按已支付订单统计', 'qilingshop'); ?></span>
                <a href="<?php echo esc_url($shop_url); ?>"><?php esc_html_e('查看商城数据', 'qilingshop'); ?> &rarr;</a>
            </div>
        </div>
        <?php
    }

// ... existing code ...

    /**
     * 注册 Meta Boxes
     */
    public function register_meta_boxes($post_type = '', $post = null) {
        if ($post_type !== 'page') {
            return;
        }

        add_meta_box(
            'qls_shop_decoration_box',
            __('启灵店铺装修', 'qilingshop'),
            [$this, 'render_decoration_meta_box'],
            'page',
            'advanced', // context: normal/advanced/side
            'high'      // priority
        );
    }

    /**
     * 渲染装修 Meta Box (Builder Mode)
     */
    public function render_decoration_meta_box($post) {
        if ($post instanceof WP_Post && $this->is_virtual_home_decoration_blocked((int) $post->ID)) {
            echo '<p class="qls-builder-skip-note">' . esc_html__('虚拟发卡首页使用专用模板，不支持商城自定义装修。', 'qilingshop') . '</p>';
            echo '<p class="qls-builder-skip-tip">' . esc_html__('常规实物商城首页请继续使用 [qls_shop] 页面进行装修。', 'qilingshop') . '</p>';
            return;
        }

        if (!$this->should_enable_builder_for_post($post)) {
            echo '<p class="qls-builder-skip-note">' . esc_html__('当前页面未检测到商城短代码或商城页面绑定，已跳过装修器加载以提升编辑速度。', 'qilingshop') . '</p>';
            echo '<p class="qls-builder-skip-tip">' . esc_html__('如需在本页启用商城装修，请先插入商城短代码并保存，或在 URL 后追加参数 qls_shop_builder=1。', 'qilingshop') . '</p>';
            return;
        }

        // Enqueue 排序
        wp_enqueue_script('jquery-ui-sortable');
        
        $saved_layout = $this->normalize_layout_value(get_post_meta($post->ID, '_qls_shop_layout', true));
        if (!is_array($saved_layout)) {
            $saved_layout = [];
        }
        
        include QILINGSHOP_PATH . 'admin/shop/views/meta-box-builder.php';
    }

    /**
     * 当前页面是否需要启用商城装修器
     */
    private function should_enable_builder_for_post($post) {
        if (!($post instanceof WP_Post) || $post->post_type !== 'page') {
            return false;
        }
        if ($this->is_virtual_home_decoration_blocked((int) $post->ID)) {
            return false;
        }
        return true;
    }

    /**
     * 虚拟发卡首页使用专用模板，不进入商城装修器。
     *
     * @param int $post_id
     * @return bool
     */
    private function is_virtual_home_decoration_blocked($post_id) {
        $post_id = absint($post_id);
        if ($post_id <= 0) {
            return false;
        }

        if ($post_id === (int) get_option('qls_shop_page_virtual_home', 0)) {
            return true;
        }

        $post = get_post($post_id);
        if (!($post instanceof WP_Post)) {
            return false;
        }

        $content = (string) $post->post_content;
        if (has_shortcode($content, 'qls_shop_virtual_home')) {
            return true;
        }

        return has_shortcode($content, 'qls_shop')
            && (bool) preg_match('/\[qls_shop[^\]]*\bmode=(["\']?)virtual_card\1/i', $content);
    }

    private function get_shop_page_option_keys() {
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
     * 订单表是否具备仅针对 order_no 的唯一索引。
     *
     * 正常库存在该约束时，后台订单列表无需再执行按 order_no 去重的重查询。
     * 只有旧库或异常库缺少唯一约束时，才保留历史兼容逻辑。
     *
     * @param string $table_orders
     * @return bool
     */
    private function orders_table_has_unique_order_no_index($table_orders) {
        static $cache = [];

        $table_orders = (string) $table_orders;
        if ($table_orders === '') {
            return false;
        }

        if (isset($cache[$table_orders])) {
            return $cache[$table_orders];
        }

        $db = QLS_Shop_Database::instance();
        $wpdb = $db->get_wpdb();
        $indexes = $wpdb->get_results("SHOW INDEX FROM `{$table_orders}`");
        if (!is_array($indexes) || empty($indexes)) {
            $cache[$table_orders] = false;
            return false;
        }

        $grouped = [];
        foreach ($indexes as $index) {
            $key_name = isset($index->Key_name) ? (string) $index->Key_name : '';
            $column_name = isset($index->Column_name) ? (string) $index->Column_name : '';
            if ($key_name === '' || $column_name === '') {
                continue;
            }

            if (!isset($grouped[$key_name])) {
                $grouped[$key_name] = [
                    'non_unique' => isset($index->Non_unique) ? (int) $index->Non_unique : 1,
                    'columns'    => [],
                ];
            }

            $seq = isset($index->Seq_in_index) ? (int) $index->Seq_in_index : count($grouped[$key_name]['columns']) + 1;
            $grouped[$key_name]['columns'][$seq] = $column_name;
        }

        foreach ($grouped as $group) {
            if ((int) $group['non_unique'] !== 0) {
                continue;
            }

            ksort($group['columns']);
            $columns = array_values($group['columns']);
            if ($columns === ['order_no']) {
                $cache[$table_orders] = true;
                return true;
            }
        }

        $cache[$table_orders] = false;
        return false;
    }

    /**
     * 构建后台订单搜索条件。
     *
     * 常见搜索是“订单号”或“完整手机号”，这两类适合走精确匹配，
     * 可以直接命中索引；只有普通关键字才回退到模糊搜索。
     *
     * @param wpdb   $wpdb
     * @param string $keyword
     * @return string
     */
    private function build_admin_order_keyword_where($wpdb, $keyword) {
        $keyword = trim((string) $keyword);
        if ($keyword === '') {
            return '';
        }

        $digits_only = preg_replace('/\D+/', '', $keyword);
        $looks_like_full_phone = $digits_only !== '' && strlen($digits_only) >= 7 && strlen($digits_only) <= 20 && $digits_only === $keyword;
        $looks_like_order_no = (
            preg_match('/^(SHOP|TUAN|SHP|QLS)/i', $keyword)
            || (strlen($keyword) >= 8 && preg_match('/[A-Za-z]/', $keyword) && preg_match('/\d/', $keyword))
        );

        if ($looks_like_order_no) {
            return $wpdb->prepare('(order_no = %s)', $keyword);
        }

        if ($looks_like_full_phone) {
            return $wpdb->prepare('(receiver_phone = %s)', $digits_only);
        }

        $like = '%' . $wpdb->esc_like($keyword) . '%';
        return $wpdb->prepare('(order_no LIKE %s OR receiver_name LIKE %s OR receiver_phone LIKE %s)', $like, $like, $like);
    }

    /**
     * 构建后台订单列表基础 SQL。
     *
     * @param string $table_orders
     * @param array  $where_clauses
     * @param bool   $needs_dedup
     * @param string $select_sql
     * @return string
     */
    private function build_admin_orders_list_sql($table_orders, $where_clauses, $needs_dedup, $select_sql = '*') {
        $table_orders = (string) $table_orders;
        $select_sql = trim((string) $select_sql);
        if ($select_sql === '') {
            $select_sql = '*';
        }

        if ($needs_dedup) {
            $sql = "SELECT {$select_sql} FROM {$table_orders} WHERE id IN (SELECT MAX(id) FROM {$table_orders} GROUP BY order_no)";
            if (!empty($where_clauses)) {
                $sql .= " AND " . implode(' AND ', $where_clauses);
            }

            return $sql;
        }

        $sql = "SELECT {$select_sql} FROM {$table_orders}";
        if (!empty($where_clauses)) {
            $sql .= " WHERE " . implode(' AND ', $where_clauses);
        }

        return $sql;
    }

    /**
     * 获取后台订单列表当前页数据。
     *
     * 深分页时先只取当前页订单 ID，再按 ID 回表加载整行，避免一上来就对大偏移扫描整行数据。
     *
     * @param wpdb   $wpdb
     * @param string $table_orders
     * @param array  $where_clauses
     * @param bool   $needs_dedup
     * @param int    $limit
     * @param int    $offset
     * @return array
     */
    private function get_admin_orders_page_rows($wpdb, $table_orders, $where_clauses, $needs_dedup, $limit, $offset) {
        $limit = max(1, (int) $limit);
        $offset = max(0, (int) $offset);

        $id_sql = $this->build_admin_orders_list_sql($table_orders, $where_clauses, $needs_dedup, 'id');
        $id_sql .= ' ORDER BY id DESC';
        $id_sql .= $wpdb->prepare(' LIMIT %d OFFSET %d', $limit, $offset);

        $id_rows = $wpdb->get_results($id_sql);
        if (empty($id_rows)) {
            return [];
        }

        $order_ids = [];
        foreach ($id_rows as $id_row) {
            $order_id = isset($id_row->id) ? (int) $id_row->id : 0;
            if ($order_id > 0) {
                $order_ids[] = $order_id;
            }
        }

        if (empty($order_ids)) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($order_ids), '%d'));
        $rows_sql = "SELECT * FROM {$table_orders} WHERE id IN ({$placeholders}) ORDER BY id DESC";
        return $wpdb->get_results($wpdb->prepare($rows_sql, $order_ids));
    }

    /**
     * 批量获取后台列表用户昵称映射，避免模板逐行调用 get_user_by()。
     *
     * @param array  $rows
     * @param string $field
     * @return array<int, string>
     */
    private function get_admin_user_display_map($rows, $field = 'user_id') {
        $user_ids = [];
        foreach ((array) $rows as $row) {
            $user_id = isset($row->{$field}) ? (int) $row->{$field} : 0;
            if ($user_id > 0) {
                $user_ids[$user_id] = $user_id;
            }
        }

        if (empty($user_ids)) {
            return [];
        }

        $users = get_users([
            'include' => array_values($user_ids),
            'fields'  => ['ID', 'display_name'],
            'orderby' => 'include',
            'count_total' => false,
        ]);

        $user_map = [];
        foreach ((array) $users as $user) {
            $user_id = isset($user->ID) ? (int) $user->ID : 0;
            if ($user_id > 0) {
                $user_map[$user_id] = isset($user->display_name) ? (string) $user->display_name : '';
            }
        }

        return $user_map;
    }

    /**
     * 批量获取订单买家昵称映射，避免模板逐行调用 get_user_by()。
     *
     * @param array $orders
     * @return array<int, string>
     */
    private function get_admin_order_user_map($orders) {
        return $this->get_admin_user_display_map($orders);
    }

    /**
     * 商城仪表盘缓存时长。
     *
     * @return int
     */
    private function get_dashboard_cache_ttl() {
        $ttl = (int) apply_filters('qls_shop_dashboard_cache_ttl', 180);
        return max(30, $ttl);
    }

    /**
     * 构建商城仪表盘缓存键。
     *
     * @param array $args
     * @return string
     */
    private function build_dashboard_cache_key($args) {
        return 'qls_shop_dashboard_' . md5(wp_json_encode($args));
    }

    /**
     * 运营看板缓存时长。
     *
     * @return int
     */
    private function get_operations_dashboard_cache_ttl() {
        $ttl = (int) apply_filters('qls_shop_operations_dashboard_cache_ttl', 180);
        return max(30, $ttl);
    }

    /**
     * 构建运营看板缓存键。
     *
     * @param array $args
     * @return string
     */
    private function build_operations_dashboard_cache_key($args) {
        return 'qls_shop_operations_dashboard_' . md5(wp_json_encode($args));
    }

    private function post_has_shop_shortcodes($content) {
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

    /**
     * 通过商城前台页面注册表判断当前页面是否属于商城上下文。
     *
     * @param int $post_id 页面ID
     * @return bool
     */
    private function is_registered_shop_page_context($post_id) {
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
    private function normalize_layout_value($layout) {
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
     * 辅助方法：渲染单个 Builder 项目
     * 用于 meta-box-builder.php 中的循环和 AJAX 模板
     */
    public function render_builder_item($idx, $type, $module, $data = [], $is_template = false) {
        // 合并默认值
        if ($is_template && isset($module['defaults'])) {
            $data = $module['defaults'];
        }
        
        // 标题
        $title = $module['name'];
        ?>
        <div class="qls-dsm-item" data-type="<?php echo esc_attr($type); ?>">
            <div class="qls-dsm-item-header">
                <span class="qls-dsm-handle dashicons dashicons-menu"></span>
                <span class="qls-dsm-title"><?php echo esc_html($title); ?></span>
                <span class="qls-dsm-toggle dashicons dashicons-arrow-down-alt2"></span>
                <a href="#" class="qls-dsm-remove"><span class="dashicons dashicons-trash"></span></a>
            </div>
            <div class="qls-dsm-content">
                <input type="hidden" name="qls_modules[<?php echo $idx; ?>][type]" value="<?php echo esc_attr($type); ?>"/>
                
                <?php if (isset($module['fields'])): ?>
                    <?php foreach ($module['fields'] as $group_key => $group): ?>
                        <div class="qls-setting-group">
                            <h4 class="qls-setting-group-title"><?php echo esc_html($group['title']); ?></h4>
                            
                            <?php foreach ($group['fields'] as $key => $field): 
                                $val = $data[$key] ?? ($module['defaults'][$key] ?? '');
                                $name_attr = "qls_modules[$idx][settings][$key]";
                            ?>
                                <div class="qls-dsm-field">
                                    <label><?php echo esc_html($field['label']); ?></label>
                                    
                                    <?php if ($field['type'] === 'text' || $field['type'] === 'number'): ?>
                                        <input type="<?php echo $field['type']; ?>" 
                                               name="<?php echo $name_attr; ?>" 
                                               value="<?php echo esc_attr($val); ?>">
                                               
                                    <?php elseif ($field['type'] === 'select'): ?>
                                        <select name="<?php echo $name_attr; ?>">
                                            <?php foreach ($field['options'] as $opt_val => $opt_label): ?>
                                                <option value="<?php echo esc_attr($opt_val); ?>" <?php selected($val, $opt_val); ?>>
                                                    <?php echo esc_html($opt_label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    
                                    <?php elseif ($field['type'] === 'image'): ?>
                                        <div class="qls-image-uploader">
                                            <input type="text" name="<?php echo $name_attr; ?>" value="<?php echo esc_attr($val); ?>" class="qls-image-url large-text">
                                            <button type="button" class="button qls-upload-btn"><?php _e('选择图片', 'qilingshop'); ?></button>
                                        </div>

                                    <?php elseif ($field['type'] === 'repeater'): ?>
                                        <div class="qls-dsm-repeater">
                                            <div class="qls-dsm-repeater-list">
                                                <?php 
                                                $items = is_array($val) ? $val : [];
                                                if (empty($items)) $items = []; // Start empty or with defaults? 
                                                // If template, items should be empty logic or handled by JS. 
                                                // Actually for PHP render, we iterate.
                                                
                                                // Use a counter for repeater items to ensure unique keys/names?
                                                // Actually, we can use simple indexing if PHP handles array_values.
                                                
                                                foreach ($items as $r_idx => $r_val): 
                                                    // 渲染重复项
                                                    $this->render_repeater_item($idx, $key, $r_idx, $field['fields'], $r_val);
                                                endforeach; 
                                                ?>
                                            </div>
                                            <button type="button" class="qls-dsm-add-repeater-btn" 
                                                data-name-prefix="qls_modules[<?php echo $idx; ?>][settings][<?php echo $key; ?>]"
                                                data-fields='<?php echo esc_attr(json_encode($field['fields'])); ?>'>
                                                <?php _e('添加一项', 'qilingshop'); ?> <span class="dashicons dashicons-plus"></span>
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (isset($field['desc'])): ?>
                                        <p class="description"><?php echo esc_html($field['desc']); ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function ajax_render_builder_item() {
        if (!current_user_can('edit_pages')) {
            wp_send_json_error(__('权限不足', 'qilingshop'));
        }

        check_ajax_referer('qls_save_decoration_meta', 'nonce');

        $type = isset($_POST['type']) ? sanitize_key(wp_unslash($_POST['type'])) : '';
        $idx = isset($_POST['idx']) ? absint($_POST['idx']) : 0;

        if ($type === '') {
            wp_send_json_error(__('模块类型无效', 'qilingshop'));
        }

        $modules = $this->get_available_modules();
        if (!isset($modules[$type])) {
            wp_send_json_error(__('模块不存在', 'qilingshop'));
        }

        ob_start();
        $this->render_builder_item($idx, $type, $modules[$type], [], true);
        $html = ob_get_clean();

        wp_send_json_success($html);
    }

    /**
     * 辅助方法：渲染重复项
     */
    public function render_repeater_item($module_idx, $field_key, $item_idx, $fields, $values = []) {
        ?>
        <div class="qls-dsm-repeater-item">
            <span class="qls-dsm-repeater-remove dashicons dashicons-no-alt"></span>
            <?php foreach ($fields as $sub_key => $sub_field): 
                // e.g. qls_modules[0][settings][slides][0][image]
                // But JS add needs to handle index placeholder.
                // For server-side render:
                $name_attr = "qls_modules[{$module_idx}][settings][{$field_key}][{$item_idx}][{$sub_key}]";
                $val = $values[$sub_key] ?? ($sub_field['default'] ?? '');
            ?>
                <div class="qls-dsm-field">
                    <label><?php echo esc_html($sub_field['label']); ?></label>
                    <?php if ($sub_field['type'] === 'image'): ?>
                         <div class="qls-image-uploader">
                            <input type="text" name="<?php echo $name_attr; ?>" value="<?php echo esc_attr($val); ?>" class="qls-image-url large-text">
                            <button type="button" class="button qls-upload-btn"><?php _e('选择图片', 'qilingshop'); ?></button>
                        </div>
                    <?php elseif ($sub_field['type'] === 'select'): ?>
                         <select name="<?php echo $name_attr; ?>">
                            <?php foreach ($sub_field['options'] as $opt_val => $opt_label): ?>
                                <option value="<?php echo esc_attr($opt_val); ?>" <?php selected($val, $opt_val); ?>>
                                    <?php echo esc_html($opt_label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="<?php echo $sub_field['type']; ?>" name="<?php echo $name_attr; ?>" value="<?php echo esc_attr($val); ?>">
                    <?php endif; ?>
                    
                    <?php if (isset($sub_field['desc'])): ?>
                         <p class="description"><?php echo esc_html($sub_field['desc']); ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php

    }

    /**
     * 保存装修 Meta Data (Modified for PHP array submission)
     */
    public function save_decoration_meta($post_id) {
        // Check verify nonce
        if (!isset($_POST['qls_decoration_nonce']) || !wp_verify_nonce($_POST['qls_decoration_nonce'], 'qls_save_decoration_meta')) {
            return;
        }

        // Autosave check
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

        // Permissions check
        if (!current_user_can('edit_page', $post_id)) return;

        // Save Data (Direct PHP Array from form)
        $sanitized = [];
        if (isset($_POST['qls_modules']) && is_array($_POST['qls_modules'])) {
            $modules = array_values($_POST['qls_modules']);
            foreach ($modules as $mod) {
                if (empty($mod['type'])) continue;
                
                $item = [
                    'id' => 'mod_' . uniqid(),
                    'type' => sanitize_key($mod['type']),
                    'settings' => []
                ];
                
                if (isset($mod['settings']) && is_array($mod['settings'])) {
                    foreach ($mod['settings'] as $k => $v) {
                        $item['settings'][sanitize_key($k)] = $this->sanitize_recursive($v);
                    }
                }
                $sanitized[] = $item;
            }
        }
        update_post_meta($post_id, '_qls_shop_layout', $sanitized);
    }

    /**
     * Recursive sanitization
     */
    private function sanitize_recursive($data) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->sanitize_recursive($value);
            }
            return $data;
        }
        return sanitize_text_field($data);
    }

    /**
     * 检查版本并更新
     */
    public function check_version() {
        $version = get_option('qls_shop_version');
        if (version_compare($version, '2.0.2', '<')) {
            require_once QILINGSHOP_PATH . 'includes/shop/class-qls-shop-activator.php';
            QLS_Shop_Activator::activate();
        }
    }

    /**
     * 添加菜单
     */
    public function add_menu() {
        // 在原有启灵商城菜单下添加子菜单
        add_submenu_page(
            'qilingshop',
            __('实物商城', 'qilingshop'),
            __('🛒 实物商城', 'qilingshop'),
            'manage_options',
            'qls-shop',
            [$this, 'render_dashboard']
        );

        add_submenu_page(
            'qilingshop',
            __('商品管理', 'qilingshop'),
            __('— 商品管理', 'qilingshop'),
            'manage_options',
            'qls-products',
            [$this, 'render_products']
        );

        add_submenu_page(
            'qilingshop',
            __('添加商品', 'qilingshop'),
            __('— 添加商品', 'qilingshop'),
            'manage_options',
            'qls-product-edit',
            [$this, 'render_product_edit']
        );

        add_submenu_page(
            'qilingshop',
            __('卡密管理', 'qilingshop'),
            __('— 卡密管理', 'qilingshop'),
            'manage_options',
            'qls-card-inventory',
            [$this, 'render_card_inventory']
        );

        add_submenu_page(
            'qilingshop',
            __('分类管理', 'qilingshop'),
            __('— 分类管理', 'qilingshop'),
            'manage_options',
            'qls-categories',
            [$this, 'render_categories']
        );

        add_submenu_page(
            'qilingshop',
            __('商城订单', 'qilingshop'),
            __('— 商城订单', 'qilingshop'),
            'manage_options',
            'qls-shop-orders',
            [$this, 'render_orders']
        );

        add_submenu_page(
            'qilingshop',
            __('售后退款', 'qilingshop'),
            __('— 售后退款', 'qilingshop'),
            'manage_options',
            'qls-shop-refunds',
            [$this, 'render_refunds']
        );

        add_submenu_page(
            'qilingshop',
            __('售后工单', 'qilingshop'),
            __('— 售后工单', 'qilingshop'),
            'manage_options',
            'qls-shop-tickets',
            [$this, 'render_tickets']
        );

        add_submenu_page(
            'qilingshop',
            __('发票管理', 'qilingshop'),
            __('— 发票管理', 'qilingshop'),
            'manage_options',
            'qls-shop-invoices',
            [$this, 'render_invoices']
        );

        add_submenu_page(
            'qilingshop',
            __('营销中心', 'qilingshop'),
            __('— 营销中心', 'qilingshop'),
            'manage_options',
            'qls-shop-marketing',
            [$this, 'render_marketing_center']
        );

        add_submenu_page(
            'qilingshop',
            __('优惠券管理', 'qilingshop'),
            __('优惠券管理', 'qilingshop'),
            'manage_options',
            'qls-shop-coupons',
            [$this, 'render_coupons']
        );
        remove_submenu_page('qilingshop', 'qls-shop-coupons');

        add_submenu_page(
            'qilingshop',
            __('评价管理', 'qilingshop'),
            __('— 评价管理', 'qilingshop'),
            'manage_options',
            'qls-shop-reviews',
            [$this, 'render_reviews']
        );

        // 拼团管理菜单
        add_submenu_page(
            'qilingshop',
            __('拼团管理', 'qilingshop'),
            __('— 拼团管理', 'qilingshop'),
            'manage_options',
            'qls-group-manage',
            [$this, 'render_group_manage']
        );

        add_submenu_page(
            'qilingshop',
            __('好友助力活动', 'qilingshop'),
            __('— 助力活动', 'qilingshop'),
            'manage_options',
            'qls-assist-activities',
            [$this, 'render_assist_activities']
        );

        add_submenu_page(
            'qilingshop',
            __('好友助力记录', 'qilingshop'),
            __('— 助力记录', 'qilingshop'),
            'manage_options',
            'qls-assist-campaigns',
            [$this, 'render_assist_campaigns']
        );

        add_submenu_page(
            'qilingshop',
            __('运营看板', 'qilingshop'),
            __('— 运营看板', 'qilingshop'),
            'manage_options',
            'qls-operations-dashboard',
            [$this, 'render_operations_dashboard']
        );

        add_submenu_page(
            'qilingshop',
            __('商城设置', 'qilingshop'),
            __('— 商城设置', 'qilingshop'),
            'manage_options',
            'qls-shop-settings',
            [$this, 'render_settings']
        );
    }

    /**
     * 获取商城后台页面 slug 白名单。
     *
     * @return array<int,string>
     */
    private function get_shop_admin_page_slugs() {
        return [
            'qls-shop',
            'qls-products',
            'qls-product-edit',
            'qls-card-inventory',
            'qls-categories',
            'qls-shop-orders',
            'qls-shop-refunds',
            'qls-shop-invoices',
            'qls-shop-marketing',
            'qls-shop-coupons',
            'qls-shop-reviews',
            'qls-group-manage',
            'qls-assist-activities',
            'qls-assist-campaigns',
            'qls-operations-dashboard',
            'qls-shop-settings',
        ];
    }

    /**
     * 是否商城后台页面。
     *
     * @param string $hook 当前后台钩子。
     * @return bool
     */
    private function is_shop_admin_page($hook) {
        $hook = (string) $hook;
        if (strpos($hook, 'qilingshop_page_qls-') === 0) {
            return true;
        }

        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        if ('' === $page) {
            return false;
        }

        return in_array($page, $this->get_shop_admin_page_slugs(), true);
    }

    /**
     * 是否页面编辑器中的商城装修器场景。
     *
     * @param string $hook 当前后台钩子。
     * @return bool
     */
    private function is_shop_builder_editor_screen($hook) {
        $hook = (string) $hook;
        if (!in_array($hook, ['post.php', 'post-new.php', 'post'], true)) {
            return false;
        }

        $post_type = '';
        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ($screen instanceof WP_Screen && !empty($screen->post_type)) {
                $post_type = (string) $screen->post_type;
            }
        }

        if ($post_type === '' && isset($_GET['post_type'])) {
            $post_type = sanitize_key((string) wp_unslash($_GET['post_type']));
        }

        if ($post_type === '' && isset($_GET['post'])) {
            $post_type = (string) get_post_type((int) $_GET['post']);
        }

        if ($post_type !== 'page') {
            return false;
        }
        return true;
    }

    /**
     * 为商城后台与装修编辑器追加 body class。
     *
     * @param string $classes 原始 body class 字符串。
     * @return string
     */
    public function filter_admin_body_class($classes) {
        $hook_id = '';
        $hook_base = '';
        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ($screen instanceof WP_Screen) {
                $hook_id = (string) $screen->id;
                $hook_base = (string) $screen->base;
            }
        }

        $is_shop_page = $this->is_shop_admin_page($hook_id) || $this->is_shop_admin_page($hook_base);
        if (!$is_shop_page) {
            return $classes;
        }

        $tokens = ['qilingshop-admin-shell', 'qilingshop-admin-theme', 'qilingshop-shop-admin'];
        foreach ($tokens as $token) {
            if (strpos(' ' . $classes . ' ', ' ' . $token . ' ') === false) {
                $classes .= ' ' . $token;
            }
        }

        return trim($classes);
    }

    /**
     * 加载资源
     */
    public function enqueue_assets($hook) {
        if ($hook === 'index.php' && current_user_can('manage_options')) {
            wp_enqueue_style(
                'qilingshop-dashboard-widget',
                QILINGSHOP_URL . 'static/css/qilingshop-dashboard-widget.css',
                [],
                qilingshop_get_assets_version()
            );
            return;
        }

        $is_shop_page = $this->is_shop_admin_page($hook);
        $is_builder_editor = $this->is_shop_builder_editor_screen($hook);
        if (!$is_shop_page && !$is_builder_editor) {
            return;
        }

        // 动态版本号
        $assets_version = qilingshop_get_assets_version();

        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_enqueue_media();

        if ($is_shop_page) {
            $shell_css_version = $assets_version;
            $shell_css_file = QILINGSHOP_PATH . 'static/css/qilingshop-admin-shell.css';
            if (file_exists($shell_css_file)) {
                $shell_css_version .= '.' . (string) filemtime($shell_css_file);
            }

            wp_enqueue_style(
                'qilingshop-admin-shell',
                QILINGSHOP_URL . 'static/css/qilingshop-admin-shell.css',
                [],
                $shell_css_version
            );

            $admin_css_version = $assets_version;
            $admin_css_file = QILINGSHOP_PATH . 'static/shop/css/shop-admin.css';
            if (file_exists($admin_css_file)) {
                $admin_css_version .= '.' . (string) filemtime($admin_css_file);
            }

            wp_enqueue_style(
                'qls-shop-admin',
                QILINGSHOP_URL . 'static/shop/css/shop-admin.css',
                ['qilingshop-admin-shell'],
                $admin_css_version
            );
        }

        // 装修器 Builder 编辑场景：加载装修专用 CSS + jQuery UI 排序
        if ($is_builder_editor) {
            wp_enqueue_script('jquery-ui-sortable');

            $dec_css_version = $assets_version;
            $dec_css_file = QILINGSHOP_PATH . 'static/shop/css/shop-decoration.css';
            if (file_exists($dec_css_file)) {
                $dec_css_version .= '.' . (string) filemtime($dec_css_file);
            }

            wp_enqueue_style(
                'qls-shop-decoration',
                QILINGSHOP_URL . 'static/shop/css/shop-decoration.css',
                [],
                $dec_css_version
            );
        }

        $admin_js_version = $assets_version;
        $admin_js_file = QILINGSHOP_PATH . 'static/shop/js/shop-admin.js';
        if (file_exists($admin_js_file)) {
            $admin_js_version .= '.' . (string) filemtime($admin_js_file);
        }

        wp_enqueue_script(
            'qls-shop-admin',
            QILINGSHOP_URL . 'static/shop/js/shop-admin.js',
            ['jquery', 'wp-color-picker'],
            $admin_js_version,
            true
        );
        // 获取参数模板（仅商城后台页需要）
        $param_templates = $is_shop_page ? $this->get_param_templates() : [];
        $param_names = array_map(function($tpl) {
            return $tpl->name;
        }, $param_templates);

        $vip_levels = [];
        if ($is_shop_page && class_exists('QilingShop_VIP')) {
            foreach ((array) QilingShop_VIP::instance()->get_levels(true) as $level) {
                if (empty($level->id)) {
                    continue;
                }
                $vip_levels[] = [
                    'id'   => (int) $level->id,
                    'name' => isset($level->level_name) ? (string) $level->level_name : ('VIP' . (int) $level->id),
                ];
            }
        }

        wp_localize_script('qls-shop-admin', 'qlsShopAdmin', [
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('qls_shop_admin'),
            'paramTemplates' => $param_names,
            'vipLevels' => $vip_levels,
            'i18n'     => [
                'confirm_delete'    => __('确定要删除吗？', 'qilingshop'),
                'confirm_batch'     => __('确定要执行此操作吗？', 'qilingshop'),
                'select_image'      => __('选择图片', 'qilingshop'),
                'select_video'      => __('选择视频', 'qilingshop'),
                'use_image'         => __('使用此图片', 'qilingshop'),
                'add_attribute'     => __('添加规格', 'qilingshop'),
                'add_value'         => __('添加值', 'qilingshop'),
                'generate_sku'      => __('生成SKU', 'qilingshop'),
                'saving'            => __('保存中...', 'qilingshop'),
                'saved'             => __('已保存', 'qilingshop'),
                'error'             => __('操作失败', 'qilingshop'),
                'delete_sku_failed' => __('删除SKU失败，请稍后重试', 'qilingshop'),
                'select_param'      => __('选择参数名', 'qilingshop'),
                'confirm_delete_title'       => __('确认删除', 'qilingshop'),
                'confirm_delete_item'        => __('确定删除该项吗？', 'qilingshop'),
                'confirm_delete_button'      => __('确认删除', 'qilingshop'),
                'confirm_action_title'       => __('确认操作', 'qilingshop'),
                'confirm_action_message'     => __('确定要执行此操作吗？', 'qilingshop'),
                'confirm_button'             => __('确认', 'qilingshop'),
                'cancel_button'              => __('取消', 'qilingshop'),
                'select_bulk_action'         => __('请选择批量操作。', 'qilingshop'),
                'select_bulk_items'          => __('请先勾选要操作的项目。', 'qilingshop'),
                'bulk_delete_title'          => __('批量删除', 'qilingshop'),
                'confirm_delete_selected'    => __('确定要删除选中的项目吗？', 'qilingshop'),
                'bulk_edit_title'            => __('批量编辑商品', 'qilingshop'),
                'bulk_edit_message'          => __('将更新已选中的 {count} 件商品，未填写或选择“不修改”的字段会保持原样。是否继续？', 'qilingshop'),
                'bulk_edit_confirm'          => __('开始批量编辑', 'qilingshop'),
                'bulk_edit_no_fields'        => __('请至少设置一个要批量修改的字段。', 'qilingshop'),
                'no_pending_ship_orders'     => __('本页没有待发货订单。', 'qilingshop'),
                'bulk_ship_required'         => __('请先填写订单号、物流公司和快递单号。', 'qilingshop'),
                'bulk_ship_title'            => __('批量导入发货', 'qilingshop'),
                'bulk_ship_message'          => __('将按填写的物流公司和快递单号逐行发货，只处理待发货订单。是否继续？', 'qilingshop'),
                'bulk_ship_confirm'          => __('开始发货', 'qilingshop'),
                'bulk_ship_cancel'           => __('再检查一下', 'qilingshop'),
                'creating'                   => __('创建中...', 'qilingshop'),
                'create_all_pages'           => __('一键创建所有页面', 'qilingshop'),
                'selected_products_count'    => __('已选择 {count} 件商品', 'qilingshop'),
                'no_products_selected'       => __('未选择商品', 'qilingshop'),
                'loading_products'           => __('正在加载商品...', 'qilingshop'),
                'load_products_failed'       => __('商品加载失败，请稍后重试', 'qilingshop'),
                'no_matching_products'       => __('没有找到匹配的上架商品', 'qilingshop'),
                'load_products_network_failed' => __('商品加载失败，请检查网络后重试', 'qilingshop'),
                'stock_label'                => __('库存', 'qilingshop'),
                'change_product'             => __('更换商品', 'qilingshop'),
                'select_product_images'      => __('选择商品图片', 'qilingshop'),
                'add_to_product'             => __('添加到商品', 'qilingshop'),
                'cover_badge'                => __('封面', 'qilingshop'),
                'video_url_prompt'           => __('请输入视频链接：', 'qilingshop'),
                'video_cover_prompt'         => __('请输入视频封面图片链接（可选）：', 'qilingshop'),
                'select_value_image'         => __('选择规格值图片', 'qilingshop'),
                'use_this_image'             => __('使用此图片', 'qilingshop'),
                'attribute_name_placeholder' => __('规格名称（如：颜色）', 'qilingshop'),
                'remove_attribute'           => __('删除规格', 'qilingshop'),
                'add_attribute_value'        => __('添加规格值', 'qilingshop'),
                'value_placeholder'          => __('规格值（如：白色）', 'qilingshop'),
                'image_button'               => __('图片', 'qilingshop'),
                'version_label'              => __('版本值：', 'qilingshop'),
                'add_version'                => __('+版本', 'qilingshop'),
                'version_placeholder'        => __('如：128G', 'qilingshop'),
                'batch_settings'             => __('批量设置：', 'qilingshop'),
                'price_placeholder'          => __('价格', 'qilingshop'),
                'sale_price_placeholder'     => __('促销价', 'qilingshop'),
                'points_price_placeholder'   => __('积分价', 'qilingshop'),
                'stock_placeholder'          => __('库存', 'qilingshop'),
                'weight_placeholder'         => __('重量', 'qilingshop'),
                'apply_batch'                => __('一键应用', 'qilingshop'),
                'add_specs_first'            => __('请先添加规格和规格值', 'qilingshop'),
                'spec_column'                => __('规格', 'qilingshop'),
                'sku_code_column'            => __('SKU编码', 'qilingshop'),
                'price_column'               => __('价格', 'qilingshop'),
                'sale_price_column'          => __('促销价', 'qilingshop'),
                'points_price_column'        => __('积分价', 'qilingshop'),
                'vip_price_column'           => __('VIP价', 'qilingshop'),
                'stock_column'               => __('库存', 'qilingshop'),
                'weight_column'              => __('重量(g)', 'qilingshop'),
                'param_name_placeholder'     => __('参数名', 'qilingshop'),
                'param_value_placeholder'    => __('参数值', 'qilingshop'),
                'delete_button'              => __('删除', 'qilingshop'),
                'delete_sku_failed_short'    => __('删除SKU失败', 'qilingshop'),
            ],
        ]);
    }

    /**
     * 检查是否需要刷新重写规则
     */
    public function check_flush_rewrite() {
        if (get_option('qls_shop_flush_rewrite')) {
            flush_rewrite_rules();
            delete_option('qls_shop_flush_rewrite');
        }
    }

    /**
     * 渲染商城仪表盘
     */
    public function render_dashboard() {
        $db = QLS_Shop_Database::instance();
        $table_orders = $db->get_table('orders');
        $wpdb = $db->get_wpdb();

        // --- 趋势图数据 (逻辑同积分统计) ---
        $period = isset($_GET['period']) ? sanitize_text_field($_GET['period']) : '30day';
        $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
        $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';

        if ($period == 'custom' && $start_date && $end_date) {
        } else {
            switch ($period) {
                case 'today':
                    $start_date = current_time('Y-m-d');
                    $end_date = current_time('Y-m-d');
                    break;
                case 'yesterday':
                    $start_date = date('Y-m-d', strtotime('-1 day'));
                    $end_date = date('Y-m-d', strtotime('-1 day'));
                    break;
                case 'month':
                    $start_date = date('Y-m-01');
                    $end_date = current_time('Y-m-d');
                    break;
                case 'year':
                    $start_date = date('Y-01-01');
                    $end_date = current_time('Y-m-d');
                    break;
                case '30day':
                default:
                    $start_date = date('Y-m-d', strtotime('-29 days'));
                    $end_date = current_time('Y-m-d');
                    break;
            }
        }
        
        if (strtotime($start_date) > strtotime($end_date)) {
            $temp = $start_date; $start_date = $end_date; $end_date = $temp;
        }
        $range_start = $start_date . ' 00:00:00';
        $range_end = date('Y-m-d H:i:s', strtotime($end_date . ' +1 day'));

        $cache_key = $this->build_dashboard_cache_key([
            'period'     => $period,
            'start_date' => $start_date,
            'end_date'   => $end_date,
        ]);
        $cached_payload = get_transient($cache_key);
        if (is_array($cached_payload)) {
            $stats = isset($cached_payload['stats']) && is_array($cached_payload['stats']) ? $cached_payload['stats'] : [];
            $chart_data = isset($cached_payload['chart_data']) && is_array($cached_payload['chart_data']) ? $cached_payload['chart_data'] : [];
            $top_products = isset($cached_payload['top_products']) && is_array($cached_payload['top_products']) ? $cached_payload['top_products'] : [];
            $category_stats = isset($cached_payload['category_stats']) && is_array($cached_payload['category_stats']) ? $cached_payload['category_stats'] : [];
            $top_users = isset($cached_payload['top_users']) && is_array($cached_payload['top_users']) ? $cached_payload['top_users'] : [];
            $group_by = isset($cached_payload['group_by']) ? (string) $cached_payload['group_by'] : 'day';

            include QILINGSHOP_PATH . 'admin/shop/views/dashboard.php';
            return;
        }

        // 统计数据
        $stats = [
            'products_total'   => $db->count('products'),
            'products_active'  => $db->count('products', ['status' => 1]),
            'orders_total'     => $db->count('orders'),
            'orders_pending'   => $db->count('orders', ['status' => 0]),
            'orders_paid'      => $db->count('orders', ['status' => 1]),
            'orders_shipped'   => $db->count('orders', ['status' => 2]),
        ];

        // 今日统计
        $today = date('Y-m-d');
        $today_start = $today . ' 00:00:00';
        $today_end = date('Y-m-d H:i:s', strtotime($today . ' +1 day'));
        
        $stats['orders_today'] = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_orders} WHERE created_at >= %s AND created_at < %s",
                $today_start,
                $today_end
            )
        );
        
        $stats['revenue_today'] = (float) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(final_amount), 0) FROM {$table_orders} WHERE created_at >= %s AND created_at < %s AND status >= 1",
                $today_start,
                $today_end
            )
        );

        // 累计数据
        $stats['revenue_total'] = (float) $wpdb->get_var(
            "SELECT COALESCE(SUM(final_amount), 0) FROM {$table_orders} WHERE status >= 1"
        );
        
        $stats['customers_total'] = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id) FROM {$table_orders} WHERE status >= 1 AND user_id > 0"
        );

        // 分组与图表数据准备
        $days_diff = (strtotime($end_date) - strtotime($start_date)) / 86400;
        $group_by = ($days_diff > 60 || $period == 'year') ? 'month' : 'day';
        $date_format_sql = ($group_by == 'month') ? '%Y-%m' : '%Y-%m-%d';
        $php_date_format = ($group_by == 'month') ? 'Y-m' : 'Y-m-d';
        $interval_spec = ($group_by == 'month') ? 'P1M' : 'P1D';

        $chart_data = [];
        $period_obj = new DatePeriod(
            new DateTime($start_date),
            new DateInterval($interval_spec),
            (new DateTime($end_date))->modify('+1 day')
        );
        
        if ($group_by == 'month') {
             $start_dt = new DateTime(date('Y-m-01', strtotime($start_date)));
             $end_dt = new DateTime(date('Y-m-01', strtotime($end_date)));
             $end_dt->modify('+1 month');
             $period_obj = new DatePeriod($start_dt, new DateInterval('P1M'), $end_dt);
        }

        foreach ($period_obj as $dt) {
            $d_str = $dt->format($php_date_format);
            $chart_data[$d_str] = ['date' => $d_str, 'orders' => 0, 'income' => 0];
        }

        $trend_sql = $wpdb->prepare(
            "SELECT DATE_FORMAT(created_at, %s) as date_key, COUNT(*) as count, SUM(final_amount) as total
             FROM {$table_orders} 
             WHERE status >= 1 AND created_at >= %s AND created_at < %s 
             GROUP BY date_key",
            $date_format_sql, $range_start, $range_end
        );
        $trend_res = $wpdb->get_results($trend_sql);

        foreach ($trend_res as $row) {
            if (isset($chart_data[$row->date_key])) {
                $chart_data[$row->date_key]['orders'] = $row->count;
                $chart_data[$row->date_key]['income'] = $row->total;
            }
        }

        // --- 热销商品前 5 名 ---
        $table_items = $db->get_table('order_items');
        $top_products = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT oi.product_title, SUM(oi.quantity) as total_qty, SUM(oi.total) as total_sales 
                 FROM {$table_items} oi
                 INNER JOIN {$table_orders} o ON o.id = oi.order_id
                 WHERE o.status >= 1 AND o.created_at >= %s AND o.created_at < %s
                 GROUP BY oi.product_id 
                 ORDER BY total_qty DESC 
                 LIMIT 5",
                $range_start,
                $range_end
            )
        );

        // --- 分类销售占比 ---
        $table_products = $db->get_table('products');
        $table_cats = $db->get_table('categories');
        $category_stats = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.name, SUM(oi.total) as total_sales
                 FROM {$table_items} oi
                 INNER JOIN {$table_orders} o ON o.id = oi.order_id
                 LEFT JOIN {$table_products} p ON oi.product_id = p.id
                 LEFT JOIN {$table_cats} c ON p.category_id = c.id
                 WHERE o.status >= 1 AND o.created_at >= %s AND o.created_at < %s
                 GROUP BY c.id
                 ORDER BY total_sales DESC",
                $range_start,
                $range_end
            )
        );
        // 处理未分类
        foreach ($category_stats as &$cat) {
            if (!$cat->name) $cat->name = __('未分类', 'qilingshop');
        }

        // --- 用户消费排行前 10 名 ---
        $table_users = $wpdb->users;
        $top_users = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT u.display_name, o.user_id, COUNT(*) as order_count, SUM(o.final_amount) as total_spend
                 FROM {$table_orders} o
                 LEFT JOIN {$table_users} u ON o.user_id = u.ID
                 WHERE o.status >= 1 AND o.created_at >= %s AND o.created_at < %s AND o.user_id > 0
                 GROUP BY o.user_id
                 ORDER BY total_spend DESC
                 LIMIT 10",
                $range_start,
                $range_end
            )
        );
        
        set_transient($cache_key, [
            'stats'          => $stats,
            'chart_data'     => $chart_data,
            'top_products'   => $top_products,
            'category_stats' => $category_stats,
            'top_users'      => $top_users,
            'group_by'       => $group_by,
        ], $this->get_dashboard_cache_ttl());

        include QILINGSHOP_PATH . 'admin/shop/views/dashboard.php';
    }

    /**
     * 处理导出
     */
    public function handle_export() {
        if (!isset($_GET['export'])) {
            return;
        }

        $export = sanitize_key(wp_unslash((string) $_GET['export']));

        if ($export === 'shop_pending_shipments') {
            $this->export_pending_shipments();
            return;
        }

        if ($export !== 'shop_stats') {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'qls_shop_export_stats')) {
            return;
        }

        $db = QLS_Shop_Database::instance();
        $wpdb = $db->get_wpdb();
        $orders_table = $db->get_table('orders');
        $order_items = $db->get_table('order_items');
        $users_table = $wpdb->users;

        // 获取筛选参数 (同 render_dashboard 逻辑)
        $period = isset($_GET['period']) ? sanitize_text_field($_GET['period']) : '30day';
        $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
        $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';

        if ($period == 'custom' && $start_date && $end_date) {
        } else {
            switch ($period) {
                case 'today': $start_date = current_time('Y-m-d'); $end_date = current_time('Y-m-d'); break;
                case 'yesterday': $start_date = date('Y-m-d', strtotime('-1 day')); $end_date = date('Y-m-d', strtotime('-1 day')); break;
                case 'month': $start_date = date('Y-m-01'); $end_date = current_time('Y-m-d'); break;
                case 'year': $start_date = date('Y-01-01'); $end_date = current_time('Y-m-d'); break;
                default: $start_date = date('Y-m-d', strtotime('-29 days')); $end_date = current_time('Y-m-d'); break;
            }
        }
        if (strtotime($start_date) > strtotime($end_date)) { $temp = $start_date; $start_date = $end_date; $end_date = $temp; }
        $range_start = $start_date . ' 00:00:00';
        $range_end = date('Y-m-d H:i:s', strtotime($end_date . ' +1 day'));

        // 清除缓冲区，确保导出内容格式正确
        if (ob_get_length()) ob_end_clean();

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="shop_stats_' . $start_date . '_' . $end_date . '.csv"');
        echo "\xEF\xBB\xBF"; // BOM
        
        $fp = fopen('php://output', 'w');

        // 查询每日数据 (Trend)
        $days_diff = (strtotime($end_date) - strtotime($start_date)) / 86400;
        $group_by = ($days_diff > 60 || $period == 'year') ? 'month' : 'day';
        $date_format_sql = ($group_by == 'month') ? '%Y-%m' : '%Y-%m-%d';
        
        $trend_sql = $wpdb->prepare(
            "SELECT DATE_FORMAT(created_at, %s) as date_key, COUNT(*) as count, SUM(final_amount) as total
             FROM {$orders_table} 
             WHERE status >= 1 AND created_at >= %s AND created_at < %s 
             GROUP BY date_key ORDER BY date_key DESC",
            $date_format_sql, $range_start, $range_end
        );
        $trend_res = $wpdb->get_results($trend_sql);

        // 1. 每日明细
        $this->put_export_csv_row($fp, [sprintf(__('每日统计明细 (%1$s 至 %2$s)', 'qilingshop'), $start_date, $end_date)]);
        $this->put_export_csv_row($fp, [__('日期', 'qilingshop'), __('订单数', 'qilingshop'), __('收入', 'qilingshop')]);
        foreach ($trend_res as $row) {
            $this->put_export_csv_row($fp, [$row->date_key, $row->count, $row->total]);
        }
        $this->put_export_csv_row($fp, []);

        // 2. 热销商品
        $this->put_export_csv_row($fp, [__('热销商品前 10 名', 'qilingshop')]);
        $this->put_export_csv_row($fp, [__('商品名称', 'qilingshop'), __('销量', 'qilingshop'), __('销售额', 'qilingshop')]);
        $top_products = $wpdb->get_results($wpdb->prepare(
            "SELECT oi.product_title, SUM(oi.quantity) as total_qty, SUM(oi.total) as total_sales 
             FROM {$order_items} oi
             INNER JOIN {$orders_table} o ON o.id = oi.order_id
             WHERE o.status >= 1 AND o.created_at >= %s AND o.created_at < %s
             GROUP BY oi.product_id ORDER BY total_qty DESC LIMIT 10",
            $range_start, $range_end
        ));
        foreach ($top_products as $p) {
            $this->put_export_csv_row($fp, [$p->product_title, $p->total_qty, $p->total_sales]);
        }
        $this->put_export_csv_row($fp, []);

        // 3. 用户排行
        $this->put_export_csv_row($fp, [__('用户消费排行前 10 名', 'qilingshop')]);
        $this->put_export_csv_row($fp, [__('用户', 'qilingshop'), __('订单数', 'qilingshop'), __('消费总额', 'qilingshop')]);
        $top_users = $wpdb->get_results($wpdb->prepare(
            "SELECT u.display_name, o.user_id, COUNT(*) as order_count, SUM(o.final_amount) as total_spend
             FROM {$orders_table} o
             LEFT JOIN {$users_table} u ON o.user_id = u.ID
             WHERE o.status >= 1 AND o.created_at >= %s AND o.created_at < %s AND o.user_id > 0
             GROUP BY o.user_id ORDER BY total_spend DESC LIMIT 10",
            $range_start, $range_end
        ));
        foreach ($top_users as $u) {
            $this->put_export_csv_row($fp, [$u->display_name ?: 'User#' . $u->user_id, $u->order_count, $u->total_spend]);
        }

        fclose($fp);
        exit;
    }

    /**
     * 输出电子面单打印页。
     */
    public function handle_waybill_print() {
        if (empty($_GET['qls_waybill_print'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('权限不足', 'qilingshop'));
        }

        $shipment_id = isset($_GET['shipment_id']) ? absint(wp_unslash($_GET['shipment_id'])) : 0;
        $log_id = isset($_GET['log_id']) ? absint(wp_unslash($_GET['log_id'])) : 0;
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';

        if ($shipment_id <= 0 || !wp_verify_nonce($nonce, 'qls_waybill_print_' . $shipment_id . '_' . $log_id)) {
            wp_die(esc_html__('电子面单打印链接无效，请返回订单详情重新打开。', 'qilingshop'));
        }

        if (!function_exists('qls_waybill')) {
            wp_die(esc_html__('电子面单服务不可用。', 'qilingshop'));
        }

        $payload = qls_waybill()->get_print_payload($shipment_id, $log_id);
        if (is_wp_error($payload)) {
            wp_die(esc_html($payload->get_error_message()));
        }

        $this->render_waybill_print_page($payload);
        exit;
    }

    /**
     * 构建电子面单打印链接。
     *
     * @param int $shipment_id
     * @param int $log_id
     * @return string
     */
    private function build_waybill_print_url($shipment_id, $log_id = 0) {
        $shipment_id = (int) $shipment_id;
        $log_id = (int) $log_id;
        if ($shipment_id <= 0) {
            return '';
        }

        $url = add_query_arg([
            'page'              => 'qls-shop-orders',
            'qls_waybill_print' => 1,
            'shipment_id'       => $shipment_id,
            'log_id'            => $log_id,
        ], admin_url('admin.php'));

        return wp_nonce_url($url, 'qls_waybill_print_' . $shipment_id . '_' . $log_id);
    }

    /**
     * 渲染电子面单打印 HTML。
     *
     * @param array $payload
     */
    private function render_waybill_print_page($payload) {
        $provider_template = isset($payload['provider_print_template']) ? (string) $payload['provider_print_template'] : '';
        if ($provider_template !== '') {
            $allowed_html = wp_kses_allowed_html('post');
            $allowed_html['html'] = ['lang' => true];
            $allowed_html['head'] = [];
            $allowed_html['body'] = ['class' => true, 'style' => true];
            $allowed_html['meta'] = ['charset' => true, 'content' => true, 'http-equiv' => true, 'name' => true];
            $allowed_html['style'] = ['type' => true];
            $safe_template = wp_kses($provider_template, $allowed_html, ['http', 'https', 'data']);
            ?><!doctype html>
            <html <?php language_attributes(); ?>>
            <head>
                <meta charset="<?php bloginfo('charset'); ?>">
                <title><?php esc_html_e('打印电子面单', 'qilingshop'); ?></title>
                <style>
                    html, body { height: 100%; margin: 0; }
                    body { background: #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
                    .qls-waybill-toolbar { height: 52px; padding: 8px 16px; display: flex; justify-content: flex-end; align-items: center; box-sizing: border-box; }
                    .qls-waybill-toolbar button { height: 36px; padding: 0 14px; border: 0; border-radius: 6px; background: #111827; color: #fff; cursor: pointer; }
                    .qls-provider-waybill { display: block; width: 100%; height: calc(100% - 52px); border: 0; background: #fff; }
                    @media print { .qls-waybill-toolbar { display: none; } .qls-provider-waybill { height: 100%; } }
                </style>
            </head>
            <body>
                <div class="qls-waybill-toolbar"><button type="button" id="qls-print-provider-waybill"><?php esc_html_e('打印电子面单', 'qilingshop'); ?></button></div>
                <iframe class="qls-provider-waybill" id="qls-provider-waybill" sandbox="allow-same-origin" srcdoc="<?php echo esc_attr($safe_template); ?>"></iframe>
                <script>
                    (function() {
                        var button = document.getElementById('qls-print-provider-waybill');
                        var frame = document.getElementById('qls-provider-waybill');
                        if (button && frame) {
                            button.addEventListener('click', function() {
                                if (frame.contentWindow) {
                                    frame.contentWindow.focus();
                                    frame.contentWindow.print();
                                }
                            });
                        }
                    }());
                </script>
            </body>
            </html><?php
            return;
        }

        $print_data = isset($payload['print_data']) && is_array($payload['print_data']) ? $payload['print_data'] : [];
        $sender = isset($print_data['sender']) && is_array($print_data['sender']) ? $print_data['sender'] : [];
        $receiver = isset($print_data['receiver']) && is_array($print_data['receiver']) ? $print_data['receiver'] : [];
        $company = isset($print_data['company']) && is_array($print_data['company']) ? $print_data['company'] : [];
        $shipment = isset($print_data['shipment']) && is_array($print_data['shipment']) ? $print_data['shipment'] : [];
        $items = isset($print_data['items']) && is_array($print_data['items']) ? $print_data['items'] : [];
        $waybill_no = (string) ($print_data['waybill_no'] ?? '');
        $sheet_size = (string) ($print_data['template']['sheet_size'] ?? '100x150');
        $page_size = '100mm 150mm';
        if (preg_match('/^(\d+(?:\.\d+)?)\s*[xX*]\s*(\d+(?:\.\d+)?)/', $sheet_size, $matches)) {
            $page_size = $matches[1] . 'mm ' . $matches[2] . 'mm';
        }
        ?><!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <title><?php echo esc_html(sprintf(__('电子面单 %s', 'qilingshop'), $waybill_no)); ?></title>
            <style>
                * { box-sizing: border-box; }
                body { margin: 0; padding: 24px; background: #f3f4f6; color: #111827; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
                .qls-waybill-toolbar { max-width: 460px; margin: 0 auto 16px; display: flex; gap: 10px; justify-content: flex-end; }
                .qls-waybill-toolbar button { height: 36px; padding: 0 14px; border: 0; border-radius: 8px; background: #111827; color: #fff; cursor: pointer; }
                .qls-waybill-sheet { width: 100mm; min-height: 150mm; margin: 0 auto; padding: 7mm; background: #fff; border: 1px solid #111827; }
                .qls-waybill-head { display: grid; grid-template-columns: 1fr auto; gap: 8px; align-items: start; border-bottom: 2px solid #111827; padding-bottom: 6px; }
                .qls-waybill-head h1 { margin: 0; font-size: 20px; line-height: 1.2; }
                .qls-waybill-no { text-align: right; font-size: 12px; }
                .qls-waybill-no strong { display: block; font-size: 17px; letter-spacing: 1px; }
                .qls-waybill-section { border-bottom: 1px solid #111827; padding: 8px 0; }
                .qls-waybill-label { display: inline-block; min-width: 42px; font-weight: 700; }
                .qls-waybill-address { margin-top: 4px; line-height: 1.45; font-size: 13px; }
                .qls-waybill-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
                .qls-waybill-meta { font-size: 12px; color: #374151; }
                .qls-waybill-items { margin: 6px 0 0; padding-left: 18px; font-size: 12px; line-height: 1.5; }
                .qls-waybill-foot { padding-top: 8px; font-size: 12px; color: #374151; }
                @media print {
                    @page { size: <?php echo esc_html($page_size); ?>; margin: 0; }
                    body { padding: 0; background: #fff; }
                    .qls-waybill-toolbar { display: none; }
                    .qls-waybill-sheet { margin: 0; border: 0; width: 100mm; min-height: 150mm; }
                }
            </style>
        </head>
        <body>
            <div class="qls-waybill-toolbar">
                <button type="button" onclick="window.print()"><?php _e('打印电子面单', 'qilingshop'); ?></button>
            </div>
            <section class="qls-waybill-sheet">
                <header class="qls-waybill-head">
                    <div>
                        <h1><?php echo esc_html($company['name'] ?? __('电子面单', 'qilingshop')); ?></h1>
                        <div class="qls-waybill-meta"><?php echo esc_html($shipment['shipment_no'] ?? ''); ?></div>
                    </div>
                    <div class="qls-waybill-no">
                        <?php _e('面单号', 'qilingshop'); ?>
                        <strong><?php echo esc_html($waybill_no); ?></strong>
                    </div>
                </header>

                <div class="qls-waybill-section">
                    <div><span class="qls-waybill-label"><?php _e('收', 'qilingshop'); ?></span><?php echo esc_html(($receiver['name'] ?? '') . '  ' . ($receiver['phone'] ?? '')); ?></div>
                    <div class="qls-waybill-address"><?php echo esc_html($this->format_waybill_address($receiver)); ?></div>
                </div>

                <div class="qls-waybill-section">
                    <div><span class="qls-waybill-label"><?php _e('寄', 'qilingshop'); ?></span><?php echo esc_html(($sender['name'] ?? '') . '  ' . ($sender['phone'] ?? '')); ?></div>
                    <div class="qls-waybill-address"><?php echo esc_html($this->format_waybill_address($sender)); ?></div>
                </div>

                <div class="qls-waybill-section qls-waybill-grid">
                    <div>
                        <strong><?php _e('订单号', 'qilingshop'); ?></strong>
                        <div class="qls-waybill-meta"><?php echo esc_html($shipment['order_no'] ?? ''); ?></div>
                    </div>
                    <div>
                        <strong><?php _e('数量/重量', 'qilingshop'); ?></strong>
                        <div class="qls-waybill-meta"><?php echo esc_html((int) ($print_data['count'] ?? 0)); ?> / <?php echo esc_html((string) ($print_data['weight'] ?? 0)); ?>kg</div>
                    </div>
                </div>

                <div class="qls-waybill-section">
                    <strong><?php _e('货品', 'qilingshop'); ?></strong>
                    <div class="qls-waybill-meta"><?php echo esc_html($print_data['cargo'] ?? ''); ?></div>
                    <?php if (!empty($items)): ?>
                    <ul class="qls-waybill-items">
                        <?php foreach ($items as $item): ?>
                        <li><?php echo esc_html(($item['title'] ?? '') . (!empty($item['sku']) ? ' [' . $item['sku'] . ']' : '') . ' × ' . (int) ($item['quantity'] ?? 0)); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>

                <footer class="qls-waybill-foot">
                    <?php if (!empty($print_data['template']['print_note'])): ?>
                    <div><?php echo esc_html($print_data['template']['print_note']); ?></div>
                    <?php endif; ?>
                    <div><?php echo esc_html(sprintf(__('生成时间：%s', 'qilingshop'), $print_data['created_at'] ?? current_time('mysql'))); ?></div>
                </footer>
            </section>
        </body>
        </html><?php
    }

    /**
     * 格式化面单地址。
     *
     * @param array $address
     * @return string
     */
    private function format_waybill_address($address) {
        $address = is_array($address) ? $address : [];
        return trim(implode(' ', array_filter([
            $address['province'] ?? '',
            $address['city'] ?? '',
            $address['district'] ?? '',
            $address['address'] ?? '',
        ])));
    }

    private function export_pending_shipments() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'qls_shop_export_shipments')) {
            return;
        }

        $db = QLS_Shop_Database::instance();
        $wpdb = $db->get_wpdb();
        $orders_table = $db->get_table('orders');

        $orders = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT o.*
                 FROM {$orders_table} o
                 INNER JOIN (
                    SELECT MAX(id) AS id
                    FROM {$orders_table}
                    GROUP BY order_no
                 ) latest ON latest.id = o.id
                 WHERE o.status = %d
                 ORDER BY o.paid_at ASC, o.id ASC",
                QLS_Shop_Order::STATUS_PAID
            )
        );

        if (ob_get_level()) {
            while (ob_get_level()) {
                ob_end_clean();
            }
        }

        $download_name = __('待发货单', 'qilingshop') . '_' . current_time('Ymd_His') . '.csv';
        $fallback_name = 'pending_shipments_' . current_time('Ymd_His') . '.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $fallback_name . '"; filename*=UTF-8\'\'' . rawurlencode($download_name));
        header('X-Content-Type-Options: nosniff');

        echo "\xEF\xBB\xBF";

        $fp = fopen('php://output', 'w');
        $this->put_export_csv_row($fp, [
            __('订单号', 'qilingshop'),
            __('物流公司', 'qilingshop'),
            __('快递单号', 'qilingshop'),
            __('收货人', 'qilingshop'),
            __('手机号', 'qilingshop'),
            __('省', 'qilingshop'),
            __('市', 'qilingshop'),
            __('区', 'qilingshop'),
            __('详细地址', 'qilingshop'),
            __('完整地址', 'qilingshop'),
            __('商品明细', 'qilingshop'),
            __('商品总数', 'qilingshop'),
            __('订单金额', 'qilingshop'),
            __('买家备注', 'qilingshop'),
            __('下单时间', 'qilingshop'),
            __('支付时间', 'qilingshop'),
        ]);

        foreach ($orders as $order) {
            $order->items = qls_shop_order()->get_items((int) $order->id);
            if (!$this->order_has_physical_items($order)) {
                continue;
            }

            $items_summary = $this->build_order_items_export_summary($order->items, true);
            if ((int) $items_summary['quantity'] <= 0) {
                continue;
            }
            $full_address = trim(implode(' ', array_filter([
                $order->receiver_province ?? '',
                $order->receiver_city ?? '',
                $order->receiver_district ?? '',
                $order->receiver_address ?? '',
            ])));

            $this->put_export_csv_row($fp, [
                $order->order_no,
                '',
                '',
                $order->receiver_name,
                $order->receiver_phone,
                $order->receiver_province,
                $order->receiver_city,
                $order->receiver_district,
                $order->receiver_address,
                $full_address,
                $items_summary['summary'],
                $items_summary['quantity'],
                number_format((float) $order->final_amount, 2, '.', ''),
                $order->buyer_remark,
                $order->created_at,
                $order->paid_at,
            ]);
        }

        fclose($fp);
        exit;
    }

    private function put_export_csv_row($fp, $row) {
        if (function_exists('qilingshop_fputcsv_safe')) {
            qilingshop_fputcsv_safe($fp, (array) $row);
            return;
        }

        fputcsv($fp, array_map([$this, 'normalize_export_cell'], $row));
    }

    private function normalize_export_cell($value) {
        if (function_exists('qilingshop_normalize_csv_cell')) {
            return qilingshop_normalize_csv_cell($value);
        }

        if (is_array($value) || is_object($value)) {
            $value = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $value = (string) $value;
        $value = preg_replace('/\r\n|\r|\n/', ' ', $value);
        $value = trim($value);

        if ($value !== '' && preg_match('/^[=+\-@]/', $value)) {
            $value = "'" . $value;
        }

        return $value;
    }

    private function build_order_items_export_summary($items, $remaining_only = false) {
        $summary = [];
        $quantity = 0;

        foreach ((array) $items as $item) {
            if ($remaining_only && function_exists('qls_product')) {
                $product_id = (int) ($item->product_id ?? 0);
                if ($product_id > 0 && qls_product()->is_virtual($product_id)) {
                    continue;
                }
            }

            $item_quantity = max(0, (int) ($item->quantity ?? 0));
            if ($remaining_only) {
                $item_quantity = max(0, $item_quantity - max(0, (int) ($item->shipped_quantity ?? 0)));
            }
            if ($item_quantity <= 0) {
                continue;
            }
            $quantity += $item_quantity;

            $title = trim((string) ($item->product_title ?? ''));
            $spec = $this->format_sku_attrs_for_export($item->sku_attrs ?? '');
            $line = $title !== '' ? $title : sprintf(__('商品 #%d', 'qilingshop'), (int) ($item->product_id ?? 0));

            if ($spec !== '') {
                $line .= ' [' . $spec . ']';
            }

            $line .= ' x' . $item_quantity;
            $summary[] = $line;
        }

        return [
            'summary'  => implode('；', $summary),
            'quantity' => $quantity,
        ];
    }

    private function format_sku_attrs_for_export($attrs) {
        if (is_string($attrs)) {
            $raw = trim($attrs);
            if ($raw === '') {
                return '';
            }

            $decoded = json_decode($raw, true);
            $attrs = is_array($decoded) ? $decoded : $raw;
        }

        if (!is_array($attrs)) {
            return trim((string) $attrs);
        }

        $parts = [];
        foreach ($attrs as $key => $value) {
            if (is_array($value)) {
                $value = implode('/', array_map('strval', $value));
            }

            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            if (is_string($key) && $key !== '') {
                $parts[] = $key . ':' . $value;
            } else {
                $parts[] = $value;
            }
        }

        return implode(' / ', $parts);
    }

    /**
     * 渲染商品列表
     */
    public function render_products() {
        // 处理单个删除
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
            if (wp_verify_nonce($_GET['_wpnonce'] ?? '', 'delete_product_' . $_GET['id'])) {
                qls_product()->delete(intval($_GET['id']));
                $this->redirect_admin_page(admin_url('admin.php?page=qls-products&message=deleted'));
            } else {
                $this->redirect_admin_page(admin_url('admin.php?page=qls-products&message=invalid_nonce'));
            }
        }

        // 处理批量操作
        $bulk_result = null;
        if (isset($_POST['action']) && $_POST['action'] !== '-1') {
            $bulk_result = $this->handle_bulk_action();
        }

        // 筛选参数
        $args = [
            'status'      => isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '',
            'category_id' => isset($_GET['category']) ? intval($_GET['category']) : '',
            'keyword'     => isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '',
            'orderby'     => isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : 'id',
            'order'       => isset($_GET['order']) ? strtoupper($_GET['order']) : 'DESC',
            'limit'       => 30,
            'offset'      => isset($_GET['paged']) ? (intval($_GET['paged']) - 1) * 30 : 0,
        ];

        $products = qls_product()->get_list($args);
        $total = qls_product()->get_count($args);
        $status_counts = qls_product()->get_status_counts();
        $categories = qls_category()->get_flat_tree();
        $category_name_map = [];
        foreach ((array) $categories as $category_row) {
            $category_id = (int) ($category_row->id ?? 0);
            if ($category_id <= 0) {
                continue;
            }

            $category_name_map[$category_id] = isset($category_row->display_name) && $category_row->display_name !== ''
                ? (string) $category_row->display_name
                : (string) ($category_row->name ?? '');
        }
        $shipping_rules = qls_shipping()->get_rules();
        $service_tags = $this->get_service_tags();

        include QILINGSHOP_PATH . 'admin/shop/views/products-list.php';
    }

    /**
     * 渲染卡密管理。
     */
    public function render_card_inventory() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('权限不足', 'qilingshop'));
        }

        $notice = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qls_card_inventory_nonce'])) {
            $notice = $this->handle_card_inventory_action(wp_unslash($_POST));
        }

        $selected_product_id = isset($_GET['product_id']) ? absint(wp_unslash($_GET['product_id'])) : 0;
        $selected_sku_id = isset($_GET['sku_id']) ? absint(wp_unslash($_GET['sku_id'])) : 0;
        $selected_status = '';
        if (isset($_GET['status'])) {
            $status_raw = sanitize_text_field(wp_unslash($_GET['status']));
            if (in_array($status_raw, ['0', '1', '2'], true)) {
                $selected_status = $status_raw;
            }
        }

        $card_sku_options = qls_card_inventory()->get_card_sku_options();
        $sku_product_map = [];
        foreach ($card_sku_options as $option) {
            $sku_product_map[(int) $option->sku_id] = (int) $option->product_id;
        }

        if ($selected_sku_id > 0 && $selected_product_id > 0 && (($sku_product_map[$selected_sku_id] ?? 0) !== $selected_product_id)) {
            $selected_sku_id = 0;
        }

        $paged = isset($_GET['paged']) ? max(1, absint(wp_unslash($_GET['paged']))) : 1;
        $per_page = 30;
        $query_args = [
            'product_id' => $selected_product_id,
            'sku_id'     => $selected_sku_id,
            'status'     => $selected_status,
            'limit'      => $per_page,
            'offset'     => ($paged - 1) * $per_page,
        ];

        $stats = qls_card_inventory()->get_admin_stats($query_args);
        $cards = qls_card_inventory()->get_admin_list($query_args);
        $total = qls_card_inventory()->get_admin_count($query_args);

        include QILINGSHOP_PATH . 'admin/shop/views/card-inventory.php';
    }

    /**
     * 处理卡密管理提交。
     *
     * @param array $data 表单数据。
     * @return array
     */
    private function handle_card_inventory_action($data) {
        if (!current_user_can('manage_options')) {
            return [
                'class'   => 'notice-error',
                'message' => __('权限不足', 'qilingshop'),
            ];
        }

        $nonce = isset($data['qls_card_inventory_nonce']) ? (string) $data['qls_card_inventory_nonce'] : '';
        if (!wp_verify_nonce($nonce, 'qls_card_inventory_action')) {
            return [
                'class'   => 'notice-error',
                'message' => __('安全校验失败，请刷新页面后重试。', 'qilingshop'),
            ];
        }

        $action = isset($data['qls_card_action']) ? sanitize_key((string) $data['qls_card_action']) : '';
        $product_id = isset($data['product_id']) ? absint($data['product_id']) : 0;
        $sku_id = isset($data['sku_id']) ? absint($data['sku_id']) : 0;
        if (($product_id <= 0 || $sku_id <= 0) && !empty($data['card_target'])) {
            $target = explode(':', sanitize_text_field((string) $data['card_target']), 2);
            if (count($target) === 2) {
                $product_id = absint($target[0]);
                $sku_id = absint($target[1]);
            }
        }

        if (!qls_card_inventory()->is_card_product_sku($product_id, $sku_id)) {
            return [
                'class'   => 'notice-error',
                'message' => __('请先选择卡密虚拟商品和对应 SKU。', 'qilingshop'),
            ];
        }

        if ($action === 'import') {
            $cards_text = isset($data['cards_text']) ? sanitize_textarea_field((string) $data['cards_text']) : '';
            if (trim($cards_text) === '') {
                return [
                    'class'   => 'notice-warning',
                    'message' => __('请输入要导入的卡密。', 'qilingshop'),
                ];
            }

            $cards = QLS_Card_Inventory::parse_cards_text($cards_text);
            if (empty($cards)) {
                return [
                    'class'   => 'notice-warning',
                    'message' => __('未解析到有效卡密，请检查每行格式。', 'qilingshop'),
                ];
            }

            $result = qls_card_inventory()->import_batch($product_id, $sku_id, $cards);
            return [
                'class'   => $result['success'] > 0 ? 'notice-success' : 'notice-warning',
                'message' => sprintf(
                    __('导入完成：成功 %1$d 条，重复 %2$d 条，失败 %3$d 条。', 'qilingshop'),
                    (int) $result['success'],
                    (int) $result['duplicates'],
                    (int) $result['failed']
                ),
            ];
        }

        if ($action === 'generate') {
            $quantity = isset($data['quantity']) ? absint($data['quantity']) : 0;
            if ($quantity < 1 || $quantity > 1000) {
                return [
                    'class'   => 'notice-error',
                    'message' => __('生成数量需在 1 到 1000 之间。', 'qilingshop'),
                ];
            }

            $prefix = isset($data['card_prefix']) ? sanitize_text_field((string) $data['card_prefix']) : '';
            $card_no_length = isset($data['card_no_length']) ? absint($data['card_no_length']) : 12;
            $card_secret_length = isset($data['card_secret_length']) ? absint($data['card_secret_length']) : 16;
            $result = qls_card_inventory()->generate_cards(
                $product_id,
                $sku_id,
                $quantity,
                $prefix,
                $card_no_length,
                $card_secret_length
            );

            return [
                'class'   => $result['success'] > 0 ? 'notice-success' : 'notice-warning',
                'message' => sprintf(
                    __('生成完成：成功 %1$d 条，重复 %2$d 条，失败 %3$d 条。', 'qilingshop'),
                    (int) $result['success'],
                    (int) $result['duplicates'],
                    (int) $result['failed']
                ),
            ];
        }

        return [
            'class'   => 'notice-error',
            'message' => __('未知操作，请刷新页面后重试。', 'qilingshop'),
        ];
    }

    /**
     * 渲染商品编辑
     */
    public function render_product_edit() {
        if (!current_user_can('manage_options')) {
            wp_die(__('权限不足', 'qilingshop'));
        }

        $product_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $product = null;
        $save_error = '';

        // 处理单个 SKU 删除（后端兜底：即使 JS 失效也能删库）
        if ($product_id > 0 && isset($_GET['delete_sku'])) {
            $sku_id = absint($_GET['delete_sku']);
            $nonce = isset($_GET['_wpnonce']) ? (string) $_GET['_wpnonce'] : '';

            if ($sku_id > 0 && wp_verify_nonce($nonce, 'delete_sku_' . $sku_id)) {
                $sku = qls_product()->get_sku($sku_id);
                if ($sku && (int) $sku->product_id === $product_id) {
                    qls_product()->delete_sku($sku_id);
                    qls_product()->sync_product_stats($product_id);
                    $this->redirect_admin_page(admin_url('admin.php?page=qls-product-edit&id=' . $product_id . '&message=sku_deleted'));
                }
            }

            $this->redirect_admin_page(admin_url('admin.php?page=qls-product-edit&id=' . $product_id . '&message=sku_delete_failed'));
        }

        if ($product_id > 0) {
            $product = qls_product()->get($product_id, true, true);
        }

        // 处理保存
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qls_product_nonce'])) {
            if (wp_verify_nonce($_POST['qls_product_nonce'], 'qls_save_product')) {
                try {
                    $result = $this->save_product(wp_unslash($_POST), $product_id);
                } catch (\Throwable $e) {
                    if (function_exists('qilingshop_log')) {
                        qilingshop_log('Shop product save exception: ' . $e->getMessage(), 'error', [
                            'product_id' => $product_id,
                            'trace'      => $e->getTraceAsString(),
                        ]);
                    }

                    $result = [
                        'success' => false,
                        'message' => __('保存商品时发生异常，请检查填写内容后重试。', 'qilingshop'),
                    ];
                }

                if (!empty($result['success']) && !empty($result['product_id'])) {
                    $redirect_url = admin_url('admin.php?page=qls-product-edit&id=' . absint($result['product_id']) . '&message=saved');
                    $this->redirect_admin_page($redirect_url);
                }
                $save_error = isset($result['message']) ? (string) $result['message'] : __('保存失败，请稍后重试。', 'qilingshop');
            } else {
                $save_error = __('安全校验失败，请刷新页面后重试。', 'qilingshop');
            }
        }

        $categories = qls_category()->get_flat_tree();
        $shipping_rules = qls_shipping()->get_rules();
        $service_tags = $this->get_service_tags();
        $param_templates = $this->get_param_templates();
        $vip_levels = class_exists('QilingShop_VIP') ? (array) QilingShop_VIP::instance()->get_levels(true) : [];

        include QILINGSHOP_PATH . 'admin/shop/views/product-edit.php';
    }

    /**
     * 保存商品
     */
    private function save_product($data, $product_id = 0) {
        $product_data = [
            'title'            => sanitize_text_field($data['title']),
            'subtitle'         => sanitize_text_field($data['subtitle'] ?? ''),
            'slug'             => sanitize_title($data['slug'] ?? ''),
            'category_id'      => intval($data['category_id'] ?? 0),
            'shipping_rule_id' => intval($data['shipping_rule_id'] ?? 0),
            'status'           => intval($data['status'] ?? 0),
            'is_hot'           => isset($data['is_hot']) ? 1 : 0,
            'activity_recommend_enabled' => isset($data['activity_recommend_enabled']) ? 1 : 0,
            'group_display_enabled'      => isset($data['group_display_enabled']) ? 1 : 0,
            'assist_display_enabled'     => isset($data['assist_display_enabled']) ? 1 : 0,
            'sort_order'       => intval($data['sort_order'] ?? 0),
            'content'          => wp_kses_post($data['content'] ?? ''),
        ];

        // 新人专项活动字段
        $new_user_special_price = round(max(0, (float) ($data['new_user_special_price'] ?? 0)), 2);
        $new_user_special_enabled = (!empty($data['new_user_special_enabled']) && $new_user_special_price > 0) ? 1 : 0;
        $product_data['new_user_special_enabled'] = $new_user_special_enabled;
        $product_data['new_user_special_price'] = ($new_user_special_enabled && $new_user_special_price > 0)
            ? $new_user_special_price
            : null;

        // 商品图片/视频（多图gallery）
        if (!empty($data['gallery']) && is_array($data['gallery'])) {
            $gallery = [];
            foreach ($data['gallery'] as $key => $item) {
                // 跳过空项
                if (empty($item)) {
                    continue;
                }
                
                // 处理嵌套数组格式 gallery[0][url], gallery[0][type]...
                if (is_array($item)) {
                    $url = isset($item['url']) ? trim($item['url']) : '';
                    if (!empty($url)) {
                        $gallery[] = [
                            'url'   => esc_url_raw($url),
                            'type'  => isset($item['type']) ? sanitize_key($item['type']) : 'image',
                            'cover' => isset($item['cover']) && !empty($item['cover']) ? esc_url_raw($item['cover']) : '',
                        ];
                    }
                } 
                // 兼容简单字符串格式
                elseif (is_string($item) && !empty(trim($item))) {
                    $gallery[] = [
                        'url'  => esc_url_raw(trim($item)),
                        'type' => 'image',
                    ];
                }
            }
            
            if (!empty($gallery)) {
                $product_data['gallery'] = $gallery;
                // 第一张作为主图
                $product_data['main_image'] = $gallery[0];
            }
        }

        // 商品参数（支持下拉选择或自定义输入）
        if (!empty($data['params'])) {
            $params = [];
            foreach ($data['params'] as $param) {
                if (!is_array($param)) {
                    continue;
                }
                // 优先使用下拉选择的name，否则使用自定义输入的name_custom
                $name = !empty($param['name']) ? $param['name'] : ($param['name_custom'] ?? '');
                $value = $param['value'] ?? '';
                
                if (!empty($name) && !empty($value)) {
                    $params[] = [
                        'name'  => sanitize_text_field($name),
                        'value' => sanitize_text_field($value),
                    ];
                }
            }
            $product_data['params'] = $params;
        }

        // 服务标签
        if (!empty($data['service_tags'])) {
            $product_data['service_tags'] = array_map('intval', (array) $data['service_tags']);
        }

        // 虚拟商品字段
        $product_type = sanitize_key($data['product_type'] ?? 'physical');
        $product_data['product_type'] = in_array($product_type, ['physical', 'virtual']) ? $product_type : 'physical';
        
        if ($product_type === 'virtual') {
            $virtual_type = sanitize_key($data['virtual_type'] ?? 'download');
            $product_data['virtual_type'] = in_array($virtual_type, ['download', 'card', 'custom']) ? $virtual_type : 'download';
            
            // 处理虚拟内容
            $virtual_content = [];
            $vc = $data['virtual_content'] ?? [];
            
            switch ($virtual_type) {
                case 'download':
                    $virtual_content['download_url'] = esc_url_raw($vc['download_url'] ?? '');
                    $virtual_content['download_code'] = sanitize_text_field($vc['download_code'] ?? '');
                    break;
                case 'card':
                    // 卡密会在商品保存后单独处理
                    break;
                case 'custom':
                    $virtual_content['custom_content'] = wp_kses_post($vc['custom_content'] ?? '');
                    break;
            }
            
            $product_data['virtual_content'] = $virtual_content;
            
            // 虚拟商品不需要运费
            $product_data['shipping_rule_id'] = 0;
        } else {
            // 实物商品清空虚拟商品字段
            $product_data['virtual_type'] = null;
            $product_data['virtual_content'] = null;
        }

        // 保存商品
        if ($product_id > 0) {
            qls_product()->update($product_id, $product_data);
        } else {
            $product_id = qls_product()->create($product_data);
        }

         // 保存商品特色 (Tags)
         if (isset($data['product_tags'])) {
             qls_product()->save_tags($product_id, is_array($data['product_tags']) ? $data['product_tags'] : []);
         } elseif (isset($_POST['qls_product_nonce'])) { // 仅在提交表单时清空（防止误删）
             qls_product()->save_tags($product_id, []);
         }

        if (!$product_id) {
            return ['success' => false, 'message' => __('保存失败', 'qilingshop')];
        }

        $valid_attributes = null;

        // 保存规格（只有在提交了规格数据时才处理）
        if (isset($data['attributes'])) {
            // 过滤掉空的规格
            $valid_attributes = [];
            foreach ($data['attributes'] as $attr) {
                if (!is_array($attr)) {
                    continue;
                }
                if (!empty($attr['name'])) {
                    // 过滤掉空的规格值
                    if (isset($attr['values']) && is_array($attr['values'])) {
                        $valid_values = [];
                        foreach ($attr['values'] as $val) {
                            if (!is_array($val)) {
                                continue;
                            }
                            if (!empty($val['value'])) {
                                $valid_values[] = $val;
                            }
                        }
                        $attr['values'] = $valid_values;
                    }
                    if (!empty($attr['values'])) {
                        $valid_attributes[] = $attr;
                    }
                }
            }
            qls_product()->save_attributes($product_id, $valid_attributes);
        }

        $deleted_sku_ids = [];
        if (!empty($data['deleted_skus']) && is_array($data['deleted_skus'])) {
            foreach ($data['deleted_skus'] as $deleted_sku_id) {
                $did = absint($deleted_sku_id);
                if ($did > 0) {
                    $deleted_sku_ids[] = $did;
                }
            }
            $deleted_sku_ids = array_values(array_unique($deleted_sku_ids));
        }

        // 保存SKU，并同步删除已被移除的旧SKU
        $original_skus = qls_product()->get_skus($product_id);
        $original_sku_ids = [];
        foreach ($original_skus as $original_sku) {
            $oid = isset($original_sku->id) ? (int) $original_sku->id : 0;
            if ($oid > 0) {
                $original_sku_ids[] = $oid;
            }
        }
        $original_sku_ids = array_values(array_unique($original_sku_ids));

        $submitted_sku_ids = [];

        if (!empty($data['skus']) && is_array($data['skus'])) {
            foreach ($data['skus'] as $sku_data) {
                if (!is_array($sku_data)) {
                    continue;
                }

                $current_sku_id = isset($sku_data['id']) ? absint($sku_data['id']) : 0;
                if ($current_sku_id > 0) {
                    if (in_array($current_sku_id, $deleted_sku_ids, true)) {
                        continue;
                    }
                    $submitted_sku_ids[] = $current_sku_id;
                }

                qls_product()->save_sku($product_id, $sku_data);
            }
        } else {
            // 无规格时保存单一SKU，优先复用已有第一条SKU（避免ID变化）
            $sku_data = [
                'sku_code'     => sanitize_text_field($data['sku_code'] ?? ''),
                'price'        => floatval($data['price'] ?? 0),
                'sale_price'   => floatval($data['sale_price'] ?? 0) ?: null,
                'points_price' => floatval($data['points_price'] ?? 0) ?: null,
                'vip_prices'   => isset($data['vip_prices']) && is_array($data['vip_prices']) ? $data['vip_prices'] : [],
                'stock'        => intval($data['stock'] ?? 0),
                'weight'       => floatval($data['weight'] ?? 0),
                'is_default'   => 1,
            ];
            if (!empty($original_sku_ids)) {
                $reuse_sku_id = (int) $original_sku_ids[0];
                if (!in_array($reuse_sku_id, $deleted_sku_ids, true)) {
                    $sku_data['id'] = $reuse_sku_id;
                    $submitted_sku_ids[] = $reuse_sku_id;
                }
            }
            qls_product()->save_sku($product_id, $sku_data);
        }

        $submitted_sku_ids = array_values(array_unique(array_filter(array_map('intval', $submitted_sku_ids))));
        $sku_ids_to_delete = array_values(array_unique(array_merge(
            array_diff($original_sku_ids, $submitted_sku_ids),
            $deleted_sku_ids
        )));

        foreach ($sku_ids_to_delete as $delete_sku_id) {
            $delete_sku_id = (int) $delete_sku_id;
            if ($delete_sku_id <= 0) {
                continue;
            }
            $existing_sku = qls_product()->get_sku($delete_sku_id);
            if ($existing_sku && (int) $existing_sku->product_id === (int) $product_id) {
                qls_product()->delete_sku($delete_sku_id);
            }
        }

        // 同步商品统计
        qls_product()->sync_product_stats($product_id);

        // 处理卡密导入（虚拟商品卡密类型）
        if ($product_type === 'virtual' && ($data['virtual_type'] ?? '') === 'card') {
            $cards_text = $data['virtual_content']['cards_import'] ?? '';
            if (!empty(trim($cards_text))) {
                // 获取默认SKU ID
                $skus = qls_product()->get_skus($product_id);
                if (!empty($skus)) {
                    $default_sku = $skus[0];
                    $cards = QLS_Card_Inventory::parse_cards_text($cards_text);
                    if (!empty($cards)) {
                        $import_result = qls_card_inventory()->import_batch($product_id, $default_sku->id, $cards);
                        // 可选：记录导入结果
                        // $import_result['success'], $import_result['failed'], $import_result['duplicates']
                    }
                }
            }
        }

        // 保存团购设置（仅实物商品支持团购）
        if ($product_type === 'physical') {
            $group_data = [
                'group_enabled'   => isset($data['group_enabled']) && $data['group_enabled'] == '1',
                'group_price'     => floatval($data['group_price'] ?? 0),
                'group_size'      => intval($data['group_size'] ?? 2),
                'time_limit'      => intval($data['time_limit'] ?? 24),
                'limit_per_user'  => intval($data['limit_per_user'] ?? 0),
                'group_stock'     => intval($data['group_stock'] ?? 0),
                'start_time'      => !empty($data['group_start_time']) ? date('Y-m-d H:i:s', strtotime($data['group_start_time'])) : null,
                'end_time'        => !empty($data['group_end_time']) ? date('Y-m-d H:i:s', strtotime($data['group_end_time'])) : null,
            ];
            $this->save_product_group_settings($product_id, $group_data);
        }

        return ['success' => true, 'product_id' => $product_id];
    }

    /**
     * 处理批量操作
     */
    private function handle_bulk_action() {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('权限不足', 'qilingshop'),
            ];
        }

        $nonce = isset($_POST['_wpnonce']) ? (string) wp_unslash($_POST['_wpnonce']) : '';
        if (!wp_verify_nonce($nonce, 'bulk-products')) {
            return [
                'success' => false,
                'message' => __('安全校验失败，请刷新页面后重试。', 'qilingshop'),
            ];
        }

        $post = wp_unslash($_POST);
        $action = isset($post['action']) ? sanitize_key($post['action']) : '';
        $ids = isset($post['product_ids']) ? array_map('absint', (array) $post['product_ids']) : [];
        $ids = array_values(array_unique(array_filter($ids)));

        if (empty($ids)) {
            return [
                'success' => false,
                'message' => __('请先选择要操作的商品。', 'qilingshop'),
            ];
        }

        $affected = 0;

        switch ($action) {
            case 'publish':
                foreach ($ids as $id) {
                    if (qls_product()->update($id, ['status' => 1])) {
                        $affected++;
                    }
                }
                return [
                    'success' => true,
                    'message' => sprintf(__('已上架 %d 件商品。', 'qilingshop'), $affected),
                ];

            case 'unpublish':
                foreach ($ids as $id) {
                    if (qls_product()->update($id, ['status' => 0])) {
                        $affected++;
                    }
                }
                return [
                    'success' => true,
                    'message' => sprintf(__('已下架 %d 件商品。', 'qilingshop'), $affected),
                ];

            case 'delete':
                foreach ($ids as $id) {
                    if (qls_product()->delete($id)) {
                        $affected++;
                    }
                }
                return [
                    'success' => true,
                    'message' => sprintf(__('已删除 %d 件商品。', 'qilingshop'), $affected),
                ];

            case 'bulk_edit':
                $settings = isset($post['bulk_edit']) && is_array($post['bulk_edit'])
                    ? $this->sanitize_product_bulk_edit_settings($post['bulk_edit'])
                    : $this->sanitize_product_bulk_edit_settings([]);

                if (!$this->has_product_bulk_edit_changes($settings)) {
                    return [
                        'success' => false,
                        'message' => __('没有可更新的字段，商品未修改。', 'qilingshop'),
                    ];
                }

                foreach ($ids as $id) {
                    if ($this->apply_product_bulk_edit($id, $settings)) {
                        $affected++;
                    }
                }

                return [
                    'success' => true,
                    'message' => sprintf(__('已批量编辑 %d 件商品。', 'qilingshop'), $affected),
                ];
        }

        return [
            'success' => false,
            'message' => __('未知批量操作。', 'qilingshop'),
        ];
    }

    /**
     * 清洗商品批量编辑设置。
     *
     * @param array $raw 原始表单数据。
     * @return array
     */
    private function sanitize_product_bulk_edit_settings($raw) {
        $raw = is_array($raw) ? $raw : [];

        $status = isset($raw['status']) ? (string) $raw['status'] : '';
        $status = in_array($status, ['0', '1', '2'], true) ? (int) $status : null;

        $category_raw = isset($raw['category_id']) ? (string) $raw['category_id'] : '__no_change';
        $category_id = $category_raw === '__no_change' ? null : max(0, absint($category_raw));

        $shipping_raw = isset($raw['shipping_rule_id']) ? (string) $raw['shipping_rule_id'] : '__no_change';
        $shipping_rule_id = $shipping_raw === '__no_change' ? null : max(0, absint($shipping_raw));

        $toggle_fields = [
            'is_hot',
            'activity_recommend_enabled',
            'group_display_enabled',
            'assist_display_enabled',
        ];
        $toggles = [];
        foreach ($toggle_fields as $field) {
            $value = isset($raw[$field]) ? sanitize_key((string) $raw[$field]) : 'no_change';
            $toggles[$field] = in_array($value, ['enable', 'disable'], true)
                ? ($value === 'enable' ? 1 : 0)
                : null;
        }

        $new_user_mode = isset($raw['new_user_special_mode']) ? sanitize_key((string) $raw['new_user_special_mode']) : 'no_change';
        if (!in_array($new_user_mode, ['no_change', 'enable', 'disable'], true)) {
            $new_user_mode = 'no_change';
        }

        $service_tags_mode = isset($raw['service_tags_mode']) ? sanitize_key((string) $raw['service_tags_mode']) : 'no_change';
        if (!in_array($service_tags_mode, ['no_change', 'replace', 'add', 'remove'], true)) {
            $service_tags_mode = 'no_change';
        }

        $service_tag_ids = isset($raw['service_tag_ids']) ? array_map('absint', (array) $raw['service_tag_ids']) : [];
        $service_tag_ids = array_values(array_unique(array_filter($service_tag_ids)));

        return [
            'status'                         => $status,
            'category_id'                    => $category_id,
            'shipping_rule_id'               => $shipping_rule_id,
            'toggles'                        => $toggles,
            'new_user_special_mode'          => $new_user_mode,
            'new_user_special_price'         => $this->sanitize_bulk_decimal_value($raw['new_user_special_price'] ?? ''),
            'new_user_special_price_set'     => $this->is_bulk_value_provided($raw['new_user_special_price'] ?? ''),
            'service_tags_mode'              => $service_tags_mode,
            'service_tag_ids'                => $service_tag_ids,
            'price_mode'                     => $this->sanitize_bulk_adjust_mode($raw['price_mode'] ?? 'none', true),
            'price_value'                    => $this->sanitize_bulk_decimal_value($raw['price_value'] ?? ''),
            'price_value_set'                => $this->is_bulk_value_provided($raw['price_value'] ?? ''),
            'stock_mode'                     => $this->sanitize_bulk_adjust_mode($raw['stock_mode'] ?? 'none', false),
            'stock_value'                    => $this->sanitize_bulk_int_value($raw['stock_value'] ?? ''),
            'stock_value_set'                => $this->is_bulk_value_provided($raw['stock_value'] ?? ''),
            'sort_order_mode'                => $this->sanitize_bulk_adjust_mode($raw['sort_order_mode'] ?? 'none', false),
            'sort_order_value'               => $this->sanitize_bulk_int_value($raw['sort_order_value'] ?? ''),
            'sort_order_value_set'           => $this->is_bulk_value_provided($raw['sort_order_value'] ?? ''),
        ];
    }

    /**
     * 判断批量编辑是否有实际修改项。
     *
     * @param array $settings 已清洗设置。
     * @return bool
     */
    private function has_product_bulk_edit_changes($settings) {
        if ($settings['status'] !== null || $settings['category_id'] !== null || $settings['shipping_rule_id'] !== null) {
            return true;
        }

        foreach ((array) $settings['toggles'] as $value) {
            if ($value !== null) {
                return true;
            }
        }

        if ($settings['new_user_special_mode'] === 'disable') {
            return true;
        }

        if (
            $settings['new_user_special_mode'] === 'enable'
            && !empty($settings['new_user_special_price_set'])
            && (float) $settings['new_user_special_price'] > 0
        ) {
            return true;
        }

        if ($settings['service_tags_mode'] === 'replace') {
            return true;
        }

        if (
            in_array($settings['service_tags_mode'], ['add', 'remove'], true)
            && !empty($settings['service_tag_ids'])
        ) {
            return true;
        }

        if ($this->is_valid_bulk_adjustment($settings['price_mode'], $settings['price_value'], $settings['price_value_set'])) {
            return true;
        }

        if ($this->is_valid_bulk_adjustment($settings['stock_mode'], $settings['stock_value'], $settings['stock_value_set'])) {
            return true;
        }

        if ($this->is_valid_bulk_adjustment($settings['sort_order_mode'], $settings['sort_order_value'], $settings['sort_order_value_set'])) {
            return true;
        }

        return false;
    }

    /**
     * 应用单个商品的批量编辑设置。
     *
     * @param int   $product_id 商品 ID。
     * @param array $settings   已清洗设置。
     * @return bool
     */
    private function apply_product_bulk_edit($product_id, $settings) {
        $product_id = absint($product_id);
        if ($product_id <= 0) {
            return false;
        }

        $product = qls_product()->get($product_id);
        if (!$product) {
            return false;
        }

        $product_data = [];

        if ($settings['status'] !== null) {
            $product_data['status'] = (int) $settings['status'];
        }

        if ($settings['category_id'] !== null) {
            $product_data['category_id'] = (int) $settings['category_id'];
        }

        $product_type = isset($product->product_type) ? (string) $product->product_type : 'physical';
        if ($settings['shipping_rule_id'] !== null && $product_type !== 'virtual') {
            $product_data['shipping_rule_id'] = (int) $settings['shipping_rule_id'];
        }

        foreach ((array) $settings['toggles'] as $field => $value) {
            if ($value !== null) {
                $product_data[$field] = (int) $value;
            }
        }

        if ($settings['new_user_special_mode'] === 'disable') {
            $product_data['new_user_special_enabled'] = 0;
            $product_data['new_user_special_price'] = null;
        } elseif (
            $settings['new_user_special_mode'] === 'enable'
            && !empty($settings['new_user_special_price_set'])
            && (float) $settings['new_user_special_price'] > 0
        ) {
            $product_data['new_user_special_enabled'] = 1;
            $product_data['new_user_special_price'] = (float) $settings['new_user_special_price'];
        }

        if ($settings['service_tags_mode'] !== 'no_change') {
            $current_tags = isset($product->service_tags) && is_array($product->service_tags)
                ? array_values(array_unique(array_filter(array_map('absint', $product->service_tags))))
                : [];
            $target_tags = $this->calculate_bulk_service_tags(
                $current_tags,
                $settings['service_tags_mode'],
                $settings['service_tag_ids']
            );
            $product_data['service_tags'] = $target_tags;
        }

        if ($this->is_valid_bulk_adjustment($settings['sort_order_mode'], $settings['sort_order_value'], $settings['sort_order_value_set'])) {
            $current_sort = isset($product->sort_order) ? (int) $product->sort_order : 0;
            $product_data['sort_order'] = $this->calculate_bulk_integer_value(
                $current_sort,
                $settings['sort_order_mode'],
                (int) $settings['sort_order_value']
            );
        }

        $updated = false;
        if (!empty($product_data)) {
            $updated = qls_product()->update($product_id, $product_data);
        }

        $sku_updated = $this->apply_product_bulk_sku_edit($product_id, $settings);

        return $updated || $sku_updated;
    }

    /**
     * 批量更新商品 SKU 价格/库存。
     *
     * @param int   $product_id 商品 ID。
     * @param array $settings   已清洗设置。
     * @return bool
     */
    private function apply_product_bulk_sku_edit($product_id, $settings) {
        $price_valid = $this->is_valid_bulk_adjustment($settings['price_mode'], $settings['price_value'], $settings['price_value_set']);
        $stock_valid = $this->is_valid_bulk_adjustment($settings['stock_mode'], $settings['stock_value'], $settings['stock_value_set']);

        if (!$price_valid && !$stock_valid) {
            return false;
        }

        $skus = qls_product()->get_skus($product_id);
        if (empty($skus)) {
            return false;
        }

        $db = QLS_Shop_Database::instance();
        $changed = false;

        foreach ($skus as $sku) {
            if (empty($sku->id)) {
                continue;
            }

            $sku_data = [];

            if ($price_valid) {
                $current_price = isset($sku->price) ? (float) $sku->price : 0;
                $sku_data['price'] = $this->calculate_bulk_decimal_value(
                    $current_price,
                    $settings['price_mode'],
                    (float) $settings['price_value']
                );
            }

            if ($stock_valid) {
                $current_stock = isset($sku->stock) ? (int) $sku->stock : 0;
                $sku_data['stock'] = $this->calculate_bulk_integer_value(
                    $current_stock,
                    $settings['stock_mode'],
                    (int) $settings['stock_value']
                );
            }

            if (!empty($sku_data) && $db->update('product_skus', $sku_data, ['id' => (int) $sku->id]) !== false) {
                $changed = true;
            }
        }

        if ($changed) {
            qls_product()->sync_product_stats($product_id);
        }

        return $changed;
    }

    /**
     * 清洗批量调整模式。
     *
     * @param string $mode          输入模式。
     * @param bool   $allow_percent 是否允许百分比模式。
     * @return string
     */
    private function sanitize_bulk_adjust_mode($mode, $allow_percent) {
        $mode = sanitize_key((string) $mode);
        $allowed = ['none', 'set', 'increase_amount', 'decrease_amount'];
        if ($allow_percent) {
            $allowed[] = 'increase_percent';
            $allowed[] = 'decrease_percent';
        }

        return in_array($mode, $allowed, true) ? $mode : 'none';
    }

    /**
     * 判断调整值是否有效。
     *
     * @param string $mode      调整模式。
     * @param mixed  $value     调整值。
     * @param bool   $value_set 是否填写值。
     * @return bool
     */
    private function is_valid_bulk_adjustment($mode, $value, $value_set) {
        if ($mode === 'none' || !$value_set) {
            return false;
        }

        if ($mode === 'set') {
            return (float) $value >= 0;
        }

        return (float) $value > 0;
    }

    /**
     * 清洗金额/百分比输入。
     *
     * @param mixed $value 原始值。
     * @return float
     */
    private function sanitize_bulk_decimal_value($value) {
        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }

        return round(max(0, (float) $value), 2);
    }

    /**
     * 清洗整数输入。
     *
     * @param mixed $value 原始值。
     * @return int
     */
    private function sanitize_bulk_int_value($value) {
        return max(0, (int) $value);
    }

    /**
     * 判断批量编辑数值是否填写。
     *
     * @param mixed $value 原始值。
     * @return bool
     */
    private function is_bulk_value_provided($value) {
        if (is_array($value)) {
            return false;
        }

        $value = is_string($value) ? str_replace(',', '.', trim($value)) : $value;
        return $value !== '' && is_numeric($value);
    }

    /**
     * 计算批量金额调整结果。
     *
     * @param float  $current 当前值。
     * @param string $mode    调整模式。
     * @param float  $value   调整值。
     * @return float
     */
    private function calculate_bulk_decimal_value($current, $mode, $value) {
        $current = max(0, (float) $current);
        $value = max(0, (float) $value);

        switch ($mode) {
            case 'set':
                return round($value, 2);
            case 'increase_amount':
                return round($current + $value, 2);
            case 'decrease_amount':
                return round(max(0, $current - $value), 2);
            case 'increase_percent':
                return round($current * (1 + $value / 100), 2);
            case 'decrease_percent':
                return round(max(0, $current * max(0, 1 - $value / 100)), 2);
            default:
                return round($current, 2);
        }
    }

    /**
     * 计算批量整数调整结果。
     *
     * @param int    $current 当前值。
     * @param string $mode    调整模式。
     * @param int    $value   调整值。
     * @return int
     */
    private function calculate_bulk_integer_value($current, $mode, $value) {
        $current = max(0, (int) $current);
        $value = max(0, (int) $value);

        switch ($mode) {
            case 'set':
                return $value;
            case 'increase_amount':
                return $current + $value;
            case 'decrease_amount':
                return max(0, $current - $value);
            default:
                return $current;
        }
    }

    /**
     * 根据批量模式计算服务标签。
     *
     * @param array  $current_tags 当前标签 ID。
     * @param string $mode         调整模式。
     * @param array  $target_tags  目标标签 ID。
     * @return array
     */
    private function calculate_bulk_service_tags($current_tags, $mode, $target_tags) {
        $current_tags = array_values(array_unique(array_filter(array_map('absint', (array) $current_tags))));
        $target_tags = array_values(array_unique(array_filter(array_map('absint', (array) $target_tags))));

        switch ($mode) {
            case 'replace':
                return $target_tags;
            case 'add':
                return array_values(array_unique(array_merge($current_tags, $target_tags)));
            case 'remove':
                return array_values(array_diff($current_tags, $target_tags));
            default:
                return $current_tags;
        }
    }

    /**
     * 渲染分类管理
     */
    public function render_categories() {
        // 处理保存
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qls_category_nonce'])) {
            if (wp_verify_nonce($_POST['qls_category_nonce'], 'qls_save_category')) {
                $this->save_category($_POST);
            }
        }

        // 处理删除
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
            if (wp_verify_nonce($_GET['_wpnonce'] ?? '', 'delete_category_' . $_GET['id'])) {
                qls_category()->delete(intval($_GET['id']));
                wp_safe_redirect(admin_url('admin.php?page=qls-categories&message=deleted'));
                exit;
            }
        }

        $categories = qls_category()->get_flat_tree();
        $category_product_count_map = qls_category()->get_product_count_map(1);
        $edit_category = null;

        if (isset($_GET['edit'])) {
            $edit_category = qls_category()->get(intval($_GET['edit']));
        }

        include QILINGSHOP_PATH . 'admin/shop/views/categories.php';
    }

    /**
     * 保存分类
     */
    private function save_category($data) {
        $category_data = [
            'name'        => sanitize_text_field($data['name']),
            'slug'        => sanitize_title($data['slug'] ?? ''),
            'parent_id'   => intval($data['parent_id'] ?? 0),
            'image'       => esc_url_raw($data['image'] ?? ''),
            'description' => sanitize_textarea_field($data['description'] ?? ''),
            'seo_keywords'=> sanitize_text_field($data['seo_keywords'] ?? ''),
            'sort_order'  => intval($data['sort_order'] ?? 0),
            'status'      => intval($data['status'] ?? 1),
        ];

        if (!empty($data['category_id'])) {
            qls_category()->update(intval($data['category_id']), $category_data);
        } else {
            qls_category()->create($category_data);
        }
    }

    /**
     * 渲染订单管理
     */
    public function render_orders() {
        // 处理订单操作
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handle_order_action($_POST);
        }

        // 筛选参数
        $args = [
            'status'  => isset($_GET['status']) ? $_GET['status'] : '',
            'keyword' => isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '',
            'limit'   => 20,
            'offset'  => isset($_GET['paged']) ? (intval($_GET['paged']) - 1) * 20 : 0,
        ];

        $db = QLS_Shop_Database::instance();
        $wpdb = $db->get_wpdb();
        $table_orders = $db->get_table('orders');
        $has_unique_order_no_index = $this->orders_table_has_unique_order_no_index($table_orders);
        $needs_dedup = $args['status'] === '' && !$has_unique_order_no_index;
        
        $where_clauses = [];

        // Status Filter
        if ($args['status'] !== '') {
            $status = intval($args['status']);
            $where_clauses[] = $wpdb->prepare("status = %d", $status);
        }

        // Keyword Search
        if (!empty($args['keyword'])) {
            $keyword_clause = $this->build_admin_order_keyword_where($wpdb, $args['keyword']);
            if ($keyword_clause !== '') {
                $where_clauses[] = $keyword_clause;
            }
        }

        $orders = $this->get_admin_orders_page_rows(
            $wpdb,
            $table_orders,
            $where_clauses,
            $needs_dedup,
            $args['limit'],
            $args['offset']
        );

        $refund_map = [];
        $order_items_map = [];
        $shipment_summary_map = [];
        if (!empty($orders)) {
            $order_ids = [];
            foreach ($orders as $order_row) {
                $order_id = (int) ($order_row->id ?? 0);
                if ($order_id > 0) {
                    $order_ids[] = $order_id;
                }
            }

            if (!empty($order_ids)) {
                $order_ids = array_values(array_unique($order_ids));
                if (function_exists('qls_shop_refund')) {
                    $refund_map = qls_shop_refund()->get_by_orders($order_ids);
                }
                $order_items_map = qls_shop_order()->get_admin_list_items_by_orders($order_ids);
                if (function_exists('qls_shipment')) {
                    $shipment_summary_map = qls_shipment()->get_order_shipment_summaries($order_ids, $order_items_map);
                }
            }
        }

        // 加载订单商品
        foreach ($orders as &$order) {
            $order_id = (int) $order->id;
            $order->items = isset($order_items_map[$order_id]) ? $order_items_map[$order_id] : [];
            $order->shipment_summary = isset($shipment_summary_map[$order_id])
                ? $shipment_summary_map[$order_id]
                : [
                    'physical_quantity' => 0,
                    'shipped_quantity'  => 0,
                    'shipment_count'    => 0,
                ];
            $order->refund_record = isset($refund_map[$order_id]) ? $refund_map[$order_id] : null;
        }
        unset($order); // Fix: Break the reference to avoid corruption in subsequent loops

        $order_user_map = $this->get_admin_order_user_map($orders);

        // Count total orders
        if ($needs_dedup) {
            // For All view, count unique order_nos
            // Use logical equivalent of the main query for count
            $total_sql = "SELECT COUNT(DISTINCT order_no) FROM {$table_orders}";
            if (!empty($where_clauses)) {
                // Approximate count with filters - strict subquery count would be better but COUNT(DISTINCT) is good proxy
                 // 关键词命中重复记录时，使用 DISTINCT 保持计数唯一。
                 $total_sql = "SELECT COUNT(DISTINCT order_no) FROM {$table_orders} WHERE 1=1";
                 if (!empty($where_clauses)) {
                     $total_sql .= " AND " . implode(' AND ', $where_clauses);
                 }
            }
        } else {
            $total_sql = "SELECT COUNT(*) FROM {$table_orders}";
            if (!empty($where_clauses)) {
                $total_sql .= " WHERE " . implode(' AND ', $where_clauses);
            }
        }
        $total = $wpdb->get_var($total_sql);

        // 状态统计
        $status_counts = function_exists('qls_shop_order') ? qls_shop_order()->get_status_counts() : [];
        for ($i = 0; $i <= 6; $i++) {
            if (!isset($status_counts[$i])) {
                $status_counts[$i] = 0;
            }
        }

        include QILINGSHOP_PATH . 'admin/shop/views/orders-list.php';
    }

    /**
     * 渲染售后退款管理
     */
    public function render_refunds() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handle_refund_action($_POST);
        }

        if (!class_exists('QLS_Shop_Refund')) {
            require_once QILINGSHOP_PATH . 'includes/shop/class-qls-shop-refund.php';
        }

        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $keyword = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $limit = 20;
        $offset = ($paged - 1) * $limit;

        $refunds = qls_shop_refund()->get_list([
            'status' => $status,
            'keyword' => $keyword,
            'limit' => $limit,
            'offset' => $offset,
        ]);

        $refund_order_map = [];
        if (!empty($refunds) && function_exists('qls_shop_order')) {
            $order_ids = [];
            foreach ((array) $refunds as $refund_row) {
                $order_id = (int) ($refund_row->order_id ?? 0);
                if ($order_id > 0) {
                    $order_ids[] = $order_id;
                }
            }

            if (!empty($order_ids)) {
                $refund_order_map = qls_shop_order()->get_by_ids($order_ids);
            }
        }
        $refund_user_map = $this->get_admin_user_display_map($refunds);
        $refund_logs_map = [];
        if (!empty($refunds)) {
            $refund_ids = [];
            foreach ((array) $refunds as $refund_row) {
                $refund_id = (int) ($refund_row->id ?? 0);
                if ($refund_id > 0) {
                    $refund_ids[] = $refund_id;
                }
            }

            if (!empty($refund_ids)) {
                $refund_logs_map = qls_shop_refund()->get_logs_by_refunds($refund_ids, 3);
            }
        }

        $counts = qls_shop_refund()->get_status_counts();
        $total = $status === ''
            ? (int) array_sum($counts)
            : (int) ($counts[(int) $status] ?? 0);

        include QILINGSHOP_PATH . 'admin/shop/views/refunds.php';
    }

    /**
     * 渲染售后工单管理。
     */
    public function render_tickets() {
        if (!current_user_can('manage_options')) {
            wp_die(__('权限不足', 'qilingshop'));
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qls_ticket_action'])) {
            $this->handle_ticket_action($_POST);
        }

        $ticket_manager = function_exists('qls_shop_ticket') ? qls_shop_ticket() : null;
        if (!$ticket_manager) {
            wp_die(__('工单模块不可用', 'qilingshop'));
        }

        $current_ticket_id = isset($_GET['ticket_id']) ? absint($_GET['ticket_id']) : 0;
        $current_ticket = null;
        $current_ticket_messages = [];
        $current_ticket_order = null;
        $current_ticket_user = null;
        $message_author_map = [];

        if ($current_ticket_id > 0) {
            $current_ticket = $ticket_manager->get_ticket($current_ticket_id);
            if ($current_ticket) {
                $current_ticket_messages = $ticket_manager->get_messages($current_ticket_id, true);
                $current_ticket_order = !empty($current_ticket->order_id)
                    ? $ticket_manager->get_order_context((int) $current_ticket->order_id)
                    : null;
                $current_ticket_user = !empty($current_ticket->user_id)
                    ? get_user_by('id', (int) $current_ticket->user_id)
                    : null;
                $message_author_map = $this->get_admin_user_display_map($current_ticket_messages, 'author_id');
            }
        }

        $status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        $type = isset($_GET['type']) ? sanitize_key(wp_unslash($_GET['type'])) : '';
        $keyword = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $limit = 20;
        $offset = ($paged - 1) * $limit;

        $query_args = [
            'status'  => $status,
            'type'    => $type,
            'keyword' => $keyword,
            'limit'   => $limit,
            'offset'  => $offset,
        ];

        $tickets = $ticket_manager->get_list($query_args);
        $total = $ticket_manager->get_count([
            'status'  => $status,
            'type'    => $type,
            'keyword' => $keyword,
        ]);
        $counts = $ticket_manager->get_status_counts();
        $ticket_user_map = $this->get_admin_user_display_map($tickets);
        $ticket_order_map = [];

        if (!empty($tickets) && function_exists('qls_shop_order')) {
            $order_ids = [];
            foreach ((array) $tickets as $ticket_row) {
                $order_id = isset($ticket_row->order_id) ? (int) $ticket_row->order_id : 0;
                if ($order_id > 0) {
                    $order_ids[$order_id] = $order_id;
                }
            }

            if (!empty($order_ids)) {
                $ticket_order_map = qls_shop_order()->get_by_ids(array_values($order_ids));
            }
        }

        include QILINGSHOP_PATH . 'admin/shop/views/tickets.php';
    }

    /**
     * 处理后台工单动作。
     *
     * @param array $data POST 数据。
     * @return void
     */
    private function handle_ticket_action($data) {
        if (!current_user_can('manage_options')) {
            wp_die(__('权限不足', 'qilingshop'));
        }

        $nonce = isset($data['_wpnonce']) ? sanitize_text_field(wp_unslash($data['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, 'qls_ticket_admin_action')) {
            add_settings_error('qls_shop_tickets', 'invalid_nonce', __('安全验证失败，请刷新后重试。', 'qilingshop'), 'error');
            return;
        }

        $action = isset($data['qls_ticket_action']) ? sanitize_key(wp_unslash($data['qls_ticket_action'])) : '';
        $ticket_id = isset($data['ticket_id']) ? absint($data['ticket_id']) : 0;

        if ($action !== 'reply' || $ticket_id <= 0 || !function_exists('qls_shop_ticket')) {
            add_settings_error('qls_shop_tickets', 'invalid_action', __('无效的工单操作。', 'qilingshop'), 'error');
            return;
        }

        $message = isset($data['reply_message']) ? wp_unslash($data['reply_message']) : '';
        $internal_note = isset($data['internal_note']) ? wp_unslash($data['internal_note']) : '';
        $raw_status = isset($data['status']) ? wp_unslash($data['status']) : '';
        if (is_array($raw_status)) {
            $raw_status = '';
        }

        $status = (string) $raw_status !== '' ? (int) $raw_status : null;
        $ticket_manager = qls_shop_ticket();
        $attachments = $ticket_manager->collect_uploaded_attachments('ticket_attachments', get_current_user_id(), [
            'source'    => 'admin',
            'ticket_id' => $ticket_id,
        ]);

        if (is_wp_error($attachments)) {
            add_settings_error('qls_shop_tickets', 'ticket_attachment_failed', $attachments->get_error_message(), 'error');
            return;
        }

        $result = $ticket_manager->admin_reply(
            $ticket_id,
            get_current_user_id(),
            $message,
            $status,
            $internal_note,
            $attachments
        );

        if (is_wp_error($result)) {
            add_settings_error('qls_shop_tickets', 'ticket_action_failed', $result->get_error_message(), 'error');
            return;
        }

        add_settings_error('qls_shop_tickets', 'ticket_updated', __('工单已更新。', 'qilingshop'), 'success');
    }

    /**
     * 构建退款后台展示上下文。
     *
     * @param object      $refund 售后记录
     * @param object|null $order  订单对象
     * @return array
     */
    private function build_refund_admin_meta($refund, $order = null) {
        $configured_mode = sanitize_key((string) get_option('qilingshop_shop_refund_mode', 'withdrawable_balance'));
        if (!in_array($configured_mode, ['withdrawable_balance', 'gateway'], true)) {
            $configured_mode = 'withdrawable_balance';
        }

        if (!is_object($order) && is_object($refund) && !empty($refund->order_id) && function_exists('qls_shop_order')) {
            $order = qls_shop_order()->get((int) $refund->order_id);
        }

        $stored_mode = sanitize_key((string) ($refund->refund_mode ?? ''));
        if (!in_array($stored_mode, ['withdrawable_balance', 'gateway'], true)) {
            $stored_mode = '';
        }

        $payment_method = sanitize_key((string) ($order->payment_method ?? ''));
        $gateway = $this->normalize_shop_refund_gateway($payment_method);
        $gateway_support = $this->inspect_shop_refund_gateway_support($gateway, $order);
        $handled_state = (int) ($refund->refund_handled ?? 0);
        $status = (int) ($refund->status ?? 0);
        $gateway_status = sanitize_key((string) ($refund->gateway_refund_status ?? ''));
        $effective_mode = $configured_mode;
        if ($stored_mode !== '' && ($status === QLS_Shop_Refund::STATUS_REFUNDED || $handled_state === 1 || in_array($gateway_status, ['processing', 'local_finalize_failed'], true))) {
            $effective_mode = $stored_mode;
        }

        return [
            'configured_mode'          => $configured_mode,
            'configured_mode_label'    => $this->get_shop_refund_mode_label($configured_mode),
            'stored_mode'              => $stored_mode,
            'stored_mode_label'        => $stored_mode !== '' ? $this->get_shop_refund_mode_label($stored_mode) : '',
            'effective_mode'           => $effective_mode,
            'effective_mode_label'     => $this->get_shop_refund_mode_label($effective_mode),
            'payment_method'           => $payment_method,
            'payment_method_label'     => $this->get_shop_payment_method_label($payment_method),
            'payment_version'          => $gateway_support['payment_version'],
            'payment_version_label'    => $gateway_support['payment_version_label'],
            'payment_version_fallback' => $gateway_support['payment_version_fallback'],
            'gateway'                  => $gateway,
            'gateway_label'            => $this->get_shop_refund_gateway_label($gateway),
            'gateway_supported'        => $gateway_support['supported'],
            'gateway_message'          => $gateway_support['message'],
            'warnings'                 => $gateway_support['warnings'],
            'gateway_status'           => $gateway_status,
            'gateway_status_label'     => $this->get_shop_refund_gateway_status_label($gateway_status),
            'is_processing'            => ((int) ($refund->refund_handled ?? 0) === 2 && $gateway_status === 'processing'),
            'needs_reconcile'          => ($gateway_status === 'local_finalize_failed'),
        ];
    }

    /**
     * 退款模式标签。
     *
     * @param string $mode
     * @return string
     */
    private function get_shop_refund_mode_label($mode) {
        if ($mode === 'gateway') {
            return __('原路退回', 'qilingshop');
        }

        return __('退回可提现余额', 'qilingshop');
    }

    /**
     * 支付方式标签。
     *
     * @param string $payment_method
     * @return string
     */
    private function get_shop_payment_method_label($payment_method) {
        $labels = [
            'alipay'         => __('支付宝', 'qilingshop'),
            'alipay_qr'      => __('支付宝扫码', 'qilingshop'),
            'alipay_page'    => __('支付宝网页', 'qilingshop'),
            'wechat'         => __('微信支付', 'qilingshop'),
            'wxpay'          => __('微信支付', 'qilingshop'),
            'weixin'         => __('微信支付', 'qilingshop'),
            'wechat_miniapp' => __('微信小程序支付', 'qilingshop'),
            'points'         => __('积分支付', 'qilingshop'),
            'balance'        => __('余额支付', 'qilingshop'),
            'free'           => __('免费订单', 'qilingshop'),
        ];

        $payment_method = sanitize_key((string) $payment_method);
        if ($payment_method === '') {
            return __('未记录', 'qilingshop');
        }

        return $labels[$payment_method] ?? $payment_method;
    }

    /**
     * 退款路由标签。
     *
     * @param string $gateway
     * @return string
     */
    private function get_shop_refund_gateway_label($gateway) {
        $labels = [
            'alipay'         => __('支付宝原路退款', 'qilingshop'),
            'wechat'         => __('微信原路退款', 'qilingshop'),
            'wechat_miniapp' => __('微信小程序原路退款', 'qilingshop'),
        ];

        return $labels[$gateway] ?? '';
    }

    /**
     * 网关退款状态标签。
     *
     * @param string $status
     * @return string
     */
    private function get_shop_refund_gateway_status_label($status) {
        $labels = [
            'processing'            => __('退款处理中', 'qilingshop'),
            'success'               => __('原路退款成功', 'qilingshop'),
            'failed'                => __('原路退款失败', 'qilingshop'),
            'local_finalize_failed' => __('资金已退回，但本地状态收尾失败', 'qilingshop'),
            'fallback_to_withdrawable_balance' => __('原路不支持，已自动退回可提现余额', 'qilingshop'),
        ];

        $status = sanitize_key((string) $status);
        if ($status === '') {
            return '';
        }

        return $labels[$status] ?? strtoupper($status);
    }

    /**
     * 将支付方式映射为商城退款网关。
     *
     * @param string $payment_method
     * @return string
     */
    private function normalize_shop_refund_gateway($payment_method) {
        $payment_method = sanitize_key((string) $payment_method);

        if (in_array($payment_method, ['alipay', 'alipay_qr', 'alipay_page'], true)) {
            return 'alipay';
        }

        if (in_array($payment_method, ['wechat', 'wxpay', 'weixin'], true)) {
            return 'wechat';
        }

        if ($payment_method === 'wechat_miniapp') {
            return 'wechat_miniapp';
        }

        return '';
    }

    /**
     * 检查订单是否支持原路退款，并给出后台提示。
     *
     * @param string      $gateway 退款网关
     * @param object|null $order   订单对象
     * @return array
     */
    private function inspect_shop_refund_gateway_support($gateway, $order = null) {
        $info = [
            'supported'                => false,
            'message'                  => __('当前支付方式不支持原路退款，执行退款时将自动退回可提现余额。', 'qilingshop'),
            'warnings'                 => [],
            'payment_version'          => '',
            'payment_version_label'    => '',
            'payment_version_fallback' => false,
        ];

        if (!is_object($order)) {
            $info['message'] = __('订单支付信息缺失，暂时无法判断原路退款能力。', 'qilingshop');
            return $info;
        }

        if ($gateway === '') {
            return $info;
        }

        $order_no = sanitize_text_field((string) ($order->order_no ?? ''));
        $payment_no = sanitize_text_field((string) ($order->payment_no ?? ''));
        if ($payment_no === '' && $order_no !== '') {
            $info['warnings'][] = __('当前订单未保存第三方交易号，退款时会回退使用商户订单号发起。', 'qilingshop');
        }

        if ($gateway === 'alipay') {
            $missing = [];
            if (sanitize_text_field((string) get_option('qilingshop_alipay_app_id', '')) === '') {
                $missing[] = __('应用 AppID', 'qilingshop');
            }
            if (trim((string) get_option('qilingshop_alipay_private_key', '')) === '') {
                $missing[] = __('应用私钥', 'qilingshop');
            }
            if (trim((string) get_option('qilingshop_alipay_public_key', '')) === '') {
                $missing[] = __('支付宝公钥', 'qilingshop');
            }

            if (!empty($missing)) {
                $info['message'] = sprintf(
                    __('支付宝退款配置不完整：%s。', 'qilingshop'),
                    implode('、', $missing)
                );
                return $info;
            }

            $info['supported'] = true;
            $info['message'] = __('当前订单支持支付宝原路退款。', 'qilingshop');
            return $info;
        }

        if ($gateway === 'wechat') {
            $missing = [];
            if (sanitize_text_field((string) get_option('qilingshop_wechat_mchid', '')) === '') {
                $missing[] = __('商户号(MCHID)', 'qilingshop');
            }
            if (sanitize_text_field((string) get_option('qilingshop_wechat_appid', '')) === '') {
                $missing[] = __('AppID', 'qilingshop');
            }
            if (sanitize_text_field((string) get_option('qilingshop_wechat_key', '')) === '') {
                $missing[] = __('API 密钥', 'qilingshop');
            }
            if (trim((string) get_option('qilingshop_wechat_client_cert', '')) === '') {
                $missing[] = __('商户 API 证书', 'qilingshop');
            }
            if (trim((string) get_option('qilingshop_wechat_client_key', '')) === '') {
                $missing[] = __('商户 API 私钥', 'qilingshop');
            }

            if (!empty($missing)) {
                $info['message'] = sprintf(
                    __('网页/公众号微信退款配置不完整：%s。', 'qilingshop'),
                    implode('、', $missing)
                );
                return $info;
            }

            $info['supported'] = true;
            $info['message'] = __('当前订单支持网页/公众号微信原路退款。', 'qilingshop');
            return $info;
        }

        if ($gateway === 'wechat_miniapp') {
            $version_data = $this->resolve_admin_miniapp_payment_version($order);
            $version = $version_data['version'];
            $info['payment_version'] = $version;
            $info['payment_version_label'] = $version !== '' ? strtoupper($version) : '';
            $info['payment_version_fallback'] = !empty($version_data['fallback']);

            if (!empty($version_data['warning'])) {
                $info['warnings'][] = $version_data['warning'];
            }

            $missing = [];
            if (!(bool) get_option('qilingshop_wechat_miniapp_enabled', false)) {
                $missing[] = __('小程序支付开关', 'qilingshop');
            }
            if (sanitize_text_field((string) get_option('qilingshop_wechat_miniapp_appid', '')) === '') {
                $missing[] = __('小程序 AppID', 'qilingshop');
            }
            if (sanitize_text_field((string) get_option('qilingshop_wechat_miniapp_mchid', '')) === '') {
                $missing[] = __('商户号(MCHID)', 'qilingshop');
            }

            if ($version === 'v3') {
                if (sanitize_text_field((string) get_option('qilingshop_wechat_miniapp_key_v3', '')) === '') {
                    $missing[] = __('APIv3 密钥', 'qilingshop');
                }
                if (sanitize_text_field((string) get_option('qilingshop_wechat_miniapp_serial_no', '')) === '') {
                    $missing[] = __('商户证书序列号', 'qilingshop');
                }
                if (trim((string) get_option('qilingshop_wechat_miniapp_client_cert', '')) === '') {
                    $missing[] = __('商户 API 证书', 'qilingshop');
                }
                if (trim((string) get_option('qilingshop_wechat_miniapp_client_key', '')) === '') {
                    $missing[] = __('商户 API 私钥', 'qilingshop');
                }
                if (sanitize_text_field((string) get_option('qilingshop_wechat_miniapp_public_key_id', '')) === '') {
                    $missing[] = __('微信支付平台公钥 ID', 'qilingshop');
                }
                if (trim((string) get_option('qilingshop_wechat_miniapp_public_key_pem', '')) === '') {
                    $missing[] = __('微信支付平台公钥 PEM', 'qilingshop');
                }
            } else {
                if (sanitize_text_field((string) get_option('qilingshop_wechat_miniapp_key', '')) === '') {
                    $missing[] = __('商户支付密钥(KEY)', 'qilingshop');
                }
                if (trim((string) get_option('qilingshop_wechat_miniapp_client_cert', '')) === '') {
                    $missing[] = __('商户 API 证书', 'qilingshop');
                }
                if (trim((string) get_option('qilingshop_wechat_miniapp_client_key', '')) === '') {
                    $missing[] = __('商户 API 私钥', 'qilingshop');
                }
            }

            if (!empty($missing)) {
                $version_text = $info['payment_version_label'] !== '' ? ' ' . $info['payment_version_label'] : '';
                $info['message'] = sprintf(
                    __('微信小程序%s退款配置不完整：%s。', 'qilingshop'),
                    $version_text,
                    implode('、', $missing)
                );
                return $info;
            }

            $version_text = $info['payment_version_label'] !== '' ? ' ' . $info['payment_version_label'] : '';
            $info['supported'] = true;
            $info['message'] = sprintf(
                __('当前订单支持微信小程序%s原路退款。', 'qilingshop'),
                $version_text
            );
            return $info;
        }

        return $info;
    }

    /**
     * 解析后台小程序订单对应的支付版本。
     *
     * @param object|null $order 订单对象
     * @return array
     */
    private function resolve_admin_miniapp_payment_version($order = null) {
        $meta = is_object($order) ? ($order->payment_channel_meta ?? null) : null;
        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $meta = $decoded;
            }
        }

        $version = is_object($order) ? sanitize_key((string) ($order->payment_channel_version ?? '')) : '';
        if (!in_array($version, ['v2', 'v3'], true) && is_array($meta)) {
            $version = sanitize_key((string) ($meta['version'] ?? ''));
        }

        if (in_array($version, ['v2', 'v3'], true)) {
            return [
                'version'  => $version,
                'fallback' => false,
                'warning'  => '',
            ];
        }

        $fallback = sanitize_key((string) get_option('qilingshop_wechat_miniapp_pay_type', 'v2'));
        if (!in_array($fallback, ['v2', 'v3'], true)) {
            $fallback = 'v2';
        }

        return [
            'version'  => $fallback,
            'fallback' => true,
            'warning'  => sprintf(
                __('该笔历史小程序订单未记录支付版本，退款时将按当前后台配置的 %s 兜底。', 'qilingshop'),
                strtoupper($fallback)
            ),
        ];
    }

    /**
     * 格式化退款后台错误提示。
     *
     * @param WP_Error    $error  错误对象
     * @param object|null $refund 售后记录
     * @param object|null $order  订单对象
     * @return string
     */
    private function format_refund_admin_error($error, $refund = null, $order = null) {
        if (!is_wp_error($error)) {
            return '';
        }

        $message = sanitize_text_field($error->get_error_message());
        $meta = null;
        if (is_object($refund) || is_object($order)) {
            $meta = $this->build_refund_admin_meta($refund, $order);
        }

        $error_code = (string) $error->get_error_code();
        if ($meta && $meta['effective_mode'] === 'gateway' && !$meta['gateway_supported']) {
            $message .= ' ' . __('该订单不支持原路退款，系统会自动尝试退回可提现余额。', 'qilingshop');
        } elseif ($error_code === 'refund_gateway_not_supported') {
            $message .= ' ' . __('系统将自动改为退回可提现余额，请重试。', 'qilingshop');
        } elseif ($error_code === 'refund_gateway_service_missing') {
            $message .= ' ' . __('请检查对应支付渠道配置是否完整后重试。', 'qilingshop');
        } elseif (in_array($error_code, ['refund_finalize_failed', 'refund_reconcile_required'], true)) {
            $message .= ' ' . __('这笔退款可能已经实际打款，请先到对应支付平台核对退款记录。', 'qilingshop');
        }

        return trim($message);
    }

    /**
     * 渲染发票管理。
     */
    public function render_invoices() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handle_invoice_action($_POST);
        }

        $status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        $keyword = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $limit = 20;
        $offset = ($paged - 1) * $limit;

        $query_args = [
            'status'  => $status,
            'keyword' => $keyword,
            'limit'   => $limit,
            'offset'  => $offset,
        ];

        $invoices = function_exists('qls_invoice') ? qls_invoice()->get_list($query_args) : [];
        $invoice_user_map = $this->get_admin_user_display_map($invoices);
        $counts = function_exists('qls_invoice') ? qls_invoice()->get_status_counts() : [];
        $all_count = (int) array_sum($counts);
        if (!function_exists('qls_invoice')) {
            $total = 0;
        } elseif ($keyword === '') {
            $total = $status === ''
                ? $all_count
                : (int) ($counts[(int) $status] ?? 0);
        } else {
            $total = qls_invoice()->get_count([
                'status'  => $status,
                'keyword' => $keyword,
            ]);
        }

        include QILINGSHOP_PATH . 'admin/shop/views/invoices.php';
    }

    /**
     * 处理发票后台操作。
     *
     * @param array $data
     * @return void
     */
    private function handle_invoice_action($data) {
        if (!isset($data['invoice_action']) || !wp_verify_nonce($data['_wpnonce'] ?? '', 'qls_invoice_action')) {
            return;
        }

        if (!current_user_can('manage_options') || !function_exists('qls_invoice')) {
            return;
        }

        $invoice_id = intval($data['invoice_id'] ?? 0);
        $action = sanitize_key($data['invoice_action'] ?? '');
        if ($invoice_id <= 0 || $action === '') {
            return;
        }

        $result = null;
        switch ($action) {
            case 'issue':
                $result = qls_invoice()->issue($invoice_id, [
                    'invoice_code'       => sanitize_text_field(wp_unslash($data['invoice_code'] ?? '')),
                    'invoice_number'     => sanitize_text_field(wp_unslash($data['invoice_number'] ?? '')),
                    'invoice_url'        => esc_url_raw(wp_unslash($data['invoice_url'] ?? '')),
                    'file_attachment_id' => intval($data['file_attachment_id'] ?? 0),
                    'admin_remark'       => sanitize_textarea_field(wp_unslash($data['admin_remark'] ?? '')),
                    'admin_id'           => get_current_user_id(),
                ]);
                break;

            case 'reject':
                $result = qls_invoice()->reject(
                    $invoice_id,
                    sanitize_textarea_field(wp_unslash($data['admin_remark'] ?? '')),
                    get_current_user_id()
                );
                break;
        }

        if (is_wp_error($result)) {
            add_settings_error('qls_shop_invoices', 'invoice_error', $result->get_error_message(), 'error');
        } elseif ($result === true) {
            add_settings_error('qls_shop_invoices', 'invoice_success', __('操作成功', 'qilingshop'), 'success');
        }
    }

    private function handle_refund_action($data) {
        if (!isset($data['refund_action']) || !wp_verify_nonce($data['_wpnonce'] ?? '', 'qls_refund_action')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        if (!class_exists('QLS_Shop_Refund')) {
            require_once QILINGSHOP_PATH . 'includes/shop/class-qls-shop-refund.php';
        }

        $refund_id = intval($data['refund_id'] ?? 0);
        $action = sanitize_key($data['refund_action']);
        $remark = sanitize_textarea_field($data['remark'] ?? '');
        $return_address = sanitize_textarea_field($data['return_address'] ?? '');
        $refund_record = null;
        $order = null;

        if (!$refund_id) {
            return;
        }

        $admin_id = get_current_user_id();
        $result = null;

        switch ($action) {
            case 'approve':
                $result = qls_shop_refund()->approve_refund($refund_id, $admin_id, $remark, $return_address);
                break;
            case 'reject':
                $result = qls_shop_refund()->reject_refund($refund_id, $admin_id, $remark);
                break;
            case 'receive':
                $result = qls_shop_refund()->confirm_return_received($refund_id, $admin_id, $remark);
                break;
            case 'refund':
                $refund_record = qls_shop_refund()->get($refund_id);
                if ($refund_record && function_exists('qls_shop_order')) {
                    $order = qls_shop_order()->get((int) $refund_record->order_id);
                }
                $result = qls_shop_refund()->confirm_refund($refund_id, $admin_id, $remark);
                break;
        }

        if (is_wp_error($result)) {
            add_settings_error('qls_shop_refunds', 'refund_error', $this->format_refund_admin_error($result, $refund_record, $order), 'error');
        } elseif ($result === true) {
            $success_message = __('操作成功', 'qilingshop');
            if ($action === 'refund') {
                $latest_refund = is_object($refund_record) ? qls_shop_refund()->get((int) $refund_record->id) : null;
                $refund_meta = $this->build_refund_admin_meta($latest_refund ?: $refund_record, $order);
                $success_message = $refund_meta['effective_mode'] === 'gateway'
                    ? __('退款成功，已按原支付方式退回', 'qilingshop')
                    : __('退款成功，已退回可提现余额', 'qilingshop');
            }
            add_settings_error('qls_shop_refunds', 'refund_success', $success_message, 'success');
        }
    }

    /**
     * 处理订单操作
     */
    private function handle_order_action($data) {
        if (!isset($data['order_action']) || !wp_verify_nonce($data['_wpnonce'] ?? '', 'qls_order_action')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $order_id = intval($data['order_id'] ?? 0);
        $action = sanitize_key($data['order_action']);

        if ($action === 'cleanup_unpaid') {
            $count = qls_shop_order()->cleanup_unpaid_orders();
            add_settings_error('qls_shop_orders', 'cleanup_success', sprintf(__('已清理 %d 个超过保护期的未付款订单', 'qilingshop'), $count), 'success');
            return;
        }

        if ($action === 'cleanup_cancelled') {
            $count = qls_shop_order()->cleanup_cancelled_orders();
            add_settings_error('qls_shop_orders', 'cleanup_cancelled_success', sprintf(__('已清理 %d 个已取消订单', 'qilingshop'), $count), 'success');
            return;
        }

        if ($action === 'cleanup_completed') {
            $count = qls_shop_order()->cleanup_completed_orders();
            add_settings_error('qls_shop_orders', 'cleanup_completed_success', sprintf(__('已清理 %d 个已完成订单', 'qilingshop'), $count), 'success');
            return;
        }

        if ($action === 'bulk_ship') {
            $rows = isset($data['bulk_ship_rows']) ? wp_unslash((string) $data['bulk_ship_rows']) : '';
            $summary = $this->handle_bulk_ship_orders($rows);

            if ($summary['success'] > 0) {
                add_settings_error(
                    'qls_shop_orders',
                    'bulk_ship_success',
                    sprintf(__('批量发货完成，已发货 %d 单。', 'qilingshop'), $summary['success']),
                    'success'
                );
            }

            if (!empty($summary['errors'])) {
                $shown_errors = array_slice($summary['errors'], 0, 6);
                $more_count = max(0, count($summary['errors']) - count($shown_errors));
                $message = sprintf(
                    __('有 %1$d 行未处理：%2$s', 'qilingshop'),
                    count($summary['errors']),
                    implode('；', $shown_errors)
                );
                if ($more_count > 0) {
                    $message .= sprintf(__('；另有 %d 条失败未展示', 'qilingshop'), $more_count);
                }
                add_settings_error('qls_shop_orders', 'bulk_ship_error', $message, 'error');
            }

            if ($summary['success'] === 0 && empty($summary['errors'])) {
                add_settings_error('qls_shop_orders', 'bulk_ship_empty', __('请先填写要发货的订单。', 'qilingshop'), 'error');
            }

            return;
        }

        if (!$order_id) {
            return;
        }

        $result = null;
        $success_message = __('操作成功', 'qilingshop');
        $error_message = __('操作失败，请稍后重试', 'qilingshop');

        switch ($action) {
            case 'ship':
                $shipping_company = sanitize_text_field(wp_unslash($data['shipping_company'] ?? ''));
                $company_record = null;
                if (function_exists('qls_shipping_company')) {
                    $company_record = qls_shipping_company()->find($shipping_company);
                    if (!$company_record || (int) ($company_record->status ?? 0) !== 1) {
                        add_settings_error('qls_shop_orders', 'shipping_company_invalid', __('物流公司不存在或已停用，请先在快递物流设置中维护。', 'qilingshop'), 'error');
                        return;
                    }
                    $shipping_company = (string) $company_record->name;
                }

                $tracking_no = sanitize_text_field(wp_unslash($data['tracking_no'] ?? ''));
                $shipment_items = $this->normalize_shipment_items_from_request($data['shipment_items'] ?? []);
                $shipment_mode = sanitize_key((string) ($data['shipment_mode'] ?? ''));
                $create_waybill = !empty($data['create_waybill']) && function_exists('qls_waybill') && (int) get_option('qls_shop_waybill_enabled', 1) === 1;
                $waybill_template_id = isset($data['waybill_template_id']) ? absint(wp_unslash($data['waybill_template_id'])) : 0;
                if ($create_waybill) {
                    $waybill_template = $waybill_template_id > 0
                        ? qls_waybill()->get_template($waybill_template_id)
                        : qls_waybill()->get_default_template(QLS_Waybill::PROVIDER_KDNIAO, (int) ($company_record->id ?? 0));
                    if (!$waybill_template || (int) ($waybill_template->status ?? 0) !== 1) {
                        add_settings_error('qls_shop_orders', 'waybill_template_missing', __('请先在“快递物流”设置中配置可用的电子面单模板。', 'qilingshop'), 'error');
                        return;
                    }
                    $tracking_no = '';
                }
                if ($shipment_mode === 'split' && empty($shipment_items)) {
                    add_settings_error('qls_shop_orders', 'shipment_items_missing', __('请选择本次要发货的商品。', 'qilingshop'), 'error');
                    return;
                }

                if (function_exists('qls_shipment') && !empty($shipment_items)) {
                    $result = qls_shipment()->create($order_id, [
                        'shipping_company_id' => (int) ($company_record->id ?? 0),
                        'shipping_company'    => $shipping_company,
                        'shipping_code'       => (string) ($company_record->code ?? ''),
                        'tracking_no'         => $tracking_no,
                        'waybill_no'          => '',
                        'allow_empty_tracking' => $create_waybill,
                        'admin_id'            => get_current_user_id(),
                    ], $shipment_items);
                    if (!is_wp_error($result) && $result) {
                        $shipment_id = (int) $result;
                        $result = true;
                        if ($create_waybill) {
                            $waybill_result = qls_waybill()->create_for_shipment($shipment_id, [
                                'template_id' => $waybill_template_id,
                                'admin_id'    => get_current_user_id(),
                            ]);
                            if (is_wp_error($waybill_result)) {
                                add_settings_error(
                                    'qls_shop_orders',
                                    'waybill_create_error',
                                    sprintf(__('订单已发货，但电子面单生成失败：%s', 'qilingshop'), $waybill_result->get_error_message()),
                                    'error'
                                );
                                return;
                            }
                        }
                    }
                } else {
                    $result = qls_shop_order()->ship($order_id, $shipping_company, $tracking_no);
                }
                $success_message = $create_waybill ? __('订单发货成功，电子面单已生成', 'qilingshop') : __('订单发货成功', 'qilingshop');
                $error_message = __('订单发货失败，请检查物流信息或订单状态', 'qilingshop');
                break;
            case 'complete':
                $result = qls_shop_order()->complete($order_id);
                $success_message = __('订单已完成', 'qilingshop');
                $error_message = __('订单完成失败，请检查订单状态', 'qilingshop');
                break;
            case 'cancel':
                $result = qls_shop_order()->cancel($order_id, sanitize_text_field($data['reason'] ?? ''));
                $success_message = __('订单已取消', 'qilingshop');
                $error_message = __('订单取消失败，请检查订单状态', 'qilingshop');
                break;
            case 'refund':
                if (!class_exists('QLS_Shop_Refund')) {
                    require_once QILINGSHOP_PATH . 'includes/shop/class-qls-shop-refund.php';
                }

                $order = function_exists('qls_shop_order') ? qls_shop_order()->get($order_id) : null;
                $refund = qls_shop_refund()->get_by_order($order_id);
                if (!$refund) {
                    add_settings_error(
                        'qls_shop_orders',
                        'refund_missing',
                        __('未找到售后申请，请先让用户提交售后并在“售后退款”页面审核。', 'qilingshop'),
                        'error'
                    );
                    return;
                }

                $refund_status = (int) $refund->status;
                if ($refund_status === QLS_Shop_Refund::STATUS_REFUNDED) {
                    add_settings_error('qls_shop_orders', 'refund_done', __('该订单已退款', 'qilingshop'), 'success');
                    return;
                }

                $refund_meta = $this->build_refund_admin_meta($refund, $order);
                if (!empty($refund_meta['needs_reconcile'])) {
                    add_settings_error(
                        'qls_shop_orders',
                        'refund_reconcile_required',
                        __('该笔退款资金可能已实际退回，请先到对应支付平台人工核对退款记录，确认后再处理。', 'qilingshop'),
                        'error'
                    );
                    return;
                }

                if (!empty($refund_meta['is_processing'])) {
                    add_settings_error(
                        'qls_shop_orders',
                        'refund_processing',
                        __('退款处理中，请稍后刷新再查看。', 'qilingshop'),
                        'error'
                    );
                    return;
                }

                $return_required = !empty($refund->return_required);
                $required_status = $return_required ? QLS_Shop_Refund::STATUS_RECEIVED : QLS_Shop_Refund::STATUS_APPROVED;
                if ($refund_status !== $required_status) {
                    $current_status_text = qls_shop_refund()->get_refund_status_text($refund);
                    if ($return_required && $refund_status === QLS_Shop_Refund::STATUS_RETURNED) {
                        $message = __('买家已退货，请先在“售后退款”页面确认收货后再执行退款。', 'qilingshop');
                    } elseif ($return_required) {
                        $message = sprintf(
                            __('实物商品需先完成售后流程后再退款（当前状态：%s）。', 'qilingshop'),
                            $current_status_text
                        );
                    } else {
                        $message = sprintf(
                            __('请先在“售后退款”页面审核通过后再执行退款（当前状态：%s）。', 'qilingshop'),
                            $current_status_text
                        );
                    }

                    add_settings_error('qls_shop_orders', 'refund_not_ready', $message, 'error');
                    return;
                }

                $result = qls_shop_refund()->confirm_refund(
                    (int) $refund->id,
                    get_current_user_id(),
                    __('后台订单列表确认退款', 'qilingshop')
                );
                if (is_wp_error($result)) {
                    add_settings_error('qls_shop_orders', 'refund_error', $this->format_refund_admin_error($result, $refund, $order), 'error');
                    return;
                }
                $latest_refund = qls_shop_refund()->get((int) $refund->id);
                $refund_meta = $this->build_refund_admin_meta($latest_refund ?: $refund, $order);
                add_settings_error(
                    'qls_shop_orders',
                    'refund_success',
                    $refund_meta['effective_mode'] === 'gateway'
                        ? __('退款成功，已按原支付方式退回', 'qilingshop')
                        : __('退款成功，已退回可提现余额', 'qilingshop'),
                    'success'
                );
                return;
            case 'delete': // 增加单个删除支持
                $result = qls_shop_order()->delete($order_id);
                $success_message = __('订单删除成功', 'qilingshop');
                $error_message = __('订单删除失败', 'qilingshop');
                break;
            default:
                add_settings_error('qls_shop_orders', 'invalid_action', __('未知订单操作', 'qilingshop'), 'error');
                return;
        }

        if ($result === true) {
            add_settings_error('qls_shop_orders', 'order_action_success', $success_message, 'success');
            return;
        }

        if (is_wp_error($result)) {
            add_settings_error('qls_shop_orders', 'order_action_error', $result->get_error_message(), 'error');
            return;
        }

        add_settings_error('qls_shop_orders', 'order_action_error', $error_message, 'error');
    }

    /**
     * 标准化后台发货表单里的商品数量。
     *
     * @param mixed $raw_items
     * @return array
     */
    private function normalize_shipment_items_from_request($raw_items) {
        $items = [];
        foreach ((array) $raw_items as $order_item_id => $quantity) {
            $order_item_id = absint($order_item_id);
            $quantity = absint($quantity);
            if ($order_item_id > 0 && $quantity > 0) {
                $items[$order_item_id] = $quantity;
            }
        }

        return $items;
    }

    private function handle_bulk_ship_orders($raw_rows) {
        $raw_rows = trim((string) $raw_rows);
        if ($raw_rows === '') {
            return [
                'success' => 0,
                'errors'  => [__('请先粘贴订单号、物流公司和快递单号。', 'qilingshop')],
            ];
        }

        $success = 0;
        $errors = [];
        $lines = preg_split('/\r\n|\r|\n/', $raw_rows);

        foreach ($lines as $index => $line) {
            $line_no = $index + 1;
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
            if ($index === 0 && preg_match('/订单号|订单编号/u', $line) && preg_match('/物流|快递/u', $line)) {
                continue;
            }

            $parts = preg_split('/[\t,，]+/', $line, 3);
            $parts = array_map('trim', $parts);

            if (count($parts) < 3) {
                $errors[] = sprintf(__('第 %d 行：格式不完整，请按“订单号,物流公司,快递单号”填写。', 'qilingshop'), $line_no);
                continue;
            }

            $order_no = sanitize_text_field(ltrim($parts[0], "# \t"));
            $shipping_company = sanitize_text_field($parts[1]);
            $tracking_no = sanitize_text_field($parts[2]);

            if ($order_no === '' || $shipping_company === '' || $tracking_no === '') {
                $errors[] = sprintf(__('第 %d 行：订单号、物流公司和快递单号都不能为空。', 'qilingshop'), $line_no);
                continue;
            }

            if (function_exists('qls_shipping_company')) {
                $company_record = qls_shipping_company()->find($shipping_company);
                if (!$company_record || (int) ($company_record->status ?? 0) !== 1) {
                    $errors[] = sprintf(__('第 %1$d 行：物流公司“%2$s”不存在或已停用。', 'qilingshop'), $line_no, $shipping_company);
                    continue;
                }
                $shipping_company = (string) $company_record->name;
            }

            $order = $this->get_latest_order_by_no($order_no);
            if (!$order) {
                $errors[] = sprintf(__('第 %1$d 行：订单 %2$s 不存在。', 'qilingshop'), $line_no, $order_no);
                continue;
            }

            if ((int) $order->status !== QLS_Shop_Order::STATUS_PAID) {
                $errors[] = sprintf(
                    __('第 %1$d 行：订单 %2$s 当前为“%3$s”，不是待发货状态。', 'qilingshop'),
                    $line_no,
                    $order_no,
                    qls_shop_order()->get_status_text($order->status)
                );
                continue;
            }

            if (!$this->order_has_physical_items($order)) {
                $errors[] = sprintf(__('第 %1$d 行：订单 %2$s 不需要物流发货。', 'qilingshop'), $line_no, $order_no);
                continue;
            }

            $result = qls_shop_order()->ship((int) $order->id, $shipping_company, $tracking_no);
            if ($result === true) {
                $success++;
                continue;
            }

            if (is_wp_error($result)) {
                $errors[] = sprintf(__('第 %1$d 行：订单 %2$s 发货失败，%3$s', 'qilingshop'), $line_no, $order_no, $result->get_error_message());
                continue;
            }

            $errors[] = sprintf(__('第 %1$d 行：订单 %2$s 发货失败，请检查订单状态。', 'qilingshop'), $line_no, $order_no);
        }

        return [
            'success' => $success,
            'errors'  => $errors,
        ];
    }

    private function get_latest_order_by_no($order_no) {
        $db = QLS_Shop_Database::instance();
        $wpdb = $db->get_wpdb();
        $table_orders = $db->get_table('orders');

        $order = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_orders} WHERE order_no = %s ORDER BY id DESC LIMIT 1",
                $order_no
            )
        );

        if (!$order) {
            return null;
        }

        $order->items = qls_shop_order()->get_items((int) $order->id);
        return $order;
    }

    private function order_has_physical_items($order) {
        $items = isset($order->items) && is_array($order->items) ? $order->items : qls_shop_order()->get_items((int) $order->id);
        if (empty($items) || !function_exists('qls_product')) {
            return true;
        }

        foreach ($items as $item) {
            $product_id = isset($item->product_id) ? (int) $item->product_id : 0;
            if ($product_id <= 0 || !qls_product()->is_virtual($product_id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 渲染营销中心。
     */
    public function render_marketing_center() {
        $tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : 'overview';
        $marketing_tabs = $this->get_marketing_center_tabs();
        if (!isset($marketing_tabs[$tab])) {
            $tab = 'overview';
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qls_marketing_nonce'])) {
            $this->handle_marketing_settings_save($_POST, $tab);
        }

        if ($tab === 'coupons') {
            extract($this->prepare_coupon_admin_context('qls-shop-marketing', ['tab' => 'coupons'], true));
        }

        $marketing_stats = $this->get_marketing_center_stats();
        $new_user_products = [];
        $birthday_coupon_choices = [];

        if ($tab === 'new_user' || $tab === 'overview') {
            $new_user_products = qls_product()->get_list([
                'new_user_special' => 1,
                'limit'            => 10,
                'offset'           => 0,
            ]);
        }

        if ($tab === 'birthday') {
            $birthday_coupon_choices = $this->get_marketing_coupon_choices();
        }

        include QILINGSHOP_PATH . 'admin/shop/views/marketing.php';
    }

    /**
     * 渲染优惠券管理页面。
     */
    public function render_coupons() {
        $this->render_coupon_admin_content('qls-shop-coupons', [], false);
    }

    /**
     * 营销中心标签页定义。
     *
     * @return array<string,string>
     */
    private function get_marketing_center_tabs() {
        return [
            'overview' => __('营销总览', 'qilingshop'),
            'coupons'  => __('优惠券', 'qilingshop'),
            'new_user' => __('新人专区', 'qilingshop'),
            'birthday' => __('生日券', 'qilingshop'),
            'pages'    => __('营销页面', 'qilingshop'),
        ];
    }

    /**
     * 处理营销中心设置保存。
     *
     * @param array  $data 表单数据。
     * @param string $fallback_tab 当前标签页。
     * @return void
     */
    private function handle_marketing_settings_save($data, $fallback_tab = 'overview') {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!wp_verify_nonce($data['qls_marketing_nonce'] ?? '', 'qls_save_marketing_settings')) {
            return;
        }

        $action = isset($data['marketing_action']) ? sanitize_key((string) wp_unslash($data['marketing_action'])) : '';
        $redirect_tab = sanitize_key((string) $fallback_tab);

        if ($action === 'save_birthday_coupon') {
            update_option('qilingshop_birthday_coupon_enabled', !empty($data['birthday_coupon_enabled']) ? 1 : 0);
            update_option('qilingshop_birthday_coupon_id', absint($data['birthday_coupon_id'] ?? 0));
            $redirect_tab = 'birthday';
        } elseif ($action === 'save_marketing_pages') {
            update_option('qls_shop_page_coupon_center', absint($data['page_coupon_center'] ?? 0));
            update_option('qls_shop_page_new_user_zone', absint($data['page_new_user_zone'] ?? 0));
            update_option('qls_shop_flush_rewrite', true);
            $redirect_tab = 'pages';
        }

        $this->redirect_admin_page(add_query_arg([
            'page'    => 'qls-shop-marketing',
            'tab'     => $redirect_tab,
            'message' => 'saved',
        ], admin_url('admin.php')));
    }

    /**
     * 获取营销中心统计。
     *
     * @return array<string,mixed>
     */
    private function get_marketing_center_stats() {
        if (!class_exists('QLS_Coupon')) {
            require_once QILINGSHOP_PATH . 'includes/shop/class-qls-coupon.php';
        }

        $coupon_manager = QLS_Coupon::instance();

        $coupon_status_counts = $coupon_manager->get_status_counts();

        return [
            'coupon_total'        => (int) array_sum($coupon_status_counts),
            'coupon_active'       => (int) ($coupon_status_counts[1] ?? 0),
            'new_user_products'   => qls_product()->get_count(['new_user_special' => 1]),
            'birthday_enabled'    => (bool) get_option('qilingshop_birthday_coupon_enabled', false),
            'birthday_coupon_id'  => absint(get_option('qilingshop_birthday_coupon_id', 0)),
            'coupon_page_id'      => absint(get_option('qls_shop_page_coupon_center', 0)),
            'new_user_page_id'    => absint(get_option('qls_shop_page_new_user_zone', 0)),
        ];
    }

    /**
     * 获取生日券候选优惠券。
     *
     * @return array
     */
    private function get_marketing_coupon_choices() {
        if (!class_exists('QLS_Coupon')) {
            require_once QILINGSHOP_PATH . 'includes/shop/class-qls-coupon.php';
        }

        $coupon_manager = QLS_Coupon::instance();
        $coupons = $coupon_manager->get_list([
            'status' => 1,
            'limit'  => 200,
            'offset' => 0,
        ]);

        $birthday_coupon_id = absint(get_option('qilingshop_birthday_coupon_id', 0));
        if ($birthday_coupon_id > 0) {
            $found = false;
            foreach ($coupons as $coupon) {
                if ((int) $coupon->id === $birthday_coupon_id) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $current_coupon = $coupon_manager->get($birthday_coupon_id);
                if ($current_coupon) {
                    array_unshift($coupons, $current_coupon);
                }
            }
        }

        return $coupons;
    }

    /**
     * 渲染优惠券后台内容。
     *
     * @param string $page_slug 页面 slug。
     * @param array  $base_args URL 基础参数。
     * @param bool   $embedded 是否嵌入其他页面。
     * @return void
     */
    private function render_coupon_admin_content($page_slug = 'qls-shop-coupons', $base_args = [], $embedded = false) {
        extract($this->prepare_coupon_admin_context($page_slug, $base_args, $embedded));
        include QILINGSHOP_PATH . 'admin/shop/views/coupons.php';
    }

    /**
     * 准备优惠券后台页面上下文。
     *
     * @param string $page_slug 页面 slug。
     * @param array  $base_args URL 基础参数。
     * @param bool   $embedded 是否嵌入营销中心。
     * @return array<string,mixed>
     */
    private function prepare_coupon_admin_context($page_slug = 'qls-shop-coupons', $base_args = [], $embedded = false) {
        if (!class_exists('QLS_Coupon')) {
            require_once QILINGSHOP_PATH . 'includes/shop/class-qls-coupon.php';
        }

        $coupon_manager = QLS_Coupon::instance();
        $page_slug = sanitize_key((string) $page_slug);
        $clean_base_args = [];
        if (is_array($base_args)) {
            foreach ($base_args as $base_key => $base_value) {
                $base_key = sanitize_key((string) $base_key);
                if ($base_key === '') {
                    continue;
                }
                $clean_base_args[$base_key] = sanitize_key((string) $base_value);
            }
        }
        $base_args = $clean_base_args;
        $base_url = add_query_arg(array_merge(['page' => $page_slug], $base_args), admin_url('admin.php'));

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qls_coupon_nonce'])) {
            if (current_user_can('manage_options') && wp_verify_nonce($_POST['qls_coupon_nonce'], 'qls_save_coupon')) {
                $this->save_coupon($_POST);
                $this->redirect_admin_page(add_query_arg('message', 'saved', $base_url));
            }
        }

        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
            $coupon_id_for_delete = absint($_GET['id']);
            if ($coupon_id_for_delete > 0 && current_user_can('manage_options') && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'delete_coupon_' . $coupon_id_for_delete)) {
                $coupon_manager->delete($coupon_id_for_delete);
                $this->redirect_admin_page(add_query_arg('message', 'deleted', $base_url));
            }
        }

        $view = isset($_GET['view']) ? sanitize_key((string) wp_unslash($_GET['view'])) : 'list';
        if (!in_array($view, ['list', 'add', 'edit'], true)) {
            $view = 'list';
        }

        $coupon_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        $coupon = null;
        if ($view === 'edit' && $coupon_id > 0) {
            $coupon = $coupon_manager->get($coupon_id);
        }

        $status = null;
        if (isset($_GET['status']) && $_GET['status'] !== '') {
            $status = sanitize_key((string) wp_unslash($_GET['status']));
            $status = in_array($status, ['0', '1'], true) ? $status : null;
        }

        $args = [
            'status'      => $status,
            'apply_scope' => isset($_GET['scope']) ? sanitize_key((string) wp_unslash($_GET['scope'])) : '',
            'search'      => isset($_GET['s']) ? sanitize_text_field((string) wp_unslash($_GET['s'])) : '',
            'limit'       => 20,
            'offset'      => isset($_GET['paged']) ? (max(1, absint($_GET['paged'])) - 1) * 20 : 0,
        ];

        $coupons = $coupon_manager->get_list($args);
        $coupon_status_counts = $coupon_manager->get_status_counts([
            'apply_scope' => $args['apply_scope'],
            'search'      => $args['search'],
        ]);
        $coupon_all_count = (int) array_sum($coupon_status_counts);
        $total = $status === null
            ? $coupon_all_count
            : (int) ($coupon_status_counts[(int) $status] ?? 0);

        return [
            'view'             => $view,
            'coupon_id'        => $coupon_id,
            'coupon'           => $coupon,
            'args'             => $args,
            'coupons'          => $coupons,
            'total'            => $total,
            'coupon_all_count' => $coupon_all_count,
            'coupon_status_counts' => $coupon_status_counts,
            'coupon_page_slug' => $page_slug,
            'coupon_base_args' => $base_args,
            'coupon_base_url'  => $base_url,
            'coupon_embedded'  => (bool) $embedded,
        ];
    }

    /**
     * 保存优惠券
     */
    private function save_coupon($data) {
        if (!class_exists('QLS_Coupon')) {
            require_once QILINGSHOP_PATH . 'includes/shop/class-qls-coupon.php';
        }

        $coupon_manager = QLS_Coupon::instance();

        $coupon_data = [
            'code' => sanitize_text_field($data['code'] ?? '') ?: $coupon_manager->generate_code(),
            'name' => sanitize_text_field($data['name']),
            'description' => sanitize_textarea_field($data['description'] ?? ''),
            'discount_type' => in_array($data['discount_type'] ?? '', ['fixed', 'percent']) ? $data['discount_type'] : 'fixed',
            'discount_value' => floatval($data['discount_value'] ?? 0),
            'max_discount' => floatval($data['max_discount'] ?? 0) ?: null,
            'apply_scope' => in_array($data['apply_scope'] ?? '', ['all', 'resource', 'recharge', 'vip', 'shop']) ? $data['apply_scope'] : 'all',
            'apply_items' => !empty($data['apply_items']) ? array_map('intval', explode(',', $data['apply_items'])) : null,
            'apply_categories' => null,
            'allowed_vip_levels' => !empty($data['allowed_vip_levels']) ? array_map('intval', (array) $data['allowed_vip_levels']) : null,
            'use_vip_levels' => !empty($data['use_vip_levels']) ? array_map('intval', (array) $data['use_vip_levels']) : null,
            'stack_with_vip' => isset($data['stack_with_vip']) ? 0 : 1,
            'min_amount' => floatval($data['min_amount'] ?? 0),
            'total_count' => intval($data['total_count'] ?? -1),
            'per_user_limit' => intval($data['per_user_limit'] ?? 1),
            'first_order_only' => isset($data['first_order_only']) ? 1 : 0,
            'first_order_scope' => in_array($data['first_order_scope'] ?? '', ['same_scope', 'all'], true) ? $data['first_order_scope'] : 'same_scope',
            'valid_type' => in_array($data['valid_type'] ?? '', ['fixed', 'days']) ? $data['valid_type'] : 'fixed',
            'valid_days' => intval($data['valid_days'] ?? 0) ?: null,
            'start_time' => !empty($data['start_time']) ? sanitize_text_field($data['start_time']) : null,
            'end_time' => !empty($data['end_time']) ? sanitize_text_field($data['end_time']) : null,
            'claim_type' => in_array($data['claim_type'] ?? '', ['public', 'login', 'vip']) ? $data['claim_type'] : 'public',
            'min_vip_level' => intval($data['min_vip_level'] ?? 0),
            'status' => isset($data['status']) ? 1 : 0,
            'is_visible' => isset($data['is_visible']) ? 1 : 0,
            'sort_order' => intval($data['sort_order'] ?? 0),
        ];

        if ($coupon_data['first_order_only']) {
            $coupon_data['per_user_limit'] = 1;
        }

        if ($coupon_data['apply_scope'] === 'resource') {
            $coupon_data['apply_categories'] = !empty($data['apply_categories_resource']) ? array_map('intval', (array) $data['apply_categories_resource']) : null;
        } elseif ($coupon_data['apply_scope'] === 'shop') {
            $coupon_data['apply_categories'] = !empty($data['apply_categories_shop']) ? array_map('intval', (array) $data['apply_categories_shop']) : null;
        }

        $coupon_id = intval($data['coupon_id'] ?? 0);

        if ($coupon_id > 0) {
            $coupon_manager->update($coupon_id, $coupon_data);
        } else {
            $coupon_manager->create($coupon_data);
        }
    }


    
    /**
     * 渲染评价管理页面
     */
    public function render_reviews() {
        if (!current_user_can('manage_options')) {
            wp_die(__('权限不足', 'qilingshop'));
        }

        // 处理操作
        if (isset($_GET['action']) && isset($_GET['id'])) {
            $action = sanitize_key($_GET['action']);
            $id = intval($_GET['id']);
            
            if (wp_verify_nonce($_GET['_wpnonce'] ?? '', 'review_action_' . $id)) {
                $redirect_url = admin_url('admin.php?page=qls-shop-reviews');
                
                switch ($action) {
                    case 'approve':
                        qls_review()->approve($id);
                        $redirect_url = add_query_arg('message', 'approved', $redirect_url);
                        break;
                    case 'hide':
                        qls_review()->hide($id);
                        $redirect_url = add_query_arg('message', 'hidden', $redirect_url);
                        break;
                    case 'delete':
                        qls_review()->delete($id);
                        $redirect_url = add_query_arg('message', 'deleted', $redirect_url);
                        break;
                    case 'top':
                        qls_review()->toggle_top($id);
                        $redirect_url = add_query_arg('message', 'updated', $redirect_url);
                        break;
                }
                
                wp_safe_redirect($redirect_url);
                exit;
            }
        }

        // 处理回复
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_reply_nonce'])) {
            if (wp_verify_nonce($_POST['review_reply_nonce'], 'review_reply')) {
                $review_id = intval($_POST['review_id']);
                $reply_content = sanitize_textarea_field($_POST['admin_reply']);
                qls_review()->reply($review_id, $reply_content);
                wp_safe_redirect(add_query_arg('message', 'replied', admin_url('admin.php?page=qls-shop-reviews')));
                exit;
            }
        }

        // 列表筛选参数
        $args = [
            'status'     => isset($_GET['status']) && $_GET['status'] !== '' ? intval($_GET['status']) : null,
            'product_id' => isset($_GET['product_id']) ? intval($_GET['product_id']) : null,
            'rating'     => isset($_GET['rating']) ? intval($_GET['rating']) : null,
            'search'     => isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '',
            'page'       => isset($_GET['paged']) ? intval($_GET['paged']) : 1,
            'per_page'   => 20,
        ];

        $result = qls_review()->get_admin_list($args);
        $reviews = $result['items'];
        $total = $result['total'];
        $total_pages = $result['total_pages'];

        // 统计
        $stats = [
            'pending'  => $this->count_reviews_by_status(0),
            'approved' => $this->count_reviews_by_status(1),
            'hidden'   => $this->count_reviews_by_status(2),
        ];

        include QILINGSHOP_PATH . 'admin/shop/views/reviews.php';
    }

    /**
     * 统计评价数量
     */
    private function count_reviews_by_status($status) {
        global $wpdb;
        $table = $wpdb->prefix . 'qls_shop_reviews';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE status = %d",
            $status
        ));
    }

    
    /**
     * 获取可用模块列表
     */
    private function get_available_modules() {
        // 模块列表保持显式注册，便于控制加载顺序。
        require_once QILINGSHOP_PATH . 'includes/shop/modules/class-qls-module-product-list.php';
        require_once QILINGSHOP_PATH . 'includes/shop/modules/class-qls-module-hero-carousel.php';
        require_once QILINGSHOP_PATH . 'includes/shop/modules/class-qls-module-category-nav.php';
        require_once QILINGSHOP_PATH . 'includes/shop/modules/class-qls-module-coupon.php';
        require_once QILINGSHOP_PATH . 'includes/shop/modules/class-qls-module-group.php';
        require_once QILINGSHOP_PATH . 'includes/shop/modules/class-qls-module-assist.php';
        require_once QILINGSHOP_PATH . 'includes/shop/modules/class-qls-module-new-user-zone.php';
        
        $product_list = new QLS_Module_Product_List();
        $hero_carousel = new QLS_Module_Hero_Carousel();
        $category_nav = new QLS_Module_Category_Nav();
        $coupon = new QLS_Module_Coupon();
        $group = new QLS_Module_Group();
        $assist = new QLS_Module_Assist();
        $new_user_zone = new QLS_Module_New_User_Zone();
        
        return [
            'hero_carousel' => [
                'id'       => 'hero_carousel',
                'name'     => __('首屏轮播', 'qilingshop'),
                'icon'     => 'dashicons-images-alt2',
                'defaults' => $hero_carousel->get_defaults(),
                'fields'   => $hero_carousel->get_settings_fields()
            ],
            'category_nav' => [
                'id'       => 'category_nav',
                'name'     => __('商品分类导航', 'qilingshop'),
                'icon'     => 'dashicons-category',
                'defaults' => $category_nav->get_defaults(),
                'fields'   => $category_nav->get_settings_fields()
            ],
            'group' => [
                'id'       => 'group',
                'name'     => __('拼团专区', 'qilingshop'),
                'icon'     => 'dashicons-groups',
                'defaults' => $group->get_defaults(),
                'fields'   => $group->get_settings_fields()
            ],
            'assist' => [
                'id'       => 'assist',
                'name'     => __('好友助力专区', 'qilingshop'),
                'icon'     => 'dashicons-megaphone',
                'defaults' => $assist->get_defaults(),
                'fields'   => $assist->get_settings_fields()
            ],
            'new_user_zone' => [
                'id'       => 'new_user_zone',
                'name'     => __('新人专区', 'qilingshop'),
                'icon'     => 'dashicons-star-filled',
                'defaults' => $new_user_zone->get_defaults(),
                'fields'   => $new_user_zone->get_settings_fields()
            ],
            'coupon' => [
                'id'       => 'coupon',
                'name'     => __('优惠券领取', 'qilingshop'),
                'icon'     => 'dashicons-tickets-alt',
                'defaults' => $coupon->get_defaults(),
                'fields'   => $coupon->get_settings_fields()
            ],
            'product_list' => [
                'id'       => 'product_list',
                'name'     => __('商品列表', 'qilingshop'),
                'icon'     => 'dashicons-grid-view',
                'defaults' => $product_list->get_defaults(), 
                'fields'   => $product_list->get_settings_fields() 
            ]
        ];
    }

    /**
     * 渲染商城设置
     */
    public function render_settings() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('权限不足', 'qilingshop'));
        }

        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general';
        $this->handle_settings_post($tab);

        include QILINGSHOP_PATH . 'admin/shop/views/settings.php';
    }

    /**
     * 处理商城设置页 POST 写操作。
     *
     * @param string $tab 当前标签页。
     * @return void
     */
    private function handle_settings_post($tab) {
        $request_method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) wp_unslash($_SERVER['REQUEST_METHOD'])) : 'GET';
        if ($request_method !== 'POST') {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('权限不足', 'qilingshop'));
        }

        $data = isset($_POST) && is_array($_POST) ? wp_unslash($_POST) : [];

        if (isset($data['qls_shop_settings_nonce'])) {
            $redirect_tab = $this->resolve_settings_tab($data, $tab);
            if (!$this->verify_settings_post_nonce($data, 'qls_save_shop_settings', 'qls_shop_settings_nonce')) {
                $this->redirect_settings_page($redirect_tab, 'invalid_nonce');
            }

            $this->redirect_settings_page($redirect_tab, $this->save_settings($data) ? 'saved' : 'save_failed');
        }

        $handlers = [
            'shipping_action'         => ['tab' => 'shipping', 'callback' => 'handle_settings_shipping_action'],
            'service_action'          => ['tab' => 'service', 'callback' => 'handle_settings_service_action'],
            'param_action'            => ['tab' => 'params', 'callback' => 'handle_settings_param_action'],
            'express_action'          => ['tab' => 'express', 'callback' => 'handle_settings_express_action'],
            'express_config_action'   => ['tab' => 'express', 'callback' => 'handle_settings_express_config_action'],
            'waybill_config_action'   => ['tab' => 'express', 'callback' => 'handle_settings_waybill_config_action'],
            'waybill_template_action' => ['tab' => 'express', 'callback' => 'handle_settings_waybill_template_action'],
            'review_settings_action'  => ['tab' => 'review', 'callback' => 'handle_settings_review_action'],
            'service_showcase_action' => ['tab' => 'service_showcase', 'callback' => 'handle_settings_service_showcase_action'],
        ];

        foreach ($handlers as $action_key => $handler) {
            if (!isset($data[$action_key])) {
                continue;
            }

            $callback = $handler['callback'];
            $message = method_exists($this, $callback) ? $this->$callback($data) : 'invalid_action';
            $this->redirect_settings_page($handler['tab'], $message);
        }

        $this->redirect_settings_page($tab, 'invalid_action');
    }

    /**
     * 获取设置页提交的标量值，避免异常数组输入触发类型警告。
     *
     * @param array  $data    已反斜杠处理的提交数据。
     * @param string $key     字段名。
     * @param string $default 默认值。
     * @return string
     */
    private function get_settings_post_scalar($data, $key, $default = '') {
        if (!isset($data[$key]) || is_array($data[$key]) || is_object($data[$key])) {
            return (string) $default;
        }

        return (string) $data[$key];
    }

    /**
     * 校验设置页表单 nonce。
     *
     * @param array  $data         已反斜杠处理的提交数据。
     * @param string $nonce_action Nonce action。
     * @param string $nonce_field  Nonce 字段名。
     * @return bool
     */
    private function verify_settings_post_nonce($data, $nonce_action, $nonce_field = '_wpnonce') {
        if (!isset($data[$nonce_field])) {
            return false;
        }

        $nonce = sanitize_text_field($this->get_settings_post_scalar($data, $nonce_field));
        return (bool) wp_verify_nonce($nonce, $nonce_action);
    }

    /**
     * 跳回商城设置页。
     *
     * @param string $tab     标签页。
     * @param string $message 消息标识。
     * @return void
     */
    private function redirect_settings_page($tab, $message) {
        $redirect_url = add_query_arg([
            'page'    => 'qls-shop-settings',
            'tab'     => sanitize_key((string) $tab),
            'message' => sanitize_key((string) $message),
        ], admin_url('admin.php'));

        $this->redirect_admin_page($redirect_url);
    }

    /**
     * 保存/删除运费规则。
     *
     * @param array $data 已反斜杠处理的提交数据。
     * @return string
     */
    private function handle_settings_shipping_action($data) {
        if (!$this->verify_settings_post_nonce($data, 'qls_shipping_action')) {
            return 'invalid_nonce';
        }
        if (!function_exists('qls_shipping')) {
            return 'service_unavailable';
        }

        $action = sanitize_key($this->get_settings_post_scalar($data, 'shipping_action'));
        if ($action === 'save') {
            $rule_data = [
                'name'           => sanitize_text_field((string) ($data['rule_name'] ?? '')),
                'type'           => intval($data['rule_type'] ?? 0),
                'base_fee'       => floatval($data['base_fee'] ?? 0),
                'free_threshold' => isset($data['free_threshold']) && $data['free_threshold'] !== '' ? floatval($data['free_threshold']) : null,
                'weight_step'    => isset($data['weight_step']) && $data['weight_step'] !== '' ? floatval($data['weight_step']) : null,
                'step_fee'       => isset($data['step_fee']) && $data['step_fee'] !== '' ? floatval($data['step_fee']) : null,
                'is_default'     => !empty($data['is_default']) ? 1 : 0,
                'status'         => !empty($data['rule_status']) ? 1 : 0,
            ];

            $rule_id = isset($data['rule_id']) ? absint($data['rule_id']) : 0;
            if ($rule_id > 0) {
                $rule_data['id'] = $rule_id;
            }

            return qls_shipping()->save_rule($rule_data) !== false ? 'saved' : 'save_failed';
        }

        if ($action === 'delete') {
            $rule_id = isset($data['rule_id']) ? absint($data['rule_id']) : 0;
            if ($rule_id <= 0) {
                return 'missing_id';
            }

            return qls_shipping()->delete_rule($rule_id) ? 'deleted' : 'delete_failed';
        }

        return 'invalid_action';
    }

    /**
     * 保存/删除服务标签。
     *
     * @param array $data 已反斜杠处理的提交数据。
     * @return string
     */
    private function handle_settings_service_action($data) {
        if (!$this->verify_settings_post_nonce($data, 'qls_service_action')) {
            return 'invalid_nonce';
        }

        $db = QLS_Shop_Database::instance();
        $action = sanitize_key($this->get_settings_post_scalar($data, 'service_action'));

        if ($action === 'save') {
            $tag_data = [
                'name'       => sanitize_text_field((string) ($data['tag_name'] ?? '')),
                'icon'       => qilingshop_sanitize_icon_value((string) ($data['tag_icon'] ?? '')),
                'sort_order' => intval($data['sort_order'] ?? 0),
                'is_default' => !empty($data['tag_default']) ? 1 : 0,
                'status'     => !empty($data['tag_status']) ? 1 : 0,
            ];

            $tag_id = isset($data['tag_id']) ? absint($data['tag_id']) : 0;
            $result = $tag_id > 0
                ? $db->update('service_tags', $tag_data, ['id' => $tag_id])
                : $db->insert('service_tags', $tag_data);

            return $result !== false ? 'saved' : 'save_failed';
        }

        if ($action === 'delete') {
            $tag_id = isset($data['tag_id']) ? absint($data['tag_id']) : 0;
            if ($tag_id <= 0) {
                return 'missing_id';
            }

            return $db->delete('service_tags', ['id' => $tag_id]) !== false ? 'deleted' : 'delete_failed';
        }

        return 'invalid_action';
    }

    /**
     * 保存/删除商品参数模板。
     *
     * @param array $data 已反斜杠处理的提交数据。
     * @return string
     */
    private function handle_settings_param_action($data) {
        if (!$this->verify_settings_post_nonce($data, 'qls_param_action')) {
            return 'invalid_nonce';
        }

        $db = QLS_Shop_Database::instance();
        $action = sanitize_key($this->get_settings_post_scalar($data, 'param_action'));

        if ($action === 'save') {
            $param_data = [
                'name'       => sanitize_text_field((string) ($data['param_name'] ?? '')),
                'sort_order' => intval($data['sort_order'] ?? 0),
                'status'     => !empty($data['param_status']) ? 1 : 0,
            ];

            $param_id = isset($data['param_id']) ? absint($data['param_id']) : 0;
            $result = $param_id > 0
                ? $db->update('product_params', $param_data, ['id' => $param_id])
                : $db->insert('product_params', $param_data);

            return $result !== false ? 'saved' : 'save_failed';
        }

        if ($action === 'delete') {
            $param_id = isset($data['param_id']) ? absint($data['param_id']) : 0;
            if ($param_id <= 0) {
                return 'missing_id';
            }

            return $db->delete('product_params', ['id' => $param_id]) !== false ? 'deleted' : 'delete_failed';
        }

        return 'invalid_action';
    }

    /**
     * 保存/删除快递公司。
     *
     * @param array $data 已反斜杠处理的提交数据。
     * @return string
     */
    private function handle_settings_express_action($data) {
        if (!$this->verify_settings_post_nonce($data, 'qls_express_action')) {
            return 'invalid_nonce';
        }
        if (!function_exists('qls_shipping_company')) {
            return 'service_unavailable';
        }

        $service = qls_shipping_company();
        if (!$service) {
            return 'service_unavailable';
        }

        $action = sanitize_key($this->get_settings_post_scalar($data, 'express_action'));
        $company_id = isset($data['company_id']) ? absint($data['company_id']) : 0;

        if ($action === 'save') {
            $aliases_raw = sanitize_textarea_field((string) ($data['company_aliases'] ?? ''));
            $aliases = preg_split('/\r\n|\r|\n|,|，/', $aliases_raw);
            $aliases = array_values(array_filter(array_map('trim', (array) $aliases)));

            $company_data = [
                'name'           => sanitize_text_field((string) ($data['company_name'] ?? '')),
                'code'           => sanitize_text_field((string) ($data['company_code'] ?? '')),
                'aliases'        => $aliases,
                'phone_required' => !empty($data['phone_required']) ? 1 : 0,
                'is_default'     => !empty($data['is_default']) ? 1 : 0,
                'status'         => isset($data['status']) ? absint($data['status']) : 1,
                'sort_order'     => isset($data['sort_order']) ? intval($data['sort_order']) : 0,
            ];

            $result = $company_id > 0
                ? $service->update($company_id, $company_data)
                : $service->create($company_data);

            return (!is_wp_error($result) && $result !== false) ? 'saved' : 'save_failed';
        }

        if ($action === 'delete') {
            if ($company_id <= 0) {
                return 'missing_id';
            }

            return $service->delete($company_id) ? 'deleted' : 'delete_failed';
        }

        return 'invalid_action';
    }

    /**
     * 保存物流接口配置。
     *
     * @param array $data 已反斜杠处理的提交数据。
     * @return string
     */
    private function handle_settings_express_config_action($data) {
        if (!$this->verify_settings_post_nonce($data, 'qls_express_config')) {
            return 'invalid_nonce';
        }

        $api_key = sanitize_text_field((string) ($data['api_key'] ?? ''));
        if ($api_key !== '' && !preg_match('/^ip_live_[A-Za-z0-9]{32}$/', $api_key)) {
            return 'save_failed';
        }
        update_option('qls_shop_express_api_key', $api_key);
        return 'saved';
    }

    /**
     * 保存电子面单配置。
     *
     * @param array $data 已反斜杠处理的提交数据。
     * @return string
     */
    private function handle_settings_waybill_config_action($data) {
        if (!$this->verify_settings_post_nonce($data, 'qls_waybill_config')) {
            return 'invalid_nonce';
        }

        update_option('qls_shop_waybill_enabled', !empty($data['waybill_enabled']) ? 1 : 0);
        update_option('qls_shop_waybill_auto_generate', !empty($data['waybill_auto_generate']) ? 1 : 0);
        update_option('qls_shop_waybill_appcode', sanitize_text_field((string) ($data['waybill_appcode'] ?? '')));
        update_option('qls_shop_waybill_provider', QLS_Waybill::PROVIDER_KDNIAO);
        return 'saved';
    }

    /**
     * 保存/删除电子面单模板。
     *
     * @param array $data 已反斜杠处理的提交数据。
     * @return string
     */
    private function handle_settings_waybill_template_action($data) {
        if (!$this->verify_settings_post_nonce($data, 'qls_waybill_template')) {
            return 'invalid_nonce';
        }
        if (!function_exists('qls_waybill')) {
            return 'service_unavailable';
        }

        $service = qls_waybill();
        if (!$service) {
            return 'service_unavailable';
        }

        $action = sanitize_key($this->get_settings_post_scalar($data, 'waybill_template_action'));
        $template_id = isset($data['template_id']) ? absint($data['template_id']) : 0;

        if ($action === 'save') {
            $template_data = [
                'id'              => $template_id,
                'name'            => sanitize_text_field((string) ($data['template_name'] ?? '')),
                'provider'        => QLS_Waybill::PROVIDER_KDNIAO,
                'company_id'      => isset($data['template_company_id']) ? absint($data['template_company_id']) : 0,
                'sender_name'     => sanitize_text_field((string) ($data['sender_name'] ?? '')),
                'sender_phone'    => sanitize_text_field((string) ($data['sender_phone'] ?? '')),
                'sender_province' => sanitize_text_field((string) ($data['sender_province'] ?? '')),
                'sender_city'     => sanitize_text_field((string) ($data['sender_city'] ?? '')),
                'sender_district' => sanitize_text_field((string) ($data['sender_district'] ?? '')),
                'sender_address'  => sanitize_textarea_field((string) ($data['sender_address'] ?? '')),
                'template_config' => [
                    'sheet_size' => sanitize_text_field((string) ($data['sheet_size'] ?? '100x150')),
                    'printer_no' => sanitize_text_field((string) ($data['printer_no'] ?? '')),
                    'weight'     => isset($data['weight']) ? (float) $data['weight'] : 1,
                    'print_note' => sanitize_textarea_field((string) ($data['print_note'] ?? '')),
                    'sender_company' => sanitize_text_field((string) ($data['sender_company'] ?? '')),
                    'pay_type'   => isset($data['pay_type']) ? absint($data['pay_type']) : 1,
                    'month_code' => sanitize_text_field((string) ($data['month_code'] ?? '')),
                    'exp_type'   => isset($data['exp_type']) ? absint($data['exp_type']) : 1,
                    'cost'       => isset($data['cost']) ? (float) $data['cost'] : 0,
                    'other_cost' => isset($data['other_cost']) ? (float) $data['other_cost'] : 0,
                    'volume'     => isset($data['volume']) ? (float) $data['volume'] : 0,
                ],
                'is_default'      => !empty($data['template_is_default']) ? 1 : 0,
                'status'          => isset($data['template_status']) ? absint($data['template_status']) : 1,
            ];

            $result = $service->save_template($template_data);
            return !is_wp_error($result) ? 'saved' : 'save_failed';
        }

        if ($action === 'delete') {
            if ($template_id <= 0) {
                return 'missing_id';
            }

            return $service->delete_template($template_id) ? 'deleted' : 'delete_failed';
        }

        return 'invalid_action';
    }

    /**
     * 保存评价设置。
     *
     * @param array $data 已反斜杠处理的提交数据。
     * @return string
     */
    private function handle_settings_review_action($data) {
        if (!$this->verify_settings_post_nonce($data, 'qls_review_settings')) {
            return 'invalid_nonce';
        }

        update_option('qls_shop_review_enabled', !empty($data['review_enabled']));
        update_option('qls_shop_review_auto_approve', !empty($data['review_auto_approve']));
        update_option('qls_shop_review_require_purchase', !empty($data['review_require_purchase']));
        update_option('qls_shop_review_show_on_card', !empty($data['review_show_on_card']));
        update_option('qls_shop_review_after_days', intval($data['review_after_days'] ?? 0));
        update_option('qls_shop_review_image_max', intval($data['review_image_max'] ?? 0));
        update_option('qls_shop_review_min_length', intval($data['review_min_length'] ?? 0));
        update_option('qls_shop_review_points_reward', intval($data['review_points_reward'] ?? 0));
        update_option('qls_shop_review_image_bonus', intval($data['review_image_bonus'] ?? 0));

        return 'saved';
    }

    /**
     * 保存服务展示设置。
     *
     * @param array $data 已反斜杠处理的提交数据。
     * @return string
     */
    private function handle_settings_service_showcase_action($data) {
        if (!$this->verify_settings_post_nonce($data, 'qls_service_showcase_settings')) {
            return 'invalid_nonce';
        }

        $allowed_positions = ['home_bottom', 'product_bottom'];
        $raw_positions = isset($data['service_positions']) && is_array($data['service_positions'])
            ? array_map('sanitize_key', $data['service_positions'])
            : [];
        $positions = array_values(array_unique(array_intersect($allowed_positions, $raw_positions)));
        if (empty($positions)) {
            $positions = ['home_bottom'];
        }

        $raw_items = isset($data['service_items']) && is_array($data['service_items']) ? $data['service_items'] : [];
        $items = [];
        foreach ($raw_items as $row) {
            if (!is_array($row)) {
                continue;
            }

            $icon = esc_url_raw($row['icon'] ?? '');
            $title = sanitize_text_field((string) ($row['title'] ?? ''));
            $desc = sanitize_text_field((string) ($row['desc'] ?? ''));
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

        update_option('qls_shop_service_showcase_positions', $positions);
        update_option('qls_shop_service_showcase_items', $items);

        return 'saved';
    }

    /**
     * 解析商城设置页当前标签页。
     *
     * @param array  $data     提交数据。
     * @param string $fallback 默认标签页。
     * @return string
     */
    private function resolve_settings_tab($data, $fallback = 'general') {
        $tab = '';
        if (isset($data['settings_tab'])) {
            $tab = sanitize_key((string) $data['settings_tab']);
        }

        if ($tab === '') {
            $tab = sanitize_key((string) $fallback);
        }

        return $tab !== '' ? $tab : 'general';
    }

    /**
     * 保存设置
     */
    private function save_settings($data) {
        // 页面配置Tab
        if (isset($data['settings_tab']) && $data['settings_tab'] === 'pages') {
            $page_options = [
                'qls_shop_page_shop'     => intval($data['page_shop'] ?? 0),
                'qls_shop_page_virtual_home' => intval($data['page_virtual_home'] ?? 0),
                'qls_shop_page_cart'     => intval($data['page_cart'] ?? 0),
                'qls_shop_page_checkout' => intval($data['page_checkout'] ?? 0),
                'qls_shop_page_all_products'  => intval($data['page_all_products'] ?? 0),
                'qls_shop_page_orders'   => intval($data['page_orders'] ?? 0),
                'qls_shop_page_center'   => intval($data['page_center'] ?? 0),
                'qls_shop_page_coupon_center' => intval($data['page_coupon_center'] ?? 0),
                'qls_shop_page_group_center' => intval($data['page_group_center'] ?? 0),
                'qls_shop_page_group_detail' => intval($data['page_group_detail'] ?? 0),
                'qls_shop_page_assist_center' => intval($data['page_assist_center'] ?? 0),
                'qls_shop_page_assist_detail' => intval($data['page_assist_detail'] ?? 0),
                'qls_shop_page_my_assists' => intval($data['page_my_assists'] ?? 0),
                'qls_shop_page_my_downloads' => intval($data['page_my_downloads'] ?? 0),
                'qls_shop_page_new_user_zone' => intval($data['page_new_user_zone'] ?? 0),
                'qls_shop_page_order_query' => intval($data['page_order_query'] ?? 0),
            ];
            
            foreach ($page_options as $key => $value) {
                update_option($key, $value);
            }

            // 与资源侧既有配置项保持同步，避免支付回跳读取不到订单查询页
            update_option('qilingshop_page_order_query', (int) ($page_options['qls_shop_page_order_query'] ?? 0));

            $all_products_id = (int) ($page_options['qls_shop_page_all_products'] ?? 0);
            if ($all_products_id > 0) {
                $all_products_slug = sanitize_title((string) get_post_field('post_name', $all_products_id));
                if ($all_products_slug !== '') {
                    update_option('qls_shop_page_all_products_slug', $all_products_slug);
                } else {
                    delete_option('qls_shop_page_all_products_slug');
                }
            } else {
                delete_option('qls_shop_page_all_products_slug');
            }

            // 页面映射变化可能影响 rewrite，统一标记刷新
            update_option('qls_shop_flush_rewrite', true);
            return true;
        }
        
        // 基础设置Tab
        $guest_order_enabled = !empty($data['guest_order_enabled']);
        if (
            !array_key_exists('guest_order_enabled', (array) $data)
            && array_key_exists('cart_guest_enabled', (array) $data)
        ) {
            $guest_order_enabled = !empty($data['cart_guest_enabled']);
        }

        $home_mode = sanitize_key((string) ($data['home_mode'] ?? 'decoration'));
        if (!in_array($home_mode, ['decoration', 'virtual_card'], true)) {
            $home_mode = 'decoration';
        }

        $virtual_home_style = sanitize_key((string) ($data['virtual_home_style'] ?? 'compact'));
        $virtual_home_styles = function_exists('qilingshop_get_virtual_home_styles') ? qilingshop_get_virtual_home_styles() : [];
        if (empty($virtual_home_styles)) {
            $virtual_home_styles = [
                'compact' => ['label' => __('清爽列表', 'qilingshop')],
            ];
        }
        if (!isset($virtual_home_styles[$virtual_home_style])) {
            $style_keys = array_keys($virtual_home_styles);
            $virtual_home_style = isset($virtual_home_styles['compact']) ? 'compact' : (string) reset($style_keys);
        }

        $virtual_home_limit = max(4, min(60, (int) ($data['virtual_home_limit'] ?? 24)));
        $virtual_home_title = sanitize_text_field($data['virtual_home_title'] ?? '');
        if ($virtual_home_title === '') {
            $virtual_home_title = __('虚拟发卡', 'qilingshop');
        }
        $virtual_home_subtitle = sanitize_text_field($data['virtual_home_subtitle'] ?? '');

        $settings = [
            'qls_shop_enabled'                  => isset($data['enabled']) ? 1 : 0,
            'qls_shop_name'                     => sanitize_text_field($data['name'] ?? ''),
            'qls_shop_home_mode'                => $home_mode,
            'qls_shop_virtual_home_enabled'     => !empty($data['virtual_home_enabled']) ? 1 : 0,
            'qls_shop_virtual_home_style'       => $virtual_home_style,
            'qls_shop_virtual_home_limit'       => $virtual_home_limit,
            'qls_shop_virtual_home_title'       => $virtual_home_title,
            'qls_shop_virtual_home_subtitle'    => $virtual_home_subtitle,
            'qls_shop_header_cart_icon'         => qilingshop_sanitize_icon_value((string) ($data['header_cart_icon'] ?? '')),
            'qls_shop_header_hide_cart_icon'    => isset($data['header_hide_cart_icon']) ? 1 : 0,
            'qls_shop_show_sales'               => isset($data['show_sales']) ? 1 : 0,
            'qls_shop_show_stock'               => isset($data['show_stock']) ? 1 : 0,
            'qls_shop_price_login_required'     => isset($data['price_login_required']) ? 1 : 0,
            'qls_shop_invoice_enabled'          => isset($data['invoice_enabled']) ? 1 : 0,
            'qls_shop_product_base'             => sanitize_text_field($data['product_base'] ?? 'shop/product'),
            'qls_shop_category_base'            => sanitize_text_field($data['category_base'] ?? ''),
            'qilingshop_locale'                 => $this->sanitize_locale_setting($data['qilingshop_locale'] ?? ''),
            'qls_shop_guest_order_enabled'      => $guest_order_enabled,
            'qls_shop_cart_guest_enabled'       => $guest_order_enabled,
            'qls_shop_guest_query_password_enabled' => !empty($data['guest_query_password_enabled']),
            'qls_shop_guest_query_password_expire_days' => max(1, min(365, intval($data['guest_query_password_expire_days'] ?? 30))),
            'qls_shop_order_auto_complete_days' => intval($data['order_auto_complete_days'] ?? 15),
            'qls_shop_order_auto_cancel_hours'  => intval($data['order_auto_cancel_hours'] ?? 24),
            'qls_shop_stock_reduce_on'          => sanitize_key($data['stock_reduce_on'] ?? 'order'),
            'qls_shop_low_stock_threshold'      => intval($data['low_stock_threshold'] ?? 5),
            'qls_shop_points_enabled'           => !empty($data['points_enabled']),
            'qls_shop_points_rate'              => intval($data['points_rate'] ?? 10),
            'qls_group_cron_key'                => sanitize_text_field($data['group_cron_key'] ?? ''),
        ];

        foreach ($settings as $key => $value) {
            update_option($key, $value);
        }

        // 标记需要刷新重写规则
        update_option('qls_shop_flush_rewrite', true);
        return true;
    }

    private function sanitize_locale_setting($locale) {
        $locale = sanitize_text_field((string) $locale);
        return in_array($locale, ['zh_CN', 'en_US'], true) ? $locale : '';
    }

    /**
     * 获取服务标签
     */
    private function get_service_tags() {
        $db = QLS_Shop_Database::instance();
        return $db->get_results('service_tags', [
            'where'   => ['status' => 1],
            'orderby' => 'sort_order',
            'order'   => 'ASC',
        ]);
    }

    /**
     * 获取商品参数模板
     */
    private function get_param_templates() {
        $db = QLS_Shop_Database::instance();
        $params = $db->get_results('product_params', [
            'where'   => ['status' => 1],
            'orderby' => 'sort_order',
            'order'   => 'ASC',
        ]);

        return array_map(function($param) {
            if (!empty($param->values)) {
                $param->values = json_decode($param->values, true);
            }
            return $param;
        }, $params);
    }

    /**
     * AJAX: 一键创建商城页面
     */
    public function ajax_create_pages() {
        check_ajax_referer('qls_shop_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('权限不足', 'qilingshop')]);
        }

        $results = QLS_Shop_Page_Manager::ensure_pages(
            QLS_Shop_Page_Manager::get_default_shop_page_definitions()
        );

        $created = [];
        $errors = [];

        foreach ($results as $result) {
            $title = isset($result['title']) ? (string) $result['title'] : '';

            if (!empty($result['created']) && $title !== '') {
                $created[] = $title;
            }

            if (!empty($result['error'])) {
                $errors[] = $title !== '' ? $title : (string) $result['error'];
            }
        }

        if (!empty($errors)) {
            wp_send_json_error([
                'message' => sprintf(__('页面创建失败：%s', 'qilingshop'), implode(', ', $errors)),
            ]);
        }

        if (!empty($created)) {
            wp_send_json_success([
                'message' => sprintf(__('已创建页面：%s', 'qilingshop'), implode(', ', $created)),
            ]);
        } else {
            wp_send_json_success([
                'message' => __('所有页面已存在或已按短代码复用', 'qilingshop'),
            ]);
        }
    }

    /**
     * 组装卡密文本行（后台订单详情展示）
     */
    private function build_admin_virtual_card_lines($cards) {
        $lines = [];
        if (!is_array($cards)) {
            return $lines;
        }

        foreach ($cards as $card) {
            if (is_object($card)) {
                $card = (array) $card;
            }
            if (!is_array($card)) {
                continue;
            }

            $card_no = sanitize_text_field((string) ($card['card_no'] ?? ''));
            $card_secret = sanitize_text_field((string) ($card['card_secret'] ?? ''));
            if ($card_no === '' && $card_secret === '') {
                continue;
            }

            $lines[] = $card_secret !== '' ? ($card_no . '----' . $card_secret) : $card_no;
        }

        return $lines;
    }

    private function build_admin_virtual_content_html($item) {
        if (!is_object($item)) {
            return '';
        }

        $virtual_content = isset($item->virtual_content) && is_array($item->virtual_content)
            ? $item->virtual_content
            : [];
        $is_virtual_product = !empty($item->product_id) && function_exists('qls_product')
            ? qls_product()->is_virtual((int) $item->product_id)
            : false;

        if (empty($virtual_content)) {
            if (!$is_virtual_product) {
                return '';
            }

            return '<div class="qls-admin-virtual-box qls-admin-virtual-pending">' .
                '<div class="qls-admin-virtual-title">' . esc_html__('虚拟内容', 'qilingshop') . '</div>' .
                '<div class="qls-admin-virtual-body">' . esc_html__('该商品为虚拟商品，内容将在支付后自动发放。', 'qilingshop') . '</div>' .
                '</div>';
        }

        $type = sanitize_key((string) ($virtual_content['type'] ?? ''));
        $title_map = [
            'download' => __('下载链接', 'qilingshop'),
            'card'     => __('卡密信息', 'qilingshop'),
            'custom'   => __('图文内容', 'qilingshop'),
        ];
        $title = $title_map[$type] ?? __('虚拟内容', 'qilingshop');

        $body_html = '';
        if ($type === 'download') {
            $download_url = esc_url_raw((string) ($virtual_content['download_url'] ?? ''));
            $download_code = sanitize_text_field((string) ($virtual_content['download_code'] ?? ''));

            $body_html .= '<div class="qls-admin-virtual-row"><span class="qls-admin-virtual-key">' . esc_html__('下载链接：', 'qilingshop') . '</span>';
            if ($download_url !== '') {
                $body_html .= '<a href="' . esc_url($download_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($download_url) . '</a>';
            } else {
                $body_html .= esc_html__('未配置', 'qilingshop');
            }
            $body_html .= '</div>';
            $body_html .= '<div class="qls-admin-virtual-row"><span class="qls-admin-virtual-key">' . esc_html__('提取码：', 'qilingshop') . '</span>' . esc_html($download_code !== '' ? $download_code : '-') . '</div>';
        } elseif ($type === 'card') {
            $card_lines = $this->build_admin_virtual_card_lines($virtual_content['cards'] ?? []);
            if (!empty($card_lines)) {
                $body_html .= '<pre class="qls-admin-virtual-pre">' . esc_html(implode("\n", $card_lines)) . '</pre>';
            } else {
                $error = sanitize_text_field((string) ($virtual_content['error'] ?? __('暂无卡密信息', 'qilingshop')));
                $body_html .= '<div class="qls-admin-virtual-row">' . esc_html($error) . '</div>';
            }
        } elseif ($type === 'custom') {
            $content = (string) ($virtual_content['content'] ?? '');
            $body_html .= '<div class="qls-admin-virtual-rich">' . wp_kses_post($content) . '</div>';
        } else {
            $body_html .= '<pre class="qls-admin-virtual-pre">' . esc_html(wp_json_encode($virtual_content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>';
        }

        if ($body_html === '') {
            return '';
        }

        return '<div class="qls-admin-virtual-box">' .
            '<div class="qls-admin-virtual-title">' . esc_html($title) . '</div>' .
            '<div class="qls-admin-virtual-body">' . $body_html . '</div>' .
            '</div>';
    }

    /**
     * AJAX Get Order Details
     */
    /**
     * AJAX 生成电子面单。
     */
    public function ajax_generate_waybill() {
        check_ajax_referer('qls_shop_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('权限不足', 'qilingshop'));
        }

        if (!function_exists('qls_waybill')) {
            wp_send_json_error(__('电子面单服务不可用。', 'qilingshop'));
        }

        if ((int) get_option('qls_shop_waybill_enabled', 1) !== 1) {
            wp_send_json_error(__('电子面单未启用，请先在快递物流设置中开启。', 'qilingshop'));
        }

        $shipment_id = isset($_POST['shipment_id']) ? absint(wp_unslash($_POST['shipment_id'])) : 0;
        $template_id = isset($_POST['template_id']) ? absint(wp_unslash($_POST['template_id'])) : 0;
        if ($shipment_id <= 0) {
            wp_send_json_error(__('发货单不存在', 'qilingshop'));
        }

        $result = qls_waybill()->create_for_shipment($shipment_id, [
            'template_id' => $template_id,
            'admin_id'    => get_current_user_id(),
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success([
            'waybill_no' => (string) ($result['waybill_no'] ?? ''),
            'print_url'  => $this->build_waybill_print_url($shipment_id, (int) ($result['log_id'] ?? 0)),
        ]);
    }

    /**
     * 给发货单附加后台面单操作数据。
     *
     * @param array $shipments
     * @return array
     */
    private function decorate_shipments_with_waybill_admin_data($shipments) {
        if (!function_exists('qls_waybill')) {
            return (array) $shipments;
        }

        foreach ((array) $shipments as $shipment) {
            if (!is_object($shipment)) {
                continue;
            }

            $log = qls_waybill()->get_latest_success_log((int) $shipment->id);
            $shipment->waybill_log_id = $log ? (int) $log->id : 0;
            $shipment->waybill_print_url = ($log || !empty($shipment->waybill_no))
                ? $this->build_waybill_print_url((int) $shipment->id, $log ? (int) $log->id : 0)
                : '';
        }

        return $shipments;
    }

    public function ajax_get_order_details() {
        check_ajax_referer('qls_shop_admin', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $order = qls_shop_order()->get($order_id, true);

        if (!$order) {
            wp_send_json_error('Order not found');
        }

        // Format dates
        $order->created_at_fmt = $order->created_at;
        $order->paid_at_fmt = $order->paid_at ? $order->paid_at : '-';
        $order->shipped_at_fmt = $order->shipped_at ? $order->shipped_at : '-';
        
        // Status text
        $order->status_text = qls_shop_order()->get_status_text($order->status);
        $order->status_badge = qls_shop_order()->get_status_badge_class($order->status);

        if (!empty($order->items) && is_array($order->items)) {
            foreach ($order->items as &$item) {
                if (!is_object($item)) {
                    continue;
                }
                $item->is_virtual = !empty($item->product_id) && function_exists('qls_product') ? qls_product()->is_virtual((int) $item->product_id) : false;
                $item->shipped_quantity = isset($item->shipped_quantity) ? (int) $item->shipped_quantity : 0;
                $item->unshipped_quantity = $item->is_virtual ? 0 : max(0, (int) $item->quantity - (int) $item->shipped_quantity);
                $item->virtual_content_display = $this->build_admin_virtual_content_html($item);
            }
            unset($item);
        }

        $order->shipments = [];
        $order->shipment_summary = [
            'physical_quantity' => 0,
            'shipped_quantity'  => 0,
            'shipment_count'    => 0,
        ];
        if (function_exists('qls_shipment')) {
            $order->shipments = $this->decorate_shipments_with_waybill_admin_data(
                qls_shipment()->get_by_order((int) $order->id, true)
            );
            $order->shipment_summary = qls_shipment()->get_order_shipment_summary((int) $order->id);
        }

        // Fetch Logistics Trace if shipped and info exists
        if ($order->status >= 2 && !empty($order->shipping_company) && !empty($order->tracking_no)) {
            if (!class_exists('QLS_Shop_Logistics')) {
                require_once QILINGSHOP_PATH . 'includes/shop/class-qls-shop-logistics.php';
            }
            // Need phone for authenticating some couriers (SF, YTO, etc)
            $trace = qls_shop_logistics()->get_trace($order->shipping_company, $order->tracking_no, $order->receiver_phone);
            
            if (is_wp_error($trace)) {
                $order->logistics_error = $trace->get_error_message();
            } else {
                $order->logistics_trace = $trace;
            }
        }

        wp_send_json_success($order);
    }

    /**
     * AJAX Update Receiver Info
     */
    public function ajax_update_receiver_info() {
        check_ajax_referer('qls_shop_admin', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        
        $data = [
            'receiver_name'     => sanitize_text_field($_POST['receiver_name']),
            'receiver_phone'    => sanitize_text_field($_POST['receiver_phone']),
            'receiver_province' => sanitize_text_field($_POST['receiver_province']),
            'receiver_city'     => sanitize_text_field($_POST['receiver_city']),
            'receiver_district' => sanitize_text_field($_POST['receiver_district']),
            'receiver_address'  => sanitize_text_field($_POST['receiver_address']),
            'buyer_remark'      => sanitize_textarea_field($_POST['buyer_remark']),
        ];

        $updated = qls_shop_order()->update_contact_fields($order_id, $data);

        if ($updated) {
            wp_send_json_success(['message' => __('收货信息已更新', 'qilingshop')]);
        } else {
            wp_send_json_error(['message' => __('更新失败', 'qilingshop')]);
        }
    }

    /**
     * AJAX 删除商品 SKU（编辑页点击垃圾桶即时删除数据库记录）
     */
	    public function ajax_delete_sku() {
	        check_ajax_referer('qls_shop_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('权限不足', 'qilingshop')]);
        }

        $sku_id = isset($_POST['sku_id']) ? absint($_POST['sku_id']) : 0;
        if ($sku_id <= 0) {
            wp_send_json_error(['message' => __('SKU 参数无效', 'qilingshop')]);
        }

        $sku = qls_product()->get_sku($sku_id);
        if (!$sku) {
            wp_send_json_error(['message' => __('SKU 不存在或已删除', 'qilingshop')]);
        }

        $product_id = isset($sku->product_id) ? (int) $sku->product_id : 0;
        if ($product_id <= 0) {
            wp_send_json_error(['message' => __('SKU 数据异常', 'qilingshop')]);
        }

        $deleted = qls_product()->delete_sku($sku_id);
        if (!$deleted) {
            wp_send_json_error(['message' => __('SKU 删除失败', 'qilingshop')]);
        }

        qls_product()->sync_product_stats($product_id);

	        wp_send_json_success([
	            'message' => __('SKU 已删除', 'qilingshop'),
	            'sku_id'  => $sku_id,
	        ]);
	    }

        /**
         * AJAX 助力活动商品库搜索
         */
        public function ajax_assist_search_products() {
            check_ajax_referer('qls_shop_admin', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('权限不足', 'qilingshop')]);
            }

            $keyword = isset($_POST['keyword']) ? sanitize_text_field(wp_unslash($_POST['keyword'])) : '';
            $page = isset($_POST['page']) ? max(1, absint($_POST['page'])) : 1;
            $per_page = isset($_POST['per_page']) ? absint($_POST['per_page']) : 12;
            $per_page = min(24, max(8, $per_page));

            $products = qls_product()->get_list([
                'status'  => 1,
                'keyword' => $keyword,
                'limit'   => $per_page + 1,
                'offset'  => ($page - 1) * $per_page,
                'orderby' => 'id',
                'order'   => 'DESC',
            ]);

            $has_more = count($products) > $per_page;
            if ($has_more) {
                $products = array_slice($products, 0, $per_page);
            }

            $items = [];
            foreach ($products as $product) {
                $image = $this->extract_admin_product_image_url($product->main_image ?? '');
                if ($image === '' && !empty($product->gallery) && is_array($product->gallery)) {
                    $image = $this->extract_admin_product_image_url(reset($product->gallery));
                }

                $min_price = isset($product->min_price) ? (float) $product->min_price : 0.0;
                $max_price = isset($product->max_price) ? (float) $product->max_price : $min_price;
                $price_text = $min_price === $max_price
                    ? '¥' . number_format($min_price, 2)
                    : '¥' . number_format($min_price, 2) . ' - ¥' . number_format($max_price, 2);

                $items[] = [
                    'id'         => (int) $product->id,
                    'title'      => (string) $product->title,
                    'subtitle'   => isset($product->subtitle) ? (string) $product->subtitle : '',
                    'image'      => $image,
                    'price_text' => $price_text,
                    'stock'      => isset($product->total_stock) ? (int) $product->total_stock : 0,
                ];
            }

            wp_send_json_success([
                'items'    => $items,
                'has_more' => $has_more,
                'page'     => $page,
            ]);
        }

        private function extract_admin_product_image_url($media) {
            if (is_array($media)) {
                return !empty($media['url']) ? (string) $media['url'] : '';
            }

            if (!is_string($media) || $media === '') {
                return '';
            }

            $decoded = json_decode($media, true);
            if (is_array($decoded)) {
                return !empty($decoded['url']) ? (string) $decoded['url'] : '';
            }

            return $media;
        }

    // =========================================================================
    // 拼团管理功能
    // =========================================================================

    /**
     * 渲染拼团管理页面
     */
    public function render_group_manage() {
        // 打开后台或执行手动操作前，先处理一批已过期团。
        if (class_exists('QLS_Group_Cron')) {
            QLS_Group_Cron::instance()->check_expired_groups();
        }

        // 处理操作
        if (isset($_GET['action'])) {
            $this->handle_group_action();
        }

        // 获取统计数据
        $stats = qls_group()->get_statistics();

        // 筛选参数
        $status = isset($_GET['status']) ? intval($_GET['status']) : null;
        $keyword = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $per_page = 20;
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;

        // 获取拼团列表
        global $wpdb;
        $groups_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'groups';
        $products_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'products';
        
        $where = "1=1";
        $params = [];
        
        if (!is_null($status)) {
            $where .= " AND g.status = %d";
            $params[] = $status;
        }

        if ($keyword !== '') {
            $keyword_like = '%' . $wpdb->esc_like($keyword) . '%';
            if (ctype_digit($keyword)) {
                $where .= " AND (g.id = %d OR g.product_id = %d OR p.title LIKE %s)";
                $params[] = (int) $keyword;
                $params[] = (int) $keyword;
                $params[] = $keyword_like;
            } else {
                $where .= " AND p.title LIKE %s";
                $params[] = $keyword_like;
            }
        }
        
        // 计算总数
        $count_sql = "SELECT COUNT(*) FROM {$groups_table} g
                      LEFT JOIN {$products_table} p ON g.product_id = p.id
                      WHERE {$where}";
        $total = empty($params) 
            ? (int) $wpdb->get_var($count_sql)
            : (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));
        
        // 获取列表
        $offset = ($page - 1) * $per_page;
        $sql = "SELECT g.*, p.title as product_title, p.main_image as product_image
                FROM {$groups_table} g
                LEFT JOIN {$products_table} p ON g.product_id = p.id
                WHERE {$where}
                ORDER BY g.id DESC
                LIMIT %d OFFSET %d";
        
        $params[] = $per_page;
        $params[] = $offset;
        
        $groups = $wpdb->get_results($wpdb->prepare($sql, $params));
        
        // 补充团长信息和成员信息
        foreach ($groups as &$group) {
            $user = get_userdata($group->leader_id);
            $group->leader_name = $user ? $user->display_name : sprintf(__('用户%s', 'qilingshop'), $group->leader_id);
            $group->leader_avatar = get_avatar_url($group->leader_id, ['size' => 32]);
            $group->status_text = qls_group()->get_status_text($group->status);
            $group->status_badge = qls_group()->get_status_badge_class($group->status);
            $group->remain_seconds = max(0, strtotime($group->expire_time) - current_time('timestamp'));
            
            // 解码商品图片
            if (is_string($group->product_image)) {
                $decoded = json_decode($group->product_image, true);
                $group->product_image = is_array($decoded) ? ($decoded['url'] ?? '') : $group->product_image;
            }
        }

        include QILINGSHOP_PATH . 'admin/shop/views/group-list.php';
    }

    /**
     * 处理拼团管理操作
     */
    private function handle_group_action() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $action = sanitize_key($_GET['action']);
        $group_id = isset($_GET['group_id']) ? intval($_GET['group_id']) : 0;
        
        if (!$group_id || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'qls_group_action_' . $group_id)) {
            return;
        }
        
        switch ($action) {
            case 'force_success':
                // 手动成团
                if (qls_group()->mark_success($group_id)) {
                    update_option('_qls_group_manual_success_' . $group_id, 1);
                    wp_safe_redirect(admin_url('admin.php?page=qls-group-manage&message=success'));
                } else {
                    wp_safe_redirect(admin_url('admin.php?page=qls-group-manage&message=noop'));
                }
                exit;
                
            case 'force_fail':
                // 手动解散并退款
                if (qls_group()->mark_failed($group_id)) {
                    delete_option('_qls_group_manual_success_' . $group_id);
                    delete_option('_qls_group_shipped_' . $group_id);
                    wp_safe_redirect(admin_url('admin.php?page=qls-group-manage&message=failed'));
                } else {
                    wp_safe_redirect(admin_url('admin.php?page=qls-group-manage&message=noop'));
                }
                exit;

            case 'mark_shipped':
                update_option('_qls_group_shipped_' . $group_id, 1);
                wp_safe_redirect(admin_url('admin.php?page=qls-group-manage&message=shipped'));
                exit;
        }
    }

    /**
     * 渲染助力活动配置
     */
    public function render_assist_activities() {
        if (!current_user_can('manage_options')) {
            wp_die(__('权限不足', 'qilingshop'));
        }

        if (!class_exists('QLS_Assist')) {
            require_once QILINGSHOP_PATH . 'includes/shop/class-qls-assist.php';
        }

        // 后台打开活动页时顺手执行一次到期下架，确保状态展示及时。
        if (function_exists('qls_assist')) {
            qls_assist()->process_expired_activities(500);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qls_assist_activity_action'])) {
            if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'qls_assist_activity_action')) {
                add_settings_error('qls_assist_activities', 'invalid_nonce', __('安全验证失败', 'qilingshop'), 'error');
            } else {
                $action = sanitize_key($_POST['qls_assist_activity_action']);
                if ($action === 'save') {
                    $activity_id = intval($_POST['activity_id'] ?? 0);
                    $payload = [
                        'name'               => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
                        'product_id'         => intval($_POST['product_id'] ?? 0),
                        'start_price'        => floatval($_POST['start_price'] ?? 0),
                        'min_price'          => floatval($_POST['min_price'] ?? 0),
                        'help_min'           => floatval($_POST['help_min'] ?? 0.1),
                        'help_max'           => floatval($_POST['help_max'] ?? 1),
                        'target_helpers'     => intval($_POST['target_helpers'] ?? 0),
                        'expire_hours'       => intval($_POST['expire_hours'] ?? 24),
                        'stock_total'        => intval($_POST['stock_total'] ?? 0),
                        'status'             => isset($_POST['status']) ? 1 : 0,
                        'auto_restore_stock' => isset($_POST['auto_restore_stock']) ? 1 : 0,
                        'start_time'         => sanitize_text_field(wp_unslash($_POST['start_time'] ?? '')),
                        'end_time'           => sanitize_text_field(wp_unslash($_POST['end_time'] ?? '')),
                    ];
                    $saved = qls_assist()->save_activity($payload, $activity_id);
                    if (is_wp_error($saved)) {
                        add_settings_error('qls_assist_activities', 'save_failed', $saved->get_error_message(), 'error');
                    } else {
                        add_settings_error('qls_assist_activities', 'saved', __('助力活动已保存', 'qilingshop'), 'success');
                    }
                } elseif ($action === 'disable') {
                    $activity_id = intval($_POST['activity_id'] ?? 0);
                    if ($activity_id > 0) {
                        $updated = qls_assist()->update_activity_status(
                            $activity_id,
                            QLS_Assist::ACTIVITY_DISABLED,
                            QLS_Assist::ACTIVITY_ENABLED
                        );
                        if ($updated) {
                            add_settings_error('qls_assist_activities', 'disabled', __('助力活动已下架', 'qilingshop'), 'success');
                        } else {
                            add_settings_error('qls_assist_activities', 'disable_failed', __('助力活动状态未变更，可能已被其他操作处理。', 'qilingshop'), 'error');
                        }
                    }
                } elseif ($action === 'enable') {
                    $activity_id = intval($_POST['activity_id'] ?? 0);
                    if ($activity_id > 0) {
                        $reopen_end_time = sanitize_text_field(wp_unslash($_POST['reopen_end_time'] ?? ''));
                        $updated = qls_assist()->reopen_activity($activity_id, $reopen_end_time);
                        if (is_wp_error($updated)) {
                            add_settings_error('qls_assist_activities', 'enable_failed', $updated->get_error_message(), 'error');
                        } elseif ($updated) {
                            add_settings_error('qls_assist_activities', 'enabled', __('助力活动已重新上架', 'qilingshop'), 'success');
                        } else {
                            add_settings_error('qls_assist_activities', 'enable_failed', __('助力活动状态未变更，可能已被其他操作处理。', 'qilingshop'), 'error');
                        }
                    }
                } elseif ($action === 'delete') {
                    $activity_id = intval($_POST['activity_id'] ?? 0);
                    if ($activity_id > 0) {
                        $deleted = qls_assist()->delete_activity($activity_id);
                        if (is_wp_error($deleted)) {
                            add_settings_error('qls_assist_activities', 'delete_failed', $deleted->get_error_message(), 'error');
                        } else {
                            add_settings_error('qls_assist_activities', 'deleted', __('助力活动已删除', 'qilingshop'), 'success');
                        }
                    }
                }
            }
        }

        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $keyword = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $limit = 20;
        $offset = ($paged - 1) * $limit;

        $activities = qls_assist()->get_activities([
            'status' => $status === '' ? '' : (int) $status,
            'keyword' => $keyword,
            'limit' => $limit,
            'offset' => $offset,
        ]);
        $activity_status_counts = qls_assist()->get_activity_status_counts();
        $enabled_count = (int) ($activity_status_counts[QLS_Assist::ACTIVITY_ENABLED] ?? 0);
        $disabled_count = (int) ($activity_status_counts[QLS_Assist::ACTIVITY_DISABLED] ?? 0);
        if ($keyword === '') {
            $total = $status === ''
                ? $enabled_count + $disabled_count
                : (int) ($activity_status_counts[(int) $status] ?? 0);
        } else {
            $total = qls_assist()->get_activities_count([
                'status' => $status === '' ? '' : (int) $status,
                'keyword' => $keyword,
            ]);
        }
        $all_count = $status === '' ? (int) $total : ($enabled_count + $disabled_count);

        $edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
        $edit_activity = $edit_id > 0 ? qls_assist()->get_activity($edit_id) : null;

        $selected_product = null;
        if ($edit_activity && !empty($edit_activity->product_id)) {
            $selected_product = qls_product()->get((int) $edit_activity->product_id);
        }

        include QILINGSHOP_PATH . 'admin/shop/views/assist-activities.php';
    }

    /**
     * 渲染助力活动记录
     */
    public function render_assist_campaigns() {
        if (!current_user_can('manage_options')) {
            wp_die(__('权限不足', 'qilingshop'));
        }

        if (!class_exists('QLS_Assist')) {
            require_once QILINGSHOP_PATH . 'includes/shop/class-qls-assist.php';
        }

        qls_assist()->process_expired_campaigns(500);

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qls_assist_campaign_action'])) {
            if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'qls_assist_campaign_action')) {
                add_settings_error('qls_assist_campaigns', 'invalid_nonce', __('安全验证失败', 'qilingshop'), 'error');
            } else {
                $action = sanitize_key($_POST['qls_assist_campaign_action']);
                if ($action === 'clear_ended') {
                    $cleared = qls_assist()->clear_campaign_records([
                        'statuses' => [
                            QLS_Assist::CAMPAIGN_COMPLETED,
                            QLS_Assist::CAMPAIGN_EXPIRED,
                            QLS_Assist::CAMPAIGN_CANCELLED,
                            QLS_Assist::CAMPAIGN_REFUNDED,
                        ],
                    ]);
                    add_settings_error(
                        'qls_assist_campaigns',
                        'records_cleared',
                        sprintf(__('已清除 %d 条已结束助力记录。进行中、待下单和待支付记录已保留。', 'qilingshop'), (int) $cleared),
                        'success'
                    );
                }
            }
        }

        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $keyword = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $limit = 20;
        $offset = ($paged - 1) * $limit;

        $campaigns = qls_assist()->get_campaigns([
            'status' => $status === '' ? '' : (int) $status,
            'keyword' => $keyword,
            'limit' => $limit,
            'offset' => $offset,
        ]);
        $campaign_statuses = [
            QLS_Assist::CAMPAIGN_ONGOING,
            QLS_Assist::CAMPAIGN_READY,
            QLS_Assist::CAMPAIGN_ORDER_PENDING,
            QLS_Assist::CAMPAIGN_COMPLETED,
            QLS_Assist::CAMPAIGN_EXPIRED,
            QLS_Assist::CAMPAIGN_CANCELLED,
            QLS_Assist::CAMPAIGN_REFUNDED,
        ];

        $status_counts = qls_assist()->get_campaign_status_counts();
        foreach ($campaign_statuses as $st) {
            if (!isset($status_counts[$st])) {
                $status_counts[$st] = 0;
            }
        }
        if ($keyword === '') {
            $total = $status === ''
                ? (int) array_sum($status_counts)
                : (int) ($status_counts[(int) $status] ?? 0);
        } else {
            $total = qls_assist()->get_campaigns_count([
                'status' => $status === '' ? '' : (int) $status,
                'keyword' => $keyword,
            ]);
        }
        $all_count = $status === '' ? (int) $total : (int) array_sum($status_counts);

        $clearable_count = (int) ($status_counts[QLS_Assist::CAMPAIGN_COMPLETED] ?? 0)
            + (int) ($status_counts[QLS_Assist::CAMPAIGN_EXPIRED] ?? 0)
            + (int) ($status_counts[QLS_Assist::CAMPAIGN_CANCELLED] ?? 0)
            + (int) ($status_counts[QLS_Assist::CAMPAIGN_REFUNDED] ?? 0);

        $view_campaign_id = isset($_GET['campaign_id']) ? intval($_GET['campaign_id']) : 0;
        $view_campaign = $view_campaign_id > 0 ? qls_assist()->get_campaign($view_campaign_id, true) : null;
        $view_logs = $view_campaign ? qls_assist()->get_campaign_logs($view_campaign_id, 200) : [];
        $campaign_user_map = $this->get_admin_user_display_map($campaigns);
        $view_log_actor_map = $this->get_admin_user_display_map($view_logs, 'actor_id');

        include QILINGSHOP_PATH . 'admin/shop/views/assist-campaigns.php';
    }

    /**
     * 渲染运营看板
     */
    public function render_operations_dashboard() {
        if (!class_exists('QLS_Assist')) {
            require_once QILINGSHOP_PATH . 'includes/shop/class-qls-assist.php';
        }
        if (!class_exists('QLS_Group')) {
            require_once QILINGSHOP_PATH . 'includes/shop/class-qls-group.php';
        }

        $db = QLS_Shop_Database::instance();
        $wpdb = $db->get_wpdb();

        $period = isset($_GET['period']) ? sanitize_key($_GET['period']) : '30day';
        $request_start = isset($_GET['start_date']) ? sanitize_text_field(wp_unslash($_GET['start_date'])) : '';
        $request_end = isset($_GET['end_date']) ? sanitize_text_field(wp_unslash($_GET['end_date'])) : '';
        $range = $this->resolve_stats_date_range($period, $request_start, $request_end);

        $period = $range['period'];
        $start_date = $range['start_date'];
        $end_date = $range['end_date'];
        $start_datetime = $range['start_datetime'];
        $end_datetime = $range['end_datetime'];

        $cache_key = $this->build_operations_dashboard_cache_key([
            'period'     => $period,
            'start_date' => $start_date,
            'end_date'   => $end_date,
        ]);
        $cached_payload = get_transient($cache_key);
        if (is_array($cached_payload)) {
            $panels = isset($cached_payload['panels']) && is_array($cached_payload['panels']) ? $cached_payload['panels'] : [];
            $active_period_label = isset($cached_payload['active_period_label']) ? (string) $cached_payload['active_period_label'] : __('最近30天', 'qilingshop');

            include QILINGSHOP_PATH . 'admin/shop/views/operations-dashboard.php';
            return;
        }

        $table_orders = $db->get_table('orders');
        $table_order_items = $db->get_table('order_items');
        $table_skus = $db->get_table('product_skus');
        $table_groups = $db->get_table('groups');
        $table_assist_campaigns = $db->get_table('assist_campaigns');
        $table_coupon_claims = $db->get_table('coupon_claims');
        $table_coupon_uses = $db->get_table('coupon_uses');

        $assist_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(*) AS launch_count,
                    COALESCE(SUM(CASE WHEN c.help_count > 0 THEN 1 ELSE 0 END), 0) AS helped_count,
                    COALESCE(SUM(CASE WHEN c.status IN (%d, %d, %d, %d) THEN 1 ELSE 0 END), 0) AS reached_count,
                    COALESCE(SUM(CASE WHEN c.pay_order_id > 0 OR c.status IN (%d, %d, %d) THEN 1 ELSE 0 END), 0) AS order_created_count,
                    COALESCE(SUM(CASE WHEN c.status IN (%d, %d) THEN 1 ELSE 0 END), 0) AS paid_count,
                    COALESCE(SUM(c.helped_amount), 0) AS cost_total,
                    COALESCE(SUM(CASE WHEN o.status IN (1, 2, 3) THEN o.final_amount ELSE 0 END), 0) AS revenue_total
                 FROM {$table_assist_campaigns} c
                 LEFT JOIN {$table_orders} o ON o.id = c.pay_order_id
                 WHERE c.created_at BETWEEN %s AND %s",
                QLS_Assist::CAMPAIGN_READY,
                QLS_Assist::CAMPAIGN_ORDER_PENDING,
                QLS_Assist::CAMPAIGN_COMPLETED,
                QLS_Assist::CAMPAIGN_REFUNDED,
                QLS_Assist::CAMPAIGN_ORDER_PENDING,
                QLS_Assist::CAMPAIGN_COMPLETED,
                QLS_Assist::CAMPAIGN_REFUNDED,
                QLS_Assist::CAMPAIGN_COMPLETED,
                QLS_Assist::CAMPAIGN_REFUNDED,
                $start_datetime,
                $end_datetime
            ),
            ARRAY_A
        );
        $assist_row = is_array($assist_row) ? $assist_row : [];
        $assist_revenue = isset($assist_row['revenue_total']) ? (float) $assist_row['revenue_total'] : 0.0;
        $assist_cost = isset($assist_row['cost_total']) ? (float) $assist_row['cost_total'] : 0.0;
        $assist_funnel = $this->build_conversion_funnel([
            ['label' => __('发起助力', 'qilingshop'), 'count' => (int) ($assist_row['launch_count'] ?? 0)],
            ['label' => __('有效助力(≥1次)', 'qilingshop'), 'count' => (int) ($assist_row['helped_count'] ?? 0)],
            ['label' => __('达成目标', 'qilingshop'), 'count' => (int) ($assist_row['reached_count'] ?? 0)],
            ['label' => __('创建差额单', 'qilingshop'), 'count' => (int) ($assist_row['order_created_count'] ?? 0)],
            ['label' => __('支付完成', 'qilingshop'), 'count' => (int) ($assist_row['paid_count'] ?? 0)],
        ]);

        $group_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(*) AS launch_count,
                    COALESCE(SUM(CASE WHEN o.status IN (1, 2, 3, 5, 6) THEN 1 ELSE 0 END), 0) AS paid_count,
                    COALESCE(SUM(CASE WHEN g.status = %d THEN 1 ELSE 0 END), 0) AS grouped_count,
                    COALESCE(SUM(CASE WHEN o.status = 3 THEN 1 ELSE 0 END), 0) AS completed_count,
                    COALESCE(SUM(CASE WHEN o.status IN (1, 2, 3) THEN o.final_amount ELSE 0 END), 0) AS revenue_total
                 FROM {$table_orders} o
                 LEFT JOIN {$table_groups} g ON g.id = o.group_id
                 WHERE o.is_group_order = 1
                   AND o.created_at BETWEEN %s AND %s",
                QLS_Group::STATUS_SUCCESS,
                $start_datetime,
                $end_datetime
            ),
            ARRAY_A
        );
        $group_row = is_array($group_row) ? $group_row : [];
        $group_revenue = isset($group_row['revenue_total']) ? (float) $group_row['revenue_total'] : 0.0;
        $group_cost = (float) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(
                    GREATEST(
                        (CASE WHEN COALESCE(s.sale_price, 0) > 0 THEN s.sale_price ELSE COALESCE(s.price, 0) END) - oi.price,
                        0
                    ) * oi.quantity
                ), 0)
                 FROM {$table_order_items} oi
                 INNER JOIN {$table_orders} o ON o.id = oi.order_id
                 LEFT JOIN {$table_skus} s ON s.id = oi.sku_id
                 WHERE o.is_group_order = 1
                   AND o.created_at BETWEEN %s AND %s
                   AND o.status IN (1, 2, 3)",
                $start_datetime,
                $end_datetime
            )
        );
        $group_funnel = $this->build_conversion_funnel([
            ['label' => __('团购下单', 'qilingshop'), 'count' => (int) ($group_row['launch_count'] ?? 0)],
            ['label' => __('支付成功', 'qilingshop'), 'count' => (int) ($group_row['paid_count'] ?? 0)],
            ['label' => __('成团订单', 'qilingshop'), 'count' => (int) ($group_row['grouped_count'] ?? 0)],
            ['label' => __('交易完成', 'qilingshop'), 'count' => (int) ($group_row['completed_count'] ?? 0)],
        ]);

        $coupon_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COALESCE(COUNT(DISTINCT c.id), 0) AS claimed_count,
                    COALESCE(COUNT(DISTINCT CASE WHEN u.id IS NOT NULL THEN c.id END), 0) AS used_count,
                    COALESCE(COUNT(DISTINCT CASE WHEN o.id IS NOT NULL AND o.status IN (1, 2, 3, 5, 6) THEN c.id END), 0) AS paid_count,
                    COALESCE(COUNT(DISTINCT CASE WHEN o.id IS NOT NULL AND o.status = 3 THEN c.id END), 0) AS completed_count,
                    COALESCE(SUM(CASE WHEN o.id IS NOT NULL AND o.status IN (1, 2, 3) THEN u.discount_amount ELSE 0 END), 0) AS cost_total,
                    COALESCE(SUM(CASE WHEN o.id IS NOT NULL AND o.status IN (1, 2, 3) THEN o.final_amount ELSE 0 END), 0) AS revenue_total
                 FROM {$table_coupon_claims} c
                 LEFT JOIN {$table_coupon_uses} u ON u.claim_id = c.id AND u.order_type IN ('shop', 'group')
                 LEFT JOIN {$table_orders} o ON o.order_no = u.order_no
                 WHERE c.created_at BETWEEN %s AND %s",
                $start_datetime,
                $end_datetime
            ),
            ARRAY_A
        );
        $coupon_row = is_array($coupon_row) ? $coupon_row : [];
        $coupon_claimed = (int) ($coupon_row['claimed_count'] ?? 0);

        $coupon_revenue = isset($coupon_row['revenue_total']) ? (float) $coupon_row['revenue_total'] : 0.0;
        $coupon_cost = isset($coupon_row['cost_total']) ? (float) $coupon_row['cost_total'] : 0.0;
        $coupon_funnel = $this->build_conversion_funnel([
            ['label' => __('领取优惠券', 'qilingshop'), 'count' => $coupon_claimed],
            ['label' => __('下单使用', 'qilingshop'), 'count' => (int) ($coupon_row['used_count'] ?? 0)],
            ['label' => __('支付成功', 'qilingshop'), 'count' => (int) ($coupon_row['paid_count'] ?? 0)],
            ['label' => __('交易完成', 'qilingshop'), 'count' => (int) ($coupon_row['completed_count'] ?? 0)],
        ]);

        $panels = [
            [
                'title' => __('好友助力漏斗', 'qilingshop'),
                'description' => __('按助力活动发起时间统计，追踪从发起到支付完成的转化链路。', 'qilingshop'),
                'manage_url' => admin_url('admin.php?page=qls-assist-campaigns'),
                'manage_text' => __('查看助力记录', 'qilingshop'),
                'kpis' => [
                    ['label' => __('发起助力', 'qilingshop'), 'value' => (int) ($assist_row['launch_count'] ?? 0)],
                    ['label' => __('达成目标', 'qilingshop'), 'value' => (int) ($assist_row['reached_count'] ?? 0)],
                    ['label' => __('支付完成', 'qilingshop'), 'value' => (int) ($assist_row['paid_count'] ?? 0)],
                    ['label' => __('成交金额', 'qilingshop'), 'value' => '¥' . number_format($assist_revenue, 2)],
                ],
                'funnel' => $assist_funnel,
                'revenue' => $assist_revenue,
                'cost' => $assist_cost,
                'roi' => $this->calc_roi_percent($assist_revenue, $assist_cost),
                'roi_note' => __('投入产出比 = （成交金额 - 助力让利）/ 助力让利', 'qilingshop'),
            ],
            [
                'title' => __('拼团漏斗', 'qilingshop'),
                'description' => __('按团购订单创建时间统计，追踪下单、支付、成团、完成的转化。', 'qilingshop'),
                'manage_url' => admin_url('admin.php?page=qls-group-manage'),
                'manage_text' => __('查看拼团管理', 'qilingshop'),
                'kpis' => [
                    ['label' => __('团购下单', 'qilingshop'), 'value' => (int) ($group_row['launch_count'] ?? 0)],
                    ['label' => __('支付成功', 'qilingshop'), 'value' => (int) ($group_row['paid_count'] ?? 0)],
                    ['label' => __('成团订单', 'qilingshop'), 'value' => (int) ($group_row['grouped_count'] ?? 0)],
                    ['label' => __('成交金额', 'qilingshop'), 'value' => '¥' . number_format($group_revenue, 2)],
                ],
                'funnel' => $group_funnel,
                'revenue' => $group_revenue,
                'cost' => $group_cost,
                'roi' => $this->calc_roi_percent($group_revenue, $group_cost),
                'roi_note' => __('拼团让利按「SKU原价/促销价 - 团购成交价」估算。', 'qilingshop'),
            ],
            [
                'title' => __('优惠券漏斗', 'qilingshop'),
                'description' => __('按优惠券领取时间统计（实物商城订单），追踪领取到成交转化。', 'qilingshop'),
                'manage_url' => admin_url('admin.php?page=qls-shop-marketing&tab=coupons'),
                'manage_text' => __('查看优惠券管理', 'qilingshop'),
                'kpis' => [
                    ['label' => __('领取', 'qilingshop'), 'value' => $coupon_claimed],
                    ['label' => __('使用', 'qilingshop'), 'value' => (int) ($coupon_row['used_count'] ?? 0)],
                    ['label' => __('支付成功', 'qilingshop'), 'value' => (int) ($coupon_row['paid_count'] ?? 0)],
                    ['label' => __('成交金额', 'qilingshop'), 'value' => '¥' . number_format($coupon_revenue, 2)],
                ],
                'funnel' => $coupon_funnel,
                'revenue' => $coupon_revenue,
                'cost' => $coupon_cost,
                'roi' => $this->calc_roi_percent($coupon_revenue, $coupon_cost),
                'roi_note' => __('优惠券成本按已支付订单的优惠金额合计计算。', 'qilingshop'),
            ],
        ];

        $period_labels = [
            'today' => __('今天', 'qilingshop'),
            'yesterday' => __('昨天', 'qilingshop'),
            '7day' => __('最近7天', 'qilingshop'),
            '30day' => __('最近30天', 'qilingshop'),
            'month' => __('本月', 'qilingshop'),
            'year' => __('本年', 'qilingshop'),
            'custom' => __('自定义', 'qilingshop'),
        ];
        $active_period_label = $period_labels[$period] ?? __('最近30天', 'qilingshop');

        set_transient($cache_key, [
            'panels' => $panels,
            'active_period_label' => $active_period_label,
        ], $this->get_operations_dashboard_cache_ttl());

        include QILINGSHOP_PATH . 'admin/shop/views/operations-dashboard.php';
    }

    /**
     * 解析统计时间范围
     */
    private function resolve_stats_date_range($period, $start_date = '', $end_date = '') {
        $allowed = ['today', 'yesterday', '7day', '30day', 'month', 'year', 'custom'];
        $period = in_array($period, $allowed, true) ? $period : '30day';
        $timestamp = current_time('timestamp');
        $today = wp_date('Y-m-d', $timestamp);

        if ($period === 'custom') {
            $valid_start = is_string($start_date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date);
            $valid_end = is_string($end_date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date);
            if (!$valid_start || !$valid_end) {
                $period = '30day';
            }
        }

        if ($period !== 'custom') {
            switch ($period) {
                case 'today':
                    $start_date = $today;
                    $end_date = $today;
                    break;
                case 'yesterday':
                    $yesterday = wp_date('Y-m-d', strtotime('-1 day', $timestamp));
                    $start_date = $yesterday;
                    $end_date = $yesterday;
                    break;
                case '7day':
                    $start_date = wp_date('Y-m-d', strtotime('-6 day', $timestamp));
                    $end_date = $today;
                    break;
                case 'month':
                    $start_date = wp_date('Y-m-01', $timestamp);
                    $end_date = $today;
                    break;
                case 'year':
                    $start_date = wp_date('Y-01-01', $timestamp);
                    $end_date = $today;
                    break;
                case '30day':
                default:
                    $start_date = wp_date('Y-m-d', strtotime('-29 day', $timestamp));
                    $end_date = $today;
                    break;
            }
        }

        if (strtotime($start_date) > strtotime($end_date)) {
            $tmp = $start_date;
            $start_date = $end_date;
            $end_date = $tmp;
        }

        return [
            'period' => $period,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'start_datetime' => $start_date . ' 00:00:00',
            'end_datetime' => $end_date . ' 23:59:59',
        ];
    }

    /**
     * 计算漏斗转化率
     */
    private function build_conversion_funnel($stages) {
        $result = [];
        $base_count = isset($stages[0]['count']) ? max(0, (int) $stages[0]['count']) : 0;
        $prev_count = $base_count;

        foreach ($stages as $index => $stage) {
            $count = max(0, (int) ($stage['count'] ?? 0));
            $step_rate = $index === 0 ? 100.0 : ($prev_count > 0 ? round(($count / $prev_count) * 100, 2) : 0.0);
            $overall_rate = $base_count > 0 ? round(($count / $base_count) * 100, 2) : 0.0;

            $result[] = [
                'label' => (string) ($stage['label'] ?? ''),
                'count' => $count,
                'step_rate' => $step_rate,
                'overall_rate' => $overall_rate,
            ];

            $prev_count = $count;
        }

        return $result;
    }

    /**
     * 计算 ROI 百分比
     */
    private function calc_roi_percent($revenue, $cost) {
        $revenue = (float) $revenue;
        $cost = (float) $cost;

        if ($cost <= 0) {
            return null;
        }

        return round((($revenue - $cost) / $cost) * 100, 2);
    }

    /**
     * 保存商品团购设置
     * 
     * @param int   $product_id 商品ID
     * @param array $data       团购数据
     * @return bool
     */
    public function save_product_group_settings($product_id, $data) {
        // 检查是否启用团购
        if (empty($data['group_enabled'])) {
            return qls_group()->disable_rules_by_product($product_id);
        }
        
        // 团购数据
        $rule_data = [
            'product_id'     => $product_id,
            'group_price'    => floatval($data['group_price'] ?? 0),
            'group_size'     => max(2, intval($data['group_size'] ?? 2)),
            'time_limit'     => max(1, intval($data['time_limit'] ?? 24)),
            'status'         => 1,
            'start_time'     => !empty($data['start_time']) ? $data['start_time'] : null,
            'end_time'       => !empty($data['end_time']) ? $data['end_time'] : null,
            'limit_per_user' => intval($data['limit_per_user'] ?? 0),
            'group_stock'    => intval($data['group_stock'] ?? 0),
        ];
        
        // 后台编辑按最近规则更新，避免过期后重新上架生成重复规则。
        $existing = qls_group()->get_latest_rule_by_product($product_id);
        if ($existing) {
            return qls_group()->update_rule($existing->id, $rule_data);
        } else {
            return qls_group()->create_rule($rule_data) !== false;
        }
    }

    /**
     * 管理后台安全跳转（兼容已输出内容场景）。
     *
     * @param string $url 目标地址。
     * @return void
     */
    private function redirect_admin_page($url) {
        $url = esc_url_raw((string) $url);
        if ('' === $url) {
            $url = admin_url();
        }

        if (!headers_sent()) {
            wp_safe_redirect($url);
        } else {
            echo '<script>window.location.href=' . wp_json_encode($url) . ';</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . esc_url($url) . '" /></noscript>';
        }
        exit;
    }
}

