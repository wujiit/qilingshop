<?php
/**
 * 前台装修适配器 - 新人专区模块
 *
 * @package QilingShop
 * @since   2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_FB_New_User_Zone extends QLS_FB_Adapter_Base {

    protected $icon = 'dashicons-star-filled';
    protected $category = 'shop';
    protected $description = '';

    public function get_id() {
        return 'qls_fb_new_user_zone';
    }

    public function get_name() {
        return __('新人专区', 'qilingshop');
    }

    public function get_description() {
        return __('展示新人专属商品，新用户可享专项低价', 'qilingshop');
    }

    protected function create_qls_module() {
        require_once QILINGSHOP_PATH . 'includes/shop/modules/class-qls-module-new-user-zone.php';
        return new QLS_Module_New_User_Zone();
    }
}
