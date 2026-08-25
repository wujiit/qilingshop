<?php
/**
 * 成长体系核心类。
 *
 * 一阶段只负责成长值账户、等级计算和流水记录，不参与 VIP 权益判断。
 *
 * @package QilingShop
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Growth {
    private static $instance = null;

    /**
     * @var QilingShop_Database
     */
    private $db;

    /**
     * @var array<string,bool>
     */
    private $table_exists_cache = [];

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->db = QilingShop_Database::instance();
        add_action('user_register', [$this, 'init_user_account'], 20, 1);
        add_shortcode('qilingshop_growth_center', [$this, 'render_growth_center_shortcode']);
    }

    public function is_enabled() {
        return (bool) get_option('qilingshop_growth_enabled', false);
    }

    public function get_growth_name() {
        $name = trim((string) get_option('qilingshop_growth_name', '成长值'));
        return $name !== '' ? $name : __('成长值', 'qilingshop');
    }

    public function init_user_account($user_id) {
        $user_id = absint($user_id);
        if ($user_id <= 0 || !$this->table_exists('user_growth')) {
            return false;
        }

        $existing = $this->db->get_row('user_growth', ['user_id' => $user_id]);
        if ($existing) {
            return true;
        }

        $level = $this->get_level_by_growth(0);
        $level_id = $level ? (int) $level->id : 0;
        $inserted = $this->db->insert('user_growth', [
            'user_id' => $user_id,
            'growth_value' => 0,
            'level_id' => $level_id,
            'highest_level_id' => $level_id,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);

        return (bool) $inserted;
    }

    public function get_user_growth($user_id) {
        $user_id = absint($user_id);
        if ($user_id <= 0 || !$this->table_exists('user_growth')) {
            return null;
        }

        $row = $this->db->get_row('user_growth', ['user_id' => $user_id]);
        if (!$row) {
            $this->init_user_account($user_id);
            $row = $this->db->get_row('user_growth', ['user_id' => $user_id]);
        }

        return $row ?: null;
    }

    public function get_levels($active_only = true) {
        if (!$this->table_exists('growth_levels')) {
            return [];
        }

        $args = [
            'orderby' => 'sort_order',
            'order' => 'ASC',
        ];
        if ($active_only) {
            $args['where'] = ['status' => 1];
        }

        return $this->db->get_results('growth_levels', $args);
    }

    public function get_level($level_id) {
        $level_id = absint($level_id);
        if ($level_id <= 0 || !$this->table_exists('growth_levels')) {
            return null;
        }

        return $this->db->get_by_id('growth_levels', $level_id);
    }

    public function get_user_level($user_id) {
        $account = $this->get_user_growth($user_id);
        if (!$account) {
            return null;
        }

        return $this->get_level((int) $account->level_id);
    }

    public function get_level_by_growth($growth_value) {
        $growth_value = max(0, (float) $growth_value);
        $levels = $this->get_levels_for_calculation(true);
        $fallback = null;

        foreach ($levels as $level) {
            $min = (float) $level->min_growth;
            $max = ($level->max_growth === null || $level->max_growth === '') ? null : (float) $level->max_growth;
            if ($growth_value >= $min && ($max === null || $growth_value <= $max)) {
                return $level;
            }
            if ($growth_value >= $min) {
                $fallback = $level;
            }
        }

        return $fallback;
    }

    public function get_next_level($growth_value) {
        $growth_value = max(0, (float) $growth_value);
        foreach ($this->get_levels_for_calculation(true) as $level) {
            if ((float) $level->min_growth > $growth_value) {
                return $level;
            }
        }
        return null;
    }

    public function add_growth($user_id, $amount, $source = 'admin', $description = '', $source_id = 0) {
        $amount = round((float) $amount, 2);
        if ($amount <= 0) {
            return false;
        }
        return $this->change_growth($user_id, $amount, $source, $description, $source_id);
    }

    public function deduct_growth($user_id, $amount, $source = 'admin', $description = '', $source_id = 0) {
        $amount = round((float) $amount, 2);
        if ($amount <= 0) {
            return false;
        }
        return $this->change_growth($user_id, -$amount, $source, $description, $source_id);
    }

    public function has_growth_log($user_id, $source, $source_id) {
        $user_id = absint($user_id);
        $source = sanitize_key((string) $source);
        $source_id = absint($source_id);
        if ($user_id <= 0 || $source === '' || $source_id <= 0 || !$this->table_exists('growth_log')) {
            return false;
        }

        return (bool) $this->db->get_row('growth_log', [
            'user_id' => $user_id,
            'source' => $source,
            'source_id' => $source_id,
        ]);
    }

    public function recalculate_user_level($user_id) {
        $account = $this->get_user_growth($user_id);
        if (!$account) {
            return false;
        }

        $old_level_id = (int) $account->level_id;
        $new_level = $this->get_level_by_growth((float) $account->growth_value);
        $new_level_id = $new_level ? (int) $new_level->id : 0;
        $highest_level_id = $this->resolve_highest_level_id((int) $account->highest_level_id, $new_level_id);

        $updated = $this->db->update('user_growth', [
            'level_id' => $new_level_id,
            'highest_level_id' => $highest_level_id,
            'updated_at' => current_time('mysql'),
        ], ['user_id' => (int) $account->user_id]);

        if ($updated !== false && $old_level_id !== $new_level_id) {
            do_action('qilingshop_growth_level_changed', (int) $account->user_id, $old_level_id, $new_level_id);
        }

        return $updated !== false;
    }

    public function get_logs($args = []) {
        global $wpdb;

        if (!$this->table_exists('growth_log')) {
            return [];
        }

        $defaults = [
            'user_id' => 0,
            'user_ids' => [],
            'source' => '',
            'source_id' => 0,
            'keyword' => '',
            'date_from' => '',
            'date_to' => '',
            'ids' => [],
            'limit' => 20,
            'offset' => 0,
        ];
        $args = wp_parse_args($args, $defaults);

        $limit = min(200, max(1, (int) $args['limit']));
        $offset = max(0, (int) $args['offset']);
        $table = $this->db->get_table('growth_log');
        [$where_sql, $params] = $this->build_log_where_sql($args);

        $sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    public function get_logs_count($args = []) {
        global $wpdb;

        if (!$this->table_exists('growth_log')) {
            return 0;
        }

        $table = $this->db->get_table('growth_log');
        [$where_sql, $params] = $this->build_log_where_sql($args);
        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";

        return (int) $wpdb->get_var(!empty($params) ? $wpdb->prepare($sql, $params) : $sql);
    }

    public function delete_logs($args = []) {
        global $wpdb;

        if (!$this->table_exists('growth_log')) {
            return false;
        }

        $args = wp_parse_args($args, [
            'ids' => [],
            'user_id' => 0,
            'user_ids' => [],
            'source' => '',
            'source_id' => 0,
            'keyword' => '',
            'date_from' => '',
            'date_to' => '',
        ]);

        if (!$this->has_log_delete_scope($args)) {
            return false;
        }

        $table = $this->db->get_table('growth_log');
        [$where_sql, $params] = $this->build_log_where_sql($args);
        $sql = "DELETE FROM {$table} WHERE {$where_sql}";

        return $wpdb->query(!empty($params) ? $wpdb->prepare($sql, $params) : $sql);
    }

    private function build_log_where_sql($args) {
        global $wpdb;

        $args = is_array($args) ? $args : [];
        $conditions = [];
        $params = [];

        $ids = array_filter(array_map('absint', (array) ($args['ids'] ?? [])));
        if (!empty($ids)) {
            $conditions[] = 'id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')';
            foreach ($ids as $id) {
                $params[] = $id;
            }
        }

        $user_id = absint($args['user_id'] ?? 0);
        if ($user_id > 0) {
            $conditions[] = 'user_id = %d';
            $params[] = $user_id;
        }

        $user_ids = array_filter(array_map('absint', (array) ($args['user_ids'] ?? [])));
        if (!empty($user_ids)) {
            $conditions[] = 'user_id IN (' . implode(',', array_fill(0, count($user_ids), '%d')) . ')';
            foreach ($user_ids as $id) {
                $params[] = $id;
            }
        }

        $source = sanitize_key((string) ($args['source'] ?? ''));
        if ($source !== '') {
            $conditions[] = 'source = %s';
            $params[] = $source;
        }

        $source_id = absint($args['source_id'] ?? 0);
        if ($source_id > 0) {
            $conditions[] = 'source_id = %d';
            $params[] = $source_id;
        }

        $keyword = sanitize_text_field((string) ($args['keyword'] ?? ''));
        if ($keyword !== '') {
            $conditions[] = 'description LIKE %s';
            $params[] = '%' . $wpdb->esc_like($keyword) . '%';
        }

        $date_from = $this->normalize_log_date($args['date_from'] ?? '', false);
        if ($date_from !== '') {
            $conditions[] = 'created_at >= %s';
            $params[] = $date_from;
        }

        $date_to = $this->normalize_log_date($args['date_to'] ?? '', true);
        if ($date_to !== '') {
            $conditions[] = 'created_at <= %s';
            $params[] = $date_to;
        }

        return [!empty($conditions) ? implode(' AND ', $conditions) : '1=1', $params];
    }

    private function normalize_log_date($date, $end_of_day = false) {
        $date = trim((string) $date);
        if ($date === '') {
            return '';
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return '';
        }

        return $date . ($end_of_day ? ' 23:59:59' : ' 00:00:00');
    }

    private function has_log_delete_scope($args) {
        $ids = array_filter(array_map('absint', (array) ($args['ids'] ?? [])));
        if (!empty($ids)) {
            return true;
        }

        if (absint($args['user_id'] ?? 0) > 0 || !empty(array_filter(array_map('absint', (array) ($args['user_ids'] ?? []))))) {
            return true;
        }

        if (sanitize_key((string) ($args['source'] ?? '')) !== '' || absint($args['source_id'] ?? 0) > 0) {
            return true;
        }

        if (trim((string) ($args['keyword'] ?? '')) !== '') {
            return true;
        }

        return $this->normalize_log_date($args['date_from'] ?? '', false) !== '' || $this->normalize_log_date($args['date_to'] ?? '', true) !== '';
    }

    public function render_summary_card($user_id) {
        if (!$this->is_enabled() || !(bool) get_option('qilingshop_growth_frontend_display', true)) {
            return '';
        }

        $account = $this->get_user_growth($user_id);
        if (!$account) {
            return '';
        }

        $level = $this->get_level((int) $account->level_id);
        $highest_level = (bool) get_option('qilingshop_growth_show_highest_level', false)
            ? $this->get_highest_user_level($user_id)
            : null;
        $next_level = $this->get_next_level((float) $account->growth_value);
        $level_name = $level ? $level->level_name : __('暂无等级', 'qilingshop');
        $level_color = $level && !empty($level->level_color) ? $level->level_color : '#64748b';
        $growth_name = $this->get_growth_name();
        $progress = 100;
        $next_text = __('已达最高等级', 'qilingshop');

        if ($next_level) {
            $current_min = $level ? (float) $level->min_growth : 0;
            $next_min = max($current_min + 1, (float) $next_level->min_growth);
            $progress = $next_min > $current_min
                ? (($account->growth_value - $current_min) / ($next_min - $current_min)) * 100
                : 0;
            $progress = max(0, min(100, $progress));
            $need = max(0, $next_min - (float) $account->growth_value);
            $next_text = sprintf(__('距离 %1$s 还差 %2$s %3$s', 'qilingshop'), $next_level->level_name, number_format($need, 2), $growth_name);
        }

        ob_start();
        ?>
        <div class="qilingshop-growth-card">
            <div class="qilingshop-growth-head">
                <span class="qilingshop-growth-badge" style="background:<?php echo esc_attr($level_color); ?>"><?php echo esc_html($level_name); ?></span>
                <strong><?php echo esc_html(number_format((float) $account->growth_value, 2)); ?> <?php echo esc_html($growth_name); ?></strong>
            </div>
            <div class="qilingshop-growth-progress"><span style="width:<?php echo esc_attr(number_format($progress, 2)); ?>%"></span></div>
            <p><?php echo esc_html($next_text); ?></p>
            <?php if ($highest_level && (!$level || (int) $highest_level->id !== (int) $level->id)) : ?>
                <p><?php echo esc_html(sprintf(__('历史最高等级：%s', 'qilingshop'), $highest_level->level_name)); ?></p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function render_growth_center_shortcode() {
        if (!is_user_logged_in()) {
            return '<p>' . sprintf(__('请先<a href="%s">登录</a>', 'qilingshop'), esc_url(wp_login_url(get_permalink()))) . '</p>';
        }

        $user_id = get_current_user_id();
        $account = $this->get_user_growth($user_id);
        $next_level = $account ? $this->get_next_level((float) $account->growth_value) : null;
        $logs = $this->get_logs(['user_id' => $user_id, 'limit' => 20]);
        ob_start();
        echo '<div class="qilingshop-growth-center">';
        echo $this->render_summary_card($user_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        if ($account) {
            echo $this->render_effective_benefits($user_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '<div class="qilingshop-growth-benefit-sections">';
            echo $this->render_level_benefits((int) $account->level_id, __('当前等级权益', 'qilingshop')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            if ($next_level) {
                echo $this->render_level_benefits((int) $next_level->id, sprintf(__('下一等级权益：%s', 'qilingshop'), $next_level->level_name)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
            echo '</div>';
        }
        echo '<h3>' . esc_html__('成长记录', 'qilingshop') . '</h3>';
        if (empty($logs)) {
            echo '<p>' . esc_html__('暂无成长记录', 'qilingshop') . '</p>';
        } else {
            echo '<table class="qilingshop-growth-log"><thead><tr><th>' . esc_html__('时间', 'qilingshop') . '</th><th>' . esc_html__('变动', 'qilingshop') . '</th><th>' . esc_html__('说明', 'qilingshop') . '</th></tr></thead><tbody>';
            foreach ($logs as $log) {
                echo '<tr><td>' . esc_html($log->created_at) . '</td><td>' . esc_html(number_format((float) $log->amount, 2)) . '</td><td>' . esc_html($log->description) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    public function render_effective_benefits($user_id) {
        if (!class_exists('QilingShop_Growth_Benefits')) {
            return '';
        }

        $summaries = QilingShop_Growth_Benefits::instance()->get_user_effective_summaries($user_id);
        if (empty($summaries)) {
            return '';
        }

        ob_start();
        echo '<section class="qilingshop-growth-effective">';
        echo '<h3>' . esc_html__('已生效权益', 'qilingshop') . '</h3>';
        echo '<ul>';
        foreach ($summaries as $summary) {
            echo '<li>' . esc_html($summary) . '</li>';
        }
        echo '</ul>';
        echo '</section>';
        return ob_get_clean();
    }

    public function render_level_benefits($level_id, $title = '') {
        if (!class_exists('QilingShop_Growth_Benefits')) {
            return '';
        }

        $benefits = QilingShop_Growth_Benefits::instance()->get_level_benefits($level_id, true);
        ob_start();
        echo '<section class="qilingshop-growth-benefits">';
        if ($title !== '') {
            echo '<h3>' . esc_html($title) . '</h3>';
        }
        if (empty($benefits)) {
            echo '<p class="qilingshop-growth-empty-benefits">' . esc_html__('暂无已配置权益', 'qilingshop') . '</p>';
        } else {
            echo '<div class="qilingshop-growth-benefit-grid">';
            foreach ($benefits as $benefit) {
                echo '<article class="qilingshop-growth-benefit-card">';
                if (!empty($benefit->icon)) {
                    echo '<img src="' . esc_url($benefit->icon) . '" alt="" class="qilingshop-growth-benefit-icon">';
                }
                echo '<div><strong>' . esc_html($benefit->display_title) . '</strong>';
                if (!empty($benefit->display_desc)) {
                    echo '<p>' . esc_html($benefit->display_desc) . '</p>';
                }
                echo '</div></article>';
            }
            echo '</div>';
        }
        echo '</section>';
        return ob_get_clean();
    }

    private function change_growth($user_id, $amount, $source, $description, $source_id) {
        global $wpdb;

        $user_id = absint($user_id);
        $source = sanitize_key((string) $source);
        $source_id = absint($source_id);
        $description = sanitize_text_field((string) $description);
        if ($user_id <= 0 || $source === '' || !$this->table_exists('user_growth') || !$this->table_exists('growth_log')) {
            return false;
        }

        try {
            if ($source_id > 0 && $this->has_growth_log($user_id, $source, $source_id)) {
                return false;
            }

            $this->init_user_account($user_id);
            $user_growth_table = $this->db->get_table('user_growth');
            $growth_log_table = $this->db->get_table('growth_log');

            $this->db->begin_transaction();

            $account = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$user_growth_table} WHERE user_id = %d LIMIT 1 FOR UPDATE",
                $user_id
            ));
            if (!$account) {
                throw new Exception('Growth account not found');
            }

            if ($source_id > 0) {
                $existing_log_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$growth_log_table} WHERE user_id = %d AND source = %s AND source_id = %d LIMIT 1 FOR UPDATE",
                    $user_id,
                    $source,
                    $source_id
                ));
                if ($existing_log_id > 0) {
                    $this->db->rollback();
                    return false;
                }
            }

            $old_level_id = (int) $account->level_id;
            $new_balance = max(0, round((float) $account->growth_value + (float) $amount, 2));
            $new_level = $this->get_level_by_growth($new_balance);
            $new_level_id = $new_level ? (int) $new_level->id : 0;
            $highest_level_id = $this->resolve_highest_level_id((int) $account->highest_level_id, $new_level_id);

            $updated = $this->db->update('user_growth', [
                'growth_value' => $new_balance,
                'level_id' => $new_level_id,
                'highest_level_id' => $highest_level_id,
                'updated_at' => current_time('mysql'),
            ], ['user_id' => $user_id]);

            if ($updated === false) {
                throw new Exception('Failed to update user growth');
            }

            $log_id = $this->db->insert('growth_log', [
                'user_id' => $user_id,
                'amount' => $amount,
                'balance_after' => $new_balance,
                'source' => $source,
                'source_id' => $source_id,
                'dedupe_key' => $this->build_dedupe_key($user_id, $source, $source_id),
                'description' => $description,
                'created_at' => current_time('mysql'),
            ]);

            if (!$log_id) {
                throw new Exception('Failed to insert growth log');
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[qilingshop] Growth change failed: ' . $e->getMessage());
            }
            return false;
        }

        if ($old_level_id !== $new_level_id) {
            do_action('qilingshop_growth_level_changed', $user_id, $old_level_id, $new_level_id);
        }

        return true;
    }

    private function table_exists($table) {
        global $wpdb;
        $cache_key = sanitize_key((string) $table);
        if ($cache_key !== '' && array_key_exists($cache_key, $this->table_exists_cache)) {
            return $this->table_exists_cache[$cache_key];
        }

        try {
            $table_name = $this->db->get_table($table);
        } catch (Exception $e) {
            return false;
        }
        $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) === $table_name;
        if ($cache_key !== '') {
            $this->table_exists_cache[$cache_key] = $exists;
        }
        return $exists;
    }

    public function get_highest_user_level($user_id) {
        $account = $this->get_user_growth($user_id);
        if (!$account || empty($account->highest_level_id)) {
            return null;
        }

        return $this->get_level((int) $account->highest_level_id);
    }

    private function get_levels_for_calculation($active_only = true) {
        $levels = $this->get_levels($active_only);
        usort($levels, [$this, 'compare_levels']);
        return $levels;
    }

    private function compare_levels($left, $right) {
        $left_min = isset($left->min_growth) ? (float) $left->min_growth : 0;
        $right_min = isset($right->min_growth) ? (float) $right->min_growth : 0;
        if ($left_min !== $right_min) {
            return $left_min < $right_min ? -1 : 1;
        }

        $left_sort = isset($left->sort_order) ? (int) $left->sort_order : 0;
        $right_sort = isset($right->sort_order) ? (int) $right->sort_order : 0;
        if ($left_sort !== $right_sort) {
            return $left_sort < $right_sort ? -1 : 1;
        }

        return (int) ($left->id ?? 0) <=> (int) ($right->id ?? 0);
    }

    private function is_level_higher($candidate_id, $current_id) {
        $candidate = $this->get_level($candidate_id);
        if (!$candidate) {
            return false;
        }

        $current = $this->get_level($current_id);
        if (!$current) {
            return true;
        }

        return $this->compare_levels($candidate, $current) > 0;
    }

    private function resolve_highest_level_id($current_highest_id, $candidate_id) {
        $current_highest_id = absint($current_highest_id);
        $candidate_id = absint($candidate_id);
        if ($candidate_id <= 0) {
            return $current_highest_id;
        }

        return $this->is_level_higher($candidate_id, $current_highest_id) ? $candidate_id : $current_highest_id;
    }

    private function build_dedupe_key($user_id, $source, $source_id) {
        $source_id = absint($source_id);
        if ($source_id <= 0) {
            return null;
        }

        return md5(absint($user_id) . '|' . sanitize_key((string) $source) . '|' . $source_id);
    }

}
