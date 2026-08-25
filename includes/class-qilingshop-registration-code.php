<?php
/**
 * 注册码管理
 *
 * @package QilingShop
 * @since   2.0.7
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Registration_Code {

    const RESERVED_LOG_GRACE_PERIOD = 7200;

    /**
     * 数据结构版本
     */
    const DB_VERSION = '1.0.0';

    /**
     * 单例实例
     *
     * @var QilingShop_Registration_Code|null
     */
    private static $instance = null;

    /**
     * 数据库封装
     *
     * @var QilingShop_Database
     */
    private $db;

    /**
     * wp-login 注册流程临时记录的日志 ID
     *
     * @var int
     */
    private $pending_wp_register_log_id = 0;

    /**
     * wp-login 注册流程是否需要在请求结束时回滚
     *
     * @var bool
     */
    private $pending_wp_register_should_rollback = false;

    /**
     * 获取单例实例
     *
     * @return QilingShop_Registration_Code
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

        // 兜底支持：当用户使用 WP 默认注册页面时也要求注册码
        add_action('register_form', [$this, 'render_wp_register_field']);
        add_filter('registration_errors', [$this, 'validate_wp_register_field'], 10, 3);
        add_action('user_register', [$this, 'consume_wp_register_code'], 5, 1);
        add_action('shutdown', [$this, 'rollback_wp_register_code'], 5);
    }

    /**
     * 数据库升级检查
     */
    public function maybe_upgrade() {
        $version = (string) get_option('qilingshop_registration_code_db_version', '0');
        if (version_compare($version, self::DB_VERSION, '<')) {
            self::create_tables();
            update_option('qilingshop_registration_code_db_version', self::DB_VERSION);
        }

        if (get_option('qilingshop_register_code_enabled', null) === null) {
            update_option('qilingshop_register_code_enabled', false);
        }
    }

    /**
     * 创建注册码相关数据表
     */
    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table_codes = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'registration_codes';
        $sql_codes = "CREATE TABLE {$table_codes} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(64) NOT NULL,
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
            KEY expires_at (expires_at),
            KEY created_by (created_by)
        ) {$charset_collate};";
        dbDelta($sql_codes);

        $table_logs = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'registration_code_logs';
        $sql_logs = "CREATE TABLE {$table_logs} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            code_id BIGINT(20) UNSIGNED DEFAULT NULL,
            code VARCHAR(64) DEFAULT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            register_source VARCHAR(50) NOT NULL DEFAULT '',
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
     * 是否启用注册码注册
     *
     * @return bool
     */
    public function is_enabled() {
        return (bool) get_option('qilingshop_register_code_enabled', false);
    }

    /**
     * 规范化注册码（去空白和非字母数字，转大写）
     *
     * @param string $code 原始输入
     * @return string
     */
    public function normalize_code($code) {
        $code = strtoupper((string) $code);
        $code = preg_replace('/[^A-Z0-9]/', '', $code);
        return sanitize_text_field($code);
    }

    /**
     * 检查注册码是否可用（不扣减次数）
     *
     * @param string $raw_code 用户输入
     * @return array
     */
    public function check_code_available($raw_code) {
        global $wpdb;

        $code = $this->normalize_code($raw_code);
        if ($code === '') {
            return [
                'success' => false,
                'message' => __('请输入注册码', 'qilingshop'),
                'code'    => '',
                'row'     => null,
            ];
        }

        $table = $this->db->get_table('registration_codes');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE code = %s LIMIT 1",
            $code
        ));

        if (!$row) {
            return [
                'success' => false,
                'message' => __('注册码不存在', 'qilingshop'),
                'code'    => $code,
                'row'     => null,
            ];
        }

        if ($row->status !== 'active') {
            return [
                'success' => false,
                'message' => __('注册码已禁用', 'qilingshop'),
                'code'    => $code,
                'row'     => $row,
            ];
        }

        if (!empty($row->expires_at) && strtotime($row->expires_at) < current_time('timestamp')) {
            return [
                'success' => false,
                'message' => __('注册码已过期', 'qilingshop'),
                'code'    => $code,
                'row'     => $row,
            ];
        }

        if ((int) $row->max_uses > 0 && (int) $row->used_count >= (int) $row->max_uses) {
            return [
                'success' => false,
                'message' => __('注册码使用次数已用完', 'qilingshop'),
                'code'    => $code,
                'row'     => $row,
            ];
        }

        return [
            'success' => true,
            'message' => '',
            'code'    => $code,
            'row'     => $row,
        ];
    }

    /**
     * 消耗注册码（预占位，用于注册前）
     *
     * @param string $raw_code 注册码
     * @param array  $context  上下文
     * @return array
     */
    public function consume_for_registration($raw_code, $context = []) {
        global $wpdb;

        $context = wp_parse_args($context, [
            'user_id'         => 0,
            'register_source' => '',
            'message'         => __('注册码预占用成功', 'qilingshop'),
            'ip_address'      => $this->get_client_ip(),
            'user_agent'      => $this->get_user_agent(),
        ]);

        $check = $this->check_code_available($raw_code);
        if (!$check['success']) {
            $log_id = $this->insert_log([
                'code_id'         => 0,
                'code'            => $check['code'],
                'user_id'         => (int) $context['user_id'],
                'register_source' => sanitize_text_field($context['register_source']),
                'status'          => 'failed',
                'message'         => $check['message'],
                'ip_address'      => sanitize_text_field($context['ip_address']),
                'user_agent'      => sanitize_text_field($context['user_agent']),
            ]);

            return [
                'success' => false,
                'message' => $check['message'],
                'log_id'  => (int) $log_id,
            ];
        }

        $table_codes = $this->db->get_table('registration_codes');
        $now = current_time('mysql');
        $row = $check['row'];

        $this->db->begin_transaction();

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table_codes}
             SET used_count = used_count + 1, updated_at = %s
             WHERE id = %d
               AND status = 'active'
               AND (expires_at IS NULL OR expires_at = '0000-00-00 00:00:00' OR expires_at >= %s)
               AND (max_uses = 0 OR used_count < max_uses)",
            $now,
            (int) $row->id,
            $now
        ));

        if ($affected !== 1) {
            $this->db->rollback();
            $message = __('注册码使用失败，请重试', 'qilingshop');
            $log_id = $this->insert_log([
                'code_id'         => (int) $row->id,
                'code'            => $check['code'],
                'user_id'         => (int) $context['user_id'],
                'register_source' => sanitize_text_field($context['register_source']),
                'status'          => 'failed',
                'message'         => $message,
                'ip_address'      => sanitize_text_field($context['ip_address']),
                'user_agent'      => sanitize_text_field($context['user_agent']),
            ]);

            return [
                'success' => false,
                'message' => $message,
                'log_id'  => (int) $log_id,
            ];
        }

        $log_id = $this->insert_log([
            'code_id'         => (int) $row->id,
            'code'            => $check['code'],
            'user_id'         => (int) $context['user_id'],
            'register_source' => sanitize_text_field($context['register_source']),
            'status'          => 'reserved',
            'message'         => sanitize_text_field($context['message']),
            'ip_address'      => sanitize_text_field($context['ip_address']),
            'user_agent'      => sanitize_text_field($context['user_agent']),
        ]);

        if ($log_id <= 0) {
            $this->db->rollback();
            return [
                'success' => false,
                'message' => __('注册码预占失败，请重试', 'qilingshop'),
                'log_id'  => 0,
            ];
        }

        $this->db->commit();

        return [
            'success' => true,
            'message' => '',
            'log_id'  => (int) $log_id,
            'code'    => $check['code'],
        ];
    }

    /**
     * 回滚注册码预占位
     *
     * @param int    $log_id  日志 ID
     * @param string $message 日志信息
     * @return bool
     */
    public function rollback_consumption($log_id, $message = '') {
        global $wpdb;

        $log_id = absint($log_id);
        if ($log_id <= 0) {
            return false;
        }

        $table_logs = $this->db->get_table('registration_code_logs');
        $table_codes = $this->db->get_table('registration_codes');

        $message = $message !== '' ? $message : __('注册失败，注册码已回滚', 'qilingshop');
        $now = current_time('mysql');

        $this->db->begin_transaction();

        $log = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_logs} WHERE id = %d FOR UPDATE",
            $log_id
        ));
        if (!$log) {
            $this->db->rollback();
            return false;
        }

        if ($log->status === 'rollback') {
            $this->db->commit();
            return true;
        }

        if ($log->status !== 'reserved') {
            $this->db->rollback();
            return false;
        }

        $ok_code = true;
        if ((int) $log->code_id > 0) {
            $ok_code = $wpdb->query($wpdb->prepare(
                "UPDATE {$table_codes}
                 SET used_count = IF(used_count > 0, used_count - 1, 0), updated_at = %s
                 WHERE id = %d",
                $now,
                (int) $log->code_id
            ));
            $ok_code = ($ok_code !== false);
        }

        $ok_log = $wpdb->update(
            $table_logs,
            [
                'status'  => 'rollback',
                'message' => sanitize_text_field($message),
            ],
            [
                'id'     => $log_id,
                'status' => 'reserved',
            ],
            ['%s', '%s'],
            ['%d', '%s']
        );

        if (!$ok_code || $ok_log !== 1) {
            $this->db->rollback();
            return false;
        }

        $this->db->commit();
        return true;
    }

    /**
     * 确认注册码消耗成功
     *
     * @param int    $log_id  日志 ID
     * @param int    $user_id 用户 ID
     * @param string $message 日志信息
     * @return bool
     */
    public function confirm_consumption($log_id, $user_id, $message = '') {
        global $wpdb;

        $log_id = absint($log_id);
        $user_id = absint($user_id);
        if ($log_id <= 0 || $user_id <= 0) {
            return false;
        }

        $table_logs = $this->db->get_table('registration_code_logs');
        $message = $message !== '' ? $message : __('注册码验证通过，注册成功', 'qilingshop');

        $this->db->begin_transaction();

        $log = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_logs} WHERE id = %d FOR UPDATE",
            $log_id
        ));
        if (!$log) {
            $this->db->rollback();
            return false;
        }

        if ($log->status === 'success') {
            $this->db->commit();
            return (int) $log->user_id === $user_id;
        }

        if ($log->status !== 'reserved') {
            $this->db->rollback();
            return false;
        }

        $result = $wpdb->update(
            $table_logs,
            [
                'user_id' => $user_id,
                'status'  => 'success',
                'message' => sanitize_text_field($message),
            ],
            [
                'id'     => $log_id,
                'status' => 'reserved',
            ],
            ['%d', '%s', '%s'],
            ['%d', '%s']
        );

        if ($result !== 1) {
            $this->db->rollback();
            return false;
        }

        $this->db->commit();
        return true;
    }

    /**
     * 更新注册码状态
     *
     * @param int    $id     记录 ID
     * @param string $status 状态
     * @return bool
     */
    public function update_code_status($id, $status) {
        $id = absint($id);
        $status = in_array($status, ['active', 'disabled'], true) ? $status : 'disabled';
        if ($id <= 0) {
            return false;
        }

        $result = $this->db->update('registration_codes', [
            'status' => $status,
        ], [
            'id' => $id,
        ]);

        return $result !== false;
    }

    /**
     * 删除注册码（日志保留）
     *
     * @param int $id 记录 ID
     * @return bool
     */
    public function delete_code($id) {
        global $wpdb;

        $id = absint($id);
        if ($id <= 0) {
            return false;
        }

        $table_logs = $this->db->get_table('registration_code_logs');
        $stale_before = date('Y-m-d H:i:s', current_time('timestamp') - self::RESERVED_LOG_GRACE_PERIOD);
        $active_reserved = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$table_logs}
             WHERE code_id = %d
               AND status = %s
               AND created_at >= %s",
            $id,
            'reserved',
            $stale_before
        ));

        if ($active_reserved > 0) {
            return false;
        }

        $result = $this->db->delete('registration_codes', ['id' => $id]);
        return $result !== false;
    }

    /**
     * 删除单条使用日志
     *
     * @param int $id 日志 ID
     * @return bool
     */
    public function delete_log($id) {
        global $wpdb;

        $id = absint($id);
        if ($id <= 0) {
            return false;
        }

        $table_logs = $this->db->get_table('registration_code_logs');
        $log = $wpdb->get_row($wpdb->prepare("SELECT status, created_at FROM {$table_logs} WHERE id = %d LIMIT 1", $id));
        if (!$log) {
            return false;
        }

        if ((string) $log->status === 'reserved' && !$this->is_reserved_log_stale($log)) {
            return false;
        }

        $result = $this->db->delete('registration_code_logs', ['id' => $id]);
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

        $table_logs = $this->db->get_table('registration_code_logs');
        $where = 'WHERE 1=1';
        $params = [];
        $join = '';

        if ($args['status'] !== '' && in_array($args['status'], ['success', 'failed', 'reserved', 'rollback'], true)) {
            $where .= ' AND l.status = %s';
            $params[] = $args['status'];
        } else {
            // 默认不清理预占记录，避免影响进行中的注册流程；可通过 status=reserved 显式清理。
            $where .= ' AND l.status <> %s';
            $params[] = 'reserved';
        }

        if ($args['status'] === 'reserved') {
            $stale_before = date('Y-m-d H:i:s', current_time('timestamp') - self::RESERVED_LOG_GRACE_PERIOD);
            $where .= ' AND l.created_at < %s';
            $params[] = $stale_before;
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

    private function is_reserved_log_stale($log) {
        if (!$log || (string) ($log->status ?? '') !== 'reserved') {
            return true;
        }

        $created_at = !empty($log->created_at) ? strtotime((string) $log->created_at) : 0;
        if ($created_at <= 0) {
            return false;
        }

        return $created_at <= (current_time('timestamp') - self::RESERVED_LOG_GRACE_PERIOD);
    }

    /**
     * 批量生成注册码
     *
     * @param int   $count 数量
     * @param array $args  参数
     * @return array
     */
    public function generate_codes($count, $args = []) {
        $count = max(1, min(500, absint($count)));
        $args = wp_parse_args($args, [
            'prefix'     => 'QLS',
            'max_uses'   => 1,
            'expires_at' => '',
            'note'       => '',
        ]);

        $prefix = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $args['prefix']));
        if ($prefix === '') {
            $prefix = 'QLS';
        }
        $prefix = substr($prefix, 0, 10);

        $max_uses = max(1, min(999999, absint($args['max_uses'])));
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

            $insert_id = $this->db->insert('registration_codes', [
                'code'       => $code,
                'max_uses'   => $max_uses,
                'used_count' => 0,
                'status'     => 'active',
                'expires_at' => $expires_at,
                'created_by' => get_current_user_id(),
                'note'       => $note,
                'created_at' => current_time('mysql'),
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
     * 获取注册码列表
     *
     * @param array $args 参数
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

        $table = $this->db->get_table('registration_codes');
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
     * 获取注册码总数
     *
     * @param array $args 过滤参数
     * @return int
     */
    public function count_codes($args = []) {
        global $wpdb;

        $args = wp_parse_args($args, [
            'status' => '',
            'search' => '',
        ]);

        $table = $this->db->get_table('registration_codes');
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
     * @param array $args 参数
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

        $table_logs = $this->db->get_table('registration_code_logs');
        $where = 'WHERE 1=1';
        $params = [];

        if ($args['status'] !== '' && in_array($args['status'], ['success', 'failed', 'reserved', 'rollback'], true)) {
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

        $sql = "SELECT l.*, u.user_login, u.user_email, u.display_name AS user_display_name, um.meta_value AS user_phone
                FROM {$table_logs} l
                LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
                LEFT JOIN {$wpdb->usermeta} um ON um.user_id = l.user_id AND um.meta_key = 'qiling_phone'
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
     * @param array $args 过滤参数
     * @return int
     */
    public function count_logs($args = []) {
        global $wpdb;

        $args = wp_parse_args($args, [
            'status'  => '',
            'search'  => '',
            'code_id' => 0,
        ]);

        $table_logs = $this->db->get_table('registration_code_logs');
        $where = 'WHERE 1=1';
        $params = [];

        if ($args['status'] !== '' && in_array($args['status'], ['success', 'failed', 'reserved', 'rollback'], true)) {
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

    /**
     * 手机号脱敏
     *
     * @param string $phone 手机号
     * @return string
     */
    public function mask_phone($phone) {
        $phone = preg_replace('/\D+/', '', (string) $phone);
        if (strlen($phone) < 7) {
            return $phone;
        }
        return substr($phone, 0, 3) . '****' . substr($phone, -4);
    }

    /**
     * 输出 WP 默认注册页的注册码字段
     */
    public function render_wp_register_field() {
        if (!$this->is_enabled()) {
            return;
        }
        ?>
        <p>
            <label for="registration_code"><?php _e('注册码', 'qilingshop'); ?><br />
                <input type="text" name="registration_code" id="registration_code" class="input" value="" size="25" />
            </label>
        </p>
        <?php
    }

    /**
     * 校验 WP 默认注册页的注册码字段
     *
     * @param WP_Error $errors
     * @param string   $sanitized_user_login 用户名
     * @param string   $user_email           邮箱
     * @return WP_Error
     */
    public function validate_wp_register_field($errors, $sanitized_user_login, $user_email) {
        if (!$this->is_enabled()) {
            return $errors;
        }

        if (!($errors instanceof WP_Error)) {
            return $errors;
        }

        if ($this->pending_wp_register_log_id > 0) {
            return $errors;
        }

        // 仅在当前没有其他注册错误时预占注册码，避免失败场景下白白扣减次数
        if (!empty($errors->get_error_codes())) {
            return $errors;
        }

        $code = isset($_POST['registration_code']) ? sanitize_text_field(wp_unslash($_POST['registration_code'])) : '';
        if ($code === '') {
            $errors->add('registration_code_required', __('请输入注册码', 'qilingshop'));
            return $errors;
        }

        $consume = $this->consume_for_registration($code, [
            'register_source' => 'wp_register',
            'message'         => __('WP默认注册流程预占用', 'qilingshop'),
        ]);

        if (empty($consume['success'])) {
            $message = !empty($consume['message']) ? $consume['message'] : __('注册码校验失败', 'qilingshop');
            $errors->add('registration_code_invalid', $message);
            return $errors;
        }

        $this->pending_wp_register_log_id = !empty($consume['log_id']) ? absint($consume['log_id']) : 0;
        $this->pending_wp_register_should_rollback = $this->pending_wp_register_log_id > 0;
        return $errors;
    }

    /**
     * WP 默认注册成功后消耗注册码
     *
     * @param int $user_id 用户 ID
     */
    public function consume_wp_register_code($user_id) {
        if (!$this->is_enabled()) {
            return;
        }

        if ($this->pending_wp_register_log_id <= 0 || absint($user_id) <= 0) {
            return;
        }

        $this->confirm_consumption(
            (int) $this->pending_wp_register_log_id,
            (int) $user_id,
            __('WP默认注册流程使用成功', 'qilingshop')
        );

        $this->pending_wp_register_should_rollback = false;
        $this->pending_wp_register_log_id = 0;
    }

    /**
     * 在请求结束时回滚 WP 默认注册流程中未确认的预占
     */
    public function rollback_wp_register_code() {
        if (!$this->is_enabled()) {
            return;
        }

        if (!$this->pending_wp_register_should_rollback || $this->pending_wp_register_log_id <= 0) {
            return;
        }

        $this->rollback_consumption(
            (int) $this->pending_wp_register_log_id,
            __('WP默认注册失败，注册码已回滚', 'qilingshop')
        );

        $this->pending_wp_register_should_rollback = false;
        $this->pending_wp_register_log_id = 0;
    }

    /**
     * 插入日志
     *
     * @param array $data 日志数据
     * @return int
     */
    private function insert_log($data) {
        $log_id = $this->db->insert('registration_code_logs', [
            'code_id'         => isset($data['code_id']) ? absint($data['code_id']) : 0,
            'code'            => isset($data['code']) ? $this->normalize_code($data['code']) : '',
            'user_id'         => isset($data['user_id']) ? absint($data['user_id']) : 0,
            'register_source' => sanitize_text_field($data['register_source'] ?? ''),
            'status'          => sanitize_text_field($data['status'] ?? 'failed'),
            'message'         => sanitize_text_field($data['message'] ?? ''),
            'ip_address'      => sanitize_text_field($data['ip_address'] ?? $this->get_client_ip()),
            'user_agent'      => sanitize_text_field($data['user_agent'] ?? $this->get_user_agent()),
            'created_at'      => current_time('mysql'),
        ]);

        return absint($log_id);
    }

    /**
     * 规范化过期时间
     *
     * @param string $expires_at 原值
     * @return string|null
     */
    private function normalize_expire_at($expires_at) {
        $expires_at = trim((string) $expires_at);
        if ($expires_at === '') {
            return null;
        }

        $ts = strtotime($expires_at);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $ts);
    }

    /**
     * 生成单个注册码
     *
     * @param string $prefix 前缀
     * @return string
     */
    private function generate_one_code($prefix) {
        $random = strtoupper(wp_generate_password(14, false, false));
        $random = preg_replace('/[^A-Z0-9]/', '', $random);

        if (strlen($random) < 14) {
            $random .= strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 14 - strlen($random)));
        }

        $code = $prefix . substr($random, 0, 14);
        return substr($code, 0, 64);
    }

    /**
     * 获取客户端 IP
     *
     * @return string
     */
    private function get_client_ip() {
        if (function_exists('qilingshop_security')) {
            return qilingshop_security()->get_client_ip();
        }

        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '';
    }

    /**
     * 获取 User-Agent
     *
     * @return string
     */
    private function get_user_agent() {
        return isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
    }
}
