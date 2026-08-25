<?php
/**
 * 积分管理类
 *
 * 处理积分余额、流水、签到、注册奖励、积分有效期、冻结/解冻
 *
 * @package QilingShop
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Points {

    /**
     * 单例实例
     *
     * @var QilingShop_Points
     */
    private static $instance = null;

    /**
     * 数据库实例
     *
     * @var QilingShop_Database
     */
    private $db;

    /**
     * 积分资产表名
     *
     * @var string
     */
    private $points_assets_table = '';

    /**
     * 积分资产表存在性缓存
     *
     * @var bool|null
     */
    private $points_assets_table_exists = null;

    /**
     * 获取单例实例
     *
     * @return QilingShop_Points
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
        $this->db = QilingShop_Database::instance();
        $this->points_assets_table = $this->db->get_table('points_assets');
        $this->init_hooks();
    }

    /**
     * 初始化钩子
     */
    private function init_hooks() {
        // 用户注册时初始化积分账户
        add_action('user_register', [$this, 'init_user_account'], 10, 1);

        // VIP 每日检查
        add_action('qilingshop_daily_vip_check', [$this, 'handle_vip_expiry']);

        // 积分有效期维护（到期提醒 + 自动过期）
        add_action('qilingshop_daily_points_maintenance', [$this, 'handle_points_maintenance']);
    }

    /**
     * 积分有效期开关
     *
     * @return bool
     */
    public function is_points_validity_enabled() {
        return (bool) get_option('qilingshop_points_validity_enabled', false);
    }

    /**
     * 获取有效期天数
     *
     * @return int
     */
    private function get_points_validity_days() {
        $days = absint(get_option('qilingshop_points_validity_days', 365));
        return max(1, $days);
    }

    /**
     * 判断积分资产表是否存在
     *
     * @return bool
     */
    private function is_points_assets_table_ready() {
        if ($this->points_assets_table_exists !== null) {
            return $this->points_assets_table_exists;
        }

        global $wpdb;
        $table = $this->points_assets_table;
        if ($table === '') {
            $this->points_assets_table_exists = false;
            return false;
        }

        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        $this->points_assets_table_exists = ($found === $table);
        return $this->points_assets_table_exists;
    }

    /**
     * 初始化用户积分账户
     *
     * @param int $user_id 用户 ID
     */
    public function init_user_account($user_id) {
        // 检查是否已存在
        $exists = $this->db->get_row('user_info', ['user_id' => $user_id]);
        if ($exists) {
            return;
        }

        // 生成邀请码
        $invite_code = qilingshop_security()->generate_invite_code($user_id);

        // 检查是否有推荐人
        $inviter_id = 0;
        if (isset($_COOKIE['qls_inviter_id']) && !empty($_COOKIE['qls_inviter_id'])) {
            $candidate_inviter_id = intval($_COOKIE['qls_inviter_id']);
            if ($candidate_inviter_id > 0 && get_userdata($candidate_inviter_id)) {
                $inviter_id = $candidate_inviter_id;
            }
        }

        if ($inviter_id <= 0 && isset($_COOKIE['qilingshop_aff'])) {
            $aff_code = sanitize_text_field($_COOKIE['qilingshop_aff']);
            $inviter = $this->db->get_row('user_info', ['invite_code' => $aff_code]);
            if ($inviter) {
                $inviter_id = $inviter->user_id;
            }
        }

        // 创建用户积分账户
        $insert_id = $this->db->insert('user_info', [
            'user_id'     => $user_id,
            'invite_code' => $invite_code,
            'inviter_id'  => $inviter_id,
            'reg_ip'      => qilingshop_security()->get_client_ip(),
            'created_at'  => current_time('mysql'),
        ]);

        if (!$insert_id) {
            return;
        }

        // 处理注册奖励
        $bonus_result = $this->handle_registration_bonus($user_id, $inviter_id);
        if (is_array($bonus_result) && empty($bonus_result['success'])) {
            if (function_exists('qilingshop_log')) {
                qilingshop_log('Registration bonus handling failed', 'error', [
                    'user_id' => (int) $user_id,
                    'inviter_id' => (int) $inviter_id,
                    'register_bonus_failed' => !empty($bonus_result['register_bonus_failed']),
                    'invite_failed' => !empty($bonus_result['invite_failed']),
                ]);
            }
        }

        /**
         * 用户积分账户创建后
         *
         * @param int $user_id    用户 ID
         * @param int $inviter_id 推荐人 ID
         */
        do_action('qilingshop_user_account_created', $user_id, $inviter_id);
    }

    /**
     * 处理注册奖励
     *
     * @param int $user_id    用户 ID
     * @param int $inviter_id 推荐人 ID
     */
    private function handle_registration_bonus($user_id, $inviter_id) {
        $result = [
            'success' => true,
            'register_bonus_failed' => false,
            'invite_failed' => false,
        ];

        $bonus_enabled = get_option('qilingshop_register_bonus_enabled', false);
        $bonus_amount = (float) get_option('qilingshop_register_bonus_amount', 0);
        $stack_invite = get_option('qilingshop_register_bonus_stack_invite', false);

        // 注册奖励
        if ($bonus_enabled && $bonus_amount > 0) {
            // 如果有推荐人且不叠加，则跳过注册奖励
            if ($inviter_id > 0 && !$stack_invite) {
                // 不发放注册奖励
            } else {
                /**
                 * 过滤注册奖励积分
                 *
                 * @param float $bonus_amount 奖励积分数量
                 * @param int   $user_id      用户 ID
                 */
                $bonus_amount = apply_filters('qilingshop_registration_bonus', $bonus_amount, $user_id);

                $bonus_added = $this->add_points($user_id, $bonus_amount, 'register', __('新用户注册奖励', 'qilingshop'));
                if (!$bonus_added) {
                    $result['success'] = false;
                    $result['register_bonus_failed'] = true;
                    return $result;
                }
            }
        }

        // 邀请奖励
        if ($inviter_id > 0) {
            $invite_registered = QilingShop_Affiliate::instance()->handle_invite_registration($inviter_id, $user_id);
            if (!$invite_registered) {
                $result['success'] = false;
                $result['invite_failed'] = true;
                return $result;
            }
        }

        return $result;
    }

    /**
     * 获取用户积分余额（可用积分）
     *
     * @param int $user_id 用户 ID
     * @return float
     */
    public function get_balance($user_id) {
        $user_info = $this->get_user_info($user_id);

        if (!$user_info) {
            return 0;
        }

        return (float) $user_info->points_balance;
    }

    /**
     * 获取用户冻结积分
     *
     * @param int $user_id 用户 ID
     * @return float
     */
    public function get_frozen_balance($user_id) {
        $user_id = absint($user_id);
        if ($user_id <= 0 || !$this->is_points_assets_table_ready()) {
            return 0;
        }

        global $wpdb;
        $sum = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(frozen_amount), 0) FROM {$this->points_assets_table} WHERE user_id = %d",
            $user_id
        ));

        return (float) $sum;
    }

    /**
     * 获取积分概览（前台展示）
     *
     * @param int $user_id 用户 ID
     * @return array
     */
    public function get_points_overview($user_id) {
        $user_id = absint($user_id);
        $available = $this->get_balance($user_id);
        $frozen = $this->get_frozen_balance($user_id);

        $overview = [
            'available'       => $available,
            'frozen'          => $frozen,
            'total'           => $available + $frozen,
            'validity_enabled'=> $this->is_points_validity_enabled(),
            'expiring_soon'   => 0,
            'next_expire_at'  => '',
            'permanent'       => $available,
            'expiring_days'   => 0,
        ];

        if ($user_id <= 0 || !$this->is_points_assets_table_ready()) {
            return $overview;
        }

        global $wpdb;
        $now = current_time('mysql');
        $range_end = date('Y-m-d 23:59:59', strtotime($now . ' + 30 days'));

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN remaining_amount > 0 AND is_permanent = 1 THEN remaining_amount ELSE 0 END), 0) AS permanent_amount,
                COALESCE(SUM(CASE WHEN remaining_amount > 0 AND is_permanent = 0 AND expires_at > %s AND expires_at <= %s THEN remaining_amount ELSE 0 END), 0) AS expiring_soon,
                MIN(CASE WHEN remaining_amount > 0 AND is_permanent = 0 AND expires_at > %s THEN expires_at ELSE NULL END) AS next_expire_at
             FROM {$this->points_assets_table}
             WHERE user_id = %d",
            $now,
            $range_end,
            $now,
            $user_id
        ));

        if ($row) {
            $overview['permanent'] = (float) $row->permanent_amount;
            if ($overview['validity_enabled']) {
                $overview['expiring_soon'] = (float) $row->expiring_soon;
                $overview['next_expire_at'] = !empty($row->next_expire_at) ? (string) $row->next_expire_at : '';
            }
        }

        if (!$overview['validity_enabled']) {
            $overview['expiring_soon'] = 0;
            $overview['next_expire_at'] = '';
            $overview['permanent'] = $available;
        }

        $overview['expiring_days'] = 30;
        return $overview;
    }

    /**
     * 获取用户信息
     *
     * @param int $user_id 用户 ID
     * @return object|null
     */
    public function get_user_info($user_id) {
        $cache_key = 'qilingshop_user_info_' . $user_id;
        $user_info = wp_cache_get($cache_key, 'qilingshop');

        if ($user_info !== false) {
            return $user_info;
        }

        $user_info = $this->db->get_row('user_info', ['user_id' => $user_id]);

        // 如果不存在，创建一个
        if (!$user_info && $user_id > 0) {
            $this->init_user_account($user_id);
            $user_info = $this->db->get_row('user_info', ['user_id' => $user_id]);
        }

        if ($user_info) {
            wp_cache_set($cache_key, $user_info, 'qilingshop', 3600);
        }

        return $user_info;
    }

    /**
     * 清除用户信息缓存
     *
     * @param int $user_id 用户 ID
     */
    public function clear_user_cache($user_id) {
        wp_cache_delete('qilingshop_user_info_' . $user_id, 'qilingshop');
    }

    /**
     * 检查积分流水是否存在
     *
     * @param int    $user_id
     * @param string $source
     * @param int    $related_id
     * @return bool
     */
    public function has_points_log($user_id, $source, $related_id) {
        $user_id = (int) $user_id;
        $related_id = (int) $related_id;
        $source = sanitize_text_field($source);
        if ($user_id <= 0 || $related_id <= 0 || $source === '') {
            return false;
        }

        $row = $this->db->get_row('points_log', [
            'user_id'    => $user_id,
            'source'     => $source,
            'related_id' => $related_id,
        ]);
        return !empty($row);
    }

    /**
     * 增加积分
     *
     * @param int    $user_id     用户 ID
     * @param float  $amount      积分数量（正数）
     * @param string $source      来源
     * @param string $description 描述
     * @param int    $related_id  关联 ID
     * @return bool
     */
    public function add_points($user_id, $amount, $source = 'admin', $description = '', $related_id = 0) {
        $user_id = absint($user_id);
        $amount = abs((float) $amount);

        if ($user_id <= 0 || $amount <= 0) {
            return false;
        }

        do_action('qilingshop_before_add_points', $user_id, $amount, $source);

        $this->db->begin_transaction();

        try {
            $now = current_time('mysql');
            $locked_row = $this->lock_user_row($user_id);
            if (!$locked_row) {
                throw new Exception('User not found');
            }

            $current_balance = (float) $locked_row->points_balance;

            $expire_result = $this->auto_expire_locked_assets($user_id, $current_balance, $now, true);
            $current_balance = (float) $expire_result['balance'];

            $this->reconcile_assets_with_balance($user_id, $current_balance, $now);

            $new_balance = $current_balance + $amount;

            $updated = $this->db->update(
                'user_info',
                ['points_balance' => $new_balance, 'updated_at' => $now],
                ['user_id' => $user_id]
            );
            if ($updated === false) {
                throw new Exception('Failed to update balance');
            }

            if (!$this->create_points_asset_batch($user_id, $amount, $source, $related_id, $now)) {
                throw new Exception('Failed to create points batch');
            }

            $log_id = $this->insert_points_log(
                $user_id,
                $amount,
                $new_balance,
                'income',
                $source,
                $description,
                $related_id
            );
            if (!$log_id) {
                throw new Exception('Failed to insert log');
            }

            $this->db->commit();
            $this->clear_user_cache($user_id);

            if (($expire_result['available_expired'] + $expire_result['frozen_expired']) > 0) {
                $this->notify_points_expired($user_id, (float) $expire_result['available_expired'], (float) $expire_result['frozen_expired']);
            }

            do_action('qilingshop_after_add_points', $user_id, $amount, $source, $new_balance);
            return true;

        } catch (Exception $e) {
            $this->db->rollback();
            qilingshop_log('Add points failed: ' . $e->getMessage(), 'error', [
                'user_id' => $user_id,
                'amount'  => $amount,
            ]);
            return false;
        }
    }

    /**
     * 扣除积分
     *
     * @param int    $user_id     用户 ID
     * @param float  $amount      积分数量（正数）
     * @param string $source      来源
     * @param string $description 描述
     * @param int    $related_id  关联 ID
     * @param bool   $use_transaction 是否在本方法内开启/提交事务
     * @return bool
     */
    public function deduct_points($user_id, $amount, $source = 'purchase', $description = '', $related_id = 0, $use_transaction = true) {
        $user_id = absint($user_id);
        $amount = abs((float) $amount);

        if ($user_id <= 0 || $amount <= 0) {
            return false;
        }

        $use_transaction = (bool) $use_transaction;
        if ($use_transaction) {
            $this->db->begin_transaction();
        }

        try {
            $now = current_time('mysql');
            $locked_row = $this->lock_user_row($user_id);
            if (!$locked_row) {
                throw new Exception('User not found');
            }

            $current_balance = (float) $locked_row->points_balance;
            $current_consumed = (float) $locked_row->total_consumed;

            $expire_result = $this->auto_expire_locked_assets($user_id, $current_balance, $now, true);
            $current_balance = (float) $expire_result['balance'];

            $this->reconcile_assets_with_balance($user_id, $current_balance, $now);

            if ($current_balance < $amount) {
                if ($use_transaction) {
                    $this->commit_expire_sync_on_early_return($user_id, (float) $locked_row->points_balance, $expire_result, $now);
                }
                return false;
            }

            if ($use_transaction) {
                do_action('qilingshop_before_deduct_points', $user_id, $amount, $source);
            }

            if (!$this->consume_available_assets($user_id, $amount, $now)) {
                throw new Exception('Failed to consume points batches');
            }

            $new_balance = $current_balance - $amount;

            $updated = $this->db->update(
                'user_info',
                [
                    'points_balance'  => $new_balance,
                    'total_consumed'  => $current_consumed + $amount,
                    'updated_at'      => $now,
                ],
                ['user_id' => $user_id]
            );
            if ($updated === false) {
                throw new Exception('Failed to update balance');
            }

            $log_id = $this->insert_points_log(
                $user_id,
                $amount,
                $new_balance,
                'expense',
                $source,
                $description,
                $related_id
            );
            if (!$log_id) {
                throw new Exception('Failed to insert log');
            }

            if ($use_transaction) {
                $this->db->commit();
                $this->clear_user_cache($user_id);
            }

            if ($use_transaction && ($expire_result['available_expired'] + $expire_result['frozen_expired']) > 0) {
                $this->notify_points_expired($user_id, (float) $expire_result['available_expired'], (float) $expire_result['frozen_expired']);
            }

            if ($use_transaction) {
                do_action('qilingshop_after_deduct_points', $user_id, $amount, $source, $new_balance);
            }
            return true;

        } catch (Exception $e) {
            if ($use_transaction) {
                $this->db->rollback();
            }
            qilingshop_log('Deduct points failed: ' . $e->getMessage(), 'error', [
                'user_id' => $user_id,
                'amount'  => $amount,
            ]);
            return false;
        }
    }

    /**
     * 冻结积分（从可用积分转入冻结积分）
     *
     * @param int    $user_id 用户ID
     * @param float  $amount  冻结数量
     * @param string $description 备注
     * @param int    $related_id 关联ID
     * @return bool
     */
    public function freeze_points($user_id, $amount, $description = '', $related_id = 0) {
        $user_id = absint($user_id);
        $amount = abs((float) $amount);

        if ($user_id <= 0 || $amount <= 0 || !$this->is_points_assets_table_ready()) {
            return false;
        }

        $this->db->begin_transaction();

        try {
            $now = current_time('mysql');
            $locked_row = $this->lock_user_row($user_id);
            if (!$locked_row) {
                throw new Exception('User not found');
            }

            $current_balance = (float) $locked_row->points_balance;
            $expire_result = $this->auto_expire_locked_assets($user_id, $current_balance, $now, true);
            $current_balance = (float) $expire_result['balance'];
            $this->reconcile_assets_with_balance($user_id, $current_balance, $now);

            if ($current_balance < $amount) {
                $this->commit_expire_sync_on_early_return($user_id, (float) $locked_row->points_balance, $expire_result, $now);
                return false;
            }

            if (!$this->shift_available_to_frozen($user_id, $amount, $now)) {
                throw new Exception('Failed to freeze points batches');
            }

            $new_balance = $current_balance - $amount;
            $updated = $this->db->update(
                'user_info',
                ['points_balance' => $new_balance, 'updated_at' => $now],
                ['user_id' => $user_id]
            );
            if ($updated === false) {
                throw new Exception('Failed to update balance');
            }

            $log_id = $this->insert_points_log(
                $user_id,
                $amount,
                $new_balance,
                'expense',
                'points_freeze',
                $description ?: __('积分冻结', 'qilingshop'),
                $related_id
            );
            if (!$log_id) {
                throw new Exception('Failed to insert log');
            }

            $this->db->commit();
            $this->clear_user_cache($user_id);

            if (($expire_result['available_expired'] + $expire_result['frozen_expired']) > 0) {
                $this->notify_points_expired($user_id, (float) $expire_result['available_expired'], (float) $expire_result['frozen_expired']);
            }

            $this->send_site_notification(
                $user_id,
                __('积分已冻结', 'qilingshop'),
                sprintf(__('已冻结 %s 积分，冻结期间不可用于消费。', 'qilingshop'), qilingshop_format_points($amount)),
                'warning',
                'qilingshop_points_frozen'
            );

            do_action('qilingshop_points_frozen', $user_id, $amount, $new_balance, $description, $related_id);
            return true;

        } catch (Exception $e) {
            $this->db->rollback();
            qilingshop_log('Freeze points failed: ' . $e->getMessage(), 'error', [
                'user_id' => $user_id,
                'amount'  => $amount,
            ]);
            return false;
        }
    }

    /**
     * 解冻积分（从冻结积分转回可用积分）
     *
     * @param int    $user_id 用户ID
     * @param float  $amount  解冻数量
     * @param string $description 备注
     * @param int    $related_id 关联ID
     * @return bool
     */
    public function unfreeze_points($user_id, $amount, $description = '', $related_id = 0) {
        $user_id = absint($user_id);
        $amount = abs((float) $amount);

        if ($user_id <= 0 || $amount <= 0 || !$this->is_points_assets_table_ready()) {
            return false;
        }

        $this->db->begin_transaction();

        try {
            $now = current_time('mysql');
            $locked_row = $this->lock_user_row($user_id);
            if (!$locked_row) {
                throw new Exception('User not found');
            }

            $current_balance = (float) $locked_row->points_balance;
            $expire_result = $this->auto_expire_locked_assets($user_id, $current_balance, $now, true);
            $current_balance = (float) $expire_result['balance'];
            $this->reconcile_assets_with_balance($user_id, $current_balance, $now);

            $current_frozen = $this->get_frozen_total_for_update($user_id, $now);
            if ($current_frozen < $amount) {
                $this->commit_expire_sync_on_early_return($user_id, (float) $locked_row->points_balance, $expire_result, $now);
                return false;
            }

            if (!$this->shift_frozen_to_available($user_id, $amount, $now)) {
                throw new Exception('Failed to unfreeze points batches');
            }

            $new_balance = $current_balance + $amount;
            $updated = $this->db->update(
                'user_info',
                ['points_balance' => $new_balance, 'updated_at' => $now],
                ['user_id' => $user_id]
            );
            if ($updated === false) {
                throw new Exception('Failed to update balance');
            }

            $log_id = $this->insert_points_log(
                $user_id,
                $amount,
                $new_balance,
                'income',
                'points_unfreeze',
                $description ?: __('积分解冻', 'qilingshop'),
                $related_id
            );
            if (!$log_id) {
                throw new Exception('Failed to insert log');
            }

            $this->db->commit();
            $this->clear_user_cache($user_id);

            if (($expire_result['available_expired'] + $expire_result['frozen_expired']) > 0) {
                $this->notify_points_expired($user_id, (float) $expire_result['available_expired'], (float) $expire_result['frozen_expired']);
            }

            $this->send_site_notification(
                $user_id,
                __('积分已解冻', 'qilingshop'),
                sprintf(__('已解冻 %s 积分，当前可正常使用。', 'qilingshop'), qilingshop_format_points($amount)),
                'success',
                'qilingshop_points_unfrozen'
            );

            do_action('qilingshop_points_unfrozen', $user_id, $amount, $new_balance, $description, $related_id);
            return true;

        } catch (Exception $e) {
            $this->db->rollback();
            qilingshop_log('Unfreeze points failed: ' . $e->getMessage(), 'error', [
                'user_id' => $user_id,
                'amount'  => $amount,
            ]);
            return false;
        }
    }

    /**
     * 增加可提现余额
     *
     * @param int    $user_id     用户 ID
     * @param float  $amount      金额（正数）
     * @param string $source      来源
     * @param string $description 描述
     * @param int    $related_id  关联 ID
     * @return bool
     */
    public function add_withdrawable_balance($user_id, $amount, $source = 'author_commission', $description = '', $related_id = 0) {
        $user_id = absint($user_id);
        $amount = abs((float) $amount);
        $source = sanitize_key((string) $source);
        $description = $this->sanitize_points_log_description($description);

        if ($user_id <= 0 || $amount <= 0) {
            return false;
        }

        $this->db->begin_transaction();
        try {
            $now = current_time('mysql');

            // 更新可提现余额
            $result = $this->db->query(
                $this->db->prepare(
                    "UPDATE " . $this->db->get_table('user_info') . "
                     SET withdrawable_balance = withdrawable_balance + %f,
                         updated_at = %s
                     WHERE user_id = %d",
                    $amount,
                    $now,
                    $user_id
                )
            );
            if ($result === false || (int) $result < 1) {
                throw new Exception('Failed to update withdrawable balance');
            }

            // 记录流水：balance_after 对可提现余额来源记录“可提现余额”
            global $wpdb;
            $withdrawable_after = (float) $wpdb->get_var($this->db->prepare(
                "SELECT withdrawable_balance FROM " . $this->db->get_table('user_info') . " WHERE user_id = %d",
                $user_id
            ));
            $log_id = $this->db->insert('points_log', [
                'user_id'       => $user_id,
                'amount'        => $amount,
                'balance_after' => $withdrawable_after,
                'type'          => 'income',
                'source'        => $source,
                'description'   => $description,
                'related_id'    => $related_id,
                'ip_address'    => qilingshop_security()->get_client_ip(),
                'created_at'    => $now,
            ]);
            if (!$log_id) {
                throw new Exception('Failed to insert withdraw log');
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            qilingshop_log('Add withdrawable balance failed: ' . $e->getMessage(), 'error', [
                'user_id' => $user_id,
                'amount'  => $amount,
            ]);
            return false;
        }

        $this->clear_user_cache($user_id);
        if (class_exists('QilingShop_Affiliate')) {
            QilingShop_Affiliate::instance()->refresh_invite_stats_cache($user_id);
        }

        do_action('qilingshop_after_add_withdrawable_balance', $user_id, $amount, $source);
        return true;
    }

    /**
     * 按指定积分来源扣减积分，并同步增加可提现余额（单事务）。
     *
     * 典型场景：仅允许“任务退款/任务奖励”等指定来源积分转换为现金余额。
     *
     * @param int    $user_id          用户 ID
     * @param float  $points_amount    需扣减积分（正数）
     * @param float  $cash_amount      需增加可提现余额（正数）
     * @param array  $allowed_sources  允许扣减的积分来源
     * @param string $points_source    积分扣减流水来源
     * @param string $cash_source      可提现余额入账流水来源
     * @param string $description      描述
     * @param int    $related_id       关联 ID
     * @return bool
     */
    public function convert_points_to_withdrawable_by_sources(
        $user_id,
        $points_amount,
        $cash_amount,
        $allowed_sources = [],
        $points_source = 'points_convert_points',
        $cash_source = 'points_convert_cash',
        $description = '',
        $related_id = 0
    ) {
        $user_id = absint($user_id);
        $points_amount = abs((float) $points_amount);
        $cash_amount = abs((float) $cash_amount);
        $allowed_sources = $this->normalize_source_filters($allowed_sources);

        if ($user_id <= 0 || $points_amount <= 0 || $cash_amount <= 0 || empty($allowed_sources)) {
            return false;
        }

        do_action('qilingshop_before_convert_points_to_withdrawable', $user_id, $points_amount, $cash_amount, $allowed_sources);

        $this->db->begin_transaction();
        try {
            $now = current_time('mysql');
            $locked_row = $this->lock_user_row($user_id);
            if (!$locked_row) {
                throw new Exception('User not found');
            }

            $current_balance = (float) $locked_row->points_balance;
            $current_consumed = (float) $locked_row->total_consumed;
            $current_withdrawable = (float) $locked_row->withdrawable_balance;

            $expire_result = $this->auto_expire_locked_assets($user_id, $current_balance, $now, true);
            $current_balance = (float) $expire_result['balance'];
            $this->reconcile_assets_with_balance($user_id, $current_balance, $now);

            if ($current_balance < $points_amount) {
                $this->commit_expire_sync_on_early_return($user_id, (float) $locked_row->points_balance, $expire_result, $now);
                return false;
            }

            $source_available = $this->sum_available_assets_by_sources_for_update($user_id, $now, $allowed_sources);
            if ($source_available < $points_amount) {
                $this->commit_expire_sync_on_early_return($user_id, (float) $locked_row->points_balance, $expire_result, $now);
                return false;
            }

            if (!$this->consume_available_assets_by_sources($user_id, $points_amount, $now, $allowed_sources)) {
                throw new Exception('Failed to consume points batches by sources');
            }

            $new_balance = $current_balance - $points_amount;
            $new_withdrawable = $current_withdrawable + $cash_amount;

            $updated = $this->db->update(
                'user_info',
                [
                    'points_balance'       => $new_balance,
                    'withdrawable_balance' => $new_withdrawable,
                    'total_consumed'       => $current_consumed + $points_amount,
                    'updated_at'           => $now,
                ],
                ['user_id' => $user_id]
            );
            if ($updated === false) {
                throw new Exception('Failed to update user balances');
            }

            $points_log_id = $this->insert_points_log(
                $user_id,
                $points_amount,
                $new_balance,
                'expense',
                $points_source,
                $description,
                $related_id
            );
            if (!$points_log_id) {
                throw new Exception('Failed to insert points deduction log');
            }

            $cash_description = $description;
            if ($cash_description === '') {
                $cash_description = __('积分转可提现余额', 'qilingshop');
            }
            $cash_log_id = $this->db->insert('points_log', [
                'user_id'       => $user_id,
                'amount'        => $cash_amount,
                'balance_after' => $new_withdrawable,
                'type'          => 'income',
                'source'        => sanitize_key((string) $cash_source),
                'description'   => sanitize_text_field($cash_description),
                'related_id'    => absint($related_id),
                'ip_address'    => qilingshop_security()->get_client_ip(),
                'created_at'    => $now,
            ]);
            if (!$cash_log_id) {
                throw new Exception('Failed to insert withdrawable log');
            }

            $this->db->commit();
            $this->clear_user_cache($user_id);

            if (($expire_result['available_expired'] + $expire_result['frozen_expired']) > 0) {
                $this->notify_points_expired($user_id, (float) $expire_result['available_expired'], (float) $expire_result['frozen_expired']);
            }

            do_action('qilingshop_after_convert_points_to_withdrawable', $user_id, $points_amount, $cash_amount, $allowed_sources, $new_balance, $new_withdrawable);
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            qilingshop_log('Convert points to withdrawable failed: ' . $e->getMessage(), 'error', [
                'user_id'       => $user_id,
                'points_amount' => $points_amount,
                'cash_amount'   => $cash_amount,
            ]);
            return false;
        }
    }

    /**
     * 签到
     *
     * @param int $user_id 用户 ID
     * @return array ['success' => bool, 'message' => string, 'points' => float]
     */
    public function checkin($user_id) {
        if (!$user_id) {
            return [
                'success' => false,
                'message' => __('请先登录', 'qilingshop'),
                'points'  => 0,
            ];
        }

        // 检查是否启用签到
        if (!get_option('qilingshop_checkin_enabled', true)) {
            return [
                'success' => false,
                'message' => __('签到功能已关闭', 'qilingshop'),
                'points'  => 0,
            ];
        }

        $today = current_time('Y-m-d');

        // 检查今日是否已签到
        $existing = $this->db->get_row('checkins', [
            'user_id'      => $user_id,
            'checkin_date' => $today,
        ]);

        if ($existing) {
            return [
                'success' => false,
                'message' => __('今日已签到，明天再来吧~', 'qilingshop'),
                'points'  => 0,
            ];
        }

        $ip_address = qilingshop_security()->get_client_ip();
        $risk_tokens = [];
        if (class_exists('QilingShop_Risk_Control')) {
            $risk_check = QilingShop_Risk_Control::instance()->begin_checkin($user_id, $ip_address);
            if (is_wp_error($risk_check)) {
                return [
                    'success' => false,
                    'message' => $risk_check->get_error_message(),
                    'points'  => 0,
                ];
            }
            $risk_tokens = is_array($risk_check) ? $risk_check : [];
        }

        // 计算连续签到天数
        $user_info = $this->get_user_info($user_id);
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $consecutive_days = 1;

        if ($user_info->last_checkin_date === $yesterday) {
            $consecutive_days = $user_info->consecutive_checkins + 1;
        }

        // 计算签到奖励
        $base_points = (float) get_option('qilingshop_checkin_base_points', 1);
        $consecutive_bonus = get_option('qilingshop_checkin_consecutive_bonus', true);
        $max_consecutive = (int) get_option('qilingshop_checkin_max_consecutive_bonus', 7);

        $bonus_multiplier = 1;
        if ($consecutive_bonus && $consecutive_days > 1) {
            $bonus_multiplier = min($consecutive_days, $max_consecutive);
        }

        $points_earned = $base_points * $bonus_multiplier;

        /**
         * 过滤签到奖励积分
         *
         * @param float $points_earned    奖励积分
         * @param int   $user_id          用户 ID
         * @param int   $consecutive_days 连续签到天数
         */
        $points_earned = apply_filters('qilingshop_checkin_bonus', $points_earned, $user_id, $consecutive_days);

        // 记录签到
        $checkin_id = $this->db->insert('checkins', [
            'user_id'           => $user_id,
            'points_earned'     => $points_earned,
            'consecutive_days'  => $consecutive_days,
            'checkin_date'      => $today,
            'ip_address'        => $ip_address,
            'created_at'        => current_time('mysql'),
        ]);
        if (!$checkin_id) {
            return [
                'success' => false,
                'message' => __('签到失败，请稍后重试', 'qilingshop'),
                'points'  => 0,
            ];
        }

        // 更新用户签到信息
        $updated = $this->db->update('user_info', [
            'last_checkin_date'    => $today,
            'consecutive_checkins' => $consecutive_days,
        ], ['user_id' => $user_id]);
        if ($updated === false) {
            $this->db->delete('checkins', ['id' => (int) $checkin_id]);
            return [
                'success' => false,
                'message' => __('签到失败，请稍后重试', 'qilingshop'),
                'points'  => 0,
            ];
        }

        // 发放积分
        $points_added = $this->add_points(
            $user_id,
            $points_earned,
            'checkin',
            sprintf(__('签到奖励（连续%d天）', 'qilingshop'), $consecutive_days)
        );
        if (!$points_added) {
            $this->db->delete('checkins', ['id' => (int) $checkin_id]);
            $rolled_back = $this->db->update('user_info', [
                'last_checkin_date'    => $user_info->last_checkin_date,
                'consecutive_checkins' => (int) $user_info->consecutive_checkins,
            ], ['user_id' => $user_id]);
            if ($rolled_back === false && function_exists('qilingshop_log')) {
                qilingshop_log('Checkin rollback failed after points award failure', 'error', [
                    'user_id'    => (int) $user_id,
                    'checkin_id' => (int) $checkin_id,
                ]);
            }
            return [
                'success' => false,
                'message' => __('签到奖励发放失败，请稍后重试', 'qilingshop'),
                'points'  => 0,
            ];
        }

        if (!empty($risk_tokens) && class_exists('QilingShop_Risk_Control')) {
            QilingShop_Risk_Control::instance()->commit_tokens($risk_tokens);
        }

        do_action('qilingshop_after_checkin', $user_id, $points_earned, $consecutive_days);

        return [
            'success'          => true,
            'message'          => sprintf(__('签到成功！获得 %s', 'qilingshop'), qilingshop_format_points($points_earned)),
            'points'           => $points_earned,
            'consecutive_days' => $consecutive_days,
        ];
    }

    /**
     * 检查今日是否已签到
     *
     * @param int $user_id 用户 ID
     * @return bool
     */
    public function has_checked_in_today($user_id) {
        $today = current_time('Y-m-d');

        $existing = $this->db->get_row('checkins', [
            'user_id'      => $user_id,
            'checkin_date' => $today,
        ]);

        return !empty($existing);
    }

    /**
     * 获取积分流水
     *
     * @param int   $user_id 用户 ID
     * @param array $args    查询参数
     * @return array
     */
    public function get_points_log($user_id, $args = []) {
        $defaults = [
            'limit'  => 20,
            'offset' => 0,
            'type'   => '',
            'source' => '',
        ];

        $args = wp_parse_args($args, $defaults);

        $where = ['user_id' => $user_id];

        if (!empty($args['type'])) {
            $where['type'] = $args['type'];
        }

        if (!empty($args['source'])) {
            $where['source'] = $args['source'];
        }

        return $this->db->get_results('points_log', [
            'where'   => $where,
            'orderby' => 'id',
            'order'   => 'DESC',
            'limit'   => $args['limit'],
            'offset'  => $args['offset'],
        ]);
    }

    /**
     * 获取积分流水总数
     *
     * @param int   $user_id 用户 ID
     * @param array $where   条件
     * @return int
     */
    public function get_points_log_count($user_id, $where = []) {
        $where['user_id'] = $user_id;
        return $this->db->count('points_log', $where);
    }

    /**
     * 获取用户邀请码
     *
     * @param int $user_id 用户 ID
     * @return string
     */
    public function get_invite_code($user_id) {
        $user_info = $this->get_user_info($user_id);
        return $user_info ? $user_info->invite_code : '';
    }

    /**
     * 获取邀请链接
     *
     * @param int $user_id 用户 ID
     * @return string
     */
    public function get_invite_url($user_id) {
        $code = $this->get_invite_code($user_id);
        if (empty($code)) {
            return '';
        }

        $base_url = apply_filters('qilingshop_invite_base_url', home_url());
        return add_query_arg('aff', $code, $base_url);
    }

    /**
     * 积分每日维护任务（到期提醒 + 自动过期）
     */
    public function handle_points_maintenance() {
        if (!$this->is_points_assets_table_ready()) {
            return;
        }

        if (!$this->is_points_validity_enabled()) {
            return;
        }

        $this->send_points_expire_reminders();
        $this->expire_due_points();
    }

    /**
     * 处理 VIP 过期
     */
    public function handle_vip_expiry() {
        global $wpdb;

        $table = $this->db->get_table('user_info');
        $today = current_time('Y-m-d');

        // 到期提醒
        $remind_enabled = (bool) get_option('qilingshop_vip_expire_remind_enabled', true);
        $remind_days = $this->parse_vip_remind_days(get_option('qilingshop_vip_expire_remind_days', '7,3'));
        if ($remind_enabled && !empty($remind_days) && class_exists('QilingShop_VIP')) {
            $vip = QilingShop_VIP::instance();
            foreach ($remind_days as $days) {
                $target_date = date('Y-m-d', strtotime($today . ' + ' . $days . ' days'));
                $users = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT user_id, vip_level, vip_expires FROM {$table}
                         WHERE vip_level > 0 AND vip_expires = %s",
                        $target_date
                    )
                );

                if (empty($users)) {
                    continue;
                }

                foreach ($users as $user) {
                    if ($vip->is_lifetime_expires($user->vip_expires)) {
                        continue;
                    }

                    $meta_key = 'qilingshop_vip_remind_' . $days;
                    $sent_mark = get_user_meta($user->user_id, $meta_key, true);
                    if ($sent_mark === $target_date) {
                        continue;
                    }

                    do_action('qilingshop_vip_expiring', $user->user_id, $user->vip_expires, (int) $days);
                    update_user_meta($user->user_id, $meta_key, $target_date);
                }
            }
        }

        // 宽限期到期后才重置 VIP 状态
        $grace_days = max(0, (int) get_option('qilingshop_vip_grace_days', 3));
        $cutoff = $grace_days > 0 ? date('Y-m-d', strtotime($today . ' - ' . $grace_days . ' days')) : $today;

        $expired_users = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT user_id, vip_level, vip_expires FROM {$table}
                 WHERE vip_level > 0 AND vip_expires < %s",
                $cutoff
            )
        );

        foreach ($expired_users as $user) {
            do_action('qilingshop_before_vip_expired', $user->user_id, $user->vip_level);

            $this->db->update('user_info', [
                'vip_level'   => 0,
                'vip_expires' => '1000-01-01',
            ], ['user_id' => $user->user_id]);

            do_action('qilingshop_vip_expired', $user->user_id, $user->vip_level, $user->vip_expires);
        }
    }

    /**
     * 锁定用户积分记录
     *
     * @param int $user_id
     * @return object|null
     */
    private function lock_user_row($user_id) {
        global $wpdb;
        $table = $this->db->get_table('user_info');
        $sql = $this->db->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d FOR UPDATE",
            $user_id
        );
        return $wpdb->get_row($sql);
    }

    /**
     * 插入积分流水
     *
     * @param int    $user_id
     * @param float  $amount
     * @param float  $balance_after
     * @param string $type income|expense
     * @param string $source
     * @param string $description
     * @param int    $related_id
     * @return int|false
     */
    private function insert_points_log($user_id, $amount, $balance_after, $type, $source, $description = '', $related_id = 0) {
        $source = sanitize_key((string) $source);
        $description = $this->sanitize_points_log_description($description);

        return $this->db->insert('points_log', [
            'user_id'       => $user_id,
            'amount'        => $amount,
            'balance_after' => $balance_after,
            'type'          => $type,
            'source'        => $source,
            'description'   => $description,
            'related_id'    => $related_id,
            'ip_address'    => qilingshop_security()->get_client_ip(),
            'created_at'    => current_time('mysql'),
        ]);
    }

    /**
     * 统一清洗积分流水描述，避免后台查看日志时渲染出恶意 HTML。
     *
     * @param string $description
     * @return string
     */
    private function sanitize_points_log_description($description) {
        return sanitize_textarea_field((string) $description);
    }

    /**
     * 提前返回时提交“自动过期”副作用，避免被回滚
     *
     * @param int   $user_id
     * @param float $old_balance
     * @param array $expire_result
     * @param string $now
     * @return void
     */
    private function commit_expire_sync_on_early_return($user_id, $old_balance, $expire_result, $now) {
        $new_balance = isset($expire_result['balance']) ? (float) $expire_result['balance'] : (float) $old_balance;

        if (abs($new_balance - (float) $old_balance) > 0.001) {
            $updated = $this->db->update(
                'user_info',
                ['points_balance' => $new_balance, 'updated_at' => $now],
                ['user_id' => $user_id]
            );
            if ($updated === false) {
                throw new Exception('Failed to sync expired points balance');
            }
        }

        $this->db->commit();
        $this->clear_user_cache($user_id);

        $expired_available = isset($expire_result['available_expired']) ? (float) $expire_result['available_expired'] : 0;
        $expired_frozen = isset($expire_result['frozen_expired']) ? (float) $expire_result['frozen_expired'] : 0;
        if (($expired_available + $expired_frozen) > 0) {
            $this->notify_points_expired($user_id, $expired_available, $expired_frozen);
        }
    }

    /**
     * 对账积分资产：以 user_info.points_balance 为准
     *
     * @param int    $user_id
     * @param float  $current_balance
     * @param string $now
     * @return void
     */
    private function reconcile_assets_with_balance($user_id, $current_balance, $now) {
        if (!$this->is_points_assets_table_ready()) {
            return;
        }

        global $wpdb;
        $summary = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(remaining_amount), 0) AS available_amount
             FROM {$this->points_assets_table}
             WHERE user_id = %d
             FOR UPDATE",
            $user_id
        ));

        $assets_available = $summary ? (float) $summary->available_amount : 0;
        $diff = round($current_balance - $assets_available, 2);

        if (abs($diff) < 0.01) {
            return;
        }

        if ($diff > 0) {
            // 历史余额补齐为永久积分批次
            $inserted = $this->db->insert('points_assets', [
                'user_id'          => $user_id,
                'total_amount'     => $diff,
                'remaining_amount' => $diff,
                'frozen_amount'    => 0,
                'source'           => 'legacy_adjust',
                'related_id'       => 0,
                'is_permanent'     => 1,
                'expires_at'       => null,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
            if (!$inserted) {
                throw new Exception('Failed to reconcile points assets');
            }
            return;
        }

        // 批次可用余额超出 user_info，裁剪到一致
        $need_trim = abs($diff);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, remaining_amount
             FROM {$this->points_assets_table}
             WHERE user_id = %d AND remaining_amount > 0
             ORDER BY CASE WHEN is_permanent = 1 THEN 1 ELSE 0 END ASC,
                      CASE WHEN expires_at IS NULL THEN '9999-12-31 23:59:59' ELSE expires_at END ASC,
                      id ASC
             FOR UPDATE",
            $user_id
        ));

        foreach ((array) $rows as $row) {
            if ($need_trim <= 0) {
                break;
            }
            $available = (float) $row->remaining_amount;
            $trim = min($available, $need_trim);
            $updated_remaining = $available - $trim;
            $updated = $this->db->query($this->db->prepare(
                "UPDATE {$this->points_assets_table}
                 SET remaining_amount = %f, updated_at = %s
                 WHERE id = %d",
                $updated_remaining,
                $now,
                $row->id
            ));
            if ($updated === false) {
                throw new Exception('Failed to trim points assets');
            }
            $need_trim -= $trim;
        }
    }

    /**
     * 自动过期（事务内，需先锁定 user_info）
     *
     * @param int    $user_id
     * @param float  $current_balance
     * @param string $now
     * @param bool   $write_log
     * @return array
     */
    private function auto_expire_locked_assets($user_id, $current_balance, $now, $write_log = true) {
        $result = [
            'balance'          => $current_balance,
            'available_expired'=> 0,
            'frozen_expired'   => 0,
        ];

        if (!$this->is_points_assets_table_ready() || !$this->is_points_validity_enabled()) {
            return $result;
        }

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, remaining_amount, frozen_amount
             FROM {$this->points_assets_table}
             WHERE user_id = %d
               AND is_permanent = 0
               AND expires_at IS NOT NULL
               AND expires_at <= %s
               AND (remaining_amount > 0 OR frozen_amount > 0)
             ORDER BY expires_at ASC, id ASC
             FOR UPDATE",
            $user_id,
            $now
        ));

        if (empty($rows)) {
            return $result;
        }

        $available_expired = 0;
        $frozen_expired = 0;

        foreach ($rows as $row) {
            $available_expired += (float) $row->remaining_amount;
            $frozen_expired += (float) $row->frozen_amount;

            $updated = $this->db->query($this->db->prepare(
                "UPDATE {$this->points_assets_table}
                 SET remaining_amount = 0, frozen_amount = 0, updated_at = %s
                 WHERE id = %d",
                $now,
                $row->id
            ));
            if ($updated === false) {
                throw new Exception('Failed to expire points asset');
            }
        }

        $new_balance = max(0, $current_balance - $available_expired);

        if ($write_log && ($available_expired > 0 || $frozen_expired > 0)) {
            $description = sprintf(
                __('积分到期失效（可用 %s，冻结 %s）', 'qilingshop'),
                qilingshop_format_points($available_expired),
                qilingshop_format_points($frozen_expired)
            );

            $log_amount = $available_expired > 0 ? $available_expired : $frozen_expired;
            $this->insert_points_log(
                $user_id,
                $log_amount,
                $new_balance,
                'expense',
                'points_expire',
                $description,
                0
            );
        }

        do_action('qilingshop_points_expired', $user_id, $available_expired, $frozen_expired, $new_balance);

        $result['balance'] = $new_balance;
        $result['available_expired'] = $available_expired;
        $result['frozen_expired'] = $frozen_expired;
        return $result;
    }

    /**
     * 创建积分批次
     *
     * @param int    $user_id
     * @param float  $amount
     * @param string $source
     * @param int    $related_id
     * @param string $now
     * @return bool
     */
    private function create_points_asset_batch($user_id, $amount, $source, $related_id, $now) {
        if (!$this->is_points_assets_table_ready()) {
            return true;
        }

        $expires_at = $this->calculate_points_expire_at($now);
        $is_permanent = empty($expires_at) ? 1 : 0;

        $inserted = $this->db->insert('points_assets', [
            'user_id'          => $user_id,
            'total_amount'     => $amount,
            'remaining_amount' => $amount,
            'frozen_amount'    => 0,
            'source'           => sanitize_key($source),
            'related_id'       => absint($related_id),
            'is_permanent'     => $is_permanent,
            'expires_at'       => $expires_at,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        return (bool) $inserted;
    }

    /**
     * 计算积分过期时间
     *
     * @param string $base_time
     * @return string|null
     */
    private function calculate_points_expire_at($base_time = '') {
        if (!$this->is_points_validity_enabled()) {
            return null;
        }

        $days = $this->get_points_validity_days();
        $base_ts = $base_time ? strtotime($base_time) : current_time('timestamp');
        if (!$base_ts) {
            $base_ts = current_time('timestamp');
        }

        $expire_ts = strtotime('+' . $days . ' days', $base_ts);
        $expire_at = date('Y-m-d 23:59:59', $expire_ts);

        return apply_filters('qilingshop_points_expire_at', $expire_at, $base_time, $days);
    }

    /**
     * 获取可用批次（事务内 FOR UPDATE）
     *
     * @param int    $user_id
     * @param string $now
     * @return array
     */
    private function get_available_batches_for_update($user_id, $now, $sources = []) {
        if (!$this->is_points_assets_table_ready()) {
            return [];
        }

        global $wpdb;
        $sources = $this->normalize_source_filters($sources);
        $source_sql = '';
        $params = [(int) $user_id];
        if (!empty($sources)) {
            $source_sql = ' AND source IN (' . implode(', ', array_fill(0, count($sources), '%s')) . ')';
            $params = array_merge($params, $sources);
        }

        if ($this->is_points_validity_enabled()) {
            $params[] = (string) $now;
            $sql = "SELECT id, remaining_amount
                    FROM {$this->points_assets_table}
                    WHERE user_id = %d
                      AND remaining_amount > 0
                      {$source_sql}
                      AND (is_permanent = 1 OR expires_at IS NULL OR expires_at > %s)
                    ORDER BY CASE WHEN is_permanent = 1 THEN 1 ELSE 0 END ASC,
                             CASE WHEN expires_at IS NULL THEN '9999-12-31 23:59:59' ELSE expires_at END ASC,
                             id ASC
                    FOR UPDATE";
            $sql = $wpdb->prepare($sql, $params);
        } else {
            $sql = "SELECT id, remaining_amount
                    FROM {$this->points_assets_table}
                    WHERE user_id = %d
                      AND remaining_amount > 0
                      {$source_sql}
                    ORDER BY CASE WHEN is_permanent = 1 THEN 1 ELSE 0 END ASC,
                             CASE WHEN expires_at IS NULL THEN '9999-12-31 23:59:59' ELSE expires_at END ASC,
                             id ASC
                    FOR UPDATE";
            $sql = $wpdb->prepare($sql, $params);
        }

        return (array) $wpdb->get_results($sql);
    }

    /**
     * 标准化来源过滤列表。
     *
     * @param array $sources 来源列表。
     * @return array
     */
    private function normalize_source_filters($sources) {
        $normalized = [];
        foreach ((array) $sources as $source) {
            $source = sanitize_key((string) $source);
            if ($source !== '') {
                $normalized[] = $source;
            }
        }
        return array_values(array_unique($normalized));
    }

    /**
     * 统计指定来源可用积分（事务内 FOR UPDATE）。
     *
     * @param int    $user_id 用户 ID。
     * @param string $now 当前时间。
     * @param array  $sources 来源列表。
     * @return float
     */
    private function sum_available_assets_by_sources_for_update($user_id, $now, $sources) {
        $rows = $this->get_available_batches_for_update($user_id, $now, $sources);
        $sum = 0.0;
        foreach ($rows as $row) {
            $sum += (float) $row->remaining_amount;
        }
        return round($sum, 2);
    }

    /**
     * 消耗可用积分批次
     *
     * @param int    $user_id
     * @param float  $amount
     * @param string $now
     * @return bool
     */
    private function consume_available_assets($user_id, $amount, $now) {
        if ($amount <= 0 || !$this->is_points_assets_table_ready()) {
            return true;
        }

        $rows = $this->get_available_batches_for_update($user_id, $now);
        $need = $amount;

        foreach ($rows as $row) {
            if ($need <= 0) {
                break;
            }

            $remaining = (float) $row->remaining_amount;
            if ($remaining <= 0) {
                continue;
            }

            $consume = min($remaining, $need);
            $new_remaining = $remaining - $consume;

            $updated = $this->db->query($this->db->prepare(
                "UPDATE {$this->points_assets_table}
                 SET remaining_amount = %f, updated_at = %s
                 WHERE id = %d",
                $new_remaining,
                $now,
                $row->id
            ));
            if ($updated === false) {
                throw new Exception('Failed to consume points batch');
            }

            $need -= $consume;
        }

        return $need <= 0.001;
    }

    /**
     * 按指定来源消耗可用积分批次。
     *
     * @param int    $user_id 用户 ID
     * @param float  $amount  扣减积分
     * @param string $now     当前时间
     * @param array  $sources 来源列表
     * @return bool
     */
    private function consume_available_assets_by_sources($user_id, $amount, $now, $sources) {
        if ($amount <= 0 || !$this->is_points_assets_table_ready()) {
            return true;
        }

        $sources = $this->normalize_source_filters($sources);
        if (empty($sources)) {
            return false;
        }

        $rows = $this->get_available_batches_for_update($user_id, $now, $sources);
        $need = $amount;

        foreach ($rows as $row) {
            if ($need <= 0) {
                break;
            }

            $remaining = (float) $row->remaining_amount;
            if ($remaining <= 0) {
                continue;
            }

            $consume = min($remaining, $need);
            $new_remaining = $remaining - $consume;

            $updated = $this->db->query($this->db->prepare(
                "UPDATE {$this->points_assets_table}
                 SET remaining_amount = %f, updated_at = %s
                 WHERE id = %d",
                $new_remaining,
                $now,
                $row->id
            ));
            if ($updated === false) {
                throw new Exception('Failed to consume points batch by source');
            }

            $need -= $consume;
        }

        return $need <= 0.001;
    }

    /**
     * 可用转冻结
     *
     * @param int    $user_id
     * @param float  $amount
     * @param string $now
     * @return bool
     */
    private function shift_available_to_frozen($user_id, $amount, $now) {
        if ($amount <= 0 || !$this->is_points_assets_table_ready()) {
            return true;
        }

        $rows = $this->get_available_batches_for_update($user_id, $now);
        $need = $amount;

        foreach ($rows as $row) {
            if ($need <= 0) {
                break;
            }

            $available = (float) $row->remaining_amount;
            if ($available <= 0) {
                continue;
            }

            $freeze = min($available, $need);
            $new_available = $available - $freeze;

            $updated = $this->db->query($this->db->prepare(
                "UPDATE {$this->points_assets_table}
                 SET remaining_amount = %f, frozen_amount = frozen_amount + %f, updated_at = %s
                 WHERE id = %d",
                $new_available,
                $freeze,
                $now,
                $row->id
            ));
            if ($updated === false) {
                throw new Exception('Failed to freeze points batch');
            }

            $need -= $freeze;
        }

        return $need <= 0.001;
    }

    /**
     * 获取冻结总额（事务内）
     *
     * @param int    $user_id
     * @param string $now
     * @return float
     */
    private function get_frozen_total_for_update($user_id, $now) {
        if (!$this->is_points_assets_table_ready()) {
            return 0;
        }

        global $wpdb;

        if ($this->is_points_validity_enabled()) {
            $sql = $wpdb->prepare(
                "SELECT COALESCE(SUM(frozen_amount), 0)
                 FROM {$this->points_assets_table}
                 WHERE user_id = %d
                   AND frozen_amount > 0
                   AND (is_permanent = 1 OR expires_at IS NULL OR expires_at > %s)
                 FOR UPDATE",
                $user_id,
                $now
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT COALESCE(SUM(frozen_amount), 0)
                 FROM {$this->points_assets_table}
                 WHERE user_id = %d
                   AND frozen_amount > 0
                 FOR UPDATE",
                $user_id
            );
        }

        return (float) $wpdb->get_var($sql);
    }

    /**
     * 冻结转可用
     *
     * @param int    $user_id
     * @param float  $amount
     * @param string $now
     * @return bool
     */
    private function shift_frozen_to_available($user_id, $amount, $now) {
        if ($amount <= 0 || !$this->is_points_assets_table_ready()) {
            return true;
        }

        global $wpdb;

        if ($this->is_points_validity_enabled()) {
            $sql = $wpdb->prepare(
                "SELECT id, frozen_amount
                 FROM {$this->points_assets_table}
                 WHERE user_id = %d
                   AND frozen_amount > 0
                   AND (is_permanent = 1 OR expires_at IS NULL OR expires_at > %s)
                 ORDER BY CASE WHEN is_permanent = 1 THEN 1 ELSE 0 END ASC,
                          CASE WHEN expires_at IS NULL THEN '9999-12-31 23:59:59' ELSE expires_at END ASC,
                          id ASC
                 FOR UPDATE",
                $user_id,
                $now
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT id, frozen_amount
                 FROM {$this->points_assets_table}
                 WHERE user_id = %d
                   AND frozen_amount > 0
                 ORDER BY CASE WHEN is_permanent = 1 THEN 1 ELSE 0 END ASC,
                          CASE WHEN expires_at IS NULL THEN '9999-12-31 23:59:59' ELSE expires_at END ASC,
                          id ASC
                 FOR UPDATE",
                $user_id
            );
        }

        $rows = (array) $wpdb->get_results($sql);
        $need = $amount;

        foreach ($rows as $row) {
            if ($need <= 0) {
                break;
            }

            $frozen = (float) $row->frozen_amount;
            if ($frozen <= 0) {
                continue;
            }

            $unfreeze = min($frozen, $need);
            $new_frozen = $frozen - $unfreeze;

            $updated = $this->db->query($this->db->prepare(
                "UPDATE {$this->points_assets_table}
                 SET frozen_amount = %f, remaining_amount = remaining_amount + %f, updated_at = %s
                 WHERE id = %d",
                $new_frozen,
                $unfreeze,
                $now,
                $row->id
            ));
            if ($updated === false) {
                throw new Exception('Failed to unfreeze points batch');
            }

            $need -= $unfreeze;
        }

        return $need <= 0.001;
    }

    /**
     * 发送积分到期提醒
     */
    private function send_points_expire_reminders() {
        if (!get_option('qilingshop_points_expire_remind_enabled', true)) {
            return;
        }

        $days_list = $this->parse_points_remind_days(get_option('qilingshop_points_expire_remind_days', '7,3,1'));
        if (empty($days_list)) {
            return;
        }

        global $wpdb;
        $today = current_time('Y-m-d');

        foreach ($days_list as $days) {
            $target_date = date('Y-m-d', strtotime($today . ' + ' . $days . ' days'));
            $start = $target_date . ' 00:00:00';
            $end = $target_date . ' 23:59:59';

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT user_id, COALESCE(SUM(remaining_amount), 0) AS amount, MIN(expires_at) AS expires_at
                 FROM {$this->points_assets_table}
                 WHERE is_permanent = 0
                   AND remaining_amount > 0
                   AND expires_at BETWEEN %s AND %s
                 GROUP BY user_id",
                $start,
                $end
            ));

            foreach ((array) $rows as $row) {
                $user_id = (int) $row->user_id;
                $amount = (float) $row->amount;
                if ($user_id <= 0 || $amount <= 0) {
                    continue;
                }

                $meta_key = 'qilingshop_points_remind_' . $days;
                $sent_mark = get_user_meta($user_id, $meta_key, true);
                if ($sent_mark === $target_date) {
                    continue;
                }

                do_action('qilingshop_points_expiring', $user_id, (string) $row->expires_at, $amount, (int) $days);
                $this->notify_points_expiring($user_id, $amount, $target_date, (int) $days);
                update_user_meta($user_id, $meta_key, $target_date);
            }
        }
    }

    /**
     * 处理到期积分
     */
    private function expire_due_points() {
        global $wpdb;
        $now = current_time('mysql');

        $batch_size = (int) apply_filters('qilingshop_points_expire_batch_size', 200);
        $batch_size = max(50, min(1000, $batch_size));
        $last_user_id = 0;

        while (true) {
            $user_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT user_id
                 FROM {$this->points_assets_table}
                 WHERE is_permanent = 0
                   AND expires_at IS NOT NULL
                   AND expires_at <= %s
                   AND (remaining_amount > 0 OR frozen_amount > 0)
                   AND user_id > %d
                 ORDER BY user_id ASC
                 LIMIT %d",
                $now,
                $last_user_id,
                $batch_size
            ));

            if (empty($user_ids)) {
                break;
            }

            foreach ($user_ids as $uid) {
                $user_id = absint($uid);
                if ($user_id <= 0) {
                    continue;
                }
                $last_user_id = max($last_user_id, $user_id);

                $this->db->begin_transaction();
                try {
                    $locked_row = $this->lock_user_row($user_id);
                    if (!$locked_row) {
                        $this->db->rollback();
                        continue;
                    }

                    $current_balance = (float) $locked_row->points_balance;
                    $expire_result = $this->auto_expire_locked_assets($user_id, $current_balance, $now, true);
                    $new_balance = (float) $expire_result['balance'];

                    if (abs($new_balance - $current_balance) > 0.001) {
                        $updated = $this->db->update(
                            'user_info',
                            ['points_balance' => $new_balance, 'updated_at' => $now],
                            ['user_id' => $user_id]
                        );
                        if ($updated === false) {
                            throw new Exception('Failed to update expired points balance');
                        }
                    }

                    $this->db->commit();
                    $this->clear_user_cache($user_id);

                    if (($expire_result['available_expired'] + $expire_result['frozen_expired']) > 0) {
                        $this->notify_points_expired($user_id, (float) $expire_result['available_expired'], (float) $expire_result['frozen_expired']);
                    }
                } catch (Exception $e) {
                    $this->db->rollback();
                    qilingshop_log('Expire points failed: ' . $e->getMessage(), 'error', [
                        'user_id' => $user_id,
                    ]);
                }
            }
        }
    }

    /**
     * 发送“即将过期”通知
     *
     * @param int    $user_id
     * @param float  $amount
     * @param string $expire_date
     * @param int    $days
     */
    private function notify_points_expiring($user_id, $amount, $expire_date, $days) {
        $title = __('积分即将过期', 'qilingshop');
        $content = sprintf(
            __('您有 %1$s 积分将在 %2$s 过期（剩余 %3$d 天），请尽快使用。', 'qilingshop'),
            qilingshop_format_points($amount),
            $expire_date,
            (int) $days
        );

        $this->send_site_notification($user_id, $title, $content, 'warning', 'qilingshop_points_expiring');
    }

    /**
     * 发送“已过期”通知
     *
     * @param int   $user_id
     * @param float $available_expired
     * @param float $frozen_expired
     */
    private function notify_points_expired($user_id, $available_expired, $frozen_expired = 0) {
        $total = $available_expired + $frozen_expired;
        if ($total <= 0) {
            return;
        }

        $title = __('积分已过期', 'qilingshop');
        $content = sprintf(
            __('本次过期：可用 %1$s，冻结 %2$s。', 'qilingshop'),
            qilingshop_format_points($available_expired),
            qilingshop_format_points($frozen_expired)
        );

        $this->send_site_notification($user_id, $title, $content, 'warning', 'qilingshop_points_expired');
    }

    /**
     * 发送站内通知（复用统一通知中心）
     *
     * @param int    $user_id
     * @param string $title
     * @param string $content
     * @param string $type
     * @param string $scene
     */
    private function send_site_notification($user_id, $title, $content, $type = 'info', $scene = '') {
        $payload = [
            'user_id' => (int) $user_id,
            'title'   => (string) $title,
            'content' => (string) $content,
            'type'    => (string) $type,
            'scene'   => sanitize_key((string) $scene),
            'link'    => $this->get_account_tab_url('qls-points'),
        ];

        do_action('qilingshop_send_notification', $payload);
    }

    /**
     * 获取个人中心标签页链接
     *
     * @param string $tab
     * @return string
     */
    private function get_account_tab_url($tab) {
        $tab = sanitize_key($tab);
        if ($tab === '') {
            return '';
        }

        if (function_exists('developer_starter_get_frontend_account_tab_url')) {
            return (string) developer_starter_get_frontend_account_tab_url($tab);
        }

        $pages = get_pages([
            'meta_key'   => '_wp_page_template',
            'meta_value' => 'templates/template-account.php',
        ]);
        if (empty($pages)) {
            return '';
        }

        return add_query_arg('tab', $tab, get_permalink($pages[0]->ID));
    }

    /**
     * 解析 VIP 到期提醒天数
     *
     * @param string $raw
     * @return array<int>
     */
    private function parse_vip_remind_days($raw) {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/[,\s]+/', $raw);
        $days = [];
        foreach ($parts as $part) {
            $value = absint($part);
            if ($value > 0) {
                $days[] = $value;
            }
        }

        $days = array_values(array_unique($days));
        rsort($days);
        return $days;
    }

    /**
     * 解析积分到期提醒天数
     *
     * @param string $raw
     * @return array<int>
     */
    private function parse_points_remind_days($raw) {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/[,\s]+/', $raw);
        $days = [];
        foreach ($parts as $part) {
            $value = absint($part);
            if ($value > 0) {
                $days[] = $value;
            }
        }

        $days = array_values(array_unique($days));
        rsort($days);
        return $days;
    }
}
