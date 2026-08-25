<?php
/**
 * 插件激活器
 * 
 * 负责在插件激活时创建数据库表和初始化默认设置
 *
 * @package QilingShop
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Activator {
    /**
     * 数据结构版本
     */
    const DB_VERSION = '2.1.17';

    /**
     * 升级锁，避免多个请求同时执行 dbDelta 造成重复字段/索引日志。
     */
    const UPGRADE_LOCK_OPTION = 'qilingshop_db_upgrade_lock';
    const UPGRADE_LOCK_TTL = 600;

    /**
     * 激活插件
     *
     * @since 1.0.0
     */
    public static function activate() {
        self::create_tables();
        self::ensure_payment_recovery_indexes();
        self::ensure_order_payment_idempotency_schema();
        self::ensure_recharge_payment_idempotency_schema();
        self::ensure_order_payment_channel_schema();
        self::ensure_recharge_payment_channel_schema();
        self::ensure_affiliate_idempotency_schema();
        self::ensure_author_commission_idempotency_schema();
        self::ensure_invite_tier_idempotency_schema();
        self::ensure_invite_bonus_idempotency_schema();
        self::ensure_growth_log_idempotency_schema();
        self::ensure_growth_log_query_indexes();
        self::create_default_options();
        self::create_default_growth_levels();
        self::create_default_growth_rules();
        self::migrate_ip_trust_mode();
        self::ensure_download_token_legacy_cutoff();
        self::create_default_vip_levels();
        self::schedule_cron_jobs();
        
        // 创建 VIP 落地页
        self::create_vip_landing_page();
        // 创建订单查询页
        self::create_order_query_page();
        
        // 记录插件版本
        update_option('qilingshop_version', QILINGSHOP_VERSION);
        update_option('qilingshop_db_version', self::DB_VERSION);
        
        // 标记需要刷新重写规则
        update_option('qilingshop_flush_rewrite', true);
        
        /**
         * 插件激活后钩子
         * 
         * @since 1.0.0
         */
        do_action('qilingshop_activated');
    }

    /**
     * 数据库升级检查
     */
    public static function maybe_upgrade() {
        $version = (string) get_option('qilingshop_db_version', '0');
        self::migrate_ip_trust_mode();
        self::ensure_download_token_legacy_cutoff();
        self::ensure_growth_log_idempotency_schema();
        self::ensure_growth_log_query_indexes();
        if (!QLS_Shop_Page_Manager::has_valid_page(QLS_Shop_Page_Manager::get_vip_landing_page_definition())) {
            self::create_vip_landing_page();
        }
        if (!QLS_Shop_Page_Manager::has_valid_page(QLS_Shop_Page_Manager::get_order_query_page_definition())) {
            self::create_order_query_page();
        }
        if (version_compare($version, self::DB_VERSION, '<')) {
            if (!self::acquire_upgrade_lock()) {
                return;
            }

            try {
                $version = (string) get_option('qilingshop_db_version', '0');
                if (version_compare($version, self::DB_VERSION, '<')) {
                    self::create_tables();
                    self::ensure_payment_recovery_indexes();
                    self::ensure_order_payment_idempotency_schema();
                    self::ensure_recharge_payment_idempotency_schema();
                    self::ensure_order_payment_channel_schema();
                    self::ensure_recharge_payment_channel_schema();
                    self::ensure_affiliate_idempotency_schema();
                    self::ensure_author_commission_idempotency_schema();
                    self::ensure_invite_tier_idempotency_schema();
                    self::ensure_invite_bonus_idempotency_schema();
                    self::ensure_growth_log_idempotency_schema();
                    self::ensure_growth_log_query_indexes();
                    self::create_default_options();
                    self::create_default_growth_levels();
                    self::create_default_growth_rules();
                    self::schedule_cron_jobs();
                    update_option('qilingshop_db_version', self::DB_VERSION);
                }
            } finally {
                self::release_upgrade_lock();
            }
        }
    }

    /**
     * 获取升级锁。
     *
     * @return bool
     */
    private static function acquire_upgrade_lock() {
        $now = time();
        $locked_at = (int) get_option(self::UPGRADE_LOCK_OPTION, 0);

        if ($locked_at > 0 && ($now - $locked_at) > self::UPGRADE_LOCK_TTL) {
            delete_option(self::UPGRADE_LOCK_OPTION);
        }

        return add_option(self::UPGRADE_LOCK_OPTION, (string) $now, '', 'no');
    }

    /**
     * 释放升级锁。
     *
     * @return void
     */
    private static function release_upgrade_lock() {
        delete_option(self::UPGRADE_LOCK_OPTION);
    }

    /**
     * 迁移旧版 IP 信任模式值，默认切换为 CDN 兼容的 auto_proxy。
     *
     * @return void
     */
    private static function migrate_ip_trust_mode() {
        $current = get_option('qilingshop_ip_trust_mode', '');
        $current = sanitize_key((string) $current);

        $map = [
            ''       => 'auto_proxy',
            'direct' => 'auto_proxy',
            'cdn'    => 'cdn_compatible',
            'proxy'  => 'cdn_compatible',
            'auto'   => 'auto_proxy',
        ];

        if (isset($map[$current])) {
            update_option('qilingshop_ip_trust_mode', $map[$current]);
        }

        if (get_option('qilingshop_trusted_proxy_list', null) === null) {
            add_option('qilingshop_trusted_proxy_list', []);
        }
    }

    /**
     * 固定旧版下载 token 的迁移截止时间。
     *
     * 新代码只生成带 HMAC 的 aes2 token；这里记录第一次运行新版代码的时间，
     * 让旧格式 token 只吃完既有短时下载窗口，不再长期参与下载授权。
     *
     * @return void
     */
    private static function ensure_download_token_legacy_cutoff() {
        if (get_option('qilingshop_download_token_legacy_cutoff_at', null) !== null) {
            return;
        }

        add_option('qilingshop_download_token_legacy_cutoff_at', (string) time(), '', 'no');
    }

    /**
     * 创建 VIP 落地页
     */
    private static function create_vip_landing_page() {
        return QLS_Shop_Page_Manager::ensure_page(
            QLS_Shop_Page_Manager::get_vip_landing_page_definition()
        );
    }

    /**
     * 创建订单查询页
     */
    private static function create_order_query_page() {
        return QLS_Shop_Page_Manager::ensure_page(
            QLS_Shop_Page_Manager::get_order_query_page_definition()
        );
    }

    /**
     * 创建数据库表
     *
     * @since 1.0.0
     */
    private static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // 用户积分信息表
        $table_user_info = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'user_info';
        $sql_user_info = "CREATE TABLE {$table_user_info} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            points_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
            affiliate_earnings DECIMAL(12,2) NOT NULL DEFAULT 0,
            withdrawable_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_consumed DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_recharged DECIMAL(12,2) NOT NULL DEFAULT 0,
            vip_level INT(4) NOT NULL DEFAULT 0,
            vip_expires DATE NOT NULL DEFAULT '1000-01-01',
            inviter_id BIGINT(20) UNSIGNED DEFAULT 0,
            invite_code VARCHAR(32) DEFAULT NULL,
            invite_count INT(11) NOT NULL DEFAULT 0,
            reg_ip VARCHAR(50) DEFAULT NULL,
            last_checkin_date DATE DEFAULT NULL,
            consecutive_checkins INT(11) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id),
            KEY invite_code (invite_code),
            KEY inviter_id (inviter_id),
            KEY vip_level (vip_level)
        ) {$charset_collate};";
        dbDelta($sql_user_info);

        // 积分流水表
        $table_points_log = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'points_log';
        $sql_points_log = "CREATE TABLE {$table_points_log} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            balance_after DECIMAL(12,2) NOT NULL DEFAULT 0,
            type ENUM('income','expense') NOT NULL,
            source VARCHAR(50) NOT NULL,
            description VARCHAR(200) DEFAULT NULL,
            related_id BIGINT(20) UNSIGNED DEFAULT NULL,
            ip_address VARCHAR(50) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY type (type),
            KEY source (source),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql_points_log);

        // 任务奖励领取记录表
        $table_task_reward_claims = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'task_reward_claims';
        $sql_task_reward_claims = "CREATE TABLE {$table_task_reward_claims} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            task_id VARCHAR(64) NOT NULL,
            status TINYINT(1) NOT NULL DEFAULT 0,
            claimed_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_task (user_id, task_id),
            KEY status (status),
            KEY claimed_at (claimed_at)
        ) {$charset_collate};";
        dbDelta($sql_task_reward_claims);

        // 积分资产批次表（有效期 / 冻结 / 解冻 / 过期）
        $table_points_assets = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'points_assets';
        $sql_points_assets = "CREATE TABLE {$table_points_assets} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            remaining_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            frozen_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            source VARCHAR(50) NOT NULL DEFAULT '',
            related_id BIGINT(20) UNSIGNED DEFAULT 0,
            is_permanent TINYINT(1) NOT NULL DEFAULT 1,
            expires_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY expires_at (expires_at),
            KEY permanent_expires (is_permanent, expires_at),
            KEY remaining_amount (remaining_amount),
            KEY frozen_amount (frozen_amount)
        ) {$charset_collate};";
        dbDelta($sql_points_assets);

        // 订单表
        $table_orders = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'orders';
        $sql_orders = "CREATE TABLE {$table_orders} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_no VARCHAR(50) NOT NULL,
            user_id BIGINT(20) UNSIGNED DEFAULT 0,
            guest_id VARCHAR(100) DEFAULT NULL,
            post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            post_title VARCHAR(200) DEFAULT NULL,
            order_type VARCHAR(20) NOT NULL DEFAULT 'resource',
            price_points DECIMAL(10,2) NOT NULL DEFAULT 0,
            price_rmb DECIMAL(10,2) NOT NULL DEFAULT 0,
            discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            final_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            payment_method VARCHAR(50) DEFAULT NULL,
            payment_no VARCHAR(100) DEFAULT NULL,
            payment_channel_meta LONGTEXT DEFAULT NULL,
            status TINYINT(1) NOT NULL DEFAULT 0,
            ip_address VARCHAR(50) DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            contact_info VARCHAR(200) DEFAULT NULL,
            remark TEXT DEFAULT NULL,
            download_index INT(5) DEFAULT 0,
            author_id BIGINT(20) UNSIGNED DEFAULT 0,
            author_commission DECIMAL(10,2) NOT NULL DEFAULT 0,
            affiliate_id BIGINT(20) UNSIGNED DEFAULT 0,
            affiliate_commission DECIMAL(10,2) NOT NULL DEFAULT 0,
            promo_code VARCHAR(50) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            paid_at DATETIME DEFAULT NULL,
            paid_handled TINYINT(1) NOT NULL DEFAULT 0,
            paid_handled_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY order_no (order_no),
            KEY user_id (user_id),
            KEY post_id (post_id),
            KEY guest_id (guest_id),
            KEY status (status),
            KEY order_type (order_type),
            KEY status_user_created (status, user_id, created_at),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql_orders);

        // 充值表
        $table_recharge = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'recharge';
        $sql_recharge = "CREATE TABLE {$table_recharge} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_no VARCHAR(50) NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            final_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            points_received DECIMAL(10,2) NOT NULL DEFAULT 0,
            bonus_points DECIMAL(10,2) NOT NULL DEFAULT 0,
            payment_method VARCHAR(50) DEFAULT NULL,
            payment_no VARCHAR(100) DEFAULT NULL,
            payment_channel_meta LONGTEXT DEFAULT NULL,
            status TINYINT(1) NOT NULL DEFAULT 0,
            ip_address VARCHAR(50) DEFAULT NULL,
            remark VARCHAR(200) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            paid_at DATETIME DEFAULT NULL,
            paid_handled TINYINT(1) NOT NULL DEFAULT 0,
            paid_handled_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY order_no (order_no),
            KEY user_id (user_id),
            KEY status (status),
            KEY status_user_created (status, user_id, created_at),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql_recharge);

        // VIP 等级表
        $table_vip_levels = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'vip_levels';
        $sql_vip_levels = "CREATE TABLE {$table_vip_levels} (
            id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            level_key VARCHAR(50) NOT NULL,
            level_name VARCHAR(100) NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            original_price DECIMAL(10,2) DEFAULT NULL,
            duration_days INT(11) NOT NULL DEFAULT 30,
            discount_rate INT(3) NOT NULL DEFAULT 100,
            can_download_free TINYINT(1) NOT NULL DEFAULT 0,
            daily_download_limit INT(11) NOT NULL DEFAULT -1,
            description TEXT DEFAULT NULL,
            badge_color VARCHAR(20) DEFAULT '#ff6600',
            sort_order INT(5) NOT NULL DEFAULT 0,
            is_recommended TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY level_key (level_key),
            KEY is_active (is_active),
            KEY sort_order (sort_order)
        ) {$charset_collate};";
        dbDelta($sql_vip_levels);

        // VIP 升级记录表
        $table_vip_log = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'vip_log';
        $sql_vip_log = "CREATE TABLE {$table_vip_log} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            vip_level INT(4) NOT NULL,
            vip_level_name VARCHAR(100) DEFAULT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            payment_type VARCHAR(20) NOT NULL DEFAULT 'points',
            order_no VARCHAR(50) DEFAULT NULL,
            expires_at DATE NOT NULL,
            ip_address VARCHAR(50) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY vip_level (vip_level),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql_vip_log);

        // 推广佣金表
        $table_affiliate = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'affiliate';
        $sql_affiliate = "CREATE TABLE {$table_affiliate} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            from_user_id BIGINT(20) UNSIGNED NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            commission_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
            level TINYINT(1) NOT NULL DEFAULT 1,
            source VARCHAR(50) NOT NULL,
            order_no VARCHAR(50) DEFAULT NULL,
            status TINYINT(1) NOT NULL DEFAULT 1,
            balance_applied TINYINT(1) NOT NULL DEFAULT 0,
            balance_applied_at DATETIME DEFAULT NULL,
            points_applied TINYINT(1) NOT NULL DEFAULT 0,
            points_applied_at DATETIME DEFAULT NULL,
            ip_address VARCHAR(50) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY from_user_id (from_user_id),
            KEY user_from (user_id, from_user_id),
            KEY user_id_id (user_id, id),
            KEY source (source),
            UNIQUE KEY commission_once (from_user_id, source, order_no, level),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql_affiliate);

        // 邀请关系表
        $table_invites = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'invites';
        $sql_invites = "CREATE TABLE {$table_invites} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            inviter_id BIGINT(20) UNSIGNED NOT NULL,
            invitee_id BIGINT(20) UNSIGNED NOT NULL,
            level TINYINT(1) NOT NULL DEFAULT 1,
            is_valid TINYINT(1) NOT NULL DEFAULT 1,
            bonus_paid TINYINT(1) NOT NULL DEFAULT 0,
            bonus_paid_at DATETIME DEFAULT NULL,
            bonus_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            ip_address VARCHAR(50) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY invitee_id (invitee_id),
            KEY inviter_id (inviter_id),
            KEY inviter_level_id (inviter_id, level, id),
            KEY inviter_level_valid (inviter_id, level, is_valid),
            KEY is_valid (is_valid)
        ) {$charset_collate};";
        dbDelta($sql_invites);

        // 邀请阶梯奖励规则表
        $table_invite_tiers = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'invite_tier_rules';
        $sql_invite_tiers = "CREATE TABLE {$table_invite_tiers} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            threshold INT(11) NOT NULL DEFAULT 0,
            bonus_points DECIMAL(10,2) NOT NULL DEFAULT 0,
            description VARCHAR(200) DEFAULT NULL,
            status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY threshold (threshold),
            KEY status (status)
        ) {$charset_collate};";
        dbDelta($sql_invite_tiers);

        // 邀请阶梯奖励发放日志
        $table_invite_tier_logs = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'invite_tier_logs';
        $sql_invite_tier_logs = "CREATE TABLE {$table_invite_tier_logs} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            rule_id BIGINT(20) UNSIGNED NOT NULL,
            invite_count INT(11) NOT NULL DEFAULT 0,
            bonus_points DECIMAL(10,2) NOT NULL DEFAULT 0,
            reward_status TINYINT(1) NOT NULL DEFAULT 0,
            reward_status_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_rule (user_id, rule_id),
            KEY user_id (user_id),
            KEY rule_id (rule_id),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql_invite_tier_logs);

        // 下载记录表
        $table_downloads = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'downloads';
        $sql_downloads = "CREATE TABLE {$table_downloads} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            guest_id VARCHAR(100) DEFAULT NULL,
            post_id BIGINT(20) UNSIGNED NOT NULL,
            order_no VARCHAR(50) DEFAULT NULL,
            download_index INT(5) NOT NULL DEFAULT 0,
            is_vip_free TINYINT(1) NOT NULL DEFAULT 0,
            ip_address VARCHAR(50) DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY post_id (post_id),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql_downloads);

        // 游客订单表
        $table_guest_orders = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'guest_orders';
        $sql_guest_orders = "CREATE TABLE {$table_guest_orders} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            guest_id VARCHAR(100) NOT NULL,
            order_no VARCHAR(50) NOT NULL,
            post_id BIGINT(20) UNSIGNED NOT NULL,
            ip_address VARCHAR(50) NOT NULL,
            user_agent_hash VARCHAR(64) DEFAULT NULL,
            cookie_token VARCHAR(100) DEFAULT NULL,
            contact_email VARCHAR(100) DEFAULT NULL,
            contact_phone VARCHAR(20) DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY order_no (order_no),
            KEY guest_id (guest_id),
            KEY ip_address (ip_address),
            KEY expires_at (expires_at)
        ) {$charset_collate};";
        dbDelta($sql_guest_orders);

        // 签到记录表
        $table_checkins = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'checkins';
        $sql_checkins = "CREATE TABLE {$table_checkins} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            points_earned DECIMAL(10,2) NOT NULL DEFAULT 0,
            consecutive_days INT(11) NOT NULL DEFAULT 1,
            checkin_date DATE NOT NULL,
            ip_address VARCHAR(50) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_date (user_id, checkin_date),
            KEY user_id (user_id),
            KEY checkin_date (checkin_date)
        ) {$charset_collate};";
        dbDelta($sql_checkins);

        // 充值奖励规则表
        $table_recharge_bonus = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'recharge_bonus';
        $sql_recharge_bonus = "CREATE TABLE {$table_recharge_bonus} (
            id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            min_amount DECIMAL(10,2) NOT NULL,
            max_amount DECIMAL(10,2) DEFAULT NULL,
            bonus_type ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',
            bonus_value DECIMAL(10,2) NOT NULL DEFAULT 0,
            description VARCHAR(200) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT(5) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY is_active (is_active),
            KEY min_amount (min_amount)
        ) {$charset_collate};";
        dbDelta($sql_recharge_bonus);

        // 订单返积分规则表
        $table_rebate_rules = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'order_rebate_rules';
        $sql_rebate_rules = "CREATE TABLE {$table_rebate_rules} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            scope ENUM('resource','shop') NOT NULL DEFAULT 'resource',
            category_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            rate DECIMAL(5,2) NOT NULL DEFAULT 0,
            description VARCHAR(200) DEFAULT NULL,
            status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY scope (scope),
            KEY category_id (category_id),
            KEY status (status)
        ) {$charset_collate};";
        dbDelta($sql_rebate_rules);

        // 成长等级表（独立于 VIP 等级）
        $table_growth_levels = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'growth_levels';
        $sql_growth_levels = "CREATE TABLE {$table_growth_levels} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            level_key VARCHAR(50) NOT NULL,
            level_name VARCHAR(100) NOT NULL,
            min_growth DECIMAL(12,2) NOT NULL DEFAULT 0,
            max_growth DECIMAL(12,2) DEFAULT NULL,
            level_icon VARCHAR(255) DEFAULT NULL,
            level_color VARCHAR(20) DEFAULT '#64748b',
            description TEXT DEFAULT NULL,
            status TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT(11) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY level_key (level_key),
            KEY growth_range (min_growth, max_growth),
            KEY status_sort (status, sort_order)
        ) {$charset_collate};";
        dbDelta($sql_growth_levels);

        // 用户成长账户表
        $table_user_growth = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'user_growth';
        $sql_user_growth = "CREATE TABLE {$table_user_growth} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            growth_value DECIMAL(12,2) NOT NULL DEFAULT 0,
            level_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            highest_level_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            last_active_date DATE DEFAULT NULL,
            continuous_active_days INT(11) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id),
            KEY level_id (level_id),
            KEY highest_level_id (highest_level_id),
            KEY growth_value (growth_value)
        ) {$charset_collate};";
        dbDelta($sql_user_growth);

        // 成长值流水表
        $table_growth_log = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'growth_log';
        $sql_growth_log = "CREATE TABLE {$table_growth_log} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            balance_after DECIMAL(12,2) NOT NULL DEFAULT 0,
            source VARCHAR(50) NOT NULL DEFAULT '',
            source_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            dedupe_key VARCHAR(64) DEFAULT NULL,
            description VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY dedupe_key (dedupe_key),
            KEY user_id (user_id),
            KEY source (source),
            KEY source_id (source_id),
            KEY user_source (user_id, source, source_id),
            KEY user_id_id (user_id, id),
            KEY user_created (user_id, created_at),
            KEY source_created (source, created_at),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql_growth_log);

        // 成长等级权益配置表（权益归属完全由后台配置）
        $table_growth_benefits = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'growth_benefits';
        $sql_growth_benefits = "CREATE TABLE {$table_growth_benefits} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            level_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            benefit_type VARCHAR(50) NOT NULL DEFAULT '',
            benefit_name VARCHAR(100) NOT NULL DEFAULT '',
            benefit_value VARCHAR(100) DEFAULT NULL,
            benefit_config LONGTEXT DEFAULT NULL,
            display_title VARCHAR(100) NOT NULL DEFAULT '',
            display_desc TEXT DEFAULT NULL,
            icon VARCHAR(255) DEFAULT NULL,
            status TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT(11) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY level_id (level_id),
            KEY benefit_type (benefit_type),
            KEY status_sort (status, sort_order)
        ) {$charset_collate};";
        dbDelta($sql_growth_benefits);

        // 成长值来源规则表（默认关闭，由后台启用）
        $table_growth_rules = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'growth_rules';
        $sql_growth_rules = "CREATE TABLE {$table_growth_rules} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            rule_key VARCHAR(64) NOT NULL,
            rule_name VARCHAR(100) NOT NULL,
            source VARCHAR(50) NOT NULL DEFAULT '',
            trigger_event VARCHAR(80) NOT NULL DEFAULT '',
            growth_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            growth_type VARCHAR(20) NOT NULL DEFAULT 'fixed',
            max_single_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            max_daily_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            max_monthly_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            config LONGTEXT DEFAULT NULL,
            status TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT(11) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY rule_key (rule_key),
            KEY source (source),
            KEY trigger_event (trigger_event),
            KEY status_sort (status, sort_order)
        ) {$charset_collate};";
        dbDelta($sql_growth_rules);

        // 提现记录表
        $table_withdrawals = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'withdrawals';
        $sql_withdrawals = "CREATE TABLE {$table_withdrawals} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            fee DECIMAL(10,2) NOT NULL DEFAULT 0,
            actual_amount DECIMAL(10,2) NOT NULL,
            account_type VARCHAR(50) NOT NULL DEFAULT 'alipay',
            account_name VARCHAR(100) NOT NULL,
            account_no VARCHAR(100) NOT NULL,
            status TINYINT(1) NOT NULL DEFAULT 0,
            admin_note VARCHAR(500) DEFAULT NULL,
            ip_address VARCHAR(50) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY user_status (user_id, status),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql_withdrawals);

        // 作者提成流水表
        $table_author_commissions = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'author_commissions';
        $sql_author_commissions = "CREATE TABLE {$table_author_commissions} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            author_id BIGINT(20) UNSIGNED NOT NULL,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            order_no VARCHAR(50) NOT NULL,
            post_id BIGINT(20) UNSIGNED NOT NULL,
            post_title VARCHAR(200) DEFAULT NULL,
            buyer_id BIGINT(20) UNSIGNED NOT NULL,
            order_amount DECIMAL(10,2) NOT NULL,
            commission_rate DECIMAL(5,2) NOT NULL,
            commission_amount DECIMAL(10,2) NOT NULL,
            status TINYINT(1) NOT NULL DEFAULT 1,
            balance_applied TINYINT(1) NOT NULL DEFAULT 0,
            balance_applied_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY order_once (order_id),
            KEY author_id (author_id),
            KEY order_id (order_id),
            KEY post_id (post_id),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql_author_commissions);

        // 注册码相关数据表
        if (class_exists('QilingShop_Registration_Code')) {
            QilingShop_Registration_Code::create_tables();
        }
    }

    /**
     * 创建默认设置
     *
     * @since 1.0.0
     */
    private static function create_default_options() {
        $default_options = [
            // 基础设置
            'qilingshop_points_name' => '积分',
            'qilingshop_points_ratio' => 10,  // 1元 = 10积分
            'qilingshop_currency_symbol' => '¥',
            'qilingshop_page_order_query' => 0,
            'qls_shop_page_order_query' => 0,
            'qilingshop_register_code_enabled' => false,
            'qilingshop_ip_trust_mode' => 'auto_proxy',
            'qilingshop_trusted_proxy_list' => [],
            'qilingshop_order_points_rebate_enabled' => false,
            'qilingshop_order_points_rebate_rate' => 0,
            'qilingshop_birthday_coupon_enabled' => false,
            'qilingshop_birthday_coupon_id' => 0,
            'qilingshop_task_check_key' => '',
            'qilingshop_growth_enabled' => false,
            'qilingshop_growth_name' => '成长值',
            'qilingshop_growth_frontend_display' => true,
            'qilingshop_growth_show_highest_level' => false,
            'qilingshop_growth_allow_admin_adjust' => true,
            
            // 注册奖励
            'qilingshop_register_bonus_enabled' => false,
            'qilingshop_register_bonus_amount' => 0,
            'qilingshop_register_bonus_stack_invite' => false, // 是否与邀请奖励叠加
            
            // 签到奖励
            'qilingshop_checkin_enabled' => true,
            'qilingshop_checkin_base_points' => 1,
            'qilingshop_checkin_consecutive_bonus' => true,
            'qilingshop_checkin_max_consecutive_bonus' => 7, // 最多连续7天奖励
            'qilingshop_points_validity_enabled' => false,
            'qilingshop_points_validity_days' => 365,
            'qilingshop_points_expire_remind_enabled' => true,
            'qilingshop_points_expire_remind_days' => '7,3,1',
            
            // 充值设置
            'qilingshop_recharge_min_amount' => 1,
            'qilingshop_recharge_max_amount' => 10000,
            
            // 推广设置
            'qilingshop_affiliate_enabled' => true,
            'qilingshop_affiliate_level1_rate' => 10, // 一级推广提成 10%
            'qilingshop_affiliate_level2_rate' => 5,  // 二级推广提成 5%
            'qilingshop_affiliate_vip_enabled' => true, // VIP购买是否计入提成
            'qilingshop_invite_bonus_inviter' => 5,   // 邀请人奖励
            'qilingshop_invite_bonus_invitee' => 5,   // 被邀请人奖励
            'qilingshop_invite_ip_limit' => true,     // 同IP限制
            'qilingshop_risk_control_enabled' => true, // 统一风控总开关
            'qilingshop_risk_invite_ip_daily_limit' => 20, // 邀请同IP每日上限（按邀请人）
            'qilingshop_risk_invite_ip_cooldown' => 30, // 邀请同IP冷却秒数
            'qilingshop_risk_checkin_ip_daily_limit' => 200, // 签到同IP每日上限
            'qilingshop_risk_assist_create_user_cooldown' => 15, // 助力发起冷却秒数
            'qilingshop_risk_assist_help_user_hour_limit' => 120, // 助力同用户每小时上限
            'qilingshop_risk_assist_help_ip_hour_limit' => 500, // 助力同IP每小时上限
            'qilingshop_risk_assist_help_user_cooldown' => 3, // 助力同用户冷却秒数
            'qilingshop_invite_tier_enabled' => false, // 邀请阶梯奖励
            'qilingshop_invite_tier_metric' => 'registration', // 邀请阶梯统计口径：registration|first_paid
            
            // 提现设置
            'qilingshop_withdraw_min_amount' => 100,
            'qilingshop_withdraw_fee_rate' => 0,      // 提现手续费 %
            
            // 下载设置
            'qilingshop_download_key' => wp_generate_password(12, false),
            'qilingshop_free_download_wait' => 0,     // 免费下载等待时间（秒）
            'qilingshop_paid_download_wait' => 0,     // 付费下载等待时间（秒）
            'qilingshop_trade_feed_enabled' => false,
            'qilingshop_trade_feed_virtual_enabled' => false,
            'qilingshop_trade_feed_interval_min' => 4,
            'qilingshop_trade_feed_interval_max' => 8,
            'qilingshop_trade_feed_batch_size' => 20,
            'qilingshop_trade_feed_virtual_ratio' => 60,
            'qilingshop_trade_feed_cache_ttl' => 120,
            'qilingshop_trade_feed_cache_ver' => 1,
            
            // 购买设置
            'qilingshop_direct_pay_enabled' => true,  // 允许直接支付购买
            'qilingshop_ajax_buy_enabled' => true,    // Ajax无跳转购买
            
            // 免登录购买
            'qilingshop_guest_buy_enabled' => false,
            'qilingshop_guest_cookie_days' => 30,
            // 默认关闭 IP 放行，改为基于设备指纹（cookie + UA）校验
            'qilingshop_guest_ip_verify' => false,
            
            // 作者分成
            'qilingshop_author_commission_enabled' => false,
            'qilingshop_author_commission_rate' => 80, // 作者获得80%
            
            // 通知设置
            'qilingshop_admin_email_recharge' => false,
            'qilingshop_admin_email_order' => false,
            'qilingshop_notify_recharge_method' => '',
            'qilingshop_notify_recharge_push_channel' => [],
            'qilingshop_notify_recharge_admin_enabled' => true,
            'qilingshop_notify_recharge_user_enabled' => true,
            'qilingshop_notify_order_method' => '',
            'qilingshop_notify_order_push_channel' => [],
            'qilingshop_notify_order_admin_enabled' => true,
            'qilingshop_notify_order_user_enabled' => true,
            'qilingshop_notify_vip_method' => '',
            'qilingshop_notify_vip_push_channel' => [],
            'qilingshop_notify_vip_admin_enabled' => true,
            'qilingshop_notify_vip_user_enabled' => true,
            'qilingshop_notify_shop_method' => '',
            'qilingshop_notify_shop_push_channel' => [],
            // 新版细分通知场景开关（管理员/用户独立）
            'qilingshop_notify_vip_expiring_admin_enabled' => false,
            'qilingshop_notify_vip_expiring_user_enabled' => true,
            'qilingshop_notify_vip_expired_admin_enabled' => false,
            'qilingshop_notify_vip_expired_user_enabled' => true,
            'qilingshop_notify_checkin_admin_enabled' => false,
            'qilingshop_notify_checkin_user_enabled' => true,
            'qilingshop_notify_invite_registered_admin_enabled' => false,
            'qilingshop_notify_invite_registered_user_enabled' => true,
            'qilingshop_notify_invite_tier_admin_enabled' => false,
            'qilingshop_notify_invite_tier_user_enabled' => true,
            'qilingshop_notify_affiliate_commission_admin_enabled' => false,
            'qilingshop_notify_affiliate_commission_user_enabled' => true,
            'qilingshop_notify_author_commission_admin_enabled' => false,
            'qilingshop_notify_author_commission_user_enabled' => true,
            'qilingshop_notify_withdraw_submitted_admin_enabled' => true,
            'qilingshop_notify_withdraw_submitted_user_enabled' => true,
            'qilingshop_notify_withdraw_approved_admin_enabled' => false,
            'qilingshop_notify_withdraw_approved_user_enabled' => true,
            'qilingshop_notify_withdraw_rejected_admin_enabled' => false,
            'qilingshop_notify_withdraw_rejected_user_enabled' => true,
            'qilingshop_notify_points_expiring_admin_enabled' => false,
            'qilingshop_notify_points_expiring_user_enabled' => true,
            'qilingshop_notify_points_expired_admin_enabled' => false,
            'qilingshop_notify_points_expired_user_enabled' => true,
            'qilingshop_notify_birthday_coupon_admin_enabled' => false,
            'qilingshop_notify_birthday_coupon_user_enabled' => true,
            'qilingshop_notify_task_reward_admin_enabled' => false,
            'qilingshop_notify_task_reward_user_enabled' => true,
            'qilingshop_notify_payment_recovery_user_enabled' => true,
            'qilingshop_notify_group_success_admin_enabled' => false,
            'qilingshop_notify_group_success_user_enabled' => true,
            'qilingshop_notify_group_failed_admin_enabled' => false,
            'qilingshop_notify_group_failed_user_enabled' => true,
            'qilingshop_notify_shop_paid_admin_enabled' => true,
            'qilingshop_notify_shop_paid_user_enabled' => true,
            'qilingshop_notify_shop_shipped_admin_enabled' => true,
            'qilingshop_notify_shop_shipped_user_enabled' => true,
            'qilingshop_notify_shop_completed_admin_enabled' => true,
            'qilingshop_notify_shop_completed_user_enabled' => true,
            'qilingshop_notify_shop_cancelled_admin_enabled' => true,
            'qilingshop_notify_shop_cancelled_user_enabled' => true,
            'qilingshop_notify_shop_refund_applied_admin_enabled' => true,
            'qilingshop_notify_shop_refund_applied_user_enabled' => true,
            'qilingshop_notify_shop_refunded_admin_enabled' => true,
            'qilingshop_notify_shop_refunded_user_enabled' => true,
            'qilingshop_notify_shop_ticket_created_admin_enabled' => true,
            'qilingshop_notify_shop_ticket_created_user_enabled' => false,
            'qilingshop_notify_shop_ticket_user_replied_admin_enabled' => true,
            'qilingshop_notify_shop_ticket_user_replied_user_enabled' => false,
            'qilingshop_notify_shop_ticket_admin_replied_admin_enabled' => false,
            'qilingshop_notify_shop_ticket_admin_replied_user_enabled' => true,
            'qilingshop_task_first_invite_enabled' => false,
            'qilingshop_task_first_invite_points' => 0,
            'qilingshop_task_first_resource_order_enabled' => false,
            'qilingshop_task_first_resource_order_points' => 0,
            'qilingshop_task_first_shop_paid_enabled' => false,
            'qilingshop_task_first_shop_paid_points' => 0,
            'qilingshop_task_payment_recovery_remind_enabled' => false,
            'qilingshop_task_payment_recovery_notify_channels' => array( 'site', 'email' ),
            'qilingshop_task_payment_recovery_delay_minutes' => 30,
            'qilingshop_task_payment_recovery_lookback_days' => 7,
            'qilingshop_task_payment_recovery_dedupe_days' => 7,
            'qilingshop_xhpay_enabled' => false,
            'qilingshop_xhpay_default_type' => 'alipay',
            'qilingshop_xhpay_plugin_id' => 'qilingshop-xhpay3',
            'qilingshop_xhpay_appid_wechat' => '',
            'qilingshop_xhpay_appsecret_wechat' => '',
            'qilingshop_xhpay_api_wechat' => 'https://api.xunhupay.com/payment/do.html',
            'qilingshop_xhpay_appid_alipay' => '',
            'qilingshop_xhpay_appsecret_alipay' => '',
            'qilingshop_xhpay_api_alipay' => 'https://api.xunhupay.com/payment/do.html',
            'qilingshop_epay_enabled' => false,
            'qilingshop_epay_pid' => '',
            'qilingshop_epay_key' => '',
            'qilingshop_epay_api_url' => '',
            'qilingshop_epay_default_type' => 'alipay',
            'qilingshop_stripe_enabled' => false,
            'qilingshop_stripe_publishable_key' => '',
            'qilingshop_stripe_secret_key' => '',
            'qilingshop_stripe_webhook_secret' => '',
            'qilingshop_stripe_currency' => 'usd',
            'qilingshop_stripe_rate' => 7,
            
            // 支付接口（默认空）
            'qilingshop_payment_gateways' => [],
            'qilingshop_shop_refund_mode' => 'withdrawable_balance',
            
            // 默认文案
            'qilingshop_tips_download' => '',
            'qilingshop_tips_view' => '',
            'qilingshop_tips_recharge' => '',
            'qilingshop_kefu_info' => '',
            
            // MetaBox 默认值
            'qilingshop_default_price' => 0,
            'qilingshop_default_vip_discount' => 'none',
            'qilingshop_post_types' => ['post'],
        ];

        foreach ($default_options as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }

    /**
     * 创建默认 VIP 等级
     *
     * @since 1.0.0
     */
    private static function create_default_vip_levels() {
        global $wpdb;
        
        $table = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'vip_levels';
        
        // 检查是否已有数据
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($count > 0) {
            return;
        }

        $default_levels = [
            [
                'level_key' => 'monthly',
                'level_name' => '月度会员',
                'price' => 29.00,
                'original_price' => 39.00,
                'duration_days' => 30,
                'discount_rate' => 90,
                'can_download_free' => 0,
                'daily_download_limit' => 10,
                'description' => '享受9折优惠，每日可下载10个资源',
                'badge_color' => '#67c23a',
                'sort_order' => 1,
            ],
            [
                'level_key' => 'quarterly',
                'level_name' => '季度会员',
                'price' => 79.00,
                'original_price' => 117.00,
                'duration_days' => 90,
                'discount_rate' => 80,
                'can_download_free' => 0,
                'daily_download_limit' => 20,
                'description' => '享受8折优惠，每日可下载20个资源',
                'badge_color' => '#409eff',
                'sort_order' => 2,
            ],
            [
                'level_key' => 'yearly',
                'level_name' => '年度会员',
                'price' => 199.00,
                'original_price' => 348.00,
                'duration_days' => 365,
                'discount_rate' => 50,
                'can_download_free' => 1,
                'daily_download_limit' => 50,
                'description' => '享受5折优惠，大部分资源免费下载',
                'badge_color' => '#e6a23c',
                'sort_order' => 3,
            ],
            [
                'level_key' => 'lifetime',
                'level_name' => '终身会员',
                'price' => 399.00,
                'original_price' => 999.00,
                'duration_days' => 999999,
                'discount_rate' => 0,
                'can_download_free' => 1,
                'daily_download_limit' => -1,
                'description' => '全站资源免费下载，无任何限制',
                'badge_color' => '#f56c6c',
                'sort_order' => 4,
            ],
        ];

        foreach ($default_levels as $level) {
            $wpdb->insert($table, $level);
        }
    }

    /**
     * 初始化任务触发策略
     * 说明：插件不注册 WP-Cron，统一由外部监控访问任务中心入口触发。
     *
     * @since 1.0.0
     */
    private static function schedule_cron_jobs() {
        // 所有定时任务统一改为外部监控触发（见 QilingShop_Task_Center）
    }

    /**
     * 创建默认成长等级。
     *
     * 成长等级独立于 VIP 等级，默认仅用于展示和后续权益配置。
     *
     * @return void
     */
    private static function create_default_growth_levels() {
        global $wpdb;

        $table = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'growth_levels';
        $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($table_exists !== $table) {
            return;
        }

        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($count > 0) {
            return;
        }

        $levels = [
            ['level_key' => 'lv1', 'level_name' => 'Lv1 新芽用户', 'min_growth' => 0, 'max_growth' => 99, 'level_color' => '#64748b', 'description' => '刚开始积累成长值的用户', 'sort_order' => 1],
            ['level_key' => 'lv2', 'level_name' => 'Lv2 活跃用户', 'min_growth' => 100, 'max_growth' => 499, 'level_color' => '#16a34a', 'description' => '保持基础活跃的用户', 'sort_order' => 2],
            ['level_key' => 'lv3', 'level_name' => 'Lv3 进阶用户', 'min_growth' => 500, 'max_growth' => 1499, 'level_color' => '#2563eb', 'description' => '持续参与站内互动的用户', 'sort_order' => 3],
            ['level_key' => 'lv4', 'level_name' => 'Lv4 核心用户', 'min_growth' => 1500, 'max_growth' => 4999, 'level_color' => '#d97706', 'description' => '高活跃和高贡献用户', 'sort_order' => 4],
            ['level_key' => 'lv5', 'level_name' => 'Lv5 荣耀用户', 'min_growth' => 5000, 'max_growth' => null, 'level_color' => '#dc2626', 'description' => '长期高贡献用户', 'sort_order' => 5],
        ];

        foreach ($levels as $level) {
            $wpdb->insert($table, array_merge($level, [
                'status' => 1,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ]));
        }
    }

    /**
     * 创建默认成长规则。
     *
     * 默认全部关闭，只提供后台可配置的规则模板。
     *
     * @return void
     */
    private static function create_default_growth_rules() {
        global $wpdb;

        $table = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'growth_rules';
        $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($table_exists !== $table) {
            return;
        }

        $rules = [
            ['daily_visit', '每日访问', 'daily_visit', 'daily_visit', 'fixed', 0, 1],
            ['checkin', '每日签到', 'checkin', 'checkin', 'fixed', 0, 2],
            ['resource_order_paid', '资源订单支付', 'resource_order_paid', 'resource_order_paid', 'fixed', 0, 3],
            ['shop_order_paid', '商城订单支付', 'shop_order_paid', 'shop_order_paid', 'amount_rate', 0, 4],
            ['review_submit', '提交评价', 'review_submit', 'review_submit', 'fixed', 0, 5],
            ['review_with_image', '带图评价', 'review_with_image', 'review_with_image', 'fixed', 0, 6],
            ['invite_registered', '邀请注册', 'invite_registered', 'invite_registered', 'fixed', 0, 7],
            ['task_completed', '任务完成', 'task_completed', 'task_completed', 'fixed', 0, 8],
        ];

        foreach ($rules as $rule) {
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE rule_key = %s LIMIT 1", $rule[0]));
            if ($exists) {
                continue;
            }

            $wpdb->insert($table, [
                'rule_key' => $rule[0],
                'rule_name' => $rule[1],
                'source' => $rule[2],
                'trigger_event' => $rule[3],
                'growth_type' => $rule[4],
                'growth_amount' => $rule[5],
                'status' => 0,
                'sort_order' => $rule[6],
                'config' => '{}',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ]);
        }
    }

    /**
     * 为支付召回扫描补充复合索引（升级迁移）
     *
     * @return void
     */
    private static function ensure_payment_recovery_indexes() {
        global $wpdb;
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $orders_table = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'orders';
        $recharge_table = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'recharge';

        self::maybe_add_index($orders_table, 'status_user_created', '`status`, `user_id`, `created_at`');
        self::maybe_add_index($recharge_table, 'status_user_created', '`status`, `user_id`, `created_at`');
    }

    /**
     * 资源/VIP 订单支付收尾幂等字段迁移
     *
     * @return void
     */
    private static function ensure_order_payment_idempotency_schema() {
        global $wpdb;
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $orders_table = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'orders';
        $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $orders_table));
        if ($table_exists !== $orders_table) {
            return;
        }

        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$orders_table} LIKE 'paid_handled'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$orders_table} ADD COLUMN paid_handled TINYINT(1) NOT NULL DEFAULT 0 AFTER paid_at");
        }

        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$orders_table} LIKE 'paid_handled_at'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$orders_table} ADD COLUMN paid_handled_at DATETIME DEFAULT NULL AFTER paid_handled");
        }
    }

    /**
     * 充值订单支付收尾幂等字段迁移
     *
     * @return void
     */
    private static function ensure_recharge_payment_idempotency_schema() {
        global $wpdb;
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $recharge_table = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'recharge';
        $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $recharge_table));
        if ($table_exists !== $recharge_table) {
            return;
        }

        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$recharge_table} LIKE 'paid_handled'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$recharge_table} ADD COLUMN paid_handled TINYINT(1) NOT NULL DEFAULT 0 AFTER paid_at");
        }

        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$recharge_table} LIKE 'paid_handled_at'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$recharge_table} ADD COLUMN paid_handled_at DATETIME DEFAULT NULL AFTER paid_handled");
        }
    }

    /**
     * 资源/VIP 订单支付渠道元数据字段迁移。
     *
     * @return void
     */
    private static function ensure_order_payment_channel_schema() {
        global $wpdb;
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $orders_table = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'orders';
        $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $orders_table));
        if ($table_exists !== $orders_table) {
            return;
        }

        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$orders_table} LIKE 'payment_channel_meta'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$orders_table} ADD COLUMN payment_channel_meta LONGTEXT DEFAULT NULL AFTER payment_no");
        }
    }

    /**
     * 充值订单支付渠道元数据字段迁移。
     *
     * @return void
     */
    private static function ensure_recharge_payment_channel_schema() {
        global $wpdb;
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $recharge_table = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'recharge';
        $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $recharge_table));
        if ($table_exists !== $recharge_table) {
            return;
        }

        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$recharge_table} LIKE 'payment_channel_meta'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$recharge_table} ADD COLUMN payment_channel_meta LONGTEXT DEFAULT NULL AFTER payment_no");
        }
    }

    /**
     * 推广佣金幂等字段与唯一约束迁移
     *
     * @return void
     */
    private static function ensure_affiliate_idempotency_schema() {
        global $wpdb;
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $affiliate_table = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'affiliate';
        $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $affiliate_table));
        if ($table_exists !== $affiliate_table) {
            return;
        }

        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$affiliate_table} LIKE 'balance_applied'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$affiliate_table} ADD COLUMN balance_applied TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
        }

        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$affiliate_table} LIKE 'balance_applied_at'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$affiliate_table} ADD COLUMN balance_applied_at DATETIME DEFAULT NULL AFTER balance_applied");
        }

        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$affiliate_table} LIKE 'points_applied'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$affiliate_table} ADD COLUMN points_applied TINYINT(1) NOT NULL DEFAULT 0 AFTER balance_applied_at");
        }

        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$affiliate_table} LIKE 'points_applied_at'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$affiliate_table} ADD COLUMN points_applied_at DATETIME DEFAULT NULL AFTER points_applied");
        }

        self::maybe_add_unique_index($affiliate_table, 'commission_once', '`from_user_id`, `source`, `order_no`, `level`');
    }

    /**
     * 作者分成幂等字段与唯一约束迁移
     *
     * @return void
     */
    private static function ensure_author_commission_idempotency_schema() {
        global $wpdb;
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $table = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'author_commissions';
        $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($table_exists !== $table) {
            return;
        }

        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'balance_applied'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN balance_applied TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
        }

        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'balance_applied_at'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN balance_applied_at DATETIME DEFAULT NULL AFTER balance_applied");
        }

        self::maybe_add_unique_index($table, 'order_once', '`order_id`');
    }

    /**
     * 邀请阶梯奖励幂等字段迁移
     *
     * @return void
     */
    private static function ensure_invite_tier_idempotency_schema() {
        global $wpdb;
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $table = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'invite_tier_logs';
        $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($table_exists !== $table) {
            return;
        }

        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'reward_status'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN reward_status TINYINT(1) NOT NULL DEFAULT 0 AFTER bonus_points");
        }

        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'reward_status_at'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN reward_status_at DATETIME DEFAULT NULL AFTER reward_status");
        }

        self::maybe_add_unique_index($table, 'user_rule', '`user_id`, `rule_id`');
    }

    /**
     * 邀请奖励幂等字段迁移
     *
     * @return void
     */
    private static function ensure_invite_bonus_idempotency_schema() {
        global $wpdb;
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $table = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'invites';
        $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($table_exists !== $table) {
            return;
        }

        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'bonus_paid_at'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN bonus_paid_at DATETIME DEFAULT NULL AFTER bonus_paid");
        }
    }

    /**
     * 成长流水幂等键迁移。
     *
     * @return void
     */
    private static function ensure_growth_log_idempotency_schema() {
        global $wpdb;
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $table = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'growth_log';
        $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($table_exists !== $table) {
            return;
        }

        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'dedupe_key'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN dedupe_key VARCHAR(64) DEFAULT NULL AFTER source_id");
        }

        self::maybe_add_unique_index($table, 'dedupe_key', '`dedupe_key`');
    }

    /**
     * 成长流水查询/清理性能索引。
     *
     * @return void
     */
    private static function ensure_growth_log_query_indexes() {
        global $wpdb;
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $table = $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'growth_log';
        self::maybe_add_index($table, 'user_id_id', '`user_id`, `id`');
        self::maybe_add_index($table, 'user_created', '`user_id`, `created_at`');
        self::maybe_add_index($table, 'source_created', '`source`, `created_at`');
    }

    /**
     * 索引不存在时创建索引
     *
     * @param string $table
     * @param string $index_name
     * @param string $columns_sql
     * @return void
     */
    private static function maybe_add_index($table, $index_name, $columns_sql) {
        global $wpdb;
        if (!$wpdb) {
            return;
        }

        $table = (string) $table;
        $index_name = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $index_name);
        if ($index_name === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return;
        }

        $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($table_exists !== $table) {
            return;
        }

        $indexes = $wpdb->get_results("SHOW INDEX FROM `{$table}`");
        if (is_array($indexes)) {
            foreach ($indexes as $idx) {
                if (isset($idx->Key_name) && (string) $idx->Key_name === $index_name) {
                    return;
                }
            }
        }

        $wpdb->query("ALTER TABLE `{$table}` ADD INDEX `{$index_name}` ({$columns_sql})");
    }

    /**
     * 唯一索引不存在时创建唯一索引
     *
     * @param string $table
     * @param string $index_name
     * @param string $columns_sql
     * @return void
     */
    private static function maybe_add_unique_index($table, $index_name, $columns_sql) {
        global $wpdb;
        if (!$wpdb) {
            return;
        }

        $table = (string) $table;
        $index_name = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $index_name);
        if ($index_name === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return;
        }

        $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($table_exists !== $table) {
            return;
        }

        $indexes = $wpdb->get_results("SHOW INDEX FROM `{$table}`");
        if (is_array($indexes)) {
            foreach ($indexes as $idx) {
                if (isset($idx->Key_name) && (string) $idx->Key_name === $index_name) {
                    return;
                }
            }
        }

        $wpdb->query("ALTER TABLE `{$table}` ADD UNIQUE INDEX `{$index_name}` ({$columns_sql})");
    }
}
