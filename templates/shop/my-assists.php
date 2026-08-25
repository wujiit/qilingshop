<?php
/**
 * 我的助力
 */
if (!defined('ABSPATH')) exit;

qls_shop_public()->get_shop_header(__('我的助力', 'qilingshop'));
$assist_detail_url = qls_shop_public()->get_page_url('assist-detail');

$status_labels = [
    '' => __('全部', 'qilingshop'),
    QLS_Assist::CAMPAIGN_ONGOING => qls_assist()->get_campaign_status_text(QLS_Assist::CAMPAIGN_ONGOING),
    QLS_Assist::CAMPAIGN_READY => qls_assist()->get_campaign_status_text(QLS_Assist::CAMPAIGN_READY),
    QLS_Assist::CAMPAIGN_ORDER_PENDING => qls_assist()->get_campaign_status_text(QLS_Assist::CAMPAIGN_ORDER_PENDING),
    QLS_Assist::CAMPAIGN_COMPLETED => qls_assist()->get_campaign_status_text(QLS_Assist::CAMPAIGN_COMPLETED),
    QLS_Assist::CAMPAIGN_EXPIRED => qls_assist()->get_campaign_status_text(QLS_Assist::CAMPAIGN_EXPIRED),
    QLS_Assist::CAMPAIGN_REFUNDED => qls_assist()->get_campaign_status_text(QLS_Assist::CAMPAIGN_REFUNDED),
];
?>

<div class="qls-account-page qls-assist-page">
    <?php include QILINGSHOP_PATH . 'templates/shop/partials/account-header.php'; ?>

    <div class="qls-account-body">
        <div class="qls-container">
            <div class="qls-account-layout">
                <?php include QILINGSHOP_PATH . 'templates/shop/partials/account-sidebar.php'; ?>

                <main class="qls-account-main">
                    <div class="qls-section-header">
                        <h3><?php _e('我的助力', 'qilingshop'); ?></h3>
                    </div>

                    <div class="qls-order-tabs">
                        <?php foreach ($status_labels as $status_key => $label): ?>
                            <a href="<?php echo esc_url(add_query_arg(['status' => $status_key, 'paged' => 1])); ?>" class="<?php echo ((string) $status === (string) $status_key) ? 'active' : ''; ?>">
                                <?php echo esc_html($label); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <table class="qls-assist-table">
                        <thead>
                            <tr>
                                <th><?php _e('活动', 'qilingshop'); ?></th>
                                <th><?php _e('分享码', 'qilingshop'); ?></th>
                                <th><?php _e('金额', 'qilingshop'); ?></th>
                                <th><?php _e('助力人数', 'qilingshop'); ?></th>
                                <th><?php _e('状态', 'qilingshop'); ?></th>
                                <th><?php _e('时间', 'qilingshop'); ?></th>
                                <th><?php _e('操作', 'qilingshop'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($campaigns)): ?>
                            <tr><td colspan="7" class="no-items"><?php _e('暂无助力记录', 'qilingshop'); ?></td></tr>
                            <?php else: ?>
                            <?php foreach ($campaigns as $campaign): ?>
                            <tr>
                                <td><?php echo esc_html($campaign->activity_name); ?></td>
                                <td><code><?php echo esc_html($campaign->share_code); ?></code></td>
                                <td>¥<?php echo number_format((float) $campaign->current_price, 2); ?></td>
                                <td><?php echo (int) $campaign->help_count; ?><?php if ((int) $campaign->target_helpers > 0): ?> / <?php echo (int) $campaign->target_helpers; ?><?php endif; ?></td>
                                <td><?php echo esc_html(qls_assist()->get_campaign_status_text((int) $campaign->status)); ?></td>
                                <td><?php echo esc_html(date('Y-m-d H:i', strtotime($campaign->created_at))); ?></td>
                                <td>
                                    <?php if (!empty($assist_detail_url)): ?>
                                    <a href="<?php echo esc_url(add_query_arg('share', rawurlencode((string) $campaign->share_code), $assist_detail_url)); ?>"><?php _e('查看详情', 'qilingshop'); ?></a>
                                    <?php else: ?>
                                    -
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <?php
                    $total_pages = (int) ceil(max(1, $total) / $limit);
                    if ($total_pages > 1):
                    ?>
                    <div class="qls-pagination">
                        <?php
                        echo paginate_links([
                            'base'      => add_query_arg('paged', '%#%'),
                            'format'    => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total'     => $total_pages,
                            'current'   => $paged,
                        ]);
                        ?>
                    </div>
                    <?php endif; ?>
                </main>
            </div>
        </div>
    </div>
</div>

<?php qls_shop_public()->get_shop_footer(); ?>

