<?php
/**
 * 投稿页面集成类
 * 在启灵主题投稿页面添加资源出售面板
 *
 * @package QilingShop
 * @since   1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Submit_Integration {

    /**
     * 单例实例
     */
    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // 仅在作者提成功能开启时才挂载钩子
        if (!get_option('qilingshop_author_commission_enabled', false)) {
            return;
        }

        // 渲染资源出售面板
        add_action('qiling_submit_post_extra_fields', [$this, 'render_sell_panel'], 10, 1);
        
        // 保存资源定价meta
        add_action('qiling_submit_post_saved', [$this, 'save_resource_meta'], 10, 3);
        
        // 加载样式
        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles'], 25);
    }

    /**
     * 加载样式
     */
    public function enqueue_styles() {
        if (!$this->is_submit_page()) {
            return;
        }

        wp_enqueue_style(
            'qilingshop-submit-integration',
            QILINGSHOP_URL . 'static/css/submit-integration.css',
            [],
            qilingshop_get_assets_version()
        );
    }

    /**
     * 检查是否在投稿页面
     */
    private function is_submit_page() {
        return is_page_template('templates/template-submit-post.php');
    }

    /**
     * 渲染资源出售面板
     * 
     * @param WP_Post|null $post 编辑模式下的文章对象
     */
    public function render_sell_panel($post = null) {
        // 获取当前值（编辑模式）
        $enable_sale = false;
        $price = '';
        $price_download = '';
        $price_view = '';
        $vip_discount = 'none';
        $vip_min_level = 1;
        $vip_only = 'none';
        $vip_only_access = 'none';
        $vip_free = 'none';
        $vip_discount_mode = '';
        $vip_discount_percent = 100;
        $sale_mode = 'download';
        $download_urls = '';
        $hidden_content = '';
        
        if ($post) {
            $enable_sale = get_post_meta($post->ID, '_qilingshop_price_download', true) > 0 
                        || get_post_meta($post->ID, '_qilingshop_price_view', true) > 0
                        || get_post_meta($post->ID, '_qilingshop_download_urls', true);
            $price = get_post_meta($post->ID, '_qilingshop_price', true);
            $price_download = get_post_meta($post->ID, '_qilingshop_price_download', true);
            $price_view = get_post_meta($post->ID, '_qilingshop_price_view', true);
            $vip_discount = get_post_meta($post->ID, '_qilingshop_vip_discount', true) ?: 'none';
            $vip_min_level = get_post_meta($post->ID, '_qilingshop_vip_min_level', true);
            $vip_only = get_post_meta($post->ID, '_qilingshop_vip_only_purchase', true);
            if ($vip_only === '') {
                $vip_only = get_post_meta($post->ID, '_qilingshop_vip_only', true) ?: 'none';
            }
            $vip_only_access = get_post_meta($post->ID, '_qilingshop_vip_only_access', true) ?: 'none';
            $vip_free = get_post_meta($post->ID, '_qilingshop_vip_free', true) ?: 'none';
            $vip_discount_mode = get_post_meta($post->ID, '_qilingshop_vip_discount_mode', true);
            $vip_discount_percent = get_post_meta($post->ID, '_qilingshop_vip_discount_percent', true);
            $sale_mode = get_post_meta($post->ID, '_qilingshop_sale_mode', true) ?: 'download';
            $download_urls = get_post_meta($post->ID, '_qilingshop_download_urls', true);
            $hidden_content = get_post_meta($post->ID, '_qilingshop_hidden_content', true);
        }
        if ($vip_min_level === '' || $vip_min_level <= 0) {
            $vip_min_level = 1;
        }
        if ($vip_discount_mode === '') {
            $legacy_setting = $vip_discount ?: get_option('qilingshop_default_vip_discount', 'none');
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

        // 获取设置
        $commission_rate = get_option('qilingshop_author_commission_rate', 80);
        $points_name = qilingshop_get_points_name();
        $vip_levels = QilingShop_VIP::instance()->get_levels();
        ?>
        <div class="form-group qls-sell-panel" id="qls-sell-panel">
            <label class="qls-sell-panel-header">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="1" x2="12" y2="23"></line>
                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                </svg>
                <?php esc_html_e('资源出售设置', 'qilingshop'); ?>
                <span class="qls-sell-badge"><?php printf(esc_html__('可获得 %s%% 销售提成', 'qilingshop'), esc_html($commission_rate)); ?></span>
            </label>
            
            <div class="qls-sell-panel-content">
                <div class="qls-sell-toggle">
                    <label class="qls-toggle-switch">
                        <input type="checkbox" name="qls_enable_sale" id="qls-enable-sale" value="1" <?php checked($enable_sale); ?>>
                        <span class="qls-toggle-slider"></span>
                    </label>
                    <span class="qls-toggle-label"><?php esc_html_e('启用资源出售', 'qilingshop'); ?></span>
                    <span class="qls-toggle-hint"><?php esc_html_e('开启后，其他用户需要付费才能查看或下载此资源', 'qilingshop'); ?></span>
                </div>
                
                <div class="qls-sell-options" id="qls-sell-options" style="<?php echo $enable_sale ? '' : 'display:none;'; ?>">
                    <!-- 销售模式 -->
                    <div class="qls-form-row">
                        <div class="qls-form-group qls-full-width">
                            <label for="qls-sale-mode"><?php esc_html_e('销售模式', 'qilingshop'); ?></label>
                            <select id="qls-sale-mode" name="qls_sale_mode">
                                <option value="download" <?php selected($sale_mode, 'download'); ?>><?php esc_html_e('付费下载', 'qilingshop'); ?></option>
                                <option value="view" <?php selected($sale_mode, 'view'); ?>><?php esc_html_e('付费查看', 'qilingshop'); ?></option>
                            </select>
                            <p class="qls-form-hint"><?php esc_html_e('付费下载：用户购买后可查看下载链接；付费查看：用户购买后可查看隐藏内容', 'qilingshop'); ?></p>
                        </div>
                    </div>
                    
                    <!-- 价格和VIP设置 -->
                    <div class="qls-form-row">
                        <div class="qls-form-group">
                            <label for="qls-price-download"><?php esc_html_e('下载价格', 'qilingshop'); ?></label>
                            <div class="qls-input-group">
                                <input type="number" 
                                       id="qls-price-download" 
                                       name="qls_price_download" 
                                       value="<?php echo esc_attr($price_download); ?>" 
                                       min="0" 
                                       step="1" 
                                       placeholder="0">
                                <span class="qls-input-suffix"><?php echo esc_html($points_name); ?></span>
                            </div>
                            <p class="qls-form-hint"><?php esc_html_e('付费下载使用该价格，下载授权包含查看', 'qilingshop'); ?></p>
                        </div>
                        
                        <div class="qls-form-group">
                            <label for="qls-price-view"><?php esc_html_e('查看价格', 'qilingshop'); ?></label>
                            <div class="qls-input-group">
                                <input type="number" 
                                       id="qls-price-view" 
                                       name="qls_price_view" 
                                       value="<?php echo esc_attr($price_view); ?>" 
                                       min="0" 
                                       step="1" 
                                       placeholder="0">
                                <span class="qls-input-suffix"><?php echo esc_html($points_name); ?></span>
                            </div>
                            <p class="qls-form-hint"><?php esc_html_e('付费查看使用该价格，仅查看不含下载；两价都填可补差价升级下载', 'qilingshop'); ?></p>
                        </div>
                        
                    </div>

                    <div class="qls-form-row">
                        <div class="qls-form-group">
                            <label for="qls-vip-min-level"><?php esc_html_e('VIP最低等级', 'qilingshop'); ?></label>
                            <select id="qls-vip-min-level" name="qls_vip_min_level">
                                <?php foreach ($vip_levels as $level): ?>
                                    <option value="<?php echo esc_attr($level->id); ?>" <?php selected((int)$vip_min_level, (int)$level->id); ?>><?php echo esc_html($level->level_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="qls-form-group">
                            <label for="qls-vip-only"><?php esc_html_e('仅限购买', 'qilingshop'); ?></label>
                            <select id="qls-vip-only" name="qls_vip_only">
                                <option value="none" <?php selected($vip_only, 'none'); ?>><?php esc_html_e('否', 'qilingshop'); ?></option>
                                <option value="download" <?php selected($vip_only, 'download'); ?>><?php esc_html_e('仅下载', 'qilingshop'); ?></option>
                                <option value="view" <?php selected($vip_only, 'view'); ?>><?php esc_html_e('仅查看', 'qilingshop'); ?></option>
                                <option value="both" <?php selected($vip_only, 'both'); ?>><?php esc_html_e('下载+查看', 'qilingshop'); ?></option>
                            </select>
                        </div>
                        <div class="qls-form-group">
                            <label for="qls-vip-only-access"><?php esc_html_e('仅限访问', 'qilingshop'); ?></label>
                            <select id="qls-vip-only-access" name="qls_vip_only_access">
                                <option value="none" <?php selected($vip_only_access, 'none'); ?>><?php esc_html_e('否', 'qilingshop'); ?></option>
                                <option value="download" <?php selected($vip_only_access, 'download'); ?>><?php esc_html_e('仅下载', 'qilingshop'); ?></option>
                                <option value="view" <?php selected($vip_only_access, 'view'); ?>><?php esc_html_e('仅查看', 'qilingshop'); ?></option>
                                <option value="both" <?php selected($vip_only_access, 'both'); ?>><?php esc_html_e('下载+查看', 'qilingshop'); ?></option>
                            </select>
                        </div>
                        <div class="qls-form-group">
                            <label for="qls-vip-free"><?php esc_html_e('VIP免费', 'qilingshop'); ?></label>
                            <select id="qls-vip-free" name="qls_vip_free">
                                <option value="none" <?php selected($vip_free, 'none'); ?>><?php esc_html_e('不启用', 'qilingshop'); ?></option>
                                <option value="download" <?php selected($vip_free, 'download'); ?>><?php esc_html_e('仅下载', 'qilingshop'); ?></option>
                                <option value="view" <?php selected($vip_free, 'view'); ?>><?php esc_html_e('仅查看', 'qilingshop'); ?></option>
                                <option value="both" <?php selected($vip_free, 'both'); ?>><?php esc_html_e('下载+查看', 'qilingshop'); ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="qls-form-row">
                        <div class="qls-form-group qls-full-width">
                            <p class="qls-form-hint"><?php esc_html_e('仅限购买：只限制新购买，已购用户仍可访问。仅限访问：强约束，已购用户也会被拦截。', 'qilingshop'); ?></p>
                        </div>
                    </div>

                    <div class="qls-form-row">
                        <div class="qls-form-group">
                            <label for="qls-vip-discount-mode"><?php esc_html_e('VIP折扣', 'qilingshop'); ?></label>
                            <select id="qls-vip-discount-mode" name="qls_vip_discount_mode">
                                <option value="inherit" <?php selected($vip_discount_mode, 'inherit'); ?>><?php esc_html_e('继承等级默认折扣', 'qilingshop'); ?></option>
                                <option value="none" <?php selected($vip_discount_mode, 'none'); ?>><?php esc_html_e('不参与VIP折扣', 'qilingshop'); ?></option>
                                <option value="custom" <?php selected($vip_discount_mode, 'custom'); ?>><?php esc_html_e('自定义折扣', 'qilingshop'); ?></option>
                            </select>
                            <div id="qls-vip-discount-percent-wrap" style="margin-top:6px;<?php echo $vip_discount_mode === 'custom' ? '' : 'display:none;'; ?>">
                                <input type="number" id="qls-vip-discount-percent" name="qls_vip_discount_percent" value="<?php echo esc_attr($vip_discount_percent !== '' ? $vip_discount_percent : 100); ?>" min="0" max="100" style="width:100px;">
                                <span>%</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 下载地址 -->
                    <div class="qls-form-row" id="qls-download-urls-row">
                        <div class="qls-form-group qls-full-width">
                            <label for="qls-download-urls">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: -3px; margin-right: 5px;">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                    <polyline points="7 10 12 15 17 10"></polyline>
                                    <line x1="12" y1="15" x2="12" y2="3"></line>
                                </svg>
                                <?php esc_html_e('下载地址', 'qilingshop'); ?>
                            </label>
                            <textarea id="qls-download-urls" 
                                      name="qls_download_urls" 
                                      rows="4" 
                                      placeholder="<?php echo esc_attr(sprintf("%s\n%s", __('每行一个，支持名称、链接和提取码的多种组合', 'qilingshop'), __('示例：百度网盘: https://pan.baidu.com/xxx 提取码: abc1', 'qilingshop'))); ?>"><?php echo esc_textarea($download_urls); ?></textarea>
                            <p class="qls-form-hint">
                                <?php echo wp_kses(__('支持格式：<code>仅链接</code>、<code>链接,提取码</code>、<code>名称,链接,提取码</code>、<code>名称: 链接 提取码: 密码</code>', 'qilingshop'), ['code' => []]); ?>
                            </p>
                        </div>
                    </div>
                    
                    <!-- 隐藏内容 -->
                    <div class="qls-form-row" id="qls-hidden-content-row">
                        <div class="qls-form-group qls-full-width">
                            <label for="qls-hidden-content">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: -3px; margin-right: 5px;">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                </svg>
                                <?php esc_html_e('隐藏内容（可选）', 'qilingshop'); ?>
                            </label>
                            <textarea id="qls-hidden-content" 
                                      name="qls_hidden_content" 
                                      rows="3" 
                                      placeholder="<?php esc_attr_e('用户购买后显示的额外内容，支持基础排版', 'qilingshop'); ?>"><?php echo esc_textarea($hidden_content); ?></textarea>
                            <p class="qls-form-hint"><?php esc_html_e('购买后显示的额外内容，可填写密码、教程说明等', 'qilingshop'); ?></p>
                        </div>
                    </div>
                    
                    <div class="qls-commission-info">
                        <div class="qls-commission-icon">💰</div>
                        <div class="qls-commission-text">
                            <strong><?php esc_html_e('销售提成说明', 'qilingshop'); ?></strong>
                            <p><?php printf(wp_kses(__('当有用户购买您的资源时，您将获得销售额的 <strong>%s%%</strong> 作为提成，提成将自动计入您的可提现余额。', 'qilingshop'), ['strong' => []]), esc_html($commission_rate)); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <script>
        (function() {
            var enableSaleCheckbox = document.getElementById('qls-enable-sale');
            var sellOptions = document.getElementById('qls-sell-options');
            var saleModeSelect = document.getElementById('qls-sale-mode');
            var downloadUrlsRow = document.getElementById('qls-download-urls-row');
            var vipDiscountMode = document.getElementById('qls-vip-discount-mode');
            var vipDiscountPercentWrap = document.getElementById('qls-vip-discount-percent-wrap');
            
            if (enableSaleCheckbox && sellOptions) {
                enableSaleCheckbox.addEventListener('change', function() {
                    sellOptions.style.display = this.checked ? 'block' : 'none';
                });
            }
            
            // 根据销售模式显示/隐藏下载地址（付费查看模式时下载地址非必填但仍可用）
            function updateFieldsVisibility() {
                // 下载地址在两种模式下都显示，但提示不同
            }
            
            if (saleModeSelect) {
                saleModeSelect.addEventListener('change', updateFieldsVisibility);
                updateFieldsVisibility();
            }

            if (vipDiscountMode && vipDiscountPercentWrap) {
                var syncVipDiscount = function() {
                    vipDiscountPercentWrap.style.display = (vipDiscountMode.value === 'custom') ? 'block' : 'none';
                };
                vipDiscountMode.addEventListener('change', syncVipDiscount);
                syncVipDiscount();
            }
        })();
        </script>
        <?php
    }

    /**
     * 保存资源定价meta
     * 
     * @param int   $post_id   文章ID
     * @param array $post_data 表单数据
     * @param bool  $is_update 是否为更新
     */
    public function save_resource_meta($post_id, $post_data, $is_update) {
        // 检查是否启用出售
        $enable_sale = isset($post_data['qls_enable_sale']) && $post_data['qls_enable_sale'] == '1';
        
        if ($enable_sale) {
            // 保存销售模式
            $sale_mode = isset($post_data['qls_sale_mode']) ? sanitize_key($post_data['qls_sale_mode']) : 'download';
            if (!in_array($sale_mode, ['download', 'view'])) {
                $sale_mode = 'download';
            }
            update_post_meta($post_id, '_qilingshop_sale_mode', $sale_mode);
            
            // 保存价格
            $price_download = isset($post_data['qls_price_download']) ? absint($post_data['qls_price_download']) : 0;
            $price_view = isset($post_data['qls_price_view']) ? absint($post_data['qls_price_view']) : 0;
            update_post_meta($post_id, '_qilingshop_price_download', $price_download);
            update_post_meta($post_id, '_qilingshop_price_view', $price_view);

            $legacy_price = $price_download > 0 ? $price_download : $price_view;
            update_post_meta($post_id, '_qilingshop_price', $legacy_price);
            
            $vip_min_level = isset($post_data['qls_vip_min_level']) ? absint($post_data['qls_vip_min_level']) : 1;
            update_post_meta($post_id, '_qilingshop_vip_min_level', $vip_min_level > 0 ? $vip_min_level : 1);

            $vip_only = isset($post_data['qls_vip_only']) ? sanitize_key($post_data['qls_vip_only']) : 'none';
            if (!in_array($vip_only, ['none', 'download', 'view', 'both'], true)) {
                $vip_only = 'none';
            }
            update_post_meta($post_id, '_qilingshop_vip_only_purchase', $vip_only);

            $vip_only_access = isset($post_data['qls_vip_only_access']) ? sanitize_key($post_data['qls_vip_only_access']) : 'none';
            if (!in_array($vip_only_access, ['none', 'download', 'view', 'both'], true)) {
                $vip_only_access = 'none';
            }
            update_post_meta($post_id, '_qilingshop_vip_only_access', $vip_only_access);
            update_post_meta($post_id, '_qilingshop_vip_only', $vip_only);

            $vip_free = isset($post_data['qls_vip_free']) ? sanitize_key($post_data['qls_vip_free']) : 'none';
            if (!in_array($vip_free, ['none', 'download', 'view', 'both'], true)) {
                $vip_free = 'none';
            }
            update_post_meta($post_id, '_qilingshop_vip_free', $vip_free);

            $vip_discount_mode = isset($post_data['qls_vip_discount_mode']) ? sanitize_key($post_data['qls_vip_discount_mode']) : 'inherit';
            if (!in_array($vip_discount_mode, ['inherit', 'none', 'custom'], true)) {
                $vip_discount_mode = 'inherit';
            }
            update_post_meta($post_id, '_qilingshop_vip_discount_mode', $vip_discount_mode);

            $vip_discount_percent = isset($post_data['qls_vip_discount_percent']) ? absint($post_data['qls_vip_discount_percent']) : 100;
            if ($vip_discount_percent > 100) {
                $vip_discount_percent = 100;
            }
            update_post_meta($post_id, '_qilingshop_vip_discount_percent', $vip_discount_percent);

            // 兼容旧字段
            $legacy_discount = 'default';
            if ($vip_discount_mode === 'none') {
                $legacy_discount = 'none';
            } elseif ($vip_discount_mode === 'custom') {
                if ($vip_discount_percent <= 0) {
                    $legacy_discount = 'vip_free';
                } else {
                    $legacy_discount = 'vip_' . $vip_discount_percent;
                }
            } else {
                $legacy_discount = 'default';
            }
            if ($vip_free === 'both') {
                $legacy_discount = 'vip_free';
            }
            update_post_meta($post_id, '_qilingshop_vip_discount', $legacy_discount);
            
            // 保存下载地址
            $download_urls = isset($post_data['qls_download_urls']) ? sanitize_textarea_field($post_data['qls_download_urls']) : '';
            update_post_meta($post_id, '_qilingshop_download_urls', $download_urls);
            
            // 保存隐藏内容（允许安全的HTML）
            $hidden_content = isset($post_data['qls_hidden_content']) ? wp_kses_post($post_data['qls_hidden_content']) : '';
            update_post_meta($post_id, '_qilingshop_hidden_content', $hidden_content);
            
            // 标记为付费资源
            update_post_meta($post_id, '_qilingshop_is_paid', '1');
        } else {
            // 清除付费设置
            delete_post_meta($post_id, '_qilingshop_price');
            delete_post_meta($post_id, '_qilingshop_price_download');
            delete_post_meta($post_id, '_qilingshop_price_view');
            delete_post_meta($post_id, '_qilingshop_vip_discount');
            delete_post_meta($post_id, '_qilingshop_is_paid');
            delete_post_meta($post_id, '_qilingshop_sale_mode');
            delete_post_meta($post_id, '_qilingshop_download_urls');
            delete_post_meta($post_id, '_qilingshop_hidden_content');
            delete_post_meta($post_id, '_qilingshop_vip_min_level');
            delete_post_meta($post_id, '_qilingshop_vip_only');
            delete_post_meta($post_id, '_qilingshop_vip_only_purchase');
            delete_post_meta($post_id, '_qilingshop_vip_only_access');
            delete_post_meta($post_id, '_qilingshop_vip_free');
            delete_post_meta($post_id, '_qilingshop_vip_discount_mode');
            delete_post_meta($post_id, '_qilingshop_vip_discount_percent');
            delete_post_meta($post_id, '_qilingshop_vip_level_discounts');
        }

        if (class_exists('QilingShop_Resource')) {
            QilingShop_Resource::instance()->sync_resource_marker($post_id);
        }
    }
}
