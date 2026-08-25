<?php
/**
 * VIP 落地页模板 (全屏模式)
 */
if (!defined('ABSPATH')) exit;

// 1. 强制隐藏主题默认的页面头部（标题栏/面包屑）
add_filter('qiling_show_page_header', '__return_false');

// 加载优惠券资源（使用动态版本号）
$assets_version = function_exists('qilingshop_get_assets_version') ? qilingshop_get_assets_version() : QILINGSHOP_VERSION;
wp_enqueue_style('qls-coupon', QILINGSHOP_URL . 'static/css/coupon.css', [], $assets_version);
wp_enqueue_script('qls-coupon-selector', QILINGSHOP_URL . 'static/js/coupon-selector.js', [], $assets_version, true);
wp_localize_script('qls-coupon-selector', 'qlsCoupon', [
    'ajaxUrl' => admin_url('admin-ajax.php'),
    'nonce'   => wp_create_nonce('qls_coupon_nonce'),
    'currency' => '¥',
    'i18n' => qilingshop_get_coupon_selector_i18n(),
]);

// 2. 加载网站头部
get_header();

// 获取设置
$hero_title = get_option('qilingshop_vip_hero_title', __('开通 VIP 会员', 'qilingshop'));
$hero_subtitle = get_option('qilingshop_vip_hero_subtitle', __('解锁全站资源，享受尊贵特权', 'qilingshop'));
$hero_btn = get_option('qilingshop_vip_hero_btn', __('立即加入', 'qilingshop'));
$hero_bg = get_option('qilingshop_vip_hero_bg', '');
$page_style = get_option('qilingshop_vip_style', 'black-gold'); // black-gold, light, blue
// VIP 落地页数量配置
$vip_benefit_count = max(1, min(intval(get_option('qilingshop_vip_benefit_count', 4)), 12));
$vip_compare_count = max(1, min(intval(get_option('qilingshop_vip_compare_count', 5)), 20));
$compare_rows = [];
for ($i = 1; $i <= $vip_compare_count; $i++) {
    $name = get_option('qilingshop_vip_compare_'.$i.'_name', '');
    if (!$name) {
        continue;
    }
    $compare_rows[] = [
        'name' => $name,
        'free' => get_option('qilingshop_vip_compare_'.$i.'_free', ''),
        'vip'  => get_option('qilingshop_vip_compare_'.$i.'_vip', ''),
    ];
}

// 获取 VIP 等级
$vip = QilingShop_VIP::instance();
$levels = $vip->get_levels(true);
$current_vip = 0;
$vip_level_info = null;
$user_id = 0;
$vip_status = [
    'level_id' => 0,
    'is_expired' => true,
    'in_grace' => false,
];
if (is_user_logged_in()) {
    $user_id = get_current_user_id();
    $vip_status = $vip->get_user_vip_status($user_id);
    $vip_level_info = $vip_status['level_info'];
    if ($vip_level_info) {
        $current_vip = (int) $vip_status['level_id'];
    }
}
$allow_diff_upgrade = (bool) get_option('qilingshop_vip_diff_upgrade', true);
$current_level_sort = $vip_level_info ? (int) $vip_level_info->sort_order : 0;
$current_level_price = $vip_level_info ? (float) $vip_level_info->price : 0;
$rule_calc_text = $allow_diff_upgrade
    ? __('差价=目标等级价-当前等级价（不按剩余天数补差价）', 'qilingshop')
    : __('升级按目标等级价计费（不按剩余天数补差价）', 'qilingshop');
$rule_stack_text = __('续费同等级在原到期日基础上叠加；升级从今日起计算；宽限期内续费仍按原到期日叠加', 'qilingshop');

// 支付相关设置
$points_name = qilingshop_get_points_name();
?>

<!-- 3. 全屏全宽容器 -->
<div class="entry-content fullscreen-content" style="max-width:100%; padding:0; background:transparent;">
    
    <div class="qls-vip-page qls-vip-style-<?php echo esc_attr($page_style); ?>">
        
        <!-- Hero Section -->
        <div class="qls-vip-hero">
            <?php if ($hero_bg): ?>
            <div class="qls-vip-hero-bg" style="background-image: url('<?php echo esc_url($hero_bg); ?>');"></div>
            <?php endif; ?>
            
            <div class="qls-vip-hero-inner">
                <div class="qls-vip-hero-content">
                    <h1><?php echo esc_html($hero_title); ?></h1>
                    <p><?php echo esc_html($hero_subtitle); ?></p>
                    <a href="#qls-vip-plans" class="qls-vip-cta-btn"><?php echo esc_html($hero_btn); ?></a>
                </div>
            </div>
        </div>

        <!-- Plans Section -->
        <div id="qls-vip-plans" class="qls-vip-section qls-vip-section-plans">
            <div class="qls-container">
                <h2 class="qls-vip-section-title"><?php _e('选择您的套餐', 'qilingshop'); ?></h2>
                
                <?php if (empty($levels)): ?>
                    <div class="qls-vip-empty-state qls-vip-empty-state-plans">
                        <div class="qls-vip-empty-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" role="img" focusable="false" aria-hidden="true">
                                <path d="M4 7.2A2.2 2.2 0 0 1 6.2 5h11.6A2.2 2.2 0 0 1 20 7.2v9.6a2.2 2.2 0 0 1-2.2 2.2H6.2A2.2 2.2 0 0 1 4 16.8V7.2Z" stroke="currentColor" stroke-width="1.5"/>
                                <path d="M8 9.5h8M8 12h8M8 14.5h4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <h3 class="qls-vip-empty-title"><?php _e('套餐暂未开放', 'qilingshop'); ?></h3>
                        <p class="qls-vip-empty-desc"><?php _e('管理员尚未配置 VIP 等级，请稍后再来。', 'qilingshop'); ?></p>
                    </div>
                <?php else: ?>
                    <div class="qls-vip-plans">
                        <?php foreach ($levels as $level): 
                            $is_current = ($current_vip == $level->id && !$vip_status['is_expired']);
                            // 推荐逻辑：后台设置推荐 OR 排序第一 OR key为yearly
                            $is_recommended = !empty($level->is_recommended) || ($level->sort_order == 1 && !isset($level->is_recommended));
                            $card_class = $is_recommended ? 'recommended' : '';
                            $level_price = (float) $level->price;
                            $pay_price = (float) $vip->calculate_upgrade_price($user_id, $level->id);
                            if ($pay_price < 0) {
                                $pay_price = 0;
                            }
                            $upgrade_label = '';
                            $show_origin_price = false;
                            if ($allow_diff_upgrade && $vip_level_info && (int) $level->sort_order > $current_level_sort) {
                                $upgrade_label = $pay_price > 0 ? __('补差价升级', 'qilingshop') : __('免费升级', 'qilingshop');
                                $show_origin_price = $level_price > $pay_price;
                            }
                            $preview_expires = $vip->calculate_new_expires($user_id, $level->id);
                            if ($preview_expires && $vip->is_lifetime_expires($preview_expires)) {
                                $preview_expires_display = __('永久', 'qilingshop');
                            } elseif ($preview_expires) {
                                $preview_expires_display = date_i18n(__('Y年m月d日', 'qilingshop'), strtotime($preview_expires));
                            } else {
                                $preview_expires_display = '-';
                            }
                        ?>
                        <div class="qls-vip-plan-card <?php echo $card_class; ?>">
                            <?php if ($is_recommended): ?>
                            <div class="qls-vip-badge"><?php _e('推荐', 'qilingshop'); ?></div>
                            <?php endif; ?>
                            
                            <div class="qls-vip-plan-name"><?php echo esc_html($level->level_name); ?></div>
                            <div class="qls-vip-plan-price">
                                <small><?php echo get_option('qilingshop_currency_symbol', '¥'); ?></small><?php echo qilingshop_format_price($pay_price, ''); ?>
                            </div>
                            <?php if ($show_origin_price): ?>
                            <div class="qls-vip-plan-original"><?php _e('原价', 'qilingshop'); ?> <?php echo qilingshop_format_price($level_price); ?></div>
                            <?php elseif ($level->original_price > $level->price): ?>
                            <div class="qls-vip-plan-original"><?php echo qilingshop_format_price($level->original_price); ?></div>
                            <?php endif; ?>
                            
                            <div class="qls-vip-plan-desc">
                                <ul>
                                    <?php 
                                    // 1. 显示有效期
                                    if ($level->duration_days >= 999999) {
                                        echo '<li>' . __('有效期：永久有效', 'qilingshop') . '</li>';
                                    } else {
                                        echo '<li>' . sprintf(__('有效期：%d 天', 'qilingshop'), $level->duration_days) . '</li>';
                                    }

                                    // 2. 显示每日下载限制
                                    if (isset($level->daily_download_limit)) {
                                        $limit = intval($level->daily_download_limit);
                                        if ($limit < 0) {
                                            echo '<li>' . __('每日下载：无限次', 'qilingshop') . '</li>';
                                        } else {
                                            echo '<li>' . sprintf(__('每日下载：%d 个资源', 'qilingshop'), $limit) . '</li>';
                                        }
                                    }
                                    
                                    // 3. 显示自定义描述（按行分割）
                                    $desc_lines = explode("\n", $level->description);
                                    foreach ($desc_lines as $line) {
                                        $line = trim($line);
                                        if ($line) {
                                            echo '<li>' . esc_html($line) . '</li>';
                                        }
                                    }
                                    ?>
                                </ul>
                            </div>

                            <?php if ($is_current): ?>
                                <button class="qls-vip-plan-btn current" disabled><?php _e('当前等级', 'qilingshop'); ?></button>
                            <?php else: ?>
                                <button class="qls-vip-plan-btn qls-buy-vip-trigger" 
                                    data-level="<?php echo $level->id; ?>"
                                    data-price-rmb="<?php echo esc_attr($pay_price); ?>"
                                    data-origin-rmb="<?php echo esc_attr($level_price); ?>"
                                    data-upgrade-label="<?php echo esc_attr($upgrade_label); ?>"
                                    data-name="<?php echo esc_attr($level->level_name); ?>"
                                    data-rule-expires="<?php echo esc_attr($preview_expires_display); ?>"
                                    data-rule-calc="<?php echo esc_attr($rule_calc_text); ?>"
                                    data-rule-stack="<?php echo esc_attr($rule_stack_text); ?>">
                                    <?php echo $upgrade_label ? esc_html($upgrade_label) : esc_html(__('立即开通', 'qilingshop')); ?>
                                </button>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Benefits Section -->
        <div class="qls-vip-section qls-vip-section-benefits">
            <div class="qls-container">
                <h2 class="qls-vip-section-title"><?php _e('会员特权', 'qilingshop'); ?></h2>
                <div class="qls-vip-benefits">
                    <?php for ($i = 1; $i <= $vip_benefit_count; $i++): 
                        $icon = get_option('qilingshop_vip_benefit_'.$i.'_icon', '');
                        $title = get_option('qilingshop_vip_benefit_'.$i.'_title', '');
                        $desc = get_option('qilingshop_vip_benefit_'.$i.'_desc', '');
                        if (!$title) continue;
                    ?>
                    <div class="qls-vip-benefit-card">
                        <div class="qls-vip-benefit-icon"><?php echo qilingshop_render_icon($icon, 'qls-vip-benefit-icon-glyph'); ?></div>
                        <h3><?php echo esc_html($title); ?></h3>
                        <p><?php echo esc_html($desc); ?></p>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
        </div>

        <!-- Comparison Section -->
        <div class="qls-vip-section qls-vip-section-compare">
            <div class="qls-container">
                <h2 class="qls-vip-section-title"><?php _e('权益对比', 'qilingshop'); ?></h2>
                <?php if (empty($compare_rows)): ?>
                <div class="qls-vip-empty-state qls-vip-empty-state-compare">
                    <div class="qls-vip-empty-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" role="img" focusable="false" aria-hidden="true">
                            <path d="M4.8 5h14.4A1.8 1.8 0 0 1 21 6.8v10.4a1.8 1.8 0 0 1-1.8 1.8H4.8A1.8 1.8 0 0 1 3 17.2V6.8A1.8 1.8 0 0 1 4.8 5Z" stroke="currentColor" stroke-width="1.5"/>
                            <path d="M8 9.5h8M8 12h8M8 14.5h5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            <path d="M6.5 3.8v16.4M17.5 3.8v16.4" stroke="currentColor" stroke-width="1.2" opacity=".45"/>
                        </svg>
                    </div>
                    <h3 class="qls-vip-empty-title"><?php _e('权益对比暂未配置', 'qilingshop'); ?></h3>
                    <p class="qls-vip-empty-desc"><?php _e('请在后台补充对比项后，这里会自动展示。', 'qilingshop'); ?></p>
                </div>
                <?php else: ?>
                <div class="qls-vip-table-wrapper">
                    <table class="qls-vip-compare-table">
                        <thead>
                            <tr>
                                <th width="30%"><?php _e('功能特性', 'qilingshop'); ?></th>
                                <th width="35%"><?php _e('普通用户', 'qilingshop'); ?></th>
                                <th width="35%" class="highlight"><?php _e('VIP 会员', 'qilingshop'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($compare_rows as $row): ?>
                            <tr>
                                <td><?php echo esc_html($row['name']); ?></td>
                                <td><?php echo wp_kses_post($row['free']); ?></td>
                                <td><?php echo wp_kses_post($row['vip']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- FAQ Section -->
        <div class="qls-vip-section qls-vip-section-faq">
            <div class="qls-container">
                <h2 class="qls-vip-section-title"><?php _e('常见问题', 'qilingshop'); ?></h2>
                <div class="qls-vip-faq-grid">
                    <?php for ($i = 1; $i <= 4; $i++): 
                        $q = get_option('qilingshop_vip_faq_'.$i.'_q', '');
                        $a = get_option('qilingshop_vip_faq_'.$i.'_a', '');
                        if (!$q) continue;
                    ?>
                    <div class="qls-vip-faq-item">
                        <div class="qls-vip-faq-q"><?php echo esc_html($q); ?></div>
                        <div class="qls-vip-faq-a"><?php echo nl2br(esc_html($a)); ?></div>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
        </div>

    </div>

    <!-- Payment Modal -->
    <div id="qls-vip-modal" class="qls-modal" style="display:none;">
        <div class="qls-modal-overlay"></div>
        <div class="qls-modal-content">
            <div class="qls-modal-header">
                <h3><?php _e('开通会员', 'qilingshop'); ?></h3>
                <span class="qls-modal-close">&times;</span>
            </div>
            <div class="qls-modal-body">
                <div class="qls-payment-info">
                    <p class="qls-pay-title"><?php _e('您正在开通：', 'qilingshop'); ?> <span id="qls-vip-name" class="highlight">VIP</span></p>
                    <div class="qls-pay-amount">
                        <span id="qls-vip-price" class="price">¥0.00</span>
                        <span id="qls-vip-price-note" class="qls-pay-note" style="display:none;"></span>
                        <span id="qls-vip-points-price" class="points-price"></span>
                    </div>
                </div>

                <div class="qls-pay-rule-box">
                    <div class="qls-pay-rule-title"><?php _e('升级/续费规则说明与预览', 'qilingshop'); ?></div>
                    <ul class="qls-pay-rule-list">
                        <li><span class="qls-pay-rule-label"><?php _e('升级后到期', 'qilingshop'); ?></span><span id="qls-vip-rule-expires">-</span></li>
                        <li><span class="qls-pay-rule-label"><?php _e('差价计算', 'qilingshop'); ?></span><span id="qls-vip-rule-calc"></span></li>
                        <li><span class="qls-pay-rule-label"><?php _e('叠加规则', 'qilingshop'); ?></span><span id="qls-vip-rule-stack"></span></li>
                    </ul>
                </div>
                
                <!-- 优惠券选择 -->
                <div class="qls-coupon-section" id="qls-vip-coupon-section" data-order-type="vip">
                    <label><?php _e('优惠券', 'qilingshop'); ?></label>
                    <div class="qls-coupon-trigger" id="qls-vip-coupon-trigger">
                        <span class="dashicons dashicons-tickets-alt"></span>
                        <span class="qls-coupon-text"><?php _e('选择优惠券', 'qilingshop'); ?></span>
                        <span class="qls-coupon-selected" id="qls-vip-coupon-selected"></span>
                    </div>
                    <div class="qls-coupon-applied" id="qls-vip-coupon-applied" style="display:none;">
                        <div class="coupon-info">
                            <span class="coupon-name" id="qls-vip-coupon-name"></span>
                            <span class="coupon-discount" id="qls-vip-coupon-discount"></span>
                        </div>
                        <button type="button" class="qls-remove-coupon" id="qls-vip-remove-coupon">&times;</button>
                    </div>
                    <input type="hidden" id="qls-vip-coupon-claim-id" value="">
                    <input type="hidden" id="qls-vip-coupon-discount-amount" value="0">
                </div>
                
                <div class="qls-payment-methods">
                    <h4><?php _e('选择支付方式', 'qilingshop'); ?></h4>
                    <div class="qls-payment-list">
                        <!-- 积分支付 -->
                        <label class="qls-payment-option" id="qls-points-option">
                            <input type="radio" name="vip_payment_method" value="points" checked>
                            <span class="qls-payment-label">
                                <span class="qls-payment-icon qls-icon-points"><?php echo get_option('qilingshop_currency_symbol', '¥'); ?></span> 
                                <?php printf(esc_html__('%s支付', 'qilingshop'), esc_html($points_name)); ?>
                                <small class="qls-payment-balance"></small>
                            </span>
                        </label>
                        
                        <?php if (get_option('qilingshop_direct_pay_enabled', true)): ?>
                            <!-- 支付宝 -->
                            <?php
                            $alipay_enabled = (bool) get_option('qilingshop_alipay_enabled');
                            $alipay_f2f_enabled = (bool) get_option('qilingshop_alipay_f2fpay');
                            if ($alipay_enabled && $alipay_f2f_enabled):
                            ?>
                            <label class="qls-payment-option">
                                <input type="radio" name="vip_payment_method" value="alipay_qr">
                                <span class="qls-payment-label">
                                    <img src="<?php echo QILINGSHOP_URL; ?>static/img/zfb.png" class="qls-payment-icon" style="width:24px;height:24px;">
                                    <?php _e('支付宝扫码', 'qilingshop'); ?>
                                </span>
                            </label>
                            <label class="qls-payment-option">
                                <input type="radio" name="vip_payment_method" value="alipay_page">
                                <span class="qls-payment-label">
                                    <img src="<?php echo QILINGSHOP_URL; ?>static/img/zfb.png" class="qls-payment-icon" style="width:24px;height:24px;">
                                    <?php _e('支付宝网页', 'qilingshop'); ?>
                                </span>
                            </label>
                            <?php elseif ($alipay_enabled): ?>
                            <label class="qls-payment-option">
                                <input type="radio" name="vip_payment_method" value="alipay">
                                <span class="qls-payment-label">
                                    <img src="<?php echo QILINGSHOP_URL; ?>static/img/zfb.png" class="qls-payment-icon" style="width:24px;height:24px;">
                                    <?php _e('支付宝', 'qilingshop'); ?>
                                </span>
                            </label>
                            <?php endif; ?>
                            
                            <!-- 微信支付 -->
                            <?php if (get_option('qilingshop_wechat_enabled')): ?>
                            <label class="qls-payment-option">
                                <input type="radio" name="vip_payment_method" value="wechat">
                                <span class="qls-payment-label">
                                    <img src="<?php echo QILINGSHOP_URL; ?>static/img/wx.png" class="qls-payment-icon" style="width:24px;height:24px;">
                                    <?php _e('微信支付', 'qilingshop'); ?>
                                </span>
                            </label>
                            <?php endif; ?>

                            <?php if (get_option('qilingshop_xhpay_enabled')): ?>
                            <label class="qls-payment-option">
                                <input type="radio" name="vip_payment_method" value="xhpay">
                                <span class="qls-payment-label">
                                    <img src="<?php echo QILINGSHOP_URL; ?>static/img/zfb.png" class="qls-payment-icon" style="width:24px;height:24px;">
                                    <?php _e('虎皮椒 V3', 'qilingshop'); ?>
                                </span>
                            </label>
                            <?php endif; ?>

                            <?php if (get_option('qilingshop_epay_enabled')): ?>
                            <label class="qls-payment-option">
                                <input type="radio" name="vip_payment_method" value="epay">
                                <span class="qls-payment-label">
                                    <img src="<?php echo QILINGSHOP_URL; ?>static/img/zfb.png" class="qls-payment-icon" style="width:24px;height:24px;">
                                    <?php _e('易支付', 'qilingshop'); ?>
                                </span>
                            </label>
                            <?php endif; ?>
                            
                            <!-- PayPal -->
                            <?php if (get_option('qilingshop_paypal_enabled')): ?>
                            <label class="qls-payment-option">
                                <input type="radio" name="vip_payment_method" value="paypal">
                                <span class="qls-payment-label">
                                    <img src="<?php echo QILINGSHOP_URL; ?>static/img/paypal.png" class="qls-payment-icon" style="width:24px;height:24px;">
                                    PayPal
                                </span>
                            </label>
                            <?php endif; ?>

                            <?php if (get_option('qilingshop_stripe_enabled')): ?>
                            <label class="qls-payment-option">
                                <input type="radio" name="vip_payment_method" value="stripe">
                                <span class="qls-payment-label">
                                    <img src="<?php echo QILINGSHOP_URL; ?>static/img/stripe.png" class="qls-payment-icon" style="width:24px;height:24px;">
                                    <?php _e('Stripe', 'qilingshop'); ?>
                                </span>
                            </label>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="qls-modal-footer">
                <button id="qls-confirm-vip-buy" class="qls-btn qls-btn-primary qls-btn-block"><?php _e('确认支付', 'qilingshop'); ?></button>
            </div>
        </div>
    </div>

</div>

<?php 
// 4. 加载网站底部
get_footer(); 
?>
