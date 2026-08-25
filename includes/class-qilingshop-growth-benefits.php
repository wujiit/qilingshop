<?php
/**
 * 成长权益配置与查询。
 *
 * 权益类型由系统注册，等级和权益的绑定完全由后台配置。
 *
 * @package QilingShop
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Growth_Benefits {
    private static $instance = null;

    /**
     * @var QilingShop_Database
     */
    private $db;

    /**
     * @var array<string,array>
     */
    private $benefit_types = [];

    /**
     * @var bool
     */
    private $default_benefit_types_registered = false;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->db = QilingShop_Database::instance();
        if (did_action('init') || doing_action('init')) {
            $this->register_default_benefit_types();
            $this->load_external_benefit_types();
        } else {
            add_action('init', [$this, 'register_default_benefit_types'], 5);
            add_action('init', [$this, 'load_external_benefit_types'], 20);
        }
    }

    public function load_external_benefit_types() {
        $this->register_default_benefit_types();
        do_action('qilingshop_growth_register_benefit_types', $this);
    }

    public function register_benefit_type($type, $args = []) {
        $type = sanitize_key((string) $type);
        if ($type === '') {
            return false;
        }

        $defaults = [
            'label' => $type,
            'description' => '',
            'value_label' => (did_action('init') || doing_action('init')) ? __('权益值', 'qilingshop') : '权益值',
            'value_placeholder' => '',
            'config_schema' => [],
            'default_display_title' => '',
            'default_display_desc' => '',
        ];
        $this->benefit_types[$type] = wp_parse_args($args, $defaults);
        return true;
    }

    public function get_benefit_types() {
        if (!$this->default_benefit_types_registered && (did_action('init') || doing_action('init'))) {
            $this->register_default_benefit_types();
        }
        return $this->benefit_types;
    }

    public function get_benefit_type($type) {
        if (!$this->default_benefit_types_registered && (did_action('init') || doing_action('init'))) {
            $this->register_default_benefit_types();
        }
        $type = sanitize_key((string) $type);
        return $this->benefit_types[$type] ?? null;
    }

    public function get_benefit($benefit_id) {
        $benefit_id = absint($benefit_id);
        if ($benefit_id <= 0 || !$this->table_exists()) {
            return null;
        }

        $benefit = $this->db->get_by_id('growth_benefits', $benefit_id);
        return $benefit ? $this->prepare_benefit($benefit) : null;
    }

    public function get_level_benefits($level_id, $active_only = true) {
        $level_id = absint($level_id);
        if ($level_id <= 0 || !$this->table_exists()) {
            return [];
        }

        $where = ['level_id' => $level_id];
        if ($active_only) {
            $where['status'] = 1;
        }

        $rows = $this->db->get_results('growth_benefits', [
            'where' => $where,
            'orderby' => 'sort_order',
            'order' => 'ASC',
        ]);

        return array_map([$this, 'prepare_benefit'], $rows);
    }

    public function get_benefits($args = []) {
        if (!$this->table_exists()) {
            return [];
        }

        $defaults = [
            'level_id' => 0,
            'active_only' => false,
            'limit' => -1,
        ];
        $args = wp_parse_args($args, $defaults);
        $where = [];
        if (!empty($args['level_id'])) {
            $where['level_id'] = absint($args['level_id']);
        }
        if (!empty($args['active_only'])) {
            $where['status'] = 1;
        }

        $rows = $this->db->get_results('growth_benefits', [
            'where' => $where,
            'orderby' => 'sort_order',
            'order' => 'ASC',
            'limit' => (int) $args['limit'],
        ]);

        return array_map([$this, 'prepare_benefit'], $rows);
    }

    public function get_user_benefits($user_id, $active_only = true) {
        if (!class_exists('QilingShop_Growth') || !QilingShop_Growth::instance()->is_enabled()) {
            return [];
        }

        $account = QilingShop_Growth::instance()->get_user_growth($user_id);
        if (!$account || empty($account->level_id)) {
            return [];
        }

        return $this->get_level_benefits((int) $account->level_id, $active_only);
    }

    public function user_has_benefit($user_id, $benefit_type, $context = []) {
        $benefit_type = sanitize_key((string) $benefit_type);
        foreach ($this->get_user_benefits($user_id, true) as $benefit) {
            if ((string) $benefit->benefit_type !== $benefit_type) {
                continue;
            }

            $matched = apply_filters('qilingshop_growth_user_has_benefit', true, $user_id, $benefit, $context);
            if ($matched) {
                return true;
            }
        }

        return false;
    }

    public function get_user_benefit_value($user_id, $benefit_type, $default = null) {
        $benefit_type = sanitize_key((string) $benefit_type);
        foreach ($this->get_user_benefits($user_id, true) as $benefit) {
            if ((string) $benefit->benefit_type === $benefit_type) {
                return $benefit->benefit_value !== null ? $benefit->benefit_value : $default;
            }
        }
        return $default;
    }

    public function get_user_benefit_config($user_id, $benefit_type) {
        $benefit_type = sanitize_key((string) $benefit_type);
        foreach ($this->get_user_benefits($user_id, true) as $benefit) {
            if ((string) $benefit->benefit_type === $benefit_type) {
                return is_array($benefit->benefit_config) ? $benefit->benefit_config : [];
            }
        }
        return [];
    }

    public function get_user_benefits_by_type($user_id, $benefit_type) {
        $benefit_type = sanitize_key((string) $benefit_type);
        if ($benefit_type === '') {
            return [];
        }

        $items = [];
        foreach ($this->get_user_benefits($user_id, true) as $benefit) {
            if ((string) $benefit->benefit_type === $benefit_type) {
                $items[] = $benefit;
            }
        }
        return $items;
    }

    public function user_can_claim_coupon($user_id, $coupon_id, $claimed_count = null) {
        $user_id = absint($user_id);
        $coupon_id = absint($coupon_id);
        if ($user_id <= 0 || $coupon_id <= 0) {
            return false;
        }

        foreach ($this->get_user_benefits_by_type($user_id, 'coupon_access') as $benefit) {
            $config = is_array($benefit->benefit_config) ? $benefit->benefit_config : [];
            $coupon_ids = array_map('absint', (array) ($config['coupon_ids'] ?? []));
            if (!in_array($coupon_id, $coupon_ids, true)) {
                continue;
            }

            $limit = isset($config['claim_limit']) ? (int) $config['claim_limit'] : 0;
            if ($claimed_count !== null && $limit > 0 && (int) $claimed_count >= $limit) {
                continue;
            }
            return true;
        }

        return false;
    }

    public function get_task_growth_bonus($user_id, $task_id, $base_amount) {
        $user_id = absint($user_id);
        $task_id = sanitize_key((string) $task_id);
        $base_amount = max(0, (float) $base_amount);
        if ($user_id <= 0 || $base_amount <= 0) {
            return 0;
        }

        $bonus = 0;
        foreach ($this->get_user_benefits_by_type($user_id, 'task_growth_bonus') as $benefit) {
            $percent = (float) $benefit->benefit_value;
            if ($percent <= 0) {
                continue;
            }

            $config = is_array($benefit->benefit_config) ? $benefit->benefit_config : [];
            $task_types = trim((string) ($config['task_types'] ?? ''));
            if ($task_types !== '') {
                $allowed = array_filter(array_map('sanitize_key', preg_split('/[，,\s]+/u', $task_types)));
                if ($task_id === '' || !in_array($task_id, $allowed, true)) {
                    continue;
                }
            }

            $single_bonus = round($base_amount * $percent / 100, 2);
            $max_bonus = isset($config['max_bonus']) ? (float) $config['max_bonus'] : 0;
            if ($max_bonus > 0) {
                $single_bonus = min($single_bonus, $max_bonus);
            }
            $bonus += max(0, $single_bonus);
        }

        return round($bonus, 2);
    }

    public function get_download_extra_quota($user_id) {
        $extra = 0;
        foreach ($this->get_user_benefits_by_type($user_id, 'download_extra_quota') as $benefit) {
            $extra += max(0, (int) $benefit->benefit_value);
        }
        return $extra;
    }

    public function get_birthday_gift_upgrade($user_id) {
        foreach ($this->get_user_benefits_by_type($user_id, 'birthday_gift_upgrade') as $benefit) {
            $config = is_array($benefit->benefit_config) ? $benefit->benefit_config : [];
            return [
                'coupon_id'    => absint($config['coupon_id'] ?? 0),
                'extra_growth' => max(0, (float) ($config['extra_growth'] ?? 0)),
                'label'        => (string) $benefit->benefit_value,
            ];
        }
        return ['coupon_id' => 0, 'extra_growth' => 0, 'label' => ''];
    }

    public function get_order_highlight($user_id) {
        foreach ($this->get_user_benefits_by_type($user_id, 'order_highlight') as $benefit) {
            $config = is_array($benefit->benefit_config) ? $benefit->benefit_config : [];
            return [
                'label' => $benefit->benefit_value !== '' ? (string) $benefit->benefit_value : __('成长权益', 'qilingshop'),
                'color' => sanitize_hex_color((string) ($config['color'] ?? '')) ?: '#fde68a',
            ];
        }
        return null;
    }

    public function get_user_effective_summaries($user_id) {
        $summaries = [];
        foreach ($this->get_user_benefits($user_id, true) as $benefit) {
            $summary = $this->describe_effective_benefit($benefit);
            if ($summary !== '') {
                $summaries[] = $summary;
            }
        }
        return $summaries;
    }

    public function describe_effective_benefit($benefit) {
        if (!$benefit || empty($benefit->benefit_type)) {
            return '';
        }

        $type = sanitize_key((string) $benefit->benefit_type);
        $value = trim((string) ($benefit->benefit_value ?? ''));
        $config = is_array($benefit->benefit_config ?? null) ? $benefit->benefit_config : [];

        if ($type === 'coupon_access') {
            $coupon_ids = array_filter(array_map('absint', (array) ($config['coupon_ids'] ?? [])));
            if (empty($coupon_ids)) {
                return __('可领取后台配置的成长专属优惠券', 'qilingshop');
            }
            return sprintf(__('可领取成长专属优惠券：%s', 'qilingshop'), implode(', ', $coupon_ids));
        }

        if ($type === 'task_growth_bonus') {
            $percent = max(0, (float) $value);
            return $percent > 0 ? sprintf(__('任务成长值额外加成 %s%%', 'qilingshop'), number_format($percent, 2)) : '';
        }

        if ($type === 'birthday_gift_upgrade') {
            $parts = [];
            if (!empty($config['coupon_id'])) {
                $parts[] = sprintf(__('升级生日券 #%d', 'qilingshop'), absint($config['coupon_id']));
            }
            if (!empty($config['extra_growth'])) {
                $parts[] = sprintf(__('生日额外成长值 %s', 'qilingshop'), number_format((float) $config['extra_growth'], 2));
            }
            return !empty($parts) ? implode('，', $parts) : __('生日礼包升级', 'qilingshop');
        }

        if ($type === 'download_extra_quota') {
            $quota = max(0, (int) $value);
            return $quota > 0 ? sprintf(__('每日 VIP 免费下载次数 +%d', 'qilingshop'), $quota) : '';
        }

        if ($type === 'order_highlight') {
            return $value !== '' ? sprintf(__('后台订单高亮：%s', 'qilingshop'), $value) : __('后台订单高亮', 'qilingshop');
        }

        if ($type === 'custom_link') {
            $button_text = trim((string) ($config['button_text'] ?? ''));
            if ($button_text !== '' && $value !== '') {
                return sprintf(__('自定义链接：%1$s（%2$s）', 'qilingshop'), $button_text, $value);
            }
            return $value !== '' ? sprintf(__('自定义链接：%s', 'qilingshop'), $value) : __('自定义链接', 'qilingshop');
        }

        if ($type === 'custom_text') {
            if ($value !== '') {
                return sprintf(__('自定义文本：%s', 'qilingshop'), $value);
            }
            $content = trim((string) ($config['content'] ?? ''));
            return $content !== '' ? wp_trim_words($content, 18) : __('自定义文本', 'qilingshop');
        }

        $types = $this->get_benefit_types();
        return (string) ($benefit->display_title ?? ($types[$type]['label'] ?? $type));
    }

    public function prepare_config_from_request($type, $raw_config) {
        $type_data = $this->get_benefit_type($type);
        $schema = is_array($type_data['config_schema'] ?? null) ? $type_data['config_schema'] : [];
        $raw_config = is_array($raw_config) ? $raw_config : [];
        $config = [];

        foreach ($schema as $key => $field) {
            $key = sanitize_key((string) $key);
            if ($key === '') {
                continue;
            }
            $field_type = sanitize_key((string) ($field['type'] ?? 'text'));
            $value = $raw_config[$key] ?? '';
            if (is_array($value)) {
                $value = array_map('sanitize_text_field', array_map('wp_unslash', $value));
            } else {
                $value = wp_unslash($value);
            }

            if ($field_type === 'number') {
                $config[$key] = (float) $value;
            } elseif ($field_type === 'int') {
                $config[$key] = (int) $value;
            } elseif ($field_type === 'checkbox') {
                $config[$key] = !empty($value) ? 1 : 0;
            } elseif ($field_type === 'textarea') {
                $config[$key] = sanitize_textarea_field((string) $value);
            } elseif ($field_type === 'url') {
                $config[$key] = esc_url_raw((string) $value);
            } elseif ($field_type === 'ids') {
                $items = is_array($value) ? $value : preg_split('/[,\s]+/', (string) $value);
                $config[$key] = array_values(array_filter(array_map('absint', (array) $items)));
            } else {
                $config[$key] = sanitize_text_field((string) $value);
            }
        }

        return $config;
    }

    public function render_config_fields($type, $config = []) {
        $type_data = $this->get_benefit_type($type);
        $schema = is_array($type_data['config_schema'] ?? null) ? $type_data['config_schema'] : [];
        if (empty($schema)) {
            echo '<p class="description">' . esc_html__('该权益类型暂无额外参数。', 'qilingshop') . '</p>';
            return;
        }

        foreach ($schema as $key => $field) {
            $key = sanitize_key((string) $key);
            if ($key === '') {
                continue;
            }
            $label = (string) ($field['label'] ?? $key);
            $field_type = sanitize_key((string) ($field['type'] ?? 'text'));
            $placeholder = (string) ($field['placeholder'] ?? '');
            $desc = (string) ($field['description'] ?? '');
            $value = $config[$key] ?? '';
            if (is_array($value)) {
                $value = implode(',', $value);
            }
            echo '<p class="qls-growth-config-field"><label>' . esc_html($label);
            if ($field_type === 'textarea') {
                echo '<textarea name="benefit_config[' . esc_attr($key) . ']" class="large-text" rows="3" placeholder="' . esc_attr($placeholder) . '">' . esc_textarea((string) $value) . '</textarea>';
            } elseif ($field_type === 'checkbox') {
                echo '<span><input type="checkbox" name="benefit_config[' . esc_attr($key) . ']" value="1" ' . checked(!empty($value), true, false) . '> ' . esc_html($placeholder) . '</span>';
            } elseif ($field_type === 'url') {
                echo '<input type="url" name="benefit_config[' . esc_attr($key) . ']" value="' . esc_attr((string) $value) . '" class="regular-text" placeholder="' . esc_attr($placeholder) . '">';
            } elseif (in_array($field_type, ['number', 'int'], true)) {
                echo '<input type="number" step="' . ($field_type === 'int' ? '1' : '0.01') . '" name="benefit_config[' . esc_attr($key) . ']" value="' . esc_attr((string) $value) . '" placeholder="' . esc_attr($placeholder) . '">';
            } else {
                echo '<input type="text" name="benefit_config[' . esc_attr($key) . ']" value="' . esc_attr((string) $value) . '" class="regular-text" placeholder="' . esc_attr($placeholder) . '">';
            }
            if ($desc !== '') {
                echo '<span class="description">' . esc_html($desc) . '</span>';
            }
            echo '</label></p>';
        }
    }

    public function register_default_benefit_types() {
        if ($this->default_benefit_types_registered) {
            return;
        }

        $this->default_benefit_types_registered = true;

        $this->register_benefit_type('badge', [
            'label' => __('成长徽章', 'qilingshop'),
            'description' => __('用于前台展示身份徽章，不改变业务权限。', 'qilingshop'),
            'value_label' => __('徽章文字', 'qilingshop'),
            'value_placeholder' => __('例如：核心用户', 'qilingshop'),
            'config_schema' => [
                'badge_color' => ['label' => __('徽章颜色', 'qilingshop'), 'type' => 'text', 'placeholder' => '#2563eb'],
                'show_position' => ['label' => __('展示位置', 'qilingshop'), 'type' => 'text', 'placeholder' => __('个人中心,评价区', 'qilingshop')],
            ],
        ]);
        $this->register_benefit_type('support_priority', [
            'label' => __('售后优先级', 'qilingshop'),
            'description' => __('仅提供优先级数值，具体业务模块后续接入。', 'qilingshop'),
            'value_label' => __('优先级数值', 'qilingshop'),
            'value_placeholder' => '80',
            'config_schema' => [
                'label' => ['label' => __('后台标签', 'qilingshop'), 'type' => 'text', 'placeholder' => __('优先处理', 'qilingshop')],
                'color' => ['label' => __('标签颜色', 'qilingshop'), 'type' => 'text', 'placeholder' => '#f59e0b'],
            ],
        ]);
        $this->register_benefit_type('support_label', [
            'label' => __('客服标识', 'qilingshop'),
            'description' => __('给后台或前台客服场景展示的身份标识。', 'qilingshop'),
            'value_label' => __('标识文本', 'qilingshop'),
            'value_placeholder' => __('专属客服', 'qilingshop'),
        ]);
        $this->register_benefit_type('coupon_access', [
            'label' => __('优惠券领取资格', 'qilingshop'),
            'description' => __('配置可领取的优惠券 ID，业务生效将在后续阶段接入。', 'qilingshop'),
            'value_label' => __('资格说明', 'qilingshop'),
            'config_schema' => [
                'coupon_ids' => ['label' => __('优惠券 ID', 'qilingshop'), 'type' => 'ids', 'placeholder' => '12,18', 'description' => __('多个 ID 用逗号分隔。', 'qilingshop')],
                'claim_limit' => ['label' => __('每人领取次数', 'qilingshop'), 'type' => 'int', 'placeholder' => '1'],
            ],
        ]);
        $this->register_benefit_type('activity_access', [
            'label' => __('活动参与资格', 'qilingshop'),
            'description' => __('配置活动资格标识，业务生效将在后续阶段接入。', 'qilingshop'),
            'value_label' => __('活动标识', 'qilingshop'),
            'value_placeholder' => 'growth_core_user',
        ]);
        $this->register_benefit_type('task_growth_bonus', [
            'label' => __('任务成长值加成', 'qilingshop'),
            'description' => __('只用于成长值加成，不影响积分奖励。', 'qilingshop'),
            'value_label' => __('加成比例(%)', 'qilingshop'),
            'value_placeholder' => '10',
            'config_schema' => [
                'max_bonus' => ['label' => __('单次最高加成', 'qilingshop'), 'type' => 'number', 'placeholder' => '100'],
                'task_types' => ['label' => __('适用任务类型', 'qilingshop'), 'type' => 'text', 'placeholder' => __('留空表示全部', 'qilingshop')],
            ],
        ]);
        $this->register_benefit_type('birthday_gift_upgrade', [
            'label' => __('生日礼包升级', 'qilingshop'),
            'description' => __('生日场景扩展配置，业务生效将在后续阶段接入。', 'qilingshop'),
            'value_label' => __('礼包说明', 'qilingshop'),
            'config_schema' => [
                'coupon_id' => ['label' => __('升级优惠券 ID', 'qilingshop'), 'type' => 'int'],
                'extra_growth' => ['label' => __('额外成长值', 'qilingshop'), 'type' => 'number'],
            ],
        ]);
        $this->register_benefit_type('download_extra_quota', [
            'label' => __('每日额外下载次数', 'qilingshop'),
            'description' => __('默认只作为配置，不会绕过现有资源和 VIP 权限。', 'qilingshop'),
            'value_label' => __('额外次数', 'qilingshop'),
            'value_placeholder' => '1',
            'config_schema' => [
                'free_only' => ['label' => __('仅限免费/已购资源', 'qilingshop'), 'type' => 'checkbox', 'placeholder' => __('不绕过付费和 VIP 专属限制', 'qilingshop')],
            ],
        ]);
        $this->register_benefit_type('order_highlight', [
            'label' => __('订单高亮', 'qilingshop'),
            'description' => __('后台订单列表展示配置，业务生效将在后续阶段接入。', 'qilingshop'),
            'value_label' => __('高亮说明', 'qilingshop'),
            'config_schema' => [
                'color' => ['label' => __('高亮颜色', 'qilingshop'), 'type' => 'text', 'placeholder' => '#fde68a'],
            ],
        ]);
        $this->register_benefit_type('custom_link', [
            'label' => __('自定义链接', 'qilingshop'),
            'description' => __('前台展示一个自定义权益链接。', 'qilingshop'),
            'value_label' => __('链接地址', 'qilingshop'),
            'value_placeholder' => 'https://example.com/member-benefit',
            'value_input_type' => 'url',
            'default_display_title' => __('专属权益入口', 'qilingshop'),
            'config_schema' => [
                'button_text' => ['label' => __('按钮文案', 'qilingshop'), 'type' => 'text', 'placeholder' => __('立即查看', 'qilingshop')],
            ],
        ]);
        $this->register_benefit_type('custom_text', [
            'label' => __('自定义文本', 'qilingshop'),
            'description' => __('纯展示权益，不影响任何业务流程。', 'qilingshop'),
            'value_label' => __('简短文本', 'qilingshop'),
            'value_placeholder' => __('例如：专属客服通道', 'qilingshop'),
            'default_display_title' => __('专属权益说明', 'qilingshop'),
            'config_schema' => [
                'content' => ['label' => __('详细内容', 'qilingshop'), 'type' => 'textarea', 'placeholder' => __('填写前台展示给用户的权益说明。', 'qilingshop')],
            ],
        ]);
    }

    private function prepare_benefit($benefit) {
        if (!$benefit) {
            return $benefit;
        }

        $config = [];
        if (!empty($benefit->benefit_config)) {
            $decoded = json_decode((string) $benefit->benefit_config, true);
            if (is_array($decoded)) {
                $config = $decoded;
            }
        }
        $benefit->benefit_config = $config;
        return $benefit;
    }

    private function table_exists() {
        global $wpdb;
        try {
            $table_name = $this->db->get_table('growth_benefits');
        } catch (Exception $e) {
            return false;
        }
        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) === $table_name;
    }
}
