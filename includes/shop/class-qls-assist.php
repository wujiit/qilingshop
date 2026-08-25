<?php
/**
 * 好友助力核心服务
 *
 * @package QilingShop
 * @since   2.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_Assist {

    /**
     * 活动状态
     */
    const ACTIVITY_DISABLED = 0;
    const ACTIVITY_ENABLED  = 1;

    /**
     * 助力单状态
     */
    const CAMPAIGN_ONGOING       = 0; // 助力中
    const CAMPAIGN_READY         = 1; // 已达到目标，可下单
    const CAMPAIGN_ORDER_PENDING = 2; // 已创建差额订单，待支付
    const CAMPAIGN_COMPLETED     = 3; // 已支付完成
    const CAMPAIGN_EXPIRED       = 4; // 已过期
    const CAMPAIGN_CANCELLED     = 5; // 已取消
    const CAMPAIGN_REFUNDED      = 6; // 已退款（库存回补）

    /**
     * 单例实例
     *
     * @var QLS_Assist|null
     */
    private static $instance = null;

    /**
     * DB 访问器
     *
     * @var QLS_Shop_Database
     */
    private $db;

    /**
     * wpdb
     *
     * @var wpdb
     */
    private $wpdb;

    /**
     * 表名
     *
     * @var string
     */
    private $table_activities = '';
    private $table_campaigns  = '';
    private $table_logs       = '';

    /**
     * 请求级维护执行缓存（避免同请求重复读 transient）
     *
     * @var array
     */
    private $maintenance_request_cache = [];

    /**
     * 获取实例
     *
     * @return QLS_Assist
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 构造
     */
    private function __construct() {
        $this->db = QLS_Shop_Database::instance();
        $this->wpdb = $this->db->get_wpdb();
        $this->table_activities = $this->db->get_table('assist_activities');
        $this->table_campaigns = $this->db->get_table('assist_campaigns');
        $this->table_logs = $this->db->get_table('assist_logs');

        if (did_action('init')) {
            $this->ensure_campaign_schema();
        } else {
            add_action('init', [$this, 'ensure_campaign_schema'], 20);
        }

        // 订单状态联动
        add_action('qls_shop_order_paid', [$this, 'handle_order_paid'], 10, 2);
        add_action('qls_shop_order_cancelled', [$this, 'handle_order_cancelled'], 10, 2);
        add_action('qls_shop_order_refunded', [$this, 'handle_order_refunded'], 10, 1);
    }

    /**
     * 兼容升级：确保助力单库存预占字段存在
     *
     * @return bool
     */
    public function ensure_campaign_schema() {
        static $checked = false;
        if ($checked) {
            return;
        }

        $table = $this->table_campaigns;
        $table_exists = (string) $this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($table_exists !== $table) {
            return;
        }

        $checked = true;
        $has_reserved = (bool) $this->wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'stock_reserved'");
        $has_reserved_qty = (bool) $this->wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'reserved_qty'");

        if (!$has_reserved) {
            $this->wpdb->query("ALTER TABLE {$table} ADD COLUMN stock_reserved TINYINT(1) NOT NULL DEFAULT 0 AFTER target_helpers");
        }
        if (!$has_reserved_qty) {
            $this->wpdb->query("ALTER TABLE {$table} ADD COLUMN reserved_qty INT(11) NOT NULL DEFAULT 0 AFTER stock_reserved");
        }
    }

    /**
     * 获取数据库级命名锁。
     *
     * @param string $lock_name
     * @param int    $timeout
     * @return bool
     */
    private function acquire_named_lock($lock_name, $timeout = 5) {
        $lock_name = sanitize_key((string) $lock_name);
        if ($lock_name === '') {
            return false;
        }

        $result = $this->wpdb->get_var($this->wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, (int) $timeout));
        return (int) $result === 1;
    }

    /**
     * 释放数据库级命名锁。
     *
     * @param string $lock_name
     * @return void
     */
    private function release_named_lock($lock_name) {
        $lock_name = sanitize_key((string) $lock_name);
        if ($lock_name === '') {
            return;
        }

        $this->wpdb->get_var($this->wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
    }

    /**
     * 构造助力订单联动锁名。
     *
     * @param int $order_id
     * @return string
     */
    private function build_assist_order_lock_name($order_id) {
        return 'qlsassord_' . md5((int) $order_id);
    }

    /**
     * 构造活动状态写锁。
     *
     * @param int $activity_id
     * @return string
     */
    private function build_activity_lock_name($activity_id) {
        return 'qlsassistact_' . md5((int) $activity_id);
    }

    /**
     * 原子切换活动状态。
     *
     * @param int      $activity_id
     * @param int      $target_status
     * @param int|null $expected_status
     * @return bool
     */
    public function update_activity_status($activity_id, $target_status, $expected_status = null) {
        $activity_id = (int) $activity_id;
        $target_status = (int) $target_status;
        if ($activity_id <= 0) {
            return false;
        }

        $lock_name = $this->build_activity_lock_name($activity_id);
        if (!$this->acquire_named_lock($lock_name, 5)) {
            return false;
        }

        try {
            $where = ['id' => $activity_id];
            if ($expected_status !== null) {
                $where['status'] = (int) $expected_status;
            }

            $updated = $this->db->update('assist_activities', [
                'status'     => $target_status,
                'updated_at' => current_time('mysql'),
            ], $where);

            return $updated !== false && (int) $updated === 1;
        } finally {
            $this->release_named_lock($lock_name);
        }
    }

    /**
     * 重新上架助力活动。
     *
     * 已到期活动必须传入新的结束时间，避免后台显示上架但前台仍不可参与。
     *
     * @param int    $activity_id
     * @param string $new_end_time
     * @return int|WP_Error
     */
    public function reopen_activity($activity_id, $new_end_time = '') {
        $activity_id = (int) $activity_id;
        if ($activity_id <= 0) {
            return new WP_Error('invalid_activity', __('活动不存在', 'qilingshop'));
        }

        $lock_name = $this->build_activity_lock_name($activity_id);
        if (!$this->acquire_named_lock($lock_name, 5)) {
            return new WP_Error('activity_busy', __('活动处理中，请稍后重试', 'qilingshop'));
        }

        try {
            $activity = $this->get_activity($activity_id);
            if (!$activity) {
                return new WP_Error('invalid_activity', __('活动不存在', 'qilingshop'));
            }

            if ((int) ($activity->stock_available ?? 0) <= 0) {
                return new WP_Error('stock_empty', __('活动库存不足，不能重新上架', 'qilingshop'));
            }

            $now = current_time('timestamp');
            $update = [
                'status'     => self::ACTIVITY_ENABLED,
                'updated_at' => current_time('mysql'),
            ];

            $new_end_time = trim((string) $new_end_time);
            if ($new_end_time !== '') {
                $normalized_end = $this->normalize_datetime_input($new_end_time);
                if (!$normalized_end || $this->to_timestamp($normalized_end) <= $now) {
                    return new WP_Error('invalid_end_time', __('新的结束时间必须晚于当前时间', 'qilingshop'));
                }
                $update['end_time'] = $normalized_end;
            } else {
                $current_end_ts = $this->to_timestamp($activity->end_time ?? null);
                if ($current_end_ts !== null && $current_end_ts <= $now) {
                    return new WP_Error('end_time_required', __('活动已到期，请先填写新的结束时间', 'qilingshop'));
                }
            }

            $updated = $this->db->update('assist_activities', $update, ['id' => $activity_id]);
            if ($updated === false) {
                return new WP_Error('reopen_failed', __('重新上架失败，请稍后重试', 'qilingshop'));
            }

            return $activity_id;
        } finally {
            $this->release_named_lock($lock_name);
        }
    }

    /**
     * 是否助力订单
     *
     * @param int $order_id
     * @return bool
     */
    public function is_assist_order($order_id) {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return false;
        }

        if ($this->has_confirmed_assist_order_meta($order_id)) {
            return true;
        }

        return $this->maybe_migrate_legacy_assist_order_meta($order_id);
    }

    /**
     * 库存账务专用助力订单判定。
     *
     * 只能信任商城订单元数据；旧版 postmeta 只有在能和助力单、商城订单互相校验时才允许迁移。
     *
     * @param int $order_id 订单 ID。
     * @return bool
     */
    public function is_assist_order_for_stock($order_id) {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return false;
        }

        if ($this->has_confirmed_assist_order_meta($order_id)) {
            return true;
        }

        return $this->maybe_migrate_legacy_assist_order_meta($order_id);
    }

    /**
     * 标记订单为助力订单
     *
     * @param int $order_id
     * @param int $campaign_id
     * @param int $activity_id
     * @param int $quantity
     * @return void
     */
    public function mark_order_as_assist($order_id, $campaign_id, $activity_id, $quantity = 1) {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return false;
        }

        $stored = true;
        $meta = [
            '_qls_assist_order'          => 'yes',
            '_qls_assist_campaign_id'    => (int) $campaign_id,
            '_qls_assist_activity_id'    => (int) $activity_id,
            '_qls_assist_qty'            => max(1, (int) $quantity),
            '_qls_assist_stock_locked'   => 'yes',
            '_qls_assist_stock_consumed' => 'no',
            '_qls_assist_refunded'       => 'no',
        ];

        foreach ($meta as $key => $value) {
            if (!$this->update_assist_order_meta($order_id, $key, $value)) {
                $stored = false;
            }
        }

        if ($stored) {
            $this->delete_legacy_assist_order_postmeta($order_id);
        }

        return $stored;
    }

    /**
     * 读取助力订单元数据
     *
     * @param int $order_id
     * @return array
     */
    public function get_assist_order_meta($order_id) {
        $order_id = (int) $order_id;
        $this->maybe_migrate_legacy_assist_order_meta($order_id);

        return [
            'is_assist'      => $this->is_assist_order($order_id),
            'campaign_id'    => (int) $this->get_assist_order_meta_value($order_id, '_qls_assist_campaign_id', 0),
            'activity_id'    => (int) $this->get_assist_order_meta_value($order_id, '_qls_assist_activity_id', 0),
            'quantity'       => max(1, (int) $this->get_assist_order_meta_value($order_id, '_qls_assist_qty', 1)),
            'stock_locked'   => $this->get_assist_order_meta_value($order_id, '_qls_assist_stock_locked', 'no') === 'yes',
            'stock_consumed' => $this->get_assist_order_meta_value($order_id, '_qls_assist_stock_consumed', 'no') === 'yes',
            'refunded'       => $this->get_assist_order_meta_value($order_id, '_qls_assist_refunded', 'no') === 'yes',
        ];
    }

    /**
     * 助力订单元数据键列表。
     *
     * @return array
     */
    private function get_assist_order_meta_keys() {
        return [
            '_qls_assist_order',
            '_qls_assist_campaign_id',
            '_qls_assist_activity_id',
            '_qls_assist_qty',
            '_qls_assist_stock_locked',
            '_qls_assist_stock_consumed',
            '_qls_assist_refunded',
        ];
    }

    /**
     * 读取助力订单元数据。
     *
     * @param int    $order_id 订单 ID。
     * @param string $key      元数据键。
     * @param mixed  $default  默认值。
     * @return mixed
     */
    private function get_assist_order_meta_value($order_id, $key, $default = '') {
        if (!function_exists('qilingshop_get_shop_order_meta')) {
            return $default;
        }

        return qilingshop_get_shop_order_meta((int) $order_id, $key, $default);
    }

    /**
     * 是否存在已确认的商城订单元数据标记。
     *
     * @param int $order_id 订单 ID。
     * @return bool
     */
    private function has_confirmed_assist_order_meta($order_id) {
        $meta = $this->get_confirmed_assist_order_meta((int) $order_id);
        return !empty($meta) && $this->is_assist_order_meta_trusted((int) $order_id, $meta);
    }

    /**
     * 读取已存储的商城订单助力元数据。
     *
     * @param int $order_id 订单 ID。
     * @return array
     */
    private function get_confirmed_assist_order_meta($order_id) {
        $order_id = (int) $order_id;
        if ($order_id <= 0 || $this->get_assist_order_meta_value($order_id, '_qls_assist_order', '') !== 'yes') {
            return [];
        }

        return [
            '_qls_assist_order'          => 'yes',
            '_qls_assist_campaign_id'    => (int) $this->get_assist_order_meta_value($order_id, '_qls_assist_campaign_id', 0),
            '_qls_assist_activity_id'    => (int) $this->get_assist_order_meta_value($order_id, '_qls_assist_activity_id', 0),
            '_qls_assist_qty'            => max(1, (int) $this->get_assist_order_meta_value($order_id, '_qls_assist_qty', 1)),
            '_qls_assist_stock_locked'   => $this->get_assist_order_meta_value($order_id, '_qls_assist_stock_locked', 'no') === 'yes' ? 'yes' : 'no',
            '_qls_assist_stock_consumed' => $this->get_assist_order_meta_value($order_id, '_qls_assist_stock_consumed', 'no') === 'yes' ? 'yes' : 'no',
            '_qls_assist_refunded'       => $this->get_assist_order_meta_value($order_id, '_qls_assist_refunded', 'no') === 'yes' ? 'yes' : 'no',
        ];
    }

    /**
     * 写入助力订单元数据。
     *
     * @param int    $order_id 订单 ID。
     * @param string $key      元数据键。
     * @param mixed  $value    元数据值。
     * @return bool
     */
    private function update_assist_order_meta($order_id, $key, $value) {
        if (!function_exists('qilingshop_update_shop_order_meta')) {
            return false;
        }

        return qilingshop_update_shop_order_meta((int) $order_id, $key, $value);
    }

    /**
     * 清理旧版本误写入 wp_postmeta 的助力订单元数据。
     *
     * @param int $order_id 订单 ID。
     * @return void
     */
    private function delete_legacy_assist_order_postmeta($order_id) {
        foreach ($this->get_assist_order_meta_keys() as $key) {
            delete_post_meta((int) $order_id, $key);
        }
    }

    /**
     * 将旧版本 wp_postmeta 中的助力订单元数据迁移到商城订单元数据表。
     *
     * @param int $order_id 订单 ID。
     * @return bool
     */
    private function maybe_migrate_legacy_assist_order_meta($order_id) {
        $order_id = (int) $order_id;
        if ($order_id <= 0 || get_post_meta($order_id, '_qls_assist_order', true) !== 'yes') {
            return false;
        }

        $meta = [
            '_qls_assist_order'          => 'yes',
            '_qls_assist_campaign_id'    => (int) get_post_meta($order_id, '_qls_assist_campaign_id', true),
            '_qls_assist_activity_id'    => (int) get_post_meta($order_id, '_qls_assist_activity_id', true),
            '_qls_assist_qty'            => max(1, (int) get_post_meta($order_id, '_qls_assist_qty', true)),
            '_qls_assist_stock_locked'   => get_post_meta($order_id, '_qls_assist_stock_locked', true) === 'no' ? 'no' : 'yes',
            '_qls_assist_stock_consumed' => get_post_meta($order_id, '_qls_assist_stock_consumed', true) === 'yes' ? 'yes' : 'no',
            '_qls_assist_refunded'       => get_post_meta($order_id, '_qls_assist_refunded', true) === 'yes' ? 'yes' : 'no',
        ];

        if (!$this->is_assist_order_meta_trusted($order_id, $meta)) {
            return false;
        }

        $stored = true;
        foreach ($meta as $key => $value) {
            if (!$this->update_assist_order_meta($order_id, $key, $value)) {
                $stored = false;
            }
        }

        if ($stored) {
            $this->delete_legacy_assist_order_postmeta($order_id);
        }

        return $stored;
    }

    /**
     * 校验助力元数据是否确实属于当前商城订单。
     *
     * @param int   $order_id 订单 ID。
     * @param array $meta     助力元数据。
     * @return bool
     */
    private function is_assist_order_meta_trusted($order_id, $meta) {
        $order_id = (int) $order_id;
        $campaign_id = (int) ($meta['_qls_assist_campaign_id'] ?? 0);
        $activity_id = (int) ($meta['_qls_assist_activity_id'] ?? 0);

        if ($order_id <= 0 || $campaign_id <= 0 || $activity_id <= 0) {
            return false;
        }

        if (!function_exists('qls_shop_order')) {
            return false;
        }

        $order = qls_shop_order()->get($order_id);
        if (!$order || empty($order->id) || empty($order->order_no)) {
            return false;
        }

        $campaign = $this->get_campaign($campaign_id);
        if (!$campaign) {
            return false;
        }

        if ((int) ($campaign->activity_id ?? 0) !== $activity_id) {
            return false;
        }

        if ((int) ($campaign->pay_order_id ?? 0) !== $order_id) {
            return false;
        }

        $campaign_order_no = trim((string) ($campaign->pay_order_no ?? ''));
        if ($campaign_order_no === '') {
            return false;
        }

        return hash_equals($campaign_order_no, (string) $order->order_no);
    }

    /**
     * 创建/更新活动
     *
     * @param array $data
     * @param int   $activity_id
     * @return int|WP_Error
     */
    public function save_activity($data, $activity_id = 0) {
        $activity_id = (int) $activity_id;
        $clean = $this->sanitize_activity_data($data, $activity_id > 0, $activity_id);
        if (is_wp_error($clean)) {
            return $clean;
        }

        if ($activity_id > 0) {
            $lock_name = $this->build_activity_lock_name($activity_id);
            if (!$this->acquire_named_lock($lock_name, 5)) {
                return new WP_Error('activity_busy', __('活动处理中，请稍后重试', 'qilingshop'));
            }

            try {
                unset($clean['created_at']);
                $affected = $this->db->update('assist_activities', $clean, ['id' => $activity_id]);
                if ($affected === false) {
                    return new WP_Error('save_failed', __('更新助力活动失败', 'qilingshop'));
                }
            } finally {
                $this->release_named_lock($lock_name);
            }
            return $activity_id;
        }

        $new_id = $this->db->insert('assist_activities', $clean);
        if (!$new_id) {
            return new WP_Error('create_failed', __('创建助力活动失败', 'qilingshop'));
        }
        return (int) $new_id;
    }

    /**
     * 获取活动详情
     *
     * @param int $activity_id
     * @return object|null
     */
    public function get_activity($activity_id) {
        $activity_id = (int) $activity_id;
        if ($activity_id <= 0) {
            return null;
        }

        $table_products = $this->db->get_table('products');
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT a.*, p.title AS product_title, p.main_image AS product_image
             FROM {$this->table_activities} a
             LEFT JOIN {$table_products} p ON p.id = a.product_id
             WHERE a.id = %d
             LIMIT 1",
            $activity_id
        ));

        if ($row) {
            $row->stock_available = max(0, (int) $row->stock_total - (int) $row->stock_locked - (int) $row->stock_sold);
        }

        return $row;
    }

    /**
     * 获取活动列表
     *
     * @param array $args
     * @return array
     */
    public function get_activities($args = []) {
        $defaults = [
            'status' => '',
            'keyword' => '',
            'time_active' => false,
            'product_status' => '',
            'product_id' => 0,
            'limit' => 20,
            'offset' => 0,
        ];
        $args = wp_parse_args($args, $defaults);

        $table_products = $this->db->get_table('products');
        $where = ['1=1'];
        $params = [];

        if ($args['status'] !== '' && $args['status'] !== null) {
            $where[] = 'a.status = %d';
            $params[] = (int) $args['status'];
        }

        if (!empty($args['keyword'])) {
            $like = '%' . $this->wpdb->esc_like($args['keyword']) . '%';
            $where[] = '(a.name LIKE %s OR p.title LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        if ($args['product_status'] !== '' && $args['product_status'] !== null) {
            $where[] = 'p.status = %d';
            $params[] = (int) $args['product_status'];
        }

        if ((int) $args['product_id'] > 0) {
            $where[] = 'a.product_id = %d';
            $params[] = (int) $args['product_id'];
        }

        if (!empty($args['time_active'])) {
            $now = current_time('mysql');
            $where[] = "(a.start_time IS NULL OR a.start_time = '0000-00-00 00:00:00' OR a.start_time <= %s)";
            $where[] = "(a.end_time IS NULL OR a.end_time = '0000-00-00 00:00:00' OR a.end_time >= %s)";
            $params[] = $now;
            $params[] = $now;
        }

        $limit = max(1, (int) $args['limit']);
        $offset = max(0, (int) $args['offset']);
        $sql = "SELECT a.*, p.title AS product_title, p.main_image AS product_image
                FROM {$this->table_activities} a
                LEFT JOIN {$table_products} p ON p.id = a.product_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY a.id DESC
                LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, $params));
        foreach ($rows as $row) {
            $row->stock_available = max(0, (int) $row->stock_total - (int) $row->stock_locked - (int) $row->stock_sold);
        }

        return $rows;
    }

    /**
     * 活动总数
     *
     * @param array $args
     * @return int
     */
    public function get_activities_count($args = []) {
        $defaults = [
            'status' => '',
            'keyword' => '',
            'time_active' => false,
            'product_status' => '',
            'product_id' => 0,
        ];
        $args = wp_parse_args($args, $defaults);

        $table_products = $this->db->get_table('products');
        $where = ['1=1'];
        $params = [];

        if ($args['status'] !== '' && $args['status'] !== null) {
            $where[] = 'a.status = %d';
            $params[] = (int) $args['status'];
        }
        if (!empty($args['keyword'])) {
            $like = '%' . $this->wpdb->esc_like($args['keyword']) . '%';
            $where[] = '(a.name LIKE %s OR p.title LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        if ($args['product_status'] !== '' && $args['product_status'] !== null) {
            $where[] = 'p.status = %d';
            $params[] = (int) $args['product_status'];
        }

        if ((int) $args['product_id'] > 0) {
            $where[] = 'a.product_id = %d';
            $params[] = (int) $args['product_id'];
        }

        if (!empty($args['time_active'])) {
            $now = current_time('mysql');
            $where[] = "(a.start_time IS NULL OR a.start_time = '0000-00-00 00:00:00' OR a.start_time <= %s)";
            $where[] = "(a.end_time IS NULL OR a.end_time = '0000-00-00 00:00:00' OR a.end_time >= %s)";
            $params[] = $now;
            $params[] = $now;
        }

        $sql = "SELECT COUNT(*)
                FROM {$this->table_activities} a
                LEFT JOIN {$table_products} p ON p.id = a.product_id
                WHERE " . implode(' AND ', $where);
        $prepared = !empty($params) ? $this->wpdb->prepare($sql, $params) : $sql;
        return (int) $this->wpdb->get_var($prepared);
    }

    /**
     * 按状态批量统计助力活动数量，避免后台筛选反复 COUNT。
     *
     * @param array $args
     * @return array<int, int>
     */
    public function get_activity_status_counts($args = []) {
        $defaults = [
            'keyword' => '',
            'time_active' => false,
            'product_status' => '',
            'product_id' => 0,
        ];
        $args = wp_parse_args($args, $defaults);

        $table_products = $this->db->get_table('products');
        $where = ['1=1'];
        $params = [];

        if (!empty($args['keyword'])) {
            $like = '%' . $this->wpdb->esc_like($args['keyword']) . '%';
            $where[] = '(a.name LIKE %s OR p.title LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        if ($args['product_status'] !== '' && $args['product_status'] !== null) {
            $where[] = 'p.status = %d';
            $params[] = (int) $args['product_status'];
        }

        if ((int) $args['product_id'] > 0) {
            $where[] = 'a.product_id = %d';
            $params[] = (int) $args['product_id'];
        }

        if (!empty($args['time_active'])) {
            $now = current_time('mysql');
            $where[] = "(a.start_time IS NULL OR a.start_time = '0000-00-00 00:00:00' OR a.start_time <= %s)";
            $where[] = "(a.end_time IS NULL OR a.end_time = '0000-00-00 00:00:00' OR a.end_time >= %s)";
            $params[] = $now;
            $params[] = $now;
        }

        $sql = "SELECT a.status, COUNT(*) AS total
                FROM {$this->table_activities} a
                LEFT JOIN {$table_products} p ON p.id = a.product_id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY a.status";
        $prepared = !empty($params) ? $this->wpdb->prepare($sql, $params) : $sql;
        $rows = $this->wpdb->get_results($prepared);

        $counts = [];
        foreach ((array) $rows as $row) {
            $status = isset($row->status) ? (int) $row->status : 0;
            $counts[$status] = isset($row->total) ? (int) $row->total : 0;
        }

        return $counts;
    }

    /**
     * 创建助力单
     *
     * @param int $activity_id
     * @param int $user_id
     * @return array|WP_Error
     */
    public function create_campaign($activity_id, $user_id) {
        $activity_id = (int) $activity_id;
        $user_id = (int) $user_id;
        if ($activity_id <= 0 || $user_id <= 0) {
            return new WP_Error('invalid_params', __('参数错误', 'qilingshop'));
        }

        // 先清理一批超时助力单，避免状态滞后导致体验异常。
        $this->process_expired_campaigns(200);

        $lock_name = $this->build_activity_lock_name($activity_id);
        if (!$this->acquire_named_lock($lock_name, 5)) {
            return new WP_Error('activity_busy', __('活动处理中，请稍后再试', 'qilingshop'));
        }

        try {
            $activity = $this->get_activity($activity_id);
            if (!$activity || (int) $activity->status !== self::ACTIVITY_ENABLED) {
                return new WP_Error('activity_invalid', __('助力活动不可用', 'qilingshop'));
            }
            if (!$this->is_activity_time_available($activity)) {
                return new WP_Error('activity_closed', __('活动未开始或已结束', 'qilingshop'));
            }

            // 同一用户同一商品只能存在一个未结束助力单（可并行参与其他商品）。
            $existing_campaign = $this->get_user_unfinished_campaign_by_product($user_id, (int) $activity->product_id);
            if ($existing_campaign) {
                return new WP_Error(
                    'campaign_exists_for_product',
                    __('已参与该商品助力，当前助力未结束，请完成后再发起新的助力', 'qilingshop'),
                    [
                        'campaign_id' => (int) $existing_campaign->id,
                        'share_code'  => (string) $existing_campaign->share_code,
                        'status'      => (int) $existing_campaign->status,
                        'status_text' => $this->get_campaign_status_text((int) $existing_campaign->status),
                        'product_id'  => (int) $existing_campaign->product_id,
                    ]
                );
            }

            $risk_tokens = [];
            if (class_exists('QilingShop_Risk_Control')) {
                $risk_check = QilingShop_Risk_Control::instance()->begin_assist_create($user_id, qilingshop_security()->get_client_ip());
                if (is_wp_error($risk_check)) {
                    return $risk_check;
                }
                $risk_tokens = is_array($risk_check) ? $risk_check : [];
            }

            $start_price = (float) $activity->start_price;
            if ($start_price <= 0) {
                $start_price = $this->get_product_base_price((int) $activity->product_id);
            }
            $min_price = (float) $activity->min_price;
            if ($min_price < 0) {
                $min_price = 0;
            }
            if ($start_price < $min_price) {
                $start_price = $min_price;
            }

            $expire_hours = max(1, (int) $activity->expire_hours);
            $share_code = $this->generate_share_code();
            $created_at = current_time('mysql');
            $expire_at = date('Y-m-d H:i:s', current_time('timestamp') + $expire_hours * HOUR_IN_SECONDS);
            $reserve_qty = 1;

            // 发起活动即预占库存，避免达成后无库存可支付。
            $reserved = $this->lock_activity_stock((int) $activity->id, $reserve_qty, true);
            if (!$reserved) {
                return new WP_Error('stock_not_enough', __('活动库存不足，暂时无法发起助力', 'qilingshop'));
            }

            $campaign_id = $this->db->insert('assist_campaigns', [
                'activity_id'     => $activity_id,
                'user_id'         => $user_id,
                'product_id'      => (int) $activity->product_id,
                'start_price'     => $start_price,
                'current_price'   => $start_price,
                'min_price'       => $min_price,
                'helped_amount'   => 0,
                'help_count'      => 0,
                'target_helpers'  => max(0, (int) $activity->target_helpers),
                'stock_reserved'  => 1,
                'reserved_qty'    => $reserve_qty,
                'status'          => self::CAMPAIGN_ONGOING,
                'share_code'      => $share_code,
                'expire_at'       => $expire_at,
                'created_at'      => $created_at,
                'updated_at'      => $created_at,
            ]);

            if (!$campaign_id) {
                $this->release_activity_stock((int) $activity->id, $reserve_qty);
                return new WP_Error('create_campaign_failed', __('创建助力活动失败，请稍后重试', 'qilingshop'));
            }

            $this->log_action((int) $campaign_id, $activity_id, $user_id, $user_id, 'user', 'create', 0, $start_price, $start_price, __('发起助力', 'qilingshop'));
            if (!empty($risk_tokens) && class_exists('QilingShop_Risk_Control')) {
                QilingShop_Risk_Control::instance()->commit_tokens($risk_tokens);
            }

            $campaign = $this->get_campaign((int) $campaign_id, true);
            return $campaign ? $campaign : new WP_Error('campaign_not_found', __('创建后读取助力单失败', 'qilingshop'));
        } finally {
            $this->release_named_lock($lock_name);
        }
    }

    public function delete_activity($activity_id) {
        $activity_id = (int) $activity_id;
        if ($activity_id <= 0) {
            return new WP_Error('invalid_activity', __('活动不存在', 'qilingshop'));
        }

        $lock_name = $this->build_activity_lock_name($activity_id);
        if (!$this->acquire_named_lock($lock_name, 5)) {
            return new WP_Error('activity_busy', __('活动处理中，请稍后重试。', 'qilingshop'));
        }

        try {
            $active_count = (int) $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$this->table_campaigns}
                 WHERE activity_id = %d
                   AND status IN (%d, %d, %d)",
                $activity_id,
                self::CAMPAIGN_ONGOING,
                self::CAMPAIGN_READY,
                self::CAMPAIGN_ORDER_PENDING
            ));

            if ($active_count > 0) {
                return new WP_Error('delete_blocked', __('当前活动仍有用户参与中，暂不可删除。请等待活动流程结束后再删除。', 'qilingshop'));
            }

            $deleted = $this->db->delete('assist_activities', ['id' => $activity_id], ['%d']);
            if ($deleted === false) {
                return new WP_Error('delete_failed', __('删除失败，请稍后重试。', 'qilingshop'));
            }

            return true;
        } finally {
            $this->release_named_lock($lock_name);
        }
    }

    /**
     * 获取用户在指定商品上的未结束助力单
     *
     * @param int $user_id
     * @param int $product_id
     * @return object|null
     */
    private function get_user_unfinished_campaign_by_product($user_id, $product_id) {
        $user_id = (int) $user_id;
        $product_id = (int) $product_id;
        if ($user_id <= 0 || $product_id <= 0) {
            return null;
        }

        return $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT id, product_id, share_code, status
             FROM {$this->table_campaigns}
             WHERE user_id = %d
               AND product_id = %d
               AND status IN (%d, %d, %d)
             ORDER BY id DESC
             LIMIT 1",
            $user_id,
            $product_id,
            self::CAMPAIGN_ONGOING,
            self::CAMPAIGN_READY,
            self::CAMPAIGN_ORDER_PENDING
        ));
    }

    /**
     * 获取助力单（可带活动信息）
     *
     * @param int  $campaign_id
     * @param bool $with_activity
     * @return object|null
     */
    public function get_campaign($campaign_id, $with_activity = false) {
        $campaign_id = (int) $campaign_id;
        if ($campaign_id <= 0) {
            return null;
        }

        if ($with_activity) {
            $table_products = $this->db->get_table('products');
            return $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT c.*, a.name AS activity_name, a.help_min, a.help_max, a.expire_hours, a.auto_restore_stock,
                        p.title AS product_title, p.main_image AS product_image
                 FROM {$this->table_campaigns} c
                 LEFT JOIN {$this->table_activities} a ON a.id = c.activity_id
                 LEFT JOIN {$table_products} p ON p.id = c.product_id
                 WHERE c.id = %d
                 LIMIT 1",
                $campaign_id
            ));
        }

        return $this->db->get_by_id('assist_campaigns', $campaign_id);
    }

    /**
     * 事务内锁定助力单
     *
     * @param int  $campaign_id
     * @param bool $with_activity
     * @return object|null
     */
    private function get_campaign_for_update($campaign_id, $with_activity = false) {
        $campaign_id = (int) $campaign_id;
        if ($campaign_id <= 0) {
            return null;
        }

        if ($with_activity) {
            $table_products = $this->db->get_table('products');
            return $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT c.*, a.name AS activity_name, a.help_min, a.help_max, a.expire_hours, a.auto_restore_stock,
                        p.title AS product_title, p.main_image AS product_image
                 FROM {$this->table_campaigns} c
                 LEFT JOIN {$this->table_activities} a ON a.id = c.activity_id
                 LEFT JOIN {$table_products} p ON p.id = c.product_id
                 WHERE c.id = %d
                 LIMIT 1
                 FOR UPDATE",
                $campaign_id
            ));
        }

        return $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->table_campaigns} WHERE id = %d LIMIT 1 FOR UPDATE",
            $campaign_id
        ));
    }

    /**
     * 通过分享码获取助力单
     *
     * @param string $share_code
     * @return object|null
     */
    public function get_campaign_by_share_code($share_code) {
        $share_code = sanitize_text_field((string) $share_code);
        if ($share_code === '') {
            return null;
        }
        return $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT c.*, a.name AS activity_name, a.help_min, a.help_max, a.expire_hours, a.auto_restore_stock,
                    p.title AS product_title, p.main_image AS product_image
             FROM {$this->table_campaigns} c
             LEFT JOIN {$this->table_activities} a ON a.id = c.activity_id
             LEFT JOIN {$this->db->get_table('products')} p ON p.id = c.product_id
             WHERE c.share_code = %s
             LIMIT 1",
            $share_code
        ));
    }

    /**
     * 助力单列表（后台/前台共用）
     *
     * @param array $args
     * @return array
     */
    public function get_campaigns($args = []) {
        $defaults = [
            'user_id' => 0,
            'status' => '',
            'keyword' => '',
            'limit' => 20,
            'offset' => 0,
        ];
        $args = wp_parse_args($args, $defaults);

        $table_products = $this->db->get_table('products');
        $where = ['1=1'];
        $params = [];

        if ((int) $args['user_id'] > 0) {
            $where[] = 'c.user_id = %d';
            $params[] = (int) $args['user_id'];
        }
        if ($args['status'] !== '' && $args['status'] !== null) {
            $where[] = 'c.status = %d';
            $params[] = (int) $args['status'];
        }
        if (!empty($args['keyword'])) {
            $like = '%' . $this->wpdb->esc_like($args['keyword']) . '%';
            $where[] = '(c.share_code LIKE %s OR p.title LIKE %s OR a.name LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $limit = max(1, (int) $args['limit']);
        $offset = max(0, (int) $args['offset']);
        $sql = "SELECT c.*, a.name AS activity_name, p.title AS product_title
                FROM {$this->table_campaigns} c
                LEFT JOIN {$this->table_activities} a ON a.id = c.activity_id
                LEFT JOIN {$table_products} p ON p.id = c.product_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY c.id DESC
                LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        return $this->wpdb->get_results($this->wpdb->prepare($sql, $params));
    }

    /**
     * 助力单总数
     *
     * @param array $args
     * @return int
     */
    public function get_campaigns_count($args = []) {
        $defaults = [
            'user_id' => 0,
            'status' => '',
            'keyword' => '',
        ];
        $args = wp_parse_args($args, $defaults);

        $table_products = $this->db->get_table('products');
        $where = ['1=1'];
        $params = [];

        if ((int) $args['user_id'] > 0) {
            $where[] = 'c.user_id = %d';
            $params[] = (int) $args['user_id'];
        }
        if ($args['status'] !== '' && $args['status'] !== null) {
            $where[] = 'c.status = %d';
            $params[] = (int) $args['status'];
        }
        if (!empty($args['keyword'])) {
            $like = '%' . $this->wpdb->esc_like($args['keyword']) . '%';
            $where[] = '(c.share_code LIKE %s OR p.title LIKE %s OR a.name LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "SELECT COUNT(*)
                FROM {$this->table_campaigns} c
                LEFT JOIN {$this->table_activities} a ON a.id = c.activity_id
                LEFT JOIN {$table_products} p ON p.id = c.product_id
                WHERE " . implode(' AND ', $where);
        $prepared = !empty($params) ? $this->wpdb->prepare($sql, $params) : $sql;
        return (int) $this->wpdb->get_var($prepared);
    }

    /**
     * 按状态批量统计助力单数量，避免后台筛选反复 COUNT。
     *
     * @param array $args
     * @return array<int, int>
     */
    public function get_campaign_status_counts($args = []) {
        $defaults = [
            'user_id' => 0,
            'keyword' => '',
        ];
        $args = wp_parse_args($args, $defaults);

        $table_products = $this->db->get_table('products');
        $where = ['1=1'];
        $params = [];

        if ((int) $args['user_id'] > 0) {
            $where[] = 'c.user_id = %d';
            $params[] = (int) $args['user_id'];
        }
        if (!empty($args['keyword'])) {
            $like = '%' . $this->wpdb->esc_like($args['keyword']) . '%';
            $where[] = '(c.share_code LIKE %s OR p.title LIKE %s OR a.name LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "SELECT c.status, COUNT(*) AS total
                FROM {$this->table_campaigns} c
                LEFT JOIN {$this->table_activities} a ON a.id = c.activity_id
                LEFT JOIN {$table_products} p ON p.id = c.product_id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY c.status";
        $prepared = !empty($params) ? $this->wpdb->prepare($sql, $params) : $sql;
        $rows = $this->wpdb->get_results($prepared);

        $counts = [];
        foreach ((array) $rows as $row) {
            $status = isset($row->status) ? (int) $row->status : 0;
            $counts[$status] = isset($row->total) ? (int) $row->total : 0;
        }

        return $counts;
    }

    /**
     * 清除助力记录和对应日志。
     *
     * 默认只清理已结束状态，避免误删仍会影响用户流程的助力单。
     *
     * @param array $args
     * @return int
     */
    public function clear_campaign_records($args = []) {
        $defaults = [
            'statuses' => [
                self::CAMPAIGN_COMPLETED,
                self::CAMPAIGN_EXPIRED,
                self::CAMPAIGN_CANCELLED,
                self::CAMPAIGN_REFUNDED,
            ],
            'keyword' => '',
        ];
        $args = wp_parse_args($args, $defaults);

        $statuses = array_values(array_unique(array_filter(array_map('intval', (array) $args['statuses']), function($status) {
            return in_array($status, [
                self::CAMPAIGN_COMPLETED,
                self::CAMPAIGN_EXPIRED,
                self::CAMPAIGN_CANCELLED,
                self::CAMPAIGN_REFUNDED,
            ], true);
        })));

        if (empty($statuses)) {
            return 0;
        }

        $table_products = $this->db->get_table('products');
        $where = [];
        $params = [];

        $status_placeholders = implode(',', array_fill(0, count($statuses), '%d'));
        $where[] = "c.status IN ({$status_placeholders})";
        $params = array_merge($params, $statuses);

        if (!empty($args['keyword'])) {
            $like = '%' . $this->wpdb->esc_like((string) $args['keyword']) . '%';
            $where[] = '(c.share_code LIKE %s OR p.title LIKE %s OR a.name LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "SELECT c.id
                FROM {$this->table_campaigns} c
                LEFT JOIN {$this->table_activities} a ON a.id = c.activity_id
                LEFT JOIN {$table_products} p ON p.id = c.product_id
                WHERE " . implode(' AND ', $where);
        $ids = array_map('intval', (array) $this->wpdb->get_col($this->wpdb->prepare($sql, $params)));
        if (empty($ids)) {
            return 0;
        }

        $deleted = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $this->wpdb->query($this->wpdb->prepare(
                "DELETE FROM {$this->table_logs} WHERE campaign_id IN ({$placeholders})",
                $chunk
            ));

            $result = $this->wpdb->query($this->wpdb->prepare(
                "DELETE FROM {$this->table_campaigns} WHERE id IN ({$placeholders})",
                $chunk
            ));
            if ($result !== false) {
                $deleted += (int) $result;
            }
        }

        return $deleted;
    }

    /**
     * 执行助力
     *
     * @param int $campaign_id
     * @param int $helper_id
     * @return array|WP_Error
     */
    public function help_campaign($campaign_id, $helper_id) {
        $campaign_id = (int) $campaign_id;
        $helper_id = (int) $helper_id;

        if ($campaign_id <= 0 || $helper_id <= 0) {
            return new WP_Error('invalid_params', __('参数错误', 'qilingshop'));
        }

        $campaign = $this->get_campaign($campaign_id, true);
        if (!$campaign) {
            return new WP_Error('campaign_not_found', __('助力单不存在', 'qilingshop'));
        }
        if ((int) $campaign->status !== self::CAMPAIGN_ONGOING) {
            return new WP_Error('campaign_status', __('当前助力单无法继续助力', 'qilingshop'));
        }
        if ((int) $campaign->user_id === $helper_id) {
            return new WP_Error('self_help', __('不能给自己助力', 'qilingshop'));
        }
        if (strtotime((string) $campaign->expire_at) <= current_time('timestamp')) {
            $this->mark_campaign_expired($campaign_id);
            return new WP_Error('campaign_expired', __('助力活动已过期', 'qilingshop'));
        }

        $risk_tokens = [];
        if (class_exists('QilingShop_Risk_Control')) {
            $risk_check = QilingShop_Risk_Control::instance()->begin_assist_help($helper_id, qilingshop_security()->get_client_ip());
            if (is_wp_error($risk_check)) {
                return $risk_check;
            }
            $risk_tokens = is_array($risk_check) ? $risk_check : [];
        }

        $this->wpdb->query('START TRANSACTION');
        try {
            $locked = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT * FROM {$this->table_campaigns} WHERE id = %d LIMIT 1 FOR UPDATE",
                $campaign_id
            ));
            if (!$locked || (int) $locked->status !== self::CAMPAIGN_ONGOING) {
                throw new Exception(__('助力单状态已变化，请刷新后重试', 'qilingshop'));
            }
            if ($this->has_helped($campaign_id, $helper_id)) {
                throw new Exception(__('已助力过该活动', 'qilingshop'));
            }

            $remaining = (float) $locked->current_price - (float) $locked->min_price;
            if ($remaining <= 0) {
                throw new Exception(__('该助力已达到最低价', 'qilingshop'));
            }

            $cut_amount = $this->random_cut_amount((float) $campaign->help_min, (float) $campaign->help_max);
            $cut_amount = min($cut_amount, $remaining);
            $new_price = round(max((float) $locked->min_price, (float) $locked->current_price - $cut_amount), 2);
            $new_help_count = (int) $locked->help_count + 1;

            $new_status = self::CAMPAIGN_ONGOING;
            $target_helpers = (int) $locked->target_helpers;
            if ($new_price <= (float) $locked->min_price || ($target_helpers > 0 && $new_help_count >= $target_helpers)) {
                $new_status = self::CAMPAIGN_READY;
            }

            $updated = $this->wpdb->update(
                $this->table_campaigns,
                [
                    'current_price' => $new_price,
                    'helped_amount' => (float) $locked->helped_amount + $cut_amount,
                    'help_count'    => $new_help_count,
                    'status'        => $new_status,
                    'updated_at'    => current_time('mysql'),
                    'last_helped_at'=> current_time('mysql'),
                ],
                ['id' => $campaign_id]
            );

            if ($updated === false) {
                throw new Exception(__('更新助力数据失败', 'qilingshop'));
            }

            $this->log_action(
                $campaign_id,
                (int) $locked->activity_id,
                (int) $locked->user_id,
                $helper_id,
                'user',
                'help',
                $cut_amount,
                (float) $locked->current_price,
                $new_price,
                __('好友助力成功', 'qilingshop')
            );

            $this->wpdb->query('COMMIT');
            if (!empty($risk_tokens) && class_exists('QilingShop_Risk_Control')) {
                QilingShop_Risk_Control::instance()->commit_tokens($risk_tokens);
            }

            return [
                'campaign_id' => $campaign_id,
                'cut_amount'  => $cut_amount,
                'new_price'   => $new_price,
                'help_count'  => $new_help_count,
                'status'      => $new_status,
            ];
        } catch (Exception $e) {
            $this->wpdb->query('ROLLBACK');
            return new WP_Error('help_failed', $e->getMessage());
        }
    }

    /**
     * 创建助力差额订单（并锁定助力库存）
     *
     * @param int   $campaign_id
     * @param int   $user_id
     * @param array $checkout
     * @return array|WP_Error
     */
    public function create_campaign_order($campaign_id, $user_id, $checkout = []) {
        $campaign_id = (int) $campaign_id;
        $user_id = (int) $user_id;
        if ($campaign_id <= 0 || $user_id <= 0) {
            return new WP_Error('invalid_params', __('参数错误', 'qilingshop'));
        }

        $this->wpdb->query('START TRANSACTION');

        try {
            $campaign = $this->get_campaign_for_update($campaign_id, true);
            if (!$campaign) {
                $this->wpdb->query('ROLLBACK');
                return new WP_Error('campaign_not_found', __('助力单不存在', 'qilingshop'));
            }
            if ((int) $campaign->user_id !== $user_id) {
                $this->wpdb->query('ROLLBACK');
                return new WP_Error('no_permission', __('无权操作该助力单', 'qilingshop'));
            }
            if (!in_array((int) $campaign->status, [self::CAMPAIGN_READY, self::CAMPAIGN_ORDER_PENDING], true)) {
                $this->wpdb->query('ROLLBACK');
                return new WP_Error('invalid_status', __('当前状态不可创建支付订单', 'qilingshop'));
            }

            if ((int) $campaign->status === self::CAMPAIGN_ORDER_PENDING && (int) $campaign->pay_order_id > 0) {
                $existing = qls_shop_order()->get((int) $campaign->pay_order_id);
                if ($existing) {
                    $existing_status = (int) $existing->status;
                    if ($existing_status === QLS_Shop_Order::STATUS_PENDING) {
                        $this->wpdb->query('COMMIT');
                        $paid = false;
                        if ((float) ($existing->final_amount ?? 0) <= 0) {
                            $paid = qls_shop_order()->mark_paid((string) $existing->order_no, '', 'free');
                            if (!$paid) {
                                return new WP_Error('assist_free_finalize_failed', __('助力订单自动完成失败，请稍后重试', 'qilingshop'));
                            }
                        }
                        return [
                            'order_id' => (int) $existing->id,
                            'order_no' => (string) $existing->order_no,
                            'payment_url' => $paid ? '' : add_query_arg([
                                'pay'   => 'shop',
                                'order' => $existing->order_no,
                            ], home_url('/')),
                            'paid' => $paid,
                            'reused' => true,
                        ];
                    }

                    if ($existing_status === QLS_Shop_Order::STATUS_CANCELLED) {
                        $reset = $this->db->update('assist_campaigns', [
                            'status'       => self::CAMPAIGN_READY,
                            'pay_order_id' => 0,
                            'pay_order_no' => '',
                            'updated_at'   => current_time('mysql'),
                        ], ['id' => $campaign_id]);
                        if ($reset === false) {
                            throw new Exception('reset_campaign_order_failed');
                        }
                        $campaign->status = self::CAMPAIGN_READY;
                        $campaign->pay_order_id = 0;
                        $campaign->pay_order_no = '';
                    } elseif (in_array($existing_status, [
                        QLS_Shop_Order::STATUS_PAID,
                        QLS_Shop_Order::STATUS_SHIPPED,
                        QLS_Shop_Order::STATUS_COMPLETED,
                        QLS_Shop_Order::STATUS_REFUNDING,
                        QLS_Shop_Order::STATUS_REFUNDED,
                    ], true)) {
                        $this->wpdb->query('COMMIT');
                        if (in_array($existing_status, [
                            QLS_Shop_Order::STATUS_PAID,
                            QLS_Shop_Order::STATUS_SHIPPED,
                            QLS_Shop_Order::STATUS_COMPLETED,
                        ], true) && !qls_shop_order()->mark_paid((string) $existing->order_no, '', (string) ($existing->payment_method ?? ''))) {
                            return new WP_Error('assist_paid_finalize_failed', __('助力订单处理未完成，请稍后重试', 'qilingshop'));
                        }
                        if ($existing_status === QLS_Shop_Order::STATUS_REFUNDED) {
                            $this->mark_order_refunded((int) $existing->id);
                        }
                        return new WP_Error('order_already_paid', __('该助力单已存在已支付订单，无需重复创建', 'qilingshop'));
                    }
                }
            }

            if (strtotime((string) $campaign->expire_at) <= current_time('timestamp')) {
                $this->wpdb->query('ROLLBACK');
                $this->mark_campaign_expired($campaign_id);
                return new WP_Error('campaign_expired', __('助力活动已过期', 'qilingshop'));
            }

            $activity = $this->get_activity((int) $campaign->activity_id);
            if (!$activity) {
                throw new Exception(__('活动不存在', 'qilingshop'));
            }
            $product = qls_product()->get((int) $campaign->product_id);
            if (!$product || (int) $product->status !== 1) {
                throw new Exception(__('商品不存在或已下架', 'qilingshop'));
            }

            $sku = $this->resolve_default_sku((int) $campaign->product_id);
            if (!$sku) {
                throw new Exception(__('未找到可用商品规格', 'qilingshop'));
            }

            $quantity = max(1, (int) ($campaign->reserved_qty ?? 1));
            $has_reserved_stock = ((int) ($campaign->stock_reserved ?? 0) === 1 && $quantity > 0);
            if (!$has_reserved_stock) {
                $locked = $this->lock_activity_stock((int) $activity->id, $quantity, false);
                if (!$locked) {
                    $this->wpdb->query('ROLLBACK');
                    return new WP_Error('stock_not_enough', __('助力活动库存不足', 'qilingshop'));
                }
                $reserved_updated = $this->db->update('assist_campaigns', [
                    'stock_reserved' => 1,
                    'reserved_qty'   => $quantity,
                    'updated_at'     => current_time('mysql'),
                ], ['id' => $campaign_id]);
                if ($reserved_updated === false) {
                    throw new Exception('reserve_stock_failed');
                }
                $campaign->stock_reserved = 1;
                $campaign->reserved_qty = $quantity;
            }

            $final_amount = max(0, round((float) $campaign->current_price, 2));
            $unit_price = round($final_amount / $quantity, 2);
            $final_amount = round($unit_price * $quantity, 2);
            $payment_method = sanitize_key($checkout['payment_method'] ?? '');

            $address = $this->get_user_default_address($user_id);
            $receiver_name = sanitize_text_field($checkout['receiver_name'] ?? '');
            $receiver_phone = sanitize_text_field($checkout['receiver_phone'] ?? '');
            $receiver_province = sanitize_text_field($checkout['receiver_province'] ?? '');
            $receiver_city = sanitize_text_field($checkout['receiver_city'] ?? '');
            $receiver_district = sanitize_text_field($checkout['receiver_district'] ?? '');
            $receiver_address = sanitize_textarea_field($checkout['receiver_address'] ?? '');

            if ($receiver_name === '' && !empty($address['name'])) {
                $receiver_name = (string) $address['name'];
            }
            if ($receiver_phone === '' && !empty($address['phone'])) {
                $receiver_phone = (string) $address['phone'];
            }
            if ($receiver_province === '' && !empty($address['province'])) {
                $receiver_province = (string) $address['province'];
            }
            if ($receiver_city === '' && !empty($address['city'])) {
                $receiver_city = (string) $address['city'];
            }
            if ($receiver_district === '' && !empty($address['district'])) {
                $receiver_district = (string) $address['district'];
            }
            if ($receiver_address === '' && !empty($address['address'])) {
                $receiver_address = (string) $address['address'];
            }

            $order_data = [
                'user_id'           => $user_id,
                'total_amount'      => $final_amount,
                'shipping_fee'      => 0,
                'discount_amount'   => 0,
                'points_used'       => 0,
                'final_amount'      => $final_amount,
                'status'            => QLS_Shop_Order::STATUS_PENDING,
                'payment_method'    => $payment_method,
                'receiver_name'     => $receiver_name,
                'receiver_phone'    => $receiver_phone,
                'receiver_province' => $receiver_province,
                'receiver_city'     => $receiver_city,
                'receiver_district' => $receiver_district,
                'receiver_address'  => $receiver_address,
                'buyer_remark'      => sanitize_textarea_field($checkout['buyer_remark'] ?? ''),
            ];

            $order_no = qls_shop_order()->create($order_data);
            if (!$order_no) {
                throw new Exception('order_create_failed');
            }

            $order = qls_shop_order()->get_by_order_no($order_no);
            if (!$order) {
                throw new Exception('order_not_found');
            }

            $added = qls_shop_order()->add_items((int) $order->id, [[
                'product_id'    => (int) $campaign->product_id,
                'sku_id'        => (int) $sku->id,
                'product_title' => (string) $product->title,
                'sku_attrs'     => is_array($sku->attr_values) ? $sku->attr_values : [],
                'image'         => is_array($product->main_image) ? ($product->main_image['url'] ?? '') : $product->main_image,
                'price'         => $unit_price,
                'quantity'      => $quantity,
            ]]);
            if (!$added) {
                throw new Exception('order_item_failed');
            }

            $updated = $this->db->update('assist_campaigns', [
                'status'       => self::CAMPAIGN_ORDER_PENDING,
                'pay_order_id' => (int) $order->id,
                'pay_order_no' => (string) $order_no,
                'updated_at'   => current_time('mysql'),
            ], ['id' => $campaign_id]);

            if ($updated === false) {
                throw new Exception('campaign_update_failed');
            }

            $this->log_action(
                $campaign_id,
                (int) $activity->id,
                $user_id,
                $user_id,
                'user',
                'order_create',
                0,
                (float) $campaign->current_price,
                (float) $campaign->current_price,
                sprintf(__('创建差额支付订单：%s', 'qilingshop'), $order_no)
            );

            if (!$this->mark_order_as_assist((int) $order->id, $campaign_id, (int) $activity->id, $quantity)) {
                throw new Exception('assist_order_meta_failed');
            }

            $this->wpdb->query('COMMIT');

            $paid = false;
            if ($final_amount <= 0) {
                $paid = qls_shop_order()->mark_paid((string) $order_no, '', 'free');
                if (!$paid) {
                    return new WP_Error('assist_free_finalize_failed', __('助力订单自动完成失败，请稍后重试', 'qilingshop'));
                }
            }

            return [
                'order_id' => (int) $order->id,
                'order_no' => (string) $order_no,
                'payment_url' => $paid ? '' : add_query_arg([
                    'pay'   => 'shop',
                    'order' => $order_no,
                ], home_url('/')),
                'paid' => $paid,
                'reused' => false,
            ];
        } catch (Exception $e) {
            $this->wpdb->query('ROLLBACK');
            qilingshop_log('Assist create campaign order failed: ' . $e->getMessage(), 'error', [
                'campaign_id' => $campaign_id,
                'user_id'     => $user_id,
            ]);
            $message = $e->getMessage() !== '' ? $e->getMessage() : __('创建支付订单失败，请重试', 'qilingshop');
            return new WP_Error('create_campaign_order_failed', $message);
        }
    }

    /**
     * 订单支付回调
     *
     * @param int    $order_id
     * @param string $payment_method
     * @return void
     */
    public function handle_order_paid($order_id, $payment_method = '') {
        $this->mark_order_paid((int) $order_id);
    }

    /**
     * 订单取消回调
     *
     * @param int    $order_id
     * @param string $reason
     * @return void
     */
    public function handle_order_cancelled($order_id, $reason = '') {
        $this->mark_order_cancelled((int) $order_id, (string) $reason);
    }

    /**
     * 订单退款回调
     *
     * @param int $order_id
     * @return void
     */
    public function handle_order_refunded($order_id) {
        $this->mark_order_refunded((int) $order_id);
    }

    /**
     * 标记助力订单已支付（锁库存转已售）
     *
     * @param int $order_id
     * @return bool
     */
    public function mark_order_paid($order_id) {
        $lock_name = $this->build_assist_order_lock_name($order_id);
        if (!$this->acquire_named_lock($lock_name, 5)) {
            return false;
        }

        try {
            $this->wpdb->query('START TRANSACTION');
            $meta = $this->get_assist_order_meta($order_id);
            if (empty($meta['is_assist'])) {
                $this->wpdb->query('COMMIT');
                return true;
            }
            if (!empty($meta['stock_consumed'])) {
                $this->wpdb->query('COMMIT');
                return true;
            }

            $activity_id = (int) $meta['activity_id'];
            $campaign_id = (int) $meta['campaign_id'];
            $quantity = max(1, (int) $meta['quantity']);
            $ok = true;

            if (!empty($meta['stock_locked'])) {
                $ok = $this->consume_activity_locked_stock($activity_id, $quantity);
            }

            if (!$ok) {
                $this->wpdb->query('ROLLBACK');
                return false;
            }

            if (!$this->update_assist_order_meta($order_id, '_qls_assist_stock_locked', 'no')
                || !$this->update_assist_order_meta($order_id, '_qls_assist_stock_consumed', 'yes')) {
                $this->wpdb->query('ROLLBACK');
                return false;
            }

            $updated = $this->db->update('assist_campaigns', [
                'status'    => self::CAMPAIGN_COMPLETED,
                'stock_reserved' => 0,
                'reserved_qty'   => 0,
                'paid_at'   => current_time('mysql'),
                'updated_at'=> current_time('mysql'),
            ], ['id' => $campaign_id]);
            if ($updated === false || (int) $updated !== 1) {
                $this->wpdb->query('ROLLBACK');
                return false;
            }

            $this->log_action(
                $campaign_id,
                $activity_id,
                0,
                (int) $order_id,
                'system',
                'paid',
                0,
                0,
                0,
                __('支付成功，助力库存已核销', 'qilingshop')
            );

            $this->wpdb->query('COMMIT');
            return true;
        } catch (Exception $e) {
            $this->wpdb->query('ROLLBACK');
            qilingshop_log('Assist paid finalization failed: ' . $e->getMessage(), 'error', [
                'order_id' => (int) $order_id,
            ]);
            return false;
        } finally {
            $this->release_named_lock($lock_name);
        }
    }

    /**
     * 标记助力订单已取消（按预占策略决定是否释放库存）
     *
     * @param int    $order_id
     * @param string $reason
     * @return bool
     */
    public function mark_order_cancelled($order_id, $reason = '') {
        $lock_name = $this->build_assist_order_lock_name($order_id);
        if (!$this->acquire_named_lock($lock_name, 5)) {
            return false;
        }

        try {
            $meta = $this->get_assist_order_meta($order_id);
            if (empty($meta['is_assist'])) {
                return true;
            }

            $activity_id = (int) $meta['activity_id'];
            $campaign_id = (int) $meta['campaign_id'];
            $quantity = max(1, (int) $meta['quantity']);
            $campaign = $this->get_campaign($campaign_id);
            $has_reserved_stock = $campaign && (int) ($campaign->stock_reserved ?? 0) === 1 && (int) ($campaign->reserved_qty ?? 0) > 0;

            if (!empty($meta['stock_locked']) && !$has_reserved_stock) {
                $this->release_activity_stock($activity_id, $quantity);
                $this->update_assist_order_meta($order_id, '_qls_assist_stock_locked', 'no');
            }

            // 订单取消后恢复为可再次支付状态
            $this->db->update('assist_campaigns', [
                'status'       => self::CAMPAIGN_READY,
                'pay_order_id' => 0,
                'pay_order_no' => '',
                'updated_at'   => current_time('mysql'),
            ], ['id' => $campaign_id]);

            $this->log_action(
                $campaign_id,
                $activity_id,
                0,
                (int) $order_id,
                'system',
                'cancel',
                0,
                0,
                0,
                $reason ? $reason : ($has_reserved_stock ? __('订单取消，活动库存继续保留', 'qilingshop') : __('订单取消，库存锁已释放', 'qilingshop'))
            );

            return true;
        } finally {
            $this->release_named_lock($lock_name);
        }
    }

    /**
     * 标记助力订单已退款（默认回补助力库存）
     *
     * @param int $order_id
     * @return bool
     */
    public function mark_order_refunded($order_id) {
        $lock_name = $this->build_assist_order_lock_name($order_id);
        if (!$this->acquire_named_lock($lock_name, 5)) {
            return false;
        }

        try {
            $this->wpdb->query('START TRANSACTION');
            $meta = $this->get_assist_order_meta($order_id);
            if (empty($meta['is_assist'])) {
                $this->wpdb->query('COMMIT');
                return true;
            }
            if (!empty($meta['refunded'])) {
                $this->wpdb->query('COMMIT');
                return true;
            }

            $activity_id = (int) $meta['activity_id'];
            $campaign_id = (int) $meta['campaign_id'];
            $quantity = max(1, (int) $meta['quantity']);

            $activity = $this->get_activity($activity_id);
            $auto_restore = !$activity || (int) $activity->auto_restore_stock === 1;

            if ($auto_restore && !empty($meta['stock_consumed'])) {
                if (!$this->restore_activity_sold_stock($activity_id, $quantity)) {
                    $this->wpdb->query('ROLLBACK');
                    return false;
                }
            }

            if (!$this->update_assist_order_meta($order_id, '_qls_assist_refunded', 'yes')) {
                $this->wpdb->query('ROLLBACK');
                return false;
            }

            $updated = $this->db->update('assist_campaigns', [
                'status'      => self::CAMPAIGN_REFUNDED,
                'refunded_at' => current_time('mysql'),
                'updated_at'  => current_time('mysql'),
            ], ['id' => $campaign_id]);
            if ($updated === false) {
                $this->wpdb->query('ROLLBACK');
                return false;
            }

            $this->log_action(
                $campaign_id,
                $activity_id,
                0,
                (int) $order_id,
                'system',
                'refund',
                0,
                0,
                0,
                $auto_restore ? __('退款完成，助力库存已回补', 'qilingshop') : __('退款完成（活动配置为不回补库存）', 'qilingshop')
            );

            $this->wpdb->query('COMMIT');
            return true;
        } catch (Exception $e) {
            $this->wpdb->query('ROLLBACK');
            qilingshop_log('Assist refund finalization failed: ' . $e->getMessage(), 'error', [
                'order_id' => (int) $order_id,
            ]);
            return false;
        } finally {
            $this->release_named_lock($lock_name);
        }
    }

    /**
     * 前台轻量维护入口：按时间窗口节流到期处理
     *
     * @param array $args
     * @return array
     */
    public function maybe_process_expirations($args = []) {
        $defaults = [
            'activities_limit' => 200,
            'campaigns_limit'  => 200,
            'interval'         => 60,
            'force'            => false,
        ];
        $args = wp_parse_args($args, $defaults);

        $activities_limit = max(0, (int) $args['activities_limit']);
        $campaigns_limit = max(0, (int) $args['campaigns_limit']);
        $interval = max(10, (int) apply_filters('qls_assist_maintenance_interval', (int) $args['interval']));
        $force = !empty($args['force']);

        $result = [
            'activities' => 0,
            'campaigns'  => 0,
            'skipped'    => true,
        ];

        if ($activities_limit > 0 && $this->should_run_maintenance_task('activities', $interval, $force)) {
            $result['activities'] = (int) $this->process_expired_activities($activities_limit);
            $result['skipped'] = false;
        }

        if ($campaigns_limit > 0 && $this->should_run_maintenance_task('campaigns', $interval, $force)) {
            $result['campaigns'] = (int) $this->process_expired_campaigns($campaigns_limit);
            $result['skipped'] = false;
        }

        return $result;
    }

    /**
     * 判断维护任务是否应在本次请求执行
     *
     * @param string $task
     * @param int    $interval
     * @param bool   $force
     * @return bool
     */
    private function should_run_maintenance_task($task, $interval, $force = false) {
        if ($force) {
            return true;
        }

        $task = sanitize_key((string) $task);
        $interval = max(10, (int) $interval);
        if ($task === '') {
            return false;
        }

        $cache_key = $task . ':' . $interval;
        if (array_key_exists($cache_key, $this->maintenance_request_cache)) {
            return (bool) $this->maintenance_request_cache[$cache_key];
        }

        $transient_key = 'qls_assist_maintenance_next_' . $task;
        $now = time();
        $next_run = (int) get_transient($transient_key);
        if ($next_run > $now) {
            $this->maintenance_request_cache[$cache_key] = false;
            return false;
        }

        set_transient($transient_key, $now + $interval, $interval);
        $this->maintenance_request_cache[$cache_key] = true;
        return true;
    }

    /**
     * 外部任务：活动到期自动下架
     *
     * @param int $limit
     * @return int
     */
    public function process_expired_activities($limit = 200) {
        $limit = max(1, (int) $limit);
        $now = current_time('mysql');

        $ids = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT id
             FROM {$this->table_activities}
             WHERE status = %d
               AND end_time IS NOT NULL
               AND end_time <> '0000-00-00 00:00:00'
               AND end_time < %s
             ORDER BY id ASC
             LIMIT %d",
            self::ACTIVITY_ENABLED,
            $now,
            $limit
        ));

        if (empty($ids)) {
            return 0;
        }

        $count = 0;
        foreach ($ids as $id) {
            if ($this->update_activity_status((int) $id, self::ACTIVITY_DISABLED, self::ACTIVITY_ENABLED)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 外部任务：过期助力单处理
     *
     * @param int $limit
     * @return int
     */
    public function process_expired_campaigns($limit = 100) {
        $limit = max(1, (int) $limit);
        $now = current_time('mysql');

        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id
             FROM {$this->table_campaigns}
             WHERE status IN (%d, %d, %d)
               AND expire_at < %s
             ORDER BY id ASC
             LIMIT %d",
            self::CAMPAIGN_ONGOING,
            self::CAMPAIGN_READY,
            self::CAMPAIGN_ORDER_PENDING,
            $now,
            $limit
        ));

        if (empty($rows)) {
            return 0;
        }

        $count = 0;
        foreach ($rows as $row) {
            if ($this->mark_campaign_expired((int) $row->id)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 外部任务：对账待支付助力单
     *
     * @param int $limit
     * @return int
     */
    public function reconcile_pending_campaign_orders($limit = 100) {
        $limit = max(1, (int) $limit);
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, pay_order_id
             FROM {$this->table_campaigns}
             WHERE status = %d
               AND pay_order_id > 0
             ORDER BY id ASC
             LIMIT %d",
            self::CAMPAIGN_ORDER_PENDING,
            $limit
        ));

        if (empty($rows)) {
            return 0;
        }

        $checked = 0;
        foreach ($rows as $row) {
            $checked++;
            $order = qls_shop_order()->get((int) $row->pay_order_id);
            if (!$order) {
                $this->db->update('assist_campaigns', [
                    'status'       => self::CAMPAIGN_READY,
                    'pay_order_id' => 0,
                    'pay_order_no' => '',
                    'updated_at'   => current_time('mysql'),
                ], ['id' => (int) $row->id]);
                continue;
            }

            $status = (int) $order->status;
            if ($status === QLS_Shop_Order::STATUS_CANCELLED) {
                $this->mark_order_cancelled((int) $order->id, __('系统巡检：待支付订单已取消', 'qilingshop'));
            } elseif (in_array($status, [
                QLS_Shop_Order::STATUS_PAID,
                QLS_Shop_Order::STATUS_SHIPPED,
                QLS_Shop_Order::STATUS_COMPLETED,
                QLS_Shop_Order::STATUS_REFUNDING,
                QLS_Shop_Order::STATUS_REFUNDED,
            ], true)) {
                $this->mark_order_paid((int) $order->id);
                if ($status === QLS_Shop_Order::STATUS_REFUNDED) {
                    $this->mark_order_refunded((int) $order->id);
                }
            }
        }

        return $checked;
    }

    /**
     * 助力状态文本
     *
     * @param int $status
     * @return string
     */
    public function get_campaign_status_text($status) {
        $map = [
            self::CAMPAIGN_ONGOING       => __('助力中', 'qilingshop'),
            self::CAMPAIGN_READY         => __('待支付', 'qilingshop'),
            self::CAMPAIGN_ORDER_PENDING => __('待付款', 'qilingshop'),
            self::CAMPAIGN_COMPLETED     => __('已完成', 'qilingshop'),
            self::CAMPAIGN_EXPIRED       => __('助力失败', 'qilingshop'),
            self::CAMPAIGN_CANCELLED     => __('已取消', 'qilingshop'),
            self::CAMPAIGN_REFUNDED      => __('已退款', 'qilingshop'),
        ];
        return $map[(int) $status] ?? __('未知', 'qilingshop');
    }

    /**
     * 读取助力日志
     *
     * @param int $campaign_id
     * @param int $limit
     * @return array
     */
    public function get_campaign_logs($campaign_id, $limit = 50) {
        $campaign_id = (int) $campaign_id;
        $limit = max(1, (int) $limit);
        if ($campaign_id <= 0) {
            return [];
        }
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT *
             FROM {$this->table_logs}
             WHERE campaign_id = %d
             ORDER BY id DESC
             LIMIT %d",
            $campaign_id,
            $limit
        ));
    }

    /**
     * 读取助力者列表（用于前台展示）
     *
     * @param int $campaign_id
     * @param int $limit
     * @return array
     */
    public function get_helper_logs($campaign_id, $limit = 50) {
        $campaign_id = (int) $campaign_id;
        $limit = max(1, (int) $limit);
        if ($campaign_id <= 0) {
            return [];
        }
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT *
             FROM {$this->table_logs}
             WHERE campaign_id = %d
               AND action = %s
             ORDER BY id DESC
             LIMIT %d",
            $campaign_id,
            'help',
            $limit
        ));
    }

    /**
     * 活动数据清洗
     *
     * @param array $data
     * @param bool  $is_update
     * @return array|WP_Error
     */
    private function sanitize_activity_data($data, $is_update = false, $activity_id = 0) {
        $product_id = (int) ($data['product_id'] ?? 0);
        if ($product_id <= 0) {
            return new WP_Error('product_required', __('请选择助力商品', 'qilingshop'));
        }

        $product = qls_product()->get($product_id);
        if (!$product || (int) $product->status !== 1) {
            return new WP_Error('product_invalid', __('商品不存在或未上架', 'qilingshop'));
        }

        $start_price = (float) ($data['start_price'] ?? 0);
        if ($start_price <= 0) {
            $start_price = $this->get_product_base_price($product_id);
        }
        $min_price = (float) ($data['min_price'] ?? 0);
        if ($min_price < 0) {
            $min_price = 0;
        }
        if ($min_price > $start_price) {
            return new WP_Error('invalid_price_range', __('最低价不能高于起始价', 'qilingshop'));
        }

        $help_min = (float) ($data['help_min'] ?? 0.1);
        $help_max = (float) ($data['help_max'] ?? 1);
        if ($help_min <= 0) {
            $help_min = 0.1;
        }
        if ($help_max < $help_min) {
            $help_max = $help_min;
        }

        $stock_total = max(0, (int) ($data['stock_total'] ?? 0));
        $stock_locked = max(0, (int) ($data['stock_locked'] ?? 0));
        $stock_sold = max(0, (int) ($data['stock_sold'] ?? 0));

        $old = null;
        if ($is_update && (int) $activity_id > 0) {
            $old = $this->get_activity((int) $activity_id);
            if ($old) {
                $stock_locked = max($stock_locked, (int) $old->stock_locked);
                $stock_sold = max($stock_sold, (int) $old->stock_sold);
                if ($stock_total < ($stock_locked + $stock_sold)) {
                    $stock_total = $stock_locked + $stock_sold;
                }
            }
        }
        if ($stock_total <= 0) {
            return new WP_Error('invalid_stock', __('助力库存必须大于 0', 'qilingshop'));
        }

        $start_time = $this->normalize_datetime_input($data['start_time'] ?? '');
        $end_time = $this->normalize_datetime_input($data['end_time'] ?? '');
        $status = !empty($data['status']) ? self::ACTIVITY_ENABLED : self::ACTIVITY_DISABLED;
        $result = [
            'name'               => sanitize_text_field($data['name'] ?? $product->title),
            'product_id'         => $product_id,
            'start_price'        => round($start_price, 2),
            'min_price'          => round($min_price, 2),
            'help_min'           => round($help_min, 2),
            'help_max'           => round($help_max, 2),
            'target_helpers'     => max(0, (int) ($data['target_helpers'] ?? 0)),
            'expire_hours'       => max(1, (int) ($data['expire_hours'] ?? 24)),
            'stock_total'        => $stock_total,
            'stock_locked'       => $stock_locked,
            'stock_sold'         => $stock_sold,
            'status'             => $status,
            'auto_restore_stock' => !isset($data['auto_restore_stock']) || !empty($data['auto_restore_stock']) ? 1 : 0,
            'start_time'         => $start_time,
            'end_time'           => $end_time,
            'updated_at'         => current_time('mysql'),
        ];

        if (!$is_update) {
            $result['created_at'] = current_time('mysql');
        }

        return $result;
    }

    /**
     * 活动时间是否可用
     *
     * @param object $activity
     * @return bool
     */
    private function is_activity_time_available($activity) {
        $now = current_time('timestamp');
        $start_ts = $this->to_timestamp($activity->start_time ?? null);
        if ($start_ts !== null && $start_ts > $now) {
            return false;
        }
        $end_ts = $this->to_timestamp($activity->end_time ?? null);
        if ($end_ts !== null && $end_ts < $now) {
            return false;
        }
        return true;
    }

    /**
     * 规范化日期时间输入
     *
     * @param mixed $raw
     * @return string|null
     */
    private function normalize_datetime_input($raw) {
        $raw = trim((string) $raw);
        if ($raw === '' || $raw === '0000-00-00 00:00:00') {
            return null;
        }

        $ts = strtotime($raw);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $ts);
    }

    /**
     * 安全转换时间戳（兼容空值/零时间）
     *
     * @param mixed $value
     * @return int|null
     */
    private function to_timestamp($value) {
        $value = trim((string) $value);
        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }

        return (int) $ts;
    }

    /**
     * 获取商品基础价格
     *
     * @param int $product_id
     * @return float
     */
    private function get_product_base_price($product_id) {
        $product = qls_product()->get((int) $product_id);
        if (!$product) {
            return 0;
        }
        $price = (float) ($product->min_price ?? 0);
        if ($price > 0) {
            return $price;
        }
        $sku = $this->resolve_default_sku((int) $product_id);
        if ($sku) {
            if ((float) $sku->sale_price > 0) {
                return (float) $sku->sale_price;
            }
            return (float) $sku->price;
        }
        return 0;
    }

    /**
     * 解析默认 SKU（优先 is_default=1）
     *
     * @param int $product_id
     * @return object|null
     */
    private function resolve_default_sku($product_id) {
        $skus = qls_product()->get_skus((int) $product_id);
        if (empty($skus)) {
            return null;
        }

        foreach ($skus as $sku) {
            if ((int) $sku->status === 1 && (int) $sku->is_default === 1) {
                return $sku;
            }
        }
        foreach ($skus as $sku) {
            if ((int) $sku->status === 1) {
                return $sku;
            }
        }
        return null;
    }

    /**
     * 生成分享码
     *
     * @return string
     */
    private function generate_share_code() {
        for ($i = 0; $i < 8; $i++) {
            $code = strtoupper(wp_generate_password(10, false, false));
            $exists = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT id FROM {$this->table_campaigns} WHERE share_code = %s LIMIT 1",
                $code
            ));
            if (!$exists) {
                return $code;
            }
        }
        return strtoupper(uniqid('AS', false));
    }

    /**
     * 随机减价金额
     *
     * @param float $min
     * @param float $max
     * @return float
     */
    private function random_cut_amount($min, $max) {
        $min_cents = max(1, (int) round($min * 100));
        $max_cents = max($min_cents, (int) round($max * 100));
        $value = mt_rand($min_cents, $max_cents);
        return round($value / 100, 2);
    }

    /**
     * 检查用户是否已助力
     *
     * @param int $campaign_id
     * @param int $helper_id
     * @return bool
     */
    private function has_helped($campaign_id, $helper_id) {
        $count = (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$this->table_logs}
             WHERE campaign_id = %d
               AND action = %s
               AND actor_id = %d",
            (int) $campaign_id,
            'help',
            (int) $helper_id
        ));
        return $count > 0;
    }

    /**
     * 获取用户默认收货地址
     *
     * @param int $user_id
     * @return array
     */
    private function get_user_default_address($user_id) {
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT *
             FROM {$this->db->get_table('user_addresses')}
             WHERE user_id = %d
             ORDER BY is_default DESC, id DESC
             LIMIT 1",
            (int) $user_id
        ), ARRAY_A);
        return is_array($row) ? $row : [];
    }

    /**
     * 锁定活动库存
     *
     * @param int  $activity_id
     * @param int  $quantity
     * @param bool $require_enabled
     * @return bool
     */
    private function lock_activity_stock($activity_id, $quantity, $require_enabled = true) {
        $activity_id = (int) $activity_id;
        $quantity = max(1, (int) $quantity);
        $sql = "UPDATE {$this->table_activities}
                SET stock_locked = stock_locked + %d,
                    updated_at = %s
                WHERE id = %d";
        $params = [
            $quantity,
            current_time('mysql'),
            $activity_id,
        ];
        if ($require_enabled) {
            $sql .= " AND status = %d";
            $params[] = self::ACTIVITY_ENABLED;
        }
        $sql .= " AND (stock_total - stock_locked - stock_sold) >= %d";
        $params[] = $quantity;

        $affected = $this->wpdb->query($this->wpdb->prepare($sql, $params));
        return $affected !== false && $affected > 0;
    }

    /**
     * 释放活动锁定库存
     *
     * @param int $activity_id
     * @param int $quantity
     * @return bool
     */
    private function release_activity_stock($activity_id, $quantity) {
        $activity_id = (int) $activity_id;
        $quantity = max(1, (int) $quantity);
        $affected = $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table_activities}
             SET stock_locked = GREATEST(0, stock_locked - %d),
                 updated_at = %s
             WHERE id = %d",
            $quantity,
            current_time('mysql'),
            $activity_id
        ));
        return $affected !== false;
    }

    /**
     * 锁定库存转已售
     *
     * @param int $activity_id
     * @param int $quantity
     * @return bool
     */
    private function consume_activity_locked_stock($activity_id, $quantity) {
        $activity_id = (int) $activity_id;
        $quantity = max(1, (int) $quantity);
        $affected = $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table_activities}
             SET stock_locked = stock_locked - %d,
                 stock_sold = stock_sold + %d,
                 updated_at = %s
             WHERE id = %d
               AND stock_locked >= %d",
            $quantity,
            $quantity,
            current_time('mysql'),
            $activity_id,
            $quantity
        ));
        return $affected !== false && $affected > 0;
    }

    /**
     * 回补已售库存
     *
     * @param int $activity_id
     * @param int $quantity
     * @return bool
     */
    private function restore_activity_sold_stock($activity_id, $quantity) {
        $activity_id = (int) $activity_id;
        $quantity = max(1, (int) $quantity);
        $affected = $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table_activities}
             SET stock_sold = GREATEST(0, stock_sold - %d),
                 updated_at = %s
             WHERE id = %d",
            $quantity,
            current_time('mysql'),
            $activity_id
        ));
        return $affected !== false;
    }

    /**
     * 标记助力单过期
     *
     * @param int $campaign_id
     * @return bool
     */
    private function mark_campaign_expired($campaign_id) {
        $campaign_id = (int) $campaign_id;
        $lock_name = 'qls_assist_expire_' . $campaign_id;
        if ($campaign_id <= 0 || !$this->acquire_named_lock($lock_name, 5)) {
            return false;
        }

        try {
            $campaign = $this->get_campaign($campaign_id, true);
            if (!$campaign || !in_array((int) $campaign->status, [self::CAMPAIGN_ONGOING, self::CAMPAIGN_READY, self::CAMPAIGN_ORDER_PENDING], true)) {
                return false;
            }

            if ((int) $campaign->status === self::CAMPAIGN_ORDER_PENDING && (int) $campaign->pay_order_id > 0) {
                $order = qls_shop_order()->get((int) $campaign->pay_order_id);
                if ($order && (int) $order->status === QLS_Shop_Order::STATUS_PENDING) {
                    if (!qls_shop_order()->cancel((int) $order->id, __('助力活动过期，自动取消待支付订单', 'qilingshop'))) {
                        return false;
                    }
                } elseif ($order && in_array((int) $order->status, [
                    QLS_Shop_Order::STATUS_PAID,
                    QLS_Shop_Order::STATUS_SHIPPED,
                    QLS_Shop_Order::STATUS_COMPLETED,
                ], true)) {
                    return false;
                }
            }

            $this->wpdb->query('START TRANSACTION');
            $campaign = $this->get_campaign_for_update($campaign_id, true);
            if (!$campaign || !in_array((int) $campaign->status, [self::CAMPAIGN_ONGOING, self::CAMPAIGN_READY], true)) {
                $this->wpdb->query('ROLLBACK');
                return false;
            }

            $reserved_qty = max(0, (int) ($campaign->reserved_qty ?? 0));
            if ((int) ($campaign->stock_reserved ?? 0) === 1 && $reserved_qty > 0
                && !$this->release_activity_stock((int) $campaign->activity_id, $reserved_qty)) {
                $this->wpdb->query('ROLLBACK');
                return false;
            }

            $updated = $this->db->update('assist_campaigns', [
                'status'     => self::CAMPAIGN_EXPIRED,
                'stock_reserved' => 0,
                'reserved_qty'   => 0,
                'updated_at' => current_time('mysql'),
            ], [
                'id' => $campaign_id,
                'status' => (int) $campaign->status,
                'stock_reserved' => (int) ($campaign->stock_reserved ?? 0),
            ]);
            if ($updated === false || (int) $updated !== 1) {
                $this->wpdb->query('ROLLBACK');
                return false;
            }

            $this->log_action(
                $campaign_id,
                (int) $campaign->activity_id,
                (int) $campaign->user_id,
                0,
                'system',
                'expire',
                0,
                (float) $campaign->current_price,
                (float) $campaign->current_price,
                __('助力活动超时过期', 'qilingshop')
            );

            $this->wpdb->query('COMMIT');
            return true;
        } catch (Exception $e) {
            $this->wpdb->query('ROLLBACK');
            qilingshop_log('Assist expiration failed: ' . $e->getMessage(), 'error', [
                'campaign_id' => $campaign_id,
            ]);
            return false;
        } finally {
            $this->release_named_lock($lock_name);
        }
    }

    /**
     * 写入操作日志
     *
     * @param int    $campaign_id
     * @param int    $activity_id
     * @param int    $user_id
     * @param int    $actor_id
     * @param string $actor_role
     * @param string $action
     * @param float  $amount
     * @param float  $before_price
     * @param float  $after_price
     * @param string $message
     * @return void
     */
    private function log_action($campaign_id, $activity_id, $user_id, $actor_id, $actor_role, $action, $amount, $before_price, $after_price, $message = '') {
        $this->db->insert('assist_logs', [
            'campaign_id'  => (int) $campaign_id,
            'activity_id'  => (int) $activity_id,
            'user_id'      => (int) $user_id,
            'actor_id'     => (int) $actor_id,
            'actor_role'   => sanitize_key((string) $actor_role),
            'action'       => sanitize_key((string) $action),
            'amount'       => round((float) $amount, 2),
            'before_price' => round((float) $before_price, 2),
            'after_price'  => round((float) $after_price, 2),
            'message'      => sanitize_text_field((string) $message),
            'created_at'   => current_time('mysql'),
        ]);
    }
}

/**
 * 快捷函数
 *
 * @return QLS_Assist
 */
function qls_assist() {
    return QLS_Assist::instance();
}
