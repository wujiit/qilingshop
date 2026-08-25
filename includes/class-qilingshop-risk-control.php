<?php
/**
 * 风控规则服务
 *
 * 统一处理邀请/签到/助力的频率限制与冷却策略。
 *
 * @package QilingShop
 * @since   2.0.6
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Risk_Control {

    /**
     * @var QilingShop_Risk_Control|null
     */
    private static $instance = null;

    /**
     * 获取单例实例
     *
     * @return QilingShop_Risk_Control
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 是否启用统一风控
     *
     * @return bool
     */
    public function is_enabled() {
        return (bool) get_option('qilingshop_risk_control_enabled', true);
    }

    /**
     * 邀请注册风控预检查
     *
     * @param int    $inviter_id 邀请人
     * @param string $ip         客户端 IP
     * @return array|WP_Error
     */
    public function begin_invite_registration($inviter_id, $ip = '') {
        $tokens = [];
        if (!$this->is_enabled()) {
            return $tokens;
        }

        $inviter_id = (int) $inviter_id;
        $ip = $this->normalize_ip($ip);
        if ($inviter_id <= 0 || $ip === '') {
            return $tokens;
        }

        if (!(bool) get_option('qilingshop_invite_ip_limit', true)) {
            return $tokens;
        }

        $daily_limit = max(0, (int) get_option('qilingshop_risk_invite_ip_daily_limit', 20));
        $cooldown = max(0, (int) get_option('qilingshop_risk_invite_ip_cooldown', 30));
        $bucket = 'inviter:' . $inviter_id . '|ip:' . $ip;

        $counter_token = $this->check_counter_limit(
            'invite_ip_daily',
            $bucket,
            $daily_limit,
            DAY_IN_SECONDS,
            __('当前IP邀请过于频繁，请稍后再试', 'qilingshop')
        );
        if (is_wp_error($counter_token)) {
            return $counter_token;
        }
        if (!empty($counter_token)) {
            $tokens[] = $counter_token;
        }

        $cooldown_token = $this->check_cooldown(
            'invite_ip_cooldown',
            $bucket,
            $cooldown,
            __('当前IP邀请过于频繁，请稍后再试', 'qilingshop')
        );
        if (is_wp_error($cooldown_token)) {
            return $cooldown_token;
        }
        if (!empty($cooldown_token)) {
            $tokens[] = $cooldown_token;
        }

        return $tokens;
    }

    /**
     * 签到风控预检查
     *
     * @param int    $user_id 用户 ID
     * @param string $ip      客户端 IP
     * @return array|WP_Error
     */
    public function begin_checkin($user_id, $ip = '') {
        $tokens = [];
        if (!$this->is_enabled()) {
            return $tokens;
        }

        $user_id = (int) $user_id;
        $ip = $this->normalize_ip($ip);
        if ($user_id <= 0 || $ip === '') {
            return $tokens;
        }

        $daily_limit = max(0, (int) get_option('qilingshop_risk_checkin_ip_daily_limit', 200));
        $token = $this->check_counter_limit(
            'checkin_ip_daily',
            'ip:' . $ip,
            $daily_limit,
            DAY_IN_SECONDS,
            __('当前IP签到过于频繁，请稍后再试', 'qilingshop')
        );

        if (is_wp_error($token)) {
            return $token;
        }
        if (!empty($token)) {
            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * 助力发起风控预检查
     *
     * @param int    $user_id 用户 ID
     * @param string $ip      客户端 IP
     * @return array|WP_Error
     */
    public function begin_assist_create($user_id, $ip = '') {
        $tokens = [];
        if (!$this->is_enabled()) {
            return $tokens;
        }

        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return $tokens;
        }

        $cooldown = max(0, (int) get_option('qilingshop_risk_assist_create_user_cooldown', 15));
        $token = $this->check_cooldown(
            'assist_create_user_cooldown',
            'user:' . $user_id,
            $cooldown,
            __('操作过于频繁，请稍后再发起助力', 'qilingshop')
        );
        if (is_wp_error($token)) {
            return $token;
        }
        if (!empty($token)) {
            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * 助力帮砍风控预检查
     *
     * @param int    $user_id 用户 ID
     * @param string $ip      客户端 IP
     * @return array|WP_Error
     */
    public function begin_assist_help($user_id, $ip = '') {
        $tokens = [];
        if (!$this->is_enabled()) {
            return $tokens;
        }

        $user_id = (int) $user_id;
        $ip = $this->normalize_ip($ip);
        if ($user_id <= 0) {
            return $tokens;
        }

        $user_hour_limit = max(0, (int) get_option('qilingshop_risk_assist_help_user_hour_limit', 120));
        $ip_hour_limit = max(0, (int) get_option('qilingshop_risk_assist_help_ip_hour_limit', 500));
        $user_cooldown = max(0, (int) get_option('qilingshop_risk_assist_help_user_cooldown', 3));

        $user_hour_token = $this->check_counter_limit(
            'assist_help_user_hour',
            'user:' . $user_id,
            $user_hour_limit,
            HOUR_IN_SECONDS,
            __('操作过于频繁，请稍后再助力', 'qilingshop')
        );
        if (is_wp_error($user_hour_token)) {
            return $user_hour_token;
        }
        if (!empty($user_hour_token)) {
            $tokens[] = $user_hour_token;
        }

        if ($ip !== '') {
            $ip_hour_token = $this->check_counter_limit(
                'assist_help_ip_hour',
                'ip:' . $ip,
                $ip_hour_limit,
                HOUR_IN_SECONDS,
                __('当前IP助力过于频繁，请稍后再试', 'qilingshop')
            );
            if (is_wp_error($ip_hour_token)) {
                return $ip_hour_token;
            }
            if (!empty($ip_hour_token)) {
                $tokens[] = $ip_hour_token;
            }
        }

        $cooldown_token = $this->check_cooldown(
            'assist_help_user_cooldown',
            'user:' . $user_id,
            $user_cooldown,
            __('操作过于频繁，请稍后再助力', 'qilingshop')
        );
        if (is_wp_error($cooldown_token)) {
            return $cooldown_token;
        }
        if (!empty($cooldown_token)) {
            $tokens[] = $cooldown_token;
        }

        return $tokens;
    }

    /**
     * 提交风控计数
     *
     * @param array $tokens 预检查返回的 token
     * @return void
     */
    public function commit_tokens($tokens) {
        if (empty($tokens) || !is_array($tokens)) {
            return;
        }

        $now = current_time('timestamp');
        foreach ($tokens as $token) {
            if (!is_array($token) || empty($token['type']) || empty($token['key'])) {
                continue;
            }

            if ($token['type'] === 'counter') {
                $count = max(0, (int) ($token['count'] ?? 0)) + 1;
                $ttl = max(60, (int) ($token['ttl'] ?? 60));
                set_transient($token['key'], $count, $ttl);
                continue;
            }

            if ($token['type'] === 'cooldown') {
                $ttl = max(60, (int) ($token['ttl'] ?? 60));
                set_transient($token['key'], $now, $ttl);
            }
        }
    }

    /**
     * 校验计数型限制
     *
     * @param string $scene   场景
     * @param string $bucket  维度
     * @param int    $max     最大次数
     * @param int    $window  窗口秒数
     * @param string $message 错误提示
     * @return array|WP_Error
     */
    private function check_counter_limit($scene, $bucket, $max, $window, $message) {
        $max = (int) $max;
        if ($max <= 0 || $bucket === '') {
            return [];
        }

        $window = max(60, (int) $window);
        $now = current_time('timestamp');
        $slot = (int) floor($now / $window);
        $key = 'qilingshop_risk_counter_' . md5($scene . '|' . $bucket . '|' . $slot);
        $count = (int) get_transient($key);

        if ($count >= $max) {
            return new WP_Error('risk_limited', $message, [
                'scene' => $scene,
                'retry_after' => max(1, $window - ($now % $window)),
            ]);
        }

        return [
            'type'  => 'counter',
            'key'   => $key,
            'count' => $count,
            'ttl'   => max(60, ($window - ($now % $window)) + 5),
        ];
    }

    /**
     * 校验冷却限制
     *
     * @param string $scene    场景
     * @param string $bucket   维度
     * @param int    $cooldown 冷却秒数
     * @param string $message  错误提示
     * @return array|WP_Error
     */
    private function check_cooldown($scene, $bucket, $cooldown, $message) {
        $cooldown = (int) $cooldown;
        if ($cooldown <= 0 || $bucket === '') {
            return [];
        }

        $now = current_time('timestamp');
        $key = 'qilingshop_risk_cd_' . md5($scene . '|' . $bucket);
        $last = (int) get_transient($key);

        if ($last > 0 && ($now - $last) < $cooldown) {
            return new WP_Error('risk_cooldown', $message, [
                'scene' => $scene,
                'retry_after' => $cooldown - ($now - $last),
            ]);
        }

        return [
            'type' => 'cooldown',
            'key'  => $key,
            'ttl'  => max(60, $cooldown + 5),
        ];
    }

    /**
     * 统一处理 IP 字符串
     *
     * @param string $ip IP
     * @return string
     */
    private function normalize_ip($ip) {
        $ip = trim((string) $ip);
        if ($ip === '') {
            return '';
        }
        return sanitize_text_field($ip);
    }
}
