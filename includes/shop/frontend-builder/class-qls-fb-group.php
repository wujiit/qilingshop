<?php
/**
 * 前台装修适配器 - 拼团专区模块
 *
 * @package QilingShop
 * @since   2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_FB_Group extends QLS_FB_Adapter_Base {

    protected $icon = 'dashicons-groups';
    protected $category = 'shop';
    protected $description = '';

    public function get_id() {
        return 'qls_fb_group';
    }

    public function get_name() {
        return __('拼团专区', 'qilingshop');
    }

    public function get_description() {
        return __('展示限时拼团商品，支持进度条和价格对比', 'qilingshop');
    }

    protected function create_qls_module() {
        require_once QILINGSHOP_PATH . 'includes/shop/modules/class-qls-module-group.php';
        return new QLS_Module_Group();
    }
}
