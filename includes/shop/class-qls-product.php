<?php
/**
 * 商品管理类
 * 
 * 处理商品的CRUD操作和查询
 *
 * @package QilingShop
 * @since   2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_Product {

    /**
     * 单例实例
     */
    private static $instance = null;

    /**
     * 数据库实例
     */
    private $db;

    /**
     * 缓存版本
     */
    private static $cache_version = null;

    /**
     * products 表字段缓存。
     *
     * @var array<string,bool>|null
     */
    private static $product_columns_map = null;

    /**
     * 商品状态常量
     */
    const STATUS_DRAFT = 0;      // 下架/草稿
    const STATUS_PUBLISHED = 1;  // 上架
    const STATUS_PENDING = 2;    // 审核中

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
    }

    private function get_cache_version() {
        if (self::$cache_version === null) {
            $version = get_option('qls_shop_product_cache_version', '');
            self::$cache_version = $version !== '' ? (string) $version : '1';
        }

        return self::$cache_version;
    }

    private function bump_cache_version() {
        $new_version = sprintf('%.0f', microtime(true) * 1000000) . '-' . wp_rand(1000, 9999);
        update_option('qls_shop_product_cache_version', $new_version);
        self::$cache_version = $new_version;
    }

    private function is_cache_enabled() {
        return (bool) apply_filters('qls_shop_product_cache_enabled', true);
    }

    private function get_cache_ttl($type = 'list') {
        $default = $type === 'single' ? 120 : 60;
        $ttl = (int) apply_filters('qls_shop_product_cache_ttl', $default, $type);
        return max(10, $ttl);
    }

    private function build_cache_key($prefix, $args) {
        return 'qls_shop_' . $prefix . ':' . $this->get_cache_version() . ':' . md5(wp_json_encode($args));
    }

    private function sanitize_orderby($orderby) {
        $allowed = [
            'id', 'sort_order', 'sales_count', 'min_price', 'max_price',
            'created_at', 'updated_at', 'title', 'view_count', 'points_price', 'new_user_special_enabled',
            'activity_recommend_enabled', 'group_display_enabled', 'assist_display_enabled'
        ];
        return in_array($orderby, $allowed, true) ? $orderby : 'id';
    }

    private function sanitize_order($order) {
        return strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
    }

    private function get_decoration_flag_columns() {
        return [
            'activity_recommend' => 'activity_recommend_enabled',
            'group_display'      => 'group_display_enabled',
            'assist_display'     => 'assist_display_enabled',
        ];
    }

    private function apply_decoration_flag_where(&$where, $args) {
        foreach ($this->get_decoration_flag_columns() as $arg_name => $column) {
            if (isset($args[$arg_name]) && $args[$arg_name] !== '') {
                $where[$column] = (int) $args[$arg_name] > 0 ? 1 : 0;
            }
        }
    }

    private function apply_decoration_flag_conditions(&$conditions, &$params, $args) {
        foreach ($this->get_decoration_flag_columns() as $arg_name => $column) {
            if (isset($args[$arg_name]) && $args[$arg_name] !== '') {
                $conditions[] = "{$column} = %d";
                $params[] = (int) $args[$arg_name] > 0 ? 1 : 0;
            }
        }
    }

    private function is_points_payable_filter_enabled($args) {
        return isset($args['points_payable']) && $args['points_payable'] !== '' && (int) $args['points_payable'] > 0;
    }

    private function append_product_sql_conditions(&$conditions, &$params, $args, $alias = '') {
        $prefix = $alias !== '' ? $alias . '.' : '';
        $column = function($name) use ($prefix) {
            return $prefix . '`' . sanitize_key($name) . '`';
        };

        if (isset($args['keyword']) && $args['keyword'] !== '') {
            global $wpdb;
            $like = '%' . $wpdb->esc_like((string) $args['keyword']) . '%';
            $conditions[] = '(' . $column('title') . ' LIKE %s OR ' . $column('subtitle') . ' LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        if (isset($args['status']) && $args['status'] !== '') {
            $conditions[] = $column('status') . ' = %d';
            $params[] = (int) $args['status'];
        }

        $columns_map = $this->get_product_columns_map();
        if (isset($args['product_type']) && $args['product_type'] !== '' && isset($columns_map['product_type'])) {
            $product_type = sanitize_key((string) $args['product_type']);
            if (in_array($product_type, ['physical', 'virtual'], true)) {
                $conditions[] = $column('product_type') . ' = %s';
                $params[] = $product_type;
            }
        }

        if (isset($args['virtual_type']) && $args['virtual_type'] !== '' && isset($columns_map['virtual_type'])) {
            $virtual_type = sanitize_key((string) $args['virtual_type']);
            if (in_array($virtual_type, ['download', 'card', 'custom'], true)) {
                $conditions[] = $column('virtual_type') . ' = %s';
                $params[] = $virtual_type;
            }
        }

        if (!empty($args['category_id'])) {
            $category_ids = array_values(array_unique(array_filter(array_map('intval', (array) $args['category_id']))));
            if (!empty($category_ids)) {
                $placeholders = implode(',', array_fill(0, count($category_ids), '%d'));
                $conditions[] = $column('category_id') . " IN ({$placeholders})";
                $params = array_merge($params, $category_ids);
            }
        }

        if (isset($args['is_hot']) && $args['is_hot'] !== '') {
            $conditions[] = $column('is_hot') . ' = %d';
            $params[] = (int) $args['is_hot'];
        }

        if (isset($args['new_user_special']) && $args['new_user_special'] !== '') {
            $enabled = (int) $args['new_user_special'] > 0 ? 1 : 0;
            $conditions[] = $column('new_user_special_enabled') . ' = %d';
            $params[] = $enabled;
            if ($enabled) {
                $conditions[] = $column('new_user_special_price') . ' > %f';
                $params[] = 0;
            }
        }

        foreach ($this->get_decoration_flag_columns() as $arg_name => $column_name) {
            if (isset($args[$arg_name]) && $args[$arg_name] !== '') {
                $conditions[] = $column($column_name) . ' = %d';
                $params[] = (int) $args[$arg_name] > 0 ? 1 : 0;
            }
        }

        if (!empty($args['include']) && is_array($args['include'])) {
            $include_ids = array_values(array_unique(array_filter(array_map('intval', $args['include']))));
            if (!empty($include_ids)) {
                $placeholders = implode(',', array_fill(0, count($include_ids), '%d'));
                $conditions[] = $column('id') . " IN ({$placeholders})";
                $params = array_merge($params, $include_ids);
            }
        }
    }

    /**
     * 商品列表页使用的轻量字段选择，避免把详情大字段一并查出。
     *
     * @param string $alias
     * @return string
     */
    private function get_list_select_fields($alias = 'p') {
        $prefix = $alias !== '' ? $alias . '.' : '';
        $columns_map = $this->get_product_columns_map();
        $fields = [
            'id',
            'title',
            'subtitle',
            'slug',
            'main_image',
            'gallery',
            'category_id',
            'min_price',
            'max_price',
            'total_stock',
            'sales_count',
            'status',
            'is_hot',
            'new_user_special_enabled',
            'new_user_special_price',
            'activity_recommend_enabled',
            'group_display_enabled',
            'assist_display_enabled',
            'product_type',
            'virtual_type',
            'created_at',
            'review_count',
            'avg_rating',
        ];

        $parts = [];
        foreach ($fields as $field) {
            if (!isset($columns_map[$field])) {
                continue;
            }
            $parts[] = $prefix . '`' . $field . '`';
        }

        return implode(', ', $parts);
    }

    /**
     * 获取 products 表当前实际存在的字段，兼容历史升级不完整的站点。
     *
     * @return array<string,bool>
     */
    private function get_product_columns_map() {
        if (is_array(self::$product_columns_map)) {
            return self::$product_columns_map;
        }

        $wpdb = $this->db->get_wpdb();
        $table = $this->db->get_table('products');
        $rows = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        $map = [];

        foreach ((array) $rows as $column_name) {
            $map[(string) $column_name] = true;
        }

        self::$product_columns_map = $map;
        return self::$product_columns_map;
    }

    /**
     * 常规商品列表排序表达式。
     *
     * @param string $orderby
     * @param string $alias
     * @return string
     */
    private function get_standard_orderby_expression($orderby, $alias = 'p') {
        $prefix = $alias !== '' ? $alias . '.' : '';
        $map = [
            'id'                         => $prefix . '`id`',
            'sort_order'                 => $prefix . '`sort_order`',
            'sales_count'                => $prefix . '`sales_count`',
            'min_price'                  => $prefix . '`min_price`',
            'max_price'                  => $prefix . '`max_price`',
            'created_at'                 => $prefix . '`created_at`',
            'updated_at'                 => $prefix . '`updated_at`',
            'title'                      => $prefix . '`title`',
            'view_count'                 => $prefix . '`view_count`',
            'new_user_special_enabled'   => $prefix . '`new_user_special_enabled`',
            'activity_recommend_enabled' => $prefix . '`activity_recommend_enabled`',
            'group_display_enabled'      => $prefix . '`group_display_enabled`',
            'assist_display_enabled'     => $prefix . '`assist_display_enabled`',
        ];

        return isset($map[$orderby]) ? $map[$orderby] : $prefix . '`id`';
    }

    /**
     * 构造“支持积分购买”SKU存在性判断 SQL。
     *
     * @param string $product_id_expr
     * @return string
     */
    private function get_points_payable_exists_sql($product_id_expr) {
        $skus_table = $this->db->get_table('product_skus');
        return "EXISTS (
            SELECT 1
            FROM {$skus_table} s
            WHERE s.product_id = {$product_id_expr}
              AND s.status = 1
              AND s.points_price IS NOT NULL
              AND s.points_price > 0
              AND s.stock > 0
            LIMIT 1
        )";
    }

    /**
     * 构造“支持积分购买”商品最低积分价 SQL。
     *
     * @param string $product_id_expr
     * @return string
     */
    private function get_points_payable_min_price_sql($product_id_expr) {
        $skus_table = $this->db->get_table('product_skus');
        return "(
            SELECT MIN(s.points_price)
            FROM {$skus_table} s
            WHERE s.product_id = {$product_id_expr}
              AND s.status = 1
              AND s.points_price IS NOT NULL
              AND s.points_price > 0
              AND s.stock > 0
        )";
    }

    private function get_points_payable_list($args) {
        global $wpdb;

        $products_table = $this->db->get_table('products');
        $conditions = [];
        $params = [];
        $this->append_product_sql_conditions($conditions, $params, $args, 'p');
        $conditions[] = $this->get_points_payable_exists_sql('p.`id`');

        $select_fields = $this->get_list_select_fields('p');
        $points_price_sql = $this->get_points_payable_min_price_sql('p.`id`');
        $sql = "SELECT {$select_fields}, {$points_price_sql} AS points_price FROM {$products_table} p";
        if (!empty($conditions)) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $orderby = $args['orderby'] === 'points_price'
            ? 'points_price'
            : $this->get_standard_orderby_expression($args['orderby'], 'p');
        $sql .= " ORDER BY {$orderby} {$args['order']}, p.`id` DESC";

        if ($args['limit'] > 0) {
            $sql .= $wpdb->prepare(' LIMIT %d OFFSET %d', $args['limit'], $args['offset']);
        }

        return $wpdb->get_results(!empty($params) ? $wpdb->prepare($sql, $params) : $sql);
    }

    private function get_points_payable_count($args) {
        global $wpdb;

        $products_table = $this->db->get_table('products');
        $conditions = [];
        $params = [];
        $this->append_product_sql_conditions($conditions, $params, $args, 'p');
        $conditions[] = $this->get_points_payable_exists_sql('p.`id`');

        $sql = "SELECT COUNT(*) FROM {$products_table} p";
        if (!empty($conditions)) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        return (int) $wpdb->get_var(!empty($params) ? $wpdb->prepare($sql, $params) : $sql);
    }

    /**
     * 创建商品
     *
     * @param array $data 商品数据
     * @return int|false 商品ID或false
     */
    public function create($data) {
        $defaults = [
            'title'            => '',
            'subtitle'         => '',
            'slug'             => '',
            'main_image'       => '',
            'gallery'          => '[]',
            'content'          => '',
            'category_id'      => 0,
            'brand'            => '',
            'model'            => '',
            'params'           => '{}',
            'service_tags'     => '[]',
            'shipping_rule_id' => 0,
            'min_price'        => 0,
            'max_price'        => 0,
            'total_stock'      => 0,
            'sales_count'      => 0,
            'status'           => self::STATUS_DRAFT,
            'is_hot'           => 0,
            'new_user_special_enabled' => 0,
            'new_user_special_price'   => null,
            'activity_recommend_enabled' => 0,
            'group_display_enabled'      => 0,
            'assist_display_enabled'     => 0,
            'sort_order'       => 0,
            // 虚拟商品字段
            'product_type'     => 'physical',
            'virtual_type'     => null,
            'virtual_content'  => null,
        ];

        $data = wp_parse_args($data, $defaults);

        // 生成唯一slug
        if (empty($data['slug'])) {
            $data['slug'] = $this->generate_slug($data['title']);
        } else {
            $data['slug'] = $this->ensure_unique_slug($data['slug']);
        }

        // JSON编码
        if (is_array($data['gallery'])) {
            $data['gallery'] = wp_json_encode($data['gallery']);
        }
        if (is_array($data['params'])) {
            $data['params'] = wp_json_encode($data['params']);
        }
        if (is_array($data['service_tags'])) {
            $data['service_tags'] = wp_json_encode($data['service_tags']);
        }
        if (is_array($data['main_image'])) {
            $data['main_image'] = wp_json_encode($data['main_image']);
        }
        if (isset($data['virtual_content']) && is_array($data['virtual_content'])) {
            $data['virtual_content'] = wp_json_encode($data['virtual_content']);
        }

        $product_id = $this->db->insert('products', $data);

        if ($product_id) {
            do_action('qls_shop_product_created', $product_id, $data);
            $this->bump_cache_version();
        }

        return $product_id;
    }

    /**
     * 更新商品
     *
     * @param int   $id   商品ID
     * @param array $data 更新数据
     * @return bool
     */
    public function update($id, $data) {
        // 如果更新slug，确保唯一
        if (isset($data['slug'])) {
            $data['slug'] = $this->ensure_unique_slug($data['slug'], $id);
        }

        // JSON编码
        if (isset($data['gallery']) && is_array($data['gallery'])) {
            $data['gallery'] = wp_json_encode($data['gallery']);
        }
        if (isset($data['params']) && is_array($data['params'])) {
            $data['params'] = wp_json_encode($data['params']);
        }
        if (isset($data['service_tags']) && is_array($data['service_tags'])) {
            $data['service_tags'] = wp_json_encode($data['service_tags']);
        }
        if (isset($data['main_image']) && is_array($data['main_image'])) {
            $data['main_image'] = wp_json_encode($data['main_image']);
        }
        if (isset($data['virtual_content']) && is_array($data['virtual_content'])) {
            $data['virtual_content'] = wp_json_encode($data['virtual_content']);
        }

        $result = $this->db->update('products', $data, ['id' => $id]);

        if ($result !== false) {
            do_action('qls_shop_product_updated', $id, $data);
            $this->bump_cache_version();
        }

        return $result !== false;
    }

    /**
     * 删除商品
     *
     * @param int $id 商品ID
     * @return bool
     */
    public function delete($id) {
        $this->db->begin_transaction();

        try {
            $skus = $this->db->get_results('product_skus', [
                'where'  => ['product_id' => $id],
                'fields' => 'id',
                'limit'  => -1,
            ]);
            foreach ($skus as $sku) {
                if (!empty($sku->id)) {
                    $this->delete_sku_vip_prices((int) $sku->id);
                }
            }

            // 删除关联的SKU
            $this->db->delete('product_skus', ['product_id' => $id]);
            
            // 删除关联的规格
            $attributes = $this->db->get_results('product_attributes', [
                'where' => ['product_id' => $id],
                'fields' => 'id'
            ]);
            foreach ($attributes as $attr) {
                $this->db->delete('product_attribute_values', ['attribute_id' => $attr->id]);
            }
            $this->db->delete('product_attributes', ['product_id' => $id]);
            
            // 删除标签关联
            $this->db->delete('product_tag_relationships', ['product_id' => $id]);
            
            // 删除商品
            $result = $this->db->delete('products', ['id' => $id]);

            $this->db->commit();

            if ($result) {
                do_action('qls_shop_product_deleted', $id);
                $this->bump_cache_version();
            }

            return $result !== false;
        } catch (Exception $e) {
            $this->db->rollback();
            return false;
        }
    }

    /**
     * 获取商品
     *
     * @param int  $id          商品ID
     * @param bool $with_skus   是否包含SKU
     * @param bool $with_attrs  是否包含规格
     * @return object|null
     */
    public function get($id, $with_skus = false, $with_attrs = false) {
        $id = (int) $id;
        $cache_key = $this->build_cache_key('product_single', [
            'id' => $id,
            'with_skus' => (int) $with_skus,
            'with_attrs' => (int) $with_attrs,
        ]);

        if ($this->is_cache_enabled()) {
            $cached = wp_cache_get($cache_key, 'qls_shop');
            if ($cached !== false) {
                if (!$cached) {
                    return null;
                }

                return $this->hydrate_product_media($cached, $with_skus, $with_attrs);
            }
        }

        $product = $this->db->get_by_id('products', $id);

        if (!$product) {
            if ($this->is_cache_enabled()) {
                wp_cache_set($cache_key, 0, 'qls_shop', 30);
            }
            return null;
        }

        // 解码JSON字段
        $product = $this->decode_product($product);

        if ($with_skus) {
            $product->skus = $this->get_skus($id);
        }

        if ($with_attrs) {
            $product->attributes = $this->get_attributes($id);
        }

        $product = $this->hydrate_product_media($product, $with_skus, $with_attrs);

        if ($this->is_cache_enabled()) {
            wp_cache_set($cache_key, $product, 'qls_shop', $this->get_cache_ttl('single'));
        }

        return $product;
    }

    /**
     * 根据slug获取商品
     *
     * @param string $slug
     * @return object|null
     */
    public function get_by_slug($slug) {
        $slug = sanitize_title($slug);
        $cache_key = $this->build_cache_key('product_slug', ['slug' => $slug]);

        if ($this->is_cache_enabled()) {
            $cached = wp_cache_get($cache_key, 'qls_shop');
            if ($cached !== false) {
                if (!$cached) {
                    return null;
                }

                return $this->hydrate_product_media($cached);
            }
        }

        $product = $this->db->get_row('products', ['slug' => $slug]);

        if (!$product) {
            if ($this->is_cache_enabled()) {
                wp_cache_set($cache_key, 0, 'qls_shop', 30);
            }
            return null;
        }

        $product = $this->decode_product($product);
        $product = $this->hydrate_product_media($product);
        if ($this->is_cache_enabled()) {
            wp_cache_set($cache_key, $product, 'qls_shop', $this->get_cache_ttl('single'));
        }

        return $product;
    }

    /**
     * 获取商品列表
     *
     * @param array $args 查询参数
     * @return array
     */
    public function get_list($args = []) {
        $defaults = [
            'status'      => '',
            'category_id' => '',
            'keyword'     => '',
            'is_hot'      => '',
            'product_type' => '',
            'virtual_type' => '',
            'new_user_special' => '',
            'activity_recommend' => '',
            'group_display' => '',
            'assist_display' => '',
            'points_payable' => '',
            'orderby'     => 'id',
            'order'       => 'DESC',
            'limit'       => 20,
            'offset'      => 0,
            'include'     => [], // 支持 ID 列表
        ];

        $args = wp_parse_args($args, $defaults);
        $args['orderby'] = $this->sanitize_orderby($args['orderby']);
        $args['order'] = $this->sanitize_order($args['order']);
        if ($args['orderby'] === 'points_price') {
            $args['points_payable'] = 1;
        }
        $args['limit'] = (int) $args['limit'];
        $args['offset'] = (int) $args['offset'];
        if (is_array($args['include'])) {
            $args['include'] = array_values(array_unique(array_filter(array_map('intval', $args['include']))));
            sort($args['include']);
        }

        $cache_key = $this->build_cache_key('product_list', $args);
        if ($this->is_cache_enabled()) {
            $cached = wp_cache_get($cache_key, 'qls_shop');
            if ($cached !== false) {
                return array_map([$this, 'hydrate_product_media'], (array) $cached);
            }
        }

        if ($this->is_points_payable_filter_enabled($args)) {
            $products = $this->get_points_payable_list($args);
            $products = array_map([$this, 'decode_product'], $products);
            $products = array_map([$this, 'hydrate_product_media'], $products);

            if ($this->is_cache_enabled()) {
                wp_cache_set($cache_key, $products, 'qls_shop', $this->get_cache_ttl('list'));
            }

            return $products;
        }

        global $wpdb;
        $table = $this->db->get_table('products');
        $conditions = [];
        $params = [];
        $this->append_product_sql_conditions($conditions, $params, $args, 'p');

        $select_fields = $this->get_list_select_fields('p');
        $sql = "SELECT {$select_fields} FROM {$table} p";
        if (!empty($conditions)) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $orderby = $this->get_standard_orderby_expression($args['orderby'], 'p');
        $sql .= " ORDER BY {$orderby} {$args['order']}, p.`id` DESC";

        if ($args['limit'] > 0) {
            $sql .= $wpdb->prepare(' LIMIT %d OFFSET %d', $args['limit'], $args['offset']);
        }

        $products = $wpdb->get_results(!empty($params) ? $wpdb->prepare($sql, $params) : $sql);

        $products = array_map([$this, 'decode_product'], $products);
        $products = array_map([$this, 'hydrate_product_media'], $products);

        if ($this->is_cache_enabled()) {
            wp_cache_set($cache_key, $products, 'qls_shop', $this->get_cache_ttl('list'));
        }

        return $products;
    }

    /**
     * 获取商品总数
     *
     * @param array $args 查询参数
     * @return int
     */
    public function get_count($args = []) {
        $defaults = [
            'status'      => '',
            'category_id' => '',
            'keyword'     => '',
            'is_hot'      => '',
            'product_type' => '',
            'virtual_type' => '',
            'new_user_special' => '',
            'activity_recommend' => '',
            'group_display' => '',
            'assist_display' => '',
            'points_payable' => '',
            'include'     => [],
        ];
        $args = wp_parse_args($args, $defaults);
        if (is_array($args['include'])) {
            $args['include'] = array_values(array_unique(array_filter(array_map('intval', $args['include']))));
            sort($args['include']);
        }

        $cache_key = $this->build_cache_key('product_count', $args);
        if ($this->is_cache_enabled()) {
            $cached = wp_cache_get($cache_key, 'qls_shop');
            if ($cached !== false) {
                return (int) $cached;
            }
        }

        if ($this->is_points_payable_filter_enabled($args)) {
            $count = $this->get_points_payable_count($args);

            if ($this->is_cache_enabled()) {
                wp_cache_set($cache_key, $count, 'qls_shop', $this->get_cache_ttl('list'));
            }

            return $count;
        }

        $where = [];

        if (isset($args['status']) && $args['status'] !== '') {
            $where['status'] = $args['status'];
        }

        if (!empty($args['category_id'])) {
            $where['category_id'] = $args['category_id'];
        }

        if (!empty($args['keyword'])) {
            global $wpdb;
            $keyword = (string) $args['keyword'];
            $table = $this->db->get_table('products');
            $like = '%' . $wpdb->esc_like($keyword) . '%';

            $conditions = ["(title LIKE %s OR subtitle LIKE %s)"];
            $params = [$like, $like];

            if (isset($args['status']) && $args['status'] !== '') {
                $conditions[] = "status = %d";
                $params[] = (int) $args['status'];
            }
            if (!empty($args['category_id'])) {
                $conditions[] = "category_id = %d";
                $params[] = (int) $args['category_id'];
            }
            if (isset($args['is_hot']) && $args['is_hot'] !== '') {
                $conditions[] = "is_hot = %d";
                $params[] = (int) $args['is_hot'];
            }
            $this->append_product_sql_conditions($conditions, $params, [
                'product_type' => $args['product_type'] ?? '',
                'virtual_type' => $args['virtual_type'] ?? '',
            ]);
            if ($args['new_user_special'] !== '') {
                $conditions[] = "new_user_special_enabled = %d";
                $params[] = (int) $args['new_user_special'] > 0 ? 1 : 0;
                if ((int) $args['new_user_special'] > 0) {
                    $conditions[] = "new_user_special_price > %f";
                    $params[] = 0;
                }
            }
            $this->apply_decoration_flag_conditions($conditions, $params, $args);
            if (!empty($args['include'])) {
                $placeholders = implode(',', array_fill(0, count($args['include']), '%d'));
                $conditions[] = "id IN ({$placeholders})";
                $params = array_merge($params, $args['include']);
            }

            $sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode(' AND ', $conditions);
            $count = (int) $wpdb->get_var($wpdb->prepare($sql, $params));
        } else {
            if (isset($args['is_hot']) && $args['is_hot'] !== '') {
                $where['is_hot'] = $args['is_hot'];
            }
            $columns_map = $this->get_product_columns_map();
            if (!empty($args['product_type']) && isset($columns_map['product_type'])) {
                $product_type = sanitize_key((string) $args['product_type']);
                if (in_array($product_type, ['physical', 'virtual'], true)) {
                    $where['product_type'] = $product_type;
                }
            }
            if (!empty($args['virtual_type']) && isset($columns_map['virtual_type'])) {
                $virtual_type = sanitize_key((string) $args['virtual_type']);
                if (in_array($virtual_type, ['download', 'card', 'custom'], true)) {
                    $where['virtual_type'] = $virtual_type;
                }
            }
            if ($args['new_user_special'] !== '') {
                $where['new_user_special_enabled'] = (int) $args['new_user_special'] > 0 ? 1 : 0;
                if ((int) $args['new_user_special'] > 0) {
                    $where['new_user_special_price'] = ['operator' => '>', 'value' => 0];
                }
            }
            $this->apply_decoration_flag_where($where, $args);
            if (!empty($args['include'])) {
                $where['id'] = $args['include'];
            }
            $count = (int) $this->db->count('products', $where);
        }

        if ($this->is_cache_enabled()) {
            wp_cache_set($cache_key, $count, 'qls_shop', $this->get_cache_ttl('list'));
        }

        return $count;
    }

    /**
     * 获取商品状态统计，减少后台列表页重复计数查询。
     *
     * @return array<int,int>
     */
    public function get_status_counts() {
        $cache_key = $this->build_cache_key('product_status_counts', []);
        if ($this->is_cache_enabled()) {
            $cached = wp_cache_get($cache_key, 'qls_shop');
            if ($cached !== false) {
                return is_array($cached) ? $cached : [];
            }
        }

        $wpdb = $this->db->get_wpdb();
        $table = $this->db->get_table('products');
        $rows = $wpdb->get_results("SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status");

        $counts = [];
        foreach ((array) $rows as $row) {
            $counts[(int) ($row->status ?? 0)] = (int) ($row->total ?? 0);
        }

        if ($this->is_cache_enabled()) {
            wp_cache_set($cache_key, $counts, 'qls_shop', $this->get_cache_ttl('list'));
        }

        return $counts;
    }

    /**
     * 获取商品的SKU列表
     *
     * @param int $product_id
     * @return array
     */
    public function get_skus($product_id) {
        $skus = $this->db->get_results('product_skus', [
            'where'   => ['product_id' => $product_id],
            'orderby' => 'is_default',
            'order'   => 'DESC',
        ]);

        $skus = array_map(function($sku) {
            if (!empty($sku->attr_values)) {
                $sku->attr_values = json_decode($sku->attr_values, true);
            }
            if (!empty($sku->image)) {
                $sku->image = qilingshop_normalize_media_url($sku->image);
            }
            return $sku;
        }, $skus);

        $this->attach_sku_vip_prices($skus);

        return $skus;
    }

    /**
     * 获取单个SKU
     *
     * @param int $sku_id
     * @return object|null
     */
    public function get_sku($sku_id) {
        $sku = $this->db->get_by_id('product_skus', $sku_id);
        
        if ($sku && !empty($sku->attr_values)) {
            $sku->attr_values = json_decode($sku->attr_values, true);
        }

        if ($sku && !empty($sku->image)) {
            $sku->image = qilingshop_normalize_media_url($sku->image);
        }

        if ($sku) {
            $sku->vip_prices = $this->get_sku_vip_prices((int) $sku->id);
        }
        
        return $sku;
    }

    /**
     * 批量获取 SKU，避免高频列表页逐条查询。
     *
     * @param array $sku_ids
     * @return array<int, object>
     */
    public function get_skus_by_ids($sku_ids) {
        global $wpdb;

        $sku_ids = array_values(array_unique(array_filter(array_map('intval', (array) $sku_ids))));
        if (empty($sku_ids)) {
            return [];
        }

        if (count($sku_ids) === 1) {
            $sku = $this->get_sku($sku_ids[0]);
            return $sku ? [$sku_ids[0] => $sku] : [];
        }

        $table = $this->db->get_table('product_skus');
        $placeholders = implode(',', array_fill(0, count($sku_ids), '%d'));
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id IN ({$placeholders})",
            $sku_ids
        );
        $skus = $wpdb->get_results($sql);
        if (empty($skus)) {
            return [];
        }

        foreach ($skus as $sku) {
            if (!empty($sku->attr_values)) {
                $sku->attr_values = json_decode($sku->attr_values, true);
            }
            if (!empty($sku->image)) {
                $sku->image = qilingshop_normalize_media_url($sku->image);
            }
        }

        $this->attach_sku_vip_prices($skus);

        $sku_map = [];
        foreach ($skus as $sku) {
            $sku_id = isset($sku->id) ? (int) $sku->id : 0;
            if ($sku_id > 0) {
                $sku_map[$sku_id] = $sku;
            }
        }

        return $sku_map;
    }

    /**
     * 获取 SKU 会员价。
     *
     * @param int $sku_id
     * @return array<int,float>
     */
    public function get_sku_vip_prices($sku_id) {
        $sku_id = absint($sku_id);
        if ($sku_id <= 0) {
            return [];
        }

        $rows = $this->db->get_results('product_sku_vip_prices', [
            'where'   => ['sku_id' => $sku_id],
            'orderby' => 'level_id',
            'order'   => 'ASC',
            'limit'   => -1,
        ]);

        $prices = [];
        foreach ($rows as $row) {
            $level_id = isset($row->level_id) ? (int) $row->level_id : 0;
            $price = isset($row->price) ? round((float) $row->price, 2) : 0;
            if ($level_id > 0 && $price > 0) {
                $prices[$level_id] = $price;
            }
        }

        return $prices;
    }

    /**
     * 保存 SKU 会员价。
     *
     * @param int   $sku_id
     * @param array $vip_prices
     * @return bool
     */
    public function save_sku_vip_prices($sku_id, $vip_prices) {
        $sku_id = absint($sku_id);
        if ($sku_id <= 0) {
            return false;
        }

        $prices = $this->normalize_sku_vip_prices($vip_prices);
        $this->delete_sku_vip_prices($sku_id);

        foreach ($prices as $level_id => $price) {
            $inserted = $this->db->insert('product_sku_vip_prices', [
                'sku_id'   => $sku_id,
                'level_id' => (int) $level_id,
                'price'    => (float) $price,
            ]);

            if (!$inserted) {
                return false;
            }
        }

        $this->bump_cache_version();
        return true;
    }

    /**
     * 删除 SKU 会员价。
     *
     * @param int $sku_id
     * @return bool
     */
    public function delete_sku_vip_prices($sku_id) {
        $sku_id = absint($sku_id);
        if ($sku_id <= 0) {
            return false;
        }

        return $this->db->delete('product_sku_vip_prices', ['sku_id' => $sku_id]) !== false;
    }

    /**
     * 批量给 SKU 附加会员价。
     *
     * @param array $skus
     */
    private function attach_sku_vip_prices(&$skus) {
        if (empty($skus) || !is_array($skus)) {
            return;
        }

        $sku_ids = [];
        foreach ($skus as $sku) {
            if (is_object($sku) && !empty($sku->id)) {
                $sku_ids[] = (int) $sku->id;
                $sku->vip_prices = [];
            }
        }

        $sku_ids = array_values(array_unique(array_filter($sku_ids)));
        if (empty($sku_ids)) {
            return;
        }

        $rows = $this->db->get_results('product_sku_vip_prices', [
            'where'   => ['sku_id' => ['operator' => 'IN', 'value' => $sku_ids]],
            'orderby' => 'sku_id',
            'order'   => 'ASC',
            'limit'   => -1,
        ]);

        $grouped = [];
        foreach ($rows as $row) {
            $sku_id = isset($row->sku_id) ? (int) $row->sku_id : 0;
            $level_id = isset($row->level_id) ? (int) $row->level_id : 0;
            $price = isset($row->price) ? round((float) $row->price, 2) : 0;
            if ($sku_id > 0 && $level_id > 0 && $price > 0) {
                if (!isset($grouped[$sku_id])) {
                    $grouped[$sku_id] = [];
                }
                $grouped[$sku_id][$level_id] = $price;
            }
        }

        foreach ($skus as $sku) {
            if (is_object($sku) && !empty($sku->id)) {
                $sku->vip_prices = $grouped[(int) $sku->id] ?? [];
            }
        }
    }

    /**
     * 规范化会员价输入。
     *
     * @param mixed $vip_prices
     * @return array<int,float>
     */
    private function normalize_sku_vip_prices($vip_prices) {
        if (!is_array($vip_prices)) {
            return [];
        }

        $normalized = [];
        foreach ($vip_prices as $level_id => $price) {
            $level_id = absint($level_id);
            $price = round(max(0, (float) $price), 2);
            if ($level_id > 0 && $price > 0) {
                $normalized[$level_id] = $price;
            }
        }

        return $normalized;
    }

    /**
     * 添加/更新SKU
     *
     * @param int   $product_id
     * @param array $sku_data
     * @return int|false
     */
    public function save_sku($product_id, $sku_data) {
        $vip_prices = isset($sku_data['vip_prices']) ? $sku_data['vip_prices'] : [];
        unset($sku_data['vip_prices']);

        $defaults = [
            'product_id'   => $product_id,
            'sku_code'     => '',
            'attr_values'  => '{}',
            'image'        => '',
            'price'        => 0,
            'sale_price'   => null,
            'points_price' => null,
            'stock'        => 0,
            'weight'       => 0,
            'is_default'   => 0,
            'status'       => 1,
        ];

        $sku_data = wp_parse_args($sku_data, $defaults);
        $sku_data['product_id'] = $product_id;

        // JSON编码
        if (is_array($sku_data['attr_values'])) {
            $sku_data['attr_values'] = wp_json_encode($sku_data['attr_values']);
        }

        // 自动生成SKU编码（如果为空）
        if (empty($sku_data['sku_code'])) {
            $sku_data['sku_code'] = 'SKU' . strtoupper(substr(md5(uniqid($product_id . microtime(), true)), 0, 10));
        }

        if (isset($sku_data['id']) && $sku_data['id'] > 0) {
            $sku_id = $sku_data['id'];
            unset($sku_data['id']);
            $this->db->update('product_skus', $sku_data, ['id' => $sku_id]);
            $this->save_sku_vip_prices($sku_id, $vip_prices);
            $this->bump_cache_version();
            return $sku_id;
        } else {
            unset($sku_data['id']);
            $new_id = $this->db->insert('product_skus', $sku_data);
            if ($new_id) {
                $this->save_sku_vip_prices($new_id, $vip_prices);
                $this->bump_cache_version();
            }
            return $new_id;
        }
    }

    /**
     * 删除SKU
     *
     * @param int $sku_id
     * @return bool
     */
    public function delete_sku($sku_id) {
        $this->delete_sku_vip_prices($sku_id);
        $result = $this->db->delete('product_skus', ['id' => $sku_id]) !== false;
        if ($result) {
            $this->bump_cache_version();
        }
        return $result;
    }

    /**
     * 获取商品规格
     *
     * @param int $product_id
     * @return array
     */
    public function get_attributes($product_id) {
        $attributes = $this->db->get_results('product_attributes', [
            'where'   => ['product_id' => $product_id],
            'orderby' => 'sort_order',
            'order'   => 'ASC',
        ]);

        foreach ($attributes as &$attr) {
            $attr->values = $this->db->get_results('product_attribute_values', [
                'where'   => ['attribute_id' => $attr->id],
                'orderby' => 'sort_order',
                'order'   => 'ASC',
            ]);
            
            // 解码versions字段
            foreach ($attr->values as &$val) {
                if (!empty($val->versions)) {
                    $val->versions = json_decode($val->versions, true) ?: [];
                } else {
                    $val->versions = [];
                }

                if (!empty($val->image)) {
                    $val->image = qilingshop_normalize_media_url($val->image);
                }
            }
        }

        return $attributes;
    }

    /**
     * 保存商品规格
     *
     * @param int   $product_id
     * @param array $attributes 规格数据
     * @return bool
     */
    public function save_attributes($product_id, $attributes) {
        // 获取现有规格
        $existing = $this->db->get_results('product_attributes', [
            'where' => ['product_id' => $product_id],
            'fields' => 'id'
        ]);
        $existing_ids = array_column($existing, 'id');

        $new_ids = [];

        foreach ($attributes as $attr) {
            $attr_data = [
                'product_id' => $product_id,
                'name'       => $attr['name'],
                'sort_order' => isset($attr['sort_order']) ? $attr['sort_order'] : 0,
            ];

            if (isset($attr['id']) && $attr['id'] > 0) {
                // 更新现有规格
                $attr_id = $attr['id'];
                $this->db->update('product_attributes', $attr_data, ['id' => $attr_id]);
                $new_ids[] = $attr_id;
            } else {
                // 创建新规格
                $attr_id = $this->db->insert('product_attributes', $attr_data);
                $new_ids[] = $attr_id;
            }

            // 保存规格值
            if (isset($attr['values']) && is_array($attr['values'])) {
                $this->save_attribute_values($attr_id, $attr['values']);
            }
        }

        // 删除不再使用的规格
        $to_delete = array_diff($existing_ids, $new_ids);
        foreach ($to_delete as $attr_id) {
            $this->db->delete('product_attribute_values', ['attribute_id' => $attr_id]);
            $this->db->delete('product_attributes', ['id' => $attr_id]);
        }

        $this->bump_cache_version();
        return true;
    }

    /**
     * 保存规格值
     *
     * @param int   $attribute_id
     * @param array $values
     * @return bool
     */
    private function save_attribute_values($attribute_id, $values) {
        // 获取现有值
        $existing = $this->db->get_results('product_attribute_values', [
            'where' => ['attribute_id' => $attribute_id],
            'fields' => 'id'
        ]);
        $existing_ids = array_column($existing, 'id');

        $new_ids = [];

        foreach ($values as $val) {
            // 处理versions数组，转为JSON存储
            $versions = '';
            if (isset($val['versions']) && is_array($val['versions'])) {
                $versions = wp_json_encode(array_filter($val['versions']));
            }
            
            $val_data = [
                'attribute_id' => $attribute_id,
                'value'        => $val['value'],
                'image'        => isset($val['image']) ? $val['image'] : '',
                'versions'     => $versions,
                'sort_order'   => isset($val['sort_order']) ? $val['sort_order'] : 0,
            ];

            if (isset($val['id']) && $val['id'] > 0) {
                $val_id = $val['id'];
                $this->db->update('product_attribute_values', $val_data, ['id' => $val_id]);
                $new_ids[] = $val_id;
            } else {
                $val_id = $this->db->insert('product_attribute_values', $val_data);
                $new_ids[] = $val_id;
            }
        }

        // 删除不再使用的值
        $to_delete = array_diff($existing_ids, $new_ids);
        foreach ($to_delete as $val_id) {
            $this->db->delete('product_attribute_values', ['id' => $val_id]);
        }

        return true;
    }

    /**
     * 更新商品库存和价格（根据SKU汇总）
     *
     * @param int $product_id
     * @return bool
     */
    public function sync_product_stats($product_id) {
        $skus = $this->get_skus($product_id);

        if (empty($skus)) {
            return $this->update($product_id, [
                'min_price'   => 0,
                'max_price'   => 0,
                'total_stock' => 0,
            ]);
        }

        $prices = [];
        $total_stock = 0;

        foreach ($skus as $sku) {
            $price = $sku->sale_price > 0 ? $sku->sale_price : $sku->price;
            $prices[] = $price;
            $total_stock += $sku->stock;
        }

        return $this->update($product_id, [
            'min_price'   => min($prices),
            'max_price'   => max($prices),
            'total_stock' => $total_stock,
        ]);
    }

    /**
     * 更新SKU库存
     *
     * @param int    $sku_id
     * @param int    $quantity  数量
     * @param string $operation 操作：add/reduce/set
     * @return bool
     */
    /**
     * 更新SKU库存 (Atomic Update)
     *
     * @param int    $sku_id
     * @param int    $quantity  数量
     * @param string $operation 操作：add/reduce/set
     * @return bool
     */
    public function update_sku_stock($sku_id, $quantity, $operation = 'reduce', $sync_stats = true) {
        $table = $this->db->get_table('product_skus');
        
        switch ($operation) {
            case 'add':
                $sql = $this->db->prepare(
                    "UPDATE {$table} SET stock = stock + %d WHERE id = %d",
                    $quantity,
                    $sku_id
                );
                $result = $this->db->query($sql);
                if ($result !== false) {
                    if ($sync_stats) {
                        $this->sync_product_stats_by_sku($sku_id);
                        $this->bump_cache_version();
                    }
                }
                return $result !== false;
                
            case 'reduce':
                // 使用 WHERE stock >= quantity 防止超卖
                $sql = $this->db->prepare(
                    "UPDATE {$table} SET stock = stock - %d WHERE id = %d AND stock >= %d",
                    $quantity,
                    $sku_id,
                    $quantity
                );
                $result = $this->db->query($sql);
                // query返回影响行数，如果为0说明库存不足或ID不存在
                if ($result === 0) {
                     return false;
                }
                if ($result !== false) {
                    if ($sync_stats) {
                        $this->sync_product_stats_by_sku($sku_id);
                        $this->bump_cache_version();
                    }
                }
                return $result !== false;

            case 'set':
                $new_stock = max(0, $quantity);
                $sql = $this->db->prepare(
                    "UPDATE {$table} SET stock = %d WHERE id = %d",
                    $new_stock,
                    $sku_id
                );
                $result = $this->db->query($sql);
                if ($result !== false) {
                    if ($sync_stats) {
                        $this->sync_product_stats_by_sku($sku_id);
                        $this->bump_cache_version();
                    }
                }
                return $result !== false;
                
            default:
                return false;
        }
    }

    /**
     * 辅助方法：通过SKU ID同步商品统计
     */
    private function sync_product_stats_by_sku($sku_id) {
         $sku = $this->get_sku($sku_id);
         if ($sku) {
             $this->sync_product_stats($sku->product_id);
         }
    }

    /**
     * 根据 SKU ID 列表批量同步商品聚合库存/价格。
     *
     * @param array $sku_ids
     * @return void
     */
    public function sync_product_stats_by_skus($sku_ids) {
        $sku_ids = array_values(array_unique(array_filter(array_map('intval', (array) $sku_ids))));
        if (empty($sku_ids)) {
            return;
        }

        $product_ids = [];
        foreach ($sku_ids as $sku_id) {
            $sku = $this->get_sku($sku_id);
            if ($sku && !empty($sku->product_id)) {
                $product_ids[] = (int) $sku->product_id;
            }
        }

        $product_ids = array_values(array_unique(array_filter($product_ids)));
        foreach ($product_ids as $product_id) {
            $this->sync_product_stats($product_id);
        }

        if (!empty($product_ids)) {
            $this->bump_cache_version();
        }
    }

    /**
     * 增加商品浏览次数
     *
     * @param int $product_id
     * @return bool
     */
    public function increment_view_count($product_id) {
        $table = $this->db->get_table('products');
        $sql = $this->db->prepare(
            "UPDATE {$table} SET view_count = view_count + 1 WHERE id = %d",
            $product_id
        );
        return $this->db->query($sql) !== false;
    }

    /**
     * 增加商品销量
     *
     * @param int $product_id
     * @param int $quantity
     * @return bool
     */
    public function increment_sales_count($product_id, $quantity = 1) {
        $table = $this->db->get_table('products');
        $sql = $this->db->prepare(
            "UPDATE {$table} SET sales_count = sales_count + %d WHERE id = %d",
            $quantity,
            $product_id
        );
        return $this->db->query($sql) !== false;
    }

    /**
     * 生成唯一slug
     *
     * @param string $title
     * @return string
     */
    private function generate_slug($title) {
        $slug = sanitize_title($title);
        
        if (empty($slug)) {
            $slug = 'product-' . time();
        }

        return $this->ensure_unique_slug($slug);
    }

    /**
     * 确保slug唯一
     *
     * @param string $slug
     * @param int    $exclude_id 排除的ID
     * @return string
     */
    private function ensure_unique_slug($slug, $exclude_id = 0) {
        $original_slug = $slug;
        $max_retries = max(1, (int) apply_filters('qls_shop_product_slug_max_retries', 100, $original_slug, $exclude_id));

        for ($counter = 0; $counter <= $max_retries; $counter++) {
            $candidate = $counter === 0 ? $original_slug : $original_slug . '-' . $counter;
            $existing = $this->db->get_row('products', ['slug' => $candidate]);

            if (!$existing || ($exclude_id > 0 && (int) $existing->id === (int) $exclude_id)) {
                return $candidate;
            }
        }

        $fallback_base = $original_slug . '-' . time();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = $fallback_base . '-' . wp_rand(1000, 9999);
            $existing = $this->db->get_row('products', ['slug' => $candidate]);

            if (!$existing || ($exclude_id > 0 && (int) $existing->id === (int) $exclude_id)) {
                return $candidate;
            }
        }

        return $fallback_base . '-' . wp_rand(10000, 99999);
    }

    /**
     * 解码商品JSON字段
     *
     * @param object $product
     * @return object
     */
    private function decode_product($product) {
        if (!empty($product->main_image)) {
            $decoded = json_decode($product->main_image, true);
            $product->main_image = $decoded !== null ? $decoded : $product->main_image;
        }
        if (!empty($product->gallery)) {
            $product->gallery = json_decode($product->gallery, true) ?: [];
        }
        if (!empty($product->content)) {
            $product->content = qilingshop_normalize_media_markup_urls((string) $product->content);
        }
        if (!empty($product->params)) {
            $product->params = json_decode($product->params, true) ?: [];
        }
        if (!empty($product->service_tags)) {
            $product->service_tags = json_decode($product->service_tags, true) ?: [];
        }
        if (!empty($product->virtual_content)) {
            $product->virtual_content = json_decode($product->virtual_content, true) ?: [];
        }
        $product->new_user_special_enabled = isset($product->new_user_special_enabled)
            ? (int) $product->new_user_special_enabled
            : 0;
        $product->new_user_special_price = isset($product->new_user_special_price)
            ? (float) $product->new_user_special_price
            : 0.0;
        foreach ($this->get_decoration_flag_columns() as $column) {
            $product->{$column} = isset($product->{$column}) ? (int) $product->{$column} : 0;
        }

        return $product;
    }

    /**
     * 运行时规范商品媒体地址，避免换域名后仍输出旧 uploads 域名。
     *
     * @param object $product 商品对象。
     * @param bool   $with_skus 是否携带 SKU。
     * @param bool   $with_attrs 是否携带规格。
     * @return object
     */
    private function hydrate_product_media($product, $with_skus = false, $with_attrs = false) {
        if (!is_object($product)) {
            return $product;
        }

        if (!empty($product->main_image)) {
            $product->main_image = qilingshop_normalize_media_value($product->main_image);
        }

        if (!empty($product->gallery)) {
            $product->gallery = qilingshop_normalize_media_value($product->gallery);
        }

        if (!empty($product->content)) {
            $product->content = qilingshop_normalize_media_markup_urls((string) $product->content);
        }

        if ($with_skus && !empty($product->skus) && is_array($product->skus)) {
            $product->skus = array_map(function($sku) {
                if (is_object($sku) && !empty($sku->image)) {
                    $sku->image = qilingshop_normalize_media_url($sku->image);
                }

                return $sku;
            }, $product->skus);
        }

        if ($with_attrs && !empty($product->attributes) && is_array($product->attributes)) {
            foreach ($product->attributes as &$attr) {
                if (empty($attr->values) || !is_array($attr->values)) {
                    continue;
                }

                foreach ($attr->values as &$val) {
                    if (is_object($val) && !empty($val->image)) {
                        $val->image = qilingshop_normalize_media_url($val->image);
                    }
                }
                unset($val);
            }
            unset($attr);
        }

        return $product;
    }

    /**
     * 是否开启新人专项活动
     *
     * @param int|object $product 商品ID或商品对象
     * @return bool
     */
    public function is_new_user_special_enabled($product) {
        if (is_numeric($product)) {
            $product = $this->get((int) $product);
        }

        if (!$product) {
            return false;
        }

        return !empty($product->new_user_special_enabled) && $this->get_new_user_special_price($product) > 0;
    }

    /**
     * 获取新人专项价
     *
     * @param int|object $product 商品ID或商品对象
     * @return float
     */
    public function get_new_user_special_price($product) {
        if (is_numeric($product)) {
            $product = $this->get((int) $product);
        }

        if (!$product) {
            return 0.0;
        }

        $price = isset($product->new_user_special_price) ? (float) $product->new_user_special_price : 0;
        return $price > 0 ? round($price, 2) : 0.0;
    }

    /**
     * 判断用户是否具备新人专项资格（首个商城支付订单前）
     *
     * @param int $user_id
     * @return bool
     */
    public function is_user_eligible_for_new_user_special($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return false;
        }

        static $eligibility_cache = [];
        if (array_key_exists($user_id, $eligibility_cache)) {
            return $eligibility_cache[$user_id];
        }

        if (!class_exists('QLS_Coupon') && defined('QILINGSHOP_PATH')) {
            $coupon_file = QILINGSHOP_PATH . 'includes/shop/class-qls-coupon.php';
            if (file_exists($coupon_file)) {
                require_once $coupon_file;
            }
        }

        if (!class_exists('QLS_Coupon')) {
            $eligibility_cache[$user_id] = false;
            return false;
        }

        // 约束1：一旦产生过商城已支付订单（含退款状态）即不再是新人。
        $eligible = !QLS_Coupon::user_has_paid_order($user_id, 'shop');

        // 约束2：存在新人专项待处理订单时，后续订单不再按新人价结算（可按常规价格购买）。
        if ($eligible && class_exists('QLS_Shop_Order') && function_exists('qls_shop_order')) {
            $eligible = !qls_shop_order()->has_new_user_special_order($user_id);
        }

        $eligibility_cache[$user_id] = $eligible;
        return $eligibility_cache[$user_id];
    }

    /**
     * 获取用于定价的用户 VIP 等级。
     *
     * @param int $user_id
     * @return int
     */
    public function get_user_vip_level_for_pricing($user_id = 0) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return 0;
        }

        if (class_exists('QilingShop_VIP')) {
            $vip = QilingShop_VIP::instance();
            if (method_exists($vip, 'get_user_level_for_pricing')) {
                return (int) $vip->get_user_level_for_pricing($user_id);
            }
            if (method_exists($vip, 'get_user_level')) {
                return (int) $vip->get_user_level($user_id);
            }
        }

        if (function_exists('qilingshop_get_user_vip_level')) {
            return (int) qilingshop_get_user_vip_level($user_id);
        }

        return 0;
    }

    /**
     * 获取当前用户可用的 SKU 会员价。
     *
     * @param int|object $sku
     * @param int        $user_id
     * @return array{price: float, level_id: int}
     */
    public function get_user_sku_vip_price($sku, $user_id = 0) {
        if (is_numeric($sku)) {
            $sku = $this->get_sku((int) $sku);
        }

        if (!$sku || empty($sku->id)) {
            return ['price' => 0.0, 'level_id' => 0];
        }

        $level_id = $this->get_user_vip_level_for_pricing($user_id);
        if ($level_id <= 0) {
            return ['price' => 0.0, 'level_id' => 0];
        }

        $vip_prices = isset($sku->vip_prices) && is_array($sku->vip_prices)
            ? $sku->vip_prices
            : $this->get_sku_vip_prices((int) $sku->id);

        $price = isset($vip_prices[$level_id]) ? round((float) $vip_prices[$level_id], 2) : 0;

        return [
            'price'    => $price > 0 ? $price : 0.0,
            'level_id' => $level_id,
        ];
    }

    /**
     * 计算 SKU 对当前用户的结算单价（含会员价、新人专项价）
     *
     * @param int|object $product 商品ID或商品对象
     * @param int|object $sku SKU ID 或 SKU 对象
     * @param int $user_id 用户ID
     * @return array{price: float, base_price: float, is_new_user_special: bool, new_user_special_price: float, is_vip_price: bool, vip_price: float, vip_level_id: int, price_source: string}
     */
    public function get_effective_sku_price($product, $sku, $user_id = 0) {
        if (is_numeric($product)) {
            $product = $this->get((int) $product);
        }
        if (is_numeric($sku)) {
            $sku = $this->get_sku((int) $sku);
        }

        if (!$product || !$sku) {
            return [
                'price' => 0.0,
                'base_price' => 0.0,
                'is_new_user_special' => false,
                'new_user_special_price' => 0.0,
                'is_vip_price' => false,
                'vip_price' => 0.0,
                'vip_level_id' => 0,
                'price_source' => 'base',
            ];
        }

        $base_price = ((float) $sku->sale_price > 0) ? (float) $sku->sale_price : (float) $sku->price;
        $base_price = max(0, round($base_price, 2));

        $result = [
            'price' => $base_price,
            'base_price' => $base_price,
            'is_new_user_special' => false,
            'new_user_special_price' => 0.0,
            'is_vip_price' => false,
            'vip_price' => 0.0,
            'vip_level_id' => 0,
            'price_source' => 'base',
        ];

        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return $result;
        }

        $vip_price = $this->get_user_sku_vip_price($sku, $user_id);
        if (!empty($vip_price['price']) && (float) $vip_price['price'] > 0 && (float) $vip_price['price'] < (float) $result['price']) {
            $result['price'] = round((float) $vip_price['price'], 2);
            $result['is_vip_price'] = true;
            $result['vip_price'] = round((float) $vip_price['price'], 2);
            $result['vip_level_id'] = (int) $vip_price['level_id'];
            $result['price_source'] = 'vip';
        }

        if (!$this->is_new_user_special_enabled($product) || !$this->is_user_eligible_for_new_user_special($user_id)) {
            return $result;
        }

        $special_price = $this->get_new_user_special_price($product);
        if ($special_price > 0 && $special_price <= (float) $result['price']) {
            $result['price'] = $special_price;
            $result['is_new_user_special'] = true;
            $result['new_user_special_price'] = $special_price;
            $result['is_vip_price'] = false;
            $result['price_source'] = 'new_user_special';
        }

        return $result;
    }

    /**
     * 判断商品是否为虚拟商品
     *
     * @param int|object $product 商品ID或商品对象
     * @return bool
     */
    public function is_virtual($product) {
        if (is_numeric($product)) {
            $product = $this->get($product);
        }
        
        if (!$product) {
            return false;
        }
        
        return isset($product->product_type) && $product->product_type === 'virtual';
    }

    /**
     * 获取虚拟商品内容
     *
     * @param int|object $product 商品ID或商品对象
     * @return array|null
     */
    public function get_virtual_content($product) {
        if (is_numeric($product)) {
            $product = $this->get($product);
        }
        
        if (!$product || !$this->is_virtual($product)) {
            return null;
        }
        
        $content = [
            'type' => $product->virtual_type ?? '',
            'data' => $product->virtual_content ?? [],
        ];
        
        return $content;
    }

    /**
     * 获取虚拟商品类型文本
     *
     * @param string $type
     * @return string
     */
    public function get_virtual_type_text($type) {
        $types = [
            'download' => __('下载链接', 'qilingshop'),
            'card'     => __('卡号卡密', 'qilingshop'),
            'custom'   => __('自定义内容', 'qilingshop'),
        ];
        
        return $types[$type] ?? $type;
    }

    /**
     * 获取商品显示价格
     *
     * @param int $product_id
     * @return array
     */
    public function get_display_price($product_id) {
        $product = $this->get($product_id);
        
        if (!$product) {
            return null;
        }

        return [
            'min_price' => $product->min_price,
            'max_price' => $product->max_price,
            'is_range'  => $product->min_price != $product->max_price,
        ];
    }

    /**
     * 获取价格范围 (Compatibility)
     *
     * @param int $product_id
     * @return array
     */
    public function get_price_range($product_id) {
        $price = $this->get_display_price($product_id);
        if (!$price) return ['min' => 0, 'max' => 0];
        
        return [
            'min' => $price['min_price'],
            'max' => $price['max_price']
        ];
    }

    /**
     * 获取SKU的实际价格（优先促销价）
     *
     * @param int $sku_id
     * @return float
     */
    public function get_sku_price($sku_id) {
        $sku = $this->get_sku($sku_id);
        
        if (!$sku) {
            return 0;
        }

        return ($sku->sale_price > 0) ? $sku->sale_price : $sku->price;
    }
    /**
     * 获取商品特色标签
     * @param int $product_id
     */
    public function get_tags($product_id) {
        $table_rel = $this->db->get_table('product_tag_relationships');
        $table_tag = $this->db->get_table('tags');
        
        $sql = "SELECT t.* FROM {$table_tag} t 
                INNER JOIN {$table_rel} r ON t.id = r.tag_id 
                WHERE r.product_id = %d";
                
        return $this->db->get_wpdb()->get_results($this->db->prepare($sql, $product_id));
    }

    /**
     * 保存商品特色标签
     * @param int $product_id
     * @param array $tags_names 标签名称数组
     */
    public function save_tags($product_id, $tags_names) {
        // 清空旧关联
        $this->db->delete('product_tag_relationships', ['product_id' => $product_id]);
        
        if (empty($tags_names)) {
            return;
        }

        $tags_names = array_unique(array_filter($tags_names));
        
        foreach ($tags_names as $name) {
            $name = sanitize_text_field(trim($name));
            if (empty($name)) continue;
            
            // 查找或创建标签
            $slug = sanitize_title($name);
            if (empty($slug)) $slug = md5($name); // fallback
            
            $tag = $this->db->get_row('tags', ['slug' => $slug]);
            
            if ($tag) {
                $tag_id = $tag->id;
            } else {
                $tag_id = $this->db->insert('tags', [
                    'name' => $name,
                    'slug' => $slug
                ]);
            }
            
            if ($tag_id) {
                $this->db->insert('product_tag_relationships', [
                    'product_id' => $product_id,
                    'tag_id' => $tag_id
                ]);
            }
        }
    }

}

/**
 * 获取商品类实例的快捷函数
 *
 * @return QLS_Product
 */
function qls_product() {
    return QLS_Product::instance();
}
