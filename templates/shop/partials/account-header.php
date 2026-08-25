<?php
/**
 * 个人中心头部 (用户卡片)
 */
if (!defined('ABSPATH')) exit;

$user = wp_get_current_user();
$vip_name = qilingshop_get_user_vip_name($user->ID);
if (!$vip_name) $vip_name = __('普通会员', 'qilingshop');
$points = qilingshop_get_user_points($user->ID);
$points_name = qilingshop_get_points_name();
$growth_account = (class_exists('QilingShop_Growth') && QilingShop_Growth::instance()->is_enabled() && (bool) get_option('qilingshop_growth_frontend_display', true)) ? QilingShop_Growth::instance()->get_user_growth($user->ID) : null;
$growth_level = $growth_account ? QilingShop_Growth::instance()->get_level((int) $growth_account->level_id) : null;
$growth_highest_level = ($growth_account && (bool) get_option('qilingshop_growth_show_highest_level', false)) ? QilingShop_Growth::instance()->get_highest_user_level($user->ID) : null;
$growth_next_level = $growth_account ? QilingShop_Growth::instance()->get_next_level((float) $growth_account->growth_value) : null;
$growth_progress = 100;
if ($growth_account && $growth_next_level) {
    $current_min = $growth_level ? (float) $growth_level->min_growth : 0;
    $next_min = max($current_min + 1, (float) $growth_next_level->min_growth);
    $growth_progress = $next_min > $current_min ? (((float) $growth_account->growth_value - $current_min) / ($next_min - $current_min)) * 100 : 0;
    $growth_progress = max(0, min(100, $growth_progress));
}
?>
<div class="qls-account-header">
    <div class="qls-container">
        <div class="qls-user-card">
            <div class="qls-user-avatar">
                <?php echo get_avatar($user->ID, 96); ?>
            </div>
            <div class="qls-user-info">
                <h2 class="qls-user-name">
                    <?php echo esc_html($user->display_name); ?>
                    <span class="qls-vip-badge"><?php echo esc_html($vip_name); ?></span>
                </h2>
                <div class="qls-user-meta">
                    <span class="meta-item">ID: <?php echo esc_html($user->ID); ?></span>
                    <span class="meta-item"><?php echo esc_html($user->user_email); ?></span>
                </div>
            </div>
            <div class="qls-user-stats">
                <div class="stat-item">
                    <span class="stat-value"><?php echo number_format($points); ?></span>
                    <span class="stat-label"><?php echo esc_html($points_name); ?></span>
                </div>
                <?php if ($growth_account): ?>
                <div class="stat-item qls-growth-header-stat">
                    <span class="stat-value"><?php echo esc_html(number_format((float) $growth_account->growth_value, 2)); ?></span>
                    <span class="stat-label"><?php echo esc_html(QilingShop_Growth::instance()->get_growth_name()); ?></span>
                    <span class="qls-growth-header-badge" style="background:<?php echo esc_attr($growth_level && !empty($growth_level->level_color) ? $growth_level->level_color : '#64748b'); ?>"><?php echo esc_html($growth_level ? $growth_level->level_name : __('暂无等级', 'qilingshop')); ?></span>
                    <?php if ($growth_highest_level && (!$growth_level || (int) $growth_highest_level->id !== (int) $growth_level->id)) : ?>
                        <span class="stat-label"><?php echo esc_html(sprintf(__('历史最高：%s', 'qilingshop'), $growth_highest_level->level_name)); ?></span>
                    <?php endif; ?>
                    <span class="qls-growth-header-progress"><i style="width:<?php echo esc_attr(number_format($growth_progress, 2)); ?>%"></i></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
