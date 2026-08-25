<?php
/**
 * 电商模块激活器
 * 
 * 负责创建电商相关数据库表和初始化默认设置
 *
 * @package QilingShop
 * @since   2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 电商数据表前缀
 */
if (!defined('QLS_SHOP_TABLE_PREFIX')) {
    define('QLS_SHOP_TABLE_PREFIX', 'qls_shop_');
}

class QLS_Shop_Activator {
    /**
     * 数据结构版本
     */
    const DB_VERSION = '2.3.9';

    /**
     * 升级锁，避免多个请求同时执行 dbDelta 造成重复字段/索引日志。
     */
    const UPGRADE_LOCK_OPTION = 'qls_shop_db_upgrade_lock';
    const UPGRADE_LOCK_TTL = 600;

    /**
     * 激活电商模块
     */
    public static function activate() {
        self::create_tables();
        self::ensure_service_tag_icon_schema();
        self::ensure_order_scan_indexes();
        self::ensure_order_admin_search_indexes();
        self::ensure_order_guest_query_lookup_schema();
        self::ensure_payment_idempotency_schema();
        self::ensure_coupon_integrity_indexes();
        self::ensure_refund_processing_schema();
        self::ensure_refund_after_sales_schema();
        self::ensure_order_payment_channel_schema();
        self::ensure_refund_gateway_schema();
        self::ensure_cart_integrity_schema();
        self::ensure_card_inventory_indexes();
        self::ensure_product_points_indexes();
        self::ensure_review_integrity_schema();
        self::ensure_order_fulfillment_schema();
        self::ensure_order_stock_state_schema();
        self::create_default_options();
        self::create_default_pages();
        self::remove_deprecated_order_success_page();
        self::create_default_shipping_rules();
        self::create_default_shipping_companies();
        self::create_default_service_tags();
        self::migrate_default_service_tag_icons();
        self::schedule_cron_jobs();
        self::backfill_legacy_shipments();
        
        // 记录版本
        update_option('qls_shop_version', '2.0.3');
        update_option('qls_shop_db_version', self::DB_VERSION);
        update_option('qls_shop_legacy_shipments_backfilled_220', 1, false);
        update_option('qls_shop_service_tag_icons_migrated_234', 1, false);
        
        // 标记需要刷新重写规则
        update_option('qls_shop_flush_rewrite', true);
        
        /**
         * 电商模块激活后钩子
         */
        do_action('qls_shop_activated');
    }

    /**
     * 数据库升级检查
     */
    public static function maybe_upgrade() {
        self::create_default_options();
        self::remove_deprecated_order_success_page();
        if (!QLS_Shop_Page_Manager::all_pages_have_valid_options(QLS_Shop_Page_Manager::get_default_shop_page_definitions())) {
            self::create_default_pages();
        }
        $version = (string) get_option('qls_shop_db_version', '0');
        if (version_compare($version, self::DB_VERSION, '<')) {
            if (!self::acquire_upgrade_lock()) {
                return;
            }

            try {
                $version = (string) get_option('qls_shop_db_version', '0');
                if (version_compare($version, self::DB_VERSION, '<')) {
                    self::create_tables();
                    self::ensure_service_tag_icon_schema();
                    self::ensure_order_scan_indexes();
                    self::ensure_order_admin_search_indexes();
                    self::ensure_order_guest_query_lookup_schema();
                    self::ensure_payment_idempotency_schema();
                    self::ensure_coupon_integrity_indexes();
                    self::ensure_refund_processing_schema();
                    self::ensure_refund_after_sales_schema();
                    self::ensure_order_payment_channel_schema();
                    self::ensure_refund_gateway_schema();
                    self::ensure_cart_integrity_schema();
                    self::ensure_card_inventory_indexes();
                    self::ensure_product_points_indexes();
                    self::ensure_review_integrity_schema();
                    self::ensure_order_fulfillment_schema();
                    self::ensure_order_stock_state_schema();
                    self::create_default_pages();
                    self::create_default_shipping_companies();
                    self::backfill_legacy_shipments();
                    update_option('qls_shop_db_version', self::DB_VERSION);
                }
            } finally {
                self::release_upgrade_lock();
            }
        }

        self::ensure_ticket_schema();

        if (!get_option('qls_shop_service_tag_icons_migrated_234', false)) {
            self::ensure_service_tag_icon_schema();
            self::migrate_default_service_tag_icons();
            update_option('qls_shop_service_tag_icons_migrated_234', 1, false);
        }

        if (!get_option('qls_shop_legacy_shipments_backfilled_220', false)) {
            self::backfill_legacy_shipments();
            update_option('qls_shop_legacy_shipments_backfilled_220', 1, false);
        }
    }

    /**
     * 初始化任务触发策略
     * 说明：电商任务由任务中心统一外部触发，不注册 WP-Cron。
     */
    private static function schedule_cron_jobs() {
        // 统一改为外部监控触发（见 QilingShop_Task_Center）
    }

    /**
     * 服务标签图标字段改为 TEXT，兼容表情、HTML 和启灵 icon-xxx。
     *
     * @return void
     */
    private static function ensure_service_tag_icon_schema() {
        global $wpdb;
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'service_tags';
        $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($table_exists !== $table) {
            return;
        }

        $column = $wpdb->get_row("SHOW COLUMNS FROM {$table} LIKE 'icon'");
        if (empty($column)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN icon TEXT DEFAULT NULL AFTER name");
            return;
        }

        $type = strtolower((string) ($column->Type ?? ''));
        if (strpos($type, 'text') === false) {
            $wpdb->query("ALTER TABLE {$table} MODIFY COLUMN icon TEXT DEFAULT NULL");
        }
    }

    /**
     * 将旧安装中的默认 dashicons 服务标签迁移为更通用的图标。
     *
     * @return void
     */
    private static function migrate_default_service_tag_icons() {
        global $wpdb;
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'service_tags';
        $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($table_exists !== $table) {
            return;
        }

        $tags = [
            '7天无理由退货' => [
                'icon' => '✅',
                'old'  => ['dashicons-yes', 'dashicons dashicons-yes'],
            ],
            '正品保证' => [
                'icon' => '🛡️',
                'old'  => ['dashicons-shield', 'dashicons dashicons-shield'],
            ],
            '送货上门' => [
                'icon' => '🚚',
                'old'  => ['dashicons-car', 'dashicons dashicons-car'],
            ],
            '极速发货' => [
                'icon' => '⚡',
                'old'  => ['dashicons-clock', 'dashicons dashicons-clock'],
            ],
            '售后无忧' => [
                'icon' => '💚',
                'old'  => ['dashicons-heart', 'dashicons dashicons-heart'],
            ],
        ];

        foreach ($tags as $name => $tag) {
            foreach ($tag['old'] as $old_icon) {
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$table} SET icon = %s WHERE name = %s AND icon = %s",
                        $tag['icon'],
                        $name,
                        $old_icon
                    )
                );
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
     * 兜底确保轻量工单表存在。
     *
     * @return void
     */
    private static function ensure_ticket_schema() {
        global $wpdb;
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $prefix = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX;
        $required_columns = [
            $prefix . 'tickets' => [
                'ticket_no',
                'user_id',
                'order_id',
                'source_type',
                'last_reply_by',
                'resolved_at',
                'closed_at',
            ],
            $prefix . 'ticket_messages' => [
                'ticket_id',
                'sender_type',
                'attachments',
                'is_internal',
            ],
        ];

        foreach ($required_columns as $table => $columns) {
            $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($table_exists !== $table) {
                self::create_tables();
                return;
            }

            foreach ($columns as $column) {
                $column_exists = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column));
                if (empty($column_exists)) {
                    self::create_tables();
                    return;
                }
            }
        }
    }

    /**
     * 创建数据库表
     */
    private static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX;
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // =============================================
        // 商品主表
        // =============================================
        $table_products = $prefix . 'products';
        $sql_products = "CREATE TABLE {$table_products} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(200) NOT NULL,
            subtitle VARCHAR(200) DEFAULT NULL,
            slug VARCHAR(200) NOT NULL,
            main_image TEXT DEFAULT NULL,
            gallery TEXT DEFAULT NULL,
            content LONGTEXT DEFAULT NULL,
            category_id BIGINT(20) UNSIGNED DEFAULT 0,
            brand VARCHAR(100) DEFAULT NULL,
            model VARCHAR(100) DEFAULT NULL,
            params TEXT DEFAULT NULL,
            service_tags TEXT DEFAULT NULL,
            shipping_rule_id BIGINT(20) UNSIGNED DEFAULT 0,
            min_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            max_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            total_stock INT(11) NOT NULL DEFAULT 0,
            sales_count INT(11) NOT NULL DEFAULT 0,
            view_count INT(11) NOT NULL DEFAULT 0,
            status TINYINT(1) NOT NULL DEFAULT 0,
            is_hot TINYINT(1) NOT NULL DEFAULT 0,
            new_user_special_enabled TINYINT(1) NOT NULL DEFAULT 0,
            new_user_special_price DECIMAL(10,2) DEFAULT NULL,
            activity_recommend_enabled TINYINT(1) NOT NULL DEFAULT 0,
            group_display_enabled TINYINT(1) NOT NULL DEFAULT 0,
            assist_display_enabled TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT(11) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY category_id (category_id),
            KEY status (status),
            KEY is_hot (is_hot),
            KEY new_user_special_enabled (new_user_special_enabled),
            KEY activity_recommend_enabled (activity_recommend_enabled),
            KEY group_display_enabled (group_display_enabled),
            KEY assist_display_enabled (assist_display_enabled),
            KEY sort_order (sort_order),
            KEY status_category (status, category_id),
            KEY status_category_created (status, category_id, created_at),
            KEY status_category_sales (status, category_id, sales_count),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql_products);

        // =============================================
        // 商品SKU表
        // =============================================
        $table_skus = $prefix . 'product_skus';
        $sql_skus = "CREATE TABLE {$table_skus} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            sku_code VARCHAR(100) DEFAULT NULL,
            attr_values TEXT DEFAULT NULL,
            image VARCHAR(500) DEFAULT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            sale_price DECIMAL(10,2) DEFAULT NULL,
            points_price DECIMAL(10,2) DEFAULT NULL,
            stock INT(11) NOT NULL DEFAULT 0,
            weight DECIMAL(10,2) NOT NULL DEFAULT 0,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY sku_code (sku_code),
            KEY is_default (is_default),
            KEY status_product_points_stock (status, product_id, points_price, stock)
        ) {$charset_collate};";
        dbDelta($sql_skus);

        // =============================================
        // 商品SKU会员价表
        // =============================================
        $table_sku_vip_prices = $prefix . 'product_sku_vip_prices';
        $sql_sku_vip_prices = "CREATE TABLE {$table_sku_vip_prices} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            sku_id BIGINT(20) UNSIGNED NOT NULL,
            level_id BIGINT(20) UNSIGNED NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY sku_level (sku_id, level_id),
            KEY sku_id (sku_id),
            KEY level_id (level_id)
        ) {$charset_collate};";
        dbDelta($sql_sku_vip_prices);

        // =============================================
        // 商品规格表
        // =============================================
        $table_attributes = $prefix . 'product_attributes';
        $sql_attributes = "CREATE TABLE {$table_attributes} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            name VARCHAR(50) NOT NULL,
            sort_order INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY product_id (product_id)
        ) {$charset_collate};";
        dbDelta($sql_attributes);

        // =============================================
        // 商品规格值表
        // =============================================
        $table_attr_values = $prefix . 'product_attribute_values';
        $sql_attr_values = "CREATE TABLE {$table_attr_values} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            attribute_id BIGINT(20) UNSIGNED NOT NULL,
            value VARCHAR(100) NOT NULL,
            image VARCHAR(500) DEFAULT NULL,
            versions TEXT DEFAULT NULL,
            sort_order INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY attribute_id (attribute_id)
        ) {$charset_collate};";
        dbDelta($sql_attr_values);

        // =============================================
        // 商品分类表
        // =============================================
        $table_categories = $prefix . 'categories';
        $sql_categories = "CREATE TABLE {$table_categories} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            name VARCHAR(100) NOT NULL,
            slug VARCHAR(100) NOT NULL,
            seo_keywords VARCHAR(255) DEFAULT NULL,
            image VARCHAR(500) DEFAULT NULL,
            description TEXT DEFAULT NULL,
            sort_order INT(11) NOT NULL DEFAULT 0,
            status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY parent_id (parent_id),
            KEY status (status),
            KEY sort_order (sort_order)
        ) {$charset_collate};";
        dbDelta($sql_categories);

        // =============================================
        // 商品特色表
        // =============================================
        $table_tags = $prefix . 'tags';
        $sql_tags = "CREATE TABLE {$table_tags} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            slug VARCHAR(100) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug)
        ) {$charset_collate};";
        dbDelta($sql_tags);

        // =============================================
        // 商品标签关联表
        // =============================================
        $table_tag_rels = $prefix . 'product_tag_relationships';
        $sql_tag_rels = "CREATE TABLE {$table_tag_rels} (
            product_id BIGINT(20) UNSIGNED NOT NULL,
            tag_id BIGINT(20) UNSIGNED NOT NULL,
            PRIMARY KEY (product_id, tag_id),
            KEY tag_id (tag_id)
        ) {$charset_collate};";
        dbDelta($sql_tag_rels);

        // =============================================
        // 购物车表
        // =============================================
        $table_cart = $prefix . 'cart_items';
        $sql_cart = "CREATE TABLE {$table_cart} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            session_id VARCHAR(100) DEFAULT NULL,
            owner_key VARCHAR(191) NOT NULL DEFAULT '',
            product_id BIGINT(20) UNSIGNED NOT NULL,
            sku_id BIGINT(20) UNSIGNED NOT NULL,
            quantity INT(11) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY session_id (session_id),
            KEY owner_key (owner_key),
            KEY product_id (product_id),
            UNIQUE KEY owner_product_sku (owner_key, product_id, sku_id)
        ) {$charset_collate};";
        dbDelta($sql_cart);

        // =============================================
        // 实物订单表
        // =============================================
        $table_orders = $prefix . 'orders';
        $sql_orders = "CREATE TABLE {$table_orders} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_no VARCHAR(50) NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            shipping_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
            discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            points_used DECIMAL(10,2) NOT NULL DEFAULT 0,
            final_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            payment_method VARCHAR(50) DEFAULT NULL,
            payment_no VARCHAR(100) DEFAULT NULL,
            payment_channel_version VARCHAR(20) DEFAULT NULL,
            payment_channel_meta LONGTEXT DEFAULT NULL,
            status TINYINT(1) NOT NULL DEFAULT 0,
            receiver_name VARCHAR(100) DEFAULT NULL,
            receiver_phone VARCHAR(20) DEFAULT NULL,
            receiver_province VARCHAR(50) DEFAULT NULL,
            receiver_city VARCHAR(50) DEFAULT NULL,
            receiver_district VARCHAR(50) DEFAULT NULL,
            receiver_address TEXT DEFAULT NULL,
            buyer_remark TEXT DEFAULT NULL,
            seller_remark TEXT DEFAULT NULL,
            guest_query_phone VARCHAR(32) NOT NULL DEFAULT '',
            guest_query_email VARCHAR(191) NOT NULL DEFAULT '',
            guest_query_password_hash VARCHAR(255) NOT NULL DEFAULT '',
            guest_query_password_expires_at DATETIME DEFAULT NULL,
            shipping_company VARCHAR(50) DEFAULT NULL,
            tracking_no VARCHAR(100) DEFAULT NULL,
            shipment_status TINYINT(1) NOT NULL DEFAULT 0,
            shipment_count INT(11) NOT NULL DEFAULT 0,
            ip_address VARCHAR(50) DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            paid_at DATETIME DEFAULT NULL,
            paid_handled TINYINT(1) NOT NULL DEFAULT 0,
            paid_handled_at DATETIME DEFAULT NULL,
            shipped_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            cancelled_at DATETIME DEFAULT NULL,
            stock_reduced TINYINT(1) NOT NULL DEFAULT 0,
            group_stock_reduced TINYINT(1) NOT NULL DEFAULT 0,
            group_stock_rule_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            group_stock_quantity INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY order_no (order_no),
            KEY user_id (user_id),
            KEY status (status),
            KEY status_user_created (status, user_id, created_at),
            KEY guest_query_phone_lookup (guest_query_phone, user_id, id),
            KEY guest_query_email_lookup (guest_query_email(100), user_id, id),
            KEY stock_reduced (stock_reduced),
            KEY group_stock_reduced (group_stock_reduced),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql_orders);

        // =============================================
        // 订单元数据表
        // =============================================
        $table_order_meta = $prefix . 'order_meta';
        $sql_order_meta = "CREATE TABLE {$table_order_meta} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            meta_key VARCHAR(191) NOT NULL,
            meta_value LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY order_meta (order_id, meta_key),
            KEY meta_key (meta_key)
        ) {$charset_collate};";
        dbDelta($sql_order_meta);

        // =============================================
        // 订单商品明细表
        // =============================================
        $table_order_items = $prefix . 'order_items';
        $sql_order_items = "CREATE TABLE {$table_order_items} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            sku_id BIGINT(20) UNSIGNED NOT NULL,
            product_title VARCHAR(200) NOT NULL,
            sku_attrs TEXT DEFAULT NULL,
            image VARCHAR(500) DEFAULT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            quantity INT(11) NOT NULL DEFAULT 1,
            total DECIMAL(10,2) NOT NULL DEFAULT 0,
            shipped_quantity INT(11) NOT NULL DEFAULT 0,
            virtual_content TEXT DEFAULT NULL,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY product_id (product_id)
        ) {$charset_collate};";
        dbDelta($sql_order_items);

        // =============================================
        // 发票抬头表
        // =============================================
        $table_invoice_titles = $prefix . 'invoice_titles';
        $sql_invoice_titles = "CREATE TABLE {$table_invoice_titles} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            title_type VARCHAR(20) NOT NULL DEFAULT 'personal',
            title VARCHAR(200) NOT NULL,
            tax_no VARCHAR(80) DEFAULT NULL,
            bank_name VARCHAR(120) DEFAULT NULL,
            bank_account VARCHAR(120) DEFAULT NULL,
            registered_address VARCHAR(255) DEFAULT NULL,
            registered_phone VARCHAR(50) DEFAULT NULL,
            email VARCHAR(120) DEFAULT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY user_default (user_id, is_default)
        ) {$charset_collate};";
        dbDelta($sql_invoice_titles);

        // =============================================
        // 发票申请表
        // =============================================
        $table_invoices = $prefix . 'invoices';
        $sql_invoices = "CREATE TABLE {$table_invoices} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            order_no VARCHAR(50) NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            invoice_type VARCHAR(20) NOT NULL DEFAULT 'electronic',
            title_type VARCHAR(20) NOT NULL DEFAULT 'personal',
            title VARCHAR(200) NOT NULL,
            tax_no VARCHAR(80) DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            email VARCHAR(120) DEFAULT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            status TINYINT(1) NOT NULL DEFAULT 0,
            invoice_code VARCHAR(100) DEFAULT NULL,
            invoice_number VARCHAR(100) DEFAULT NULL,
            invoice_url TEXT DEFAULT NULL,
            file_attachment_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            admin_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            admin_remark TEXT DEFAULT NULL,
            extra_data LONGTEXT DEFAULT NULL,
            requested_at DATETIME DEFAULT NULL,
            issued_at DATETIME DEFAULT NULL,
            rejected_at DATETIME DEFAULT NULL,
            cancelled_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY order_id (order_id),
            KEY order_no (order_no),
            KEY user_id (user_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql_invoices);

        // =============================================
        // 收货地址表
        // =============================================
        $table_addresses = $prefix . 'user_addresses';
        $sql_addresses = "CREATE TABLE {$table_addresses} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            name VARCHAR(100) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            province VARCHAR(50) DEFAULT NULL,
            city VARCHAR(50) DEFAULT NULL,
            district VARCHAR(50) DEFAULT NULL,
            address VARCHAR(300) NOT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY is_default (is_default)
        ) {$charset_collate};";
        dbDelta($sql_addresses);

        // =============================================
        // 运费规则表
        // =============================================
        $table_shipping = $prefix . 'shipping_rules';
        $sql_shipping = "CREATE TABLE {$table_shipping} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            type TINYINT(1) NOT NULL DEFAULT 0,
            base_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
            free_threshold DECIMAL(10,2) DEFAULT NULL,
            first_weight DECIMAL(10,2) DEFAULT NULL,
            weight_step DECIMAL(10,2) DEFAULT NULL,
            step_fee DECIMAL(10,2) DEFAULT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY is_default (is_default),
            KEY status (status)
        ) {$charset_collate};";
        dbDelta($sql_shipping);

        // =============================================
        // 物流公司表
        // =============================================
        $table_shipping_companies = $prefix . 'shipping_companies';
        $sql_shipping_companies = "CREATE TABLE {$table_shipping_companies} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            code VARCHAR(50) NOT NULL,
            aliases TEXT DEFAULT NULL,
            phone_required TINYINT(1) NOT NULL DEFAULT 0,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            status TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT(11) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            KEY status_sort (status, sort_order),
            KEY is_default (is_default)
        ) {$charset_collate};";
        dbDelta($sql_shipping_companies);

        // =============================================
        // 订单发货单表
        // =============================================
        $table_shipments = $prefix . 'shipments';
        $sql_shipments = "CREATE TABLE {$table_shipments} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            shipment_no VARCHAR(50) NOT NULL,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            order_no VARCHAR(50) NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            shipping_company_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            shipping_company VARCHAR(100) DEFAULT NULL,
            shipping_code VARCHAR(50) DEFAULT NULL,
            tracking_no VARCHAR(100) DEFAULT NULL,
            waybill_no VARCHAR(100) DEFAULT NULL,
            status TINYINT(1) NOT NULL DEFAULT 1,
            receiver_name VARCHAR(100) DEFAULT NULL,
            receiver_phone VARCHAR(50) DEFAULT NULL,
            receiver_province VARCHAR(50) DEFAULT NULL,
            receiver_city VARCHAR(50) DEFAULT NULL,
            receiver_district VARCHAR(50) DEFAULT NULL,
            receiver_address TEXT DEFAULT NULL,
            sender_snapshot LONGTEXT DEFAULT NULL,
            admin_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            remark TEXT DEFAULT NULL,
            shipped_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY shipment_no (shipment_no),
            KEY order_id (order_id),
            KEY order_status (order_id, status),
            KEY status (status),
            KEY tracking_no (tracking_no),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql_shipments);

        // =============================================
        // 订单发货明细表
        // =============================================
        $table_shipment_items = $prefix . 'shipment_items';
        $sql_shipment_items = "CREATE TABLE {$table_shipment_items} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            shipment_id BIGINT(20) UNSIGNED NOT NULL,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            order_item_id BIGINT(20) UNSIGNED NOT NULL,
            product_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            sku_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            product_title VARCHAR(200) DEFAULT NULL,
            sku_attrs TEXT DEFAULT NULL,
            quantity INT(11) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY shipment_id (shipment_id),
            KEY order_id (order_id),
            KEY order_item_id (order_item_id),
            KEY product_id (product_id)
        ) {$charset_collate};";
        dbDelta($sql_shipment_items);

        // =============================================
        // 电子面单模板表
        // =============================================
        $table_waybill_templates = $prefix . 'waybill_templates';
        $sql_waybill_templates = "CREATE TABLE {$table_waybill_templates} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            provider VARCHAR(50) NOT NULL DEFAULT 'kdniao',
            company_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            sender_name VARCHAR(100) DEFAULT NULL,
            sender_phone VARCHAR(50) DEFAULT NULL,
            sender_province VARCHAR(50) DEFAULT NULL,
            sender_city VARCHAR(50) DEFAULT NULL,
            sender_district VARCHAR(50) DEFAULT NULL,
            sender_address TEXT DEFAULT NULL,
            template_config LONGTEXT DEFAULT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY provider (provider),
            KEY company_id (company_id),
            KEY status (status),
            KEY is_default (is_default)
        ) {$charset_collate};";
        dbDelta($sql_waybill_templates);

        // =============================================
        // 电子面单日志表
        // =============================================
        $table_waybill_logs = $prefix . 'waybill_logs';
        $sql_waybill_logs = "CREATE TABLE {$table_waybill_logs} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            shipment_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            order_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            provider VARCHAR(50) NOT NULL DEFAULT 'kdniao',
            company_code VARCHAR(50) DEFAULT NULL,
            waybill_no VARCHAR(100) DEFAULT NULL,
            request_data LONGTEXT DEFAULT NULL,
            response_data LONGTEXT DEFAULT NULL,
            status TINYINT(1) NOT NULL DEFAULT 0,
            error_message TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY shipment_id (shipment_id),
            KEY order_id (order_id),
            KEY provider (provider),
            KEY waybill_no (waybill_no),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql_waybill_logs);

            $table_service_tags = $prefix . 'service_tags';
            $sql_service_tags = "CREATE TABLE {$table_service_tags} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            icon TEXT DEFAULT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT(11) NOT NULL DEFAULT 0,
            status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status),
            KEY sort_order (sort_order)
        ) {$charset_collate};";
        dbDelta($sql_service_tags);

        // =============================================
        // 商品参数模板表
        // =============================================
        $table_params = $prefix . 'product_params';
        $sql_params = "CREATE TABLE {$table_params} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            `values` TEXT DEFAULT NULL,
            sort_order INT(11) NOT NULL DEFAULT 0,
            status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status),
            KEY sort_order (sort_order)
        ) {$charset_collate};";
        dbDelta($sql_params);

        // =============================================
        // 优惠券主表
        // =============================================
        $table_coupons = $prefix . 'coupons';
        $sql_coupons = "CREATE TABLE {$table_coupons} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(50) NOT NULL,
            name VARCHAR(100) NOT NULL,
            description TEXT DEFAULT NULL,
            discount_type ENUM('fixed','percent') DEFAULT 'fixed',
            discount_value DECIMAL(10,2) NOT NULL DEFAULT 0,
            max_discount DECIMAL(10,2) DEFAULT NULL,
            apply_scope ENUM('all','resource','recharge','vip','shop') DEFAULT 'all',
            apply_items TEXT DEFAULT NULL,
            apply_categories TEXT DEFAULT NULL,
            allowed_vip_levels TEXT DEFAULT NULL,
            use_vip_levels TEXT DEFAULT NULL,
            stack_with_vip TINYINT(1) NOT NULL DEFAULT 1,
            min_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            total_count INT(11) NOT NULL DEFAULT -1,
            per_user_limit INT(11) NOT NULL DEFAULT 1,
            first_order_only TINYINT(1) NOT NULL DEFAULT 0,
            first_order_scope ENUM('same_scope','all') DEFAULT 'same_scope',
            used_count INT(11) NOT NULL DEFAULT 0,
            claimed_count INT(11) NOT NULL DEFAULT 0,
            valid_type ENUM('fixed','days') DEFAULT 'fixed',
            valid_days INT(11) DEFAULT NULL,
            start_time DATETIME DEFAULT NULL,
            end_time DATETIME DEFAULT NULL,
            claim_type ENUM('public','login','vip') DEFAULT 'public',
            min_vip_level INT(4) NOT NULL DEFAULT 0,
            status TINYINT(1) NOT NULL DEFAULT 1,
            is_visible TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT(11) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            KEY status (status),
            KEY apply_scope (apply_scope),
            KEY start_time (start_time),
            KEY end_time (end_time)
        ) {$charset_collate};";
        dbDelta($sql_coupons);

        // =============================================
        // 优惠券领取记录表
        // =============================================
        $table_coupon_claims = $prefix . 'coupon_claims';
        $sql_coupon_claims = "CREATE TABLE {$table_coupon_claims} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            coupon_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            status TINYINT(1) NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            ip_address VARCHAR(50) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY coupon_id (coupon_id),
            KEY user_id (user_id),
            KEY status (status),
            KEY expires_at (expires_at),
            KEY coupon_user_status (coupon_id, user_id, status)
        ) {$charset_collate};";
        dbDelta($sql_coupon_claims);

        // =============================================
        // 优惠券使用记录表
        // =============================================
        $table_coupon_uses = $prefix . 'coupon_uses';
        $sql_coupon_uses = "CREATE TABLE {$table_coupon_uses} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            coupon_id BIGINT(20) UNSIGNED NOT NULL,
            claim_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            order_type VARCHAR(20) NOT NULL,
            order_no VARCHAR(50) NOT NULL,
            order_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            ip_address VARCHAR(50) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY claim_id_unique (claim_id),
            KEY coupon_id (coupon_id),
            KEY claim_id (claim_id),
            KEY user_id (user_id),
            KEY order_no (order_no),
            KEY order_type (order_type)
        ) {$charset_collate};";
        dbDelta($sql_coupon_uses);

        // =============================================
        // 商品评价表
        // =============================================
        $table_reviews = $prefix . 'reviews';
        $sql_reviews = "CREATE TABLE {$table_reviews} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            order_item_id BIGINT(20) UNSIGNED NOT NULL,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            sku_id BIGINT(20) UNSIGNED DEFAULT 0,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            rating TINYINT(1) NOT NULL DEFAULT 5,
            content TEXT NOT NULL,
            images TEXT DEFAULT NULL,
            sku_info VARCHAR(500) DEFAULT NULL,
            is_anonymous TINYINT(1) NOT NULL DEFAULT 0,
            status TINYINT(1) NOT NULL DEFAULT 0,
            admin_reply TEXT DEFAULT NULL,
            reply_time DATETIME DEFAULT NULL,
            is_top TINYINT(1) NOT NULL DEFAULT 0,
            like_count INT(11) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY order_item_id (order_item_id),
            KEY product_id (product_id),
            KEY user_id (user_id),
            KEY status (status),
            KEY rating (rating),
            KEY is_top (is_top),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql_reviews);

        $table_review_likes = $prefix . 'review_likes';
        $sql_review_likes = "CREATE TABLE {$table_review_likes} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            review_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY review_user (review_id, user_id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql_review_likes);

        // =============================================
        // 添加评价相关字段到现有表
        // =============================================
        
        // order_items 表: 添加 is_reviewed 字段
        $table_order_items = $prefix . 'order_items';
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_order_items} LIKE 'is_reviewed'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$table_order_items} ADD COLUMN is_reviewed TINYINT(1) NOT NULL DEFAULT 0");
        }

        // products 表: 添加 review_count 和 avg_rating 字段
        $table_products = $prefix . 'products';
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_products} LIKE 'review_count'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$table_products} ADD COLUMN review_count INT(11) NOT NULL DEFAULT 0");
        }
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_products} LIKE 'avg_rating'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$table_products} ADD COLUMN avg_rating DECIMAL(2,1) NOT NULL DEFAULT 0.0");
        }

        // =============================================
        // 添加虚拟商品相关字段
        // =============================================
        
        // products 表: 添加商品类型字段
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_products} LIKE 'product_type'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$table_products} ADD COLUMN product_type VARCHAR(20) NOT NULL DEFAULT 'physical' COMMENT '商品类型: physical/virtual'");
        }
        
        // products 表: 添加虚拟商品类型字段
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_products} LIKE 'virtual_type'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$table_products} ADD COLUMN virtual_type VARCHAR(20) DEFAULT NULL COMMENT '虚拟商品类型: download/card/custom'");
        }
        
        // products 表: 添加虚拟商品内容字段
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_products} LIKE 'virtual_content'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$table_products} ADD COLUMN virtual_content TEXT DEFAULT NULL COMMENT '虚拟商品内容JSON'");
        }

        // products 表: 新人专项活动字段
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_products} LIKE 'new_user_special_enabled'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$table_products} ADD COLUMN new_user_special_enabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否开启新人专项价'");
        }

        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_products} LIKE 'new_user_special_price'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$table_products} ADD COLUMN new_user_special_price DECIMAL(10,2) DEFAULT NULL COMMENT '新人专项价'");
        }

        // products 表: 小程序装修商品展示标识
        $decoration_columns = [
            'activity_recommend_enabled' => '是否在小程序活动推荐位展示',
            'group_display_enabled'      => '是否在小程序拼团装修位展示',
            'assist_display_enabled'     => '是否在小程序助力装修位展示',
        ];
        foreach ($decoration_columns as $column => $comment) {
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_products} LIKE '{$column}'");
            if (empty($column_exists)) {
                $wpdb->query("ALTER TABLE {$table_products} ADD COLUMN {$column} TINYINT(1) NOT NULL DEFAULT 0 COMMENT '{$comment}'");
            }
            self::maybe_add_index($table_products, $column, "`{$column}`");
        }

        // order_items 表: 添加虚拟内容字段（用于存储已分配的卡密等）
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_order_items} LIKE 'virtual_content'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$table_order_items} ADD COLUMN virtual_content TEXT DEFAULT NULL COMMENT '已分配的虚拟商品内容'");
        }

        // =============================================
        // 卡密库存表
        // =============================================
        $table_cards = $prefix . 'card_inventory';
        $sql_cards = "CREATE TABLE {$table_cards} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            sku_id BIGINT(20) UNSIGNED NOT NULL,
            card_no VARCHAR(255) NOT NULL COMMENT '卡号',
            card_secret VARCHAR(255) DEFAULT NULL COMMENT '卡密',
            status TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0:未使用 1:已售出 2:已作废',
            order_id BIGINT(20) UNSIGNED DEFAULT NULL COMMENT '关联订单ID',
            order_item_id BIGINT(20) UNSIGNED DEFAULT NULL COMMENT '关联订单明细ID',
            sold_at DATETIME DEFAULT NULL COMMENT '售出时间',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY product_sku_card_no (product_id, sku_id, card_no),
            KEY product_id (product_id),
            KEY sku_id (sku_id),
            KEY status (status),
            KEY sku_status_created (sku_id, status, created_at),
            KEY order_id (order_id)
        ) {$charset_collate};";
        dbDelta($sql_cards);

        // =============================================
        // 团购规则表
        // =============================================
        $table_group_rules = $prefix . 'group_rules';
        $sql_group_rules = "CREATE TABLE {$table_group_rules} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT(20) UNSIGNED NOT NULL COMMENT '关联商品ID',
            group_price DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '团购价格',
            group_size INT(11) NOT NULL DEFAULT 2 COMMENT '成团人数',
            time_limit INT(11) NOT NULL DEFAULT 24 COMMENT '成团时限(小时)',
            status TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态: 0禁用 1启用',
            start_time DATETIME DEFAULT NULL COMMENT '活动开始时间',
            end_time DATETIME DEFAULT NULL COMMENT '活动结束时间',
            limit_per_user INT(11) NOT NULL DEFAULT 0 COMMENT '单用户购买限制(0=不限)',
            group_stock INT(11) NOT NULL DEFAULT 0 COMMENT '团购专用库存(0=使用商品SKU库存)',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY status (status),
            KEY start_time (start_time),
            KEY end_time (end_time)
        ) {$charset_collate};";
        dbDelta($sql_group_rules);

        // =============================================
        // 拼团实例表
        // =============================================
        $table_groups = $prefix . 'groups';
        $sql_groups = "CREATE TABLE {$table_groups} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            rule_id BIGINT(20) UNSIGNED NOT NULL COMMENT '关联规则ID',
            product_id BIGINT(20) UNSIGNED NOT NULL COMMENT '商品ID',
            leader_id BIGINT(20) UNSIGNED NOT NULL COMMENT '团长用户ID',
            current_size INT(11) NOT NULL DEFAULT 1 COMMENT '当前人数',
            target_size INT(11) NOT NULL DEFAULT 2 COMMENT '目标人数(快照)',
            group_price DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '团购价格(快照)',
            status TINYINT(1) NOT NULL DEFAULT 0 COMMENT '状态: 0拼团中 1成功 2失败',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '开团时间',
            expire_time DATETIME NOT NULL COMMENT '过期时间',
            completed_at DATETIME DEFAULT NULL COMMENT '完成时间',
            PRIMARY KEY (id),
            KEY rule_id (rule_id),
            KEY product_id (product_id),
            KEY leader_id (leader_id),
            KEY status (status),
            KEY expire_time (expire_time),
            KEY status_expire (status, expire_time)
        ) {$charset_collate};";
        dbDelta($sql_groups);

        // =============================================
        // 拼团成员表
        // =============================================
        $table_group_members = $prefix . 'group_members';
        $sql_group_members = "CREATE TABLE {$table_group_members} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            group_id BIGINT(20) UNSIGNED NOT NULL COMMENT '团ID',
            user_id BIGINT(20) UNSIGNED NOT NULL COMMENT '用户ID',
            order_id BIGINT(20) UNSIGNED NOT NULL COMMENT '订单ID',
            is_leader TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否团长',
            joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '参团时间',
            PRIMARY KEY (id),
            UNIQUE KEY group_user (group_id, user_id),
            KEY group_id (group_id),
            KEY user_id (user_id),
            KEY order_id (order_id)
        ) {$charset_collate};";
        dbDelta($sql_group_members);

        // =============================================
        // 售后退款申请表
        // =============================================
        $table_refunds = $prefix . 'refunds';
        $sql_refunds = "CREATE TABLE {$table_refunds} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            order_no VARCHAR(50) NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            refund_mode VARCHAR(30) DEFAULT NULL,
            refund_no VARCHAR(64) DEFAULT NULL,
            gateway_refund_no VARCHAR(100) DEFAULT NULL,
            gateway_refund_status VARCHAR(50) DEFAULT NULL,
            gateway_response LONGTEXT DEFAULT NULL,
            gateway_error TEXT DEFAULT NULL,
            refunded_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            gateway_refunded_at DATETIME DEFAULT NULL,
            status TINYINT(1) NOT NULL DEFAULT 0,
            reason TEXT DEFAULT NULL,
            evidence_images TEXT DEFAULT NULL,
            return_required TINYINT(1) NOT NULL DEFAULT 0,
            return_address TEXT DEFAULT NULL,
            return_shipping_company VARCHAR(100) DEFAULT NULL,
            return_tracking_no VARCHAR(100) DEFAULT NULL,
            admin_id BIGINT(20) UNSIGNED DEFAULT NULL,
            admin_remark TEXT DEFAULT NULL,
            order_status_before TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            reviewed_at DATETIME DEFAULT NULL,
            return_shipped_at DATETIME DEFAULT NULL,
            return_received_at DATETIME DEFAULT NULL,
            refunded_at DATETIME DEFAULT NULL,
            cancelled_at DATETIME DEFAULT NULL,
            refund_handled TINYINT(1) NOT NULL DEFAULT 0,
            refund_handled_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY user_id (user_id),
            KEY refund_no (refund_no),
            KEY gateway_refund_no (gateway_refund_no),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql_refunds);

        // =============================================
        // 售后退款操作日志
        // =============================================
        $table_refund_logs = $prefix . 'refund_logs';
        $sql_refund_logs = "CREATE TABLE {$table_refund_logs} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            refund_id BIGINT(20) UNSIGNED NOT NULL,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            actor_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            actor_role VARCHAR(20) NOT NULL DEFAULT 'system',
            action VARCHAR(20) NOT NULL,
            message TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY refund_id (refund_id),
            KEY order_id (order_id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql_refund_logs);

        // =============================================
        // 售后工单表
        // =============================================
        $table_tickets = $prefix . 'tickets';
        $sql_tickets = "CREATE TABLE {$table_tickets} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_no VARCHAR(32) NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            order_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            product_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            resource_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            card_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            source_type VARCHAR(32) NOT NULL DEFAULT '',
            source_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            type VARCHAR(32) NOT NULL DEFAULT 'other',
            title VARCHAR(200) NOT NULL DEFAULT '',
            content LONGTEXT DEFAULT NULL,
            status TINYINT(1) NOT NULL DEFAULT 0,
            priority VARCHAR(20) NOT NULL DEFAULT 'normal',
            last_reply_by VARCHAR(20) NOT NULL DEFAULT 'user',
            last_reply_at DATETIME DEFAULT NULL,
            resolved_at DATETIME DEFAULT NULL,
            closed_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ticket_no (ticket_no),
            KEY user_status (user_id, status),
            KEY order_id (order_id),
            KEY product_id (product_id),
            KEY type_status (type, status),
            KEY source_lookup (source_type, source_id),
            KEY last_reply_at (last_reply_at),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql_tickets);

        // =============================================
        // 售后工单消息表
        // =============================================
        $table_ticket_messages = $prefix . 'ticket_messages';
        $sql_ticket_messages = "CREATE TABLE {$table_ticket_messages} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT(20) UNSIGNED NOT NULL,
            author_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            sender_type VARCHAR(20) NOT NULL DEFAULT 'user',
            message LONGTEXT NOT NULL,
            attachments LONGTEXT DEFAULT NULL,
            is_internal TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ticket_id (ticket_id),
            KEY author_id (author_id),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql_ticket_messages);

        // =============================================
        // 好友助力活动配置
        // =============================================
        $table_assist_activities = $prefix . 'assist_activities';
        $sql_assist_activities = "CREATE TABLE {$table_assist_activities} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            start_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            min_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            help_min DECIMAL(10,2) NOT NULL DEFAULT 0.1,
            help_max DECIMAL(10,2) NOT NULL DEFAULT 1,
            target_helpers INT(11) NOT NULL DEFAULT 0,
            expire_hours INT(11) NOT NULL DEFAULT 24,
            stock_total INT(11) NOT NULL DEFAULT 0,
            stock_locked INT(11) NOT NULL DEFAULT 0,
            stock_sold INT(11) NOT NULL DEFAULT 0,
            status TINYINT(1) NOT NULL DEFAULT 1,
            auto_restore_stock TINYINT(1) NOT NULL DEFAULT 1,
            start_time DATETIME DEFAULT NULL,
            end_time DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY status (status),
            KEY start_time (start_time),
            KEY end_time (end_time)
        ) {$charset_collate};";
        dbDelta($sql_assist_activities);

        // =============================================
        // 好友助力活动实例
        // =============================================
        $table_assist_campaigns = $prefix . 'assist_campaigns';
        $sql_assist_campaigns = "CREATE TABLE {$table_assist_campaigns} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            activity_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            start_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            current_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            min_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            helped_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            help_count INT(11) NOT NULL DEFAULT 0,
            target_helpers INT(11) NOT NULL DEFAULT 0,
            stock_reserved TINYINT(1) NOT NULL DEFAULT 0,
            reserved_qty INT(11) NOT NULL DEFAULT 0,
            status TINYINT(1) NOT NULL DEFAULT 0,
            share_code VARCHAR(32) NOT NULL,
            pay_order_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            pay_order_no VARCHAR(50) DEFAULT NULL,
            expire_at DATETIME NOT NULL,
            last_helped_at DATETIME DEFAULT NULL,
            paid_at DATETIME DEFAULT NULL,
            refunded_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY share_code (share_code),
            KEY activity_id (activity_id),
            KEY user_id (user_id),
            KEY status (status),
            KEY expire_at (expire_at),
            KEY pay_order_id (pay_order_id)
        ) {$charset_collate};";
        dbDelta($sql_assist_campaigns);

        // =============================================
        // 好友助力日志
        // =============================================
        $table_assist_logs = $prefix . 'assist_logs';
        $sql_assist_logs = "CREATE TABLE {$table_assist_logs} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id BIGINT(20) UNSIGNED NOT NULL,
            activity_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            actor_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            actor_role VARCHAR(20) NOT NULL DEFAULT 'system',
            action VARCHAR(30) NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            before_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            after_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            message VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY campaign_id (campaign_id),
            KEY activity_id (activity_id),
            KEY user_id (user_id),
            KEY actor_id (actor_id),
            KEY action (action),
            KEY created_at (created_at)
        ) {$charset_collate};";
        dbDelta($sql_assist_logs);

        // =============================================
        // 订单表: 添加团购相关字段
        // =============================================
        $table_orders = $prefix . 'orders';
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_orders} LIKE 'group_id'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$table_orders} ADD COLUMN group_id BIGINT(20) UNSIGNED DEFAULT 0 COMMENT '关联团购ID'");
        }
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_orders} LIKE 'is_group_order'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$table_orders} ADD COLUMN is_group_order TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否团购订单'");
        }
    }

    /**
     * 创建默认设置
     */
    private static function create_default_options() {
        $defaults = [
            // 商城基础设置
            'qls_shop_enabled' => true,
            'qls_shop_enabled' => true,
            'qls_shop_name' => '实物商城',
            'qls_shop_home_mode' => 'decoration',
            'qls_shop_virtual_home_enabled' => sanitize_key((string) get_option('qls_shop_home_mode', 'decoration')) === 'virtual_card',
            'qls_shop_virtual_home_style' => 'compact',
            'qls_shop_virtual_home_limit' => 24,
            'qls_shop_virtual_home_title' => '虚拟发卡',
            'qls_shop_virtual_home_subtitle' => '自动发货，支付后查看卡密信息',
            'qls_shop_show_sales' => true,
            'qls_shop_show_stock' => true,
            
            // 页面设置
            'qls_shop_page_shop' => 0,
            'qls_shop_page_virtual_home' => 0,
            'qls_shop_page_cart' => 0,
            'qls_shop_page_checkout' => 0,
            'qls_shop_page_orders' => 0,
            'qls_shop_page_center' => 0,
            'qls_shop_page_group_center' => 0,
            'qls_shop_page_group_detail' => 0,
            'qls_shop_page_assist_center' => 0,
            'qls_shop_page_assist_detail' => 0,
            'qls_shop_page_my_assists' => 0,
            'qls_shop_page_my_downloads' => 0,
            'qls_shop_page_my_tickets' => 0,
            'qls_shop_page_new_user_zone' => 0,
            'qls_shop_page_order_query' => 0,
            
            // URL设置
            'qls_shop_product_base' => 'shop/product',
            'qls_shop_category_base' => 'shop/category',
            
            // 游客下单/购物车设置
            'qls_shop_guest_order_enabled' => true,
            'qls_shop_cart_guest_enabled' => true,
            'qls_shop_cart_cookie_days' => 7,
            'qls_shop_guest_query_password_enabled' => false,
            'qls_shop_guest_query_password_expire_days' => 30,
            
            // 订单设置
            'qls_shop_order_auto_complete_days' => 15,
            'qls_shop_order_auto_cancel_hours' => 24,

            // 发票 / 物流扩展
            'qls_shop_invoice_enabled' => true,
            'qls_shop_waybill_enabled' => true,
            'qls_shop_waybill_provider' => 'kdniao',
            'qls_shop_waybill_auto_generate' => false,
            
            // 库存设置
            'qls_shop_stock_reduce_on' => 'order', // order 或 payment
            'qls_shop_low_stock_threshold' => 5,
            
            // 积分设置
            'qls_shop_points_enabled' => true,
            'qls_shop_points_rate' => 10, // 10积分 = 1元
            'qls_group_cron_key' => wp_generate_password(32, false, false),
            'qls_assist_enabled' => true,
            'qls_assist_default_expire_hours' => 24,

            // 评价设置
            'qls_shop_review_enabled' => true,           // 评价功能总开关
            'qls_shop_review_auto_approve' => false,     // 自动审核通过（默认需人工审核）
            'qls_shop_review_require_purchase' => true,  // 仅购买用户可评价
            'qls_shop_review_after_days' => 15,          // 收货后N天内可评价
            'qls_shop_review_image_max' => 9,            // 最多上传图片数
            'qls_shop_review_min_length' => 10,          // 最少评价字数
            'qls_shop_review_points_reward' => 10,       // 评价奖励积分
            'qls_shop_review_image_bonus' => 5,          // 带图额外奖励
        ];

        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }

    /**
     * 为订单扫描任务补充复合索引（升级迁移）
     *
     * @return void
     */
    private static function ensure_order_scan_indexes() {
        global $wpdb;
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $orders_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'orders';
        self::maybe_add_index($orders_table, 'status_user_created', '`status`, `user_id`, `created_at`');
    }

    /**
     * 补充后台订单搜索常用索引。
     *
     * @return void
     */
    private static function ensure_order_admin_search_indexes() {
        global $wpdb;
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $orders_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'orders';
        self::maybe_add_index($orders_table, 'receiver_phone', '`receiver_phone`');
    }

    /**
     * 补充游客发卡订单查询专用字段和索引。
     *
     * @return void
     */
    private static function ensure_order_guest_query_lookup_schema() {
        global $wpdb;
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $orders_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'orders';
        $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $orders_table));
        if ($table_exists !== $orders_table) {
            return;
        }

        $columns = [
            'guest_query_phone'         => "ALTER TABLE {$orders_table} ADD COLUMN guest_query_phone VARCHAR(32) NOT NULL DEFAULT '' AFTER seller_remark",
            'guest_query_email'         => "ALTER TABLE {$orders_table} ADD COLUMN guest_query_email VARCHAR(191) NOT NULL DEFAULT '' AFTER guest_query_phone",
            'guest_query_password_hash' => "ALTER TABLE {$orders_table} ADD COLUMN guest_query_password_hash VARCHAR(255) NOT NULL DEFAULT '' AFTER guest_query_email",
            'guest_query_password_expires_at' => "ALTER TABLE {$orders_table} ADD COLUMN guest_query_password_expires_at DATETIME DEFAULT NULL AFTER guest_query_password_hash",
        ];

        foreach ($columns as $column => $sql) {
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$orders_table} LIKE '{$column}'");
            if (empty($column_exists)) {
                $wpdb->query($sql);
            }
        }

        self::maybe_add_index($orders_table, 'guest_query_phone_lookup', '`guest_query_phone`, `user_id`, `id`');
        self::maybe_add_index($orders_table, 'guest_query_email_lookup', '`guest_query_email`(100), `user_id`, `id`');
    }

    /**
     * 补充支付幂等字段。
     *
     * @return void
     */
    private static function ensure_payment_idempotency_schema() {
        global $wpdb;
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $orders_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'orders';
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
     * 补充优惠券并发控制所需索引。
     *
     * @return void
     */
    private static function ensure_coupon_integrity_indexes() {
        global $wpdb;
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $claims_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'coupon_claims';
        $uses_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'coupon_uses';

        self::maybe_add_index($claims_table, 'coupon_user_status', '`coupon_id`, `user_id`, `status`');
        self::maybe_add_unique_index($uses_table, 'claim_id_unique', '`claim_id`');
    }

    /**
     * 补充退款处理幂等字段。
     *
     * @return void
     */
    private static function ensure_refund_processing_schema() {
        global $wpdb;
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $refunds_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'refunds';
        $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $refunds_table));
        if ($table_exists !== $refunds_table) {
            return;
        }

        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$refunds_table} LIKE 'refund_handled'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$refunds_table} ADD COLUMN refund_handled TINYINT(1) NOT NULL DEFAULT 0 AFTER cancelled_at");
        }

        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$refunds_table} LIKE 'refund_handled_at'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$refunds_table} ADD COLUMN refund_handled_at DATETIME DEFAULT NULL AFTER refund_handled");
        }
    }

    /**
     * 补充售后退货流程字段。
     *
     * @return void
     */
    private static function ensure_refund_after_sales_schema() {
        global $wpdb;
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $refunds_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'refunds';
        $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $refunds_table));
        if ($table_exists !== $refunds_table) {
            return;
        }

        $columns = [
            'evidence_images'         => "ALTER TABLE {$refunds_table} ADD COLUMN evidence_images TEXT DEFAULT NULL AFTER reason",
            'return_required'         => "ALTER TABLE {$refunds_table} ADD COLUMN return_required TINYINT(1) NOT NULL DEFAULT 0 AFTER evidence_images",
            'return_address'          => "ALTER TABLE {$refunds_table} ADD COLUMN return_address TEXT DEFAULT NULL AFTER return_required",
            'return_shipping_company' => "ALTER TABLE {$refunds_table} ADD COLUMN return_shipping_company VARCHAR(100) DEFAULT NULL AFTER return_address",
            'return_tracking_no'      => "ALTER TABLE {$refunds_table} ADD COLUMN return_tracking_no VARCHAR(100) DEFAULT NULL AFTER return_shipping_company",
            'return_shipped_at'       => "ALTER TABLE {$refunds_table} ADD COLUMN return_shipped_at DATETIME DEFAULT NULL AFTER reviewed_at",
            'return_received_at'      => "ALTER TABLE {$refunds_table} ADD COLUMN return_received_at DATETIME DEFAULT NULL AFTER return_shipped_at",
        ];

        foreach ($columns as $column => $sql) {
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$refunds_table} LIKE '{$column}'");
            if (empty($column_exists)) {
                $wpdb->query($sql);
            }
        }

        self::maybe_add_index($refunds_table, 'return_required', '`return_required`');
    }

    /**
     * 补充订单支付渠道元数据字段。
     *
     * @return void
     */
    private static function ensure_order_payment_channel_schema() {
        global $wpdb;
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $orders_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'orders';
        $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $orders_table));
        if ($table_exists !== $orders_table) {
            return;
        }

        $columns = [
            'payment_channel_version' => "ALTER TABLE {$orders_table} ADD COLUMN payment_channel_version VARCHAR(20) DEFAULT NULL AFTER payment_no",
            'payment_channel_meta'    => "ALTER TABLE {$orders_table} ADD COLUMN payment_channel_meta LONGTEXT DEFAULT NULL AFTER payment_channel_version",
        ];

        foreach ($columns as $column => $sql) {
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$orders_table} LIKE '{$column}'");
            if (empty($column_exists)) {
                $wpdb->query($sql);
            }
        }
    }

    /**
     * 补充原路退款网关字段。
     *
     * @return void
     */
    private static function ensure_refund_gateway_schema() {
        global $wpdb;
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $refunds_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'refunds';
        $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $refunds_table));
        if ($table_exists !== $refunds_table) {
            return;
        }

        $columns = [
            'refund_mode'         => "ALTER TABLE {$refunds_table} ADD COLUMN refund_mode VARCHAR(30) DEFAULT NULL AFTER amount",
            'refund_no'           => "ALTER TABLE {$refunds_table} ADD COLUMN refund_no VARCHAR(64) DEFAULT NULL AFTER refund_mode",
            'gateway_refund_no'   => "ALTER TABLE {$refunds_table} ADD COLUMN gateway_refund_no VARCHAR(100) DEFAULT NULL AFTER refund_no",
            'gateway_refund_status' => "ALTER TABLE {$refunds_table} ADD COLUMN gateway_refund_status VARCHAR(50) DEFAULT NULL AFTER gateway_refund_no",
            'gateway_response'    => "ALTER TABLE {$refunds_table} ADD COLUMN gateway_response LONGTEXT DEFAULT NULL AFTER gateway_refund_status",
            'gateway_error'       => "ALTER TABLE {$refunds_table} ADD COLUMN gateway_error TEXT DEFAULT NULL AFTER gateway_response",
            'refunded_amount'     => "ALTER TABLE {$refunds_table} ADD COLUMN refunded_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER gateway_error",
            'gateway_refunded_at' => "ALTER TABLE {$refunds_table} ADD COLUMN gateway_refunded_at DATETIME DEFAULT NULL AFTER refunded_amount",
        ];

        foreach ($columns as $column => $sql) {
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$refunds_table} LIKE '{$column}'");
            if (empty($column_exists)) {
                $wpdb->query($sql);
            }
        }

        self::maybe_add_index($refunds_table, 'refund_no', '`refund_no`');
        self::maybe_add_index($refunds_table, 'gateway_refund_no', '`gateway_refund_no`');
    }

    /**
     * 补充购物车归属键与唯一约束。
     *
     * @return void
     */
    private static function ensure_cart_integrity_schema() {
        global $wpdb;
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $cart_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'cart_items';
        $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $cart_table));
        if ($table_exists !== $cart_table) {
            return;
        }

        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$cart_table} LIKE 'owner_key'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$cart_table} ADD COLUMN owner_key VARCHAR(191) NOT NULL DEFAULT '' AFTER session_id");
        }

        $wpdb->query(
            "UPDATE {$cart_table}
             SET owner_key = CASE
                 WHEN user_id > 0 THEN CONCAT('u:', user_id)
                 WHEN session_id IS NOT NULL AND session_id <> '' THEN CONCAT('s:', session_id)
                 ELSE CONCAT('legacy:', id)
             END
             WHERE owner_key = '' OR owner_key IS NULL"
        );

        $wpdb->query(
            "UPDATE {$cart_table} AS keep_row
             INNER JOIN (
                 SELECT MIN(id) AS keep_id, owner_key, product_id, sku_id, SUM(quantity) AS total_quantity
                 FROM {$cart_table}
                 WHERE owner_key <> ''
                 GROUP BY owner_key, product_id, sku_id
                 HAVING COUNT(*) > 1
             ) AS dup
                 ON dup.keep_id = keep_row.id
             SET keep_row.quantity = dup.total_quantity"
        );

        $wpdb->query(
            "DELETE dup_row
             FROM {$cart_table} AS dup_row
             INNER JOIN {$cart_table} AS keep_row
                 ON dup_row.owner_key = keep_row.owner_key
                AND dup_row.product_id = keep_row.product_id
                AND dup_row.sku_id = keep_row.sku_id
                AND dup_row.id > keep_row.id
             WHERE dup_row.owner_key <> ''"
        );

        self::maybe_add_index($cart_table, 'owner_key', '`owner_key`');
        self::maybe_add_unique_index($cart_table, 'owner_product_sku', '`owner_key`, `product_id`, `sku_id`');
    }

    /**
     * 补充卡密库存唯一约束和查询索引。
     *
     * @return void
     */
    private static function ensure_card_inventory_indexes() {
        global $wpdb;
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $cards_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'card_inventory';
        self::maybe_add_unique_index($cards_table, 'product_sku_card_no', '`product_id`, `sku_id`, `card_no`');
        self::maybe_add_index($cards_table, 'sku_status_created', '`sku_id`, `status`, `created_at`');
    }

    /**
     * 补充积分商品筛选所需的 SKU 聚合索引。
     *
     * @return void
     */
    private static function ensure_product_points_indexes() {
        global $wpdb;
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $skus_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'product_skus';
        self::maybe_add_index($skus_table, 'status_product_points_stock', '`status`, `product_id`, `points_price`, `stock`');
    }

    /**
     * 补充评价并发保护约束。
     *
     * @return void
     */
    private static function ensure_review_integrity_schema() {
        global $wpdb;
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $reviews_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'reviews';
        self::maybe_add_unique_index($reviews_table, 'order_item_once', '`order_item_id`');
    }

    /**
     * 补充发货、拆单发货与发票基础字段。
     *
     * @return void
     */
    private static function ensure_order_fulfillment_schema() {
        global $wpdb;
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $orders_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'orders';
        $items_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'order_items';

        $orders_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $orders_table));
        if ($orders_exists === $orders_table) {
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$orders_table} LIKE 'shipment_status'");
            if (empty($column_exists)) {
                $wpdb->query("ALTER TABLE {$orders_table} ADD COLUMN shipment_status TINYINT(1) NOT NULL DEFAULT 0 AFTER tracking_no");
            }

            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$orders_table} LIKE 'shipment_count'");
            if (empty($column_exists)) {
                $wpdb->query("ALTER TABLE {$orders_table} ADD COLUMN shipment_count INT(11) NOT NULL DEFAULT 0 AFTER shipment_status");
            }

            self::maybe_add_index($orders_table, 'shipment_status', '`shipment_status`');
        }

        $items_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $items_table));
        if ($items_exists === $items_table) {
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$items_table} LIKE 'shipped_quantity'");
            if (empty($column_exists)) {
                $wpdb->query("ALTER TABLE {$items_table} ADD COLUMN shipped_quantity INT(11) NOT NULL DEFAULT 0 AFTER total");
            }

            self::maybe_add_index($items_table, 'order_shipped', '`order_id`, `shipped_quantity`');
        }
    }

    /**
     * 补充订单库存扣减状态字段，并迁移旧版 wp_postmeta 标记。
     *
     * @return void
     */
    private static function ensure_order_stock_state_schema() {
        global $wpdb;
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $orders_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'orders';
        $orders_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $orders_table));
        if ($orders_exists !== $orders_table) {
            return;
        }

        $columns = [
            'stock_reduced'        => "ALTER TABLE {$orders_table} ADD COLUMN stock_reduced TINYINT(1) NOT NULL DEFAULT 0 AFTER cancelled_at",
            'group_stock_reduced'  => "ALTER TABLE {$orders_table} ADD COLUMN group_stock_reduced TINYINT(1) NOT NULL DEFAULT 0 AFTER stock_reduced",
            'group_stock_rule_id'  => "ALTER TABLE {$orders_table} ADD COLUMN group_stock_rule_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER group_stock_reduced",
            'group_stock_quantity' => "ALTER TABLE {$orders_table} ADD COLUMN group_stock_quantity INT(11) NOT NULL DEFAULT 0 AFTER group_stock_rule_id",
        ];

        foreach ($columns as $column => $sql) {
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$orders_table} LIKE '{$column}'");
            if (empty($column_exists)) {
                $wpdb->query($sql);
            }
        }

        self::maybe_add_index($orders_table, 'stock_reduced', '`stock_reduced`');
        self::maybe_add_index($orders_table, 'group_stock_reduced', '`group_stock_reduced`');

        if (!get_option('qls_shop_stock_state_backfilled_233', false)) {
            self::backfill_legacy_stock_state($orders_table);
            update_option('qls_shop_stock_state_backfilled_233', 1, false);
        }
    }

    /**
     * 将旧版写在 wp_postmeta 的库存扣减标记迁移到订单表。
     *
     * @param string $orders_table 订单表名。
     * @return void
     */
    private static function backfill_legacy_stock_state($orders_table) {
        global $wpdb;
        if (!$wpdb || empty($orders_table)) {
            return;
        }

        $postmeta_table = $wpdb->postmeta;
        $postmeta_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $postmeta_table));
        if ($postmeta_exists !== $postmeta_table) {
            return;
        }

        $wpdb->query(
            "UPDATE {$orders_table} o
             INNER JOIN {$postmeta_table} m
                ON m.post_id = o.id
               AND m.meta_key = '_stock_reduced'
               AND m.meta_value = 'yes'
             SET o.stock_reduced = 1
             WHERE o.stock_reduced = 0"
        );

        $wpdb->query(
            "UPDATE {$orders_table} o
             INNER JOIN {$postmeta_table} reduced
                ON reduced.post_id = o.id
               AND reduced.meta_key = '_group_stock_reduced'
               AND reduced.meta_value = 'yes'
             LEFT JOIN {$postmeta_table} rule_meta
                ON rule_meta.post_id = o.id
               AND rule_meta.meta_key = '_group_stock_rule_id'
             LEFT JOIN {$postmeta_table} qty_meta
                ON qty_meta.post_id = o.id
               AND qty_meta.meta_key = '_group_stock_quantity'
             SET o.group_stock_reduced = 1,
                 o.group_stock_rule_id = CAST(COALESCE(NULLIF(rule_meta.meta_value, ''), '0') AS UNSIGNED),
                 o.group_stock_quantity = CAST(COALESCE(NULLIF(qty_meta.meta_value, ''), '0') AS UNSIGNED)
             WHERE o.group_stock_reduced = 0"
        );
    }

    /**
     * 初始化默认物流公司。
     *
     * @return void
     */
    private static function create_default_shipping_companies() {
        global $wpdb;
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'shipping_companies';
        $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($table_exists !== $table) {
            return;
        }

        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($count > 0) {
            return;
        }

        $legacy = get_option('qls_shop_shipping_companies', []);
        $companies = is_array($legacy) && !empty($legacy) ? $legacy : self::get_default_shipping_companies();

        $has_default = false;
        foreach (array_values($companies) as $index => $company) {
            $name = sanitize_text_field($company['name'] ?? '');
            $code = sanitize_text_field($company['code'] ?? '');
            if ($name === '' || $code === '') {
                continue;
            }

            $is_default = !empty($company['is_default']) ? 1 : 0;
            if (!$has_default && $index === 0) {
                $is_default = 1;
            }
            if ($is_default) {
                $has_default = true;
            }

            $aliases = isset($company['aliases']) && is_array($company['aliases']) ? wp_json_encode(array_values($company['aliases'])) : null;
            self::insert_default_shipping_company($table, [
                'name'           => $name,
                'code'           => $code,
                'aliases'        => $aliases,
                'phone_required' => !empty($company['phone_required']) ? 1 : 0,
                'is_default'     => $is_default,
                'status'         => isset($company['status']) ? (int) $company['status'] : 1,
                'sort_order'     => isset($company['sort_order']) ? (int) $company['sort_order'] : ($index + 1),
            ]);
        }
    }

    /**
     * 幂等写入默认物流公司，避免并发初始化时重复 code 报错。
     *
     * @param string $table
     * @param array  $company
     * @return void
     */
    private static function insert_default_shipping_company($table, $company) {
        global $wpdb;
        if (!$wpdb || !preg_match('/^[a-zA-Z0-9_]+$/', (string) $table)) {
            return;
        }

        $aliases_placeholder = 'NULL';
        $params = [
            (string) ($company['name'] ?? ''),
            (string) ($company['code'] ?? ''),
        ];

        if (isset($company['aliases']) && $company['aliases'] !== null && $company['aliases'] !== '') {
            $aliases_placeholder = '%s';
            $params[] = (string) $company['aliases'];
        }

        $params[] = !empty($company['phone_required']) ? 1 : 0;
        $params[] = !empty($company['is_default']) ? 1 : 0;
        $params[] = isset($company['status']) ? (int) $company['status'] : 1;
        $params[] = isset($company['sort_order']) ? (int) $company['sort_order'] : 0;

        $sql = "INSERT IGNORE INTO `{$table}` (`name`, `code`, `aliases`, `phone_required`, `is_default`, `status`, `sort_order`)
                VALUES (%s, %s, {$aliases_placeholder}, %d, %d, %d, %d)";
        $wpdb->query($wpdb->prepare($sql, $params));
    }

    /**
     * 默认物流公司列表。
     *
     * @return array
     */
    private static function get_default_shipping_companies() {
        return [
            ['name' => '顺丰速运', 'code' => 'SF', 'sort_order' => 1, 'phone_required' => 1],
            ['name' => '圆通速递', 'code' => 'YTO', 'sort_order' => 2],
            ['name' => '中通快递', 'code' => 'ZTO', 'sort_order' => 3, 'phone_required' => 1],
            ['name' => '韵达快递', 'code' => 'YD', 'sort_order' => 4],
            ['name' => '申通快递', 'code' => 'STO', 'sort_order' => 5],
            ['name' => '邮政EMS', 'code' => 'EMS', 'sort_order' => 6],
            ['name' => '京东快递', 'code' => 'JD', 'sort_order' => 7],
            ['name' => '极兔速递', 'code' => 'J&T', 'sort_order' => 8],
            ['name' => '其他', 'code' => 'OTHER', 'sort_order' => 99],
        ];
    }

    /**
     * 为历史已发货订单补齐发货单底表。
     *
     * @return void
     */
    private static function backfill_legacy_shipments() {
        if (!function_exists('qls_shipment')) {
            return;
        }

        $result = qls_shipment()->backfill_legacy_order_shipments();
        if (is_wp_error($result) && function_exists('qilingshop_log')) {
            qilingshop_log('Backfill legacy shop shipments failed: ' . $result->get_error_message(), 'warning');
        }
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

        $previous_suppress = $wpdb->suppress_errors();
        $result = $wpdb->query("ALTER TABLE `{$table}` ADD INDEX `{$index_name}` ({$columns_sql})");
        $last_error = (string) $wpdb->last_error;
        $wpdb->suppress_errors($previous_suppress);

        if ($result === false && self::is_duplicate_schema_error($last_error)) {
            return;
        }

        if ($result === false && function_exists('qilingshop_log')) {
            qilingshop_log('Failed to add shop index', 'error', [
                'table'      => $table,
                'index_name' => $index_name,
                'error'      => $last_error,
            ]);
        }
    }

    /**
     * 判断是否是并发迁移下可忽略的重复结构错误。
     *
     * @param string $error
     * @return bool
     */
    private static function is_duplicate_schema_error($error) {
        $error = (string) $error;
        return stripos($error, 'Duplicate key name') !== false
            || stripos($error, 'Duplicate column name') !== false
            || stripos($error, 'already exists') !== false;
    }

    /**
     * 唯一索引不存在时创建。
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

        $previous_suppress = $wpdb->suppress_errors();
        $result = $wpdb->query("ALTER TABLE `{$table}` ADD UNIQUE INDEX `{$index_name}` ({$columns_sql})");
        $last_error = (string) $wpdb->last_error;
        $wpdb->suppress_errors($previous_suppress);

        if ($result === false && self::is_duplicate_schema_error($last_error)) {
            return;
        }

        if ($result === false && function_exists('qilingshop_log')) {
            qilingshop_log('Failed to add shop unique index', 'error', [
                'table'      => $table,
                'index_name' => $index_name,
                'error'      => $last_error,
            ]);
        }
    }

    /**
     * 创建默认页面
     */
    private static function create_default_pages() {
        QLS_Shop_Page_Manager::ensure_pages(
            QLS_Shop_Page_Manager::get_default_shop_page_definitions()
        );
    }

    /**
     * 移除历史生成的独立订单成功页。
     */
    private static function remove_deprecated_order_success_page() {
        $flag_option = 'qls_shop_order_success_page_removed';
        if ((int) get_option($flag_option, 0) > 0) {
            return;
        }

        $candidate_ids = [];
        $configured_id = (int) get_option('qls_shop_page_success', 0);
        if ($configured_id > 0) {
            $candidate_ids[] = $configured_id;
        }

        $success_page = get_page_by_path('qls-order-success');
        if ($success_page instanceof WP_Post) {
            $candidate_ids[] = (int) $success_page->ID;
        }

        foreach (array_unique(array_filter($candidate_ids)) as $page_id) {
            $post = get_post((int) $page_id);
            if (!($post instanceof WP_Post) || $post->post_type !== 'page') {
                continue;
            }

            $is_default_success_page = (
                (string) $post->post_name === 'qls-order-success'
                && trim((string) $post->post_content) === '[qls_order_success]'
                && (string) $post->post_status !== 'trash'
            );

            if ($is_default_success_page) {
                wp_trash_post((int) $post->ID);
            }
        }

        delete_option('qls_shop_page_success');
        update_option($flag_option, 1, false);
    }

    /**
     * 创建默认运费规则
     */
    private static function create_default_shipping_rules() {
        global $wpdb;
        
        $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'shipping_rules';
        
        // 检查是否已有数据
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($count > 0) {
            return;
        }

        $rules = [
            [
                'name' => '包邮',
                'type' => 0,
                'base_fee' => 0,
                'is_default' => 1,
                'status' => 1,
            ],
            [
                'name' => '固定运费',
                'type' => 1,
                'base_fee' => 10.00,
                'free_threshold' => 99.00,
                'is_default' => 0,
                'status' => 1,
            ],
            [
                'name' => '按重量计费',
                'type' => 2,
                'base_fee' => 8.00,
                'first_weight' => 1000,
                'weight_step' => 1000,
                'step_fee' => 5.00,
                'free_threshold' => 199.00,
                'is_default' => 0,
                'status' => 1,
            ],
        ];

        foreach ($rules as $rule) {
            $wpdb->insert($table, $rule);
        }
    }

    /**
     * 创建默认服务标签
     */
    private static function create_default_service_tags() {
        global $wpdb;
        
        $table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'service_tags';
        
        // 检查是否已有数据
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($count > 0) {
            return;
        }

        $tags = [
            ['name' => '7天无理由退货', 'icon' => '✅', 'sort_order' => 1],
            ['name' => '正品保证', 'icon' => '🛡️', 'sort_order' => 2],
            ['name' => '送货上门', 'icon' => '🚚', 'sort_order' => 3],
            ['name' => '极速发货', 'icon' => '⚡', 'sort_order' => 4],
            ['name' => '售后无忧', 'icon' => '💚', 'sort_order' => 5],
        ];

        foreach ($tags as $tag) {
            $tag['status'] = 1;
            $wpdb->insert($table, $tag);
        }
    }
}
