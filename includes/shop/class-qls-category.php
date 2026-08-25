<?php
/**
 * 分类管理类
 * 
 * 处理商品分类的CRUD操作
 *
 * @package QilingShop
 * @since   2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_Category {

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
            $version = get_option('qls_shop_category_cache_version', '');
            self::$cache_version = $version !== '' ? (string) $version : '1';
        }

        return self::$cache_version;
    }

    private function bump_cache_version() {
        $new_version = (string) time();
        update_option('qls_shop_category_cache_version', $new_version);
        self::$cache_version = $new_version;
    }

    private function is_cache_enabled() {
        return (bool) apply_filters('qls_shop_category_cache_enabled', true);
    }

    private function get_cache_ttl($type = 'list') {
        $default = 300;
        $ttl = (int) apply_filters('qls_shop_category_cache_ttl', $default, $type);
        return max(30, $ttl);
    }

    private function get_product_cache_version() {
        $version = get_option('qls_shop_product_cache_version', '');
        return $version !== '' ? (string) $version : '1';
    }

    private function build_cache_key($prefix, $args) {
        return 'qls_shop_' . $prefix . ':' . $this->get_cache_version() . ':' . md5(wp_json_encode($args));
    }

    private function sanitize_orderby($orderby) {
        $allowed = ['id', 'sort_order', 'name', 'created_at'];
        return in_array($orderby, $allowed, true) ? $orderby : 'sort_order';
    }

    private function sanitize_order($order) {
        return strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
    }

    /**
     * 创建分类
     *
     * @param array $data
     * @return int|false
     */
    public function create($data) {
        $defaults = [
            'parent_id'   => 0,
            'name'        => '',
            'slug'        => '',
            'image'       => '',
            'description' => '',
            'sort_order'  => 0,
            'status'      => 1,
        ];

        $data = wp_parse_args($data, $defaults);

        // 生成唯一slug
        if (empty($data['slug'])) {
            $data['slug'] = $this->generate_slug($data['name']);
        } else {
            $data['slug'] = $this->ensure_unique_slug($data['slug']);
        }

        $id = $this->db->insert('categories', $data);
        if ($id) {
            $this->bump_cache_version();
        }
        return $id;
    }

    /**
     * 更新分类
     *
     * @param int   $id
     * @param array $data
     * @return bool
     */
    public function update($id, $data) {
        if (isset($data['slug'])) {
            $data['slug'] = $this->ensure_unique_slug($data['slug'], $id);
        }

        $result = $this->db->update('categories', $data, ['id' => $id]) !== false;
        if ($result) {
            $this->bump_cache_version();
        }
        return $result;
    }

    /**
     * 删除分类
     *
     * @param int $id
     * @return bool
     */
    public function delete($id) {
        // 将子分类移到顶级
        $this->db->update('categories', ['parent_id' => 0], ['parent_id' => $id]);
        
        // 将该分类下的商品分类设为0
        $this->db->update('products', ['category_id' => 0], ['category_id' => $id]);
        
        $result = $this->db->delete('categories', ['id' => $id]) !== false;
        if ($result) {
            $this->bump_cache_version();
        }
        return $result;
    }

    /**
     * 获取分类
     *
     * @param int $id
     * @return object|null
     */
    public function get($id) {
        $id = (int) $id;
        $cache_key = $this->build_cache_key('category', ['id' => $id]);
        if ($this->is_cache_enabled()) {
            $cached = wp_cache_get($cache_key, 'qls_shop');
            if ($cached !== false) {
                if (!$cached) {
                    return null;
                }

                return $this->hydrate_category_media($cached);
            }
        }

        $category = $this->db->get_by_id('categories', $id);
        $category = $this->hydrate_category_media($category);
        if ($this->is_cache_enabled()) {
            wp_cache_set($cache_key, $category ?: 0, 'qls_shop', $this->get_cache_ttl('single'));
        }

        return $category;
    }

    /**
     * 根据slug获取分类
     *
     * @param string $slug
     * @return object|null
     */
    public function get_by_slug($slug) {
        $slug = sanitize_title($slug);
        $cache_key = $this->build_cache_key('category_slug', ['slug' => $slug]);
        if ($this->is_cache_enabled()) {
            $cached = wp_cache_get($cache_key, 'qls_shop');
            if ($cached !== false) {
                if (!$cached) {
                    return null;
                }

                return $this->hydrate_category_media($cached);
            }
        }

        $category = $this->db->get_row('categories', ['slug' => $slug]);
        $category = $this->hydrate_category_media($category);
        if ($this->is_cache_enabled()) {
            wp_cache_set($cache_key, $category ?: 0, 'qls_shop', $this->get_cache_ttl('single'));
        }

        return $category;
    }

    /**
     * 获取分类列表
     *
     * @param array $args
     * @return array
     */
    public function get_list($args = []) {
        $defaults = [
            'parent_id' => '',
            'status'    => '',
            'orderby'   => 'sort_order',
            'order'     => 'ASC',
            'limit'     => -1,
        ];

        $args = wp_parse_args($args, $defaults);
        $args['orderby'] = $this->sanitize_orderby($args['orderby']);
        $args['order'] = $this->sanitize_order($args['order']);

        $cache_key = $this->build_cache_key('category_list', $args);
        if ($this->is_cache_enabled()) {
            $cached = wp_cache_get($cache_key, 'qls_shop');
            if ($cached !== false) {
                return $this->hydrate_category_collection($cached);
            }
        }
        $where = [];

        if ($args['parent_id'] !== '') {
            $where['parent_id'] = $args['parent_id'];
        }

        if ($args['status'] !== '') {
            $where['status'] = $args['status'];
        }

        $result = $this->db->get_results('categories', [
            'where'   => $where,
            'orderby' => $args['orderby'],
            'order'   => $args['order'],
            'limit'   => $args['limit'],
        ]);

        $result = array_map([$this, 'hydrate_category_media'], $result);

        if ($this->is_cache_enabled()) {
            wp_cache_set($cache_key, $result, 'qls_shop', $this->get_cache_ttl('list'));
        }

        return $result;
    }

    /**
     * 获取分类树
     *
     * @param int $parent_id
     * @return array
     */
    public function get_tree($parent_id = 0) {
        $cache_key = $this->build_cache_key('category_tree', [
            'parent_id' => (int) $parent_id,
            'product_ver' => $this->get_product_cache_version(),
        ]);

        if ($this->is_cache_enabled()) {
            $cached = wp_cache_get($cache_key, 'qls_shop');
            if ($cached !== false) {
                return $this->hydrate_category_collection($cached);
            }
        }

        $categories = $this->get_list([
            'status' => 1,
        ]);
        $children_map = [];
        foreach ((array) $categories as $category) {
            $category_parent_id = (int) ($category->parent_id ?? 0);
            if (!isset($children_map[$category_parent_id])) {
                $children_map[$category_parent_id] = [];
            }

            $children_map[$category_parent_id][] = $category;
        }

        $product_count_map = $this->get_category_product_count_map(1);
        $categories = $this->build_tree_from_map($children_map, $product_count_map, (int) $parent_id);
        $categories = $this->hydrate_category_collection($categories);

        if ($this->is_cache_enabled()) {
            wp_cache_set($cache_key, $categories, 'qls_shop', $this->get_cache_ttl('tree'));
        }

        return $categories;
    }

    /**
     * 基于已加载的分类映射构造分类树，避免递归查询数据库。
     *
     * @param array<int, array>     $children_map
     * @param array<int, int>       $product_count_map
     * @param int                   $parent_id
     * @return array
     */
    private function build_tree_from_map($children_map, $product_count_map, $parent_id) {
        $result = [];
        $children = isset($children_map[$parent_id]) && is_array($children_map[$parent_id]) ? $children_map[$parent_id] : [];

        foreach ($children as $category) {
            $category_id = (int) ($category->id ?? 0);
            $category->children = $this->build_tree_from_map($children_map, $product_count_map, $category_id);
            $category->product_count = isset($product_count_map[$category_id]) ? (int) $product_count_map[$category_id] : 0;
            $result[] = $category;
        }

        return $result;
    }

    /**
     * 获取扁平化的分类列表（带层级缩进）
     *
     * @param int    $parent_id
     * @param int    $depth
     * @param string $prefix
     * @return array
     */
    public function get_flat_tree($parent_id = 0, $depth = 0, $prefix = '') {
        $cache_key = $this->build_cache_key('category_flat_tree', [
            'parent_id' => (int) $parent_id,
        ]);

        if ($this->is_cache_enabled()) {
            $cached = wp_cache_get($cache_key, 'qls_shop');
            if ($cached !== false) {
                return $this->hydrate_category_collection($cached);
            }
        }

        $categories = $this->get_list();
        $children_map = [];
        foreach ((array) $categories as $category) {
            $category_parent_id = (int) ($category->parent_id ?? 0);
            if (!isset($children_map[$category_parent_id])) {
                $children_map[$category_parent_id] = [];
            }

            $children_map[$category_parent_id][] = $category;
        }

        $result = $this->build_flat_tree_from_map($children_map, (int) $parent_id, (int) $depth, (string) $prefix);
        if ($this->is_cache_enabled()) {
            wp_cache_set($cache_key, $result, 'qls_shop', $this->get_cache_ttl('tree'));
        }

        return $result;
    }

    /**
     * 基于已加载的分类映射构造扁平分类树，避免递归查询数据库。
     *
     * @param array<int, array> $children_map
     * @param int               $parent_id
     * @param int               $depth
     * @param string            $prefix
     * @return array
     */
    private function build_flat_tree_from_map($children_map, $parent_id, $depth, $prefix) {
        $result = [];
        $children = isset($children_map[$parent_id]) && is_array($children_map[$parent_id]) ? $children_map[$parent_id] : [];

        foreach ($children as $category) {
            $category->depth = $depth;
            $category->display_name = $prefix . $category->name;
            $result[] = $category;

            $descendants = $this->build_flat_tree_from_map($children_map, (int) ($category->id ?? 0), $depth + 1, $prefix . '— ');
            if (!empty($descendants)) {
                $result = array_merge($result, $descendants);
            }
        }

        return $result;
    }

    /**
     * 获取分类下的商品数量
     *
     * @param int  $category_id
     * @param bool $include_children 是否包含子分类
     * @return int
     */
    public function get_product_count($category_id, $include_children = false) {
        $category_id = (int) $category_id;
        $cache_key = $this->build_cache_key('category_count', [
            'category_id' => $category_id,
            'include_children' => (int) $include_children,
            'product_ver' => $this->get_product_cache_version(),
        ]);
        if ($this->is_cache_enabled()) {
            $cached = wp_cache_get($cache_key, 'qls_shop');
            if ($cached !== false) {
                return (int) $cached;
            }
        }

        if ($include_children) {
            $ids = $this->get_descendant_ids($category_id);
            $ids[] = $category_id;
            
            $table = $this->db->get_table('products');
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $sql = $this->db->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE category_id IN ({$placeholders}) AND status = 1",
                ...$ids
            );
            $count = (int) $this->db->get_wpdb()->get_var($sql);
            if ($this->is_cache_enabled()) {
                wp_cache_set($cache_key, $count, 'qls_shop', $this->get_cache_ttl('count'));
            }
            return $count;
        }

        $count = $this->db->count('products', [
            'category_id' => $category_id,
            'status'      => 1,
        ]);
        if ($this->is_cache_enabled()) {
            wp_cache_set($cache_key, (int) $count, 'qls_shop', $this->get_cache_ttl('count'));
        }
        return (int) $count;
    }

    /**
     * 批量获取分类商品数映射，减少分类树逐项统计查询。
     *
     * @param int $status
     * @return array<int,int>
     */
    public function get_product_count_map($status = 1) {
        return $this->get_category_product_count_map($status);
    }

    /**
     * 批量获取卡密虚拟商品分类数量映射。
     *
     * @param int $status
     * @return array<int,int>
     */
    public function get_virtual_card_product_count_map($status = 1) {
        return $this->get_category_product_count_map($status, 'virtual', 'card');
    }

    /**
     * 批量获取分类商品数映射，减少分类树逐项统计查询。
     *
     * @param int    $status
     * @param string $product_type
     * @param string $virtual_type
     * @return array<int,int>
     */
    private function get_category_product_count_map($status = 1, $product_type = '', $virtual_type = '') {
        $status = (int) $status;
        $product_type = sanitize_key((string) $product_type);
        $virtual_type = sanitize_key((string) $virtual_type);

        if (!in_array($product_type, ['physical', 'virtual'], true)) {
            $product_type = '';
        }

        if (!in_array($virtual_type, ['download', 'card', 'custom'], true)) {
            $virtual_type = '';
        }

        $cache_key = $this->build_cache_key('category_product_count_map', [
            'status'       => $status,
            'product_type' => $product_type,
            'virtual_type' => $virtual_type,
            'product_ver'  => $this->get_product_cache_version(),
        ]);

        if ($this->is_cache_enabled()) {
            $cached = wp_cache_get($cache_key, 'qls_shop');
            if ($cached !== false) {
                return is_array($cached) ? $cached : [];
            }
        }

        $table = $this->db->get_table('products');
        $where = [
            'status = %d',
            'category_id > 0',
        ];
        $params = [$status];

        if ($product_type !== '') {
            $where[] = 'product_type = %s';
            $params[] = $product_type;
        }

        if ($virtual_type !== '') {
            $where[] = 'virtual_type = %s';
            $params[] = $virtual_type;
        }

        $sql = "SELECT category_id, COUNT(*) AS total
             FROM {$table}
             WHERE " . implode(' AND ', $where) . '
             GROUP BY category_id';
        $rows = $this->db->get_wpdb()->get_results($this->db->prepare($sql, ...$params));

        $map = [];
        foreach ((array) $rows as $row) {
            $category_id = (int) ($row->category_id ?? 0);
            if ($category_id <= 0) {
                continue;
            }

            $map[$category_id] = (int) ($row->total ?? 0);
        }

        if ($this->is_cache_enabled()) {
            wp_cache_set($cache_key, $map, 'qls_shop', $this->get_cache_ttl('count'));
        }

        return $map;
    }

    /**
     * 获取所有后代分类ID
     *
     * @param int $parent_id
     * @return array
     */
    public function get_descendant_ids($parent_id) {
        static $local_cache = [];
        static $children_map_cache = [];

        $parent_id = (int) $parent_id;
        $cache_key = $this->build_cache_key('category_descendants', [
            'parent_id' => $parent_id,
        ]);

        if (isset($local_cache[$cache_key])) {
            return $local_cache[$cache_key];
        }

        if ($this->is_cache_enabled()) {
            $cached = wp_cache_get($cache_key, 'qls_shop');
            if ($cached !== false) {
                $local_cache[$cache_key] = array_values(array_unique(array_filter(array_map('intval', (array) $cached))));
                return $local_cache[$cache_key];
            }
        }

        $version_key = $this->get_cache_version();
        if (!isset($children_map_cache[$version_key])) {
            $children_map_cache[$version_key] = [];
            $categories = $this->get_list();
            foreach ((array) $categories as $category) {
                $category_parent_id = (int) ($category->parent_id ?? 0);
                if (!isset($children_map_cache[$version_key][$category_parent_id])) {
                    $children_map_cache[$version_key][$category_parent_id] = [];
                }

                $children_map_cache[$version_key][$category_parent_id][] = (int) ($category->id ?? 0);
            }
        }

        $ids = $this->build_descendant_ids_from_map($children_map_cache[$version_key], $parent_id);
        $local_cache[$cache_key] = $ids;

        if ($this->is_cache_enabled()) {
            wp_cache_set($cache_key, $ids, 'qls_shop', $this->get_cache_ttl('tree'));
        }

        return $ids;
    }

    /**
     * 基于已加载的分类映射构造后代分类 ID 列表，避免递归查询数据库。
     *
     * @param array<int, array<int, int>> $children_map
     * @param int                         $parent_id
     * @return array<int, int>
     */
    private function build_descendant_ids_from_map($children_map, $parent_id) {
        $ids = [];
        $children = isset($children_map[$parent_id]) && is_array($children_map[$parent_id]) ? $children_map[$parent_id] : [];

        foreach ($children as $child_id) {
            $child_id = (int) $child_id;
            if ($child_id <= 0) {
                continue;
            }

            $ids[] = $child_id;
            $descendants = $this->build_descendant_ids_from_map($children_map, $child_id);
            if (!empty($descendants)) {
                $ids = array_merge($ids, $descendants);
            }
        }

        return $ids;
    }

    /**
     * 获取分类面包屑
     *
     * @param int $category_id
     * @return array
     */
    public function get_breadcrumb($category_id) {
        $breadcrumb = [];
        $category = $this->get($category_id);

        while ($category) {
            array_unshift($breadcrumb, $category);
            
            if ($category->parent_id > 0) {
                $category = $this->get($category->parent_id);
            } else {
                break;
            }
        }

        return $breadcrumb;
    }

    /**
     * 生成唯一slug
     */
    private function generate_slug($name) {
        $slug = sanitize_title($name);
        
        if (empty($slug)) {
            $slug = 'category-' . time();
        }

        return $this->ensure_unique_slug($slug);
    }

    /**
     * 确保slug唯一
     */
    private function ensure_unique_slug($slug, $exclude_id = 0) {
        $original_slug = $slug;
        $max_retries = max(1, (int) apply_filters('qls_shop_category_slug_max_retries', 100, $original_slug, $exclude_id));

        for ($counter = 0; $counter <= $max_retries; $counter++) {
            $candidate = $counter === 0 ? $original_slug : $original_slug . '-' . $counter;
            $existing = $this->db->get_row('categories', ['slug' => $candidate]);

            if (!$existing || ($exclude_id > 0 && (int) $existing->id === (int) $exclude_id)) {
                return $candidate;
            }
        }

        $fallback_base = $original_slug . '-' . time();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = $fallback_base . '-' . wp_rand(1000, 9999);
            $existing = $this->db->get_row('categories', ['slug' => $candidate]);

            if (!$existing || ($exclude_id > 0 && (int) $existing->id === (int) $exclude_id)) {
                return $candidate;
            }
        }

        return $fallback_base . '-' . wp_rand(10000, 99999);
    }

    /**
     * 运行时规范分类图片地址，避免换域名后继续输出旧 uploads 域名。
     *
     * @param object|null $category 分类对象。
     * @return object|null
     */
    private function hydrate_category_media($category) {
        if (!is_object($category)) {
            return $category;
        }

        if (!empty($category->image)) {
            $category->image = qilingshop_normalize_media_url($category->image);
        }

        return $category;
    }

    /**
     * 递归规范分类集合中的图片地址。
     *
     * @param mixed $categories 分类对象数组。
     * @return mixed
     */
    private function hydrate_category_collection($categories) {
        if (!is_array($categories)) {
            return $categories;
        }

        foreach ($categories as $index => $category) {
            $category = $this->hydrate_category_media($category);
            if (is_object($category) && isset($category->children) && is_array($category->children)) {
                $category->children = $this->hydrate_category_collection($category->children);
            }
            $categories[$index] = $category;
        }

        return $categories;
    }
}

/**
 * 获取分类类实例的快捷函数
 */
function qls_category() {
    return QLS_Category::instance();
}
