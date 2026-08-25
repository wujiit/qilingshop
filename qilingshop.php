<?php
/**
 * Plugin Name: 启灵商城
 * Plugin URI: https://www.jingxialai.com
 * Description: 启灵主题商城插件，付费资源、vip会员、实物商城等功能。
 * Version: 2.2.5
 * Author: Summer
 * Author URI: https://www.jingxialai.com
 * Text Domain: qilingshop
 * Domain Path: /lang
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 插件版本号
 */
define('QILINGSHOP_VERSION', '2.2.5');

/**
 * 插件目录路径
 */
define('QILINGSHOP_PATH', plugin_dir_path(__FILE__));

/**
 * 插件目录 URL
 */
define('QILINGSHOP_URL', plugin_dir_url(__FILE__));

/**
 * 插件基础名称
 */
define('QILINGSHOP_BASENAME', plugin_basename(__FILE__));

/**
 * 检查当前站点是否已通过启灵主题正版授权。
 *
 * 商城与启灵主题共用授权，且仅在主题授权类真实加载时认可本地状态，
 * 防止切换主题后残留的 option 继续解锁商城。
 *
 * @return bool
 */
function qilingshop_is_authorized() {
    $license_class = '\\Developer_Starter\\Core\\Theme_License';

    return class_exists($license_class)
        && method_exists($license_class, 'get_status')
        && call_user_func([$license_class, 'get_status']) === 'valid';
}

/**
 * 获取启灵主题正版授权设置地址。
 *
 * @return string
 */
function qilingshop_get_license_url() {
    return admin_url('admin.php?page=developer-starter-settings&tab=license');
}

/**
 * 获取商城遥测接口地址。
 *
 * 可通过 QILINGSHOP_LICENSE_API_URL 常量或 qilingshop_license_api_url 过滤器覆盖。
 *
 * @return string
 */
function qilingshop_get_license_api_url() {
    $default_url = defined('QILINGSHOP_LICENSE_API_URL')
        ? QILINGSHOP_LICENSE_API_URL
        : 'https://www.jingxialai.com/wp-json/qiling-verify/v1/check';

    return esc_url_raw((string) apply_filters('qilingshop_license_api_url', $default_url));
}

/**
 * 首次运行时向授权服务端上报一次商城使用情况。
 */
function qilingshop_maybe_send_telemetry() {
    $sent_option = 'qilingshop_telemetry_sent_v2';
    $retry_transient = 'qilingshop_telemetry_retry_v2';
    if (get_option($sent_option, false) || get_transient($retry_transient)) {
        return;
    }

    $domain = wp_parse_url(home_url('/'), PHP_URL_HOST);
    $domain = is_string($domain) ? strtolower(trim($domain)) : '';
    $endpoint = qilingshop_get_license_api_url();
    if ($domain === '' || $endpoint === '') {
        return;
    }

    $license_class = '\\Developer_Starter\\Core\\Theme_License';
    $license_key = '';
    if (class_exists($license_class) && method_exists($license_class, 'get_key')) {
        $license_key = sanitize_text_field((string) call_user_func([$license_class, 'get_key']));
    }

    $response = wp_remote_post(
        $endpoint,
        [
            'body' => [
                'domain'  => $domain,
                'key'     => $license_key,
                'version' => QILINGSHOP_VERSION,
                'product' => 'qilingshop',
            ],
            'timeout'   => 5,
            'blocking'  => true,
            'sslverify' => true,
        ]
    );

    if (is_wp_error($response)) {
        set_transient($retry_transient, '1', 7 * DAY_IN_SECONDS);
        return;
    }

    $response_code = (int) wp_remote_retrieve_response_code($response);
    $response_body = json_decode(wp_remote_retrieve_body($response), true);
    $accepted = $response_code === 200
        && is_array($response_body)
        && isset($response_body['code'])
        && $response_body['code'] === 'success';

    if (!$accepted) {
        set_transient($retry_transient, '1', 7 * DAY_IN_SECONDS);
        return;
    }

    add_option($sent_option, time(), '', false);
    delete_transient($retry_transient);
}
add_action('admin_init', 'qilingshop_maybe_send_telemetry', 3);

/**
 * 注册未授权状态下唯一保留的商城后台入口。
 */
function qilingshop_register_license_page() {
    if (qilingshop_is_authorized() || !current_user_can('manage_options')) {
        return;
    }

    add_menu_page(
        __('启灵商城授权', 'qilingshop'),
        __('启灵商城', 'qilingshop'),
        'manage_options',
        'qilingshop-license',
        'qilingshop_render_license_page',
        'dashicons-lock',
        30
    );
}
add_action('admin_menu', 'qilingshop_register_license_page');

/**
 * 输出商城未授权页面。
 */
function qilingshop_render_license_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('您没有权限访问此页面。', 'qilingshop'));
    }

    $theme_available = class_exists('\\Developer_Starter\\Core\\Theme_License');
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('启灵商城正版授权', 'qilingshop'); ?></h1>
        <div class="notice notice-error inline">
            <p><strong><?php esc_html_e('启灵商城当前未授权，商城业务功能已停止加载。', 'qilingshop'); ?></strong></p>
            <p>
                <?php
                echo esc_html(
                    $theme_available
                        ? __('商城与启灵主题共用授权，请先完成启灵主题正版授权。', 'qilingshop')
                        : __('未检测到启灵主题授权组件，请先启用启灵主题并完成正版授权。', 'qilingshop')
                );
                ?>
            </p>
        </div>
        <?php if ($theme_available) : ?>
            <p><a class="button button-primary" href="<?php echo esc_url(qilingshop_get_license_url()); ?>"><?php esc_html_e('前往正版授权', 'qilingshop'); ?></a></p>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * 数据表前缀
 */
define('QILINGSHOP_TABLE_PREFIX', 'qilingshop_');

/**
 * 定义数据库表名到 $wpdb 属性
 */
function qilingshop_define_tables() {
    global $wpdb;
    
    // 用户积分信息表
    $wpdb->qilingshop_user_info = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'user_info';
    
    // 积分流水表
    $wpdb->qilingshop_points_log = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'points_log';

    // 积分资产批次表（有效期 / 冻结）
    $wpdb->qilingshop_points_assets = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'points_assets';
    
    // 订单表
    $wpdb->qilingshop_orders = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'orders';
    
    // 充值表
    $wpdb->qilingshop_recharge = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'recharge';
    
    // VIP 等级表
    $wpdb->qilingshop_vip_levels = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'vip_levels';
    
    // VIP 升级记录表
    $wpdb->qilingshop_vip_log = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'vip_log';
    
    // 推广佣金表
    $wpdb->qilingshop_affiliate = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'affiliate';
    
    // 邀请关系表
    $wpdb->qilingshop_invites = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'invites';
    
    // 下载记录表
    $wpdb->qilingshop_downloads = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'downloads';
    
    // 游客订单表
    $wpdb->qilingshop_guest_orders = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'guest_orders';
    
    // 签到记录表
    $wpdb->qilingshop_checkins = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'checkins';
    
    // 充值奖励规则表
    $wpdb->qilingshop_recharge_bonus = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'recharge_bonus';
    
    // 作者提成流水表
    $wpdb->qilingshop_author_commissions = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'author_commissions';

    // 注册码表
    $wpdb->qilingshop_registration_codes = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'registration_codes';

    // 注册码使用日志表
    $wpdb->qilingshop_registration_code_logs = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'registration_code_logs';

    // VIP 兑换码表
    $wpdb->qilingshop_vip_codes = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'vip_codes';

    // VIP 兑换码使用日志表
    $wpdb->qilingshop_vip_code_logs = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'vip_code_logs';

    // 成长体系等级表
    $wpdb->qilingshop_growth_levels = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'growth_levels';

    // 用户成长账户表
    $wpdb->qilingshop_user_growth = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'user_growth';

    // 成长值流水表
    $wpdb->qilingshop_growth_log = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'growth_log';

    // 成长等级权益表
    $wpdb->qilingshop_growth_benefits = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'growth_benefits';

    // 成长值来源规则表
    $wpdb->qilingshop_growth_rules = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'growth_rules';
}
add_action('init', 'qilingshop_define_tables', 0);
add_action('switch_blog', 'qilingshop_define_tables', 0);

// 立即定义一次（用于激活时）
qilingshop_define_tables();

/**
 * 加载插件文本域
 */
function qilingshop_get_forced_locale() {
    $locale = (string) get_option('qilingshop_locale', '');
    $supported_locales = ['zh_CN', 'en_US'];

    return in_array($locale, $supported_locales, true) ? $locale : '';
}

function qilingshop_filter_plugin_locale($locale, $domain) {
    if ($domain !== 'qilingshop') {
        return $locale;
    }

    $forced_locale = qilingshop_get_forced_locale();
    return $forced_locale !== '' ? $forced_locale : $locale;
}
add_filter('plugin_locale', 'qilingshop_filter_plugin_locale', 10, 2);

function qilingshop_filter_textdomain_mofile($mofile, $domain) {
    if ($domain !== 'qilingshop') {
        return $mofile;
    }

    $forced_locale = qilingshop_get_forced_locale();
    if ($forced_locale === '') {
        return $mofile;
    }

    $forced_mofile = QILINGSHOP_PATH . 'lang/qilingshop-' . $forced_locale . '.mo';
    return file_exists($forced_mofile) ? $forced_mofile : $mofile;
}
add_filter('load_textdomain_mofile', 'qilingshop_filter_textdomain_mofile', 10, 2);

function qilingshop_load_textdomain() {
    $forced_locale = qilingshop_get_forced_locale();
    if ($forced_locale !== '') {
        $mofile = QILINGSHOP_PATH . 'lang/qilingshop-' . $forced_locale . '.mo';
        if (file_exists($mofile)) {
            unload_textdomain('qilingshop');
            load_textdomain('qilingshop', $mofile);
            return;
        }
    }

    load_plugin_textdomain(
        'qilingshop',
        false,
        dirname(QILINGSHOP_BASENAME) . '/lang'
    );
}
add_action('init', 'qilingshop_load_textdomain', 0);

/**
 * 自动加载类文件
 */
spl_autoload_register(function ($class) {
    // 只处理以 QilingShop_ 开头的类
    if (strpos($class, 'QilingShop_') !== 0) {
        return;
    }
    
    // 将类名转换为文件名
    $class_name = str_replace('QilingShop_', '', $class);
    $class_name = str_replace('_', '-', strtolower($class_name));
    
    // 定义可能的文件路径
    $paths = [
        QILINGSHOP_PATH . 'includes/class-qilingshop-' . $class_name . '.php',
        QILINGSHOP_PATH . 'admin/class-qilingshop-' . $class_name . '.php',
        QILINGSHOP_PATH . 'public/class-qilingshop-' . $class_name . '.php',
    ];
    
    foreach ($paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            return;
        }
    }
});

/**
 * 记录插件启动阶段缺失的类、函数或文件。
 *
 * @param string $symbol 类名、函数名或文件路径。
 * @param string $type   symbol 类型。
 */
function qilingshop_log_missing_bootstrap_symbol($symbol, $type = 'class') {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf('[qilingshop] Missing %s during bootstrap: %s', $type, $symbol));
    }
}

/**
 * 加载可降级的模块文件。
 *
 * @param string $relative_file 相对插件目录的文件路径。
 * @return bool
 */
function qilingshop_require_optional_file($relative_file) {
    $path = QILINGSHOP_PATH . ltrim($relative_file, '/');

    if (file_exists($path)) {
        require_once $path;
        return true;
    }

    qilingshop_log_missing_bootstrap_symbol($relative_file, 'file');
    return false;
}

/**
 * 加载核心文件
 */
require_once QILINGSHOP_PATH . 'includes/class-qilingshop-loader.php';
require_once QILINGSHOP_PATH . 'includes/class-qilingshop-i18n.php';
require_once QILINGSHOP_PATH . 'includes/class-qilingshop-database.php';
require_once QILINGSHOP_PATH . 'includes/class-qilingshop-security.php';
require_once QILINGSHOP_PATH . 'includes/class-qilingshop-risk-control.php';
require_once QILINGSHOP_PATH . 'includes/class-qilingshop-growth.php';
require_once QILINGSHOP_PATH . 'includes/class-qilingshop-growth-benefits.php';
require_once QILINGSHOP_PATH . 'includes/class-qilingshop-growth-rules.php';
require_once QILINGSHOP_PATH . 'includes/class-qilingshop-registration-code.php';
require_once QILINGSHOP_PATH . 'includes/class-qls-shop-page-manager.php';
require_once QILINGSHOP_PATH . 'includes/class-qilingshop-activator.php';
require_once QILINGSHOP_PATH . 'includes/class-qilingshop-deactivator.php';
require_once QILINGSHOP_PATH . 'includes/helpers.php';

// 业务逻辑类
require_once QILINGSHOP_PATH . 'includes/class-qilingshop-points.php';
require_once QILINGSHOP_PATH . 'includes/class-qilingshop-vip.php';
require_once QILINGSHOP_PATH . 'includes/class-qilingshop-order.php';
require_once QILINGSHOP_PATH . 'includes/class-qilingshop-affiliate.php';
require_once QILINGSHOP_PATH . 'includes/class-qilingshop-resource.php';
require_once QILINGSHOP_PATH . 'includes/class-qilingshop-recharge.php';
require_once QILINGSHOP_PATH . 'includes/class-qilingshop-guest.php';
require_once QILINGSHOP_PATH . 'includes/class-qilingshop-payment.php';
require_once QILINGSHOP_PATH . 'includes/class-qilingshop-rest-api.php';
require_once QILINGSHOP_PATH . 'includes/class-qilingshop-author-commission.php';
require_once QILINGSHOP_PATH . 'includes/class-qilingshop-pancheck.php';
qilingshop_require_optional_file('includes/class-qilingshop-notifier.php');
qilingshop_require_optional_file('includes/class-qilingshop-trade-feed.php');
qilingshop_require_optional_file('includes/class-qilingshop-task-center.php');
qilingshop_require_optional_file('includes/class-qilingshop-vip-code.php');

// 小程序联动（独立目录，便于和商城主流程隔离维护）
qilingshop_require_optional_file('miniapp/class-qilingshop-miniapp.php');

// 电商模块核心类
require_once QILINGSHOP_PATH . 'includes/shop/class-qls-shop-database.php';
require_once QILINGSHOP_PATH . 'includes/shop/class-qls-shop-activator.php';
require_once QILINGSHOP_PATH . 'includes/shop/class-qls-product.php';
require_once QILINGSHOP_PATH . 'includes/shop/class-qls-category.php';
require_once QILINGSHOP_PATH . 'includes/shop/class-qls-cart.php';
require_once QILINGSHOP_PATH . 'includes/shop/class-qls-shop-order.php';
require_once QILINGSHOP_PATH . 'includes/shop/class-qls-shipping-company.php';
require_once QILINGSHOP_PATH . 'includes/shop/class-qls-shipment.php';
require_once QILINGSHOP_PATH . 'includes/shop/class-qls-waybill.php';
require_once QILINGSHOP_PATH . 'includes/shop/class-qls-invoice.php';
require_once QILINGSHOP_PATH . 'includes/shop/class-qls-shipping.php';
require_once QILINGSHOP_PATH . 'includes/shop/class-qls-coupon.php';
require_once QILINGSHOP_PATH . 'includes/shop/class-qls-review.php';
require_once QILINGSHOP_PATH . 'includes/shop/class-qls-card-inventory.php';
require_once QILINGSHOP_PATH . 'includes/shop/class-qls-group.php';
require_once QILINGSHOP_PATH . 'includes/shop/class-qls-group-cron.php';
require_once QILINGSHOP_PATH . 'includes/shop/class-qls-shop-refund.php';
require_once QILINGSHOP_PATH . 'includes/shop/class-qls-shop-ticket.php';
require_once QILINGSHOP_PATH . 'includes/shop/class-qls-assist.php';
require_once QILINGSHOP_PATH . 'includes/shop/class-qls-blocks.php';

// 后台管理类
if (is_admin()) {
    require_once QILINGSHOP_PATH . 'admin/class-qilingshop-admin.php';
    require_once QILINGSHOP_PATH . 'admin/class-qilingshop-admin-settings.php';
    require_once QILINGSHOP_PATH . 'admin/class-qilingshop-admin-orders.php';
    require_once QILINGSHOP_PATH . 'admin/class-qilingshop-admin-users.php';
    require_once QILINGSHOP_PATH . 'admin/class-qilingshop-admin-resource-bulk.php';
    require_once QILINGSHOP_PATH . 'admin/class-qilingshop-admin-statistics.php';
    require_once QILINGSHOP_PATH . 'admin/class-qilingshop-admin-growth.php';
    require_once QILINGSHOP_PATH . 'admin/class-qilingshop-admin-registration-codes.php';
    require_once QILINGSHOP_PATH . 'admin/class-qilingshop-metabox.php';
    
    // 电商后台管理
    require_once QILINGSHOP_PATH . 'admin/shop/class-qls-admin-shop.php';
}

// 前台类
require_once QILINGSHOP_PATH . 'public/class-qilingshop-public.php';
require_once QILINGSHOP_PATH . 'public/class-qilingshop-shortcodes.php';
require_once QILINGSHOP_PATH . 'public/class-qilingshop-ajax.php';
require_once QILINGSHOP_PATH . 'public/class-qilingshop-theme-integration.php';
qilingshop_require_optional_file('public/class-qilingshop-submit-integration.php');

// 电商前台类
require_once QILINGSHOP_PATH . 'public/shop/class-qls-shop-public.php';
require_once QILINGSHOP_PATH . 'public/shop/class-qls-shop-shortcodes.php';
require_once QILINGSHOP_PATH . 'public/shop/class-qls-shop-ajax.php';
require_once QILINGSHOP_PATH . 'public/shop/class-qls-coupon-ajax.php';
require_once QILINGSHOP_PATH . 'public/shop/class-qls-review-ajax.php';
require_once QILINGSHOP_PATH . 'public/shop/class-qls-group-public.php';

/**
 * 自动启用 VIP 补差价升级（仅执行一次）
 */
add_action('admin_init', function() {
    if (!qilingshop_is_authorized() || !current_user_can('manage_options')) {
        return;
    }
    $flag = get_option('qilingshop_vip_diff_upgrade_autoset', false);
    if (!$flag) {
        update_option('qilingshop_vip_diff_upgrade', true);
        update_option('qilingshop_vip_diff_upgrade_autoset', 1);
    }
});

/**
 * 插件激活钩子
 */
register_activation_hook(__FILE__, ['QilingShop_Activator', 'activate']);

/**
 * 电商模块激活钩子
 */
register_activation_hook(__FILE__, ['QLS_Shop_Activator', 'activate']);

// 数据库升级检查
add_action('init', ['QilingShop_Activator', 'maybe_upgrade'], 5);
add_action('init', ['QLS_Shop_Activator', 'maybe_upgrade'], 5);

/**
 * 插件停用钩子
 */
register_deactivation_hook(__FILE__, ['QilingShop_Deactivator', 'deactivate']);

/**
 * 安全初始化单例类。
 *
 * @param string $class_name 类名。
 * @return object|null
 */
function qilingshop_bootstrap_instance($class_name) {
    if (!qilingshop_is_authorized()) {
        return null;
    }

    if (!class_exists($class_name)) {
        qilingshop_log_missing_bootstrap_symbol($class_name, 'class');
        return null;
    }

    if (!method_exists($class_name, 'instance')) {
        qilingshop_log_missing_bootstrap_symbol($class_name . '::instance', 'method');
        return null;
    }

    return call_user_func([$class_name, 'instance']);
}

/**
 * 安全初始化函数式模块。
 *
 * @param string $function_name 函数名。
 * @return mixed|null
 */
function qilingshop_bootstrap_function($function_name) {
    if (!qilingshop_is_authorized()) {
        return null;
    }

    if (!function_exists($function_name)) {
        qilingshop_log_missing_bootstrap_symbol($function_name, 'function');
        return null;
    }

    return call_user_func($function_name);
}

/**
 * 初始化插件
 */
function qilingshop_init() {
    if (!qilingshop_is_authorized()) {
        return;
    }

    // 检查推广链接
    if (isset($_REQUEST['aff']) && !empty($_REQUEST['aff'])) {
        $aff_code = sanitize_text_field($_REQUEST['aff']);
        if (function_exists('qilingshop_set_cookie')) {
            qilingshop_set_cookie('qilingshop_aff', $aff_code, time() + 2592000, '/'); // 30天有效
        } elseif (!headers_sent()) {
            setcookie('qilingshop_aff', $aff_code, time() + 2592000, '/', '', is_ssl(), true);
        }
        $_COOKIE['qilingshop_aff'] = $aff_code;
    }
    
    // 初始化各个模块
    $modules = [
        'QilingShop_Points',
        'QilingShop_VIP',
        'QilingShop_Order',
        'QilingShop_Affiliate',
        'QilingShop_Resource',
        'QilingShop_Recharge',
        'QilingShop_Guest',
        'QilingShop_Payment',
        'QilingShop_REST_API',
        'QilingShop_Author_Commission',
        'QilingShop_Growth',
        'QilingShop_Growth_Benefits',
        'QilingShop_Growth_Rules',
        'QilingShop_Registration_Code',
        'QilingShop_VIP_Code',
        'QilingShop_Notifier',
        'QilingShop_Trade_Feed',
        'QilingShop_Task_Center',
        'QLS_Blocks',
        // 小程序联动初始化：用于向 qilingminiapp 暴露商城开关等能力。
        'QilingShop_Miniapp',
    ];

    foreach ($modules as $module_class) {
        qilingshop_bootstrap_instance($module_class);
    }
    
    // 初始化前台
    qilingshop_bootstrap_instance('QilingShop_Public');
    qilingshop_bootstrap_instance('QilingShop_Shortcodes');
    qilingshop_bootstrap_instance('QilingShop_Ajax');
    
    // 初始化主题集成（在init阶段，此时主题函数已可用）
    add_action('init', function() {
        if (function_exists('developer_starter_get_option')) {
            qilingshop_bootstrap_instance('QilingShop_Theme_Integration');
            qilingshop_bootstrap_instance('QilingShop_Submit_Integration');
        }
    }, 5);
    
    // 初始化后台
    if (is_admin()) {
        qilingshop_bootstrap_instance('QilingShop_Admin');
        qilingshop_bootstrap_instance('QLS_Admin_Shop');
    }
    
    // 初始化电商模块
    qilingshop_bootstrap_function('qls_shop_public');
    qilingshop_bootstrap_function('qls_shop_shortcodes');
    qilingshop_bootstrap_function('qls_shop_ajax');
    qilingshop_bootstrap_function('qls_coupon_ajax');
    qilingshop_bootstrap_function('qls_review_ajax');
    qilingshop_bootstrap_function('qls_shop_ticket');
    
    // 初始化团购模块
    qilingshop_bootstrap_function('qls_group');
    qilingshop_bootstrap_function('qls_group_cron');
    qilingshop_bootstrap_function('qls_group_public');
    qilingshop_bootstrap_function('qls_assist');
    
    /**
     * 插件初始化完成钩子
     * 
     * @since 1.0.0
     */
    do_action('qilingshop_init');
}
add_action('init', 'qilingshop_init', 2);

/**
 * 注册小工具
 */
function qilingshop_register_widgets() {
    if (!qilingshop_is_authorized()) {
        return;
    }

    require_once QILINGSHOP_PATH . 'inc/widgets/class-widget-download-box.php';
    register_widget('QilingShop_Widget_Download_Box');
}
add_action('widgets_init', 'qilingshop_register_widgets');

/**
 * 添加插件设置链接
 */
function qilingshop_plugin_action_links($links, $file) {
    if ($file === QILINGSHOP_BASENAME) {
        $is_authorized = qilingshop_is_authorized();
        $settings_url = $is_authorized
            ? admin_url('admin.php?page=qilingshop-settings')
            : admin_url('admin.php?page=qilingshop-license');
        $settings_label = $is_authorized ? __('设置', 'qilingshop') : __('正版授权', 'qilingshop');
        $settings_link = '<a href="' . esc_url($settings_url) . '">' . esc_html($settings_label) . '</a>';
        array_unshift($links, $settings_link);
        
        $docs_link = '<a href="https://www.wujiit.com/qishop" target="_blank">' . 
                     __('文档', 'qilingshop') . '</a>';
        $links[] = $docs_link;
    }
    return $links;
}
add_filter('plugin_action_links', 'qilingshop_plugin_action_links', 10, 2);

/**
 * 获取插件实例（方便外部访问）
 * 
 * @return object|null 已授权时返回包含公共模块的对象，否则返回 null。
 */
function qilingshop() {
    if (!qilingshop_is_authorized()) {
        return null;
    }

    return (object) [
        'points'    => qilingshop_bootstrap_instance('QilingShop_Points'),
        'vip'       => qilingshop_bootstrap_instance('QilingShop_VIP'),
        'order'     => qilingshop_bootstrap_instance('QilingShop_Order'),
        'affiliate' => qilingshop_bootstrap_instance('QilingShop_Affiliate'),
        'resource'  => qilingshop_bootstrap_instance('QilingShop_Resource'),
        'recharge'  => qilingshop_bootstrap_instance('QilingShop_Recharge'),
        'guest'     => qilingshop_bootstrap_instance('QilingShop_Guest'),
        'payment'   => qilingshop_bootstrap_instance('QilingShop_Payment'),
    ];
}

