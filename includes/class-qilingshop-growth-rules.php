<?php
/**
 * 成长值来源规则执行器。
 *
 * @package QilingShop
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Growth_Rules {
    private static $instance = null;

    /**
     * @var QilingShop_Database
     */
    private $db;

    /**
     * @var bool|null
     */
    private $table_exists_cache = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->db = QilingShop_Database::instance();
        add_action('wp', [$this, 'maybe_reward_daily_visit'], 30);
        add_action('qilingshop_after_checkin', [$this, 'handle_checkin'], 20, 3);
        add_action('qilingshop_order_paid', [$this, 'handle_resource_order_paid'], 20, 2);
        add_action('qls_shop_order_paid', [$this, 'handle_shop_order_paid'], 20, 2);
        add_action('qilingshop_invite_registered', [$this, 'handle_invite_registered'], 20, 2);
        add_action('qilingshop_growth_task_completed', [$this, 'handle_task_completed'], 20, 3);
    }

    public function get_rules($args = []) {
        if (!$this->table_exists()) {
            return [];
        }

        $defaults = [
            'active_only' => false,
            'trigger_event' => '',
        ];
        $args = wp_parse_args($args, $defaults);
        $where = [];
        if (!empty($args['active_only'])) {
            $where['status'] = 1;
        }
        if (!empty($args['trigger_event'])) {
            $where['trigger_event'] = sanitize_key((string) $args['trigger_event']);
        }

        $rows = $this->db->get_results('growth_rules', [
            'where' => $where,
            'orderby' => 'sort_order',
            'order' => 'ASC',
        ]);

        return array_map([$this, 'prepare_rule'], $rows);
    }

    public function get_rule($rule_id) {
        $rule_id = absint($rule_id);
        if ($rule_id <= 0 || !$this->table_exists()) {
            return null;
        }

        $rule = $this->db->get_by_id('growth_rules', $rule_id);
        return $rule ? $this->prepare_rule($rule) : null;
    }

    public function handle_event($event, $user_id, $context = []) {
        if (!class_exists('QilingShop_Growth') || !QilingShop_Growth::instance()->is_enabled()) {
            return false;
        }

        $event = sanitize_key((string) $event);
        $user_id = absint($user_id);
        if ($event === '' || $user_id <= 0) {
            return false;
        }

        $applied = false;
        foreach ($this->get_rules(['active_only' => true, 'trigger_event' => $event]) as $rule) {
            if ($this->apply_growth($rule, $user_id, $context)) {
                $applied = true;
            }
        }

        return $applied;
    }

    public function calculate_growth($rule, $context = []) {
        $amount = (float) ($rule->growth_amount ?? 0);
        $type = sanitize_key((string) ($rule->growth_type ?? 'fixed'));

        if ($type === 'amount_rate') {
            $base_amount = isset($context['amount']) ? (float) $context['amount'] : 0;
            $amount = $base_amount * $amount;
        }

        $amount = round(max(0, $amount), 2);
        $max_single = isset($rule->max_single_amount) ? (float) $rule->max_single_amount : 0;
        if ($max_single > 0) {
            $amount = min($amount, $max_single);
        }

        return $amount;
    }

    public function apply_growth($rule, $user_id, $context = []) {
        $user_id = absint($user_id);
        if (!$rule || $user_id <= 0 || empty($rule->source)) {
            return false;
        }

        $source = sanitize_key((string) $rule->source);
        $lock_name = $this->build_lock_name('rule', [$user_id, $source]);
        if (!$this->acquire_named_lock($lock_name, 5)) {
            return false;
        }

        try {
            $source_id = isset($context['source_id']) ? absint($context['source_id']) : 0;
            if ($source_id > 0 && QilingShop_Growth::instance()->has_growth_log($user_id, $source, $source_id)) {
                return false;
            }

            $amount = $this->calculate_growth($rule, $context);
            if ($amount <= 0) {
                return false;
            }

            if ((string) ($rule->trigger_event ?? '') === 'task_completed' && class_exists('QilingShop_Growth_Benefits')) {
                $bonus = QilingShop_Growth_Benefits::instance()->get_task_growth_bonus(
                    $user_id,
                    sanitize_key((string) ($context['task_id'] ?? '')),
                    $amount
                );
                if ($bonus > 0) {
                    $amount = round($amount + $bonus, 2);
                }
            }

            $amount = $this->apply_period_limits($rule, $user_id, $amount);
            if ($amount <= 0) {
                return false;
            }

            $description = isset($context['description']) && $context['description'] !== ''
                ? sanitize_text_field((string) $context['description'])
                : sprintf(__('成长规则：%s', 'qilingshop'), $rule->rule_name);

            return QilingShop_Growth::instance()->add_growth($user_id, $amount, $source, $description, $source_id);
        } finally {
            $this->release_named_lock($lock_name);
        }
    }

    public function maybe_reward_daily_visit() {
        if (is_admin() || !is_user_logged_in()) {
            return;
        }

        $user_id = get_current_user_id();
        $today_key = (int) current_time('Ymd');
        $this->handle_event('daily_visit', $user_id, [
            'source_id' => $today_key,
            'description' => __('每日访问成长值', 'qilingshop'),
        ]);
    }

    public function handle_checkin($user_id, $points_earned, $consecutive_days) {
        $today_key = (int) current_time('Ymd');
        $this->handle_event('checkin', $user_id, [
            'source_id' => $today_key,
            'consecutive_days' => (int) $consecutive_days,
            'description' => __('签到成长值', 'qilingshop'),
        ]);
    }

    public function handle_resource_order_paid($order_id, $payment_method = '') {
        if (!class_exists('QilingShop_Order')) {
            return;
        }

        $order = QilingShop_Order::instance()->get($order_id);
        if (!$order || empty($order->user_id)) {
            return;
        }

        $event = ((string) ($order->order_type ?? '') === 'resource' || !empty($order->post_id)) ? 'resource_order_paid' : 'task_completed';
        if ($event !== 'resource_order_paid') {
            return;
        }

        $this->handle_event($event, (int) $order->user_id, [
            'source_id' => (int) $order->id,
            'amount' => (float) ($order->final_price ?? $order->price_rmb ?? 0),
            'order_no' => (string) ($order->order_no ?? ''),
            'description' => sprintf(__('资源订单成长值：%s', 'qilingshop'), (string) ($order->order_no ?? '')),
        ]);
    }

    public function handle_shop_order_paid($order_id, $payment_method = '') {
        if (!function_exists('qls_shop_order')) {
            return;
        }

        $order = qls_shop_order()->get($order_id, true);
        if (!$order || empty($order->user_id)) {
            return;
        }

        $this->handle_event('shop_order_paid', (int) $order->user_id, [
            'source_id' => (int) $order->id,
            'amount' => (float) ($order->final_amount ?? 0),
            'order_no' => (string) ($order->order_no ?? ''),
            'description' => sprintf(__('商城订单成长值：%s', 'qilingshop'), (string) ($order->order_no ?? '')),
        ]);
    }

    public function handle_review_submit($review_id, $user_id, $context = []) {
        $context = is_array($context) ? $context : [];
        $context['source_id'] = absint($review_id);
        $context['description'] = __('评价成长值', 'qilingshop');
        $this->handle_event('review_submit', $user_id, $context);

        if (!empty($context['has_image'])) {
            $context['description'] = __('带图评价成长值', 'qilingshop');
            $this->handle_event('review_with_image', $user_id, $context);
        }
    }

    public function handle_invite_registered($inviter_id, $invitee_id) {
        $this->handle_event('invite_registered', $inviter_id, [
            'source_id' => absint($invitee_id),
            'invitee_id' => absint($invitee_id),
            'description' => __('邀请注册成长值', 'qilingshop'),
        ]);
    }

    public function handle_task_completed($user_id, $task_id, $context = []) {
        $context = is_array($context) ? $context : [];
        $context['source_id'] = !empty($context['source_id']) ? absint($context['source_id']) : absint(crc32((string) $task_id));
        $context['task_id'] = sanitize_key((string) $task_id);
        $context['description'] = sprintf(__('任务成长值：%s', 'qilingshop'), sanitize_text_field((string) $task_id));
        $this->handle_event('task_completed', $user_id, $context);
    }

    private function apply_period_limits($rule, $user_id, $amount) {
        $daily_limit = (float) ($rule->max_daily_amount ?? 0);
        if ($daily_limit > 0) {
            $used = $this->sum_growth_for_period($user_id, (string) $rule->source, date('Y-m-d 00:00:00', current_time('timestamp')));
            $amount = min($amount, max(0, $daily_limit - $used));
        }

        $monthly_limit = (float) ($rule->max_monthly_amount ?? 0);
        if ($monthly_limit > 0) {
            $used = $this->sum_growth_for_period($user_id, (string) $rule->source, date('Y-m-01 00:00:00', current_time('timestamp')));
            $amount = min($amount, max(0, $monthly_limit - $used));
        }

        return round(max(0, $amount), 2);
    }

    private function sum_growth_for_period($user_id, $source, $start_at) {
        global $wpdb;
        $table = $this->db->get_table('growth_log');
        return (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE user_id = %d AND source = %s AND created_at >= %s",
            absint($user_id),
            sanitize_key($source),
            $start_at
        ));
    }

    private function prepare_rule($rule) {
        $config = [];
        if (!empty($rule->config)) {
            $decoded = json_decode((string) $rule->config, true);
            if (is_array($decoded)) {
                $config = $decoded;
            }
        }
        $rule->config = $config;
        return $rule;
    }

    private function table_exists() {
        global $wpdb;
        if ($this->table_exists_cache !== null) {
            return $this->table_exists_cache;
        }

        try {
            $table_name = $this->db->get_table('growth_rules');
        } catch (Exception $e) {
            return false;
        }
        $this->table_exists_cache = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) === $table_name;
        return $this->table_exists_cache;
    }

    private function build_lock_name($scope, $parts) {
        return 'qls_growth_rule_' . md5(sanitize_key((string) $scope) . '|' . implode('|', array_map('strval', (array) $parts)));
    }

    private function acquire_named_lock($lock_name, $timeout = 5) {
        global $wpdb;
        $lock_name = substr((string) $lock_name, 0, 64);
        if ($lock_name === '') {
            return true;
        }

        return (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, max(0, (int) $timeout))) === 1;
    }

    private function release_named_lock($lock_name) {
        global $wpdb;
        $lock_name = substr((string) $lock_name, 0, 64);
        if ($lock_name === '') {
            return;
        }

        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
    }
}
