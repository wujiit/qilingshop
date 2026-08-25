<?php
/**
 * 商城设置视图
 */
if (!defined('ABSPATH')) exit;

$tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general';
$tabs = [
    'general'  => __('基础设置', 'qilingshop'),
    'pages'    => __('页面配置', 'qilingshop'),
    'shipping' => __('运费规则', 'qilingshop'),
    'service'  => __('服务标签', 'qilingshop'),
    'params'   => __('参数模板', 'qilingshop'),
    'express'  => __('快递物流', 'qilingshop'),
    'review'   => __('评价设置', 'qilingshop'),
    'service_showcase' => __('服务展示', 'qilingshop'),
];

$db = QLS_Shop_Database::instance();
$notice_key = isset($_GET['message']) ? sanitize_key(wp_unslash($_GET['message'])) : '';
$notice_map = [
    'saved'               => ['type' => 'success', 'text' => __('设置已保存', 'qilingshop')],
    'deleted'             => ['type' => 'success', 'text' => __('已删除', 'qilingshop')],
    'save_failed'         => ['type' => 'error', 'text' => __('保存失败，请检查填写内容', 'qilingshop')],
    'delete_failed'       => ['type' => 'error', 'text' => __('删除失败', 'qilingshop')],
    'invalid_nonce'       => ['type' => 'error', 'text' => __('安全校验失败，请刷新后重试', 'qilingshop')],
    'invalid_action'      => ['type' => 'error', 'text' => __('操作无效', 'qilingshop')],
    'missing_id'          => ['type' => 'error', 'text' => __('请选择要操作的项目', 'qilingshop')],
    'service_unavailable' => ['type' => 'error', 'text' => __('服务不可用，请检查相关模块是否正常加载', 'qilingshop')],
];
$notice = isset($notice_map[$notice_key]) ? $notice_map[$notice_key] : null;
?>
<div class="wrap qilingshop-admin-page qls-shop-wrap">
    <div class="qls-page-header">
        <h1 class="qls-page-title"><?php _e('商城设置', 'qilingshop'); ?></h1>
    </div>
    
    <?php if ($notice): ?>
    <div class="qls-admin-message qls-admin-message-<?php echo esc_attr($notice['type']); ?>">
        <p><?php echo esc_html($notice['text']); ?></p>
    </div>
    <?php endif; ?>
    
    <nav class="nav-tab-wrapper qls-ui-tabs">
        <?php foreach ($tabs as $key => $label): ?>
        <a href="<?php echo admin_url('admin.php?page=qls-shop-settings&tab=' . $key); ?>" 
           class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>">
            <?php echo esc_html($label); ?>
        </a>
        <?php endforeach; ?>
    </nav>
    
    <div class="qls-settings-content">
        <?php if ($tab === 'general'): ?>
        <!-- 基础设置 -->
        <form method="post">
            <?php wp_nonce_field('qls_save_shop_settings', 'qls_shop_settings_nonce'); ?>
            
            <table class="form-table qls-ui-form-table">
                <tr>
                    <th><label><?php _e('启用商城', 'qilingshop'); ?></label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="enabled" value="1" <?php checked(get_option('qls_shop_enabled', true)); ?>>
                            <?php _e('启用实物商城功能', 'qilingshop'); ?>
                        </label>
                    </td>
                </tr>
	                <tr>
	                    <th><label for="name"><?php _e('商城名称', 'qilingshop'); ?></label></th>
	                    <td>
	                        <input type="text" name="name" id="name" class="regular-text" 
	                               value="<?php echo esc_attr(get_option('qls_shop_name', __('实物商城', 'qilingshop'))); ?>">
	                    </td>
	                </tr>
                <tr>
                    <th><label><?php _e('启用发卡首页', 'qilingshop'); ?></label></th>
                    <td>
                        <?php
                        $virtual_home_enabled = get_option('qls_shop_virtual_home_enabled', '__qls_missing__');
                        if ($virtual_home_enabled === '__qls_missing__') {
                            $virtual_home_enabled = sanitize_key((string) get_option('qls_shop_home_mode', 'decoration')) === 'virtual_card';
                        }
                        ?>
                        <label>
                            <input type="checkbox" name="virtual_home_enabled" value="1" <?php checked((bool) $virtual_home_enabled); ?>>
                            <?php _e('启用独立虚拟发卡首页', 'qilingshop'); ?>
                        </label>
                        <p class="description"><?php _e('常规商城首页继续使用 [qls_shop] 和页面装修；发卡首页请使用 [qls_shop_virtual_home]。关闭后，专用发卡首页前台将不可访问。', 'qilingshop'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="virtual_home_style"><?php _e('发卡首页风格', 'qilingshop'); ?></label></th>
                    <td>
                        <?php
                        $virtual_home_styles = function_exists('qilingshop_get_virtual_home_styles') ? qilingshop_get_virtual_home_styles() : [
                            'compact' => ['label' => __('清爽列表', 'qilingshop')],
                            'grid'    => ['label' => __('精选橱窗', 'qilingshop')],
                        ];
                        $virtual_home_style = sanitize_key((string) get_option('qls_shop_virtual_home_style', 'compact'));
                        if (!isset($virtual_home_styles[$virtual_home_style])) {
                            $style_keys = array_keys($virtual_home_styles);
                            $virtual_home_style = isset($virtual_home_styles['compact']) ? 'compact' : (string) reset($style_keys);
                        }
                        ?>
                        <select name="virtual_home_style" id="virtual_home_style">
                            <?php foreach ($virtual_home_styles as $style_key => $style_config): ?>
                                <?php
                                $style_key = sanitize_key((string) $style_key);
                                if ($style_key === '') {
                                    continue;
                                }
                                $style_label = isset($style_config['label']) ? (string) $style_config['label'] : $style_key;
                                ?>
                                <option value="<?php echo esc_attr($style_key); ?>" <?php selected($virtual_home_style, $style_key); ?>><?php echo esc_html($style_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php _e('主题或子主题可通过 qls_shop_virtual_home_styles 注册新风格，并在 qilingshop/shop/virtual-home/ 下提供模板。', 'qilingshop'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="virtual_home_limit"><?php _e('发卡首页商品数', 'qilingshop'); ?></label></th>
                    <td>
                        <input type="number" name="virtual_home_limit" id="virtual_home_limit" class="small-text" min="4" max="60"
                               value="<?php echo esc_attr((int) get_option('qls_shop_virtual_home_limit', 24)); ?>">
                        <p class="description"><?php _e('建议控制在 12-36 个，前台会使用商城商品缓存。', 'qilingshop'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="virtual_home_title"><?php _e('发卡首页标题', 'qilingshop'); ?></label></th>
                    <td>
                        <input type="text" name="virtual_home_title" id="virtual_home_title" class="regular-text"
                               value="<?php echo esc_attr(get_option('qls_shop_virtual_home_title', __('虚拟发卡', 'qilingshop'))); ?>">
                    </td>
                </tr>
                <tr>
                    <th><label for="virtual_home_subtitle"><?php _e('发卡首页副标题', 'qilingshop'); ?></label></th>
                    <td>
                        <input type="text" name="virtual_home_subtitle" id="virtual_home_subtitle" class="large-text"
                               value="<?php echo esc_attr(get_option('qls_shop_virtual_home_subtitle', __('自动发货，支付后查看卡密信息', 'qilingshop'))); ?>">
                    </td>
                </tr>
	                <tr>
	                    <th><label for="qilingshop_locale"><?php _e('商城语言', 'qilingshop'); ?></label></th>
	                    <td>
	                        <?php $qilingshop_locale = (string) get_option('qilingshop_locale', ''); ?>
	                        <select name="qilingshop_locale" id="qilingshop_locale">
	                            <option value="" <?php selected($qilingshop_locale, ''); ?>><?php _e('跟随 WordPress 当前语言', 'qilingshop'); ?></option>
	                            <option value="zh_CN" <?php selected($qilingshop_locale, 'zh_CN'); ?>><?php _e('简体中文', 'qilingshop'); ?></option>
	                            <option value="en_US" <?php selected($qilingshop_locale, 'en_US'); ?>><?php _e('English', 'qilingshop'); ?></option>
	                        </select>
	                        <p class="description"><?php _e('只影响启灵积分商城插件固定文案，不会改变网站主题或其他插件语言。', 'qilingshop'); ?></p>
	                    </td>
	                </tr>
	                <tr>
	                    <th><label for="header_cart_icon"><?php _e('顶部购物车图标', 'qilingshop'); ?></label></th>
	                    <td>
	                        <input type="text" name="header_cart_icon" id="header_cart_icon" class="regular-text" 
                               value="<?php echo esc_attr(get_option('qls_shop_header_cart_icon', '')); ?>" placeholder="<?php _e('输入表情符号（🛒）或启灵阿里图标（如 icon-cart）', 'qilingshop'); ?>">
                        <p class="description"><?php _e('自定义顶部菜单购物车图标。支持表情符号、HTML 图标片段、启灵阿里图标 icon-xxx，留空显示默认图标。', 'qilingshop'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label><?php _e('网站顶部不显示购物车图标', 'qilingshop'); ?></label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="header_hide_cart_icon" value="1" <?php checked(get_option('qls_shop_header_hide_cart_icon', false)); ?>>
                            <?php _e('勾选后网站顶部将不再显示购物车图标', 'qilingshop'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label><?php _e('显示销量', 'qilingshop'); ?></label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="show_sales" value="1" <?php checked(get_option('qls_shop_show_sales', true)); ?>>
                            <?php _e('在前台显示商品销量', 'qilingshop'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label><?php _e('显示库存', 'qilingshop'); ?></label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="show_stock" value="1" <?php checked(get_option('qls_shop_show_stock', true)); ?>>
                            <?php _e('在前台显示商品库存', 'qilingshop'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label><?php _e('商品价格显示', 'qilingshop'); ?></label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="price_login_required" value="1" <?php checked(get_option('qls_shop_price_login_required', false)); ?>>
                            <?php _e('登录后显示商城商品价格', 'qilingshop'); ?>
                        </label>
                        <p class="description"><?php _e('开启后，实物商品列表、商品详情和虚拟卡密商城列表中的价格仅登录用户可见。', 'qilingshop'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label><?php _e('启用发票功能', 'qilingshop'); ?></label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="invoice_enabled" value="1" <?php checked(get_option('qls_shop_invoice_enabled', true)); ?>>
                            <?php _e('允许用户在商城订单中申请发票，并在商城中心管理常用发票信息', 'qilingshop'); ?>
                        </label>
                        <p class="description"><?php _e('关闭后前台隐藏发票申请入口和发票信息管理入口；已提交的发票记录仍可在后台发票管理中处理。', 'qilingshop'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="product_base"><?php _e('商品链接前缀', 'qilingshop'); ?></label></th>
                    <td>
                        <input type="text" name="product_base" id="product_base" class="regular-text" 
                               value="<?php echo esc_attr(get_option('qls_shop_product_base', 'shop/product')); ?>">
                        <p class="description"><?php _e('商品详情页链接前缀，如：shop/product', 'qilingshop'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="category_base"><?php _e('分类链接前缀', 'qilingshop'); ?></label></th>
                    <td>
                        <input type="text" name="category_base" id="category_base" class="regular-text" 
                               value="<?php echo esc_attr(get_option('qls_shop_category_base', 'shop/category')); ?>">
                    </td>
                </tr>
                <tr>
                    <?php
                    $guest_order_enabled = function_exists('qls_shop_is_guest_order_enabled')
                        ? qls_shop_is_guest_order_enabled()
                        : (bool) get_option('qls_shop_cart_guest_enabled', true);
                    ?>
                    <th><label><?php _e('允许游客下单', 'qilingshop'); ?></label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="guest_order_enabled" value="1" <?php checked($guest_order_enabled); ?>>
                            <?php _e('开启后未登录用户可加入购物车并提交订单，支付后可通过订单查询页查单', 'qilingshop'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label><?php _e('发卡订单查询密码', 'qilingshop'); ?></label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="guest_query_password_enabled" value="1" <?php checked(get_option('qls_shop_guest_query_password_enabled', false)); ?>>
                            <?php _e('开启后游客购买卡号卡密类虚拟商品时需设置查询密码，可在订单查询页用手机号/邮箱 + 查询密码查单', 'qilingshop'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label for="guest_query_password_expire_days"><?php _e('查询密码有效期', 'qilingshop'); ?></label></th>
                    <td>
                        <input type="number" name="guest_query_password_expire_days" id="guest_query_password_expire_days" class="small-text" min="1" max="365"
                               value="<?php echo esc_attr(get_option('qls_shop_guest_query_password_expire_days', 30)); ?>">
                        <?php _e('天后失效，仅影响游客发卡订单的手机号/邮箱 + 查询密码查询方式', 'qilingshop'); ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="order_auto_complete_days"><?php _e('自动确认收货', 'qilingshop'); ?></label></th>
                    <td>
                        <input type="number" name="order_auto_complete_days" id="order_auto_complete_days" class="small-text" min="1" 
                               value="<?php echo esc_attr(get_option('qls_shop_order_auto_complete_days', 15)); ?>">
                        <?php _e('天后自动确认收货', 'qilingshop'); ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="order_auto_cancel_hours"><?php _e('自动取消订单', 'qilingshop'); ?></label></th>
                    <td>
                        <input type="number" name="order_auto_cancel_hours" id="order_auto_cancel_hours" class="small-text" min="1" 
                               value="<?php echo esc_attr(get_option('qls_shop_order_auto_cancel_hours', 24)); ?>">
                        <?php _e('小时未付款自动取消', 'qilingshop'); ?>
                    </td>
                </tr>
                <tr>
                    <th><label><?php _e('库存扣减时机', 'qilingshop'); ?></label></th>
                    <td>
                        <select name="stock_reduce_on">
                            <option value="order" <?php selected(get_option('qls_shop_stock_reduce_on', 'order'), 'order'); ?>><?php _e('下单时扣减', 'qilingshop'); ?></option>
                            <option value="payment" <?php selected(get_option('qls_shop_stock_reduce_on', 'order'), 'payment'); ?>><?php _e('付款时扣减', 'qilingshop'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="low_stock_threshold"><?php _e('低库存阈值', 'qilingshop'); ?></label></th>
                    <td>
                        <input type="number" name="low_stock_threshold" id="low_stock_threshold" class="small-text" min="0" 
                               value="<?php echo esc_attr(get_option('qls_shop_low_stock_threshold', 5)); ?>">
                        <p class="description"><?php _e('库存低于此值时显示警告', 'qilingshop'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label><?php _e('积分支付', 'qilingshop'); ?></label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="points_enabled" value="1" <?php checked(get_option('qls_shop_points_enabled', true)); ?>>
                            <?php _e('允许使用积分支付', 'qilingshop'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label for="points_rate"><?php _e('积分兑换比例', 'qilingshop'); ?></label></th>
                    <td>
                        <input type="number" name="points_rate" id="points_rate" class="small-text" min="1" 
                               value="<?php echo esc_attr(get_option('qls_shop_points_rate', 10)); ?>">
                        <?php _e('积分 = 1 元', 'qilingshop'); ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="group_cron_key"><?php _e('团购兼容 Key（旧监控）', 'qilingshop'); ?></label></th>
                    <td>
                        <?php $group_cron_key = get_option('qls_group_cron_key', ''); ?>
                        <input type="text" name="group_cron_key" id="group_cron_key" class="regular-text" 
                               value="<?php echo esc_attr($group_cron_key); ?>">
                        <?php if (!empty($group_cron_key)) : ?>
                            <?php $group_cron_url = home_url('/wp-json/qilingshop/v1/group/cron/check-expire?key=' . $group_cron_key); ?>
                            <p class="description"><?php _e('团购监控完整地址：', 'qilingshop'); ?></p>
                            <div class="qls-inline-actions">
                                <input type="text" id="group_cron_url" class="regular-text" readonly value="<?php echo esc_attr($group_cron_url); ?>">
                                <button type="button" class="button qls-copy-btn" data-target="group_cron_url" onclick="return qlsShopCopyText('group_cron_url', this);"><?php _e('复制地址', 'qilingshop'); ?></button>
                            </div>
                            <?php
                            $task_url = '';
                            if (class_exists('QilingShop_Task_Center')) {
                                $task_url = QilingShop_Task_Center::instance()->get_task_check_url();
                            }
                            ?>
                            <p class="description"><?php _e('建议优先使用“插件设置 -> 基础设置 -> 任务中心外部触发”中的统一入口（建议每 5 分钟访问一次）。', 'qilingshop'); ?></p>
                            <?php if (!empty($task_url)) : ?>
                                <p class="description"><?php _e('统一入口：', 'qilingshop'); ?></p>
                                <div class="qls-inline-actions">
                                    <input type="text" id="qls_group_unified_task_url" class="regular-text" readonly value="<?php echo esc_attr($task_url); ?>">
                                    <button type="button" class="button qls-copy-btn" data-target="qls_group_unified_task_url" onclick="return qlsShopCopyText('qls_group_unified_task_url', this);"><?php _e('复制地址', 'qilingshop'); ?></button>
                                </div>
                            <?php endif; ?>
                            <p class="description"><?php _e('统一入口会自动按任务节流：团购 5 分钟、订单/助力 10 分钟、积分/VIP/游客 1 小时、生日券 6 小时。', 'qilingshop'); ?></p>
                            <p class="description"><?php _e('监控策略建议：HTTP 超时 10~15 秒，失败重试 1 次（1 分钟后），连续失败再告警。', 'qilingshop'); ?></p>
                            <p class="description"><?php _e('推荐监控工具：Uptime Kuma、cron-job.org、宝塔计划任务、云监控 HTTP 探测。', 'qilingshop'); ?></p>
                            <p class="description"><?php _e('当前团购专用地址仅保留用于兼容旧监控，非必填。新部署请只用统一入口。', 'qilingshop'); ?></p>
                            <script>
                                if (typeof window.qlsShopCopyText !== 'function') {
                                    window.qlsShopCopyText = function (targetId, btn) {
                                        var target = document.getElementById(targetId);
                                        if (!target) return false;
                                        var text = target.value || target.textContent || '';
                                        var originalText = btn && btn.textContent ? btn.textContent : '';

                                        var done = function () {
                                            if (!btn) return;
                                            btn.textContent = '✓';
                                            setTimeout(function () { btn.textContent = originalText; }, 1500);
                                        };

                                        var fail = function () {
                                            if (!btn) return;
                                            btn.textContent = '×';
                                            setTimeout(function () { btn.textContent = originalText; }, 1500);
                                        };

                                        if (navigator.clipboard && window.isSecureContext) {
                                            navigator.clipboard.writeText(text).then(done).catch(fail);
                                            return false;
                                        }

                                        var temp = document.createElement('textarea');
                                        temp.value = text;
                                        document.body.appendChild(temp);
                                        temp.select();
                                        try {
                                            document.execCommand('copy');
                                            done();
                                        } catch (e) {
                                            fail();
                                        }
                                        document.body.removeChild(temp);
                                        return false;
                                    };
                                }
                            </script>
                        <?php else : ?>
                            <p class="description"><?php _e('仅当需要兼容旧监控地址时再填写该 Key；新部署建议只配置统一入口。', 'qilingshop'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <button type="submit" class="button button-primary"><?php _e('保存设置', 'qilingshop'); ?></button>
            </p>
        </form>
        
        <?php elseif ($tab === 'pages'): ?>
        <!-- 页面配置 -->
        <form method="post">
            <?php wp_nonce_field('qls_save_shop_settings', 'qls_shop_settings_nonce'); ?>
            <input type="hidden" name="settings_tab" value="pages">
            
            <div class="qls-pages-info">
                <h3><?php _e('可用短代码', 'qilingshop'); ?></h3>
                <p class="description"><?php _e('创建页面并添加对应的短代码即可使用相关功能：', 'qilingshop'); ?></p>
                
                <table class="wp-list-table qls-ui-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th class="qls-w-150"><?php _e('页面', 'qilingshop'); ?></th>
                            <th class="qls-w-200"><?php _e('短代码', 'qilingshop'); ?></th>
                            <th><?php _e('说明', 'qilingshop'); ?></th>
                            <th class="qls-w-200"><?php _e('关联页面', 'qilingshop'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong><?php _e('商城首页', 'qilingshop'); ?></strong></td>
                            <td><code>[qls_shop]</code></td>
                            <td><?php _e('显示商城首页，包含分类导航和商品列表', 'qilingshop'); ?></td>
                            <td>
                                <?php wp_dropdown_pages([
                                    'name' => 'page_shop',
                                    'show_option_none' => __('— 选择页面 —', 'qilingshop'),
                                    'option_none_value' => 0,
                                    'selected' => get_option('qls_shop_page_shop', 0),
                                ]); ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('虚拟发卡首页', 'qilingshop'); ?></strong></td>
                            <td><code>[qls_shop_virtual_home]</code></td>
                            <td><?php _e('独立展示实物商城中的卡号卡密类虚拟商品，需在基础设置启用后访问', 'qilingshop'); ?></td>
                            <td>
                                <?php wp_dropdown_pages([
                                    'name' => 'page_virtual_home',
                                    'show_option_none' => __('— 选择页面 —', 'qilingshop'),
                                    'option_none_value' => 0,
                                    'selected' => get_option('qls_shop_page_virtual_home', 0),
                                ]); ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('购物车', 'qilingshop'); ?></strong></td>
                            <td><code>[qls_cart]</code></td>
                            <td><?php _e('购物车页面，展示已添加的商品', 'qilingshop'); ?></td>
                            <td>
                                <?php wp_dropdown_pages([
                                    'name' => 'page_cart',
                                    'show_option_none' => __('— 选择页面 —', 'qilingshop'),
                                    'option_none_value' => 0,
                                    'selected' => get_option('qls_shop_page_cart', 0),
                                ]); ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('结账页面', 'qilingshop'); ?></strong></td>
                            <td><code>[qls_checkout]</code></td>
                            <td><?php _e('填写收货地址并提交订单', 'qilingshop'); ?></td>
                            <td>
                                <?php wp_dropdown_pages([
                                    'name' => 'page_checkout',
                                    'show_option_none' => __('— 选择页面 —', 'qilingshop'),
                                    'option_none_value' => 0,
                                    'selected' => get_option('qls_shop_page_checkout', 0),
                                ]); ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('全部商品', 'qilingshop'); ?></strong></td>
                            <td><code>[qls_all_products]</code></td>
                            <td><?php _e('全部商品分类页面', 'qilingshop'); ?></td>
                            <td>
                                <?php wp_dropdown_pages([
                                    'name' => 'page_all_products',
                                    'show_option_none' => __('— 选择页面 —', 'qilingshop'),
                                    'option_none_value' => 0,
                                    'selected' => get_option('qls_shop_page_all_products', 0),
                                ]); ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('新人专区', 'qilingshop'); ?></strong></td>
                            <td><code>[qls_new_user_zone]</code></td>
                            <td><?php _e('展示开启新人专项价的商品，支持首单专享引流', 'qilingshop'); ?></td>
                            <td>
                                <?php wp_dropdown_pages([
                                    'name' => 'page_new_user_zone',
                                    'show_option_none' => __('— 选择页面 —', 'qilingshop'),
                                    'option_none_value' => 0,
                                    'selected' => get_option('qls_shop_page_new_user_zone', 0),
                                ]); ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('我的订单', 'qilingshop'); ?></strong></td>
                            <td><code>[qls_my_orders]</code></td>
                            <td><?php _e('用户查看自己的订单列表(有商城中心就可以不用)', 'qilingshop'); ?></td>
                            <td>
                                <?php wp_dropdown_pages([
                                    'name' => 'page_orders',
                                    'show_option_none' => __('— 选择页面 —', 'qilingshop'),
                                    'option_none_value' => 0,
                                    'selected' => get_option('qls_shop_page_orders', 0),
                                ]); ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('商城中心', 'qilingshop'); ?></strong></td>
                            <td><code>[qls_shop_center]</code></td>
                            <td><?php _e('用户个人中心，包含订单、消费、地址管理', 'qilingshop'); ?></td>
                            <td>
                                <?php wp_dropdown_pages([
                                    'name' => 'page_center',
                                    'show_option_none' => __('— 选择页面 —', 'qilingshop'),
                                    'option_none_value' => 0,
                                    'selected' => get_option('qls_shop_page_center', 0),
                                ]); ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('优惠券中心', 'qilingshop'); ?></strong></td>
                            <td><code>[qls_coupon_center]</code></td>
                            <td><?php _e('显示所有可领取的优惠券列表', 'qilingshop'); ?></td>
                            <td>
                                <?php wp_dropdown_pages([
                                    'name' => 'page_coupon_center',
                                    'show_option_none' => __('— 选择页面 —', 'qilingshop'),
                                    'option_none_value' => 0,
                                    'selected' => get_option('qls_shop_page_coupon_center', 0),
                                ]); ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('团购中心', 'qilingshop'); ?></strong></td>
                            <td><code>[qls_group_center]</code></td>
                            <td><?php _e('团购商品列表，展示所有开启团购的商品', 'qilingshop'); ?></td>
                            <td>
                                <?php wp_dropdown_pages([
                                    'name' => 'page_group_center',
                                    'show_option_none' => __('— 选择页面 —', 'qilingshop'),
                                    'option_none_value' => 0,
                                    'selected' => get_option('qls_shop_page_group_center', 0),
                                ]); ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('团购详情', 'qilingshop'); ?></strong></td>
                            <td><code>[qls_group_detail]</code></td>
                            <td><?php _e('拼团详情页，支持分享链接', 'qilingshop'); ?></td>
                            <td>
                                <?php wp_dropdown_pages([
                                    'name' => 'page_group_detail',
                                    'show_option_none' => __('— 选择页面 —', 'qilingshop'),
                                    'option_none_value' => 0,
                                    'selected' => get_option('qls_shop_page_group_detail', 0),
                                ]); ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('好友助力中心', 'qilingshop'); ?></strong></td>
                            <td><code>[qls_assist_center]</code></td>
                            <td><?php _e('助力活动列表页，用户可发起助力活动', 'qilingshop'); ?></td>
                            <td>
                                <?php wp_dropdown_pages([
                                    'name' => 'page_assist_center',
                                    'show_option_none' => __('— 选择页面 —', 'qilingshop'),
                                    'option_none_value' => 0,
                                    'selected' => get_option('qls_shop_page_assist_center', 0),
                                ]); ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('助力详情', 'qilingshop'); ?></strong></td>
                            <td><code>[qls_assist_detail]</code></td>
                            <td><?php _e('单个助力活动详情页，支持邀请好友助力', 'qilingshop'); ?></td>
                            <td>
                                <?php wp_dropdown_pages([
                                    'name' => 'page_assist_detail',
                                    'show_option_none' => __('— 选择页面 —', 'qilingshop'),
                                    'option_none_value' => 0,
                                    'selected' => get_option('qls_shop_page_assist_detail', 0),
                                ]); ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('我的助力', 'qilingshop'); ?></strong></td>
                            <td><code>[qls_my_assists]</code></td>
                            <td><?php _e('用户查看自己的助力记录', 'qilingshop'); ?></td>
                            <td>
                                <?php wp_dropdown_pages([
                                    'name' => 'page_my_assists',
                                    'show_option_none' => __('— 选择页面 —', 'qilingshop'),
                                    'option_none_value' => 0,
                                    'selected' => get_option('qls_shop_page_my_assists', 0),
                                ]); ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('我的下载', 'qilingshop'); ?></strong></td>
                            <td><code>[qls_my_downloads]</code></td>
                            <td><?php _e('用户查看虚拟商品订单并快速复制下载内容', 'qilingshop'); ?></td>
                            <td>
                                <?php wp_dropdown_pages([
                                    'name' => 'page_my_downloads',
                                    'show_option_none' => __('— 选择页面 —', 'qilingshop'),
                                    'option_none_value' => 0,
                                    'selected' => get_option('qls_shop_page_my_downloads', 0),
                                ]); ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('订单查询(游客)', 'qilingshop'); ?></strong></td>
                            <td><code>[qilingshop_order_query]</code></td>
                            <td><?php _e('游客输入订单号+手机号/邮箱查询订单状态，实物商城游客支付成功后默认跳转此页', 'qilingshop'); ?></td>
                            <td>
                                <?php wp_dropdown_pages([
                                    'name' => 'page_order_query',
                                    'show_option_none' => __('— 选择页面 —', 'qilingshop'),
                                    'option_none_value' => 0,
                                    'selected' => get_option('qls_shop_page_order_query', get_option('qilingshop_page_order_query', 0)),
                                ]); ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('我的拼团', 'qilingshop'); ?></strong></td>
                            <td><code>[qls_my_groups]</code></td>
                            <td><?php _e('用户参与的拼团记录(默认已集成到商城中心)', 'qilingshop'); ?></td>
                            <td><em><?php _e('无需关联', 'qilingshop'); ?></em></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('商品列表', 'qilingshop'); ?></strong></td>
                            <td><code>[qls_products category="slug" limit="12"]</code></td>
                            <td><?php _e('可指定分类和数量展示商品列表，可用于任意页面', 'qilingshop'); ?></td>
                            <td><em><?php _e('无需关联', 'qilingshop'); ?></em></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <p class="submit">
                <button type="submit" class="button button-primary"><?php _e('保存页面设置', 'qilingshop'); ?></button>
                <button type="button" class="button" id="qls-create-pages"><?php _e('一键创建所有页面', 'qilingshop'); ?></button>
            </p>
        </form>
        
        <?php elseif ($tab === 'shipping'): ?>
        <!-- 运费规则 -->
        <?php
        $rules = qls_shipping()->get_rules(false);
        $edit_rule = null;
        if (isset($_GET['edit_rule'])) {
            $edit_rule = qls_shipping()->get_rule(intval($_GET['edit_rule']));
        }
        ?>
        
        <div class="qls-settings-two-col">
            <div class="qls-form-col">
                <h3><?php echo $edit_rule ? __('编辑运费规则', 'qilingshop') : __('添加运费规则', 'qilingshop'); ?></h3>
                
                <form method="post">
                    <?php wp_nonce_field('qls_shipping_action'); ?>
                    <input type="hidden" name="shipping_action" value="save">
                    <?php if ($edit_rule): ?>
                    <input type="hidden" name="rule_id" value="<?php echo esc_attr($edit_rule->id); ?>">
                    <?php endif; ?>
                    
                    <table class="form-table qls-ui-form-table">
                        <tr>
                            <th><label><?php _e('规则名称', 'qilingshop'); ?></label></th>
                            <td><input type="text" name="rule_name" class="regular-text" required value="<?php echo esc_attr($edit_rule->name ?? ''); ?>"></td>
                        </tr>
                        <tr>
                            <th><label><?php _e('计费类型', 'qilingshop'); ?></label></th>
                            <td>
                                <select name="rule_type" id="rule_type">
                                    <option value="0" <?php selected($edit_rule->type ?? 0, 0); ?>><?php _e('包邮', 'qilingshop'); ?></option>
                                    <option value="1" <?php selected($edit_rule->type ?? 0, 1); ?>><?php _e('固定运费', 'qilingshop'); ?></option>
                                    <option value="2" <?php selected($edit_rule->type ?? 0, 2); ?>><?php _e('按重量计费', 'qilingshop'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr class="rule-fee-row">
                            <th><label><?php _e('基础运费', 'qilingshop'); ?></label></th>
                            <td><input type="number" name="base_fee" class="small-text" step="0.01" min="0" value="<?php echo esc_attr($edit_rule->base_fee ?? 0); ?>"> <?php esc_html_e('元', 'qilingshop'); ?></td>
                        </tr>
                        <tr class="rule-weight-row<?php echo ($edit_rule->type ?? 0) != 2 ? ' qls-hidden' : ''; ?>">
                            <th><label><?php _e('首重', 'qilingshop'); ?></label></th>
                            <td><input type="number" name="weight_step" class="small-text" step="1" min="1" value="<?php echo esc_attr($edit_rule->weight_step ?? 1000); ?>"> <?php esc_html_e('克', 'qilingshop'); ?></td>
                        </tr>
                        <tr class="rule-weight-row<?php echo ($edit_rule->type ?? 0) != 2 ? ' qls-hidden' : ''; ?>">
                            <th><label><?php _e('续重费用', 'qilingshop'); ?></label></th>
                            <td><input type="number" name="step_fee" class="small-text" step="0.01" min="0" value="<?php echo esc_attr($edit_rule->step_fee ?? 1); ?>"> <?php printf(esc_html__('元/每%s克', 'qilingshop'), esc_html($edit_rule->weight_step ?? 1000)); ?></td>
                        </tr>
                        <tr>
                            <th><label><?php _e('满额包邮', 'qilingshop'); ?></label></th>
                            <td><input type="number" name="free_threshold" class="small-text" step="0.01" min="0" value="<?php echo esc_attr($edit_rule->free_threshold ?? ''); ?>"> <?php esc_html_e('元', 'qilingshop'); ?> <span class="description"><?php _e('留空表示不启用', 'qilingshop'); ?></span></td>
                        </tr>
                        <tr>
                            <th><label><?php _e('设为默认', 'qilingshop'); ?></label></th>
                            <td><label><input type="checkbox" name="is_default" value="1" <?php checked($edit_rule->is_default ?? 0); ?>> <?php _e('新商品默认使用此规则', 'qilingshop'); ?></label></td>
                        </tr>
                        <tr>
                            <th><label><?php _e('启用状态', 'qilingshop'); ?></label></th>
                            <td><label><input type="checkbox" name="rule_status" value="1" <?php checked($edit_rule->status ?? 1); ?>> <?php _e('启用', 'qilingshop'); ?></label></td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php _e('保存规则', 'qilingshop'); ?></button>
                        <?php if ($edit_rule): ?>
                        <a href="<?php echo admin_url('admin.php?page=qls-shop-settings&tab=shipping'); ?>" class="button"><?php _e('取消', 'qilingshop'); ?></a>
                        <?php endif; ?>
                    </p>
                </form>
            </div>
            
            <div class="qls-list-col">
                <h3><?php _e('运费规则列表', 'qilingshop'); ?></h3>
                
                <table class="wp-list-table qls-ui-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('规则名称', 'qilingshop'); ?></th>
                            <th><?php _e('类型', 'qilingshop'); ?></th>
                            <th><?php _e('说明', 'qilingshop'); ?></th>
                            <th><?php _e('默认', 'qilingshop'); ?></th>
                            <th><?php _e('状态', 'qilingshop'); ?></th>
                            <th><?php _e('操作', 'qilingshop'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rules)): ?>
                        <tr><td colspan="6"><?php _e('暂无运费规则', 'qilingshop'); ?></td></tr>
                        <?php else: ?>
                        <?php foreach ($rules as $rule): ?>
                        <tr>
                            <td><?php echo esc_html($rule->name); ?></td>
                            <td><?php echo esc_html(qls_shipping()->get_type_text($rule->type)); ?></td>
                            <td><?php echo esc_html(qls_shipping()->get_rule_description($rule)); ?></td>
                            <td><?php echo $rule->is_default ? '✓' : ''; ?></td>
                            <td>
                                <span class="status-badge <?php echo $rule->status ? 'success' : ''; ?>">
                                    <?php echo $rule->status ? __('启用', 'qilingshop') : __('禁用', 'qilingshop'); ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=qls-shop-settings&tab=shipping&edit_rule=' . $rule->id); ?>"><?php _e('编辑', 'qilingshop'); ?></a> |
                                <form method="post" class="qls-inline-form">
                                    <?php wp_nonce_field('qls_shipping_action'); ?>
                                    <input type="hidden" name="shipping_action" value="delete">
                                    <input type="hidden" name="rule_id" value="<?php echo esc_attr($rule->id); ?>">
                                    <button type="submit" class="button-link delete-link" onclick="return confirm('<?php _e('确定删除？', 'qilingshop'); ?>');"><?php _e('删除', 'qilingshop'); ?></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <script>
        jQuery(function($) {
            $('#rule_type').on('change', function() {
                if ($(this).val() == '2') {
                    $('.rule-weight-row').show();
                } else {
                    $('.rule-weight-row').hide();
                }
            });
        });
        </script>
        
        <?php elseif ($tab === 'service'): ?>
        <!-- 服务标签 -->
        <?php
        $tags = $db->get_results('service_tags', ['orderby' => 'sort_order', 'order' => 'ASC']);
        $edit_tag = null;
        if (isset($_GET['edit_tag'])) {
            $edit_tag = $db->get_by_id('service_tags', intval($_GET['edit_tag']));
        }
        ?>
        
        <div class="qls-settings-two-col">
            <div class="qls-form-col">
                <h3><?php echo $edit_tag ? __('编辑服务标签', 'qilingshop') : __('添加服务标签', 'qilingshop'); ?></h3>
                
                <form method="post">
                    <?php wp_nonce_field('qls_service_action'); ?>
                    <input type="hidden" name="service_action" value="save">
                    <?php if ($edit_tag): ?>
                    <input type="hidden" name="tag_id" value="<?php echo esc_attr($edit_tag->id); ?>">
                    <?php endif; ?>
                    
                    <table class="form-table qls-ui-form-table">
                        <tr>
                            <th><label><?php _e('标签名称', 'qilingshop'); ?></label></th>
                            <td><input type="text" name="tag_name" class="regular-text" required value="<?php echo esc_attr($edit_tag->name ?? ''); ?>" placeholder="<?php _e('如：7天无理由退货', 'qilingshop'); ?>"></td>
                        </tr>
                        <tr>
                            <th><label><?php _e('图标', 'qilingshop'); ?></label></th>
                            <td>
                                <input type="text" name="tag_icon" class="regular-text" value="<?php echo esc_attr($edit_tag->icon ?? '✅'); ?>">
                                <p class="description"><?php _e('支持表情符号、HTML 图标片段、启灵阿里图标 icon-xxx；旧的 dashicons-xxx 也会兼容显示。', 'qilingshop'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php _e('排序', 'qilingshop'); ?></label></th>
                            <td><input type="number" name="sort_order" class="small-text" value="<?php echo esc_attr($edit_tag->sort_order ?? 0); ?>"></td>
                        </tr>
                        <tr>
                            <th><label><?php _e('默认显示', 'qilingshop'); ?></label></th>
                            <td><label><input type="checkbox" name="tag_default" value="1" <?php checked($edit_tag->is_default ?? 0); ?>> <?php _e('发布新商品时默认勾选', 'qilingshop'); ?></label></td>
                        </tr>
                        <tr>
                            <th><label><?php _e('启用状态', 'qilingshop'); ?></label></th>
                            <td><label><input type="checkbox" name="tag_status" value="1" <?php checked($edit_tag->status ?? 1); ?>> <?php _e('启用', 'qilingshop'); ?></label></td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php _e('保存标签', 'qilingshop'); ?></button>
                        <?php if ($edit_tag): ?>
                        <a href="<?php echo admin_url('admin.php?page=qls-shop-settings&tab=service'); ?>" class="button"><?php _e('取消', 'qilingshop'); ?></a>
                        <?php endif; ?>
                    </p>
                </form>
            </div>
            
            <div class="qls-list-col">
                <h3><?php _e('服务标签列表', 'qilingshop'); ?></h3>
                
                <table class="wp-list-table qls-ui-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('标签名称', 'qilingshop'); ?></th>
                            <th><?php _e('图标', 'qilingshop'); ?></th>
                            <th><?php _e('默认', 'qilingshop'); ?></th>
                            <th><?php _e('排序', 'qilingshop'); ?></th>
                            <th><?php _e('状态', 'qilingshop'); ?></th>
                            <th><?php _e('操作', 'qilingshop'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tags)): ?>
                        <tr><td colspan="6"><?php _e('暂无服务标签', 'qilingshop'); ?></td></tr>
                        <?php else: ?>
                        <?php foreach ($tags as $tag): ?>
                        <tr>
                            <td><?php echo esc_html($tag->name); ?></td>
                            <td><?php echo qilingshop_render_icon($tag->icon ?? '', 'qls-service-tag-icon'); ?></td>
                            <td><?php if (!empty($tag->is_default)): ?><span class="dashicons dashicons-yes" title="<?php _e('默认', 'qilingshop'); ?>"></span><?php endif; ?></td>
                            <td><?php echo esc_html($tag->sort_order); ?></td>
                            <td>
                                <span class="status-badge <?php echo $tag->status ? 'success' : ''; ?>">
                                    <?php echo $tag->status ? __('启用', 'qilingshop') : __('禁用', 'qilingshop'); ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=qls-shop-settings&tab=service&edit_tag=' . $tag->id); ?>"><?php _e('编辑', 'qilingshop'); ?></a> |
                                <form method="post" class="qls-inline-form">
                                    <?php wp_nonce_field('qls_service_action'); ?>
                                    <input type="hidden" name="service_action" value="delete">
                                    <input type="hidden" name="tag_id" value="<?php echo esc_attr($tag->id); ?>">
                                    <button type="submit" class="button-link delete-link" onclick="return confirm('<?php _e('确定删除？', 'qilingshop'); ?>');"><?php _e('删除', 'qilingshop'); ?></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <?php elseif ($tab === 'params'): ?>
        <!-- 参数模板 -->
        <?php
        $params = $db->get_results('product_params', ['orderby' => 'sort_order', 'order' => 'ASC']);
        $edit_param = null;
        if (isset($_GET['edit_param'])) {
            $edit_param = $db->get_by_id('product_params', intval($_GET['edit_param']));
        }
        ?>
        
        <div class="qls-settings-two-col">
            <div class="qls-form-col">
                <h3><?php echo $edit_param ? __('编辑参数模板', 'qilingshop') : __('添加参数模板', 'qilingshop'); ?></h3>
                <p class="description"><?php _e('预设常用的商品参数名称，如品牌、型号、尺寸、内存、版本等', 'qilingshop'); ?></p>
                
                <form method="post">
                    <?php wp_nonce_field('qls_param_action'); ?>
                    <input type="hidden" name="param_action" value="save">
                    <?php if ($edit_param): ?>
                    <input type="hidden" name="param_id" value="<?php echo esc_attr($edit_param->id); ?>">
                    <?php endif; ?>
                    
                    <table class="form-table qls-ui-form-table">
                        <tr>
                            <th><label><?php _e('参数名称', 'qilingshop'); ?></label></th>
                            <td><input type="text" name="param_name" class="regular-text" required value="<?php echo esc_attr($edit_param->name ?? ''); ?>" placeholder="<?php _e('如：品牌、型号、尺寸', 'qilingshop'); ?>"></td>
                        </tr>
                        <tr>
                            <th><label><?php _e('排序', 'qilingshop'); ?></label></th>
                            <td><input type="number" name="sort_order" class="small-text" value="<?php echo esc_attr($edit_param->sort_order ?? 0); ?>"></td>
                        </tr>
                        <tr>
                            <th><label><?php _e('启用状态', 'qilingshop'); ?></label></th>
                            <td><label><input type="checkbox" name="param_status" value="1" <?php checked($edit_param->status ?? 1); ?>> <?php _e('启用', 'qilingshop'); ?></label></td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php _e('保存参数', 'qilingshop'); ?></button>
                        <?php if ($edit_param): ?>
                        <a href="<?php echo admin_url('admin.php?page=qls-shop-settings&tab=params'); ?>" class="button"><?php _e('取消', 'qilingshop'); ?></a>
                        <?php endif; ?>
                    </p>
                </form>
            </div>
            
            <div class="qls-list-col">
                <h3><?php _e('参数模板列表', 'qilingshop'); ?></h3>
                <p class="description"><?php _e('这些参数会在添加商品时作为快速选择项出现', 'qilingshop'); ?></p>
                
                <table class="wp-list-table qls-ui-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('参数名称', 'qilingshop'); ?></th>
                            <th><?php _e('排序', 'qilingshop'); ?></th>
                            <th><?php _e('状态', 'qilingshop'); ?></th>
                            <th><?php _e('操作', 'qilingshop'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($params)): ?>
                        <tr><td colspan="4"><?php _e('暂无参数模板，请添加常用的商品参数名称', 'qilingshop'); ?></td></tr>
                        <?php else: ?>
                        <?php foreach ($params as $param): ?>
                        <tr>
                            <td><strong><?php echo esc_html($param->name); ?></strong></td>
                            <td><?php echo esc_html($param->sort_order); ?></td>
                            <td>
                                <span class="status-badge <?php echo $param->status ? 'success' : ''; ?>">
                                    <?php echo $param->status ? __('启用', 'qilingshop') : __('禁用', 'qilingshop'); ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=qls-shop-settings&tab=params&edit_param=' . $param->id); ?>"><?php _e('编辑', 'qilingshop'); ?></a> |
                                <form method="post" class="qls-inline-form">
                                    <?php wp_nonce_field('qls_param_action'); ?>
                                    <input type="hidden" name="param_action" value="delete">
                                    <input type="hidden" name="param_id" value="<?php echo esc_attr($param->id); ?>">
                                    <button type="submit" class="button-link delete-link" onclick="return confirm('<?php _e('确定删除？', 'qilingshop'); ?>');"><?php _e('删除', 'qilingshop'); ?></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <?php elseif ($tab === 'express'): ?>
        <!-- 快递物流 -->
        <?php
        $shipping_company_service = function_exists('qls_shipping_company') ? qls_shipping_company() : null;
        $waybill_service = function_exists('qls_waybill') ? qls_waybill() : null;
        $companies = $shipping_company_service ? $shipping_company_service->all(true) : [];
        
        $edit_company = null;
        $edit_id = 0;
        if (isset($_GET['edit_id'])) {
            $edit_id = absint(wp_unslash($_GET['edit_id']));
            $edit_company = $shipping_company_service ? $shipping_company_service->get($edit_id) : null;
        } elseif (isset($_GET['edit_index'])) {
            $edit_index = absint(wp_unslash($_GET['edit_index']));
            if (isset($companies[$edit_index])) {
                $edit_company = $companies[$edit_index];
                $edit_id = (int) ($edit_company->id ?? 0);
            }
        }

        $waybill_providers = $waybill_service ? $waybill_service->get_providers() : [];
        $waybill_templates = $waybill_service ? $waybill_service->get_templates(['limit' => -1]) : [];
        $edit_template_id = isset($_GET['edit_template']) ? absint(wp_unslash($_GET['edit_template'])) : 0;
        $edit_template = $edit_template_id > 0 && $waybill_service ? $waybill_service->get_template($edit_template_id) : null;
        $company_name_map = [];
        foreach ((array) $companies as $company) {
            $company_name_map[(int) ($company->id ?? 0)] = (string) ($company->name ?? '');
        }
        ?>
        
        <div class="qls-box qls-settings-card-box">
            <h3><?php _e('接口配置', 'qilingshop'); ?></h3>
            <form method="post">
                <?php wp_nonce_field('qls_express_config'); ?>
                <input type="hidden" name="express_config_action" value="save">
                <table class="form-table qls-ui-form-table">
                    <tr>
                        <th class="qls-w-120"><label for="api_key"><?php _e('灵简 API Key', 'qilingshop'); ?></label></th>
                        <td>
                            <input type="password" name="api_key" id="api_key" class="regular-text" value="<?php echo esc_attr(get_option('qls_shop_express_api_key', '')); ?>" pattern="ip_live_[A-Za-z0-9]{32}" title="<?php esc_attr_e('请输入灵简平台生成的 API Key', 'qilingshop'); ?>" autocomplete="new-password">
                            <p class="description">
                                <?php _e('用于查询物流轨迹。请前往', 'qilingshop'); ?>
                                <a href="https://api.jingxialai.com/" target="_blank" rel="noopener noreferrer">灵简 API</a>
                                <?php _e('获取 API Key。', 'qilingshop'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <p class="submit"><button type="submit" class="button button-primary"><?php _e('保存配置', 'qilingshop'); ?></button></p>
            </form>
        </div>

        <div class="qls-box qls-settings-card-box">
            <h3><?php _e('电子面单配置', 'qilingshop'); ?></h3>
            <form method="post">
                <?php wp_nonce_field('qls_waybill_config'); ?>
                <input type="hidden" name="waybill_config_action" value="save">
                <table class="form-table qls-ui-form-table">
                    <tr>
                        <th class="qls-w-120"><?php _e('启用电子面单', 'qilingshop'); ?></th>
                        <td>
                            <label><input type="checkbox" name="waybill_enabled" value="1" <?php checked((int) get_option('qls_shop_waybill_enabled', 1), 1); ?>> <?php _e('允许发货时生成和打印电子面单', 'qilingshop'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="waybill_appcode"><?php _e('电子面单 AppCode', 'qilingshop'); ?></label></th>
                        <td>
                            <input type="password" name="waybill_appcode" id="waybill_appcode" class="regular-text" value="<?php echo esc_attr(get_option('qls_shop_waybill_appcode', '')); ?>">
                            <p class="description"><?php _e('快递鸟电子面单接口使用的 AppCode，可与物流查询 AppCode 分开配置。', 'qilingshop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('服务商', 'qilingshop'); ?></th>
                        <td>
                            <strong><?php _e('快递鸟电子面单', 'qilingshop'); ?></strong>
                            <input type="hidden" name="waybill_provider" value="kdniao">
                            <p class="description"><?php _e('发货时通过快递鸟接口创建真实电子面单。', 'qilingshop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('发货时自动生成', 'qilingshop'); ?></th>
                        <td>
                            <label><input type="checkbox" name="waybill_auto_generate" value="1" <?php checked((int) get_option('qls_shop_waybill_auto_generate', 0), 1); ?>> <?php _e('后台订单发货时默认勾选生成电子面单', 'qilingshop'); ?></label>
                        </td>
                    </tr>
                </table>
                <p class="submit"><button type="submit" class="button button-primary"><?php _e('保存电子面单配置', 'qilingshop'); ?></button></p>
            </form>
        </div>

        <div class="qls-settings-two-col">
            <div class="qls-form-col">
                <h3><?php echo $edit_template ? __('编辑电子面单模板', 'qilingshop') : __('添加电子面单模板', 'qilingshop'); ?></h3>
                <?php $edit_config = is_array($edit_template->template_config ?? null) ? $edit_template->template_config : []; ?>
                <form method="post">
                    <?php wp_nonce_field('qls_waybill_template'); ?>
                    <input type="hidden" name="waybill_template_action" value="save">
                    <input type="hidden" name="template_id" value="<?php echo esc_attr($edit_template_id); ?>">

                    <table class="form-table qls-ui-form-table">
                        <tr>
                            <th><label><?php _e('模板名称', 'qilingshop'); ?></label></th>
                            <td><input type="text" name="template_name" class="regular-text" required value="<?php echo esc_attr($edit_template->name ?? ''); ?>" placeholder="<?php esc_attr_e('如：默认电子面单', 'qilingshop'); ?>"></td>
                        </tr>
                        <tr>
                            <th><label><?php _e('服务商', 'qilingshop'); ?></label></th>
                            <td>
                                <strong><?php _e('快递鸟电子面单', 'qilingshop'); ?></strong>
                                <input type="hidden" name="template_provider" value="kdniao">
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php _e('快递公司', 'qilingshop'); ?></label></th>
                            <td>
                                <select name="template_company_id">
                                    <option value="0"><?php _e('不限公司', 'qilingshop'); ?></option>
                                    <?php foreach ($companies as $company): ?>
                                    <option value="<?php echo esc_attr($company->id); ?>" <?php selected((int) ($edit_template->company_id ?? 0), (int) $company->id); ?>><?php echo esc_html($company->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php _e('寄件人', 'qilingshop'); ?></label></th>
                            <td><input type="text" name="sender_name" class="regular-text" value="<?php echo esc_attr($edit_template->sender_name ?? ''); ?>" placeholder="<?php esc_attr_e('寄件人姓名', 'qilingshop'); ?>"></td>
                        </tr>
                        <tr>
                            <th><label><?php _e('寄件电话', 'qilingshop'); ?></label></th>
                            <td><input type="text" name="sender_phone" class="regular-text" value="<?php echo esc_attr($edit_template->sender_phone ?? ''); ?>" placeholder="<?php esc_attr_e('寄件人手机号', 'qilingshop'); ?>"></td>
                        </tr>
                        <tr>
                            <th><label><?php _e('寄件地区', 'qilingshop'); ?></label></th>
                            <td>
                                <div class="qls-address-trio">
                                    <input type="text" name="sender_province" class="small-text qls-input-province" value="<?php echo esc_attr($edit_template->sender_province ?? ''); ?>" placeholder="<?php esc_attr_e('省', 'qilingshop'); ?>">
                                    <input type="text" name="sender_city" class="small-text qls-input-city" value="<?php echo esc_attr($edit_template->sender_city ?? ''); ?>" placeholder="<?php esc_attr_e('市', 'qilingshop'); ?>">
                                    <input type="text" name="sender_district" class="small-text qls-input-district" value="<?php echo esc_attr($edit_template->sender_district ?? ''); ?>" placeholder="<?php esc_attr_e('区', 'qilingshop'); ?>">
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php _e('寄件地址', 'qilingshop'); ?></label></th>
                            <td><textarea name="sender_address" rows="2" class="large-text" placeholder="<?php esc_attr_e('寄件人详细地址', 'qilingshop'); ?>"><?php echo esc_textarea($edit_template->sender_address ?? ''); ?></textarea></td>
                        </tr>
                        <tr>
                            <th><label><?php _e('面单规格', 'qilingshop'); ?></label></th>
                            <td><input type="text" name="sheet_size" class="regular-text" value="<?php echo esc_attr($edit_config['sheet_size'] ?? '100x150'); ?>" placeholder="100x150"></td>
                        </tr>
                        <tr>
                            <th><label><?php _e('打印机编号', 'qilingshop'); ?></label></th>
                            <td><input type="text" name="printer_no" class="regular-text" value="<?php echo esc_attr($edit_config['printer_no'] ?? ''); ?>" placeholder="<?php esc_attr_e('可选，给第三方打印服务使用', 'qilingshop'); ?>"></td>
                        </tr>
                        <tr>
                            <th><label><?php _e('默认重量', 'qilingshop'); ?></label></th>
                            <td>
                                <div class="qls-input-inline-unit">
                                    <input type="number" name="weight" class="small-text" min="0" step="0.1" value="<?php echo esc_attr($edit_config['weight'] ?? 1); ?>">
                                    <span class="qls-unit-text">kg</span>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php _e('打印备注', 'qilingshop'); ?></label></th>
                            <td><textarea name="print_note" rows="2" class="large-text"><?php echo esc_textarea($edit_config['print_note'] ?? ''); ?></textarea></td>
                        </tr>
                        <tr>
                            <th><label><?php _e('寄件公司', 'qilingshop'); ?></label></th>
                            <td><input type="text" name="sender_company" class="regular-text" value="<?php echo esc_attr($edit_config['sender_company'] ?? ''); ?>"></td>
                        </tr>
                        <tr>
                            <th><label><?php _e('支付方式', 'qilingshop'); ?></label></th>
                            <td>
                                <div class="qls-input-inline-unit">
                                    <input type="number" name="pay_type" class="small-text" min="1" value="<?php echo esc_attr($edit_config['pay_type'] ?? 1); ?>">
                                    <span class="description"><?php _e('按快递鸟接口定义填写，默认 1。', 'qilingshop'); ?></span>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php _e('月结号', 'qilingshop'); ?></label></th>
                            <td><input type="text" name="month_code" class="regular-text" value="<?php echo esc_attr($edit_config['month_code'] ?? ''); ?>"></td>
                        </tr>
                        <tr>
                            <th><label><?php _e('快递产品类型', 'qilingshop'); ?></label></th>
                            <td><input type="number" name="exp_type" class="small-text" min="1" value="<?php echo esc_attr($edit_config['exp_type'] ?? 1); ?>"></td>
                        </tr>
                        <tr>
                            <th><label><?php _e('费用参数', 'qilingshop'); ?></label></th>
                            <td>
                                <div class="qls-fee-duo">
                                    <input type="number" name="cost" class="small-text qls-input-cost" min="0" step="0.01" value="<?php echo esc_attr($edit_config['cost'] ?? 0); ?>" placeholder="Cost">
                                    <input type="number" name="other_cost" class="small-text qls-input-other-cost" min="0" step="0.01" value="<?php echo esc_attr($edit_config['other_cost'] ?? 0); ?>" placeholder="OtherCost">
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php _e('体积', 'qilingshop'); ?></label></th>
                            <td>
                                <div class="qls-input-inline-unit">
                                    <input type="number" name="volume" class="small-text" min="0" step="0.01" value="<?php echo esc_attr($edit_config['volume'] ?? 0); ?>">
                                    <span class="qls-unit-text">m³</span>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php _e('默认模板', 'qilingshop'); ?></label></th>
                            <td><label><input type="checkbox" name="template_is_default" value="1" <?php checked($edit_template ? !empty($edit_template->is_default) : empty($waybill_templates)); ?>> <?php _e('设为默认电子面单模板', 'qilingshop'); ?></label></td>
                        </tr>
                        <tr>
                            <th><label><?php _e('状态', 'qilingshop'); ?></label></th>
                            <td>
                                <select name="template_status">
                                    <option value="1" <?php selected((int) ($edit_template->status ?? 1), 1); ?>><?php _e('启用', 'qilingshop'); ?></option>
                                    <option value="0" <?php selected((int) ($edit_template->status ?? 1), 0); ?>><?php _e('停用', 'qilingshop'); ?></option>
                                </select>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php _e('保存电子面单模板', 'qilingshop'); ?></button>
                        <?php if ($edit_template): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=qls-shop-settings&tab=express')); ?>" class="button"><?php _e('取消', 'qilingshop'); ?></a>
                        <?php endif; ?>
                    </p>
                </form>
            </div>

            <div class="qls-list-col">
                <h3><?php _e('电子面单模板', 'qilingshop'); ?></h3>
                <table class="wp-list-table qls-ui-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('模板', 'qilingshop'); ?></th>
                            <th><?php _e('快递公司', 'qilingshop'); ?></th>
                            <th><?php _e('寄件人', 'qilingshop'); ?></th>
                            <th><?php _e('默认', 'qilingshop'); ?></th>
                            <th><?php _e('状态', 'qilingshop'); ?></th>
                            <th><?php _e('操作', 'qilingshop'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($waybill_templates)): ?>
                        <tr><td colspan="6"><?php _e('暂无电子面单模板', 'qilingshop'); ?></td></tr>
                        <?php else: ?>
                        <?php foreach ($waybill_templates as $template): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($template->name); ?></strong>
                                <br><small><?php echo esc_html($waybill_providers[$template->provider]['label'] ?? $template->provider); ?></small>
                            </td>
                            <td><?php echo !empty($template->company_id) ? esc_html($company_name_map[(int) $template->company_id] ?? ('#' . (int) $template->company_id)) : esc_html__('不限', 'qilingshop'); ?></td>
                            <td>
                                <?php echo esc_html($template->sender_name ?: '-'); ?>
                                <?php if (!empty($template->sender_phone)): ?>
                                <br><small><?php echo esc_html($template->sender_phone); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo !empty($template->is_default) ? esc_html__('是', 'qilingshop') : esc_html__('否', 'qilingshop'); ?></td>
                            <td><?php echo !empty($template->status) ? esc_html__('启用', 'qilingshop') : esc_html__('停用', 'qilingshop'); ?></td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=qls-shop-settings&tab=express&edit_template=' . (int) $template->id)); ?>"><?php _e('编辑', 'qilingshop'); ?></a> |
                                <form method="post" class="qls-inline-form">
                                    <?php wp_nonce_field('qls_waybill_template'); ?>
                                    <input type="hidden" name="waybill_template_action" value="delete">
                                    <input type="hidden" name="template_id" value="<?php echo esc_attr($template->id); ?>">
                                    <button type="submit" class="button-link delete-link" onclick="return confirm('<?php esc_attr_e('确定删除？', 'qilingshop'); ?>');"><?php _e('删除', 'qilingshop'); ?></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="qls-settings-two-col">
            <div class="qls-form-col">
                <h3><?php echo $edit_company ? __('编辑快递公司', 'qilingshop') : __('添加快递公司', 'qilingshop'); ?></h3>
                
                <form method="post">
                    <?php wp_nonce_field('qls_express_action'); ?>
                    <input type="hidden" name="express_action" value="save">
                    <input type="hidden" name="company_id" value="<?php echo esc_attr($edit_id); ?>">
                    
                    <table class="form-table qls-ui-form-table">
                        <tr>
                            <th><label><?php _e('公司名称', 'qilingshop'); ?></label></th>
                            <td><input type="text" name="company_name" class="regular-text" required value="<?php echo esc_attr($edit_company->name ?? ''); ?>" placeholder="<?php _e('如：顺丰速运', 'qilingshop'); ?>"></td>
                        </tr>
                        <tr>
                            <th><label><?php _e('公司代码', 'qilingshop'); ?></label></th>
                            <td>
                                <input type="text" name="company_code" class="regular-text" required value="<?php echo esc_attr($edit_company->code ?? ''); ?>" placeholder="<?php _e('如：SF', 'qilingshop'); ?>">
                                <p class="description"><?php _e('必填，用于物流查询接口和后续电子面单匹配。', 'qilingshop'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php _e('别名', 'qilingshop'); ?></label></th>
                            <td>
                                <textarea name="company_aliases" rows="3" class="large-text" placeholder="<?php esc_attr_e("如：顺丰\n顺丰快递", 'qilingshop'); ?>"><?php echo esc_textarea(implode("\n", (array) ($edit_company->aliases ?? []))); ?></textarea>
                                <p class="description"><?php _e('一行一个，也支持用逗号分隔。批量发货或物流识别时可以匹配这些别名。', 'qilingshop'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php _e('查询手机号', 'qilingshop'); ?></label></th>
                            <td>
                                <label><input type="checkbox" name="phone_required" value="1" <?php checked(!empty($edit_company->phone_required)); ?>> <?php _e('物流轨迹查询通常需要收件人手机号', 'qilingshop'); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php _e('默认公司', 'qilingshop'); ?></label></th>
                            <td>
                                <label><input type="checkbox" name="is_default" value="1" <?php checked($edit_company ? !empty($edit_company->is_default) : empty($companies)); ?>> <?php _e('设为默认物流公司', 'qilingshop'); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php _e('状态', 'qilingshop'); ?></label></th>
                            <td>
                                <select name="status">
                                    <option value="1" <?php selected((int) ($edit_company->status ?? 1), 1); ?>><?php _e('启用', 'qilingshop'); ?></option>
                                    <option value="0" <?php selected((int) ($edit_company->status ?? 1), 0); ?>><?php _e('停用', 'qilingshop'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php _e('排序', 'qilingshop'); ?></label></th>
                            <td><input type="number" name="sort_order" class="small-text" value="<?php echo esc_attr($edit_company->sort_order ?? 0); ?>"></td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php _e('保存', 'qilingshop'); ?></button>
                        <?php if ($edit_company): ?>
                        <a href="<?php echo admin_url('admin.php?page=qls-shop-settings&tab=express'); ?>" class="button"><?php _e('取消', 'qilingshop'); ?></a>
                        <?php endif; ?>
                    </p>
                </form>
            </div>
            
            <div class="qls-list-col">
                <h3><?php _e('快递公司列表', 'qilingshop'); ?></h3>
                
                <table class="wp-list-table qls-ui-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('名称', 'qilingshop'); ?></th>
                            <th><?php _e('代码', 'qilingshop'); ?></th>
                            <th><?php _e('状态', 'qilingshop'); ?></th>
                            <th><?php _e('默认', 'qilingshop'); ?></th>
                            <th><?php _e('需手机号', 'qilingshop'); ?></th>
                            <th><?php _e('排序', 'qilingshop'); ?></th>
                            <th><?php _e('操作', 'qilingshop'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($companies)): ?>
                        <tr><td colspan="7"><?php _e('暂无快递公司', 'qilingshop'); ?></td></tr>
                        <?php else: ?>
                        <?php foreach ($companies as $company): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($company->name); ?></strong>
                                <?php if (!empty($company->aliases)): ?>
                                    <br><small><?php echo esc_html(implode(' / ', (array) $company->aliases)); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($company->code ?? ''); ?></td>
                            <td><?php echo !empty($company->status) ? esc_html__('启用', 'qilingshop') : esc_html__('停用', 'qilingshop'); ?></td>
                            <td><?php echo !empty($company->is_default) ? esc_html__('是', 'qilingshop') : esc_html__('否', 'qilingshop'); ?></td>
                            <td><?php echo !empty($company->phone_required) ? esc_html__('是', 'qilingshop') : esc_html__('否', 'qilingshop'); ?></td>
                            <td><?php echo esc_html($company->sort_order ?? 0); ?></td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=qls-shop-settings&tab=express&edit_id=' . (int) $company->id)); ?>"><?php _e('编辑', 'qilingshop'); ?></a> |
                                <form method="post" class="qls-inline-form">
                                    <?php wp_nonce_field('qls_express_action'); ?>
                                    <input type="hidden" name="express_action" value="delete">
                                    <input type="hidden" name="company_id" value="<?php echo esc_attr($company->id); ?>">
                                    <button type="submit" class="button-link delete-link" onclick="return confirm('<?php _e('确定删除？', 'qilingshop'); ?>');"><?php _e('删除', 'qilingshop'); ?></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <?php elseif ($tab === 'review'): ?>
        <!-- 评价设置 -->
        <form method="post">
            <?php wp_nonce_field('qls_review_settings'); ?>
            <input type="hidden" name="review_settings_action" value="save">
            
            <table class="form-table qls-ui-form-table">
                <tr>
                    <th><label><?php _e('启用商品评价', 'qilingshop'); ?></label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="review_enabled" value="1" <?php checked(get_option('qls_shop_review_enabled', true)); ?>>
                            <?php _e('开启评价功能', 'qilingshop'); ?>
                        </label>
                        <p class="description"><?php _e('关闭后前台不显示评价入口和评价列表', 'qilingshop'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label><?php _e('审核方式', 'qilingshop'); ?></label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="review_auto_approve" value="1" <?php checked(get_option('qls_shop_review_auto_approve', false)); ?>>
                            <?php _e('自动审核通过', 'qilingshop'); ?>
                        </label>
                        <p class="description"><?php _e('勾选后评价无需人工审核直接显示；不勾选则需要管理员手动审核', 'qilingshop'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label><?php _e('评价条件', 'qilingshop'); ?></label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="review_require_purchase" value="1" <?php checked(get_option('qls_shop_review_require_purchase', true)); ?>>
                            <?php _e('仅购买用户可评价', 'qilingshop'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label for="review_after_days"><?php _e('评价时限', 'qilingshop'); ?></label></th>
                    <td>
                        <?php _e('收货后', 'qilingshop'); ?>
                        <input type="number" name="review_after_days" id="review_after_days" class="small-text" min="1" max="365"
                               value="<?php echo esc_attr(get_option('qls_shop_review_after_days', 15)); ?>">
                        <?php _e('天内可评价', 'qilingshop'); ?>
                        <p class="description"><?php _e('超过时限后无法再评价', 'qilingshop'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="review_image_max"><?php _e('图片上限', 'qilingshop'); ?></label></th>
                    <td>
                        <?php _e('每条评价最多上传', 'qilingshop'); ?>
                        <input type="number" name="review_image_max" id="review_image_max" class="small-text" min="0" max="20"
                               value="<?php echo esc_attr(get_option('qls_shop_review_image_max', 9)); ?>">
                        <?php _e('张图片', 'qilingshop'); ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="review_min_length"><?php _e('内容最少字数', 'qilingshop'); ?></label></th>
                    <td>
                        <input type="number" name="review_min_length" id="review_min_length" class="small-text" min="1" max="500"
                               value="<?php echo esc_attr(get_option('qls_shop_review_min_length', 10)); ?>">
                        <?php _e('字', 'qilingshop'); ?>
                    </td>
                </tr>
                <tr>
                    <th colspan="2" class="qls-settings-section-heading-cell"><h3 class="qls-settings-section-heading"><?php _e('积分奖励', 'qilingshop'); ?></h3></th>
                </tr>
                <tr>
                    <th><label for="review_points_reward"><?php _e('评价奖励积分', 'qilingshop'); ?></label></th>
                    <td>
                        <input type="number" name="review_points_reward" id="review_points_reward" class="small-text" min="0"
                               value="<?php echo esc_attr(get_option('qls_shop_review_points_reward', 10)); ?>">
                        <?php _e('积分', 'qilingshop'); ?>
                        <p class="description"><?php _e('提交评价后奖励的基础积分，设为0表示不奖励', 'qilingshop'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="review_image_bonus"><?php _e('带图额外奖励', 'qilingshop'); ?></label></th>
                    <td>
                        <input type="number" name="review_image_bonus" id="review_image_bonus" class="small-text" min="0"
                               value="<?php echo esc_attr(get_option('qls_shop_review_image_bonus', 5)); ?>">
                        <?php _e('积分', 'qilingshop'); ?>
                        <p class="description"><?php _e('上传图片的评价额外奖励', 'qilingshop'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th colspan="2" class="qls-settings-section-heading-cell"><h3 class="qls-settings-section-heading"><?php _e('显示设置', 'qilingshop'); ?></h3></th>
                </tr>
                <tr>
                    <th><label><?php _e('商品卡片显示评分', 'qilingshop'); ?></label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="review_show_on_card" value="1" <?php checked(get_option('qls_shop_review_show_on_card', false)); ?>>
                            <?php _e('在商品列表卡片上显示评分和评价数', 'qilingshop'); ?>
                        </label>
                        <p class="description"><?php _e('如：★ 4.8 (128条评价)', 'qilingshop'); ?></p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <button type="submit" class="button button-primary"><?php _e('保存评价设置', 'qilingshop'); ?></button>
            </p>
        </form>
        
        <?php elseif ($tab === 'service_showcase'): ?>
        <!-- 服务展示 -->
        <?php
        $service_positions = get_option('qls_shop_service_showcase_positions', ['home_bottom']);
        if (!is_array($service_positions) || empty($service_positions)) {
            $service_positions = ['home_bottom'];
        }
        $service_items = get_option('qls_shop_service_showcase_items', []);
        if (!is_array($service_items) || empty($service_items)) {
            $service_items = [
                ['icon' => '', 'title' => '', 'desc' => ''],
            ];
        }
        ?>

        <form method="post">
            <?php wp_nonce_field('qls_service_showcase_settings'); ?>
            <input type="hidden" name="service_showcase_action" value="save">

            <table class="form-table qls-ui-form-table">
                <tr>
                    <th><label><?php _e('展示位置', 'qilingshop'); ?></label></th>
                    <td>
                        <label class="qls-mr-18">
                            <input type="checkbox" name="service_positions[]" value="home_bottom" <?php checked(in_array('home_bottom', $service_positions, true)); ?>>
                            <?php _e('商城首页底部', 'qilingshop'); ?>
                        </label>
                        <label>
                            <input type="checkbox" name="service_positions[]" value="product_bottom" <?php checked(in_array('product_bottom', $service_positions, true)); ?>>
                            <?php _e('商品详情页底部', 'qilingshop'); ?>
                        </label>
                        <p class="description"><?php _e('可多选。默认勾选“商城首页底部”。', 'qilingshop'); ?></p>
                    </td>
                </tr>
            </table>

            <div class="qls-service-showcase-editor">
                <h3><?php _e('服务项配置', 'qilingshop'); ?></h3>
                <p class="description"><?php _e('每项包含：图标图片链接、标题、描述。全部留空的行不会保存。', 'qilingshop'); ?></p>

                <table class="widefat qls-ui-table striped qls-service-showcase-table">
                    <thead>
                        <tr>
                            <th class="qls-w-240"><?php _e('图标链接', 'qilingshop'); ?></th>
                            <th class="qls-w-260"><?php _e('标题', 'qilingshop'); ?></th>
                            <th><?php _e('描述', 'qilingshop'); ?></th>
                            <th class="qls-w-80"><?php _e('操作', 'qilingshop'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="qls-service-showcase-list">
                        <?php foreach ($service_items as $idx => $item): ?>
                        <tr class="qls-service-showcase-row">
                            <td>
                                <input type="text" class="regular-text" name="service_items[<?php echo esc_attr($idx); ?>][icon]" value="<?php echo esc_attr($item['icon'] ?? ''); ?>" placeholder="<?php esc_attr_e('如：https://example.com/icon.png', 'qilingshop'); ?>">
                            </td>
                            <td>
                                <input type="text" class="regular-text" name="service_items[<?php echo esc_attr($idx); ?>][title]" value="<?php echo esc_attr($item['title'] ?? ''); ?>" placeholder="<?php esc_attr_e('如：正品保障', 'qilingshop'); ?>">
                            </td>
                            <td>
                                <input type="text" class="regular-text" name="service_items[<?php echo esc_attr($idx); ?>][desc]" value="<?php echo esc_attr($item['desc'] ?? ''); ?>" placeholder="<?php esc_attr_e('如：正品保障，品质担保', 'qilingshop'); ?>">
                            </td>
                            <td>
                                <button type="button" class="button-link-delete qls-remove-showcase-row"><?php _e('删除', 'qilingshop'); ?></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p class="qls-mt-12">
                    <button type="button" class="button" id="qls-add-showcase-row"><?php _e('新增一项', 'qilingshop'); ?></button>
                </p>
            </div>

            <p class="submit">
                <button type="submit" class="button button-primary"><?php _e('保存服务展示', 'qilingshop'); ?></button>
            </p>
        </form>

        <script>
        jQuery(function($) {
            var $list = $('#qls-service-showcase-list');
            var nextIndex = $list.find('.qls-service-showcase-row').length;

            function buildRow(index) {
                return '' +
                    '<tr class="qls-service-showcase-row">' +
                        '<td><input type="text" class="regular-text" name="service_items[' + index + '][icon]" placeholder="<?php echo esc_js(__('如：https://example.com/icon.png', 'qilingshop')); ?>"></td>' +
                        '<td><input type="text" class="regular-text" name="service_items[' + index + '][title]" placeholder="<?php echo esc_js(__('如：正品保障', 'qilingshop')); ?>"></td>' +
                        '<td><input type="text" class="regular-text" name="service_items[' + index + '][desc]" placeholder="<?php echo esc_js(__('如：正品保障，品质担保', 'qilingshop')); ?>"></td>' +
                        '<td><button type="button" class="button-link-delete qls-remove-showcase-row"><?php echo esc_js(__('删除', 'qilingshop')); ?></button></td>' +
                    '</tr>';
            }

            $('#qls-add-showcase-row').on('click', function() {
                $list.append(buildRow(nextIndex));
                nextIndex += 1;
            });

            $list.on('click', '.qls-remove-showcase-row', function() {
                if ($list.find('.qls-service-showcase-row').length <= 1) {
                    $list.find('input').val('');
                    return;
                }
                $(this).closest('tr').remove();
            });
        });
        </script>

        <?php endif; ?>
    </div>
</div>

