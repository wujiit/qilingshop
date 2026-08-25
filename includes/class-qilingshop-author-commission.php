<?php
/**
 * 作者提成管理类
 * 处理作者销售资源的提成计算、记录和查询
 *
 * @package QilingShop
 * @since   1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Author_Commission {
    const BALANCE_PENDING = 0;
    const BALANCE_APPLIED = 1;
    const BALANCE_PROCESSING = 2;

    /**
     * 单例实例
     */
    private static $instance = null;

    /**
     * 数据库实例
     */
    private $db;

    /**
     * 提成状态常量
     */
    const STATUS_SETTLED = 1;    // 已结算（已计入余额）
    const STATUS_WITHDRAWN = 2;  // 已提现
    const STATUS_CANCELLED = 3;  // 已取消（如订单退款）

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->db = QilingShop_Database::instance();
        
        // 挂载订单完成钩子
        add_action('qilingshop_order_completed', [$this, 'process_order_commission'], 10, 1);
    }

    /**
     * 检查作者提成功能是否开启
     */
    public function is_enabled() {
        return get_option('qilingshop_author_commission_enabled', false);
    }

    /**
     * 获取提成比例
     */
    public function get_commission_rate() {
        return floatval(get_option('qilingshop_author_commission_rate', 80));
    }

    /**
     * 处理订单的作者提成
     * 
     * @param int $order_id 订单ID
     */
    public function process_order_commission($order_id) {
        // 检查功能是否开启
        if (!$this->is_enabled()) {
            return;
        }

        // 获取订单
        $order = $this->db->get_by_id('orders', $order_id);
        if (!$order) {
            return;
        }

        // 仅处理资源类型订单
        if ($order->order_type !== 'resource') {
            return;
        }

        // 获取文章作者
        $author_id = $order->author_id ?: get_post_field('post_author', $order->post_id);
        if (!$author_id) {
            return;
        }

        // 自己买自己的不计提成
        if ($author_id == $order->user_id) {
            return;
        }

        // 计算提成
        $rate = $this->get_commission_rate();
        $order_amount = $order->price_rmb ?: qilingshop_points_to_rmb($order->price_points);
        
        if ($order_amount <= 0) {
            return; // 免费订单不计提成
        }

        $commission = round($order_amount * ($rate / 100), 2);

        if ($commission <= 0) {
            return;
        }

        global $wpdb;
        $this->db->begin_transaction();
        try {
            $this->db->update('orders', [
                'author_id'         => $author_id,
                'author_commission' => $commission
            ], ['id' => $order_id]);

            $record = $this->get_commission_record_for_update($order_id);
            if (!$record) {
                $inserted = $wpdb->insert($wpdb->qilingshop_author_commissions, [
                    'author_id'          => $author_id,
                    'order_id'           => $order_id,
                    'order_no'           => $order->order_no,
                    'post_id'            => $order->post_id,
                    'post_title'         => $order->post_title ?: get_the_title($order->post_id),
                    'buyer_id'           => $order->user_id,
                    'order_amount'       => $order_amount,
                    'commission_rate'    => $rate,
                    'commission_amount'  => $commission,
                    'status'             => self::STATUS_SETTLED,
                    'balance_applied'    => self::BALANCE_PENDING,
                    'balance_applied_at' => null,
                    'created_at'         => current_time('mysql'),
                ]);
                if ($inserted === false) {
                    if (stripos((string) $wpdb->last_error, 'Duplicate entry') === false) {
                        throw new Exception('Failed to insert author commission');
                    }
                }

                $record = $this->get_commission_record_for_update($order_id);
                if (!$record) {
                    throw new Exception('Failed to load author commission');
                }
            }

            if ((int) ($record->balance_applied ?? self::BALANCE_PENDING) === self::BALANCE_APPLIED) {
                $this->db->commit();
                return;
            }

            $points = QilingShop_Points::instance();
            if ($points->has_points_log($author_id, 'author_commission', $order_id)) {
                $this->mark_balance_applied($order_id);
                $this->db->commit();
                return;
            }

            $balance_state = (int) ($record->balance_applied ?? self::BALANCE_PENDING);
            if ($balance_state === self::BALANCE_PROCESSING && !empty($record->balance_applied_at)) {
                $processing_since = strtotime((string) $record->balance_applied_at);
                if ($processing_since && (time() - $processing_since) < 300) {
                    $this->db->commit();
                    return;
                }
            }

            $claimed = $wpdb->update(
                $wpdb->qilingshop_author_commissions,
                [
                    'balance_applied'    => self::BALANCE_PROCESSING,
                    'balance_applied_at' => current_time('mysql'),
                ],
                [
                    'id'              => (int) $record->id,
                    'balance_applied' => $balance_state,
                ],
                ['%d', '%s'],
                ['%d', '%d']
            );

            if ($claimed === false) {
                throw new Exception('Failed to claim author commission processing');
            }

            if ((int) $claimed === 0) {
                $this->db->commit();
                return;
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            qilingshop_log('Author commission failed: ' . $e->getMessage(), 'error', [
                'order_id'  => $order_id,
                'author_id' => $author_id,
            ]);
            return;
        }

        $credited = QilingShop_Points::instance()->add_withdrawable_balance(
            $author_id,
            $commission,
            'author_commission',
            sprintf(__('资源销售提成：%s', 'qilingshop'), $order->post_title ?: get_the_title($order->post_id)),
            $order_id
        );

        if (!$credited) {
            $this->reset_balance_processing($order_id);
            return;
        }

        if (!$this->finalize_balance_applied($order_id)) {
            return;
        }

        /**
         * 作者提成处理完成钩子
         * 
         * @param int    $author_id  作者ID
         * @param float  $commission 提成金额
         * @param object $order      订单对象
         */
        do_action('qilingshop_author_commission_processed', $author_id, $commission, $order);
    }

    /**
     * 事务内锁定作者分成记录
     *
     * @param int $order_id
     * @return object|null
     */
    private function get_commission_record_for_update($order_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->qilingshop_author_commissions}
             WHERE order_id = %d
             LIMIT 1
             FOR UPDATE",
            (int) $order_id
        ));
    }

    /**
     * 标记作者分成余额已入账
     *
     * @param int $order_id
     * @return void
     */
    private function mark_balance_applied($order_id) {
        global $wpdb;

        $wpdb->update(
            $wpdb->qilingshop_author_commissions,
            [
                'balance_applied'    => self::BALANCE_APPLIED,
                'balance_applied_at' => current_time('mysql'),
            ],
            ['order_id' => (int) $order_id],
            ['%d', '%s'],
            ['%d']
        );
    }

    /**
     * 将处理中状态回退为待处理，供后续重试
     *
     * @param int $order_id
     * @return void
     */
    private function reset_balance_processing($order_id) {
        global $wpdb;

        $this->db->begin_transaction();
        try {
            $record = $this->get_commission_record_for_update($order_id);
            if ($record && (int) ($record->balance_applied ?? self::BALANCE_PENDING) === self::BALANCE_PROCESSING) {
                $wpdb->update(
                    $wpdb->qilingshop_author_commissions,
                    [
                        'balance_applied'    => self::BALANCE_PENDING,
                        'balance_applied_at' => null,
                    ],
                    ['id' => (int) $record->id],
                    ['%d', '%s'],
                    ['%d']
                );
            }
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
        }
    }

    /**
     * 完成作者分成入账状态
     *
     * @param int $order_id
     * @return bool
     */
    private function finalize_balance_applied($order_id) {
        $this->db->begin_transaction();
        try {
            $record = $this->get_commission_record_for_update($order_id);
            if (!$record) {
                throw new Exception('Author commission record not found');
            }

            if ((int) ($record->balance_applied ?? self::BALANCE_PENDING) !== self::BALANCE_APPLIED) {
                $this->mark_balance_applied($order_id);
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            qilingshop_log('Finalize author commission failed: ' . $e->getMessage(), 'error', [
                'order_id' => $order_id,
            ]);
            return false;
        }
    }

    /**
     * 获取作者的提成记录
     * 
     * @param int   $author_id 作者ID
     * @param array $args      查询参数
     */
    public function get_author_commissions($author_id, $args = []) {
        global $wpdb;

        $defaults = [
            'limit'   => 20,
            'offset'  => 0,
            'orderby' => 'created_at',
            'order'   => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);
        $orderby = $this->normalize_orderby($args['orderby']);
        $order = $this->normalize_order($args['order']);
        $limit = $this->normalize_limit($args['limit']);
        $offset = $this->normalize_offset($args['offset']);

        $sql = $wpdb->prepare(
            "SELECT * FROM {$wpdb->qilingshop_author_commissions} 
             WHERE author_id = %d 
             ORDER BY {$orderby} {$order} 
             LIMIT %d OFFSET %d",
            $author_id,
            $limit,
            $offset
        );

        return $wpdb->get_results($sql);
    }

    /**
     * 获取作者提成统计
     * 
     * @param int $author_id 作者ID
     */
    public function get_author_statistics($author_id) {
        global $wpdb;

        // 总销售额和总提成
        $totals = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                SUM(order_amount) as total_sales,
                SUM(commission_amount) as total_commission,
                COUNT(*) as total_orders
             FROM {$wpdb->qilingshop_author_commissions} 
             WHERE author_id = %d AND status = %d",
            $author_id,
            self::STATUS_SETTLED
        ));

        // 本月数据
        $month_start = date('Y-m-01 00:00:00');
        $monthly = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                SUM(order_amount) as month_sales,
                SUM(commission_amount) as month_commission,
                COUNT(*) as month_orders
             FROM {$wpdb->qilingshop_author_commissions} 
             WHERE author_id = %d AND status = %d AND created_at >= %s",
            $author_id,
            self::STATUS_SETTLED,
            $month_start
        ));

        return [
            'total_sales'      => floatval($totals->total_sales ?: 0),
            'total_commission' => floatval($totals->total_commission ?: 0),
            'total_orders'     => intval($totals->total_orders ?: 0),
            'month_sales'      => floatval($monthly->month_sales ?: 0),
            'month_commission' => floatval($monthly->month_commission ?: 0),
            'month_orders'     => intval($monthly->month_orders ?: 0),
        ];
    }

    /**
     * 获取作者提成记录数量
     * 
     * @param int $author_id 作者ID
     */
    public function get_author_commissions_count($author_id) {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->qilingshop_author_commissions} WHERE author_id = %d",
            $author_id
        ));
    }

    /**
     * 获取全站提成统计（后台用）
     */
    public function get_global_statistics() {
        global $wpdb;

        // 总数据
        $totals = $wpdb->get_row(
            "SELECT 
                SUM(order_amount) as total_sales,
                SUM(commission_amount) as total_commission,
                COUNT(*) as total_orders,
                COUNT(DISTINCT author_id) as total_authors
             FROM {$wpdb->qilingshop_author_commissions} 
             WHERE status = " . self::STATUS_SETTLED
        );

        // 本月数据
        $month_start = date('Y-m-01 00:00:00');
        $monthly = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                SUM(order_amount) as month_sales,
                SUM(commission_amount) as month_commission,
                COUNT(*) as month_orders
             FROM {$wpdb->qilingshop_author_commissions} 
             WHERE status = %d AND created_at >= %s",
            self::STATUS_SETTLED,
            $month_start
        ));

        return [
            'total_sales'      => floatval($totals->total_sales ?: 0),
            'total_commission' => floatval($totals->total_commission ?: 0),
            'total_orders'     => intval($totals->total_orders ?: 0),
            'total_authors'    => intval($totals->total_authors ?: 0),
            'month_sales'      => floatval($monthly->month_sales ?: 0),
            'month_commission' => floatval($monthly->month_commission ?: 0),
            'month_orders'     => intval($monthly->month_orders ?: 0),
        ];
    }

    /**
     * 获取全站提成记录（后台用）
     * 
     * @param array $args 查询参数
     */
    public function get_all_commissions($args = []) {
        global $wpdb;

        $defaults = [
            'limit'     => 20,
            'offset'    => 0,
            'orderby'   => 'created_at',
            'order'     => 'DESC',
            'author_id' => 0,
            'post_id'   => 0,
        ];

        $args = wp_parse_args($args, $defaults);
        $orderby = $this->normalize_orderby($args['orderby']);
        $order = $this->normalize_order($args['order']);
        $limit = $this->normalize_limit($args['limit']);
        $offset = $this->normalize_offset($args['offset']);

        $where = "WHERE 1=1";

        if ($args['author_id']) {
            $where .= $wpdb->prepare(" AND author_id = %d", $args['author_id']);
        }

        if ($args['post_id']) {
            $where .= $wpdb->prepare(" AND post_id = %d", $args['post_id']);
        }

        $sql = "SELECT * FROM {$wpdb->qilingshop_author_commissions} 
                {$where} 
                ORDER BY {$orderby} {$order}";

        $sql = $wpdb->prepare($sql . " LIMIT %d OFFSET %d", $limit, $offset);

        return $wpdb->get_results($sql);
    }

    /**
     * 获取全站提成记录数量（后台用）
     * 
     * @param array $args 查询参数
     */
    public function get_all_commissions_count($args = []) {
        global $wpdb;

        $where = "WHERE 1=1";

        if (!empty($args['author_id'])) {
            $where .= $wpdb->prepare(" AND author_id = %d", $args['author_id']);
        }

        if (!empty($args['post_id'])) {
            $where .= $wpdb->prepare(" AND post_id = %d", $args['post_id']);
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->qilingshop_author_commissions} {$where}");
    }

    /**
     * 获取作者排行榜
     * 
     * @param int $limit 数量
     */
    public function get_top_authors($limit = 10) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT 
                author_id,
                SUM(order_amount) as total_sales,
                SUM(commission_amount) as total_commission,
                COUNT(*) as total_orders
             FROM {$wpdb->qilingshop_author_commissions} 
             WHERE status = %d
             GROUP BY author_id 
             ORDER BY total_commission DESC 
             LIMIT %d",
            self::STATUS_SETTLED,
            $limit
        ));
    }

    /**
     * 白名单化排序字段。
     *
     * @param string $orderby 排序字段
     * @return string
     */
    private function normalize_orderby($orderby) {
        $allowed = [
            'id',
            'author_id',
            'order_id',
            'post_id',
            'order_amount',
            'commission_rate',
            'commission_amount',
            'status',
            'created_at',
            'settled_at',
        ];

        $orderby = sanitize_key((string) $orderby);
        return in_array($orderby, $allowed, true) ? $orderby : 'created_at';
    }

    /**
     * 标准化排序方向。
     *
     * @param string $order 排序方向
     * @return string
     */
    private function normalize_order($order) {
        return strtoupper((string) $order) === 'ASC' ? 'ASC' : 'DESC';
    }

    /**
     * 限制分页大小。
     *
     * @param mixed $limit 限制数量
     * @return int
     */
    private function normalize_limit($limit) {
        $limit = (int) $limit;
        if ($limit <= 0) {
            return 20;
        }
        return min($limit, 200);
    }

    /**
     * 标准化偏移量。
     *
     * @param mixed $offset 偏移
     * @return int
     */
    private function normalize_offset($offset) {
        return max(0, (int) $offset);
    }
}

/**
 * 获取作者提成实例
 */
function qilingshop_author_commission() {
    return QilingShop_Author_Commission::instance();
}
