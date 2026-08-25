<?php
/**
 * 售后退款管理视图
 */
if (!defined('ABSPATH')) exit;

$current_status = isset($_GET['status']) ? (string) $_GET['status'] : '';
$status_labels = [
    QLS_Shop_Refund::STATUS_PENDING   => __('待审核', 'qilingshop'),
    QLS_Shop_Refund::STATUS_APPROVED  => __('已通过', 'qilingshop'),
    QLS_Shop_Refund::STATUS_RETURNED  => __('买家已退货', 'qilingshop'),
    QLS_Shop_Refund::STATUS_RECEIVED  => __('已收货待退款', 'qilingshop'),
    QLS_Shop_Refund::STATUS_REFUNDED  => __('已退款', 'qilingshop'),
    QLS_Shop_Refund::STATUS_REJECTED  => __('已驳回', 'qilingshop'),
    QLS_Shop_Refund::STATUS_CANCELLED => __('已撤销', 'qilingshop'),
];

$refund_manager = qls_shop_refund();
$refund_order_map = isset($refund_order_map) && is_array($refund_order_map) ? $refund_order_map : [];
$refund_user_map = isset($refund_user_map) && is_array($refund_user_map) ? $refund_user_map : [];
$refund_logs_map = isset($refund_logs_map) && is_array($refund_logs_map) ? $refund_logs_map : [];
$refund_log_labels = [
    'apply'            => __('提交申请', 'qilingshop'),
    'approve'          => __('审核通过', 'qilingshop'),
    'reject'           => __('审核驳回', 'qilingshop'),
    'cancel'           => __('撤销申请', 'qilingshop'),
    'return_shipped'   => __('买家已退货', 'qilingshop'),
    'return_received'  => __('商家确认收货', 'qilingshop'),
    'refund_start'     => __('发起退款', 'qilingshop'),
    'refund_fail'      => __('退款失败', 'qilingshop'),
    'refund_reconcile' => __('待人工核对', 'qilingshop'),
    'refund'           => __('退款完成', 'qilingshop'),
];
?>
<div class="wrap qilingshop-admin-page qls-shop-wrap">
    <h1><?php _e('售后退款', 'qilingshop'); ?></h1>

    <?php settings_errors('qls_shop_refunds'); ?>

    <ul class="subsubsub">
        <li>
            <a href="<?php echo esc_url(admin_url('admin.php?page=qls-shop-refunds')); ?>" class="<?php echo $current_status === '' ? 'current' : ''; ?>">
                <?php _e('全部', 'qilingshop'); ?>
                <span class="count">(<?php echo (int) $total; ?>)</span>
            </a> |
        </li>
        <?php $status_index = 0; ?>
        <?php foreach ($status_labels as $status => $label): ?>
        <?php $status_index++; ?>
        <li>
            <a href="<?php echo esc_url(admin_url('admin.php?page=qls-shop-refunds&status=' . $status)); ?>" class="<?php echo $current_status === (string) $status ? 'current' : ''; ?>">
                <?php echo esc_html($label); ?>
                <span class="count">(<?php echo (int) ($counts[$status] ?? 0); ?>)</span>
            </a>
            <?php echo $status_index < count($status_labels) ? '|' : ''; ?>
        </li>
        <?php endforeach; ?>
    </ul>

    <form method="get" class="qls-search-form">
        <input type="hidden" name="page" value="qls-shop-refunds">
        <input type="search" name="s" value="<?php echo esc_attr($keyword); ?>" placeholder="<?php _e('搜索订单号...', 'qilingshop'); ?>">
        <button type="submit" class="button"><?php _e('搜索', 'qilingshop'); ?></button>
    </form>

    <table class="wp-list-table qls-ui-table widefat fixed striped qls-orders-table qls-refunds-table">
        <thead>
            <tr>
                <th><?php _e('订单号', 'qilingshop'); ?></th>
                <th><?php _e('用户', 'qilingshop'); ?></th>
                <th><?php _e('类型', 'qilingshop'); ?></th>
                <th><?php _e('金额', 'qilingshop'); ?></th>
                <th><?php _e('申请信息', 'qilingshop'); ?></th>
                <th><?php _e('退货信息', 'qilingshop'); ?></th>
                <th><?php _e('状态', 'qilingshop'); ?></th>
                <th><?php _e('操作', 'qilingshop'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($refunds)): ?>
            <tr>
                <td colspan="8" class="no-items"><?php _e('暂无记录', 'qilingshop'); ?></td>
            </tr>
            <?php else: ?>
            <?php foreach ($refunds as $refund): ?>
            <?php
            $buyer_name = isset($refund_user_map[(int) ($refund->user_id ?? 0)]) ? (string) $refund_user_map[(int) ($refund->user_id ?? 0)] : '';
            $is_return_required = !empty($refund->return_required);
            $evidence_images = $refund_manager->get_evidence_images($refund);
            $status = (int) $refund->status;
            $order = isset($refund_order_map[(int) ($refund->order_id ?? 0)]) ? $refund_order_map[(int) ($refund->order_id ?? 0)] : null;
            $refund_meta = $this->build_refund_admin_meta($refund, $order);
            $refund_logs = isset($refund_logs_map[(int) ($refund->id ?? 0)]) ? (array) $refund_logs_map[(int) ($refund->id ?? 0)] : [];
            ?>
            <tr>
                <td>
                    <strong>#<?php echo esc_html($refund->order_no); ?></strong>
                    <div class="qls-refund-subtext"><?php echo esc_html($refund->created_at); ?></div>
                </td>
                <td><?php echo $buyer_name !== '' ? esc_html($buyer_name) : esc_html__('游客', 'qilingshop'); ?></td>
                <td>
                    <span class="qls-refund-type-badge <?php echo $is_return_required ? 'is-physical' : 'is-virtual'; ?>">
                        <?php echo esc_html($is_return_required ? __('退货退款', 'qilingshop') : __('仅退款', 'qilingshop')); ?>
                    </span>
                    <div class="qls-refund-subtext"><?php _e('支付方式：', 'qilingshop'); ?><?php echo esc_html($refund_meta['payment_method_label']); ?></div>
                    <?php if ($refund_meta['payment_version_label'] !== ''): ?>
                    <div class="qls-refund-subtext"><?php _e('支付版本：', 'qilingshop'); ?><?php echo esc_html($refund_meta['payment_version_label']); ?></div>
                    <?php endif; ?>
                    <div class="qls-refund-subtext"><?php _e('退款方式：', 'qilingshop'); ?><?php echo esc_html($refund_meta['effective_mode_label']); ?></div>
                    <?php if ($refund_meta['effective_mode'] === 'gateway' && $refund_meta['gateway_label'] !== ''): ?>
                    <div class="qls-refund-subtext"><?php _e('退款通道：', 'qilingshop'); ?><?php echo esc_html($refund_meta['gateway_label']); ?></div>
                    <?php endif; ?>
                </td>
                <td>¥<?php echo number_format((float) $refund->amount, 2); ?></td>
                <td>
                    <div class="qls-refund-info">
                        <div><strong><?php _e('原因：', 'qilingshop'); ?></strong><?php echo esc_html($refund->reason ?: __('未填写', 'qilingshop')); ?></div>
                        <?php if (!empty($evidence_images)): ?>
                        <div class="qls-refund-evidence">
                            <strong><?php _e('图片凭证：', 'qilingshop'); ?></strong>
                            <div class="qls-refund-evidence-list">
                                <?php foreach ($evidence_images as $image_url): ?>
                                <a href="<?php echo esc_url($image_url); ?>" target="_blank" rel="noopener">
                                    <img src="<?php echo esc_url($image_url); ?>" alt="<?php esc_attr_e('售后凭证', 'qilingshop'); ?>">
                                </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <span class="qls-refund-empty"><?php _e('无图片凭证', 'qilingshop'); ?></span>
                        <?php endif; ?>
                    </div>
                </td>
                <td>
                    <?php if ($is_return_required): ?>
                    <div class="qls-refund-return-box">
                        <div>
                            <strong><?php _e('退货地址：', 'qilingshop'); ?></strong>
                            <?php echo !empty($refund->return_address) ? nl2br(esc_html($refund->return_address)) : esc_html__('待审核通过后填写', 'qilingshop'); ?>
                        </div>
                        <?php if (!empty($refund->return_shipping_company) || !empty($refund->return_tracking_no)): ?>
                        <div>
                            <strong><?php _e('退货物流：', 'qilingshop'); ?></strong>
                            <?php echo esc_html(trim((string) $refund->return_shipping_company)); ?>
                            <?php if (!empty($refund->return_tracking_no)): ?>
                            <span><?php _e('单号：', 'qilingshop'); ?><?php echo esc_html($refund->return_tracking_no); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($refund->return_shipped_at)): ?>
                        <div class="qls-refund-subtext"><?php _e('买家退货时间：', 'qilingshop'); ?><?php echo esc_html($refund->return_shipped_at); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($refund->return_received_at)): ?>
                        <div class="qls-refund-subtext"><?php _e('确认收货时间：', 'qilingshop'); ?><?php echo esc_html($refund->return_received_at); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <span class="qls-refund-empty"><?php _e('虚拟商品无需退货物流', 'qilingshop'); ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <strong><?php echo esc_html($refund_manager->get_refund_status_text($refund)); ?></strong>
                    <?php if (!empty($refund->reviewed_at)): ?>
                    <div class="qls-refund-subtext"><?php _e('审核时间：', 'qilingshop'); ?><?php echo esc_html($refund->reviewed_at); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($refund->refunded_at)): ?>
                    <div class="qls-refund-subtext"><?php _e('退款时间：', 'qilingshop'); ?><?php echo esc_html($refund->refunded_at); ?></div>
                    <?php endif; ?>
                    <?php if ($refund_meta['gateway_status_label'] !== ''): ?>
                    <div class="qls-refund-subtext"><?php _e('原路状态：', 'qilingshop'); ?><?php echo esc_html($refund_meta['gateway_status_label']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($refund->gateway_refund_no)): ?>
                    <div class="qls-refund-subtext"><?php _e('网关退款单号：', 'qilingshop'); ?><?php echo esc_html($refund->gateway_refund_no); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($refund->gateway_refunded_at)): ?>
                    <div class="qls-refund-subtext"><?php _e('网关退款时间：', 'qilingshop'); ?><?php echo esc_html($refund->gateway_refunded_at); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($refund->gateway_error)): ?>
                    <div class="qls-refund-subtext"><strong><?php _e('失败原因：', 'qilingshop'); ?></strong><?php echo esc_html($refund->gateway_error); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($refund->admin_remark)): ?>
                    <div class="qls-refund-subtext"><?php _e('备注：', 'qilingshop'); ?><?php echo esc_html($refund->admin_remark); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($refund_logs)): ?>
                    <div class="qls-refund-subtext"><strong><?php _e('最近日志：', 'qilingshop'); ?></strong></div>
                    <?php foreach ($refund_logs as $refund_log): ?>
                    <?php
                    $log_action = sanitize_key((string) ($refund_log->action ?? ''));
                    $log_label = $refund_log_labels[$log_action] ?? $log_action;
                    $log_message = sanitize_textarea_field((string) ($refund_log->message ?? ''));
                    ?>
                    <div class="qls-refund-subtext">
                        [<?php echo esc_html((string) ($refund_log->created_at ?? '')); ?>]
                        <?php echo esc_html($log_label); ?>
                        <?php if ($log_message !== ''): ?>
                        : <?php echo esc_html($log_message); ?>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </td>
                <td>
                        <div class="qls-refund-action-stack">
                            <div class="qls-refund-subtext"><strong><?php _e('当前执行：', 'qilingshop'); ?></strong><?php echo esc_html($refund_meta['effective_mode_label']); ?></div>
                        <?php if ($refund_meta['stored_mode_label'] !== '' && $refund_meta['stored_mode'] !== $refund_meta['effective_mode']): ?>
                        <div class="qls-refund-subtext"><?php _e('上次执行：', 'qilingshop'); ?><?php echo esc_html($refund_meta['stored_mode_label']); ?></div>
                        <?php endif; ?>
                        <?php if ($refund_meta['effective_mode'] === 'gateway'): ?>
                        <div class="qls-refund-subtext"><?php echo esc_html($refund_meta['gateway_message']); ?></div>
                        <?php elseif ($refund_meta['gateway_supported']): ?>
                        <div class="qls-refund-subtext"><?php _e('如切换到“原路退回”，该订单可走第三方支付原路退款。', 'qilingshop'); ?></div>
                        <?php endif; ?>
                        <?php foreach ($refund_meta['warnings'] as $warning): ?>
                        <div class="qls-refund-subtext"><?php echo esc_html($warning); ?></div>
                        <?php endforeach; ?>
                        <?php if ($status === QLS_Shop_Refund::STATUS_PENDING): ?>
                        <form method="post" class="qls-refund-action-form">
                            <?php wp_nonce_field('qls_refund_action'); ?>
                            <input type="hidden" name="refund_action" value="approve">
                            <input type="hidden" name="refund_id" value="<?php echo esc_attr($refund->id); ?>">
                            <?php if ($is_return_required): ?>
                            <textarea name="return_address" rows="3" required placeholder="<?php esc_attr_e('退货地址（必填）', 'qilingshop'); ?>"></textarea>
                            <?php endif; ?>
                            <textarea name="remark" rows="2" placeholder="<?php esc_attr_e('审核备注（可选）', 'qilingshop'); ?>"></textarea>
                            <button type="submit" class="button button-primary"><?php _e('审核通过', 'qilingshop'); ?></button>
                        </form>
                        <form method="post" class="qls-refund-action-form">
                            <?php wp_nonce_field('qls_refund_action'); ?>
                            <input type="hidden" name="refund_action" value="reject">
                            <input type="hidden" name="refund_id" value="<?php echo esc_attr($refund->id); ?>">
                            <textarea name="remark" rows="2" placeholder="<?php esc_attr_e('驳回原因（可选）', 'qilingshop'); ?>"></textarea>
                            <button type="submit" class="button"><?php _e('驳回', 'qilingshop'); ?></button>
                        </form>
                        <?php elseif ($status === QLS_Shop_Refund::STATUS_APPROVED && !$is_return_required && $refund_meta['needs_reconcile']): ?>
                        <span class="qls-refund-empty"><?php _e('该笔退款资金可能已实际退回，请先人工核对第三方平台，勿重复退款。', 'qilingshop'); ?></span>
                        <?php elseif ($status === QLS_Shop_Refund::STATUS_APPROVED && !$is_return_required && $refund_meta['is_processing']): ?>
                        <span class="qls-refund-empty"><?php _e('退款处理中，请稍后刷新。', 'qilingshop'); ?></span>
                        <?php elseif ($status === QLS_Shop_Refund::STATUS_APPROVED && !$is_return_required): ?>
                        <form method="post" class="qls-refund-action-form" onsubmit="return confirm('<?php echo esc_js(__('确定执行最终退款吗？', 'qilingshop')); ?>');">
                            <?php wp_nonce_field('qls_refund_action'); ?>
                            <input type="hidden" name="refund_action" value="refund">
                            <input type="hidden" name="refund_id" value="<?php echo esc_attr($refund->id); ?>">
                            <textarea name="remark" rows="2" placeholder="<?php esc_attr_e('退款备注（可选）', 'qilingshop'); ?>"></textarea>
                            <button type="submit" class="button button-primary"><?php _e('最终退款', 'qilingshop'); ?></button>
                        </form>
                        <?php elseif ($status === QLS_Shop_Refund::STATUS_APPROVED): ?>
                        <span class="qls-refund-empty"><?php _e('等待买家填写退货物流', 'qilingshop'); ?></span>
                        <?php elseif ($status === QLS_Shop_Refund::STATUS_RETURNED): ?>
                        <form method="post" class="qls-refund-action-form" onsubmit="return confirm('<?php echo esc_js(__('确认已收到买家退货吗？', 'qilingshop')); ?>');">
                            <?php wp_nonce_field('qls_refund_action'); ?>
                            <input type="hidden" name="refund_action" value="receive">
                            <input type="hidden" name="refund_id" value="<?php echo esc_attr($refund->id); ?>">
                            <textarea name="remark" rows="2" placeholder="<?php esc_attr_e('收货备注（可选）', 'qilingshop'); ?>"></textarea>
                            <button type="submit" class="button button-primary"><?php _e('确认收货', 'qilingshop'); ?></button>
                        </form>
                        <?php elseif ($status === QLS_Shop_Refund::STATUS_RECEIVED && $refund_meta['needs_reconcile']): ?>
                        <span class="qls-refund-empty"><?php _e('该笔退款资金可能已实际退回，请先人工核对第三方平台，勿重复退款。', 'qilingshop'); ?></span>
                        <?php elseif ($status === QLS_Shop_Refund::STATUS_RECEIVED && $refund_meta['is_processing']): ?>
                        <span class="qls-refund-empty"><?php _e('退款处理中，请稍后刷新。', 'qilingshop'); ?></span>
                        <?php elseif ($status === QLS_Shop_Refund::STATUS_RECEIVED): ?>
                        <form method="post" class="qls-refund-action-form" onsubmit="return confirm('<?php echo esc_js(__('确定执行最终退款吗？', 'qilingshop')); ?>');">
                            <?php wp_nonce_field('qls_refund_action'); ?>
                            <input type="hidden" name="refund_action" value="refund">
                            <input type="hidden" name="refund_id" value="<?php echo esc_attr($refund->id); ?>">
                            <textarea name="remark" rows="2" placeholder="<?php esc_attr_e('退款备注（可选）', 'qilingshop'); ?>"></textarea>
                            <button type="submit" class="button button-primary"><?php _e('最终退款', 'qilingshop'); ?></button>
                        </form>
                        <?php else: ?>
                        <span class="qls-refund-empty">-</span>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

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
