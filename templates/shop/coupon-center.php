<?php
/**
 * 优惠券中心页面模板 (全屏模式)
 * 
 * @package QilingShop
 * @since   2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// 使用动态版本号
$assets_version = function_exists('qilingshop_get_assets_version') ? qilingshop_get_assets_version() : QILINGSHOP_VERSION;

// 加载优惠券资源
wp_enqueue_style('qls-coupon', QILINGSHOP_URL . 'static/css/coupon.css', [], $assets_version);
wp_enqueue_script('qls-coupon-selector', QILINGSHOP_URL . 'static/js/coupon-selector.js', [], $assets_version, true);
wp_localize_script('qls-coupon-selector', 'qlsCoupon', [
    'ajaxUrl' => admin_url('admin-ajax.php'),
    'nonce'   => wp_create_nonce('qls_coupon_nonce'),
    'currency' => '¥',
    'i18n' => qilingshop_get_coupon_selector_i18n(),
]);

// 渲染全屏模式头部（与商城页面保持一致）
qls_shop_public()->get_shop_header(__('优惠券中心', 'qilingshop'), true);

$user_id = get_current_user_id();
$is_logged_in = $user_id > 0;

// 确保优惠券类已加载
if (!class_exists('QLS_Coupon')) {
    require_once QILINGSHOP_PATH . 'includes/shop/class-qls-coupon.php';
}

$coupon_manager = QLS_Coupon::instance();
$public_coupons = $coupon_manager->get_public_coupons($user_id);
$my_coupons = $is_logged_in ? $coupon_manager->get_user_coupons($user_id, 'unused') : [];
$used_coupons = $is_logged_in ? $coupon_manager->get_user_coupons($user_id, 'used') : [];
$usage_records = $is_logged_in ? $coupon_manager->get_user_usage_records($user_id, 15) : [];
$public_count = count($public_coupons);
$my_unused_count = count($my_coupons);
$my_used_count = count($usage_records);

$apply_scopes = [
    'all' => __('全站通用', 'qilingshop'),
    'resource' => __('文章资源', 'qilingshop'),
    'recharge' => __('积分充值', 'qilingshop'),
    'vip' => __('VIP会员', 'qilingshop'),
    'shop' => __('实物商城', 'qilingshop'),
];
?>

<div class="qls-shop-wrapper qls-coupon-center-page">
<div class="qls-container">
    <nav class="qls-breadcrumb">
        <a href="<?php echo esc_url(qls_shop_public()->get_shop_url()); ?>"><?php _e('商城', 'qilingshop'); ?></a>
        <span class="sep">›</span>
        <span class="current"><?php _e('优惠券中心', 'qilingshop'); ?></span>
    </nav>
<div class="qls-coupon-center">
    <!-- 头部信息 -->
    <div class="qls-cc-hero">
        <div class="qls-cc-hero-main">
            <span class="qls-cc-eyebrow"><?php _e('领券中心', 'qilingshop'); ?></span>
            <h2><?php _e('优惠券中心', 'qilingshop'); ?></h2>
            <p><?php _e('先领券再下单，覆盖资源/VIP/积分充值/实物商城多场景优惠。', 'qilingshop'); ?></p>
        </div>
        <div class="qls-cc-stats">
            <div class="qls-cc-stat">
                <span class="label"><?php _e('可领取', 'qilingshop'); ?></span>
                <strong><?php echo intval($public_count); ?></strong>
            </div>
            <div class="qls-cc-stat">
                <span class="label"><?php _e('我的可用', 'qilingshop'); ?></span>
                <strong><?php echo $is_logged_in ? intval($my_unused_count) : 0; ?></strong>
            </div>
            <div class="qls-cc-stat">
                <span class="label"><?php _e('最近使用', 'qilingshop'); ?></span>
                <strong><?php echo $is_logged_in ? intval($my_used_count) : 0; ?></strong>
            </div>
        </div>
    </div>

    <!-- 优惠码输入 -->
    <div class="qls-cc-header">
        <h3><?php _e('兑换优惠码', 'qilingshop'); ?></h3>
        <div class="qls-cc-input">
            <input type="text" id="qls-coupon-code-input" placeholder="<?php _e('输入优惠码', 'qilingshop'); ?>">
            <button type="button" id="qls-redeem-coupon" class="qls-btn qls-btn-primary"><?php _e('立即兑换', 'qilingshop'); ?></button>
        </div>
    </div>

    <!-- 标签导航 -->
    <div class="qls-cc-tabs">
        <button type="button" class="qls-cc-tab active" data-tab="available"><?php _e('可领取', 'qilingshop'); ?></button>
        <button type="button" class="qls-cc-tab" data-tab="my"><?php _e('我的优惠券', 'qilingshop'); ?></button>
    </div>

    <div class="qls-scope-filter" id="qls-scope-filter">
        <span class="qls-scope-filter-label"><?php _e('可用范围', 'qilingshop'); ?></span>
        <button type="button" class="qls-scope-chip active" data-scope="__all__"><?php _e('全部范围', 'qilingshop'); ?></button>
        <button type="button" class="qls-scope-chip" data-scope="all"><?php echo esc_html($apply_scopes['all']); ?></button>
        <button type="button" class="qls-scope-chip" data-scope="resource"><?php echo esc_html($apply_scopes['resource']); ?></button>
        <button type="button" class="qls-scope-chip" data-scope="recharge"><?php echo esc_html($apply_scopes['recharge']); ?></button>
        <button type="button" class="qls-scope-chip" data-scope="vip"><?php echo esc_html($apply_scopes['vip']); ?></button>
        <button type="button" class="qls-scope-chip" data-scope="shop"><?php echo esc_html($apply_scopes['shop']); ?></button>
    </div>

    <!-- 可领取的优惠券 -->
    <div class="qls-cc-panel active" id="qls-panel-available">
        <?php if (empty($public_coupons)): ?>
        <div class="qls-cc-empty">
            <span class="dashicons dashicons-tickets-alt"></span>
            <p><?php _e('暂无可领取的优惠券', 'qilingshop'); ?></p>
        </div>
        <?php else: ?>
        <div class="qls-coupon-grid" id="qls-available-coupons-list">
            <?php foreach ($public_coupons as $coupon): ?>
            <div class="qls-coupon-card <?php echo $coupon->is_claimed ? 'claimed' : ''; ?>" data-coupon-id="<?php echo esc_attr($coupon->id); ?>" data-scope="<?php echo esc_attr($coupon->apply_scope); ?>">
                <div class="qls-coupon-left">
                    <div class="qls-coupon-value">
                        <?php if ($coupon->discount_type === 'fixed'): ?>
                        <span class="qls-cv-symbol">¥</span>
                        <span class="qls-cv-number"><?php echo intval($coupon->discount_value); ?></span>
                        <?php else: ?>
                        <span class="qls-cv-number"><?php echo $coupon->discount_value; ?></span>
                        <span class="qls-cv-symbol">%</span>
                        <?php endif; ?>
                    </div>
                    <div class="qls-coupon-condition">
                        <?php if ($coupon->min_amount > 0): ?>
                        <?php printf(__('满%s可用', 'qilingshop'), '¥' . number_format($coupon->min_amount, 0)); ?>
                        <?php else: ?>
                        <?php _e('无门槛', 'qilingshop'); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="qls-coupon-right">
                    <div class="qls-coupon-name"><?php echo esc_html($coupon->name); ?></div>
                    <div class="qls-coupon-scope qls-scope-<?php echo esc_attr($coupon->apply_scope); ?>"><?php echo esc_html($apply_scopes[$coupon->apply_scope] ?? ''); ?></div>
                    <div class="qls-coupon-time">
                        <?php if ($coupon->end_time): ?>
                        <?php printf(__('有效期至 %s', 'qilingshop'), date('Y/m/d', strtotime($coupon->end_time))); ?>
                        <?php else: ?>
                        <?php _e('永久有效', 'qilingshop'); ?>
                        <?php endif; ?>
                    </div>
                    <div class="qls-coupon-action">
                        <?php if ($coupon->is_claimed): ?>
                        <button type="button" class="qls-btn qls-btn-disabled" disabled><?php _e('已领取', 'qilingshop'); ?></button>
                        <?php elseif ($coupon->can_claim): ?>
                        <button type="button" class="qls-btn qls-btn-claim" data-coupon-id="<?php echo esc_attr($coupon->id); ?>"><?php _e('立即领取', 'qilingshop'); ?></button>
                        <?php else: ?>
                        <button type="button" class="qls-btn qls-btn-disabled" disabled title="<?php echo esc_attr($coupon->claim_reason); ?>"><?php echo esc_html($coupon->claim_reason); ?></button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($coupon->total_count > 0): ?>
                <div class="qls-coupon-stock">
                    <?php printf(__('剩余 %d 张', 'qilingshop'), max(0, $coupon->total_count - $coupon->claimed_count)); ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="qls-cc-empty qls-filter-empty" id="qls-available-filter-empty" style="display:none;">
            <span class="dashicons dashicons-filter"></span>
            <p><?php _e('当前筛选范围暂无可领取优惠券', 'qilingshop'); ?></p>
        </div>
        <?php endif; ?>
    </div>

    <!-- 我的优惠券 -->
    <div class="qls-cc-panel" id="qls-panel-my">
        <?php if (!$is_logged_in): ?>
        <div class="qls-cc-empty">
            <span class="dashicons dashicons-lock"></span>
            <p><?php _e('请先登录查看您的优惠券', 'qilingshop'); ?></p>
            <a href="<?php echo wp_login_url(get_permalink()); ?>" class="qls-btn qls-btn-primary"><?php _e('立即登录', 'qilingshop'); ?></a>
        </div>
        <?php else: ?>
        
        <!-- 我的优惠券筛选 -->
        <div class="qls-my-tabs">
            <button type="button" class="qls-my-tab active" data-status="unused"><?php _e('未使用', 'qilingshop'); ?> (<?php echo count($my_coupons); ?>)</button>
            <button type="button" class="qls-my-tab" data-status="used"><?php _e('已使用', 'qilingshop'); ?> (<?php echo count($usage_records); ?>)</button>
            <button type="button" class="qls-my-tab" data-status="expired"><?php _e('已过期', 'qilingshop'); ?></button>
        </div>
        
        <?php if (empty($my_coupons)): ?>
        <div class="qls-cc-empty" id="qls-no-unused-tip">
            <span class="dashicons dashicons-tickets-alt"></span>
            <p><?php _e('暂无可用优惠券', 'qilingshop'); ?></p>
            <button type="button" class="qls-btn qls-btn-outline" onclick="document.querySelector('[data-tab=available]').click();"><?php _e('去领取', 'qilingshop'); ?></button>
        </div>
        <?php endif; ?>
        
        <div class="qls-coupon-grid" id="qls-my-coupons-list" <?php echo empty($my_coupons) ? 'style="display:none;"' : ''; ?>>
            <?php foreach ($my_coupons as $coupon): ?>
            <div class="qls-coupon-card my-coupon" data-scope="<?php echo esc_attr($coupon->apply_scope); ?>">
                <div class="qls-coupon-left">
                    <div class="qls-coupon-value">
                        <?php if ($coupon->discount_type === 'fixed'): ?>
                        <span class="qls-cv-symbol">¥</span>
                        <span class="qls-cv-number"><?php echo intval($coupon->discount_value); ?></span>
                        <?php else: ?>
                        <span class="qls-cv-number"><?php echo $coupon->discount_value; ?></span>
                        <span class="qls-cv-symbol">%</span>
                        <?php endif; ?>
                    </div>
                    <div class="qls-coupon-condition">
                        <?php if ($coupon->min_amount > 0): ?>
                        <?php printf(__('满%s可用', 'qilingshop'), '¥' . number_format($coupon->min_amount, 0)); ?>
                        <?php else: ?>
                        <?php _e('无门槛', 'qilingshop'); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="qls-coupon-right">
                    <div class="qls-coupon-name"><?php echo esc_html($coupon->name); ?></div>
                    <div class="qls-coupon-scope qls-scope-<?php echo esc_attr($coupon->apply_scope); ?>"><?php echo esc_html($apply_scopes[$coupon->apply_scope] ?? ''); ?></div>
                    <div class="qls-coupon-time">
                        <?php printf(__('有效期至 %s', 'qilingshop'), date('Y/m/d H:i', strtotime($coupon->expires_at))); ?>
                    </div>
                    <div class="qls-coupon-code">
                        <code><?php echo esc_html($coupon->code); ?></code>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="qls-cc-empty qls-filter-empty" id="qls-my-filter-empty" style="display:none;">
            <span class="dashicons dashicons-filter"></span>
            <p><?php _e('当前筛选范围暂无可用优惠券', 'qilingshop'); ?></p>
        </div>
        
        <!-- 已使用优惠券记录 -->
        <div id="qls-used-coupons-section" class="qls-used-section" style="display:none;">
            <h4 class="qls-used-title">📋 <?php _e('最近15天使用记录', 'qilingshop'); ?></h4>
            <?php if (empty($usage_records)): ?>
            <div class="qls-cc-empty qls-used-empty">
                <span class="dashicons dashicons-clipboard"></span>
                <p><?php _e('暂无使用记录', 'qilingshop'); ?></p>
            </div>
            <?php else: ?>
            <div class="qls-usage-list">
                <?php 
                $order_type_labels = [
                    'resource' => __('购买文章', 'qilingshop'),
                    'recharge' => __('积分充值', 'qilingshop'),
                    'vip' => __('开通VIP', 'qilingshop'),
                    'shop' => __('商城购物', 'qilingshop'),
                ];
                foreach ($usage_records as $record): 
                ?>
                <div class="qls-usage-item" data-scope="<?php echo esc_attr($record->order_type); ?>">
                    <div class="qls-usage-coupon">
                        <span class="qls-usage-value">
                            <?php if ($record->discount_type === 'fixed'): ?>
                            ¥<?php echo intval($record->discount_value); ?>
                            <?php else: ?>
                            <?php echo $record->discount_value; ?>%
                            <?php endif; ?>
                        </span>
                        <span class="qls-usage-name"><?php echo esc_html($record->coupon_name); ?></span>
                    </div>
                    <div class="qls-usage-order">
                        <span class="qls-usage-type qls-scope-<?php echo esc_attr($record->order_type); ?>"><?php echo esc_html($order_type_labels[$record->order_type] ?? $record->order_type); ?></span>
                        <span class="qls-usage-no"><?php _e('订单号:', 'qilingshop'); ?> <?php echo esc_html($record->order_no); ?></span>
                    </div>
                    <div class="qls-usage-detail">
                        <span class="qls-usage-discount">-¥<?php echo number_format($record->discount_amount, 2); ?></span>
                        <span class="qls-usage-time"><?php echo date('m/d H:i', strtotime($record->created_at)); ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="qls-cc-empty qls-filter-empty" id="qls-used-filter-empty" style="display:none;">
                <span class="dashicons dashicons-filter"></span>
                <p><?php _e('当前筛选范围暂无使用记录', 'qilingshop'); ?></p>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>


<script>
(function() {
    function qlsNotify(message, type) {
        var text = (message || '').toString();
        var level = type || 'info';
        if (window.QilingShopAccount && typeof window.QilingShopAccount.showMessage === 'function') {
            window.QilingShopAccount.showMessage(text, level);
            return;
        }
        var toast = document.createElement('div');
        toast.className = 'qls-cc-toast ' + level;
        toast.textContent = text;
        document.body.appendChild(toast);
        requestAnimationFrame(function() {
            toast.classList.add('show');
        });
        window.setTimeout(function() {
            toast.classList.remove('show');
            window.setTimeout(function() {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 260);
        }, 2200);
    }

    var currentScope = '__all__';

    function filterScopedItems(selector, emptyElId, scopeValue) {
        var activeScope = scopeValue || currentScope;
        var items = document.querySelectorAll(selector);
        var visibleCount = 0;
        items.forEach(function(item) {
            var itemScope = (item.getAttribute('data-scope') || '').toLowerCase();
            var visible = activeScope === '__all__' || itemScope === activeScope;
            item.style.display = visible ? '' : 'none';
            if (visible) {
                visibleCount += 1;
            }
        });

        var emptyEl = document.getElementById(emptyElId);
        if (emptyEl) {
            emptyEl.style.display = items.length > 0 && visibleCount === 0 ? 'block' : 'none';
        }

        return {
            total: items.length,
            visible: visibleCount
        };
    }

    function applyScopeFilter() {
        var availablePanel = document.getElementById('qls-panel-available');
        var myPanel = document.getElementById('qls-panel-my');
        var isAvailableActive = availablePanel && availablePanel.classList.contains('active');
        var isMyActive = myPanel && myPanel.classList.contains('active');

        var availableRes = filterScopedItems('#qls-available-coupons-list .qls-coupon-card', 'qls-available-filter-empty');
        var availableFilterEmpty = document.getElementById('qls-available-filter-empty');
        if (availableFilterEmpty && !isAvailableActive) {
            availableFilterEmpty.style.display = 'none';
        }
        if (isAvailableActive && availableRes.total > 0) {
            var availableList = document.getElementById('qls-available-coupons-list');
            if (availableList) {
                availableList.style.display = 'grid';
            }
        }

        var myList = document.getElementById('qls-my-coupons-list');
        var noUnusedTip = document.getElementById('qls-no-unused-tip');
        var myFilterEmpty = document.getElementById('qls-my-filter-empty');
        var activeMyTab = document.querySelector('.qls-my-tab.active');
        var myStatus = activeMyTab ? activeMyTab.dataset.status : 'unused';

        var myRes = filterScopedItems('#qls-my-coupons-list .qls-coupon-card', 'qls-my-filter-empty');
        if (myStatus === 'unused') {
            if (myList) {
                myList.style.display = myRes.visible > 0 ? 'grid' : 'none';
            }
            if (noUnusedTip) {
                noUnusedTip.style.display = myRes.total === 0 ? 'block' : 'none';
            }
            if (myFilterEmpty) {
                myFilterEmpty.style.display = myRes.total > 0 && myRes.visible === 0 ? 'block' : 'none';
            }
        } else if (myFilterEmpty) {
            myFilterEmpty.style.display = 'none';
        }
        if (!isMyActive && myFilterEmpty) {
            myFilterEmpty.style.display = 'none';
        }

        var usedScope = currentScope === 'all' ? '__all__' : currentScope;
        var usedRes = filterScopedItems('#qls-used-coupons-section .qls-usage-item', 'qls-used-filter-empty', usedScope);
        var usedSection = document.getElementById('qls-used-coupons-section');
        var usedFilterEmpty = document.getElementById('qls-used-filter-empty');
        var usedVisible = usedSection && usedSection.style.display !== 'none' && myStatus === 'used' && isMyActive;
        if (usedFilterEmpty) {
            usedFilterEmpty.style.display = usedVisible && usedRes.total > 0 && usedRes.visible === 0 ? 'block' : 'none';
        }
    }

    // 标签页切换
    document.querySelectorAll('.qls-cc-tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            document.querySelectorAll('.qls-cc-tab').forEach(function(t) { t.classList.remove('active'); });
            document.querySelectorAll('.qls-cc-panel').forEach(function(p) { p.classList.remove('active'); });
            this.classList.add('active');
            document.getElementById('qls-panel-' + this.dataset.tab).classList.add('active');
            applyScopeFilter();
        });
    });

    document.querySelectorAll('.qls-scope-chip').forEach(function(chip) {
        chip.addEventListener('click', function() {
            document.querySelectorAll('.qls-scope-chip').forEach(function(item) {
                item.classList.remove('active');
            });
            this.classList.add('active');
            currentScope = (this.dataset.scope || '__all__').toLowerCase();
            applyScopeFilter();
        });
    });

    // 我的优惠券子标签页切换
    document.querySelectorAll('.qls-my-tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            document.querySelectorAll('.qls-my-tab').forEach(function(t) { t.classList.remove('active'); });
            this.classList.add('active');
            
            var status = this.dataset.status;
            var couponList = document.getElementById('qls-my-coupons-list');
            var usedSection = document.getElementById('qls-used-coupons-section');
            var noUnusedTip = document.getElementById('qls-no-unused-tip');
            var hasCouponCard = couponList && couponList.querySelector('.qls-coupon-card');
            
            if (status === 'used') {
                // 显示已使用记录
                if (couponList) couponList.style.display = 'none';
                if (usedSection) usedSection.style.display = 'block';
                if (noUnusedTip) noUnusedTip.style.display = 'none';
            } else if (status === 'unused') {
                // 显示未使用优惠券
                if (couponList && hasCouponCard) {
                    couponList.style.display = 'grid';
                    if (noUnusedTip) noUnusedTip.style.display = 'none';
                } else {
                    if (couponList) couponList.style.display = 'none';
                    if (noUnusedTip) noUnusedTip.style.display = 'block';
                }
                if (usedSection) usedSection.style.display = 'none';
            } else {
                // 已过期标签（暂无功能）
                if (couponList) couponList.style.display = 'none';
                if (usedSection) usedSection.style.display = 'none';
                if (noUnusedTip) noUnusedTip.style.display = 'none';
            }
            applyScopeFilter();
        });
    });

    // 领取优惠券
    document.querySelectorAll('.qls-btn-claim').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var couponId = this.dataset.couponId;
            var btnEl = this;
            btnEl.disabled = true;
            btnEl.textContent = '<?php _e('领取中...', 'qilingshop'); ?>';

            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=qls_claim_coupon&coupon_id=' + couponId + '&nonce=<?php echo wp_create_nonce('qls_coupon_nonce'); ?>'
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success) {
                    btnEl.textContent = '<?php _e('已领取', 'qilingshop'); ?>';
                    btnEl.classList.remove('qls-btn-claim');
                    btnEl.classList.add('qls-btn-disabled');
                    btnEl.closest('.qls-coupon-card').classList.add('claimed');
                    qlsNotify('<?php echo esc_js(__('领取成功', 'qilingshop')); ?>', 'success');
                } else {
                    qlsNotify((data.data && data.data.message) || '<?php echo esc_js(__('领取失败', 'qilingshop')); ?>', 'error');
                    btnEl.disabled = false;
                    btnEl.textContent = '<?php _e('立即领取', 'qilingshop'); ?>';
                }
            })
            .catch(function() {
                qlsNotify('<?php echo esc_js(__('网络错误', 'qilingshop')); ?>', 'error');
                btnEl.disabled = false;
                btnEl.textContent = '<?php _e('立即领取', 'qilingshop'); ?>';
            });
        });
    });

    // 兑换优惠码
    document.getElementById('qls-redeem-coupon').addEventListener('click', function() {
        var code = document.getElementById('qls-coupon-code-input').value.trim();
        if (!code) {
            qlsNotify('<?php echo esc_js(__('请输入优惠码', 'qilingshop')); ?>', 'warning');
            return;
        }

        <?php if (!$is_logged_in): ?>
        qlsNotify('<?php echo esc_js(__('请先登录后兑换', 'qilingshop')); ?>', 'warning');
        return;
        <?php endif; ?>

        var btn = this;
        btn.disabled = true;
        btn.textContent = '<?php _e('兑换中...', 'qilingshop'); ?>';

        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=qls_validate_coupon&code=' + encodeURIComponent(code) + '&order_type=all&amount=0&nonce=<?php echo wp_create_nonce('qls_coupon_nonce'); ?>'
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                qlsNotify('<?php echo esc_js(__('兑换成功！', 'qilingshop')); ?> ' + data.data.coupon.name, 'success');
                window.setTimeout(function() {
                    location.reload();
                }, 700);
            } else {
                qlsNotify((data.data && data.data.message) || '<?php echo esc_js(__('兑换失败', 'qilingshop')); ?>', 'error');
            }
            btn.disabled = false;
            btn.textContent = '<?php _e('立即兑换', 'qilingshop'); ?>';
        })
        .catch(function() {
            qlsNotify('<?php echo esc_js(__('网络错误', 'qilingshop')); ?>', 'error');
            btn.disabled = false;
            btn.textContent = '<?php _e('立即兑换', 'qilingshop'); ?>';
        });
    });

    // 我的优惠券筛选
    document.querySelectorAll('.qls-my-tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            document.querySelectorAll('.qls-my-tab').forEach(function(t) { t.classList.remove('active'); });
            this.classList.add('active');
            
            // AJAX加载对应状态的优惠券
            var status = this.dataset.status;
            var listEl = document.getElementById('qls-my-coupons-list');
            if (status !== 'unused') {
                applyScopeFilter();
                return;
            }
            listEl.innerHTML = '<div style="text-align:center;padding:40px;color:#999;"><?php _e('加载中...', 'qilingshop'); ?></div>';

            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=qls_get_my_coupons&status=' + status + '&nonce=<?php echo wp_create_nonce('qls_coupon_nonce'); ?>'
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success && data.data.coupons.length > 0) {
                    var html = '';
                    var scopes = <?php echo json_encode($apply_scopes); ?>;
                    var couponText = {
                        minAmount: '<?php echo esc_js(__('满¥%s可用', 'qilingshop')); ?>',
                        noThreshold: '<?php echo esc_js(__('无门槛', 'qilingshop')); ?>',
                        validUntil: '<?php echo esc_js(__('有效期至 %s', 'qilingshop')); ?>'
                    };
                    data.data.coupons.forEach(function(c) {
                        var isUsed = c.status == 1;
                        var cardClass = isUsed ? 'qls-coupon-card my-coupon used' : 'qls-coupon-card my-coupon';
                        var scope = (c.apply_scope || '').toLowerCase();
                        html += '<div class="' + cardClass + '" data-scope="' + scope + '">';
                        html += '<div class="qls-coupon-left">';
                        html += '<div class="qls-coupon-value">';
                        if (c.discount_type === 'fixed') {
                            html += '<span class="qls-cv-symbol">¥</span><span class="qls-cv-number">' + parseInt(c.discount_value) + '</span>';
                        } else {
                            html += '<span class="qls-cv-number">' + c.discount_value + '</span><span class="qls-cv-symbol">%</span>';
                        }
                        html += '</div>';
                        html += '<div class="qls-coupon-condition">' + (c.min_amount > 0 ? couponText.minAmount.replace('%s', c.min_amount) : couponText.noThreshold) + '</div>';
                        html += '</div>';
                        html += '<div class="qls-coupon-right">';
                        html += '<div class="qls-coupon-name">' + c.name + '</div>';
                        html += '<div class="qls-coupon-scope qls-scope-' + scope + '">' + (scopes[c.apply_scope] || '') + '</div>';
                        html += '<div class="qls-coupon-time">' + couponText.validUntil.replace('%s', c.expires_at.substring(0, 10).replace(/-/g, '/')) + '</div>';
                        html += '<div class="qls-coupon-code"><code>' + c.code + '</code></div>';
                        html += '</div></div>';
                    });
                    listEl.innerHTML = html;
                    applyScopeFilter();
                } else {
                    listEl.innerHTML = '';
                    applyScopeFilter();
                }
            });
        });
    });

    applyScopeFilter();
})();
</script>

</div>
</div>
</div>

<?php 
// 渲染全屏模式底部
qls_shop_public()->get_shop_footer(); 
?>
