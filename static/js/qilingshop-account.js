/**
 * QilingShop 个人中心 JS
 * 
 * @package QilingShop
 */

(function ($) {
    'use strict';

    // 配置
    var config = window.qilingshopAccount || {};

    function text(key, fallback) {
        var i18n = config.i18n || {};
        return i18n[key] || fallback || key;
    }

    // 工具函数：显示消息
    function showMessage(message, type) {
        var $msg = $('<div class="qls-toast ' + (type || 'info') + '">' + message + '</div>');
        $('body').append($msg);
        setTimeout(function () {
            $msg.addClass('show');
        }, 10);
        setTimeout(function () {
            $msg.removeClass('show');
            setTimeout(function () { $msg.remove(); }, 300);
        }, 3000);
    }

    // 工具函数：复制到剪贴板
    function copyToClipboard(text) {
        if (navigator.clipboard) {
            return navigator.clipboard.writeText(text);
        }
        // 兜底
        var temp = document.createElement('textarea');
        temp.value = text;
        document.body.appendChild(temp);
        temp.select();
        document.execCommand('copy');
        document.body.removeChild(temp);
        return Promise.resolve();
    }

    function getBaseUrl() {
        return window.location.href.split('#')[0];
    }

    function requestLazyList($container, page) {
        var listType = $container.data('qls-list');
        if (!listType) {
            return;
        }

        var action = listType === 'commission' ? 'qilingshop_commission_log' : 'qilingshop_invite_list';
        var baseUrl = getBaseUrl();
        $container.addClass('is-loading');
        $container.html('<div class="qls-empty-state qls-empty-sm"><p>' + text('loading', '加载中...') + '</p></div>');

        $.post(config.ajaxUrl, {
            action: action,
            nonce: config.nonce,
            paged: page || 1,
            base_url: baseUrl
        }).done(function (res) {
            if (res && res.success && res.data && res.data.html) {
                $container.html(res.data.html);
            } else {
                $container.html('<div class="qls-empty-state qls-empty-sm"><p>' + (res?.message || text('loadFailed', '加载失败')) + '</p></div>');
            }
        }).fail(function () {
            $container.html('<div class="qls-empty-state qls-empty-sm"><p>' + text('loadFailed', '加载失败') + '</p></div>');
        }).always(function () {
            $container.removeClass('is-loading');
        });
    }

    function initLazyLists() {
        var $lists = $('.js-qls-lazy-list');
        if ($lists.length === 0 || !config.ajaxUrl || !config.nonce) {
            return;
        }

        if ('IntersectionObserver' in window) {
            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        var $target = $(entry.target);
                        var page = parseInt($target.data('page'), 10) || 1;
                        requestLazyList($target, page);
                        observer.unobserve(entry.target);
                    }
                });
            }, { rootMargin: '120px' });

            $lists.each(function () {
                observer.observe(this);
            });
        } else {
            $lists.each(function () {
                var $target = $(this);
                var page = parseInt($target.data('page'), 10) || 1;
                requestLazyList($target, page);
            });
        }
    }

    // 初始化
    $(document).ready(function () {
        // 复制按钮
        $('.qls-copy-btn').on('click', function () {
            var $btn = $(this);
            var targetId = $btn.data('target');
            var $target = $('#' + targetId);
            var text = $target.is('input') ? $target.val() : $target.text();

            copyToClipboard(text).then(function () {
                $btn.text('✓');
                setTimeout(function () { $btn.text('📋'); }, 2000);
                showMessage(text('copied', '已复制'), 'success');
            }).catch(function () {
                showMessage(text('copyFailed', '复制失败'), 'error');
            });
        });

        // 初始化懒加载列表
        initLazyLists();

        // 分页点击（懒加载模式）
        $(document).on('click', '.qls-page-link[data-qls-page]', function (e) {
            var $link = $(this);
            var $container = $link.closest('.js-qls-lazy-list');
            if ($container.length === 0) {
                return;
            }
            e.preventDefault();
            var page = parseInt($link.data('qls-page'), 10) || 1;
            $container.data('page', page);
            requestLazyList($container, page);
        });
    });

    // 暴露给全局
    window.QilingShopAccount = {
        showMessage: showMessage,
        copyToClipboard: copyToClipboard
    };

})(jQuery);
