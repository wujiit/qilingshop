<?php
/**
 * 商品详情页模板
 */
if (!defined('ABSPATH')) exit;

// 获取完整商品信息（用于页面内容和 SEO）
$product = qls_product()->get($product->id, true, true);
$category = $product->category_id ? qls_category()->get($product->category_id) : null;
$show_stock = (bool) get_option('qls_shop_show_stock', true);
$service_tags = $product->service_tags ? QLS_Shop_Database::instance()->get_results('service_tags', [
    'where' => ['id' => ['operator' => 'IN', 'value' => $product->service_tags], 'status' => 1]
]) : [];

// SEO: 修改页面标题
add_filter('pre_get_document_title', function() use ($product) {
    return $product->title . ' - ' . get_bloginfo('name');
}, 999);

// SEO: 添加元标签
add_action('wp_head', function() use ($product) {
    $raw_content = trim(wp_strip_all_tags(strip_shortcodes((string) ($product->content ?? ''))));
    $raw_subtitle = trim((string) ($product->subtitle ?? ''));
    $description_text = '';

    // 优先用详情内容前 80 字符作为描述，无内容再回退副标题
    if ($raw_content !== '') {
        $description_text = wp_html_excerpt($raw_content, 80, '...');
    } elseif ($raw_subtitle !== '') {
        $description_text = $raw_subtitle;
    } else {
        $description_text = (string) ($product->title ?? '');
    }
    $description = esc_attr($description_text);

    // 关键词优先使用商品特色标签（商品标签），并补充分类与参数值
    $keywords = [];
    $feature_tags = qls_product()->get_tags((int) $product->id);
    if (!empty($feature_tags)) {
        foreach ($feature_tags as $tag) {
            $name = trim((string) ($tag->name ?? ''));
            if ($name !== '') {
                $keywords[] = $name;
            }
        }
    }

    if ($product->category_id) {
        $cat = qls_category()->get($product->category_id);
        if ($cat && !empty($cat->name)) {
            $keywords[] = (string) $cat->name;
        }
    }

    if (!empty($product->params) && is_array($product->params)) {
        foreach ($product->params as $param) {
            $value = trim((string) ($param['value'] ?? ''));
            if ($value !== '') {
                $keywords[] = $value;
            }
        }
    }

    $keywords = array_values(array_unique(array_filter($keywords)));
    $keywords_str = esc_attr(implode(',', array_slice($keywords, 0, 12)));

    // 主图URL
    $image_url = '';
    if (is_array($product->main_image)) {
        $image_url = $product->main_image['url'] ?? '';
    } else {
        $image_url = $product->main_image;
    }

    $product_url = qls_shop_public()->get_product_url($product);

    echo '<meta name="description" content="' . $description . '">' . "\n";
    if ($keywords_str !== '') {
        echo '<meta name="keywords" content="' . $keywords_str . '">' . "\n";
    }
    echo '<link rel="canonical" href="' . esc_url($product_url) . '">' . "\n";

    // Open Graph
    echo '<meta property="og:title" content="' . esc_attr($product->title) . '">' . "\n";
    echo '<meta property="og:description" content="' . $description . '">' . "\n";
    echo '<meta property="og:type" content="product">' . "\n";
    if ($image_url) {
        echo '<meta property="og:image" content="' . esc_url($image_url) . '">' . "\n";
    }
    echo '<meta property="og:url" content="' . esc_url($product_url) . '">' . "\n";
    echo '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) . '">' . "\n";
}, 20);

qls_shop_public()->get_shop_header('', true);

$main_image = '';
if (is_array($product->main_image)) {
    $main_image = $product->main_image['url'] ?? '';
    $is_video = ($product->main_image['type'] ?? '') === 'video';
} else {
    $main_image = $product->main_image;
    $is_video = false;
}

$default_sku = null;
foreach ($product->skus as $sku) {
    if ($sku->is_default) {
        $default_sku = $sku;
        break;
    }
}
if (!$default_sku && !empty($product->skus)) {
    $default_sku = $product->skus[0];
}

// 计算价格显示逻辑
$sku_count = count($product->skus);
$all_original_prices = [];  // 所有原价
$all_actual_prices = [];    // 所有实际价格（有促销用促销，无促销用原价）
$has_any_sale = false;

foreach ($product->skus as $sku) {
    $price = floatval($sku->price);
    $sale = floatval($sku->sale_price);
    $all_original_prices[] = $price;
    
    if ($sale > 0 && $sale < $price) {
        $all_actual_prices[] = $sale;
        $has_any_sale = true;
    } else {
        $all_actual_prices[] = $price;
    }
}

$min_original = min($all_original_prices);
$max_original = max($all_original_prices);
$min_actual = min($all_actual_prices);
$max_actual = max($all_actual_prices);

$is_same_original_price = ($min_original == $max_original);  // 原价是否相同
$is_same_actual_price = ($min_actual == $max_actual);        // 实际价是否相同

// 确定显示价格和原价
$display_price = '';
$original_price = '';
$show_original = false;

if ($sku_count == 1) {
    // 单规格
    $sku = $product->skus[0];
    $price = floatval($sku->price);
    $sale = floatval($sku->sale_price);
    
    if ($sale > 0 && $sale < $price) {
        // 有促销价
        $display_price = number_format($sale, 2);
        $original_price = number_format($price, 2);
        $show_original = true;
    } else {
        // 无促销价
        $display_price = number_format($price, 2);
    }
} else {
    // 多规格
    if ($is_same_actual_price) {
        // 实际价格相同 - 显示单一价格
        $display_price = number_format($min_actual, 2);
        
        // 如果有促销价且原价不同，显示原价
        if ($has_any_sale && !$is_same_original_price) {
            $original_price = number_format($max_original, 2);
            $show_original = true;
        }
    } else {
        // 实际价格不同 - 显示价格区间
        $display_price = number_format($min_actual, 2) . ' - ' . number_format($max_actual, 2);
    }
}

$new_user_special_enabled = qls_product()->is_new_user_special_enabled($product);
$new_user_special_price = qls_product()->get_new_user_special_price($product);
$new_user_special_eligible = is_user_logged_in() ? qls_product()->is_user_eligible_for_new_user_special(get_current_user_id()) : false;
$new_user_special_active = $new_user_special_enabled && $new_user_special_eligible && $new_user_special_price > 0;
$shop_price_login_required = (bool) get_option('qls_shop_price_login_required', false);
$shop_price_hidden = $shop_price_login_required && !is_user_logged_in();

// 当前商品的助力活动（用于商品详情提示与左侧引导卡）
$assist_activity = null;
$assist_login_url = wp_login_url(get_permalink());
$assist_center_url = qls_shop_public()->get_page_url('assist-center');
if (empty($assist_center_url)) {
    $assist_center_url = qls_shop_public()->get_shop_url();
}

if (function_exists('qls_assist') && class_exists('QLS_Assist')) {
    $assist_activities = qls_assist()->get_activities([
        'status' => QLS_Assist::ACTIVITY_ENABLED,
        'time_active' => true,
        'product_status' => 1,
        'product_id' => (int) $product->id,
        'limit' => 1,
        'offset' => 0,
    ]);

    if (!empty($assist_activities)) {
        $assist_activity = $assist_activities[0];
    }
}

if ($new_user_special_active) {
    $display_price = number_format($new_user_special_price, 2);

    if ($is_same_actual_price) {
        $original_price = number_format($min_actual, 2);
    } else {
        $original_price = number_format($min_actual, 2) . ' - ' . number_format($max_actual, 2);
    }
    $show_original = true;
} elseif (is_user_logged_in()) {
    $effective_prices = [];
    $frontend_effective_price_map = [];
    $has_vip_effective_price = false;

    foreach ($product->skus as $sku) {
        $effective_price = qls_product()->get_effective_sku_price($product, $sku, get_current_user_id());
        $frontend_effective_price_map[(int) ($sku->id ?? 0)] = $effective_price;
        $current_price = isset($effective_price['price']) ? (float) $effective_price['price'] : 0;
        if ($current_price > 0) {
            $effective_prices[] = $current_price;
        }
        if (!empty($effective_price['is_vip_price'])) {
            $has_vip_effective_price = true;
        }
    }

    if ($has_vip_effective_price && !empty($effective_prices)) {
        $min_effective = min($effective_prices);
        $max_effective = max($effective_prices);

        if ($sku_count == 1) {
            $display_price = number_format($min_effective, 2);
        } elseif ($min_effective == $max_effective) {
            $display_price = number_format($min_effective, 2);
        } else {
            $display_price = number_format($min_effective, 2) . ' - ' . number_format($max_effective, 2);
        }

        if ($is_same_actual_price) {
            $original_price = number_format($min_actual, 2);
        } else {
            $original_price = number_format($min_actual, 2) . ' - ' . number_format($max_actual, 2);
        }
        $show_original = true;
    }
}

$frontend_sku_payload = [
    'product_id' => (int) $product->id,
    'skus'       => [],
];
$frontend_price_hidden = (bool) $shop_price_hidden;
$current_user_id = is_user_logged_in() ? (int) get_current_user_id() : 0;
$frontend_effective_price_map = isset($frontend_effective_price_map) && is_array($frontend_effective_price_map) ? $frontend_effective_price_map : [];
foreach ((array) $product->skus as $sku) {
    $sku_id = (int) ($sku->id ?? 0);
    $sku_attrs = isset($sku->attr_values) && is_array($sku->attr_values) ? $sku->attr_values : [];
    $effective_price = isset($frontend_effective_price_map[$sku_id])
        ? $frontend_effective_price_map[$sku_id]
        : qls_product()->get_effective_sku_price($product, $sku, $current_user_id);

    $frontend_sku_payload['skus'][] = [
        'id'              => $sku_id,
        'sku_code'        => (string) ($sku->sku_code ?? ''),
        'attr_values'     => $sku_attrs,
        'price'           => $frontend_price_hidden ? 0 : (float) ($sku->price ?? 0),
        'sale_price'      => $frontend_price_hidden ? 0 : (float) ($sku->sale_price ?? 0),
        'base_price'      => $frontend_price_hidden ? 0 : (isset($effective_price['base_price']) ? (float) $effective_price['base_price'] : (float) ($sku->price ?? 0)),
        'effective_price' => $frontend_price_hidden ? 0 : (isset($effective_price['price']) ? (float) $effective_price['price'] : (float) ($sku->price ?? 0)),
        'is_vip_price'    => !$frontend_price_hidden && !empty($effective_price['is_vip_price']),
        'vip_price'       => $frontend_price_hidden ? 0 : (isset($effective_price['vip_price']) ? (float) $effective_price['vip_price'] : 0),
        'vip_level_id'    => isset($effective_price['vip_level_id']) ? (int) $effective_price['vip_level_id'] : 0,
        'price_source'    => isset($effective_price['price_source']) ? (string) $effective_price['price_source'] : 'base',
        'points_price'    => $frontend_price_hidden ? 0 : (float) ($sku->points_price ?? 0),
        'stock'           => (int) ($sku->stock ?? 0),
        'image'           => (string) ($sku->image ?? ''),
        'is_default'      => !empty($sku->is_default),
    ];
}
?>




<div class="qls-shop-wrapper qls-product-single-page">
    <div class="qls-container">
        <!-- 面包屑 -->
        <nav class="qls-breadcrumb">
            <a href="<?php echo esc_url(qls_shop_public()->get_shop_url()); ?>"><?php _e('商城', 'qilingshop'); ?></a>
            <?php if ($category): ?>
            <span class="sep">›</span>
            <a href="<?php echo esc_url(qls_shop_public()->get_category_url($category)); ?>"><?php echo esc_html($category->name); ?></a>
            <?php endif; ?>
            <span class="sep">›</span>
            <span class="current"><?php echo esc_html($product->title); ?></span>
        </nav>
        
        <?php
        $detail_classes = [
            $sku_count > 1 ? 'is-multi-sku' : 'is-single-sku',
        ];
        if (!empty($assist_activity)) {
            $detail_classes[] = 'is-assist-product';
        }
        ?>
        <div class="qls-product-detail <?php echo esc_attr(implode(' ', $detail_classes)); ?>">
            <!-- 左侧图片 -->
            <div class="qls-product-gallery">
                <div class="qls-main-image">
                    <?php if ($is_video): ?>
                    <video src="<?php echo esc_url($main_image); ?>" controls poster=""></video>
                    <?php else: ?>
                    <img src="<?php echo esc_url($main_image); ?>" alt="<?php echo esc_attr($product->title); ?>" id="qls-main-img">
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($product->gallery)): ?>
                <div class="qls-thumb-list">
                    <?php if ($main_image && !$is_video): ?>
                    <div class="qls-thumb active" data-image="<?php echo esc_url($main_image); ?>">
                        <img src="<?php echo esc_url($main_image); ?>" alt="">
                    </div>
                    <?php endif; ?>
                    <?php foreach ($product->gallery as $img): 
                        // gallery元素可能是数组或字符串
                        $img_url = is_array($img) ? ($img['url'] ?? '') : $img;
                        $img_type = is_array($img) ? ($img['type'] ?? 'image') : 'image';
                        if (empty($img_url)) continue;
                        
                        // 避免重复显示主图
                        if ($img_url === $main_image) continue;
                    ?>
                    <div class="qls-thumb <?php echo $img_type === 'video' ? 'is-video' : ''; ?>" data-image="<?php echo esc_url($img_url); ?>" data-type="<?php echo esc_attr($img_type); ?>">
                        <?php if ($img_type === 'video'): ?>
                        <span class="dashicons dashicons-video-alt3"></span>
                        <?php else: ?>
                        <img src="<?php echo esc_url($img_url); ?>" alt="">
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ($sku_count > 1): ?>
                <div class="qls-gallery-note">
                    <div class="qls-gallery-note-title"><?php _e('选购提示', 'qilingshop'); ?></div>
                    <p>
                        <?php
                        echo $show_stock
                            ? esc_html__('多规格商品请先选择规格与版本，右侧价格和库存会实时变化。', 'qilingshop')
                            : esc_html__('多规格商品请先选择规格与版本，右侧价格会实时变化。', 'qilingshop');
                        ?>
                    </p>
                </div>
                <?php endif; ?>

                <?php if (!empty($assist_activity)): ?>
                <?php
                $assist_target_helpers_preview = max(0, (int) ($assist_activity->target_helpers ?? 0));
                $assist_expire_hours_preview = max(1, (int) ($assist_activity->expire_hours ?? 24));
                ?>
                <div class="qls-gallery-assist-guide">
                    <div class="qls-gallery-assist-guide-title"><?php _e('助力玩法', 'qilingshop'); ?></div>
                    <ul class="qls-gallery-assist-guide-list">
                        <li><?php _e('发起活动后将助力链接分享给好友', 'qilingshop'); ?></li>
                        <li><?php echo $assist_target_helpers_preview > 0 ? esc_html(sprintf(__('%d 位好友助力即可达标', 'qilingshop'), $assist_target_helpers_preview)) : esc_html__('助力完成即可解锁活动低价', 'qilingshop'); ?></li>
                        <li><?php echo esc_html(sprintf(__('发起后 %d 小时内有效，超时自动失败', 'qilingshop'), $assist_expire_hours_preview)); ?></li>
                    </ul>
                    <?php if (!empty($assist_center_url)): ?>
                    <a class="qls-gallery-assist-guide-link" href="<?php echo esc_url($assist_center_url); ?>">
                        <?php _e('查看更多助力活动', 'qilingshop'); ?>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            </div>
            
            <!-- 右侧信息 -->
            <div class="qls-product-summary">
                <h1 class="qls-product-title"><?php echo esc_html($product->title); ?></h1>
                
                <?php if ($product->subtitle): ?>
                <p class="qls-product-subtitle"><?php echo esc_html($product->subtitle); ?></p>
                <?php endif; ?>
                
                <!-- 价格 -->
                <div class="qls-price-box">
                    <span class="qls-current-price" id="qls-current-price">
                        <?php echo $shop_price_hidden ? esc_html__('登录后查看价格', 'qilingshop') : '¥' . esc_html($display_price); ?>
                    </span>
                    <?php if (!$shop_price_hidden && $show_original && $original_price): ?>
                    <span class="qls-original-price" id="qls-original-price">¥<?php echo esc_html($original_price); ?></span>
                    <?php else: ?>
                    <span class="qls-original-price" id="qls-original-price" style="display:none;"></span>
                    <?php endif; ?>
                    <?php if ($new_user_special_enabled): ?>
                    <span class="qls-product-new-user-flag <?php echo $new_user_special_active ? 'active' : 'inactive'; ?>">
                        <?php echo $new_user_special_active ? __('新人专项价', 'qilingshop') : __('新人专项', 'qilingshop'); ?>
                    </span>
                    <?php endif; ?>
                    
                    <?php if (!$shop_price_hidden && !$new_user_special_active && $default_sku && floatval($default_sku->points_price) > 0): ?>
                    <span class="qls-points-price" id="qls-points-price"><?php printf(__('或 %s 积分', 'qilingshop'), $default_sku->points_price); ?></span>
                    <?php else: ?>
                    <span class="qls-points-price" id="qls-points-price" style="display:none;"></span>
                    <?php endif; ?>
                </div>

                <?php if ($new_user_special_enabled): ?>
                <div class="qls-product-new-user-tip">
                    <?php if ($new_user_special_active): ?>
                        <?php _e('当前符合新人资格，结账将自动按新人专项价结算（限购 1 件）。', 'qilingshop'); ?>
                    <?php elseif (!is_user_logged_in()): ?>
                        <?php _e('该商品支持新人专项价，请登录后自动判断是否可享。', 'qilingshop'); ?>
                    <?php else: ?>
                        <?php _e('该商品支持新人专项价，当前不满足新人资格。', 'qilingshop'); ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php 
                // 获取团购规则
                $group_rule = qls_group()->get_active_rule_by_product($product->id);
                $active_groups = [];
                $group_stock = 0;
                $activity_time_text = '';
                $activity_end_timestamp = 0;
                if ($group_rule) {
                    $active_groups = qls_group()->get_active_groups_by_product($product->id, 5);
                    $group_stock = qls_group()->get_rule_stock($group_rule->id);
                    $activity_start_timestamp = !empty($group_rule->start_time) ? strtotime($group_rule->start_time) : 0;
                    $activity_end_timestamp = !empty($group_rule->end_time) ? strtotime($group_rule->end_time) : 0;
                    $date_format = get_option('date_format') . ' ' . get_option('time_format');
                    if ($activity_start_timestamp && $activity_end_timestamp) {
                        $activity_time_text = sprintf(__('活动时间：%s - %s', 'qilingshop'), date_i18n($date_format, $activity_start_timestamp), date_i18n($date_format, $activity_end_timestamp));
                    } elseif ($activity_end_timestamp) {
                        $activity_time_text = sprintf(__('活动截止：%s', 'qilingshop'), date_i18n($date_format, $activity_end_timestamp));
                    } elseif ($activity_start_timestamp) {
                        $activity_time_text = sprintf(__('活动开始：%s', 'qilingshop'), date_i18n($date_format, $activity_start_timestamp));
                    }
                }
                
                // 检查URL参数是否要参加某个团
                $join_group_id = isset($_GET['join_group']) ? intval($_GET['join_group']) : 0;

                ?>
                
                <?php if ($group_rule): ?>
                <!-- 团购区域 -->
                <div class="qls-product-group-section" data-activity-end="<?php echo esc_attr($activity_end_timestamp); ?>">
                    <div class="qls-product-group-header">
                        <span class="qls-product-group-tag">🔥 <?php printf(esc_html__('%s人团', 'qilingshop'), esc_html($group_rule->group_size)); ?></span>
                        <span class="qls-product-group-time-limit">
                            <?php printf(__('满%d人成团，%d小时内有效', 'qilingshop'), $group_rule->group_size, $group_rule->time_limit); ?>
                        </span>
                    </div>
                    
                    <div class="qls-product-group-price-row">
                        <?php if ($shop_price_hidden): ?>
                        <span class="qls-product-group-price"><?php _e('登录后查看价格', 'qilingshop'); ?></span>
                        <?php else: ?>
                        <span class="qls-product-group-price">¥<?php echo number_format($group_rule->group_price, 2); ?></span>
                        <span class="qls-product-group-original">¥<?php echo esc_html($display_price); ?></span>
                        <?php endif; ?>
                        <?php 
                        $save_amount = floatval($min_actual) - floatval($group_rule->group_price);
                        if (!$shop_price_hidden && $save_amount > 0):
                        ?>
                        <span class="qls-product-group-save"><?php printf(__('拼团立省 ¥%.2f', 'qilingshop'), $save_amount); ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="qls-product-group-stock" data-rule-id="<?php echo $group_rule->id; ?>" data-product-id="<?php echo $product->id; ?>"<?php echo $show_stock ? '' : ' hidden'; ?>>
                        <span class="qls-product-group-stock-label"><?php _e('团购库存', 'qilingshop'); ?></span>
                        <span class="qls-product-group-stock-value" id="qls-group-stock-value"><?php echo intval($group_stock); ?></span>
                        <span class="qls-product-group-stock-unit"><?php _e('件', 'qilingshop'); ?></span>
                        <span class="qls-product-group-stock-status" id="qls-group-stock-status" <?php echo $group_stock > 0 ? 'style="display:none;"' : ''; ?>>
                            <?php _e('团购已结束', 'qilingshop'); ?>
                        </span>
                        <?php if (!empty($activity_time_text)): ?>
                        <span class="qls-product-group-activity-time"><?php echo esc_html($activity_time_text); ?></span>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($group_stock > 0 && !empty($active_groups)): ?>
                    <!-- 正在开团的列表 -->
                    <div class="qls-product-groups-list">
                        <div class="qls-product-groups-title"><?php _e('正在开团，差1人就成团', 'qilingshop'); ?></div>
                        <?php foreach ($active_groups as $ag): ?>
                        <div class="qls-product-group-item" data-group-id="<?php echo $ag->id; ?>">
                            <div class="qls-product-group-leader">
                                <img src="<?php echo esc_url($ag->leader_avatar); ?>" alt="">
                                <span><?php printf(esc_html__('%s的团', 'qilingshop'), esc_html($ag->leader_name)); ?></span>
                            </div>
                            <div class="qls-product-group-info">
                                <div class="qls-product-group-remain">
                                    <?php printf(__('还差%d人', 'qilingshop'), $ag->remain_count); ?>
                                </div>
                                <div class="qls-product-group-time" data-seconds="<?php echo $ag->remain_seconds; ?>">
                                    <?php 
                                    $hours = floor($ag->remain_seconds / 3600);
                                    $mins = floor(($ag->remain_seconds % 3600) / 60);
                                    echo sprintf(__('剩余 %02d:%02d:%02d', 'qilingshop'), $hours, $mins, $ag->remain_seconds % 60);
                                    ?>
                                </div>
                            </div>
                            <button type="button" class="qls-join-btn qls-join-group-btn" 
                                    data-group-id="<?php echo $ag->id; ?>"
                                    data-product-id="<?php echo $product->id; ?>">
                                <?php _e('去拼团', 'qilingshop'); ?>
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- 发起拼团按钮 -->
                    <button type="button" class="qls-start-group-btn" id="qls-start-group-btn"
                            data-product-id="<?php echo $product->id; ?>"
                            data-rule-id="<?php echo $group_rule->id; ?>" <?php echo $group_stock > 0 ? '' : 'disabled'; ?>>
                        <?php echo $group_stock <= 0 ? esc_html__('团购已结束', 'qilingshop') : ($shop_price_hidden ? esc_html__('登录后查看价格', 'qilingshop') : esc_html(sprintf(__('¥%s 发起拼团', 'qilingshop'), number_format($group_rule->group_price, 2)))); ?>
                    </button>
                </div>
                <?php endif; ?>

                <?php if (!empty($assist_activity)): ?>
                <?php
                $assist_start_price = (float) ($assist_activity->start_price ?? 0);
                if ($assist_start_price <= 0) {
                    $assist_start_price = (float) $min_actual;
                }
                $assist_min_price = max(0, (float) ($assist_activity->min_price ?? 0));
                if ($assist_start_price < $assist_min_price) {
                    $assist_start_price = $assist_min_price;
                }
                $assist_save_amount = max(0, $assist_start_price - $assist_min_price);
                $assist_save_percent = $assist_start_price > 0 ? (int) min(100, round($assist_save_amount / $assist_start_price * 100)) : 0;
                $assist_stock_total = max(0, (int) ($assist_activity->stock_total ?? 0));
                $assist_stock_available = isset($assist_activity->stock_available)
                    ? max(0, (int) $assist_activity->stock_available)
                    : max(0, $assist_stock_total - (int) ($assist_activity->stock_locked ?? 0) - (int) ($assist_activity->stock_sold ?? 0));
                $assist_stock_used = max(0, $assist_stock_total - $assist_stock_available);
                $assist_stock_percent = $assist_stock_total > 0 ? (int) min(100, round($assist_stock_used / $assist_stock_total * 100)) : 0;
                $assist_target_helpers = max(0, (int) ($assist_activity->target_helpers ?? 0));
                $assist_expire_hours = max(1, (int) ($assist_activity->expire_hours ?? 24));
                $assist_helper_text = $assist_target_helpers > 0
                    ? sprintf(__('%d人助力达标', 'qilingshop'), $assist_target_helpers)
                    : __('助力到低价', 'qilingshop');

                $assist_time_text = '';
                $assist_start_timestamp = !empty($assist_activity->start_time) ? strtotime($assist_activity->start_time) : 0;
                $assist_end_timestamp = !empty($assist_activity->end_time) ? strtotime($assist_activity->end_time) : 0;
                $assist_date_format = get_option('date_format') . ' ' . get_option('time_format');
                if ($assist_start_timestamp && $assist_end_timestamp) {
                    $assist_time_text = sprintf(__('活动时间：%s - %s', 'qilingshop'), date_i18n($assist_date_format, $assist_start_timestamp), date_i18n($assist_date_format, $assist_end_timestamp));
                } elseif ($assist_end_timestamp) {
                    $assist_time_text = sprintf(__('活动截止：%s', 'qilingshop'), date_i18n($assist_date_format, $assist_end_timestamp));
                } elseif ($assist_start_timestamp) {
                    $assist_time_text = sprintf(__('活动开始：%s', 'qilingshop'), date_i18n($assist_date_format, $assist_start_timestamp));
                }
                ?>
                <div class="qls-product-assist-section">
                    <div class="qls-product-assist-header">
                        <span class="qls-product-assist-tag"><?php _e('好友助力', 'qilingshop'); ?></span>
                        <span class="qls-product-assist-rule"><?php echo esc_html($assist_helper_text); ?></span>
                    </div>

                    <div class="qls-product-assist-price-row">
                        <?php if ($shop_price_hidden): ?>
                        <span class="qls-product-assist-price"><?php _e('登录后查看价格', 'qilingshop'); ?></span>
                        <?php else: ?>
                        <span class="qls-product-assist-price">¥<?php echo esc_html(number_format($assist_min_price, 2)); ?></span>
                        <?php if ($assist_start_price > $assist_min_price): ?>
                        <span class="qls-product-assist-original">¥<?php echo esc_html(number_format($assist_start_price, 2)); ?></span>
                        <?php endif; ?>
                        <?php endif; ?>
                        <?php if (!$shop_price_hidden && $assist_save_amount > 0): ?>
                        <span class="qls-product-assist-save">
                            <?php printf(__('最高省 ¥%s (%d%%)', 'qilingshop'), number_format($assist_save_amount, 2), (int) $assist_save_percent); ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <div class="qls-product-assist-stock"<?php echo $show_stock ? '' : ' hidden'; ?>>
                        <?php if ($assist_stock_total > 0): ?>
                        <span class="qls-product-assist-stock-text">
                            <?php printf(__('库存剩余 %1$d / %2$d', 'qilingshop'), $assist_stock_available, $assist_stock_total); ?>
                        </span>
                        <div class="qls-product-assist-stock-track"><span style="width: <?php echo (int) $assist_stock_percent; ?>%;"></span></div>
                        <?php else: ?>
                        <span class="qls-product-assist-stock-text"><?php _e('库存不限', 'qilingshop'); ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="qls-product-assist-meta">
                        <span class="qls-product-assist-expire"><?php printf(__('活动有效期：%d小时（发起后）', 'qilingshop'), $assist_expire_hours); ?></span>
                        <?php if (!empty($assist_time_text)): ?>
                        <span class="qls-product-assist-time" title="<?php echo esc_attr($assist_time_text); ?>"><?php echo esc_html($assist_time_text); ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if (is_user_logged_in()): ?>
                    <button type="button" class="qls-start-assist-btn qls-assist-create-btn" data-activity-id="<?php echo (int) $assist_activity->id; ?>">
                        <?php _e('立即发起助力', 'qilingshop'); ?>
                    </button>
                    <?php else: ?>
                    <a class="qls-start-assist-btn qls-login-trigger user-login"
                       href="<?php echo esc_url($assist_login_url); ?>"
                       data-login-url="<?php echo esc_url($assist_login_url); ?>">
                        <?php _e('登录后发起助力', 'qilingshop'); ?>
                    </a>
                    <?php endif; ?>

                    <?php if (!empty($assist_center_url)): ?>
                    <a class="qls-product-assist-link" href="<?php echo esc_url($assist_center_url); ?>">
                        <?php _e('查看更多助力活动', 'qilingshop'); ?>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="qls-product-meta-row">
                    <?php if (get_option('qls_shop_show_sales', true)): ?>
                    <span class="qls-sales-count"><?php printf(__('销量: %d', 'qilingshop'), $product->sales_count); ?></span>
                    <?php endif; ?>
                </div>
                
                <!-- 商品特色 (Features) -->
                <?php 
                $features = qls_product()->get_tags($product->id);
                if (!empty($features)):
                ?>
                <div class="qls-product-features">
                    <?php foreach ($features as $feature): ?>
                    <span class="qls-feature-tag"><?php echo esc_html($feature->name); ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <!-- 服务标签 (Moved below buttons) -->
                
                <!-- 规格选择 -->
                <?php if (!empty($product->attributes)): ?>
                <div class="qls-sku-selector" id="qls-sku-selector" data-product-id="<?php echo esc_attr($product->id); ?>">
                    <?php foreach ($product->attributes as $attr): ?>
                    <div class="qls-attr-group" data-attr="<?php echo esc_attr($attr->name); ?>">
                        <label><?php echo esc_html($attr->name); ?></label>
                        <div class="qls-attr-values">
                            <?php foreach ($attr->values as $vi => $val): 
                                $has_versions = !empty($val->versions);
                                $val_image = $val->image ?? '';
                            ?>
                            <span class="qls-attr-value" 
                                  data-value="<?php echo esc_attr($val->value); ?>"
                                  <?php if ($val_image): ?>data-image="<?php echo esc_url($val_image); ?>"<?php endif; ?>
                                  <?php if ($has_versions): ?>data-has-versions="1"<?php endif; ?>>
                                <?php if ($val_image): ?>
                                <img src="<?php echo esc_url($val_image); ?>" alt="" class="qls-value-thumb">
                                <?php endif; ?>
                                <?php echo esc_html($val->value); ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- 版本值选择（动态显示） -->
                        <?php 
                        $first_val = $attr->values[0] ?? null;
                        if ($first_val && !empty($first_val->versions)): 
                        ?>
                        <div class="qls-version-selector" data-parent-attr="<?php echo esc_attr($attr->name); ?>">
                            <label><?php _e('版本', 'qilingshop'); ?></label>
                            <div class="qls-version-values">
                                <?php foreach ($first_val->versions as $veri => $ver): ?>
                                <span class="qls-version-value" data-version="<?php echo esc_attr($ver); ?>">
                                    <?php echo esc_html($ver); ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- 存储所有版本值数据供JS使用 -->
                        <script type="application/json" class="qls-versions-data">
                        <?php 
                        $versions_map = [];
                        foreach ($attr->values as $val) {
                            $versions_map[$val->value] = $val->versions ?? [];
                        }
                        echo wp_json_encode($versions_map);
                        ?>
                        </script>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <!-- 数量 -->
                <div class="qls-quantity-box">
                    <label><?php _e('数量', 'qilingshop'); ?></label>
                    <div class="qls-quantity-input">
                        <button type="button" class="qls-qty-minus">-</button>
                        <input type="number" id="qls-quantity" value="1" min="1" max="<?php echo esc_attr($default_sku ? $default_sku->stock : 999); ?>">
                        <button type="button" class="qls-qty-plus">+</button>
                    </div>
                    <span class="qls-stock" id="qls-stock"<?php echo $show_stock ? '' : ' hidden'; ?>>
                        <?php printf(__('库存 %d 件', 'qilingshop'), $default_sku ? $default_sku->stock : $product->total_stock); ?>
                    </span>
                </div>
                
                <!-- 购买按钮 -->
                <div class="qls-buy-actions">
                    <input type="hidden" id="qls-product-id" value="<?php echo esc_attr($product->id); ?>">
                    <input type="hidden" id="qls-sku-id" value="<?php echo esc_attr($default_sku ? $default_sku->id : 0); ?>">
                    <input type="hidden" id="qls-new-user-special-enabled" value="<?php echo $new_user_special_enabled ? '1' : '0'; ?>">
                    <input type="hidden" id="qls-new-user-special-eligible" value="<?php echo $new_user_special_active ? '1' : '0'; ?>">
                    <input type="hidden" id="qls-new-user-special-price" value="<?php echo esc_attr(number_format((float) $new_user_special_price, 2, '.', '')); ?>">
                    <script type="application/json" id="qls-product-sku-data"><?php echo wp_json_encode($frontend_sku_payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
                    
                    <?php 
                    // 检查是否允许游客下单（兼容旧配置键）
                    $guest_checkout = function_exists('qls_shop_is_guest_order_enabled')
                        ? qls_shop_is_guest_order_enabled()
                        : (bool) get_option('qls_shop_cart_guest_enabled', true);
                    if (!is_user_logged_in() && !$guest_checkout):
                    ?>
                        <button type="button" class="qls-btn qls-btn-primary user-login">
                            <?php _e('立即登录', 'qilingshop'); ?>
                        </button>
                    <?php else: ?>
                        <button type="button" class="qls-btn qls-btn-cart" id="qls-add-cart" <?php echo ($default_sku && $default_sku->stock <= 0) ? 'disabled' : ''; ?>>
                            <span class="dashicons dashicons-cart"></span>
                            <?php _e('加入购物车', 'qilingshop'); ?>
                        </button>
                        
                        <button type="button" class="qls-btn qls-btn-buy" id="qls-buy-now" <?php echo ($default_sku && $default_sku->stock <= 0) ? 'disabled' : ''; ?>>
                            <?php _e('立即购买', 'qilingshop'); ?>
                        </button>
                    <?php endif; ?>
                </div>

                <!-- 服务标签 -->
                <?php if (!empty($service_tags)): ?>
                <div class="qls-service-tags">
                    <?php foreach ($service_tags as $tag): ?>
                    <span class="qls-service-tag">
                        <?php echo qilingshop_render_icon($tag->icon ?? '', 'qls-service-tag-icon'); ?>
                        <?php echo esc_html($tag->name); ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- 运费说明 -->
                <?php 
                $shipping_rule_id = $product->shipping_rule_id ?? 0;
                $shipping_rule = $shipping_rule_id ? qls_shipping()->get_rule($shipping_rule_id) : qls_shipping()->get_default_rule();
                $shipping_desc = $shipping_rule ? qls_shipping()->get_rule_description($shipping_rule) : __('免运费', 'qilingshop');
                ?>
                <div class="qls-shipping-info">
                    <span class="label"><?php _e('运费', 'qilingshop'); ?></span>
                    <span class="value"><?php echo esc_html($shipping_desc); ?></span>
                </div>
            </div>
        </div>
        
        <!-- 商品详情 & 参数 & 评价 -->
        <div class="qls-product-content">
            <?php 
            // 获取评价统计
            $review_stats = null;
            $review_enabled = get_option('qls_shop_review_enabled', true);
            if ($review_enabled) {
                $review_stats = qls_review()->get_stats($product->id);
            }
            ?>
            <div class="qls-tab-nav">
                <span class="qls-tab active" data-tab="detail"><?php _e('商品详情', 'qilingshop'); ?></span>
                <span class="qls-tab" data-tab="params"><?php _e('商品参数', 'qilingshop'); ?></span>
                <?php if ($review_enabled): ?>
                <span class="qls-tab" data-tab="reviews">
                    <?php _e('商品评价', 'qilingshop'); ?>
                    <?php if ($review_stats && $review_stats['total'] > 0): ?>
                    <span class="tab-count">(<?php echo $review_stats['total']; ?>)</span>
                    <?php endif; ?>
                </span>
                <?php endif; ?>
            </div>
            
            <div class="qls-tab-content">
                <div class="qls-tab-pane active" id="tab-detail">
                    <?php echo wp_kses_post(wpautop($product->content)); ?>
                </div>
                
                <div class="qls-tab-pane" id="tab-params">
                    <?php if (!empty($product->params)): ?>
                    <div class="qls-params-table">
                        <?php foreach ($product->params as $param): ?>
                        <div class="param-row">
                            <span class="param-name"><?php echo esc_html($param['name']); ?></span>
                            <span class="param-value"><?php echo esc_html($param['value']); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="qls-no-params"><?php _e('暂无参数', 'qilingshop'); ?></p>
                    <?php endif; ?>
                </div>

                <?php if ($review_enabled): ?>
                <div class="qls-tab-pane" id="tab-reviews">
                    <div class="qls-review-section" data-product-id="<?php echo esc_attr($product->id); ?>">
                        <!-- 评价统计头部 -->
                        <?php if ($review_stats && $review_stats['total'] > 0): ?>
                        <div class="qls-review-stats">
                            <div class="stats-summary">
                                <span class="avg-rating"><?php echo number_format($review_stats['avg_rating'], 1); ?></span>
                                <span class="rating-stars">
                                    <?php 
                                    $full_stars = floor($review_stats['avg_rating']);
                                    $half_star = ($review_stats['avg_rating'] - $full_stars) >= 0.5;
                                    for ($i = 0; $i < 5; $i++) {
                                        if ($i < $full_stars) echo '★';
                                        elseif ($i == $full_stars && $half_star) echo '★';
                                        else echo '☆';
                                    }
                                    ?>
                                </span>
                                <span class="good-rate"><?php printf(__('好评率 %d%%', 'qilingshop'), $review_stats['good_rate']); ?></span>
                            </div>
                            <div class="stats-breakdown">
                                <span><?php printf(__('好评(%d)', 'qilingshop'), $review_stats['good_count']); ?></span>
                                <span><?php printf(__('中评(%d)', 'qilingshop'), $review_stats['medium_count']); ?></span>
                                <span><?php printf(__('差评(%d)', 'qilingshop'), $review_stats['bad_count']); ?></span>
                                <span><?php printf(__('有图(%d)', 'qilingshop'), $review_stats['image_count']); ?></span>
                            </div>
                        </div>

                        <!-- 评价筛选 -->
                        <div class="qls-review-filters">
                            <span class="filter-btn active" data-filter="all"><?php _e('全部', 'qilingshop'); ?></span>
                            <span class="filter-btn" data-filter="good"><?php _e('好评', 'qilingshop'); ?></span>
                            <span class="filter-btn" data-filter="medium"><?php _e('中评', 'qilingshop'); ?></span>
                            <span class="filter-btn" data-filter="bad"><?php _e('差评', 'qilingshop'); ?></span>
                            <span class="filter-btn" data-filter="image"><?php _e('有图', 'qilingshop'); ?></span>
                        </div>

                        <!-- 评价列表 -->
                        <div class="qls-review-list" id="review-list">
                            <div class="loading-indicator" style="text-align:center; padding:30px; color:#999;">
                                <?php _e('加载中...', 'qilingshop'); ?>
                            </div>
                        </div>

                        <!-- 加载更多 -->
                        <div class="qls-review-more" style="text-align:center; padding:20px; display:none;">
                            <button type="button" class="qls-btn" id="load-more-reviews"><?php _e('加载更多', 'qilingshop'); ?></button>
                        </div>
                        
                        <?php else: ?>
                        <div class="qls-review-empty" style="text-align:center; padding:40px; color:#999;">
                            <span class="dashicons dashicons-format-status" style="font-size:48px; display:block; margin-bottom:10px;"></span>
                            <p><?php _e('暂无评价，快来抢首评吧！', 'qilingshop'); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php qls_shop_public()->render_service_showcase('product_bottom'); ?>
    </div>
</div>

<!-- 评价样式 -->
<style>
.qls-review-section { padding: 20px 0; }
.qls-review-stats { 
    display: flex; 
    align-items: center; 
    justify-content: space-between; 
    padding: 20px; 
    background: #f9f9f9; 
    border-radius: 8px; 
    margin-bottom: 20px; 
}
.qls-review-stats .avg-rating { 
    font-size: 48px; 
    font-weight: 600; 
    color: #f90; 
    margin-right: 10px; 
}
.qls-review-stats .stats-summary { display: flex; align-items: center; gap: 15px; }
.qls-review-stats .good-rate { color: #5cb85c; font-weight: 500; }
.qls-review-stats .stats-breakdown { display: flex; gap: 20px; color: #666; font-size: 14px; }
.qls-review-filters { 
    display: flex; 
    gap: 10px; 
    margin-bottom: 20px; 
    padding-bottom: 15px; 
    border-bottom: 1px solid #eee; 
}
.qls-review-filters .filter-btn { 
    padding: 6px 16px; 
    border: 1px solid #ddd; 
    border-radius: 20px; 
    cursor: pointer; 
    transition: all 0.2s; 
    font-size: 14px;
}
.qls-review-filters .filter-btn:hover,
.qls-review-filters .filter-btn.active { 
    border-color: var(--qls-primary); 
    color: var(--qls-primary); 
    background: rgba(255,77,45,0.08); 
}
.qls-review-item { 
    padding: 20px 0; 
    border-bottom: 1px solid #eee; 
}
.qls-review-item:last-child { border-bottom: none; }
.qls-review-header { 
    display: flex; 
    align-items: center; 
    gap: 12px; 
    margin-bottom: 10px; 
}
.qls-review-avatar { 
    width: 40px; 
    height: 40px; 
    border-radius: 50%; 
    background: #ddd; 
    display: flex; 
    align-items: center; 
    justify-content: center; 
    color: #666; 
}
.qls-review-user { font-weight: 500; }
.qls-review-rating { color: #f90; }
.qls-review-sku { font-size: 12px; color: #999; }
.qls-review-content { 
    line-height: 1.6; 
    color: #333; 
    margin-bottom: 12px; 
}
.qls-review-images { 
    display: flex; 
    flex-wrap: wrap; 
    gap: 10px; 
    margin-bottom: 12px; 
}
.qls-review-images img { 
    width: 80px; 
    height: 80px; 
    object-fit: cover; 
    border-radius: 4px; 
    cursor: pointer; 
    transition: transform 0.2s;
}
.qls-review-images img:hover { transform: scale(1.05); }
.qls-review-footer { 
    display: flex; 
    justify-content: space-between; 
    align-items: center; 
    font-size: 13px; 
    color: #999; 
}
.qls-review-date { }
.qls-review-actions { display: flex; gap: 15px; }
.qls-review-actions span { cursor: pointer; }
.qls-review-actions span:hover { color: var(--qls-primary); }
.qls-review-reply { 
    margin-top: 12px; 
    padding: 12px; 
    background: #f9f9f9; 
    border-radius: 4px; 
    font-size: 14px; 
}
.qls-review-reply .reply-label { color: var(--qls-primary); font-weight: 500; }
.tab-count { font-size: 12px; color: #999; }
/* Lightbox 图片预览 */
.qls-lightbox {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.9);
    z-index: 999999;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: zoom-out;
}
.qls-lightbox img {
    max-width: 90%;
    max-height: 90%;
    border-radius: 4px;
    box-shadow: 0 4px 30px rgba(0,0,0,0.5);
}
.qls-lightbox-close {
    position: absolute;
    top: 20px;
    right: 30px;
    color: #fff;
    font-size: 36px;
    cursor: pointer;
    opacity: 0.8;
}
.qls-lightbox-close:hover { opacity: 1; }

/* ==================== 商品评价响应式 ==================== */
@media (max-width: 768px) {
    /* 评价统计区 */
    .qls-review-summary {
        flex-direction: column !important;
        gap: 15px !important;
    }
    .qls-review-score {
        text-align: center !important;
    }
    .qls-review-bars {
        width: 100% !important;
    }
    
    /* 筛选按钮 */
    .qls-review-filters {
        flex-wrap: wrap !important;
        gap: 8px !important;
    }
    .qls-review-filters .filter-btn {
        flex: 0 0 auto !important;
        padding: 6px 12px !important;
        font-size: 13px !important;
    }
    
    /* 评价列表 */
    .qls-review-item {
        padding: 15px !important;
    }
    .qls-review-header {
        flex-wrap: wrap !important;
        gap: 8px !important;
    }
    .qls-review-user span {
        font-size: 13px !important;
    }
    
    /* 评价图片 */
    .qls-review-images img {
        width: 60px !important;
        height: 60px !important;
    }
    
    /* Lightbox */
    .qls-lightbox img {
        max-width: 100% !important;
        max-height: 80% !important;
    }
    .qls-lightbox-close {
        top: 10px !important;
        right: 15px !important;
        font-size: 28px !important;
    }
}

@media (max-width: 480px) {
    .qls-review-filters .filter-btn {
        padding: 5px 10px !important;
        font-size: 12px !important;
    }
    .qls-review-images img {
        width: 50px !important;
        height: 50px !important;
    }
    .qls-review-content {
        font-size: 14px !important;
    }
}
</style>

<!-- 评价JS -->
<?php if ($review_enabled && $review_stats && $review_stats['total'] > 0): ?>
<script>
jQuery(document).ready(function($) {
    var productId = <?php echo $product->id; ?>;
    var currentPage = 1;
    var currentFilter = 'all';
    var totalPages = 1;

    // 初始加载
    loadReviews();

    // 筛选点击
    $('.qls-review-filters .filter-btn').click(function() {
        $('.qls-review-filters .filter-btn').removeClass('active');
        $(this).addClass('active');
        currentFilter = $(this).data('filter');
        currentPage = 1;
        loadReviews();
    });

    // 加载更多
    $('#load-more-reviews').click(function() {
        currentPage++;
        loadReviews(true);
    });

    function loadReviews(append) {
        var params = {
            action: 'qls_get_reviews',
            nonce: (typeof qlsShop !== 'undefined' && qlsShop.nonce) ? qlsShop.nonce : (typeof qls_shop_vars !== 'undefined' ? qls_shop_vars.nonce : ''),
            product_id: productId,
            page: currentPage,
            per_page: 10
        };

        if (currentFilter === 'good') params.rating = 'good';
        else if (currentFilter === 'medium') params.rating = 'medium';
        else if (currentFilter === 'bad') params.rating = 'bad';
        else if (currentFilter === 'image') params.has_image = 'true';

        $.get(qlsShop.ajaxUrl || qls_shop_vars.ajax_url, params, function(response) {
            if (response.success) {
                var html = '';
                $.each(response.data.reviews, function(i, review) {
                    html += renderReviewItem(review);
                });

                if (append) {
                    $('#review-list').append(html);
                } else {
                    if (html) {
                        $('#review-list').html(html);
                    } else {
                        $('#review-list').html('<div style="text-align:center; padding:30px; color:#999;"><?php _e('暂无符合条件的评价', 'qilingshop'); ?></div>');
                    }
                }

                totalPages = response.data.total_pages;
                if (currentPage < totalPages) {
                    $('.qls-review-more').show();
                } else {
                    $('.qls-review-more').hide();
                }
            }
        });
    }

    function renderReviewItem(review) {
        var stars = '';
        for (var i = 0; i < 5; i++) {
            stars += i < review.rating ? '★' : '☆';
        }

        var html = '<div class="qls-review-item">';
        html += '<div class="qls-review-header">';
        html += '<div class="qls-review-avatar"><span class="dashicons dashicons-admin-users"></span></div>';
        html += '<div>';
        html += '<div class="qls-review-user">' + (review.user_name || '<?php _e('用户', 'qilingshop'); ?>') + '</div>';
        html += '<div class="qls-review-rating">' + stars + '</div>';
        html += '</div>';
        if (review.sku_info) {
            html += '<div class="qls-review-sku">' + review.sku_info + '</div>';
        }
        html += '</div>';
        
        html += '<div class="qls-review-content">' + review.content + '</div>';

        if (review.images && review.images.length) {
            html += '<div class="qls-review-images">';
            $.each(review.images, function(i, img) {
                html += '<img src="' + img + '" alt="">';
            });
            html += '</div>';
        }

        html += '<div class="qls-review-footer">';
        html += '<span class="qls-review-date">' + review.created_at + '</span>';
        html += '<div class="qls-review-actions">';
        html += '<span class="like-btn" data-id="' + review.id + '"><span class="dashicons dashicons-thumbs-up"></span> ' + (review.like_count || 0) + '</span>';
        html += '</div>';
        html += '</div>';

        if (review.admin_reply) {
            html += '<div class="qls-review-reply">';
            html += '<span class="reply-label"><?php _e('商家回复:', 'qilingshop'); ?></span> ' + review.admin_reply;
            html += '</div>';
        }

        html += '</div>';
        return html;
    }

    // 点赞
    $(document).on('click', '.like-btn', function() {
        var $btn = $(this);
        var reviewId = $btn.data('id');
        
        $.post(qlsShop.ajaxUrl || qls_shop_vars.ajax_url, {
            action: 'qls_like_review',
            review_id: reviewId,
            nonce: qlsShop.nonce || qls_shop_vars.nonce
        }, function(response) {
            if (response.success) {
                $btn.html('<span class="dashicons dashicons-thumbs-up"></span> ' + response.data.like_count);
            }
        });
    });

    // Lightbox图片预览
    $(document).on('click', '.qls-review-images img', function() {
        var src = $(this).attr('src');
        var $lightbox = $('<div class="qls-lightbox"><span class="qls-lightbox-close">&times;</span><img src="'+src+'" alt=""></div>');
        $('body').append($lightbox);
        $lightbox.click(function() { $(this).remove(); });
    });
});
</script>
<?php endif; ?>

<?php qls_shop_public()->get_shop_footer(); ?>
