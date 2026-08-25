/**
 * QilingShop Public JS
 */
(function ($) {
    'use strict';

    function text(key, fallback) {
        var i18n = window.qilingshop && window.qilingshop.i18n ? window.qilingshop.i18n : {};
        return i18n[key] || fallback || key;
    }

    function formatText(key, replacements, fallback) {
        var value = text(key, fallback);
        Object.keys(replacements || {}).forEach(function (placeholder) {
            value = value.replace(new RegExp('\\{' + placeholder + '\\}', 'g'), replacements[placeholder]);
        });
        return value;
    }

    function isDebugEnabled() {
        return !!(window.qilingshop && (window.qilingshop.debug === true || window.qilingshop.debug === 1 || window.qilingshop.debug === '1'));
    }

    function debugLog() {
        var logger = window.console;
        if (!isDebugEnabled() || !logger || typeof logger.log !== 'function') {
            return;
        }
        logger.log.apply(logger, arguments);
    }

    var QilingShop = {
        init: function () {
            this.bindEvents();
        },

        bindEvents: function () {
            // Buy with points
            $(document).on('click', '.qls-download-content-tb-btn-buy[data-action="buy_with_points"], .qls-download-title-hd-btn-buy[data-action="buy_with_points"], .qls-download-sidebar-re-btn-buy[data-action="buy_with_points"]', this.buyWithPoints);

            // Direct pay
            $(document).on('click', '.qls-download-content-tb-btn-pay[data-action="direct_pay"], .qls-download-title-hd-btn-pay[data-action="direct_pay"], .qls-download-sidebar-re-btn-pay[data-action="direct_pay"]', this.directPay);

            // Checkin
            $(document).on('click', '[data-action="checkin"]', this.checkin);

            // Buy VIP
            $(document).on('click', '.btn-buy-vip', this.buyVip);

            // Recharge
            $(document).on('click', '.btn-recharge[data-action="recharge"]', this.recharge);

            // Quick amount buttons
            $(document).on('click', '.quick-amounts button', this.selectQuickAmount);

            // Amount input change
            $(document).on('input', '#recharge-amount', this.updatePointsPreview);

            // Secure download
            $(document).on('click', '.qls-download-content-tb-secure-download, .qls-download-title-hd-secure-download, .qls-download-sidebar-re-secure-download', this.secureDownload);

            // Pancheck - cloud drive link detection
            $(document).on('click', '.qls-download-content-tb-pancheck-btn, .qls-download-title-hd-pancheck-btn, .qls-download-sidebar-re-pancheck-btn', this.pancheck);

            // Login Modal Trigger
            $(document).on('click', '.qls-login-trigger', this.triggerLoginModal);

            // Guest order lookup
            $(document).on('submit', '.qilingshop-order-query-form', this.lookupOrder);
            $(document).on('click', '.qilingshop-order-query-copy', this.copyOrderLookupVirtualItem);
        },

        setButtonLoading: function ($btn, loadingText) {
            if (!$btn || !$btn.length) {
                return;
            }
            if (!$btn.data('qlsOriginalText')) {
                $btn.data('qlsOriginalText', $.trim($btn.text()));
            }
            $btn.addClass('is-loading').prop('disabled', true);
            if (typeof loadingText === 'string' && loadingText !== '') {
                $btn.text(loadingText);
            }
        },

        clearButtonLoading: function ($btn, text) {
            if (!$btn || !$btn.length) {
                return;
            }
            var nextText = text;
            if (typeof nextText !== 'string' || nextText === '') {
                nextText = $btn.data('qlsOriginalText') || $btn.text();
            }
            $btn.removeClass('is-loading').prop('disabled', false).text(nextText);
            $btn.removeData('qlsOriginalText');
        },

        getDownloadIndex: function ($btn, $box) {
            var raw = $btn && $btn.length ? $btn.attr('data-download-index') : '';
            if ((typeof raw === 'undefined' || raw === '') && $box && $box.length) {
                raw = $box.attr('data-download-index');
            }
            var index = parseInt(raw, 10);
            return isNaN(index) ? -1 : index;
        },

        // Trigger Theme Login Modal
        triggerLoginModal: function (e) {
            var $btn = $(this);
            var url = $btn.attr('href');
            var processed = false;

            // 1. Try global function
            if (typeof window.developerStarterShowLoginModal === 'function') {
                if (window.developerStarterShowLoginModal('login')) {
                    e.preventDefault();
                    processed = true;
                }
            }

            // 2. Try click header button
            if (!processed) {
                var $headerBtn = $('#header-login-toggle');

                if ($headerBtn.length) {
                    // Try naive click
                    $headerBtn.click();
                    // Try native click
                    if ($headerBtn[0]) $headerBtn[0].click();

                    e.preventDefault();
                    processed = true;
                }
            }

            // 3. 尝试手动更新页面元素
            if (!processed) {
                var $modal = $('#login-modal');
                var $overlay = $('#login-modal-overlay');

                if ($modal.length) {
                    $modal.addClass('active');
                    if ($overlay.length) $overlay.addClass('active');
                    $('body').css('overflow', 'hidden');

                    // Reset to login view if possible (naive)
                    $('#login-panel').show();
                    $('#register-panel').hide();
                    $('#modal-title').text(text('userLogin', '用户登录'));

                    e.preventDefault();
                    processed = true;
                }
            }

            // 4. 兜底：跳转链接
            if (!processed) {
                // Let the default link action proceed
            }
        },

        // 安全下载处理
        secureDownload: function (e) {
            e.preventDefault();

            var $link = $(this);
            var originalHtml = $link.html(); // Save original content
            var token = $link.data('token');
            var isPaid = $link.data('is-paid');

            // 获取等待时间
            var waitTime = 0;
            // 确保转换为整数
            if (parseInt(isPaid) === 1) {
                waitTime = parseInt(qilingshop.paidDownloadWait) || 0;
            } else {
                waitTime = parseInt(qilingshop.freeDownloadWait) || 0;
            }

            debugLog('Download 类型：', parseInt(isPaid) === 1 ? 'Paid' : '免费');
            debugLog('Wait Time:', waitTime);

            if (!token) {
                QilingShop.showMessage(text('invalidDownloadLink', '下载链接无效'), 'error');
                return;
            }

            var doDownload = function () {
                if ($link.data('qlsDownloadPending')) {
                    return;
                }
                $link.data('qlsDownloadPending', true);
                $link.html(text('fetching', '获取中...'));
                var request = QilingShop.ajax('get_download', {
                    token: token
                }, function (response) {
                    if (response.success && response.data && response.data.url) {
                        // 打开真实下载地址
                        window.open(response.data.url, '_blank');
                        $link.html(originalHtml);
                    } else {
                        QilingShop.showMessage(response.message || text('downloadUrlFailed', '获取下载链接失败'), 'error');
                        $link.html(originalHtml);
                    }
                });
                if (request && typeof request.always === 'function') {
                    request.always(function () {
                        $link.removeData('qlsDownloadPending');
                        if ($link.html() === text('fetching', '获取中...')) {
                            $link.html(originalHtml);
                        }
                    });
                }
            };

            // 如果有等待时间，显示倒计时弹窗
            if (waitTime > 0) {
                QilingShop.showWaitModal(waitTime, doDownload);
            } else {
                doDownload();
            }
        },

        // 显示等待倒计时弹窗
        showWaitModal: function (seconds, callback) {
            // 检查是否已存在弹窗，防止重复
            if ($('#qilingshop-wait-modal').length > 0) return;

            var html = '<div id="qilingshop-wait-modal" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);display:flex;align-items:center;justify-content:center;z-index:99999;">' +
                '<div style="background:#fff;padding:30px;border-radius:16px;min-width:300px;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,0.3);">' +
                '<div style="font-size:40px;margin-bottom:15px;">⏳</div>' +
                '<h3 style="margin:0 0 10px;color:#333;font-size:18px;">' + text('preparingResource', '正在准备资源...') + '</h3>' +
                '<p style="margin:0 0 20px;color:#666;font-size:14px;">' + formatText('pleaseWaitSeconds', { seconds: '<span id="qls-wait-seconds" style="color:#e74c3c;font-weight:bold;font-size:18px;">' + seconds + '</span>' }, 'Please wait {seconds} seconds') + '</p>' +
                '<div style="height:4px;background:#eee;border-radius:2px;overflow:hidden;margin-bottom:20px;">' +
                '<div id="qls-wait-progress" style="height:100%;background:#3498db;width:100%;transition:width 1s linear;"></div>' +
                '</div>' +
                '<button id="qls-wait-close" type="button" style="background:#f5f5f5;color:#999;border:none;padding:10px 25px;border-radius:6px;cursor:not-allowed;font-size:14px;transition:all 0.3s;" disabled>' + text('downloadPreparing', '正在准备下载...') + '</button>' +
                '</div></div>';

            $('body').append(html);

            var remaining = seconds;
            var $seconds = $('#qls-wait-seconds');
            var $progress = $('#qls-wait-progress');
            var $closeBtn = $('#qls-wait-close');

            // 立即设置初始宽度
            $progress.css('width', '100%');

            var interval = setInterval(function () {
                remaining--;
                $seconds.text(remaining);
                $progress.css('width', (remaining / seconds * 100) + '%');

                if (remaining <= 0) {
                    clearInterval(interval);

                    // 倒计时结束，更新按钮状态
                    $closeBtn.prop('disabled', false)
                        .css({
                            'background': '#3498db',
                            'color': '#fff',
                            'cursor': 'pointer',
                            'box-shadow': '0 4px 15px rgba(52, 152, 219, 0.4)'
                        })
                        .text(text('downloadNow', '立即下载'));

                    // 绑定点击事件执行回调
                    $closeBtn.one('click', function () {
                        $('#qilingshop-wait-modal').remove();
                        if (typeof callback === 'function') {
                            callback();
                        }
                    });
                }
            }, 1000);
        },

        // 网盘链接检测
        pancheck: function (e) {
            e.preventDefault();
            e.stopPropagation();

            var $btn = $(this);
            var $textSpan = $btn.find('.qls-download-content-tb-pancheck-text, .qls-download-title-hd-pancheck-text, .qls-download-sidebar-re-pancheck-text, .pancheck-text');
            if (!$textSpan.length) {
                $textSpan = $btn;
            }
            var $box = $btn.closest('.qls-download-content-tb-wrap, .qls-download-title-hd-wrap, .qls-download-sidebar-re-wrap');
            var postId = $btn.data('post-id') || $box.data('post-id') || 0;
            var downloadIndex = $btn.data('download-index');
            var token = $btn.data('pancheck-token') || '';

            if (!postId && !token) {
                QilingShop.showMessage(text('invalidLink', '链接无效'), 'error');
                return;
            }

            // 设置加载状态
            $btn.prop('disabled', true);
            $textSpan.text(text('checking', '检测中...'));
            $btn.removeClass('pancheck-valid pancheck-invalid');

            QilingShop.ajax('pancheck', {
                token: token || '',
                post_id: postId || 0,
                download_index: (downloadIndex === undefined || downloadIndex === null || downloadIndex === '') ? 0 : downloadIndex
            }, function (response) {
                $btn.prop('disabled', false);

                if (response.success && response.data) {
                    if (response.data.status === 'valid') {
                        $textSpan.text(text('linkValid', '链接有效') + ' ✓');
                        $btn.addClass('pancheck-valid');
                    } else if (response.data.status === 'invalid') {
                        $textSpan.text(text('linkInvalid', '链接无效') + ' ✗');
                        $btn.addClass('pancheck-invalid');
                    } else {
                        $textSpan.text(response.data.message || text('checkCompleted', '检测完成'));
                    }
                } else {
                    $textSpan.text(text('checkFailed', '检测失败'));
                    $btn.addClass('pancheck-invalid');
                    QilingShop.showMessage(response.message || text('checkFailed', '检测失败'), 'error');
                }
            });
        },

        ajax: function (action, data, callback) {
            data.action = 'qilingshop_' + action;
            data.nonce = qilingshop.nonce;

            return $.post(qilingshop.ajaxUrl, data, function (response) {
                if (typeof callback === 'function') {
                    callback(response);
                }
            });
        },

        lookupOrder: function (e) {
            e.preventDefault();

            var $form = $(this);
            var $submit = $form.find('.qilingshop-order-query-submit');
            var $result = $form.closest('.qilingshop-order-query').find('.qilingshop-order-query-result');
            var orderNo = $.trim(($form.find('input[name="order_no"]').val() || '').toUpperCase());
            var contact = $.trim($form.find('input[name="contact"]').val() || '');
            var queryPassword = $.trim($form.find('input[name="query_password"]').val() || '');

            if (!contact || (!orderNo && !queryPassword)) {
                $result.removeClass('is-success').addClass('is-error')
                    .html('<div class="qilingshop-order-query-message">' + text('enterOrderLookupCredentials', '请输入手机号/邮箱，并填写订单号或查询密码') + '</div>');
                return;
            }

            $submit.prop('disabled', true).text(text('querying', '查询中...'));
            $result.removeClass('is-success is-error').html('<div class="qilingshop-order-query-loading">' + text('queryingWait', '正在查询，请稍候...') + '</div>');

            QilingShop.ajax('order_lookup', {
                order_no: orderNo,
                contact: contact,
                query_password: queryPassword
            }, function (response) {
                $submit.prop('disabled', false).text(text('queryOrder', '查询订单'));

                if (!response || !response.success || !response.data) {
                    var errorMessage = (response && (response.message || (response.data && response.data.message))) ? (response.message || response.data.message) : text('queryFailedRetry', '查询失败，请稍后重试。');
                    $result.removeClass('is-success').addClass('is-error')
                        .html('<div class="qilingshop-order-query-message">' + QilingShop.escapeHtml(errorMessage) + '</div>');
                    return;
                }

                $result.removeClass('is-error').addClass('is-success')
                    .html(QilingShop.renderOrderLookupResult(response.data));
            });
        },

        renderOrderLookupResult: function (data) {
            if (data && Array.isArray(data.orders)) {
                if (!data.orders.length) {
                    return '<div class="qilingshop-order-query-message">' + text('queryFailedRetry', '查询失败，请稍后重试') + '</div>';
                }

                var listHtml = '<div class="qilingshop-order-query-list">';
                data.orders.forEach(function (order) {
                    listHtml += QilingShop.renderOrderLookupSingleResult(order);
                });
                listHtml += '</div>';
                return listHtml;
            }

            return QilingShop.renderOrderLookupSingleResult(data);
        },

        renderOrderLookupSingleResult: function (data) {
            var rows = [];
            rows.push({ label: text('orderType', '订单类型'), value: data.scene_label || '-' });
            rows.push({ label: text('orderNo', '订单号'), value: data.order_no || '-' });
            rows.push({ label: text('orderStatus', '订单状态'), value: data.status_text || '-' });
            rows.push({ label: text('orderAmount', '订单金额'), value: data.amount_text || '-' });
            rows.push({ label: text('createdAt', '创建时间'), value: data.created_at || '-' });
            if (data.paid_at) {
                rows.push({ label: text('paidAt', '支付时间'), value: data.paid_at });
            }
            if (data.item_title) {
                rows.push({ label: text('orderContent', '订单内容'), value: data.item_title });
            }
            if (data.scope_label) {
                rows.push({ label: text('scopeLabel', '范围'), value: data.scope_label });
            }

            var html = '<div class="qilingshop-order-query-card">';
            html += '<div class="qilingshop-order-query-card-title">' + text('queryResult', '查询结果') + '</div>';
            html += '<div class="qilingshop-order-query-grid">';

            rows.forEach(function (row) {
                html += '<div class="qilingshop-order-query-grid-item">' +
                    '<span class="qilingshop-order-query-key">' + QilingShop.escapeHtml(row.label) + '</span>' +
                    '<span class="qilingshop-order-query-val">' + QilingShop.escapeHtml(row.value) + '</span>' +
                    '</div>';
            });

            html += '</div>';
            if (Array.isArray(data.virtual_items) && data.virtual_items.length) {
                html += QilingShop.renderOrderLookupVirtualItems(data.virtual_items);
            }
            if (data.detail_url) {
                html += '<div class="qilingshop-order-query-actions">' +
                    '<a class="qilingshop-order-query-link" href="' + QilingShop.escapeHtml(data.detail_url) + '">' + text('viewOrderDetail', '查看订单详情') + '</a>' +
                    '</div>';
            }
            html += '</div>';

            return html;
        },

        renderOrderLookupVirtualItems: function (virtualItems) {
            var html = '<div class="qilingshop-order-query-virtual">';
            html += '<div class="qilingshop-order-query-virtual-title">' + text('virtualInfo', '虚拟商品信息') + '</div>';

            virtualItems.forEach(function (item) {
                var title = item && item.title ? item.title : text('virtualProduct', '虚拟商品');
                var typeLabel = item && item.type_label ? item.type_label : (item && item.type ? item.type : text('virtualContent', '虚拟内容'));
                var lines = item && Array.isArray(item.lines) ? item.lines : [];
                var copyText = title + '\n' + text('typeLabel', '类型：') + typeLabel + '\n' + (lines.length ? lines.join('\n') : text('noVirtualContent', '暂无虚拟内容'));
                var copyPayload = encodeURIComponent(copyText);

                html += '<div class="qilingshop-order-query-virtual-item">';
                html += '<div class="qilingshop-order-query-virtual-head">' +
                    '<div class="qilingshop-order-query-virtual-meta">' +
                    '<span class="qilingshop-order-query-virtual-name">' + QilingShop.escapeHtml(title) + '</span>' +
                    '<span class="qilingshop-order-query-virtual-type">' + QilingShop.escapeHtml(typeLabel) + '</span>' +
                    '</div>' +
                    '<button type="button" class="qilingshop-order-query-copy" data-copy-text="' + QilingShop.escapeHtml(copyPayload) + '">' + text('copyAll', '复制') + '</button>' +
                    '</div>';

                if (lines.length) {
                    html += '<pre class="qilingshop-order-query-virtual-pre">' + QilingShop.escapeHtml(lines.join('\n')) + '</pre>';
                } else {
                    html += '<div class="qilingshop-order-query-virtual-empty">' + text('noVirtualContent', '暂无虚拟内容') + '</div>';
                }

                html += '</div>';
            });

            html += '</div>';
            return html;
        },

        copyOrderLookupVirtualItem: function (e) {
            e.preventDefault();
            var $btn = $(this);
            var encodedText = String($btn.attr('data-copy-text') || '').trim();
            var copyText = '';
            if (encodedText) {
                try {
                    copyText = decodeURIComponent(encodedText);
                } catch (err) {
                    copyText = encodedText;
                }
            }

            if (!copyText) {
                QilingShop.showMessage(text('nothingToCopy', '没有可复制内容'), 'warning');
                return;
            }

            var originalText = $btn.text();
            QilingShop.copyText(copyText, function (success) {
                if (!success) {
                    QilingShop.showMessage(text('copyFailedSelectManual', '复制失败，请手动选择复制。'), 'error');
                    return;
                }

                $btn.addClass('is-copied').text(text('copied', '已复制'));
                setTimeout(function () {
                    $btn.removeClass('is-copied').text(originalText || text('copyAll', '复制'));
                }, 1500);
            });
        },

        copyText: function (text, callback) {
            var done = function (result) {
                if (typeof callback === 'function') {
                    callback(!!result);
                }
            };

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function () {
                    done(true);
                }).catch(function () {
                    QilingShop.copyTextFallback(text, done);
                });
                return;
            }

            QilingShop.copyTextFallback(text, done);
        },

        copyTextFallback: function (text, callback) {
            var $temp = $('<textarea readonly></textarea>');
            $temp.css({
                position: 'fixed',
                top: '-9999px',
                left: '-9999px',
                opacity: '0'
            });
            $temp.val(text);
            $('body').append($temp);

            var ok = false;
            try {
                $temp[0].focus();
                $temp[0].select();
                ok = document.execCommand('copy');
            } catch (err) {
                ok = false;
            }

            $temp.remove();
            if (typeof callback === 'function') {
                callback(ok);
            }
        },

        escapeHtml: function (value) {
            return String(value === null || value === undefined ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        },

        showMessage: function (message, type) {
            type = type || 'success';

            // 移除 existing toast
            $('.qilingshop-toast').remove();

            // Create toast element
            var bgColor = type === 'error' ? 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)' :
                type === 'warning' ? 'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)' :
                    'linear-gradient(135deg, #10b981 0%, #059669 100%)';

            var icon = type === 'error' ? '❌' : type === 'warning' ? '⚠️' : '✅';

            var html = '<div class="qilingshop-toast" style="' +
                'position: fixed;' +
                'top: 20px;' +
                'left: 50%;' +
                'transform: translateX(-50%) translateY(-100px);' +
                'background: ' + bgColor + ';' +
                'color: #fff;' +
                'padding: 14px 28px;' +
                'border-radius: 10px;' +
                'font-size: 15px;' +
                'font-weight: 500;' +
                'z-index: 999999;' +
                'box-shadow: 0 10px 40px rgba(0,0,0,0.3);' +
                'display: flex;' +
                'align-items: center;' +
                'gap: 10px;' +
                'transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);' +
                '">' +
                '<span style="font-size: 18px;">' + icon + '</span>' +
                '<span>' + message + '</span>' +
                '</div>';

            $('body').append(html);

            // Animate in
            setTimeout(function () {
                $('.qilingshop-toast').css('transform', 'translateX(-50%) translateY(0)');
            }, 10);

            // Auto dismiss after 3 seconds
            setTimeout(function () {
                $('.qilingshop-toast').css('transform', 'translateX(-50%) translateY(-100px)');
                setTimeout(function () {
                    $('.qilingshop-toast').remove();
                }, 400);
            }, 3000);
        },

        buyWithPoints: function (e) {
            e.preventDefault();

            var $btn = $(this);
            var $box = $btn.closest('.qls-download-content-tb-wrap, .qls-download-title-hd-wrap, .qls-download-sidebar-re-wrap');
            var postId = $box.data('post-id');
            var price = $btn.data('price') || 0;
            var scope = $box.attr('data-scope') || '';
            var upgradeFrom = $box.attr('data-upgrade-from') || '';
            var downloadIndex = QilingShop.getDownloadIndex($btn, $box);

            // Show custom confirm modal instead of browser confirm
            QilingShop.showConfirmModal(
                text('confirm', '确认购买？'),
                formatText('pointsDeduction', { price: price, pointsName: qilingshop.pointsName || text('pointsName', '积分') }, '将扣除 {price} {pointsName}'),
                function () {
                    QilingShop.setButtonLoading($btn, text('processing', '处理中...'));

                    QilingShop.ajax('buy_with_points', {
                        post_id: postId,
                        scope: scope,
                        upgrade_from: upgradeFrom,
                        download_index: downloadIndex
                    }, function (response) {
                        if (response.success) {
                            $btn.removeClass('is-loading').prop('disabled', true).text(text('purchaseSuccess', '购买成功'));
                            $btn.removeData('qlsOriginalText');
                            QilingShop.showMessage(response.message);
                            // Reload to show download
                            setTimeout(function () {
                                location.reload();
                            }, 1000);
                        } else {
                            QilingShop.clearButtonLoading($btn);
                            QilingShop.showMessage(response.message, 'error');
                        }
                    });
                }
            );
        },

        // Custom confirm modal
        showConfirmModal: function (title, message, onConfirm) {
            var html = '<div id="qilingshop-confirm-modal" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);display:flex;align-items:center;justify-content:center;z-index:99999;">' +
                '<div style="background:#fff;padding:30px;border-radius:16px;min-width:320px;max-width:400px;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,0.3);">' +
                '<h3 style="margin:0 0 15px;color:#333;font-size:20px;">💰 ' + title + '</h3>' +
                '<p style="margin:0 0 25px;color:#666;font-size:15px;">' + message + '</p>' +
                '<div style="display:flex;gap:10px;justify-content:center;">' +
                '<button id="qilingshop-confirm-yes" style="background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border:none;padding:12px 40px;border-radius:8px;font-size:15px;cursor:pointer;font-weight:500;">' + text('confirmPurchase', '确认购买') + '</button>' +
                '<button id="qilingshop-confirm-no" style="background:#f5f5f5;color:#666;border:none;padding:12px 30px;border-radius:8px;font-size:15px;cursor:pointer;">' + text('cancel', '取消') + '</button>' +
                '</div></div></div>';

            $('body').append(html);

            $('#qilingshop-confirm-yes').click(function () {
                $('#qilingshop-confirm-modal').remove();
                if (typeof onConfirm === 'function') {
                    onConfirm();
                }
            });

            $('#qilingshop-confirm-no').click(function () {
                $('#qilingshop-confirm-modal').remove();
            });
        },

        directPay: function (e) {
            e.preventDefault();

            var $btn = $(this);
            var $box = $btn.closest('.qls-download-content-tb-wrap, .qls-download-title-hd-wrap, .qls-download-sidebar-re-wrap');
            var postId = $box.data('post-id');
            var currentUrl = window.location.href; // 获取当前页面链接用于支付成功后返回
            var scope = $box.attr('data-scope') || '';
            var upgradeFrom = $box.attr('data-upgrade-from') || '';
            var downloadIndex = QilingShop.getDownloadIndex($btn, $box);

            // 显示支付网关选择弹窗
            QilingShop.showGatewayModal(postId, function (gateway, couponClaimId, guestContact) {
                QilingShop.setButtonLoading($btn, text('processing', '处理中...'));

                QilingShop.ajax('direct_pay', {
                    post_id: postId,
                    gateway: gateway,
                    coupon_claim_id: couponClaimId || '',
                    guest_phone: guestContact && guestContact.phone ? guestContact.phone : '',
                    guest_email: guestContact && guestContact.email ? guestContact.email : '',
                    redirect_url: currentUrl, // 传递返回地址
                    scope: scope,
                    upgrade_from: upgradeFrom,
                    download_index: downloadIndex
                }, function (response) {
                    if (response.success) {
                        // 处理 0 元订单（已直接完成）
                        if (response.data && response.data.paid) {
                            $btn.removeClass('is-loading').prop('disabled', true).text(text('purchaseSuccess', '购买成功'));
                            $btn.removeData('qlsOriginalText');
                            QilingShop.showMessage(response.message || text('purchaseSuccess', '购买成功'));
                            setTimeout(function () {
                                location.reload();
                            }, 1000);
                        } else if (response.data && response.data.pay_url) {
                            if (response.data.guest_binding_failed) {
                                QilingShop.showMessage(response.message || text('guestBindingFailed', '游客订单绑定失败，请在当前设备完成支付。'), 'error');
                                QilingShop.clearButtonLoading($btn);
                                setTimeout(function () {
                                    window.location.href = response.data.pay_url;
                                }, 1200);
                            } else {
                                // 直接跳转到支付页面
                                window.location.href = response.data.pay_url;
                            }
                        } else if (response.data && response.data.qrcode) {
                            QilingShop.clearButtonLoading($btn);
                            QilingShop.showQrcode(response.data.qrcode, response.data.order_no);
                        } else {
                            QilingShop.clearButtonLoading($btn);
                            QilingShop.showMessage(response.data ? response.data.message : text('paymentRequestFailed', '支付请求失败'), 'error');
                        }
                    } else {
                        QilingShop.clearButtonLoading($btn);
                        QilingShop.showMessage(response.data ? response.data.message : (response.message || text('paymentRequestFailed', '支付请求失败')), 'error');
                    }
                });
            });
        },

        showGatewayModal: function (postId, callback) {
            // 获取资源信息
            var $box = $('.qls-download-content-tb-wrap[data-post-id="' + postId + '"], .qls-download-title-hd-wrap[data-post-id="' + postId + '"], .qls-download-sidebar-re-wrap[data-post-id="' + postId + '"]');
            // 使用 RMB 价格（现金），不是积分
            var price = parseFloat($box.data('rmb')) || 0;
            var resourceTitle = $box.closest('article').find('.entry-title').text() || $('h1.entry-title').text() || document.title;
            var resourceDisplayTitle = resourceTitle.length > 30 ? resourceTitle.substring(0, 30) + '...' : resourceTitle;

            // 支付网关配置
            var gateways = qilingshop.gateways || {
                'alipay_qr': text('gatewayAlipayQr', '支付宝扫码支付'),
                'alipay_page': text('gatewayAlipayPage', '支付宝网页支付'),
                'wechat': text('gatewayWechat', '微信扫码支付'),
                'xhpay': text('gatewayXhpay', '虎皮椒 V3'),
                'epay': text('gatewayEpay', '易支付'),
                'paypal': 'PayPal',
                'stripe': 'Stripe'
            };

            var gatewayIcons = {
                'alipay': 'zfb.png',
                'alipay_qr': 'zfb.png',
                'alipay_page': 'zfb.png',
                'wechat': 'wx.png',
                'xhpay': 'zfb.png',
                'epay': 'zfb.png',
                'paypal': 'paypal.png',
                'stripe': 'stripe.png'
            };

            var gatewayHtml = '';
            var isFirst = true;
            for (var key in gateways) {
                if (gateways.hasOwnProperty(key)) {
                    var iconHtml = '';
                    if (gatewayIcons[key] && qilingshop.pluginUrl) {
                        iconHtml = '<img src="' + qilingshop.pluginUrl + 'static/img/' + gatewayIcons[key] + '" class="qpm-gateway-icon">';
                    }
                    var gatewayKey = QilingShop.escapeHtml(key);
                    var gatewayLabel = QilingShop.escapeHtml(gateways[key]);
                    gatewayHtml += '<label class="qpm-gateway-option' + (isFirst ? ' selected' : '') + '">' +
                        '<input type="radio" name="qilingshop_gateway" value="' + gatewayKey + '"' + (isFirst ? ' checked' : '') + '>' +
                        iconHtml + '<span>' + gatewayLabel + '</span></label>';
                    isFirst = false;
                }
            }

            var isGuest = !qilingshop.isLoggedIn;
            var guestContactHtml = '';
            if (isGuest) {
                guestContactHtml = '<div class="qpm-guest-contact-section">' +
                    '<div class="qpm-section-title"><span class="qpm-section-icon">锁</span><span>' + text('orderQueryVerification', '订单查询验证') + '</span></div>' +
                    '<div class="qpm-guest-contact-desc">' + text('guestContactDesc', '游客购买请填写手机号或邮箱，便于后续查询订单。') + '</div>' +
                    '<div class="qpm-guest-contact-grid">' +
                    '<input type="text" id="qpm-guest-phone" placeholder="' + QilingShop.escapeHtml(text('phoneOptional', '手机号（可选）')) + '" autocomplete="off">' +
                    '<input type="email" id="qpm-guest-email" placeholder="' + QilingShop.escapeHtml(text('emailOptional', '邮箱（可选）')) + '" autocomplete="off">' +
                    '</div>' +
                    '</div>';
            }

            // 构建弹窗结构
            var html = '<div id="qilingshop-gateway-modal" class="qpm-modal-overlay">' +
                '<div class="qpm-modal">' +
                // 头部
                '<div class="qpm-header">' +
                '<h3>' + text('confirmOrder', '确认订单') + '</h3>' +
                '<button type="button" class="qpm-close" id="qilingshop-gateway-cancel">&times;</button>' +
                '</div>' +

                '<div class="qpm-modal-body">' +

                // 资源信息
                '<div class="qpm-resource-info">' +
                '<div class="qpm-resource-title">' + QilingShop.escapeHtml(resourceDisplayTitle) + '</div>' +
                '<div class="qpm-resource-price">¥<span id="qpm-original-price">' + price.toFixed(2) + '</span></div>' +
                '</div>' +

                // 优惠券区域
                '<div class="qpm-coupon-section">' +
                '<button type="button" id="qpm-coupon-toggle" class="qpm-coupon-toggle" aria-expanded="false">' +
                '<span class="qpm-coupon-toggle-main"><span class="qpm-section-icon">券</span><span class="qpm-coupon-toggle-title">' + text('coupon', '优惠券') + '</span></span>' +
                '<span class="qpm-coupon-toggle-meta" id="qpm-coupon-status">' + text('loading', '加载中...') + '</span>' +
                '<span class="qpm-coupon-chevron">⌄</span>' +
                '</button>' +
                '<div id="qpm-selected-coupon" class="qpm-selected-coupon" style="display:none;">' +
                '<div class="qpm-coupon-info">' +
                '<span class="qpm-coupon-name"></span>' +
                '<span class="qpm-coupon-discount"></span>' +
                '</div>' +
                '<button type="button" class="qpm-coupon-remove">' + text('remove', '移除') + '</button>' +
                '</div>' +
                '<div id="qpm-coupon-panel" class="qpm-coupon-panel" hidden>' +
                '<div id="qpm-coupon-list" class="qpm-coupon-list">' +
                '<div class="qpm-loading">' + text('loading', '加载中...') + '</div>' +
                '</div>' +
                '</div>' +
                '<input type="hidden" id="qpm-coupon-claim-id" value="">' +
                '<input type="hidden" id="qpm-discount-amount" value="0">' +
                '</div>' +

                guestContactHtml +

                // 支付方式
                '<div class="qpm-gateway-section">' +
                '<div class="qpm-section-title"><span class="qpm-section-icon">卡</span><span>' + text('paymentMethod', '支付方式') + '</span></div>' +
                '<div class="qpm-gateway-list">' + gatewayHtml + '</div>' +
                '</div>' +

                // 价格汇总
                '<div class="qpm-price-summary">' +
                '<div class="qpm-price-row">' +
                '<span>' + text('productAmount', '商品金额') + '</span>' +
                '<span>¥' + price.toFixed(2) + '</span>' +
                '</div>' +
                '<div class="qpm-price-row qpm-discount-row" id="qpm-discount-row" style="display:none;">' +
                '<span>' + text('coupon', '优惠券') + '</span>' +
                '<span class="qpm-discount-value" id="qpm-discount-display">-¥0.00</span>' +
                '</div>' +
                '<div class="qpm-price-row qpm-total-row">' +
                '<span>' + text('finalAmount', '应付金额') + '</span>' +
                '<span class="qpm-total-value" id="qpm-final-price">¥' + price.toFixed(2) + '</span>' +
                '</div>' +
                '</div>' +
                '</div>' +

                // 按钮
                '<div class="qpm-actions">' +
                '<button type="button" id="qilingshop-gateway-confirm" class="qpm-btn-confirm">' + formatText('payNowAmount', { amount: '<span id="qpm-btn-price">' + price.toFixed(2) + '</span>' }, 'Pay now ¥{amount}') + '</button>' +
                '</div>' +
                '</div>' +
                '</div>';

            // 样式
            var style = '<style id="qpm-modal-styles">' +
                '.qpm-modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,0.62);display:flex;align-items:center;justify-content:center;z-index:99999;backdrop-filter:blur(4px);padding:16px;box-sizing:border-box;}' +
                '.qpm-modal{background:#fff;border-radius:16px;width:560px;max-width:100%;max-height:calc(100vh - 32px);max-height:min(760px,calc(100dvh - 32px));display:flex;flex-direction:column;overflow:hidden;box-shadow:0 20px 60px rgba(15,23,42,0.28);}' +
                '.qpm-modal-body{overflow-y:auto;overscroll-behavior:contain;}' +
                '.qpm-header{display:flex;justify-content:space-between;align-items:center;padding:18px 22px;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);}' +
                '.qpm-header h3{margin:0;font-size:19px;color:#fff;font-weight:700;line-height:1.3;}' +
                '.qpm-close{width:36px;height:36px;display:inline-flex;align-items:center;justify-content:center;background:rgba(255,255,255,0.12);border:none;border-radius:999px;font-size:26px;line-height:1;color:#fff;cursor:pointer;opacity:0.9;transition:opacity 0.2s,background 0.2s;}.qpm-close:hover{opacity:1;background:rgba(255,255,255,0.2);}' +
                '.qpm-resource-info{padding:18px 22px;background:#f8fafc;border-bottom:1px solid #edf0f5;display:flex;gap:14px;justify-content:space-between;align-items:flex-start;}' +
                '.qpm-resource-title{font-size:15px;color:#111827;font-weight:600;line-height:1.55;flex:1;min-width:0;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}' +
                '.qpm-resource-price{font-size:22px;color:#ef4444;font-weight:800;white-space:nowrap;line-height:1.3;}' +
                '.qpm-coupon-section,.qpm-gateway-section{padding:16px 22px;border-bottom:1px solid #edf0f5;}' +
                '.qpm-section-title{display:flex;align-items:center;gap:8px;font-size:15px;color:#4b5563;margin:0 0 12px;font-weight:600;line-height:1.4;}' +
                '.qpm-section-icon{width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;border-radius:7px;background:#eef2ff;color:#667eea;font-size:12px;font-weight:700;flex:0 0 auto;}' +
                '.qpm-coupon-toggle{width:100%;display:grid;grid-template-columns:minmax(0,1fr) auto auto;align-items:center;gap:10px;padding:12px 14px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;color:#111827;cursor:pointer;text-align:left;transition:border-color 0.2s,background 0.2s;}' +
                '.qpm-coupon-toggle:hover,.qpm-coupon-toggle.is-open{border-color:#667eea;background:#f6f7ff;}' +
                '.qpm-coupon-toggle-main{display:flex;align-items:center;gap:8px;min-width:0;}' +
                '.qpm-coupon-toggle-title{font-size:15px;font-weight:700;color:#374151;}' +
                '.qpm-coupon-toggle-meta{font-size:12px;color:#64748b;white-space:nowrap;}.qpm-coupon-toggle-meta.has-selected{color:#16a34a;font-weight:800;}' +
                '.qpm-coupon-chevron{font-size:16px;color:#94a3b8;transition:transform 0.2s;}.qpm-coupon-toggle.is-open .qpm-coupon-chevron{transform:rotate(180deg);}' +
                '.qpm-coupon-panel{margin-top:12px;}' +
                '.qpm-coupon-list{max-height:210px;overflow-y:auto;padding-right:2px;}' +
                '.qpm-loading{text-align:center;color:#94a3b8;padding:18px 12px;font-size:13px;}' +
                '.qpm-coupon-card{display:flex;align-items:stretch;border:1px solid #e5e7eb;border-radius:10px;margin-bottom:10px;overflow:hidden;cursor:pointer;transition:border-color 0.2s,background 0.2s;}' +
                '.qpm-coupon-card:hover:not(.disabled){border-color:#667eea;background:#fafbff;}' +
                '.qpm-coupon-card.selected{border-color:#667eea;background:#f0f3ff;}' +
                '.qpm-coupon-card.disabled{opacity:0.58;cursor:not-allowed;}' +
                '.qpm-coupon-left{background:linear-gradient(135deg,#ef4444,#f97316);color:#fff;padding:10px 12px;min-width:76px;display:flex;flex-direction:column;align-items:center;justify-content:center;}' +
                '.qpm-coupon-value{font-size:19px;font-weight:800;line-height:1.1;}.qpm-coupon-type{font-size:11px;opacity:0.9;margin-top:3px;}' +
                '.qpm-coupon-right{flex:1;min-width:0;padding:10px 12px;display:flex;flex-direction:column;justify-content:center;}' +
                '.qpm-coupon-name{font-size:14px;color:#111827;font-weight:600;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}' +
                '.qpm-coupon-desc{font-size:12px;color:#94a3b8;margin-bottom:4px;line-height:1.4;}' +
                '.qpm-coupon-limit{font-size:11px;color:#b45309;background:#fff7ed;padding:2px 6px;border-radius:5px;display:inline-block;align-self:flex-start;}' +
                '.qpm-coupon-invalid{font-size:11px;color:#dc2626;margin-top:4px;}' +
                '.qpm-no-coupon{text-align:center;color:#94a3b8;padding:18px 12px;font-size:13px;}' +
                '.qpm-selected-coupon{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 12px;background:#ecfdf5;border:1px solid #bbf7d0;border-radius:10px;margin-top:10px;}' +
                '.qpm-coupon-info{display:flex;gap:8px;align-items:center;min-width:0;}' +
                '.qpm-coupon-info .qpm-coupon-name{color:#166534;font-weight:700;margin:0;}' +
                '.qpm-coupon-info .qpm-coupon-discount{color:#16a34a;font-weight:800;white-space:nowrap;}' +
                '.qpm-coupon-remove{background:none;border:none;color:#64748b;cursor:pointer;font-size:13px;white-space:nowrap;}.qpm-coupon-remove:hover{color:#dc2626;}' +
                '.qpm-guest-contact-section{padding:16px 22px;border-bottom:1px solid #edf0f5;background:#f8fbff;}' +
                '.qpm-guest-contact-desc{font-size:13px;color:#64748b;margin:0 0 12px;line-height:1.65;}' +
                '.qpm-guest-contact-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}' +
                '.qpm-guest-contact-grid input{width:100%;height:44px;padding:10px 12px;border:1px solid #dbe3ee;border-radius:10px;font-size:14px;box-sizing:border-box;}' +
                '.qpm-guest-contact-grid input:focus{outline:none;border-color:#667eea;box-shadow:0 0 0 3px rgba(102,126,234,0.14);}' +
                '.qpm-gateway-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;}' +
                '.qpm-gateway-option{display:flex;align-items:center;gap:10px;min-height:54px;padding:11px 13px;border:1px solid #e5e7eb;border-radius:10px;cursor:pointer;transition:border-color 0.2s,background 0.2s,box-shadow 0.2s;box-sizing:border-box;}' +
                '.qpm-gateway-option:hover{border-color:#667eea;background:#f8f9ff;}' +
                '.qpm-gateway-option.selected{border-color:#667eea;background:#f0f3ff;box-shadow:0 0 0 1px #667eea inset;}' +
                '.qpm-gateway-option input{display:none;}' +
                '.qpm-gateway-icon{width:28px;height:28px;border-radius:6px;object-fit:contain;flex:0 0 auto;}' +
                '.qpm-gateway-option span{font-size:14px;color:#111827;line-height:1.35;min-width:0;}' +
                '.qpm-price-summary{padding:16px 22px;background:#f8fafc;}' +
                '.qpm-price-row{display:flex;justify-content:space-between;gap:16px;margin-bottom:8px;font-size:14px;color:#4b5563;}' +
                '.qpm-discount-value{color:#16a34a;}' +
                '.qpm-total-row{margin-top:10px;padding-top:12px;border-top:1px dashed #d8dee9;font-size:17px;color:#111827;font-weight:800;}' +
                '.qpm-total-value{color:#ef4444;font-size:22px;}' +
                '.qpm-actions{flex:0 0 auto;padding:16px 22px 18px;background:#fff;border-top:1px solid #edf0f5;box-shadow:0 -8px 22px rgba(15,23,42,0.06);}' +
                '.qpm-btn-confirm{width:100%;min-height:52px;padding:13px 16px;font-size:16px;font-weight:700;color:#fff;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);border:none;border-radius:12px;cursor:pointer;transition:box-shadow 0.2s,transform 0.2s;}.qpm-btn-confirm:hover{transform:translateY(-1px);box-shadow:0 8px 22px rgba(102,126,234,0.32);}' +
                '@media(max-width:560px){.qpm-modal-overlay{align-items:flex-end;padding:10px;}.qpm-modal{width:100%;max-height:calc(100vh - 20px);max-height:calc(100dvh - 20px);border-radius:14px;}.qpm-header{padding:15px 16px;}.qpm-header h3{font-size:18px;}.qpm-resource-info,.qpm-coupon-section,.qpm-gateway-section,.qpm-guest-contact-section,.qpm-price-summary{padding:14px 16px;}.qpm-gateway-list{grid-template-columns:1fr;}.qpm-guest-contact-grid{grid-template-columns:1fr;}.qpm-resource-price{font-size:20px;}.qpm-actions{padding:13px 16px 14px;}.qpm-coupon-toggle{grid-template-columns:minmax(0,1fr) auto;}.qpm-coupon-toggle-meta{grid-column:1 / 2;margin-left:30px;}.qpm-coupon-chevron{grid-column:2;grid-row:1 / span 2;}.qpm-coupon-list{max-height:185px;}}' +
                '</style>';

            $('body').append(style + html);

            var selectedCoupon = null;
            var currentDiscount = 0;
            var availableCouponCount = 0;

            function setCouponPanelOpen(open) {
                $('#qpm-coupon-panel').prop('hidden', !open);
                $('#qpm-coupon-toggle')
                    .toggleClass('is-open', open)
                    .attr('aria-expanded', open ? 'true' : 'false');
            }

            function updateCouponStatus() {
                var statusText = '';
                if (selectedCoupon) {
                    statusText = '-¥' + currentDiscount.toFixed(2);
                } else if (availableCouponCount > 0) {
                    statusText = formatText('availableCouponsCount', { count: availableCouponCount }, '{count} 张可用');
                } else {
                    statusText = text('noCoupons', '暂无可用优惠券');
                }

                $('#qpm-coupon-status')
                    .text(statusText)
                    .toggleClass('has-selected', !!selectedCoupon);
            }

            $('#qpm-coupon-toggle').on('click', function () {
                setCouponPanelOpen(!$(this).hasClass('is-open'));
            });

            function closeGatewayModal() {
                $('#qpm-modal-styles').remove();
                $('#qilingshop-gateway-modal').remove();
            }

            // 更新价格显示
            function updatePriceDisplay() {
                var finalPrice = Math.max(0, price - currentDiscount);

                if (finalPrice <= 0) {
                    // 0元订单：显示免费
                    $('#qpm-final-price').text(text('free', '免费'));
                    $('#qpm-btn-price').text('0.00');
                    $('#qilingshop-gateway-confirm').text(text('getNow', '立即领取'));
                } else {
                    $('#qpm-final-price').text('¥' + finalPrice.toFixed(2));
                    $('#qpm-btn-price').text(finalPrice.toFixed(2));
                    $('#qilingshop-gateway-confirm').html(formatText('payNowAmount', { amount: '<span id="qpm-btn-price">' + finalPrice.toFixed(2) + '</span>' }, 'Pay now ¥{amount}'));
                }

                if (currentDiscount > 0) {
                    $('#qpm-discount-display').text('-¥' + currentDiscount.toFixed(2));
                    $('#qpm-discount-row').show();
                } else {
                    $('#qpm-discount-row').hide();
                }
            }

            // 计算折扣
            function calculateDiscount(coupon) {
                var discount = 0;
                if (coupon.discount_type === 'fixed') {
                    discount = parseFloat(coupon.discount_value);
                } else {
                    discount = price * (parseFloat(coupon.discount_value) / 100);
                    if (coupon.max_discount && parseFloat(coupon.max_discount) > 0) {
                        discount = Math.min(discount, parseFloat(coupon.max_discount));
                    }
                }
                if (price > 0 && discount > price) discount = price;
                return discount;
            }

            // 加载优惠券
            var couponAjaxUrl = (typeof qlsCoupon !== 'undefined' && qlsCoupon.ajaxUrl) ? qlsCoupon.ajaxUrl : qilingshop.ajaxUrl;
            var couponNonce = (typeof qlsCoupon !== 'undefined' && qlsCoupon.nonce) ? qlsCoupon.nonce : qilingshop.couponNonce;

            if (couponAjaxUrl && couponNonce) {
                $.post(couponAjaxUrl, {
                    action: 'qls_get_available_coupons',
                    order_type: 'resource',
                    amount: price,
                    items: [postId],
                    nonce: couponNonce,
                    _t: Date.now() // 防止缓存
                }, function (res) {
                    if (res.success && res.data.coupons && res.data.coupons.length > 0) {
                        var coupons = res.data.coupons;
                        var listHtml = '';
                        availableCouponCount = 0;

                        coupons.forEach(function (c) {
                            var isDisabled = c.is_valid === false;
                            if (!isDisabled) {
                                availableCouponCount++;
                            }
                            var valueText = c.discount_type === 'fixed' ? '¥' + parseInt(c.discount_value) : c.discount_value + '%';
                            var typeText = c.discount_type === 'fixed' ? text('fixedCoupon', '满减券') : text('discountCoupon', '折扣券');
                            var limitText = c.min_amount > 0 ? formatText('couponMinAmount', { amount: parseInt(c.min_amount) }, '满 ¥{amount} 可用') : text('noThreshold', '无门槛');
                            var couponName = QilingShop.escapeHtml(c.name || '');
                            var couponDesc = QilingShop.escapeHtml(c.description || '');
                            var invalidReason = QilingShop.escapeHtml(c.invalid_reason || '');

                            listHtml += '<div class="qpm-coupon-card' + (isDisabled ? ' disabled' : '') + '" ' +
                                'data-claim-id="' + c.claim_id + '" ' +
                                'data-name="' + couponName + '" ' +
                                'data-discount-type="' + c.discount_type + '" ' +
                                'data-discount-value="' + c.discount_value + '" ' +
                                'data-max-discount="' + (c.max_discount || 0) + '">' +
                                '<div class="qpm-coupon-left">' +
                                '<div class="qpm-coupon-value">' + valueText + '</div>' +
                                '<div class="qpm-coupon-type">' + typeText + '</div>' +
                                '</div>' +
                                '<div class="qpm-coupon-right">' +
                                '<div class="qpm-coupon-name">' + couponName + '</div>' +
                                '<div class="qpm-coupon-desc">' + couponDesc + '</div>' +
                                '<div class="qpm-coupon-limit">' + limitText + '</div>' +
                                (isDisabled && c.invalid_reason ? '<div class="qpm-coupon-invalid">' + invalidReason + '</div>' : '') +
                                '</div>' +
                                '</div>';
                        });

                        $('#qpm-coupon-list').html(listHtml);
                        updateCouponStatus();

                        // 选择优惠券
                        $('.qpm-coupon-card:not(.disabled)').on('click', function () {
                            var $this = $(this);
                            if ($this.hasClass('selected')) {
                                // 取消选择
                                $this.removeClass('selected');
                                selectedCoupon = null;
                                currentDiscount = 0;
                                $('#qpm-coupon-claim-id').val('');
                                $('#qpm-selected-coupon').hide();
                                $('#qpm-coupon-list').show();
                                updateCouponStatus();
                            } else {
                                // 选择
                                $('.qpm-coupon-card').removeClass('selected');
                                $this.addClass('selected');
                                selectedCoupon = {
                                    claim_id: $this.data('claim-id'),
                                    name: $this.data('name'),
                                    discount_type: $this.data('discount-type'),
                                    discount_value: $this.data('discount-value'),
                                    max_discount: $this.data('max-discount')
                                };
                                currentDiscount = calculateDiscount(selectedCoupon);
                                $('#qpm-coupon-claim-id').val(selectedCoupon.claim_id);
                                $('#qpm-discount-amount').val(currentDiscount);
                                $('#qpm-selected-coupon .qpm-coupon-name').text(selectedCoupon.name);
                                $('#qpm-selected-coupon .qpm-coupon-discount').text('-¥' + currentDiscount.toFixed(2));
                                $('#qpm-selected-coupon').css('display', 'flex');
                                updateCouponStatus();
                                setCouponPanelOpen(false);
                            }
                            updatePriceDisplay();
                        });

                    } else {
                        availableCouponCount = 0;
                        $('#qpm-coupon-list').html('<div class="qpm-no-coupon">' + text('noCoupons', '暂无可用优惠券') + '</div>');
                        updateCouponStatus();
                    }
                }).fail(function () {
                    availableCouponCount = 0;
                    $('#qpm-coupon-list').html('<div class="qpm-no-coupon">' + text('noCoupons', '暂无可用优惠券') + '</div>');
                    updateCouponStatus();
                });
            } else {
                availableCouponCount = 0;
                $('#qpm-coupon-list').html('<div class="qpm-no-coupon">' + text('noCoupons', '暂无可用优惠券') + '</div>');
                updateCouponStatus();
            }

            // 移除优惠券
            $('#qilingshop-gateway-modal').on('click', '.qpm-coupon-remove', function () {
                selectedCoupon = null;
                currentDiscount = 0;
                $('#qpm-coupon-claim-id').val('');
                $('.qpm-coupon-card').removeClass('selected');
                $('#qpm-selected-coupon').hide();
                $('#qpm-coupon-list').show();
                updateCouponStatus();
                updatePriceDisplay();
            });

            // 选择支付方式
            $('#qilingshop-gateway-modal').on('click', '.qpm-gateway-option', function () {
                $('.qpm-gateway-option').removeClass('selected');
                $(this).addClass('selected');
                $(this).find('input').prop('checked', true);
            });

            // 确认支付
            $('#qilingshop-gateway-confirm').click(function () {
                var gateway = $('input[name="qilingshop_gateway"]:checked').val() || 'alipay';
                var couponClaimId = $('#qpm-coupon-claim-id').val();
                var guestContact = { phone: '', email: '' };

                if (isGuest) {
                    guestContact.phone = $.trim($('#qpm-guest-phone').val() || '');
                    guestContact.email = $.trim($('#qpm-guest-email').val() || '');

                    if (!guestContact.phone && !guestContact.email) {
                        QilingShop.showMessage(text('guestContactRequired', '游客购买需填写手机号或邮箱。'), 'error');
                        return;
                    }
                }

                closeGatewayModal();
                callback(gateway, couponClaimId, guestContact);
            });

            // 取消/关闭
            $('#qilingshop-gateway-cancel').click(function () {
                closeGatewayModal();
            });

            // 点击背景关闭
            $('#qilingshop-gateway-modal').on('click', function (e) {
                if (e.target === this) {
                    closeGatewayModal();
                }
            });
        },

        checkin: function (e) {
            e.preventDefault();

            var $btn = $(this);

            if ($btn.hasClass('checked')) {
                return;
            }

            $btn.prop('disabled', true);

            QilingShop.ajax('checkin', {}, function (response) {
                if (response.success) {
                    QilingShop.showMessage(response.message);
                    $btn.addClass('checked').text(text('checkedIn', '已签到'));
                } else {
                    QilingShop.showMessage(response.message, 'error');
                    $btn.prop('disabled', false);
                }
            });
        },

        buyVip: function (e) {
            e.preventDefault();

            var $btn = $(this);
            var levelId = $btn.data('level');

            if (!confirm(text('confirm', '确认购买？'))) {
                return;
            }

            QilingShop.setButtonLoading($btn, text('processing', '处理中...'));

            QilingShop.ajax('buy_vip', {
                level_id: levelId
            }, function (response) {
                if (response.success) {
                    $btn.removeClass('is-loading').prop('disabled', true).text(text('purchaseSuccess', '购买成功'));
                    $btn.removeData('qlsOriginalText');
                    QilingShop.showMessage(response.message);
                    location.reload();
                } else {
                    QilingShop.clearButtonLoading($btn);
                    QilingShop.showMessage(response.message, 'error');
                }
            });
        },

        recharge: function (e) {
            e.preventDefault();

            var $btn = $(this);
            var amount = $('#recharge-amount').val();
            var gateway = $('input[name="gateway"]:checked').val();

            if (!amount || amount <= 0) {
                QilingShop.showMessage(text('enterRechargeAmount', '请输入充值金额'), 'error');
                return;
            }

            QilingShop.setButtonLoading($btn, text('processing', '处理中...'));

            QilingShop.ajax('recharge', {
                amount: amount,
                gateway: gateway
            }, function (response) {
                if (response.success) {
                    if (response.data.pay_url) {
                        window.location.href = response.data.pay_url;
                    } else if (response.data.qrcode) {
                        QilingShop.clearButtonLoading($btn);
                        QilingShop.showQrcode(response.data.qrcode);

                        // 轮询检查支付状态
                        var checkInterval = setInterval(function () {
                            $.ajax({
                                type: 'POST',
                                url: qilingshop.ajaxUrl,
                                dataType: 'json',
                                data: {
                                    action: 'qilingshop_check_order',
                                    order_no: response.data.order_no,
                                    type: 'recharge',
                                    nonce: qilingshop.nonce,
                                    poll_token: response.data.poll_token || ''
                                },
                                success: function (res) {
                                    if (res && res.success && res.data && res.data.paid) {
                                        clearInterval(checkInterval);
                                        QilingShop.showMessage(text('rechargeSuccess', '充值成功'), 'success');
                                        setTimeout(function () {
                                            location.reload();
                                        }, 1000);
                                    }
                                }
                            });
                        }, 3000);
                    }
                } else {
                    QilingShop.clearButtonLoading($btn);
                    QilingShop.showMessage(response.message, 'error');
                }
            });
        },

        selectQuickAmount: function (e) {
            var amount = $(this).data('amount');
            $('#recharge-amount').val(amount).trigger('input');
        },

        updatePointsPreview: function () {
            var amount = parseFloat($(this).val()) || 0;
            var ratio = parseInt(qilingshop.pointsRatio) || 10;
            var points = amount * ratio;
            $('#points-preview').text(points);
        },

        showQrcode: function (qrcode) {
            // Simple modal for QR code
            var html = '<div id="qilingshop-qrcode-modal" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);display:flex;align-items:center;justify-content:center;z-index:99999;">' +
                '<div style="background:#fff;padding:30px;border-radius:10px;text-align:center;">' +
                '<p>' + text('scanToPay', '扫码支付') + '</p>' +
                '<img src="' + qrcode + '" style="max-width:200px;">' +
                '<p><button onclick="document.getElementById(\'qilingshop-qrcode-modal\').remove()">' + text('close', '关闭') + '</button></p>' +
                '</div></div>';
            $('body').append(html);
        }
    };

    $(document).ready(function () {
        QilingShop.init();
    });

})(jQuery);
