<?php
/**
 * 售后工单模板。
 */
if (!defined('ABSPATH')) exit;

$ticket_manager = isset($ticket_manager) ? $ticket_manager : (function_exists('qls_shop_ticket') ? qls_shop_ticket() : null);
$tickets = isset($tickets) && is_array($tickets) ? $tickets : [];
$counts = isset($counts) && is_array($counts) ? $counts : [];
$total = isset($total) ? max(0, (int) $total) : 0;
$paged = isset($paged) ? max(1, (int) $paged) : 1;
$limit = isset($limit) ? max(1, (int) $limit) : 10;
$status = isset($status) ? $status : '';
$notice = isset($notice) ? (string) $notice : '';
$error = isset($error) ? (string) $error : '';
$current_ticket = isset($current_ticket) ? $current_ticket : null;
$current_messages = isset($current_messages) && is_array($current_messages) ? $current_messages : [];
$current_order = isset($current_order) ? $current_order : null;
$order_options = isset($order_options) && is_array($order_options) ? $order_options : [];
$selected_order_id = isset($selected_order_id) ? (int) $selected_order_id : 0;
$ticket_base_url = !empty($ticket_base_url) ? (string) $ticket_base_url : get_permalink();
$status_labels = $ticket_manager ? $ticket_manager->get_statuses() : [];
$type_labels = $ticket_manager ? $ticket_manager->get_types() : [];
$ticket_attachment_max_count = $ticket_manager && method_exists($ticket_manager, 'get_max_attachment_count') ? $ticket_manager->get_max_attachment_count() : 3;
$ticket_attachment_max_size = $ticket_manager && method_exists($ticket_manager, 'get_max_attachment_size') ? $ticket_manager->get_max_attachment_size() : 5 * 1024 * 1024;
$ticket_attachment_accept = $ticket_manager && method_exists($ticket_manager, 'get_attachment_accept_attribute') ? $ticket_manager->get_attachment_accept_attribute() : 'image/jpeg,image/png,image/webp,application/pdf';
$ticket_attachment_help = $ticket_manager && method_exists($ticket_manager, 'get_attachment_help_text') ? $ticket_manager->get_attachment_help_text() : __('最多 3 个，单个不超过 5MB，支持 JPG、PNG、WebP、PDF。', 'qilingshop');
$ticket_attachments_enabled = $ticket_attachment_max_count > 0 && $ticket_attachment_accept !== '';
$current_user = wp_get_current_user();

qls_shop_public()->get_shop_header(__('售后工单', 'qilingshop'));
?>

<div class="qls-account-page qls-my-tickets-page">
    <?php include QILINGSHOP_PATH . 'templates/shop/partials/account-header.php'; ?>

    <div class="qls-account-body">
        <div class="qls-container">
            <div class="qls-account-layout">
                <?php include QILINGSHOP_PATH . 'templates/shop/partials/account-sidebar.php'; ?>

                <main class="qls-account-main">
                    <div class="qls-section-header">
                        <h3><?php _e('售后工单', 'qilingshop'); ?></h3>
                        <?php if ($current_ticket): ?>
                            <a href="<?php echo esc_url($ticket_base_url); ?>" class="qls-btn qls-btn-outline"><?php _e('返回列表', 'qilingshop'); ?></a>
                        <?php endif; ?>
                    </div>

                    <?php if ($notice !== ''): ?>
                        <div class="qls-notice success"><?php echo esc_html($notice); ?></div>
                    <?php endif; ?>
                    <?php if ($error !== ''): ?>
                        <div class="qls-notice warning"><?php echo esc_html($error); ?></div>
                    <?php endif; ?>

                    <?php if ($current_ticket): ?>
                        <?php
                        $ticket_status = (int) $current_ticket->status;
                        $order_no = $current_order && !empty($current_order->order_no) ? (string) $current_order->order_no : '';
                        ?>
                        <section class="qls-ticket-detail">
                            <div class="qls-ticket-detail-head">
                                <div>
                                    <span class="qls-ticket-no"><?php echo esc_html($current_ticket->ticket_no); ?></span>
                                    <h4><?php echo esc_html($current_ticket->title); ?></h4>
                                    <p>
                                        <?php echo esc_html($ticket_manager->get_type_text($current_ticket->type)); ?>
                                        <?php if ($order_no !== ''): ?>
                                            · <?php _e('订单', 'qilingshop'); ?> <?php echo esc_html($order_no); ?>
                                        <?php endif; ?>
                                        · <?php echo esc_html(date_i18n('Y-m-d H:i', strtotime($current_ticket->created_at))); ?>
                                    </p>
                                </div>
                                <span class="qls-ticket-status <?php echo esc_attr($ticket_manager->get_status_badge_class($ticket_status)); ?>">
                                    <?php echo esc_html($ticket_manager->get_status_text($ticket_status)); ?>
                                </span>
                            </div>

                            <div class="qls-ticket-thread">
                                <?php if (empty($current_messages)): ?>
                                    <div class="qls-empty-state">
                                        <span class="dashicons dashicons-format-chat"></span>
                                        <p><?php _e('暂无沟通记录', 'qilingshop'); ?></p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($current_messages as $message): ?>
                                        <?php
                                        $sender_type = sanitize_key((string) ($message->sender_type ?? 'system'));
                                        $is_user_message = $sender_type === 'user';
                                        $author_name = $is_user_message
                                            ? ($current_user->display_name ?: __('我', 'qilingshop'))
                                            : __('客服', 'qilingshop');
                                        $attachments = $ticket_manager->get_message_attachments($message);
                                        ?>
                                        <article class="qls-ticket-message <?php echo $is_user_message ? 'is-user' : 'is-admin'; ?>">
                                            <div class="qls-ticket-message-meta">
                                                <strong><?php echo esc_html($author_name); ?></strong>
                                                <span><?php echo esc_html(date_i18n('Y-m-d H:i', strtotime($message->created_at))); ?></span>
                                            </div>
                                            <div class="qls-ticket-message-body"><?php echo nl2br(esc_html($message->message)); ?></div>
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
                                        </article>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <?php if ($ticket_status === QLS_Shop_Ticket::STATUS_CLOSED): ?>
                                <div class="qls-notice warning"><?php _e('该工单已关闭。', 'qilingshop'); ?></div>
                            <?php else: ?>
                                <form method="post" enctype="multipart/form-data" class="qls-ticket-reply-form">
                                    <?php wp_nonce_field('qls_ticket_frontend_action'); ?>
                                    <input type="hidden" name="qls_ticket_action" value="reply">
                                    <input type="hidden" name="ticket_id" value="<?php echo (int) $current_ticket->id; ?>">
                                    <textarea name="message" rows="4" placeholder="<?php esc_attr_e('输入回复内容', 'qilingshop'); ?>"></textarea>
                                    <?php if ($ticket_attachments_enabled): ?>
                                        <div class="qls-ticket-upload-field">
                                            <span><?php _e('附件', 'qilingshop'); ?></span>
                                            <label class="qls-ticket-upload-box">
                                                <input type="file" name="ticket_attachments[]" <?php echo $ticket_attachment_max_count > 1 ? 'multiple' : ''; ?> accept="<?php echo esc_attr($ticket_attachment_accept); ?>" data-qls-ticket-file-input data-max-files="<?php echo esc_attr($ticket_attachment_max_count); ?>" data-max-size="<?php echo esc_attr($ticket_attachment_max_size); ?>" data-too-many="<?php echo esc_attr(sprintf(__('最多只能上传 %d 个附件', 'qilingshop'), $ticket_attachment_max_count)); ?>" data-too-large="<?php echo esc_attr(sprintf(__('单个附件不能超过 %s', 'qilingshop'), size_format($ticket_attachment_max_size))); ?>">
                                                <span class="qls-ticket-upload-icon">+</span>
                                                <span class="qls-ticket-upload-main"><?php _e('选择附件', 'qilingshop'); ?></span>
                                                <em><?php echo esc_html($ticket_attachment_help); ?></em>
                                            </label>
                                            <div class="qls-ticket-upload-files" data-qls-ticket-file-list><?php _e('未选择文件', 'qilingshop'); ?></div>
                                        </div>
                                    <?php endif; ?>
                                    <button type="submit" class="qls-btn qls-btn-primary"><?php _e('提交回复', 'qilingshop'); ?></button>
                                </form>
                            <?php endif; ?>
                        </section>
                    <?php else: ?>
                        <section class="qls-ticket-create">
                            <h4><?php _e('提交工单', 'qilingshop'); ?></h4>
                            <form method="post" enctype="multipart/form-data" class="qls-ticket-create-form">
                                <?php wp_nonce_field('qls_ticket_frontend_action'); ?>
                                <input type="hidden" name="qls_ticket_action" value="create">

                                <div class="qls-ticket-form-grid">
                                    <div class="qls-ticket-type-field">
                                        <span><?php _e('问题类型', 'qilingshop'); ?></span>
                                        <div class="qls-ticket-type-options">
                                            <?php $ticket_type_index = 0; ?>
                                            <?php foreach ($type_labels as $type_value => $type_label): ?>
                                                <label class="qls-ticket-type-option">
                                                    <input type="radio" name="type" value="<?php echo esc_attr($type_value); ?>" <?php checked($ticket_type_index, 0); ?> required>
                                                    <span><?php echo esc_html($type_label); ?></span>
                                                </label>
                                                <?php $ticket_type_index++; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <div class="qls-ticket-order-field">
                                        <span><?php _e('关联订单', 'qilingshop'); ?></span>
                                        <div class="qls-ticket-order-picker" data-qls-ticket-order-picker>
                                            <input type="search" class="qls-ticket-order-search" placeholder="<?php esc_attr_e('搜索订单号或状态', 'qilingshop'); ?>" autocomplete="off">
                                            <div class="qls-ticket-order-list">
                                                <label class="qls-ticket-order-option" data-order-text="<?php esc_attr_e('不关联订单', 'qilingshop'); ?>">
                                                    <input type="radio" name="order_id" value="0" <?php checked($selected_order_id, 0); ?>>
                                                    <span class="qls-ticket-order-content">
                                                        <strong><?php _e('不关联订单', 'qilingshop'); ?></strong>
                                                        <em><?php _e('普通咨询或无法确认订单时选择', 'qilingshop'); ?></em>
                                                    </span>
                                                </label>

                                                <?php $selected_order_rendered = $selected_order_id <= 0; ?>
                                                <?php foreach ($order_options as $order): ?>
                                                    <?php
                                                    $option_order_id = isset($order->id) ? (int) $order->id : 0;
                                                    if ($option_order_id <= 0) {
                                                        continue;
                                                    }
                                                    $order_no = !empty($order->order_no) ? (string) $order->order_no : (string) $option_order_id;
                                                    $order_status_text = function_exists('qls_shop_order') ? qls_shop_order()->get_status_text((int) $order->status) : '';
                                                    $order_search_text = trim('#' . $order_no . ' ' . $order_status_text);
                                                    if ($selected_order_id === $option_order_id) {
                                                        $selected_order_rendered = true;
                                                    }
                                                    ?>
                                                    <label class="qls-ticket-order-option" data-order-text="<?php echo esc_attr($order_search_text); ?>">
                                                        <input type="radio" name="order_id" value="<?php echo esc_attr($option_order_id); ?>" <?php checked($selected_order_id, $option_order_id); ?>>
                                                        <span class="qls-ticket-order-content">
                                                            <strong>#<?php echo esc_html($order_no); ?></strong>
                                                            <?php if ($order_status_text !== ''): ?>
                                                                <em><?php echo esc_html($order_status_text); ?></em>
                                                            <?php endif; ?>
                                                        </span>
                                                    </label>
                                                <?php endforeach; ?>

                                                <?php if (!$selected_order_rendered): ?>
                                                    <label class="qls-ticket-order-option" data-order-text="<?php echo esc_attr('#' . $selected_order_id); ?>">
                                                        <input type="radio" name="order_id" value="<?php echo esc_attr($selected_order_id); ?>" checked>
                                                        <span class="qls-ticket-order-content">
                                                            <strong>#<?php echo esc_html($selected_order_id); ?></strong>
                                                            <em><?php _e('当前关联订单', 'qilingshop'); ?></em>
                                                        </span>
                                                    </label>
                                                <?php endif; ?>
                                            </div>
                                            <div class="qls-ticket-order-empty" hidden><?php _e('没有匹配的订单', 'qilingshop'); ?></div>
                                        </div>
                                    </div>
                                </div>

                                <label>
                                    <span><?php _e('标题', 'qilingshop'); ?></span>
                                    <input type="text" name="title" maxlength="200" required placeholder="<?php esc_attr_e('一句话描述问题', 'qilingshop'); ?>">
                                </label>

                                <label>
                                    <span><?php _e('问题说明', 'qilingshop'); ?></span>
                                    <textarea name="content" rows="5" required placeholder="<?php esc_attr_e('描述订单、资源、卡密、物流或发票相关问题', 'qilingshop'); ?>"></textarea>
                                </label>

                                <?php if ($ticket_attachments_enabled): ?>
                                    <div class="qls-ticket-upload-field">
                                        <span><?php _e('附件', 'qilingshop'); ?></span>
                                        <label class="qls-ticket-upload-box">
                                            <input type="file" name="ticket_attachments[]" <?php echo $ticket_attachment_max_count > 1 ? 'multiple' : ''; ?> accept="<?php echo esc_attr($ticket_attachment_accept); ?>" data-qls-ticket-file-input data-max-files="<?php echo esc_attr($ticket_attachment_max_count); ?>" data-max-size="<?php echo esc_attr($ticket_attachment_max_size); ?>" data-too-many="<?php echo esc_attr(sprintf(__('最多只能上传 %d 个附件', 'qilingshop'), $ticket_attachment_max_count)); ?>" data-too-large="<?php echo esc_attr(sprintf(__('单个附件不能超过 %s', 'qilingshop'), size_format($ticket_attachment_max_size))); ?>">
                                            <span class="qls-ticket-upload-icon">+</span>
                                            <span class="qls-ticket-upload-main"><?php _e('选择图片或附件', 'qilingshop'); ?></span>
                                            <em><?php echo esc_html($ticket_attachment_help); ?></em>
                                        </label>
                                        <div class="qls-ticket-upload-files" data-qls-ticket-file-list><?php _e('未选择文件', 'qilingshop'); ?></div>
                                    </div>
                                <?php endif; ?>

                                <button type="submit" class="qls-btn qls-btn-primary"><?php _e('提交工单', 'qilingshop'); ?></button>
                            </form>
                        </section>

                        <section class="qls-ticket-list-section">
                            <div class="qls-ticket-tabs">
                                <a href="<?php echo esc_url(remove_query_arg(['status', 'paged', 'ticket_id'])); ?>" class="<?php echo $status === '' ? 'active' : ''; ?>">
                                    <?php _e('全部', 'qilingshop'); ?> (<?php echo (int) array_sum($counts); ?>)
                                </a>
                                <?php foreach ($status_labels as $status_value => $status_label): ?>
                                    <a href="<?php echo esc_url(add_query_arg(['status' => (int) $status_value, 'paged' => 1], $ticket_base_url)); ?>" class="<?php echo $status !== '' && (int) $status === (int) $status_value ? 'active' : ''; ?>">
                                        <?php echo esc_html($status_label); ?> (<?php echo (int) ($counts[$status_value] ?? 0); ?>)
                                    </a>
                                <?php endforeach; ?>
                            </div>

                            <?php if (empty($tickets)): ?>
                                <div class="qls-orders-empty">
                                    <span class="dashicons dashicons-format-chat"></span>
                                    <p><?php _e('暂无工单', 'qilingshop'); ?></p>
                                </div>
                            <?php else: ?>
                                <div class="qls-ticket-list">
                                    <?php foreach ($tickets as $ticket): ?>
                                        <?php $ticket_status = (int) $ticket->status; ?>
                                        <a class="qls-ticket-card" href="<?php echo esc_url(add_query_arg('ticket_id', (int) $ticket->id, $ticket_base_url)); ?>">
                                            <div class="qls-ticket-card-main">
                                                <span class="qls-ticket-no"><?php echo esc_html($ticket->ticket_no); ?></span>
                                                <strong><?php echo esc_html($ticket->title); ?></strong>
                                                <span><?php echo esc_html($ticket_manager->get_type_text($ticket->type)); ?> · <?php echo esc_html(date_i18n('Y-m-d H:i', strtotime($ticket->updated_at))); ?></span>
                                            </div>
                                            <span class="qls-ticket-status <?php echo esc_attr($ticket_manager->get_status_badge_class($ticket_status)); ?>">
                                                <?php echo esc_html($ticket_manager->get_status_text($ticket_status)); ?>
                                            </span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>

                                <?php
                                $total_pages = (int) ceil($total / $limit);
                                if ($total_pages > 1):
                                    $pagination_base_url = $status === ''
                                        ? $ticket_base_url
                                        : add_query_arg('status', (int) $status, $ticket_base_url);
                                    $pagination = paginate_links([
                                        'base'      => add_query_arg('paged', '%#%', $pagination_base_url),
                                        'format'    => '',
                                        'current'   => $paged,
                                        'total'     => $total_pages,
                                        'prev_text' => __('上一页', 'qilingshop'),
                                        'next_text' => __('下一页', 'qilingshop'),
                                    ]);
                                ?>
                                    <div class="qls-pagination"><?php echo wp_kses_post($pagination); ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>
                </main>
            </div>
        </div>
    </div>
</div>

<style>
.qls-my-tickets-page .qls-section-header {
    align-items: center;
    gap: 12px;
}
.qls-ticket-create,
.qls-ticket-detail,
.qls-ticket-list-section {
    background: #fff;
    border: 1px solid #edf0f3;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 18px;
}
.qls-ticket-create h4,
.qls-ticket-detail h4 {
    margin: 0 0 16px;
    font-size: 18px;
}
.qls-ticket-create-form,
.qls-ticket-reply-form {
    display: grid;
    gap: 14px;
}
.qls-ticket-create-form label {
    display: grid;
    gap: 7px;
    color: #4b5563;
    font-size: 14px;
    min-width: 0;
}
.qls-ticket-create-form input,
.qls-ticket-create-form select,
.qls-ticket-create-form textarea,
.qls-ticket-reply-form textarea {
    width: 100%;
    border: 1px solid #d9dde5;
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 14px;
    line-height: 1.5;
    background: #fff;
    box-sizing: border-box;
}
.qls-ticket-create-form input:not([type="file"]):not([type="radio"]),
.qls-ticket-create-form select {
    height: 42px !important;
    min-height: 42px !important;
    max-height: 42px !important;
}
.qls-ticket-create-form select {
    display: block;
    padding: 0 36px 0 12px !important;
    line-height: 40px !important;
    appearance: auto;
    -webkit-appearance: menulist;
}
.qls-ticket-create-form textarea,
.qls-ticket-reply-form textarea {
    min-height: 130px;
    resize: vertical;
}
.qls-ticket-form-grid {
    display: grid;
    grid-template-columns: minmax(220px, 0.85fr) minmax(300px, 1fr);
    gap: 14px;
    align-items: start;
}
.qls-ticket-type-field {
    display: grid;
    gap: 7px;
    color: #4b5563;
    font-size: 14px;
    min-width: 0;
    align-self: start;
}
.qls-ticket-type-options {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(96px, 1fr));
    gap: 8px;
}
.qls-ticket-create-form .qls-ticket-type-option {
    position: relative;
    display: block;
    gap: 0;
    min-width: 0;
    margin: 0;
    color: #374151;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
}
.qls-ticket-type-option input[type="radio"] {
    position: absolute;
    width: 1px !important;
    min-width: 1px;
    height: 1px !important;
    min-height: 1px !important;
    margin: 0;
    padding: 0;
    border: 0;
    opacity: 0;
    pointer-events: none;
}
.qls-ticket-type-option span {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 42px;
    padding: 0 12px;
    border: 1px solid #d9dde5;
    border-radius: 8px;
    background: #fafbfc;
    color: inherit;
    text-align: center;
    line-height: 1.2;
    transition: border-color .15s ease, background-color .15s ease, color .15s ease, box-shadow .15s ease;
}
.qls-ticket-type-option:hover span {
    border-color: #c9d2df;
    background: #fff;
}
.qls-ticket-type-option input[type="radio"]:focus-visible + span {
    outline: 2px solid rgba(255, 77, 46, .28);
    outline-offset: 2px;
}
.qls-ticket-type-option input[type="radio"]:checked + span {
    border-color: #ff4d2e;
    background: #fff7f3;
    color: #ff4d2e;
    box-shadow: 0 6px 16px rgba(255, 77, 46, .10);
}
.qls-ticket-order-field {
    display: grid;
    gap: 7px;
    color: #4b5563;
    font-size: 14px;
    min-width: 0;
}
.qls-ticket-order-picker {
    border: 1px solid #d9dde5;
    border-radius: 8px;
    padding: 9px;
    background: #fff;
}
.qls-ticket-order-search {
    margin-bottom: 8px;
}
.qls-ticket-order-list {
    display: grid;
    gap: 6px;
    max-height: 64px;
    overflow-y: auto;
    padding-right: 4px;
}
.qls-ticket-create-form .qls-ticket-order-option {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin: 0;
    min-height: 64px;
    padding: 8px 10px;
    border: 1px solid #edf0f3;
    border-radius: 8px;
    background: #fafbfc;
    cursor: pointer;
}
.qls-ticket-order-option:hover {
    border-color: #c9d2df;
    background: #fff;
}
.qls-ticket-order-option.is-selected {
    border-color: #ff4d2e;
    background: #fff7f3;
}
.qls-ticket-order-option.is-hidden {
    display: none;
}
.qls-ticket-order-option input[type="radio"] {
    width: 16px;
    min-width: 16px;
    height: 16px;
    margin: 3px 0 0;
    padding: 0;
    border: 1px solid #d9dde5;
    accent-color: #ff4d2e;
}
.qls-ticket-order-content {
    display: grid;
    gap: 3px;
    min-width: 0;
}
.qls-ticket-order-content strong {
    color: #111827;
    font-size: 14px;
    overflow-wrap: anywhere;
}
.qls-ticket-order-content em {
    color: #6b7280;
    font-size: 12px;
    font-style: normal;
    line-height: 1.4;
}
.qls-ticket-order-empty {
    padding: 12px 4px 2px;
    color: #6b7280;
    font-size: 13px;
}
.qls-ticket-upload-field {
    display: grid;
    gap: 7px;
    color: #4b5563;
    font-size: 14px;
}
.qls-ticket-upload-box {
    position: relative;
    display: grid;
    grid-template-columns: 34px minmax(0, 1fr);
    gap: 4px 12px;
    align-items: center;
    min-height: 78px;
    padding: 14px 16px;
    border: 1px dashed #cfd6e2;
    border-radius: 8px;
    background: #fafbfc;
    cursor: pointer;
    transition: border-color .15s ease, background-color .15s ease, box-shadow .15s ease;
}
.qls-ticket-upload-box:hover {
    border-color: #ff7a5f;
    background: #fff7f3;
    box-shadow: 0 8px 18px rgba(255, 77, 46, .08);
}
.qls-ticket-upload-box input[type="file"] {
    position: absolute;
    inset: 0;
    width: 100% !important;
    height: 100% !important;
    min-height: 0 !important;
    margin: 0;
    padding: 0;
    border: 0;
    opacity: 0;
    cursor: pointer;
}
.qls-ticket-upload-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    grid-row: 1 / span 2;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: #fff0ea;
    color: #ff4d2e;
    font-size: 22px;
    font-weight: 700;
    line-height: 1;
}
.qls-ticket-upload-main {
    color: #111827;
    font-size: 14px;
    font-weight: 700;
}
.qls-ticket-upload-box em {
    color: #6b7280;
    font-size: 12px;
    font-style: normal;
    line-height: 1.5;
}
.qls-ticket-upload-files {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    min-height: 20px;
    color: #6b7280;
    font-size: 12px;
}
.qls-ticket-upload-files span {
    display: inline-flex;
    align-items: center;
    max-width: 220px;
    min-height: 24px;
    padding: 0 8px;
    border-radius: 999px;
    background: #f3f4f6;
    color: #374151;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.qls-ticket-detail-head,
.qls-ticket-card {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 14px;
}
.qls-ticket-detail-head {
    padding-bottom: 16px;
    border-bottom: 1px solid #edf0f3;
}
.qls-ticket-detail-head h4 {
    margin: 6px 0;
}
.qls-ticket-detail-head p {
    margin: 0;
    color: #6b7280;
    font-size: 13px;
}
.qls-ticket-no {
    color: #6b7280;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0;
}
.qls-ticket-status {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 24px;
    padding: 0 9px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
    background: #f3f4f6;
    color: #4b5563;
}
.qls-ticket-status.is-open { background: #fff4e5; color: #9a5b00; }
.qls-ticket-status.is-processing { background: #eaf2ff; color: #1d4ed8; }
.qls-ticket-status.is-waiting { background: #eef6ff; color: #0f5fa8; }
.qls-ticket-status.is-resolved { background: #ecfdf3; color: #18713b; }
.qls-ticket-status.is-closed { background: #f3f4f6; color: #6b7280; }
.qls-ticket-thread {
    display: grid;
    gap: 12px;
    margin: 16px 0;
}
.qls-ticket-message {
    max-width: 82%;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 12px;
    background: #f9fafb;
}
.qls-ticket-message.is-user {
    justify-self: end;
    background: #eff6ff;
    border-color: #bfdbfe;
}
.qls-ticket-message.is-admin {
    justify-self: start;
    background: #fff;
}
.qls-ticket-message-meta {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-bottom: 7px;
    color: #6b7280;
    font-size: 12px;
}
.qls-ticket-message-body {
    color: #111827;
    line-height: 1.7;
}
.qls-ticket-attachments {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
}
.qls-ticket-attachment {
    display: inline-grid;
    grid-template-columns: 50px minmax(0, 120px);
    gap: 8px;
    align-items: center;
    padding: 6px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: #fff;
    color: inherit;
    text-decoration: none;
}
.qls-ticket-attachment img,
.qls-ticket-attachment span {
    width: 50px;
    height: 40px;
    border-radius: 6px;
    object-fit: cover;
    background: #f3f4f6;
}
.qls-ticket-attachment span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #6b7280;
    font-size: 12px;
    font-weight: 700;
}
.qls-ticket-attachment em {
    overflow: hidden;
    font-size: 12px;
    font-style: normal;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.qls-ticket-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 16px;
}
.qls-ticket-tabs a {
    display: inline-flex;
    align-items: center;
    min-height: 34px;
    padding: 0 12px;
    border: 1px solid #d9dde5;
    border-radius: 999px;
    color: #4b5563;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
}
.qls-ticket-tabs a.active {
    background: #111827;
    border-color: #111827;
    color: #fff;
}
.qls-ticket-list {
    display: grid;
    gap: 10px;
}
.qls-ticket-card {
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 14px;
    color: inherit;
    text-decoration: none;
    background: #fff;
}
.qls-ticket-card:hover {
    border-color: #c9d2df;
    background: #fafbfc;
}
.qls-ticket-card-main {
    display: grid;
    gap: 5px;
    min-width: 0;
}
.qls-ticket-card-main strong {
    color: #111827;
    overflow-wrap: anywhere;
}
.qls-ticket-card-main span:last-child {
    color: #6b7280;
    font-size: 13px;
}
.qls-ticket-reply-form .qls-btn,
.qls-ticket-create-form .qls-btn {
    justify-self: start;
}
@media (max-width: 768px) {
    .qls-ticket-form-grid,
    .qls-ticket-detail-head,
    .qls-ticket-card {
        grid-template-columns: 1fr;
        display: grid;
    }
    .qls-ticket-message {
        max-width: 100%;
    }
}
</style>

<script>
(function () {
    var pickers = document.querySelectorAll('[data-qls-ticket-order-picker]');

    function normalize(value) {
        return String(value || '').toLowerCase().replace(/\s+/g, '');
    }

    Array.prototype.forEach.call(pickers, function (picker) {
        var search = picker.querySelector('.qls-ticket-order-search');
        var options = picker.querySelectorAll('.qls-ticket-order-option');
        var empty = picker.querySelector('.qls-ticket-order-empty');

        function syncSelected() {
            Array.prototype.forEach.call(options, function (option) {
                var radio = option.querySelector('input[type="radio"]');
                if (radio && radio.checked) {
                    option.classList.add('is-selected');
                } else {
                    option.classList.remove('is-selected');
                }
            });
        }

        function filterOptions() {
            var keyword = normalize(search ? search.value : '');
            var visible = 0;

            Array.prototype.forEach.call(options, function (option) {
                var text = normalize(option.getAttribute('data-order-text') || option.textContent);
                var matched = keyword === '' || text.indexOf(keyword) !== -1;
                if (matched) {
                    option.classList.remove('is-hidden');
                    visible++;
                } else {
                    option.classList.add('is-hidden');
                }
            });

            if (empty) {
                empty.hidden = visible > 0;
            }
        }

        Array.prototype.forEach.call(options, function (option) {
            option.addEventListener('change', syncSelected);
        });

        if (search) {
            search.addEventListener('input', filterOptions);
        }

        syncSelected();
        filterOptions();
    });

    Array.prototype.forEach.call(document.querySelectorAll('[data-qls-ticket-file-input]'), function (input) {
        var field = input.closest('.qls-ticket-upload-field');
        var list = field ? field.querySelector('[data-qls-ticket-file-list]') : null;
        var emptyText = list ? list.textContent : '';
        var maxFiles = parseInt(input.getAttribute('data-max-files') || '0', 10);
        var maxSize = parseInt(input.getAttribute('data-max-size') || '0', 10);
        var tooManyText = input.getAttribute('data-too-many') || '';
        var tooLargeText = input.getAttribute('data-too-large') || '';

        function renderFiles(files) {
            if (!list) {
                return;
            }

            list.textContent = '';
            if (!files.length) {
                list.textContent = emptyText;
                return;
            }

            files.forEach(function (file) {
                var item = document.createElement('span');
                item.textContent = file.name;
                item.title = file.name;
                list.appendChild(item);
            });
        }

        input.addEventListener('change', function () {
            var files = Array.prototype.slice.call(input.files || []);
            if (maxFiles > 0 && files.length > maxFiles) {
                alert(tooManyText);
                input.value = '';
                renderFiles([]);
                return;
            }

            for (var i = 0; i < files.length; i++) {
                if (maxSize > 0 && files[i].size > maxSize) {
                    alert(tooLargeText);
                    input.value = '';
                    renderFiles([]);
                    return;
                }
            }

            renderFiles(files);
        });
    });
})();
</script>
