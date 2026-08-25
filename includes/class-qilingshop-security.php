<?php
/**
 * 安全工具类
 * 
 * 提供加密、解密、验证等安全相关功能
 *
 * @package QilingShop
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Security {
    const DOWNLOAD_TOKEN_FORMAT_AES2 = 'aes2';
    const DOWNLOAD_TOKEN_FORMAT_AES = 'aes';
    const DOWNLOAD_TOKEN_FORMAT_LEGACY = 'legacy';
    const DOWNLOAD_TOKEN_LEGACY_CUTOFF_OPTION = 'qilingshop_download_token_legacy_cutoff_at';

    /**
     * 单例实例
     *
     * @var QilingShop_Security
     */
    private static $instance = null;

    /**
     * 加密密钥
     *
     * @var string
     */
    private $secret_key;

    /**
     * 获取单例实例
     *
     * @return QilingShop_Security
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
        $this->secret_key = $this->get_secret_key();
    }

    /**
     * 获取或生成加密密钥
     *
     * @return string
     */
    private function get_secret_key() {
        $key = get_option('qilingshop_secret_key');
        
        if (empty($key)) {
            $key = wp_generate_password(64, true, true);
            update_option('qilingshop_secret_key', $key);
        }
        
        return $key;
    }

    /**
     * 加密字符串 (AES-256-CBC + HMAC-SHA256)
     *
     * @param string $data 要加密的数据
     * @param string $key  可选的自定义密钥
     * @return string
     */
    public function encrypt($data, $key = '') {
        if (empty($key)) {
            $key = $this->secret_key;
        }
        
        // 使用 AES-256-CBC 加密
        $method = 'AES-256-CBC';
        $key_hash = hash('sha256', $key, true);
        $iv = openssl_random_pseudo_bytes(16);
        
        $encrypted = openssl_encrypt($data, $method, $key_hash, OPENSSL_RAW_DATA, $iv);
        
        if ($encrypted === false) {
            // 如果 OpenSSL 失败，回退到旧方法
            return $this->encrypt_legacy($data, $key);
        }

        // 为密文添加完整性校验，避免被篡改后仍进入解密流程。
        $mac = hash_hmac('sha256', $iv . $encrypted, $key_hash, true);

        // 返回 IV + 密文 + MAC 的 base64 编码，前缀标识新格式
        return 'aes2:' . base64_encode($iv . $encrypted . $mac);
    }

    /**
     * 解密字符串 (AES-256-CBC，兼容旧格式)
     *
     * @param string $data 要解密的数据
     * @param string $key  可选的自定义密钥
     * @return string
     */
    public function decrypt($data, $key = '') {
        if (empty($key)) {
            $key = $this->secret_key;
        }
        
        // 检查是否为带完整性校验的新格式
        if (strpos($data, 'aes2:') === 0) {
            $data = substr($data, 5); // 移除 'aes2:' 前缀
            $raw = base64_decode($data, true);
            if ($raw === false || strlen($raw) < (16 + 32 + 1)) {
                return '';
            }

            $method = 'AES-256-CBC';
            $key_hash = hash('sha256', $key, true);
            $iv = substr($raw, 0, 16);
            $mac = substr($raw, -32);
            $encrypted = substr($raw, 16, -32);

            $expected_mac = hash_hmac('sha256', $iv . $encrypted, $key_hash, true);
            if (!hash_equals($expected_mac, $mac)) {
                return '';
            }

            $decrypted = openssl_decrypt($encrypted, $method, $key_hash, OPENSSL_RAW_DATA, $iv);
            return $decrypted !== false ? $decrypted : '';
        }

        // 兼容旧版 AES 格式（无 MAC）
        if (strpos($data, 'aes:') === 0) {
            $data = substr($data, 4); // 移除 'aes:' 前缀
            $raw = base64_decode($data, true);
            
            if ($raw === false || strlen($raw) < 16) {
                return '';
            }
            
            $method = 'AES-256-CBC';
            $key_hash = hash('sha256', $key, true);
            $iv = substr($raw, 0, 16);
            $encrypted = substr($raw, 16);
            
            $decrypted = openssl_decrypt($encrypted, $method, $key_hash, OPENSSL_RAW_DATA, $iv);
            
            return $decrypted !== false ? $decrypted : '';
        }
        
        // 旧格式：使用旧方法解密（向后兼容）
        return $this->decrypt_legacy($data, $key);
    }

    /**
     * 识别密文格式。
     *
     * @param string $data 密文。
     * @return string
     */
    private function get_encryption_format($data) {
        $data = (string) $data;
        if (strpos($data, 'aes2:') === 0) {
            return self::DOWNLOAD_TOKEN_FORMAT_AES2;
        }
        if (strpos($data, 'aes:') === 0) {
            return self::DOWNLOAD_TOKEN_FORMAT_AES;
        }
        return self::DOWNLOAD_TOKEN_FORMAT_LEGACY;
    }

    /**
     * 获取旧下载 token 的授权截止点。
     *
     * 首次运行新版代码时固定一个时间点：旧格式 token 只允许使用该时间点之前
     * 已经签发、且仍处于 24 小时有效期内的历史 token。通用 decrypt() 仍保留
     * 旧格式读取能力，避免影响历史数据迁移。
     *
     * @return int Unix 时间戳，0 表示不允许旧格式参与下载授权。
     */
    private function get_legacy_download_token_cutoff_at() {
        $cutoff = get_option(self::DOWNLOAD_TOKEN_LEGACY_CUTOFF_OPTION, null);
        if ($cutoff === null || $cutoff === false || $cutoff === '') {
            $cutoff = time();
            if (!add_option(self::DOWNLOAD_TOKEN_LEGACY_CUTOFF_OPTION, (string) $cutoff, '', 'no')) {
                $cutoff = get_option(self::DOWNLOAD_TOKEN_LEGACY_CUTOFF_OPTION, $cutoff);
            }
        }

        $cutoff = max(0, (int) $cutoff);
        return max(0, (int) apply_filters('qilingshop_download_token_legacy_cutoff_at', $cutoff));
    }

    /**
     * 下载 token 有效期。
     *
     * @return int
     */
    private function get_download_token_ttl() {
        return defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
    }

    /**
     * 旧格式下载 token 授权迁移窗口是否仍开启。
     *
     * @return bool
     */
    private function legacy_download_token_authorization_window_is_open() {
        $cutoff_at = $this->get_legacy_download_token_cutoff_at();
        if ($cutoff_at <= 0) {
            return false;
        }

        return time() <= ($cutoff_at + $this->get_download_token_ttl() + 300);
    }

    /**
     * 判断下载 token 是否还能用于授权。
     *
     * @param array  $data   解密后的下载 token 数据。
     * @param string $format token 加密格式。
     * @return bool
     */
    private function download_token_can_authorize($data, $format) {
        if ($format === self::DOWNLOAD_TOKEN_FORMAT_AES2) {
            return true;
        }

        $issued_at = isset($data['time']) ? (int) $data['time'] : 0;
        if ($issued_at <= 0) {
            return false;
        }

        $cutoff_at = $this->get_legacy_download_token_cutoff_at();
        if ($cutoff_at <= 0 || $issued_at > $cutoff_at) {
            return false;
        }

        $ttl = $this->get_download_token_ttl();
        $now = time();
        if ($issued_at > ($now + 300)) {
            return false;
        }

        return ($now - $issued_at) <= $ttl;
    }
    
    /**
     * 旧版加密方法 (XOR + MD5，仅用于向后兼容)
     *
     * @param string $data 要加密的数据
     * @param string $key  密钥
     * @return string
     */
    private function encrypt_legacy($data, $key) {
        $key = md5($key);
        $data = (string) $data;
        $x = 0;
        $len = strlen($data);
        $l = strlen($key);
        $char = '';
        $str = '';
        
        for ($i = 0; $i < $len; $i++) {
            if ($x == $l) {
                $x = 0;
            }
            $char .= $key[$x];
            $x++;
        }
        
        for ($i = 0; $i < $len; $i++) {
            $str .= chr(ord($data[$i]) + (ord($char[$i])) % 256);
        }
        
        return base64_encode($str);
    }
    
    /**
     * 旧版解密方法 (XOR + MD5，用于解密旧数据)
     *
     * @param string $data 要解密的数据
     * @param string $key  密钥
     * @return string
     */
    private function decrypt_legacy($data, $key) {
        $key = md5($key);
        $x = 0;
        $data = base64_decode($data);
        $len = strlen($data);
        $l = strlen($key);
        $char = '';
        $str = '';
        
        for ($i = 0; $i < $len; $i++) {
            if ($x == $l) {
                $x = 0;
            }
            $char .= substr($key, $x, 1);
            $x++;
        }
        
        for ($i = 0; $i < $len; $i++) {
            if (ord(substr($data, $i, 1)) < ord(substr($char, $i, 1))) {
                $str .= chr((ord(substr($data, $i, 1)) + 256) - ord(substr($char, $i, 1)));
            } else {
                $str .= chr(ord(substr($data, $i, 1)) - ord(substr($char, $i, 1)));
            }
        }
        
        return $str;
    }

    /**
     * 获取下载令牌密钥（缺失时自动补齐随机密钥）
     *
     * @return string
     */
    private function get_download_key() {
        $download_key = (string) get_option('qilingshop_download_key', '');
        if ($download_key === '') {
            $download_key = wp_generate_password(32, true, true);
            update_option('qilingshop_download_key', $download_key);
        }
        return $download_key;
    }

    /**
     * 加密下载 URL
     *
     * @param string $url     下载 URL
     * @param int    $post_id 文章 ID
     * @param int    $index   下载地址索引
     * @return string
     */
    public function encrypt_download_url($url, $post_id, $index = 0) {
        $download_key = $this->get_download_key();
        
        $data = json_encode([
            'url'     => $url,
            'post_id' => $post_id,
            'index'   => $index,
            'time'    => time(),
        ]);
        
        $token = $this->encrypt($data, $download_key);
        if ($this->get_encryption_format($token) !== self::DOWNLOAD_TOKEN_FORMAT_AES2) {
            return '';
        }

        return $token;
    }

    /**
     * 解密下载 URL
     *
     * @param string $encrypted         加密的字符串
     * @param bool   $for_authorization 是否用于下载/检测授权；历史数据读取可传 false。
     * @return array|false
     */
    public function decrypt_download_url($encrypted, $for_authorization = true) {
        $download_key = $this->get_download_key();
        $format = $this->get_encryption_format($encrypted);

        if ($for_authorization && $format !== self::DOWNLOAD_TOKEN_FORMAT_AES2 && !$this->legacy_download_token_authorization_window_is_open()) {
            return false;
        }
        
        $decrypted = $this->decrypt($encrypted, $download_key);
        $data = json_decode($decrypted, true);
        
        if (!$data || !isset($data['url']) || !isset($data['post_id'])) {
            return false;
        }

        if ($for_authorization && !$this->download_token_can_authorize($data, $format)) {
            return false;
        }
        
        return $data;
    }

    /**
     * 生成唯一订单号
     *
     * @param string $prefix 前缀
     * @return string
     */
    public function generate_order_no($prefix = '') {
        $prefix = $prefix ?: 'QLS';
        $time = date('YmdHis');
        $rand = mt_rand(1000, 9999);
        $unique = substr(md5(uniqid(mt_rand(), true)), 0, 6);
        
        return strtoupper($prefix . $time . $rand . $unique);
    }

    /**
     * 生成邀请码
     *
     * @param int $user_id 用户 ID
     * @return string
     */
    public function generate_invite_code($user_id) {
        $base = $user_id . time() . wp_generate_password(8, false);
        return strtoupper(substr(md5($base), 0, 8));
    }

    /**
     * 获取（并在必要时初始化）游客 cookie token。
     *
     * @return string
     */
    public function get_guest_cookie_token() {
        $cookie_token = isset($_COOKIE['qilingshop_guest_token'])
            ? sanitize_text_field((string) $_COOKIE['qilingshop_guest_token'])
            : '';

        if ($cookie_token === '') {
            $cookie_token = wp_generate_password(32, false);
            if (function_exists('qilingshop_set_cookie')) {
                qilingshop_set_cookie('qilingshop_guest_token', $cookie_token, time() + (86400 * 30), '/');
            } elseif (!headers_sent()) {
                setcookie('qilingshop_guest_token', $cookie_token, time() + (86400 * 30), '/', '', is_ssl(), true);
            }
            $_COOKIE['qilingshop_guest_token'] = $cookie_token;
        }

        return (string) $cookie_token;
    }

    /**
     * 生成游客标识（设备指纹：cookie token + UA）。
     *
     * 说明：不再将 IP 纳入标识，避免同 IP 用户互相放行。
     *
     * @return string
     */
    public function generate_guest_id() {
        $ua_hash = $this->get_user_agent_hash();
        $cookie_token = $this->get_guest_cookie_token();
        $payload = 'v2|' . $ua_hash . '|' . $cookie_token;
        return hash_hmac('sha256', $payload, wp_salt('auth'));
    }

    /**
     * 生成旧版游客标识（兼容历史订单）。
     *
     * @param string|null $ip
     * @return string
     */
    public function generate_legacy_guest_id($ip = null) {
        $ip = $ip === null ? $this->get_client_ip() : (string) $ip;
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        $cookie_token = $this->get_guest_cookie_token();
        return md5($ip . $user_agent . $cookie_token);
    }

    /**
     * 获取可用于兼容查询的游客标识集合。
     *
     * @return array
     */
    public function get_guest_id_candidates() {
        $candidates = [
            $this->generate_guest_id(),
            $this->generate_legacy_guest_id(),
        ];

        $candidates = array_map('strval', $candidates);
        $candidates = array_filter($candidates, function ($item) {
            return $item !== '';
        });

        return array_values(array_unique($candidates));
    }

    /**
     * 获取客户端 IP 地址
     *
     * @return string
     */
    public function get_client_ip() {
        $remote_addr = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
        $trust_mode = get_option('qilingshop_ip_trust_mode', 'auto_proxy');

        if (!$this->should_trust_forwarded_headers($trust_mode, $remote_addr)) {
            if (filter_var($remote_addr, FILTER_VALIDATE_IP)) {
                return $remote_addr;
            }
            return '0.0.0.0';
        }

        $candidates = [];
        $forwarded_headers = [
            'HTTP_TRUE_CLIENT_IP',
            'HTTP_CF_CONNECTING_IP',
            'HTTP_ALI_CDN_REAL_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_X_CLIENT_IP',
            'HTTP_FORWARDED',
            'HTTP_CLIENT_IP',
        ];

        foreach ($forwarded_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $candidates = array_merge($candidates, $this->parse_ip_candidates($_SERVER[$header]));
            }
        }

        if (filter_var($remote_addr, FILTER_VALIDATE_IP)) {
            $candidates[] = $remote_addr;
        }

        $candidates = array_values(array_unique(array_filter(array_map('trim', $candidates))));

        // 优先公网 IP，避免落到代理内网地址
        foreach ($candidates as $candidate) {
            if ($this->is_public_ip($candidate)) {
                return $candidate;
            }
        }

        foreach ($candidates as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }

        return '0.0.0.0';
    }

    /**
     * 判定当前请求是否应信任转发头。
     *
     * 支持模式:
     * - auto_proxy（默认）：配置了可信代理时仅信任代理请求；未配置时兼容 CDN 头。
     * - strict_proxy：仅信任来自可信代理网段的请求。
     * - cdn_compatible：始终信任常见 CDN/代理头（兼容历史站点）。
     * - remote_only：仅使用 REMOTE_ADDR。
     *
     * 兼容旧值:
     * - direct => remote_only
     * - cdn/proxy => cdn_compatible
     * - auto => auto_proxy
     *
     * @param string $mode 模式
     * @param string $remote_addr 来源 IP
     * @return bool
     */
    private function should_trust_forwarded_headers($mode, $remote_addr) {
        $mode = sanitize_key((string) $mode);
        if (!filter_var($remote_addr, FILTER_VALIDATE_IP)) {
            return false;
        }

        if ($mode === 'direct') {
            $mode = 'remote_only';
        } elseif ($mode === 'cdn' || $mode === 'proxy') {
            $mode = 'cdn_compatible';
        } elseif ($mode === 'auto') {
            $mode = 'auto_proxy';
        }

        if ($mode === 'remote_only') {
            return false;
        }

        if ($mode === 'strict_proxy') {
            return $this->is_trusted_proxy_ip($remote_addr);
        }

        if ($mode === 'cdn_compatible') {
            return true;
        }

        // auto_proxy：有可信代理配置则严格校验；否则兼容 CDN 场景
        $trusted = $this->get_trusted_proxy_list();
        if (!empty($trusted)) {
            return $this->is_trusted_proxy_ip($remote_addr);
        }

        return true;
    }

    /**
     * 获取可信代理网段列表。
     *
     * @return array
     */
    private function get_trusted_proxy_list() {
        $raw = get_option('qilingshop_trusted_proxy_list', []);
        if (is_string($raw)) {
            $raw = preg_split('/[\r\n,]+/', $raw);
        }

        if (!is_array($raw)) {
            $raw = [];
        }

        $trusted = array_map('trim', $raw);
        $trusted = array_filter($trusted, function ($item) {
            return $item !== '';
        });
        $trusted = array_values(array_unique($trusted));

        $trusted = apply_filters('qilingshop_trusted_proxies', $trusted);
        if (!is_array($trusted)) {
            return [];
        }

        $trusted = array_map('trim', $trusted);
        $trusted = array_filter($trusted, function ($item) {
            return $item !== '';
        });

        return array_values(array_unique($trusted));
    }

    /**
     * 判断来源 IP 是否在可信代理列表中。
     *
     * @param string $remote_addr 来源 IP
     * @return bool
     */
    private function is_trusted_proxy_ip($remote_addr) {
        if (!filter_var($remote_addr, FILTER_VALIDATE_IP)) {
            return false;
        }

        $trusted = $this->get_trusted_proxy_list();
        if (empty($trusted)) {
            return false;
        }

        foreach ($trusted as $range) {
            if ($this->ip_in_cidr($remote_addr, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 判断 IP 是否落在 CIDR/IP 范围内（支持 IPv4/IPv6）。
     *
     * @param string $ip IP
     * @param string $cidr IP 或 CIDR
     * @return bool
     */
    private function ip_in_cidr($ip, $cidr) {
        $ip = trim((string) $ip);
        $cidr = trim((string) $cidr);
        if ($ip === '' || $cidr === '') {
            return false;
        }

        if (strpos($cidr, '/') === false) {
            return $ip === $cidr;
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) {
            return false;
        }

        $subnet = trim($parts[0]);
        $bits = (int) $parts[1];

        $ip_bin = @inet_pton($ip);
        $subnet_bin = @inet_pton($subnet);
        if ($ip_bin === false || $subnet_bin === false || strlen($ip_bin) !== strlen($subnet_bin)) {
            return false;
        }

        $max_bits = strlen($ip_bin) * 8;
        if ($bits < 0 || $bits > $max_bits) {
            return false;
        }

        $bytes = (int) floor($bits / 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && substr($ip_bin, 0, $bytes) !== substr($subnet_bin, 0, $bytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = (~(0xff >> $remainder)) & 0xff;
        return ((ord($ip_bin[$bytes]) & $mask) === (ord($subnet_bin[$bytes]) & $mask));
    }

    /**
     * 解析转发头中的 IP 列表。
     *
     * @param string $raw_value 转发头原始值
     * @return array
     */
    private function parse_ip_candidates($raw_value) {
        $raw_value = trim((string) $raw_value);
        if ($raw_value === '') {
            return [];
        }

        $parts = preg_split('/[,;]/', $raw_value);
        if (!is_array($parts)) {
            $parts = [$raw_value];
        }

        $ips = [];
        foreach ($parts as $part) {
            $token = trim((string) $part);
            if ($token === '') {
                continue;
            }

            if (stripos($token, 'for=') === 0) {
                $token = trim(substr($token, 4));
            } elseif (strpos($token, '=') !== false) {
                // 跳过 proto= / by= / host= 等字段
                continue;
            }

            $token = trim($token, " \t\n\r\0\x0B\"'");
            if ($token === '' || strtolower($token) === 'unknown') {
                continue;
            }

            if (preg_match('/^\[([a-f0-9:.]+)\](?::\d+)?$/i', $token, $matches)) {
                $token = $matches[1];
            } elseif (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})(?::\d+)?$/', $token, $matches)) {
                $token = $matches[1];
            }

            if (filter_var($token, FILTER_VALIDATE_IP)) {
                $ips[] = $token;
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * 判断是否公网 IP。
     *
     * @param string $ip IP
     * @return bool
     */
    private function is_public_ip($ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /**
     * User-Agent Hash
     *
     * @return string
     */
    public function get_user_agent_hash() {
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        return md5($user_agent);
    }

    /**
     * 验证 Nonce
     *
     * @param string $nonce  Nonce 值
     * @param string $action Action 名称
     * @return bool
     */
    public function verify_nonce($nonce, $action = 'qilingshop_action') {
        return wp_verify_nonce($nonce, $action) !== false;
    }

    /**
     * 创建 Nonce
     *
     * @param string $action Action 名称
     * @return string
     */
    public function create_nonce($action = 'qilingshop_action') {
        return wp_create_nonce($action);
    }

    /**
     * 验证 AJAX 请求
     *
     * @param string $action Action 名称
     * @return bool
     */
    public function verify_ajax_request($action = 'qilingshop_ajax') {
        return check_ajax_referer($action, 'nonce', false) !== false;
    }

    /**
     * 清理输入数据
     *
     * @param mixed  $data 输入数据
     * @param string $type 数据类型
     * @return mixed
     */
    public function sanitize($data, $type = 'text') {
        switch ($type) {
            case 'email':
                return sanitize_email($data);
            case 'url':
                return esc_url_raw($data);
            case 'int':
                return intval($data);
            case 'float':
                return floatval($data);
            case 'key':
                return sanitize_key($data);
            case 'textarea':
                return sanitize_textarea_field($data);
            case 'html':
                return wp_kses_post($data);
            case 'filename':
                return sanitize_file_name($data);
            case 'text':
            default:
                return sanitize_text_field($data);
        }
    }

    /**
     * 转义输出
     *
     * @param string $data 要转义的数据
     * @param string $type 转义类型
     * @return string
     */
    public function escape($data, $type = 'html') {
        switch ($type) {
            case 'attr':
                return esc_attr($data);
            case 'url':
                return esc_url($data);
            case 'js':
                return esc_js($data);
            case 'textarea':
                return esc_textarea($data);
            case 'html':
            default:
                return esc_html($data);
        }
    }

    /**
     * 验证是否为有效的 URL
     *
     * @param string $url URL
     * @return bool
     */
    public function is_valid_url($url) {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * 验证是否为安全的内部链接
     *
     * @param string $url URL
     * @return bool
     */
    public function is_internal_url($url) {
        $site_url = parse_url(get_site_url());
        $check_url = parse_url($url);
        
        // 相对路径视为内部链接
        if (empty($check_url['host'])) {
            return true;
        }
        
        return $check_url['host'] === $site_url['host'];
    }

    /**
     * 频率限制检查
     *
     * @param string $key      限制键
     * @param int    $max      最大次数
     * @param int    $interval 时间间隔（秒）
     * @return bool 是否允许操作
     */
    public function rate_limit($key, $max = 10, $interval = 60) {
        $transient_key = 'qilingshop_rate_' . md5($key);
        $current = get_transient($transient_key);
        
        if ($current === false) {
            set_transient($transient_key, 1, $interval);
            return true;
        }
        
        if ($current >= $max) {
            return false;
        }
        
        set_transient($transient_key, $current + 1, $interval);
        return true;
    }

    /**
     * 验证支付回调签名（通用方法）
     *
     * @param array  $data   回调数据
     * @param string $sign   签名
     * @param string $key    密钥
     * @param string $method 签名方法
     * @return bool
     */
    public function verify_payment_sign($data, $sign, $key, $method = 'md5') {
        // 移除签名字段
        unset($data['sign'], $data['sign_type']);
        
        // 按键名排序
        ksort($data);
        
        // 构建签名字符串
        $sign_str = '';
        foreach ($data as $k => $v) {
            if ($v !== '' && $v !== null) {
                $sign_str .= "{$k}={$v}&";
            }
        }
        $sign_str .= "key={$key}";
        
        // 计算签名
        switch ($method) {
            case 'sha256':
                $calculated = hash('sha256', $sign_str);
                break;
            case 'md5':
            default:
                $calculated = md5($sign_str);
                break;
        }
        
        return strtoupper($calculated) === strtoupper($sign);
    }
}

/**
 * 获取安全实例的快捷函数
 *
 * @return QilingShop_Security
 */
function qilingshop_security() {
    return QilingShop_Security::instance();
}
