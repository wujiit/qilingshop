<?php
/**
 * 商品分类导航模块
 *
 * @package QilingShop
 * @since   2.0.2
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once 'class-qls-module-base.php';

class QLS_Module_Category_Nav extends QLS_Module_Base {

    public function get_defaults() {
        return [
            // Data Source
            'source'        => 'auto', // auto (top level), manual
            'menu_ids'      => '',     // comma separated IDs
            'limit'         => 10,
            
            // Layout & Style
            'style'         => 'icon', // icon (icon+text), text (pill), card (image bg)
            'columns_pc'    => 5,
            'columns_mobile'=> 5,
            
            // Appearance
            'icon_size'     => '40px',
            'font_size'     => '14px',
            'gap_x'         => '10px',
            'gap_y'         => '10px',
            
            // Colors
            'text_color'    => '#333333',
            'bg_color'      => '#ffffff',
            'hover_color'   => '', // Optional hover accent
            
            // Spacing
            'margin_top'    => '10px',
            'margin_bottom' => '10px',
            'padding_content'=> '10px',
            
            // All Products Entry
            'show_all_products'     => true,
            'all_products_name'     => __('全部商品', 'qilingshop'),
            'all_products_icon'     => '',  // 留空使用默认图标
            'all_products_link'     => '',  // 留空自动获取
            'all_products_position' => 'first', // first 或 last
        ];
    }
    
    public function get_settings_fields() {
        return [
            'content' => [
                'title' => __('内容设置', 'qilingshop'),
                'fields' => [
                    'source' => [
                        'type'    => 'select',
                        'label'   => __('数据来源', 'qilingshop'),
                        'options' => [
                            'auto'   => __('自动获取一级分类', 'qilingshop'),
                            'manual' => __('手动指定分类', 'qilingshop'),
                        ]
                    ],
                    'menu_ids' => [
                        'type'  => 'text',
                        'label' => __('分类编号列表', 'qilingshop'),
                        'desc'  => __('仅手动模式有效，多个编号用半角逗号分隔，例如：1,2,3', 'qilingshop'),
                    ],
                    'limit' => [
                        'type'  => 'number',
                        'label' => __('显示数量限制', 'qilingshop'),
                        'desc'  => __('默认 10', 'qilingshop'),
                    ]
                ]
            ],
            'all_products' => [
                'title' => __('全部商品入口', 'qilingshop'),
                'fields' => [
                    'show_all_products' => [
                        'type'    => 'select',
                        'label'   => __('显示全部商品', 'qilingshop'),
                        'options' => [
                            1 => __('显示', 'qilingshop'),
                            0 => __('隐藏', 'qilingshop'),
                        ]
                    ],
                    'all_products_name' => [
                        'type'  => 'text',
                        'label' => __('名称', 'qilingshop'),
                        'desc'  => __('默认：全部商品', 'qilingshop'),
                    ],
                    'all_products_icon' => [
                        'type'  => 'text',
                        'label' => __('图标链接', 'qilingshop'),
                        'desc'  => __('留空使用默认图标', 'qilingshop'),
                    ],
                    'all_products_link' => [
                        'type'  => 'text',
                        'label' => __('链接地址', 'qilingshop'),
                        'desc'  => __('留空自动获取全部商品页面', 'qilingshop'),
                    ],
                    'all_products_position' => [
                        'type'    => 'select',
                        'label'   => __('显示位置', 'qilingshop'),
                        'options' => [
                            'first' => __('最前面', 'qilingshop'),
                            'last'  => __('最后面', 'qilingshop'),
                        ]
                    ],
                ]
            ],
            'layout' => [
                'title' => __('布局样式', 'qilingshop'),
                'fields' => [
                    'style' => [
                        'type'    => 'select',
                        'label'   => __('展示风格', 'qilingshop'),
                        'options' => [
                            'icon' => __('图标 + 文字', 'qilingshop'),
                            'text' => __('纯文字标签', 'qilingshop'),
                            'card' => __('卡片模式（图片背景）', 'qilingshop'),
                        ]
                    ],
                    'columns_pc' => [
                        'type'    => 'select',
                        'label'   => __('电脑端列数', 'qilingshop'),
                        'options' => [
                            4 => __('4列', 'qilingshop'),
                            5 => __('5列', 'qilingshop'),
                            6 => __('6列', 'qilingshop'),
                            8 => __('8列', 'qilingshop'),
                            10 => __('10列', 'qilingshop'),
                        ]
                    ],
                    'columns_mobile' => [
                        'type'    => 'select',
                        'label'   => __('移动端列数', 'qilingshop'),
                        'options' => [
                            2 => __('2列', 'qilingshop'),
                            3 => __('3列', 'qilingshop'),
                            4 => __('4列', 'qilingshop'),
                            5 => __('5列', 'qilingshop'),
                        ]
                    ],
                ]
            ],
            'appearance' => [
                'title' => __('外观设置', 'qilingshop'),
                'fields' => [
                    'icon_size' => [
                        'type'  => 'text',
                        'label' => __('图标尺寸', 'qilingshop'),
                        'desc'  => __('例如 40px, 3rem', 'qilingshop'),
                    ],
                    'font_size' => [
                        'type'  => 'text',
                        'label' => __('文字大小', 'qilingshop'),
                        'desc'  => __('例如 14px', 'qilingshop'),
                    ],
                    'text_color' => [
                        'type'  => 'text', // 后台脚本会为颜色字段提供选择器
                        'label' => __('文字颜色', 'qilingshop'),
                        'desc'  => __('十六进制颜色值', 'qilingshop'),
                    ],
                    'bg_color' => [
                        'type'  => 'text',
                        'label' => __('背景颜色', 'qilingshop'),
                    ],
                    'margin_top' => [
                        'type'  => 'text',
                        'label' => __('上间距', 'qilingshop'),
                    ],
                    'margin_bottom' => [
                        'type'  => 'text',
                        'label' => __('下间距', 'qilingshop'),
                    ]
                ]
            ]
        ];
    }

    protected function render_content($atts) {
        // Enqueue Module CSS (Only once)
        $assets_version = function_exists('qilingshop_get_assets_version') ? qilingshop_get_assets_version() : (defined('QILINGSHOP_VERSION') ? QILINGSHOP_VERSION : '2.0.2');

        wp_enqueue_style(
            'qls-module-category-nav',
            QILINGSHOP_URL . 'static/shop/css/module-category-nav.css',
            [],
            $assets_version
        );

        // 1. Get Categories
        $categories = [];
        if ($atts['source'] === 'manual' && !empty($atts['menu_ids'])) {
            $ids = explode(',', $atts['menu_ids']);
            foreach ($ids as $id) {
                $cat = qls_category()->get(intval($id));
                if ($cat && $cat->status == 1) $categories[] = $cat;
            }
        } else {
            // Auto: Top level
            $raw_cats = qls_category()->get_tree(0); 
            $categories = array_filter($raw_cats, function($c){ return $c->status == 1; });
        }
        
        $limit = intval($atts['limit']) ?: 10;
        $categories = array_slice($categories, 0, $limit);
        
        // 准备"全部商品"入口数据
        $show_all_products = !empty($atts['show_all_products']);
        $all_products_item = null;
        
        if ($show_all_products) {
            $all_products_link = !empty($atts['all_products_link']) 
                ? $atts['all_products_link'] 
                : $this->get_all_products_url();
            
            $all_products_icon = !empty($atts['all_products_icon']) 
                ? $atts['all_products_icon'] 
                : QILINGSHOP_URL . 'static/img/cat-placeholder.svg';
            
            $all_products_item = (object) [
                'name'  => !empty($atts['all_products_name']) ? $atts['all_products_name'] : __('全部商品', 'qilingshop'),
                'image' => $all_products_icon,
                'link'  => $all_products_link,
                'is_all_products' => true,
            ];
        }
        
        // 如果没有分类且没有全部商品入口，则返回
        if (empty($categories) && !$all_products_item) return;

        // 2. CSS Variables for Dynamic Styling
        $unique_id = 'qls-cat-nav-' . uniqid();
        
        // Prepare variables
        $vars = [
            '--qls-nav-gap-x'      => $atts['gap_x'] ?? '10px',
            '--qls-nav-gap-y'      => $atts['gap_y'] ?? '10px',
            '--qls-nav-cols-pc'    => $atts['columns_pc'],
            '--qls-nav-cols-m'     => $atts['columns_mobile'],
            '--qls-nav-icon-size'  => $atts['icon_size'],
            '--qls-nav-font-size'  => $atts['font_size'],
            '--qls-nav-text-color' => $atts['text_color'],
            '--qls-nav-bg'         => $atts['bg_color'],
            '--qls-nav-mt'         => $atts['margin_top'],
            '--qls-nav-mb'         => $atts['margin_bottom'],
            '--qls-nav-pd'         => $atts['padding_content'] ?? '10px',
        ];

        $style_str = '';
        foreach ($vars as $key => $val) {
            $style_str .= "{$key}: {$val};";
        }

        // Classes
        $wrapper_classes = $this->get_class_names('qls-module-category-nav', [
            'style' => $atts['style']
        ]);
        
        // 渲染单个导航项
        $render_nav_item = function($item, $atts, $is_all_products = false) {
            $link = $is_all_products ? $item->link : qls_shop_public()->get_category_url($item);
            $image = !empty($item->image) ? $item->image : QILINGSHOP_URL . 'static/img/cat-placeholder.svg';
            $extra_class = $is_all_products ? ' qls-nav-item-all' : '';
            ?>
            <a href="<?php echo esc_url($link); ?>" class="qls-nav-item<?php echo esc_attr($extra_class); ?>">
                <?php if ($atts['style'] !== 'text'): ?>
                <div class="qls-nav-icon">
                    <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($item->name); ?>" loading="lazy">
                </div>
                <?php endif; ?>
                
                <span class="qls-nav-text"><?php echo esc_html($item->name); ?></span>
            </a>
            <?php
        };
        
        ?>
        <div id="<?php echo esc_attr($unique_id); ?>" class="qls-module <?php echo esc_attr($wrapper_classes); ?>" style="<?php echo esc_attr($style_str); ?>">
            <div class="qls-nav-grid">
                <?php 
                // 如果位置是最前面，先渲染"全部商品"
                if ($all_products_item && ($atts['all_products_position'] ?? 'first') === 'first') {
                    $render_nav_item($all_products_item, $atts, true);
                }
                
                // 渲染分类列表
                foreach ($categories as $cat) {
                    $render_nav_item($cat, $atts, false);
                }
                
                // 如果位置是最后面，最后渲染"全部商品"
                if ($all_products_item && ($atts['all_products_position'] ?? 'first') === 'last') {
                    $render_nav_item($all_products_item, $atts, true);
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * 获取全部商品页面链接
     * 
     * @return string 全部商品页面URL
     */
    private function get_all_products_url() {
        if (function_exists('qls_shop_public')) {
            $url = qls_shop_public()->get_page_url('all-products');
            if (!empty($url)) {
                return $url;
            }
            $shop_url = qls_shop_public()->get_shop_url();
            if (!empty($shop_url)) {
                return $shop_url;
            }
        }

        return home_url();
    }
}

