<?php
/**
 * 团购定时任务处理类
 * 
 * 处理过期团购检测、自动退款、REST API端点等
 *
 * @package QilingShop
 * @since   2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 团购定时任务类
 */
class QLS_Group_Cron {

    /**
     * 单例实例
     * @var QLS_Group_Cron
     */
    private static $instance = null;

    /**
     * 获取单例实例
     * 
     * @return QLS_Group_Cron
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
        $this->init_hooks();
    }

    private function acquire_named_lock($lock_name, $timeout = 5) {
        global $wpdb;

        $lock_name = sanitize_key((string) $lock_name);
        if ($lock_name === '') {
            return false;
        }

        $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, (int) $timeout));
        return (int) $result === 1;
    }

    private function release_named_lock($lock_name) {
        global $wpdb;

        $lock_name = sanitize_key((string) $lock_name);
        if ($lock_name === '') {
            return;
        }

        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
    }

    private function build_group_refund_lock_name($order_id) {
        // 与商城订单侧保持同一把订单锁，避免两个退款入口并发穿透。
        return 'qlsord_' . md5((string) absint($order_id));
    }

    /**
     * 初始化钩子
     */
    private function init_hooks() {
        // 注册REST API端点
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        
        // 监听拼团失败事件，执行退款
        add_action('qls_group_failed', [$this, 'handle_group_failed']);
        
        // 监听拼团成功事件，发送通知
        add_action('qls_group_success', [$this, 'handle_group_success']);
        
        // 统一由外部任务中心触发；该 hook 仅用于兼容旧触发方式。
        add_action('qls_shop_check_expired_groups', [$this, 'check_expired_groups']);
    }

    /**
     * 注册REST API路由
     */
    public function register_rest_routes() {
        register_rest_route('qilingshop/v1', '/group/cron/check-expire', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_check_expire'],
            'permission_callback' => [$this, 'verify_cron_key'],
        ]);
    }

    /**
     * 验证Cron Key
     * 
     * @param WP_REST_Request $request
     * @return bool
     */
    public function verify_cron_key($request) {
        $key = (string) $request->get_param('key');
        $stored_key = (string) get_option('qls_group_cron_key', '');
        
        if (empty($stored_key)) {
            return false;
        }
        
        return hash_equals($stored_key, $key);
    }

    /**
     * REST API: 检查过期团购
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function rest_check_expire($request) {
        $result = $this->check_expired_groups();
        
        return new WP_REST_Response([
            'success'        => true,
            'message'        => __('过期检查完成', 'qilingshop'),
            'processed'      => $result['processed'],
            'refunded'       => $result['refunded'],
            'refund_amount'  => $result['refund_amount'],
            'errors'         => $result['errors'],
            'timestamp'      => current_time('mysql'),
        ], 200);
    }

    /**
     * 检查并处理过期团购
     * 
     * @return array 处理结果
     */
    public function check_expired_groups() {
        global $wpdb;
        
        $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'groups';
        $rules_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'group_rules';
        $now = current_time('mysql');
        
        $result = [
            'processed'     => 0,
            'refunded'      => 0,
            'refund_amount' => 0,
            'errors'        => [],
        ];
        
        // 查找所有过期且仍在拼团中的团
        $expired_groups = $wpdb->get_results($wpdb->prepare(
            "SELECT g.* FROM {$table} g
             INNER JOIN {$rules_table} r ON g.rule_id = r.id
             WHERE g.status = %d AND DATE_ADD(g.created_at, INTERVAL r.time_limit HOUR) < %s
             LIMIT 100",
            QLS_Group::STATUS_GROUPING,
            $now
        ));
        
        if (empty($expired_groups)) {
            return $result;
        }
        
        foreach ($expired_groups as $group) {
            $result['processed']++;
            
            try {
                // 标记团为失败
                qls_group()->mark_failed($group->id);
                
                // 处理退款（由 handle_group_failed 钩子自动触发）
                
            } catch (Exception $e) {
                $result['errors'][] = [
                    'group_id' => $group->id,
                    'message'  => $e->getMessage(),
                ];
            }
        }
        
        return $result;
    }

    /**
     * 处理拼团失败事件
     * 
     * @param int $group_id 团ID
     */
    public function handle_group_failed($group_id) {
        global $wpdb;
        
        $members_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'group_members';
        $orders_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'orders';
        $points_manager = class_exists('QilingShop_Points') ? QilingShop_Points::instance() : null;
        if (!$points_manager) {
            return;
        }
        
        // 获取团信息
        $group = qls_group()->get_group($group_id);
        if (!$group) {
            return;
        }
        
        // 获取所有成员
        $members = $wpdb->get_results($wpdb->prepare(
            "SELECT m.*, o.final_amount, o.user_id as order_user_id, o.status as order_status
             FROM {$members_table} m
             LEFT JOIN {$orders_table} o ON m.order_id = o.id
             WHERE m.group_id = %d AND o.status != 6",
            $group_id
        ));
        
        foreach ($members as $member) {
            if (empty($member->final_amount) || $member->final_amount <= 0) {
                continue;
            }

            $refund_user_id = !empty($member->order_user_id) ? $member->order_user_id : $member->user_id;
            $lock_name = $this->build_group_refund_lock_name((int) $member->order_id);
            if (!$this->acquire_named_lock($lock_name, 5)) {
                continue;
            }

            try {
                if ($points_manager->has_points_log((int) $refund_user_id, 'qls_group_refund', (int) $member->order_id)) {
                    continue;
                }

                $refund_success = $points_manager->add_withdrawable_balance(
                    $refund_user_id,
                    $member->final_amount,
                    'qls_group_refund',
                    sprintf(__('拼团失败自动退款（团ID: %d）', 'qilingshop'), $group_id),
                    $member->order_id
                );
                
                if ($refund_success) {
                    $status = class_exists('QLS_Shop_Order') ? QLS_Shop_Order::STATUS_REFUNDED : 6;
                    if (function_exists('qls_shop_order') && class_exists('QLS_Shop_Order')) {
                        qls_shop_order()->update_status($member->order_id, $status);
                    }
                    $wpdb->update(
                        $orders_table,
                        [
                            'status'       => $status,
                            'cancelled_at' => current_time('mysql'),
                        ],
                        ['id' => $member->order_id]
                    );
                    
                    // 恢复库存
                    $this->restore_order_stock($member->order_id);

                    // 恢复优惠券
                    if (function_exists('qls_shop_order') && class_exists('QLS_Coupon')) {
                        $order_obj = qls_shop_order()->get($member->order_id, true);
                        if ($order_obj && !empty($order_obj->seller_remark)) {
                            $remark_data = json_decode($order_obj->seller_remark, true);
                            $coupon_claim_id = $remark_data['coupon_claim_id'] ?? 0;
                            if ($coupon_claim_id > 0) {
                                QLS_Coupon::restore_coupon($coupon_claim_id);
                            }
                        }
                    }

                    if (function_exists('qls_card_inventory')) {
                        qls_card_inventory()->revoke_by_order($member->order_id);
                    }
                    
                    // 发送通知
                    $this->send_fail_notification($refund_user_id, $group, $member->final_amount);
                }
            } finally {
                $this->release_named_lock($lock_name);
            }
        }
    }

    /**
     * 处理拼团成功事件
     * 
     * @param int $group_id 团ID
     */
    public function handle_group_success($group_id) {
        $group = qls_group()->get_group($group_id, true);
        if (!$group) {
            return;
        }
        
        // 发送成功通知给所有成员
        foreach ($group->members as $member) {
            $this->send_success_notification($member->user_id, $group);
        }
    }

    /**
     * 恢复订单库存
     * 
     * @param int $order_id 订单ID
     */
    private function restore_order_stock($order_id) {
        // 调用现有订单类的库存恢复方法
        if (class_exists('QLS_Shop_Order')) {
            $order = QLS_Shop_Order::instance()->get($order_id, true);
            if ($order) {
                QLS_Shop_Order::instance()->restore_stock($order);
            }
        }
    }

    /**
     * 发送拼团失败通知
     * 
     * @param int    $user_id       用户ID
     * @param object $group         团信息
     * @param float  $refund_amount 退款金额
     */
    private function send_fail_notification($user_id, $group, $refund_amount) {
        $message = sprintf(
            __('您参与的「%s」拼团未能在规定时间内成团，已自动取消。退款金额 ¥%.2f 已退回您的可提现余额。', 'qilingshop'),
            $group->product_title ?? __('商品', 'qilingshop'),
            $refund_amount
        );
        
        // 触发通知钩子（可被主题或其他插件接收）
        do_action('qilingshop_send_notification', [
            'user_id' => $user_id,
            'type'    => 'qls_group_failed',
            'title'   => __('拼团失败通知', 'qilingshop'),
            'content' => $message,
        ]);
    }

    /**
     * 发送拼团成功通知
     * 
     * @param int    $user_id 用户ID
     * @param object $group   团信息
     */
    private function send_success_notification($user_id, $group) {
        $message = sprintf(
            __('恭喜！您参与的「%s」拼团已成功，商家即将安排发货。', 'qilingshop'),
            $group->product_title ?? __('商品', 'qilingshop')
        );
        
        // 触发通知钩子
        do_action('qilingshop_send_notification', [
            'user_id' => $user_id,
            'type'    => 'qls_group_success',
            'title'   => __('拼团成功通知', 'qilingshop'),
            'content' => $message,
        ]);
    }
}

/**
 * 获取团购定时任务类实例
 * 
 * @return QLS_Group_Cron
 */
function qls_group_cron() {
    return QLS_Group_Cron::instance();
}
