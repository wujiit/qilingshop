/**
 * 优惠券选择器组件
 * 
 * 提供通用的优惠券选择功能，可在各种支付场景中使用
 * 
 * @package QilingShop
 * @since   2.1.0
 */

var QLSCoupon = (function () {
    'use strict';

    // 配置：优先使用 qlsCoupon 全局变量，兼容多种场景
    var config = {
        ajaxUrl: '/wp-admin/admin-ajax.php',
        nonce: '',
        currency: '¥',
        i18n: {
            select_coupon: 'Select coupon',
            loading: '加载中...',
            no_coupons: '暂无可用优惠券',
            confirm: 'Confirm',
            coupon_min_amount: 'Available over {currency}{amount}',
            coupon_no_threshold: 'No threshold'
        }
    };

    function text(key, fallback) {
        return config.i18n[key] || fallback || key;
    }

    function formatText(key, replacements, fallback) {
        var value = text(key, fallback);
        Object.keys(replacements || {}).forEach(function (placeholder) {
            value = value.replace(new RegExp('\\{' + placeholder + '\\}', 'g'), replacements[placeholder]);
        });
        return value;
    }

    // 初始化配置
    function initConfig() {
        // 尝试从多个可能的全局变量获取配置（合并所有源）
        var sources = [
            typeof qlsCoupon !== 'undefined' ? qlsCoupon : null,
            typeof qilingshop !== 'undefined' ? qilingshop : null,
            typeof qlsVipLanding !== 'undefined' ? qlsVipLanding : null,
            typeof qlsShop !== 'undefined' ? qlsShop : null
        ];

        // 遍历所有源，合并配置（后面的覆盖前面的，qlsCoupon优先级最高放第一个）
        for (var i = sources.length - 1; i >= 0; i--) {
            var src = sources[i];
            if (src) {
                if (src.ajaxUrl && !config.ajaxUrl) config.ajaxUrl = src.ajaxUrl;
                if (src.nonce && !config.nonce) config.nonce = src.nonce;
                if (src.currency) config.currency = src.currency;
                if (src.i18n) {
                    for (var key in src.i18n) {
                        config.i18n[key] = src.i18n[key];
                    }
                }
            }
        }

        // qlsCoupon 优先级最高，最后覆盖
        if (typeof qlsCoupon !== 'undefined' && qlsCoupon) {
            if (qlsCoupon.ajaxUrl) config.ajaxUrl = qlsCoupon.ajaxUrl;
            if (qlsCoupon.nonce) config.nonce = qlsCoupon.nonce;
            if (qlsCoupon.currency) config.currency = qlsCoupon.currency;
        }
    }

    var state = {
        selectedCoupon: null,
        currentOrderType: '',
        currentAmount: 0,
        onSelectCallback: null
    };

    var $modal = null;

    /**
     * 初始化弹窗HTML
     */
    function initModal() {
        if ($modal) return;

        var html = '<div class="qls-coupon-picker-modal" id="qls-coupon-picker-modal">' +
            '<div class="qls-coupon-picker-overlay"></div>' +
            '<div class="qls-coupon-picker-content">' +
            '<div class="qls-coupon-picker-header">' +
            '<h4>' + text('select_coupon') + '</h4>' +
            '<button type="button" class="qls-coupon-picker-close">&times;</button>' +
            '</div>' +
            '<div class="qls-coupon-picker-body">' +
            '<div class="qls-coupon-picker-loading">' + text('loading') + '</div>' +
            '</div>' +
            '<div class="qls-coupon-picker-footer">' +
            '<button type="button" class="qls-btn qls-btn-primary" id="qls-coupon-picker-confirm">' +
            text('confirm') +
            '</button>' +
            '</div>' +
            '</div>' +
            '</div>';

        document.body.insertAdjacentHTML('beforeend', html);
        $modal = document.getElementById('qls-coupon-picker-modal');
        var accountSkin = document.querySelector('.qls-account-skin');
        if (accountSkin) {
            $modal.classList.add('qls-account-skin');
            ['fresh', 'business', 'coral', 'emerald'].forEach(function (style) {
                if (accountSkin.classList.contains('qls-account-skin-' + style)) {
                    $modal.classList.add('qls-account-skin-' + style);
                }
            });
        }

        // 绑定事件
        $modal.querySelector('.qls-coupon-picker-overlay').addEventListener('click', hide);
        $modal.querySelector('.qls-coupon-picker-close').addEventListener('click', hide);
        $modal.querySelector('#qls-coupon-picker-confirm').addEventListener('click', confirmSelection);
    }

    /**
     * 显示优惠券选择器
     */
    function showPicker(options) {
        initConfig();

        options = options || {};
        state.currentOrderType = options.orderType || 'all';
        state.currentAmount = parseFloat(options.amount) || 0;
        state.onSelectCallback = options.onSelect || null;
        state.selectedCoupon = null;

        initModal();
        $modal.classList.add('active');

        loadCoupons();
    }

    /**
     * 隐藏选择器
     */
    function hide() {
        if ($modal) {
            $modal.classList.remove('active');
        }
    }

    /**
     * 加载优惠券列表
     */
    function loadCoupons() {
        var body = $modal.querySelector('.qls-coupon-picker-body');
        body.innerHTML = '<div class="qls-coupon-picker-loading">' + text('loading') + '</div>';

        var formData = new FormData();
        formData.append('action', 'qls_get_available_coupons');
        formData.append('order_type', state.currentOrderType);
        formData.append('amount', state.currentAmount);
        formData.append('nonce', config.nonce);
        // 添加时间戳防止缓存
        formData.append('_t', Date.now());

        fetch(config.ajaxUrl, {
            method: 'POST',
            body: formData,
            // 禁用缓存
            cache: 'no-store'
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success && data.data && data.data.coupons && data.data.coupons.length > 0) {
                    renderCoupons(data.data.coupons);
                } else {
                    renderEmpty();
                }
            })
            .catch(function (err) {
                console.error('Load coupons error:', err);
                renderEmpty();
            });
    }

    /**
     * 渲染优惠券列表
     */
    function renderCoupons(coupons) {
        var body = $modal.querySelector('.qls-coupon-picker-body');
        var html = '<div class="qls-coupon-picker-list">';

        coupons.forEach(function (coupon) {
            // 使用后端返回的可用状态，而不是前端判断
            var isDisabled = coupon.is_valid === false;
            var valueHtml = '';

            if (coupon.discount_type === 'fixed') {
                valueHtml = '<span class="unit">' + config.currency + '</span>' +
                    '<span class="amount">' + parseInt(coupon.discount_value) + '</span>';
            } else {
                valueHtml = '<span class="amount">' + coupon.discount_value + '</span>' +
                    '<span class="unit">%</span>';
            }

            var conditionText = coupon.min_amount > 0
                ? formatText('coupon_min_amount', { currency: config.currency, amount: coupon.min_amount })
                : text('coupon_no_threshold');

            // 如果不可用，显示不可用原因
            var reasonHtml = '';
            if (isDisabled && coupon.invalid_reason) {
                reasonHtml = '<div class="coupon-reason">' + escapeHtml(coupon.invalid_reason) + '</div>';
            }

            html += '<div class="qls-coupon-picker-item' + (isDisabled ? ' disabled' : '') + '" ' +
                'data-claim-id="' + coupon.claim_id + '" ' +
                'data-coupon-id="' + (coupon.coupon_id || coupon.id) + '" ' +
                'data-discount-type="' + coupon.discount_type + '" ' +
                'data-discount-value="' + coupon.discount_value + '" ' +
                'data-max-discount="' + (coupon.max_discount || 0) + '" ' +
                'data-name="' + escapeHtml(coupon.name) + '">' +
                '<div class="coupon-value">' + valueHtml + '</div>' +
                '<div class="coupon-info">' +
                '<div class="name">' + escapeHtml(coupon.name) + '</div>' +
                '<div class="condition">' + conditionText + '</div>' +
                reasonHtml +
                '</div>' +
                '<div class="coupon-check">✓</div>' +
                '</div>';
        });

        html += '</div>';
        body.innerHTML = html;

        // 绑定点击事件（只绑定可用的优惠券）
        var items = body.querySelectorAll('.qls-coupon-picker-item:not(.disabled)');
        for (var i = 0; i < items.length; i++) {
            items[i].addEventListener('click', function () {
                var allItems = body.querySelectorAll('.qls-coupon-picker-item');
                for (var j = 0; j < allItems.length; j++) {
                    allItems[j].classList.remove('selected');
                }
                this.classList.add('selected');

                state.selectedCoupon = {
                    claimId: this.dataset.claimId,
                    couponId: this.dataset.couponId,
                    name: this.dataset.name,
                    discountType: this.dataset.discountType,
                    discountValue: parseFloat(this.dataset.discountValue),
                    maxDiscount: parseFloat(this.dataset.maxDiscount) || 0
                };
            });
        }
    }

    /**
     * 渲染空状态
     */
    function renderEmpty() {
        var body = $modal.querySelector('.qls-coupon-picker-body');
        body.innerHTML = '<div class="qls-coupon-picker-empty">' +
            '<div style="font-size:48px;margin-bottom:15px;">🎟️</div>' +
            '<p>' + text('no_coupons') + '</p>' +
            '</div>';
    }

    /**
     * 确认选择
     */
    function confirmSelection() {
        if (!state.selectedCoupon) {
            hide();
            return;
        }

        var discount = calculateDiscount(state.selectedCoupon, state.currentAmount);

        if (state.onSelectCallback) {
            state.onSelectCallback(state.selectedCoupon, discount);
        }

        hide();
    }

    /**
     * 计算折扣金额
     */
    function calculateDiscount(coupon, amount) {
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

    /**
     * HTML转义
     */
    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    /**
     * 格式化价格
     */
    function formatPrice(amount) {
        return config.currency + parseFloat(amount).toFixed(2);
    }

    // 公共API
    return {
        showPicker: showPicker,
        hide: hide,
        calculateDiscount: calculateDiscount,
        formatPrice: formatPrice
    };

})();

/**
 * 页面加载后自动绑定优惠券触发器
 */
document.addEventListener('DOMContentLoaded', function () {
    // 绑定所有 .qls-coupon-trigger 元素
    var triggers = document.querySelectorAll('.qls-coupon-trigger');

    for (var i = 0; i < triggers.length; i++) {
        (function (trigger) {
            trigger.addEventListener('click', function () {
                var section = this.closest('.qls-coupon-section');
                if (!section) return;

                var orderType = section.dataset.orderType || 'all';

                // 尝试获取金额
                var amount = 0;
                var amountEl = document.querySelector('[data-order-amount]');
                if (amountEl) {
                    amount = parseFloat(amountEl.dataset.orderAmount) || 0;
                }

                // 显示优惠券选择器
                QLSCoupon.showPicker({
                    orderType: orderType,
                    amount: amount,
                    onSelect: function (coupon, discount) {
                        // 更新UI
                        var applied = section.querySelector('.qls-coupon-applied');
                        var triggerEl = section.querySelector('.qls-coupon-trigger');
                        var claimIdInput = section.querySelector('input[type="hidden"][id$="coupon-claim-id"]');
                        var discountInput = section.querySelector('input[type="hidden"][id$="coupon-discount-amount"]');
                        var nameEl = section.querySelector('[id$="coupon-name"]');
                        var discountEl = section.querySelector('[id$="coupon-discount"]');

                        if (triggerEl) triggerEl.style.display = 'none';
                        if (applied) applied.style.display = 'flex';
                        if (nameEl) nameEl.textContent = coupon.name;
                        if (discountEl) discountEl.textContent = '-' + QLSCoupon.formatPrice(discount);
                        if (claimIdInput) claimIdInput.value = coupon.claimId;
                        if (discountInput) discountInput.value = discount;
                    }
                });
            });
        })(triggers[i]);
    }

    // 绑定移除优惠券按钮
    var removeBtns = document.querySelectorAll('.qls-remove-coupon');
    for (var j = 0; j < removeBtns.length; j++) {
        (function (btn) {
            btn.addEventListener('click', function () {
                var section = this.closest('.qls-coupon-section');
                if (!section) return;

                var applied = section.querySelector('.qls-coupon-applied');
                var triggerEl = section.querySelector('.qls-coupon-trigger');
                var claimIdInput = section.querySelector('input[type="hidden"][id$="coupon-claim-id"]');
                var discountInput = section.querySelector('input[type="hidden"][id$="coupon-discount-amount"]');

                if (triggerEl) triggerEl.style.display = 'flex';
                if (applied) applied.style.display = 'none';
                if (claimIdInput) claimIdInput.value = '';
                if (discountInput) discountInput.value = '0';
            });
        })(removeBtns[j]);
    }
});
