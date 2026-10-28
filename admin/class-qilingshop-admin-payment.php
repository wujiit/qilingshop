<?php
/**
 * 后台支付设置类
 */
if (!defined('ABSPATH')) exit;

class QilingShop_Admin_Payment {
    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_init', [$this, 'handle_save']);
    }

    public function handle_save() {
        if (!isset($_POST['qilingshop_save_payment'])) return;
        if (!wp_verify_nonce($_POST['_wpnonce'], 'qilingshop_payment')) return;
        if (!current_user_can('manage_options')) return;

        // 支付接口顺序
        if (isset($_POST['payment_order'])) {
            update_option('qilingshop_payment_order', sanitize_text_field($_POST['payment_order']));
        }

        $shop_refund_mode = sanitize_key((string) ($_POST['shop_refund_mode'] ?? 'withdrawable_balance'));
        if (!in_array($shop_refund_mode, ['withdrawable_balance', 'gateway'], true)) {
            $shop_refund_mode = 'withdrawable_balance';
        }
        update_option('qilingshop_shop_refund_mode', $shop_refund_mode);

        // 支付宝官方接口
        update_option('qilingshop_alipay_enabled', isset($_POST['alipay_enabled']));
        update_option('qilingshop_alipay_type', sanitize_text_field($_POST['alipay_type'] ?? 'new'));
        update_option('qilingshop_alipay_partner', sanitize_text_field($_POST['alipay_partner'] ?? ''));
        update_option('qilingshop_alipay_key', sanitize_text_field($_POST['alipay_key'] ?? ''));
        update_option('qilingshop_alipay_seller', sanitize_text_field($_POST['alipay_seller'] ?? ''));
        update_option('qilingshop_alipay_app_id', sanitize_text_field($_POST['alipay_app_id'] ?? ''));
        update_option('qilingshop_alipay_private_key', sanitize_textarea_field($_POST['alipay_private_key'] ?? ''));
        update_option('qilingshop_alipay_public_key', sanitize_textarea_field($_POST['alipay_public_key'] ?? ''));
        update_option('qilingshop_alipay_h5', isset($_POST['alipay_h5']));

        // 微信支付官方接口
        update_option('qilingshop_wechat_enabled', isset($_POST['wechat_enabled']));
        update_option('qilingshop_wechat_mchid', sanitize_text_field($_POST['wechat_mchid'] ?? ''));
        update_option('qilingshop_wechat_appid', sanitize_text_field($_POST['wechat_appid'] ?? ''));
        update_option('qilingshop_wechat_secret', sanitize_text_field($_POST['wechat_secret'] ?? ''));
        update_option('qilingshop_wechat_key', sanitize_text_field($_POST['wechat_key'] ?? ''));
        update_option('qilingshop_wechat_client_cert', sanitize_textarea_field(wp_unslash($_POST['wechat_client_cert'] ?? '')));
        update_option('qilingshop_wechat_client_key', sanitize_textarea_field(wp_unslash($_POST['wechat_client_key'] ?? '')));
        update_option('qilingshop_wechat_jsapi', isset($_POST['wechat_jsapi']));
        update_option('qilingshop_wechat_h5', isset($_POST['wechat_h5']));

        // 微信小程序支付官方接口（独立于上面的网页/公众号微信支付）
        update_option('qilingshop_wechat_miniapp_enabled', isset($_POST['wechat_miniapp_enabled']));
        update_option('qilingshop_wechat_miniapp_appid', sanitize_text_field($_POST['wechat_miniapp_appid'] ?? ''));
        update_option('qilingshop_wechat_miniapp_mchid', sanitize_text_field($_POST['wechat_miniapp_mchid'] ?? ''));
        update_option('qilingshop_wechat_miniapp_pay_type', sanitize_text_field($_POST['wechat_miniapp_pay_type'] ?? 'v2'));
        update_option('qilingshop_wechat_miniapp_key', sanitize_text_field($_POST['wechat_miniapp_key'] ?? ''));
        update_option('qilingshop_wechat_miniapp_key_v3', sanitize_text_field($_POST['wechat_miniapp_key_v3'] ?? ''));
        update_option('qilingshop_wechat_miniapp_serial_no', sanitize_text_field($_POST['wechat_miniapp_serial_no'] ?? ''));
        update_option('qilingshop_wechat_miniapp_public_key_id', sanitize_text_field($_POST['wechat_miniapp_public_key_id'] ?? ''));
        update_option('qilingshop_wechat_miniapp_transfer_scene_id', sanitize_text_field($_POST['wechat_miniapp_transfer_scene_id'] ?? ''));
        update_option('qilingshop_wechat_miniapp_client_cert', sanitize_textarea_field(wp_unslash($_POST['wechat_miniapp_client_cert'] ?? '')));
        update_option('qilingshop_wechat_miniapp_client_key', sanitize_textarea_field(wp_unslash($_POST['wechat_miniapp_client_key'] ?? '')));
        update_option('qilingshop_wechat_miniapp_public_key_pem', sanitize_textarea_field(wp_unslash($_POST['wechat_miniapp_public_key_pem'] ?? '')));

        // 微信小程序虚拟支付（官方虚拟支付 / 米大师 XPay）
        update_option('qilingshop_wechat_miniapp_xpay_enabled', isset($_POST['wechat_miniapp_xpay_enabled']));
        update_option('qilingshop_wechat_miniapp_xpay_env', intval($_POST['wechat_miniapp_xpay_env'] ?? 0));
        update_option('qilingshop_wechat_miniapp_xpay_offer_id', sanitize_text_field($_POST['wechat_miniapp_xpay_offer_id'] ?? ''));
        update_option('qilingshop_wechat_miniapp_xpay_app_key', sanitize_text_field($_POST['wechat_miniapp_xpay_app_key'] ?? ''));
        update_option('qilingshop_wechat_miniapp_xpay_sandbox_app_key', sanitize_text_field($_POST['wechat_miniapp_xpay_sandbox_app_key'] ?? ''));

        if (isset($_POST['wechat_prop_vip_mappings']) && is_array($_POST['wechat_prop_vip_mappings'])) {
            $mappings = [];
            foreach ($_POST['wechat_prop_vip_mappings'] as $lid => $pid) {
                $mappings[intval($lid)] = sanitize_text_field($pid);
            }
            update_option('qilingshop_wechat_prop_vip_mappings', $mappings);
        }

        // PayPal 接口
        update_option('qilingshop_paypal_enabled', isset($_POST['paypal_enabled']));
        update_option('qilingshop_paypal_client_id', sanitize_text_field($_POST['paypal_client_id'] ?? ''));
        update_option('qilingshop_paypal_client_secret', sanitize_text_field($_POST['paypal_client_secret'] ?? ''));
        update_option('qilingshop_paypal_webhook_id', sanitize_text_field($_POST['paypal_webhook_id'] ?? ''));
        update_option('qilingshop_paypal_rate', floatval($_POST['paypal_rate'] ?? 7));
        update_option('qilingshop_paypal_sandbox', isset($_POST['paypal_sandbox']));

        add_action('admin_notices', function() {
            echo '<div class="qls-admin-message qls-admin-message-success"><p>' . __('支付设置已保存', 'qilingshop') . '</p></div>';
        });
    }

    public function render() {
        ?>
        <div class="wrap qilingshop-admin-page">
            <h1><?php _e('支付设置', 'qilingshop'); ?></h1>
            <form method="post">
                <?php wp_nonce_field('qilingshop_payment'); ?>
                
                <h2><?php _e('支付接口顺序', 'qilingshop'); ?></h2>
                <p><?php echo wp_kses(__('支付宝 <code>alipay</code>、微信支付 <code>wechat</code>、PayPal <code>paypal</code>', 'qilingshop'), ['code' => []]); ?></p>
                <table class="form-table qls-ui-form-table">
                    <tr>
                        <th><?php _e('接口顺序', 'qilingshop'); ?></th>
                        <td>
                            <input type="text" name="payment_order" value="<?php echo esc_attr(get_option('qilingshop_payment_order', 'alipay,wechat,paypal')); ?>" class="regular-text">
                            <p class="description"><?php _e('多个支付标识用半角逗号隔开，例如：alipay,wechat', 'qilingshop'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php _e('退款设置', 'qilingshop'); ?></h2>
                <p><?php _e('保留原有“退款到可提现余额”能力，同时支持第一阶段支付宝/微信原路退款。', 'qilingshop'); ?></p>
                <table class="form-table qls-ui-form-table">
                    <tr>
                        <th><?php _e('商城退款方式', 'qilingshop'); ?></th>
                        <td>
                            <select name="shop_refund_mode">
                                <option value="withdrawable_balance" <?php selected(get_option('qilingshop_shop_refund_mode', 'withdrawable_balance'), 'withdrawable_balance'); ?>><?php _e('退回可提现余额（默认）', 'qilingshop'); ?></option>
                                <option value="gateway" <?php selected(get_option('qilingshop_shop_refund_mode', 'withdrawable_balance'), 'gateway'); ?>><?php _e('原路退回（仅支付宝/微信）', 'qilingshop'); ?></option>
                            </select>
                            <p class="description"><?php _e('只影响商城最终确认退款时的现金退款去向；售后申请、审核、退货流程保持不变。', 'qilingshop'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php if (function_exists('qilingshop_render_shop_refund_diagnostics_panel')) {
                    qilingshop_render_shop_refund_diagnostics_panel();
                } ?>

                <h2>1、<?php _e('支付宝（官方接口）', 'qilingshop'); ?></h2>
                <table class="form-table qls-ui-form-table">
                    <tr>
                        <th><?php _e('启用', 'qilingshop'); ?></th>
                        <td><label><input type="checkbox" name="alipay_enabled" value="1" <?php checked(get_option('qilingshop_alipay_enabled')); ?>> <?php _e('启用支付宝支付', 'qilingshop'); ?></label></td>
                    </tr>
                    <tr>
                        <th><?php _e('接口版本', 'qilingshop'); ?></th>
                        <td>
                            <select name="alipay_type">
                                <option value="old" <?php selected(get_option('qilingshop_alipay_type'), 'old'); ?>><?php _e('老接口（MD5签名）', 'qilingshop'); ?></option>
                                <option value="new" <?php selected(get_option('qilingshop_alipay_type', 'new'), 'new'); ?>><?php _e('新接口（RSA2签名）推荐', 'qilingshop'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr class="alipay-old">
                        <th><?php _e('合作者身份(Partner ID)', 'qilingshop'); ?></th>
                        <td><input type="text" name="alipay_partner" value="<?php echo esc_attr(get_option('qilingshop_alipay_partner')); ?>" class="regular-text"><p class="description"><?php _e('老接口需填写', 'qilingshop'); ?></p></td>
                    </tr>
                    <tr class="alipay-old">
                        <th><?php _e('安全校验码(Key)', 'qilingshop'); ?></th>
                        <td><input type="text" name="alipay_key" value="<?php echo esc_attr(get_option('qilingshop_alipay_key')); ?>" class="regular-text"><p class="description"><?php _e('老接口需填写，MD5密钥', 'qilingshop'); ?></p></td>
                    </tr>
                    <tr class="alipay-old">
                        <th><?php _e('支付宝收款账号', 'qilingshop'); ?></th>
                        <td><input type="text" name="alipay_seller" value="<?php echo esc_attr(get_option('qilingshop_alipay_seller')); ?>" class="regular-text"><p class="description"><?php _e('老接口需填写', 'qilingshop'); ?></p></td>
                    </tr>
                    <tr>
                        <th><?php _e('APPID', 'qilingshop'); ?></th>
                        <td><input type="text" name="alipay_app_id" value="<?php echo esc_attr(get_option('qilingshop_alipay_app_id')); ?>" class="regular-text"><p class="description"><?php _e('新接口与H5支付需填写', 'qilingshop'); ?></p></td>
                    </tr>
                    <tr>
                        <th><?php _e('商户应用私钥', 'qilingshop'); ?></th>
                        <td><textarea name="alipay_private_key" class="large-text" rows="4"><?php echo esc_textarea(get_option('qilingshop_alipay_private_key')); ?></textarea><p class="description"><?php _e('新接口与H5支付需填写', 'qilingshop'); ?></p></td>
                    </tr>
                    <tr>
                        <th><?php _e('支付宝公钥', 'qilingshop'); ?></th>
                        <td><textarea name="alipay_public_key" class="large-text" rows="4"><?php echo esc_textarea(get_option('qilingshop_alipay_public_key')); ?></textarea><p class="description"><?php _e('注意不是应用公钥！', 'qilingshop'); ?></p></td>
                    </tr>
                    <tr>
                        <th><?php _e('启用H5支付', 'qilingshop'); ?></th>
                        <td><label><input type="checkbox" name="alipay_h5" value="1" <?php checked(get_option('qilingshop_alipay_h5')); ?>> <?php _e('手机端H5支付，需申请手机网站支付权限', 'qilingshop'); ?></label></td>
                    </tr>
                </table>

                <h2>2、<?php _e('微信支付（官方接口）', 'qilingshop'); ?></h2>
                <p><?php _e('微信支付--开发配置，设置支付授权目录', 'qilingshop'); ?>：<?php echo esc_url(QILINGSHOP_URL . 'payment/'); ?></p>
                <table class="form-table qls-ui-form-table">
                    <tr>
                        <th><?php _e('启用', 'qilingshop'); ?></th>
                        <td><label><input type="checkbox" name="wechat_enabled" value="1" <?php checked(get_option('qilingshop_wechat_enabled')); ?>> <?php _e('启用微信支付', 'qilingshop'); ?></label></td>
                    </tr>
                    <tr>
                        <th><?php _e('商户号(MCHID)', 'qilingshop'); ?></th>
                        <td><input type="text" name="wechat_mchid" value="<?php echo esc_attr(get_option('qilingshop_wechat_mchid')); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><?php _e('APPID', 'qilingshop'); ?></th>
                        <td><input type="text" name="wechat_appid" value="<?php echo esc_attr(get_option('qilingshop_wechat_appid')); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><?php _e('公众号AppSecret', 'qilingshop'); ?></th>
                        <td><input type="text" name="wechat_secret" value="<?php echo esc_attr(get_option('qilingshop_wechat_secret')); ?>" class="regular-text"><p class="description"><?php _e('非必须，有则填，唤醒JSAPI/H5支付可能需要', 'qilingshop'); ?></p></td>
                    </tr>
                    <tr>
                        <th><?php _e('商户支付密钥(KEY)', 'qilingshop'); ?></th>
                        <td><input type="text" name="wechat_key" value="<?php echo esc_attr(get_option('qilingshop_wechat_key')); ?>" class="regular-text"><p class="description"><?php _e('建议32位随机字符串', 'qilingshop'); ?></p></td>
                    </tr>
                    <tr>
                        <th><?php _e('商户 API 证书', 'qilingshop'); ?></th>
                        <td><textarea name="wechat_client_cert" class="large-text code" rows="6"><?php echo esc_textarea(get_option('qilingshop_wechat_client_cert')); ?></textarea><p class="description"><?php _e('仅用于网页/公众号微信原路退款。可直接粘贴 PEM 内容，或填写服务器可读取的绝对路径/站内文件 URL。', 'qilingshop'); ?></p></td>
                    </tr>
                    <tr>
                        <th><?php _e('商户 API 私钥', 'qilingshop'); ?></th>
                        <td><textarea name="wechat_client_key" class="large-text code" rows="6"><?php echo esc_textarea(get_option('qilingshop_wechat_client_key')); ?></textarea><p class="description"><?php _e('仅用于网页/公众号微信原路退款。可直接粘贴 PEM 内容，或填写服务器可读取的绝对路径/站内文件 URL。', 'qilingshop'); ?></p></td>
                    </tr>
                    <tr>
                        <th><?php _e('启用JSAPI支付', 'qilingshop'); ?></th>
                        <td><label><input type="checkbox" name="wechat_jsapi" value="1" <?php checked(get_option('qilingshop_wechat_jsapi')); ?>> <?php _e('微信内H5支付', 'qilingshop'); ?></label></td>
                    </tr>
                    <tr>
                        <th><?php _e('启用H5支付', 'qilingshop'); ?></th>
                        <td><label><input type="checkbox" name="wechat_h5" value="1" <?php checked(get_option('qilingshop_wechat_h5')); ?>> <?php _e('非微信浏览器H5支付', 'qilingshop'); ?></label></td>
                    </tr>
                </table>

                <h2>3、<?php _e('微信小程序支付（官方接口）', 'qilingshop'); ?></h2>
                <p><?php _e('小程序商城专用支付方式，独立于上方网页/公众号微信支付配置。', 'qilingshop'); ?></p>
                <p><?php _e('请按微信支付商户平台提供的参数填写，支持 v2 / v3 两套配置。', 'qilingshop'); ?></p>
                <p><?php _e('异步通知地址：', 'qilingshop'); ?><code><?php echo esc_html(rest_url('qls/v1/notify/wechat-miniapp')); ?></code></p>
                <table class="form-table qls-ui-form-table">
                    <tr>
                        <th><?php _e('启用', 'qilingshop'); ?></th>
                        <td><label><input type="checkbox" name="wechat_miniapp_enabled" value="1" <?php checked(get_option('qilingshop_wechat_miniapp_enabled')); ?>> <?php _e('启用微信小程序支付', 'qilingshop'); ?></label></td>
                    </tr>
                    <tr>
                        <th><?php _e('小程序 AppID', 'qilingshop'); ?></th>
                        <td><input type="text" name="wechat_miniapp_appid" value="<?php echo esc_attr(get_option('qilingshop_wechat_miniapp_appid')); ?>" class="regular-text"><p class="description"><?php _e('必须与小程序登录获取 openid 的 AppID 一致', 'qilingshop'); ?></p></td>
                    </tr>
                    <tr>
                        <th><?php _e('商户号(MCHID)', 'qilingshop'); ?></th>
                        <td><input type="text" name="wechat_miniapp_mchid" value="<?php echo esc_attr(get_option('qilingshop_wechat_miniapp_mchid')); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><?php _e('接口版本', 'qilingshop'); ?></th>
                        <td>
                            <select name="wechat_miniapp_pay_type" class="qls-miniapp-pay-type">
                                <option value="v2" <?php selected(get_option('qilingshop_wechat_miniapp_pay_type', 'v2'), 'v2'); ?>><?php _e('v2（旧版密钥）', 'qilingshop'); ?></option>
                                <option value="v3" <?php selected(get_option('qilingshop_wechat_miniapp_pay_type', 'v2'), 'v3'); ?>><?php _e('v3（新版密钥）', 'qilingshop'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr class="qls-miniapp-paytype-row qls-miniapp-paytype-v2">
                        <th><?php _e('商户支付密钥(KEY)', 'qilingshop'); ?></th>
                        <td><input type="text" name="wechat_miniapp_key" value="<?php echo esc_attr(get_option('qilingshop_wechat_miniapp_key')); ?>" class="regular-text"><p class="description"><?php _e('微信支付 v2 API 密钥。', 'qilingshop'); ?></p></td>
                    </tr>
                    <tr class="qls-miniapp-paytype-row qls-miniapp-paytype-v3">
                        <th><?php _e('APIv3 密钥', 'qilingshop'); ?></th>
                        <td><input type="text" name="wechat_miniapp_key_v3" value="<?php echo esc_attr(get_option('qilingshop_wechat_miniapp_key_v3')); ?>" class="regular-text"><p class="description"><?php _e('微信支付 APIv3 密钥。', 'qilingshop'); ?></p></td>
                    </tr>
                    <tr class="qls-miniapp-paytype-row qls-miniapp-paytype-v3">
                        <th><?php _e('商户证书序列号', 'qilingshop'); ?></th>
                        <td><input type="text" name="wechat_miniapp_serial_no" value="<?php echo esc_attr(get_option('qilingshop_wechat_miniapp_serial_no')); ?>" class="regular-text"><p class="description"><?php _e('商户 API 证书序列号。', 'qilingshop'); ?></p></td>
                    </tr>
                    <tr class="qls-miniapp-paytype-row qls-miniapp-paytype-v3">
                        <th><?php _e('商户 API 证书', 'qilingshop'); ?></th>
                        <td><textarea name="wechat_miniapp_client_cert" class="large-text code" rows="6"><?php echo esc_textarea(get_option('qilingshop_wechat_miniapp_client_cert')); ?></textarea><p class="description"><?php _e('商户 API 证书。可直接粘贴 PEM 内容，或填写服务器可读取的绝对路径/站内文件地址。', 'qilingshop'); ?></p></td>
                    </tr>
                    <tr class="qls-miniapp-paytype-row qls-miniapp-paytype-v3">
                        <th><?php _e('商户 API 私钥', 'qilingshop'); ?></th>
                        <td><textarea name="wechat_miniapp_client_key" class="large-text code" rows="6"><?php echo esc_textarea(get_option('qilingshop_wechat_miniapp_client_key')); ?></textarea><p class="description"><?php _e('商户 API 私钥。可直接粘贴 PEM 内容，或填写服务器可读取的绝对路径/站内文件地址。', 'qilingshop'); ?></p></td>
                    </tr>
                    <tr class="qls-miniapp-paytype-row qls-miniapp-paytype-v3">
                        <th><?php _e('微信支付平台公钥 ID', 'qilingshop'); ?></th>
                        <td><input type="text" name="wechat_miniapp_public_key_id" value="<?php echo esc_attr(get_option('qilingshop_wechat_miniapp_public_key_id')); ?>" class="regular-text"><p class="description"><?php _e('微信支付平台公钥 ID。', 'qilingshop'); ?></p></td>
                    </tr>
                    <tr class="qls-miniapp-paytype-row qls-miniapp-paytype-v3">
                        <th><?php _e('微信支付平台公钥 PEM', 'qilingshop'); ?></th>
                        <td><textarea name="wechat_miniapp_public_key_pem" class="large-text code" rows="6"><?php echo esc_textarea(get_option('qilingshop_wechat_miniapp_public_key_pem')); ?></textarea><p class="description"><?php _e('微信支付平台公钥 PEM。可直接粘贴 PEM 内容，或填写服务器可读取的绝对路径/站内文件地址。', 'qilingshop'); ?></p></td>
                    </tr>
                    <tr class="qls-miniapp-paytype-row qls-miniapp-paytype-v3">
                        <th><?php _e('转账场景 ID', 'qilingshop'); ?></th>
                        <td><input type="text" name="wechat_miniapp_transfer_scene_id" value="<?php echo esc_attr(get_option('qilingshop_wechat_miniapp_transfer_scene_id')); ?>" class="regular-text"><p class="description"><?php _e('微信支付转账场景编号，仅在开通对应能力时填写。', 'qilingshop'); ?></p></td>
                    </tr>
                </table>

                <h3 style="margin-top:25px;padding-top:15px;border-top:1px dashed #ddd;"><?php _e('微信小程序官方虚拟支付（道具直购）', 'qilingshop'); ?></h3>
                <p><?php _e('对应微信公众平台（mp.weixin.qq.com）侧边栏【支付与交易 -> 虚拟支付】功能。适用于个人主体小程序收款、VIP 会员开通、虚拟数字道具、付费资源阅读及 iOS 端合规支付通道。', 'qilingshop'); ?></p>
                <p><?php _e('发货通知推送地址（请在微信小程序后台【开发管理 -> 消息推送】或【虚拟支付】中配置）：', 'qilingshop'); ?><code><?php echo esc_html(home_url('/wp-json/qilingminiapp/v1/shop/pay/notify/wechat-virtual')); ?></code></p>
                <table class="form-table qls-ui-form-table">
                    <tr>
                        <th><?php _e('启用虚拟支付', 'qilingshop'); ?></th>
                        <td><label><input type="checkbox" name="wechat_miniapp_xpay_enabled" value="1" <?php checked(get_option('qilingshop_wechat_miniapp_xpay_enabled')); ?>> <?php _e('启用微信小程序虚拟支付', 'qilingshop'); ?></label>
                        <p class="description"><?php _e('启用后，在小程序内购买 VIP 或虚拟商品将优先调起微信官方 wx.requestVirtualPayment 虚拟支付收银台。', 'qilingshop'); ?></p></td>
                    </tr>
                    <tr>
                        <th><?php _e('环境模式', 'qilingshop'); ?></th>
                        <td>
                            <select name="wechat_miniapp_xpay_env">
                                <option value="0" <?php selected(get_option('qilingshop_wechat_miniapp_xpay_env', 0), 0); ?>><?php _e('现网正式环境 (env=0)', 'qilingshop'); ?></option>
                                <option value="1" <?php selected(get_option('qilingshop_wechat_miniapp_xpay_env', 0), 1); ?>><?php _e('沙箱测试环境 (env=1)', 'qilingshop'); ?></option>
                            </select>
                            <p class="description"><?php _e('正式发布上线时务必选择现网正式环境。测试阶段可先在沙箱中联调。', 'qilingshop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('支付账号 OfferID', 'qilingshop'); ?></th>
                        <td><input type="text" name="wechat_miniapp_xpay_offer_id" value="<?php echo esc_attr(get_option('qilingshop_wechat_miniapp_xpay_offer_id')); ?>" class="regular-text"><p class="description"><?php _e('在微信公众平台【虚拟支付 -> 基本配置】中查看并复制分配的 OfferID。', 'qilingshop'); ?></p></td>
                    </tr>
                    <tr>
                        <th><?php _e('现网 AppKey', 'qilingshop'); ?></th>
                        <td><input type="text" name="wechat_miniapp_xpay_app_key" value="<?php echo esc_attr(get_option('qilingshop_wechat_miniapp_xpay_app_key')); ?>" class="regular-text"><p class="description"><?php _e('在微信公众平台【虚拟支付 -> 基本配置】中获取的正式支付密钥 AppKey。', 'qilingshop'); ?></p></td>
                    </tr>
                    <tr>
                        <th><?php _e('沙箱 AppKey', 'qilingshop'); ?></th>
                        <td><input type="text" name="wechat_miniapp_xpay_sandbox_app_key" value="<?php echo esc_attr(get_option('qilingshop_wechat_miniapp_xpay_sandbox_app_key')); ?>" class="regular-text"><p class="description"><?php _e('沙箱测试环境 AppKey，仅在环境模式为沙箱时生效。', 'qilingshop'); ?></p></td>
                    </tr>
                </table>

                <div style="background:#fff;border:1px solid #ccd0d4;border-left:4px solid #0073aa;padding:14px 18px;margin:20px 0;max-width:880px;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                    <h4 style="margin:0 0 8px 0;font-size:14px;color:#1d2327;"><?php _e('微信公众平台【虚拟支付 -> 道具管理】与商城对照表', 'qilingshop'); ?></h4>
                    <p style="margin:0 0 10px 0;color:#555;line-height:1.6;font-size:13px;"><?php _e('微信官方虚拟支付核心规则：必须预先在微信小程序后台【虚拟支付 -> 道具管理】中添加对应道具并发布。微信服务器会强校验道具 ID 和价格，两者必须与商城完全一致。请对照下方表格在微信后台创建道具：', 'qilingshop'); ?></p>
                    <?php
                    $vip_levels = class_exists('QilingShop_VIP') ? QilingShop_VIP::instance()->get_levels(false) : [];
                    $vip_mappings = get_option('qilingshop_wechat_prop_vip_mappings', []);
                    ?>
                    <table class="wp-list-table widefat striped" style="margin-top:8px;">
                        <thead>
                            <tr>
                                <th style="width:20%;"><?php _e('VIP 等级名称', 'qilingshop'); ?></th>
                                <th style="width:20%;"><?php _e('商城售价 (元)', 'qilingshop'); ?></th>
                                <th style="width:25%;"><?php _e('微信后台道具单价 (分)', 'qilingshop'); ?></th>
                                <th style="width:35%;"><?php _e('微信后台道具ID (productId)', 'qilingshop'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($vip_levels)): ?>
                                <tr><td colspan="4"><?php _e('暂无配置的 VIP 等级，请先在【启灵商城 -> VIP 等级】中创建。', 'qilingshop'); ?></td></tr>
                            <?php else: ?>
                                <?php foreach ($vip_levels as $lvl):
                                    $lvl_id = intval($lvl->id);
                                    $default_pid = 'vip_' . $lvl_id;
                                    $current_pid = !empty($vip_mappings[$lvl_id]) ? $vip_mappings[$lvl_id] : $default_pid;
                                    $price_cents = intval(round(floatval($lvl->price) * 100));
                                ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($lvl->level_name); ?></strong> (ID: <?php echo esc_html($lvl_id); ?>)</td>
                                        <td>¥<?php echo number_format(floatval($lvl->price), 2); ?></td>
                                        <td><code style="color:#d63638;font-weight:bold;"><?php echo esc_html($price_cents); ?></code> 分</td>
                                        <td>
                                            <input type="text" name="wechat_prop_vip_mappings[<?php echo esc_attr($lvl_id); ?>]" value="<?php echo esc_attr($current_pid); ?>" style="width:180px;" placeholder="<?php echo esc_attr($default_pid); ?>">
                                            <span class="description"><?php _e('对应微信道具ID', 'qilingshop'); ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <p style="margin:10px 0 0 0;font-size:12px;color:#666;"><?php _e('提示：若在微信后台自定了道具 ID，可直接在上方修改保存；若未修改，默认使用 vip_等级ID（如 vip_1）。对于单独购买的商品，微信道具ID规则为 goods_商品ID。', 'qilingshop'); ?></p>
                </div>

                <h2>4、<?php _e('PayPal贝宝（官方接口）', 'qilingshop'); ?></h2>
                <p><?php _e('仅支持 Orders v2 + Webhook（已移除 SOAP/IPN）。', 'qilingshop'); ?></p>
                <p><?php _e('Webhook 地址：', 'qilingshop'); ?><code><?php echo esc_html(home_url('/wp-json/qls/v1/notify/paypal')); ?></code></p>
                <p><?php _e('建议订阅事件：CHECKOUT.ORDER.APPROVED、PAYMENT.CAPTURE.COMPLETED', 'qilingshop'); ?></p>
                <table class="form-table qls-ui-form-table">
                    <tr>
                        <th><?php _e('启用', 'qilingshop'); ?></th>
                        <td><label><input type="checkbox" name="paypal_enabled" value="1" <?php checked(get_option('qilingshop_paypal_enabled')); ?>> <?php _e('启用PayPal支付', 'qilingshop'); ?></label></td>
                    </tr>
                    <tr>
                        <th><?php _e('REST API Client ID', 'qilingshop'); ?></th>
                        <td><input type="text" name="paypal_client_id" value="<?php echo esc_attr(get_option('qilingshop_paypal_client_id')); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><?php _e('REST API Secret', 'qilingshop'); ?></th>
                        <td><input type="password" name="paypal_client_secret" value="<?php echo esc_attr(get_option('qilingshop_paypal_client_secret')); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><?php _e('Webhook ID', 'qilingshop'); ?></th>
                        <td><input type="text" name="paypal_webhook_id" value="<?php echo esc_attr(get_option('qilingshop_paypal_webhook_id')); ?>" class="regular-text"><p class="description"><?php _e('用于官方验签，建议必填。', 'qilingshop'); ?></p></td>
                    </tr>
                    <tr>
                        <th><?php _e('汇率', 'qilingshop'); ?></th>
                        <td><input type="number" step="0.01" name="paypal_rate" value="<?php echo esc_attr(get_option('qilingshop_paypal_rate', 7)); ?>" class="small-text"><p class="description"><?php _e('填7表示1美元=7元人民币', 'qilingshop'); ?></p></td>
                    </tr>
                    <tr>
                        <th><?php _e('沙盒模式', 'qilingshop'); ?></th>
                        <td><label><input type="checkbox" name="paypal_sandbox" value="1" <?php checked(get_option('qilingshop_paypal_sandbox')); ?>> <?php _e('测试模式', 'qilingshop'); ?></label></td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="qilingshop_save_payment" class="button button-primary"><?php _e('保存设置', 'qilingshop'); ?></button>
                </p>
            </form>
        </div>
        <script>
        jQuery(function($){
            function toggleAlipayOld() {
                if($('select[name="alipay_type"]').val() == 'new') {
                    $('.alipay-old').hide();
                } else {
                    $('.alipay-old').show();
                }
            }
            function toggleMiniappPayType() {
                var payType = $('select[name="wechat_miniapp_pay_type"]').val() || 'v2';
                $('.qls-miniapp-paytype-row').hide();
                $('.qls-miniapp-paytype-' + payType).show();
            }
            toggleAlipayOld();
            toggleMiniappPayType();
            $('select[name="alipay_type"]').change(toggleAlipayOld);
            $('select[name="wechat_miniapp_pay_type"]').change(toggleMiniappPayType);
        });
        </script>
        <?php
    }
}
