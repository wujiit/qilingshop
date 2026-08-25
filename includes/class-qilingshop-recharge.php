<?php
/**
 * 充值管理类
 *
 * @package QilingShop
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Recharge {

    private static $instance = null;
    private $db;

    const STATUS_PENDING = 0;
    const STATUS_PAID = 1;
    const STATUS_CANCELLED = 2;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->db = QilingShop_Database::instance();
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
     * 构造充值单写操作互斥锁名。
     *
     * @param int $recharge_id
     * @return string
     */
    private function build_recharge_lock_name($recharge_id) {
        return 'qlsrcg_' . md5((string) absint($recharge_id));
    }

    /**
     * 释放充值单占用的商城优惠券。
     *
     * @param object $order
     * @param bool   $use_transaction
     * @return bool
     */
    private function release_coupon_reservation_for_order($order, $use_transaction = true, $restore_used = false) {
        if (!class_exists('QLS_Coupon') || !is_object($order) || empty($order->remark)) {
            return true;
        }

        $remark_data = json_decode((string) $order->remark, true);
        $coupon_claim_id = is_array($remark_data) ? absint($remark_data['coupon_claim_id'] ?? 0) : 0;
        $discount_amount = is_array($remark_data) ? (float) ($remark_data['discount_amount'] ?? 0) : (float) ($order->discount_amount ?? 0);
        if ($coupon_claim_id <= 0 || $discount_amount <= 0) {
            return true;
        }

        if ($restore_used && method_exists('QLS_Coupon', 'release_or_restore_for_order')) {
            return QLS_Coupon::release_or_restore_for_order($coupon_claim_id, (string) $order->order_no, $use_transaction);
        }

        return QLS_Coupon::release_reservation($coupon_claim_id, (string) $order->order_no, $use_transaction);
    }

    /**
     * 事务内按订单号锁定充值单
     *
     * @param string $order_no
     * @return object|null
     */
    private function get_by_order_no_for_update($order_no) {
        global $wpdb;

        $table = $this->db->get_table('recharge');
        $sql = $this->db->prepare(
            "SELECT * FROM {$table} WHERE order_no = %s LIMIT 1 FOR UPDATE",
            $order_no
        );

        return $wpdb->get_row($sql);
    }

    /**
     * 释放充值收尾处理中状态，避免 paid_handled=2 长期卡死。
     *
     * @param string $order_no
     * @param string $reason
     * @return bool
     */
    private function reset_finalize_claim($order_no, $reason = '') {
        $order_no = sanitize_text_field((string) $order_no);
        if ($order_no === '') {
            return false;
        }

        $reset = $this->db->update('recharge', [
            'paid_handled'    => 0,
            'paid_handled_at' => null,
        ], [
            'order_no'      => $order_no,
            'paid_handled'  => 2,
        ]);

        if ($reset === false) {
            qilingshop_log('Recharge finalize claim reset failed', 'error', [
                'order_no' => $order_no,
                'reason'   => $reason,
            ]);
            return false;
        }

        if ((int) $reset === 0) {
            $latest = $this->get_by_order_no($order_no);
            if ($latest && (int) ($latest->paid_handled ?? 0) === 1) {
                return true;
            }
            qilingshop_log('Recharge finalize claim reset skipped', 'error', [
                'order_no' => $order_no,
                'reason'   => $reason,
            ]);
            return false;
        }

        return true;
    }

    /**
     * 创建充值订单
     */
    public function create_order($user_id, $amount, $payment_method = '', $final_amount = 0, $discount_amount = 0, $remark = '', $order_no = '') {
        // 验证金额
        $min = (float) get_option('qilingshop_recharge_min_amount', 1);
        $max = (float) get_option('qilingshop_recharge_max_amount', 10000);

        if ($amount < $min) {
            return ['success' => false, 'message' => sprintf(__('最小充值金额为 %s 元', 'qilingshop'), $min)];
        }
        if ($max > 0 && $amount > $max) {
            return ['success' => false, 'message' => sprintf(__('最大充值金额为 %s 元', 'qilingshop'), $max)];
        }

        // 如果未传入实付金额，默认等于原价
        if ($final_amount <= 0 && $discount_amount <= 0) {
            $final_amount = $amount;
        }

        // 计算积分
        $ratio = qilingshop_get_points_ratio();
        $base_points = $amount * $ratio;
        $bonus_points = $this->calculate_bonus($amount);
        $total_points = $base_points + $bonus_points;

        $order_no = sanitize_text_field((string) $order_no);
        if ($order_no === '') {
            $order_no = qilingshop_security()->generate_order_no('CZ');
        }

        $order_id = $this->db->insert('recharge', [
            'order_no'        => $order_no,
            'user_id'         => $user_id,
            'amount'          => $amount,
            'final_amount'    => $final_amount,
            'discount_amount' => $discount_amount,
            'remark'          => is_string($remark) ? $remark : '',
            'points_received' => $total_points,
            'bonus_points'    => $bonus_points,
            'payment_method'  => $payment_method,
            'status'          => self::STATUS_PENDING,
            'ip_address'      => qilingshop_security()->get_client_ip(),
            'created_at'      => current_time('mysql'),
        ]);

        if (!$order_id) {
            global $wpdb;
            $db_error = $wpdb->last_error ?: '';
            return ['success' => false, 'message' => __('创建订单失败', 'qilingshop') . ($db_error ? ': ' . $db_error : '')];
        }

        do_action('qilingshop_recharge_order_created', $order_no, $user_id, $amount);

        return [
            'success'       => true,
            'order_no'      => $order_no,
            'amount'        => $amount,
            'points'        => $total_points,
            'bonus_points'  => $bonus_points,
        ];
    }

    /**
     * 计算充值奖励
     */
    public function calculate_bonus($amount) {
        $bonus = 0;

        // 获取奖励规则
        $rules = $this->db->get_results('recharge_bonus', [
            'where'   => ['is_active' => 1],
            'orderby' => 'min_amount',
            'order'   => 'DESC',
            'limit'   => -1,
        ]);

        foreach ($rules as $rule) {
            if ($amount >= $rule->min_amount) {
                if ($rule->max_amount === null || $amount <= $rule->max_amount) {
                    if ($rule->bonus_type === 'fixed') {
                        $bonus = (float) $rule->bonus_value;
                    } else {
                        // percent
                        $bonus = $amount * $rule->bonus_value / 100;
                    }
                    break;
                }
            }
        }

        $ratio = qilingshop_get_points_ratio();
        $bonus_points = $bonus * $ratio;

        return apply_filters('qilingshop_recharge_bonus', $bonus_points, $amount);
    }

    /**
     * 完成充值
     */
    public function complete($order_no, $payment_no = '') {
        $order_no = sanitize_text_field((string) $order_no);
        if ($order_no === '') {
            return false;
        }

        try {
            $order = $this->get_by_order_no($order_no);
            if (!$order) {
                throw new Exception('Recharge order not found');
            }

            $lock_name = $this->build_recharge_lock_name((int) $order->id);
            if (!$this->acquire_named_lock($lock_name, 5)) {
                throw new Exception('Recharge order busy');
            }

            try {
                $order = $this->get_by_order_no($order_no);
                if (!$order) {
                    throw new Exception('Recharge order not found');
                }

                $status = (int) $order->status;
                if ($status === self::STATUS_CANCELLED) {
                    throw new Exception('Recharge order cancelled');
                }
                if (!in_array($status, [self::STATUS_PENDING, self::STATUS_PAID], true)) {
                    throw new Exception('Recharge order status invalid');
                }

                if ($status === self::STATUS_PENDING) {
                    // 仅允许待支付 -> 已支付，避免并发重复处理
                    $updated = $this->db->update('recharge', [
                        'status'     => self::STATUS_PAID,
                        'payment_no' => $payment_no,
                        'paid_at'    => current_time('mysql'),
                    ], [
                        'order_no' => $order_no,
                        'status'   => self::STATUS_PENDING,
                    ]);

                    if ($updated === false) {
                        throw new Exception('Failed to update recharge status');
                    }
                    if ((int) $updated === 0) {
                        $latest = $this->get_by_order_no($order_no);
                        if (!$latest || (int) $latest->status !== self::STATUS_PAID) {
                            throw new Exception('Recharge status transition rejected');
                        }
                        $order = $latest;
                    }
                }

                return $this->finalize_paid_recharge($order_no);
            } finally {
                $this->release_named_lock($lock_name);
            }
        } catch (Exception $e) {
            qilingshop_log('Recharge complete failed: ' . $e->getMessage(), 'error', ['order_no' => $order_no]);
            return false;
        }
    }

    /**
     * 充值单支付后的幂等收尾
     *
     * @param string $order_no
     * @return bool
     */
    private function finalize_paid_recharge($order_no) {
        $order_no = sanitize_text_field((string) $order_no);
        if ($order_no === '') {
            return false;
        }

        $processing_timeout = 300;
        $order = null;
        $claimed_state = false;
        $claim_time = current_time('mysql');

        $this->db->begin_transaction();

        try {
            $locked_order = $this->get_by_order_no_for_update($order_no);
            if (!$locked_order) {
                throw new Exception('Recharge order not found when finalizing');
            }

            if ((int) $locked_order->status !== self::STATUS_PAID) {
                throw new Exception('Recharge order status invalid when finalizing');
            }

            $handled_state = (int) ($locked_order->paid_handled ?? 0);
            if ($handled_state === 1) {
                $this->db->commit();
                return true;
            }

            if ($handled_state === 2) {
                $claimed_at = !empty($locked_order->paid_handled_at) ? strtotime((string) $locked_order->paid_handled_at) : 0;
                $is_stale = $claimed_at > 0 && (current_time('timestamp') - $claimed_at) >= $processing_timeout;
                if (!$is_stale) {
                    $this->db->commit();
                    return true;
                }
            }

            $updated = $this->db->update('recharge', [
                'paid_handled'    => 2,
                'paid_handled_at' => $claim_time,
            ], [
                'id'           => (int) $locked_order->id,
                'paid_handled' => $handled_state === 2 ? 2 : 0,
            ]);

            if ($updated === false || (int) $updated !== 1) {
                throw new Exception('Failed to claim recharge finalization');
            }

            $order = $locked_order;
            $claimed_state = true;
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            qilingshop_log('Recharge finalize claim failed: ' . $e->getMessage(), 'error', ['order_no' => $order_no]);
            return false;
        }

        try {
            if (class_exists('QLS_Coupon') && !empty($order->remark)) {
                $remark_data = json_decode($order->remark, true);
                $coupon_claim_id = $remark_data['coupon_claim_id'] ?? 0;
                $discount_amount = $remark_data['discount_amount'] ?? 0;
                if ($coupon_claim_id > 0 && $discount_amount > 0) {
                    $coupon_used = QLS_Coupon::mark_as_used($coupon_claim_id, $order_no, 'recharge', $order->amount, $discount_amount);
                    if (!$coupon_used) {
                        throw new Exception('Failed to mark coupon as used');
                    }
                }
            }

            $points_manager = QilingShop_Points::instance();
            $points_logged = $points_manager->has_points_log((int) $order->user_id, 'recharge', (int) $order->id);
            if (!$points_logged) {
                $points_added = $points_manager->add_points(
                    (int) $order->user_id,
                    (float) $order->points_received,
                    'recharge',
                    sprintf(__('充值 %s 元', 'qilingshop'), $order->amount),
                    (int) $order->id
                );
                if (!$points_added) {
                    throw new Exception('Failed to add recharge points');
                }
            }

            $total_recharged = (float) $this->db->sum('recharge', 'amount', [
                'user_id' => (int) $order->user_id,
                'status'  => self::STATUS_PAID,
            ]);
            $recharged_updated = $this->db->update('user_info', [
                'total_recharged' => $total_recharged,
            ], [
                'user_id' => (int) $order->user_id,
            ]);
            if ($recharged_updated === false) {
                throw new Exception('Failed to update total_recharged');
            }
            if ((int) $recharged_updated === 0) {
                $user_info = $this->db->get_row('user_info', ['user_id' => (int) $order->user_id]);
                if (!$user_info) {
                    throw new Exception('User info not found');
                }
            }

            $commission_ok = QilingShop_Affiliate::instance()->process_commission(
                (int) $order->user_id,
                (float) $order->amount,
                'recharge',
                $order_no
            );
            if (!$commission_ok) {
                $queued = QilingShop_Affiliate::instance()->queue_commission_retry(
                    (int) $order->user_id,
                    (float) $order->amount,
                    'recharge',
                    (string) $order_no,
                    'recharge_order'
                );
                throw new Exception($queued ? 'Affiliate commission queued for retry' : 'Affiliate commission retry queue failed');
            }
        } catch (Exception $e) {
            if ($claimed_state) {
                $this->reset_finalize_claim($order_no, $e->getMessage());
            }
            qilingshop_log('Recharge finalize failed: ' . $e->getMessage(), 'error', ['order_no' => $order_no]);
            return false;
        }

        $completed = $this->db->update('recharge', [
            'paid_handled'    => 1,
            'paid_handled_at' => current_time('mysql'),
        ], [
            'order_no'      => $order_no,
            'paid_handled'  => 2,
        ]);

        if ($completed === false) {
            qilingshop_log('Recharge finalize completion mark failed', 'error', ['order_no' => $order_no]);
            $this->reset_finalize_claim($order_no, 'completion mark failed');
            return false;
        }

        if ((int) $completed === 0) {
            $latest = $this->get_by_order_no($order_no);
            if (!$latest || (int) ($latest->paid_handled ?? 0) !== 1) {
                qilingshop_log('Recharge finalize completion mark lost', 'error', ['order_no' => $order_no]);
                $this->reset_finalize_claim($order_no, 'completion mark lost');
                return false;
            }
        }

        do_action('qilingshop_recharge_completed', $order->user_id, $order->amount, $order->points_received);
        return true;
    }

    /**
     * 发送管理员通知
     */
    private function send_admin_notification($order) {
        $user = get_user_by('ID', $order->user_id);
        $message = sprintf(
            __("用户 %s 充值了 %s 元\n订单号：%s\n到账积分：%s\n充值时间：%s", 'qilingshop'),
            $user ? $user->user_login : $order->user_id,
            $order->amount,
            $order->order_no,
            $order->points_received,
            current_time('Y-m-d H:i:s')
        );

        wp_mail(
            get_option('admin_email'),
            sprintf(__('[%s] 充值通知', 'qilingshop'), get_bloginfo('name')),
            $message
        );
    }

    /**
     * 获取充值订单
     */
    public function get_by_order_no($order_no) {
        return $this->db->get_row('recharge', ['order_no' => $order_no]);
    }

    /**
     * 根据ID获取充值订单。
     *
     * @param int $recharge_id
     * @return object|null
     */
    public function get($recharge_id) {
        return $this->db->get_by_id('recharge', (int) $recharge_id);
    }

    /**
     * 安全删除待支付充值单。
     *
     * @param int $recharge_id
     * @return bool
     */
    public function delete_pending($recharge_id) {
        $recharge_id = (int) $recharge_id;
        if ($recharge_id <= 0) {
            return false;
        }

        $order = $this->get($recharge_id);
        if (!$order) {
            return false;
        }

        $lock_name = $this->build_recharge_lock_name($recharge_id);
        if (!$this->acquire_named_lock($lock_name, 5)) {
            return false;
        }

        try {
            $this->db->begin_transaction();

            $latest = $this->get($recharge_id);
            if (!$latest || (int) $latest->status !== self::STATUS_PENDING) {
                $this->db->rollback();
                return false;
            }

            if (!$this->release_coupon_reservation_for_order($latest, false)) {
                throw new Exception('Failed to release coupon reservation');
            }

            $deleted = $this->db->delete('recharge', [
                'id'     => $recharge_id,
                'status' => self::STATUS_PENDING,
            ]);

            if ($deleted === false || (int) $deleted !== 1) {
                throw new Exception('Failed to delete pending recharge');
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            qilingshop_log('Delete pending recharge failed: ' . $e->getMessage(), 'error', [
                'recharge_id' => $recharge_id,
            ]);
            return false;
        } finally {
            $this->release_named_lock($lock_name);
        }
    }

    /**
     * 取消未完成的 0 元内部充值单，并回滚本订单优惠券占用。
     *
     * @param string $order_no
     * @return bool
     */
    public function cancel_failed_internal_order($order_no) {
        $order_no = sanitize_text_field((string) $order_no);
        if ($order_no === '') {
            return false;
        }

        $order = $this->get_by_order_no($order_no);
        if (!$order || empty($order->id)) {
            return false;
        }

        $lock_name = $this->build_recharge_lock_name((int) $order->id);
        if (!$this->acquire_named_lock($lock_name, 5)) {
            return false;
        }

        try {
            $this->db->begin_transaction();

            $latest = $this->get_by_order_no($order_no);
            if (!$latest || (int) ($latest->paid_handled ?? 0) === 1 || (float) ($latest->final_amount ?? 0) > 0) {
                $this->db->rollback();
                return false;
            }

            if (!$this->release_coupon_reservation_for_order($latest, false, true)) {
                throw new Exception('Failed to release coupon reservation');
            }

            $updated = $this->db->update('recharge', [
                'status'          => self::STATUS_CANCELLED,
                'paid_at'         => null,
                'paid_handled'    => 0,
                'paid_handled_at' => null,
            ], [
                'id' => (int) $latest->id,
            ]);

            if ($updated === false || (int) $updated !== 1) {
                throw new Exception('Failed to cancel failed internal recharge');
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            qilingshop_log('Cancel failed internal recharge failed: ' . $e->getMessage(), 'error', [
                'order_no' => $order_no,
            ]);
            return false;
        } finally {
            $this->release_named_lock($lock_name);
        }
    }

    /**
     * 获取用户充值记录
     */
    public function get_user_records($user_id, $args = []) {
        $defaults = ['limit' => 20, 'offset' => 0];
        $args = wp_parse_args($args, $defaults);

        return $this->db->get_results('recharge', [
            'where'   => ['user_id' => $user_id, 'status' => self::STATUS_PAID],
            'orderby' => 'id',
            'order'   => 'DESC',
            'limit'   => $args['limit'],
            'offset'  => $args['offset'],
        ]);
    }

    /**
     * 获取奖励规则
     */
    public function get_bonus_rules() {
        return $this->db->get_results('recharge_bonus', [
            'where'   => ['is_active' => 1],
            'orderby' => 'sort_order',
            'order'   => 'ASC',
            'limit'   => -1,
        ]);
    }

    /**
     * 添加奖励规则
     */
    public function add_bonus_rule($data) {
        return $this->db->insert('recharge_bonus', [
            'min_amount'  => (float) $data['min_amount'],
            'max_amount'  => isset($data['max_amount']) ? (float) $data['max_amount'] : null,
            'bonus_type'  => $data['bonus_type'] ?? 'fixed',
            'bonus_value' => (float) $data['bonus_value'],
            'description' => $data['description'] ?? '',
            'is_active'   => 1,
            'created_at'  => current_time('mysql'),
        ]);
    }

    /**
     * 删除奖励规则
     */
    public function delete_bonus_rule($rule_id) {
        return $this->db->delete('recharge_bonus', ['id' => $rule_id]);
    }
}
