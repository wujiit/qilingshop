<?php
/**
 * 前台装修适配器 - 优惠券模块
 *
 * @package QilingShop
 * @since   2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_FB_Coupon extends QLS_FB_Adapter_Base {

    protected $icon = 'dashicons-tickets-alt';
    protected $category = 'shop';
    protected $description = '';

    public function get_id() {
        return 'qls_fb_coupon';
    }

    public function get_name() {
        return __('优惠券领取', 'qilingshop');
    }

    public function get_description() {
        return __('展示可领取的优惠券，支持自动/手动选择', 'qilingshop');
    }

    protected function create_qls_module() {
        require_once QILINGSHOP_PATH . 'includes/shop/modules/class-qls-module-coupon.php';
        return new QLS_Module_Coupon();
    }
}
