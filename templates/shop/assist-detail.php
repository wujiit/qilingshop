<?php
/**
 * 助力详情
 */
if (!defined('ABSPATH')) exit;

qls_shop_public()->get_shop_header(__('助力详情', 'qilingshop'), true);

$is_logged_in = is_user_logged_in();
$assist_center_url = qls_shop_public()->get_page_url('assist-center');
$my_assists_url = qls_shop_public()->get_page_url('my-assists');

$expire_ts = !empty($campaign->expire_at) ? strtotime($campaign->expire_at) : 0;
$remain_seconds = $expire_ts > 0 ? max(0, $expire_ts - current_time('timestamp')) : 0;
$campaign_expire_hours = max(1, (int) ($campaign->expire_hours ?? 24));
$image = '';
if (!empty($campaign->product_image)) {
    if (is_string($campaign->product_image)) {
        $decoded = json_decode($campaign->product_image, true);
        $image = is_array($decoded) ? ($decoded['url'] ?? '') : $campaign->product_image;
    } elseif (is_array($campaign->product_image)) {
        $image = $campaign->product_image['url'] ?? '';
    }
}

$start_price = (float) $campaign->start_price;
$current_price = (float) $campaign->current_price;
$min_price = (float) $campaign->min_price;
$saved_amount = max(0, $start_price - $current_price);
$max_save = max(0, $start_price - $min_price);
$price_progress = $max_save > 0 ? min(100, round($saved_amount / $max_save * 100)) : 0;
$target_helpers = max(0, (int) $campaign->target_helpers);
$helper_progress = $target_helpers > 0 ? min(100, round((int) $campaign->help_count / $target_helpers * 100)) : $price_progress;

$pay_url = '';
if ((int) $campaign->status === QLS_Assist::CAMPAIGN_ORDER_PENDING && !empty($campaign->pay_order_no)) {
    $pay_url = add_query_arg([
        'pay'   => 'shop',
        'order' => (string) $campaign->pay_order_no,
    ], home_url('/'));
}
?>

<div class="qls-shop-wrapper qls-group-detail-page-wrapper qls-assist-detail-page-wrapper">
    <div class="qls-container">
        <nav class="qls-breadcrumb">
            <a href="<?php echo esc_url(qls_shop_public()->get_shop_url()); ?>"><?php _e('商城', 'qilingshop'); ?></a>
            <span class="sep">›</span>
            <?php if (!empty($assist_center_url)): ?>
            <a href="<?php echo esc_url($assist_center_url); ?>"><?php _e('好友助力', 'qilingshop'); ?></a>
            <span class="sep">›</span>
            <?php endif; ?>
            <span class="current"><?php _e('助力详情', 'qilingshop'); ?></span>
        </nav>

        <div class="qls-group-center qls-assist-detail-shell">
            <div class="qls-section-header">
                <h3><?php _e('助力详情', 'qilingshop'); ?></h3>
                <div class="qls-assist-hall-actions">
                    <?php if (!empty($assist_center_url)): ?>
                    <a class="qls-btn qls-btn-outline" href="<?php echo esc_url($assist_center_url); ?>"><?php _e('返回助力大厅', 'qilingshop'); ?></a>
                    <?php endif; ?>
                    <?php if ($is_logged_in && !empty($my_assists_url)): ?>
                    <a class="qls-btn qls-btn-primary" href="<?php echo esc_url($my_assists_url); ?>"><?php _e('我的助力', 'qilingshop'); ?></a>
                    <?php endif; ?>
                </div>
            </div>

            <main class="qls-assist-detail-main">
                <section class="qls-assist-detail-banner qls-assist-detail-card"
                         data-campaign-id="<?php echo (int) $campaign->id; ?>"
                         data-share-code="<?php echo esc_attr($campaign->share_code); ?>"
                         data-remain-seconds="<?php echo (int) $remain_seconds; ?>">
                    <div class="assist-detail-banner-main">
                        <span class="assist-tag"><?php _e('限时助力', 'qilingshop'); ?></span>
                        <div class="qls-assist-detail-status"><?php echo esc_html(qls_assist()->get_campaign_status_text((int) $campaign->status)); ?></div>
                        <h2><?php echo esc_html($campaign->activity_name); ?></h2>
                        <p><?php echo esc_html($campaign->product_title ?: __('商品已删除', 'qilingshop')); ?></p>

                        <div class="assist-price-showcase">
                            <div class="assist-price-showcase-left">
                                <span class="assist-price-label"><?php _e('当前到手价', 'qilingshop'); ?></span>
                                <strong>¥<?php echo number_format($current_price, 2); ?></strong>
                                <span class="assist-price-original">¥<?php echo number_format($start_price, 2); ?></span>
                            </div>
                            <div class="assist-price-save">
                                <span><?php _e('已砍掉', 'qilingshop'); ?></span>
                                <strong>¥<?php echo number_format($saved_amount, 2); ?></strong>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($image)): ?>
                    <div class="assist-detail-banner-cover">
                        <img src="<?php echo esc_url($image); ?>" alt="">
                    </div>
                    <?php endif; ?>
                </section>

                <div class="qls-assist-detail-highlights">
                    <span><?php printf(__('立减 ¥%s', 'qilingshop'), number_format($saved_amount, 2)); ?></span>
                    <span><?php printf(__('最低可到 ¥%s', 'qilingshop'), number_format($min_price, 2)); ?></span>
                    <span><?php printf(__('已助力 %d 人', 'qilingshop'), (int) $campaign->help_count); ?></span>
                </div>

                <section class="qls-assist-progress-card">
                    <div class="assist-progress-row">
                        <div class="assist-progress-title"><?php _e('价格进度', 'qilingshop'); ?></div>
                        <div class="assist-progress-value"><?php echo (int) $price_progress; ?>%</div>
                    </div>
                    <div class="assist-progress-track"><span style="width: <?php echo (int) $price_progress; ?>%;"></span></div>

                    <div class="assist-progress-row" style="margin-top: 12px;">
                        <div class="assist-progress-title"><?php _e('人数进度', 'qilingshop'); ?></div>
                        <div class="assist-progress-value"><?php echo (int) $campaign->help_count; ?><?php if ($target_helpers > 0): ?> / <?php echo (int) $target_helpers; ?><?php else: ?> / <?php _e('不限制', 'qilingshop'); ?><?php endif; ?></div>
                    </div>
                    <div class="assist-progress-track"><span style="width: <?php echo (int) $helper_progress; ?>%;"></span></div>

                    <div class="assist-progress-meta">
                        <span><?php printf(__('最低价：¥%s', 'qilingshop'), number_format($min_price, 2)); ?></span>
                        <span><?php printf(__('活动有效期：%d小时（发起后）', 'qilingshop'), $campaign_expire_hours); ?></span>
                        <span><?php _e('剩余时间：', 'qilingshop'); ?><strong class="qls-assist-countdown"><?php echo esc_html($remain_seconds > 0 ? gmdate('H:i:s', $remain_seconds) : __('已结束', 'qilingshop')); ?></strong></span>
                        <span><?php _e('状态：', 'qilingshop'); ?><strong><?php echo esc_html(qls_assist()->get_campaign_status_text((int) $campaign->status)); ?></strong></span>
                    </div>
                </section>

                <section class="qls-assist-share-box">
                    <label><?php _e('分享链接', 'qilingshop'); ?></label>
                    <div class="assist-share-row">
                        <input type="text" readonly value="<?php echo esc_attr($share_url); ?>" class="qls-assist-share-input">
                        <button type="button" class="qls-btn qls-btn-outline qls-assist-copy-link"><?php _e('复制链接', 'qilingshop'); ?></button>
                    </div>
                </section>

                <div class="assist-actions">
                    <?php if ($can_help): ?>
                    <button type="button" class="qls-btn qls-btn-primary qls-assist-help-btn" data-campaign-id="<?php echo (int) $campaign->id; ?>"><?php _e('帮TA助力', 'qilingshop'); ?></button>
                    <?php elseif (!empty($show_login_help) && !empty($login_help_url)): ?>
                    <a href="<?php echo esc_url($login_help_url); ?>"
                       class="qls-btn qls-btn-primary user-login"
                       data-login-url="<?php echo esc_url($login_help_url); ?>"><?php _e('登录后助力', 'qilingshop'); ?></a>
                    <?php endif; ?>

                    <?php if ($can_pay): ?>
                        <?php if (!empty($pay_url)): ?>
                        <a href="<?php echo esc_url($pay_url); ?>" class="qls-btn qls-btn-primary"><?php _e('继续支付', 'qilingshop'); ?></a>
                        <?php else: ?>
                        <button type="button" class="qls-btn qls-btn-primary qls-assist-pay-btn" data-campaign-id="<?php echo (int) $campaign->id; ?>"><?php _e('支付差额', 'qilingshop'); ?></button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="qls-section-header qls-assist-log-header">
                    <h3><?php _e('助力记录', 'qilingshop'); ?></h3>
                </div>

                <ul class="qls-assist-helpers">
                    <?php if (empty($helper_logs)): ?>
                    <li class="assist-helper-item empty"><?php _e('还没有好友助力，快去分享吧', 'qilingshop'); ?></li>
                    <?php else: ?>
                    <?php foreach ($helper_logs as $log): ?>
                    <li class="assist-helper-item">
                        <div class="helper-user">
                            <?php
                            $helper = (int) $log->actor_id > 0 ? get_user_by('ID', (int) $log->actor_id) : null;
                            echo esc_html($helper ? $helper->display_name : __('匿名好友', 'qilingshop'));
                            ?>
                        </div>
                        <div class="helper-amount">-¥<?php echo number_format((float) $log->amount, 2); ?></div>
                        <div class="helper-time"><?php echo esc_html($log->created_at); ?></div>
                    </li>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </main>
        </div>
    </div>
</div>

<?php qls_shop_public()->get_shop_footer(); ?>
