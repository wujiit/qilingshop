<?php
/**
 * 游客购买管理类
 *
 * @package QilingShop
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Guest {

    private static $instance = null;
    private $db;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->db = QilingShop_Database::instance();
        add_action('qilingshop_daily_guest_cleanup', [$this, 'cleanup_expired']);
    }

    /**
     * 检查是否启用游客购买
     */
    public function is_enabled() {
        return (bool) get_option('qilingshop_guest_buy_enabled', false);
    }

    /**
     * 生成游客标识
     */
    public function get_guest_id() {
        return qilingshop_security()->generate_guest_id();
    }

    /**
     * 创建游客订单关联
     */
    public function create_guest_order($order_no, $post_id, $contact_info = []) {
        $guest_id = $this->get_guest_id();
        $cookie_days = (int) get_option('qilingshop_guest_cookie_days', 30);
        $expires_at = date('Y-m-d H:i:s', strtotime("+{$cookie_days} days"));

        $payload = [
            'guest_id'        => $guest_id,
            'order_no'        => sanitize_text_field((string) $order_no),
            'post_id'         => (int) $post_id,
            'ip_address'      => qilingshop_security()->get_client_ip(),
            'user_agent_hash' => qilingshop_security()->get_user_agent_hash(),
            'cookie_token'    => qilingshop_security()->get_guest_cookie_token(),
            'contact_email'   => isset($contact_info['email']) ? sanitize_email($contact_info['email']) : '',
            'contact_phone'   => isset($contact_info['phone']) ? sanitize_text_field($contact_info['phone']) : '',
            'expires_at'      => $expires_at,
            'created_at'      => current_time('mysql'),
        ];

        $inserted = $this->db->insert('guest_orders', $payload);
        if (!$inserted) {
            $existing = $this->db->get_row('guest_orders', ['order_no' => $payload['order_no']]);
            if ($existing) {
                $updated = $this->db->update('guest_orders', [
                    'guest_id'        => $payload['guest_id'],
                    'post_id'         => $payload['post_id'],
                    'ip_address'      => $payload['ip_address'],
                    'user_agent_hash' => $payload['user_agent_hash'],
                    'cookie_token'    => $payload['cookie_token'],
                    'contact_email'   => $payload['contact_email'],
                    'contact_phone'   => $payload['contact_phone'],
                    'expires_at'      => $payload['expires_at'],
                ], ['id' => (int) $existing->id]);
                if ($updated === false) {
                    return false;
                }
            } else {
                return false;
            }
        }

        return $guest_id;
    }

    /**
     * 检查游客是否有权限访问资源
     */
    public function can_access($post_id) {
        if (!$this->is_enabled()) {
            return false;
        }

        return QilingShop_Order::instance()->guest_has_purchased((int) $post_id, $this->get_guest_id(), 'any');
    }

    /**
     * 通过订单号恢复购买（需设备一致性校验）
     */
    public function recover_order($identifier) {
        $identifier = strtoupper(sanitize_text_field($identifier));

        // 仅允许通过订单号恢复，禁止通过邮箱直接接管
        if (!preg_match('/^[A-Z0-9]{8,64}$/', $identifier)) {
            return [
                'success' => false,
                'message' => __('请输入正确的订单号', 'qilingshop'),
            ];
        }

        $guest_order = $this->db->get_row('guest_orders', ['order_no' => $identifier]);

        if (!$guest_order) {
            return [
                'success' => false,
                'message' => __('未找到相关订单，请检查输入是否正确', 'qilingshop'),
            ];
        }

        $main_order = QilingShop_Order::instance()->get_by_order_no($guest_order->order_no);

        if (!$main_order || $main_order->status != 1) {
            return [
                'success' => false,
                'message' => __('订单未支付或已失效', 'qilingshop'),
            ];
        }

        $current_cookie_token = isset($_COOKIE['qilingshop_guest_token']) ? sanitize_text_field($_COOKIE['qilingshop_guest_token']) : '';
        $authorized = !empty($guest_order->cookie_token)
            && !empty($current_cookie_token)
            && hash_equals((string) $guest_order->cookie_token, $current_cookie_token);

        // 兼容旧数据：若历史订单无 cookie_token，则仅允许原 IP + UA 恢复
        if (!$authorized && empty($guest_order->cookie_token)) {
            $same_ip = (string) $guest_order->ip_address === (string) qilingshop_security()->get_client_ip();
            $same_ua = (string) $guest_order->user_agent_hash === (string) qilingshop_security()->get_user_agent_hash();
            $authorized = $same_ip && $same_ua;
        }

        if (!$authorized) {
            return [
                'success' => false,
                'message' => __('安全验证失败，请在原购买设备操作或联系管理员处理', 'qilingshop'),
            ];
        }

        // 更新当前游客的关联
        $current_guest_id = $this->get_guest_id();
        $updated = $this->db->update('guest_orders', [
            'guest_id'   => $current_guest_id,
            'ip_address' => qilingshop_security()->get_client_ip(),
        ], ['order_no' => $guest_order->order_no]);
        if ($updated === false) {
            return [
                'success' => false,
                'message' => __('订单恢复失败，请稍后重试', 'qilingshop'),
            ];
        }

        do_action('qilingshop_guest_order_recovered', $guest_order->order_no, $identifier);

        return [
            'success'  => true,
            'message'  => __('订单恢复成功', 'qilingshop'),
            'order_no' => $guest_order->order_no,
            'post_id'  => $guest_order->post_id,
        ];
    }

    /**
     * 获取游客购买的资源列表
     */
    public function get_purchased_resources() {
        $guest_ids = qilingshop_security()->get_guest_id_candidates();
        $cookie_token = qilingshop_security()->get_guest_cookie_token();
        $ua_hash = qilingshop_security()->get_user_agent_hash();

        $guest_ids = array_values(array_unique(array_filter(array_map('strval', (array) $guest_ids))));

        global $wpdb;
        $guest_table = $this->db->get_table('guest_orders');
        $order_table = $this->db->get_table('orders');

        $where_parts = [];
        $params = [];

        if (!empty($guest_ids)) {
            $placeholders = implode(',', array_fill(0, count($guest_ids), '%s'));
            $where_parts[] = "g.guest_id IN ({$placeholders})";
            $params = array_merge($params, $guest_ids);
        }

        if ($cookie_token !== '') {
            $where_parts[] = "(g.cookie_token = %s AND (g.user_agent_hash = %s OR g.user_agent_hash IS NULL OR g.user_agent_hash = ''))";
            $params[] = $cookie_token;
            $params[] = $ua_hash;
        }

        if (empty($where_parts)) {
            return [];
        }

        $where_sql = implode(' OR ', $where_parts);
        $sql = "SELECT g.*, o.status, o.paid_at, o.price_rmb
                FROM {$guest_table} g
                LEFT JOIN {$order_table} o ON g.order_no = o.order_no
                WHERE ({$where_sql}) AND o.status = 1
                ORDER BY g.id DESC";
        $sql = $wpdb->prepare($sql, $params);

        return $wpdb->get_results($sql);
    }

    /**
     * 清理过期的游客订单
     */
    public function cleanup_expired() {
        global $wpdb;
        $table = $this->db->get_table('guest_orders');
        $now = current_time('mysql');

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE expires_at < %s",
                $now
            )
        );
    }
}
