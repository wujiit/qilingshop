<?php
/**
 * 我的拼团页面模板
 * 
 * @package QilingShop
 * @since   2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="qls-my-groups">
    <h2 class="qls-section-title"><?php _e('我的拼团', 'qilingshop'); ?></h2>

    <!-- 状态筛选 -->
    <div class="qls-group-tabs">
        <a href="<?php echo esc_url(remove_query_arg('gstatus')); ?>" 
           class="qls-tab <?php echo is_null($status) ? 'active' : ''; ?>">
            <?php _e('全部', 'qilingshop'); ?> (<?php echo (int) $counts['all']; ?>)
        </a>
        <a href="<?php echo esc_url(add_query_arg('gstatus', 0)); ?>" 
           class="qls-tab <?php echo $status === 0 ? 'active' : ''; ?>">
            <?php _e('拼团中', 'qilingshop'); ?> (<?php echo (int) $counts['pending']; ?>)
        </a>
        <a href="<?php echo esc_url(add_query_arg('gstatus', 1)); ?>" 
           class="qls-tab <?php echo $status === 1 ? 'active' : ''; ?>">
            <?php _e('拼团成功', 'qilingshop'); ?> (<?php echo (int) $counts['success']; ?>)
        </a>
        <a href="<?php echo esc_url(add_query_arg('gstatus', 2)); ?>" 
           class="qls-tab <?php echo $status === 2 ? 'active' : ''; ?>">
            <?php _e('拼团失败', 'qilingshop'); ?> (<?php echo (int) $counts['failed']; ?>)
        </a>
    </div>

    <?php if (empty($groups)): ?>
    <div class="qls-empty-state">
        <span class="dashicons dashicons-groups" style="font-size: 48px; color: #ddd;"></span>
        <p><?php _e('暂无拼团记录', 'qilingshop'); ?></p>
        <a href="<?php echo esc_url(qls_group_public()->get_group_center_url()); ?>" class="qls-btn qls-btn-primary">
            <?php _e('去拼团', 'qilingshop'); ?>
        </a>
    </div>
    <?php else: ?>
    <div class="qls-group-list">
        <?php foreach ($groups as $group): 
            $image_url = '';
            if (is_string($group->product_image)) {
                $decoded = json_decode($group->product_image, true);
                $image_url = is_array($decoded) ? ($decoded['url'] ?? '') : $group->product_image;
            }
            
            $group_url = qls_group_public()->get_group_detail_url($group->id);
        ?>
        <div class="qls-group-item">
            <div class="qls-group-item-header">
                <span class="qls-group-item-status <?php echo esc_attr(qls_group()->get_status_badge_class($group->status)); ?>">
                    <?php echo esc_html($group->status_text); ?>
                </span>
                <?php if ($group->is_leader): ?>
                <span class="qls-leader-tag"><?php _e('团长', 'qilingshop'); ?></span>
                <?php endif; ?>
                <span class="qls-group-item-time"><?php echo esc_html($group->joined_at); ?></span>
            </div>
            
            <a href="<?php echo esc_url($group_url); ?>" class="qls-group-item-body">
                <div class="qls-group-item-image">
                    <img src="<?php echo esc_url($image_url); ?>" alt="">
                </div>
                <div class="qls-group-item-info">
                    <h4 class="qls-group-item-title"><?php echo esc_html($group->product_title ?: __('商品已删除', 'qilingshop')); ?></h4>
                    <div class="qls-group-item-price">¥<?php echo number_format((float) $group->group_price, 2); ?></div>
                    <div class="qls-group-item-progress">
                        <?php printf(__('%d/%d人', 'qilingshop'), (int) $group->current_size, (int) $group->target_size); ?>
                    </div>
                </div>
                <div class="qls-group-item-arrow">
                    <span class="dashicons dashicons-arrow-right-alt2"></span>
                </div>
            </a>
            
            <div class="qls-group-item-footer">
                <?php if ($group->status == 0): ?>
                    <?php if ($group->remain_seconds > 0): ?>
                    <span class="qls-countdown-mini" data-seconds="<?php echo (int) $group->remain_seconds; ?>">
                        <?php 
                        $remain_seconds = (int) $group->remain_seconds;
                        $hours = floor($remain_seconds / 3600);
                        $mins = floor(($remain_seconds % 3600) / 60);
                        echo esc_html(sprintf(__('剩余 %02d:%02d:%02d', 'qilingshop'), $hours, $mins, $remain_seconds % 60));
                        ?>
                    </span>
                    <a href="<?php echo esc_url($group_url); ?>" class="qls-btn qls-btn-small qls-btn-primary">
                        <?php _e('邀请好友', 'qilingshop'); ?>
                    </a>
                    <?php else: ?>
                    <span class="qls-expired"><?php _e('已过期，等待系统处理', 'qilingshop'); ?></span>
                    <?php endif; ?>
                <?php elseif ($group->status == 1): ?>
                    <span class="qls-success-text"><?php _e('拼团成功，等待发货', 'qilingshop'); ?></span>
                    <a href="<?php echo esc_url(qls_shop_public()->get_orders_url()); ?>" class="qls-btn qls-btn-small">
                        <?php _e('查看订单', 'qilingshop'); ?>
                    </a>
                <?php else: ?>
                    <span class="qls-failed-text"><?php _e('已退款至余额', 'qilingshop'); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- 分页 -->
    <?php if ($total_pages > 1): ?>
    <div class="qls-pagination">
        <?php
        $group_pagination = paginate_links([
            'base'      => add_query_arg('gpage', '%#%'),
            'format'    => '',
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
            'total'     => $total_pages,
            'current'   => $page,
        ]);
        if ($group_pagination) {
            echo wp_kses_post($group_pagination);
        }
        ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
