<?php
/**
 * 新人专区装修模块
 *
 * 商城首页装修模块 - 展示新人专项商品
 *
 * @package QilingShop
 * @since   2.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once 'class-qls-module-base.php';

class QLS_Module_New_User_Zone extends QLS_Module_Base {

    /**
     * 获取模块默认属性
     *
     * @return array
     */
    public function get_defaults() {
        return [
            'title'            => __('新人专区', 'qilingshop'),
            'show_more'        => 'yes',
            'more_text'        => __('查看更多', 'qilingshop'),
            'show_status'      => 'yes',

            'source'           => 'auto', // auto|manual
            'product_ids'      => '',
            'limit'            => 4,

            'style'            => 'card', // card|scroll
            'columns_pc'       => 4,
            'columns_mobile'   => 2,

            'show_subtitle'    => 'yes',
            'show_sales'       => 'yes',
            'show_action'      => 'yes',

            'primary_color'    => '#ff4d2d',
            'bg_color'         => '#fff6f2',
            'card_bg'          => '#ffffff',
            'padding'          => '20px',
            'gap'              => '16px',
            'border_radius'    => '14px',
        ];
    }

    /**
     * 获取后台设置字段
     *
     * @return array
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
                        'label'   => __('显示“查看更多”', 'qilingshop'),
                        'options' => [
                            'yes' => __('显示', 'qilingshop'),
                            'no'  => __('隐藏', 'qilingshop'),
                        ],
                    ],
                    'more_text' => [
                        'type'  => 'text',
                        'label' => __('更多按钮文案', 'qilingshop'),
                    ],
                    'show_status' => [
                        'type'    => 'select',
                        'label'   => __('显示资格提示栏', 'qilingshop'),
                        'options' => [
                            'yes' => __('显示', 'qilingshop'),
                            'no'  => __('隐藏', 'qilingshop'),
                        ],
                    ],
                    'source' => [
                        'type'    => 'select',
                        'label'   => __('数据来源', 'qilingshop'),
                        'options' => [
                            'auto'   => __('自动获取新人商品', 'qilingshop'),
                            'manual' => __('手动指定商品', 'qilingshop'),
                        ],
                    ],
                    'product_ids' => [
                        'type'  => 'text',
                        'label' => __('商品编号列表', 'qilingshop'),
                        'desc'  => __('手动模式有效，多个编号用半角逗号分隔，例如：12,35,68', 'qilingshop'),
                    ],
                    'limit' => [
                        'type'  => 'number',
                        'label' => __('显示数量', 'qilingshop'),
                    ],
                ],
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
                        ],
                    ],
                    'columns_pc' => [
                        'type'    => 'select',
                        'label'   => __('电脑端列数', 'qilingshop'),
                        'options' => [2 => __('2列', 'qilingshop'), 3 => __('3列', 'qilingshop'), 4 => __('4列', 'qilingshop')],
                    ],
                    'columns_mobile' => [
                        'type'    => 'select',
                        'label'   => __('移动端列数', 'qilingshop'),
                        'options' => [1 => __('1列', 'qilingshop'), 2 => __('2列', 'qilingshop')],
                    ],
                ],
            ],
            'appearance' => [
                'title'  => __('显示与样式', 'qilingshop'),
                'fields' => [
                    'show_subtitle' => [
                        'type'    => 'select',
                        'label'   => __('显示商品副标题', 'qilingshop'),
                        'options' => [
                            'yes' => __('显示', 'qilingshop'),
                            'no'  => __('隐藏', 'qilingshop'),
                        ],
                    ],
                    'show_sales' => [
                        'type'    => 'select',
                        'label'   => __('显示销量', 'qilingshop'),
                        'options' => [
                            'yes' => __('显示', 'qilingshop'),
                            'no'  => __('隐藏', 'qilingshop'),
                        ],
                    ],
                    'show_action' => [
                        'type'    => 'select',
                        'label'   => __('显示操作按钮', 'qilingshop'),
                        'options' => [
                            'yes' => __('显示', 'qilingshop'),
                            'no'  => __('隐藏', 'qilingshop'),
                        ],
                    ],
                    'primary_color' => [
                        'type'  => 'text',
                        'label' => __('主题色', 'qilingshop'),
                    ],
                    'bg_color' => [
                        'type'  => 'text',
                        'label' => __('模块背景色', 'qilingshop'),
                    ],
                    'card_bg' => [
                        'type'  => 'text',
                        'label' => __('卡片背景色', 'qilingshop'),
                    ],
                    'padding' => [
                        'type'  => 'text',
                        'label' => __('模块内边距', 'qilingshop'),
                        'desc'  => __('例如：20px', 'qilingshop'),
                    ],
                    'gap' => [
                        'type'  => 'text',
                        'label' => __('卡片间距', 'qilingshop'),
                        'desc'  => __('例如：16px', 'qilingshop'),
                    ],
                    'border_radius' => [
                        'type'  => 'text',
                        'label' => __('模块圆角', 'qilingshop'),
                        'desc'  => __('例如：14px', 'qilingshop'),
                    ],
                ],
            ],
        ];
    }

    /**
     * 渲染模块
     *
     * @param array $atts
     * @return void
     */
    protected function render_content($atts) {
        if (!function_exists('qls_product') || !function_exists('qls_shop_public')) {
            return;
        }

        $assets_version = function_exists('qilingshop_get_assets_version')
            ? qilingshop_get_assets_version()
            : (defined('QILINGSHOP_VERSION') ? QILINGSHOP_VERSION : '2.0.2');

        wp_enqueue_style(
            'qls-module-new-user-zone',
            QILINGSHOP_URL . 'static/shop/css/module-new-user-zone.css',
            [],
            $assets_version
        );

        $products = $this->get_module_products($atts);
        if (empty($products)) {
            if (current_user_can('manage_options')) {
                echo '<div class="qls-notice" style="padding:18px;text-align:center;background:#fff7f3;border:1px dashed #ff5a3c;color:#ff5a3c;border-radius:8px;margin-bottom:20px;">';
                echo esc_html__('【新人专区】暂无可展示商品，请先在商品编辑中开启“新人专项”并设置价格。', 'qilingshop');
                echo '</div>';
            }
            return;
        }

        $is_logged_in = is_user_logged_in();
        $user_id = (int) get_current_user_id();
        $is_eligible = $is_logged_in ? qls_product()->is_user_eligible_for_new_user_special($user_id) : false;
        $login_url = wp_login_url(get_permalink());

        $more_url = qls_shop_public()->get_page_url('new-user-zone');
        if (empty($more_url)) {
            $more_url = qls_shop_public()->get_shop_url();
        }

        $cols_pc = max(2, min(4, (int) ($atts['columns_pc'] ?? 4)));
        $cols_mobile = max(1, min(2, (int) ($atts['columns_mobile'] ?? 2)));
        $wrapper_classes = $this->get_class_names('qls-module-new-user-zone', [
            'style' => sanitize_key((string) ($atts['style'] ?? 'card')),
        ]);
        $css_vars = implode(' ', [
            '--qls-nuz-primary: ' . sanitize_text_field((string) ($atts['primary_color'] ?? '#ff4d2d')) . ';',
            '--qls-nuz-bg: ' . sanitize_text_field((string) ($atts['bg_color'] ?? '#fff6f2')) . ';',
            '--qls-nuz-card-bg: ' . sanitize_text_field((string) ($atts['card_bg'] ?? '#ffffff')) . ';',
            '--qls-nuz-padding: ' . sanitize_text_field((string) ($atts['padding'] ?? '20px')) . ';',
            '--qls-nuz-gap: ' . sanitize_text_field((string) ($atts['gap'] ?? '16px')) . ';',
            '--qls-nuz-radius: ' . sanitize_text_field((string) ($atts['border_radius'] ?? '14px')) . ';',
            '--qls-nuz-cols: ' . $cols_pc . ';',
            '--qls-nuz-cols-mobile: ' . $cols_mobile . ';',
        ]);

        $title = !empty($atts['title']) ? (string) $atts['title'] : '';
        $show_more = !isset($atts['show_more']) || $atts['show_more'] === 'yes';
        $more_text = !empty($atts['more_text']) ? (string) $atts['more_text'] : __('查看更多', 'qilingshop');
        $show_status = !isset($atts['show_status']) || $atts['show_status'] === 'yes';
        ?>
        <section class="qls-module <?php echo esc_attr($wrapper_classes); ?>" style="<?php echo esc_attr($css_vars); ?>">
            <?php if ($title !== '' || $show_more): ?>
            <div class="qls-module-header qls-new-user-zone-module-header">
                <?php if ($title !== ''): ?>
                <h3 class="qls-module-title qls-new-user-zone-module-title"><?php echo esc_html($title); ?></h3>
                <?php endif; ?>
                <?php if ($show_more && !empty($more_url)): ?>
                <a href="<?php echo esc_url($more_url); ?>" class="qls-module-more qls-new-user-zone-module-more"><?php echo esc_html($more_text); ?></a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($show_status): ?>
            <div class="qls-new-user-zone-module-status">
                <?php if (!$is_logged_in): ?>
                    <span class="status-pill need-login"><?php _e('登录后可参与新人价', 'qilingshop'); ?></span>
                    <a class="status-action qls-login-trigger user-login" href="<?php echo esc_url($login_url); ?>" data-login-url="<?php echo esc_url($login_url); ?>">
                        <?php _e('立即登录', 'qilingshop'); ?>
                    </a>
                <?php elseif ($is_eligible): ?>
                    <span class="status-pill can-join"><?php _e('当前可享新人专属价', 'qilingshop'); ?></span>
                    <span class="status-tip"><?php _e('首个商城支付订单将自动按新人价结算', 'qilingshop'); ?></span>
                <?php else: ?>
                    <span class="status-pill used"><?php _e('当前不满足新人资格', 'qilingshop'); ?></span>
                    <span class="status-tip"><?php _e('仍可按原价正常购买商品', 'qilingshop'); ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="qls-new-user-zone-module-grid">
                <?php foreach ($products as $product): ?>
                    <?php $this->render_product_card($product, $is_logged_in, $is_eligible, $login_url, $atts); ?>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    }

    /**
     * 获取模块商品
     *
     * @param array $atts
     * @return array
     */
    private function get_module_products($atts) {
        $limit = max(1, min(20, (int) ($atts['limit'] ?? 4)));
        $source = sanitize_key((string) ($atts['source'] ?? 'auto'));

        $args = [
            'status' => 1,
            'new_user_special' => 1,
            'orderby' => 'created_at',
            'order' => 'DESC',
            'limit' => $limit,
            'offset' => 0,
        ];

        $manual_ids = [];
        if ($source === 'manual') {
            $manual_ids = $this->parse_id_list((string) ($atts['product_ids'] ?? ''));
            if (empty($manual_ids)) {
                return [];
            }
            $args['include'] = $manual_ids;
        }

        $rows = qls_product()->get_list($args);
        if (empty($rows)) {
            return [];
        }

        if ($source === 'manual' && !empty($manual_ids)) {
            $sort_map = array_flip($manual_ids);
            usort($rows, function ($a, $b) use ($sort_map) {
                $a_pos = $sort_map[(int) ($a->id ?? 0)] ?? PHP_INT_MAX;
                $b_pos = $sort_map[(int) ($b->id ?? 0)] ?? PHP_INT_MAX;
                if ($a_pos === $b_pos) {
                    return 0;
                }
                return $a_pos < $b_pos ? -1 : 1;
            });
        }

        return array_slice(array_values($rows), 0, $limit);
    }

    /**
     * 渲染单个商品卡片
     *
     * @param object $product
     * @param bool   $is_logged_in
     * @param bool   $is_eligible
     * @param string $login_url
     * @param array  $atts
     * @return void
     */
    private function render_product_card($product, $is_logged_in, $is_eligible, $login_url, $atts) {
        $price_login_required = !$is_logged_in && get_option('qls_shop_price_login_required', false);
        $product_url = qls_shop_public()->get_product_url($product);
        $image = $this->extract_image_url($product);
        $new_user_price = (float) qls_product()->get_new_user_special_price($product);
        if ($new_user_price <= 0) {
            $new_user_price = (float) ($product->min_price ?? 0);
        }

        $min_price = (float) ($product->min_price ?? 0);
        $max_price = (float) ($product->max_price ?? $min_price);
        $origin_price_text = $min_price === $max_price
            ? '¥' . number_format($min_price, 2)
            : '¥' . number_format($min_price, 2) . ' - ¥' . number_format($max_price, 2);
        if ($price_login_required) {
            $origin_price_text = __('登录后查看价格', 'qilingshop');
        }

        $show_subtitle = !isset($atts['show_subtitle']) || $atts['show_subtitle'] === 'yes';
        $show_sales = !isset($atts['show_sales']) || $atts['show_sales'] === 'yes';
        $show_action = !isset($atts['show_action']) || $atts['show_action'] === 'yes';

        $action_text = __('查看商品', 'qilingshop');
        $action_url = $product_url;
        $action_classes = 'qls-new-user-zone-module-btn';
        $action_attrs = '';
        if (!$is_logged_in) {
            $action_text = __('登录后参与', 'qilingshop');
            $action_url = $login_url;
            $action_classes .= ' qls-login-trigger user-login is-login';
            $action_attrs = ' data-login-url="' . esc_url($login_url) . '"';
        } elseif ($is_eligible) {
            $action_text = __('去享新人价', 'qilingshop');
        }
        ?>
        <article class="qls-new-user-zone-module-card">
            <a class="qls-new-user-zone-module-thumb" href="<?php echo esc_url($product_url); ?>">
                <?php if ($image !== ''): ?>
                <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($product->title ?: __('新人商品', 'qilingshop')); ?>" loading="lazy">
                <?php else: ?>
                <span class="qls-new-user-zone-module-empty"><?php _e('暂无图片', 'qilingshop'); ?></span>
                <?php endif; ?>
                <span class="qls-new-user-zone-module-badge"><?php _e('新人价', 'qilingshop'); ?></span>
            </a>

            <div class="qls-new-user-zone-module-body">
                <h4 class="qls-new-user-zone-module-name">
                    <a href="<?php echo esc_url($product_url); ?>"><?php echo esc_html($product->title ?: __('未命名商品', 'qilingshop')); ?></a>
                </h4>

                <?php if ($show_subtitle && !empty($product->subtitle)): ?>
                <p class="qls-new-user-zone-module-subtitle"><?php echo esc_html($product->subtitle); ?></p>
                <?php endif; ?>

                <div class="qls-new-user-zone-module-price">
                    <span class="new-user-price"><?php echo $price_login_required ? esc_html__('登录后查看价格', 'qilingshop') : '¥' . esc_html(number_format($new_user_price, 2)); ?></span>
                    <span class="origin-price"><?php echo esc_html($origin_price_text); ?></span>
                </div>

                <div class="qls-new-user-zone-module-meta">
                    <?php if ($show_sales): ?>
                    <span><?php printf(__('销量 %d', 'qilingshop'), (int) ($product->sales_count ?? 0)); ?></span>
                    <?php endif; ?>
                    <span><?php _e('限购 1 件', 'qilingshop'); ?></span>
                </div>

                <?php if ($show_action): ?>
                <a class="<?php echo esc_attr($action_classes); ?>" href="<?php echo esc_url($action_url); ?>"<?php echo $action_attrs; ?>>
                    <?php echo esc_html($action_text); ?>
                </a>
                <?php endif; ?>
            </div>
        </article>
        <?php
    }

    /**
     * 解析 ID 列表
     *
     * @param string $raw
     * @return array
     */
    private function parse_id_list($raw) {
        $parts = explode(',', (string) $raw);
        $ids = [];
        foreach ($parts as $part) {
            $id = (int) trim($part);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * 提取商品主图
     *
     * @param object $product
     * @return string
     */
    private function extract_image_url($product) {
        $main_image = $product->main_image ?? '';
        if (is_array($main_image)) {
            return (string) ($main_image['url'] ?? '');
        }

        if (is_string($main_image) && $main_image !== '') {
            $decoded = json_decode($main_image, true);
            if (is_array($decoded) && !empty($decoded['url'])) {
                return (string) $decoded['url'];
            }
            return $main_image;
        }

        if (!empty($product->gallery) && is_array($product->gallery)) {
            $first = reset($product->gallery);
            if (is_array($first)) {
                return (string) ($first['url'] ?? '');
            }
            if (is_string($first)) {
                return $first;
            }
        }

        return '';
    }
}
