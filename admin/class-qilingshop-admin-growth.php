<?php
/**
 * 成长体系后台管理。
 *
 * @package QilingShop
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Admin_Growth {
    private static $instance = null;

    /**
     * @var QilingShop_Database
     */
    private $db;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->db = QilingShop_Database::instance();
        add_action('admin_init', [$this, 'handle_actions']);
    }

    public function handle_actions() {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        $page = isset($_REQUEST['page']) ? sanitize_key((string) wp_unslash($_REQUEST['page'])) : '';
        if ($page !== 'qilingshop-growth' || empty($_POST['qilingshop_growth_action'])) {
            return;
        }

        $action = sanitize_key((string) wp_unslash($_POST['qilingshop_growth_action']));
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce((string) wp_unslash($_POST['_wpnonce']), 'qilingshop_growth_action')) {
            wp_die(__('请求校验失败', 'qilingshop'));
        }

        $message = '';
        $type = 'success';

        if ($action === 'save_settings') {
            update_option('qilingshop_growth_enabled', isset($_POST['growth_enabled']));
            update_option('qilingshop_growth_name', sanitize_text_field((string) wp_unslash($_POST['growth_name'] ?? '成长值')));
            update_option('qilingshop_growth_frontend_display', isset($_POST['frontend_display']));
            update_option('qilingshop_growth_show_highest_level', isset($_POST['show_highest_level']));
            update_option('qilingshop_growth_allow_admin_adjust', isset($_POST['allow_admin_adjust']));
            $message = __('成长设置已保存', 'qilingshop');
        } elseif ($action === 'save_level') {
            $ok = $this->save_level($_POST);
            $message = $ok ? __('成长等级已保存', 'qilingshop') : __('成长等级保存失败，请检查等级标识和成长值区间', 'qilingshop');
            $type = $ok ? 'success' : 'error';
        } elseif ($action === 'delete_level') {
            $level_id = absint($_POST['level_id'] ?? 0);
            $used_count = $level_id > 0 ? $this->db->count('user_growth', ['level_id' => $level_id]) : 0;
            $highest_used_count = $level_id > 0 ? $this->db->count('user_growth', ['highest_level_id' => $level_id]) : 0;
            $ok = $level_id > 0 && $used_count <= 0 && $highest_used_count <= 0 && $this->db->delete('growth_levels', ['id' => $level_id]) !== false;
            $message = ($used_count > 0 || $highest_used_count > 0) ? __('该成长等级已有用户使用，不能删除，可改为禁用', 'qilingshop') : ($ok ? __('成长等级已删除', 'qilingshop') : __('成长等级删除失败', 'qilingshop'));
            $type = $ok ? 'success' : 'error';
        } elseif ($action === 'save_benefit') {
            $ok = $this->save_benefit($_POST);
            $message = $ok ? __('成长权益已保存', 'qilingshop') : __('成长权益保存失败', 'qilingshop');
            $type = $ok ? 'success' : 'error';
        } elseif ($action === 'delete_benefit') {
            $benefit_id = absint($_POST['benefit_id'] ?? 0);
            $ok = $benefit_id > 0 && $this->db->delete('growth_benefits', ['id' => $benefit_id]) !== false;
            $message = $ok ? __('成长权益已删除', 'qilingshop') : __('成长权益删除失败', 'qilingshop');
            $type = $ok ? 'success' : 'error';
        } elseif ($action === 'save_rule') {
            $ok = $this->save_rule($_POST);
            $message = $ok ? __('成长规则已保存', 'qilingshop') : __('成长规则保存失败', 'qilingshop');
            $type = $ok ? 'success' : 'error';
        } elseif ($action === 'adjust_user_growth') {
            $ok = $this->adjust_user_growth($_POST);
            $message = $ok ? __('用户成长值已调整', 'qilingshop') : __('用户成长值调整失败', 'qilingshop');
            $type = $ok ? 'success' : 'error';
        } elseif ($action === 'recalculate_user') {
            $user_id = absint($_POST['user_id'] ?? 0);
            $ok = $user_id > 0 && QilingShop_Growth::instance()->recalculate_user_level($user_id);
            $message = $ok ? __('用户成长等级已重算', 'qilingshop') : __('用户成长等级重算失败', 'qilingshop');
            $type = $ok ? 'success' : 'error';
        } elseif ($action === 'delete_growth_logs') {
            $ids = array_filter(array_map('absint', (array) ($_POST['log_ids'] ?? [])));
            $deleted = !empty($ids) ? QilingShop_Growth::instance()->delete_logs(['ids' => $ids]) : false;
            $message = $deleted !== false ? sprintf(__('已删除 %d 条成长流水', 'qilingshop'), (int) $deleted) : __('请先勾选要删除的成长流水', 'qilingshop');
            $type = $deleted !== false ? 'success' : 'error';
        } elseif ($action === 'clear_growth_logs') {
            $filters = $this->get_log_filters_from_request($_POST);
            $confirmed = !empty($_POST['confirm_clear_growth_logs']);
            if (!$confirmed) {
                $message = __('请先勾选确认清理成长流水', 'qilingshop');
                $type = 'error';
            } elseif (!$this->has_log_filter_scope($filters)) {
                $message = __('请至少设置一个筛选条件后再清理，避免误删全部成长流水', 'qilingshop');
                $type = 'error';
            } else {
                $deleted = QilingShop_Growth::instance()->delete_logs($filters);
                $message = $deleted !== false ? sprintf(__('已按当前筛选条件清理 %d 条成长流水', 'qilingshop'), (int) $deleted) : __('成长流水清理失败', 'qilingshop');
                $type = $deleted !== false ? 'success' : 'error';
            }
        }

        if ($message !== '') {
            $current_tab = sanitize_key((string) ($_POST['current_tab'] ?? 'levels'));
            $redirect_args = [
                'page' => 'qilingshop-growth',
                'tab' => $current_tab,
                'qls_msg' => rawurlencode($message),
                'qls_type' => $type,
            ];
            if ($current_tab === 'logs') {
                $redirect_args = array_merge($redirect_args, $this->get_log_redirect_args_from_request($_POST));
            }

            wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
            exit;
        }
    }

    public function render() {
        if (!current_user_can('manage_options')) {
            wp_die(__('无权访问', 'qilingshop'));
        }

        $tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : 'overview';
        if (!in_array($tab, ['overview', 'levels', 'benefits', 'rules', 'settings', 'users', 'logs'], true)) {
            $tab = 'overview';
        }

        $tabs = [
            'overview' => __('成长概览', 'qilingshop'),
            'levels' => __('成长等级', 'qilingshop'),
            'benefits' => __('等级权益', 'qilingshop'),
            'rules' => __('成长规则', 'qilingshop'),
            'settings' => __('成长设置', 'qilingshop'),
            'users' => __('用户成长', 'qilingshop'),
            'logs' => __('成长流水', 'qilingshop'),
        ];
        ?>
        <div class="wrap qilingshop-admin-page qilingshop-vr-page qls-growth-admin">
            <h1><?php esc_html_e('成长体系', 'qilingshop'); ?></h1>
            <?php $this->render_notice(); ?>
            <nav class="nav-tab-wrapper">
                <?php foreach ($tabs as $key => $label) : ?>
                    <a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['page' => 'qilingshop-growth', 'tab' => $key], admin_url('admin.php'))); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <?php
            if ($tab === 'overview') {
                $this->render_overview();
            } elseif ($tab === 'benefits') {
                $this->render_benefits();
            } elseif ($tab === 'rules') {
                $this->render_rules();
            } elseif ($tab === 'settings') {
                $this->render_settings();
            } elseif ($tab === 'users') {
                $this->render_users();
            } elseif ($tab === 'logs') {
                $this->render_logs();
            } else {
                $this->render_levels();
            }
            ?>
        </div>
        <?php
    }

    private function render_notice() {
        if (empty($_GET['qls_msg'])) {
            return;
        }
        $type = isset($_GET['qls_type']) && $_GET['qls_type'] === 'error' ? 'error' : 'success';
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html(rawurldecode((string) wp_unslash($_GET['qls_msg']))) . '</p></div>';
    }

    private function render_overview() {
        global $wpdb;
        $db = QilingShop_Database::instance();
        $enabled = QilingShop_Growth::instance()->is_enabled();
        $growth_name = QilingShop_Growth::instance()->get_growth_name();
        $user_growth_table = $db->get_table('user_growth');
        $growth_log_table = $db->get_table('growth_log');
        $levels = QilingShop_Growth::instance()->get_levels(false);
        $benefit_count = $db->count('growth_benefits');
        $active_rule_count = $db->count('growth_rules', ['status' => 1]);
        $user_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$user_growth_table}");
        $total_growth = (float) $wpdb->get_var("SELECT COALESCE(SUM(growth_value), 0) FROM {$user_growth_table}");
        $today_growth = (float) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount), 0) FROM {$growth_log_table} WHERE created_at >= %s", date('Y-m-d 00:00:00', current_time('timestamp'))));
        ?>
        <div class="qls-growth-overview">
            <div class="qls-growth-overview-hero">
                <div>
                    <h2><?php esc_html_e('成长体系运营概览', 'qilingshop'); ?></h2>
                    <p><?php echo esc_html($enabled ? __('成长体系已启用，用户成长值、等级和权益正在生效。', 'qilingshop') : __('成长体系当前未启用，用户侧权益不会生效。', 'qilingshop')); ?></p>
                </div>
                <span class="qls-growth-status <?php echo $enabled ? 'is-on' : 'is-off'; ?>"><?php echo $enabled ? esc_html__('已启用', 'qilingshop') : esc_html__('未启用', 'qilingshop'); ?></span>
            </div>
            <div class="qls-growth-stat-grid">
                <div class="qls-growth-stat-card"><strong><?php echo esc_html(number_format($user_count)); ?></strong><span><?php esc_html_e('成长用户', 'qilingshop'); ?></span></div>
                <div class="qls-growth-stat-card"><strong><?php echo esc_html(number_format($total_growth, 2)); ?></strong><span><?php echo esc_html(sprintf(__('累计%s', 'qilingshop'), $growth_name)); ?></span></div>
                <div class="qls-growth-stat-card"><strong><?php echo esc_html(number_format($today_growth, 2)); ?></strong><span><?php echo esc_html(sprintf(__('今日发放%s', 'qilingshop'), $growth_name)); ?></span></div>
                <div class="qls-growth-stat-card"><strong><?php echo esc_html(number_format($benefit_count)); ?></strong><span><?php esc_html_e('已配置权益', 'qilingshop'); ?></span></div>
                <div class="qls-growth-stat-card"><strong><?php echo esc_html(number_format($active_rule_count)); ?></strong><span><?php esc_html_e('启用规则', 'qilingshop'); ?></span></div>
            </div>
            <div class="qls-admin-card">
                <h2><?php esc_html_e('等级用户分布', 'qilingshop'); ?></h2>
                <table class="widefat striped qls-ui-table">
                    <thead><tr><th><?php esc_html_e('等级', 'qilingshop'); ?></th><th><?php esc_html_e('成长值区间', 'qilingshop'); ?></th><th><?php esc_html_e('用户数', 'qilingshop'); ?></th><th><?php esc_html_e('权益数', 'qilingshop'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($levels as $level) :
                        $level_user_count = $db->count('user_growth', ['level_id' => (int) $level->id]);
                        $level_benefit_count = $db->count('growth_benefits', ['level_id' => (int) $level->id, 'status' => 1]);
                    ?>
                        <tr>
                            <td><span class="qls-growth-level-chip" style="background:<?php echo esc_attr($level->level_color); ?>"><?php echo esc_html($level->level_name); ?></span></td>
                            <td><?php echo esc_html(number_format((float) $level->min_growth, 2)); ?> - <?php echo $level->max_growth === null ? esc_html__('无上限', 'qilingshop') : esc_html(number_format((float) $level->max_growth, 2)); ?></td>
                            <td><?php echo esc_html(number_format($level_user_count)); ?></td>
                            <td><?php echo esc_html(number_format($level_benefit_count)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    private function render_settings() {
        ?>
        <form method="post" class="qls-admin-card qls-growth-form">
            <?php wp_nonce_field('qilingshop_growth_action'); ?>
            <input type="hidden" name="qilingshop_growth_action" value="save_settings">
            <input type="hidden" name="current_tab" value="settings">
            <table class="form-table" role="presentation">
                <tr><th><?php esc_html_e('启用成长体系', 'qilingshop'); ?></th><td><label><input type="checkbox" name="growth_enabled" value="1" <?php checked(get_option('qilingshop_growth_enabled', false)); ?>> <?php esc_html_e('启用', 'qilingshop'); ?></label></td></tr>
                <tr><th><?php esc_html_e('成长值名称', 'qilingshop'); ?></th><td><input type="text" name="growth_name" value="<?php echo esc_attr(get_option('qilingshop_growth_name', '成长值')); ?>" class="regular-text"></td></tr>
                <tr><th><?php esc_html_e('前台展示', 'qilingshop'); ?></th><td><label><input type="checkbox" name="frontend_display" value="1" <?php checked(get_option('qilingshop_growth_frontend_display', true)); ?>> <?php esc_html_e('在用户中心展示成长等级和进度', 'qilingshop'); ?></label></td></tr>
                <tr><th><?php esc_html_e('历史最高等级', 'qilingshop'); ?></th><td><label><input type="checkbox" name="show_highest_level" value="1" <?php checked(get_option('qilingshop_growth_show_highest_level', false)); ?>> <?php esc_html_e('保留历史最高等级字段用于后续展示', 'qilingshop'); ?></label></td></tr>
                <tr><th><?php esc_html_e('后台手动调整', 'qilingshop'); ?></th><td><label><input type="checkbox" name="allow_admin_adjust" value="1" <?php checked(get_option('qilingshop_growth_allow_admin_adjust', true)); ?>> <?php esc_html_e('允许管理员手动调整成长值', 'qilingshop'); ?></label></td></tr>
            </table>
            <?php submit_button(__('保存设置', 'qilingshop')); ?>
        </form>
        <?php
    }

    private function render_levels() {
        $levels = QilingShop_Growth::instance()->get_levels(false);
        $edit_id = absint($_GET['edit'] ?? 0);
        $edit = $edit_id > 0 ? QilingShop_Growth::instance()->get_level($edit_id) : null;
        ?>
        <div class="qls-growth-grid">
            <div class="qls-admin-card">
                <h2><?php echo $edit ? esc_html__('编辑成长等级', 'qilingshop') : esc_html__('新增成长等级', 'qilingshop'); ?></h2>
                <form method="post" class="qls-growth-form">
                    <?php wp_nonce_field('qilingshop_growth_action'); ?>
                    <input type="hidden" name="qilingshop_growth_action" value="save_level">
                    <input type="hidden" name="current_tab" value="levels">
                    <input type="hidden" name="level_id" value="<?php echo esc_attr($edit ? (int) $edit->id : 0); ?>">
                    <p><label><?php esc_html_e('等级标识', 'qilingshop'); ?><input type="text" name="level_key" value="<?php echo esc_attr($edit->level_key ?? ''); ?>" class="regular-text" required></label></p>
                    <p><label><?php esc_html_e('等级名称', 'qilingshop'); ?><input type="text" name="level_name" value="<?php echo esc_attr($edit->level_name ?? ''); ?>" class="regular-text" required></label></p>
                    <p><label><?php esc_html_e('最低成长值', 'qilingshop'); ?><input type="number" step="0.01" min="0" name="min_growth" value="<?php echo esc_attr($edit->min_growth ?? 0); ?>" required></label></p>
                    <p><label><?php esc_html_e('最高成长值', 'qilingshop'); ?><input type="number" step="0.01" min="0" name="max_growth" value="<?php echo esc_attr($edit && $edit->max_growth !== null ? $edit->max_growth : ''); ?>" placeholder="<?php esc_attr_e('留空表示无上限', 'qilingshop'); ?>"></label></p>
                    <p><label><?php esc_html_e('图标 URL', 'qilingshop'); ?><input type="url" name="level_icon" value="<?php echo esc_attr($edit->level_icon ?? ''); ?>" class="regular-text"></label></p>
                    <p><label><?php esc_html_e('颜色', 'qilingshop'); ?><input type="text" name="level_color" value="<?php echo esc_attr($edit->level_color ?? '#64748b'); ?>" class="regular-text"></label></p>
                    <p><label><?php esc_html_e('排序', 'qilingshop'); ?><input type="number" name="sort_order" value="<?php echo esc_attr($edit->sort_order ?? 0); ?>"></label></p>
                    <p><label><?php esc_html_e('说明', 'qilingshop'); ?><textarea name="description" class="large-text" rows="3"><?php echo esc_textarea($edit->description ?? ''); ?></textarea></label></p>
                    <p><label><input type="checkbox" name="status" value="1" <?php checked($edit ? (int) $edit->status : 1); ?>> <?php esc_html_e('启用', 'qilingshop'); ?></label></p>
                    <?php submit_button($edit ? __('保存等级', 'qilingshop') : __('新增等级', 'qilingshop')); ?>
                </form>
            </div>
            <div class="qls-admin-card">
                <h2><?php esc_html_e('等级列表', 'qilingshop'); ?></h2>
                <table class="widefat striped qls-ui-table">
                    <thead><tr><th>ID</th><th><?php esc_html_e('名称', 'qilingshop'); ?></th><th><?php esc_html_e('成长值区间', 'qilingshop'); ?></th><th><?php esc_html_e('状态', 'qilingshop'); ?></th><th><?php esc_html_e('操作', 'qilingshop'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($levels as $level) : ?>
                        <tr>
                            <td><?php echo esc_html((int) $level->id); ?></td>
                            <td><span class="qls-growth-level-chip" style="background:<?php echo esc_attr($level->level_color); ?>"><?php echo esc_html($level->level_name); ?></span></td>
                            <td><?php echo esc_html(number_format((float) $level->min_growth, 2)); ?> - <?php echo $level->max_growth === null ? esc_html__('无上限', 'qilingshop') : esc_html(number_format((float) $level->max_growth, 2)); ?></td>
                            <td><?php echo (int) $level->status ? esc_html__('启用', 'qilingshop') : esc_html__('禁用', 'qilingshop'); ?></td>
                            <td>
                                <a class="button button-small" href="<?php echo esc_url(add_query_arg(['page' => 'qilingshop-growth', 'tab' => 'levels', 'edit' => (int) $level->id], admin_url('admin.php'))); ?>"><?php esc_html_e('编辑', 'qilingshop'); ?></a>
                                <form method="post" class="qls-inline-form" onsubmit="return confirm('<?php echo esc_js(__('确定删除该成长等级吗？', 'qilingshop')); ?>');">
                                    <?php wp_nonce_field('qilingshop_growth_action'); ?>
                                    <input type="hidden" name="qilingshop_growth_action" value="delete_level">
                                    <input type="hidden" name="current_tab" value="levels">
                                    <input type="hidden" name="level_id" value="<?php echo esc_attr((int) $level->id); ?>">
                                    <button type="submit" class="button button-small"><?php esc_html_e('删除', 'qilingshop'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    private function render_users() {
        $keyword = isset($_GET['s']) ? sanitize_text_field((string) wp_unslash($_GET['s'])) : '';
        $users = $keyword !== '' ? get_users(['search' => '*' . $keyword . '*', 'number' => 50, 'search_columns' => ['user_login', 'user_email', 'display_name']]) : get_users(['number' => 50, 'orderby' => 'registered', 'order' => 'DESC']);
        ?>
        <div class="qls-admin-card">
            <form method="get" class="qls-growth-search">
                <input type="hidden" name="page" value="qilingshop-growth"><input type="hidden" name="tab" value="users">
                <input type="search" name="s" value="<?php echo esc_attr($keyword); ?>" placeholder="<?php esc_attr_e('搜索用户', 'qilingshop'); ?>">
                <button class="button"><?php esc_html_e('搜索', 'qilingshop'); ?></button>
            </form>
            <table class="widefat striped qls-ui-table">
                <thead><tr><th><?php esc_html_e('用户', 'qilingshop'); ?></th><th><?php esc_html_e('成长值', 'qilingshop'); ?></th><th><?php esc_html_e('当前等级', 'qilingshop'); ?></th><th><?php esc_html_e('调整', 'qilingshop'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($users as $user) : $account = QilingShop_Growth::instance()->get_user_growth($user->ID); $level = $account ? QilingShop_Growth::instance()->get_level((int) $account->level_id) : null; ?>
                    <tr>
                        <td><?php echo esc_html($user->display_name . ' (#' . $user->ID . ')'); ?><br><span class="description"><?php echo esc_html($user->user_email); ?></span></td>
                        <td><?php echo esc_html($account ? number_format((float) $account->growth_value, 2) : '0.00'); ?></td>
                        <td><?php echo $level ? esc_html($level->level_name) : esc_html__('暂无等级', 'qilingshop'); ?></td>
                        <td>
                            <?php if ((bool) get_option('qilingshop_growth_allow_admin_adjust', true)) : ?>
                            <form method="post" class="qls-inline-form qls-growth-adjust-form">
                                <?php wp_nonce_field('qilingshop_growth_action'); ?>
                                <input type="hidden" name="qilingshop_growth_action" value="adjust_user_growth"><input type="hidden" name="current_tab" value="users"><input type="hidden" name="user_id" value="<?php echo esc_attr($user->ID); ?>">
                                <select name="adjust_type"><option value="add"><?php esc_html_e('增加', 'qilingshop'); ?></option><option value="deduct"><?php esc_html_e('扣减', 'qilingshop'); ?></option></select>
                                <input type="number" step="0.01" min="0.01" name="amount" value="1">
                                <input type="text" name="description" value="<?php esc_attr_e('后台调整', 'qilingshop'); ?>">
                                <button class="button button-small"><?php esc_html_e('提交', 'qilingshop'); ?></button>
                            </form>
                            <?php endif; ?>
                            <form method="post" class="qls-inline-form">
                                <?php wp_nonce_field('qilingshop_growth_action'); ?>
                                <input type="hidden" name="qilingshop_growth_action" value="recalculate_user"><input type="hidden" name="current_tab" value="users"><input type="hidden" name="user_id" value="<?php echo esc_attr($user->ID); ?>">
                                <button class="button button-small"><?php esc_html_e('重算等级', 'qilingshop'); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_benefits() {
        $growth = QilingShop_Growth::instance();
        $benefits_api = QilingShop_Growth_Benefits::instance();
        $levels = $growth->get_levels(false);
        $types = $benefits_api->get_benefit_types();
        $edit_id = absint($_GET['edit_benefit'] ?? 0);
        $edit = $edit_id > 0 ? $benefits_api->get_benefit($edit_id) : null;
        $selected_type = sanitize_key((string) ($_GET['benefit_type'] ?? ($edit->benefit_type ?? 'badge')));
        if (!isset($types[$selected_type])) {
            $selected_type = key($types) ?: 'badge';
        }
        $selected_type_data = $types[$selected_type] ?? [];
        $selected_level_id = absint($_GET['level_id'] ?? ($edit->level_id ?? 0));
        $benefits = $benefits_api->get_benefits(['level_id' => $selected_level_id]);
        ?>
        <div class="qls-growth-grid">
            <div class="qls-admin-card">
                <h2><?php echo $edit ? esc_html__('编辑等级权益', 'qilingshop') : esc_html__('新增等级权益', 'qilingshop'); ?></h2>
                <p class="description"><?php esc_html_e('权益不会写死到某个等级，保存后只对所选成长等级生效。业务模块后续通过统一接口读取这些配置。', 'qilingshop'); ?></p>
                <form method="get" class="qls-growth-search qls-growth-type-switch">
                    <input type="hidden" name="page" value="qilingshop-growth">
                    <input type="hidden" name="tab" value="benefits">
                    <?php if ($edit) : ?><input type="hidden" name="edit_benefit" value="<?php echo esc_attr((int) $edit->id); ?>"><?php endif; ?>
                    <select name="benefit_type" onchange="this.form.submit()">
                        <?php foreach ($types as $type_key => $type_data) : ?>
                            <option value="<?php echo esc_attr($type_key); ?>" <?php selected($selected_type, $type_key); ?>><?php echo esc_html($type_data['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="button"><?php esc_html_e('切换权益类型', 'qilingshop'); ?></button>
                </form>
                <form method="post" class="qls-growth-form">
                    <?php wp_nonce_field('qilingshop_growth_action'); ?>
                    <input type="hidden" name="qilingshop_growth_action" value="save_benefit">
                    <input type="hidden" name="current_tab" value="benefits">
                    <input type="hidden" name="benefit_id" value="<?php echo esc_attr($edit ? (int) $edit->id : 0); ?>">
                    <input type="hidden" name="benefit_type" value="<?php echo esc_attr($selected_type); ?>">
                    <p><label><?php esc_html_e('绑定成长等级', 'qilingshop'); ?>
                        <select name="level_id" required>
                            <option value="0"><?php esc_html_e('请选择等级', 'qilingshop'); ?></option>
                            <?php foreach ($levels as $level) : ?>
                                <option value="<?php echo esc_attr((int) $level->id); ?>" <?php selected((int) ($edit->level_id ?? $selected_level_id), (int) $level->id); ?>><?php echo esc_html($level->level_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label></p>
                    <p><label><?php esc_html_e('权益类型', 'qilingshop'); ?><input type="text" value="<?php echo esc_attr($selected_type_data['label'] ?? $selected_type); ?>" class="regular-text" readonly></label></p>
                    <?php if (!empty($selected_type_data['description'])) : ?><p class="description"><?php echo esc_html($selected_type_data['description']); ?></p><?php endif; ?>
                    <p><label><?php esc_html_e('后台名称', 'qilingshop'); ?><input type="text" name="benefit_name" value="<?php echo esc_attr($edit->benefit_name ?? ($selected_type_data['label'] ?? '')); ?>" class="regular-text" required></label></p>
                    <?php $value_input_type = sanitize_key((string) ($selected_type_data['value_input_type'] ?? 'text')); ?>
                    <?php if (!in_array($value_input_type, ['text', 'url', 'number'], true)) { $value_input_type = 'text'; } ?>
                    <p><label><?php echo esc_html($selected_type_data['value_label'] ?? __('权益值', 'qilingshop')); ?><input type="<?php echo esc_attr($value_input_type); ?>" name="benefit_value" value="<?php echo esc_attr($edit->benefit_value ?? ''); ?>" class="regular-text" placeholder="<?php echo esc_attr($selected_type_data['value_placeholder'] ?? ''); ?>"></label></p>
                    <p><label><?php esc_html_e('前台展示标题', 'qilingshop'); ?><input type="text" name="display_title" value="<?php echo esc_attr($edit->display_title ?? ($selected_type_data['default_display_title'] ?? '')); ?>" class="regular-text" required></label></p>
                    <p><label><?php esc_html_e('前台展示说明', 'qilingshop'); ?><textarea name="display_desc" class="large-text" rows="3"><?php echo esc_textarea($edit->display_desc ?? ($selected_type_data['default_display_desc'] ?? '')); ?></textarea></label></p>
                    <p><label><?php esc_html_e('图标 URL', 'qilingshop'); ?><input type="url" name="icon" value="<?php echo esc_attr($edit->icon ?? ''); ?>" class="regular-text"></label></p>
                    <div class="qls-growth-config-box">
                        <h3><?php esc_html_e('权益参数', 'qilingshop'); ?></h3>
                        <?php $benefits_api->render_config_fields($selected_type, is_array($edit->benefit_config ?? null) ? $edit->benefit_config : []); ?>
                    </div>
                    <p><label><?php esc_html_e('排序', 'qilingshop'); ?><input type="number" name="sort_order" value="<?php echo esc_attr($edit->sort_order ?? 0); ?>"></label></p>
                    <p><label><input type="checkbox" name="status" value="1" <?php checked($edit ? (int) $edit->status : 1); ?>> <?php esc_html_e('启用', 'qilingshop'); ?></label></p>
                    <?php submit_button($edit ? __('保存权益', 'qilingshop') : __('新增权益', 'qilingshop')); ?>
                </form>
            </div>
            <div class="qls-admin-card">
                <h2><?php esc_html_e('权益列表', 'qilingshop'); ?></h2>
                <form method="get" class="qls-growth-search">
                    <input type="hidden" name="page" value="qilingshop-growth"><input type="hidden" name="tab" value="benefits">
                    <select name="level_id"><option value="0"><?php esc_html_e('全部等级', 'qilingshop'); ?></option><?php foreach ($levels as $level) : ?><option value="<?php echo esc_attr((int) $level->id); ?>" <?php selected($selected_level_id, (int) $level->id); ?>><?php echo esc_html($level->level_name); ?></option><?php endforeach; ?></select>
                    <button class="button"><?php esc_html_e('筛选', 'qilingshop'); ?></button>
                </form>
                <table class="widefat striped qls-ui-table">
                    <thead><tr><th>ID</th><th><?php esc_html_e('等级', 'qilingshop'); ?></th><th><?php esc_html_e('权益', 'qilingshop'); ?></th><th><?php esc_html_e('类型', 'qilingshop'); ?></th><th><?php esc_html_e('状态', 'qilingshop'); ?></th><th><?php esc_html_e('操作', 'qilingshop'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($benefits as $benefit) : $level = $growth->get_level((int) $benefit->level_id); $type_data = $types[$benefit->benefit_type] ?? []; ?>
                        <tr>
                            <td><?php echo esc_html((int) $benefit->id); ?></td>
                            <td><?php echo $level ? esc_html($level->level_name) : esc_html__('未知等级', 'qilingshop'); ?></td>
                            <td><strong><?php echo esc_html($benefit->display_title); ?></strong><br><span class="description"><?php echo esc_html($benefit->benefit_name); ?></span></td>
                            <td><?php echo esc_html($type_data['label'] ?? $benefit->benefit_type); ?></td>
                            <td><?php echo (int) $benefit->status ? esc_html__('启用', 'qilingshop') : esc_html__('禁用', 'qilingshop'); ?></td>
                            <td>
                                <a class="button button-small" href="<?php echo esc_url(add_query_arg(['page' => 'qilingshop-growth', 'tab' => 'benefits', 'edit_benefit' => (int) $benefit->id, 'benefit_type' => $benefit->benefit_type], admin_url('admin.php'))); ?>"><?php esc_html_e('编辑', 'qilingshop'); ?></a>
                                <form method="post" class="qls-inline-form" onsubmit="return confirm('<?php echo esc_js(__('确定删除该成长权益吗？', 'qilingshop')); ?>');">
                                    <?php wp_nonce_field('qilingshop_growth_action'); ?>
                                    <input type="hidden" name="qilingshop_growth_action" value="delete_benefit"><input type="hidden" name="current_tab" value="benefits"><input type="hidden" name="benefit_id" value="<?php echo esc_attr((int) $benefit->id); ?>">
                                    <button class="button button-small"><?php esc_html_e('删除', 'qilingshop'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    private function render_logs() {
        $filters = $this->get_log_filters_from_request($_GET);
        $filter_query_args = $this->get_log_query_args_from_filters($filters);
        $per_page = $this->get_logs_per_page($_GET);
        $paged = max(1, absint($_GET['log_paged'] ?? 1));
        $offset = ($paged - 1) * $per_page;
        $growth = QilingShop_Growth::instance();
        $total = $growth->get_logs_count($filters);
        $logs = $growth->get_logs(array_merge($filters, [
            'limit' => $per_page,
            'offset' => $offset,
        ]));
        $total_pages = max(1, (int) ceil($total / $per_page));
        $user_ids = [];
        foreach ($logs as $log) {
            $user_ids[] = (int) $log->user_id;
        }
        if (!empty($user_ids) && function_exists('cache_users')) {
            cache_users(array_values(array_unique($user_ids)));
        }
        ?>
        <div class="qls-admin-card">
            <form method="get" class="qls-growth-log-filter">
                <input type="hidden" name="page" value="qilingshop-growth">
                <input type="hidden" name="tab" value="logs">
                <div class="qls-growth-form qls-growth-log-filter-fields">
                    <p class="qls-growth-log-field qls-growth-log-field-user"><label><?php esc_html_e('用户', 'qilingshop'); ?><input type="text" name="log_user" value="<?php echo esc_attr($filter_query_args['log_user'] ?? ''); ?>" placeholder="<?php esc_attr_e('用户ID/用户名/邮箱', 'qilingshop'); ?>"></label></p>
                    <p class="qls-growth-log-field qls-growth-log-field-source"><label><?php esc_html_e('来源', 'qilingshop'); ?><input type="text" name="log_source" value="<?php echo esc_attr($filter_query_args['log_source'] ?? ''); ?>" placeholder="daily_visit"></label></p>
                    <p class="qls-growth-log-field qls-growth-log-field-source-id"><label><?php esc_html_e('来源ID', 'qilingshop'); ?><input type="number" name="log_source_id" value="<?php echo esc_attr($filter_query_args['log_source_id'] ?? ''); ?>" min="0"></label></p>
                    <p class="qls-growth-log-field qls-growth-log-field-keyword"><label><?php esc_html_e('说明关键词', 'qilingshop'); ?><input type="text" name="log_keyword" value="<?php echo esc_attr($filter_query_args['log_keyword'] ?? ''); ?>"></label></p>
                    <p class="qls-growth-log-field qls-growth-log-field-date"><label><?php esc_html_e('开始日期', 'qilingshop'); ?><input type="date" name="log_date_from" value="<?php echo esc_attr($filter_query_args['log_date_from'] ?? ''); ?>"></label></p>
                    <p class="qls-growth-log-field qls-growth-log-field-date"><label><?php esc_html_e('结束日期', 'qilingshop'); ?><input type="date" name="log_date_to" value="<?php echo esc_attr($filter_query_args['log_date_to'] ?? ''); ?>"></label></p>
                    <p class="qls-growth-log-field qls-growth-log-field-per-page"><label><?php esc_html_e('每页', 'qilingshop'); ?>
                        <select name="log_per_page">
                            <?php foreach ([20, 50, 100] as $option) : ?>
                                <option value="<?php echo esc_attr($option); ?>" <?php selected($per_page, $option); ?>><?php echo esc_html($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label></p>
                </div>
                <p class="qls-growth-log-filter-actions">
                    <button type="submit" class="button button-primary"><?php esc_html_e('查询流水', 'qilingshop'); ?></button>
                    <a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'qilingshop-growth', 'tab' => 'logs'], admin_url('admin.php'))); ?>"><?php esc_html_e('重置', 'qilingshop'); ?></a>
                </p>
            </form>
        </div>

        <div class="qls-admin-card">
            <div class="tablenav top">
                <div class="alignleft actions">
                    <span class="description"><?php echo esc_html(sprintf(__('共 %d 条成长流水，当前每页 %d 条。', 'qilingshop'), (int) $total, (int) $per_page)); ?></span>
                </div>
                <?php if ($total_pages > 1) : ?>
                    <div class="tablenav-pages">
                        <?php
                        $pagination_big = 999999999;
                        $pagination_base = str_replace((string) $pagination_big, '%#%', add_query_arg(array_merge([
                            'page' => 'qilingshop-growth',
                            'tab' => 'logs',
                            'log_paged' => $pagination_big,
                        ], $filter_query_args), admin_url('admin.php')));
                        echo wp_kses_post(paginate_links([
                            'base' => $pagination_base,
                            'format' => '',
                            'current' => $paged,
                            'total' => $total_pages,
                            'prev_text' => '&lsaquo;',
                            'next_text' => '&rsaquo;',
                        ]));
                        ?>
                    </div>
                <?php endif; ?>
            </div>

            <form method="post">
                <?php wp_nonce_field('qilingshop_growth_action'); ?>
                <input type="hidden" name="qilingshop_growth_action" value="delete_growth_logs">
                <input type="hidden" name="current_tab" value="logs">
                <?php $this->render_log_filter_hidden_fields($filter_query_args); ?>
                <table class="widefat striped qls-ui-table">
                    <thead><tr><th class="check-column"><input type="checkbox" onclick="jQuery(this).closest('table').find('tbody input[type=checkbox]').prop('checked', this.checked);"></th><th>ID</th><th><?php esc_html_e('用户', 'qilingshop'); ?></th><th><?php esc_html_e('变动', 'qilingshop'); ?></th><th><?php esc_html_e('余额', 'qilingshop'); ?></th><th><?php esc_html_e('来源', 'qilingshop'); ?></th><th><?php esc_html_e('来源ID', 'qilingshop'); ?></th><th><?php esc_html_e('说明', 'qilingshop'); ?></th><th><?php esc_html_e('时间', 'qilingshop'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($logs as $log) : $user = get_userdata((int) $log->user_id); ?>
                    <tr><th class="check-column"><input type="checkbox" name="log_ids[]" value="<?php echo esc_attr((int) $log->id); ?>"></th><td><?php echo esc_html((int) $log->id); ?></td><td><?php echo esc_html($user ? $user->display_name : ('#' . (int) $log->user_id)); ?><br><span class="description">#<?php echo esc_html((int) $log->user_id); ?></span></td><td><?php echo esc_html(number_format((float) $log->amount, 2)); ?></td><td><?php echo esc_html(number_format((float) $log->balance_after, 2)); ?></td><td><?php echo esc_html($log->source); ?></td><td><?php echo esc_html((int) $log->source_id); ?></td><td><?php echo esc_html($log->description); ?></td><td><?php echo esc_html($log->created_at); ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($logs)) : ?>
                    <tr><td colspan="9"><?php esc_html_e('暂无符合条件的成长流水', 'qilingshop'); ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
                <p><button type="submit" class="button"><?php esc_html_e('删除选中流水', 'qilingshop'); ?></button></p>
            </form>
        </div>

        <div class="qls-admin-card">
            <h2><?php esc_html_e('清理成长流水', 'qilingshop'); ?></h2>
            <p class="description"><?php esc_html_e('清理会删除当前筛选条件匹配的成长流水记录，不会改变用户当前成长值和等级。请至少设置一个筛选条件后再执行。', 'qilingshop'); ?></p>
            <form method="post">
                <?php wp_nonce_field('qilingshop_growth_action'); ?>
                <input type="hidden" name="qilingshop_growth_action" value="clear_growth_logs">
                <input type="hidden" name="current_tab" value="logs">
                <?php $this->render_log_filter_hidden_fields($filter_query_args); ?>
                <label><input type="checkbox" name="confirm_clear_growth_logs" value="1"> <?php esc_html_e('确认按当前筛选条件清理成长流水', 'qilingshop'); ?></label>
                <p><button type="submit" class="button button-secondary"><?php esc_html_e('清理当前筛选流水', 'qilingshop'); ?></button></p>
            </form>
        </div>
        <?php
    }

    private function get_logs_per_page($request) {
        $per_page = absint($request['log_per_page'] ?? 20);
        return in_array($per_page, [20, 50, 100], true) ? $per_page : 20;
    }

    private function get_log_filters_from_request($request) {
        $filters = [];
        $request = is_array($request) ? $request : [];

        $user_query = sanitize_text_field((string) wp_unslash($request['log_user'] ?? ''));
        if ($user_query !== '') {
            $filters['_log_user'] = $user_query;
            if (ctype_digit($user_query)) {
                $filters['user_id'] = absint($user_query);
            } else {
                $user_ids = $this->resolve_log_user_ids($user_query);
                $filters['user_ids'] = !empty($user_ids) ? $user_ids : [0];
            }
        }

        $source = sanitize_key((string) wp_unslash($request['log_source'] ?? ''));
        if ($source !== '') {
            $filters['source'] = $source;
            $filters['_log_source'] = $source;
        }

        $source_id = absint($request['log_source_id'] ?? 0);
        if ($source_id > 0) {
            $filters['source_id'] = $source_id;
            $filters['_log_source_id'] = $source_id;
        }

        $keyword = sanitize_text_field((string) wp_unslash($request['log_keyword'] ?? ''));
        if ($keyword !== '') {
            $filters['keyword'] = $keyword;
            $filters['_log_keyword'] = $keyword;
        }

        $date_from = $this->sanitize_log_date((string) wp_unslash($request['log_date_from'] ?? ''));
        if ($date_from !== '') {
            $filters['date_from'] = $date_from;
            $filters['_log_date_from'] = $date_from;
        }

        $date_to = $this->sanitize_log_date((string) wp_unslash($request['log_date_to'] ?? ''));
        if ($date_to !== '') {
            $filters['date_to'] = $date_to;
            $filters['_log_date_to'] = $date_to;
        }

        return $filters;
    }

    private function get_log_query_args_from_filters($filters) {
        $query_args = [];
        $map = [
            '_log_user' => 'log_user',
            '_log_source' => 'log_source',
            '_log_source_id' => 'log_source_id',
            '_log_keyword' => 'log_keyword',
            '_log_date_from' => 'log_date_from',
            '_log_date_to' => 'log_date_to',
        ];

        foreach ($map as $internal_key => $query_key) {
            if (isset($filters[$internal_key]) && $filters[$internal_key] !== '') {
                $query_args[$query_key] = $filters[$internal_key];
            }
        }

        if (!empty($_GET['log_per_page'])) {
            $query_args['log_per_page'] = $this->get_logs_per_page($_GET);
        } elseif (!empty($_POST['log_per_page'])) {
            $query_args['log_per_page'] = $this->get_logs_per_page($_POST);
        }

        return $query_args;
    }

    private function get_log_redirect_args_from_request($request) {
        $filters = $this->get_log_filters_from_request($request);
        $args = $this->get_log_query_args_from_filters($filters);
        $per_page = $this->get_logs_per_page($request);
        if ($per_page !== 20) {
            $args['log_per_page'] = $per_page;
        }
        return $args;
    }

    private function render_log_filter_hidden_fields($query_args) {
        foreach ($query_args as $key => $value) {
            echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr((string) $value) . '">';
        }
    }

    private function resolve_log_user_ids($user_query) {
        $user_query = trim((string) $user_query);
        if ($user_query === '') {
            return [];
        }

        $user = get_user_by('login', $user_query);
        if (!$user && is_email($user_query)) {
            $user = get_user_by('email', $user_query);
        }
        if ($user) {
            return [(int) $user->ID];
        }

        $users = get_users([
            'search' => '*' . $user_query . '*',
            'search_columns' => ['user_login', 'user_email', 'display_name'],
            'fields' => 'ID',
            'number' => 20,
        ]);

        return array_values(array_filter(array_map('absint', (array) $users)));
    }

    private function sanitize_log_date($date) {
        $date = trim((string) $date);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '';
    }

    private function has_log_filter_scope($filters) {
        return !empty($filters['user_id'])
            || !empty($filters['user_ids'])
            || !empty($filters['source'])
            || !empty($filters['source_id'])
            || !empty($filters['keyword'])
            || !empty($filters['date_from'])
            || !empty($filters['date_to']);
    }

    private function render_rules() {
        if (!class_exists('QilingShop_Growth_Rules')) {
            echo '<div class="notice notice-error"><p>' . esc_html__('成长规则模块不可用', 'qilingshop') . '</p></div>';
            return;
        }

        $rules = QilingShop_Growth_Rules::instance()->get_rules();
        $edit_id = absint($_GET['edit_rule'] ?? 0);
        $edit = $edit_id > 0 ? QilingShop_Growth_Rules::instance()->get_rule($edit_id) : null;
        ?>
        <div class="qls-growth-grid">
            <div class="qls-admin-card">
                <h2><?php echo $edit ? esc_html__('编辑成长规则', 'qilingshop') : esc_html__('新增成长规则', 'qilingshop'); ?></h2>
                <p class="description"><?php esc_html_e('规则默认关闭。启用后只发放成长值，不影响原业务的成功或失败。按金额奖励时：成长值 = 订单实付金额 × 成长值系数。', 'qilingshop'); ?></p>
                <form method="post" class="qls-growth-form">
                    <?php wp_nonce_field('qilingshop_growth_action'); ?>
                    <input type="hidden" name="qilingshop_growth_action" value="save_rule">
                    <input type="hidden" name="current_tab" value="rules">
                    <input type="hidden" name="rule_id" value="<?php echo esc_attr($edit ? (int) $edit->id : 0); ?>">
                    <p><label><?php esc_html_e('规则标识', 'qilingshop'); ?><input type="text" name="rule_key" value="<?php echo esc_attr($edit->rule_key ?? ''); ?>" class="regular-text" required></label></p>
                    <p><label><?php esc_html_e('规则名称', 'qilingshop'); ?><input type="text" name="rule_name" value="<?php echo esc_attr($edit->rule_name ?? ''); ?>" class="regular-text" required></label></p>
                    <p><label><?php esc_html_e('流水来源', 'qilingshop'); ?><input type="text" name="source" value="<?php echo esc_attr($edit->source ?? ''); ?>" class="regular-text" required></label></p>
                    <p><label><?php esc_html_e('触发事件', 'qilingshop'); ?>
                        <select name="trigger_event">
                            <?php foreach ($this->get_growth_rule_events() as $event_key => $event_label) : ?>
                                <option value="<?php echo esc_attr($event_key); ?>" <?php selected((string) ($edit->trigger_event ?? ''), $event_key); ?>><?php echo esc_html($event_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label></p>
                    <p><label><?php esc_html_e('奖励方式', 'qilingshop'); ?>
                        <select name="growth_type">
                            <option value="fixed" <?php selected((string) ($edit->growth_type ?? 'fixed'), 'fixed'); ?>><?php esc_html_e('固定成长值', 'qilingshop'); ?></option>
                            <option value="amount_rate" <?php selected((string) ($edit->growth_type ?? ''), 'amount_rate'); ?>><?php esc_html_e('按金额比例', 'qilingshop'); ?></option>
                        </select>
                    </label></p>
                    <p><label><?php esc_html_e('成长值 / 比例系数', 'qilingshop'); ?><input type="number" step="0.01" min="0" name="growth_amount" value="<?php echo esc_attr($edit->growth_amount ?? 0); ?>" required></label></p>
                    <p><label><?php esc_html_e('单次最高成长值', 'qilingshop'); ?><input type="number" step="0.01" min="0" name="max_single_amount" value="<?php echo esc_attr($edit->max_single_amount ?? 0); ?>"><span class="description"><?php esc_html_e('0 表示不限制', 'qilingshop'); ?></span></label></p>
                    <p><label><?php esc_html_e('每日最高成长值', 'qilingshop'); ?><input type="number" step="0.01" min="0" name="max_daily_amount" value="<?php echo esc_attr($edit->max_daily_amount ?? 0); ?>"><span class="description"><?php esc_html_e('0 表示不限制', 'qilingshop'); ?></span></label></p>
                    <p><label><?php esc_html_e('每月最高成长值', 'qilingshop'); ?><input type="number" step="0.01" min="0" name="max_monthly_amount" value="<?php echo esc_attr($edit->max_monthly_amount ?? 0); ?>"><span class="description"><?php esc_html_e('0 表示不限制', 'qilingshop'); ?></span></label></p>
                    <p><label><?php esc_html_e('扩展配置 JSON', 'qilingshop'); ?><textarea name="config" class="large-text code" rows="4"><?php echo esc_textarea(!empty($edit->config) ? wp_json_encode($edit->config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '{}'); ?></textarea></label></p>
                    <p><label><?php esc_html_e('排序', 'qilingshop'); ?><input type="number" name="sort_order" value="<?php echo esc_attr($edit->sort_order ?? 0); ?>"></label></p>
                    <p><label><input type="checkbox" name="status" value="1" <?php checked($edit ? (int) $edit->status : 0); ?>> <?php esc_html_e('启用该规则', 'qilingshop'); ?></label></p>
                    <?php submit_button($edit ? __('保存规则', 'qilingshop') : __('新增规则', 'qilingshop')); ?>
                </form>
            </div>
            <div class="qls-admin-card">
                <h2><?php esc_html_e('规则列表', 'qilingshop'); ?></h2>
                <table class="widefat striped qls-ui-table">
                    <thead><tr><th>ID</th><th><?php esc_html_e('规则', 'qilingshop'); ?></th><th><?php esc_html_e('事件', 'qilingshop'); ?></th><th><?php esc_html_e('奖励', 'qilingshop'); ?></th><th><?php esc_html_e('状态', 'qilingshop'); ?></th><th><?php esc_html_e('操作', 'qilingshop'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($rules as $rule) : ?>
                        <tr>
                            <td><?php echo esc_html((int) $rule->id); ?></td>
                            <td><strong><?php echo esc_html($rule->rule_name); ?></strong><br><span class="description"><?php echo esc_html($rule->rule_key); ?></span></td>
                            <td><?php echo esc_html($this->get_growth_rule_events()[$rule->trigger_event] ?? $rule->trigger_event); ?></td>
                            <td><?php echo esc_html($rule->growth_type === 'amount_rate' ? sprintf(__('金额 × %s', 'qilingshop'), $rule->growth_amount) : number_format((float) $rule->growth_amount, 2)); ?></td>
                            <td><?php echo (int) $rule->status ? esc_html__('启用', 'qilingshop') : esc_html__('关闭', 'qilingshop'); ?></td>
                            <td><a class="button button-small" href="<?php echo esc_url(add_query_arg(['page' => 'qilingshop-growth', 'tab' => 'rules', 'edit_rule' => (int) $rule->id], admin_url('admin.php'))); ?>"><?php esc_html_e('编辑', 'qilingshop'); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    private function save_level($data) {
        $level_id = absint($data['level_id'] ?? 0);
        $max_growth_raw = isset($data['max_growth']) ? trim((string) wp_unslash($data['max_growth'])) : '';
        $payload = [
            'level_key' => sanitize_key((string) wp_unslash($data['level_key'] ?? '')),
            'level_name' => sanitize_text_field((string) wp_unslash($data['level_name'] ?? '')),
            'min_growth' => max(0, (float) ($data['min_growth'] ?? 0)),
            'max_growth' => $max_growth_raw === '' ? null : max(0, (float) $max_growth_raw),
            'level_icon' => esc_url_raw((string) wp_unslash($data['level_icon'] ?? '')),
            'level_color' => sanitize_text_field((string) wp_unslash($data['level_color'] ?? '#64748b')),
            'description' => sanitize_textarea_field((string) wp_unslash($data['description'] ?? '')),
            'status' => isset($data['status']) ? 1 : 0,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'updated_at' => current_time('mysql'),
        ];

        if ($payload['level_key'] === '' || $payload['level_name'] === '') {
            return false;
        }

        if ($payload['max_growth'] !== null && (float) $payload['max_growth'] < (float) $payload['min_growth']) {
            return false;
        }

        if (!$this->validate_level_range($level_id, $payload)) {
            return false;
        }

        $saved = false;
        if ($level_id > 0) {
            $saved = $this->db->update('growth_levels', $payload, ['id' => $level_id]) !== false;
        } else {
            $payload['created_at'] = current_time('mysql');
            $saved = (bool) $this->db->insert('growth_levels', $payload);
        }

        if ($saved) {
            $this->recalculate_all_user_levels();
        }

        return $saved;
    }

    private function validate_level_range($level_id, $payload) {
        if (empty($payload['status'])) {
            return true;
        }

        $new_min = (float) $payload['min_growth'];
        $new_max = $payload['max_growth'] === null ? null : (float) $payload['max_growth'];
        foreach (QilingShop_Growth::instance()->get_levels(true) as $level) {
            if ((int) $level->id === (int) $level_id) {
                continue;
            }

            $existing_min = (float) $level->min_growth;
            $existing_max = $level->max_growth === null ? null : (float) $level->max_growth;
            if ($this->growth_ranges_overlap($new_min, $new_max, $existing_min, $existing_max)) {
                return false;
            }
        }

        return true;
    }

    private function growth_ranges_overlap($left_min, $left_max, $right_min, $right_max) {
        $left_max_value = $left_max === null ? INF : (float) $left_max;
        $right_max_value = $right_max === null ? INF : (float) $right_max;
        return (float) $left_min <= $right_max_value && (float) $right_min <= $left_max_value;
    }

    private function recalculate_all_user_levels() {
        global $wpdb;
        $table = $this->db->get_table('user_growth');
        $user_ids = $wpdb->get_col("SELECT user_id FROM {$table}");
        foreach ((array) $user_ids as $user_id) {
            QilingShop_Growth::instance()->recalculate_user_level((int) $user_id);
        }
    }

    private function save_benefit($data) {
        $benefits_api = QilingShop_Growth_Benefits::instance();
        $benefit_id = absint($data['benefit_id'] ?? 0);
        $level_id = absint($data['level_id'] ?? 0);
        $benefit_type = sanitize_key((string) wp_unslash($data['benefit_type'] ?? ''));
        if ($level_id <= 0 || !$benefits_api->get_benefit_type($benefit_type)) {
            return false;
        }

        $config = $benefits_api->prepare_config_from_request($benefit_type, $data['benefit_config'] ?? []);
        $benefit_value_raw = isset($data['benefit_value']) ? (string) wp_unslash($data['benefit_value']) : '';
        $benefit_value = $benefit_type === 'custom_link'
            ? esc_url_raw($benefit_value_raw)
            : sanitize_text_field($benefit_value_raw);
        $payload = [
            'level_id' => $level_id,
            'benefit_type' => $benefit_type,
            'benefit_name' => sanitize_text_field((string) wp_unslash($data['benefit_name'] ?? '')),
            'benefit_value' => $benefit_value,
            'benefit_config' => wp_json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'display_title' => sanitize_text_field((string) wp_unslash($data['display_title'] ?? '')),
            'display_desc' => sanitize_textarea_field((string) wp_unslash($data['display_desc'] ?? '')),
            'icon' => esc_url_raw((string) wp_unslash($data['icon'] ?? '')),
            'status' => isset($data['status']) ? 1 : 0,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'updated_at' => current_time('mysql'),
        ];

        if ($payload['benefit_name'] === '' || $payload['display_title'] === '') {
            return false;
        }

        if ($benefit_type === 'custom_link' && $payload['benefit_value'] === '') {
            return false;
        }

        if ($benefit_type === 'custom_text' && $payload['benefit_value'] === '' && trim((string) ($config['content'] ?? '')) === '') {
            return false;
        }

        if ($benefit_id > 0) {
            return $this->db->update('growth_benefits', $payload, ['id' => $benefit_id]) !== false;
        }

        $payload['created_at'] = current_time('mysql');
        return (bool) $this->db->insert('growth_benefits', $payload);
    }

    private function save_rule($data) {
        $rule_id = absint($data['rule_id'] ?? 0);
        $rule_key = sanitize_key((string) wp_unslash($data['rule_key'] ?? ''));
        $rule_name = sanitize_text_field((string) wp_unslash($data['rule_name'] ?? ''));
        $source = sanitize_key((string) wp_unslash($data['source'] ?? ''));
        $trigger_event = sanitize_key((string) wp_unslash($data['trigger_event'] ?? ''));
        $growth_type = sanitize_key((string) wp_unslash($data['growth_type'] ?? 'fixed'));
        if ($rule_key === '' || $rule_name === '' || $source === '' || $trigger_event === '') {
            return false;
        }
        if (!in_array($growth_type, ['fixed', 'amount_rate'], true)) {
            $growth_type = 'fixed';
        }

        $config_raw = (string) wp_unslash($data['config'] ?? '{}');
        $config = json_decode($config_raw, true);
        if (!is_array($config)) {
            $config = [];
        }

        $payload = [
            'rule_key' => $rule_key,
            'rule_name' => $rule_name,
            'source' => $source,
            'trigger_event' => $trigger_event,
            'growth_amount' => max(0, (float) ($data['growth_amount'] ?? 0)),
            'growth_type' => $growth_type,
            'max_single_amount' => max(0, (float) ($data['max_single_amount'] ?? 0)),
            'max_daily_amount' => max(0, (float) ($data['max_daily_amount'] ?? 0)),
            'max_monthly_amount' => max(0, (float) ($data['max_monthly_amount'] ?? 0)),
            'config' => wp_json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => isset($data['status']) ? 1 : 0,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'updated_at' => current_time('mysql'),
        ];

        if ($rule_id > 0) {
            return $this->db->update('growth_rules', $payload, ['id' => $rule_id]) !== false;
        }

        $payload['created_at'] = current_time('mysql');
        return (bool) $this->db->insert('growth_rules', $payload);
    }

    private function get_growth_rule_events() {
        return [
            'daily_visit' => __('每日访问', 'qilingshop'),
            'checkin' => __('每日签到', 'qilingshop'),
            'resource_order_paid' => __('资源订单支付', 'qilingshop'),
            'shop_order_paid' => __('商城订单支付', 'qilingshop'),
            'review_submit' => __('提交评价', 'qilingshop'),
            'review_with_image' => __('带图评价', 'qilingshop'),
            'invite_registered' => __('邀请注册', 'qilingshop'),
            'task_completed' => __('任务完成', 'qilingshop'),
        ];
    }

    private function adjust_user_growth($data) {
        if (!(bool) get_option('qilingshop_growth_allow_admin_adjust', true)) {
            return false;
        }
        $user_id = absint($data['user_id'] ?? 0);
        $amount = max(0, (float) ($data['amount'] ?? 0));
        $description = sanitize_text_field((string) wp_unslash($data['description'] ?? __('后台调整', 'qilingshop')));
        if ($user_id <= 0 || $amount <= 0) {
            return false;
        }
        if (($data['adjust_type'] ?? 'add') === 'deduct') {
            return QilingShop_Growth::instance()->deduct_growth($user_id, $amount, 'admin_adjust', $description, 0);
        }
        return QilingShop_Growth::instance()->add_growth($user_id, $amount, 'admin_adjust', $description, 0);
    }
}
