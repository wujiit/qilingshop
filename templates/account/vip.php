<?php
/**
 * 个人中心 - VIP会员页面
 * 
 * 可用变量: $user_id, $current_user
 *
 * @package QilingShop
 */

if (!defined('ABSPATH')) exit;

// 加载优惠券资源（使用动态版本号）
$assets_version = function_exists('qilingshop_get_assets_version') ? qilingshop_get_assets_version() : QILINGSHOP_VERSION;
wp_enqueue_style('qls-coupon', QILINGSHOP_URL . 'static/css/coupon.css', [], $assets_version);
wp_enqueue_script('qls-coupon-selector', QILINGSHOP_URL . 'static/js/coupon-selector.js', [], $assets_version, true);
wp_localize_script('qls-coupon-selector', 'qlsCoupon', [
    'ajaxUrl' => admin_url('admin-ajax.php'),
    'nonce'   => wp_create_nonce('qls_coupon_nonce'),
    'currency' => '¥',
    'i18n' => qilingshop_get_coupon_selector_i18n(),
]);

$vip = QilingShop_VIP::instance();
$vip_status = $vip->get_user_vip_status($user_id);
$vip_level_info = $vip_status['level_info'];
$is_vip = ($vip_status['level_id'] > 0 && !$vip_status['is_expired']);
$vip_expires = $vip->format_expires_display($vip_status['expires']);
$discount_rate = $vip->get_discount_rate($user_id);
$vip_info = $is_vip && $vip_level_info ? [
    'is_vip' => true,
    'level' => $vip_level_info->id,
    'level_name' => $vip_level_info->level_name,
    'expire_time' => $vip_expires,
    'discount' => $discount_rate,
] : null;
$vip_levels = $vip->get_levels();
$points_name = qilingshop_get_points_name();
$allow_diff_upgrade = (bool) get_option('qilingshop_vip_diff_upgrade', true);
$current_level_id = $vip_status['level_id'];
$current_level_sort = $vip_level_info ? (int) $vip_level_info->sort_order : 0;
$current_level_price = $vip_level_info ? (float) $vip_level_info->price : 0;
$rule_calc_text = $allow_diff_upgrade
    ? __('差价=目标等级价-当前等级价（不按剩余天数补差价）', 'qilingshop')
    : __('升级按目标等级价计费（不按剩余天数补差价）', 'qilingshop');
$rule_stack_text = __('续费同等级在原到期日基础上叠加；升级从今日起计算；宽限期内续费仍按原到期日叠加', 'qilingshop');
$vip_badge_color = ($vip_level_info && !empty($vip_level_info->badge_color)) ? $vip_level_info->badge_color : '#f59e0b';
$vip_status_text = __('普通用户', 'qilingshop');
$vip_status_note = __('未开通会员', 'qilingshop');
if ($vip_status['level_id'] > 0) {
    if (!$vip_status['is_expired']) {
        $vip_status_text = __('已开通', 'qilingshop');
        $vip_status_note = __('享受会员权益中', 'qilingshop');
    } elseif ($vip_status['in_grace']) {
        $vip_status_text = __('宽限期', 'qilingshop');
        $vip_status_note = __('尽快续费以保留权益', 'qilingshop');
    } else {
        $vip_status_text = __('已过期', 'qilingshop');
        $vip_status_note = __('会员权益已暂停', 'qilingshop');
    }
}
$vip_expire_display = $vip_status['is_lifetime'] ? __('永久', 'qilingshop') : ($vip_status['expires'] ? date_i18n(__('Y年m月d日', 'qilingshop'), strtotime($vip_status['expires'])) : '-');
$vip_grace_display = ($vip_status['in_grace'] && $vip_status['grace_until']) ? date_i18n(__('Y年m月d日', 'qilingshop'), strtotime($vip_status['grace_until'])) : '';
$vip_download_text = __('无', 'qilingshop');
if ($vip_level_info) {
    if ((int) $vip_level_info->daily_download_limit === -1) {
        $vip_download_text = __('无限', 'qilingshop');
    } elseif ((int) $vip_level_info->daily_download_limit > 0) {
        $vip_download_text = sprintf(__('每日 %d 次', 'qilingshop'), (int) $vip_level_info->daily_download_limit);
    }
}
$vip_discount_text = '-';
if ($vip_level_info) {
    if ((int) $vip_level_info->discount_rate === 0) {
        $vip_discount_text = __('免费', 'qilingshop');
    } elseif ((int) $vip_level_info->discount_rate >= 100) {
        $vip_discount_text = __('无折扣', 'qilingshop');
    } else {
        $vip_discount_text = ($vip_level_info->discount_rate / 10) . __(' 折', 'qilingshop');
    }
}
$vip_log_page = isset($_GET['vlpaged']) ? max(1, intval($_GET['vlpaged'])) : 1;
$vip_log_per_page = 5;
$vip_logs = $vip->get_vip_log($user_id, [
    'limit'  => $vip_log_per_page,
    'offset' => ($vip_log_page - 1) * $vip_log_per_page,
]);
$vip_log_total = $vip->get_vip_log_count($user_id);
$vip_log_pages = ceil($vip_log_total / $vip_log_per_page);
$growth_enabled = class_exists('QilingShop_Growth')
    && QilingShop_Growth::instance()->is_enabled()
    && (bool) get_option('qilingshop_growth_frontend_display', true);
$growth = $growth_enabled ? QilingShop_Growth::instance() : null;
$growth_name = $growth ? $growth->get_growth_name() : __('成长值', 'qilingshop');
$growth_account = $growth ? $growth->get_user_growth($user_id) : null;
$growth_value = $growth_account ? (float) $growth_account->growth_value : 0;
$growth_level = ($growth && $growth_account) ? $growth->get_level((int) $growth_account->level_id) : null;
$growth_highest_level = ($growth && $growth_account && (bool) get_option('qilingshop_growth_show_highest_level', false)) ? $growth->get_highest_user_level($user_id) : null;
$growth_next_level = ($growth && $growth_account) ? $growth->get_next_level($growth_value) : null;
$growth_levels = $growth ? $growth->get_levels(true) : [];
$growth_logs = $growth ? $growth->get_logs(['user_id' => $user_id, 'limit' => 10]) : [];
$growth_level_name = $growth_level ? $growth_level->level_name : __('暂无等级', 'qilingshop');
$growth_level_color = $growth_level && !empty($growth_level->level_color) ? $growth_level->level_color : '#64748b';
$growth_progress = 100;
$growth_need_value = 0;
$growth_next_text = __('已达最高成长等级', 'qilingshop');

if ($growth_account && $growth_next_level) {
    $growth_current_min = $growth_level ? (float) $growth_level->min_growth : 0;
    $growth_next_min = max($growth_current_min + 1, (float) $growth_next_level->min_growth);
    $growth_progress = $growth_next_min > $growth_current_min
        ? (($growth_value - $growth_current_min) / ($growth_next_min - $growth_current_min)) * 100
        : 0;
    $growth_progress = max(0, min(100, $growth_progress));
    $growth_need_value = max(0, $growth_next_min - $growth_value);
    $growth_next_text = sprintf(__('距 %1$s 还差 %2$s %3$s', 'qilingshop'), $growth_next_level->level_name, number_format($growth_need_value, 2), $growth_name);
}

$growth_benefits_api = class_exists('QilingShop_Growth_Benefits') ? QilingShop_Growth_Benefits::instance() : null;
$growth_effective_summaries = ($growth_benefits_api && $growth_account) ? $growth_benefits_api->get_user_effective_summaries($user_id) : [];
$growth_level_benefits_map = [];
if ($growth_benefits_api && !empty($growth_levels)) {
    foreach ($growth_levels as $growth_level_item) {
        $growth_level_benefits_map[(int) $growth_level_item->id] = $growth_benefits_api->get_level_benefits((int) $growth_level_item->id, true);
    }
}

$growth_rules = class_exists('QilingShop_Growth_Rules') ? QilingShop_Growth_Rules::instance()->get_rules(['active_only' => true]) : [];
$growth_rules = array_slice((array) $growth_rules, 0, 8);
$growth_event_labels = [
    'daily_visit' => __('每日访问', 'qilingshop'),
    'checkin' => __('每日签到', 'qilingshop'),
    'resource_order_paid' => __('资源订单支付', 'qilingshop'),
    'shop_order_paid' => __('商城订单支付', 'qilingshop'),
    'review_submit' => __('提交评价', 'qilingshop'),
    'review_with_image' => __('带图评价', 'qilingshop'),
    'invite_registered' => __('邀请注册', 'qilingshop'),
    'task_completed' => __('任务完成', 'qilingshop'),
];
?>

<div class="qls-account-section">
    <h3 class="qls-section-title"><?php echo esc_html(get_option('qilingshop_vip_name', __('VIP会员', 'qilingshop'))); ?></h3>
    
    <!-- 当前VIP状态 -->
    <div class="qls-vip-status-card <?php echo $vip_info && $vip_info['is_vip'] ? 'is-vip' : 'not-vip'; ?>">
        <?php if ($vip_info && $vip_info['is_vip']): ?>
        <div class="qls-vip-icon">👑</div>
        <div class="qls-vip-info">
            <h4><?php echo esc_html($vip_info['level_name']); ?></h4>
            <?php
            $expire_display = $vip_info['expire_time'];
            if ($vip_status['is_lifetime']) {
                $expire_display = __('永久', 'qilingshop');
            } elseif (!empty($vip_info['expire_time'])) {
                $expire_display = date_i18n(__('Y年m月d日', 'qilingshop'), strtotime($vip_info['expire_time']));
            }
            ?>
            <p><?php echo sprintf(__('有效期至：%s', 'qilingshop'), esc_html($expire_display)); ?></p>
            <p class="qls-vip-discount"><?php echo sprintf(__('专享 %s 折优惠', 'qilingshop'), $vip_info['discount'] / 10); ?></p>
        </div>
        <?php else: ?>
        <div class="qls-vip-icon">🎖️</div>
        <div class="qls-vip-info">
            <h4><?php echo sprintf(__('您还不是%s', 'qilingshop'), esc_html(get_option('qilingshop_vip_name', __('VIP会员', 'qilingshop')))); ?></h4>
            <p><?php echo sprintf(__('开通%s享受专属折扣和特权', 'qilingshop'), esc_html(get_option('qilingshop_vip_name', __('VIP会员', 'qilingshop')))); ?></p>
        </div>
        <?php endif; ?>
    </div>

    <!-- VIP身份卡 -->
    <div class="qls-vip-identity-card" style="--vip-color: <?php echo esc_attr($vip_badge_color); ?>;">
        <div class="qls-vip-identity-header">
            <div class="qls-vip-identity-badge"><?php echo esc_html($vip_level_info ? $vip_level_info->level_name : __('普通用户', 'qilingshop')); ?></div>
            <div class="qls-vip-identity-text">
                <div class="qls-vip-identity-status"><?php echo esc_html($vip_status_text); ?></div>
                <div class="qls-vip-identity-note"><?php echo esc_html($vip_status_note); ?></div>
            </div>
        </div>
        <div class="qls-vip-identity-meta">
            <div class="qls-vip-identity-item">
                <span><?php _e('有效期至', 'qilingshop'); ?></span>
                <strong><?php echo esc_html($vip_expire_display); ?></strong>
                <?php if ($vip_grace_display): ?>
                    <em><?php echo sprintf(__('宽限至 %s', 'qilingshop'), esc_html($vip_grace_display)); ?></em>
                <?php endif; ?>
            </div>
            <div class="qls-vip-identity-item">
                <span><?php _e('专享折扣', 'qilingshop'); ?></span>
                <strong><?php echo esc_html($vip_discount_text); ?></strong>
            </div>
            <div class="qls-vip-identity-item">
                <span><?php _e('下载额度', 'qilingshop'); ?></span>
                <strong><?php echo esc_html($vip_download_text); ?></strong>
            </div>
            <div class="qls-vip-identity-item">
                <span><?php _e('VIP免费', 'qilingshop'); ?></span>
                <strong><?php echo ($vip_level_info && $vip_level_info->can_download_free) ? __('支持', 'qilingshop') : __('不支持', 'qilingshop'); ?></strong>
            </div>
        </div>
    </div>

    <?php ob_start(); ?>
    <?php if ($growth_enabled && $growth_account): ?>
    <!-- 成长体系 -->
    <div class="qls-growth-vip-panel" style="--growth-color: <?php echo esc_attr($growth_level_color); ?>;">
        <div class="qls-growth-vip-head">
            <div>
                <span class="qls-growth-vip-kicker"><?php _e('会员成长体系', 'qilingshop'); ?></span>
                <h4><?php echo esc_html($growth_level_name); ?></h4>
                <p><?php echo esc_html($growth_next_text); ?></p>
            </div>
            <div class="qls-growth-vip-score">
                <strong><?php echo esc_html(number_format($growth_value, 2)); ?></strong>
                <span><?php echo esc_html($growth_name); ?></span>
            </div>
        </div>
        <div class="qls-growth-vip-progress">
            <span style="width: <?php echo esc_attr(number_format($growth_progress, 2)); ?>%;"></span>
        </div>
        <div class="qls-growth-vip-meta">
            <div>
                <span><?php _e('当前等级', 'qilingshop'); ?></span>
                <strong><?php echo esc_html($growth_level_name); ?></strong>
            </div>
            <div>
                <span><?php _e('下一等级', 'qilingshop'); ?></span>
                <strong><?php echo esc_html($growth_next_level ? $growth_next_level->level_name : __('最高等级', 'qilingshop')); ?></strong>
            </div>
            <div>
                <span><?php _e('升级还差', 'qilingshop'); ?></span>
                <strong><?php echo esc_html($growth_next_level ? number_format($growth_need_value, 2) : '0.00'); ?></strong>
            </div>
            <?php if ($growth_highest_level): ?>
            <div>
                <span><?php _e('历史最高', 'qilingshop'); ?></span>
                <strong><?php echo esc_html($growth_highest_level->level_name); ?></strong>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($growth_effective_summaries)): ?>
        <div class="qls-growth-vip-effective">
            <span><?php _e('当前已生效权益', 'qilingshop'); ?></span>
            <ul>
                <?php foreach ($growth_effective_summaries as $summary): ?>
                    <li><?php echo esc_html($summary); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($growth_levels)): ?>
    <div class="qls-growth-vip-levels">
        <h4><?php _e('成长等级体系', 'qilingshop'); ?></h4>
        <div class="qls-growth-vip-level-grid">
            <?php foreach ($growth_levels as $level): ?>
                <?php
                $level_id = (int) $level->id;
                $level_color = !empty($level->level_color) ? (string) $level->level_color : '#64748b';
                $level_active = $growth_level && (int) $growth_level->id === $level_id;
                $level_reached = $growth_value >= (float) $level->min_growth;
                $level_max_text = ($level->max_growth === null || $level->max_growth === '') ? __('无上限', 'qilingshop') : number_format((float) $level->max_growth, 2);
                $level_benefits = $growth_level_benefits_map[$level_id] ?? [];
                ?>
                <article class="qls-growth-vip-level-card <?php echo $level_active ? 'is-active' : ($level_reached ? 'is-reached' : ''); ?>" style="--growth-level-color: <?php echo esc_attr($level_color); ?>;">
                    <div class="qls-growth-vip-level-title">
                        <span><?php echo esc_html($level->level_name); ?></span>
                        <?php if ($level_active): ?><em><?php _e('当前', 'qilingshop'); ?></em><?php endif; ?>
                    </div>
                    <p><?php echo esc_html(sprintf(__('%1$s - %2$s %3$s', 'qilingshop'), number_format((float) $level->min_growth, 2), $level_max_text, $growth_name)); ?></p>
                    <?php if (!empty($level->description)): ?>
                        <div class="qls-growth-vip-level-desc"><?php echo esc_html($level->description); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($level_benefits)): ?>
                        <ul class="qls-growth-vip-benefit-list">
                            <?php foreach (array_slice($level_benefits, 0, 4) as $benefit): ?>
                                <?php
                                $benefit_text = $growth_benefits_api ? $growth_benefits_api->describe_effective_benefit($benefit) : '';
                                if ($benefit_text === '') {
                                    $benefit_text = $benefit->display_title ?: $benefit->benefit_name;
                                }
                                ?>
                                <li><?php echo esc_html($benefit_text); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="qls-growth-vip-empty"><?php _e('暂无权益配置', 'qilingshop'); ?></div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="qls-growth-vip-bottom">
        <div class="qls-growth-vip-rules">
            <h4><?php _e('如何获得成长值', 'qilingshop'); ?></h4>
            <?php if (!empty($growth_rules)): ?>
                <div class="qls-growth-vip-rule-list">
                    <?php foreach ($growth_rules as $rule): ?>
                        <?php
                        $rule_event = sanitize_key((string) ($rule->trigger_event ?? ''));
                        $rule_amount = (float) ($rule->growth_amount ?? 0);
                        $rule_type = sanitize_key((string) ($rule->growth_type ?? 'fixed'));
                        $rule_amount_text = $rule_type === 'amount_rate'
                            ? sprintf(__('金额 × %s', 'qilingshop'), number_format($rule_amount, 2))
                            : sprintf(__('+%1$s %2$s', 'qilingshop'), number_format($rule_amount, 2), $growth_name);
                        ?>
                        <div class="qls-growth-vip-rule-item">
                            <span><?php echo esc_html($growth_event_labels[$rule_event] ?? $rule_event); ?></span>
                            <strong><?php echo esc_html($rule_amount_text); ?></strong>
                            <em><?php echo esc_html($rule->rule_name); ?></em>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="qls-growth-vip-empty"><?php _e('暂无已启用的成长规则', 'qilingshop'); ?></div>
            <?php endif; ?>
        </div>

        <div class="qls-growth-vip-logs">
            <h4><?php _e('最近成长记录', 'qilingshop'); ?></h4>
            <?php if (!empty($growth_logs)): ?>
                <div class="qls-growth-vip-log-list">
                    <?php foreach ($growth_logs as $log): ?>
                        <?php $log_amount = (float) $log->amount; ?>
                        <div class="qls-growth-vip-log-item">
                            <div>
                                <strong><?php echo esc_html($log->description ?: ($log->source ?? '')); ?></strong>
                                <span><?php echo esc_html(date_i18n('Y-m-d H:i', strtotime($log->created_at))); ?></span>
                            </div>
                            <em class="<?php echo $log_amount >= 0 ? 'is-plus' : 'is-minus'; ?>"><?php echo esc_html(($log_amount >= 0 ? '+' : '') . number_format($log_amount, 2)); ?></em>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="qls-growth-vip-empty"><?php _e('暂无成长记录', 'qilingshop'); ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php $growth_vip_html = ob_get_clean(); ?>

    <!-- VIP兑换码 -->
    <div class="qls-vip-redeem-card">
        <div class="qls-vip-redeem-title"><?php _e('VIP 兑换码', 'qilingshop'); ?></div>
        <div class="qls-vip-redeem-form">
            <input type="text" id="qls-vip-redeem-code" placeholder="<?php _e('请输入兑换码', 'qilingshop'); ?>">
            <button type="button" id="qls-vip-redeem-btn" class="qls-btn qls-btn-primary"><?php _e('立即兑换', 'qilingshop'); ?></button>
        </div>
        <div class="qls-vip-redeem-tip"><?php _e('已是会员的用户不可使用兑换码', 'qilingshop'); ?></div>
    </div>

    <!-- VIP等级体系 -->
    <div class="qls-vip-tier-list">
        <h4><?php _e('VIP等级体系', 'qilingshop'); ?></h4>
        <div class="qls-vip-tier-grid">
            <?php foreach ($vip_levels as $level): 
                $tier_active = ($current_level_id === (int) $level->id && !$vip_status['is_expired']);
                $tier_color = !empty($level->badge_color) ? $level->badge_color : '#f59e0b';
                $tier_duration = $level->duration_days >= 999999 ? __('永久', 'qilingshop') : sprintf(__('%d天', 'qilingshop'), (int) $level->duration_days);
                if ((int) $level->discount_rate === 0) {
                    $tier_discount = __('免费', 'qilingshop');
                } elseif ((int) $level->discount_rate >= 100) {
                    $tier_discount = __('无折扣', 'qilingshop');
                } else {
                    $tier_discount = ($level->discount_rate / 10) . __(' 折', 'qilingshop');
                }
            ?>
            <div class="qls-vip-tier-item <?php echo $tier_active ? 'active' : ''; ?>" style="--tier-color: <?php echo esc_attr($tier_color); ?>;">
                <div class="qls-vip-tier-badge"><?php echo esc_html($level->level_name); ?></div>
                <div class="qls-vip-tier-meta">
                    <span><?php echo esc_html($tier_duration); ?></span>
                    <span><?php echo esc_html($tier_discount); ?></span>
                    <span><?php echo ((int) $level->daily_download_limit === -1) ? __('无限下载', 'qilingshop') : sprintf(__('每日 %d 次', 'qilingshop'), (int) $level->daily_download_limit); ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- VIP套餐列表 -->
    <div class="qls-vip-plans">
        <h4><?php _e('选择VIP套餐', 'qilingshop'); ?></h4>
        <div class="qls-plans-grid">
            <?php foreach ($vip_levels as $level): ?>
            <?php
                $level_price = isset($level->price) ? (float) $level->price : 0;
                $is_current = $is_vip && $current_level_id == $level->id;
                $is_same_level = ($current_level_id > 0 && $current_level_id == $level->id);
                $is_upgrade = ($current_level_id > 0 && $vip_level_info && (int) $level->sort_order > $current_level_sort);
                $pay_price = (float) $vip->calculate_upgrade_price($user_id, $level->id);
                if ($pay_price < 0) {
                    $pay_price = 0;
                }
                $upgrade_label = '';
                $show_origin_price = false;
                if ($allow_diff_upgrade && $is_upgrade) {
                    $upgrade_label = $pay_price > 0 ? __('补差价升级', 'qilingshop') : __('免费升级', 'qilingshop');
                    $show_origin_price = $level_price > $pay_price;
                } elseif ($is_upgrade) {
                    $upgrade_label = __('立即升级', 'qilingshop');
                }
                $preview_expires = $vip->calculate_new_expires($user_id, $level->id);
                if ($preview_expires && $vip->is_lifetime_expires($preview_expires)) {
                    $preview_expires_display = __('永久', 'qilingshop');
                } elseif ($preview_expires) {
                    $preview_expires_display = date_i18n(__('Y年m月d日', 'qilingshop'), strtotime($preview_expires));
                } else {
                    $preview_expires_display = '-';
                }
            ?>
            <div class="qls-plan-card" data-level="<?php echo esc_attr($level->id); ?>">
                <?php if (!empty($level->is_popular)): ?>
                <div class="qls-plan-badge"><?php _e('最受欢迎', 'qilingshop'); ?></div>
                <?php endif; ?>
                
                <div class="qls-plan-header">
                    <h5 class="qls-plan-name"><?php echo esc_html($level->level_name); ?></h5>
                    <p class="qls-plan-duration"><?php echo sprintf(__('%d天', 'qilingshop'), $level->duration_days); ?></p>
                </div>
                
                <div class="qls-plan-price">
                    <?php if ($pay_price > 0): ?>
                    <span class="qls-price-currency">¥</span>
                    <span class="qls-price-value"><?php echo esc_html($pay_price); ?></span>
                    <?php if ($show_origin_price): ?>
                        <span class="qls-price-origin">¥<?php echo esc_html($level_price); ?></span>
                    <?php endif; ?>
                    <?php else: ?>
                    <span class="qls-price-value"><?php _e('免费', 'qilingshop'); ?></span>
                    <?php if ($show_origin_price && $level_price > 0): ?>
                        <span class="qls-price-origin">¥<?php echo esc_html($level_price); ?></span>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php if ($upgrade_label && !$is_current): ?>
                <div class="qls-plan-upgrade-tag"><?php echo esc_html($upgrade_label); ?></div>
                <?php endif; ?>
                
                <div class="qls-plan-discount">
                    <?php 
                    if ($level->discount_rate == 0) {
                        _e('单独购买免费', 'qilingshop');
                    } else {
                        echo sprintf(__('单独购买 %s 折', 'qilingshop'), $level->discount_rate / 10);
                    }
                    ?>
                </div>
                
                <div class="qls-plan-features">
                    <?php if (isset($level->daily_download_limit) && $level->daily_download_limit > 0): ?>
                    <div class="qls-plan-feature">
                        <span class="feature-icon">📥</span>
                        <span class="feature-text"><?php echo sprintf(__('每日下载 %d 次', 'qilingshop'), $level->daily_download_limit); ?></span>
                    </div>
                    <?php elseif (isset($level->daily_download_limit) && $level->daily_download_limit == -1): ?>
                    <div class="qls-plan-feature">
                        <span class="feature-icon">📥</span>
                        <span class="feature-text"><?php _e('无限下载', 'qilingshop'); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($level->description)): 
                        $desc_lines = explode("\n", $level->description);
                        foreach ($desc_lines as $line):
                            $line = trim($line);
                            if (empty($line)) continue;
                    ?>
                    <div class="qls-plan-feature">
                        <span class="feature-icon">✨</span>
                        <span class="feature-text"><?php echo esc_html($line); ?></span>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
                
                <button class="qls-btn qls-btn-primary qls-btn-block qls-buy-vip-btn" 
                        data-level="<?php echo esc_attr($level->id); ?>"
                        data-price-rmb="<?php echo esc_attr($pay_price); ?>"
                        data-origin-rmb="<?php echo esc_attr($level_price); ?>"
                        data-upgrade-label="<?php echo esc_attr($upgrade_label); ?>"
                        data-rule-expires="<?php echo esc_attr($preview_expires_display); ?>"
                        data-rule-calc="<?php echo esc_attr($rule_calc_text); ?>"
                        data-rule-stack="<?php echo esc_attr($rule_stack_text); ?>">
                    <?php 
                    if ($is_current) {
                        _e('续费', 'qilingshop');
                    } elseif ($is_same_level) {
                        _e('续费', 'qilingshop');
                    } elseif ($upgrade_label) {
                        echo esc_html($upgrade_label);
                    } else {
                        _e('立即开通', 'qilingshop');
                    }
                    ?>
                </button>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- VIP权益 -->
    <div class="qls-vip-benefits">
        <h4><?php echo sprintf(__('%s权益', 'qilingshop'), esc_html(get_option('qilingshop_vip_name', __('VIP会员', 'qilingshop')))); ?></h4>
        <div class="qls-benefits-grid">
            <?php 
            $defaults = [
                1 => ['icon' => 'Discount', 'title' => __('专属折扣', 'qilingshop'), 'desc' => __('购买资源享受专属优惠', 'qilingshop')],
                2 => ['icon' => 'Present', 'title' => __('免费资源', 'qilingshop'), 'desc' => __('部分资源VIP免费下载', 'qilingshop')],
                3 => ['icon' => 'Trophy', 'title' => __('专属标识', 'qilingshop'), 'desc' => __('VIP尊贵身份标识', 'qilingshop')],
                4 => ['icon' => 'Service', 'title' => __('优先支持', 'qilingshop'), 'desc' => __('享受优先客服支持', 'qilingshop')],
            ];
            
            // 图标字段支持直接填写 emoji；默认图标名会映射为对应符号。
            
            for ($i = 1; $i <= 4; $i++):
                $icon = get_option('qilingshop_benefit_'.$i.'_icon', $defaults[$i]['icon']);
                $title = get_option('qilingshop_benefit_'.$i.'_title', $defaults[$i]['title']);
                $desc = get_option('qilingshop_benefit_'.$i.'_desc', $defaults[$i]['desc']);
                
                // 默认图标名称映射
                $display_icon = $icon;
                if ($icon == 'Discount') $display_icon = '💰';
                if ($icon == 'Present') $display_icon = '🎁';
                if ($icon == 'Trophy') $display_icon = '⭐';
                if ($icon == 'Service') $display_icon = '🚀';
            ?>
            <div class="qls-benefit-item">
                <?php echo qilingshop_render_icon($display_icon, 'qls-benefit-icon'); ?>
                <span class="qls-benefit-title"><?php echo esc_html($title); ?></span>
                <span class="qls-benefit-desc"><?php echo esc_html($desc); ?></span>
            </div>
            <?php endfor; ?>
        </div>
    </div>

    <?php echo $growth_vip_html; ?>

    <!-- VIP变更记录 -->
    <div class="qls-vip-log">
        <h4><?php _e('VIP变更记录', 'qilingshop'); ?></h4>
        <?php if (!empty($vip_logs)): ?>
        <div class="qls-orders-table-wrapper">
            <table class="qls-orders-table qls-vip-log-table">
                <thead>
                    <tr>
                        <th><?php _e('时间', 'qilingshop'); ?></th>
                        <th><?php _e('等级', 'qilingshop'); ?></th>
                        <th><?php _e('支付', 'qilingshop'); ?></th>
                        <th><?php _e('到期', 'qilingshop'); ?></th>
                        <th><?php _e('订单号', 'qilingshop'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vip_logs as $log):
                        if ($log->payment_type === 'points') {
                            $pay_amount = number_format((float) $log->price, 0) . ' ' . $points_name;
                        } elseif ($log->payment_type === 'code') {
                            $pay_amount = __('兑换码', 'qilingshop');
                        } elseif ($log->payment_type === 'coupon') {
                            $pay_amount = __('优惠券', 'qilingshop');
                        } else {
                            $pay_amount = '¥' . number_format((float) $log->price, 2);
                        }
                        $expires_text = $vip->is_lifetime_expires($log->expires_at) ? __('永久', 'qilingshop') : date_i18n('Y-m-d', strtotime($log->expires_at));
                    ?>
                    <tr>
                        <td data-label="<?php esc_attr_e('时间', 'qilingshop'); ?>"><?php echo date_i18n('Y-m-d H:i', strtotime($log->created_at)); ?></td>
                        <td data-label="<?php esc_attr_e('等级', 'qilingshop'); ?>"><?php echo esc_html($log->vip_level_name ?: $log->vip_level); ?></td>
                        <td data-label="<?php esc_attr_e('支付', 'qilingshop'); ?>"><?php echo esc_html($pay_amount); ?></td>
                        <td data-label="<?php esc_attr_e('到期', 'qilingshop'); ?>"><?php echo esc_html($expires_text); ?></td>
                        <td data-label="<?php esc_attr_e('订单号', 'qilingshop'); ?>"><?php echo esc_html($log->order_no ?: '-'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($vip_log_pages > 1): ?>
        <div class="qls-pagination">
            <?php
            $log_base = add_query_arg('tab', 'qls-vip', get_permalink());
            for ($i = 1; $i <= $vip_log_pages; $i++):
                $url = add_query_arg('vlpaged', $i, $log_base);
            ?>
            <a href="<?php echo esc_url($url); ?>" class="qls-page-link <?php echo $i === $vip_log_page ? 'active' : ''; ?>">
                <?php echo $i; ?>
            </a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <div class="qls-empty-state">
            <div class="qls-empty-icon">📝</div>
            <p><?php _e('暂无VIP变更记录', 'qilingshop'); ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- VIP购买支付方式选择弹窗 -->
<div id="qls-vip-modal" class="qls-modal" style="display:none;">
    <div class="qls-modal-overlay"></div>
    <div class="qls-modal-content">
        <div class="qls-modal-header">
            <h4><?php _e('选择支付方式', 'qilingshop'); ?></h4>
            <button class="qls-modal-close">×</button>
        </div>
        <div class="qls-modal-body">
        <div class="qls-vip-info qls-vip-modal-info">
            <div class="qls-vip-name qls-vip-modal-name" id="qls-vip-name"></div>
            <div class="qls-vip-price qls-vip-modal-price" id="qls-vip-price"></div>
            <div class="qls-vip-price-note qls-vip-modal-note" id="qls-vip-price-note" style="display:none;"></div>
            <div class="qls-vip-points-price qls-vip-modal-points-price" id="qls-vip-points-price"></div>
        </div>

            <div class="qls-vip-rule-box">
                <div class="qls-vip-rule-title"><?php _e('升级/续费规则说明与预览', 'qilingshop'); ?></div>
                <ul class="qls-vip-rule-list">
                    <li><span class="qls-vip-rule-label"><?php _e('升级后到期', 'qilingshop'); ?></span><span id="qls-vip-rule-expires">-</span></li>
                    <li><span class="qls-vip-rule-label"><?php _e('差价计算', 'qilingshop'); ?></span><span id="qls-vip-rule-calc"></span></li>
                    <li><span class="qls-vip-rule-label"><?php _e('叠加规则', 'qilingshop'); ?></span><span id="qls-vip-rule-stack"></span></li>
                </ul>
            </div>
            
            <?php 
            $user_balance = QilingShop_Points::instance()->get_balance($user_id);
            $points_ratio = qilingshop_get_points_ratio();
            ?>
            
            <!-- 优惠券选择 -->
            <div class="qls-coupon-section" id="qls-vip-coupon-section" data-order-type="vip">
                <label><?php _e('优惠券', 'qilingshop'); ?></label>
                <div class="qls-coupon-trigger" id="qls-vip-coupon-trigger">
                    <span class="dashicons dashicons-tickets-alt"></span>
                    <span class="qls-coupon-text"><?php _e('选择优惠券', 'qilingshop'); ?></span>
                    <span class="qls-coupon-selected" id="qls-vip-coupon-selected"></span>
                </div>
                <div class="qls-coupon-applied" id="qls-vip-coupon-applied" style="display:none;">
                    <div class="coupon-info">
                        <span class="coupon-name" id="qls-vip-coupon-name"></span>
                        <span class="coupon-discount" id="qls-vip-coupon-discount"></span>
                    </div>
                    <button type="button" class="qls-remove-coupon" id="qls-vip-remove-coupon">&times;</button>
                </div>
                <input type="hidden" id="qls-vip-coupon-claim-id" value="">
                <input type="hidden" id="qls-vip-coupon-discount-amount" value="0">
            </div>
            
            <div class="qls-payment-methods">
                <label><?php _e('支付方式', 'qilingshop'); ?></label>
                <div class="qls-payment-options">
                    <!-- 积分支付选项 -->
                    <label class="qls-payment-option" id="qls-points-option">
                        <input type="radio" name="vip_payment_method" value="points" checked>
                        <span class="qls-payment-label">💰 <?php printf(__('使用%s支付', 'qilingshop'), $points_name); ?></span>
                        <span class="qls-payment-balance qls-payment-balance-inline">
                            <?php printf(__('余额：%s %s', 'qilingshop'), number_format($user_balance), $points_name); ?>
                        </span>
                    </label>
                    
                    <?php if (get_option('qilingshop_direct_pay_enabled', true)): ?>
                    <div class="qls-payment-divider"><?php _e('或在线支付', 'qilingshop'); ?></div>
                    <?php
                    $alipay_enabled = (bool) get_option('qilingshop_alipay_enabled');
                    $alipay_f2f_enabled = (bool) get_option('qilingshop_alipay_f2fpay');
                    if ($alipay_enabled):
                        if ($alipay_f2f_enabled):
                    ?>
                    <label class="qls-payment-option">
                        <input type="radio" name="vip_payment_method" value="alipay_qr">
                        <span class="qls-payment-label"><img src="<?php echo QILINGSHOP_URL; ?>static/img/zfb.png" class="qls-payment-icon"> <?php _e('支付宝扫码', 'qilingshop'); ?></span>
                    </label>
                    <label class="qls-payment-option">
                        <input type="radio" name="vip_payment_method" value="alipay_page">
                        <span class="qls-payment-label"><img src="<?php echo QILINGSHOP_URL; ?>static/img/zfb.png" class="qls-payment-icon"> <?php _e('支付宝网页', 'qilingshop'); ?></span>
                    </label>
                    <?php else: ?>
                    <label class="qls-payment-option">
                        <input type="radio" name="vip_payment_method" value="alipay">
                        <span class="qls-payment-label"><img src="<?php echo QILINGSHOP_URL; ?>static/img/zfb.png" class="qls-payment-icon"> <?php _e('支付宝', 'qilingshop'); ?></span>
                    </label>
                    <?php endif; endif; ?>
                    
                    <?php if (get_option('qilingshop_wechat_enabled')): ?>
                    <label class="qls-payment-option">
                        <input type="radio" name="vip_payment_method" value="wechat">
                        <span class="qls-payment-label"><img src="<?php echo QILINGSHOP_URL; ?>static/img/wx.png" class="qls-payment-icon"> <?php _e('微信支付', 'qilingshop'); ?></span>
                    </label>
                    <?php endif; ?>

                    <?php if (get_option('qilingshop_xhpay_enabled')): ?>
                    <label class="qls-payment-option">
                        <input type="radio" name="vip_payment_method" value="xhpay">
                        <span class="qls-payment-label"><img src="<?php echo QILINGSHOP_URL; ?>static/img/zfb.png" class="qls-payment-icon"> <?php _e('虎皮椒 V3', 'qilingshop'); ?></span>
                    </label>
                    <?php endif; ?>

                    <?php if (get_option('qilingshop_epay_enabled')): ?>
                    <label class="qls-payment-option">
                        <input type="radio" name="vip_payment_method" value="epay">
                        <span class="qls-payment-label"><img src="<?php echo QILINGSHOP_URL; ?>static/img/zfb.png" class="qls-payment-icon"> <?php _e('易支付', 'qilingshop'); ?></span>
                    </label>
                    <?php endif; ?>
                    
                    <?php if (get_option('qilingshop_paypal_enabled')): ?>
                    <label class="qls-payment-option">
                        <input type="radio" name="vip_payment_method" value="paypal">
                        <span class="qls-payment-label"><img src="<?php echo QILINGSHOP_URL; ?>static/img/paypal.png" class="qls-payment-icon"> PayPal</span>
                    </label>
                    <?php endif; ?>

                    <?php if (get_option('qilingshop_stripe_enabled')): ?>
                    <label class="qls-payment-option">
                        <input type="radio" name="vip_payment_method" value="stripe">
                        <span class="qls-payment-label"><img src="<?php echo QILINGSHOP_URL; ?>static/img/stripe.png" class="qls-payment-icon"> <?php _e('Stripe', 'qilingshop'); ?></span>
                    </label>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="qls-modal-footer">
            <button id="qls-confirm-vip-buy" class="qls-btn qls-btn-primary qls-btn-block"><?php _e('确认支付', 'qilingshop'); ?></button>
        </div>
    </div>
</div>

<script>
(function() {
    var modal = document.getElementById('qls-vip-modal');
    var currentLevelId = null;
    var currentPrice = 0;
    var pointsRatio = <?php echo $points_ratio; ?>;
    var userBalance = <?php echo $user_balance; ?>;

    function qlsNotify(message, type) {
        var text = (message || '').toString();
        var level = type || 'info';
        if (window.QilingShopAccount && typeof window.QilingShopAccount.showMessage === 'function') {
            window.QilingShopAccount.showMessage(text, level);
            return;
        }
        var toast = document.createElement('div');
        toast.className = 'qls-toast ' + level + ' show';
        toast.textContent = text;
        document.body.appendChild(toast);
        window.setTimeout(function() {
            toast.classList.remove('show');
            window.setTimeout(function() {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 240);
        }, 2200);
    }
    
    // 计算优惠券折扣
    function calculateCouponDiscount(coupon, amount) {
        if (!coupon) return 0;
        var discount = 0;
        if (coupon.discountType === 'fixed') {
            discount = coupon.discountValue;
        } else {
            discount = amount * (coupon.discountValue / 100);
            if (coupon.maxDiscount > 0 && discount > coupon.maxDiscount) {
                discount = coupon.maxDiscount;
            }
        }
        // 只有当金额大于0时才限制折扣不超过金额
        if (amount > 0 && discount > amount) {
            discount = amount;
        }
        return Math.round(discount * 100) / 100;
    }
    
    // 重置优惠券状态
    function resetCouponState() {
        window.vipSelectedCoupon = null;
        document.getElementById('qls-vip-coupon-claim-id').value = '';
        document.getElementById('qls-vip-coupon-discount-amount').value = '0';
        document.getElementById('qls-vip-coupon-trigger').style.display = 'flex';
        document.getElementById('qls-vip-coupon-applied').style.display = 'none';
    }
    
    // 打开弹窗选择支付方式
    document.querySelectorAll('.qls-buy-vip-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            currentLevelId = this.dataset.level;
            currentPrice = parseFloat(this.dataset.priceRmb) || 0;
            var originPrice = parseFloat(this.dataset.originRmb) || 0;
            var upgradeLabel = this.dataset.upgradeLabel || '';
            
            // 重置优惠券
            resetCouponState();
            
            if (currentPrice >= 0) {
                // 找到对应的VIP名称
                var card = this.closest('.qls-plan-card');
                var name = card.querySelector('.qls-plan-name').textContent;
                var pointsPrice = currentPrice * pointsRatio;
                
                document.getElementById('qls-vip-name').textContent = name;
                document.getElementById('qls-vip-price').textContent = currentPrice > 0 ? ('¥' + currentPrice) : '<?php _e('免费', 'qilingshop'); ?>';
                document.getElementById('qls-vip-points-price').textContent = currentPrice > 0 ? ('或 ' + pointsPrice + ' <?php echo $points_name; ?>') : '<?php _e('无需支付', 'qilingshop'); ?>';
                
                var priceNote = document.getElementById('qls-vip-price-note');
                if (priceNote) {
                    if (upgradeLabel) {
                        var noteText = upgradeLabel;
                        if (originPrice > currentPrice && originPrice > 0) {
                            noteText = '<?php _e('原价', 'qilingshop'); ?> ¥' + originPrice + ' · ' + upgradeLabel;
                        }
                        priceNote.textContent = noteText;
                        priceNote.style.display = 'block';
                    } else {
                        priceNote.style.display = 'none';
                    }
                }

                var ruleExpires = this.dataset.ruleExpires || '-';
                var ruleCalc = this.dataset.ruleCalc || '';
                var ruleStack = this.dataset.ruleStack || '';
                var ruleExpiresEl = document.getElementById('qls-vip-rule-expires');
                var ruleCalcEl = document.getElementById('qls-vip-rule-calc');
                var ruleStackEl = document.getElementById('qls-vip-rule-stack');
                if (ruleExpiresEl) {
                    ruleExpiresEl.textContent = ruleExpires;
                }
                if (ruleCalcEl) {
                    ruleCalcEl.textContent = ruleCalc;
                }
                if (ruleStackEl) {
                    ruleStackEl.textContent = ruleStack;
                }
                
                // 检查积分余额是否足够
                var pointsOption = document.getElementById('qls-points-option');
                if (pointsOption) {
                    if (userBalance < pointsPrice) {
                        pointsOption.style.opacity = '0.5';
                        pointsOption.querySelector('input').disabled = true;
                        pointsOption.querySelector('.qls-payment-balance').innerHTML = '<?php printf(__('余额不足（需要 %s %s）', 'qilingshop'), '\' + pointsPrice + \'', $points_name); ?>';
                        // 选中第一个可用的在线支付
                        var firstOnline = document.querySelector('input[name="vip_payment_method"]:not([disabled])');
                        if (firstOnline) firstOnline.checked = true;
                    } else {
                        pointsOption.style.opacity = '1';
                        pointsOption.querySelector('input').disabled = false;
                        pointsOption.querySelector('input').checked = true;
                    }
                }
                
                modal.style.display = 'flex';
            } else {
                qlsNotify('<?php echo esc_js(__('请联系管理员开通此VIP等级', 'qilingshop')); ?>', 'warning');
            }
        });
    });
    
    // 优惠券选择触发器
    var couponTrigger = document.getElementById('qls-vip-coupon-trigger');
    if (couponTrigger) {
        couponTrigger.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (typeof QLSCoupon !== 'undefined') {
                QLSCoupon.showPicker({
                    orderType: 'vip',
                    amount: currentPrice,
                    onSelect: function(coupon, discount) {
                        // 保存选中的优惠券信息
                        window.vipSelectedCoupon = coupon;
                        document.getElementById('qls-vip-coupon-claim-id').value = coupon.claimId;
                        document.getElementById('qls-vip-coupon-name').textContent = coupon.name;
                        document.getElementById('qls-vip-coupon-discount').textContent = '-¥' + discount.toFixed(2);
                        document.getElementById('qls-vip-coupon-discount-amount').value = discount;
                        // 显示已选优惠券，隐藏触发器
                        document.getElementById('qls-vip-coupon-trigger').style.display = 'none';
                        document.getElementById('qls-vip-coupon-applied').style.display = 'flex';
                    }
                });
            } else {
                console.error('QLSCoupon not loaded');
            }
        }, true);
    }
    
    // 移除已选优惠券
    var removeCouponBtn = document.getElementById('qls-vip-remove-coupon');
    if (removeCouponBtn) {
        removeCouponBtn.addEventListener('click', function() {
            resetCouponState();
        });
    }
    
    // 关闭弹窗
    modal.querySelector('.qls-modal-close').addEventListener('click', function() {
        modal.style.display = 'none';
    });
    modal.querySelector('.qls-modal-overlay').addEventListener('click', function() {
        modal.style.display = 'none';
    });
    
    // 确认购买
    document.getElementById('qls-confirm-vip-buy').addEventListener('click', function() {
        var gateway = document.querySelector('input[name="vip_payment_method"]:checked')?.value;
        var couponClaimId = document.getElementById('qls-vip-coupon-claim-id')?.value || '';
        
        if (!gateway) {
            qlsNotify('<?php echo esc_js(__('请选择支付方式', 'qilingshop')); ?>', 'warning');
            return;
        }
        
        var btn = this;
        btn.disabled = true;
        btn.textContent = '<?php _e('处理中...', 'qilingshop'); ?>';
        
        // 根据支付方式选择不同的action
        var action = gateway === 'points' ? 'qilingshop_buy_vip' : 'qilingshop_buy_vip_direct';
        
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=' + action + '&level_id=' + currentLevelId + '&gateway=' + gateway + '&coupon_claim_id=' + couponClaimId + '&nonce=<?php echo wp_create_nonce('qilingshop_ajax'); ?>'
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                if (gateway === 'points') {
                    // 积分支付成功
                    qlsNotify(data.message || '<?php echo esc_js(__('购买成功', 'qilingshop')); ?>', 'success');
                    window.setTimeout(function() {
                        location.reload();
                    }, 650);
                } else if (data.data && data.data.pay_url) {
                    // 在线支付跳转
                    window.location.href = data.data.pay_url;
                } else {
                    qlsNotify(data.message || '<?php echo esc_js(__('操作成功', 'qilingshop')); ?>', 'success');
                    window.setTimeout(function() {
                        location.reload();
                    }, 650);
                }
            } else {
                qlsNotify(data.message || '<?php echo esc_js(__('操作失败', 'qilingshop')); ?>', 'error');
                btn.disabled = false;
                btn.textContent = '<?php _e('确认支付', 'qilingshop'); ?>';
            }
        })
        .catch(function() {
            qlsNotify('<?php echo esc_js(__('网络错误', 'qilingshop')); ?>', 'error');
            btn.disabled = false;
            btn.textContent = '<?php _e('确认支付', 'qilingshop'); ?>';
        });
    });

    // 兑换码
    var redeemBtn = document.getElementById('qls-vip-redeem-btn');
    if (redeemBtn) {
        redeemBtn.addEventListener('click', function() {
            var codeInput = document.getElementById('qls-vip-redeem-code');
            var code = codeInput ? codeInput.value.trim() : '';
            if (!code) {
                qlsNotify('<?php echo esc_js(__('请输入兑换码', 'qilingshop')); ?>', 'warning');
                return;
            }

            redeemBtn.disabled = true;
            redeemBtn.textContent = '<?php _e('处理中...', 'qilingshop'); ?>';

            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=qilingshop_vip_redeem&code=' + encodeURIComponent(code) + '&nonce=<?php echo wp_create_nonce('qilingshop_ajax'); ?>'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    qlsNotify(data.message || '<?php echo esc_js(__('兑换成功', 'qilingshop')); ?>', 'success');
                    window.setTimeout(function() {
                        location.reload();
                    }, 650);
                } else {
                    qlsNotify(data.message || '<?php echo esc_js(__('兑换失败', 'qilingshop')); ?>', 'error');
                }
            })
            .catch(function() {
                qlsNotify('<?php echo esc_js(__('网络错误', 'qilingshop')); ?>', 'error');
            })
            .finally(function() {
                redeemBtn.disabled = false;
                redeemBtn.textContent = '<?php _e('立即兑换', 'qilingshop'); ?>';
            });
        });
    }
})();
</script>
