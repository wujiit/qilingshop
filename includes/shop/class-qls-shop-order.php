<?php
/**
 * 实物订单管理类
 * 
 * 处理订单的创建、支付、发货等流程
 *
 * @package QilingShop
 * @since   2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_Shop_Order {
    const MANUAL_PENDING_CLEANUP_GRACE_PERIOD = 300;

    /**
     * 单例实例
     */
    private static $instance = null;

    /**
     * 数据库实例
     */
    private $db;

    /**
     * 订单状态常量
     */
    const STATUS_PENDING   = 0;  // 待付款
    const STATUS_PAID      = 1;  // 已付款
    const STATUS_SHIPPED   = 2;  // 已发货
    const STATUS_COMPLETED = 3;  // 已完成
    const STATUS_CANCELLED = 4;  // 已取消
    const STATUS_REFUNDING = 5;  // 退款中
    const STATUS_REFUNDED  = 6;  // 已退款

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
        $this->db = QLS_Shop_Database::instance();
        
        // 注册自动取消订单钩子（由外部任务中心触发）
        add_action('qls_shop_auto_cancel_orders', [$this, 'auto_cancel_expired_orders']);
    }

    /**
     * 创建订单
     *
     * @param array $data 订单数据
     * @return string|false 订单号或false
     */
    public function create($data) {
        $defaults = [
            'order_no'          => '',
            'user_id'           => get_current_user_id(),
            'total_amount'      => 0,
            'shipping_fee'      => 0,
            'discount_amount'   => 0,
            'points_used'       => 0,
            'final_amount'      => 0,
            'payment_method'    => '',
            'status'            => self::STATUS_PENDING,
            'receiver_name'     => '',
            'receiver_phone'    => '',
            'receiver_province' => '',
            'receiver_city'     => '',
            'receiver_district' => '',
            'receiver_address'  => '',
            'buyer_remark'      => '',
            'ip_address'        => $this->get_client_ip(),
            'user_agent'        => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : '',
        ];

        $data = wp_parse_args($data, $defaults);

        foreach (['total_amount', 'shipping_fee', 'discount_amount', 'points_used', 'final_amount'] as $amount_field) {
            $amount = (float) $data[$amount_field];
            if (!is_finite($amount) || $amount < 0) {
                return false;
            }
            $data[$amount_field] = round($amount, 2);
        }

        if (empty($data['order_no'])) {
            $prefix = !empty($data['is_group_order']) ? 'TUAN' : 'SHOP';
            $data['order_no'] = $this->generate_order_no($prefix);
        }

        // 过滤订单数据
        $data = apply_filters('qls_shop_before_create_order', $data);

        $order_id = $this->db->insert('orders', $data);

        if ($order_id) {
            do_action('qls_shop_order_created', $order_id, $data);
            return $data['order_no'];
        }

        return false;
    }

    /**
     * 添加订单商品明细
     *
     * @param int   $order_id
     * @param array $items
     * @return bool
     */
    public function add_items($order_id, $items) {
        foreach ($items as $item) {
            $quantity = (int) ($item['quantity'] ?? 0);
            $price = (float) ($item['price'] ?? -1);
            if ($quantity < 1 || !is_finite($price) || $price < 0) {
                return false;
            }

            $item_data = [
                'order_id'      => $order_id,
                'product_id'    => $item['product_id'],
                'sku_id'        => $item['sku_id'],
                'product_title' => $item['product_title'],
                'sku_attrs'     => is_array($item['sku_attrs']) ? wp_json_encode($item['sku_attrs']) : $item['sku_attrs'],
                'image'         => $item['image'] ?? '',
                'price'         => round($price, 2),
                'quantity'      => $quantity,
                'total'         => round($price * $quantity, 2),
            ];

            $inserted = $this->db->insert('order_items', $item_data);
            if (!$inserted) {
                return false;
            }
        }

        return true;
    }

    /**
     * 获取订单
     *
     * @param int  $order_id
     * @param bool $with_items 是否包含商品明细
     * @return object|null
     */
    public function get($order_id, $with_items = false) {
        $order = $this->db->get_by_id('orders', $order_id);

        if (!$order) {
            return null;
        }

        if ($with_items) {
            $order->items = $this->get_items($order_id);
        }

        return $order;
    }

    /**
     * 批量获取订单，减少后台列表页逐单查询。
     *
     * @param int[] $order_ids
     * @param bool  $with_items 是否包含商品明细
     * @return array<int, object>
     */
    public function get_by_ids($order_ids, $with_items = false) {
        $order_ids = array_values(array_unique(array_filter(array_map('intval', (array) $order_ids))));
        if (empty($order_ids)) {
            return [];
        }

        $wpdb = $this->db->get_wpdb();
        $table = $this->db->get_table('orders');
        $placeholders = implode(', ', array_fill(0, count($order_ids), '%d'));
        $sql = "SELECT * FROM {$table} WHERE id IN ({$placeholders})";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $order_ids));

        $order_map = [];
        foreach ((array) $rows as $row) {
            $order_id = (int) ($row->id ?? 0);
            if ($order_id <= 0) {
                continue;
            }

            $order_map[$order_id] = $row;
        }

        if ($with_items && !empty($order_map)) {
            $items_map = $this->get_items_by_orders(array_keys($order_map));
            foreach ($order_map as $order_id => $order) {
                $order->items = isset($items_map[$order_id]) ? $items_map[$order_id] : [];
            }
        }

        return $order_map;
    }

    /**
     * 根据订单号获取订单
     *
     * @param string $order_no
     * @param bool   $with_items
     * @return object|null
     */
    public function get_by_order_no($order_no, $with_items = false) {
        $order = $this->db->get_row('orders', ['order_no' => $order_no]);

        if (!$order) {
            return null;
        }

        if ($with_items) {
            $order->items = $this->get_items($order->id);
        }

        return $order;
    }

    /**
     * 根据订单号锁定订单行。
     *
     * @param string $order_no
     * @param bool   $with_items
     * @return object|null
     */
    private function get_by_order_no_for_update($order_no, $with_items = false) {
        $wpdb = $this->db->get_wpdb();
        $table = $this->db->get_table('orders');
        $order = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE order_no = %s LIMIT 1 FOR UPDATE",
                $order_no
            )
        );

        if (!$order) {
            return null;
        }

        if ($with_items) {
            $order->items = $this->get_items($order->id);
        }

        return $order;
    }

    /**
     * 获取数据库级命名锁，串行化订单级写操作。
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
     * 构造订单级互斥锁名。
     *
     * 同一订单的取消、删除、退款、发货等写操作必须串行，
     * 这里故意不再区分 action，避免不同动作使用不同锁导致穿透。
     *
     * @param int $order_id
     * @param string $action
     * @return string
     */
    private function build_order_lock_name($order_id, $action = '') {
        return 'qlsord_' . md5((string) absint($order_id));
    }

    /**
     * 获取当前结账主体标识。
     *
     * @return string
     */
    private function get_checkout_actor_key() {
        $user_id = (int) get_current_user_id();
        if ($user_id > 0) {
            return 'u' . $user_id;
        }

        if (class_exists('QilingShop_Guest')) {
            $guest_id = (string) QilingShop_Guest::instance()->get_guest_id();
            if ($guest_id !== '') {
                return 'g' . substr(md5($guest_id), 0, 16);
            }
        }

        $session_id = isset($_COOKIE['qls_cart_session']) ? sanitize_text_field((string) wp_unslash($_COOKIE['qls_cart_session'])) : '';
        if ($session_id !== '') {
            return 's' . substr(md5($session_id), 0, 16);
        }

        $ip = '';
        if (function_exists('qilingshop_security')) {
            $ip = (string) qilingshop_security()->get_client_ip();
        }
        if ($ip === '' && isset($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field((string) wp_unslash($_SERVER['REMOTE_ADDR']));
        }

        return 'ip' . substr(md5($ip !== '' ? $ip : '0.0.0.0'), 0, 16);
    }

    /**
     * 构造结账互斥锁名。
     *
     * @return string
     */
    private function build_checkout_lock_name() {
        return 'qlschk_' . md5($this->get_checkout_actor_key());
    }

    /**
     * 获取订单商品明细
     *
     * @param int $order_id
     * @return array
     */
    public function get_items($order_id) {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return [];
        }

        $items_map = $this->get_items_by_orders([$order_id]);
        return isset($items_map[$order_id]) ? $items_map[$order_id] : [];
    }

    /**
     * 批量获取订单商品明细，避免列表页逐单查询。
     *
     * @param int[] $order_ids
     * @return array<int, array>
     */
    public function get_items_by_orders($order_ids) {
        $order_ids = array_values(array_unique(array_filter(array_map('intval', (array) $order_ids))));
        if (empty($order_ids)) {
            return [];
        }

        $wpdb = $this->db->get_wpdb();
        $table = $this->db->get_table('order_items');
        $placeholders = implode(', ', array_fill(0, count($order_ids), '%d'));
        $sql = "SELECT * FROM {$table} WHERE order_id IN ({$placeholders}) ORDER BY order_id ASC, id ASC";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $order_ids));

        $items_map = [];
        foreach ($order_ids as $order_id) {
            $items_map[$order_id] = [];
        }

        foreach ((array) $rows as $row) {
            $row = $this->parse_order_item($row);
            $row_order_id = (int) ($row->order_id ?? 0);
            if (!isset($items_map[$row_order_id])) {
                $items_map[$row_order_id] = [];
            }

            $items_map[$row_order_id][] = $row;
        }

        return $items_map;
    }

    /**
     * 批量获取后台订单列表所需的轻量订单商品明细，避免拉取大字段。
     *
     * @param int[] $order_ids
     * @return array<int, array>
     */
    public function get_admin_list_items_by_orders($order_ids) {
        $order_ids = array_values(array_unique(array_filter(array_map('intval', (array) $order_ids))));
        if (empty($order_ids)) {
            return [];
        }

        $wpdb = $this->db->get_wpdb();
        $table = $this->db->get_table('order_items');
        $placeholders = implode(', ', array_fill(0, count($order_ids), '%d'));
        $sql = "SELECT id, order_id, product_id, product_title, image, quantity, shipped_quantity,
                       CASE WHEN TRIM(COALESCE(virtual_content, '')) <> '' THEN 1 ELSE 0 END AS has_virtual_content
                FROM {$table}
                WHERE order_id IN ({$placeholders})
                ORDER BY order_id ASC, id ASC";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $order_ids));

        $items_map = [];
        foreach ($order_ids as $order_id) {
            $items_map[$order_id] = [];
        }

        foreach ((array) $rows as $row) {
            $row = $this->parse_admin_list_order_item($row);
            $row_order_id = (int) ($row->order_id ?? 0);
            if (!isset($items_map[$row_order_id])) {
                $items_map[$row_order_id] = [];
            }

            $items_map[$row_order_id][] = $row;
        }

        return $items_map;
    }

    /**
     * 规范化订单明细字段。
     *
     * @param object $item
     * @return object
     */
    private function parse_order_item($item) {
        if (!empty($item->sku_attrs)) {
            $decoded = json_decode($item->sku_attrs, true);
            $item->sku_attrs = is_array($decoded) ? $decoded : $item->sku_attrs;
        }
        if (!empty($item->virtual_content)) {
            $decoded = json_decode($item->virtual_content, true);
            $item->virtual_content = is_array($decoded) ? $decoded : $item->virtual_content;
        }

        return $item;
    }

    /**
     * 规范化后台订单列表使用的轻量订单明细字段。
     *
     * @param object $item
     * @return object
     */
    private function parse_admin_list_order_item($item) {
        $item->id = (int) ($item->id ?? 0);
        $item->order_id = (int) ($item->order_id ?? 0);
        $item->product_id = (int) ($item->product_id ?? 0);
        $item->quantity = (int) ($item->quantity ?? 0);
        $item->shipped_quantity = (int) ($item->shipped_quantity ?? 0);
        $item->has_virtual_content = (int) ($item->has_virtual_content ?? 0);
        $item->product_title = (string) ($item->product_title ?? '');
        $item->image = (string) ($item->image ?? '');

        return $item;
    }

    /**
     * 获取订单状态统计。
     *
     * @return array<int, int>
     */
    public function get_status_counts() {
        $wpdb = $this->db->get_wpdb();
        $table = $this->db->get_table('orders');
        $rows = $wpdb->get_results("SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status");

        $counts = [];
        foreach ((array) $rows as $row) {
            $counts[(int) $row->status] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * 更新订单状态
     *
     * @param int $order_id
     * @param int $status
     * @return bool
     */
    public function update_status($order_id, $status) {
        $order = $this->get($order_id);
        
        if (!$order) {
            return false;
        }

        $old_status = (int) $order->status;
        $status = (int) $status;

        if ($old_status === $status) {
            return true;
        }

        $update_data = ['status' => $status];

        // 更新时间戳
        switch ($status) {
            case self::STATUS_PAID:
                $update_data['paid_at'] = current_time('mysql');
                break;
            case self::STATUS_SHIPPED:
                $update_data['shipped_at'] = current_time('mysql');
                break;
            case self::STATUS_COMPLETED:
                $update_data['completed_at'] = current_time('mysql');
                break;
            case self::STATUS_CANCELLED:
                $update_data['cancelled_at'] = current_time('mysql');
                break;
        }

        $result = $this->db->update('orders', $update_data, [
            'id'     => $order_id,
            'status' => $old_status,
        ]);

        if ((int) $result === 1) {
            do_action('qls_shop_order_status_changed', $order_id, $status, $old_status);
            do_action('qls_shop_order_status_' . $status, $order_id);
            return true;
        }

        if ((int) $result === 0) {
            $latest = $this->get($order_id);
            return $latest && (int) $latest->status === $status;
        }

        return false;
    }

    /**
     * 更新订单收货/备注信息。
     *
     * @param int   $order_id
     * @param array $data
     * @return bool
     */
    public function update_contact_fields($order_id, $data) {
        $order_id = (int) $order_id;
        if ($order_id <= 0 || !is_array($data) || empty($data)) {
            return false;
        }

        $allowed_fields = [
            'receiver_name',
            'receiver_phone',
            'receiver_province',
            'receiver_city',
            'receiver_district',
            'receiver_address',
            'buyer_remark',
        ];

        $update_data = array_intersect_key($data, array_flip($allowed_fields));
        if (empty($update_data)) {
            return false;
        }

        $lock_name = $this->build_order_lock_name($order_id, 'contact');
        if (!$this->acquire_named_lock($lock_name, 5)) {
            return false;
        }

        try {
            $order = $this->get($order_id);
            if (!$order) {
                return false;
            }

            $updated = $this->db->update('orders', $update_data, ['id' => $order_id]);
            return $updated !== false;
        } finally {
            $this->release_named_lock($lock_name);
        }
    }

    /**
     * 标记订单为已支付
     *
     * @param string $order_no
     * @param string $payment_no
     * @param string $payment_method
     * @return bool
     */
    public function mark_paid($order_no, $payment_no = '', $payment_method = '', $payment_meta = []) {
        $order = $this->get_by_order_no($order_no, true);

        if (!$order) {
            return false;
        }

        $lock_name = $this->build_order_lock_name((int) $order->id, 'mark_paid');
        if (!$this->acquire_named_lock($lock_name, 5)) {
            return false;
        }

        try {
            $order = $this->get_by_order_no($order_no, true);
            if (!$order) {
                return false;
            }

            $status = (int) $order->status;
            if (in_array($status, [self::STATUS_PAID, self::STATUS_SHIPPED, self::STATUS_COMPLETED], true)) {
                if (!$this->validate_order_amount_integrity($order)) {
                    qilingshop_log('Rejected paid shop order finalization for invalid amount', 'error', [
                        'order_no' => (string) $order_no,
                        'order_id' => (int) $order->id,
                    ]);
                    return false;
                }
                return $this->finalize_paid_order($order, $order_no, $payment_method);
            }

            // 非待支付状态（如已取消/退款中/已退款）不允许再标记支付成功
            if ($status !== self::STATUS_PENDING) {
                return false;
            }

            if (!$this->validate_order_amount_integrity($order)) {
                qilingshop_log('Rejected shop payment for invalid order amount', 'error', [
                    'order_no' => (string) $order_no,
                    'order_id' => (int) $order->id,
                ]);
                return false;
            }

            $update_data = [
                'status'  => self::STATUS_PAID,
                'paid_at' => current_time('mysql'),
            ];

            if ($payment_no) {
                $update_data['payment_no'] = $payment_no;
            }
            if ($payment_method) {
                $update_data['payment_method'] = $payment_method;
            }
            if (is_array($payment_meta)) {
                $payment_channel_version = sanitize_key((string) ($payment_meta['payment_channel_version'] ?? ''));
                if ($payment_channel_version !== '') {
                    $update_data['payment_channel_version'] = $payment_channel_version;
                }

                if (array_key_exists('payment_channel_meta', $payment_meta)) {
                    $channel_meta = $payment_meta['payment_channel_meta'];
                    if (is_array($channel_meta) || is_object($channel_meta)) {
                        $channel_meta = wp_json_encode($channel_meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    } else {
                        $channel_meta = (string) $channel_meta;
                    }

                    if ($channel_meta !== '') {
                        $update_data['payment_channel_meta'] = $channel_meta;
                    }
                }
            }

            $result = $this->db->update('orders', $update_data, [
                'order_no' => $order_no,
                'status'   => self::STATUS_PENDING,
            ]);

            if ($result === 0) {
                // 并发回调场景：读取最新状态并判定是否已被其他请求处理成功
                $latest = $this->get_by_order_no($order_no, true);
                if ($latest && in_array((int) $latest->status, [self::STATUS_PAID, self::STATUS_SHIPPED, self::STATUS_COMPLETED], true)) {
                    return $this->finalize_paid_order($latest, $order_no, $payment_method);
                }
                return false;
            }

            if ($result !== false) {
                // 已收到并验签的支付结果后保持已支付状态，收尾失败由幂等对账重试。
                return $this->finalize_paid_order($order, $order_no, $payment_method);
            }

            return false;
        } finally {
            $this->release_named_lock($lock_name);
        }
    }

    /**
     * 支付前校验订单头金额与订单明细，拦截历史异常待付款订单。
     */
    private function validate_order_amount_integrity($order) {
        if (!$order || empty($order->id)) {
            return false;
        }

        $items = !empty($order->items) ? $order->items : $this->get_items((int) $order->id);
        if (empty($items)) {
            return false;
        }

        $item_total_fen = 0;
        foreach ($items as $item) {
            $quantity = (int) ($item->quantity ?? 0);
            $price = (float) ($item->price ?? -1);
            if ($quantity < 1 || !is_finite($price) || $price < 0) {
                return false;
            }
            $item_total_fen += (int) round($price * 100) * $quantity;
        }

        $order_total_fen = (int) round((float) ($order->total_amount ?? 0) * 100);
        $final_amount_fen = (int) round((float) ($order->final_amount ?? 0) * 100);
        $shipping_fee_fen = (int) round((float) ($order->shipping_fee ?? 0) * 100);
        $discount_fen = (int) round((float) ($order->discount_amount ?? 0) * 100);
        $points_used = max(0, (float) ($order->points_used ?? 0));

        $payment_method = sanitize_key((string) ($order->payment_method ?? ''));
        $gross_final_fen = max(0, $order_total_fen + $shipping_fee_fen - $discount_fen);
        $final_matches = $payment_method === 'points'
            ? $final_amount_fen === 0
            : ($points_used > 0
                ? $final_amount_fen >= 0 && $final_amount_fen <= $gross_final_fen
                : $final_amount_fen === $gross_final_fen);

        return $item_total_fen === $order_total_fen
            && $order_total_fen >= 0
            && $final_amount_fen >= 0
            && $shipping_fee_fen >= 0
            && $discount_fen >= 0
            && $discount_fen <= $order_total_fen
            && $final_matches;
    }

    /**
     * 处理已支付订单
     *
     * @param object $order
     */
    private function finalize_paid_order($order, $order_no, $payment_method = '') {
        if (!$order || empty($order->id)) {
            return false;
        }

        $handled_order_id = 0;
        $run_group_followup = false;

        $this->db->begin_transaction();

        try {
            $locked_order = $this->get_by_order_no_for_update($order_no, true);
            if (!$locked_order) {
                throw new Exception('Order not found when finalizing payment');
            }

            if ((int) ($locked_order->paid_handled ?? 0) === 1) {
                $this->db->commit();
                return true;
            }

            if ($locked_order->paid_handled === null) {
                $normalized = $this->db->update('orders', ['paid_handled' => 0], ['id' => (int) $locked_order->id]);
                if ($normalized === false) {
                    throw new Exception('Failed to normalize paid handled state');
                }
                $locked_order->paid_handled = 0;
            }

            if (class_exists('QLS_Coupon') && !empty($locked_order->seller_remark)) {
                $remark_data = json_decode($locked_order->seller_remark, true);
                $coupon_claim_id = $remark_data['coupon_claim_id'] ?? 0;
                if ($coupon_claim_id > 0 && $locked_order->discount_amount > 0) {
                    $coupon_used = QLS_Coupon::mark_as_used($coupon_claim_id, $order_no, 'shop', $locked_order->total_amount, $locked_order->discount_amount, false);
                    if (!$coupon_used) {
                        throw new Exception('Failed to mark coupon as used');
                    }
                }
            }

            $handled = $this->handle_paid_order($locked_order);
            if (!$handled) {
                throw new Exception('Handle paid order failed');
            }

            $run_group_followup = !empty($locked_order->is_group_order) || !empty(qilingshop_get_group_order_data($locked_order->id));

            $updated = $this->db->update('orders', [
                'paid_handled'    => 1,
                'paid_handled_at' => current_time('mysql'),
            ], [
                'id'           => $locked_order->id,
                'paid_handled' => 0,
            ]);

            if ($updated === false || (int) $updated !== 1) {
                throw new Exception('Failed to mark paid order handled');
            }

            $handled_order_id = (int) $locked_order->id;
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            qilingshop_log('Finalize paid shop order failed: ' . $e->getMessage(), 'error', [
                'order_no' => $order_no,
                'order_id' => (int) ($order->id ?? 0),
            ]);
            return false;
        }

        if ($handled_order_id > 0) {
            $fresh_order = $this->get($handled_order_id, true);
            if ($fresh_order) {
                if ($run_group_followup) {
                    $this->handle_group_order_paid($fresh_order);
                }
                $this->maybe_send_virtual_card_email($fresh_order);
            }
            do_action('qls_shop_order_paid', $handled_order_id, $payment_method);
        }

        return true;
    }

    /**
     * 处理已支付订单
     *
     * @param object $order
     */
    private function handle_paid_order($order) {
        // 扣减库存
        $reduce_on = get_option('qls_shop_stock_reduce_on', 'order');
        if ($reduce_on === 'payment') {
            $stock_reduced = $this->reduce_stock($order);
            if (!$stock_reduced) {
                $this->update_status($order->id, self::STATUS_REFUNDING);
                return false;
            }
        }

        // 处理虚拟商品。先完成内容分配，再执行销量/佣金/返积分等副作用。
        $is_fully_virtual = $this->is_fully_virtual($order);
        if (!$this->allocate_virtual_content($order)) {
            qilingshop_log('Virtual content allocation failed for paid shop order', 'error', [
                'order_id' => (int) ($order->id ?? 0),
                'order_no' => (string) ($order->order_no ?? ''),
            ]);
            return false;
        }

        // 增加销量
        foreach ($order->items as $item) {
            qls_product()->increment_sales_count($item->product_id, $item->quantity);
        }

        // 处理推广提成（如果启用）
        if ($order->user_id > 0 && class_exists('QilingShop_Affiliate')) {
            $commission_ok = QilingShop_Affiliate::instance()->process_commission(
                $order->user_id,
                $order->final_amount,
                'shop_order',
                $order->order_no
            );
            if (!$commission_ok) {
                $queued = QilingShop_Affiliate::instance()->queue_commission_retry(
                    (int) $order->user_id,
                    (float) $order->final_amount,
                    'shop_order',
                    (string) $order->order_no,
                    'shop_order'
                );
                qilingshop_log(
                    $queued ? 'Affiliate commission queued for retry after shop order' : 'Affiliate commission retry queue failed after shop order',
                    'error',
                    [
                    'order_no' => (string) $order->order_no,
                    'user_id'  => (int) $order->user_id,
                    ]
                );
            }
        }

        // 订单返积分（商城订单）
        if (!$this->maybe_reward_order_points($order)) {
            return false;
        }
        
        // 如果是纯虚拟订单，自动完成
        if ($is_fully_virtual) {
            $this->db->update('orders', [
                'status'       => self::STATUS_COMPLETED,
                'completed_at' => current_time('mysql'),
            ], ['id' => $order->id]);
            
            do_action('qls_shop_virtual_order_completed', $order->id);
        }
        return true;
    }

    /**
     * 订单返积分（商城订单）
     *
     * @param object $order
     */
    private function maybe_reward_order_points($order) {
        if (!$order || empty($order->user_id)) {
            return true;
        }
        if (!get_option('qilingshop_order_points_rebate_enabled', false)) {
            return true;
        }
        $paid_amount = (float) ($order->final_amount ?? 0);
        if ($paid_amount <= 0) {
            return true;
        }

        $points_manager = class_exists('QilingShop_Points') ? QilingShop_Points::instance() : null;
        if (!$points_manager) {
            return true;
        }
        if ($points_manager->has_points_log($order->user_id, 'shop_order_rebate', (int) $order->id)) {
            return true;
        }

        $points = $this->calculate_shop_rebate_points($order);
        if ($points <= 0) {
            return true;
        }

        return $points_manager->add_points(
            $order->user_id,
            $points,
            'shop_order_rebate',
            sprintf(__('商城订单返积分：%s', 'qilingshop'), $order->order_no),
            (int) $order->id
        );
    }

    /**
     * 计算商城订单返积分
     *
     * @param object $order
     * @return float
     */
    private function calculate_shop_rebate_points($order) {
        if (!$order || empty($order->items)) {
            return 0;
        }

        $total_amount = (float) ($order->total_amount ?? 0);
        if ($total_amount <= 0) {
            return 0;
        }

        $net_items_amount = (float) ($order->final_amount ?? 0) - (float) ($order->shipping_fee ?? 0);
        if ($net_items_amount <= 0) {
            return 0;
        }

        $category_rate_map = qilingshop_get_order_rebate_rules('shop');
        $default_rate = (float) get_option('qilingshop_order_points_rebate_rate', 0);
        $product_cache = [];
        $points = 0;

        foreach ($order->items as $item) {
            $item_total = (float) ($item->price ?? 0) * (int) ($item->quantity ?? 0);
            if ($item_total <= 0) {
                continue;
            }
            $allocated_amount = $net_items_amount * ($item_total / $total_amount);
            $rate = $default_rate;

            $product_id = (int) ($item->product_id ?? 0);
            if ($product_id > 0) {
                if (!isset($product_cache[$product_id])) {
                    $product_cache[$product_id] = function_exists('qls_product') ? qls_product()->get($product_id) : null;
                }
                $product = $product_cache[$product_id];
                if ($product && !empty($product->category_id)) {
                    $category_id = (int) $product->category_id;
                    if (isset($category_rate_map[$category_id])) {
                        $rate = (float) $category_rate_map[$category_id];
                    }
                }
            }

            if ($rate > 0) {
                $points += qilingshop_calculate_rebate_points($allocated_amount, $rate);
            }
        }

        return round($points, 2);
    }

    /**
     * 处理团购订单支付完成
     *
     * @param object $order
     */
    private function handle_group_order_paid($order) {
        // 检查是否为团购订单
        if (empty($order->is_group_order)) {
            return;
        }
        
        // 获取订单的团购信息
        $group_info = qilingshop_get_group_order_data($order->id);
        if (!$group_info) {
            return;
        }
        
        $rule_id = $group_info['rule_id'] ?? 0;
        $is_leader = $group_info['is_leader'] ?? false;
        $group_id = $group_info['group_id'] ?? 0;
        
        if ($is_leader) {
            $rule = qls_group()->get_rule($rule_id);
            if (!$rule || !qls_group()->is_rule_active($rule)) {
                qilingshop_clear_group_order_data($order->id);
                $this->refund_group_order($order, __('团购活动已结束，已退款', 'qilingshop'));
                return;
            }

            // 团长：创建新团
            $new_group_id = qls_group()->create_group($rule_id, $order->user_id, $order->id);
            
            if ($new_group_id) {
                // 更新订单的团购ID
                $this->db->update('orders', ['group_id' => $new_group_id], ['id' => $order->id]);
                
                // 删除临时信息
                qilingshop_clear_group_order_data($order->id);
                
                // 检查是否1人即成团（特殊情况）
                if ($rule && $rule->group_size <= 1) {
                    qls_group()->mark_success($new_group_id);
                }
            } else {
                qilingshop_clear_group_order_data($order->id);
                $this->refund_group_order($order, __('开团失败，已退款', 'qilingshop'));
            }
        } else {
            // 参团：加入现有团
            if ($group_id > 0) {
                $result = qls_group()->join_group($group_id, $order->user_id, $order->id);
                
                if (!empty($result['success'])) {
                    $this->db->update('orders', ['group_id' => $group_id], ['id' => $order->id]);
                    qilingshop_clear_group_order_data($order->id);
                } else {
                    qilingshop_clear_group_order_data($order->id);
                    $message = !empty($result['message']) ? $result['message'] : __('参团失败，已退款', 'qilingshop');
                    $this->refund_group_order($order, $message);
                }
            } else {
                qilingshop_clear_group_order_data($order->id);
                $this->refund_group_order($order, __('拼团不存在，已退款', 'qilingshop'));
            }
        }
    }

    private function refund_group_order($order, $reason) {
        if (empty($order) || empty($order->user_id)) {
            return false;
        }

        if ($order->status < self::STATUS_PAID) {
            return false;
        }

        $amount = floatval($order->final_amount);
        $needs_balance_refund = $amount > 0;
        if ($needs_balance_refund && !class_exists('QilingShop_Points')) {
            return false;
        }

        $lock_name = $this->build_order_lock_name((int) $order->id, 'group_refund');
        if (!$this->acquire_named_lock($lock_name, 5)) {
            return false;
        }

        try {
            $refunded = true;
            if ($needs_balance_refund) {
                $points_manager = QilingShop_Points::instance();
                if (!$points_manager->has_points_log((int) $order->user_id, 'qls_group_refund', (int) $order->id)) {
                    $refunded = $points_manager->add_withdrawable_balance(
                        $order->user_id,
                        $amount,
                        'qls_group_refund',
                        $reason,
                        $order->id
                    );
                }
            }

            if ($refunded) {
                $this->restore_stock($order);
                if (function_exists('qls_card_inventory')) {
                    qls_card_inventory()->revoke_by_order($order->id);
                }
                $this->db->update('orders', ['group_id' => 0], ['id' => $order->id]);
                $updated = $this->update_status($order->id, self::STATUS_REFUNDED);
                if ($updated) {
                    $this->db->update('orders', ['cancelled_at' => current_time('mysql')], ['id' => $order->id]);
                }
            }

            return $refunded;
        } finally {
            $this->release_named_lock($lock_name);
        }
    }

    /**
     * 判断订单是否为纯虚拟订单
     *
     * @param object $order
     * @return bool
     */
    public function is_fully_virtual($order) {
        if (!isset($order->items) || empty($order->items)) {
            $order->items = $this->get_items($order->id);
        }
        
        foreach ($order->items as $item) {
            if (!qls_product()->is_virtual($item->product_id)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * 分配虚拟商品内容
     *
     * @param object $order
     * @return bool
     */
    private function allocate_virtual_content($order) {
        if (!isset($order->items) || empty($order->items)) {
            $order->items = $this->get_items($order->id);
        }
        
        foreach ($order->items as $item) {
            $product = qls_product()->get($item->product_id);
            
            if (!$product || !qls_product()->is_virtual($product)) {
                continue;
            }
            
            $virtual_content = [];

            if (!empty($item->virtual_content) && is_array($item->virtual_content)) {
                $existing_type = sanitize_key((string) ($item->virtual_content['type'] ?? ''));
                $has_retryable_error = $existing_type === 'card' && !empty($item->virtual_content['error']);
                if (!$has_retryable_error) {
                    continue;
                }
            }
            
            switch ($product->virtual_type) {
                case 'download':
                    // 下载链接类型，直接从商品获取
                    $virtual_content = [
                        'type' => 'download',
                        'download_url' => $product->virtual_content['download_url'] ?? '',
                        'download_code' => $product->virtual_content['download_code'] ?? '',
                    ];
                    break;
                    
                case 'card':
                    // 卡密类型，从库存中分配
                    $allocated = qls_card_inventory()->allocate(
                        $item->sku_id,
                        $order->id,
                        $item->id,
                        $item->quantity,
                        false
                    );
                    
                    if ($allocated) {
                        $virtual_content = [
                            'type' => 'card',
                            'cards' => $allocated,
                        ];
                    } else {
                        return false;
                    }
                    break;
                    
                case 'custom':
                    // 自定义内容类型
                    $virtual_content = [
                        'type' => 'custom',
                        'content' => $product->virtual_content['custom_content'] ?? '',
                    ];
                    break;
            }
            
            // 更新订单明细的虚拟内容
            if (!empty($virtual_content)) {
                $updated = $this->db->update('order_items', [
                    'virtual_content' => wp_json_encode($virtual_content),
                ], ['id' => $item->id]);
                if ($updated === false) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * 向联系人邮箱发送虚拟商品发卡信息（用户/游客均支持）。
     *
     * @param object $order
     * @return void
     */
    private function maybe_send_virtual_card_email($order) {
        if (empty($order) || empty($order->id)) {
            return;
        }

        $to = $this->extract_virtual_contact_email($order);
        if ($to === '') {
            return;
        }

        $items = $this->get_items((int) $order->id);
        if (empty($items)) {
            return;
        }

        $card_groups = array();
        foreach ($items as $item) {
            $virtual_content = is_array($item->virtual_content ?? null) ? $item->virtual_content : array();
            if (empty($virtual_content) || (($virtual_content['type'] ?? '') !== 'card')) {
                continue;
            }

            $cards = isset($virtual_content['cards']) && is_array($virtual_content['cards']) ? $virtual_content['cards'] : array();
            if (empty($cards)) {
                continue;
            }

            $card_groups[] = array(
                'title' => !empty($item->product_title) ? (string) $item->product_title : sprintf(__('商品 #%d', 'qilingshop'), (int) ($item->product_id ?? 0)),
                'cards' => $cards,
            );
        }

        if (empty($card_groups)) {
            return;
        }

        $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $subject = sprintf(__('[%s] 虚拟商品发卡信息', 'qilingshop'), $site_name);

        $plain_lines = array(
            sprintf(__('订单号：%s', 'qilingshop'), (string) ($order->order_no ?? '')),
            sprintf(__('联系邮箱：%s', 'qilingshop'), $to),
            sprintf(__('发放时间：%s', 'qilingshop'), current_time('Y-m-d H:i:s')),
            '',
            __('以下是您的虚拟商品卡密信息，请妥善保管：', 'qilingshop'),
        );

        $html_lines = array(
            __('订单号', 'qilingshop')   => (string) ($order->order_no ?? ''),
            __('联系邮箱', 'qilingshop') => $to,
            __('发放时间', 'qilingshop') => current_time('Y-m-d H:i:s'),
        );

        foreach ($card_groups as $group_index => $group) {
            $plain_lines[] = '';
            $plain_lines[] = '【' . wp_strip_all_tags((string) $group['title']) . '】';

            $group_lines = array();
            foreach ($group['cards'] as $idx => $card) {
                $card_no = isset($card['card_no']) ? trim((string) $card['card_no']) : '';
                $card_secret = isset($card['card_secret']) ? trim((string) $card['card_secret']) : '';
                if ($card_no === '' && $card_secret === '') {
                    continue;
                }

                if ($card_no !== '') {
                    $plain_lines[] = sprintf(__('卡号 %1$d：%2$s', 'qilingshop'), (int) $idx + 1, $card_no);
                    $group_lines[] = sprintf(__('卡号 %1$d：%2$s', 'qilingshop'), (int) $idx + 1, $card_no);
                }
                if ($card_secret !== '') {
                    $plain_lines[] = sprintf(__('卡密 %1$d：%2$s', 'qilingshop'), (int) $idx + 1, $card_secret);
                    $group_lines[] = sprintf(__('卡密 %1$d：%2$s', 'qilingshop'), (int) $idx + 1, $card_secret);
                }
            }

            if (!empty($group_lines)) {
                $group_label = wp_strip_all_tags((string) $group['title']);
                if ($group_label === '') {
                    $group_label = sprintf(__('商品 #%d', 'qilingshop'), (int) $group_index + 1);
                } elseif (isset($html_lines[$group_label])) {
                    $group_label .= ' #' . ((int) $group_index + 1);
                }
                $html_lines[$group_label] = implode("\n", $group_lines);
            }
        }

        $message = implode("\n", $plain_lines);
        $headers = array('Content-Type: text/plain; charset=UTF-8');

        if (function_exists('developer_starter_build_html_email_template')) {
            $html_message = developer_starter_build_html_email_template(array(
                'title'  => __('虚拟商品发卡信息', 'qilingshop'),
                'intro'  => __('您的订单已完成发卡，以下是本次发放的卡号卡密，请妥善保管并尽快使用。', 'qilingshop'),
                'lines'  => $html_lines,
                'notice' => __('本邮件包含敏感信息，请勿转发给他人。', 'qilingshop'),
            ));
            if (is_string($html_message) && trim($html_message) !== '') {
                $message = $html_message;
                $headers = array('Content-Type: text/html; charset=UTF-8');
            }
        }

        $sent = wp_mail($to, $subject, $message, $headers);
        if (!$sent && function_exists('qilingshop_log')) {
            qilingshop_log('Send virtual card email failed', 'warning', array(
                'order_id' => (int) $order->id,
                'order_no' => (string) ($order->order_no ?? ''),
                'email'    => $to,
            ));
        }
    }

    /**
     * 从虚拟订单联系信息中提取邮箱地址。
     *
     * @param object $order
     * @return string
     */
    private function extract_virtual_contact_email($order) {
        if (empty($order)) {
            return '';
        }

        $remark = (string) ($order->seller_remark ?? '');
        if ($remark !== '') {
            $remark_data = json_decode($remark, true);
            if (is_array($remark_data) && !empty($remark_data['contact_email'])) {
                $saved_email = sanitize_email((string) $remark_data['contact_email']);
                if ($saved_email !== '') {
                    return $saved_email;
                }
            }
        }

        $address = (string) ($order->receiver_address ?? '');
        if ($address === '') {
            return '';
        }

        if (preg_match('/([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/i', $address, $matches)) {
            return sanitize_email((string) $matches[1]);
        }

        return '';
    }

    /**
     * 规范化游客订单查询手机号。
     *
     * @param string $value
     * @return string
     */
    private function normalize_guest_query_phone($value) {
        $digits = preg_replace('/\D+/', '', (string) $value);
        if ($digits === null) {
            $digits = '';
        }

        if (strpos($digits, '0086') === 0 && strlen($digits) > 4) {
            $digits = substr($digits, 4);
        } elseif (strpos($digits, '86') === 0 && strlen($digits) > 11) {
            $digits = substr($digits, 2);
        }

        return $digits;
    }

    /**
     * 规范化游客订单查询邮箱。
     *
     * @param string $value
     * @return string
     */
    private function normalize_guest_query_email($value) {
        $email = sanitize_email((string) $value);
        if ($email === '' || !is_email($email)) {
            return '';
        }

        return strtolower($email);
    }

    /**
     * 判断订单表是否已具备游客查询索引字段。
     *
     * @return bool
     */
    private function guest_query_lookup_columns_exist() {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }

        if (!$this->db || !method_exists($this->db, 'get_wpdb') || !method_exists($this->db, 'get_table')) {
            $exists = false;
            return $exists;
        }

        $wpdb = $this->db->get_wpdb();
        $table = $this->db->get_table('orders');
        if (!$wpdb || $table === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            $exists = false;
            return $exists;
        }

        foreach (['guest_query_phone', 'guest_query_email', 'guest_query_password_hash', 'guest_query_password_expires_at'] as $column) {
            $found = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", $column));
            if (empty($found)) {
                $exists = false;
                return $exists;
            }
        }

        $exists = true;
        return $exists;
    }

    /**
     * 获取游客查询密码有效天数。
     *
     * @return int
     */
    private function get_guest_query_password_expire_days() {
        return max(1, min(365, (int) get_option('qls_shop_guest_query_password_expire_days', 30)));
    }

    /**
     * 生成游客查询密码失效时间。
     *
     * @return string
     */
    private function build_guest_query_password_expires_at() {
        $seconds_per_day = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
        $timestamp = current_time('timestamp') + ($this->get_guest_query_password_expire_days() * $seconds_per_day);
        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * 判断订单是否已经扣减过库存。
     *
     * @param object $order
     * @return bool
     */
    private function order_stock_already_reduced($order) {
        if (empty($order)) {
            return false;
        }

        return (int) ($order->stock_reduced ?? 0) === 1 || (int) ($order->group_stock_reduced ?? 0) === 1;
    }

    /**
     * 写入订单库存扣减状态。
     *
     * @param int   $order_id
     * @param array $state
     * @return bool
     */
    private function update_order_stock_state($order_id, $state) {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return false;
        }

        return $this->db->update('orders', $state, ['id' => $order_id]) !== false;
    }

    /**
     * 库存账务场景下判断是否助力订单。
     *
     * @param object $order 订单对象。
     * @return bool
     */
    private function is_assist_order_for_stock($order) {
        if (empty($order->id) || !function_exists('qls_assist')) {
            return false;
        }

        $assist = qls_assist();
        if (!is_object($assist)) {
            return false;
        }

        return method_exists($assist, 'is_assist_order_for_stock')
            && (bool) $assist->is_assist_order_for_stock((int) $order->id);
    }

    /**
     * 扣减库存
     *
     * @param object $order
     */
    public function reduce_stock($order) {
        if ($this->is_assist_order_for_stock($order)) {
            return true;
        }

        if ($this->order_stock_already_reduced($order)) {
            return true;
        }

        $is_group_order = !empty($order->is_group_order) || !empty(qilingshop_get_group_order_data($order->id));
        if ($is_group_order && class_exists('QLS_Group')) {
            $rule_id = $this->get_group_rule_id_for_order($order);
            if ($rule_id <= 0) {
                return false;
            }
            $rule = qls_group()->get_rule($rule_id);
            if (!$rule) {
                return false;
            }

            if (!qls_group()->rule_uses_product_stock($rule)) {
                $quantity = 0;
                foreach ($order->items as $item) {
                    $quantity += intval($item->quantity);
                }
                if ($quantity <= 0) {
                    return true;
                }
                if (qls_group()->reduce_rule_stock($rule_id, $quantity)) {
                    $marked = $this->update_order_stock_state($order->id, [
                        'stock_reduced'        => 0,
                        'group_stock_reduced'  => 1,
                        'group_stock_rule_id'  => $rule_id,
                        'group_stock_quantity' => $quantity,
                    ]);
                    if (!$marked) {
                        qls_group()->restore_rule_stock($rule_id, $quantity);
                        return false;
                    }
                    return true;
                }
                return false;
            }
        }

        $reduced_items = [];
        $affected_sku_ids = [];
        foreach ($order->items as $item) {
            $result = qls_product()->update_sku_stock($item->sku_id, $item->quantity, 'reduce', false);
            if ($result === false) {
                foreach ($reduced_items as $reduced) {
                    qls_product()->update_sku_stock($reduced['sku_id'], $reduced['quantity'], 'add', false);
                }
                qls_product()->sync_product_stats_by_skus($affected_sku_ids);
                return false;
            }
            $reduced_items[] = [
                'sku_id' => $item->sku_id,
                'quantity' => $item->quantity,
            ];
            $affected_sku_ids[] = (int) $item->sku_id;
        }

        qls_product()->sync_product_stats_by_skus($affected_sku_ids);

        if (!$this->update_order_stock_state($order->id, [
            'stock_reduced'        => 1,
            'group_stock_reduced'  => 0,
            'group_stock_rule_id'  => 0,
            'group_stock_quantity' => 0,
        ])) {
            foreach ($reduced_items as $reduced) {
                qls_product()->update_sku_stock($reduced['sku_id'], $reduced['quantity'], 'add', false);
            }
            qls_product()->sync_product_stats_by_skus($affected_sku_ids);
            return false;
        }

        return true;
    }

    /**
     * 恢复库存
     *
     * @param object $order
     */
    public function restore_stock($order) {
        if ($this->is_assist_order_for_stock($order)) {
            return;
        }

        if ((int) ($order->group_stock_reduced ?? 0) === 1 && class_exists('QLS_Group')) {
            $rule_id = intval($order->group_stock_rule_id ?? 0);
            $quantity = intval($order->group_stock_quantity ?? 0);
            if ($rule_id > 0) {
                if ($quantity <= 0) {
                    $quantity = 0;
                    foreach ($order->items as $item) {
                        $quantity += intval($item->quantity);
                    }
                }
                if (!qls_group()->restore_rule_stock($rule_id, $quantity)) {
                    return;
                }
            }
            $this->update_order_stock_state($order->id, [
                'stock_reduced'        => 0,
                'group_stock_reduced'  => 0,
                'group_stock_rule_id'  => 0,
                'group_stock_quantity' => 0,
            ]);
            return;
        }

        if ((int) ($order->stock_reduced ?? 0) !== 1) {
            return;
        }

        $affected_sku_ids = [];
        $restored = true;
        foreach ($order->items as $item) {
            if (!qls_product()->update_sku_stock($item->sku_id, $item->quantity, 'add', false)) {
                $restored = false;
            }
            $affected_sku_ids[] = (int) $item->sku_id;
        }
        qls_product()->sync_product_stats_by_skus($affected_sku_ids);

        if ($restored) {
            $this->update_order_stock_state($order->id, [
                'stock_reduced'        => 0,
                'group_stock_reduced'  => 0,
                'group_stock_rule_id'  => 0,
                'group_stock_quantity' => 0,
            ]);
        }
    }

    /**
     * 订单发货
     *
     * @param int    $order_id
     * @param string $company     物流公司
     * @param string $tracking_no 物流单号
     * @return bool|WP_Error
     */
    public function ship($order_id, $company, $tracking_no) {
        $lock_name = $this->build_order_lock_name($order_id, 'ship');
        if (!$this->acquire_named_lock($lock_name, 5)) {
            return false;
        }

        try {
            $order = $this->get($order_id);
            
            if (!$order) {
                return false;
            }

            if ((int) $order->status === self::STATUS_SHIPPED) {
                return true;
            }

            if ((int) $order->status !== self::STATUS_PAID) {
                return false;
            }

            if (function_exists('qls_shipment')) {
                $shipment_result = qls_shipment()->create_full_shipment($order_id, $company, $tracking_no);
                if (is_wp_error($shipment_result)) {
                    if ($shipment_result->get_error_code() !== 'missing_table') {
                        return $shipment_result;
                    }
                } elseif ($shipment_result) {
                    return true;
                }
            }

            $result = $this->db->update('orders', [
                'status'           => self::STATUS_SHIPPED,
                'shipping_company' => $company,
                'tracking_no'      => $tracking_no,
                'shipped_at'       => current_time('mysql'),
            ], [
                'id'     => $order_id,
                'status' => self::STATUS_PAID,
            ]);

            if ((int) $result === 1) {
                do_action('qls_shop_order_shipped', $order_id, $company, $tracking_no);
                return true;
            }

            if ((int) $result === 0) {
                $latest = $this->get($order_id);
                return $latest && (int) $latest->status === self::STATUS_SHIPPED;
            }

            return false;
        } finally {
            $this->release_named_lock($lock_name);
        }
    }

    /**
     * 确认收货
     *
     * @param int $order_id
     * @return bool
     */
    public function complete($order_id) {
        $order = $this->get($order_id);
        
        if (!$order || $order->status != self::STATUS_SHIPPED) {
            return false;
        }

        return $this->update_status($order_id, self::STATUS_COMPLETED);
    }

    /**
     * 取消订单
     *
     * @param int    $order_id
     * @param string $reason
     * @return bool
     */
    public function cancel($order_id, $reason = '') {
        $lock_name = $this->build_order_lock_name($order_id, 'cancel');
        if (!$this->acquire_named_lock($lock_name, 5)) {
            return false;
        }

        try {
            $order = $this->get($order_id, true);
            
            if (!$order || $order->status != self::STATUS_PENDING) {
                return false;
            }

            // 待支付订单若已在下单环节扣过积分，取消时归还（幂等）
            if ($order->points_used > 0 && $order->user_id > 0 && class_exists('QilingShop_Points')) {
                $points_manager = QilingShop_Points::instance();
                if (!$points_manager->has_points_log((int) $order->user_id, 'shop_cancel_refund', (int) $order->id)) {
                    $refunded = $points_manager->add_points(
                        (int) $order->user_id,
                        (float) $order->points_used,
                        'shop_cancel_refund',
                        sprintf(__('订单取消退还：%s', 'qilingshop'), $order->order_no),
                        (int) $order->id
                    );
                    if (!$refunded) {
                        return false;
                    }
                }
            }

            // 恢复已预占的库存；未扣减过时 restore_stock 会幂等跳过。
            $this->restore_stock($order);

            // 待支付订单只释放本订单的优惠券预占。
            if (class_exists('QLS_Coupon') && !empty($order->seller_remark)) {
                $remark_data = json_decode($order->seller_remark, true);
                $coupon_claim_id = $remark_data['coupon_claim_id'] ?? 0;
                if ($coupon_claim_id > 0) {
                    QLS_Coupon::release_reservation($coupon_claim_id, (string) $order->order_no);
                }
            }

            $update_data = [
                'status'       => self::STATUS_CANCELLED,
                'cancelled_at' => current_time('mysql'),
            ];

            if ($reason) {
                $update_data['buyer_remark'] = $reason;
            }

            $result = $this->db->update('orders', $update_data, [
                'id'     => $order_id,
                'status' => self::STATUS_PENDING,
            ]);

            if ($result !== false && (int) $result === 1) {
                do_action('qls_shop_order_cancelled', $order_id, $reason);
                return true;
            }

            return false;
        } finally {
            $this->release_named_lock($lock_name);
        }
    }

    /**
     * 申请退款
     *
     * @param int    $order_id
     * @param string $reason
     * @return bool
     */
    public function apply_refund($order_id, $reason = '') {
        $lock_name = $this->build_order_lock_name($order_id, 'apply_refund');
        if (!$this->acquire_named_lock($lock_name, 5)) {
            return false;
        }

        try {
            $order = $this->get($order_id);
            
            if (!$order) {
                return false;
            }

            $current_status = (int) $order->status;
            if ($current_status === self::STATUS_REFUNDING) {
                return true;
            }

            if (!in_array($current_status, [self::STATUS_PAID, self::STATUS_SHIPPED, self::STATUS_COMPLETED], true)) {
                return false;
            }

            $update_data = ['status' => self::STATUS_REFUNDING];
            if ($reason) {
                $update_data['buyer_remark'] = $reason;
            }

            $result = $this->db->update('orders', $update_data, [
                'id'     => $order_id,
                'status' => $current_status,
            ]);

            if ((int) $result === 1) {
                do_action('qls_shop_order_refund_applied', $order_id, $reason);
                return true;
            }

            if ((int) $result === 0) {
                $latest = $this->get($order_id);
                return $latest && (int) $latest->status === self::STATUS_REFUNDING;
            }

            return false;
        } finally {
            $this->release_named_lock($lock_name);
        }
    }

    /**
     * 确认退款
     *
     * @param int $order_id
     * @return array|WP_Error
     */
    public function confirm_refund($order_id, $args = []) {
        $lock_name = $this->build_order_lock_name($order_id, 'confirm_refund');
        if (!$this->acquire_named_lock($lock_name, 5)) {
            return new WP_Error('refund_busy', __('退款处理中，请稍后重试', 'qilingshop'));
        }

        try {
            $args = wp_parse_args($args, [
                'emit_hook'   => true,
                'refund_id'   => 0,
                'refund_no'   => '',
                'refund_mode' => '',
            ]);

            $order = $this->get($order_id, true);
            if (!$order) {
                return new WP_Error('order_not_found', __('订单不存在', 'qilingshop'));
            }

            $context = $this->build_refund_execution_context($order, $args);
            $this->log_refund_debug('Refund execution started', 'info', $this->build_refund_log_context($context));

            if ((int) $order->status === self::STATUS_REFUNDED) {
                return array_merge($context, [
                    'success'         => true,
                    'already_refunded'=> true,
                    'local_finalized' => true,
                    'cash_refunded'   => true,
                    'message'         => __('订单已退款', 'qilingshop'),
                ]);
            }

            if ((int) $order->status !== self::STATUS_REFUNDING) {
                return new WP_Error('refund_invalid_order_status', __('当前订单状态无法退款', 'qilingshop'), $context);
            }

            $cash_result = $this->execute_cash_refund($order, $context);
            if (is_wp_error($cash_result)) {
                qilingshop_log('Refund cash execution failed: ' . $cash_result->get_error_message(), 'error', [
                    'order_id'    => (int) $order_id,
                    'refund_id'   => (int) $context['refund_id'],
                    'refund_mode' => $context['refund_mode'],
                    'gateway'     => $context['gateway'],
                ]);
                return $cash_result;
            }

            $finalize_result = $this->finalize_local_refund($order);
            if (is_wp_error($finalize_result)) {
                $error_data = array_merge($context, $cash_result, [
                    'cash_refunded'        => true,
                    'local_finalize_failed'=> true,
                ]);

                qilingshop_log('Refund finalize failed: ' . $finalize_result->get_error_message(), 'error', [
                    'order_id'    => (int) $order_id,
                    'refund_id'   => (int) $context['refund_id'],
                    'refund_mode' => $context['refund_mode'],
                    'gateway'     => $context['gateway'],
                ]);

                return new WP_Error(
                    'refund_finalize_failed',
                    __('退款资金已处理，但订单本地状态收尾失败，请先人工核对，勿重复退款', 'qilingshop'),
                    $error_data
                );
            }

            $result = array_merge($context, $cash_result, $finalize_result, [
                'success'         => true,
                'cash_refunded'   => true,
                'local_finalized' => true,
            ]);

            $this->log_refund_debug('Refund finalized successfully', 'info', $this->build_refund_log_context($result, [
                'gateway_status'    => sanitize_key((string) ($result['gateway_status'] ?? '')),
                'gateway_refund_no' => sanitize_text_field((string) ($result['gateway_refund_no'] ?? '')),
            ]));

            if (!empty($args['emit_hook'])) {
                do_action(
                    'qls_shop_order_refunded',
                    $order_id,
                    (int) ($context['refund_id'] ?? 0),
                    (string) ($result['refund_mode'] ?? ($context['refund_mode'] ?? '')),
                    [
                        'gateway_status'      => sanitize_key((string) ($result['gateway_status'] ?? '')),
                        'gateway_refund_no'   => sanitize_text_field((string) ($result['gateway_refund_no'] ?? '')),
                        'gateway_refunded_at' => sanitize_text_field((string) ($result['gateway_refunded_at'] ?? '')),
                        'refunded_amount'     => round((float) ($result['refunded_amount'] ?? $order->final_amount ?? 0), 2),
                    ]
                );
            }

            return $result;
        } finally {
            $this->release_named_lock($lock_name);
        }
    }

    /**
     * 解析商城退款模式。
     *
     * @param array $args 退款参数
     * @return string
     */
    private function resolve_refund_mode($args = []) {
        $mode = sanitize_key((string) ($args['refund_mode'] ?? ''));
        if (!in_array($mode, ['withdrawable_balance', 'gateway'], true)) {
            $mode = sanitize_key((string) get_option('qilingshop_shop_refund_mode', 'withdrawable_balance'));
        }

        if (!in_array($mode, ['withdrawable_balance', 'gateway'], true)) {
            $mode = 'withdrawable_balance';
        }

        return $mode;
    }

    /**
     * 构建退款执行上下文，供服务层记录流水。
     *
     * @param object $order 订单对象
     * @param array  $args  执行参数
     * @return array
     */
    private function build_refund_execution_context($order, $args = []) {
        $resolved_mode = $this->resolve_refund_mode($args);
        $payment_channel_meta = $order->payment_channel_meta ?? null;
        if (is_string($payment_channel_meta) && $payment_channel_meta !== '') {
            $decoded = json_decode($payment_channel_meta, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $payment_channel_meta = $decoded;
            }
        }

        return [
            'order_id'                 => (int) ($order->id ?? 0),
            'order_no'                 => (string) ($order->order_no ?? ''),
            'refund_id'                => (int) ($args['refund_id'] ?? 0),
            'refund_no'                => sanitize_text_field((string) ($args['refund_no'] ?? '')),
            'refund_mode'              => $resolved_mode,
            'configured_refund_mode'   => $resolved_mode,
            'payment_method'           => sanitize_key((string) ($order->payment_method ?? '')),
            'payment_no'               => sanitize_text_field((string) ($order->payment_no ?? '')),
            'payment_channel_version'  => sanitize_key((string) ($order->payment_channel_version ?? '')),
            'payment_channel_meta'     => $payment_channel_meta,
            'gateway'                  => $this->normalize_refund_gateway($order->payment_method ?? ''),
            'refunded_amount'          => round((float) ($order->final_amount ?? 0), 2),
        ];
    }

    /**
     * 执行退款资金处理。
     *
     * @param object $order   订单对象
     * @param array  $context 执行上下文
     * @return array|WP_Error
     */
    private function execute_cash_refund($order, $context) {
        if ($context['refund_mode'] === 'gateway') {
            return $this->execute_gateway_refund($order, $context);
        }

        return $this->refund_to_withdrawable_balance($order, $context);
    }

    /**
     * 退回可提现余额。
     *
     * @param object $order   订单对象
     * @param array  $context 执行上下文
     * @return array|WP_Error
     */
    private function refund_to_withdrawable_balance($order, $context) {
        $amount = round((float) ($order->final_amount ?? 0), 2);
        $is_gateway_fallback = !empty($context['gateway_fallback']);
        $fallback_message = __('当前支付方式不支持原路退款，已自动退回可提现余额', 'qilingshop');

        if ($amount <= 0 || (int) ($order->user_id ?? 0) <= 0) {
            return array_merge($context, [
                'success'         => true,
                'refund_mode'     => 'withdrawable_balance',
                'gateway_status'  => '',
                'gateway_refund_no' => '',
                'gateway_response'=> null,
                'message'         => $is_gateway_fallback ? $fallback_message : __('订单无需退回现金金额', 'qilingshop'),
                'refunded_amount' => $amount,
            ]);
        }

        if (!class_exists('QilingShop_Points')) {
            return new WP_Error(
                'refund_balance_service_missing',
                __('积分资产服务未加载，无法退回可提现余额', 'qilingshop'),
                $context
            );
        }

        $credited = QilingShop_Points::instance()->add_withdrawable_balance(
            (int) $order->user_id,
            $amount,
            'refund',
            sprintf(__('订单退款：%s', 'qilingshop'), $order->order_no),
            (int) $order->id
        );

        if (!$credited) {
            return new WP_Error(
                'refund_balance_failed',
                __('退款到可提现余额失败，请稍后重试', 'qilingshop'),
                $context
            );
        }

        $this->log_refund_debug('Refund credited to withdrawable balance', 'info', $this->build_refund_log_context($context, [
            'target' => 'withdrawable_balance',
        ]));

        return array_merge($context, [
            'success'           => true,
            'refund_mode'       => 'withdrawable_balance',
            'gateway_status'    => '',
            'gateway_refund_no' => '',
            'gateway_response'  => null,
            'message'           => $is_gateway_fallback ? $fallback_message : __('已退回可提现余额', 'qilingshop'),
            'refunded_amount'   => $amount,
        ]);
    }

    /**
     * 执行原路退款。
     *
     * @param object $order   订单对象
     * @param array  $context 执行上下文
     * @return array|WP_Error
     */
    private function execute_gateway_refund($order, $context) {
        if ((float) $context['refunded_amount'] <= 0) {
            return array_merge($context, [
                'success'             => true,
                'gateway_status'      => '',
                'gateway_refund_no'   => '',
                'gateway_response'    => null,
                'message'             => __('订单无需原路退回现金金额', 'qilingshop'),
                'gateway_refunded_at' => current_time('mysql'),
                'refunded_amount'     => 0,
            ]);
        }

        if ($context['gateway'] === '') {
            $fallback_context = $context;
            $fallback_context['gateway_fallback'] = true;
            $fallback_context['gateway_fallback_reason'] = 'gateway_not_supported';
            $fallback_context['refund_mode'] = 'withdrawable_balance';

            $this->log_refund_debug('Gateway refund not supported, auto fallback to withdrawable balance', 'warning', $this->build_refund_log_context($fallback_context, [
                'gateway_status' => 'fallback_to_withdrawable_balance',
            ]));

            $fallback_result = $this->refund_to_withdrawable_balance($order, $fallback_context);
            if (is_wp_error($fallback_result)) {
                return $fallback_result;
            }

            $fallback_result['gateway_status'] = 'fallback_to_withdrawable_balance';
            $fallback_result['gateway_response'] = [
                'fallback_reason' => 'gateway_not_supported',
            ];

            return $fallback_result;
        }

        $this->log_refund_debug('Gateway refund request started', 'info', $this->build_refund_log_context($context, [
            'gateway_status' => 'request_started',
        ]));

        $refund_params = [
            'order_id'                => (int) ($order->id ?? 0),
            'order_no'                => (string) ($order->order_no ?? ''),
            'payment_no'              => (string) ($order->payment_no ?? ''),
            'payment_method'          => $context['payment_method'],
            'payment_channel_version' => $context['payment_channel_version'],
            'payment_channel_meta'    => $context['payment_channel_meta'],
            'amount'                  => $context['refunded_amount'],
            'refund_id'               => (int) $context['refund_id'],
            'refund_no'               => (string) $context['refund_no'],
        ];

        if ($context['gateway'] === 'wechat_miniapp') {
            if (!class_exists('QilingShop_Miniapp')) {
                return new WP_Error(
                    'refund_gateway_service_missing',
                    __('微信小程序支付服务未加载，无法发起原路退款', 'qilingshop'),
                    $context
                );
            }

            $miniapp = QilingShop_Miniapp::instance();
            if (!is_object($miniapp) || !method_exists($miniapp, 'refund_wechat_miniapp_payment')) {
                return new WP_Error(
                    'refund_gateway_service_missing',
                    __('微信小程序支付服务未就绪，无法发起原路退款', 'qilingshop'),
                    $context
                );
            }

            $result = $miniapp->refund_wechat_miniapp_payment($order, array_merge($context, $refund_params));
        } else {
            if (!class_exists('QilingShop_Payment')) {
                return new WP_Error(
                    'refund_gateway_service_missing',
                    __('支付服务未加载，无法发起原路退款', 'qilingshop'),
                    $context
                );
            }

            $result = QilingShop_Payment::instance()->refund_payment($context['gateway'], $refund_params);
        }

        if (is_wp_error($result)) {
            $this->log_refund_debug('Gateway refund failed with WP_Error: ' . $result->get_error_message(), 'error', $this->build_refund_log_context($context, [
                'gateway_status'   => 'failed',
                'gateway_response' => $this->summarize_refund_gateway_payload($result->get_error_data()),
            ]));
            return $result;
        }

        if (empty($result['success'])) {
            $this->log_refund_debug('Gateway refund failed: ' . (string) ($result['message'] ?? __('原路退款失败', 'qilingshop')), 'error', $this->build_refund_log_context($context, [
                'gateway_status'    => sanitize_key((string) ($result['status'] ?? 'failed')),
                'gateway_refund_no' => sanitize_text_field((string) ($result['gateway_refund_no'] ?? '')),
                'gateway_response'  => $this->summarize_refund_gateway_payload($result['raw'] ?? $result),
            ]));
            return new WP_Error(
                'refund_gateway_failed',
                (string) ($result['message'] ?? __('原路退款失败', 'qilingshop')),
                array_merge($context, [
                    'gateway_status'    => sanitize_key((string) ($result['status'] ?? 'failed')),
                    'gateway_refund_no' => sanitize_text_field((string) ($result['gateway_refund_no'] ?? '')),
                    'gateway_response'  => $result['raw'] ?? $result,
                ])
            );
        }

        $this->log_refund_debug('Gateway refund succeeded', 'info', $this->build_refund_log_context($context, [
            'gateway_status'    => sanitize_key((string) ($result['status'] ?? 'success')),
            'gateway_refund_no' => sanitize_text_field((string) ($result['gateway_refund_no'] ?? '')),
            'gateway_response'  => $this->summarize_refund_gateway_payload($result['raw'] ?? $result),
        ]));

        return array_merge($context, [
            'success'           => true,
            'gateway_status'    => sanitize_key((string) ($result['status'] ?? 'success')),
            'gateway_refund_no' => sanitize_text_field((string) ($result['gateway_refund_no'] ?? '')),
            'gateway_response'  => $result['raw'] ?? $result,
            'gateway_refunded_at' => sanitize_text_field((string) ($result['gateway_refunded_at'] ?? '')),
            'message'           => (string) ($result['message'] ?? __('原路退款已提交', 'qilingshop')),
            'refunded_amount'   => round((float) ($result['refunded_amount'] ?? $context['refunded_amount']), 2),
        ]);
    }

    /**
     * 执行退款后的本地收尾。
     *
     * @param object $order 订单对象
     * @return array|WP_Error
     */
    private function finalize_local_refund($order) {
        try {
            // 恢复库存
            $this->restore_stock($order);

            // 撤回虚拟商品卡密（使其可重新使用）
            if (function_exists('qls_card_inventory')) {
                $inventory = qls_card_inventory();
                if (is_object($inventory) && method_exists($inventory, 'revoke_by_order')) {
                    $inventory->revoke_by_order((int) $order->id);
                }
            }

            // 退还积分（如果使用了积分）
            if ((float) ($order->points_used ?? 0) > 0 && (int) ($order->user_id ?? 0) > 0 && class_exists('QilingShop_Points')) {
                $points_refunded = QilingShop_Points::instance()->add_points(
                    (int) $order->user_id,
                    (float) $order->points_used,
                    'refund',
                    sprintf(__('订单退款：%s', 'qilingshop'), $order->order_no),
                    (int) $order->id
                );
                if (!$points_refunded) {
                    throw new Exception('Failed to refund points');
                }
            }

            $result = $this->update_status((int) $order->id, self::STATUS_REFUNDED);
            if (!$result) {
                throw new Exception('Failed to update order status');
            }

            return [
                'local_finalized' => true,
            ];
        } catch (Exception $e) {
            return new WP_Error('refund_local_finalize_failed', $e->getMessage());
        }
    }

    /**
     * 构建退款调试日志上下文。
     *
     * @param array $context
     * @param array $extra
     * @return array
     */
    private function build_refund_log_context($context, $extra = []) {
        $context = is_array($context) ? $context : [];
        $extra = is_array($extra) ? $extra : [];

        $log_context = [
            'order_id'                => (int) ($context['order_id'] ?? 0),
            'order_no'                => sanitize_text_field((string) ($context['order_no'] ?? '')),
            'refund_id'               => (int) ($context['refund_id'] ?? 0),
            'refund_no'               => sanitize_text_field((string) ($context['refund_no'] ?? '')),
            'refund_mode'             => sanitize_key((string) ($context['refund_mode'] ?? '')),
            'payment_method'          => sanitize_key((string) ($context['payment_method'] ?? '')),
            'payment_channel_version' => sanitize_key((string) ($context['payment_channel_version'] ?? '')),
            'gateway'                 => sanitize_key((string) ($context['gateway'] ?? '')),
            'refunded_amount'         => round((float) ($context['refunded_amount'] ?? 0), 2),
        ];

        if (array_key_exists('gateway_status', $extra)) {
            $log_context['gateway_status'] = sanitize_key((string) $extra['gateway_status']);
            unset($extra['gateway_status']);
        }

        if (array_key_exists('gateway_refund_no', $extra)) {
            $log_context['gateway_refund_no'] = sanitize_text_field((string) $extra['gateway_refund_no']);
            unset($extra['gateway_refund_no']);
        }

        if (array_key_exists('gateway_response', $extra)) {
            $log_context['gateway_response'] = $extra['gateway_response'];
            unset($extra['gateway_response']);
        }

        foreach ($extra as $key => $value) {
            $log_context[sanitize_key((string) $key)] = is_scalar($value) || $value === null
                ? $value
                : $this->summarize_refund_gateway_payload($value);
        }

        return $log_context;
    }

    /**
     * 提炼网关响应摘要，避免日志过大。
     *
     * @param mixed $payload
     * @return mixed
     */
    private function summarize_refund_gateway_payload($payload) {
        if ($payload instanceof WP_Error) {
            return [
                'code'    => $payload->get_error_code(),
                'message' => $payload->get_error_message(),
            ];
        }

        if (is_scalar($payload) || $payload === null) {
            $text = (string) $payload;
            return strlen($text) > 300 ? substr($text, 0, 300) . '...' : $text;
        }

        if (!is_array($payload)) {
            return gettype($payload);
        }

        $preferred_keys = [
            'code',
            'message',
            'status',
            'refund_id',
            'refund_status',
            'out_refund_no',
            'return_code',
            'return_msg',
            'result_code',
            'err_code',
            'err_code_des',
            'trade_no',
            'out_trade_no',
            'refund_fee',
            'version',
            'amount',
            'wechat',
        ];

        $summary = [];
        foreach ($preferred_keys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];
            if (is_array($value)) {
                $summary[$key] = array_slice($value, 0, 8, true);
            } elseif (is_scalar($value) || $value === null) {
                $text = (string) $value;
                $summary[$key] = strlen($text) > 200 ? substr($text, 0, 200) . '...' : $value;
            }
        }

        if (empty($summary)) {
            $summary = array_slice($payload, 0, 8, true);
        }

        return $summary;
    }

    /**
     * 输出退款调试日志。
     *
     * @param string $message
     * @param string $level
     * @param array  $context
     * @return void
     */
    private function log_refund_debug($message, $level = 'info', $context = []) {
        if (!function_exists('qilingshop_log')) {
            return;
        }

        qilingshop_log($message, $level, $context);
    }

    /**
     * 将订单支付方式映射为退款路由标识。
     *
     * @param string $payment_method 支付方式
     * @return string
     */
    private function normalize_refund_gateway($payment_method) {
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
     * 获取用户订单列表
     *
     * @param int   $user_id
     * @param array $args
     * @return array
     */
    public function get_user_orders($user_id, $args = []) {
        $defaults = [
            'status' => '',
            'limit'  => 20,
            'offset' => 0,
        ];

        $args = wp_parse_args($args, $defaults);
        $where = ['user_id' => $user_id];

        if ($args['status'] !== '') {
            $where['status'] = $args['status'];
        }

        $orders = $this->db->get_results('orders', [
            'where'   => $where,
            'orderby' => 'id',
            'order'   => 'DESC',
            'limit'   => $args['limit'],
            'offset'  => $args['offset'],
        ]);

        // 加载商品
        foreach ($orders as &$order) {
            $order->items = $this->get_items($order->id);
            if (function_exists('qls_shipment')) {
                $order->shipments = qls_shipment()->get_by_order((int) $order->id, true);
                $order->shipment_summary = qls_shipment()->get_order_shipment_summary((int) $order->id);
            } else {
                $order->shipments = [];
                $order->shipment_summary = [
                    'physical_quantity' => 0,
                    'shipped_quantity'  => 0,
                    'shipment_count'    => 0,
                ];
            }
        }

        return $orders;
    }

    /**
     * 获取用户虚拟商品订单列表（仅包含虚拟商品明细）。
     *
     * @param int   $user_id 用户ID
     * @param array $args    查询参数
     * @return array
     */
    public function get_user_virtual_orders($user_id, $args = []) {
        $defaults = [
            'limit'    => 20,
            'offset'   => 0,
            'statuses' => [
                self::STATUS_PAID,
                self::STATUS_SHIPPED,
                self::STATUS_COMPLETED,
            ],
        ];
        $args = wp_parse_args($args, $defaults);

        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return [];
        }

        $statuses = array_values(array_unique(array_map('intval', (array) $args['statuses'])));
        if (empty($statuses)) {
            return [];
        }

        $limit = max(1, (int) $args['limit']);
        $offset = max(0, (int) $args['offset']);

        $wpdb = $this->db->get_wpdb();
        $table_orders = $this->db->get_table('orders');
        $table_items = $this->db->get_table('order_items');
        $table_products = $this->db->get_table('products');

        $status_placeholders = implode(', ', array_fill(0, count($statuses), '%d'));
        $sql = "SELECT o.*
                FROM {$table_orders} o
                WHERE o.user_id = %d
                  AND o.status IN ({$status_placeholders})
                  AND EXISTS (
                      SELECT 1
                      FROM {$table_items} oi
                      LEFT JOIN {$table_products} p ON p.id = oi.product_id
                      WHERE oi.order_id = o.id
                        AND (
                            (oi.virtual_content IS NOT NULL AND oi.virtual_content <> '')
                            OR p.product_type = %s
                        )
                  )
                ORDER BY o.id DESC
                LIMIT %d OFFSET %d";

        $params = array_merge([$user_id], $statuses, ['virtual', $limit, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params));
        if (empty($rows)) {
            return [];
        }

        $orders = [];
        foreach ($rows as $row) {
            $items = $this->get_items((int) $row->id);
            $virtual_items = [];

            foreach ((array) $items as $item) {
                $has_virtual_content = is_array($item->virtual_content ?? null) && !empty($item->virtual_content);
                $is_virtual_product = !empty($item->product_id) && function_exists('qls_product') && qls_product()->is_virtual((int) $item->product_id);
                if ($has_virtual_content || $is_virtual_product) {
                    $virtual_items[] = $item;
                }
            }

            if (!empty($virtual_items)) {
                $row->items = $virtual_items;
                $orders[] = $row;
            }
        }

        return $orders;
    }

    /**
     * 获取用户虚拟商品订单数量。
     *
     * @param int   $user_id 用户ID
     * @param array $args    查询参数
     * @return int
     */
    public function get_user_virtual_orders_count($user_id, $args = []) {
        $defaults = [
            'statuses' => [
                self::STATUS_PAID,
                self::STATUS_SHIPPED,
                self::STATUS_COMPLETED,
            ],
        ];
        $args = wp_parse_args($args, $defaults);

        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return 0;
        }

        $statuses = array_values(array_unique(array_map('intval', (array) $args['statuses'])));
        if (empty($statuses)) {
            return 0;
        }

        $wpdb = $this->db->get_wpdb();
        $table_orders = $this->db->get_table('orders');
        $table_items = $this->db->get_table('order_items');
        $table_products = $this->db->get_table('products');

        $status_placeholders = implode(', ', array_fill(0, count($statuses), '%d'));
        $sql = "SELECT COUNT(DISTINCT o.id)
                FROM {$table_orders} o
                WHERE o.user_id = %d
                  AND o.status IN ({$status_placeholders})
                  AND EXISTS (
                      SELECT 1
                      FROM {$table_items} oi
                      LEFT JOIN {$table_products} p ON p.id = oi.product_id
                      WHERE oi.order_id = o.id
                        AND (
                            (oi.virtual_content IS NOT NULL AND oi.virtual_content <> '')
                            OR p.product_type = %s
                        )
                  )";
        $params = array_merge([$user_id], $statuses, ['virtual']);

        return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
    }

    /**
     * 获取用户订单数量
     *
     * @param int   $user_id
     * @param array $where
     * @return int
     */
    public function get_user_orders_count($user_id, $where = []) {
        $where['user_id'] = $user_id;
        return $this->db->count('orders', $where);
    }

    /**
     * 获取订单状态文本
     *
     * @param int $status
     * @return string
     */
    public function get_status_text($status) {
        $statuses = [
            self::STATUS_PENDING   => __('待付款', 'qilingshop'),
            self::STATUS_PAID      => __('已付款', 'qilingshop'),
            self::STATUS_SHIPPED   => __('已发货', 'qilingshop'),
            self::STATUS_COMPLETED => __('已完成', 'qilingshop'),
            self::STATUS_CANCELLED => __('已取消', 'qilingshop'),
            self::STATUS_REFUNDING => __('退款中', 'qilingshop'),
            self::STATUS_REFUNDED  => __('已退款', 'qilingshop'),
        ];

        return isset($statuses[$status]) ? $statuses[$status] : __('未知', 'qilingshop');
    }

    /**
     * 获取订单状态Badge样式
     *
     * @param int $status
     * @return string
     */
    public function get_status_badge_class($status) {
        $classes = [
            self::STATUS_PENDING   => 'warning',
            self::STATUS_PAID      => 'info',
            self::STATUS_SHIPPED   => 'primary',
            self::STATUS_COMPLETED => 'success',
            self::STATUS_CANCELLED => 'secondary',
            self::STATUS_REFUNDING => 'danger',
            self::STATUS_REFUNDED  => 'dark',
        ];

        return isset($classes[$status]) ? $classes[$status] : 'secondary';
    }

    /**
     * 生成订单号
     *
     * @return string
     */
    private function generate_order_no($prefix = 'SHOP') {
        $max_attempts = 10;
        
        for ($i = 0; $i < $max_attempts; $i++) {
            $date = date('YmdHis');
            $random = mt_rand(1000, 9999);
            $order_no = $prefix . $date . $random;
            
            // 检查是否重复
            $exists = $this->db->get_row('orders', ['order_no' => $order_no]);
            if (!$exists) {
                return $order_no;
            }
        }
        
        // 如果多次尝试仍重复，添加微秒级随机数
        return $prefix . date('YmdHis') . mt_rand(100000, 999999);
    }

    private function get_group_rule_id_for_order($order) {
        if (empty($order)) {
            return 0;
        }

        if (!empty($order->group_id) && class_exists('QLS_Group')) {
            $group = qls_group()->get_group($order->group_id);
            if ($group && !empty($group->rule_id)) {
                return intval($group->rule_id);
            }
        }

        $group_info = qilingshop_get_group_order_data($order->id);
        if (!empty($group_info['rule_id'])) {
            return intval($group_info['rule_id']);
        }

        return 0;
    }

    /**
     * 获取客户端IP
     *
     * @return string
     */
    private function get_client_ip() {
        if (function_exists('qilingshop_security')) {
            return qilingshop_security()->get_client_ip();
        }

        $remote_addr = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
        return filter_var($remote_addr, FILTER_VALIDATE_IP) ? $remote_addr : '0.0.0.0';
    }

    /**
     * 检查用户是否已有新人专项订单（含待支付、已支付与售后状态）
     *
     * @param int $user_id
     * @return bool
     */
    public function has_new_user_special_order($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return false;
        }

        $wpdb = $this->db->get_wpdb();
        if (!$wpdb) {
            return false;
        }

        $table = $this->db->get_table('orders');
        $status_sql = implode(',', array_map('intval', [
            self::STATUS_PENDING,
            self::STATUS_PAID,
            self::STATUS_SHIPPED,
            self::STATUS_COMPLETED,
            self::STATUS_REFUNDING,
            self::STATUS_REFUNDED,
        ]));

        $sql = $wpdb->prepare(
            "SELECT 1
             FROM `{$table}`
             WHERE user_id = %d
               AND status IN ({$status_sql})
               AND seller_remark LIKE %s
             LIMIT 1",
            $user_id,
            '%"new_user_special_applied":1%'
        );

        return (bool) $wpdb->get_var($sql);
    }

    /**
     * 从购物车创建订单
     *
     * @param array $checkout_data 结账数据
     * @return array
     */
    public function create_from_cart($checkout_data) {
        $lock_name = $this->build_checkout_lock_name();
        if (!$this->acquire_named_lock($lock_name, 5)) {
            return ['success' => false, 'message' => __('结账处理中，请勿重复提交', 'qilingshop')];
        }

        $cart = qls_cart();
        $cart_locked = false;
        try {
        if (!$cart->begin_checkout_lock()) {
            return ['success' => false, 'message' => __('购物车处理中，请稍后重试', 'qilingshop')];
        }
        $cart_locked = true;
        $items = $cart->get_items();
        $current_user_id = (int) get_current_user_id();

        if (empty($items)) {
            return ['success' => false, 'message' => __('购物车为空', 'qilingshop')];
        }

        // 统一后端重算单价并校验新人专项约束，避免依赖前端传值。
        $new_user_special_qty = 0;
        $new_user_special_lines = 0;
        $new_user_special_product_ids = [];
        $vip_price_lines = 0;
        foreach ($items as $item) {
            if (!empty($item->is_invalid) || (int) $item->quantity < 1) {
                return ['success' => false, 'message' => __('购物车包含无效商品或异常数量，请刷新后重试', 'qilingshop')];
            }

            $effective_price = qls_product()->get_effective_sku_price(
                $item->product ?? (int) $item->product_id,
                $item->sku ?? (int) $item->sku_id,
                $current_user_id
            );
            $item->price = round((float) ($effective_price['price'] ?? 0), 2);
            $item->base_price = (float) ($effective_price['base_price'] ?? $item->price);
            $item->is_vip_price = !empty($effective_price['is_vip_price']);
            $item->vip_price = (float) ($effective_price['vip_price'] ?? 0);
            $item->vip_level_id = (int) ($effective_price['vip_level_id'] ?? 0);
            $item->price_source = isset($effective_price['price_source']) ? (string) $effective_price['price_source'] : 'base';
            if (!is_finite($item->price) || $item->price < 0) {
                return ['success' => false, 'message' => __('商品价格异常，请联系管理员', 'qilingshop')];
            }
            $item->subtotal = $item->price * (int) $item->quantity;

            if (!empty($effective_price['is_vip_price'])) {
                $vip_price_lines++;
            }

            if (!empty($effective_price['is_new_user_special'])) {
                $new_user_special_lines++;
                $new_user_special_qty += max(0, (int) $item->quantity);
                $new_user_special_product_ids[] = (int) $item->product_id;
            }
        }

        if ($new_user_special_lines > 0) {
            if ($current_user_id <= 0) {
                return ['success' => false, 'message' => __('新人专项商品请先登录后购买', 'qilingshop')];
            }
            if (!qls_product()->is_user_eligible_for_new_user_special($current_user_id)) {
                return ['success' => false, 'message' => __('当前账号不满足新人专项资格', 'qilingshop')];
            }
            if ($new_user_special_lines > 1 || $new_user_special_qty > 1) {
                return ['success' => false, 'message' => __('新人专项商品仅限购1件', 'qilingshop')];
            }
            if ($this->has_new_user_special_order($current_user_id)) {
                return ['success' => false, 'message' => __('已有新人专项订单待处理，暂不可重复使用新人价', 'qilingshop')];
            }
        }

        // 验证库存
        $validation = $cart->validate_stock($items);
        if (!$validation['valid']) {
            return ['success' => false, 'message' => __('部分商品库存不足', 'qilingshop'), 'errors' => $validation['errors']];
        }

        // 计算金额
        $totals = $cart->calculate_totals($items);
        if ($totals['invalid_count'] > 0 || $totals['total_quantity'] < 1 || $totals['total_amount'] < 0) {
            return ['success' => false, 'message' => __('购物车金额或数量异常，请刷新后重试', 'qilingshop')];
        }
        
        // 计算运费
        $shipping_fee = qls_shipping()->calculate($items, $checkout_data);

        // 处理优惠券
        $discount_amount = 0;
        $coupon_claim_id = isset($checkout_data['coupon_claim_id']) ? intval($checkout_data['coupon_claim_id']) : 0;
        
        if ($coupon_claim_id > 0 && class_exists('QLS_Coupon')) {
            // 优惠券领取记录属于登录用户，游客不可直接提交 claim_id 使用
            if ($current_user_id <= 0) {
                $coupon_claim_id = 0;
            }

            $coupon_manager = QLS_Coupon::instance();
            if ($coupon_claim_id > 0) {
                // 验证优惠券
                $product_ids = array_values(array_unique(array_map(function($item) {
                    return (int) $item->product_id;
                }, $items)));
                $validation = $coupon_manager->validate($coupon_claim_id, 'shop', $totals['total_amount'], $product_ids, $current_user_id);
                if ($validation['valid']) {
                    $discount_base = $this->get_coupon_eligible_subtotal($items, $validation['coupon']);
                    $validation = $coupon_manager->validate($coupon_claim_id, 'shop', $discount_base, $product_ids, $current_user_id);
                    if ($validation['valid'] && $discount_base > 0) {
                        $discount_amount = $coupon_manager->calculate_discount($coupon_claim_id, $discount_base);
                    } else {
                        $coupon_claim_id = 0;
                    }
                } else {
                    // 无效/越权 claim_id 直接忽略，避免被利用
                    $coupon_claim_id = 0;
                }
            }
        }

        $points_enabled = (bool) get_option('qls_shop_points_enabled', true);
        $points_rate = max(0.01, (float) get_option('qls_shop_points_rate', 10));
        $payment_method = sanitize_key((string) ($checkout_data['payment_method'] ?? ''));
        $is_points_payment = ($payment_method === 'points');
        if ($is_points_payment) {
            // 积分支付不叠加现金网关优惠券，避免完整扣积分后仍核销优惠券。
            $coupon_claim_id = 0;
            $discount_amount = 0;
        }
        $points_used = max(0, round((float) ($checkout_data['points_used'] ?? 0), 2));
        $max_deductible_amount = max(0, (float) $totals['total_amount'] + (float) $shipping_fee - (float) $discount_amount);

        if ($is_points_payment) {
            $expected_points_total = 0;
            foreach ($items as $item) {
                $points_price = isset($item->sku->points_price) ? (float) $item->sku->points_price : 0;
                if ($points_price <= 0) {
                return ['success' => false, 'message' => __('当前商品不支持积分支付', 'qilingshop')];
                }
                $expected_points_total += $points_price * (int) $item->quantity;
            }
            $points_used = max(0, round($expected_points_total, 2));
        }

        if ($points_used > 0) {
            if (!$points_enabled) {
                return ['success' => false, 'message' => __('积分支付未开启', 'qilingshop')];
            }

            if (!is_user_logged_in()) {
                return ['success' => false, 'message' => __('请先登录后使用积分', 'qilingshop')];
            }

            if (!$is_points_payment && $max_deductible_amount <= 0) {
                return ['success' => false, 'message' => __('当前订单无需积分抵扣', 'qilingshop')];
            }

            if (!$is_points_payment) {
                $max_points_deductible = floor($max_deductible_amount * $points_rate);
                if ($points_used > $max_points_deductible) {
                    return ['success' => false, 'message' => __('积分抵扣超出订单可抵扣上限', 'qilingshop')];
                }
            }
        }

        $points_deduction = $is_points_payment
            ? $max_deductible_amount
            : round($points_used / $points_rate, 2);
        $calculated_amount = $totals['total_amount'] + $shipping_fee - $points_deduction - $discount_amount;
        if ($calculated_amount < -0.00001) {
            return ['success' => false, 'message' => __('订单金额计算异常，请重新结账', 'qilingshop')];
        }
        $final_amount = round(max(0, $calculated_amount), 2);

        $points_deducted_in_order_transaction = false;

        $this->db->begin_transaction();

        try {
            // 创建订单
            $seller_remark_data = ['coupon_claim_id' => $coupon_claim_id];
            $contact_email = isset($checkout_data['receiver_email']) ? sanitize_email((string) $checkout_data['receiver_email']) : '';
            if ($contact_email !== '') {
                $seller_remark_data['contact_email'] = $contact_email;
            }
            $guest_query_password = isset($checkout_data['guest_query_password']) ? trim((string) $checkout_data['guest_query_password']) : '';
            $guest_query_password_hash = '';
            if ($current_user_id <= 0 && $guest_query_password !== '') {
                $guest_query_password_hash = wp_hash_password($guest_query_password);
            }
            if ($new_user_special_lines > 0) {
                $seller_remark_data['new_user_special_applied'] = 1;
                $seller_remark_data['new_user_special_qty'] = (int) $new_user_special_qty;
                $seller_remark_data['new_user_special_product_ids'] = array_values(array_unique(array_map('intval', $new_user_special_product_ids)));
            }
            if ($vip_price_lines > 0) {
                $seller_remark_data['vip_price_applied'] = 1;
                $seller_remark_data['vip_price_lines'] = (int) $vip_price_lines;
            }

            $order_data = [
                'user_id'           => get_current_user_id(),
                'total_amount'      => $totals['total_amount'],
                'shipping_fee'      => $shipping_fee,
                'discount_amount'   => $discount_amount,
                'points_used'       => $points_used,
                'final_amount'      => $final_amount,
                'receiver_name'     => $checkout_data['receiver_name'],
                'receiver_phone'    => $checkout_data['receiver_phone'],
                'receiver_province' => $checkout_data['receiver_province'] ?? '',
                'receiver_city'     => $checkout_data['receiver_city'] ?? '',
                'receiver_district' => $checkout_data['receiver_district'] ?? '',
                'receiver_address'  => $checkout_data['receiver_address'],
                'buyer_remark'      => $checkout_data['buyer_remark'] ?? '',
                'payment_method'    => $checkout_data['payment_method'] ?? '',
                'seller_remark'     => wp_json_encode($seller_remark_data),
            ];

            if ($guest_query_password_hash !== '') {
                if (!$this->guest_query_lookup_columns_exist()) {
                    throw new Exception(__('订单查询密码存储结构未初始化，请稍后重试', 'qilingshop'));
                }

                $order_data['guest_query_phone'] = $this->normalize_guest_query_phone((string) ($checkout_data['receiver_phone'] ?? ''));
                $order_data['guest_query_email'] = $this->normalize_guest_query_email($contact_email);
                $order_data['guest_query_password_hash'] = $guest_query_password_hash;
                $order_data['guest_query_password_expires_at'] = $this->build_guest_query_password_expires_at();
            }

            $order_no = $this->create($order_data);

            if (!$order_no) {
                throw new Exception(__('创建订单失败', 'qilingshop'));
            }

            $order = $this->get_by_order_no($order_no);
            if (!$order || empty($order->id)) {
                throw new Exception(__('订单读取失败', 'qilingshop'));
            }

            if ($coupon_claim_id > 0 && $discount_amount > 0 && class_exists('QLS_Coupon')) {
                $coupon_reserved = QLS_Coupon::reserve_for_order(
                    $coupon_claim_id,
                    $order_no,
                    'shop',
                    $totals['total_amount'],
                    $discount_amount,
                    false
                );

                if (!$coupon_reserved) {
                    throw new Exception(__('优惠券已被使用或占用，请重新选择优惠券', 'qilingshop'));
                }
            }

            // 添加订单商品
            $order_items = [];
            foreach ($items as $item) {
                $order_items[] = [
                    'product_id'    => $item->product_id,
                    'sku_id'        => $item->sku_id,
                    'product_title' => $item->product->title,
                    'sku_attrs'     => $item->sku->attr_values ?? [],
                    'image'         => is_array($item->product->main_image) ? ($item->product->main_image['url'] ?? '') : $item->product->main_image,
                    'price'         => $item->price,
                    'quantity'      => $item->quantity,
                ];
            }
            if (!$this->add_items($order->id, $order_items)) {
                throw new Exception(__('订单商品写入失败', 'qilingshop'));
            }

            // 扣减库存（如果配置为下单时扣减）
            $reduce_on = get_option('qls_shop_stock_reduce_on', 'order');
            if ($reduce_on === 'order') {
                $order_with_items = $this->get($order->id, true);
                if ($order_with_items && !$this->reduce_stock($order_with_items)) {
                    throw new Exception(__('库存扣减失败', 'qilingshop'));
                }
            }

            // 扣减积分（如果使用了积分）
            if ($points_used > 0) {
                if (class_exists('QilingShop_Points')) {
                    $deducted = QilingShop_Points::instance()->deduct_points(
                        get_current_user_id(),
                        $points_used,
                        'shop_order',
                        sprintf(__('商城购物：%s', 'qilingshop'), $order_no),
                        $order->id,
                        false
                    );

                    if (!$deducted) {
                        throw new Exception(__('积分扣除失败', 'qilingshop'));
                    }
                    $points_deducted_in_order_transaction = true;
                }
            }

            // 优惠券在下单事务中预占，支付成功后的 mark_paid 再确认核销。
            // coupon_claim_id 已保存在订单的 seller_remark 中。

            // 清空购物车
            if (!$cart->clear()) {
                throw new Exception(__('购物车清空失败，请稍后重试', 'qilingshop'));
            }

            $this->db->commit();
            if ($points_deducted_in_order_transaction && class_exists('QilingShop_Points')) {
                $points_manager = QilingShop_Points::instance();
                $current_user_id = get_current_user_id();
                $points_manager->clear_user_cache($current_user_id);
                do_action('qilingshop_after_deduct_points', $current_user_id, $points_used, 'shop_order', $points_manager->get_balance($current_user_id));
            }

            return [
                'success'  => true,
                'message'  => __('订单创建成功', 'qilingshop'),
                'order_no' => $order_no,
                'order_id' => $order->id,
            ];

        } catch (Exception $e) {
            $this->db->rollback();

            return ['success' => false, 'message' => $e->getMessage()];
        }
        } finally {
            if ($cart_locked) {
                $cart->end_checkout_lock();
            }
            $this->release_named_lock($lock_name);
        }
    }

    /**
     * 计算优惠券实际可覆盖的商品小计。
     */
    private function get_coupon_eligible_subtotal($items, $coupon) {
        if (!$coupon) {
            return 0.0;
        }

        $apply_items = array_map('intval', (array) ($coupon->apply_items ?? []));
        $apply_categories = array_map('intval', (array) ($coupon->apply_categories ?? []));
        $subtotal = 0.0;

        foreach ((array) $items as $item) {
            $product_id = (int) ($item->product_id ?? 0);
            if ($apply_items && !in_array($product_id, $apply_items, true)) {
                continue;
            }

            $category_id = (int) ($item->product->category_id ?? 0);
            if ($apply_categories && !in_array($category_id, $apply_categories, true)) {
                continue;
            }

            $subtotal += (float) ($item->subtotal ?? 0);
        }

        return round(max(0, $subtotal), 2);
    }
    /**
     * 删除订单
     *
     * @param int $order_id
     * @return bool
     */
    public function delete($order_id) {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return false;
        }

        $lock_name = $this->build_order_lock_name($order_id, 'delete');
        if (!$this->acquire_named_lock($lock_name, 5)) {
            return false;
        }

        try {
            $order = $this->get($order_id, true);
            
            if (!$order) {
                return false;
            }

            // 如果订单未完成/取消/退款，先尝试恢复已扣减的库存。
            if ($order->status == self::STATUS_PENDING || $order->status == self::STATUS_PAID) {
                 $this->restore_stock($order);
                 
                 // 如果已使用积分，退还积分
                 if ($order->points_used > 0 && $order->user_id > 0 && class_exists('QilingShop_Points')) {
                    $points_manager = QilingShop_Points::instance();
                    if (!$points_manager->has_points_log((int) $order->user_id, 'shop_delete_refund', (int) $order->id)) {
                        $points_refunded = $points_manager->add_points(
                            $order->user_id,
                            $order->points_used,
                            'shop_delete_refund',
                            sprintf(__('订单删除退还：%s', 'qilingshop'), $order->order_no),
                            $order->id
                        );
                        if (!$points_refunded) {
                            return false;
                        }
                    }
                }
            }

            $this->db->begin_transaction();
            try {
                $this->delete_order_relations($order_id);
                $result = $this->db->delete('orders', ['id' => $order_id]);
                if ($result === false || (int) $result !== 1) {
                    throw new Exception(__('订单删除失败', 'qilingshop'));
                }
                $this->db->commit();
            } catch (Exception $e) {
                $this->db->rollback();
                if (function_exists('qilingshop_log')) {
                    qilingshop_log('Shop order deletion failed: ' . $e->getMessage(), 'error');
                }
                return false;
            }

            if ($result) {
                do_action('qls_shop_order_deleted', $order_id);
            }

            return $result;
        } finally {
            $this->release_named_lock($lock_name);
        }
    }

    /**
     * 删除订单的业务关联数据。
     *
     * 优惠券使用记录和商品评价属于独立权益/内容历史，不随订单清理删除。
     *
     * @param int $order_id
     * @throws Exception 任一删除失败时抛出异常并由调用方回滚。
     */
    private function delete_order_relations($order_id) {
        $order_id = (int) $order_id;
        $relations = [
            'waybill_logs',
            'shipment_items',
            'shipments',
            'invoices',
            'refund_logs',
            'refunds',
            'order_meta',
            'order_items',
        ];

        $wpdb = $this->db->get_wpdb();
        $ticket_messages = $this->db->get_table('ticket_messages');
        $tickets = $this->db->get_table('tickets');
        $messages_deleted = $wpdb->query($wpdb->prepare(
            "DELETE tm
             FROM {$ticket_messages} tm
             INNER JOIN {$tickets} t ON t.id = tm.ticket_id
             WHERE t.order_id = %d",
            $order_id
        ));
        if ($messages_deleted === false) {
            throw new Exception(__('订单工单消息清理失败', 'qilingshop'));
        }

        if ($this->db->delete('tickets', ['order_id' => $order_id]) === false) {
            throw new Exception(__('订单工单清理失败', 'qilingshop'));
        }

        foreach ($relations as $table) {
            if ($this->db->delete($table, ['order_id' => $order_id]) === false) {
                throw new Exception(sprintf(__('订单关联数据清理失败：%s', 'qilingshop'), $table));
            }
        }
    }

    /**
     * 自动取消超时未支付的订单
     * 
     * 由外部任务中心触发，检查所有待付款订单，
     * 如超过设置的小时数则自动取消并恢复库存
     *
     * @return int 取消的订单数量
     */
    public function auto_cancel_expired_orders() {
        $hours = intval(get_option('qls_shop_order_auto_cancel_hours', 24));
        
        // 如果设置为0或负数，表示禁用自动取消
        if ($hours <= 0) {
            return 0;
        }
        
        global $wpdb;
        $table = $this->db->get_table('orders');
        $cutoff_time = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));
        
        // 查询超时的待付款订单
        $orders = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$table} WHERE status = %d AND created_at < %s LIMIT 100",
            self::STATUS_PENDING,
            $cutoff_time
        ));
        
        $count = 0;
        foreach ($orders as $order) {
            if ($this->cancel($order->id, __('系统超时自动取消', 'qilingshop'))) {
                $count++;
            }
        }
        
        // 记录日志（可选）
        if ($count > 0 && function_exists('qilingshop_log')) {
            qilingshop_log(sprintf('Auto-cancelled %d expired orders', $count), 'info');
        }
        
        return $count;
    }

    /**
     * 清理未付款订单（手动删除）
     * 
     * @deprecated 建议使用 auto_cancel_expired_orders() 自动取消
     * @return int 删除数量
     */
    public function cleanup_unpaid_orders() {
        global $wpdb;

        $table = $this->db->get_table('orders');
        $cutoff_time = date('Y-m-d H:i:s', current_time('timestamp') - self::MANUAL_PENDING_CLEANUP_GRACE_PERIOD);
        $orders = $wpdb->get_results($wpdb->prepare(
            "SELECT id
             FROM {$table}
             WHERE status = %d
               AND created_at < %s
             ORDER BY id ASC
             LIMIT 500",
            self::STATUS_PENDING,
            $cutoff_time
        ));

        $count = 0;
        foreach ($orders as $order) {
            if ($this->delete($order->id)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 按终态批量清理订单。
     *
     * 仅允许清理已完成或已取消订单，避免误删仍在支付、发货或退款流程中的订单。
     * 每次最多处理 500 条，防止单次后台请求长时间占用数据库连接。
     *
     * @param int $status 订单状态。
     * @return int 删除数量。
     */
    public function cleanup_orders_by_status($status) {
        global $wpdb;

        $status = (int) $status;
        $allowed_statuses = [self::STATUS_COMPLETED, self::STATUS_CANCELLED];
        if (!in_array($status, $allowed_statuses, true)) {
            return 0;
        }

        $table = $this->db->get_table('orders');
        $orders = $wpdb->get_results($wpdb->prepare(
            "SELECT id
             FROM {$table}
             WHERE status = %d
             ORDER BY id ASC
             LIMIT 500",
            $status
        ));

        $count = 0;
        foreach ((array) $orders as $order) {
            if ($this->delete((int) $order->id)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 清理已取消订单。
     *
     * @return int
     */
    public function cleanup_cancelled_orders() {
        return $this->cleanup_orders_by_status(self::STATUS_CANCELLED);
    }

    /**
     * 清理已完成订单。
     *
     * @return int
     */
    public function cleanup_completed_orders() {
        return $this->cleanup_orders_by_status(self::STATUS_COMPLETED);
    }

    /**
     * 获取快递公司列表
     *
     * @return array
     */
    public function get_shipping_companies() {
        if (function_exists('qls_shipping_company')) {
            $companies = qls_shipping_company()->enabled();
            if (!empty($companies)) {
                return array_map(function($company) {
                    return [
                        'id'             => (int) ($company->id ?? 0),
                        'name'           => (string) ($company->name ?? ''),
                        'code'           => (string) ($company->code ?? ''),
                        'aliases'        => (array) ($company->aliases ?? []),
                        'phone_required' => (int) ($company->phone_required ?? 0),
                        'is_default'     => (int) ($company->is_default ?? 0),
                        'status'         => (int) ($company->status ?? 1),
                        'sort_order'     => (int) ($company->sort_order ?? 0),
                    ];
                }, $companies);
            }
        }

        return $this->get_legacy_shipping_companies();
    }

    /**
     * 获取旧版 option 物流公司列表。
     *
     * @return array
     */
    private function get_legacy_shipping_companies() {
        $defaults = [
            ['name' => '顺丰速运', 'code' => 'SF', 'sort_order' => 1],
            ['name' => '圆通速递', 'code' => 'YTO', 'sort_order' => 2],
            ['name' => '中通快递', 'code' => 'ZTO', 'sort_order' => 3],
            ['name' => '韵达快递', 'code' => 'YD', 'sort_order' => 4],
            ['name' => '申通快递', 'code' => 'STO', 'sort_order' => 5],
            ['name' => '邮政EMS', 'code' => 'EMS', 'sort_order' => 6],
            ['name' => '京东快递', 'code' => 'JD', 'sort_order' => 7],
            ['name' => '极兔速递', 'code' => 'J&T', 'sort_order' => 8],
            ['name' => '其他', 'code' => 'OTHER', 'sort_order' => 99],
        ];

        $companies = get_option('qls_shop_shipping_companies', $defaults);
        if (!is_array($companies)) {
            $companies = $defaults;
        }

        usort($companies, function($a, $b) {
            $order_a = isset($a['sort_order']) ? (int) $a['sort_order'] : 0;
            $order_b = isset($b['sort_order']) ? (int) $b['sort_order'] : 0;
            if ($order_a === $order_b) {
                return 0;
            }
            return ($order_a < $order_b) ? -1 : 1;
        });

        return $companies;
    }

}

/**
 * 获取订单类实例的快捷函数
 */
function qls_shop_order() {
    return QLS_Shop_Order::instance();
}
