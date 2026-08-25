<?php
/**
 * 前台公共类
 */
if (!defined('ABSPATH')) exit;

class QilingShop_Public {
    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('the_content', [$this, 'filter_content'], 99);
        // 注册短代码，用于部分内容付费查看
        add_shortcode('qls_content', [$this, 'shortcode_qls_content']);
        // 注册 VIP 落地页短代码
        add_shortcode('qilingshop_vip_landing', [$this, 'shortcode_vip_landing']);
        // 处理独立下载页面请求
        add_action('template_redirect', [$this, 'handle_download_page'], 0);
        add_filter('redirect_canonical', [$this, 'disable_canonical_for_download_page'], 0, 2);
        // VIP 落地页全屏模板支持
        add_filter('template_include', [$this, 'load_vip_template']);
        
        // ========== 邀请系统 ==========
        // 捕获邀请参数并存入 Cookie
        add_action('init', [$this, 'qilingshop_capture_invite_code'], 5);
        // 用户注册时建立邀请关系
        add_action('user_register', [$this, 'qilingshop_handle_invite_on_register'], 10, 1);
    }

    /**
     * 获取个人中心标签页地址
     *
     * @param string $tab 标签页标识
     * @return string
     */
    private function get_account_tab_url($tab = '') {
        if (function_exists('developer_starter_get_frontend_account_tab_url')) {
            return (string) developer_starter_get_frontend_account_tab_url($tab);
        }

        $account_url = '';

        $account_page_id = (int) get_option('developer_starter_account_page_id', 0);
        if ($account_page_id > 0 && get_post_status($account_page_id) === 'publish') {
            $account_url = get_permalink($account_page_id);
        }

        if ($account_url === '') {
            $pages = get_pages([
                'meta_key'   => '_wp_page_template',
                'meta_value' => 'templates/template-account.php',
                'number'     => 1,
            ]);
            if (!empty($pages)) {
                $account_url = get_permalink($pages[0]->ID);
            }
        }

        if ($account_url === '') {
            $account_url = function_exists('developer_starter_build_raw_home_url')
                ? developer_starter_build_raw_home_url('/user')
                : home_url('/user');
        }

        $tab = sanitize_key((string) $tab);
        if ($tab !== '') {
            return add_query_arg('tab', $tab, $account_url);
        }

        return $account_url;
    }

    /**
     * 判断当前请求是否为独立下载页请求。
     *
     * @return bool
     */
    private function is_download_page_request() {
        if (!isset($_GET['qls_download'])) {
            return false;
        }

        return '1' === (string) wp_unslash($_GET['qls_download']);
    }

    /**
     * 构建独立下载页 URL。
     *
     * 优先挂在文章 permalink 下，避免使用首页 query 参数时被 canonical
     * 或主题首页跳转规则误修正回首页。
     *
     * @param int $post_id 文章 ID。
     * @return string
     */
    private function get_download_page_url($post_id) {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return '';
        }

        $base_url = '';
        if (function_exists('developer_starter_get_post_url_for_frontend_lang')) {
            $base_url = (string) developer_starter_get_post_url_for_frontend_lang($post_id);
        }

        if ($base_url === '') {
            $base_url = (string) get_permalink($post_id);
        }

        if ($base_url !== '' && function_exists('developer_starter_translate_internal_url_for_frontend_lang')) {
            $base_url = (string) developer_starter_translate_internal_url_for_frontend_lang($base_url);
        }

        if (!$base_url) {
            $base_url = home_url('/');
        }

        return add_query_arg([
            'qls_download' => 1,
            'post_id'      => $post_id,
        ], $base_url);
    }

    /**
     * 独立下载页请求命中时，关闭 WordPress canonical 跳转。
     *
     * 否则某些环境会把 `?qls_download=1` 修正回首页或正文页。
     *
     * @param string|false $redirect_url 目标 canonical 地址。
     * @param string       $requested_url 当前请求地址。
     * @return string|false
     */
    public function disable_canonical_for_download_page($redirect_url, $requested_url) {
        unset($requested_url);

        if (is_admin() || wp_doing_ajax()) {
            return $redirect_url;
        }

        if ($this->is_download_page_request()) {
            return false;
        }

        return $redirect_url;
    }

    /**
     * 加载 VIP 落地页全屏模板
     */
    public function load_vip_template($template) {
        $vip_page_id = get_option('qilingshop_page_vip_landing');
        if ($vip_page_id && is_page($vip_page_id)) {
            // 隐藏主题默认的面包屑头部
            add_filter('qiling_show_page_header', '__return_false');
            
            // 使用自定义全屏模板
            $new_template = QILINGSHOP_PATH . 'templates/vip-landing.php';
            if (file_exists($new_template)) {
                return $new_template;
            }
        }
        return $template;
    }
    
    /**
     * 处理独立下载页面请求
     */
    public function handle_download_page() {
        if (!$this->is_download_page_request()) {
            return;
        }

        $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;
        if ($post_id && !qilingshop_points_resource_enabled($post_id)) {
            if ($post_id) {
                wp_safe_redirect(get_permalink($post_id));
            } else {
                wp_safe_redirect(home_url('/'));
            }
            exit;
        }
        
        $template_path = QILINGSHOP_PATH . 'templates/download-page.php';
        if (file_exists($template_path)) {
            include $template_path;
            exit;
        }
    }

    public function enqueue_assets() {
        // 智能判断是否需要加载通用资源
        $should_load = false;
        
        // 1. VIP 落地页
        $vip_page_id = get_option('qilingshop_page_vip_landing');
        if ($vip_page_id && is_page($vip_page_id)) {
            $should_load = true;
        }

        // 1.1 订单查询页
        $order_query_page_id = (int) get_option('qls_shop_page_order_query', 0);
        if ($order_query_page_id <= 0) {
            $order_query_page_id = (int) get_option('qilingshop_page_order_query', 0);
        }
        if ($order_query_page_id > 0 && is_page($order_query_page_id)) {
            $should_load = true;
        }
        
        // 2. 独立下载页
        if ($this->is_download_page_request()) {
            $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;
            if ($post_id && qilingshop_points_resource_enabled($post_id)) {
                $should_load = true;
            }
        }

        // 2.1 任意页面中的订单查询短代码
        if (is_singular()) {
            global $post;
            if ($post && has_shortcode((string) $post->post_content, 'qilingshop_order_query')) {
                $should_load = true;
            }
        }
        
        // 3. 包含特定资源数据的文章页面
        if (is_singular()) {
             global $post;
             if ($post) {
                 if (qilingshop_points_resource_enabled($post->ID)) {
                     $resource = QilingShop_Resource::instance();
                     // 检查是否有价格、下载链接、查看模式或短代码
                     $price_download = $resource->get_points_price($post->ID, 'download');
                     $price_view = $resource->get_points_price($post->ID, 'view');
                     if ($price_download > 0 || 
                         $price_view > 0 ||
                         $resource->has_download_urls($post->ID) || 
                         $resource->get_sale_mode($post->ID) == 'view' ||
                         has_shortcode($post->post_content, 'qls_content')) {
                         $should_load = true;
                     }
                 }
             }
        }
        
        if ($should_load) {
            $assets_version = qilingshop_get_assets_version();

            wp_enqueue_style(
                'qilingshop-public-shop',
                QILINGSHOP_URL . 'static/css/qilingshop-public-shop.css',
                [],
                $assets_version
            );

            $download_box_position = (string) get_option('qilingshop_download_box_position', 'bottom');
            $load_points_css = !in_array($download_box_position, ['sidebar', 'title'], true);
            $load_widgets_css = in_array($download_box_position, ['sidebar', 'title', 'top_sidebar', 'bottom_sidebar'], true);
            $public_style_handles = ['qilingshop-public-shop'];

            if ($load_points_css) {
                wp_enqueue_style(
                    'qilingshop-public-points',
                    QILINGSHOP_URL . 'static/css/qilingshop-public-points.css',
                    ['qilingshop-public-shop'],
                    $assets_version
                );
                $public_style_handles[] = 'qilingshop-public-points';
            }

            if ($load_widgets_css) {
                wp_enqueue_style(
                    'qilingshop-public-widgets',
                    QILINGSHOP_URL . 'static/css/qilingshop-public-widgets.css',
                    ['qilingshop-public-shop'],
                    $assets_version
                );
                $public_style_handles[] = 'qilingshop-public-widgets';
            }

            // 兼容旧句柄：允许第三方继续依赖 qilingshop-public 样式句柄。
            wp_register_style('qilingshop-public', false, $public_style_handles, $assets_version);
            wp_enqueue_style('qilingshop-public');

            wp_enqueue_script(
                'qilingshop-public',
                QILINGSHOP_URL . 'static/js/qilingshop-public.js',
                ['jquery'],
                $assets_version,
                true
            );

            $gateway_labels = [];
            if (class_exists('QilingShop_Payment')) {
                $enabled_gateways = QilingShop_Payment::instance()->get_enabled_gateways();
                foreach ($enabled_gateways as $gateway_key => $gateway_conf) {
                    if ($gateway_key === 'wechat_miniapp') {
                        continue;
                    }

                    if ($gateway_key === 'alipay') {
                        $alipay_f2f_enabled = (bool) get_option('qilingshop_alipay_f2fpay');
                        if ($alipay_f2f_enabled) {
                            $gateway_labels['alipay_qr'] = __('支付宝扫码支付', 'qilingshop');
                            $gateway_labels['alipay_page'] = __('支付宝网页支付', 'qilingshop');
                        } else {
                            $gateway_labels['alipay'] = !empty($gateway_conf['name']) ? $gateway_conf['name'] : __('支付宝', 'qilingshop');
                        }
                        continue;
                    }

                    if (!empty($gateway_conf['name'])) {
                        $gateway_labels[$gateway_key] = $gateway_conf['name'];
                    }
                }
            }

            wp_localize_script('qilingshop-public', 'qilingshop', [
                'ajaxUrl'    => admin_url('admin-ajax.php'),
                'nonce'      => wp_create_nonce('qilingshop_ajax'),
                'isLoggedIn' => is_user_logged_in(),
                'loginUrl'   => wp_login_url(get_permalink()),
                'pluginUrl'  => QILINGSHOP_URL,
                'debug'      => (bool) apply_filters('qilingshop_frontend_debug', defined('WP_DEBUG') && WP_DEBUG),
                'gateways'   => $gateway_labels,
                'pointsName' => qilingshop_get_points_name(),
                'couponNonce' => wp_create_nonce('qls_coupon_nonce'), // 优惠券API nonce
                'freeDownloadWait' => get_option('qilingshop_free_download_wait', 0),
                'paidDownloadWait' => get_option('qilingshop_paid_download_wait', 0),
                'i18n'       => [
                    'loading'      => __('加载中...', 'qilingshop'),
                    'success'      => __('操作成功', 'qilingshop'),
                    'error'        => __('操作失败', 'qilingshop'),
                    'confirm'      => __('确定要购买吗？', 'qilingshop'),
                    'loginRequired'=> __('请先登录', 'qilingshop'),
                    'processing'   => __('处理中...', 'qilingshop'),
                    'download'     => __('点击下载', 'qilingshop'),
                    'userLogin'    => __('用户登录', 'qilingshop'),
                    'invalidDownloadLink' => __('下载链接无效', 'qilingshop'),
                    'fetching' => __('获取中...', 'qilingshop'),
                    'downloadUrlFailed' => __('获取下载地址失败', 'qilingshop'),
                    'preparingResource' => __('正在为您准备资源...', 'qilingshop'),
                    'pleaseWaitSeconds' => __('请耐心等待 {seconds} 秒', 'qilingshop'),
                    'downloadPreparing' => __('下载准备中...', 'qilingshop'),
                    'downloadNow' => __('立即下载', 'qilingshop'),
                    'invalidLink' => __('链接无效', 'qilingshop'),
                    'checking' => __('检测中...', 'qilingshop'),
                    'linkValid' => __('链接正常', 'qilingshop'),
                    'linkInvalid' => __('链接失效', 'qilingshop'),
                    'checkCompleted' => __('检测完成', 'qilingshop'),
                    'checkFailed' => __('检测失败', 'qilingshop'),
                    'enterOrderContact' => __('请输入订单号和手机号/邮箱', 'qilingshop'),
                    'enterOrderLookupCredentials' => __('请输入手机号/邮箱，并填写订单号或查询密码', 'qilingshop'),
                    'querying' => __('查询中...', 'qilingshop'),
                    'queryingWait' => __('正在查询，请稍候...', 'qilingshop'),
                    'queryOrder' => __('查询订单', 'qilingshop'),
                    'queryFailedRetry' => __('查询失败，请稍后重试', 'qilingshop'),
                    'orderType' => __('订单类型', 'qilingshop'),
                    'orderNo' => __('订单号', 'qilingshop'),
                    'orderStatus' => __('订单状态', 'qilingshop'),
                    'orderAmount' => __('订单金额', 'qilingshop'),
                    'createdAt' => __('下单时间', 'qilingshop'),
                    'paidAt' => __('支付时间', 'qilingshop'),
                    'orderContent' => __('订单内容', 'qilingshop'),
                    'scopeLabel' => __('权益范围', 'qilingshop'),
                    'queryResult' => __('查询结果', 'qilingshop'),
                    'viewOrderDetail' => __('查看订单详情', 'qilingshop'),
                    'virtualInfo' => __('虚拟商品信息', 'qilingshop'),
                    'virtualProduct' => __('虚拟商品', 'qilingshop'),
                    'virtualContent' => __('虚拟内容', 'qilingshop'),
                    'typeLabel' => __('类型：', 'qilingshop'),
                    'noVirtualContent' => __('暂无虚拟内容', 'qilingshop'),
                    'copyAll' => __('一键复制', 'qilingshop'),
                    'nothingToCopy' => __('暂无可复制内容', 'qilingshop'),
                    'copyFailedSelectManual' => __('复制失败，请手动选择内容复制', 'qilingshop'),
                    'copied' => __('已复制', 'qilingshop'),
                    'pointsDeduction' => __('将扣除 {price} {pointsName}', 'qilingshop'),
                    'pointsName' => __('积分', 'qilingshop'),
                    'purchaseSuccess' => __('购买成功', 'qilingshop'),
                    'confirmPurchase' => __('确认购买', 'qilingshop'),
                    'cancel' => __('取消', 'qilingshop'),
                    'guestBindingFailed' => __('游客订单关联失败，请在当前设备完成支付', 'qilingshop'),
                    'paymentRequestFailed' => __('支付请求失败', 'qilingshop'),
                    'gatewayAlipayQr' => __('支付宝扫码支付', 'qilingshop'),
                    'gatewayAlipayPage' => __('支付宝网页支付', 'qilingshop'),
                    'gatewayWechat' => __('微信扫码支付', 'qilingshop'),
                    'gatewayXhpay' => __('虎皮椒 V3', 'qilingshop'),
                    'gatewayEpay' => __('易支付', 'qilingshop'),
                    'orderQueryVerification' => __('订单查询验证', 'qilingshop'),
                    'guestContactDesc' => __('游客购买请填写手机号或邮箱，后续可在订单查询页查状态', 'qilingshop'),
                    'phoneOptional' => __('手机号（可选）', 'qilingshop'),
                    'emailOptional' => __('邮箱（可选）', 'qilingshop'),
                    'confirmOrder' => __('确认订单', 'qilingshop'),
                    'coupon' => __('优惠券', 'qilingshop'),
                    'remove' => __('移除', 'qilingshop'),
                    'paymentMethod' => __('支付方式', 'qilingshop'),
                    'productAmount' => __('商品金额', 'qilingshop'),
                    'finalAmount' => __('实付金额', 'qilingshop'),
                    'payNowAmount' => __('立即支付 ¥{amount}', 'qilingshop'),
                    'free' => __('免费', 'qilingshop'),
                    'getNow' => __('立即获取', 'qilingshop'),
                    'fixedCoupon' => __('满减券', 'qilingshop'),
                    'discountCoupon' => __('折扣券', 'qilingshop'),
                    'couponMinAmount' => __('满¥{amount}可用', 'qilingshop'),
                    'noThreshold' => __('无门槛', 'qilingshop'),
                    'noCoupons' => __('暂无可用优惠券', 'qilingshop'),
                    'guestContactRequired' => __('游客购买请填写手机号或邮箱', 'qilingshop'),
                    'checkedIn' => __('已签到', 'qilingshop'),
                    'enterRechargeAmount' => __('请输入充值金额', 'qilingshop'),
                    'rechargeSuccess' => __('充值成功', 'qilingshop'),
                    'scanToPay' => __('请扫码支付', 'qilingshop'),
                    'close' => __('关闭', 'qilingshop'),
                ],
            ]);
        }

        // 如果是 VIP 落地页，加载专属资源
        $vip_page_id = get_option('qilingshop_page_vip_landing');
        if ($vip_page_id && is_page($vip_page_id)) {
            wp_enqueue_style(
                'qilingshop-vip-landing',
                QILINGSHOP_URL . 'static/css/vip-landing.css',
                [],
                qilingshop_get_assets_version()
            );
            
            wp_enqueue_script(
                'qilingshop-vip-landing',
                QILINGSHOP_URL . 'static/js/vip-landing.js',
                ['jquery'],
                qilingshop_get_assets_version(),
                true
            );

            // 获取用户数据
            $user_id = get_current_user_id();
            $user_balance = $user_id ? QilingShop_Points::instance()->get_balance($user_id) : 0;
            
            // 传递数据给 JS
            wp_localize_script('qilingshop-vip-landing', 'qlsVipLanding', [
                'ajaxUrl'      => admin_url('admin-ajax.php'),
                'nonce'        => wp_create_nonce('qilingshop_ajax'),
                'isLoggedIn'   => is_user_logged_in(),
                'loginUrl'     => wp_login_url(get_permalink()),
                'vipCenterUrl' => $this->get_account_tab_url('qls-vip'),
                'userBalance'  => $user_balance,
                'pointsRatio'  => qilingshop_get_points_ratio(),
                'pointsName'   => qilingshop_get_points_name(),
                'currencySymbol'=> get_option('qilingshop_currency_symbol', '¥'),
                'i18n'         => [
                    'confirm' => __('确认支付', 'qilingshop'),
                    'processing' => __('处理中...', 'qilingshop'),
                    'success' => __('操作成功', 'qilingshop'),
                    'error' => __('操作失败', 'qilingshop'),
                    'balanceInsufficient' => __('余额不足（需要 %s %s）', 'qilingshop'),
                    'selectPayment' => __('请选择支付方式', 'qilingshop'),
                    'contactAdmin' => __('请联系管理员开通此VIP等级', 'qilingshop'),
                    'loginRequired' => __('请先登录', 'qilingshop'),
                    'or' => __('或', 'qilingshop'),
                    'balance' => __('余额', 'qilingshop'),
                    'free' => __('免费', 'qilingshop'),
                    'noPayment' => __('无需支付', 'qilingshop'),
                    'origin' => __('原价', 'qilingshop'),
                ]
            ]);
        }

        // 交易播报（全站右下角）
        if (get_option('qilingshop_trade_feed_enabled', false)) {
            $interval_min = max(2, min((int) get_option('qilingshop_trade_feed_interval_min', 4), 60));
            $interval_max = max(2, min((int) get_option('qilingshop_trade_feed_interval_max', 8), 60));
            if ($interval_max < $interval_min) {
                $interval_max = $interval_min;
            }

            $batch_size = max(5, min((int) get_option('qilingshop_trade_feed_batch_size', 20), 50));

            wp_enqueue_style(
                'qilingshop-trade-feed',
                QILINGSHOP_URL . 'static/css/trade-feed.css',
                [],
                qilingshop_get_assets_version()
            );

            wp_enqueue_script(
                'qilingshop-trade-feed',
                QILINGSHOP_URL . 'static/js/trade-feed.js',
                ['jquery'],
                qilingshop_get_assets_version(),
                true
            );

            wp_localize_script('qilingshop-trade-feed', 'qilingshopTradeFeed', [
                'enabled' => true,
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('qilingshop_ajax'),
                'batchSize' => $batch_size,
                'intervalMin' => $interval_min,
                'intervalMax' => $interval_max,
            ]);
        }
    }

    /**
     * 短代码处理（只是简单的占位，实际逻辑在 filter_content 处理）
     * 这里主要是为了防止短代码直接输出，或者在未处理时被 WP 解析
     */
    public function shortcode_qls_content($atts, $content = null) {
        if (is_singular()) {
            global $post;
            if ($post && !qilingshop_points_resource_enabled($post->ID)) {
                return $content;
            }
        }
        if (!is_singular()) {
            return $content;
        }
        return '<!--qls_content_start-->' . $content . '<!--qls_content_end-->';
    }

    /**
     * 过滤内容 - 添加购买框与付费查看逻辑
     */
    public function filter_content($content) {
        if (!is_singular()) return $content;
        
        global $post;
        if (!qilingshop_points_resource_enabled($post->ID)) return $content;
        $post_types = qilingshop_normalize_resource_post_types(get_option('qilingshop_post_types', ['post']), ['post']);
        
        if (!in_array($post->post_type, (array)$post_types)) {
            // 如果不在支持的类型中，但只要有短代码，也应该处理（可选）
            return $content;
        }

        $resource = QilingShop_Resource::instance();
        // 获取价格和销售模式
        $price_view = $resource->get_points_price($post->ID, 'view');
        $price_download = $resource->get_points_price($post->ID, 'download');
        $price_view_rmb = $resource->get_rmb_price($post->ID, 'view');
        $price_download_rmb = $resource->get_rmb_price($post->ID, 'download');
        $sale_mode = $resource->get_sale_mode($post->ID);

        // 检查是否有短代码 [qls_content]
        $has_shortcode = (strpos($content, '[qls_content]') !== false) || (strpos($content, '<!--qls_content_start-->') !== false);
        $user_id = get_current_user_id();
        $login_free_price = ($sale_mode === 'view') ? $price_view : $price_download;
        $login_free_price_rmb = ($sale_mode === 'view') ? $price_view_rmb : $price_download_rmb;
        $is_login_free = ($sale_mode !== 'free' && $user_id && $login_free_price <= 0 && $login_free_price_rmb <= 0);
        $context_view = 'view';
        $context_download = 'download';
        $vip_block_view = false;
        $vip_block_download = false;
        $vip_block_view = $resource->is_vip_only_access($post->ID, $context_view) && (!$user_id || !$resource->has_vip_access($post->ID, $user_id, $context_view));
        $vip_block_download = $resource->is_vip_only_access($post->ID, $context_download) && (!$user_id || !$resource->has_vip_access($post->ID, $user_id, $context_download));
        $has_view_purchase = $user_id ? QilingShop_Order::instance()->user_has_purchased($post->ID, $user_id, false, 'view') : false;
        $has_download_purchase = $user_id ? QilingShop_Order::instance()->user_has_purchased($post->ID, $user_id, false, 'download') : false;
        $guest_has_purchased = false;
        $guest_has_download = false;
        if (!$user_id && QilingShop_Guest::instance()->is_enabled()) {
            $guest_id = QilingShop_Guest::instance()->get_guest_id();
            $guest_has_purchased = QilingShop_Order::instance()->guest_has_purchased($post->ID, $guest_id, 'view');
            $guest_has_download = QilingShop_Order::instance()->guest_has_purchased($post->ID, $guest_id, 'download');
        }
        $can_view = false;
        if (!$vip_block_view) {
            if ($sale_mode === 'free') {
                $can_view = true;
            } elseif ($price_view <= 0 && $price_view_rmb <= 0 && $user_id) {
                $can_view = true;
            } elseif ($has_view_purchase || $guest_has_purchased) {
                $can_view = true;
            } elseif ($user_id && $resource->is_vip_free($post->ID, $user_id, $context_view)) {
                $can_view = true;
            }
        }
        $can_download = false;
        if (!$vip_block_download) {
            if ($sale_mode === 'free') {
                $can_download = true;
            } elseif ($price_download <= 0 && $price_download_rmb <= 0 && $user_id) {
                $can_download = true;
            } elseif ($has_download_purchase || $guest_has_download) {
                $can_download = true;
            } elseif ($user_id && $resource->is_vip_free($post->ID, $user_id, $context_download)) {
                $can_download = true;
            }
        }

        /**
         * 修复：如果没有资源数据，则不显示任何内容
         * 判读依据：
         * 1. 价格 <= 0
         * 2. 无下载链接
         * 3. 模式不是付费查看 (view)
         * 4. 模式不是免费资源 (free)
         * 5. 无短代码
         * 6. 无服务标签
         */
        $service_tags = get_post_meta($post->ID, '_qilingshop_service_tags', true);
        $price_any = max($price_view, $price_download, $price_view_rmb, $price_download_rmb);
        
        if ($price_any <= 0 && 
            !$resource->has_download_urls($post->ID) && 
            $sale_mode !== 'view' && 
            $sale_mode !== 'free' && 
            !$has_shortcode && 
            empty($service_tags)
        ) {
            return $content;
        }

        // 如果既不是付费查看模式，也没有短代码，且是免费资源，直接返回（带下载框）
        if (($sale_mode !== 'view' && !$has_shortcode) && ($sale_mode === 'free' || $is_login_free)) {
            if ($resource->has_download_urls($post->ID) && $can_download) {
                $position = get_option('qilingshop_download_box_position', 'bottom');
                if ($position === 'sidebar' || $position === 'title') return $content;
                
                $download_box = $this->render_download_box($post->ID, true);
                if ($position === 'top' || $position === 'top_sidebar') {
                    $content = $download_box . $content;
                } else {
                    $content .= $download_box;
                }
            }
            return $content;
        }

        if ($can_view) {
            // 【已购买】
            // 1. 如果有短代码标记，移除标记并保留内容。
            if ($has_shortcode) {
                $content = str_replace(['<!--qls_content_start-->', '<!--qls_content_end-->'], '', $content);
                // 确保短代码已被展开
                $content = do_shortcode($content);
            }
            
            // 2. 如果是 view 模式，显示隐藏内容（如果有设置独立隐藏内容的话）
            if ($sale_mode === 'view') {
                $hidden = $resource->get_hidden_content($post->ID);
                if ($hidden) {
                    $content .= '<div class="qilingshop-hidden-content">' . wp_kses_post($hidden) . '</div>';
                }
            }

            // 3. 显示下载地址
            if ($resource->has_download_urls($post->ID)) {
                $position = get_option('qilingshop_download_box_position', 'bottom');
                if ($position !== 'sidebar' && $position !== 'title') {
                    if ($can_download) {
                        $download_box = $this->render_download_box($post->ID, true);
                        if ($position === 'top' || $position === 'top_sidebar') {
                            $content = $download_box . $content;
                        } else {
                            $content .= $download_box;
                        }
                    } else {
                        $buy_box = $this->render_buy_box($post->ID, 'content', 'download');
                        if ($position === 'top' || $position === 'top_sidebar') {
                            $content = $buy_box . $content;
                        } else {
                            $content .= $buy_box;
                        }
                    }
                }
            }
        } else {
            // 【未购买】
            $buy_box = $this->render_buy_box($post->ID, 'content', ($sale_mode === 'view' ? 'view' : 'download'));
            $position = get_option('qilingshop_download_box_position', 'bottom');

            if ($has_shortcode) {
                // 情况 A：部分付费（有短代码）- 替换短代码内容为购买框
                // 使用更精确的正则，兼容短代码已解析成 HTML 标记的情况。
                // 同时兼容原始 [qls_content] 包裹内容。
                
                // 尝试正则替换未解析的短代码
                $pattern = '/\[qls_content\](.*?)\[\/qls_content\]/is';
                if (preg_match($pattern, $content)) {
                    $content = preg_replace($pattern, $position === 'title' ? '' : $buy_box, $content);
                } 
                // 尝试替换已解析的标记 (如果 WP 先执行了 do_shortcode)
                elseif (strpos($content, '<!--qls_content_start-->') !== false) {
                    $pattern = '/<!--qls_content_start-->(.*?)<!--qls_content_end-->/is';
                    $content = preg_replace($pattern, $position === 'title' ? '' : $buy_box, $content);
                }

            } elseif ($sale_mode === 'view') {
                // 情况 B：全局付费（无短代码，但在 view 模式下）- 隐藏全文，只显示购买框
                // 此时隐藏原内容，只返回购买框。
                // 后续如需展示摘要，可在这里扩展。
                $content = $position === 'title' ? '' : $buy_box;
            } else {
                 // 情况 C：普通下载付费（download 模式）- 显示全文 + 购买框
                 if ($position !== 'sidebar' && $position !== 'title') {
                     if ($position === 'top' || $position === 'top_sidebar') {
                         $content = $buy_box . $content;
                     } else {
                         $content .= $buy_box;
                     }
                 }
            }
        }

        return $content;
    }

    /**
     * 渲染购买框
     */
    public function render_buy_box($post_id, $location = 'content', $target_scope = '') {
        if (!qilingshop_points_resource_enabled($post_id)) {
            return '';
        }
        $user_id = get_current_user_id();
        $resource = QilingShop_Resource::instance();
        $sale_mode = $resource->get_sale_mode($post_id);
        if ($sale_mode === 'free') {
            return '';
        }
        if (!in_array($target_scope, ['view', 'download'], true)) {
            $target_scope = $sale_mode === 'view' ? 'view' : 'download';
        }
        $context = $target_scope;
        $vip_only_purchase = $resource->is_vip_only_purchase($post_id, $context);
        $vip_only_access = $resource->is_vip_only_access($post_id, $context);
        $has_scope_purchase = false;
        if ($user_id) {
            $has_scope_purchase = QilingShop_Order::instance()->user_has_purchased($post_id, $user_id, false, $context);
        }
        $need_vip_access = $vip_only_access && (!$user_id || !$resource->has_vip_access($post_id, $user_id, $context));
        $need_vip_purchase = $vip_only_purchase && (!$user_id || !$resource->has_vip_access($post_id, $user_id, $context)) && !$has_scope_purchase;

        if ($need_vip_access) {
            // VIP 仅限访问：强约束提示
            if ($location === 'sidebar') {
                $prefix = 'qls-download-sidebar-re-';
            } elseif ($location === 'title') {
                $prefix = 'qls-download-title-hd-';
            } else {
                $prefix = 'qls-download-content-tb-';
            }
            $box_style = get_option('qilingshop_buy_box_style', 'fresh');
            $style_class = 'style-' . $box_style;
            $vip_name = get_option('qilingshop_vip_name', '');
            $vip_btn_text = $vip_name ? sprintf(__('开通%s', 'qilingshop'), $vip_name) : __('开通会员', 'qilingshop');

            ob_start();
            ?>
            <div class="<?php echo $prefix; ?>wrap <?php echo esc_attr($style_class); ?> <?php echo $prefix; ?>vip-only-access">
                <div class="<?php echo $prefix; ?>header">
                    <span class="<?php echo $prefix; ?>badge">👑 <?php _e('VIP专属访问', 'qilingshop'); ?></span>
                </div>
                <div class="<?php echo $prefix; ?>body">
                    <div class="<?php echo $prefix; ?>vip-only-panel">
                        <div class="<?php echo $prefix; ?>vip-only-badge">
                            <span class="<?php echo $prefix; ?>vip-only-badge-icon">★</span>
                            <span class="<?php echo $prefix; ?>vip-only-badge-text"><?php _e('VIP 专属访问', 'qilingshop'); ?></span>
                        </div>
                        <div class="<?php echo $prefix; ?>vip-only-title"><?php _e('当前资源仅限 VIP 用户访问', 'qilingshop'); ?></div>
                        <div class="<?php echo $prefix; ?>vip-only-desc"><?php _e('包含已购用户，非 VIP 将被拦截', 'qilingshop'); ?></div>
                        <div class="<?php echo $prefix; ?>vip-only-actions">
                            <?php if ($user_id): ?>
                                <a href="<?php echo esc_url($this->get_account_tab_url('qls-vip')); ?>" class="<?php echo $prefix; ?>btn-vip">
                                    <?php echo esc_html($vip_btn_text); ?>
                                </a>
                            <?php else: ?>
                                <a href="<?php echo esc_url(wp_login_url($this->get_account_tab_url('qls-vip'))); ?>" class="<?php echo $prefix; ?>btn-vip qls-login-trigger">
                                    <?php _e('登录后开通', 'qilingshop'); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

        if ($need_vip_purchase) {
            // 确定类名前缀
            if ($location === 'sidebar') {
                $prefix = 'qls-download-sidebar-re-';
            } elseif ($location === 'title') {
                $prefix = 'qls-download-title-hd-';
            } else {
                $prefix = 'qls-download-content-tb-';
            }
            $box_style = get_option('qilingshop_buy_box_style', 'fresh');
            $style_class = 'style-' . $box_style;
            $vip_name = get_option('qilingshop_vip_name', '');
            $vip_btn_text = $vip_name ? sprintf(__('开通%s', 'qilingshop'), $vip_name) : __('开通会员', 'qilingshop');
            $vip_only_text = $need_vip_access ? __('该资源仅限 VIP 访问', 'qilingshop') : __('该资源仅限 VIP 购买', 'qilingshop');

            ob_start();
            ?>
            <div class="<?php echo $prefix; ?>wrap <?php echo esc_attr($style_class); ?>">
                <div class="<?php echo $prefix; ?>header">
                    <span class="<?php echo $prefix; ?>badge">👑 <?php _e('VIP专属', 'qilingshop'); ?></span>
                </div>
                <div class="<?php echo $prefix; ?>body">
                    <div class="<?php echo $prefix; ?>login-only">
                        <div class="<?php echo $prefix; ?>login-text"><?php echo esc_html($vip_only_text); ?></div>
                        <?php if ($user_id): ?>
                            <a href="<?php echo esc_url($this->get_account_tab_url('qls-vip')); ?>" class="<?php echo $prefix; ?>btn-vip">
                                <?php echo esc_html($vip_btn_text); ?>
                            </a>
                        <?php else: ?>
                            <a href="<?php echo wp_login_url(get_permalink($post_id)); ?>" class="<?php echo $prefix; ?>btn-login qls-login-trigger">
                                <?php _e('登录后开通', 'qilingshop'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }
        
        // 检查是否有权下载（已购买、免费、VIP免费、价格为0且已登录）
        // 如果有权下载且有下载链接，直接渲染下载框
        if ($resource->has_download_urls($post_id)) {
            $can_download = false;
            
            // 1. 检查是否免费或价格为0
            $price_info_download = $resource->get_price($post_id, $user_id, 'download');
            
            if ($sale_mode === 'free') {
                $can_download = true;
            } elseif ($price_info_download['points'] <= 0 && $user_id) {
                // 价格为0且已登录
                $can_download = true;
            }
            
            // 2. 检查由 QilingShop_Order 判断的已购买状态
            if (!$can_download) {
                if (QilingShop_Order::instance()->user_has_purchased($post_id, $user_id, false, 'download')) {
                    $can_download = true;
                }
            }
            
            // 3. 检查 VIP 免费
            if (!$can_download && $user_id) {
                if ($resource->is_vip_free($post_id, $user_id, 'download')) {
                    $can_download = true;
                }
            }

            // 如果判断可以下载，则直接返回下载框
            if ($can_download) {
                return $this->render_download_box($post_id, true, $location);
            }
        }

        // --- 以下为原 Buy Box 渲染逻辑 ---

        $purchase_scope = $context;
        $upgrade_from = '';
        if ($user_id && $context === 'download') {
            $user_scope = QilingShop_Order::instance()->get_user_purchase_scope($post_id, $user_id);
            if ($user_scope === 'view') {
                $upgrade_from = 'view';
            }
        }

        $price_info = $resource->get_price($post_id, $user_id, $context); // Re-fetch to be safe/clean
        if ($upgrade_from === 'view') {
            $view_price = $resource->get_price($post_id, $user_id, 'view');
            $price_info['points'] = max(0, $price_info['points'] - $view_price['points']);
            $price_info['rmb'] = max(0, $price_info['rmb'] - $view_price['rmb']);
            $price_info['original'] = max(0, $price_info['original'] - $view_price['original']);
        }
        $points_name = qilingshop_get_points_name();
        $login_only = (!$user_id && $sale_mode !== 'free' && $price_info['points'] <= 0);
        if (get_option('qilingshop_resource_price_login_required', false) && !$user_id && $sale_mode !== 'free') {
            $login_only = true;
        }
        
        // 确定类名前缀
        if ($location === 'sidebar') {
            $prefix = 'qls-download-sidebar-re-';
        } elseif ($location === 'title') {
            $prefix = 'qls-download-title-hd-';
        } else {
            $prefix = 'qls-download-content-tb-';
        }
        
        // 获取所有VIP等级的价格信息
        $vip_levels = QilingShop_VIP::instance()->get_levels(true);
        $vip_price_list = [];
        $original_points = $resource->get_points_price($post_id, $context);
        
        foreach ($vip_levels as $level) {
            $discount = $resource->get_vip_discount_by_level($post_id, $level->id, $context);
            $level_price = round($original_points * $discount / 100, 2);
            if ($level_price > $original_points) {
                $level_price = $original_points;
            }
            
            $vip_price_list[] = [
                'name'     => $level->level_name,
                'price'    => $level_price,
                'discount' => $discount
            ];
        }

        // 根据销售模式选择提示文案
        if ($sale_mode === 'view') {
            $tips = get_option('qilingshop_tips_view', '');
        } else {
            $tips = get_option('qilingshop_tips_download_pay', '');
        }
        
        // 销售数量
        $sales_count = $resource->get_download_count($post_id); 
        
        // 检查用户VIP状态
        $is_vip = false;
        if ($user_id) {
            $user_level = QilingShop_VIP::instance()->get_user_level($user_id);
            if ($user_level > 0) {
                $is_vip = true;
            }
        }
        
        // VIP名称设置
        $vip_name = get_option('qilingshop_vip_name', '');
        $vip_btn_text = $vip_name ? sprintf(__('开通%s', 'qilingshop'), $vip_name) : __('开通会员', 'qilingshop');

        // ...
        $badge_text = get_option('qilingshop_buy_box_badge_text', __('付费资源', 'qilingshop'));
        $show_sales_count = get_option('qilingshop_show_sales_count', false);
        $box_style = get_option('qilingshop_buy_box_style', 'fresh');
        $style_class = 'style-' . $box_style;

        ob_start();
        ?>
        <div class="<?php echo $prefix; ?>wrap <?php echo esc_attr($style_class); ?>" data-post-id="<?php echo $post_id; ?>" data-rmb="<?php echo esc_attr($price_info['rmb']); ?>" data-scope="<?php echo esc_attr($purchase_scope); ?>" data-upgrade-from="<?php echo esc_attr($upgrade_from); ?>" data-download-index="<?php echo esc_attr($purchase_scope === 'download' ? QilingShop_Order::DOWNLOAD_ALL_INDEX : 0); ?>">
            
            <div class="<?php echo $prefix; ?>header">
                <span class="<?php echo $prefix; ?>badge">⬇ <?php echo esc_html($badge_text); ?></span>
                <div class="<?php echo $prefix; ?>header-right" style="display:flex;align-items:center;gap:10px;">
                    <?php if ($user_id && isset($price_info['discount']) && $price_info['discount'] < 100): ?>
                         <span class="<?php echo $prefix; ?>user-discount-tag"><?php _e('已享VIP优惠', 'qilingshop'); ?></span>
                    <?php endif; ?>
                    
                    <?php if ($show_sales_count): ?>
                         <span class="<?php echo $prefix; ?>sales text-sm"><?php printf(__('已售 %d', 'qilingshop'), (int)$sales_count); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="<?php echo $prefix; ?>body">
                <?php if ($login_only): ?>
                    <div class="<?php echo $prefix; ?>login-only">
                        <div class="<?php echo $prefix; ?>login-text"><?php echo esc_html(get_option('qilingshop_guest_login_text', __('请先登录', 'qilingshop'))); ?></div>
                        <a href="<?php echo wp_login_url(get_permalink($post_id)); ?>" class="<?php echo $prefix; ?>btn-login qls-login-trigger">
                            <?php _e('立即登录', 'qilingshop'); ?>
                        </a>
                    </div>
                <?php else: ?>

                <!-- Main Price -->
                <div class="<?php echo $prefix; ?>price-area">
                    <span class="<?php echo $prefix; ?>price-label"><?php _e('需要', 'qilingshop'); ?></span>
                    <?php
                    if ($upgrade_from === 'view') {
                        $scope_tag_text = __('补差价', 'qilingshop');
                        $scope_tag_class = 'is-upgrade';
                    } elseif ($purchase_scope === 'view') {
                        $scope_tag_text = __('仅查看', 'qilingshop');
                        $scope_tag_class = 'is-view';
                    } else {
                        $scope_tag_text = __('含下载', 'qilingshop');
                        $scope_tag_class = 'is-download';
                    }
                    ?>
                    <span class="<?php echo $prefix; ?>scope-tag <?php echo esc_attr($scope_tag_class); ?>"><?php echo esc_html($scope_tag_text); ?></span>
                    <span class="<?php echo $prefix; ?>price-val"><?php echo qilingshop_format_points($price_info['points']); ?></span>
                </div>
                <?php if ($upgrade_from === 'view'): ?>
                <div class="<?php echo $prefix; ?>tips">
                    <?php _e('已购买查看，补差价可下载', 'qilingshop'); ?>
                </div>
                <div class="<?php echo $prefix; ?>tips">
                    <?php _e('升级后授权：下载（含查看）', 'qilingshop'); ?>
                </div>
                <?php else: ?>
                <div class="<?php echo $prefix; ?>tips">
                    <?php echo $context === 'view' ? esc_html(__('授权范围：仅查看', 'qilingshop')) : esc_html(__('授权范围：下载（含查看）', 'qilingshop')); ?>
                </div>
                <?php endif; ?>

                <!-- VIP Grid -->
                <?php if (!empty($vip_price_list)): ?>
                <div class="<?php echo $prefix; ?>vip-grid">
                    <?php foreach ($vip_price_list as $vip): ?>
                    <div class="<?php echo $prefix; ?>vip-item">
                        <div class="<?php echo $prefix; ?>vip-name">
                            <span class="<?php echo $prefix; ?>vip-icon">👑</span>
                            <span class="<?php echo $prefix; ?>vip-name-text"><?php echo esc_html($vip['name']); ?></span>
                        </div>
                        <div class="<?php echo $prefix; ?>vip-price">
                            <?php echo $vip['price'] > 0 ? $vip['price'] : __('免费', 'qilingshop'); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ($tips): ?>
                <div class="<?php echo $prefix; ?>tips">
                    <?php echo wp_kses_post($tips); ?>
                </div>
                <?php endif; ?>

                <!-- Action Buttons -->
                <div class="<?php echo $prefix; ?>actions">
                <?php if ($user_id): 
                    $user_balance = qilingshop_get_user_points();
                    $has_enough = $user_balance >= $price_info['points'];
                ?>
                    <!-- Balance Info -->
                    <div class="<?php echo $prefix; ?>balance-row">
                        <span class="<?php echo $prefix; ?>balance-text"><?php printf(__('当前余额：%s', 'qilingshop'), qilingshop_format_points($user_balance)); ?></span>
                    </div>

                    <div class="<?php echo $prefix; ?>btn-group">
                        <?php if ($has_enough): ?>
                            <button class="<?php echo $prefix; ?>btn-buy" data-action="buy_with_points" data-price="<?php echo $price_info['points']; ?>">
                                <?php
                                if ($upgrade_from === 'view') {
                                    echo sprintf(__('补差价下载 (%s)', 'qilingshop'), $points_name);
                                } elseif ($purchase_scope === 'view') {
                                    echo sprintf(__('立即购买查看 (%s)', 'qilingshop'), $points_name);
                                } else {
                                    echo sprintf(__('立即购买下载 (%s)', 'qilingshop'), $points_name);
                                }
                                ?>
                            </button>
                        <?php else: ?>
                            <button class="<?php echo $prefix; ?>btn-buy disabled" disabled>
                                <?php echo sprintf(__('余额不足 (%s)', 'qilingshop'), $points_name); ?>
                            </button>
                            <a href="<?php echo esc_url($this->get_account_tab_url('qls-shop')); ?>" class="<?php echo $prefix; ?>btn-recharge"><?php _e('去充值', 'qilingshop'); ?></a>
                        <?php endif; ?>
                        
                        <?php if (get_option('qilingshop_direct_pay_enabled', true) && $price_info['rmb'] > 0): ?>
                            <button class="<?php echo $prefix; ?>btn-pay" data-action="direct_pay">
                                <?php
                                if ($upgrade_from === 'view') {
                                    echo sprintf(__('直接支付补差价 %s', 'qilingshop'), qilingshop_format_price($price_info['rmb']));
                                } elseif ($purchase_scope === 'view') {
                                    echo sprintf(__('直接支付查看 %s', 'qilingshop'), qilingshop_format_price($price_info['rmb']));
                                } else {
                                    echo sprintf(__('直接支付下载 %s', 'qilingshop'), qilingshop_format_price($price_info['rmb']));
                                }
                                ?>
                            </button>
                        <?php endif; ?>

                        <?php if (!$is_vip): ?>
                            <a href="<?php echo esc_url($this->get_account_tab_url('qls-vip')); ?>" class="<?php echo $prefix; ?>btn-vip">
                                <?php echo esc_html($vip_btn_text); ?>
                            </a>
                        <?php endif; ?>
                    </div>

                <?php else: ?>
                    <!-- Guest View -->
                     <div class="<?php echo $prefix; ?>btn-group">
                        <?php if (QilingShop_Guest::instance()->is_enabled() && get_option('qilingshop_direct_pay_enabled', true) && $price_info['rmb'] > 0): ?>
                            <button class="<?php echo $prefix; ?>btn-pay" data-action="direct_pay">
                                <?php
                                $guest_action = $purchase_scope === 'view' ? __('免登录支付查看 %s', 'qilingshop') : __('免登录支付下载 %s', 'qilingshop');
                                echo sprintf($guest_action, qilingshop_format_price($price_info['rmb']));
                                ?>
                            </button>
                        <?php endif; ?>
                        
                        <a href="<?php echo wp_login_url(get_permalink($post_id)); ?>" class="<?php echo $prefix; ?>btn-login qls-login-trigger">
                            <?php echo $purchase_scope === 'view' ? __('登录后购买查看', 'qilingshop') : __('登录后购买下载', 'qilingshop'); ?>
                        </a>
                     </div>
                <?php endif; ?>
                </div>

                <?php 
                // Service Tags
                $service_tags_str = get_post_meta($post_id, '_qilingshop_service_tags', true);
                if (!empty($service_tags_str)):
                    $stags = explode(',', $service_tags_str);
                ?>
                <div class="<?php echo $prefix; ?>service-tags">
                    <?php foreach ($stags as $stag): 
                        $stag = trim($stag);
                        if (empty($stag)) continue;
                    ?>
                    <span class="qls-service-tag"><span class="icon">✓</span> <?php echo esc_html($stag); ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * 渲染下载框
     */
    public function render_download_box($post_id, $show_urls = false, $location = 'content') {
        if (!qilingshop_points_resource_enabled($post_id)) {
            return '';
        }
        $urls = QilingShop_Resource::instance()->get_download_urls($post_id);
        $security = qilingshop_security();
        
        if (empty($urls)) return '';
        
        // 生成下载页面链接
        $download_page_url = $this->get_download_page_url($post_id);
        
        $url_count = count($urls);
        
        $disable_direct = get_option('qilingshop_disable_direct_download', false);
        
        $box_style = get_option('qilingshop_buy_box_style', 'fresh');
        $style_class = 'style-' . $box_style;

        // 确定类名前缀
        if ($location === 'sidebar') {
            $prefix = 'qls-download-sidebar-re-';
        } elseif ($location === 'title') {
            $prefix = 'qls-download-title-hd-';
        } else {
            $prefix = 'qls-download-content-tb-';
        }

        // 获取资源价格和销售模式，判断是否免费
        $resource = QilingShop_Resource::instance();
        $user_id = get_current_user_id();
        $price = $resource->get_points_price($post_id, 'download');
        $price_rmb = $resource->get_rmb_price($post_id, 'download');
        $price_info = $resource->get_price($post_id, $user_id, 'download');
        $sale_mode = $resource->get_sale_mode($post_id);
        $context = 'download';
        $is_vip_free = $user_id && $resource->is_vip_free($post_id, $user_id, $context);
        $guest_id = (!$user_id && QilingShop_Guest::instance()->is_enabled()) ? QilingShop_Guest::instance()->get_guest_id() : '';
        $is_free = ($sale_mode === 'free' || ($price <= 0 && $price_rmb <= 0));
        $is_paid = !$is_free;
        if ($is_vip_free || $is_free) {
            $is_paid = false;
        }
        $badge_label = $is_vip_free ? __('VIP免费', 'qilingshop') : ($is_free ? __('免费', 'qilingshop') : __('已购买', 'qilingshop'));

        ob_start();
        ?>
        <div class="<?php echo $prefix; ?>wrap <?php echo $prefix; ?>download-box <?php echo esc_attr($style_class); ?>" data-post-id="<?php echo (int) $post_id; ?>" data-scope="download" data-upgrade-from="" data-download-index="<?php echo esc_attr(QilingShop_Order::DOWNLOAD_ALL_INDEX); ?>">
             <div class="<?php echo $prefix; ?>header">
                <span class="<?php echo $prefix; ?>badge success">⬇ <?php echo esc_html($badge_label); ?></span>
            </div>
            
            <div class="<?php echo $prefix; ?>body">
                <div class="<?php echo $prefix; ?>download-list">
                    <?php foreach ($urls as $index => $url): 
                        $download_index = isset($url['index']) ? (int) $url['index'] : (int) $index;
                        $item_can_download = !$is_paid;
                        if (!$item_can_download && $user_id) {
                            $item_can_download = QilingShop_Order::instance()->user_has_purchased($post_id, $user_id, false, 'download', $download_index);
                        }
                        if (!$item_can_download && !$user_id && $guest_id !== '') {
                            $item_can_download = QilingShop_Order::instance()->guest_has_purchased($post_id, $guest_id, 'download', $download_index);
                        }
                        // 加密下载令牌
                        $token = $security->encrypt_download_url($url['url'], $post_id, $download_index);
                    ?>
                    <div class="<?php echo $prefix; ?>download-item">
                        <div class="<?php echo $prefix; ?>download-info">
                            <span class="<?php echo $prefix; ?>download-name"><?php echo esc_html($url['name']); ?></span>
                            <?php if ($item_can_download && !empty($url['code'])): ?>
                            <span class="<?php echo $prefix; ?>download-code">
                                <?php _e('提取码：', 'qilingshop'); ?>
                                <code class="copyable" data-copy="<?php echo esc_attr($url['code']); ?>"><?php echo esc_html($url['code']); ?></code>
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php if (!$item_can_download): ?>
                        <div class="<?php echo $prefix; ?>download-actions">
                            <span class="<?php echo $prefix; ?>download-locked"><?php _e('未购买此下载项', 'qilingshop'); ?></span>
                            <?php if ($user_id && !empty($price_info['points']) && $price_info['points'] > 0): ?>
                            <button type="button" class="<?php echo $prefix; ?>btn-buy" data-action="buy_with_points" data-price="<?php echo esc_attr($price_info['points']); ?>" data-download-index="<?php echo esc_attr($download_index); ?>">
                                <?php _e('购买此项', 'qilingshop'); ?>
                            </button>
                            <?php endif; ?>
                            <?php if (get_option('qilingshop_direct_pay_enabled', true) && !empty($price_info['rmb']) && $price_info['rmb'] > 0): ?>
                            <button type="button" class="<?php echo $prefix; ?>btn-pay" data-action="direct_pay" data-download-index="<?php echo esc_attr($download_index); ?>">
                                <?php echo esc_html(sprintf(__('直接支付 %s', 'qilingshop'), qilingshop_format_price($price_info['rmb']))); ?>
                            </button>
                            <?php endif; ?>
                            <?php if (!$user_id && (!QilingShop_Guest::instance()->is_enabled() || empty($price_info['rmb']))): ?>
                            <a href="<?php echo esc_url(wp_login_url(get_permalink($post_id))); ?>" class="<?php echo $prefix; ?>btn-login qls-login-trigger">
                                <?php _e('登录后购买', 'qilingshop'); ?>
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php elseif ($disable_direct): ?>
                        <a href="<?php echo esc_url($download_page_url); ?>" class="<?php echo $prefix; ?>download-go-btn" target="_blank">
                            <?php _e('前往下载', 'qilingshop'); ?> <span class="arr">&rarr;</span>
                        </a>
                        <?php else: ?>
                        <button type="button" class="<?php echo $prefix; ?>download-go-btn <?php echo $prefix; ?>secure-download" 
                           data-token="<?php echo esc_attr($token); ?>" 
                           data-post-id="<?php echo $post_id; ?>"
                           data-is-paid="<?php echo $is_paid ? 1 : 0; ?>">
                             <?php _e('点击下载', 'qilingshop'); ?> ⬇
                        </button>
                        <?php endif; ?>
                        <?php
                        $pancheck_enabled = get_option('qilingshop_pancheck_enabled', false);
                        $pancheck_supported = $pancheck_enabled && $item_can_download && class_exists('QilingShop_Pancheck') && QilingShop_Pancheck::is_supported($url['url']);
                        ?>
                        <?php if ($pancheck_supported): ?>
                        <button type="button" class="<?php echo $prefix; ?>pancheck-btn"
                                data-post-id="<?php echo esc_attr((int) $post_id); ?>"
                                data-download-index="<?php echo esc_attr($download_index); ?>">
                            <span class="<?php echo $prefix; ?>pancheck-text"><?php _e('检测有效性', 'qilingshop'); ?></span>
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="<?php echo $prefix; ?>footer">
                     <a href="<?php echo esc_url($download_page_url); ?>" class="<?php echo $prefix; ?>link-page-main" target="_blank">
                        <?php _e('前往独立下载页', 'qilingshop'); ?> <span class="arr">&rarr;</span>
                        <span class="sub-text"><?php _e('（查看更多内容）', 'qilingshop'); ?></span>
                    </a>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * VIP 落地页短代码
     */
    public function shortcode_vip_landing() {
        if (is_admin()) {
            return '<div class="qls-shortcode-placeholder" style="padding:20px;background:#f0f0f1;text-align:center;border:1px dashed #ccc;">[qilingshop_vip_landing] ' . __('VIP会员介绍', 'qilingshop') . '</div>';
        }

        // 加载专属资源
        wp_enqueue_style(
            'qilingshop-vip-landing',
            QILINGSHOP_URL . 'static/css/vip-landing.css',
            [],
            qilingshop_get_assets_version()
        );
        
        wp_enqueue_script(
            'qilingshop-vip-landing',
            QILINGSHOP_URL . 'static/js/vip-landing.js',
            ['jquery'],
            qilingshop_get_assets_version(),
            true
        );

        // 获取用户数据
        $user_id = get_current_user_id();
        $user_balance = $user_id ? QilingShop_Points::instance()->get_balance($user_id) : 0;
        
        // 传递数据给 JS
        wp_localize_script('qilingshop-vip-landing', 'qlsVipLanding', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('qilingshop_ajax'), // 使用通用的ajax nonce
            'isLoggedIn'   => is_user_logged_in(),
            'loginUrl'     => wp_login_url(get_permalink()),
            'vipCenterUrl' => $this->get_account_tab_url('qls-vip'),
            'userBalance'  => $user_balance,
            'pointsRatio'  => qilingshop_get_points_ratio(),
            'pointsName'   => qilingshop_get_points_name(),
            'currencySymbol'=> get_option('qilingshop_currency_symbol', '¥'),
            'i18n'         => [
                'confirm' => __('确认支付', 'qilingshop'),
                'processing' => __('处理中...', 'qilingshop'),
                'success' => __('操作成功', 'qilingshop'),
                'error' => __('操作失败', 'qilingshop'),
                'balanceInsufficient' => __('余额不足（需要 %s %s）', 'qilingshop'),
                'selectPayment' => __('请选择支付方式', 'qilingshop'),
                'contactAdmin' => __('请联系管理员开通此VIP等级', 'qilingshop'),
                'loginRequired' => __('请先登录', 'qilingshop'),
                'or' => __('或', 'qilingshop'),
                'balance' => __('余额', 'qilingshop'),
                'free' => __('免费', 'qilingshop'),
                'noPayment' => __('无需支付', 'qilingshop'),
                'origin' => __('原价', 'qilingshop'),
            ]
        ]);

        ob_start();
        $template = QILINGSHOP_PATH . 'templates/vip-landing.php';
        if (file_exists($template)) {
            include $template;
        } else {
            echo '<p>VIP Landing Page Template Not Found.</p>';
        }
        return ob_get_clean();
    }

    // ========== 邀请系统方法 ==========
    
    /**
     * 捕获邀请参数并存入 Cookie
     * 
     * 支持以下 URL 格式：
     * - ?ref=用户ID
     * - ?invite_code=邀请码
     * 
     * Cookie 有效期 30 天，用于注册时建立邀请关系
     * 注意：隐私浏览器可能禁用 Cookie，此时邀请追踪将失效（属于极少数情况）
     */
    public function qilingshop_capture_invite_code() {
        // 后台不处理
        if (is_admin()) {
            return;
        }
        
        // 已登录用户不需要追踪
        if (is_user_logged_in()) {
            return;
        }
        
        $inviter_id = 0;
        
        // 方式1：通过 ref 参数（直接传用户ID）
        if (isset($_GET['ref']) && !empty($_GET['ref'])) {
            $ref_id = intval($_GET['ref']);
            if ($ref_id > 0 && get_userdata($ref_id)) {
                $inviter_id = $ref_id;
            }
        }
        
        // 方式2：通过 invite_code 参数（邀请码）
        if ($inviter_id <= 0 && isset($_GET['invite_code']) && !empty($_GET['invite_code'])) {
            $invite_code = sanitize_text_field($_GET['invite_code']);
            // 通过邀请码查找用户
            if (class_exists('QilingShop_Points')) {
                global $wpdb;
                $table = isset($wpdb->qilingshop_user_info)
                    ? $wpdb->qilingshop_user_info
                    : $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'user_info';
                $found_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT user_id FROM {$table} WHERE invite_code = %s LIMIT 1",
                    $invite_code
                ));
                if ($found_id) {
                    $inviter_id = intval($found_id);
                }
            }
        }
        
        // 找到有效邀请人，设置 Cookie
        if ($inviter_id > 0) {
            $cookie_name = 'qls_inviter_id';
            $expire = time() + (30 * DAY_IN_SECONDS); // 30 天有效期
            $path = COOKIEPATH ?: '/';
            $domain = COOKIE_DOMAIN ?: '';
            
            if (function_exists('qilingshop_set_cookie')) {
                qilingshop_set_cookie($cookie_name, (string) $inviter_id, $expire, $path, $domain, null, true, 'Lax');
            } elseif (!headers_sent()) {
                setcookie($cookie_name, (string) $inviter_id, $expire, $path, $domain, is_ssl(), true);
            }
            $_COOKIE[$cookie_name] = (string) $inviter_id; // 立即生效于当前请求
        }
    }
    
    /**
     * 用户注册时建立邀请关系
     * 
     * 从 Cookie 中读取邀请人 ID，调用 Affiliate 类建立绑定
     * 
     * @param int $new_user_id 新注册用户的ID
     */
    public function qilingshop_handle_invite_on_register($new_user_id) {
        // 检查邀请功能是否启用
        if (!get_option('qilingshop_affiliate_enabled', true)) {
            return;
        }
        
        $cookie_name = 'qls_inviter_id';
        $inviter_id = 0;
        
        // 从 Cookie 读取邀请人 ID
        if (isset($_COOKIE[$cookie_name]) && !empty($_COOKIE[$cookie_name])) {
            $inviter_id = intval($_COOKIE[$cookie_name]);
        }
        
        // 验证邀请人有效性
        if ($inviter_id <= 0 || !get_userdata($inviter_id)) {
            return;
        }
        
        // 不能邀请自己
        if ($inviter_id === $new_user_id) {
            return;
        }
        
        // 调用 Affiliate 类建立邀请关系
        if (class_exists('QilingShop_Affiliate')) {
            $affiliate = QilingShop_Affiliate::instance();
            $result = $affiliate->handle_invite_registration($inviter_id, $new_user_id);
            
            if ($result) {
                // 绑定成功，更新新用户的 inviter_id 字段
                if (class_exists('QilingShop_Database')) {
                    $db = QilingShop_Database::instance();
                    $db->update('user_info', 
                        ['inviter_id' => $inviter_id], 
                        ['user_id' => $new_user_id]
                    );
                }
                
                // 清除 Cookie（可选，防止重复触发）
                if (function_exists('qilingshop_clear_cookie')) {
                    qilingshop_clear_cookie($cookie_name, COOKIEPATH ?: '/', COOKIE_DOMAIN ?: '', null, true, 'Lax');
                } elseif (!headers_sent()) {
                    setcookie($cookie_name, '', time() - 3600, COOKIEPATH ?: '/', COOKIE_DOMAIN ?: '', is_ssl(), true);
                }
            }
        }
    }
}
