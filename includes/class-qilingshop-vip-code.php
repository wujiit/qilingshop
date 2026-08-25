<?php
/**
 * VIP 兑换码管理
 *
 * @package QilingShop
 * @since   2.0.7
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_VIP_Code {

    /**
     * 数据结构版本
     */
    const DB_VERSION = '1.0.0';

    /**
     * 单例实例
     *
     * @var QilingShop_VIP_Code|null
     */
    private static $instance = null;

    /**
     * 数据库封装
     *
     * @var QilingShop_Database
     */
    private $db;

    /**
     * 获取单例
     *
     * @return QilingShop_VIP_Code
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
        $this->db = QilingShop_Database::instance();
        add_action('init', [$this, 'maybe_upgrade'], 30);
    }

    /**
     * 获取数据库级命名锁。
     *
     * @param string $lock_name
     * @param int    $timeout
     * @return bool
     */
    private function acquire_named_lock($lock_name, $timeout = 5) {
        global $wpdb;

        $lock_name = sanitize_key((string) $lock_name);
        if ($lock_name === '') {
            return false;
        }

        $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, (int) $timeout));
        return (int) $result === 1;
    }

    /**
     * 释放数据库级命名锁。
     *
     * @param string $lock_name
     * @return void
     */
    private function release_named_lock($lock_name) {
        global $wpdb;

        $lock_name = sanitize_key((string) $lock_name);
        if ($lock_name === '') {
            return;
        }

        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
    }

    /**
     * 构造与 VIP 升级一致的用户级锁名。
     *
     * @param int $user_id
     * @return string
     */
    private function build_vip_user_lock_name($user_id) {
        return 'qlsvip_' . md5((string) absint($user_id));
    }

    /**
     * 数据库升级检查
     */
    public function maybe_upgrade() {
        $version = (string) get_option('qilingshop_vip_code_db_version', '0');
        if (version_compare($version, self::DB_VERSION, '<')) {
            self::create_tables();
            update_option('qilingshop_vip_code_db_version', self::DB_VERSION);
        }
    }

    /**
     * 创建兑换码相关数据表
     */
    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table_codes = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'vip_codes';
        $sql_codes = "CREATE TABLE {$table_codes} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(64) NOT NULL,
            vip_level_id INT(11) UNSIGNED NOT NULL DEFAULT 0,
            duration_days INT(11) UNSIGNED NOT NULL DEFAULT 0,
            max_uses INT(11) UNSIGNED NOT NULL DEFAULT 1,
            used_count INT(11) UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            expires_at DATETIME DEFAULT NULL,
            created_by BIGINT(20) UNSIGNED DEFAULT 0,
            note VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            KEY status (status),
            KEY vip_level_id (vip_level_id),
            KEY expires_at (expires_at),
            KEY created_by (created_by)
        ) {$charset_collate};";
        dbDelta($sql_codes);

        $table_logs = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'vip_code_logs';
        $sql_logs = "CREATE TABLE {$table_logs} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            code_id BIGINT(20) UNSIGNED DEFAULT NULL,
            code VARCHAR(64) DEFAULT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            vip_level_id INT(11) UNSIGNED NOT NULL DEFAULT 0,
            vip_level_name VARCHAR(100) DEFAULT NULL,
            duration_days INT(11) UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'failed',
            message VARCHAR(255) DEFAULT NULL,
            ip_address VARCHAR(50) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY code_id (code_id),
            KEY user_id (user_id),
            KEY status (status),
            KEY code (code),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql_logs);
    }

    /**
     * 规范化兑换码
     *
     * @param string $code
     * @return string
     */
    public function normalize_code($code) {
        $code = strtoupper((string) $code);
        $code = preg_replace('/[^A-Z0-9]/', '', $code);
        return sanitize_text_field($code);
    }

    /**
     * 检查兑换码是否可用（不扣减次数）
     *
     * @param string $raw_code
     * @return array
     */
    public function check_code_available($raw_code) {
        global $wpdb;

        $code = $this->normalize_code($raw_code);
        if ($code === '') {
            return [
                'success' => false,
                'message' => __('请输入兑换码', 'qilingshop'),
                'code'    => '',
                'row'     => null,
                'level'   => null,
            ];
        }

        $table = $this->db->get_table('vip_codes');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE code = %s LIMIT 1",
            $code
        ));

        if (!$row) {
            return [
                'success' => false,
                'message' => __('兑换码不存在', 'qilingshop'),
                'code'    => $code,
                'row'     => null,
                'level'   => null,
            ];
        }

        if ($row->status !== 'active') {
            return [
                'success' => false,
                'message' => __('兑换码已禁用', 'qilingshop'),
                'code'    => $code,
                'row'     => $row,
                'level'   => null,
            ];
        }

        if (!empty($row->expires_at) && strtotime($row->expires_at) < current_time('timestamp')) {
            return [
                'success' => false,
                'message' => __('兑换码已过期', 'qilingshop'),
                'code'    => $code,
                'row'     => $row,
                'level'   => null,
            ];
        }

        // 统一规则：单个兑换码仅限使用一次
        if ((int) $row->used_count >= 1) {
            return [
                'success' => false,
                'message' => __('兑换码已使用', 'qilingshop'),
                'code'    => $code,
                'row'     => $row,
                'level'   => null,
            ];
        }

        $level = null;
        if (class_exists('QilingShop_VIP')) {
            $level = QilingShop_VIP::instance()->get_level_by_id((int) $row->vip_level_id);
        }
        if (!$level || empty($level->is_active)) {
            return [
                'success' => false,
                'message' => __('兑换码对应等级不可用', 'qilingshop'),
                'code'    => $code,
                'row'     => $row,
                'level'   => null,
            ];
        }

        return [
            'success' => true,
            'message' => '',
            'code'    => $code,
            'row'     => $row,
            'level'   => $level,
        ];
    }

    /**
     * 记录日志
     *
     * @param array $data
     * @return int|false
     */
    private function insert_log($data) {
        $defaults = [
            'code_id'        => 0,
            'code'           => '',
            'user_id'        => 0,
            'vip_level_id'   => 0,
            'vip_level_name' => '',
            'duration_days'  => 0,
            'status'         => 'failed',
            'message'        => '',
            'ip_address'     => '',
            'user_agent'     => '',
            'created_at'     => current_time('mysql'),
        ];

        $data = wp_parse_args($data, $defaults);

        return $this->db->insert('vip_code_logs', $data);
    }

    /**
     * 记录失败尝试（不消耗次数）
     *
     * @param string $raw_code
     * @param int    $user_id
     * @param string $message
     * @return int|false
     */
    public function log_failed_attempt($raw_code, $user_id, $message) {
        global $wpdb;

        $code = $this->normalize_code($raw_code);
        $table = $this->db->get_table('vip_codes');
        $row = $code !== '' ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE code = %s LIMIT 1", $code)) : null;

        $level_name = '';
        if ($row && class_exists('QilingShop_VIP')) {
            $level = QilingShop_VIP::instance()->get_level_by_id((int) $row->vip_level_id);
            if ($level && !empty($level->level_name)) {
                $level_name = (string) $level->level_name;
            }
        }

        return $this->insert_log([
            'code_id'        => $row ? (int) $row->id : 0,
            'code'           => $code,
            'user_id'        => (int) $user_id,
            'vip_level_id'   => $row ? (int) $row->vip_level_id : 0,
            'vip_level_name' => $level_name,
            'duration_days'  => $row ? (int) $row->duration_days : 0,
            'status'         => 'failed',
            'message'        => sanitize_text_field((string) $message),
            'ip_address'     => $this->get_client_ip(),
            'user_agent'     => $this->get_user_agent(),
        ]);
    }

    /**
     * 使用兑换码开通 VIP
     *
     * @param string $raw_code
     * @param int    $user_id
     * @return array
     */
    public function redeem_code($raw_code, $user_id) {
        global $wpdb;

        $user_id = absint($user_id);
        if ($user_id <= 0) {
            return [
                'success' => false,
                'message' => __('请先登录', 'qilingshop'),
            ];
        }

        $lock_name = $this->build_vip_user_lock_name($user_id);
        if (!$this->acquire_named_lock($lock_name, 5)) {
            return [
                'success' => false,
                'message' => __('兑换处理中，请勿重复提交', 'qilingshop'),
            ];
        }

        try {

            if (class_exists('QilingShop_VIP')) {
                $status = QilingShop_VIP::instance()->get_user_vip_status($user_id);
                if ($status['level_id'] > 0 && (!$status['is_expired'] || $status['in_grace'])) {
                    $this->log_failed_attempt($raw_code, $user_id, __('已是会员，无法使用兑换码', 'qilingshop'));
                    return [
                        'success' => false,
                        'message' => __('您已是会员，暂不支持使用兑换码', 'qilingshop'),
                    ];
                }
            }

            $check = $this->check_code_available($raw_code);
            if (!$check['success']) {
                $this->insert_log([
                    'code_id'    => 0,
                    'code'       => $check['code'],
                    'user_id'    => $user_id,
                    'status'     => 'failed',
                    'message'    => sanitize_text_field($check['message']),
                    'ip_address' => $this->get_client_ip(),
                    'user_agent' => $this->get_user_agent(),
                ]);

                return [
                    'success' => false,
                    'message' => $check['message'],
                ];
            }

            $row = $check['row'];
            $level = $check['level'];
            $duration_days = (int) $row->duration_days;
            if ($duration_days <= 0) {
                $duration_days = (int) $level->duration_days;
            }

            $table_codes = $this->db->get_table('vip_codes');
            $now = current_time('mysql');

            $affected = $wpdb->query($wpdb->prepare(
                "UPDATE {$table_codes}
                 SET used_count = used_count + 1, updated_at = %s
                 WHERE id = %d
                   AND status = 'active'
                   AND (expires_at IS NULL OR expires_at = '0000-00-00 00:00:00' OR expires_at >= %s)
                   AND used_count < 1",
                $now,
                (int) $row->id,
                $now
            ));

            if ($affected !== 1) {
                $message = __('兑换失败，请稍后重试', 'qilingshop');
                $this->insert_log([
                    'code_id'        => (int) $row->id,
                    'code'           => $check['code'],
                    'user_id'        => $user_id,
                    'vip_level_id'   => (int) $row->vip_level_id,
                    'vip_level_name' => (string) $level->level_name,
                    'duration_days'  => $duration_days,
                    'status'         => 'failed',
                    'message'        => $message,
                    'ip_address'     => $this->get_client_ip(),
                    'user_agent'     => $this->get_user_agent(),
                ]);

                return [
                    'success' => false,
                    'message' => $message,
                ];
            }

            $order_no = qilingshop_security()->generate_order_no('VIPC');
            $vip = QilingShop_VIP::instance();
            $upgrade = $vip->upgrade($user_id, (int) $row->vip_level_id, 'code', 0, $order_no, $duration_days, true);

            if (!$upgrade['success']) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$table_codes}
                     SET used_count = IF(used_count > 0, used_count - 1, 0), updated_at = %s
                     WHERE id = %d",
                    $now,
                    (int) $row->id
                ));

                $this->insert_log([
                    'code_id'        => (int) $row->id,
                    'code'           => $check['code'],
                    'user_id'        => $user_id,
                    'vip_level_id'   => (int) $row->vip_level_id,
                    'vip_level_name' => (string) $level->level_name,
                    'duration_days'  => $duration_days,
                    'status'         => 'failed',
                    'message'        => sanitize_text_field($upgrade['message'] ?? __('兑换失败', 'qilingshop')),
                    'ip_address'     => $this->get_client_ip(),
                    'user_agent'     => $this->get_user_agent(),
                ]);

                return [
                    'success' => false,
                    'message' => $upgrade['message'] ?? __('兑换失败', 'qilingshop'),
                ];
            }

            $this->insert_log([
                'code_id'        => (int) $row->id,
                'code'           => $check['code'],
                'user_id'        => $user_id,
                'vip_level_id'   => (int) $row->vip_level_id,
                'vip_level_name' => (string) $level->level_name,
                'duration_days'  => $duration_days,
                'status'         => 'success',
                'message'        => __('兑换成功', 'qilingshop'),
                'ip_address'     => $this->get_client_ip(),
                'user_agent'     => $this->get_user_agent(),
            ]);

            // 记录一笔 VIP 订单（仅用于账单展示）
            QilingShop_Database::instance()->insert('orders', [
                'order_no'        => $order_no,
                'user_id'         => $user_id,
                'post_id'         => 0,
                'post_title'      => $level->level_name,
                'order_type'      => 'vip',
                'price_rmb'       => 0,
                'discount_amount' => 0,
                'final_price'     => 0,
                'payment_method'  => 'code',
                'status'          => 1,
                'ip_address'      => $this->get_client_ip(),
                'remark'          => wp_json_encode([
                    'level_id' => (int) $row->vip_level_id,
                    'duration' => $duration_days,
                    'vip_code' => $check['code'],
                ]),
                'paid_at'         => current_time('mysql'),
                'created_at'      => current_time('mysql'),
            ]);

            return [
                'success'     => true,
                'message'     => __('兑换成功', 'qilingshop'),
                'level_id'    => (int) $row->vip_level_id,
                'level_name'  => (string) $level->level_name,
                'expires'     => $upgrade['expires'] ?? '',
                'order_no'    => $order_no,
            ];
        } finally {
            $this->release_named_lock($lock_name);
        }
    }

    /**
     * 更新兑换码状态
     *
     * @param int    $id
     * @param string $status
     * @return bool
     */
    public function update_code_status($id, $status) {
        $id = absint($id);
        $status = in_array($status, ['active', 'disabled'], true) ? $status : 'disabled';
        if ($id <= 0) {
            return false;
        }

        $result = $this->db->update('vip_codes', [
            'status' => $status,
        ], [
            'id' => $id,
        ]);

        return $result !== false;
    }

    /**
     * 删除兑换码（日志保留）
     *
     * @param int $id
     * @return bool
     */
    public function delete_code($id) {
        $id = absint($id);
        if ($id <= 0) {
            return false;
        }
        $result = $this->db->delete('vip_codes', ['id' => $id]);
        return $result !== false;
    }

    /**
     * 删除单条使用日志
     *
     * @param int $id 日志 ID
     * @return bool
     */
    public function delete_log($id) {
        $id = absint($id);
        if ($id <= 0) {
            return false;
        }

        $result = $this->db->delete('vip_code_logs', ['id' => $id]);
        return $result !== false;
    }

    /**
     * 清空使用日志（支持按筛选条件删除）
     *
     * @param array $args 筛选条件
     * @return int|false 删除条数，失败返回 false
     */
    public function clear_logs($args = []) {
        global $wpdb;

        $args = wp_parse_args($args, [
            'status'  => '',
            'search'  => '',
            'code_id' => 0,
        ]);

        $table_logs = $this->db->get_table('vip_code_logs');
        $where = 'WHERE 1=1';
        $params = [];
        $join = '';

        if ($args['status'] !== '' && in_array($args['status'], ['success', 'failed'], true)) {
            $where .= ' AND l.status = %s';
            $params[] = $args['status'];
        }

        if ((int) $args['code_id'] > 0) {
            $where .= ' AND l.code_id = %d';
            $params[] = (int) $args['code_id'];
        }

        if ($args['search'] !== '') {
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            $join = " LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id";
            $where .= ' AND (l.code LIKE %s OR u.user_login LIKE %s OR u.user_email LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "DELETE l FROM {$table_logs} l{$join} {$where}";
        if (empty($params)) {
            return $this->db->query($sql);
        }

        return $this->db->query($wpdb->prepare($sql, $params));
    }

    /**
     * 批量生成兑换码
     *
     * @param int   $count
     * @param array $args
     * @return array
     */
    public function generate_codes($count, $args = []) {
        $count = max(1, min(500, absint($count)));
        $args = wp_parse_args($args, [
            'prefix'       => 'VIP',
            'vip_level_id' => 0,
            'duration_days'=> 0,
            'max_uses'     => 1,
            'expires_at'   => '',
            'note'         => '',
        ]);

        $level_id = absint($args['vip_level_id']);
        if ($level_id <= 0 || !class_exists('QilingShop_VIP')) {
            return [
                'total' => $count,
                'ok'    => 0,
                'codes' => [],
            ];
        }

        $level = QilingShop_VIP::instance()->get_level_by_id($level_id);
        if (!$level || empty($level->is_active)) {
            return [
                'total' => $count,
                'ok'    => 0,
                'codes' => [],
            ];
        }

        $prefix = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $args['prefix']));
        if ($prefix === '') {
            $prefix = 'VIP';
        }
        $prefix = substr($prefix, 0, 10);

        $duration_days = absint($args['duration_days']);
        if ($duration_days <= 0) {
            $duration_days = (int) $level->duration_days;
        }

        // 单码仅限使用一次（固定为 1）
        $max_uses = 1;
        $note = sanitize_text_field((string) $args['note']);
        $expires_at = $this->normalize_expire_at($args['expires_at']);

        $created = [];
        $tries = 0;
        $max_tries = $count * 20;

        while (count($created) < $count && $tries < $max_tries) {
            $tries++;
            $code = $this->generate_one_code($prefix);
            if (isset($created[$code])) {
                continue;
            }

            $insert_id = $this->db->insert('vip_codes', [
                'code'         => $code,
                'vip_level_id' => $level_id,
                'duration_days'=> $duration_days,
                'max_uses'     => $max_uses,
                'used_count'   => 0,
                'status'       => 'active',
                'expires_at'   => $expires_at,
                'created_by'   => get_current_user_id(),
                'note'         => $note,
                'created_at'   => current_time('mysql'),
            ]);

            if ($insert_id) {
                $created[$code] = $insert_id;
            }
        }

        return [
            'total' => $count,
            'ok'    => count($created),
            'codes' => array_keys($created),
        ];
    }

    /**
     * 获取兑换码列表
     *
     * @param array $args
     * @return array
     */
    public function get_codes($args = []) {
        global $wpdb;

        $args = wp_parse_args($args, [
            'status'   => '',
            'search'   => '',
            'page'     => 1,
            'per_page' => 20,
        ]);

        $table = $this->db->get_table('vip_codes');
        $where = 'WHERE 1=1';
        $params = [];

        if ($args['status'] !== '' && in_array($args['status'], ['active', 'disabled'], true)) {
            $where .= ' AND status = %s';
            $params[] = $args['status'];
        }

        if ($args['search'] !== '') {
            $where .= ' AND code LIKE %s';
            $params[] = '%' . $wpdb->esc_like($this->normalize_code($args['search'])) . '%';
        }

        $page = max(1, absint($args['page']));
        $per_page = max(1, min(200, absint($args['per_page'])));
        $offset = ($page - 1) * $per_page;

        $sql = "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    /**
     * 获取兑换码总数
     *
     * @param array $args
     * @return int
     */
    public function count_codes($args = []) {
        global $wpdb;

        $args = wp_parse_args($args, [
            'status' => '',
            'search' => '',
        ]);

        $table = $this->db->get_table('vip_codes');
        $where = 'WHERE 1=1';
        $params = [];

        if ($args['status'] !== '' && in_array($args['status'], ['active', 'disabled'], true)) {
            $where .= ' AND status = %s';
            $params[] = $args['status'];
        }

        if ($args['search'] !== '') {
            $where .= ' AND code LIKE %s';
            $params[] = '%' . $wpdb->esc_like($this->normalize_code($args['search'])) . '%';
        }

        $sql = "SELECT COUNT(1) FROM {$table} {$where}";
        if (empty($params)) {
            return (int) $wpdb->get_var($sql);
        }

        return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
    }

    /**
     * 获取使用日志
     *
     * @param array $args
     * @return array
     */
    public function get_logs($args = []) {
        global $wpdb;

        $args = wp_parse_args($args, [
            'status'   => '',
            'search'   => '',
            'code_id'  => 0,
            'page'     => 1,
            'per_page' => 30,
        ]);

        $table_logs = $this->db->get_table('vip_code_logs');
        $where = 'WHERE 1=1';
        $params = [];

        if ($args['status'] !== '' && in_array($args['status'], ['success', 'failed'], true)) {
            $where .= ' AND l.status = %s';
            $params[] = $args['status'];
        }

        if ((int) $args['code_id'] > 0) {
            $where .= ' AND l.code_id = %d';
            $params[] = (int) $args['code_id'];
        }

        if ($args['search'] !== '') {
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            $where .= ' AND (l.code LIKE %s OR u.user_login LIKE %s OR u.user_email LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $page = max(1, absint($args['page']));
        $per_page = max(1, min(200, absint($args['per_page'])));
        $offset = ($page - 1) * $per_page;

        $sql = "SELECT l.*, u.user_login, u.user_email
                FROM {$table_logs} l
                LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
                {$where}
                ORDER BY l.id DESC
                LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    /**
     * 获取日志总数
     *
     * @param array $args
     * @return int
     */
    public function count_logs($args = []) {
        global $wpdb;

        $args = wp_parse_args($args, [
            'status'  => '',
            'search'  => '',
            'code_id' => 0,
        ]);

        $table_logs = $this->db->get_table('vip_code_logs');
        $where = 'WHERE 1=1';
        $params = [];

        if ($args['status'] !== '' && in_array($args['status'], ['success', 'failed'], true)) {
            $where .= ' AND l.status = %s';
            $params[] = $args['status'];
        }

        if ((int) $args['code_id'] > 0) {
            $where .= ' AND l.code_id = %d';
            $params[] = (int) $args['code_id'];
        }

        if ($args['search'] !== '') {
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            $where .= ' AND (l.code LIKE %s OR u.user_login LIKE %s OR u.user_email LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "SELECT COUNT(1)
                FROM {$table_logs} l
                LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
                {$where}";

        if (empty($params)) {
            return (int) $wpdb->get_var($sql);
        }

        return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
    }

    private function generate_one_code($prefix) {
        $rand = strtoupper(wp_generate_password(8, false, false));
        return $prefix . $rand;
    }

    private function normalize_expire_at($expires_at) {
        $expires_at = trim((string) $expires_at);
        if ($expires_at === '') {
            return null;
        }
        $timestamp = strtotime($expires_at);
        if (!$timestamp) {
            return null;
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    private function get_client_ip() {
        if (function_exists('qilingshop_security')) {
            return qilingshop_security()->get_client_ip();
        }
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }

    private function get_user_agent() {
        return isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : '';
    }
}
