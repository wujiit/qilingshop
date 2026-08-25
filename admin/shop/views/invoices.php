<?php
/**
 * 发票管理视图
 */
if (!defined('ABSPATH')) exit;

$current_status = isset($_GET['status']) ? (string) sanitize_text_field(wp_unslash($_GET['status'])) : '';
$current_keyword = isset($keyword) ? (string) $keyword : '';
$invoice_manager = function_exists('qls_invoice') ? qls_invoice() : null;
$status_labels = [
    QLS_Invoice::STATUS_PENDING   => __('待开票', 'qilingshop'),
    QLS_Invoice::STATUS_ISSUED    => __('已开票', 'qilingshop'),
    QLS_Invoice::STATUS_REJECTED  => __('已驳回', 'qilingshop'),
    QLS_Invoice::STATUS_CANCELLED => __('已撤销', 'qilingshop'),
];
$invoice_user_map = isset($invoice_user_map) && is_array($invoice_user_map) ? $invoice_user_map : [];
$all_count = isset($all_count) ? (int) $all_count : (int) $total;
$has_filters = $current_status !== '' || $current_keyword !== '';
?>
<div class="wrap qilingshop-admin-page qls-shop-wrap">
    <div class="qls-page-header">
        <div>
            <h1 class="qls-page-title"><?php _e('发票管理', 'qilingshop'); ?></h1>
            <p class="qls-page-intro"><?php _e('处理用户订单发票申请，支持电子发票链接、发票代码和发票号码登记。', 'qilingshop'); ?></p>
        </div>
    </div>

    <?php settings_errors('qls_shop_invoices'); ?>

    <?php if (!get_option('qls_shop_invoice_enabled', true)): ?>
    <div class="notice notice-warning inline">
        <p><?php _e('当前商城发票功能已关闭，前台用户暂不能提交新的发票申请。', 'qilingshop'); ?></p>
    </div>
    <?php endif; ?>

    <ul class="qls-chip-nav" aria-label="<?php esc_attr_e('发票状态筛选', 'qilingshop'); ?>">
        <li>
            <a href="<?php echo esc_url(admin_url('admin.php?page=qls-shop-invoices')); ?>" class="<?php echo $current_status === '' ? 'current' : ''; ?>">
                <?php _e('全部', 'qilingshop'); ?>
                <span class="count">(<?php echo (int) $all_count; ?>)</span>
            </a>
        </li>
        <?php foreach ($status_labels as $status => $label): ?>
        <li>
            <a href="<?php echo esc_url(admin_url('admin.php?page=qls-shop-invoices&status=' . $status)); ?>" class="<?php echo $current_status === (string) $status ? 'current' : ''; ?>">
                <?php echo esc_html($label); ?>
                <span class="count">(<?php echo (int) ($counts[$status] ?? 0); ?>)</span>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>

    <div class="qls-toolbar qls-toolbar-between">
        <form method="get" class="qls-search-form qls-toolbar-search">
            <input type="hidden" name="page" value="qls-shop-invoices">
            <?php if ($current_status !== ''): ?>
            <input type="hidden" name="status" value="<?php echo esc_attr($current_status); ?>">
            <?php endif; ?>
            <input type="search" name="s" value="<?php echo esc_attr($current_keyword); ?>" placeholder="<?php esc_attr_e('搜索订单号、抬头、税号、邮箱、手机号...', 'qilingshop'); ?>">
            <button type="submit" class="button"><?php _e('搜索', 'qilingshop'); ?></button>
            <?php if ($has_filters): ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=qls-shop-invoices')); ?>" class="button button-secondary"><?php _e('清除筛选', 'qilingshop'); ?></a>
            <?php endif; ?>
        </form>
    </div>

    <div class="qls-table-shell">
        <table class="wp-list-table qls-ui-table widefat fixed striped qls-invoices-table">
            <thead>
                <tr>
                    <th><?php _e('订单 / 发票', 'qilingshop'); ?></th>
                    <th><?php _e('用户', 'qilingshop'); ?></th>
                    <th><?php _e('发票信息', 'qilingshop'); ?></th>
                    <th><?php _e('金额', 'qilingshop'); ?></th>
                    <th><?php _e('接收方式', 'qilingshop'); ?></th>
                    <th><?php _e('状态', 'qilingshop'); ?></th>
                    <th><?php _e('时间', 'qilingshop'); ?></th>
                    <th><?php _e('操作', 'qilingshop'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($invoices)): ?>
                <tr>
                    <td colspan="8" class="no-items qls-empty-cell">
                        <div class="qls-empty-state-admin">
                            <strong><?php echo $has_filters ? esc_html__('没有找到匹配的发票申请', 'qilingshop') : esc_html__('暂无发票申请', 'qilingshop'); ?></strong>
                            <p><?php echo $has_filters ? esc_html__('可以换个关键词，或清除状态筛选后再查看。', 'qilingshop') : esc_html__('用户在订单中心提交申请后，会出现在这里等待处理。', 'qilingshop'); ?></p>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($invoices as $invoice): ?>
                <?php
                $status = (int) $invoice->status;
                $buyer_name = isset($invoice_user_map[(int) ($invoice->user_id ?? 0)]) ? (string) $invoice_user_map[(int) ($invoice->user_id ?? 0)] : '';
                $status_class = $invoice_manager ? $invoice_manager->get_status_badge_class($status) : 'is-unknown';
                ?>
                <tr>
                    <td>
                        <strong>#<?php echo esc_html($invoice->order_no); ?></strong>
                        <div class="qls-invoice-subtext"><?php printf(esc_html__('申请编号：%d', 'qilingshop'), (int) $invoice->id); ?></div>
                        <?php if (!empty($invoice->invoice_number)): ?>
                        <div class="qls-invoice-subtext"><?php _e('发票号码：', 'qilingshop'); ?><?php echo esc_html($invoice->invoice_number); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($invoice->invoice_code)): ?>
                        <div class="qls-invoice-subtext"><?php _e('发票代码：', 'qilingshop'); ?><?php echo esc_html($invoice->invoice_code); ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $buyer_name !== '' ? esc_html($buyer_name) : esc_html__('游客', 'qilingshop'); ?></td>
                    <td>
                        <div class="qls-invoice-info">
                            <span class="qls-invoice-type-badge">
                                <?php echo esc_html($invoice_manager ? $invoice_manager->get_invoice_type_text($invoice->invoice_type) : $invoice->invoice_type); ?>
                            </span>
                            <div><strong><?php echo esc_html($invoice_manager ? $invoice_manager->get_title_type_text($invoice->title_type) : $invoice->title_type); ?>：</strong><?php echo esc_html($invoice->title); ?></div>
                            <?php if (!empty($invoice->tax_no)): ?>
                            <div class="qls-invoice-subtext"><?php _e('税号：', 'qilingshop'); ?><?php echo esc_html($invoice->tax_no); ?></div>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>¥<?php echo number_format((float) $invoice->amount, 2); ?></td>
                    <td>
                        <?php if (!empty($invoice->email)): ?>
                        <div><?php _e('邮箱：', 'qilingshop'); ?><?php echo esc_html($invoice->email); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($invoice->phone)): ?>
                        <div class="qls-invoice-subtext"><?php _e('手机：', 'qilingshop'); ?><?php echo esc_html($invoice->phone); ?></div>
                        <?php endif; ?>
                        <?php if (empty($invoice->email) && empty($invoice->phone)): ?>
                        <span class="qls-invoice-empty">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="qls-invoice-status-badge <?php echo esc_attr($status_class); ?>">
                            <?php echo esc_html($invoice_manager ? $invoice_manager->get_status_text($status) : $status); ?>
                        </span>
                        <?php if (!empty($invoice->admin_remark)): ?>
                        <div class="qls-invoice-subtext"><?php _e('备注：', 'qilingshop'); ?><?php echo esc_html($invoice->admin_remark); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($invoice->invoice_url)): ?>
                        <div class="qls-invoice-subtext">
                            <a href="<?php echo esc_url($invoice->invoice_url); ?>" target="_blank" rel="noopener"><?php _e('查看电子发票', 'qilingshop'); ?></a>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div><?php _e('申请：', 'qilingshop'); ?><?php echo esc_html($invoice->requested_at); ?></div>
                        <?php if (!empty($invoice->issued_at)): ?>
                        <div class="qls-invoice-subtext"><?php _e('开票：', 'qilingshop'); ?><?php echo esc_html($invoice->issued_at); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($invoice->rejected_at)): ?>
                        <div class="qls-invoice-subtext"><?php _e('驳回：', 'qilingshop'); ?><?php echo esc_html($invoice->rejected_at); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($invoice->cancelled_at)): ?>
                        <div class="qls-invoice-subtext"><?php _e('撤销：', 'qilingshop'); ?><?php echo esc_html($invoice->cancelled_at); ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="qls-invoice-action-stack">
                            <?php if ($status === QLS_Invoice::STATUS_PENDING): ?>
                            <form method="post" class="qls-invoice-action-form">
                                <?php wp_nonce_field('qls_invoice_action'); ?>
                                <input type="hidden" name="invoice_action" value="issue">
                                <input type="hidden" name="invoice_id" value="<?php echo esc_attr($invoice->id); ?>">
                                <input type="text" name="invoice_code" value="" placeholder="<?php esc_attr_e('发票代码（可选）', 'qilingshop'); ?>">
                                <input type="text" name="invoice_number" value="" placeholder="<?php esc_attr_e('发票号码（可选）', 'qilingshop'); ?>">
                                <input type="url" name="invoice_url" value="" placeholder="<?php esc_attr_e('电子发票链接（可选）', 'qilingshop'); ?>">
                                <textarea name="admin_remark" rows="2" placeholder="<?php esc_attr_e('开票备注（可选）', 'qilingshop'); ?>"></textarea>
                                <button type="submit" class="button button-primary"><?php _e('标记已开票', 'qilingshop'); ?></button>
                            </form>
                            <form method="post" class="qls-invoice-action-form">
                                <?php wp_nonce_field('qls_invoice_action'); ?>
                                <input type="hidden" name="invoice_action" value="reject">
                                <input type="hidden" name="invoice_id" value="<?php echo esc_attr($invoice->id); ?>">
                                <textarea name="admin_remark" rows="2" placeholder="<?php esc_attr_e('驳回原因（可选）', 'qilingshop'); ?>"></textarea>
                                <button type="submit" class="button"><?php _e('驳回申请', 'qilingshop'); ?></button>
                            </form>
                            <?php else: ?>
                            <span class="qls-invoice-empty">-</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <?php
            $total_pages = ceil($total / $limit);
            if ($total_pages > 1) {
                echo paginate_links([
                    'base'      => add_query_arg('paged', '%#%'),
                    'format'    => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'total'     => $total_pages,
                    'current'   => $paged,
                ]);
            }
            ?>
        </div>
    </div>
</div>
