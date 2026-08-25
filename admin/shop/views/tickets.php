<?php
/**
 * 售后工单管理视图。
 */
if (!defined('ABSPATH')) exit;

$status_labels = $ticket_manager->get_statuses();
$type_labels = $ticket_manager->get_types();
$current_status = isset($status) ? (string) $status : '';
$current_type = isset($type) ? (string) $type : '';
$keyword = isset($keyword) ? (string) $keyword : '';
$tickets = isset($tickets) && is_array($tickets) ? $tickets : [];
$counts = isset($counts) && is_array($counts) ? $counts : [];
$ticket_user_map = isset($ticket_user_map) && is_array($ticket_user_map) ? $ticket_user_map : [];
$ticket_order_map = isset($ticket_order_map) && is_array($ticket_order_map) ? $ticket_order_map : [];
$total = isset($total) ? max(0, (int) $total) : 0;
$paged = isset($paged) ? max(1, (int) $paged) : 1;
$limit = isset($limit) ? max(1, (int) $limit) : 20;
$ticket_attachment_max_count = method_exists($ticket_manager, 'get_max_attachment_count') ? $ticket_manager->get_max_attachment_count() : 3;
$ticket_attachment_accept = method_exists($ticket_manager, 'get_attachment_accept_attribute') ? $ticket_manager->get_attachment_accept_attribute() : 'image/jpeg,image/png,image/webp,application/pdf';
$ticket_attachment_help = method_exists($ticket_manager, 'get_attachment_help_text') ? $ticket_manager->get_attachment_help_text() : __('最多 3 个，单个不超过 5MB，支持 JPG、PNG、WebP、PDF。', 'qilingshop');
?>
<div class="wrap qilingshop-admin-page qls-shop-wrap qls-ticket-admin-page">
    <h1><?php _e('售后工单', 'qilingshop'); ?></h1>

    <?php settings_errors('qls_shop_tickets'); ?>

    <?php if (!empty($current_ticket_id)): ?>
        <?php if (!$current_ticket): ?>
            <div class="notice notice-error"><p><?php _e('工单不存在。', 'qilingshop'); ?></p></div>
            <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=qls-shop-tickets')); ?>"><?php _e('返回工单列表', 'qilingshop'); ?></a></p>
        <?php else: ?>
            <?php
            $detail_status = (int) $current_ticket->status;
            $detail_order_no = $current_ticket_order && !empty($current_ticket_order->order_no) ? (string) $current_ticket_order->order_no : '';
            $detail_user_name = $current_ticket_user ? $current_ticket_user->display_name : __('用户已删除', 'qilingshop');
            ?>
            <p>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=qls-shop-tickets')); ?>"><?php _e('返回工单列表', 'qilingshop'); ?></a>
            </p>

            <div class="qls-ticket-detail-header">
                <div>
                    <h2><?php echo esc_html($current_ticket->title); ?></h2>
                    <p class="description">
                        <?php echo esc_html($current_ticket->ticket_no); ?>
                        · <?php echo esc_html($ticket_manager->get_type_text($current_ticket->type)); ?>
                        · <?php echo esc_html($current_ticket->created_at); ?>
                    </p>
                </div>
                <span class="qls-ticket-status <?php echo esc_attr($ticket_manager->get_status_badge_class($detail_status)); ?>">
                    <?php echo esc_html($ticket_manager->get_status_text($detail_status)); ?>
                </span>
            </div>

            <div class="qls-ticket-detail-grid">
                <div class="qls-ticket-panel">
                    <h3><?php _e('工单信息', 'qilingshop'); ?></h3>
                    <table class="widefat striped">
                        <tbody>
                            <tr>
                                <th><?php _e('用户', 'qilingshop'); ?></th>
                                <td><?php echo esc_html($detail_user_name); ?> (ID: <?php echo (int) $current_ticket->user_id; ?>)</td>
                            </tr>
                            <tr>
                                <th><?php _e('关联订单', 'qilingshop'); ?></th>
                                <td>
                                    <?php if ($detail_order_no !== ''): ?>
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=qls-shop-orders&s=' . rawurlencode($detail_order_no))); ?>">
                                            <?php echo esc_html($detail_order_no); ?>
                                        </a>
                                    <?php else: ?>
                                        <?php _e('未关联订单', 'qilingshop'); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th><?php _e('商品/资源', 'qilingshop'); ?></th>
                                <td>
                                    <?php
                                    $related_parts = [];
                                    if (!empty($current_ticket->product_id)) {
                                        $related_parts[] = sprintf(__('商品 #%d', 'qilingshop'), (int) $current_ticket->product_id);
                                    }
                                    if (!empty($current_ticket->resource_id)) {
                                        $related_parts[] = sprintf(__('资源 #%d', 'qilingshop'), (int) $current_ticket->resource_id);
                                    }
                                    if (!empty($current_ticket->card_id)) {
                                        $related_parts[] = sprintf(__('卡密 #%d', 'qilingshop'), (int) $current_ticket->card_id);
                                    }
                                    echo esc_html(!empty($related_parts) ? implode(' / ', $related_parts) : __('未指定', 'qilingshop'));
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <th><?php _e('最后回复', 'qilingshop'); ?></th>
                                <td><?php echo esc_html($current_ticket->last_reply_at ?: $current_ticket->updated_at); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="qls-ticket-panel">
                    <h3><?php _e('后台处理', 'qilingshop'); ?></h3>
                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field('qls_ticket_admin_action'); ?>
                        <input type="hidden" name="qls_ticket_action" value="reply">
                        <input type="hidden" name="ticket_id" value="<?php echo (int) $current_ticket->id; ?>">

                        <p>
                            <label for="qls-ticket-status"><strong><?php _e('状态', 'qilingshop'); ?></strong></label>
                            <select id="qls-ticket-status" name="status" class="regular-text">
                                <option value=""><?php _e('自动处理（回复后待用户回复）', 'qilingshop'); ?></option>
                                <?php foreach ($status_labels as $status_value => $status_label): ?>
                                    <option value="<?php echo esc_attr($status_value); ?>">
                                        <?php echo esc_html($status_label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </p>

                        <p>
                            <label for="qls-ticket-reply"><strong><?php _e('回复用户', 'qilingshop'); ?></strong></label>
                            <textarea id="qls-ticket-reply" name="reply_message" rows="5" class="large-text" placeholder="<?php esc_attr_e('填写给用户看的回复内容', 'qilingshop'); ?>"></textarea>
                        </p>

                        <p>
                            <label for="qls-ticket-attachments"><strong><?php _e('附件', 'qilingshop'); ?></strong></label>
                            <?php if ($ticket_attachment_max_count > 0 && $ticket_attachment_accept !== ''): ?>
                                <input id="qls-ticket-attachments" type="file" name="ticket_attachments[]" <?php echo $ticket_attachment_max_count > 1 ? 'multiple' : ''; ?> accept="<?php echo esc_attr($ticket_attachment_accept); ?>">
                                <span class="description"><?php echo esc_html($ticket_attachment_help); ?></span>
                            <?php else: ?>
                                <span class="description"><?php _e('当前未开放附件上传。', 'qilingshop'); ?></span>
                            <?php endif; ?>
                        </p>

                        <p>
                            <label for="qls-ticket-note"><strong><?php _e('内部备注', 'qilingshop'); ?></strong></label>
                            <textarea id="qls-ticket-note" name="internal_note" rows="3" class="large-text" placeholder="<?php esc_attr_e('仅后台可见，可记录处理线索', 'qilingshop'); ?>"></textarea>
                        </p>

                        <?php submit_button(__('保存处理', 'qilingshop'), 'primary', 'submit', false); ?>
                    </form>
                </div>
            </div>

            <div class="qls-ticket-panel qls-ticket-messages">
                <h3><?php _e('沟通记录', 'qilingshop'); ?></h3>
                <?php if (empty($current_ticket_messages)): ?>
                    <p class="description"><?php _e('暂无消息。', 'qilingshop'); ?></p>
                <?php else: ?>
                    <?php foreach ($current_ticket_messages as $message): ?>
                        <?php
                        $sender_type = sanitize_key((string) ($message->sender_type ?? 'system'));
                        $is_internal = !empty($message->is_internal);
                        $author_id = isset($message->author_id) ? (int) $message->author_id : 0;
                        $attachments = $ticket_manager->get_message_attachments($message);
                        $author_name = $author_id > 0 && isset($message_author_map[$author_id]) ? $message_author_map[$author_id] : '';
                        if ($author_name === '') {
                            $author_name = $sender_type === 'user' ? __('用户', 'qilingshop') : __('系统/后台', 'qilingshop');
                        }
                        ?>
                        <div class="qls-ticket-message <?php echo esc_attr('is-' . $sender_type); ?> <?php echo $is_internal ? 'is-internal' : ''; ?>">
                            <div class="qls-ticket-message-meta">
                                <strong><?php echo esc_html($author_name); ?></strong>
                                <span><?php echo esc_html($message->created_at); ?></span>
                                <?php if ($is_internal): ?>
                                    <em><?php _e('内部备注', 'qilingshop'); ?></em>
                                <?php endif; ?>
                            </div>
                            <div class="qls-ticket-message-body">
                                <?php echo nl2br(esc_html($message->message)); ?>
                            </div>
                            <?php if (!empty($attachments)): ?>
                                <div class="qls-ticket-attachments">
                                    <?php foreach ($attachments as $attachment): ?>
                                        <?php
                                        $attachment_url = isset($attachment['url']) ? (string) $attachment['url'] : '';
                                        $attachment_name = isset($attachment['name']) ? (string) $attachment['name'] : __('附件', 'qilingshop');
                                        $attachment_type = isset($attachment['type']) ? (string) $attachment['type'] : '';
                                        if ($attachment_url === '') {
                                            continue;
                                        }
                                        ?>
                                        <a class="qls-ticket-attachment" href="<?php echo esc_url($attachment_url); ?>" target="_blank" rel="noopener">
                                            <?php if (strpos($attachment_type, 'image/') === 0): ?>
                                                <img src="<?php echo esc_url($attachment_url); ?>" alt="<?php echo esc_attr($attachment_name); ?>">
                                            <?php else: ?>
                                                <span><?php _e('PDF', 'qilingshop'); ?></span>
                                            <?php endif; ?>
                                            <em><?php echo esc_html($attachment_name); ?></em>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <ul class="subsubsub qls-ticket-status-tabs">
            <li>
                <a href="<?php echo esc_url(admin_url('admin.php?page=qls-shop-tickets')); ?>" class="<?php echo $current_status === '' ? 'current' : ''; ?>">
                    <?php _e('全部', 'qilingshop'); ?>
                    <span class="count">(<?php echo (int) array_sum($counts); ?>)</span>
                </a> |
            </li>
            <?php $status_index = 0; ?>
            <?php foreach ($status_labels as $status_value => $status_label): ?>
                <?php $status_index++; ?>
                <li>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=qls-shop-tickets&status=' . (int) $status_value)); ?>" class="<?php echo $current_status === (string) $status_value ? 'current' : ''; ?>">
                        <?php echo esc_html($status_label); ?>
                        <span class="count">(<?php echo (int) ($counts[$status_value] ?? 0); ?>)</span>
                    </a>
                    <?php echo $status_index < count($status_labels) ? '|' : ''; ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <form method="get" class="qls-search-form qls-ticket-filter-form">
            <input type="hidden" name="page" value="qls-shop-tickets">
            <?php if ($current_status !== ''): ?>
                <input type="hidden" name="status" value="<?php echo esc_attr($current_status); ?>">
            <?php endif; ?>
            <select name="type">
                <option value=""><?php _e('全部类型', 'qilingshop'); ?></option>
                <?php foreach ($type_labels as $type_value => $type_label): ?>
                    <option value="<?php echo esc_attr($type_value); ?>" <?php selected($current_type, $type_value); ?>>
                        <?php echo esc_html($type_label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="search" name="s" value="<?php echo esc_attr($keyword); ?>" placeholder="<?php esc_attr_e('搜索工单号、标题、说明', 'qilingshop'); ?>">
            <button type="submit" class="button"><?php _e('筛选', 'qilingshop'); ?></button>
        </form>

        <table class="wp-list-table qls-ui-table widefat fixed striped qls-tickets-table">
            <thead>
                <tr>
                    <th><?php _e('工单', 'qilingshop'); ?></th>
                    <th><?php _e('类型', 'qilingshop'); ?></th>
                    <th><?php _e('用户', 'qilingshop'); ?></th>
                    <th><?php _e('关联订单', 'qilingshop'); ?></th>
                    <th><?php _e('状态', 'qilingshop'); ?></th>
                    <th><?php _e('最后回复', 'qilingshop'); ?></th>
                    <th><?php _e('创建时间', 'qilingshop'); ?></th>
                    <th><?php _e('操作', 'qilingshop'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tickets)): ?>
                    <tr>
                        <td colspan="8" class="no-items"><?php _e('暂无工单', 'qilingshop'); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($tickets as $ticket): ?>
                        <?php
                        $ticket_status = (int) $ticket->status;
                        $user_id = isset($ticket->user_id) ? (int) $ticket->user_id : 0;
                        $order_id = isset($ticket->order_id) ? (int) $ticket->order_id : 0;
                        $order = $order_id > 0 && isset($ticket_order_map[$order_id]) ? $ticket_order_map[$order_id] : null;
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($ticket->ticket_no); ?></strong>
                                <div class="qls-ticket-subtext"><?php echo esc_html($ticket->title); ?></div>
                            </td>
                            <td><?php echo esc_html($ticket_manager->get_type_text($ticket->type)); ?></td>
                            <td><?php echo $user_id > 0 && isset($ticket_user_map[$user_id]) ? esc_html($ticket_user_map[$user_id]) : esc_html__('用户已删除', 'qilingshop'); ?></td>
                            <td>
                                <?php if ($order && !empty($order->order_no)): ?>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=qls-shop-orders&s=' . rawurlencode((string) $order->order_no))); ?>">
                                        <?php echo esc_html($order->order_no); ?>
                                    </a>
                                <?php else: ?>
                                    <span class="qls-ticket-subtext"><?php _e('未关联', 'qilingshop'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="qls-ticket-status <?php echo esc_attr($ticket_manager->get_status_badge_class($ticket_status)); ?>">
                                    <?php echo esc_html($ticket_manager->get_status_text($ticket_status)); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo esc_html($ticket->last_reply_at ?: $ticket->updated_at); ?>
                                <div class="qls-ticket-subtext"><?php echo esc_html($ticket->last_reply_by === 'admin' ? __('后台', 'qilingshop') : __('用户', 'qilingshop')); ?></div>
                            </td>
                            <td><?php echo esc_html($ticket->created_at); ?></td>
                            <td>
                                <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=qls-shop-tickets&ticket_id=' . (int) $ticket->id)); ?>">
                                    <?php _e('处理', 'qilingshop'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php
        $total_pages = (int) ceil($total / $limit);
        if ($total_pages > 1):
            $pagination = paginate_links([
                'base'      => add_query_arg('paged', '%#%'),
                'format'    => '',
                'current'   => $paged,
                'total'     => $total_pages,
                'prev_text' => __('&laquo;', 'qilingshop'),
                'next_text' => __('&raquo;', 'qilingshop'),
            ]);
        ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages"><?php echo wp_kses_post($pagination); ?></div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<style>
.qls-ticket-admin-page .qls-ticket-detail-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    margin: 16px 0;
    padding: 18px;
    background: #fff;
    border: 1px solid #dcdcde;
}
.qls-ticket-detail-header h2 { margin: 0 0 6px; }
.qls-ticket-status {
    display: inline-flex;
    align-items: center;
    min-height: 24px;
    padding: 0 9px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    background: #f0f0f1;
    color: #3c434a;
}
.qls-ticket-status.is-open { background: #fff4e5; color: #8a4b00; }
.qls-ticket-status.is-processing { background: #e7f0ff; color: #0954a5; }
.qls-ticket-status.is-waiting { background: #f0f6fc; color: #1d4ed8; }
.qls-ticket-status.is-resolved { background: #edfaef; color: #247f3d; }
.qls-ticket-status.is-closed { background: #f6f7f7; color: #646970; }
.qls-ticket-detail-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(320px, 420px);
    gap: 16px;
    align-items: start;
}
.qls-ticket-panel {
    background: #fff;
    border: 1px solid #dcdcde;
    padding: 16px;
    margin-bottom: 16px;
}
.qls-ticket-panel h3 { margin-top: 0; }
.qls-ticket-message {
    border: 1px solid #dcdcde;
    border-left-width: 4px;
    padding: 12px;
    margin-bottom: 12px;
    background: #fff;
}
.qls-ticket-message.is-user { border-left-color: #2271b1; }
.qls-ticket-message.is-admin { border-left-color: #46b450; }
.qls-ticket-message.is-internal {
    border-left-color: #dba617;
    background: #fffaf0;
}
.qls-ticket-message-meta {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-bottom: 8px;
    color: #646970;
}
.qls-ticket-message-meta strong { color: #1d2327; }
.qls-ticket-message-meta em {
    padding: 1px 6px;
    border-radius: 999px;
    background: #f6e8b1;
    color: #6f4d00;
    font-style: normal;
    font-size: 12px;
}
.qls-ticket-message-body { line-height: 1.7; }
.qls-ticket-attachments {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
}
.qls-ticket-attachment {
    display: inline-grid;
    grid-template-columns: 52px minmax(0, 120px);
    gap: 8px;
    align-items: center;
    padding: 6px;
    border: 1px solid #dcdcde;
    border-radius: 4px;
    background: #fff;
    text-decoration: none;
}
.qls-ticket-attachment img,
.qls-ticket-attachment span {
    width: 52px;
    height: 42px;
    border-radius: 3px;
    object-fit: cover;
    background: #f0f0f1;
}
.qls-ticket-attachment span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #646970;
    font-weight: 700;
    font-size: 12px;
}
.qls-ticket-attachment em {
    overflow: hidden;
    color: #1d2327;
    font-style: normal;
    font-size: 12px;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.qls-ticket-subtext {
    margin-top: 4px;
    color: #646970;
    font-size: 12px;
    line-height: 1.5;
}
.qls-ticket-filter-form {
    display: flex;
    gap: 8px;
    align-items: center;
    margin: 12px 0;
}
.qls-ticket-filter-form input[type="search"] { min-width: 260px; }
@media (max-width: 960px) {
    .qls-ticket-detail-grid { grid-template-columns: 1fr; }
}
</style>
