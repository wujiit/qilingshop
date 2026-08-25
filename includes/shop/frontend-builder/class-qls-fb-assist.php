<?php
/**
 * 前台装修适配器 - 好友助力模块
 *
 * @package QilingShop
 * @since   2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_FB_Assist extends QLS_FB_Adapter_Base {

    protected $icon = 'dashicons-megaphone';
    protected $category = 'shop';
    protected $description = '';

    public function get_id() {
        return 'qls_fb_assist';
    }

    public function get_name() {
        return __('好友助力专区', 'qilingshop');
    }

    public function get_description() {
        return __('展示好友助力活动，支持价格递减和库存进度', 'qilingshop');
    }

    protected function create_qls_module() {
        require_once QILINGSHOP_PATH . 'includes/shop/modules/class-qls-module-assist.php';
        return new QLS_Module_Assist();
    }
}
