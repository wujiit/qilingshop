<?php
/**
 * 后台设置类
 *
 * @package QilingShop
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Admin_Settings {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_init', [$this, 'handle_save']);
        add_action('wp_ajax_qilingshop_refresh_cache', [$this, 'ajax_refresh_cache']);
    }
    
    /**
     * AJAX刷新前端资源缓存
     */
    public function ajax_refresh_cache() {
        check_ajax_referer('qilingshop_refresh_cache', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('权限不足', 'qilingshop')]);
        }
        
        qilingshop_refresh_assets_cache();
        
        wp_send_json_success([
            'version' => qilingshop_get_assets_version()
        ]);
    }

    /**
     * 处理保存
     */
    public function handle_save() {
        // 处理充值奖励规则保存（独立处理，使用相同的nonce）
        if (isset($_POST['save_bonus_rule']) && !empty($_POST['bonus_min_amount'])) {
            if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'qilingshop_settings')) {
                return;
            }
            if (!current_user_can('manage_options')) {
                return;
            }
            
            $db = QilingShop_Database::instance();
            $bonus_data = [
                'min_amount'   => floatval($_POST['bonus_min_amount']),
                'max_amount'   => !empty($_POST['bonus_max_amount']) ? floatval($_POST['bonus_max_amount']) : null,
                'bonus_type'   => sanitize_text_field($_POST['bonus_type'] ?? 'fixed'),
                'bonus_value'  => floatval($_POST['bonus_value'] ?? 0),
                'description'  => sanitize_text_field($_POST['bonus_description'] ?? ''),
                'is_active'    => isset($_POST['bonus_is_active']) ? 1 : 0,
            ];
            
            if (!empty($_POST['bonus_rule_id'])) {
                $db->update('recharge_bonus', $bonus_data, ['id' => intval($_POST['bonus_rule_id'])]);
            } else {
                $bonus_data['created_at'] = current_time('mysql');
                $bonus_data['sort_order'] = 0;
                $db->insert('recharge_bonus', $bonus_data);
            }
            
            $this->redirect_settings_page('bonus_saved');
        }

        // 处理订单返积分规则保存
        if (isset($_POST['save_rebate_rule']) && isset($_POST['rebate_scope'])) {
            if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'qilingshop_settings')) {
                return;
            }
            if (!current_user_can('manage_options')) {
                return;
            }

            $scope = sanitize_text_field($_POST['rebate_scope']);
            if (!in_array($scope, ['resource', 'shop'], true)) {
                $scope = 'resource';
            }

            $category_id = 0;
            if ($scope === 'resource') {
                $category_id = intval($_POST['rebate_category_resource'] ?? 0);
            } else {
                $category_id = intval($_POST['rebate_category_shop'] ?? 0);
            }

            $rebate_data = [
                'scope'       => $scope,
                'category_id' => $category_id,
                'rate'        => floatval($_POST['rebate_rate'] ?? 0),
                'description' => sanitize_text_field($_POST['rebate_description'] ?? ''),
                'status'      => isset($_POST['rebate_status']) ? 1 : 0,
            ];

            if ($rebate_data['category_id'] <= 0 || $rebate_data['rate'] <= 0) {
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-error"><p>' . __('请填写有效的分类与返积分比例', 'qilingshop') . '</p></div>';
                });
                return;
            }

            $db = QilingShop_Database::instance();
            if (!empty($_POST['rebate_rule_id'])) {
                $db->update('order_rebate_rules', $rebate_data, ['id' => intval($_POST['rebate_rule_id'])]);
            } else {
                $rebate_data['created_at'] = current_time('mysql');
                $db->insert('order_rebate_rules', $rebate_data);
            }

            wp_cache_delete('qilingshop_rebate_rules_resource', 'qilingshop');
            wp_cache_delete('qilingshop_rebate_rules_shop', 'qilingshop');

            $this->redirect_settings_page('rebate_saved');
        }

        // 处理邀请阶梯奖励规则保存
        if (isset($_POST['save_invite_tier_rule'])) {
            if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'qilingshop_settings')) {
                return;
            }
            if (!current_user_can('manage_options')) {
                return;
            }

            $threshold = intval($_POST['invite_tier_threshold'] ?? 0);
            $bonus_points = floatval($_POST['invite_tier_bonus'] ?? 0);
            if ($threshold <= 0 || $bonus_points <= 0) {
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-error"><p>' . __('请填写有效的邀请人数和奖励积分', 'qilingshop') . '</p></div>';
                });
                return;
            }

            $tier_data = [
                'threshold' => $threshold,
                'bonus_points' => $bonus_points,
                'description' => sanitize_text_field($_POST['invite_tier_desc'] ?? ''),
                'status' => isset($_POST['invite_tier_status']) ? 1 : 0,
            ];

            $db = QilingShop_Database::instance();
            if (!empty($_POST['invite_tier_rule_id'])) {
                $db->update('invite_tier_rules', $tier_data, ['id' => intval($_POST['invite_tier_rule_id'])]);
            } else {
                $tier_data['created_at'] = current_time('mysql');
                $db->insert('invite_tier_rules', $tier_data);
            }

            $this->redirect_settings_page('invite_tier_saved');
        }
        
        // 处理主设置保存
        if (!isset($_POST['qilingshop_save_settings'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'qilingshop_settings')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        // 处理 VIP 落地页数量配置（仅在表单存在时更新）
        if (isset($_POST['vip_benefit_count'])) {
            $vip_benefit_count = max(1, min(absint($_POST['vip_benefit_count']), 12));
            update_option('qilingshop_vip_benefit_count', $vip_benefit_count);
        }

        if (isset($_POST['vip_compare_count'])) {
            $vip_compare_count = max(1, min(absint($_POST['vip_compare_count']), 20));
            update_option('qilingshop_vip_compare_count', $vip_compare_count);
        }

        // 所有文本/数字选项
        $text_options = [
            'points_name', 'points_ratio', 'currency_symbol', 'fixed_order_title',
            'register_code_obtain_url', 'register_code_obtain_link_text', 'register_code_obtain_tip_text',
            'checkin_base_points', 'checkin_max_consecutive_bonus',
            'points_validity_days', 'points_expire_remind_days',
            'register_bonus_amount',
            'recharge_min_amount', 'recharge_max_amount',
            'affiliate_level1_rate', 'affiliate_level2_rate',
            'invite_bonus_inviter', 'invite_bonus_invitee',
            'invite_tier_metric',
            'risk_invite_ip_daily_limit', 'risk_invite_ip_cooldown',
            'risk_checkin_ip_daily_limit',
            'risk_assist_create_user_cooldown',
            'risk_assist_help_user_hour_limit', 'risk_assist_help_ip_hour_limit',
            'risk_assist_help_user_cooldown',
            'order_points_rebate_rate',
            'birthday_coupon_id',
            'task_first_invite_points', 'task_first_resource_order_points', 'task_first_shop_paid_points',
            'task_check_key',
            'free_download_wait', 'paid_download_wait', 'guest_cookie_days',
            'trade_feed_interval_min', 'trade_feed_interval_max',
            'trade_feed_batch_size', 'trade_feed_virtual_ratio', 'trade_feed_cache_ttl',
            'author_commission_rate',
            'withdraw_min_amount', 'withdraw_fee_rate', // 提现设置
            'default_price', 'default_vip_discount', // 默认值
            'admin_email_recharge', 'admin_email_order',
            'alipay_app_id',
            'vip_icon',
            'wechat_mchid', 'wechat_appid', 'wechat_secret', 'wechat_key',
            'wechat_miniapp_appid', 'wechat_miniapp_mchid', 'wechat_miniapp_pay_type',
            'wechat_miniapp_key', 'wechat_miniapp_key_v3', 'wechat_miniapp_serial_no',
            'wechat_miniapp_public_key_id', 'wechat_miniapp_transfer_scene_id',
            'xhpay_default_type', 'xhpay_plugin_id',
            'xhpay_appid_wechat', 'xhpay_appsecret_wechat', 'xhpay_api_wechat',
            'xhpay_appid_alipay', 'xhpay_appsecret_alipay', 'xhpay_api_alipay',
            'epay_pid', 'epay_key', 'epay_api_url', 'epay_default_type',
            'paypal_client_id', 'paypal_client_secret', 'paypal_webhook_id', 'paypal_rate',
            'stripe_publishable_key', 'stripe_secret_key', 'stripe_webhook_secret', 'stripe_currency', 'stripe_rate',
            'payment_return_url',
            // 自定义设置
            'vip_name', 'guest_login_text',
            'benefit_1_icon', 'benefit_1_title', 'benefit_1_desc',
            'benefit_2_icon', 'benefit_2_title', 'benefit_2_desc',
            'benefit_3_icon', 'benefit_3_title', 'benefit_3_desc',
            'benefit_4_icon', 'benefit_4_title', 'benefit_4_desc',
            // 快捷功能自定义设置
            'quick_action_1_icon', 'quick_action_1_title', 'quick_action_1_desc', 'quick_action_1_link',
            'quick_action_2_icon', 'quick_action_2_title', 'quick_action_2_desc', 'quick_action_2_link',
            'quick_action_3_icon', 'quick_action_3_title', 'quick_action_3_desc', 'quick_action_3_link',
            'quick_action_4_icon', 'quick_action_4_title', 'quick_action_4_desc', 'quick_action_4_link',
            // VIP 落地页设置
            'vip_style',
            'vip_hero_title', 'vip_hero_subtitle', 'vip_hero_btn', 'vip_hero_bg',
            'vip_benefit_1_icon', 'vip_benefit_1_title', 'vip_benefit_1_desc',
            'vip_benefit_2_icon', 'vip_benefit_2_title', 'vip_benefit_2_desc',
            'vip_benefit_3_icon', 'vip_benefit_3_title', 'vip_benefit_3_desc',
            'vip_benefit_4_icon', 'vip_benefit_4_title', 'vip_benefit_4_desc',
            'vip_compare_1_name', 'vip_compare_1_free', 'vip_compare_1_vip',
            'vip_compare_2_name', 'vip_compare_2_free', 'vip_compare_2_vip',
            'vip_compare_3_name', 'vip_compare_3_free', 'vip_compare_3_vip',
            'vip_compare_4_name', 'vip_compare_4_free', 'vip_compare_4_vip',
            'vip_compare_5_name', 'vip_compare_5_free', 'vip_compare_5_vip',
            'vip_faq_1_q', 'vip_faq_1_a',
            'vip_faq_2_q', 'vip_faq_2_a',
            'vip_faq_3_q', 'vip_faq_3_a',
            'vip_faq_4_q', 'vip_faq_4_a',
            // VIP 到期提醒与宽限期
            'vip_expire_remind_days', 'vip_grace_days',
            // 购买框设置
            'buy_box_badge_text', 'buy_box_style', 'download_box_position',
        ];

        $checkbox_options = [
            'register_code_enabled',
            'checkin_enabled', 'checkin_consecutive_bonus',
            'register_bonus_enabled', 'register_bonus_stack_invite',
            'order_points_rebate_enabled',
            'birthday_coupon_enabled',
            'task_first_invite_enabled', 'task_first_resource_order_enabled', 'task_first_shop_paid_enabled',
            'task_payment_recovery_remind_enabled',
            'invite_tier_enabled',
            'risk_control_enabled',
            'affiliate_enabled', 'affiliate_vip_enabled', 'invite_ip_limit',
            'direct_pay_enabled', 'ajax_buy_enabled', 'guest_buy_enabled', 'guest_ip_verify',
            'trade_feed_enabled', 'trade_feed_virtual_enabled',
            'author_commission_enabled', 'withdraw_enabled', 'pancheck_enabled',
            'admin_email_recharge', 'admin_email_order',
            'alipay_enabled', 'alipay_f2fpay', 'alipay_h5',
            'wechat_enabled', 'wechat_jsapi', 'wechat_h5', 'wechat_miniapp_enabled',
            'xhpay_enabled', 'epay_enabled',
            'paypal_enabled', 'paypal_sandbox',
            'stripe_enabled',
            'show_sales_count',
            'resource_price_login_required',
            'disable_direct_download',
            'vip_diff_upgrade',
            'vip_expire_remind_enabled',
            'points_validity_enabled',
            'points_expire_remind_enabled',
        ];

        // textarea选项
        $textarea_options = [
            'alipay_private_key', 'alipay_public_key',
            'wechat_client_cert', 'wechat_client_key',
            'wechat_miniapp_client_cert', 'wechat_miniapp_client_key', 'wechat_miniapp_public_key_pem',
            'tips_download', 'tips_view', 'tips_download_pay', 'tips_recharge'
        ];

        $icon_options = ['vip_icon'];
        for ($i = 1; $i <= 4; $i++) {
            $icon_options[] = 'benefit_' . $i . '_icon';
            $icon_options[] = 'quick_action_' . $i . '_icon';
            $icon_options[] = 'vip_benefit_' . $i . '_icon';
        }

        // 处理文本选项
        foreach ($text_options as $key) {
            if (isset($_POST[$key])) {
                $raw_value = wp_unslash($_POST[$key]);
                if ($key === 'payment_return_url' && function_exists('qilingshop_validate_return_url')) {
                    $validated_return_url = qilingshop_validate_return_url($raw_value);
                    $value = $validated_return_url !== '' ? $validated_return_url : '';
                } elseif ($key === 'register_code_obtain_url') {
                    $value = esc_url_raw($raw_value);
                } else {
                    $value = in_array($key, $icon_options, true)
                        ? qilingshop_sanitize_icon_value($raw_value)
                        : sanitize_text_field($raw_value);
                }

                update_option('qilingshop_' . $key, $value);
            }
        }

        if (isset($_POST['account_style'])) {
            $account_style = sanitize_key((string) wp_unslash($_POST['account_style']));
            if (!in_array($account_style, ['fresh', 'business', 'coral', 'emerald'], true)) {
                $account_style = 'fresh';
            }
            update_option('qilingshop_account_style', $account_style);
        }

        // 邀请阶梯奖励统计口径
        if (isset($_POST['invite_tier_metric'])) {
            $metric = sanitize_key((string) wp_unslash($_POST['invite_tier_metric']));
            if (!in_array($metric, ['registration', 'first_paid'], true)) {
                $metric = 'registration';
            }
            update_option('qilingshop_invite_tier_metric', $metric);
        }

        if (isset($_POST['shop_refund_mode'])) {
            $refund_mode = sanitize_key((string) wp_unslash($_POST['shop_refund_mode']));
            if (!in_array($refund_mode, ['withdrawable_balance', 'gateway'], true)) {
                $refund_mode = 'withdrawable_balance';
            }
            update_option('qilingshop_shop_refund_mode', $refund_mode);
        }

        if (isset($_POST['shop_ticket_attachment_max_count'])) {
            $ticket_attachment_max_count = max(0, min(10, absint($_POST['shop_ticket_attachment_max_count'])));
            update_option('qilingshop_shop_ticket_attachment_max_count', $ticket_attachment_max_count);
        }

        if (isset($_POST['shop_ticket_attachment_max_size'])) {
            $ticket_attachment_max_size = (float) wp_unslash($_POST['shop_ticket_attachment_max_size']);
            $ticket_attachment_max_size = max(1, min(20, $ticket_attachment_max_size));
            update_option('qilingshop_shop_ticket_attachment_max_size', $ticket_attachment_max_size);
        }

        if (isset($_POST['has_shop_ticket_attachment_types'])) {
            $type_options = function_exists('qls_shop_ticket') ? qls_shop_ticket()->get_attachment_type_options() : [];
            $raw_types = isset($_POST['shop_ticket_attachment_types']) && is_array($_POST['shop_ticket_attachment_types'])
                ? wp_unslash($_POST['shop_ticket_attachment_types'])
                : [];
            $allowed_types = [];
            foreach ((array) $raw_types as $type_key) {
                $type_key = sanitize_key((string) $type_key);
                if (isset($type_options[$type_key])) {
                    $allowed_types[] = $type_key;
                }
            }

            if (empty($allowed_types)) {
                $allowed_types = ['jpg', 'png', 'webp'];
            }

            update_option('qilingshop_shop_ticket_attachment_types', array_values(array_unique($allowed_types)));
        }

        // 处理复选框 - 只处理页面上存在的复选框（通过has_xxx隐藏字段判断）
        foreach ($checkbox_options as $key) {
            // 只有当页面上有这个复选框时才处理它
            if (isset($_POST['has_' . $key])) {
                $value = isset($_POST[$key]) ? true : false;
                update_option('qilingshop_' . $key, $value);
            }
        }

        // 处理textarea
        foreach ($textarea_options as $key) {
            if (isset($_POST[$key])) {
                update_option('qilingshop_' . $key, sanitize_textarea_field($_POST[$key]));
            }
        }

        // 处理积分有效期配置（范围 + 格式）
        if (isset($_POST['points_validity_days'])) {
            $days = absint($_POST['points_validity_days']);
            $days = max(1, min($days, 3650));
            update_option('qilingshop_points_validity_days', $days);
        }

        if (isset($_POST['points_expire_remind_days'])) {
            $raw = sanitize_text_field(wp_unslash($_POST['points_expire_remind_days']));
            $parts = preg_split('/[,\s]+/', $raw);
            $days = [];
            foreach ($parts as $part) {
                $value = absint($part);
                if ($value > 0 && $value <= 3650) {
                    $days[] = $value;
                }
            }
            $days = array_values(array_unique($days));
            rsort($days);
            update_option('qilingshop_points_expire_remind_days', implode(',', $days));
        }

        // 处理风控规则配置
        $risk_numeric_fields = [
            'risk_invite_ip_daily_limit' => [0, 10000],
            'risk_invite_ip_cooldown' => [0, 86400],
            'risk_checkin_ip_daily_limit' => [0, 100000],
            'risk_assist_create_user_cooldown' => [0, 86400],
            'risk_assist_help_user_hour_limit' => [0, 100000],
            'risk_assist_help_ip_hour_limit' => [0, 100000],
            'risk_assist_help_user_cooldown' => [0, 86400],
        ];
        foreach ($risk_numeric_fields as $field => $range) {
            if (!isset($_POST[$field])) {
                continue;
            }
            $min = (int) $range[0];
            $max = (int) $range[1];
            $value = absint($_POST[$field]);
            $value = max($min, min($value, $max));
            update_option('qilingshop_' . $field, $value);
        }

        // 处理任务中心奖励积分配置
        $task_points_fields = [
            'task_first_invite_points',
            'task_first_resource_order_points',
            'task_first_shop_paid_points',
        ];
        foreach ($task_points_fields as $field) {
            if (!isset($_POST[$field])) {
                continue;
            }
            $value = max(0, min(absint($_POST[$field]), 1000000));
            update_option('qilingshop_' . $field, $value);
        }

        // 处理未支付订单召回通知配置
        if (isset($_POST['task_payment_recovery_delay_minutes'])) {
            $delay_minutes = absint($_POST['task_payment_recovery_delay_minutes']);
            $delay_minutes = max(5, min($delay_minutes, 10080));
            update_option('qilingshop_task_payment_recovery_delay_minutes', $delay_minutes);
        }

        if (isset($_POST['task_payment_recovery_lookback_days'])) {
            $lookback_days = absint($_POST['task_payment_recovery_lookback_days']);
            $lookback_days = max(1, min($lookback_days, 90));
            update_option('qilingshop_task_payment_recovery_lookback_days', $lookback_days);
        }

        if (isset($_POST['has_task_payment_recovery_notify_channels'])) {
            $raw_values = [];
            if (isset($_POST['task_payment_recovery_notify_channels'])) {
                $raw_values = is_array($_POST['task_payment_recovery_notify_channels'])
                    ? wp_unslash($_POST['task_payment_recovery_notify_channels'])
                    : [wp_unslash($_POST['task_payment_recovery_notify_channels'])];
            }

            $channels = [];
            foreach ($raw_values as $channel) {
                $channel = sanitize_key((string) $channel);
                if (in_array($channel, ['site', 'email'], true)) {
                    $channels[] = $channel;
                }
            }

            update_option('qilingshop_task_payment_recovery_notify_channels', array_values(array_unique($channels)));
        }

        // 处理交易播报配置
        $trade_feed_numeric_fields = [
            'trade_feed_interval_min' => [2, 60],
            'trade_feed_interval_max' => [2, 60],
            'trade_feed_batch_size' => [5, 50],
            'trade_feed_virtual_ratio' => [0, 100],
            'trade_feed_cache_ttl' => [10, 1800],
        ];
        foreach ($trade_feed_numeric_fields as $field => $range) {
            if (!isset($_POST[$field])) {
                continue;
            }
            $min = (int) $range[0];
            $max = (int) $range[1];
            $value = absint($_POST[$field]);
            $value = max($min, min($value, $max));
            update_option('qilingshop_' . $field, $value);
        }

        $trade_feed_min = (int) get_option('qilingshop_trade_feed_interval_min', 4);
        $trade_feed_max = (int) get_option('qilingshop_trade_feed_interval_max', 8);
        if ($trade_feed_max < $trade_feed_min) {
            update_option('qilingshop_trade_feed_interval_max', $trade_feed_min);
        }

        if (class_exists('QilingShop_Trade_Feed')) {
            QilingShop_Trade_Feed::instance()->bump_cache_version();
        }
        
        // 处理通知场景开关 + 管理员通知方式/通道
        $notify_scenes = $this->get_notify_scene_definitions();
        foreach ($notify_scenes as $scene => $scene_config) {
            $scene = sanitize_key((string) $scene);
            if ($scene === '') {
                continue;
            }

            $admin_supported = !isset($scene_config['admin']) || (bool) $scene_config['admin'];
            $user_supported  = !isset($scene_config['user']) || (bool) $scene_config['user'];
            $sms_supported   = $user_supported && !empty($scene_config['sms']);

            $admin_field = 'notify_' . $scene . '_admin_enabled';
            if ($admin_supported && isset($_POST['has_' . $admin_field])) {
                update_option('qilingshop_' . $admin_field, isset($_POST[$admin_field]));
            }

            $user_field = 'notify_' . $scene . '_user_enabled';
            if ($user_supported && isset($_POST['has_' . $user_field])) {
                update_option('qilingshop_' . $user_field, isset($_POST[$user_field]));
            }

            if ($sms_supported) {
                $sms_enabled_field = 'notify_' . $scene . '_sms_enabled';
                if (isset($_POST['has_' . $sms_enabled_field])) {
                    update_option('qilingshop_' . $sms_enabled_field, isset($_POST[$sms_enabled_field]));
                }

                $sms_template_field = 'notify_' . $scene . '_sms_template_code';
                if (isset($_POST[$sms_template_field])) {
                    update_option(
                        'qilingshop_' . $sms_template_field,
                        sanitize_text_field(wp_unslash($_POST[$sms_template_field]))
                    );
                }
            }

            if ($admin_supported) {
                $method_field = 'notify_' . $scene . '_method';
                if (isset($_POST[$method_field])) {
                    $mode = sanitize_text_field(wp_unslash($_POST[$method_field]));
                    if (!in_array($mode, ['none', 'email', 'push', 'both'], true)) {
                        $mode = 'none';
                    }
                    update_option('qilingshop_' . $method_field, $mode);
                }

                $channel_field = 'notify_' . $scene . '_push_channel';
                if (isset($_POST['has_' . $channel_field])) {
                    $raw_values = [];
                    if (isset($_POST[$channel_field])) {
                        $raw_values = is_array($_POST[$channel_field]) ? wp_unslash($_POST[$channel_field]) : [wp_unslash($_POST[$channel_field])];
                    }

                    $channels = [];
                    foreach ($raw_values as $channel_id) {
                        $clean_id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $channel_id);
                        if ($clean_id !== '') {
                            $channels[] = $clean_id;
                        }
                    }

                    update_option('qilingshop_' . $channel_field, array_values(array_unique($channels)));
                }
            }
        }

        // 处理数组 (post_types)
        if (isset($_POST['has_post_types'])) {
            $value = isset($_POST['post_types']) && is_array($_POST['post_types']) ? wp_unslash($_POST['post_types']) : ['post'];
            $value = qilingshop_normalize_resource_post_types($value, ['post']);
            update_option('qilingshop_post_types', $value);
        }

        $this->redirect_settings_page('saved');
    }

    /**
     * 保存完成后跳回设置页（PRG，避免刷新重复提交）。
     *
     * @param string $notice 提示标记。
     * @return void
     */
    private function redirect_settings_page($notice = 'saved') {
        $tab = 'general';
        if (isset($_POST['qilingshop_current_tab'])) {
            $tab = sanitize_key((string) wp_unslash($_POST['qilingshop_current_tab']));
        } elseif (isset($_GET['tab'])) {
            $tab = sanitize_key((string) wp_unslash($_GET['tab']));
        }

        if ($tab === '') {
            $tab = 'general';
        }

        $url = add_query_arg([
            'page'              => 'qilingshop-settings',
            'tab'               => $tab,
            'qilingshop_notice' => sanitize_key((string) $notice),
        ], admin_url('admin.php'));

        if (!headers_sent()) {
            wp_safe_redirect($url);
        } else {
            echo '<script>window.location.href=' . wp_json_encode($url) . ';</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . esc_url($url) . '" /></noscript>';
        }
        exit;
    }

    /**
     * 渲染设置页面
     */
    public function render() {
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
        
        // 处理充值奖励规则删除
        if (isset($_GET['delete_bonus']) && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'delete_bonus_rule')) {
            $db = QilingShop_Database::instance();
            $db->delete('recharge_bonus', ['id' => intval($_GET['delete_bonus'])]);
            echo '<div class="notice notice-success"><p>' . __('规则已删除', 'qilingshop') . '</p></div>';
        }
        if (isset($_GET['delete_rebate']) && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'delete_rebate_rule')) {
            $db = QilingShop_Database::instance();
            $db->delete('order_rebate_rules', ['id' => intval($_GET['delete_rebate'])]);
            wp_cache_delete('qilingshop_rebate_rules_resource', 'qilingshop');
            wp_cache_delete('qilingshop_rebate_rules_shop', 'qilingshop');
            echo '<div class="notice notice-success"><p>' . __('返积分规则已删除', 'qilingshop') . '</p></div>';
        }
        if (isset($_GET['delete_invite_tier']) && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'delete_invite_tier_rule')) {
            $db = QilingShop_Database::instance();
            $db->delete('invite_tier_rules', ['id' => intval($_GET['delete_invite_tier'])]);
            echo '<div class="notice notice-success"><p>' . __('邀请阶梯奖励规则已删除', 'qilingshop') . '</p></div>';
        }
        ?>
        <div class="wrap qilingshop-admin-page qilingshop-vr-page qilingshop-settings-page">
            <h1><?php _e('启灵商城设置', 'qilingshop'); ?></h1>
            <div class="notice notice-success inline">
                <p>
                    <strong><?php esc_html_e('正版授权', 'qilingshop'); ?></strong>
                    <?php esc_html_e('启灵商城已通过当前启灵主题授权验证，可以正常使用全部功能。', 'qilingshop'); ?>
                </p>
            </div>
            <?php
            $notice_key = isset($_GET['qilingshop_notice']) ? sanitize_key((string) wp_unslash($_GET['qilingshop_notice'])) : '';
            $notice_map = [
                'saved'             => __('设置已保存', 'qilingshop'),
                'bonus_saved'       => __('奖励规则已保存', 'qilingshop'),
                'rebate_saved'      => __('返积分规则已保存', 'qilingshop'),
                'invite_tier_saved' => __('邀请阶梯奖励规则已保存', 'qilingshop'),
            ];
            if (isset($notice_map[$notice_key])) :
            ?>
                <div class="qls-admin-message qls-admin-message-success"><p><?php echo esc_html($notice_map[$notice_key]); ?></p></div>
            <?php endif; ?>

            <nav class="nav-tab-wrapper qls-ui-tabs">
                <a href="?page=qilingshop-settings&tab=general" class="nav-tab <?php echo $tab === 'general' ? 'nav-tab-active' : ''; ?>"><?php _e('基础设置', 'qilingshop'); ?></a>
                <a href="?page=qilingshop-settings&tab=recharge" class="nav-tab <?php echo $tab === 'recharge' ? 'nav-tab-active' : ''; ?>"><?php _e('充值设置', 'qilingshop'); ?></a>
                <a href="?page=qilingshop-settings&tab=affiliate" class="nav-tab <?php echo $tab === 'affiliate' ? 'nav-tab-active' : ''; ?>"><?php _e('推广设置', 'qilingshop'); ?></a>
                <a href="?page=qilingshop-settings&tab=payment" class="nav-tab <?php echo $tab === 'payment' ? 'nav-tab-active' : ''; ?>"><?php _e('支付设置', 'qilingshop'); ?></a>
                <a href="?page=qilingshop-settings&tab=download" class="nav-tab <?php echo $tab === 'download' ? 'nav-tab-active' : ''; ?>"><?php _e('下载设置', 'qilingshop'); ?></a>
                <a href="?page=qilingshop-settings&tab=marketing" class="nav-tab <?php echo $tab === 'marketing' ? 'nav-tab-active' : ''; ?>"><?php _e('营销设置', 'qilingshop'); ?></a>
                <a href="?page=qilingshop-settings&tab=vip_page" class="nav-tab <?php echo $tab === 'vip_page' ? 'nav-tab-active' : ''; ?>"><?php _e('VIP 落地页', 'qilingshop'); ?></a>
                <a href="?page=qilingshop-settings&tab=custom" class="nav-tab <?php echo $tab === 'custom' ? 'nav-tab-active' : ''; ?>"><?php _e('自定义设置', 'qilingshop'); ?></a>
                <a href="?page=qilingshop-settings&tab=notify" class="nav-tab <?php echo $tab === 'notify' ? 'nav-tab-active' : ''; ?>"><?php _e('通知设置', 'qilingshop'); ?></a>
            </nav>

            <form method="post" class="qls-settings-form">
                <?php wp_nonce_field('qilingshop_settings'); ?>
                <input type="hidden" name="qilingshop_current_tab" value="<?php echo esc_attr($tab); ?>">

                <div class="qls-settings-tab-content qls-settings-tab-content--<?php echo esc_attr($tab); ?>">
                    <?php
                    switch ($tab) {
                        case 'recharge':
                            $this->render_recharge_tab();
                            break;
                        case 'affiliate':
                            $this->render_affiliate_tab();
                            break;
                        case 'payment':
                            $this->render_payment_tab();
                            break;
                        case 'download':
                            $this->render_download_tab();
                            break;
                        case 'marketing':
                            $this->render_marketing_tab();
                            break;
                        case 'vip_page':
                            $this->render_vip_page_tab();
                            break;
                        case 'custom':
                            $this->render_custom_tab();
                            break;
                        case 'notify':
                            $this->render_notify_tab();
                            break;
                        default:
                            $this->render_general_tab();
                    }
                    ?>
                </div>

                <p class="submit qls-settings-submit">
                    <button type="submit" name="qilingshop_save_settings" class="button button-primary"><?php _e('保存设置', 'qilingshop'); ?></button>
                </p>
            </form>
        </div>
        <?php
    }

    private function render_general_tab() {
        ?>
        <!-- 缓存刷新 -->
        <h2><?php _e('资源文件缓存', 'qilingshop'); ?></h2>
        <table class="form-table qls-ui-form-table">
            <tr>
                <th><?php _e('刷新前端缓存', 'qilingshop'); ?></th>
                <td>
                    <button type="button" id="qls-refresh-cache" class="button">
                        <?php _e('一键刷新前端资源文件缓存', 'qilingshop'); ?>
                    </button>
                    <span id="qls-cache-status" class="qls-cache-status"></span>
                    <p class="description">
                        <?php printf(
                            __('当前版本号：%s。点击刷新后，浏览器将重新加载最新的 CSS/JS 文件。', 'qilingshop'),
                            '<code>' . esc_html(qilingshop_get_assets_version()) . '</code>'
                        ); ?>
                    </p>
                </td>
            </tr>
        </table>
        
        <script>
        jQuery(document).ready(function($) {
            $('#qls-refresh-cache').on('click', function() {
                var btn = $(this);
                var status = $('#qls-cache-status');
                
                btn.prop('disabled', true).text('<?php _e('刷新中...', 'qilingshop'); ?>');
                status.hide();
                
                $.post(ajaxurl, {
                    action: 'qilingshop_refresh_cache',
                    nonce: '<?php echo wp_create_nonce('qilingshop_refresh_cache'); ?>'
                }, function(response) {
                    btn.prop('disabled', false).text('<?php _e('一键刷新前端资源文件缓存', 'qilingshop'); ?>');
                    if (response.success) {
                        status.html('<span class="qls-status-text-success">✓ <?php _e('缓存已刷新！新版本号：', 'qilingshop'); ?>' + response.data.version + '</span>').show();
                    } else {
                        status.html('<span class="qls-status-text-error">✗ <?php _e('刷新失败', 'qilingshop'); ?></span>').show();
                    }
                });
            });
        });
        </script>
        
        <h2><?php _e('基础设置', 'qilingshop'); ?></h2>
        <table class="form-table qls-ui-form-table">
            <tr>
                <th><?php _e('积分名称', 'qilingshop'); ?></th>
                <td><input type="text" name="points_name" value="<?php echo esc_attr(get_option('qilingshop_points_name', __('积分', 'qilingshop'))); ?>"></td>
            </tr>
            <tr>
                <th><?php _e('积分比例', 'qilingshop'); ?></th>
                <td><input type="number" name="points_ratio" value="<?php echo esc_attr(get_option('qilingshop_points_ratio', 10)); ?>"> <?php esc_html_e('= 1元', 'qilingshop'); ?></td>
            </tr>
            <tr>
                <th><?php _e('货币符号', 'qilingshop'); ?></th>
                <td><input type="text" name="currency_symbol" value="<?php echo esc_attr(get_option('qilingshop_currency_symbol', '¥')); ?>"></td>
            </tr>
            <tr>
                <th><?php _e('全站固定自定义订单标题', 'qilingshop'); ?></th>
                <td>
                    <input type="text" name="fixed_order_title" class="regular-text" value="<?php echo esc_attr(get_option('qilingshop_fixed_order_title', '')); ?>">
                    <p class="description"><?php _e('留空则使用默认标题（如：文章标题、积分充值）。设置后所有支付订单标题将统一使用此名称，可提升部分支付通道的标题兼容性。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('注册码注册', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_register_code_enabled" value="1">
                    <label>
                        <input type="checkbox" name="register_code_enabled" value="1" <?php checked(get_option('qilingshop_register_code_enabled', false)); ?>>
                        <?php _e('启用注册码注册验证', 'qilingshop'); ?>
                    </label>
                    <p class="description"><?php _e('开启后，用户注册时必须填写可用注册码（同时作用于启灵主题注册页与注册弹窗）。', 'qilingshop'); ?></p>
                    <p>
                        <label for="register-code-obtain-url"><?php _e('获取链接', 'qilingshop'); ?></label><br>
                        <input type="url" id="register-code-obtain-url" name="register_code_obtain_url" class="regular-text" value="<?php echo esc_attr(get_option('qilingshop_register_code_obtain_url', '')); ?>" placeholder="https://example.com/register-code">
                    </p>
                    <p>
                        <label for="register-code-obtain-link-text"><?php _e('链接文案', 'qilingshop'); ?></label><br>
                        <input type="text" id="register-code-obtain-link-text" name="register_code_obtain_link_text" class="regular-text" value="<?php echo esc_attr(get_option('qilingshop_register_code_obtain_link_text', __('注册码获取', 'qilingshop'))); ?>" placeholder="<?php echo esc_attr__('注册码获取', 'qilingshop'); ?>">
                    </p>
                    <p>
                        <label for="register-code-obtain-tip-text"><?php _e('提示文案', 'qilingshop'); ?></label><br>
                        <input type="text" id="register-code-obtain-tip-text" name="register_code_obtain_tip_text" class="regular-text" value="<?php echo esc_attr(get_option('qilingshop_register_code_obtain_tip_text', '')); ?>" placeholder="<?php echo esc_attr__('没有注册码？', 'qilingshop'); ?>">
                    </p>
                    <p class="description"><?php _e('填写后，启灵主题注册页和注册弹窗会在注册码输入框下方显示获取入口。留空链接则不显示。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('签到功能', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_checkin_enabled" value="1">
                    <label><input type="checkbox" name="checkin_enabled" value="1" <?php checked(get_option('qilingshop_checkin_enabled', true)); ?>> <?php _e('启用每日签到', 'qilingshop'); ?></label>
                </td>
            </tr>
            <tr>
                <th><?php _e('签到基础积分', 'qilingshop'); ?></th>
                <td><input type="number" name="checkin_base_points" value="<?php echo esc_attr(get_option('qilingshop_checkin_base_points', 1)); ?>"></td>
            </tr>
            <tr>
                <th><?php _e('连续签到奖励', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_checkin_consecutive_bonus" value="1">
                    <label><input type="checkbox" name="checkin_consecutive_bonus" value="1" <?php checked(get_option('qilingshop_checkin_consecutive_bonus', true)); ?>> <?php _e('连签天数倍增', 'qilingshop'); ?></label>
                </td>
            </tr>
            <tr>
                <th><?php _e('最大连签倍数', 'qilingshop'); ?></th>
                <td><input type="number" name="checkin_max_consecutive_bonus" value="<?php echo esc_attr(get_option('qilingshop_checkin_max_consecutive_bonus', 7)); ?>"></td>
            </tr>
            <tr>
                <th><?php _e('积分有效期', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_points_validity_enabled" value="1">
                    <label>
                        <input type="checkbox" name="points_validity_enabled" value="1" <?php checked(get_option('qilingshop_points_validity_enabled', false)); ?>>
                        <?php _e('启用积分有效期与自动过期', 'qilingshop'); ?>
                    </label>
                    <p class="description"><?php _e('关闭时积分永久有效，不触发过期。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('有效期天数', 'qilingshop'); ?></th>
                <td>
                    <input type="number" name="points_validity_days" value="<?php echo esc_attr(get_option('qilingshop_points_validity_days', 365)); ?>" min="1" max="3650" step="1">
                    <p class="description"><?php _e('仅对开启有效期后新增的积分生效。建议 30 ~ 365 天。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('到期提醒', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_points_expire_remind_enabled" value="1">
                    <label>
                        <input type="checkbox" name="points_expire_remind_enabled" value="1" <?php checked(get_option('qilingshop_points_expire_remind_enabled', true)); ?>>
                        <?php _e('开启积分到期提醒', 'qilingshop'); ?>
                    </label>
                    <p class="description"><?php _e('通过站内通知提醒用户即将过期的可用积分。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('提醒天数', 'qilingshop'); ?></th>
                <td>
                    <input type="text" name="points_expire_remind_days" value="<?php echo esc_attr(get_option('qilingshop_points_expire_remind_days', '7,3,1')); ?>" class="regular-text">
                    <p class="description"><?php _e('使用半角逗号分隔，例如 7,3,1 表示到期前 7/3/1 天提醒。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('支持的文章类型', 'qilingshop'); ?></th>
                <td>
                    <?php 
                    $post_types = qilingshop_normalize_resource_post_types(get_option('qilingshop_post_types', ['post']), ['post']);
                    $resource_post_type_choices = ['post', 'page'];
                    ?>
                    <input type="hidden" name="has_post_types" value="1">
                    <?php
                    foreach ($resource_post_type_choices as $type_name):
                        $type = get_post_type_object($type_name);
                        if (!$type) {
                            continue;
                        }
                    ?>
                    <label><input type="checkbox" name="post_types[]" value="<?php echo esc_attr($type->name); ?>" <?php checked(in_array($type->name, (array)$post_types, true)); ?>> <?php echo esc_html($type->label); ?></label><br>
                    <?php endforeach; ?>
                    <p class="description"><?php _e('虚拟资源仅支持 WordPress 文章和页面，不读取主题注册的服务、案例、团队、软件等内容类型。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('资源价格显示', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_resource_price_login_required" value="1">
                    <label>
                        <input type="checkbox" name="resource_price_login_required" value="1" <?php checked(get_option('qilingshop_resource_price_login_required', false)); ?>>
                        <?php _e('登录后显示虚拟资源价格', 'qilingshop'); ?>
                    </label>
                    <p class="description"><?php _e('开启后，未登录用户只能看到登录提示，登录后才显示该资源的积分、人民币和会员价格。', 'qilingshop'); ?></p>
                </td>
            </tr>
        </table>

        <hr class="qls-section-divider">

        <h2 class="qls-settings-section-title"><?php _e('任务中心外部触发', 'qilingshop'); ?></h2>
        <table class="form-table qls-ui-form-table">
            <?php
            $task_key = (string) get_option('qilingshop_task_check_key', '');
            $task_url = '';
            $task_url_plain = '';
            $task_plan = [];
            $recommended_minutes = 5;
            $recovery_channels = get_option('qilingshop_task_payment_recovery_notify_channels', ['site', 'email']);
            if (!is_array($recovery_channels)) {
                $recovery_channels = [$recovery_channels];
            }
            $recovery_channels = array_values(array_intersect(array_map('sanitize_key', $recovery_channels), ['site', 'email']));
            $recovery_delay_minutes = max(5, min(absint(get_option('qilingshop_task_payment_recovery_delay_minutes', 30)), 10080));
            $recovery_lookback_days = max(1, min(absint(get_option('qilingshop_task_payment_recovery_lookback_days', 7)), 90));
            if (class_exists('QilingShop_Task_Center')) {
                $task_center = QilingShop_Task_Center::instance();
                $task_url = $task_center->get_task_check_url();
                if ($task_url) {
                    $task_url_plain = add_query_arg('plain', 1, $task_url);
                }
                $task_plan = $task_center->get_external_task_plan();
                $recommended_minutes = (int) $task_center->get_recommended_check_interval_minutes();
            } elseif ($task_key !== '') {
                $task_url = add_query_arg(
                    array('qilingshop_task_check' => 1, 'key' => $task_key),
                    home_url('/')
                );
                $task_url_plain = add_query_arg('plain', 1, $task_url);
            }
            ?>
            <tr>
                <th><?php _e('触发密钥', 'qilingshop'); ?></th>
                <td>
                    <input type="text" name="task_check_key" id="task-check-key" class="regular-text"
                           value="<?php echo esc_attr($task_key); ?>">
                    <button type="button" class="button" id="task-check-generate"><?php _e('生成随机密钥', 'qilingshop'); ?></button>
                    <p class="description"><?php _e('外部监控调用时需携带该密钥', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('外部触发地址(JSON)', 'qilingshop'); ?></th>
                <td>
                    <div class="qls-inline-actions">
                        <input type="text" id="qilingshop-task-url-json" class="regular-text" readonly value="<?php echo esc_attr($task_url); ?>">
                        <button type="button" class="button qls-copy-btn" data-target="qilingshop-task-url-json"><?php _e('复制地址', 'qilingshop'); ?></button>
                    </div>
                    <p class="description"><?php _e('默认返回 JSON，可用于监控系统解析执行状态。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('外部触发地址(Plain)', 'qilingshop'); ?></th>
                <td>
                    <div class="qls-inline-actions">
                        <input type="text" id="qilingshop-task-url-plain" class="regular-text" readonly value="<?php echo esc_attr($task_url_plain); ?>">
                        <button type="button" class="button qls-copy-btn" data-target="qilingshop-task-url-plain"><?php _e('复制地址', 'qilingshop'); ?></button>
                    </div>
                    <p class="description"><?php _e('返回固定 ok 文本，适合仅检查 HTTP 200 的场景。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('监控建议', 'qilingshop'); ?></th>
                <td>
                    <p class="description">
                        <?php
                        printf(
                            __('统一入口模式：建议外部监控每 %d 分钟访问一次同一个触发地址；插件内部按任务粒度节流，不会每次都全量执行。', 'qilingshop'),
                            $recommended_minutes
                        );
                        ?>
                    </p>
                    <p class="description"><?php _e('推荐监控策略：超时 10~15 秒，失败重试 1 次（间隔 1 分钟），连续失败告警通知运维。', 'qilingshop'); ?></p>
                    <p class="description"><?php _e('推荐工具：Uptime Kuma（自建）、cron-job.org（免费）、宝塔计划任务（服务器端）、云监控 HTTP 探测。', 'qilingshop'); ?></p>
                    <p class="description"><?php _e('执行方式建议：24 小时持续执行；任务实际执行频率以“外部任务清单”的每项建议频率为准。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('订单召回通知', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_task_payment_recovery_remind_enabled" value="1">
                    <label>
                        <input type="checkbox" name="task_payment_recovery_remind_enabled" value="1" <?php checked(get_option('qilingshop_task_payment_recovery_remind_enabled', false)); ?>>
                        <?php _e('启用未支付订单召回通知任务（独立开关）', 'qilingshop'); ?>
                    </label>
                    <p class="description"><?php _e('关闭后仅禁用“未支付订单召回通知”任务，不影响补单对账、自动取消、团购检查等其他任务。', 'qilingshop'); ?></p>
                    <p class="description"><?php _e('召回仅针对登录用户订单；游客订单不参与召回通知。', 'qilingshop'); ?></p>
                    <div class="qls-settings-stack-10">
                        <label class="qls-settings-label-gap">
                            <input type="hidden" name="has_task_payment_recovery_notify_channels" value="1">
                            <input type="checkbox" name="task_payment_recovery_notify_channels[]" value="site" <?php checked(in_array('site', $recovery_channels, true)); ?>>
                            <?php _e('站内通知', 'qilingshop'); ?>
                        </label>
                        <label>
                            <input type="checkbox" name="task_payment_recovery_notify_channels[]" value="email" <?php checked(in_array('email', $recovery_channels, true)); ?>>
                            <?php _e('邮件通知', 'qilingshop'); ?>
                        </label>
                        <p class="description"><?php _e('通知方式支持多选；若两项都不选，则任务执行时不会发送通知。', 'qilingshop'); ?></p>
                    </div>
                    <div class="qls-settings-stack-10">
                        <label>
                            <?php _e('触发时延（分钟）：', 'qilingshop'); ?>
                            <input type="number" name="task_payment_recovery_delay_minutes" min="5" max="10080" step="1" value="<?php echo esc_attr($recovery_delay_minutes); ?>" class="small-text">
                        </label>
                        <p class="description"><?php _e('订单创建后超过该时间仍未支付，将进入召回扫描。建议 15~60 分钟。', 'qilingshop'); ?></p>
                    </div>
                    <div class="qls-settings-stack-10">
                        <label>
                            <?php _e('扫描回溯（天）：', 'qilingshop'); ?>
                            <input type="number" name="task_payment_recovery_lookback_days" min="1" max="90" step="1" value="<?php echo esc_attr($recovery_lookback_days); ?>" class="small-text">
                        </label>
                        <p class="description"><?php _e('仅扫描最近 N 天的待支付订单，降低历史数据扫描压力。', 'qilingshop'); ?></p>
                    </div>
                    <p class="description"><?php _e('同一订单仅发送一次召回通知（无论站内信/邮件如何组合）；历史已发送订单不会重复召回。', 'qilingshop'); ?></p>
                </td>
            </tr>
        </table>

        <h3 class="qls-settings-subtitle"><?php _e('外部任务清单（统一入口）', 'qilingshop'); ?></h3>
        <table class="wp-list-table qls-ui-table widefat striped qls-settings-task-table">
            <thead>
                <tr>
                    <th class="qls-settings-col-task"><?php _e('任务', 'qilingshop'); ?></th>
                    <th class="qls-settings-col-interval"><?php _e('建议频率', 'qilingshop'); ?></th>
                    <th><?php _e('说明', 'qilingshop'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($task_plan)): ?>
                    <?php foreach ($task_plan as $plan_row): ?>
                        <tr>
                            <td><?php echo esc_html($plan_row['task'] ?? ''); ?></td>
                            <td><?php echo esc_html($plan_row['interval'] ?? ''); ?></td>
                            <td><?php echo esc_html($plan_row['detail'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="3"><?php _e('暂无任务清单', 'qilingshop'); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <script>
        jQuery(function($){
            $('#task-check-generate').on('click', function(){
                var rand = '';
                for (var i = 0; i < 3; i++) {
                    rand += Math.random().toString(36).slice(2, 10);
                }
                $('#task-check-key').val(rand);
            });

            function qlsCopyText(targetId, btn) {
                var target = document.getElementById(targetId);
                if (!target) return false;
                var text = target.value || target.textContent || '';
                if (!text) return false;

                var originalText = btn && btn.textContent ? btn.textContent : '';
                var done = function () {
                    if (!btn) return;
                    btn.textContent = '✓';
                    setTimeout(function () { btn.textContent = originalText; }, 1500);
                };
                var fail = function () {
                    if (!btn) return;
                    btn.textContent = '×';
                    setTimeout(function () { btn.textContent = originalText; }, 1500);
                };

                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(done).catch(fail);
                    return false;
                }

                var temp = document.createElement('textarea');
                temp.value = text;
                document.body.appendChild(temp);
                temp.select();
                try {
                    document.execCommand('copy');
                    done();
                } catch (e) {
                    fail();
                }
                document.body.removeChild(temp);
                return false;
            }

            $('.qls-copy-btn[data-target]').on('click', function(){
                var targetId = $(this).data('target');
                qlsCopyText(targetId, this);
                return false;
            });
        });
        </script>
        <?php
    }

    private function render_marketing_tab() {
        ?>
        <h2><?php _e('订单返积分', 'qilingshop'); ?></h2>
        <table class="form-table qls-ui-form-table">
            <tr>
                <th><?php _e('启用返积分', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_order_points_rebate_enabled" value="1">
                    <label>
                        <input type="checkbox" name="order_points_rebate_enabled" value="1" <?php checked(get_option('qilingshop_order_points_rebate_enabled', false)); ?>>
                        <?php _e('订单完成后返积分', 'qilingshop'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th><?php _e('默认返积分比例', 'qilingshop'); ?></th>
                <td>
                    <input type="number" name="order_points_rebate_rate" step="0.01" min="0"
                           value="<?php echo esc_attr(get_option('qilingshop_order_points_rebate_rate', 0)); ?>"> %
                    <p class="description"><?php _e('按订单实付金额计算，分类规则可覆盖此比例', 'qilingshop'); ?></p>
                </td>
            </tr>
        </table>

        <?php
        $db = QilingShop_Database::instance();
        $rebate_rules = $db->get_results('order_rebate_rules', [
            'orderby' => 'id',
            'order'   => 'DESC',
            'limit'   => -1,
        ]);
        $edit_rebate = null;
        if (isset($_GET['edit_rebate'])) {
            $edit_rebate = $db->get_row('order_rebate_rules', ['id' => intval($_GET['edit_rebate'])]);
        }

        $resource_categories = get_terms([
            'taxonomy'   => 'category',
            'hide_empty' => false,
        ]);
        $shop_categories = function_exists('qls_category') ? qls_category()->get_flat_tree() : [];
        ?>

        <table class="wp-list-table qls-ui-table widefat fixed striped qls-settings-rules-table">
            <thead>
                <tr>
                    <th width="120"><?php _e('适用类型', 'qilingshop'); ?></th>
                    <th><?php _e('分类', 'qilingshop'); ?></th>
                    <th width="120"><?php _e('返积分比例', 'qilingshop'); ?></th>
                    <th width="80"><?php _e('状态', 'qilingshop'); ?></th>
                    <th width="100"><?php _e('操作', 'qilingshop'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rebate_rules) : foreach ($rebate_rules as $rule) : ?>
                    <tr>
                        <td><?php echo $rule->scope === 'shop' ? __('商城', 'qilingshop') : __('资源', 'qilingshop'); ?></td>
                        <td>
                            <?php
                            $category_name = '';
                            if ($rule->scope === 'shop') {
                                if (function_exists('qls_category') && $rule->category_id) {
                                    $cat = qls_category()->get((int) $rule->category_id);
                                    $category_name = $cat ? $cat->name : '';
                                }
                            } else {
                                $term = $rule->category_id ? get_term((int) $rule->category_id, 'category') : null;
                                $category_name = ($term && !is_wp_error($term)) ? $term->name : '';
                            }
                            echo esc_html($category_name ?: ('#' . (int) $rule->category_id));
                            ?>
                        </td>
                        <td><?php echo esc_html($rule->rate); ?>%</td>
                        <td><?php echo $rule->status ? __('启用', 'qilingshop') : __('停用', 'qilingshop'); ?></td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=qilingshop-settings&tab=marketing&edit_rebate=' . $rule->id); ?>"><?php _e('编辑', 'qilingshop'); ?></a> |
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=qilingshop-settings&tab=marketing&delete_rebate=' . $rule->id), 'delete_rebate_rule'); ?>" onclick="return confirm('<?php _e('确定删除？', 'qilingshop'); ?>')" class="qls-admin-link-danger"><?php _e('删除', 'qilingshop'); ?></a>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr>
                        <td colspan="5" class="qls-admin-empty-row"><?php _e('暂无规则', 'qilingshop'); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="qls-settings-form-panel">
            <h3 class="qls-settings-form-title"><?php echo $edit_rebate ? __('编辑返积分规则', 'qilingshop') : __('添加返积分规则', 'qilingshop'); ?></h3>
            <input type="hidden" name="rebate_rule_id" value="<?php echo $edit_rebate ? esc_attr($edit_rebate->id) : ''; ?>">

            <table class="form-table qls-ui-form-table">
                <tr>
                    <th><?php _e('适用类型', 'qilingshop'); ?></th>
                    <td>
                        <select name="rebate_scope" id="rebate-scope">
                            <option value="resource" <?php selected($edit_rebate ? $edit_rebate->scope : '', 'resource'); ?>><?php _e('资源', 'qilingshop'); ?></option>
                            <option value="shop" <?php selected($edit_rebate ? $edit_rebate->scope : '', 'shop'); ?>><?php _e('商城', 'qilingshop'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('分类', 'qilingshop'); ?></th>
                    <td>
                        <select name="rebate_category_resource" id="rebate-category-resource">
                            <?php if (!is_wp_error($resource_categories) && !empty($resource_categories)) : ?>
                                <?php foreach ($resource_categories as $cat) : ?>
                                    <option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected($edit_rebate && $edit_rebate->scope === 'resource' && (int) $edit_rebate->category_id === (int) $cat->term_id); ?>>
                                        <?php echo esc_html($cat->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="0"><?php _e('暂无分类', 'qilingshop'); ?></option>
                            <?php endif; ?>
                        </select>
                        <select name="rebate_category_shop" id="rebate-category-shop" class="qls-settings-hidden">
                            <?php if (!empty($shop_categories)) : ?>
                                <?php foreach ($shop_categories as $cat) : ?>
                                    <option value="<?php echo esc_attr($cat->id); ?>" <?php selected($edit_rebate && $edit_rebate->scope === 'shop' && (int) $edit_rebate->category_id === (int) $cat->id); ?>>
                                        <?php echo esc_html($cat->display_name ?? $cat->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="0"><?php _e('暂无商品分类', 'qilingshop'); ?></option>
                            <?php endif; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('返积分比例', 'qilingshop'); ?></th>
                    <td>
                        <input type="number" name="rebate_rate" step="0.01" min="0" value="<?php echo esc_attr($edit_rebate ? $edit_rebate->rate : ''); ?>"> %
                    </td>
                </tr>
                <tr>
                    <th><?php _e('说明', 'qilingshop'); ?></th>
                    <td>
                        <input type="text" name="rebate_description" value="<?php echo esc_attr($edit_rebate ? $edit_rebate->description : ''); ?>" class="regular-text" placeholder="<?php _e('可选', 'qilingshop'); ?>">
                    </td>
                </tr>
                <tr>
                    <th><?php _e('状态', 'qilingshop'); ?></th>
                    <td>
                        <label><input type="checkbox" name="rebate_status" value="1" <?php checked($edit_rebate ? $edit_rebate->status : 1); ?>> <?php _e('启用此规则', 'qilingshop'); ?></label>
                    </td>
                </tr>
            </table>
            <button type="submit" name="save_rebate_rule" class="button button-secondary"><?php echo $edit_rebate ? __('更新规则', 'qilingshop') : __('添加规则', 'qilingshop'); ?></button>
            <?php if ($edit_rebate): ?>
                <a href="<?php echo admin_url('admin.php?page=qilingshop-settings&tab=marketing'); ?>" class="button"><?php _e('取消编辑', 'qilingshop'); ?></a>
            <?php endif; ?>
        </div>

        <script>
        jQuery(function($){
            function toggleRebateCategory() {
                var scope = $('#rebate-scope').val();
                if (scope === 'shop') {
                    $('#rebate-category-resource').hide();
                    $('#rebate-category-shop').show();
                } else {
                    $('#rebate-category-resource').show();
                    $('#rebate-category-shop').hide();
                }
            }
            $('#rebate-scope').on('change', toggleRebateCategory);
            toggleRebateCategory();
        });
        </script>

        <hr class="qls-section-divider">

        <h2><?php _e('生日券设置', 'qilingshop'); ?></h2>
        <table class="form-table qls-ui-form-table">
            <tr>
                <th><?php _e('启用生日券', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_birthday_coupon_enabled" value="1">
                    <label>
                        <input type="checkbox" name="birthday_coupon_enabled" value="1" <?php checked(get_option('qilingshop_birthday_coupon_enabled', false)); ?>>
                        <?php _e('生日当天自动发放优惠券', 'qilingshop'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th><?php _e('优惠券编号', 'qilingshop'); ?></th>
                <td>
                    <input type="number" name="birthday_coupon_id" step="1" min="0"
                           value="<?php echo esc_attr(get_option('qilingshop_birthday_coupon_id', 0)); ?>">
                    <p class="description"><?php _e('填写优惠券编号，用于生日当天自动发放（需先在优惠券管理中创建）', 'qilingshop'); ?></p>
                    <p class="description">
                        <?php
                        printf(
                            __('可在%s查看优惠券编号后填写到这里。', 'qilingshop'),
                            '<a href="' . esc_url(admin_url('admin.php?page=qls-shop-marketing&tab=coupons')) . '">' . esc_html__('商城 -> 营销中心 -> 优惠券', 'qilingshop') . '</a>'
                        );
                        ?>
                    </p>
                </td>
            </tr>
        </table>

        <hr class="qls-section-divider">

        <h2><?php _e('任务中心新手任务', 'qilingshop'); ?></h2>
        <table class="form-table qls-ui-form-table">
            <tr>
                <th><?php _e('首次邀请成功', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_task_first_invite_enabled" value="1">
                    <label>
                        <input type="checkbox" name="task_first_invite_enabled" value="1" <?php checked(get_option('qilingshop_task_first_invite_enabled', false)); ?>>
                        <?php _e('邀请 1 位好友注册后可领取奖励', 'qilingshop'); ?>
                    </label>
                    <p class="qls-settings-points-row">
                        <?php _e('奖励积分：', 'qilingshop'); ?>
                        <input type="number" name="task_first_invite_points" step="1" min="0" value="<?php echo esc_attr((int) get_option('qilingshop_task_first_invite_points', 0)); ?>" class="small-text">
                    </p>
                </td>
            </tr>
            <tr>
                <th><?php _e('首次资源购买', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_task_first_resource_order_enabled" value="1">
                    <label>
                        <input type="checkbox" name="task_first_resource_order_enabled" value="1" <?php checked(get_option('qilingshop_task_first_resource_order_enabled', false)); ?>>
                        <?php _e('完成首次资源订单后可领取奖励', 'qilingshop'); ?>
                    </label>
                    <p class="qls-settings-points-row">
                        <?php _e('奖励积分：', 'qilingshop'); ?>
                        <input type="number" name="task_first_resource_order_points" step="1" min="0" value="<?php echo esc_attr((int) get_option('qilingshop_task_first_resource_order_points', 0)); ?>" class="small-text">
                    </p>
                </td>
            </tr>
            <tr>
                <th><?php _e('首次商城下单支付', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_task_first_shop_paid_enabled" value="1">
                    <label>
                        <input type="checkbox" name="task_first_shop_paid_enabled" value="1" <?php checked(get_option('qilingshop_task_first_shop_paid_enabled', false)); ?>>
                        <?php _e('完成首次商城支付后可领取奖励', 'qilingshop'); ?>
                    </label>
                    <p class="qls-settings-points-row">
                        <?php _e('奖励积分：', 'qilingshop'); ?>
                        <input type="number" name="task_first_shop_paid_points" step="1" min="0" value="<?php echo esc_attr((int) get_option('qilingshop_task_first_shop_paid_points', 0)); ?>" class="small-text">
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    private function render_recharge_tab() {
        ?>
        <table class="form-table qls-ui-form-table">
            <tr>
                <th><?php _e('最小充值金额', 'qilingshop'); ?></th>
                <td><input type="number" name="recharge_min_amount" step="0.01" value="<?php echo esc_attr(get_option('qilingshop_recharge_min_amount', 1)); ?>"> <?php esc_html_e('元', 'qilingshop'); ?></td>
            </tr>
            <tr>
                <th><?php _e('最大充值金额', 'qilingshop'); ?></th>
                <td><input type="number" name="recharge_max_amount" step="0.01" value="<?php echo esc_attr(get_option('qilingshop_recharge_max_amount', 10000)); ?>"> <?php esc_html_e('元', 'qilingshop'); ?> <small><?php esc_html_e('(0=不限)', 'qilingshop'); ?></small></td>
            </tr>
            <tr>
                <th><?php _e('注册奖励', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_register_bonus_enabled" value="1">
                    <label><input type="checkbox" name="register_bonus_enabled" value="1" <?php checked(get_option('qilingshop_register_bonus_enabled', false)); ?>> <?php _e('启用', 'qilingshop'); ?></label>
                </td>
            </tr>
            <tr>
                <th><?php _e('注册奖励积分', 'qilingshop'); ?></th>
                <td><input type="number" name="register_bonus_amount" value="<?php echo esc_attr(get_option('qilingshop_register_bonus_amount', 0)); ?>"></td>
            </tr>
            <tr>
                <th><?php _e('邀请奖励叠加', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_register_bonus_stack_invite" value="1">
                    <label><input type="checkbox" name="register_bonus_stack_invite" value="1" <?php checked(get_option('qilingshop_register_bonus_stack_invite', false)); ?>> <?php _e('与邀请奖励叠加', 'qilingshop'); ?></label>
                </td>
            </tr>
        </table>
        
        <hr class="qls-section-divider">
        
        <h2><?php _e('充值奖励规则', 'qilingshop'); ?></h2>
        <p class="description"><?php _e('用户充值时，根据充值金额自动赠送额外积分。这里的金额区间是"触发奖励的条件"，与上面的"最小/最大充值金额"（限制用户可充值的范围）不同。', 'qilingshop'); ?></p>
        
        <?php
        $db = QilingShop_Database::instance();
        $points_name = qilingshop_get_points_name();
        $bonus_rules = $db->get_results('recharge_bonus', [
            'orderby' => 'min_amount',
            'order'   => 'ASC',
            'limit'   => -1,
        ]);
        ?>
        
        <table class="wp-list-table qls-ui-table widefat fixed striped qls-settings-rules-table">
            <thead>
                <tr>
                    <th width="150"><?php _e('充值金额区间', 'qilingshop'); ?></th>
                    <th width="100"><?php _e('奖励类型', 'qilingshop'); ?></th>
                    <th width="120"><?php _e('奖励值', 'qilingshop'); ?></th>
                    <th><?php _e('说明', 'qilingshop'); ?></th>
                    <th width="60"><?php _e('状态', 'qilingshop'); ?></th>
                    <th width="100"><?php _e('操作', 'qilingshop'); ?></th>
                </tr>
            </thead>
            <tbody id="bonus-rules-list">
                <?php if ($bonus_rules): foreach ($bonus_rules as $rule): ?>
                <tr data-id="<?php echo $rule->id; ?>">
                    <td>
                        <?php 
                        if ($rule->max_amount) {
                            printf('¥%s - ¥%s', number_format($rule->min_amount), number_format($rule->max_amount));
                        } else {
                            printf(esc_html__('¥%s 以上', 'qilingshop'), esc_html(number_format($rule->min_amount)));
                        }
                        ?>
                    </td>
                    <td><?php echo $rule->bonus_type === 'fixed' ? __('固定值', 'qilingshop') : __('百分比', 'qilingshop'); ?></td>
                    <td>
                        <?php 
                        $ratio = qilingshop_get_points_ratio();
                        if ($rule->bonus_type === 'fixed') {
                            echo number_format($rule->bonus_value * $ratio) . ' ' . $points_name;
                        } else {
                            echo $rule->bonus_value . '%';
                        }
                        ?>
                    </td>
                    <td><?php echo esc_html($rule->description ?: '-'); ?></td>
                    <td>
                        <span class="<?php echo $rule->is_active ? 'qls-status-text-success' : 'qls-status-text-muted'; ?>">
                            <?php echo $rule->is_active ? __('启用', 'qilingshop') : __('禁用', 'qilingshop'); ?>
                        </span>
                    </td>
                    <td>
                        <a href="<?php echo admin_url('admin.php?page=qilingshop-settings&tab=recharge&edit_bonus=' . $rule->id); ?>"><?php _e('编辑', 'qilingshop'); ?></a> |
                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=qilingshop-settings&tab=recharge&delete_bonus=' . $rule->id), 'delete_bonus_rule'); ?>" onclick="return confirm('<?php _e('确定删除？', 'qilingshop'); ?>')" class="qls-admin-link-danger"><?php _e('删除', 'qilingshop'); ?></a>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="6" class="qls-settings-empty-muted"><?php _e('暂无奖励规则，请在下方添加', 'qilingshop'); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <?php
        // 获取编辑的规则
        $edit_bonus = null;
        if (isset($_GET['edit_bonus'])) {
            $edit_bonus = $db->get_row('recharge_bonus', ['id' => intval($_GET['edit_bonus'])]);
        }
        ?>
        
        <div class="qls-settings-form-panel qls-settings-form-panel-soft">
            <h3 class="qls-settings-form-title"><?php echo $edit_bonus ? __('编辑奖励规则', 'qilingshop') : __('添加奖励规则', 'qilingshop'); ?></h3>
            
            <input type="hidden" name="bonus_rule_id" value="<?php echo $edit_bonus ? $edit_bonus->id : ''; ?>">
            
            <table class="form-table qls-ui-form-table">
                <tr>
                    <th><?php _e('最低充值金额', 'qilingshop'); ?></th>
                    <td>
                        <input type="number" name="bonus_min_amount" step="0.01" min="0" value="<?php echo $edit_bonus ? esc_attr($edit_bonus->min_amount) : ''; ?>" class="qls-settings-input-120"> <?php esc_html_e('元', 'qilingshop'); ?>
                        <p class="description"><?php _e('充值达到此金额时触发奖励', 'qilingshop'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('最高充值金额', 'qilingshop'); ?></th>
                    <td>
                        <input type="number" name="bonus_max_amount" step="0.01" min="0" value="<?php echo $edit_bonus && $edit_bonus->max_amount ? esc_attr($edit_bonus->max_amount) : ''; ?>" class="qls-settings-input-120"> <?php esc_html_e('元', 'qilingshop'); ?>
                        <p class="description"><?php _e('留空表示无上限，即"大于等于最低金额"均适用', 'qilingshop'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('奖励类型', 'qilingshop'); ?></th>
                    <td>
                        <select name="bonus_type">
                            <option value="fixed" <?php selected($edit_bonus ? $edit_bonus->bonus_type : '', 'fixed'); ?>><?php _e('固定积分', 'qilingshop'); ?></option>
                            <option value="percent" <?php selected($edit_bonus ? $edit_bonus->bonus_type : '', 'percent'); ?>><?php _e('按充值金额百分比', 'qilingshop'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('奖励值', 'qilingshop'); ?></th>
                    <td>
                        <input type="number" name="bonus_value" step="0.01" min="0" value="<?php echo $edit_bonus ? esc_attr($edit_bonus->bonus_value) : ''; ?>" class="qls-settings-input-120">
                        <p class="description"><?php printf(__('固定积分填元数(如10表示10元换算的积分)，百分比填数字(如10表示10%%)', 'qilingshop')); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('说明文字', 'qilingshop'); ?></th>
                    <td>
                        <input type="text" name="bonus_description" value="<?php echo $edit_bonus ? esc_attr($edit_bonus->description) : ''; ?>" class="qls-settings-input-300" placeholder="<?php _e('例如：充100送10', 'qilingshop'); ?>">
                    </td>
                </tr>
                <tr>
                    <th><?php _e('启用', 'qilingshop'); ?></th>
                    <td>
                        <label><input type="checkbox" name="bonus_is_active" value="1" <?php checked($edit_bonus ? $edit_bonus->is_active : 1); ?>> <?php _e('启用此规则', 'qilingshop'); ?></label>
                    </td>
                </tr>
            </table>
            
            <p>
                <button type="submit" name="save_bonus_rule" class="button button-secondary"><?php echo $edit_bonus ? __('更新规则', 'qilingshop') : __('添加规则', 'qilingshop'); ?></button>
                <?php if ($edit_bonus): ?>
                <a href="<?php echo admin_url('admin.php?page=qilingshop-settings&tab=recharge'); ?>" class="button"><?php _e('取消编辑', 'qilingshop'); ?></a>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    private function render_affiliate_tab() {
        ?>
        <table class="form-table qls-ui-form-table">
            <?php $invite_tier_metric = sanitize_key((string) get_option('qilingshop_invite_tier_metric', 'registration')); ?>
            <?php if (!in_array($invite_tier_metric, ['registration', 'first_paid'], true)) { $invite_tier_metric = 'registration'; } ?>
            <tr>
                <th><?php _e('推广系统', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_affiliate_enabled" value="1">
                    <label><input type="checkbox" name="affiliate_enabled" value="1" <?php checked(get_option('qilingshop_affiliate_enabled', true)); ?>> <?php _e('启用', 'qilingshop'); ?></label>
                </td>
            </tr>
            <tr>
                <th><?php _e('一级提成比例', 'qilingshop'); ?></th>
                <td><input type="number" name="affiliate_level1_rate" value="<?php echo esc_attr(get_option('qilingshop_affiliate_level1_rate', 10)); ?>"> %</td>
            </tr>
            <tr>
                <th><?php _e('二级提成比例', 'qilingshop'); ?></th>
                <td><input type="number" name="affiliate_level2_rate" value="<?php echo esc_attr(get_option('qilingshop_affiliate_level2_rate', 5)); ?>"> %</td>
            </tr>
            <tr>
                <th><?php _e('VIP购买计提成', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_affiliate_vip_enabled" value="1">
                    <label><input type="checkbox" name="affiliate_vip_enabled" value="1" <?php checked(get_option('qilingshop_affiliate_vip_enabled', true)); ?>> <?php _e('启用', 'qilingshop'); ?></label>
                </td>
            </tr>
            <tr>
                <th><?php _e('邀请人奖励积分', 'qilingshop'); ?></th>
                <td><input type="number" name="invite_bonus_inviter" value="<?php echo esc_attr(get_option('qilingshop_invite_bonus_inviter', 5)); ?>"></td>
            </tr>
            <tr>
                <th><?php _e('被邀请人奖励积分', 'qilingshop'); ?></th>
                <td><input type="number" name="invite_bonus_invitee" value="<?php echo esc_attr(get_option('qilingshop_invite_bonus_invitee', 5)); ?>"></td>
            </tr>
            <tr>
                <th><?php _e('同IP限制', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_invite_ip_limit" value="1">
                    <label><input type="checkbox" name="invite_ip_limit" value="1" <?php checked(get_option('qilingshop_invite_ip_limit', true)); ?>> <?php _e('启用邀请IP风控（按阈值，不是一刀切）', 'qilingshop'); ?></label>
                </td>
            </tr>
            <tr>
                <th><?php _e('统一风控开关', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_risk_control_enabled" value="1">
                    <label><input type="checkbox" name="risk_control_enabled" value="1" <?php checked(get_option('qilingshop_risk_control_enabled', true)); ?>> <?php _e('启用营销行为风控', 'qilingshop'); ?></label>
                    <p class="description"><?php _e('关闭后将只保留旧版同IP邀请一次逻辑（若上方同IP限制已启用）。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('邀请同IP每日上限', 'qilingshop'); ?></th>
                <td>
                    <input type="number" name="risk_invite_ip_daily_limit" min="0" value="<?php echo esc_attr(get_option('qilingshop_risk_invite_ip_daily_limit', 20)); ?>">
                    <p class="description"><?php _e('同一邀请人 + 同一IP 每日最多记入邀请次数，0表示不限制。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('邀请同IP冷却(秒)', 'qilingshop'); ?></th>
                <td>
                    <input type="number" name="risk_invite_ip_cooldown" min="0" value="<?php echo esc_attr(get_option('qilingshop_risk_invite_ip_cooldown', 30)); ?>">
                    <p class="description"><?php _e('同一邀请人 + 同一IP 两次邀请之间最小间隔，0表示不限制。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('签到同IP每日上限', 'qilingshop'); ?></th>
                <td>
                    <input type="number" name="risk_checkin_ip_daily_limit" min="0" value="<?php echo esc_attr(get_option('qilingshop_risk_checkin_ip_daily_limit', 200)); ?>">
                    <p class="description"><?php _e('同一IP每日允许成功签到的账户数，0表示不限制。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('发起助力冷却(秒)', 'qilingshop'); ?></th>
                <td>
                    <input type="number" name="risk_assist_create_user_cooldown" min="0" value="<?php echo esc_attr(get_option('qilingshop_risk_assist_create_user_cooldown', 15)); ?>">
                    <p class="description"><?php _e('同一用户发起助力的最小间隔，0表示不限制。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('助力同用户每小时上限', 'qilingshop'); ?></th>
                <td>
                    <input type="number" name="risk_assist_help_user_hour_limit" min="0" value="<?php echo esc_attr(get_option('qilingshop_risk_assist_help_user_hour_limit', 120)); ?>">
                    <p class="description"><?php _e('同一用户每小时可助力次数，0表示不限制。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('助力同IP每小时上限', 'qilingshop'); ?></th>
                <td>
                    <input type="number" name="risk_assist_help_ip_hour_limit" min="0" value="<?php echo esc_attr(get_option('qilingshop_risk_assist_help_ip_hour_limit', 500)); ?>">
                    <p class="description"><?php _e('同一IP每小时可助力总次数，默认宽松；0表示不限制。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('助力同用户冷却(秒)', 'qilingshop'); ?></th>
                <td>
                    <input type="number" name="risk_assist_help_user_cooldown" min="0" value="<?php echo esc_attr(get_option('qilingshop_risk_assist_help_user_cooldown', 3)); ?>">
                    <p class="description"><?php _e('同一用户连续助力最小间隔，0表示不限制。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('邀请阶梯奖励', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_invite_tier_enabled" value="1">
                    <label><input type="checkbox" name="invite_tier_enabled" value="1" <?php checked(get_option('qilingshop_invite_tier_enabled', false)); ?>> <?php _e('启用阶梯奖励', 'qilingshop'); ?></label>
                    <p class="qls-settings-stack-8">
                        <?php _e('统计口径：', 'qilingshop'); ?>
                        <select name="invite_tier_metric">
                            <option value="registration" <?php selected($invite_tier_metric, 'registration'); ?>><?php _e('按邀请注册人数', 'qilingshop'); ?></option>
                            <option value="first_paid" <?php selected($invite_tier_metric, 'first_paid'); ?>><?php _e('按被邀请用户首单消费人数', 'qilingshop'); ?></option>
                        </select>
                    </p>
                    <p class="description"><?php _e('“按注册人数”是当前模式；“按首单消费人数”需被邀请用户至少完成1笔已支付订单（资源/商城/充值任一）。', 'qilingshop'); ?></p>
                </td>
            </tr>
        </table>

        <h2 class="qls-settings-section-title"><?php _e('邀请阶梯奖励规则', 'qilingshop'); ?></h2>
        <?php
        $db = QilingShop_Database::instance();
        $tiers = $db->get_results('invite_tier_rules', [
            'orderby' => 'threshold',
            'order'   => 'ASC',
            'limit'   => -1,
        ]);
        $edit_tier = null;
        if (isset($_GET['edit_invite_tier'])) {
            $edit_tier = $db->get_row('invite_tier_rules', ['id' => intval($_GET['edit_invite_tier'])]);
        }
        ?>
        <table class="wp-list-table qls-ui-table widefat fixed striped qls-settings-rules-table">
            <thead>
                <tr>
                    <th width="120"><?php echo $invite_tier_metric === 'first_paid' ? esc_html__('有效邀请人数', 'qilingshop') : esc_html__('邀请人数', 'qilingshop'); ?></th>
                    <th width="120"><?php _e('奖励积分', 'qilingshop'); ?></th>
                    <th><?php _e('说明', 'qilingshop'); ?></th>
                    <th width="80"><?php _e('状态', 'qilingshop'); ?></th>
                    <th width="100"><?php _e('操作', 'qilingshop'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($tiers) : foreach ($tiers as $tier) : ?>
                    <tr>
                        <td><?php echo esc_html($tier->threshold); ?></td>
                        <td><?php echo esc_html($tier->bonus_points); ?></td>
                        <td><?php echo esc_html($tier->description); ?></td>
                        <td><?php echo $tier->status ? __('启用', 'qilingshop') : __('停用', 'qilingshop'); ?></td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=qilingshop-settings&tab=affiliate&edit_invite_tier=' . $tier->id); ?>"><?php _e('编辑', 'qilingshop'); ?></a> |
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=qilingshop-settings&tab=affiliate&delete_invite_tier=' . $tier->id), 'delete_invite_tier_rule'); ?>" onclick="return confirm('<?php _e('确定删除？', 'qilingshop'); ?>')" class="qls-settings-link-danger"><?php _e('删除', 'qilingshop'); ?></a>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr>
                        <td colspan="5" class="qls-settings-empty-muted"><?php _e('暂无规则', 'qilingshop'); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="qls-settings-form-panel">
            <h3 class="qls-settings-form-title"><?php echo $edit_tier ? __('编辑阶梯规则', 'qilingshop') : __('添加阶梯规则', 'qilingshop'); ?></h3>
            <input type="hidden" name="invite_tier_rule_id" value="<?php echo $edit_tier ? esc_attr($edit_tier->id) : ''; ?>">
            <table class="form-table qls-ui-form-table">
                <tr>
                    <th><?php echo $invite_tier_metric === 'first_paid' ? esc_html__('有效邀请人数', 'qilingshop') : esc_html__('邀请人数', 'qilingshop'); ?></th>
                    <td><input type="number" name="invite_tier_threshold" min="1" value="<?php echo esc_attr($edit_tier ? $edit_tier->threshold : ''); ?>"></td>
                </tr>
                <tr>
                    <th><?php _e('奖励积分', 'qilingshop'); ?></th>
                    <td><input type="number" name="invite_tier_bonus" step="0.01" min="0" value="<?php echo esc_attr($edit_tier ? $edit_tier->bonus_points : ''); ?>"></td>
                </tr>
                <tr>
                    <th><?php _e('说明', 'qilingshop'); ?></th>
                    <td><input type="text" name="invite_tier_desc" class="regular-text" value="<?php echo esc_attr($edit_tier ? $edit_tier->description : ''); ?>"></td>
                </tr>
                <tr>
                    <th><?php _e('状态', 'qilingshop'); ?></th>
                    <td><label><input type="checkbox" name="invite_tier_status" value="1" <?php checked($edit_tier ? $edit_tier->status : 1); ?>> <?php _e('启用此规则', 'qilingshop'); ?></label></td>
                </tr>
            </table>
            <button type="submit" name="save_invite_tier_rule" class="button button-secondary"><?php echo $edit_tier ? __('更新规则', 'qilingshop') : __('添加规则', 'qilingshop'); ?></button>
            <?php if ($edit_tier): ?>
                <a href="<?php echo admin_url('admin.php?page=qilingshop-settings&tab=affiliate'); ?>" class="button"><?php _e('取消编辑', 'qilingshop'); ?></a>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_payment_tab() {
        ?>
        <div class="notice notice-info inline">
            <p>
                <strong><?php _e('回调说明：', 'qilingshop'); ?></strong>
                <?php _e('支付宝/微信/虎皮椒/易支付等多数通道会在“下单请求”中自动携带 notify_url，通常无需再到支付平台重复填写；若平台有固定回调地址、域名白名单或 IP 白名单要求，请按平台规则配置并与下方地址保持一致。PayPal/Stripe 仍需在平台后台配置 Webhook。', 'qilingshop'); ?>
            </p>
        </div>

        <h2><?php _e('商城退款设置', 'qilingshop'); ?></h2>
        <p class="description"><?php _e('保留原有退款到可提现余额能力，同时支持第一阶段支付宝/微信原路退款。', 'qilingshop'); ?></p>
        <table class="form-table qls-ui-form-table">
            <tr>
                <th><?php _e('商城退款方式', 'qilingshop'); ?></th>
                <td>
                    <select name="shop_refund_mode">
                        <option value="withdrawable_balance" <?php selected(get_option('qilingshop_shop_refund_mode', 'withdrawable_balance'), 'withdrawable_balance'); ?>><?php _e('退回可提现余额（默认）', 'qilingshop'); ?></option>
                        <option value="gateway" <?php selected(get_option('qilingshop_shop_refund_mode', 'withdrawable_balance'), 'gateway'); ?>><?php _e('原路退回（仅支付宝/微信）', 'qilingshop'); ?></option>
                    </select>
                    <p class="description"><?php _e('只影响商城最终确认退款时的现金退款方式；售后申请、审核、退货流程保持不变。', 'qilingshop'); ?></p>
                </td>
            </tr>
        </table>

        <?php
        $ticket_manager = function_exists('qls_shop_ticket') ? qls_shop_ticket() : null;
        $ticket_attachment_type_options = $ticket_manager ? $ticket_manager->get_attachment_type_options() : [];
        $ticket_attachment_type_keys = $ticket_manager ? $ticket_manager->get_allowed_attachment_type_keys() : ['jpg', 'png', 'webp', 'pdf'];
        $ticket_attachment_max_count = $ticket_manager ? $ticket_manager->get_max_attachment_count() : 3;
        $ticket_attachment_max_size_mb = max(1, min(20, (float) get_option('qilingshop_shop_ticket_attachment_max_size', 5)));
        ?>
        <h2><?php _e('售后工单附件设置', 'qilingshop'); ?></h2>
        <p class="description"><?php _e('控制前台售后工单和后台客服回复的附件上传限制。图片会进行扩展名、MIME 和图片内容真实性校验。', 'qilingshop'); ?></p>
        <table class="form-table qls-ui-form-table">
            <tr>
                <th><?php _e('最大上传数量', 'qilingshop'); ?></th>
                <td>
                    <input type="number" name="shop_ticket_attachment_max_count" value="<?php echo esc_attr($ticket_attachment_max_count); ?>" min="0" max="10" step="1" class="small-text">
                    <p class="description"><?php _e('每次提交或回复最多可上传的附件数量。填 0 表示关闭工单附件上传。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('单个附件大小', 'qilingshop'); ?></th>
                <td>
                    <input type="number" name="shop_ticket_attachment_max_size" value="<?php echo esc_attr($ticket_attachment_max_size_mb); ?>" min="1" max="20" step="0.5" class="small-text"> MB
                    <p class="description"><?php _e('单个附件大小上限，范围 1-20MB。服务器自身上传限制仍会优先生效。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('允许附件类型', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_shop_ticket_attachment_types" value="1">
                    <?php foreach ($ticket_attachment_type_options as $type_key => $type_data): ?>
                        <label class="qls-settings-label-gap-sm">
                            <input type="checkbox" name="shop_ticket_attachment_types[]" value="<?php echo esc_attr($type_key); ?>" <?php checked(in_array($type_key, $ticket_attachment_type_keys, true)); ?>>
                            <?php echo esc_html($type_data['label'] ?? $type_key); ?>
                        </label>
                    <?php endforeach; ?>
                    <p class="description"><?php _e('建议保留 JPG/PNG/WebP 作为图片凭证格式；PDF 可用于发票、物流单据等场景。', 'qilingshop'); ?></p>
                </td>
            </tr>
        </table>

        <?php if (function_exists('qilingshop_render_shop_refund_diagnostics_panel')) {
            qilingshop_render_shop_refund_diagnostics_panel();
        } ?>

        <?php
        $alipay_primary_notify = function_exists('qilingshop_get_payment_notify_url')
            ? qilingshop_get_payment_notify_url('alipay')
            : esc_url_raw(home_url('?qilingshop_payment=notify&gateway=alipay'));
        $alipay_legacy_notify = function_exists('qilingshop_get_payment_notify_url')
            ? qilingshop_get_payment_notify_url('alipay', true)
            : esc_url_raw(home_url('?qilingshop_payment=notify&gateway=alipay'));
        ?>
        <h2><?php _e('支付宝（RSA2签名）', 'qilingshop'); ?></h2>
        <p class="description"><?php _e('仅支持新版RSA2签名接口。支持当面付（扫码支付）和电脑网站支付。', 'qilingshop'); ?></p>
        <p class="qls-payment-endpoint"><?php esc_html_e('主异步通知地址：', 'qilingshop'); ?><code><?php echo esc_html($alipay_primary_notify); ?></code></p>
        <p class="qls-payment-endpoint"><?php esc_html_e('兼容旧版通知地址：', 'qilingshop'); ?><code><?php echo esc_html($alipay_legacy_notify); ?></code></p>
        <table class="form-table qls-ui-form-table">
            <tr>
                <th><?php _e('启用', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_alipay_enabled" value="1">
                    <label><input type="checkbox" name="alipay_enabled" value="1" <?php checked(get_option('qilingshop_alipay_enabled', false)); ?>> <?php _e('启用支付宝', 'qilingshop'); ?></label>
                </td>
            </tr>
            <tr>
                <th><?php _e('当面付（扫码支付）', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_alipay_f2fpay" value="1">
                    <label><input type="checkbox" name="alipay_f2fpay" value="1" <?php checked(get_option('qilingshop_alipay_f2fpay', true)); ?>> <?php _e('启用当面付，生成二维码扫码支付', 'qilingshop'); ?></label>
                </td>
            </tr>
            <tr>
                <th><?php _e('H5支付', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_alipay_h5" value="1">
                    <label><input type="checkbox" name="alipay_h5" value="1" <?php checked(get_option('qilingshop_alipay_h5', false)); ?>> <?php _e('手机网站支付（需申请权限）', 'qilingshop'); ?></label>
                </td>
            </tr>
            <tr>
                <th><?php _e('App ID', 'qilingshop'); ?></th>
                <td><input type="text" name="alipay_app_id" value="<?php echo esc_attr(get_option('qilingshop_alipay_app_id', '')); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><?php _e('应用私钥', 'qilingshop'); ?></th>
                <td><textarea name="alipay_private_key" rows="4" class="large-text"><?php echo esc_textarea(get_option('qilingshop_alipay_private_key', '')); ?></textarea>
                <p class="description"><?php _e('RSA2私钥，不需要头尾标签', 'qilingshop'); ?></p></td>
            </tr>
            <tr>
                <th><?php _e('支付宝公钥', 'qilingshop'); ?></th>
                <td><textarea name="alipay_public_key" rows="4" class="large-text"><?php echo esc_textarea(get_option('qilingshop_alipay_public_key', '')); ?></textarea>
                <p class="description"><?php _e('注意：是支付宝公钥，不是应用公钥', 'qilingshop'); ?></p></td>
            </tr>
        </table>

        <?php
        $wechat_primary_notify = function_exists('qilingshop_get_payment_notify_url')
            ? qilingshop_get_payment_notify_url('wechat')
            : home_url('?qilingshop_payment=notify&gateway=wechat');
        $wechat_legacy_notify = function_exists('qilingshop_get_payment_notify_url')
            ? qilingshop_get_payment_notify_url('wechat', true)
            : home_url('?qilingshop_payment=notify&gateway=wechat');
        ?>
        <h2><?php _e('微信支付', 'qilingshop'); ?></h2>
        <p class="qls-payment-endpoint"><?php esc_html_e('主异步通知地址：', 'qilingshop'); ?><code><?php echo esc_html($wechat_primary_notify); ?></code></p>
        <p class="qls-payment-endpoint"><?php esc_html_e('兼容旧版通知地址：', 'qilingshop'); ?><code><?php echo esc_html($wechat_legacy_notify); ?></code></p>
        <p class="qls-payment-endpoint"><?php esc_html_e('支付授权目录：', 'qilingshop'); ?><code><?php echo QILINGSHOP_URL . 'payment/'; ?></code></p>
        <table class="form-table qls-ui-form-table">
            <tr>
                <th><?php _e('启用', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_wechat_enabled" value="1">
                    <label><input type="checkbox" name="wechat_enabled" value="1" <?php checked(get_option('qilingshop_wechat_enabled', false)); ?>> <?php _e('启用微信支付', 'qilingshop'); ?></label>
                </td>
            </tr>
            <tr>
                <th><?php _e('商户号(MCHID)', 'qilingshop'); ?></th>
                <td><input type="text" name="wechat_mchid" value="<?php echo esc_attr(get_option('qilingshop_wechat_mchid', '')); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><?php _e('公众号/小程序APPID', 'qilingshop'); ?></th>
                <td><input type="text" name="wechat_appid" value="<?php echo esc_attr(get_option('qilingshop_wechat_appid', '')); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><?php _e('公众号AppSecret', 'qilingshop'); ?></th>
                <td><input type="text" name="wechat_secret" value="<?php echo esc_attr(get_option('qilingshop_wechat_secret', '')); ?>" class="regular-text">
                <p class="description"><?php _e('JSAPI支付需要（获取openid用）', 'qilingshop'); ?></p></td>
            </tr>
            <tr>
                <th><?php _e('商户支付密钥(KEY)', 'qilingshop'); ?></th>
                <td><input type="text" name="wechat_key" value="<?php echo esc_attr(get_option('qilingshop_wechat_key', '')); ?>" class="regular-text">
                <p class="description"><?php _e('32位密钥，在商户平台设置', 'qilingshop'); ?></p></td>
            </tr>
            <tr>
                <th><?php _e('商户 API 证书', 'qilingshop'); ?></th>
                <td><textarea name="wechat_client_cert" class="large-text code" rows="6"><?php echo esc_textarea(get_option('qilingshop_wechat_client_cert', '')); ?></textarea>
                <p class="description"><?php _e('仅用于网页/公众号微信原路退款。可直接粘贴 PEM 内容，或填写服务器可读取的绝对路径/站内文件 URL。', 'qilingshop'); ?></p></td>
            </tr>
            <tr>
                <th><?php _e('商户 API 私钥', 'qilingshop'); ?></th>
                <td><textarea name="wechat_client_key" class="large-text code" rows="6"><?php echo esc_textarea(get_option('qilingshop_wechat_client_key', '')); ?></textarea>
                <p class="description"><?php _e('仅用于网页/公众号微信原路退款。可直接粘贴 PEM 内容，或填写服务器可读取的绝对路径/站内文件 URL。', 'qilingshop'); ?></p></td>
            </tr>
            <tr>
                <th><?php _e('启用JSAPI支付', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_wechat_jsapi" value="1">
                    <label><input type="checkbox" name="wechat_jsapi" value="1" <?php checked(get_option('qilingshop_wechat_jsapi', false)); ?>> <?php _e('微信内H5支付', 'qilingshop'); ?></label>
                </td>
            </tr>
            <tr>
                <th><?php _e('启用H5支付', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_wechat_h5" value="1">
                    <label><input type="checkbox" name="wechat_h5" value="1" <?php checked(get_option('qilingshop_wechat_h5', false)); ?>> <?php _e('手机浏览器支付（唤醒支付应用）', 'qilingshop'); ?></label>
                    <p class="description qls-settings-guide-text">
                        <strong><?php _e('配置指南（必读）：', 'qilingshop'); ?></strong><br>
                        1. <strong><?php _e('微信公众平台 -> 公众号设置 -> 功能设置：', 'qilingshop'); ?></strong><br>
                        - <?php _e('设置【业务域名】、【JS接口安全域名】、【网页授权域名】为当前网站域名', 'qilingshop'); ?><br>
                        2. <strong><?php _e('微信商户平台 -> 产品中心 -> 开发配置：', 'qilingshop'); ?></strong><br>
                        - <?php _e('设置【支付授权目录】为：', 'qilingshop'); ?><code><?php echo QILINGSHOP_URL . 'payment/'; ?></code><br>
                        - <?php _e('设置【H5支付域名】为当前网站域名', 'qilingshop'); ?>
                    </p>
                </td>
            </tr>
        </table>

        <h2><?php _e('微信小程序支付', 'qilingshop'); ?></h2>
        <p class="description"><?php _e('小程序商城专用支付方式，独立于上方网页/公众号微信支付配置。', 'qilingshop'); ?></p>
        <p class="description"><?php _e('请按微信支付商户平台提供的参数填写，支持 v2 / v3 两套配置。', 'qilingshop'); ?></p>
        <p class="qls-payment-endpoint"><?php esc_html_e('异步通知地址：', 'qilingshop'); ?><code><?php echo esc_html(rest_url('qls/v1/notify/wechat-miniapp')); ?></code></p>
        <table class="form-table qls-ui-form-table">
            <tr>
                <th><?php _e('启用', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_wechat_miniapp_enabled" value="1">
                    <label><input type="checkbox" name="wechat_miniapp_enabled" value="1" <?php checked(get_option('qilingshop_wechat_miniapp_enabled', false)); ?>> <?php _e('启用微信小程序支付', 'qilingshop'); ?></label>
                </td>
            </tr>
            <tr>
                <th><?php _e('小程序 AppID', 'qilingshop'); ?></th>
                <td><input type="text" name="wechat_miniapp_appid" value="<?php echo esc_attr(get_option('qilingshop_wechat_miniapp_appid', '')); ?>" class="regular-text">
                <p class="description"><?php _e('必须与启灵小程序登录获取 openid 的 AppID 一致。', 'qilingshop'); ?></p></td>
            </tr>
            <tr>
                <th><?php _e('商户号(MCHID)', 'qilingshop'); ?></th>
                <td><input type="text" name="wechat_miniapp_mchid" value="<?php echo esc_attr(get_option('qilingshop_wechat_miniapp_mchid', '')); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><?php _e('接口版本', 'qilingshop'); ?></th>
                <td>
                    <select name="wechat_miniapp_pay_type" class="qls-miniapp-pay-type">
                        <option value="v2" <?php selected(get_option('qilingshop_wechat_miniapp_pay_type', 'v2'), 'v2'); ?>><?php _e('v2（旧版密钥）', 'qilingshop'); ?></option>
                        <option value="v3" <?php selected(get_option('qilingshop_wechat_miniapp_pay_type', 'v2'), 'v3'); ?>><?php _e('v3（新版密钥）', 'qilingshop'); ?></option>
                    </select>
                </td>
            </tr>
            <tr class="qls-miniapp-paytype-row qls-miniapp-paytype-v2">
                <th><?php _e('商户支付密钥(KEY)', 'qilingshop'); ?></th>
                <td><input type="text" name="wechat_miniapp_key" value="<?php echo esc_attr(get_option('qilingshop_wechat_miniapp_key', '')); ?>" class="regular-text">
                <p class="description"><?php _e('微信支付 v2 API 密钥。', 'qilingshop'); ?></p></td>
            </tr>
            <tr class="qls-miniapp-paytype-row qls-miniapp-paytype-v3">
                <th><?php _e('APIv3 密钥', 'qilingshop'); ?></th>
                <td><input type="text" name="wechat_miniapp_key_v3" value="<?php echo esc_attr(get_option('qilingshop_wechat_miniapp_key_v3', '')); ?>" class="regular-text">
                <p class="description"><?php _e('微信支付 APIv3 密钥。', 'qilingshop'); ?></p></td>
            </tr>
            <tr class="qls-miniapp-paytype-row qls-miniapp-paytype-v3">
                <th><?php _e('商户证书序列号', 'qilingshop'); ?></th>
                <td><input type="text" name="wechat_miniapp_serial_no" value="<?php echo esc_attr(get_option('qilingshop_wechat_miniapp_serial_no', '')); ?>" class="regular-text">
                <p class="description"><?php _e('商户 API 证书序列号。', 'qilingshop'); ?></p></td>
            </tr>
            <tr class="qls-miniapp-paytype-row qls-miniapp-paytype-v3">
                <th><?php _e('商户 API 证书', 'qilingshop'); ?></th>
                <td><textarea name="wechat_miniapp_client_cert" class="large-text code" rows="6"><?php echo esc_textarea(get_option('qilingshop_wechat_miniapp_client_cert', '')); ?></textarea>
                <p class="description"><?php _e('商户 API 证书。可直接粘贴 PEM 内容，或填写服务器可读取的绝对路径/站内文件地址。', 'qilingshop'); ?></p></td>
            </tr>
            <tr class="qls-miniapp-paytype-row qls-miniapp-paytype-v3">
                <th><?php _e('商户 API 私钥', 'qilingshop'); ?></th>
                <td><textarea name="wechat_miniapp_client_key" class="large-text code" rows="6"><?php echo esc_textarea(get_option('qilingshop_wechat_miniapp_client_key', '')); ?></textarea>
                <p class="description"><?php _e('商户 API 私钥。可直接粘贴 PEM 内容，或填写服务器可读取的绝对路径/站内文件地址。', 'qilingshop'); ?></p></td>
            </tr>
            <tr class="qls-miniapp-paytype-row qls-miniapp-paytype-v3">
                <th><?php _e('微信支付平台公钥 ID', 'qilingshop'); ?></th>
                <td><input type="text" name="wechat_miniapp_public_key_id" value="<?php echo esc_attr(get_option('qilingshop_wechat_miniapp_public_key_id', '')); ?>" class="regular-text">
                <p class="description"><?php _e('微信支付平台公钥 ID。', 'qilingshop'); ?></p></td>
            </tr>
            <tr class="qls-miniapp-paytype-row qls-miniapp-paytype-v3">
                <th><?php _e('微信支付平台公钥 PEM', 'qilingshop'); ?></th>
                <td><textarea name="wechat_miniapp_public_key_pem" class="large-text code" rows="6"><?php echo esc_textarea(get_option('qilingshop_wechat_miniapp_public_key_pem', '')); ?></textarea>
                <p class="description"><?php _e('微信支付平台公钥 PEM。可直接粘贴 PEM 内容，或填写服务器可读取的绝对路径/站内文件地址。', 'qilingshop'); ?></p></td>
            </tr>
            <tr class="qls-miniapp-paytype-row qls-miniapp-paytype-v3">
                <th><?php _e('转账场景 ID', 'qilingshop'); ?></th>
                <td><input type="text" name="wechat_miniapp_transfer_scene_id" value="<?php echo esc_attr(get_option('qilingshop_wechat_miniapp_transfer_scene_id', '')); ?>" class="regular-text">
                <p class="description"><?php _e('微信支付转账场景编号，仅在开通对应能力时填写。', 'qilingshop'); ?></p></td>
            </tr>
        </table>
        <script>
        jQuery(function($){
            function toggleMiniappPayTypeRows() {
                var payType = $('select[name="wechat_miniapp_pay_type"]').val() || 'v2';
                $('.qls-miniapp-paytype-row').hide();
                $('.qls-miniapp-paytype-' + payType).show();
            }
            toggleMiniappPayTypeRows();
            $(document).on('change', 'select[name="wechat_miniapp_pay_type"]', toggleMiniappPayTypeRows);
        });
        </script>

        <h2><?php _e('PayPal贝宝（官方接口）', 'qilingshop'); ?></h2>
        <p class="description"><?php _e('仅支持 PayPal Checkout Orders v2 + Webhook。', 'qilingshop'); ?></p>
        <p class="qls-payment-endpoint"><?php _e('Webhook 地址：', 'qilingshop'); ?><code><?php echo esc_html(home_url('/wp-json/qls/v1/notify/paypal')); ?></code></p>
        <p class="description"><?php _e('请在 PayPal Webhook 中订阅事件：CHECKOUT.ORDER.APPROVED、PAYMENT.CAPTURE.COMPLETED。', 'qilingshop'); ?></p>
        <table class="form-table qls-ui-form-table">
            <tr>
                <th><?php _e('启用', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_paypal_enabled" value="1">
                    <label><input type="checkbox" name="paypal_enabled" value="1" <?php checked(get_option('qilingshop_paypal_enabled', false)); ?>> <?php _e('启用 PayPal', 'qilingshop'); ?></label>
                </td>
            </tr>
            <tr>
                <th><?php _e('REST API Client ID', 'qilingshop'); ?></th>
                <td><input type="text" name="paypal_client_id" value="<?php echo esc_attr(get_option('qilingshop_paypal_client_id', '')); ?>" class="regular-text">
                <p class="description"><?php _e('PayPal Developer 应用的 Client ID', 'qilingshop'); ?></p></td>
            </tr>
            <tr>
                <th><?php _e('REST API Secret', 'qilingshop'); ?></th>
                <td><input type="password" name="paypal_client_secret" value="<?php echo esc_attr(get_option('qilingshop_paypal_client_secret', '')); ?>" class="regular-text">
                <p class="description"><?php _e('PayPal Developer 应用的 Secret', 'qilingshop'); ?></p></td>
            </tr>
            <tr>
                <th><?php _e('Webhook ID', 'qilingshop'); ?></th>
                <td><input type="text" name="paypal_webhook_id" value="<?php echo esc_attr(get_option('qilingshop_paypal_webhook_id', '')); ?>" class="regular-text">
                <p class="description"><?php _e('用于验签，来自 PayPal Webhook Endpoint 的 ID（建议必填）。', 'qilingshop'); ?></p></td>
            </tr>
            <tr>
                <th><?php _e('汇率', 'qilingshop'); ?></th>
                <td><input type="number" step="0.01" name="paypal_rate" value="<?php echo esc_attr(get_option('qilingshop_paypal_rate', 7)); ?>" class="small-text">
                <p class="description"><?php _e('填7表示1美元=7元人民币', 'qilingshop'); ?></p></td>
            </tr>
            <tr>
                <th><?php _e('沙盒模式', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_paypal_sandbox" value="1">
                    <label><input type="checkbox" name="paypal_sandbox" value="1" <?php checked(get_option('qilingshop_paypal_sandbox', false)); ?>> <?php _e('启用测试模式', 'qilingshop'); ?></label>
                </td>
            </tr>
        </table>

        <?php
        $xhpay_primary_notify = function_exists('qilingshop_get_payment_notify_url')
            ? qilingshop_get_payment_notify_url('xhpay')
            : esc_url_raw(home_url('?qilingshop_payment=notify&gateway=xhpay'));
        $xhpay_legacy_notify = function_exists('qilingshop_get_payment_notify_url')
            ? qilingshop_get_payment_notify_url('xhpay', true)
            : esc_url_raw(home_url('?qilingshop_payment=notify&gateway=xhpay'));
        ?>
        <h2><?php _e('虎皮椒 V3（个人免签）', 'qilingshop'); ?></h2>
        <p class="description"><?php _e('支持虎皮椒 V3 主备网关（do.html），可配置微信/支付宝两套账号。', 'qilingshop'); ?></p>
        <p class="qls-payment-endpoint"><?php _e('主异步通知地址：', 'qilingshop'); ?><code><?php echo esc_html($xhpay_primary_notify); ?></code></p>
        <p class="qls-payment-endpoint"><?php _e('兼容旧版通知地址：', 'qilingshop'); ?><code><?php echo esc_html($xhpay_legacy_notify); ?></code></p>
        <table class="form-table qls-ui-form-table">
            <tr>
                <th><?php _e('启用', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_xhpay_enabled" value="1">
                    <label><input type="checkbox" name="xhpay_enabled" value="1" <?php checked(get_option('qilingshop_xhpay_enabled', false)); ?>> <?php _e('启用虎皮椒 V3', 'qilingshop'); ?></label>
                </td>
            </tr>
            <tr>
                <th><?php _e('默认支付通道', 'qilingshop'); ?></th>
                <td>
                    <?php $xhpay_default_type = sanitize_key((string) get_option('qilingshop_xhpay_default_type', 'alipay')); ?>
                    <select name="xhpay_default_type">
                        <option value="alipay" <?php selected($xhpay_default_type, 'alipay'); ?>><?php _e('支付宝', 'qilingshop'); ?></option>
                        <option value="wechat" <?php selected($xhpay_default_type, 'wechat'); ?>><?php _e('微信', 'qilingshop'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><?php _e('插件标识（plugins）', 'qilingshop'); ?></th>
                <td>
                    <input type="text" name="xhpay_plugin_id" value="<?php echo esc_attr(get_option('qilingshop_xhpay_plugin_id', 'qilingshop-xhpay3')); ?>" class="regular-text">
                    <p class="description"><?php _e('用于回调校验的插件标识，默认保留即可。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('微信 AppID', 'qilingshop'); ?></th>
                <td><input type="text" name="xhpay_appid_wechat" value="<?php echo esc_attr(get_option('qilingshop_xhpay_appid_wechat', '')); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><?php _e('微信 AppSecret', 'qilingshop'); ?></th>
                <td><input type="text" name="xhpay_appsecret_wechat" value="<?php echo esc_attr(get_option('qilingshop_xhpay_appsecret_wechat', '')); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><?php _e('微信网关', 'qilingshop'); ?></th>
                <td>
                    <input type="text" name="xhpay_api_wechat" value="<?php echo esc_attr(get_option('qilingshop_xhpay_api_wechat', 'https://api.xunhupay.com/payment/do.html')); ?>" class="regular-text">
                    <p class="description"><?php _e('示例：https://api.xunhupay.com/payment/do.html 或备用 https://api.dpweixin.com/payment/do.html', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('支付宝 AppID', 'qilingshop'); ?></th>
                <td><input type="text" name="xhpay_appid_alipay" value="<?php echo esc_attr(get_option('qilingshop_xhpay_appid_alipay', '')); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><?php _e('支付宝 AppSecret', 'qilingshop'); ?></th>
                <td><input type="text" name="xhpay_appsecret_alipay" value="<?php echo esc_attr(get_option('qilingshop_xhpay_appsecret_alipay', '')); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><?php _e('支付宝网关', 'qilingshop'); ?></th>
                <td>
                    <input type="text" name="xhpay_api_alipay" value="<?php echo esc_attr(get_option('qilingshop_xhpay_api_alipay', 'https://api.xunhupay.com/payment/do.html')); ?>" class="regular-text">
                </td>
            </tr>
        </table>

        <?php
        $epay_primary_notify = function_exists('qilingshop_get_payment_notify_url')
            ? qilingshop_get_payment_notify_url('epay')
            : esc_url_raw(home_url('?qilingshop_payment=notify&gateway=epay'));
        $epay_legacy_notify = function_exists('qilingshop_get_payment_notify_url')
            ? qilingshop_get_payment_notify_url('epay', true)
            : esc_url_raw(home_url('?qilingshop_payment=notify&gateway=epay'));
        ?>
        <h2><?php _e('易支付（聚合通道）', 'qilingshop'); ?></h2>
        <p class="description"><?php _e('适配主流易支付程序，默认跳转 submit.php 下单。', 'qilingshop'); ?></p>
        <p class="qls-payment-endpoint"><?php _e('主异步通知地址：', 'qilingshop'); ?><code><?php echo esc_html($epay_primary_notify); ?></code></p>
        <p class="qls-payment-endpoint"><?php _e('兼容旧版通知地址：', 'qilingshop'); ?><code><?php echo esc_html($epay_legacy_notify); ?></code></p>
        <table class="form-table qls-ui-form-table">
            <tr>
                <th><?php _e('启用', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_epay_enabled" value="1">
                    <label><input type="checkbox" name="epay_enabled" value="1" <?php checked(get_option('qilingshop_epay_enabled', false)); ?>> <?php _e('启用易支付', 'qilingshop'); ?></label>
                </td>
            </tr>
            <tr>
                <th><?php _e('商户 ID (pid)', 'qilingshop'); ?></th>
                <td><input type="text" name="epay_pid" value="<?php echo esc_attr(get_option('qilingshop_epay_pid', '')); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><?php _e('商户密钥 (key)', 'qilingshop'); ?></th>
                <td><input type="text" name="epay_key" value="<?php echo esc_attr(get_option('qilingshop_epay_key', '')); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><?php _e('API 地址', 'qilingshop'); ?></th>
                <td>
                    <input type="text" name="epay_api_url" value="<?php echo esc_attr(get_option('qilingshop_epay_api_url', '')); ?>" class="regular-text" placeholder="https://example.com/">
                    <p class="description"><?php _e('填写站点根地址，系统会自动拼接 submit.php。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('默认支付通道', 'qilingshop'); ?></th>
                <td>
                    <?php $epay_default_type = sanitize_key((string) get_option('qilingshop_epay_default_type', 'alipay')); ?>
                    <select name="epay_default_type">
                        <option value="alipay" <?php selected($epay_default_type, 'alipay'); ?>><?php _e('支付宝', 'qilingshop'); ?></option>
                        <option value="wxpay" <?php selected($epay_default_type, 'wxpay'); ?>><?php _e('微信', 'qilingshop'); ?></option>
                        <option value="qqpay" <?php selected($epay_default_type, 'qqpay'); ?>>QQ Wallet</option>
                    </select>
                </td>
            </tr>
        </table>

        <h2><?php _e('Stripe（国际信用卡）', 'qilingshop'); ?></h2>
        <p class="description"><?php _e('推荐使用 Checkout + Webhook 模式。用户支付成功后由 Stripe Webhook 异步确认订单状态。', 'qilingshop'); ?></p>
        <p class="qls-payment-endpoint"><?php _e('Webhook 地址：', 'qilingshop'); ?><code><?php echo esc_html(home_url('/wp-json/qls/v1/notify/stripe')); ?></code></p>
        <table class="form-table qls-ui-form-table">
            <tr>
                <th><?php _e('启用', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_stripe_enabled" value="1">
                    <label><input type="checkbox" name="stripe_enabled" value="1" <?php checked(get_option('qilingshop_stripe_enabled', false)); ?>> <?php _e('启用 Stripe 支付', 'qilingshop'); ?></label>
                </td>
            </tr>
            <tr>
                <th><?php _e('Publishable Key', 'qilingshop'); ?></th>
                <td>
                    <input type="text" name="stripe_publishable_key" value="<?php echo esc_attr(get_option('qilingshop_stripe_publishable_key', '')); ?>" class="regular-text">
                    <p class="description"><?php _e('前端公钥（以 pk_ 开头）。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('Secret Key', 'qilingshop'); ?></th>
                <td>
                    <input type="password" name="stripe_secret_key" value="<?php echo esc_attr(get_option('qilingshop_stripe_secret_key', '')); ?>" class="regular-text">
                    <p class="description"><?php _e('服务端密钥（以 sk_ 开头），仅服务器可见。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('Webhook Secret', 'qilingshop'); ?></th>
                <td>
                    <input type="password" name="stripe_webhook_secret" value="<?php echo esc_attr(get_option('qilingshop_stripe_webhook_secret', '')); ?>" class="regular-text">
                    <p class="description"><?php _e('在 Stripe Webhook Endpoint 中复制签名密钥（以 whsec_ 开头）。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('结算币种', 'qilingshop'); ?></th>
                <td>
                    <input type="text" name="stripe_currency" value="<?php echo esc_attr(get_option('qilingshop_stripe_currency', 'usd')); ?>" class="small-text" placeholder="usd">
                    <p class="description"><?php _e('默认 usd。若填写 cny，将按人民币金额直接下单；其他币种按下方汇率换算。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('Stripe 汇率', 'qilingshop'); ?></th>
                <td>
                    <input type="number" step="0.0001" name="stripe_rate" value="<?php echo esc_attr(get_option('qilingshop_stripe_rate', 7)); ?>" class="small-text">
                    <p class="description"><?php _e('用于人民币金额换算到 Stripe 结算币种（例如 7 = 1 美元约 7 人民币）。', 'qilingshop'); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php _e('其他设置', 'qilingshop'); ?></h2>
        <table class="form-table qls-ui-form-table">
            <tr>
                <th><?php _e('支付成功跳转', 'qilingshop'); ?></th>
                <td><input type="url" name="payment_return_url" value="<?php echo esc_attr(get_option('qilingshop_payment_return_url', '')); ?>" class="regular-text" placeholder="<?php echo home_url('/'); ?>"></td>
            </tr>
        </table>
        <?php
    }

    private function render_notify_tab() {
        $scene_definitions = $this->get_notify_scene_definitions();
        ?>
        <h2><?php _e('统一通知设置', 'qilingshop'); ?></h2>
        <p class="description">
            <?php printf(__('邮件通知默认发送至站点管理员邮箱：<strong>%s</strong>。推送通知依赖“启灵推送”插件（支持飞书/钉钉多通道同时发送）。', 'qilingshop'), esc_html(get_option('admin_email'))); ?>
        </p>
        <p class="description">
            <?php _e('每个场景都拆分为「通知管理员 / 通知用户」独立开关，按业务需要单独配置。', 'qilingshop'); ?>
        </p>
        <p class="description">
            <?php _e('短信通知仅在支持场景下展示，模板 CODE 需在阿里云审核通过后手动填写。', 'qilingshop'); ?>
        </p>

        <div class="qls-settings-group">
            <?php foreach ($scene_definitions as $scene => $scene_config) : ?>
                <?php $this->render_notify_scene_settings($scene, $scene_config); ?>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private function render_download_tab() {
        ?>
        <table class="form-table qls-ui-form-table">
            <tr>
                <th><?php _e('关闭直接下载', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_disable_direct_download" value="1">
                    <label><input type="checkbox" name="disable_direct_download" value="1" <?php checked(get_option('qilingshop_disable_direct_download', false)); ?>> <?php _e('关闭购买框中的直接下载功能', 'qilingshop'); ?></label>
                    <p class="description"><?php _e('启用后，购买框中的"点击下载"按钮将变为"前往下载"，点击跳转到独立下载页面。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('免费资源等待时间', 'qilingshop'); ?></th>
                <td><input type="number" name="free_download_wait" value="<?php echo esc_attr(get_option('qilingshop_free_download_wait', 0)); ?>"> <?php esc_html_e('秒', 'qilingshop'); ?></td>
            </tr>
            <tr>
                <th><?php _e('付费资源等待时间', 'qilingshop'); ?></th>
                <td><input type="number" name="paid_download_wait" value="<?php echo esc_attr(get_option('qilingshop_paid_download_wait', 0)); ?>"> <?php esc_html_e('秒', 'qilingshop'); ?></td>
            </tr>
            <tr>
                <th><?php _e('购买框右上角徽章文案', 'qilingshop'); ?></th>
                <td>
                    <input type="text" name="buy_box_badge_text" value="<?php echo esc_attr(get_option('qilingshop_buy_box_badge_text', __('付费资源', 'qilingshop'))); ?>" class="regular-text">
                    <p class="description"><?php _e('默认：付费资源。显示在卡片左上角的提示文字。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('显示销量数据', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_show_sales_count" value="1">
                    <label><input type="checkbox" name="show_sales_count" value="1" <?php checked(get_option('qilingshop_show_sales_count', false)); ?>> <?php _e('在购买框右上角显示"已售 xx"', 'qilingshop'); ?></label>
                </td>
            </tr>
            <tr>
                <th><?php _e('允许直接支付', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_direct_pay_enabled" value="1">
                    <label><input type="checkbox" name="direct_pay_enabled" value="1" <?php checked(get_option('qilingshop_direct_pay_enabled', true)); ?>> <?php _e('允许用户直接付款购买资源', 'qilingshop'); ?></label>
                </td>
            </tr>
            <tr>
                <th><?php _e('无跳转购买', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_ajax_buy_enabled" value="1">
                    <label><input type="checkbox" name="ajax_buy_enabled" value="1" <?php checked(get_option('qilingshop_ajax_buy_enabled', true)); ?>> <?php _e('启用', 'qilingshop'); ?></label>
                </td>
            </tr>
            <tr>
                <th><?php _e('免登录购买', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_guest_buy_enabled" value="1">
                    <label><input type="checkbox" name="guest_buy_enabled" value="1" <?php checked(get_option('qilingshop_guest_buy_enabled', false)); ?>> <?php _e('启用（谨慎开启）', 'qilingshop'); ?></label>
                </td>
            </tr>
            <tr>
                <th><?php _e('游客购买有效天数', 'qilingshop'); ?></th>
                <td><input type="number" name="guest_cookie_days" value="<?php echo esc_attr(get_option('qilingshop_guest_cookie_days', 30)); ?>"> <?php esc_html_e('天', 'qilingshop'); ?></td>
            </tr>
            <tr>
                <th><?php _e('游客 IP 验证', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_guest_ip_verify" value="1">
                    <label><input type="checkbox" name="guest_ip_verify" value="1" <?php checked(get_option('qilingshop_guest_ip_verify', true)); ?>> <?php _e('同 IP 也视为已购买', 'qilingshop'); ?></label>
                </td>
            </tr>
            <tr>
                <th><?php _e('作者分成', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_author_commission_enabled" value="1">
                    <label><input type="checkbox" name="author_commission_enabled" value="1" <?php checked(get_option('qilingshop_author_commission_enabled', false)); ?>> <?php _e('启用', 'qilingshop'); ?></label>
                </td>
            </tr>
            <tr>
                <th><?php _e('作者分成比例', 'qilingshop'); ?></th>
                <td><input type="number" name="author_commission_rate" value="<?php echo esc_attr(get_option('qilingshop_author_commission_rate', 80)); ?>"> %</td>
            </tr>
            <tr>
                <th><?php _e('网盘链接检测', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_pancheck_enabled" value="1">
                    <label><input type="checkbox" name="pancheck_enabled" value="1" <?php checked(get_option('qilingshop_pancheck_enabled', false)); ?>> <?php _e('启用网盘有效性检测', 'qilingshop'); ?></label>
                    <p class="description"><?php _e('在购买框中显示"在线检测"按钮，支持：百度网盘、夸克网盘、天翼云盘、123云盘', 'qilingshop'); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php _e('交易播报', 'qilingshop'); ?></h2>
        <table class="form-table qls-ui-form-table">
            <tr>
                <th><?php _e('启用交易播报', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_trade_feed_enabled" value="1">
                    <label><input type="checkbox" name="trade_feed_enabled" value="1" <?php checked(get_option('qilingshop_trade_feed_enabled', false)); ?>> <?php _e('在网站右下角显示交易滚动播报', 'qilingshop'); ?></label>
                </td>
            </tr>
            <tr>
                <th><?php _e('启用虚拟数据', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_trade_feed_virtual_enabled" value="1">
                    <label><input type="checkbox" name="trade_feed_virtual_enabled" value="1" <?php checked(get_option('qilingshop_trade_feed_virtual_enabled', false)); ?>> <?php _e('开启后才混入虚拟头像和虚拟交易内容', 'qilingshop'); ?></label>
                </td>
            </tr>
            <tr>
                <th><?php _e('轮播间隔（秒）', 'qilingshop'); ?></th>
                <td>
                    <input type="number" name="trade_feed_interval_min" value="<?php echo esc_attr(get_option('qilingshop_trade_feed_interval_min', 4)); ?>" min="2" max="60" step="1" class="qls-settings-input-90"> ~
                    <input type="number" name="trade_feed_interval_max" value="<?php echo esc_attr(get_option('qilingshop_trade_feed_interval_max', 8)); ?>" min="2" max="60" step="1" class="qls-settings-input-90">
                    <p class="description"><?php _e('前端将按该区间随机切换播报。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('数据批量数', 'qilingshop'); ?></th>
                <td>
                    <input type="number" name="trade_feed_batch_size" value="<?php echo esc_attr(get_option('qilingshop_trade_feed_batch_size', 20)); ?>" min="5" max="50" step="1">
                    <p class="description"><?php _e('每次前端拉取的播报条数。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('虚拟数据占比', 'qilingshop'); ?></th>
                <td>
                    <input type="number" name="trade_feed_virtual_ratio" value="<?php echo esc_attr(get_option('qilingshop_trade_feed_virtual_ratio', 60)); ?>" min="0" max="100" step="1"> %
                    <p class="description"><?php _e('仅在开启虚拟数据时生效。0 表示纯真实数据。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('缓存时长（秒）', 'qilingshop'); ?></th>
                <td>
                    <input type="number" name="trade_feed_cache_ttl" value="<?php echo esc_attr(get_option('qilingshop_trade_feed_cache_ttl', 120)); ?>" min="10" max="1800" step="10">
                    <p class="description"><?php _e('通过短缓存减少查询压力，提高页面性能。', 'qilingshop'); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php _e('提现设置', 'qilingshop'); ?></h2>
        <table class="form-table qls-ui-form-table">
            <tr>
                <th><?php _e('提现功能', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_withdraw_enabled" value="1">
                    <label><input type="checkbox" name="withdraw_enabled" value="1" <?php checked(get_option('qilingshop_withdraw_enabled', false)); ?>> <?php _e('启用提现功能', 'qilingshop'); ?></label>
                    <p class="description"><?php _e('启用后，用户可在个人中心申请提现推广佣金', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('最低提现金额', 'qilingshop'); ?></th>
                <td><input type="number" name="withdraw_min_amount" step="0.01" value="<?php echo esc_attr(get_option('qilingshop_withdraw_min_amount', 100)); ?>"> <?php esc_html_e('元', 'qilingshop'); ?></td>
            </tr>
            <tr>
                <th><?php _e('提现手续费率', 'qilingshop'); ?></th>
                <td><input type="number" name="withdraw_fee_rate" step="0.1" min="0" max="100" value="<?php echo esc_attr(get_option('qilingshop_withdraw_fee_rate', 0)); ?>"> %
                <p class="description"><?php _e('0 表示不收取手续费', 'qilingshop'); ?></p></td>
            </tr>
        </table>

        <h2><?php _e('提示文案', 'qilingshop'); ?></h2>
        <p class="description"><?php _e('以下提示文案支持基础排版内容，可添加链接和格式化文本。', 'qilingshop'); ?></p>
        <table class="form-table qls-ui-form-table">
            <tr>
                <th><?php _e('下载提示', 'qilingshop'); ?></th>
                <td>
                    <textarea name="tips_download" rows="3" class="large-text"><?php echo esc_textarea(get_option('qilingshop_tips_download', '')); ?></textarea>
                    <p class="description"><?php _e('显示在独立下载页面的提示信息（购买后点击下载进入的页面）', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('付费查看提示', 'qilingshop'); ?></th>
                <td>
                    <textarea name="tips_view" rows="3" class="large-text"><?php echo esc_textarea(get_option('qilingshop_tips_view', '')); ?></textarea>
                    <p class="description"><?php _e('显示在付费查看购买框中的提示信息', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('付费下载提示', 'qilingshop'); ?></th>
                <td>
                    <textarea name="tips_download_pay" rows="3" class="large-text"><?php echo esc_textarea(get_option('qilingshop_tips_download_pay', '')); ?></textarea>
                    <p class="description"><?php _e('显示在付费下载购买框中的提示信息', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('充值提示', 'qilingshop'); ?></th>
                <td>
                    <textarea name="tips_recharge" rows="3" class="large-text"><?php echo esc_textarea(get_option('qilingshop_tips_recharge', '')); ?></textarea>
                    <p class="description"><?php _e('显示在积分中心充值弹窗中的提示信息', 'qilingshop'); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php _e('默认值设置', 'qilingshop'); ?></h2>
        <table class="form-table qls-ui-form-table">
            <tr>
                <th><?php _e('新文章默认价格', 'qilingshop'); ?></th>
                <td><input type="number" name="default_price" step="0.01" min="0" value="<?php echo esc_attr(get_option('qilingshop_default_price', 0)); ?>"> <?php echo qilingshop_get_points_name(); ?>
                <p class="description"><?php _e('新建文章时的默认资源价格', 'qilingshop'); ?></p></td>
            </tr>
            <tr>
                <th><?php _e('默认VIP折扣设置', 'qilingshop'); ?></th>
                <td>
                    <select name="default_vip_discount">
                        <option value="none" <?php selected(get_option('qilingshop_default_vip_discount', 'none'), 'none'); ?>><?php _e('不参与VIP折扣', 'qilingshop'); ?></option>
                        <option value="default" <?php selected(get_option('qilingshop_default_vip_discount', 'none'), 'default'); ?>><?php _e('使用VIP等级默认折扣', 'qilingshop'); ?></option>
                        <option value="vip_free" <?php selected(get_option('qilingshop_default_vip_discount', 'none'), 'vip_free'); ?>><?php _e('VIP免费', 'qilingshop'); ?></option>
                    </select>
                    <p class="description"><?php _e('新建文章时的默认VIP折扣设置', 'qilingshop'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * 渲染单个业务场景的通知配置
     *
     * @param string $scene  场景键
     * @param array  $config 场景配置
     */
    private function render_notify_scene_settings($scene, $config = []) {
        $scene         = sanitize_key($scene);
        $label         = isset($config['label']) ? (string) $config['label'] : $scene;
        $description   = isset($config['description']) ? (string) $config['description'] : '';
        $legacy_option = isset($config['legacy_option']) ? sanitize_key((string) $config['legacy_option']) : '';
        $legacy_scene  = isset($config['legacy_scene']) ? sanitize_key((string) $config['legacy_scene']) : '';
        $admin_enabled_default = isset($config['default_admin']) ? (bool) $config['default_admin'] : true;
        $user_enabled_default  = isset($config['default_user']) ? (bool) $config['default_user'] : true;
        $admin_supported = !isset($config['admin']) || (bool) $config['admin'];
        $user_supported  = !isset($config['user']) || (bool) $config['user'];
        $sms_supported   = $user_supported && !empty($config['sms']);

        $admin_field   = 'notify_' . $scene . '_admin_enabled';
        $user_field    = 'notify_' . $scene . '_user_enabled';
        $method_field  = 'notify_' . $scene . '_method';
        $channel_field = 'notify_' . $scene . '_push_channel';
        $sms_enabled_field = 'notify_' . $scene . '_sms_enabled';
        $sms_template_field = 'notify_' . $scene . '_sms_template_code';
        $method_value  = $this->get_notify_method_value($scene, $legacy_option, $legacy_scene);
        $channels      = $this->get_notify_channels_value($scene, $legacy_scene);
        $admin_enabled = $this->get_notify_role_enabled_value($scene, 'admin', $admin_enabled_default);
        $user_enabled  = $this->get_notify_role_enabled_value($scene, 'user', $user_enabled_default);
        $sms_enabled_default = isset($config['sms_default']) ? (bool) $config['sms_default'] : false;
        $sms_enabled = $sms_supported ? $this->get_bool_option_value('qilingshop_' . $sms_enabled_field, $sms_enabled_default) : false;
        $sms_template_code = $sms_supported ? sanitize_text_field((string) get_option('qilingshop_' . $sms_template_field, '')) : '';
        $sms_description = isset($config['sms_description']) ? (string) $config['sms_description'] : '';
        $sms_template_example = isset($config['sms_template_example']) ? (string) $config['sms_template_example'] : '';
        $sms_vars = isset($config['sms_vars']) && is_array($config['sms_vars']) ? $config['sms_vars'] : [];
        $choices       = $this->get_qilinghook_channel_options();
        $has_webhook   = function_exists('qilinghook_send');
        ?>
        <div class="qls-notify-scene-card">
            <strong class="qls-notify-scene-title"><?php echo esc_html($label); ?></strong>
            <?php if ($description !== '') : ?>
                <p class="description"><?php echo esc_html($description); ?></p>
            <?php endif; ?>

            <div class="qls-settings-stack-10 qls-notify-switch-group">
                <?php if ($admin_supported) : ?>
                    <input type="hidden" name="has_<?php echo esc_attr($admin_field); ?>" value="1">
                    <label class="qls-notify-switch-label">
                        <input type="checkbox" name="<?php echo esc_attr($admin_field); ?>" value="1" <?php checked($admin_enabled); ?>>
                        <?php _e('通知管理员（邮件/推送）', 'qilingshop'); ?>
                    </label>

                    <label for="<?php echo esc_attr($method_field); ?>" class="qls-settings-label-gap-sm"><?php _e('管理员通知方式：', 'qilingshop'); ?></label>
                    <select name="<?php echo esc_attr($method_field); ?>" id="<?php echo esc_attr($method_field); ?>">
                        <option value="none" <?php selected($method_value, 'none'); ?>><?php _e('关闭', 'qilingshop'); ?></option>
                        <option value="email" <?php selected($method_value, 'email'); ?>><?php _e('仅邮件', 'qilingshop'); ?></option>
                        <option value="push" <?php selected($method_value, 'push'); ?>><?php _e('仅飞书/钉钉推送', 'qilingshop'); ?></option>
                        <option value="both" <?php selected($method_value, 'both'); ?>><?php _e('邮件 + 飞书/钉钉推送', 'qilingshop'); ?></option>
                    </select>

                    <div class="qls-settings-stack-8">
                        <input type="hidden" name="has_<?php echo esc_attr($channel_field); ?>" value="1">
                        <span class="qls-notify-scene-subtitle"><?php _e('管理员推送通道（可多选）：', 'qilingshop'); ?></span>
                        <?php if (!empty($choices)) : ?>
                            <?php foreach ($choices as $channel_id => $channel_name) : ?>
                                <label class="qls-notify-channel-label">
                                    <input type="checkbox" name="<?php echo esc_attr($channel_field); ?>[]" value="<?php echo esc_attr($channel_id); ?>" <?php checked(in_array($channel_id, $channels, true)); ?>>
                                    <?php echo esc_html($channel_name); ?>
                                </label>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <span class="description">
                                <?php
                                if ($has_webhook) {
                                    _e('暂未配置可用推送通道，请先到「启灵推送」插件中添加并启用通道。', 'qilingshop');
                                } else {
                                    _e('未检测到“启灵推送”插件，暂不可使用飞书/钉钉推送。', 'qilingshop');
                                }
                                ?>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($user_supported) : ?>
                    <input type="hidden" name="has_<?php echo esc_attr($user_field); ?>" value="1">
                    <label class="qls-notify-switch-label">
                        <input type="checkbox" name="<?php echo esc_attr($user_field); ?>" value="1" <?php checked($user_enabled); ?>>
                        <?php _e('通知用户', 'qilingshop'); ?>
                    </label>

                    <?php if ($sms_supported) : ?>
                        <div class="qls-settings-stack-8 qls-notify-sms-group">
                            <input type="hidden" name="has_<?php echo esc_attr($sms_enabled_field); ?>" value="1">
                            <label class="qls-notify-switch-label">
                                <input type="checkbox" name="<?php echo esc_attr($sms_enabled_field); ?>" value="1" <?php checked($sms_enabled); ?>>
                                <?php _e('启用短信通知（用户）', 'qilingshop'); ?>
                            </label>

                            <label for="<?php echo esc_attr($sms_template_field); ?>" class="qls-settings-label-gap-sm"><?php _e('短信模板 CODE：', 'qilingshop'); ?></label>
                            <input
                                type="text"
                                class="regular-text"
                                id="<?php echo esc_attr($sms_template_field); ?>"
                                name="<?php echo esc_attr($sms_template_field); ?>"
                                value="<?php echo esc_attr($sms_template_code); ?>"
                                placeholder="SMS_XXXXXXXX"
                            >
                            <p class="description">
                                <?php
                                echo esc_html(
                                    $sms_description !== ''
                                        ? $sms_description
                                        : __('模板在阿里云短信服务中申请并审核通过后填写。', 'qilingshop')
                                );
                                ?>
                            </p>

                            <?php if ($sms_template_example !== '') : ?>
                                <div class="qls-settings-stack-6">
                                    <p class="description">
                                        <strong><?php _e('参考模板内容：', 'qilingshop'); ?></strong>
                                    </p>
                                    <p class="description">
                                        <code><?php echo esc_html($sms_template_example); ?></code>
                                    </p>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($sms_vars)) : ?>
                                <div class="qls-settings-stack-6">
                                    <p class="description">
                                        <strong><?php _e('模板变量说明：', 'qilingshop'); ?></strong>
                                    </p>
                                    <?php foreach ($sms_vars as $var_key => $var_desc) : ?>
                                        <?php
                                        $safe_var_key = preg_replace('/[^A-Za-z0-9_]/', '', (string) $var_key);
                                        if ($safe_var_key === '') {
                                            continue;
                                        }
                                        ?>
                                        <p class="description">
                                            <code>${<?php echo esc_html($safe_var_key); ?>}</code> <?php echo esc_html((string) $var_desc); ?>
                                        </p>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * 获取通知方式当前值（支持旧开关回退）
     *
     * @param string $scene         场景键
     * @param string $legacy_option 旧开关（不含前缀）
     * @param string $legacy_scene  旧场景键（不含前缀）
     * @return string
     */
    private function get_notify_method_value($scene, $legacy_option = '', $legacy_scene = '') {
        $scene = sanitize_key($scene);
        $mode  = get_option('qilingshop_notify_' . $scene . '_method', '');

        if (!in_array($mode, ['none', 'email', 'push', 'both'], true)) {
            $mode = '';
        }

        if ($mode === '' && $legacy_scene !== '') {
            $legacy_scene = sanitize_key($legacy_scene);
            $legacy_mode  = get_option('qilingshop_notify_' . $legacy_scene . '_method', '');
            if (in_array($legacy_mode, ['none', 'email', 'push', 'both'], true)) {
                $mode = $legacy_mode;
            }
        }

        if ($mode === '' && !empty($legacy_option) && get_option('qilingshop_' . $legacy_option, false)) {
            return 'email';
        }

        return $mode === '' ? 'none' : $mode;
    }

    /**
     * 获取通知场景的推送通道
     *
     * @param string $scene        场景键
     * @param string $legacy_scene 旧场景键（不含前缀）
     * @return array
     */
    private function get_notify_channels_value($scene, $legacy_scene = '') {
        $scene = sanitize_key($scene);
        $values = $this->normalize_notify_channels_value(
            get_option('qilingshop_notify_' . $scene . '_push_channel', [])
        );

        if (empty($values) && $legacy_scene !== '') {
            $legacy_scene = sanitize_key($legacy_scene);
            $values = $this->normalize_notify_channels_value(
                get_option('qilingshop_notify_' . $legacy_scene . '_push_channel', [])
            );
        }

        $channels = [];
        foreach ($values as $channel_id) {
            $clean_id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $channel_id);
            if ($clean_id !== '') {
                $channels[] = $clean_id;
            }
        }

        return array_values(array_unique($channels));
    }

    /**
     * 获取角色开关值
     *
     * @param string $scene   场景键
     * @param string $role    admin|user
     * @param bool   $default 默认值
     * @return bool
     */
    private function get_notify_role_enabled_value($scene, $role, $default = true) {
        $scene = sanitize_key($scene);
        $role  = $role === 'admin' ? 'admin' : 'user';
        return $this->get_bool_option_value('qilingshop_notify_' . $scene . '_' . $role . '_enabled', (bool) $default);
    }

    /**
     * 标准化通道配置值
     *
     * @param mixed $raw 原始值
     * @return array
     */
    private function normalize_notify_channels_value($raw) {
        if (is_array($raw)) {
            return $raw;
        }
        if ($raw === '' || $raw === null) {
            return [];
        }
        return [$raw];
    }

    /**
     * 读取布尔选项
     *
     * @param string $option  选项名
     * @param bool   $default 默认值
     * @return bool
     */
    private function get_bool_option_value($option, $default = false) {
        $raw = get_option((string) $option, null);
        if ($raw === null) {
            return (bool) $default;
        }

        if (is_bool($raw)) {
            return $raw;
        }

        if (is_numeric($raw)) {
            return (int) $raw === 1;
        }

        $raw = strtolower(trim((string) $raw));
        if (in_array($raw, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($raw, ['0', 'false', 'no', 'off', ''], true)) {
            return false;
        }

        return (bool) $default;
    }

    /**
     * 通知场景定义
     *
     * @return array
     */
    private function get_notify_scene_definitions() {
        return [
            'recharge' => [
                'label' => __('用户充值成功', 'qilingshop'),
                'description' => __('充值成功后，通常需要通知管理员对账，也应通知用户到账结果。', 'qilingshop'),
                'legacy_option' => 'admin_email_recharge',
                'default_admin' => true,
                'default_user' => true,
            ],
            'order' => [
                'label' => __('用户购买资源成功', 'qilingshop'),
                'description' => __('资源订单支付完成通知。', 'qilingshop'),
                'legacy_option' => 'admin_email_order',
                'default_admin' => true,
                'default_user' => true,
            ],
            'vip' => [
                'label' => __('VIP 开通/升级成功', 'qilingshop'),
                'default_admin' => true,
                'default_user' => true,
            ],
            'vip_expiring' => [
                'label' => __('VIP 即将到期提醒', 'qilingshop'),
                'default_admin' => false,
                'default_user' => true,
            ],
            'vip_expired' => [
                'label' => __('VIP 已过期提醒', 'qilingshop'),
                'default_admin' => false,
                'default_user' => true,
            ],
            'checkin' => [
                'label' => __('用户签到成功', 'qilingshop'),
                'default_admin' => false,
                'default_user' => true,
            ],
            'invite_registered' => [
                'label' => __('邀请注册成功', 'qilingshop'),
                'default_admin' => false,
                'default_user' => true,
            ],
            'invite_tier' => [
                'label' => __('邀请阶梯奖励到账', 'qilingshop'),
                'default_admin' => false,
                'default_user' => true,
            ],
            'affiliate_commission' => [
                'label' => __('推广佣金到账', 'qilingshop'),
                'default_admin' => false,
                'default_user' => true,
            ],
            'author_commission' => [
                'label' => __('作者提成到账', 'qilingshop'),
                'default_admin' => false,
                'default_user' => true,
            ],
            'withdraw_submitted' => [
                'label' => __('提现申请已提交', 'qilingshop'),
                'description' => __('提现申请建议通知管理员及时审核，同时通知用户提交成功。', 'qilingshop'),
                'default_admin' => true,
                'default_user' => true,
            ],
            'withdraw_approved' => [
                'label' => __('提现审核通过', 'qilingshop'),
                'default_admin' => false,
                'default_user' => true,
            ],
            'withdraw_rejected' => [
                'label' => __('提现审核拒绝', 'qilingshop'),
                'default_admin' => false,
                'default_user' => true,
            ],
            'points_expiring' => [
                'label' => __('积分即将过期提醒', 'qilingshop'),
                'default_admin' => false,
                'default_user' => true,
            ],
            'points_expired' => [
                'label' => __('积分已过期提醒', 'qilingshop'),
                'default_admin' => false,
                'default_user' => true,
            ],
            'birthday_coupon' => [
                'label' => __('生日券发放通知', 'qilingshop'),
                'default_admin' => false,
                'default_user' => true,
            ],
            'task_reward' => [
                'label' => __('任务奖励到账通知', 'qilingshop'),
                'default_admin' => false,
                'default_user' => true,
            ],
            'payment_recovery' => [
                'label' => __('待支付订单召回提醒', 'qilingshop'),
                'description' => __('该场景仅发给用户，用于提醒完成未支付订单。', 'qilingshop'),
                'admin' => false,
                'default_user' => true,
            ],
            'group_success' => [
                'label' => __('拼团成功通知', 'qilingshop'),
                'default_admin' => false,
                'default_user' => true,
            ],
            'group_failed' => [
                'label' => __('拼团失败通知', 'qilingshop'),
                'default_admin' => false,
                'default_user' => true,
            ],
            'shop_paid' => [
                'label' => __('实物订单已付款', 'qilingshop'),
                'legacy_scene' => 'shop',
                'default_admin' => true,
                'default_user' => true,
            ],
            'shop_shipped' => [
                'label' => __('实物订单已发货', 'qilingshop'),
                'legacy_scene' => 'shop',
                'default_admin' => true,
                'default_user' => true,
                'sms' => true,
                'sms_default' => false,
                'sms_description' => __('使用启灵主题阿里云短信发送。模板变量建议包含：orderNo、company、trackingNo、site、status。', 'qilingshop'),
                'sms_template_example' => __('【${site}】您的订单${orderNo}已发货，物流公司：${company}，物流单号：${trackingNo}，当前状态：${status}。', 'qilingshop'),
                'sms_vars' => [
                    'site' => __('站点名称，例如“启灵资源站”。', 'qilingshop'),
                    'orderNo' => __('订单号，例如 SHOP202603120001。', 'qilingshop'),
                    'company' => __('物流公司名称，例如 顺丰速运。', 'qilingshop'),
                    'trackingNo' => __('物流单号。', 'qilingshop'),
                    'status' => __('订单状态文案，当前固定为“已发货”。', 'qilingshop'),
                ],
            ],
            'shop_completed' => [
                'label' => __('实物订单已完成', 'qilingshop'),
                'legacy_scene' => 'shop',
                'default_admin' => true,
                'default_user' => true,
            ],
            'shop_cancelled' => [
                'label' => __('实物订单已取消', 'qilingshop'),
                'legacy_scene' => 'shop',
                'default_admin' => true,
                'default_user' => true,
            ],
            'shop_refund_applied' => [
                'label' => __('实物订单申请退款', 'qilingshop'),
                'legacy_scene' => 'shop',
                'default_admin' => true,
                'default_user' => true,
            ],
            'shop_refunded' => [
                'label' => __('实物订单已退款', 'qilingshop'),
                'legacy_scene' => 'shop',
                'default_admin' => true,
                'default_user' => true,
            ],
            'shop_ticket_created' => [
                'label' => __('售后工单已提交', 'qilingshop'),
                'legacy_scene' => 'shop',
                'default_admin' => true,
                'default_user' => false,
            ],
            'shop_ticket_user_replied' => [
                'label' => __('售后工单用户追问', 'qilingshop'),
                'legacy_scene' => 'shop',
                'default_admin' => true,
                'default_user' => false,
            ],
            'shop_ticket_admin_replied' => [
                'label' => __('售后工单客服回复', 'qilingshop'),
                'legacy_scene' => 'shop',
                'default_admin' => false,
                'default_user' => true,
            ],
        ];
    }

    /**
     * 读取启灵推送插件里的通道列表
     *
     * @return array key = channel_id, value = 展示名称
     */
    private function get_qilinghook_channel_options() {
        $settings = get_option('qilinghook_settings', []);
        if (!is_array($settings) || empty($settings['channels']) || !is_array($settings['channels'])) {
            return [];
        }

        $choices = [];
        foreach ($settings['channels'] as $channel) {
            if (!is_array($channel) || empty($channel['id']) || empty($channel['webhook_url']) || empty($channel['enabled'])) {
                continue;
            }

            $channel_id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $channel['id']);
            if ($channel_id === '') {
                continue;
            }

            $provider       = isset($channel['provider']) ? sanitize_key($channel['provider']) : 'feishu';
            $provider_label = $provider === 'dingtalk' ? __('钉钉', 'qilingshop') : __('飞书', 'qilingshop');
            $channel_name   = !empty($channel['name']) ? sanitize_text_field($channel['name']) : $channel_id;

            $choices[$channel_id] = sprintf('%s (%s)', $channel_name, $provider_label);
        }

        return $choices;
    }

    /**
     * 获取积分中心「会员权益设置」默认项。
     *
     * @return array
     */
    private function get_custom_benefit_defaults() {
        return [
            1 => ['icon' => '💰', 'title' => __('专属折扣', 'qilingshop'), 'desc' => __('购买资源享受专属优惠', 'qilingshop')],
            2 => ['icon' => '🎁', 'title' => __('免费资源', 'qilingshop'), 'desc' => __('部分资源VIP免费下载', 'qilingshop')],
            3 => ['icon' => '👑', 'title' => __('专属标识', 'qilingshop'), 'desc' => __('VIP尊贵身份标识', 'qilingshop')],
            4 => ['icon' => '💎', 'title' => __('优先支持', 'qilingshop'), 'desc' => __('享受优先客服支持', 'qilingshop')],
        ];
    }

    /**
     * 获取积分中心「快捷功能设置」默认项。
     *
     * @return array
     */
    private function get_custom_action_defaults() {
        return [
            1 => ['icon' => '📅', 'title' => __('每日签到', 'qilingshop'), 'desc' => '', 'link' => '#checkin'],
            2 => ['icon' => '👑', 'title' => __('VIP会员', 'qilingshop'), 'desc' => '', 'link' => '?tab=qls-vip'],
            3 => ['icon' => '🎁', 'title' => __('邀请好友', 'qilingshop'), 'desc' => '', 'link' => '?tab=qls-invite'],
            4 => ['icon' => '📦', 'title' => __('我的订单', 'qilingshop'), 'desc' => '', 'link' => '?tab=qls-orders'],
        ];
    }

    /**
     * 获取 VIP 落地页「核心权益」默认项。
     *
     * @return array
     */
    private function get_vip_landing_benefit_defaults() {
        return [
            1 => ['icon' => '⚡', 'title' => __('极速下载', 'qilingshop'), 'desc' => __('全站资源高速下载，通过网盘直链或本地储存', 'qilingshop')],
            2 => ['icon' => '🔓', 'title' => __('专属内容', 'qilingshop'), 'desc' => __('解锁会员专享文章、视频及隐藏内容', 'qilingshop')],
            3 => ['icon' => '🛡️', 'title' => __('无广告', 'qilingshop'), 'desc' => __('享受纯净的浏览体验，告别弹窗干扰', 'qilingshop')],
            4 => ['icon' => '🎫', 'title' => __('优先客服', 'qilingshop'), 'desc' => __('专属客服通道，问题优先响应处理', 'qilingshop')],
        ];
    }

    /**
     * 获取 VIP 落地页「权益对比表」默认项。
     *
     * @return array
     */
    private function get_vip_compare_defaults() {
        return [
            1 => ['name' => __('资源下载', 'qilingshop'), 'free' => __('部分限制', 'qilingshop'), 'vip' => __('全站免费/折扣', 'qilingshop')],
            2 => ['name' => __('下载速度', 'qilingshop'), 'free' => __('普通速度', 'qilingshop'), 'vip' => __('极速通道', 'qilingshop')],
            3 => ['name' => __('专属客服', 'qilingshop'), 'free' => '❌', 'vip' => '✅'],
            4 => ['name' => __('每日限制', 'qilingshop'), 'free' => __('1次/天', 'qilingshop'), 'vip' => __('无限制', 'qilingshop')],
            5 => ['name' => __('去广告', 'qilingshop'), 'free' => '❌', 'vip' => '✅'],
        ];
    }

    /**
     * 获取 VIP 落地页 FAQ 默认项。
     *
     * @return array
     */
    private function get_vip_faq_defaults() {
        return [
            1 => [
                'q' => __('支付后多久到账？', 'qilingshop'),
                'a' => __('通常情况下，支付成功后系统会自动为您开通权益，即时生效。', 'qilingshop'),
            ],
            2 => [
                'q' => __('会员有效期如何计算？', 'qilingshop'),
                'a' => __('从您支付成功的这一刻开始计算，有效期内享受相应权益。过期后自动恢复为普通用户。', 'qilingshop'),
            ],
        ];
    }

    /**
     * 渲染积分中心权益项配置卡片。
     *
     * @param int   $index    权益序号。
     * @param array $defaults 默认值。
     */
    private function render_custom_benefit_item($index, array $defaults) {
        $index = (int) $index;
        $icon  = get_option('qilingshop_benefit_' . $index . '_icon', isset($defaults['icon']) ? $defaults['icon'] : '');
        $title = get_option('qilingshop_benefit_' . $index . '_title', isset($defaults['title']) ? $defaults['title'] : '');
        $desc  = get_option('qilingshop_benefit_' . $index . '_desc', isset($defaults['desc']) ? $defaults['desc'] : '');
        ?>
        <div class="qls-item-card">
            <h4><?php printf(__('权益 %d', 'qilingshop'), $index); ?></h4>
            <div class="qls-inline-fields">
                <div class="qls-inline-field">
                    <label><?php _e('图标', 'qilingshop'); ?></label>
                    <input type="text" name="benefit_<?php echo $index; ?>_icon" value="<?php echo esc_attr($icon); ?>" class="qls-settings-input-80">
                </div>
                <div class="qls-inline-field">
                    <label><?php _e('标题', 'qilingshop'); ?></label>
                    <input type="text" name="benefit_<?php echo $index; ?>_title" value="<?php echo esc_attr($title); ?>">
                </div>
                <div class="qls-inline-field">
                    <label><?php _e('描述', 'qilingshop'); ?></label>
                    <input type="text" name="benefit_<?php echo $index; ?>_desc" value="<?php echo esc_attr($desc); ?>">
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * 渲染积分中心快捷功能配置卡片。
     *
     * @param int   $index    功能序号。
     * @param array $defaults 默认值。
     */
    private function render_custom_action_item($index, array $defaults) {
        $index = (int) $index;
        $icon  = get_option('qilingshop_quick_action_' . $index . '_icon', isset($defaults['icon']) ? $defaults['icon'] : '');
        $title = get_option('qilingshop_quick_action_' . $index . '_title', isset($defaults['title']) ? $defaults['title'] : '');
        $desc  = get_option('qilingshop_quick_action_' . $index . '_desc', isset($defaults['desc']) ? $defaults['desc'] : '');
        $link  = get_option('qilingshop_quick_action_' . $index . '_link', isset($defaults['link']) ? $defaults['link'] : '');
        ?>
        <div class="qls-item-card">
            <h4><?php printf(__('快捷功能 %d', 'qilingshop'), $index); ?></h4>
            <div class="qls-inline-fields">
                <div class="qls-inline-field">
                    <label><?php _e('图标', 'qilingshop'); ?></label>
                    <input type="text" name="quick_action_<?php echo $index; ?>_icon" value="<?php echo esc_attr($icon); ?>" class="qls-settings-input-80">
                </div>
                <div class="qls-inline-field">
                    <label><?php _e('标题', 'qilingshop'); ?></label>
                    <input type="text" name="quick_action_<?php echo $index; ?>_title" value="<?php echo esc_attr($title); ?>">
                </div>
                <div class="qls-inline-field">
                    <label><?php _e('描述', 'qilingshop'); ?></label>
                    <input type="text" name="quick_action_<?php echo $index; ?>_desc" value="<?php echo esc_attr($desc); ?>" placeholder="<?php echo esc_attr__('可选', 'qilingshop'); ?>">
                </div>
                <div class="qls-inline-field">
                    <label><?php _e('链接', 'qilingshop'); ?></label>
                    <input type="text" name="quick_action_<?php echo $index; ?>_link" value="<?php echo esc_attr($link); ?>">
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * 渲染 VIP 落地页权益卡片。
     *
     * @param int   $index    权益序号。
     * @param array $defaults 默认值。
     */
    private function render_vip_benefit_item($index, array $defaults) {
        $index = (int) $index;
        $icon  = get_option('qilingshop_vip_benefit_' . $index . '_icon', isset($defaults['icon']) ? $defaults['icon'] : '');
        $title = get_option('qilingshop_vip_benefit_' . $index . '_title', isset($defaults['title']) ? $defaults['title'] : '');
        $desc  = get_option('qilingshop_vip_benefit_' . $index . '_desc', isset($defaults['desc']) ? $defaults['desc'] : '');
        ?>
        <div class="qls-card">
            <div class="qls-card-title"><?php printf(__('权益 %d', 'qilingshop'), $index); ?></div>
            <div class="qls-field-row qls-field-row-column">
                <label class="qls-field-row-label-inline"><?php _e('图标', 'qilingshop'); ?></label>
                <input type="text" name="vip_benefit_<?php echo $index; ?>_icon" value="<?php echo esc_attr($icon); ?>" class="qls-settings-input-50">
            </div>
            <div class="qls-field-row qls-field-row-column">
                <label class="qls-field-row-label-inline"><?php _e('标题', 'qilingshop'); ?></label>
                <input type="text" name="vip_benefit_<?php echo $index; ?>_title" value="<?php echo esc_attr($title); ?>" class="qls-settings-input-full">
            </div>
            <div class="qls-field-row qls-field-row-column qls-field-row-no-margin">
                <label class="qls-field-row-label-inline"><?php _e('描述', 'qilingshop'); ?></label>
                <textarea name="vip_benefit_<?php echo $index; ?>_desc" rows="3" class="qls-settings-textarea-resize"><?php echo esc_textarea($desc); ?></textarea>
            </div>
        </div>
        <?php
    }

    /**
     * 渲染 VIP 落地页对比表单行。
     *
     * @param int   $index    行序号。
     * @param array $defaults 默认值。
     */
    private function render_vip_compare_row($index, array $defaults) {
        $index = (int) $index;
        $name  = get_option('qilingshop_vip_compare_' . $index . '_name', isset($defaults['name']) ? $defaults['name'] : '');
        $free  = get_option('qilingshop_vip_compare_' . $index . '_free', isset($defaults['free']) ? $defaults['free'] : '');
        $vip   = get_option('qilingshop_vip_compare_' . $index . '_vip', isset($defaults['vip']) ? $defaults['vip'] : '');
        ?>
        <tr>
            <td class="qls-vip-compare-cell">
                <input type="text" name="vip_compare_<?php echo $index; ?>_name" value="<?php echo esc_attr($name); ?>" class="qls-settings-input-full">
            </td>
            <td class="qls-vip-compare-cell">
                <input type="text" name="vip_compare_<?php echo $index; ?>_free" value="<?php echo esc_attr($free); ?>" class="qls-settings-input-full">
            </td>
            <td class="qls-vip-compare-cell">
                <input type="text" name="vip_compare_<?php echo $index; ?>_vip" value="<?php echo esc_attr($vip); ?>" class="qls-settings-input-full">
            </td>
        </tr>
        <?php
    }

    /**
     * 渲染 VIP 落地页 FAQ 项。
     *
     * @param int    $index      FAQ 序号。
     * @param string $default_q  默认问题。
     * @param string $default_a  默认答案。
     */
    private function render_vip_faq_item($index, $default_q = '', $default_a = '') {
        $index = (int) $index;
        $q     = get_option('qilingshop_vip_faq_' . $index . '_q', $default_q);
        $a     = get_option('qilingshop_vip_faq_' . $index . '_a', $default_a);
        ?>
        <div class="qls-vip-faq-item">
            <div class="qls-field-row">
                <label><?php printf(__('问题 %d', 'qilingshop'), $index); ?></label>
                <input type="text" name="vip_faq_<?php echo $index; ?>_q" value="<?php echo esc_attr($q); ?>" class="qls-settings-input-full">
            </div>
            <div class="qls-field-row">
                <label><?php _e('回答', 'qilingshop'); ?></label>
                <textarea name="vip_faq_<?php echo $index; ?>_a" rows="2" class="qls-settings-input-full"><?php echo esc_textarea($a); ?></textarea>
            </div>
        </div>
        <?php
    }

    private function render_custom_tab() {
        ?>
        <!-- 风格设置 -->
        <div class="qls-settings-group">
            <h3><?php _e('风格设置', 'qilingshop'); ?></h3>
            <div class="qls-inline-fields">
                <div class="qls-inline-field">
                    <label><?php _e('购买框风格', 'qilingshop'); ?></label>
                    <select name="buy_box_style">
                        <option value="fresh" <?php selected(get_option('qilingshop_buy_box_style', 'fresh'), 'fresh'); ?>><?php _e('清新多彩 (默认)', 'qilingshop'); ?></option>
                        <option value="business" <?php selected(get_option('qilingshop_buy_box_style', 'fresh'), 'business'); ?>><?php _e('商务', 'qilingshop'); ?></option>
                        <option value="gallery" <?php selected(get_option('qilingshop_buy_box_style', 'fresh'), 'gallery'); ?>><?php _e('暗黑', 'qilingshop'); ?></option>
                        <option value="violet" <?php selected(get_option('qilingshop_buy_box_style', 'fresh'), 'violet'); ?>><?php _e('紫韵梦幻', 'qilingshop'); ?></option>
                        <option value="blue_ocean" <?php selected(get_option('qilingshop_buy_box_style', 'fresh'), 'blue_ocean'); ?>><?php _e('碧海蓝天', 'qilingshop'); ?></option>
                        <option value="emerald" <?php selected(get_option('qilingshop_buy_box_style', 'fresh'), 'emerald'); ?>><?php _e('清新翠绿', 'qilingshop'); ?></option>
                    </select>
                </div>
                <div class="qls-inline-field">
                    <label><?php _e('个人中心风格', 'qilingshop'); ?></label>
                    <select name="account_style">
                        <option value="fresh" <?php selected(get_option('qilingshop_account_style', 'fresh'), 'fresh'); ?>><?php _e('清新多彩（默认）', 'qilingshop'); ?></option>
                        <option value="business" <?php selected(get_option('qilingshop_account_style', 'fresh'), 'business'); ?>><?php _e('商务蓝灰', 'qilingshop'); ?></option>
                        <option value="coral" <?php selected(get_option('qilingshop_account_style', 'fresh'), 'coral'); ?>><?php _e('活力珊瑚', 'qilingshop'); ?></option>
                        <option value="emerald" <?php selected(get_option('qilingshop_account_style', 'fresh'), 'emerald'); ?>><?php _e('自然青绿', 'qilingshop'); ?></option>
                    </select>
                    <p class="description"><?php _e('仅应用于启灵主题个人中心内的积分中心、VIP会员、积分订单等商城插件页面，不影响实物商城中心。', 'qilingshop'); ?></p>
                </div>
            </div>
            
            <div class="qls-inline-fields">
                <div class="qls-inline-field">
                    <label><?php _e('顶部 VIP 图标', 'qilingshop'); ?></label>
                    <input type="text" name="vip_icon" value="<?php echo esc_attr(get_option('qilingshop_vip_icon', '')); ?>" placeholder="<?php _e('表情符号、HTML 或 icon-vip', 'qilingshop'); ?>">
                    <p class="description qls-description-muted-tight"><?php _e('支持表情符号、HTML 图标片段、启灵阿里图标 icon-xxx，显示在网站顶部菜单栏。', 'qilingshop'); ?></p>
                </div>
            </div>
        </div>

        <!-- 位置设置 -->
        <div class="qls-settings-group">
            <h3><?php _e('位置设置', 'qilingshop'); ?></h3>
            <div class="qls-inline-fields">
                <div class="qls-inline-field">
                    <label><?php _e('下载框显示位置（侧边栏要去小工具添加）', 'qilingshop'); ?></label>
                    <select name="download_box_position">
                        <option value="bottom" <?php selected(get_option('qilingshop_download_box_position', 'bottom'), 'bottom'); ?>><?php _e('文章内容下方 (默认)', 'qilingshop'); ?></option>
                        <option value="top" <?php selected(get_option('qilingshop_download_box_position', 'bottom'), 'top'); ?>><?php _e('文章内容上方', 'qilingshop'); ?></option>
                        <option value="title" <?php selected(get_option('qilingshop_download_box_position', 'bottom'), 'title'); ?>><?php _e('标题区域（启灵主题）', 'qilingshop'); ?></option>
                        <option value="sidebar" <?php selected(get_option('qilingshop_download_box_position', 'bottom'), 'sidebar'); ?>><?php _e('侧边栏', 'qilingshop'); ?></option>
                        <option value="bottom_sidebar" <?php selected(get_option('qilingshop_download_box_position', 'bottom'), 'bottom_sidebar'); ?>><?php _e('内容下 + 侧边栏', 'qilingshop'); ?></option>
                        <option value="top_sidebar" <?php selected(get_option('qilingshop_download_box_position', 'bottom'), 'top_sidebar'); ?>><?php _e('内容上 + 侧边栏', 'qilingshop'); ?></option>
                    </select>
                </div>
            </div>
        </div>

        <!-- 会员名称设置 -->
        <div class="qls-settings-group">
            <h3><?php _e('会员名称设置', 'qilingshop'); ?></h3>
            <div class="qls-inline-fields">
                <div class="qls-inline-field">
                    <label><?php _e('VIP会员名称', 'qilingshop'); ?></label>
                    <input type="text" name="vip_name" value="<?php echo esc_attr(get_option('qilingshop_vip_name', __('VIP会员', 'qilingshop'))); ?>">
                </div>
            </div>
        </div>

        <!-- 游客提示文字设置 -->
        <div class="qls-settings-group">
            <h3><?php _e('游客提示文字设置', 'qilingshop'); ?></h3>
            <div class="qls-inline-fields">
                <div class="qls-inline-field">
                    <label><?php _e('游客提示文字', 'qilingshop'); ?></label>
                    <input type="text" name="guest_login_text" value="<?php echo esc_attr(get_option('qilingshop_guest_login_text', __('请先登录', 'qilingshop'))); ?>">
                    <p class="description"><?php _e('设置购买框中显示的“请先登录”提示文案', 'qilingshop'); ?></p>
                </div>
            </div>
        </div>
        
        <!-- 会员权益设置 -->
        <div class="qls-settings-group">
            <h3><?php _e('会员权益设置', 'qilingshop'); ?></h3>
            <p class="description"><?php _e('设置VIP会员页面展示的权益图标、标题和描述', 'qilingshop'); ?></p>
            
            <?php
            $benefit_defaults = $this->get_custom_benefit_defaults();
            foreach ($benefit_defaults as $i => $def) {
                $this->render_custom_benefit_item($i, $def);
            }
            ?>
        </div>
        
        <!-- 快捷功能设置 -->
        <div class="qls-settings-group">
            <h3><?php _e('快捷功能设置', 'qilingshop'); ?></h3>
            <p class="description"><?php _e('自定义积分中心的快捷功能入口（留空则不显示该项）。图标支持表情符号、HTML 图标片段、启灵阿里图标 icon-xxx。', 'qilingshop'); ?></p>
            
            <?php
            $action_defaults = $this->get_custom_action_defaults();
            foreach ($action_defaults as $i => $def) {
                $this->render_custom_action_item($i, $def);
            }
            ?>
        </div>
        <?php
    }

    private function render_vip_page_tab() {
        ?>
        <p class="description success qls-vip-shortcode-tip">
            <?php _e('VIP 落地页短代码：', 'qilingshop'); ?> <code>[qilingshop_vip_landing]</code>
            <br>
            <?php _e('您可以新建一个页面，填入此短代码，作为您的 VIP 会员介绍页。', 'qilingshop'); ?>
        </p>

        <!-- 购买/升级设置 -->
        <div class="qls-settings-group-vip">
            <h3><span>⚙️</span> <?php _e('购买与升级设置', 'qilingshop'); ?></h3>
            <table class="form-table qls-ui-form-table qls-settings-table-no-margin">
                <tr>
                    <th><?php _e('差价升级', 'qilingshop'); ?></th>
                    <td>
                        <input type="hidden" name="has_vip_diff_upgrade" value="1">
                        <label>
                            <input type="checkbox" name="vip_diff_upgrade" value="1" <?php checked(get_option('qilingshop_vip_diff_upgrade', true)); ?>>
                            <?php _e('允许补差价升级到更高等级', 'qilingshop'); ?>
                        </label>
                        <p class="description"><?php _e('开启后，高等级购买金额=目标等级价-当前等级价；仅在升级更高等级时生效。', 'qilingshop'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- 到期提醒与宽限期 -->
        <div class="qls-settings-group-vip">
            <h3><span>⏰</span> <?php _e('到期提醒与宽限期', 'qilingshop'); ?></h3>
            <table class="form-table qls-ui-form-table qls-settings-table-no-margin">
                <tr>
                    <th><?php _e('到期提醒', 'qilingshop'); ?></th>
                    <td>
                        <input type="hidden" name="has_vip_expire_remind_enabled" value="1">
                        <label>
                            <input type="checkbox" name="vip_expire_remind_enabled" value="1" <?php checked(get_option('qilingshop_vip_expire_remind_enabled', true)); ?>>
                            <?php _e('开启到期提醒', 'qilingshop'); ?>
                        </label>
                        <p class="description"><?php _e('开启后在到期前指定天数发送提醒邮件给用户。', 'qilingshop'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('提醒天数', 'qilingshop'); ?></th>
                    <td>
                        <input type="text" name="vip_expire_remind_days" value="<?php echo esc_attr(get_option('qilingshop_vip_expire_remind_days', '7,3')); ?>" class="regular-text">
                        <p class="description"><?php _e('使用半角逗号分隔，例如 7,3 表示到期前 7 天和 3 天提醒。留空则不提醒。', 'qilingshop'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('宽限期天数', 'qilingshop'); ?></th>
                    <td>
                        <input type="number" name="vip_grace_days" value="<?php echo esc_attr(get_option('qilingshop_vip_grace_days', 3)); ?>" min="0" max="30" step="1" class="qls-settings-input-120">
                        <p class="description"><?php _e('到期后在宽限期内续费仍按原到期日叠加；超过宽限期将自动失效。', 'qilingshop'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- 顶部 Banner 设置 -->
        <div class="qls-settings-group-vip">
            <h3><span>🖼️</span> <?php _e('顶部横幅区域', 'qilingshop'); ?></h3>
            <table class="form-table qls-ui-form-table qls-settings-table-no-margin">
                <tr>
                    <th scope="row"><?php _e('页面风格', 'qilingshop'); ?></th>
                    <td>
                        <select name="vip_style">
                            <option value="black-gold" <?php selected(get_option('qilingshop_vip_style'), 'black-gold'); ?>><?php _e('黑金尊贵 (默认)', 'qilingshop'); ?></option>
                            <option value="light" <?php selected(get_option('qilingshop_vip_style'), 'light'); ?>><?php _e('清新简约', 'qilingshop'); ?></option>
                            <option value="blue" <?php selected(get_option('qilingshop_vip_style'), 'blue'); ?>><?php _e('科技蓝', 'qilingshop'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('主标题', 'qilingshop'); ?></th>
                    <td><input type="text" name="vip_hero_title" value="<?php echo esc_attr(get_option('qilingshop_vip_hero_title', __('开通 VIP 会员', 'qilingshop'))); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><?php _e('副标题', 'qilingshop'); ?></th>
                    <td><input type="text" name="vip_hero_subtitle" value="<?php echo esc_attr(get_option('qilingshop_vip_hero_subtitle', __('解锁全站资源，享受尊贵特权', 'qilingshop'))); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><?php _e('按钮文案', 'qilingshop'); ?></th>
                    <td><input type="text" name="vip_hero_btn" value="<?php echo esc_attr(get_option('qilingshop_vip_hero_btn', __('立即加入', 'qilingshop'))); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><?php _e('背景图片链接', 'qilingshop'); ?></th>
                    <td>
                        <input type="text" name="vip_hero_bg" value="<?php echo esc_attr(get_option('qilingshop_vip_hero_bg', '')); ?>" class="large-text">
                        <p class="description"><?php _e('留空则使用默认渐变背景。建议使用深色系大图。', 'qilingshop'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- 核心权益 -->
        <div class="qls-settings-group-vip">
            <h3><span>💎</span> <?php _e('核心权益介绍', 'qilingshop'); ?></h3>
            <p class="description qls-settings-margin-bottom-15"><?php _e('配置核心卖点数量，展示在横幅下方。', 'qilingshop'); ?></p>
            <div class="qls-field-row">
                <label><?php _e('展示数量', 'qilingshop'); ?></label>
                <?php $vip_benefit_count = max(1, min(intval(get_option('qilingshop_vip_benefit_count', 4)), 12)); ?>
                <input type="number" name="vip_benefit_count" value="<?php echo esc_attr($vip_benefit_count); ?>" min="1" max="12" step="1" class="qls-settings-input-120">
                <span class="description qls-settings-inline-tip"><?php _e('建议 4-8 个(改变数量之后先保存)', 'qilingshop'); ?></span>
            </div>

            <div class="qls-cols-4">
                <?php
                $benefit_defaults = $this->get_vip_landing_benefit_defaults();
                for ($i = 1; $i <= $vip_benefit_count; $i++) {
                    $def = $benefit_defaults[$i] ?? ['icon' => '', 'title' => '', 'desc' => ''];
                    $this->render_vip_benefit_item($i, $def);
                }
                ?>
            </div>
        </div>

        <!-- 权益对比表 -->
        <div class="qls-settings-group-vip">
            <h3><span>⚖️</span> <?php _e('权益对比表', 'qilingshop'); ?></h3>
            <p class="description"><?php _e('设置对比项行数，用于展示普通用户与VIP用户的区别。支持基础排版。', 'qilingshop'); ?></p>
            <div class="qls-field-row qls-field-row-top-gap">
                <label><?php _e('展示行数', 'qilingshop'); ?></label>
                <?php $vip_compare_count = max(1, min(intval(get_option('qilingshop_vip_compare_count', 5)), 20)); ?>
                <input type="number" name="vip_compare_count" value="<?php echo esc_attr($vip_compare_count); ?>" min="1" max="20" step="1" class="qls-settings-input-120">
                <span class="description qls-settings-inline-tip"><?php _e('可配置更多对比项(改变数量之后先保存)', 'qilingshop'); ?></span>
            </div>
            
            <table class="wp-list-table qls-ui-table widefat fixed striped qls-vip-compare-table">
                <thead>
                    <tr>
                        <th width="30%"><?php _e('功能特性', 'qilingshop'); ?></th>
                        <th width="35%"><?php _e('普通用户', 'qilingshop'); ?></th>
                        <th width="35%"><?php _e('VIP 会员', 'qilingshop'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $compare_defaults = $this->get_vip_compare_defaults();
                    for ($i = 1; $i <= $vip_compare_count; $i++) {
                        $def = $compare_defaults[$i] ?? ['name' => '', 'free' => '', 'vip' => ''];
                        $this->render_vip_compare_row($i, $def);
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <!-- 常见问题 -->
        <div class="qls-settings-group-vip">
            <h3><span>❓</span> <?php _e('常见问题', 'qilingshop'); ?></h3>
            
            <?php
            $faq_defaults = $this->get_vip_faq_defaults();
            for ($i = 1; $i <= 4; $i++) {
                $def_q = isset($faq_defaults[$i]['q']) ? $faq_defaults[$i]['q'] : '';
                $def_a = isset($faq_defaults[$i]['a']) ? $faq_defaults[$i]['a'] : '';
                $this->render_vip_faq_item($i, $def_q, $def_a);
            }
            ?>
        </div>
        <?php
    }
}
