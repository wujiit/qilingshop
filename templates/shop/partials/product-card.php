<?php
/**
 * 商品卡片组件
 */
if (!defined('ABSPATH')) exit;

$main_image = '';
if (is_array($product->main_image)) {
    $main_image = $product->main_image['url'] ?? '';
} elseif (is_string($product->main_image)) {
    $main_image = $product->main_image;
}

// 缺省时使用商品图集
if (empty($main_image) && !empty($product->gallery) && is_array($product->gallery)) {
    $first = reset($product->gallery);
    if (is_array($first)) {
        $main_image = $first['url'] ?? '';
    } elseif (is_string($first)) {
        $main_image = $first;
    }
}

$product_url = qls_shop_public()->get_product_url($product);
?>

<div class="qls-product-card">
    <a href="<?php echo esc_url($product_url); ?>" class="qls-product-link">
        <div class="qls-product-image">
            <?php if ($main_image): ?>
            <img src="<?php echo esc_url($main_image); ?>" alt="<?php echo esc_attr($product->title); ?>" loading="lazy">
            <?php else: ?>
            <div class="qls-no-image">
                <span class="dashicons dashicons-format-image"></span>
            </div>
            <?php endif; ?>
            
            <?php if ($product->total_stock <= 0): ?>
            <span class="qls-product-badge sold-out"><?php _e('售罄', 'qilingshop'); ?></span>
            <?php endif; ?>
        </div>
        
        <div class="qls-product-info">
            <h3 class="qls-product-title"><?php echo esc_html($product->title); ?></h3>
            
            <?php if ($product->subtitle): ?>
            <p class="qls-product-subtitle"><?php echo esc_html($product->subtitle); ?></p>
            <?php endif; ?>
            
            <div class="qls-product-price">
                <?php if (!is_user_logged_in() && get_option('qls_shop_price_login_required', false)): ?>
                <span class="price-login-required"><?php _e('登录后查看价格', 'qilingshop'); ?></span>
                <?php else: ?>
                <?php if ($product->min_price == $product->max_price): ?>
                <span class="price">¥<?php echo number_format($product->min_price, 2); ?></span>
                <?php else: ?>
                <span class="price">¥<?php echo number_format($product->min_price, 2); ?> - ¥<?php echo number_format($product->max_price, 2); ?></span>
                <?php endif; ?>
                <?php if (!empty($product->points_price) && floatval($product->points_price) > 0): ?>
                <?php $points_name = function_exists('qilingshop_get_points_name') ? qilingshop_get_points_name() : __('积分', 'qilingshop'); ?>
                <span class="points-price"><?php printf(__('积分价 %1$s %2$s', 'qilingshop'), number_format_i18n(floatval($product->points_price), 0), esc_html($points_name)); ?></span>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <div class="qls-product-meta">
                <?php if (get_option('qls_shop_show_sales', true)): ?>
                <span class="sales"><?php printf(__('销量 %d', 'qilingshop'), $product->sales_count); ?></span>
                <?php endif; ?>
                
                <?php if (get_option('qls_shop_review_show_on_card', false) && get_option('qls_shop_review_enabled', true)): ?>
                <?php
                // 显示评分和评价数
                $review_count = isset($product->review_count) ? intval($product->review_count) : 0;
                $avg_rating = isset($product->avg_rating) ? floatval($product->avg_rating) : 0;
                if ($review_count > 0):
                ?>
                <span class="rating">
                    ★ <?php echo number_format($avg_rating, 1); ?>
                    <span class="rating-count">(<?php echo $review_count; ?>)</span>
                </span>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </a>
</div>
