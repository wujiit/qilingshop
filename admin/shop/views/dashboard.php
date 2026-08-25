<?php
/**
 * 商城仪表盘视图
 */
if (!defined('ABSPATH')) exit;
?>
<div class="wrap qilingshop-admin-page qls-shop-wrap">
    <div class="qls-page-header">
        <h1 class="qls-page-title"><?php _e('实物商城', 'qilingshop'); ?></h1>
    </div>
    
    <div class="qls-shop-dashboard">
        <!-- 统计卡片 -->
        <div class="qls-stats-grid">
            <div class="qls-stat-card">
                <div class="qls-stat-icon products">
                    <span class="dashicons dashicons-cart"></span>
                </div>
                <div class="qls-stat-content">
                    <div class="qls-stat-value"><?php echo esc_html($stats['products_active']); ?></div>
                    <div class="qls-stat-label"><?php _e('在售商品', 'qilingshop'); ?></div>
                </div>
                <a href="<?php echo admin_url('admin.php?page=qls-products'); ?>" class="qls-stat-link">
                    <?php _e('管理', 'qilingshop'); ?> →
                </a>
            </div>
            
            <div class="qls-stat-card">
                <div class="qls-stat-icon orders-pending">
                    <span class="dashicons dashicons-clock"></span>
                </div>
                <div class="qls-stat-content">
                    <div class="qls-stat-value"><?php echo esc_html($stats['orders_pending']); ?></div>
                    <div class="qls-stat-label"><?php _e('待付款订单', 'qilingshop'); ?></div>
                </div>
                <a href="<?php echo admin_url('admin.php?page=qls-shop-orders&status=0'); ?>" class="qls-stat-link">
                    <?php _e('查看', 'qilingshop'); ?> →
                </a>
            </div>
            
            <div class="qls-stat-card">
                <div class="qls-stat-icon orders-paid">
                    <span class="dashicons dashicons-yes"></span>
                </div>
                <div class="qls-stat-content">
                    <div class="qls-stat-value"><?php echo esc_html($stats['orders_paid']); ?></div>
                    <div class="qls-stat-label"><?php _e('待发货订单', 'qilingshop'); ?></div>
                </div>
                <a href="<?php echo admin_url('admin.php?page=qls-shop-orders&status=1'); ?>" class="qls-stat-link">
                    <?php _e('处理', 'qilingshop'); ?> →
                </a>
            </div>
            
            <div class="qls-stat-card">
                <div class="qls-stat-icon orders-shipped">
                    <span class="dashicons dashicons-car"></span>
                </div>
                <div class="qls-stat-content">
                    <div class="qls-stat-value"><?php echo esc_html($stats['orders_shipped']); ?></div>
                    <div class="qls-stat-label"><?php _e('配送中订单', 'qilingshop'); ?></div>
                </div>
                <a href="<?php echo admin_url('admin.php?page=qls-shop-orders&status=2'); ?>" class="qls-stat-link">
                    <?php _e('查看', 'qilingshop'); ?> →
                </a>
            </div>
        </div>
        
        <!-- 今日数据 -->
        <!-- 今日数据 & 累计数据 -->
        <div class="qls-dashboard-panels">
            <div class="qls-today-stats qls-dashboard-panel">
                <h2><?php _e('今日数据', 'qilingshop'); ?></h2>
                <div class="qls-stats-grid small">
                    <div class="qls-stat-card">
                        <div class="qls-stat-content">
                            <div class="qls-stat-value"><?php echo esc_html($stats['orders_today']); ?></div>
                            <div class="qls-stat-label"><?php _e('今日订单', 'qilingshop'); ?></div>
                        </div>
                    </div>
                    <div class="qls-stat-card">
                        <div class="qls-stat-content">
                            <div class="qls-stat-value">¥<?php echo number_format($stats['revenue_today'], 2); ?></div>
                            <div class="qls-stat-label"><?php _e('今日收入', 'qilingshop'); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="qls-today-stats qls-dashboard-panel">
                <h2><?php _e('累计数据', 'qilingshop'); ?></h2>
                <div class="qls-stats-grid small">
                    <div class="qls-stat-card">
                        <div class="qls-stat-content">
                            <div class="qls-stat-value"><?php echo esc_html($stats['customers_total']); ?></div>
                            <div class="qls-stat-label"><?php _e('成交用户', 'qilingshop'); ?></div>
                        </div>
                    </div>
                    <div class="qls-stat-card">
                        <div class="qls-stat-content">
                            <div class="qls-stat-value">¥<?php echo number_format($stats['revenue_total'], 2); ?></div>
                            <div class="qls-stat-label"><?php _e('累计收入', 'qilingshop'); ?></div>
                        </div>
                    </div>
                     <div class="qls-stat-card">
                        <div class="qls-stat-content">
                            <div class="qls-stat-value"><?php echo esc_html($stats['orders_total']); ?></div>
                            <div class="qls-stat-label"><?php _e('累计订单', 'qilingshop'); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 筛选栏 -->
        <div class="qls-filter-bar qls-shop-dashboard-filter">
            <div class="qls-quick-filters">
                <?php 
                $periods = [
                    'today' => __('今天', 'qilingshop'),
                    'yesterday' => __('昨天', 'qilingshop'),
                    '30day' => __('最近30天', 'qilingshop'),
                    'month' => __('本月', 'qilingshop'),
                    'year' => __('本年', 'qilingshop'),
                ];
                foreach ($periods as $k => $label): 
                    $active = ($period === $k) ? 'active' : '';
                ?>
                <a href="<?php echo add_query_arg(['period' => $k], remove_query_arg(['start_date', 'end_date'])); ?>" class="qls-quick-btn <?php echo $active; ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </div>

            <div class="qls-dashboard-filter-actions">
                <form method="get" class="qls-date-inputs">
                    <input type="hidden" name="page" value="qls-shop">
                    <input type="hidden" name="period" value="custom">
                    <span class="qls-filter-label"><?php _e('自定义:', 'qilingshop'); ?></span>
                    <input type="date" name="start_date" value="<?php echo esc_attr($start_date); ?>">
                    <span>-</span>
                    <input type="date" name="end_date" value="<?php echo esc_attr($end_date); ?>">
                    <button type="submit" class="button"><?php _e('查询', 'qilingshop'); ?></button>
                </form>
                
                <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('export', 'shop_stats'), 'qls_shop_export_stats')); ?>" target="_blank" class="button button-secondary">
                     <span class="dashicons dashicons-download qls-inline-icon"></span> <?php _e('导出表格', 'qilingshop'); ?>
                </a>
            </div>
        </div>

        <!-- 趋势图 & 热销排行 -->
        <div class="qls-dashboard-panels qls-dashboard-panels-gap">
            <!-- 趋势图 -->
            <div class="qls-dashboard-card qls-dashboard-card-main">
                <h2 class="qls-dashboard-card-title"><?php printf(__('销售趋势 (%s 至 %s) - 按%s统计', 'qilingshop'), $start_date, $end_date, ($group_by=='month'?__('月','qilingshop'):__('天','qilingshop'))); ?></h2>
                <div class="qls-dashboard-chart">
                    <canvas id="shop-trend-chart"></canvas>
                </div>
            </div>
            
            <!-- 热销排行 -->
            <div class="qls-dashboard-card qls-dashboard-card-side">
                <h2 class="qls-dashboard-card-title"><?php _e('热销商品前 5 名', 'qilingshop'); ?></h2>
                <table class="wp-list-table qls-ui-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('商品名称', 'qilingshop'); ?></th>
                            <th class="qls-w-60 qls-text-right"><?php _e('销量', 'qilingshop'); ?></th>
                            <th class="qls-w-80 qls-text-right"><?php _e('销售额', 'qilingshop'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($top_products)): ?>
                        <tr><td colspan="3"><?php _e('暂无数据', 'qilingshop'); ?></td></tr>
                        <?php else: ?>
                        <?php foreach ($top_products as $p): ?>
                        <tr>
                            <td class="qls-nowrap-ellipsis" title="<?php echo esc_attr($p->product_title); ?>">
                                <?php echo esc_html($p->product_title); ?>
                            </td>
                            <td class="qls-text-right"><?php echo intval($p->total_qty); ?></td>
                            <td class="qls-text-right">¥<?php echo number_format($p->total_sales, 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 第二行：分类占比 & 用户排行 -->
        <div class="qls-dashboard-panels qls-dashboard-panels-gap">
            <!-- 分类占比 -->
            <div class="qls-dashboard-card qls-dashboard-card-side">
                <h2 class="qls-dashboard-card-title"><?php _e('销售额分类占比', 'qilingshop'); ?></h2>
                <div class="qls-dashboard-chart pie">
                    <canvas id="category-pie-chart"></canvas>
                </div>
            </div>

            <!-- 用户排行 -->
            <div class="qls-dashboard-card qls-dashboard-card-side">
                <h2 class="qls-dashboard-card-title"><?php _e('用户消费排行前 10 名', 'qilingshop'); ?></h2>
                <table class="wp-list-table qls-ui-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('用户', 'qilingshop'); ?></th>
                            <th class="qls-w-60 qls-text-right"><?php _e('单量', 'qilingshop'); ?></th>
                            <th class="qls-w-80 qls-text-right"><?php _e('总消费', 'qilingshop'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($top_users)): ?>
                        <tr><td colspan="3"><?php _e('暂无数据', 'qilingshop'); ?></td></tr>
                        <?php else: ?>
                        <?php foreach ($top_users as $u): ?>
                        <tr>
                            <td>
                                <?php 
                                    $dName = $u->display_name ?: 'User#' . $u->user_id;
                                    echo esc_html($dName); 
                                ?>
                            </td>
                            <td class="qls-text-right"><?php echo intval($u->order_count); ?></td>
                            <td class="qls-text-right">¥<?php echo number_format($u->total_spend, 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 快捷操作 -->
        <div class="qls-quick-actions">
            <h2><?php _e('快捷操作', 'qilingshop'); ?></h2>
            <div class="qls-action-buttons">
                <a href="<?php echo admin_url('admin.php?page=qls-product-edit'); ?>" class="button button-primary button-hero">
                    <span class="dashicons dashicons-plus-alt"></span>
                    <?php _e('添加商品', 'qilingshop'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=qls-categories'); ?>" class="button button-secondary button-hero">
                    <span class="dashicons dashicons-category"></span>
                    <?php _e('管理分类', 'qilingshop'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=qls-shop-settings'); ?>" class="button button-secondary button-hero">
                    <span class="dashicons dashicons-admin-generic"></span>
                    <?php _e('商城设置', 'qilingshop'); ?>
                </a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // 趋势图
    var ctxShop = document.getElementById('shop-trend-chart').getContext('2d');
    new Chart(ctxShop, {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_keys($chart_data)); ?>,
            datasets: [{
                label: '<?php _e('销售额', 'qilingshop'); ?>',
                data: <?php echo json_encode(array_column(array_values($chart_data), 'income')); ?>,
                borderColor: '#2271b1',
                backgroundColor: 'rgba(34, 113, 177, 0.1)',
                yAxisID: 'y',
                fill: true,
                tension: 0.3
            }, {
                label: '<?php _e('订单量', 'qilingshop'); ?>',
                data: <?php echo json_encode(array_column(array_values($chart_data), 'orders')); ?>,
                borderColor: '#e74c3c',
                backgroundColor: 'rgba(231, 76, 60, 0.1)',
                yAxisID: 'y1',
                fill: true,
                tension: 0.3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    beginAtZero: true,
                    title: { display: true, text: '<?php _e('金额 (元)', 'qilingshop'); ?>' }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    beginAtZero: true,
                    title: { display: true, text: '<?php _e('单量', 'qilingshop'); ?>' }
                }
            }
        }
    });

    // 分类饼图
    var ctxPie = document.getElementById('category-pie-chart').getContext('2d');
    var catNames = <?php echo json_encode(array_column($category_stats, 'name')); ?>;
    var catSales = <?php echo json_encode(array_column($category_stats, 'total_sales')); ?>;
    
    // 生成随机颜色
    function generateColors(count) {
        var colors = [];
        for(var i=0; i<count; i++) {
            var hue = (i * 360 / count) % 360;
            colors.push('hsl('+hue+', 70%, 60%)');
        }
        return colors;
    }

    if(catNames.length > 0) {
        new Chart(ctxPie, {
            type: 'doughnut',
            data: {
                labels: catNames,
                datasets: [{
                    data: catSales,
                    backgroundColor: generateColors(catNames.length),
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                    }
                }
            }
        });
    } else {
        // 无数据处理
        ctxPie.textAlign = 'center';
        ctxPie.fillText('<?php _e('暂无分类数据', 'qilingshop'); ?>', 150, 150);
    }
</script>
