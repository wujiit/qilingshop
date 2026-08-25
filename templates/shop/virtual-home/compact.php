<?php
/**
 * 虚拟发卡首页 - 清爽列表风格
 */
if (!defined('ABSPATH')) exit;
?>

<?php if (empty($products)): ?>
<div class="qls-card-home-empty">
    <h2><?php _e('暂无可售卡密商品', 'qilingshop'); ?></h2>
    <p><?php _e('请稍后再来，或调整搜索条件。', 'qilingshop'); ?></p>
</div>
<?php else: ?>
<div class="qls-card-home-list">
    <?php foreach ($products as $product): ?>
        <?php
        $product_url = qls_shop_public()->get_product_url($product);
        $main_image = '';
        if (!empty($product->main_image)) {
            if (is_array($product->main_image)) {
                $main_image = (string) ($product->main_image['url'] ?? '');
            } elseif (is_string($product->main_image)) {
                $main_image = $product->main_image;
            }
        }
        if ($main_image === '' && !empty($product->gallery) && is_array($product->gallery)) {
            $first_image = reset($product->gallery);
            $main_image = is_array($first_image) ? (string) ($first_image['url'] ?? '') : (string) $first_image;
        }
        $min_price = isset($product->min_price) ? (float) $product->min_price : 0;
        $max_price = isset($product->max_price) ? (float) $product->max_price : 0;
        $stock = isset($product->total_stock) ? (int) $product->total_stock : 0;
        $sold_out = $stock <= 0;
        ?>
        <article class="qls-card-home-row<?php echo $sold_out ? ' is-sold-out' : ''; ?>">
            <a class="qls-card-home-row-thumb" href="<?php echo esc_url($product_url); ?>">
                <?php if ($main_image !== ''): ?>
                <img src="<?php echo esc_url($main_image); ?>" alt="<?php echo esc_attr($product->title ?? ''); ?>" loading="lazy">
                <?php else: ?>
                <span class="qls-card-home-noimg"><?php _e('卡密', 'qilingshop'); ?></span>
                <?php endif; ?>
            </a>
            <div class="qls-card-home-row-main">
                <div class="qls-card-home-row-copy">
                    <h2><a href="<?php echo esc_url($product_url); ?>"><?php echo esc_html($product->title ?? ''); ?></a></h2>
                    <?php if (!empty($product->subtitle)): ?>
                    <p><?php echo esc_html($product->subtitle); ?></p>
                    <?php endif; ?>
                    <div class="qls-card-home-tags">
                        <span><?php _e('自动发货', 'qilingshop'); ?></span>
                        <?php if ($show_stock): ?>
                        <span><?php echo esc_html(sprintf(__('库存 %d', 'qilingshop'), max(0, $stock))); ?></span>
                        <?php endif; ?>
                        <?php if ($show_sales): ?>
                        <span><?php echo esc_html(sprintf(__('%d 人付款', 'qilingshop'), (int) ($product->sales_count ?? 0))); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="qls-card-home-row-action">
                    <div class="qls-card-home-price">
                        <?php
                        if (!is_user_logged_in() && get_option('qls_shop_price_login_required', false)) {
                            esc_html_e('登录后查看价格', 'qilingshop');
                        } else {
                            echo $max_price > $min_price && $min_price > 0
                                ? esc_html(qilingshop_format_price($min_price) . ' - ' . qilingshop_format_price($max_price))
                                : esc_html(qilingshop_format_price($min_price));
                        }
                        ?>
                    </div>
                    <a class="qls-card-home-buy" href="<?php echo esc_url($product_url); ?>">
                        <?php echo $sold_out ? esc_html__('已售罄', 'qilingshop') : esc_html__('立即购买', 'qilingshop'); ?>
                    </a>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>
