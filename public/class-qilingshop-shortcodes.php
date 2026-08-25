<?php
/**
 * 短代码
 */
if (!defined('ABSPATH')) exit;

class QilingShop_Shortcodes {
    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_shortcode('qilingshop_user_center', [$this, 'user_center']);
        add_shortcode('qilingshop_recharge', [$this, 'recharge_form']);
        add_shortcode('qilingshop_vip', [$this, 'vip_page']);
        add_shortcode('qilingshop_orders', [$this, 'orders_list']);
        add_shortcode('qilingshop_order_query', [$this, 'order_query_page']);
        add_shortcode('qilingshop_downloads', [$this, 'downloads_list']);
        add_shortcode('qilingshop_invite', [$this, 'invite_page']);
        add_shortcode('qilingshop_checkin', [$this, 'checkin_button']);
        add_shortcode('qilingshop_hidden', [$this, 'hidden_content']);
        add_shortcode('qilingshop_vip_only', [$this, 'vip_only_content']);
        
        // Modules
        add_shortcode('qls_product_list', [$this, 'product_list']);
        
        // 优惠券中心
        add_shortcode('qls_coupon_center', [$this, 'coupon_center']);
    }

    /**
     * 用户中心
     */
    public function user_center($atts) {
        if (!is_user_logged_in()) {
            return '<p>' . sprintf(__('请先<a href="%s">登录</a>', 'qilingshop'), wp_login_url(get_permalink())) . '</p>';
        }

        $user_id = get_current_user_id();
        $points = QilingShop_Points::instance();
        $vip = QilingShop_VIP::instance();
        $user_info = $points->get_user_info($user_id);
        $invite_stats = QilingShop_Affiliate::instance()->get_invite_stats($user_id);

        ob_start();
        ?>
        <div class="qilingshop-user-center">
            <div class="user-header">
                <div class="user-avatar"><?php echo get_avatar($user_id, 80); ?></div>
                <div class="user-info">
                    <h3><?php echo esc_html(wp_get_current_user()->display_name); ?></h3>
                    <span class="vip-badge" style="background:<?php echo $vip->get_user_level_info($user_id)->badge_color ?? '#999'; ?>"><?php echo esc_html($vip->get_user_level_name($user_id)); ?></span>
                    <?php if ($vip->get_user_level($user_id) > 0): ?>
                        <span class="vip-expires"><?php echo sprintf(__('到期：%s', 'qilingshop'), $vip->get_user_expires($user_id)); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="user-stats">
                <div class="stat-item">
                    <span class="stat-value"><?php echo qilingshop_format_points($user_info->points_balance, false); ?></span>
                    <span class="stat-label"><?php echo qilingshop_get_points_name(); ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-value"><?php echo $invite_stats['total_invites']; ?></span>
                    <span class="stat-label"><?php _e('邀请人数', 'qilingshop'); ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-value"><?php echo qilingshop_format_price($invite_stats['total_earnings']); ?></span>
                    <span class="stat-label"><?php _e('推广收益', 'qilingshop'); ?></span>
                </div>
            </div>

            <?php
            if (class_exists('QilingShop_Growth')) {
                echo QilingShop_Growth::instance()->render_summary_card($user_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
            ?>

            <div class="user-actions">
                <a href="#recharge" class="btn-primary"><?php _e('充值', 'qilingshop'); ?></a>
                <a href="#vip" class="btn-secondary"><?php _e('升级VIP', 'qilingshop'); ?></a>
                <button class="btn-checkin" data-action="checkin"><?php echo $points->has_checked_in_today($user_id) ? __('已签到', 'qilingshop') : __('签到', 'qilingshop'); ?></button>
            </div>

            <div class="user-invite">
                <h4><?php _e('我的邀请链接', 'qilingshop'); ?></h4>
                <input type="text" readonly value="<?php echo esc_attr($points->get_invite_url($user_id)); ?>" onclick="this.select()">
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * 充值表单
     */
    public function recharge_form($atts) {
        if (!is_user_logged_in()) {
            return '<p>' . sprintf(__('请先<a href="%s">登录</a>', 'qilingshop'), wp_login_url(get_permalink())) . '</p>';
        }

        $gateways = QilingShop_Payment::instance()->get_enabled_gateways();
        unset($gateways['wechat_miniapp']);
        $ratio = qilingshop_get_points_ratio();
        $min = get_option('qilingshop_recharge_min_amount', 1);
        $max = get_option('qilingshop_recharge_max_amount', 10000);
        $points_name = qilingshop_get_points_name();
        
        // 获取充值奖励规则
        $bonus_rules = QilingShop_Recharge::instance()->get_bonus_rules();

        ob_start();
        ?>
        <div class="qilingshop-recharge">
            <h3><?php _e('积分充值', 'qilingshop'); ?></h3>
            <p class="recharge-tips"><?php echo sprintf(__('1元 = %d %s', 'qilingshop'), $ratio, $points_name); ?></p>
            
            <?php if (!empty($bonus_rules)): ?>
            <div class="recharge-bonus-rules">
                <h4>🎁 <?php _e('充值奖励', 'qilingshop'); ?></h4>
                <ul class="bonus-rules-list">
                    <?php foreach ($bonus_rules as $rule): ?>
                    <li class="bonus-rule-item" data-min="<?php echo $rule->min_amount; ?>" data-max="<?php echo $rule->max_amount ?: 999999999; ?>" data-type="<?php echo $rule->bonus_type; ?>" data-value="<?php echo $rule->bonus_value; ?>">
                        <?php 
                        // 显示金额区间
                        if ($rule->max_amount) {
                            $range = sprintf(__('充值 ¥%s - ¥%s', 'qilingshop'), number_format($rule->min_amount), number_format($rule->max_amount));
                        } else {
                            $range = sprintf(__('充值 ¥%s 以上', 'qilingshop'), number_format($rule->min_amount));
                        }
                        
                        // 显示奖励
                        if ($rule->bonus_type === 'fixed') {
                            $bonus_text = sprintf(__('送 %s %s', 'qilingshop'), number_format($rule->bonus_value * $ratio), $points_name);
                        } else {
                            $bonus_text = sprintf(__('额外送 %s%%', 'qilingshop'), $rule->bonus_value);
                        }
                        
                        echo '<span class="bonus-range">' . esc_html($range) . '</span>';
                        echo '<span class="bonus-reward">' . esc_html($bonus_text) . '</span>';
                        if ($rule->description) {
                            echo '<span class="bonus-desc">(' . esc_html($rule->description) . ')</span>';
                        }
                        ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            
            <div class="recharge-form">
                <label><?php _e('充值金额', 'qilingshop'); ?></label>
                <input type="number" id="recharge-amount" min="<?php echo $min; ?>" max="<?php echo $max; ?>" step="1" value="<?php echo $min; ?>">
                
                <div class="quick-amounts">
                    <?php foreach ([10, 50, 100, 200, 500] as $amt): ?>
                    <button type="button" data-amount="<?php echo $amt; ?>"><?php printf(esc_html__('%s元', 'qilingshop'), esc_html($amt)); ?></button>
                    <?php endforeach; ?>
                </div>
                
                <div class="points-preview">
                    <?php _e('可获得', 'qilingshop'); ?>：
                    <span id="points-base"><?php echo $min * $ratio; ?></span> <?php echo $points_name; ?>
                    <span id="points-bonus-text" style="display:none; color:#ff6600; margin-left:5px;">
                        + <span id="points-bonus">0</span> <?php echo $points_name; ?> <?php _e('(奖励)', 'qilingshop'); ?>
                    </span>
                    <span id="points-total-wrap" style="display:none; margin-left:10px; font-weight:bold;">
                        = <span id="points-total"><?php echo $min * $ratio; ?></span> <?php echo $points_name; ?>
                    </span>
                </div>

                <label><?php _e('支付方式', 'qilingshop'); ?></label>
                <div class="payment-methods">
                    <?php foreach ($gateways as $key => $gateway): ?>
                    <label><input type="radio" name="gateway" value="<?php echo $key; ?>" <?php echo key($gateways) === $key ? 'checked' : ''; ?>> <?php echo $gateway['name']; ?></label>
                    <?php endforeach; ?>
                </div>

                <button type="button" class="btn-recharge" data-action="recharge"><?php _e('立即充值', 'qilingshop'); ?></button>
            </div>
        </div>
        
        <script>
        (function() {
            var ratio = <?php echo $ratio; ?>;
            var rules = <?php echo json_encode(array_map(function($r) {
                return [
                    'min' => floatval($r->min_amount),
                    'max' => $r->max_amount ? floatval($r->max_amount) : null,
                    'type' => $r->bonus_type,
                    'value' => floatval($r->bonus_value)
                ];
            }, $bonus_rules)); ?>;
            
            function calculateBonus(amount) {
                for (var i = 0; i < rules.length; i++) {
                    var rule = rules[i];
                    if (amount >= rule.min && (rule.max === null || amount <= rule.max)) {
                        if (rule.type === 'fixed') {
                            return rule.value * ratio;
                        } else {
                            return amount * ratio * rule.value / 100;
                        }
                    }
                }
                return 0;
            }
            
            function updatePreview() {
                var amount = parseFloat(document.getElementById('recharge-amount').value) || 0;
                var basePoints = amount * ratio;
                var bonusPoints = calculateBonus(amount);
                var totalPoints = basePoints + bonusPoints;
                
                document.getElementById('points-base').textContent = Math.floor(basePoints);
                document.getElementById('points-bonus').textContent = Math.floor(bonusPoints);
                document.getElementById('points-total').textContent = Math.floor(totalPoints);
                
                // Also update old points-preview for compatibility
                var oldPreview = document.getElementById('points-preview');
                if (oldPreview) oldPreview.textContent = Math.floor(totalPoints);
                
                if (bonusPoints > 0) {
                    document.getElementById('points-bonus-text').style.display = 'inline';
                    document.getElementById('points-total-wrap').style.display = 'inline';
                    
                    // Highlight matching rule
                    document.querySelectorAll('.bonus-rule-item').forEach(function(item) {
                        item.classList.remove('active');
                        var min = parseFloat(item.dataset.min);
                        var max = parseFloat(item.dataset.max);
                        if (amount >= min && amount <= max) {
                            item.classList.add('active');
                        }
                    });
                } else {
                    document.getElementById('points-bonus-text').style.display = 'none';
                    document.getElementById('points-total-wrap').style.display = 'none';
                    document.querySelectorAll('.bonus-rule-item').forEach(function(item) {
                        item.classList.remove('active');
                    });
                }
            }
            
            document.getElementById('recharge-amount').addEventListener('input', updatePreview);
            updatePreview();
        })();
        </script>
        
        <style>
        .recharge-bonus-rules {
            background: linear-gradient(135deg, #fff9e6 0%, #fff3cc 100%);
            border: 1px solid #ffd54f;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .recharge-bonus-rules h4 {
            margin: 0 0 10px;
            color: #ff8f00;
            font-size: 15px;
        }
        .bonus-rules-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .bonus-rule-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 12px;
            margin: 5px 0;
            background: #fff;
            border-radius: 6px;
            border: 1px solid #eee;
            transition: all 0.2s;
        }
        .bonus-rule-item.active {
            border-color: #ff6600;
            background: #fff5f0;
        }
        .bonus-range {
            color: #333;
        }
        .bonus-reward {
            color: #ff6600;
            font-weight: bold;
        }
        .bonus-desc {
            color: #999;
            font-size: 12px;
        }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * VIP 升级页面
     */
    public function vip_page($atts) {
        $levels = QilingShop_VIP::instance()->get_levels();
        $user_level = is_user_logged_in() ? QilingShop_VIP::instance()->get_user_level() : 0;

        ob_start();
        ?>
        <div class="qilingshop-vip-page">
            <h3><?php _e('VIP 会员', 'qilingshop'); ?></h3>
            <div class="vip-levels">
                <?php foreach ($levels as $level): ?>
                <div class="vip-level-card <?php echo $user_level == $level->id ? 'current' : ''; ?>">
                    <h4 style="color:<?php echo $level->badge_color; ?>"><?php echo esc_html($level->level_name); ?></h4>
                    <div class="level-price">
                        <span class="price"><?php echo qilingshop_format_price($level->price); ?></span>
                        <?php if ($level->original_price): ?>
                        <span class="original"><?php echo qilingshop_format_price($level->original_price); ?></span>
                        <?php endif; ?>
                    </div>
                    <ul class="level-features">
                        <li><?php echo $level->duration_days >= 999999 ? __('永久有效', 'qilingshop') : sprintf(__('%d天有效', 'qilingshop'), $level->duration_days); ?></li>
                        <li><?php echo $level->discount_rate == 0 ? __('全部免费', 'qilingshop') : sprintf(__('%d%%折扣', 'qilingshop'), $level->discount_rate); ?></li>
                        <li><?php echo $level->can_download_free ? __('免费下载大部分资源', 'qilingshop') : __('享受专属折扣', 'qilingshop'); ?></li>
                    </ul>
                    <?php if ($user_level == $level->id): ?>
                        <button disabled><?php _e('当前等级', 'qilingshop'); ?></button>
                    <?php elseif (is_user_logged_in()): ?>
                        <button class="btn-buy-vip" data-level="<?php echo $level->id; ?>"><?php _e('立即开通', 'qilingshop'); ?></button>
                    <?php else: ?>
                        <a href="<?php echo wp_login_url(get_permalink()); ?>"><?php _e('登录购买', 'qilingshop'); ?></a>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * 订单列表
     */
    public function orders_list($atts) {
        if (!is_user_logged_in()) return '<p>' . __('请先登录', 'qilingshop') . '</p>';
        
        $orders = QilingShop_Order::instance()->get_user_orders(get_current_user_id(), ['limit' => 20]);
        
        ob_start();
        ?>
        <div class="qilingshop-orders">
            <h3><?php _e('我的订单', 'qilingshop'); ?></h3>
            <table class="orders-table">
                <thead><tr><th><?php _e('订单号', 'qilingshop'); ?></th><th><?php _e('资源', 'qilingshop'); ?></th><th><?php _e('金额', 'qilingshop'); ?></th><th><?php _e('状态', 'qilingshop'); ?></th><th><?php _e('时间', 'qilingshop'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($orders as $o): ?>
                <tr>
                    <td><?php echo esc_html($o->order_no); ?></td>
                    <td><a href="<?php echo get_permalink($o->post_id); ?>"><?php echo esc_html($o->post_title); ?></a></td>
                    <td><?php echo $o->price_points > 0 ? qilingshop_format_points($o->price_points) : qilingshop_format_price($o->price_rmb); ?></td>
                    <td><?php echo QilingShop_Order::instance()->get_status_text($o->status); ?></td>
                    <td><?php echo esc_html($o->created_at); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * 订单查询页（游客）
     */
    public function order_query_page($atts) {
        if (is_admin()) {
            return '<div class="qls-shortcode-placeholder" style="padding:20px;background:#f0f0f1;text-align:center;border:1px dashed #ccc;">[qilingshop_order_query] ' . __('订单查询', 'qilingshop') . '</div>';
        }

        $prefill_order_no = isset($_GET['order_no']) ? strtoupper(sanitize_text_field(wp_unslash($_GET['order_no']))) : '';
        if (!preg_match('/^[A-Z0-9]{0,64}$/', $prefill_order_no)) {
            $prefill_order_no = '';
        }
        $password_lookup_enabled = (bool) get_option('qls_shop_guest_query_password_enabled', false);

        ob_start();
        ?>
        <div class="qls-shop-wrapper qls-order-query-page">
            <div class="qls-container">
                <div class="qilingshop-order-query qls-cart-wrapper">
                    <div class="qilingshop-order-query-header">
                        <div>
                            <span class="qilingshop-order-query-eyebrow"><?php _e('游客查单', 'qilingshop'); ?></span>
                            <h3><?php _e('订单查询', 'qilingshop'); ?></h3>
                            <p>
                                <?php
                                echo $password_lookup_enabled
                                    ? esc_html__('输入购买时填写的手机号或邮箱，可通过订单号或查询密码查询游客订单。', 'qilingshop')
                                    : esc_html__('输入订单号和购买时填写的手机号或邮箱，可查询游客订单状态。', 'qilingshop');
                                ?>
                            </p>
                        </div>
                        <div class="qilingshop-order-query-pills" aria-hidden="true">
                            <span><?php _e('状态', 'qilingshop'); ?></span>
                            <span><?php _e('卡密', 'qilingshop'); ?></span>
                            <span><?php _e('支付', 'qilingshop'); ?></span>
                        </div>
                    </div>

                    <form class="qilingshop-order-query-form <?php echo $password_lookup_enabled ? 'has-query-password' : 'has-order-number-only'; ?>" autocomplete="off">
                        <div class="qilingshop-order-query-field">
                            <label for="qilingshop-order-query-no">
                                <?php echo $password_lookup_enabled ? esc_html__('订单号（可选）', 'qilingshop') : esc_html__('订单号', 'qilingshop'); ?>
                            </label>
                            <input
                                id="qilingshop-order-query-no"
                                type="text"
                                name="order_no"
                                maxlength="64"
                                placeholder="<?php esc_attr_e('例如：SHOP202603051234ABCD', 'qilingshop'); ?>"
                                value="<?php echo esc_attr($prefill_order_no); ?>"
                                <?php echo $password_lookup_enabled ? '' : 'required'; ?>
                            >
                        </div>
                        <div class="qilingshop-order-query-field">
                            <label for="qilingshop-order-query-contact"><?php _e('手机号或邮箱', 'qilingshop'); ?></label>
                            <input
                                id="qilingshop-order-query-contact"
                                type="text"
                                name="contact"
                                maxlength="120"
                                placeholder="<?php esc_attr_e('请输入购买时填写的手机号或邮箱', 'qilingshop'); ?>"
                                required
                            >
                        </div>
                        <?php if ($password_lookup_enabled): ?>
                        <div class="qilingshop-order-query-field">
                            <label for="qilingshop-order-query-password"><?php _e('查询密码', 'qilingshop'); ?></label>
                            <input
                                id="qilingshop-order-query-password"
                                type="password"
                                name="query_password"
                                maxlength="64"
                                autocomplete="current-password"
                                placeholder="<?php esc_attr_e('购买发卡商品时设置的查询密码', 'qilingshop'); ?>"
                            >
                        </div>
                        <?php endif; ?>
                        <button type="submit" class="qilingshop-order-query-submit qls-btn qls-btn-primary"><?php _e('查询订单', 'qilingshop'); ?></button>
                    </form>

                    <div class="qilingshop-order-query-note">
                        <?php
                        echo $password_lookup_enabled
                            ? esc_html__('可使用“订单号 + 联系方式”，或“联系方式 + 查询密码”查询发卡游客订单。账号订单请登录后到个人中心查看。', 'qilingshop')
                            : esc_html__('仅支持游客订单查询。账号订单请登录后到个人中心查看。', 'qilingshop');
                        ?>
                    </div>

                    <div class="qilingshop-order-query-result" aria-live="polite"></div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * 下载列表
     */
    public function downloads_list($atts) {
        if (!is_user_logged_in()) return '<p>' . __('请先登录', 'qilingshop') . '</p>';
        
        $downloads = QilingShop_Resource::instance()->get_user_downloads(get_current_user_id(), ['limit' => 20]);
        
        ob_start();
        ?>
        <div class="qilingshop-downloads">
            <h3><?php _e('我的下载', 'qilingshop'); ?></h3>
            <ul class="downloads-list">
            <?php foreach ($downloads as $d): ?>
                <li><a href="<?php echo get_permalink($d->post_id); ?>"><?php echo get_the_title($d->post_id); ?></a> - <?php echo qilingshop_human_time_diff($d->created_at); ?></li>
            <?php endforeach; ?>
            </ul>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * 邀请页面
     */
    public function invite_page($atts) {
        if (!is_user_logged_in()) return '<p>' . __('请先登录', 'qilingshop') . '</p>';
        
        $user_id = get_current_user_id();
        $stats = QilingShop_Affiliate::instance()->get_invite_stats($user_id);
        $list = QilingShop_Affiliate::instance()->get_invite_list($user_id, ['limit' => 10]);
        $url = QilingShop_Points::instance()->get_invite_url($user_id);
        
        ob_start();
        ?>
        <div class="qilingshop-invite">
            <h3><?php _e('邀请好友', 'qilingshop'); ?></h3>
            <div class="invite-url">
                <input type="text" readonly value="<?php echo esc_attr($url); ?>" onclick="this.select()">
            </div>
            <div class="invite-stats">
                <span><?php _e('邀请人数：', 'qilingshop'); ?><?php echo $stats['total_invites']; ?></span>
                <span><?php _e('推广收益：', 'qilingshop'); ?><?php echo qilingshop_format_price($stats['total_earnings']); ?></span>
            </div>
            <h4><?php _e('邀请记录', 'qilingshop'); ?></h4>
            <ul>
            <?php foreach ($list as $item): ?>
                <li><?php echo esc_html($item->user_login ?: $item->invitee_id); ?> - <?php echo qilingshop_human_time_diff($item->created_at); ?></li>
            <?php endforeach; ?>
            </ul>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * 签到按钮
     */
    public function checkin_button($atts) {
        if (!is_user_logged_in()) return '';
        
        $checked = QilingShop_Points::instance()->has_checked_in_today(get_current_user_id());
        return '<button class="qilingshop-checkin-btn ' . ($checked ? 'checked' : '') . '" data-action="checkin">' . 
               ($checked ? __('已签到', 'qilingshop') : __('签到', 'qilingshop')) . '</button>';
    }

    /**
     * 隐藏内容
     */
    public function hidden_content($atts, $content = '') {
        global $post;
        
        if (!$post) return '';
        
        $resource = QilingShop_Resource::instance();
        $price = $resource->get_points_price($post->ID, 'view');
        $sale_mode = $resource->get_sale_mode($post->ID);
        $user_id = get_current_user_id();
        $context = 'view';
        
        if ($resource->is_vip_only_access($post->ID, $context) && (!$user_id || !$resource->has_vip_access($post->ID, $user_id, $context))) {
            return '<div class="qilingshop-hidden-placeholder">' . __('此内容仅限 VIP 查看', 'qilingshop') . '</div>';
        }
        
        if ($sale_mode === 'free') {
            return do_shortcode($content);
        }
        
        if ($price <= 0 && $user_id) {
            return do_shortcode($content);
        }
        
        if ($user_id && QilingShop_Order::instance()->user_has_purchased($post->ID, $user_id, false, 'view')) {
            return do_shortcode($content);
        }

        if (!$user_id && QilingShop_Guest::instance()->is_enabled()) {
            $guest_id = QilingShop_Guest::instance()->get_guest_id();
            if (QilingShop_Order::instance()->guest_has_purchased($post->ID, $guest_id, 'view')) {
                return do_shortcode($content);
            }
        }
        
        if ($user_id && $resource->is_vip_free($post->ID, $user_id, $context)) {
            return do_shortcode($content);
        }
        
        if (!$user_id && $price <= 0 && $sale_mode !== 'free') {
            return '<div class="qilingshop-hidden-placeholder"><div>' . __('请先登录', 'qilingshop') . '</div><a href="' . esc_url(wp_login_url(get_permalink($post->ID))) . '" class="qls-download-content-tb-btn-login qls-login-trigger">' . __('立即登录', 'qilingshop') . '</a></div>';
        }
        
        return '<div class="qilingshop-hidden-placeholder">' . __('此内容需付费后查看', 'qilingshop') . '</div>';
    }

    /**
     * VIP 专属内容
     */
    /**
     * VIP 专属内容
     */
    public function vip_only_content($atts, $content = '') {
        $atts = shortcode_atts(['level' => 1], $atts);
        
        if (qilingshop_is_vip(null, $atts['level'])) {
            return do_shortcode($content);
        }
        
        return '<div class="qilingshop-vip-placeholder">' . __('此内容仅限 VIP 查看', 'qilingshop') . '</div>';
    }

    /**
     * 商品列表模块
     */
    public function product_list($atts) {
        if (!class_exists('QLS_Module_Product_List')) {
            require_once QILINGSHOP_PATH . 'includes/shop/modules/class-qls-module-product-list.php';
        }
        $module = new QLS_Module_Product_List();
        return $module->render($atts);
    }

    /**
     * 优惠券中心
     */
    public function coupon_center($atts) {
        if (is_admin()) {
            return '<div class="qls-shortcode-placeholder" style="padding:20px;background:#f0f0f1;text-align:center;border:1px dashed #ccc;">[qls_coupon_center] ' . __('优惠券中心', 'qilingshop') . '</div>';
        }

        // 隐藏主题页面头部（面包屑等），实现全屏效果
        add_filter('qiling_show_page_header', '__return_false');
        
        // 隐藏主题侧边栏
        add_filter('qiling_show_sidebar', '__return_false');
        
        // 禁止加载主题侧边栏CSS
        add_filter('developer_starter_load_sidebar_css', '__return_false');
        
        ob_start();
        include QILINGSHOP_PATH . 'templates/shop/coupon-center.php';
        return ob_get_clean();
    }
}
