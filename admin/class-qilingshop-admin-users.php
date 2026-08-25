<?php
/**
 * 用户管理后台
 */
if (!defined('ABSPATH')) exit;

class QilingShop_Admin_Users {
    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_qilingshop_user_action', [$this, 'handle_ajax']);
        add_action('wp_ajax_qilingshop_get_points_log', [$this, 'ajax_get_points_log']);
    }

    public function render() {
        $db = QilingShop_Database::instance();
        $points_manager = QilingShop_Points::instance();
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $consume = isset($_GET['consume']) ? sanitize_key($_GET['consume']) : 'all';
        $gift_notice = '';
        $gift_notice_type = 'success';

        global $wpdb;
        $user_info_table = $db->get_table('user_info');
        $orders_table = $db->get_table('orders');
        $recharge_table = $db->get_table('recharge');
        $downloads_table = $db->get_table('downloads');
        $vip_log_table = $db->get_table('vip_log');
        $vip_levels = class_exists('QilingShop_VIP') ? QilingShop_VIP::instance()->get_levels() : [];
        $vip_levels = array_values(array_filter((array) $vip_levels, function($level) {
            return !empty($level->is_active);
        }));

        $consume_options = [
            'all'      => __('全部用户', 'qilingshop'),
            'active'   => __('交易用户', 'qilingshop'),
            'purchase' => __('购买用户', 'qilingshop'),
            'recharge' => __('充值用户', 'qilingshop'),
            'download' => __('下载用户', 'qilingshop'),
            'vip'      => __('当前VIP', 'qilingshop'),
        ];
        if (!isset($consume_options[$consume])) {
            $consume = 'all';
        }

        $search_ids = null;
        if ($search) {
            // 搜索 WordPress 用户表获取符合条件的用户 ID
            $user_query = new WP_User_Query([
                'search'         => '*' . $search . '*',
                'search_columns' => ['user_login', 'user_nicename', 'user_email', 'display_name'],
                'fields'         => 'ID',
            ]);

            $found_ids = $user_query->get_results();
            if (!empty($found_ids)) {
                $search_ids = array_map('intval', $found_ids);
            } else {
                // 没有找到匹配用户，强制条件为假
                $search_ids = [0];
            }
        }

        $consume_sql = '';
        switch ($consume) {
            case 'active':
                $consume_sql = "ui.user_id IN (
                    SELECT DISTINCT user_id FROM (
                        SELECT user_id FROM {$orders_table} WHERE status = 1 AND user_id > 0
                        UNION
                        SELECT user_id FROM {$recharge_table} WHERE status = 1 AND user_id > 0
                        UNION
                        SELECT user_id FROM {$downloads_table} WHERE user_id > 0
                        UNION
                        SELECT user_id FROM {$vip_log_table} WHERE user_id > 0
                    ) AS qls_users
                )";
                break;
            case 'purchase':
                $consume_sql = "ui.user_id IN (
                    SELECT DISTINCT user_id FROM {$orders_table}
                    WHERE status = 1 AND user_id > 0 AND order_type = 'resource'
                )";
                break;
            case 'recharge':
                $consume_sql = "ui.user_id IN (
                    SELECT DISTINCT user_id FROM {$recharge_table}
                    WHERE status = 1 AND user_id > 0
                )";
                break;
            case 'download':
                $consume_sql = "ui.user_id IN (
                    SELECT DISTINCT user_id FROM {$downloads_table}
                    WHERE user_id > 0
                )";
                break;
            case 'vip':
                $today = esc_sql(current_time('Y-m-d'));
                $consume_sql = "ui.vip_level > 0 AND ui.vip_expires >= '{$today}'";
                break;
            case 'all':
            default:
                $consume_sql = '';
                break;
        }

        $base_where = '1=1';
        if (is_array($search_ids)) {
            $base_where .= ' AND ui.user_id IN (' . implode(',', $search_ids) . ')';
        }
        if ($consume_sql) {
            $base_where .= ' AND ' . $consume_sql;
        }

        $total_all = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$user_info_table}");
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$user_info_table} ui WHERE {$base_where}");
        $users = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ui.* FROM {$user_info_table} ui WHERE {$base_where} ORDER BY ui.id DESC LIMIT %d OFFSET %d",
                $per_page,
                ($paged - 1) * $per_page
            )
        );

        // 处理赠送 VIP
        if (isset($_POST['qilingshop_gift_vip']) && wp_verify_nonce($_POST['_wpnonce'], 'qilingshop_gift_vip')) {
            $gift_user_id = isset($_POST['gift_user_id']) ? absint($_POST['gift_user_id']) : 0;
            $gift_level_id = isset($_POST['gift_level_id']) ? absint($_POST['gift_level_id']) : 0;
            $gift_duration_days = isset($_POST['gift_duration_days']) ? absint($_POST['gift_duration_days']) : 0;

            if ($gift_user_id <= 0 || !$gift_level_id) {
                $gift_notice = __('请输入有效的用户ID和VIP等级', 'qilingshop');
                $gift_notice_type = 'error';
            } elseif (!get_user_by('ID', $gift_user_id)) {
                $gift_notice = __('用户不存在', 'qilingshop');
                $gift_notice_type = 'error';
            } elseif (empty($vip_levels)) {
                $gift_notice = __('暂无可用的VIP等级', 'qilingshop');
                $gift_notice_type = 'error';
            } else {
                $vip = QilingShop_VIP::instance();
                $level = $vip->get_level_by_id($gift_level_id);
                if (!$level || empty($level->is_active)) {
                    $gift_notice = __('VIP 等级不可用', 'qilingshop');
                    $gift_notice_type = 'error';
                } else {
                    // 确保用户积分账户存在
                    QilingShop_Points::instance()->get_user_info($gift_user_id);

                    $duration_override = $gift_duration_days > 0 ? $gift_duration_days : null;
                    $order_no = qilingshop_security()->generate_order_no('VIPG');
                    $result = $vip->upgrade($gift_user_id, $gift_level_id, 'gift', 0, $order_no, $duration_override);

                    if (!empty($result['success'])) {
                        $gift_notice = $result['message'] ?? __('赠送成功', 'qilingshop');
                        $gift_notice_type = 'success';
                    } else {
                        $gift_notice = $result['message'] ?? __('赠送失败，请重试', 'qilingshop');
                        $gift_notice_type = 'error';
                    }
                }
            }
        }

        // 处理积分调整
        if (isset($_POST['qilingshop_adjust_points']) && wp_verify_nonce($_POST['_wpnonce'], 'adjust_points')) {
            $user_id = intval($_POST['user_id']);
            $amount = floatval($_POST['amount']);
            $type = sanitize_key($_POST['type'] ?? 'add');
            $note = sanitize_text_field($_POST['note'] ?? '');
            $success = false;

            if ($type === 'add') {
                $success = $points_manager->add_points($user_id, $amount, 'admin', $note ?: __('管理员调整', 'qilingshop'));
            } elseif ($type === 'deduct') {
                $success = $points_manager->deduct_points($user_id, $amount, 'admin', $note ?: __('管理员扣除', 'qilingshop'));
            } elseif ($type === 'freeze') {
                $success = $points_manager->freeze_points($user_id, $amount, $note ?: __('管理员冻结积分', 'qilingshop'));
            } elseif ($type === 'unfreeze') {
                $success = $points_manager->unfreeze_points($user_id, $amount, $note ?: __('管理员解冻积分', 'qilingshop'));
            }

            if ($success) {
                echo '<div class="notice notice-success"><p>' . __('操作成功', 'qilingshop') . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . __('操作失败，请检查余额/冻结余额是否充足', 'qilingshop') . '</p></div>';
            }
        }
        ?>
        <div class="wrap qilingshop-admin-page qilingshop-vr-page qilingshop-users-page">
            <h1><?php _e('用户管理', 'qilingshop'); ?></h1>

            <?php if ($gift_notice !== '') : ?>
                <div class="notice notice-<?php echo esc_attr($gift_notice_type); ?> is-dismissible">
                    <p><?php echo esc_html($gift_notice); ?></p>
                </div>
            <?php endif; ?>
            
            <form method="get">
                <input type="hidden" name="page" value="qilingshop-users">
                <p class="search-box">
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php _e('搜索用户(用户名/邮箱/昵称)...', 'qilingshop'); ?>">
                    <button type="submit" class="button"><?php _e('搜索', 'qilingshop'); ?></button>
                </p>
                <div class="qls-user-filters">
                    <label for="qls-consume-filter"><?php _e('消费类型', 'qilingshop'); ?>:</label>
                    <select name="consume" id="qls-consume-filter">
                        <?php foreach ($consume_options as $key => $label): ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($consume, $key); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button"><?php _e('筛选', 'qilingshop'); ?></button>
                    <span class="qls-user-counts">
                        <?php echo esc_html(sprintf(__('总用户数：%d', 'qilingshop'), $total_all)); ?>
                        <span class="sep">|</span>
                        <?php echo esc_html(sprintf(__('当前筛选：%s（%d）', 'qilingshop'), $consume_options[$consume], $total)); ?>
                    </span>
                </div>
            </form>

            <div class="qls-gift-vip-box">
                <h2><?php _e('赠送 VIP', 'qilingshop'); ?></h2>
                <?php if (empty($vip_levels)) : ?>
                    <p><?php _e('暂无可用的 VIP 等级，请先在“VIP 等级”中配置。', 'qilingshop'); ?></p>
                <?php else : ?>
                    <form method="post" class="qls-gift-vip-form">
                        <?php wp_nonce_field('qilingshop_gift_vip'); ?>
                        <input type="hidden" name="qilingshop_gift_vip" value="1" />
                        <label>
                            <?php _e('用户ID', 'qilingshop'); ?>
                            <input type="number" name="gift_user_id" min="1" required placeholder="<?php _e('输入用户ID', 'qilingshop'); ?>">
                        </label>
                        <label>
                            <?php _e('VIP 等级', 'qilingshop'); ?>
                            <select name="gift_level_id" required>
                                <?php foreach ($vip_levels as $level) : ?>
                                    <option value="<?php echo (int) $level->id; ?>">
                                        <?php echo esc_html($level->level_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <?php _e('赠送天数', 'qilingshop'); ?>
                            <input type="number" name="gift_duration_days" min="0" placeholder="<?php _e('留空使用等级默认时长', 'qilingshop'); ?>">
                        </label>
                        <button type="submit" class="button button-primary"><?php _e('确认赠送', 'qilingshop'); ?></button>
                    </form>
                <?php endif; ?>
            </div>
            
            <table class="wp-list-table qls-ui-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('用户', 'qilingshop'); ?></th>
                        <th><?php _e('积分余额', 'qilingshop'); ?></th>
                        <th><?php _e('冻结积分', 'qilingshop'); ?></th>
                        <th><?php _e('消费总额', 'qilingshop'); ?></th>
                        <th><?php _e('VIP', 'qilingshop'); ?></th>
                        <th><?php _e('过期时间', 'qilingshop'); ?></th>
                        <th><?php _e('邀请人数', 'qilingshop'); ?></th>
                        <th><?php _e('注册时间', 'qilingshop'); ?></th>
                        <th><?php _e('操作', 'qilingshop'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                    <tr><td colspan="9"><?php _e('暂无用户数据', 'qilingshop'); ?></td></tr>
                    <?php else: ?>
                    <?php foreach ($users as $u): 
                        $wp_user = get_user_by('ID', $u->user_id);
                        $vip_name = QilingShop_VIP::instance()->get_user_level_name($u->user_id);
                    ?>
                    <tr>
                        <td>
                            <?php if ($wp_user): ?>
                                <?php echo get_avatar($u->user_id, 32); ?>
                                <strong><?php echo esc_html($wp_user->user_login); ?></strong>
                                <br><small><?php echo esc_html($wp_user->user_email); ?></small>
                            <?php else: ?>
                                <?php echo $u->user_id; ?> (<?php _e('已删除', 'qilingshop'); ?>)
                            <?php endif; ?>
                        </td>
                        <td><?php echo qilingshop_format_points($u->points_balance); ?></td>
                        <td><?php echo qilingshop_format_points($points_manager->get_frozen_balance($u->user_id)); ?></td>
                        <td><?php echo qilingshop_format_points($u->total_consumed); ?></td>
                        <td><?php echo esc_html($vip_name); ?></td>
                        <td><?php echo $u->vip_level > 0 ? esc_html($u->vip_expires) : '-'; ?></td>
                        <td><?php echo $u->invite_count; ?></td>
                        <td><?php echo esc_html($u->created_at); ?></td>
                        <td>
                            <a href="#" class="adjust-points" data-user="<?php echo $u->user_id; ?>"><?php _e('调整积分', 'qilingshop'); ?></a> |
                            <a href="#" class="view-points-log" data-user="<?php echo $u->user_id; ?>"><?php _e('积分流水', 'qilingshop'); ?></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php
            $total_pages = ceil($total / $per_page);
            if ($total_pages > 1) {
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo paginate_links(['base' => add_query_arg('paged', '%#%'), 'current' => $paged, 'total' => $total_pages]);
                echo '</div></div>';
            }
            ?>
            
            <!-- 调整积分弹窗 -->
            <div class="qilingshop-modal-overlay" id="adjust-points-overlay" style="display:none;">
                <div class="qilingshop-modal">
                    <a href="#" class="qilingshop-modal-close">&times;</a>
                    <h3><?php _e('调整积分', 'qilingshop'); ?></h3>
                    <form method="post">
                        <?php wp_nonce_field('adjust_points'); ?>
                        <input type="hidden" name="user_id" id="adjust-user-id">
                        <p>
                            <select name="type" class="qls-user-modal-input">
                                <option value="add"><?php _e('增加', 'qilingshop'); ?></option>
                                <option value="deduct"><?php _e('扣除', 'qilingshop'); ?></option>
                                <option value="freeze"><?php _e('冻结', 'qilingshop'); ?></option>
                                <option value="unfreeze"><?php _e('解冻', 'qilingshop'); ?></option>
                            </select>
                        </p>
                        <p><input type="number" name="amount" step="0.01" class="qls-user-modal-input" placeholder="<?php _e('积分数量', 'qilingshop'); ?>" required></p>
                        <p><input type="text" name="note" class="qls-user-modal-input" placeholder="<?php _e('备注（可选）', 'qilingshop'); ?>"></p>
                        <div class="qilingshop-modal-footer">
                            <button type="button" class="button button-secondary modal-cancel"><?php _e('取消', 'qilingshop'); ?></button>
                            <button type="submit" name="qilingshop_adjust_points" class="button button-primary"><?php _e('确定', 'qilingshop'); ?></button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- 积分流水弹窗 -->
            <div class="qilingshop-modal-overlay" id="points-log-overlay" style="display:none;">
                <div class="qilingshop-modal large-modal">
                    <a href="#" class="qilingshop-modal-close">&times;</a>
                    <h3><?php _e('积分流水记录', 'qilingshop'); ?></h3>
                    <div id="points-log-content">
                        <p class="qls-user-log-loading"><span class="spinner is-active qls-user-spinner-inline"></span> <?php _e('加载中...', 'qilingshop'); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <script>
        jQuery(document).ready(function($){
            function escapeHtml(value) {
                return $('<div>').text(value == null ? '' : String(value)).html();
            }

            // 调整积分弹窗
            $('.adjust-points').click(function(e){
                e.preventDefault();
                $('#adjust-user-id').val($(this).data('user'));
                $('#adjust-points-overlay').css('display', 'flex');
            });

            // 查看积分流水
            $('.view-points-log').click(function(e){
                e.preventDefault();
                var userId = $(this).data('user');
                $('#points-log-overlay').css('display', 'flex');
                $('#points-log-content').html('<p class="qls-user-log-loading"><span class="spinner is-active qls-user-spinner-inline"></span> <?php _e('加载中...', 'qilingshop'); ?></p>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'qilingshop_get_points_log',
                        user_id: userId,
                        nonce: '<?php echo wp_create_nonce("qilingshop_admin"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            var html = '<table class="points-log-table"><thead><tr><th><?php _e('时间', 'qilingshop'); ?></th><th><?php _e('类型', 'qilingshop'); ?></th><th><?php _e('变动', 'qilingshop'); ?></th><th><?php _e('描述', 'qilingshop'); ?></th></tr></thead><tbody>';
                            
                            if (response.data.length > 0) {
                                $.each(response.data, function(i, log){
                                    var amountClass = log.type === 'income' ? 'log-income' : 'log-expense';
                                    var amountPrefix = log.type === 'income' ? '+' : '-';
                                    
                                    html += '<tr>';
                                    html += '<td>' + escapeHtml(log.created_at) + '</td>';
                                    html += '<td>' + (log.type === 'income' ? '<?php _e('收入', 'qilingshop'); ?>' : '<?php _e('支出', 'qilingshop'); ?>') + '</td>';
                                    html += '<td class="' + amountClass + '">' + escapeHtml(amountPrefix + log.amount) + '</td>';
                                    html += '<td>' + escapeHtml(log.description) + '</td>';
                                    html += '</tr>';
                                });
                            } else {
                                html += '<tr><td colspan="4" class="qls-user-log-empty"><?php _e('暂无记录', 'qilingshop'); ?></td></tr>';
                            }
                            
                            html += '</tbody></table>';
                            $('#points-log-content').html(html);
                        } else {
                            $('#points-log-content').html('<p class="qls-user-log-error">' + escapeHtml(response.data || '<?php _e('加载失败', 'qilingshop'); ?>') + '</p>');
                        }
                    },
                    error: function() {
                        $('#points-log-content').html('<p class="qls-user-log-error"><?php _e('网络错误', 'qilingshop'); ?></p>');
                    }
                });
            });

            // 通用关闭
            $('.qilingshop-modal-close, .modal-cancel').click(function(e){
                e.preventDefault();
                $('.qilingshop-modal-overlay').hide();
            });

            $('.qilingshop-modal-overlay').click(function(e){
                if (e.target === this) {
                    $(this).hide();
                }
            });
        });
        </script>
        <?php
    }

    public function handle_ajax() {
        check_ajax_referer('qilingshop_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error();
        wp_send_json_success();
    }

    /**
     * AJAX 获取积分流水
     */
    public function ajax_get_points_log() {
        check_ajax_referer('qilingshop_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('权限不足', 'qilingshop'));
        }

        $user_id = intval($_POST['user_id']);
        if (!$user_id) {
            wp_send_json_error(__('无效用户', 'qilingshop'));
        }

        $db = QilingShop_Database::instance();
        $logs = $db->get_results('points_log', [
            'where'   => ['user_id' => $user_id],
            'orderby' => 'id',
            'order'   => 'DESC',
            'limit'   => 50 // 只显示最近 50 条
        ]);

        if (is_array($logs)) {
            foreach ($logs as $log) {
                if (!is_object($log)) {
                    continue;
                }

                $log->created_at = sanitize_text_field((string) ($log->created_at ?? ''));
                $log->type = sanitize_key((string) ($log->type ?? ''));
                $log->amount = (float) ($log->amount ?? 0);
                $log->description = sanitize_textarea_field((string) ($log->description ?? ''));
            }
        }

        wp_send_json_success($logs);
    }
}
