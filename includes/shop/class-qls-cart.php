<?php
/**
 * 购物车管理类
 * 
 * 处理购物车的添加、删除、更新操作
 * 支持登录用户和游客
 *
 * @package QilingShop
 * @since   2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_Cart {

    /**
     * 单例实例
     */
    private static $instance = null;

    /**
     * 数据库实例
     */
    private $db;

    /**
     * 会话ID（游客使用）
     */
    private $session_id;

    /**
     * 当前请求持有的结账购物车锁。
     */
    private $checkout_lock_name = '';

    /**
     * 购物车锁等待时间（秒）
     */
    const CART_LOCK_TIMEOUT = 5;

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
        $this->init_session();
    }

    /**
     * 初始化会话
     */
    private function init_session() {
        if (is_user_logged_in()) {
            $this->session_id = null;
            return;
        }

        $cookie_name = 'qls_cart_session';
        
        if (isset($_COOKIE[$cookie_name])) {
            $this->session_id = sanitize_text_field($_COOKIE[$cookie_name]);
        } else {
            $this->session_id = wp_generate_uuid4();
            $days = get_option('qls_shop_cart_cookie_days', 7);
            
            if (!headers_sent()) {
                setcookie(
                    $cookie_name,
                    $this->session_id,
                    time() + (DAY_IN_SECONDS * $days),
                    COOKIEPATH,
                    COOKIE_DOMAIN,
                    is_ssl(),
                    true
                );
            }
        }
    }

    /**
     * 获取当前用户ID或会话ID
     */
    private function get_identifier() {
        if (is_user_logged_in()) {
            return ['user_id' => get_current_user_id()];
        }
        return ['session_id' => $this->session_id];
    }

    /**
     * 构建购物车归属键
     *
     * @param array|null $identifier
     * @return string
     */
    private function get_owner_key($identifier = null) {
        $identifier = is_array($identifier) && !empty($identifier) ? $identifier : $this->get_identifier();

        if (isset($identifier['user_id']) && (int) $identifier['user_id'] > 0) {
            return 'u:' . (int) $identifier['user_id'];
        }

        return 's:' . (string) ($identifier['session_id'] ?? '');
    }

    /**
     * 构建购物车锁名
     *
     * @param array $identifier
     * @return string
     */
    private function build_cart_lock_name($identifier) {
        if (isset($identifier['user_id'])) {
            return 'qls_cart_user_' . (int) $identifier['user_id'];
        }

        return 'qls_cart_session_' . md5((string) ($identifier['session_id'] ?? ''));
    }

    /**
     * 获取多个锁名（排序后用于避免死锁）
     *
     * @param array $identifiers
     * @return array
     */
    private function build_sorted_cart_lock_names($identifiers) {
        $lock_names = [];
        foreach ($identifiers as $identifier) {
            if (!is_array($identifier) || empty($identifier)) {
                continue;
            }
            $lock_names[] = $this->build_cart_lock_name($identifier);
        }

        $lock_names = array_values(array_unique($lock_names));
        sort($lock_names, SORT_STRING);

        return $lock_names;
    }

    /**
     * 获取命名锁
     *
     * @param string $lock_name
     * @param int    $timeout
     * @return bool
     */
    private function acquire_named_lock($lock_name, $timeout = self::CART_LOCK_TIMEOUT) {
        global $wpdb;

        $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, (int) $timeout));
        return $result !== null && (int) $result === 1;
    }

    /**
     * 释放命名锁
     *
     * @param string $lock_name
     * @return void
     */
    private function release_named_lock($lock_name) {
        global $wpdb;

        if (!empty($lock_name)) {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    /**
     * 获取购物车锁
     *
     * @param array $identifier
     * @param int   $timeout
     * @return string|false
     */
    private function acquire_cart_lock($identifier, $timeout = self::CART_LOCK_TIMEOUT) {
        $lock_name = $this->build_cart_lock_name($identifier);
        return $this->acquire_named_lock($lock_name, $timeout) ? $lock_name : false;
    }

    /**
     * 在整个结账流程内锁定当前购物车，阻止并发增删改。
     *
     * @return bool
     */
    public function begin_checkout_lock() {
        if ($this->checkout_lock_name !== '') {
            return true;
        }

        $lock_name = $this->acquire_cart_lock($this->get_identifier());
        if (!$lock_name) {
            return false;
        }

        $this->checkout_lock_name = $lock_name;
        return true;
    }

    /**
     * 释放当前请求持有的结账购物车锁。
     */
    public function end_checkout_lock() {
        if ($this->checkout_lock_name === '') {
            return;
        }

        $this->release_named_lock($this->checkout_lock_name);
        $this->checkout_lock_name = '';
    }

    /**
     * 获取多把购物车锁
     *
     * @param array $identifiers
     * @param int   $timeout
     * @return array|false
     */
    private function acquire_cart_locks($identifiers, $timeout = self::CART_LOCK_TIMEOUT) {
        $lock_names = $this->build_sorted_cart_lock_names($identifiers);
        $acquired = [];

        foreach ($lock_names as $lock_name) {
            if (!$this->acquire_named_lock($lock_name, $timeout)) {
                foreach (array_reverse($acquired) as $held_lock) {
                    $this->release_named_lock($held_lock);
                }
                return false;
            }
            $acquired[] = $lock_name;
        }

        return $acquired;
    }

    /**
     * 释放多把购物车锁
     *
     * @param array $lock_names
     * @return void
     */
    private function release_cart_locks($lock_names) {
        foreach (array_reverse((array) $lock_names) as $lock_name) {
            $this->release_named_lock($lock_name);
        }
    }

    /**
     * 归并同一购物车内的重复商品行
     *
     * @param array $identifier
     * @return void
     */
    private function normalize_duplicate_items($identifier) {
        $rows = $this->db->get_results('cart_items', [
            'where'   => $identifier,
            'orderby' => 'id',
            'order'   => 'ASC',
            'limit'   => -1,
        ]);

        if (empty($rows)) {
            return;
        }

        $seen = [];
        foreach ($rows as $row) {
            $key = (int) $row->product_id . ':' . (int) $row->sku_id;

            if (!isset($seen[$key])) {
                $seen[$key] = $row;
                continue;
            }

            $primary = $seen[$key];
            $merged_quantity = max(1, (int) $primary->quantity + (int) $row->quantity);
            $this->db->update('cart_items', ['quantity' => $merged_quantity], ['id' => (int) $primary->id]);
            $primary->quantity = $merged_quantity;
            $seen[$key] = $primary;

            $this->db->delete('cart_items', ['id' => (int) $row->id]);
        }
    }

    /**
     * 加载购物车商品列表
     *
     * @param array $identifier
     * @param int   $current_user_id
     * @return array
     */
    private function load_items($identifier, $current_user_id = 0) {
        $items = $this->db->get_results('cart_items', [
            'where'   => $identifier,
            'orderby' => 'created_at',
            'order'   => 'DESC',
        ]);

        $result = [];
        $resource_maps = $this->build_cart_resource_maps($items);
        $product_map = $resource_maps['products'];
        $sku_map = $resource_maps['skus'];

        foreach ($items as $item) {
            $product = $product_map[(int) $item->product_id] ?? null;
            $sku = $sku_map[(int) $item->sku_id] ?? null;

            if (!$product || !$sku) {
                $this->db->delete('cart_items', ['id' => $item->id]);
                continue;
            }

            $effective_price = qls_product()->get_effective_sku_price($product, $sku, $current_user_id);
            $is_new_user_special = !empty($effective_price['is_new_user_special']);

            if ((int) $item->quantity < 1) {
                $item->is_invalid = true;
                $item->invalid_reason = __('商品数量异常，请删除后重新添加', 'qilingshop');
            } elseif ($product->status != 1) {
                $item->is_invalid = true;
                $item->invalid_reason = __('商品已下架', 'qilingshop');
            } elseif ($sku->stock <= 0) {
                $item->is_invalid = true;
                $item->invalid_reason = __('库存不足', 'qilingshop');
            } elseif ($is_new_user_special && (int) $item->quantity > 1) {
                $item->is_invalid = true;
                $item->invalid_reason = __('新人专项商品仅限购1件', 'qilingshop');
            } else {
                $item->is_invalid = false;
            }

            if ($item->quantity > $sku->stock) {
                $item->quantity = $sku->stock;
                $this->db->update('cart_items', ['quantity' => $sku->stock], ['id' => $item->id]);
            }

            $item->product = $product;
            $item->sku = $sku;
            $item->price = (float) ($effective_price['price'] ?? 0);
            $item->base_price = (float) ($effective_price['base_price'] ?? $item->price);
            $item->is_new_user_special = $is_new_user_special;
            $item->new_user_special_price = (float) ($effective_price['new_user_special_price'] ?? 0);
            $item->is_vip_price = !empty($effective_price['is_vip_price']);
            $item->vip_price = (float) ($effective_price['vip_price'] ?? 0);
            $item->vip_level_id = (int) ($effective_price['vip_level_id'] ?? 0);
            $item->price_source = isset($effective_price['price_source']) ? (string) $effective_price['price_source'] : 'base';
            $item->subtotal = $item->price * $item->quantity;

            $result[] = $item;
        }

        return $result;
    }

    /**
     * 统计当前购物车内已命中新人专项价的商品数量
     *
     * @param int $exclude_item_id 排除的购物车项ID
     * @param int $user_id 当前登录用户ID
     * @return array{line_count:int, quantity:int}
     */
    private function get_new_user_special_cart_stats($exclude_item_id = 0, $user_id = 0) {
        $exclude_item_id = (int) $exclude_item_id;
        $user_id = (int) $user_id;
        $identifier = $this->get_identifier();
        $rows = $this->db->get_results('cart_items', [
            'where' => $identifier,
            'limit' => -1,
        ]);

        $stats = [
            'line_count' => 0,
            'quantity' => 0,
        ];
        $resource_maps = $this->build_cart_resource_maps($rows);
        $product_map = $resource_maps['products'];
        $sku_map = $resource_maps['skus'];

        foreach ($rows as $row) {
            if ($exclude_item_id > 0 && (int) $row->id === $exclude_item_id) {
                continue;
            }

            $product = $product_map[(int) $row->product_id] ?? null;
            $sku = $sku_map[(int) $row->sku_id] ?? null;
            if (!$product || !$sku) {
                continue;
            }

            $effective_price = qls_product()->get_effective_sku_price($product, $sku, $user_id);
            if (empty($effective_price['is_new_user_special'])) {
                continue;
            }

            $stats['line_count']++;
            $stats['quantity'] += max(0, (int) $row->quantity);
        }

        return $stats;
    }

    /**
     * 预加载购物车商品和 SKU，避免逐条查库。
     *
     * @param array $rows
     * @return array{products: array<int, object>, skus: array<int, object>}
     */
    private function build_cart_resource_maps($rows) {
        $product_map = [];
        $sku_map = [];
        $product_ids = [];
        $sku_ids = [];

        foreach ((array) $rows as $row) {
            $product_id = isset($row->product_id) ? (int) $row->product_id : 0;
            $sku_id = isset($row->sku_id) ? (int) $row->sku_id : 0;
            if ($product_id > 0) {
                $product_ids[$product_id] = $product_id;
            }
            if ($sku_id > 0) {
                $sku_ids[$sku_id] = $sku_id;
            }
        }

        if (empty($product_ids) && empty($sku_ids)) {
            return ['products' => $product_map, 'skus' => $sku_map];
        }

        $product_manager = function_exists('qls_product') ? qls_product() : null;
        if (!$product_manager) {
            return ['products' => $product_map, 'skus' => $sku_map];
        }

        if (!empty($product_ids)) {
            $products = $product_manager->get_list([
                'include' => array_values($product_ids),
                'status'  => '',
                'limit'   => -1,
            ]);
            foreach ((array) $products as $product) {
                $product_id = isset($product->id) ? (int) $product->id : 0;
                if ($product_id > 0) {
                    $product_map[$product_id] = $product;
                }
            }
        }

        if (!empty($sku_ids) && method_exists($product_manager, 'get_skus_by_ids')) {
            $sku_map = $product_manager->get_skus_by_ids(array_values($sku_ids));
        }

        return ['products' => $product_map, 'skus' => $sku_map];
    }

    /**
     * 添加商品到购物车
     *
     * @param int $product_id
     * @param int $sku_id
     * @param int $quantity
     * @return array
     */
    public function add($product_id, $sku_id, $quantity = 1) {
        $quantity = (int) $quantity;
        if ($quantity < 1) {
            return ['success' => false, 'message' => __('商品数量必须大于等于1', 'qilingshop')];
        }

        // 验证商品
        $product = qls_product()->get($product_id);
        if (!$product || $product->status != 1) {
            return ['success' => false, 'message' => __('商品不存在或已下架', 'qilingshop')];
        }

        // 验证SKU
        $sku = qls_product()->get_sku($sku_id);
        if (!$sku || $sku->product_id != $product_id) {
            return ['success' => false, 'message' => __('商品规格不存在', 'qilingshop')];
        }

        // 检查库存
        if ($sku->stock < $quantity) {
            return ['success' => false, 'message' => __('库存不足', 'qilingshop')];
        }

        $current_user_id = is_user_logged_in() ? (int) get_current_user_id() : 0;
        $effective_price = qls_product()->get_effective_sku_price($product, $sku, $current_user_id);
        $is_new_user_special_active = !empty($effective_price['is_new_user_special']);

        if ($is_new_user_special_active && $quantity > 1) {
            return ['success' => false, 'message' => __('新人专项商品仅限购1件', 'qilingshop')];
        }

        $identifier = $this->get_identifier();
        $lock_name = $this->acquire_cart_lock($identifier);
        if (!$lock_name) {
            return ['success' => false, 'message' => __('购物车处理中，请稍后重试', 'qilingshop')];
        }

        try {
            $this->normalize_duplicate_items($identifier);

            $where = array_merge($identifier, [
                'product_id' => $product_id,
                'sku_id'     => $sku_id,
            ]);
            $existing = $this->db->get_row('cart_items', $where);

            if ($existing) {
                $new_quantity = (int) $existing->quantity + $quantity;

                if ($new_quantity > $sku->stock) {
                    return ['success' => false, 'message' => __('超出库存数量', 'qilingshop')];
                }

                if ($is_new_user_special_active && $new_quantity > 1) {
                    return ['success' => false, 'message' => __('新人专项商品仅限购1件', 'qilingshop')];
                }

                $this->db->update('cart_items', ['quantity' => $new_quantity], ['id' => (int) $existing->id]);
            } else {
                if ($is_new_user_special_active) {
                    $special_stats = $this->get_new_user_special_cart_stats(0, $current_user_id);
                    if ($special_stats['quantity'] > 0) {
                        return ['success' => false, 'message' => __('购物车已有新人专项商品，仅允许购买1件', 'qilingshop')];
                    }
                }

                $data = array_merge($identifier, [
                    'owner_key'  => $this->get_owner_key($identifier),
                    'product_id' => $product_id,
                    'sku_id'     => $sku_id,
                    'quantity'   => $quantity,
                ]);
                $inserted = $this->db->insert('cart_items', $data);
                if (!$inserted) {
                    $duplicate = stripos((string) $this->db->get_wpdb()->last_error, 'Duplicate entry') !== false;
                    if ($duplicate) {
                        $existing = $this->db->get_row('cart_items', $where);
                        if ($existing) {
                            $new_quantity = (int) $existing->quantity + $quantity;
                            if ($new_quantity > $sku->stock) {
                                return ['success' => false, 'message' => __('超出库存数量', 'qilingshop')];
                            }

                            if ($is_new_user_special_active && $new_quantity > 1) {
                                return ['success' => false, 'message' => __('新人专项商品仅限购1件', 'qilingshop')];
                            }

                            $this->db->update('cart_items', ['quantity' => $new_quantity], ['id' => (int) $existing->id]);
                        } else {
                            return ['success' => false, 'message' => __('添加购物车失败，请稍后重试', 'qilingshop')];
                        }
                    } else {
                        return ['success' => false, 'message' => __('添加购物车失败，请稍后重试', 'qilingshop')];
                    }
                }
            }
        } finally {
            $this->release_named_lock($lock_name);
        }

        do_action('qls_shop_cart_updated');

        return ['success' => true, 'message' => __('已添加到购物车', 'qilingshop')];
    }

    /**
     * 更新购物车商品数量
     *
     * @param int $cart_item_id
     * @param int $quantity
     * @return array
     */
    public function update($cart_item_id, $quantity) {
        $identifier = $this->get_identifier();
        $lock_name = $this->acquire_cart_lock($identifier);
        if (!$lock_name) {
            return ['success' => false, 'message' => __('购物车处理中，请稍后重试', 'qilingshop')];
        }

        try {
            $this->normalize_duplicate_items($identifier);

            $item = $this->db->get_by_id('cart_items', $cart_item_id);
            if (!$item) {
                return ['success' => false, 'message' => __('购物车项不存在', 'qilingshop')];
            }

            $key = key($identifier);
            if ($item->$key != $identifier[$key]) {
                return ['success' => false, 'message' => __('无权操作', 'qilingshop')];
            }

            if ($quantity <= 0) {
                $this->db->delete('cart_items', ['id' => $cart_item_id]);
                do_action('qls_shop_cart_updated');
                return ['success' => true, 'message' => __('已移除', 'qilingshop')];
            }

            $sku = qls_product()->get_sku($item->sku_id);
            if (!$sku || $quantity > $sku->stock) {
                return ['success' => false, 'message' => __('库存不足', 'qilingshop')];
            }

            $product = qls_product()->get((int) $item->product_id);
            $current_user_id = is_user_logged_in() ? (int) get_current_user_id() : 0;
            $effective_price = qls_product()->get_effective_sku_price($product, $sku, $current_user_id);
            if ($product && !empty($effective_price['is_new_user_special'])) {
                if ($quantity > 1) {
                    return ['success' => false, 'message' => __('新人专项商品仅限购1件', 'qilingshop')];
                }

                $special_stats = $this->get_new_user_special_cart_stats($cart_item_id, $current_user_id);
                if ($special_stats['quantity'] > 0) {
                    return ['success' => false, 'message' => __('购物车已有新人专项商品，仅允许购买1件', 'qilingshop')];
                }
            }

            $this->db->update('cart_items', ['quantity' => $quantity], ['id' => $cart_item_id]);
        } finally {
            $this->release_named_lock($lock_name);
        }

        do_action('qls_shop_cart_updated');

        return ['success' => true, 'message' => __('已更新', 'qilingshop')];
    }

    /**
     * 移除购物车商品
     *
     * @param int $cart_item_id
     * @return array
     */
    public function remove($cart_item_id) {
        $identifier = $this->get_identifier();
        $lock_name = $this->acquire_cart_lock($identifier);
        if (!$lock_name) {
            return ['success' => false, 'message' => __('购物车处理中，请稍后重试', 'qilingshop')];
        }

        try {
            $this->normalize_duplicate_items($identifier);

            $item = $this->db->get_by_id('cart_items', $cart_item_id);
            if (!$item) {
                return ['success' => false, 'message' => __('购物车项不存在', 'qilingshop')];
            }

            $key = key($identifier);
            if ($item->$key != $identifier[$key]) {
                return ['success' => false, 'message' => __('无权操作', 'qilingshop')];
            }

            $this->db->delete('cart_items', ['id' => $cart_item_id]);
        } finally {
            $this->release_named_lock($lock_name);
        }

        do_action('qls_shop_cart_updated');

        return ['success' => true, 'message' => __('已移除', 'qilingshop')];
    }

    /**
     * 清空购物车
     *
     * @return bool
     */
    public function clear() {
        $identifier = $this->get_identifier();
        if ($this->checkout_lock_name !== '') {
            $deleted = $this->db->delete('cart_items', $identifier);
            if ($deleted !== false) {
                do_action('qls_shop_cart_updated');
            }
            return $deleted !== false;
        }

        $lock_name = $this->acquire_cart_lock($identifier);
        if (!$lock_name) {
            return false;
        }

        try {
            $this->db->delete('cart_items', $identifier);
        } finally {
            $this->release_named_lock($lock_name);
        }

        do_action('qls_shop_cart_updated');

        return true;
    }

    /**
     * 获取购物车商品列表
     *
     * @return array
     */
    public function get_items() {
        $identifier = $this->get_identifier();
        $current_user_id = (int) get_current_user_id();

        if ($this->checkout_lock_name !== '') {
            $this->normalize_duplicate_items($identifier);
            return $this->load_items($identifier, $current_user_id);
        }

        $lock_name = $this->acquire_cart_lock($identifier, 2);
        if (!$lock_name) {
            return $this->load_items($identifier, $current_user_id);
        }

        try {
            $this->normalize_duplicate_items($identifier);
            return $this->load_items($identifier, $current_user_id);
        } finally {
            $this->release_named_lock($lock_name);
        }
    }

    /**
     * 获取购物车商品数量
     *
     * @return int
     */
    public function get_count() {
        $identifier = $this->get_identifier();
        $lock_name = $this->acquire_cart_lock($identifier, 2);
        if (!$lock_name) {
            return (int) $this->db->sum('cart_items', 'quantity', $identifier);
        }

        try {
            $this->normalize_duplicate_items($identifier);
            return (int) $this->db->sum('cart_items', 'quantity', $identifier);
        } finally {
            $this->release_named_lock($lock_name);
        }
    }

    /**
     * 获取购物车总金额
     *
     * @return array
     */
    public function get_totals() {
        return $this->calculate_totals($this->get_items());
    }

    /**
     * 根据指定购物车快照计算总计。
     *
     * @param array $items
     * @return array
     */
    public function calculate_totals($items) {
        
        $total_quantity = 0;
        $total_amount = 0;
        $total_points = 0;
        $total_weight = 0;
        $invalid_count = 0;

        foreach ($items as $item) {
            if ($item->is_invalid) {
                $invalid_count++;
                continue;
            }

            $total_quantity += $item->quantity;
            $total_amount += $item->subtotal;
            $total_weight += ($item->sku->weight ?? 0) * $item->quantity;
            
            if ($item->sku->points_price > 0) {
                $total_points += $item->sku->points_price * $item->quantity;
            }
        }

        return [
            'total_quantity' => $total_quantity,
            'total_amount'   => $total_amount,
            'total_points'   => $total_points,
            'total_weight'   => $total_weight,
            'invalid_count'  => $invalid_count,
            'item_count'     => count($items),
        ];
    }

    /**
     * 验证购物车库存
     *
     * @return array
     */
    public function validate_stock($items = null) {
        if ($items === null) {
            $items = $this->get_items();
        }
        $errors = [];

        foreach ($items as $item) {
            if ((int) $item->quantity < 1) {
                $errors[] = [
                    'item_id' => (int) ($item->id ?? 0),
                    'message' => __('商品数量异常，请删除后重新添加', 'qilingshop'),
                ];
                continue;
            }
            if ($item->is_invalid) {
                $errors[] = [
                    'item_id' => $item->id,
                    'product' => $item->product->title,
                    'reason'  => $item->invalid_reason,
                ];
            }
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * 合并游客购物车到用户账号
     *
     * @param int    $user_id
     * @param string $session_id
     * @return int|false 合并数量；锁冲突时返回 false
     */
    public function merge_cart($user_id, $session_id) {
        $lock_names = $this->acquire_cart_locks([
            ['user_id' => (int) $user_id],
            ['session_id' => (string) $session_id],
        ]);
        if (!$lock_names) {
            return false;
        }

        try {
            $this->normalize_duplicate_items(['user_id' => (int) $user_id]);
            $this->normalize_duplicate_items(['session_id' => (string) $session_id]);

            $guest_items = $this->db->get_results('cart_items', [
                'where' => ['session_id' => $session_id],
            ]);

            $merged = 0;

            foreach ($guest_items as $item) {
                $existing = $this->db->get_row('cart_items', [
                    'user_id'    => $user_id,
                    'product_id' => $item->product_id,
                    'sku_id'     => $item->sku_id,
                ]);

                if ($existing) {
                    $sku = qls_product()->get_sku($item->sku_id);
                    $new_quantity = min((int) $existing->quantity + (int) $item->quantity, $sku ? (int) $sku->stock : 99999);
                    $this->db->update('cart_items', ['quantity' => $new_quantity], ['id' => (int) $existing->id]);
                    $this->db->delete('cart_items', ['id' => (int) $item->id]);
                } else {
                    $this->db->update('cart_items', [
                        'user_id'    => $user_id,
                        'session_id' => null,
                        'owner_key'  => $this->get_owner_key(['user_id' => (int) $user_id]),
                    ], ['id' => $item->id]);
                }

                $merged++;
            }

            $this->db->delete('cart_items', ['session_id' => $session_id]);

            return $merged;
        } finally {
            $this->release_cart_locks($lock_names);
        }
    }

    /**
     * 清理过期的游客购物车
     *
     * @param int $days 过期天数
     * @return int 清理数量
     */
    public function cleanup_expired($days = 30) {
        $table = $this->db->get_table('cart_items');
        $date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $sql = $this->db->prepare(
            "DELETE FROM {$table} WHERE session_id IS NOT NULL AND updated_at < %s",
            $date
        );
        
        return $this->db->query($sql);
    }
}

/**
 * 获取购物车类实例的快捷函数
 */
function qls_cart() {
    return QLS_Cart::instance();
}
