<?php
/**
 * 新人专项页模板
 */
if (!defined('ABSPATH')) {
    exit;
}

$products = isset($products) && is_array($products) ? $products : [];
$total = isset($total) ? (int) $total : 0;
$paged = isset($paged) ? max(1, (int) $paged) : 1;
$total_pages = isset($total_pages) ? max(1, (int) $total_pages) : 1;
$is_eligible = !empty($is_eligible);
$is_logged_in = !empty($is_logged_in);

qls_shop_public()->get_shop_header('', true);
?>
<div class="qls-shop-wrapper qls-new-user-zone-page">
    <div class="qls-container">
        <nav class="qls-breadcrumb">
            <a href="<?php echo esc_url(qls_shop_public()->get_shop_url()); ?>"><?php _e('商城', 'qilingshop'); ?></a>
            <span class="sep">›</span>
            <span class="current"><?php _e('新人专区', 'qilingshop'); ?></span>
        </nav>

        <section class="qls-new-user-hero">
            <div class="qls-new-user-hero-main">
                <span class="qls-new-user-tag"><?php _e('新人专享', 'qilingshop'); ?></span>
                <h1><?php _e('新人首单专享活动', 'qilingshop'); ?></h1>
                <p><?php _e('精选商品可享新人专属价，活动商品仅限购 1 件，且退款后资格不恢复。', 'qilingshop'); ?></p>
                <div class="qls-new-user-hero-meta">
                    <span><?php printf(__('当前活动商品 %d 件', 'qilingshop'), $total); ?></span>
                    <span><?php _e('限首个商城支付订单', 'qilingshop'); ?></span>
                </div>
            </div>
            <div class="qls-new-user-hero-status">
                <?php if (!$is_logged_in): ?>
                <div class="qls-status-pill need-login"><?php _e('登录后可参与新人价', 'qilingshop'); ?></div>
                <a class="qls-status-action qls-login-trigger user-login"
                   href="<?php echo esc_url(wp_login_url(get_permalink())); ?>"
                   data-login-url="<?php echo esc_url(wp_login_url(get_permalink())); ?>"><?php _e('立即登录', 'qilingshop'); ?></a>
                <?php elseif ($is_eligible): ?>
                <div class="qls-status-pill can-join"><?php _e('当前可享新人专属价', 'qilingshop'); ?></div>
                <span class="qls-status-tip"><?php _e('下单时将自动按新人价结算', 'qilingshop'); ?></span>
                <?php else: ?>
                <div class="qls-status-pill used"><?php _e('当前不满足新人资格', 'qilingshop'); ?></div>
                <span class="qls-status-tip"><?php _e('仍可按商品原价正常购买', 'qilingshop'); ?></span>
                <?php endif; ?>
            </div>
        </section>

        <?php if (!empty($products)): ?>
        <div class="qls-new-user-grid">
            <?php foreach ($products as $product): ?>
                <?php
                $product_url = qls_shop_public()->get_product_url($product);
                $main_image = '';
                if (is_array($product->main_image)) {
                    $main_image = $product->main_image['url'] ?? '';
                } elseif (is_string($product->main_image)) {
                    $main_image = $product->main_image;
                }
                if ($main_image === '' && !empty($product->gallery) && is_array($product->gallery)) {
                    $first = reset($product->gallery);
                    if (is_array($first)) {
                        $main_image = $first['url'] ?? '';
                    } elseif (is_string($first)) {
                        $main_image = $first;
                    }
                }

                $new_user_price = qls_product()->get_new_user_special_price($product);
                $normal_price_text = '';
                if ((float) $product->min_price === (float) $product->max_price) {
                    $normal_price_text = '¥' . number_format((float) $product->min_price, 2);
                } else {
                    $normal_price_text = '¥' . number_format((float) $product->min_price, 2) . ' - ¥' . number_format((float) $product->max_price, 2);
                }
                ?>
                <article class="qls-new-user-card qls-product-card">
                    <a href="<?php echo esc_url($product_url); ?>" class="qls-product-link">
                        <div class="qls-product-image">
                            <?php if ($main_image !== ''): ?>
                            <img src="<?php echo esc_url($main_image); ?>" alt="<?php echo esc_attr($product->title); ?>" loading="lazy">
                            <?php else: ?>
                            <div class="qls-no-image"><span class="dashicons dashicons-format-image"></span></div>
                            <?php endif; ?>
                            <span class="qls-new-user-corner"><?php _e('新人价', 'qilingshop'); ?></span>
                        </div>

                        <div class="qls-product-info">
                            <h3 class="qls-product-title"><?php echo esc_html($product->title); ?></h3>
                            <?php if (!empty($product->subtitle)): ?>
                            <p class="qls-product-subtitle"><?php echo esc_html($product->subtitle); ?></p>
                            <?php endif; ?>

                            <div class="qls-new-user-price-row">
                                <span class="qls-new-user-price">¥<?php echo esc_html(number_format($new_user_price, 2)); ?></span>
                                <span class="qls-new-user-origin"><?php echo esc_html($normal_price_text); ?></span>
                            </div>

                            <div class="qls-product-meta">
                                <span class="sales"><?php printf(__('销量 %d', 'qilingshop'), (int) $product->sales_count); ?></span>
                                <span class="qls-new-user-limit"><?php _e('限购 1 件', 'qilingshop'); ?></span>
                            </div>
                        </div>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="qls-new-user-pagination">
            <?php
            echo wp_kses_post(paginate_links([
                'base' => str_replace(999999999, '%#%', esc_url(get_pagenum_link(999999999))),
                'format' => '?paged=%#%',
                'current' => $paged,
                'total' => $total_pages,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
                'type' => 'list',
            ]));
            ?>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <div class="qls-notice warning"><?php _e('当前暂无可参与的新人专项商品。', 'qilingshop'); ?></div>
        <?php endif; ?>
    </div>
</div>
<?php qls_shop_public()->get_shop_footer(); ?>
