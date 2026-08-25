<?php
/**
 * 前台装修适配器 - 分类导航模块
 *
 * @package QilingShop
 * @since   2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_FB_Category_Nav extends QLS_FB_Adapter_Base {

    protected $icon = 'dashicons-category';
    protected $category = 'shop';
    protected $description = '';

    public function get_id() {
        return 'qls_fb_category_nav';
    }

    public function get_name() {
        return __('商品分类导航', 'qilingshop');
    }

    public function get_description() {
        return __('展示商品分类导航，支持图标/文字/卡片等多种风格', 'qilingshop');
    }

    protected function create_qls_module() {
        require_once QILINGSHOP_PATH . 'includes/shop/modules/class-qls-module-category-nav.php';
        return new QLS_Module_Category_Nav();
    }
}
