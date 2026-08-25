<?php
/**
 * 个人中心 - 积分商城首页
 * 
 * 可用变量: $user_id, $current_user
 *
 * @package QilingShop
 */

if (!defined('ABSPATH')) exit;

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

$points = QilingShop_Points::instance();
$vip = QilingShop_VIP::instance();

$balance = $points->get_balance($user_id);
$vip_level_info = $vip->get_user_level_info($user_id);
$is_vip = $vip->is_vip($user_id);
$vip_expires = $vip->get_user_expires($user_id);
$vip_info = $is_vip && $vip_level_info ? [
    'is_vip' => true,
    'level' => $vip_level_info->id,
    'level_name' => $vip_level_info->level_name,
    'expire_time' => $vip_expires,
] : null;
$today_signed = $points->has_checked_in_today($user_id);
$points_name = qilingshop_get_points_name();
?>

<div class="qls-account-section">
    <!-- 积分余额卡片 -->
    <div class="qls-balance-card">
        <div class="qls-balance-header">
            <h3><?php printf(esc_html__('%s余额', 'qilingshop'), esc_html($points_name)); ?></h3>
            <?php if ($vip_info && $vip_info['is_vip']): ?>
            <?php $vip_badge_color = $vip_level_info ? $vip_level_info->badge_color : ''; ?>
            <span class="qls-vip-badge vip-<?php echo esc_attr($vip_info['level']); ?>" style="<?php echo $vip_badge_color ? 'background:' . esc_attr($vip_badge_color) . ';' : ''; ?>">
                <?php echo esc_html($vip_info['level_name']); ?>
            </span>
            <?php endif; ?>
        </div>
        <div class="qls-balance-amount">
            <span class="qls-balance-value"><?php echo number_format($balance); ?></span>
            <span class="qls-balance-unit"><?php echo esc_html($points_name); ?></span>
            <span class="qls-balance-ratio-tag">
                <?php echo sprintf(__('1元 = %s %s', 'qilingshop'), qilingshop_get_points_ratio(), $points_name); ?>
            </span>
        </div>
        <div class="qls-balance-actions">
            <a href="?tab=qls-points" class="qls-btn qls-btn-outline"><?php _e('积分明细', 'qilingshop'); ?></a>
            <button class="qls-btn qls-btn-primary" id="qls-recharge-btn"><?php _e('立即充值', 'qilingshop'); ?></button>
        </div>
    </div>

    <?php 
    // 获取充值奖励规则
    $bonus_rules = QilingShop_Recharge::instance()->get_bonus_rules();
    $ratio = qilingshop_get_points_ratio();
    if (!empty($bonus_rules)): 
    ?>
    <!-- 快捷充值 -->
    <div class="qls-quick-recharge">
        <h4><?php _e('快捷充值', 'qilingshop'); ?></h4>
        <div class="qls-recharge-grid">
            <?php foreach ($bonus_rules as $rule): 
                // 计算显示文本
                if ($rule->bonus_type === 'fixed') {
                    $bonus_text = sprintf(__('送%s%s', 'qilingshop'), number_format($rule->bonus_value * $ratio), $points_name);
                } else {
                    $bonus_text = sprintf(__('送%s%%', 'qilingshop'), $rule->bonus_value);
                }
            ?>
            <button class="qls-recharge-item" 
                    data-amount="<?php echo esc_attr($rule->min_amount); ?>"
                    data-bonus-type="<?php echo esc_attr($rule->bonus_type); ?>"
                    data-bonus-value="<?php echo esc_attr($rule->bonus_value); ?>">
                <span class="qls-recharge-amount"><?php printf(esc_html__('充%s元', 'qilingshop'), esc_html(number_format($rule->min_amount))); ?></span>
                <span class="qls-recharge-bonus"><?php echo esc_html($bonus_text); ?></span>
            </button>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- 快捷功能 -->
    <div class="qls-quick-actions">
        <h4><?php _e('快捷功能', 'qilingshop'); ?></h4>
        <div class="qls-actions-grid">
            <!-- 签到（固定项） -->
            <div class="qls-action-item">
                <div class="qls-action-icon"><?php echo qilingshop_render_icon(get_option('qilingshop_quick_action_1_icon', '📅'), 'qls-action-icon-glyph'); ?></div>
                <div class="qls-action-info">
                    <span class="qls-action-title"><?php echo esc_html(get_option('qilingshop_quick_action_1_title', __('每日签到', 'qilingshop'))); ?></span>
                    <?php if ($today_signed): ?>
                        <span class="qls-action-status signed"><?php _e('今日已签到', 'qilingshop'); ?></span>
                    <?php else: ?>
                        <button class="qls-btn qls-btn-sm" id="qls-checkin-btn"><?php _e('立即签到', 'qilingshop'); ?></button>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php 
            // 自定义快捷功能 2-4
            $action_defaults = [
                2 => ['icon' => '👑', 'title' => __('VIP会员', 'qilingshop'), 'desc' => __('开通享受优惠', 'qilingshop'), 'link' => '?tab=qls-vip'],
                3 => ['icon' => '🎁', 'title' => __('邀请好友', 'qilingshop'), 'desc' => __('邀请赚积分', 'qilingshop'), 'link' => '?tab=qls-invite'],
                4 => ['icon' => '📦', 'title' => __('我的订单', 'qilingshop'), 'desc' => __('查看购买记录', 'qilingshop'), 'link' => '?tab=qls-orders'],
            ];
            
            foreach ($action_defaults as $i => $def):
                $icon = get_option('qilingshop_quick_action_' . $i . '_icon', $def['icon']);
                $title = get_option('qilingshop_quick_action_' . $i . '_title', $def['title']);
                $desc = get_option('qilingshop_quick_action_' . $i . '_desc', $def['desc']);
                $link = get_option('qilingshop_quick_action_' . $i . '_link', $def['link']);
                
                // 如果标题为空则跳过
                if (empty($title)) continue;
            ?>
            <a href="<?php echo esc_url($link); ?>" class="qls-action-item">
                <div class="qls-action-icon"><?php echo qilingshop_render_icon($icon, 'qls-action-icon-glyph'); ?></div>
                <div class="qls-action-info">
                    <span class="qls-action-title"><?php echo esc_html($title); ?></span>
                    <?php 
                    // 特殊处理VIP状态显示
                    if ($i === 2 && $vip_info && $vip_info['is_vip']): ?>
                        <span class="qls-action-status vip"><?php echo sprintf(__('到期：%s', 'qilingshop'), date_i18n('Y-m-d', strtotime($vip_info['expire_time']))); ?></span>
                    <?php elseif ($desc): ?>
                        <span class="qls-action-status"><?php echo esc_html($desc); ?></span>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- 充值弹窗 -->
<div id="qls-recharge-modal" class="qls-modal" style="display:none;">
    <div class="qls-modal-overlay"></div>
    <div class="qls-modal-content">
        <div class="qls-modal-header">
            <h3><?php _e('积分充值', 'qilingshop'); ?></h3>
            <button class="qls-modal-close">&times;</button>
        </div>
        <div class="qls-modal-body">
            <?php
            $packages = get_option('qilingshop_recharge_packages', []);
            $custom_enable = get_option('qilingshop_custom_recharge', true);
            $tips_recharge = get_option('qilingshop_tips_recharge', '');
            ?>
            
            <?php if ($tips_recharge): ?>
            <div class="qls-tips-box">
                <?php echo wp_kses_post($tips_recharge); ?>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($packages)): ?>
            <div class="qls-package-grid">
                <?php foreach ($packages as $pkg): ?>
                <div class="qls-package-item" data-amount="<?php echo esc_attr($pkg['price']); ?>" data-points="<?php echo esc_attr($pkg['points']); ?>">
                    <div class="qls-package-points"><?php echo number_format($pkg['points']); ?></div>
                    <div class="qls-package-unit"><?php echo esc_html($points_name); ?></div>
                    <div class="qls-package-price">¥<?php echo esc_html($pkg['price']); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <?php if ($custom_enable): ?>
            <div class="qls-custom-amount">
                <label><?php _e('自定义金额', 'qilingshop'); ?></label>
                <div class="qls-input-group">
                    <span class="qls-input-prefix">¥</span>
                    <input type="number" id="qls-custom-amount" min="1" placeholder="<?php _e('输入金额', 'qilingshop'); ?>">
                </div>
            </div>
            <?php endif; ?>
            
            <!-- 优惠券选择 -->
            <div class="qls-coupon-section" id="qls-recharge-coupon-section" data-order-type="recharge">
                <label><?php _e('优惠券', 'qilingshop'); ?></label>
                <div class="qls-coupon-trigger" id="qls-recharge-coupon-trigger">
                    <span class="dashicons dashicons-tickets-alt"></span>
                    <span class="qls-coupon-text"><?php _e('选择优惠券', 'qilingshop'); ?></span>
                    <span class="qls-coupon-selected" id="qls-recharge-coupon-selected"></span>
                </div>
                <div class="qls-coupon-applied" id="qls-recharge-coupon-applied" style="display:none;">
                    <div class="coupon-info">
                        <span class="coupon-name" id="qls-recharge-coupon-name"></span>
                        <span class="coupon-discount" id="qls-recharge-coupon-discount"></span>
                    </div>
                    <button type="button" class="qls-remove-coupon" id="qls-recharge-remove-coupon">&times;</button>
                </div>
                <input type="hidden" id="qls-recharge-coupon-claim-id" value="">
                <input type="hidden" id="qls-recharge-coupon-discount-amount" value="0">
            </div>
            
            <div class="qls-payment-methods">
                <label><?php _e('支付方式', 'qilingshop'); ?></label>
                <div class="qls-payment-options">
                    <?php
                    $first_enabled = true;
                    $alipay_enabled = (bool) get_option('qilingshop_alipay_enabled');
                    $alipay_f2f_enabled = (bool) get_option('qilingshop_alipay_f2fpay');
                    if ($alipay_enabled):
                        if ($alipay_f2f_enabled):
                    ?>
                    <label class="qls-payment-option">
                        <input type="radio" name="payment_method" value="alipay_qr" <?php echo $first_enabled ? 'checked' : ''; ?>>
                        <span class="qls-payment-label"><img src="<?php echo QILINGSHOP_URL; ?>static/img/zfb.png" class="qls-payment-icon"> <?php _e('支付宝扫码', 'qilingshop'); ?></span>
                    </label>
                    <?php $first_enabled = false; ?>
                    <label class="qls-payment-option">
                        <input type="radio" name="payment_method" value="alipay_page" <?php echo $first_enabled ? 'checked' : ''; ?>>
                        <span class="qls-payment-label"><img src="<?php echo QILINGSHOP_URL; ?>static/img/zfb.png" class="qls-payment-icon"> <?php _e('支付宝网页', 'qilingshop'); ?></span>
                    </label>
                    <?php $first_enabled = false; else: ?>
                    <label class="qls-payment-option">
                        <input type="radio" name="payment_method" value="alipay" <?php echo $first_enabled ? 'checked' : ''; ?>>
                        <span class="qls-payment-label"><img src="<?php echo QILINGSHOP_URL; ?>static/img/zfb.png" class="qls-payment-icon"> <?php _e('支付宝', 'qilingshop'); ?></span>
                    </label>
                    <?php $first_enabled = false; endif; endif; ?>
                    
                    <?php if (get_option('qilingshop_wechat_enabled')): ?>
                    <label class="qls-payment-option">
                        <input type="radio" name="payment_method" value="wechat" <?php echo $first_enabled ? 'checked' : ''; ?>>
                        <span class="qls-payment-label"><img src="<?php echo QILINGSHOP_URL; ?>static/img/wx.png" class="qls-payment-icon"> <?php _e('微信支付', 'qilingshop'); ?></span>
                    </label>
                    <?php $first_enabled = false; endif; ?>

                    <?php if (get_option('qilingshop_xhpay_enabled')): ?>
                    <label class="qls-payment-option">
                        <input type="radio" name="payment_method" value="xhpay" <?php echo $first_enabled ? 'checked' : ''; ?>>
                        <span class="qls-payment-label"><img src="<?php echo QILINGSHOP_URL; ?>static/img/zfb.png" class="qls-payment-icon"> <?php _e('虎皮椒 V3', 'qilingshop'); ?></span>
                    </label>
                    <?php $first_enabled = false; endif; ?>

                    <?php if (get_option('qilingshop_epay_enabled')): ?>
                    <label class="qls-payment-option">
                        <input type="radio" name="payment_method" value="epay" <?php echo $first_enabled ? 'checked' : ''; ?>>
                        <span class="qls-payment-label"><img src="<?php echo QILINGSHOP_URL; ?>static/img/zfb.png" class="qls-payment-icon"> <?php _e('易支付', 'qilingshop'); ?></span>
                    </label>
                    <?php $first_enabled = false; endif; ?>
                    
                    <?php if (get_option('qilingshop_paypal_enabled')): ?>
                    <label class="qls-payment-option">
                        <input type="radio" name="payment_method" value="paypal" <?php echo $first_enabled ? 'checked' : ''; ?>>
                        <span class="qls-payment-label"><img src="<?php echo QILINGSHOP_URL; ?>static/img/paypal.png" class="qls-payment-icon"> PayPal</span>
                    </label>
                    <?php $first_enabled = false; endif; ?>

                    <?php if (get_option('qilingshop_stripe_enabled')): ?>
                    <label class="qls-payment-option">
                        <input type="radio" name="payment_method" value="stripe" <?php echo $first_enabled ? 'checked' : ''; ?>>
                        <span class="qls-payment-label"><img src="<?php echo QILINGSHOP_URL; ?>static/img/stripe.png" class="qls-payment-icon"> <?php _e('Stripe', 'qilingshop'); ?></span>
                    </label>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="qls-modal-footer">
            <button class="qls-btn qls-btn-primary qls-btn-block" id="qls-submit-recharge"><?php _e('确认充值', 'qilingshop'); ?></button>
        </div>
    </div>
</div>

<script>
(function() {
    // 签到
    var checkinBtn = document.getElementById('qls-checkin-btn');
    if (checkinBtn) {
        checkinBtn.addEventListener('click', function() {
            this.disabled = true;
            this.textContent = '<?php _e('签到中...', 'qilingshop'); ?>';
            
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=qilingshop_checkin&nonce=<?php echo wp_create_nonce('qilingshop_ajax'); ?>'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    this.textContent = '✓ <?php _e('签到成功', 'qilingshop'); ?>';
                    this.classList.add('signed');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    alert(data.data?.message || '<?php _e('签到失败', 'qilingshop'); ?>');
                    this.disabled = false;
                    this.textContent = '<?php _e('立即签到', 'qilingshop'); ?>';
                }
            });
        });
    }
    
    // 充值弹窗
    var rechargeBtn = document.getElementById('qls-recharge-btn');
    var modal = document.getElementById('qls-recharge-modal');
    if (rechargeBtn && modal) {
        // 弹窗脱离个人中心内容容器，避免被主题的 overflow/transform 裁剪。
        var modalParent = modal.parentNode;
        var modalNextSibling = modal.nextSibling;
        var accountSkin = modal.closest('.qls-account-skin');
        if (accountSkin) {
            accountSkin.classList.forEach(function(className) {
                if (className.indexOf('qls-account-skin-') === 0) {
                    modal.classList.add(className);
                }
            });
        }
        var moveModalToBody = function() {
            if (modal.parentNode !== document.body) {
                document.body.appendChild(modal);
            }
        };
        var restoreModal = function() {
            modal.style.display = 'none';
            if (modalParent && modal.parentNode === document.body) {
                if (modalNextSibling && modalNextSibling.parentNode === modalParent) {
                    modalParent.insertBefore(modal, modalNextSibling);
                } else {
                    modalParent.appendChild(modal);
                }
            }
        };
        rechargeBtn.addEventListener('click', function() {
            moveModalToBody();
            modal.style.display = 'flex';
        });
        modal.querySelector('.qls-modal-close').addEventListener('click', function() {
            restoreModal();
        });
        modal.querySelector('.qls-modal-overlay').addEventListener('click', function() {
            restoreModal();
        });

        modal.addEventListener('click', function(event) {
            if (event.target === modal) {
                restoreModal();
            }
        });
    }
    
    // 快捷充值按钮
    var quickRechargeButtons = document.querySelectorAll('.qls-recharge-item');
    quickRechargeButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var amount = this.dataset.amount;
            if (modal) {
                if (typeof moveModalToBody === 'function') {
                    moveModalToBody();
                }
                modal.style.display = 'flex';
                // 清除已选套餐
                var packages = document.querySelectorAll('.qls-package-item');
                packages.forEach(function(p) { p.classList.remove('selected'); });
                // 设置自定义金额
                var customInput = document.getElementById('qls-custom-amount');
                if (customInput) {
                    customInput.value = amount;
                    // 触发优惠券更新
                    updateCouponForAmount(amount);
                }
            }
        });
    });
    
    // 套餐选择
    var packages = document.querySelectorAll('.qls-package-item');
    packages.forEach(function(pkg) {
        pkg.addEventListener('click', function() {
            packages.forEach(p => p.classList.remove('selected'));
            this.classList.add('selected');
            document.getElementById('qls-custom-amount').value = '';
            // 触发优惠券更新
            var amount = this.dataset.amount;
            updateCouponForAmount(amount);
        });
    });
    
    // 自定义金额输入
    var customAmountInput = document.getElementById('qls-custom-amount');
    if (customAmountInput) {
        customAmountInput.addEventListener('input', function() {
            packages.forEach(p => p.classList.remove('selected'));
            updateCouponForAmount(this.value);
        });
    }
    
    // 获取当前充值金额
    function getCurrentRechargeAmount() {
        var selected = document.querySelector('.qls-package-item.selected');
        var customAmount = document.getElementById('qls-custom-amount')?.value;
        return parseFloat(selected ? selected.dataset.amount : customAmount) || 0;
    }
    
    // 更新优惠券显示（当选择的优惠券可能不再适用时重新计算）
    function updateCouponForAmount(amount) {
        var couponClaimId = document.getElementById('qls-recharge-coupon-claim-id')?.value;
        if (couponClaimId && window.currentSelectedCoupon) {
            // 重新计算折扣金额
            var discount = calculateCouponDiscount(window.currentSelectedCoupon, parseFloat(amount) || 0);
            document.getElementById('qls-recharge-coupon-discount').textContent = '-¥' + discount.toFixed(2);
            document.getElementById('qls-recharge-coupon-discount-amount').value = discount;
        }
    }
    
    // 计算优惠券折扣
    function calculateCouponDiscount(coupon, amount) {
        if (!coupon) return 0;
        var discount = 0;
        if (coupon.discountType === 'fixed') {
            discount = coupon.discountValue;
        } else {
            discount = amount * (coupon.discountValue / 100);
            if (coupon.maxDiscount > 0 && discount > coupon.maxDiscount) {
                discount = coupon.maxDiscount;
            }
        }
        // 只有当金额大于0时才限制折扣不超过金额
        if (amount > 0 && discount > amount) {
            discount = amount;
        }
        return Math.round(discount * 100) / 100;
    }
    
    // 优惠券选择触发器
    var couponTrigger = document.getElementById('qls-recharge-coupon-trigger');
    if (couponTrigger) {
        couponTrigger.addEventListener('click', function() {
            var amount = getCurrentRechargeAmount();
            if (typeof QLSCoupon !== 'undefined') {
                QLSCoupon.showPicker({
                    orderType: 'recharge',
                    amount: amount,
                    onSelect: function(coupon, discount) {
                        // 保存选中的优惠券信息
                        window.currentSelectedCoupon = coupon;
                        document.getElementById('qls-recharge-coupon-claim-id').value = coupon.claimId;
                        document.getElementById('qls-recharge-coupon-name').textContent = coupon.name;
                        document.getElementById('qls-recharge-coupon-discount').textContent = '-¥' + discount.toFixed(2);
                        document.getElementById('qls-recharge-coupon-discount-amount').value = discount;
                        // 显示已选优惠券，隐藏触发器
                        document.getElementById('qls-recharge-coupon-trigger').style.display = 'none';
                        document.getElementById('qls-recharge-coupon-applied').style.display = 'flex';
                    }
                });
            } else {
                console.error('QLSCoupon not loaded');
            }
        });
    }
    
    // 移除已选优惠券
    var removeCouponBtn = document.getElementById('qls-recharge-remove-coupon');
    if (removeCouponBtn) {
        removeCouponBtn.addEventListener('click', function() {
            window.currentSelectedCoupon = null;
            document.getElementById('qls-recharge-coupon-claim-id').value = '';
            document.getElementById('qls-recharge-coupon-discount-amount').value = '0';
            document.getElementById('qls-recharge-coupon-trigger').style.display = 'flex';
            document.getElementById('qls-recharge-coupon-applied').style.display = 'none';
        });
    }
    
    // 提交充值
    var submitBtn = document.getElementById('qls-submit-recharge');
    if (submitBtn) {
        submitBtn.addEventListener('click', function() {
            var selected = document.querySelector('.qls-package-item.selected');
            var customAmount = document.getElementById('qls-custom-amount')?.value;
            var amount = selected ? selected.dataset.amount : customAmount;
            var method = document.querySelector('input[name="payment_method"]:checked')?.value;
            var couponClaimId = document.getElementById('qls-recharge-coupon-claim-id')?.value || '';
            
            if (!amount || amount <= 0) {
                alert('<?php _e('请选择或输入充值金额', 'qilingshop'); ?>');
                return;
            }
            if (!method) {
                alert('<?php _e('请选择支付方式', 'qilingshop'); ?>');
                return;
            }
            
            this.disabled = true;
            this.textContent = '<?php _e('处理中...', 'qilingshop'); ?>';
            
            // 通过AJAX创建充值订单（包含优惠券）
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=qilingshop_recharge&amount=' + amount + '&gateway=' + method + '&coupon_claim_id=' + couponClaimId + '&nonce=<?php echo wp_create_nonce('qilingshop_ajax'); ?>'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success && data.data.pay_url) {
                    window.location.href = data.data.pay_url;
                } else {
                    alert(data.message || '<?php _e('创建订单失败', 'qilingshop'); ?>');
                    this.disabled = false;
                    this.textContent = '<?php _e('确认充值', 'qilingshop'); ?>';
                }
            })
            .catch(function() {
                alert('<?php _e('网络错误', 'qilingshop'); ?>');
                this.disabled = false;
                this.textContent = '<?php _e('确认充值', 'qilingshop'); ?>';
            });
        });
    }
})();
</script>
