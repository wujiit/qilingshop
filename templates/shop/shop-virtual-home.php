<?php
/**
 * 虚拟发卡首页模板
 */
if (!defined('ABSPATH')) exit;

$style_registry = function_exists('qilingshop_get_virtual_home_styles') ? qilingshop_get_virtual_home_styles() : [];
if (empty($style_registry)) {
    $style_registry = [
        'compact' => [
            'label'    => __('清爽列表', 'qilingshop'),
            'template' => 'compact',
        ],
    ];
}
$requested_style = isset($style) ? sanitize_key((string) $style) : '';
$selected_style = $requested_style !== '' ? $requested_style : sanitize_key((string) get_option('qls_shop_virtual_home_style', 'compact'));
if (!isset($style_registry[$selected_style])) {
    $style_keys = array_keys($style_registry);
    $selected_style = isset($style_registry['compact']) ? 'compact' : (string) reset($style_keys);
}

$configured_limit = isset($limit) && (int) $limit > 0
    ? (int) $limit
    : (int) get_option('qls_shop_virtual_home_limit', 24);
$product_limit = max(4, min(60, $configured_limit));

$raw_category = isset($_GET['card_category']) && !is_array($_GET['card_category']) ? wp_unslash($_GET['card_category']) : 0;
$raw_keyword = isset($_GET['card_keyword']) && !is_array($_GET['card_keyword']) ? wp_unslash($_GET['card_keyword']) : '';
$selected_category = absint($raw_category);
$keyword = sanitize_text_field($raw_keyword);

$categories = [];
$card_category_counts = [];
$category_service = function_exists('qls_category') ? qls_category() : null;
if ($category_service) {
    $category_limit = (int) apply_filters('qls_shop_virtual_home_category_limit', 24);
    $category_limit = max(1, min(100, $category_limit));
    $supports_card_counts = method_exists($category_service, 'get_virtual_card_product_count_map');
    $categories = $category_service->get_list([
        'status'  => 1,
        'orderby' => 'sort_order',
        'order'   => 'ASC',
        'limit'   => $supports_card_counts ? -1 : $category_limit,
    ]);

    if ($supports_card_counts) {
        $card_category_counts = $category_service->get_virtual_card_product_count_map(1);
        $categories = array_values(array_filter((array) $categories, function($category) use ($card_category_counts) {
            $category_id = (int) ($category->id ?? 0);
            return $category_id > 0 && !empty($card_category_counts[$category_id]);
        }));
        $categories = array_slice($categories, 0, $category_limit);

        if ($selected_category > 0 && empty($card_category_counts[$selected_category])) {
            $selected_category = 0;
        }
    }
}

$categories = apply_filters('qls_shop_virtual_home_categories', $categories, [
    'category_counts'    => $card_category_counts,
    'selected_category'  => $selected_category,
    'keyword'            => $keyword,
]);

$query_args = [
    'status'       => 1,
    'product_type' => 'virtual',
    'virtual_type' => 'card',
    'limit'        => $product_limit,
    'orderby'      => 'id',
    'order'        => 'DESC',
];
if ($selected_category > 0) {
    $query_args['category_id'] = $selected_category;
}
if ($keyword !== '') {
    $query_args['keyword'] = $keyword;
}

$query_args = apply_filters('qls_shop_virtual_home_query_args', $query_args, [
    'style'             => $selected_style,
    'selected_category' => $selected_category,
    'keyword'           => $keyword,
]);

$products = function_exists('qls_product') ? qls_product()->get_list($query_args) : [];

$shop_url = qls_shop_public()->get_page_url('virtual_home');
if ($shop_url === '') {
    $current_permalink = get_permalink();
    $shop_url = is_string($current_permalink) ? $current_permalink : '';
}
if ($shop_url === '') {
    $shop_url = qls_shop_public()->get_shop_url();
}
if ($shop_url === '') {
    $shop_url = home_url('/');
}
$order_query_url = qls_shop_public()->get_page_url('order_query');
$home_title = (string) get_option('qls_shop_virtual_home_title', __('虚拟发卡', 'qilingshop'));
$home_subtitle = (string) get_option('qls_shop_virtual_home_subtitle', __('自动发货，支付后查看卡密信息', 'qilingshop'));
if ($home_title === '') {
    $home_title = __('虚拟发卡', 'qilingshop');
}
$show_stock = (bool) get_option('qls_shop_show_stock', true);
$show_sales = (bool) get_option('qls_shop_show_sales', true);

$style_template = function_exists('qilingshop_locate_virtual_home_style_template')
    ? qilingshop_locate_virtual_home_style_template($selected_style, $style_registry[$selected_style] ?? [])
    : QILINGSHOP_PATH . 'templates/shop/virtual-home/compact.php';
$style_template_class = sanitize_html_class(pathinfo((string) $style_template, PATHINFO_FILENAME));
if ($style_template_class === '') {
    $style_template_class = 'compact';
}

qls_shop_public()->get_shop_header('', true);
?>

<div class="qls-shop-wrapper qls-card-home qls-card-home--<?php echo esc_attr($selected_style); ?> qls-card-home-template--<?php echo esc_attr($style_template_class); ?>">
    <div class="qls-container">
        <div class="qls-card-home-head">
            <div class="qls-card-home-title-group">
                <span class="qls-card-home-kicker"><?php _e('虚拟商品', 'qilingshop'); ?></span>
                <h1><?php echo esc_html($home_title); ?></h1>
                <?php if ($home_subtitle !== ''): ?>
                <p><?php echo esc_html($home_subtitle); ?></p>
                <?php endif; ?>
            </div>
            <?php if ($order_query_url): ?>
            <a class="qls-card-home-query" href="<?php echo esc_url($order_query_url); ?>">
                <?php _e('查询订单', 'qilingshop'); ?>
            </a>
            <?php endif; ?>
        </div>

        <form class="qls-card-home-search" method="get" action="<?php echo esc_url($shop_url); ?>">
            <?php if ($selected_category > 0): ?>
            <input type="hidden" name="card_category" value="<?php echo esc_attr($selected_category); ?>">
            <?php endif; ?>
            <input type="search" name="card_keyword" value="<?php echo esc_attr($keyword); ?>" placeholder="<?php esc_attr_e('搜索卡密商品', 'qilingshop'); ?>">
            <button type="submit"><?php _e('搜索', 'qilingshop'); ?></button>
        </form>

        <?php if (!empty($categories)): ?>
        <nav class="qls-card-home-cats" aria-label="<?php esc_attr_e('发卡商品分类', 'qilingshop'); ?>">
            <?php
            $all_url = $keyword !== ''
                ? add_query_arg(['card_keyword' => $keyword], $shop_url)
                : $shop_url;
            ?>
            <a class="<?php echo $selected_category <= 0 ? 'is-active' : ''; ?>" href="<?php echo esc_url($all_url); ?>">
                <?php _e('全部', 'qilingshop'); ?>
            </a>
            <?php foreach ($categories as $category): ?>
                <?php
                $category_id = (int) ($category->id ?? 0);
                if ($category_id <= 0) {
                    continue;
                }
                $category_args = ['card_category' => $category_id];
                if ($keyword !== '') {
                    $category_args['card_keyword'] = $keyword;
                }
                $category_url = add_query_arg($category_args, $shop_url);
                ?>
                <a class="<?php echo $selected_category === $category_id ? 'is-active' : ''; ?>" href="<?php echo esc_url($category_url); ?>">
                    <?php echo esc_html($category->name ?? ''); ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>

        <?php include $style_template; ?>

        <?php qls_shop_public()->render_service_showcase('home_bottom'); ?>
    </div>
</div>

<?php qls_shop_public()->get_shop_footer(); ?>
