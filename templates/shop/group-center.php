<?php
/**
 * 团购中心页面模板
 * 
 * @package QilingShop
 * @since   2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>


<?php qls_shop_public()->get_shop_header(__('团购中心', 'qilingshop'), true); ?>

<div class="qls-shop-wrapper qls-group-center-page">
    <div class="qls-container">
        <nav class="qls-breadcrumb">
            <a href="<?php echo esc_url(qls_shop_public()->get_shop_url()); ?>"><?php _e('商城', 'qilingshop'); ?></a>
            <span class="sep">›</span>
            <span class="current"><?php _e('团购中心', 'qilingshop'); ?></span>
        </nav>
        <div class="qls-group-center">
            <div class="qls-group-header">
                <h2 class="qls-group-title">
                    <span class="qls-group-icon">🔥</span>
                    <?php _e('限时拼团', 'qilingshop'); ?>
                </h2>
                <p class="qls-group-desc"><?php _e('多人拼团，享超低价格', 'qilingshop'); ?></p>
                <?php if (is_user_logged_in()): ?>
                <a href="<?php echo esc_url(qls_group_public()->get_group_detail_url()); ?>" class="qls-btn qls-btn-my-groups">
                    <span class="dashicons dashicons-groups"></span>
                    <?php _e('我的拼团', 'qilingshop'); ?>
                </a>
                <?php endif; ?>
            </div>

        <?php if (empty($products)): ?>
        <div class="qls-empty-state">
            <div class="qls-empty-icon">🛍️</div>
            <h3 class="qls-empty-title"><?php _e('暂无团购商品', 'qilingshop'); ?></h3>
            <p class="qls-empty-desc"><?php _e('精选团购活动正在筹备中，先逛逛商城吧', 'qilingshop'); ?></p>
            <div class="qls-empty-actions">
                <a href="<?php echo esc_url(qls_shop_public()->get_shop_url()); ?>" class="qls-btn qls-btn-primary qls-btn-lg"><?php _e('去商城看看', 'qilingshop'); ?></a>
            </div>
        </div>
        <?php else: ?>
        <div class="qls-group-products">
            <?php foreach ($products as $product): 
                $image_url = '';
                if (is_array($product->main_image)) {
                    $image_url = $product->main_image['url'] ?? '';
                } elseif (is_string($product->main_image)) {
                    $image_url = $product->main_image;
                }
                
                $product_url = qls_shop_public()->get_product_url($product);
                $original_price = floatval($product->min_price);
                $group_price = floatval($product->group_price);
                $discount = $original_price > 0 ? round(($original_price - $group_price) / $original_price * 100) : 0;
            ?>
            <div class="qls-group-product-card">
                <a href="<?php echo esc_url($product_url); ?>" class="qls-group-product-link">
                    <div class="qls-group-product-image">
                        <img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($product->title); ?>">
                        <span class="qls-group-badge"><?php printf(esc_html__('%s人团', 'qilingshop'), esc_html($product->group_size)); ?></span>
                        <?php if ($discount > 0): ?>
                        <span class="qls-group-discount"><?php printf(esc_html__('省%s%%', 'qilingshop'), esc_html($discount)); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="qls-group-product-info">
                        <h3 class="qls-group-product-title"><?php echo esc_html($product->title); ?></h3>
                        <div class="qls-group-price-box">
                            <span class="qls-group-price">¥<?php echo number_format($group_price, 2); ?></span>
                            <span class="qls-group-original">¥<?php echo number_format($original_price, 2); ?></span>
                        </div>
                        <div class="qls-group-stats">
                            <span class="qls-group-joined">
                                <?php printf(__('%d人已拼', 'qilingshop'), intval($product->success_member_count ?? 0)); ?>
                            </span>
                        </div>
                    </div>
                </a>
                <div class="qls-group-action">
                    <a href="<?php echo esc_url($product_url); ?>" class="qls-btn qls-btn-group">
                        <?php _e('去拼团', 'qilingshop'); ?>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="qls-pagination">
            <?php
            echo paginate_links([
                'base'      => add_query_arg('gpage', '%#%'),
                'format'    => '',
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
                'total'     => $total_pages,
                'current'   => $page,
            ]);
            ?>
        </div>
        <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php qls_shop_public()->get_shop_footer(); ?>
