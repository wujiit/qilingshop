<?php
/**
 * 拼团管理列表页面
 * 
 * @package QilingShop
 * @since   2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// 消息提示
$message = isset($_GET['message']) ? sanitize_key($_GET['message']) : '';
$keyword = isset($keyword) ? (string) $keyword : '';
$has_filters = !is_null($status) || $keyword !== '';
?>

<div class="wrap qilingshop-admin-page qls-wrap">
    <div class="qls-page-header">
        <h1 class="qls-page-title"><?php _e('拼团管理', 'qilingshop'); ?></h1>
    </div>

    <?php if ($message === 'success'): ?>
    <div class="notice notice-success is-dismissible">
        <p><?php _e('操作成功！', 'qilingshop'); ?></p>
    </div>
    <?php elseif ($message === 'failed'): ?>
    <div class="notice notice-warning is-dismissible">
        <p><?php _e('团购已解散，退款已处理', 'qilingshop'); ?></p>
    </div>
    <?php elseif ($message === 'noop'): ?>
    <div class="notice notice-warning is-dismissible">
        <p><?php _e('操作未生效，当前拼团状态已变化', 'qilingshop'); ?></p>
    </div>
    <?php endif; ?>

    <!-- 统计卡片 -->
    <div class="qls-group-stats-grid">
        <div class="qls-group-stat-card">
            <div class="qls-group-stat-value qls-group-stat-total"><?php echo number_format($stats['total_groups']); ?></div>
            <div class="qls-group-stat-label"><?php _e('总拼团数', 'qilingshop'); ?></div>
        </div>
        <div class="qls-group-stat-card">
            <div class="qls-group-stat-value qls-group-stat-grouping"><?php echo number_format($stats['grouping_count']); ?></div>
            <div class="qls-group-stat-label"><?php _e('拼团中', 'qilingshop'); ?></div>
        </div>
        <div class="qls-group-stat-card">
            <div class="qls-group-stat-value qls-group-stat-success"><?php echo number_format($stats['success_count']); ?></div>
            <div class="qls-group-stat-label"><?php _e('成团成功', 'qilingshop'); ?></div>
        </div>
        <div class="qls-group-stat-card">
            <div class="qls-group-stat-value qls-group-stat-failed"><?php echo number_format($stats['failed_count']); ?></div>
            <div class="qls-group-stat-label"><?php _e('拼团失败', 'qilingshop'); ?></div>
        </div>
        <div class="qls-group-stat-card">
            <div class="qls-group-stat-value qls-group-stat-rate"><?php echo $stats['success_rate']; ?>%</div>
            <div class="qls-group-stat-label"><?php _e('成团率', 'qilingshop'); ?></div>
        </div>
    </div>

    <!-- 筛选 -->
    <ul class="qls-chip-nav" aria-label="<?php esc_attr_e('拼团状态筛选', 'qilingshop'); ?>">
        <li>
            <a href="<?php echo admin_url('admin.php?page=qls-group-manage'); ?>" 
               class="<?php echo is_null($status) ? 'current' : ''; ?>">
                <?php _e('全部', 'qilingshop'); ?>
                <span class="count">(<?php echo $stats['total_groups']; ?>)</span>
            </a>
        </li>
        <li>
            <a href="<?php echo admin_url('admin.php?page=qls-group-manage&status=0'); ?>" 
               class="<?php echo $status === 0 ? 'current' : ''; ?>">
                <?php _e('拼团中', 'qilingshop'); ?>
                <span class="count">(<?php echo $stats['grouping_count']; ?>)</span>
            </a>
        </li>
        <li>
            <a href="<?php echo admin_url('admin.php?page=qls-group-manage&status=1'); ?>" 
               class="<?php echo $status === 1 ? 'current' : ''; ?>">
                <?php _e('成功', 'qilingshop'); ?>
                <span class="count">(<?php echo $stats['success_count']; ?>)</span>
            </a>
        </li>
        <li>
            <a href="<?php echo admin_url('admin.php?page=qls-group-manage&status=2'); ?>" 
               class="<?php echo $status === 2 ? 'current' : ''; ?>">
                <?php _e('失败', 'qilingshop'); ?>
                <span class="count">(<?php echo $stats['failed_count']; ?>)</span>
            </a>
        </li>
    </ul>

    <div class="qls-toolbar qls-toolbar-between">
        <form method="get" class="qls-search-form qls-toolbar-search">
            <input type="hidden" name="page" value="qls-group-manage">
            <?php if (!is_null($status)): ?>
            <input type="hidden" name="status" value="<?php echo esc_attr($status); ?>">
            <?php endif; ?>
            <input type="search" name="s" value="<?php echo esc_attr($keyword); ?>" placeholder="<?php esc_attr_e('搜索拼团编号、商品编号、商品名...', 'qilingshop'); ?>">
            <button type="submit" class="button"><?php _e('搜索', 'qilingshop'); ?></button>
            <?php if ($has_filters): ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=qls-group-manage')); ?>" class="button button-secondary"><?php _e('清除筛选', 'qilingshop'); ?></a>
            <?php endif; ?>
        </form>
    </div>

    <!-- 列表 -->
    <table class="wp-list-table qls-ui-table widefat fixed striped qls-group-table">
        <thead>
            <tr>
                <th class="qls-w-60"><?php _e('团ID', 'qilingshop'); ?></th>
                <th class="qls-w-280"><?php _e('商品', 'qilingshop'); ?></th>
                <th class="qls-w-150"><?php _e('团长', 'qilingshop'); ?></th>
                <th class="qls-w-100"><?php _e('团购价', 'qilingshop'); ?></th>
                <th class="qls-w-100"><?php _e('进度', 'qilingshop'); ?></th>
                <th class="qls-w-100"><?php _e('状态', 'qilingshop'); ?></th>
                <th class="qls-w-160"><?php _e('剩余时间', 'qilingshop'); ?></th>
                <th class="qls-w-160"><?php _e('开团时间', 'qilingshop'); ?></th>
                <th><?php _e('操作', 'qilingshop'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($groups)): ?>
            <tr>
                <td colspan="9" class="qls-group-empty qls-empty-cell">
                    <div class="qls-empty-state-admin">
                        <span class="dashicons dashicons-groups qls-group-empty-icon"></span>
                        <strong><?php echo $has_filters ? esc_html__('没有找到匹配的拼团', 'qilingshop') : esc_html__('暂无拼团记录', 'qilingshop'); ?></strong>
                        <p><?php echo $has_filters ? esc_html__('换个关键词或清除筛选再试试。', 'qilingshop') : esc_html__('用户发起拼团后会显示在这里。', 'qilingshop'); ?></p>
                        <?php if ($has_filters): ?>
                        <div class="qls-empty-actions">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=qls-group-manage')); ?>" class="button"><?php _e('清除筛选', 'qilingshop'); ?></a>
                        </div>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($groups as $group): ?>
            <tr>
                <td><strong>#<?php echo $group->id; ?></strong></td>
                <td>
                    <div class="qls-group-product-cell">
                        <?php if ($group->product_image): ?>
                        <img src="<?php echo esc_url($group->product_image); ?>" 
                             class="qls-group-product-image">
                        <?php endif; ?>
                        <div class="qls-nowrap-ellipsis">
                            <?php echo esc_html($group->product_title ?: __('商品已删除', 'qilingshop')); ?>
                        </div>
                    </div>
                </td>
                <td>
                    <div class="qls-group-leader">
                        <img src="<?php echo esc_url($group->leader_avatar); ?>" 
                             class="qls-group-leader-avatar">
                        <span><?php echo esc_html($group->leader_name); ?></span>
                    </div>
                </td>
                <td>
                    <span class="qls-group-price">¥<?php echo number_format($group->group_price, 2); ?></span>
                </td>
                <td>
                    <span class="qls-group-progress-current"><?php echo $group->current_size; ?></span>
                    <span class="qls-text-muted"><?php printf(esc_html__('/%s人', 'qilingshop'), esc_html($group->target_size)); ?></span>
                </td>
                <td>
                    <span class="qls-group-status-badge <?php echo $group->status_badge; ?>">
                        <?php echo $group->status_text; ?>
                    </span>
                </td>
                <td>
                    <?php if ($group->status == 0): ?>
                        <?php if ($group->remain_seconds > 0): ?>
                        <span class="qls-countdown qls-group-countdown" data-seconds="<?php echo $group->remain_seconds; ?>">
                            <?php 
                            $hours = floor($group->remain_seconds / 3600);
                            $mins = floor(($group->remain_seconds % 3600) / 60);
                            echo sprintf('%02d:%02d:%02d', $hours, $mins, $group->remain_seconds % 60);
                            ?>
                        </span>
                        <?php else: ?>
                        <span class="qls-text-muted"><?php _e('已过期', 'qilingshop'); ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="qls-text-muted">-</span>
                    <?php endif; ?>
                </td>
                <td><?php echo $group->created_at; ?></td>
                <td>
                    <?php if ($group->status == 0): ?>
                    <a href="<?php echo esc_url(wp_nonce_url(
                        admin_url('admin.php?page=qls-group-manage&action=force_success&group_id=' . $group->id),
                        'qls_group_action_' . $group->id
                    )); ?>" class="button button-small qls-confirm-link"
                       data-confirm-title="<?php echo esc_attr__('手动成团', 'qilingshop'); ?>"
                       data-confirm-message="<?php echo esc_attr__('确定要将该拼团直接标记为成团成功吗？', 'qilingshop'); ?>"
                       data-confirm-ok="<?php echo esc_attr__('确认成团', 'qilingshop'); ?>">
                        <?php _e('手动成团', 'qilingshop'); ?>
                    </a>
                    <a href="<?php echo esc_url(wp_nonce_url(
                        admin_url('admin.php?page=qls-group-manage&action=force_fail&group_id=' . $group->id),
                        'qls_group_action_' . $group->id
                    )); ?>" class="button button-small qls-button-danger qls-confirm-link"
                       data-confirm-title="<?php echo esc_attr__('解散并退款', 'qilingshop'); ?>"
                       data-confirm-message="<?php echo esc_attr__('确定要解散此团并触发退款处理吗？该操作会影响已参与用户。', 'qilingshop'); ?>"
                       data-confirm-ok="<?php echo esc_attr__('确认解散', 'qilingshop'); ?>">
                        <?php _e('解散退款', 'qilingshop'); ?>
                    </a>
                    <?php elseif ($group->status == 1): ?>
                    <?php $is_shipped = get_option('_qls_group_shipped_' . $group->id); ?>
                    <?php if ($is_shipped): ?>
                    <span class="button button-small qls-button-disabled">
                        <?php _e('已发货', 'qilingshop'); ?>
                    </span>
                    <?php else: ?>
                    <a href="<?php echo admin_url('admin.php?page=qls-shop-orders&group_id=' . $group->id . '&status=1'); ?>" class="button button-small button-primary">
                        <?php _e('去发货', 'qilingshop'); ?>
                    </a>
                    <a href="<?php echo esc_url(wp_nonce_url(
                        admin_url('admin.php?page=qls-group-manage&action=mark_shipped&group_id=' . $group->id),
                        'qls_group_action_' . $group->id
                    )); ?>" class="button button-small qls-confirm-link"
                       data-confirm-title="<?php echo esc_attr__('标记已发货', 'qilingshop'); ?>"
                       data-confirm-message="<?php echo esc_attr__('确认该拼团关联订单已完成发货处理吗？', 'qilingshop'); ?>"
                       data-confirm-ok="<?php echo esc_attr__('确认标记', 'qilingshop'); ?>">
                        <?php _e('已发货', 'qilingshop'); ?>
                    </a>
                    <?php endif; ?>
                    <?php else: ?>
                    <span class="qls-text-muted">-</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- 分页 -->
    <?php if ($total > $per_page): ?>
    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <?php
            echo paginate_links([
                'base'      => add_query_arg('paged', '%#%'),
                'format'    => '',
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
                'total'     => ceil($total / $per_page),
                'current'   => $page,
            ]);
            ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
    // 倒计时更新
    function updateCountdowns() {
        $('.qls-countdown').each(function() {
            var $el = $(this);
            var seconds = parseInt($el.data('seconds'));
            
            if (seconds > 0) {
                seconds--;
                $el.data('seconds', seconds);
                
                var hours = Math.floor(seconds / 3600);
                var mins = Math.floor((seconds % 3600) / 60);
                var secs = seconds % 60;
                
                $el.text(
                    String(hours).padStart(2, '0') + ':' +
                    String(mins).padStart(2, '0') + ':' +
                    String(secs).padStart(2, '0')
                );
            } else {
                $el.text('<?php _e('已过期', 'qilingshop'); ?>').removeClass('qls-group-countdown').addClass('qls-text-muted');
            }
        });
    }
    
    // 每秒更新倒计时
    setInterval(updateCountdowns, 1000);
});
</script>
