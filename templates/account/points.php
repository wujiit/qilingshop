<?php
/**
 * 个人中心 - 积分记录
 * 
 * 可用变量: $user_id, $current_user
 *
 * @package QilingShop
 */

if (!defined('ABSPATH')) exit;

$points = QilingShop_Points::instance();
$balance = $points->get_balance($user_id);
$paged = isset($_GET['ppaged']) ? max(1, intval($_GET['ppaged'])) : 1;
$per_page = 15;
$log_type = isset($_GET['ptype']) ? sanitize_key($_GET['ptype']) : '';
if (!in_array($log_type, ['income', 'expense'], true)) {
    $log_type = '';
}

$logs = $points->get_points_log($user_id, [
    'limit'  => $per_page,
    'offset' => ($paged - 1) * $per_page,
    'type'   => $log_type,
]);
$count_where = [];
if ($log_type !== '') {
    $count_where['type'] = $log_type;
}
$total = $points->get_points_log_count($user_id, $count_where);
$total_pages = ceil($total / $per_page);
$points_name = qilingshop_get_points_name();
$overview = $points->get_points_overview($user_id);
$balance_sources = ['withdraw', 'commission', 'author_commission', 'qls_group_refund', 'refund', 'qilingfreetask_convert_cash'];
?>

<div class="qls-account-section">
    <div class="qls-section-header">
        <h3 class="qls-section-title"><?php _e('积分记录', 'qilingshop'); ?></h3>
        <div class="qls-balance-info">
            <?php _e('当前余额：', 'qilingshop'); ?>
            <span class="qls-balance-highlight"><?php echo number_format($balance); ?></span>
            <?php echo esc_html($points_name); ?>
        </div>
    </div>

    <div class="qls-points-overview">
        <div class="qls-points-overview-item">
            <span class="qls-po-label"><?php _e('可用积分', 'qilingshop'); ?></span>
            <strong class="qls-po-value"><?php echo number_format((float) $overview['available']); ?></strong>
        </div>
        <div class="qls-points-overview-item">
            <span class="qls-po-label"><?php _e('冻结积分', 'qilingshop'); ?></span>
            <strong class="qls-po-value"><?php echo number_format((float) $overview['frozen']); ?></strong>
        </div>
        <?php if (!empty($overview['validity_enabled'])): ?>
        <div class="qls-points-overview-item">
            <span class="qls-po-label"><?php echo sprintf(__('近%d天将过期', 'qilingshop'), (int) ($overview['expiring_days'] ?: 30)); ?></span>
            <strong class="qls-po-value qls-po-warning"><?php echo number_format((float) $overview['expiring_soon']); ?></strong>
        </div>
        <div class="qls-points-overview-item">
            <span class="qls-po-label"><?php _e('最近到期日', 'qilingshop'); ?></span>
            <strong class="qls-po-value">
                <?php echo !empty($overview['next_expire_at']) ? esc_html(date_i18n('Y-m-d', strtotime($overview['next_expire_at']))) : '-'; ?>
            </strong>
        </div>
        <?php else: ?>
        <div class="qls-points-overview-item qls-po-full">
            <span class="qls-po-label"><?php _e('积分有效期', 'qilingshop'); ?></span>
            <strong class="qls-po-value"><?php _e('当前为永久有效', 'qilingshop'); ?></strong>
        </div>
        <?php endif; ?>
    </div>

    <div class="qls-filter-bar">
        <?php
        $base_url = add_query_arg('tab', 'qls-points', get_permalink());
        $filters = [
            '' => __('全部', 'qilingshop'),
            'income' => __('收入', 'qilingshop'),
            'expense' => __('支出', 'qilingshop'),
        ];
        foreach ($filters as $key => $label):
            $url = $key === '' ? $base_url : add_query_arg('ptype', $key, $base_url);
            $active = ($log_type === $key) ? 'active' : '';
        ?>
        <a href="<?php echo esc_url($url); ?>" class="qls-filter-pill <?php echo esc_attr($active); ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
    </div>
    
    <?php if (!empty($logs)): ?>
    <div class="qls-points-list">
        <?php foreach ($logs as $log): 
            $row_type = isset($log->type) ? strtolower((string) $log->type) : '';
            if ($row_type === 'income') {
                $is_income = true;
            } elseif ($row_type === 'expense') {
                $is_income = false;
            } else {
                // 兼容历史数据：没有 type 时再按金额正负兜底
                $is_income = ((float) $log->amount >= 0);
            }
            $display_amount = abs((float) $log->amount);
            $is_balance_source = in_array((string) $log->source, $balance_sources, true);
        ?>
        <div class="qls-points-item <?php echo $is_income ? 'income' : 'expense'; ?>">
            <div class="qls-points-icon">
                <?php echo $is_income ? '📥' : '📤'; ?>
            </div>
            <div class="qls-points-info">
                <div class="qls-points-title">
                    <?php 
                    $types = [
                        'recharge'         => __('充值', 'qilingshop'),
                        'purchase'         => __('购买资源', 'qilingshop'),
                        'checkin'          => __('签到奖励', 'qilingshop'),
                        'register'         => __('注册奖励', 'qilingshop'),
                        'invite'           => __('邀请奖励', 'qilingshop'),
                        'commission'       => __('推广佣金', 'qilingshop'),
                        'author_commission'=> __('作者佣金', 'qilingshop'),
                        'refund'           => __('退款', 'qilingshop'),
                        'qls_group_refund' => __('拼团退款', 'qilingshop'),
                        'admin'            => __('管理员操作', 'qilingshop'),
                        'withdraw'         => __('提现', 'qilingshop'),
                        'vip'              => __('购买VIP', 'qilingshop'),
                        'lottery'          => __('转盘抽奖', 'qilingshop'),
                        'points_expire'    => __('积分过期', 'qilingshop'),
                        'points_freeze'    => __('积分冻结', 'qilingshop'),
                        'points_unfreeze'  => __('积分解冻', 'qilingshop'),
                        'task_first_invite' => __('任务奖励（首次邀请成功）', 'qilingshop'),
                        'task_first_resource_order' => __('任务奖励（首次资源购买）', 'qilingshop'),
                        'task_first_shop_paid' => __('任务奖励（首次商城下单支付）', 'qilingshop'),
                        'qilingfreetask_escrow' => __('任务托管', 'qilingshop'),
                        'qilingfreetask_refund' => __('任务退款', 'qilingshop'),
                        'qilingfreetask_award' => __('任务奖励发放', 'qilingshop'),
                        'qilingfreetask_award_rollback' => __('任务奖励回滚', 'qilingshop'),
                        'qilingfreetask_refund_rollback' => __('任务退款回滚', 'qilingshop'),
                        'qilingfreetask_convert_points' => __('任务积分转余额（扣减）', 'qilingshop'),
                        'qilingfreetask_convert_cash' => __('任务积分转余额（入账）', 'qilingshop'),
                        'qilingfreetask_convert_rollback' => __('任务积分转换回滚', 'qilingshop'),
                    ];
                    // 优先使用 source，其次 type；并做标准化，避免历史脏值导致映射失效。
                    $log_key_raw = !empty($log->source) ? $log->source : $log->type;
                    $log_key = sanitize_key(trim((string) $log_key_raw));
                    if ($log_key === '') {
                        $log_key = trim((string) $log_key_raw);
                    }
                    $source_label = $types[$log_key] ?? $types[(string) $log_key_raw] ?? (string) $log_key_raw;
                    if (strpos((string) $log_key, 'qilingfreetask_') === 0 && !isset($types[$log_key])) {
                        $source_label = __('任务积分变动', 'qilingshop');
                    }
                    echo esc_html($source_label);
                    ?>
                </div>
                <div class="qls-points-desc">
                    <?php echo esc_html($log->description ?: '-'); ?>
                </div>
                <div class="qls-points-meta">
                    <?php if ($is_balance_source): ?>
                        <span><?php echo sprintf(__('可提现余额：¥%s', 'qilingshop'), number_format((float) $log->balance_after, 2)); ?></span>
                    <?php else: ?>
                        <span><?php echo sprintf(__('余额：%s %s', 'qilingshop'), number_format((float) $log->balance_after, 0), $points_name); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($log->source)): ?>
                        <span class="qls-points-source"><?php echo esc_html($source_label); ?></span>
                    <?php endif; ?>
                </div>
                <div class="qls-points-time">
                    <?php echo date_i18n('Y-m-d H:i', strtotime($log->created_at)); ?>
                </div>
            </div>
            <div class="qls-points-amount <?php echo $is_income ? 'plus' : 'minus'; ?>">
                <?php 
                $is_balance = $is_balance_source;
                echo $is_income ? '+' : '-'; 
                echo number_format($display_amount, $is_balance ? 2 : 0); 
                ?>
                <span class="qls-amount-unit">
                    <?php echo $is_balance ? __('元', 'qilingshop') : $points_name; ?>
                </span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <?php if ($total_pages > 1): ?>
    <div class="qls-pagination">
        <?php
        $base_url = add_query_arg('tab', 'qls-points', get_permalink());
        if ($log_type !== '') {
            $base_url = add_query_arg('ptype', $log_type, $base_url);
        }
        
        // 上一页
        if ($paged > 1):
            $prev_url = add_query_arg('ppaged', $paged - 1, $base_url);
        ?>
        <a href="<?php echo esc_url($prev_url); ?>" class="qls-page-link qls-page-prev">‹</a>
        <?php endif; ?>
        
        <?php
        // 页码
        $start = max(1, $paged - 2);
        $end = min($total_pages, $paged + 2);
        
        if ($start > 1): ?>
        <a href="<?php echo esc_url(add_query_arg('ppaged', 1, $base_url)); ?>" class="qls-page-link">1</a>
        <?php if ($start > 2): ?><span class="qls-page-dots">...</span><?php endif; ?>
        <?php endif; ?>
        
        <?php for ($i = $start; $i <= $end; $i++):
            $url = add_query_arg('ppaged', $i, $base_url);
        ?>
        <a href="<?php echo esc_url($url); ?>" class="qls-page-link <?php echo $i === $paged ? 'active' : ''; ?>">
            <?php echo $i; ?>
        </a>
        <?php endfor; ?>
        
        <?php if ($end < $total_pages): ?>
        <?php if ($end < $total_pages - 1): ?><span class="qls-page-dots">...</span><?php endif; ?>
        <a href="<?php echo esc_url(add_query_arg('ppaged', $total_pages, $base_url)); ?>" class="qls-page-link"><?php echo $total_pages; ?></a>
        <?php endif; ?>
        
        <?php
        // 下一页
        if ($paged < $total_pages):
            $next_url = add_query_arg('ppaged', $paged + 1, $base_url);
        ?>
        <a href="<?php echo esc_url($next_url); ?>" class="qls-page-link qls-page-next">›</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <?php else: ?>
    <div class="qls-empty-state">
        <div class="qls-empty-icon">📊</div>
        <p><?php _e('暂无积分记录', 'qilingshop'); ?></p>
    </div>
    <?php endif; ?>
</div>
