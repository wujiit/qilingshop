<?php
/**
 * 虚拟发卡首页 - 精选橱窗风格
 */
if (!defined('ABSPATH')) exit;

$products = is_array($products) ? array_values($products) : [];
$build_card_view = function($product) {
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

    return [
        'product'    => $product,
        'url'        => $product_url,
        'image'      => $main_image,
        'min_price'  => $min_price,
        'max_price'  => $max_price,
        'stock'      => $stock,
        'sold_out'   => $stock <= 0,
        'sales'      => (int) ($product->sales_count ?? 0),
        'title'      => (string) ($product->title ?? ''),
        'subtitle'   => (string) ($product->subtitle ?? ''),
    ];
};

$format_price_range = function($view) {
    return $view['max_price'] > $view['min_price'] && $view['min_price'] > 0
        ? qilingshop_format_price($view['min_price']) . ' - ' . qilingshop_format_price($view['max_price'])
        : qilingshop_format_price($view['min_price']);
};
?>

<?php if (empty($products)): ?>
<div class="qls-card-home-empty">
    <h2><?php _e('暂无可售卡密商品', 'qilingshop'); ?></h2>
    <p><?php _e('请稍后再来，或调整搜索条件。', 'qilingshop'); ?></p>
</div>
<?php else: ?>
<div class="qls-card-showcase">
    <div class="qls-card-showcase-grid qls-card-showcase-grid--all">
        <?php foreach ($products as $index => $product): ?>
        <?php
        $view = $build_card_view($product);
        $badge = $view['sold_out']
            ? __('售罄', 'qilingshop')
            : ($index === 0 ? __('热卖卡密', 'qilingshop') : __('自动发货', 'qilingshop'));
        ?>
        <article class="qls-card-home-card<?php echo $view['sold_out'] ? ' is-sold-out' : ''; ?>">
            <a class="qls-card-home-card-media" href="<?php echo esc_url($view['url']); ?>">
                <?php if ($view['image'] !== ''): ?>
                <img src="<?php echo esc_url($view['image']); ?>" alt="<?php echo esc_attr($view['title']); ?>" loading="lazy">
                <?php else: ?>
                <span class="qls-card-home-noimg"><?php _e('卡密', 'qilingshop'); ?></span>
                <?php endif; ?>
                <span class="qls-card-home-badge"><?php echo esc_html($badge); ?></span>
            </a>
            <div class="qls-card-home-card-body">
                <h2><a href="<?php echo esc_url($view['url']); ?>"><?php echo esc_html($view['title']); ?></a></h2>
                <?php if ($view['subtitle'] !== ''): ?>
                <p><?php echo esc_html($view['subtitle']); ?></p>
                <?php endif; ?>
                <div class="qls-card-home-card-meta">
                    <?php if ($show_stock): ?>
                    <span><?php echo esc_html(sprintf(__('库存 %d', 'qilingshop'), max(0, $view['stock']))); ?></span>
                    <?php endif; ?>
                    <?php if ($show_sales): ?>
                    <span><?php echo esc_html(sprintf(__('%d 人付款', 'qilingshop'), $view['sales'])); ?></span>
                    <?php endif; ?>
                </div>
                <div class="qls-card-home-card-foot">
                    <div class="qls-card-home-price"><?php echo (!is_user_logged_in() && get_option('qls_shop_price_login_required', false)) ? esc_html__('登录后查看价格', 'qilingshop') : esc_html($format_price_range($view)); ?></div>
                    <a class="qls-card-home-buy" href="<?php echo esc_url($view['url']); ?>">
                        <?php echo $view['sold_out'] ? esc_html__('已售罄', 'qilingshop') : esc_html__('购买', 'qilingshop'); ?>
                    </a>
                </div>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
