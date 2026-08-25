<?php
/**
 * 前台装修适配器 - 商品列表模块
 *
 * @package QilingShop
 * @since   2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_FB_Product_List extends QLS_FB_Adapter_Base {

    protected $icon = 'dashicons-grid-view';
    protected $category = 'shop';
    protected $description = '';

    public function get_id() {
        return 'qls_fb_product_list';
    }

    public function get_name() {
        return __('商品列表', 'qilingshop');
    }

    public function get_description() {
        return __('展示商城商品列表，支持按分类、热门、最新等方式筛选', 'qilingshop');
    }

    protected function create_qls_module() {
        require_once QILINGSHOP_PATH . 'includes/shop/modules/class-qls-module-product-list.php';
        return new QLS_Module_Product_List();
    }
}
