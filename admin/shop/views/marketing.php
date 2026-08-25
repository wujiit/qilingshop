<?php
/**
 * 营销中心视图
 */
if (!defined('ABSPATH')) exit;

$tab = isset($tab) ? (string) $tab : 'overview';
$marketing_tabs = isset($marketing_tabs) && is_array($marketing_tabs) ? $marketing_tabs : [];
$marketing_stats = isset($marketing_stats) && is_array($marketing_stats) ? $marketing_stats : [];
$new_user_products = isset($new_user_products) && is_array($new_user_products) ? $new_user_products : [];
$birthday_coupon_choices = isset($birthday_coupon_choices) && is_array($birthday_coupon_choices) ? $birthday_coupon_choices : [];
$marketing_message = isset($_GET['message']) ? sanitize_key((string) wp_unslash($_GET['message'])) : '';

$marketing_url = function($args = []) {
    return add_query_arg(array_merge(['page' => 'qls-shop-marketing'], $args), admin_url('admin.php'));
};
?>
<div class="wrap qilingshop-admin-page qls-shop-wrap qls-marketing-wrap">
    <div class="qls-page-header qls-page-header-inline">
        <div>
            <h1 class="qls-page-title"><?php _e('营销中心', 'qilingshop'); ?></h1>
            <p class="qls-page-intro"><?php _e('统一管理优惠券、新人专区、生日券和营销页面入口。拼团和好友助力保留在独立菜单，不纳入营销中心。', 'qilingshop'); ?></p>
        </div>
        <a href="<?php echo esc_url($marketing_url(['tab' => 'coupons', 'view' => 'add'])); ?>" class="page-title-action"><?php _e('添加优惠券', 'qilingshop'); ?></a>
    </div>

    <?php if ($marketing_message === 'saved' && $tab !== 'coupons'): ?>
    <div class="qls-admin-message qls-admin-message-success">
        <p><?php _e('设置已保存', 'qilingshop'); ?></p>
    </div>
    <?php endif; ?>

    <nav class="nav-tab-wrapper qls-ui-tabs">
        <?php foreach ($marketing_tabs as $tab_key => $tab_label): ?>
        <a href="<?php echo esc_url($marketing_url(['tab' => $tab_key])); ?>" class="nav-tab <?php echo $tab === $tab_key ? 'nav-tab-active' : ''; ?>">
            <?php echo esc_html($tab_label); ?>
        </a>
        <?php endforeach; ?>
    </nav>

    <div class="qls-marketing-content">
        <?php if ($tab === 'overview'): ?>
        <div class="qls-marketing-grid">
            <div class="qls-marketing-card">
                <span class="qls-marketing-card-label"><?php _e('优惠券', 'qilingshop'); ?></span>
                <strong><?php echo (int) ($marketing_stats['coupon_active'] ?? 0); ?> / <?php echo (int) ($marketing_stats['coupon_total'] ?? 0); ?></strong>
                <p><?php _e('启用 / 全部优惠券，用于领券中心、订单抵扣、生日券等场景。', 'qilingshop'); ?></p>
                <a href="<?php echo esc_url($marketing_url(['tab' => 'coupons'])); ?>" class="button"><?php _e('管理优惠券', 'qilingshop'); ?></a>
            </div>

            <div class="qls-marketing-card">
                <span class="qls-marketing-card-label"><?php _e('新人专区', 'qilingshop'); ?></span>
                <strong><?php echo (int) ($marketing_stats['new_user_products'] ?? 0); ?></strong>
                <p><?php _e('已开启新人专项价的商品数量，适合首单转化和新客拉新。', 'qilingshop'); ?></p>
                <a href="<?php echo esc_url($marketing_url(['tab' => 'new_user'])); ?>" class="button"><?php _e('查看新人商品', 'qilingshop'); ?></a>
            </div>

            <div class="qls-marketing-card">
                <span class="qls-marketing-card-label"><?php _e('生日券', 'qilingshop'); ?></span>
                <strong><?php echo !empty($marketing_stats['birthday_enabled']) ? esc_html__('已启用', 'qilingshop') : esc_html__('未启用', 'qilingshop'); ?></strong>
                <p><?php _e('会员生日当天自动发放指定优惠券，需要任务中心定时触发。', 'qilingshop'); ?></p>
                <a href="<?php echo esc_url($marketing_url(['tab' => 'birthday'])); ?>" class="button"><?php _e('设置生日券', 'qilingshop'); ?></a>
            </div>

            <div class="qls-marketing-card">
                <span class="qls-marketing-card-label"><?php _e('营销页面', 'qilingshop'); ?></span>
                <strong><?php echo ((int) ($marketing_stats['coupon_page_id'] ?? 0) > 0 && (int) ($marketing_stats['new_user_page_id'] ?? 0) > 0) ? esc_html__('已配置', 'qilingshop') : esc_html__('待完善', 'qilingshop'); ?></strong>
                <p><?php _e('集中配置优惠券中心和新人专区页面，方便前台导航和装修模块跳转。', 'qilingshop'); ?></p>
                <a href="<?php echo esc_url($marketing_url(['tab' => 'pages'])); ?>" class="button"><?php _e('配置页面入口', 'qilingshop'); ?></a>
            </div>
        </div>

        <div class="qls-marketing-panel">
            <h2><?php _e('最近新人专项商品', 'qilingshop'); ?></h2>
            <?php if (empty($new_user_products)): ?>
            <p class="qls-text-muted"><?php _e('暂无新人专项商品。可以在商品编辑页开启“新人专项”并设置新人专享价。', 'qilingshop'); ?></p>
            <?php else: ?>
            <table class="widefat fixed striped qls-ui-table">
                <thead>
                    <tr>
                        <th><?php _e('商品', 'qilingshop'); ?></th>
                        <th><?php _e('新人专享价', 'qilingshop'); ?></th>
                        <th><?php _e('状态', 'qilingshop'); ?></th>
                        <th><?php _e('操作', 'qilingshop'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($new_user_products as $product): ?>
                    <tr>
                        <td><?php echo esc_html($product->title); ?></td>
                        <td>¥<?php echo number_format((float) ($product->new_user_special_price ?? 0), 2); ?></td>
                        <td><?php echo ((int) ($product->status ?? 0) === 1) ? esc_html__('已上架', 'qilingshop') : esc_html__('已下架', 'qilingshop'); ?></td>
                        <td><a href="<?php echo esc_url(admin_url('admin.php?page=qls-product-edit&id=' . (int) $product->id)); ?>"><?php _e('编辑商品', 'qilingshop'); ?></a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <?php elseif ($tab === 'coupons'): ?>
            <?php include QILINGSHOP_PATH . 'admin/shop/views/coupons.php'; ?>

        <?php elseif ($tab === 'new_user'): ?>
        <div class="qls-marketing-panel">
            <div class="qls-page-header qls-page-header-inline">
                <div>
                    <h2 class="qls-mt-0"><?php _e('新人专区', 'qilingshop'); ?></h2>
                    <p class="qls-page-intro"><?php _e('新人专区基于商品级“新人专项价”运行。开启后，符合首单资格的用户可按新人价购买，且仅限购 1 件。', 'qilingshop'); ?></p>
                </div>
                <a href="<?php echo esc_url(admin_url('admin.php?page=qls-product-edit')); ?>" class="button button-primary"><?php _e('添加新人商品', 'qilingshop'); ?></a>
            </div>

            <div class="qls-marketing-actions">
                <a href="<?php echo esc_url(admin_url('admin.php?page=qls-products')); ?>" class="button"><?php _e('去商品列表批量设置', 'qilingshop'); ?></a>
                <a href="<?php echo esc_url($marketing_url(['tab' => 'pages'])); ?>" class="button"><?php _e('配置新人专区页面', 'qilingshop'); ?></a>
            </div>

            <table class="widefat fixed striped qls-ui-table qls-marketing-products-table">
                <thead>
                    <tr>
                        <th><?php _e('商品', 'qilingshop'); ?></th>
                        <th><?php _e('新人专享价', 'qilingshop'); ?></th>
                        <th><?php _e('状态', 'qilingshop'); ?></th>
                        <th><?php _e('操作', 'qilingshop'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($new_user_products)): ?>
                    <tr><td colspan="4" class="no-items"><?php _e('暂无新人专项商品', 'qilingshop'); ?></td></tr>
                    <?php else: ?>
                    <?php foreach ($new_user_products as $product): ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($product->title); ?></strong>
                            <?php if (!empty($product->subtitle)): ?>
                            <div class="qls-text-muted"><?php echo esc_html($product->subtitle); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>¥<?php echo number_format((float) ($product->new_user_special_price ?? 0), 2); ?></td>
                        <td><?php echo ((int) ($product->status ?? 0) === 1) ? esc_html__('已上架', 'qilingshop') : esc_html__('已下架', 'qilingshop'); ?></td>
                        <td><a href="<?php echo esc_url(admin_url('admin.php?page=qls-product-edit&id=' . (int) $product->id)); ?>"><?php _e('编辑商品', 'qilingshop'); ?></a></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php elseif ($tab === 'birthday'): ?>
        <div class="qls-marketing-panel">
            <h2><?php _e('生日券设置', 'qilingshop'); ?></h2>
            <form method="post">
                <?php wp_nonce_field('qls_save_marketing_settings', 'qls_marketing_nonce'); ?>
                <input type="hidden" name="marketing_action" value="save_birthday_coupon">
                <table class="form-table qls-ui-form-table">
                    <tr>
                        <th><?php _e('启用生日券', 'qilingshop'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="birthday_coupon_enabled" value="1" <?php checked(get_option('qilingshop_birthday_coupon_enabled', false)); ?>>
                                <?php _e('生日当天自动发放优惠券', 'qilingshop'); ?>
                            </label>
                            <p class="description"><?php _e('生日券由任务中心低频检查并自动发放，同一年同一张券只发一次。', 'qilingshop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="birthday_coupon_id"><?php _e('生日优惠券', 'qilingshop'); ?></label></th>
                        <td>
                            <?php $birthday_coupon_id = absint(get_option('qilingshop_birthday_coupon_id', 0)); ?>
                            <select name="birthday_coupon_id" id="birthday_coupon_id">
                                <option value="0" <?php selected($birthday_coupon_id, 0); ?>><?php _e('不指定', 'qilingshop'); ?></option>
                                <?php foreach ($birthday_coupon_choices as $coupon_item): ?>
                                <?php $coupon_status_label = isset($coupon_item->status) && (int) $coupon_item->status !== 1 ? __('（当前已停用）', 'qilingshop') : ''; ?>
                                <option value="<?php echo esc_attr((int) $coupon_item->id); ?>" <?php selected($birthday_coupon_id, (int) $coupon_item->id); ?>>
                                    #<?php echo (int) $coupon_item->id; ?> <?php echo esc_html($coupon_item->name); ?> (<?php echo esc_html($coupon_item->code); ?>)<?php echo esc_html($coupon_status_label); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('需先创建并启用优惠券。若这里没有可选项，请先到“优惠券”标签页创建。', 'qilingshop'); ?></p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" class="button button-primary"><?php _e('保存设置', 'qilingshop'); ?></button>
                    <a href="<?php echo esc_url($marketing_url(['tab' => 'coupons', 'view' => 'add'])); ?>" class="button"><?php _e('创建优惠券', 'qilingshop'); ?></a>
                </p>
            </form>
        </div>

        <?php elseif ($tab === 'pages'): ?>
        <div class="qls-marketing-panel">
            <h2><?php _e('营销页面', 'qilingshop'); ?></h2>
            <p class="qls-page-intro"><?php _e('这里只配置营销相关前台页面。拼团中心、助力中心仍在商城设置或对应独立菜单中维护。', 'qilingshop'); ?></p>
            <form method="post">
                <?php wp_nonce_field('qls_save_marketing_settings', 'qls_marketing_nonce'); ?>
                <input type="hidden" name="marketing_action" value="save_marketing_pages">
                <table class="widefat striped qls-ui-table qls-marketing-pages-table">
                    <thead>
                        <tr>
                            <th><?php _e('页面', 'qilingshop'); ?></th>
                            <th><?php _e('短代码', 'qilingshop'); ?></th>
                            <th><?php _e('用途', 'qilingshop'); ?></th>
                            <th><?php _e('绑定页面', 'qilingshop'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong><?php _e('优惠券中心', 'qilingshop'); ?></strong></td>
                            <td><code>[qls_coupon_center]</code></td>
                            <td><?php _e('显示可领取优惠券列表', 'qilingshop'); ?></td>
                            <td>
                                <?php wp_dropdown_pages([
                                    'name'              => 'page_coupon_center',
                                    'show_option_none'  => __('— 选择页面 —', 'qilingshop'),
                                    'option_none_value' => 0,
                                    'selected'          => get_option('qls_shop_page_coupon_center', 0),
                                ]); ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('新人专区', 'qilingshop'); ?></strong></td>
                            <td><code>[qls_new_user_zone]</code></td>
                            <td><?php _e('展示开启新人专项价的商品', 'qilingshop'); ?></td>
                            <td>
                                <?php wp_dropdown_pages([
                                    'name'              => 'page_new_user_zone',
                                    'show_option_none'  => __('— 选择页面 —', 'qilingshop'),
                                    'option_none_value' => 0,
                                    'selected'          => get_option('qls_shop_page_new_user_zone', 0),
                                ]); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="submit">
                    <button type="submit" class="button button-primary"><?php _e('保存设置', 'qilingshop'); ?></button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=qls-shop-settings&tab=pages')); ?>" class="button"><?php _e('查看全部商城页面配置', 'qilingshop'); ?></a>
                </p>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>
