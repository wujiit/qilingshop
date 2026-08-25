<?php
/**
 * 运营看板视图
 *
 * @package QilingShop
 */
if (!defined('ABSPATH')) {
    exit;
}

$quick_periods = [
    'today' => __('今天', 'qilingshop'),
    'yesterday' => __('昨天', 'qilingshop'),
    '7day' => __('最近7天', 'qilingshop'),
    '30day' => __('最近30天', 'qilingshop'),
    'month' => __('本月', 'qilingshop'),
    'year' => __('本年', 'qilingshop'),
];
?>
<div class="wrap qilingshop-admin-page qls-shop-wrap qls-ops-wrap">
    <h1><?php _e('运营看板', 'qilingshop'); ?></h1>
    <p class="description">
        <?php
        printf(
            esc_html__('统计周期：%1$s（%2$s 至 %3$s）', 'qilingshop'),
            esc_html($active_period_label),
            esc_html($start_date),
            esc_html($end_date)
        );
        ?>
    </p>

    <div class="qls-ops-filter">
        <div class="qls-ops-quick-filters">
            <?php foreach ($quick_periods as $key => $label) : ?>
                <?php
                $link = add_query_arg(
                    [
                        'page' => 'qls-operations-dashboard',
                        'period' => $key,
                    ],
                    admin_url('admin.php')
                );
                ?>
                <a class="qls-ops-period-btn <?php echo $period === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url($link); ?>">
                    <?php echo esc_html($label); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <form method="get" class="qls-ops-custom-range">
            <input type="hidden" name="page" value="qls-operations-dashboard">
            <input type="hidden" name="period" value="custom">
            <label>
                <?php _e('自定义', 'qilingshop'); ?>
                <input type="date" name="start_date" value="<?php echo esc_attr($start_date); ?>">
            </label>
            <span class="qls-ops-sep">-</span>
            <label>
                <span class="screen-reader-text"><?php _e('结束日期', 'qilingshop'); ?></span>
                <input type="date" name="end_date" value="<?php echo esc_attr($end_date); ?>">
            </label>
            <button type="submit" class="button button-secondary"><?php _e('查询', 'qilingshop'); ?></button>
        </form>
    </div>

    <?php foreach ($panels as $panel) : ?>
        <section class="qls-ops-panel">
            <div class="qls-ops-panel-head">
                <div>
                    <h2><?php echo esc_html($panel['title']); ?></h2>
                    <p><?php echo esc_html($panel['description']); ?></p>
                </div>
                <a class="button button-secondary" href="<?php echo esc_url($panel['manage_url']); ?>">
                    <?php echo esc_html($panel['manage_text']); ?>
                </a>
            </div>

            <div class="qls-ops-kpi-grid">
                <?php foreach ($panel['kpis'] as $kpi) : ?>
                    <div class="qls-ops-kpi-card">
                        <div class="qls-ops-kpi-label"><?php echo esc_html($kpi['label']); ?></div>
                        <div class="qls-ops-kpi-value"><?php echo esc_html($kpi['value']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="qls-ops-funnel-wrap">
                <table class="widefat qls-ui-table striped qls-ops-funnel-table">
                    <thead>
                        <tr>
                            <th><?php _e('阶段', 'qilingshop'); ?></th>
                            <th><?php _e('数量', 'qilingshop'); ?></th>
                            <th><?php _e('环比转化', 'qilingshop'); ?></th>
                            <th><?php _e('总体转化', 'qilingshop'); ?></th>
                            <th><?php _e('可视化', 'qilingshop'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($panel['funnel'] as $stage) : ?>
                            <?php
                            $overall = max(0, min(100, (float) $stage['overall_rate']));
                            ?>
                            <tr>
                                <td><?php echo esc_html($stage['label']); ?></td>
                                <td><?php echo esc_html(number_format((int) $stage['count'])); ?></td>
                                <td><?php echo esc_html(number_format((float) $stage['step_rate'], 2)); ?>%</td>
                                <td><?php echo esc_html(number_format((float) $stage['overall_rate'], 2)); ?>%</td>
                                <td>
                                    <div class="qls-ops-progress" data-overall="<?php echo esc_attr($overall); ?>">
                                        <span class="qls-ops-progress-bar"></span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php
                $roi = $panel['roi'];
                $roi_class = 'is-neutral';
                if ($roi !== null) {
                    $roi_class = $roi >= 0 ? 'is-positive' : 'is-negative';
                }
                ?>
                <aside class="qls-ops-roi <?php echo esc_attr($roi_class); ?>">
                    <h3><?php _e('ROI', 'qilingshop'); ?></h3>
                    <div class="qls-ops-roi-value">
                        <?php echo $roi === null ? '--' : esc_html(number_format((float) $roi, 2)) . '%'; ?>
                    </div>
                    <div class="qls-ops-roi-item">
                        <span><?php _e('成交金额', 'qilingshop'); ?></span>
                        <strong>¥<?php echo esc_html(number_format((float) $panel['revenue'], 2)); ?></strong>
                    </div>
                    <div class="qls-ops-roi-item">
                        <span><?php _e('让利成本', 'qilingshop'); ?></span>
                        <strong>¥<?php echo esc_html(number_format((float) $panel['cost'], 2)); ?></strong>
                    </div>
                    <p class="description"><?php echo esc_html($panel['roi_note']); ?></p>
                </aside>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<script>
jQuery(function($) {
    $('.qls-ops-progress').each(function() {
        var overall = parseFloat($(this).attr('data-overall')) || 0;
        overall = Math.max(0, Math.min(100, overall));
        $(this).find('.qls-ops-progress-bar').css('width', overall + '%');
    });
});
</script>
