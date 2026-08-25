/**
 * QilingShop Admin Statistics Chart
 */
(function() {
    'use strict';

    function toArray(value) {
        return Array.isArray(value) ? value : [];
    }

    function text(i18n, key, fallback) {
        return (i18n && i18n[key]) || fallback || key;
    }

    function initStatisticsChart() {
        var canvas = document.getElementById('qilingshop-chart');
        if (!canvas || typeof window.Chart === 'undefined') {
            return;
        }

        var payload = window.qilingshopStatsChart || {};
        var labels = toArray(payload.labels);
        var incomes = toArray(payload.incomes);
        var recharges = toArray(payload.recharges);
        var downloads = toArray(payload.downloads);
        var i18n = payload.i18n || {};
        var ctx = canvas.getContext('2d');

        if (!ctx) {
            return;
        }

        if (canvas.__qlsChartInstance && typeof canvas.__qlsChartInstance.destroy === 'function') {
            canvas.__qlsChartInstance.destroy();
        }

        canvas.__qlsChartInstance = new window.Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: text(i18n, 'orderIncome', '订单收入'),
                        data: incomes,
                        borderColor: '#2271b1',
                        backgroundColor: 'rgba(34, 113, 177, 0.1)',
                        fill: true,
                        tension: 0.3
                    },
                    {
                        label: text(i18n, 'recharge', '充值金额'),
                        data: recharges,
                        borderColor: '#00a32a',
                        backgroundColor: 'rgba(0, 163, 42, 0.1)',
                        fill: true,
                        tension: 0.3
                    },
                    {
                        label: text(i18n, 'downloads', '下载次数'),
                        data: downloads,
                        borderColor: '#d97706',
                        backgroundColor: 'rgba(217, 119, 6, 0.08)',
                        fill: false,
                        tension: 0.3,
                        yAxisID: 'downloads'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true
                    },
                    downloads: {
                        beginAtZero: true,
                        position: 'right',
                        grid: {
                            drawOnChartArea: false
                        },
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initStatisticsChart);
    } else {
        initStatisticsChart();
    }
})();
