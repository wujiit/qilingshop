<?php
/**
 * 推广联盟管理类
 *
 * @package QilingShop
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Affiliate {
    const INVITE_TIER_PENDING = 0;
    const INVITE_TIER_APPLIED = 1;
    const INVITE_TIER_PROCESSING = 2;
    const INVITE_BONUS_PENDING = 0;
    const INVITE_BONUS_APPLIED = 1;
    const INVITE_BONUS_PROCESSING = 2;
    const COMMISSION_RETRY_OPTION = 'qilingshop_affiliate_retry_queue';
    const COMMISSION_RETRY_LOCK_OPTION = 'qilingshop_affiliate_retry_queue_lock';
    const COMMISSION_RETRY_LOCK_TTL = 600;
    const COMMISSION_QUEUE_LOCK_OPTION = 'qilingshop_affiliate_retry_queue_write_lock';
    const COMMISSION_QUEUE_LOCK_TTL = 30;
    const COMMISSION_QUEUE_LOCK_RETRIES = 3;
    const COMMISSION_QUEUE_LOCK_BACKOFF_US = 120000;
    const COMMISSION_SHADOW_PREFIX = 'qilingshop_affiliate_retry_entry_';
    const INVITE_BONUS_TIMEOUT = 300;

    private static $instance = null;
    private $db;

    private function get_invite_stats_cache_key($user_id) {
        return 'qilingshop_invite_stats_' . (int) $user_id;
    }

    private function build_invite_stats($user_id) {
        $user_id = (int) $user_id;
        $total = $this->db->count('invites', ['inviter_id' => $user_id, 'level' => 1]);
        $valid = $this->db->count('invites', ['inviter_id' => $user_id, 'level' => 1, 'is_valid' => 1]);
        $earnings = $this->db->sum('affiliate', 'amount', ['user_id' => $user_id]);
        $withdrawn = $this->db->sum('withdrawals', 'actual_amount', ['user_id' => $user_id, 'status' => 1]);
        $user_info = QilingShop_Points::instance()->get_user_info($user_id);
        $withdrawable = $user_info ? (float) $user_info->withdrawable_balance : 0;

        return [
            'total_invites'   => $total,
            'valid_invites'   => $valid,
            'total_earnings'  => $earnings,
            'total_commission' => $earnings,
            'pending_commission' => $withdrawable,
            'withdrawn' => $withdrawn,
        ];
    }

    public function clear_invite_stats_cache($user_id) {
        wp_cache_delete($this->get_invite_stats_cache_key($user_id), 'qilingshop');
    }

    public function refresh_invite_stats_cache($user_id) {
        $stats = $this->build_invite_stats($user_id);
        wp_cache_set($this->get_invite_stats_cache_key($user_id), $stats, 'qilingshop', 600);
        return $stats;
    }

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->db = QilingShop_Database::instance();
        add_action('qilingshop_order_paid', [$this, 'handle_invitee_paid_resource'], 10, 2);
        add_action('qls_shop_order_paid', [$this, 'handle_invitee_paid_shop'], 10, 2);
        add_action('qilingshop_recharge_completed', [$this, 'handle_invitee_paid_recharge'], 10, 3);
        add_action('qilingshop_daily_task_check', [$this, 'process_pending_invite_bonuses'], 15, 1);
        add_action('qilingshop_daily_task_check', [$this, 'process_retry_queue'], 20, 1);
    }

    /**
     * 处理邀请注册
     */
    public function handle_invite_registration($inviter_id, $invitee_id) {
        // 检查是否已记录
        $existing = $this->db->get_row('invites', ['invitee_id' => $invitee_id, 'level' => 1]);
        if ($existing) {
            if ((int) $existing->inviter_id !== (int) $inviter_id) {
                return false;
            }

            if ((int) ($existing->bonus_paid ?? 0) !== 1 && !$this->pay_invite_bonus($inviter_id, $invitee_id)) {
                if (function_exists('qilingshop_log')) {
                    qilingshop_log('Invite bonus still pending for existing relation', 'error', [
                        'inviter_id' => (int) $inviter_id,
                        'invitee_id' => (int) $invitee_id,
                    ]);
                }
            }

            $this->handle_invite_tier_bonus($inviter_id);
            $this->refresh_invite_stats_cache($inviter_id);
            return true;
        }

        $ip = qilingshop_security()->get_client_ip();
        $risk_tokens = [];

        // 风控模式：可配置阈值；关闭时回退旧版“同IP仅一次”限制。
        if (class_exists('QilingShop_Risk_Control')) {
            $risk = QilingShop_Risk_Control::instance();
            if ($risk->is_enabled()) {
                $risk_check = $risk->begin_invite_registration($inviter_id, $ip);
                if (is_wp_error($risk_check)) {
                    return false;
                }
                $risk_tokens = is_array($risk_check) ? $risk_check : [];
            } elseif (get_option('qilingshop_invite_ip_limit', true)) {
                $same_ip = $this->db->get_row('invites', ['ip_address' => $ip, 'inviter_id' => $inviter_id]);
                if ($same_ip) {
                    return false;
                }
            }
        } elseif (get_option('qilingshop_invite_ip_limit', true)) {
            $same_ip = $this->db->get_row('invites', ['ip_address' => $ip, 'inviter_id' => $inviter_id]);
            if ($same_ip) {
                return false;
            }
        }
    
        // 记录邀请关系
        $insert_id = $this->db->insert('invites', [
            'inviter_id'  => $inviter_id,
            'invitee_id'  => $invitee_id,
            'level'       => 1,
            'ip_address'  => $ip,
            'created_at'  => current_time('mysql'),
        ]);
        if (!$insert_id) {
            return false;
        }

        // 更新邀请计数
        $invite_count_updated = $this->db->query(
            $this->db->prepare(
                "UPDATE " . $this->db->get_table('user_info') . " SET invite_count = invite_count + 1 WHERE user_id = %d",
                $inviter_id
            )
        );
        if ($invite_count_updated === false) {
            $this->db->delete('invites', ['id' => (int) $insert_id]);
            return false;
        }

        // 发放邀请奖励
        if (!$this->pay_invite_bonus($inviter_id, $invitee_id)) {
            if (function_exists('qilingshop_log')) {
                qilingshop_log('Invite bonus pending retry after registration', 'error', [
                    'inviter_id' => (int) $inviter_id,
                    'invitee_id' => (int) $invitee_id,
                ]);
            }
        }

        if (!empty($risk_tokens) && class_exists('QilingShop_Risk_Control')) {
            QilingShop_Risk_Control::instance()->commit_tokens($risk_tokens);
        }

        // 处理邀请阶梯奖励
        $this->handle_invite_tier_bonus($inviter_id);

        do_action('qilingshop_invite_registered', $inviter_id, $invitee_id);
        $this->refresh_invite_stats_cache($inviter_id);

        return true;
    }

    public function process_pending_invite_bonuses($force = false) {
        $limit = $force ? 50 : 20;
        global $wpdb;

        $table = $this->db->get_table('invites');
        $stale_before = wp_date('Y-m-d H:i:s', current_time('timestamp') - self::INVITE_BONUS_TIMEOUT);
        $pending = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT inviter_id, invitee_id
                 FROM {$table}
                 WHERE level = %d
                   AND (
                        bonus_paid = %d
                        OR (
                            bonus_paid = %d
                            AND (bonus_paid_at IS NULL OR bonus_paid_at <= %s)
                        )
                   )
                 ORDER BY id ASC
                 LIMIT %d",
                1,
                self::INVITE_BONUS_PENDING,
                self::INVITE_BONUS_PROCESSING,
                $stale_before,
                $limit
            )
        );

        if (empty($pending)) {
            return 0;
        }

        $processed = 0;
        foreach ($pending as $invite) {
            $inviter_id = (int) ($invite->inviter_id ?? 0);
            $invitee_id = (int) ($invite->invitee_id ?? 0);
            if ($inviter_id <= 0 || $invitee_id <= 0) {
                continue;
            }

            if ($this->pay_invite_bonus($inviter_id, $invitee_id)) {
                $this->refresh_invite_stats_cache($inviter_id);
            }

            $processed++;
        }

        return $processed;
    }

    /**
     * 处理邀请阶梯奖励
     *
     * @param int $inviter_id
     */
    private function handle_invite_tier_bonus($inviter_id) {
        if (!get_option('qilingshop_invite_tier_enabled', false)) {
            return;
        }

        $inviter_id = (int) $inviter_id;
        if ($inviter_id <= 0) {
            return;
        }

        $invite_count = $this->get_invite_tier_progress_count($inviter_id);
        if ($invite_count <= 0) {
            return;
        }

        $rules = $this->db->get_results('invite_tier_rules', [
            'where' => [
                'status' => 1,
            ],
            'orderby' => 'threshold',
            'order' => 'ASC',
            'limit' => -1,
        ]);

        if (empty($rules)) {
            return;
        }

        foreach ($rules as $rule) {
            $rule_id = (int) $rule->id;
            $threshold = (int) $rule->threshold;
            $bonus = (float) $rule->bonus_points;

            if ($rule_id <= 0 || $threshold <= 0 || $bonus <= 0) {
                continue;
            }

            if ($invite_count < $threshold) {
                continue;
            }

            if (!$this->claim_invite_tier_reward($inviter_id, $rule_id, $invite_count, $bonus)) {
                continue;
            }

            $added = QilingShop_Points::instance()->add_points(
                $inviter_id,
                $bonus,
                'invite_tier',
                sprintf(__('邀请阶梯奖励：%s', 'qilingshop'), sprintf($this->get_invite_tier_threshold_text(), $threshold)),
                $rule_id
            );

            if (!$added) {
                $this->reset_invite_tier_reward($inviter_id, $rule_id);
                continue;
            }

            if (!$this->finalize_invite_tier_reward($inviter_id, $rule_id, $invite_count, $bonus)) {
                continue;
            }

            do_action('qilingshop_send_notification', [
                'user_id' => $inviter_id,
                'title'   => __('邀请阶梯奖励到账', 'qilingshop'),
                'content' => sprintf(__('%s，奖励 %s', 'qilingshop'), sprintf($this->get_invite_tier_threshold_text(), $threshold), qilingshop_format_points($bonus)),
                'type'    => 'success',
                'scene'   => 'qilingshop_invite_tier',
                'link'    => '',
            ]);
        }
    }

    /**
     * 抢占邀请阶梯奖励处理权
     *
     * @param int   $user_id
     * @param int   $rule_id
     * @param int   $invite_count
     * @param float $bonus
     * @return bool
     */
    private function claim_invite_tier_reward($user_id, $rule_id, $invite_count, $bonus) {
        global $wpdb;

        $user_id = (int) $user_id;
        $rule_id = (int) $rule_id;
        $invite_count = (int) $invite_count;
        $bonus = (float) $bonus;
        if ($user_id <= 0 || $rule_id <= 0 || $bonus <= 0) {
            return false;
        }

        $logs_table = $this->db->get_table('invite_tier_logs');
        $now = current_time('mysql');
        $timeout = 300;

        $this->db->begin_transaction();
        try {
            $log = $this->get_invite_tier_log_for_update($user_id, $rule_id);
            if ($log) {
                $status = (int) ($log->reward_status ?? self::INVITE_TIER_PENDING);
                if ($status === self::INVITE_TIER_APPLIED) {
                    $this->db->commit();
                    return false;
                }

                if (QilingShop_Points::instance()->has_points_log($user_id, 'invite_tier', $rule_id)) {
                    $this->mark_invite_tier_reward_applied((int) $log->id, $invite_count, $bonus);
                    $this->db->commit();
                    return false;
                }

                if ($status === self::INVITE_TIER_PROCESSING && !empty($log->reward_status_at)) {
                    $claimed_at = strtotime((string) $log->reward_status_at);
                    if ($claimed_at && (time() - $claimed_at) < $timeout) {
                        $this->db->commit();
                        return false;
                    }
                }

                $claimed = $wpdb->update(
                    $logs_table,
                    [
                        'invite_count'      => $invite_count,
                        'bonus_points'      => $bonus,
                        'reward_status'     => self::INVITE_TIER_PROCESSING,
                        'reward_status_at'  => $now,
                    ],
                    [
                        'id'            => (int) $log->id,
                        'reward_status' => $status,
                    ],
                    ['%d', '%f', '%d', '%s'],
                    ['%d', '%d']
                );

                if ($claimed === false) {
                    throw new Exception('Failed to claim invite tier reward');
                }

                $this->db->commit();
                return (int) $claimed === 1;
            }

            $inserted = $this->db->insert('invite_tier_logs', [
                'user_id'          => $user_id,
                'rule_id'          => $rule_id,
                'invite_count'     => $invite_count,
                'bonus_points'     => $bonus,
                'reward_status'    => self::INVITE_TIER_PROCESSING,
                'reward_status_at' => $now,
                'created_at'       => $now,
            ]);

            if (!$inserted) {
                if (stripos((string) $wpdb->last_error, 'Duplicate entry') === false) {
                    throw new Exception('Failed to insert invite tier reward log');
                }

                $log = $this->get_invite_tier_log_for_update($user_id, $rule_id);
                if (!$log) {
                    throw new Exception('Failed to reload invite tier reward log');
                }

                if (QilingShop_Points::instance()->has_points_log($user_id, 'invite_tier', $rule_id)) {
                    $this->mark_invite_tier_reward_applied((int) $log->id, $invite_count, $bonus);
                }

                $this->db->commit();
                return false;
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            qilingshop_log('Invite tier reward claim failed: ' . $e->getMessage(), 'error', [
                'user_id' => $user_id,
                'rule_id' => $rule_id,
            ]);
            return false;
        }
    }

    /**
     * 完成邀请阶梯奖励状态
     *
     * @param int   $user_id
     * @param int   $rule_id
     * @param int   $invite_count
     * @param float $bonus
     * @return bool
     */
    private function finalize_invite_tier_reward($user_id, $rule_id, $invite_count, $bonus) {
        $this->db->begin_transaction();
        try {
            $log = $this->get_invite_tier_log_for_update($user_id, $rule_id);
            if (!$log) {
                throw new Exception('Invite tier reward log not found');
            }

            if ((int) ($log->reward_status ?? self::INVITE_TIER_PENDING) !== self::INVITE_TIER_APPLIED) {
                $this->mark_invite_tier_reward_applied((int) $log->id, $invite_count, $bonus);
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            qilingshop_log('Invite tier reward finalize failed: ' . $e->getMessage(), 'error', [
                'user_id' => (int) $user_id,
                'rule_id' => (int) $rule_id,
            ]);
            return false;
        }
    }

    /**
     * 回退邀请阶梯奖励处理中状态
     *
     * @param int $user_id
     * @param int $rule_id
     * @return void
     */
    private function reset_invite_tier_reward($user_id, $rule_id) {
        global $wpdb;

        $logs_table = $this->db->get_table('invite_tier_logs');
        $this->db->begin_transaction();
        try {
            $log = $this->get_invite_tier_log_for_update($user_id, $rule_id);
            if ($log && (int) ($log->reward_status ?? self::INVITE_TIER_PENDING) === self::INVITE_TIER_PROCESSING) {
                $wpdb->update(
                    $logs_table,
                    [
                        'reward_status'    => self::INVITE_TIER_PENDING,
                        'reward_status_at' => null,
                    ],
                    ['id' => (int) $log->id],
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
     * 事务内锁定邀请阶梯奖励日志
     *
     * @param int $user_id
     * @param int $rule_id
     * @return object|null
     */
    private function get_invite_tier_log_for_update($user_id, $rule_id) {
        global $wpdb;

        $logs_table = $this->db->get_table('invite_tier_logs');
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$logs_table}
             WHERE user_id = %d AND rule_id = %d
             LIMIT 1
             FOR UPDATE",
            (int) $user_id,
            (int) $rule_id
        ));
    }

    /**
     * 标记邀请阶梯奖励已发放
     *
     * @param int   $log_id
     * @param int   $invite_count
     * @param float $bonus
     * @return void
     */
    private function mark_invite_tier_reward_applied($log_id, $invite_count, $bonus) {
        global $wpdb;

        $logs_table = $this->db->get_table('invite_tier_logs');
        $wpdb->update(
            $logs_table,
            [
                'invite_count'      => (int) $invite_count,
                'bonus_points'      => (float) $bonus,
                'reward_status'     => self::INVITE_TIER_APPLIED,
                'reward_status_at'  => current_time('mysql'),
            ],
            ['id' => (int) $log_id],
            ['%d', '%f', '%d', '%s'],
            ['%d']
        );
    }

    /**
     * 邀请阶梯奖励统计口径
     *
     * @return string registration|first_paid
     */
    private function get_invite_tier_metric() {
        $metric = sanitize_key((string) get_option('qilingshop_invite_tier_metric', 'registration'));
        if (!in_array($metric, ['registration', 'first_paid'], true)) {
            $metric = 'registration';
        }
        return $metric;
    }

    /**
     * 口径对应的阈值文案
     *
     * @return string
     */
    private function get_invite_tier_threshold_text() {
        if ($this->get_invite_tier_metric() === 'first_paid') {
            return __('累计有效邀请（首单消费）%d 人', 'qilingshop');
        }
        return __('累计邀请 %d 人', 'qilingshop');
    }

    /**
     * 获取邀请阶梯奖励进度人数
     *
     * @param int $inviter_id
     * @return int
     */
    private function get_invite_tier_progress_count($inviter_id) {
        $inviter_id = (int) $inviter_id;
        if ($inviter_id <= 0) {
            return 0;
        }

        if ($this->get_invite_tier_metric() !== 'first_paid') {
            $user_info = QilingShop_Points::instance()->get_user_info($inviter_id);
            return $user_info ? (int) ($user_info->invite_count ?? 0) : 0;
        }

        global $wpdb;
        if (!$wpdb) {
            return 0;
        }

        $invites_table = $this->db->get_table('invites');
        $orders_table = $this->db->get_table('orders');
        $recharge_table = $this->db->get_table('recharge');
        $shop_prefix = defined('QLS_SHOP_TABLE_PREFIX') ? QLS_SHOP_TABLE_PREFIX : 'qls_shop_';
        $shop_orders_table = $wpdb->prefix . $shop_prefix . 'orders';
        $resource_paid_status = class_exists('QilingShop_Order') ? (int) QilingShop_Order::STATUS_PAID : 1;
        $recharge_paid_status = class_exists('QilingShop_Recharge') ? (int) QilingShop_Recharge::STATUS_PAID : 1;
        $shop_paid_status = class_exists('QLS_Shop_Order') ? (int) QLS_Shop_Order::STATUS_PAID : 1;
        $shop_shipped_status = class_exists('QLS_Shop_Order') ? (int) QLS_Shop_Order::STATUS_SHIPPED : 2;
        $shop_completed_status = class_exists('QLS_Shop_Order') ? (int) QLS_Shop_Order::STATUS_COMPLETED : 3;

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

        if (!$table_exists($invites_table)) {
            return 0;
        }

        $paid_conditions = [];
        $prepare_args = [$inviter_id];

        if ($table_exists($orders_table)) {
            $paid_conditions[] = "EXISTS (
                SELECT 1 FROM `{$orders_table}` o
                WHERE o.user_id = i.invitee_id
                  AND o.status = %d
                LIMIT 1
            )";
            $prepare_args[] = $resource_paid_status;
        }

        if ($table_exists($recharge_table)) {
            $paid_conditions[] = "EXISTS (
                SELECT 1 FROM `{$recharge_table}` r
                WHERE r.user_id = i.invitee_id
                  AND r.status = %d
                LIMIT 1
            )";
            $prepare_args[] = $recharge_paid_status;
        }

        if ($table_exists($shop_orders_table)) {
            $paid_conditions[] = "EXISTS (
                SELECT 1 FROM `{$shop_orders_table}` s
                WHERE s.user_id = i.invitee_id
                  AND s.status IN (%d, %d, %d)
                LIMIT 1
            )";
            $prepare_args[] = $shop_paid_status;
            $prepare_args[] = $shop_shipped_status;
            $prepare_args[] = $shop_completed_status;
        }

        if (empty($paid_conditions)) {
            return 0;
        }

        $sql = "SELECT COUNT(DISTINCT i.invitee_id)
                FROM `{$invites_table}` i
                WHERE i.inviter_id = %d
                  AND i.level = 1
                  AND i.is_valid = 1
                  AND (" . implode(' OR ', $paid_conditions) . ')';

        $prepared_sql = $wpdb->prepare($sql, $prepare_args);
        if (!$prepared_sql) {
            return 0;
        }

        return (int) $wpdb->get_var($prepared_sql);
    }

    /**
     * 资源/VIP订单支付后：若启用首单口径，尝试触发邀请阶梯结算
     *
     * @param int $order_id
     * @param string $payment_method
     * @return void
     */
    public function handle_invitee_paid_resource($order_id, $payment_method = '') {
        if ($this->get_invite_tier_metric() !== 'first_paid' || !class_exists('QilingShop_Order')) {
            return;
        }
        $order = QilingShop_Order::instance()->get((int) $order_id);
        $invitee_id = (int) ($order->user_id ?? 0);
        $this->handle_invitee_paid_trigger($invitee_id);
    }

    /**
     * 商城订单支付后：若启用首单口径，尝试触发邀请阶梯结算
     *
     * @param int $order_id
     * @param string $payment_method
     * @return void
     */
    public function handle_invitee_paid_shop($order_id, $payment_method = '') {
        if ($this->get_invite_tier_metric() !== 'first_paid' || !class_exists('QLS_Shop_Order')) {
            return;
        }
        $order = QLS_Shop_Order::instance()->get((int) $order_id);
        $invitee_id = (int) ($order->user_id ?? 0);
        $this->handle_invitee_paid_trigger($invitee_id);
    }

    /**
     * 充值支付后：若启用首单口径，尝试触发邀请阶梯结算
     *
     * @param int $user_id
     * @param float $amount
     * @param float $points_received
     * @return void
     */
    public function handle_invitee_paid_recharge($user_id, $amount = 0, $points_received = 0) {
        if ($this->get_invite_tier_metric() !== 'first_paid') {
            return;
        }
        $this->handle_invitee_paid_trigger((int) $user_id);
    }

    /**
     * 支付触发邀请阶梯结算（首单口径）
     *
     * @param int $invitee_id
     * @return void
     */
    private function handle_invitee_paid_trigger($invitee_id) {
        $invitee_id = (int) $invitee_id;
        if ($invitee_id <= 0) {
            return;
        }

        $user_info = QilingShop_Points::instance()->get_user_info($invitee_id);
        $inviter_id = (int) ($user_info->inviter_id ?? 0);
        if ($inviter_id <= 0) {
            return;
        }

        $this->handle_invite_tier_bonus($inviter_id);
    }

    /**
     * 发放邀请奖励
     */
    private function pay_invite_bonus($inviter_id, $invitee_id) {
        $points = QilingShop_Points::instance();
        $claim = $this->claim_invite_bonus_processing($inviter_id, $invitee_id);
        if ($claim === 'missing') {
            return false;
        }
        if ($claim === 'applied') {
            return true;
        }
        if ($claim === 'busy') {
            return false;
        }

        // 邀请人奖励
        $inviter_bonus = (float) get_option('qilingshop_invite_bonus_inviter', 0);
        if ($inviter_bonus > 0) {
            $inviter_bonus = apply_filters('qilingshop_invite_bonus_inviter', $inviter_bonus, $inviter_id, $invitee_id);

            if (!$points->has_points_log($inviter_id, 'affiliate', $invitee_id)) {
                $bonus_paid = $points->add_points(
                    $inviter_id,
                    $inviter_bonus,
                    'affiliate',
                    __('邀请好友注册奖励', 'qilingshop'),
                    $invitee_id
                );

                if (!$bonus_paid) {
                    $this->reset_invite_bonus_processing($inviter_id, $invitee_id);
                    return false;
                }
            }
        }

        // 被邀请人奖励
        $invitee_bonus = (float) get_option('qilingshop_invite_bonus_invitee', 0);
        if ($invitee_bonus > 0) {
            $invitee_bonus = apply_filters('qilingshop_invite_bonus_invitee', $invitee_bonus, $inviter_id, $invitee_id);
            $invitee_related_id = $inviter_id > 0 ? $inviter_id : $invitee_id;

            if (!$points->has_points_log($invitee_id, 'invite', $invitee_related_id)) {
                $invitee_bonus_paid = $points->add_points(
                    $invitee_id,
                    $invitee_bonus,
                    'invite',
                    __('受邀注册奖励', 'qilingshop'),
                    $invitee_related_id
                );

                if (!$invitee_bonus_paid) {
                    $this->reset_invite_bonus_processing($inviter_id, $invitee_id);
                    return false;
                }
            }
        }

        $updated = $this->db->update('invites', [
            'bonus_paid'    => self::INVITE_BONUS_APPLIED,
            'bonus_paid_at' => current_time('mysql'),
            'bonus_amount'  => $inviter_bonus,
        ], [
            'inviter_id' => $inviter_id,
            'invitee_id' => $invitee_id,
            'level'      => 1,
            'bonus_paid' => self::INVITE_BONUS_PROCESSING,
        ]);
        if ($updated === false) {
            $this->reset_invite_bonus_processing($inviter_id, $invitee_id);
            return false;
        }

        return true;
    }

    private function claim_invite_bonus_processing($inviter_id, $invitee_id) {
        global $wpdb;

        $inviter_id = (int) $inviter_id;
        $invitee_id = (int) $invitee_id;
        if ($inviter_id <= 0 || $invitee_id <= 0) {
            return 'missing';
        }

        $table = $this->db->get_table('invites');
        $now = current_time('mysql');

        $this->db->begin_transaction();
        try {
            $invite = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE inviter_id = %d AND invitee_id = %d AND level = 1 LIMIT 1 FOR UPDATE",
                    $inviter_id,
                    $invitee_id
                )
            );

            if (!$invite) {
                throw new Exception('Invite record not found');
            }

            $state = (int) ($invite->bonus_paid ?? self::INVITE_BONUS_PENDING);
            $inviter_bonus = (float) get_option('qilingshop_invite_bonus_inviter', 0);
            $invitee_bonus = (float) get_option('qilingshop_invite_bonus_invitee', 0);
            $invitee_related_id = $inviter_id > 0 ? $inviter_id : $invitee_id;
            $inviter_logged = $inviter_bonus <= 0 || QilingShop_Points::instance()->has_points_log($inviter_id, 'affiliate', $invitee_id);
            $invitee_logged = $invitee_bonus <= 0 || QilingShop_Points::instance()->has_points_log($invitee_id, 'invite', $invitee_related_id);

            if ($state === self::INVITE_BONUS_APPLIED || ($inviter_logged && $invitee_logged)) {
                if ($state !== self::INVITE_BONUS_APPLIED) {
                    $this->db->update('invites', [
                        'bonus_paid'    => self::INVITE_BONUS_APPLIED,
                        'bonus_paid_at' => $now,
                    ], ['id' => (int) $invite->id]);
                }
                $this->db->commit();
                return 'applied';
            }

            if ($state === self::INVITE_BONUS_PROCESSING) {
                $claimed_at = !empty($invite->bonus_paid_at) ? strtotime((string) $invite->bonus_paid_at) : 0;
                if ($claimed_at > 0 && (current_time('timestamp') - $claimed_at) < self::INVITE_BONUS_TIMEOUT) {
                    $this->db->commit();
                    return 'busy';
                }
            }

            $updated = $this->db->update('invites', [
                'bonus_paid'    => self::INVITE_BONUS_PROCESSING,
                'bonus_paid_at' => $now,
            ], [
                'id'         => (int) $invite->id,
                'bonus_paid' => $state,
            ]);

            if ($updated === false) {
                throw new Exception('Failed to claim invite bonus processing state');
            }

            $this->db->commit();
            return (int) $updated === 1 ? 'claimed' : 'busy';
        } catch (Exception $e) {
            $this->db->rollback();
            if (function_exists('qilingshop_log')) {
                qilingshop_log('Invite bonus claim failed: ' . $e->getMessage(), 'error', [
                    'inviter_id' => $inviter_id,
                    'invitee_id' => $invitee_id,
                ]);
            }
            return 'missing';
        }
    }

    private function reset_invite_bonus_processing($inviter_id, $invitee_id) {
        $this->db->update('invites', [
            'bonus_paid'    => self::INVITE_BONUS_PENDING,
            'bonus_paid_at' => null,
        ], [
            'inviter_id' => (int) $inviter_id,
            'invitee_id' => (int) $invitee_id,
            'level'      => 1,
            'bonus_paid' => self::INVITE_BONUS_PROCESSING,
        ]);
    }

    /**
     * 处理消费提成
     */
    public function process_commission($user_id, $amount, $source = 'resource', $order_no = '') {
        if (!get_option('qilingshop_affiliate_enabled', true)) {
            return true;
        }

        if ($amount <= 0) {
            return true;
        }

        $order_no = sanitize_text_field((string) $order_no);
        $source = sanitize_text_field((string) $source);

        $user_info = QilingShop_Points::instance()->get_user_info($user_id);
        if (!$user_info || $user_info->inviter_id <= 0) {
            return true;
        }

        $success = true;

        // 一级提成
        $level1_rate = (float) get_option('qilingshop_affiliate_level1_rate', 10);
        $level1_rate = apply_filters('qilingshop_affiliate_commission_rate', $level1_rate, 1, $user_info->inviter_id);

        if ($level1_rate > 0) {
            $commission = round($amount * $level1_rate / 100, 2);
            if (!$this->add_commission($user_info->inviter_id, $user_id, $commission, $level1_rate, 1, $source, $order_no)) {
                $success = false;
            }
        }

        // 二级提成
        $level2_rate = (float) get_option('qilingshop_affiliate_level2_rate', 5);
        $inviter_info = QilingShop_Points::instance()->get_user_info($user_info->inviter_id);
        
        if ($level2_rate > 0 && $inviter_info && $inviter_info->inviter_id > 0) {
            $level2_rate = apply_filters('qilingshop_affiliate_commission_rate', $level2_rate, 2, $inviter_info->inviter_id);
            $commission = round($amount * $level2_rate / 100, 2);
            if (!$this->add_commission($inviter_info->inviter_id, $user_id, $commission, $level2_rate, 2, $source, $order_no)) {
                $success = false;
            }
        }

        return $success;
    }

    public function queue_commission_retry($user_id, $amount, $source = 'resource', $order_no = '', $context = '') {
        $user_id = (int) $user_id;
        $amount = (float) $amount;
        $source = sanitize_text_field((string) $source);
        $order_no = sanitize_text_field((string) $order_no);
        $context = sanitize_text_field((string) $context);

        if ($user_id <= 0 || $amount <= 0 || $source === '' || $order_no === '') {
            return false;
        }

        $key = $this->build_commission_retry_key($user_id, $source, $order_no);
        $now = current_time('mysql');
        $entry = [
            'user_id'      => $user_id,
            'amount'       => $amount,
            'source'       => $source,
            'order_no'     => $order_no,
            'context'      => $context,
            'attempts'     => 0,
            'queued_at'    => $now,
            'updated_at'   => $now,
            'last_attempt' => null,
        ];

        $lock_token = wp_generate_password(20, false, false);
        $lock_acquired = false;
        for ($attempt = 0; $attempt < self::COMMISSION_QUEUE_LOCK_RETRIES; $attempt++) {
            if ($this->acquire_commission_queue_lock($lock_token)) {
                $lock_acquired = true;
                break;
            }

            if ($attempt + 1 < self::COMMISSION_QUEUE_LOCK_RETRIES) {
                usleep(self::COMMISSION_QUEUE_LOCK_BACKOFF_US);
            }
        }

        if (!$lock_acquired) {
            return $this->persist_commission_retry_shadow($key, $entry);
        }

        try {
            $queue = get_option(self::COMMISSION_RETRY_OPTION, []);
            if (!is_array($queue)) {
                $queue = [];
            }

            $existing = isset($queue[$key]) && is_array($queue[$key]) ? $queue[$key] : [];
            $queue[$key] = [
                'user_id'      => $entry['user_id'],
                'amount'       => $entry['amount'],
                'source'       => $entry['source'],
                'order_no'     => $entry['order_no'],
                'context'      => $entry['context'],
                'attempts'     => (int) ($existing['attempts'] ?? 0),
                'queued_at'    => $existing['queued_at'] ?? $entry['queued_at'],
                'updated_at'   => $entry['updated_at'],
                'last_attempt' => $existing['last_attempt'] ?? null,
            ];

            $persisted = $this->persist_commission_retry_queue($queue);
            if ($persisted) {
                $this->delete_commission_retry_shadow($key);
                return true;
            }

            return $this->persist_commission_retry_shadow($key, $queue[$key]);
        } finally {
            $this->release_commission_queue_lock($lock_token);
        }
    }

    public function process_retry_queue($force = false) {
        $queue = get_option(self::COMMISSION_RETRY_OPTION, []);
        if (!is_array($queue)) {
            $queue = [];
        }

        $lock_token = wp_generate_password(20, false, false);
        if (!$this->acquire_commission_retry_lock($lock_token)) {
            return 0;
        }

        $processed = 0;
        $limit = $force ? 50 : 20;

        try {
            $queue = get_option(self::COMMISSION_RETRY_OPTION, []);
            if (!is_array($queue)) {
                $queue = [];
            }
            $queue = $this->merge_commission_retry_shadows($queue);
            if (empty($queue)) {
                return 0;
            }

            foreach ($queue as $key => $entry) {
                if (!is_array($entry)) {
                    unset($queue[$key]);
                    $this->delete_commission_retry_shadow($key);
                    continue;
                }

                $attempts = (int) ($entry['attempts'] ?? 0);
                $last_attempt = !empty($entry['last_attempt']) ? strtotime((string) $entry['last_attempt']) : 0;
                if (!$force && $attempts > 0 && $last_attempt > 0 && (current_time('timestamp') - $last_attempt) < 600) {
                    continue;
                }

                $ok = $this->process_commission(
                    (int) ($entry['user_id'] ?? 0),
                    (float) ($entry['amount'] ?? 0),
                    (string) ($entry['source'] ?? 'resource'),
                    (string) ($entry['order_no'] ?? '')
                );

                if ($ok) {
                    unset($queue[$key]);
                    $this->delete_commission_retry_shadow($key);
                } else {
                    $entry['attempts'] = $attempts + 1;
                    $entry['last_attempt'] = current_time('mysql');
                    $entry['updated_at'] = current_time('mysql');
                    $queue[$key] = $entry;
                    $this->persist_commission_retry_shadow($key, $entry);
                }

                $processed++;
                if ($processed >= $limit) {
                    break;
                }
            }

            $this->persist_commission_retry_queue($queue);
        } finally {
            $this->release_commission_retry_lock($lock_token);
        }

        return $processed;
    }

    private function build_commission_retry_key($user_id, $source, $order_no) {
        return md5((int) $user_id . '|' . sanitize_text_field((string) $source) . '|' . sanitize_text_field((string) $order_no));
    }

    private function get_commission_shadow_option_name($key) {
        return self::COMMISSION_SHADOW_PREFIX . sanitize_key((string) $key);
    }

    private function persist_commission_retry_shadow($key, array $entry) {
        $option_name = $this->get_commission_shadow_option_name($key);
        if (get_option($option_name, null) === null) {
            return add_option($option_name, $entry, '', 'no');
        }

        return update_option($option_name, $entry, false);
    }

    private function delete_commission_retry_shadow($key) {
        delete_option($this->get_commission_shadow_option_name($key));
    }

    private function merge_commission_retry_shadows(array $queue) {
        global $wpdb;

        $like = $wpdb->esc_like(self::COMMISSION_SHADOW_PREFIX) . '%';
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
            $key = substr($option_name, strlen(self::COMMISSION_SHADOW_PREFIX));
            if ($key === '') {
                continue;
            }

            $entry = maybe_unserialize($row->option_value);
            if (!is_array($entry)) {
                $this->delete_commission_retry_shadow($key);
                continue;
            }

            if (!isset($queue[$key]) || !is_array($queue[$key])) {
                $queue[$key] = $entry;
            }
        }

        return $queue;
    }

    private function persist_commission_retry_queue(array $queue) {
        if (get_option(self::COMMISSION_RETRY_OPTION, null) === null) {
            return add_option(self::COMMISSION_RETRY_OPTION, $queue, '', 'no');
        }

        $updated = update_option(self::COMMISSION_RETRY_OPTION, $queue, false);
        if ($updated) {
            return true;
        }

        $current = get_option(self::COMMISSION_RETRY_OPTION, null);
        if ($current === $queue) {
            return true;
        }

        delete_option(self::COMMISSION_RETRY_OPTION);
        return add_option(self::COMMISSION_RETRY_OPTION, $queue, '', 'no');
    }

    private function acquire_commission_queue_lock($token) {
        $now = current_time('timestamp');
        $payload = [
            'token'   => (string) $token,
            'expires' => $now + self::COMMISSION_QUEUE_LOCK_TTL,
        ];

        if (add_option(self::COMMISSION_QUEUE_LOCK_OPTION, $payload, '', 'no')) {
            return true;
        }

        $existing = get_option(self::COMMISSION_QUEUE_LOCK_OPTION, []);
        $expires = isset($existing['expires']) ? absint($existing['expires']) : 0;
        if ($expires > $now) {
            return false;
        }

        delete_option(self::COMMISSION_QUEUE_LOCK_OPTION);
        return add_option(self::COMMISSION_QUEUE_LOCK_OPTION, $payload, '', 'no');
    }

    private function release_commission_queue_lock($token) {
        $current = get_option(self::COMMISSION_QUEUE_LOCK_OPTION, []);
        $current_token = isset($current['token']) ? (string) $current['token'] : '';
        if ($current_token !== '' && hash_equals($current_token, (string) $token)) {
            delete_option(self::COMMISSION_QUEUE_LOCK_OPTION);
        }
    }

    private function acquire_commission_retry_lock($token) {
        $now = current_time('timestamp');
        $payload = [
            'token'   => (string) $token,
            'expires' => $now + self::COMMISSION_RETRY_LOCK_TTL,
        ];

        if (add_option(self::COMMISSION_RETRY_LOCK_OPTION, $payload, '', 'no')) {
            return true;
        }

        $existing = get_option(self::COMMISSION_RETRY_LOCK_OPTION, []);
        $expires = isset($existing['expires']) ? absint($existing['expires']) : 0;
        if ($expires > $now) {
            return false;
        }

        delete_option(self::COMMISSION_RETRY_LOCK_OPTION);
        return add_option(self::COMMISSION_RETRY_LOCK_OPTION, $payload, '', 'no');
    }

    private function release_commission_retry_lock($token) {
        $current = get_option(self::COMMISSION_RETRY_LOCK_OPTION, []);
        $current_token = isset($current['token']) ? (string) $current['token'] : '';
        if ($current_token !== '' && hash_equals($current_token, (string) $token)) {
            delete_option(self::COMMISSION_RETRY_LOCK_OPTION);
        }
    }

    /**
     * 添加佣金记录
     */
    private function add_commission($user_id, $from_user_id, $amount, $rate, $level, $source, $order_no) {
        if ($amount <= 0) {
            return false;
        }

        global $wpdb;

        $user_id = (int) $user_id;
        $from_user_id = (int) $from_user_id;
        $level = (int) $level;
        $source = sanitize_text_field((string) $source);
        $order_no = sanitize_text_field((string) $order_no);
        $amount = (float) $amount;
        $rate = (float) $rate;

        $affiliate_id = 0;
        $need_points_award = false;
        $now = current_time('mysql');
        $points_state_timeout = 300;

        $this->db->begin_transaction();

        try {
            $record = null;
            if ($order_no !== '') {
                $record = $this->get_commission_record_for_update($from_user_id, $source, $order_no, $level);
            }

            if (!$record) {
                $affiliate_id = $this->db->insert('affiliate', [
                    'user_id'            => $user_id,
                    'from_user_id'       => $from_user_id,
                    'amount'             => $amount,
                    'commission_rate'    => $rate,
                    'level'              => $level,
                    'source'             => $source,
                    'order_no'           => $order_no,
                    'status'             => 1,
                    'balance_applied'    => 0,
                    'balance_applied_at' => null,
                    'points_applied'     => 0,
                    'points_applied_at'  => null,
                    'ip_address'         => qilingshop_security()->get_client_ip(),
                    'created_at'         => $now,
                ]);

                if (!$affiliate_id) {
                    $duplicate = $order_no !== '' && stripos((string) $wpdb->last_error, 'Duplicate entry') !== false;
                    if (!$duplicate) {
                        throw new Exception('Failed to insert affiliate commission');
                    }
                    $record = $this->get_commission_record_for_update($from_user_id, $source, $order_no, $level);
                    if (!$record) {
                        throw new Exception('Failed to load affiliate commission after duplicate insert');
                    }
                } else {
                    $record = $this->db->get_by_id('affiliate', $affiliate_id);
                    if (!$record) {
                        throw new Exception('Failed to load affiliate commission');
                    }
                }
            }

            $affiliate_id = (int) $record->id;

            if ((int) ($record->balance_applied ?? 0) !== 1) {
                $updated = $this->db->query(
                    $this->db->prepare(
                        "UPDATE " . $this->db->get_table('user_info') . "
                         SET affiliate_earnings = affiliate_earnings + %f,
                             withdrawable_balance = withdrawable_balance + %f
                         WHERE user_id = %d",
                        $amount,
                        $amount,
                        $user_id
                    )
                );

                if ($updated === false || (int) $updated !== 1) {
                    throw new Exception('Failed to apply affiliate balance');
                }

                $flagged = $this->db->update('affiliate', [
                    'balance_applied'    => 1,
                    'balance_applied_at' => $now,
                ], [
                    'id'              => $affiliate_id,
                    'balance_applied' => 0,
                ]);

                if ($flagged === false || (int) $flagged !== 1) {
                    throw new Exception('Failed to mark affiliate balance applied');
                }
            }

            $points_state = (int) ($record->points_applied ?? 0);
            if ($points_state === 2 && QilingShop_Points::instance()->has_points_log($user_id, 'affiliate', $affiliate_id)) {
                $finalized = $this->db->update('affiliate', [
                    'points_applied'    => 1,
                    'points_applied_at' => $now,
                ], [
                    'id'             => $affiliate_id,
                    'points_applied' => 2,
                ]);
                if ($finalized === false) {
                    throw new Exception('Failed to finalize affiliate points state');
                }
                $points_state = 1;
            }

            if ($points_state === 2) {
                $claimed_at = !empty($record->points_applied_at) ? strtotime((string) $record->points_applied_at) : 0;
                $expired_claim = $claimed_at > 0 && (current_time('timestamp') - $claimed_at) >= $points_state_timeout;
                if ($expired_claim) {
                    $reset = $this->db->update('affiliate', [
                        'points_applied'    => 0,
                        'points_applied_at' => null,
                    ], [
                        'id'             => $affiliate_id,
                        'points_applied' => 2,
                    ]);
                    if ($reset === false) {
                        throw new Exception('Failed to reset stale affiliate points state');
                    }
                    $points_state = 0;
                }
            }

            if ($points_state === 0) {
                $claimed = $this->db->update('affiliate', [
                    'points_applied'    => 2,
                    'points_applied_at' => $now,
                ], [
                    'id'             => $affiliate_id,
                    'points_applied' => 0,
                ]);

                if ($claimed === false || (int) $claimed !== 1) {
                    throw new Exception('Failed to claim affiliate points award');
                }

                $need_points_award = true;
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            qilingshop_log('Affiliate commission failed: ' . $e->getMessage(), 'error', [
                'user_id'      => $user_id,
                'from_user_id' => $from_user_id,
                'source'       => $source,
                'order_no'     => $order_no,
                'level'        => $level,
            ]);
            return false;
        }

        if ($need_points_award) {
            $points = qilingshop_rmb_to_points($amount);
            $points_manager = QilingShop_Points::instance();
            $points_logged = $points_manager->has_points_log($user_id, 'affiliate', $affiliate_id);

            if (!$points_logged) {
                $points_added = $points_manager->add_points(
                    $user_id,
                    $points,
                    'affiliate',
                    sprintf(__('%d级推广提成', 'qilingshop'), $level),
                    $affiliate_id
                );

                if (!$points_added) {
                    $this->db->update('affiliate', [
                        'points_applied'    => 0,
                        'points_applied_at' => null,
                    ], [
                        'id'             => $affiliate_id,
                        'points_applied' => 2,
                    ]);
                    qilingshop_log('Affiliate commission points award failed', 'error', [
                        'affiliate_id' => $affiliate_id,
                        'user_id'      => $user_id,
                    ]);
                    return false;
                }
            }

            $flagged = $this->db->update('affiliate', [
                'points_applied'    => 1,
                'points_applied_at' => current_time('mysql'),
            ], [
                'id'             => $affiliate_id,
                'points_applied' => 2,
            ]);

            if ($flagged === false) {
                qilingshop_log('Affiliate commission points flag update failed', 'error', [
                    'affiliate_id' => $affiliate_id,
                ]);
                return false;
            }
        }

        do_action('qilingshop_affiliate_commission_paid', $user_id, $amount, $source);
        $this->refresh_invite_stats_cache($user_id);

        return true;
    }

    /**
     * 事务内按自然键锁定佣金记录
     *
     * @param int    $from_user_id
     * @param string $source
     * @param string $order_no
     * @param int    $level
     * @return object|null
     */
    private function get_commission_record_for_update($from_user_id, $source, $order_no, $level) {
        global $wpdb;

        $table = $this->db->get_table('affiliate');
        $sql = $this->db->prepare(
            "SELECT * FROM {$table}
             WHERE from_user_id = %d
               AND source = %s
               AND order_no = %s
               AND level = %d
             LIMIT 1
             FOR UPDATE",
            (int) $from_user_id,
            sanitize_text_field((string) $source),
            sanitize_text_field((string) $order_no),
            (int) $level
        );

        return $wpdb->get_row($sql);
    }

    /**
     * 获取用户邀请统计
     */
    public function get_invite_stats($user_id) {
        $user_id = (int) $user_id;
        $cache_key = $this->get_invite_stats_cache_key($user_id);
        $cached = wp_cache_get($cache_key, 'qilingshop');
        if ($cached !== false) {
            return $cached;
        }

        return $this->refresh_invite_stats_cache($user_id);
    }

    /**
     * 获取邀请列表
     */
    public function get_invite_list($user_id, $args = []) {
        $defaults = ['limit' => 20, 'offset' => 0];
        $args = wp_parse_args($args, $defaults);

        global $wpdb;
        $table = $this->db->get_table('invites');
        $affiliate_table = $this->db->get_table('affiliate');
        $users_table = $wpdb->users;

        $limit = max(1, intval($args['limit']));
        $offset = max(0, intval($args['offset']));
        $inviter_id = (int) $user_id;
        $affiliate_agg = $wpdb->prepare(
            "SELECT user_id, from_user_id, SUM(amount) AS total_commission
             FROM {$affiliate_table}
             WHERE user_id = %d
             GROUP BY user_id, from_user_id",
            $inviter_id
        );

        $sql = $wpdb->prepare(
            "SELECT i.*, u.user_login, u.user_email, u.user_registered,
             COALESCE(a.total_commission, 0) AS total_commission
             FROM {$table} i
             LEFT JOIN {$users_table} u ON i.invitee_id = u.ID
             LEFT JOIN ({$affiliate_agg}) a ON a.user_id = i.inviter_id AND a.from_user_id = i.invitee_id
             WHERE i.inviter_id = %d AND i.level = 1
             ORDER BY i.id DESC
             LIMIT %d OFFSET %d",
            $user_id, $limit, $offset
        );

        return $wpdb->get_results($sql);
    }

    /**
     * 获取邀请总数
     *
     * @param int $user_id
     * @return int
     */
    public function get_invite_total($user_id) {
        return $this->db->count('invites', ['inviter_id' => (int) $user_id, 'level' => 1]);
    }

    /**
     * 获取邀请阶梯奖励规则
     *
     * @param bool|null $only_active 是否仅返回启用规则
     * @return array
     */
    public function get_invite_tier_rules($only_active = null) {
        $where = [];
        if ($only_active !== null) {
            $where['status'] = $only_active ? 1 : 0;
        }

        return $this->db->get_results('invite_tier_rules', [
            'where'   => $where,
            'orderby' => 'threshold',
            'order'   => 'ASC',
            'limit'   => -1,
        ]);
    }

    /**
     * 获取用户已发放的阶梯奖励规则ID
     *
     * @param int $user_id
     * @return array
     */
    public function get_invite_tier_awarded_ids($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return [];
        }

        $rows = $this->db->get_results('invite_tier_logs', [
            'where'   => ['user_id' => $user_id],
            'fields'  => 'rule_id',
            'orderby' => 'id',
            'order'   => 'DESC',
            'limit'   => -1,
        ]);

        if (empty($rows)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', wp_list_pluck($rows, 'rule_id'))));
    }

    /**
     * 获取佣金记录
     */
    public function get_commission_log($user_id, $args = []) {
        $defaults = ['limit' => 20, 'offset' => 0];
        $args = wp_parse_args($args, $defaults);

        return $this->db->get_results('affiliate', [
            'where'   => ['user_id' => $user_id],
            'orderby' => 'id',
            'order'   => 'DESC',
            'limit'   => $args['limit'],
            'offset'  => $args['offset'],
        ]);
    }

    /**
     * 获取佣金记录数量
     *
     * @param int $user_id
     * @return int
     */
    public function get_commission_total($user_id) {
        return $this->db->count('affiliate', ['user_id' => (int) $user_id]);
    }
}
