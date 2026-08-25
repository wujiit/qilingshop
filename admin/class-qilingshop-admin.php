<?php
/**
 * 后台管理主类
 *
 * @package QilingShop
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Admin {

    const RECHARGE_CLEANUP_GRACE_PERIOD = 300;
    const WITHDRAWAL_CLEANUP_GRACE_PERIOD = 300;

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_head', [$this, 'print_virtual_admin_inline_css']);
        add_action('admin_init', [$this, 'check_flush_rewrite']);
        add_action('admin_init', [$this, 'handle_record_cleanup_actions']);
        add_filter('admin_body_class', [$this, 'filter_admin_body_class']);
        
        // 初始化子模块
        QilingShop_Admin_Settings::instance();
        QilingShop_Admin_Orders::instance();
        QilingShop_Admin_Users::instance();
        QilingShop_Admin_Statistics::instance();
        QilingShop_Admin_Growth::instance();
        QilingShop_Admin_Registration_Codes::instance();
        QilingShop_Metabox::instance();
        QilingShop_Admin_Resource_Bulk::instance();
    }

    private function acquire_named_lock($lock_name, $timeout = 5) {
        global $wpdb;

        if ($lock_name === '') {
            return false;
        }

        $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, (int) $timeout));
        return (int) $result === 1;
    }

    private function release_named_lock($lock_name) {
        global $wpdb;

        if ($lock_name === '') {
            return;
        }

        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
    }

    private function build_withdrawal_lock_name($withdrawal_id) {
        $withdrawal_id = absint($withdrawal_id);
        if ($withdrawal_id <= 0) {
            return '';
        }

        return 'qlswd_' . $withdrawal_id;
    }

    /**
     * 处理后台记录删除/清空动作（需在 admin_init 提前执行，保证可安全重定向）。
     */
    public function handle_record_cleanup_actions() {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        $page = isset($_REQUEST['page']) ? sanitize_key((string) wp_unslash($_REQUEST['page'])) : '';
        if ($page === 'qilingshop-recharge') {
            $this->handle_recharge_cleanup_action();
            return;
        }

        if ($page === 'qilingshop-withdrawals') {
            $this->handle_withdrawal_cleanup_action();
        }
    }

    /**
     * 处理充值记录删除/清空。
     */
    private function handle_recharge_cleanup_action() {
        $action = '';
        if (isset($_POST['qls_recharge_action'])) {
            $action = sanitize_key((string) wp_unslash($_POST['qls_recharge_action']));
        } elseif (isset($_GET['qls_recharge_action'])) {
            $action = sanitize_key((string) wp_unslash($_GET['qls_recharge_action']));
        }

        if ($action === '') {
            return;
        }

        $db = QilingShop_Database::instance();
        $is_post_action = isset($_POST['qls_recharge_action']);
        if (!$is_post_action) {
            return;
        }
        $message = '';
        $type = 'success';

        if ($action === 'delete') {
            $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
            $nonce_ok = isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'qilingshop_recharge_delete_' . $id);
            if (!$nonce_ok) {
                wp_die(__('请求校验失败', 'qilingshop'));
            }

            $ok = ($id > 0) ? QilingShop_Recharge::instance()->delete_pending($id) : false;
            $message = $ok ? __('充值记录删除成功', 'qilingshop') : __('仅支持删除待支付充值单，或订单正在处理中。', 'qilingshop');
            $type = $ok ? 'success' : 'error';
        } elseif ($action === 'clear_all') {
            $nonce_ok = isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'qilingshop_recharge_clear_all');
            if (!$nonce_ok) {
                wp_die(__('请求校验失败', 'qilingshop'));
            }

            $table = $db->get_table('recharge');
            $cutoff = date('Y-m-d H:i:s', current_time('timestamp') - self::RECHARGE_CLEANUP_GRACE_PERIOD);
            $deleted = $db->query($db->prepare(
                "DELETE FROM {$table}
                 WHERE status = %d
                   AND paid_handled = %d
                   AND paid_handled_at IS NOT NULL
                   AND paid_handled_at < %s",
                1,
                1,
                $cutoff
            ));
            $ok = $deleted !== false;
            if ($ok) {
                $message = ((int) $deleted > 0)
                    ? sprintf(__('已清空 %d 条超过保护期的充值记录', 'qilingshop'), (int) $deleted)
                    : __('没有可清理的已完成充值记录', 'qilingshop');
            } else {
                $message = __('充值记录清空失败', 'qilingshop');
            }
            $type = $ok ? 'success' : 'error';
        }

        if ($message === '') {
            return;
        }

        $redirect_url = add_query_arg([
            'page'     => 'qilingshop-recharge',
            'qls_msg'  => $message,
            'qls_type' => $type,
        ], admin_url('admin.php'));
        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * 处理提现记录删除/清空。
     */
    private function handle_withdrawal_cleanup_action() {
        $action = '';
        if (isset($_POST['qls_withdrawals_action'])) {
            $action = sanitize_key((string) wp_unslash($_POST['qls_withdrawals_action']));
        } elseif (isset($_GET['qls_withdrawals_action'])) {
            $action = sanitize_key((string) wp_unslash($_GET['qls_withdrawals_action']));
        }

        if ($action === '') {
            return;
        }

        $db = QilingShop_Database::instance();
        $is_post_action = isset($_POST['qls_withdrawals_action']);
        if (!$is_post_action) {
            return;
        }

        $status_filter_for_redirect = isset($_POST['status']) ? intval($_POST['status']) : (isset($_GET['status']) ? intval($_GET['status']) : -1);
        if (!in_array($status_filter_for_redirect, [-1, 0, 1, 2], true)) {
            $status_filter_for_redirect = -1;
        }

        $message = '';
        $type = 'success';

        if ($action === 'delete') {
            $withdrawal_id = isset($_POST['id']) ? absint($_POST['id']) : 0;
            $nonce_ok = isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'qilingshop_withdrawal_delete_' . $withdrawal_id);
            if (!$nonce_ok) {
                wp_die(__('请求校验失败', 'qilingshop'));
            }

            $lock_name = $this->build_withdrawal_lock_name($withdrawal_id);
            if (!$this->acquire_named_lock($lock_name, 5)) {
                $message = __('提现记录处理中，请稍后再试', 'qilingshop');
                $type = 'error';
            } else {
                try {
                    $record = $withdrawal_id > 0 ? $db->get_row('withdrawals', ['id' => $withdrawal_id]) : null;
                    if (!$record) {
                        $message = __('提现记录不存在', 'qilingshop');
                        $type = 'error';
                    } elseif ((int) $record->status === 0) {
                        $message = __('待审核提现记录不可删除，请先处理后再清理', 'qilingshop');
                        $type = 'error';
                    } else {
                        $ok = $db->delete('withdrawals', ['id' => $withdrawal_id]) !== false;
                        $message = $ok ? __('提现记录删除成功', 'qilingshop') : __('提现记录删除失败', 'qilingshop');
                        $type = $ok ? 'success' : 'error';
                    }
                } finally {
                    $this->release_named_lock($lock_name);
                }
            }
        } elseif ($action === 'clear_processed') {
            $nonce_ok = isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'qilingshop_withdrawal_clear_processed');
            if (!$nonce_ok) {
                wp_die(__('请求校验失败', 'qilingshop'));
            }

            $table = $db->get_table('withdrawals');
            $cutoff = date('Y-m-d H:i:s', current_time('timestamp') - self::WITHDRAWAL_CLEANUP_GRACE_PERIOD);
            if ($status_filter_for_redirect === 0) {
                $deleted = 0;
            } elseif ($status_filter_for_redirect === 1) {
                $deleted = $db->query($db->prepare(
                    "DELETE FROM {$table} WHERE status = %d AND processed_at IS NOT NULL AND processed_at < %s",
                    1,
                    $cutoff
                ));
            } elseif ($status_filter_for_redirect === 2) {
                $deleted = $db->query($db->prepare(
                    "DELETE FROM {$table} WHERE status = %d AND processed_at IS NOT NULL AND processed_at < %s",
                    2,
                    $cutoff
                ));
            } else {
                $deleted = $db->query($db->prepare(
                    "DELETE FROM {$table}
                     WHERE status IN (%d, %d)
                       AND processed_at IS NOT NULL
                       AND processed_at < %s",
                    1,
                    2,
                    $cutoff
                ));
            }

            $ok = $deleted !== false;
            if ($ok) {
                $message = ((int) $deleted > 0)
                    ? sprintf(__('已清理 %d 条提现记录', 'qilingshop'), (int) $deleted)
                    : __('没有可清理的提现记录', 'qilingshop');
            } else {
                $message = __('提现记录清理失败', 'qilingshop');
            }
            $type = $ok ? 'success' : 'error';
        }

        if ($message === '') {
            return;
        }

        $args = [
            'page'     => 'qilingshop-withdrawals',
            'qls_msg'  => $message,
            'qls_type' => $type,
        ];
        if ($status_filter_for_redirect >= 0) {
            $args['status'] = $status_filter_for_redirect;
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    /**
     * 为插件后台页面追加专属 body class，便于样式作用域隔离。
     *
     * @param string $classes 后台 body class 字符串。
     * @return string
     */
    public function filter_admin_body_class($classes) {
        $is_virtual_admin_page = $this->is_qilingshop_virtual_admin_request_page();
        if (!$is_virtual_admin_page) {
            return $classes;
        }

        $tokens = ['qilingshop-admin-shell', 'qilingshop-admin-theme', 'qilingshop-vr-admin'];
        foreach ($tokens as $token) {
            if (strpos(' ' . $classes . ' ', ' ' . $token . ' ') === false) {
                $classes .= ' ' . $token;
            }
        }

        return trim($classes);
    }

    /**
     * 判断当前请求是否属于启灵商城后台页面。
     *
     * @return bool
     */
    private function is_qilingshop_request_page() {
        return $this->is_qilingshop_virtual_admin_request_page();
    }

    /**
     * 判断当前请求是否为虚拟资源后台菜单页（不含实物商城后台）。
     *
     * @return bool
     */
    private function is_qilingshop_virtual_admin_request_page() {
        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        if ($page === '') {
            return false;
        }

        $virtual_pages = [
            'qilingshop',
            'qilingshop-orders',
            'qilingshop-resource-bulk',
            'qilingshop-points-assets',
            'qilingshop-recharge',
            'qilingshop-withdrawals',
            'qilingshop-vip-levels',
            'qilingshop-vip-codes',
            'qilingshop-growth',
            'qilingshop-statistics',
            'qilingshop-registration-codes',
            'qilingshop-users',
            'qilingshop-commissions',
            'qilingshop-settings',
        ];

        return in_array($page, $virtual_pages, true);
    }

    /**
     * 获取资源相关文章类型配置。
     *
     * @return array
     */
    private function get_qilingshop_resource_post_types() {
        return qilingshop_normalize_resource_post_types(get_option('qilingshop_post_types', ['post']), ['post']);
    }

    /**
     * 获取当前后台编辑页的 post type。
     *
     * @return string
     */
    private function get_current_admin_post_type() {
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

        return sanitize_key($post_type);
    }

    /**
     * 判断当前页面是否为资源编辑页（post.php / post-new.php）。
     *
     * @param string $hook 当前后台钩子。
     * @return bool
     */
    private function is_qilingshop_resource_editor_page($hook = '') {
        $hook = (string) $hook;

        if ($hook !== '' && !in_array($hook, ['post.php', 'post-new.php', 'post'], true)) {
            return false;
        }

        if ($hook === '') {
            global $pagenow;
            if (!in_array((string) $pagenow, ['post.php', 'post-new.php'], true)) {
                return false;
            }
        }

        $post_type = $this->get_current_admin_post_type();
        if ($post_type === '') {
            return false;
        }

        return in_array($post_type, $this->get_qilingshop_resource_post_types(), true);
    }

    /**
     * 添加菜单
     */
    public function add_menu() {
        // 主菜单
        add_menu_page(
            __('启灵商城', 'qilingshop'),
            __('启灵商城', 'qilingshop'),
            'manage_options',
            'qilingshop',
            [$this, 'render_dashboard'],
            'dashicons-cart',
            30
        );

        // 仪表盘
        add_submenu_page(
            'qilingshop',
            __('仪表盘', 'qilingshop'),
            __('仪表盘', 'qilingshop'),
            'manage_options',
            'qilingshop',
            [$this, 'render_dashboard']
        );

        // 订单管理
        add_submenu_page(
            'qilingshop',
            __('订单管理', 'qilingshop'),
            __('订单管理', 'qilingshop'),
            'manage_options',
            'qilingshop-orders',
            [QilingShop_Admin_Orders::instance(), 'render']
        );

        // 用户管理
        add_submenu_page(
            'qilingshop',
            __('用户管理', 'qilingshop'),
            __('用户管理', 'qilingshop'),
            'manage_options',
            'qilingshop-users',
            [QilingShop_Admin_Users::instance(), 'render']
        );

        // 批量操作
        add_submenu_page(
            'qilingshop',
            __('批量操作', 'qilingshop'),
            __('批量操作', 'qilingshop'),
            'manage_options',
            'qilingshop-resource-bulk',
            [QilingShop_Admin_Resource_Bulk::instance(), 'render']
        );

        // 积分批次
        add_submenu_page(
            'qilingshop',
            __('积分批次', 'qilingshop'),
            __('积分批次', 'qilingshop'),
            'manage_options',
            'qilingshop-points-assets',
            [$this, 'render_points_assets']
        );

        // 充值记录
        add_submenu_page(
            'qilingshop',
            __('充值记录', 'qilingshop'),
            __('充值记录', 'qilingshop'),
            'manage_options',
            'qilingshop-recharge',
            [$this, 'render_recharge']
        );

        // 提现审核
        add_submenu_page(
            'qilingshop',
            __('提现审核', 'qilingshop'),
            __('提现审核', 'qilingshop'),
            'manage_options',
            'qilingshop-withdrawals',
            [$this, 'render_withdrawals']
        );

        // VIP 等级
        add_submenu_page(
            'qilingshop',
            __('VIP 等级', 'qilingshop'),
            __('VIP 等级', 'qilingshop'),
            'manage_options',
            'qilingshop-vip-levels',
            [$this, 'render_vip_levels']
        );

        // VIP 兑换码
        add_submenu_page(
            'qilingshop',
            __('VIP 兑换码', 'qilingshop'),
            __('VIP 兑换码', 'qilingshop'),
            'manage_options',
            'qilingshop-vip-codes',
            [QilingShop_Admin_VIP_Codes::instance(), 'render']
        );

        // 成长体系
        add_submenu_page(
            'qilingshop',
            __('成长体系', 'qilingshop'),
            __('成长体系', 'qilingshop'),
            'manage_options',
            'qilingshop-growth',
            [QilingShop_Admin_Growth::instance(), 'render']
        );

        // 数据统计
        add_submenu_page(
            'qilingshop',
            __('数据统计', 'qilingshop'),
            __('数据统计', 'qilingshop'),
            'manage_options',
            'qilingshop-statistics',
            [QilingShop_Admin_Statistics::instance(), 'render']
        );

        // 注册码管理
        add_submenu_page(
            'qilingshop',
            __('注册码管理', 'qilingshop'),
            __('注册码管理', 'qilingshop'),
            'manage_options',
            'qilingshop-registration-codes',
            [QilingShop_Admin_Registration_Codes::instance(), 'render']
        );

        // 作者提成管理（仅在功能开启时显示）
        if (get_option('qilingshop_author_commission_enabled', false)) {
            add_submenu_page(
                'qilingshop',
                __('作者提成', 'qilingshop'),
                __('作者提成', 'qilingshop'),
                'manage_options',
                'qilingshop-commissions',
                [$this, 'render_commissions']
            );
        }

        // 设置
        add_submenu_page(
            'qilingshop',
            __('插件设置', 'qilingshop'),
            __('插件设置', 'qilingshop'),
            'manage_options',
            'qilingshop-settings',
            [QilingShop_Admin_Settings::instance(), 'render']
        );
    }

    /**
     * 判断是否启灵商城后台页面。
     *
     * 仅允许：主菜单页 + 该主菜单下的子页面。
     *
     * @param string $hook 当前后台钩子。
     * @return bool
     */
    private function is_qilingshop_admin_page($hook) {
        $hook = (string) $hook;
        if ($hook === 'toplevel_page_qilingshop') {
            return true;
        }

        return strpos($hook, 'qilingshop_page_') === 0;
    }

    /**
     * 加载资源
     */
    public function enqueue_assets($hook) {
        $is_qilingshop_page = $this->is_qilingshop_admin_page($hook);
        if (!$is_qilingshop_page) {
            return;
        }

        $shell_css_version = QILINGSHOP_VERSION;
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

        $admin_css_version = QILINGSHOP_VERSION;
        $admin_css_file = QILINGSHOP_PATH . 'static/css/qilingshop-admin.css';
        if (file_exists($admin_css_file)) {
            $admin_css_version .= '.' . (string) filemtime($admin_css_file);
        }

        wp_enqueue_style(
            'qilingshop-admin',
            QILINGSHOP_URL . 'static/css/qilingshop-admin.css',
            ['qilingshop-admin-shell'],
            $admin_css_version
        );

        if ($this->is_qilingshop_virtual_admin_request_page()) {
            $assets_version = function_exists('qilingshop_get_assets_version')
                ? (string) qilingshop_get_assets_version()
                : QILINGSHOP_VERSION;

            $virtual_css_version = $assets_version;
            $virtual_css_file = QILINGSHOP_PATH . 'static/css/qilingshop-admin-vr.css';
            if (file_exists($virtual_css_file)) {
                $virtual_css_version .= '.' . (string) filemtime($virtual_css_file);
            }

            wp_enqueue_style(
                'qilingshop-admin-vr',
                QILINGSHOP_URL . 'static/css/qilingshop-admin-vr.css',
                ['qilingshop-admin'],
                $virtual_css_version
            );

            // 在后台页面立即刷新样式，避免缓存影响。
            if (file_exists($virtual_css_file) && is_readable($virtual_css_file)) {
                $virtual_css = file_get_contents($virtual_css_file);
                if (is_string($virtual_css) && $virtual_css !== '') {
                    wp_add_inline_style('qilingshop-admin-vr', $virtual_css);
                }
            }
        }

        $admin_js_version = QILINGSHOP_VERSION;
        $admin_js_file = QILINGSHOP_PATH . 'static/js/qilingshop-admin.js';
        if (file_exists($admin_js_file)) {
            $admin_js_version .= '.' . (string) filemtime($admin_js_file);
        }

        wp_enqueue_script(
            'qilingshop-admin',
            QILINGSHOP_URL . 'static/js/qilingshop-admin.js',
            ['jquery'],
            $admin_js_version,
            true
        );

        wp_localize_script('qilingshop-admin', 'qilingshopAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('qilingshop_admin'),
            'i18n'    => [
                'confirm'  => __('确定要执行此操作吗？', 'qilingshop'),
                'success'  => __('操作成功', 'qilingshop'),
                'error'    => __('操作失败', 'qilingshop'),
            ],
        ]);
    }

    /**
     * 虚拟资源后台样式兜底：直接输出 style，避免某些环境下 enqueue 被缓存/冲突导致不生效。
     *
     * @return void
     */
    public function print_virtual_admin_inline_css() {
        if (!is_admin() || !$this->is_qilingshop_virtual_admin_request_page()) {
            return;
        }

        $core_css_file = QILINGSHOP_PATH . 'static/css/qilingshop-admin.css';
        if (file_exists($core_css_file) && is_readable($core_css_file)) {
            $core_css = file_get_contents($core_css_file);
            if (is_string($core_css) && $core_css !== '') {
                echo "<style id=\"qilingshop-admin-core-inline\">\n";
                echo $core_css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo "\n</style>\n";
            }
        }

        $virtual_css_file = QILINGSHOP_PATH . 'static/css/qilingshop-admin-vr.css';
        if (!file_exists($virtual_css_file) || !is_readable($virtual_css_file)) {
            return;
        }

        $virtual_css = file_get_contents($virtual_css_file);
        if (!is_string($virtual_css) || $virtual_css === '') {
            return;
        }

        echo "<style id=\"qilingshop-admin-vr-inline\">\n";
        echo $virtual_css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo "\n</style>\n";
    }

    /**
     * 检查是否需要刷新重写规则
     */
    public function check_flush_rewrite() {
        if (get_option('qilingshop_flush_rewrite')) {
            flush_rewrite_rules();
            delete_option('qilingshop_flush_rewrite');
        }
    }

    /**
     * 渲染仪表盘
     */
    public function render_dashboard() {
        $db = QilingShop_Database::instance();
        
        // 今日数据
        $today = current_time('Y-m-d');
        $today_start = $today . ' 00:00:00';
        $today_end = $today . ' 23:59:59';

        global $wpdb;
        $orders_table = $db->get_table('orders');
        $recharge_table = $db->get_table('recharge');
        $user_info_table = $db->get_table('user_info');
        $downloads_table = $db->get_table('downloads');
        $vip_log_table = $db->get_table('vip_log');

        // 今日收入
        $today_income = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(final_price), 0) FROM {$orders_table} 
             WHERE status = 1 AND paid_at BETWEEN %s AND %s",
            $today_start, $today_end
        ));

        // 今日充值
        $today_recharge = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$recharge_table} 
             WHERE status = 1 AND paid_at BETWEEN %s AND %s",
            $today_start, $today_end
        ));

        // 今日订单数
        $today_orders = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$orders_table} 
             WHERE status = 1 AND paid_at BETWEEN %s AND %s",
            $today_start, $today_end
        ));

        // 今日新用户
        $today_users = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$user_info_table} 
             WHERE created_at BETWEEN %s AND %s",
            $today_start, $today_end
        ));

        // 总计数据
        $total_income = $wpdb->get_var("SELECT COALESCE(SUM(final_price), 0) FROM {$orders_table} WHERE status = 1");
        $total_recharge = $wpdb->get_var("SELECT COALESCE(SUM(amount), 0) FROM {$recharge_table} WHERE status = 1");
        // 统计有积分相关交易/行为的用户数（购买/下载/充值/VIP）
        $total_users = $wpdb->get_var("
            SELECT COUNT(DISTINCT user_id) FROM (
                SELECT user_id FROM {$orders_table} WHERE status = 1 AND user_id > 0
                UNION
                SELECT user_id FROM {$recharge_table} WHERE status = 1 AND user_id > 0
                UNION
                SELECT user_id FROM {$downloads_table} WHERE user_id > 0
                UNION
                SELECT user_id FROM {$vip_log_table} WHERE user_id > 0
            ) AS qls_users
        ");
        $total_vip = $wpdb->get_var("SELECT COUNT(*) FROM {$user_info_table} WHERE vip_level > 0");

        // 资源购买排行前 20 名
        $top_resources = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id,
                        MAX(post_title) AS post_title,
                        COUNT(*) AS order_count,
                        COALESCE(SUM(final_price), 0) AS total_amount,
                        COALESCE(SUM(price_points), 0) AS total_points
                 FROM {$orders_table}
                 WHERE status = 1 AND order_type = 'resource' AND post_id > 0
                 GROUP BY post_id
                 ORDER BY order_count DESC, total_amount DESC
                 LIMIT %d",
                20
            )
        );
        ?>
        <div class="wrap qilingshop-admin-page qilingshop-vr-page qilingshop-admin qilingshop-dashboard-page">
            <h1><?php _e('启灵积分商城', 'qilingshop'); ?></h1>
            
            <div class="qilingshop-dashboard">
                <div class="dashboard-section">
                    <h2><?php _e('今日数据', 'qilingshop'); ?></h2>
                    <div class="stat-cards">
                        <div class="stat-card">
                            <span class="stat-value"><?php echo qilingshop_format_price($today_income); ?></span>
                            <span class="stat-label"><?php _e('今日收入', 'qilingshop'); ?></span>
                        </div>
                        <div class="stat-card">
                            <span class="stat-value"><?php echo qilingshop_format_price($today_recharge); ?></span>
                            <span class="stat-label"><?php _e('今日充值', 'qilingshop'); ?></span>
                        </div>
                        <div class="stat-card">
                            <span class="stat-value"><?php echo $today_orders; ?></span>
                            <span class="stat-label"><?php _e('今日订单', 'qilingshop'); ?></span>
                        </div>
                        <div class="stat-card">
                            <span class="stat-value"><?php echo $today_users; ?></span>
                            <span class="stat-label"><?php _e('新增用户', 'qilingshop'); ?></span>
                        </div>
                    </div>
                </div>

                <div class="dashboard-section">
                    <h2><?php _e('总计数据', 'qilingshop'); ?></h2>
                    <div class="stat-cards">
                        <div class="stat-card">
                            <span class="stat-value"><?php echo qilingshop_format_price($total_income); ?></span>
                            <span class="stat-label"><?php _e('累计收入', 'qilingshop'); ?></span>
                        </div>
                        <div class="stat-card">
                            <span class="stat-value"><?php echo qilingshop_format_price($total_recharge); ?></span>
                            <span class="stat-label"><?php _e('累计充值', 'qilingshop'); ?></span>
                        </div>
                        <div class="stat-card">
                            <span class="stat-value"><?php echo $total_users; ?></span>
                            <span class="stat-label"><?php _e('交易用户数', 'qilingshop'); ?></span>
                        </div>
                        <div class="stat-card">
                            <span class="stat-value"><?php echo $total_vip; ?></span>
                            <span class="stat-label"><?php _e('VIP 会员', 'qilingshop'); ?></span>
                        </div>
                    </div>
                </div>

                <div class="dashboard-section">
                    <h2><?php _e('资源购买排行', 'qilingshop'); ?></h2>
                    <table class="wp-list-table qls-ui-table widefat fixed striped qls-admin-resource-ranking-table">
                        <thead>
                            <tr>
                                <th class="qls-admin-col-rank"><?php _e('排名', 'qilingshop'); ?></th>
                                <th><?php _e('资源', 'qilingshop'); ?></th>
                                <th class="qls-admin-col-purchase"><?php _e('购买次数', 'qilingshop'); ?></th>
                                <th class="qls-admin-col-amount"><?php _e('累计金额', 'qilingshop'); ?></th>
                                <th class="qls-admin-col-points"><?php _e('累计积分', 'qilingshop'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $top_resources ) ) : ?>
                                <tr>
                                    <td colspan="5"><?php _e('暂无购买数据', 'qilingshop'); ?></td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ( $top_resources as $index => $row ) :
                                    $post_id = (int) $row->post_id;
                                    $title = '';
                                    if ( ! empty( $row->post_title ) ) {
                                        $title = (string) $row->post_title;
                                    }
                                    if ( $title === '' ) {
                                        $title = get_the_title( $post_id );
                                    }
                                    if ( $title === '' ) {
                                        $title = sprintf( __( '资源 #%d', 'qilingshop' ), $post_id );
                                    }
                                    $edit_link = get_edit_post_link( $post_id );
                                ?>
                                    <tr>
                                        <td><?php echo esc_html( $index + 1 ); ?></td>
                                        <td>
                                            <?php if ( $edit_link ) : ?>
                                                <a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( $title ); ?></a>
                                            <?php else : ?>
                                                <?php echo esc_html( $title ); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html( (int) $row->order_count ); ?></td>
                                        <td><?php echo esc_html( qilingshop_format_price( (float) $row->total_amount ) ); ?></td>
                                        <td><?php echo esc_html( qilingshop_format_points( (float) $row->total_points ) ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="dashboard-section">
                    <h2><?php _e('快捷操作', 'qilingshop'); ?></h2>
                    <div class="quick-actions">
                        <a href="<?php echo admin_url('admin.php?page=qilingshop-settings'); ?>" class="button button-primary"><?php _e('插件设置', 'qilingshop'); ?></a>
                        <a href="<?php echo admin_url('admin.php?page=qilingshop-orders'); ?>" class="button"><?php _e('查看订单', 'qilingshop'); ?></a>
                        <a href="<?php echo admin_url('admin.php?page=qilingshop-users'); ?>" class="button"><?php _e('管理用户', 'qilingshop'); ?></a>
                        <a href="<?php echo admin_url('admin.php?page=qilingshop-points-assets'); ?>" class="button"><?php _e('积分批次', 'qilingshop'); ?></a>
                        <a href="<?php echo admin_url('admin.php?page=qilingshop-vip-levels'); ?>" class="button"><?php _e('VIP 等级', 'qilingshop'); ?></a>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * 渲染充值记录
     */
    public function render_recharge() {
        $db = QilingShop_Database::instance();

        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($paged - 1) * $per_page;

        $total = $db->count('recharge', ['status' => 1]);
        $records = $db->get_results('recharge', [
            'where'   => ['status' => 1],
            'orderby' => 'id',
            'order'   => 'DESC',
            'limit'   => $per_page,
            'offset'  => $offset,
        ]);

        ?>
        <div class="wrap qilingshop-admin-page qilingshop-vr-page qilingshop-recharge-page">
            <h1><?php _e('充值记录', 'qilingshop'); ?></h1>

            <?php if (!empty($_GET['qls_msg'])) : ?>
                <?php
                $notice_msg = sanitize_text_field(wp_unslash($_GET['qls_msg']));
                $notice_type = (isset($_GET['qls_type']) && $_GET['qls_type'] === 'error') ? 'error' : 'success';
                ?>
                <div class="notice notice-<?php echo esc_attr($notice_type); ?> is-dismissible">
                    <p><?php echo esc_html($notice_msg); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" class="qls-admin-record-actions qls-admin-inline-action"
                  onsubmit="return confirm('<?php echo esc_js(__('确定要清空全部充值记录吗？此操作不可恢复。', 'qilingshop')); ?>');">
                <?php wp_nonce_field('qilingshop_recharge_clear_all'); ?>
                <input type="hidden" name="page" value="qilingshop-recharge" />
                <input type="hidden" name="qls_recharge_action" value="clear_all" />
                <button type="submit" class="button qls-admin-link-danger"><?php _e('清空全部记录', 'qilingshop'); ?></button>
            </form>

            <table class="wp-list-table qls-ui-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('订单号', 'qilingshop'); ?></th>
                        <th><?php _e('用户', 'qilingshop'); ?></th>
                        <th><?php _e('金额', 'qilingshop'); ?></th>
                        <th><?php _e('积分', 'qilingshop'); ?></th>
                        <th><?php _e('奖励', 'qilingshop'); ?></th>
                        <th><?php _e('支付方式', 'qilingshop'); ?></th>
                        <th><?php _e('时间', 'qilingshop'); ?></th>
                        <th><?php _e('操作', 'qilingshop'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)) : ?>
                        <tr><td colspan="8"><?php _e('暂无记录', 'qilingshop'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($records as $record): ?>
                            <?php
                            $user = get_user_by('ID', $record->user_id);
                            ?>
                            <tr>
                                <td><?php echo esc_html($record->order_no); ?></td>
                                <td><?php echo $user ? esc_html($user->user_login) : (int) $record->user_id; ?></td>
                                <td><?php echo qilingshop_format_price($record->amount); ?></td>
                                <td><?php echo qilingshop_format_points($record->points_received); ?></td>
                                <td><?php echo qilingshop_format_points($record->bonus_points); ?></td>
                                <td><?php echo esc_html($record->payment_method); ?></td>
                                <td><?php echo esc_html($record->paid_at); ?></td>
                                <td>
                                    <form method="post" class="qls-admin-inline-action"
                                          onsubmit="return confirm('<?php echo esc_js(__('确定删除该充值记录？', 'qilingshop')); ?>');">
                                        <?php wp_nonce_field('qilingshop_recharge_delete_' . (int) $record->id); ?>
                                        <input type="hidden" name="page" value="qilingshop-recharge" />
                                        <input type="hidden" name="qls_recharge_action" value="delete" />
                                        <input type="hidden" name="id" value="<?php echo (int) $record->id; ?>" />
                                        <button type="submit" class="button-link qls-admin-link-danger"><?php _e('删除', 'qilingshop'); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php
            $total_pages = ceil($total / $per_page);
            if ($total_pages > 1) {
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo paginate_links([
                    'base'    => add_query_arg('paged', '%#%'),
                    'format'  => '',
                    'current' => $paged,
                    'total'   => $total_pages,
                ]);
                echo '</div></div>';
            }
            ?>
        </div>
        <?php
    }

    /**
     * 渲染积分批次列表
     */
    public function render_points_assets() {
        $db = QilingShop_Database::instance();
        global $wpdb;

        $table_assets = $db->get_table('points_assets');
        $table_users = $wpdb->users;

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_assets));
        if ($exists !== $table_assets) {
            ?>
            <div class="wrap qilingshop-admin-page qilingshop-vr-page qilingshop-points-assets-page">
                <h1><?php _e('积分批次', 'qilingshop'); ?></h1>
                <div class="notice notice-warning"><p><?php _e('积分批次表不存在，请先执行插件升级（停用后启用或进入任意后台页面触发升级）。', 'qilingshop'); ?></p></div>
            </div>
            <?php
            return;
        }

        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 30;
        $offset = ($paged - 1) * $per_page;

        $user_id = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;
        $keyword = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $source = isset($_GET['source']) ? sanitize_key($_GET['source']) : '';
        $status = isset($_GET['status']) ? sanitize_key($_GET['status']) : 'active';

        $allowed_statuses = ['all', 'active', 'available', 'frozen', 'expired', 'permanent'];
        if (!in_array($status, $allowed_statuses, true)) {
            $status = 'active';
        }

        $where = ['1=1'];
        $args = [];
        $now = current_time('mysql');

        if ($user_id > 0) {
            $where[] = 'pa.user_id = %d';
            $args[] = $user_id;
        }

        if ($source !== '') {
            $where[] = 'pa.source = %s';
            $args[] = $source;
        }

        if ($keyword !== '') {
            $like = '%' . $wpdb->esc_like($keyword) . '%';
            $where[] = '(u.user_login LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s OR pa.source LIKE %s)';
            $args[] = $like;
            $args[] = $like;
            $args[] = $like;
            $args[] = $like;
        }

        switch ($status) {
            case 'active':
                $where[] = '(pa.remaining_amount > 0 OR pa.frozen_amount > 0)';
                $where[] = '(pa.is_permanent = 1 OR pa.expires_at IS NULL OR pa.expires_at > %s)';
                $args[] = $now;
                break;
            case 'available':
                $where[] = 'pa.remaining_amount > 0';
                break;
            case 'frozen':
                $where[] = 'pa.frozen_amount > 0';
                break;
            case 'expired':
                $where[] = '(pa.is_permanent = 0 AND pa.expires_at IS NOT NULL AND pa.expires_at <= %s)';
                $args[] = $now;
                break;
            case 'permanent':
                $where[] = 'pa.is_permanent = 1';
                break;
        }

        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*) FROM {$table_assets} pa LEFT JOIN {$table_users} u ON u.ID = pa.user_id WHERE {$where_sql}";
        $total = (int) $wpdb->get_var(empty($args) ? $count_sql : $wpdb->prepare($count_sql, $args));

        $list_sql = "SELECT
                pa.*,
                u.user_login,
                u.user_email,
                u.display_name
            FROM {$table_assets} pa
            LEFT JOIN {$table_users} u ON u.ID = pa.user_id
            WHERE {$where_sql}
            ORDER BY pa.id DESC
            LIMIT %d OFFSET %d";

        $list_args = array_merge($args, [$per_page, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($list_sql, $list_args));

        $stats_sql = "SELECT
                COUNT(*) AS batch_count,
                COALESCE(SUM(remaining_amount), 0) AS available_points,
                COALESCE(SUM(frozen_amount), 0) AS frozen_points,
                COALESCE(SUM(CASE WHEN is_permanent = 1 THEN remaining_amount ELSE 0 END), 0) AS permanent_points,
                COALESCE(SUM(CASE WHEN is_permanent = 0 AND expires_at IS NOT NULL AND expires_at <= %s THEN (remaining_amount + frozen_amount) ELSE 0 END), 0) AS expired_pending
            FROM {$table_assets}";
        $stats = $wpdb->get_row($wpdb->prepare($stats_sql, $now));

        $task_url = '';
        if (class_exists('QilingShop_Task_Center')) {
            $task_url = QilingShop_Task_Center::instance()->get_task_check_url();
        }
        ?>
        <div class="wrap qilingshop-admin-page qilingshop-vr-page qilingshop-points-assets-page">
            <h1><?php _e('积分批次', 'qilingshop'); ?></h1>

            <?php if ($task_url): ?>
                <div class="notice notice-info">
                    <p><?php _e('积分过期维护已并入统一外部任务入口（不走 WP Cron）。建议每 5 分钟访问一次统一任务地址：', 'qilingshop'); ?></p>
                    <div class="qls-inline-actions qls-admin-task-url-actions">
                        <input type="text" id="qilingshop-points-task-url" readonly class="regular-text qls-admin-task-url" value="<?php echo esc_attr($task_url); ?>">
                        <button type="button" class="button qls-copy-btn" data-target="qilingshop-points-task-url"><?php _e('复制地址', 'qilingshop'); ?></button>
                    </div>
                    <p><?php _e('统一入口会自动按任务节流：团购 5 分钟、订单/助力 10 分钟、积分/VIP/游客 1 小时、生日券 6 小时。', 'qilingshop'); ?></p>
                    <p><?php _e('推荐监控工具：Uptime Kuma、cron-job.org、宝塔计划任务、云监控 HTTP 探测。', 'qilingshop'); ?></p>
                </div>
            <?php endif; ?>

            <div class="qls-admin-points-overview">
                <div class="qls-admin-points-card">
                    <div class="qls-admin-points-card-label"><?php _e('批次数', 'qilingshop'); ?></div>
                    <strong><?php echo esc_html((int) ($stats->batch_count ?? 0)); ?></strong>
                </div>
                <div class="qls-admin-points-card">
                    <div class="qls-admin-points-card-label"><?php _e('可用积分', 'qilingshop'); ?></div>
                    <strong><?php echo esc_html(number_format((float) ($stats->available_points ?? 0), 2)); ?></strong>
                </div>
                <div class="qls-admin-points-card">
                    <div class="qls-admin-points-card-label"><?php _e('冻结积分', 'qilingshop'); ?></div>
                    <strong><?php echo esc_html(number_format((float) ($stats->frozen_points ?? 0), 2)); ?></strong>
                </div>
                <div class="qls-admin-points-card">
                    <div class="qls-admin-points-card-label"><?php _e('永久可用', 'qilingshop'); ?></div>
                    <strong><?php echo esc_html(number_format((float) ($stats->permanent_points ?? 0), 2)); ?></strong>
                </div>
                <div class="qls-admin-points-card">
                    <div class="qls-admin-points-card-label"><?php _e('已到期待处理', 'qilingshop'); ?></div>
                    <strong><?php echo esc_html(number_format((float) ($stats->expired_pending ?? 0), 2)); ?></strong>
                </div>
            </div>

            <form method="get" class="qls-admin-points-filter-form">
                <input type="hidden" name="page" value="qilingshop-points-assets">
                <input type="number" name="user_id" value="<?php echo esc_attr($user_id ?: ''); ?>" placeholder="<?php esc_attr_e('用户ID', 'qilingshop'); ?>" class="qls-admin-input-id">
                <input type="text" name="s" value="<?php echo esc_attr($keyword); ?>" placeholder="<?php esc_attr_e('搜索用户名/邮箱/来源', 'qilingshop'); ?>" class="qls-admin-input-search">
                <input type="text" name="source" value="<?php echo esc_attr($source); ?>" placeholder="<?php esc_attr_e('来源标识', 'qilingshop'); ?>" class="qls-admin-input-source">
                <select name="status">
                    <option value="active" <?php selected($status, 'active'); ?>><?php _e('有效中', 'qilingshop'); ?></option>
                    <option value="all" <?php selected($status, 'all'); ?>><?php _e('全部', 'qilingshop'); ?></option>
                    <option value="available" <?php selected($status, 'available'); ?>><?php _e('有可用余额', 'qilingshop'); ?></option>
                    <option value="frozen" <?php selected($status, 'frozen'); ?>><?php _e('有冻结余额', 'qilingshop'); ?></option>
                    <option value="expired" <?php selected($status, 'expired'); ?>><?php _e('已到期', 'qilingshop'); ?></option>
                    <option value="permanent" <?php selected($status, 'permanent'); ?>><?php _e('永久积分', 'qilingshop'); ?></option>
                </select>
                <button type="submit" class="button"><?php _e('筛选', 'qilingshop'); ?></button>
                <a href="<?php echo esc_url(admin_url('admin.php?page=qilingshop-points-assets')); ?>" class="button"><?php _e('重置', 'qilingshop'); ?></a>
            </form>

            <table class="wp-list-table qls-ui-table widefat fixed striped qls-admin-points-table">
                <thead>
                    <tr>
                        <th class="qls-admin-points-col-id"><?php _e('ID', 'qilingshop'); ?></th>
                        <th class="qls-admin-points-col-user"><?php _e('用户', 'qilingshop'); ?></th>
                        <th class="qls-admin-points-col-source"><?php _e('来源', 'qilingshop'); ?></th>
                        <th class="qls-admin-points-col-money"><?php _e('总额', 'qilingshop'); ?></th>
                        <th class="qls-admin-points-col-money"><?php _e('可用', 'qilingshop'); ?></th>
                        <th class="qls-admin-points-col-money"><?php _e('冻结', 'qilingshop'); ?></th>
                        <th class="qls-admin-points-col-money"><?php _e('已消耗', 'qilingshop'); ?></th>
                        <th class="qls-admin-points-col-time"><?php _e('到期时间', 'qilingshop'); ?></th>
                        <th class="qls-admin-points-col-status"><?php _e('状态', 'qilingshop'); ?></th>
                        <th class="qls-admin-points-col-time"><?php _e('创建时间', 'qilingshop'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="10"><?php _e('暂无数据', 'qilingshop'); ?></td></tr>
                <?php else: ?>
                    <?php
                    $now_ts = current_time('timestamp');
                    foreach ($rows as $row):
                        $total_amount = (float) $row->total_amount;
                        $remaining_amount = (float) $row->remaining_amount;
                        $frozen_amount = (float) $row->frozen_amount;
                        $consumed_amount = max(0, $total_amount - $remaining_amount - $frozen_amount);

                        $is_permanent = (int) $row->is_permanent === 1;
                        $expired = (!$is_permanent && !empty($row->expires_at) && strtotime($row->expires_at) <= $now_ts);

                        if ($is_permanent) {
                            $status_text = __('永久有效', 'qilingshop');
                            $status_class = 'qls-admin-points-status-permanent';
                        } elseif ($expired && ($remaining_amount + $frozen_amount) > 0) {
                            $status_text = __('已到期（待处理）', 'qilingshop');
                            $status_class = 'qls-admin-points-status-expired-pending';
                        } elseif ($expired) {
                            $status_text = __('已失效', 'qilingshop');
                            $status_class = 'qls-admin-points-status-expired';
                        } elseif (($remaining_amount + $frozen_amount) > 0) {
                            $status_text = __('有效中', 'qilingshop');
                            $status_class = 'qls-admin-points-status-active';
                        } else {
                            $status_text = __('已用完', 'qilingshop');
                            $status_class = 'qls-admin-points-status-empty';
                        }

                        $user_label = '#' . (int) $row->user_id;
                        if (!empty($row->display_name)) {
                            $user_label = $row->display_name;
                        } elseif (!empty($row->user_login)) {
                            $user_label = $row->user_login;
                        }
                        ?>
                        <tr>
                            <td><?php echo esc_html((int) $row->id); ?></td>
                            <td>
                                <strong><?php echo esc_html($user_label); ?></strong><br>
                                <span class="qls-admin-muted-text">UID: <?php echo esc_html((int) $row->user_id); ?></span>
                                <?php if (!empty($row->user_email)): ?>
                                    <br><span class="qls-admin-muted-text"><?php echo esc_html($row->user_email); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <code><?php echo esc_html($row->source); ?></code>
                                <?php if (!empty($row->related_id)): ?>
                                    <br><span class="qls-admin-muted-text">RID: <?php echo esc_html((int) $row->related_id); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(number_format($total_amount, 2)); ?></td>
                            <td><?php echo esc_html(number_format($remaining_amount, 2)); ?></td>
                            <td><?php echo esc_html(number_format($frozen_amount, 2)); ?></td>
                            <td><?php echo esc_html(number_format($consumed_amount, 2)); ?></td>
                            <td>
                                <?php if ($is_permanent): ?>
                                    <?php _e('永久', 'qilingshop'); ?>
                                <?php else: ?>
                                    <?php echo !empty($row->expires_at) ? esc_html($row->expires_at) : '-'; ?>
                                <?php endif; ?>
                            </td>
                            <td><span class="qls-admin-points-status <?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_text); ?></span></td>
                            <td><?php echo esc_html($row->created_at); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php
            $total_pages = (int) ceil($total / $per_page);
            if ($total_pages > 1) {
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo paginate_links([
                    'base'    => add_query_arg('paged', '%#%'),
                    'format'  => '',
                    'current' => $paged,
                    'total'   => $total_pages,
                ]);
                echo '</div></div>';
            }
            ?>
        </div>
        <?php
    }

    /**
     * 渲染 VIP 等级管理
     */
    public function render_vip_levels() {
        $vip = QilingShop_VIP::instance();
        
        // 处理表单提交
        if (isset($_POST['qilingshop_save_vip_level']) && wp_verify_nonce($_POST['_wpnonce'], 'qilingshop_vip_level')) {
            $data = [
                'level_key'            => sanitize_text_field($_POST['level_key']),
                'level_name'           => sanitize_text_field($_POST['level_name']),
                'price'                => floatval($_POST['price']),
                'original_price'       => floatval($_POST['original_price']) ?: null,
                'duration_days'        => intval($_POST['duration_days']),
                'discount_rate'        => intval($_POST['discount_rate']),
                'can_download_free'    => isset($_POST['can_download_free']) ? 1 : 0,
                'daily_download_limit' => intval($_POST['daily_download_limit']),
                'description'          => sanitize_textarea_field($_POST['description']),
                'badge_color'          => sanitize_hex_color($_POST['badge_color']),
                'sort_order'           => intval($_POST['sort_order']),
                'is_recommended'       => isset($_POST['is_recommended']) ? 1 : 0,
                'is_active'            => isset($_POST['is_active']) ? 1 : 0,
            ];

            if (!empty($_POST['level_id'])) {
                $vip->update_level(intval($_POST['level_id']), $data);
                echo '<div class="notice notice-success"><p>' . __('更新成功', 'qilingshop') . '</p></div>';
            } else {
                $vip->add_level($data);
                echo '<div class="notice notice-success"><p>' . __('添加成功', 'qilingshop') . '</p></div>';
            }
        }

        // 删除操作
        if (isset($_GET['delete']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_vip_level')) {
            $vip->delete_level(intval($_GET['delete']));
            echo '<div class="notice notice-success"><p>' . __('删除成功', 'qilingshop') . '</p></div>';
        }

        // 获取编辑的等级数据
        $edit_level = null;
        if (isset($_GET['edit'])) {
            $edit_level = $vip->get_level(intval($_GET['edit']));
        }

        $levels = $vip->get_levels(false);
        ?>
        <div class="wrap qilingshop-admin-page qilingshop-vr-page qilingshop-vip-levels-page">
            <h1><?php _e('VIP 等级管理', 'qilingshop'); ?></h1>
            
            <table class="wp-list-table qls-ui-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('ID', 'qilingshop'); ?></th>
                        <th><?php _e('名称', 'qilingshop'); ?></th>
                        <th><?php _e('价格', 'qilingshop'); ?></th>
                        <th><?php _e('时长', 'qilingshop'); ?></th>
                        <th><?php _e('折扣', 'qilingshop'); ?></th>
                        <th><?php _e('免费下载', 'qilingshop'); ?></th>
                        <th><?php _e('推荐', 'qilingshop'); ?></th>
                        <th><?php _e('状态', 'qilingshop'); ?></th>
                        <th><?php _e('操作', 'qilingshop'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($levels as $level): ?>
                    <tr>
                        <td><?php echo $level->id; ?></td>
                        <td><span class="qls-admin-vip-level-name" data-badge-color="<?php echo esc_attr($level->badge_color); ?>"><?php echo esc_html($level->level_name); ?></span></td>
                        <td><?php echo qilingshop_format_price($level->price); ?></td>
                        <td><?php echo $level->duration_days >= 999999 ? __('永久', 'qilingshop') : $level->duration_days . __('天', 'qilingshop'); ?></td>
                        <td><?php echo $level->discount_rate; ?>%</td>
                        <td><?php echo $level->can_download_free ? __('是', 'qilingshop') : __('否', 'qilingshop'); ?></td>
                        <td><?php echo $level->is_recommended ? __('是', 'qilingshop') : __('否', 'qilingshop'); ?></td>
                        <td><?php echo $level->is_active ? __('启用', 'qilingshop') : __('禁用', 'qilingshop'); ?></td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=qilingshop-vip-levels&edit=' . $level->id); ?>"><?php _e('编辑', 'qilingshop'); ?></a> |
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=qilingshop-vip-levels&delete=' . $level->id), 'delete_vip_level'); ?>" onclick="return confirm('<?php _e('确定删除？', 'qilingshop'); ?>')"><?php _e('删除', 'qilingshop'); ?></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2><?php echo $edit_level ? __('编辑 VIP 等级', 'qilingshop') : __('添加 VIP 等级', 'qilingshop'); ?></h2>
            <form method="post" class="qilingshop-form">
                <?php wp_nonce_field('qilingshop_vip_level'); ?>
                <?php if ($edit_level): ?>
                <input type="hidden" name="level_id" value="<?php echo $edit_level->id; ?>">
                <?php endif; ?>
                <table class="form-table qls-ui-form-table">
                    <tr>
                        <th><?php _e('等级标识', 'qilingshop'); ?></th>
                        <td><input type="text" name="level_key" value="<?php echo $edit_level ? esc_attr($edit_level->level_key) : ''; ?>" required placeholder="monthly, yearly, lifetime"></td>
                    </tr>
                    <tr>
                        <th><?php _e('等级名称', 'qilingshop'); ?></th>
                        <td><input type="text" name="level_name" value="<?php echo $edit_level ? esc_attr($edit_level->level_name) : ''; ?>" required></td>
                    </tr>
                    <tr>
                        <th><?php _e('价格(元)', 'qilingshop'); ?></th>
                        <td><input type="number" name="price" step="0.01" value="<?php echo $edit_level ? esc_attr($edit_level->price) : ''; ?>" required></td>
                    </tr>
                    <tr>
                        <th><?php _e('原价(元)', 'qilingshop'); ?></th>
                        <td><input type="number" name="original_price" step="0.01" value="<?php echo $edit_level ? esc_attr($edit_level->original_price) : ''; ?>"></td>
                    </tr>
                    <tr>
                        <th><?php _e('有效天数', 'qilingshop'); ?></th>
                        <td><input type="number" name="duration_days" value="<?php echo $edit_level ? esc_attr($edit_level->duration_days) : '30'; ?>" required> <small><?php _e('永久填 999999', 'qilingshop'); ?></small></td>
                    </tr>
                    <tr>
                        <th><?php _e('折扣率(%)', 'qilingshop'); ?></th>
                        <td><input type="number" name="discount_rate" value="<?php echo $edit_level ? esc_attr($edit_level->discount_rate) : '100'; ?>" min="0" max="100"> <small><?php _e('100=无折扣, 80=8折, 0=免费', 'qilingshop'); ?></small></td>
                    </tr>
                    <tr>
                        <th><?php _e('可免费下载', 'qilingshop'); ?></th>
                        <td><label><input type="checkbox" name="can_download_free" value="1" <?php checked($edit_level ? $edit_level->can_download_free : 0); ?>> <?php _e('是', 'qilingshop'); ?></label></td>
                    </tr>
                    <tr>
                        <th><?php _e('每日下载限制', 'qilingshop'); ?></th>
                        <td><input type="number" name="daily_download_limit" value="<?php echo $edit_level ? esc_attr($edit_level->daily_download_limit) : '-1'; ?>"> <small><?php _e('-1=不限', 'qilingshop'); ?></small></td>
                    </tr>
                    <tr>
                        <th><?php _e('徽章颜色', 'qilingshop'); ?></th>
                        <td><input type="color" name="badge_color" value="<?php echo $edit_level ? esc_attr($edit_level->badge_color) : '#ff6600'; ?>"></td>
                    </tr>
                    <tr>
                        <th><?php _e('排序', 'qilingshop'); ?></th>
                        <td><input type="number" name="sort_order" value="<?php echo $edit_level ? esc_attr($edit_level->sort_order) : '0'; ?>"></td>
                    </tr>
                    <tr>
                        <th><?php _e('设为推荐', 'qilingshop'); ?></th>
                        <td><label><input type="checkbox" name="is_recommended" value="1" <?php checked($edit_level ? $edit_level->is_recommended : 0); ?>> <?php _e('是', 'qilingshop'); ?></label></td>
                    </tr>
                    <tr>
                        <th><?php _e('描述', 'qilingshop'); ?></th>
                        <td>
                            <textarea name="description" rows="5" class="large-text code"><?php echo $edit_level ? esc_textarea($edit_level->description) : ''; ?></textarea>
                            <p class="description"><?php _e('一行一项权益，显示在套餐卡片中。', 'qilingshop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('启用', 'qilingshop'); ?></th>
                        <td><label><input type="checkbox" name="is_active" value="1" <?php checked($edit_level ? $edit_level->is_active : 1); ?>> <?php _e('是', 'qilingshop'); ?></label></td>
                    </tr>
                </table>
                <p>
                    <button type="submit" name="qilingshop_save_vip_level" class="button button-primary"><?php _e('保存', 'qilingshop'); ?></button>
                    <?php if ($edit_level): ?>
                    <a href="<?php echo admin_url('admin.php?page=qilingshop-vip-levels'); ?>" class="button"><?php _e('取消', 'qilingshop'); ?></a>
                    <?php endif; ?>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * 渲染充值奖励规则管理 (已弃用 - 已合并到插件设置 → 充值设置)
     * 
     * @deprecated 功能已整合到 class-qilingshop-admin-settings.php 的 render_recharge_tab()
     */

    /**
     * 渲染提现审核页面
     * 
     * 管理员可查看用户提现申请，进行通过或拒绝操作
     */
    public function render_withdrawals() {
        $db = QilingShop_Database::instance();
        
        // 处理审核操作
        if (isset($_POST['qilingshop_withdrawal_action']) && wp_verify_nonce($_POST['_wpnonce'], 'qilingshop_withdrawal_action')) {
            $withdrawal_id = intval($_POST['withdrawal_id']);
            $action = sanitize_text_field($_POST['action_type']);
            $admin_note = sanitize_textarea_field($_POST['admin_note'] ?? '');
            
            $this->process_withdrawal_action($withdrawal_id, $action, $admin_note);
        }
        
        // 筛选状态
        $status_filter = isset($_GET['status']) ? intval($_GET['status']) : -1;
        
        // 分页
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($paged - 1) * $per_page;
        
        // 构建查询条件
        $where = [];
        if ($status_filter >= 0) {
            $where['status'] = $status_filter;
        }
        
        $total = $db->count('withdrawals', $where);
        $records = $db->get_results('withdrawals', [
            'where'   => $where,
            'orderby' => 'id',
            'order'   => 'DESC',
            'limit'   => $per_page,
            'offset'  => $offset,
        ]);
        
        // 统计各状态数量
        $count_pending = $db->count('withdrawals', ['status' => 0]);
        $count_completed = $db->count('withdrawals', ['status' => 1]);
        $count_rejected = $db->count('withdrawals', ['status' => 2]);
        $count_all = $count_pending + $count_completed + $count_rejected;
        
        $status_labels = [
            0 => ['label' => __('待审核', 'qilingshop'), 'class' => 'pending'],
            1 => ['label' => __('已完成', 'qilingshop'), 'class' => 'completed'],
            2 => ['label' => __('已拒绝', 'qilingshop'), 'class' => 'rejected'],
        ];
        
        $account_types = [
            'alipay' => __('支付宝', 'qilingshop'),
            'wechat' => __('微信', 'qilingshop'),
            'bank'   => __('银行卡', 'qilingshop'),
        ];

        $clear_label = __('清空已处理记录', 'qilingshop');
        if ($status_filter === 1) {
            $clear_label = __('清空已完成记录', 'qilingshop');
        } elseif ($status_filter === 2) {
            $clear_label = __('清空已拒绝记录', 'qilingshop');
        }
        $show_clear_button = ($status_filter !== 0);
        ?>
        <div class="wrap qilingshop-admin-page qilingshop-vr-page qilingshop-withdrawals-page">
            <h1><?php _e('提现审核', 'qilingshop'); ?></h1>

            <?php if (!empty($_GET['qls_msg'])) : ?>
                <?php
                $notice_msg = sanitize_text_field(wp_unslash($_GET['qls_msg']));
                $notice_type = (isset($_GET['qls_type']) && $_GET['qls_type'] === 'error') ? 'error' : 'success';
                ?>
                <div class="notice notice-<?php echo esc_attr($notice_type); ?> is-dismissible">
                    <p><?php echo esc_html($notice_msg); ?></p>
                </div>
            <?php endif; ?>
            
            <!-- 状态筛选 -->
            <ul class="subsubsub">
                <li>
                    <a href="<?php echo admin_url('admin.php?page=qilingshop-withdrawals'); ?>" <?php echo $status_filter < 0 ? 'class="current"' : ''; ?>>
                        <?php _e('全部', 'qilingshop'); ?> <span class="count">(<?php echo $count_all; ?>)</span>
                    </a> |
                </li>
                <li>
                    <a href="<?php echo admin_url('admin.php?page=qilingshop-withdrawals&status=0'); ?>" <?php echo $status_filter === 0 ? 'class="current"' : ''; ?>>
                        <?php _e('待审核', 'qilingshop'); ?> <span class="count">(<?php echo $count_pending; ?>)</span>
                    </a> |
                </li>
                <li>
                    <a href="<?php echo admin_url('admin.php?page=qilingshop-withdrawals&status=1'); ?>" <?php echo $status_filter === 1 ? 'class="current"' : ''; ?>>
                        <?php _e('已完成', 'qilingshop'); ?> <span class="count">(<?php echo $count_completed; ?>)</span>
                    </a> |
                </li>
                <li>
                    <a href="<?php echo admin_url('admin.php?page=qilingshop-withdrawals&status=2'); ?>" <?php echo $status_filter === 2 ? 'class="current"' : ''; ?>>
                        <?php _e('已拒绝', 'qilingshop'); ?> <span class="count">(<?php echo $count_rejected; ?>)</span>
                    </a>
                </li>
            </ul>

            <?php if ($show_clear_button) : ?>
                <form method="post" class="qls-admin-record-actions qls-admin-inline-action"
                      onsubmit="return confirm('<?php echo esc_js(__('确定要清空已处理的提现记录吗？此操作不可恢复。', 'qilingshop')); ?>');">
                    <?php wp_nonce_field('qilingshop_withdrawal_clear_processed'); ?>
                    <input type="hidden" name="page" value="qilingshop-withdrawals" />
                    <input type="hidden" name="qls_withdrawals_action" value="clear_processed" />
                    <?php if ($status_filter >= 0) : ?>
                        <input type="hidden" name="status" value="<?php echo (int) $status_filter; ?>" />
                    <?php endif; ?>
                    <button type="submit" class="button qls-admin-link-danger"><?php echo esc_html($clear_label); ?></button>
                </form>
            <?php endif; ?>
            
            <table class="wp-list-table qls-ui-table widefat fixed striped qls-admin-withdrawals-table">
                <thead>
                    <tr>
                        <th class="qls-admin-col-rank"><?php _e('ID', 'qilingshop'); ?></th>
                        <th><?php _e('用户', 'qilingshop'); ?></th>
                        <th><?php _e('金额', 'qilingshop'); ?></th>
                        <th><?php _e('手续费', 'qilingshop'); ?></th>
                        <th><?php _e('实际到账', 'qilingshop'); ?></th>
                        <th><?php _e('收款方式', 'qilingshop'); ?></th>
                        <th><?php _e('收款账号', 'qilingshop'); ?></th>
                        <th><?php _e('状态', 'qilingshop'); ?></th>
                        <th><?php _e('申请时间', 'qilingshop'); ?></th>
                        <th class="qls-admin-withdrawals-col-actions"><?php _e('操作', 'qilingshop'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="10" class="qls-admin-empty-row"><?php _e('暂无提现申请', 'qilingshop'); ?></td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($records as $record): 
                        $user = get_user_by('ID', $record->user_id);
                        $status_info = $status_labels[$record->status] ?? $status_labels[0];
                        $actual_amount = $record->amount - ($record->fee ?? 0);
                    ?>
                    <tr>
                        <td><?php echo $record->id; ?></td>
                        <td>
                            <?php if ($user): ?>
                                <?php echo get_avatar($record->user_id, 24); ?>
                                <strong><?php echo esc_html($user->display_name); ?></strong>
                                <br><small><?php echo esc_html($user->user_email); ?></small>
                            <?php else: ?>
                                <em><?php _e('用户已删除', 'qilingshop'); ?></em>
                            <?php endif; ?>
                        </td>
                        <td><strong>¥<?php echo number_format($record->amount, 2); ?></strong></td>
                        <td>¥<?php echo number_format($record->fee ?? 0, 2); ?></td>
                        <td class="qls-admin-amount-positive"><strong>¥<?php echo number_format($actual_amount, 2); ?></strong></td>
                        <td><?php echo esc_html($account_types[$record->account_type] ?? $record->account_type); ?></td>
                        <td>
                            <strong><?php echo esc_html($record->account_name); ?></strong><br>
                            <code><?php echo esc_html($record->account_no); ?></code>
                        </td>
                        <td>
                            <span class="qls-status-badge <?php echo $status_info['class']; ?>">
                                <?php echo $status_info['label']; ?>
                            </span>
                            <?php if ($record->status == 2 && $record->admin_note): ?>
                                <br><small class="qls-admin-note-danger"><?php echo esc_html($record->admin_note); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($record->created_at); ?></td>
                        <td>
                            <?php if ($record->status == 0): ?>
                            <!-- 待审核 - 显示操作按钮 -->
                            <button type="button" class="button button-primary qls-approve-btn" 
                                    data-id="<?php echo $record->id; ?>"
                                    data-amount="<?php echo $actual_amount; ?>"
                                    data-user="<?php echo esc_attr($user ? $user->display_name : ''); ?>">
                                <?php _e('确认打款', 'qilingshop'); ?>
                            </button>
                            <button type="button" class="button qls-reject-btn" 
                                    data-id="<?php echo $record->id; ?>">
                                <?php _e('拒绝', 'qilingshop'); ?>
                            </button>
                            <?php elseif ($record->status == 1): ?>
                            <span class="qls-admin-status-success">✓ <?php _e('已打款', 'qilingshop'); ?></span>
                            <?php if ($record->processed_at): ?>
                                <br><small><?php echo esc_html($record->processed_at); ?></small>
                            <?php endif; ?>
                            <br>
                            <form method="post" class="qls-admin-inline-action"
                                  onsubmit="return confirm('<?php echo esc_js(__('确定删除该提现记录？', 'qilingshop')); ?>');">
                                <?php wp_nonce_field('qilingshop_withdrawal_delete_' . (int) $record->id); ?>
                                <input type="hidden" name="page" value="qilingshop-withdrawals" />
                                <input type="hidden" name="qls_withdrawals_action" value="delete" />
                                <input type="hidden" name="id" value="<?php echo (int) $record->id; ?>" />
                                <?php if ($status_filter >= 0) : ?>
                                    <input type="hidden" name="status" value="<?php echo (int) $status_filter; ?>" />
                                <?php endif; ?>
                                <button type="submit" class="button-link qls-admin-link-danger"><?php _e('删除记录', 'qilingshop'); ?></button>
                            </form>
                            <?php else: ?>
                            <span class="qls-admin-status-danger">✗ <?php _e('已拒绝', 'qilingshop'); ?></span>
                            <br>
                            <form method="post" class="qls-admin-inline-action"
                                  onsubmit="return confirm('<?php echo esc_js(__('确定删除该提现记录？', 'qilingshop')); ?>');">
                                <?php wp_nonce_field('qilingshop_withdrawal_delete_' . (int) $record->id); ?>
                                <input type="hidden" name="page" value="qilingshop-withdrawals" />
                                <input type="hidden" name="qls_withdrawals_action" value="delete" />
                                <input type="hidden" name="id" value="<?php echo (int) $record->id; ?>" />
                                <?php if ($status_filter >= 0) : ?>
                                    <input type="hidden" name="status" value="<?php echo (int) $status_filter; ?>" />
                                <?php endif; ?>
                                <button type="submit" class="button-link qls-admin-link-danger"><?php _e('删除记录', 'qilingshop'); ?></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <?php
            // 分页
            $total_pages = ceil($total / $per_page);
            if ($total_pages > 1) {
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo paginate_links([
                    'base'    => add_query_arg('paged', '%#%'),
                    'format'  => '',
                    'current' => $paged,
                    'total'   => $total_pages,
                ]);
                echo '</div></div>';
            }
            ?>
        </div>
        
        <!-- 确认打款弹窗 -->
        <div id="qls-approve-modal" class="qls-admin-modal-hidden">
            <div class="qls-modal-backdrop"></div>
            <div class="qls-modal-dialog">
                <h3><?php _e('确认打款', 'qilingshop'); ?></h3>
                <p id="qls-approve-info"></p>
                <p class="qls-admin-alert-danger"><?php _e('请确认您已通过线下方式完成转账！', 'qilingshop'); ?></p>
                <form method="post" id="qls-approve-form">
                    <?php wp_nonce_field('qilingshop_withdrawal_action'); ?>
                    <input type="hidden" name="qilingshop_withdrawal_action" value="1">
                    <input type="hidden" name="withdrawal_id" id="approve-withdrawal-id">
                    <input type="hidden" name="action_type" value="approve">
                    <p>
                        <button type="submit" class="button button-primary"><?php _e('确认已打款', 'qilingshop'); ?></button>
                        <button type="button" class="button qls-close-modal"><?php _e('取消', 'qilingshop'); ?></button>
                    </p>
                </form>
            </div>
        </div>
        
        <!-- 拒绝弹窗 -->
        <div id="qls-reject-modal" class="qls-admin-modal-hidden">
            <div class="qls-modal-backdrop"></div>
            <div class="qls-modal-dialog">
                <h3><?php _e('拒绝提现申请', 'qilingshop'); ?></h3>
                <p><?php _e('拒绝后，申请金额将退还到用户的可提现余额。', 'qilingshop'); ?></p>
                <form method="post" id="qls-reject-form">
                    <?php wp_nonce_field('qilingshop_withdrawal_action'); ?>
                    <input type="hidden" name="qilingshop_withdrawal_action" value="1">
                    <input type="hidden" name="withdrawal_id" id="reject-withdrawal-id">
                    <input type="hidden" name="action_type" value="reject">
                    <p>
                        <label><?php _e('拒绝原因（可选）：', 'qilingshop'); ?></label><br>
                        <textarea name="admin_note" rows="3" class="qls-admin-textarea-full"></textarea>
                    </p>
                    <p>
                        <button type="submit" class="button button-primary qls-admin-btn-danger"><?php _e('确认拒绝', 'qilingshop'); ?></button>
                        <button type="button" class="button qls-close-modal"><?php _e('取消', 'qilingshop'); ?></button>
                    </p>
                </form>
            </div>
        </div>
        
        <script>
        jQuery(function($) {
            // 确认打款
	            $('.qls-approve-btn').click(function() {
	                var id = $(this).data('id');
	                var amount = parseFloat($(this).data('amount')) || 0;
	                var user = String($(this).data('user') || '');
	                $('#approve-withdrawal-id').val(id);
	                $('#qls-approve-info')
	                    .empty()
	                    .append(document.createTextNode('<?php echo esc_js(__('用户：', 'qilingshop')); ?>'))
	                    .append($('<strong>').text(user))
	                    .append('<br>')
	                    .append(document.createTextNode('<?php echo esc_js(__('打款金额：', 'qilingshop')); ?>'))
	                    .append($('<strong>').addClass('qls-admin-amount-positive-text').text('¥' + amount.toFixed(2)));
	                $('#qls-approve-modal').show();
	            });
            
            // 拒绝
            $('.qls-reject-btn').click(function() {
                var id = $(this).data('id');
                $('#reject-withdrawal-id').val(id);
                $('#qls-reject-modal').show();
            });
            
            // 关闭弹窗
            $('.qls-close-modal, .qls-modal-backdrop').click(function() {
                $('#qls-approve-modal, #qls-reject-modal').hide();
            });
        });
        </script>
        <?php
    }

    /**
     * 处理提现审核操作
     * 
     * @param int    $withdrawal_id 提现记录ID
     * @param string $action        操作类型 approve/reject
     * @param string $admin_note    管理员备注
     */
    private function process_withdrawal_action($withdrawal_id, $action, $admin_note = '') {
        $db = QilingShop_Database::instance();

        $withdrawal_id = absint($withdrawal_id);
        $lock_name = $this->build_withdrawal_lock_name($withdrawal_id);
        if (!$this->acquire_named_lock($lock_name, 5)) {
            echo '<div class="notice notice-error"><p>' . __('提现记录处理中，请稍后重试', 'qilingshop') . '</p></div>';
            return;
        }

        global $wpdb;

        try {
            $table_withdrawals = $db->get_table('withdrawals');
            $table_user_info = $db->get_table('user_info');

            $db->begin_transaction();

            $record = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_withdrawals} WHERE id = %d FOR UPDATE",
                $withdrawal_id
            ));
            if (!$record || (int) $record->status !== 0) {
                $db->rollback();
                echo '<div class="notice notice-error"><p>' . __('提现记录不存在或已处理', 'qilingshop') . '</p></div>';
                return;
            }

            $processed_at = current_time('mysql');
            $updated = 0;

            if ($action === 'approve') {
                $updated = $db->update('withdrawals', [
                    'status'       => 1,
                    'processed_at' => $processed_at,
                    'admin_note'   => $admin_note,
                ], [
                    'id'     => $withdrawal_id,
                    'status' => 0,
                ]);

                if ((int) $updated !== 1) {
                    throw new Exception(__('提现处理失败，请稍后重试', 'qilingshop'));
                }

                $db->commit();

                do_action(
                    'qilingshop_withdraw_approved',
                    (int) $record->user_id,
                    (int) $record->id,
                    (float) $record->amount,
                    (float) ($record->fee ?? 0),
                    (float) ($record->actual_amount ?? $record->amount),
                    (string) $admin_note
                );
                QilingShop_Points::instance()->clear_user_cache((int) $record->user_id);
                if (class_exists('QilingShop_Affiliate')) {
                    QilingShop_Affiliate::instance()->refresh_invite_stats_cache((int) $record->user_id);
                }

                echo '<div class="notice notice-success"><p>' . __('已确认打款，提现完成', 'qilingshop') . '</p></div>';
                return;
            }

            if ($action === 'reject') {
                $updated = $db->update('withdrawals', [
                    'status'       => 2,
                    'processed_at' => $processed_at,
                    'admin_note'   => $admin_note,
                ], [
                    'id'     => $withdrawal_id,
                    'status' => 0,
                ]);

                if ((int) $updated !== 1) {
                    throw new Exception(__('提现处理失败，请稍后重试', 'qilingshop'));
                }

                $credited = $wpdb->query($wpdb->prepare(
                    "UPDATE {$table_user_info}
                     SET withdrawable_balance = withdrawable_balance + %f
                     WHERE user_id = %d",
                    (float) $record->amount,
                    (int) $record->user_id
                ));
                if ($credited === false || (int) $credited !== 1) {
                    throw new Exception(__('退还用户余额失败，请稍后重试', 'qilingshop'));
                }

                $db->commit();

                do_action(
                    'qilingshop_withdraw_rejected',
                    (int) $record->user_id,
                    (int) $record->id,
                    (float) $record->amount,
                    (string) $admin_note
                );
                QilingShop_Points::instance()->clear_user_cache((int) $record->user_id);
                if (class_exists('QilingShop_Affiliate')) {
                    QilingShop_Affiliate::instance()->refresh_invite_stats_cache((int) $record->user_id);
                }

                echo '<div class="notice notice-success"><p>' . __('已拒绝提现申请，金额已退还至用户余额', 'qilingshop') . '</p></div>';
                return;
            }

            $db->rollback();
            echo '<div class="notice notice-error"><p>' . __('不支持的提现处理动作', 'qilingshop') . '</p></div>';
        } catch (Exception $e) {
            $db->rollback();
            echo '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div>';
        } finally {
            $this->release_named_lock($lock_name);
        }
    }

    /**
     * 渲染作者提成管理页面
     */
    public function render_commissions() {
        $commission = qilingshop_author_commission();
        $statistics = $commission->get_global_statistics();
        
        // 分页
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($paged - 1) * $per_page;
        
        // 筛选
        $author_id = isset($_GET['author_id']) ? intval($_GET['author_id']) : 0;
        $total = $commission->get_all_commissions_count(['author_id' => $author_id]);
        $records = $commission->get_all_commissions([
            'limit'     => $per_page,
            'offset'    => $offset,
            'author_id' => $author_id,
        ]);
        
        // 作者排行
        $top_authors = $commission->get_top_authors(10);
        ?>
        <div class="wrap qilingshop-admin-page qilingshop-vr-page qilingshop-admin qilingshop-commissions-page">
            <h1><?php _e('作者提成管理', 'qilingshop'); ?></h1>
            
            <!-- 统计概览 -->
            <div class="dashboard-section">
                <h2><?php _e('提成统计', 'qilingshop'); ?></h2>
                <div class="stat-cards">
                    <div class="stat-card">
                        <span class="stat-value">¥<?php echo number_format($statistics['total_commission'], 2); ?></span>
                        <span class="stat-label"><?php _e('累计提成', 'qilingshop'); ?></span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-value">¥<?php echo number_format($statistics['total_sales'], 2); ?></span>
                        <span class="stat-label"><?php _e('累计销售', 'qilingshop'); ?></span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-value"><?php echo intval($statistics['total_orders']); ?></span>
                        <span class="stat-label"><?php _e('成交订单', 'qilingshop'); ?></span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-value"><?php echo intval($statistics['total_authors']); ?></span>
                        <span class="stat-label"><?php _e('作者数', 'qilingshop'); ?></span>
                    </div>
                </div>
            </div>
            
            <div class="dashboard-section">
                <h2><?php _e('本月数据', 'qilingshop'); ?></h2>
                <div class="stat-cards">
                    <div class="stat-card">
                        <span class="stat-value">¥<?php echo number_format($statistics['month_commission'], 2); ?></span>
                        <span class="stat-label"><?php _e('本月提成', 'qilingshop'); ?></span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-value">¥<?php echo number_format($statistics['month_sales'], 2); ?></span>
                        <span class="stat-label"><?php _e('本月销售', 'qilingshop'); ?></span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-value"><?php echo intval($statistics['month_orders']); ?></span>
                        <span class="stat-label"><?php _e('本月订单', 'qilingshop'); ?></span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-value"><?php echo get_option('qilingshop_author_commission_rate', 80); ?>%</span>
                        <span class="stat-label"><?php _e('提成比例', 'qilingshop'); ?></span>
                    </div>
                </div>
            </div>
            
            <!-- 作者排行 -->
            <?php if (!empty($top_authors)): ?>
            <div class="dashboard-section">
                <h2><?php _e('作者排行榜前 10 名', 'qilingshop'); ?></h2>
                <table class="wp-list-table qls-ui-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th class="qls-admin-col-rank"><?php _e('排名', 'qilingshop'); ?></th>
                            <th><?php _e('作者', 'qilingshop'); ?></th>
                            <th><?php _e('销售额', 'qilingshop'); ?></th>
                            <th><?php _e('提成', 'qilingshop'); ?></th>
                            <th><?php _e('订单数', 'qilingshop'); ?></th>
                            <th><?php _e('操作', 'qilingshop'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rank = 1; foreach ($top_authors as $author): 
                            $user = get_user_by('ID', $author->author_id);
                        ?>
                        <tr>
                            <td><strong><?php echo $rank++; ?></strong></td>
                            <td>
                                <?php if ($user): ?>
                                    <?php echo get_avatar($author->author_id, 24); ?>
                                    <strong><?php echo esc_html($user->display_name); ?></strong>
                                <?php else: ?>
                                    <em><?php _e('用户已删除', 'qilingshop'); ?></em>
                                <?php endif; ?>
                            </td>
                            <td>¥<?php echo number_format($author->total_sales, 2); ?></td>
                            <td class="qls-admin-amount-positive"><strong>¥<?php echo number_format($author->total_commission, 2); ?></strong></td>
                            <td><?php echo intval($author->total_orders); ?></td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=qilingshop-commissions&author_id=' . $author->author_id); ?>"><?php _e('查看明细', 'qilingshop'); ?></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <!-- 提成记录列表 -->
            <div class="dashboard-section">
                <h2>
                    <?php _e('提成明细', 'qilingshop'); ?>
                    <?php if ($author_id): 
                        $filter_user = get_user_by('ID', $author_id);
                    ?>
                    <span class="qls-admin-subtitle-text">
                        - <?php echo esc_html($filter_user ? $filter_user->display_name : sprintf(__('用户ID:%s', 'qilingshop'), $author_id)); ?>
                        <a href="<?php echo admin_url('admin.php?page=qilingshop-commissions'); ?>" class="qls-admin-link-plain">[<?php _e('清除筛选', 'qilingshop'); ?>]</a>
                    </span>
                    <?php endif; ?>
                </h2>
                <table class="wp-list-table qls-ui-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th class="qls-admin-col-rank"><?php _e('ID', 'qilingshop'); ?></th>
                            <th><?php _e('作者', 'qilingshop'); ?></th>
                            <th><?php _e('资源', 'qilingshop'); ?></th>
                            <th><?php _e('购买者', 'qilingshop'); ?></th>
                            <th><?php _e('订单金额', 'qilingshop'); ?></th>
                            <th><?php _e('提成比例', 'qilingshop'); ?></th>
                            <th><?php _e('提成金额', 'qilingshop'); ?></th>
                            <th><?php _e('时间', 'qilingshop'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="8" class="qls-admin-empty-row"><?php _e('暂无提成记录', 'qilingshop'); ?></td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($records as $record): 
                            $author = get_user_by('ID', $record->author_id);
                            $buyer = get_user_by('ID', $record->buyer_id);
                        ?>
                        <tr>
                            <td><?php echo $record->id; ?></td>
                            <td>
                                <?php if ($author): ?>
                                    <?php echo get_avatar($record->author_id, 24); ?>
                                    <strong><?php echo esc_html($author->display_name); ?></strong>
                                <?php else: ?>
                                    <em><?php _e('用户已删除', 'qilingshop'); ?></em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($record->post_id && get_post($record->post_id)): ?>
                                    <a href="<?php echo get_permalink($record->post_id); ?>" target="_blank">
                                        <?php echo esc_html($record->post_title ?: get_the_title($record->post_id)); ?>
                                    </a>
                                <?php else: ?>
                                    <?php echo esc_html($record->post_title ?: __('资源已删除', 'qilingshop')); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($buyer): ?>
                                    <?php echo esc_html($buyer->display_name); ?>
                                <?php else: ?>
                                    <em><?php _e('用户已删除', 'qilingshop'); ?></em>
                                <?php endif; ?>
                            </td>
                            <td>¥<?php echo number_format($record->order_amount, 2); ?></td>
                            <td><?php echo number_format($record->commission_rate, 0); ?>%</td>
                            <td class="qls-admin-amount-positive"><strong>+¥<?php echo number_format($record->commission_amount, 2); ?></strong></td>
                            <td><?php echo esc_html($record->created_at); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <?php
                // 分页
                $total_pages = ceil($total / $per_page);
                if ($total_pages > 1) {
                    echo '<div class="tablenav"><div class="tablenav-pages">';
                    echo paginate_links([
                        'base'    => add_query_arg('paged', '%#%'),
                        'format'  => '',
                        'current' => $paged,
                        'total'   => $total_pages,
                    ]);
                    echo '</div></div>';
                }
                ?>
            </div>
        </div>
        <?php
    }
}

