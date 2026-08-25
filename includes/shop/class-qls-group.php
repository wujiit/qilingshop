<?php
/**
 * 团购核心业务类
 * 
 * 处理团购规则CRUD、发起拼团、参与拼团、成团判断等核心逻辑
 *
 * @package QilingShop
 * @since   2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 团购核心类
 * 
 * 提供团购功能的所有核心操作
 */
class QLS_Group {

    /**
     * 单例实例
     * @var QLS_Group
     */
    private static $instance = null;

    /**
     * 拼团商品缓存版本（请求级缓存）
     *
     * @var int|null
     */
    private $products_cache_version = null;

    /**
     * 团购状态常量
     */
    const STATUS_GROUPING = 0;   // 拼团中
    const STATUS_SUCCESS  = 1;   // 拼团成功
    const STATUS_FAILED   = 2;   // 拼团失败

    /**
     * 规则状态常量
     */
    const RULE_DISABLED = 0;     // 禁用
    const RULE_ENABLED  = 1;     // 启用

    /**
     * 获取单例实例
     * 
     * @return QLS_Group
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
        // 私有构造，防止直接实例化
    }

    /**
     * 获取拼团商品展示缓存版本
     *
     * @return int
     */
    private function get_products_cache_version() {
        if ($this->products_cache_version !== null) {
            return (int) $this->products_cache_version;
        }

        $version = (int) get_option('qls_group_products_cache_version', 1);
        $this->products_cache_version = $version > 0 ? $version : 1;
        return (int) $this->products_cache_version;
    }

    /**
     * 刷新拼团商品展示缓存版本
     *
     * @return int
     */
    private function bump_products_cache_version() {
        $next = $this->get_products_cache_version() + 1;
        update_option('qls_group_products_cache_version', $next, false);
        $this->products_cache_version = $next;
        return $next;
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
     * 构造订单级拼团锁名。
     *
     * @param int $order_id
     * @return string
     */
    private function build_order_lock_name($order_id) {
        return 'qlsord_' . md5((string) absint($order_id));
    }

    /**
     * 查询订单当前已绑定的拼团ID。
     *
     * @param int $order_id
     * @return int
     */
    private function get_group_id_by_order($order_id) {
        global $wpdb;

        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return 0;
        }

        $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'group_members';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT group_id FROM {$table} WHERE order_id = %d LIMIT 1",
            $order_id
        ));
    }

    // =========================================================================
    // 团购规则管理
    // =========================================================================

    /**
     * 创建团购规则
     * 
     * @param array $data 规则数据
     * @return int|false 规则ID或false
     */
    public function create_rule($data) {
        global $wpdb;
        
        $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'group_rules';
        
        $defaults = [
            'product_id'     => 0,
            'group_price'    => 0.00,
            'group_size'     => 2,
            'time_limit'     => 24,
            'status'         => self::RULE_ENABLED,
            'start_time'     => null,
            'end_time'       => null,
            'limit_per_user' => 0,
            'group_stock'    => 0,
            'created_at'     => current_time('mysql'),
        ];
        
        $data = wp_parse_args($data, $defaults);
        
        // 验证必填字段
        if (empty($data['product_id']) || $data['group_price'] <= 0) {
            return false;
        }
        
        $result = $wpdb->insert($table, [
            'product_id'     => absint($data['product_id']),
            'group_price'    => floatval($data['group_price']),
            'group_size'     => max(2, absint($data['group_size'])),
            'time_limit'     => max(1, absint($data['time_limit'])),
            'status'         => absint($data['status']),
            'start_time'     => $data['start_time'],
            'end_time'       => $data['end_time'],
            'limit_per_user' => absint($data['limit_per_user']),
            'group_stock'    => absint($data['group_stock']),
            'created_at'     => $data['created_at'],
        ]);

        if (!$result) {
            return false;
        }

        $this->bump_products_cache_version();
        return $wpdb->insert_id;
    }

    /**
     * 更新团购规则
     * 
     * @param int   $rule_id 规则ID
     * @param array $data    更新数据
     * @return bool
     */
    public function update_rule($rule_id, $data) {
        global $wpdb;
        
        $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'group_rules';
        
        $update_data = [];
        $allowed_fields = [
            'group_price', 'group_size', 'time_limit', 'status',
            'start_time', 'end_time', 'limit_per_user', 'group_stock'
        ];
        
        foreach ($allowed_fields as $field) {
            if (isset($data[$field])) {
                $update_data[$field] = $data[$field];
            }
        }
        
        if (empty($update_data)) {
            return false;
        }
        
        $update_data['updated_at'] = current_time('mysql');
        
        $affected = $wpdb->update($table, $update_data, ['id' => $rule_id]);
        if ($affected !== false && (int) $affected > 0) {
            $this->bump_products_cache_version();
        }

        return $affected !== false;
    }

    /**
     * 删除团购规则
     * 
     * @param int $rule_id 规则ID
     * @return bool
     */
    public function delete_rule($rule_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'group_rules';
        
        $deleted = $wpdb->delete($table, ['id' => $rule_id]);
        if ($deleted !== false && (int) $deleted > 0) {
            $this->bump_products_cache_version();
        }

        return $deleted !== false;
    }

    /**
     * 获取团购规则
     * 
     * @param int $rule_id 规则ID
     * @return object|null
     */
    public function get_rule($rule_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'group_rules';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $rule_id
        ));
    }

    public function get_rule_stock($rule_id) {
        $rule = $this->get_rule($rule_id);
        if (!$rule) {
            return 0;
        }

        if ((int) $rule->group_stock > 0) {
            return (int) $rule->group_stock;
        }

        if (!function_exists('qls_product')) {
            return 0;
        }

        $skus = qls_product()->get_skus((int) $rule->product_id);
        $stock = 0;
        foreach ((array) $skus as $sku) {
            if (isset($sku->status) && (int) $sku->status !== 1) {
                continue;
            }
            $stock += max(0, (int) ($sku->stock ?? 0));
        }

        return $stock;
    }

    public function reduce_rule_stock($rule_id, $quantity) {
        global $wpdb;

        $quantity = intval($quantity);
        if ($quantity <= 0) {
            return true;
        }

        if ($this->rule_uses_product_stock($rule_id)) {
            return true;
        }

        $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'group_rules';

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET group_stock = group_stock - %d WHERE id = %d AND group_stock >= %d",
            $quantity,
            $rule_id,
            $quantity
        ));

        $ok = $updated !== false && $updated > 0;
        if ($ok) {
            $this->bump_products_cache_version();
        }
        return $ok;
    }

    public function restore_rule_stock($rule_id, $quantity) {
        global $wpdb;

        $quantity = intval($quantity);
        if ($quantity <= 0) {
            return true;
        }

        if ($this->rule_uses_product_stock($rule_id)) {
            return true;
        }

        $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'group_rules';

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET group_stock = group_stock + %d WHERE id = %d",
            $quantity,
            $rule_id
        ));

        if ($updated !== false && (int) $updated > 0) {
            $this->bump_products_cache_version();
        }
        return $updated !== false;
    }

    /**
     * 根据商品ID获取有效的团购规则
     * 
     * @param int $product_id 商品ID
     * @return object|null
     */
    public function get_active_rule_by_product($product_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'group_rules';
        $now = current_time('mysql');
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} 
             WHERE product_id = %d 
             AND status = %d
             AND (start_time IS NULL OR start_time <= %s)
             AND (end_time IS NULL OR end_time >= %s)
             ORDER BY id DESC 
             LIMIT 1",
            $product_id,
            self::RULE_ENABLED,
            $now,
            $now
        ));
    }

    /**
     * 获取商品最近一条团购规则（后台编辑使用，不按时间过滤）。
     *
     * @param int $product_id 商品ID
     * @return object|null
     */
    public function get_latest_rule_by_product($product_id) {
        global $wpdb;

        $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'group_rules';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE product_id = %d
             ORDER BY id DESC
             LIMIT 1",
            $product_id
        ));
    }

    /**
     * 判断规则是否处于可参与时间内。
     *
     * @param object $rule 团购规则
     * @return bool
     */
    public function is_rule_active($rule) {
        if (!$rule || (int) ($rule->status ?? self::RULE_DISABLED) !== self::RULE_ENABLED) {
            return false;
        }

        $now = current_time('timestamp');
        if (!empty($rule->start_time) && strtotime((string) $rule->start_time) > $now) {
            return false;
        }
        if (!empty($rule->end_time) && strtotime((string) $rule->end_time) < $now) {
            return false;
        }

        return true;
    }

    /**
     * 判断团购规则是否使用商品 SKU 库存。
     *
     * @param object|int $rule 团购规则或规则ID
     * @return bool
     */
    public function rule_uses_product_stock($rule) {
        if (is_numeric($rule)) {
            $rule = $this->get_rule((int) $rule);
        }

        return $rule && (int) ($rule->group_stock ?? 0) <= 0;
    }

    /**
     * 禁用某个商品下的全部团购规则。
     *
     * @param int $product_id 商品ID
     * @return bool
     */
    public function disable_rules_by_product($product_id) {
        global $wpdb;

        $product_id = (int) $product_id;
        if ($product_id <= 0) {
            return false;
        }

        $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'group_rules';
        $result = $wpdb->update(
            $table,
            [
                'status'     => self::RULE_DISABLED,
                'updated_at' => current_time('mysql'),
            ],
            [
                'product_id' => $product_id,
                'status'     => self::RULE_ENABLED,
            ]
        );

        if ($result !== false) {
            $this->bump_products_cache_version();
        }

        return $result !== false && (int) $result > 0;
    }

    /**
     * 获取团购规则列表
     * 
     * @param array $args 查询参数
     * @return array
     */
    public function get_rules($args = []) {
        global $wpdb;
        
        $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'group_rules';
        $products_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'products';
        
        $defaults = [
            'status'   => null,
            'per_page' => 20,
            'page'     => 1,
            'orderby'  => 'id',
            'order'    => 'DESC',
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        $where = "1=1";
        $params = [];
        
        if (!is_null($args['status'])) {
            $where .= " AND r.status = %d";
            $params[] = $args['status'];
        }
        
        $offset = ($args['page'] - 1) * $args['per_page'];
        $orderby = sanitize_sql_orderby("{$args['orderby']} {$args['order']}") ?: 'r.id DESC';
        
        $sql = "SELECT r.*, p.title as product_title, p.main_image as product_image
                FROM {$table} r
                LEFT JOIN {$products_table} p ON r.product_id = p.id
                WHERE {$where}
                ORDER BY {$orderby}
                LIMIT %d OFFSET %d";
        
        $params[] = $args['per_page'];
        $params[] = $offset;
        
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    // =========================================================================
    // 团购实例管理
    // =========================================================================

    /**
     * 发起拼团
     * 
     * @param int $rule_id  规则ID
     * @param int $user_id  团长用户ID
     * @param int $order_id 订单ID
     * @return int|false 团ID或false
     */
    public function create_group($rule_id, $user_id, $order_id) {
        global $wpdb;

        $lock_name = $order_id > 0 ? $this->build_order_lock_name($order_id) : '';
        if ($lock_name !== '' && !$this->acquire_named_lock($lock_name, 5)) {
            return false;
        }
        
        try {
            if ($order_id > 0) {
                $existing_group_id = $this->get_group_id_by_order($order_id);
                if ($existing_group_id > 0) {
                    return $existing_group_id;
                }
            }

            // 获取规则信息
            $rule = $this->get_rule($rule_id);
            if (!$this->is_rule_active($rule)) {
                return false;
            }

            // 检查用户参与次数限制
            if ($rule->limit_per_user > 0) {
                $user_count = $this->get_user_group_count($user_id, $rule->product_id, $rule->id);
                if ($user_count >= $rule->limit_per_user) {
                    return false;
                }
            }

            $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'groups';
            $now = current_time('mysql');
            $base_time = $now;
            if (!empty($order_id) && function_exists('qls_shop_order')) {
                $order = qls_shop_order()->get($order_id, true);
                if ($order && !empty($order->paid_at)) {
                    $base_time = $order->paid_at;
                }
            }
            
            $expire_time = date('Y-m-d H:i:s', strtotime($base_time) + ($rule->time_limit * 3600));
            
            // 创建团购实例
            $result = $wpdb->insert($table, [
                'rule_id'      => $rule_id,
                'product_id'   => $rule->product_id,
                'leader_id'    => $user_id,
                'current_size' => 1,
                'target_size'  => $rule->group_size,
                'group_price'  => $rule->group_price,
                'status'       => self::STATUS_GROUPING,
                'created_at'   => $base_time,
                'expire_time'  => $expire_time,
            ]);
            
            if (!$result) {
                return false;
            }
            
            $group_id = $wpdb->insert_id;
            
            // 添加团长为成员
            $this->add_member($group_id, $user_id, $order_id, true);
            $this->bump_products_cache_version();
            
            return $group_id;
        } finally {
            if ($lock_name !== '') {
                $this->release_named_lock($lock_name);
            }
        }
    }

    /**
     * 参与拼团
     * 
     * @param int $group_id 团ID
     * @param int $user_id  用户ID
     * @param int $order_id 订单ID
     * @return array ['success' => bool, 'message' => string, 'is_full' => bool]
     */
    public function join_group($group_id, $user_id, $order_id) {
        global $wpdb;

        $lock_name = $order_id > 0 ? $this->build_order_lock_name($order_id) : '';
        if ($lock_name !== '' && !$this->acquire_named_lock($lock_name, 5)) {
            return ['success' => false, 'message' => __('参团处理中，请勿重复提交', 'qilingshop'), 'is_full' => false];
        }

        try {
            if ($order_id > 0) {
                $existing_group_id = $this->get_group_id_by_order($order_id);
                if ($existing_group_id > 0) {
                    if ((int) $existing_group_id === (int) $group_id) {
                        $existing_group = $this->get_group($existing_group_id);
                        $is_full = $existing_group
                            ? ((int) $existing_group->current_size >= (int) $existing_group->target_size || (int) $existing_group->status === self::STATUS_SUCCESS)
                            : false;
                        return ['success' => true, 'message' => __('您已参与此团', 'qilingshop'), 'is_full' => $is_full];
                    }
                    return ['success' => false, 'message' => __('该订单已绑定其他拼团', 'qilingshop'), 'is_full' => false];
                }
            }

            $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'groups';
            $wpdb->query('START TRANSACTION');

            $group = $this->get_group_for_update($group_id);
            if (!$group) {
                $wpdb->query('ROLLBACK');
                return ['success' => false, 'message' => __('团购不存在', 'qilingshop'), 'is_full' => false];
            }

            if ((int) $group->status !== self::STATUS_GROUPING) {
                $wpdb->query('ROLLBACK');
                return ['success' => false, 'message' => __('该团已结束', 'qilingshop'), 'is_full' => false];
            }

            if (strtotime((string) $group->expire_time) < current_time('timestamp')) {
                $wpdb->query('ROLLBACK');
                return ['success' => false, 'message' => __('该团已过期', 'qilingshop'), 'is_full' => false];
            }

            if ($this->is_member($group_id, $user_id)) {
                $wpdb->query('ROLLBACK');
                return ['success' => false, 'message' => __('您已参与此团', 'qilingshop'), 'is_full' => false];
            }

            if ((int) $group->current_size >= (int) $group->target_size) {
                $wpdb->query('ROLLBACK');
                return ['success' => false, 'message' => __('该团已满员', 'qilingshop'), 'is_full' => true];
            }

            $rule = $this->get_rule($group->rule_id);
            if (!$this->is_rule_active($rule)) {
                $wpdb->query('ROLLBACK');
                return ['success' => false, 'message' => __('团购活动已结束', 'qilingshop'), 'is_full' => false];
            }
            if ($rule && $rule->limit_per_user > 0) {
                $user_count = $this->get_user_group_count($user_id, $group->product_id, $rule->id);
                if ($user_count >= $rule->limit_per_user) {
                    $wpdb->query('ROLLBACK');
                    return ['success' => false, 'message' => __('您已达到该商品的参团上限', 'qilingshop'), 'is_full' => false];
                }
            }

            if (!$this->add_member($group_id, $user_id, $order_id, false)) {
                $wpdb->query('ROLLBACK');
                if ($this->is_member($group_id, $user_id)) {
                    return ['success' => false, 'message' => __('您已参与此团', 'qilingshop'), 'is_full' => false];
                }
                return ['success' => false, 'message' => __('参团失败，请重试', 'qilingshop'), 'is_full' => false];
            }

            $new_size = (int) $group->current_size + 1;
            $is_full = ($new_size >= (int) $group->target_size);
            $update_data = [
                'current_size' => $new_size,
            ];
            if ($is_full) {
                $update_data['status'] = self::STATUS_SUCCESS;
                $update_data['completed_at'] = current_time('mysql');
            }

            $updated = $wpdb->update($table, $update_data, [
                'id'     => $group_id,
                'status' => self::STATUS_GROUPING,
            ]);

            if ($updated === false || (int) $updated === 0) {
                throw new Exception('update_group_failed');
            }

            $wpdb->query('COMMIT');
            if ($is_full) {
                do_action('qls_group_success', $group_id);
            }
            $this->bump_products_cache_version();

            return [
                'success' => true,
                'message' => $is_full ? __('拼团成功！', 'qilingshop') : __('参团成功，等待成团', 'qilingshop'),
                'is_full' => $is_full
            ];

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return ['success' => false, 'message' => sprintf(__('参团失败：%s', 'qilingshop'), $e->getMessage()), 'is_full' => false];
        } finally {
            if ($lock_name !== '') {
                $this->release_named_lock($lock_name);
            }
        }
    }

    /**
     * 添加团成员
     * 
     * @param int  $group_id  团ID
     * @param int  $user_id   用户ID
     * @param int  $order_id  订单ID
     * @param bool $is_leader 是否团长
     * @return bool
     */
    public function add_member($group_id, $user_id, $order_id, $is_leader = false) {
        global $wpdb;
        
        $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'group_members';
        
        return $wpdb->insert($table, [
            'group_id'  => $group_id,
            'user_id'   => $user_id,
            'order_id'  => $order_id,
            'is_leader' => $is_leader ? 1 : 0,
            'joined_at' => current_time('mysql'),
        ]) !== false;
    }

    private function update_group_expire_time($group_id, $expire_time) {
        if (empty($group_id) || empty($expire_time)) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'groups';
        $wpdb->update($table, ['expire_time' => $expire_time], ['id' => $group_id]);
    }

    private function update_group_created_at($group_id, $created_at) {
        if (empty($group_id) || empty($created_at)) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'groups';
        $wpdb->update($table, ['created_at' => $created_at], ['id' => $group_id]);
    }

    private function get_group_created_at_from_members($group_id) {
        if (empty($group_id)) {
            return '';
        }
        global $wpdb;
        $members_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'group_members';
        $created_at = $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(joined_at) FROM {$members_table} WHERE group_id = %d",
            $group_id
        ));
        return $created_at ?: '';
    }

    private function get_group_expire_time($group, $time_limit = null) {
        if (empty($group)) {
            return '';
        }
        
        $expire_time = $group->expire_time ?? '';
        $created_at = $group->created_at ?? '';
        
        if (empty($created_at) || strtotime($created_at) === false) {
            if (!empty($group->id)) {
                $created_at = $this->get_group_created_at_from_members($group->id);
                if (!empty($created_at)) {
                    $this->update_group_created_at($group->id, $created_at);
                }
            }
        }
        
        if ($time_limit === null && !empty($group->rule_id)) {
            $rule = $this->get_rule($group->rule_id);
            $time_limit = $rule ? $rule->time_limit : null;
        }
        
        // Fix: Explicitly recalculate expire time based on Created At + Time Limit
        // This ensures the group timer is independent of the main Activity End Time.
        if (!empty($time_limit) && !empty($created_at)) {
            $expected = date('Y-m-d H:i:s', strtotime($created_at) + ($time_limit * 3600));
            
            // Check if we need to update the DB
            // We use the calculated expected time as the source of truth
            if (empty($expire_time) || strtotime($expire_time) !== strtotime($expected)) {
                $expire_time = $expected;
                if (!empty($group->id)) {
                    $this->update_group_expire_time($group->id, $expire_time);
                }
            } else {
                $expire_time = $expected;
            }
            
            return $expire_time;
        }
        
        return $expire_time;
    }

    /**
     * 获取团信息
     * 
     * @param int  $group_id     团ID
     * @param bool $with_members 是否包含成员信息
     * @return object|null
     */
    public function get_group($group_id, $with_members = false) {
        global $wpdb;
        
        $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'groups';
        $products_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'products';
        
        $group = $wpdb->get_row($wpdb->prepare(
            "SELECT g.*, p.title as product_title, p.main_image as product_image
             FROM {$table} g
             LEFT JOIN {$products_table} p ON g.product_id = p.id
             WHERE g.id = %d",
            $group_id
        ));
        
        if ($group) {
            $group->expire_time = $this->get_group_expire_time($group);
            if ($with_members) {
                $group->members = $this->get_members($group_id);
            }
        }
        
        return $group;
    }

    /**
     * 事务内锁定团信息
     *
     * @param int $group_id 团ID
     * @return object|null
     */
    private function get_group_for_update($group_id) {
        global $wpdb;

        $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'groups';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d LIMIT 1 FOR UPDATE",
            $group_id
        ));
    }

    /**
     * 获取团成员列表
     * 
     * @param int $group_id 团ID
     * @return array
     */
    public function get_members($group_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'group_members';
        
        $members = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE group_id = %d ORDER BY is_leader DESC, joined_at ASC",
            $group_id
        ));
        
        // 补充用户信息
        foreach ($members as &$member) {
            $user = get_userdata($member->user_id);
            $member->user_name = $user ? $user->display_name : sprintf(__('用户%s', 'qilingshop'), $member->user_id);
            $member->user_avatar = get_avatar_url($member->user_id, ['size' => 64]);
        }
        
        return $members;
    }

    /**
     * 检查用户是否已是成员
     * 
     * @param int $group_id 团ID
     * @param int $user_id  用户ID
     * @return bool
     */
    public function is_member($group_id, $user_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'group_members';
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE group_id = %d AND user_id = %d",
            $group_id,
            $user_id
        ));
        
        return $count > 0;
    }

    /**
     * 获取用户在某商品的参团次数
     *
     * @param int $user_id    用户ID
     * @param int $product_id 商品ID
     * @param int $rule_id    规则ID，0 表示不限制
     * @return int
     */
    public function get_user_group_count($user_id, $product_id, $rule_id = 0) {
        global $wpdb;
        
        $groups_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'groups';
        $members_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'group_members';
        $orders_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'orders';

        $where = "m.user_id = %d AND g.product_id = %d AND g.status IN (%d, %d)";
        $params = [
            (int) $user_id,
            (int) $product_id,
            self::STATUS_GROUPING,
            self::STATUS_SUCCESS,
        ];

        if ((int) $rule_id > 0) {
            $where .= " AND g.rule_id = %d";
            $params[] = (int) $rule_id;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$members_table} m
             INNER JOIN {$groups_table} g ON m.group_id = g.id
             LEFT JOIN {$orders_table} o ON o.id = m.order_id
             WHERE {$where}
               AND (m.order_id = 0 OR o.status IN (%d, %d, %d, %d))",
            array_merge($params, [
                QLS_Shop_Order::STATUS_PAID,
                QLS_Shop_Order::STATUS_SHIPPED,
                QLS_Shop_Order::STATUS_COMPLETED,
                QLS_Shop_Order::STATUS_REFUNDING,
            ])
        ));
    }

    /**
     * 获取商品正在进行的团列表
     * 
     * @param int $product_id 商品ID
     * @param int $limit      数量限制
     * @return array
     */
    public function get_active_groups_by_product($product_id, $limit = 10) {
        global $wpdb;
        
        $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'groups';
        $rules_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'group_rules';
        $now = current_time('mysql');
        
        $groups = $wpdb->get_results($wpdb->prepare(
            "SELECT g.*, r.time_limit as rule_time_limit, DATE_ADD(g.created_at, INTERVAL r.time_limit HOUR) as computed_expire_time
             FROM {$table} g
             INNER JOIN {$rules_table} r ON g.rule_id = r.id
             WHERE g.product_id = %d 
             AND g.status = %d 
             AND DATE_ADD(g.created_at, INTERVAL r.time_limit HOUR) > %s
             ORDER BY g.created_at DESC
             LIMIT %d",
            $product_id,
            self::STATUS_GROUPING,
            $now,
            $limit
        ));
        
        // 补充团长信息
        foreach ($groups as &$group) {
            $user = get_userdata($group->leader_id);
            $group->leader_name = $user ? $user->display_name : sprintf(__('用户%s', 'qilingshop'), $group->leader_id);
            $group->leader_avatar = get_avatar_url($group->leader_id, ['size' => 64]);
            $group->remain_count = $group->target_size - $group->current_size;
            if (!empty($group->computed_expire_time)) {
                if (empty($group->expire_time) || strtotime($group->computed_expire_time) !== strtotime($group->expire_time)) {
                    $this->update_group_expire_time($group->id, $group->computed_expire_time);
                }
                $group->expire_time = $group->computed_expire_time;
            } else {
                $group->expire_time = $this->get_group_expire_time($group, $group->rule_time_limit ?? null);
            }
            $group->remain_seconds = max(0, strtotime($group->expire_time) - current_time('timestamp'));
        }
        
        return $groups;
    }

    /**
     * 获取用户的拼团列表
     * 
     * @param int   $user_id 用户ID
     * @param array $args    查询参数
     * @return array
     */
    public function get_user_groups($user_id, $args = []) {
        global $wpdb;
        
        $groups_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'groups';
        $members_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'group_members';
        $products_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'products';
        
        $defaults = [
            'status'   => null,    // null表示全部，0/1/2具体状态
            'per_page' => 10,
            'page'     => 1,
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        $where = "m.user_id = %d";
        $params = [$user_id];
        
        if (!is_null($args['status'])) {
            $where .= " AND g.status = %d";
            $params[] = $args['status'];
        }
        
        $offset = ($args['page'] - 1) * $args['per_page'];
        
        $sql = "SELECT g.*, m.is_leader, m.order_id, m.joined_at,
                       p.title as product_title, p.main_image as product_image
                FROM {$members_table} m
                INNER JOIN {$groups_table} g ON m.group_id = g.id
                LEFT JOIN {$products_table} p ON g.product_id = p.id
                WHERE {$where}
                ORDER BY m.joined_at DESC
                LIMIT %d OFFSET %d";
        
        $params[] = $args['per_page'];
        $params[] = $offset;
        
        $groups = $wpdb->get_results($wpdb->prepare($sql, $params));
        
        // 处理附加信息
        foreach ($groups as &$group) {
            $group->remain_count = $group->target_size - $group->current_size;
            $group->expire_time = $this->get_group_expire_time($group);
            $group->remain_seconds = max(0, strtotime($group->expire_time) - current_time('timestamp'));
            $group->status_text = $this->get_status_text($group->status);
        }
        
        return $groups;
    }

    /**
     * 获取用户拼团数量
     * 
     * @param int  $user_id 用户ID
     * @param int  $status  状态筛选
     * @return int
     */
    public function get_user_groups_count($user_id, $status = null) {
        global $wpdb;
        
        $groups_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'groups';
        $members_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'group_members';
        
        $where = "m.user_id = %d";
        $params = [$user_id];
        
        if (!is_null($status)) {
            $where .= " AND g.status = %d";
            $params[] = $status;
        }
        
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$members_table} m
             INNER JOIN {$groups_table} g ON m.group_id = g.id
             WHERE {$where}",
            $params
        ));
    }

    // =========================================================================
    // 状态管理
    // =========================================================================

    /**
     * 标记拼团成功
     * 
     * @param int $group_id 团ID
     * @return bool
     */
    public function mark_success($group_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'groups';
        
        $result = $wpdb->update(
            $table,
            [
                'status'       => self::STATUS_SUCCESS,
                'completed_at' => current_time('mysql'),
            ],
            [
                'id'     => $group_id,
                'status' => self::STATUS_GROUPING,
            ]
        );
        
        if ($result !== false && (int) $result > 0) {
            // 触发成团成功钩子
            do_action('qls_group_success', $group_id);
            $this->bump_products_cache_version();
            
            // 拼团成功后订单保持已付款状态，由商家后台发货。
        }
        
        return $result !== false && (int) $result > 0;
    }

    /**
     * 标记拼团失败
     * 
     * @param int $group_id 团ID
     * @return bool
     */
    public function mark_failed($group_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'groups';
        
        $result = $wpdb->update(
            $table,
            [
                'status'       => self::STATUS_FAILED,
                'completed_at' => current_time('mysql'),
            ],
            [
                'id'     => $group_id,
                'status' => self::STATUS_GROUPING,
            ]
        );
        
        if ($result !== false && (int) $result > 0) {
            // 触发成团失败钩子
            do_action('qls_group_failed', $group_id);
            $this->bump_products_cache_version();
        }
        
        return $result !== false && (int) $result > 0;
    }

    /**
     * 更新成员订单状态
     * 
     * @param int $group_id 团ID
     * @param int $status   新状态
     * @return int 更新数量
     */
    public function update_member_orders_status($group_id, $status) {
        global $wpdb;
        
        $members_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'group_members';
        $orders_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'orders';
        
        // 获取所有成员订单
        $order_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT order_id FROM {$members_table} WHERE group_id = %d",
            $group_id
        ));
        
        if (empty($order_ids)) {
            return 0;
        }
        
        $placeholders = implode(',', array_fill(0, count($order_ids), '%d'));
        
        return $wpdb->query($wpdb->prepare(
            "UPDATE {$orders_table} SET status = %d WHERE id IN ({$placeholders})",
            array_merge([$status], $order_ids)
        ));
    }

    /**
     * 获取状态文本
     * 
     * @param int $status 状态码
     * @return string
     */
    public function get_status_text($status) {
        $texts = [
            self::STATUS_GROUPING => __('拼团中', 'qilingshop'),
            self::STATUS_SUCCESS  => __('拼团成功', 'qilingshop'),
            self::STATUS_FAILED   => __('拼团失败', 'qilingshop'),
        ];
        
        return $texts[$status] ?? __('未知状态', 'qilingshop');
    }

    /**
     * 获取状态Badge样式
     * 
     * @param int $status 状态码
     * @return string
     */
    public function get_status_badge_class($status) {
        $classes = [
            self::STATUS_GROUPING => 'qls-badge-warning',
            self::STATUS_SUCCESS  => 'qls-badge-success',
            self::STATUS_FAILED   => 'qls-badge-danger',
        ];
        
        return $classes[$status] ?? 'qls-badge-secondary';
    }

    // =========================================================================
    // 统计相关
    // =========================================================================

    /**
     * 获取团购统计数据
     * 
     * @return array
     */
    public function get_statistics() {
        global $wpdb;
        
        $groups_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'groups';
        $rows = $wpdb->get_results("SELECT status, COUNT(*) AS total FROM {$groups_table} GROUP BY status");

        $status_counts = [];
        foreach ((array) $rows as $row) {
            $status = isset($row->status) ? (int) $row->status : 0;
            $status_counts[$status] = isset($row->total) ? (int) $row->total : 0;
        }

        $stats = [];
        $stats['grouping_count'] = (int) ($status_counts[self::STATUS_GROUPING] ?? 0);
        $stats['success_count'] = (int) ($status_counts[self::STATUS_SUCCESS] ?? 0);
        $stats['failed_count'] = (int) ($status_counts[self::STATUS_FAILED] ?? 0);
        $stats['total_groups'] = (int) array_sum($status_counts);
        
        // 成团率
        $completed = $stats['success_count'] + $stats['failed_count'];
        $stats['success_rate'] = $completed > 0 
            ? round($stats['success_count'] / $completed * 100, 1) 
            : 0;
        
        return $stats;
    }

    /**
     * 获取所有开启了团购的商品列表
     * 
     * @param array $args 查询参数
     * @return array
     */
    public function get_group_products($args = []) {
        global $wpdb;
        
        $rules_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'group_rules';
        $products_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'products';
        $groups_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'groups';
        $members_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'group_members';
        
        $defaults = [
            'per_page' => 20,
            'page'     => 1,
            'orderby'  => 'r.id',
            'order'    => 'DESC',
            'include'  => [],
        ];
        
        $args = wp_parse_args($args, $defaults);
        $args['per_page'] = max(1, (int) $args['per_page']);
        $args['page'] = max(1, (int) $args['page']);
        $args['order'] = strtoupper((string) $args['order']) === 'ASC' ? 'ASC' : 'DESC';
        $args['orderby'] = (string) $args['orderby'];
        $args['include'] = array_values(array_unique(array_filter(array_map('intval', (array) $args['include']))));

        $cache_window = (int) apply_filters('qls_group_products_cache_window', 30);
        $use_cache = $cache_window > 0;
        $cache_key = '';
        if ($use_cache) {
            $cache_window = max(10, $cache_window);
            $cache_bucket = (int) floor(time() / $cache_window);
            $cache_version = $this->get_products_cache_version();
            $cache_key = 'group_products_' . md5(wp_json_encode([
                'args' => $args,
                'bucket' => $cache_bucket,
                'version' => $cache_version,
            ]));
            $cached = wp_cache_get($cache_key, 'qls_shop');
            if ($cached !== false) {
                return $cached;
            }
        }

        $now = current_time('mysql');
        $offset = ($args['page'] - 1) * $args['per_page'];
        if ($args['orderby'] === 'include' && !empty($args['include'])) {
            $orderby = 'FIELD(p.id, ' . implode(',', $args['include']) . ')';
        } else {
            $orderby = sanitize_sql_orderby("{$args['orderby']} {$args['order']}") ?: 'r.id DESC';
        }

        $where = "r.status = %d
                AND p.status = 1
                AND (r.start_time IS NULL OR r.start_time <= %s)
                AND (r.end_time IS NULL OR r.end_time >= %s)";
        $params = [
            self::RULE_ENABLED,
            $now,
            $now,
        ];
        if (!empty($args['include'])) {
            $placeholders = implode(',', array_fill(0, count($args['include']), '%d'));
            $where .= " AND p.id IN ({$placeholders})";
            $params = array_merge($params, $args['include']);
        }
        
        $sql = "SELECT p.*, r.id as rule_id, r.group_price, r.group_size, r.time_limit, 
                       r.start_time as rule_start, r.end_time as rule_end
                FROM {$rules_table} r
                INNER JOIN {$products_table} p ON r.product_id = p.id
                WHERE {$where}
                ORDER BY {$orderby}
                LIMIT %d OFFSET %d";

        $params[] = $args['per_page'];
        $params[] = $offset;
        $products = $wpdb->get_results($wpdb->prepare($sql, $params));

        if (empty($products)) {
            if ($use_cache) {
                wp_cache_set($cache_key, [], 'qls_shop', $cache_window);
            }
            return [];
        }

        $product_ids = array_values(array_unique(array_filter(array_map('intval', wp_list_pluck($products, 'id')))));
        $active_counts = [];
        $success_member_counts = [];

        if (!empty($product_ids)) {
            $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));

            $active_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT product_id, COUNT(*) AS cnt
                 FROM {$groups_table}
                 WHERE product_id IN ({$placeholders})
                   AND status = %d
                   AND expire_time > %s
                 GROUP BY product_id",
                array_merge($product_ids, [self::STATUS_GROUPING, $now])
            ));
            foreach ((array) $active_rows as $row) {
                $active_counts[(int) $row->product_id] = (int) $row->cnt;
            }

            $success_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT g.product_id, COUNT(*) AS cnt
                 FROM {$members_table} m
                 INNER JOIN {$groups_table} g ON m.group_id = g.id
                 WHERE g.product_id IN ({$placeholders})
                   AND g.status = %d
                 GROUP BY g.product_id",
                array_merge($product_ids, [self::STATUS_SUCCESS])
            ));
            foreach ((array) $success_rows as $row) {
                $success_member_counts[(int) $row->product_id] = (int) $row->cnt;
            }
        }
        
        // 处理附加信息
        foreach ($products as &$product) {
            // 解码图片
            if (is_string($product->main_image)) {
                $decoded = json_decode($product->main_image, true);
                $product->main_image = $decoded ?: $product->main_image;
            }
            
            $product_id = (int) $product->id;
            $product->active_groups_count = (int) ($active_counts[$product_id] ?? 0);
            $product->success_member_count = (int) ($success_member_counts[$product_id] ?? 0);
        }
        unset($product);

        if ($use_cache) {
            wp_cache_set($cache_key, $products, 'qls_shop', $cache_window);
        }
        
        return $products;
    }

    /**
     * 获取团购商品总数
     * 
     * @return int
     */
    public function get_group_products_count() {
        global $wpdb;
        
        $rules_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'group_rules';
        $products_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'products';
        $cache_window = (int) apply_filters('qls_group_products_count_cache_window', 30);
        $use_cache = $cache_window > 0;
        $cache_key = '';
        if ($use_cache) {
            $cache_window = max(10, $cache_window);
            $cache_bucket = (int) floor(time() / $cache_window);
            $cache_version = $this->get_products_cache_version();
            $cache_key = 'group_products_count_' . $cache_version . '_' . $cache_bucket;
            $cached = wp_cache_get($cache_key, 'qls_shop');
            if ($cached !== false) {
                return (int) $cached;
            }
        }

        $now = current_time('mysql');
        
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$rules_table} r
             INNER JOIN {$products_table} p ON r.product_id = p.id
             WHERE r.status = %d
             AND p.status = 1
             AND (r.start_time IS NULL OR r.start_time <= %s)
             AND (r.end_time IS NULL OR r.end_time >= %s)",
            self::RULE_ENABLED,
            $now,
            $now
        ));

        if ($use_cache) {
            wp_cache_set($cache_key, $count, 'qls_shop', $cache_window);
        }
        return $count;
    }

    /**
     * 获取商品活跃团数量
     * 
     * @param int $product_id 商品ID
     * @return int
     */
    public function get_active_groups_count($product_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'groups';
        $now = current_time('mysql');
        
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} 
             WHERE product_id = %d AND status = %d AND expire_time > %s",
            $product_id,
            self::STATUS_GROUPING,
            $now
        ));
    }

    public function get_success_members_count($product_id) {
        global $wpdb;

        $groups_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'groups';
        $members_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'group_members';

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$members_table} m
             INNER JOIN {$groups_table} g ON m.group_id = g.id
             WHERE g.product_id = %d AND g.status = %d",
            $product_id,
            self::STATUS_SUCCESS
        ));
    }
}

/**
 * 获取团购类实例的快捷函数
 * 
 * @return QLS_Group
 */
function qls_group() {
    return QLS_Group::instance();
}
