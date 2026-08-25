<?php
/**
 * 商城个人中心模板
 */
if (!defined('ABSPATH')) exit;

qls_shop_public()->get_shop_header(__('个人中心', 'qilingshop'));

$view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : 'dashboard';
$user_id = get_current_user_id();
$invoice_available = function_exists('qls_invoice') && class_exists('QLS_Invoice');
$invoice_enabled = get_option('qls_shop_invoice_enabled', true) && $invoice_available;
$invoice_titles = isset($invoice_titles) && is_array($invoice_titles) ? $invoice_titles : [];
if ($view === 'invoice' && $invoice_enabled && empty($invoice_titles)) {
    $invoice_titles = qls_invoice()->get_titles($user_id);
}

// 获取统计数据 (仅 Dashboard 需要)
if ($view == 'dashboard') {
    $paid_statuses = [
        QLS_Shop_Order::STATUS_PAID,
        QLS_Shop_Order::STATUS_SHIPPED,
        QLS_Shop_Order::STATUS_COMPLETED,
    ];
    if (!isset($total_orders)) {
        $total_orders = qls_shop_order()->get_user_orders_count($user_id, [
            'status' => $paid_statuses,
        ]);
    }
    if (!isset($total_spent)) {
        $db = QLS_Shop_Database::instance();
        $total_spent = $db->sum('orders', 'final_amount', [
            'user_id' => $user_id,
            'status'  => $paid_statuses,
        ]);
    }
}

// 获取地址数据 (Dashboard 和 Address 需要)
$addresses = isset($addresses) ? $addresses : [];
if (empty($addresses) || $view == 'address') {
    $db = QLS_Shop_Database::instance();
    $addresses = $db->get_results('user_addresses', [
        'where'   => ['user_id' => $user_id],
        'orderby' => 'is_default',
        'order'   => 'DESC',
    ]);
}
// 获取团购数据 (仅 Groups 需要)
if ($view == 'groups') {
    $page = isset($_GET['gpage']) ? max(1, intval($_GET['gpage'])) : 1;
    $status = isset($_GET['gstatus']) ? intval($_GET['gstatus']) : null;
    $per_page = 10;
    
    $groups = qls_group()->get_user_groups($user_id, [
        'status'   => $status,
        'per_page' => $per_page,
        'page'     => $page,
    ]);
    
    $total = qls_group()->get_user_groups_count($user_id, $status);
    $total_pages = ceil($total / $per_page);
    
    $counts = [
        'all'     => qls_group()->get_user_groups_count($user_id),
        'pending' => qls_group()->get_user_groups_count($user_id, 0),
        'success' => qls_group()->get_user_groups_count($user_id, 1),
        'failed'  => qls_group()->get_user_groups_count($user_id, 2),
    ];
}
?>

<div class="qls-account-page">
    <?php include QILINGSHOP_PATH . 'templates/shop/partials/account-header.php'; ?>
    
    <div class="qls-account-body">
        <div class="qls-container">
            <div class="qls-account-layout">
                <?php include QILINGSHOP_PATH . 'templates/shop/partials/account-sidebar.php'; ?>
                
                <main class="qls-account-main">
                    <?php if ($view == 'dashboard'): ?>
                        <!-- 概览视图 -->
                        <div class="qls-section-header">
                            <h3><?php _e('概览', 'qilingshop'); ?></h3>
                        </div>
                        
                        <div class="qls-center-stats">
                            <div class="qls-stat-card">
                                <span class="dashicons dashicons-cart"></span>
                                <div class="stat-info">
                                    <div class="stat-value"><?php echo intval($total_orders); ?></div>
                                    <div class="stat-label"><?php _e('总订单', 'qilingshop'); ?></div>
                                </div>
                            </div>
                            <div class="qls-stat-card">
                                <span class="dashicons dashicons-money-alt"></span>
                                <div class="stat-info">
                                    <div class="stat-value">¥<?php echo number_format($total_spent, 2); ?></div>
                                    <div class="stat-label"><?php _e('总消费', 'qilingshop'); ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- 最近的地址（只显示默认） -->
                        <?php if (!empty($addresses)): $addr = $addresses[0]; ?>
                        <div class="qls-dashboard-address">
                            <h4><?php _e('默认收货地址', 'qilingshop'); ?> <a href="?view=address" class="edit-link"><?php _e('管理', 'qilingshop'); ?></a></h4>
                            <div class="qls-address-card default">
                                <div class="card-info">
                                     <strong class="name"><?php echo esc_html($addr->name); ?></strong>
                                     <span class="phone"><?php echo esc_html($addr->phone); ?></span>
                                     <div class="address-text"><?php echo esc_html($addr->province . $addr->city . $addr->district . ' ' . $addr->address); ?></div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                    <?php elseif ($view == 'groups'): ?>
                        <!-- 我的拼团视图 -->
                        <div class="qls-section-header">
                            <h3><?php _e('我的拼团', 'qilingshop'); ?></h3>
                        </div>

                        <!-- 状态筛选 -->
                        <div class="qls-group-tabs qls-shop-center-group-tabs">
                            <a href="<?php echo esc_url(remove_query_arg(['gstatus', 'gpage'])); ?>" 
                               class="qls-tab qls-shop-center-group-tab <?php echo is_null($status) ? 'active' : ''; ?>">
                                <?php _e('全部', 'qilingshop'); ?> (<?php echo (int) $counts['all']; ?>)
                            </a>
                            <a href="<?php echo esc_url(add_query_arg('gstatus', 0)); ?>" 
                               class="qls-tab qls-shop-center-group-tab <?php echo $status === 0 ? 'active' : ''; ?>">
                                <?php _e('拼团中', 'qilingshop'); ?> (<?php echo (int) $counts['pending']; ?>)
                            </a>
                            <a href="<?php echo esc_url(add_query_arg('gstatus', 1)); ?>" 
                               class="qls-tab qls-shop-center-group-tab <?php echo $status === 1 ? 'active' : ''; ?>">
                                <?php _e('拼团成功', 'qilingshop'); ?> (<?php echo (int) $counts['success']; ?>)
                            </a>
                            <a href="<?php echo esc_url(add_query_arg('gstatus', 2)); ?>" 
                               class="qls-tab qls-shop-center-group-tab <?php echo $status === 2 ? 'active' : ''; ?>">
                                <?php _e('拼团失败', 'qilingshop'); ?> (<?php echo (int) $counts['failed']; ?>)
                            </a>
                        </div>

                        <?php if (empty($groups)): ?>
                        <div class="qls-empty-state qls-group-empty-state">
                            <span class="dashicons dashicons-groups qls-group-empty-icon"></span>
                            <p><?php _e('暂无拼团记录', 'qilingshop'); ?></p>
                            <a href="<?php echo esc_url(qls_group_public()->get_group_center_url()); ?>" class="qls-btn qls-btn-primary qls-group-empty-action">
                                <?php _e('去拼团', 'qilingshop'); ?>
                            </a>
                        </div>
                        <?php else: ?>
                        <div class="qls-group-list qls-shop-center-group-list">
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
                                    <div class="header-left">
                                        <span class="qls-status-badge <?php echo esc_attr(qls_group()->get_status_badge_class($group->status)); ?>">
                                            <?php echo esc_html($group->status_text); ?>
                                        </span>
                                        <?php if ($group->is_leader): ?>
                                        <span class="qls-leader-tag"><?php _e('团长', 'qilingshop'); ?></span>
                                        <?php endif; ?>
                                    </div>
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
                                        <span class="qls-countdown-mini qls-shop-center-countdown" data-seconds="<?php echo (int) $group->remain_seconds; ?>">
                                            <?php 
                                            $remain_seconds = (int) $group->remain_seconds;
                                            $hours = floor($remain_seconds / 3600);
                                            $mins = floor(($remain_seconds % 3600) / 60);
                                            echo esc_html(sprintf(__('剩余 %02d:%02d:%02d', 'qilingshop'), $hours, $mins, $remain_seconds % 60));
                                            ?>
                                        </span>
                                        <a href="<?php echo esc_url($group_url); ?>" class="qls-btn qls-btn-sm qls-btn-primary">
                                            <?php _e('邀请好友', 'qilingshop'); ?>
                                        </a>
                                        <?php else: ?>
                                        <span class="qls-expired"><?php _e('已过期，等待系统处理', 'qilingshop'); ?></span>
                                        <?php endif; ?>
                                    <?php elseif ($group->status == 1): ?>
                                        <?php $is_manual_success = get_option('_qls_group_manual_success_' . $group->id); ?>
                                        <span class="qls-success-text qls-shop-center-success-text">
                                            <?php echo $is_manual_success ? __('该团为平台授权为成功，商家即将发货', 'qilingshop') : __('该团已成功，商家即将发货', 'qilingshop'); ?>
                                        </span>
                                        <a href="<?php echo esc_url(qls_shop_public()->get_page_url('orders')); ?>" class="qls-btn qls-btn-sm">
                                            <?php _e('查看订单', 'qilingshop'); ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="qls-failed-text qls-shop-center-failed-text"><?php _e('已退款至余额', 'qilingshop'); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- 分页 -->
                        <?php if ($total_pages > 1): ?>
                        <div class="qls-pagination qls-shop-center-group-pagination">
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

                    <?php elseif ($view == 'invoice'): ?>
                        <!-- 发票信息管理视图 -->
                        <div class="qls-invoice-title-section">
                            <div class="qls-section-header">
                                <h3><?php _e('发票信息管理', 'qilingshop'); ?></h3>
                                <?php if ($invoice_enabled): ?>
                                <button type="button" class="qls-btn qls-btn-sm" id="qls-add-invoice-title"><?php _e('新增发票信息', 'qilingshop'); ?></button>
                                <?php endif; ?>
                            </div>

                            <?php if (!$invoice_enabled): ?>
                            <div class="qls-empty-state qls-invoice-title-empty">
                                <span class="dashicons dashicons-media-spreadsheet"></span>
                                <p><?php _e('发票功能暂未开启', 'qilingshop'); ?></p>
                            </div>
                            <?php else: ?>
                            <div class="qls-invoice-title-list">
                                <?php if (empty($invoice_titles)): ?>
                                <div class="qls-empty-state qls-invoice-title-empty">
                                    <span class="dashicons dashicons-media-spreadsheet"></span>
                                    <p><?php _e('暂无发票信息', 'qilingshop'); ?></p>
                                    <button type="button" class="qls-btn qls-btn-primary" id="qls-add-invoice-title-empty"><?php _e('新增发票信息', 'qilingshop'); ?></button>
                                </div>
                                <?php else: ?>
                                <?php foreach ($invoice_titles as $invoice_title): ?>
                                    <?php
                                    $title_json = wp_json_encode($invoice_title);
                                    $title_type_text = qls_invoice()->get_title_type_text($invoice_title->title_type ?? QLS_Invoice::TITLE_PERSONAL);
                                    ?>
                                    <div class="qls-invoice-title-card <?php echo !empty($invoice_title->is_default) ? 'default' : ''; ?>"
                                         data-id="<?php echo esc_attr($invoice_title->id); ?>"
                                         data-json='<?php echo esc_attr($title_json); ?>'>
                                        <div class="qls-invoice-title-main">
                                            <div class="qls-invoice-title-head">
                                                <strong><?php echo esc_html($invoice_title->title); ?></strong>
                                                <span class="qls-invoice-title-type"><?php echo esc_html($title_type_text); ?></span>
                                                <?php if (!empty($invoice_title->is_default)): ?>
                                                <span class="tag"><?php _e('默认', 'qilingshop'); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="qls-invoice-title-meta">
                                                <?php if (!empty($invoice_title->tax_no)): ?>
                                                <span><?php _e('税号:', 'qilingshop'); ?> <?php echo esc_html($invoice_title->tax_no); ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($invoice_title->email)): ?>
                                                <span><?php _e('邮箱:', 'qilingshop'); ?> <?php echo esc_html($invoice_title->email); ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($invoice_title->registered_phone)): ?>
                                                <span><?php _e('注册电话:', 'qilingshop'); ?> <?php echo esc_html($invoice_title->registered_phone); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($invoice_title->bank_name) || !empty($invoice_title->bank_account) || !empty($invoice_title->registered_address)): ?>
                                            <div class="qls-invoice-title-extra">
                                                <?php if (!empty($invoice_title->bank_name) || !empty($invoice_title->bank_account)): ?>
                                                <span><?php _e('开户信息:', 'qilingshop'); ?> <?php echo esc_html(trim(($invoice_title->bank_name ?? '') . ' ' . ($invoice_title->bank_account ?? ''))); ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($invoice_title->registered_address)): ?>
                                                <span><?php _e('注册地址:', 'qilingshop'); ?> <?php echo esc_html($invoice_title->registered_address); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="card-actions">
                                            <?php if (empty($invoice_title->is_default)): ?>
                                            <button type="button" class="qls-btn-text set-default-invoice-title"><?php _e('设为默认', 'qilingshop'); ?></button>
                                            <?php endif; ?>
                                            <button type="button" class="qls-btn-text edit-invoice-title"><?php _e('编辑', 'qilingshop'); ?></button>
                                            <button type="button" class="qls-btn-text delete-invoice-title"><?php _e('删除', 'qilingshop'); ?></button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <div id="qls-invoice-title-modal" class="qls-modal" style="display:none;">
                                <div class="qls-modal-content qls-invoice-title-modal-content">
                                    <button type="button" class="qls-modal-x qls-modal-close" aria-label="<?php esc_attr_e('关闭', 'qilingshop'); ?>">×</button>
                                    <h3><?php _e('编辑发票信息', 'qilingshop'); ?></h3>
                                    <form id="qls-center-invoice-title-form">
                                        <input type="hidden" name="title_id" value="">

                                        <div class="qls-invoice-radio-group">
                                            <label><input type="radio" name="title_type" value="personal" checked> <?php _e('个人抬头', 'qilingshop'); ?></label>
                                            <label><input type="radio" name="title_type" value="company"> <?php _e('企业抬头', 'qilingshop'); ?></label>
                                        </div>

                                        <label class="qls-invoice-field">
                                            <span><?php _e('发票抬头', 'qilingshop'); ?></span>
                                            <input type="text" name="title" value="" required placeholder="<?php esc_attr_e('请输入发票抬头', 'qilingshop'); ?>">
                                        </label>
                                        <label class="qls-invoice-field qls-invoice-title-company-field">
                                            <span><?php _e('企业税号', 'qilingshop'); ?></span>
                                            <input type="text" name="tax_no" value="" placeholder="<?php esc_attr_e('企业抬头必填', 'qilingshop'); ?>">
                                        </label>
                                        <label class="qls-invoice-field">
                                            <span><?php _e('接收邮箱', 'qilingshop'); ?></span>
                                            <input type="email" name="email" value="" placeholder="<?php esc_attr_e('用于接收电子发票，可选', 'qilingshop'); ?>">
                                        </label>

                                        <div class="qls-invoice-title-company-fields">
                                            <div class="form-row two-col">
                                                <label class="qls-invoice-field">
                                                    <span><?php _e('开户银行', 'qilingshop'); ?></span>
                                                    <input type="text" name="bank_name" value="">
                                                </label>
                                                <label class="qls-invoice-field">
                                                    <span><?php _e('银行账号', 'qilingshop'); ?></span>
                                                    <input type="text" name="bank_account" value="">
                                                </label>
                                            </div>
                                            <label class="qls-invoice-field">
                                                <span><?php _e('注册地址', 'qilingshop'); ?></span>
                                                <input type="text" name="registered_address" value="">
                                            </label>
                                            <label class="qls-invoice-field">
                                                <span><?php _e('注册电话', 'qilingshop'); ?></span>
                                                <input type="text" name="registered_phone" value="">
                                            </label>
                                        </div>

                                        <div class="form-group check-group">
                                            <label>
                                                <input type="checkbox" name="is_default" value="1">
                                                <?php _e('设为默认发票信息', 'qilingshop'); ?>
                                            </label>
                                        </div>
                                        <div class="form-actions qls-invoice-modal-actions">
                                            <button type="button" class="qls-btn qls-btn-secondary qls-modal-cancel"><?php _e('取消', 'qilingshop'); ?></button>
                                            <button type="submit" class="qls-btn qls-btn-primary"><?php _e('保存发票信息', 'qilingshop'); ?></button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                    <?php elseif ($view == 'address'): ?>
                        <!-- 地址管理视图 -->
                        <div class="qls-address-section">
                            <div class="qls-section-header">
                                <h3><?php _e('收货地址管理', 'qilingshop'); ?></h3>
                                <button type="button" class="qls-btn qls-btn-sm" id="qls-add-address-center"><?php _e('新增地址', 'qilingshop'); ?></button>
                            </div>
                            
                            <div class="qls-address-list">
                                <?php if (empty($addresses)): ?>
                                <p class="no-address"><?php _e('暂无收货地址', 'qilingshop'); ?></p>
                                <?php else: ?>
                                <?php foreach ($addresses as $address): ?>
                                <div class="qls-address-card <?php echo $address->is_default ? 'default' : ''; ?>" data-id="<?php echo $address->id; ?>" data-json='<?php echo esc_attr(json_encode($address)); ?>'>
                                    <div class="card-info">
                                        <div class="info-row">
                                            <strong class="name"><?php echo esc_html($address->name); ?></strong>
                                            <span class="phone"><?php echo esc_html($address->phone); ?></span>
                                            <?php if ($address->is_default): ?>
                                            <span class="tag"><?php _e('默认', 'qilingshop'); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="info-address">
                                            <?php echo esc_html($address->province . $address->city . $address->district . ' ' . $address->address); ?>
                                        </div>
                                    </div>
                                    <div class="card-actions">
                                        <button type="button" class="qls-btn-text edit-address"><?php _e('编辑', 'qilingshop'); ?></button>
                                        <button type="button" class="qls-btn-text delete-address"><?php _e('删除', 'qilingshop'); ?></button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            
                            <!-- 地址表单 (复用原有逻辑) -->
                            <div id="qls-address-modal" class="qls-modal" style="display:none;">
                                <div class="qls-modal-content">
                                    <h3><?php _e('编辑收货地址', 'qilingshop'); ?></h3>
                                    <form id="qls-center-address-form">
                                        <input type="hidden" name="address_id" value="">
                                        <div class="form-row two-col">
                                            <div class="form-group">
                                                <label><?php _e('收货人', 'qilingshop'); ?></label>
                                                <input type="text" name="name" required>
                                            </div>
                                            <div class="form-group">
                                                <label><?php _e('手机号', 'qilingshop'); ?></label>
                                                <input type="text" name="phone" required>
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <label><?php _e('收货地址', 'qilingshop'); ?></label>
                                            <textarea name="address" required rows="3" placeholder="<?php _e('请填写省/市/区及详细地址，例如：北京市朝阳区建国路88号', 'qilingshop'); ?>"></textarea>
                                        </div>
                                        <div class="form-group check-group">
                                            <label>
                                                <input type="checkbox" name="is_default" value="1">
                                                <?php _e('设为默认地址', 'qilingshop'); ?>
                                            </label>
                                        </div>
                                        <div class="form-actions">
                                            <button type="submit" class="qls-btn qls-btn-primary"><?php _e('保存', 'qilingshop'); ?></button>
                                            <button type="button" class="qls-btn qls-btn-secondary qls-modal-cancel"><?php _e('取消', 'qilingshop'); ?></button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            
                            <!-- 删除确认弹窗 -->
                            <div id="qls-confirm-modal" class="qls-modal" style="display:none;">
                                <div class="qls-modal-content small-modal">
                                    <h3><?php _e('确认操作', 'qilingshop'); ?></h3>
                                    <p class="confirm-message"><?php _e('确定要删除此收货地址吗？此操作无法撤销。', 'qilingshop'); ?></p>
                                    <div class="form-actions">
                                        <button type="button" class="qls-btn qls-btn-danger" id="qls-confirm-delete-btn"><?php _e('确认删除', 'qilingshop'); ?></button>
                                        <button type="button" class="qls-btn qls-btn-secondary qls-modal-cancel"><?php _e('取消', 'qilingshop'); ?></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </main>
            </div>
        </div>
    </div>
</div>

<?php qls_shop_public()->get_shop_footer(); ?>
