<?php
/**
 * 商品评价核心类
 * 
 * 处理商品评价的创建、查询、审核等业务逻辑
 *
 * @package QilingShop
 * @since   2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_Review {
    const REWARD_RETRY_OPTION = 'qls_review_reward_retry_queue';
    const REWARD_RETRY_LOCK_OPTION = 'qls_review_reward_retry_lock';
    const REWARD_QUEUE_LOCK_OPTION = 'qls_review_reward_retry_write_lock';
    const REWARD_RETRY_LOCK_TTL = 600;
    const REWARD_QUEUE_LOCK_TTL = 30;
    const REWARD_QUEUE_LOCK_RETRIES = 3;
    const REWARD_QUEUE_LOCK_BACKOFF_US = 120000;
    const REWARD_SHADOW_PREFIX = 'qls_review_reward_retry_entry_';
    
    /**
     * 单例实例
     */
    private static $instance = null;
    
    /**
     * 数据表名
     */
    private $table;
    private $table_items;
    private $table_products;
    private $table_likes;
    
    /**
     * 评价状态常量
     */
    const STATUS_PENDING = 0;   // 待审核
    const STATUS_APPROVED = 1;  // 已通过
    const STATUS_HIDDEN = 2;    // 已隐藏

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
        $prefix = $wpdb->prefix . 'qls_shop_';
        $this->table = $prefix . 'reviews';
        $this->table_items = $prefix . 'order_items';
        $this->table_products = $prefix . 'products';
        $this->table_likes = $prefix . 'review_likes';
        add_action('qilingshop_daily_task_check', [$this, 'process_reward_retry_queue'], 25, 1);
    }

    /**
     * 规范化前台评价排序字段，避免 ORDER BY 注入。
     *
     * @param string $orderby
     * @return string
     */
    private function normalize_public_review_orderby($orderby) {
        $orderby = strtolower(trim((string) $orderby));
        $orderby = preg_replace('/\s+/', ' ', $orderby);

        $allowed = [
            'created_at'              => 'r.created_at',
            'rating'                  => 'r.rating',
            'like_count'              => 'r.like_count',
            'is_top'                  => 'r.is_top',
            'is_top desc, created_at' => 'r.is_top DESC, r.created_at',
        ];

        return $allowed[$orderby] ?? 'r.is_top DESC, r.created_at';
    }

    /**
     * 创建评价
     * 
     * @param array $data 评价数据
     * @return int|WP_Error 评价ID或错误
     */
    public function create($data) {
        global $wpdb;
        
        // 验证必填字段
        $required = ['order_id', 'order_item_id', 'product_id', 'user_id', 'rating', 'content'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return new WP_Error('missing_field', sprintf(__('缺少必填字段: %s', 'qilingshop'), $field));
            }
        }
        
        // 验证评分范围
        $rating = intval($data['rating']);
        if ($rating < 1 || $rating > 5) {
            return new WP_Error('invalid_rating', __('评分必须在1-5之间', 'qilingshop'));
        }
        
        // 验证内容长度
        $min_length = get_option('qls_shop_review_min_length', 10);
        if (mb_strlen($data['content']) < $min_length) {
            return new WP_Error('content_too_short', sprintf(__('评价内容至少%d个字', 'qilingshop'), $min_length));
        }
        
        // 过滤XSS
        $content = wp_kses_post($data['content']);
        
        // 处理图片
        $images = isset($data['images']) ? $data['images'] : [];
        if (is_array($images)) {
            $max_images = get_option('qls_shop_review_image_max', 9);
            $images = array_slice($images, 0, $max_images);
            $images = array_filter($images, function($url) {
                return filter_var($url, FILTER_VALIDATE_URL);
            });
        }
        
        // 确定初始状态
        $auto_approve = get_option('qls_shop_review_auto_approve', false);
        $status = $auto_approve ? self::STATUS_APPROVED : self::STATUS_PENDING;
        
        // 插入评价
        $insert_data = [
            'order_id'      => intval($data['order_id']),
            'order_item_id' => intval($data['order_item_id']),
            'product_id'    => intval($data['product_id']),
            'sku_id'        => isset($data['sku_id']) ? intval($data['sku_id']) : 0,
            'user_id'       => intval($data['user_id']),
            'rating'        => $rating,
            'content'       => $content,
            'images'        => !empty($images) ? wp_json_encode($images) : null,
            'sku_info'      => isset($data['sku_info']) ? sanitize_text_field($data['sku_info']) : null,
            'is_anonymous'  => !empty($data['is_anonymous']) ? 1 : 0,
            'status'        => $status,
            'created_at'    => current_time('mysql'),
        ];
        
        $review_id = 0;

        $wpdb->query('START TRANSACTION');

        try {
            $item = $this->get_reviewable_item($data['order_item_id'], true);
            $can_review = $this->validate_reviewable_item($item, $data['user_id']);
            if (is_wp_error($can_review)) {
                throw new Exception($can_review->get_error_message());
            }

            $insert_data['order_id'] = (int) $item->order_id;
            $insert_data['product_id'] = (int) $item->product_id;
            $insert_data['sku_id'] = (int) ($item->sku_id ?? 0);

            $result = $wpdb->insert($this->table, $insert_data);
            if ($result === false) {
                $existing_review = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$this->table} WHERE order_item_id = %d LIMIT 1",
                    $data['order_item_id']
                ));
                if ($existing_review) {
                    throw new Exception(__('该商品已评价', 'qilingshop'));
                }
                throw new Exception(__('保存评价失败', 'qilingshop'));
            }

            $review_id = (int) $wpdb->insert_id;

            $updated = $wpdb->update(
                $this->table_items,
                ['is_reviewed' => 1],
                ['id' => $data['order_item_id'], 'is_reviewed' => 0]
            );
            if ($updated === false || (int) $updated !== 1) {
                throw new Exception(__('评价状态更新失败', 'qilingshop'));
            }

            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            $message = $e->getMessage() ?: __('保存评价失败', 'qilingshop');
            if ($message === __('该商品已评价', 'qilingshop')) {
                return new WP_Error('already_reviewed', $message);
            }
            return new WP_Error('db_error', $message);
        }
        
        // 如果自动审核通过，同步商品统计
        if ($status === self::STATUS_APPROVED) {
            $this->sync_product_stats($insert_data['product_id']);
            $this->reward_points($data['user_id'], !empty($images), $review_id);
            $this->reward_growth((int) $data['user_id'], !empty($images), $review_id, [
                'product_id' => (int) $insert_data['product_id'],
                'order_id' => (int) $insert_data['order_id'],
            ]);
        }

        return $review_id;
    }

    /**
     * 获取单条评价
     * 
     * @param int $id 评价ID
     * @return object|null
     */
    public function get($id) {
        global $wpdb;
        
        $review = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $id
        ));
        
        if ($review && $review->images) {
            $review->images = json_decode($review->images, true);
        }
        
        return $review;
    }

    /**
     * 获取商品评价列表
     * 
     * @param int   $product_id 商品ID
     * @param array $args       查询参数
     * @return array
     */
    public function get_by_product($product_id, $args = []) {
        global $wpdb;
        
        $defaults = [
            'status'   => self::STATUS_APPROVED,  // 默认只显示已审核的
            'rating'   => null,                    // 按评分筛选
            'has_image'=> null,                    // 是否有图
            'page'     => 1,
            'per_page' => 10,
            'orderby'  => 'is_top DESC, created_at',
            'order'    => 'DESC',
        ];
        $args = wp_parse_args($args, $defaults);
        $args['page'] = max(1, absint($args['page']));
        $args['per_page'] = min(100, max(1, absint($args['per_page'])));
        $orderby_sql = $this->normalize_public_review_orderby($args['orderby']);
        $order_sql = strtoupper((string) $args['order']) === 'ASC' ? 'ASC' : 'DESC';
	        
        $where = ["r.product_id = %d"];
        $params = [$product_id];
        
        // 状态筛选
        if ($args['status'] !== null) {
            $where[] = "r.status = %d";
            $params[] = $args['status'];
        }
        
        // 评分筛选
        if ($args['rating'] !== null) {
            if ($args['rating'] === 'good') {
                $where[] = "r.rating >= 4";
            } elseif ($args['rating'] === 'medium') {
                $where[] = "r.rating = 3";
            } elseif ($args['rating'] === 'bad') {
                $where[] = "r.rating <= 2";
            } elseif (is_numeric($args['rating'])) {
                $where[] = "r.rating = %d";
                $params[] = intval($args['rating']);
            }
        }
        
        // 有图筛选
        if ($args['has_image'] === true) {
            $where[] = "r.images IS NOT NULL AND r.images != '[]'";
        }
        
        $where_sql = implode(' AND ', $where);
        $offset = ($args['page'] - 1) * $args['per_page'];
        
        // 获取总数
        $count_sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} r WHERE {$where_sql}",
            ...$params
        );
        $total = $wpdb->get_var($count_sql);
        
        // 获取列表
        $sql = $wpdb->prepare(
            "SELECT r.*, u.display_name as user_name, u.user_email
	             FROM {$this->table} r
	             LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
	             WHERE {$where_sql}
	             ORDER BY {$orderby_sql} {$order_sql}
	             LIMIT %d OFFSET %d",
            ...array_merge($params, [$args['per_page'], $offset])
        );
        
        $reviews = $wpdb->get_results($sql);
        
        // 处理每条评价
        foreach ($reviews as &$review) {
            if ($review->images) {
                $review->images = json_decode($review->images, true);
            } else {
                $review->images = [];
            }
            
            // 匿名处理
            if ($review->is_anonymous) {
                $review->user_name = $this->mask_name($review->user_name);
            }
        }
        
        return [
            'items'      => $reviews,
            'total'      => intval($total),
            'page'       => $args['page'],
            'per_page'   => $args['per_page'],
            'total_pages'=> ceil($total / $args['per_page']),
        ];
    }

    /**
     * 获取用户评价列表
     * 
     * @param int   $user_id 用户ID
     * @param array $args    查询参数
     * @return array
     */
    public function get_by_user($user_id, $args = []) {
        global $wpdb;
        
        $defaults = [
            'page'     => 1,
            'per_page' => 10,
        ];
        $args = wp_parse_args($args, $defaults);
        
        $offset = ($args['page'] - 1) * $args['per_page'];
        
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE user_id = %d",
            $user_id
        ));
        
        $reviews = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $user_id, $args['per_page'], $offset
        ));
        
        foreach ($reviews as &$review) {
            if ($review->images) {
                $review->images = json_decode($review->images, true);
            }
        }
        
        return [
            'items' => $reviews,
            'total' => intval($total),
            'page'  => $args['page'],
        ];
    }

    /**
     * 获取订单的评价
     * 
     * @param int $order_id 订单ID
     * @return array
     */
    public function get_by_order($order_id) {
        global $wpdb;
        
        $reviews = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE order_id = %d",
            $order_id
        ));
        
        foreach ($reviews as &$review) {
            if ($review->images) {
                $review->images = json_decode($review->images, true);
            }
        }
        
        return $reviews;
    }

    /**
     * 获取订单项的评价
     * 
     * @param int $order_item_id 订单项ID
     * @return object|null
     */
    public function get_by_order_item($order_item_id) {
        global $wpdb;
        
        $review = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE order_item_id = %d",
            $order_item_id
        ));
        
        if ($review && $review->images) {
            $review->images = json_decode($review->images, true);
        }
        
        return $review;
    }

    /**
     * 审核通过
     * 
     * @param int $id 评价ID
     * @return bool
     */
    public function approve($id) {
        global $wpdb;
        
        $review = $this->get($id);
        if (!$review) {
            return false;
        }
        
        $result = $wpdb->update(
            $this->table,
            ['status' => self::STATUS_APPROVED],
            ['id' => $id]
        );
        
        if ($result !== false) {
            $this->sync_product_stats($review->product_id);
            $review_images = is_array($review->images) ? $review->images : [];
            $this->reward_points((int) $review->user_id, !empty($review_images), (int) $review->id);
            $this->reward_growth((int) $review->user_id, !empty($review_images), (int) $review->id, [
                'product_id' => (int) $review->product_id,
                'order_id' => (int) $review->order_id,
            ]);
            return true;
        }
        
        return false;
    }

    /**
     * 隐藏评价
     * 
     * @param int $id 评价ID
     * @return bool
     */
    public function hide($id) {
        global $wpdb;
        
        $review = $this->get($id);
        if (!$review) {
            return false;
        }
        
        $result = $wpdb->update(
            $this->table,
            ['status' => self::STATUS_HIDDEN],
            ['id' => $id]
        );
        
        if ($result !== false) {
            $this->sync_product_stats($review->product_id);
            return true;
        }
        
        return false;
    }

    /**
     * 删除评价
     * 
     * @param int $id 评价ID
     * @return bool
     */
    public function delete($id) {
        global $wpdb;
        
        $review = $this->get($id);
        if (!$review) {
            return false;
        }
        
        $result = $wpdb->delete($this->table, ['id' => $id]);
        
        if ($result !== false) {
            // 恢复订单项未评价状态
            $wpdb->update(
                $this->table_items,
                ['is_reviewed' => 0],
                ['id' => $review->order_item_id]
            );
            
            $this->sync_product_stats($review->product_id);
            return true;
        }
        
        return false;
    }

    /**
     * 切换置顶状态
     * 
     * @param int $id 评价ID
     * @return bool
     */
    public function toggle_top($id) {
        global $wpdb;
        
        $review = $this->get($id);
        if (!$review) {
            return false;
        }
        
        $new_status = $review->is_top ? 0 : 1;
        
        return $wpdb->update(
            $this->table,
            ['is_top' => $new_status],
            ['id' => $id]
        ) !== false;
    }

    /**
     * 商家回复
     * 
     * @param int    $id      评价ID
     * @param string $content 回复内容
     * @return bool
     */
    public function reply($id, $content) {
        global $wpdb;
        
        $content = wp_kses_post($content);
        
        return $wpdb->update(
            $this->table,
            [
                'admin_reply' => $content,
                'reply_time'  => current_time('mysql'),
            ],
            ['id' => $id]
        ) !== false;
    }

    /**
     * 检查是否可以评价
     * 
     * @param int $order_item_id 订单项ID
     * @param int $user_id       用户ID
     * @return true|WP_Error
     */
    public function can_review($order_item_id, $user_id) {
        $item = $this->get_reviewable_item($order_item_id, false);
        return $this->validate_reviewable_item($item, $user_id);
    }

    /**
     * 获取可评价订单项。
     *
     * @param int  $order_item_id
     * @param bool $for_update
     * @return object|null
     */
    private function get_reviewable_item($order_item_id, $for_update = false) {
        global $wpdb;

        $order_item_id = (int) $order_item_id;
        if ($order_item_id <= 0) {
            return null;
        }

        $sql = $wpdb->prepare(
            "SELECT i.*, o.user_id as order_user_id, o.status as order_status, o.completed_at
             FROM {$this->table_items} i
             JOIN {$wpdb->prefix}qls_shop_orders o ON i.order_id = o.id
             WHERE i.id = %d",
            $order_item_id
        );

        if ($for_update) {
            $sql .= ' FOR UPDATE';
        }

        return $wpdb->get_row($sql);
    }

    /**
     * 校验订单项是否可评价。
     *
     * @param object|null $item
     * @param int         $user_id
     * @return true|WP_Error
     */
    private function validate_reviewable_item($item, $user_id) {
        if (!$item) {
            return new WP_Error('item_not_found', __('订单项不存在', 'qilingshop'));
        }

        if (get_option('qls_shop_review_require_purchase', true) && (int) $item->order_user_id !== (int) $user_id) {
            return new WP_Error('not_owner', __('只有购买用户才能评价', 'qilingshop'));
        }

        if ((int) $item->order_status !== 3) {
            return new WP_Error('order_not_completed', __('订单未完成，无法评价', 'qilingshop'));
        }

        if ((int) $item->is_reviewed === 1) {
            return new WP_Error('already_reviewed', __('该商品已评价', 'qilingshop'));
        }

        $after_days = (int) get_option('qls_shop_review_after_days', 15);
        if ($after_days > 0 && !empty($item->completed_at)) {
            $completed_time = strtotime((string) $item->completed_at);
            $deadline = $completed_time + ($after_days * DAY_IN_SECONDS);
            if ($completed_time > 0 && time() > $deadline) {
                return new WP_Error('review_expired', sprintf(__('已超过%d天评价期限', 'qilingshop'), $after_days));
            }
        }

        return true;
    }

    /**
     * 获取商品评价统计
     * 
     * @param int $product_id 商品ID
     * @return array
     */
    public function get_stats($product_id) {
        global $wpdb;
        
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total,
                AVG(rating) as avg_rating,
                SUM(CASE WHEN rating >= 4 THEN 1 ELSE 0 END) as good_count,
                SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as medium_count,
                SUM(CASE WHEN rating <= 2 THEN 1 ELSE 0 END) as bad_count,
                SUM(CASE WHEN images IS NOT NULL AND images != '[]' THEN 1 ELSE 0 END) as image_count
             FROM {$this->table}
             WHERE product_id = %d AND status = %d",
            $product_id, self::STATUS_APPROVED
        ), ARRAY_A);
        
        $total = intval($stats['total']);
        $good_count = intval($stats['good_count']);
        
        return [
            'total'        => $total,
            'avg_rating'   => $total > 0 ? round(floatval($stats['avg_rating']), 1) : 0,
            'good_count'   => $good_count,
            'medium_count' => intval($stats['medium_count']),
            'bad_count'    => intval($stats['bad_count']),
            'image_count'  => intval($stats['image_count']),
            'good_rate'    => $total > 0 ? round(($good_count / $total) * 100) : 100,
        ];
    }

    /**
     * 同步商品评分缓存
     * 
     * @param int $product_id 商品ID
     */
    public function sync_product_stats($product_id) {
        global $wpdb;
        
        $stats = $this->get_stats($product_id);
        
        $wpdb->update(
            $this->table_products,
            [
                'review_count' => $stats['total'],
                'avg_rating'   => $stats['avg_rating'],
            ],
            ['id' => $product_id]
        );
    }

    /**
     * 点赞评价
     * 
     * @param int $review_id 评价ID
     * @param int $user_id   用户ID
     * @return bool
     */
    public function like($review_id, $user_id) {
        global $wpdb;

        $review_id = (int) $review_id;
        $user_id = (int) $user_id;
        if ($review_id <= 0 || $user_id <= 0) {
            return false;
        }

        $wpdb->query('START TRANSACTION');

        try {
            $inserted = $wpdb->insert($this->table_likes, [
                'review_id'   => $review_id,
                'user_id'     => $user_id,
                'created_at'  => current_time('mysql'),
            ]);

            if ($inserted === false) {
                $existing_like = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$this->table_likes} WHERE review_id = %d AND user_id = %d LIMIT 1",
                    $review_id,
                    $user_id
                ));
                if ($existing_like) {
                    $wpdb->query('ROLLBACK');
                    return false;
                }
                throw new Exception('Insert review like failed');
            }

            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table} SET like_count = like_count + 1 WHERE id = %d",
                $review_id
            ));

            if ($updated === false || (int) $updated !== 1) {
                throw new Exception('Update review like_count failed');
            }

            $wpdb->query('COMMIT');
            return true;
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return false;
        }
    }

    /**
     * 发放评价积分奖励
     * 
     * @param int  $user_id   用户ID
     * @param bool $has_image 是否带图
     * @param int  $review_id 评价ID
     */
    private function reward_points($user_id, $has_image = false, $review_id = 0) {
        $base_points = intval(get_option('qls_shop_review_points_reward', 10));
        $image_bonus = $has_image ? intval(get_option('qls_shop_review_image_bonus', 5)) : 0;
        $total_points = $base_points + $image_bonus;
        
        $user_id = (int) $user_id;
        $review_id = (int) $review_id;

        if ($user_id <= 0 || $review_id <= 0 || $total_points <= 0) {
            return;
        }

        if (!class_exists('QilingShop_Points')) {
            return;
        }

        $points = QilingShop_Points::instance();
        if ($points->has_points_log($user_id, 'review', $review_id)) {
            return;
        }

        $rewarded = $points->add_points($user_id, $total_points, 'review', __('商品评价奖励', 'qilingshop'), $review_id);
        if (!$rewarded) {
            if (function_exists('qilingshop_log')) {
                qilingshop_log('Review reward points failed', 'error', [
                    'user_id'   => $user_id,
                    'review_id' => $review_id,
                    'points'    => $total_points,
                ]);
            }

            if (!$this->queue_reward_retry($user_id, $total_points, $review_id)) {
                if (function_exists('qilingshop_log')) {
                    qilingshop_log('Review reward retry enqueue failed', 'error', [
                        'user_id'   => $user_id,
                        'review_id' => $review_id,
                        'points'    => $total_points,
                    ]);
                }
            }
        }
    }

    private function reward_growth($user_id, $has_image = false, $review_id = 0, $context = []) {
        $user_id = (int) $user_id;
        $review_id = (int) $review_id;
        if ($user_id <= 0 || $review_id <= 0 || !class_exists('QilingShop_Growth_Rules')) {
            return;
        }

        $context = is_array($context) ? $context : [];
        $context['has_image'] = (bool) $has_image;
        QilingShop_Growth_Rules::instance()->handle_review_submit($review_id, $user_id, $context);
    }

    private function queue_reward_retry($user_id, $points, $review_id) {
        $user_id = (int) $user_id;
        $points = (float) $points;
        $review_id = (int) $review_id;
        if ($user_id <= 0 || $points <= 0 || $review_id <= 0) {
            return false;
        }

        $entry = [
            'user_id'    => $user_id,
            'points'     => $points,
            'review_id'  => $review_id,
            'attempts'   => 0,
            'queued_at'  => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        $token = wp_generate_password(20, false, false);
        $lock_acquired = false;
        for ($attempt = 0; $attempt < self::REWARD_QUEUE_LOCK_RETRIES; $attempt++) {
            if ($this->acquire_reward_queue_lock($token)) {
                $lock_acquired = true;
                break;
            }

            if ($attempt + 1 < self::REWARD_QUEUE_LOCK_RETRIES) {
                usleep(self::REWARD_QUEUE_LOCK_BACKOFF_US);
            }
        }

        if (!$lock_acquired) {
            return $this->persist_reward_retry_shadow($review_id, $entry);
        }

        try {
            $queue = get_option(self::REWARD_RETRY_OPTION, []);
            if (!is_array($queue)) {
                $queue = [];
            }

            $key = (string) $review_id;
            $existing = isset($queue[$key]) && is_array($queue[$key]) ? $queue[$key] : [];
            $queue[$key] = [
                'user_id'    => $entry['user_id'],
                'points'     => $entry['points'],
                'review_id'  => $entry['review_id'],
                'attempts'   => (int) ($existing['attempts'] ?? 0),
                'queued_at'  => $existing['queued_at'] ?? $entry['queued_at'],
                'updated_at' => $entry['updated_at'],
            ];

            $persisted = $this->persist_reward_retry_queue($queue);
            if ($persisted) {
                $this->delete_reward_retry_shadow($key);
                return true;
            }

            return $this->persist_reward_retry_shadow($review_id, $queue[$key]);
        } finally {
            $this->release_reward_queue_lock($token);
        }
    }

    public function process_reward_retry_queue($force = false) {
        $queue = get_option(self::REWARD_RETRY_OPTION, []);
        if (!is_array($queue)) {
            $queue = [];
        }

        $token = wp_generate_password(20, false, false);
        if (!$this->acquire_reward_retry_lock($token)) {
            return 0;
        }

        $processed = 0;
        $limit = $force ? 50 : 20;

        try {
            $queue = get_option(self::REWARD_RETRY_OPTION, []);
            if (!is_array($queue)) {
                $queue = [];
            }
            $queue = $this->merge_reward_retry_shadows($queue);
            if (empty($queue) || !class_exists('QilingShop_Points')) {
                return 0;
            }

            $points = QilingShop_Points::instance();
            foreach ($queue as $key => $entry) {
                if (!is_array($entry)) {
                    unset($queue[$key]);
                    $this->delete_reward_retry_shadow($key);
                    continue;
                }

                $review_id = (int) ($entry['review_id'] ?? 0);
                $user_id = (int) ($entry['user_id'] ?? 0);
                $amount = (float) ($entry['points'] ?? 0);
                if ($review_id <= 0 || $user_id <= 0 || $amount <= 0) {
                    unset($queue[$key]);
                    $this->delete_reward_retry_shadow($key);
                    continue;
                }

                if ($points->has_points_log($user_id, 'review', $review_id)) {
                    unset($queue[$key]);
                    $this->delete_reward_retry_shadow($key);
                } else {
                    $rewarded = $points->add_points($user_id, $amount, 'review', __('商品评价奖励', 'qilingshop'), $review_id);
                    if ($rewarded) {
                        unset($queue[$key]);
                        $this->delete_reward_retry_shadow($key);
                    } else {
                        $entry['attempts'] = (int) ($entry['attempts'] ?? 0) + 1;
                        $entry['updated_at'] = current_time('mysql');
                        $queue[$key] = $entry;
                        $this->persist_reward_retry_shadow($review_id, $entry);
                    }
                }

                $processed++;
                if ($processed >= $limit) {
                    break;
                }
            }

            $this->persist_reward_retry_queue($queue);
        } finally {
            $this->release_reward_retry_lock($token);
        }

        return $processed;
    }

    private function persist_reward_retry_queue(array $queue) {
        if (get_option(self::REWARD_RETRY_OPTION, null) === null) {
            return add_option(self::REWARD_RETRY_OPTION, $queue, '', 'no');
        }

        $updated = update_option(self::REWARD_RETRY_OPTION, $queue, false);
        if ($updated) {
            return true;
        }

        return get_option(self::REWARD_RETRY_OPTION, null) === $queue;
    }

    private function get_reward_shadow_option_name($review_id) {
        return self::REWARD_SHADOW_PREFIX . absint($review_id);
    }

    private function persist_reward_retry_shadow($review_id, array $entry) {
        $option_name = $this->get_reward_shadow_option_name($review_id);
        if (get_option($option_name, null) === null) {
            return add_option($option_name, $entry, '', 'no');
        }

        return update_option($option_name, $entry, false);
    }

    private function delete_reward_retry_shadow($review_id) {
        delete_option($this->get_reward_shadow_option_name($review_id));
    }

    private function merge_reward_retry_shadows(array $queue) {
        global $wpdb;

        $like = $wpdb->esc_like(self::REWARD_SHADOW_PREFIX) . '%';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                $like
            )
        );

        if (empty($rows)) {
            return $queue;
        }

        foreach ($rows as $row) {
            $option_name = isset($row->option_name) ? (string) $row->option_name : '';
            $key = substr($option_name, strlen(self::REWARD_SHADOW_PREFIX));
            if ($key === '') {
                continue;
            }

            $entry = maybe_unserialize($row->option_value);
            if (!is_array($entry)) {
                $this->delete_reward_retry_shadow($key);
                continue;
            }

            if (!isset($queue[$key]) || !is_array($queue[$key])) {
                $queue[$key] = $entry;
            }
        }

        return $queue;
    }

    private function acquire_reward_retry_lock($token) {
        $now = current_time('timestamp');
        $payload = [
            'token'   => (string) $token,
            'expires' => $now + self::REWARD_RETRY_LOCK_TTL,
        ];

        if (add_option(self::REWARD_RETRY_LOCK_OPTION, $payload, '', 'no')) {
            return true;
        }

        $existing = get_option(self::REWARD_RETRY_LOCK_OPTION, []);
        $expires = isset($existing['expires']) ? absint($existing['expires']) : 0;
        if ($expires > $now) {
            return false;
        }

        delete_option(self::REWARD_RETRY_LOCK_OPTION);
        return add_option(self::REWARD_RETRY_LOCK_OPTION, $payload, '', 'no');
    }

    private function release_reward_retry_lock($token) {
        $current = get_option(self::REWARD_RETRY_LOCK_OPTION, []);
        $current_token = isset($current['token']) ? (string) $current['token'] : '';
        if ($current_token !== '' && hash_equals($current_token, (string) $token)) {
            delete_option(self::REWARD_RETRY_LOCK_OPTION);
        }
    }

    private function acquire_reward_queue_lock($token) {
        $now = current_time('timestamp');
        $payload = [
            'token'   => (string) $token,
            'expires' => $now + self::REWARD_QUEUE_LOCK_TTL,
        ];

        if (add_option(self::REWARD_QUEUE_LOCK_OPTION, $payload, '', 'no')) {
            return true;
        }

        $existing = get_option(self::REWARD_QUEUE_LOCK_OPTION, []);
        $expires = isset($existing['expires']) ? absint($existing['expires']) : 0;
        if ($expires > $now) {
            return false;
        }

        delete_option(self::REWARD_QUEUE_LOCK_OPTION);
        return add_option(self::REWARD_QUEUE_LOCK_OPTION, $payload, '', 'no');
    }

    private function release_reward_queue_lock($token) {
        $current = get_option(self::REWARD_QUEUE_LOCK_OPTION, []);
        $current_token = isset($current['token']) ? (string) $current['token'] : '';
        if ($current_token !== '' && hash_equals($current_token, (string) $token)) {
            delete_option(self::REWARD_QUEUE_LOCK_OPTION);
        }
    }

    /**
     * 匿名处理用户名
     * 
     * @param string $name 原始用户名
     * @return string
     */
    private function mask_name($name) {
        if (empty($name)) {
            return __('匿名用户', 'qilingshop');
        }
        
        $len = mb_strlen($name);
        if ($len <= 2) {
            return mb_substr($name, 0, 1) . '*';
        }
        
        return mb_substr($name, 0, 1) . str_repeat('*', $len - 2) . mb_substr($name, -1);
    }

    /**
     * 后台获取评价列表（管理用）
     * 
     * @param array $args 查询参数
     * @return array
     */
    public function get_admin_list($args = []) {
        global $wpdb;
        
        $defaults = [
            'status'     => null,
            'product_id' => null,
            'user_id'    => null,
            'rating'     => null,
            'search'     => '',
            'page'       => 1,
            'per_page'   => 20,
        ];
        $args = wp_parse_args($args, $defaults);
        
        $where = ["1=1"];
        $params = [];
        
        if ($args['status'] !== null && $args['status'] !== '') {
            $where[] = "r.status = %d";
            $params[] = intval($args['status']);
        }
        
        if ($args['product_id']) {
            $where[] = "r.product_id = %d";
            $params[] = intval($args['product_id']);
        }
        
        if ($args['user_id']) {
            $where[] = "r.user_id = %d";
            $params[] = intval($args['user_id']);
        }
        
        if ($args['rating']) {
            $where[] = "r.rating = %d";
            $params[] = intval($args['rating']);
        }
        
        if ($args['search']) {
            $where[] = "(r.content LIKE %s OR p.title LIKE %s)";
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        
        $where_sql = implode(' AND ', $where);
        $offset = ($args['page'] - 1) * $args['per_page'];
        
        // 构建基础SQL
        $base_sql = "FROM {$this->table} r
                    LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
                    LEFT JOIN {$this->table_products} p ON r.product_id = p.id
                    WHERE {$where_sql}";
        
        // 获取总数 - 需要特殊处理，因为可能没有参数
        if (!empty($params)) {
            $total = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) {$base_sql}",
                ...$params
            ));
        } else {
            $total = $wpdb->get_var("SELECT COUNT(*) {$base_sql}");
        }
        
        // 获取列表
        $select_sql = "SELECT r.*, u.display_name as user_name, p.title as product_title {$base_sql}
                      ORDER BY r.created_at DESC
                      LIMIT %d OFFSET %d";
        
        $query_params = array_merge($params, [$args['per_page'], $offset]);
        $reviews = $wpdb->get_results($wpdb->prepare($select_sql, ...$query_params));
        
        foreach ($reviews as &$review) {
            if ($review->images) {
                $review->images = json_decode($review->images, true);
            }
        }
        
        return [
            'items'      => $reviews,
            'total'      => intval($total),
            'page'       => $args['page'],
            'per_page'   => $args['per_page'],
            'total_pages'=> ceil($total / $args['per_page']),
        ];
    }
}

/**
 * 获取评价类实例
 * 
 * @return QLS_Review
 */
function qls_review() {
    return QLS_Review::instance();
}
