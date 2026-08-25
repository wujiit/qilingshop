/**
 * QilingShop Trade Feed
 */
(function ($) {
    'use strict';

    var config = window.qilingshopTradeFeed || {};
    if (!config.enabled) {
        return;
    }

    var state = {
        items: [],
        lastIndex: -1,
        timer: null,
        isFetching: false,
        cycleCount: 0,
        root: null,
        card: null,
        avatar: null,
        text: null
    };

    function clampInt(value, min, max, fallback) {
        var parsed = parseInt(value, 10);
        if (isNaN(parsed)) {
            parsed = fallback;
        }
        if (parsed < min) {
            parsed = min;
        }
        if (parsed > max) {
            parsed = max;
        }
        return parsed;
    }

    var intervalMin = clampInt(config.intervalMin, 2, 60, 4);
    var intervalMax = clampInt(config.intervalMax, 2, 60, 8);
    if (intervalMax < intervalMin) {
        intervalMax = intervalMin;
    }
    var batchSize = clampInt(config.batchSize, 5, 50, 20);

    function randomInt(min, max) {
        return Math.floor(Math.random() * (max - min + 1)) + min;
    }

    function buildDom() {
        var html = ''
            + '<div id="qls-trade-feed" class="qls-trade-feed" aria-live="polite" aria-atomic="true">'
            + '  <div class="qls-trade-feed-card is-type-resource">'
            + '      <span class="qls-trade-feed-avatar-wrap">'
            + '          <img class="qls-trade-feed-avatar" src="" alt="">'
            + '      </span>'
            + '      <span class="qls-trade-feed-text"></span>'
            + '  </div>'
            + '</div>';

        $('body').append(html);

        state.root = $('#qls-trade-feed');
        state.card = state.root.find('.qls-trade-feed-card');
        state.avatar = state.root.find('.qls-trade-feed-avatar');
        state.text = state.root.find('.qls-trade-feed-text');
    }

    function normalizeItems(items) {
        if (!Array.isArray(items)) {
            return [];
        }

        var cleaned = [];
        for (var i = 0; i < items.length; i++) {
            var item = items[i] || {};
            var text = typeof item.text === 'string' ? item.text.trim() : '';
            var type = typeof item.type === 'string' ? item.type : 'resource';
            var avatar = typeof item.avatar === 'string' ? item.avatar : '';
            if (!text || !avatar) {
                continue;
            }
            cleaned.push({
                id: item.id || ('feed-' + i),
                type: type,
                text: text,
                avatar: avatar
            });
        }

        return cleaned;
    }

    function fetchItems(done) {
        if (state.isFetching) {
            if (typeof done === 'function') {
                done();
            }
            return;
        }

        state.isFetching = true;
        $.post(config.ajaxUrl, {
            action: 'qilingshop_trade_feed',
            nonce: config.nonce,
            limit: batchSize
        }, null, 'json')
            .done(function (response) {
                var data = response && response.data ? response.data : {};
                state.items = normalizeItems(data.items || []);
                state.lastIndex = -1;
                state.cycleCount = 0;
            })
            .fail(function () {
                state.items = [];
                state.lastIndex = -1;
            })
            .always(function () {
                state.isFetching = false;
                if (typeof done === 'function') {
                    done();
                }
            });
    }

    function pickNextItem() {
        if (!state.items.length) {
            return null;
        }

        if (state.items.length === 1) {
            state.lastIndex = 0;
            return state.items[0];
        }

        var index = state.lastIndex;
        var guard = 0;
        while (index === state.lastIndex && guard < 10) {
            index = Math.floor(Math.random() * state.items.length);
            guard++;
        }

        state.lastIndex = index;
        return state.items[index] || null;
    }

    function applyItem(item) {
        if (!item) {
            return;
        }

        var typeClass = 'is-type-' + item.type;
        state.card
            .removeClass('is-type-resource is-type-shop is-type-recharge is-type-vip')
            .addClass(typeClass);

        state.avatar.attr('src', item.avatar);
        state.text.text(item.text);
    }

    function showItem(item, immediate) {
        if (!item) {
            return;
        }

        if (immediate) {
            applyItem(item);
            state.root.addClass('is-visible');
            return;
        }

        state.root.removeClass('is-visible');
        setTimeout(function () {
            applyItem(item);
            state.root.addClass('is-visible');
        }, 180);
    }

    function scheduleNext() {
        clearTimeout(state.timer);
        state.timer = setTimeout(runCycle, randomInt(intervalMin, intervalMax) * 1000);
    }

    function runCycle() {
        if (!state.items.length) {
            state.root.removeClass('is-visible');
            fetchItems(function () {
                if (state.items.length) {
                    showItem(pickNextItem(), true);
                    scheduleNext();
                } else {
                    clearTimeout(state.timer);
                }
            });
            return;
        }

        showItem(pickNextItem(), false);
        state.cycleCount++;

        if (state.cycleCount >= state.items.length) {
            fetchItems();
        }

        scheduleNext();
    }

    $(function () {
        buildDom();
        fetchItems(function () {
            if (!state.items.length) {
                return;
            }
            showItem(pickNextItem(), true);
            scheduleNext();
        });
    });
})(jQuery);
