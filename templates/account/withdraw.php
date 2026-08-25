<?php
/**
 * 个人中心 - 提现记录
 * 
 * 可用变量: $user_id, $current_user
 *
 * @package QilingShop
 */

if (!defined('ABSPATH')) exit;

$db = QilingShop_Database::instance();
$points = QilingShop_Points::instance();
$user_info = $points->get_user_info($user_id);

// 可提现余额（推广佣金）
$withdrawable = $user_info ? floatval($user_info->withdrawable_balance) : 0;

// 提现设置
$min_amount = floatval(get_option('qilingshop_withdraw_min_amount', 100));
$fee_rate = floatval(get_option('qilingshop_withdraw_fee_rate', 0));

// 获取用户保存的提现账户
$saved_account_type = get_user_meta($user_id, '_qilingshop_withdraw_account_type', true) ?: 'alipay';
$saved_account_name = get_user_meta($user_id, '_qilingshop_withdraw_account_name', true) ?: '';
$saved_account_no = get_user_meta($user_id, '_qilingshop_withdraw_account_no', true) ?: '';

// 分页
$paged = isset($_GET['wpaged']) ? max(1, intval($_GET['wpaged'])) : 1;
$per_page = 15;

// 获取提现记录
$withdrawals = $db->get_results('withdrawals', [
    'where'   => ['user_id' => $user_id],
    'orderby' => 'id',
    'order'   => 'DESC',
    'limit'   => $per_page,
    'offset'  => ($paged - 1) * $per_page,
]);
$total = $db->count('withdrawals', ['user_id' => $user_id]);
$total_pages = ceil($total / $per_page);

$status_labels = [
    0 => ['label' => __('待审核', 'qilingshop'), 'class' => 'pending'],
    1 => ['label' => __('已完成', 'qilingshop'), 'class' => 'completed'],
    2 => ['label' => __('已拒绝', 'qilingshop'), 'class' => 'rejected'],
];

$account_types = [
    'alipay' => __('支付宝', 'qilingshop'),
    'wechat' => __('微信', 'qilingshop'),
    'bank'   => __('银行卡', 'qilingshop'),
];
?>

<div class="qls-account-section">
    <div class="qls-section-header">
        <h3 class="qls-section-title"><?php _e('提现管理', 'qilingshop'); ?></h3>
    </div>
    
    <!-- 可提现余额 -->
    <div class="qls-withdraw-balance">
        <div class="qls-balance-card">
            <div class="qls-balance-label"><?php _e('可提现余额', 'qilingshop'); ?></div>
            <div class="qls-balance-amount">¥<?php echo number_format($withdrawable, 2); ?></div>
            <div class="qls-balance-note">
                <?php printf(__('最低提现金额：¥%s', 'qilingshop'), number_format($min_amount, 2)); ?>
                <?php if ($fee_rate > 0): ?>
                    | <?php printf(__('手续费：%s%%', 'qilingshop'), $fee_rate); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- 提现申请表单 -->
    <?php if ($withdrawable >= $min_amount): ?>
    <div class="qls-withdraw-form">
        <h4><?php _e('申请提现', 'qilingshop'); ?></h4>
        <form id="qls-withdraw-form">
            <div class="qls-form-row">
                <label><?php _e('提现金额', 'qilingshop'); ?></label>
                <input type="number" name="amount" id="withdraw-amount" step="0.01" min="<?php echo $min_amount; ?>" max="<?php echo $withdrawable; ?>" placeholder="<?php printf(__('最低 %s 元', 'qilingshop'), $min_amount); ?>" required>
                <span class="qls-fee-preview"></span>
            </div>
            <div class="qls-form-row">
                <label><?php _e('收款方式', 'qilingshop'); ?></label>
                <select name="account_type" id="withdraw-account-type">
                    <?php foreach ($account_types as $key => $label): ?>
                        <option value="<?php echo $key; ?>" <?php selected($saved_account_type, $key); ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="qls-form-row">
                <label><?php _e('收款人姓名', 'qilingshop'); ?></label>
                <input type="text" name="account_name" value="<?php echo esc_attr($saved_account_name); ?>" placeholder="<?php _e('请输入真实姓名', 'qilingshop'); ?>" required>
            </div>
            <div class="qls-form-row">
                <label><?php _e('收款账号', 'qilingshop'); ?></label>
                <input type="text" name="account_no" value="<?php echo esc_attr($saved_account_no); ?>" placeholder="<?php _e('支付宝/微信号/银行卡号', 'qilingshop'); ?>" required>
            </div>
            <div class="qls-form-row">
                <button type="submit" class="qls-btn qls-btn-primary" id="submit-withdraw"><?php _e('提交申请', 'qilingshop'); ?></button>
            </div>
        </form>
    </div>
    <?php elseif ($withdrawable > 0): ?>
    <div class="qls-withdraw-notice">
        <p><?php printf(__('可提现余额不足，最低提现金额为 ¥%s', 'qilingshop'), number_format($min_amount, 2)); ?></p>
    </div>
    <?php else: ?>
    <div class="qls-withdraw-notice">
        <p><?php _e('暂无可提现余额，推广获得佣金后可在此提现', 'qilingshop'); ?></p>
    </div>
    <?php endif; ?>
    
    <!-- 提现记录 -->
    <div class="qls-section-header" style="margin-top: 30px;">
        <h4><?php _e('提现记录', 'qilingshop'); ?></h4>
    </div>
    
    <?php if (!empty($withdrawals)): ?>
    <div class="qls-withdraw-list">
        <?php foreach ($withdrawals as $record): 
            $status_info = $status_labels[$record->status] ?? $status_labels[0];
        ?>
        <div class="qls-withdraw-item">
            <div class="qls-withdraw-info">
                <div class="qls-withdraw-amount">
                    ¥<?php echo number_format($record->amount, 2); ?>
                    <?php if ($record->fee > 0): ?>
                        <small class="qls-fee">(<?php printf(__('手续费：¥%s', 'qilingshop'), number_format($record->fee, 2)); ?>)</small>
                    <?php endif; ?>
                </div>
                <div class="qls-withdraw-account">
                    <?php echo esc_html($account_types[$record->account_type] ?? $record->account_type); ?>: 
                    <?php echo esc_html($record->account_name); ?> - <?php echo esc_html(substr($record->account_no, 0, 3) . '****' . substr($record->account_no, -4)); ?>
                </div>
                <div class="qls-withdraw-time">
                    <?php echo date_i18n('Y-m-d H:i', strtotime($record->created_at)); ?>
                </div>
                <?php if ($record->status == 2 && $record->admin_note): ?>
                <div class="qls-withdraw-note">
                    <?php _e('拒绝原因：', 'qilingshop'); ?><?php echo esc_html($record->admin_note); ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="qls-withdraw-status <?php echo $status_info['class']; ?>">
                <?php echo $status_info['label']; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <?php if ($total_pages > 1): ?>
    <div class="qls-pagination">
        <?php
        $base_url = add_query_arg('tab', 'qls-withdraw', get_permalink());
        
        if ($paged > 1):
            $prev_url = add_query_arg('wpaged', $paged - 1, $base_url);
        ?>
        <a href="<?php echo esc_url($prev_url); ?>" class="qls-page-link qls-page-prev">‹</a>
        <?php endif; ?>
        
        <?php for ($i = 1; $i <= $total_pages; $i++):
            $url = add_query_arg('wpaged', $i, $base_url);
        ?>
        <a href="<?php echo esc_url($url); ?>" class="qls-page-link <?php echo $i === $paged ? 'active' : ''; ?>">
            <?php echo $i; ?>
        </a>
        <?php endfor; ?>
        
        <?php if ($paged < $total_pages):
            $next_url = add_query_arg('wpaged', $paged + 1, $base_url);
        ?>
        <a href="<?php echo esc_url($next_url); ?>" class="qls-page-link qls-page-next">›</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <?php else: ?>
    <div class="qls-empty-state">
        <div class="qls-empty-icon">💳</div>
        <p><?php _e('暂无提现记录', 'qilingshop'); ?></p>
    </div>
    <?php endif; ?>
</div>

<script>
jQuery(function($) {
    var feeRate = <?php echo $fee_rate; ?>;
    
    // 实时计算手续费
    $('#withdraw-amount').on('input', function() {
        var amount = parseFloat($(this).val()) || 0;
        var fee = amount * feeRate / 100;
        var actual = amount - fee;
        if (fee > 0) {
            $('.qls-fee-preview').text('<?php echo esc_js(__('手续费：¥', 'qilingshop')); ?>' + fee.toFixed(2) + '<?php echo esc_js(__('，实际到账：¥', 'qilingshop')); ?>' + actual.toFixed(2));
        } else {
            $('.qls-fee-preview').text('');
        }
    });
    
    // 提交提现申请
    $('#qls-withdraw-form').on('submit', function(e) {
        e.preventDefault();
        
        var $btn = $('#submit-withdraw');
        $btn.prop('disabled', true).text('<?php _e('提交中...', 'qilingshop'); ?>');
        
        $.post(qilingshop.ajaxUrl, {
            action: 'qilingshop_submit_withdraw',
            nonce: qilingshop.nonce,
            amount: $('#withdraw-amount').val(),
            account_type: $('select[name="account_type"]').val(),
            account_name: $('input[name="account_name"]').val(),
            account_no: $('input[name="account_no"]').val()
        }, function(response) {
            if (response.success) {
                alert(response.message || '<?php _e('提现申请已提交，请等待审核', 'qilingshop'); ?>');
                location.reload();
            } else {
                alert(response.message || '<?php _e('提交失败', 'qilingshop'); ?>');
                $btn.prop('disabled', false).text('<?php _e('提交申请', 'qilingshop'); ?>');
            }
        });
    });
});
</script>

<style>
.qls-withdraw-balance {
    margin-bottom: 25px;
}
.qls-balance-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
    padding: 25px;
    border-radius: 12px;
    text-align: center;
}
.qls-balance-label {
    font-size: 14px;
    opacity: 0.9;
}
.qls-balance-amount {
    font-size: 36px;
    font-weight: 700;
    margin: 10px 0;
}
.qls-balance-note {
    font-size: 12px;
    opacity: 0.8;
}
.qls-withdraw-form {
    background: #f9f9f9;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 25px;
}
.qls-withdraw-form h4 {
    margin: 0 0 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}
.qls-form-row {
    margin-bottom: 15px;
}
.qls-form-row label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
}
.qls-form-row input,
.qls-form-row select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
}
.qls-fee-preview {
    display: block;
    margin-top: 5px;
    font-size: 13px;
    color: #666;
}
.qls-btn {
    padding: 12px 30px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
}
.qls-btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
}
.qls-btn-primary:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
.qls-withdraw-notice {
    padding: 15px;
    background: #fff3cd;
    border-radius: 8px;
    color: #856404;
    margin-bottom: 25px;
}
.qls-withdraw-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px;
    background: #fff;
    border: 1px solid #eee;
    border-radius: 8px;
    margin-bottom: 10px;
}
.qls-withdraw-amount {
    font-size: 18px;
    font-weight: 600;
    color: #333;
}
.qls-withdraw-amount .qls-fee {
    font-size: 12px;
    color: #999;
    font-weight: 400;
}
.qls-withdraw-account {
    font-size: 13px;
    color: #666;
    margin-top: 5px;
}
.qls-withdraw-time {
    font-size: 12px;
    color: #999;
    margin-top: 3px;
}
.qls-withdraw-note {
    font-size: 12px;
    color: #dc3545;
    margin-top: 5px;
}
.qls-withdraw-status {
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
}
.qls-withdraw-status.pending {
    background: #fff3cd;
    color: #856404;
}
.qls-withdraw-status.completed {
    background: #d4edda;
    color: #155724;
}
.qls-withdraw-status.rejected {
    background: #f8d7da;
    color: #721c24;
}

/* 启灵主题暗黑模式兼容 */
html.dark-mode .qls-withdraw-form,
body.dark-mode .qls-withdraw-form,
[data-theme='dark'] .qls-withdraw-form {
    background: var(--dm-bg-card, #1e293b);
    border: 1px solid var(--dm-border, #334155);
}

html.dark-mode .qls-withdraw-form h4,
body.dark-mode .qls-withdraw-form h4,
[data-theme='dark'] .qls-withdraw-form h4 {
    color: var(--dm-text, #e2e8f0);
    border-bottom-color: var(--dm-border, #334155);
}

html.dark-mode .qls-form-row label,
body.dark-mode .qls-form-row label,
[data-theme='dark'] .qls-form-row label {
    color: var(--dm-text, #e2e8f0);
}

html.dark-mode .qls-form-row input,
html.dark-mode .qls-form-row select,
body.dark-mode .qls-form-row input,
body.dark-mode .qls-form-row select,
[data-theme='dark'] .qls-form-row input,
[data-theme='dark'] .qls-form-row select {
    background: var(--dm-bg, #0f172a);
    border-color: var(--dm-border, #334155);
    color: var(--dm-text, #e2e8f0);
}

html.dark-mode .qls-fee-preview,
body.dark-mode .qls-fee-preview,
[data-theme='dark'] .qls-fee-preview {
    color: var(--dm-text-muted, #94a3b8);
}

html.dark-mode .qls-withdraw-notice,
body.dark-mode .qls-withdraw-notice,
[data-theme='dark'] .qls-withdraw-notice {
    background: rgba(245, 158, 11, 0.15);
    color: #fcd34d;
}

html.dark-mode .qls-withdraw-item,
body.dark-mode .qls-withdraw-item,
[data-theme='dark'] .qls-withdraw-item {
    background: var(--dm-bg-card, #1e293b);
    border-color: var(--dm-border, #334155);
}

html.dark-mode .qls-withdraw-amount,
body.dark-mode .qls-withdraw-amount,
[data-theme='dark'] .qls-withdraw-amount {
    color: var(--dm-text, #e2e8f0);
}

html.dark-mode .qls-withdraw-amount .qls-fee,
body.dark-mode .qls-withdraw-amount .qls-fee,
[data-theme='dark'] .qls-withdraw-amount .qls-fee {
    color: var(--dm-text-muted, #94a3b8);
}

html.dark-mode .qls-withdraw-account,
html.dark-mode .qls-withdraw-time,
body.dark-mode .qls-withdraw-account,
body.dark-mode .qls-withdraw-time,
[data-theme='dark'] .qls-withdraw-account,
[data-theme='dark'] .qls-withdraw-time {
    color: var(--dm-text-muted, #94a3b8);
}

html.dark-mode .qls-withdraw-note,
body.dark-mode .qls-withdraw-note,
[data-theme='dark'] .qls-withdraw-note {
    color: #fca5a5;
}

html.dark-mode .qls-withdraw-status.pending,
body.dark-mode .qls-withdraw-status.pending,
[data-theme='dark'] .qls-withdraw-status.pending {
    background: rgba(245, 158, 11, 0.2);
    color: #fcd34d;
}

html.dark-mode .qls-withdraw-status.completed,
body.dark-mode .qls-withdraw-status.completed,
[data-theme='dark'] .qls-withdraw-status.completed {
    background: rgba(16, 185, 129, 0.2);
    color: #6ee7b7;
}

html.dark-mode .qls-withdraw-status.rejected,
body.dark-mode .qls-withdraw-status.rejected,
[data-theme='dark'] .qls-withdraw-status.rejected {
    background: rgba(239, 68, 68, 0.2);
    color: #fca5a5;
}
</style>
