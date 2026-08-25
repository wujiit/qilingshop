/**
 * QilingShop Admin JS
 */
(function($) {
    'use strict';

    function addClassBySelector($context, selector, className) {
        $context
            .filter(selector)
            .add($context.find(selector))
            .addClass(className);
    }

    function applyAdminUiClasses(context) {
        if (!$('body').hasClass('qilingshop-admin-shell')) {
            return;
        }
        var $ctx = context ? $(context) : $(document.body);
        if (!$ctx.length) {
            return;
        }

        addClassBySelector($ctx, '.wrap', 'qilingshop-admin-page');
        addClassBySelector($ctx, 'nav.nav-tab-wrapper, .nav-tab-wrapper', 'qls-ui-tabs');
        addClassBySelector($ctx, 'table.form-table', 'qls-ui-form-table');
        addClassBySelector($ctx, 'table.wp-list-table, table.widefat', 'qls-ui-table');
        addClassBySelector($ctx, '.button', 'qls-ui-btn');
        addClassBySelector($ctx, '.button-primary', 'qls-ui-btn-primary');
        addClassBySelector($ctx, '.button-link-delete, .submitdelete', 'qls-ui-btn-danger');
    }

    function isSafeCssColor(value) {
        if (typeof value !== 'string') {
            return false;
        }
        var color = $.trim(value);
        return /^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/.test(color);
    }

    function applyVipBadgeColors(context) {
        var $ctx = context ? $(context) : $(document.body);
        if (!$ctx.length) {
            return;
        }

        $ctx
            .filter('.qls-admin-vip-level-name[data-badge-color]')
            .add($ctx.find('.qls-admin-vip-level-name[data-badge-color]'))
            .each(function() {
                var color = $(this).data('badgeColor');
                if (isSafeCssColor(color)) {
                    $(this).css('color', color);
                }
            });
    }

    function copyTextToClipboard(text, done, fail) {
        if (!text) {
            fail();
            return;
        }

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done).catch(fail);
            return;
        }

        var temp = document.createElement('textarea');
        temp.value = text;
        temp.setAttribute('readonly', 'readonly');
        temp.style.position = 'fixed';
        temp.style.left = '-9999px';
        document.body.appendChild(temp);
        temp.select();

        try {
            document.execCommand('copy') ? done() : fail();
        } catch (e) {
            fail();
        }

        document.body.removeChild(temp);
    }

    $(document).ready(function() {
        applyAdminUiClasses(document);
        applyVipBadgeColors(document);

        if (!window.__qilingshopAdminUiObserver && window.MutationObserver && document.body) {
            window.__qilingshopAdminUiObserver = true;
            var pendingNodes = [];
            var flushScheduled = false;
            var flushPendingNodes = function() {
                flushScheduled = false;
                if (!pendingNodes.length) {
                    return;
                }
                var nodes = pendingNodes.slice();
                pendingNodes = [];
                nodes.forEach(function(node) {
                    applyAdminUiClasses(node);
                    applyVipBadgeColors(node);
                });
            };
            var scheduleFlush = function() {
                if (flushScheduled) {
                    return;
                }
                flushScheduled = true;
                if (window.requestAnimationFrame) {
                    window.requestAnimationFrame(flushPendingNodes);
                } else {
                    window.setTimeout(flushPendingNodes, 0);
                }
            };

            var observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.addedNodes && mutation.addedNodes.length) {
                        mutation.addedNodes.forEach(function(node) {
                            if (node.nodeType === 1) {
                                pendingNodes.push(node);
                            }
                        });
                    }
                });
                scheduleFlush();
            });

            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
        }

        // Confirm dialogs
        $('[data-confirm]').on('click', function(e) {
            if (!confirm($(this).data('confirm') || qilingshopAdmin.i18n.confirm)) {
                e.preventDefault();
            }
        });

        $(document).on('click', '.qls-copy-btn[data-target]', function(e) {
            var btn = this;
            var $btn = $(btn);
            var targetId = String($btn.data('target') || '');
            var target = targetId ? document.getElementById(targetId) : null;
            if (!target) {
                return;
            }

            e.preventDefault();

            var text = target.value || target.textContent || '';
            var originalText = btn.textContent || '';
            var restore = function(mark) {
                btn.textContent = mark;
                window.setTimeout(function() {
                    btn.textContent = originalText;
                }, 1500);
            };

            copyTextToClipboard(
                text,
                function() { restore('✓'); },
                function() { restore('×'); }
            );
        });
    });

})(jQuery);
