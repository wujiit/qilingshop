<?php
/**
 * 优惠券核心管理类
 * 
 * 提供优惠券的创建、领取、验证和使用功能
 *
 * @package QilingShop
 * @since   2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_Coupon {

    const CLAIM_STATUS_UNUSED = 0;
    const CLAIM_STATUS_USED = 1;
    const CLAIM_STATUS_EXPIRED = 2;
    const CLAIM_STATUS_RESERVED = 3;

    /**
     * 单例实例
     */
    private static $instance = null;

    /**
     * 数据库表名
     */
    private $table_coupons;
    private $table_claims;
    private $table_uses;

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
        global $wpdb;
        $prefix = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX;
        $this->table_coupons = $prefix . 'coupons';
        $this->table_claims = $prefix . 'coupon_claims';
        $this->table_uses = $prefix . 'coupon_uses';
    }

    // =========================================
    // CRUD 操作
    // =========================================

    /**
     * 创建优惠券
     * 
     * @param array $data 优惠券数据
     * @return int|false 优惠券ID或false
     */
    public function create($data) {
        global $wpdb;

        $defaults = [
            'code' => $this->generate_code(),
            'name' => '',
            'description' => '',
            'discount_type' => 'fixed',
            'discount_value' => 0,
            'max_discount' => null,
            'apply_scope' => 'all',
            'apply_items' => null,
            'apply_categories' => null,
            'allowed_vip_levels' => null,
            'use_vip_levels' => null,
            'stack_with_vip' => 1,
            'min_amount' => 0,
            'total_count' => -1,
            'per_user_limit' => 1,
            'first_order_only' => 0,
            'first_order_scope' => 'same_scope',
            'used_count' => 0,
            'claimed_count' => 0,
            'valid_type' => 'fixed',
            'valid_days' => null,
            'start_time' => null,
            'end_time' => null,
            'claim_type' => 'public',
            'min_vip_level' => 0,
            'status' => 1,
            'is_visible' => 1,
            'sort_order' => 0,
        ];

        $data = wp_parse_args($data, $defaults);
        $data['first_order_only'] = empty($data['first_order_only']) ? 0 : 1;
        $data['first_order_scope'] = in_array($data['first_order_scope'], ['same_scope', 'all'], true) ? $data['first_order_scope'] : 'same_scope';

        // 处理JSON字段
        if (is_array($data['apply_items'])) {
            $data['apply_items'] = wp_json_encode($data['apply_items']);
        }
        if (is_array($data['apply_categories'])) {
            $data['apply_categories'] = wp_json_encode($data['apply_categories']);
        }
        if (is_array($data['allowed_vip_levels'])) {
            $data['allowed_vip_levels'] = wp_json_encode($data['allowed_vip_levels']);
        }
        if (is_array($data['use_vip_levels'])) {
            $data['use_vip_levels'] = wp_json_encode($data['use_vip_levels']);
        }

        $result = $wpdb->insert($this->table_coupons, $data);

        if ($result) {
            return $wpdb->insert_id;
        }

        return false;
    }

    /**
     * 更新优惠券
     * 
     * @param int $id 优惠券ID
     * @param array $data 更新数据
     * @return bool
     */
    public function update($id, $data) {
        global $wpdb;

        // 处理JSON字段
        if (isset($data['apply_items']) && is_array($data['apply_items'])) {
            $data['apply_items'] = wp_json_encode($data['apply_items']);
        }
        if (isset($data['apply_categories']) && is_array($data['apply_categories'])) {
            $data['apply_categories'] = wp_json_encode($data['apply_categories']);
        }
        if (isset($data['allowed_vip_levels']) && is_array($data['allowed_vip_levels'])) {
            $data['allowed_vip_levels'] = wp_json_encode($data['allowed_vip_levels']);
        }
        if (isset($data['use_vip_levels']) && is_array($data['use_vip_levels'])) {
            $data['use_vip_levels'] = wp_json_encode($data['use_vip_levels']);
        }
        if (isset($data['first_order_only'])) {
            $data['first_order_only'] = empty($data['first_order_only']) ? 0 : 1;
        }
        if (isset($data['first_order_scope'])) {
            $data['first_order_scope'] = in_array($data['first_order_scope'], ['same_scope', 'all'], true) ? $data['first_order_scope'] : 'same_scope';
        }

        $result = $wpdb->update(
            $this->table_coupons,
            $data,
            ['id' => $id]
        );

        return $result !== false;
    }

    /**
     * 删除优惠券
     * 
     * @param int $id 优惠券ID
     * @return bool
     */
    public function delete($id) {
        global $wpdb;

        // 删除相关的领取记录
        $wpdb->delete($this->table_claims, ['coupon_id' => $id]);
        
        // 删除优惠券
        $result = $wpdb->delete($this->table_coupons, ['id' => $id]);

        return $result !== false;
    }

    /**
     * 获取单个优惠券
     * 
     * @param int $id 优惠券ID
     * @return object|null
     */
    public function get($id) {
        global $wpdb;

        $coupon = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_coupons} WHERE id = %d",
            $id
        ));

        if ($coupon) {
            $coupon = $this->parse_coupon($coupon);
        }

        return $coupon;
    }

    /**
     * 根据优惠码获取优惠券
     * 
     * @param string $code 优惠码
     * @return object|null
     */
    public function get_by_code($code) {
        global $wpdb;

        $coupon = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_coupons} WHERE code = %s",
            $code
        ));

        if ($coupon) {
            $coupon = $this->parse_coupon($coupon);
        }

        return $coupon;
    }

    /**
     * 获取优惠券列表
     * 
     * @param array $args 查询参数
     * @return array
     */
    public function get_list($args = []) {
        global $wpdb;

        $defaults = [
            'status' => null,
            'apply_scope' => null,
            'is_visible' => null,
            'search' => '',
            'orderby' => 'id',
            'order' => 'DESC',
            'limit' => 20,
            'offset' => 0,
        ];

        $args = wp_parse_args($args, $defaults);

        $where = ['1=1'];
        $values = [];

        if ($args['status'] !== null) {
            $where[] = 'status = %d';
            $values[] = $args['status'];
        }

        if ($args['apply_scope']) {
            $where[] = 'apply_scope = %s';
            $values[] = $args['apply_scope'];
        }

        if ($args['is_visible'] !== null) {
            $where[] = 'is_visible = %d';
            $values[] = $args['is_visible'];
        }

        if ($args['search']) {
            $where[] = '(name LIKE %s OR code LIKE %s)';
            $values[] = '%' . $wpdb->esc_like($args['search']) . '%';
            $values[] = '%' . $wpdb->esc_like($args['search']) . '%';
        }

        $where_sql = implode(' AND ', $where);
        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']) ?: 'id DESC';

        $sql = "SELECT * FROM {$this->table_coupons} WHERE {$where_sql} ORDER BY {$orderby} LIMIT %d OFFSET %d";
        $values[] = $args['limit'];
        $values[] = $args['offset'];

        $coupons = $wpdb->get_results($wpdb->prepare($sql, $values));

        return array_map([$this, 'parse_coupon'], $coupons);
    }

    /**
     * 获取优惠券总数
     * 
     * @param array $args 查询参数
     * @return int
     */
    public function get_count($args = []) {
        global $wpdb;

        $where = ['1=1'];
        $values = [];

        if (isset($args['status']) && $args['status'] !== null) {
            $where[] = 'status = %d';
            $values[] = $args['status'];
        }

        if (!empty($args['apply_scope'])) {
            $where[] = 'apply_scope = %s';
            $values[] = $args['apply_scope'];
        }

        if (!empty($args['search'])) {
            $where[] = '(name LIKE %s OR code LIKE %s)';
            $values[] = '%' . $wpdb->esc_like($args['search']) . '%';
            $values[] = '%' . $wpdb->esc_like($args['search']) . '%';
        }

        $where_sql = implode(' AND ', $where);

        if (!empty($values)) {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_coupons} WHERE {$where_sql}",
                $values
            ));
        } else {
            $count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_coupons} WHERE {$where_sql}");
        }

        return (int) $count;
    }

    /**
     * 按状态批量统计优惠券数量，避免后台页面反复 COUNT。
     *
     * @param array $args
     * @return array<int, int>
     */
    public function get_status_counts($args = []) {
        global $wpdb;

        $where = ['1=1'];
        $values = [];

        if (!empty($args['apply_scope'])) {
            $where[] = 'apply_scope = %s';
            $values[] = $args['apply_scope'];
        }

        if (array_key_exists('is_visible', $args) && $args['is_visible'] !== null) {
            $where[] = 'is_visible = %d';
            $values[] = (int) $args['is_visible'];
        }

        if (!empty($args['search'])) {
            $where[] = '(name LIKE %s OR code LIKE %s)';
            $values[] = '%' . $wpdb->esc_like($args['search']) . '%';
            $values[] = '%' . $wpdb->esc_like($args['search']) . '%';
        }

        $where_sql = implode(' AND ', $where);
        $sql = "SELECT status, COUNT(*) AS total FROM {$this->table_coupons} WHERE {$where_sql} GROUP BY status";
        $rows = !empty($values)
            ? $wpdb->get_results($wpdb->prepare($sql, $values))
            : $wpdb->get_results($sql);

        $counts = [];
        foreach ((array) $rows as $row) {
            $status = isset($row->status) ? (int) $row->status : 0;
            $counts[$status] = isset($row->total) ? (int) $row->total : 0;
        }

        return $counts;
    }

    // =========================================
    // 用户领取
    // =========================================

    /**
     * 用户领取优惠券
     * 
     * @param int $coupon_id 优惠券ID
     * @param int $user_id 用户ID
     * @return array ['success' => bool, 'message' => string, 'claim_id' => int|null]
     */
    public function claim($coupon_id, $user_id) {
        global $wpdb;
        $coupon_id = (int) $coupon_id;
        $user_id = (int) $user_id;

        if ($user_id <= 0) {
            return [
                'success' => false,
                'message' => __('请先登录', 'qilingshop'),
                'claim_id' => null,
            ];
        }

        $wpdb->query('START TRANSACTION');

        try {
            $coupon = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table_coupons} WHERE id = %d LIMIT 1 FOR UPDATE",
                $coupon_id
            ));

            if (!$coupon) {
                throw new Exception(__('优惠券不存在', 'qilingshop'));
            }

            $claimed_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_claims} WHERE coupon_id = %d AND user_id = %d",
                $coupon_id,
                $user_id
            ));

            $check = $this->can_claim($coupon_id, $user_id, [
                'coupon' => $this->parse_coupon($coupon),
                'claimed_count' => $claimed_count,
            ]);
            if (!$check['can_claim']) {
                throw new Exception($check['reason']);
            }

            if ($coupon->valid_type === 'days' && (int) $coupon->valid_days > 0) {
                $expires_at = date('Y-m-d H:i:s', strtotime('+' . (int) $coupon->valid_days . ' days'));
            } else {
                $expires_at = $coupon->end_time ?: '2099-12-31 23:59:59';
            }

            $result = $wpdb->insert($this->table_claims, [
                'coupon_id' => $coupon_id,
                'user_id' => $user_id,
                'status' => 0,
                'expires_at' => $expires_at,
                'ip_address' => $this->get_client_ip(),
            ]);

            if (!$result) {
                throw new Exception(__('领取失败，请稍后重试', 'qilingshop'));
            }

            $claim_id = (int) $wpdb->insert_id;

            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table_coupons} SET claimed_count = claimed_count + 1 WHERE id = %d",
                $coupon_id
            ));

            if ($updated === false || (int) $updated !== 1) {
                throw new Exception(__('领取数量更新失败', 'qilingshop'));
            }

            $wpdb->query('COMMIT');

            return [
                'success' => true,
                'message' => __('领取成功', 'qilingshop'),
                'claim_id' => $claim_id,
            ];
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');

            return [
                'success' => false,
                'message' => $e->getMessage() ?: __('领取失败，请稍后重试', 'qilingshop'),
                'claim_id' => null,
            ];
        }
    }

    /**
     * 检查用户是否可领取优惠券
     * 
     * @param int $coupon_id 优惠券ID
     * @param int $user_id 用户ID
     * @param array $context 预加载上下文（coupon/claimed_count/user_vip_level）
     * @return array ['can_claim' => bool, 'reason' => string]
     */
    public function can_claim($coupon_id, $user_id, $context = []) {
        global $wpdb;
        $user_id = (int) $user_id;

        $context = is_array($context) ? $context : [];
        $coupon = (isset($context['coupon']) && is_object($context['coupon']))
            ? $context['coupon']
            : $this->get($coupon_id);

        if (!$coupon) {
            return ['can_claim' => false, 'reason' => __('优惠券不存在', 'qilingshop')];
        }

        if (!$coupon->status) {
            return ['can_claim' => false, 'reason' => __('优惠券已禁用', 'qilingshop')];
        }

        // 检查领取时间
        $now = current_time('mysql');
        if ($coupon->start_time && $now < $coupon->start_time) {
            return ['can_claim' => false, 'reason' => __('优惠券还未开始发放', 'qilingshop')];
        }
        if ($coupon->end_time && $now > $coupon->end_time) {
            return ['can_claim' => false, 'reason' => __('优惠券已过期', 'qilingshop')];
        }

        // 检查发放总量
        if ($coupon->total_count > 0 && $coupon->claimed_count >= $coupon->total_count) {
            return ['can_claim' => false, 'reason' => __('优惠券已领完', 'qilingshop')];
        }
        
        // 全量优惠券仅允许登录用户领取
        if ($user_id <= 0) {
            return ['can_claim' => false, 'reason' => __('请先登录', 'qilingshop')];
        }

        // 检查领取权限
        $growth_can_claim = class_exists('QilingShop_Growth_Benefits')
            && QilingShop_Growth_Benefits::instance()->user_can_claim_coupon($user_id, $coupon_id, $context['claimed_count'] ?? null);
        if ($coupon->claim_type === 'vip') {
            $user_vip = array_key_exists('user_vip_level', $context)
                ? (int) $context['user_vip_level']
                : (int) $this->get_user_vip_level($user_id);
            $allowed_levels = is_array($coupon->allowed_vip_levels ?? null)
                ? $coupon->allowed_vip_levels
                : [];
            if ($growth_can_claim) {
                // 成长权益只补充领取资格，不绕过时间、库存、限领等基础规则。
            } elseif (!empty($allowed_levels)) {
                if (!in_array($user_vip, $allowed_levels, true)) {
                    return ['can_claim' => false, 'reason' => __('当前VIP等级不可领取此优惠券', 'qilingshop')];
                }
            } elseif ($user_vip < $coupon->min_vip_level) {
                return ['can_claim' => false, 'reason' => sprintf(__('需要VIP%s及以上才能领取', 'qilingshop'), $coupon->min_vip_level)];
            }
        } elseif (!in_array($coupon->claim_type, ['public', 'login'], true) && !$growth_can_claim) {
            return ['can_claim' => false, 'reason' => __('当前等级不可领取此优惠券', 'qilingshop')];
        }

        // 检查每人限领数量
        if ($user_id && $coupon->per_user_limit > 0) {
            if (array_key_exists('claimed_count', $context)) {
                $claimed = (int) $context['claimed_count'];
            } else {
                $claimed = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->table_claims} WHERE coupon_id = %d AND user_id = %d",
                    $coupon_id,
                    $user_id
                ));
            }

            if ($claimed >= $coupon->per_user_limit) {
                return ['can_claim' => false, 'reason' => __('已达到领取上限', 'qilingshop')];
            }
        }
        
        $first_order_check = $this->validate_first_order_eligibility($coupon, $user_id, '');
        if (!$first_order_check['valid']) {
            return ['can_claim' => false, 'reason' => $first_order_check['reason']];
        }

        return ['can_claim' => true, 'reason' => ''];
    }

    /**
     * 获取用户的优惠券列表
     * 
     * @param int $user_id 用户ID
     * @param string $status 状态筛选: all/unused/used/expired
     * @return array
     */
    public function get_user_coupons($user_id, $status = 'all') {
        global $wpdb;

        $where = ['cl.user_id = %d'];
        $values = [$user_id];

        $now = current_time('mysql');

        switch ($status) {
            case 'unused':
                $where[] = 'cl.status = 0 AND cl.expires_at > %s';
                $values[] = $now;
                break;
            case 'used':
                $where[] = 'cl.status = 1';
                break;
            case 'expired':
                $where[] = '(cl.status = 2 OR (cl.status = 0 AND cl.expires_at <= %s))';
                $values[] = $now;
                break;
        }

        $where_sql = implode(' AND ', $where);

        $sql = "SELECT cl.*, c.code, c.name, c.description, c.discount_type, c.discount_value, 
                       c.max_discount, c.apply_scope, c.apply_items, c.apply_categories, c.min_amount
                FROM {$this->table_claims} cl
                LEFT JOIN {$this->table_coupons} c ON cl.coupon_id = c.id
                WHERE {$where_sql}
                ORDER BY cl.status ASC, cl.expires_at ASC";

        return $wpdb->get_results($wpdb->prepare($sql, $values));
    }

    // =========================================
    // 验证与使用
    // =========================================

    /**
     * 验证优惠券是否可用
     * 
     * @param int $claim_id 领取记录ID
     * @param string $order_type 订单类型: resource/recharge/vip/shop
     * @param float $amount 订单金额
     * @param array $items 商品/文章ID列表
     * @param int $user_id 限定的用户ID（>0 时强制校验优惠券归属）
     * @return array ['valid' => bool, 'message' => string, 'coupon' => object|null]
     */
    public function validate($claim_id, $order_type, $amount, $items = [], $user_id = 0) {
        global $wpdb;

        // 获取领取记录
        $claim = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_claims} WHERE id = %d",
            $claim_id
        ));

        if (!$claim) {
            return ['valid' => false, 'message' => __('优惠券不存在', 'qilingshop'), 'coupon' => null];
        }

        // 限定用户时，必须校验领取记录归属，防止越权使用他人优惠券
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return ['valid' => false, 'message' => __('请先登录后使用优惠券', 'qilingshop'), 'coupon' => null];
        }
        if ((int) $claim->user_id <= 0) {
            return ['valid' => false, 'message' => __('优惠券无效或不属于当前账户', 'qilingshop'), 'coupon' => null];
        }
        if ((int) $claim->user_id !== $user_id) {
            return ['valid' => false, 'message' => __('优惠券无效或不属于当前账户', 'qilingshop'), 'coupon' => null];
        }

        // 检查状态
        $claim_status = (int) $claim->status;
        if ($claim_status === self::CLAIM_STATUS_USED) {
            return ['valid' => false, 'message' => __('优惠券已使用', 'qilingshop'), 'coupon' => null];
        }
        if ($claim_status === self::CLAIM_STATUS_RESERVED) {
            return ['valid' => false, 'message' => __('优惠券已被待支付订单占用', 'qilingshop'), 'coupon' => null];
        }
        if ($claim_status === self::CLAIM_STATUS_EXPIRED) {
            return ['valid' => false, 'message' => __('优惠券已过期', 'qilingshop'), 'coupon' => null];
        }

        // 检查过期
        $now = current_time('mysql');
        if ($claim->expires_at <= $now) {
            return ['valid' => false, 'message' => __('优惠券已过期', 'qilingshop'), 'coupon' => null];
        }

        // 获取优惠券详情
        $coupon = $this->get($claim->coupon_id);
        if (!$coupon || !$coupon->status) {
            return ['valid' => false, 'message' => __('优惠券已禁用', 'qilingshop'), 'coupon' => null];
        }

        // 检查适用范围
        if ($coupon->apply_scope !== 'all' && $coupon->apply_scope !== $order_type) {
            $scope_names = [
                'resource' => __('文章资源', 'qilingshop'),
                'recharge' => __('积分充值', 'qilingshop'),
                'vip' => __('VIP会员', 'qilingshop'),
                'shop' => __('实物商城', 'qilingshop'),
            ];
            return [
                'valid' => false,
                'message' => sprintf(__('此优惠券仅限%s使用', 'qilingshop'), $scope_names[$coupon->apply_scope] ?? __('特定场景', 'qilingshop')),
                'coupon' => null,
            ];
        }

        // 检查可用VIP等级
        if (!empty($coupon->use_vip_levels)) {
            $user_vip = $this->get_user_vip_level((int) $claim->user_id);
            if (!$user_vip || !in_array($user_vip, (array) $coupon->use_vip_levels, true)) {
                return ['valid' => false, 'message' => __('当前VIP等级不可使用此优惠券', 'qilingshop'), 'coupon' => null];
            }
        }

        // 检查指定商品/文章
        if (!empty($coupon->apply_items) && in_array($order_type, ['resource', 'shop'], true)) {
            if (empty($items)) {
                return ['valid' => false, 'message' => __('此优惠券不适用于当前商品', 'qilingshop'), 'coupon' => null];
            }
            $match = array_intersect($coupon->apply_items, $items);
            if (empty($match)) {
                return ['valid' => false, 'message' => __('此优惠券不适用于当前商品', 'qilingshop'), 'coupon' => null];
            }
        }

        // 检查指定分类（仅实物商城有效）
        if (!empty($coupon->apply_categories) && in_array($order_type, ['shop', 'resource'], true) && !empty($items)) {
            $has_valid_category = false;
            if ($order_type === 'shop') {
                foreach ($items as $product_id) {
                    $product = function_exists('qls_product') ? qls_product()->get($product_id) : null;
                    if ($product && !empty($product->category_id)) {
                        if (in_array((int) $product->category_id, $coupon->apply_categories, true)) {
                            $has_valid_category = true;
                            break;
                        }
                    }
                }
            } else {
                foreach ($items as $post_id) {
                    $term_ids = wp_get_post_terms((int) $post_id, 'category', ['fields' => 'ids']);
                    if (!is_wp_error($term_ids) && !empty($term_ids)) {
                        if (array_intersect($coupon->apply_categories, $term_ids)) {
                            $has_valid_category = true;
                            break;
                        }
                    }
                }
            }
            if (!$has_valid_category) {
                return ['valid' => false, 'message' => __('此优惠券不适用于当前商品分类', 'qilingshop'), 'coupon' => null];
            }
        }

        // 检查最低消费
        if ($coupon->min_amount > 0 && $amount < $coupon->min_amount) {
            return [
                'valid' => false,
                'message' => sprintf(__('订单金额需满%s元才能使用此优惠券', 'qilingshop'), $coupon->min_amount),
                'coupon' => null,
            ];
        }
        
        $first_order_check = $this->validate_first_order_eligibility($coupon, $user_id, $order_type);
        if (!$first_order_check['valid']) {
            return ['valid' => false, 'message' => $first_order_check['reason'], 'coupon' => null];
        }

        return ['valid' => true, 'message' => '', 'coupon' => $coupon];
    }

    /**
     * 计算优惠金额
     * 
     * @param int $claim_id 领取记录ID
     * @param float $amount 订单金额
     * @return float 优惠金额
     */
    public function calculate_discount($claim_id, $amount) {
        global $wpdb;

        $claim = $wpdb->get_row($wpdb->prepare(
            "SELECT coupon_id FROM {$this->table_claims} WHERE id = %d",
            $claim_id
        ));

        if (!$claim) {
            return 0;
        }

        $coupon = $this->get($claim->coupon_id);
        if (!$coupon) {
            return 0;
        }

        if ($coupon->discount_type === 'fixed') {
            // 固定金额优惠
            $discount = min($coupon->discount_value, $amount);
        } else {
            // 百分比优惠
            $discount = $amount * ($coupon->discount_value / 100);
            if ($coupon->max_discount > 0) {
                $discount = min($discount, $coupon->max_discount);
            }
        }

        return round($discount, 2);
    }

    /**
     * 使用优惠券
     * 
     * @param int $claim_id 领取记录ID
     * @param array $order_data 订单数据
     * @return bool
     */
    public function use_coupon($claim_id, $order_data) {
        global $wpdb;

        // 获取领取记录
        $claim = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_claims} WHERE id = %d",
            $claim_id
        ));

        if (!$claim || (int) $claim->status !== self::CLAIM_STATUS_UNUSED) {
            return false;
        }

        // 计算优惠金额
        $discount = $this->calculate_discount($claim_id, $order_data['order_amount']);

        // 记录使用
        $result = $wpdb->insert($this->table_uses, [
            'coupon_id' => $claim->coupon_id,
            'claim_id' => $claim_id,
            'user_id' => $claim->user_id,
            'order_type' => $order_data['order_type'],
            'order_no' => $order_data['order_no'],
            'order_amount' => $order_data['order_amount'],
            'discount_amount' => $discount,
            'ip_address' => $this->get_client_ip(),
        ]);

        if (!$result) {
            return false;
        }

        // 更新领取记录状态
        $wpdb->update(
            $this->table_claims,
            [
                'status' => 1,
                'used_at' => current_time('mysql'),
            ],
            ['id' => $claim_id]
        );

        // 更新优惠券使用数量
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table_coupons} SET used_count = used_count + 1 WHERE id = %d",
            $claim->coupon_id
        ));

        return true;
    }

    /**
     * 获取用户可用的优惠券列表
     * 
     * @param int $user_id 用户ID
     * @param string $order_type 订单类型
     * @param float $amount 订单金额
     * @param array $items 商品ID列表
     * @param bool $include_all 是否包含不可用的优惠券（默认true，显示所有优惠券）
     * @return array
     */
    public function get_available_coupons($user_id, $order_type, $amount, $items = [], $include_all = true) {
        $user_coupons = $this->get_user_coupons($user_id, 'unused');
        $available = [];

        foreach ($user_coupons as $claim) {
            $validation = $this->validate($claim->id, $order_type, $amount, $items, (int) $user_id);
            
            // 添加验证状态到每个优惠券
            $claim->is_valid = $validation['valid'];
            $claim->invalid_reason = $validation['message'] ?? '';
            $claim->discount_amount = $validation['valid'] ? $this->calculate_discount($claim->id, $amount) : 0;
            
            // 根据 include_all 参数决定是否包含不可用优惠券
            if ($include_all || $validation['valid']) {
                $available[] = $claim;
            }
        }

        // 按可用性和优惠金额排序：先显示可用的，再显示不可用的
        usort($available, function($a, $b) {
            // 可用的排前面
            if ($a->is_valid !== $b->is_valid) {
                return $b->is_valid - $a->is_valid;
            }
            // 同等可用性时按优惠金额降序
            return $b->discount_amount - $a->discount_amount;
        });

        return $available;
    }

    // =========================================
    // 前台展示
    // =========================================

    /**
     * 获取前台可领取的优惠券列表
     * 
     * @param int $user_id 当前用户ID（0表示未登录）
     * @param int $limit 返回数量限制，0 表示不限制
     * @return array
     */
    public function get_public_coupons($user_id = 0, $limit = 0) {
        global $wpdb;
        $user_id = (int) $user_id;
        $limit = (int) $limit;
        if ($limit < 0) {
            $limit = 0;
        }

        $now = current_time('mysql');

        $sql = "SELECT * FROM {$this->table_coupons} 
                WHERE status = 1 
                AND is_visible = 1
                AND (start_time IS NULL OR start_time <= %s)
                AND (end_time IS NULL OR end_time >= %s)
                AND (total_count = -1 OR claimed_count < total_count)
                ORDER BY sort_order ASC, id DESC";

        if ($limit > 0) {
            $sql .= " LIMIT %d";
            $coupons = $wpdb->get_results($wpdb->prepare($sql, $now, $now, $limit));
        } else {
            $coupons = $wpdb->get_results($wpdb->prepare($sql, $now, $now));
        }
        if (empty($coupons)) {
            return [];
        }

        $coupon_ids = [];
        foreach ($coupons as $coupon) {
            $coupon_ids[] = (int) $coupon->id;
        }

        $claim_count_map = [];
        $user_vip_level = 0;
        if ($user_id > 0 && !empty($coupon_ids)) {
            $placeholders = implode(',', array_fill(0, count($coupon_ids), '%d'));
            $claim_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT coupon_id, COUNT(*) AS claim_count
                 FROM {$this->table_claims}
                 WHERE user_id = %d
                   AND coupon_id IN ({$placeholders})
                 GROUP BY coupon_id",
                array_merge([$user_id], $coupon_ids)
            ));
            foreach ((array) $claim_rows as $row) {
                $claim_count_map[(int) $row->coupon_id] = (int) $row->claim_count;
            }
            $user_vip_level = (int) $this->get_user_vip_level($user_id);
        }

        $result = [];
        foreach ($coupons as $coupon) {
            $coupon = $this->parse_coupon($coupon);
            $coupon_id = (int) $coupon->id;
            $claimed_count = (int) ($claim_count_map[$coupon_id] ?? 0);

            $can_claim = $this->can_claim($coupon_id, $user_id, [
                'coupon' => $coupon,
                'claimed_count' => $claimed_count,
                'user_vip_level' => $user_vip_level,
            ]);
            $coupon->can_claim = !empty($can_claim['can_claim']);
            $coupon->claim_reason = (string) ($can_claim['reason'] ?? '');
            $coupon->is_claimed = $user_id > 0 ? $claimed_count > 0 : false;
            $result[] = $coupon;
        }

        return $result;
    }

    // =========================================
    // 辅助方法
    // =========================================

    /**
     * 解析优惠券数据
     */
    private function parse_coupon($coupon) {
        if (is_string($coupon->apply_items)) {
            $coupon->apply_items = json_decode($coupon->apply_items, true) ?: [];
        }
        if (is_string($coupon->apply_categories)) {
            $coupon->apply_categories = json_decode($coupon->apply_categories, true) ?: [];
        }
        if (is_string($coupon->allowed_vip_levels)) {
            $coupon->allowed_vip_levels = json_decode($coupon->allowed_vip_levels, true) ?: [];
        }
        if (is_string($coupon->use_vip_levels)) {
            $coupon->use_vip_levels = json_decode($coupon->use_vip_levels, true) ?: [];
        }
        $coupon->first_order_only = isset($coupon->first_order_only) ? (int) $coupon->first_order_only : 0;
        $first_order_scope = sanitize_key((string) ($coupon->first_order_scope ?? 'same_scope'));
        if (!in_array($first_order_scope, ['same_scope', 'all'], true)) {
            $first_order_scope = 'same_scope';
        }
        $coupon->first_order_scope = $first_order_scope;
        return $coupon;
    }

    /**
     * 校验新人首单券资格
     *
     * @param object $coupon
     * @param int $user_id
     * @param string $order_type
     * @return array ['valid' => bool, 'reason' => string]
     */
    private function validate_first_order_eligibility($coupon, $user_id, $order_type = '') {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return ['valid' => false, 'reason' => __('请先登录后使用优惠券', 'qilingshop')];
        }

        if ((int) ($coupon->first_order_only ?? 0) !== 1) {
            return ['valid' => true, 'reason' => ''];
        }

        $scope_mode = self::sanitize_first_order_scope($coupon->first_order_scope ?? 'same_scope');
        $check_type = self::resolve_first_order_check_type($coupon, $order_type, $scope_mode);
        if (self::user_has_paid_order($user_id, $check_type)) {
            if ($check_type === '') {
                return ['valid' => false, 'reason' => __('此券仅限全站首单用户使用', 'qilingshop')];
            }
            $labels = [
                'resource' => __('资源', 'qilingshop'),
                'shop'     => __('商城', 'qilingshop'),
                'vip'      => 'VIP',
                'recharge' => __('充值', 'qilingshop'),
            ];
            $type_label = $labels[$check_type] ?? __('当前场景', 'qilingshop');
            return ['valid' => false, 'reason' => sprintf(__('此券仅限%s首单用户使用', 'qilingshop'), $type_label)];
        }

        return ['valid' => true, 'reason' => ''];
    }

    /**
     * 首单范围值清洗
     *
     * @param string $scope
     * @return string same_scope|all
     */
    private static function sanitize_first_order_scope($scope) {
        $scope = sanitize_key((string) $scope);
        return in_array($scope, ['same_scope', 'all'], true) ? $scope : 'same_scope';
    }

    /**
     * 解析首单校验目标订单类型
     *
     * @param object $coupon
     * @param string $order_type
     * @param string $scope_mode
     * @return string ''|resource|shop|vip|recharge
     */
    private static function resolve_first_order_check_type($coupon, $order_type, $scope_mode) {
        if ($scope_mode === 'all') {
            return '';
        }

        $apply_scope = sanitize_key((string) ($coupon->apply_scope ?? ''));
        if (in_array($apply_scope, ['resource', 'shop', 'vip', 'recharge'], true)) {
            return $apply_scope;
        }

        $order_type = sanitize_key((string) $order_type);
        if (in_array($order_type, ['resource', 'shop', 'vip', 'recharge'], true)) {
            return $order_type;
        }

        return '';
    }

    /**
     * 判断用户是否已有已支付消费订单
     *
     * @param int $user_id
     * @param string $order_type 传空表示全站任意消费
     * @return bool
     */
    public static function user_has_paid_order($user_id, $order_type = '') {
        global $wpdb;

        $user_id = (int) $user_id;
        if ($user_id <= 0 || !$wpdb) {
            return false;
        }

        $order_type = sanitize_key((string) $order_type);
        if (!in_array($order_type, ['', 'resource', 'shop', 'vip', 'recharge'], true)) {
            $order_type = '';
        }

        static $paid_order_cache = [];
        $cache_key = $user_id . '|' . ($order_type === '' ? 'all' : $order_type);
        if (array_key_exists($cache_key, $paid_order_cache)) {
            return (bool) $paid_order_cache[$cache_key];
        }

        $table_exists = static function ($table_name) use ($wpdb) {
            static $cache = [];
            $table_name = (string) $table_name;
            if ($table_name === '') {
                return false;
            }
            if (array_key_exists($table_name, $cache)) {
                return $cache[$table_name];
            }
            $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
            $cache[$table_name] = ($exists === $table_name);
            return $cache[$table_name];
        };

        $resource_orders_table = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'orders';
        $recharge_table = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'recharge';
        $shop_prefix = defined('QLS_SHOP_TABLE_PREFIX') ? QLS_SHOP_TABLE_PREFIX : 'qls_shop_';
        $shop_orders_table = $wpdb->prefix . $shop_prefix . 'orders';

        $resource_paid_status = class_exists('QilingShop_Order') ? (int) QilingShop_Order::STATUS_PAID : 1;
        $resource_refunded_status = class_exists('QilingShop_Order') ? (int) QilingShop_Order::STATUS_REFUNDED : 3;
        $recharge_paid_status = class_exists('QilingShop_Recharge') ? (int) QilingShop_Recharge::STATUS_PAID : 1;
        $shop_paid_status = class_exists('QLS_Shop_Order') ? (int) QLS_Shop_Order::STATUS_PAID : 1;
        $shop_shipped_status = class_exists('QLS_Shop_Order') ? (int) QLS_Shop_Order::STATUS_SHIPPED : 2;
        $shop_completed_status = class_exists('QLS_Shop_Order') ? (int) QLS_Shop_Order::STATUS_COMPLETED : 3;
        $shop_refunding_status = class_exists('QLS_Shop_Order') ? (int) QLS_Shop_Order::STATUS_REFUNDING : 5;
        $shop_refunded_status = class_exists('QLS_Shop_Order') ? (int) QLS_Shop_Order::STATUS_REFUNDED : 6;

        $has_resource = static function ($target_type = '') use ($wpdb, $table_exists, $resource_orders_table, $resource_paid_status, $resource_refunded_status, $user_id) {
            if (!$table_exists($resource_orders_table)) {
                return false;
            }
            if ($target_type === 'resource') {
                $sql = $wpdb->prepare(
                    "SELECT 1 FROM `{$resource_orders_table}`
                     WHERE user_id = %d
                       AND (order_type = %s OR (order_type = '' AND post_id > 0))
                       AND status IN (%d, %d)
                     LIMIT 1",
                    $user_id,
                    'resource',
                    $resource_paid_status,
                    $resource_refunded_status
                );
            } else {
                $sql = $wpdb->prepare(
                    "SELECT 1 FROM `{$resource_orders_table}`
                     WHERE user_id = %d
                       AND order_type = %s
                       AND status IN (%d, %d)
                     LIMIT 1",
                    $user_id,
                    'vip',
                    $resource_paid_status,
                    $resource_refunded_status
                );
            }
            return (bool) $wpdb->get_var($sql);
        };

        $has_recharge = static function () use ($wpdb, $table_exists, $recharge_table, $recharge_paid_status, $user_id) {
            if (!$table_exists($recharge_table)) {
                return false;
            }
            $sql = $wpdb->prepare(
                "SELECT 1 FROM `{$recharge_table}`
                 WHERE user_id = %d
                   AND status = %d
                 LIMIT 1",
                $user_id,
                $recharge_paid_status
            );
            return (bool) $wpdb->get_var($sql);
        };

        $has_shop = static function () use ($wpdb, $table_exists, $shop_orders_table, $shop_paid_status, $shop_shipped_status, $shop_completed_status, $shop_refunding_status, $shop_refunded_status, $user_id) {
            if (!$table_exists($shop_orders_table)) {
                return false;
            }
            $sql = $wpdb->prepare(
                "SELECT 1 FROM `{$shop_orders_table}`
                 WHERE user_id = %d
                   AND status IN (%d, %d, %d, %d, %d)
                 LIMIT 1",
                $user_id,
                $shop_paid_status,
                $shop_shipped_status,
                $shop_completed_status,
                $shop_refunding_status,
                $shop_refunded_status
            );
            return (bool) $wpdb->get_var($sql);
        };

        $result = false;
        switch ($order_type) {
            case 'resource':
                $result = $has_resource('resource');
                break;
            case 'vip':
                $result = $has_resource('vip');
                break;
            case 'recharge':
                $result = $has_recharge();
                break;
            case 'shop':
                $result = $has_shop();
                break;
            default:
                $result = $has_resource('resource') || $has_resource('vip') || $has_recharge() || $has_shop();
                break;
        }

        $paid_order_cache[$cache_key] = (bool) $result;
        return (bool) $paid_order_cache[$cache_key];
    }

    /**
     * 生成随机优惠码
     */
    public function generate_code($length = 8) {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $code;
    }

    /**
     * 获取客户端IP
     */
    private function get_client_ip() {
        if (function_exists('qilingshop_security')) {
            return qilingshop_security()->get_client_ip();
        }

        $remote_addr = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
        return filter_var($remote_addr, FILTER_VALIDATE_IP) ? $remote_addr : '0.0.0.0';
    }

    /**
     * 获取用户VIP等级
     */
    private function get_user_vip_level($user_id) {
        // 调用主题的VIP等级获取函数
        if (function_exists('developer_get_user_vip_level')) {
            return developer_get_user_vip_level($user_id);
        }
        return 0;
    }

    /**
     * 更新过期的优惠券状态
     * 用于定时任务
     */
    public function update_expired_claims() {
        global $wpdb;

        $now = current_time('mysql');

        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table_claims} SET status = 2 WHERE status = 0 AND expires_at <= %s",
            $now
        ));
    }

    /**
     * 获取优惠券使用记录
     * 
     * @param int $coupon_id 优惠券ID
     * @param int $limit 数量限制
     * @param int $offset 偏移量
     * @return array
     */
    public function get_use_records($coupon_id, $limit = 20, $offset = 0) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT u.*, usr.display_name as user_name
             FROM {$this->table_uses} u
             INNER JOIN {$this->table_claims} cl ON cl.id = u.claim_id AND cl.status = %d
             LEFT JOIN {$wpdb->users} usr ON u.user_id = usr.ID
             WHERE u.coupon_id = %d
             ORDER BY u.created_at DESC
             LIMIT %d OFFSET %d",
            self::CLAIM_STATUS_USED,
            $coupon_id,
            $limit,
            $offset
        ));
    }

    /**
     * 获取用户的优惠券使用记录（最近N天）
     * 
     * @param int $user_id 用户ID
     * @param int $days 天数（默认15天）
     * @return array
     */
    public function get_user_usage_records($user_id, $days = 15) {
        global $wpdb;

        $date_limit = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        return $wpdb->get_results($wpdb->prepare(
            "SELECT u.*, c.code, c.name as coupon_name, c.discount_type, c.discount_value, 
                    c.max_discount, c.apply_scope
             FROM {$this->table_uses} u
             INNER JOIN {$this->table_claims} cl ON cl.id = u.claim_id AND cl.status = %d
             LEFT JOIN {$this->table_coupons} c ON u.coupon_id = c.id
             WHERE u.user_id = %d AND u.created_at >= %s
             ORDER BY u.created_at DESC",
            self::CLAIM_STATUS_USED,
            $user_id,
            $date_limit
        ));
    }

    /**
     * 获取优惠券统计数据
     * 
     * @param int $coupon_id 优惠券ID
     * @return array
     */
    public function get_coupon_stats($coupon_id) {
        global $wpdb;

        $coupon = $this->get($coupon_id);
        if (!$coupon) {
            return null;
        }

        $total_discount = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(u.discount_amount)
             FROM {$this->table_uses} u
             INNER JOIN {$this->table_claims} cl ON cl.id = u.claim_id AND cl.status = %d
             WHERE u.coupon_id = %d",
            self::CLAIM_STATUS_USED,
            $coupon_id
        ));

        return [
            'claimed_count' => $coupon->claimed_count,
            'used_count' => $coupon->used_count,
            'total_count' => $coupon->total_count,
            'total_discount' => $total_discount ?: 0,
            'usage_rate' => $coupon->claimed_count > 0 
                ? round($coupon->used_count / $coupon->claimed_count * 100, 1) 
                : 0,
        ];
    }

    // =========================================
    // 静态帮助方法（供AJAX处理器使用）
    // =========================================

    /**
     * 根据领取ID获取用户优惠券
     * 
     * @param int $claim_id 领取记录ID
     * @param int $user_id 用户ID
     * @return object|null
     */
    public static function get_user_coupon_by_claim_id($claim_id, $user_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX;
        $table_claims = $prefix . 'coupon_claims';
        $table_coupons = $prefix . 'coupons';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT c.*, cl.id as claim_id, cl.user_id, cl.status as claim_status,
                    CASE
                        WHEN cl.status = 0 THEN 'unused'
                        WHEN cl.status = 1 THEN 'used'
                        WHEN cl.status = 3 THEN 'reserved'
                        ELSE 'expired'
                    END as status
             FROM {$table_claims} cl
             JOIN {$table_coupons} c ON cl.coupon_id = c.id
             WHERE cl.id = %d AND cl.user_id = %d",
            $claim_id,
            $user_id
        ));
    }

    /**
     * 验证优惠券是否可用于订单
     * 
     * @param object $coupon 优惠券对象（含apply_scope, min_amount等）
     * @param string $order_type 订单类型
     * @param float $amount 订单金额
     * @return array ['valid' => bool, 'reason' => string]
     */
    public static function validate_coupon_for_order($coupon, $order_type, $amount, $items = [], $user_id = 0) {
        if (!$coupon) {
            return ['valid' => false, 'reason' => __('优惠券不存在', 'qilingshop')];
        }

        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            $user_id = (int) ($coupon->user_id ?? 0);
        }
        if ($user_id <= 0) {
            return ['valid' => false, 'reason' => __('请先登录后使用优惠券', 'qilingshop')];
        }

        // 检查状态
        if (isset($coupon->status) && $coupon->status !== 'unused') {
            return ['valid' => false, 'reason' => __('优惠券已使用或已过期', 'qilingshop')];
        }

        // 检查有效期
        if (!empty($coupon->end_time) && strtotime($coupon->end_time) < time()) {
            return ['valid' => false, 'reason' => __('优惠券已过期', 'qilingshop')];
        }

        // 检查适用范围
        $apply_scope = $coupon->apply_scope ?? 'all';
        if ($apply_scope !== 'all') {
            $allowed_types = is_array($apply_scope) ? $apply_scope : explode(',', $apply_scope);
            if (!in_array($order_type, $allowed_types)) {
                return ['valid' => false, 'reason' => __('优惠券不适用于此类订单', 'qilingshop')];
            }
        }

        // 检查最低消费
        $min_amount = floatval($coupon->min_amount ?? 0);
        if ($min_amount > 0 && $amount < $min_amount) {
            return ['valid' => false, 'reason' => sprintf(__('需满%.2f元可用', 'qilingshop'), $min_amount)];
        }

        // 检查可用VIP等级
        $use_vip_levels = [];
        if (!empty($coupon->use_vip_levels)) {
            $use_vip_levels = is_array($coupon->use_vip_levels)
                ? $coupon->use_vip_levels
                : (json_decode($coupon->use_vip_levels, true) ?: []);
        }
        if (!empty($use_vip_levels)) {
            $user_id = $user_id ?: (int) ($coupon->user_id ?? 0);
            $vip_level = 0;
            if ($user_id) {
                if (function_exists('developer_get_user_vip_level')) {
                    $vip_level = (int) developer_get_user_vip_level($user_id);
                } elseif (function_exists('qilingshop_get_user_vip_level')) {
                    $vip_level = (int) qilingshop_get_user_vip_level($user_id);
                } elseif (class_exists('QilingShop_VIP')) {
                    $vip_level = (int) QilingShop_VIP::instance()->get_user_level($user_id);
                }
            }
            if ($vip_level <= 0 || !in_array($vip_level, $use_vip_levels, true)) {
                return ['valid' => false, 'reason' => __('当前VIP等级不可使用此优惠券', 'qilingshop')];
            }
        }

        // 检查指定商品/文章
        $apply_items = [];
        if (!empty($coupon->apply_items)) {
            $apply_items = is_array($coupon->apply_items)
                ? $coupon->apply_items
                : (json_decode($coupon->apply_items, true) ?: []);
        }
        if (!empty($apply_items) && in_array($order_type, ['resource', 'shop'], true)) {
            if (empty($items) || empty(array_intersect($apply_items, (array) $items))) {
                return ['valid' => false, 'reason' => __('优惠券不适用于当前商品', 'qilingshop')];
            }
        }

        // 检查指定分类
        $apply_categories = [];
        if (!empty($coupon->apply_categories)) {
            $apply_categories = is_array($coupon->apply_categories)
                ? $coupon->apply_categories
                : (json_decode($coupon->apply_categories, true) ?: []);
        }
        if (!empty($apply_categories) && in_array($order_type, ['resource', 'shop'], true) && !empty($items)) {
            $has_valid_category = false;
            if ($order_type === 'shop') {
                foreach ((array) $items as $product_id) {
                    $product = function_exists('qls_product') ? qls_product()->get($product_id) : null;
                    if ($product && !empty($product->category_id)) {
                        if (in_array((int) $product->category_id, $apply_categories, true)) {
                            $has_valid_category = true;
                            break;
                        }
                    }
                }
            } else {
                foreach ((array) $items as $post_id) {
                    $term_ids = wp_get_post_terms((int) $post_id, 'category', ['fields' => 'ids']);
                    if (!is_wp_error($term_ids) && !empty($term_ids)) {
                        if (array_intersect($apply_categories, $term_ids)) {
                            $has_valid_category = true;
                            break;
                        }
                    }
                }
            }
            if (!$has_valid_category) {
                return ['valid' => false, 'reason' => __('优惠券不适用于当前分类', 'qilingshop')];
            }
        }

        $first_order_only = isset($coupon->first_order_only) ? (int) $coupon->first_order_only : 0;
        if ($first_order_only === 1) {
            $scope_mode = self::sanitize_first_order_scope($coupon->first_order_scope ?? 'same_scope');
            $check_type = self::resolve_first_order_check_type($coupon, $order_type, $scope_mode);
            if (self::user_has_paid_order($user_id, $check_type)) {
                if ($check_type === '') {
                    return ['valid' => false, 'reason' => __('此券仅限全站首单用户使用', 'qilingshop')];
                }
                $labels = [
                    'resource' => __('资源', 'qilingshop'),
                    'shop'     => __('商城', 'qilingshop'),
                    'vip'      => 'VIP',
                    'recharge' => __('充值', 'qilingshop'),
                ];
                $type_label = $labels[$check_type] ?? __('当前场景', 'qilingshop');
                return ['valid' => false, 'reason' => sprintf(__('此券仅限%s首单用户使用', 'qilingshop'), $type_label)];
            }
        }

        return ['valid' => true, 'reason' => ''];
    }

    /**
     * 静态计算优惠金额
     * 
     * @param object $coupon 优惠券对象
     * @param float $amount 订单金额
     * @return float 优惠金额
     */
    public static function calc_discount_amount($coupon, $amount) {
        if (!$coupon || $amount <= 0) {
            return 0;
        }

        $discount_type = $coupon->discount_type ?? 'fixed';
        $discount_value = floatval($coupon->discount_value ?? 0);
        $max_discount = floatval($coupon->max_discount ?? 0);

        if ($discount_type === 'fixed') {
            $discount = min($discount_value, $amount);
        } else {
            // 百分比
            $discount = $amount * ($discount_value / 100);
            if ($max_discount > 0) {
                $discount = min($discount, $max_discount);
            }
        }

        return round(min($discount, $amount), 2);
    }

    /**
     * 静态预占优惠券，避免多个待支付订单共用同一张券。
     *
     * @param int    $claim_id        领取记录ID
     * @param string $order_no        订单号
     * @param string $order_type      订单类型
     * @param float  $order_amount    订单金额
     * @param float  $discount_amount 优惠金额
     * @param bool   $use_transaction 是否在本方法内开启事务
     * @return bool
     */
    public static function reserve_for_order($claim_id, $order_no, $order_type, $order_amount = 0, $discount_amount = 0, $use_transaction = true) {
        global $wpdb;
        $prefix = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX;
        $table_claims = $prefix . 'coupon_claims';
        $table_uses = $prefix . 'coupon_uses';

        $claim_id = absint($claim_id);
        $order_no = sanitize_text_field((string) $order_no);
        $order_type = sanitize_key((string) $order_type);

        if ($claim_id <= 0 || $order_no === '' || $order_type === '') {
            return false;
        }

        if ($use_transaction) {
            $wpdb->query('START TRANSACTION');
        }

        try {
            $claim = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_claims} WHERE id = %d LIMIT 1 FOR UPDATE",
                $claim_id
            ));

            if (!$claim) {
                throw new Exception('Coupon claim not found');
            }

            $existing_use = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_uses} WHERE claim_id = %d LIMIT 1",
                $claim_id
            ));

            if (in_array((int) $claim->status, [self::CLAIM_STATUS_RESERVED, self::CLAIM_STATUS_USED], true)) {
                if ($existing_use && (string) $existing_use->order_no === (string) $order_no) {
                    if ($use_transaction) {
                        $wpdb->query('COMMIT');
                    }
                    return true;
                }
                throw new Exception('Coupon already reserved or used');
            }

            if ((int) $claim->status !== self::CLAIM_STATUS_UNUSED) {
                throw new Exception('Coupon claim is not available');
            }

            if (!empty($claim->expires_at) && (string) $claim->expires_at <= current_time('mysql')) {
                throw new Exception('Coupon claim expired');
            }

            if ($existing_use) {
                throw new Exception('Coupon use record already exists');
            }

            $result = $wpdb->insert($table_uses, [
                'coupon_id' => $claim->coupon_id,
                'claim_id' => $claim_id,
                'user_id' => $claim->user_id,
                'order_type' => $order_type,
                'order_no' => $order_no,
                'order_amount' => $order_amount,
                'discount_amount' => $discount_amount,
                'ip_address' => function_exists('qilingshop_security') ? qilingshop_security()->get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? ''),
            ]);

            if (!$result) {
                throw new Exception('Failed to reserve coupon use');
            }

            $updated = $wpdb->update(
                $table_claims,
                [
                    'status' => self::CLAIM_STATUS_RESERVED,
                    'used_at' => null,
                ],
                [
                    'id' => $claim_id,
                    'status' => self::CLAIM_STATUS_UNUSED,
                ]
            );

            if ($updated === false || (int) $updated !== 1) {
                throw new Exception('Failed to reserve coupon claim');
            }

            if ($use_transaction) {
                $wpdb->query('COMMIT');
            }

            return true;
        } catch (Exception $e) {
            if ($use_transaction) {
                $wpdb->query('ROLLBACK');
            }
            return false;
        }
    }

    /**
     * 静态标记优惠券为已使用
     * 
     * @param int $claim_id 领取记录ID
     * @param string $order_no 订单号
     * @param string $order_type 订单类型
     * @return bool
     */
    public static function mark_as_used($claim_id, $order_no, $order_type, $order_amount = 0, $discount_amount = 0, $use_transaction = true) {
        global $wpdb;
        $prefix = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX;
        $table_claims = $prefix . 'coupon_claims';
        $table_uses = $prefix . 'coupon_uses';
        $table_coupons = $prefix . 'coupons';

        $claim_id = absint($claim_id);
        $order_no = sanitize_text_field((string) $order_no);
        $order_type = sanitize_key((string) $order_type);

        if ($claim_id <= 0 || $order_no === '' || $order_type === '') {
            return false;
        }

        if ($use_transaction) {
            $wpdb->query('START TRANSACTION');
        }

        try {
            $claim = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_claims} WHERE id = %d LIMIT 1 FOR UPDATE",
                $claim_id
            ));

            if (!$claim) {
                throw new Exception('Coupon claim not found');
            }

            $existing_use = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_uses} WHERE claim_id = %d LIMIT 1",
                $claim_id
            ));
            $is_same_order = $existing_use && (string) $existing_use->order_no === (string) $order_no;
            $claim_status = (int) $claim->status;

            if ($claim_status === self::CLAIM_STATUS_USED) {
                if ($is_same_order) {
                    if ($use_transaction) {
                        $wpdb->query('COMMIT');
                    }
                    return true;
                }
                throw new Exception('Coupon already used');
            }

            if ($claim_status === self::CLAIM_STATUS_RESERVED) {
                if (!$is_same_order) {
                    throw new Exception('Coupon reserved by another order');
                }

                $updated = $wpdb->update(
                    $table_claims,
                    [
                        'status' => self::CLAIM_STATUS_USED,
                        'used_at' => current_time('mysql'),
                    ],
                    [
                        'id' => $claim_id,
                        'status' => self::CLAIM_STATUS_RESERVED,
                    ]
                );

                if ($updated === false || (int) $updated !== 1) {
                    throw new Exception('Failed to confirm coupon use');
                }

                $count_updated = $wpdb->query($wpdb->prepare(
                    "UPDATE {$table_coupons} SET used_count = used_count + 1 WHERE id = %d",
                    $claim->coupon_id
                ));

                if ($count_updated === false || (int) $count_updated !== 1) {
                    throw new Exception('Failed to update coupon used count');
                }

                if ($use_transaction) {
                    $wpdb->query('COMMIT');
                }

                return true;
            }

            if ($claim_status !== self::CLAIM_STATUS_UNUSED) {
                throw new Exception('Coupon claim is not available');
            }

            if (!empty($claim->expires_at) && (string) $claim->expires_at <= current_time('mysql')) {
                throw new Exception('Coupon claim expired');
            }

            if ($existing_use) {
                throw new Exception('Coupon use record already exists');
            }

            $result = $wpdb->insert($table_uses, [
                'coupon_id' => $claim->coupon_id,
                'claim_id' => $claim_id,
                'user_id' => $claim->user_id,
                'order_type' => $order_type,
                'order_no' => $order_no,
                'order_amount' => $order_amount,
                'discount_amount' => $discount_amount,
                'ip_address' => function_exists('qilingshop_security') ? qilingshop_security()->get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? ''),
            ]);

            if (!$result) {
                $duplicate = stripos((string) $wpdb->last_error, 'Duplicate entry') !== false;
                if ($duplicate) {
                    $existing_use = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$table_uses} WHERE claim_id = %d LIMIT 1",
                        $claim_id
                    ));
                    if ($existing_use && (string) $existing_use->order_no === (string) $order_no) {
                        if ($use_transaction) {
                            $wpdb->query('COMMIT');
                        }
                        return true;
                    }
                }
                throw new Exception('Failed to record coupon use');
            }

            $updated = $wpdb->update(
                $table_claims,
                [
                    'status' => self::CLAIM_STATUS_USED,
                    'used_at' => current_time('mysql'),
                ],
                [
                    'id' => $claim_id,
                    'status' => self::CLAIM_STATUS_UNUSED,
                ]
            );

            if ($updated === false || (int) $updated !== 1) {
                throw new Exception('Failed to update coupon claim status');
            }

            $count_updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$table_coupons} SET used_count = used_count + 1 WHERE id = %d",
                $claim->coupon_id
            ));

            if ($count_updated === false || (int) $count_updated !== 1) {
                throw new Exception('Failed to update coupon used count');
            }

            if ($use_transaction) {
                $wpdb->query('COMMIT');
            }

            return true;
        } catch (Exception $e) {
            if ($use_transaction) {
                $wpdb->query('ROLLBACK');
            }
            return false;
        }
    }

    /**
     * 释放待支付订单的优惠券预占，不影响已正式核销的优惠券。
     *
     * @param int    $claim_id
     * @param string $order_no
     * @param bool   $use_transaction
     * @return bool
     */
    public static function release_reservation($claim_id, $order_no = '', $use_transaction = true) {
        global $wpdb;
        $prefix = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX;
        $table_claims = $prefix . 'coupon_claims';
        $table_uses = $prefix . 'coupon_uses';

        $claim_id = absint($claim_id);
        $order_no = sanitize_text_field((string) $order_no);
        if ($claim_id <= 0) {
            return false;
        }

        if ($use_transaction) {
            $wpdb->query('START TRANSACTION');
        }

        try {
            $claim = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_claims} WHERE id = %d LIMIT 1 FOR UPDATE",
                $claim_id
            ));

            if (!$claim) {
                throw new Exception('Coupon claim not found');
            }

            if ((int) $claim->status !== self::CLAIM_STATUS_RESERVED) {
                if ($use_transaction) {
                    $wpdb->query('COMMIT');
                }
                return true;
            }

            $existing_use = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_uses} WHERE claim_id = %d LIMIT 1",
                $claim_id
            ));

            if ($order_no !== '' && !$existing_use) {
                throw new Exception('Coupon reservation record not found');
            }

            if ($order_no !== '' && $existing_use && (string) $existing_use->order_no !== (string) $order_no) {
                throw new Exception('Coupon reserved by another order');
            }

            if ($existing_use) {
                $deleted = $wpdb->delete($table_uses, ['claim_id' => $claim_id]);
                if ($deleted === false || (int) $deleted !== 1) {
                    throw new Exception('Failed to delete coupon reservation');
                }
            }

            $updated = $wpdb->update(
                $table_claims,
                [
                    'status' => self::CLAIM_STATUS_UNUSED,
                    'used_at' => null,
                ],
                [
                    'id' => $claim_id,
                    'status' => self::CLAIM_STATUS_RESERVED,
                ]
            );

            if ($updated === false || (int) $updated !== 1) {
                throw new Exception('Failed to release coupon reservation');
            }

            if ($use_transaction) {
                $wpdb->query('COMMIT');
            }

            return true;
        } catch (Exception $e) {
            if ($use_transaction) {
                $wpdb->query('ROLLBACK');
            }
            return false;
        }
    }

    /**
     * 按订单号释放预占或回滚当前订单已核销的优惠券。
     *
     * 仅在确认当前订单失败且需要撤销本订单优惠券占用时使用。
     *
     * @param int    $claim_id
     * @param string $order_no
     * @param bool   $use_transaction
     * @return bool
     */
    public static function release_or_restore_for_order($claim_id, $order_no, $use_transaction = true) {
        global $wpdb;
        $prefix = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX;
        $table_claims = $prefix . 'coupon_claims';
        $table_uses = $prefix . 'coupon_uses';
        $table_coupons = $prefix . 'coupons';

        $claim_id = absint($claim_id);
        $order_no = sanitize_text_field((string) $order_no);
        if ($claim_id <= 0 || $order_no === '') {
            return false;
        }

        if ($use_transaction) {
            $wpdb->query('START TRANSACTION');
        }

        try {
            $claim = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_claims} WHERE id = %d LIMIT 1 FOR UPDATE",
                $claim_id
            ));

            if (!$claim) {
                throw new Exception('Coupon claim not found');
            }

            $existing_use = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_uses} WHERE claim_id = %d LIMIT 1",
                $claim_id
            ));

            if ($existing_use && (string) $existing_use->order_no !== (string) $order_no) {
                throw new Exception('Coupon belongs to another order');
            }

            $claim_status = (int) $claim->status;
            if (!$existing_use && $claim_status === self::CLAIM_STATUS_UNUSED) {
                if ($use_transaction) {
                    $wpdb->query('COMMIT');
                }
                return true;
            }

            if (!$existing_use) {
                throw new Exception('Coupon order reservation record not found');
            }

            if (!in_array($claim_status, [self::CLAIM_STATUS_RESERVED, self::CLAIM_STATUS_USED], true)) {
                throw new Exception('Coupon claim is not releasable');
            }

            $deleted = $wpdb->delete($table_uses, ['claim_id' => $claim_id]);
            if ($deleted === false || (int) $deleted !== 1) {
                throw new Exception('Failed to delete coupon use record');
            }

            $updated = $wpdb->update(
                $table_claims,
                [
                    'status'  => self::CLAIM_STATUS_UNUSED,
                    'used_at' => null,
                ],
                ['id' => $claim_id]
            );

            if ($updated === false) {
                throw new Exception('Failed to restore coupon claim status');
            }

            if ($claim_status === self::CLAIM_STATUS_USED) {
                $count_updated = $wpdb->query($wpdb->prepare(
                    "UPDATE {$table_coupons} SET used_count = used_count - 1 WHERE id = %d AND used_count > 0",
                    $claim->coupon_id
                ));
                if ($count_updated === false) {
                    throw new Exception('Failed to decrement coupon used count');
                }
            }

            if ($use_transaction) {
                $wpdb->query('COMMIT');
            }

            return true;
        } catch (Exception $e) {
            if ($use_transaction) {
                $wpdb->query('ROLLBACK');
            }
            return false;
        }
    }

    /**
     * 静态归还优惠券（将已使用的优惠券恢复为未使用）
     * 
     * @param int $claim_id 领取记录ID
     * @return bool
     */
    public static function restore_coupon($claim_id, $use_transaction = true) {
        global $wpdb;
        $prefix = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX;
        $table_claims = $prefix . 'coupon_claims';
        $table_uses = $prefix . 'coupon_uses';
        $table_coupons = $prefix . 'coupons';

        $claim_id = absint($claim_id);
        if ($claim_id <= 0) {
            return false;
        }

        if ($use_transaction) {
            $wpdb->query('START TRANSACTION');
        }

        try {
            $claim = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_claims} WHERE id = %d LIMIT 1 FOR UPDATE",
                $claim_id
            ));

            if (!$claim) {
                throw new Exception('Coupon claim not found');
            }

            $claim_status = (int) $claim->status;

            $existing_use = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_uses} WHERE claim_id = %d LIMIT 1",
                $claim_id
            ));

            if (!$existing_use && $claim_status === self::CLAIM_STATUS_UNUSED) {
                if ($use_transaction) {
                    $wpdb->query('COMMIT');
                }
                return true;
            }

            if (!$existing_use) {
                if ($claim_status === self::CLAIM_STATUS_RESERVED) {
                    $updated = $wpdb->update(
                        $table_claims,
                        [
                            'status' => self::CLAIM_STATUS_UNUSED,
                            'used_at' => null,
                        ],
                        ['id' => $claim_id]
                    );
                    if ($updated === false) {
                        throw new Exception('Failed to restore reserved coupon claim');
                    }

                    if ($use_transaction) {
                        $wpdb->query('COMMIT');
                    }
                    return true;
                }

                throw new Exception('Coupon use record not found');
            }

            $deleted = $wpdb->delete($table_uses, ['claim_id' => $claim_id]);
            if ($deleted === false || (int) $deleted !== 1) {
                throw new Exception('Failed to delete coupon use record');
            }

            $updated = $wpdb->update(
                $table_claims,
                [
                    'status' => self::CLAIM_STATUS_UNUSED,
                    'used_at' => null,
                ],
                ['id' => $claim_id]
            );

            if ($updated === false) {
                throw new Exception('Failed to restore coupon claim status');
            }

            if ($claim_status === self::CLAIM_STATUS_USED) {
                $count_updated = $wpdb->query($wpdb->prepare(
                    "UPDATE {$table_coupons} SET used_count = used_count - 1 WHERE id = %d AND used_count > 0",
                    $claim->coupon_id
                ));

                if ($count_updated === false) {
                    throw new Exception('Failed to decrement coupon used count');
                }
            }

            if ($use_transaction) {
                $wpdb->query('COMMIT');
            }

            return true;
        } catch (Exception $e) {
            if ($use_transaction) {
                $wpdb->query('ROLLBACK');
            }
            return false;
        }
    }
}
