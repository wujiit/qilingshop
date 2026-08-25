<?php
/**
 * 全部商品列表页模板
 */
if (!defined('ABSPATH')) exit;

// 基础链接（用于拼接参数）
// 基础链接（用于拼接参数）
$base_url = get_permalink();

// 获取当前排序
$current_sort = get_query_var('qls_sort');
if (empty($current_sort) && isset($_GET['sort'])) {
    $current_sort = sanitize_text_field($_GET['sort']);
}
if (empty($current_sort)) {
    $current_sort = 'default';
}

$points_param = isset($_GET['points']) ? strtolower(trim(sanitize_text_field(wp_unslash($_GET['points'])))) : '';
$points_only = (isset($points_only) && !empty($points_only)) || in_array($points_param, ['1', 'yes', 'true', 'on'], true);

// 获取当前分类 (Fixed logic to check query var first)
$current_cat_slug = get_query_var('qls_category');
if (empty($current_cat_slug) && isset($_GET['category'])) {
    $current_cat_slug = sanitize_text_field($_GET['category']);
}

// 供面包屑使用
$current_category = null;
if ($current_cat_slug) {
    $current_category = qls_category()->get_by_slug($current_cat_slug);
}

// 处理筛选：传递给 Product Module
// IMPORTANT: Need to map 'current_sort' to proper arguments for get_list or module
// The logic below (Line 130+) in existing file probably used $sort and $current_category variables.
// I need to ensure $args for query are correct.

// 获取筛选参数 (From Query Vars first, then GET)
$qb_sort = get_query_var('qls_sort');
$sort = $qb_sort ? $qb_sort : (isset($_GET['sort']) ? sanitize_text_field($_GET['sort']) : 'default');
$allowed_sorts = ['default', 'latest', 'sales', 'hot', 'price_desc', 'price_asc', 'points_desc', 'points_asc'];
if (!in_array($sort, $allowed_sorts, true)) {
    $sort = 'default';
}
if (in_array($sort, ['points_desc', 'points_asc'], true)) {
    $points_only = true;
}
$current_sort = $sort;

$qb_category = get_query_var('qls_category');
$category_slug = $qb_category ? $qb_category : (isset($_GET['category']) ? sanitize_text_field($_GET['category']) : '');

$keyword = isset($_GET['keyword']) ? sanitize_text_field($_GET['keyword']) : '';
$paged = max(1, get_query_var('paged') ? get_query_var('paged') : (get_query_var('page') ? get_query_var('page') : 1));

// 构建查询参数
$args = [
    'status' => 1,
    'limit'  => 16, // Default limit
    'offset' => ($paged - 1) * 16,
    'keyword'=> $keyword,
];

// 排序逻辑
switch ($sort) {
    case 'sales': // 销量优先
        $args['orderby'] = 'sales_count';
        $args['order'] = 'DESC';
        break;
    case 'hot': // 热门优先
        $args['is_hot'] = 1;
        break;
    case 'price_asc': // 价格低到高
        $args['orderby'] = 'min_price';
        $args['order'] = 'ASC';
        break;
    case 'price_desc': // 价格高到低
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
    case 'latest':
    case 'default':
    default:
        $args['orderby'] = 'created_at';
        $args['order'] = 'DESC';
        break;
}

// 分类处理
$current_category = null;
if ($category_slug) {
    // If not already fetched above
    if (!$current_category) {
        $current_category = qls_category()->get_by_slug($category_slug);
    }
    if ($current_category) {
        $args['category_id'] = $current_category->id;
    }
}

if ($points_only) {
    $args['points_payable'] = 1;
}

// 获取商品
$products = qls_product()->get_list($args);
$total_products = qls_product()->get_count($args);
$total_pages = ceil($total_products / $args['limit']);

// 渲染头部（全屏模式）
qls_shop_public()->get_shop_header('', true);
?>
<div class="qls-shop-wrapper qls-all-products-page">
    <div class="qls-container">
        
        <!-- 面包屑 (Clean style) -->
        <nav class="qls-breadcrumb">
            <a href="<?php echo esc_url(qls_shop_public()->get_shop_url()); ?>"><?php _e('商城', 'qilingshop'); ?></a>
            <span class="sep">›</span>
            <span class="current"><?php echo isset($current_category) && $current_category ? esc_html($current_category->name) : (isset($search_query) && $search_query ? __('搜索结果', 'qilingshop') : __('全部商品', 'qilingshop')); ?></span>
        </nav>

        <!-- 分类导航 -->
        <div class="qls-category-tabs-wrapper">
            <div class="qls-category-tabs">
                <?php
                $get_seo_url = function($cat_slug = '', $sort_type = '', $keep_points = null) use ($base_url, $keyword, $points_only) {
                    $url = $base_url;
                    $url = untrailingslashit($url);
                    if ($cat_slug) {
                        $url .= '/category/' . $cat_slug;
                    }
                    if ($sort_type && $sort_type !== 'default') { 
                        $url .= '/' . $sort_type;
                    }
                    $url = trailingslashit($url);
                    
                    if ($keyword) {
                        $url = add_query_arg('keyword', $keyword, $url);
                    }
                    if ($keep_points === null) {
                        $keep_points = $points_only;
                    }
                    if ($keep_points) {
                        $url = add_query_arg('points', '1', $url);
                    } else {
                        $url = remove_query_arg('points', $url);
                    }
                    
                    return $url;
                };

                // "All" tab
                $all_link = $get_seo_url('');
                ?>
                <a href="<?php echo esc_url($all_link); ?>" class="qls-tab-item <?php echo empty($current_cat_slug) ? 'active' : ''; ?>">
                    <?php _e('全部', 'qilingshop'); ?>
                </a>
                <?php
                // 获取顶级分类
                $top_categories = qls_category()->get_list([
                    'parent_id' => 0, 
                    'status' => 1, 
                    'orderby' => 'sort_order', 
                    'order' => 'DESC'
                ]);
                
                foreach ($top_categories as $cat) {
                    $is_active = ($current_cat_slug === $cat->slug) || ($current_category && $current_category->parent_id == $cat->id);
                    $link = $get_seo_url($cat->slug, '');
                    echo '<a href="' . esc_url($link) . '" class="qls-tab-item ' . ($is_active ? 'active' : '') . '">' . esc_html($cat->name) . '</a>';
                }
                ?>
            </div>

            <?php
            // 子分类处理，同一容器内作为第二行展示
            $sub_categories = [];
            $sub_parent = null;

            if ($current_category) {
                // 1. 尝试获取当前分类的子分类
                $children = qls_category()->get_list([
                    'parent_id' => $current_category->id,
                    'status'    => 1,
                    'orderby'   => 'sort_order',
                    'order'     => 'DESC'
                ]);

                if (!empty($children)) {
                    $sub_categories = $children;
                    $sub_parent = $current_category;
                } elseif ($current_category->parent_id != 0) {
                    // 2. 如果当前没有子分类且不是一级分类，显示同级分类（即父分类的子分类）
                    $siblings = qls_category()->get_list([
                        'parent_id' => $current_category->parent_id,
                        'status'    => 1,
                        'orderby'   => 'sort_order',
                        'order'     => 'DESC'
                    ]);
                    $sub_categories = $siblings;
                    // 获取父对象用于"全部"链接
                    $sub_parent = qls_category()->get($current_category->parent_id);
                }
            }

            if (!empty($sub_categories)): 
            ?>
            <div class="qls-subcategory-tabs">
                <?php
                // "全部" (回到父分类)
                $parent_link = $get_seo_url($sub_parent->slug, '');
                $is_all_active = ($current_category->id == $sub_parent->id);
                ?>
                <a href="<?php echo esc_url($parent_link); ?>" class="qls-subtab-item <?php echo $is_all_active ? 'active' : ''; ?>">
                    <?php _e('全部', 'qilingshop'); ?>
                </a>
                
                <?php foreach ($sub_categories as $sub): 
                    $is_active = ($current_category->id == $sub->id);
                    $link = $get_seo_url($sub->slug, '');
                ?>
                    <a href="<?php echo esc_url($link); ?>" class="qls-subtab-item <?php echo $is_active ? 'active' : ''; ?>">
                        <?php echo esc_html($sub->name); ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>



        <!-- 筛选工具栏 -->
        <div class="qls-filter-bar">
            <!-- 排序选项 -->
            <div class="qls-sort-options">
                <?php
                $sort_options = [
                    'default'    => __('默认', 'qilingshop'),
                    'latest'     => __('最新上架', 'qilingshop'),
                    'sales'      => __('销量优先', 'qilingshop'),
                    'hot'        => __('热门推荐', 'qilingshop'),
                    'price_desc' => __('价格从高到低', 'qilingshop'),
                    'price_asc'  => __('价格从低到高', 'qilingshop'),
                    'points_asc' => __('积分从低到高', 'qilingshop'),
                    'points_desc'=> __('积分从高到低', 'qilingshop'),
                ];
                
                foreach ($sort_options as $key => $label) {
                    $class = ($current_sort === $key) ? 'active' : '';
                    $link = $get_seo_url($current_cat_slug, $key, in_array($key, ['points_desc', 'points_asc'], true) ? true : false);
                    echo '<a href="' . esc_url($link) . '" class="qls-sort-item ' . $class . '" data-sort="' . esc_attr($key) . '">' . $label . '</a>';
                }
                ?>
            </div>
            
            <!-- 搜索框 -->
            <div class="qls-search-box">
                <input type="text" id="qls-product-search" placeholder="<?php esc_attr_e('搜索商品...', 'qilingshop'); ?>" value="<?php echo esc_attr($keyword); ?>">
                <button type="button" id="qls-search-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                </button>
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

        <!-- 商品列表 -->
        <?php if (!empty($products)): ?>
            <div class="qls-product-list-wrapper qls-grid-4">
                <?php 
                foreach ($products as $product) {
                    // 复用商品列表模块的卡片结构，保持商城列表样式一致。
                    
                    $link = qls_shop_public()->get_product_url($product);
                    $thumb = '';
                    if (!empty($product->main_image)) {
                         if (is_array($product->main_image)) $thumb = $product->main_image['url'] ?? '';
                         elseif (is_string($product->main_image)) $thumb = $product->main_image;
                    }
                    if (empty($thumb) && !empty($product->gallery) && is_array($product->gallery)) {
                        $first = reset($product->gallery);
                        if (is_array($first)) $thumb = $first['url'] ?? '';
                        elseif (is_string($first)) $thumb = $first;
                    }
                    
                    $min_price = $product->min_price;
                    $max_price = $product->max_price;
                    $price_html = (!is_user_logged_in() && get_option('qls_shop_price_login_required', false))
                        ? '<span class="price-login-required">' . esc_html__('登录后查看价格', 'qilingshop') . '</span>'
                        : '<span class="curr">¥' . $min_price . '</span>';
                    $points_price_html = '';
                    if ((is_user_logged_in() || !get_option('qls_shop_price_login_required', false)) && !empty($product->points_price) && floatval($product->points_price) > 0) {
                        $points_name = function_exists('qilingshop_get_points_name') ? qilingshop_get_points_name() : __('积分', 'qilingshop');
                        $points_price_html = '<div class="qls-product-points-price">' . sprintf(__('积分价 %1$s %2$s', 'qilingshop'), number_format_i18n(floatval($product->points_price), 0), esc_html($points_name)) . '</div>';
                    }
                         
                    ?>
                    <div class="qls-product-card">
                        <div class="qls-product-image">
                            <a href="<?php echo esc_url($link); ?>">
                                <?php if ($product->is_hot): ?>
                                <span class="qls-badge qls-badge--hot"><?php esc_html_e('热卖', 'qilingshop'); ?></span>
                                <?php endif; ?>
                                <?php if ($thumb): ?>
                                <img src="<?php echo esc_url($thumb); ?>" alt="<?php echo esc_attr($product->title); ?>" loading="lazy">
                                <?php else: ?>
                                <div class="qls-image-placeholder"></div>
                                <?php endif; ?>
                            </a>
                        </div>
                        <div class="qls-product-info">
                            <h3 class="qls-product-title">
                                <a href="<?php echo esc_url($link); ?>"><?php echo esc_html($product->title); ?></a>
                            </h3>
                            <div class="qls-product-meta">
                                <div class="qls-product-price">
                                    <?php echo $price_html; ?>
                                    <?php echo $points_price_html; ?>
                                </div>
                                <div class="qls-product-sales">
                                    <?php printf(__('销量 %d', 'qilingshop'), $product->sales_count); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php
                }
                ?>
            </div>

            <!-- 分页 -->
            <?php if ($total_pages > 1): ?>
            <div class="qls-pagination">
                <?php
                echo paginate_links([
                    'base'    => add_query_arg('paged', '%#%'),
                    'format'  => '',
                    'current' => $paged,
                    'total'   => $total_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'type'      => 'list',
                ]);
                ?>
            </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="qls-empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" style="color: #cbd5e1; margin-bottom: 20px;"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
                <p><?php _e('暂无相关商品', 'qilingshop'); ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php qls_shop_public()->get_shop_footer(); ?>
