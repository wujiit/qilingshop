<?php
/**
 * 注册码后台管理
 *
 * @package QilingShop
 * @since   2.0.7
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Admin_Registration_Codes {

    /**
     * 单例实例
     *
     * @var QilingShop_Admin_Registration_Codes|null
     */
    private static $instance = null;

    /**
     * 注册码服务
     *
     * @var QilingShop_Registration_Code
     */
    private $service;

    /**
     * 获取单例
     *
     * @return QilingShop_Admin_Registration_Codes
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 构造
     */
    private function __construct() {
        $this->service = QilingShop_Registration_Code::instance();
        add_action('admin_init', [$this, 'handle_actions']);
    }

    /**
     * 处理页面操作
     */
    public function handle_actions() {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        $page = isset($_REQUEST['page']) ? sanitize_key($_REQUEST['page']) : '';
        if ($page !== 'qilingshop-registration-codes') {
            return;
        }

        // 生成注册码
        if (isset($_POST['qilingshop_create_codes'])) {
            check_admin_referer('qilingshop_create_codes_action', 'qilingshop_create_codes_nonce');

            $count = isset($_POST['create_count']) ? absint($_POST['create_count']) : 1;
            $prefix = isset($_POST['create_prefix']) ? sanitize_text_field(wp_unslash($_POST['create_prefix'])) : 'QLS';
            $max_uses = isset($_POST['create_max_uses']) ? absint($_POST['create_max_uses']) : 1;
            $expires_at = isset($_POST['create_expires_at']) ? sanitize_text_field(wp_unslash($_POST['create_expires_at'])) : '';
            $note = isset($_POST['create_note']) ? sanitize_text_field(wp_unslash($_POST['create_note'])) : '';

            $result = $this->service->generate_codes($count, [
                'prefix'     => $prefix,
                'max_uses'   => $max_uses,
                'expires_at' => $expires_at,
                'note'       => $note,
            ]);

            $msg = ($result['ok'] > 0)
                ? sprintf(__('已生成 %d/%d 个注册码', 'qilingshop'), (int) $result['ok'], (int) $result['total'])
                : __('未生成任何注册码，请重试', 'qilingshop');
            $type = ($result['ok'] > 0) ? 'success' : 'error';
            $this->redirect_with_notice('codes', '', '', 0, $msg, $type);
        }

        $action = '';
        if (isset($_POST['qls_rc_action'])) {
            $action = sanitize_key((string) wp_unslash($_POST['qls_rc_action']));
        } elseif (isset($_GET['qls_rc_action'])) {
            $action = sanitize_key((string) wp_unslash($_GET['qls_rc_action']));
        }

        if ($action === '') {
            return;
        }

        $search = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';
        $status = isset($_REQUEST['status']) ? sanitize_key($_REQUEST['status']) : '';
        $code_id = isset($_REQUEST['code_id']) ? absint($_REQUEST['code_id']) : 0;
        $is_post_action = isset($_POST['qls_rc_action']);

        if ($action === 'clear_logs') {
            if (!$is_post_action) {
                return;
            }

            $nonce_ok = isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'qilingshop_rc_clear_logs');
            if (!$nonce_ok) {
                wp_die(__('请求校验失败', 'qilingshop'));
            }

            $deleted = $this->service->clear_logs([
                'status'  => $status,
                'search'  => $search,
                'code_id' => $code_id,
            ]);
            $ok = $deleted !== false;
            if ($ok) {
                $msg = ((int) $deleted > 0)
                    ? sprintf(__('已清理 %d 条记录', 'qilingshop'), (int) $deleted)
                    : __('没有可清理的记录', 'qilingshop');
            } else {
                $msg = __('清理失败，请稍后重试', 'qilingshop');
            }

            $this->redirect_with_notice('logs', $search, $status, $code_id, $msg, $ok ? 'success' : 'error');
        }

        if ($action === 'delete_log') {
            if (!$is_post_action) {
                return;
            }

            $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
            if ($id <= 0) {
                return;
            }

            $nonce_ok = isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'qilingshop_rc_delete_log_' . $id);
            if (!$nonce_ok) {
                wp_die(__('请求校验失败', 'qilingshop'));
            }

            $ok = $this->service->delete_log($id);
            $msg = $ok ? __('记录删除成功', 'qilingshop') : __('记录删除失败，预占中的日志需等待流程结束或超时后再清理', 'qilingshop');
            $this->redirect_with_notice('logs', $search, $status, $code_id, $msg, $ok ? 'success' : 'error');
        }

        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        if ($id <= 0 || !in_array($action, ['enable', 'disable', 'delete'], true)) {
            return;
        }

        $nonce_ok = isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'qilingshop_rc_' . $action . '_' . $id);
        if (!$nonce_ok) {
            wp_die(__('请求校验失败', 'qilingshop'));
        }

        $ok = false;
        if ($action === 'enable') {
            $ok = $this->service->update_code_status($id, 'active');
        } elseif ($action === 'disable') {
            $ok = $this->service->update_code_status($id, 'disabled');
        } elseif ($action === 'delete') {
            $ok = $this->service->delete_code($id);
        }

        if ($ok) {
            $msg = __('操作成功', 'qilingshop');
        } elseif ($action === 'delete') {
            $msg = __('删除失败，注册码仍有关联的预占注册流程，请等待流程结束或超时后再删除', 'qilingshop');
        } else {
            $msg = __('操作失败', 'qilingshop');
        }
        $this->redirect_with_notice('codes', '', '', 0, $msg, $ok ? 'success' : 'error');
    }

    /**
     * 渲染页面
     */
    public function render() {
        if (!current_user_can('manage_options')) {
            wp_die(__('权限不足', 'qilingshop'));
        }

        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'codes';
        if (!in_array($tab, ['codes', 'logs'], true)) {
            $tab = 'codes';
        }

        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $status = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
        $code_id = isset($_GET['code_id']) ? absint($_GET['code_id']) : 0;
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;

        if ($tab === 'codes') {
            $per_page = 20;
            $total = $this->service->count_codes([
                'status' => $status,
                'search' => $search,
            ]);
            $rows = $this->service->get_codes([
                'status'   => $status,
                'search'   => $search,
                'page'     => $paged,
                'per_page' => $per_page,
            ]);
        } else {
            $per_page = 30;
            $total = $this->service->count_logs([
                'status'  => $status,
                'search'  => $search,
                'code_id' => $code_id,
            ]);
            $rows = $this->service->get_logs([
                'status'   => $status,
                'search'   => $search,
                'code_id'  => $code_id,
                'page'     => $paged,
                'per_page' => $per_page,
            ]);
        }

        $total_pages = max(1, (int) ceil($total / $per_page));
        ?>
        <div class="wrap qilingshop-admin-page qilingshop-vr-page qilingshop-registration-codes-page">
            <h1><?php _e('注册码管理', 'qilingshop'); ?></h1>

            <?php $this->render_notice(); ?>

            <h2 class="nav-tab-wrapper qls-ui-tabs">
                <a href="<?php echo esc_url(admin_url('admin.php?page=qilingshop-registration-codes&tab=codes')); ?>"
                   class="nav-tab <?php echo $tab === 'codes' ? 'nav-tab-active' : ''; ?>">
                   <?php _e('注册码列表', 'qilingshop'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=qilingshop-registration-codes&tab=logs')); ?>"
                   class="nav-tab <?php echo $tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                   <?php _e('使用记录', 'qilingshop'); ?>
                </a>
            </h2>

            <?php if ($tab === 'codes') : ?>
                <div class="qls-admin-bulk-panel">
                    <h2 class="qls-admin-bulk-panel-title"><?php _e('批量生成注册码', 'qilingshop'); ?></h2>
                    <form method="post">
                        <?php wp_nonce_field('qilingshop_create_codes_action', 'qilingshop_create_codes_nonce'); ?>
                        <table class="form-table qls-ui-form-table" role="presentation">
                            <tr>
                                <th scope="row"><?php _e('生成数量', 'qilingshop'); ?></th>
                                <td><input type="number" name="create_count" min="1" max="500" value="20" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('前缀', 'qilingshop'); ?></th>
                                <td><input type="text" name="create_prefix" value="QLS" maxlength="10" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('单码可用次数', 'qilingshop'); ?></th>
                                <td><input type="number" name="create_max_uses" min="1" max="999999" value="1" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('过期时间', 'qilingshop'); ?></th>
                                <td>
                                    <input type="datetime-local" name="create_expires_at" value="" />
                                    <p class="description"><?php _e('留空表示永不过期', 'qilingshop'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('备注', 'qilingshop'); ?></th>
                                <td><input type="text" name="create_note" value="" class="regular-text" maxlength="255" /></td>
                            </tr>
                        </table>
                        <p>
                            <button type="submit" name="qilingshop_create_codes" class="button button-primary">
                                <?php _e('生成注册码', 'qilingshop'); ?>
                            </button>
                        </p>
                    </form>
                </div>
            <?php endif; ?>

            <form method="get" class="qls-admin-filter-form">
                <input type="hidden" name="page" value="qilingshop-registration-codes" />
                <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>" />
                <?php if ($code_id > 0) : ?>
                    <input type="hidden" name="code_id" value="<?php echo (int) $code_id; ?>" />
                <?php endif; ?>
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php _e('搜索注册码/用户', 'qilingshop'); ?>" />
                <select name="status">
                    <option value=""><?php _e('全部状态', 'qilingshop'); ?></option>
                    <?php if ($tab === 'codes') : ?>
                        <option value="active" <?php selected($status, 'active'); ?>><?php _e('启用', 'qilingshop'); ?></option>
                        <option value="disabled" <?php selected($status, 'disabled'); ?>><?php _e('禁用', 'qilingshop'); ?></option>
                    <?php else : ?>
                        <option value="success" <?php selected($status, 'success'); ?>><?php _e('成功', 'qilingshop'); ?></option>
                        <option value="failed" <?php selected($status, 'failed'); ?>><?php _e('失败', 'qilingshop'); ?></option>
                        <option value="reserved" <?php selected($status, 'reserved'); ?>><?php _e('预占中', 'qilingshop'); ?></option>
                        <option value="rollback" <?php selected($status, 'rollback'); ?>><?php _e('已回滚', 'qilingshop'); ?></option>
                    <?php endif; ?>
                </select>
                <button type="submit" class="button"><?php _e('筛选', 'qilingshop'); ?></button>
            </form>

            <?php if ($tab === 'logs') : ?>
                <?php
                $has_filter = ($search !== '' || $status !== '' || $code_id > 0);
                $clear_label = $has_filter ? __('清空当前筛选记录', 'qilingshop') : __('清空全部记录', 'qilingshop');
                ?>
                <form method="post" class="qls-admin-record-actions qls-admin-inline-action"
                      onsubmit="return confirm('<?php echo esc_js(__('确定要清空当前日志记录吗？此操作不可恢复。', 'qilingshop')); ?>');">
                    <?php wp_nonce_field('qilingshop_rc_clear_logs'); ?>
                    <input type="hidden" name="page" value="qilingshop-registration-codes" />
                    <input type="hidden" name="tab" value="logs" />
                    <input type="hidden" name="qls_rc_action" value="clear_logs" />
                    <?php if ($search !== '') : ?>
                        <input type="hidden" name="s" value="<?php echo esc_attr($search); ?>" />
                    <?php endif; ?>
                    <?php if ($status !== '') : ?>
                        <input type="hidden" name="status" value="<?php echo esc_attr($status); ?>" />
                    <?php endif; ?>
                    <?php if ($code_id > 0) : ?>
                        <input type="hidden" name="code_id" value="<?php echo (int) $code_id; ?>" />
                    <?php endif; ?>
                    <button type="submit" class="button qls-admin-link-danger"><?php echo esc_html($clear_label); ?></button>
                </form>
                <p class="description qls-admin-record-actions-note">
                    <?php _e('默认清理不包含“预占中”记录；如需清理，请先筛选“预占中”状态后执行。', 'qilingshop'); ?>
                </p>
            <?php endif; ?>

            <?php if ($tab === 'codes') : ?>
                <?php $this->render_codes_table($rows); ?>
            <?php else : ?>
                <?php $this->render_logs_table($rows, $search, $status, $code_id); ?>
            <?php endif; ?>

            <?php $this->render_pagination($tab, $paged, $total_pages, $search, $status, $code_id); ?>
        </div>
        <?php
    }

    /**
     * 渲染消息
     */
    private function render_notice() {
        if (empty($_GET['qls_msg'])) {
            return;
        }

        $message = sanitize_text_field(wp_unslash($_GET['qls_msg']));
        $type = isset($_GET['qls_type']) && $_GET['qls_type'] === 'error' ? 'error' : 'success';
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    /**
     * 跳转并携带反馈提示
     *
     * @param string $tab     目标 tab
     * @param string $search  搜索词
     * @param string $status  状态筛选
     * @param int    $code_id 兑换码筛选
     * @param string $message 提示语
     * @param string $type    提示类型
     */
    private function redirect_with_notice($tab, $search, $status, $code_id, $message, $type = 'success') {
        $args = [
            'page'     => 'qilingshop-registration-codes',
            'tab'      => in_array($tab, ['codes', 'logs'], true) ? $tab : 'codes',
            'qls_msg'  => $message,
            'qls_type' => $type === 'error' ? 'error' : 'success',
        ];

        if ($search !== '') {
            $args['s'] = $search;
        }
        if ($status !== '') {
            $args['status'] = $status;
        }
        if ((int) $code_id > 0) {
            $args['code_id'] = (int) $code_id;
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    /**
     * 注册码表格
     *
     * @param array $rows 数据
     */
    private function render_codes_table($rows) {
        $now = current_time('timestamp');
        ?>
        <table class="widefat qls-ui-table striped">
            <thead>
            <tr>
                <th><?php _e('ID', 'qilingshop'); ?></th>
                <th><?php _e('注册码', 'qilingshop'); ?></th>
                <th><?php _e('状态', 'qilingshop'); ?></th>
                <th><?php _e('总次数', 'qilingshop'); ?></th>
                <th><?php _e('已使用', 'qilingshop'); ?></th>
                <th><?php _e('剩余', 'qilingshop'); ?></th>
                <th><?php _e('过期时间', 'qilingshop'); ?></th>
                <th><?php _e('备注', 'qilingshop'); ?></th>
                <th><?php _e('操作', 'qilingshop'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)) : ?>
                <tr><td colspan="9"><?php _e('暂无数据', 'qilingshop'); ?></td></tr>
            <?php else : ?>
                <?php foreach ($rows as $row) : ?>
                    <?php
                    $max_uses = (int) $row->max_uses;
                    $used_count = (int) $row->used_count;
                    $remaining = $max_uses > 0 ? max(0, $max_uses - $used_count) : __('无限', 'qilingshop');
                    $is_expired = !empty($row->expires_at) && strtotime($row->expires_at) < $now;
                    $status_text = $row->status === 'active' ? __('启用', 'qilingshop') : __('禁用', 'qilingshop');
                    if ($row->status === 'active' && $max_uses > 0 && $used_count >= $max_uses) {
                        $status_text = __('已用完', 'qilingshop');
                    }
                    if ($row->status === 'active' && $is_expired) {
                        $status_text = __('已过期', 'qilingshop');
                    }

                    $enable_url = wp_nonce_url(
                        add_query_arg([
                            'page'          => 'qilingshop-registration-codes',
                            'qls_rc_action' => 'enable',
                            'id'            => (int) $row->id,
                        ], admin_url('admin.php')),
                        'qilingshop_rc_enable_' . (int) $row->id
                    );

                    $disable_url = wp_nonce_url(
                        add_query_arg([
                            'page'          => 'qilingshop-registration-codes',
                            'qls_rc_action' => 'disable',
                            'id'            => (int) $row->id,
                        ], admin_url('admin.php')),
                        'qilingshop_rc_disable_' . (int) $row->id
                    );

                    $delete_url = wp_nonce_url(
                        add_query_arg([
                            'page'          => 'qilingshop-registration-codes',
                            'qls_rc_action' => 'delete',
                            'id'            => (int) $row->id,
                        ], admin_url('admin.php')),
                        'qilingshop_rc_delete_' . (int) $row->id
                    );

                    $logs_url = add_query_arg([
                        'page'    => 'qilingshop-registration-codes',
                        'tab'     => 'logs',
                        'code_id' => (int) $row->id,
                    ], admin_url('admin.php'));
                    ?>
                    <tr>
                        <td><?php echo (int) $row->id; ?></td>
                        <td><code><?php echo esc_html($row->code); ?></code></td>
                        <td><?php echo esc_html($status_text); ?></td>
                        <td><?php echo (int) $max_uses; ?></td>
                        <td><?php echo (int) $used_count; ?></td>
                        <td><?php echo is_numeric($remaining) ? (int) $remaining : esc_html($remaining); ?></td>
                        <td><?php echo !empty($row->expires_at) ? esc_html($row->expires_at) : __('永不过期', 'qilingshop'); ?></td>
                        <td><?php echo esc_html($row->note); ?></td>
                        <td>
                            <?php if ($row->status === 'active') : ?>
                                <a href="<?php echo esc_url($disable_url); ?>"><?php _e('禁用', 'qilingshop'); ?></a> |
                            <?php else : ?>
                                <a href="<?php echo esc_url($enable_url); ?>"><?php _e('启用', 'qilingshop'); ?></a> |
                            <?php endif; ?>
                            <a href="<?php echo esc_url($logs_url); ?>"><?php _e('记录', 'qilingshop'); ?></a> |
                            <a href="<?php echo esc_url($delete_url); ?>" class="qls-admin-link-danger" onclick="return confirm('<?php echo esc_js(__('确定删除该注册码？', 'qilingshop')); ?>');">
                                <?php _e('删除', 'qilingshop'); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * 日志表格
     *
     * @param array $rows 数据
     */
    private function render_logs_table($rows, $search = '', $status = '', $code_id = 0) {
        ?>
        <table class="widefat qls-ui-table striped">
            <thead>
            <tr>
                <th><?php _e('时间', 'qilingshop'); ?></th>
                <th><?php _e('注册码', 'qilingshop'); ?></th>
                <th><?php _e('用户', 'qilingshop'); ?></th>
                <th><?php _e('手机号', 'qilingshop'); ?></th>
                <th><?php _e('邮箱', 'qilingshop'); ?></th>
                <th><?php _e('来源', 'qilingshop'); ?></th>
                <th><?php _e('状态', 'qilingshop'); ?></th>
                <th><?php _e('说明', 'qilingshop'); ?></th>
                <th><?php _e('IP', 'qilingshop'); ?></th>
                <th><?php _e('操作', 'qilingshop'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)) : ?>
                <tr><td colspan="10"><?php _e('暂无记录', 'qilingshop'); ?></td></tr>
            <?php else : ?>
                <?php foreach ($rows as $row) : ?>
                    <?php
                    $user_label = '-';
                    if ((int) $row->user_id > 0) {
                        $display_name = !empty($row->user_display_name) ? (string) $row->user_display_name : '';
                        $user_login = !empty($row->user_login) ? (string) $row->user_login : '';

                        if ($display_name === '' && $user_login === '') {
                            $user_label = sprintf(__('用户已删除 (ID:%d)', 'qilingshop'), (int) $row->user_id);
                        } else {
                            $primary = $display_name !== '' ? $display_name : $user_login;
                            $secondary = ($display_name !== '' && $user_login !== '' && $display_name !== $user_login) ? $user_login : '';
                            $user_link = admin_url('user-edit.php?user_id=' . (int) $row->user_id);

                            $user_label = '<a href="' . esc_url($user_link) . '">'
                                . esc_html($primary)
                                . '</a>'
                                . ' (ID:' . (int) $row->user_id . ')';
                            if ($secondary !== '') {
                                $user_label .= '<br><small>' . esc_html($secondary) . '</small>';
                            }
                        }
                    }
                    ?>
                    <tr>
                        <td><?php echo esc_html($row->created_at); ?></td>
                        <td><code><?php echo esc_html($row->code); ?></code></td>
                        <td>
                            <?php echo $user_label; ?>
                        </td>
                        <td>
                            <?php
                            $phone = isset($row->user_phone) ? (string) $row->user_phone : '';
                            echo $phone !== '' ? esc_html($this->service->mask_phone($phone)) : '-';
                            ?>
                        </td>
                        <td><?php echo !empty($row->user_email) ? esc_html($row->user_email) : '-'; ?></td>
                        <td><?php echo esc_html($row->register_source); ?></td>
                        <td><?php echo esc_html($row->status); ?></td>
                        <td><?php echo esc_html($row->message); ?></td>
                        <td><?php echo esc_html($row->ip_address); ?></td>
                        <td>
                            <form method="post" class="qls-admin-inline-action"
                                  onsubmit="return confirm('<?php echo esc_js(__('确定删除该条记录？', 'qilingshop')); ?>');">
                                <?php wp_nonce_field('qilingshop_rc_delete_log_' . (int) $row->id); ?>
                                <input type="hidden" name="page" value="qilingshop-registration-codes" />
                                <input type="hidden" name="tab" value="logs" />
                                <input type="hidden" name="qls_rc_action" value="delete_log" />
                                <input type="hidden" name="id" value="<?php echo (int) $row->id; ?>" />
                                <?php if ($search !== '') : ?>
                                    <input type="hidden" name="s" value="<?php echo esc_attr($search); ?>" />
                                <?php endif; ?>
                                <?php if ($status !== '') : ?>
                                    <input type="hidden" name="status" value="<?php echo esc_attr($status); ?>" />
                                <?php endif; ?>
                                <?php if ((int) $code_id > 0) : ?>
                                    <input type="hidden" name="code_id" value="<?php echo (int) $code_id; ?>" />
                                <?php endif; ?>
                                <button type="submit" class="button-link qls-admin-link-danger"><?php _e('删除', 'qilingshop'); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * 分页
     *
     * @param string $tab 当前 tab
     * @param int    $paged 当前页
     * @param int    $total_pages 总页数
     * @param string $search 搜索
     * @param string $status 状态
     * @param int    $code_id code id
     */
    private function render_pagination($tab, $paged, $total_pages, $search, $status, $code_id) {
        if ($total_pages <= 1) {
            return;
        }

        $base_args = [
            'page'   => 'qilingshop-registration-codes',
            'tab'    => $tab,
            's'      => $search,
            'status' => $status,
        ];
        if ($code_id > 0) {
            $base_args['code_id'] = $code_id;
        }

        $base_url = add_query_arg($base_args, admin_url('admin.php'));
        echo '<div class="tablenav"><div class="tablenav-pages">';
        echo paginate_links([
            'base'      => add_query_arg('paged', '%#%', $base_url),
            'format'    => '',
            'current'   => $paged,
            'total'     => $total_pages,
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
        ]);
        echo '</div></div>';
    }
}
