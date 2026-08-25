/**
 * 电商前台 JavaScript
 */
(function ($) {
    'use strict';

    function text(key, fallback) {
        var i18n = window.qlsShop && window.qlsShop.i18n ? window.qlsShop.i18n : {};
        return i18n[key] || fallback || key;
    }

    function formatText(key, replacements, fallback) {
        var value = text(key, fallback);
        Object.keys(replacements || {}).forEach(function (placeholder) {
            value = value.replace(new RegExp('\\{' + placeholder + '\\}', 'g'), replacements[placeholder]);
        });
        return value;
    }

    function isLoginRequiredMessage(message) {
        var loginRequired = text('login_required', '请先登录');
        return !!(message && loginRequired && String(message).indexOf(loginRequired) !== -1);
    }

    var qlsShopFrontend = {
        init: function () {
            this.bindEvents();
            this.updateCartCount();
            this.initGroupStock();
            this.initAssistCountdown();
        },

        bindEvents: function () {
            var self = this;

            // 商品详情页 - 缩略图切换
            $(document).on('click', '.qls-thumb', function () {
                var imgUrl = $(this).data('image');
                var imgType = $(this).data('type') || 'image';

                if (imgType === 'video') {
                    // 处理视频
                    $('.qls-main-image').html('<video src="' + imgUrl + '" controls autoplay></video>');
                } else {
                    $('.qls-main-image').html('<img src="' + imgUrl + '" alt="" id="qls-main-img">');
                }

                $('.qls-thumb').removeClass('active');
                $(this).addClass('active');
            });

            // 商品详情页 - 规格选择
            $(document).on('click', '.qls-attr-value:not(.disabled)', function () {
                var $group = $(this).closest('.qls-attr-group');
                $group.find('.qls-attr-value').removeClass('active');
                $(this).addClass('active');

                var value = $(this).data('value');
                var image = $(this).data('image');

                // 如果规格值有图片，切换主图
                if (image) {
                    $('.qls-main-image').html('<img src="' + image + '" alt="" id="qls-main-img">');
                }

                // 更新版本值选择器
                self.updateVersionSelector($group, value);

                self.updateSkuInfo();
            });

            // 商品详情页 - 版本值选择
            $(document).on('click', '.qls-version-value', function () {
                $(this).closest('.qls-version-selector').find('.qls-version-value').removeClass('active');
                $(this).addClass('active');
                self.updateSkuInfo();
            });

            // 数量增减
            $(document).on('click', '.qls-qty-minus', function () {
                var $input = $(this).siblings('input');
                var val = parseInt($input.val()) || 1;
                if (val > 1) {
                    $input.val(val - 1).trigger('change');
                }
            });

            $(document).on('click', '.qls-qty-plus', function () {
                var $input = $(this).siblings('input');
                var val = parseInt($input.val()) || 1;
                var max = parseInt($input.attr('max')) || 9999;
                if (val < max) {
                    $input.val(val + 1).trigger('change');
                }
            });

            // 加入购物车
            $('#qls-add-cart').on('click', function () {
                self.addToCart();
            });

            // 立即购买
            $('#qls-buy-now').on('click', function () {
                self.buyNow();
            });

            // 发起拼团
            $(document).on('click', '.qls-start-group-btn', function () {
                var $btn = $(this);
                var productId = $btn.data('product-id') || $('#qls-product-id').val();
                var skuId = $('#qls-sku-id').val();
                var quantity = parseInt($('#qls-quantity').val()) || 1;

                if (!skuId || skuId === '0') {
                    self.showToast(text('select_product_spec', '请选择商品规格'), 'error');
                    return;
                }

                self.createGroupOrder(productId, skuId, quantity, $btn);
            });

            // 参与拼团
            $(document).on('click', '.qls-join-group-btn', function () {
                if ($('#qls-sku-id').length === 0) {
                    return;
                }

                var $btn = $(this);
                var groupId = $btn.data('group-id');
                var skuId = $('#qls-sku-id').val();
                var quantity = parseInt($('#qls-quantity').val()) || 1;

                if (!groupId) {
                    self.showToast(text('error', '操作失败'), 'error');
                    return;
                }

                if (!skuId || skuId === '0') {
                    self.showToast(text('select_product_spec', '请选择商品规格'), 'error');
                    return;
                }

                self.joinGroupOrder(groupId, skuId, quantity, $btn);
            });

            // 统一登录触发：优先主题弹窗，失败再回退默认登录页
            $(document).on('click', '.user-login, .qls-login-trigger', function (e) {
                e.preventDefault();
                var fallbackUrl = $(this).data('login-url') || $(this).attr('href') || '/wp-login.php';
                self.triggerLogin(fallbackUrl);
            });

            // 购物车 - 数量更新
            $(document).on('change', '.qls-cart-qty', function () {
                var itemId = $(this).data('item');
                var quantity = parseInt($(this).val()) || 1;
                self.updateCart(itemId, quantity);
            });

            // 购物车 - 移除商品（使用自定义确认框）
            $(document).on('click', '.qls-remove-item', function (e) {
                e.preventDefault();
                var itemId = $(this).data('item');
                self.showConfirm({
                    title: text('confirm_delete_title', '确认删除'),
                    message: text('confirm_remove_product', '确定删除该商品？'),
                    onConfirm: function () {
                        self.removeFromCart(itemId);
                    }
                });
            });

            // 结账页 - 移除商品（使用自定义确认框）
            $(document).on('click', '.qls-remove-checkout-item', function (e) {
                e.preventDefault();
                var itemId = $(this).data('item');
                var $item = $(this).closest('.qls-order-item');

                // 使用自定义确认框
                self.showConfirm({
                    title: text('confirm_delete_title', '确认删除'),
                    message: text('confirm_remove_checkout', '确定从购物车移除此商品？'),
                    onConfirm: function () {
                        $.post(qlsShop.ajaxUrl, {
                            action: 'qls_remove_from_cart',
                            nonce: qlsShop.nonce,
                            item_id: itemId
                        }, function (res) {
                            if (res.success) {
                                $item.fadeOut(300, function () {
                                    $(this).remove();
                                    if ($('.qls-order-item').length === 0) {
                                        location.reload();
                                    } else {
                                        location.reload();
                                    }
                                });
                            } else {
                                self.showToast(res.data.message || text('delete_failed', '删除失败'), 'error');
                            }
                        });
                    }
                });
            });

            // 结账 - 地址选择
            $(document).on('change', 'input[name="address_id"]', function () {
                $('.qls-address-item').removeClass('selected');
                $(this).closest('.qls-address-item').addClass('selected');
            });

            // 结账 - 新地址
            $('#qls-new-address-btn').on('click', function (e) {
                e.preventDefault();
                $('#qls-address-list').slideUp();
                $('#qls-address-form').slideDown();
                $('input[name="address_id"]').prop('checked', false);
            });

            // 结账 - 积分全部使用
            $('#qls-use-all-points').on('click', function () {
                var max = parseInt($('#points_used').attr('max')) || 0;
                $('#points_used').val(max);
                self.updateCheckoutTotal();
            });

            // 结账 - 积分变化
            $('#points_used').on('change', function () {
                self.updateCheckoutTotal();
            });

            // 结账 - 显示优惠券弹窗
            $('#qls-show-coupons').on('click', function () {
                var $modal = $('#qls-coupon-modal');
                var $list = $('#qls-coupon-list');

                $modal.show();
                $list.html('<div class="loading">' + text('loading', '加载中...') + '</div>');

                // 获取当前订单金额
                var amount = parseFloat($('#qls-product-total').val()) || 0;

                // 使用 qlsCoupon 或 qlsShop 获取配置
                var ajaxUrl = (typeof qlsCoupon !== 'undefined' && qlsCoupon.ajaxUrl) ? qlsCoupon.ajaxUrl : qlsShop.ajaxUrl;
                var nonce = (typeof qlsCoupon !== 'undefined' && qlsCoupon.nonce) ? qlsCoupon.nonce : '';

                $.post(ajaxUrl, {
                    action: 'qls_get_available_coupons',
                    order_type: 'shop',
                    amount: amount,
                    nonce: nonce,
                    _t: Date.now() // 防止缓存
                }, function (res) {
                    if (res.success && res.data.coupons && res.data.coupons.length > 0) {
                        var html = '<div class="qls-coupon-picker-list">';
                        res.data.coupons.forEach(function (coupon) {
                            // 使用后端返回的 is_valid 而不是只检查 min_amount
                            var isDisabled = coupon.is_valid === false;
                            var valueHtml = '';

                            if (coupon.discount_type === 'fixed') {
                                valueHtml = '<span class="unit">¥</span><span class="amount">' + parseInt(coupon.discount_value) + '</span>';
                            } else {
                                valueHtml = '<span class="amount">' + coupon.discount_value + '</span><span class="unit">%</span>';
                            }

                            var conditionText = coupon.min_amount > 0 ? formatText('coupon_min_amount', { currency: '¥', amount: coupon.min_amount }, '满{currency}{amount}可用') : text('coupon_no_threshold', '无门槛');
                            // 显示不可用原因
                            var reasonHtml = isDisabled && coupon.invalid_reason ? '<div class="coupon-reason" style="color:#f44336;font-size:11px;margin-top:4px;">⚠ ' + coupon.invalid_reason + '</div>' : '';

                            html += '<div class="qls-coupon-picker-item' + (isDisabled ? ' disabled' : '') + '" ' +
                                (isDisabled ? '' : 'data-claim-id="' + coupon.claim_id + '" data-name="' + coupon.name + '" data-discount-type="' + coupon.discount_type + '" data-discount-value="' + coupon.discount_value + '" data-max-discount="' + (coupon.max_discount || 0) + '"') + '>' +
                                '<div class="coupon-value">' + valueHtml + '</div>' +
                                '<div class="coupon-info">' +
                                '<div class="name">' + coupon.name + '</div>' +
                                '<div class="condition">' + conditionText + '</div>' +
                                reasonHtml +
                                '</div>' +
                                (isDisabled ? '' : '<div class="coupon-check">✓</div>') +
                                '</div>';
                        });
                        html += '</div>';
                        $list.html(html);

                        // 绑定选择事件 - 只绑定可用的优惠券
                        $list.find('.qls-coupon-picker-item:not(.disabled)').on('click', function () {
                            var claimId = $(this).data('claim-id');
                            var name = $(this).data('name');
                            var discountType = $(this).data('discount-type');
                            var discountValue = parseFloat($(this).data('discount-value'));
                            var maxDiscount = parseFloat($(this).data('max-discount')) || 0;

                            // 计算折扣金额
                            var discount = 0;
                            if (discountType === 'fixed') {
                                discount = discountValue;
                            } else {
                                discount = amount * (discountValue / 100);
                                if (maxDiscount > 0 && discount > maxDiscount) {
                                    discount = maxDiscount;
                                }
                            }
                            discount = Math.min(discount, amount);

                            // 更新界面
                            $('#qls-coupon-claim-id').val(claimId);
                            $('#qls-applied-coupon-name').text(name);
                            $('#qls-applied-coupon-discount').text('-¥' + discount.toFixed(2));
                            $('#qls-coupon-applied').show();
                            $('#qls-available-coupons').hide();

                            $modal.hide();
                            self.updateCheckoutTotal();
                        });
                    } else {
                        $list.html('<div style="text-align:center;padding:40px;color:#999;">' + text('no_coupons', '暂无可用优惠券') + '</div>');
                    }
                }).fail(function () {
                    $list.html('<div style="text-align:center;padding:40px;color:#999;">' + text('load_failed', '加载失败') + '</div>');
                });
            });

            // 结账 - 关闭优惠券弹窗
            $('#qls-close-coupon-modal').on('click', function () {
                $('#qls-coupon-modal').hide();
            });

            // 结账 - 关闭弹窗（点击遮罩层）
            $('#qls-coupon-modal').on('click', function (e) {
                if ($(e.target).hasClass('qls-coupon-modal')) {
                    $(this).hide();
                }
            });

            // 结账 - 移除已选优惠券
            $('#qls-remove-coupon').on('click', function () {
                $('#qls-coupon-claim-id').val('');
                $('#qls-coupon-applied').hide();
                $('#qls-available-coupons').show();
                self.updateCheckoutTotal();
            });

            // 结账 - 输入优惠码验证
            $('#qls-apply-coupon').on('click', function () {
                var code = $('#qls-coupon-code').val().trim();
                if (!code) {
                    alert(text('enter_coupon_code', '请输入优惠券码'));
                    return;
                }

                var $btn = $(this);
                var originalText = $btn.text();
                $btn.text(text('validating', '校验中...')).prop('disabled', true);

                var ajaxUrl = (typeof qlsCoupon !== 'undefined' && qlsCoupon.ajaxUrl) ? qlsCoupon.ajaxUrl : qlsShop.ajaxUrl;
                var nonce = (typeof qlsCoupon !== 'undefined' && qlsCoupon.nonce) ? qlsCoupon.nonce : '';
                var amount = parseFloat($('#qls-product-total').val()) || 0;

                $.post(ajaxUrl, {
                    action: 'qls_validate_coupon',
                    code: code,
                    order_type: 'shop',
                    amount: amount,
                    nonce: nonce
                }, function (res) {
                    $btn.text(originalText).prop('disabled', false);

                    if (res.success) {
                        var coupon = res.data.coupon;
                        var discount = parseFloat(res.data.discount) || 0;

                        $('#qls-coupon-claim-id').val(coupon.claim_id);
                        $('#qls-applied-coupon-name').text(coupon.name);
                        $('#qls-applied-coupon-discount').text('-¥' + discount.toFixed(2));
                        $('#qls-coupon-applied').show();
                        $('#qls-available-coupons').hide();
                        $('#qls-coupon-code').val('');

                        self.updateCheckoutTotal();
                    } else {
                        alert(res.data.message || text('coupon_invalid', '优惠券码无效'));
                    }
                }).fail(function () {
                    $btn.text(originalText).prop('disabled', false);
                    alert(text('validation_failed', '校验失败，请稍后重试。'));
                });
            });

            // 结账 - 提交订单
            $('#qls-checkout-form').on('submit', function (e) {
                e.preventDefault();
                self.submitOrder();
            });



            // 计算运费
            var isGroupOrder = $('#qls-group-order').val() === '1';
            if ($('#qls-shipping-fee').length && !isGroupOrder) {
                this.calculateShipping();
            }

            // --- 个人中心地址管理 ---

            // 新增地址
            $('#qls-add-address-center').on('click', function () {
                $('#qls-center-address-form')[0].reset();
                $('#qls-center-address-form input[name="address_id"]').val('');
                $('#qls-address-modal').fadeIn();
            });

            // 编辑地址
            $('.edit-address').on('click', function () {
                var $card = $(this).closest('.qls-address-card');
                var data = $card.data('json');

                var $form = $('#qls-center-address-form');
                $form.find('input[name="address_id"]').val(data.id);
                $form.find('input[name="name"]').val(data.name);
                $form.find('input[name="phone"]').val(data.phone);
                $form.find('input[name="province"]').val(data.province);
                $form.find('input[name="city"]').val(data.city);
                $form.find('input[name="district"]').val(data.district);
                $form.find('textarea[name="address"]').val(data.address);
                $form.find('input[name="is_default"]').prop('checked', data.is_default == 1);

                $('#qls-address-modal').fadeIn();
            });

            // 删除地址 (Trigger Modal)
            $('.delete-address').on('click', function () {
                var $card = $(this).closest('.qls-address-card');
                var id = $card.data('id');

                // Store ID on the confirm button
                $('#qls-confirm-delete-btn').data('id', id);

                // Show Modal
                $('#qls-confirm-modal').fadeIn();
            });

            // 确认删除 (Execute)
            $('#qls-confirm-delete-btn').on('click', function () {
                var $btn = $(this);
                var id = $btn.data('id');
                if (!id) return;

                $btn.text(text('deleting', '删除中...')).prop('disabled', true);

                $.post(qlsShop.ajaxUrl, {
                    action: 'qls_delete_address',
                    address_id: id,
                    nonce: qlsShop.nonce
                }, function (res) {
                    if (res.success) {
                        location.reload();
                    } else {
                        alert(res.data.message);
                        $btn.text(text('confirm_delete_button', '删除')).prop('disabled', false);
                        $('#qls-confirm-modal').fadeOut();
                    }
                });
            });

            // 保存地址
            $('#qls-center-address-form').on('submit', function (e) {
                e.preventDefault();
                var data = $(this).serialize();

                $.post(qlsShop.ajaxUrl, data + '&action=qls_save_address&nonce=' + qlsShop.nonce, function (res) {
                    if (res.success) {
                        location.reload();
                    } else {
                        alert(res.data.message);
                    }
                });
            });

            // --- 个人中心发票信息管理 ---

            function getInvoiceTitleCardData($card) {
                var data = $card.data('json') || {};
                if (typeof data === 'string') {
                    try {
                        data = JSON.parse(data);
                    } catch (e) {
                        data = {};
                    }
                }
                return data || {};
            }

            function toggleInvoiceTitleCompanyFields() {
                var $form = $('#qls-center-invoice-title-form');
                var titleType = $form.find('input[name="title_type"]:checked').val();
                $form.find('.qls-invoice-title-company-field, .qls-invoice-title-company-fields').toggle(titleType === 'company');
            }

            function openInvoiceTitleModal(data) {
                data = data || {};
                var $form = $('#qls-center-invoice-title-form');
                if (!$form.length) {
                    return;
                }

                $form[0].reset();
                $form.find('input[name="title_id"]').val(data.id || '');
                $form.find('input[name="title_type"][value="' + (data.title_type || 'personal') + '"]').prop('checked', true);
                $form.find('input[name="title"]').val(data.title || '');
                $form.find('input[name="tax_no"]').val(data.tax_no || '');
                $form.find('input[name="email"]').val(data.email || '');
                $form.find('input[name="bank_name"]').val(data.bank_name || '');
                $form.find('input[name="bank_account"]').val(data.bank_account || '');
                $form.find('input[name="registered_address"]').val(data.registered_address || '');
                $form.find('input[name="registered_phone"]').val(data.registered_phone || '');
                $form.find('input[name="is_default"]').prop('checked', String(data.is_default || '') === '1' || data.is_default === 1 || data.is_default === true);
                $form.find('button[type="submit"]').prop('disabled', false).text(text('save_invoice_title', '保存发票信息'));
                toggleInvoiceTitleCompanyFields();
                $('#qls-invoice-title-modal').fadeIn();
            }

            $(document).on('change', '#qls-center-invoice-title-form input[name="title_type"]', toggleInvoiceTitleCompanyFields);

            $(document).on('click', '#qls-add-invoice-title, #qls-add-invoice-title-empty', function () {
                openInvoiceTitleModal({ title_type: 'personal', is_default: $('.qls-invoice-title-card').length ? 0 : 1 });
            });

            $(document).on('click', '.edit-invoice-title', function () {
                openInvoiceTitleModal(getInvoiceTitleCardData($(this).closest('.qls-invoice-title-card')));
            });

            $(document).on('click', '.set-default-invoice-title', function () {
                var id = $(this).closest('.qls-invoice-title-card').data('id');
                if (!id) {
                    return;
                }

                $.post(qlsShop.ajaxUrl, {
                    action: 'qls_set_default_invoice_title',
                    title_id: id,
                    nonce: qlsShop.nonce
                }, function (res) {
                    if (res.success) {
                        location.reload();
                    } else {
                        self.showToast((res.data && res.data.message) || text('error', '操作失败'), 'error');
                    }
                });
            });

            $(document).on('click', '.delete-invoice-title', function () {
                var id = $(this).closest('.qls-invoice-title-card').data('id');
                if (!id) {
                    return;
                }

                self.showConfirm({
                    title: text('confirm_delete_title', '确认删除'),
                    message: text('confirm_delete_invoice_title', '确定删除该发票信息吗？'),
                    confirmText: text('confirm_delete_button', '确认删除'),
                    onConfirm: function () {
                        $.post(qlsShop.ajaxUrl, {
                            action: 'qls_delete_invoice_title',
                            title_id: id,
                            nonce: qlsShop.nonce
                        }, function (res) {
                            if (res.success) {
                                location.reload();
                            } else {
                                self.showToast((res.data && res.data.message) || text('delete_failed', '删除失败'), 'error');
                            }
                        });
                    }
                });
            });

            $('#qls-center-invoice-title-form').on('submit', function (e) {
                e.preventDefault();
                var $form = $(this);
                var titleType = $form.find('input[name="title_type"]:checked').val();
                var title = $.trim($form.find('input[name="title"]').val());
                var taxNo = $.trim($form.find('input[name="tax_no"]').val());
                var $submit = $form.find('button[type="submit"]');

                if (!title) {
                    self.showToast(text('enter_invoice_title', '请填写发票抬头'), 'error');
                    return;
                }
                if (titleType === 'company' && !taxNo) {
                    self.showToast(text('enter_invoice_tax_no', '企业抬头请填写税号'), 'error');
                    return;
                }

                $submit.prop('disabled', true).text(text('saving', '保存中...'));
                $.post(qlsShop.ajaxUrl, $form.serialize() + '&action=qls_save_invoice_title&nonce=' + qlsShop.nonce, function (res) {
                    if (res.success) {
                        location.reload();
                    } else {
                        $submit.prop('disabled', false).text(text('save_invoice_title', '保存发票信息'));
                        self.showToast((res.data && res.data.message) || text('save_failed', '保存失败'), 'error');
                    }
                }).fail(function () {
                    $submit.prop('disabled', false).text(text('save_invoice_title', '保存发票信息'));
                    self.showToast(text('network_error', '网络错误，请重试'), 'error');
                });
            });

            // 关闭弹窗
            $('.qls-modal-close, .qls-modal-cancel').on('click', function () {
                $('.qls-modal').fadeOut();
            });

            // 发起助力
            $(document).on('click', '.qls-assist-create-btn', function () {
                var activityId = parseInt($(this).data('activity-id'), 10) || 0;
                if (!activityId) {
                    self.showToast(text('assist_param_error', '活动参数无效'), 'error');
                    return;
                }
                self.assistCreateCampaign(activityId, $(this));
            });

            // 助力按钮
            $(document).on('click', '.qls-assist-help-btn', function () {
                var campaignId = parseInt($(this).data('campaign-id'), 10) || 0;
                if (!campaignId) {
                    self.showToast(text('assist_param_error', '活动参数无效'), 'error');
                    return;
                }
                self.assistHelpCampaign(campaignId, $(this));
            });

            // 支付差额
            $(document).on('click', '.qls-assist-pay-btn', function () {
                var campaignId = parseInt($(this).data('campaign-id'), 10) || 0;
                if (!campaignId) {
                    self.showToast(text('assist_param_error', '活动参数无效'), 'error');
                    return;
                }
                self.assistCreateOrder(campaignId, $(this));
            });

            // 复制分享链接
            $(document).on('click', '.qls-assist-copy-link', function () {
                var $input = $('.qls-assist-share-input').first();
                if (!$input.length) {
                    return;
                }
                var text = $input.val();
                if (!text) {
                    return;
                }

                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(function () {
                        self.showToast(text('share_link_copied', '分享链接已复制'), 'success');
                    }).catch(function () {
                        self.showToast(text('copy_failed_manual', '复制失败，请手动复制。'), 'error');
                    });
                    return;
                }

                $input[0].select();
                try {
                    document.execCommand('copy');
                    self.showToast(text('share_link_copied', '分享链接已复制'), 'success');
                } catch (e) {
                    self.showToast(text('copy_failed_manual', '复制失败，请手动复制。'), 'error');
                }
            });
        },

        // 更新购物车数量
        updateCartCount: function () {
            $.post(qlsShop.ajaxUrl, {
                action: 'qls_get_cart_count',
                nonce: qlsShop.nonce
            }, function (res) {
                if (res.success) {
                    $('.qls-cart-count').text(res.data.count);
                }
            });
        },

        // 更新版本选择器（根据选中的规格值）
        updateVersionSelector: function ($group, selectedValue) {
            var $versionSelector = $group.find('.qls-version-selector');
            if (!$versionSelector.length) return;

            // 获取版本数据
            var $versionsData = $group.find('.qls-versions-data');
            if (!$versionsData.length) return;

            try {
                var versionsMap = JSON.parse($versionsData.text());
                var versions = versionsMap[selectedValue] || [];

                if (versions.length > 0) {
                    var html = '';
                    versions.forEach(function (ver, i) {
                        html += '<span class="qls-version-value' + (i === 0 ? ' active' : '') + '" data-version="' + ver + '">' + ver + '</span>';
                    });
                    $versionSelector.find('.qls-version-values').html(html);
                    $versionSelector.show();
                } else {
                    $versionSelector.hide();
                }
            } catch (e) {
                console.error('Failed to parse version data', e);
            }
        },

        formatPrice: function (price) {
            var value = parseFloat(price);
            if (!isFinite(value)) {
                value = 0;
            }
            return value.toFixed(2);
        },

        getProductSkuData: function (productId) {
            if (!this.productSkuDataParsed) {
                this.productSkuDataParsed = true;
                this.productSkuData = null;

                var dataEl = document.getElementById('qls-product-sku-data');
                if (dataEl && dataEl.textContent) {
                    try {
                        this.productSkuData = JSON.parse(dataEl.textContent);
                    } catch (e) {
                        console.error('Failed to parse SKU data', e);
                    }
                }
            }

            if (!this.productSkuData || String(this.productSkuData.product_id) !== String(productId)) {
                return null;
            }

            return Array.isArray(this.productSkuData.skus) ? this.productSkuData.skus : null;
        },

        getSelectedSkuAttrValues: function () {
            var attrValues = {};

            $('.qls-attr-group').each(function () {
                var $group = $(this);
                var attrName = $group.data('attr');
                var attrValue = $group.find('.qls-attr-value.active').data('value');

                var $versionSelector = $group.find('.qls-version-selector');
                if ($versionSelector.is(':visible')) {
                    var version = $versionSelector.find('.qls-version-value.active').data('version');
                    if (typeof version !== 'undefined' && version !== null && version !== '') {
                        attrValue = attrValue + ' ' + version;
                    }
                }

                if (attrName && typeof attrValue !== 'undefined' && attrValue !== null && attrValue !== '') {
                    attrValues[attrName] = attrValue;
                }
            });

            return attrValues;
        },

        countObjectKeys: function (obj) {
            return Object.keys(obj || {}).length;
        },

        skuMatchesSelection: function (skuAttrs, attrValues) {
            skuAttrs = skuAttrs || {};
            var keys = Object.keys(attrValues || {});

            for (var i = 0; i < keys.length; i++) {
                var key = keys[i];
                if (!Object.prototype.hasOwnProperty.call(skuAttrs, key) || String(skuAttrs[key]) !== String(attrValues[key])) {
                    return false;
                }
            }

            return true;
        },

        buildLocalPriceRange: function (matches) {
            if (!matches || !matches.length) {
                return null;
            }

            var minPrice = Number.POSITIVE_INFINITY;
            var maxPrice = 0;
            var totalStock = 0;
            var hasSale = false;
            var hasVipPrice = false;

            matches.forEach(function (sku) {
                var price = parseFloat(sku.price || 0);
                var salePrice = parseFloat(sku.sale_price || 0);
                var effectivePrice = parseFloat(sku.effective_price);
                var actualPrice = isFinite(effectivePrice)
                    ? effectivePrice
                    : ((salePrice > 0 && salePrice < price) ? salePrice : price);

                minPrice = Math.min(minPrice, actualPrice);
                maxPrice = Math.max(maxPrice, actualPrice);
                totalStock += parseInt(sku.stock || 0, 10) || 0;

                if (salePrice > 0 && salePrice < price) {
                    hasSale = true;
                }

                if (sku.is_vip_price) {
                    hasVipPrice = true;
                }
            });

            if (!isFinite(minPrice)) {
                return null;
            }

            return {
                min_price: minPrice,
                max_price: maxPrice,
                is_range: minPrice !== maxPrice,
                has_sale: hasSale,
                has_vip_price: hasVipPrice,
                total_stock: totalStock
            };
        },

        getLocalSkuResponse: function (productId, attrValues) {
            var skus = this.getProductSkuData(productId);
            if (!skus || !skus.length) {
                return null;
            }

            var selectedCount = this.countObjectKeys(attrValues);
            var matchedSku = null;
            var partialMatches = [];

            for (var i = 0; i < skus.length; i++) {
                var sku = skus[i];
                var skuAttrs = sku.attr_values || {};

                if (selectedCount === 0) {
                    if (sku.is_default) {
                        matchedSku = sku;
                        break;
                    }
                    partialMatches.push(sku);
                    continue;
                }

                if (this.skuMatchesSelection(skuAttrs, attrValues)) {
                    if (this.countObjectKeys(skuAttrs) === selectedCount) {
                        matchedSku = sku;
                        break;
                    }
                    partialMatches.push(sku);
                }
            }

            if (matchedSku) {
                return { sku: matchedSku };
            }

            var priceRange = this.buildLocalPriceRange(partialMatches);
            return priceRange ? { price_range: priceRange } : null;
        },

        applySkuData: function (data, options) {
            options = options || {};
            var priceHidden = typeof qlsShop !== 'undefined' && qlsShop.priceLoginRequired && !qlsShop.isLoggedIn;
            var useNewUserSpecialPrice = !!options.useNewUserSpecialPrice;
            var newUserSpecialPrice = parseFloat(options.newUserSpecialPrice || 0);

            if (data.sku) {
                var sku = data.sku;
                $('#qls-sku-id').val(sku.id);

                var basePrice = parseFloat(sku.base_price || 0);
                if (basePrice <= 0) {
                    basePrice = parseFloat(sku.sale_price) > 0 ? parseFloat(sku.sale_price) : parseFloat(sku.price);
                }

                var hasEffectivePrice = Object.prototype.hasOwnProperty.call(sku, 'effective_price');
                var price = hasEffectivePrice ? parseFloat(sku.effective_price) : (useNewUserSpecialPrice ? newUserSpecialPrice : basePrice);
                $('#qls-current-price').text('¥' + this.formatPrice(price));

                if (hasEffectivePrice && price < basePrice) {
                    $('#qls-original-price').text('¥' + this.formatPrice(basePrice)).show();
                } else if (useNewUserSpecialPrice) {
                    $('#qls-original-price').text('¥' + this.formatPrice(basePrice)).show();
                } else if (parseFloat(sku.sale_price) > 0 && parseFloat(sku.price) > parseFloat(sku.sale_price)) {
                    $('#qls-original-price').text('¥' + this.formatPrice(sku.price)).show();
                } else {
                    $('#qls-original-price').hide();
                }

                if (price >= basePrice && !useNewUserSpecialPrice && parseFloat(sku.points_price) > 0) {
                    $('#qls-points-price').text(formatText('or_points_price', { points: sku.points_price, pointsName: qlsShop.pointsName || text('points_name', '积分') }, '或 {points} {pointsName}')).show();
                } else {
                    $('#qls-points-price').hide();
                }

                if (typeof qlsShop === 'undefined' || qlsShop.showStock !== false) {
                    $('#qls-stock').text(formatText('stock_pieces', { stock: sku.stock }, '库存 {stock} 件'));
                }
                $('#qls-quantity').attr('max', sku.stock);

                var $cartBtn = $('#qls-add-cart');
                var $buyBtn = $('#qls-buy-now');

                if (parseInt(sku.stock, 10) <= 0) {
                    $cartBtn.prop('disabled', true);
                    $buyBtn.prop('disabled', true);
                } else {
                    $cartBtn.prop('disabled', false);
                    $buyBtn.prop('disabled', false);
                }

                if (sku.image) {
                    $('.qls-main-image').html('<img src="' + sku.image + '" alt="" id="qls-main-img">');
                }
                if (priceHidden) {
                    $('#qls-current-price').text(text('login_price_required', '登录后查看价格'));
                    $('#qls-original-price').hide();
                    $('#qls-points-price').hide();
                }
                return;
            }

            if (data.price_range) {
                var range = data.price_range;
                $('#qls-sku-id').val('');

                if (range.is_range) {
                    $('#qls-current-price').text('¥' + this.formatPrice(range.min_price) + ' - ¥' + this.formatPrice(range.max_price));
                } else if (range.min_price > 0) {
                    $('#qls-current-price').text('¥' + this.formatPrice(range.min_price));
                } else if (useNewUserSpecialPrice) {
                    $('#qls-current-price').text('¥' + this.formatPrice(newUserSpecialPrice));
                } else {
                    $('#qls-current-price').text('¥' + this.formatPrice(range.min_price));
                }

                $('#qls-original-price').hide();
                $('#qls-points-price').hide();
                if (typeof qlsShop === 'undefined' || qlsShop.showStock !== false) {
                    $('#qls-stock').text(formatText('stock_pieces', { stock: range.total_stock }, '库存 {stock} 件'));
                }

                $('#qls-add-cart').prop('disabled', true);
                $('#qls-buy-now').prop('disabled', true);
                if (priceHidden) {
                    $('#qls-current-price').text(text('login_price_required', '登录后查看价格'));
                }
            }
        },

        // 更新SKU信息
        updateSkuInfo: function () {
            var self = this;
            var productId = $('#qls-product-id').val() || $('#qls-sku-selector').data('product-id');
            if (!productId) return;

            var attrValues = this.getSelectedSkuAttrValues();
            var newUserSpecialEnabled = $('#qls-new-user-special-enabled').val() === '1';
            var newUserSpecialEligible = $('#qls-new-user-special-eligible').val() === '1';
            var newUserSpecialPrice = parseFloat($('#qls-new-user-special-price').val() || '0');
            var useNewUserSpecialPrice = newUserSpecialEnabled && newUserSpecialEligible && newUserSpecialPrice > 0;
            var priceOptions = {
                useNewUserSpecialPrice: useNewUserSpecialPrice,
                newUserSpecialPrice: newUserSpecialPrice
            };

            var localSkuData = this.getLocalSkuResponse(productId, attrValues);
            if (localSkuData) {
                this.applySkuData(localSkuData, priceOptions);
                return;
            }

            $.post(qlsShop.ajaxUrl, {
                action: 'qls_get_sku',
                nonce: qlsShop.nonce,
                product_id: productId,
                attr_values: attrValues
            }, function (res) {
                if (res.success) {
                    self.applySkuData(res.data, priceOptions);
                }
            });
        },

        // 加入购物车
        addToCart: function () {
            var $btn = $('#qls-add-cart');
            var originalText = $btn.text();

            var productId = $('#qls-product-id').val() || $('#qls-sku-selector').data('product-id') || $('input[name="product_id"]').val();
            var skuId = $('#qls-sku-id').val();
            var quantity = parseInt($('#qls-quantity').val()) || 1;

            // 单规格商品不需要选择规格，直接使用默认SKU
            if (!skuId || skuId === '0') {
                alert(text('select_product_spec', '请选择商品规格'));
                return;
            }

            $btn.text(qlsShop.i18n.adding).prop('disabled', true);

            $.post(qlsShop.ajaxUrl, {
                action: 'qls_add_to_cart',
                nonce: qlsShop.nonce,
                product_id: productId,
                sku_id: skuId,
                quantity: quantity
            }, function (res) {
                if (res.success) {
                    $btn.text(qlsShop.i18n.added);
                    $('.qls-cart-count').text(res.data.cart_count);

                    setTimeout(function () {
                        $btn.text(originalText).prop('disabled', false);
                    }, 1500);
                } else {
                    alert(res.data.message || qlsShop.i18n.error);
                    $btn.text(originalText).prop('disabled', false);
                }
            }).fail(function () {
                alert(qlsShop.i18n.error);
                $btn.text(originalText).prop('disabled', false);
            });
        },

        // 立即购买
        buyNow: function () {
            var self = this;
            var productId = $('#qls-product-id').val() || $('#qls-sku-selector').data('product-id') || $('input[name="product_id"]').val();
            var skuId = $('#qls-sku-id').val();
            var quantity = parseInt($('#qls-quantity').val()) || 1;

            // 单规格商品不需要选择规格，直接使用默认SKU
            if (!skuId || skuId === '0') {
                alert(text('select_product_spec', '请选择商品规格'));
                return;
            }

            $.post(qlsShop.ajaxUrl, {
                action: 'qls_add_to_cart',
                nonce: qlsShop.nonce,
                product_id: productId,
                sku_id: skuId,
                quantity: quantity
            }, function (res) {
                if (res.success) {
                    window.location.href = qlsShop.checkoutUrl;
                } else {
                    alert(res.data.message || qlsShop.i18n.error);
                }
            });
        },

        // 更新购物车
        updateCart: function (itemId, quantity) {
            $.post(qlsShop.ajaxUrl, {
                action: 'qls_update_cart',
                nonce: qlsShop.nonce,
                item_id: itemId,
                quantity: quantity
            }, function (res) {
                if (res.success) {
                    var totals = res.data.totals;
                    $('#qls-cart-count').text(totals.total_quantity);
                    $('#qls-cart-total').text('¥' + totals.total_amount.toFixed(2));

                    // 更新小计 (需要根据新的价格重新计算)
                    location.reload();
                } else {
                    alert(res.data.message || qlsShop.i18n.error);
                }
            });
        },

        // 从购物车移除
        removeFromCart: function (itemId) {
            $.post(qlsShop.ajaxUrl, {
                action: 'qls_remove_from_cart',
                nonce: qlsShop.nonce,
                item_id: itemId
            }, function (res) {
                if (res.success) {
                    $('[data-item-id="' + itemId + '"]').fadeOut(300, function () {
                        $(this).remove();
                        if ($('.qls-cart-item').length === 0) {
                            location.reload();
                        }
                    });

                    var totals = res.data.totals;
                    $('#qls-cart-count').text(totals.total_quantity);
                    $('#qls-cart-total').text('¥' + totals.total_amount.toFixed(2));
                } else {
                    alert(res.data.message || qlsShop.i18n.error);
                }
            });
        },

        // 计算运费
        calculateShipping: function () {
            $.post(qlsShop.ajaxUrl, {
                action: 'qls_calculate_shipping',
                nonce: qlsShop.nonce
            }, function (res) {
                if (res.success) {
                    var fee = res.data.shipping_fee;
                    if (fee > 0) {
                        $('#qls-shipping-fee').text('¥' + parseFloat(fee).toFixed(2));
                    } else {
                        $('#qls-shipping-fee').text(text('free_shipping', '包邮'));
                    }

                    var productTotal = parseFloat($('#qls-product-total').val()) || 0;
                    var pointsUsed = parseInt($('#points_used').val()) || 0;
                    var pointsRate = parseInt($('#qls-points-rate').val()) || 10;
                    var pointsDiscount = pointsUsed / pointsRate;

                    var total = productTotal + fee - pointsDiscount;
                    $('#qls-total-amount').text('¥' + Math.max(0, total).toFixed(2));
                }
            });
        },

        // 显示自定义确认弹窗
        showConfirm: function (options) {
            var $modal = $('#qls-confirm-modal');

            // 如果模态框不存在，创建一个
            if ($modal.length === 0) {
                $('body').append(
                    '<div class="qls-confirm-modal" id="qls-confirm-modal" style="display:none;">' +
                    '<div class="qls-confirm-backdrop"></div>' +
                    '<div class="qls-confirm-dialog">' +
                    '<div class="qls-confirm-icon">' +
                    '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2">' +
                    '<circle cx="12" cy="12" r="10"></circle>' +
                    '<line x1="12" y1="8" x2="12" y2="12"></line>' +
                    '<line x1="12" y1="16" x2="12.01" y2="16"></line>' +
                    '</svg>' +
                    '</div>' +
                    '<h3 class="qls-confirm-title"></h3>' +
                    '<p class="qls-confirm-message"></p>' +
                    '<div class="qls-confirm-actions">' +
                    '<button type="button" class="qls-confirm-cancel"></button>' +
                    '<button type="button" class="qls-confirm-ok"></button>' +
                    '</div>' +
                    '</div>' +
                    '</div>'
                );
                $modal = $('#qls-confirm-modal');
            }

            // 设置内容
            $modal.find('.qls-confirm-title').text(options.title || text('confirm_title', '确认'));
            $modal.find('.qls-confirm-message').text(options.message || text('confirm_action_message', '确定继续操作？'));
            $modal.find('.qls-confirm-ok').text(options.confirmText || text('confirm_button', '确认'));
            $modal.find('.qls-confirm-cancel').text(options.cancelText || text('cancel_button', '取消'));

            // 显示模态框
            $modal.fadeIn(200);

            // 绑定事件（先解绑避免重复）
            $modal.find('.qls-confirm-cancel, .qls-confirm-backdrop').off('click').on('click', function () {
                $modal.fadeOut(200);
                if (options.onCancel) options.onCancel();
            });

            $modal.find('.qls-confirm-ok').off('click').on('click', function () {
                $modal.fadeOut(200);
                if (options.onConfirm) options.onConfirm();
            });
        },

        // 显示提示消息
        showToast: function (message, type) {
            type = type || 'info';
            var bgColor = type === 'error' ? '#ef4444' : (type === 'success' ? '#10b981' : '#3b82f6');

            var $toast = $('<div class="qls-toast"></div>')
                .text(message)
                .css({
                    position: 'fixed',
                    top: '50%',
                    left: '50%',
                    transform: 'translate(-50%, -50%)',
                    background: bgColor,
                    color: '#fff',
                    padding: '12px 24px',
                    borderRadius: '8px',
                    zIndex: 999999,
                    boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
                    fontWeight: 500,
                    opacity: 0
                });

            $('body').append($toast);
            $toast.animate({ opacity: 1 }, 200);

            setTimeout(function () {
                $toast.animate({ opacity: 0 }, 200, function () {
                    $(this).remove();
                });
            }, 3000);
        },

        // 更新结账金额
        updateCheckoutTotal: function () {
            var method = $('input[name="payment_method"]:checked').val() || 'alipay';
            var productTotal = parseFloat($('#qls-product-total').val()) || 0;
            var shippingFee = parseFloat($('#qls-shipping-fee').text().replace(/[^0-9.]/g, '')) || 0;
            var pointsName = $('#qls-points-name').val() || qlsShop.pointsName || text('points_name', '积分');

            // 获取优惠券折扣金额
            var couponDiscount = 0;
            var couponDiscountText = $('#qls-applied-coupon-discount').text();
            if (couponDiscountText) {
                couponDiscount = parseFloat(couponDiscountText.replace(/[^0-9.]/g, '')) || 0;
            }

            if (method === 'points') {
                // 积分支付模式
                var totalPoints = parseInt($('#qls-total-points-amount').val()) || 0;
                $('#qls-total-amount').text(totalPoints + ' ' + pointsName).css('color', 'var(--qls-warning)');
            } else {
                // 现金支付模式 - 包含优惠券折扣
                var total = productTotal + shippingFee - couponDiscount;
                $('#qls-total-amount').text('¥' + Math.max(0, total).toFixed(2)).css('color', 'var(--qls-danger)');

                // 显示优惠金额提示
                if (couponDiscount > 0) {
                    var discountText = formatText('coupon_discount_display', { amount: couponDiscount.toFixed(2) }, '已优惠 ¥{amount}');
                    if ($('#qls-coupon-discount-display').length === 0) {
                        $('#qls-total-amount').after('<span id="qls-coupon-discount-display" style="color:#27ae60;font-size:14px;margin-left:10px;">' + discountText + '</span>');
                    } else {
                        $('#qls-coupon-discount-display').text(discountText).show();
                    }
                } else {
                    $('#qls-coupon-discount-display').hide();
                }
            }
        },

        // 提交订单
        submitOrder: function () {
            var self = this;
            var $btn = $('#qls-submit-order');
            var originalText = $btn.text();

            // 获取表单数据
            var formData = {
                action: 'qls_checkout',
                nonce: qlsShop.nonce
            };

            // 检查是否是纯虚拟订单
            var isVirtualOnly = $('#qls-is-virtual-only').val() === '1';

            if (isVirtualOnly) {
                // 虚拟商品只需联系信息
                formData.receiver_name = $('#virtual_contact_name').val() || '';
                formData.receiver_phone = $('#virtual_contact_phone').val() || '';
                formData.receiver_email = $('#virtual_contact_email').val() || '';
                formData.receiver_address = text('virtual_no_shipping_address', '虚拟商品无需填写收货地址');
                formData.is_virtual_order = 1;

                var $queryPassword = $('#guest_query_password');
                if ($queryPassword.length) {
                    formData.guest_query_password = $queryPassword.val() || '';
                }
            } else {
                // 实物商品需要收货地址
                var addressId = $('input[name="address_id"]:checked').val();

                if (addressId) {
                    var $selectedAddress = $('input[name="address_id"]:checked').closest('.qls-address-item');
                    formData.receiver_name = $selectedAddress.find('.name').text();
                    formData.receiver_phone = $selectedAddress.find('.phone').text();
                    formData.receiver_address = $selectedAddress.find('.address').text();
                } else {
                    formData.receiver_name = $('#receiver_name').val();
                    formData.receiver_phone = $('#receiver_phone').val();
                    formData.receiver_province = $('#receiver_province').val();
                    formData.receiver_city = $('#receiver_city').val();
                    formData.receiver_district = $('#receiver_district').val();
                    formData.receiver_address = $('#receiver_address').val();

                    if ($('input[name="save_address"]').is(':checked')) {
                        formData.save_address = 1;
                    }
                }
            }

            formData.buyer_remark = $('#buyer_remark').val();

            // 优惠券
            var couponClaimId = $('#qls-coupon-claim-id').val();
            if (couponClaimId) {
                formData.coupon_claim_id = couponClaimId;
            }

            // 支付方式
            var paymentMethod = $('input[name="payment_method"]:checked').val();
            if (!paymentMethod) {
                self.showToast(text('select_payment_method', '请选择支付方式'), 'error');
                return;
            }
            formData.payment_method = paymentMethod;

            // 验证
            if (isVirtualOnly) {
                // 虚拟商品：只需联系人和联系方式（电话或邮箱）
                if (!formData.receiver_name) {
                    self.showToast(text('enter_contact_name', '请输入联系人姓名'), 'error');
                    return;
                }
                if (!formData.receiver_phone && !formData.receiver_email) {
                    self.showToast(text('enter_phone_or_email', '请输入手机号或邮箱'), 'error');
                    return;
                }
                if ($('#guest_query_password').length) {
                    var queryPassword = formData.guest_query_password || '';
                    if (!queryPassword || queryPassword.length < 4 || queryPassword.length > 64) {
                        self.showToast(text('enter_query_password', '请设置4-64位订单查询密码'), 'error');
                        return;
                    }
                }
            } else {
                // 实物商品：需要完整收货信息
                if (!formData.receiver_name || !formData.receiver_phone || !formData.receiver_address) {
                    self.showToast(text('enter_shipping_info', '请填写完整收货信息'), 'error');
                    return;
                }
            }

            var isGroupOrder = $('#qls-group-order').val() === '1';
            if (isGroupOrder) {
                formData.action = 'qls_group_checkout';
                formData.order_no = $('#qls-group-order-no').val();
            }

            $btn.text(text('submitting', '提交中...')).prop('disabled', true);

            $.post(qlsShop.ajaxUrl, formData, function (res) {
                if (res.success) {
                    var data = res.data || {};
                    if (data.payment_url) {
                        window.location.href = data.payment_url;
                        return;
                    }

                    // 兜底：有订单号且未标记 paid 时，强制走统一支付入口，避免被错误 redirect 抢先
                    if (!data.paid && data.order_no && paymentMethod !== 'points') {
                        var homeUrl = (qlsShop.homeUrl || window.location.origin || '/');
                        homeUrl = String(homeUrl).replace(/\/+$/, '');
                        window.location.href = homeUrl + '/?pay=shop&order=' + encodeURIComponent(data.order_no);
                        return;
                    }

                    if (data.redirect) {
                        window.location.href = data.redirect;
                    }
                } else {
                    self.showToast(res.data.message || qlsShop.i18n.error, 'error');
                    $btn.text(originalText).prop('disabled', false);
                }
            }).fail(function () {
                self.showToast(text('network_error', '网络异常，请稍后重试。'), 'error');
                $btn.text(originalText).prop('disabled', false);
            });
        },

        initGroupStock: function () {
            var self = this;
            var $stockBox = $('.qls-product-group-stock');
            if ($stockBox.length === 0) {
                return;
            }
            if (!self.checkGroupActivity()) {
                return;
            }
            self.refreshGroupStock();
            if (self.groupStockTimer) {
                clearInterval(self.groupStockTimer);
            }
            self.groupStockTimer = setInterval(function () {
                self.refreshGroupStock();
            }, 15000);
        },

        refreshGroupStock: function () {
            var self = this;
            if (!self.checkGroupActivity()) {
                return;
            }
            var $stockBox = $('.qls-product-group-stock');
            if ($stockBox.length === 0) {
                return;
            }
            var ruleId = parseInt($stockBox.data('rule-id')) || 0;
            var productId = parseInt($stockBox.data('product-id')) || 0;
            if (!ruleId && !productId) {
                return;
            }
            $.post(qlsShop.ajaxUrl, {
                action: 'qls_get_group_stock',
                nonce: qlsShop.nonce,
                rule_id: ruleId,
                product_id: productId
            }, function (res) {
                if (!res || !res.success || !res.data) {
                    return;
                }
                if (res.data.activity_ended) {
                    self.hideGroupSection();
                    return;
                }
                var stock = parseInt(res.data.stock, 10);
                if (isNaN(stock)) {
                    stock = 0;
                }
                $('#qls-group-stock-value').text(stock);
                if (stock <= 0) {
                    $('#qls-group-stock-status').show();
                    self.toggleGroupButtons(true);
                } else {
                    $('#qls-group-stock-status').hide();
                    self.toggleGroupButtons(false);
                }
            });
        },

        checkGroupActivity: function () {
            var $section = $('.qls-product-group-section');
            if ($section.length === 0) {
                return true;
            }
            var endTimestamp = parseInt($section.data('activity-end'), 10) || 0;
            if (endTimestamp > 0) {
                var now = Math.floor(Date.now() / 1000);
                if (now >= endTimestamp) {
                    this.hideGroupSection();
                    return false;
                }
            }
            return true;
        },

        hideGroupSection: function () {
            var $section = $('.qls-product-group-section');
            if ($section.length) {
                $section.hide();
            }
            if (this.groupStockTimer) {
                clearInterval(this.groupStockTimer);
                this.groupStockTimer = null;
            }
        },

        toggleGroupButtons: function (isEnded) {
            var $startBtn = $('#qls-start-group-btn');
            if ($startBtn.length) {
                if ($startBtn.data('origin-text') === undefined) {
                    $startBtn.data('origin-text', $startBtn.text());
                }
                if (isEnded) {
                    $startBtn.prop('disabled', true).text(text('group_ended', '拼团已结束'));
                } else {
                    $startBtn.prop('disabled', false).text($startBtn.data('origin-text'));
                }
            }
            $('.qls-join-group-btn').each(function () {
                var $btn = $(this);
                if ($btn.data('origin-text') === undefined) {
                    $btn.data('origin-text', $btn.text());
                }
                if (isEnded) {
                    $btn.prop('disabled', true).text(text('ended', '已结束'));
                } else {
                    $btn.prop('disabled', false).text($btn.data('origin-text'));
                }
            });
            if (isEnded) {
                $('.qls-product-groups-list').hide();
            } else {
                $('.qls-product-groups-list').show();
            }
        },

        triggerLogin: function (fallbackUrl) {
            if (typeof window.developerStarterShowLoginModal === 'function') {
                if (window.developerStarterShowLoginModal('login')) {
                    return true;
                }
            }
            if ($('#header-login-toggle').length) {
                $('#header-login-toggle').trigger('click');
                if ($('#header-login-toggle')[0]) {
                    $('#header-login-toggle')[0].click();
                }
                return true;
            }

            var $modal = $('#login-modal');
            var $overlay = $('#login-modal-overlay');
            if ($modal.length) {
                $modal.addClass('active');
                if ($overlay.length) {
                    $overlay.addClass('active');
                }
                $('body').css('overflow', 'hidden');
                $('#login-panel').show();
                $('#register-panel').hide();
                $('#modal-title').text(text('user_login', '用户登录'));
                return true;
            }

            window.location.href = fallbackUrl || '/wp-login.php';
            return true;
        },

        // 发起拼团
        createGroupOrder: function (productId, skuId, quantity, $btn) {
            var self = this;
            var originalText = $btn.text();

            $btn.text(text('processing', '处理中...')).prop('disabled', true);

            $.post(qlsShop.ajaxUrl, {
                action: 'qls_create_group_order',
                nonce: qlsShop.nonce,
                product_id: productId,
                sku_id: skuId,
                quantity: quantity
            }, function (res) {
                if (!res || typeof res !== 'object') {
                    self.showToast(qlsShop.i18n.error, 'error');
                    $btn.text(originalText).prop('disabled', false);
                    return;
                }

                if (res.success) {
                    if (res.data && res.data.redirect_url) {
                        window.location.href = res.data.redirect_url;
                        return;
                    }
                    self.showToast(text('order_created', '订单已创建'), 'success');
                } else {
                    var message = (res.data && res.data.message) ? res.data.message : qlsShop.i18n.error;
                    if (isLoginRequiredMessage(message)) {
                        self.triggerLogin();
                        $btn.text(originalText).prop('disabled', false);
                        return;
                    }
                    self.showToast(message || qlsShop.i18n.error, 'error');
                }
                $btn.text(originalText).prop('disabled', false);
            }).fail(function (xhr) {
                var message = text('network_error', '网络异常，请稍后重试。');
                if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    message = xhr.responseJSON.data.message;
                } else if (xhr && xhr.responseText && xhr.responseText !== '0' && xhr.responseText !== '-1') {
                    message = xhr.responseText;
                } else if (xhr && xhr.responseText === '-1') {
                    message = text('security_check_failed', '安全校验失败');
                }
                if (isLoginRequiredMessage(message)) {
                    self.triggerLogin();
                    $btn.text(originalText).prop('disabled', false);
                    return;
                }
                self.showToast(message, 'error');
                $btn.text(originalText).prop('disabled', false);
            });
        },

        // 参与拼团
        joinGroupOrder: function (groupId, skuId, quantity, $btn) {
            var self = this;
            var originalText = $btn.text();

            $btn.text(text('processing', '处理中...')).prop('disabled', true);

            $.post(qlsShop.ajaxUrl, {
                action: 'qls_join_group',
                nonce: qlsShop.nonce,
                group_id: groupId,
                sku_id: skuId,
                quantity: quantity
            }, function (res) {
                if (!res || typeof res !== 'object') {
                    self.showToast(qlsShop.i18n.error, 'error');
                    $btn.text(originalText).prop('disabled', false);
                    return;
                }

                if (res.success) {
                    if (res.data && res.data.redirect_url) {
                        window.location.href = res.data.redirect_url;
                        return;
                    }
                    self.showToast(text('order_created', '订单已创建'), 'success');
                } else {
                    var message = (res.data && res.data.message) ? res.data.message : qlsShop.i18n.error;
                    if (isLoginRequiredMessage(message)) {
                        self.triggerLogin();
                        $btn.text(originalText).prop('disabled', false);
                        return;
                    }
                    self.showToast(message || qlsShop.i18n.error, 'error');
                }
                $btn.text(originalText).prop('disabled', false);
            }).fail(function (xhr) {
                var message = text('network_error', '网络异常，请稍后重试。');
                if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    message = xhr.responseJSON.data.message;
                } else if (xhr && xhr.responseText && xhr.responseText !== '0' && xhr.responseText !== '-1') {
                    message = xhr.responseText;
                } else if (xhr && xhr.responseText === '-1') {
                    message = text('security_check_failed', '安全校验失败');
                }
                if (isLoginRequiredMessage(message)) {
                    self.triggerLogin();
                    $btn.text(originalText).prop('disabled', false);
                    return;
                }
                self.showToast(message, 'error');
                $btn.text(originalText).prop('disabled', false);
            });
        },

        // All Products Page - AJAX Search & Filter
        initAllProductsFilter: function () {
            var self = this;
            var $container = $('.qls-all-products-page');
            if ($container.length === 0) return;

            var $searchInput = $('#qls-product-search');
            var $searchBtn = $('#qls-search-btn');
            var typingTimer;
            var doneTypingInterval = 500; // 0.5s debounce

            // 1. Search Box Input (Debounced)
            $searchInput.on('input', function () {
                clearTimeout(typingTimer);
                typingTimer = setTimeout(function () {
                    self.filterProducts(1);
                }, doneTypingInterval);
            });

            // 2. Search Button Click
            $searchBtn.on('click', function () {
                self.filterProducts(1);
            });

            // 3. Enter Key
            $searchInput.on('keydown', function (e) {
                if (e.which == 13) {
                    clearTimeout(typingTimer);
                    self.filterProducts(1);
                    e.preventDefault();
                }
            });

            // 4. Sort Options Click
            $(document).on('click', '.qls-sort-item', function (e) {
                e.preventDefault();
                $('.qls-sort-item').removeClass('active');
                $(this).addClass('active');

                // Update URL immediately based on the valid HREF generated by PHP
                var href = $(this).attr('href');
                if (href) {
                    window.history.pushState({ path: href }, '', href);
                }

                self.filterProducts(1); // Reset to page 1
            });

            // 5. 分类标签点击：分类切换保持页面跳转，搜索和排序使用 AJAX。
            $(document).on('click', '.qls-tab-item, .qls-subtab-item', function (e) {
                // 当前页面的分类链接由全部商品页生成，保留完整链接。
                e.preventDefault();

                // 更新一级分类选中状态
                if ($(this).hasClass('qls-tab-item')) {
                    var $parent = $(this).closest('.qls-category-tabs');
                    $parent.find('.qls-tab-item').removeClass('active');
                    $(this).addClass('active');
                }

                // 更新二级分类选中状态
                if ($(this).hasClass('qls-subtab-item')) {
                    var $parent = $(this).closest('.qls-subcategory-tabs');
                    $parent.find('.qls-subtab-item').removeClass('active');
                    $(this).addClass('active');
                }

                // 分类切换需要重新渲染子分类结构，使用完整页面跳转更稳定。
                window.location.href = $(this).attr('href');
            });

            // 6. Pagination Click
            $container.on('click', '.qls-pagination a', function (e) {
                e.preventDefault();
                var href = $(this).attr('href');
                // 从 page/N/ 或 ?paged=N 中提取页码
                var page = 1;
                var match = href.match(/page\/(\d+)/);
                if (match) page = match[1];
                else {
                    match = href.match(/paged=(\d+)/);
                    if (match) page = match[1];
                }
                self.filterProducts(page);
                $('html, body').animate({ scrollTop: $container.offset().top - 100 }, 500);
            });

            // 浏览器后退时恢复服务端渲染状态
            window.onpopstate = function (event) {
                if (event.state) {
                    location.reload();
                }
            };
        },

        // 商品筛选
        filterProducts: function (paged) {
            var self = this;
            var keyword = $('#qls-product-search').val();
            // 当前排序
            var sort = $('.qls-sort-item.active').data('sort') || 'default';
            var urlParams = new URLSearchParams(window.location.search);
            var points = urlParams.get('points') || ($('.qls-points-filter-item.active').length ? '1' : '');
            if (sort === 'points_asc' || sort === 'points_desc') {
                points = '1';
            } else {
                points = '';
            }

            // 从当前 URL 读取分类
            var category = '';
            var path = window.location.pathname;
            var catMatch = path.match(/category\/([^\/]+)/);
            if (catMatch) category = catMatch[1];
            else {
                if (urlParams.has('category')) category = urlParams.get('category');
            }

            $('.qls-product-list-wrapper').css('opacity', '0.5');

	            var requestData = {
	                action: 'qls_shop_filter_products',
	                keyword: keyword,
	                sort: sort,
	                category: category,
	                paged: paged || 1,
	                nonce: qlsShop.nonce
	            };
	            if (points) {
	                requestData.points = points;
	            }
	
	            $.get(qlsShop.ajaxUrl, requestData, function (res) {
                $('.qls-product-list-wrapper').css('opacity', '1');
                if (res.success) {
                    // 更新商品列表
                    if (res.data.html.trim() !== '') {
                        $('.qls-product-list-wrapper').html(res.data.html);
                    } else {
                        $('.qls-product-list-wrapper').html('<div class="qls-empty-state"><p>' + text('no_products_found', '暂无匹配商品') + '</p></div>');
                    }

                    // 更新分页
                    $('.qls-pagination').html(res.data.pagination);

                    // 更新商品数量
                    $('.qls-products-count').html(res.data.count_html);

                    // 更新页面标题
                    if (res.data.page_title) {
                        document.title = res.data.page_title;
                    }

                    // 更新地址栏查询参数
                    var newUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
                    var params = new URLSearchParams(window.location.search);
                    if (keyword) params.set('keyword', keyword); else params.delete('keyword');
                    if (points && points !== '0') params.set('points', '1'); else params.delete('points');

                    // 排序与分类由路径承载，这里只同步搜索词和分页。
                    var queryString = params.toString();
                    if (paged > 1) {
                        if (queryString) queryString += '&paged=' + paged;
                        else queryString = 'paged=' + paged;
                    }

                    var finalUrl = newUrl;
                    if (queryString) {
                        finalUrl += '?' + queryString;
                    }

                    window.history.pushState({ path: finalUrl }, '', finalUrl);
                }
            });
        },

        // 助力活动倒计时
        initAssistCountdown: function () {
            var $card = $('.qls-assist-detail-card');
            if ($card.length === 0) {
                return;
            }
            var remain = parseInt($card.data('remain-seconds'), 10) || 0;
            var $el = $card.find('.qls-assist-countdown');
            if (!$el.length) {
                return;
            }

            var render = function () {
                if (remain <= 0) {
                    $el.text(text('ended', '已结束'));
                    return;
                }
                var h = Math.floor(remain / 3600);
                var m = Math.floor((remain % 3600) / 60);
                var s = remain % 60;
                $el.text(
                    String(h).padStart(2, '0') + ':' +
                    String(m).padStart(2, '0') + ':' +
                    String(s).padStart(2, '0')
                );
                remain--;
            };

            render();
            setInterval(render, 1000);
        },

        // 助力：创建活动实例
        assistCreateCampaign: function (activityId, $btn) {
            var self = this;
            var origin = $btn.text();
            $btn.prop('disabled', true).text(text('creating', '创建中...'));

            $.post(qlsShop.ajaxUrl, {
                action: 'qls_assist_create_campaign',
                nonce: qlsShop.nonce,
                activity_id: activityId
            }, function (res) {
                if (res && res.success) {
                    self.showToast(res.data.message || text('assist_created', '助力活动已创建'), 'success');
                    if (res.data.redirect_url) {
                        window.location.href = res.data.redirect_url;
                    } else {
                        location.reload();
                    }
                    return;
                }

                var message = (res && res.data && res.data.message) ? res.data.message : text('create_failed_retry', '创建失败，请稍后重试。');
                if (res && res.data && res.data.data && res.data.data.share_code && qlsShop.assistDetailUrl) {
                    var jumpUrl = qlsShop.assistDetailUrl + (qlsShop.assistDetailUrl.indexOf('?') >= 0 ? '&' : '?') + 'share=' + encodeURIComponent(res.data.data.share_code);
                    self.showConfirm({
                        title: text('assist_active_title', '已存在进行中的活动'),
                        message: message,
                        confirmText: text('go_view', '查看'),
                        onConfirm: function () {
                            window.location.href = jumpUrl;
                        }
                    });
                } else {
                    self.showToast(message, 'error');
                }
                $btn.prop('disabled', false).text(origin);
            }).fail(function () {
                self.showToast(text('network_error', '网络异常，请稍后重试。'), 'error');
                $btn.prop('disabled', false).text(origin);
            });
        },

        // 助力：帮忙砍价
        assistHelpCampaign: function (campaignId, $btn) {
            var self = this;
            var origin = $btn.text();
            $btn.prop('disabled', true).text(text('assisting', '助力中...'));

            $.post(qlsShop.ajaxUrl, {
                action: 'qls_assist_help_campaign',
                nonce: qlsShop.nonce,
                campaign_id: campaignId
            }, function (res) {
                if (res && res.success) {
                    self.showToast(formatText('assist_success_cut', { amount: Number(res.data.cut_amount || 0).toFixed(2) }, 'Assist succeeded, saved ¥{amount}'), 'success');
                    setTimeout(function () {
                        location.reload();
                    }, 800);
                    return;
                }
                self.showToast((res && res.data && res.data.message) ? res.data.message : text('assist_failed', '助力失败'), 'error');
                $btn.prop('disabled', false).text(origin);
            }).fail(function () {
                self.showToast(text('network_error', '网络异常，请稍后重试。'), 'error');
                $btn.prop('disabled', false).text(origin);
            });
        },

        // 助力：创建支付订单
        assistCreateOrder: function (campaignId, $btn) {
            var self = this;
            var origin = $btn.text();
            $btn.prop('disabled', true).text(text('creating_order', '正在创建订单...'));

            var paymentMethod = qlsShop.defaultPayment || 'alipay';
            $.post(qlsShop.ajaxUrl, {
                action: 'qls_assist_create_order',
                nonce: qlsShop.nonce,
                campaign_id: campaignId,
                payment_method: paymentMethod
            }, function (res) {
                if (res && res.success && res.data && res.data.payment_url) {
                    window.location.href = res.data.payment_url;
                    return;
                }
                self.showToast((res && res.data && res.data.message) ? res.data.message : text('create_payment_order_failed', '创建支付订单失败'), 'error');
                $btn.prop('disabled', false).text(origin);
            }).fail(function () {
                self.showToast(text('network_error', '网络异常，请稍后重试。'), 'error');
                $btn.prop('disabled', false).text(origin);
            });
        }
    };

    $(document).ready(function () {
        qlsShopFrontend.init();
        qlsShopFrontend.initAllProductsFilter(); // Initialize Search

        // 首页轮播 - Hero Carousel (Swiper)
        if ($('.qls-hero-slider.swiper-container').length > 0 && typeof Swiper !== 'undefined') {
            new Swiper('.qls-hero-slider.swiper-container', {
                loop: true,
                autoplay: {
                    delay: 5000,
                    disableOnInteraction: false,
                },
                pagination: {
                    el: '.swiper-pagination',
                    clickable: true,
                },
                navigation: {
                    nextEl: '.swiper-button-next',
                    prevEl: '.swiper-button-prev',
                },
            });
        }

        // Listen for payment method change to update total display
        $(document).on('change', 'input[name="payment_method"]', function () {
            qlsShopFrontend.updateCheckoutTotal();
        });
        // 标签页切换逻辑
        $(document).on('click', '.qls-tab', function () {
            var tabId = $(this).data('tab');
            if (!tabId) return;

            // 切换标签导航
            $(this).addClass('active').siblings().removeClass('active');

            // 切换标签内容
            $('#tab-' + tabId).addClass('active').siblings().removeClass('active');
        });

    });

})(jQuery);
