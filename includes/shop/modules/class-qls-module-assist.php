<?php
/**
 * 好友助力装修模块
 *
 * 商城首页装修模块 - 展示可参与的助力活动
 *
 * @package QilingShop
 * @since   2.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once 'class-qls-module-base.php';

class QLS_Module_Assist extends QLS_Module_Base {

    /**
     * 商品缓存
     *
     * @var array<int, object|null>
     */
    private $product_cache = [];

    /**
     * 获取模块默认属性
     */
    public function get_defaults() {
        return [
            'title'            => __('好友助力', 'qilingshop'),
            'show_more'        => 'yes',
            'more_text'        => __('更多助力', 'qilingshop'),

            'source'           => 'auto', // auto|manual
            'activity_ids'     => '',
            'limit'            => 4,

            'style'            => 'card', // card|scroll
            'columns_pc'       => 4,
            'columns_mobile'   => 2,

            'show_stock'       => 'yes',
            'show_expire'      => 'yes',
            'show_helper_rule' => 'yes',
            'show_button'      => 'yes',
            'button_text'      => __('立即助力', 'qilingshop'),
            'login_text'       => __('登录后助力', 'qilingshop'),

            'primary_color'    => '#ff5a3c',
            'bg_color'         => '#fff3ea',
            'card_bg'          => '#ffffff',
            'padding'          => '20px',
            'gap'              => '16px',
            'border_radius'    => '14px',
        ];
    }

    /**
     * 装修设置字段
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
                        'label'   => __('显示“更多助力”', 'qilingshop'),
                        'options' => [
                            'yes' => __('显示', 'qilingshop'),
                            'no'  => __('隐藏', 'qilingshop'),
                        ],
                    ],
                    'more_text' => [
                        'type'  => 'text',
                        'label' => __('更多按钮文案', 'qilingshop'),
                    ],
                    'source' => [
                        'type'    => 'select',
                        'label'   => __('数据来源', 'qilingshop'),
                        'options' => [
                            'auto'   => __('自动获取启用活动', 'qilingshop'),
                            'manual' => __('手动指定活动ID', 'qilingshop'),
                        ],
                    ],
                    'activity_ids' => [
                        'type'  => 'text',
                        'label' => __('活动ID列表', 'qilingshop'),
                        'desc'  => __('手动模式有效，逗号分隔，例如：3,8,12', 'qilingshop'),
                    ],
                    'limit' => [
                        'type'  => 'number',
                        'label' => __('显示数量', 'qilingshop'),
                    ],
                    'show_helper_rule' => [
                        'type'    => 'select',
                        'label'   => __('显示助力规则', 'qilingshop'),
                        'options' => [
                            'yes' => __('显示', 'qilingshop'),
                            'no'  => __('隐藏', 'qilingshop'),
                        ],
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
                    'gap' => [
                        'type'  => 'text',
                        'label' => __('卡片间距', 'qilingshop'),
                        'desc'  => __('例如：16px', 'qilingshop'),
                    ],
                ],
            ],
            'appearance' => [
                'title'  => __('显示与样式', 'qilingshop'),
                'fields' => [
                    'show_stock' => [
                        'type'    => 'select',
                        'label'   => __('显示库存进度', 'qilingshop'),
                        'options' => [
                            'yes' => __('显示', 'qilingshop'),
                            'no'  => __('隐藏', 'qilingshop'),
                        ],
                    ],
                    'show_expire' => [
                        'type'    => 'select',
                        'label'   => __('显示有效期', 'qilingshop'),
                        'options' => [
                            'yes' => __('显示', 'qilingshop'),
                            'no'  => __('隐藏', 'qilingshop'),
                        ],
                    ],
                    'show_button' => [
                        'type'    => 'select',
                        'label'   => __('显示助力按钮', 'qilingshop'),
                        'options' => [
                            'yes' => __('显示', 'qilingshop'),
                            'no'  => __('隐藏', 'qilingshop'),
                        ],
                    ],
                    'button_text' => [
                        'type'  => 'text',
                        'label' => __('按钮文案(登录)', 'qilingshop'),
                    ],
                    'login_text' => [
                        'type'  => 'text',
                        'label' => __('按钮文案(游客)', 'qilingshop'),
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
     */
    protected function render_content($atts) {
        if (!function_exists('qls_assist') || !function_exists('qls_shop_public')) {
            return;
        }

        $assets_version = function_exists('qilingshop_get_assets_version')
            ? qilingshop_get_assets_version()
            : (defined('QILINGSHOP_VERSION') ? QILINGSHOP_VERSION : '2.0.2');

        wp_enqueue_style(
            'qls-module-assist',
            QILINGSHOP_URL . 'static/shop/css/module-assist.css',
            [],
            $assets_version
        );

        $activities = $this->get_module_activities($atts);
        if (empty($activities)) {
            if (current_user_can('manage_options')) {
                echo '<div class="qls-notice" style="padding:18px;text-align:center;background:#fff7f3;border:1px dashed #ff5a3c;color:#ff5a3c;border-radius:8px;margin-bottom:20px;">';
                echo esc_html__('【好友助力专区】暂无可展示活动，请先在「助力活动」中启用活动。', 'qilingshop');
                echo '</div>';
            }
            return;
        }

        $is_logged_in = is_user_logged_in();
        $login_url = wp_login_url(get_permalink());
        $more_url = qls_shop_public()->get_page_url('assist-center');
        if (empty($more_url)) {
            $more_url = qls_shop_public()->get_shop_url();
        }

        $wrapper_classes = $this->get_class_names('qls-module-assist', [
            'style' => sanitize_key((string) $atts['style']),
        ]);

        $cols_pc = max(2, min(4, (int) $atts['columns_pc']));
        $cols_mobile = max(1, min(2, (int) $atts['columns_mobile']));

        $css_vars = implode(' ', [
            '--qls-ass-primary: ' . $this->sanitize_css_value($atts['primary_color'], '#ff5a3c') . ';',
            '--qls-ass-bg: ' . $this->sanitize_css_value($atts['bg_color'], '#fff3ea') . ';',
            '--qls-ass-card-bg: ' . $this->sanitize_css_value($atts['card_bg'], '#ffffff') . ';',
            '--qls-ass-padding: ' . $this->sanitize_css_value($atts['padding'], '20px') . ';',
            '--qls-ass-gap: ' . $this->sanitize_css_value($atts['gap'], '16px') . ';',
            '--qls-ass-radius: ' . $this->sanitize_css_value($atts['border_radius'], '14px') . ';',
            '--qls-ass-cols: ' . $cols_pc . ';',
            '--qls-ass-cols-mobile: ' . $cols_mobile . ';',
        ]);

        $show_more = !isset($atts['show_more']) || $atts['show_more'] === 'yes';
        $more_text = !empty($atts['more_text']) ? (string) $atts['more_text'] : __('更多助力', 'qilingshop');
        $title = !empty($atts['title']) ? (string) $atts['title'] : '';
        ?>
        <section class="qls-module <?php echo esc_attr($wrapper_classes); ?>" style="<?php echo esc_attr($css_vars); ?>">
            <?php if ($title !== '' || $show_more): ?>
            <div class="qls-module-header qls-assist-module-header">
                <?php if ($title !== ''): ?>
                <h3 class="qls-module-title qls-assist-module-title"><?php echo esc_html($title); ?></h3>
                <?php endif; ?>

                <?php if ($show_more): ?>
                <a href="<?php echo esc_url($more_url); ?>" class="qls-module-more qls-assist-module-more"><?php echo esc_html($more_text); ?></a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="qls-assist-module-grid">
                <?php foreach ($activities as $activity): ?>
                    <?php $this->render_activity_card($activity, $atts, $is_logged_in, $login_url, $more_url); ?>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    }

    /**
     * 获取模块活动数据
     *
     * @param array $atts
     * @return array
     */
    private function get_module_activities($atts) {
        $limit = max(1, (int) ($atts['limit'] ?? 4));
        if (function_exists('qls_assist')) {
            qls_assist()->maybe_process_expirations([
                'activities_limit' => 200,
                'campaigns_limit'  => 0,
            ]);
        }

        if (($atts['source'] ?? 'auto') === 'manual' && !empty($atts['activity_ids'])) {
            $ids = $this->parse_id_list($atts['activity_ids']);
            if (empty($ids)) {
                return [];
            }

            $rows = [];
            foreach ($ids as $activity_id) {
                $row = qls_assist()->get_activity($activity_id);
                if (!$row || (int) $row->status !== QLS_Assist::ACTIVITY_ENABLED) {
                    continue;
                }
                if (!$this->is_activity_time_active($row)) {
                    continue;
                }
                if (!$this->is_product_active((int) $row->product_id)) {
                    continue;
                }
                $rows[] = $row;
                if (count($rows) >= $limit) {
                    break;
                }
            }

            return $rows;
        }

        $rows = qls_assist()->get_activities([
            'status' => QLS_Assist::ACTIVITY_ENABLED,
            'product_status' => 1,
            'time_active' => true,
            'limit'  => $limit,
            'offset' => 0,
        ]);

        if (empty($rows)) {
            return [];
        }

        return array_values($rows);
    }

    /**
     * 商品是否上架
     *
     * @param int $product_id
     * @return bool
     */
    private function is_product_active($product_id) {
        if ($product_id <= 0 || !function_exists('qls_product')) {
            return false;
        }

        $product = qls_product()->get((int) $product_id);
        return (bool) ($product && (int) ($product->status ?? 0) === 1);
    }

    /**
     * 活动是否处于可展示时间内。
     *
     * @param object $activity
     * @return bool
     */
    private function is_activity_time_active($activity) {
        $now = current_time('timestamp');

        if (!empty($activity->start_time)) {
            $start_ts = strtotime((string) $activity->start_time);
            if ($start_ts && $start_ts > $now) {
                return false;
            }
        }

        if (!empty($activity->end_time)) {
            $end_ts = strtotime((string) $activity->end_time);
            if ($end_ts && $end_ts < $now) {
                return false;
            }
        }

        return true;
    }

    /**
     * 渲染活动卡片
     *
     * @param object $activity
     * @param array  $atts
     * @param bool   $is_logged_in
     * @param string $login_url
     * @param string $more_url
     * @return void
     */
    private function render_activity_card($activity, $atts, $is_logged_in, $login_url, $more_url) {
        $product = $this->get_product_cached((int) $activity->product_id);
        $product_url = $product ? qls_shop_public()->get_product_url($product) : $more_url;
        $product_image = $product ? $this->extract_image_url($product->main_image ?? '') : '';

        $start_price = (float) ($activity->start_price ?? 0);
        if ($start_price <= 0 && $product && isset($product->min_price)) {
            $start_price = (float) $product->min_price;
        }
        $min_price = max(0, (float) ($activity->min_price ?? 0));
        if ($start_price < $min_price) {
            $start_price = $min_price;
        }
        $save_amount = max(0, $start_price - $min_price);
        $save_percent = $start_price > 0 ? (int) min(100, round(($save_amount / $start_price) * 100)) : 0;

        $stock_total = max(0, (int) ($activity->stock_total ?? 0));
        $stock_available = isset($activity->stock_available)
            ? max(0, (int) $activity->stock_available)
            : max(0, $stock_total - (int) ($activity->stock_locked ?? 0) - (int) ($activity->stock_sold ?? 0));
        $stock_used = max(0, $stock_total - $stock_available);
        $stock_percent = $stock_total > 0 ? (int) min(100, round(($stock_used / $stock_total) * 100)) : 0;

        $target_helpers = max(0, (int) ($activity->target_helpers ?? 0));
        $helper_label = $target_helpers > 0
            ? sprintf(__('%d人助力达标', 'qilingshop'), $target_helpers)
            : __('助力到低价', 'qilingshop');

        $show_stock = (!isset($atts['show_stock']) || $atts['show_stock'] === 'yes') && (bool) get_option('qls_shop_show_stock', true);
        $show_expire = !isset($atts['show_expire']) || $atts['show_expire'] === 'yes';
        $show_helper_rule = !isset($atts['show_helper_rule']) || $atts['show_helper_rule'] === 'yes';
        $show_button = !isset($atts['show_button']) || $atts['show_button'] === 'yes';
        $button_text = !empty($atts['button_text']) ? (string) $atts['button_text'] : __('立即助力', 'qilingshop');
        $login_text = !empty($atts['login_text']) ? (string) $atts['login_text'] : __('登录后助力', 'qilingshop');
        ?>
        <article class="qls-assist-module-card">
            <a class="qls-assist-module-thumb" href="<?php echo esc_url($product_url); ?>">
                <?php if ($product_image !== ''): ?>
                <img src="<?php echo esc_url($product_image); ?>" alt="<?php echo esc_attr($activity->product_title ?: __('助力商品', 'qilingshop')); ?>" loading="lazy">
                <?php endif; ?>
                <span class="qls-assist-module-badge"><?php echo esc_html__('助力省钱', 'qilingshop'); ?></span>
            </a>

            <div class="qls-assist-module-body">
                <h4 class="qls-assist-module-name"><?php echo esc_html($activity->name); ?></h4>
                <p class="qls-assist-module-product"><?php echo esc_html($activity->product_title ?: __('商品已删除', 'qilingshop')); ?></p>

                <?php if ($show_helper_rule): ?>
                <div class="qls-assist-module-rule"><?php echo esc_html($helper_label); ?></div>
                <?php endif; ?>

                <div class="qls-assist-module-price">
                    <span class="assist-current">¥<?php echo esc_html(number_format($min_price, 2)); ?></span>
                    <?php if ($start_price > $min_price): ?>
                    <span class="assist-origin">¥<?php echo esc_html(number_format($start_price, 2)); ?></span>
                    <?php endif; ?>
                </div>
                <div class="qls-assist-module-save">
                    <?php
                    printf(
                        esc_html__('最高省 ¥%1$s (%2$d%%)', 'qilingshop'),
                        esc_html(number_format($save_amount, 2)),
                        esc_html($save_percent)
                    );
                    ?>
                </div>

                <?php if ($show_stock): ?>
                <div class="qls-assist-module-stock-text">
                    <?php
                    if ($stock_total > 0) {
                        printf(
                            esc_html__('库存剩余 %1$d / %2$d', 'qilingshop'),
                            esc_html($stock_available),
                            esc_html($stock_total)
                        );
                    } else {
                        esc_html_e('库存不限', 'qilingshop');
                    }
                    ?>
                </div>
                <?php if ($stock_total > 0): ?>
                <div class="qls-assist-module-stock-track"><span style="width: <?php echo esc_attr($stock_percent); ?>%;"></span></div>
                <?php endif; ?>
                <?php endif; ?>

                <?php if ($show_expire): ?>
                <div class="qls-assist-module-expire">
                    <?php printf(esc_html__('有效期 %d 小时', 'qilingshop'), (int) ($activity->expire_hours ?? 24)); ?>
                </div>
                <?php endif; ?>

                <?php if ($show_button): ?>
                    <?php if ($is_logged_in): ?>
                    <button type="button" class="qls-assist-module-btn qls-assist-create-btn" data-activity-id="<?php echo esc_attr((int) $activity->id); ?>">
                        <?php echo esc_html($button_text); ?>
                    </button>
                    <?php else: ?>
                    <a class="qls-assist-module-btn is-guest qls-login-trigger user-login"
                       href="<?php echo esc_url($login_url); ?>"
                       data-login-url="<?php echo esc_url($login_url); ?>">
                        <?php echo esc_html($login_text); ?>
                    </a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </article>
        <?php
    }

    /**
     * 读取商品缓存
     *
     * @param int $product_id
     * @return object|null
     */
    private function get_product_cached($product_id) {
        $product_id = (int) $product_id;
        if ($product_id <= 0) {
            return null;
        }

        if (array_key_exists($product_id, $this->product_cache)) {
            return $this->product_cache[$product_id];
        }

        $product = function_exists('qls_product') ? qls_product()->get($product_id) : null;
        $this->product_cache[$product_id] = $product ?: null;
        return $this->product_cache[$product_id];
    }

    /**
     * 解析ID列表
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
     * 提取图片URL
     *
     * @param mixed $main_image
     * @return string
     */
    private function extract_image_url($main_image) {
        if (is_array($main_image)) {
            return !empty($main_image['url']) ? (string) $main_image['url'] : '';
        }

        if (!is_string($main_image) || $main_image === '') {
            return '';
        }

        $decoded = json_decode($main_image, true);
        if (is_array($decoded) && !empty($decoded['url'])) {
            return (string) $decoded['url'];
        }

        return $main_image;
    }

    /**
     * 清理 CSS 变量值
     *
     * @param string $value
     * @param string $fallback
     * @return string
     */
    private function sanitize_css_value($value, $fallback) {
        $value = is_scalar($value) ? (string) $value : '';
        $value = trim($value);
        if ($value === '') {
            return $fallback;
        }

        $clean = preg_replace('/[^#(),.%\-\sa-zA-Z0-9]/', '', $value);
        $clean = trim((string) $clean);

        return $clean !== '' ? $clean : $fallback;
    }
}
