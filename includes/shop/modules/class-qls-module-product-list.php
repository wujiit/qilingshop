<?php
/**
 * 商品列表模块
 *
 * @package QilingShop
 * @since   2.0.2
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once 'class-qls-module-base.php';

class QLS_Module_Product_List extends QLS_Module_Base {

    public function get_defaults() {
        return [
            // Data Source
            'source'        => 'latest', // latest, hot, category, tags, ids
            'source_value'  => '',       // ID, slug, or comma-separated list
            'limit'         => 8,
            'orderby'       => '',       // date, sales_count, price
            'order'         => 'DESC',
            
            // Layout
            'layout'        => 'grid',   // grid, scroll, list
            'columns'       => 4,        // 桌面端列数：2、3、4、5
            'style'         => 'simple', // simple, card
            
            // Styling
            'card_style'    => 'default',   // default, shadow, bordered
            'hover_effect'  => 'scale',     // none, scale, lift, shadow
            'bg_color'      => '',          // hex color
            'padding_top'   => '30px',
            'padding_bottom'=> '30px',
            
            // Content Switches
            'show_thumbnail'=> 'yes',
            'show_title'    => 'yes',
            'show_badge'    => 'auto',   // auto, none, hot, new, discount, or custom text
            'show_price'    => 'yes',
            'show_original' => 'yes',
            'show_sales'    => 'no',
            'show_btn'      => 'none',   // none, cart, buy
            
            // Header
            'title'         => '',
            'subtitle'      => '',
            'view_more'     => 'auto',   // auto, none, or custom URL
            'title_style'   => 'default', // default, center, underline
        ];
    }
    
    public function get_settings_fields() {
        return [
            'general' => [
                'title' => __('基本设置', 'qilingshop'),
                'fields' => [
                    'title' => [
                        'type'  => 'text',
                        'label' => __('模块标题', 'qilingshop'),
                        'desc'  => __('显示在模块顶部的标题', 'qilingshop'),
                    ],
                    'subtitle' => [
                        'type'  => 'text',
                        'label' => __('副标题', 'qilingshop'),
                    ],
                    'view_more' => [
                        'type'  => 'text',
                        'label' => __('查看更多链接', 'qilingshop'),
                        'desc'  => __('填 "auto" 表示自动生成，“none” 表示不显示，或填写具体链接', 'qilingshop'),
                    ]
                ]
            ],
            'source' => [
                'title' => __('数据来源', 'qilingshop'),
                'fields' => [
                    'source' => [
                        'type'    => 'select',
                        'label'   => __('数据来源', 'qilingshop'),
                        'options' => [
                            'latest'   => __('最新上架', 'qilingshop'),
                            'sales'    => __('按销量', 'qilingshop'),
                            'hot'      => __('热门商品', 'qilingshop'),
                            'points'   => __('支持积分价商品', 'qilingshop'),
                            'category' => __('指定分类', 'qilingshop'),
                            'ids'      => __('手动指定商品', 'qilingshop'),
                        ]
                    ],
                    'source_value' => [
                        'type'  => 'text',
                        'label' => __('来源参数', 'qilingshop'),
                        'desc'  => __('分类编号或商品编号，多个编号用半角逗号分隔', 'qilingshop'),
                    ],
                    'limit' => [
                        'type'  => 'number',
                        'label' => __('显示数量', 'qilingshop'),
                    ]
                ]
            ],
            'layout' => [
                'title' => __('布局样式', 'qilingshop'),
                'fields' => [
                    'layout' => [
                        'type'    => 'select',
                        'label'   => __('布局模式', 'qilingshop'),
                        'options' => [
                            'grid'   => __('网格布局', 'qilingshop'),
                            'scroll' => __('横向滑动（适合移动端）', 'qilingshop'),
                        ]
                    ],
                    'columns' => [
                        'type'    => 'select',
                        'label'   => __('电脑端列数', 'qilingshop'),
                        'options' => [
                            1 => __('1列', 'qilingshop'),
                            2 => __('2列', 'qilingshop'),
                            3 => __('3列', 'qilingshop'),
                            4 => __('4列', 'qilingshop'),
                            5 => __('5列', 'qilingshop'),
                        ]
                    ],
                    'show_badge' => [
                        'type'    => 'select',
                        'label'   => __('角标显示', 'qilingshop'),
                        'options' => [
                            'auto' => __('自动显示热门、新品、折扣', 'qilingshop'),
                            'none' => __('不显示', 'qilingshop'),
                            'hot'  => __('热门', 'qilingshop'),
                            'new'  => __('新品', 'qilingshop'),
                        ]
                    ],
                    'show_price' => [
                        'type'    => 'select',
                        'label'   => __('显示价格', 'qilingshop'),
                        'options' => ['yes' => __('显示', 'qilingshop'), 'no' => __('隐藏', 'qilingshop')]
                    ],
                    'show_btn' => [
                        'type'    => 'select',
                        'label'   => __('购买按钮', 'qilingshop'),
                        'options' => [
                            'none' => __('不显示', 'qilingshop'),
                            'cart' => __('购物车图标', 'qilingshop'),
                            'buy'  => __('购买文字', 'qilingshop'),
                        ]
                    ]
                ]
            ],
            'styling' => [
                'title' => __('样式设置', 'qilingshop'),
                'fields' => [
                    'card_style' => [
                        'type'    => 'select',
                        'label'   => __('卡片样式', 'qilingshop'),
                        'options' => [
                            'default'  => __('默认', 'qilingshop'),
                            'shadow'   => __('阴影卡片', 'qilingshop'),
                            'bordered' => __('边框卡片', 'qilingshop'),
                        ]
                    ],
                    'hover_effect' => [
                        'type'    => 'select',
                        'label'   => __('悬停效果', 'qilingshop'),
                        'options' => [
                            'none'   => __('无', 'qilingshop'),
                            'scale'  => __('缩放', 'qilingshop'),
                            'lift'   => __('上浮', 'qilingshop'),
                            'shadow' => __('阴影增强', 'qilingshop'),
                        ]
                    ],
                    'bg_color' => [
                        'type'  => 'text',
                        'label' => __('背景颜色', 'qilingshop'),
                        'desc'  => __('例如：#f5f5f5、透明', 'qilingshop'),
                    ],
                    'padding_top' => [
                        'type'  => 'text',
                        'label' => __('上间距', 'qilingshop'),
                        'desc'  => __('例如：30px、2rem', 'qilingshop'),
                    ],
                    'padding_bottom' => [
                        'type'  => 'text',
                        'label' => __('下间距', 'qilingshop'),
                        'desc'  => __('例如：30px、2rem', 'qilingshop'),
                    ],
                    'title_style' => [
                        'type'    => 'select',
                        'label'   => __('标题样式', 'qilingshop'),
                        'options' => [
                            'default'   => __('默认 (左对齐)', 'qilingshop'),
                            'center'    => __('居中', 'qilingshop'),
                            'underline' => __('带下划线', 'qilingshop'),
                        ]
                    ],
                ]
            ]
        ];
    }

    protected function render_content($atts) {
        // 1. Prepare Query Arguments
        $args = [
            'status' => 1,
            'limit'  => intval($atts['limit']),
            'order'  => $atts['order'],
        ];

        // Handle Source logic
        switch ($atts['source']) {
            case 'hot':
                $args['is_hot'] = 1;
                // $args['orderby'] = 'sales_count'; 
                // Hot is improved by also sorting by sales
                $args['orderby'] = 'sales_count'; 
                break;
            case 'sales': // 按销量
                $args['orderby'] = 'sales_count';
                break;
            case 'points':
                $args['points_payable'] = 1;
                $args['orderby'] = $atts['orderby'] ?: 'points_price';
                $args['order'] = $atts['order'] ?: 'ASC';
                break;
            case 'category':
                if (!empty($atts['source_value'])) {
                    $args['category_id'] = intval($atts['source_value']);
                }
                $args['orderby'] = $atts['orderby'] ?: 'id';
                break;
            case 'ids':
                if (!empty($atts['source_value'])) {
                    $args['include'] = array_map('intval', explode(',', $atts['source_value']));
                    $args['orderby'] = 'include';
                }
                break;
            case 'latest':
            default:
                $args['orderby'] = $atts['orderby'] ?: 'id'; // default by ID (newest)
                break;
        }

        // Fetch Products
        $products = qls_product()->get_list($args);

        if (empty($products)) {
            return;
        }

        // 2. Prepare Wrapper Classes
        $wrapper_classes = $this->get_class_names('qls-module-product-list', [
            'layout' => $atts['layout'],
            'cols'   => $atts['columns'],
            'style'  => $atts['style']
        ]);
        
        // Add styling classes
        $card_style = $atts['card_style'] ?? 'default';
        $hover_effect = $atts['hover_effect'] ?? 'scale';
        $title_style = $atts['title_style'] ?? 'default';
        $wrapper_classes .= " card-{$card_style} hover-{$hover_effect} title-{$title_style}";
        
        // Inline styles
        $inline_styles = [];
        if (!empty($atts['bg_color'])) {
            $inline_styles[] = "background-color: " . esc_attr($atts['bg_color']);
        }
        if (!empty($atts['padding_top'])) {
            $inline_styles[] = "padding-top: " . esc_attr($atts['padding_top']);
        }
        if (!empty($atts['padding_bottom'])) {
            $inline_styles[] = "padding-bottom: " . esc_attr($atts['padding_bottom']);
        }
        $style_attr = !empty($inline_styles) ? ' style="' . implode('; ', $inline_styles) . '"' : '';

        // 3. 渲染内容
        ?>
        <div class="qls-module <?php echo esc_attr($wrapper_classes); ?>"<?php echo $style_attr; ?>>
            <?php $this->render_header($atts); ?>

            <div class="qls-module-content">
                <div class="qls-product-list-wrapper">
                    <?php foreach ($products as $product): ?>
                        <?php $this->render_product_item($product, $atts); ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render Module Header
     */
    private function render_header($atts) {
        if (empty($atts['title'])) {
            return;
        }
        
        $more_url = '';
        if ($atts['view_more'] === 'auto') {
            // New: Point to the specialized "All Products" page
            $all_products_url = qls_shop_public()->get_page_url('all_products');
            
            // 兜底 to shop home if not created yet (though it should be)
            if (empty($all_products_url)) {
                 $all_products_url = qls_shop_public()->get_shop_url() . '?view=products';
            }
            
            $more_url = $all_products_url;
            
            if ($atts['source'] === 'category' && !empty($atts['source_value'])) {
                // Get slug from ID if possible
                 $cat = qls_category()->get($atts['source_value']);
                 if ($cat) {
                     // /all-products/category/slug/
                     $more_url = untrailingslashit($all_products_url) . '/category/' . $cat->slug . '/';
                 } else {
                     // 兜底 if no cat obj found (rare)
                     $more_url = add_query_arg('category', $atts['source_value'], $all_products_url);
                 }
                 
            } elseif ($atts['source'] === 'hot') {
                // /all-products/hot/
                $more_url = untrailingslashit($all_products_url) . '/hot/';
                
            } elseif ($atts['source'] === 'sales') {
                // /all-products/sales/
                $more_url = untrailingslashit($all_products_url) . '/sales/';
                
            } elseif ($atts['source'] === 'points') {
                $more_url = add_query_arg('points', '1', $all_products_url);

            } elseif ($atts['source'] === 'latest') {
                // /all-products/latest/ (optional, or just base url)
                $more_url = untrailingslashit($all_products_url) . '/latest/';
            }
        } elseif ($atts['view_more'] !== 'none') {
            $more_url = $atts['view_more'];
        }
        ?>
        <div class="qls-module-header">
            <div class="qls-module-title-group">
                <h3 class="qls-module-title"><?php echo esc_html($atts['title']); ?></h3>
                <?php if (!empty($atts['subtitle'])): ?>
                <span class="qls-module-subtitle"><?php echo esc_html($atts['subtitle']); ?></span>
                <?php endif; ?>
            </div>
            
            <?php if ($more_url): ?>
            <a href="<?php echo esc_url($more_url); ?>" class="qls-module-more">
                <?php _e('查看更多', 'qilingshop'); ?> 
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle;"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
            </a>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render Single Product Item
     */
    private function render_product_item($product, $atts) {
        $link = qls_shop_public()->get_product_url($product);
        $thumb = '';
        
        if (!empty($product->main_image)) {
             if (is_array($product->main_image)) {
                 $thumb = $product->main_image['url'] ?? '';
             } elseif (is_string($product->main_image)) {
                 $thumb = $product->main_image;
             }
        }
        
        // 缺省时使用商品图集
        if (empty($thumb) && !empty($product->gallery) && is_array($product->gallery)) {
            $first = reset($product->gallery);
            if (is_array($first)) {
                $thumb = $first['url'] ?? '';
            } elseif (is_string($first)) {
                $thumb = $first;
            }
        }
        
        // 角标逻辑
        $badge_html = '';
        if ($atts['show_badge'] !== 'none') {
            if ($atts['show_badge'] === 'auto') {
                if ($product->is_hot) {
                    $badge_html = '<span class="qls-badge qls-badge--hot">' . esc_html__('热卖', 'qilingshop') . '</span>';
                } elseif (strtotime($product->created_at) > strtotime('-7 days')) {
                    $badge_html = '<span class="qls-badge qls-badge--new">' . esc_html__('新品', 'qilingshop') . '</span>';
                } elseif ($product->sale_price > 0 && $product->sale_price < $product->price) {
                     $badge_html = '<span class="qls-badge qls-badge--sale">' . esc_html__('特惠', 'qilingshop') . '</span>';
                }
            } else {
                $badge_html = '<span class="qls-badge qls-badge--custom">' . esc_html($atts['show_badge']) . '</span>';
            }
        }

        // 价格展示逻辑
        $price_html = '';
        if ($atts['show_price'] === 'yes') {
            if (!is_user_logged_in() && get_option('qls_shop_price_login_required', false)) {
                $price_html = '<div class="qls-p-price">' . esc_html__('登录后查看价格', 'qilingshop') . '</div>';
            } else {
            // Fix: Products table stores min_price/max_price. 
            // Individual price/sale_price are on SKUs, but we are listing Products here.
            $min_price = isset($product->min_price) ? floatval($product->min_price) : 0;
            $max_price = isset($product->max_price) ? floatval($product->max_price) : 0;
            
            // Just show "From ¥XXX" or range "¥XXX - ¥YYY"? Usually just show min price for card.
            if ($max_price > $min_price) {
                $price_html = '<div class="qls-p-price"><span>¥</span>' . number_format($min_price, 2) . ' - <span>¥</span>' . number_format($max_price, 2) . '</div>';
            } else {
                $price_html = '<div class="qls-p-price"><span>¥</span>' . number_format($min_price, 2) . '</div>';
            }

            if (!empty($product->points_price) && floatval($product->points_price) > 0) {
                $points_name = function_exists('qilingshop_get_points_name') ? qilingshop_get_points_name() : __('积分', 'qilingshop');
                $price_html .= '<div class="qls-p-points-price">' . sprintf(__('积分价 %1$s %2$s', 'qilingshop'), number_format_i18n(floatval($product->points_price), 0), esc_html($points_name)) . '</div>';
            }
            }
        }
        ?>
        <div class="qls-product-item">
            <div class="qls-p-thumb">
                <a href="<?php echo esc_url($link); ?>">
                    <?php echo $badge_html; ?>
                    <?php if ($thumb): ?>
                    <img src="<?php echo esc_url($thumb); ?>" alt="<?php echo esc_attr($product->title); ?>" loading="lazy">
                    <?php else: ?>
                    <div class="qls-p-placeholder"></div>
                    <?php endif; ?>
                </a>
            </div>
            
            <div class="qls-p-info">
                <?php if ($atts['show_title'] === 'yes'): ?>
                <h4 class="qls-p-title">
                    <a href="<?php echo esc_url($link); ?>"><?php echo esc_html($product->title); ?></a>
                </h4>
                <?php endif; ?>
                
                <?php if ($atts['show_sales'] === 'yes' && isset($product->sales_count)): ?>
                <div class="qls-p-meta">
                    <span class="qls-p-sales"><?php printf(__('%d人付款', 'qilingshop'), $product->sales_count); ?></span>
                </div>
                <?php endif; ?>

                <div class="qls-p-bottom">
                    <div class="qls-p-prices"><?php echo $price_html; ?></div>
                    
                    <?php if ($atts['show_btn'] === 'cart'): ?>
                    <button class="qls-btn-icon qls-add-to-cart" data-id="<?php echo esc_attr($product->id); ?>" aria-label="<?php esc_attr_e('加入购物车', 'qilingshop'); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
                    </button>
                    <?php elseif ($atts['show_btn'] === 'buy'): ?>
                    <a href="<?php echo esc_url($link); ?>" class="qls-btn-buy"><?php _e('购买', 'qilingshop'); ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
}
