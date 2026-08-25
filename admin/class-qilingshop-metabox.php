<?php
/**
 * 文章 Metabox
 */
if (!defined('ABSPATH')) exit;

class QilingShop_Metabox {
    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('add_meta_boxes', [$this, 'add_meta_box']);
        add_action('save_post', [$this, 'save_meta'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('admin_body_class', [$this, 'filter_admin_body_class']);
    }

    /**
     * 获取资源 Metabox 编辑页的文章类型。
     *
     * @param string $hook 当前后台钩子。
     * @return string
     */
    private function get_metabox_editor_post_type($hook = '') {
        $hook = (string) $hook;

        if ($hook !== '' && !in_array($hook, ['post.php', 'post-new.php', 'post'], true)) {
            return '';
        }

        if ($hook === '') {
            global $pagenow;
            if (!in_array((string) $pagenow, ['post.php', 'post-new.php'], true)) {
                return '';
            }
        }

        $post_type = '';
        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ($screen instanceof WP_Screen && !empty($screen->post_type)) {
                $post_type = (string) $screen->post_type;
            }
        }

        if ($post_type === '' && isset($_GET['post_type'])) {
            $post_type = sanitize_key((string) wp_unslash($_GET['post_type']));
        }

        if ($post_type === '' && isset($_GET['post'])) {
            $post_type = (string) get_post_type((int) $_GET['post']);
        }

        return sanitize_key($post_type);
    }

    private function is_metabox_editor_screen($hook = '') {
        $post_type = $this->get_metabox_editor_post_type($hook);
        if ($post_type === '') {
            return false;
        }

        $post_types = qilingshop_normalize_resource_post_types(get_option('qilingshop_post_types', ['post']), ['post']);
        return in_array($post_type, $post_types, true);
    }

    /**
     * 是否加载资源 Metabox 专用样式。
     *
     * 默认跳过普通文章编辑器，避免商城后台皮肤进入文章编辑页。
     *
     * @param string $hook 当前后台钩子。
     * @return bool
     */
    private function should_enqueue_metabox_styles($hook = '') {
        if (!$this->is_metabox_editor_screen($hook)) {
            return false;
        }

        $post_type = $this->get_metabox_editor_post_type($hook);
        $should_enqueue = $post_type !== 'post';

        return (bool) apply_filters(
            'qilingshop_should_enqueue_resource_metabox_styles',
            $should_enqueue,
            $post_type,
            $hook
        );
    }

    /**
     * 为资源文章编辑页加载专用样式。
     *
     * @param string $hook 当前后台钩子。
     * @return void
     */
    public function enqueue_assets($hook) {
        if (!$this->should_enqueue_metabox_styles($hook)) {
            return;
        }

        $style_version = QILINGSHOP_VERSION;
        $style_file = QILINGSHOP_PATH . 'static/css/qilingshop-metabox.css';
        if (file_exists($style_file)) {
            $style_version .= '.' . (string) filemtime($style_file);
        }

        wp_enqueue_style(
            'qilingshop-metabox',
            QILINGSHOP_URL . 'static/css/qilingshop-metabox.css',
            [],
            $style_version
        );
    }

    /**
     * 为资源文章编辑页追加 body class，便于专属样式生效。
     *
     * @param string $classes 后台 body class 字符串。
     * @return string
     */
    public function filter_admin_body_class($classes) {
        if (!$this->is_metabox_editor_screen()) {
            return $classes;
        }

        $token = 'qilingshop-resource-editor';
        if (strpos(' ' . $classes . ' ', ' ' . $token . ' ') === false) {
            $classes .= ' ' . $token;
        }

        return trim($classes);
    }

    public function add_meta_box() {
        $post_types = qilingshop_normalize_resource_post_types(get_option('qilingshop_post_types', ['post']), ['post']);
        foreach ((array)$post_types as $type) {
            add_meta_box(
                'qilingshop_resource',
                __('启灵商城 - 资源设置', 'qilingshop'),
                [$this, 'render'],
                $type,
                'normal',
                'high'
            );
        }
    }

    public function render($post) {
        wp_nonce_field('qilingshop_metabox', 'qilingshop_nonce');
        
        $price = get_post_meta($post->ID, '_qilingshop_price', true);
        $price_download = get_post_meta($post->ID, '_qilingshop_price_download', true);
        $price_view = get_post_meta($post->ID, '_qilingshop_price_view', true);
        $vip_discount = get_post_meta($post->ID, '_qilingshop_vip_discount', true);
        $vip_min_level = get_post_meta($post->ID, '_qilingshop_vip_min_level', true);
        $vip_only_purchase = get_post_meta($post->ID, '_qilingshop_vip_only_purchase', true);
        $vip_only_access = get_post_meta($post->ID, '_qilingshop_vip_only_access', true);
        $vip_only_legacy = get_post_meta($post->ID, '_qilingshop_vip_only', true);
        $vip_free = get_post_meta($post->ID, '_qilingshop_vip_free', true);
        $vip_discount_mode = get_post_meta($post->ID, '_qilingshop_vip_discount_mode', true);
        $vip_discount_percent = get_post_meta($post->ID, '_qilingshop_vip_discount_percent', true);
        $vip_level_discounts_raw = get_post_meta($post->ID, '_qilingshop_vip_level_discounts', true);
        $vip_level_discounts = [];
        if (!empty($vip_level_discounts_raw)) {
            $decoded = json_decode($vip_level_discounts_raw, true);
            if (is_array($decoded)) {
                $vip_level_discounts = $decoded;
            }
        }

        if ($price_download === '' && $price_view === '' && $price !== '') {
            $price_download = $price;
            $price_view = $price;
        }

        if ($vip_min_level === '' || $vip_min_level <= 0) {
            $vip_min_level = 1;
        }
        if ($vip_only_purchase === '') {
            $vip_only_purchase = $vip_only_legacy !== '' ? $vip_only_legacy : 'none';
        }
        if ($vip_only_access === '') {
            $vip_only_access = 'none';
        }
        if ($vip_free === '') {
            $vip_free = 'none';
        }

        if ($vip_discount_mode === '') {
            $legacy_setting = $vip_discount;
            if ($legacy_setting === '') {
                $legacy_setting = get_option('qilingshop_default_vip_discount', 'none');
            }
            if ($legacy_setting === 'none') {
                $vip_discount_mode = 'none';
            } elseif ($legacy_setting === 'default') {
                $vip_discount_mode = 'inherit';
            } elseif ($legacy_setting === 'vip_free') {
                $vip_discount_mode = 'custom';
                $vip_discount_percent = 0;
                if ($vip_free === 'none') {
                    $vip_free = 'both';
                }
            } elseif (preg_match('/^vip_(\d+)$/', $legacy_setting, $matches)) {
                $vip_discount_mode = 'custom';
                $vip_discount_percent = (int) $matches[1];
            } else {
                $vip_discount_mode = 'inherit';
            }
        }
        $download_urls = get_post_meta($post->ID, '_qilingshop_download_urls', true);
        $hidden_content = get_post_meta($post->ID, '_qilingshop_hidden_content', true);
        $sale_mode = get_post_meta($post->ID, '_qilingshop_sale_mode', true) ?: 'download';
        $points_resource_enabled = get_post_meta($post->ID, '_qilingshop_points_resource_enabled', true);
        if ($points_resource_enabled === '') {
            $points_resource_enabled = true;
        }

        $points_name = qilingshop_get_points_name();
        $vip_levels = QilingShop_VIP::instance()->get_levels();
        ?>
        <table class="form-table qls-ui-form-table qilingshop-metabox">
            <tr>
                <th><?php _e('启用积分付费资源', 'qilingshop'); ?></th>
                <td>
                    <input type="hidden" name="has_qilingshop_points_resource_enabled" value="1">
                    <label><input type="checkbox" name="qilingshop_points_resource_enabled" value="1" <?php checked($points_resource_enabled); ?>> <?php _e('是', 'qilingshop'); ?></label>
                    <p class="description"><?php _e('关闭后，该文章前台不显示付费资源内容，也不加载积分资源相关前端文件。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('销售模式', 'qilingshop'); ?></th>
                <td>
                    <select name="qilingshop_sale_mode">
                        <option value="free" <?php selected($sale_mode, 'free'); ?>><?php _e('免费资源', 'qilingshop'); ?></option>
                        <option value="download" <?php selected($sale_mode, 'download'); ?>><?php _e('付费下载', 'qilingshop'); ?></option>
                        <option value="view" <?php selected($sale_mode, 'view'); ?>><?php _e('付费查看', 'qilingshop'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><?php _e('资源价格', 'qilingshop'); ?></th>
                <td>
                    <div class="qls-metabox-flex-row qls-metabox-flex-row-end">
                        <label>
                            <?php echo sprintf(__('下载价（%s）', 'qilingshop'), $points_name); ?>
                            <input type="number" name="qilingshop_price_download" value="<?php echo esc_attr($price_download); ?>" step="0.01" min="0" class="qls-metabox-inline-field qls-metabox-inline-number">
                        </label>
                        <label>
                            <?php echo sprintf(__('查看价（%s）', 'qilingshop'), $points_name); ?>
                            <input type="number" name="qilingshop_price_view" value="<?php echo esc_attr($price_view); ?>" step="0.01" min="0" class="qls-metabox-inline-field qls-metabox-inline-number">
                        </label>
                    </div>
                    <p class="description"><?php _e('0 = 免费。下载价用于“付费下载”，查看价用于“付费查看”。下载授权包含查看，查看不包含下载；两者都填写时可先购查看再补差价升级下载。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('服务标签', 'qilingshop'); ?></th>
                <td>
                    <input type="text" name="qilingshop_service_tags" value="<?php echo esc_attr(get_post_meta($post->ID, '_qilingshop_service_tags', true)); ?>" class="large-text">
                    <p class="description"><?php _e('多个标签请用半角逗号分隔，例如：正版授权,永久更新', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('VIP 权益设置', 'qilingshop'); ?></th>
                <td>
                    <div class="qls-metabox-flex-row">
                        <label>
                            <?php _e('最低等级', 'qilingshop'); ?>
                            <select name="qilingshop_vip_min_level" class="qls-metabox-inline-field">
                                <?php foreach ($vip_levels as $level): ?>
                                    <option value="<?php echo esc_attr($level->id); ?>" <?php selected((int)$vip_min_level, (int)$level->id); ?>><?php echo esc_html($level->level_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <?php _e('仅限购买', 'qilingshop'); ?>
                            <select name="qilingshop_vip_only_purchase" class="qls-metabox-inline-field">
                                <option value="none" <?php selected($vip_only_purchase, 'none'); ?>><?php _e('否', 'qilingshop'); ?></option>
                                <option value="download" <?php selected($vip_only_purchase, 'download'); ?>><?php _e('仅下载', 'qilingshop'); ?></option>
                                <option value="view" <?php selected($vip_only_purchase, 'view'); ?>><?php _e('仅查看', 'qilingshop'); ?></option>
                                <option value="both" <?php selected($vip_only_purchase, 'both'); ?>><?php _e('下载+查看', 'qilingshop'); ?></option>
                            </select>
                        </label>
                        <label>
                            <?php _e('仅限访问', 'qilingshop'); ?>
                            <select name="qilingshop_vip_only_access" class="qls-metabox-inline-field">
                                <option value="none" <?php selected($vip_only_access, 'none'); ?>><?php _e('否', 'qilingshop'); ?></option>
                                <option value="download" <?php selected($vip_only_access, 'download'); ?>><?php _e('仅下载', 'qilingshop'); ?></option>
                                <option value="view" <?php selected($vip_only_access, 'view'); ?>><?php _e('仅查看', 'qilingshop'); ?></option>
                                <option value="both" <?php selected($vip_only_access, 'both'); ?>><?php _e('下载+查看', 'qilingshop'); ?></option>
                            </select>
                        </label>
                        <label>
                            <?php _e('VIP免费', 'qilingshop'); ?>
                            <select name="qilingshop_vip_free" class="qls-metabox-inline-field">
                                <option value="none" <?php selected($vip_free, 'none'); ?>><?php _e('不启用', 'qilingshop'); ?></option>
                                <option value="download" <?php selected($vip_free, 'download'); ?>><?php _e('仅下载', 'qilingshop'); ?></option>
                                <option value="view" <?php selected($vip_free, 'view'); ?>><?php _e('仅查看', 'qilingshop'); ?></option>
                                <option value="both" <?php selected($vip_free, 'both'); ?>><?php _e('下载+查看', 'qilingshop'); ?></option>
                            </select>
                        </label>
                    </div>
                    <p class="description qls-metabox-mt-8">
                        <?php _e('仅限购买：只限制新购买，已购用户仍可访问。仅限访问：强约束，已购用户也会被拦截。', 'qilingshop'); ?>
                    </p>
                    <div class="qls-metabox-mt-10">
                        <label><?php _e('VIP折扣', 'qilingshop'); ?></label>
                        <select name="qilingshop_vip_discount_mode" id="qilingshop-vip-discount-mode" class="qls-metabox-inline-field">
                            <option value="inherit" <?php selected($vip_discount_mode, 'inherit'); ?>><?php _e('继承等级默认折扣', 'qilingshop'); ?></option>
                            <option value="none" <?php selected($vip_discount_mode, 'none'); ?>><?php _e('不参与VIP折扣', 'qilingshop'); ?></option>
                            <option value="custom" <?php selected($vip_discount_mode, 'custom'); ?>><?php _e('自定义折扣', 'qilingshop'); ?></option>
                            <option value="per_level" <?php selected($vip_discount_mode, 'per_level'); ?>><?php _e('按等级自定义', 'qilingshop'); ?></option>
                        </select>
                        <span id="qilingshop-vip-discount-percent-wrap" class="qls-metabox-discount-percent-wrap<?php echo $vip_discount_mode === 'custom' ? '' : ' qls-metabox-hidden'; ?>">
                            <input type="number" name="qilingshop_vip_discount_percent" value="<?php echo esc_attr($vip_discount_percent !== '' ? $vip_discount_percent : 100); ?>" min="0" max="100" class="qls-metabox-input-100">
                            <span>%</span>
                        </span>
                    </div>
                    <div id="qilingshop-vip-level-discounts" class="qls-metabox-level-discounts<?php echo $vip_discount_mode === 'per_level' ? '' : ' qls-metabox-hidden'; ?>">
                        <table class="widefat qls-ui-table striped qls-metabox-level-table">
                            <thead>
                                <tr>
                                    <th><?php _e('VIP等级', 'qilingshop'); ?></th>
                                    <th><?php _e('折扣(%)', 'qilingshop'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($vip_levels as $level): ?>
                                    <?php $level_discount = isset($vip_level_discounts[$level->id]) ? (int) $vip_level_discounts[$level->id] : ''; ?>
                                    <tr>
                                        <td><?php echo esc_html($level->level_name); ?></td>
                                        <td>
                                            <input type="number" name="qilingshop_vip_level_discounts[<?php echo esc_attr($level->id); ?>]" value="<?php echo esc_attr($level_discount); ?>" min="0" max="100" class="qls-metabox-input-100">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p class="description qls-metabox-mt-6"><?php _e('留空则使用该等级默认折扣率', 'qilingshop'); ?></p>
                    </div>
                    <p class="description qls-metabox-mt-8"><?php _e('VIP免费优先于折扣。', 'qilingshop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('下载地址', 'qilingshop'); ?></th>
                <td>
                    <textarea name="qilingshop_download_urls" rows="5" class="large-text code qls-metabox-textarea-full" style="width:100%;"><?php echo esc_textarea($download_urls); ?></textarea>
                    <p class="description"><?php _e('支持站内路径、外部链接，以及名称、链接、提取码组合格式。示例：/wp-content/uploads/your-file.zip 或 百度网盘,https://pan.baidu.com/xxx,abc1', 'qilingshop'); ?></p>
                    <details class="qls-download-format-help">
                        <summary><?php _e('查看更多格式', 'qilingshop'); ?></summary>
                        <div class="qls-download-format-help-list">
                            <p><code>https://pan.baidu.com/xxx</code></p>
                            <p><code>https://pan.baidu.com/xxx,abc1</code></p>
                            <p><code>百度网盘,https://pan.baidu.com/xxx,abc1</code></p>
                            <p><code>百度网盘: https://pan.baidu.com/xxx,abc1</code></p>
                            <p><code>百度网盘: https://pan.baidu.com/xxx 提取码: abc1</code></p>
                            <p><code>百度网盘,https://pan.baidu.com/xxx 提取码: abc1</code></p>
                            <p><code>/wp-content/uploads/your-file.zip</code></p>
                            <p><code>模板文件,/wp-content/uploads/your-file.zip</code></p>
                        </div>
                    </details>
                </td>
            </tr>
            <tr>
                <th><?php _e('隐藏内容', 'qilingshop'); ?></th>
                <td>
                    <textarea name="qilingshop_hidden_content" rows="3" class="large-text code qls-metabox-textarea-full" style="width:100%;"><?php echo esc_textarea($hidden_content); ?></textarea>
                    <p class="description"><?php _e('购买后显示的额外内容（支持基础排版）', 'qilingshop'); ?></p>
                </td>
            </tr>
        </table>
        <div class="qilingshop-usage-guide qls-metabox-usage-guide">
            <p class="qls-metabox-usage-title"><?php _e('使用说明', 'qilingshop'); ?></p>
            <ol class="qls-metabox-usage-list">
                <li class="qls-metabox-usage-item"><?php _e('<strong>销售模式</strong>：决定资源展示方式。“付费下载”侧重文件下载，“付费查看”侧重隐藏内容展示。', 'qilingshop'); ?></li>
                <li class="qls-metabox-usage-item"><?php _e('<strong>价格设置</strong>：单位为站内积分（灵力）。设为 0 即为免费资源。', 'qilingshop'); ?></li>
                <li class="qls-metabox-usage-item"><?php _e('<strong>下载地址</strong>：支持多行输入。格式：显示名称,下载链接,提取码。', 'qilingshop'); ?></li>
                <li class="qls-metabox-usage-item"><?php _e('<strong>部分付费简码（推荐）</strong>：使用 [qls_content]内容[/qls_content]，未购买时会替换为购买框。', 'qilingshop'); ?></li>
                <li class="qls-metabox-usage-item"><?php _e('<strong>隐藏提示简码（兼容）</strong>：使用 [qilingshop_hidden]内容[/qilingshop_hidden]，未购买时主要显示提示文案。', 'qilingshop'); ?></li>
                <li class="qls-metabox-usage-item qls-metabox-usage-item-last"><?php _e('<strong>隐藏内容字段</strong>：仅在用户购买（或免费资源）后显示。适合放置解压密码、激活码等内容。', 'qilingshop'); ?></li>
            </ol>
        </div>
        <script>
        (function() {
            var mode = document.getElementById('qilingshop-vip-discount-mode');
            var percentWrap = document.getElementById('qilingshop-vip-discount-percent-wrap');
            var levelWrap = document.getElementById('qilingshop-vip-level-discounts');
            if (!mode) return;
            function sync() {
                if (percentWrap) {
                    percentWrap.classList.toggle('qls-metabox-hidden', mode.value !== 'custom');
                }
                if (levelWrap) {
                    levelWrap.classList.toggle('qls-metabox-hidden', mode.value !== 'per_level');
                }
            }
            mode.addEventListener('change', sync);
            sync();
        })();
        </script>
        <?php
    }

    public function save_meta($post_id, $post) {
        if (!isset($_POST['qilingshop_nonce']) || !wp_verify_nonce($_POST['qilingshop_nonce'], 'qilingshop_metabox')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (isset($_POST['has_qilingshop_points_resource_enabled'])) {
            $enabled = isset($_POST['qilingshop_points_resource_enabled']) ? 1 : 0;
            update_post_meta($post_id, '_qilingshop_points_resource_enabled', $enabled);
        }

        $fields = [
            'qilingshop_download_urls' => '_qilingshop_download_urls',
            'qilingshop_hidden_content'=> '_qilingshop_hidden_content',
            'qilingshop_sale_mode'     => '_qilingshop_sale_mode',
            'qilingshop_service_tags'  => '_qilingshop_service_tags',
        ];

        foreach ($fields as $field => $meta_key) {
            if (isset($_POST[$field])) {
                $value = $_POST[$field];
                if ($meta_key === '_qilingshop_hidden_content') {
                    // 允许安全的 HTML 内容
                    $value = wp_kses_post($value);
                } elseif ($meta_key === '_qilingshop_download_urls') {
                    $value = sanitize_textarea_field($value);
                } else {
                    $value = sanitize_text_field($value);
                }
                update_post_meta($post_id, $meta_key, $value);
            }
        }

        $price_download = isset($_POST['qilingshop_price_download']) ? floatval($_POST['qilingshop_price_download']) : 0;
        $price_view = isset($_POST['qilingshop_price_view']) ? floatval($_POST['qilingshop_price_view']) : 0;
        update_post_meta($post_id, '_qilingshop_price_download', $price_download);
        update_post_meta($post_id, '_qilingshop_price_view', $price_view);

        // 兼容旧字段：默认用下载价，下载价为空则回退查看价
        $legacy_price = $price_download > 0 ? $price_download : $price_view;
        update_post_meta($post_id, '_qilingshop_price', $legacy_price);

        $vip_min_level = isset($_POST['qilingshop_vip_min_level']) ? absint($_POST['qilingshop_vip_min_level']) : 1;
        update_post_meta($post_id, '_qilingshop_vip_min_level', $vip_min_level > 0 ? $vip_min_level : 1);

        $vip_only_purchase = isset($_POST['qilingshop_vip_only_purchase']) ? sanitize_key($_POST['qilingshop_vip_only_purchase']) : 'none';
        if (!in_array($vip_only_purchase, ['none', 'download', 'view', 'both'], true)) {
            $vip_only_purchase = 'none';
        }
        update_post_meta($post_id, '_qilingshop_vip_only_purchase', $vip_only_purchase);

        $vip_only_access = isset($_POST['qilingshop_vip_only_access']) ? sanitize_key($_POST['qilingshop_vip_only_access']) : 'none';
        if (!in_array($vip_only_access, ['none', 'download', 'view', 'both'], true)) {
            $vip_only_access = 'none';
        }
        update_post_meta($post_id, '_qilingshop_vip_only_access', $vip_only_access);
        update_post_meta($post_id, '_qilingshop_vip_only', $vip_only_purchase);

        $vip_free = isset($_POST['qilingshop_vip_free']) ? sanitize_key($_POST['qilingshop_vip_free']) : 'none';
        if (!in_array($vip_free, ['none', 'download', 'view', 'both'], true)) {
            $vip_free = 'none';
        }
        update_post_meta($post_id, '_qilingshop_vip_free', $vip_free);

        $vip_discount_mode = isset($_POST['qilingshop_vip_discount_mode']) ? sanitize_key($_POST['qilingshop_vip_discount_mode']) : 'inherit';
        if (!in_array($vip_discount_mode, ['inherit', 'none', 'custom', 'per_level'], true)) {
            $vip_discount_mode = 'inherit';
        }
        update_post_meta($post_id, '_qilingshop_vip_discount_mode', $vip_discount_mode);

        $vip_discount_percent = isset($_POST['qilingshop_vip_discount_percent']) ? absint($_POST['qilingshop_vip_discount_percent']) : 100;
        if ($vip_discount_percent > 100) {
            $vip_discount_percent = 100;
        }
        update_post_meta($post_id, '_qilingshop_vip_discount_percent', $vip_discount_percent);

        $level_discounts_input = $_POST['qilingshop_vip_level_discounts'] ?? [];
        $level_discounts = [];
        if (is_array($level_discounts_input)) {
            foreach ($level_discounts_input as $level_id => $discount) {
                $level_id = absint($level_id);
                if ($level_id <= 0) {
                    continue;
                }
                $discount = is_numeric($discount) ? absint($discount) : '';
                if ($discount === '' || $discount > 100) {
                    if ($discount > 100) {
                        $discount = 100;
                    } else {
                        continue;
                    }
                }
                $level_discounts[$level_id] = $discount;
            }
        }
        $level_discounts_json = !empty($level_discounts) ? wp_json_encode($level_discounts) : '';
        update_post_meta($post_id, '_qilingshop_vip_level_discounts', $level_discounts_json);

        // 兼容旧字段：根据新配置映射到 _qilingshop_vip_discount
        $legacy_discount = 'default';
        if ($vip_discount_mode === 'none') {
            $legacy_discount = 'none';
        } elseif ($vip_discount_mode === 'custom') {
            if ($vip_discount_percent <= 0) {
                $legacy_discount = 'vip_free';
            } else {
                $legacy_discount = 'vip_' . $vip_discount_percent;
            }
        } elseif ($vip_discount_mode === 'per_level') {
            $legacy_discount = 'default';
        } else {
            $legacy_discount = 'default';
        }
        if ($vip_free === 'both') {
            $legacy_discount = 'vip_free';
        }
        update_post_meta($post_id, '_qilingshop_vip_discount', $legacy_discount);

        if (class_exists('QilingShop_Resource')) {
            QilingShop_Resource::instance()->sync_resource_marker($post_id);
        }
    }
}
