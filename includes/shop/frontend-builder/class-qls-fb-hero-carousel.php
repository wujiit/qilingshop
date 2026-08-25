<?php
/**
 * 前台装修适配器 - 首屏轮播模块
 *
 * @package QilingShop
 * @since   2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_FB_Hero_Carousel extends QLS_FB_Adapter_Base {

    protected $icon = 'dashicons-images-alt2';
    protected $category = 'shop';
    protected $description = '';

    public function get_id() {
        return 'qls_fb_hero_carousel';
    }

    public function get_name() {
        return __('首屏轮播', 'qilingshop');
    }

    public function get_description() {
        return __('全屏/盒模型轮播大图，支持视频、按钮、侧边栏导航', 'qilingshop');
    }

    protected function create_qls_module() {
        require_once QILINGSHOP_PATH . 'includes/shop/modules/class-qls-module-hero-carousel.php';
        return new QLS_Module_Hero_Carousel();
    }
}
