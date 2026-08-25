<?php
/**
 * 商品列表视图
 */
if (!defined('ABSPATH')) exit;

$current_status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
$current_category = isset($args['category_id']) ? (int) $args['category_id'] : 0;
$current_keyword = isset($args['keyword']) ? (string) $args['keyword'] : '';
$has_filters = $current_status !== '' || $current_category > 0 || $current_keyword !== '';
$bulk_result = isset($bulk_result) && is_array($bulk_result) ? $bulk_result : null;
$shipping_rules = isset($shipping_rules) && is_array($shipping_rules) ? $shipping_rules : [];
$service_tags = isset($service_tags) && is_array($service_tags) ? $service_tags : [];
$status_counts = isset($status_counts) && is_array($status_counts) ? $status_counts : [];
$category_name_map = isset($category_name_map) && is_array($category_name_map) ? $category_name_map : [];
$published_count = isset($status_counts[1]) ? (int) $status_counts[1] : 0;
$draft_count = isset($status_counts[0]) ? (int) $status_counts[0] : 0;
$pending_count = isset($status_counts[2]) ? (int) $status_counts[2] : 0;
$all_count = $published_count + $draft_count + $pending_count;
?>
<div class="wrap qilingshop-admin-page qls-shop-wrap">
    <div class="qls-page-header qls-page-header-inline">
        <div>
            <h1 class="wp-heading-inline qls-page-title"><?php _e('商品管理', 'qilingshop'); ?></h1>
            <p class="qls-page-intro"><?php _e('管理上架状态、库存、价格和小程序展示标签。', 'qilingshop'); ?></p>
        </div>
        <a href="<?php echo esc_url(admin_url('admin.php?page=qls-product-edit')); ?>" class="page-title-action"><?php _e('添加商品', 'qilingshop'); ?></a>
    </div>
    
    <?php if (!empty($bulk_result)): ?>
    <div class="notice <?php echo !empty($bulk_result['success']) ? 'notice-success' : 'notice-warning'; ?> is-dismissible">
        <p><?php echo esc_html($bulk_result['message'] ?? ''); ?></p>
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['message'])): ?>
    <?php
    $message = sanitize_key(wp_unslash($_GET['message']));
    $message_text = __('操作成功', 'qilingshop');
    if ($message === 'deleted') {
        $message_text = __('商品已删除', 'qilingshop');
    } elseif ($message === 'invalid_nonce') {
        $message_text = __('安全校验失败，请刷新页面后重试。', 'qilingshop');
    }
    ?>
    <div class="notice <?php echo $message === 'invalid_nonce' ? 'notice-error' : 'notice-success'; ?> is-dismissible">
        <p><?php echo esc_html($message_text); ?></p>
    </div>
    <?php endif; ?>
    
    <!-- 筛选栏 -->
    <div class="qls-filter-bar qls-toolbar qls-toolbar-between">
        <ul class="qls-chip-nav" aria-label="<?php esc_attr_e('商品状态筛选', 'qilingshop'); ?>">
            <li>
                <a href="<?php echo admin_url('admin.php?page=qls-products'); ?>" class="<?php echo $current_status === '' ? 'current' : ''; ?>">
                    <?php _e('全部', 'qilingshop'); ?>
                    <span class="count">(<?php echo esc_html($all_count); ?>)</span>
                </a>
            </li>
            <li>
                <a href="<?php echo admin_url('admin.php?page=qls-products&status=1'); ?>" class="<?php echo $current_status === '1' ? 'current' : ''; ?>">
                    <?php _e('已上架', 'qilingshop'); ?>
                    <span class="count">(<?php echo esc_html($published_count); ?>)</span>
                </a>
            </li>
            <li>
                <a href="<?php echo admin_url('admin.php?page=qls-products&status=0'); ?>" class="<?php echo $current_status === '0' ? 'current' : ''; ?>">
                    <?php _e('已下架', 'qilingshop'); ?>
                    <span class="count">(<?php echo esc_html($draft_count); ?>)</span>
                </a>
            </li>
        </ul>
        
        <form method="get" class="qls-search-form qls-toolbar-search">
            <input type="hidden" name="page" value="qls-products">
            <?php if ($current_status !== ''): ?>
            <input type="hidden" name="status" value="<?php echo esc_attr($current_status); ?>">
            <?php endif; ?>
            
            <select name="category">
                <option value=""><?php _e('全部分类', 'qilingshop'); ?></option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?php echo esc_attr($cat->id); ?>" <?php selected($args['category_id'], $cat->id); ?>>
                    <?php echo esc_html($cat->display_name); ?>
                </option>
                <?php endforeach; ?>
            </select>
            
            <input type="search" name="s" value="<?php echo esc_attr($args['keyword']); ?>" placeholder="<?php _e('搜索商品...', 'qilingshop'); ?>">
            <button type="submit" class="button"><?php _e('搜索', 'qilingshop'); ?></button>
            <?php if ($has_filters): ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=qls-products')); ?>" class="button button-secondary"><?php _e('清除筛选', 'qilingshop'); ?></a>
            <?php endif; ?>
        </form>
    </div>
    
    <!-- 商品列表 -->
    <form method="post" class="qls-bulk-form"
          data-bulk-delete-message="<?php echo esc_attr__('确定要删除选中的商品吗？删除后不可恢复。', 'qilingshop'); ?>"
          data-bulk-empty-message="<?php echo esc_attr__('请先勾选要操作的商品。', 'qilingshop'); ?>"
          data-bulk-action-message="<?php echo esc_attr__('请选择批量操作。', 'qilingshop'); ?>"
          data-bulk-edit-empty-message="<?php echo esc_attr__('请至少设置一个要批量修改的字段。', 'qilingshop'); ?>">
        <?php wp_nonce_field('bulk-products'); ?>

        <div class="qls-table-shell">
        <div class="tablenav top qls-table-toolbar">
            <div class="alignleft actions bulkactions qls-bulk-actions">
                <select name="action">
                    <option value="-1"><?php _e('批量操作', 'qilingshop'); ?></option>
                    <option value="publish"><?php _e('上架', 'qilingshop'); ?></option>
                    <option value="unpublish"><?php _e('下架', 'qilingshop'); ?></option>
                    <option value="bulk_edit"><?php _e('批量编辑', 'qilingshop'); ?></option>
                    <option value="delete"><?php _e('删除', 'qilingshop'); ?></option>
                </select>
                <button type="submit" class="button"><?php _e('应用', 'qilingshop'); ?></button>
                <span class="qls-selected-count"><?php _e('未选择商品', 'qilingshop'); ?></span>
            </div>
            
            <div class="tablenav-pages">
                <?php
                $total_pages = ceil($total / $args['limit']);
                $current_page = ($args['offset'] / $args['limit']) + 1;
                
                if ($total_pages > 1):
                    echo paginate_links([
                        'base'      => add_query_arg('paged', '%#%'),
                        'format'    => '',
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                        'total'     => $total_pages,
                        'current'   => $current_page,
                    ]);
                endif;
                ?>
            </div>
        </div>

        <div class="qls-bulk-edit-panel qls-hidden">
            <div class="qls-bulk-edit-head">
                <div>
                    <h2><?php _e('批量编辑商品', 'qilingshop'); ?></h2>
                    <p><?php _e('只会修改已设置的字段，选择“不修改”的字段会保持原样。价格和库存会应用到所选商品的全部 SKU。', 'qilingshop'); ?></p>
                </div>
            </div>

            <div class="qls-bulk-edit-grid">
                <div class="qls-bulk-edit-field">
                    <label for="bulk-status"><?php _e('商品状态', 'qilingshop'); ?></label>
                    <select id="bulk-status" name="bulk_edit[status]" class="qls-bulk-edit-control" data-default="">
                        <option value=""><?php _e('不修改', 'qilingshop'); ?></option>
                        <option value="1"><?php _e('上架', 'qilingshop'); ?></option>
                        <option value="0"><?php _e('下架', 'qilingshop'); ?></option>
                        <option value="2"><?php _e('审核中', 'qilingshop'); ?></option>
                    </select>
                </div>

                <div class="qls-bulk-edit-field">
                    <label for="bulk-category"><?php _e('商品分类', 'qilingshop'); ?></label>
                    <select id="bulk-category" name="bulk_edit[category_id]" class="qls-bulk-edit-control" data-default="__no_change">
                        <option value="__no_change"><?php _e('不修改分类', 'qilingshop'); ?></option>
                        <option value="0"><?php _e('设为未分类', 'qilingshop'); ?></option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo esc_attr($cat->id); ?>"><?php echo esc_html($cat->display_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="qls-bulk-edit-field">
                    <label for="bulk-shipping-rule"><?php _e('运费规则', 'qilingshop'); ?></label>
                    <select id="bulk-shipping-rule" name="bulk_edit[shipping_rule_id]" class="qls-bulk-edit-control" data-default="__no_change">
                        <option value="__no_change"><?php _e('不修改运费规则', 'qilingshop'); ?></option>
                        <option value="0"><?php _e('使用默认规则', 'qilingshop'); ?></option>
                        <?php foreach ($shipping_rules as $rule): ?>
                        <option value="<?php echo esc_attr($rule->id); ?>"><?php echo esc_html($rule->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php _e('仅影响实物商品，虚拟商品会自动跳过。', 'qilingshop'); ?></p>
                </div>

                <div class="qls-bulk-edit-field">
                    <label for="bulk-is-hot"><?php _e('热门推荐', 'qilingshop'); ?></label>
                    <select id="bulk-is-hot" name="bulk_edit[is_hot]" class="qls-bulk-edit-control" data-default="no_change">
                        <option value="no_change"><?php _e('不修改', 'qilingshop'); ?></option>
                        <option value="enable"><?php _e('开启', 'qilingshop'); ?></option>
                        <option value="disable"><?php _e('关闭', 'qilingshop'); ?></option>
                    </select>
                </div>

                <div class="qls-bulk-edit-field">
                    <label for="bulk-activity-recommend"><?php _e('活动推荐', 'qilingshop'); ?></label>
                    <select id="bulk-activity-recommend" name="bulk_edit[activity_recommend_enabled]" class="qls-bulk-edit-control" data-default="no_change">
                        <option value="no_change"><?php _e('不修改', 'qilingshop'); ?></option>
                        <option value="enable"><?php _e('开启', 'qilingshop'); ?></option>
                        <option value="disable"><?php _e('关闭', 'qilingshop'); ?></option>
                    </select>
                </div>

                <div class="qls-bulk-edit-field">
                    <label for="bulk-group-display"><?php _e('拼团展示', 'qilingshop'); ?></label>
                    <select id="bulk-group-display" name="bulk_edit[group_display_enabled]" class="qls-bulk-edit-control" data-default="no_change">
                        <option value="no_change"><?php _e('不修改', 'qilingshop'); ?></option>
                        <option value="enable"><?php _e('开启', 'qilingshop'); ?></option>
                        <option value="disable"><?php _e('关闭', 'qilingshop'); ?></option>
                    </select>
                </div>

                <div class="qls-bulk-edit-field">
                    <label for="bulk-assist-display"><?php _e('助力展示', 'qilingshop'); ?></label>
                    <select id="bulk-assist-display" name="bulk_edit[assist_display_enabled]" class="qls-bulk-edit-control" data-default="no_change">
                        <option value="no_change"><?php _e('不修改', 'qilingshop'); ?></option>
                        <option value="enable"><?php _e('开启', 'qilingshop'); ?></option>
                        <option value="disable"><?php _e('关闭', 'qilingshop'); ?></option>
                    </select>
                </div>

                <div class="qls-bulk-edit-field qls-bulk-edit-field-wide">
                    <label for="bulk-new-user-special-mode"><?php _e('新人专项价', 'qilingshop'); ?></label>
                    <div class="qls-bulk-inline-row">
                        <select id="bulk-new-user-special-mode" name="bulk_edit[new_user_special_mode]" class="qls-bulk-edit-control" data-default="no_change">
                            <option value="no_change"><?php _e('不修改', 'qilingshop'); ?></option>
                            <option value="enable"><?php _e('开启并设置价格', 'qilingshop'); ?></option>
                            <option value="disable"><?php _e('关闭新人专项价', 'qilingshop'); ?></option>
                        </select>
                        <input type="number" name="bulk_edit[new_user_special_price]" class="qls-bulk-edit-value" min="0" step="0.01" placeholder="<?php esc_attr_e('新人价', 'qilingshop'); ?>">
                    </div>
                </div>

                <div class="qls-bulk-edit-field qls-bulk-edit-field-wide">
                    <label for="bulk-price-mode"><?php _e('SKU价格', 'qilingshop'); ?></label>
                    <div class="qls-bulk-inline-row">
                        <select id="bulk-price-mode" name="bulk_edit[price_mode]" class="qls-bulk-edit-control" data-default="none">
                            <option value="none"><?php _e('不修改价格', 'qilingshop'); ?></option>
                            <option value="set"><?php _e('统一设为', 'qilingshop'); ?></option>
                            <option value="increase_amount"><?php _e('按金额增加', 'qilingshop'); ?></option>
                            <option value="decrease_amount"><?php _e('按金额减少', 'qilingshop'); ?></option>
                            <option value="increase_percent"><?php _e('按百分比增加', 'qilingshop'); ?></option>
                            <option value="decrease_percent"><?php _e('按百分比减少', 'qilingshop'); ?></option>
                        </select>
                        <input type="number" name="bulk_edit[price_value]" class="qls-bulk-edit-value" min="0" step="0.01" placeholder="<?php esc_attr_e('金额或百分比', 'qilingshop'); ?>">
                    </div>
                    <p class="description"><?php _e('会修改所选商品的所有 SKU 原价，并自动同步商品价格区间。', 'qilingshop'); ?></p>
                </div>

                <div class="qls-bulk-edit-field qls-bulk-edit-field-wide">
                    <label for="bulk-stock-mode"><?php _e('SKU库存', 'qilingshop'); ?></label>
                    <div class="qls-bulk-inline-row">
                        <select id="bulk-stock-mode" name="bulk_edit[stock_mode]" class="qls-bulk-edit-control" data-default="none">
                            <option value="none"><?php _e('不修改库存', 'qilingshop'); ?></option>
                            <option value="set"><?php _e('统一设为', 'qilingshop'); ?></option>
                            <option value="increase_amount"><?php _e('增加库存', 'qilingshop'); ?></option>
                            <option value="decrease_amount"><?php _e('减少库存', 'qilingshop'); ?></option>
                        </select>
                        <input type="number" name="bulk_edit[stock_value]" class="qls-bulk-edit-value" min="0" step="1" placeholder="<?php esc_attr_e('库存数量', 'qilingshop'); ?>">
                    </div>
                    <p class="description"><?php _e('会修改所选商品的所有 SKU 库存，并自动同步商品总库存。', 'qilingshop'); ?></p>
                </div>

                <div class="qls-bulk-edit-field qls-bulk-edit-field-wide">
                    <label for="bulk-sort-order-mode"><?php _e('排序值', 'qilingshop'); ?></label>
                    <div class="qls-bulk-inline-row">
                        <select id="bulk-sort-order-mode" name="bulk_edit[sort_order_mode]" class="qls-bulk-edit-control" data-default="none">
                            <option value="none"><?php _e('不修改排序', 'qilingshop'); ?></option>
                            <option value="set"><?php _e('统一设为', 'qilingshop'); ?></option>
                            <option value="increase_amount"><?php _e('增加排序值', 'qilingshop'); ?></option>
                            <option value="decrease_amount"><?php _e('减少排序值', 'qilingshop'); ?></option>
                        </select>
                        <input type="number" name="bulk_edit[sort_order_value]" class="qls-bulk-edit-value" min="0" step="1" placeholder="<?php esc_attr_e('排序值', 'qilingshop'); ?>">
                    </div>
                </div>

                <?php if (!empty($service_tags)): ?>
                <div class="qls-bulk-edit-field qls-bulk-edit-field-wide qls-bulk-edit-service-tags">
                    <label for="bulk-service-tags-mode"><?php _e('服务标签', 'qilingshop'); ?></label>
                    <div class="qls-bulk-inline-row">
                        <select id="bulk-service-tags-mode" name="bulk_edit[service_tags_mode]" class="qls-bulk-edit-control" data-default="no_change">
                            <option value="no_change"><?php _e('不修改服务标签', 'qilingshop'); ?></option>
                            <option value="replace"><?php _e('替换为所选标签', 'qilingshop'); ?></option>
                            <option value="add"><?php _e('追加所选标签', 'qilingshop'); ?></option>
                            <option value="remove"><?php _e('移除所选标签', 'qilingshop'); ?></option>
                        </select>
                    </div>
                    <div class="qls-bulk-service-tags-list">
                        <?php foreach ($service_tags as $tag): ?>
                        <label>
                            <input type="checkbox" name="bulk_edit[service_tag_ids][]" value="<?php echo esc_attr($tag->id); ?>">
                            <?php echo esc_html($tag->name); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="description"><?php _e('选择“替换为所选标签”且不勾选任何标签时，会清空商品服务标签。', 'qilingshop'); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <table class="wp-list-table qls-ui-table widefat fixed striped qls-products-table">
            <thead>
                <tr>
                    <td class="manage-column column-cb check-column">
                        <input type="checkbox" id="cb-select-all" class="qls-select-all">
                    </td>
                    <th class="column-image"><?php _e('图片', 'qilingshop'); ?></th>
                    <th class="column-title"><?php _e('商品标题', 'qilingshop'); ?></th>
                    <th class="column-price"><?php _e('价格', 'qilingshop'); ?></th>
                    <th class="column-stock"><?php _e('库存', 'qilingshop'); ?></th>
                    <th class="column-category"><?php _e('分类', 'qilingshop'); ?></th>
                    <th class="column-sales"><?php _e('销量', 'qilingshop'); ?></th>
                    <th class="column-status"><?php _e('状态', 'qilingshop'); ?></th>
                    <th class="column-date"><?php _e('日期', 'qilingshop'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                <tr>
                    <td colspan="9" class="no-items qls-empty-cell">
                        <div class="qls-empty-state-admin">
                            <strong><?php echo $has_filters ? esc_html__('没有找到匹配的商品', 'qilingshop') : esc_html__('还没有商品', 'qilingshop'); ?></strong>
                            <p><?php echo $has_filters ? esc_html__('换个关键词或清除筛选再试试。', 'qilingshop') : esc_html__('先添加第一个商品，再设置价格、库存和小程序展示。', 'qilingshop'); ?></p>
                            <div class="qls-empty-actions">
                                <?php if ($has_filters): ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=qls-products')); ?>" class="button"><?php _e('清除筛选', 'qilingshop'); ?></a>
                                <?php endif; ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=qls-product-edit')); ?>" class="button button-primary"><?php _e('添加商品', 'qilingshop'); ?></a>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($products as $product): ?>
                <tr>
                    <th class="check-column">
                        <input type="checkbox" name="product_ids[]" class="qls-row-check" value="<?php echo esc_attr($product->id); ?>">
                    </th>
                    <td class="column-image">
                        <?php 
                        $image_url = '';
                        if (is_array($product->main_image)) {
                            $image_url = $product->main_image['url'] ?? '';
                        } elseif (is_string($product->main_image)) {
                            $image_url = $product->main_image;
                        }
                        if ($image_url): ?>
                        <img src="<?php echo esc_url($image_url); ?>" alt="" class="product-thumb">
                        <?php else: ?>
                        <span class="no-image dashicons dashicons-format-image"></span>
                        <?php endif; ?>
                    </td>
                    <td class="column-title">
                        <strong>
                            <a href="<?php echo admin_url('admin.php?page=qls-product-edit&id=' . $product->id); ?>">
                                <?php echo esc_html($product->title); ?>
                            </a>
                        </strong>
                        <?php if ($product->subtitle): ?>
                        <div class="subtitle"><?php echo esc_html($product->subtitle); ?></div>
                        <?php endif; ?>
                        <?php
                        $miniapp_badges = [];
                        if (!empty($product->new_user_special_enabled)) {
                            $miniapp_badges[] = __('新人专享', 'qilingshop');
                        }
                        if (!empty($product->activity_recommend_enabled)) {
                            $miniapp_badges[] = __('活动推荐', 'qilingshop');
                        }
                        if (!empty($product->group_display_enabled)) {
                            $miniapp_badges[] = __('拼团展示', 'qilingshop');
                        }
                        if (!empty($product->assist_display_enabled)) {
                            $miniapp_badges[] = __('助力展示', 'qilingshop');
                        }
                        ?>
                        <?php if (!empty($miniapp_badges)): ?>
                        <div class="subtitle"><?php echo esc_html__('小程序：', 'qilingshop') . esc_html(implode(' / ', $miniapp_badges)); ?></div>
                        <?php endif; ?>
                        <div class="row-actions">
                            <span class="edit">
                                <a href="<?php echo admin_url('admin.php?page=qls-product-edit&id=' . $product->id); ?>"><?php _e('编辑', 'qilingshop'); ?></a> |
                            </span>
                            <span class="view">
                                <a href="<?php echo home_url('/shop/product/' . $product->slug); ?>" target="_blank"><?php _e('查看', 'qilingshop'); ?></a> |
                            </span>
                            <span class="delete">
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=qls-products&action=delete&id=' . $product->id), 'delete_product_' . $product->id); ?>" class="delete-link"><?php _e('删除', 'qilingshop'); ?></a>
                            </span>
                        </div>
                    </td>
                    <td class="column-price">
                        <?php if ($product->min_price == $product->max_price): ?>
                        ¥<?php echo number_format($product->min_price, 2); ?>
                        <?php else: ?>
                        ¥<?php echo number_format($product->min_price, 2); ?> - ¥<?php echo number_format($product->max_price, 2); ?>
                        <?php endif; ?>
                    </td>
                    <td class="column-stock">
                        <?php 
                        $stock_class = $product->total_stock <= 5 ? 'low-stock' : '';
                        ?>
                        <span class="<?php echo esc_attr($stock_class); ?>">
                            <?php echo esc_html($product->total_stock); ?>
                        </span>
                    </td>
                    <td class="column-category">
                        <?php 
                        if ($product->category_id) {
                            $category_name = isset($category_name_map[(int) $product->category_id]) ? (string) $category_name_map[(int) $product->category_id] : '';
                            echo $category_name !== '' ? esc_html($category_name) : '—';
                        } else {
                            echo '—';
                        }
                        ?>
                    </td>
                    <td class="column-sales"><?php echo esc_html($product->sales_count); ?></td>
                    <td class="column-status">
                        <?php if ($product->status == 1): ?>
                        <span class="status-badge success"><?php _e('已上架', 'qilingshop'); ?></span>
                        <?php elseif ($product->status == 2): ?>
                        <span class="status-badge warning"><?php _e('审核中', 'qilingshop'); ?></span>
                        <?php else: ?>
                        <span class="status-badge"><?php _e('已下架', 'qilingshop'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="column-date">
                        <?php echo date('Y-m-d', strtotime($product->created_at)); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </form>
</div>
