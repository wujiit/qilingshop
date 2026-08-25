<?php
/**
 * 国际化支持
 *
 * @package QilingShop
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_i18n {

    /**
     * 加载插件的翻译文件
     *
     * @since 1.0.0
     */
    public function load_plugin_textdomain() {
        load_plugin_textdomain(
            'qilingshop',
            false,
            dirname(QILINGSHOP_BASENAME) . '/lang/'
        );
    }
}
