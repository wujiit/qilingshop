<?php
/**
 * 商城订单发货单服务。
 *
 * @package QilingShop
 * @since   2.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_Shipment {

    const STATUS_SHIPPED   = 1;
    const STATUS_CANCELLED = 2;

    const ORDER_SHIPMENT_NONE    = 0;
    const ORDER_SHIPMENT_PARTIAL = 1;
    const ORDER_SHIPMENT_FULL    = 2;

    /**
     * 单例实例。
     *
     * @var QLS_Shipment|null
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
    private $shipments_table;
    private $items_table;

    /**
     * 获取单例实例。
     *
     * @return QLS_Shipment
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        $this->db = QLS_Shop_Database::instance();
        $this->shipments_table = $this->db->get_table('shipments');
        $this->items_table = $this->db->get_table('shipment_items');
    }

    /**
     * 创建发货单。
     *
     * @param int   $order_id
     * @param array $shipment_data
     * @param array $items 发货明细：order_item_id => quantity，或包含 order_item_id/quantity 的数组。
     * @return int|WP_Error
     */
    public function create($order_id, $shipment_data, $items) {
        $order_id = (int) $order_id;
        $shipment_data = is_array($shipment_data) ? $shipment_data : [];
        $items = $this->normalize_requested_items($items);

        if ($order_id <= 0 || empty($items)) {
            return new WP_Error('invalid_shipment_items', __('请选择要发货的商品', 'qilingshop'));
        }

        if (!$this->tables_exist()) {
            return new WP_Error('missing_table', __('发货单数据表不存在', 'qilingshop'));
        }

        $shipping_company_name = sanitize_text_field($shipment_data['shipping_company'] ?? '');
        $tracking_no = sanitize_text_field($shipment_data['tracking_no'] ?? '');
        $waybill_no = sanitize_text_field($shipment_data['waybill_no'] ?? '');
        if ($shipping_company_name === '') {
            return new WP_Error('missing_shipping_company', __('请选择物流公司', 'qilingshop'));
        }
        if ($tracking_no === '' && $waybill_no === '' && empty($shipment_data['allow_empty_tracking'])) {
            return new WP_Error('missing_tracking_no', __('请填写物流单号', 'qilingshop'));
        }

        $this->db->begin_transaction();

        try {
            $order = $this->get_order_for_update($order_id);
            if (!$order) {
                throw new Exception(__('订单不存在', 'qilingshop'));
            }

            if ((int) $order->status !== QLS_Shop_Order::STATUS_PAID) {
                throw new Exception(__('当前订单状态不可发货', 'qilingshop'));
            }

            $shipment_items = [];
            foreach ($items as $order_item_id => $quantity) {
                $item = $this->get_order_item_for_update($order_item_id, $order_id);
                if (!$item) {
                    throw new Exception(__('订单商品不存在', 'qilingshop'));
                }

                if ($this->is_order_item_virtual($item)) {
                    throw new Exception(__('虚拟商品不需要物流发货', 'qilingshop'));
                }

                $quantity = (int) $quantity;
                $remaining = max(0, (int) $item->quantity - (int) ($item->shipped_quantity ?? 0));
                if ($quantity <= 0 || $quantity > $remaining) {
                    throw new Exception(sprintf(__('商品「%s」发货数量不正确', 'qilingshop'), $item->product_title));
                }

                $shipment_items[] = [
                    'row'      => $item,
                    'quantity' => $quantity,
                ];
            }

            $company = $this->resolve_company($shipping_company_name, $shipment_data['shipping_company_id'] ?? 0);
            if ($company && !empty($company->name)) {
                $shipping_company_name = (string) $company->name;
            }
            $shipment_no = $this->generate_shipment_no($order);
            $now = current_time('mysql');

            $insert_id = $this->db->insert('shipments', [
                'shipment_no'         => $shipment_no,
                'order_id'            => $order_id,
                'order_no'            => (string) $order->order_no,
                'user_id'             => (int) $order->user_id,
                'shipping_company_id' => (int) ($company->id ?? 0),
                'shipping_company'    => $shipping_company_name !== '' ? $shipping_company_name : sanitize_text_field($company->name ?? ''),
                'shipping_code'       => sanitize_text_field($shipment_data['shipping_code'] ?? ($company->code ?? '')),
                'tracking_no'         => $tracking_no,
                'waybill_no'          => $waybill_no,
                'status'              => self::STATUS_SHIPPED,
                'receiver_name'       => (string) ($order->receiver_name ?? ''),
                'receiver_phone'      => (string) ($order->receiver_phone ?? ''),
                'receiver_province'   => (string) ($order->receiver_province ?? ''),
                'receiver_city'       => (string) ($order->receiver_city ?? ''),
                'receiver_district'   => (string) ($order->receiver_district ?? ''),
                'receiver_address'    => (string) ($order->receiver_address ?? ''),
                'sender_snapshot'     => !empty($shipment_data['sender_snapshot']) ? wp_json_encode($shipment_data['sender_snapshot']) : null,
                'admin_id'            => isset($shipment_data['admin_id']) ? (int) $shipment_data['admin_id'] : get_current_user_id(),
                'remark'              => isset($shipment_data['remark']) ? sanitize_textarea_field($shipment_data['remark']) : null,
                'shipped_at'          => !empty($shipment_data['shipped_at']) ? sanitize_text_field($shipment_data['shipped_at']) : $now,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);

            if (!$insert_id) {
                throw new Exception(__('发货单创建失败', 'qilingshop'));
            }

            foreach ($shipment_items as $entry) {
                $item = $entry['row'];
                $quantity = (int) $entry['quantity'];

                $detail_id = $this->db->insert('shipment_items', [
                    'shipment_id'    => (int) $insert_id,
                    'order_id'       => $order_id,
                    'order_item_id'  => (int) $item->id,
                    'product_id'     => (int) $item->product_id,
                    'sku_id'         => (int) $item->sku_id,
                    'product_title'  => (string) $item->product_title,
                    'sku_attrs'      => (string) ($item->sku_attrs ?? ''),
                    'quantity'       => $quantity,
                    'created_at'     => $now,
                ]);

                if (!$detail_id) {
                    throw new Exception(__('发货明细保存失败', 'qilingshop'));
                }

                $updated = $this->db->update('order_items', [
                    'shipped_quantity' => (int) ($item->shipped_quantity ?? 0) + $quantity,
                ], [
                    'id'               => (int) $item->id,
                    'shipped_quantity' => (int) ($item->shipped_quantity ?? 0),
                ]);

                if ($updated === false || (int) $updated !== 1) {
                    throw new Exception(__('发货数量更新失败，请重试', 'qilingshop'));
                }
            }

            $this->sync_order_shipment_state($order_id, $order);

            $this->db->commit();
            do_action('qls_shop_shipment_created', (int) $insert_id, $order_id);

            return (int) $insert_id;
        } catch (Exception $e) {
            $this->db->rollback();
            return new WP_Error('shipment_create_failed', $e->getMessage());
        }
    }

    /**
     * 按旧整单发货语义创建“剩余全部实体商品”的发货单。
     *
     * @param int    $order_id
     * @param string $company
     * @param string $tracking_no
     * @param array  $extra
     * @return int|WP_Error
     */
    public function create_full_shipment($order_id, $company, $tracking_no, $extra = []) {
        $extra = is_array($extra) ? $extra : [];
        $items = $this->get_unshipped_items($order_id);
        $shipment_items = [];
        foreach ($items as $item) {
            $shipment_items[(int) $item->id] = (int) $item->unshipped_quantity;
        }

        if (empty($shipment_items)) {
            return new WP_Error('no_physical_items', __('该订单没有需要发货的实体商品', 'qilingshop'));
        }

        $data = wp_parse_args($extra, [
            'shipping_company' => $company,
            'tracking_no'      => $tracking_no,
        ]);

        return $this->create($order_id, $data, $shipment_items);
    }

    /**
     * 获取发货单。
     *
     * @param int  $shipment_id
     * @param bool $with_items
     * @return object|null
     */
    public function get($shipment_id, $with_items = false) {
        $shipment_id = (int) $shipment_id;
        if ($shipment_id <= 0 || !$this->tables_exist()) {
            return null;
        }

        $shipment = $this->db->get_by_id('shipments', $shipment_id);
        if (!$shipment) {
            return null;
        }

        $shipment = $this->parse_shipment($shipment);
        if ($with_items) {
            $shipment->items = $this->get_items($shipment_id);
        }

        return $shipment;
    }

    /**
     * 获取订单发货单列表。
     *
     * @param int  $order_id
     * @param bool $with_items
     * @return array
     */
    public function get_by_order($order_id, $with_items = false) {
        $order_id = (int) $order_id;
        if ($order_id <= 0 || !$this->tables_exist()) {
            return [];
        }

        $rows = $this->db->get_results('shipments', [
            'where'   => ['order_id' => $order_id],
            'orderby' => 'id',
            'order'   => 'ASC',
        ]);

        return array_map(function($shipment) use ($with_items) {
            $shipment = $this->parse_shipment($shipment);
            if ($with_items) {
                $shipment->items = $this->get_items((int) $shipment->id);
            }

            return $shipment;
        }, $rows);
    }

    /**
     * 获取发货单明细。
     *
     * @param int $shipment_id
     * @return array
     */
    public function get_items($shipment_id) {
        $shipment_id = (int) $shipment_id;
        if ($shipment_id <= 0 || !$this->tables_exist()) {
            return [];
        }

        $items = $this->db->get_results('shipment_items', [
            'where'   => ['shipment_id' => $shipment_id],
            'orderby' => 'id',
            'order'   => 'ASC',
        ]);

        return array_map([$this, 'parse_item'], $items);
    }

    /**
     * 获取订单未发货的实体商品。
     *
     * @param int $order_id
     * @return array
     */
    public function get_unshipped_items($order_id) {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return [];
        }

        $items = qls_shop_order()->get_items($order_id);
        $remaining = [];
        foreach ($items as $item) {
            if ($this->is_order_item_virtual($item)) {
                continue;
            }

            $unshipped = max(0, (int) $item->quantity - (int) ($item->shipped_quantity ?? 0));
            if ($unshipped <= 0) {
                continue;
            }

            $item->unshipped_quantity = $unshipped;
            $remaining[] = $item;
        }

        return $remaining;
    }

    /**
     * 同步订单发货状态。
     *
     * @param int         $order_id
     * @param object|null $locked_order 事务内已锁定的订单。
     * @return bool
     */
    public function sync_order_shipment_state($order_id, $locked_order = null) {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return false;
        }

        $summary = $this->get_order_shipment_summary($order_id);
        $latest = $this->get_latest_shipment($order_id);
        $shipment_status = self::ORDER_SHIPMENT_NONE;
        if ($summary['shipped_quantity'] > 0 && $summary['shipped_quantity'] < $summary['physical_quantity']) {
            $shipment_status = self::ORDER_SHIPMENT_PARTIAL;
        } elseif ($summary['physical_quantity'] > 0 && $summary['shipped_quantity'] >= $summary['physical_quantity']) {
            $shipment_status = self::ORDER_SHIPMENT_FULL;
        }

        $order = $locked_order ?: qls_shop_order()->get($order_id);
        if (!$order) {
            return false;
        }

        $data = [
            'shipment_status' => $shipment_status,
            'shipment_count'  => $summary['shipment_count'],
        ];

        if ($latest) {
            $data['shipping_company'] = (string) $latest->shipping_company;
            $data['tracking_no'] = (string) $latest->tracking_no;
        }

        if ($shipment_status === self::ORDER_SHIPMENT_FULL && (int) $order->status === QLS_Shop_Order::STATUS_PAID) {
            $data['status'] = QLS_Shop_Order::STATUS_SHIPPED;
            $data['shipped_at'] = current_time('mysql');
        }

        $updated = $this->db->update('orders', $data, ['id' => $order_id]);
        if ($updated !== false && isset($data['status']) && (int) $order->status !== QLS_Shop_Order::STATUS_SHIPPED) {
            do_action('qls_shop_order_status_changed', $order_id, QLS_Shop_Order::STATUS_SHIPPED, (int) $order->status);
            do_action('qls_shop_order_status_' . QLS_Shop_Order::STATUS_SHIPPED, $order_id);
            do_action('qls_shop_order_shipped', $order_id, (string) ($data['shipping_company'] ?? ''), (string) ($data['tracking_no'] ?? ''));
        }

        return $updated !== false;
    }

    /**
     * 获取订单发货汇总。
     *
     * @param int $order_id
     * @return array
     */
    public function get_order_shipment_summary($order_id) {
        $order_id = (int) $order_id;
        $summary = [
            'physical_quantity' => 0,
            'shipped_quantity'  => 0,
            'shipment_count'    => 0,
        ];

        if ($order_id <= 0) {
            return $summary;
        }

        $summaries = $this->get_order_shipment_summaries([$order_id]);
        return isset($summaries[$order_id]) ? $summaries[$order_id] : $summary;
    }

    /**
     * 批量获取订单发货汇总，减少后台列表页逐单统计。
     *
     * @param int[]      $order_ids
     * @param array|null $order_items_map
     * @return array<int, array>
     */
    public function get_order_shipment_summaries($order_ids, $order_items_map = null) {
        $order_ids = array_values(array_unique(array_filter(array_map('intval', (array) $order_ids))));
        if (empty($order_ids)) {
            return [];
        }

        $summaries = [];
        foreach ($order_ids as $order_id) {
            $summaries[$order_id] = [
                'physical_quantity' => 0,
                'shipped_quantity'  => 0,
                'shipment_count'    => 0,
            ];
        }

        if (!is_array($order_items_map)) {
            $order_items_map = qls_shop_order()->get_items_by_orders($order_ids);
        }

        $product_type_map = $this->get_product_type_map_from_items($order_items_map);

        foreach ($order_ids as $order_id) {
            $items = isset($order_items_map[$order_id]) && is_array($order_items_map[$order_id]) ? $order_items_map[$order_id] : [];
            foreach ($items as $item) {
                if ($this->is_virtual_order_item_from_map($item, $product_type_map)) {
                    continue;
                }

                $summaries[$order_id]['physical_quantity'] += (int) ($item->quantity ?? 0);
                $summaries[$order_id]['shipped_quantity'] += min(
                    (int) ($item->quantity ?? 0),
                    (int) ($item->shipped_quantity ?? 0)
                );
            }
        }

        if ($this->tables_exist()) {
            $wpdb = $this->db->get_wpdb();
            $placeholders = implode(', ', array_fill(0, count($order_ids), '%d'));
            $params = array_merge([self::STATUS_SHIPPED], $order_ids);
            $sql = "SELECT order_id, COUNT(*) AS shipment_count
                    FROM {$this->shipments_table}
                    WHERE status = %d AND order_id IN ({$placeholders})
                    GROUP BY order_id";
            $rows = $wpdb->get_results($wpdb->prepare($sql, $params));

            foreach ((array) $rows as $row) {
                $row_order_id = (int) ($row->order_id ?? 0);
                if (isset($summaries[$row_order_id])) {
                    $summaries[$row_order_id]['shipment_count'] = (int) ($row->shipment_count ?? 0);
                }
            }
        }

        return $summaries;
    }

    /**
     * 为历史整单发货记录生成发货单。
     *
     * @param int $limit
     * @return int|WP_Error
     */
    public function backfill_legacy_order_shipments($limit = 500) {
        if (!$this->tables_exist()) {
            return new WP_Error('missing_table', __('发货单数据表不存在', 'qilingshop'));
        }

        $wpdb = $this->db->get_wpdb();
        $orders_table = $this->db->get_table('orders');
        $limit = max(1, min(1000, (int) $limit));

        $orders = $wpdb->get_results($wpdb->prepare(
            "SELECT o.*
             FROM {$orders_table} AS o
             WHERE o.status IN (%d, %d)
               AND o.tracking_no IS NOT NULL
               AND o.tracking_no <> ''
               AND NOT EXISTS (
                   SELECT 1 FROM {$this->shipments_table} AS s WHERE s.order_id = o.id LIMIT 1
               )
             ORDER BY o.id ASC
             LIMIT %d",
            QLS_Shop_Order::STATUS_SHIPPED,
            QLS_Shop_Order::STATUS_COMPLETED,
            $limit
        ));

        $created = 0;
        foreach ($orders as $order) {
            $items = $this->get_physical_order_items((int) $order->id);
            if (empty($items)) {
                continue;
            }

            $shipment_id = $this->insert_legacy_shipment($order, $items);
            if ($shipment_id) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * 判断发货表是否存在。
     *
     * @return bool
     */
    private function tables_exist() {
        $wpdb = $this->db->get_wpdb();
        $shipments_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->shipments_table)) === $this->shipments_table;
        $items_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->items_table)) === $this->items_table;

        return $shipments_exists && $items_exists;
    }

    /**
     * 事务内锁定订单。
     *
     * @param int $order_id
     * @return object|null
     */
    private function get_order_for_update($order_id) {
        $wpdb = $this->db->get_wpdb();
        $table = $this->db->get_table('orders');

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d LIMIT 1 FOR UPDATE",
            (int) $order_id
        ));
    }

    /**
     * 事务内锁定订单商品。
     *
     * @param int $order_item_id
     * @param int $order_id
     * @return object|null
     */
    private function get_order_item_for_update($order_item_id, $order_id) {
        $wpdb = $this->db->get_wpdb();
        $table = $this->db->get_table('order_items');

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND order_id = %d LIMIT 1 FOR UPDATE",
            (int) $order_item_id,
            (int) $order_id
        ));
    }

    /**
     * 获取实体订单商品。
     *
     * @param int $order_id
     * @return array
     */
    private function get_physical_order_items($order_id) {
        $items = qls_shop_order()->get_items($order_id);
        return array_values(array_filter($items, function($item) {
            return !$this->is_order_item_virtual($item);
        }));
    }

    /**
     * 历史订单发货单补齐。
     *
     * @param object $order
     * @param array  $items
     * @return int
     */
    private function insert_legacy_shipment($order, $items) {
        $now = current_time('mysql');
        $shipment_id = $this->db->insert('shipments', [
            'shipment_no'       => $this->generate_shipment_no($order, true),
            'order_id'          => (int) $order->id,
            'order_no'          => (string) $order->order_no,
            'user_id'           => (int) $order->user_id,
            'shipping_company'  => (string) $order->shipping_company,
            'tracking_no'       => (string) $order->tracking_no,
            'status'            => self::STATUS_SHIPPED,
            'receiver_name'     => (string) ($order->receiver_name ?? ''),
            'receiver_phone'    => (string) ($order->receiver_phone ?? ''),
            'receiver_province' => (string) ($order->receiver_province ?? ''),
            'receiver_city'     => (string) ($order->receiver_city ?? ''),
            'receiver_district' => (string) ($order->receiver_district ?? ''),
            'receiver_address'  => (string) ($order->receiver_address ?? ''),
            'shipped_at'        => !empty($order->shipped_at) ? (string) $order->shipped_at : $now,
            'created_at'        => !empty($order->shipped_at) ? (string) $order->shipped_at : $now,
            'updated_at'        => $now,
        ]);

        if (!$shipment_id) {
            return 0;
        }

        foreach ($items as $item) {
            $quantity = (int) $item->quantity;
            $this->db->insert('shipment_items', [
                'shipment_id'   => (int) $shipment_id,
                'order_id'      => (int) $order->id,
                'order_item_id' => (int) $item->id,
                'product_id'    => (int) $item->product_id,
                'sku_id'        => (int) $item->sku_id,
                'product_title' => (string) $item->product_title,
                'sku_attrs'     => is_array($item->sku_attrs ?? null) ? wp_json_encode($item->sku_attrs) : (string) ($item->sku_attrs ?? ''),
                'quantity'      => $quantity,
                'created_at'    => !empty($order->shipped_at) ? (string) $order->shipped_at : $now,
            ]);

            $this->db->update('order_items', [
                'shipped_quantity' => $quantity,
            ], [
                'id' => (int) $item->id,
            ]);
        }

        $this->db->update('orders', [
            'shipment_status' => self::ORDER_SHIPMENT_FULL,
            'shipment_count'  => 1,
        ], [
            'id' => (int) $order->id,
        ]);

        return (int) $shipment_id;
    }

    /**
     * 标准化发货明细。
     *
     * @param array $items
     * @return array
     */
    private function normalize_requested_items($items) {
        $normalized = [];
        foreach ((array) $items as $key => $value) {
            if (is_array($value)) {
                $order_item_id = (int) ($value['order_item_id'] ?? $value['id'] ?? 0);
                $quantity = (int) ($value['quantity'] ?? 0);
            } else {
                $order_item_id = (int) $key;
                $quantity = (int) $value;
            }

            if ($order_item_id > 0 && $quantity > 0) {
                $normalized[$order_item_id] = $quantity;
            }
        }

        return $normalized;
    }

    /**
     * 为批量发货汇总预加载商品类型。
     *
     * @param array $order_items_map
     * @return array<int, string>
     */
    private function get_product_type_map_from_items($order_items_map) {
        $product_ids = [];
        foreach ((array) $order_items_map as $items) {
            foreach ((array) $items as $item) {
                $product_id = (int) ($item->product_id ?? 0);
                if ($product_id > 0) {
                    $product_ids[$product_id] = $product_id;
                }
            }
        }

        if (empty($product_ids)) {
            return [];
        }

        $wpdb = $this->db->get_wpdb();
        $table = $this->db->get_table('products');
        $product_ids = array_values($product_ids);
        $placeholders = implode(', ', array_fill(0, count($product_ids), '%d'));
        $sql = "SELECT id, product_type FROM {$table} WHERE id IN ({$placeholders})";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $product_ids));

        $map = [];
        foreach ((array) $rows as $row) {
            $map[(int) $row->id] = (string) ($row->product_type ?? '');
        }

        return $map;
    }

    /**
     * 批量汇总时使用商品类型映射判断虚拟商品，避免逐条取商品详情。
     *
     * @param object $item
     * @param array  $product_type_map
     * @return bool
     */
    private function is_virtual_order_item_from_map($item, $product_type_map) {
        if (!empty($item->has_virtual_content)) {
            return true;
        }

        $virtual_content = $item->virtual_content ?? null;
        if (is_array($virtual_content) && !empty($virtual_content)) {
            return true;
        }

        if (is_string($virtual_content) && trim($virtual_content) !== '') {
            return true;
        }

        $product_id = (int) ($item->product_id ?? 0);
        return $product_id > 0
            && isset($product_type_map[$product_id])
            && $product_type_map[$product_id] === 'virtual';
    }

    /**
     * 匹配物流公司。
     *
     * @param string $company_name
     * @param int    $company_id
     * @return object|null
     */
    private function resolve_company($company_name, $company_id = 0) {
        if (function_exists('qls_shipping_company')) {
            if ((int) $company_id > 0) {
                $company = qls_shipping_company()->get((int) $company_id);
                if ($company) {
                    return $company;
                }
            }

            $company = qls_shipping_company()->find($company_name);
            if ($company) {
                return $company;
            }
        }

        return null;
    }

    /**
     * 判断订单明细是否为虚拟商品。
     *
     * @param object $item
     * @return bool
     */
    private function is_order_item_virtual($item) {
        if (!$item || empty($item->product_id)) {
            return false;
        }

        return function_exists('qls_product') && qls_product()->is_virtual((int) $item->product_id);
    }

    /**
     * 获取最近一张发货单。
     *
     * @param int $order_id
     * @return object|null
     */
    private function get_latest_shipment($order_id) {
        if (!$this->tables_exist()) {
            return null;
        }

        $rows = $this->db->get_results('shipments', [
            'where'   => [
                'order_id' => (int) $order_id,
                'status'   => self::STATUS_SHIPPED,
            ],
            'orderby' => 'id',
            'order'   => 'DESC',
            'limit'   => 1,
        ]);

        return !empty($rows) ? $this->parse_shipment($rows[0]) : null;
    }

    /**
     * 生成发货单号。
     *
     * @param object $order
     * @param bool   $legacy
     * @return string
     */
    private function generate_shipment_no($order, $legacy = false) {
        $base = preg_replace('/[^A-Za-z0-9]/', '', (string) ($order->order_no ?? ''));
        if ($legacy && $base !== '') {
            $suffix = (string) (int) ($order->id ?? 0);
            return 'SHP' . substr($base, 0, max(1, 46 - strlen($suffix))) . $suffix;
        }

        return 'SHP' . date('YmdHis', current_time('timestamp')) . wp_rand(1000, 9999);
    }

    /**
     * 解析发货单。
     *
     * @param object $shipment
     * @return object
     */
    private function parse_shipment($shipment) {
        if (!empty($shipment->sender_snapshot) && is_string($shipment->sender_snapshot)) {
            $decoded = json_decode($shipment->sender_snapshot, true);
            $shipment->sender_snapshot = is_array($decoded) ? $decoded : [];
        } else {
            $shipment->sender_snapshot = [];
        }

        foreach (['id', 'order_id', 'user_id', 'shipping_company_id', 'status', 'admin_id'] as $field) {
            if (isset($shipment->{$field})) {
                $shipment->{$field} = (int) $shipment->{$field};
            }
        }

        return $shipment;
    }

    /**
     * 解析发货明细。
     *
     * @param object $item
     * @return object
     */
    private function parse_item($item) {
        if (!empty($item->sku_attrs) && is_string($item->sku_attrs)) {
            $decoded = json_decode($item->sku_attrs, true);
            $item->sku_attrs = is_array($decoded) ? $decoded : $item->sku_attrs;
        }

        foreach (['id', 'shipment_id', 'order_id', 'order_item_id', 'product_id', 'sku_id', 'quantity'] as $field) {
            if (isset($item->{$field})) {
                $item->{$field} = (int) $item->{$field};
            }
        }

        return $item;
    }
}

/**
 * 获取发货单服务实例。
 *
 * @return QLS_Shipment
 */
function qls_shipment() {
    return QLS_Shipment::instance();
}
