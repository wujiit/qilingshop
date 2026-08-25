<?php
/**
 * 结账页面模板
 */
if (!defined('ABSPATH')) exit;

// 加载优惠券资源（使用动态版本号）
$assets_version = function_exists('qilingshop_get_assets_version') ? qilingshop_get_assets_version() : QILINGSHOP_VERSION;
wp_enqueue_style('qls-coupon', QILINGSHOP_URL . 'static/css/coupon.css', [], $assets_version);
wp_enqueue_script('qls-coupon-selector', QILINGSHOP_URL . 'static/js/coupon-selector.js', [], $assets_version, true);
wp_localize_script('qls-coupon-selector', 'qlsCoupon', [
    'ajaxUrl' => admin_url('admin-ajax.php'),
    'nonce'   => wp_create_nonce('qls_coupon_nonce'),
    'currency' => '¥',
    'i18n' => qilingshop_get_coupon_selector_i18n(),
]);

$user_id = get_current_user_id();
$is_group_order = isset($_GET['type'], $_GET['order_no']) && in_array($_GET['type'], ['group', 'group_join'], true);
$group_order = null;
if ($is_group_order) {
    $order_no = sanitize_text_field(wp_unslash($_GET['order_no']));
    $group_order = qls_shop_order()->get_by_order_no($order_no, true);
    if (!$group_order || intval($group_order->user_id) !== $user_id || empty($group_order->is_group_order)) {
        $group_order = null;
        $is_group_order = false;
    }
}

if ($is_group_order) {
    $items = $group_order->items ?? [];
    $total_quantity = 0;
    foreach ($items as $item) {
        $total_quantity += intval($item->quantity ?? 0);
    }
    $totals = [
        'total_amount' => floatval($group_order->total_amount),
        'total_quantity' => $total_quantity,
    ];
    $shipping_fee = floatval($group_order->shipping_fee ?? 0);
    $discount_amount = floatval($group_order->discount_amount ?? 0);
    $final_amount = floatval($group_order->final_amount ?? 0);
} else {
    $items = qls_cart()->get_items();
    $totals = qls_cart()->get_totals();
    $shipping_fee = 0;
    $discount_amount = 0;
    $final_amount = $totals['total_amount'] ?? 0;
}

// 检测是否为纯虚拟商品订单
$is_virtual_only = true;
$has_virtual_card_item = false;
foreach ($items as $item) {
    $product_id = intval($item->product_id ?? 0);
    $product = isset($item->product) && is_object($item->product) ? $item->product : ($product_id ? qls_product()->get($product_id) : null);
    if (!$product || !qls_product()->is_virtual($product)) {
        $is_virtual_only = false;
        break;
    }

    if (sanitize_key((string) ($product->virtual_type ?? '')) === 'card') {
        $has_virtual_card_item = true;
    }
}

$show_guest_query_password = !$user_id
    && $is_virtual_only
    && $has_virtual_card_item
    && (bool) get_option('qls_shop_guest_query_password_enabled', false);

// 获取用户地址（仅实物商品需要）
$addresses = [];
if ($user_id && !$is_virtual_only) {
    $db = QLS_Shop_Database::instance();
    $addresses = $db->get_results('user_addresses', [
        'where'   => ['user_id' => $user_id],
        'orderby' => 'is_default',
        'order'   => 'DESC',
    ]);
}
$default_address = !empty($addresses) ? $addresses[0] : null;

// 获取用户积分
$user_points = 0;
if ($user_id && class_exists('QilingShop_Points')) {
    $user_points = QilingShop_Points::instance()->get_balance($user_id);
}

$points_enabled = get_option('qls_shop_points_enabled', true);
$points_rate = get_option('qls_shop_points_rate', 10);
qls_shop_public()->get_shop_header(__('结账', 'qilingshop'));
?>

<div class="qls-shop-wrapper qls-checkout-page">
<div class="qls-container">
<nav class="qls-breadcrumb">
    <a href="<?php echo esc_url(qls_shop_public()->get_shop_url()); ?>"><?php _e('商城', 'qilingshop'); ?></a>
    <span class="sep">›</span>
    <a href="<?php echo esc_url(qls_shop_public()->get_page_url('cart')); ?>"><?php _e('购物车', 'qilingshop'); ?></a>
    <span class="sep">›</span>
    <span class="current"><?php _e('结账', 'qilingshop'); ?></span>
</nav>


<div class="qls-checkout-wrapper">
    <?php if (empty($items)): ?>
    <div class="qls-notice warning">
        <?php if ($is_group_order): ?>
            <?php _e('订单不存在或已失效', 'qilingshop'); ?>
        <?php else: ?>
            <?php _e('购物车是空的', 'qilingshop'); ?>
            <a href="<?php echo esc_url(qls_shop_public()->get_shop_url()); ?>"><?php _e('去逛逛', 'qilingshop'); ?></a>
        <?php endif; ?>
    </div>
    <?php else: ?>
    
    <form id="qls-checkout-form" class="qls-checkout-form">
        <!-- 收货地址 -->
        <?php if ($is_virtual_only): ?>
        <!-- 虚拟商品只需联系信息 -->
        <input type="hidden" id="qls-is-virtual-only" value="1">
        <div class="qls-checkout-section qls-virtual-contact">
            <h3><?php _e('联系信息', 'qilingshop'); ?></h3>
            <div class="qls-notice success" style="margin-bottom: 20px;">
                <span class="dashicons dashicons-yes-alt" style="color: #46b450; margin-right: 8px;"></span>
                <?php _e('本次购买为虚拟商品，支付成功后将自动获取商品内容。请填写联系方式用于接收商品信息。', 'qilingshop'); ?>
            </div>
            <div class="qls-virtual-contact-form">
                <div class="form-row">
                    <div class="form-group">
                        <label><?php _e('联系人', 'qilingshop'); ?> <span class="required">*</span></label>
                        <input type="text" name="virtual_contact_name" id="virtual_contact_name" 
                               placeholder="<?php _e('请输入您的称呼', 'qilingshop'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label><?php _e('手机号码', 'qilingshop'); ?> <span class="required">*</span></label>
                        <input type="tel" name="virtual_contact_phone" id="virtual_contact_phone" 
                               placeholder="<?php _e('用于接收商品信息', 'qilingshop'); ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label><?php _e('邮箱地址', 'qilingshop'); ?></label>
                    <input type="email" name="virtual_contact_email" id="virtual_contact_email" 
                           placeholder="<?php _e('选填，用于接收电子商品', 'qilingshop'); ?>">
                    <p class="description"><?php _e('手机号和邮箱至少填写一项', 'qilingshop'); ?></p>
                </div>
                <?php if ($show_guest_query_password): ?>
                <div class="form-group">
                    <label><?php _e('订单查询密码', 'qilingshop'); ?> <span class="required">*</span></label>
                    <input type="password" name="guest_query_password" id="guest_query_password"
                           minlength="4" maxlength="64" autocomplete="new-password"
                           placeholder="<?php esc_attr_e('用于后续通过联系方式查询订单', 'qilingshop'); ?>" required>
                    <p class="description"><?php _e('支付后可在订单查询页用手机号/邮箱 + 查询密码查看订单和卡密。', 'qilingshop'); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="qls-checkout-section">
            <h3><?php _e('收货信息', 'qilingshop'); ?></h3>
            
            <?php if (!empty($addresses)): ?>
            <div class="qls-address-list" id="qls-address-list">
                <?php foreach ($addresses as $addr): ?>
                <label class="qls-address-item <?php echo $addr->is_default ? 'selected' : ''; ?>">
                    <input type="radio" name="address_id" value="<?php echo esc_attr($addr->id); ?>" <?php checked($addr->is_default); ?>>
                    <div class="address-content">
                        <span class="name"><?php echo esc_html($addr->name); ?></span>
                        <span class="phone"><?php echo esc_html($addr->phone); ?></span>
                        <?php if ($addr->is_default): ?>
                        <span class="default-tag"><?php _e('默认', 'qilingshop'); ?></span>
                        <?php endif; ?>
                        <p class="address">
                            <?php echo esc_html($addr->province . ' ' . $addr->city . ' ' . $addr->district . ' ' . $addr->address); ?>
                        </p>
                    </div>
                </label>
                <?php endforeach; ?>
            </div>
            <a href="#" id="qls-new-address-btn" class="qls-link"><?php _e('+ 使用新地址', 'qilingshop'); ?></a>
            <?php endif; ?>
            
            <div class="qls-address-form" id="qls-address-form" style="<?php echo !empty($addresses) ? 'display:none;' : ''; ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label><?php _e('收货人', 'qilingshop'); ?> <span class="required">*</span></label>
                        <input type="text" name="receiver_name" id="receiver_name" required value="<?php echo esc_attr($default_address->name ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label><?php _e('手机号', 'qilingshop'); ?> <span class="required">*</span></label>
                        <input type="tel" name="receiver_phone" id="receiver_phone" required value="<?php echo esc_attr($default_address->phone ?? ''); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label><?php _e('省份', 'qilingshop'); ?></label>
                        <input type="text" name="receiver_province" id="receiver_province" value="<?php echo esc_attr($default_address->province ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label><?php _e('城市', 'qilingshop'); ?></label>
                        <input type="text" name="receiver_city" id="receiver_city" value="<?php echo esc_attr($default_address->city ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label><?php _e('区县', 'qilingshop'); ?></label>
                        <input type="text" name="receiver_district" id="receiver_district" value="<?php echo esc_attr($default_address->district ?? ''); ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label><?php _e('详细地址', 'qilingshop'); ?> <span class="required">*</span></label>
                    <textarea name="receiver_address" id="receiver_address" rows="2" required><?php echo esc_textarea($default_address->address ?? ''); ?></textarea>
                </div>
                
                <?php if ($user_id): ?>
                <label class="save-address-check">
                    <input type="checkbox" name="save_address" value="1" checked>
                    <?php _e('保存为收货地址', 'qilingshop'); ?>
                </label>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- 订单商品 -->
        <div class="qls-checkout-section">
            <h3><?php _e('订单商品', 'qilingshop'); ?></h3>
            
            <div class="qls-order-items">
                <?php if ($is_group_order): ?>
                    <?php foreach ($items as $item): ?>
                    <div class="qls-order-item">
                        <?php 
                        $img = is_array($item->image ?? '') ? ($item->image['url'] ?? '') : ($item->image ?? '');
                        $sku_attrs = $item->sku_attrs ?? [];
                        $quantity = intval($item->quantity ?? 0);
                        $price = floatval($item->price ?? 0);
                        $subtotal = $price * $quantity;
                        ?>
                        <?php if ($img): ?>
                            <img src="<?php echo esc_url($img); ?>" alt="" class="item-thumb">
                        <?php endif; ?>
                        <div class="item-info">
                            <span class="item-title"><?php echo esc_html($item->product_title ?? ''); ?></span>
                            <?php if (!empty($sku_attrs)): ?>
                            <span class="item-sku"><?php echo esc_html(implode(' / ', (array)$sku_attrs)); ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="item-price">¥<?php echo number_format($price, 2); ?></span>
                        <div class="item-qty-selector is-static">
                            <input type="number" class="qls-checkout-qty" value="<?php echo esc_attr($quantity); ?>" min="1" disabled>
                        </div>
                        <span class="item-subtotal">¥<?php echo number_format($subtotal, 2); ?></span>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                    <?php if ($item->is_invalid) continue; ?>
                    <div class="qls-order-item" id="checkout-item-<?php echo esc_attr($item->id); ?>">
                        <?php 
                        $img = is_array($item->product->main_image) ? ($item->product->main_image['url'] ?? '') : $item->product->main_image;
                        ?>
                        <img src="<?php echo esc_url($img); ?>" alt="" class="item-thumb">
                        <div class="item-info">
                            <a href="<?php echo esc_url(qls_shop_public()->get_product_url($item->product)); ?>" target="_blank" class="item-title"><?php echo esc_html($item->product->title); ?></a>
                            <?php if (!empty($item->sku->attr_values)): ?>
                            <span class="item-sku"><?php echo esc_html(implode(' / ', (array)$item->sku->attr_values)); ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="item-price">¥<?php echo number_format($item->price, 2); ?></span>
                        <div class="item-qty-selector">
                            <button type="button" class="qls-qty-minus" aria-label="<?php esc_attr_e('减少数量', 'qilingshop'); ?>">-</button>
                            <input type="number" class="qls-cart-qty qls-checkout-qty" data-item="<?php echo esc_attr($item->id); ?>" value="<?php echo esc_attr($item->quantity); ?>" min="1" max="<?php echo esc_attr($item->sku->stock); ?>">
                            <button type="button" class="qls-qty-plus" aria-label="<?php esc_attr_e('增加数量', 'qilingshop'); ?>">+</button>
                        </div>
                        <span class="item-subtotal">¥<?php echo number_format($item->subtotal, 2); ?></span>
                        <button type="button" class="qls-remove-checkout-item" data-item="<?php echo esc_attr($item->id); ?>" title="<?php _e('删除', 'qilingshop'); ?>">
                            <span class="dashicons dashicons-trash"></span>
                        </button>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- 备注 -->
        <div class="qls-checkout-section qls-remark-section">
            <h3><?php _e('订单备注', 'qilingshop'); ?></h3>
            <div class="qls-input-wrapper">
                <textarea name="buyer_remark" id="buyer_remark" rows="3" placeholder="<?php _e('选填，请先与卖家协商一致，在此处备注特殊要求', 'qilingshop'); ?>"></textarea>
            </div>
        </div>
        
        <!-- 优惠券 -->
        <?php if ($user_id && !$is_group_order): ?>
        <div class="qls-checkout-section qls-coupon-section">
            <h3><?php _e('优惠券', 'qilingshop'); ?></h3>
            <div class="qls-coupon-select">
                <div class="qls-coupon-input-row">
                    <input type="text" id="qls-coupon-code" placeholder="<?php _e('输入优惠码', 'qilingshop'); ?>">
                    <button type="button" id="qls-apply-coupon" class="qls-btn qls-btn-outline"><?php _e('使用', 'qilingshop'); ?></button>
                </div>
                <div class="qls-available-coupons" id="qls-available-coupons">
                    <button type="button" id="qls-show-coupons" class="qls-coupon-toggle">
                        <span class="dashicons dashicons-tickets-alt"></span>
                        <?php _e('选择优惠券', 'qilingshop'); ?>
                        <span class="qls-coupon-count" id="qls-coupon-count"></span>
                    </button>
                </div>
                <div class="qls-coupon-applied" id="qls-coupon-applied" style="display:none;">
                    <div class="qls-applied-tag">
                        <span class="coupon-name" id="qls-applied-coupon-name"></span>
                        <span class="coupon-discount" id="qls-applied-coupon-discount"></span>
                        <button type="button" class="qls-remove-coupon" id="qls-remove-coupon">&times;</button>
                    </div>
                </div>
            </div>
            <input type="hidden" name="coupon_claim_id" id="qls-coupon-claim-id" value="">
        </div>
        
        <!-- 优惠券弹窗 -->
        <div class="qls-coupon-modal" id="qls-coupon-modal" style="display:none;">
            <div class="qls-coupon-modal-content">
                <div class="modal-header">
                    <h4><?php _e('选择优惠券', 'qilingshop'); ?></h4>
                    <button type="button" class="modal-close" id="qls-close-coupon-modal">&times;</button>
                </div>
                <div class="modal-body" id="qls-coupon-list">
                    <div class="loading"><?php _e('加载中...', 'qilingshop'); ?></div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- 支付方式 -->
        <div class="qls-checkout-section qls-payment-section">
            <div class="section-header">
                <h3><?php _e('支付方式', 'qilingshop'); ?></h3>
        <?php if ($user_points > 0): ?>
                <div class="qls-user-balance">
                    <span class="balance-label"><?php _e('当前积分:', 'qilingshop'); ?></span>
                    <span class="balance-value"><?php echo number_format($user_points); ?></span>
                </div>
                <?php endif; ?>
            </div>
            <div class="qls-payment-methods">
                <div class="qls-payment-grid">
                    <?php 
                    $first_enabled = true;
                    $points_name = function_exists('qilingshop_get_points_name') ? qilingshop_get_points_name() : __('积分', 'qilingshop');
                    
                    // 计算纯积分购买总额 (Sum of points price for all items)
                    $total_points_amount = 0;
                    $can_pay_points = true;
                    if (!$is_group_order) {
                        foreach ($items as $item) {
                            $p_price = floatval($item->sku->points_price ?? 0);
                            if ($p_price > 0) {
                                $total_points_amount += $p_price * $item->quantity;
                            } else {
                            }
                        }
                    }

                    // 积分支付选项
                    if ($total_points_amount > 0 && $user_id && !$is_group_order):
                        $user_can_afford = ($user_points >= $total_points_amount);
                    ?>
                    <label class="qls-payment-card <?php echo !$user_can_afford ? 'disabled' : ''; ?>" data-type="points">
                        <input type="radio" name="payment_method" value="points" <?php echo !$user_can_afford ? 'disabled' : ''; ?>>
                        <div class="payment-card-content">
                            <span class="payment-icon-text">💰</span>
                            <div class="payment-info">
                                <span class="payment-name"><?php printf(__('%s支付', 'qilingshop'), $points_name); ?></span>
                                <span class="payment-desc"><?php printf(__('消耗 %s %s', 'qilingshop'), number_format($total_points_amount), $points_name); ?></span>
                            </div>
                        </div>
                    </label>
                    <?php endif; ?>
                    
                    <?php 
                    if (get_option('qilingshop_alipay_enabled')): 
                        $alipay_f2f = get_option('qilingshop_alipay_f2fpay');
                    ?>
                    <?php if ($alipay_f2f): ?>
                    <label class="qls-payment-card" data-type="cash">
                        <input type="radio" name="payment_method" value="alipay_qr" <?php echo $first_enabled ? 'checked' : ''; ?>>
                        <div class="payment-card-content">
                            <img src="<?php echo QILINGSHOP_URL; ?>static/img/zfb.png" class="payment-icon">
                            <span class="payment-name"><?php _e('支付宝', 'qilingshop'); ?></span>
                        </div>
                    </label>
                    <?php 
                        $first_enabled = false; 
                    endif; 
                    ?>
                    
                    <label class="qls-payment-card" data-type="cash">
                        <input type="radio" name="payment_method" value="alipay" <?php echo $first_enabled ? 'checked' : ''; ?>>
                        <div class="payment-card-content">
                            <img src="<?php echo QILINGSHOP_URL; ?>static/img/zfb.png" class="payment-icon">
                            <span class="payment-name"><?php _e('支付宝网页', 'qilingshop'); ?></span>
                        </div>
                    </label>
                    <?php $first_enabled = false; endif; ?>
                    
                    <?php if (get_option('qilingshop_wechat_enabled')): ?>
                    <label class="qls-payment-card" data-type="cash">
                        <input type="radio" name="payment_method" value="wechat" <?php echo $first_enabled ? 'checked' : ''; ?>>
                        <div class="payment-card-content">
                            <img src="<?php echo QILINGSHOP_URL; ?>static/img/wx.png" class="payment-icon">
                            <span class="payment-name"><?php _e('微信支付', 'qilingshop'); ?></span>
                        </div>
                    </label>
                    <?php $first_enabled = false; endif; ?>

                    <?php if (get_option('qilingshop_xhpay_enabled')): ?>
                    <label class="qls-payment-card" data-type="cash">
                        <input type="radio" name="payment_method" value="xhpay" <?php echo $first_enabled ? 'checked' : ''; ?>>
                        <div class="payment-card-content">
                            <img src="<?php echo QILINGSHOP_URL; ?>static/img/zfb.png" class="payment-icon">
                            <span class="payment-name"><?php _e('虎皮椒 V3', 'qilingshop'); ?></span>
                        </div>
                    </label>
                    <?php $first_enabled = false; endif; ?>

                    <?php if (get_option('qilingshop_epay_enabled')): ?>
                    <label class="qls-payment-card" data-type="cash">
                        <input type="radio" name="payment_method" value="epay" <?php echo $first_enabled ? 'checked' : ''; ?>>
                        <div class="payment-card-content">
                            <img src="<?php echo QILINGSHOP_URL; ?>static/img/zfb.png" class="payment-icon">
                            <span class="payment-name"><?php _e('易支付', 'qilingshop'); ?></span>
                        </div>
                    </label>
                    <?php $first_enabled = false; endif; ?>
                    
                    <?php if (get_option('qilingshop_paypal_enabled')): ?>
                    <label class="qls-payment-card" data-type="cash">
                        <input type="radio" name="payment_method" value="paypal" <?php echo $first_enabled ? 'checked' : ''; ?>>
                        <div class="payment-card-content">
                            <img src="<?php echo QILINGSHOP_URL; ?>static/img/paypal.png" class="payment-icon">
                            <span class="payment-name">PayPal</span>
                        </div>
                    </label>
                    <?php $first_enabled = false; endif; ?>

                    <?php if (get_option('qilingshop_stripe_enabled')): ?>
                    <label class="qls-payment-card" data-type="cash">
                        <input type="radio" name="payment_method" value="stripe" <?php echo $first_enabled ? 'checked' : ''; ?>>
                        <div class="payment-card-content">
                            <img src="<?php echo QILINGSHOP_URL; ?>static/img/stripe.png" class="payment-icon">
                            <span class="payment-name"><?php _e('Stripe', 'qilingshop'); ?></span>
                        </div>
                    </label>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- 订单汇总 -->
        <div class="qls-checkout-section qls-order-summary">
            <div class="summary-row">
                <span><?php _e('商品金额:', 'qilingshop'); ?></span>
                <span>¥<?php echo number_format($totals['total_amount'], 2); ?></span>
            </div>

            <div class="summary-row">
                <span><?php _e('运费:', 'qilingshop'); ?></span>
                <?php if ($is_group_order): ?>
                    <span style="color: #46b450;">¥<?php echo number_format($shipping_fee, 2); ?></span>
                <?php elseif ($is_virtual_only): ?>
                    <span id="qls-shipping-fee" style="color: #46b450;"><?php _e('免运费', 'qilingshop'); ?></span>
                <?php else: ?>
                    <span id="qls-shipping-fee"><?php _e('计算中...', 'qilingshop'); ?></span>
                <?php endif; ?>
            </div>
            
            <div class="summary-row qls-coupon-discount-row" id="qls-coupon-discount-row" style="display:none;">
                <span><?php _e('优惠券折扣:', 'qilingshop'); ?></span>
                <span id="qls-coupon-discount" class="discount-amount">-¥0.00</span>
            </div>
            
            <div class="summary-row total">
                <span><?php _e('应付总额:', 'qilingshop'); ?></span>
                <div class="total-values">
                    <span class="total-amount" id="qls-total-amount">¥<?php echo number_format($is_group_order ? $final_amount : $totals['total_amount'], 2); ?></span>
                </div>
            </div>
        </div>
        
        <!-- 提交订单 -->
        <div class="qls-checkout-footer">
            <button type="submit" class="qls-btn qls-btn-primary qls-btn-large" id="qls-submit-order">
                <?php _e('提交订单', 'qilingshop'); ?>
            </button>
        </div>
        
        <input type="hidden" id="qls-product-total" value="<?php echo esc_attr($totals['total_amount']); ?>">
        <input type="hidden" id="qls-total-points-amount" value="<?php echo esc_attr($total_points_amount); ?>">
        <input type="hidden" id="qls-points-name" value="<?php echo esc_attr($points_name); ?>">
        <?php if ($is_group_order): ?>
            <input type="hidden" id="qls-group-order" value="1">
            <input type="hidden" id="qls-group-order-no" value="<?php echo esc_attr($group_order->order_no); ?>">
        <?php endif; ?>
    </form>
    
    <?php endif; ?>
</div>

<!-- 自定义确认弹窗 -->
<div class="qls-confirm-modal" id="qls-confirm-modal" style="display: none;">
    <div class="qls-confirm-backdrop"></div>
    <div class="qls-confirm-dialog">
        <div class="qls-confirm-icon">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="8" x2="12" y2="12"></line>
                <line x1="12" y1="16" x2="12.01" y2="16"></line>
            </svg>
        </div>
        <h3 class="qls-confirm-title"><?php _e('确认删除', 'qilingshop'); ?></h3>
        <p class="qls-confirm-message"><?php _e('确定要从购物车中删除该商品吗？', 'qilingshop'); ?></p>
        <div class="qls-confirm-actions">
            <button type="button" class="qls-confirm-cancel"><?php _e('取消', 'qilingshop'); ?></button>
            <button type="button" class="qls-confirm-ok"><?php _e('确认删除', 'qilingshop'); ?></button>
        </div>
    </div>
</div>

</div>
</div>
<?php qls_shop_public()->get_shop_footer(); ?>
