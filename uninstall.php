<?php
/**
 * 启灵商城卸载处理器。
 *
 * WordPress 会在插件被彻底卸载时直接加载此文件。
 * 数据清理策略由 QilingShop_Deactivator::uninstall() 统一处理。
 *
 * @package QilingShop
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/includes/class-qilingshop-deactivator.php';

QilingShop_Deactivator::uninstall();
