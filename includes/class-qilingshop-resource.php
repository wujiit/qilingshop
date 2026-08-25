<?php
/**
 * 资源/商品管理类
 *
 * @package QilingShop
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Resource {

    private static $instance = null;
    private $db;

    // Meta keys
    const META_PRICE = '_qilingshop_price';
    const META_PRICE_RMB = '_qilingshop_price_rmb';
    const META_PRICE_DOWNLOAD = '_qilingshop_price_download';
    const META_PRICE_VIEW = '_qilingshop_price_view';
    const META_PRICE_RMB_DOWNLOAD = '_qilingshop_price_rmb_download';
    const META_PRICE_RMB_VIEW = '_qilingshop_price_rmb_view';
    const META_VIP_DISCOUNT = '_qilingshop_vip_discount';
    const META_VIP_MIN_LEVEL = '_qilingshop_vip_min_level';
    const META_VIP_ONLY = '_qilingshop_vip_only';
    const META_VIP_ONLY_PURCHASE = '_qilingshop_vip_only_purchase';
    const META_VIP_ONLY_ACCESS = '_qilingshop_vip_only_access';
    const META_VIP_FREE = '_qilingshop_vip_free';
    const META_VIP_DISCOUNT_MODE = '_qilingshop_vip_discount_mode';
    const META_VIP_DISCOUNT_PERCENT = '_qilingshop_vip_discount_percent';
    const META_VIP_LEVEL_DISCOUNTS = '_qilingshop_vip_level_discounts';
    const META_DOWNLOAD_URLS = '_qilingshop_download_urls';
    const META_HIDDEN_CONTENT = '_qilingshop_hidden_content';
    const META_SALE_MODE = '_qilingshop_sale_mode';
    const META_IS_RESOURCE = '_qilingshop_is_resource';

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->db = QilingShop_Database::instance();
    }

    /**
     * 同步资源标记，供后台批量页走纯索引查询。
     *
     * @param int $post_id
     * @return bool
     */
    public function sync_resource_marker($post_id) {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return false;
        }

        if ($this->has_resource_features($post_id)) {
            update_post_meta($post_id, self::META_IS_RESOURCE, '1');
            return true;
        }

        delete_post_meta($post_id, self::META_IS_RESOURCE);
        return false;
    }

    /**
     * 判断文章是否具备资源特征。
     *
     * @param int $post_id
     * @return bool
     */
    public function has_resource_features($post_id) {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return false;
        }

        if ($this->meta_has_content($post_id, self::META_DOWNLOAD_URLS)) {
            return true;
        }

        if ($this->meta_has_content($post_id, self::META_HIDDEN_CONTENT)) {
            return true;
        }

        $post = get_post($post_id);
        if ($post instanceof WP_Post) {
            $post_content = (string) $post->post_content;
            if (
                strpos($post_content, '[qls_content') !== false ||
                strpos($post_content, '[qilingshop_hidden') !== false ||
                strpos($post_content, '<!--qls_content_start-->') !== false
            ) {
                return true;
            }
        }

        $price_keys = [
            self::META_PRICE_DOWNLOAD,
            self::META_PRICE_VIEW,
            self::META_PRICE,
            self::META_PRICE_RMB_DOWNLOAD,
            self::META_PRICE_RMB_VIEW,
            self::META_PRICE_RMB,
        ];

        foreach ($price_keys as $key) {
            if ((float) get_post_meta($post_id, $key, true) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * 判断 Meta 是否包含有效内容。
     *
     * @param int    $post_id
     * @param string $meta_key
     * @return bool
     */
    private function meta_has_content($post_id, $meta_key) {
        $value = get_post_meta($post_id, $meta_key, true);

        if (is_array($value)) {
            $value = implode("\n", array_filter(array_map('strval', $value)));
        }

        return trim((string) $value) !== '';
    }

    /**
     * 获取资源积分价格
     */
    public function get_points_price($post_id, $context = 'download') {
        $price = '';
        if ($context === 'view') {
            $price = get_post_meta($post_id, self::META_PRICE_VIEW, true);
        } else {
            $price = get_post_meta($post_id, self::META_PRICE_DOWNLOAD, true);
        }
        if ($price === '' || $price === false) {
            if ($context === 'view') {
                $price = get_post_meta($post_id, self::META_PRICE_DOWNLOAD, true);
            } else {
                $price = get_post_meta($post_id, self::META_PRICE_VIEW, true);
            }
        }
        if ($price === '' || $price === false) {
            $price = get_post_meta($post_id, self::META_PRICE, true);
        }
        if ($price === '' || $price === false) {
            $price = 0;
        }
        return (float) $price;
    }

    /**
     * 获取资源人民币价格
     */
    public function get_rmb_price($post_id, $context = 'download') {
        $price = '';
        if ($context === 'view') {
            $price = get_post_meta($post_id, self::META_PRICE_RMB_VIEW, true);
        } else {
            $price = get_post_meta($post_id, self::META_PRICE_RMB_DOWNLOAD, true);
        }
        if ($price === '' || $price === false) {
            if ($context === 'view') {
                $price = get_post_meta($post_id, self::META_PRICE_RMB_DOWNLOAD, true);
            } else {
                $price = get_post_meta($post_id, self::META_PRICE_RMB_VIEW, true);
            }
        }
        if ($price === '' || $price === false) {
            $price = get_post_meta($post_id, self::META_PRICE_RMB, true);
        }
        if ($price === '' || $price === false) {
            // 从积分价格计算
            return qilingshop_points_to_rmb($this->get_points_price($post_id, $context));
        }
        return (float) $price;
    }

    /**
     * 获取资源价格（含 VIP 折扣）
     */
    public function get_price($post_id, $user_id = null, $context = '') {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        if ($context === '') {
            $context = $this->get_sale_mode($post_id) === 'view' ? 'view' : 'download';
        }
        $original_points = $this->get_points_price($post_id, $context);
        $original_rmb = $this->get_rmb_price($post_id, $context);
        
        $final_points = $original_points;
        $final_rmb = $original_rmb;

        // 检查 VIP 免费
        if ($user_id && $this->is_vip_free($post_id, $user_id, $context)) {
            return [
                'points'   => 0,
                'rmb'      => 0,
                'original' => $original_points,
                'discount' => 100,
                'is_free'  => true,
            ];
        }

        // 计算 VIP 折扣
        if ($user_id) {
            $discount = $this->get_vip_discount($post_id, $user_id, $context);
            if ($discount < 100) {
                $final_points = round($original_points * $discount / 100, 2);
                $final_rmb = round($original_rmb * $discount / 100, 2);
            }
        }

        $final_points = apply_filters('qilingshop_resource_price', $final_points, $post_id, $user_id);

        return [
            'points'   => $final_points,
            'rmb'      => $final_rmb,
            'original' => $original_points,
            'discount' => $user_id ? $this->get_vip_discount($post_id, $user_id, $context) : 100,
            'is_free'  => $final_points <= 0,
        ];
    }

    /**
     * 获取 VIP 折扣
     */
    public function get_vip_discount($post_id, $user_id = null, $context = 'download') {
        if (!$user_id) {
            return 100;
        }

        $vip_level = QilingShop_VIP::instance()->get_user_level($user_id);
        if ($vip_level <= 0) {
            return 100;
        }

        return $this->get_vip_discount_by_level($post_id, $vip_level, $context);
    }

    /**
     * 根据 VIP 等级获取折扣
     */
    public function get_vip_discount_by_level($post_id, $level_id, $context = 'download') {
        $level_id = (int) $level_id;
        if ($level_id <= 0) {
            return 100;
        }

        $level_info = QilingShop_VIP::instance()->get_level_by_id($level_id);
        if (!$level_info) {
            return 100;
        }

        $min_level = $this->get_vip_min_level($post_id);
        if (!$this->level_meets_min($level_id, $min_level)) {
            return 100;
        }

        // VIP 免费优先
        $vip_free_mode = $this->get_vip_free_mode($post_id);
        if ($this->vip_mode_applies($vip_free_mode, $context)) {
            return 0;
        }

        $discount_mode = get_post_meta($post_id, self::META_VIP_DISCOUNT_MODE, true);
        if ($discount_mode === '') {
            $discount_mode = 'legacy';
        }

        if ($discount_mode === 'none') {
            return 100;
        }

        if ($discount_mode === 'custom') {
            $percent = (int) get_post_meta($post_id, self::META_VIP_DISCOUNT_PERCENT, true);
            return $this->normalize_discount_percent($percent);
        }

        if ($discount_mode === 'per_level') {
            $level_discounts = $this->get_vip_level_discounts($post_id);
            if (isset($level_discounts[$level_id])) {
                return $this->normalize_discount_percent($level_discounts[$level_id]);
            }
            return $this->normalize_discount_percent($level_info->discount_rate);
        }

        // legacy / inherit
        $vip_setting = get_post_meta($post_id, self::META_VIP_DISCOUNT, true);
        if ($vip_setting === '') {
            $vip_setting = get_option('qilingshop_default_vip_discount', 'none');
        }

        if (!empty($vip_setting) && $vip_setting !== 'none' && $vip_setting !== 'default') {
            if ($vip_setting === 'vip_free') {
                return 0;
            }
            if (preg_match('/^vip_(\d+)$/', $vip_setting, $matches)) {
                return $this->normalize_discount_percent((int) $matches[1]);
            }
        }

        return $this->normalize_discount_percent($level_info->discount_rate);
    }

    /**
     * 检查 VIP 是否免费
     */
    public function is_vip_free($post_id, $user_id = null, $context = 'download') {
        if (!$user_id) {
            return false;
        }

        $vip_level = QilingShop_VIP::instance()->get_user_level($user_id);
        if ($vip_level <= 0) {
            return false;
        }

        $min_level = $this->get_vip_min_level($post_id);
        if (!$this->level_meets_min($vip_level, $min_level)) {
            return false;
        }

        $vip_free_mode = $this->get_vip_free_mode($post_id);
        if ($this->vip_mode_applies($vip_free_mode, $context)) {
            return true;
        }

        $vip_setting = get_post_meta($post_id, self::META_VIP_DISCOUNT, true);
        if ($vip_setting === '') {
            $vip_setting = get_option('qilingshop_default_vip_discount', 'none');
        }
        if ($vip_setting === 'vip_free') {
            return true;
        }

        if ($context === 'download') {
            $level_info = QilingShop_VIP::instance()->get_level_by_id($vip_level);
            if ($level_info && $level_info->can_download_free && (int) $level_info->discount_rate === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * 是否 VIP 仅限购买
     */
    public function is_vip_only_purchase($post_id, $context = 'download') {
        $vip_only = get_post_meta($post_id, self::META_VIP_ONLY_PURCHASE, true);
        if ($vip_only === '') {
            $vip_only = get_post_meta($post_id, self::META_VIP_ONLY, true);
        }
        if ($vip_only === '') {
            return false;
        }
        return $this->vip_mode_applies($vip_only, $context);
    }

    /**
     * 是否 VIP 仅限访问
     */
    public function is_vip_only_access($post_id, $context = 'download') {
        $vip_only = get_post_meta($post_id, self::META_VIP_ONLY_ACCESS, true);
        if ($vip_only === '') {
            return false;
        }
        return $this->vip_mode_applies($vip_only, $context);
    }

    /**
     * 兼容旧方法（视作仅限购买）
     */
    public function is_vip_only($post_id, $context = 'download') {
        return $this->is_vip_only_purchase($post_id, $context);
    }

    /**
     * 是否满足 VIP 访问条件
     */
    public function has_vip_access($post_id, $user_id = null, $context = 'download') {
        if (!$user_id) {
            return false;
        }
        $min_level = $this->get_vip_min_level($post_id);
        return QilingShop_VIP::instance()->is_vip($user_id, $min_level);
    }

    public function get_vip_min_level($post_id) {
        $min_level = (int) get_post_meta($post_id, self::META_VIP_MIN_LEVEL, true);
        if ($min_level <= 0) {
            $min_level = 1;
        }
        return $min_level;
    }

    public function get_vip_free_mode($post_id) {
        $vip_free = get_post_meta($post_id, self::META_VIP_FREE, true);
        if ($vip_free === '') {
            $vip_setting = get_post_meta($post_id, self::META_VIP_DISCOUNT, true);
            if ($vip_setting === '') {
                $vip_setting = get_option('qilingshop_default_vip_discount', 'none');
            }
            if ($vip_setting === 'vip_free') {
                return 'both';
            }
            return 'none';
        }
        return $vip_free;
    }

    public function get_vip_level_discounts($post_id) {
        $raw = get_post_meta($post_id, self::META_VIP_LEVEL_DISCOUNTS, true);
        if (empty($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $result = [];
        foreach ($decoded as $level_id => $discount) {
            $level_id = (int) $level_id;
            if ($level_id <= 0) {
                continue;
            }
            $result[$level_id] = $this->normalize_discount_percent((int) $discount);
        }
        return $result;
    }

    private function vip_mode_applies($mode, $context) {
        $mode = $mode ?: 'none';
        if ($mode === 'both') {
            return true;
        }
        if ($context === 'view' && $mode === 'view') {
            return true;
        }
        if ($context === 'download' && $mode === 'download') {
            return true;
        }
        return false;
    }

    private function normalize_discount_percent($percent) {
        $percent = (int) $percent;
        if ($percent < 0) {
            return 0;
        }
        if ($percent > 100) {
            return 100;
        }
        return $percent;
    }

    private function level_meets_min($level_id, $min_level) {
        if ($min_level <= 1) {
            return true;
        }
        $vip = QilingShop_VIP::instance();
        $user_level = $vip->get_level_by_id($level_id);
        $min_level_info = $vip->get_level_by_id($min_level);
        if (!$user_level || !$min_level_info) {
            return $level_id >= $min_level;
        }
        return $user_level->sort_order >= $min_level_info->sort_order;
    }

    /**
     * 判断并规范化下载地址。
     * 内部路径统一转换为站点绝对地址，交给现有安全下载令牌处理。
     *
     * @param string $url 下载地址。
     * @return string
     */
    private function normalize_download_url($url) {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        if (preg_match('~^/(?!/)[^\s]+$~u', $url)) {
            return home_url($url);
        }

        return '';
    }

    /**
     * 获取下载地址列表
     */
    public function get_download_urls($post_id) {
        $urls_raw = get_post_meta($post_id, self::META_DOWNLOAD_URLS, true);
        
        if (empty($urls_raw)) {
            return [];
        }

        $lines = explode("\n", $urls_raw);
        $urls = [];
        $extra_index = count($lines);

        foreach ($lines as $index => $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // 先处理“名称,链接,提取码”格式，确保名称不会因网盘域名或链接格式不同而丢失。
            $comma_parts = array_map('trim', explode(',', $line));
            $comma_url = isset($comma_parts[1]) ? $this->normalize_download_url($comma_parts[1]) : '';
            if (count($comma_parts) >= 2 && $comma_parts[0] !== '' && $comma_url !== '') {
                $urls[] = [
                    'index' => $index,
                    'name'  => $comma_parts[0],
                    'url'   => $comma_url,
                    'code'  => isset($comma_parts[2]) ? preg_replace('/^(?:提取码|访问码|密码)\s*[:：]?\s*/u', '', $comma_parts[2]) : '',
                ];
                continue;
            }

            // 先提取真实 URL，再解析前后的名称和提取码，兼容逗号及带标签格式。
            $url_matches = [];
            preg_match_all('~(?:https?://[^\s，,]+|/(?!/)[^\s，,]+)~iu', $line, $url_matches, PREG_OFFSET_CAPTURE);
            $matched_urls = isset($url_matches[0]) ? $url_matches[0] : [];
            if (!empty($matched_urls)) {
                foreach ($matched_urls as $match_position => $matched_url) {
                    $raw_url = (string) $matched_url[0];
                    $url_offset = (int) $matched_url[1];
                    $url = rtrim($raw_url, ".;，。；");
                    $url = $this->normalize_download_url($url);
                    if ($url === '') {
                        continue;
                    }

                    $prefix_start = 0;
                    if ($match_position > 0) {
                        $previous_url = $matched_urls[$match_position - 1];
                        $prefix_start = (int) $previous_url[1] + strlen((string) $previous_url[0]);
                    }
                    $prefix = substr($line, $prefix_start, $url_offset - $prefix_start);
                    $name = '';
                    if (preg_match('/([^\s,，;；:：]{1,30})\s*[:：]\s*$/u', $prefix, $name_match)) {
                        $name = trim($name_match[1]);
                    } else {
                        $name = trim($prefix, " \t\n\r\0\x0B,，;；:：");
                        if (preg_match('/(?:提取码|访问码|密码)\s*[:：]?\s*[^\s,，;；]+\s+(.+)$/u', $name, $name_match)) {
                            $name = trim($name_match[1], " \t\n\r\0\x0B,，;；:：");
                        }
                    }
                    if ($name === '') {
                        $name = __('下载地址', 'qilingshop') . ' ' . (count($urls) + 1);
                    }

                    $suffix_start = $url_offset + strlen($raw_url);
                    $suffix_end = isset($matched_urls[$match_position + 1])
                        ? (int) $matched_urls[$match_position + 1][1]
                        : strlen($line);
                    $suffix = substr($line, $suffix_start, max(0, $suffix_end - $suffix_start));
                    $code = '';
                    if (preg_match('/(?:提取码|访问码|密码)\s*[:：]?\s*([^\s,，;；]+)/u', $suffix, $code_match)) {
                        $code = trim($code_match[1]);
                    } else {
                        $code = trim($suffix, " \t\n\r\0\x0B,，;；:：");
                        $code = preg_replace('/^(?:提取码|访问码|密码)\s*[:：]?\s*/u', '', $code);
                    }

                    $urls[] = [
                        'index' => $match_position === 0 ? $index : $extra_index++,
                        'name'  => $name,
                        'url'   => $url,
                        'code'  => $code,
                    ];
                }
                continue;
            }

            // 解析格式：名称,链接,提取码 或 链接,提取码 或 链接
            $parts = explode(',', $line);
            
            if (count($parts) >= 3) {
                $part_url = $this->normalize_download_url($parts[1]);
                if ($part_url !== '') {
                    $urls[] = [
                        'index' => $index,
                        'name'  => trim($parts[0]),
                        'url'   => $part_url,
                        'code'  => preg_replace('/^(?:提取码|访问码|密码)\s*[:：]?\s*/u', '', trim($parts[2])),
                    ];
                }
            } elseif (count($parts) == 2) {
                $part_url = $this->normalize_download_url($parts[0]);
                if ($part_url !== '') {
                    $urls[] = [
                        'index' => $index,
                        'name'  => __('下载地址', 'qilingshop') . ' ' . ($index + 1),
                        'url'   => $part_url,
                        'code'  => trim($parts[1]),
                    ];
                } else {
                    $part_url = $this->normalize_download_url($parts[1]);
                    $urls[] = [
                        'index' => $index,
                        'name'  => trim($parts[0]),
                        'url'   => $part_url !== '' ? $part_url : trim($parts[1]),
                        'code'  => isset($parts[2]) ? trim($parts[2]) : '',
                    ];
                }
            } else {
                $single_url = $this->normalize_download_url($parts[0]);
                $urls[] = [
                    'index' => $index,
                    'name'  => $single_url !== '' ? __('下载地址', 'qilingshop') . ' ' . ($index + 1) : trim($parts[0]),
                    'url'   => $single_url !== '' ? $single_url : trim($parts[0]),
                    'code'  => '',
                ];
            }
        }

        return apply_filters('qilingshop_download_urls', $urls, $post_id);
    }

    /**
     * 获取隐藏内容
     */
    public function get_hidden_content($post_id) {
        return get_post_meta($post_id, self::META_HIDDEN_CONTENT, true);
    }

    /**
     * 获取销售模式
     */
    public function get_sale_mode($post_id) {
        $mode = get_post_meta($post_id, self::META_SALE_MODE, true);
        return $mode ?: 'download'; // download, view, card
    }

    /**
     * 检查资源是否为付费资源
     */
    public function is_paid_resource($post_id, $context = 'download') {
        $price = $this->get_points_price($post_id, $context);
        return $price > 0;
    }

    /**
     * 检查资源是否有下载地址
     */
    public function has_download_urls($post_id) {
        $urls = $this->get_download_urls($post_id);
        return !empty($urls);
    }

    /**
     * 检查指定下载项是否存在
     */
    public function download_index_exists($post_id, $download_index) {
        $download_index = (int) $download_index;
        if ($download_index < 0) {
            return true;
        }

        $urls = $this->get_download_urls($post_id);
        foreach ($urls as $position => $url) {
            $index = isset($url['index']) ? (int) $url['index'] : (int) $position;
            if ($index === $download_index) {
                return true;
            }
        }

        return false;
    }

    /**
     * 记录下载
     */
    public function log_download($post_id, $user_id = 0, $guest_id = '', $url_index = 0, $is_vip_free = false) {
        $this->db->insert('downloads', [
            'user_id'        => $user_id,
            'guest_id'       => $guest_id,
            'post_id'        => $post_id,
            'download_index' => $url_index,
            'is_vip_free'    => $is_vip_free ? 1 : 0,
            'ip_address'     => qilingshop_security()->get_client_ip(),
            'user_agent'     => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
            'created_at'     => current_time('mysql'),
        ]);

        do_action('qilingshop_resource_downloaded', $post_id, $user_id, $url_index);
    }

    /**
     * 获取用户下载记录
     */
    public function get_user_downloads($user_id, $args = []) {
        $defaults = ['limit' => 20, 'offset' => 0];
        $args = wp_parse_args($args, $defaults);

        return $this->db->get_results('downloads', [
            'where'   => ['user_id' => $user_id],
            'orderby' => 'id',
            'order'   => 'DESC',
            'limit'   => $args['limit'],
            'offset'  => $args['offset'],
        ]);
    }

    /**
     * 获取资源下载次数
     */
    public function get_download_count($post_id) {
        return $this->db->count('downloads', ['post_id' => $post_id]);
    }

    /**
     * 保存资源 Meta
     */
    public function save_meta($post_id, $data) {
        if (isset($data['price'])) {
            update_post_meta($post_id, self::META_PRICE, floatval($data['price']));
        }
        if (isset($data['price_download'])) {
            update_post_meta($post_id, self::META_PRICE_DOWNLOAD, floatval($data['price_download']));
        }
        if (isset($data['price_view'])) {
            update_post_meta($post_id, self::META_PRICE_VIEW, floatval($data['price_view']));
        }
        if (!isset($data['price']) && (isset($data['price_download']) || isset($data['price_view']))) {
            $legacy_price = isset($data['price_download']) && floatval($data['price_download']) > 0 ? floatval($data['price_download']) : (isset($data['price_view']) ? floatval($data['price_view']) : 0);
            update_post_meta($post_id, self::META_PRICE, $legacy_price);
        }
        if (isset($data['price_rmb'])) {
            update_post_meta($post_id, self::META_PRICE_RMB, floatval($data['price_rmb']));
        }
        if (isset($data['price_rmb_download'])) {
            update_post_meta($post_id, self::META_PRICE_RMB_DOWNLOAD, floatval($data['price_rmb_download']));
        }
        if (isset($data['price_rmb_view'])) {
            update_post_meta($post_id, self::META_PRICE_RMB_VIEW, floatval($data['price_rmb_view']));
        }
        if (isset($data['vip_discount'])) {
            update_post_meta($post_id, self::META_VIP_DISCOUNT, sanitize_text_field($data['vip_discount']));
        }
        if (isset($data['vip_min_level'])) {
            update_post_meta($post_id, self::META_VIP_MIN_LEVEL, absint($data['vip_min_level']));
        }
        if (isset($data['vip_only'])) {
            update_post_meta($post_id, self::META_VIP_ONLY, sanitize_text_field($data['vip_only']));
        }
        if (isset($data['vip_only_purchase'])) {
            update_post_meta($post_id, self::META_VIP_ONLY_PURCHASE, sanitize_text_field($data['vip_only_purchase']));
            update_post_meta($post_id, self::META_VIP_ONLY, sanitize_text_field($data['vip_only_purchase']));
        }
        if (isset($data['vip_only_access'])) {
            update_post_meta($post_id, self::META_VIP_ONLY_ACCESS, sanitize_text_field($data['vip_only_access']));
        }
        if (isset($data['vip_free'])) {
            update_post_meta($post_id, self::META_VIP_FREE, sanitize_text_field($data['vip_free']));
        }
        if (isset($data['vip_discount_mode'])) {
            update_post_meta($post_id, self::META_VIP_DISCOUNT_MODE, sanitize_text_field($data['vip_discount_mode']));
        }
        if (isset($data['vip_discount_percent'])) {
            update_post_meta($post_id, self::META_VIP_DISCOUNT_PERCENT, absint($data['vip_discount_percent']));
        }
        if (isset($data['vip_level_discounts'])) {
            update_post_meta($post_id, self::META_VIP_LEVEL_DISCOUNTS, sanitize_text_field($data['vip_level_discounts']));
        }
        if (isset($data['download_urls'])) {
            update_post_meta($post_id, self::META_DOWNLOAD_URLS, sanitize_textarea_field($data['download_urls']));
        }
        if (isset($data['hidden_content'])) {
            update_post_meta($post_id, self::META_HIDDEN_CONTENT, sanitize_textarea_field($data['hidden_content']));
        }
        if (isset($data['sale_mode'])) {
            update_post_meta($post_id, self::META_SALE_MODE, sanitize_text_field($data['sale_mode']));
        }
    }
}
