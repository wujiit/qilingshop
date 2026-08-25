<?php
/**
 * 网盘链接有效性检测类
 * 
 * 支持检测：百度网盘、夸克网盘、天翼云盘、123云盘
 * 
 * @package QilingShop
 * @since   2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Pancheck {
    
    /**
     * 支持的网盘类型配置
     */
    private static $supported_pans = [
        'baidu' => [
            'domain'  => 'pan.baidu.com',
            'name'    => '百度网盘',
        ],
        'quark' => [
            'domain'  => 'pan.quark.cn',
            'name'    => '夸克网盘',
        ],
        'tianyi' => [
            'domain'  => 'cloud.189.cn',
            'name'    => '天翼云盘',
        ],
        '123pan' => [
            'domain'  => '123pan.com',
            'name'    => '123云盘',
        ],
    ];
    
    /**
     * 请求超时时间（秒）
     */
    private static $timeout = 10;

    /**
     * 有效结果短缓存时长（秒）
     */
    private static $valid_cache_ttl = 60;

    /**
     * 无效结果短缓存时长（秒）
     */
    private static $invalid_cache_ttl = 120;
    
    /**
     * 检测链接是否有效
     * 
     * @param string $url  分享链接
     * @param string $code 提取码（可选）
     * @return array ['success' => bool, 'status' => 'valid'|'invalid'|'unsupported'|'error', 'message' => string]
     */
    public static function check($url, $code = '') {
        $normalized_url = self::normalize_url($url);
        if (!$normalized_url) {
            return [
                'success' => false,
                'status'  => 'unsupported',
                'message' => __('不支持检测此网盘类型', 'qilingshop'),
            ];
        }

        $pan_type = self::detect_pan_type($normalized_url);
        
        if (!$pan_type) {
            return [
                'success' => false,
                'status'  => 'unsupported',
                'message' => __('不支持检测此网盘类型', 'qilingshop'),
            ];
        }
        
        $method = 'check_' . $pan_type;
        
        if (!method_exists(__CLASS__, $method)) {
            return [
                'success' => false,
                'status'  => 'error',
                'message' => __('检测方法不存在', 'qilingshop'),
            ];
        }

        $cache_key = self::get_cache_key($normalized_url, $code);
        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['success'], $cached['status'], $cached['message'])) {
            return $cached;
        }

        $result = self::$method($normalized_url, $code);
        $cache_ttl = self::get_cache_ttl($result);
        if ($cache_ttl > 0 && is_array($result) && isset($result['success'], $result['status'], $result['message'])) {
            set_transient($cache_key, $result, $cache_ttl);
        }

        return $result;
    }

    /**
     * 获取缓存 key。
     *
     * @param string $url
     * @param string $code
     * @return string
     */
    private static function get_cache_key($url, $code = '') {
        $payload = strtolower(trim((string) $url)) . '|' . trim((string) $code);
        return 'qilingshop_pancheck_' . md5($payload);
    }

    /**
     * 获取缓存时长。
     *
     * 仅做短冷却，避免长时间复用旧状态。
     *
     * @param array $result
     * @return int
     */
    private static function get_cache_ttl($result) {
        $status = isset($result['status']) ? (string) $result['status'] : '';

        if ($status === 'valid') {
            $ttl = (int) apply_filters('qilingshop_pancheck_valid_cache_ttl', self::$valid_cache_ttl);
            return max(0, $ttl);
        }

        if ($status === 'invalid') {
            $ttl = (int) apply_filters('qilingshop_pancheck_invalid_cache_ttl', self::$invalid_cache_ttl);
            return max(0, $ttl);
        }

        return 0;
    }
    
    /**
     * 识别网盘类型
     * 
     * @param string $url 分享链接
     * @return string|false 网盘类型标识或 false
     */
    public static function detect_pan_type($url) {
        $normalized_url = self::normalize_url($url);
        if (!$normalized_url) {
            return false;
        }

        $host = parse_url($normalized_url, PHP_URL_HOST);
        if (empty($host)) {
            return false;
        }
        $host = strtolower((string) $host);

        foreach (self::$supported_pans as $type => $config) {
            if (self::is_host_match($host, $config['domain'])) {
                return $type;
            }
        }
        return false;
    }

    /**
     * 规范化并校验 URL（仅允许 https）
     *
     * @param string $url 原始 URL
     * @return string|false
     */
    private static function normalize_url($url) {
        $url = trim((string) $url);
        if ($url === '') {
            return false;
        }

        $parsed = wp_parse_url($url);
        if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
            return false;
        }

        $scheme = strtolower((string) $parsed['scheme']);
        if ($scheme !== 'https') {
            return false;
        }

        return esc_url_raw($url);
    }

    /**
     * 主机名严格匹配：等于域名或其子域名
     *
     * @param string $host 当前主机
     * @param string $domain 白名单域名
     * @return bool
     */
    private static function is_host_match($host, $domain) {
        $host = strtolower((string) $host);
        $domain = strtolower((string) $domain);

        if ($host === $domain) {
            return true;
        }

        $suffix = '.' . $domain;
        return substr($host, -strlen($suffix)) === $suffix;
    }
    
    /**
     * 获取网盘名称
     * 
     * @param string $url 分享链接
     * @return string 网盘名称
     */
    public static function get_pan_name($url) {
        $type = self::detect_pan_type($url);
        if ($type && isset(self::$supported_pans[$type])) {
            return self::$supported_pans[$type]['name'];
        }
        return __('未知网盘', 'qilingshop');
    }
    
    /**
     * 检查是否为支持的网盘
     * 
     * @param string $url 分享链接
     * @return bool
     */
    public static function is_supported($url) {
        return self::detect_pan_type($url) !== false;
    }
    
    /**
     * 获取所有支持的网盘列表
     * 
     * @return array
     */
    public static function get_supported_pans() {
        return self::$supported_pans;
    }
    
    /**
     * 检测百度网盘
     * 
     * 失效标识：
     * - "链接不存在" / "分享的文件已经被取消"
     * - "此链接分享内容可能因为涉及侵权"
     * - "分享已过期"
     * 
     * @param string $url  分享链接
     * @param string $code 提取码
     * @return array
     */
    private static function check_baidu($url, $code = '') {
        $response = wp_remote_get($url, [
            'timeout'    => self::$timeout,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'headers'    => [
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'zh-CN,zh;q=0.9',
            ],
        ]);
        
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'status'  => 'error',
                'message' => __('网络请求失败', 'qilingshop'),
            ];
        }
        
        $body = wp_remote_retrieve_body($response);
        $status_code = wp_remote_retrieve_response_code($response);
        
        // 检查HTTP状态码
        if ($status_code === 404) {
            return [
                'success' => true,
                'status'  => 'invalid',
                'message' => __('链接已失效', 'qilingshop'),
            ];
        }
        
        // 检查失效标识
        $invalid_patterns = [
            '链接不存在',
            '分享的文件已经被取消',
            '分享已过期',
            '此链接分享内容可能因为涉及侵权',
            '分享已被取消',
            '链接已失效',
            'share_nofound_des',
            'error-page',
        ];
        
        foreach ($invalid_patterns as $pattern) {
            if (strpos($body, $pattern) !== false) {
                return [
                    'success' => true,
                    'status'  => 'invalid',
                    'message' => __('链接已失效', 'qilingshop'),
                ];
            }
        }
        
        // 检查有效标识
        $valid_patterns = [
            'share-header',
            'file-name',
            '保存到网盘',
            'share-file-list',
        ];
        
        foreach ($valid_patterns as $pattern) {
            if (strpos($body, $pattern) !== false) {
                return [
                    'success' => true,
                    'status'  => 'valid',
                    'message' => __('链接正常', 'qilingshop'),
                ];
            }
        }
        
        // 如果需要提取码，可能显示输入框页面
        if (strpos($body, '请输入提取码') !== false || strpos($body, 'input-code') !== false) {
            return [
                'success' => true,
                'status'  => 'valid',
                'message' => __('链接正常（需提取码）', 'qilingshop'),
            ];
        }
        
        // 无法确定状态
        return [
            'success' => true,
            'status'  => 'valid',
            'message' => __('链接可能有效', 'qilingshop'),
        ];
    }
    
    /**
     * 检测夸克网盘
     * 
     * @param string $url  分享链接
     * @param string $code 提取码（夸克一般不需要）
     * @return array
     */
    private static function check_quark($url, $code = '') {
        $response = wp_remote_get($url, [
            'timeout'    => self::$timeout,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'headers'    => [
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'zh-CN,zh;q=0.9',
            ],
        ]);
        
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'status'  => 'error',
                'message' => __('网络请求失败', 'qilingshop'),
            ];
        }
        
        $body = wp_remote_retrieve_body($response);
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code === 404) {
            return [
                'success' => true,
                'status'  => 'invalid',
                'message' => __('链接已失效', 'qilingshop'),
            ];
        }
        
        // 夸克失效标识
        $invalid_patterns = [
            '分享的文件已失效',
            '链接不存在',
            '分享已取消',
            '分享已过期',
            'file-expired',
            'share-error',
        ];
        
        foreach ($invalid_patterns as $pattern) {
            if (strpos($body, $pattern) !== false) {
                return [
                    'success' => true,
                    'status'  => 'invalid',
                    'message' => __('链接已失效', 'qilingshop'),
                ];
            }
        }
        
        return [
            'success' => true,
            'status'  => 'valid',
            'message' => __('链接正常', 'qilingshop'),
        ];
    }
    
    /**
     * 检测天翼云盘
     * 
     * @param string $url  分享链接
     * @param string $code 提取码
     * @return array
     */
    private static function check_tianyi($url, $code = '') {
        $response = wp_remote_get($url, [
            'timeout'    => self::$timeout,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'headers'    => [
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'zh-CN,zh;q=0.9',
            ],
        ]);
        
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'status'  => 'error',
                'message' => __('网络请求失败', 'qilingshop'),
            ];
        }
        
        $body = wp_remote_retrieve_body($response);
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code === 404) {
            return [
                'success' => true,
                'status'  => 'invalid',
                'message' => __('链接已失效', 'qilingshop'),
            ];
        }
        
        // 天翼云盘失效标识
        $invalid_patterns = [
            '分享链接不存在',
            '文件已删除',
            '分享已取消',
            '分享已过期',
            '该分享不存在',
            'expired',
            'notFound',
        ];
        
        foreach ($invalid_patterns as $pattern) {
            if (strpos($body, $pattern) !== false) {
                return [
                    'success' => true,
                    'status'  => 'invalid',
                    'message' => __('链接已失效', 'qilingshop'),
                ];
            }
        }
        
        return [
            'success' => true,
            'status'  => 'valid',
            'message' => __('链接正常', 'qilingshop'),
        ];
    }
    
    /**
     * 检测123云盘
     * 
     * @param string $url  分享链接
     * @param string $code 提取码
     * @return array
     */
    private static function check_123pan($url, $code = '') {
        $response = wp_remote_get($url, [
            'timeout'    => self::$timeout,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'headers'    => [
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'zh-CN,zh;q=0.9',
            ],
        ]);
        
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'status'  => 'error',
                'message' => __('网络请求失败', 'qilingshop'),
            ];
        }
        
        $body = wp_remote_retrieve_body($response);
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code === 404) {
            return [
                'success' => true,
                'status'  => 'invalid',
                'message' => __('链接已失效', 'qilingshop'),
            ];
        }
        
        // 123云盘失效标识
        $invalid_patterns = [
            '分享已失效',
            '链接不存在',
            '分享的文件已被删除',
            '分享已过期',
            '分享的文件已失效',
            'share-error',
        ];
        
        foreach ($invalid_patterns as $pattern) {
            if (strpos($body, $pattern) !== false) {
                return [
                    'success' => true,
                    'status'  => 'invalid',
                    'message' => __('链接已失效', 'qilingshop'),
                ];
            }
        }
        
        return [
            'success' => true,
            'status'  => 'valid',
            'message' => __('链接正常', 'qilingshop'),
        ];
    }
}
