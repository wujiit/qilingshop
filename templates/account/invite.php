<?php
/**
 * 个人中心 - 邀请记录
 * 
 * 可用变量: $user_id, $current_user
 *
 * @package QilingShop
 */

if (!defined('ABSPATH')) exit;

$affiliate = QilingShop_Affiliate::instance();
$points_instance = QilingShop_Points::instance();
$invite_code = $points_instance->get_invite_code($user_id);
$invite_link = $points_instance->get_invite_url($user_id);
$stats = $affiliate->get_invite_stats($user_id);
$user_info = $points_instance->get_user_info($user_id);
$invite_count = $user_info ? (int) $user_info->invite_count : 0;
$invite_tier_enabled = (bool) get_option('qilingshop_invite_tier_enabled', false);
$invite_tiers = $invite_tier_enabled ? $affiliate->get_invite_tier_rules(true) : [];
$invite_tier_awarded = $invite_tier_enabled ? $affiliate->get_invite_tier_awarded_ids($user_id) : [];
$paged = isset($_GET['ipaged']) ? max(1, intval($_GET['ipaged'])) : 1;
$per_page = 10;
$commission_paged = isset($_GET['cpaged']) ? max(1, intval($_GET['cpaged'])) : 1;
$commission_per_page = 10;
$lazy_invite = apply_filters('qilingshop_lazy_invite_enabled', true);

$invites = [];
$total = 0;
$total_pages = 0;
$commissions = [];
$commission_total = 0;
$commission_total_pages = 0;
if (!$lazy_invite) {
    $invites = $affiliate->get_invite_list($user_id, [
        'limit'  => $per_page,
        'offset' => ($paged - 1) * $per_page,
    ]);
    $total = $affiliate->get_invite_total($user_id);
    $total_pages = ceil($total / $per_page);

    $commissions = $affiliate->get_commission_log($user_id, [
        'limit'  => $commission_per_page,
        'offset' => ($commission_paged - 1) * $commission_per_page,
    ]);
    $commission_total = $affiliate->get_commission_total($user_id);
    $commission_total_pages = ceil($commission_total / $commission_per_page);
}
$points_name = qilingshop_get_points_name();
$invite_bonus_inviter = (float) get_option('qilingshop_invite_bonus_inviter', 0);
$invite_bonus_invitee = (float) get_option('qilingshop_invite_bonus_invitee', 0);
$affiliate_level1_rate = (float) get_option('qilingshop_affiliate_level1_rate', 10);
$affiliate_level2_rate = (float) get_option('qilingshop_affiliate_level2_rate', 5);
?>

<div class="qls-account-section">
    <h3 class="qls-section-title"><?php _e('邀请记录', 'qilingshop'); ?></h3>
    
    <!-- 邀请码和链接 -->
    <div class="qls-invite-card">
        <div class="qls-invite-code-section">
            <label><?php _e('您的专属邀请码', 'qilingshop'); ?></label>
            <div class="qls-invite-code">
                <code id="qls-code"><?php echo esc_html($invite_code); ?></code>
                <button class="qls-copy-btn" data-target="qls-code" title="<?php _e('复制', 'qilingshop'); ?>">📋</button>
            </div>
        </div>
        <div class="qls-invite-link-section">
            <label><?php _e('邀请链接', 'qilingshop'); ?></label>
            <div class="qls-invite-link">
                <input type="text" id="qls-link" value="<?php echo esc_attr($invite_link); ?>" readonly>
                <button class="qls-copy-btn" data-target="qls-link" title="<?php _e('复制', 'qilingshop'); ?>">📋</button>
            </div>
        </div>
    </div>
    
    <!-- 邀请统计 -->
    <div class="qls-invite-stats">
        <div class="qls-stat-item">
            <span class="qls-stat-value"><?php echo number_format($stats['total_invites'] ?? 0); ?></span>
            <span class="qls-stat-label"><?php _e('邀请人数', 'qilingshop'); ?></span>
        </div>
        <div class="qls-stat-item">
            <span class="qls-stat-value"><?php echo number_format($stats['total_commission'] ?? 0); ?></span>
            <span class="qls-stat-label"><?php _e('累计佣金', 'qilingshop'); ?></span>
        </div>
        <div class="qls-stat-item">
            <span class="qls-stat-value"><?php echo number_format($stats['pending_commission'] ?? 0); ?></span>
            <span class="qls-stat-label"><?php _e('待结算', 'qilingshop'); ?></span>
        </div>
        <div class="qls-stat-item">
            <span class="qls-stat-value"><?php echo number_format($stats['withdrawn'] ?? 0); ?></span>
            <span class="qls-stat-label"><?php _e('已提现', 'qilingshop'); ?></span>
        </div>
    </div>
    
    <!-- 邀请规则 -->
    <div class="qls-invite-rules">
        <h4><?php _e('邀请规则', 'qilingshop'); ?></h4>
        <ul>
            <?php if ($invite_bonus_inviter > 0): ?>
            <li><?php echo sprintf(__('邀请好友注册，您可获得 %s %s奖励', 'qilingshop'), $invite_bonus_inviter, $points_name); ?></li>
            <?php endif; ?>
            <?php if ($invite_bonus_invitee > 0): ?>
            <li><?php echo sprintf(__('好友注册后可获得 %s %s奖励', 'qilingshop'), $invite_bonus_invitee, $points_name); ?></li>
            <?php endif; ?>
            <?php if ($affiliate_level1_rate > 0): ?>
            <li><?php echo sprintf(__('好友消费时，您可获得 %s%% 佣金', 'qilingshop'), $affiliate_level1_rate); ?></li>
            <?php endif; ?>
            <?php if ($affiliate_level2_rate > 0): ?>
            <li><?php echo sprintf(__('二级下线消费，您可获得 %s%% 佣金', 'qilingshop'), $affiliate_level2_rate); ?></li>
            <?php endif; ?>
            <li><?php _e('统计范围：仅一级邀请', 'qilingshop'); ?></li>
            <?php if ($invite_tier_enabled): ?>
            <li><?php _e('阶梯奖励以当前规则为准', 'qilingshop'); ?></li>
            <?php endif; ?>
        </ul>
    </div>

    <?php if ($invite_tier_enabled && !empty($invite_tiers)) : ?>
    <div class="qls-invite-tiers">
        <h4><?php _e('阶梯奖励', 'qilingshop'); ?></h4>
        <div class="qls-invite-tier-grid">
            <?php foreach ($invite_tiers as $tier) :
                $threshold = (int) $tier->threshold;
                $bonus = (float) $tier->bonus_points;
                $reached = $invite_count >= $threshold;
                $awarded = in_array((int) $tier->id, $invite_tier_awarded, true);
                $remaining = max(0, $threshold - $invite_count);
            ?>
            <div class="qls-invite-tier <?php echo $reached ? 'is-reached' : ''; ?>">
                <div class="qls-invite-tier-title"><?php echo sprintf(__('累计邀请 %d 人', 'qilingshop'), $threshold); ?></div>
                <div class="qls-invite-tier-bonus"><?php echo sprintf(__('奖励 %s', 'qilingshop'), qilingshop_format_points($bonus)); ?></div>
                <div class="qls-invite-tier-status">
                    <?php if ($reached): ?>
                        <span class="qls-invite-tier-pill"><?php echo $awarded ? __('已发放', 'qilingshop') : __('待发放', 'qilingshop'); ?></span>
                    <?php else: ?>
                        <span class="qls-invite-tier-muted"><?php echo sprintf(__('还差 %d 人', 'qilingshop'), $remaining); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- 邀请列表 -->
    <div class="qls-invite-list">
        <h4><?php _e('邀请明细', 'qilingshop'); ?></h4>

        <div class="qls-invite-list-body js-qls-lazy-list" data-qls-list="invite" data-page="<?php echo esc_attr($paged); ?>">
            <?php if ($lazy_invite): ?>
                <div class="qls-empty-state qls-empty-sm">
                    <p><?php _e('加载中...', 'qilingshop'); ?></p>
                </div>
            <?php else: ?>
                <?php
                $base_url = add_query_arg('tab', 'qls-invite', get_permalink());
                qilingshop_get_template('account/partials/invite-list', [
                    'invites' => $invites,
                    'points_name' => $points_name,
                    'paged' => $paged,
                    'total_pages' => $total_pages,
                    'base_url' => $base_url,
                ]);
                ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- 佣金明细 -->
    <div class="qls-commission-log">
        <h4><?php _e('佣金明细', 'qilingshop'); ?></h4>
        <div class="qls-commission-log-body js-qls-lazy-list" data-qls-list="commission" data-page="<?php echo esc_attr($commission_paged); ?>">
            <?php if ($lazy_invite): ?>
                <div class="qls-empty-state qls-empty-sm">
                    <p><?php _e('加载中...', 'qilingshop'); ?></p>
                </div>
            <?php else: ?>
                <?php
                $base_url = add_query_arg('tab', 'qls-invite', get_permalink());
                qilingshop_get_template('account/partials/commission-log', [
                    'commissions' => $commissions,
                    'paged' => $commission_paged,
                    'total_pages' => $commission_total_pages,
                    'base_url' => $base_url,
                ]);
                ?>
            <?php endif; ?>
        </div>
    </div>
</div>
