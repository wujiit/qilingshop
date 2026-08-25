<?php
/**
 * 拼团模块
 * 
 * 商城首页装修模块 - 展示拼团商品
 *
 * @package QilingShop
 * @since   2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once 'class-qls-module-base.php';

class QLS_Module_Group extends QLS_Module_Base {

    /**
     * 获取模块默认属性
     */
    public function get_defaults() {
        return [
            // 内容设置
            'title'           => __('限时拼团', 'qilingshop'),
            'limit'           => 4,
            'source'          => 'auto', // auto: 自动获取(最新拼团), manual: 手动指定
            'product_ids'     => '',     // 手动指定的商品编号
            'show_more'       => 'yes',  // 是否显示更多按钮
            'more_text'       => '',     // 更多按钮文案
            
            // 布局设置
            'style'           => 'card', // card: 网格卡片, scroll: 横向滚动
            'columns_pc'      => 4,
            'columns_mobile'  => 2,
            
            // 外观设置
            'show_price'      => 'yes',
            'show_original_price' => 'yes',
            'show_badge'      => 'yes', // 显示拼团角标
            'show_progress'   => 'yes', // 显示已拼进度
            'show_btn'        => 'yes', // 显示去拼团按钮
            
            // 颜色
            'primary_color'   => '#ff4400', // 拼团红
            'bg_color'        => '#fff0e6', // 浅色背景
        ];
    }

    /**
     * 获取设置字段
     */
    public function get_settings_fields() {
        return [
            'content' => [
                'title'  => __('内容设置', 'qilingshop'),
                'fields' => [
                    'title' => [
                        'type'  => 'text',
                        'label' => __('模块标题', 'qilingshop'),
                    ],
                    'show_more' => [
                        'type'    => 'select',
                        'label'   => __('显示“参加更多”', 'qilingshop'),
                        'options' => [
                            'yes' => __('显示', 'qilingshop'),
                            'no'  => __('隐藏', 'qilingshop'),
                        ]
                    ],
                    'more_text' => [
                        'type'  => 'text',
                        'label' => __('按钮文案', 'qilingshop'),
                        'desc'  => __('默认为“参加更多”', 'qilingshop'),
                    ],
                    'source' => [
                        'type'    => 'select',
                        'label'   => __('数据来源', 'qilingshop'),
                        'options' => [
                            'auto'   => __('自动获取最新拼团', 'qilingshop'),
                            'manual' => __('手动指定商品', 'qilingshop'),
                        ]
                    ],
                    'product_ids' => [
                        'type'  => 'text',
                        'label' => __('商品编号列表', 'qilingshop'),
                        'desc'  => __('仅手动模式有效，多个编号用半角逗号分隔', 'qilingshop'),
                    ],
                    'limit' => [
                        'type'  => 'number',
                        'label' => __('显示数量', 'qilingshop'),
                    ],
                ]
            ],
            'layout' => [
                'title'  => __('布局设置', 'qilingshop'),
                'fields' => [
                    'style' => [
                        'type'    => 'select',
                        'label'   => __('展示风格', 'qilingshop'),
                        'options' => [
                            'card'   => __('卡片网格', 'qilingshop'),
                            'scroll' => __('横向滚动', 'qilingshop'),
                        ]
                    ],
                    'columns_pc' => [
                        'type'    => 'select',
                        'label'   => __('电脑端列数', 'qilingshop'),
                        'options' => [2 => __('2列', 'qilingshop'), 3 => __('3列', 'qilingshop'), 4 => __('4列', 'qilingshop')],
                    ],
                ]
            ],
            'appearance' => [
                'title'  => __('外观设置', 'qilingshop'),
                'fields' => [
                    'show_progress' => [
                        'type'    => 'select',
                        'label'   => __('显示已拼进度', 'qilingshop'),
                        'options' => ['yes'=>__('显示','qilingshop'), 'no'=>__('隐藏','qilingshop')],
                    ],
                    'show_btn' => [
                        'type'    => 'select',
                        'label'   => __('显示按钮', 'qilingshop'),
                        'options' => ['yes'=>__('显示','qilingshop'), 'no'=>__('隐藏','qilingshop')],
                    ],
                    'primary_color' => [
                        'type'  => 'text',
                        'label' => __('主题色', 'qilingshop'),
                    ],
                    'bg_color' => [
                        'type'  => 'text',
                        'label' => __('背景颜色', 'qilingshop'),
                    ],
                ]
            ]
        ];
    }

    /**
     * 渲染内容
     */
    protected function render_content($atts) {
        $assets_version = function_exists('qilingshop_get_assets_version')
            ? qilingshop_get_assets_version()
            : (defined('QILINGSHOP_VERSION') ? QILINGSHOP_VERSION : '2.0.2');
        
        // 加载专属样式 (稍后创建)
        wp_enqueue_style(
            'qls-module-group',
            QILINGSHOP_URL . 'static/shop/css/module-group.css',
            [],
            $assets_version
        );

        $products = $this->get_products($atts);
        
        // 如果没有商品，管理员可见提示
        if (empty($products)) {
            if (current_user_can('manage_options')) {
                echo '<div class="qls-notice" style="padding:20px;text-align:center;background:#fff0e6;border:1px dashed #ff4400;color:#ff4400;border-radius:8px;margin-bottom:20px;">';
                echo '<p>' . __('【拼团专区】暂无拼团商品，请在商品编辑页面开启“限时拼团”并保存。', 'qilingshop') . '</p>';
                echo '</div>';
            }
            return;
        }

        $unique_id = 'qls-group-' . wp_rand(1000, 9999);
        
        $css_vars = "--qls-grp-primary: {$atts['primary_color']}; --qls-grp-bg: {$atts['bg_color']}; --qls-grp-cols: {$atts['columns_pc']};";
        
        $wrapper_classes = $this->get_class_names('qls-module-group', ['style' => $atts['style']]);
        
        // 按钮文案 (默认为"参加更多")
        $more_text = !empty($atts['more_text']) ? $atts['more_text'] : __('参加更多', 'qilingshop');
        $show_more = !isset($atts['show_more']) || $atts['show_more'] === 'yes';

        ?>
        <div id="<?php echo esc_attr($unique_id); ?>" class="qls-module <?php echo esc_attr($wrapper_classes); ?>" style="<?php echo esc_attr($css_vars); ?>">
            
            <?php if (!empty($atts['title'])): ?>
            <div class="qls-module-header">
                <h3 class="qls-module-title">
                    <span class="qls-grp-icon">
                        <!-- 火焰图标 SVG -->
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" class="qls-icon-fire"><path d="M12 23a7.5 7.5 0 0 1-5.138-12.963C8.204 8.774 11.5 6.5 11 1.5c6 4 9 8 3 14 1.075-2.16 1.09-4.471.04-6.437A7.46 7.46 0 0 1 12 23Z"/></svg>
                    </span>
                    <?php echo esc_html($atts['title']); ?>
                </h3>
                
                <?php if ($show_more): ?>
                <a href="<?php echo esc_url(qls_shop_public()->get_page_url('group_center') ?: home_url('/group-center')); ?>" class="qls-module-more">
                    <?php echo esc_html($more_text); ?> <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="qls-group-grid">
                <?php foreach ($products as $product): ?>
                    <?php $this->render_card($product, $atts); ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * 获取拼团商品
     */
    private function get_products($atts) {
        if ($atts['source'] === 'manual' && !empty($atts['product_ids'])) {
            $include_ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $atts['product_ids'])))));
            if (empty($include_ids)) {
                return [];
            }

            return qls_group()->get_group_products([
                'per_page' => max(count($include_ids), intval($atts['limit'])),
                'page'     => 1,
                'orderby'  => 'include',
                'include'  => $include_ids,
            ]);
        }

        $args = [
            'per_page' => intval($atts['limit']),
            'page'     => 1,
            'orderby'  => 'r.id',
            'order'    => 'DESC'
        ];

        return qls_group()->get_group_products($args);
    }

    /**
     * 渲染单张拼团卡片
     */
    private function render_card($product, $atts) {
        $link = qls_shop_public()->get_product_url($product);
        
        // 获取团购信息 (优先使用对象属性，兼容旧逻辑)
        $group_price = isset($product->group_price) ? $product->group_price : (get_post_meta($product->id, '_qls_group_price', true) ?: $product->price);
        $group_num = isset($product->group_size) ? $product->group_size : (get_post_meta($product->id, '_qls_group_num', true) ?: 2);
        
        // 模拟已拼数据 (如果有真实数据更好)
        // active_groups_count 和 success_member_count 是 get_group_products 返回的
        $sales = isset($product->success_member_count) ? intval($product->success_member_count) : (isset($product->sales_count) ? intval($product->sales_count) : 0);
        $progress = min(100, max(15, ($sales % 50) * 2)); 
        
        // 图片处理
        $thumb = '';
        if (!empty($product->main_image)) {
             $thumb = is_array($product->main_image) ? ($product->main_image['url'] ?? '') : $product->main_image;
        }

        ?>
        <div class="qls-group-card">
            <div class="qls-grp-thumb">
                <a href="<?php echo esc_url($link); ?>">
                    <?php if ($thumb): ?>
                        <img src="<?php echo esc_url($thumb); ?>" alt="<?php echo esc_attr($product->title); ?>">
                    <?php endif; ?>
                    
                    <!-- 团购角标图标 SVG -->
                    <div class="qls-grp-badge-icon">
                       <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" class="qls-icon-group">
                           <circle cx="12" cy="12" r="12" fill="var(--qls-grp-primary, #ff4400)"/>
                           <path d="M12 11.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5ZM12 13.5a4.5 4.5 0 0 0-4.5 4.5v.5h9v-.5a4.5 4.5 0 0 0-4.5-4.5Z" fill="#fff"/>
                           <path d="M6 10a2 2 0 1 0 0-4 2 2 0 0 0 0 4ZM6 12a3.5 3.5 0 0 0-3.5 3.5v.5h3.5" fill="#fff" opacity="0.7"/>
                           <path d="M18 10a2 2 0 1 1 0-4 2 2 0 0 1 0 4ZM18 12a3.5 3.5 0 0 1 3.5 3.5v.5h-3.5" fill="#fff" opacity="0.7"/>
                       </svg>
                       <span class="qls-grp-badge-text"><?php printf(esc_html__('%s人团', 'qilingshop'), esc_html(intval($group_num))); ?></span>
                    </div>
                </a>
            </div>
            
            <div class="qls-grp-info">
                <h4 class="qls-grp-title">
                    <a href="<?php echo esc_url($link); ?>"><?php echo esc_html($product->title); ?></a>
                </h4>
                
                <?php if ($atts['show_progress'] === 'yes'): ?>
                <div class="qls-grp-progress-bar">
                    <div class="qls-grp-progress-track">
                        <div class="qls-grp-progress-fill" style="width: <?php echo $progress; ?>%"></div>
                    </div>
                    <span class="qls-grp-sales"><?php printf(__('已抢%d件', 'qilingshop'), $sales); ?></span>
                </div>
                <?php endif; ?>

                <div class="qls-grp-bottom">
                    <div class="qls-grp-price-box">
                        <div class="qls-grp-price">
                            <span class="symbol">¥</span>
                            <span class="num"><?php echo number_format($group_price, 2); ?></span>
                        </div>
                        <?php if ($atts['show_original_price'] === 'yes'): ?>
                        <div class="qls-grp-original">¥<?php echo number_format($product->min_price ?? $product->price, 2); ?></div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($atts['show_btn'] === 'yes'): ?>
                    <a href="<?php echo esc_url($link); ?>" class="qls-grp-btn">
                        <?php _e('去拼团', 'qilingshop'); ?>
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
}
