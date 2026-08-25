<?php
/**
 * 订单列表视图
 */
if (!defined('ABSPATH')) exit;

$current_status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
$current_keyword = isset($args['keyword']) ? (string) $args['keyword'] : '';
$has_filters = $current_status !== '' || $current_keyword !== '';
$order_statuses = [
    0 => __('待付款', 'qilingshop'),
    1 => __('已付款', 'qilingshop'),
    2 => __('已发货', 'qilingshop'),
    3 => __('已完成', 'qilingshop'),
    4 => __('已取消', 'qilingshop'),
    5 => __('退款中', 'qilingshop'),
    6 => __('已退款', 'qilingshop'),
];
?>
<div class="wrap qilingshop-admin-page qls-shop-wrap">
    <div class="qls-page-header">
        <div>
            <h1 class="qls-page-title"><?php _e('商城订单', 'qilingshop'); ?></h1>
            <p class="qls-page-intro"><?php _e('优先处理待发货、退款中和异常订单，支持按订单号、收货人、手机号搜索。', 'qilingshop'); ?></p>
        </div>
    </div>
    <?php settings_errors('qls_shop_orders'); ?>

    <div class="qls-admin-task-grid">
        <a class="qls-admin-task-card primary" href="<?php echo esc_url(admin_url('admin.php?page=qls-shop-orders&status=1')); ?>">
            <span><?php _e('待发货', 'qilingshop'); ?></span>
            <strong><?php echo (int) ($status_counts[1] ?? 0); ?></strong>
            <em><?php _e('优先处理', 'qilingshop'); ?></em>
        </a>
        <a class="qls-admin-task-card warning" href="<?php echo esc_url(admin_url('admin.php?page=qls-shop-orders&status=5')); ?>">
            <span><?php _e('退款中', 'qilingshop'); ?></span>
            <strong><?php echo (int) ($status_counts[5] ?? 0); ?></strong>
            <em><?php _e('需要售后跟进', 'qilingshop'); ?></em>
        </a>
        <a class="qls-admin-task-card" href="<?php echo esc_url(admin_url('admin.php?page=qls-shop-orders&status=0')); ?>">
            <span><?php _e('待付款', 'qilingshop'); ?></span>
            <strong><?php echo (int) ($status_counts[0] ?? 0); ?></strong>
            <em><?php _e('可清理超时订单', 'qilingshop'); ?></em>
        </a>
        <a class="qls-admin-task-card" href="<?php echo esc_url(admin_url('admin.php?page=qls-shop-orders')); ?>">
            <span><?php _e('全部订单', 'qilingshop'); ?></span>
            <strong><?php echo (int) array_sum($status_counts); ?></strong>
            <em><?php _e('查看完整记录', 'qilingshop'); ?></em>
        </a>
    </div>
    
    <!-- 状态筛选 -->
    <ul class="qls-chip-nav" aria-label="<?php esc_attr_e('订单状态筛选', 'qilingshop'); ?>">
        <li>
            <a href="<?php echo admin_url('admin.php?page=qls-shop-orders'); ?>" class="<?php echo $current_status === '' ? 'current' : ''; ?>">
                <?php _e('全部', 'qilingshop'); ?>
                <span class="count">(<?php echo array_sum($status_counts); ?>)</span>
            </a>
        </li>
        <?php foreach ($order_statuses as $status => $label): ?>
        <li>
            <a href="<?php echo admin_url('admin.php?page=qls-shop-orders&status=' . $status); ?>" class="<?php echo $current_status === (string)$status ? 'current' : ''; ?>">
                <?php echo esc_html($label); ?>
                <span class="count">(<?php echo $status_counts[$status] ?? 0; ?>)</span>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
    
    <div class="qls-toolbar qls-toolbar-between">
        <!-- 搜索 -->
        <form method="get" class="qls-search-form qls-toolbar-search">
            <input type="hidden" name="page" value="qls-shop-orders">
            <?php if ($current_status !== ''): ?>
            <input type="hidden" name="status" value="<?php echo esc_attr($current_status); ?>">
            <?php endif; ?>
            <input type="search" name="s" value="<?php echo esc_attr($current_keyword); ?>" placeholder="<?php esc_attr_e('搜索订单号、收货人、手机号...', 'qilingshop'); ?>">
            <button type="submit" class="button"><?php _e('搜索', 'qilingshop'); ?></button>
            <?php if ($has_filters): ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=qls-shop-orders')); ?>" class="button button-secondary"><?php _e('清除筛选', 'qilingshop'); ?></a>
            <?php endif; ?>
        </form>
        
        <div class="qls-toolbar-actions">
            <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('export', 'shop_pending_shipments', admin_url('admin.php?page=qls-shop-orders')), 'qls_shop_export_shipments')); ?>" class="button button-secondary"><?php _e('导出待发货单', 'qilingshop'); ?></a>
            <button type="button" class="button button-primary" id="qls-open-bulk-ship"><?php _e('批量导入发货', 'qilingshop'); ?></button>
            <form method="post" class="qls-inline-form" onsubmit="return confirm('<?php _e('确定要清理所有未付款订单吗？此操作无法撤销。', 'qilingshop'); ?>');">
                <?php wp_nonce_field('qls_order_action'); ?>
                <input type="hidden" name="order_action" value="cleanup_unpaid">
                <button type="submit" class="button button-secondary delete"><?php _e('一键清理未付款', 'qilingshop'); ?></button>
            </form>
            <form method="post" class="qls-inline-form" onsubmit="return confirm('<?php _e('确定要清理已取消订单吗？每次最多清理 500 条，此操作无法撤销。', 'qilingshop'); ?>');">
                <?php wp_nonce_field('qls_order_action'); ?>
                <input type="hidden" name="order_action" value="cleanup_cancelled">
                <button type="submit" class="button button-secondary delete"><?php _e('清理已取消', 'qilingshop'); ?></button>
            </form>
            <form method="post" class="qls-inline-form" onsubmit="return confirm('<?php _e('确定要清理已完成订单吗？订单记录删除后无法恢复，每次最多清理 500 条。', 'qilingshop'); ?>');">
                <?php wp_nonce_field('qls_order_action'); ?>
                <input type="hidden" name="order_action" value="cleanup_completed">
                <button type="submit" class="button button-secondary delete"><?php _e('清理已完成', 'qilingshop'); ?></button>
            </form>
        </div>
    </div>
    
    <!-- 订单列表 -->
    <div class="qls-table-shell">
    <table class="wp-list-table qls-ui-table widefat fixed striped qls-orders-table">
        <thead>
            <tr>
                <th class="column-order"><?php _e('订单', 'qilingshop'); ?></th>
                <th class="column-type"><?php _e('类型', 'qilingshop'); ?></th>
                <th class="column-products"><?php _e('商品', 'qilingshop'); ?></th>
                <th class="column-customer"><?php _e('买家', 'qilingshop'); ?></th>
                <th class="column-amount"><?php _e('金额', 'qilingshop'); ?></th>
                <th class="column-status"><?php _e('状态', 'qilingshop'); ?></th>
                <th class="column-date"><?php _e('日期', 'qilingshop'); ?></th>
                <th class="column-actions"><?php _e('操作', 'qilingshop'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($orders)): ?>
            <tr>
                <td colspan="8" class="no-items qls-empty-cell">
                    <div class="qls-empty-state-admin">
                        <strong><?php echo $has_filters ? esc_html__('没有找到匹配的订单', 'qilingshop') : esc_html__('还没有订单', 'qilingshop'); ?></strong>
                        <p><?php echo $has_filters ? esc_html__('可以换个关键词，或清除状态筛选后再查看。', 'qilingshop') : esc_html__('新订单产生后会出现在这里，待发货订单会优先提醒。', 'qilingshop'); ?></p>
                        <?php if ($has_filters): ?>
                        <div class="qls-empty-actions">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=qls-shop-orders')); ?>" class="button"><?php _e('清除筛选', 'qilingshop'); ?></a>
                        </div>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($orders as $order): ?>
            <tr data-order-no="<?php echo esc_attr($order->order_no); ?>" data-order-status="<?php echo (int) $order->status; ?>">
                <td class="column-order">
                    <div class="qls-order-no-line">
                        <strong>#<span id="qls-order-no-<?php echo (int) $order->id; ?>"><?php echo esc_html($order->order_no); ?></span></strong>
                        <button type="button" class="button-link qls-copy-btn qls-order-copy" data-target="qls-order-no-<?php echo (int) $order->id; ?>"><?php _e('复制', 'qilingshop'); ?></button>
                    </div>
                    <?php
                    $shipment_summary = is_array($order->shipment_summary ?? null) ? $order->shipment_summary : [];
                    $shipment_count = (int) ($shipment_summary['shipment_count'] ?? ($order->shipment_count ?? 0));
                    ?>
                    <?php if ($order->tracking_no || $shipment_count > 0): ?>
                    <div class="tracking-info">
                        <small>
                            <?php if ($shipment_count > 1): ?>
                                <?php printf(esc_html__('已发 %d 个包裹', 'qilingshop'), $shipment_count); ?>
                                <?php if ($order->tracking_no): ?> · <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($order->tracking_no): ?>
                                <?php echo esc_html($order->shipping_company); ?>: <?php echo esc_html($order->tracking_no); ?>
                            <?php endif; ?>
                        </small>
                    </div>
                    <?php endif; ?>
                </td>
                <td class="column-type">
                    <?php if (!empty($order->is_group_order)): ?>
                    <span class="qls-badge qls-badge-group"><?php _e('团购', 'qilingshop'); ?></span>
                    <?php else: ?>
                    <span class="qls-badge qls-badge-normal"><?php _e('普通', 'qilingshop'); ?></span>
                    <?php endif; ?>
                </td>
                <td class="column-products">
                    <?php foreach ($order->items as $item): ?>
                    <div class="order-item">
                        <?php if ($item->image): ?>
                        <img src="<?php echo esc_url($item->image); ?>" alt="" class="item-thumb">
                        <?php endif; ?>
                        <span class="item-title"><?php echo esc_html(mb_substr($item->product_title, 0, 20)); ?></span>
                        <span class="item-qty">×<?php echo esc_html($item->quantity); ?></span>
                    </div>
                    <?php endforeach; ?>
                </td>
                <td class="column-customer">
                    <?php
                    if ($order->user_id) {
                        $buyer_name = isset($order_user_map[(int) $order->user_id]) ? (string) $order_user_map[(int) $order->user_id] : '';
                        echo $buyer_name !== '' ? esc_html($buyer_name) : __('用户已删除', 'qilingshop');
                    } else {
                        _e('游客', 'qilingshop');
                    }
                    ?>
                    <div class="receiver-info">
                        <small><?php echo esc_html($order->receiver_name); ?> <?php echo esc_html($order->receiver_phone); ?></small>
                    </div>
                </td>
                <td class="column-amount">
                    <strong>¥<?php echo number_format($order->final_amount, 2); ?></strong>
                    <?php if ($order->shipping_fee > 0): ?>
                    <div><small><?php _e('含运费', 'qilingshop'); ?>: ¥<?php echo number_format($order->shipping_fee, 2); ?></small></div>
                    <?php endif; ?>
                </td>
                <td class="column-status">
                    <span class="status-badge <?php echo qls_shop_order()->get_status_badge_class($order->status); ?>">
                        <?php echo esc_html(qls_shop_order()->get_status_text($order->status)); ?>
                    </span>
                    <?php if ((int) ($order->shipment_status ?? 0) === 1): ?>
                    <div><small><?php _e('部分发货', 'qilingshop'); ?></small></div>
                    <?php endif; ?>
                    <?php if ((int) $order->status === 5): ?>
                    <?php if (!empty($order->refund_record) && function_exists('qls_shop_refund')): ?>
                    <div><small><?php echo esc_html(sprintf(__('售后：%s', 'qilingshop'), qls_shop_refund()->get_refund_status_text($order->refund_record))); ?></small></div>
                    <?php else: ?>
                    <div><small><?php _e('售后记录待同步', 'qilingshop'); ?></small></div>
                    <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td class="column-date">
                    <?php echo date('Y-m-d H:i', strtotime($order->created_at)); ?>
                </td>
                <td class="column-actions">
                    <a href="#" class="qls-view-order" data-order-id="<?php echo esc_attr($order->id); ?>"><?php _e('查看', 'qilingshop'); ?></a>
                    
                    <?php if ($order->status == 1): // 已付款，可发货 ?>
                    | <a href="#" class="qls-ship-order" data-order-id="<?php echo esc_attr($order->id); ?>"><?php _e('发货', 'qilingshop'); ?></a>
                    <?php endif; ?>
                    
                    <?php if ((int) $order->status === 5): // 退款中 ?>
                    <?php
                    $refund_action_text = __('去售后处理', 'qilingshop');
                    $refund_record = is_object($order->refund_record ?? null) ? $order->refund_record : null;
                    if ($refund_record) {
                        $refund_status_value = (int) ($refund_record->status ?? -1);
                        $return_required = !empty($refund_record->return_required);
                        if ($refund_status_value === 0) {
                            $refund_action_text = __('去审核', 'qilingshop');
                        } elseif ($refund_status_value === 5) {
                            $refund_action_text = __('去确认收货', 'qilingshop');
                        } elseif ((!$return_required && $refund_status_value === 1) || ($return_required && $refund_status_value === 6)) {
                            $refund_action_text = __('去确认退款', 'qilingshop');
                        }
                    }
                    ?>
                    | <a href="<?php echo esc_url(add_query_arg(['page' => 'qls-shop-refunds', 's' => $order->order_no], admin_url('admin.php'))); ?>"><?php echo esc_html($refund_action_text); ?></a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    
    <!-- 分页 -->
    <div class="qls-table-pagination tablenav bottom">
        <div class="tablenav-pages">
            <?php
            $total_pages = ceil($total / $args['limit']);
            $current_page = ($args['offset'] / $args['limit']) + 1;
            
            if ($total_pages > 1):
                echo paginate_links([
                    'base'      => add_query_arg('paged', '%#%'),
                    'format'    => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'total'     => $total_pages,
                    'current'   => $current_page,
                ]);
            endif;
            ?>
        </div>
    </div>
    </div>
</div>

<!-- 批量导入发货弹窗 -->
<div id="qls-bulk-ship-modal" class="qls-modal qls-hidden" aria-hidden="true">
    <div class="qls-modal-content large qls-bulk-ship-modal-content">
        <div class="qls-modal-header">
            <div>
                <h3><?php _e('批量导入发货', 'qilingshop'); ?></h3>
                <p class="qls-modal-subtitle"><?php _e('适合从表格或快递助手复制物流信息，系统会逐行校验，只处理待发货订单。', 'qilingshop'); ?></p>
            </div>
            <button type="button" class="qls-modal-close dashicons dashicons-no-alt"></button>
        </div>
        <form method="post" id="qls-bulk-ship-form">
            <?php wp_nonce_field('qls_order_action'); ?>
            <input type="hidden" name="order_action" value="bulk_ship">

            <div class="qls-bulk-ship-guide">
                <strong><?php _e('每行一单，按下面格式填写：', 'qilingshop'); ?></strong>
                <code><?php echo esc_html__('订单号,物流公司,快递单号', 'qilingshop'); ?></code>
                <span><?php _e('半角逗号、中文逗号或制表符都可以。物流公司和快递单号不能为空，复制表格时首行表头会自动跳过。', 'qilingshop'); ?></span>
                <span><?php _e('可以先导出待发货单，填好物流公司和快递单号后，把前三列复制到这里。', 'qilingshop'); ?></span>
            </div>

            <textarea
                name="bulk_ship_rows"
                id="qls-bulk-ship-rows"
                class="widefat qls-bulk-ship-textarea"
                rows="12"
                placeholder="<?php echo esc_attr__("QLS202604160001,顺丰速运,SF123456789\nQLS202604160002,圆通速递,YT987654321", 'qilingshop'); ?>"></textarea>

            <div class="qls-bulk-ship-tools">
                <button type="button" class="button" id="qls-fill-paid-orders-template"><?php _e('填入本页待发货订单号', 'qilingshop'); ?></button>
                <span><?php _e('会生成订单号模板，请再填写物流公司和快递单号。', 'qilingshop'); ?></span>
            </div>

            <p class="submit qls-modal-actions">
                <button type="submit" class="button button-primary"><?php _e('开始发货', 'qilingshop'); ?></button>
                <button type="button" class="button qls-modal-close"><?php _e('取消', 'qilingshop'); ?></button>
            </p>
        </form>
    </div>
</div>

<!-- 发货弹窗 -->
<?php
$waybill_enabled = function_exists('qls_waybill') && (int) get_option('qls_shop_waybill_enabled', 1) === 1;
$waybill_auto_generate = (int) get_option('qls_shop_waybill_auto_generate', 0) === 1;
$waybill_templates_for_ship = $waybill_enabled ? qls_waybill()->get_templates(['status' => 1, 'limit' => -1]) : [];
?>
<div id="qls-ship-modal" class="qls-modal qls-hidden">
    <div class="qls-modal-content large">
        <h3><?php _e('订单发货', 'qilingshop'); ?></h3>
        <form method="post" id="qls-ship-form">
            <?php wp_nonce_field('qls_order_action'); ?>
            <input type="hidden" name="order_action" value="ship">
            <input type="hidden" name="shipment_mode" value="split">
            <input type="hidden" name="order_id" id="ship-order-id" value="">
            <p class="description" id="ship-order-tip"><?php _e('可选择本次要发出的商品和数量；不拆单时保持默认全选即可。', 'qilingshop'); ?></p>
            
            <p>
                <label><?php _e('物流公司', 'qilingshop'); ?></label>
                <select name="shipping_company" required>
                    <option value=""><?php _e('请选择', 'qilingshop'); ?></option>
                    <?php 
                    $companies = qls_shop_order()->get_shipping_companies();
                    foreach ($companies as $company): 
                    ?>
                    <option value="<?php echo esc_attr($company['name']); ?>"><?php echo esc_html($company['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p>
                <label><?php _e('物流单号', 'qilingshop'); ?></label>
                <input type="text" name="tracking_no">
                <?php if ($waybill_enabled): ?>
                <span class="description"><?php _e('生成电子面单时可留空，系统会自动生成面单号。', 'qilingshop'); ?></span>
                <?php endif; ?>
            </p>
            <?php if ($waybill_enabled): ?>
            <div class="qls-box qls-waybill-ship-box">
                <label>
                    <input type="checkbox" name="create_waybill" id="ship-create-waybill" value="1" <?php checked($waybill_auto_generate); ?>>
                    <?php _e('生成电子面单', 'qilingshop'); ?>
                </label>
                <select name="waybill_template_id">
                    <option value="0"><?php _e('使用默认模板', 'qilingshop'); ?></option>
                    <?php foreach ($waybill_templates_for_ship as $template): ?>
                    <option value="<?php echo esc_attr($template->id); ?>" <?php selected(!empty($template->is_default)); ?>>
                        <?php echo esc_html($template->name); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($waybill_templates_for_ship)): ?>
                <p class="description"><?php _e('还没有启用的电子面单模板，请先到“快递物流”设置中添加模板。', 'qilingshop'); ?></p>
                <?php else: ?>
                <p class="description"><?php _e('勾选后会在发货成功时生成面单日志，并可在订单详情中打印。', 'qilingshop'); ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="qls-box" id="ship-items-box">
                <h4><?php _e('本次发货商品', 'qilingshop'); ?></h4>
                <div id="ship-items-loading"><?php _e('正在读取可发商品...', 'qilingshop'); ?></div>
                <div id="ship-items-list"></div>
            </div>
            <p class="submit">
                <button type="submit" class="button button-primary"><?php _e('确认发货', 'qilingshop'); ?></button>
                <button type="button" class="button qls-modal-close"><?php _e('取消', 'qilingshop'); ?></button>
            </p>
        </form>
    </div>
</div>

<!-- 订单详情弹窗 -->
<div id="qls-detail-modal" class="qls-modal qls-hidden">
    <div class="qls-modal-content large">
        <div class="qls-modal-header">
            <h3><?php _e('订单详情', 'qilingshop'); ?> <span id="detail-order-no"></span></h3>
            <button type="button" class="qls-modal-close dashicons dashicons-no-alt"></button>
        </div>
        
        <div class="qls-modal-body" id="detail-loading">
            <p class="qls-loading-note"><span class="spinner is-active qls-spinner-inline"></span> <?php _e('加载中...', 'qilingshop'); ?></p>
        </div>

        <div class="qls-modal-body qls-hidden" id="detail-content">
            <div class="qls-order-grid">
                <!-- 基本信息 -->
                <div class="qls-box">
                    <h4><?php _e('基本信息', 'qilingshop'); ?></h4>
                    <table class="qls-info-table">
                        <tr><th><?php _e('订单状态:', 'qilingshop'); ?></th><td><span id="d-status"></span></td></tr>
                        <tr><th><?php _e('订单总额:', 'qilingshop'); ?></th><td><span id="d-total"></span></td></tr>
                        <tr><th><?php _e('支付方式:', 'qilingshop'); ?></th><td><span id="d-payment"></span></td></tr>
                        <tr><th><?php _e('下单时间:', 'qilingshop'); ?></th><td><span id="d-created"></span></td></tr>
                        <tr><th><?php _e('支付时间:', 'qilingshop'); ?></th><td><span id="d-paid"></span></td></tr>
                        <tr><th><?php _e('发货时间:', 'qilingshop'); ?></th><td><span id="d-shipped"></span></td></tr>
                    </table>
                </div>

                <!-- 收货信息 -->
                <div class="qls-box">
                    <div class="qls-box-header">
                        <h4><?php _e('收货信息', 'qilingshop'); ?></h4>
                        <button type="button" class="button button-small" id="btn-edit-receiver"><?php _e('修改', 'qilingshop'); ?></button>
                    </div>
                    
                    <!-- Display Mode -->
                    <div id="receiver-display">
                        <table class="qls-info-table">
                            <tr><th><?php _e('收货人:', 'qilingshop'); ?></th><td><span id="r-name"></span></td></tr>
                            <tr><th><?php _e('联系电话:', 'qilingshop'); ?></th><td><span id="r-phone"></span></td></tr>
                            <tr><th><?php _e('所在地区:', 'qilingshop'); ?></th><td><span id="r-region"></span></td></tr>
                            <tr><th><?php _e('详细地址:', 'qilingshop'); ?></th><td><span id="r-address"></span></td></tr>
                            <tr><th><?php _e('买家备注:', 'qilingshop'); ?></th><td><span id="r-remark"></span></td></tr>
                        </table>
                    </div>

                    <!-- Edit Mode -->
                    <form id="receiver-edit-form" class="qls-hidden">
                        <input type="hidden" name="order_id" id="edit-order-id">
                        <p>
                            <label><?php _e('收货人', 'qilingshop'); ?></label>
                            <input type="text" name="receiver_name" id="edit-name" class="widefat">
                        </p>
                        <p>
                            <label><?php _e('联系电话', 'qilingshop'); ?></label>
                            <input type="text" name="receiver_phone" id="edit-phone" class="widefat">
                        </p>
                        <div class="qls-inline-flex-gap-5">
                            <input type="text" name="receiver_province" id="edit-province" class="widefat" placeholder="<?php esc_attr_e('省', 'qilingshop'); ?>">
                            <input type="text" name="receiver_city" id="edit-city" class="widefat" placeholder="<?php esc_attr_e('市', 'qilingshop'); ?>">
                            <input type="text" name="receiver_district" id="edit-district" class="widefat" placeholder="<?php esc_attr_e('区', 'qilingshop'); ?>">
                        </div>
                        <p>
                            <label><?php _e('详细地址', 'qilingshop'); ?></label>
                            <input type="text" name="receiver_address" id="edit-address" class="widefat">
                        </p>
                        <p>
                            <label><?php _e('备注', 'qilingshop'); ?></label>
                            <textarea name="buyer_remark" id="edit-remark" class="widefat" rows="2"></textarea>
                        </p>
                        <p class="qls-text-right">
                            <button type="button" class="button button-primary" id="btn-save-receiver"><?php _e('保存', 'qilingshop'); ?></button>
                            <button type="button" class="button" id="btn-cancel-edit"><?php _e('取消', 'qilingshop'); ?></button>
                        </p>
                    </form>
                </div>
            </div>

            <!-- 商品信息 -->
            <div class="qls-box top-margin">
                <h4><?php _e('商品信息', 'qilingshop'); ?></h4>
                <table class="wp-list-table qls-ui-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th class="qls-w-60"><?php _e('图片', 'qilingshop'); ?></th>
                            <th><?php _e('商品名称', 'qilingshop'); ?></th>
                            <th><?php _e('规格', 'qilingshop'); ?></th>
                            <th><?php _e('单价', 'qilingshop'); ?></th>
                            <th><?php _e('数量', 'qilingshop'); ?></th>
                            <th><?php _e('小计', 'qilingshop'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="detail-items">
                        <!-- Items rendered via JS -->
                    </tbody>
                </table>
            </div>
            
            <!-- 物流跟踪 -->
            <div class="qls-box top-margin qls-hidden" id="logistics-box">
                <h4><?php _e('物流信息', 'qilingshop'); ?></h4>
                <p><?php _e('物流公司：', 'qilingshop'); ?> <span id="l-company"></span></p>
                <p><?php _e('物流单号：', 'qilingshop'); ?> <span id="l-tracking-no"></span></p>
            </div>

        </div>
    </div>
</div>

<script>
jQuery(function($) {
    function showModal($modal) {
        $modal.removeClass('qls-hidden').css('display', 'flex').hide().fadeIn(200, function() {
            $(this).css('display', 'flex');
        });
    }

    function hideModal($modal) {
        $modal.fadeOut(200, function() {
            $(this).addClass('qls-hidden').css('display', '');
        });
    }

    // 发货弹窗
    $('.qls-ship-order').on('click', function(e) {
        e.preventDefault();
        var orderId = $(this).data('order-id');
        $('#ship-order-id').val(orderId);
        $('#ship-items-loading').show().text('<?php echo esc_js(__('正在读取可发商品...', 'qilingshop')); ?>');
        $('#ship-items-list').empty();
        showModal($('#qls-ship-modal'));

        $.ajax({
            url: qlsShopAdmin.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'qls_shop_get_order_details',
                order_id: orderId,
                nonce: qlsShopAdmin.nonce
            },
            success: function(response) {
                $('#ship-items-loading').hide();
                if (!response.success) {
                    $('#ship-items-list').html('<p class="description"><?php echo esc_js(__('订单明细读取失败，请关闭后重试。', 'qilingshop')); ?></p>');
                    return;
                }
                renderShipmentItems(response.data);
            },
            error: function() {
                $('#ship-items-loading').hide();
                $('#ship-items-list').html('<p class="description"><?php echo esc_js(__('订单明细读取失败，请关闭后重试。', 'qilingshop')); ?></p>');
            }
        });
    });
    
    // 关闭弹窗
    $('.qls-modal-close').on('click', function() {
        hideModal($(this).closest('.qls-modal'));
    });

    // --- 订单详情逻辑 ---
    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function renderShipmentItems(order) {
        var rows = [];
        if (order.items && order.items.length) {
            $.each(order.items, function(_, item) {
                var unshipped = parseInt(item.unshipped_quantity || 0, 10);
                var shipped = parseInt(item.shipped_quantity || 0, 10);
                var quantity = parseInt(item.quantity || 0, 10);
                if (item.is_virtual || unshipped <= 0) {
                    return;
                }

                var spec = '';
                if (item.sku_attrs) {
                    if ($.isArray(item.sku_attrs)) {
                        spec = item.sku_attrs.join(' / ');
                    } else {
                        var specParts = [];
                        $.each(item.sku_attrs, function(k, v) {
                            if (v !== null && v !== undefined && String(v) !== '') {
                                specParts.push(String(k) + ':' + String(v));
                            }
                        });
                        spec = specParts.join(' / ');
                    }
                }

                rows.push(
                    '<div class="qls-ship-item-row">' +
                        '<label class="qls-ship-item-main">' +
                            '<input type="checkbox" class="qls-ship-item-toggle" checked>' +
                            '<span>' +
                                '<strong>' + escapeHtml(item.product_title || '') + '</strong>' +
                                (spec ? '<em>' + escapeHtml(spec) + '</em>' : '') +
                                '<small><?php echo esc_js(__('已发', 'qilingshop')); ?> ' + shipped + ' / <?php echo esc_js(__('共', 'qilingshop')); ?> ' + quantity + '，<?php echo esc_js(__('可发', 'qilingshop')); ?> ' + unshipped + '</small>' +
                            '</span>' +
                        '</label>' +
                        '<input type="number" class="small-text qls-ship-qty" name="shipment_items[' + parseInt(item.id, 10) + ']" min="1" max="' + unshipped + '" value="' + unshipped + '">' +
                    '</div>'
                );
            });
        }

        if (!rows.length) {
            $('#ship-items-list').html('<p class="description"><?php echo esc_js(__('该订单没有可发的实体商品。', 'qilingshop')); ?></p>');
            return;
        }

        $('#ship-items-list').html(rows.join(''));
    }

    $(document).on('change', '.qls-ship-item-toggle', function() {
        $(this).closest('.qls-ship-item-row').find('.qls-ship-qty').prop('disabled', !this.checked);
    });

    $('#qls-ship-form').on('submit', function(e) {
        var createWaybill = $('#ship-create-waybill').length && $('#ship-create-waybill').is(':checked');
        var trackingNo = $.trim($('#qls-ship-form input[name="tracking_no"]').val() || '');
        if (!createWaybill && !trackingNo) {
            e.preventDefault();
            alert('<?php echo esc_js(__('请填写物流单号，或勾选生成电子面单。', 'qilingshop')); ?>');
            return;
        }

        var hasEnabledItem = $('#ship-items-list .qls-ship-qty:not(:disabled)').filter(function() {
            return parseInt($(this).val() || '0', 10) > 0;
        }).length > 0;

        if ($('#ship-items-list .qls-ship-item-row').length && !hasEnabledItem) {
            e.preventDefault();
            alert('<?php echo esc_js(__('请至少选择一个要发货的商品。', 'qilingshop')); ?>');
        }
    });
    
    // 打开详情
    $(document).on('click', '.qls-view-order', function(e) {
        e.preventDefault();
        var orderId = $(this).data('order-id');
        
        showModal($('#qls-detail-modal'));
        $('#detail-loading').show();
        $('#detail-content').hide();
        $('#receiver-display').show();
        $('#receiver-edit-form').hide();
        
        // AJAX 获取详情
        $.ajax({
            url: qlsShopAdmin.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'qls_shop_get_order_details',
                order_id: orderId,
                nonce: qlsShopAdmin.nonce
            },
            success: function(response) {
                if(response.success) {
                    var o = response.data;
                    
                    // 渲染基础信息
                    $('#detail-order-no').text(o.order_no);
                    $('#d-status').html('<span class="status-badge '+o.status_badge+'">'+o.status_text+'</span>');
                    $('#d-total').text('¥' + o.final_amount);
                    $('#d-payment').text(o.payment_method || '-');
                    $('#d-created').text(o.created_at_fmt);
                    $('#d-paid').text(o.paid_at_fmt);
                    $('#d-shipped').text(o.shipped_at_fmt);

                    // 渲染收货信息
                    fillReceiverInfo(o);

                    // 渲染商品明细
                    var itemsHtml = '';
                    if(o.items && o.items.length) {
                        $.each(o.items, function(i, item){
                            var specParts = [];
                            if (item.sku_attrs) {
                                if ($.isArray(item.sku_attrs)) {
                                    $.each(item.sku_attrs, function(_, v){
                                        if (v !== null && v !== undefined && String(v) !== '') {
                                            specParts.push(String(v));
                                        }
                                    });
                                } else {
                                    $.each(item.sku_attrs, function(k, v){
                                        if (v !== null && v !== undefined && String(v) !== '') {
                                            specParts.push(String(k) + ':' + String(v));
                                        }
                                    });
                                }
                            }
                            var spec = specParts.join(' / ');
                            var productTitle = escapeHtml(item.product_title || '');
                            var virtualHtml = item.virtual_content_display ? item.virtual_content_display : '';
                            
                            itemsHtml += '<tr>';
                            itemsHtml += '<td>' + (item.image ? '<img src="' + escapeHtml(item.image) + '" width="40" height="40">' : '') + '</td>';
                            itemsHtml += '<td><a href="#" target="_blank">' + productTitle + '</a>' + virtualHtml + '</td>';
                            itemsHtml += '<td>' + escapeHtml(spec || '-') + '</td>';
                            itemsHtml += '<td>¥' + escapeHtml(item.price) + '</td>';
                            itemsHtml += '<td>x' + escapeHtml(item.quantity);
                            if (!item.is_virtual) {
                                itemsHtml += '<br><small><?php echo esc_js(__('已发:', 'qilingshop')); ?> ' + escapeHtml(item.shipped_quantity || 0) + '</small>';
                            }
                            itemsHtml += '</td>';
                            itemsHtml += '<td>¥' + escapeHtml(item.total) + '</td>';
                            itemsHtml += '</tr>';
                        });
                    } else {
                        itemsHtml = '<tr><td colspan="6"><?php echo esc_js(__('暂无商品明细', 'qilingshop')); ?></td></tr>';
                    }
                    $('#detail-items').html(itemsHtml);
                    
                    // 渲染物流信息
                    $('#logistics-box .qls-timeline').remove();
                    $('#logistics-box .notice-error').remove();
                    $('#logistics-box .qls-shipment-list').remove();
                    if (o.shipments && o.shipments.length) {
                        $('#logistics-box').show();
                        $('#l-company').text(o.shipping_company || '-');
                        $('#l-tracking-no').text(o.tracking_no || '-');

                        var shipmentHtml = '<div class="qls-shipment-list">';
                        $.each(o.shipments, function(i, shipment) {
                            shipmentHtml += '<div class="qls-shipment-card">';
                            shipmentHtml += '<strong><?php echo esc_js(__('包裹', 'qilingshop')); ?> ' + (i + 1) + '</strong>';
                            shipmentHtml += '<p>' + escapeHtml(shipment.shipping_company || '-') + ' · ' + escapeHtml(shipment.tracking_no || shipment.waybill_no || '-') + '</p>';
                            if (shipment.waybill_print_url) {
                                shipmentHtml += '<p><a class="button button-small" target="_blank" rel="noopener" href="' + escapeHtml(shipment.waybill_print_url) + '"><?php echo esc_js(__('打印电子面单', 'qilingshop')); ?></a></p>';
                            } else {
                                shipmentHtml += '<p><button type="button" class="button button-small qls-generate-waybill" data-shipment-id="' + parseInt(shipment.id || 0, 10) + '"><?php echo esc_js(__('生成电子面单', 'qilingshop')); ?></button></p>';
                            }
                            if (shipment.items && shipment.items.length) {
                                shipmentHtml += '<ul>';
                                $.each(shipment.items, function(_, shipItem) {
                                    shipmentHtml += '<li>' + escapeHtml(shipItem.product_title || '') + ' × ' + escapeHtml(shipItem.quantity || 0) + '</li>';
                                });
                                shipmentHtml += '</ul>';
                            }
                            shipmentHtml += '</div>';
                        });
                        shipmentHtml += '</div>';
                        $('#logistics-box').append(shipmentHtml);

                        if (o.logistics_error) {
                            $('#logistics-box').append('<div class="notice notice-error inline"><p>'+escapeHtml(o.logistics_error)+'</p></div>');
                        } else if (o.logistics_trace && o.logistics_trace.logisticsTraceDetails) {
                            var timelineHtml = '<div class="qls-timeline">';
                            $.each(o.logistics_trace.logisticsTraceDetails, function(i, trace){
                                var time = new Date(trace.time).toLocaleString();
                                var activeClass = (i === 0) ? 'active' : '';
                                timelineHtml += '<div class="qls-timeline-item '+activeClass+'">';
                                timelineHtml += '<div class="qls-timeline-time">'+escapeHtml(time)+'</div>';
                                timelineHtml += '<div class="qls-timeline-desc">'+escapeHtml(trace.desc)+'</div>';
                                timelineHtml += '</div>';
                            });
                            timelineHtml += '</div>';
                            $('#logistics-box').append(timelineHtml);
                        }
                    } else if(o.status >= 2) {
                        $('#logistics-box').show();
                        $('#l-company').text(o.shipping_company || '-');
                        $('#l-tracking-no').text(o.tracking_no || '-');
                    } else {
                        $('#logistics-box').hide();
                    }

                    // Store current order obj for Edit usage
                    $('#qls-detail-modal').data('order', o);

                    $('#detail-loading').hide();
                    $('#detail-content').fadeIn();
                } else {
                    alert('Error: ' + (response.data || 'Unknown error'));
                    hideModal($('#qls-detail-modal'));
                }
            }
        });
    });

    $(document).on('click', '.qls-generate-waybill', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var shipmentId = parseInt($btn.data('shipment-id') || 0, 10);
        if (!shipmentId || $btn.data('loading')) {
            return;
        }

        $btn.data('loading', 1).prop('disabled', true).text('<?php echo esc_js(__('生成中...', 'qilingshop')); ?>');
        $.ajax({
            url: qlsShopAdmin.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'qls_shop_generate_waybill',
                shipment_id: shipmentId,
                nonce: qlsShopAdmin.nonce
            },
            success: function(response) {
                if (!response || !response.success) {
                    alert((response && response.data) ? response.data : '<?php echo esc_js(__('电子面单生成失败。', 'qilingshop')); ?>');
                    $btn.prop('disabled', false).text('<?php echo esc_js(__('生成电子面单', 'qilingshop')); ?>');
                    return;
                }

                var printUrl = response.data && response.data.print_url ? response.data.print_url : '';
                if (printUrl) {
                    $btn.replaceWith('<a class="button button-small" target="_blank" rel="noopener" href="' + escapeHtml(printUrl) + '"><?php echo esc_js(__('打印电子面单', 'qilingshop')); ?></a>');
                    window.open(printUrl, '_blank');
                } else {
                    $btn.prop('disabled', false).text('<?php echo esc_js(__('已生成电子面单', 'qilingshop')); ?>');
                }
            },
            error: function() {
                alert('<?php echo esc_js(__('电子面单生成失败，请稍后重试。', 'qilingshop')); ?>');
                $btn.prop('disabled', false).text('<?php echo esc_js(__('生成电子面单', 'qilingshop')); ?>');
            },
            complete: function() {
                $btn.data('loading', 0);
            }
        });
    });

    // 填充收货信息辅助函数
    function fillReceiverInfo(o) {
        $('#r-name').text(o.receiver_name);
        $('#r-phone').text(o.receiver_phone);
        $('#r-region').text((o.receiver_province||'')+' '+(o.receiver_city||'')+' '+(o.receiver_district||''));
        $('#r-address').text(o.receiver_address);
        $('#r-remark').text(o.buyer_remark || '-');
        
        // Fill Edit Form values
        $('#edit-order-id').val(o.id);
        $('#edit-name').val(o.receiver_name);
        $('#edit-phone').val(o.receiver_phone);
        $('#edit-province').val(o.receiver_province);
        $('#edit-city').val(o.receiver_city);
        $('#edit-district').val(o.receiver_district);
        $('#edit-address').val(o.receiver_address);
        $('#edit-remark').val(o.buyer_remark);
    }

    // 点击“修改”收货信息
    $('#btn-edit-receiver').on('click', function() {
        $('#receiver-display').hide();
        $('#receiver-edit-form').fadeIn();
        $(this).hide();
    });

    // 取消修改
    $('#btn-cancel-edit').on('click', function() {
        $('#receiver-edit-form').hide();
        $('#receiver-display').fadeIn();
        $('#btn-edit-receiver').show();
    });

    // 保存收货信息
    $('#btn-save-receiver').on('click', function() {
        var $btn = $(this);
        $btn.text('<?php _e('保存中...', 'qilingshop'); ?>').prop('disabled', true);
        
        var data = $('#receiver-edit-form').serialize();
        data += '&action=qls_shop_update_receiver_info&nonce=' + qlsShopAdmin.nonce;

        $.ajax({
            url: qlsShopAdmin.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: data,
            success: function(res) {
                if(res.success) {
                    // Update display view manually from form values
                    var updated = {
                        receiver_name: $('#edit-name').val(),
                        receiver_phone: $('#edit-phone').val(),
                        receiver_province: $('#edit-province').val(),
                        receiver_city: $('#edit-city').val(),
                        receiver_district: $('#edit-district').val(),
                        receiver_address: $('#edit-address').val(),
                        buyer_remark: $('#edit-remark').val(),
                        // keep others
                        id: $('#edit-order-id').val()
                    };
                    fillReceiverInfo(updated);
                    
                    // Switch back
                    $('#receiver-edit-form').hide();
                    $('#receiver-display').fadeIn();
                    $('#btn-edit-receiver').show();
                } else {
                    alert(res.data.message || 'Error');
                }
            },
            complete: function() {
                $btn.text('<?php _e('保存', 'qilingshop'); ?>').prop('disabled', false);
            }
        });
    });

});
</script>
