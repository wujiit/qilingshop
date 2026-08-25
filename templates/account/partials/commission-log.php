<?php
/**
 * 个人中心 - 佣金明细
 *
 * @package QilingShop
 */

if (!defined('ABSPATH')) exit;

$commissions = $commissions ?? [];
$paged = isset($paged) ? (int) $paged : 1;
$total_pages = isset($total_pages) ? (int) $total_pages : 0;
$base_url = $base_url ?? '';
?>

<?php if (!empty($commissions)): ?>
<div class="qls-commissions-table-wrapper">
    <table class="qls-commissions-table">
        <thead>
            <tr>
                <th><?php _e('时间', 'qilingshop'); ?></th>
                <th><?php _e('来源用户', 'qilingshop'); ?></th>
                <th><?php _e('类型', 'qilingshop'); ?></th>
                <th><?php _e('金额', 'qilingshop'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($commissions as $log): 
                $from_user = get_userdata($log->from_user_id);
                $source_label = ($log->source === 'order') ? __('商城订单', 'qilingshop') : __('资源购买', 'qilingshop');
                $level_label = ($log->level == 1) ? __('一级', 'qilingshop') : __('二级', 'qilingshop');
            ?>
            <tr>
                <td><?php echo date_i18n('Y-m-d H:i', strtotime($log->created_at)); ?></td>
                <td class="qls-from-user-info">
                    <?php if ($from_user): ?>
                    <?php echo get_avatar($log->from_user_id, 24); ?>
                    <span><?php echo esc_html($from_user->display_name); ?></span>
                    <?php else: ?>
                    <span><?php _e('用户已删除', 'qilingshop'); ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="qls-source-tag"><?php echo esc_html($source_label); ?></span>
                    <span class="qls-level-tag level-<?php echo (int) $log->level; ?>"><?php echo esc_html($level_label); ?></span>
                </td>
                <td class="qls-amount">+<?php echo number_format($log->amount, 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($total_pages > 1): ?>
<div class="qls-pagination">
    <?php for ($i = 1; $i <= $total_pages; $i++): 
        $url = $base_url ? add_query_arg('cpaged', $i, $base_url) : '#';
    ?>
    <a href="<?php echo esc_url($url); ?>" class="qls-page-link <?php echo $i === $paged ? 'active' : ''; ?>" data-qls-page="<?php echo esc_attr($i); ?>" data-qls-param="cpaged">
        <?php echo $i; ?>
    </a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php else: ?>
<div class="qls-empty-state qls-empty-sm">
    <p><?php _e('暂无佣金记录', 'qilingshop'); ?></p>
</div>
<?php endif; ?>
