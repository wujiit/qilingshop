<?php
/**
 * 个人中心 - 邀请明细列表
 *
 * @package QilingShop
 */

if (!defined('ABSPATH')) exit;

$invites = $invites ?? [];
$points_name = $points_name ?? qilingshop_get_points_name();
$paged = isset($paged) ? (int) $paged : 1;
$total_pages = isset($total_pages) ? (int) $total_pages : 0;
$base_url = $base_url ?? '';
?>

<?php if (!empty($invites)): ?>
<div class="qls-invites-table-wrapper">
    <table class="qls-invites-table">
        <thead>
            <tr>
                <th><?php _e('用户', 'qilingshop'); ?></th>
                <th><?php _e('注册时间', 'qilingshop'); ?></th>
                <th><?php _e('贡献佣金', 'qilingshop'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($invites as $inv): 
                $invitee = get_userdata($inv->invitee_id);
            ?>
            <tr>
                <td class="qls-invitee-info" data-label="<?php esc_attr_e('用户', 'qilingshop'); ?>">
                    <?php if ($invitee): ?>
                    <?php echo get_avatar($inv->invitee_id, 32); ?>
                    <span><?php echo esc_html($invitee->display_name); ?></span>
                    <?php else: ?>
                    <span><?php _e('用户已删除', 'qilingshop'); ?></span>
                    <?php endif; ?>
                </td>
                <td data-label="<?php esc_attr_e('注册时间', 'qilingshop'); ?>"><?php echo date_i18n('Y-m-d', strtotime($inv->created_at)); ?></td>
                <td class="qls-commission" data-label="<?php esc_attr_e('贡献佣金', 'qilingshop'); ?>">
                    <?php echo number_format($inv->total_commission ?? 0); ?> <?php echo esc_html($points_name); ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($total_pages > 1): ?>
<div class="qls-pagination">
    <?php for ($i = 1; $i <= $total_pages; $i++): 
        $url = $base_url ? add_query_arg('ipaged', $i, $base_url) : '#';
    ?>
    <a href="<?php echo esc_url($url); ?>" class="qls-page-link <?php echo $i === $paged ? 'active' : ''; ?>" data-qls-page="<?php echo esc_attr($i); ?>" data-qls-param="ipaged">
        <?php echo $i; ?>
    </a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php else: ?>
<div class="qls-empty-state qls-empty-sm">
    <p><?php _e('暂无邀请记录，快去分享邀请链接吧！', 'qilingshop'); ?></p>
</div>
<?php endif; ?>
