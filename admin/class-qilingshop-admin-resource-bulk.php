<?php
/**
 * 资源批量操作后台页面
 */
if (!defined('ABSPATH')) exit;

class QilingShop_Admin_Resource_Bulk {
    private static $instance = null;
    private $marker_rebuild_recommended = false;

    public static function instance() {
        if (is_null(self::$instance)) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_post_qilingshop_rebuild_resource_markers', [$this, 'maybe_handle_resource_marker_rebuild']);
    }

    /**
     * 获取资源允许的文章类型
     *
     * @return array
     */
    private function get_resource_post_types() {
        return qilingshop_normalize_resource_post_types(get_option('qilingshop_post_types', ['post']), ['post']);
    }

    /**
     * 可用操作项
     *
     * @return array
     */
    private function get_action_options() {
        return [
            'set_free' => __('批量改为免费', 'qilingshop'),
            'set_paid_download' => __('批量改为收费（下载）', 'qilingshop'),
            'set_sale_mode_download' => __('批量设置销售模式：付费下载', 'qilingshop'),
            'set_sale_mode_view' => __('批量设置销售模式：付费查看', 'qilingshop'),
            'set_price_download' => __('批量设置下载价', 'qilingshop'),
            'set_price_view' => __('批量设置查看价', 'qilingshop'),
            'set_price_both' => __('批量设置下载价/查看价（同价）', 'qilingshop'),
            'set_vip_free_download' => __('批量设置 VIP 免费下载', 'qilingshop'),
            'disable_vip_free' => __('批量取消 VIP 免费', 'qilingshop'),
            'enable_resource' => __('批量启用积分付费资源', 'qilingshop'),
            'disable_resource' => __('批量关闭积分付费资源', 'qilingshop'),
        ];
    }

    /**
     * 处理请求参数
     *
     * @return array
     */
    private function get_filters() {
        $resource_post_types = $this->get_resource_post_types();

        $post_type_filter = isset($_GET['post_type_filter']) ? sanitize_key((string) wp_unslash($_GET['post_type_filter'])) : 'all';
        if ($post_type_filter !== 'all' && !in_array($post_type_filter, $resource_post_types, true)) {
            $post_type_filter = 'all';
        }

        $sale_mode_filter = isset($_GET['sale_mode_filter']) ? sanitize_key((string) wp_unslash($_GET['sale_mode_filter'])) : 'all';
        if (!in_array($sale_mode_filter, ['all', 'free', 'download', 'view'], true)) {
            $sale_mode_filter = 'all';
        }

        $enabled_filter = isset($_GET['enabled_filter']) ? sanitize_key((string) wp_unslash($_GET['enabled_filter'])) : 'all';
        if (!in_array($enabled_filter, ['all', 'enabled', 'disabled'], true)) {
            $enabled_filter = 'all';
        }

        $keyword = isset($_GET['s']) ? sanitize_text_field((string) wp_unslash($_GET['s'])) : '';

        $per_page = isset($_GET['per_page']) ? absint($_GET['per_page']) : 20;
        if (!in_array($per_page, [20, 50, 100], true)) {
            $per_page = 20;
        }

        $paged = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
        if ($paged < 1) {
            $paged = 1;
        }

        return [
            'post_type_filter' => $post_type_filter,
            'sale_mode_filter' => $sale_mode_filter,
            'enabled_filter' => $enabled_filter,
            'keyword' => $keyword,
            'per_page' => $per_page,
            'paged' => $paged,
            'resource_post_types' => $resource_post_types,
        ];
    }

    /**
     * 获取当前筛选后的文章类型。
     *
     * @param array $filters
     * @return array
     */
    private function get_selected_post_types($filters) {
        $post_types = $filters['post_type_filter'] === 'all'
            ? (array) $filters['resource_post_types']
            : [(string) $filters['post_type_filter']];

        return array_values(array_filter(array_map('sanitize_key', $post_types)));
    }

    /**
     * 获取资源实例。
     *
     * @return QilingShop_Resource|null
     */
    private function get_resource_instance() {
        if (!class_exists('QilingShop_Resource')) {
            return null;
        }

        try {
            return QilingShop_Resource::instance();
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * 获取资源标记 meta key。
     *
     * @return string
     */
    private function get_resource_marker_key() {
        return defined('QilingShop_Resource::META_IS_RESOURCE')
            ? QilingShop_Resource::META_IS_RESOURCE
            : '_qilingshop_is_resource';
    }

    /**
     * 获取资源页涉及的文章状态。
     *
     * @return array
     */
    private function get_resource_post_statuses() {
        return ['publish', 'draft', 'pending', 'future', 'private'];
    }

    /**
     * 同步单篇文章的资源标记。
     *
     * @param int $post_id
     * @return void
     */
    private function sync_resource_marker($post_id) {
        $resource = $this->get_resource_instance();
        if ($resource && method_exists($resource, 'sync_resource_marker')) {
            $resource->sync_resource_marker($post_id);
        }
    }

    /**
     * 判断当前文章类型下是否已经存在资源标记。
     *
     * @param array $post_types
     * @return bool
     */
    private function has_resource_markers($post_types) {
        global $wpdb;

        $post_types = array_values(array_filter(array_map('sanitize_key', (array) $post_types)));
        if (empty($post_types)) {
            return false;
        }

        $statuses = $this->get_resource_post_statuses();
        $type_placeholders = implode(', ', array_fill(0, count($post_types), '%s'));
        $status_placeholders = implode(', ', array_fill(0, count($statuses), '%s'));
        $sql = "
            SELECT 1
            FROM {$wpdb->posts} p
            WHERE p.post_type IN ({$type_placeholders})
              AND p.post_status IN ({$status_placeholders})
              AND EXISTS (
                    SELECT 1
                    FROM {$wpdb->postmeta} pm_marker
                    WHERE pm_marker.post_id = p.ID
                      AND pm_marker.meta_key = %s
                      AND pm_marker.meta_value = '1'
              )
            LIMIT 1
        ";

        $params = array_merge($post_types, $statuses, [$this->get_resource_marker_key()]);
        $prepared = $wpdb->prepare($sql, $params);

        return (bool) $wpdb->get_var($prepared);
    }

    /**
     * 生成资源标记重建链接。
     *
     * @param int $offset
     * @return string
     */
    private function get_resource_marker_rebuild_url($offset = 0, $last_id = 0) {
        $url = add_query_arg([
            'action' => 'qilingshop_rebuild_resource_markers',
            'marker_offset' => max(0, (int) $offset),
            'marker_last_id' => max(0, (int) $last_id),
        ], admin_url('admin-post.php'));

        // 保持原始查询分隔符；该地址既用于 HTML，也用于服务端分批重定向。
        return add_query_arg(
            '_wpnonce',
            wp_create_nonce('qilingshop_rebuild_resource_markers'),
            $url
        );
    }

    /**
     * 分批重建历史资源标记。
     *
     * @return void
     */
    public function maybe_handle_resource_marker_rebuild() {
        global $wpdb;

        if (!current_user_can('manage_options')) {
            wp_die(__('权限不足', 'qilingshop'));
        }

        check_admin_referer('qilingshop_rebuild_resource_markers');
        update_option('qilingshop_resource_marker_index_ready', 0, false);

        $offset = max(0, absint($_GET['marker_offset'] ?? 0));
        $last_id = max(0, absint($_GET['marker_last_id'] ?? 0));
        $limit = 200;

        $post_types = $this->get_resource_post_types();
        $statuses = $this->get_resource_post_statuses();
        $type_placeholders = implode(', ', array_fill(0, count($post_types), '%s'));
        $status_placeholders = implode(', ', array_fill(0, count($statuses), '%s'));
        $sql = "
            SELECT p.ID
            FROM {$wpdb->posts} p
            WHERE p.ID > %d
              AND p.post_type IN ({$type_placeholders})
              AND p.post_status IN ({$status_placeholders})
              AND (
                    p.post_content LIKE %s
                    OR p.post_content LIKE %s
                    OR p.post_content LIKE %s
                    OR EXISTS (
                        SELECT 1
                        FROM {$wpdb->postmeta} pm_resource
                        WHERE pm_resource.post_id = p.ID
                          AND (
                                (pm_resource.meta_key = '_qilingshop_is_resource' AND pm_resource.meta_value = '1')
                                OR (pm_resource.meta_key = '_qilingshop_download_urls' AND pm_resource.meta_value <> '')
                                OR (pm_resource.meta_key = '_qilingshop_hidden_content' AND pm_resource.meta_value <> '')
                                OR (
                                    pm_resource.meta_key IN (
                                        '_qilingshop_price_download',
                                        '_qilingshop_price_view',
                                        '_qilingshop_price',
                                        '_qilingshop_price_rmb_download',
                                        '_qilingshop_price_rmb_view',
                                        '_qilingshop_price_rmb'
                                    )
                                    AND CAST(pm_resource.meta_value AS DECIMAL(20,6)) > 0
                                )
                          )
                    )
              )
            ORDER BY p.ID ASC
            LIMIT %d
        ";
        $params = array_merge(
            [$last_id],
            $post_types,
            $statuses,
            ['%[qls_content%', '%[qilingshop_hidden%', '%<!--qls_content_start-->%', $limit]
        );
        $post_ids = array_map('absint', (array) $wpdb->get_col($wpdb->prepare($sql, $params)));
        $post_ids = array_values(array_filter($post_ids));

        foreach ((array) $post_ids as $post_id) {
            $this->sync_resource_marker((int) $post_id);
        }

        $processed = count((array) $post_ids);
        if ($processed === $limit) {
            nocache_headers();
            $next_offset = $offset + $processed;
            $next_last_id = (int) end($post_ids);
            $next_url = $this->get_resource_marker_rebuild_url($next_offset, $next_last_id);
            $progress_message = sprintf(
                __('已重建 %d 篇文章，正在继续处理下一批，请勿关闭页面。', 'qilingshop'),
                $next_offset
            );
            ?>
            <!doctype html>
            <html <?php language_attributes(); ?>>
            <head>
                <meta charset="<?php bloginfo('charset'); ?>">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title><?php esc_html_e('正在重建资源标记', 'qilingshop'); ?></title>
                <style>
                    body{margin:0;background:#f0f0f1;color:#1d2327;font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
                    .qls-rebuild-progress{max-width:520px;margin:12vh auto;padding:32px;background:#fff;border:1px solid #c3c4c7;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,.08);text-align:center}
                    .qls-rebuild-progress h1{margin:0 0 12px;font-size:22px}
                    .qls-rebuild-progress p{margin:0;color:#50575e}
                    .qls-rebuild-progress-bar{height:6px;margin-top:22px;overflow:hidden;background:#dcdcde;border-radius:3px}
                    .qls-rebuild-progress-bar span{display:block;width:35%;height:100%;background:#2271b1;border-radius:3px;animation:qls-rebuild-moving 1.2s ease-in-out infinite alternate}
                    @keyframes qls-rebuild-moving{from{transform:translateX(-15%)}to{transform:translateX(200%)}}
                </style>
            </head>
            <body>
                <main class="qls-rebuild-progress">
                    <h1><?php esc_html_e('正在重建资源标记', 'qilingshop'); ?></h1>
                    <p><?php echo esc_html($progress_message); ?></p>
                    <div class="qls-rebuild-progress-bar" aria-hidden="true"><span></span></div>
                </main>
                <script>window.setTimeout(function(){window.location.replace(<?php echo wp_json_encode($next_url); ?>);},300);</script>
            </body>
            </html>
            <?php
            exit;
        }

        update_option('qilingshop_resource_marker_index_ready', 1, false);

        $done_url = add_query_arg([
            'page' => 'qilingshop-resource-bulk',
            'marker_rebuild_done' => 1,
            'marker_rebuild_total' => $offset + $processed,
        ], admin_url('admin.php'));

        wp_safe_redirect($done_url);
        exit;
    }

    /**
     * 查询资源文章（带分页）
     *
     * @param array $filters
     * @return array{posts: array, max_num_pages: int, current_page: int, total: int}
     */
    private function query_resources($filters) {
        global $wpdb;

        $post_types = $this->get_selected_post_types($filters);
        if (empty($post_types)) {
            return [
                'posts' => [],
                'max_num_pages' => 0,
                'current_page' => 1,
                'total' => 0,
            ];
        }

        if (!(bool) get_option('qilingshop_resource_marker_index_ready', false)) {
            $this->marker_rebuild_recommended = true;
            return $this->query_resources_legacy($filters);
        }

        $per_page = max(1, (int) $filters['per_page']);
        $requested_page = max(1, (int) $filters['paged']);
        $where = [];
        $params = [];

        $type_placeholders = implode(', ', array_fill(0, count($post_types), '%s'));
        $where[] = "p.post_type IN ({$type_placeholders})";
        $params = array_merge($params, $post_types);

        $status_placeholders = implode(', ', array_fill(0, count($this->get_resource_post_statuses()), '%s'));
        $where[] = "p.post_status IN ({$status_placeholders})";
        $params = array_merge($params, $this->get_resource_post_statuses());

        $where[] = "EXISTS (
            SELECT 1
            FROM {$wpdb->postmeta} pm_resource_marker
            WHERE pm_resource_marker.post_id = p.ID
              AND pm_resource_marker.meta_key = %s
              AND pm_resource_marker.meta_value = '1'
        )";
        $params[] = $this->get_resource_marker_key();

        if ($filters['keyword'] !== '') {
            $where[] = "p.post_title LIKE %s";
            $params[] = '%' . $wpdb->esc_like($filters['keyword']) . '%';
        }

        if ($filters['sale_mode_filter'] !== 'all') {
            $where[] = "EXISTS (
                SELECT 1
                FROM {$wpdb->postmeta} pm_sale
                WHERE pm_sale.post_id = p.ID
                  AND pm_sale.meta_key = '_qilingshop_sale_mode'
                  AND pm_sale.meta_value = %s
            )";
            $params[] = $filters['sale_mode_filter'];
        }

        if ($filters['enabled_filter'] === 'enabled') {
            $where[] = "EXISTS (
                SELECT 1
                FROM {$wpdb->postmeta} pm_enabled
                WHERE pm_enabled.post_id = p.ID
                  AND pm_enabled.meta_key = '_qilingshop_points_resource_enabled'
                  AND pm_enabled.meta_value = '1'
            )";
        } elseif ($filters['enabled_filter'] === 'disabled') {
            $where[] = "(
                NOT EXISTS (
                    SELECT 1
                    FROM {$wpdb->postmeta} pm_enabled_missing
                    WHERE pm_enabled_missing.post_id = p.ID
                      AND pm_enabled_missing.meta_key = '_qilingshop_points_resource_enabled'
                )
                OR EXISTS (
                    SELECT 1
                    FROM {$wpdb->postmeta} pm_enabled_zero
                    WHERE pm_enabled_zero.post_id = p.ID
                      AND pm_enabled_zero.meta_key = '_qilingshop_points_resource_enabled'
                      AND pm_enabled_zero.meta_value = '0'
                )
            )";
        }

        $where_sql = implode(' AND ', $where);
        $count_sql = "
            SELECT COUNT(*)
            FROM {$wpdb->posts} p
            WHERE {$where_sql}
        ";
        $count_sql = $wpdb->prepare($count_sql, $params);

        $total = (int) $wpdb->get_var($count_sql);
        $max_num_pages = $total > 0 ? (int) ceil($total / $per_page) : 0;
        $current_page = $max_num_pages > 0 ? min($requested_page, $max_num_pages) : 1;
        $offset = ($current_page - 1) * $per_page;

        $posts = [];
        if ($total > 0) {
            $ids_sql = "
                SELECT p.ID
                FROM {$wpdb->posts} p
                WHERE {$where_sql}
                ORDER BY p.post_modified DESC
                LIMIT %d OFFSET %d
            ";
            $ids_params = $params;
            $ids_params[] = $per_page;
            $ids_params[] = $offset;
            $ids_sql = $wpdb->prepare($ids_sql, $ids_params);

            $post_ids = array_map('absint', (array) $wpdb->get_col($ids_sql));
            $post_ids = array_values(array_filter($post_ids));

            if (!empty($post_ids)) {
                $posts = get_posts([
                    'post_type' => $post_types,
                    'post_status' => $this->get_resource_post_statuses(),
                    'post__in' => $post_ids,
                    'orderby' => 'post__in',
                    'posts_per_page' => count($post_ids),
                ]);
            }
        }

        return [
            'posts' => $posts,
            'max_num_pages' => $max_num_pages,
            'current_page' => $current_page,
            'total' => $total,
        ];
    }

    /**
     * 查询资源文章（带分页）
     *
     * @param array $filters
     * @return array{posts: array, max_num_pages: int, current_page: int, total: int}
     */
    private function query_resources_legacy($filters) {
        global $wpdb;

        $post_types = $this->get_selected_post_types($filters);
        if (empty($post_types)) {
            return [
                'posts' => [],
                'max_num_pages' => 0,
                'current_page' => 1,
                'total' => 0,
            ];
        }

        $per_page = max(1, (int) $filters['per_page']);
        $requested_page = max(1, (int) $filters['paged']);

        $where = [];
        $params = [];

        $type_placeholders = implode(', ', array_fill(0, count($post_types), '%s'));
        $where[] = "p.post_type IN ({$type_placeholders})";
        $params = array_merge($params, $post_types);

        $where[] = "p.post_status IN ('publish', 'draft', 'pending', 'future', 'private')";
        $where[] = "(
            p.post_content LIKE %s
            OR p.post_content LIKE %s
            OR EXISTS (
                SELECT 1
                FROM {$wpdb->postmeta} pm_resource
                WHERE pm_resource.post_id = p.ID
                  AND (
                    (pm_resource.meta_key = '_qilingshop_download_urls' AND pm_resource.meta_value <> '')
                    OR (pm_resource.meta_key = '_qilingshop_hidden_content' AND pm_resource.meta_value <> '')
                    OR (
                        pm_resource.meta_key IN (
                            '_qilingshop_price_download',
                            '_qilingshop_price_view',
                            '_qilingshop_price',
                            '_qilingshop_price_rmb_download',
                            '_qilingshop_price_rmb_view',
                            '_qilingshop_price_rmb'
                        )
                        AND CAST(pm_resource.meta_value AS DECIMAL(20,6)) > 0
                    )
                  )
            )
        )";
        $params[] = '%[qls_content%';
        $params[] = '%[qilingshop_hidden%';

        if ($filters['keyword'] !== '') {
            $where[] = "p.post_title LIKE %s";
            $params[] = '%' . $wpdb->esc_like($filters['keyword']) . '%';
        }

        if ($filters['sale_mode_filter'] !== 'all') {
            $where[] = "EXISTS (
                SELECT 1
                FROM {$wpdb->postmeta} pm_sale
                WHERE pm_sale.post_id = p.ID
                  AND pm_sale.meta_key = '_qilingshop_sale_mode'
                  AND pm_sale.meta_value = %s
            )";
            $params[] = $filters['sale_mode_filter'];
        }

        if ($filters['enabled_filter'] === 'enabled') {
            $where[] = "EXISTS (
                SELECT 1
                FROM {$wpdb->postmeta} pm_enabled
                WHERE pm_enabled.post_id = p.ID
                  AND pm_enabled.meta_key = '_qilingshop_points_resource_enabled'
                  AND pm_enabled.meta_value = '1'
            )";
        } elseif ($filters['enabled_filter'] === 'disabled') {
            $where[] = "(
                NOT EXISTS (
                    SELECT 1
                    FROM {$wpdb->postmeta} pm_enabled_missing
                    WHERE pm_enabled_missing.post_id = p.ID
                      AND pm_enabled_missing.meta_key = '_qilingshop_points_resource_enabled'
                )
                OR EXISTS (
                    SELECT 1
                    FROM {$wpdb->postmeta} pm_enabled_zero
                    WHERE pm_enabled_zero.post_id = p.ID
                      AND pm_enabled_zero.meta_key = '_qilingshop_points_resource_enabled'
                      AND pm_enabled_zero.meta_value = '0'
                )
            )";
        }

        $where_sql = implode(' AND ', $where);

        $count_sql = "
            SELECT COUNT(*)
            FROM {$wpdb->posts} p
            WHERE {$where_sql}
        ";
        if (!empty($params)) {
            $count_sql = $wpdb->prepare($count_sql, $params);
        }

        $total = (int) $wpdb->get_var($count_sql);
        $max_num_pages = $total > 0 ? (int) ceil($total / $per_page) : 0;
        $current_page = $max_num_pages > 0 ? min($requested_page, $max_num_pages) : 1;
        $offset = ($current_page - 1) * $per_page;

        $posts = [];
        if ($total > 0) {
            $ids_sql = "
                SELECT p.ID
                FROM {$wpdb->posts} p
                WHERE {$where_sql}
                ORDER BY p.post_modified DESC
                LIMIT %d OFFSET %d
            ";
            $ids_params = $params;
            $ids_params[] = $per_page;
            $ids_params[] = $offset;
            $ids_sql = $wpdb->prepare($ids_sql, $ids_params);

            $post_ids = array_map('absint', (array) $wpdb->get_col($ids_sql));
            $post_ids = array_values(array_filter($post_ids));

            if (!empty($post_ids)) {
                $posts = get_posts([
                    'post_type' => $post_types,
                    'post_status' => $this->get_resource_post_statuses(),
                    'post__in' => $post_ids,
                    'orderby' => 'post__in',
                    'posts_per_page' => count($post_ids),
                ]);

                foreach ($post_ids as $post_id) {
                    $this->sync_resource_marker($post_id);
                }
            }
        }

        return [
            'posts' => $posts,
            'max_num_pages' => $max_num_pages,
            'current_page' => $current_page,
            'total' => $total,
        ];
    }

    /**
     * 应用单条批量操作
     *
     * @param int         $post_id
     * @param string      $action
     * @param float|null  $price
     * @return bool
     */
    private function apply_bulk_action($post_id, $action, $price = null) {
        $updated = false;

        switch ($action) {
            case 'set_free':
                update_post_meta($post_id, '_qilingshop_sale_mode', 'free');
                update_post_meta($post_id, '_qilingshop_price_download', 0);
                update_post_meta($post_id, '_qilingshop_price_view', 0);
                update_post_meta($post_id, '_qilingshop_price', 0);
                delete_post_meta($post_id, '_qilingshop_is_paid');
                $updated = true;
                break;

            case 'set_paid_download':
                if ($price === null) {
                    return false;
                }
                update_post_meta($post_id, '_qilingshop_sale_mode', 'download');
                update_post_meta($post_id, '_qilingshop_price_download', $price);
                update_post_meta($post_id, '_qilingshop_price_view', $price);
                update_post_meta($post_id, '_qilingshop_price', $price);
                if ($price > 0) {
                    update_post_meta($post_id, '_qilingshop_is_paid', '1');
                } else {
                    delete_post_meta($post_id, '_qilingshop_is_paid');
                }
                $updated = true;
                break;

            case 'set_sale_mode_download':
                update_post_meta($post_id, '_qilingshop_sale_mode', 'download');
                $updated = true;
                break;

            case 'set_sale_mode_view':
                update_post_meta($post_id, '_qilingshop_sale_mode', 'view');
                $updated = true;
                break;

            case 'set_price_download':
                if ($price === null) {
                    return false;
                }
                update_post_meta($post_id, '_qilingshop_price_download', $price);
                $this->sync_legacy_price($post_id);
                $updated = true;
                break;

            case 'set_price_view':
                if ($price === null) {
                    return false;
                }
                update_post_meta($post_id, '_qilingshop_price_view', $price);
                $this->sync_legacy_price($post_id);
                $updated = true;
                break;

            case 'set_price_both':
                if ($price === null) {
                    return false;
                }
                update_post_meta($post_id, '_qilingshop_price_download', $price);
                update_post_meta($post_id, '_qilingshop_price_view', $price);
                $this->sync_legacy_price($post_id);
                $updated = true;
                break;

            case 'set_vip_free_download':
                update_post_meta($post_id, '_qilingshop_vip_free', 'download');
                $updated = true;
                break;

            case 'disable_vip_free':
                update_post_meta($post_id, '_qilingshop_vip_free', 'none');
                $updated = true;
                break;

            case 'enable_resource':
                update_post_meta($post_id, '_qilingshop_points_resource_enabled', 1);
                $updated = true;
                break;

            case 'disable_resource':
                update_post_meta($post_id, '_qilingshop_points_resource_enabled', 0);
                $updated = true;
                break;
        }

        if ($updated) {
            $this->sync_resource_marker($post_id);
        }

        return $updated;
    }

    /**
     * 同步旧字段价格，避免价格元数据不一致
     *
     * @param int $post_id
     * @return void
     */
    private function sync_legacy_price($post_id) {
        $price_download = (float) get_post_meta($post_id, '_qilingshop_price_download', true);
        $price_view = (float) get_post_meta($post_id, '_qilingshop_price_view', true);
        $legacy_price = $price_download > 0 ? $price_download : $price_view;
        update_post_meta($post_id, '_qilingshop_price', $legacy_price);

        if ($legacy_price > 0) {
            update_post_meta($post_id, '_qilingshop_is_paid', '1');
        } else {
            delete_post_meta($post_id, '_qilingshop_is_paid');
        }
    }

    /**
     * 判断文章是否具备真实资源特征
     *
     * @param int $post_id
     * @return bool
     */
    private function is_real_resource_post($post_id) {
        $resource = $this->get_resource_instance();
        if ($resource && method_exists($resource, 'has_resource_features')) {
            return $resource->has_resource_features($post_id);
        }

        return false;
    }

    /**
     * 处理批量提交
     *
     * @param array $filters
     * @return array
     */
    private function handle_post_action($filters) {
        $result = [
            'message' => '',
            'type' => '',
        ];

        if (!isset($_POST['qilingshop_resource_bulk_submit'])) {
            return $result;
        }

        if (!current_user_can('manage_options')) {
            $result['message'] = __('权限不足', 'qilingshop');
            $result['type'] = 'error';
            return $result;
        }

        check_admin_referer('qilingshop_resource_bulk_action', '_wpnonce_qilingshop_resource_bulk');

        $selected_ids = isset($_POST['selected_posts']) ? (array) $_POST['selected_posts'] : [];
        $selected_ids = array_map('absint', $selected_ids);
        $selected_ids = array_values(array_filter($selected_ids));

        if (empty($selected_ids)) {
            $result['message'] = __('请先勾选要操作的资源文章', 'qilingshop');
            $result['type'] = 'error';
            return $result;
        }

        $action = isset($_POST['bulk_action']) ? sanitize_key((string) wp_unslash($_POST['bulk_action'])) : '';
        $actions = $this->get_action_options();
        if ($action === '' || !isset($actions[$action])) {
            $result['message'] = __('请点击要执行的批量操作按钮', 'qilingshop');
            $result['type'] = 'error';
            return $result;
        }

        $price = null;
        $price_actions = ['set_paid_download', 'set_price_download', 'set_price_view', 'set_price_both'];
        if (in_array($action, $price_actions, true)) {
            $price_field_map = [
                'set_paid_download' => 'price_set_paid_download',
                'set_price_download' => 'price_set_price_download',
                'set_price_view' => 'price_set_price_view',
                'set_price_both' => 'price_set_price_both',
            ];
            $price_field = isset($price_field_map[$action]) ? $price_field_map[$action] : 'bulk_price';
            $price_raw = isset($_POST[$price_field]) ? (string) wp_unslash($_POST[$price_field]) : '';
            if ($price_raw === '' && isset($_POST['bulk_price'])) {
                // 兼容旧表单字段
                $price_raw = (string) wp_unslash($_POST['bulk_price']);
            }
            if ($price_raw === '' || !is_numeric($price_raw) || (float) $price_raw < 0) {
                $result['message'] = __('请输入大于等于 0 的有效价格', 'qilingshop');
                $result['type'] = 'error';
                return $result;
            }
            $price = round((float) $price_raw, 2);
        }

        $allowed_post_types = $filters['resource_post_types'];
        $updated = 0;
        $skipped = 0;

        foreach ($selected_ids as $post_id) {
            if (!$post_id || !current_user_can('edit_post', $post_id)) {
                $skipped++;
                continue;
            }

            $post = get_post($post_id);
            if (!$post || !in_array($post->post_type, $allowed_post_types, true)) {
                $skipped++;
                continue;
            }

            if ($this->apply_bulk_action($post_id, $action, $price)) {
                $updated++;
            } else {
                $skipped++;
            }
        }

        $result['message'] = sprintf(
            __('批量操作完成：成功 %1$d 篇，跳过 %2$d 篇。', 'qilingshop'),
            $updated,
            $skipped
        );
        $result['type'] = $updated > 0 ? 'success' : 'warning';
        return $result;
    }

    /**
     * 渲染页面
     *
     * @return void
     */
    public function render() {
        if (!current_user_can('manage_options')) {
            wp_die(__('权限不足', 'qilingshop'));
        }

        $filters = $this->get_filters();
        $notice = $this->handle_post_action($filters);
        if (isset($_GET['marker_rebuild_done'])) {
            $rebuilt = absint($_GET['marker_rebuild_total'] ?? 0);
            $notice = [
                'message' => sprintf(__('资源标记重建完成，本次共同步 %d 篇文章。', 'qilingshop'), $rebuilt),
                'type' => 'success',
            ];
        }
        $query = [
            'posts' => [],
            'max_num_pages' => 0,
            'current_page' => 1,
            'total' => 0,
        ];
        try {
            $query = $this->query_resources($filters);
        } catch (Throwable $e) {
            $notice = [
                'message' => __('资源列表加载失败，请稍后重试或联系站点管理员。', 'qilingshop'),
                'type' => 'error',
            ];
        }
        $points_name = qilingshop_get_points_name();
        $resource = null;
        if (class_exists('QilingShop_Resource')) {
            try {
                $resource = QilingShop_Resource::instance();
            } catch (Throwable $e) {
                $resource = null;
            }
        }
        $resource_posts = isset($query['posts']) && is_array($query['posts']) ? $query['posts'] : [];
        $current_page = isset($query['current_page']) ? (int) $query['current_page'] : $filters['paged'];
        if ($current_page < 1) {
            $current_page = 1;
        }
        ?>
        <div class="wrap qilingshop-admin-page qilingshop-vr-page qilingshop-resource-bulk-page">
            <h1><?php _e('批量操作', 'qilingshop'); ?></h1>

            <?php if (!empty($notice['message'])): ?>
                <div class="notice <?php echo $notice['type'] === 'error' ? 'notice-error' : ($notice['type'] === 'warning' ? 'notice-warning' : 'notice-success'); ?> is-dismissible">
                    <p><?php echo esc_html($notice['message']); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($this->marker_rebuild_recommended): ?>
                <div class="notice notice-warning">
                    <p>
                        <?php _e('当前资源列表仍在兼容旧识别方式。建议先重建资源标记，后续批量页将不再扫描文章内容字段。', 'qilingshop'); ?>
                        <a href="<?php echo esc_url($this->get_resource_marker_rebuild_url()); ?>" class="button button-secondary"><?php _e('重建资源标记', 'qilingshop'); ?></a>
                    </p>
                </div>
            <?php endif; ?>

            <form method="get" class="qls-admin-resource-filter-form">
                <input type="hidden" name="page" value="qilingshop-resource-bulk">
                <div class="qls-admin-resource-filter-grid">
                    <label>
                        <?php _e('文章类型', 'qilingshop'); ?><br>
                        <select name="post_type_filter">
                            <option value="all" <?php selected($filters['post_type_filter'], 'all'); ?>><?php _e('全部', 'qilingshop'); ?></option>
                            <?php foreach ($filters['resource_post_types'] as $pt): $pt_obj = get_post_type_object($pt); ?>
                                <option value="<?php echo esc_attr($pt); ?>" <?php selected($filters['post_type_filter'], $pt); ?>>
                                    <?php echo esc_html($pt_obj ? $pt_obj->labels->singular_name : $pt); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <?php _e('销售模式', 'qilingshop'); ?><br>
                        <select name="sale_mode_filter">
                            <option value="all" <?php selected($filters['sale_mode_filter'], 'all'); ?>><?php _e('全部', 'qilingshop'); ?></option>
                            <option value="free" <?php selected($filters['sale_mode_filter'], 'free'); ?>><?php _e('免费', 'qilingshop'); ?></option>
                            <option value="download" <?php selected($filters['sale_mode_filter'], 'download'); ?>><?php _e('付费下载', 'qilingshop'); ?></option>
                            <option value="view" <?php selected($filters['sale_mode_filter'], 'view'); ?>><?php _e('付费查看', 'qilingshop'); ?></option>
                        </select>
                    </label>
                    <label>
                        <?php _e('资源开关', 'qilingshop'); ?><br>
                        <select name="enabled_filter">
                            <option value="all" <?php selected($filters['enabled_filter'], 'all'); ?>><?php _e('全部', 'qilingshop'); ?></option>
                            <option value="enabled" <?php selected($filters['enabled_filter'], 'enabled'); ?>><?php _e('已启用', 'qilingshop'); ?></option>
                            <option value="disabled" <?php selected($filters['enabled_filter'], 'disabled'); ?>><?php _e('已关闭', 'qilingshop'); ?></option>
                        </select>
                    </label>
                    <label>
                        <?php _e('每页', 'qilingshop'); ?><br>
                        <select name="per_page">
                            <option value="20" <?php selected($filters['per_page'], 20); ?>>20</option>
                            <option value="50" <?php selected($filters['per_page'], 50); ?>>50</option>
                            <option value="100" <?php selected($filters['per_page'], 100); ?>>100</option>
                        </select>
                    </label>
                    <label>
                        <?php _e('关键词', 'qilingshop'); ?><br>
                        <input type="search" name="s" value="<?php echo esc_attr($filters['keyword']); ?>" placeholder="<?php esc_attr_e('标题关键词', 'qilingshop'); ?>">
                    </label>
                    <button type="submit" class="button"><?php _e('筛选', 'qilingshop'); ?></button>
                    <a href="<?php echo esc_url($this->get_resource_marker_rebuild_url()); ?>" class="button button-secondary"><?php _e('重建资源标记', 'qilingshop'); ?></a>
                </div>
            </form>

            <form method="post" id="qilingshop-resource-bulk-form">
                <?php wp_nonce_field('qilingshop_resource_bulk_action', '_wpnonce_qilingshop_resource_bulk'); ?>
                <input type="hidden" name="qilingshop_resource_bulk_submit" value="1">
                <input type="hidden" name="page" value="qilingshop-resource-bulk">
                <input type="hidden" name="post_type_filter" value="<?php echo esc_attr($filters['post_type_filter']); ?>">
                <input type="hidden" name="sale_mode_filter" value="<?php echo esc_attr($filters['sale_mode_filter']); ?>">
                <input type="hidden" name="enabled_filter" value="<?php echo esc_attr($filters['enabled_filter']); ?>">
                <input type="hidden" name="per_page" value="<?php echo esc_attr($filters['per_page']); ?>">
                <input type="hidden" name="paged" value="<?php echo esc_attr($current_page); ?>">
                <input type="hidden" name="s" value="<?php echo esc_attr($filters['keyword']); ?>">

                <div class="qls-admin-resource-actions-panel">
                    <h2 class="qls-admin-resource-actions-title"><?php _e('操作区域', 'qilingshop'); ?></h2>
                    <p class="qls-admin-resource-actions-desc">
                        <?php _e('先在下方勾选资源，再点击对应按钮执行。', 'qilingshop'); ?>
                        <?php _e('当前已勾选：', 'qilingshop'); ?><strong id="qilingshop-selected-count">0</strong>
                    </p>

                    <div class="qls-admin-resource-actions-grid">
                        <div class="qls-admin-resource-action-card">
                            <div class="qls-admin-resource-action-card-title"><?php _e('快捷操作（无需价格）', 'qilingshop'); ?></div>
                            <div class="qls-admin-resource-action-buttons">
                                <button type="submit" name="bulk_action" value="set_free" class="button"><?php _e('批量改为免费', 'qilingshop'); ?></button>
                                <button type="submit" name="bulk_action" value="set_sale_mode_download" class="button"><?php _e('设为付费下载模式', 'qilingshop'); ?></button>
                                <button type="submit" name="bulk_action" value="set_sale_mode_view" class="button"><?php _e('设为付费查看模式', 'qilingshop'); ?></button>
                                <button type="submit" name="bulk_action" value="set_vip_free_download" class="button"><?php _e('设为 VIP 免费下载', 'qilingshop'); ?></button>
                                <button type="submit" name="bulk_action" value="disable_vip_free" class="button"><?php _e('取消 VIP 免费', 'qilingshop'); ?></button>
                                <button type="submit" name="bulk_action" value="enable_resource" class="button"><?php _e('批量启用资源', 'qilingshop'); ?></button>
                                <button type="submit" name="bulk_action" value="disable_resource" class="button"><?php _e('批量关闭资源', 'qilingshop'); ?></button>
                            </div>
                        </div>

                        <div class="qls-admin-resource-action-card">
                            <div class="qls-admin-resource-action-card-title"><?php echo esc_html(sprintf(__('价格操作（单位：%s）', 'qilingshop'), $points_name)); ?></div>
                            <div class="qls-admin-resource-price-row">
                                <input type="number" name="price_set_paid_download" value="" step="0.01" min="0" placeholder="<?php esc_attr_e('输入统一价格', 'qilingshop'); ?>">
                                <button type="submit" name="bulk_action" value="set_paid_download" class="button button-primary"><?php _e('改为收费下载', 'qilingshop'); ?></button>
                            </div>
                            <div class="qls-admin-resource-price-row">
                                <input type="number" name="price_set_price_download" value="" step="0.01" min="0" placeholder="<?php esc_attr_e('输入下载价', 'qilingshop'); ?>">
                                <button type="submit" name="bulk_action" value="set_price_download" class="button"><?php _e('批量设置下载价', 'qilingshop'); ?></button>
                            </div>
                            <div class="qls-admin-resource-price-row">
                                <input type="number" name="price_set_price_view" value="" step="0.01" min="0" placeholder="<?php esc_attr_e('输入查看价', 'qilingshop'); ?>">
                                <button type="submit" name="bulk_action" value="set_price_view" class="button"><?php _e('批量设置查看价', 'qilingshop'); ?></button>
                            </div>
                            <div class="qls-admin-resource-price-row">
                                <input type="number" name="price_set_price_both" value="" step="0.01" min="0" placeholder="<?php esc_attr_e('输入统一价格', 'qilingshop'); ?>">
                                <button type="submit" name="bulk_action" value="set_price_both" class="button"><?php _e('下载价/查看价同价', 'qilingshop'); ?></button>
                            </div>
                        </div>
                    </div>
                </div>

                <table class="wp-list-table qls-ui-table widefat fixed striped qls-admin-resource-table">
                    <thead>
                        <tr>
                            <td class="qls-admin-resource-col-check"><input type="checkbox" id="qilingshop-check-all"></td>
                            <th class="qls-admin-resource-col-id">ID</th>
                            <th><?php _e('标题', 'qilingshop'); ?></th>
                            <th class="qls-admin-resource-col-type"><?php _e('类型', 'qilingshop'); ?></th>
                            <th class="qls-admin-resource-col-sale"><?php _e('销售模式', 'qilingshop'); ?></th>
                            <th class="qls-admin-resource-col-price"><?php _e('下载价', 'qilingshop'); ?></th>
                            <th class="qls-admin-resource-col-price"><?php _e('查看价', 'qilingshop'); ?></th>
                            <th class="qls-admin-resource-col-switch"><?php _e('资源开关', 'qilingshop'); ?></th>
                            <th class="qls-admin-resource-col-link"><?php _e('下载地址', 'qilingshop'); ?></th>
                            <th class="qls-admin-resource-col-time"><?php _e('更新时间', 'qilingshop'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rendered_rows = 0; ?>
                        <?php if (!empty($resource_posts)): ?>
                            <?php foreach ($resource_posts as $post_obj): ?>
                                <?php
                                if (!($post_obj instanceof WP_Post)) {
                                    continue;
                                }
                                $post_id = (int) $post_obj->ID;
                                $rendered_rows++;
                                $sale_mode = (string) get_post_meta($post_id, '_qilingshop_sale_mode', true);
                                if ($sale_mode === '') $sale_mode = 'download';
                                $price_download_points = (is_object($resource) && method_exists($resource, 'get_points_price'))
                                    ? (float) $resource->get_points_price($post_id, 'download')
                                    : (float) get_post_meta($post_id, '_qilingshop_price_download', true);
                                $price_view_points = (is_object($resource) && method_exists($resource, 'get_points_price'))
                                    ? (float) $resource->get_points_price($post_id, 'view')
                                    : (float) get_post_meta($post_id, '_qilingshop_price_view', true);
                                $price_download_rmb = (is_object($resource) && method_exists($resource, 'get_rmb_price'))
                                    ? (float) $resource->get_rmb_price($post_id, 'download')
                                    : 0.0;
                                $price_view_rmb = (is_object($resource) && method_exists($resource, 'get_rmb_price'))
                                    ? (float) $resource->get_rmb_price($post_id, 'view')
                                    : 0.0;
                                $resource_enabled = get_post_meta($post_id, '_qilingshop_points_resource_enabled', true);
                                $resource_enabled = $resource_enabled === '' ? 1 : (int) $resource_enabled;
                                $download_urls_meta = get_post_meta($post_id, '_qilingshop_download_urls', true);
                                $download_urls = is_array($download_urls_meta)
                                    ? implode("\n", array_filter(array_map('strval', $download_urls_meta)))
                                    : (string) $download_urls_meta;
                                $post_type_obj = get_post_type_object($post_obj->post_type);
                                $post_status_obj = get_post_status_object($post_obj->post_status);
                                $download_display = '0';
                                if ($price_download_points > 0) {
                                    $download_display = $price_download_points . ' ' . $points_name;
                                } elseif ($price_download_rmb > 0) {
                                    $download_display = '¥' . number_format($price_download_rmb, 2);
                                }
                                $view_display = '0';
                                if ($price_view_points > 0) {
                                    $view_display = $price_view_points . ' ' . $points_name;
                                } elseif ($price_view_rmb > 0) {
                                    $view_display = '¥' . number_format($price_view_rmb, 2);
                                }
                                ?>
                                <tr>
                                    <td><input type="checkbox" class="qilingshop-item-check" name="selected_posts[]" value="<?php echo esc_attr($post_id); ?>"></td>
                                    <td><?php echo esc_html($post_id); ?></td>
                                    <td>
                                        <strong><a href="<?php echo esc_url(get_edit_post_link($post_id)); ?>"><?php echo esc_html(get_the_title($post_id)); ?></a></strong>
                                        <?php if ($post_obj && $post_obj->post_status !== 'publish'): ?>
                                            <div class="qls-admin-resource-post-status"><?php echo esc_html($post_status_obj ? $post_status_obj->label : $post_obj->post_status); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($post_type_obj ? $post_type_obj->labels->singular_name : $post_obj->post_type); ?></td>
                                    <td>
                                        <?php
                                        if ($sale_mode === 'free') {
                                            echo esc_html__('免费', 'qilingshop');
                                        } elseif ($sale_mode === 'view') {
                                            echo esc_html__('付费查看', 'qilingshop');
                                        } else {
                                            echo esc_html__('付费下载', 'qilingshop');
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo esc_html($download_display); ?></td>
                                    <td><?php echo esc_html($view_display); ?></td>
                                    <td><?php echo $resource_enabled ? esc_html__('启用', 'qilingshop') : esc_html__('关闭', 'qilingshop'); ?></td>
                                    <td><?php echo $download_urls !== '' ? esc_html__('有', 'qilingshop') : esc_html__('无', 'qilingshop'); ?></td>
                                    <td><?php echo esc_html(get_the_modified_date('Y-m-d H:i', $post_id)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if ($rendered_rows === 0): ?>
                            <tr><td colspan="10"><?php _e('暂无符合条件的资源文章', 'qilingshop'); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php
                $total_pages = (int) $query['max_num_pages'];
                if ($total_pages > 1):
                    $pagination_base = add_query_arg('paged', '%#%');
                    $page_links = paginate_links([
                        'base' => $pagination_base,
                        'format' => '',
                        'current' => $current_page,
                        'total' => $total_pages,
                        'type' => 'array',
                    ]);
                    if (!empty($page_links)):
                ?>
                        <div class="tablenav qls-admin-resource-pagination">
                            <div class="tablenav-pages">
                                <span class="displaying-num"><?php echo esc_html(sprintf(__('共 %d 页', 'qilingshop'), $total_pages)); ?></span>
                                <span class="pagination-links">
                                    <?php foreach ($page_links as $link): ?>
                                        <?php echo wp_kses_post($link); ?>
                                    <?php endforeach; ?>
                                </span>
                            </div>
                        </div>
                <?php
                    endif;
                endif;
                ?>
            </form>
        </div>

        <script>
        (function() {
            var form = document.getElementById('qilingshop-resource-bulk-form');
            var checkAll = document.getElementById('qilingshop-check-all');
            var itemChecks = document.querySelectorAll('.qilingshop-item-check');
            var selectedCount = document.getElementById('qilingshop-selected-count');
            var actionButtons = document.querySelectorAll('button[name="bulk_action"]');
            var priceInputs = form ? form.querySelectorAll('input[type="number"][name^="price_set_"]') : [];

            function updateSelectedCount() {
                if (!selectedCount) return;
                var count = 0;
                itemChecks.forEach(function(item) {
                    if (item.checked) count++;
                });
                selectedCount.textContent = String(count);
            }

            if (checkAll) {
                checkAll.addEventListener('change', function() {
                    itemChecks.forEach(function(item) {
                        item.checked = checkAll.checked;
                    });
                    updateSelectedCount();
                });
            }

            itemChecks.forEach(function(item) {
                item.addEventListener('change', function() {
                    if (checkAll) {
                        var checked = 0;
                        itemChecks.forEach(function(i) { if (i.checked) checked++; });
                        checkAll.checked = checked > 0 && checked === itemChecks.length;
                    }
                    updateSelectedCount();
                });
            });

            actionButtons.forEach(function(button) {
                button.addEventListener('click', function(e) {
                    var checkedCount = 0;
                    itemChecks.forEach(function(item) {
                        if (item.checked) checkedCount++;
                    });
                    if (checkedCount === 0) {
                        e.preventDefault();
                        window.alert('<?php echo esc_js(__('请先勾选要操作的资源文章', 'qilingshop')); ?>');
                        return;
                    }

                    var actionText = button.textContent ? button.textContent.trim() : '';
                    var message = '<?php echo esc_js(__('确定执行以下批量操作吗：', 'qilingshop')); ?>' + '\n' + actionText;
                    if (!window.confirm(message)) {
                        e.preventDefault();
                        return;
                    }
                });
            });

            priceInputs.forEach(function(input) {
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                    }
                });
            });

            updateSelectedCount();
        })();
        </script>
        <?php
    }
}
