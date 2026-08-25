<?php
/**
 * 辅助函数
 * 
 * 提供全局可用的便捷函数
 *
 * @package QilingShop
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 获取积分名称
 *
 * @return string
 */
function qilingshop_get_points_name() {
    $name = get_option('qilingshop_points_name', __('积分', 'qilingshop'));
    return apply_filters('qilingshop_points_name', $name);
}

/**
 * 获取积分与人民币比例
 *
 * @return int 多少积分等于1元
 */
function qilingshop_get_points_ratio() {
    $ratio = get_option('qilingshop_points_ratio', 10);
    return apply_filters('qilingshop_points_ratio', (int) $ratio);
}

/**
 * 积分转换为人民币
 *
 * @param float $points 积分数量
 * @return float
 */
function qilingshop_points_to_rmb($points) {
    $ratio = qilingshop_get_points_ratio();
    if ($ratio <= 0) {
        $ratio = 1;
    }
    return round($points / $ratio, 2);
}

/**
 * 人民币转换为积分
 *
 * @param float $rmb 人民币金额
 * @return float
 */
function qilingshop_rmb_to_points($rmb) {
    $ratio = qilingshop_get_points_ratio();
    return round($rmb * $ratio, 2);
}

/**
 * 获取虚拟资源不允许选择的文章类型。
 *
 * @return array
 */
function qilingshop_get_resource_post_type_excludes() {
    $default = ['qilingdoc_doc', 'qlrecycling_model'];
    $excluded = apply_filters('qilingshop_resource_post_type_excludes', $default);
    $excluded = array_map('sanitize_key', (array) $excluded);
    $excluded = array_filter($excluded, function($item) {
        return $item !== '';
    });
    return array_values(array_unique($excluded));
}

/**
 * 清洗虚拟资源支持的文章类型配置。
 *
 * @param array|string $post_types 原始文章类型配置。
 * @param array        $fallback   兜底文章类型。
 * @return array
 */
function qilingshop_normalize_resource_post_types($post_types, $fallback = ['post']) {
    $allowed = ['post', 'page'];
    $normalized = array_map('sanitize_key', (array) $post_types);
    $normalized = array_values(array_intersect($normalized, $allowed));

    $normalized = array_values(array_unique($normalized));
    if (!empty($normalized)) {
        return $normalized;
    }

    $fallback = array_map('sanitize_key', (array) $fallback);
    $fallback = array_values(array_intersect($fallback, $allowed));

    $fallback = array_values(array_unique($fallback));
    if (!empty($fallback)) {
        return $fallback;
    }

    return ['post'];
}

/**
 * 获取订单返积分规则（分类 -> 比例）
 *
 * @param string $scope resource|shop
 * @return array
 */
function qilingshop_get_order_rebate_rules($scope) {
    $scope = $scope === 'shop' ? 'shop' : 'resource';
    $cache_key = 'qilingshop_rebate_rules_' . $scope;
    $cached = wp_cache_get($cache_key, 'qilingshop');
    if ($cached !== false) {
        return $cached;
    }

    if (!class_exists('QilingShop_Database')) {
        return [];
    }

    $db = QilingShop_Database::instance();
    $rules = $db->get_results('order_rebate_rules', [
        'where'   => [
            'scope'  => $scope,
            'status' => 1,
        ],
        'orderby' => 'rate',
        'order'   => 'DESC',
        'limit'   => -1,
    ]);

    $map = [];
    foreach ((array) $rules as $rule) {
        $category_id = (int) ($rule->category_id ?? 0);
        $rate = (float) ($rule->rate ?? 0);
        if ($category_id > 0 && $rate > 0) {
            if (!isset($map[$category_id]) || $rate > $map[$category_id]) {
                $map[$category_id] = $rate;
            }
        }
    }

    wp_cache_set($cache_key, $map, 'qilingshop', 600);
    return $map;
}

/**
 * 获取订单返积分比例
 *
 * @param string $scope resource|shop
 * @param array  $category_ids 分类ID列表
 * @return float
 */
function qilingshop_get_order_rebate_rate($scope, $category_ids = []) {
    $default_rate = (float) get_option('qilingshop_order_points_rebate_rate', 0);
    if (empty($category_ids)) {
        return $default_rate;
    }

    $rules = qilingshop_get_order_rebate_rules($scope);
    $rate = 0;
    foreach ((array) $category_ids as $category_id) {
        $category_id = (int) $category_id;
        if ($category_id > 0 && isset($rules[$category_id])) {
            $rate = max($rate, (float) $rules[$category_id]);
        }
    }

    return $rate > 0 ? $rate : $default_rate;
}

/**
 * 计算返积分
 *
 * @param float $amount_rmb 订单金额
 * @param float $rate_percent 返积分比例
 * @return float
 */
function qilingshop_calculate_rebate_points($amount_rmb, $rate_percent) {
    $amount_rmb = (float) $amount_rmb;
    $rate_percent = (float) $rate_percent;
    if ($amount_rmb <= 0 || $rate_percent <= 0) {
        return 0;
    }
    $ratio = (float) qilingshop_get_points_ratio();
    if ($ratio <= 0) {
        $ratio = 1;
    }
    return round($amount_rmb * $ratio * $rate_percent / 100, 2);
}

/**
 * 格式化积分显示
 *
 * @param float  $points    积分数量
 * @param bool   $with_name 是否带积分名称
 * @return string
 */
function qilingshop_format_points($points, $with_name = true) {
    $formatted = number_format((float) $points, 2, '.', '');
    
    // 如果是整数，去掉小数点
    if ($formatted == floor($formatted)) {
        $formatted = (int) $formatted;
    }
    
    if ($with_name) {
        $formatted .= ' ' . qilingshop_get_points_name();
    }
    
    return $formatted;
}

/**
 * 格式化价格显示
 *
 * @param float  $price  价格
 * @param string $symbol 货币符号
 * @return string
 */
function qilingshop_format_price($price, $symbol = null) {
    if ($symbol === null) {
        $symbol = get_option('qilingshop_currency_symbol', '¥');
    }
    
    return $symbol . number_format((float) $price, 2, '.', '');
}

/**
 * 获取用户积分余额
 *
 * @param int $user_id 用户 ID，默认当前用户
 * @return float
 */
function qilingshop_get_user_points($user_id = null) {
    if ($user_id === null) {
        $user_id = get_current_user_id();
    }
    
    if (!$user_id) {
        return 0;
    }
    
    return QilingShop_Points::instance()->get_balance($user_id);
}

/**
 * 获取用户 VIP 等级
 *
 * @param int $user_id 用户 ID，默认当前用户
 * @return int
 */
function qilingshop_get_user_vip_level($user_id = null) {
    if ($user_id === null) {
        $user_id = get_current_user_id();
    }
    
    if (!$user_id) {
        return 0;
    }
    
    return QilingShop_VIP::instance()->get_user_level($user_id);
}

/**
 * 检查用户是否为 VIP
 *
 * @param int $user_id   用户 ID，默认当前用户
 * @param int $min_level 最低等级要求
 * @return bool
 */
function qilingshop_is_vip($user_id = null, $min_level = 1) {
    return QilingShop_VIP::instance()->is_vip($user_id, $min_level);
}

/**
 * 获取商城订单元数据表名。
 *
 * @return string
 */
function qilingshop_get_shop_order_meta_table() {
    global $wpdb;
    $prefix = defined('QLS_SHOP_TABLE_PREFIX') ? QLS_SHOP_TABLE_PREFIX : 'qls_shop_';
    return $wpdb->prefix . $prefix . 'order_meta';
}

/**
 * 规范化商城订单元数据键。
 *
 * @param string $meta_key 元数据键。
 * @return string
 */
function qilingshop_sanitize_shop_order_meta_key($meta_key) {
    $sanitized = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $meta_key));
    return is_string($sanitized) ? $sanitized : '';
}

/**
 * 判断商城订单元数据表是否存在。
 *
 * @return bool
 */
function qilingshop_shop_order_meta_table_exists() {
    static $exists = null;
    if ($exists === true) {
        return true;
    }

    global $wpdb;
    $table = qilingshop_get_shop_order_meta_table();
    $found = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($found === $table) {
        $exists = true;
        return true;
    }

    return false;
}

/**
 * 获取商城订单元数据的兜底 option 名。
 *
 * @param int    $order_id 订单 ID。
 * @param string $meta_key 元数据键。
 * @return string
 */
function qilingshop_get_shop_order_meta_option_name($order_id, $meta_key) {
    return '_qls_shop_order_meta_' . (int) $order_id . '_' . md5((string) $meta_key);
}

/**
 * 读取商城订单元数据。
 *
 * @param int    $order_id 订单 ID。
 * @param string $meta_key 元数据键。
 * @param mixed  $default  默认值。
 * @return mixed
 */
function qilingshop_get_shop_order_meta($order_id, $meta_key, $default = '') {
    $order_id = (int) $order_id;
    $meta_key = qilingshop_sanitize_shop_order_meta_key($meta_key);
    if ($order_id <= 0 || $meta_key === '') {
        return $default;
    }

    global $wpdb;
    $table = qilingshop_get_shop_order_meta_table();
    if (qilingshop_shop_order_meta_table_exists()) {
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$table} WHERE order_id = %d AND meta_key = %s LIMIT 1",
            $order_id,
            $meta_key
        ));
        if ($value !== null) {
            return maybe_unserialize($value);
        }
    }

    $option_name = qilingshop_get_shop_order_meta_option_name($order_id, $meta_key);
    $fallback = get_option($option_name, null);
    if ($fallback !== null) {
        if (qilingshop_shop_order_meta_table_exists() && qilingshop_update_shop_order_meta($order_id, $meta_key, $fallback)) {
            delete_option($option_name);
        }
        return $fallback;
    }

    return $default;
}

/**
 * 写入商城订单元数据。
 *
 * @param int    $order_id   订单 ID。
 * @param string $meta_key   元数据键。
 * @param mixed  $meta_value 元数据值。
 * @return bool
 */
function qilingshop_update_shop_order_meta($order_id, $meta_key, $meta_value) {
    $order_id = (int) $order_id;
    $meta_key = qilingshop_sanitize_shop_order_meta_key($meta_key);
    if ($order_id <= 0 || $meta_key === '') {
        return false;
    }

    global $wpdb;
    $table = qilingshop_get_shop_order_meta_table();
    $now = current_time('mysql');
    $serialized = maybe_serialize($meta_value);

    if (qilingshop_shop_order_meta_table_exists()) {
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (order_id, meta_key, meta_value, created_at, updated_at)
             VALUES (%d, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = VALUES(updated_at)",
            $order_id,
            $meta_key,
            $serialized,
            $now,
            $now
        ));
        if ($result !== false) {
            delete_option(qilingshop_get_shop_order_meta_option_name($order_id, $meta_key));
            return true;
        }
    }

    $option_name = qilingshop_get_shop_order_meta_option_name($order_id, $meta_key);
    if (get_option($option_name, null) === null) {
        return add_option($option_name, $meta_value, '', 'no');
    }

    update_option($option_name, $meta_value, false);
    return true;
}

/**
 * 删除商城订单元数据。
 *
 * @param int         $order_id 订单 ID。
 * @param string|null $meta_key 元数据键；为空时删除该订单全部商城订单元数据。
 * @return bool
 */
function qilingshop_delete_shop_order_meta($order_id, $meta_key = null) {
    $order_id = (int) $order_id;
    if ($order_id <= 0) {
        return false;
    }

    $delete_all = ($meta_key === null || $meta_key === '');
    if (!$delete_all) {
        $meta_key = qilingshop_sanitize_shop_order_meta_key($meta_key);
        if ($meta_key === '') {
            return false;
        }
    }

    global $wpdb;
    $deleted = true;
    $table = qilingshop_get_shop_order_meta_table();
    if (qilingshop_shop_order_meta_table_exists()) {
        if ($delete_all) {
            $result = $wpdb->delete($table, ['order_id' => $order_id], ['%d']);
        } else {
            $result = $wpdb->delete($table, ['order_id' => $order_id, 'meta_key' => $meta_key], ['%d', '%s']);
        }
        $deleted = $result !== false;
    }

    if (!$delete_all) {
        delete_option(qilingshop_get_shop_order_meta_option_name($order_id, $meta_key));
    } else {
        $prefix = '_qls_shop_order_meta_' . $order_id . '_';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like($prefix) . '%'
        ));
    }

    return $deleted;
}

/**
 * 删除实物订单时清理新旧商城订单元数据存储。
 *
 * @param int $order_id 订单 ID。
 * @return void
 */
function qilingshop_cleanup_deleted_shop_order_meta($order_id) {
    $order_id = (int) $order_id;
    if ($order_id <= 0) {
        return;
    }

    qilingshop_delete_shop_order_meta($order_id);
    delete_option('_qls_group_order_' . $order_id);

    $legacy_postmeta_keys = [
        '_qls_group_order',
        '_qls_assist_order',
        '_qls_assist_campaign_id',
        '_qls_assist_activity_id',
        '_qls_assist_qty',
        '_qls_assist_stock_locked',
        '_qls_assist_stock_consumed',
        '_qls_assist_refunded',
    ];
    foreach ($legacy_postmeta_keys as $key) {
        delete_post_meta($order_id, $key);
    }
}
add_action('qls_shop_order_deleted', 'qilingshop_cleanup_deleted_shop_order_meta');

/**
 * 获取团购订单临时信息（兼容旧的 option / postmeta 存储）
 *
 * @param int $order_id 订单ID
 * @return array
 */
function qilingshop_get_group_order_data($order_id) {
    $order_id = (int) $order_id;
    if ($order_id <= 0) {
        return [];
    }

    $data = qilingshop_get_shop_order_meta($order_id, '_qls_group_order', []);
    if (!empty($data) && is_array($data)) {
        return $data;
    }

    $legacy = get_option('_qls_group_order_' . $order_id);
    if (!empty($legacy)) {
        if (!is_array($legacy)) {
            $legacy = (array) $legacy;
        }
        if (qilingshop_update_shop_order_meta($order_id, '_qls_group_order', $legacy)) {
            delete_option('_qls_group_order_' . $order_id);
            delete_post_meta($order_id, '_qls_group_order');
        }
        return $legacy;
    }

    $legacy_postmeta = get_post_meta($order_id, '_qls_group_order', true);
    if (!empty($legacy_postmeta)) {
        if (!is_array($legacy_postmeta)) {
            $legacy_postmeta = (array) $legacy_postmeta;
        }
        if (qilingshop_update_shop_order_meta($order_id, '_qls_group_order', $legacy_postmeta)) {
            delete_post_meta($order_id, '_qls_group_order');
        }
        return $legacy_postmeta;
    }

    return [];
}

/**
 * 写入团购订单临时信息
 *
 * @param int   $order_id 订单ID
 * @param array $data     团购数据
 * @return void
 */
function qilingshop_set_group_order_data($order_id, $data) {
    $order_id = (int) $order_id;
    if ($order_id <= 0) {
        return;
    }
    if (!is_array($data)) {
        $data = (array) $data;
    }
    if (qilingshop_update_shop_order_meta($order_id, '_qls_group_order', $data)) {
        delete_option('_qls_group_order_' . $order_id);
        delete_post_meta($order_id, '_qls_group_order');
    }
}

/**
 * 清理团购订单临时信息（包含旧的 option / postmeta）
 *
 * @param int $order_id 订单ID
 * @return void
 */
function qilingshop_clear_group_order_data($order_id) {
    $order_id = (int) $order_id;
    if ($order_id <= 0) {
        return;
    }
    qilingshop_delete_shop_order_meta($order_id, '_qls_group_order');
    delete_post_meta($order_id, '_qls_group_order');
    delete_option('_qls_group_order_' . $order_id);
}

/**
 * 校验并规范化支付回跳地址（仅允许站内相对路径或同域URL）
 *
 * @param string $raw_url 原始URL
 * @return string 通过校验的URL（可能为相对路径），失败返回空字符串
 */
function qilingshop_validate_return_url($raw_url) {
    $raw_url = trim((string) $raw_url);
    if ($raw_url === '') {
        return '';
    }

    if (strpos($raw_url, '/') === 0 && strpos($raw_url, '//') !== 0) {
        return $raw_url;
    }

    $parsed = wp_parse_url($raw_url);
    $home = wp_parse_url(home_url('/'));
    $scheme = isset($parsed['scheme']) ? strtolower((string) $parsed['scheme']) : '';
    if (!in_array($scheme, ['http', 'https'], true)) {
        return '';
    }

    $target_host = isset($parsed['host']) ? strtolower((string) $parsed['host']) : '';
    $home_host = isset($home['host']) ? strtolower((string) $home['host']) : '';
    if ($target_host !== '' && $home_host !== '' && $target_host === $home_host) {
        return $raw_url;
    }

    return '';
}

/**
 * 规范化支付回跳地址为本站绝对 URL。
 *
 * @param string $raw_url      原始 URL。
 * @param string $fallback_url 原始 URL 无效时使用的兜底 URL。
 * @return string 通过校验的本站绝对 URL，失败返回空字符串。
 */
function qilingshop_normalize_return_url($raw_url, $fallback_url = '') {
    $validated = qilingshop_validate_return_url($raw_url);
    if ($validated === '' && $fallback_url !== '') {
        $validated = qilingshop_validate_return_url($fallback_url);
    }

    if ($validated === '') {
        return '';
    }

    if (strpos($validated, '/') === 0 && strpos($validated, '//') !== 0) {
        return home_url($validated);
    }

    return esc_url_raw($validated);
}

/**
 * 将本站 uploads 媒体地址规范到当前域名。
 *
 * 适用于更换域名后，商城仍保存旧媒体绝对地址的场景。
 *
 * @param string $url 原始媒体地址。
 * @return string
 */
function qilingshop_normalize_media_url($url) {
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }

    if (preg_match('#^(?:data:|mailto:|tel:|javascript:)#i', $url)) {
        return $url;
    }

    $parsed = wp_parse_url($url);
    if (false === $parsed || empty($parsed['path'])) {
        return $url;
    }

    if (function_exists('wp_get_upload_dir')) {
        $upload_dir = wp_get_upload_dir();
    } else {
        $upload_dir = wp_upload_dir(null, false);
    }

    $baseurl = isset($upload_dir['baseurl']) ? untrailingslashit((string) $upload_dir['baseurl']) : '';
    if ($baseurl === '') {
        return $url;
    }

    $path = (string) $parsed['path'];
    $base_path = (string) wp_parse_url($baseurl, PHP_URL_PATH);
    $base_path = untrailingslashit($base_path);
    $relative = null;

    if ($base_path !== '') {
        if ($path === $base_path) {
            $relative = '';
        } elseif (0 === strpos($path, $base_path . '/')) {
            $relative = ltrim(substr($path, strlen($base_path)), '/');
        }
    }

    if ($relative === null) {
        $uploads_marker = '/wp-content/uploads/';
        $marker_pos = strpos($path, $uploads_marker);
        if ($marker_pos === false) {
            return $url;
        }

        $relative = ltrim(substr($path, $marker_pos + strlen($uploads_marker)), '/');
    }

    $relative = ltrim(str_replace(['../', '..\\'], '', rawurldecode((string) $relative)), '/');

    $basedir = isset($upload_dir['basedir']) ? untrailingslashit((string) $upload_dir['basedir']) : '';
    if ($basedir !== '' && $relative !== '') {
        $candidate_file = $basedir . '/' . str_replace('\\', '/', $relative);
        if (!file_exists($candidate_file)) {
            return $url;
        }
    }

    $normalized = $baseurl;
    if ($relative !== '') {
        $normalized .= '/' . $relative;
    }

    if (!empty($parsed['query'])) {
        $normalized .= '?' . (string) $parsed['query'];
    }

    if (!empty($parsed['fragment'])) {
        $normalized .= '#' . (string) $parsed['fragment'];
    }

    return $normalized;
}

/**
 * 递归规范商城里的媒体值。
 *
 * @param mixed $value 媒体值或媒体配置数组。
 * @return mixed
 */
function qilingshop_normalize_media_value($value) {
    if (is_string($value)) {
        if ($value === '') {
            return $value;
        }

        if (strpos($value, '<') !== false && preg_match('/<(?:img|source|video|a)\b/i', $value)) {
            return qilingshop_normalize_media_markup_urls($value);
        }

        return qilingshop_normalize_media_url($value);
    }

    if (is_array($value)) {
        foreach ($value as $key => $item) {
            if (is_array($item) || is_string($item)) {
                $value[$key] = qilingshop_normalize_media_value($item);
            }
        }
    }

    return $value;
}

/**
 * 规范 HTML 标记中的媒体地址。
 *
 * @param string $html HTML 内容。
 * @return string
 */
function qilingshop_normalize_media_markup_urls($html) {
    $html = (string) $html;
    if ($html === '' || strpos($html, 'wp-content/uploads') === false) {
        return $html;
    }

    $normalized = preg_replace_callback(
        '/\b(src|href|poster)=([\"\'])(.*?)\2/i',
        static function ($matches) {
            $normalized_url = qilingshop_normalize_media_url($matches[3]);
            if ($normalized_url === $matches[3]) {
                return $matches[0];
            }

            return $matches[1] . '=' . $matches[2] . esc_url($normalized_url) . $matches[2];
        },
        $html
    );

    return is_string($normalized) ? $normalized : $html;
}

/**
 * 统一设置 Cookie（带安全属性）。
 *
 * @param string      $name     Cookie 名称
 * @param string      $value    Cookie 值
 * @param int         $expires  过期时间戳
 * @param string      $path     路径
 * @param string      $domain   域名
 * @param bool|null   $secure   是否仅 HTTPS，null 表示按 is_ssl 自动
 * @param bool        $httponly HttpOnly
 * @param string      $samesite SameSite
 * @return bool
 */
function qilingshop_set_cookie($name, $value, $expires = 0, $path = '/', $domain = '', $secure = null, $httponly = true, $samesite = 'Lax') {
    if (headers_sent()) {
        return false;
    }

    $secure = is_null($secure) ? is_ssl() : (bool) $secure;
    $path = $path ?: '/';
    $domain = (string) $domain;
    $samesite = ucfirst(strtolower((string) $samesite));
    if (!in_array($samesite, ['Lax', 'Strict', 'None'], true)) {
        $samesite = 'Lax';
    }

    if ($samesite === 'None' && !$secure) {
        $samesite = 'Lax';
    }

    if (PHP_VERSION_ID >= 70300) {
        return setcookie($name, (string) $value, [
            'expires'  => (int) $expires,
            'path'     => $path,
            'domain'   => $domain,
            'secure'   => $secure,
            'httponly' => (bool) $httponly,
            'samesite' => $samesite,
        ]);
    }

    $legacy_path = $path . '; samesite=' . $samesite;
    return setcookie($name, (string) $value, (int) $expires, $legacy_path, $domain, $secure, (bool) $httponly);
}

/**
 * 清除 Cookie（与设置时保持相同作用域）。
 *
 * @param string    $name Cookie 名称
 * @param string    $path 路径
 * @param string    $domain 域名
 * @param bool|null $secure 是否仅 HTTPS，null 表示按 is_ssl 自动
 * @param bool      $httponly HttpOnly
 * @param string    $samesite SameSite
 * @return bool
 */
function qilingshop_clear_cookie($name, $path = '/', $domain = '', $secure = null, $httponly = true, $samesite = 'Lax') {
    unset($_COOKIE[$name]);
    return qilingshop_set_cookie($name, '', time() - 3600, $path, $domain, $secure, $httponly, $samesite);
}

/**
 * 写入回跳地址 Cookie
 *
 * @param string $validated_url 已校验的URL（可为空）
 * @return void
 */
function qilingshop_set_return_cookie($validated_url) {
    if ($validated_url !== '') {
        $_COOKIE['qilingshop_return'] = $validated_url;
        qilingshop_set_cookie('qilingshop_return', $validated_url, 0, '/', '', null, true, 'Lax');
        return;
    }

    qilingshop_clear_cookie('qilingshop_return', '/', '', null, true, 'Lax');
}

/**
 * 根据原始地址写入回跳 Cookie，并返回校验后的地址
 *
 * @param string $raw_url 原始URL
 * @return string 校验后的URL（可为空）
 */
function qilingshop_prepare_return_cookie($raw_url) {
    $validated = qilingshop_validate_return_url($raw_url);
    qilingshop_set_return_cookie($validated);
    return $validated;
}

/**
 * 获取安全回跳 URL（绝对地址）
 *
 * @return string
 */
function qilingshop_get_safe_return_url() {
    $raw_return = isset($_COOKIE['qilingshop_return']) ? (string) $_COOKIE['qilingshop_return'] : '';
    $normalized = qilingshop_normalize_return_url($raw_return);
    return $normalized !== '' ? $normalized : home_url();
}

/**
 * 规范化 CSV 单元格，避免 Excel/LibreOffice 打开时执行公式。
 *
 * @param mixed $value 单元格原始值。
 * @return string
 */
function qilingshop_normalize_csv_cell($value) {
    if (is_array($value) || is_object($value)) {
        $value = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $value = (string) $value;
    $value = preg_replace('/\r\n|\r|\n/', ' ', $value);
    $value = trim($value);

    if ($value !== '' && preg_match('/^[=+\-@]/', $value)) {
        $value = "'" . $value;
    }

    return $value;
}

/**
 * 安全输出 CSV 行。
 *
 * @param resource $fp  CSV 输出句柄。
 * @param array    $row 行数据。
 * @return int|false
 */
function qilingshop_fputcsv_safe($fp, array $row) {
    return fputcsv($fp, array_map('qilingshop_normalize_csv_cell', $row));
}

/**
 * 规范化支付网关标识。
 *
 * @param string $gateway 原始网关标识。
 * @return string
 */
function qilingshop_normalize_payment_gateway($gateway) {
    $gateway = strtolower((string) $gateway);
    $gateway = str_replace(['wechat-miniapp', 'wechat_mini_app'], 'wechat_miniapp', $gateway);
    $gateway = str_replace(['weixin', 'wxpay'], 'wechat', $gateway);
    $gateway = sanitize_key(str_replace('-', '_', $gateway));

    return $gateway;
}

/**
 * 获取支付异步通知地址集合。
 *
 * @param string $gateway 网关标识。
 * @return array
 */
function qilingshop_get_payment_notify_endpoints($gateway) {
    $gateway = qilingshop_normalize_payment_gateway($gateway);

    $rest_segments = [
        'alipay'         => 'alipay',
        'wechat'         => 'wechat',
        'wechat_miniapp' => 'wechat-miniapp',
        'paypal'         => 'paypal',
        'stripe'         => 'stripe',
        'epay'           => 'epay',
        'xhpay'          => 'xhpay',
    ];

    $legacy_files = [
        'alipay' => 'notify-alipay.php',
        'wechat' => 'notify-wechat.php',
        'epay'   => 'notify-epay.php',
        'xhpay'  => 'notify-xhpay.php',
    ];

    $primary = '';
    if (isset($rest_segments[$gateway])) {
        $primary = rest_url('qls/v1/notify/' . $rest_segments[$gateway]);
    }
    if ($primary === '') {
        $primary = add_query_arg([
            'qilingshop_payment' => 'notify',
            'gateway'            => $gateway,
        ], home_url('/'));
    }

    $legacy = '';
    if (isset($legacy_files[$gateway])) {
        $legacy = QILINGSHOP_URL . 'payment/' . $legacy_files[$gateway];
    }
    if ($legacy === '') {
        $legacy = add_query_arg([
            'qilingshop_payment' => 'notify',
            'gateway'            => $gateway,
        ], home_url('/'));
    }

    return apply_filters('qilingshop_payment_notify_endpoints', [
        'gateway' => $gateway,
        'primary' => esc_url_raw($primary),
        'legacy'  => esc_url_raw($legacy),
    ], $gateway);
}

/**
 * 获取支付异步通知地址。
 *
 * @param string $gateway 网关标识。
 * @param bool   $legacy  是否返回兼容旧版地址。
 * @return string
 */
function qilingshop_get_payment_notify_url($gateway, $legacy = false) {
    $endpoints = qilingshop_get_payment_notify_endpoints($gateway);
    $key = $legacy ? 'legacy' : 'primary';

    return isset($endpoints[$key]) ? (string) $endpoints[$key] : '';
}

/**
 * 获取标准支付入口地址。
 *
 * 使用 WordPress query var 入口，避免新链路直接访问插件 PHP 文件。
 *
 * @param string $gateway 网关标识。
 * @param array  $args    入口参数。
 * @return string
 */
function qilingshop_get_payment_entry_url($gateway, $args = []) {
    $gateway = qilingshop_normalize_payment_gateway($gateway);
    if ($gateway === '') {
        return home_url('/');
    }

    $args = is_array($args) ? $args : [];
    $args['qilingshop_payment'] = 'entry';
    $args['gateway'] = $gateway;

    return esc_url_raw(add_query_arg($args, home_url('/')));
}

/**
 * 获取标准支付动作地址。
 *
 * @param string $action  动作标识。
 * @param string $gateway 网关标识。
 * @param array  $args    附加参数。
 * @return string
 */
function qilingshop_get_payment_action_url($action, $gateway = '', $args = []) {
    $action = sanitize_key((string) $action);
    $gateway = qilingshop_normalize_payment_gateway($gateway);
    $args = is_array($args) ? $args : [];
    $args['qilingshop_payment'] = $action;
    if ($gateway !== '') {
        $args['gateway'] = $gateway;
    }

    return esc_url_raw(add_query_arg($args, home_url('/')));
}

/**
 * 获取当前请求头集合。
 *
 * @return array
 */
function qilingshop_get_request_headers() {
    $headers = [];

    if (function_exists('getallheaders')) {
        $headers = (array) getallheaders();
    }

    if (!empty($headers)) {
        return $headers;
    }

    foreach ($_SERVER as $key => $value) {
        if (strpos((string) $key, 'HTTP_') !== 0) {
            continue;
        }

        $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr((string) $key, 5)))));
        $headers[$name] = $value;
    }

    return $headers;
}

/**
 * 输出兼容旧版通知入口的响应。
 *
 * @param string $gateway  网关标识。
 * @param mixed  $response REST 响应对象或结果。
 * @return void
 */
function qilingshop_emit_legacy_notify_response($gateway, $response = null) {
    $gateway = qilingshop_normalize_payment_gateway($gateway);

    if ($response instanceof WP_REST_Response) {
        foreach ((array) $response->get_headers() as $header_name => $header_value) {
            header($header_name . ': ' . $header_value, true);
        }

        $data = $response->get_data();
        if (is_string($data) || is_numeric($data)) {
            echo (string) $data;
        } elseif (is_array($data) || is_object($data)) {
            echo wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        exit;
    }

    if ($gateway === 'wechat' || $gateway === 'wechat_miniapp') {
        header('Content-Type: text/xml; charset=utf-8');
        echo '<xml><return_code><![CDATA[FAIL]]></return_code><return_msg><![CDATA[兼容通知处理失败]]></return_msg></xml>';
        exit;
    }

    echo 'fail';
    exit;
}

/**
 * 兼容旧版通知文件/Query 入口，统一分发到 REST 处理器。
 *
 * @param string $gateway 网关标识。
 * @return void
 */
function qilingshop_dispatch_legacy_payment_notify($gateway) {
    $gateway = qilingshop_normalize_payment_gateway($gateway);

    if (!class_exists('QilingShop_REST_API')) {
        qilingshop_emit_legacy_notify_response($gateway);
    }

    $request = new WP_REST_Request('POST', '/qls/v1/notify/' . str_replace('_', '-', $gateway));
    $raw_body = file_get_contents('php://input');
    if (is_string($raw_body) && $raw_body !== '') {
        $request->set_body($raw_body);
    }

    if (!empty($_POST)) {
        $request->set_body_params(wp_unslash((array) $_POST));
    }
    if (!empty($_GET)) {
        $request->set_query_params(wp_unslash((array) $_GET));
    }

    foreach (qilingshop_get_request_headers() as $header_name => $header_value) {
        $request->set_header($header_name, $header_value);
    }

    $api = QilingShop_REST_API::instance();
    $response = null;

    switch ($gateway) {
        case 'alipay':
            $response = $api->handle_alipay_notify($request);
            break;
        case 'wechat':
            $response = $api->handle_wechat_notify($request);
            break;
        case 'wechat_miniapp':
            $response = $api->handle_wechat_miniapp_notify($request);
            break;
        case 'epay':
            $response = $api->handle_epay_notify($request);
            break;
        case 'xhpay':
            $response = $api->handle_xhpay_notify($request);
            break;
    }

    qilingshop_emit_legacy_notify_response($gateway, $response);
}

/**
 * 判断实物商城是否允许游客下单（兼容旧配置键）。
 *
 * @return bool
 */
function qls_shop_is_guest_order_enabled() {
    $new_value = get_option('qls_shop_guest_order_enabled', null);
    if ($new_value !== null) {
        return (bool) $new_value;
    }

    $legacy_value = get_option('qls_shop_cart_guest_enabled', null);
    if ($legacy_value !== null) {
        return (bool) $legacy_value;
    }

    return true;
}

/**
 * 获取订单查询页 URL（可附带订单号）。
 *
 * @param string $order_no     订单号（可选）
 * @param string $fallback_url 当查询页未配置时的回退地址
 * @return string
 */
function qilingshop_get_order_query_page_url($order_no = '', $fallback_url = '') {
    $url = '';
    $page_id = (int) get_option('qls_shop_page_order_query', 0);
    if ($page_id <= 0) {
        $page_id = (int) get_option('qilingshop_page_order_query', 0);
    }
    if ($page_id > 0 && get_post_status($page_id) === 'publish') {
        $url = get_permalink($page_id);
    }

    if ($url === '') {
        $fallback_url = is_string($fallback_url) ? trim($fallback_url) : '';
        if ($fallback_url !== '') {
            $url = qilingshop_normalize_return_url($fallback_url);
        }
    }

    if ($url === '') {
        $url = home_url('/');
    }

    $order_no = strtoupper(sanitize_text_field((string) $order_no));
    if ($order_no !== '' && preg_match('/^[A-Z0-9]{1,64}$/', $order_no)) {
        $url = add_query_arg('order_no', $order_no, $url);
    }

    return $url;
}

/**
 * 校验支付入口访问者是否有权拉起该订单支付。
 *
 * 规则：
 * - 订单绑定了 user_id 时，仅允许该用户本人支付。
 * - 未绑定用户（user_id=0，游客单）保持原有行为。
 *
 * @param object|array $order_info 订单对象
 * @param string       $order_type 订单类型（仅用于扩展，不参与当前判定）
 * @return true|WP_Error
 */
function qilingshop_enforce_payment_order_access($order_info, $order_type = '') {
    unset($order_type);

    if (is_array($order_info)) {
        $order_info = (object) $order_info;
    }

    if (!is_object($order_info)) {
        return new WP_Error('invalid_order', __('订单不存在', 'qilingshop'));
    }

    $owner_user_id = isset($order_info->user_id) ? (int) $order_info->user_id : 0;
    if ($owner_user_id <= 0) {
        return true;
    }

    $current_user_id = (int) get_current_user_id();
    if ($current_user_id <= 0) {
        return new WP_Error('pay_login_required', __('请先登录订单所属账号后再支付', 'qilingshop'));
    }

    if ($current_user_id !== $owner_user_id) {
        return new WP_Error('pay_forbidden', __('当前账号无权支付该订单', 'qilingshop'));
    }

    return true;
}

/**
 * 获取用户 VIP 名称
 *
 * @param int $user_id 用户 ID，默认当前用户
 * @return string
 */
function qilingshop_get_user_vip_name($user_id = null) {
    return QilingShop_VIP::instance()->get_user_level_name($user_id);
}

/**
 * 检查用户是否已购买资源
 *
 * @param int $post_id 文章 ID
 * @param int $user_id 用户 ID，默认当前用户
 * @param bool $include_vip_free 是否包含 VIP 免费
 * @param string $scope any|view|download
 * @param int|null $download_index 下载项索引；为空时按任意下载项兼容判断。
 * @return bool
 */
function qilingshop_user_has_purchased($post_id, $user_id = null, $include_vip_free = true, $scope = 'any', $download_index = null) {
    return QilingShop_Order::instance()->user_has_purchased($post_id, $user_id, $include_vip_free, $scope, $download_index);
}

/**
 * 获取资源价格
 *
 * @param int $post_id 文章 ID
 * @param int $user_id 用户 ID（用于计算 VIP 折扣）
 * @return array ['points' => 积分价格, 'rmb' => 人民币价格, 'original' => 原价]
 */
function qilingshop_get_resource_price($post_id, $user_id = null) {
    return QilingShop_Resource::instance()->get_price($post_id, $user_id);
}

/**
 * 获取选项值
 *
 * @param string $key     选项键
 * @param mixed  $default 默认值
 * @return mixed
 */
function qilingshop_get_option($key, $default = '') {
    return get_option('qilingshop_' . $key, $default);
}

/**
 * 设置选项值
 *
 * @param string $key   选项键
 * @param mixed  $value 选项值
 * @return bool
 */
function qilingshop_update_option($key, $value) {
    return update_option('qilingshop_' . $key, $value);
}

/**
 * 是否启用注册码注册
 *
 * @return bool
 */
function qilingshop_registration_code_is_enabled() {
    if (!class_exists('QilingShop_Registration_Code')) {
        return false;
    }
    return QilingShop_Registration_Code::instance()->is_enabled();
}

/**
 * 获取注册码获取入口配置。
 *
 * @return array{enabled:bool,url:string,link_text:string,tip_text:string}
 */
function qilingshop_get_registration_code_obtain_link() {
    $enabled = qilingshop_registration_code_is_enabled();
    $url = $enabled ? esc_url_raw((string) get_option('qilingshop_register_code_obtain_url', '')) : '';
    $link_text = trim((string) get_option('qilingshop_register_code_obtain_link_text', __('注册码获取', 'qilingshop')));
    $tip_text = trim((string) get_option('qilingshop_register_code_obtain_tip_text', ''));

    if ($link_text === '') {
        $link_text = __('注册码获取', 'qilingshop');
    }

    return [
        'enabled'   => $enabled && $url !== '',
        'url'       => $url,
        'link_text' => sanitize_text_field($link_text),
        'tip_text'  => sanitize_text_field($tip_text),
    ];
}

/**
 * 预占用注册码（注册前）
 *
 * @param string $code 注册码
 * @param array  $context 上下文
 * @return array
 */
function qilingshop_registration_code_consume_for_registration($code, $context = []) {
    if (!class_exists('QilingShop_Registration_Code')) {
        return [
            'success' => false,
            'message' => __('注册码服务不可用', 'qilingshop'),
            'log_id'  => 0,
        ];
    }
    return QilingShop_Registration_Code::instance()->consume_for_registration($code, $context);
}

/**
 * 回滚注册码预占
 *
 * @param int    $log_id  日志 ID
 * @param string $message 说明
 * @return bool
 */
function qilingshop_registration_code_rollback_consumption($log_id, $message = '') {
    if (!class_exists('QilingShop_Registration_Code')) {
        return false;
    }
    return QilingShop_Registration_Code::instance()->rollback_consumption($log_id, $message);
}

/**
 * 确认注册码使用成功
 *
 * @param int    $log_id  日志 ID
 * @param int    $user_id 用户 ID
 * @param string $message 说明
 * @return bool
 */
function qilingshop_registration_code_confirm_consumption($log_id, $user_id, $message = '') {
    if (!class_exists('QilingShop_Registration_Code')) {
        return false;
    }
    return QilingShop_Registration_Code::instance()->confirm_consumption($log_id, $user_id, $message);
}

function qilingshop_points_resource_enabled($post_id = null) {
    if ($post_id) {
        $value = get_post_meta($post_id, '_qilingshop_points_resource_enabled', true);
        if ($value === '') {
            return true;
        }
        return (bool) $value;
    }
    return true;
}

/**
 * 记录日志
 *
 * @param string $message 日志消息
 * @param string $level   日志级别
 * @param array  $context 上下文数据
 */
function qilingshop_log($message, $level = 'info', $context = []) {
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        return;
    }
    
    $log_message = sprintf(
        '[QilingShop %s] %s',
        strtoupper($level),
        $message
    );
    
    if (!empty($context)) {
        $log_message .= ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    
    error_log($log_message);
}

/**
 * 检查是否为手机访问
 *
 * @return bool
 */
function qilingshop_is_mobile() {
    if (function_exists('wp_is_mobile')) {
        return wp_is_mobile();
    }
    
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    
    $mobile_agents = [
        'Mobile', 'Android', 'iPhone', 'iPad', 'iPod', 'BlackBerry',
        'Windows Phone', 'webOS', 'Opera Mini', 'IEMobile'
    ];
    
    foreach ($mobile_agents as $agent) {
        if (stripos($user_agent, $agent) !== false) {
            return true;
        }
    }
    
    return false;
}

/**
 * 检查是否在微信浏览器中
 *
 * @return bool
 */
function qilingshop_is_wechat() {
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    return stripos($user_agent, 'MicroMessenger') !== false;
}

/**
 * 检查是否在支付宝浏览器中
 *
 * @return bool
 */
function qilingshop_is_alipay() {
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    return stripos($user_agent, 'AlipayClient') !== false;
}

/**
 * 格式化支付宝公钥为标准 PEM
 *
 * 支持后台保存“纯公钥内容”或“带 BEGIN/END 的完整公钥”两种格式。
 *
 * @param string $raw_key 原始公钥文本
 * @return string PEM 格式公钥，失败返回空字符串
 */
function qilingshop_format_public_key_pem($raw_key) {
    $key = trim((string) $raw_key);
    if ($key === '') {
        return '';
    }

    // 兼容被转义的换行
    $key = str_replace(["\r\n", "\r"], "\n", $key);
    $key = str_replace(['\\n', '\\r'], "\n", $key);

    // 兼容用户粘贴完整 PEM（含头尾）
    $key = preg_replace('/-----BEGIN (?:RSA )?PUBLIC KEY-----/i', '', $key);
    $key = preg_replace('/-----END (?:RSA )?PUBLIC KEY-----/i', '', $key);
    $key = preg_replace('/\s+/', '', (string) $key);

    if ($key === '') {
        return '';
    }

    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split($key, 64, "\n") . "-----END PUBLIC KEY-----";
}

/**
 * 获取当前页面 URL
 *
 * @return string
 */
function qilingshop_get_current_url() {
    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/';
    $request_uri = preg_replace('/[\x00-\x1F\x7F]/', '', $request_uri);
    $request_uri = is_string($request_uri) ? trim($request_uri) : '/';

    if ($request_uri === '' || strpos($request_uri, '/') !== 0) {
        $request_uri = '/';
    }

    $path = wp_parse_url($request_uri, PHP_URL_PATH);
    $query = wp_parse_url($request_uri, PHP_URL_QUERY);

    $path = is_string($path) && $path !== '' ? '/' . ltrim($path, '/') : '/';
    $relative_url = $path;
    if (is_string($query) && $query !== '') {
        $relative_url .= '?' . $query;
    }

    return esc_url_raw(home_url($relative_url));
}

/**
 * 获取分页数据
 *
 * @param int $total    总数
 * @param int $per_page 每页数量
 * @param int $current  当前页
 * @return array
 */
function qilingshop_get_pagination($total, $per_page = 15, $current = 1) {
    $total_pages = ceil($total / $per_page);
    
    return [
        'total'       => $total,
        'per_page'    => $per_page,
        'current'     => $current,
        'total_pages' => $total_pages,
        'has_prev'    => $current > 1,
        'has_next'    => $current < $total_pages,
        'offset'      => ($current - 1) * $per_page,
    ];
}

/**
 * 渲染分页 HTML
 *
 * @param array  $pagination 分页数据
 * @param string $base_url   基础 URL
 * @return string
 */
function qilingshop_render_pagination($pagination, $base_url = '') {
    if ($pagination['total_pages'] <= 1) {
        return '';
    }
    
    $output = '<div class="qilingshop-pagination">';
    
    // 上一页
    if ($pagination['has_prev']) {
        $prev_url = add_query_arg('paged', $pagination['current'] - 1, $base_url);
        $output .= '<a href="' . esc_url($prev_url) . '" class="page-prev">&laquo; ' . __('上一页', 'qilingshop') . '</a>';
    }
    
    // 页码
    for ($i = 1; $i <= $pagination['total_pages']; $i++) {
        if ($i == $pagination['current']) {
            $output .= '<span class="page-current">' . $i . '</span>';
        } else {
            $page_url = add_query_arg('paged', $i, $base_url);
            $output .= '<a href="' . esc_url($page_url) . '" class="page-number">' . $i . '</a>';
        }
    }
    
    // 下一页
    if ($pagination['has_next']) {
        $next_url = add_query_arg('paged', $pagination['current'] + 1, $base_url);
        $output .= '<a href="' . esc_url($next_url) . '" class="page-next">' . __('下一页', 'qilingshop') . ' &raquo;</a>';
    }
    
    $output .= '</div>';
    
    return $output;
}

/**
 * 发送 JSON 响应
 *
 * @param mixed $data    响应数据
 * @param bool  $success 是否成功
 * @param string $message 消息
 */
function qilingshop_json_response($data = null, $success = true, $message = '') {
    $response = [
        'success' => $success,
        'message' => $message,
        'data'    => $data,
    ];
    
    if (!$success && empty($message)) {
        $response['message'] = __('操作失败', 'qilingshop');
    }
    
    wp_send_json($response);
}

/**
 * 发送成功 JSON 响应
 *
 * @param mixed  $data    响应数据
 * @param string $message 消息
 */
function qilingshop_json_success($data = null, $message = '') {
    qilingshop_json_response($data, true, $message);
}

/**
 * 发送失败 JSON 响应
 *
 * @param string $message 错误消息
 * @param mixed  $data    附加数据
 */
function qilingshop_json_error($message = '', $data = null) {
    qilingshop_json_response($data, false, $message);
}

/**
 * 获取虚拟发卡首页风格注册表。
 *
 * @return array<string,array>
 */
function qilingshop_get_virtual_home_styles() {
    $default_styles = [
        'compact' => [
            'label'    => __('清爽列表', 'qilingshop'),
            'template' => 'compact',
        ],
        'grid' => [
            'label'    => __('精选橱窗', 'qilingshop'),
            'template' => 'grid',
        ],
    ];

    $styles = apply_filters('qls_shop_virtual_home_styles', $default_styles);
    if (!is_array($styles)) {
        $styles = $default_styles;
    }

    $normalized = [];
    foreach ($styles as $style_key => $style_config) {
        $style_key = sanitize_key((string) $style_key);
        if ($style_key === '') {
            continue;
        }

        if (!is_array($style_config)) {
            $style_config = [
                'label' => (string) $style_config,
            ];
        }

        $label = isset($style_config['label']) ? sanitize_text_field((string) $style_config['label']) : $style_key;
        if ($label === '') {
            $label = $style_key;
        }

        $template = isset($style_config['template']) ? (string) $style_config['template'] : $style_key;
        if (trim($template) === '') {
            $template = $style_key;
        }

        $style_config['label'] = $label;
        $style_config['template'] = $template;
        $normalized[$style_key] = $style_config;
    }

    return !empty($normalized) ? $normalized : $default_styles;
}

/**
 * 标准化主题相对模板路径。
 *
 * @param string $path
 * @return string
 */
function qilingshop_normalize_theme_template_path($path) {
    $path = function_exists('wp_normalize_path') ? wp_normalize_path((string) $path) : str_replace('\\', '/', (string) $path);
    $path = ltrim(trim($path), '/');

    if ($path === '' || strpos($path, '..') !== false || preg_match('/^[a-zA-Z]:\//', $path)) {
        return '';
    }

    if (substr($path, -4) !== '.php') {
        $path .= '.php';
    }

    return $path;
}

/**
 * 判断是否为可读取的 PHP 模板文件。
 *
 * @param string $path
 * @return bool
 */
function qilingshop_is_readable_php_template($path) {
    $path = function_exists('wp_normalize_path') ? wp_normalize_path((string) $path) : str_replace('\\', '/', (string) $path);
    return $path !== '' && substr($path, -4) === '.php' && file_exists($path) && is_readable($path);
}

/**
 * 判断是否为绝对路径。
 *
 * @param string $path
 * @return bool
 */
function qilingshop_is_absolute_template_path($path) {
    $path = function_exists('wp_normalize_path') ? wp_normalize_path((string) $path) : str_replace('\\', '/', (string) $path);
    return $path !== '' && ($path[0] === '/' || (bool) preg_match('/^[a-zA-Z]:\//', $path));
}

/**
 * 定位虚拟发卡首页风格模板。
 *
 * 查找顺序：
 * 1. 子主题/父主题模板；
 * 2. 过滤器传入的绝对模板文件；
 * 3. 插件内置模板；
 * 4. 插件 compact 兜底模板。
 *
 * @param string     $style_key
 * @param array|null $style_config
 * @return string
 */
function qilingshop_locate_virtual_home_style_template($style_key, $style_config = null) {
    $style_key = sanitize_key((string) $style_key);
    $styles = qilingshop_get_virtual_home_styles();
    if (!is_array($style_config)) {
        $style_config = isset($styles[$style_key]) && is_array($styles[$style_key]) ? $styles[$style_key] : [];
    }

    $template_ref = isset($style_config['template']) ? trim((string) $style_config['template']) : $style_key;
    if ($template_ref === '') {
        $template_ref = $style_key !== '' ? $style_key : 'compact';
    }

    $theme_candidates = [];
    $absolute_candidates = [];
    $plugin_candidates = [];

    foreach (['theme_template', 'theme_templates'] as $key) {
        if (empty($style_config[$key])) {
            continue;
        }

        foreach ((array) $style_config[$key] as $candidate) {
            $candidate = qilingshop_normalize_theme_template_path((string) $candidate);
            if ($candidate !== '') {
                $theme_candidates[] = $candidate;
            }
        }
    }

    foreach (['template_file', 'template_path', 'file'] as $key) {
        if (empty($style_config[$key])) {
            continue;
        }

        foreach ((array) $style_config[$key] as $candidate) {
            $candidate = (string) $candidate;
            if (qilingshop_is_absolute_template_path($candidate)) {
                $absolute_candidates[] = $candidate;
            } else {
                $candidate = qilingshop_normalize_theme_template_path($candidate);
                if ($candidate !== '') {
                    $theme_candidates[] = $candidate;
                }
            }
        }
    }

    if (strpos($template_ref, '/') !== false || strpos($template_ref, '\\') !== false) {
        $relative_template = qilingshop_normalize_theme_template_path($template_ref);
        if ($relative_template !== '') {
            $theme_candidates[] = $relative_template;
        }
    } else {
        $template_slug = sanitize_file_name($template_ref);
        $template_slug = preg_replace('/\.php$/i', '', $template_slug);
        if ($template_slug === '') {
            $template_slug = $style_key !== '' ? $style_key : 'compact';
        }

        $theme_candidates[] = 'qilingshop/shop/virtual-home/' . $template_slug . '.php';
        $theme_candidates[] = 'qilingshop/virtual-home/' . $template_slug . '.php';
        $theme_candidates[] = 'templates/qilingshop/virtual-home/' . $template_slug . '.php';
        $theme_candidates[] = 'templates/shop/virtual-home/' . $template_slug . '.php';
        $plugin_candidates[] = QILINGSHOP_PATH . 'templates/shop/virtual-home/' . $template_slug . '.php';
    }

    if (!empty($style_config['plugin_template'])) {
        foreach ((array) $style_config['plugin_template'] as $candidate) {
            $candidate = qilingshop_normalize_theme_template_path((string) $candidate);
            if ($candidate !== '') {
                $plugin_candidates[] = QILINGSHOP_PATH . ltrim($candidate, '/');
            }
        }
    }

    $candidates = apply_filters('qls_shop_virtual_home_style_template_candidates', [
        'theme'    => array_values(array_unique($theme_candidates)),
        'absolute' => array_values(array_unique($absolute_candidates)),
        'plugin'   => array_values(array_unique($plugin_candidates)),
    ], $style_key, $style_config);

    $located = '';
    $theme_candidates = isset($candidates['theme']) && is_array($candidates['theme']) ? $candidates['theme'] : [];
    if (!empty($theme_candidates) && function_exists('locate_template')) {
        $located = locate_template($theme_candidates, false, false);
    }

    if ($located === '') {
        $absolute_candidates = isset($candidates['absolute']) && is_array($candidates['absolute']) ? $candidates['absolute'] : [];
        foreach ($absolute_candidates as $candidate) {
            if (qilingshop_is_readable_php_template((string) $candidate)) {
                $located = (string) $candidate;
                break;
            }
        }
    }

    if ($located === '') {
        $plugin_candidates = isset($candidates['plugin']) && is_array($candidates['plugin']) ? $candidates['plugin'] : [];
        foreach ($plugin_candidates as $candidate) {
            if (qilingshop_is_readable_php_template((string) $candidate)) {
                $located = (string) $candidate;
                break;
            }
        }
    }

    if ($located === '') {
        $located = QILINGSHOP_PATH . 'templates/shop/virtual-home/compact.php';
    }

    $located = apply_filters('qls_shop_virtual_home_style_template', $located, $style_key, $style_config, $candidates);

    return qilingshop_is_readable_php_template($located)
        ? $located
        : QILINGSHOP_PATH . 'templates/shop/virtual-home/compact.php';
}

/**
 * 获取模板文件
 *
 * @param string $template 模板名称
 * @param array  $args     传递给模板的变量
 * @param bool   $echo     是否直接输出
 * @return string|void
 */
function qilingshop_get_template($template, $args = [], $echo = true) {
    // 提取变量
    if (!empty($args)) {
        extract($args);
    }
    
    // 检查主题目录
    $theme_file = get_stylesheet_directory() . '/qilingshop/' . $template . '.php';
    
    $plugin_template = QILINGSHOP_PATH . 'templates/' . $template . '.php';
    $legacy_template = QILINGSHOP_PATH . 'public/views/' . $template . '.php';

    if (file_exists($theme_file)) {
        $file = $theme_file;
    } elseif (file_exists($plugin_template)) {
        $file = $plugin_template;
    } else {
        $file = $legacy_template;
    }
    
    if (!file_exists($file)) {
        return '';
    }
    
    if ($echo) {
        include $file;
    } else {
        ob_start();
        include $file;
        return ob_get_clean();
    }
}

/**
 * 时间格式化（如：3分钟前）
 *
 * @param string $datetime 时间字符串
 * @return string
 */
function qilingshop_human_time_diff($datetime) {
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;
    
    if ($diff < 60) {
        return __('刚刚', 'qilingshop');
    } elseif ($diff < 3600) {
        return sprintf(__('%d分钟前', 'qilingshop'), floor($diff / 60));
    } elseif ($diff < 86400) {
        return sprintf(__('%d小时前', 'qilingshop'), floor($diff / 3600));
    } elseif ($diff < 2592000) {
        return sprintf(__('%d天前', 'qilingshop'), floor($diff / 86400));
    } else {
        return date('Y-m-d', $timestamp);
    }
}

/**
 * 检查插件是否在多站点网络激活
 *
 * @return bool
 */
function qilingshop_is_network_activated() {
    if (!is_multisite()) {
        return false;
    }
    
    if (!function_exists('is_plugin_active_for_network')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    
    return is_plugin_active_for_network(QILINGSHOP_BASENAME);
}

/**
 * 获取前端资源版本号（用于缓存刷新）
 *
 * @return string
 */
function qilingshop_get_assets_version() {
    $cache_version = get_option('qilingshop_assets_version', '');
    
    if (empty($cache_version)) {
        $cache_version = QILINGSHOP_VERSION;
    }
    
    return $cache_version;
}

/**
 * 刷新前端资源缓存版本号
 *
 * @return bool
 */
function qilingshop_refresh_assets_cache() {
    $new_version = QILINGSHOP_VERSION . '.' . time();
    return update_option('qilingshop_assets_version', $new_version);
}

/**
 * 获取优惠券选择器脚本文案
 *
 * @return array
 */
function qilingshop_get_coupon_selector_i18n() {
    return [
        'select_coupon'     => __('选择优惠券', 'qilingshop'),
        'loading'           => __('加载中...', 'qilingshop'),
        'no_coupons'        => __('暂无可用优惠券', 'qilingshop'),
        'confirm'           => __('确认选择', 'qilingshop'),
        'coupon_min_amount' => __('满{currency}{amount}可用', 'qilingshop'),
        'coupon_no_threshold' => __('无门槛', 'qilingshop'),
    ];
}

/**
 * 获取插件图标 HTML 白名单。
 *
 * @return array
 */
function qilingshop_get_icon_allowed_html() {
    $allowed = [
        'span' => [
            'class'       => true,
            'style'       => true,
            'title'       => true,
            'aria-hidden' => true,
        ],
        'i' => [
            'class'       => true,
            'style'       => true,
            'title'       => true,
            'aria-hidden' => true,
        ],
        'em' => [
            'class'       => true,
            'style'       => true,
            'aria-hidden' => true,
        ],
        'strong' => [
            'class'       => true,
            'style'       => true,
            'aria-hidden' => true,
        ],
        'b' => [
            'class'       => true,
            'style'       => true,
            'aria-hidden' => true,
        ],
        'small' => [
            'class'       => true,
            'style'       => true,
            'aria-hidden' => true,
        ],
        'img' => [
            'class'       => true,
            'style'       => true,
            'src'         => true,
            'alt'         => true,
            'width'       => true,
            'height'      => true,
            'aria-hidden' => true,
        ],
        'svg' => [
            'class'       => true,
            'id'          => true,
            'width'       => true,
            'height'      => true,
            'viewbox'     => true,
            'viewBox'     => true,
            'fill'        => true,
            'stroke'      => true,
            'xmlns'       => true,
            'xmlns:xlink' => true,
            'aria-hidden' => true,
            'role'        => true,
            'focusable'   => true,
            'style'       => true,
        ],
        'use' => [
            'href'       => true,
            'xlink:href' => true,
            'x'          => true,
            'y'          => true,
            'width'      => true,
            'height'     => true,
        ],
        'path' => [
            'd'               => true,
            'fill'            => true,
            'stroke'          => true,
            'stroke-width'    => true,
            'stroke-linecap'  => true,
            'stroke-linejoin' => true,
            'fill-rule'       => true,
            'clip-rule'       => true,
            'opacity'         => true,
            'transform'       => true,
        ],
        'g' => [
            'fill'      => true,
            'stroke'    => true,
            'transform' => true,
            'opacity'   => true,
            'id'        => true,
            'class'     => true,
        ],
        'circle' => [
            'cx'           => true,
            'cy'           => true,
            'r'            => true,
            'fill'         => true,
            'stroke'       => true,
            'stroke-width' => true,
            'opacity'      => true,
        ],
        'rect' => [
            'x'            => true,
            'y'            => true,
            'width'        => true,
            'height'       => true,
            'rx'           => true,
            'ry'           => true,
            'fill'         => true,
            'stroke'       => true,
            'stroke-width' => true,
            'opacity'      => true,
        ],
        'line' => [
            'x1'             => true,
            'y1'             => true,
            'x2'             => true,
            'y2'             => true,
            'stroke'         => true,
            'stroke-width'   => true,
            'stroke-linecap' => true,
        ],
        'polyline' => [
            'points'          => true,
            'fill'            => true,
            'stroke'          => true,
            'stroke-width'    => true,
            'stroke-linecap'  => true,
            'stroke-linejoin' => true,
        ],
        'polygon' => [
            'points'       => true,
            'fill'         => true,
            'stroke'       => true,
            'stroke-width' => true,
        ],
        'ellipse' => [
            'cx'     => true,
            'cy'     => true,
            'rx'     => true,
            'ry'     => true,
            'fill'   => true,
            'stroke' => true,
        ],
        'defs' => [],
        'clippath' => [
            'id' => true,
        ],
        'clipPath' => [
            'id' => true,
        ],
        'mask' => [
            'id' => true,
        ],
        'symbol' => [
            'id'      => true,
            'viewbox' => true,
            'viewBox' => true,
        ],
        'title' => [],
        'desc'  => [],
    ];

    return apply_filters('qilingshop_icon_allowed_html', $allowed);
}

/**
 * 清洗图标 HTML 片段。
 *
 * @param string $html 原始 HTML。
 * @return string
 */
function qilingshop_sanitize_icon_html($html) {
    $html = trim((string) $html);
    if ($html === '') {
        return '';
    }

    if (preg_match('/^\s*<svg\b/i', $html) && function_exists('developer_starter_sanitize_svg')) {
        return developer_starter_sanitize_svg($html);
    }

    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
    $html = preg_replace('/\s*on\w+\s*=\s*["\'][^"\']*["\']/i', '', $html);
    $html = preg_replace('/\s*on\w+\s*=\s*[^\s>]*/i', '', $html);
    $html = preg_replace('/javascript\s*:/i', '', $html);
    $html = preg_replace('/data\s*:/i', '', $html);
    $html = preg_replace('/\s(?:href|xlink:href)\s*=\s*["\']https?:[^"\']*["\']/i', '', $html);

    return wp_kses($html, qilingshop_get_icon_allowed_html());
}

/**
 * 清洗后台保存的图标配置值。
 *
 * @param mixed $icon 原始图标值。
 * @return string
 */
function qilingshop_sanitize_icon_value($icon) {
    if (is_array($icon) || is_object($icon)) {
        return '';
    }

    $icon = trim((string) $icon);
    if ($icon === '') {
        return '';
    }

    if (strpos($icon, '<') !== false && strpos($icon, '>') !== false) {
        return qilingshop_sanitize_icon_html($icon);
    }

    $icon = sanitize_text_field($icon);
    $icon = trim(preg_replace('/\s+/', ' ', $icon));

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($icon, 'UTF-8') > 500) {
            $icon = mb_substr($icon, 0, 500, 'UTF-8');
        }
    } elseif (strlen($icon) > 1000) {
        $icon = substr($icon, 0, 1000);
    }

    return $icon;
}

/**
 * 规范化图标输出 class。
 *
 * @param string $classes CSS class 列表。
 * @return string
 */
function qilingshop_normalize_icon_classes($classes) {
    $tokens = preg_split('/\s+/', (string) $classes);
    $tokens = array_filter(array_map('sanitize_html_class', (array) $tokens));

    return implode(' ', array_values(array_unique($tokens)));
}

/**
 * 判断普通文本是否适合作为图标直接输出。
 *
 * @param string $icon 图标值。
 * @return bool
 */
function qilingshop_is_text_icon_value($icon) {
    $decoded = html_entity_decode(trim((string) $icon), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($decoded === '') {
        return false;
    }

    $is_emoji = preg_match('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F700}-\x{1F77F}\x{1F780}-\x{1F7FF}\x{1F800}-\x{1F8FF}\x{1F900}-\x{1F9FF}\x{1FA00}-\x{1FA6F}\x{1FA70}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', $decoded);
    if ($is_emoji) {
        return true;
    }

    $length = function_exists('mb_strlen') ? mb_strlen($decoded, 'UTF-8') : strlen($decoded);
    if ($length > 4) {
        return false;
    }

    return !preg_match('/^(dashicons|dashicons-|iconfont|icon-)/i', $decoded);
}

/**
 * 渲染插件图标。
 *
 * 支持表情符号、HTML 片段、启灵主题阿里图标 icon-xxx、iconfont icon-xxx 和旧 dashicons。
 *
 * @param mixed  $icon     图标配置。
 * @param string $class    额外 CSS class。
 * @param string $fallback 兜底图标。
 * @return string 安全的图标 HTML。
 */
function qilingshop_render_icon($icon, $class = 'qls-icon', $fallback = '') {
    $icon = qilingshop_sanitize_icon_value($icon);
    if ($icon === '' && $fallback !== '') {
        $icon = qilingshop_sanitize_icon_value($fallback);
    }

    if ($icon === '') {
        return '';
    }

    $classes = qilingshop_normalize_icon_classes('qls-icon ' . $class);

    if (strpos($icon, '<') !== false && strpos($icon, '>') !== false) {
        $safe_html = qilingshop_sanitize_icon_html($icon);
        if ($safe_html === '') {
            return '';
        }

        return '<span class="' . esc_attr(qilingshop_normalize_icon_classes($classes . ' qls-icon-html')) . '" aria-hidden="true">' . $safe_html . '</span>';
    }

    if (preg_match('/(?:^|\s)iconfont(?:\s|$)/i', $icon) && preg_match('/(?:^|\s)(icon-[a-zA-Z0-9_-]+)(?:\s|$)/', $icon)) {
        $icon_classes = qilingshop_normalize_icon_classes($icon . ' ' . $classes);
        return '<i class="' . esc_attr($icon_classes) . '" aria-hidden="true"></i>';
    }

    if (preg_match('/(?:^|\s)(icon-[a-zA-Z0-9_-]+)(?:\s|$)/', $icon, $matches)) {
        $icon_name = $matches[1];
        $extra_classes = trim(str_replace($icon_name, '', $icon));
        $symbol_classes = qilingshop_normalize_icon_classes($classes . ' ' . $extra_classes);

        if (function_exists('developer_starter_get_icon_html')) {
            return developer_starter_get_icon_html($icon_name, $symbol_classes);
        }

        return '<svg class="' . esc_attr(qilingshop_normalize_icon_classes('qs-icon ' . $symbol_classes)) . '" aria-hidden="true"><use xlink:href="' . esc_attr('#' . $icon_name) . '"></use></svg>';
    }

    if (preg_match('/(?:^|\s)(dashicons-[a-z0-9-]+)(?:\s|$)/i', $icon, $matches)) {
        $dashicon_classes = $icon;
        if (!preg_match('/(?:^|\s)dashicons(?:\s|$)/i', $dashicon_classes)) {
            $dashicon_classes = 'dashicons ' . $dashicon_classes;
        }

        return '<span class="' . esc_attr(qilingshop_normalize_icon_classes($dashicon_classes . ' ' . $classes)) . '" aria-hidden="true"></span>';
    }

    if (qilingshop_is_text_icon_value($icon)) {
        $decoded = html_entity_decode($icon, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return '<span class="' . esc_attr(qilingshop_normalize_icon_classes($classes . ' qls-icon-text')) . '" aria-hidden="true">' . esc_html($decoded) . '</span>';
    }

    if (preg_match('/^[a-zA-Z0-9_-]+(\s+[a-zA-Z0-9_-]+)*$/', $icon)) {
        return '<i class="' . esc_attr(qilingshop_normalize_icon_classes($icon . ' ' . $classes)) . '" aria-hidden="true"></i>';
    }

    return '<span class="' . esc_attr(qilingshop_normalize_icon_classes($classes . ' qls-icon-text')) . '" aria-hidden="true">' . esc_html($icon) . '</span>';
}

/**
 * 兼容 PHP 7.2+ each() 函数被废弃的问题
 *
 * @param array $array
 * @return array|bool
 */
function qilingshop_replace_each(&$array) {
    $value = current($array);
    $key = key($array);
    if (is_null($key)) {
        return false;
    }
    next($array);
    return array(1 => $value, 'value' => $value, 0 => $key, 'key' => $key);
}
