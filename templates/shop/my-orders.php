<?php
/**
 * 我的订单模板
 */
if (!defined('ABSPATH')) exit;

$refunds = isset($refunds) && is_array($refunds) ? $refunds : [];
$invoices = isset($invoices) && is_array($invoices) ? $invoices : [];
$invoice_titles = isset($invoice_titles) && is_array($invoice_titles) ? $invoice_titles : [];
$invoice_available = function_exists('qls_invoice') && class_exists('QLS_Invoice');
$invoice_enabled = get_option('qls_shop_invoice_enabled', true) && $invoice_available;
$refund_manager = function_exists('qls_shop_refund') ? qls_shop_refund() : null;
$tickets_page_url = qls_shop_public()->get_page_url('my-tickets');
$default_invoice_title = null;
if (!empty($invoice_titles)) {
    foreach ($invoice_titles as $saved_invoice_title) {
        if (!empty($saved_invoice_title->is_default)) {
            $default_invoice_title = $saved_invoice_title;
            break;
        }
    }
    if (!$default_invoice_title) {
        $default_invoice_title = reset($invoice_titles);
    }
}

qls_shop_public()->get_shop_header(__('我的订单', 'qilingshop'));
?>

<div class="qls-account-page">
    <?php include QILINGSHOP_PATH . 'templates/shop/partials/account-header.php'; ?>
    
    <div class="qls-account-body">
        <div class="qls-container">
            <div class="qls-account-layout">
                <?php include QILINGSHOP_PATH . 'templates/shop/partials/account-sidebar.php'; ?>
                
                <main class="qls-account-main">
                    <div class="qls-section-header">
                        <h3><?php _e('我的订单', 'qilingshop'); ?></h3>
                    </div>

                    <div class="qls-orders-wrapper">
                        <!-- 状态筛选 -->
                        <div class="qls-order-tabs">
                            <a href="<?php echo esc_url(remove_query_arg(['status', 'paged', 'qls_orders_page'])); ?>" class="<?php echo !isset($_GET['status']) ? 'active' : ''; ?>"><?php _e('全部', 'qilingshop'); ?></a>
                            <a href="<?php echo esc_url(add_query_arg('status', 0, remove_query_arg(['paged', 'qls_orders_page']))); ?>" class="<?php echo isset($_GET['status']) && $_GET['status'] === '0' ? 'active' : ''; ?>"><?php _e('待付款', 'qilingshop'); ?></a>
                            <a href="<?php echo esc_url(add_query_arg('status', 1, remove_query_arg(['paged', 'qls_orders_page']))); ?>" class="<?php echo isset($_GET['status']) && $_GET['status'] === '1' ? 'active' : ''; ?>"><?php _e('待发货', 'qilingshop'); ?></a>
                            <a href="<?php echo esc_url(add_query_arg('status', 2, remove_query_arg(['paged', 'qls_orders_page']))); ?>" class="<?php echo isset($_GET['status']) && $_GET['status'] === '2' ? 'active' : ''; ?>"><?php _e('待收货', 'qilingshop'); ?></a>
                            <a href="<?php echo esc_url(add_query_arg('status', 3, remove_query_arg(['paged', 'qls_orders_page']))); ?>" class="<?php echo isset($_GET['status']) && $_GET['status'] === '3' ? 'active' : ''; ?>"><?php _e('已完成', 'qilingshop'); ?></a>
                            <a href="<?php echo esc_url(add_query_arg('status', 5, remove_query_arg(['paged', 'qls_orders_page']))); ?>" class="<?php echo isset($_GET['status']) && $_GET['status'] === '5' ? 'active' : ''; ?>"><?php _e('退款中', 'qilingshop'); ?></a>
                            <a href="<?php echo esc_url(add_query_arg('status', 6, remove_query_arg(['paged', 'qls_orders_page']))); ?>" class="<?php echo isset($_GET['status']) && $_GET['status'] === '6' ? 'active' : ''; ?>"><?php _e('已退款', 'qilingshop'); ?></a>
                        </div>
                        
                        <?php if (empty($orders)): ?>
                        <div class="qls-orders-empty">
                            <span class="dashicons dashicons-portfolio"></span>
                            <p><?php _e('暂无订单', 'qilingshop'); ?></p>
                            <a href="<?php echo esc_url(qls_shop_public()->get_shop_url()); ?>" class="qls-btn qls-btn-primary">
                                <?php _e('去逛逛', 'qilingshop'); ?>
                            </a>
                        </div>
                        <?php else: ?>
                        
                        <div class="qls-order-list">
                            <?php foreach ($orders as $order): ?>
                            <?php $refund = $refunds[$order->id] ?? null; ?>
                            <?php
                            $invoice = $invoice_available ? ($invoices[$order->id] ?? null) : null;
                            $invoice_status = $invoice ? (int) $invoice->status : -1;
                            $invoice_status_text = $invoice ? qls_invoice()->get_status_text($invoice) : '';
                            $invoice_status_class = $invoice ? qls_invoice()->get_status_badge_class($invoice) : '';
                            $invoice_type_text = $invoice ? qls_invoice()->get_invoice_type_text($invoice->invoice_type) : '';
                            $invoice_title_type_text = $invoice ? qls_invoice()->get_title_type_text($invoice->title_type) : '';
                            $refund_status = $refund ? (int) $refund->status : -1;
                            $refund_return_required = $refund ? !empty($refund->return_required) : false;
                            $refund_evidence_images = ($refund && $refund_manager) ? $refund_manager->get_evidence_images($refund) : [];
                            $refund_meta = ($refund && $refund_manager) ? $refund_manager->get_display_meta($refund) : [];
                            $active_refund_statuses = [
                                QLS_Shop_Refund::STATUS_PENDING,
                                QLS_Shop_Refund::STATUS_APPROVED,
                                QLS_Shop_Refund::STATUS_RETURNED,
                                QLS_Shop_Refund::STATUS_RECEIVED,
                            ];
                            $has_physical_item = false;
                            foreach ((array) ($order->items ?? []) as $item_check) {
                                $pid = (int) ($item_check->product_id ?? 0);
                                if ($pid > 0 && !qls_product()->is_virtual($pid)) {
                                    $has_physical_item = true;
                                    break;
                                }
                            }
                            $shipments = is_array($order->shipments ?? null) ? $order->shipments : [];
                            $shipment_summary = is_array($order->shipment_summary ?? null) ? $order->shipment_summary : [];
                            $shipment_count = (int) ($shipment_summary['shipment_count'] ?? count($shipments));
                            $shipment_status = (int) ($order->shipment_status ?? 0);
                            ?>
                            <div class="qls-order-card">
                                <div class="order-header">
                                    <span class="order-no"><?php _e('订单号:', 'qilingshop'); ?> <?php echo esc_html($order->order_no); ?></span>
                                    <span class="order-date"><?php echo date('Y-m-d H:i', strtotime($order->created_at)); ?></span>
                                    <span class="order-status status-<?php echo esc_attr($order->status); ?>">
                                        <?php 
                                        $status_text = qls_shop_order()->get_status_text($order->status);
                                        if ((int) $order->status === 1 && $shipment_status === 1) {
                                            $status_text = __('部分发货', 'qilingshop');
                                        }
                                        // 拼团订单状态补充
                                        if ($order->status == 1 && !empty($order->group_id) && class_exists('QLS_Group')) {
                                            $group_info = qls_group()->get_group($order->group_id);
                                            if ($group_info) {
                                                if ($group_info->status == 0) {
                                                    $status_text = __('还在拼团中', 'qilingshop');
                                                } elseif ($group_info->status == 1) {
                                                    $status_text = __('等待商家发货', 'qilingshop');
                                                }
                                            }
                                        }
                                        echo esc_html($status_text); 
                                        ?>
                                    </span>
                                </div>
                                
                                <div class="order-items">
                                    <?php foreach ($order->items as $item): ?>
                                    <div class="order-item">
                                        <?php 
                                        $img = is_array($item->image) ? ($item->image['url'] ?? '') : $item->image;
                                        // 兼容旧数据
                                        if (empty($img) && !empty($item->product_id)) {
                                            $prod = qls_product()->get($item->product_id);
                                            if ($prod) $img = is_array($prod->main_image) ? ($prod->main_image['url'] ?? '') : $prod->main_image;
                                        }
                                        ?>
                                        <img src="<?php echo esc_url($img); ?>" alt="" class="item-thumb">
                                        <div class="item-info">
                                            <span class="item-title"><?php echo esc_html($item->product_title); ?></span>
                                            <?php if (!empty($item->sku_attrs)): ?>
                                            <span class="item-sku"><?php echo esc_html(implode(' / ', (array)$item->sku_attrs)); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="item-price">¥<?php echo number_format($item->price, 2); ?></span>
                                        <span class="item-qty">×<?php echo esc_html($item->quantity); ?></span>
                                    </div>
                                    
                                    <?php 
                                    // 显示虚拟商品内容（仅已完成订单）
                                    if (in_array((int) $order->status, [
                                        QLS_Shop_Order::STATUS_PAID,
                                        QLS_Shop_Order::STATUS_SHIPPED,
                                        QLS_Shop_Order::STATUS_COMPLETED,
                                    ], true) && !empty($item->virtual_content)):
                                        $vc = $item->virtual_content;
                                    ?>
                                    <div class="qls-virtual-content-box">
                                        <?php if ($vc['type'] === 'download'): ?>
                                        <div class="vc-download">
                                            <span class="vc-label"><?php _e('下载链接:', 'qilingshop'); ?></span>
                                            <a href="<?php echo esc_url($vc['download_url']); ?>" target="_blank" class="vc-link">
                                                <?php _e('点击下载', 'qilingshop'); ?>
                                            </a>
                                            <?php if (!empty($vc['download_code'])): ?>
                                            <span class="vc-code"><?php _e('提取码:', 'qilingshop'); ?> <strong><?php echo esc_html($vc['download_code']); ?></strong></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php elseif ($vc['type'] === 'card'): ?>
                                        <div class="vc-cards">
                                            <span class="vc-label"><?php _e('卡密信息:', 'qilingshop'); ?></span>
                                            <?php if (!empty($vc['cards'])): ?>
                                            <div class="vc-cards-list">
                                                <?php foreach ($vc['cards'] as $card): ?>
                                                <div class="vc-card-item">
                                                    <span class="card-no"><?php echo esc_html($card['card_no']); ?></span>
                                                    <?php if (!empty($card['card_secret'])): ?>
                                                    <span class="card-secret"><?php echo esc_html($card['card_secret']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php elseif (!empty($vc['error'])): ?>
                                            <span class="vc-error"><?php echo esc_html($vc['error']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php elseif ($vc['type'] === 'custom'): ?>
                                        <div class="vc-custom">
                                            <span class="vc-label"><?php _e('商品内容:', 'qilingshop'); ?></span>
                                            <div class="vc-custom-content"><?php echo wp_kses_post($vc['content']); ?></div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                                
                                <div class="order-footer">
                                    <div class="order-meta">
                                    <div class="order-total">
                                        <?php _e('实付款:', 'qilingshop'); ?> 
                                        <span class="amount">¥<?php echo number_format($order->final_amount, 2); ?></span>
                                    </div>

                                    <?php if ($has_physical_item && in_array((int) $order->status, [1, 2, 3], true)): ?>
                                    <div class="order-logistics-status">
                                        <?php _e('物流状态:', 'qilingshop'); ?>
                                        <?php if ($shipment_status === 1): ?>
                                            <?php _e('部分发货', 'qilingshop'); ?>
                                        <?php elseif ((int) $order->status === 1): ?>
                                            <?php _e('待发货', 'qilingshop'); ?>
                                        <?php else: ?>
                                            <?php _e('已发货', 'qilingshop'); ?>
                                        <?php endif; ?>

                                        <?php if (!empty($shipments)): ?>
                                            <div class="logistics-extra qls-order-shipments">
                                                <?php foreach ($shipments as $shipment_index => $shipment): ?>
                                                <?php $shipment_no = !empty($shipment->tracking_no) ? (string) $shipment->tracking_no : (string) ($shipment->waybill_no ?? ''); ?>
                                                <span class="logistics-no">
                                                    <?php printf(esc_html__('包裹%d:', 'qilingshop'), $shipment_index + 1); ?>
                                                    <?php echo esc_html(trim((string) ($shipment->shipping_company ?? ''))); ?>
                                                    <?php if ($shipment_no !== ''): ?>
                                                    <?php echo esc_html($shipment_no); ?>
                                                    <?php endif; ?>
                                                </span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php elseif (!empty($order->shipping_company) || !empty($order->tracking_no)): ?>
                                            <span class="logistics-extra">
                                                <?php echo esc_html(trim((string) $order->shipping_company)); ?>
                                                <?php if (!empty($order->tracking_no)): ?>
                                                <span class="logistics-no"><?php _e('单号:', 'qilingshop'); ?> <?php echo esc_html((string) $order->tracking_no); ?></span>
                                                <?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>

                                    <?php if ($refund): ?>
                                    <div class="order-refund-status">
                                        <div>
                                            <?php _e('售后状态:', 'qilingshop'); ?> <?php echo esc_html($refund_manager ? $refund_manager->get_refund_status_text($refund) : __('未知', 'qilingshop')); ?>
                                        </div>
                                        <?php if (!empty($refund->reason)): ?>
                                        <div class="refund-detail-line"><?php _e('售后原因:', 'qilingshop'); ?> <?php echo esc_html($refund->reason); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($refund_meta['effective_mode_label'])): ?>
                                        <div class="refund-detail-line"><?php _e('退款方式:', 'qilingshop'); ?> <?php echo esc_html($refund_meta['effective_mode_label']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($refund_meta['gateway_status_label'])): ?>
                                        <div class="refund-detail-line"><?php _e('原路状态:', 'qilingshop'); ?> <?php echo esc_html($refund_meta['gateway_status_label']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($refund->gateway_refund_no)): ?>
                                        <div class="refund-detail-line"><?php _e('网关退款单号:', 'qilingshop'); ?> <?php echo esc_html($refund->gateway_refund_no); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($refund->gateway_refunded_at)): ?>
                                        <div class="refund-detail-line"><?php _e('网关退款时间:', 'qilingshop'); ?> <?php echo esc_html($refund->gateway_refunded_at); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($refund->gateway_error)): ?>
                                        <div class="refund-detail-line is-warning"><?php _e('失败原因:', 'qilingshop'); ?> <?php echo esc_html($refund->gateway_error); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($refund_meta['is_processing'])): ?>
                                        <div class="refund-detail-line"><?php _e('退款正在处理中，请稍后刷新查看。', 'qilingshop'); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($refund_meta['needs_reconcile'])): ?>
                                        <div class="refund-detail-line is-warning"><?php _e('该笔退款可能已实际退回，请先联系商家人工核对，勿重复提交。', 'qilingshop'); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($refund_meta['stored_mode']) && !empty($refund_meta['configured_mode']) && $refund_meta['stored_mode'] !== $refund_meta['configured_mode']): ?>
                                        <div class="refund-detail-line"><?php _e('当前店铺退款策略已变更，本单按历史执行方式展示。', 'qilingshop'); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($refund_evidence_images)): ?>
                                        <div class="refund-evidence-list">
                                            <?php foreach ($refund_evidence_images as $image_url): ?>
                                            <a href="<?php echo esc_url($image_url); ?>" target="_blank" rel="noopener">
                                                <img src="<?php echo esc_url($image_url); ?>" alt="<?php esc_attr_e('售后凭证', 'qilingshop'); ?>">
                                            </a>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($refund_return_required && !empty($refund->return_address)): ?>
                                        <div class="refund-return-box">
                                            <strong><?php _e('退货地址:', 'qilingshop'); ?></strong>
                                            <span><?php echo nl2br(esc_html($refund->return_address)); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($refund_return_required && (!empty($refund->return_shipping_company) || !empty($refund->return_tracking_no))): ?>
                                        <div class="refund-detail-line">
                                            <?php _e('退货物流:', 'qilingshop'); ?>
                                            <?php echo esc_html(trim((string) $refund->return_shipping_company)); ?>
                                            <?php if (!empty($refund->return_tracking_no)): ?>
                                            <span><?php _e('单号:', 'qilingshop'); ?> <?php echo esc_html($refund->return_tracking_no); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>

                                    <?php if ($invoice): ?>
                                    <div class="order-invoice-status">
                                        <div>
                                            <?php _e('发票状态:', 'qilingshop'); ?>
                                            <span class="qls-invoice-status <?php echo esc_attr($invoice_status_class); ?>">
                                                <?php echo esc_html($invoice_status_text); ?>
                                            </span>
                                        </div>
                                        <div class="invoice-detail-line">
                                            <?php echo esc_html($invoice_title_type_text); ?> · <?php echo esc_html($invoice->title); ?>
                                        </div>
                                        <?php if (!empty($invoice->invoice_number)): ?>
                                        <div class="invoice-detail-line"><?php _e('发票号码:', 'qilingshop'); ?> <?php echo esc_html($invoice->invoice_number); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($invoice->admin_remark)): ?>
                                        <div class="invoice-detail-line"><?php _e('处理备注:', 'qilingshop'); ?> <?php echo esc_html($invoice->admin_remark); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    </div>
                                    
                                    <div class="order-actions">
                                        <?php if ($order->status == 0): // 待付款 ?>
                                        <a href="<?php echo esc_url(add_query_arg(['pay' => 'shop', 'order' => $order->order_no], home_url('/'))); ?>" class="qls-btn qls-btn-primary"><?php _e('去付款', 'qilingshop'); ?></a>
                                        <button type="button" class="qls-btn qls-cancel-order" data-id="<?php echo esc_attr($order->id); ?>"><?php _e('取消订单', 'qilingshop'); ?></button>
                                        <?php elseif ($order->status == 1 && !empty($shipments)): // 部分发货 ?>
                                            <?php foreach ($shipments as $shipment_index => $shipment): ?>
                                            <?php $shipment_no = !empty($shipment->tracking_no) ? (string) $shipment->tracking_no : (string) ($shipment->waybill_no ?? ''); ?>
                                            <?php if ($shipment_no !== ''): ?>
                                            <button type="button" class="qls-btn qls-btn-outline qls-view-tracking" data-company="<?php echo esc_attr($shipment->shipping_company); ?>" data-no="<?php echo esc_attr($shipment_no); ?>" data-order-no="<?php echo esc_attr($order->order_no); ?>">
                                                <?php printf(esc_html__('查看物流%d', 'qilingshop'), $shipment_index + 1); ?>
                                            </button>
                                            <?php endif; ?>
                                            <?php endforeach; ?>
                                        <?php elseif ($order->status == 2): // 待收货 ?>
                                        <button type="button" class="qls-btn qls-btn-primary qls-confirm-receive" data-order="<?php echo esc_attr($order->order_no); ?>"><?php _e('确认收货', 'qilingshop'); ?></button>
                                        <?php if (!empty($shipments)): ?>
                                            <?php foreach ($shipments as $shipment_index => $shipment): ?>
                                            <?php $shipment_no = !empty($shipment->tracking_no) ? (string) $shipment->tracking_no : (string) ($shipment->waybill_no ?? ''); ?>
                                            <?php if ($shipment_no !== ''): ?>
                                            <button type="button" class="qls-btn qls-btn-outline qls-view-tracking" data-company="<?php echo esc_attr($shipment->shipping_company); ?>" data-no="<?php echo esc_attr($shipment_no); ?>" data-order-no="<?php echo esc_attr($order->order_no); ?>">
                                                <?php printf(esc_html__('查看物流%d', 'qilingshop'), $shipment_index + 1); ?>
                                            </button>
                                            <?php endif; ?>
                                            <?php endforeach; ?>
                                        <?php elseif ($order->tracking_no): ?>
                                        <button type="button" class="qls-btn qls-btn-outline qls-view-tracking" data-company="<?php echo esc_attr($order->shipping_company); ?>" data-no="<?php echo esc_attr($order->tracking_no); ?>" data-order-no="<?php echo esc_attr($order->order_no); ?>"><?php _e('查看物流', 'qilingshop'); ?></button>
                                        <?php endif; ?>
                                        <?php elseif ($order->status == 3): // 已完成 ?>
                                        <?php 
                                        // 检查订单是否有未评价的商品
                                        $has_unreviewd = false;
                                        $all_reviewed = true;
                                        foreach ($order->items as $check_item) {
                                            if (empty($check_item->is_reviewed)) {
                                                $has_unreviewd = true;
                                                $all_reviewed = false;
                                                break;
                                            }
                                        }
                                        ?>
                                        <?php if ($has_unreviewd && get_option('qls_shop_review_enabled', true)): ?>
                                        <button type="button" class="qls-btn qls-btn-primary qls-open-review" 
                                                data-order-id="<?php echo esc_attr($order->id); ?>"
                                                data-order-no="<?php echo esc_attr($order->order_no); ?>">
                                            <?php _e('去评价', 'qilingshop'); ?>
                                        </button>
                                        <?php elseif ($all_reviewed): ?>
                                        <button type="button" class="qls-btn qls-btn-outline qls-view-my-review" 
                                                data-order-id="<?php echo esc_attr($order->id); ?>">
                                            <span class="dashicons dashicons-star-filled review-icon" aria-hidden="true"></span>
                                            <span class="review-label"><?php _e('已评价', 'qilingshop'); ?></span>
                                        </button>
                                        <?php endif; ?>
                                        <?php if (!empty($shipments)): ?>
                                            <?php foreach ($shipments as $shipment_index => $shipment): ?>
                                            <?php $shipment_no = !empty($shipment->tracking_no) ? (string) $shipment->tracking_no : (string) ($shipment->waybill_no ?? ''); ?>
                                            <?php if ($shipment_no !== ''): ?>
                                            <button type="button" class="qls-btn qls-btn-outline qls-view-tracking" data-company="<?php echo esc_attr($shipment->shipping_company); ?>" data-no="<?php echo esc_attr($shipment_no); ?>" data-order-no="<?php echo esc_attr($order->order_no); ?>">
                                                <?php printf(esc_html__('查看物流%d', 'qilingshop'), $shipment_index + 1); ?>
                                            </button>
                                            <?php endif; ?>
                                            <?php endforeach; ?>
                                        <?php elseif (!empty($order->tracking_no)): ?>
                                        <button type="button" class="qls-btn qls-btn-outline qls-view-tracking" data-company="<?php echo esc_attr($order->shipping_company); ?>" data-no="<?php echo esc_attr($order->tracking_no); ?>" data-order-no="<?php echo esc_attr($order->order_no); ?>"><?php _e('查看物流', 'qilingshop'); ?></button>
                                        <?php endif; ?>
                                        <?php 
                                        // 再次购买 - 获取第一个商品的链接
                                        $buy_again_url = '';
                                        if (!empty($order->items)) {
                                            $first_item = $order->items[0];
                                            $first_product = qls_product()->get($first_item->product_id);
                                            if ($first_product && $first_product->status == 1) {
                                                $buy_again_url = qls_shop_public()->get_product_url($first_product);
                                            }
                                        }
                                        // 如果商品已删除或不存在，跳转到商城首页
                                        if (empty($buy_again_url)) {
                                            $buy_again_url = qls_shop_public()->get_shop_url() ?: home_url('/');
                                        }
                                        ?>
                                        <a href="<?php echo esc_url($buy_again_url); ?>" class="qls-btn qls-btn-outline"><?php _e('再次购买', 'qilingshop'); ?></a>
                                        <?php endif; ?>

                                        <?php
                                        $can_apply_invoice = $invoice_enabled && in_array((int) $order->status, [
                                            QLS_Shop_Order::STATUS_PAID,
                                            QLS_Shop_Order::STATUS_SHIPPED,
                                            QLS_Shop_Order::STATUS_COMPLETED,
                                        ], true);
                                        if ($invoice && !in_array($invoice_status, [QLS_Invoice::STATUS_REJECTED, QLS_Invoice::STATUS_CANCELLED], true)) {
                                            $can_apply_invoice = false;
                                        }
                                        $can_apply_refund = in_array((int) $order->status, [1, 2, 3], true) && empty($order->is_group_order);
                                        if ($refund && in_array($refund_status, $active_refund_statuses, true)) {
                                            $can_apply_refund = false;
                                        }
                                        ?>

                                        <?php if ($can_apply_invoice): ?>
                                        <?php
                                        $invoice_button_source = $invoice ?: $default_invoice_title;
                                        $invoice_button_title_type = $invoice_button_source->title_type ?? QLS_Invoice::TITLE_PERSONAL;
                                        $invoice_button_title = $invoice_button_source->title ?? wp_get_current_user()->display_name;
                                        $invoice_button_tax_no = $invoice_button_source->tax_no ?? '';
                                        $invoice_button_email = $invoice_button_source->email ?? wp_get_current_user()->user_email;
                                        $invoice_button_phone = $invoice ? ($invoice->phone ?? '') : ($default_invoice_title->registered_phone ?? '');
                                        $invoice_button_title_id = (!$invoice && !empty($default_invoice_title->id)) ? (int) $default_invoice_title->id : 0;
                                        ?>
                                        <button type="button"
                                                class="qls-btn qls-btn-outline qls-apply-invoice"
                                                data-order-id="<?php echo esc_attr($order->id); ?>"
                                                data-title-id="<?php echo esc_attr($invoice_button_title_id); ?>"
                                                data-has-invoice="<?php echo $invoice ? '1' : '0'; ?>"
                                                data-title-type="<?php echo esc_attr($invoice_button_title_type); ?>"
                                                data-title="<?php echo esc_attr($invoice_button_title); ?>"
                                                data-tax-no="<?php echo esc_attr($invoice_button_tax_no); ?>"
                                                data-email="<?php echo esc_attr($invoice_button_email); ?>"
                                                data-phone="<?php echo esc_attr($invoice_button_phone); ?>">
                                            <?php echo $invoice ? esc_html__('重新申请发票', 'qilingshop') : esc_html__('申请发票', 'qilingshop'); ?>
                                        </button>
                                        <?php endif; ?>
                                        <?php if ($invoice): ?>
                                        <button type="button"
                                                class="qls-btn qls-btn-outline qls-view-invoice"
                                                data-status-text="<?php echo esc_attr($invoice_status_text); ?>"
                                                data-invoice-type="<?php echo esc_attr($invoice_type_text); ?>"
                                                data-title-type="<?php echo esc_attr($invoice_title_type_text); ?>"
                                                data-title="<?php echo esc_attr($invoice->title); ?>"
                                                data-tax-no="<?php echo esc_attr($invoice->tax_no ?? ''); ?>"
                                                data-amount="<?php echo esc_attr(number_format((float) $invoice->amount, 2)); ?>"
                                                data-email="<?php echo esc_attr($invoice->email ?? ''); ?>"
                                                data-phone="<?php echo esc_attr($invoice->phone ?? ''); ?>"
                                                data-code="<?php echo esc_attr($invoice->invoice_code ?? ''); ?>"
                                                data-number="<?php echo esc_attr($invoice->invoice_number ?? ''); ?>"
                                                data-url="<?php echo esc_url($invoice->invoice_url ?? ''); ?>"
                                                data-remark="<?php echo esc_attr($invoice->admin_remark ?? ''); ?>"
                                                data-requested-at="<?php echo esc_attr($invoice->requested_at ?? ''); ?>"
                                                data-issued-at="<?php echo esc_attr($invoice->issued_at ?? ''); ?>"
                                                data-rejected-at="<?php echo esc_attr($invoice->rejected_at ?? ''); ?>"
                                                data-cancelled-at="<?php echo esc_attr($invoice->cancelled_at ?? ''); ?>">
                                            <?php _e('查看发票', 'qilingshop'); ?>
                                        </button>
                                        <?php endif; ?>
                                        <?php if ($invoice && $invoice_status === QLS_Invoice::STATUS_PENDING): ?>
                                        <button type="button" class="qls-btn qls-btn-outline qls-cancel-invoice" data-invoice-id="<?php echo esc_attr($invoice->id); ?>"><?php _e('撤销发票', 'qilingshop'); ?></button>
                                        <?php endif; ?>
                                        <?php if ($can_apply_refund): ?>
                                        <button type="button" class="qls-btn qls-btn-outline qls-apply-refund" data-order-id="<?php echo esc_attr($order->id); ?>"><?php _e('申请售后', 'qilingshop'); ?></button>
                                        <?php endif; ?>
                                        <?php if ($refund && in_array($refund_status, [QLS_Shop_Refund::STATUS_PENDING, QLS_Shop_Refund::STATUS_APPROVED], true) && (int) $order->status === 5): ?>
                                        <button type="button" class="qls-btn qls-btn-outline qls-cancel-refund" data-refund-id="<?php echo esc_attr($refund->id); ?>"><?php _e('撤销申请', 'qilingshop'); ?></button>
                                        <?php endif; ?>
                                        <?php if ($refund && $refund_return_required && $refund_status === QLS_Shop_Refund::STATUS_APPROVED && (int) $order->status === 5): ?>
                                        <button type="button" class="qls-btn qls-btn-primary qls-submit-return" data-refund-id="<?php echo esc_attr($refund->id); ?>"><?php _e('填写退货物流', 'qilingshop'); ?></button>
                                        <?php endif; ?>
                                        <?php if (!empty($tickets_page_url)): ?>
                                        <a href="<?php echo esc_url(add_query_arg('order_id', (int) $order->id, $tickets_page_url)); ?>" class="qls-btn qls-btn-outline"><?php _e('提交工单', 'qilingshop'); ?></a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- 分页 -->
                        <?php 
                        $total_pages = ceil($total / $limit);
                        if ($total_pages > 1):
                        ?>
                        <div class="qls-pagination">
                            <?php
                            $pagination_base = str_replace(
                                '999999999',
                                '%#%',
                                add_query_arg('qls_orders_page', 999999999, remove_query_arg(['paged', 'qls_orders_page']))
                            );
                            echo paginate_links([
                                'base'      => $pagination_base,
                                'format'    => '',
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'total'     => $total_pages,
                                'current'   => $paged,
                            ]);
                            ?>
                        </div>
                        <?php endif; ?>
                        
                        <?php endif; ?>
                    </div>
                </main>
            </div>
        </div>
    </div>
</div>

<!-- 订单操作弹窗 -->
<div id="qls-order-modal" class="qls-modal" style="display:none;">
    <div class="qls-modal-content" style="max-width: 400px;">
        <h3 id="qls-modal-title"><?php _e('提示', 'qilingshop'); ?></h3>
        <p id="qls-modal-message" style="margin: 20px 0; color: #666; font-size: 15px;"></p>
        <div class="form-actions" style="justify-content: flex-end; display: flex; gap: 10px;">
            <button type="button" class="qls-btn qls-btn-secondary qls-modal-cancel"><?php _e('取消', 'qilingshop'); ?></button>
            <button type="button" id="qls-modal-confirm" class="qls-btn qls-btn-primary"><?php _e('确定', 'qilingshop'); ?></button>
        </div>
    </div>
</div>

<!-- 退款申请弹窗 -->
<div id="qls-refund-modal" class="qls-modal" style="display:none;">
    <div class="qls-modal-content" style="max-width: 420px;">
        <h3><?php _e('申请售后/退款', 'qilingshop'); ?></h3>
        <input type="hidden" id="qls-refund-order-id" value="">
        <p style="margin: 16px 0 8px; color:#666;"><?php _e('请填写退货/退款原因', 'qilingshop'); ?></p>
        <textarea id="qls-refund-reason" rows="4" style="width:100%;" required></textarea>
        <div class="qls-refund-upload">
            <div class="qls-refund-upload-title"><?php _e('图片凭证（可选，最多6张）', 'qilingshop'); ?></div>
            <p class="description" style="margin: 4px 0 8px; color:#888; font-size: 13px;">
                <?php printf(esc_html__('最多上传%d张图片，单张不超过2MB，仅支持JPG/PNG格式', 'qilingshop'), 6); ?>
            </p>
            <div id="qls-refund-images" class="qls-refund-upload-list">
                <button type="button" id="qls-refund-add-image" class="qls-refund-upload-btn"><?php _e('添加图片', 'qilingshop'); ?></button>
            </div>
            <input type="file" id="qls-refund-image-input" accept="image/jpeg,image/png,.jpg,.jpeg,.png" multiple class="qls-hidden">
        </div>
        <div class="form-actions" style="justify-content: flex-end; display: flex; gap: 10px; margin-top: 16px;">
            <button type="button" class="qls-btn qls-btn-secondary qls-modal-cancel"><?php _e('取消', 'qilingshop'); ?></button>
            <button type="button" id="qls-refund-submit" class="qls-btn qls-btn-primary"><?php _e('提交申请', 'qilingshop'); ?></button>
        </div>
    </div>
</div>

<!-- 发票申请弹窗 -->
<div id="qls-invoice-modal" class="qls-modal" style="display:none;">
    <div class="qls-modal-content qls-invoice-modal-content">
        <button type="button" class="qls-modal-x qls-modal-close" aria-label="<?php esc_attr_e('关闭', 'qilingshop'); ?>">×</button>
        <h3><?php _e('申请发票', 'qilingshop'); ?></h3>
        <input type="hidden" id="qls-invoice-order-id" value="">
        <?php if (!empty($invoice_titles)): ?>
        <label class="qls-invoice-field qls-invoice-saved-title-field">
            <span><?php _e('常用发票信息', 'qilingshop'); ?></span>
            <select id="qls-invoice-saved-title">
                <option value=""><?php _e('手动填写', 'qilingshop'); ?></option>
                <?php foreach ($invoice_titles as $saved_title): ?>
                    <?php
                    $saved_title_id = (int) ($saved_title->id ?? 0);
                    $saved_title_label = trim((string) ($saved_title->title ?? ''));
                    if ($saved_title_label === '') {
                        continue;
                    }
                    ?>
                    <option value="<?php echo esc_attr($saved_title_id); ?>"
                            data-title-type="<?php echo esc_attr($saved_title->title_type ?? QLS_Invoice::TITLE_PERSONAL); ?>"
                            data-title="<?php echo esc_attr($saved_title_label); ?>"
                            data-tax-no="<?php echo esc_attr($saved_title->tax_no ?? ''); ?>"
                            data-email="<?php echo esc_attr($saved_title->email ?? ''); ?>"
                            data-phone="<?php echo esc_attr($saved_title->registered_phone ?? ''); ?>">
                        <?php echo esc_html($saved_title_label . (!empty($saved_title->is_default) ? ' · ' . __('默认', 'qilingshop') : '')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        <label class="qls-invoice-field">
            <span><?php _e('发票类型', 'qilingshop'); ?></span>
            <select id="qls-invoice-type">
                <option value="electronic"><?php _e('电子普通发票', 'qilingshop'); ?></option>
                <option value="paper"><?php _e('纸质普通发票', 'qilingshop'); ?></option>
            </select>
        </label>
        <div class="qls-invoice-radio-group">
            <label><input type="radio" name="qls_invoice_title_type" value="personal" checked> <?php _e('个人抬头', 'qilingshop'); ?></label>
            <label><input type="radio" name="qls_invoice_title_type" value="company"> <?php _e('企业抬头', 'qilingshop'); ?></label>
        </div>
        <label class="qls-invoice-field">
            <span><?php _e('发票抬头', 'qilingshop'); ?></span>
            <input type="text" id="qls-invoice-title" value="" placeholder="<?php esc_attr_e('请输入发票抬头', 'qilingshop'); ?>">
        </label>
        <label class="qls-invoice-field qls-invoice-company-field">
            <span><?php _e('企业税号', 'qilingshop'); ?></span>
            <input type="text" id="qls-invoice-tax-no" value="" placeholder="<?php esc_attr_e('企业抬头必填', 'qilingshop'); ?>">
        </label>
        <label class="qls-invoice-field">
            <span><?php _e('接收邮箱', 'qilingshop'); ?></span>
            <input type="email" id="qls-invoice-email" value="" placeholder="<?php esc_attr_e('用于接收电子发票，可选', 'qilingshop'); ?>">
        </label>
        <label class="qls-invoice-field">
            <span><?php _e('联系电话', 'qilingshop'); ?></span>
            <input type="text" id="qls-invoice-phone" value="" placeholder="<?php esc_attr_e('可选', 'qilingshop'); ?>">
        </label>
        <p class="qls-invoice-tip"><?php _e('提交后商家会在后台审核并开具发票，开票结果会显示在当前订单中。', 'qilingshop'); ?></p>
        <div class="form-actions qls-invoice-modal-actions">
            <button type="button" class="qls-btn qls-btn-secondary qls-modal-cancel"><?php _e('取消', 'qilingshop'); ?></button>
            <button type="button" id="qls-invoice-submit" class="qls-btn qls-btn-primary"><?php _e('提交申请', 'qilingshop'); ?></button>
        </div>
    </div>
</div>

<!-- 发票详情弹窗 -->
<div id="qls-invoice-detail-modal" class="qls-modal" style="display:none;">
    <div class="qls-modal-content qls-invoice-modal-content" style="max-width: 460px;">
        <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #eee; padding-bottom:10px; margin-bottom:15px;">
            <h3 style="margin:0;"><?php _e('发票详情', 'qilingshop'); ?></h3>
            <span class="dashicons dashicons-no-alt qls-modal-close" style="cursor:pointer; font-size:24px;"></span>
        </div>
        <dl class="qls-invoice-detail-list">
            <div><dt><?php _e('状态', 'qilingshop'); ?></dt><dd id="qls-invoice-detail-status"></dd></div>
            <div><dt><?php _e('类型', 'qilingshop'); ?></dt><dd id="qls-invoice-detail-type"></dd></div>
            <div><dt><?php _e('抬头', 'qilingshop'); ?></dt><dd id="qls-invoice-detail-title"></dd></div>
            <div><dt><?php _e('金额', 'qilingshop'); ?></dt><dd id="qls-invoice-detail-amount"></dd></div>
            <div><dt><?php _e('接收方式', 'qilingshop'); ?></dt><dd id="qls-invoice-detail-contact"></dd></div>
            <div class="qls-invoice-detail-code-row"><dt><?php _e('发票代码', 'qilingshop'); ?></dt><dd id="qls-invoice-detail-code"></dd></div>
            <div class="qls-invoice-detail-number-row"><dt><?php _e('发票号码', 'qilingshop'); ?></dt><dd id="qls-invoice-detail-number"></dd></div>
            <div class="qls-invoice-detail-remark-row"><dt><?php _e('处理备注', 'qilingshop'); ?></dt><dd id="qls-invoice-detail-remark"></dd></div>
            <div><dt><?php _e('申请时间', 'qilingshop'); ?></dt><dd id="qls-invoice-detail-requested"></dd></div>
            <div class="qls-invoice-detail-finished-row"><dt><?php _e('处理时间', 'qilingshop'); ?></dt><dd id="qls-invoice-detail-finished"></dd></div>
        </dl>
        <a href="#" id="qls-invoice-detail-url" class="qls-btn qls-btn-primary qls-hidden" target="_blank" rel="noopener"><?php _e('打开电子发票', 'qilingshop'); ?></a>
    </div>
</div>

<!-- 退货物流弹窗 -->
<div id="qls-return-modal" class="qls-modal" style="display:none;">
    <div class="qls-modal-content" style="max-width: 420px;">
        <h3><?php _e('填写退货物流', 'qilingshop'); ?></h3>
        <input type="hidden" id="qls-return-refund-id" value="">
        <p style="margin: 16px 0 8px; color:#666;"><?php _e('请按商家提供的退货地址寄回商品后填写物流信息。', 'qilingshop'); ?></p>
        <label class="qls-return-field">
            <span><?php _e('物流公司', 'qilingshop'); ?></span>
            <input type="text" id="qls-return-company" value="">
        </label>
        <label class="qls-return-field">
            <span><?php _e('物流单号', 'qilingshop'); ?></span>
            <input type="text" id="qls-return-tracking" value="">
        </label>
        <div class="form-actions" style="justify-content: flex-end; display: flex; gap: 10px; margin-top: 16px;">
            <button type="button" class="qls-btn qls-btn-secondary qls-modal-cancel"><?php _e('取消', 'qilingshop'); ?></button>
            <button type="button" id="qls-return-submit" class="qls-btn qls-btn-primary"><?php _e('提交物流', 'qilingshop'); ?></button>
        </div>
    </div>
</div>

<!-- 物流查询弹窗 -->
<div id="qls-logistics-modal" class="qls-modal" style="display:none;">
    <div class="qls-modal-content" style="max-width: 600px; max-height: 80vh; display: flex; flex-direction: column;">
        <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #eee; padding-bottom:10px; margin-bottom:15px;">
            <h3 style="margin:0;"><?php _e('物流详情', 'qilingshop'); ?></h3>
            <span class="dashicons dashicons-no-alt qls-modal-close" style="cursor:pointer; font-size:24px;"></span>
        </div>
        <div class="modal-body" style="overflow-y:auto; flex:1;">
             <div id="logistics-loading" style="text-align:center; padding:30px; display:none;">
                 <span class="spinner" style="display:inline-block; width:20px; height:20px; border:2px solid #ddd; border-top-color:#333; border-radius:50%; animation:spin 1s linear infinite;"></span>
                 <p><?php _e('加载中...', 'qilingshop'); ?></p>
             </div>
             <div id="logistics-content"></div>
        </div>
    </div>
</div>
<style>
@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

/* Virtual Content Styles */
.qls-virtual-content-box {
    background: linear-gradient(135deg, #f0f9ff 0%, #e8f5e9 100%);
    border: 1px solid #c8e6c9;
    border-radius: 8px;
    padding: 12px 15px;
    margin: 10px 0 5px 70px;
    font-size: 13px;
}
.qls-virtual-content-box .vc-label {
    font-weight: 600;
    color: #2e7d32;
    margin-right: 8px;
}
.qls-virtual-content-box .vc-link {
    background: #4caf50;
    color: #fff;
    padding: 4px 12px;
    border-radius: 4px;
    text-decoration: none;
    font-size: 12px;
    margin-right: 10px;
}
.qls-virtual-content-box .vc-link:hover {
    background: #388e3c;
}
.qls-virtual-content-box .vc-code {
    color: #666;
}
.qls-virtual-content-box .vc-code strong {
    color: #d32f2f;
    user-select: all;
}
.qls-virtual-content-box .vc-cards-list {
    margin-top: 8px;
}
.qls-virtual-content-box .vc-card-item {
    background: #fff;
    border: 1px dashed #81c784;
    border-radius: 4px;
    padding: 8px 12px;
    margin-top: 5px;
    display: flex;
    gap: 20px;
    font-family: monospace;
    font-size: 13px;
    user-select: all;
}
.qls-virtual-content-box .card-no {
    color: #1976d2;
}
.qls-virtual-content-box .card-secret {
    color: #d32f2f;
}
.qls-virtual-content-box .vc-error {
    color: #d32f2f;
}
.qls-virtual-content-box .vc-custom-content {
    margin-top: 8px;
    padding: 10px;
    background: #fff;
    border-radius: 4px;
    line-height: 1.6;
}
@media (max-width: 768px) {
    .qls-virtual-content-box {
        margin-left: 0;
    }
    .qls-virtual-content-box .vc-card-item {
        flex-direction: column;
        gap: 5px;
    }
}

/* Modal Styles */
.qls-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 99999;
    display: flex;
    justify-content: center;
    align-items: center;
}
.qls-modal-content {
    background: #fff;
    padding: 20px;
    border-radius: 8px;
    width: 90%;
    max-width: 600px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
    position: relative;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
}
.qls-hidden {
    display: none;
}
.refund-detail-line {
    margin-top: 4px;
}
.refund-evidence-list {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-top: 6px;
}
.refund-evidence-list a,
.qls-refund-upload-preview {
    width: 46px;
    height: 46px;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    overflow: hidden;
    background: #fff;
}
.refund-evidence-list img,
.qls-refund-upload-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.refund-return-box {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-top: 6px;
    padding: 8px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: #f9fafb;
    color: #4b5563;
}
.qls-refund-upload {
    margin-top: 14px;
}
.qls-refund-upload-title {
    margin-bottom: 8px;
    color: #666;
    font-size: 13px;
}
.qls-refund-upload-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.qls-refund-upload-preview {
    position: relative;
}
.qls-refund-remove-img {
    position: absolute;
    top: -1px;
    right: -1px;
    width: 18px;
    height: 18px;
    border-radius: 0 6px 0 6px;
    background: rgba(0,0,0,0.65);
    color: #fff;
    line-height: 18px;
    text-align: center;
    cursor: pointer;
    font-size: 14px;
}
.qls-refund-upload-btn {
    width: 46px;
    height: 46px;
    border: 1px dashed #cbd5e1;
    border-radius: 6px;
    background: #f8fafc;
    color: #475569;
    cursor: pointer;
    font-size: 12px;
}
.qls-return-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-top: 12px;
    color: #4b5563;
    font-size: 13px;
}
.qls-return-field input {
    width: 100%;
}
.order-invoice-status {
    margin: 0;
    font-size: 13px;
    color: #6b7280;
    line-height: 1.5;
}
.invoice-detail-line {
    margin-top: 4px;
}
.qls-invoice-status {
    display: inline-flex;
    align-items: center;
    min-height: 22px;
    padding: 0 8px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
}
.qls-invoice-status.is-pending {
    background: #fff7ed;
    color: #9a3412;
}
.qls-invoice-status.is-issued {
    background: #ecfdf5;
    color: #047857;
}
.qls-invoice-status.is-rejected {
    background: #fef2f2;
    color: #b91c1c;
}
.qls-invoice-status.is-cancelled,
.qls-invoice-status.is-unknown {
    background: #f1f5f9;
    color: #475569;
}
.qls-invoice-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-top: 12px;
    color: #4b5563;
    font-size: 13px;
}
.qls-invoice-field input,
.qls-invoice-field select {
    width: 100%;
}
.qls-invoice-radio-group {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 14px;
    color: #4b5563;
    font-size: 13px;
}
.qls-invoice-tip {
    margin: 12px 0 0;
    color: #6b7280;
    font-size: 13px;
    line-height: 1.6;
}
.qls-invoice-detail-list {
    display: grid;
    gap: 10px;
    margin: 0 0 16px;
}
.qls-invoice-detail-list div {
    display: grid;
    grid-template-columns: 86px 1fr;
    gap: 10px;
    padding: 8px 0;
    border-bottom: 1px solid #eef2f7;
}
.qls-invoice-detail-list dt {
    color: #6b7280;
    font-weight: 600;
}
.qls-invoice-detail-list dd {
    margin: 0;
    color: #111827;
    word-break: break-all;
}
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .order-invoice-status,
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-invoice-field,
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-invoice-radio-group,
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-invoice-tip,
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-invoice-detail-list dt {
    color: var(--qiling-dark-text-muted, #cbd5e1);
}
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-invoice-modal-content {
    background: var(--qiling-dark-surface, #1f2937);
    color: var(--qiling-dark-text, #f8fafc);
    border: 1px solid var(--qiling-dark-border, #334155);
}
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-invoice-modal-content h3,
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-invoice-detail-list dd {
    color: var(--qiling-dark-text, #f8fafc);
}
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-invoice-field input,
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-invoice-field select {
    background: rgba(15, 23, 42, 0.82);
    color: var(--qiling-dark-text, #f8fafc);
    border-color: var(--qiling-dark-border, #334155);
}
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-invoice-detail-list div {
    border-bottom-color: var(--qiling-dark-border, #334155);
}
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-modal-content {
    background: var(--qiling-dark-surface, #1f2937);
    color: var(--qiling-dark-text, #f8fafc);
    border: 1px solid var(--qiling-dark-border, #334155);
}
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-modal-content h3,
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .refund-detail-line,
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-return-field,
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-refund-upload-title,
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) #logistics-content,
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-f-timeline-item {
    color: var(--qiling-dark-text-muted, #cbd5e1);
}
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .refund-return-box,
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-virtual-content-box,
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .refund-evidence-list a,
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-refund-upload-preview,
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-refund-upload-btn {
    background: rgba(15, 23, 42, 0.82);
    border-color: var(--qiling-dark-border, #334155);
    color: var(--qiling-dark-text, #f8fafc);
}
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-refund-upload-btn {
    border-style: dashed;
}
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-return-field input,
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-modal-content input[type="text"],
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-modal-content input[type="email"],
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-modal-content textarea,
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-modal-content select {
    background: rgba(15, 23, 42, 0.92);
    color: var(--qiling-dark-text, #f8fafc);
    border-color: var(--qiling-dark-border, #334155);
}
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-virtual-content-box .vc-label,
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-virtual-content-box .card-no,
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-virtual-content-box .card-secret,
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-virtual-content-box .vc-code strong,
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) #logistics-content strong {
    color: var(--qiling-dark-text, #f8fafc);
}
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-virtual-content-box .vc-link {
    color: #7dd3fc;
}
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-virtual-content-box .vc-card-item,
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-virtual-content-box .vc-custom-content {
    background: rgba(30, 41, 59, 0.92);
    border-color: var(--qiling-dark-border, #334155);
    color: var(--qiling-dark-text, #f8fafc);
}
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-f-timeline-item {
    border-left-color: var(--qiling-dark-border, #334155);
}
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-f-timeline-item:before {
    background: #475569;
    border-color: var(--qiling-dark-surface, #1f2937);
    box-shadow: 0 0 0 1px #475569;
}
:is(.dark, .dark-mode, html.dark-mode, body.dark-mode, [data-theme='dark']) .qls-f-timeline-item.active:before {
    background: #38bdf8;
    box-shadow: 0 0 0 1px #38bdf8;
}

/* Frontend Timeline Styles */
.qls-f-timeline { margin: 10px 0; padding-left: 10px; }
.qls-f-timeline-item { position: relative; padding-left: 25px; padding-bottom: 25px; border-left: 2px solid #eee; }
.qls-f-timeline-item:last-child { border-left: 2px solid transparent; }
.qls-f-timeline-item:before { content: ''; position: absolute; left: -6px; top: 0; width: 10px; height: 10px; border-radius: 50%; background: #ccc; border: 2px solid #fff; box-shadow: 0 0 0 1px #ccc; }
.qls-f-timeline-item.active:before { background: #2271b1; box-shadow: 0 0 0 1px #2271b1; }
.qls-f-timeline-time { font-size: 12px; color: #999; margin-bottom: 5px; }
.qls-f-timeline-desc { font-size: 14px; color: #333; line-height: 1.5; }
</style>

<script>
jQuery(document).ready(function($) {
    // 弹窗控制
    function showModal(title, message, onConfirm) {
        $('#qls-modal-title').text(title);
        $('#qls-modal-message').text(message);
        $('#qls-order-modal').fadeIn(200);
        
        $('#qls-modal-confirm').off('click').on('click', function() {
            if (typeof onConfirm === 'function') {
                onConfirm();
            }
            $('#qls-order-modal').fadeOut(200);
        });
    }

    $('.qls-modal-close, .qls-modal-cancel').click(function() {
        $(this).closest('.qls-modal').fadeOut(200);
    });

    // 取消订单
    $('.qls-cancel-order').click(function(e) {
        e.preventDefault();
        var orderId = $(this).data('id');
        
        showModal(
            '<?php _e('取消订单', 'qilingshop'); ?>',
            '<?php _e('确定要取消该订单吗？取消后无法恢复。', 'qilingshop'); ?>',
            function() {
                // 执行取消
                $.post(qlsShop.ajaxUrl || qls_shop_vars.ajax_url, {
                    action: 'qls_cancel_order',
                    order_id: orderId,
                    nonce: qlsShop.nonce || qls_shop_vars.nonce
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data.message || '<?php echo esc_js(__('操作失败', 'qilingshop')); ?>');
                    }
                });
            }
        );
    });

    // 确认收货
    $('.qls-confirm-receive').click(function(e) {
        e.preventDefault();
        var orderNo = $(this).data('order');
        
        showModal(
            '<?php _e('确认收货', 'qilingshop'); ?>',
            '<?php _e('确定已收到商品吗？确认后订单将完成。', 'qilingshop'); ?>',
            function() {
                $.post(qlsShop.ajaxUrl || qls_shop_vars.ajax_url, {
                    action: 'qls_shop_confirm_receive',
                    order_no: orderNo,
                    nonce: qlsShop.nonce || qls_shop_vars.nonce
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data.message || '<?php echo esc_js(__('操作失败', 'qilingshop')); ?>');
                    }
                });
            }
        );
    });

    function toggleInvoiceTaxField() {
        var titleType = $('input[name="qls_invoice_title_type"]:checked').val();
        $('.qls-invoice-company-field').toggle(titleType === 'company');
    }

    $('input[name="qls_invoice_title_type"]').on('change', toggleInvoiceTaxField);
    toggleInvoiceTaxField();

    function fillInvoiceApplyForm(data) {
        data = data || {};
        var titleType = data.titleType || data.title_type || 'personal';
        $('input[name="qls_invoice_title_type"][value="' + titleType + '"]').prop('checked', true);
        $('#qls-invoice-title').val(data.title || '');
        $('#qls-invoice-tax-no').val(data.taxNo || data.tax_no || '');
        $('#qls-invoice-email').val(data.email || '');
        $('#qls-invoice-phone').val(data.phone || data.registered_phone || '');
        toggleInvoiceTaxField();
    }

    $('#qls-invoice-saved-title').on('change', function() {
        var $option = $(this).find('option:selected');
        if (!$option.val()) {
            return;
        }

        fillInvoiceApplyForm({
            titleType: $option.attr('data-title-type') || 'personal',
            title: $option.attr('data-title') || '',
            taxNo: $option.attr('data-tax-no') || '',
            email: $option.attr('data-email') || '',
            phone: $option.attr('data-phone') || ''
        });
    });

    $('.qls-apply-invoice').click(function(e) {
        e.preventDefault();
        var $btn = $(this);
        var savedTitleId = $btn.attr('data-title-id') || '';
        var hasInvoice = $btn.attr('data-has-invoice') === '1';

        $('#qls-invoice-order-id').val($btn.attr('data-order-id') || '');
        $('#qls-invoice-type').val('electronic');
        $('#qls-invoice-saved-title').val(!hasInvoice && savedTitleId ? savedTitleId : '');
        fillInvoiceApplyForm({
            titleType: $btn.attr('data-title-type') || 'personal',
            title: $btn.attr('data-title') || '',
            taxNo: $btn.attr('data-tax-no') || '',
            email: $btn.attr('data-email') || '',
            phone: $btn.attr('data-phone') || ''
        });
        $('#qls-invoice-submit').prop('disabled', false).text('<?php echo esc_js(__('提交申请', 'qilingshop')); ?>');
        $('#qls-invoice-modal').fadeIn(200);
    });

    $('#qls-invoice-submit').click(function() {
        var orderId = $('#qls-invoice-order-id').val();
        var titleType = $('input[name="qls_invoice_title_type"]:checked').val();
        var title = $.trim($('#qls-invoice-title').val());
        var taxNo = $.trim($('#qls-invoice-tax-no').val());

        if (!orderId) {
            alert('<?php echo esc_js(__('请先选择订单', 'qilingshop')); ?>');
            return;
        }
        if (!title) {
            alert('<?php echo esc_js(__('请填写发票抬头', 'qilingshop')); ?>');
            return;
        }
        if (titleType === 'company' && !taxNo) {
            alert('<?php echo esc_js(__('企业抬头请填写税号', 'qilingshop')); ?>');
            return;
        }

        var $submit = $(this);
        $submit.prop('disabled', true).text('<?php echo esc_js(__('提交中...', 'qilingshop')); ?>');

        $.post(qlsShop.ajaxUrl || qls_shop_vars.ajax_url, {
            action: 'qls_apply_invoice',
            order_id: orderId,
            invoice_type: $('#qls-invoice-type').val(),
            title_type: titleType,
            title: title,
            tax_no: taxNo,
            email: $.trim($('#qls-invoice-email').val()),
            phone: $.trim($('#qls-invoice-phone').val()),
            nonce: qlsShop.nonce || qls_shop_vars.nonce
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                $submit.prop('disabled', false).text('<?php echo esc_js(__('提交申请', 'qilingshop')); ?>');
                alert((response.data && response.data.message) || '<?php echo esc_js(__('操作失败', 'qilingshop')); ?>');
            }
        }).fail(function() {
            $submit.prop('disabled', false).text('<?php echo esc_js(__('提交申请', 'qilingshop')); ?>');
            alert('<?php echo esc_js(__('操作失败', 'qilingshop')); ?>');
        });
    });

    $('.qls-cancel-invoice').click(function(e) {
        e.preventDefault();
        var invoiceId = $(this).attr('data-invoice-id');

        showModal(
            '<?php _e('撤销发票', 'qilingshop'); ?>',
            '<?php _e('确定要撤销该发票申请吗？撤销后可以重新申请。', 'qilingshop'); ?>',
            function() {
                $.post(qlsShop.ajaxUrl || qls_shop_vars.ajax_url, {
                    action: 'qls_cancel_invoice',
                    invoice_id: invoiceId,
                    nonce: qlsShop.nonce || qls_shop_vars.nonce
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert((response.data && response.data.message) || '<?php echo esc_js(__('操作失败', 'qilingshop')); ?>');
                    }
                });
            }
        );
    });

    $('.qls-view-invoice').click(function(e) {
        e.preventDefault();
        var $btn = $(this);
        var code = $btn.attr('data-code') || '';
        var number = $btn.attr('data-number') || '';
        var remark = $btn.attr('data-remark') || '';
        var url = $btn.attr('data-url') || '';
        var contact = [];
        var email = $btn.attr('data-email') || '';
        var phone = $btn.attr('data-phone') || '';
        var finishedAt = $btn.attr('data-issued-at') || $btn.attr('data-rejected-at') || $btn.attr('data-cancelled-at') || '';
        var titleText = ($btn.attr('data-title-type') || '') + ' · ' + ($btn.attr('data-title') || '');
        var taxNo = $btn.attr('data-tax-no') || '';

        if (taxNo) {
            titleText += '（' + '<?php echo esc_js(__('税号:', 'qilingshop')); ?>' + taxNo + '）';
        }
        if (email) {
            contact.push('<?php echo esc_js(__('邮箱:', 'qilingshop')); ?>' + email);
        }
        if (phone) {
            contact.push('<?php echo esc_js(__('手机:', 'qilingshop')); ?>' + phone);
        }

        $('#qls-invoice-detail-status').text($btn.attr('data-status-text') || '-');
        $('#qls-invoice-detail-type').text($btn.attr('data-invoice-type') || '-');
        $('#qls-invoice-detail-title').text(titleText);
        $('#qls-invoice-detail-amount').text('¥' + ($btn.attr('data-amount') || '0.00'));
        $('#qls-invoice-detail-contact').text(contact.length ? contact.join(' / ') : '-');
        $('#qls-invoice-detail-requested').text($btn.attr('data-requested-at') || '-');

        $('.qls-invoice-detail-code-row').toggle(!!code);
        $('#qls-invoice-detail-code').text(code);
        $('.qls-invoice-detail-number-row').toggle(!!number);
        $('#qls-invoice-detail-number').text(number);
        $('.qls-invoice-detail-remark-row').toggle(!!remark);
        $('#qls-invoice-detail-remark').text(remark);
        $('.qls-invoice-detail-finished-row').toggle(!!finishedAt);
        $('#qls-invoice-detail-finished').text(finishedAt);

        if (url) {
            $('#qls-invoice-detail-url').attr('href', url).removeClass('qls-hidden');
        } else {
            $('#qls-invoice-detail-url').attr('href', '#').addClass('qls-hidden');
        }

        $('#qls-invoice-detail-modal').fadeIn(200);
    });

    var refundImages = [];
    var refundUploadCount = 0;
    var maxRefundImages = 6;
    var maxRefundImageSize = 2 * 1024 * 1024;
    var allowedRefundImageTypes = ['image/jpeg', 'image/png'];

    function resetRefundImages() {
        refundImages = [];
        refundUploadCount = 0;
        $('#qls-refund-images .qls-refund-upload-preview').remove();
        $('#qls-refund-add-image').show();
        $('#qls-refund-image-input').val('');
        $('#qls-refund-submit').prop('disabled', false).text('<?php echo esc_js(__('提交申请', 'qilingshop')); ?>');
    }

    function updateRefundUploadButton() {
        $('#qls-refund-add-image').toggle(refundImages.length < maxRefundImages);
    }

    function addRefundImagePreview(url) {
        var $preview = $('<div/>', {'class': 'qls-refund-upload-preview'});
        $('<img/>', {src: url, alt: ''}).appendTo($preview);
        $('<span/>', {'class': 'qls-refund-remove-img', 'data-url': url, text: '×'}).appendTo($preview);
        $('#qls-refund-add-image').before($preview);
        updateRefundUploadButton();
    }

    function validateRefundImageFile(file) {
        if (!file) {
            return false;
        }

        var fileName = (file.name || '').toLowerCase();
        var validExtension = /\.(jpe?g|png)$/.test(fileName);
        var validMime = !file.type || allowedRefundImageTypes.indexOf(file.type) !== -1;

        if (!validExtension || !validMime) {
            alert('<?php echo esc_js(__('只支持 JPG、PNG 格式图片', 'qilingshop')); ?>');
            return false;
        }

        if (!file.size || file.size <= 0) {
            alert('<?php echo esc_js(__('无效的图片文件', 'qilingshop')); ?>');
            return false;
        }

        if (file.size > maxRefundImageSize) {
            alert('<?php echo esc_js(__('图片大小不能超过 2MB', 'qilingshop')); ?>');
            return false;
        }

        return true;
    }

    function uploadRefundImage(file) {
        if (!validateRefundImageFile(file)) {
            return;
        }

        if (refundImages.length + refundUploadCount >= maxRefundImages) {
            alert('<?php echo esc_js(__('最多上传6张图片', 'qilingshop')); ?>');
            return;
        }

        var orderId = $('#qls-refund-order-id').val();
        if (!orderId) {
            alert('<?php echo esc_js(__('请先选择售后订单', 'qilingshop')); ?>');
            return;
        }

        var formData = new FormData();
        formData.append('image', file);
        formData.append('order_id', orderId);
        formData.append('action', 'qls_upload_refund_image');
        formData.append('nonce', qlsShop.nonce || qls_shop_vars.nonce);

        refundUploadCount++;
        $('#qls-refund-submit').prop('disabled', true).text('<?php echo esc_js(__('图片上传中...', 'qilingshop')); ?>');

        $.ajax({
            url: qlsShop.ajaxUrl || qls_shop_vars.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success && response.data.url) {
                    refundImages.push(response.data.url);
                    addRefundImagePreview(response.data.url);
                } else {
                    alert((response.data && response.data.message) || '<?php echo esc_js(__('上传失败', 'qilingshop')); ?>');
                }
            },
            error: function() {
                alert('<?php echo esc_js(__('上传失败', 'qilingshop')); ?>');
            },
            complete: function() {
                refundUploadCount--;
                if (refundUploadCount <= 0) {
                    refundUploadCount = 0;
                    $('#qls-refund-submit').prop('disabled', false).text('<?php echo esc_js(__('提交申请', 'qilingshop')); ?>');
                }
                updateRefundUploadButton();
            }
        });
    }

    // 申请退款弹窗
    $('.qls-apply-refund').click(function(e) {
        e.preventDefault();
        var orderId = $(this).data('order-id');
        $('#qls-refund-order-id').val(orderId);
        $('#qls-refund-reason').val('');
        resetRefundImages();
        $('#qls-refund-modal').fadeIn(200);
    });

    $('#qls-refund-add-image').click(function() {
        if (refundImages.length >= maxRefundImages) {
            alert('<?php echo esc_js(__('最多上传6张图片', 'qilingshop')); ?>');
            return;
        }
        $('#qls-refund-image-input').click();
    });

    $('#qls-refund-image-input').change(function() {
        var files = this.files;
        var slots = maxRefundImages - refundImages.length - refundUploadCount;
        for (var i = 0; i < files.length && i < slots; i++) {
            uploadRefundImage(files[i]);
        }
        $(this).val('');
    });

    $(document).on('click', '.qls-refund-remove-img', function() {
        var url = $(this).data('url');
        refundImages = refundImages.filter(function(item) {
            return item !== url;
        });
        $(this).closest('.qls-refund-upload-preview').remove();
        updateRefundUploadButton();
    });

    // 提交退款申请
    $('#qls-refund-submit').click(function() {
        var orderId = $('#qls-refund-order-id').val();
        var reason = $.trim($('#qls-refund-reason').val());

        if (!reason) {
            alert('<?php echo esc_js(__('请填写退货/退款原因', 'qilingshop')); ?>');
            return;
        }

        if (refundUploadCount > 0) {
            alert('<?php echo esc_js(__('图片上传中，请稍候', 'qilingshop')); ?>');
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('<?php echo esc_js(__('提交中...', 'qilingshop')); ?>');

        $.post(qlsShop.ajaxUrl || qls_shop_vars.ajax_url, {
            action: 'qls_apply_refund',
            order_id: orderId,
            reason: reason,
            images: refundImages,
            nonce: qlsShop.nonce || qls_shop_vars.nonce
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                $btn.prop('disabled', false).text('<?php echo esc_js(__('提交申请', 'qilingshop')); ?>');
                alert((response.data && response.data.message) || '<?php echo esc_js(__('操作失败', 'qilingshop')); ?>');
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('<?php echo esc_js(__('提交申请', 'qilingshop')); ?>');
            alert('<?php echo esc_js(__('操作失败', 'qilingshop')); ?>');
        });
    });

    // 填写退货物流
    $('.qls-submit-return').click(function(e) {
        e.preventDefault();
        $('#qls-return-refund-id').val($(this).data('refund-id'));
        $('#qls-return-company').val('');
        $('#qls-return-tracking').val('');
        $('#qls-return-modal').fadeIn(200);
    });

    $('#qls-return-submit').click(function() {
        var refundId = $('#qls-return-refund-id').val();
        var company = $.trim($('#qls-return-company').val());
        var tracking = $.trim($('#qls-return-tracking').val());

        if (!company || !tracking) {
            alert('<?php echo esc_js(__('请填写退货物流公司和单号', 'qilingshop')); ?>');
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('<?php echo esc_js(__('提交中...', 'qilingshop')); ?>');

        $.post(qlsShop.ajaxUrl || qls_shop_vars.ajax_url, {
            action: 'qls_submit_refund_return',
            refund_id: refundId,
            shipping_company: company,
            tracking_no: tracking,
            nonce: qlsShop.nonce || qls_shop_vars.nonce
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                $btn.prop('disabled', false).text('<?php echo esc_js(__('提交物流', 'qilingshop')); ?>');
                alert((response.data && response.data.message) || '<?php echo esc_js(__('操作失败', 'qilingshop')); ?>');
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('<?php echo esc_js(__('提交物流', 'qilingshop')); ?>');
            alert('<?php echo esc_js(__('操作失败', 'qilingshop')); ?>');
        });
    });

    // 撤销退款申请
    $('.qls-cancel-refund').click(function(e) {
        e.preventDefault();
        var refundId = $(this).data('refund-id');

        showModal(
            '<?php _e('撤销申请', 'qilingshop'); ?>',
            '<?php _e('确定要撤销该售后申请吗？', 'qilingshop'); ?>',
            function() {
                $.post(qlsShop.ajaxUrl || qls_shop_vars.ajax_url, {
                    action: 'qls_cancel_refund',
                    refund_id: refundId,
                    nonce: qlsShop.nonce || qls_shop_vars.nonce
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data.message || '<?php echo esc_js(__('操作失败', 'qilingshop')); ?>');
                    }
                });
            }
        );
    });

    // 查看物流
    $('.qls-view-tracking').click(function(e) {
        e.preventDefault();
        var company = $(this).data('company');
        var number = $(this).data('no');
        var orderNo = $(this).data('order-no');
        
        $('#qls-logistics-modal').fadeIn(200);
        $('#logistics-loading').show();
        $('#logistics-content').html('');

        $.post(qlsShop.ajaxUrl || qls_shop_vars.ajax_url, {
            action: 'qls_shop_get_logistics',
            company: company,
            number: number,
            order_no: orderNo,
            nonce: qlsShop.nonce || qls_shop_vars.nonce
        }, function(response) {
            $('#logistics-loading').hide();
            if (response.success) {
                var data = response.data;
                var html = '<div style="padding:15px; background:#f9f9f9; border-radius:4px; margin-bottom:15px;">';
                html += '<p><strong>'+(data.expressCompanyName || company)+'</strong> ('+data.number+')</p>';
                if (data.logisticsStatusDesc) {
                    html += '<p style="color:#2271b1;">' + data.logisticsStatusDesc + '</p>';
                }
                html += '</div>';

                if (data.logisticsTraceDetails && data.logisticsTraceDetails.length) {
                    html += '<div class="qls-f-timeline">';
                    $.each(data.logisticsTraceDetails, function(i, trace) {
                        var time = new Date(trace.time).toLocaleString();
                        var active = i === 0 ? 'active' : '';
                        html += '<div class="qls-f-timeline-item '+active+'">';
                        html += '<div class="qls-f-timeline-time">'+time+'</div>';
                        html += '<div class="qls-f-timeline-desc">'+trace.desc+'</div>';
                        html += '</div>';
                    });
                    html += '</div>';
                } else {
                    html += '<p><?php _e('暂无物流轨迹', 'qilingshop'); ?></p>';
                }
                $('#logistics-content').html(html);
            } else {
                 $('#logistics-content').html('<div class="qls-notice warning">'+(response.data || 'Error')+'</div>');
            }
        });
    });
});
</script>

<!-- 评价弹窗 -->
<?php if (get_option('qls_shop_review_enabled', true)): ?>
<div id="qls-review-modal" class="qls-modal" style="display:none;">
    <div class="qls-modal-content" style="max-width: 600px; max-height: 90vh; overflow-y: auto;">
        <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #eee; padding-bottom:15px; margin-bottom:20px;">
            <h3 style="margin:0;"><?php _e('商品评价', 'qilingshop'); ?></h3>
            <span class="dashicons dashicons-no-alt qls-modal-close" style="cursor:pointer; font-size:24px;"></span>
        </div>
        
        <div id="review-items-list">
            <!-- 动态加载订单商品项 -->
        </div>
        
        <div id="review-form-area" style="display:none;">
            <form id="review-form">
                <input type="hidden" id="review-order-id" name="order_id" value="">
                <input type="hidden" id="review-item-id" name="order_item_id" value="">
                <input type="hidden" id="review-product-id" name="product_id" value="">
                <input type="hidden" id="review-sku-id" name="sku_id" value="">
                <input type="hidden" id="review-sku-info" name="sku_info" value="">
                
                <div class="review-product-info" style="display:flex; gap:15px; padding:15px; background:#f9f9f9; border-radius:8px; margin-bottom:20px;">
                    <img id="review-product-img" src="" alt="" style="width:80px; height:80px; object-fit:cover; border-radius:4px;">
                    <div>
                        <div id="review-product-title" style="font-weight:600;"></div>
                        <div id="review-product-sku" style="color:#999; font-size:13px;"></div>
                    </div>
                </div>
                
                <div class="form-group" style="margin-bottom:20px;">
                    <label style="display:block; margin-bottom:8px; font-weight:500;"><?php _e('评分', 'qilingshop'); ?></label>
                    <div class="qls-star-rating" id="star-rating">
                        <span class="star" data-value="1">★</span>
                        <span class="star" data-value="2">★</span>
                        <span class="star" data-value="3">★</span>
                        <span class="star" data-value="4">★</span>
                        <span class="star" data-value="5">★</span>
                        <span class="rating-text" style="margin-left:10px; color:#999;"></span>
                    </div>
                    <input type="hidden" id="review-rating" name="rating" value="5">
                </div>
                
                <div class="form-group" style="margin-bottom:20px;">
                    <label style="display:block; margin-bottom:8px; font-weight:500;"><?php _e('评价内容', 'qilingshop'); ?></label>
                    <textarea id="review-content" name="content" rows="4" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px; resize:vertical;" 
                              placeholder="<?php printf(esc_attr__('请输入评价内容（至少%d字）', 'qilingshop'), get_option('qls_shop_review_min_length', 10)); ?>"></textarea>
                    <div style="text-align:right; font-size:12px; color:#999;">
                        <span id="content-count">0</span> / <?php echo get_option('qls_shop_review_min_length', 10); ?><?php _e('字', 'qilingshop'); ?>
                    </div>
                </div>
                
                <div class="form-group" style="margin-bottom:20px;">
                    <label style="display:block; margin-bottom:8px; font-weight:500;"><?php _e('上传图片', 'qilingshop'); ?></label>
                    <div id="review-images" style="display:flex; flex-wrap:wrap; gap:10px;">
                        <div class="upload-btn" id="add-image-btn" style="width:80px; height:80px; border:2px dashed #ddd; border-radius:4px; display:flex; align-items:center; justify-content:center; cursor:pointer; color:#999;">
                            <span class="dashicons dashicons-plus-alt" style="font-size:24px;"></span>
                        </div>
                    </div>
                    <input type="file" id="image-input" accept="image/jpeg,image/png" style="display:none;" multiple>
                    <p style="margin:8px 0 0; font-size:12px; color:#999;">
                        <?php printf(__('最多上传%d张图片，单张不超过2MB，仅支持JPG/PNG格式', 'qilingshop'), get_option('qls_shop_review_image_max', 9)); ?>
                    </p>
                </div>
                
                <div class="form-group" style="margin-bottom:20px;">
                    <label class="qls-checkbox-label">
                        <input type="checkbox" id="review-anonymous" name="is_anonymous" value="1">
                        <span><?php _e('匿名评价', 'qilingshop'); ?></span>
                    </label>
                </div>
                
                <div style="display:flex; justify-content:space-between; align-items:center; padding-top:15px; border-top:1px solid #eee;">
                    <span style="color:#f90; font-size:14px;">
                        <?php 
                        $base_points = get_option('qls_shop_review_points_reward', 10);
                        $image_bonus = get_option('qls_shop_review_image_bonus', 5);
                        printf(__('评价可获得 %d 积分，带图额外 +%d 积分', 'qilingshop'), $base_points, $image_bonus);
                        ?>
                    </span>
                    <button type="submit" class="qls-btn qls-btn-primary" id="submit-review-btn">
                        <?php _e('提交评价', 'qilingshop'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 查看已评价弹窗 -->
<div id="qls-view-review-modal" class="qls-modal" style="display:none;">
    <div class="qls-modal-content" style="max-width: 550px; max-height: 90vh; overflow-y: auto;">
        <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #eee; padding-bottom:15px; margin-bottom:20px;">
            <h3 style="margin:0;"><?php _e('我的评价', 'qilingshop'); ?></h3>
            <span class="dashicons dashicons-no-alt qls-modal-close" style="cursor:pointer; font-size:24px;"></span>
        </div>
        
        <div id="my-reviews-list">
            <!-- 动态加载评价内容 -->
        </div>
    </div>
</div>

<style>
/* 评价星级 */
.qls-star-rating .star {
    font-size: 28px;
    color: #ddd;
    cursor: pointer;
    transition: color 0.2s;
}
.qls-star-rating .star.active,
.qls-star-rating .star:hover {
    color: #f90;
}
.qls-star-rating:hover .star {
    color: #ddd;
}
.qls-star-rating:hover .star:hover,
.qls-star-rating:hover .star:hover ~ .star {
    color: #ddd;
}
.qls-star-rating .star:hover ~ .star {
    color: #ddd !important;
}
.qls-star-rating:hover .star:first-child:hover ~ .star,
.qls-star-rating:hover .star:nth-child(2):hover ~ .star,
.qls-star-rating:hover .star:nth-child(3):hover ~ .star,
.qls-star-rating:hover .star:nth-child(4):hover ~ .star {
    color: #ddd !important;
}
/* 修复hover逻辑 */
.qls-star-rating .star { color: #ddd; }
.qls-star-rating[data-rating="1"] .star:nth-child(-n+1),
.qls-star-rating[data-rating="2"] .star:nth-child(-n+2),
.qls-star-rating[data-rating="3"] .star:nth-child(-n+3),
.qls-star-rating[data-rating="4"] .star:nth-child(-n+4),
.qls-star-rating[data-rating="5"] .star:nth-child(-n+5) {
    color: #f90;
}

/* 上传的图片预览 */
.review-image-preview {
    position: relative;
    width: 80px;
    height: 80px;
}
.review-image-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 4px;
}
.review-image-preview .remove-img {
    position: absolute;
    top: -8px;
    right: -8px;
    width: 20px;
    height: 20px;
    background: #d63638;
    color: #fff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 12px;
}
/* 待评价商品项 */
.review-item-card {
    display: flex;
    gap: 15px;
    padding: 15px;
    border: 1px solid #eee;
    border-radius: 8px;
    margin-bottom: 10px;
    cursor: pointer;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.review-item-card:hover {
    border-color: #2271b1;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
.review-item-card.reviewed {
    opacity: 0.6;
    cursor: not-allowed;
}
.review-item-card img {
    width: 60px;
    height: 60px;
    object-fit: cover;
    border-radius: 4px;
}
/* 匿名评价复选框 */
.qls-checkbox-label {
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    cursor: pointer;
    font-weight: normal;
}
.qls-checkbox-label input[type="checkbox"] {
    margin: 0 !important;
    width: auto !important;
}
.qls-checkbox-label span {
    line-height: 1;
}
/* 自定义Toast提示框 */
.qls-toast {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: rgba(0,0,0,0.85);
    color: #fff;
    padding: 20px 30px;
    border-radius: 12px;
    z-index: 999999;
    text-align: center;
    min-width: 200px;
    max-width: 80%;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    animation: qls-toast-in 0.3s ease;
}
.qls-toast.success { background: rgba(92,184,92,0.95); }
.qls-toast.error { background: rgba(217,83,79,0.95); }
.qls-toast.warning { background: rgba(240,173,78,0.95); }
.qls-toast-icon { font-size: 36px; margin-bottom: 10px; }
.qls-toast-message { font-size: 15px; line-height: 1.5; }
.qls-toast-points { margin-top: 8px; font-size: 18px; font-weight: 600; color: #ffd700; }
@keyframes qls-toast-in {
    from { opacity: 0; transform: translate(-50%, -50%) scale(0.8); }
    to { opacity: 1; transform: translate(-50%, -50%) scale(1); }
}
/* Lightbox 图片预览 */
.qls-lightbox {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.9);
    z-index: 999999;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: zoom-out;
}
.qls-lightbox img {
    max-width: 90%;
    max-height: 90%;
    border-radius: 4px;
    box-shadow: 0 4px 30px rgba(0,0,0,0.5);
}
.qls-lightbox-close {
    position: absolute;
    top: 20px;
    right: 30px;
    color: #fff;
    font-size: 36px;
    cursor: pointer;
    opacity: 0.8;
}
.qls-lightbox-close:hover { opacity: 1; }

/* 我的订单底部信息与操作区美化 */
.qls-orders-wrapper .order-footer {
    align-items: flex-end;
    gap: 14px 18px;
    flex-wrap: wrap;
}
.qls-orders-wrapper .order-footer .order-meta {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 240px;
    flex: 1;
}
.qls-orders-wrapper .order-footer .order-total {
    margin: 0;
    line-height: 1.4;
}
.qls-orders-wrapper .order-footer .order-logistics-status,
.qls-orders-wrapper .order-footer .order-refund-status,
.qls-orders-wrapper .order-footer .order-invoice-status {
    margin: 0;
    font-size: 13px;
    color: #6b7280;
    line-height: 1.5;
}
.qls-orders-wrapper .order-footer .order-logistics-status .logistics-extra {
    margin-left: 8px;
    color: #4b5563;
}
.qls-orders-wrapper .order-footer .order-logistics-status .qls-order-shipments {
    display: grid;
    gap: 3px;
    margin-top: 4px;
    margin-left: 0;
}
.qls-orders-wrapper .order-footer .order-logistics-status .logistics-no {
    margin-left: 8px;
}
.qls-orders-wrapper .order-footer .order-logistics-status .qls-order-shipments .logistics-no {
    margin-left: 0;
}
.qls-orders-wrapper .order-footer .order-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    align-items: center;
    gap: 10px;
}
.qls-orders-wrapper .order-footer .order-actions .qls-btn,
.qls-orders-wrapper .order-footer .order-actions a.qls-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: auto;
    height: 36px;
    padding: 0 14px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    line-height: 1;
    letter-spacing: 0;
    text-decoration: none;
    white-space: nowrap;
}
.qls-orders-wrapper .order-footer .order-actions .qls-btn-primary {
    min-width: auto;
    box-shadow: none;
}
.qls-orders-wrapper .order-footer .order-actions .qls-btn-outline {
    border-color: #d9dde5;
    color: #374151;
    background: #fff;
}
.qls-orders-wrapper .order-footer .order-actions .qls-btn-outline:hover {
    background: #f6f8fb;
    color: #111827;
    border-color: #cfd5df;
}
.qls-orders-wrapper .order-footer .order-actions .qls-view-my-review {
    border-color: #8acb8a;
    color: #2f7a2f;
}
.qls-orders-wrapper .order-footer .order-actions .qls-view-my-review .review-icon {
    font-size: 14px;
    width: 14px;
    height: 14px;
    color: currentColor;
}
.qls-orders-wrapper .order-footer .order-actions .qls-view-my-review:hover {
    background: #f2fbf2;
    color: #256325;
    border-color: #78be78;
}
@media (max-width: 768px) {
    .qls-orders-wrapper .order-footer {
        align-items: stretch;
    }
    .qls-orders-wrapper .order-footer .order-meta {
        width: 100%;
        min-width: 0;
    }
    .qls-orders-wrapper .order-footer .order-actions {
        width: 100%;
        justify-content: flex-start;
    }
}

/* 已评价按钮样式 */
.qls-btn-outline {
    background: transparent;
    border: 1px solid #5cb85c;
    color: #5cb85c;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 6px 12px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 13px;
    transition: all 0.2s;
}
.qls-btn-outline:hover {
    background: #5cb85c;
    color: #fff;
}
.qls-btn-outline:hover .dashicons { color: #fff !important; }

/* 查看评价弹窗内的评价卡片 */
.my-review-card {
    background: #f9f9f9;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 15px;
}
.my-review-card.hidden {
    background: #fff5f5;
    border: 1px solid #f5c6cb;
}
.my-review-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}
.my-review-rating { color: #f90; font-size: 16px; }
.my-review-date { color: #999; font-size: 12px; }
.my-review-content { margin-bottom: 12px; line-height: 1.6; }
.my-review-images { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
.my-review-images img { width: 60px; height: 60px; object-fit: cover; border-radius: 4px; cursor: pointer; }
.my-review-reply {
    background: #fff;
    border-left: 3px solid #2271b1;
    padding: 10px 12px;
    border-radius: 0 4px 4px 0;
    font-size: 13px;
}
.my-review-reply-label { color: #2271b1; font-weight: 500; margin-bottom: 5px; }
.my-review-hidden-notice {
    background: #d63638;
    color: #fff;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
}

/* ==================== 响应式适配 ==================== */
@media (max-width: 768px) {
    /* 评价弹窗响应式 */
    #qls-review-modal .qls-modal-content,
    #qls-view-review-modal .qls-modal-content {
        width: 100% !important;
        max-width: 100% !important;
        height: 100vh !important;
        max-height: 100vh !important;
        border-radius: 0 !important;
        margin: 0 !important;
    }
    
    /* 评价弹窗头部 */
    .modal-header {
        padding: 15px !important;
        margin: 0 !important;
        position: sticky;
        top: 0;
        background: #fff;
        z-index: 10;
    }
    .modal-header h3 { font-size: 16px !important; }
    
    /* 评价商品卡片 */
    .review-item-card {
        padding: 12px !important;
        gap: 10px !important;
    }
    .review-item-card img {
        width: 50px !important;
        height: 50px !important;
    }
    
    /* 评价表单区域 */
    #review-form-area {
        padding: 15px !important;
    }
    
    /* 星级评分 */
    .qls-star-rating .star {
        font-size: 24px !important;
    }
    
    /* 上传图片预览 */
    .review-image-preview,
    .review-image-preview img {
        width: 70px !important;
        height: 70px !important;
    }
    #add-image-btn {
        width: 70px !important;
        height: 70px !important;
    }
    
    /* 评价底部按钮区 */
    #review-form-area > div:last-child {
        flex-direction: column !important;
        gap: 12px !important;
    }
    #review-form-area > div:last-child span {
        text-align: center;
    }
    #submit-review-btn {
        width: 100% !important;
    }
    
    /* 查看评价 */
    .my-review-card {
        padding: 12px;
    }
    .my-review-images img {
        width: 50px !important;
        height: 50px !important;
    }
    
    /* Toast提示 */
    .qls-toast {
        min-width: 70% !important;
        padding: 15px 20px !important;
    }
    .qls-toast-icon { font-size: 28px !important; }
    .qls-toast-message { font-size: 14px !important; }
    
    /* Lightbox */
    .qls-lightbox img {
        max-width: 100% !important;
        max-height: 80% !important;
    }
    .qls-lightbox-close {
        top: 10px !important;
        right: 15px !important;
        font-size: 28px !important;
    }
}

@media (max-width: 480px) {
    .review-item-card img {
        width: 40px !important;
        height: 40px !important;
    }
    .qls-star-rating .star {
        font-size: 20px !important;
    }
    .review-image-preview,
    .review-image-preview img,
    #add-image-btn {
        width: 60px !important;
        height: 60px !important;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    var uploadedImages = [];
    var maxImages = <?php echo get_option('qls_shop_review_image_max', 9); ?>;
    var ratingTexts = {
        1: '<?php _e('非常差', 'qilingshop'); ?>',
        2: '<?php _e('差', 'qilingshop'); ?>',
        3: '<?php _e('一般', 'qilingshop'); ?>',
        4: '<?php _e('好', 'qilingshop'); ?>',
        5: '<?php _e('非常好', 'qilingshop'); ?>'
    };

    // 打开评价弹窗
    $('.qls-open-review').click(function() {
        var orderId = $(this).data('order-id');
        var orderNo = $(this).data('order-no');
        
        // 获取订单商品评价状态
        $.post(qlsShop.ajaxUrl || qls_shop_vars.ajax_url, {
            action: 'qls_get_order_review_status',
            order_id: orderId,
            nonce: qlsShop.nonce || qls_shop_vars.nonce
        }, function(response) {
            if (response.success) {
                var html = '';
                $.each(response.data.items, function(i, item) {
                    var reviewedClass = item.is_reviewed ? 'reviewed' : '';
                    var statusBadge = item.is_reviewed 
                        ? '<span style="color:#5cb85c; font-size:12px;"><?php _e('已评价', 'qilingshop'); ?></span>' 
                        : '<span style="color:#f90; font-size:12px;"><?php _e('待评价', 'qilingshop'); ?></span>';
                    
                    // 获取商品图片
                    var imgSrc = item.image || '';
                    
                    html += '<div class="review-item-card '+reviewedClass+'" data-item=\''+JSON.stringify(item)+'\'>';
                    html += '<img src="'+imgSrc+'" alt="" style="background:#f5f5f5;">';
                    html += '<div style="flex:1;">';
                    html += '<div style="font-weight:500;">'+item.product_title+'</div>';
                    if (item.sku_attrs && Object.keys(item.sku_attrs).length) {
                        var skuText = Object.values(item.sku_attrs).join(' / ');
                        html += '<div style="color:#999; font-size:13px;">'+skuText+'</div>';
                    }
                    html += '</div>';
                    html += statusBadge;
                    html += '</div>';
                });
                
                $('#review-items-list').html(html);
                $('#review-form-area').hide();
                $('#review-order-id').val(orderId);
                $('#qls-review-modal').fadeIn(200);
            }
        });
    });

    // 点击商品项开始评价
    $(document).on('click', '.review-item-card:not(.reviewed)', function() {
        var item = $(this).data('item');
        
        $('#review-item-id').val(item.order_item_id);
        $('#review-product-id').val(item.product_id);
        $('#review-sku-id').val(item.sku_id || 0);
        $('#review-product-title').text(item.product_title);
        
        // 设置商品图片
        $('#review-product-img').attr('src', item.image || '').css('background', '#f5f5f5');
        
        var skuInfo = '';
        if (item.sku_attrs && Object.keys(item.sku_attrs).length) {
            skuInfo = Object.values(item.sku_attrs).join(' / ');
        }
        $('#review-product-sku').text(skuInfo);
        $('#review-sku-info').val(skuInfo);
        
        // 重置表单
        $('#review-rating').val(5);
        $('#star-rating').attr('data-rating', 5);
        $('#star-rating .rating-text').text(ratingTexts[5]);
        $('#review-content').val('');
        $('#content-count').text('0');
        $('#review-anonymous').prop('checked', false);
        uploadedImages = [];
        $('#review-images .review-image-preview').remove();
        
        $('#review-items-list').hide();
        $('#review-form-area').show();
    });

    // 星级评分
    $('#star-rating .star').click(function() {
        var rating = $(this).data('value');
        $('#review-rating').val(rating);
        $('#star-rating').attr('data-rating', rating);
        $('#star-rating .rating-text').text(ratingTexts[rating]);
    });

    // 内容字数统计
    $('#review-content').on('input', function() {
        $('#content-count').text($(this).val().length);
    });

    // 图片上传
    $('#add-image-btn').click(function() {
        if (uploadedImages.length >= maxImages) {
            alert('<?php printf(__('最多上传%d张图片', 'qilingshop'), get_option('qls_shop_review_image_max', 9)); ?>');
            return;
        }
        $('#image-input').click();
    });

    $('#image-input').change(function() {
        var files = this.files;
        for (var i = 0; i < files.length && uploadedImages.length < maxImages; i++) {
            uploadImage(files[i]);
        }
        $(this).val('');
    });

    function uploadImage(file) {
        var orderItemId = $('#review-item-id').val();
        if (!orderItemId) {
            showToast('<?php _e('请先选择要评价的商品', 'qilingshop'); ?>', 'warning');
            return;
        }

        var formData = new FormData();
        formData.append('image', file);
        formData.append('order_item_id', orderItemId);
        formData.append('action', 'qls_upload_review_image');
        formData.append('nonce', qlsShop.nonce || qls_shop_vars.nonce);
        
        $.ajax({
            url: qlsShop.ajaxUrl || qls_shop_vars.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    uploadedImages.push(response.data.url);
                    var preview = '<div class="review-image-preview">';
                    preview += '<img src="'+response.data.url+'" alt="">';
                    preview += '<span class="remove-img" data-url="'+response.data.url+'">&times;</span>';
                    preview += '</div>';
                    $('#add-image-btn').before(preview);
                    
                    if (uploadedImages.length >= maxImages) {
                        $('#add-image-btn').hide();
                    }
                } else {
                    showToast(response.data.message || '<?php _e('上传失败', 'qilingshop'); ?>', 'error');
                }
            }
        });
    }

    // 删除图片
    $(document).on('click', '.remove-img', function() {
        var url = $(this).data('url');
        uploadedImages = uploadedImages.filter(function(u) { return u !== url; });
        $(this).parent().remove();
        $('#add-image-btn').show();
    });

    // 提交评价
    $('#review-form').submit(function(e) {
        e.preventDefault();
        
        var content = $('#review-content').val();
        var minLength = <?php echo get_option('qls_shop_review_min_length', 10); ?>;
        
        if (content.length < minLength) {
            showToast('<?php printf(__('评价内容至少%d字', 'qilingshop'), get_option('qls_shop_review_min_length', 10)); ?>', 'warning');
            return;
        }
        
        var $btn = $('#submit-review-btn');
        $btn.prop('disabled', true).text('<?php _e('提交中...', 'qilingshop'); ?>');
        
        $.post(qlsShop.ajaxUrl || qls_shop_vars.ajax_url, {
            action: 'qls_submit_review',
            order_id: $('#review-order-id').val(),
            order_item_id: $('#review-item-id').val(),
            product_id: $('#review-product-id').val(),
            sku_id: $('#review-sku-id').val(),
            sku_info: $('#review-sku-info').val(),
            rating: $('#review-rating').val(),
            content: content,
            images: uploadedImages,
            is_anonymous: $('#review-anonymous').is(':checked') ? 1 : 0,
            nonce: qlsShop.nonce || qls_shop_vars.nonce
        }, function(response) {
            $btn.prop('disabled', false).text('<?php _e('提交评价', 'qilingshop'); ?>');
            
            if (response.success) {
                var msg = response.data.message;
                var points = response.data.points_earned || 0;
                showSuccessToast(msg, points);
                setTimeout(function() { location.reload(); }, 2000);
            } else {
                showToast(response.data.message || '<?php _e('提交失败', 'qilingshop'); ?>', 'error');
            }
        });
    });

    // 关闭弹窗
    $('#qls-review-modal .qls-modal-close').click(function() {
        $('#qls-review-modal').fadeOut(200);
    });

    // 自定义Toast提示函数
    function showToast(message, type, duration) {
        type = type || 'info';
        duration = duration || 2500;
        var icon = type === 'success' ? '✓' : (type === 'error' ? '✗' : (type === 'warning' ? '⚠' : 'ⓘ'));
        var $toast = $('<div class="qls-toast '+type+'"><div class="qls-toast-icon">'+icon+'</div><div class="qls-toast-message">'+message+'</div></div>');
        $('body').append($toast);
        setTimeout(function() { $toast.fadeOut(300, function() { $(this).remove(); }); }, duration);
    }

    function showSuccessToast(message, points) {
        var html = '<div class="qls-toast success">';
        html += '<div class="qls-toast-icon">✓</div>';
        html += '<div class="qls-toast-message">'+message+'</div>';
        if (points > 0) {
            html += '<div class="qls-toast-points"><?php _e('获得积分:', 'qilingshop'); ?> +'+points+'</div>';
        }
        html += '</div>';
        var $toast = $(html);
        $('body').append($toast);
        setTimeout(function() { $toast.fadeOut(300, function() { $(this).remove(); }); }, 2500);
    }

    // Lightbox图片预览
    $(document).on('click', '.review-image-preview img, .my-review-images img', function() {
        var src = $(this).attr('src');
        var $lightbox = $('<div class="qls-lightbox"><span class="qls-lightbox-close">&times;</span><img src="'+src+'" alt=""></div>');
        $('body').append($lightbox);
        $lightbox.click(function() { $(this).remove(); });
    });

    // 查看已评价
    $('.qls-view-my-review').click(function() {
        var orderId = $(this).data('order-id');
        var $list = $('#my-reviews-list');
        $list.html('<div style="text-align:center; padding:30px; color:#999;"><?php _e('加载中...', 'qilingshop'); ?></div>');
        $('#qls-view-review-modal').fadeIn(200);
        
        $.post(qlsShop.ajaxUrl || qls_shop_vars.ajax_url, {
            action: 'qls_get_user_order_reviews',
            order_id: orderId,
            nonce: qlsShop.nonce || qls_shop_vars.nonce
        }, function(response) {
            if (response.success && response.data.reviews.length > 0) {
                var html = '';
                $.each(response.data.reviews, function(i, review) {
                    var cardClass = review.is_hidden ? 'my-review-card hidden' : 'my-review-card';
                    html += '<div class="'+cardClass+'">';
                    
                    // 头部
                    html += '<div class="my-review-header">';
                    html += '<div>';
                    html += '<span class="my-review-rating">' + '★'.repeat(review.rating) + '☆'.repeat(5 - review.rating) + '</span>';
                    html += ' <strong>' + (review.product_title || '') + '</strong>';
                    html += '</div>';
                    if (review.is_hidden) {
                        html += '<span class="my-review-hidden-notice"><?php _e('评价已隐藏', 'qilingshop'); ?></span>';
                    } else {
                        html += '<span class="my-review-date">' + review.created_at + '</span>';
                    }
                    html += '</div>';
                    
                    // 内容
                    html += '<div class="my-review-content">' + (review.content || '') + '</div>';
                    
                    // 图片
                    if (review.images && review.images.length > 0) {
                        html += '<div class="my-review-images">';
                        $.each(review.images, function(j, img) {
                            html += '<img src="'+img+'" alt="">';
                        });
                        html += '</div>';
                    }
                    
                    // 商家回复
                    if (review.admin_reply) {
                        html += '<div class="my-review-reply">';
                        html += '<div class="my-review-reply-label"><?php _e('商家回复:', 'qilingshop'); ?></div>';
                        html += review.admin_reply;
                        if (review.reply_time) {
                            html += '<div style="color:#999; font-size:11px; margin-top:5px;">' + review.reply_time + '</div>';
                        }
                        html += '</div>';
                    }
                    
                    html += '</div>';
                });
                $list.html(html);
            } else {
                $list.html('<div style="text-align:center; padding:30px; color:#999;"><?php _e('暂无评价记录', 'qilingshop'); ?></div>');
            }
        }).fail(function() {
            $list.html('<div style="text-align:center; padding:30px; color:#d63638;"><?php _e('加载失败，请重试', 'qilingshop'); ?></div>');
        });
    });

    // 关闭所有弹窗
    $('.qls-modal-close').click(function() {
        $(this).closest('.qls-modal').fadeOut(200);
    });
});
</script>
<?php endif; ?>

<?php qls_shop_public()->get_shop_footer(); ?>
