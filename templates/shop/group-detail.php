<?php
/**
 * 团详情页面模板
 * 
 * @package QilingShop
 * @since   2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$group = isset($group) ? $group : null;
$product = isset($product) ? $product : null;
$group_id = isset($group_id) ? intval($group_id) : 0;
$has_group = $group && $product;
$shop_url = qls_shop_public()->get_shop_url();
$group_center_url = qls_group_public()->get_group_center_url();

$image_url = '';
$product_url = '';
$remain_seconds = 0;
$is_member = false;
$progress_percentage = 0;
$remain_count = 0;
$is_manual_success = false;

if ($has_group) {
    if (is_array($product->main_image)) {
        $image_url = $product->main_image['url'] ?? '';
    } elseif (is_string($product->main_image)) {
        $decoded = json_decode($product->main_image, true);
        $image_url = is_array($decoded) ? ($decoded['url'] ?? '') : $product->main_image;
    }
    
    $product_url = qls_shop_public()->get_product_url($product);
    $remain_seconds = max(0, strtotime($group->expire_time) - current_time('timestamp'));
    $is_member = is_user_logged_in() && qls_group()->is_member($group->id, get_current_user_id());
    $remain_count = max(0, $group->target_size - $group->current_size);
    $progress_percentage = $group->target_size > 0 ? min(100, ($group->current_size / $group->target_size) * 100) : 0;
    $is_manual_success = get_option('_qls_group_manual_success_' . $group->id) ? true : false;
}
?>


<?php qls_shop_public()->get_shop_header(__('团购详情', 'qilingshop'), true); ?>

<div class="qls-shop-wrapper qls-group-detail-page-wrapper">
    <div class="qls-container">
        <nav class="qls-breadcrumb">
            <a href="<?php echo esc_url($shop_url); ?>"><?php _e('商城', 'qilingshop'); ?></a>
            <span class="sep">›</span>
            <a href="<?php echo esc_url($group_center_url); ?>"><?php _e('团购中心', 'qilingshop'); ?></a>
            <span class="sep">›</span>
            <span class="current"><?php _e('团购详情', 'qilingshop'); ?></span>
        </nav>
        <div class="qls-group-center">
            <div class="qls-group-detail-wrapper">
            <?php if (!$has_group): ?>
                <div class="qls-empty-state">
                    <div class="qls-empty-icon">⚠️</div>
                    <h3 class="qls-empty-title"><?php _e('团购不存在', 'qilingshop'); ?></h3>
                    <p class="qls-empty-desc"><?php _e('该团购已结束或被删除', 'qilingshop'); ?></p>
                    <div class="qls-empty-actions">
                        <a href="<?php echo esc_url($group_center_url); ?>" class="qls-btn qls-btn-primary"><?php _e('去团购中心', 'qilingshop'); ?></a>
                        <a href="<?php echo esc_url($shop_url); ?>" class="qls-btn qls-btn-secondary"><?php _e('返回商城', 'qilingshop'); ?></a>
                    </div>
                </div>
            <?php else: ?>
                <div class="qls-group-status-header qls-group-status-<?php echo $group->status; ?>">
                    <?php if ($group->status == 0): ?>
                        <div class="qls-group-status-icon">🔥</div>
                        <div class="qls-group-status-text"><?php _e('拼团中', 'qilingshop'); ?></div>
                        <div class="qls-group-countdown" data-seconds="<?php echo $remain_seconds; ?>">
                            <span class="qls-countdown-label"><?php _e('剩余时间', 'qilingshop'); ?></span>
                            <span class="qls-countdown-time">
                                <?php 
                                $hours = floor($remain_seconds / 3600);
                                $mins = floor(($remain_seconds % 3600) / 60);
                                $secs = $remain_seconds % 60;
                                echo sprintf('%02d:%02d:%02d', $hours, $mins, $secs);
                                ?>
                            </span>
                        </div>
                    <?php elseif ($group->status == 1): ?>
                        <div class="qls-group-status-icon">✅</div>
                        <div class="qls-group-status-text"><?php _e('拼团成功！', 'qilingshop'); ?></div>
                        <div class="qls-group-status-desc">
                            <?php echo $is_manual_success ? __('该团为平台授权为成功，商家即将发货', 'qilingshop') : __('该团已成功，商家即将发货', 'qilingshop'); ?>
                        </div>
                    <?php else: ?>
                        <div class="qls-group-status-icon">❌</div>
                        <div class="qls-group-status-text"><?php _e('拼团失败', 'qilingshop'); ?></div>
                        <div class="qls-group-status-desc"><?php _e('未能在规定时间内成团，已自动退款', 'qilingshop'); ?></div>
                    <?php endif; ?>
                </div>

                <?php
                // Find current user's order in this group
                $my_order_info = null;
                if (is_user_logged_in()) {
                    $current_uid = get_current_user_id();
                    foreach ($group->members as $member) {
                        if ($member->user_id == $current_uid) {
                            // group_members 记录以 order_id 关联订单。
                            $my_order = qls_shop_order()->get($member->order_id, true);
                            if ($my_order && !empty($my_order->items)) {
                                $my_order_info = $my_order;
                            }
                            break;
                        }
                    }
                }
                ?>
                <?php if ($my_order_info): ?>
                <div class="qls-my-participation">
                    <h4 class="qls-my-participation-title">
                        <span class="dashicons dashicons-id-alt"></span> <?php _e('我的参团信息', 'qilingshop'); ?>
                    </h4>
                    <?php $item = $my_order_info->items[0]; ?>
                    <div class="qls-participation-content">
                        <div class="qls-participation-info">
                            <div class="qls-participation-row">
                                <span class="qls-participation-label"><?php _e('规格：', 'qilingshop'); ?></span>
                                <span class="qls-participation-value">
                                <?php 
                                if (!empty($item->sku_attrs)) {
                                    echo implode(' / ', array_values($item->sku_attrs));
                                } else {
                                    _e('默认规格', 'qilingshop');
                                }
                                ?>
                                </span>
                            </div>
                            <div class="qls-participation-row">
                                <span class="qls-participation-label"><?php _e('数量：', 'qilingshop'); ?></span>
                                <span class="qls-participation-value">x<?php echo $item->quantity; ?></span>
                            </div>
                        </div>
                        <div class="text-right">
                            <span class="qls-badge <?php echo qls_shop_order()->get_status_badge_class($my_order_info->status); ?>">
                                <?php echo qls_shop_order()->get_status_text($my_order_info->status); ?>
                            </span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            <div class="qls-group-members-section">
                <div class="qls-group-progress">
                    <span class="qls-group-progress-text">
                        <?php printf(__('还差 %d 人成团', 'qilingshop'), $remain_count); ?>
                    </span>
                    <div class="qls-group-progress-bar">
                        <div class="qls-group-progress-fill" style="width: <?php echo $progress_percentage; ?>%;"></div>
                    </div>
                </div>
                
                <div class="qls-group-members-list">
                    <?php foreach ($group->members as $member): ?>
                    <div class="qls-group-member <?php echo $member->is_leader ? 'is-leader' : ''; ?>">
                        <img src="<?php echo esc_url($member->user_avatar); ?>" alt="" class="qls-member-avatar">
                        <?php if ($member->is_leader): ?>
                        <span class="qls-leader-badge"><?php _e('团长', 'qilingshop'); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    
                    <?php for ($i = $group->current_size; $i < $group->target_size; $i++): ?>
                    <div class="qls-group-member empty">
                        <span class="qls-member-placeholder">?</span>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>

            <div class="qls-group-product-section">
                <a href="<?php echo esc_url($product_url); ?>" class="qls-group-product-detail">
                    <div class="qls-group-product-image">
                        <img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($product->title); ?>">
                    </div>
                    <div class="qls-group-product-info">
                        <h3 class="qls-product-title"><?php echo esc_html($product->title); ?></h3>
                        <div class="qls-price-row">
                            <span class="qls-group-price">¥<?php echo number_format($group->group_price, 2); ?></span>
                            <span class="qls-group-label"><?php printf(esc_html__('%s人团', 'qilingshop'), esc_html($group->target_size)); ?></span>
                        </div>
                    </div>
                </a>
            </div>

            <div class="qls-group-actions">
                <?php if ($group->status == 0): ?>
                    <?php if (!is_user_logged_in()): ?>
                        <button class="qls-btn qls-btn-primary qls-btn-lg user-login"><?php _e('登录参团', 'qilingshop'); ?></button>
                    <?php elseif ($is_member): ?>
                        <div class="qls-already-joined">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <?php _e('您已参与此团', 'qilingshop'); ?>
                        </div>
                        <button class="qls-btn qls-btn-share qls-btn-lg" data-group-id="<?php echo $group->id; ?>">
                            <?php _e('邀请好友参团', 'qilingshop'); ?>
                        </button>
                    <?php else: ?>
                        <button class="qls-btn qls-btn-primary qls-btn-lg qls-join-group-btn" 
                                data-group-id="<?php echo $group->id; ?>"
                                data-product-id="<?php echo $group->product_id; ?>">
                            <?php _e('立即参团', 'qilingshop'); ?>
                        </button>
                    <?php endif; ?>
                <?php else: ?>
                    <a href="<?php echo esc_url($product_url); ?>" class="qls-btn qls-btn-secondary qls-btn-lg">
                        <?php _e('查看商品', 'qilingshop'); ?>
                    </a>
                <?php endif; ?>
            </div>

            <div class="qls-group-rules">
                <h4><?php _e('拼团规则', 'qilingshop'); ?></h4>
                <ul>
                    <li><?php printf(__('%d人成团，人数不足自动退款', 'qilingshop'), $group->target_size); ?></li>
                    <li><?php _e('拼团成功后，商家会尽快安排发货', 'qilingshop'); ?></li>
                    <li><?php _e('团购价格不可使用优惠券', 'qilingshop'); ?></li>
                </ul>
            </div>
            <?php endif; ?>
            <!-- My Group History Section -->
            <?php
            if (is_user_logged_in()) {
                $user_groups = qls_group()->get_user_groups(get_current_user_id(), ['limit' => 10]);
                if (!empty($user_groups)):
            ?>
            <div class="qls-group-history-section">
                <h4 class="qls-history-title"><?php _e('我的拼团记录', 'qilingshop'); ?></h4>
                <div class="qls-history-list">
                    <?php foreach ($user_groups as $h_group): 
                        $h_product = qls_product()->get($h_group->product_id);
                        $h_status_text = '';
                        $h_status_class = '';
                        $h_detail_url = qls_group_public()->get_group_detail_url($h_group->id);
                        
                        switch($h_group->status) {
                            case 0: $h_status_text = __('拼团中', 'qilingshop'); $h_status_class = 'warning'; break;
                            case 1: $h_status_text = __('拼团成功', 'qilingshop'); $h_status_class = 'success'; break;
                            case 2: $h_status_text = __('拼团失败', 'qilingshop'); $h_status_class = 'danger'; break;
                        }
                    ?>
                    <a href="<?php echo esc_url($h_detail_url); ?>" class="qls-history-card">
                        <div class="qls-history-thumb">
                            <?php 
                            $h_img = is_array($h_product->main_image) ? ($h_product->main_image['url'] ?? '') : $h_product->main_image;
                            ?>
                            <img src="<?php echo esc_url($h_img); ?>" alt="<?php echo esc_attr($h_product->title); ?>">
                        </div>
                        <div class="qls-history-info">
                            <div class="qls-history-product-title"><?php echo esc_html($h_product->title); ?></div>
                            <div class="qls-history-meta">
                                <span class="qls-history-status <?php echo $h_status_class; ?>"><?php echo $h_status_text; ?></span>
                                <span><?php echo $h_group->target_size; ?><?php _e('人团', 'qilingshop'); ?></span>
                            </div>
                        </div>
                        <div class="qls-history-price">¥<?php echo number_format($h_group->group_price, 2); ?></div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; } ?>

            </div>
        </div>
    </div>
</div>

<?php if ($has_group): ?>
<script>
jQuery(document).ready(function($) {
    var $countdown = $('.qls-group-countdown');
    if ($countdown.length) {
        var seconds = parseInt($countdown.data('seconds'));
        
        var timer = setInterval(function() {
            if (seconds <= 0) {
                clearInterval(timer);
                location.reload();
                return;
            }
            
            seconds--;
            var hours = Math.floor(seconds / 3600);
            var mins = Math.floor((seconds % 3600) / 60);
            var secs = seconds % 60;
            
            $countdown.find('.qls-countdown-time').text(
                String(hours).padStart(2, '0') + ':' +
                String(mins).padStart(2, '0') + ':' +
                String(secs).padStart(2, '0')
            );
        }, 1000);
    }
    
    // Custom Toast Function
    function showToast(message, type) {
        var bgColor = type === 'error' ? '#ef4444' : '#10b981';
        var icon = type === 'error' ? '❌' : '✅';
        
        var $toast = $('<div class="qls-toast">' + icon + ' ' + message + '</div>');
        $toast.css({
            position: 'fixed',
            top: '50%',
            left: '50%',
            transform: 'translate(-50%, -50%)',
            background: 'rgba(0, 0, 0, 0.8)',
            color: '#fff',
            padding: '16px 24px',
            borderRadius: '8px',
            zIndex: 9999,
            fontSize: '15px',
            boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
            display: 'flex',
            alignItems: 'center',
            gap: '8px',
            minWidth: '200px',
            justifyContent: 'center',
            backdropFilter: 'blur(4px)'
        });
        
        $('body').append($toast);
        
        setTimeout(function() {
            $toast.fadeOut(300, function() {
                $(this).remove();
            });
        }, 2000);
    }

    $('.qls-join-group-btn').on('click', function() {
        var groupId = $(this).data('group-id');
        var productId = $(this).data('product-id');
        window.location.href = '<?php echo esc_url($product_url); ?>?join_group=' + groupId;
    });
    
    $('.qls-btn-share').on('click', function() {
        // Construct the exclusive invite link (Product Page + Join Param)
        var shareUrl = '<?php echo esc_url($product_url); ?>?join_group=<?php echo $group->id; ?>';
        
        if (navigator.share) {
            navigator.share({
                title: '<?php echo esc_js($product->title); ?>',
                text: '<?php printf(esc_js(__('邀请参与 %s 的拼团，仅需 ¥%s', 'qilingshop')), $product->title, number_format($group->group_price, 2)); ?>',
                url: shareUrl
            });
        } else {
            navigator.clipboard.writeText(shareUrl).then(function() {
                showToast('<?php _e('专属拼团链接已复制！', 'qilingshop'); ?>', 'success');
            }).catch(function() {
                showToast('<?php _e('复制失败，请手动复制', 'qilingshop'); ?>', 'error');
            });
        }
    });
});
</script>
<?php endif; ?>

<?php qls_shop_public()->get_shop_footer(); ?>
