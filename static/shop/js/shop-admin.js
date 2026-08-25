/**
 * 电商后台 JavaScript
 */
(function ($) {
    'use strict';

    function text(key, fallback) {
        var i18n = window.qlsShopAdmin && window.qlsShopAdmin.i18n ? window.qlsShopAdmin.i18n : {};
        return i18n[key] || fallback || key;
    }

    function formatText(key, replacements, fallback) {
        var value = text(key, fallback);
        Object.keys(replacements || {}).forEach(function (placeholder) {
            value = value.replace(new RegExp('\\{' + placeholder + '\\}', 'g'), replacements[placeholder]);
        });
        return value;
    }

    var qlsShop = {
        init: function () {
            this.applyAdminUiClasses(document);
            this.bindEvents();
            this.updateBulkSelection($('.qls-bulk-form'));
            this.toggleBulkEditPanel($('.qls-bulk-form'));
            this.initMediaUploader();
            this.initSortable();
            this.observeDomChanges();
        },

        applyAdminUiClasses: function (context) {
            var $ctx = context ? $(context) : $(document);
            var $scope = $ctx.find('*').add($ctx);
            if (!$('body').hasClass('qilingshop-admin-shell')) {
                return;
            }

            $scope.filter('.wrap').addClass('qilingshop-admin-page');
            $scope.filter('nav.nav-tab-wrapper, .nav-tab-wrapper').addClass('qls-ui-tabs');
            $scope.filter('table.form-table').addClass('qls-ui-form-table');
            $scope.filter('table.wp-list-table, table.widefat').addClass('qls-ui-table');
            $scope.filter('.button').addClass('qls-ui-btn');
            $scope.filter('.button-primary').addClass('qls-ui-btn-primary');
            $scope.filter('.button-link-delete, .submitdelete').addClass('qls-ui-btn-danger');
        },

        observeDomChanges: function () {
            var self = this;
            if (!window.MutationObserver || !document.body || window.__qilingshopAdminUiObserver) {
                return;
            }
            window.__qilingshopAdminUiObserver = true;

            var observer = new MutationObserver(function (mutations) {
                mutations.forEach(function (mutation) {
                    if (!mutation.addedNodes || !mutation.addedNodes.length) {
                        return;
                    }

                    mutation.addedNodes.forEach(function (node) {
                        if (node.nodeType === 1) {
                            self.applyAdminUiClasses(node);
                        }
                    });
                });
            });

            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
        },

        bindEvents: function () {
            var self = this;

            // 旧删除链接确认（统一改为自定义确认框）
            $(document).on('click', '.delete-link', function (e) {
                var $trigger = $(this);
                var href = $trigger.attr('href');

                if ($trigger.is('a') && href) {
                    e.preventDefault();
                    self.showConfirm({
                        title: text('confirm_delete_title', '确认删除'),
                        message: text('confirm_delete_item', '确定删除该项？'),
                        confirmText: text('confirm_delete_button', '删除'),
                        onConfirm: function () {
                            window.location.href = href;
                        }
                    });
                    return;
                }

                var $form = $trigger.closest('form');
                if ($form.length && !$form.hasClass('qls-confirm-form')) {
                    e.preventDefault();
                    self.showConfirm({
                        title: text('confirm_delete_title', '确认删除'),
                        message: text('confirm_delete_item', '确定删除该项？'),
                        confirmText: text('confirm_delete_button', '删除'),
                        onConfirm: function () {
                            if ($form[0]) {
                                $form[0].submit();
                            }
                        }
                    });
                }
            });

            // 后台表单确认（用于助力活动：下架/上架/删除）
            $(document).on('submit', '.qls-confirm-form', function (e) {
                var $form = $(this);
                if ($form.data('qlsConfirmed') === 1) {
                    $form.data('qlsConfirmed', 0);
                    return;
                }

                e.preventDefault();
                self.showConfirm({
                    title: $form.data('confirm-title') || text('confirm_action_title', '确认操作'),
                    message: $form.data('confirm-message') || text('confirm_action_message', '确定继续操作？'),
                    confirmText: $form.data('confirm-ok') || text('confirm_button', '确认'),
                    cancelText: text('cancel_button', '取消'),
                    onConfirm: function () {
                        $form.data('qlsConfirmed', 1);
                        if ($form[0]) {
                            $form[0].submit();
                        }
                    }
                });
            });

            $(document).on('click', '.qls-confirm-link', function (e) {
                var $link = $(this);
                var href = $link.attr('href');
                if (!href) {
                    return;
                }

                e.preventDefault();
                self.showConfirm({
                    title: $link.data('confirm-title') || text('confirm_action_title', '确认操作'),
                    message: $link.data('confirm-message') || text('confirm_action_message', '确定继续操作？'),
                    confirmText: $link.data('confirm-ok') || text('confirm_button', '确认'),
                    cancelText: $link.data('confirm-cancel') || text('cancel_button', '取消'),
                    onConfirm: function () {
                        window.location.href = href;
                    }
                });
            });

            $(document).on('change', '.qls-bulk-form .qls-select-all', function () {
                var $form = $(this).closest('.qls-bulk-form');
                $form.find('input[name="product_ids[]"]').prop('checked', $(this).is(':checked'));
                self.updateBulkSelection($form);
            });

            $(document).on('change', '.qls-bulk-form input[name="product_ids[]"]', function () {
                var $form = $(this).closest('.qls-bulk-form');
                var total = $form.find('input[name="product_ids[]"]').length;
                var selected = $form.find('input[name="product_ids[]"]:checked').length;
                $form.find('.qls-select-all').prop('checked', total > 0 && selected === total);
                self.updateBulkSelection($form);
            });

            $(document).on('change', '.qls-bulk-form select[name="action"]', function () {
                self.toggleBulkEditPanel($(this).closest('.qls-bulk-form'));
            });

            $(document).on('submit', '.qls-bulk-form', function (e) {
                var $form = $(this);
                var action = String($form.find('select[name="action"]').val() || '');
                var selected = $form.find('input[name="product_ids[]"]:checked').length;

                if (action === '-1' || action === '') {
                    e.preventDefault();
                    alert($form.data('bulk-action-message') || text('select_bulk_action', '请选择批量操作。'));
                    return;
                }

                if (selected <= 0) {
                    e.preventDefault();
                    alert($form.data('bulk-empty-message') || text('select_bulk_items', '请先选择项目。'));
                    return;
                }

                if (action === 'bulk_edit' && !self.hasBulkEditChanges($form)) {
                    e.preventDefault();
                    alert($form.data('bulk-edit-empty-message') || text('bulk_edit_no_fields', '请至少设置一个要编辑的字段。'));
                    return;
                }

                if (action === 'delete' && $form.data('qlsConfirmed') !== 1) {
                    e.preventDefault();
                    self.showConfirm({
                        title: text('bulk_delete_title', '批量删除'),
                        message: $form.data('bulk-delete-message') || text('confirm_delete_selected', '确定删除选中项目？'),
                        confirmText: text('confirm_delete_button', '删除'),
                        cancelText: text('cancel_button', '取消'),
                        onConfirm: function () {
                            $form.data('qlsConfirmed', 1);
                            if ($form[0]) {
                                $form[0].submit();
                            }
                        }
                    });
                    return;
                }

                if (action === 'bulk_edit' && $form.data('qlsConfirmed') !== 1) {
                    e.preventDefault();
                    self.showConfirm({
                        title: text('bulk_edit_title', '批量编辑商品'),
                        message: formatText('bulk_edit_message', { count: selected }, 'Update {count} selected products?'),
                        confirmText: text('bulk_edit_confirm', '开始批量编辑'),
                        cancelText: text('cancel_button', '取消'),
                        onConfirm: function () {
                            $form.data('qlsConfirmed', 1);
                            if ($form[0]) {
                                $form[0].submit();
                            }
                        }
                    });
                    return;
                }

                $form.data('qlsConfirmed', 0);
            });

            $(document).on('click', '#qls-assist-open-product-library', function (e) {
                e.preventDefault();
                self.openAssistProductLibrary();
            });

            $(document).on('submit', '#qls-assist-product-search-form', function (e) {
                e.preventDefault();
                self.loadAssistProducts(1);
            });

            var assistSearchTimer = null;
            $(document).on('input', '#qls-assist-product-search', function () {
                clearTimeout(assistSearchTimer);
                assistSearchTimer = setTimeout(function () {
                    self.loadAssistProducts(1);
                }, 350);
            });

            $(document).on('click', '.qls-assist-product-card', function () {
                var product = $(this).data('product');
                if (product) {
                    self.selectAssistProduct(product);
                }
            });

            $(document).on('click', '#qls-assist-product-load-more', function () {
                var nextPage = parseInt($(this).data('nextPage'), 10) || 2;
                self.loadAssistProducts(nextPage, true);
            });

            $(document).on('submit', '.qls-assist-activity-form', function (e) {
                if (parseInt($('#assist_product').val(), 10) > 0) {
                    $('.qls-assist-product-error').hide();
                    return;
                }
                e.preventDefault();
                $('.qls-assist-product-error').show();
                self.openAssistProductLibrary();
            });
	
            // 添加规格
            $('#qls-add-attribute').on('click', function () {
                self.addAttribute();
            });

            // 删除规格
            $(document).on('click', '.qls-remove-attr', function () {
                $(this).closest('.qls-attribute-row').remove();
            });

            // 添加规格值
            $(document).on('click', '.qls-add-value', function () {
                self.addAttributeValue($(this).closest('.qls-attribute-row'));
            });

            // 删除规格值
            $(document).on('click', '.qls-remove-value', function () {
                $(this).closest('.qls-attr-value-row').remove();
            });

            // 添加版本值
            $(document).on('click', '.qls-add-version', function () {
                self.addVersion($(this).closest('.qls-versions-container'));
            });

            // 删除版本值
            $(document).on('click', '.qls-remove-version', function () {
                $(this).closest('.qls-version-tag').remove();
            });

            // 生成SKU组合
            $('#qls-generate-skus').on('click', function () {
                self.generateSkus();
            });

            // 删除SKU
            $(document).on('click', '.qls-remove-sku', function () {
                var $row = $(this).closest('.qls-sku-row');
                var $btn = $(this);
                var skuId = parseInt($row.find('input[name$="[id]"]').val(), 10) || parseInt($btn.data('sku-id'), 10) || 0;

                // 新增行（尚未入库）直接移除
                if (skuId <= 0) {
                    $row.remove();
                    return;
                }

                // 已入库 SKU：点击即删数据库，成功后再移除行
                if ($btn.data('deleting') === 1) {
                    return;
                }
                $btn.data('deleting', 1).css('opacity', 0.5);

                $.post(qlsShopAdmin.ajaxUrl, {
                    action: 'qls_shop_delete_sku',
                    nonce: qlsShopAdmin.nonce,
                    sku_id: skuId
                }).done(function (res) {
                    if (res && res.success) {
                        $row.remove();
                        return;
                    }
                    var msg = (res && res.data && res.data.message)
                        ? res.data.message
                        : text('delete_sku_failed_short', 'SKU 删除失败');
                    alert(msg);
                }).fail(function () {
                    var msg = text('delete_sku_failed_short', 'SKU 删除失败');
                    alert(msg);
                }).always(function () {
                    $btn.data('deleting', 0).css('opacity', '');
                });
            });

            // 添加参数
            $('#qls-add-param').on('click', function () {
                self.addParam();
            });

            // 删除参数
            $(document).on('click', '.qls-remove-param', function () {
                $(this).closest('.qls-param-row').remove();
            });

            // 快速添加参数
            $(document).on('click', '.qls-add-param-tpl', function () {
                var name = $(this).data('name');
                self.addParam(name);
            });

            // 发货弹窗
            $(document).on('click', '.qls-ship-order', function (e) {
                e.preventDefault();
                var orderId = $(this).data('order-id');
                $('#ship-order-id').val(orderId);
                $('#qls-ship-modal').removeClass('qls-hidden').css('display', 'flex');
            });

            $(document).on('click', '#qls-open-bulk-ship', function (e) {
                e.preventDefault();
                $('#qls-bulk-ship-modal')
                    .removeClass('qls-hidden')
                    .css('display', 'flex')
                    .attr('aria-hidden', 'false');
                $('#qls-bulk-ship-rows').trigger('focus');
            });

            $(document).on('click', '#qls-fill-paid-orders-template', function () {
                var lines = [];
                $('.qls-orders-table tbody tr[data-order-no][data-order-status="1"]').each(function () {
                    var orderNo = String($(this).attr('data-order-no') || '').trim();
                    if (orderNo) {
                        lines.push(orderNo + ',,');
                    }
                });

                if (!lines.length) {
                    alert(text('no_pending_ship_orders', '当前页面没有待发货订单。'));
                    return;
                }

                $('#qls-bulk-ship-rows').val(lines.join('\n')).trigger('focus');
            });

            $(document).on('submit', '#qls-bulk-ship-form', function (e) {
                var $form = $(this);
                if ($form.data('qlsConfirmed') === 1) {
                    $form.data('qlsConfirmed', 0);
                    return;
                }

                if (!$.trim($('#qls-bulk-ship-rows').val() || '')) {
                    e.preventDefault();
                    alert(text('bulk_ship_required', '请先填写订单号、物流公司和快递单号。'));
                    return;
                }

                e.preventDefault();
                self.showConfirm({
                    title: text('bulk_ship_title', '批量导入发货'),
                    message: text('bulk_ship_message', '将按已填写的物流公司和快递单号逐行发货，是否继续？'),
                    confirmText: text('bulk_ship_confirm', '开始发货'),
                    cancelText: text('bulk_ship_cancel', '再检查一下'),
                    onConfirm: function () {
                        $form.data('qlsConfirmed', 1);
                        if ($form[0]) {
                            $form[0].submit();
                        }
                    }
                });
            });

            // 关闭弹窗
            $(document).on('click', '.qls-modal-close', function (e) {
                e.preventDefault();
                $(this).closest('.qls-modal').hide().addClass('qls-hidden').attr('aria-hidden', 'true').css('display', '');
            });

            // 移除商品图集图片
            $(document).on('click', '.qls-gallery-item .qls-remove-item', function () {
                $(this).closest('.qls-gallery-item').remove();
                self.updateGalleryIndexes();
            });

            // 一键创建页面
            $('#qls-create-pages').on('click', function () {
                var $btn = $(this);
                $btn.prop('disabled', true).text(text('creating', '创建中...'));

                $.post(qlsShopAdmin.ajaxUrl, {
                    action: 'qls_create_shop_pages',
                    nonce: qlsShopAdmin.nonce
                }, function (res) {
                    if (res.success) {
                        alert(res.data.message);
                        location.reload();
                    } else {
                        alert(res.data.message || qlsShopAdmin.i18n.error);
                    }
                    $btn.prop('disabled', false).text(text('create_all_pages', '创建全部页面'));
                });
            });

            $(document).on('click', '.qls-copy-btn', function () {
                var $btn = $(this);
                var targetId = $btn.data('target');
                var $target = $('#' + targetId);
                var text = $target.is('input') ? $target.val() : $target.text();
                var originalText = $btn.text();

                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(function () {
                        $btn.text('✓');
                        setTimeout(function () { $btn.text(originalText); }, 1500);
                    });
                    return;
                }

                var $temp = $('<textarea>');
                $('body').append($temp);
                $temp.val(text).select();
                try {
                    document.execCommand('copy');
                    $btn.text('✓');
                    setTimeout(function () { $btn.text(originalText); }, 1500);
                } catch (e) {
                    $btn.text('×');
                    setTimeout(function () { $btn.text(originalText); }, 1500);
                }
                $temp.remove();
            });
        },

        showConfirm: function (options) {
            var $modal = $('#qls-admin-confirm-modal');
            if ($modal.length === 0) {
                $('body').append(
                    '<div id="qls-admin-confirm-modal" style="display:none;position:fixed;inset:0;z-index:100000;">' +
                    '<div class="qls-admin-confirm-backdrop" style="position:absolute;inset:0;background:rgba(15,23,42,.45);"></div>' +
                    '<div class="qls-admin-confirm-dialog" style="position:relative;max-width:420px;margin:16vh auto 0;background:#fff;border-radius:12px;box-shadow:0 16px 40px rgba(15,23,42,.24);padding:20px 20px 16px;">' +
                    '<h3 class="qls-admin-confirm-title" style="margin:0 0 10px;font-size:18px;line-height:1.4;color:#0f172a;"></h3>' +
                    '<p class="qls-admin-confirm-message" style="margin:0 0 18px;color:#475569;line-height:1.75;font-size:14px;"></p>' +
                    '<div style="display:flex;justify-content:flex-end;gap:10px;">' +
                    '<button type="button" class="button qls-admin-confirm-cancel"></button>' +
                    '<button type="button" class="button button-primary qls-admin-confirm-ok"></button>' +
                    '</div>' +
                    '</div>' +
                    '</div>'
                );
                $modal = $('#qls-admin-confirm-modal');
            }

            $modal.find('.qls-admin-confirm-title').text(options.title || text('confirm_action_title', '确认操作'));
            $modal.find('.qls-admin-confirm-message').text(options.message || text('confirm_action_message', '确定继续操作？'));
            $modal.find('.qls-admin-confirm-ok').text(options.confirmText || text('confirm_button', '确认'));
            $modal.find('.qls-admin-confirm-cancel').text(options.cancelText || text('cancel_button', '取消'));

            var close = function () {
                $modal.hide();
            };

            $modal.find('.qls-admin-confirm-backdrop, .qls-admin-confirm-cancel')
                .off('click')
                .on('click', close);

            $modal.find('.qls-admin-confirm-ok')
                .off('click')
                .on('click', function () {
                    close();
                    if (typeof options.onConfirm === 'function') {
                        options.onConfirm();
                    }
                });

            $modal.show();
        },

        updateBulkSelection: function (forms) {
            $(forms).each(function () {
                var $form = $(this);
                var selected = $form.find('input[name="product_ids[]"]:checked').length;
                var $count = $form.find('.qls-selected-count');
                if (!$count.length) {
                    return;
                }
                $count.text(selected > 0 ? formatText('selected_products_count', { count: selected }, '已选择 {count} 个商品') : text('no_products_selected', '未选择商品'));
                $count.toggleClass('is-active', selected > 0);
            });
        },

        toggleBulkEditPanel: function (forms) {
            $(forms).each(function () {
                var $form = $(this);
                var action = String($form.find('select[name="action"]').val() || '');
                var $panel = $form.find('.qls-bulk-edit-panel');
                if (!$panel.length) {
                    return;
                }

                $panel.toggleClass('qls-hidden', action !== 'bulk_edit');
            });
        },

        hasBulkEditChanges: function ($form) {
            if (this.isBulkSelectChanged($form.find('select[name="bulk_edit[status]"]'))) {
                return true;
            }

            if (this.isBulkSelectChanged($form.find('select[name="bulk_edit[category_id]"]'))) {
                return true;
            }

            if (this.isBulkSelectChanged($form.find('select[name="bulk_edit[shipping_rule_id]"]'))) {
                return true;
            }

            var toggleNames = [
                'bulk_edit[is_hot]',
                'bulk_edit[activity_recommend_enabled]',
                'bulk_edit[group_display_enabled]',
                'bulk_edit[assist_display_enabled]'
            ];
            for (var i = 0; i < toggleNames.length; i++) {
                if (this.isBulkSelectChanged($form.find('select[name="' + toggleNames[i] + '"]'))) {
                    return true;
                }
            }

            var newUserMode = String($form.find('select[name="bulk_edit[new_user_special_mode]"]').val() || 'no_change');
            var newUserPrice = $.trim($form.find('input[name="bulk_edit[new_user_special_price]"]').val() || '');
            if (newUserMode === 'disable' || (newUserMode === 'enable' && newUserPrice !== '' && parseFloat(newUserPrice) > 0)) {
                return true;
            }

            var priceMode = String($form.find('select[name="bulk_edit[price_mode]"]').val() || 'none');
            var priceValue = $.trim($form.find('input[name="bulk_edit[price_value]"]').val() || '');
            if (priceMode !== 'none' && priceValue !== '' && !isNaN(parseFloat(priceValue))) {
                return true;
            }

            var stockMode = String($form.find('select[name="bulk_edit[stock_mode]"]').val() || 'none');
            var stockValue = $.trim($form.find('input[name="bulk_edit[stock_value]"]').val() || '');
            if (stockMode !== 'none' && stockValue !== '' && !isNaN(parseInt(stockValue, 10))) {
                return true;
            }

            var sortMode = String($form.find('select[name="bulk_edit[sort_order_mode]"]').val() || 'none');
            var sortValue = $.trim($form.find('input[name="bulk_edit[sort_order_value]"]').val() || '');
            if (sortMode !== 'none' && sortValue !== '' && !isNaN(parseInt(sortValue, 10))) {
                return true;
            }

            var serviceMode = String($form.find('select[name="bulk_edit[service_tags_mode]"]').val() || 'no_change');
            var serviceChecked = $form.find('input[name="bulk_edit[service_tag_ids][]"]:checked').length;
            if (serviceMode === 'replace' || ((serviceMode === 'add' || serviceMode === 'remove') && serviceChecked > 0)) {
                return true;
            }

            return false;
        },

        isBulkSelectChanged: function ($select) {
            if (!$select.length) {
                return false;
            }

            var defaultValue = $select.data('default');
            if (typeof defaultValue === 'undefined') {
                defaultValue = '';
            }

            return String($select.val()) !== String(defaultValue);
        },

        openAssistProductLibrary: function () {
            var $modal = $('#qls-assist-product-modal');
            if ($modal.length === 0) {
                return;
            }
            $modal.removeClass('qls-hidden').css('display', 'flex').attr('aria-hidden', 'false');
            $('#qls-assist-product-search').trigger('focus');
            if (!$modal.data('loaded')) {
                this.loadAssistProducts(1);
            }
        },

        loadAssistProducts: function (page, append) {
            var self = this;
            var $modal = $('#qls-assist-product-modal');
            var $results = $('#qls-assist-product-results');
            var $status = $('#qls-assist-product-status');
            var $loadMore = $('#qls-assist-product-load-more');
            if ($modal.length === 0 || $results.length === 0) {
                return;
            }

            page = parseInt(page, 10) || 1;
            append = !!append;
            var keyword = $('#qls-assist-product-search').val() || '';

            if (!append) {
                $results.empty();
                $loadMore.hide();
            }
            $status.text(text('loading_products', '商品加载中...'));

            $.post(qlsShopAdmin.ajaxUrl, {
                action: 'qls_assist_search_products',
                nonce: qlsShopAdmin.nonce,
                keyword: keyword,
                page: page,
                per_page: 12
            }).done(function (res) {
                if (!res || !res.success || !res.data) {
                    $status.text((res && res.data && res.data.message) ? res.data.message : text('load_products_failed', '商品加载失败，请稍后重试。'));
                    return;
                }

                self.renderAssistProducts(res.data.items || [], append);
                $modal.data('loaded', 1);

                if (res.data.has_more) {
                    $loadMore.data('nextPage', page + 1).show();
                } else {
                    $loadMore.hide();
                }

                if ((res.data.items || []).length === 0 && !append) {
                    $status.text(text('no_matching_products', '暂无匹配的已上架商品。'));
                } else {
                    $status.text('');
                }
            }).fail(function () {
                $status.text(text('load_products_network_failed', '商品加载失败，请检查网络后重试。'));
            });
        },

        renderAssistProducts: function (items, append) {
            var $results = $('#qls-assist-product-results');
            if (!append) {
                $results.empty();
            }

            items.forEach(function (product) {
                var $card = $('<button type="button" class="qls-assist-product-card"></button>');
                var imageHtml = product.image
                    ? '<img src="' + qlsShop.escapeHtml(product.image) + '" alt="">'
                    : '<span class="dashicons dashicons-products"></span>';

                $card.html(
                    '<span class="qls-assist-product-card-thumb">' + imageHtml + '</span>' +
                    '<span class="qls-assist-product-card-body">' +
                    '<strong>#' + qlsShop.escapeHtml(product.id) + ' ' + qlsShop.escapeHtml(product.title) + '</strong>' +
                    '<em>' + qlsShop.escapeHtml(product.price_text || '') + '</em>' +
                    '<small>' + text('stock_label', '库存') + ' ' + qlsShop.escapeHtml(product.stock || 0) + '</small>' +
                    '</span>'
                );
                $card.data('product', product);
                $results.append($card);
            });
        },

        selectAssistProduct: function (product) {
            $('#assist_product').val(product.id);
            $('.qls-assist-product-error').hide();

            var imageHtml = product.image
                ? '<img src="' + this.escapeHtml(product.image) + '" alt="">'
                : '<span class="dashicons dashicons-products"></span>';

            $('.qls-assist-selected-product')
                .removeClass('is-empty')
                .addClass('has-product')
                .find('.qls-assist-selected-thumb')
                .html(imageHtml);

            $('.qls-assist-selected-title').text('#' + product.id + ' ' + product.title);
            $('.qls-assist-selected-meta').text(product.price_text || '');
            $('#qls-assist-open-product-library').text(text('change_product', '更换商品'));
            $('#qls-assist-product-modal').hide().addClass('qls-hidden').attr('aria-hidden', 'true').css('display', '');
        },

        escapeHtml: function (value) {
            return String(value === null || value === undefined ? '' : value).replace(/[&<>"']/g, function (char) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                }[char];
            });
        },
	
        initMediaUploader: function () {
            var self = this;

            // 添加图片到商品图集
            $(document).on('click', '.qls-add-gallery-image', function (e) {
                e.preventDefault();
                var frame = wp.media({
                    title: text('select_product_images', '选择商品图片'),
                    button: { text: text('add_to_product', '添加到商品') },
                    multiple: true
                });

                frame.on('select', function () {
                    var attachments = frame.state().get('selection').toJSON();
                    var $container = $('#product-gallery .qls-gallery-items');

                    attachments.forEach(function (attachment) {
                        var index = $container.find('.qls-gallery-item').length;
                        var url = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
                        var isCover = index === 0 ? 'is-cover' : '';
                        var coverBadge = index === 0 ? '<span class="qls-cover-badge">' + text('cover_badge', '封面') + '</span>' : '';

                        var html = '<div class="qls-gallery-item ' + isCover + '" data-type="image">' +
                            '<img src="' + url + '" alt="">' +
                            '<input type="hidden" name="gallery[' + index + '][url]" value="' + attachment.url + '">' +
                            '<input type="hidden" name="gallery[' + index + '][type]" value="image">' +
                            '<span class="qls-remove-item">&times;</span>' +
                            coverBadge +
                            '</div>';
                        $container.append(html);
                    });
                    self.updateGalleryIndexes();
                });

                frame.open();
            });

            // 添加视频到商品图集
            $(document).on('click', '.qls-add-gallery-video', function (e) {
                e.preventDefault();

                var videoUrl = prompt(text('video_url_prompt', '请输入视频链接：'));
                if (!videoUrl) return;

                var coverUrl = prompt(text('video_cover_prompt', '请输入视频封面图片链接（可选）：')) || '';

                var $container = $('#product-gallery .qls-gallery-items');
                var index = $container.find('.qls-gallery-item').length;
                var isCover = index === 0 ? 'is-cover' : '';
                var coverBadge = index === 0 ? '<span class="qls-cover-badge">' + text('cover_badge', '封面') + '</span>' : '';

                var thumbHtml = coverUrl ? '<img src="' + coverUrl + '" alt="">' : '<span class="dashicons dashicons-video-alt3"></span>';

                var html = '<div class="qls-gallery-item ' + isCover + '" data-type="video">' +
                    '<div class="qls-video-thumb">' + thumbHtml + '</div>' +
                    '<input type="hidden" name="gallery[' + index + '][url]" value="' + videoUrl + '">' +
                    '<input type="hidden" name="gallery[' + index + '][type]" value="video">' +
                    '<input type="hidden" name="gallery[' + index + '][cover]" value="' + coverUrl + '">' +
                    '<span class="qls-remove-item">&times;</span>' +
                    coverBadge +
                    '</div>';
                $container.append(html);
                self.updateGalleryIndexes();
            });

            // 上传规格值图片
            $(document).on('click', '.qls-upload-value-image', function (e) {
                e.preventDefault();
                var $btn = $(this);
                var $container = $btn.closest('.qls-value-image');

                var frame = wp.media({
                    title: text('select_value_image', '选择选项图片'),
                    button: { text: text('use_this_image', '使用此图片') },
                    multiple: false
                });

                frame.on('select', function () {
                    var attachment = frame.state().get('selection').first().toJSON();
                    var url = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;

                    $container.find('.qls-value-image-preview').html('<img src="' + url + '" alt="">');
                    $container.find('input[type="hidden"]').val(attachment.url);
                });

                frame.open();
            });

            // 通用单图上传 (分类、模块配置等)
            $(document).on('click', '.qls-upload-btn', function (e) {
                e.preventDefault();
                var $btn = $(this);
                // Find container: closest .qls-media-upload OR .qls-image-uploader (admin-shop/builder)
                var $container = $btn.closest('.qls-media-upload, .qls-image-uploader');
                
                var frame = wp.media({
                    title: text('select_image', '选择图片'),
                    button: { text: text('use_image', '使用此图片') },
                    multiple: false
                });

                frame.on('select', function () {
                    var attachment = frame.state().get('selection').first().toJSON();
                    // Prefer larger size for generic uploads? Or stay with full url?
                    // Typically value is full URL. Preview might be thumb.
                    var url = attachment.url;
                    
                    // Update Input
                    $container.find('input[type="text"], input[type="hidden"]').val(url).trigger('change');
                    
                    // Update Preview if exists (Category page)
                    var $preview = $container.find('.qls-media-preview');
                    if ($preview.length) {
                        $preview.html('<img src="' + url + '" alt="" style="max-width:100%; height:auto;">');
                    }
                });

                frame.open();
            });

            // 通用移除图片
            $(document).on('click', '.qls-remove-btn', function (e) {
                e.preventDefault();
                var $container = $(this).closest('.qls-media-upload, .qls-image-uploader');
                $container.find('input[type="text"], input[type="hidden"]').val('').trigger('change');
                $container.find('.qls-media-preview').empty();
            });
        },

        initSortable: function () {
            var self = this;
            if ($.fn.sortable) {
                $('#product-gallery .qls-gallery-items').sortable({
                    items: '.qls-gallery-item',
                    cursor: 'move',
                    update: function () {
                        self.updateGalleryIndexes();
                    }
                });
            }
        },

        updateGalleryIndexes: function () {
            $('#product-gallery .qls-gallery-item').each(function (index) {
                var $item = $(this);

                // 更新索引
                $item.find('input').each(function () {
                    var name = $(this).attr('name');
                    if (name) {
                        name = name.replace(/gallery\[\d+\]/, 'gallery[' + index + ']');
                        $(this).attr('name', name);
                    }
                });

                // 更新封面标记
                $item.removeClass('is-cover').find('.qls-cover-badge').remove();
                if (index === 0) {
                    $item.addClass('is-cover');
                    $item.append('<span class="qls-cover-badge">' + text('cover_badge', '封面') + '</span>');
                }
            });
        },

        addAttribute: function () {
            var index = $('.qls-attribute-row').length;
            var html = '<div class="qls-attribute-row" data-index="' + index + '">' +
                '<div class="qls-attr-header">' +
                '<input type="text" name="attributes[' + index + '][name]" class="attr-name" placeholder="' + this.escapeAttr(text('attribute_name_placeholder', '属性名称，例如：颜色')) + '">' +
                '<button type="button" class="button qls-remove-attr">' + text('remove_attribute', '移除属性') + '</button>' +
                '</div>' +
                '<div class="qls-attr-values">' +
                this.getValueRowHtml(index, 0) +
                '</div>' +
                '<button type="button" class="button button-small qls-add-value">' + text('add_attribute_value', '添加属性值') + '</button>' +
                '</div>';

            $('#qls-attributes-container').append(html);
        },

        getValueRowHtml: function (attrIndex, valueIndex) {
            return '<div class="qls-attr-value-row" data-value-index="' + valueIndex + '">' +
                '<div class="qls-value-main">' +
                '<input type="text" name="attributes[' + attrIndex + '][values][' + valueIndex + '][value]" class="qls-value-input" placeholder="' + this.escapeAttr(text('value_placeholder', '属性值，例如：白色')) + '">' +
                '<div class="qls-value-image">' +
                '<div class="qls-value-image-preview"></div>' +
                '<input type="hidden" name="attributes[' + attrIndex + '][values][' + valueIndex + '][image]" value="">' +
                '<button type="button" class="button button-small qls-upload-value-image">' + text('image_button', '图片') + '</button>' +
                '</div>' +
                '<span class="qls-remove-value">&times;</span>' +
                '</div>' +
                '<div class="qls-versions-container">' +
                '<label>' + text('version_label', '版本值：') + '</label>' +
                '<div class="qls-versions-list"></div>' +
                '<button type="button" class="button button-small qls-add-version">' + text('add_version', '添加版本') + '</button>' +
                '</div>' +
                '</div>';
        },

        addAttributeValue: function ($attrRow) {
            var attrIndex = $attrRow.data('index');
            var valueIndex = $attrRow.find('.qls-attr-value-row').length;

            $attrRow.find('.qls-attr-values').append(this.getValueRowHtml(attrIndex, valueIndex));
        },

        addVersion: function ($container) {
            var $row = $container.closest('.qls-attr-value-row');
            var $attrRow = $row.closest('.qls-attribute-row');
            var attrIndex = $attrRow.data('index');
            var valueIndex = $row.data('value-index');

            var html = '<span class="qls-version-tag">' +
                '<input type="text" name="attributes[' + attrIndex + '][values][' + valueIndex + '][versions][]" placeholder="' + this.escapeAttr(text('version_placeholder', 'e.g. 128G')) + '">' +
                '<span class="qls-remove-version">&times;</span>' +
                '</span>';

            $container.find('.qls-versions-list').append(html);
        },

        getVipLevels: function () {
            return (window.qlsShopAdmin && $.isArray(window.qlsShopAdmin.vipLevels))
                ? window.qlsShopAdmin.vipLevels
                : [];
        },

        escapeHtml: function (value) {
            return $('<div>').text(value === undefined || value === null ? '' : String(value)).html();
        },

        escapeAttr: function (value) {
            return this.escapeHtml(value).replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        },

        getBatchSettingsHtml: function () {
            var self = this;
            var vipLevels = this.getVipLevels();
            var html = '<div class="qls-batch-settings qls-batch-settings-toolbar">' +
                '<strong>' + text('batch_settings', '批量设置：') + '</strong>' +
                '<input type="number" id="batch-price" class="small-text" placeholder="' + this.escapeAttr(text('price_placeholder', '价格')) + '" step="0.01" min="0">' +
                '<input type="number" id="batch-sale-price" class="small-text" placeholder="' + this.escapeAttr(text('sale_price_placeholder', '优惠价')) + '" step="0.01" min="0">' +
                '<input type="number" id="batch-points-price" class="small-text" placeholder="' + this.escapeAttr(text('points_price_placeholder', '积分价')) + '" step="0.01" min="0">';

            if (vipLevels.length) {
                html += '<span class="qls-batch-vip-prices">';
                vipLevels.forEach(function (level) {
                    var levelId = parseInt(level.id, 10) || 0;
                    if (levelId <= 0) {
                        return;
                    }
                    html += '<input type="number" class="small-text batch-vip-price" data-level-id="' + levelId + '" placeholder="' + self.escapeAttr(level.name || ('VIP' + levelId)) + '" step="0.01" min="0">';
                });
                html += '</span>';
            }

            html += '<input type="number" id="batch-stock" class="small-text" placeholder="' + this.escapeAttr(text('stock_placeholder', '库存')) + '" min="0">' +
                '<input type="number" id="batch-weight" class="small-text" placeholder="' + this.escapeAttr(text('weight_placeholder', '重量')) + '" step="0.01" min="0">' +
                '<button type="button" class="button" id="qls-batch-apply">' + text('apply_batch', '应用到全部') + '</button>' +
                '</div>';

            return html;
        },

        getVipPriceInputsHtml: function (skuIndex) {
            var self = this;
            var vipLevels = this.getVipLevels();
            if (!vipLevels.length) {
                return '';
            }

            var html = '<td class="qls-sku-vip-prices-cell"><div class="qls-sku-vip-prices">';
            vipLevels.forEach(function (level) {
                var levelId = parseInt(level.id, 10) || 0;
                if (levelId <= 0) {
                    return;
                }
                html += '<label>' +
                    '<span>' + self.escapeHtml(level.name || ('VIP' + levelId)) + '</span>' +
                    '<input type="number" name="skus[' + skuIndex + '][vip_prices][' + levelId + ']" class="small-text" step="0.01" min="0">' +
                    '</label>';
            });
            html += '</div></td>';
            return html;
        },

        generateSkus: function () {
            var self = this;
            var combinations = [];

            // 收集所有规格数据
            $('.qls-attribute-row').each(function () {
                var attrName = $(this).find('.attr-name').val();
                if (!attrName) return;

                var values = [];
                $(this).find('.qls-attr-value-row').each(function () {
                    var $row = $(this);
                    var value = $row.find('.qls-value-input').val();
                    if (!value) return;

                    // 收集版本值
                    var versions = [];
                    $row.find('.qls-versions-list input').each(function () {
                        var v = $(this).val();
                        if (v) versions.push(v);
                    });

                    if (versions.length > 0) {
                        // 有版本值时，每个版本生成一个组合
                        versions.forEach(function (ver) {
                            values.push({
                                name: attrName,
                                value: value,
                                version: ver,
                                display: value + ' ' + ver
                            });
                        });
                    } else {
                        // 无版本值
                        values.push({
                            name: attrName,
                            value: value,
                            version: '',
                            display: value
                        });
                    }
                });

                if (values.length > 0) {
                    combinations.push(values);
                }
            });

            if (combinations.length === 0) {
                alert(text('add_specs_first', '请先添加属性和值。'));
                return;
            }

            // 生成笛卡尔积
            var skuList = this.cartesian(combinations);
            var vipLevels = this.getVipLevels();

            // 构建SKU表格
            var html = this.getBatchSettingsHtml() +
                '<table class="wp-list-table widefat fixed qls-skus-table">' +
                '<thead><tr>' +
                '<th>' + text('spec_column', '属性') + '</th>' +
                '<th>' + text('sku_code_column', 'SKU 编码') + '</th>' +
                '<th>' + text('price_column', '价格') + '</th>' +
                '<th>' + text('sale_price_column', '优惠价') + '</th>' +
                '<th>' + text('points_price_column', '积分价') + '</th>';
            if (vipLevels.length) {
                html += '<th>' + text('vip_price_column', 'VIP 价格') + '</th>';
            }
            html +=
                '<th>' + text('stock_column', '库存') + '</th>' +
                '<th>' + text('weight_column', '重量(g)') + '</th>' +
                '<th></th>' +
                '</tr></thead><tbody>';

            skuList.forEach(function (combo, index) {
                var attrValues = {};
                var display = [];
                combo.forEach(function (item) {
                    var key = item.name;
                    attrValues[key] = item.version ? (item.value + ' ' + item.version) : item.value;
                    display.push(item.display);
                });

                html += '<tr class="qls-sku-row">' +
                    '<td>' +
                    '<input type="hidden" name="skus[' + index + '][attr_values]" value=\'' + JSON.stringify(attrValues) + '\'>' +
                    display.join(' / ') +
                    '</td>' +
                    '<td><input type="text" name="skus[' + index + '][sku_code]" class="small-text"></td>' +
                    '<td><input type="number" name="skus[' + index + '][price]" class="small-text" step="0.01" min="0" value="0"></td>' +
                    '<td><input type="number" name="skus[' + index + '][sale_price]" class="small-text" step="0.01" min="0"></td>' +
                    '<td><input type="number" name="skus[' + index + '][points_price]" class="small-text" step="0.01" min="0"></td>' +
                    self.getVipPriceInputsHtml(index) +
                    '<td><input type="number" name="skus[' + index + '][stock]" class="small-text" min="0" value="0"></td>' +
                    '<td><input type="number" name="skus[' + index + '][weight]" class="small-text" step="0.01" min="0" value="0"></td>' +
                    '<td><span class="qls-remove-sku dashicons dashicons-trash"></span></td>' +
                    '</tr>';
            });

            html += '</tbody></table>';

            $('#qls-skus-container').html(html);
        },

        cartesian: function (arrays) {
            if (arrays.length === 0) return [[]];

            var result = [];
            var first = arrays[0];
            var rest = this.cartesian(arrays.slice(1));

            first.forEach(function (item) {
                rest.forEach(function (combo) {
                    result.push([item].concat(combo));
                });
            });

            return result;
        },

        addParam: function (name) {
            var index = $('#qls-params-container tr.qls-param-row').length;

            // 参数名：如果是预设参数则直接显示，否则显示输入框
            var paramName = name || '';

            var html = '<tr class="qls-param-row">' +
                '<td>' +
                '<input type="text" name="params[' + index + '][name]" class="widefat qls-param-name" value="' + paramName + '" placeholder="' + this.escapeAttr(text('param_name_placeholder', '参数名称')) + '"' + (name ? ' readonly' : '') + '>' +
                '</td>' +
                '<td>' +
                '<input type="text" name="params[' + index + '][value]" class="widefat qls-param-value" placeholder="' + this.escapeAttr(text('param_value_placeholder', '参数值')) + '">' +
                '</td>' +
                '<td>' +
                '<span class="qls-remove-param dashicons dashicons-trash" title="' + this.escapeAttr(text('delete_button', '删除')) + '"></span>' +
                '</td>' +
                '</tr>';

            $('#qls-params-container').append(html);

            // 聚焦到参数值输入框
            $('#qls-params-container tr.qls-param-row').last().find('.qls-param-value').focus();
        }
    };

    $(document).ready(function () {
        qlsShop.init();
    });

})(jQuery);
