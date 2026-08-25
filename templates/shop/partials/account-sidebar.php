<?php
/**
 * 个人中心侧边栏
 */
if (!defined('ABSPATH')) exit;

$current_page = '';
if (is_page(qls_shop_public()->get_page_id('shop-center'))) $current_page = 'shop-center';
if (is_page(qls_shop_public()->get_page_id('orders'))) $current_page = 'orders';
if (is_page(qls_shop_public()->get_page_id('my-tickets'))) $current_page = 'my-tickets';
if (is_page(qls_shop_public()->get_page_id('cart'))) $current_page = 'cart';
if (is_page(qls_shop_public()->get_page_id('coupon-center'))) $current_page = 'coupon-center';
if (is_page(qls_shop_public()->get_page_id('assist-center'))) $current_page = 'assist-center';
if (is_page(qls_shop_public()->get_page_id('assist-detail'))) $current_page = 'assist-detail';
if (is_page(qls_shop_public()->get_page_id('my-assists'))) $current_page = 'my-assists';
if (is_page(qls_shop_public()->get_page_id('my-downloads'))) $current_page = 'my-downloads';
if (is_page(get_option('qilingshop_task_center_page_id'))) $current_page = 'task-center';

// 如果通过 tab 参数区分 (在 shop-center 页面)
$current_view = isset($_GET['view']) ? $_GET['view'] : 'dashboard';
$active = '';
if ($current_page == 'shop-center' && $current_view == 'dashboard') $active = 'dashboard';
if ($current_page == 'orders') $active = 'orders';
if ($current_page == 'my-tickets') $active = 'my-tickets';
if ($current_page == 'cart') $active = 'cart';
if ($current_page == 'shop-center' && $current_view == 'address') $active = 'address';
if ($current_page == 'shop-center' && $current_view == 'invoice') $active = 'invoice';
if ($current_page == 'shop-center' && $current_view == 'groups') $active = 'groups';
if ($current_page == 'coupon-center') $active = 'coupon-center';
if ($current_page == 'assist-center' || $current_page == 'assist-detail' || $current_page == 'my-assists') $active = 'my-assists';
if ($current_page == 'my-downloads') $active = 'my-downloads';
if ($current_page == 'task-center') $active = 'task-center';

$my_tickets_url = qls_shop_public()->get_page_url('my-tickets');
?>
<aside class="qls-account-sidebar">
    <nav class="qls-account-nav">
        <a href="<?php echo esc_url(qls_shop_public()->get_page_url('shop-center')); ?>" class="nav-item <?php echo ($active == 'dashboard') ? 'active' : ''; ?>">
            <span class="dashicons dashicons-dashboard"></span>
            <?php _e('概览', 'qilingshop'); ?>
        </a>
        <a href="<?php echo esc_url(qls_shop_public()->get_page_url('orders')); ?>" class="nav-item <?php echo ($active == 'orders') ? 'active' : ''; ?>">
            <span class="dashicons dashicons-list-view"></span>
            <?php _e('我的订单', 'qilingshop'); ?>
        </a>
        <a href="<?php echo esc_url(qls_shop_public()->get_page_url('shop-center') . '?view=groups'); ?>" class="nav-item <?php echo ($active == 'groups') ? 'active' : ''; ?>">
            <span class="dashicons dashicons-groups"></span>
            <?php _e('我的拼团', 'qilingshop'); ?>
        </a>
        <?php
        $task_center_url = '';
        $task_center_page_id = (int) get_option('qilingshop_task_center_page_id', 0);
        if ($task_center_page_id) {
            $task_center_url = get_permalink($task_center_page_id);
        }
        $coupon_center_url = qls_shop_public()->get_page_url('coupon-center');
        $my_assists_url = qls_shop_public()->get_page_url('my-assists');
        $my_downloads_url = qls_shop_public()->get_page_url('my-downloads');
        ?>
        <?php if (!empty($my_assists_url)): ?>
        <a href="<?php echo esc_url($my_assists_url); ?>" class="nav-item nav-item-sub <?php echo ($active == 'my-assists') ? 'active' : ''; ?>">
            <span class="dashicons dashicons-megaphone"></span>
            <?php _e('我的助力', 'qilingshop'); ?>
        </a>
        <?php endif; ?>
        <?php if (!empty($my_downloads_url)): ?>
        <a href="<?php echo esc_url($my_downloads_url); ?>" class="nav-item nav-item-sub <?php echo ($active == 'my-downloads') ? 'active' : ''; ?>">
            <span class="dashicons dashicons-download"></span>
            <?php _e('我的下载', 'qilingshop'); ?>
        </a>
        <?php endif; ?>
        <a href="<?php echo esc_url(qls_shop_public()->get_page_url('cart')); ?>" class="nav-item <?php echo ($active == 'cart') ? 'active' : ''; ?>">
            <span class="dashicons dashicons-cart"></span>
            <?php _e('购物车', 'qilingshop'); ?>
        </a>
         <a href="<?php echo esc_url(qls_shop_public()->get_page_url('shop-center') . '?view=address'); ?>" class="nav-item <?php echo ($active == 'address') ? 'active' : ''; ?>">
            <span class="dashicons dashicons-location"></span>
            <?php _e('地址管理', 'qilingshop'); ?>
        </a>
        <?php if (function_exists('qls_invoice') && get_option('qls_shop_invoice_enabled', true)): ?>
        <a href="<?php echo esc_url(qls_shop_public()->get_page_url('shop-center') . '?view=invoice'); ?>" class="nav-item <?php echo ($active == 'invoice') ? 'active' : ''; ?>">
            <span class="dashicons dashicons-media-spreadsheet"></span>
            <?php _e('发票信息', 'qilingshop'); ?>
        </a>
        <?php endif; ?>
        <?php if (!empty($my_tickets_url)): ?>
        <a href="<?php echo esc_url($my_tickets_url); ?>" class="nav-item <?php echo ($active == 'my-tickets') ? 'active' : ''; ?>">
            <span class="dashicons dashicons-format-chat"></span>
            <?php _e('售后工单', 'qilingshop'); ?>
        </a>
        <?php endif; ?>
        <?php if ($task_center_url): ?>
        <a href="<?php echo esc_url($task_center_url); ?>" class="nav-item <?php echo ($active == 'task-center') ? 'active' : ''; ?>">
            <span class="dashicons dashicons-awards"></span>
            <?php _e('任务中心', 'qilingshop'); ?>
        </a>
        <?php endif; ?>
        <a href="<?php echo esc_url($coupon_center_url); ?>" class="nav-item <?php echo ($active == 'coupon-center') ? 'active' : ''; ?>">
            <span class="dashicons dashicons-tickets-alt"></span>
            <?php _e('优惠券中心', 'qilingshop'); ?>
        </a>
        <div class="nav-divider"></div>
        <a href="<?php echo esc_url(wp_logout_url(home_url())); ?>" class="nav-item logout">
            <span class="dashicons dashicons-exit"></span>
            <?php _e('退出登录', 'qilingshop'); ?>
        </a>
    </nav>
</aside>
