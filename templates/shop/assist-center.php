<?php
/**
 * 好友助力中心（活动大厅）
 */
if (!defined('ABSPATH')) exit;

qls_shop_public()->get_shop_header(__('好友助力中心', 'qilingshop'), true);
$is_logged_in = is_user_logged_in();
$assist_detail_url = qls_shop_public()->get_page_url('assist-detail');
$my_assists_url = qls_shop_public()->get_page_url('my-assists');
$login_url = wp_login_url(get_permalink());
$show_stock = (bool) get_option('qls_shop_show_stock', true);

$activity_count = is_array($activities) ? count($activities) : 0;
$max_save = 0;
$best_min_price = 0;
if ($activity_count > 0) {
    foreach ($activities as $activity) {
        $save = max(0, (float) $activity->start_price - (float) $activity->min_price);
        if ($save > $max_save) {
            $max_save = $save;
            $best_min_price = (float) $activity->min_price;
        }
    }
}
?>

<div class="qls-shop-wrapper qls-group-center-page qls-assist-center-page qls-assist-hall-marketing">
    <div class="qls-container">
        <nav class="qls-breadcrumb">
            <a href="<?php echo esc_url(qls_shop_public()->get_shop_url()); ?>"><?php _e('商城', 'qilingshop'); ?></a>
            <span class="sep">›</span>
            <span class="current"><?php _e('好友助力', 'qilingshop'); ?></span>
        </nav>

        <div class="qls-group-center qls-assist-center-shell">
            <section class="qls-group-header qls-assist-header">
                <h2 class="qls-group-title">
                    <span class="qls-group-icon">⚡</span>
                    <?php _e('好友助力大厅', 'qilingshop'); ?>
                </h2>
                <p class="qls-group-desc"><?php _e('发起活动后分享给好友助力，金额递减，达成后支付差额即可下单。', 'qilingshop'); ?></p>
                <div class="qls-assist-hero-tags">
                    <span><?php _e('限时活动', 'qilingshop'); ?></span>
                    <span><?php _e('邀请即减价', 'qilingshop'); ?></span>
                    <?php if ($show_stock): ?>
                    <span><?php _e('库存实时扣减', 'qilingshop'); ?></span>
                    <?php endif; ?>
                </div>

                <div class="qls-assist-hero-stats">
                    <div class="hero-stat-item">
                        <strong><?php echo (int) $activity_count; ?></strong>
                        <span><?php _e('进行中活动', 'qilingshop'); ?></span>
                    </div>
                    <div class="hero-stat-item">
                        <strong>¥<?php echo number_format((float) $max_save, 2); ?></strong>
                        <span><?php _e('最高可省', 'qilingshop'); ?></span>
                    </div>
                    <div class="hero-stat-item">
                        <strong><?php echo $best_min_price > 0 ? ('¥' . number_format($best_min_price, 2)) : '--'; ?></strong>
                        <span><?php _e('最低到手价', 'qilingshop'); ?></span>
                    </div>
                </div>

                <div class="qls-assist-hall-actions">
                    <?php if ($is_logged_in && !empty($my_assists_url)): ?>
                    <a class="qls-btn qls-btn-primary" href="<?php echo esc_url($my_assists_url); ?>"><?php _e('查看我的助力', 'qilingshop'); ?></a>
                    <?php else: ?>
                    <a class="qls-btn qls-btn-primary qls-login-trigger user-login"
                       href="<?php echo esc_url($login_url); ?>"
                       data-login-url="<?php echo esc_url($login_url); ?>"><?php _e('登录后发起助力', 'qilingshop'); ?></a>
                    <?php endif; ?>
                </div>
            </section>

            <div class="qls-section-header">
                <h3><?php _e('全部助力活动', 'qilingshop'); ?></h3>
            </div>

            <div class="qls-assist-grid">
                <?php if (empty($activities)): ?>
                <div class="qls-orders-empty">
                    <span class="dashicons dashicons-awards"></span>
                    <p><?php _e('暂无可参与的助力活动', 'qilingshop'); ?></p>
                </div>
                <?php else: ?>
                <?php foreach ($activities as $activity): ?>
                <?php
                $product_title = (string) ($activity->product_title ?? '');
                $product_image = (string) ($activity->product_image ?? '');
                if (($product_title === '' || $product_image === '') && function_exists('qls_product')) {
                    $product_obj = qls_product()->get((int) $activity->product_id);
                    if ($product_obj) {
                        if ($product_title === '' && !empty($product_obj->title)) {
                            $product_title = (string) $product_obj->title;
                        }
                        if ($product_image === '' && !empty($product_obj->main_image)) {
                            if (is_array($product_obj->main_image)) {
                                $product_image = (string) ($product_obj->main_image['url'] ?? '');
                            } elseif (is_string($product_obj->main_image)) {
                                $decoded_main = json_decode($product_obj->main_image, true);
                                if (is_array($decoded_main) && !empty($decoded_main['url'])) {
                                    $product_image = (string) $decoded_main['url'];
                                } else {
                                    $product_image = (string) $product_obj->main_image;
                                }
                            }
                        }
                    }
                }
                if (is_string($product_image) && $product_image !== '') {
                    $decoded_image = json_decode($product_image, true);
                    if (is_array($decoded_image) && !empty($decoded_image['url'])) {
                        $product_image = (string) $decoded_image['url'];
                    }
                }
                $start_price = (float) $activity->start_price;
                $min_price = (float) $activity->min_price;
                $save_amount = max(0, $start_price - $min_price);
                $save_percent = $start_price > 0 ? min(100, round($save_amount / $start_price * 100)) : 0;
                $stock_total = max(0, (int) $activity->stock_total);
                $stock_available = max(0, (int) $activity->stock_available);
                $stock_used = max(0, $stock_total - $stock_available);
                $stock_percent = $stock_total > 0 ? min(100, round($stock_used / $stock_total * 100)) : 0;
                ?>
                <article class="qls-assist-activity-card">
                    <span class="qls-assist-card-corner"><?php _e('热销活动', 'qilingshop'); ?></span>
                    <div class="assist-card-head">
                        <span class="assist-tag"><?php _e('活动', 'qilingshop'); ?></span>
                        <span class="assist-tag assist-tag-hot"><?php echo (int) $activity->target_helpers > 0 ? sprintf(__('%d人助力', 'qilingshop'), (int) $activity->target_helpers) : __('低价达成', 'qilingshop'); ?></span>
                    </div>

                    <h4 class="assist-title"><?php echo esc_html($activity->name); ?></h4>
                    <?php if (!empty($product_image)): ?>
                    <div class="assist-product-cover">
                        <img src="<?php echo esc_url($product_image); ?>" alt="<?php echo esc_attr($product_title ?: __('助力商品', 'qilingshop')); ?>">
                    </div>
                    <?php endif; ?>
                    <p class="assist-product"><?php echo esc_html($product_title ?: __('商品已删除', 'qilingshop')); ?></p>

                    <div class="assist-price-block">
                        <div class="assist-origin-price">¥<?php echo number_format($start_price, 2); ?></div>
                        <div class="assist-final-price">¥<?php echo number_format($min_price, 2); ?></div>
                        <div class="assist-save-price"><?php printf(__('最高省 ¥%s (%d%%)', 'qilingshop'), number_format($save_amount, 2), (int) $save_percent); ?></div>
                    </div>

                    <div class="assist-stock-box"<?php echo $show_stock ? '' : ' hidden'; ?>>
                        <div class="assist-stock-text"><?php printf(__('库存剩余 %d / %d', 'qilingshop'), $stock_available, $stock_total); ?></div>
                        <div class="assist-stock-track"><span style="width: <?php echo (int) $stock_percent; ?>%;"></span></div>
                    </div>

                    <div class="assist-meta">
                        <span><?php printf(__('活动有效期：%d小时', 'qilingshop'), (int) $activity->expire_hours); ?></span>
                    </div>

                    <?php if ($is_logged_in): ?>
                    <button class="qls-btn qls-btn-primary qls-assist-create-btn" data-activity-id="<?php echo (int) $activity->id; ?>"><?php _e('立即发起助力', 'qilingshop'); ?></button>
                    <?php else: ?>
                    <a class="qls-btn qls-btn-primary qls-login-trigger user-login"
                       href="<?php echo esc_url($login_url); ?>"
                       data-login-url="<?php echo esc_url($login_url); ?>"><?php _e('登录后发起', 'qilingshop'); ?></a>
                    <?php endif; ?>
                </article>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ($is_logged_in): ?>
            <div class="qls-section-header qls-assist-recent-header">
                <h3><?php _e('我的最近助力', 'qilingshop'); ?></h3>
            </div>

            <div class="qls-assist-table-wrap">
                <table class="qls-assist-table">
                    <thead>
                        <tr>
                            <th><?php _e('活动', 'qilingshop'); ?></th>
                            <th><?php _e('分享码', 'qilingshop'); ?></th>
                            <th><?php _e('当前金额', 'qilingshop'); ?></th>
                            <th><?php _e('助力进度', 'qilingshop'); ?></th>
                            <th><?php _e('状态', 'qilingshop'); ?></th>
                            <th><?php _e('操作', 'qilingshop'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($my_campaigns)): ?>
                        <tr><td colspan="6" class="no-items"><?php _e('还没有助力记录', 'qilingshop'); ?></td></tr>
                        <?php else: ?>
                        <?php foreach ($my_campaigns as $campaign): ?>
                        <tr>
                            <td><?php echo esc_html($campaign->activity_name); ?></td>
                            <td><code><?php echo esc_html($campaign->share_code); ?></code></td>
                            <td>¥<?php echo number_format((float) $campaign->current_price, 2); ?></td>
                            <td><?php echo (int) $campaign->help_count; ?><?php if ((int) $campaign->target_helpers > 0): ?> / <?php echo (int) $campaign->target_helpers; ?><?php endif; ?></td>
                            <td><?php echo esc_html(qls_assist()->get_campaign_status_text((int) $campaign->status)); ?></td>
                            <td>
                                <?php if (!empty($assist_detail_url)): ?>
                                <a href="<?php echo esc_url(add_query_arg('share', rawurlencode((string) $campaign->share_code), $assist_detail_url)); ?>"><?php _e('查看详情', 'qilingshop'); ?></a>
                                <?php else: ?>
                                -
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php qls_shop_public()->get_shop_footer(); ?>
