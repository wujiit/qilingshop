<?php
/**
 * 商城发票服务。
 *
 * @package QilingShop
 * @since   2.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_Invoice {

    const STATUS_PENDING   = 0;
    const STATUS_ISSUED    = 1;
    const STATUS_REJECTED  = 2;
    const STATUS_CANCELLED = 3;

    const TITLE_PERSONAL = 'personal';
    const TITLE_COMPANY  = 'company';

    /**
     * 单例实例。
     *
     * @var QLS_Invoice|null
     */
    private static $instance = null;

    /**
     * 数据库实例。
     *
     * @var QLS_Shop_Database
     */
    private $db;

    /**
     * 表名。
     *
     * @var string
     */
    private $table;
    private $titles_table;

    /**
     * 获取单例实例。
     *
     * @return QLS_Invoice
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        $this->db = QLS_Shop_Database::instance();
        $this->table = $this->db->get_table('invoices');
        $this->titles_table = $this->db->get_table('invoice_titles');
    }

    /**
     * 提交或更新订单发票申请。
     *
     * @param int   $order_id
     * @param int   $user_id
     * @param array $data
     * @return int|WP_Error
     */
    public function request($order_id, $user_id, $data) {
        $order_id = (int) $order_id;
        $user_id = (int) $user_id;
        if ($order_id <= 0 || $user_id <= 0) {
            return new WP_Error('invalid_params', __('参数错误', 'qilingshop'));
        }

        if (!$this->table_exists($this->table)) {
            return new WP_Error('missing_table', __('发票数据表不存在', 'qilingshop'));
        }

        $order = qls_shop_order()->get($order_id);
        if (!$order) {
            return new WP_Error('order_missing', __('订单不存在', 'qilingshop'));
        }

        if ((int) $order->user_id !== $user_id) {
            return new WP_Error('forbidden', __('无权操作该订单', 'qilingshop'));
        }

        if (!in_array((int) $order->status, [
            QLS_Shop_Order::STATUS_PAID,
            QLS_Shop_Order::STATUS_SHIPPED,
            QLS_Shop_Order::STATUS_COMPLETED,
        ], true)) {
            return new WP_Error('invalid_order_status', __('当前订单状态暂不能申请发票', 'qilingshop'));
        }

        $clean = $this->sanitize_request_data($data);
        if (is_wp_error($clean)) {
            return $clean;
        }

        $existing = $this->get_by_order($order_id);
        if ($existing && !in_array((int) $existing->status, [self::STATUS_REJECTED, self::STATUS_CANCELLED], true)) {
            return new WP_Error('invoice_exists', __('该订单已有发票申请', 'qilingshop'));
        }

        $payload = array_merge($clean, [
            'order_id'     => $order_id,
            'order_no'     => (string) $order->order_no,
            'user_id'      => $user_id,
            'amount'       => (float) $order->final_amount,
            'status'       => self::STATUS_PENDING,
            'requested_at' => current_time('mysql'),
            'updated_at'   => current_time('mysql'),
        ]);

        if ($existing) {
            $payload['rejected_at'] = null;
            $payload['cancelled_at'] = null;
            $payload['admin_remark'] = null;
            $payload['invoice_code'] = null;
            $payload['invoice_number'] = null;
            $payload['invoice_url'] = null;
            $payload['file_attachment_id'] = 0;
            $payload['admin_id'] = 0;
            $payload['issued_at'] = null;
            $updated = $this->db->update('invoices', $payload, ['id' => (int) $existing->id]);
            return $updated !== false ? (int) $existing->id : new WP_Error('invoice_update_failed', __('发票申请保存失败', 'qilingshop'));
        }

        $payload['created_at'] = current_time('mysql');
        $invoice_id = $this->db->insert('invoices', $payload);
        return $invoice_id ? (int) $invoice_id : new WP_Error('invoice_create_failed', __('发票申请提交失败', 'qilingshop'));
    }

    /**
     * 获取发票。
     *
     * @param int $invoice_id
     * @return object|null
     */
    public function get($invoice_id) {
        $invoice_id = (int) $invoice_id;
        if ($invoice_id <= 0 || !$this->table_exists($this->table)) {
            return null;
        }

        $invoice = $this->db->get_by_id('invoices', $invoice_id);
        return $invoice ? $this->parse_invoice($invoice) : null;
    }

    /**
     * 按订单获取发票。
     *
     * @param int $order_id
     * @return object|null
     */
    public function get_by_order($order_id) {
        $order_id = (int) $order_id;
        if ($order_id <= 0 || !$this->table_exists($this->table)) {
            return null;
        }

        $invoice = $this->db->get_row('invoices', ['order_id' => $order_id]);
        return $invoice ? $this->parse_invoice($invoice) : null;
    }

    /**
     * 获取用户发票列表。
     *
     * @param int   $user_id
     * @param array $args
     * @return array
     */
    public function get_by_user($user_id, $args = []) {
        $user_id = (int) $user_id;
        if ($user_id <= 0 || !$this->table_exists($this->table)) {
            return [];
        }

        $defaults = [
            'status' => null,
            'limit'  => 20,
            'offset' => 0,
        ];
        $args = wp_parse_args($args, $defaults);

        $where = ['user_id' => $user_id];
        if ($args['status'] !== null) {
            $where['status'] = (int) $args['status'];
        }

        $rows = $this->db->get_results('invoices', [
            'where'   => $where,
            'orderby' => 'id',
            'order'   => 'DESC',
            'limit'   => (int) $args['limit'],
            'offset'  => (int) $args['offset'],
        ]);

        return array_map([$this, 'parse_invoice'], $rows);
    }

    /**
     * 按订单 ID 批量获取发票。
     *
     * @param array $order_ids
     * @return array<int,object>
     */
    public function get_by_orders($order_ids) {
        if (!$this->table_exists($this->table)) {
            return [];
        }

        $order_ids = array_values(array_unique(array_filter(array_map('absint', (array) $order_ids))));
        if (empty($order_ids)) {
            return [];
        }

        $wpdb = $this->db->get_wpdb();
        $placeholders = implode(', ', array_fill(0, count($order_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE order_id IN ({$placeholders}) ORDER BY id DESC",
            $order_ids
        ));

        $map = [];
        foreach ($rows as $row) {
            $invoice = $this->parse_invoice($row);
            $map[(int) $invoice->order_id] = $invoice;
        }

        return $map;
    }

    /**
     * 获取发票申请列表。
     *
     * @param array $args
     * @return array
     */
    public function get_list($args = []) {
        if (!$this->table_exists($this->table)) {
            return [];
        }

        $defaults = [
            'status'  => null,
            'user_id' => 0,
            'keyword' => '',
            'limit'   => 20,
            'offset'  => 0,
        ];
        $args = wp_parse_args($args, $defaults);

        $wpdb = $this->db->get_wpdb();
        $where = $this->build_list_where($args);
        $where_sql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);
        $limit = max(0, (int) $args['limit']);
        $offset = max(0, (int) $args['offset']);

        $sql = "SELECT * FROM {$this->table}{$where_sql} ORDER BY id DESC";
        if ($limit > 0) {
            $sql .= $wpdb->prepare(' LIMIT %d OFFSET %d', $limit, $offset);
        }

        $rows = $wpdb->get_results($sql);
        return array_map([$this, 'parse_invoice'], $rows);
    }

    /**
     * 获取发票申请数量。
     *
     * @param array $args
     * @return int
     */
    public function get_count($args = []) {
        if (!$this->table_exists($this->table)) {
            return 0;
        }

        $defaults = [
            'status'  => null,
            'user_id' => 0,
            'keyword' => '',
        ];
        $args = wp_parse_args($args, $defaults);

        $wpdb = $this->db->get_wpdb();
        $where = $this->build_list_where($args);
        $where_sql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}{$where_sql}");
    }

    /**
     * 获取各状态数量。
     *
     * @return array<int,int>
     */
    public function get_status_counts() {
        $counts = [
            self::STATUS_PENDING   => 0,
            self::STATUS_ISSUED    => 0,
            self::STATUS_REJECTED  => 0,
            self::STATUS_CANCELLED => 0,
        ];

        if (!$this->table_exists($this->table)) {
            return $counts;
        }

        $wpdb = $this->db->get_wpdb();
        $rows = $wpdb->get_results("SELECT status, COUNT(*) AS total FROM {$this->table} GROUP BY status");
        foreach ($rows as $row) {
            $counts[(int) $row->status] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * 获取状态文本。
     *
     * @param int|object $status
     * @return string
     */
    public function get_status_text($status) {
        if (is_object($status) && isset($status->status)) {
            $status = (int) $status->status;
        }

        $labels = [
            self::STATUS_PENDING   => __('待开票', 'qilingshop'),
            self::STATUS_ISSUED    => __('已开票', 'qilingshop'),
            self::STATUS_REJECTED  => __('已驳回', 'qilingshop'),
            self::STATUS_CANCELLED => __('已撤销', 'qilingshop'),
        ];

        return $labels[(int) $status] ?? __('未知状态', 'qilingshop');
    }

    /**
     * 获取状态徽标 class。
     *
     * @param int|object $status
     * @return string
     */
    public function get_status_badge_class($status) {
        if (is_object($status) && isset($status->status)) {
            $status = (int) $status->status;
        }

        $classes = [
            self::STATUS_PENDING   => 'is-pending',
            self::STATUS_ISSUED    => 'is-issued',
            self::STATUS_REJECTED  => 'is-rejected',
            self::STATUS_CANCELLED => 'is-cancelled',
        ];

        return $classes[(int) $status] ?? 'is-unknown';
    }

    /**
     * 获取抬头类型文本。
     *
     * @param string $title_type
     * @return string
     */
    public function get_title_type_text($title_type) {
        return (string) $title_type === self::TITLE_COMPANY
            ? __('企业', 'qilingshop')
            : __('个人', 'qilingshop');
    }

    /**
     * 获取发票类型文本。
     *
     * @param string $invoice_type
     * @return string
     */
    public function get_invoice_type_text($invoice_type) {
        $types = [
            'electronic' => __('电子普通发票', 'qilingshop'),
            'paper'      => __('纸质普通发票', 'qilingshop'),
            'special'    => __('增值税专用发票', 'qilingshop'),
        ];

        $invoice_type = sanitize_key((string) $invoice_type);
        return $types[$invoice_type] ?? __('普通发票', 'qilingshop');
    }

    /**
     * 开具发票。
     *
     * @param int   $invoice_id
     * @param array $data
     * @return bool|WP_Error
     */
    public function issue($invoice_id, $data = []) {
        $data = is_array($data) ? $data : [];
        $invoice = $this->get($invoice_id);
        if (!$invoice) {
            return new WP_Error('invoice_missing', __('发票申请不存在', 'qilingshop'));
        }

        if ((int) $invoice->status !== self::STATUS_PENDING) {
            return new WP_Error('invalid_invoice_status', __('当前发票状态不可开票', 'qilingshop'));
        }

        $payload = [
            'status'             => self::STATUS_ISSUED,
            'invoice_code'       => sanitize_text_field($data['invoice_code'] ?? ''),
            'invoice_number'     => sanitize_text_field($data['invoice_number'] ?? ''),
            'invoice_url'        => esc_url_raw($data['invoice_url'] ?? ''),
            'file_attachment_id' => isset($data['file_attachment_id']) ? (int) $data['file_attachment_id'] : 0,
            'admin_id'           => isset($data['admin_id']) ? (int) $data['admin_id'] : get_current_user_id(),
            'admin_remark'       => isset($data['admin_remark']) ? sanitize_textarea_field($data['admin_remark']) : null,
            'issued_at'          => current_time('mysql'),
            'updated_at'         => current_time('mysql'),
        ];

        if (isset($data['extra_data'])) {
            $payload['extra_data'] = is_array($data['extra_data']) ? wp_json_encode($data['extra_data']) : (string) $data['extra_data'];
        }

        return $this->db->update('invoices', $payload, ['id' => (int) $invoice_id]) !== false;
    }

    /**
     * 驳回发票申请。
     *
     * @param int    $invoice_id
     * @param string $remark
     * @param int    $admin_id
     * @return bool|WP_Error
     */
    public function reject($invoice_id, $remark = '', $admin_id = 0) {
        $invoice = $this->get($invoice_id);
        if (!$invoice) {
            return new WP_Error('invoice_missing', __('发票申请不存在', 'qilingshop'));
        }

        if ((int) $invoice->status !== self::STATUS_PENDING) {
            return new WP_Error('invalid_invoice_status', __('当前发票状态不可驳回', 'qilingshop'));
        }

        return $this->db->update('invoices', [
            'status'       => self::STATUS_REJECTED,
            'admin_id'     => $admin_id > 0 ? (int) $admin_id : get_current_user_id(),
            'admin_remark' => sanitize_textarea_field($remark),
            'rejected_at'  => current_time('mysql'),
            'updated_at'   => current_time('mysql'),
        ], ['id' => (int) $invoice_id]) !== false;
    }

    /**
     * 取消发票申请。
     *
     * @param int $invoice_id
     * @param int $user_id
     * @return bool|WP_Error
     */
    public function cancel($invoice_id, $user_id = 0) {
        $invoice = $this->get($invoice_id);
        if (!$invoice) {
            return new WP_Error('invoice_missing', __('发票申请不存在', 'qilingshop'));
        }

        if ($user_id > 0 && (int) $invoice->user_id !== (int) $user_id) {
            return new WP_Error('forbidden', __('无权操作该发票', 'qilingshop'));
        }

        if ((int) $invoice->status !== self::STATUS_PENDING) {
            return new WP_Error('invalid_invoice_status', __('当前发票状态不可取消', 'qilingshop'));
        }

        return $this->db->update('invoices', [
            'status'       => self::STATUS_CANCELLED,
            'cancelled_at' => current_time('mysql'),
            'updated_at'   => current_time('mysql'),
        ], ['id' => (int) $invoice_id]) !== false;
    }

    /**
     * 保存发票抬头。
     *
     * @param int   $user_id
     * @param array $data
     * @return int|WP_Error
     */
    public function save_title($user_id, $data) {
        $user_id = (int) $user_id;
        $data = is_array($data) ? $data : [];
        if ($user_id <= 0 || !$this->table_exists($this->titles_table)) {
            return new WP_Error('invalid_params', __('参数错误', 'qilingshop'));
        }

        $clean = $this->sanitize_title_data($data);
        if (is_wp_error($clean)) {
            return $clean;
        }

        $clean['user_id'] = $user_id;
        $clean['updated_at'] = current_time('mysql');

        if (!empty($data['id'])) {
            $title_id = (int) $data['id'];
            $existing = $this->db->get_row('invoice_titles', ['id' => $title_id, 'user_id' => $user_id]);
            if (!$existing) {
                return new WP_Error('title_missing', __('发票抬头不存在', 'qilingshop'));
            }

            if (!empty($clean['is_default'])) {
                $reset = $this->db->update('invoice_titles', ['is_default' => 0], ['user_id' => $user_id, 'is_default' => 1]);
                if ($reset === false) {
                    return new WP_Error('title_update_failed', __('发票抬头保存失败', 'qilingshop'));
                }
            }

            $updated = $this->db->update('invoice_titles', $clean, ['id' => $title_id, 'user_id' => $user_id]);
            return $updated !== false ? $title_id : new WP_Error('title_update_failed', __('发票抬头保存失败', 'qilingshop'));
        }

        if (!empty($clean['is_default'])) {
            $reset = $this->db->update('invoice_titles', ['is_default' => 0], ['user_id' => $user_id, 'is_default' => 1]);
            if ($reset === false) {
                return new WP_Error('title_create_failed', __('发票抬头保存失败', 'qilingshop'));
            }
        }

        $clean['created_at'] = current_time('mysql');
        $title_id = $this->db->insert('invoice_titles', $clean);
        return $title_id ? (int) $title_id : new WP_Error('title_create_failed', __('发票抬头保存失败', 'qilingshop'));
    }

    /**
     * 获取用户发票抬头。
     *
     * @param int $user_id
     * @return array
     */
    public function get_titles($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0 || !$this->table_exists($this->titles_table)) {
            return [];
        }

        return $this->db->get_results('invoice_titles', [
            'where'   => ['user_id' => $user_id],
            'orderby' => 'is_default',
            'order'   => 'DESC',
        ]);
    }

    /**
     * 删除用户发票抬头。
     *
     * @param int $title_id
     * @param int $user_id
     * @return bool|WP_Error
     */
    public function delete_title($title_id, $user_id) {
        $title_id = (int) $title_id;
        $user_id = (int) $user_id;
        if ($title_id <= 0 || $user_id <= 0 || !$this->table_exists($this->titles_table)) {
            return new WP_Error('invalid_params', __('参数错误', 'qilingshop'));
        }

        $title = $this->db->get_row('invoice_titles', ['id' => $title_id, 'user_id' => $user_id]);
        if (!$title) {
            return new WP_Error('title_missing', __('发票抬头不存在', 'qilingshop'));
        }

        $deleted = $this->db->delete('invoice_titles', ['id' => $title_id, 'user_id' => $user_id]);
        if ($deleted === false || (int) $deleted < 1) {
            return new WP_Error('title_delete_failed', __('发票抬头删除失败', 'qilingshop'));
        }

        if (!empty($title->is_default)) {
            $remaining = $this->db->get_results('invoice_titles', [
                'where'   => ['user_id' => $user_id],
                'orderby' => 'updated_at',
                'order'   => 'DESC',
                'limit'   => 1,
            ]);
            if (!empty($remaining[0]->id)) {
                $this->set_default_title((int) $remaining[0]->id, $user_id);
            }
        }

        return true;
    }

    /**
     * 设置默认发票抬头。
     *
     * @param int $title_id
     * @param int $user_id
     * @return bool|WP_Error
     */
    public function set_default_title($title_id, $user_id) {
        $title_id = (int) $title_id;
        $user_id = (int) $user_id;
        if ($title_id <= 0 || $user_id <= 0 || !$this->table_exists($this->titles_table)) {
            return new WP_Error('invalid_params', __('参数错误', 'qilingshop'));
        }

        $title = $this->db->get_row('invoice_titles', ['id' => $title_id, 'user_id' => $user_id]);
        if (!$title) {
            return new WP_Error('title_missing', __('发票抬头不存在', 'qilingshop'));
        }

        $reset = $this->db->update('invoice_titles', ['is_default' => 0], ['user_id' => $user_id, 'is_default' => 1]);
        if ($reset === false) {
            return new WP_Error('title_default_failed', __('默认发票抬头设置失败', 'qilingshop'));
        }

        $updated = $this->db->update('invoice_titles', [
            'is_default' => 1,
            'updated_at' => current_time('mysql'),
        ], ['id' => $title_id, 'user_id' => $user_id]);

        return $updated !== false ? true : new WP_Error('title_default_failed', __('默认发票抬头设置失败', 'qilingshop'));
    }

    /**
     * 构建后台列表查询条件。
     *
     * @param array $args
     * @return array
     */
    private function build_list_where($args) {
        $wpdb = $this->db->get_wpdb();
        $where = [];

        if (isset($args['status']) && $args['status'] !== '' && $args['status'] !== null) {
            $where[] = $wpdb->prepare('status = %d', (int) $args['status']);
        }

        if (!empty($args['user_id'])) {
            $where[] = $wpdb->prepare('user_id = %d', (int) $args['user_id']);
        }

        $keyword = isset($args['keyword']) ? sanitize_text_field((string) $args['keyword']) : '';
        if ($keyword !== '') {
            $like = '%' . $wpdb->esc_like($keyword) . '%';
            $where[] = $wpdb->prepare(
                '(order_no LIKE %s OR title LIKE %s OR tax_no LIKE %s OR email LIKE %s OR phone LIKE %s OR invoice_number LIKE %s)',
                $like,
                $like,
                $like,
                $like,
                $like,
                $like
            );
        }

        return $where;
    }

    /**
     * 清洗发票申请数据。
     *
     * @param array $data
     * @return array|WP_Error
     */
    private function sanitize_request_data($data) {
        $data = is_array($data) ? $data : [];
        $title_type = sanitize_key($data['title_type'] ?? self::TITLE_PERSONAL);
        $title_type = in_array($title_type, [self::TITLE_PERSONAL, self::TITLE_COMPANY], true) ? $title_type : self::TITLE_PERSONAL;
        $title = sanitize_text_field($data['title'] ?? '');

        if ($title === '') {
            return new WP_Error('missing_title', __('请填写发票抬头', 'qilingshop'));
        }

        $tax_no = sanitize_text_field($data['tax_no'] ?? '');
        if ($title_type === self::TITLE_COMPANY && $tax_no === '') {
            return new WP_Error('missing_tax_no', __('企业抬头请填写税号', 'qilingshop'));
        }

        return [
            'invoice_type' => sanitize_key($data['invoice_type'] ?? 'electronic'),
            'title_type'   => $title_type,
            'title'        => $title,
            'tax_no'       => $tax_no,
            'email'        => sanitize_email($data['email'] ?? ''),
            'phone'        => sanitize_text_field($data['phone'] ?? ''),
            'extra_data'   => isset($data['extra_data']) ? (is_array($data['extra_data']) ? wp_json_encode($data['extra_data']) : (string) $data['extra_data']) : null,
        ];
    }

    /**
     * 清洗发票抬头数据。
     *
     * @param array $data
     * @return array|WP_Error
     */
    private function sanitize_title_data($data) {
        $request = $this->sanitize_request_data($data);
        if (is_wp_error($request)) {
            return $request;
        }

        return [
            'title_type'         => $request['title_type'],
            'title'              => $request['title'],
            'tax_no'             => $request['tax_no'],
            'bank_name'          => sanitize_text_field($data['bank_name'] ?? ''),
            'bank_account'       => sanitize_text_field($data['bank_account'] ?? ''),
            'registered_address' => sanitize_text_field($data['registered_address'] ?? ''),
            'registered_phone'   => sanitize_text_field($data['registered_phone'] ?? ''),
            'email'              => $request['email'],
            'is_default'         => empty($data['is_default']) ? 0 : 1,
        ];
    }

    /**
     * 解析发票。
     *
     * @param object $invoice
     * @return object
     */
    private function parse_invoice($invoice) {
        if (!empty($invoice->extra_data) && is_string($invoice->extra_data)) {
            $decoded = json_decode($invoice->extra_data, true);
            $invoice->extra_data = is_array($decoded) ? $decoded : [];
        } else {
            $invoice->extra_data = [];
        }

        foreach (['id', 'order_id', 'user_id', 'status', 'file_attachment_id', 'admin_id'] as $field) {
            if (isset($invoice->{$field})) {
                $invoice->{$field} = (int) $invoice->{$field};
            }
        }

        if (isset($invoice->amount)) {
            $invoice->amount = (float) $invoice->amount;
        }

        return $invoice;
    }

    /**
     * 判断表是否存在。
     *
     * @param string $table
     * @return bool
     */
    private function table_exists($table) {
        $wpdb = $this->db->get_wpdb();
        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }
}

/**
 * 获取发票服务实例。
 *
 * @return QLS_Invoice
 */
function qls_invoice() {
    return QLS_Invoice::instance();
}
