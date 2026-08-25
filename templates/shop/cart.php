<?php
/**
 * 购物车模板
 */
if (!defined('ABSPATH')) exit;

$items = qls_cart()->get_items();
$totals = qls_cart()->get_totals();

qls_shop_public()->get_shop_header(__('购物车', 'qilingshop'));
?>

<div class="qls-shop-wrapper qls-cart-page">
<div class="qls-container">
<nav class="qls-breadcrumb">
    <a href="<?php echo esc_url(qls_shop_public()->get_shop_url()); ?>"><?php _e('商城', 'qilingshop'); ?></a>
    <span class="sep">›</span>
    <span class="current"><?php _e('购物车', 'qilingshop'); ?></span>
</nav>
<div class="qls-cart-wrapper">
    <?php if (empty($items)): ?>
    <div class="qls-cart-empty">
        <span class="dashicons dashicons-cart"></span>
        <p><?php _e('购物车是空的', 'qilingshop'); ?></p>
        <a href="<?php echo esc_url(qls_shop_public()->get_shop_url()); ?>" class="qls-btn qls-btn-primary">
            <?php _e('去逛逛', 'qilingshop'); ?>
        </a>
    </div>
    <?php else: ?>
    
    <table class="qls-cart-table">
        <thead>
            <tr>
                <th class="col-product"><?php _e('商品', 'qilingshop'); ?></th>
                <th class="col-price"><?php _e('单价', 'qilingshop'); ?></th>
                <th class="col-quantity"><?php _e('数量', 'qilingshop'); ?></th>
                <th class="col-subtotal"><?php _e('小计', 'qilingshop'); ?></th>
                <th class="col-action"><?php _e('操作', 'qilingshop'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
            <tr class="qls-cart-item <?php echo $item->is_invalid ? 'invalid' : ''; ?>" data-item-id="<?php echo esc_attr($item->id); ?>">
                <td class="col-product" data-label="<?php esc_attr_e('商品', 'qilingshop'); ?>">
                    <div class="qls-cart-product">
                        <?php 
                        $img = '';
                        if (is_array($item->product->main_image)) {
                            $img = $item->product->main_image['url'] ?? '';
                        } else {
                            $img = $item->product->main_image;
                        }
                        ?>
                        <img src="<?php echo esc_url($img); ?>" alt="" class="product-thumb">
                        <div class="product-info">
                            <a href="<?php echo esc_url(qls_shop_public()->get_product_url($item->product)); ?>" class="product-title">
                                <?php echo esc_html($item->product->title); ?>
                            </a>
                            <?php if (!empty($item->sku->attr_values)): ?>
                            <span class="product-sku">
                                <?php echo esc_html(implode(' / ', (array)$item->sku->attr_values)); ?>
                            </span>
                            <?php endif; ?>
                            
                            <?php if ($item->is_invalid): ?>
                            <span class="invalid-reason"><?php echo esc_html($item->invalid_reason); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
                <td class="col-price" data-label="<?php esc_attr_e('单价', 'qilingshop'); ?>">
                    <span class="price">¥<?php echo number_format($item->price, 2); ?></span>
                </td>
                <td class="col-quantity" data-label="<?php esc_attr_e('数量', 'qilingshop'); ?>">
                    <?php if (!$item->is_invalid): ?>
                    <div class="qls-quantity-input">
                        <button type="button" class="qls-qty-minus" data-item="<?php echo esc_attr($item->id); ?>" aria-label="<?php esc_attr_e('减少数量', 'qilingshop'); ?>">-</button>
                        <input type="number" class="qls-cart-qty" data-item="<?php echo esc_attr($item->id); ?>" value="<?php echo esc_attr($item->quantity); ?>" min="1" max="<?php echo esc_attr($item->sku->stock); ?>">
                        <button type="button" class="qls-qty-plus" data-item="<?php echo esc_attr($item->id); ?>" aria-label="<?php esc_attr_e('增加数量', 'qilingshop'); ?>">+</button>
                    </div>
                    <?php else: ?>
                    <span><?php echo esc_html($item->quantity); ?></span>
                    <?php endif; ?>
                </td>
                <td class="col-subtotal" data-label="<?php esc_attr_e('小计', 'qilingshop'); ?>">
                    <span class="subtotal" data-item="<?php echo esc_attr($item->id); ?>">¥<?php echo number_format($item->subtotal, 2); ?></span>
                </td>
                <td class="col-action" data-label="<?php esc_attr_e('操作', 'qilingshop'); ?>">
                    <a href="#" class="qls-remove-item" data-item="<?php echo esc_attr($item->id); ?>"><?php _e('删除', 'qilingshop'); ?></a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <div class="qls-cart-footer">
        <div class="qls-cart-summary">
            <div class="summary-row">
                <span><?php _e('商品件数:', 'qilingshop'); ?></span>
                <span class="count" id="qls-cart-count"><?php echo esc_html($totals['total_quantity']); ?></span>
            </div>
            <div class="summary-row total">
                <span><?php _e('合计:', 'qilingshop'); ?></span>
                <span class="total-amount" id="qls-cart-total">¥<?php echo number_format($totals['total_amount'], 2); ?></span>
            </div>
            
            <?php if ($totals['invalid_count'] > 0): ?>
            <p class="invalid-notice">
                <?php printf(__('购物车中有 %d 件商品已失效', 'qilingshop'), $totals['invalid_count']); ?>
            </p>
            <?php endif; ?>
        </div>
        
        <div class="qls-cart-actions">
            <a href="<?php echo esc_url(qls_shop_public()->get_shop_url()); ?>" class="qls-btn qls-btn-secondary">
                <?php _e('继续购物', 'qilingshop'); ?>
            </a>
            <a href="<?php echo esc_url(qls_shop_public()->get_page_url('checkout')); ?>" class="qls-btn qls-btn-primary" id="qls-checkout-btn">
                <?php _e('去结算', 'qilingshop'); ?>
            </a>
        </div>
    </div>
    
    <?php endif; ?>
</div>
</div>
</div>
<?php qls_shop_public()->get_shop_footer(); ?>
