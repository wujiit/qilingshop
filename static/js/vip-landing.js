jQuery(document).ready(function ($) {
    var modal = $('#qls-vip-modal');
    var currentLevelId = null;
    var currentPrice = 0;

    // Access localized data
    var config = window.qlsVipLanding || {};
    var i18n = config.i18n || {};

    function text(key, fallback) {
        return i18n[key] || fallback || key;
    }

    // Reset coupon state
    function resetCouponState() {
        window.vipLandingSelectedCoupon = null;
        $('#qls-vip-coupon-claim-id').val('');
        $('#qls-vip-coupon-discount-amount').val('0');
        $('#qls-vip-coupon-trigger').show();
        $('#qls-vip-coupon-applied').hide();
    }

    // 1. Open Modal
    $('.qls-buy-vip-trigger').on('click', function (e) {
        e.preventDefault();

        if (!config.isLoggedIn) {
            window.location.href = config.loginUrl;
            return;
        }

        var btn = $(this);
        currentLevelId = btn.data('level');
        currentPrice = parseFloat(btn.data('price-rmb')) || 0;
        var originPrice = parseFloat(btn.data('origin-rmb')) || 0;
        var upgradeLabel = btn.data('upgrade-label') || '';
        var levelName = btn.data('name');
        var ruleExpires = btn.data('rule-expires') || '-';
        var ruleCalc = btn.data('rule-calc') || '';
        var ruleStack = btn.data('rule-stack') || '';

        // Reset coupon when opening modal
        resetCouponState();

        if (currentPrice >= 0) {
            // Update Modal Content
            $('#qls-vip-name').text(levelName);
            $('#qls-vip-price').text(currentPrice > 0 ? (config.currencySymbol + currentPrice) : text('free', '免费'));

            // Calculate Points Price
            var pointsPrice = Math.ceil(currentPrice * config.pointsRatio);
            var orText = text('or', 'or');
            $('#qls-vip-points-price').text(currentPrice > 0 ? (orText + ' ' + pointsPrice + ' ' + config.pointsName) : text('noPayment', '无需支付'));

            var priceNote = $('#qls-vip-price-note');
            if (priceNote.length) {
                if (upgradeLabel) {
                    var noteText = upgradeLabel;
                    if (originPrice > currentPrice && originPrice > 0) {
                        noteText = text('origin', '原价') + ' ' + config.currencySymbol + originPrice + ' · ' + upgradeLabel;
                    }
                    priceNote.text(noteText).show();
                } else {
                    priceNote.hide();
                }
            }

            var ruleExpiresEl = $('#qls-vip-rule-expires');
            var ruleCalcEl = $('#qls-vip-rule-calc');
            var ruleStackEl = $('#qls-vip-rule-stack');
            if (ruleExpiresEl.length) {
                ruleExpiresEl.text(ruleExpires || '-');
            }
            if (ruleCalcEl.length) {
                ruleCalcEl.text(ruleCalc);
            }
            if (ruleStackEl.length) {
                ruleStackEl.text(ruleStack);
            }

            // Check Points 余额
            var pointsOption = $('#qls-points-option');
            var balanceInput = pointsOption.find('input');
            var balanceText = pointsOption.find('.qls-payment-balance');

            if (config.user余额 < pointsPrice) {
                pointsOption.css('opacity', '0.6');
                balanceInput.prop('disabled', true);
                balanceText.html('<span style="color:red;font-size:12px;">' + i18n.balanceInsufficient.replace('%s', pointsPrice).replace('%s', config.pointsName) + '</span>');

                // Select first available option
                var firstAvailable = $('input[name="vip_payment_method"]:not(:disabled)').first();
                if (firstAvailable.length) {
                    firstAvailable.prop('checked', true);
                } else {
                    // No payment method available
                    balanceInput.prop('checked', false);
                }
            } else {
                pointsOption.css('opacity', '1');
                balanceInput.prop('disabled', false);
                var balanceLabel = text('balance', '余额');
                balanceText.html('(' + balanceLabel + ': ' + config.user余额 + ')');
                // balanceText.empty(); // Or clear it if cleaner

                // Select points by default if available
                balanceInput.prop('checked', true);
            }

            modal.fadeIn(300).css('display', 'flex');
        } else {
            alert(i18n.contactAdmin);
        }
    });

    // 2. Close Modal
    $('.qls-modal-close, .qls-modal-overlay').on('click', function () {
        modal.fadeOut(300);
    });

    // 3. 常见问题折叠交互
    function qlsVipLandingInitFaqToggle() {
        var faqItems = $('.qls-vip-faq-item');
        if (!faqItems.length) {
            return;
        }

        faqItems.removeClass('qls-vip-faq-open');
        faqItems.find('.qls-vip-faq-a').hide();

        faqItems.find('.qls-vip-faq-q').off('click.qlsVipLandingFaq').on('click.qlsVipLandingFaq', function () {
            var item = $(this).closest('.qls-vip-faq-item');
            var answer = item.find('.qls-vip-faq-a');

            if (item.hasClass('qls-vip-faq-open')) {
                item.removeClass('qls-vip-faq-open');
                answer.stop(true, true).slideUp(200);
            } else {
                item.addClass('qls-vip-faq-open');
                answer.stop(true, true).slideDown(200);
            }
        });
    }

    qlsVipLandingInitFaqToggle();

    // 4. Coupon Trigger
    var couponTriggerEl = document.getElementById('qls-vip-coupon-trigger');
    if (couponTriggerEl) {
        couponTriggerEl.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopImmediatePropagation();

            if (typeof QLSCoupon !== 'undefined') {
                QLSCoupon.showPicker({
                    orderType: 'vip',
                    amount: currentPrice,
                    onSelect: function (coupon, discount) {
                        window.vipLandingSelectedCoupon = coupon;
                        $('#qls-vip-coupon-claim-id').val(coupon.claimId);
                        $('#qls-vip-coupon-name').text(coupon.name);
                        $('#qls-vip-coupon-discount').text('-¥' + discount.toFixed(2));
                        $('#qls-vip-coupon-discount-amount').val(discount);
                        $('#qls-vip-coupon-trigger').hide();
                        $('#qls-vip-coupon-applied').show();
                    }
                });
            } else {
                console.error('QLSCoupon not loaded');
            }
        }, true);
    }

    // 5. 移除优惠券
    $('#qls-vip-remove-coupon').on('click', function () {
        resetCouponState();
    });

    // 6. Confirm Payment
    $('#qls-confirm-vip-buy').on('click', function () {
        var btn = $(this);
        var gateway = $('input[name="vip_payment_method"]:checked').val();
        var couponClaimId = $('#qls-vip-coupon-claim-id').val() || '';
        var processingText = text('processing', '处理中...');
        var confirmText = text('confirm', '确认支付');

        if (!gateway) {
            alert(i18n.selectPayment);
            return;
        }

        btn.addClass('is-loading').prop('disabled', true).text(processingText);

        var action = (gateway === 'points') ? 'qilingshop_buy_vip' : 'qilingshop_buy_vip_direct';

        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: action,
                level_id: currentLevelId,
                gateway: gateway,
                coupon_claim_id: couponClaimId,
                nonce: config.nonce
            },
            success: function (response) {
                if (response.success) {
                    if (gateway === 'points') {
                        alert(response.data.message || i18n.success);
                        if (config.vipCenterUrl) {
                            window.location.href = config.vipCenterUrl;
                        } else {
                            location.reload();
                        }
                    } else if (response.data && response.data.pay_url) {
                        window.location.href = response.data.pay_url;
                    } else {
                        alert(response.data.message || i18n.success);
                        if (config.vipCenterUrl) {
                            window.location.href = config.vipCenterUrl;
                        } else {
                            location.reload();
                        }
                    }
                } else {
                    alert(response.message || i18n.error);
                    btn.removeClass('is-loading').prop('disabled', false).text(confirmText);
                }
            },
            error: function () {
                alert(i18n.error);
                btn.removeClass('is-loading').prop('disabled', false).text(confirmText);
            }
        });
    });

});
