<?php
/**
 * 首屏轮播模块 (Hero Carousel)
 *
 * @package QilingShop
 * @since   2.0.2
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once 'class-qls-module-base.php';

class QLS_Module_Hero_Carousel extends QLS_Module_Base {

    public function get_defaults() {
        return [
            // Layout
            'layout'         => 'full',
            
            // Height
            'height_pc'      => '500px',
            'height_mobile'  => '300px',
            
            // Margins
            'margin_top'     => '0',
            'margin_bottom'  => '0',
            
            // 侧边栏
            'menu_source'    => 'auto',
            'menu_ids'       => '',
            'menu_max'       => 10,
            
            // 轮播图重复项
            'slides'         => [],
            
            // Options
            'autoplay'       => 'yes',
            'interval'       => 5000,
        ];
    }
    
    public function get_settings_fields() {
        return [
            'layout' => [
                'title' => __('布局设置', 'qilingshop'),
                'fields' => [
                    'layout' => [
                        'type'    => 'select',
                        'label'   => __('显示模式', 'qilingshop'),
                        'options' => [
                            'full'    => __('通栏全宽', 'qilingshop'),
                            'sidebar' => __('侧边栏叠加轮播', 'qilingshop'),
                            'boxed'   => __('居中自适应', 'qilingshop'),
                        ],
                        'desc'    => __('“侧边栏叠加轮播”模式：轮播全宽显示，侧边栏悬浮在内容区左侧。', 'qilingshop')
                    ],
                    'height_pc' => [
                        'type'  => 'text',
                        'label' => __('电脑端高度', 'qilingshop'),
                        'desc'  => __('支持像素或视窗高度，例如：500px、80vh、100vh', 'qilingshop'),
                    ],
                    'height_mobile' => [
                        'type'  => 'text',
                        'label' => __('移动端高度', 'qilingshop'),
                        'desc'  => __('例如：300px、40vh', 'qilingshop'),
                    ],
                    'margin_top' => [
                        'type'  => 'text',
                        'label' => __('上间距', 'qilingshop'),
                        'desc'  => __('例如：0、20px、-60px，负值可覆盖上方元素', 'qilingshop'),
                    ],
                    'margin_bottom' => [
                        'type'  => 'text',
                        'label' => __('下间距', 'qilingshop'),
                        'desc'  => __('例如：0、30px', 'qilingshop'),
                    ],
                    'menu_source' => [
                        'type'    => 'select',
                        'label'   => __('侧栏菜单来源', 'qilingshop'),
                        'options' => [
                            'auto'   => __('自动获取一级分类', 'qilingshop'),
                            'manual' => __('手动指定分类', 'qilingshop'),
                        ],
                        'desc'    => __('仅在“侧边栏叠加轮播”模式下有效', 'qilingshop')
                    ],
                    'menu_ids' => [
                        'type'  => 'text',
                        'label' => __('指定分类编号', 'qilingshop'),
                        'desc'  => __('多个编号用半角逗号分隔，例如：1,2,3', 'qilingshop'),
                    ]
                ]
            ],
            'slides' => [
                'title' => __('轮播内容', 'qilingshop'),
                'fields' => [
                    'slides' => [
                        'type' => 'repeater',
                        'label' => __('轮播项管理', 'qilingshop'),
                        'fields' => [
                            // 媒体内容
                            'image' => [
                                'type'  => 'image',
                                'label' => __('背景图片', 'qilingshop'),
                                'desc'  => __('建议尺寸：1920x800 或更大', 'qilingshop'),
                            ],
                            'image_mobile' => [
                                'type'  => 'image',
                                'label' => __('移动端图片（可选）', 'qilingshop'),
                                'desc'  => __('建议尺寸：750x600，不设置则使用电脑端图片', 'qilingshop'),
                            ],
                            'video' => [
                                'type'  => 'text',
                                'label' => __('视频链接（可选）', 'qilingshop'),
                                'desc'  => __('视频文件格式为 MP4，设置后优先显示视频', 'qilingshop'),
                            ],
                            // 文案内容
                            'title' => [
                                'type'  => 'text',
                                'label' => __('主标题', 'qilingshop'),
                            ],
                            'subtitle' => [
                                'type'  => 'text',
                                'label' => __('副标题/描述', 'qilingshop'),
                            ],
                            'text_align' => [
                                'type'    => 'select',
                                'label'   => __('文字位置', 'qilingshop'),
                                'options' => [
                                    'left'   => __('左对齐', 'qilingshop'),
                                    'center' => __('居中', 'qilingshop'),
                                    'right'  => __('右对齐', 'qilingshop'),
                                ],
                            ],
                            'text_color' => [
                                'type'    => 'select',
                                'label'   => __('文字配色', 'qilingshop'),
                                'options' => [
                                    'light' => __('浅色 (白字)', 'qilingshop'),
                                    'dark'  => __('深色 (黑字)', 'qilingshop'),
                                ],
                            ],
                            // Primary Button
                            'btn_text' => [
                                'type'  => 'text',
                                'label' => __('按钮1 文字', 'qilingshop'),
                            ],
                            'btn_url' => [
                                'type'  => 'text',
                                'label' => __('按钮1 链接', 'qilingshop'),
                            ],
                            'btn_style' => [
                                'type'    => 'select',
                                'label'   => __('按钮1 样式', 'qilingshop'),
                                'options' => [
                                    'solid'   => __('实心', 'qilingshop'),
                                    'outline' => __('线框', 'qilingshop'),
                                    'ghost'   => __('透明', 'qilingshop'),
                                ],
                            ],
                            // Secondary Button
                            'btn2_text' => [
                                'type'  => 'text',
                                'label' => __('按钮2 文字 (可选)', 'qilingshop'),
                            ],
                            'btn2_url' => [
                                'type'  => 'text',
                                'label' => __('按钮2 链接', 'qilingshop'),
                            ],
                            // Link (整块点击)
                            'link' => [
                                'type'  => 'text',
                                'label' => __('整块链接 (无按钮时使用)', 'qilingshop'),
                            ],
                        ]
                    ]
                ]
            ],
            'options' => [
                'title' => __('播放设置', 'qilingshop'),
                'fields' => [
                    'autoplay' => [
                        'type'    => 'select',
                        'label'   => __('自动播放', 'qilingshop'),
                        'options' => ['yes' => __('开启', 'qilingshop'), 'no' => __('关闭', 'qilingshop')]
                    ],
                    'interval' => [
                        'type'  => 'number',
                        'label' => __('切换间隔 (ms)', 'qilingshop'),
                        'desc'  => __('默认 5000 (5秒)', 'qilingshop'),
                    ]
                ]
            ]
        ];
    }

    protected function render_content($atts) {
        $slides = [];
        if (!empty($atts['slides']) && is_array($atts['slides'])) {
            $slides = $atts['slides'];
        }

        if (empty($slides)) {
            if (current_user_can('manage_options')) {
                echo '<div style="padding:40px; text-align:center; background:#f5f5f5; border:2px dashed #ddd; margin:20px 0;">';
                echo '<p style="color:#666;">' . __('请在后台添加轮播图', 'qilingshop') . '</p>';
                echo '</div>';
            }
            return;
        }

        // Extract settings
        $height_pc = $atts['height_pc'] ?: '500px';
        $height_mobile = $atts['height_mobile'] ?: '300px';
        $layout = $atts['layout'] ?? 'full';
        $full_screen = ($atts['full_screen'] ?? 'no') === 'yes';
        $margin_top = $atts['margin_top'] ?? '0';
        $margin_bottom = $atts['margin_bottom'] ?? '0';
        $autoplay = ($atts['autoplay'] ?? 'yes') === 'yes';
        $interval = intval($atts['interval'] ?? 5000);
        
        // Add body class for full-screen mode (allows theme CSS to respond)
        if ($full_screen && $layout === 'full') {
            add_filter('body_class', function($classes) {
                $classes[] = 'qls-hero-fullscreen';
                return $classes;
            });
        }
        
        $unique_id = 'qls-hero-' . uniqid();
        // 侧边栏模式跟随页面内容宽度，避免超出商城容器。
        $is_boxed = ($layout === 'boxed' || $layout === 'sidebar');
        
        // Ensure margin values have units
        if (is_numeric($margin_top)) $margin_top .= 'px';
        if (is_numeric($margin_bottom)) $margin_bottom .= 'px';
        ?>
        
        <style>
            #<?php echo $unique_id; ?> {
                --hero-height: <?php echo esc_attr($height_pc); ?>;
                --hero-height-m: <?php echo esc_attr($height_mobile); ?>;
                --hero-max-width: var(--qls-content-max-width, min(var(--qls-container-width, 1320px), calc(100vw - 40px)));
                --hero-sidebar-width: clamp(190px, 13.2vw, 252px);
                --hero-sidebar-gap: clamp(16px, 1.2vw, 26px);
                --hero-accent: var(--qls-primary, #ff4d2d);
                --hero-accent-strong: var(--qls-primary-hover, #e73f22);
                --hero-accent-soft: rgba(255, 77, 45, 0.14);
                --hero-accent-soft-weak: rgba(255, 77, 45, 0.06);
                --hero-panel-surface: var(--qls-card-bg, #ffffff);
                --hero-panel-surface-muted: #f8fafc;
                --hero-border: var(--qls-border, #e5e7eb);
                --hero-text: var(--qls-text, #111827);
                --hero-text-muted: var(--qls-text-secondary, #4b5563);
                --hero-sidebar-surface: rgba(15, 23, 42, 0.72);
                --hero-sidebar-surface-end: rgba(15, 23, 42, 0.5);
                --hero-sidebar-text: rgba(255, 255, 255, 0.98);
                --hero-sidebar-text-muted: rgba(255, 255, 255, 0.9);
                --hero-sidebar-hover: rgba(255, 255, 255, 0.12);
                margin-top: <?php echo esc_attr($margin_top); ?>;
                margin-bottom: <?php echo esc_attr($margin_bottom); ?>;
            }

            /* 布局逻辑 */
            <?php if ($is_boxed): ?>
                /* 居中模式：跟随商城容器宽度变量 */
                #<?php echo $unique_id; ?> {
                    position: relative;
                    width: 100%;
                    max-width: var(--hero-max-width);
                    margin-left: auto;
                    margin-right: auto;
                    overflow: hidden;
                    border-radius: clamp(10px, 0.8vw, 16px);
                    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.12);
                }
            <?php else: ?>
                /* 通栏模式：使用完整视窗宽度 */
                #<?php echo $unique_id; ?> {
                    width: 100vw;
                    position: relative;
                    left: 50%;
                    right: 50%;
                    margin-left: -50vw;
                    margin-right: -50vw;
                    overflow: hidden;
                    <?php if ($full_screen): ?>
                    margin-top: -80px; 
                    <?php endif; ?>
                }
            <?php endif; ?>

            #<?php echo $unique_id; ?> .qls-hero-slider {
                height: var(--hero-height);
                width: 100%;
                border-radius: inherit;
            }
            
            /* Overlay Container: Centers content/sidebar */
            #<?php echo $unique_id; ?> .qls-hero-overlay-container {
                position: absolute;
                top: 0;
                left: 50%;
                transform: translateX(-50%);
                width: 100%;
                max-width: var(--hero-max-width);
                height: 100%;
                z-index: 10;
                pointer-events: none;
                overflow: visible; /* 确保子菜单不被裁剪 */
            }
            /* 居中模式下叠加层跟随父容器 */
            <?php if ($is_boxed): ?>
            #<?php echo $unique_id; ?> .qls-hero-overlay-container {
                left: 0;
                transform: none;
                max-width: 100%;
            }
            <?php endif; ?>

            /* 侧边栏叠加层 */
            #<?php echo $unique_id; ?> .qls-hero-sidebar {
                position: absolute;
                top: 0;
                left: 0;
                width: var(--hero-sidebar-width);
                height: 100%;
                z-index: 20;
                pointer-events: auto;
                background: linear-gradient(180deg, var(--hero-sidebar-surface) 0%, var(--hero-sidebar-surface-end) 100%);
                backdrop-filter: blur(8px);
                overflow: visible; /* 确保子菜单不被裁剪 */
                border-radius: clamp(10px, 0.8vw, 16px) 0 0 clamp(10px, 0.8vw, 16px);
                border-right: 1px solid rgba(255, 255, 255, 0.16);
                box-shadow: 10px 0 30px rgba(0, 0, 0, 0.22);
            }

            #<?php echo $unique_id; ?> .qls-hero-menu-head {
                display: flex;
                align-items: center;
                height: clamp(44px, 2.6vw, 52px);
                padding: 0 clamp(14px, 1.1vw, 22px);
                margin: 0;
                color: var(--hero-sidebar-text);
                font-size: clamp(13px, 0.92vw, 15px);
                font-weight: 700;
                letter-spacing: 0.02em;
                border-bottom: 1px solid rgba(255, 255, 255, 0.16);
                background: rgba(255, 255, 255, 0.08);
            }
            
            /* 侧边栏菜单样式 */
            #<?php echo $unique_id; ?> .qls-hero-menu {
                list-style: none;
                margin: 0;
                padding: 12px 0;
                height: 100%;
                overflow: visible; /* 确保不裁剪子菜单 */
            }
            #<?php echo $unique_id; ?> .qls-hero-menu li {
                position: static; /* 让子菜单相对于sidebar定位 */
            }
            #<?php echo $unique_id; ?> .qls-hero-menu li > a {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 0 clamp(14px, 1.1vw, 22px);
                height: clamp(40px, 2.2vw, 50px);
                line-height: clamp(40px, 2.2vw, 50px);
                color: var(--hero-sidebar-text-muted);
                text-decoration: none;
                font-size: clamp(13px, 0.9vw, 15px);
                font-weight: 500;
                transition: all 0.2s ease;
            }
            #<?php echo $unique_id; ?> .qls-hero-menu li > a:hover {
                background: var(--hero-sidebar-hover);
                color: #fff;
                transform: translateX(2px);
            }
            #<?php echo $unique_id; ?> .qls-hero-menu li.has-children:hover {
                background: var(--hero-sidebar-hover);
            }
            #<?php echo $unique_id; ?> .qls-hero-menu li .dashicons {
                font-size: 12px;
                width: 12px;
                height: 12px;
                opacity: 0.75;
                transition: transform 0.2s ease;
            }
            #<?php echo $unique_id; ?> .qls-hero-menu li:hover .dashicons {
                transform: translateX(3px);
                opacity: 1;
            }
            /* Submenu - 弹出面板 */
            #<?php echo $unique_id; ?> .qls-sub-menu {
                display: none;
                position: absolute;
                left: var(--hero-sidebar-width); /* sidebar宽度，紧贴右侧 */
                top: 0;
                width: auto; /* 自适应内容宽度 */
                min-width: clamp(220px, 22vw, 340px);
                max-width: min(760px, calc(100vw - var(--hero-sidebar-width) - var(--hero-sidebar-gap) - 24px));
                height: auto; /* 自适应内容高度 */
                max-height: 100%; /* 最大不超过sidebar高度 */
                background: var(--hero-panel-surface);
                box-shadow: 0 14px 38px rgba(15, 23, 42, 0.16);
                padding: 20px 25px;
                z-index: 9999;
                overflow-y: auto;
                box-sizing: border-box;
                border-radius: 0 14px 14px 14px;
                border: 1px solid var(--hero-border);
            }
            /* 悬停到li时显示子菜单 */
            #<?php echo $unique_id; ?> .qls-menu-item.has-children:hover .qls-sub-menu {
                display: flex;
                flex-wrap: wrap;
                align-content: flex-start;
                gap: 8px 20px;
            }
            #<?php echo $unique_id; ?> .qls-sub-menu a {
                display: flex;
                align-items: center;
                width: auto; /* 自适应宽度 */
                padding: 10px 14px;
                color: var(--hero-text-muted);
                text-decoration: none;
                font-size: 14px;
                border-radius: 8px;
                transition: all 0.2s ease;
                background: var(--hero-panel-surface-muted);
                white-space: nowrap;
            }
            #<?php echo $unique_id; ?> .qls-sub-menu a:hover {
                color: var(--hero-accent);
                background: rgba(255, 77, 45, 0.1);
            }

            :is(html.dark-mode, body.dark-mode, [data-theme='dark']) #<?php echo $unique_id; ?> {
                --hero-panel-surface: #1f2937;
                --hero-panel-surface-muted: #111827;
                --hero-border: #374151;
                --hero-text: #f9fafb;
                --hero-text-muted: #d1d5db;
                --hero-sidebar-surface: rgba(17, 24, 39, 0.84);
                --hero-sidebar-surface-end: rgba(17, 24, 39, 0.66);
                --hero-sidebar-text: #f9fafb;
                --hero-sidebar-text-muted: #e5e7eb;
                --hero-sidebar-hover: rgba(255, 255, 255, 0.11);
            }
            
            /* Common styles */
            #<?php echo $unique_id; ?> .swiper-slide {
                width: 100%;
                height: 100%;
            }
            #<?php echo $unique_id; ?> .qls-hero-item {
                display: block;
                width: 100%;
                height: 100%;
                position: relative;
            }
            #<?php echo $unique_id; ?> .qls-hero-img,
            #<?php echo $unique_id; ?> .qls-hero-video {
                width: 100%;
                height: 100%;
                object-fit: cover;
            }
            #<?php echo $unique_id; ?> .qls-hero-video {
                position: absolute;
                top: 0;
                left: 0;
                z-index: 1;
            }
            
            /* Hero Content Positioning */
            #<?php echo $unique_id; ?> .qls-hero-content {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                z-index: 5;
                pointer-events: none;
                display: flex;
                align-items: center; /* Vertical Center */
            }
            
            /* Responsive Content Width */
            #<?php echo $unique_id; ?> .qls-hero-content-inner {
                pointer-events: auto;
                max-width: min(540px, 40vw);
                padding: 20px;
                border-radius: 14px;
                border: 1px solid rgba(255, 255, 255, 0.2);
                background: linear-gradient(145deg, rgba(0, 0, 0, 0.36) 0%, rgba(0, 0, 0, 0.2) 100%);
                backdrop-filter: blur(4px);
                box-shadow: 0 12px 34px rgba(0, 0, 0, 0.24);
            }
            
            /* Adjust content position based on layout */
            /* 侧边栏显示时，左对齐文案向右留出空间 */
            <?php if ($layout === 'sidebar'): ?>
                #<?php echo $unique_id; ?> .qls-hero-item.text-left .qls-hero-content-inner {
                    margin-left: calc(var(--hero-sidebar-width) + var(--hero-sidebar-gap));
                }
            <?php else: ?>
                #<?php echo $unique_id; ?> .qls-hero-item.text-left .qls-hero-content-inner {
                    margin-left: 0;
                }
            <?php endif; ?>
            
            /* Text Alignment */
            #<?php echo $unique_id; ?> .qls-hero-item.text-left .qls-hero-content { justify-content: flex-start; }
            #<?php echo $unique_id; ?> .qls-hero-item.text-center .qls-hero-content { justify-content: center; }
            #<?php echo $unique_id; ?> .qls-hero-item.text-right .qls-hero-content { justify-content: flex-end; }

            /* Text Colors */
            #<?php echo $unique_id; ?> .qls-hero-item.color-light .qls-hero-content {
                color: #fff;
                text-shadow: 0 2px 10px rgba(0,0,0,0.3);
            }
            #<?php echo $unique_id; ?> .qls-hero-item.color-dark .qls-hero-content {
                color: #1a1a1a;
                text-shadow: none;
            }
            #<?php echo $unique_id; ?> .qls-hero-item.color-dark .qls-hero-content-inner {
                border-color: rgba(17, 24, 39, 0.12);
                background: linear-gradient(150deg, rgba(255, 255, 255, 0.92) 0%, rgba(248, 250, 252, 0.88) 100%);
                box-shadow: 0 12px 28px rgba(15, 23, 42, 0.14);
            }
            /* Headers */
            #<?php echo $unique_id; ?> .qls-hero-title {
                margin: 0 0 12px;
                font-size: clamp(32px, 2.1vw, 44px);
                font-weight: 700;
                line-height: 1.2;
            }
            #<?php echo $unique_id; ?> .qls-hero-subtitle {
                margin: 0 0 24px;
                font-size: clamp(15px, 1vw, 18px);
                opacity: 0.9;
                line-height: 1.5;
            }
            /* Buttons */
            #<?php echo $unique_id; ?> .qls-hero-buttons {
                display: flex;
                gap: 12px;
                flex-wrap: wrap;
            }
            #<?php echo $unique_id; ?> .qls-hero-item.text-center .qls-hero-buttons { justify-content: center; }
            #<?php echo $unique_id; ?> .qls-hero-item.text-right .qls-hero-buttons { justify-content: flex-end; }
            
            #<?php echo $unique_id; ?> .qls-hero-btn {
                display: inline-block;
                padding: 12px 26px;
                font-size: 15px;
                font-weight: 600;
                text-decoration: none;
                border-radius: 999px;
                transition: all 0.3s ease;
            }
            #<?php echo $unique_id; ?> .qls-btn-solid { background: linear-gradient(135deg, var(--hero-accent) 0%, var(--hero-accent-strong) 100%); color: #fff; border: 2px solid transparent; box-shadow: 0 8px 18px rgba(255, 77, 45, 0.35); }
            #<?php echo $unique_id; ?> .qls-btn-solid:hover { filter: brightness(0.95); transform: translateY(-2px); }
            #<?php echo $unique_id; ?> .qls-btn-outline { background: transparent; border: 2px solid currentColor; }
            #<?php echo $unique_id; ?> .qls-btn-outline:hover { background: rgba(255,255,255,0.1); }
            #<?php echo $unique_id; ?> .qls-btn-ghost { background: rgba(255,255,255,0.2); color: #fff; border: none; backdrop-filter: blur(10px); }

            @media (min-width: 1440px) {
                #<?php echo $unique_id; ?> {
                    --hero-sidebar-width: clamp(220px, 14vw, 286px);
                    --hero-sidebar-gap: clamp(20px, 1.4vw, 30px);
                }

                #<?php echo $unique_id; ?> .qls-hero-content-inner {
                    max-width: min(620px, 42vw);
                    padding: clamp(24px, 1.5vw, 34px);
                }

                #<?php echo $unique_id; ?> .qls-hero-title {
                    font-size: clamp(40px, 2.35vw, 54px);
                    margin-bottom: 14px;
                }

                #<?php echo $unique_id; ?> .qls-hero-subtitle {
                    font-size: clamp(18px, 1.1vw, 22px);
                    margin-bottom: 26px;
                }

                #<?php echo $unique_id; ?> .qls-hero-btn {
                    font-size: clamp(15px, 0.95vw, 17px);
                    padding: 13px 30px;
                }
            }

            @media (min-width: 1920px) {
                #<?php echo $unique_id; ?> {
                    --hero-sidebar-width: clamp(248px, 14.5vw, 320px);
                }
            }
            
            /* Swiper Controls */
            #<?php echo $unique_id; ?> .swiper-pagination-bullet { width: 10px; height: 10px; background: rgba(255,255,255,0.5); opacity: 1; border: 2px solid transparent; }
            #<?php echo $unique_id; ?> .swiper-pagination-bullet-active { background: rgba(255,255,255,1); border-color: rgba(0,0,0,0.1); width: 24px; border-radius: 5px; }
            
            #<?php echo $unique_id; ?> .swiper-button-prev,
            #<?php echo $unique_id; ?> .swiper-button-next { color: rgba(255,255,255,0.8); width: 44px; height: 70px; background: rgba(0,0,0,0.1); transition: all 0.3s; }
            #<?php echo $unique_id; ?> .swiper-button-prev:hover,
            #<?php echo $unique_id; ?> .swiper-button-next:hover { background: rgba(0,0,0,0.4); }
            #<?php echo $unique_id; ?> .swiper-button-prev::after, .swiper-button-next::after { font-size: 24px; }
            
            /* Mobile */
            @media (max-width: 768px) {
                #<?php echo $unique_id; ?> .qls-hero-slider { height: var(--hero-height-m); }
                #<?php echo $unique_id; ?> .qls-hero-sidebar { display: none; }
                
                #<?php echo $unique_id; ?> .qls-hero-item.text-left .qls-hero-content-inner { margin-left: 0; }
                
                #<?php echo $unique_id; ?> .qls-hero-content {
                    width: 100%;
                    max-width: 100%;
                    justify-content: center !important;
                }
                #<?php echo $unique_id; ?> .qls-hero-content-inner {
                    max-width: 90%;
                    padding: 15px;
                    text-align: center !important;
                    margin: 0 auto !important;
                }
                #<?php echo $unique_id; ?> .qls-hero-buttons { justify-content: center !important; }
            }
        </style>
        
        <div id="<?php echo esc_attr($unique_id); ?>" class="qls-module-hero-carousel layout-<?php echo esc_attr($layout); ?><?php echo $full_screen ? ' full-screen' : ''; ?>">
            
            <!-- 独立元素叠加容器（侧边栏等） -->
            <div class="qls-hero-overlay-container">
                
                <?php if ($layout === 'sidebar'): ?>
                <div class="qls-hero-sidebar">
                    <?php $this->render_sidebar_menu($atts); ?>
                </div>
                <?php endif; ?>
                
            </div>

            <div class="qls-hero-slider swiper" data-autoplay="<?php echo $autoplay ? 'true' : 'false'; ?>" data-interval="<?php echo $interval; ?>">
                <div class="swiper-wrapper">
                    <?php foreach ($slides as $slide): 
                        $text_align = $slide['text_align'] ?? 'center';
                        $text_color = $slide['text_color'] ?? 'light';
                        $has_content = !empty($slide['title']) || !empty($slide['subtitle']) || !empty($slide['btn_text']);
                        $has_buttons = !empty($slide['btn_text']) || !empty($slide['btn2_text']);
                        $slide_link = (!$has_buttons && !empty($slide['link'])) ? esc_url($slide['link']) : '';
                    ?>
                    <div class="swiper-slide">
                        <?php if ($slide_link): ?>
                        <a href="<?php echo $slide_link; ?>" class="qls-hero-item text-<?php echo esc_attr($text_align); ?> color-<?php echo esc_attr($text_color); ?>">
                        <?php else: ?>
                        <div class="qls-hero-item text-<?php echo esc_attr($text_align); ?> color-<?php echo esc_attr($text_color); ?>">
                        <?php endif; ?>
                        
                            <?php if (!empty($slide['video'])): ?>
                                <video class="qls-hero-video" src="<?php echo esc_url($slide['video']); ?>" autoplay muted loop playsinline></video>
                            <?php endif; ?>
                            
                            <?php if (!empty($slide['image'])): ?>
                                <?php if (!empty($slide['image_mobile'])): ?>
                                <picture>
                                    <source media="(max-width: 768px)" srcset="<?php echo esc_url($slide['image_mobile']); ?>">
                                    <img src="<?php echo esc_url($slide['image']); ?>" alt="<?php echo esc_attr($slide['title'] ?? ''); ?>" class="qls-hero-img" loading="lazy">
                                </picture>
                                <?php else: ?>
                                <img src="<?php echo esc_url($slide['image']); ?>" alt="<?php echo esc_attr($slide['title'] ?? ''); ?>" class="qls-hero-img" loading="lazy">
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php if ($has_content): ?>
                            <div class="qls-hero-content">
                                <div class="qls-hero-overlay-container" style="position:relative; left:auto; transform:none; top:auto; height:100%; display:flex; align-items:center;">
                                    <div class="qls-hero-content-inner">
                                        <?php if (!empty($slide['title'])): ?>
                                            <h2 class="qls-hero-title"><?php echo esc_html($slide['title']); ?></h2>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($slide['subtitle'])): ?>
                                            <p class="qls-hero-subtitle"><?php echo esc_html($slide['subtitle']); ?></p>
                                        <?php endif; ?>
                                        
                                        <?php if ($has_buttons): ?>
                                        <div class="qls-hero-buttons">
                                            <?php if (!empty($slide['btn_text']) && !empty($slide['btn_url'])): 
                                                $btn_style = $slide['btn_style'] ?? 'solid';
                                            ?>
                                            <a href="<?php echo esc_url($slide['btn_url']); ?>" class="qls-hero-btn qls-btn-<?php echo esc_attr($btn_style); ?>"><?php echo esc_html($slide['btn_text']); ?></a>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($slide['btn2_text']) && !empty($slide['btn2_url'])): ?>
                                            <a href="<?php echo esc_url($slide['btn2_url']); ?>" class="qls-hero-btn qls-btn-outline"><?php echo esc_html($slide['btn2_text']); ?></a>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                        <?php if ($slide_link): ?>
                        </a>
                        <?php else: ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="swiper-pagination"></div>
                <div class="swiper-button-prev"></div>
                <div class="swiper-button-next"></div>
            </div>
        </div>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var container = document.querySelector('#<?php echo $unique_id; ?>');
            var el = container ? container.querySelector('.swiper') : null;
            
            if (el && typeof Swiper !== 'undefined') {
                new Swiper(el, {
                    loop: true,
                    autoplay: el.dataset.autoplay === 'true' ? { delay: parseInt(el.dataset.interval) || 5000 } : false,
                    pagination: { el: '#<?php echo $unique_id; ?> .swiper-pagination', clickable: true },
                    navigation: { nextEl: '#<?php echo $unique_id; ?> .swiper-button-next', prevEl: '#<?php echo $unique_id; ?> .swiper-button-prev' }
                });
            }
        });
        </script>
        <?php
    }

    private function render_sidebar_menu($atts) {
        $categories = [];
        if ($atts['menu_source'] === 'manual' && !empty($atts['menu_ids'])) {
            $ids = explode(',', $atts['menu_ids']);
            foreach ($ids as $id) {
                $cat = qls_category()->get(intval($id));
                if ($cat) $categories[] = $cat;
            }
        } else {
            // Auto: get top level categories
            $categories = qls_category()->get_tree(0); // Assuming get_tree(0) gets roots. Check qls_category implementation if needed.
        }
        
        // Limit
        $max = intval($atts['menu_max']) ?: 10;
        $categories = array_slice($categories, 0, $max);

        echo '<div class="qls-hero-menu-head">' . esc_html__('全部商品分类', 'qilingshop') . '</div>';
        echo '<ul class="qls-hero-menu">';
        foreach ($categories as $cat) {
            $link = qls_shop_public()->get_category_url($cat);
            $has_children = !empty($cat->children);
            
            echo '<li class="qls-menu-item ' . ($has_children ? 'has-children' : '') . '">';
            echo '<a href="' . esc_url($link) . '">';
            // 分类菜单当前仅输出名称。
            echo '<span class="text">' . esc_html($cat->name) . '</span>';
            if ($has_children) echo '<i class="dashicons dashicons-arrow-right-alt2"></i>';
            echo '</a>';
            
            // Submenu
            if ($has_children) {
                 echo '<div class="qls-sub-menu">';
                 foreach ($cat->children as $child) {
                     $child_link = qls_shop_public()->get_category_url($child);
                     echo '<a href="' . esc_url($child_link) . '">' . esc_html($child->name) . '</a>';
                 }
                 echo '</div>';
            }
            
            echo '</li>';
        }
        echo '</ul>';
    }
}
