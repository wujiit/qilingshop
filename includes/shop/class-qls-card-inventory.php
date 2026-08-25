<?php
/**
 * 卡密库存管理类
 * 
 * 处理虚拟商品卡密的导入、分配、撤回等操作
 *
 * @package QilingShop
 * @since   2.0.3
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_Card_Inventory {

    /**
     * 单例实例
     */
    private static $instance = null;

    /**
     * 数据库操作实例
     */
    private $db;

    /**
     * 卡密状态常量
     */
    const STATUS_AVAILABLE = 0;  // 可用
    const STATUS_SOLD = 1;       // 已售出
    const STATUS_REVOKED = 2;    // 已撤回（不可再次销售）

    /**
     * 获取单例实例
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 构造函数
     */
    private function __construct() {
        $this->db = QLS_Shop_Database::instance();
    }

    /**
     * 批量导入卡密
     *
     * @param int   $product_id 商品ID
     * @param int   $sku_id     SKU ID
     * @param array $cards      卡密数组 [['card_no' => '', 'card_secret' => ''], ...]
     * @return array ['success' => int, 'failed' => int, 'duplicates' => int]
     */
    public function import_batch($product_id, $sku_id, $cards) {
        $result = [
            'success'    => 0,
            'failed'     => 0,
            'duplicates' => 0,
        ];

        if (empty($cards) || !is_array($cards)) {
            return $result;
        }

        foreach ($cards as $card) {
            $card_no = trim($card['card_no'] ?? '');
            $card_secret = trim($card['card_secret'] ?? '');

            if (empty($card_no)) {
                $result['failed']++;
                continue;
            }

            // 检查是否已存在（同商品同SKU下的相同卡号）
            if ($this->exists($product_id, $sku_id, $card_no)) {
                $result['duplicates']++;
                continue;
            }

            $inserted = $this->db->insert('card_inventory', [
                'product_id'  => $product_id,
                'sku_id'      => $sku_id,
                'card_no'     => $card_no,
                'card_secret' => $card_secret,
                'status'      => self::STATUS_AVAILABLE,
            ]);

            if ($inserted) {
                $result['success']++;
            } else {
                $last_error = (string) $this->db->get_last_error();
                if (stripos($last_error, 'Duplicate entry') !== false) {
                    $result['duplicates']++;
                } else {
                    $result['failed']++;
                }
            }
        }

        return $result;
    }

    /**
     * 检查卡号是否已存在
     *
     * @param int    $product_id
     * @param int    $sku_id
     * @param string $card_no
     * @return bool
     */
    public function exists($product_id, $sku_id, $card_no) {
        global $wpdb;
        $table = $this->db->get_table('card_inventory');
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE product_id = %d AND sku_id = %d AND card_no = %s",
            $product_id,
            $sku_id,
            $card_no
        ));

        return $count > 0;
    }

    /**
     * 分配卡密给订单（FIFO 先进先出）
     *
     * @param int $sku_id        SKU ID
     * @param int $order_id      订单ID
     * @param int $order_item_id 订单明细ID
     * @param int  $quantity        需要分配的数量
     * @param bool $use_transaction 是否在本方法内开启事务
     * @return array|false 分配的卡密数组，失败返回 false
     */
    public function allocate($sku_id, $order_id, $order_item_id, $quantity = 1, $use_transaction = true) {
        global $wpdb;
        $table = $this->db->get_table('card_inventory');

        $quantity = (int) $quantity;
        $sku_id = absint($sku_id);
        $order_id = absint($order_id);
        $order_item_id = absint($order_item_id);

        if ($quantity < 1 || $sku_id <= 0 || $order_id <= 0 || $order_item_id <= 0) {
            return false;
        }

        if ($use_transaction) {
            $wpdb->query('START TRANSACTION');
        }

        try {
            // 获取可用卡密（按创建时间升序，FIFO）
            $cards = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} 
                 WHERE sku_id = %d AND status = %d 
                 ORDER BY created_at ASC, id ASC 
                 LIMIT %d 
                 FOR UPDATE",
                $sku_id,
                self::STATUS_AVAILABLE,
                $quantity
            ));

            if (empty($cards) || count($cards) < $quantity) {
                if ($use_transaction) {
                    $wpdb->query('ROLLBACK');
                }
                return false; // 库存不足
            }

            $allocated = [];
            $now = current_time('mysql');

            foreach ($cards as $card) {
                $updated = $wpdb->update(
                    $table,
                    [
                        'status'        => self::STATUS_SOLD,
                        'order_id'      => $order_id,
                        'order_item_id' => $order_item_id,
                        'sold_at'       => $now,
                    ],
                    [
                        'id'     => $card->id,
                        'status' => self::STATUS_AVAILABLE,
                    ],
                    ['%d', '%d', '%d', '%s'],
                    ['%d', '%d']
                );

                if ($updated !== 1) {
                    if ($use_transaction) {
                        $wpdb->query('ROLLBACK');
                    }
                    return false;
                }

                $allocated[] = [
                    'id'          => $card->id,
                    'card_no'     => $card->card_no,
                    'card_secret' => $card->card_secret,
                ];
            }

            if ($use_transaction) {
                $wpdb->query('COMMIT');
            }

            return $allocated;
        } catch (Exception $e) {
            if ($use_transaction) {
                $wpdb->query('ROLLBACK');
            }

            qilingshop_log('Allocate card inventory failed: ' . $e->getMessage(), 'error', [
                'sku_id'        => $sku_id,
                'order_id'      => $order_id,
                'order_item_id' => $order_item_id,
                'quantity'      => $quantity,
            ]);

            return false;
        }
    }

    /**
     * 永久撤销已交付卡密，避免退款后再次销售已泄露的密钥。
     *
     * @param int $order_id 订单ID
     * @return int 撤回的卡密数量
     */
    public function revoke_by_order($order_id) {
        global $wpdb;
        $table = $this->db->get_table('card_inventory');

        $updated = $wpdb->update(
            $table,
            [
                'status' => self::STATUS_REVOKED,
            ],
            [
                'order_id' => $order_id,
                'status'   => self::STATUS_SOLD,
            ],
            ['%d'],
            ['%d', '%d']
        );

        return $updated !== false ? $updated : 0;
    }

    /**
     * 永久撤销订单明细分配的卡密
     *
     * @param int $order_item_id 订单明细ID
     * @return int 撤回的卡密数量
     */
    public function revoke_by_order_item($order_item_id) {
        global $wpdb;
        $table = $this->db->get_table('card_inventory');

        $updated = $wpdb->update(
            $table,
            [
                'status' => self::STATUS_REVOKED,
            ],
            [
                'order_item_id' => $order_item_id,
                'status'        => self::STATUS_SOLD,
            ],
            ['%d'],
            ['%d', '%d']
        );

        return $updated !== false ? $updated : 0;
    }

    /**
     * 获取SKU可用卡密数量
     *
     * @param int $sku_id
     * @return int
     */
    public function get_available_count($sku_id) {
        global $wpdb;
        $table = $this->db->get_table('card_inventory');

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE sku_id = %d AND status = %d",
            $sku_id,
            self::STATUS_AVAILABLE
        ));
    }

    /**
     * 获取商品可用卡密总数
     *
     * @param int $product_id
     * @return int
     */
    public function get_product_available_count($product_id) {
        global $wpdb;
        $table = $this->db->get_table('card_inventory');

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE product_id = %d AND status = %d",
            $product_id,
            self::STATUS_AVAILABLE
        ));
    }

    /**
     * 获取订单分配的卡密
     *
     * @param int $order_id
     * @return array
     */
    public function get_by_order($order_id) {
        global $wpdb;
        $table = $this->db->get_table('card_inventory');

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE order_id = %d ORDER BY order_item_id, id",
            $order_id
        ));
    }

    /**
     * 获取订单明细分配的卡密
     *
     * @param int $order_item_id
     * @return array
     */
    public function get_by_order_item($order_item_id) {
        global $wpdb;
        $table = $this->db->get_table('card_inventory');

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE order_item_id = %d ORDER BY id",
            $order_item_id
        ));
    }

    /**
     * 获取SKU的卡密列表（后台管理用）
     *
     * @param int   $sku_id
     * @param array $args
     * @return array
     */
    public function get_list($sku_id, $args = []) {
        global $wpdb;
        $table = $this->db->get_table('card_inventory');

        $defaults = [
            'status' => null,
            'offset' => 0,
            'limit'  => 20,
        ];
        $args = wp_parse_args($args, $defaults);

        $where = "WHERE sku_id = %d";
        $params = [$sku_id];

        if ($args['status'] !== null) {
            $where .= " AND status = %d";
            $params[] = $args['status'];
        }

        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            array_merge($params, [$args['limit'], $args['offset']])
        );

        return $wpdb->get_results($sql);
    }

    /**
     * 获取SKU的卡密总数
     *
     * @param int  $sku_id
     * @param int|null $status
     * @return int
     */
    public function get_count($sku_id, $status = null) {
        global $wpdb;
        $table = $this->db->get_table('card_inventory');

        if ($status !== null) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE sku_id = %d AND status = %d",
                $sku_id,
                $status
            ));
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE sku_id = %d",
            $sku_id
        ));
    }

    /**
     * 删除单个卡密
     *
     * @param int $card_id
     * @return bool
     */
    public function delete($card_id) {
        global $wpdb;
        $table = $this->db->get_table('card_inventory');

        // 只能删除未售出的卡密
        $deleted = $wpdb->delete(
            $table,
            [
                'id'     => $card_id,
                'status' => self::STATUS_AVAILABLE,
            ],
            ['%d', '%d']
        );

        return $deleted !== false;
    }

    /**
     * 批量删除未使用的卡密
     *
     * @param int $sku_id
     * @return int 删除数量
     */
    public function delete_available_by_sku($sku_id) {
        global $wpdb;
        $table = $this->db->get_table('card_inventory');

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE sku_id = %d AND status = %d",
            $sku_id,
            self::STATUS_AVAILABLE
        ));

        return $deleted !== false ? $deleted : 0;
    }

    /**
     * 获取卡密商品 SKU 选项。
     *
     * @return array
     */
    public function get_card_sku_options() {
        global $wpdb;

        $products_table = $this->db->get_table('products');
        $skus_table = $this->db->get_table('product_skus');
        $cards_table = $this->db->get_table('card_inventory');

        $sql = $wpdb->prepare(
            "SELECT
                p.id AS product_id,
                p.title AS product_title,
                p.status AS product_status,
                s.id AS sku_id,
                s.sku_code,
                s.attr_values,
                s.is_default,
                s.status AS sku_status,
                COUNT(c.id) AS total_count,
                COALESCE(SUM(CASE WHEN c.status = %d THEN 1 ELSE 0 END), 0) AS available_count,
                COALESCE(SUM(CASE WHEN c.status = %d THEN 1 ELSE 0 END), 0) AS sold_count
            FROM {$products_table} p
            INNER JOIN {$skus_table} s ON s.product_id = p.id
            LEFT JOIN {$cards_table} c ON c.sku_id = s.id
            WHERE p.product_type = %s AND p.virtual_type = %s
            GROUP BY p.id, p.title, p.status, s.id, s.sku_code, s.attr_values, s.is_default, s.status
            ORDER BY p.id DESC, s.is_default DESC, s.id ASC",
            self::STATUS_AVAILABLE,
            self::STATUS_SOLD,
            'virtual',
            'card'
        );

        $rows = $wpdb->get_results($sql);
        if (empty($rows)) {
            return [];
        }

        foreach ($rows as $row) {
            $row->sku_label = $this->format_sku_label($row);
        }

        return $rows;
    }

    /**
     * 校验 SKU 是否属于卡密虚拟商品。
     *
     * @param int $product_id 商品 ID。
     * @param int $sku_id     SKU ID。
     * @return bool
     */
    public function is_card_product_sku($product_id, $sku_id) {
        global $wpdb;

        $product_id = absint($product_id);
        $sku_id = absint($sku_id);
        if ($product_id <= 0 || $sku_id <= 0) {
            return false;
        }

        $products_table = $this->db->get_table('products');
        $skus_table = $this->db->get_table('product_skus');

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
            FROM {$products_table} p
            INNER JOIN {$skus_table} s ON s.product_id = p.id
            WHERE p.id = %d
                AND s.id = %d
                AND p.product_type = %s
                AND p.virtual_type = %s",
            $product_id,
            $sku_id,
            'virtual',
            'card'
        ));

        return (int) $count > 0;
    }

    /**
     * 获取后台卡密统计。
     *
     * @param array $args 查询参数。
     * @return array
     */
    public function get_admin_stats($args = []) {
        global $wpdb;

        $table = $this->db->get_table('card_inventory');
        $clauses = $this->build_admin_filter_clauses($args, 'c', false);
        $where_sql = !empty($clauses['where']) ? 'WHERE ' . implode(' AND ', $clauses['where']) : '';

        $params = array_merge(
            [self::STATUS_AVAILABLE, self::STATUS_SOLD, self::STATUS_REVOKED],
            $clauses['params']
        );

        $sql = $wpdb->prepare(
            "SELECT
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN c.status = %d THEN 1 ELSE 0 END), 0) AS available,
                COALESCE(SUM(CASE WHEN c.status = %d THEN 1 ELSE 0 END), 0) AS sold,
                COALESCE(SUM(CASE WHEN c.status = %d THEN 1 ELSE 0 END), 0) AS revoked
            FROM {$table} c
            {$where_sql}",
            $params
        );

        $row = $wpdb->get_row($sql);
        return [
            'total'     => isset($row->total) ? (int) $row->total : 0,
            'available' => isset($row->available) ? (int) $row->available : 0,
            'sold'      => isset($row->sold) ? (int) $row->sold : 0,
            'revoked'   => isset($row->revoked) ? (int) $row->revoked : 0,
        ];
    }

    /**
     * 获取后台卡密列表。
     *
     * @param array $args 查询参数。
     * @return array
     */
    public function get_admin_list($args = []) {
        global $wpdb;

        $defaults = [
            'product_id' => 0,
            'sku_id'     => 0,
            'status'     => '',
            'limit'      => 30,
            'offset'     => 0,
        ];
        $args = wp_parse_args($args, $defaults);
        $args['limit'] = max(1, min(100, absint($args['limit'])));
        $args['offset'] = max(0, absint($args['offset']));

        $cards_table = $this->db->get_table('card_inventory');
        $products_table = $this->db->get_table('products');
        $skus_table = $this->db->get_table('product_skus');
        $orders_table = $this->db->get_table('orders');

        $clauses = $this->build_admin_filter_clauses($args, 'c', true);
        $where_sql = !empty($clauses['where']) ? 'WHERE ' . implode(' AND ', $clauses['where']) : '';
        $params = array_merge($clauses['params'], [$args['limit'], $args['offset']]);

        $sql = $wpdb->prepare(
            "SELECT
                c.*,
                p.title AS product_title,
                s.sku_code,
                s.attr_values,
                o.order_no
            FROM {$cards_table} c
            LEFT JOIN {$products_table} p ON p.id = c.product_id
            LEFT JOIN {$skus_table} s ON s.id = c.sku_id
            LEFT JOIN {$orders_table} o ON o.id = c.order_id
            {$where_sql}
            ORDER BY c.created_at DESC, c.id DESC
            LIMIT %d OFFSET %d",
            $params
        );

        $rows = $wpdb->get_results($sql);
        if (empty($rows)) {
            return [];
        }

        foreach ($rows as $row) {
            $row->sku_label = $this->format_sku_label($row);
        }

        return $rows;
    }

    /**
     * 获取后台卡密数量。
     *
     * @param array $args 查询参数。
     * @return int
     */
    public function get_admin_count($args = []) {
        global $wpdb;

        $table = $this->db->get_table('card_inventory');
        $clauses = $this->build_admin_filter_clauses($args, 'c', true);
        $where_sql = !empty($clauses['where']) ? 'WHERE ' . implode(' AND ', $clauses['where']) : '';

        $sql = "SELECT COUNT(*) FROM {$table} c {$where_sql}";
        if (!empty($clauses['params'])) {
            $sql = $wpdb->prepare($sql, $clauses['params']);
        }

        return (int) $wpdb->get_var($sql);
    }

    /**
     * 自动生成卡密。
     *
     * @param int    $product_id         商品 ID。
     * @param int    $sku_id             SKU ID。
     * @param int    $quantity           生成数量。
     * @param string $prefix             卡号前缀。
     * @param int    $card_no_length     卡号随机位数。
     * @param int    $card_secret_length 卡密随机位数。
     * @return array
     */
    public function generate_cards($product_id, $sku_id, $quantity, $prefix = '', $card_no_length = 12, $card_secret_length = 16) {
        $product_id = absint($product_id);
        $sku_id = absint($sku_id);
        $quantity = max(0, min(1000, absint($quantity)));
        $card_no_length = max(6, min(32, absint($card_no_length)));
        $card_secret_length = max(8, min(64, absint($card_secret_length)));
        $prefix = $this->sanitize_generated_prefix($prefix);

        $result = [
            'success'    => 0,
            'failed'     => 0,
            'duplicates' => 0,
        ];

        if ($quantity <= 0) {
            return $result;
        }

        if (!$this->is_card_product_sku($product_id, $sku_id)) {
            $result['failed'] = $quantity;
            return $result;
        }

        $cards = [];
        for ($i = 0; $i < $quantity; $i++) {
            $card_no = '';
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $candidate = $prefix . $this->generate_random_code($card_no_length);
                if (!$this->exists($product_id, $sku_id, $candidate)) {
                    $card_no = $candidate;
                    break;
                }
            }

            if ($card_no === '') {
                $result['failed']++;
                continue;
            }

            $cards[] = [
                'card_no'     => $card_no,
                'card_secret' => $this->generate_random_code($card_secret_length),
            ];
        }

        if (empty($cards)) {
            return $result;
        }

        $import_result = $this->import_batch($product_id, $sku_id, $cards);
        $import_result['failed'] += $result['failed'];

        return $import_result;
    }

    /**
     * 获取状态文案。
     *
     * @param int $status 状态。
     * @return string
     */
    public function get_status_label($status) {
        switch ((int) $status) {
            case self::STATUS_AVAILABLE:
                return __('未售出', 'qilingshop');
            case self::STATUS_SOLD:
                return __('已售出', 'qilingshop');
            case self::STATUS_REVOKED:
                return __('已撤回', 'qilingshop');
            default:
                return __('未知', 'qilingshop');
        }
    }

    /**
     * 获取状态徽标 class。
     *
     * @param int $status 状态。
     * @return string
     */
    public function get_status_badge_class($status) {
        switch ((int) $status) {
            case self::STATUS_AVAILABLE:
                return 'success';
            case self::STATUS_SOLD:
                return 'primary';
            case self::STATUS_REVOKED:
                return 'secondary';
            default:
                return '';
        }
    }

    /**
     * 构建后台筛选条件。
     *
     * @param array  $args           查询参数。
     * @param string $alias          表别名。
     * @param bool   $include_status 是否包含状态筛选。
     * @return array
     */
    private function build_admin_filter_clauses($args, $alias = 'c', $include_status = true) {
        $args = wp_parse_args($args, [
            'product_id' => 0,
            'sku_id'     => 0,
            'status'     => '',
        ]);

        $alias = preg_replace('/[^A-Za-z0-9_]/', '', (string) $alias);
        if ($alias === '') {
            $alias = 'c';
        }

        $where = [];
        $params = [];

        $product_id = absint($args['product_id']);
        if ($product_id > 0) {
            $where[] = "{$alias}.product_id = %d";
            $params[] = $product_id;
        }

        $sku_id = absint($args['sku_id']);
        if ($sku_id > 0) {
            $where[] = "{$alias}.sku_id = %d";
            $params[] = $sku_id;
        }

        if ($include_status && $args['status'] !== '' && $args['status'] !== null) {
            $status = (int) $args['status'];
            if (in_array($status, [self::STATUS_AVAILABLE, self::STATUS_SOLD, self::STATUS_REVOKED], true)) {
                $where[] = "{$alias}.status = %d";
                $params[] = $status;
            }
        }

        return [
            'where'  => $where,
            'params' => $params,
        ];
    }

    /**
     * 格式化 SKU 标签。
     *
     * @param object $row SKU 数据。
     * @return string
     */
    private function format_sku_label($row) {
        $parts = [];

        if (!empty($row->sku_code)) {
            $parts[] = (string) $row->sku_code;
        }

        if (!empty($row->attr_values)) {
            $attr_values = json_decode((string) $row->attr_values, true);
            if (is_array($attr_values)) {
                $attr_parts = [];
                foreach ($attr_values as $key => $value) {
                    if (is_array($value)) {
                        $value = implode('/', array_map('strval', $value));
                    }

                    $value = trim((string) $value);
                    if ($value === '') {
                        continue;
                    }

                    if (is_string($key) && $key !== '') {
                        $attr_parts[] = $key . ':' . $value;
                    } else {
                        $attr_parts[] = $value;
                    }
                }

                if (!empty($attr_parts)) {
                    $parts[] = implode(' / ', $attr_parts);
                }
            }
        }

        if (empty($parts)) {
            $parts[] = !empty($row->is_default)
                ? __('默认SKU', 'qilingshop')
                : sprintf(__('SKU #%d', 'qilingshop'), isset($row->sku_id) ? (int) $row->sku_id : 0);
        }

        return implode(' - ', $parts);
    }

    /**
     * 清理自动生成卡号前缀。
     *
     * @param string $prefix 前缀。
     * @return string
     */
    private function sanitize_generated_prefix($prefix) {
        $prefix = sanitize_text_field((string) $prefix);
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', $prefix));
        return substr($prefix, 0, 32);
    }

    /**
     * 生成易读随机码。
     *
     * @param int $length 长度。
     * @return string
     */
    private function generate_random_code($length) {
        $length = max(1, absint($length));
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($chars) - 1;
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[wp_rand(0, $max)];
        }

        return $code;
    }

    /**
     * 解析卡密文本（支持多种格式）
     * 
     * 支持格式：
     * - 每行一个卡号
     * - 每行 卡号----卡密
     * - 每行 卡号,卡密
     * - 每行 卡号\t卡密
     *
     * @param string $text
     * @return array
     */
    public static function parse_cards_text($text) {
        $cards = [];
        $lines = preg_split('/\r\n|\r|\n/', $text);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $card_no = '';
            $card_secret = '';

            // 尝试不同的分隔符
            if (strpos($line, '----') !== false) {
                list($card_no, $card_secret) = array_pad(explode('----', $line, 2), 2, '');
            } elseif (strpos($line, "\t") !== false) {
                list($card_no, $card_secret) = array_pad(explode("\t", $line, 2), 2, '');
            } elseif (strpos($line, ',') !== false) {
                list($card_no, $card_secret) = array_pad(explode(',', $line, 2), 2, '');
            } else {
                $card_no = $line;
            }

            $card_no = trim($card_no);
            $card_secret = trim($card_secret);

            if (!empty($card_no)) {
                $cards[] = [
                    'card_no'     => $card_no,
                    'card_secret' => $card_secret,
                ];
            }
        }

        return $cards;
    }
}

/**
 * 获取卡密库存实例
 *
 * @return QLS_Card_Inventory
 */
function qls_card_inventory() {
    return QLS_Card_Inventory::instance();
}
