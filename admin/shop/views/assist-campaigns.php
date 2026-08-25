<?php
/**
 * 好友助力记录
 */
if (!defined('ABSPATH')) {
    exit;
}

$current_status = isset($_GET['status']) ? (string) $_GET['status'] : '';
$all_count = isset($all_count) ? (int) $all_count : (int) $total;
$campaign_user_map = isset($campaign_user_map) && is_array($campaign_user_map) ? $campaign_user_map : [];
$view_log_actor_map = isset($view_log_actor_map) && is_array($view_log_actor_map) ? $view_log_actor_map : [];
?>
<div class="wrap qilingshop-admin-page qls-shop-wrap">
    <div class="qls-page-header">
        <h1 class="qls-page-title"><?php _e('好友助力记录', 'qilingshop'); ?></h1>
    </div>

    <?php settings_errors('qls_assist_campaigns'); ?>

    <ul class="qls-chip-nav" aria-label="<?php esc_attr_e('助力记录筛选', 'qilingshop'); ?>">
        <li>
            <a href="<?php echo esc_url(admin_url('admin.php?page=qls-assist-campaigns')); ?>" class="<?php echo $current_status === '' ? 'current' : ''; ?>">
                <?php _e('全部', 'qilingshop'); ?>
                <span class="count">(<?php echo (int) $all_count; ?>)</span>
            </a>
        </li>
        <?php foreach ($campaign_statuses as $st): ?>
        <li>
            <a href="<?php echo esc_url(admin_url('admin.php?page=qls-assist-campaigns&status=' . $st)); ?>" class="<?php echo $current_status === (string) $st ? 'current' : ''; ?>">
                <?php echo esc_html(qls_assist()->get_campaign_status_text($st)); ?>
                <span class="count">(<?php echo (int) ($status_counts[$st] ?? 0); ?>)</span>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>

    <div class="qls-toolbar">
        <form method="get" class="qls-search-form qls-toolbar-search">
            <input type="hidden" name="page" value="qls-assist-campaigns">
            <?php if ($current_status !== ''): ?>
            <input type="hidden" name="status" value="<?php echo esc_attr($current_status); ?>">
            <?php endif; ?>
            <input type="search" name="s" value="<?php echo esc_attr($keyword); ?>" placeholder="<?php esc_attr_e('搜索分享码/活动/商品', 'qilingshop'); ?>">
            <button type="submit" class="button"><?php _e('搜索', 'qilingshop'); ?></button>
        </form>
        <form method="post"
              class="qls-confirm-form qls-inline-form qls-assist-clear-form"
              data-confirm-title="<?php echo esc_attr__('清除已结束记录', 'qilingshop'); ?>"
              data-confirm-message="<?php echo esc_attr__('将清除已完成、已过期、已取消、已退款的助力记录和日志；进行中、已达成待下单、待支付记录会保留。确认继续吗？', 'qilingshop'); ?>"
              data-confirm-ok="<?php echo esc_attr__('确认清除', 'qilingshop'); ?>">
            <?php wp_nonce_field('qls_assist_campaign_action'); ?>
            <input type="hidden" name="qls_assist_campaign_action" value="clear_ended">
            <button type="submit" class="button button-secondary" <?php disabled((int) $clearable_count <= 0); ?>>
                <?php _e('清除已结束记录', 'qilingshop'); ?>
            </button>
            <span class="description"><?php printf(__('可清除 %d 条', 'qilingshop'), (int) $clearable_count); ?></span>
        </form>
    </div>

    <table class="wp-list-table qls-ui-table widefat fixed striped">
        <thead>
            <tr>
                <th class="qls-w-70">ID</th>
                <th class="qls-w-120"><?php _e('分享码', 'qilingshop'); ?></th>
                <th><?php _e('活动/商品', 'qilingshop'); ?></th>
                <th class="qls-w-140"><?php _e('发起人', 'qilingshop'); ?></th>
                <th class="qls-w-130"><?php _e('价格进度', 'qilingshop'); ?></th>
                <th class="qls-w-120"><?php _e('助力进度', 'qilingshop'); ?></th>
                <th class="qls-w-90"><?php _e('状态', 'qilingshop'); ?></th>
                <th class="qls-w-130"><?php _e('支付订单', 'qilingshop'); ?></th>
                <th class="qls-w-140"><?php _e('过期时间', 'qilingshop'); ?></th>
                <th class="qls-w-80"><?php _e('操作', 'qilingshop'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($campaigns)): ?>
            <tr>
                <td colspan="10" class="no-items"><?php _e('暂无记录', 'qilingshop'); ?></td>
            </tr>
            <?php else: ?>
            <?php foreach ($campaigns as $campaign): ?>
            <tr>
                <td><code><?php echo (int) $campaign->id; ?></code></td>
                <td><code><?php echo esc_html($campaign->share_code); ?></code></td>
                <td>
                    <strong><?php echo esc_html($campaign->activity_name); ?></strong>
                    <div class="qls-text-subtle"><?php echo esc_html($campaign->product_title); ?></div>
                </td>
                <td>
                    <?php
                    $campaign_user_id = isset($campaign->user_id) ? (int) $campaign->user_id : 0;
                    $campaign_user_name = $campaign_user_id > 0 && isset($campaign_user_map[$campaign_user_id]) ? (string) $campaign_user_map[$campaign_user_id] : '';
                    echo $campaign_user_name !== '' ? esc_html($campaign_user_name) : ('UID:' . $campaign_user_id);
                    ?>
                </td>
                <td>¥<?php echo number_format((float) $campaign->start_price, 2); ?> → <strong>¥<?php echo number_format((float) $campaign->current_price, 2); ?></strong><br><span class="qls-text-muted"><?php printf(esc_html__('最低 ¥%s', 'qilingshop'), esc_html(number_format((float) $campaign->min_price, 2))); ?></span></td>
                <td><?php echo (int) $campaign->help_count; ?><?php if ((int) $campaign->target_helpers > 0): ?> / <?php echo (int) $campaign->target_helpers; ?><?php endif; ?></td>
                <td><?php echo esc_html(qls_assist()->get_campaign_status_text((int) $campaign->status)); ?></td>
                <td>
                    <?php if (!empty($campaign->pay_order_no)): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=qls-shop-orders&s=' . rawurlencode($campaign->pay_order_no))); ?>">#<?php echo esc_html($campaign->pay_order_no); ?></a>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html($campaign->expire_at); ?></td>
                <td><a href="<?php echo esc_url(add_query_arg('campaign_id', (int) $campaign->id)); ?>"><?php _e('日志', 'qilingshop'); ?></a></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <?php
            $total_pages = (int) ceil(max(1, $total) / $limit);
            if ($total_pages > 1) {
                echo paginate_links([
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'total' => $total_pages,
                    'current' => $paged,
                ]);
            }
            ?>
        </div>
    </div>

    <?php if ($view_campaign): ?>
    <h2 class="qls-mt-26"><?php printf(__('助力日志 #%d', 'qilingshop'), (int) $view_campaign->id); ?></h2>
    <p>
        <strong><?php _e('活动', 'qilingshop'); ?>：</strong><?php echo esc_html($view_campaign->activity_name); ?>
        &nbsp;&nbsp;
        <strong><?php _e('分享码', 'qilingshop'); ?>：</strong><code><?php echo esc_html($view_campaign->share_code); ?></code>
    </p>

    <table class="wp-list-table qls-ui-table widefat fixed striped">
        <thead>
            <tr>
                <th class="qls-w-150"><?php _e('时间', 'qilingshop'); ?></th>
                <th class="qls-w-120"><?php _e('动作', 'qilingshop'); ?></th>
                <th class="qls-w-140"><?php _e('执行人', 'qilingshop'); ?></th>
                <th class="qls-w-100"><?php _e('金额变化', 'qilingshop'); ?></th>
                <th><?php _e('说明', 'qilingshop'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($view_logs)): ?>
            <tr><td colspan="5" class="no-items"><?php _e('暂无日志', 'qilingshop'); ?></td></tr>
            <?php else: ?>
            <?php foreach ($view_logs as $log): ?>
            <tr>
                <td><?php echo esc_html($log->created_at); ?></td>
                <td><code><?php echo esc_html($log->action); ?></code></td>
                <td>
                    <?php
                    if ((int) $log->actor_id > 0) {
                        $actor_id = (int) $log->actor_id;
                        $actor_name = isset($view_log_actor_map[$actor_id]) ? (string) $view_log_actor_map[$actor_id] : '';
                        echo $actor_name !== '' ? esc_html($actor_name) : ('UID:' . $actor_id);
                    } else {
                        echo esc_html($log->actor_role);
                    }
                    ?>
                </td>
                <td>
                    <?php if ((float) $log->amount > 0): ?>
                    -¥<?php echo number_format((float) $log->amount, 2); ?>
                    <?php else: ?>
                    -
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html($log->message); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
