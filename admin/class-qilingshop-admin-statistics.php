<?php
/**
 * 数据统计后台
 */
if (!defined('ABSPATH')) exit;

class QilingShop_Admin_Statistics {
    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_init', [$this, 'handle_export']);
        add_action('admin_post_qilingshop_cleanup_download_logs', [$this, 'handle_download_log_cleanup']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * 按保留周期清理实际下载行为日志，不删除购买后生成的下载授权。
     */
    public function handle_download_log_cleanup() {
        if (!current_user_can('manage_options')) {
            wp_die(__('权限不足', 'qilingshop'));
        }

        check_admin_referer('qilingshop_cleanup_download_logs');

        $retention_days = isset($_POST['retention_days']) ? absint(wp_unslash($_POST['retention_days'])) : 90;
        if (!in_array($retention_days, [30, 90, 180, 365], true)) {
            $retention_days = 90;
        }

        global $wpdb;
        $table = QilingShop_Database::instance()->get_table('downloads');
        $cutoff = date('Y-m-d H:i:s', current_time('timestamp') - ($retention_days * DAY_IN_SECONDS));
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table}
             WHERE id IN (
                 SELECT id FROM (
                     SELECT id
                     FROM {$table}
                     WHERE order_no IS NULL AND created_at < %s
                     ORDER BY id ASC
                     LIMIT 5000
                 ) AS cleanup_ids
             )",
            $cutoff
        ));

        $message = $deleted === false
            ? __('下载日志清理失败，请稍后重试', 'qilingshop')
            : sprintf(__('已清理 %d 条下载行为日志，购买下载权限未受影响。', 'qilingshop'), (int) $deleted);
        $type = $deleted === false ? 'error' : 'success';

        wp_safe_redirect(add_query_arg([
            'page' => 'qilingshop-statistics',
            'download_cleanup_message' => $message,
            'download_cleanup_type' => $type,
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * 判断是否统计页。
     *
     * @param string $hook 当前后台钩子。
     * @return bool
     */
    private function is_statistics_page($hook = '') {
        $hook = (string) $hook;
        if ($hook === 'qilingshop_page_qilingshop-statistics') {
            return true;
        }

        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        return $page === 'qilingshop-statistics';
    }

    /**
     * 仅在统计页按需加载图表资源。
     *
     * @param string $hook 当前后台钩子。
     */
    public function enqueue_assets($hook) {
        if (!$this->is_statistics_page($hook)) {
            return;
        }

        wp_enqueue_script(
            'qilingshop-chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
            [],
            '4.4.1',
            true
        );

        $stats_js_version = QILINGSHOP_VERSION;
        $stats_js_file = QILINGSHOP_PATH . 'static/js/qilingshop-admin-statistics.js';
        if (file_exists($stats_js_file)) {
            $stats_js_version .= '.' . (string) filemtime($stats_js_file);
        }

        wp_enqueue_script(
            'qilingshop-admin-statistics',
            QILINGSHOP_URL . 'static/js/qilingshop-admin-statistics.js',
            ['qilingshop-chart-js'],
            $stats_js_version,
            true
        );
    }

    public function render() {
        global $wpdb;
        $db = QilingShop_Database::instance();
        $orders_table = $db->get_table('orders');
        $recharge_table = $db->get_table('recharge');
        $downloads_table = $db->get_table('downloads');

        // 参数处理
        $period = isset($_GET['period']) ? sanitize_text_field($_GET['period']) : '30day';
        $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
        $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';
        
        // 计算日期范围
        if ($period == 'custom' && $start_date && $end_date) {
            // 自定义范围，保持不变
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

        // 确保 start_date <= end_date
        if (strtotime($start_date) > strtotime($end_date)) {
            $temp = $start_date; $start_date = $end_date; $end_date = $temp;
        }
        $range_start = $start_date . ' 00:00:00';
        $range_end = date('Y-m-d H:i:s', strtotime($end_date . ' +1 day'));

        // 决定分组方式 (天/月)
        $days_diff = (strtotime($end_date) - strtotime($start_date)) / 86400;
        $group_by = ($days_diff > 60 || $period == 'year') ? 'month' : 'day';
        $date_format_sql = ($group_by == 'month') ? '%Y-%m' : '%Y-%m-%d';
        $php_date_format = ($group_by == 'month') ? 'Y-m' : 'Y-m-d';
        $interval_spec = ($group_by == 'month') ? 'P1M' : 'P1D';

        // --- 数据查询 ---

        // 1. 全量概览数据
        $order_users = $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$orders_table} WHERE status = 1 AND user_id > 0");
        $total_paying_users = $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id) FROM (
                SELECT user_id FROM {$orders_table} WHERE status = 1 AND user_id > 0
                UNION
                SELECT user_id FROM {$recharge_table} WHERE status = 1 AND user_id > 0
            ) as paying_users"
        );
        $total_order_income = $wpdb->get_var("SELECT SUM(final_price) FROM {$orders_table} WHERE status = 1") ?: 0;
        $total_recharge = $wpdb->get_var("SELECT SUM(amount) FROM {$recharge_table} WHERE status = 1") ?: 0;

        // 2. 生成图表时间轴
        $chart_data = [];
        $period_obj = new DatePeriod(
            new DateTime($start_date),
            new DateInterval($interval_spec),
            (new DateTime($end_date))->modify('+1 day') // include end date needs logic adjustment for month
        );
        
        // Fix DatePeriod for months to include last month correctly if not exact match
        if ($group_by == 'month') {
             $start_dt = new DateTime(date('Y-m-01', strtotime($start_date)));
             $end_dt = new DateTime(date('Y-m-01', strtotime($end_date)));
             $end_dt->modify('+1 month'); // Include the end month
             $period_obj = new DatePeriod($start_dt, new DateInterval('P1M'), $end_dt);
        }

        foreach ($period_obj as $dt) {
            $d_str = $dt->format($php_date_format);
            $chart_data[$d_str] = ['date' => $d_str, 'orders' => 0, 'income' => 0, 'recharge' => 0, 'users' => 0, 'downloads' => 0];
        }

        // 3. 范围统计查询 (动态 Group By)
        
        // 订单统计
        $order_sql = $wpdb->prepare(
            "SELECT DATE_FORMAT(paid_at, %s) as date_key, COUNT(*) as count, SUM(final_price) as total, COUNT(DISTINCT user_id) as users
             FROM {$orders_table} 
             WHERE status = 1 AND paid_at >= %s AND paid_at < %s 
             GROUP BY date_key",
            $date_format_sql, $range_start, $range_end
        );
        $order_stats = $wpdb->get_results($order_sql);
        
        foreach ($order_stats as $row) {
            if (isset($chart_data[$row->date_key])) {
                $chart_data[$row->date_key]['orders'] = $row->count;
                $chart_data[$row->date_key]['income'] = $row->total;
                $chart_data[$row->date_key]['users'] = $row->users;
            }
        }

        // 充值统计
        $recharge_sql = $wpdb->prepare(
            "SELECT DATE_FORMAT(paid_at, %s) as date_key, SUM(amount) as total 
             FROM {$recharge_table} 
             WHERE status = 1 AND paid_at >= %s AND paid_at < %s 
             GROUP BY date_key",
             $date_format_sql, $range_start, $range_end
        );
        $recharge_stats = $wpdb->get_results($recharge_sql);
        
        foreach ($recharge_stats as $row) {
            if (isset($chart_data[$row->date_key])) {
                $chart_data[$row->date_key]['recharge'] = $row->total;
            }
        }

        // 带 order_no 的记录是购买后生成的下载权限，仅统计实际点击下载日志。
        $download_summary = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS total,
                    COUNT(DISTINCT post_id) AS resources,
                    SUM(CASE WHEN is_vip_free = 1 THEN 1 ELSE 0 END) AS vip_free,
                    SUM(CASE WHEN user_id > 0 THEN 1 ELSE 0 END) AS members,
                    SUM(CASE WHEN user_id = 0 THEN 1 ELSE 0 END) AS guests
             FROM {$downloads_table}
             WHERE order_no IS NULL AND created_at >= %s AND created_at < %s",
            $range_start,
            $range_end
        ));
        $download_summary = $download_summary ?: (object) [
            'total' => 0,
            'resources' => 0,
            'vip_free' => 0,
            'members' => 0,
            'guests' => 0,
        ];

        $download_stats = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE_FORMAT(created_at, %s) AS date_key, COUNT(*) AS count
             FROM {$downloads_table}
             WHERE order_no IS NULL AND created_at >= %s AND created_at < %s
             GROUP BY date_key",
            $date_format_sql,
            $range_start,
            $range_end
        ));
        foreach ((array) $download_stats as $row) {
            if (isset($chart_data[$row->date_key])) {
                $chart_data[$row->date_key]['downloads'] = (int) $row->count;
            }
        }

        $top_download_resources = $wpdb->get_results($wpdb->prepare(
            "SELECT d.post_id, p.post_title, COUNT(*) AS downloads,
                    COUNT(DISTINCT CASE WHEN d.user_id > 0 THEN d.user_id END) AS users,
                    SUM(CASE WHEN d.is_vip_free = 1 THEN 1 ELSE 0 END) AS vip_free
             FROM {$downloads_table} d
             LEFT JOIN {$wpdb->posts} p ON p.ID = d.post_id
             WHERE d.order_no IS NULL AND d.created_at >= %s AND d.created_at < %s
             GROUP BY d.post_id, p.post_title
             ORDER BY downloads DESC
             LIMIT 20",
            $range_start,
            $range_end
        ));

        $recent_downloads = $wpdb->get_results($wpdb->prepare(
            "SELECT d.id, d.post_id, d.download_index, d.user_id, d.is_vip_free,
                    d.ip_address, d.created_at, p.post_title, u.display_name
             FROM {$downloads_table} d
             LEFT JOIN {$wpdb->posts} p ON p.ID = d.post_id
             LEFT JOIN {$wpdb->users} u ON u.ID = d.user_id
             WHERE d.order_no IS NULL AND d.created_at >= %s AND d.created_at < %s
             ORDER BY d.id DESC
             LIMIT 50",
            $range_start,
            $range_end
        ));

        $labels = array_keys($chart_data);
        $incomes = array_column(array_values($chart_data), 'income');
        $recharges = array_column(array_values($chart_data), 'recharge');
        $downloads = array_column(array_values($chart_data), 'downloads');

        // 统计图数据注入到独立脚本（避免模板内联脚本）。
        wp_localize_script('qilingshop-admin-statistics', 'qilingshopStatsChart', [
            'labels'    => array_values($labels),
            'incomes'   => array_map('floatval', $incomes),
            'recharges' => array_map('floatval', $recharges),
            'downloads' => array_map('intval', $downloads),
            'i18n'      => [
                'orderIncome' => __('订单收入', 'qilingshop'),
                'recharge'    => __('充值金额', 'qilingshop'),
                'downloads'   => __('下载次数', 'qilingshop'),
            ],
        ]);

        // --- 用户排行榜 ---
        $table_users = $wpdb->users;
        
        // 充值排行
        $top_rechargers = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT u.display_name, r.user_id, COUNT(*) as count, SUM(r.amount) as total
                 FROM {$recharge_table} r
                 LEFT JOIN {$table_users} u ON r.user_id = u.ID
                 WHERE r.status = 1 AND r.paid_at >= %s AND r.paid_at < %s
                 GROUP BY r.user_id
                 ORDER BY total DESC
                 LIMIT 10",
                $range_start, $range_end
            )
        );

        // 消费排行
        $top_spenders = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT u.display_name, o.user_id, COUNT(*) as count, SUM(o.final_price) as total
                 FROM {$orders_table} o
                 LEFT JOIN {$table_users} u ON o.user_id = u.ID
                 WHERE o.status = 1 AND o.paid_at >= %s AND o.paid_at < %s
                 GROUP BY o.user_id
                 ORDER BY total DESC
                 LIMIT 10",
                $range_start, $range_end
            )
        );

        ?>
        <div class="wrap qilingshop-admin-page qilingshop-vr-page qilingshop-statistics-page">
            <h1 class="wp-heading-inline"><?php _e('数据统计', 'qilingshop'); ?></h1>

            <?php if (!empty($_GET['download_cleanup_message'])): ?>
                <?php $cleanup_type = isset($_GET['download_cleanup_type']) && $_GET['download_cleanup_type'] === 'error' ? 'notice-error' : 'notice-success'; ?>
                <div class="notice <?php echo esc_attr($cleanup_type); ?> is-dismissible"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['download_cleanup_message']))); ?></p></div>
            <?php endif; ?>
            
            <!-- 筛选栏 -->
            <div class="qls-filter-bar">
                <div class="qls-quick-filters">
                    <?php 
                    $periods = [
                        'today' => __('今天', 'qilingshop'),
                        'yesterday' => __('昨天', 'qilingshop'),
                        '30day' => __('最近30天', 'qilingshop'),
                        'month' => __('本月', 'qilingshop'),
                        'year' => __('本年', 'qilingshop'),
                    ];
                    foreach ($periods as $k => $label): 
                        $active = ($period === $k) ? 'active' : '';
                    ?>
                    <a href="<?php echo add_query_arg(['period' => $k], remove_query_arg(['start_date', 'end_date'])); ?>" class="qls-quick-btn <?php echo $active; ?>"><?php echo esc_html($label); ?></a>
                    <?php endforeach; ?>
                </div>

                <div class="qls-admin-stats-actions">
                    <form method="get" class="qls-date-inputs">
                        <input type="hidden" name="page" value="qilingshop-statistics">
                        <input type="hidden" name="period" value="custom">
                        <span class="qls-admin-stats-custom-label"><?php _e('自定义:', 'qilingshop'); ?></span>
                        <input type="date" name="start_date" value="<?php echo esc_attr($start_date); ?>">
                        <span>-</span>
                        <input type="date" name="end_date" value="<?php echo esc_attr($end_date); ?>">
                        <button type="submit" class="button"><?php _e('查询', 'qilingshop'); ?></button>
                    </form>
                    <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('export', 'points_stats'), 'qilingshop_export_points_stats')); ?>" target="_blank" class="button button-secondary">
                        <span class="dashicons dashicons-download qls-admin-inline-icon"></span> <?php _e('导出表格', 'qilingshop'); ?>
                    </a>
                </div>
            </div>
            
            <!-- 总览卡片 -->
            <div class="qls-admin-stats-overview">
                <div class="qls-admin-stats-card">
                    <div class="qls-admin-stats-card-label"><?php _e('付费用户数 (总)', 'qilingshop'); ?></div>
                    <div class="qls-admin-stats-card-value qls-admin-stats-card-value-users"><?php echo intval($total_paying_users); ?></div>
                </div>
                <div class="qls-admin-stats-card">
                    <div class="qls-admin-stats-card-label"><?php _e('订单总收入 (总)', 'qilingshop'); ?></div>
                    <div class="qls-admin-stats-card-value qls-admin-stats-card-value-income"><?php echo qilingshop_format_price($total_order_income); ?></div>
                </div>
                <div class="qls-admin-stats-card">
                    <div class="qls-admin-stats-card-label"><?php _e('充值总金额 (总)', 'qilingshop'); ?></div>
                    <div class="qls-admin-stats-card-value qls-admin-stats-card-value-recharge"><?php echo qilingshop_format_price($total_recharge); ?></div>
                </div>
            </div>
            
            <!-- 图表 -->
            <div class="qls-admin-stats-chart-panel">
                <canvas id="qilingshop-chart" height="300" class="qls-admin-stats-chart-canvas"></canvas>
            </div>

            <h2><?php printf(__('资源下载统计（%s 至 %s）', 'qilingshop'), esc_html($start_date), esc_html($end_date)); ?></h2>
            <div class="qls-admin-stats-overview">
                <div class="qls-admin-stats-card">
                    <div class="qls-admin-stats-card-label"><?php _e('实际下载次数', 'qilingshop'); ?></div>
                    <div class="qls-admin-stats-card-value"><?php echo (int) $download_summary->total; ?></div>
                </div>
                <div class="qls-admin-stats-card">
                    <div class="qls-admin-stats-card-label"><?php _e('被下载资源数', 'qilingshop'); ?></div>
                    <div class="qls-admin-stats-card-value"><?php echo (int) $download_summary->resources; ?></div>
                </div>
                <div class="qls-admin-stats-card">
                    <div class="qls-admin-stats-card-label"><?php _e('VIP 免费下载', 'qilingshop'); ?></div>
                    <div class="qls-admin-stats-card-value"><?php echo (int) $download_summary->vip_free; ?></div>
                </div>
                <div class="qls-admin-stats-card">
                    <div class="qls-admin-stats-card-label"><?php _e('登录用户 / 游客', 'qilingshop'); ?></div>
                    <div class="qls-admin-stats-card-value"><?php echo (int) $download_summary->members; ?> / <?php echo (int) $download_summary->guests; ?></div>
                </div>
            </div>

            <div class="qls-admin-stats-ranks">
                <div class="qls-admin-stats-rank-card">
                    <h2 class="qls-admin-stats-rank-title"><?php _e('资源下载排行', 'qilingshop'); ?></h2>
                    <table class="wp-list-table qls-ui-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('资源', 'qilingshop'); ?></th>
                                <th><?php _e('下载次数', 'qilingshop'); ?></th>
                                <th><?php _e('用户数', 'qilingshop'); ?></th>
                                <th><?php _e('VIP免费', 'qilingshop'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($top_download_resources)): ?>
                            <tr><td colspan="4"><?php _e('该时间范围内暂无下载记录', 'qilingshop'); ?></td></tr>
                        <?php else: foreach ($top_download_resources as $item): ?>
                            <tr>
                                <td><a href="<?php echo esc_url(get_edit_post_link((int) $item->post_id)); ?>"><?php echo esc_html($item->post_title ?: ('#' . (int) $item->post_id)); ?></a></td>
                                <td><?php echo (int) $item->downloads; ?></td>
                                <td><?php echo (int) $item->users; ?></td>
                                <td><?php echo (int) $item->vip_free; ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="qls-admin-stats-rank-card">
                    <h2 class="qls-admin-stats-rank-title"><?php _e('最近下载明细', 'qilingshop'); ?></h2>
                    <table class="wp-list-table qls-ui-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('时间', 'qilingshop'); ?></th>
                                <th><?php _e('资源', 'qilingshop'); ?></th>
                                <th><?php _e('下载者', 'qilingshop'); ?></th>
                                <th><?php _e('类型', 'qilingshop'); ?></th>
                                <th><?php _e('下载项 / IP', 'qilingshop'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($recent_downloads)): ?>
                            <tr><td colspan="5"><?php _e('该时间范围内暂无下载记录', 'qilingshop'); ?></td></tr>
                        <?php else: foreach ($recent_downloads as $item): ?>
                            <tr>
                                <td><?php echo esc_html($item->created_at); ?></td>
                                <td><a href="<?php echo esc_url(get_edit_post_link((int) $item->post_id)); ?>"><?php echo esc_html($item->post_title ?: ('#' . (int) $item->post_id)); ?></a></td>
                                <td><?php echo esc_html($item->user_id > 0 ? ($item->display_name ?: ('User#' . (int) $item->user_id)) : __('游客', 'qilingshop')); ?></td>
                                <td><?php echo $item->is_vip_free ? esc_html__('VIP免费', 'qilingshop') : esc_html__('普通下载', 'qilingshop'); ?></td>
                                <td>#<?php echo (int) $item->download_index; ?> / <?php echo esc_html($item->ip_address ?: '-'); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <h2><?php _e('下载日志清理', 'qilingshop'); ?></h2>
            <p class="description"><?php _e('仅删除实际点击下载产生的统计日志，不删除购买记录和用户下载权限。每次最多清理 5000 条，可重复执行。', 'qilingshop'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('确定清理所选保留周期以前的下载统计日志吗？此操作无法撤销。', 'qilingshop')); ?>');">
                <input type="hidden" name="action" value="qilingshop_cleanup_download_logs">
                <?php wp_nonce_field('qilingshop_cleanup_download_logs'); ?>
                <label>
                    <?php _e('保留最近', 'qilingshop'); ?>
                    <select name="retention_days">
                        <option value="30"><?php _e('30 天', 'qilingshop'); ?></option>
                        <option value="90" selected><?php _e('90 天', 'qilingshop'); ?></option>
                        <option value="180"><?php _e('180 天', 'qilingshop'); ?></option>
                        <option value="365"><?php _e('365 天', 'qilingshop'); ?></option>
                    </select>
                </label>
                <button type="submit" class="button button-secondary"><?php _e('清理过期下载日志', 'qilingshop'); ?></button>
            </form>

            <!-- 排行榜 -->
            <div class="qls-admin-stats-ranks">
                <div class="qls-admin-stats-rank-card">
                    <h2 class="qls-admin-stats-rank-title"><?php _e('充值排行前 10 名', 'qilingshop'); ?></h2>
                    <table class="wp-list-table qls-ui-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('用户', 'qilingshop'); ?></th>
                                <th class="qls-admin-text-right"><?php _e('充值次数', 'qilingshop'); ?></th>
                                <th class="qls-admin-text-right"><?php _e('总金额', 'qilingshop'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($top_rechargers)): ?>
                            <tr><td colspan="3"><?php _e('暂无数据', 'qilingshop'); ?></td></tr>
                            <?php else: ?>
                            <?php foreach ($top_rechargers as $u): ?>
                            <tr>
                                <td><?php echo esc_html($u->display_name ?: 'User#' . $u->user_id); ?></td>
                                <td class="qls-admin-text-right"><?php echo intval($u->count); ?></td>
                                <td class="qls-admin-text-right"><?php echo qilingshop_format_price($u->total); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="qls-admin-stats-rank-card">
                    <h2 class="qls-admin-stats-rank-title"><?php _e('消费排行前 10 名', 'qilingshop'); ?></h2>
                    <table class="wp-list-table qls-ui-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('用户', 'qilingshop'); ?></th>
                                <th class="qls-admin-text-right"><?php _e('订单数', 'qilingshop'); ?></th>
                                <th class="qls-admin-text-right"><?php _e('总金额', 'qilingshop'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($top_spenders)): ?>
                            <tr><td colspan="3"><?php _e('暂无数据', 'qilingshop'); ?></td></tr>
                            <?php else: ?>
                            <?php foreach ($top_spenders as $u): ?>
                            <tr>
                                <td><?php echo esc_html($u->display_name ?: 'User#' . $u->user_id); ?></td>
                                <td class="qls-admin-text-right"><?php echo intval($u->count); ?></td>
                                <td class="qls-admin-text-right"><?php echo qilingshop_format_price($u->total); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- 表格 -->
            <h2><?php printf(__('统计明细 (%s 至 %s) - 按%s统计', 'qilingshop'), $start_date, $end_date, ($group_by=='month'?__('月','qilingshop'):__('天','qilingshop'))); ?></h2>
            <table class="wp-list-table qls-ui-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php echo ($group_by=='month') ? __('月份', 'qilingshop') : __('日期', 'qilingshop'); ?></th>
                        <th><?php _e('订单数', 'qilingshop'); ?></th>
                        <th><?php _e('付费用户', 'qilingshop'); ?></th>
                        <th><?php _e('订单收入', 'qilingshop'); ?></th>
                        <th><?php _e('充值金额', 'qilingshop'); ?></th>
                        <th><?php _e('下载次数', 'qilingshop'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($chart_data)): ?>
                    <tr><td colspan="6"><?php _e('暂无数据', 'qilingshop'); ?></td></tr>
                    <?php else: ?>
                    <?php foreach (array_reverse($chart_data) as $day): ?>
                    <tr>
                        <td><?php echo $day['date']; ?></td>
                        <td><?php echo $day['orders']; ?></td>
                        <td><?php echo $day['users']; ?></td>
                        <td><?php echo qilingshop_format_price($day['income']); ?></td>
                        <td><?php echo qilingshop_format_price($day['recharge']); ?></td>
                        <td><?php echo (int) $day['downloads']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function handle_export() {
        if (!isset($_GET['export']) || $_GET['export'] !== 'points_stats') {
            return;
        }
        
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'qilingshop_export_points_stats')) {
            return;
        }

        global $wpdb;
        $db = QilingShop_Database::instance();
        $orders_table = $db->get_table('orders');
        $recharge_table = $db->get_table('recharge');
        $table_users = $wpdb->users;

        // 参数处理 (复制 render 逻辑)
        $period = isset($_GET['period']) ? sanitize_text_field($_GET['period']) : '30day';
        $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
        $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';
        
        switch ($period) {
            case 'today': $start_date = current_time('Y-m-d'); $end_date = current_time('Y-m-d'); break;
            case 'yesterday': $start_date = date('Y-m-d', strtotime('-1 day')); $end_date = date('Y-m-d', strtotime('-1 day')); break;
            case 'month': $start_date = date('Y-m-01'); $end_date = current_time('Y-m-d'); break;
            case 'year': $start_date = date('Y-01-01'); $end_date = current_time('Y-m-d'); break;
            case 'custom': break; 
            default: $start_date = date('Y-m-d', strtotime('-29 days')); $end_date = current_time('Y-m-d'); break;
        }
        if (strtotime($start_date) > strtotime($end_date)) { $temp = $start_date; $start_date = $end_date; $end_date = $temp; }
        $range_start = $start_date . ' 00:00:00';
        $range_end = date('Y-m-d H:i:s', strtotime($end_date . ' +1 day'));

        if (ob_get_length()) ob_end_clean();

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="points_stats_' . $start_date . '_' . $end_date . '.csv"');
        echo "\xEF\xBB\xBF";
        
        $fp = fopen('php://output', 'w');

        // 查询每日数据
        $days_diff = (strtotime($end_date) - strtotime($start_date)) / 86400;
        $group_by = ($days_diff > 60 || $period == 'year') ? 'month' : 'day';
        $date_format_sql = ($group_by == 'month') ? '%Y-%m' : '%Y-%m-%d';
        
        // 每日订单
        $order_stats = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE_FORMAT(paid_at, %s) as date_key, COUNT(*) as count, SUM(final_price) as total, COUNT(DISTINCT user_id) as users
             FROM {$orders_table} WHERE status = 1 AND paid_at >= %s AND paid_at < %s GROUP BY date_key",
            $date_format_sql, $range_start, $range_end
        ));
        $recharge_stats = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE_FORMAT(paid_at, %s) as date_key, SUM(amount) as total 
             FROM {$recharge_table} WHERE status = 1 AND paid_at >= %s AND paid_at < %s GROUP BY date_key",
            $date_format_sql, $range_start, $range_end
        ));
        
        // 合并数据 (简化版)
        $chart_data = [];
        foreach ($order_stats as $o) {
            $chart_data[$o->date_key]['orders'] = $o->count;
            $chart_data[$o->date_key]['income'] = $o->total;
            $chart_data[$o->date_key]['users'] = $o->users;
        }
        foreach ($recharge_stats as $r) {
            $chart_data[$r->date_key]['recharge'] = $r->total;
        }

        $this->put_export_csv_row($fp, [sprintf(__('统计明细 (%1$s 至 %2$s)', 'qilingshop'), $start_date, $end_date)]);
        $this->put_export_csv_row($fp, [__('日期', 'qilingshop'), __('订单数', 'qilingshop'), __('付费用户', 'qilingshop'), __('订单收入', 'qilingshop'), __('充值金额', 'qilingshop')]);
        
        // 导出数据按实际记录输出，不额外补齐空日期。
        // But for export, sparse data is often acceptable or better. Can refine if needed.
        foreach ($chart_data as $date => $row) {
            $this->put_export_csv_row($fp, [
                $date, 
                $row['orders'] ?? 0, 
                $row['users'] ?? 0, 
                $row['income'] ?? 0, 
                $row['recharge'] ?? 0
            ]);
        }
        $this->put_export_csv_row($fp, []);

        // 排行
        $top_rechargers = $wpdb->get_results($wpdb->prepare(
            "SELECT u.display_name, r.user_id, COUNT(*) as count, SUM(r.amount) as total
             FROM {$recharge_table} r LEFT JOIN {$table_users} u ON r.user_id = u.ID
             WHERE r.status = 1 AND r.paid_at >= %s AND r.paid_at < %s
             GROUP BY r.user_id ORDER BY total DESC LIMIT 10",
            $range_start, $range_end
        ));
        
        $this->put_export_csv_row($fp, [__('充值排行前 10 名', 'qilingshop')]);
        $this->put_export_csv_row($fp, [__('用户', 'qilingshop'), __('充值次数', 'qilingshop'), __('充值总额', 'qilingshop')]);
        foreach ($top_rechargers as $u) {
            $this->put_export_csv_row($fp, [$u->display_name ?: 'User#' . $u->user_id, $u->count, $u->total]);
        }
        
        fclose($fp);
        exit;
    }

    private function put_export_csv_row($fp, $row) {
        if (function_exists('qilingshop_fputcsv_safe')) {
            qilingshop_fputcsv_safe($fp, (array) $row);
            return;
        }

        fputcsv($fp, array_map([$this, 'normalize_export_cell'], (array) $row));
    }

    private function normalize_export_cell($value) {
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
}

