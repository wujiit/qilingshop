<?php
/**
 * 插件停用器
 *
 * @package QilingShop
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Deactivator {

    /**
     * 停用插件
     *
     * @since 1.0.0
     */
    public static function deactivate() {
        // 清除定时任务
        wp_clear_scheduled_hook('qilingshop_daily_task_check');
        wp_clear_scheduled_hook('qilingshop_daily_vip_check');
        wp_clear_scheduled_hook('qilingshop_daily_guest_cleanup');
        wp_clear_scheduled_hook('qilingshop_daily_points_maintenance');
        wp_clear_scheduled_hook('qls_shop_auto_cancel_orders');
        wp_clear_scheduled_hook('qls_shop_check_expired_groups');
        
        // 清除缓存的 transients
        self::clear_transients();
        
        /**
         * 插件停用后钩子
         * 
         * @since 1.0.0
         */
        do_action('qilingshop_deactivated');
    }

    /**
     * 卸载插件
     *
     * 由根目录 uninstall.php 调用。
     * 默认仅清理任务与临时缓存；若开启 qilingshop_remove_data_on_uninstall，则执行全量数据清理。
     *
     * @return void
     */
    public static function uninstall() {
        self::deactivate();

        $remove_all = (bool) get_option('qilingshop_remove_data_on_uninstall', false);
        if (!$remove_all) {
            return;
        }

        global $wpdb;

        // 删除插件选项
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like('qilingshop_') . '%',
                $wpdb->esc_like('qls_shop_') . '%'
            )
        );

        // 删除自定义数据表
        $table_patterns = [
            $wpdb->esc_like($wpdb->prefix . 'qilingshop_') . '%',
            $wpdb->esc_like($wpdb->prefix . 'qls_shop_') . '%',
        ];

        foreach ($table_patterns as $pattern) {
            $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $pattern));
            if (empty($tables)) {
                continue;
            }

            foreach ($tables as $table) {
                $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $table);
                if ($table === '') {
                    continue;
                }
                $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
            }
        }
    }

    /**
     * 清除插件相关的 transients
     *
     * @since 1.0.0
     */
    private static function clear_transients() {
        global $wpdb;
        
        // 删除所有以 qilingshop_ 开头的 transients
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_qilingshop_%' 
             OR option_name LIKE '_transient_timeout_qilingshop_%'"
        );
    }
}
