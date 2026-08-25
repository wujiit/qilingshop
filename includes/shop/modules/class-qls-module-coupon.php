<?php
/**
 * 优惠券模块
 * 
 * 商城首页装修模块 - 展示可领取优惠券
 *
 * @package QilingShop
 * @since   2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once 'class-qls-module-base.php';

class QLS_Module_Coupon extends QLS_Module_Base {

    /**
     * 获取模块默认属性
     * 
     * @return array
     */
    public function get_defaults() {
        return [
            // 数据来源
            'source'          => 'auto',      // auto: 自动获取可领取优惠券, manual: 手动指定
            'coupon_ids'      => '',          // 手动模式下的优惠券编号，多个编号用半角逗号分隔
            'limit'           => 4,           // 显示数量限制
            
            // 头部设置
            'title'           => '',          // 模块标题
            'show_more'       => 'yes',       // 是否显示更多按钮
            'more_text'       => '',          // 更多按钮文案
            
            // 布局样式
            'style'           => 'card',      // card: 卡片, list: 横向列表, scroll: 水平滚动
            'columns_pc'      => 4,           // 电脑端列数
            'columns_mobile'  => 2,           // 移动端列数
            
            // 外观设置
            'show_desc'       => 'yes',       // 是否显示描述
            'show_validity'   => 'yes',       // 是否显示有效期
            'show_condition'  => 'yes',       // 是否显示使用条件
            
            // 颜色设置
            'primary_color'   => '#ff6b6b',   // 主题色 (优惠金额)
            'bg_color'        => '#ffffff',   // 背景色
            'text_color'      => '#333333',   // 文字颜色
            'btn_color'       => '#ff6b6b',   // 按钮颜色
            
            // 间距设置
            'margin_top'      => '20px',      // 上间距
            'margin_bottom'   => '20px',      // 下间距
            'padding'         => '20px',      // 内边距
            'gap'             => '16px',      // 卡片间距
            
            // 圆角
            'border_radius'   => '12px',      // 卡片圆角
        ];
    }

    /**
     * 获取模块设置字段 (供后台装修界面使用)
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
                        'label'   => __('显示“获取更多”', 'qilingshop'),
                        'options' => [
                            'yes' => __('显示', 'qilingshop'),
                            'no'  => __('隐藏', 'qilingshop'),
                        ]
                    ],
                    'more_text' => [
                        'type'  => 'text',
                        'label' => __('按钮文案', 'qilingshop'),
                        'desc'  => __('默认为“获取更多”', 'qilingshop'),
                    ],
                    'source' => [
                        'type'    => 'select',
                        'label'   => __('数据来源', 'qilingshop'),
                        'options' => [
                            'auto'   => __('自动获取可领取优惠券', 'qilingshop'),
                            'manual' => __('手动指定优惠券', 'qilingshop'),
                        ]
                    ],
                    'coupon_ids' => [
                        'type'  => 'text',
                        'label' => __('优惠券编号列表', 'qilingshop'),
                        'desc'  => __('仅手动模式有效，多个编号用半角逗号分隔，例如：1,2,3', 'qilingshop'),
                    ],
                    'limit' => [
                        'type'  => 'number',
                        'label' => __('显示数量', 'qilingshop'),
                        'desc'  => __('最多显示多少张优惠券', 'qilingshop'),
                    ],
                    'show_desc' => [
                        'type'    => 'select',
                        'label'   => __('显示描述', 'qilingshop'),
                        'options' => [
                            'yes' => __('显示', 'qilingshop'),
                            'no'  => __('隐藏', 'qilingshop'),
                        ]
                    ],
                    'show_validity' => [
                        'type'    => 'select',
                        'label'   => __('显示有效期', 'qilingshop'),
                        'options' => [
                            'yes' => __('显示', 'qilingshop'),
                            'no'  => __('隐藏', 'qilingshop'),
                        ]
                    ],
                    'show_condition' => [
                        'type'    => 'select',
                        'label'   => __('显示使用条件', 'qilingshop'),
                        'options' => [
                            'yes' => __('显示', 'qilingshop'),
                            'no'  => __('隐藏', 'qilingshop'),
                        ]
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
                            'list'   => __('横向列表', 'qilingshop'),
                            'scroll' => __('水平滚动', 'qilingshop'),
                        ]
                    ],
                    'columns_pc' => [
                        'type'    => 'select',
                        'label'   => __('电脑端列数', 'qilingshop'),
                        'options' => [
                            2 => __('2列', 'qilingshop'),
                            3 => __('3列', 'qilingshop'),
                            4 => __('4列', 'qilingshop'),
                            5 => __('5列', 'qilingshop'),
                        ]
                    ],
                    'columns_mobile' => [
                        'type'    => 'select',
                        'label'   => __('移动端列数', 'qilingshop'),
                        'options' => [
                            1 => __('1列', 'qilingshop'),
                            2 => __('2列', 'qilingshop'),
                        ]
                    ],
                ]
            ],
            'appearance' => [
                'title'  => __('外观设置', 'qilingshop'),
                'fields' => [
                    'primary_color' => [
                        'type'  => 'text',
                        'label' => __('主题色', 'qilingshop'),
                        'desc'  => __('优惠金额和按钮的主色调', 'qilingshop'),
                    ],
                    'bg_color' => [
                        'type'  => 'text',
                        'label' => __('背景颜色', 'qilingshop'),
                    ],
                    'text_color' => [
                        'type'  => 'text',
                        'label' => __('文字颜色', 'qilingshop'),
                    ],
                    'border_radius' => [
                        'type'  => 'text',
                        'label' => __('卡片圆角', 'qilingshop'),
                        'desc'  => __('例如 12px', 'qilingshop'),
                    ],
                ]
            ],
            'spacing' => [
                'title'  => __('间距设置', 'qilingshop'),
                'fields' => [
                    'margin_top' => [
                        'type'  => 'text',
                        'label' => __('上间距', 'qilingshop'),
                        'desc'  => __('例如 20px', 'qilingshop'),
                    ],
                    'margin_bottom' => [
                        'type'  => 'text',
                        'label' => __('下间距', 'qilingshop'),
                    ],
                    'padding' => [
                        'type'  => 'text',
                        'label' => __('内边距', 'qilingshop'),
                    ],
                    'gap' => [
                        'type'  => 'text',
                        'label' => __('卡片间距', 'qilingshop'),
                    ],
                ]
            ]
        ];
    }

    /**
     * 渲染模块内容
     * 
     * @param array $atts 模块属性
     */
    protected function render_content($atts) {
        // 加载模块样式 (使用动态版本号防止缓存)
        $assets_version = function_exists('qilingshop_get_assets_version') 
            ? qilingshop_get_assets_version() 
            : (defined('QILINGSHOP_VERSION') ? QILINGSHOP_VERSION : '2.0.2');

        wp_enqueue_style(
            'qls-module-category-nav',
            QILINGSHOP_URL . 'static/shop/css/module-category-nav.css',
            [],
            $assets_version
        );

        // 获取当前用户ID
        $user_id = get_current_user_id();

        // 获取优惠券数据
        $coupons = $this->get_coupons($atts, $user_id);

        if (empty($coupons)) {
            return; // 无优惠券则不渲染
        }

        // 生成唯一ID (防止多个模块冲突)
        $unique_id = 'qls-coupon-module-' . wp_rand(1000, 9999);

        // 生成 nonce (用于领取请求, 每次刷新生成新的防止缓存)
        $nonce = wp_create_nonce('qls_coupon_nonce');

        // 构建 CSS 变量
        $css_vars = $this->build_css_variables($atts);

        // 构建类名
        $wrapper_classes = $this->get_class_names('qls-module-coupon', [
            'style' => $atts['style']
        ]);

        ?>
        <div id="<?php echo esc_attr($unique_id); ?>" 
             class="qls-module <?php echo esc_attr($wrapper_classes); ?>" 
             style="<?php echo esc_attr($css_vars); ?>"
             data-nonce="<?php echo esc_attr($nonce); ?>">
            
            <?php $this->render_header($atts); ?>
            
            <div class="qls-coupon-grid">
                <?php foreach ($coupons as $coupon): ?>
                    <?php $this->render_coupon_card($coupon, $atts, $user_id); ?>
                <?php endforeach; ?>
            </div>
        </div>

        <?php $this->render_inline_script($unique_id); ?>
        <?php
    }

    /**
     * 渲染模块头部
     */
    private function render_header($atts) {
        // 默认标题 (如果设置为空，则不显示)
        $title = !empty($atts['title']) ? $atts['title'] : '';
        
        // 检查开关
        if (isset($atts['show_more']) && $atts['show_more'] === 'no') {
             if ($title) {
                 // 仅显示标题
                 echo '<div class="qls-module-header"><h3 class="qls-module-title">' . esc_html($title) . '</h3></div>';
             }
             return;
        }

        // 按钮文案
        $btn_text = !empty($atts['more_text']) ? $atts['more_text'] : __('获取更多', 'qilingshop');

        // 自动获取优惠券中心链接
        $more_url = qls_shop_public()->get_page_url('coupon_center');
        if (empty($more_url)) {
            $more_url = qls_shop_public()->get_shop_url();
        }

        if (empty($title) && empty($more_url)) {
            return;
        }
        ?>
        <div class="qls-module-header">
            <div class="qls-module-title-group">
                <?php if ($title): ?>
                <h3 class="qls-module-title"><?php echo esc_html($title); ?></h3>
                <?php endif; ?>
            </div>
            
            <?php if ($more_url): ?>
            <a href="<?php echo esc_url($more_url); ?>" class="qls-module-more">
                <?php echo esc_html($btn_text); ?> 
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle;"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
            </a>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * 获取优惠券数据
     * 
     * @param array $atts    模块属性
     * @param int   $user_id 用户ID
     * @return array
     */
    private function get_coupons($atts, $user_id) {
        if (!class_exists('QLS_Coupon')) {
            return [];
        }

        $coupon_manager = QLS_Coupon::instance();
        $limit = intval($atts['limit']) ?: 4;
        $limit = max(1, $limit);

        if ($atts['source'] === 'manual' && !empty($atts['coupon_ids'])) {
            // 手动指定模式
            $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $atts['coupon_ids'])))));
            if (empty($ids)) {
                return [];
            }

            $claim_count_map = $this->get_user_claimed_count_map((int) $user_id, $ids);
            $coupons = [];
            
            foreach ($ids as $id) {
                $coupon = $coupon_manager->get($id);
                if ($coupon && $coupon->status == 1 && $coupon->is_visible == 1) {
                    $claimed_count = (int) ($claim_count_map[$id] ?? 0);
                    $can_claim = $coupon_manager->can_claim($id, $user_id, [
                        'coupon' => $coupon,
                        'claimed_count' => $claimed_count,
                    ]);
                    $coupon->can_claim = $can_claim['can_claim'];
                    $coupon->claim_reason = $can_claim['reason'];
                    $coupon->is_claimed = $claimed_count > 0;
                    $coupons[] = $coupon;
                }

                if (count($coupons) >= $limit) {
                    break;
                }
            }
            
            return $coupons;
        }

        // 自动获取模式
        return $coupon_manager->get_public_coupons($user_id, $limit);
    }

    /**
     * 批量获取用户领取数量
     * 
     * @param int   $user_id
     * @param array $coupon_ids
     * @return array<int, int>
     */
    private function get_user_claimed_count_map($user_id, $coupon_ids) {
        $user_id = (int) $user_id;
        $coupon_ids = array_values(array_unique(array_filter(array_map('intval', (array) $coupon_ids))));
        if ($user_id <= 0 || empty($coupon_ids)) {
            return [];
        }

        global $wpdb;
        $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'coupon_claims';
        $placeholders = implode(',', array_fill(0, count($coupon_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT coupon_id, COUNT(*) AS claim_count
             FROM {$table}
             WHERE user_id = %d
               AND coupon_id IN ({$placeholders})
             GROUP BY coupon_id",
            array_merge([$user_id], $coupon_ids)
        ));

        $result = [];
        foreach ((array) $rows as $row) {
            $result[(int) $row->coupon_id] = (int) $row->claim_count;
        }

        return $result;
    }

    /**
     * 构建 CSS 变量字符串
     * 
     * @param array $atts 模块属性
     * @return string
     */
    private function build_css_variables($atts) {
        $vars = [
            '--qls-cpn-primary'     => $atts['primary_color'],
            '--qls-cpn-bg'          => $atts['bg_color'],
            '--qls-cpn-text'        => $atts['text_color'],
            '--qls-cpn-btn'         => $atts['btn_color'] ?: $atts['primary_color'],
            '--qls-cpn-radius'      => $atts['border_radius'],
            '--qls-cpn-gap'         => $atts['gap'],
            '--qls-cpn-cols-pc'     => $atts['columns_pc'],
            '--qls-cpn-cols-m'      => $atts['columns_mobile'],
            '--qls-cpn-mt'          => $atts['margin_top'],
            '--qls-cpn-mb'          => $atts['margin_bottom'],
            '--qls-cpn-pd'          => $atts['padding'],
        ];

        $style_str = '';
        foreach ($vars as $key => $val) {
            if ($val) {
                $style_str .= "{$key}: {$val}; ";
            }
        }

        return $style_str;
    }

    /**
     * 渲染单张优惠券卡片
     * 
     * @param object $coupon  优惠券数据
     * @param array  $atts    模块属性
     * @param int    $user_id 用户ID
     */
    private function render_coupon_card($coupon, $atts, $user_id) {
        // 计算优惠券显示值
        $discount_display = $this->format_discount($coupon);
        $condition_text = $this->format_condition($coupon);
        $validity_text = $this->format_validity($coupon);
        $scope_text = $this->format_scope($coupon);

        // 按钮状态
        $is_claimed = !empty($coupon->is_claimed);
        $can_claim = !$is_claimed && !empty($coupon->can_claim);
        $btn_class = $is_claimed ? 'qls-cpn-btn--claimed' : ($can_claim ? '' : 'qls-cpn-btn--disabled');
        $btn_text = $is_claimed ? __('已领取', 'qilingshop') : ($can_claim ? __('立即领取', 'qilingshop') : ($coupon->claim_reason ?: __('无法领取', 'qilingshop')));

        ?>
        <div class="qls-cpn-card" data-coupon-id="<?php echo esc_attr($coupon->id); ?>">
            <!-- 上部: 优惠券信息 -->
            <div class="qls-cpn-header">
                <div class="qls-cpn-value">
                    <span class="qls-cpn-amount"><?php echo esc_html($discount_display['amount']); ?></span>
                    <span class="qls-cpn-unit"><?php echo esc_html($discount_display['unit']); ?></span>
                </div>
                <div class="qls-cpn-info">
                    <div class="qls-cpn-name"><?php echo esc_html($coupon->name); ?></div>
                    
                    <?php if ($atts['show_condition'] === 'yes' && $condition_text): ?>
                        <div class="qls-cpn-condition"><?php echo esc_html($condition_text); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- 中部: 额外信息 -->
            <div class="qls-cpn-body">
                <?php if ($atts['show_desc'] === 'yes' && !empty($coupon->description)): ?>
                    <div class="qls-cpn-desc"><?php echo esc_html($coupon->description); ?></div>
                <?php endif; ?>
                
                <div class="qls-cpn-meta">
                    <?php if ($scope_text): ?>
                        <span class="qls-cpn-scope"><?php echo esc_html($scope_text); ?></span>
                    <?php endif; ?>
                    
                    <?php if ($atts['show_validity'] === 'yes' && $validity_text): ?>
                        <span class="qls-cpn-validity"><?php echo esc_html($validity_text); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 底部: 居中领取按钮 -->
            <div class="qls-cpn-footer">
                <button type="button" 
                        class="qls-cpn-btn <?php echo esc_attr($btn_class); ?>"
                        data-coupon-id="<?php echo esc_attr($coupon->id); ?>"
                        <?php echo ($is_claimed || !$can_claim) ? 'disabled' : ''; ?>>
                    <?php echo esc_html($btn_text); ?>
                </button>
            </div>
        </div>
        <?php
    }

    /**
     * 格式化优惠金额显示
     * 
     * @param object $coupon
     * @return array
     */
    private function format_discount($coupon) {
        if ($coupon->discount_type === 'percent') {
            return [
                'amount' => intval($coupon->discount_value),
                'unit'   => '%',
            ];
        }

        // 固定金额
        $amount = floatval($coupon->discount_value);
        if ($amount >= 1) {
            return [
                'amount' => '¥' . intval($amount),
                'unit'   => '',
            ];
        }

        return [
            'amount' => '¥' . $amount,
            'unit'   => '',
        ];
    }

    /**
     * 格式化使用条件
     * 
     * @param object $coupon
     * @return string
     */
    private function format_condition($coupon) {
        if (empty($coupon->min_amount) || $coupon->min_amount <= 0) {
            return __('无门槛', 'qilingshop');
        }

        return sprintf(__('满%s可用', 'qilingshop'), '¥' . number_format($coupon->min_amount, 0));
    }

    /**
     * 格式化有效期
     * 
     * @param object $coupon
     * @return string
     */
    private function format_validity($coupon) {
        if ($coupon->valid_type === 'days' && !empty($coupon->valid_days)) {
            return sprintf(__('领取后%d天有效', 'qilingshop'), $coupon->valid_days);
        }

        if (!empty($coupon->end_time)) {
            $end = strtotime($coupon->end_time);
            if ($end) {
                return sprintf(__('%s前有效', 'qilingshop'), date('m/d', $end));
            }
        }

        return __('长期有效', 'qilingshop');
    }

    /**
     * 格式化适用范围
     * 
     * @param object $coupon
     * @return string
     */
    private function format_scope($coupon) {
        $scopes = [
            'all'      => __('全场通用', 'qilingshop'),
            'resource' => __('文章资源', 'qilingshop'),
            'recharge' => __('积分充值', 'qilingshop'),
            'vip'      => __('VIP会员', 'qilingshop'),
            'shop'     => __('实物商城', 'qilingshop'),
        ];

        return $scopes[$coupon->apply_scope] ?? '';
    }

    /**
     * 渲染内联脚本 (领取交互)
     * 
     * @param string $container_id 容器ID
     */
    private function render_inline_script($container_id) {
        ?>
        <script>
        (function() {
            'use strict';
            
            var container = document.getElementById('<?php echo esc_js($container_id); ?>');
            if (!container) return;
            
            var nonce = container.getAttribute('data-nonce');
            
            // 事件委托处理领取按钮点击
            container.addEventListener('click', function(e) {
                var btn = e.target.closest('.qls-cpn-btn');
                if (!btn || btn.disabled) return;
                
                var couponId = btn.getAttribute('data-coupon-id');
                if (!couponId) return;
                
                // 防止重复点击
                if (btn.classList.contains('qls-cpn-btn--loading')) return;
                
                // 检查是否登录
                <?php if (!is_user_logged_in()): ?>
                    // 未登录,触发登录弹窗(如果主题支持)
                    if (typeof window.qlsShowLogin === 'function') {
                        window.qlsShowLogin();
                    } else {
                        alert('<?php echo esc_js(__('请先登录后再领取', 'qilingshop')); ?>');
                    }
                    return;
                <?php endif; ?>
                
                // 显示加载状态
                btn.classList.add('qls-cpn-btn--loading');
                var originalText = btn.textContent;
                btn.textContent = '<?php echo esc_js(__('领取中...', 'qilingshop')); ?>';
                
                // 发送领取请求
                fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'action=qls_claim_coupon&coupon_id=' + encodeURIComponent(couponId) + '&nonce=' + encodeURIComponent(nonce)
                })
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    btn.classList.remove('qls-cpn-btn--loading');
                    
                    if (data.success) {
                        // 领取成功
                        btn.textContent = '<?php echo esc_js(__('已领取', 'qilingshop')); ?>';
                        btn.classList.add('qls-cpn-btn--claimed');
                        btn.disabled = true;
                        
                        // 添加成功动画
                        var card = btn.closest('.qls-cpn-card');
                        if (card) {
                            card.classList.add('qls-cpn-card--claimed');
                        }
                    } else {
                        // 领取失败
                        btn.textContent = data.data && data.data.message ? data.data.message : originalText;
                        
                        // 3秒后恢复
                        setTimeout(function() {
                            if (!btn.classList.contains('qls-cpn-btn--claimed')) {
                                btn.textContent = originalText;
                            }
                        }, 3000);
                    }
                })
                .catch(function(error) {
                    btn.classList.remove('qls-cpn-btn--loading');
                    btn.textContent = '<?php echo esc_js(__('网络错误', 'qilingshop')); ?>';
                    
                    setTimeout(function() {
                        btn.textContent = originalText;
                    }, 3000);
                });
            });
        })();
        </script>
        <?php
    }
}
