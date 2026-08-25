<?php
/**
 * 销售提成页面模板
 * 
 * 展示用户作为作者出售资源获得的提成记录
 *
 * @package QilingShop
 * @since   1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$user_id = get_current_user_id();
$commission = qilingshop_author_commission();
$statistics = $commission->get_author_statistics($user_id);

// 分页
$page = isset($_GET['cpage']) ? max(1, intval($_GET['cpage'])) : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;
$total = $commission->get_author_commissions_count($user_id);
$total_pages = ceil($total / $per_page);

$records = $commission->get_author_commissions($user_id, [
    'limit'  => $per_page,
    'offset' => $offset,
]);

$points_name = qilingshop_get_points_name();
?>

<div class="qls-commission-page">
    <!-- 统计概览 -->
    <div class="qls-commission-stats">
        <div class="qls-stat-card qls-stat-primary">
            <div class="qls-stat-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="1" x2="12" y2="23"></line>
                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                </svg>
            </div>
            <div class="qls-stat-content">
                <span class="qls-stat-label"><?php _e('累计提成', 'qilingshop'); ?></span>
                <span class="qls-stat-value">¥<?php echo number_format($statistics['total_commission'], 2); ?></span>
            </div>
        </div>
        
        <div class="qls-stat-card">
            <div class="qls-stat-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                    <line x1="1" y1="10" x2="23" y2="10"></line>
                </svg>
            </div>
            <div class="qls-stat-content">
                <span class="qls-stat-label"><?php _e('累计销售', 'qilingshop'); ?></span>
                <span class="qls-stat-value">¥<?php echo number_format($statistics['total_sales'], 2); ?></span>
            </div>
        </div>
        
        <div class="qls-stat-card">
            <div class="qls-stat-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                </svg>
            </div>
            <div class="qls-stat-content">
                <span class="qls-stat-label"><?php _e('本月提成', 'qilingshop'); ?></span>
                <span class="qls-stat-value">¥<?php echo number_format($statistics['month_commission'], 2); ?></span>
            </div>
        </div>
        
        <div class="qls-stat-card">
            <div class="qls-stat-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 12h-4l-3 9L9 3l-3 9H2"></path>
                </svg>
            </div>
            <div class="qls-stat-content">
                <span class="qls-stat-label"><?php _e('成交订单', 'qilingshop'); ?></span>
                <span class="qls-stat-value"><?php echo intval($statistics['total_orders']); ?></span>
            </div>
        </div>
    </div>
    
    <!-- 提成记录列表 -->
    <div class="qls-commission-list">
        <h3 class="qls-section-title">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
                <line x1="16" y1="13" x2="8" y2="13"></line>
                <line x1="16" y1="17" x2="8" y2="17"></line>
            </svg>
            <?php _e('提成明细', 'qilingshop'); ?>
        </h3>
        
        <?php if (!empty($records)): ?>
        <div class="qls-records-table-wrapper">
            <table class="qls-records-table">
                <thead>
                    <tr>
                        <th><?php _e('时间', 'qilingshop'); ?></th>
                        <th><?php _e('资源', 'qilingshop'); ?></th>
                        <th><?php _e('订单金额', 'qilingshop'); ?></th>
                        <th><?php _e('提成比例', 'qilingshop'); ?></th>
                        <th><?php _e('提成金额', 'qilingshop'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $record): ?>
                    <tr>
                        <td class="qls-td-time">
                            <?php echo date_i18n('Y-m-d H:i', strtotime($record->created_at)); ?>
                        </td>
                        <td class="qls-td-title">
                            <?php if ($record->post_id && get_post($record->post_id)): ?>
                                <a href="<?php echo get_permalink($record->post_id); ?>" target="_blank">
                                    <?php echo esc_html($record->post_title ?: get_the_title($record->post_id)); ?>
                                </a>
                            <?php else: ?>
                                <?php echo esc_html($record->post_title ?: __('资源已删除', 'qilingshop')); ?>
                            <?php endif; ?>
                        </td>
                        <td class="qls-td-amount">
                            ¥<?php echo number_format($record->order_amount, 2); ?>
                        </td>
                        <td class="qls-td-rate">
                            <?php echo number_format($record->commission_rate, 0); ?>%
                        </td>
                        <td class="qls-td-commission">
                            <span class="qls-commission-amount">+¥<?php echo number_format($record->commission_amount, 2); ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- 分页 -->
        <?php if ($total_pages > 1): ?>
        <div class="qls-pagination">
            <?php
            $base_url = add_query_arg('tab', 'qls-commission', get_permalink());
            
            // 上一页
            if ($page > 1): ?>
                <a href="<?php echo esc_url(add_query_arg('cpage', $page - 1, $base_url)); ?>" class="qls-page-btn">
                    &laquo;
                </a>
            <?php endif;
            
            // 页码
            $start = max(1, $page - 2);
            $end = min($total_pages, $page + 2);
            
            for ($i = $start; $i <= $end; $i++): ?>
                <a href="<?php echo esc_url(add_query_arg('cpage', $i, $base_url)); ?>" 
                   class="qls-page-btn <?php echo $i === $page ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor;
            
            // 下一页
            if ($page < $total_pages): ?>
                <a href="<?php echo esc_url(add_query_arg('cpage', $page + 1, $base_url)); ?>" class="qls-page-btn">
                    &raquo;
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php else: ?>
        <!-- 空状态 -->
        <div class="qls-empty-state">
            <div class="qls-empty-icon">
                <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <line x1="12" y1="1" x2="12" y2="23"></line>
                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                </svg>
            </div>
            <p class="qls-empty-text"><?php _e('暂无提成记录', 'qilingshop'); ?></p>
            <p class="qls-empty-hint"><?php _e('投稿资源并设置价格，当有用户购买时即可获得提成', 'qilingshop'); ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
/* 统计卡片 */
.qls-commission-stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.qls-stat-card {
    background: #fff;
    border-radius: 12px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    border: 1px solid #e5e7eb;
    transition: all 0.3s;
}

.qls-stat-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}

.qls-stat-primary {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    border: none;
}

.qls-stat-primary .qls-stat-icon,
.qls-stat-primary .qls-stat-label,
.qls-stat-primary .qls-stat-value {
    color: #fff;
}

.qls-stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: linear-gradient(135deg, rgba(245, 158, 11, 0.1) 0%, rgba(217, 119, 6, 0.1) 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #f59e0b;
}

.qls-stat-primary .qls-stat-icon {
    background: rgba(255,255,255,0.2);
}

.qls-stat-content {
    display: flex;
    flex-direction: column;
}

.qls-stat-label {
    font-size: 13px;
    color: #6b7280;
    margin-bottom: 4px;
}

.qls-stat-value {
    font-size: 20px;
    font-weight: 600;
    color: #1f2937;
}

/* 列表 */
.qls-commission-list {
    background: #fff;
    border-radius: 12px;
    padding: 24px;
    border: 1px solid #e5e7eb;
}

.qls-section-title {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0 0 20px;
    font-size: 16px;
    font-weight: 600;
    color: #1f2937;
}

.qls-section-title svg {
    color: #f59e0b;
}

.qls-records-table-wrapper {
    overflow-x: auto;
}

.qls-records-table {
    width: 100%;
    border-collapse: collapse;
}

.qls-records-table th {
    background: #f9fafb;
    padding: 12px 16px;
    font-weight: 500;
    color: #6b7280;
    text-align: left;
    font-size: 13px;
    border-bottom: 1px solid #e5e7eb;
}

.qls-records-table td {
    padding: 14px 16px;
    border-bottom: 1px solid #f3f4f6;
    font-size: 14px;
    color: #374151;
}

.qls-records-table tr:hover td {
    background: #f9fafb;
}

.qls-td-title a {
    color: var(--color-primary, #2563eb);
    text-decoration: none;
}

.qls-td-title a:hover {
    text-decoration: underline;
}

.qls-commission-amount {
    color: #10b981;
    font-weight: 600;
}

/* 分页 */
.qls-pagination {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #f3f4f6;
}

.qls-page-btn {
    min-width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    background: #f9fafb;
    color: #6b7280;
    text-decoration: none;
    font-size: 14px;
    transition: all 0.2s;
    border: 1px solid #e5e7eb;
}

.qls-page-btn:hover,
.qls-page-btn.active {
    background: #f59e0b;
    color: #fff;
    border-color: #f59e0b;
}

/* 空状态 */
.qls-empty-state {
    text-align: center;
    padding: 60px 20px;
}

.qls-empty-icon {
    margin-bottom: 16px;
    color: #e5e7eb;
}

.qls-empty-text {
    font-size: 16px;
    color: #6b7280;
    margin: 0 0 8px;
}

.qls-empty-hint {
    font-size: 14px;
    color: #9ca3af;
    margin: 0;
}

/* 启灵主题暗黑模式兼容 */
html.dark-mode .qls-commission-page .qls-stat-card,
body.dark-mode .qls-commission-page .qls-stat-card,
[data-theme='dark'] .qls-commission-page .qls-stat-card {
    background: var(--dm-bg-card, #1e293b);
    border-color: var(--dm-border, #334155);
}

html.dark-mode .qls-commission-page .qls-stat-card:hover,
body.dark-mode .qls-commission-page .qls-stat-card:hover,
[data-theme='dark'] .qls-commission-page .qls-stat-card:hover {
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.35);
}

html.dark-mode .qls-commission-page .qls-stat-primary,
body.dark-mode .qls-commission-page .qls-stat-primary,
[data-theme='dark'] .qls-commission-page .qls-stat-primary {
    border: none;
}

html.dark-mode .qls-commission-page .qls-stat-icon,
body.dark-mode .qls-commission-page .qls-stat-icon,
[data-theme='dark'] .qls-commission-page .qls-stat-icon {
    background: rgba(245, 158, 11, 0.15);
    color: #fbbf24;
}

html.dark-mode .qls-commission-page .qls-stat-label,
body.dark-mode .qls-commission-page .qls-stat-label,
[data-theme='dark'] .qls-commission-page .qls-stat-label {
    color: var(--dm-text-muted, #94a3b8);
}

html.dark-mode .qls-commission-page .qls-stat-value,
body.dark-mode .qls-commission-page .qls-stat-value,
[data-theme='dark'] .qls-commission-page .qls-stat-value {
    color: var(--dm-text, #e2e8f0);
}

html.dark-mode .qls-commission-page .qls-commission-list,
body.dark-mode .qls-commission-page .qls-commission-list,
[data-theme='dark'] .qls-commission-page .qls-commission-list {
    background: var(--dm-bg-card, #1e293b);
    border-color: var(--dm-border, #334155);
}

html.dark-mode .qls-commission-page .qls-section-title,
body.dark-mode .qls-commission-page .qls-section-title,
[data-theme='dark'] .qls-commission-page .qls-section-title {
    color: var(--dm-text, #e2e8f0);
}

html.dark-mode .qls-commission-page .qls-records-table th,
body.dark-mode .qls-commission-page .qls-records-table th,
[data-theme='dark'] .qls-commission-page .qls-records-table th {
    background: rgba(255, 255, 255, 0.03);
    color: var(--dm-text-muted, #94a3b8);
    border-bottom-color: var(--dm-border, #334155);
}

html.dark-mode .qls-commission-page .qls-records-table td,
body.dark-mode .qls-commission-page .qls-records-table td,
[data-theme='dark'] .qls-commission-page .qls-records-table td {
    color: var(--dm-text, #e2e8f0);
    border-bottom-color: var(--dm-border, #334155);
}

html.dark-mode .qls-commission-page .qls-records-table tr:hover td,
body.dark-mode .qls-commission-page .qls-records-table tr:hover td,
[data-theme='dark'] .qls-commission-page .qls-records-table tr:hover td {
    background: rgba(255, 255, 255, 0.02);
}

html.dark-mode .qls-commission-page .qls-pagination,
body.dark-mode .qls-commission-page .qls-pagination,
[data-theme='dark'] .qls-commission-page .qls-pagination {
    border-top-color: var(--dm-border, #334155);
}

html.dark-mode .qls-commission-page .qls-page-btn,
body.dark-mode .qls-commission-page .qls-page-btn,
[data-theme='dark'] .qls-commission-page .qls-page-btn {
    background: rgba(255, 255, 255, 0.03);
    border-color: var(--dm-border, #334155);
    color: var(--dm-text-muted, #94a3b8);
}

html.dark-mode .qls-commission-page .qls-empty-icon,
body.dark-mode .qls-commission-page .qls-empty-icon,
[data-theme='dark'] .qls-commission-page .qls-empty-icon {
    color: var(--dm-border, #334155);
}

html.dark-mode .qls-commission-page .qls-empty-text,
body.dark-mode .qls-commission-page .qls-empty-text,
[data-theme='dark'] .qls-commission-page .qls-empty-text {
    color: var(--dm-text-muted, #94a3b8);
}

html.dark-mode .qls-commission-page .qls-empty-hint,
body.dark-mode .qls-commission-page .qls-empty-hint,
[data-theme='dark'] .qls-commission-page .qls-empty-hint {
    color: #64748b;
}

/* 响应式 */
@media (max-width: 768px) {
    .qls-commission-stats {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .qls-stat-card {
        padding: 16px;
    }
    
    .qls-stat-value {
        font-size: 18px;
    }
}

@media (max-width: 480px) {
    .qls-commission-stats {
        grid-template-columns: 1fr;
    }
}
</style>
