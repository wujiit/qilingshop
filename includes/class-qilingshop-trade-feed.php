<?php
/**
 * 交易播报数据服务
 *
 * @package QilingShop
 * @since   2.0.9
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Trade_Feed {

    /**
     * 单例实例
     *
     * @var QilingShop_Trade_Feed|null
     */
    private static $instance = null;

    /**
     * 主库
     *
     * @var QilingShop_Database
     */
    private $db;

    /**
     * 商城库
     *
     * @var QLS_Shop_Database|null
     */
    private $shop_db = null;

    /**
     * 获取单例实例
     *
     * @return QilingShop_Trade_Feed
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 构造函数
     */
    private function __construct() {
        $this->db = QilingShop_Database::instance();
        if (function_exists('qls_shop_db')) {
            $this->shop_db = qls_shop_db();
        }
        $this->register_hooks();
    }

    /**
     * 注册缓存失效钩子
     *
     * @return void
     */
    private function register_hooks() {
        add_action('qilingshop_order_completed', [$this, 'bump_cache_version']);
        add_action('qilingshop_recharge_completed', [$this, 'bump_cache_version']);
        add_action('qilingshop_vip_upgraded', [$this, 'bump_cache_version']);
        add_action('qls_shop_order_paid', [$this, 'bump_cache_version']);
    }

    /**
     * 更新缓存版本号
     *
     * @return void
     */
    public function bump_cache_version(...$args) {
        $version = (int) get_option('qilingshop_trade_feed_cache_ver', 1);
        if ($version < 1) {
            $version = 1;
        }
        update_option('qilingshop_trade_feed_cache_ver', $version + 1);
    }

    /**
     * 获取播报列表
     *
     * @param int $limit 数量
     * @return array
     */
    public function get_feed_items($limit = 20) {
        if (!get_option('qilingshop_trade_feed_enabled', false)) {
            return [];
        }

        $limit = max(1, min((int) $limit, 50));
        $virtual_enabled = (bool) get_option('qilingshop_trade_feed_virtual_enabled', false);
        $virtual_ratio = max(0, min((int) get_option('qilingshop_trade_feed_virtual_ratio', 60), 100));
        $cache_ttl = max(10, min((int) get_option('qilingshop_trade_feed_cache_ttl', 120), 1800));
        $cache_key = $this->build_cache_key($limit, $virtual_enabled, $virtual_ratio);

        $cached_items = get_transient($cache_key);
        if (is_array($cached_items)) {
            return $cached_items;
        }

        $real_items = $this->get_real_items(max($limit * 2, 20));
        if (!empty($real_items)) {
            shuffle($real_items);
        }

        if ($virtual_enabled) {
            $items = $this->mix_items($real_items, $limit, $virtual_ratio);
        } else {
            $items = array_slice($real_items, 0, $limit);
        }

        if (!empty($items)) {
            shuffle($items);
        }

        set_transient($cache_key, $items, $cache_ttl);
        return $items;
    }

    /**
     * 构建缓存键
     *
     * @param int  $limit           数量
     * @param bool $virtual_enabled 虚拟数据开关
     * @param int  $virtual_ratio   虚拟占比
     * @return string
     */
    private function build_cache_key($limit, $virtual_enabled, $virtual_ratio) {
        $version = (int) get_option('qilingshop_trade_feed_cache_ver', 1);
        if ($version < 1) {
            $version = 1;
        }

        $raw = implode('|', [
            (string) get_current_blog_id(),
            (string) $version,
            (string) (int) $limit,
            (string) (int) $virtual_enabled,
            (string) (int) $virtual_ratio,
        ]);

        return 'qilingshop_tf_' . md5($raw);
    }

    /**
     * 混合真实与虚拟数据
     *
     * @param array $real_items    真实数据
     * @param int   $limit         总量
     * @param int   $virtual_ratio 虚拟占比
     * @return array
     */
    private function mix_items($real_items, $limit, $virtual_ratio) {
        $target_virtual = (int) round($limit * ($virtual_ratio / 100));
        $target_virtual = max(0, min($target_virtual, $limit));
        $target_real = $limit - $target_virtual;

        $items = array_slice($real_items, 0, $target_real);
        $missing = $limit - count($items);

        if ($missing > 0) {
            $items = array_merge($items, $this->build_virtual_items($missing));
        }

        if (count($items) < $limit && count($real_items) > $target_real) {
            $items = array_merge($items, array_slice($real_items, $target_real, $limit - count($items)));
        }

        return array_slice($items, 0, $limit);
    }

    /**
     * 获取真实交易数据
     *
     * @param int $limit 限制
     * @return array
     */
    private function get_real_items($limit) {
        $items = [];
        $bucket_limit = max(5, (int) ceil($limit / 4));

        $items = array_merge($items, $this->fetch_resource_items($bucket_limit));
        $items = array_merge($items, $this->fetch_shop_items($bucket_limit));
        $items = array_merge($items, $this->fetch_recharge_items($bucket_limit));
        $items = array_merge($items, $this->fetch_vip_items($bucket_limit));

        if (empty($items)) {
            return [];
        }

        usort($items, static function ($a, $b) {
            return (int) $b['ts'] - (int) $a['ts'];
        });

        return array_slice($items, 0, $limit);
    }

    /**
     * 获取资源购买播报
     *
     * @param int $limit 限制
     * @return array
     */
    private function fetch_resource_items($limit) {
        $limit = max(1, (int) $limit);
        $wpdb = $this->db->get_wpdb();
        $table = $this->db->get_table('orders');

        $sql = $wpdb->prepare(
            "SELECT id, user_id, post_id, post_title, paid_at, created_at
             FROM {$table}
             WHERE status = 1 AND order_type = %s
             ORDER BY COALESCE(paid_at, created_at) DESC
             LIMIT %d",
            'resource',
            $limit
        );
        $rows = $wpdb->get_results($sql);
        if (empty($rows)) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            $title = $this->clean_title($row->post_title);
            if ($title === '' && !empty($row->post_id)) {
                $title = $this->clean_title(get_the_title((int) $row->post_id));
            }
            if ($title === '') {
                $title = __('精选资源', 'qilingshop');
            }

            $timestamp = $this->to_timestamp($row->paid_at, $row->created_at);
            $items[] = [
                'id'     => 'resource-' . (int) $row->id,
                'type'   => 'resource',
                'avatar' => $this->resolve_avatar((int) $row->user_id, 'resource-' . (int) $row->id),
                'text'   => sprintf(__('刚刚购买了资源：%s', 'qilingshop'), $title),
                'ts'     => $timestamp,
            ];
        }

        return $items;
    }

    /**
     * 获取商城购买播报
     *
     * @param int $limit 限制
     * @return array
     */
    private function fetch_shop_items($limit) {
        if (!$this->shop_db) {
            return [];
        }

        $limit = max(1, (int) $limit);
        $wpdb = $this->shop_db->get_wpdb();
        $orders_table = $this->shop_db->get_table('orders');
        $items_table = $this->shop_db->get_table('order_items');

        $sql = $wpdb->prepare(
            "SELECT o.id, o.user_id, o.paid_at, o.created_at,
                (
                    SELECT oi.product_title
                    FROM {$items_table} oi
                    WHERE oi.order_id = o.id
                    ORDER BY oi.id ASC
                    LIMIT 1
                ) AS product_title
             FROM {$orders_table} o
             WHERE o.status BETWEEN 1 AND 3
             ORDER BY COALESCE(o.paid_at, o.created_at) DESC
             LIMIT %d",
            $limit
        );
        $rows = $wpdb->get_results($sql);
        if (empty($rows)) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            $title = $this->clean_title($row->product_title);
            if ($title === '') {
                $title = __('商城商品', 'qilingshop');
            }

            $timestamp = $this->to_timestamp($row->paid_at, $row->created_at);
            $items[] = [
                'id'     => 'shop-' . (int) $row->id,
                'type'   => 'shop',
                'avatar' => $this->resolve_avatar((int) $row->user_id, 'shop-' . (int) $row->id),
                'text'   => sprintf(__('刚刚购买了商品：%s', 'qilingshop'), $title),
                'ts'     => $timestamp,
            ];
        }

        return $items;
    }

    /**
     * 获取充值播报
     *
     * @param int $limit 限制
     * @return array
     */
    private function fetch_recharge_items($limit) {
        $limit = max(1, (int) $limit);
        $wpdb = $this->db->get_wpdb();
        $table = $this->db->get_table('recharge');

        $sql = $wpdb->prepare(
            "SELECT id, user_id, amount, paid_at, created_at
             FROM {$table}
             WHERE status = 1
             ORDER BY COALESCE(paid_at, created_at) DESC
             LIMIT %d",
            $limit
        );
        $rows = $wpdb->get_results($sql);
        if (empty($rows)) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            $timestamp = $this->to_timestamp($row->paid_at, $row->created_at);
            $items[] = [
                'id'     => 'recharge-' . (int) $row->id,
                'type'   => 'recharge',
                'avatar' => $this->resolve_avatar((int) $row->user_id, 'recharge-' . (int) $row->id),
                'text'   => sprintf(__('刚刚充值了 %s 元', 'qilingshop'), $this->format_money($row->amount)),
                'ts'     => $timestamp,
            ];
        }

        return $items;
    }

    /**
     * 获取 VIP 播报
     *
     * @param int $limit 限制
     * @return array
     */
    private function fetch_vip_items($limit) {
        $limit = max(1, (int) $limit);
        $wpdb = $this->db->get_wpdb();
        $table = $this->db->get_table('vip_log');

        $sql = $wpdb->prepare(
            "SELECT id, user_id, vip_level_name, created_at
             FROM {$table}
             ORDER BY created_at DESC
             LIMIT %d",
            $limit
        );
        $rows = $wpdb->get_results($sql);
        if (empty($rows)) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            $level_name = $this->clean_title($row->vip_level_name);
            if ($level_name === '') {
                $level_name = __('体验VIP', 'qilingshop');
            }

            $timestamp = $this->to_timestamp($row->created_at, '');
            $items[] = [
                'id'     => 'vip-' . (int) $row->id,
                'type'   => 'vip',
                'avatar' => $this->resolve_avatar((int) $row->user_id, 'vip-' . (int) $row->id),
                'text'   => sprintf(__('刚刚开通了 %s', 'qilingshop'), $level_name),
                'ts'     => $timestamp,
            ];
        }

        return $items;
    }

    /**
     * 构建虚拟数据
     *
     * @param int $count 条数
     * @return array
     */
    private function build_virtual_items($count) {
        $count = max(1, (int) $count);
        $resource_titles = [
            '运营增长实战模板包',
            '企业运营方案合集',
            '短视频脚本资料库',
            '品牌定位方法论',
            '私域转化SOP手册',
            '高转化落地页模板',
        ];
        $shop_titles = [
            '启灵周边礼盒',
            '精选办公设备',
            '创作者桌搭套装',
            '智能键盘配件',
            '效率提升工具包',
            '会员专享实体礼品',
        ];
        $vip_titles = [
            '体验VIP',
            '月度VIP',
            '季度VIP',
            '年度VIP',
            '尊享VIP',
        ];
        $recharge_amounts = [9.9, 19, 29, 49, 99, 199, 299];

        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $type = $this->pick_random(['resource', 'shop', 'recharge', 'vip']);
            $seed = 'virtual-' . wp_rand(1000, 999999) . '-' . $i;
            $timestamp = time() - wp_rand(20, 7200);

            if ($type === 'resource') {
                $text = sprintf(
                    __('刚刚购买了资源：%s', 'qilingshop'),
                    $this->pick_random($resource_titles)
                );
            } elseif ($type === 'shop') {
                $text = sprintf(
                    __('刚刚购买了商品：%s', 'qilingshop'),
                    $this->pick_random($shop_titles)
                );
            } elseif ($type === 'recharge') {
                $text = sprintf(
                    __('刚刚充值了 %s 元', 'qilingshop'),
                    $this->format_money($this->pick_random($recharge_amounts))
                );
            } else {
                $text = sprintf(
                    __('刚刚开通了 %s', 'qilingshop'),
                    $this->pick_random($vip_titles)
                );
            }

            $items[] = [
                'id'     => 'virtual-' . $seed,
                'type'   => $type,
                'avatar' => $this->build_avatar_data_uri($seed),
                'text'   => $text,
                'ts'     => $timestamp,
            ];
        }

        return $items;
    }

    /**
     * 清理标题
     *
     * @param string $title 标题
     * @return string
     */
    private function clean_title($title) {
        $title = wp_strip_all_tags((string) $title);
        $title = trim($title);
        if ($title === '') {
            return '';
        }
        return wp_html_excerpt($title, 48, '...');
    }

    /**
     * 格式化金额
     *
     * @param mixed $amount 金额
     * @return string
     */
    private function format_money($amount) {
        $amount = (float) $amount;
        if (abs($amount - floor($amount)) < 0.01) {
            return (string) (int) round($amount);
        }
        return number_format($amount, 2, '.', '');
    }

    /**
     * 转时间戳
     *
     * @param string $primary   主时间
     * @param string $secondary 备选时间
     * @return int
     */
    private function to_timestamp($primary, $secondary) {
        $timestamp = 0;
        if (!empty($primary)) {
            $timestamp = strtotime((string) $primary);
        }
        if ($timestamp <= 0 && !empty($secondary)) {
            $timestamp = strtotime((string) $secondary);
        }
        if ($timestamp <= 0) {
            $timestamp = time();
        }
        return (int) $timestamp;
    }

    /**
     * 解析头像（真实用户优先）
     *
     * @param int    $user_id 用户ID
     * @param string $seed    种子
     * @return string
     */
    private function resolve_avatar($user_id, $seed) {
        $user_id = (int) $user_id;
        if ($user_id > 0) {
            $avatar_url = get_avatar_url($user_id, ['size' => 64, 'default' => 'mystery']);
            if (is_string($avatar_url) && $avatar_url !== '') {
                return esc_url_raw($avatar_url);
            }
        }
        return $this->build_avatar_data_uri((string) $seed);
    }

    /**
     * 构造默认头像（SVG Data URI）
     *
     * @param string $seed 随机种子
     * @return string
     */
    private function build_avatar_data_uri($seed) {
        $palette = [
            ['#3b82f6', '#dbeafe'],
            ['#10b981', '#dcfce7'],
            ['#f59e0b', '#fef3c7'],
            ['#8b5cf6', '#ede9fe'],
            ['#ef4444', '#fee2e2'],
            ['#0ea5e9', '#e0f2fe'],
        ];
        $index = abs((int) crc32((string) $seed)) % count($palette);
        $pair = $palette[$index];
        $bg = $pair[0];
        $face = $pair[1];

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            . '<rect width="64" height="64" rx="32" fill="' . esc_attr($bg) . '"/>'
            . '<circle cx="32" cy="24" r="11" fill="' . esc_attr($face) . '"/>'
            . '<path d="M14 54c2-10 10-16 18-16s16 6 18 16" fill="' . esc_attr($face) . '"/>'
            . '</svg>';

        return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
    }

    /**
     * 随机取数组元素
     *
     * @param array $pool 候选池
     * @return mixed|string
     */
    private function pick_random($pool) {
        if (!is_array($pool) || empty($pool)) {
            return '';
        }
        $key = array_rand($pool);
        return $pool[$key];
    }
}
