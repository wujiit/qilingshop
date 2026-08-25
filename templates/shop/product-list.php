<?php
/**
 * 商品列表模板（分类页）
 */
if (!defined('ABSPATH')) exit;

qls_shop_public()->get_shop_header('', true);

$paged = get_query_var('paged') ? get_query_var('paged') : 1;
if (isset($_GET['paged'])) {
    $paged = max(1, intval($_GET['paged']));
}
$limit = 12;

$category = isset($category) ? $category : null;
$page_title = $category ? $category->name : __('所有商品', 'qilingshop');
$base_url = $category ? qls_shop_public()->get_category_url($category) : qls_shop_public()->get_shop_url() . '?view=products';
$sort = isset($_GET['sort']) ? sanitize_key(wp_unslash($_GET['sort'])) : 'default';
$allowed_sorts = ['default', 'latest', 'sales', 'price_desc', 'price_asc', 'points_desc', 'points_asc'];
if (!in_array($sort, $allowed_sorts, true)) {
    $sort = 'default';
}
$points_param = isset($_GET['points']) ? strtolower(trim(sanitize_text_field(wp_unslash($_GET['points'])))) : '';
$points_only = in_array($points_param, ['1', 'yes', 'true', 'on'], true);
if (in_array($sort, ['points_desc', 'points_asc'], true)) {
    $points_only = true;
}

$args = [
    'limit' => $limit,
    'page' => $paged,
    'status' => 1,
    'orderby' => 'created_at',
    'order' => 'DESC'
];

if ($category) {
    $args['category_id'] = $category->id;
}

switch ($sort) {
    case 'sales':
        $args['orderby'] = 'sales_count';
        $args['order'] = 'DESC';
        break;
    case 'price_asc':
        $args['orderby'] = 'min_price';
        $args['order'] = 'ASC';
        break;
    case 'price_desc':
        $args['orderby'] = 'min_price';
        $args['order'] = 'DESC';
        break;
    case 'points_asc':
        $args['orderby'] = 'points_price';
        $args['order'] = 'ASC';
        break;
    case 'points_desc':
        $args['orderby'] = 'points_price';
        $args['order'] = 'DESC';
        break;
}

if ($points_only) {
    $args['points_payable'] = 1;
}

$products = qls_product()->get_list($args);
$total_products = qls_product()->get_count($args);
$pagination = qilingshop_get_pagination($total_products, $limit, $paged);
$build_filter_url = function($sort_type = 'default', $keep_points = null) use ($base_url, $points_only) {
    $url = remove_query_arg(['paged', 'sort', 'points'], $base_url);
    if ($sort_type !== '' && $sort_type !== 'default') {
        $url = add_query_arg('sort', $sort_type, $url);
    }
    if ($keep_points === null) {
        $keep_points = $points_only;
    }
    if ($keep_points) {
        $url = add_query_arg('points', '1', $url);
    }
    return $url;
};

$all_products_url = qls_shop_public()->get_page_url('all_products');
if (empty($all_products_url)) {
    $all_products_url = qls_shop_public()->get_shop_url() . '?view=products';
}

$category_breadcrumb = $category ? qls_category()->get_breadcrumb($category->id) : [];
$root_category = !empty($category_breadcrumb) ? reset($category_breadcrumb) : $category;
$root_category_id = $root_category ? (int) $root_category->id : 0;
$top_categories = qls_category()->get_list([
    'parent_id' => 0,
    'status'    => 1,
    'orderby'   => 'sort_order',
    'order'     => 'DESC',
]);
$sub_categories = [];
$sub_parent = null;

if ($category) {
    $children = qls_category()->get_list([
        'parent_id' => (int) $category->id,
        'status'    => 1,
        'orderby'   => 'sort_order',
        'order'     => 'DESC',
    ]);

    if (!empty($children)) {
        $sub_categories = $children;
        $sub_parent = $category;
    } elseif ((int) $category->parent_id > 0) {
        $sub_parent = qls_category()->get((int) $category->parent_id);
        if ($sub_parent) {
            $sub_categories = qls_category()->get_list([
                'parent_id' => (int) $sub_parent->id,
                'status'    => 1,
                'orderby'   => 'sort_order',
                'order'     => 'DESC',
            ]);
        }
    }
}
?>
<div class="qls-shop-wrapper qls-product-list-page">
<div class="qls-container">
    <nav class="qls-breadcrumb">
        <a href="<?php echo esc_url(qls_shop_public()->get_shop_url()); ?>"><?php _e('商城', 'qilingshop'); ?></a>
        <span class="sep">›</span>
        <?php if ($category && $category->parent_id): 
            $parent = qls_category()->get($category->parent_id);
            if ($parent): ?>
            <a href="<?php echo esc_url(qls_shop_public()->get_category_url($parent)); ?>"><?php echo esc_html($parent->name); ?></a>
            <span class="sep">›</span>
            <?php endif; 
        endif; ?>
        <span class="current"><?php echo esc_html($page_title); ?></span>
    </nav>

    <div class="qls-shop-page-header">
        <h1 class="qls-page-title"><?php echo esc_html($page_title); ?></h1>
        <?php if ($category && !empty($category->description)): ?>
            <div class="qls-page-desc"><?php echo wp_kses_post($category->description); ?></div>
        <?php endif; ?>
    </div>

    <?php if (!empty($top_categories)): ?>
    <div class="qls-category-tabs-wrapper qls-category-tabs-wrapper--category-page">
        <div class="qls-category-tabs">
            <a href="<?php echo esc_url($all_products_url); ?>" class="qls-tab-item">
                <?php esc_html_e('全部商品', 'qilingshop'); ?>
            </a>
            <?php foreach ($top_categories as $top_category): ?>
                <?php $is_active_top = $root_category_id === (int) $top_category->id; ?>
                <a href="<?php echo esc_url(qls_shop_public()->get_category_url($top_category)); ?>" class="qls-tab-item <?php echo $is_active_top ? 'active' : ''; ?>">
                    <?php echo esc_html($top_category->name); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($sub_categories) && $sub_parent): ?>
        <div class="qls-subcategory-tabs">
            <?php $is_all_sub_active = $category && (int) $category->id === (int) $sub_parent->id; ?>
            <a href="<?php echo esc_url(qls_shop_public()->get_category_url($sub_parent)); ?>" class="qls-subtab-item <?php echo $is_all_sub_active ? 'active' : ''; ?>">
                <?php esc_html_e('全部', 'qilingshop'); ?>
            </a>
            <?php foreach ($sub_categories as $sub_category): ?>
                <?php $is_active_sub = $category && (int) $category->id === (int) $sub_category->id; ?>
                <a href="<?php echo esc_url(qls_shop_public()->get_category_url($sub_category)); ?>" class="qls-subtab-item <?php echo $is_active_sub ? 'active' : ''; ?>">
                    <?php echo esc_html($sub_category->name); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="qls-filter-bar">
        <div class="qls-sort-options">
            <?php
            $sort_options = [
                'default'     => __('默认', 'qilingshop'),
                'latest'      => __('最新上架', 'qilingshop'),
                'sales'       => __('销量优先', 'qilingshop'),
                'price_desc'  => __('价格从高到低', 'qilingshop'),
                'price_asc'   => __('价格从低到高', 'qilingshop'),
                'points_asc'  => __('积分从低到高', 'qilingshop'),
                'points_desc' => __('积分从高到低', 'qilingshop'),
            ];
            foreach ($sort_options as $key => $label) {
                $link = $build_filter_url($key, in_array($key, ['points_desc', 'points_asc'], true) ? true : false);
                echo '<a href="' . esc_url($link) . '" class="qls-sort-item ' . ($sort === $key ? 'active' : '') . '">' . esc_html($label) . '</a>';
            }
            ?>
        </div>

        <div class="qls-products-count">
            <?php
            if ($points_only) {
                printf(__('共找到 %d 件支持积分购买的商品', 'qilingshop'), $total_products);
            } else {
                printf(__('共找到 %d 件商品', 'qilingshop'), $total_products);
            }
            ?>
        </div>
    </div>

    <div class="qls-main-content">
        <?php if (!empty($products)): ?>
        <div class="qls-product-grid">
            <?php foreach ($products as $product): 
                qls_shop_public()->load_template('partials/product-card', ['product' => $product]);
            endforeach; ?>
        </div>

        <?php if ($pagination['total_pages'] > 1): ?>
        <div class="qls-pagination">
            <?php 
            echo paginate_links([
                'base' => str_replace(999999999, '%#%', esc_url(get_pagenum_link(999999999))),
                'format' => '?paged=%#%',
                'current' => $paged,
                'total' => $pagination['total_pages'],
                'add_args' => [
                    'sort' => $sort !== 'default' ? $sort : false,
                    'points' => in_array($sort, ['points_desc', 'points_asc'], true) ? 1 : false,
                ],
                'prev_text' => '&larr;',
                'next_text' => '&rarr;',
            ]);
            ?>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="qls-no-products">
            <p><?php echo esc_html($points_only ? __('该分类下暂无支持积分购买的商品', 'qilingshop') : __('该分类下暂无商品', 'qilingshop')); ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>

<?php qls_shop_public()->get_shop_footer(); ?>
