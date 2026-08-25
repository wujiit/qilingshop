<?php
/**
 * AJAX 处理
 */
if (!defined('ABSPATH')) exit;

class QilingShop_Ajax {
    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $auth_actions = [
            'checkin', 'buy_with_points', 'buy_vip', 'buy_vip_direct', 'recharge',
            'submit_withdraw', 'save_address', 'delete_address', 'pancheck', 'vip_redeem',
            'invite_list', 'commission_log',
        ];

        $public_actions = [
            'direct_pay', 'guest_recovery', 'check_order', 'get_download', 'order_lookup', 'trade_feed',
        ];

        foreach ($auth_actions as $action) {
            add_action('wp_ajax_qilingshop_' . $action, [$this, 'handle_' . $action]);
        }

        foreach ($public_actions as $action) {
            add_action('wp_ajax_qilingshop_' . $action, [$this, 'handle_' . $action]);
            add_action('wp_ajax_nopriv_qilingshop_' . $action, [$this, 'handle_' . $action]);
        }
    }

    private function verify_nonce() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'qilingshop_ajax')) {
            qilingshop_json_error(__('安全验证失败', 'qilingshop'));
        }
    }

    private function verify_account_nonce() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'qilingshop_account')) {
            qilingshop_json_error(__('安全验证失败', 'qilingshop'));
        }
    }

    private function require_login() {
        if (!is_user_logged_in()) {
            qilingshop_json_error(__('请先登录', 'qilingshop'));
        }
    }

    /**
     * 获取商城数据库访问器。
     *
     * @return QLS_Shop_Database|null
     */
    private function get_shop_db() {
        if (function_exists('qls_shop_db')) {
            return qls_shop_db();
        }

        if (class_exists('QLS_Shop_Database')) {
            return QLS_Shop_Database::instance();
        }

        return null;
    }

    /**
     * 获取下单限流主体标识（用户优先，其次游客，其次 IP）
     *
     * @return string
     */
    private function get_order_rate_actor_key() {
        $user_id = (int) get_current_user_id();
        if ($user_id > 0) {
            return 'u' . $user_id;
        }

        if (class_exists('QilingShop_Guest')) {
            $guest_id = (string) QilingShop_Guest::instance()->get_guest_id();
            if ($guest_id !== '') {
                return 'g' . substr(md5($guest_id), 0, 16);
            }
        }

        $ip = '';
        if (function_exists('qilingshop_security')) {
            $ip = (string) qilingshop_security()->get_client_ip();
        }
        if ($ip === '' && isset($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field((string) wp_unslash($_SERVER['REMOTE_ADDR']));
        }

        return 'ip' . substr(md5($ip !== '' ? $ip : '0.0.0.0'), 0, 16);
    }

    /**
     * 获取数据库级命名锁。
     *
     * @param string $lock_name
     * @param int    $timeout
     * @return bool
     */
    private function acquire_named_lock($lock_name, $timeout = 5) {
        global $wpdb;

        $lock_name = sanitize_key((string) $lock_name);
        if ($lock_name === '') {
            return false;
        }

        $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, (int) $timeout));
        return (int) $result === 1;
    }

    /**
     * 释放数据库级命名锁。
     *
     * @param string $lock_name
     * @return void
     */
    private function release_named_lock($lock_name) {
        global $wpdb;

        $lock_name = sanitize_key((string) $lock_name);
        if ($lock_name === '') {
            return;
        }

        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
    }

    /**
     * 构造用户级写操作互斥锁名。
     *
     * @param string $scope
     * @param int    $user_id
     * @return string
     */
    private function build_user_write_lock_name($scope, $user_id) {
        return sanitize_key((string) $scope) . '_' . md5((string) absint($user_id));
    }

    private function build_user_address_lock_name($user_id) {
        return $this->build_user_write_lock_name('qlsaddr', $user_id);
    }

    /**
     * 下单写接口限流
     *
     * @param string $scope    作用域
     * @param array  $context  附加上下文
     * @param int    $max      时间窗内最大次数
     * @param int    $interval 时间窗（秒）
     * @return void
     */
    private function guard_order_write_request($scope, $context = [], $max = 6, $interval = 60) {
        if (!function_exists('qilingshop_security')) {
            return;
        }

        $scope = sanitize_key((string) $scope);
        if ($scope === '') {
            $scope = 'default';
        }

        $normalized_context = [];
        if (is_array($context)) {
            foreach ($context as $key => $value) {
                $context_key = sanitize_key((string) $key);
                if ($context_key === '') {
                    continue;
                }
                if (is_array($value) || is_object($value)) {
                    continue;
                }
                $normalized_context[$context_key] = (string) $value;
            }
        }
        ksort($normalized_context);
        $context_hash = md5(wp_json_encode($normalized_context));

        $actor_key = $this->get_order_rate_actor_key();
        $max = (int) apply_filters('qilingshop_order_write_rate_limit_max', (int) $max, $scope, $normalized_context, $actor_key);
        $interval = (int) apply_filters('qilingshop_order_write_rate_limit_interval', (int) $interval, $scope, $normalized_context, $actor_key);

        if ($max <= 0 || $interval <= 0) {
            return;
        }

        $rate_key = 'qilingshop_order_write:' . $scope . ':' . $actor_key . ':' . $context_hash;
        $allowed = qilingshop_security()->rate_limit($rate_key, $max, $interval);
        if (!$allowed) {
            wp_send_json([
                'success' => false,
                'message' => __('请求过于频繁，请稍后重试', 'qilingshop'),
                'data'    => null,
            ], 429);
        }
    }

    /**
     * 为待支付订单预占优惠券。
     *
     * @param int    $coupon_claim_id
     * @param string $order_no
     * @param string $order_type
     * @param float  $order_amount
     * @param float  $discount_amount
     * @return bool
     */
    private function reserve_coupon_for_order($coupon_claim_id, $order_no, $order_type, $order_amount, $discount_amount) {
        $coupon_claim_id = absint($coupon_claim_id);
        if ($coupon_claim_id <= 0 || (float) $discount_amount <= 0 || !class_exists('QLS_Coupon')) {
            return true;
        }

        return QLS_Coupon::reserve_for_order(
            $coupon_claim_id,
            $order_no,
            $order_type,
            $order_amount,
            $discount_amount
        );
    }

    /**
     * 释放未成功创建订单时产生的优惠券预占。
     *
     * @param int    $coupon_claim_id
     * @param string $order_no
     * @return void
     */
    private function release_coupon_reservation($coupon_claim_id, $order_no = '') {
        $coupon_claim_id = absint($coupon_claim_id);
        if ($coupon_claim_id > 0 && class_exists('QLS_Coupon')) {
            QLS_Coupon::release_reservation($coupon_claim_id, $order_no);
        }
    }

    /**
     * 订单查询接口限流（防撞库）
     *
     * @param string $order_no
     * @param string $contact_value
     * @return void
     */
    private function guard_order_lookup_request($order_no, $contact_value) {
        if (!function_exists('qilingshop_security')) {
            return;
        }

        $actor_key = $this->get_order_rate_actor_key();
        $order_no = strtoupper((string) $order_no);
        $contact_value = strtolower((string) $contact_value);

        $global_max = (int) apply_filters('qilingshop_order_lookup_rate_limit_max', 20, $actor_key);
        $global_interval = (int) apply_filters('qilingshop_order_lookup_rate_limit_interval', 300, $actor_key);
        if ($global_max > 0 && $global_interval > 0) {
            $global_key = 'qilingshop_order_lookup:global:' . $actor_key;
            if (!qilingshop_security()->rate_limit($global_key, $global_max, $global_interval)) {
                wp_send_json([
                    'success' => false,
                    'message' => __('查询过于频繁，请稍后重试', 'qilingshop'),
                    'data'    => null,
                ], 429);
            }
        }

        $pair_max = (int) apply_filters('qilingshop_order_lookup_pair_rate_limit_max', 8, $actor_key, $order_no);
        $pair_interval = (int) apply_filters('qilingshop_order_lookup_pair_rate_limit_interval', 300, $actor_key, $order_no);
        if ($pair_max > 0 && $pair_interval > 0) {
            $pair_hash = md5($order_no . '|' . $contact_value);
            $pair_key = 'qilingshop_order_lookup:pair:' . $actor_key . ':' . $pair_hash;
            if (!qilingshop_security()->rate_limit($pair_key, $pair_max, $pair_interval)) {
                wp_send_json([
                    'success' => false,
                    'message' => __('查询过于频繁，请稍后重试', 'qilingshop'),
                    'data'    => null,
                ], 429);
            }
        }
    }

    /**
     * VIP 兑换码接口限流（防撞库/爆破）。
     *
     * @param string $code
     * @return void
     */
    private function guard_vip_redeem_request($code) {
        if (!function_exists('qilingshop_security')) {
            return;
        }

        $actor_key = $this->get_order_rate_actor_key();
        $normalized_code = strtoupper((string) $code);
        $normalized_code = preg_replace('/[^A-Z0-9]/', '', $normalized_code);
        $normalized_code = sanitize_text_field((string) $normalized_code);
        $code_hash = substr(hash('sha256', $normalized_code), 0, 24);

        $ip = (string) qilingshop_security()->get_client_ip();
        $ip_hash = substr(hash('sha256', $ip !== '' ? $ip : '0.0.0.0'), 0, 24);

        $limits = [
            [
                'key'      => 'qilingshop_vip_redeem:user:' . $actor_key,
                'max'      => 10,
                'interval' => 10 * MINUTE_IN_SECONDS,
            ],
            [
                'key'      => 'qilingshop_vip_redeem:code:' . $actor_key . ':' . $code_hash,
                'max'      => 5,
                'interval' => 15 * MINUTE_IN_SECONDS,
            ],
            [
                'key'      => 'qilingshop_vip_redeem:ip:' . $ip_hash,
                'max'      => 30,
                'interval' => 10 * MINUTE_IN_SECONDS,
            ],
        ];

        foreach ($limits as $limit) {
            $max = (int) apply_filters('qilingshop_vip_redeem_rate_limit_max', $limit['max'], $limit['key'], $actor_key, $code_hash);
            $interval = (int) apply_filters('qilingshop_vip_redeem_rate_limit_interval', $limit['interval'], $limit['key'], $actor_key, $code_hash);
            if ($max <= 0 || $interval <= 0) {
                continue;
            }

            if (!qilingshop_security()->rate_limit($limit['key'], $max, $interval)) {
                wp_send_json([
                    'success' => false,
                    'message' => __('兑换尝试过于频繁，请稍后重试', 'qilingshop'),
                    'data'    => null,
                ], 429);
            }
        }
    }

    /**
     * 规范化手机号（仅保留数字，兼容 +86/0086）
     *
     * @param string $value
     * @return string
     */
    private function normalize_lookup_phone($value) {
        $value = (string) $value;
        $digits = preg_replace('/\D+/', '', $value);
        if ($digits === null) {
            $digits = '';
        }
        $digits = (string) $digits;
        if ($digits === '') {
            return '';
        }

        if (strpos($digits, '0086') === 0 && strlen($digits) > 4) {
            $digits = substr($digits, 4);
        } elseif (strpos($digits, '86') === 0 && strlen($digits) > 11) {
            $digits = substr($digits, 2);
        }

        return $digits;
    }

    /**
     * 规范化邮箱
     *
     * @param string $value
     * @return string
     */
    private function normalize_lookup_email($value) {
        $email = sanitize_email((string) $value);
        if ($email === '' || !is_email($email)) {
            return '';
        }
        return strtolower($email);
    }

    /**
     * 解析订单 contact_info 字段
     *
     * @param string $contact_info
     * @return array{phone:string,email:string}
     */
    private function parse_order_contact_info($contact_info) {
        $result = ['phone' => '', 'email' => ''];
        if (!is_string($contact_info) || $contact_info === '') {
            return $result;
        }

        $decoded = json_decode($contact_info, true);
        if (is_array($decoded)) {
            $phone_raw = '';
            foreach (['phone', 'mobile', 'contact_phone'] as $key) {
                if (!empty($decoded[$key])) {
                    $phone_raw = (string) $decoded[$key];
                    break;
                }
            }

            $email_raw = '';
            foreach (['email', 'contact_email'] as $key) {
                if (!empty($decoded[$key])) {
                    $email_raw = (string) $decoded[$key];
                    break;
                }
            }

            $result['phone'] = $this->normalize_lookup_phone($phone_raw);
            $result['email'] = $this->normalize_lookup_email($email_raw);
            return $result;
        }

        $as_email = $this->normalize_lookup_email($contact_info);
        if ($as_email !== '') {
            $result['email'] = $as_email;
            return $result;
        }

        $result['phone'] = $this->normalize_lookup_phone($contact_info);
        return $result;
    }

    /**
     * 从文本中提取邮箱
     *
     * @param string $text
     * @return string
     */
    private function extract_email_from_text($text) {
        $text = (string) $text;
        if ($text === '') {
            return '';
        }

        if (preg_match('/([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/i', $text, $matches)) {
            return $this->normalize_lookup_email($matches[1]);
        }

        return '';
    }

    /**
     * 聚合资源订单可用于验证的联系方式
     *
     * @param string $order_no
     * @param object $order
     * @return array{phones:array,emails:array}
     */
    private function gather_resource_order_contacts($order_no, $order) {
        $contacts = ['phones' => [], 'emails' => []];

        if (isset($order->contact_info)) {
            $parsed = $this->parse_order_contact_info((string) $order->contact_info);
            if ($parsed['phone'] !== '') {
                $contacts['phones'][] = $parsed['phone'];
            }
            if ($parsed['email'] !== '') {
                $contacts['emails'][] = $parsed['email'];
            }
        }

        if (class_exists('QilingShop_Database')) {
            $guest_order = QilingShop_Database::instance()->get_row('guest_orders', [
                'order_no' => $order_no,
            ]);
            if ($guest_order) {
                $phone = $this->normalize_lookup_phone((string) ($guest_order->contact_phone ?? ''));
                $email = $this->normalize_lookup_email((string) ($guest_order->contact_email ?? ''));
                if ($phone !== '') {
                    $contacts['phones'][] = $phone;
                }
                if ($email !== '') {
                    $contacts['emails'][] = $email;
                }
            }
        }

        $contacts['phones'] = array_values(array_unique(array_filter(array_map('strval', $contacts['phones']))));
        $contacts['emails'] = array_values(array_unique(array_filter(array_map('strval', $contacts['emails']))));

        return $contacts;
    }

    /**
     * 聚合商城订单可用于验证的联系方式
     *
     * @param object $order
     * @return array{phones:array,emails:array}
     */
    private function gather_shop_order_contacts($order) {
        $contacts = ['phones' => [], 'emails' => []];

        $indexed_phone = $this->normalize_lookup_phone((string) ($order->guest_query_phone ?? ''));
        if ($indexed_phone !== '') {
            $contacts['phones'][] = $indexed_phone;
        }

        $indexed_email = $this->normalize_lookup_email((string) ($order->guest_query_email ?? ''));
        if ($indexed_email !== '') {
            $contacts['emails'][] = $indexed_email;
        }

        $phone = $this->normalize_lookup_phone((string) ($order->receiver_phone ?? ''));
        if ($phone !== '') {
            $contacts['phones'][] = $phone;
        }

        $address = isset($order->receiver_address) ? (string) $order->receiver_address : '';
        $email = $this->extract_email_from_text($address);
        if ($email !== '') {
            $contacts['emails'][] = $email;
        }

        $seller_remark = $this->parse_shop_order_seller_remark($order);
        if (!empty($seller_remark['contact_email'])) {
            $email = $this->normalize_lookup_email((string) $seller_remark['contact_email']);
            if ($email !== '') {
                $contacts['emails'][] = $email;
            }
        }

        $contacts['phones'] = array_values(array_unique(array_filter(array_map('strval', $contacts['phones']))));
        $contacts['emails'] = array_values(array_unique(array_filter(array_map('strval', $contacts['emails']))));

        return $contacts;
    }

    /**
     * 获取虚拟内容类型文本
     *
     * @param string $type
     * @return string
     */
    private function get_virtual_item_type_label($type) {
        $map = [
            'download' => __('下载链接', 'qilingshop'),
            'card'     => __('卡密内容', 'qilingshop'),
            'custom'   => __('图文内容', 'qilingshop'),
            'pending'  => __('待发放', 'qilingshop'),
        ];

        $type = sanitize_key((string) $type);
        return $map[$type] ?? __('虚拟内容', 'qilingshop');
    }

    /**
     * 将卡密数组格式化为可展示文本行
     *
     * @param array $cards
     * @return array
     */
    private function build_virtual_card_lines($cards) {
        $lines = [];
        if (!is_array($cards)) {
            return $lines;
        }

        foreach ($cards as $card) {
            if (is_object($card)) {
                $card = (array) $card;
            }
            if (!is_array($card)) {
                continue;
            }

            $card_no = sanitize_text_field((string) ($card['card_no'] ?? ''));
            $card_secret = sanitize_text_field((string) ($card['card_secret'] ?? ''));
            if ($card_no === '' && $card_secret === '') {
                continue;
            }

            $lines[] = $card_secret !== '' ? ($card_no . '----' . $card_secret) : $card_no;
        }

        return $lines;
    }

    /**
     * 获取订单项已保存的虚拟内容快照。
     *
     * @param object $item
     * @return array
     */
    private function get_shop_order_item_virtual_content($item) {
        if (!is_object($item) || !isset($item->virtual_content)) {
            return [];
        }

        $virtual_content = $item->virtual_content;
        if (is_array($virtual_content)) {
            return $virtual_content;
        }

        if (is_object($virtual_content)) {
            return (array) $virtual_content;
        }

        if (is_string($virtual_content) && trim($virtual_content) !== '') {
            $decoded = json_decode($virtual_content, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * 组装商城订单中的虚拟商品内容（用于游客订单查询）
     *
     * @param object $order
     * @return array
     */
    private function build_shop_virtual_items($order) {
        $allowed_statuses = class_exists('QLS_Shop_Order')
            ? [QLS_Shop_Order::STATUS_PAID, QLS_Shop_Order::STATUS_SHIPPED, QLS_Shop_Order::STATUS_COMPLETED]
            : [1, 2, 3];
        if (!is_object($order) || !in_array((int) ($order->status ?? -1), $allowed_statuses, true)) {
            return [];
        }

        $items = isset($order->items) && is_array($order->items) ? $order->items : [];
        $virtual_items = [];

        foreach ($items as $item) {
            if (!is_object($item)) {
                continue;
            }

            $virtual_content = $this->get_shop_order_item_virtual_content($item);
            $virtual_type = sanitize_key((string) ($virtual_content['type'] ?? ''));

            if ($virtual_type === '' && !empty($item->product_id) && function_exists('qls_product') && qls_product()->is_virtual((int) $item->product_id)) {
                $virtual_type = 'pending';
            }

            if ($virtual_type === '') {
                continue;
            }

            $entry = [
                'title'      => sanitize_text_field((string) ($item->product_title ?? __('虚拟商品', 'qilingshop'))),
                'type'       => $virtual_type,
                'type_label' => $this->get_virtual_item_type_label($virtual_type),
                'lines'      => [],
            ];

            if ($virtual_type === 'download') {
                $download_url = esc_url_raw((string) ($virtual_content['download_url'] ?? ''));
                $download_code = sanitize_text_field((string) ($virtual_content['download_code'] ?? ''));
                $entry['lines'][] = sprintf(
                    __('下载链接：%s', 'qilingshop'),
                    $download_url !== '' ? $download_url : __('未配置', 'qilingshop')
                );
                $entry['lines'][] = sprintf(
                    __('提取码：%s', 'qilingshop'),
                    $download_code !== '' ? $download_code : '-'
                );
            } elseif ($virtual_type === 'card') {
                $card_lines = $this->build_virtual_card_lines($virtual_content['cards'] ?? []);
                if (!empty($card_lines)) {
                    $entry['lines'] = $card_lines;
                } else {
                    $entry['lines'][] = sanitize_text_field((string) ($virtual_content['error'] ?? __('暂无卡密信息', 'qilingshop')));
                }
            } elseif ($virtual_type === 'custom') {
                $content = trim(wp_strip_all_tags((string) ($virtual_content['content'] ?? '')));
                $entry['lines'][] = $content !== '' ? $content : __('暂无图文内容', 'qilingshop');
            } elseif ($virtual_type === 'pending') {
                $entry['lines'][] = __('虚拟内容发放中，请稍后刷新查询。', 'qilingshop');
            } else {
                $entry['lines'][] = wp_json_encode($virtual_content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $virtual_items[] = $entry;
        }

        return $virtual_items;
    }

    /**
     * 校验输入联系方式是否命中订单记录
     *
     * @param string $contact_type phone|email
     * @param string $contact_value
     * @param array  $contacts
     * @return bool
     */
    private function lookup_contact_matches($contact_type, $contact_value, $contacts) {
        $contact_type = $contact_type === 'email' ? 'email' : 'phone';
        $contact_value = (string) $contact_value;

        if ($contact_type === 'email') {
            $emails = isset($contacts['emails']) && is_array($contacts['emails']) ? $contacts['emails'] : [];
            return in_array($contact_value, array_map('strtolower', array_map('strval', $emails)), true);
        }

        $phones = isset($contacts['phones']) && is_array($contacts['phones']) ? $contacts['phones'] : [];
        return in_array($contact_value, array_map('strval', $phones), true);
    }

    /**
     * 解析商城订单的 seller_remark JSON。
     *
     * @param object $order
     * @return array
     */
    private function parse_shop_order_seller_remark($order) {
        $remark = isset($order->seller_remark) ? (string) $order->seller_remark : '';
        if ($remark === '') {
            return [];
        }

        $decoded = json_decode($remark, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 判断商城订单是否包含卡密类虚拟商品。
     *
     * @param object $order
     * @return bool
     */
    private function shop_order_contains_card_item($order) {
        $items = isset($order->items) && is_array($order->items) ? $order->items : [];
        foreach ($items as $item) {
            if (!is_object($item)) {
                continue;
            }

            $virtual_content = $this->get_shop_order_item_virtual_content($item);
            $stored_type = sanitize_key((string) ($virtual_content['type'] ?? ''));
            if ($stored_type === 'card') {
                return true;
            }

            if ($stored_type === '' && !empty($virtual_content['cards']) && is_array($virtual_content['cards'])) {
                return true;
            }

            // 有历史虚拟内容快照时，以快照为准，避免商品后来改类型影响旧订单。
            if ($stored_type !== '' || !empty($virtual_content) || !empty($item->has_virtual_content)) {
                continue;
            }

            if (empty($item->product_id) || !function_exists('qls_product')) {
                continue;
            }

            $product = qls_product()->get((int) $item->product_id);
            if ($product && qls_product()->is_virtual($product) && sanitize_key((string) ($product->virtual_type ?? '')) === 'card') {
                return true;
            }
        }

        return false;
    }

    /**
     * 校验商城游客订单查询密码。
     *
     * @param object $order
     * @param string $query_password
     * @return bool
     */
    private function shop_order_query_password_matches($order, $query_password) {
        if (!function_exists('wp_check_password')) {
            return false;
        }

        $query_password = trim((string) $query_password);
        if ($query_password === '') {
            return false;
        }

        $hash = isset($order->guest_query_password_hash) ? (string) $order->guest_query_password_hash : '';
        if ($hash === '') {
            $remark = $this->parse_shop_order_seller_remark($order);
            $hash = isset($remark['guest_query_password_hash']) ? (string) $remark['guest_query_password_hash'] : '';
        }
        if ($hash === '') {
            return false;
        }

        if (!$this->shop_order_guest_query_password_is_active($order)) {
            return false;
        }

        return wp_check_password($query_password, $hash);
    }

    /**
     * 判断商城订单表是否已具备游客查询索引字段。
     *
     * @param object|null $db
     * @return bool
     */
    private function shop_order_guest_query_lookup_columns_exist($db = null) {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }

        if (!$db) {
            $db = $this->get_shop_db();
        }
        if (!$db || !method_exists($db, 'get_wpdb') || !method_exists($db, 'get_table')) {
            $exists = false;
            return $exists;
        }

        $wpdb = $db->get_wpdb();
        $table = $db->get_table('orders');
        if (!$wpdb || $table === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            $exists = false;
            return $exists;
        }

        foreach (['guest_query_phone', 'guest_query_email', 'guest_query_password_hash', 'guest_query_password_expires_at'] as $column) {
            $found = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", $column));
            if (empty($found)) {
                $exists = false;
                return $exists;
            }
        }

        $exists = true;
        return $exists;
    }

    /**
     * 获取游客查询密码有效天数。
     *
     * @return int
     */
    private function get_guest_query_password_expire_days() {
        return max(1, min(365, (int) get_option('qls_shop_guest_query_password_expire_days', 30)));
    }

    /**
     * 根据订单时间计算查询密码兜底失效时间。
     *
     * @param object $order
     * @return string
     */
    private function resolve_shop_order_guest_query_password_expires_at($order) {
        $expires_at = trim((string) ($order->guest_query_password_expires_at ?? ''));
        if ($expires_at !== '' && $expires_at !== '0000-00-00 00:00:00') {
            return $expires_at;
        }

        $created_at = trim((string) ($order->created_at ?? ''));
        $created_timestamp = $created_at !== '' ? strtotime($created_at) : 0;
        if ($created_timestamp <= 0) {
            return '';
        }

        $seconds_per_day = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
        return date('Y-m-d H:i:s', $created_timestamp + ($this->get_guest_query_password_expire_days() * $seconds_per_day));
    }

    /**
     * 判断游客查询密码是否仍在有效期内。
     *
     * @param object $order
     * @return bool
     */
    private function shop_order_guest_query_password_is_active($order) {
        $expires_at = $this->resolve_shop_order_guest_query_password_expires_at($order);
        if ($expires_at === '') {
            return true;
        }

        return $expires_at >= current_time('mysql');
    }

    /**
     * 获取旧 seller_remark 查询的时间下限，避免扫描已过期历史订单。
     *
     * @return string
     */
    private function get_guest_query_password_legacy_cutoff_at() {
        $seconds_per_day = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
        $timestamp = current_time('timestamp') - ($this->get_guest_query_password_expire_days() * $seconds_per_day);
        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * 定期清理已失效的游客查询密码，避免 hash 长期留存。
     *
     * @param object $db
     * @return void
     */
    private function maybe_cleanup_expired_shop_guest_query_passwords($db) {
        $last_cleanup = (int) get_option('qls_shop_guest_query_password_cleanup_last', 0);
        $seconds_per_day = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
        if ($last_cleanup > 0 && (time() - $last_cleanup) < $seconds_per_day) {
            return;
        }

        update_option('qls_shop_guest_query_password_cleanup_last', time(), false);

        if (!$db || !method_exists($db, 'get_wpdb') || !method_exists($db, 'get_table')) {
            return;
        }

        $wpdb = $db->get_wpdb();
        $table = $db->get_table('orders');
        if (!$wpdb || $table === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return;
        }

        if ($this->shop_order_guest_query_lookup_columns_exist($db)) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE `{$table}` SET guest_query_phone = '', guest_query_email = '', guest_query_password_hash = '', guest_query_password_expires_at = NULL WHERE guest_query_password_hash <> '' AND guest_query_password_expires_at IS NOT NULL AND guest_query_password_expires_at < %s LIMIT 200",
                    current_time('mysql')
                )
            );
        }

        $legacy_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, seller_remark FROM `{$table}` WHERE created_at < %s AND seller_remark LIKE %s ORDER BY id ASC LIMIT 100",
                $this->get_guest_query_password_legacy_cutoff_at(),
                '%' . $wpdb->esc_like('guest_query_password_hash') . '%'
            )
        );

        if (empty($legacy_rows)) {
            return;
        }

        foreach ($legacy_rows as $row) {
            $remark = json_decode((string) ($row->seller_remark ?? ''), true);
            if (!is_array($remark) || !array_key_exists('guest_query_password_hash', $remark)) {
                continue;
            }

            unset($remark['guest_query_password_hash'], $remark['guest_query_password_set']);
            $db->update(
                'orders',
                ['seller_remark' => wp_json_encode($remark)],
                ['id' => (int) $row->id],
                ['%s'],
                ['%d']
            );
        }
    }

    /**
     * 按候选 ID 批量读取商城订单并保留可按 ID 访问的映射。
     *
     * @param array $order_ids
     * @return array<int, object>
     */
    private function get_shop_orders_by_candidate_ids($order_ids) {
        $order_ids = array_values(array_unique(array_filter(array_map('intval', (array) $order_ids))));
        if (empty($order_ids) || !function_exists('qls_shop_order')) {
            return [];
        }

        $order_manager = qls_shop_order();
        if (method_exists($order_manager, 'get_by_ids')) {
            return $order_manager->get_by_ids($order_ids, true);
        }

        $orders = [];
        foreach ($order_ids as $order_id) {
            $order = method_exists($order_manager, 'get') ? $order_manager->get($order_id, true) : null;
            if ($order && !empty($order->id)) {
                $orders[(int) $order->id] = $order;
            }
        }

        return $orders;
    }

    /**
     * 老订单命中查询后，回填游客查询专用字段，后续查询可直接走索引。
     *
     * @param object $order
     * @param array  $contacts
     * @return void
     */
    private function maybe_update_shop_order_guest_query_lookup($order, $contacts) {
        if (!is_object($order) || empty($order->id)) {
            return;
        }

        $db = $this->get_shop_db();
        if (!$db || !$this->shop_order_guest_query_lookup_columns_exist($db)) {
            return;
        }

        $remark = null;
        $hash = isset($order->guest_query_password_hash) ? (string) $order->guest_query_password_hash : '';
        if ($hash === '') {
            $remark = $this->parse_shop_order_seller_remark($order);
            $hash = isset($remark['guest_query_password_hash']) ? (string) $remark['guest_query_password_hash'] : '';
        }
        if ($hash === '') {
            return;
        }

        $phones = isset($contacts['phones']) && is_array($contacts['phones']) ? array_values($contacts['phones']) : [];
        $emails = isset($contacts['emails']) && is_array($contacts['emails']) ? array_values($contacts['emails']) : [];
        $phone = isset($phones[0]) ? $this->normalize_lookup_phone((string) $phones[0]) : '';
        $email = isset($emails[0]) ? $this->normalize_lookup_email((string) $emails[0]) : '';
        $expires_at = $this->resolve_shop_order_guest_query_password_expires_at($order);

        $data = [
            'guest_query_phone' => $phone,
            'guest_query_email' => $email,
            'guest_query_password_hash' => $hash,
            'guest_query_password_expires_at' => $expires_at !== '' ? $expires_at : null,
        ];
        $formats = ['%s', '%s', '%s', '%s'];

        if ($remark === null) {
            $remark = $this->parse_shop_order_seller_remark($order);
        }
        if (is_array($remark) && (array_key_exists('guest_query_password_hash', $remark) || array_key_exists('guest_query_password_set', $remark))) {
            unset($remark['guest_query_password_hash'], $remark['guest_query_password_set']);
            $data['seller_remark'] = wp_json_encode($remark);
            $formats[] = '%s';
        }

        if (count($data) === 4
            && $phone === (string) ($order->guest_query_phone ?? '')
            && $email === (string) ($order->guest_query_email ?? '')
            && $hash === (string) ($order->guest_query_password_hash ?? '')
            && $expires_at === (string) ($order->guest_query_password_expires_at ?? '')
        ) {
            return;
        }

        $db->update(
            'orders',
            $data,
            ['id' => (int) $order->id],
            $formats,
            ['%d']
        );
    }

    /**
     * 使用联系方式 + 查询密码查找商城发卡游客订单。
     *
     * @param string $contact_type phone|email
     * @param string $contact_value
     * @param string $query_password
     * @return array
     */
    private function find_shop_card_orders_by_contact_password($contact_type, $contact_value, $query_password) {
        if (!(bool) get_option('qls_shop_guest_query_password_enabled', false) || !function_exists('qls_shop_order')) {
            return [];
        }

        $query_password = trim((string) $query_password);
        $password_length = function_exists('mb_strlen') ? mb_strlen($query_password) : strlen($query_password);
        if ($password_length < 4 || $password_length > 64) {
            return [];
        }

        $db = $this->get_shop_db();
        if (!$db || !method_exists($db, 'get_wpdb') || !method_exists($db, 'get_table')) {
            return [];
        }

        $wpdb = $db->get_wpdb();
        $table = $db->get_table('orders');
        if (!$wpdb || $table === '') {
            return [];
        }

        $this->maybe_cleanup_expired_shop_guest_query_passwords($db);

        $matches = [];
        $matched_ids = [];

        if ($this->shop_order_guest_query_lookup_columns_exist($db)) {
            $contact_column = $contact_type === 'email' ? 'guest_query_email' : 'guest_query_phone';
            $sql = $wpdb->prepare(
                "SELECT id FROM `{$table}` WHERE user_id = 0 AND `{$contact_column}` = %s AND guest_query_password_hash <> '' AND (guest_query_password_expires_at IS NULL OR guest_query_password_expires_at >= %s) ORDER BY id DESC LIMIT 50",
                (string) $contact_value,
                current_time('mysql')
            );
            $order_ids = array_values(array_filter(array_map('intval', (array) $wpdb->get_col($sql))));
            $orders = $this->get_shop_orders_by_candidate_ids($order_ids);

            foreach ($order_ids as $order_id) {
                $order = isset($orders[$order_id]) ? $orders[$order_id] : null;
                if (!$order || (int) ($order->user_id ?? 0) > 0) {
                    continue;
                }

                if (!$this->shop_order_contains_card_item($order)) {
                    continue;
                }

                $contacts = $this->gather_shop_order_contacts($order);
                if (!$this->lookup_contact_matches($contact_type, $contact_value, $contacts)) {
                    continue;
                }

                if (!$this->shop_order_query_password_matches($order, $query_password)) {
                    continue;
                }

                $this->maybe_update_shop_order_guest_query_lookup($order, $contacts);
                $matches[] = $order;
                $matched_ids[(int) $order->id] = true;
                if (count($matches) >= 10) {
                    return $matches;
                }
            }

            if (!empty($matches)) {
                return $matches;
            }
        }

        $params = ['%' . $wpdb->esc_like('guest_query_password_hash') . '%'];
        $where = [
            'user_id = 0',
            'seller_remark LIKE %s',
            'created_at >= %s',
        ];
        $params[] = $this->get_guest_query_password_legacy_cutoff_at();

        if ($contact_type === 'email') {
            $contact_like = '%' . $wpdb->esc_like((string) $contact_value) . '%';
            $where[] = '(seller_remark LIKE %s OR receiver_address LIKE %s)';
            $params[] = $contact_like;
            $params[] = $contact_like;
        } else {
            $phone = (string) $contact_value;
            $phone_tail = strlen($phone) >= 6 ? substr($phone, -6) : $phone;
            $where[] = '(receiver_phone LIKE %s OR receiver_phone LIKE %s)';
            $params[] = '%' . $wpdb->esc_like($phone) . '%';
            $params[] = '%' . $wpdb->esc_like($phone_tail) . '%';
        }

        $sql = "SELECT order_no FROM `{$table}` WHERE " . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 50';
        $order_numbers = $wpdb->get_col($wpdb->prepare($sql, $params));
        if (empty($order_numbers)) {
            return $matches;
        }

        foreach ($order_numbers as $order_no) {
            $order_no = strtoupper(sanitize_text_field((string) $order_no));
            if ($order_no === '') {
                continue;
            }

            $order = qls_shop_order()->get_by_order_no($order_no, true);
            if (!$order || (int) ($order->user_id ?? 0) > 0) {
                continue;
            }

            $order_id = (int) ($order->id ?? 0);
            if ($order_id > 0 && isset($matched_ids[$order_id])) {
                continue;
            }

            if (!$this->shop_order_contains_card_item($order)) {
                continue;
            }

            $contacts = $this->gather_shop_order_contacts($order);
            if (!$this->lookup_contact_matches($contact_type, $contact_value, $contacts)) {
                continue;
            }

            if (!$this->shop_order_query_password_matches($order, $query_password)) {
                continue;
            }

            $this->maybe_update_shop_order_guest_query_lookup($order, $contacts);
            $matches[] = $order;
            if ($order_id > 0) {
                $matched_ids[$order_id] = true;
            }
            if (count($matches) >= 10) {
                break;
            }
        }

        return $matches;
    }

    /**
     * 构造商城订单查询响应。
     *
     * @param object $shop_order
     * @return array
     */
    private function build_shop_order_lookup_payload($shop_order) {
        $order_no = strtoupper(sanitize_text_field((string) ($shop_order->order_no ?? '')));
        $status = (int) ($shop_order->status ?? 0);
        $status_text = function_exists('qls_shop_order') ? qls_shop_order()->get_status_text($status) : (string) $status;
        $amount = (float) ($shop_order->final_amount ?? 0);
        if ($amount <= 0) {
            $amount = (float) ($shop_order->total_amount ?? 0);
        }

        $item_title = '';
        if (!empty($shop_order->items) && is_array($shop_order->items)) {
            $first_title = isset($shop_order->items[0]->product_title) ? (string) $shop_order->items[0]->product_title : '';
            $count = count($shop_order->items);
            if ($first_title !== '' && $count > 1) {
                $item_title = sprintf('%s %s', $first_title, sprintf(__('等 %d 件商品', 'qilingshop'), $count));
            } elseif ($first_title !== '') {
                $item_title = $first_title;
            }
        }

        return [
            'scene'       => 'shop',
            'scene_label' => __('商城订单', 'qilingshop'),
            'order_no'    => $order_no,
            'status'      => $status,
            'status_text' => $status_text,
            'amount_text' => qilingshop_format_price($amount),
            'created_at'  => isset($shop_order->created_at) ? (string) $shop_order->created_at : '',
            'paid_at'     => isset($shop_order->paid_at) ? (string) $shop_order->paid_at : '',
            'item_title'  => $item_title,
            'scope_label' => '',
            'detail_url'  => '',
            'virtual_items' => $this->build_shop_virtual_items($shop_order),
        ];
    }

    /**
     * 构造游客联系方式存储值
     *
     * @param string $phone
     * @param string $email
     * @return string
     */
    private function build_guest_contact_storage($phone, $email) {
        $payload = [];
        $phone = $this->normalize_lookup_phone($phone);
        $email = $this->normalize_lookup_email($email);
        if ($phone !== '') {
            $payload['phone'] = $phone;
        }
        if ($email !== '') {
            $payload['email'] = $email;
        }

        return empty($payload) ? '' : wp_json_encode($payload);
    }

    /**
     * 解析在线支付方式（兼容支付宝扫码/网页两种别名）
     *
     * @param string $gateway 原始网关标识
     * @return array
     */
    private function parse_online_gateway($gateway) {
        $gateway = sanitize_key((string) $gateway);
        if ($gateway === 'wechat_miniapp') {
            return [];
        }

        $payment = QilingShop_Payment::instance();

        $alipay_enabled = (bool) get_option('qilingshop_alipay_enabled');
        $alipay_f2f_enabled = (bool) get_option('qilingshop_alipay_f2fpay');

        if ($gateway === 'alipay_qr') {
            if (!$alipay_enabled || !$alipay_f2f_enabled) {
                return [];
            }
            return [
                'selected' => 'alipay_qr',
                'gateway'  => 'alipay',
                'entry'    => 'alipay',
                'extra'    => ['method' => 'f2f'],
            ];
        }

        if ($gateway === 'alipay_page') {
            if (!$alipay_enabled) {
                return [];
            }
            return [
                'selected' => 'alipay_page',
                'gateway'  => 'alipay',
                'entry'    => 'alipay',
                'extra'    => ['method' => 'page'],
            ];
        }

        if ($gateway === 'alipay') {
            if (!$alipay_enabled) {
                return [];
            }
            $extra = [];
            if ($alipay_f2f_enabled) {
                $extra['method'] = 'f2f';
            }
            return [
                'selected' => 'alipay',
                'gateway'  => 'alipay',
                'entry'    => 'alipay',
                'extra'    => $extra,
            ];
        }

        $entry = $payment->get_gateway_entry_slug($gateway);
        if ($entry === '' || !$payment->is_gateway_enabled($entry)) {
            return [];
        }

        return [
            'selected' => $gateway,
            'gateway'  => $entry,
            'entry'    => $entry,
            'extra'    => [],
        ];
    }

    /**
     * 构建支付入口 URL
     *
     * @param array $gateway_data 网关解析结果
     * @param array $args         查询参数
     * @return string
     */
    private function build_payment_url($gateway_data, $args = []) {
        if (empty($gateway_data['entry'])) {
            return '';
        }

        if ($gateway_data['entry'] === 'alipay' && !empty($gateway_data['extra']['method'])) {
            $args['method'] = sanitize_key((string) $gateway_data['extra']['method']);
        }

        return qilingshop_get_payment_entry_url($gateway_data['entry'], $args);
    }

    /**
     * 获取个人中心标签页地址
     *
     * @param string $tab 标签页标识
     * @return string
     */
    private function get_account_tab_url($tab = '') {
        if (function_exists('developer_starter_get_frontend_account_tab_url')) {
            return (string) developer_starter_get_frontend_account_tab_url($tab);
        }

        $account_url = '';

        $account_page_id = (int) get_option('developer_starter_account_page_id', 0);
        if ($account_page_id > 0 && get_post_status($account_page_id) === 'publish') {
            $account_url = get_permalink($account_page_id);
        }

        if ($account_url === '') {
            $pages = get_pages([
                'meta_key'   => '_wp_page_template',
                'meta_value' => 'templates/template-account.php',
                'number'     => 1,
            ]);
            if (!empty($pages)) {
                $account_url = get_permalink($pages[0]->ID);
            }
        }

        if ($account_url === '') {
            $account_url = function_exists('developer_starter_build_raw_home_url')
                ? developer_starter_build_raw_home_url('/user')
                : home_url('/user');
        }

        $tab = sanitize_key((string) $tab);
        if ($tab !== '') {
            return add_query_arg('tab', $tab, $account_url);
        }

        return $account_url;
    }

    /**
     * 签到
     */
    public function handle_checkin() {
        $this->verify_nonce();
        $this->require_login();
        
        $result = QilingShop_Points::instance()->checkin(get_current_user_id());
        
        if ($result['success']) {
            qilingshop_json_success([
                'points' => $result['points'],
                'consecutive_days' => $result['consecutive_days'],
            ], $result['message']);
        } else {
            qilingshop_json_error($result['message']);
        }
    }

    /**
     * 交易播报数据
     */
    public function handle_trade_feed() {
        $this->verify_nonce();

        if (!get_option('qilingshop_trade_feed_enabled', false)) {
            qilingshop_json_success([
                'items' => [],
            ]);
        }

        $default_limit = (int) get_option('qilingshop_trade_feed_batch_size', 20);
        $limit = isset($_POST['limit']) ? absint($_POST['limit']) : $default_limit;
        if ($limit <= 0) {
            $limit = $default_limit;
        }
        $limit = max(1, min($limit, 50));

        $items = QilingShop_Trade_Feed::instance()->get_feed_items($limit);
        qilingshop_json_success([
            'items' => is_array($items) ? $items : [],
        ]);
    }

    /**
     * 积分购买
     */
    public function handle_buy_with_points() {
        $this->verify_nonce();
        $this->require_login();
        
        $post_id = intval($_POST['post_id'] ?? 0);
        $download_index = array_key_exists('download_index', $_POST) ? intval($_POST['download_index']) : QilingShop_Order::DOWNLOAD_ALL_INDEX;
        $scope = sanitize_key($_POST['scope'] ?? '');
        $upgrade_from = sanitize_key($_POST['upgrade_from'] ?? '');

        if (!in_array($scope, ['view', 'download'], true)) {
            $scope = QilingShop_Resource::instance()->get_sale_mode($post_id) === 'view' ? 'view' : 'download';
        }
        if ($scope !== 'download') {
            $download_index = 0;
        } elseif ($download_index < 0) {
            $download_index = QilingShop_Order::DOWNLOAD_ALL_INDEX;
        }
        
        if (!$post_id) {
            qilingshop_json_error(__('参数错误', 'qilingshop'));
        }

        $this->guard_order_write_request('buy_with_points', [], 10, 60);
        
        $result = QilingShop_Order::instance()->purchase_with_points(get_current_user_id(), $post_id, $download_index, $scope, $upgrade_from);
        
        if ($result['success']) {
            qilingshop_json_success([
                'order_no'        => $result['order_no'],
                'scope'           => $scope,
                'download_index'  => $scope === 'download' ? $download_index : 0,
                'reload_required' => true,
            ], $result['message']);
        } else {
            qilingshop_json_error($result['message']);
        }
    }

    /**
     * 购买 VIP
     */
    public function handle_buy_vip() {
        $this->verify_nonce();
        $this->require_login();
        
        $level_id = intval($_POST['level_id'] ?? 0);
        
        if (!$level_id) {
            qilingshop_json_error(__('请选择 VIP 等级', 'qilingshop'));
        }

        $this->guard_order_write_request('buy_vip_points', [], 6, 60);
        
        $result = QilingShop_VIP::instance()->purchase_with_points(get_current_user_id(), $level_id);
        
        if ($result['success']) {
            qilingshop_json_success([
                'level_id'   => $result['level_id'],
                'level_name' => $result['level_name'],
                'expires'    => $result['expires'],
            ], $result['message']);
        } else {
            qilingshop_json_error($result['message']);
        }
    }

    /**
     * VIP 直付购买
     */
    public function handle_buy_vip_direct() {
        $this->verify_nonce();
        $this->require_login();
        
        $level_id = intval($_POST['level_id'] ?? 0);
        $gateway_data = $this->parse_online_gateway(sanitize_text_field($_POST['gateway'] ?? ''));
        $coupon_claim_id = intval($_POST['coupon_claim_id'] ?? 0);
        
        if (!$level_id) {
            qilingshop_json_error(__('请选择 VIP 等级', 'qilingshop'));
        }

        if (empty($gateway_data)) {
            qilingshop_json_error(__('请选择可用的支付方式', 'qilingshop'));
        }
        
        $vip = QilingShop_VIP::instance();
        $level = $vip->get_level_by_id($level_id);
        
        if (!$level) {
            qilingshop_json_error(__('VIP等级不存在', 'qilingshop'));
        }

        $this->guard_order_write_request('buy_vip_direct', [], 6, 60);
        
        $user_id = get_current_user_id();
        $price = (float) $vip->calculate_upgrade_price($user_id, $level_id);
        if ($price < 0) {
            $price = 0;
        }
        $upgrade_from = 0;
        if ($user_id) {
            $current_level_id = $vip->get_user_level_for_pricing($user_id);
            if ($current_level_id > 0) {
                $current_info = $vip->get_level_by_id($current_level_id);
                if ($current_info && $level && (int) $level->sort_order > (int) $current_info->sort_order) {
                    $upgrade_from = $current_level_id;
                }
            }
        }
        
        // 处理优惠券
        $discount = 0;
        if ($coupon_claim_id > 0 && class_exists('QLS_Coupon')) {
            $coupon = QLS_Coupon::get_user_coupon_by_claim_id($coupon_claim_id, get_current_user_id());
            if ($coupon && $coupon->status === 'unused') {
                $validation = QLS_Coupon::validate_coupon_for_order($coupon, 'vip', $price, [], $user_id);
                if ($validation['valid']) {
                    $discount = QLS_Coupon::calc_discount_amount($coupon, $price);
                }
            }
        }
        
        $final_price = $price - $discount;
        
        // 如果优惠后价格为0，直接完成订单
        if ($final_price <= 0) {
            $final_price = 0;
            $order_no = qilingshop_security()->generate_order_no('VIP');
            if (!$this->reserve_coupon_for_order($coupon_claim_id, $order_no, 'vip', $price, $discount)) {
                qilingshop_json_error(__('优惠券已被使用或占用，请重新选择优惠券', 'qilingshop'));
            }
            
            $order_id = QilingShop_Database::instance()->insert('orders', [
                'order_no'        => $order_no,
                'user_id'         => $user_id,
                'post_id'         => 0,
                'post_title'      => $level->level_name,
                'order_type'      => 'vip',
                'price_rmb'       => $price,
                'discount_amount' => $discount,
                'final_price'     => $final_price,
                'payment_method'  => 'coupon',
                'status'          => 0,
                'ip_address'      => qilingshop_security()->get_client_ip(),
                'remark'          => json_encode(['level_id' => $level_id, 'duration' => $level->duration_days, 'coupon_claim_id' => $coupon_claim_id, 'upgrade_from' => $upgrade_from]),
                'created_at'      => current_time('mysql'),
            ]);
            
            if (!$order_id) {
                $this->release_coupon_reservation($coupon_claim_id, $order_no);
                qilingshop_json_error(__('创建订单失败', 'qilingshop'));
            }
            
            $paid = QilingShop_Order::instance()->mark_paid($order_no, '', 'coupon');
            if (!$paid) {
                QilingShop_Order::instance()->cancel_failed_internal_order($order_no);
                qilingshop_json_error(__('VIP开通失败，请稍后重试', 'qilingshop'));
            }

            qilingshop_json_success([
                'order_no' => $order_no,
                'paid'     => true,
                'discount' => $discount,
            ], __('VIP开通成功', 'qilingshop'));
        }
        
        // 创建VIP订单（待支付）
        $order_no = qilingshop_security()->generate_order_no('VIP');
        if (!$this->reserve_coupon_for_order($coupon_claim_id, $order_no, 'vip', $price, $discount)) {
            qilingshop_json_error(__('优惠券已被使用或占用，请重新选择优惠券', 'qilingshop'));
        }
        
        $order_id = QilingShop_Database::instance()->insert('orders', [
            'order_no'        => $order_no,
            'user_id'         => $user_id,
            'post_id'         => 0,
            'post_title'      => $level->level_name,
            'order_type'      => 'vip',
            'price_rmb'       => $price,
            'discount_amount' => $discount,
            'final_price'     => $final_price,
            'payment_method'  => $gateway_data['selected'],
            'status'          => 0,
            'ip_address'      => qilingshop_security()->get_client_ip(),
            'remark'          => json_encode(['level_id' => $level_id, 'duration' => $level->duration_days, 'coupon_claim_id' => $coupon_claim_id, 'upgrade_from' => $upgrade_from]),
            'created_at'      => current_time('mysql'),
        ]);
        
        if (!$order_id) {
            $this->release_coupon_reservation($coupon_claim_id, $order_no);
            qilingshop_json_error(__('创建订单失败', 'qilingshop'));
        }
        
        // 优惠券已预占，支付成功后再确认核销（coupon_claim_id已保存在remark中）
        
        // 创建支付（VIP在线支付成功后统一回到会员中心）
        $payment_extra = array_merge($gateway_data['extra'], [
            'return_url' => $this->get_account_tab_url('qls-vip'),
        ]);

        $payment = QilingShop_Payment::instance()->create_payment(
            'vip',
            $order_no,
            $final_price,
            $gateway_data['gateway'],
            $payment_extra
        );
        
        if ($payment['success']) {
            qilingshop_json_success([
                'order_no' => $order_no,
                'pay_url'  => $payment['pay_url'] ?? '',
                'discount' => $discount,
            ]);
        } else {
            QilingShop_Order::instance()->delete_pending((int) $order_id);
            qilingshop_json_error($payment['message']);
        }
    }

    /**
     * VIP 兑换码
     */
    public function handle_vip_redeem() {
        $this->verify_nonce();
        $this->require_login();

        if (!class_exists('QilingShop_VIP_Code')) {
            qilingshop_json_error(__('兑换服务不可用', 'qilingshop'));
        }

        $code = sanitize_text_field($_POST['code'] ?? '');
        if ($code === '') {
            qilingshop_json_error(__('请输入兑换码', 'qilingshop'));
        }

        $this->guard_vip_redeem_request($code);

        $user_id = get_current_user_id();
        $result = QilingShop_VIP_Code::instance()->redeem_code($code, $user_id);
        if ($result['success']) {
            qilingshop_json_success([
                'level_id'   => $result['level_id'] ?? 0,
                'level_name' => $result['level_name'] ?? '',
                'expires'    => $result['expires'] ?? '',
                'order_no'   => $result['order_no'] ?? '',
            ], $result['message'] ?? __('兑换成功', 'qilingshop'));
        }

        qilingshop_json_error($result['message'] ?? __('兑换失败', 'qilingshop'));
    }


    /**
     * 充值
     */
    public function handle_recharge() {
        $this->verify_nonce();
        $this->require_login();
        
        $amount = floatval($_POST['amount'] ?? 0);
        $gateway_data = $this->parse_online_gateway(sanitize_text_field($_POST['gateway'] ?? ''));
        $coupon_claim_id = intval($_POST['coupon_claim_id'] ?? 0);
        $user_id = get_current_user_id();
        
        if ($amount <= 0) {
            qilingshop_json_error(__('请输入充值金额', 'qilingshop'));
        }

        if (empty($gateway_data)) {
            qilingshop_json_error(__('请选择可用的支付方式', 'qilingshop'));
        }

        $this->guard_order_write_request('recharge', [], 6, 60);

        $lock_name = $this->build_user_write_lock_name('qlsrecharge', $user_id);
        if (!$this->acquire_named_lock($lock_name, 5)) {
            qilingshop_json_error(__('充值处理中，请勿重复提交', 'qilingshop'));
        }

        try {
        
            // 处理优惠券
            $discount = 0;
            if ($coupon_claim_id > 0 && class_exists('QLS_Coupon')) {
                $coupon = QLS_Coupon::get_user_coupon_by_claim_id($coupon_claim_id, $user_id);
                if ($coupon && $coupon->status === 'unused') {
                    $validation = QLS_Coupon::validate_coupon_for_order($coupon, 'recharge', $amount, [], $user_id);
                    if ($validation['valid']) {
                        $discount = QLS_Coupon::calc_discount_amount($coupon, $amount);
                    }
                }
            }
            
            $final_amount = $amount - $discount;
            $recharge_remark = '';
            if ($coupon_claim_id > 0 && $discount > 0) {
                $recharge_remark = wp_json_encode([
                    'coupon_claim_id' => $coupon_claim_id,
                    'discount_amount' => $discount,
                ]);
            }
            $order_no = qilingshop_security()->generate_order_no('CZ');
            if (!$this->reserve_coupon_for_order($coupon_claim_id, $order_no, 'recharge', $amount, $discount)) {
                qilingshop_json_error(__('优惠券已被使用或占用，请重新选择优惠券', 'qilingshop'));
            }
            
            // 如果优惠后价格为0，直接完成订单
            if ($final_amount <= 0) {
                $final_amount = 0;
                // 创建已完成的充值订单
                $result = QilingShop_Recharge::instance()->create_order($user_id, $amount, 'coupon', 0, $discount, $recharge_remark, $order_no);
                
                if (!$result['success']) {
                    $this->release_coupon_reservation($coupon_claim_id, $order_no);
                    qilingshop_json_error($result['message']);
                }

                // 完成充值
                $completed = QilingShop_Recharge::instance()->complete($result['order_no'], '');
                if (!$completed) {
                    $failed_recharge = QilingShop_Recharge::instance()->get_by_order_no($result['order_no']);
                    $points_logged = false;
                    if ($failed_recharge && class_exists('QilingShop_Points')) {
                        $points_logged = QilingShop_Points::instance()->has_points_log(
                            (int) $failed_recharge->user_id,
                            'recharge',
                            (int) $failed_recharge->id
                        );
                    }
                    if (!$points_logged) {
                        QilingShop_Recharge::instance()->cancel_failed_internal_order($result['order_no']);
                    }
                    qilingshop_json_error(__('充值失败，请稍后重试', 'qilingshop'));
                }
                
                qilingshop_json_success([
                    'order_no' => $result['order_no'],
                    'paid'     => true,
                    'discount' => $discount,
                ], __('充值成功', 'qilingshop'));
            }
            
            // 创建充值订单
            $result = QilingShop_Recharge::instance()->create_order(
                $user_id,
                $amount,
                $gateway_data['selected'],
                $final_amount,
                $discount,
                $recharge_remark,
                $order_no
            );
            
            if (!$result['success']) {
                $this->release_coupon_reservation($coupon_claim_id, $order_no);
                qilingshop_json_error($result['message']);
            }
            
            // 构建支付URL，使用优惠后的价格
            $fixed_title = get_option('qilingshop_fixed_order_title', '');
            $subject = !empty($fixed_title) ? $fixed_title : get_bloginfo('name');
            $pay_url = $this->build_payment_url($gateway_data, [
                'order'        => $result['order_no'],
                'price'        => $final_amount,
                'subject'      => $subject,
                'redirect_url' => $this->get_account_tab_url('qls-shop'),
            ]);

            if ($pay_url === '') {
                $pending_recharge = QilingShop_Recharge::instance()->get_by_order_no($result['order_no']);
                if ($pending_recharge) {
                    QilingShop_Recharge::instance()->delete_pending((int) $pending_recharge->id);
                }
                qilingshop_json_error(__('支付方式不可用', 'qilingshop'));
            }
            
            qilingshop_json_success([
                'order_no' => $result['order_no'],
                'pay_url'  => $pay_url,
                'discount' => $discount,
                'poll_token' => wp_create_nonce('qilingshop_poll_' . $result['order_no']),
            ]);
        } finally {
            $this->release_named_lock($lock_name);
        }
    }

    /**
     * 直接支付购买资源
     */
    public function handle_direct_pay() {
        $this->verify_nonce();
        
        $post_id = intval($_POST['post_id'] ?? 0);
        $gateway_data = $this->parse_online_gateway(sanitize_text_field($_POST['gateway'] ?? 'alipay'));
        $raw_redirect_url = isset($_POST['redirect_url']) ? (string) wp_unslash($_POST['redirect_url']) : '';
        $coupon_claim_id = intval($_POST['coupon_claim_id'] ?? 0);
        $scope = sanitize_key($_POST['scope'] ?? '');
        $upgrade_from = sanitize_key($_POST['upgrade_from'] ?? '');
        $download_index = array_key_exists('download_index', $_POST) ? intval($_POST['download_index']) : QilingShop_Order::DOWNLOAD_ALL_INDEX;
        
        if (!$post_id) {
            qilingshop_json_error(__('参数错误', 'qilingshop'));
        }

        $default_redirect_url = get_permalink($post_id);
        $redirect_url = function_exists('qilingshop_normalize_return_url')
            ? qilingshop_normalize_return_url($raw_redirect_url, $default_redirect_url)
            : $default_redirect_url;
        if ($redirect_url === '') {
            $redirect_url = home_url('/');
        }

        if (empty($gateway_data)) {
            qilingshop_json_error(__('请选择可用的支付方式', 'qilingshop'));
        }
        
        $user_id = get_current_user_id();
        $guest_id = '';
        $guest_contact_phone = '';
        $guest_contact_email = '';
        $guest_contact_info = [];
        $guest_contact_storage = '';
        
        // 游客购买
        if (!$user_id && QilingShop_Guest::instance()->is_enabled()) {
            $guest_id = QilingShop_Guest::instance()->get_guest_id();
        } elseif (!$user_id) {
            qilingshop_json_error(__('请先登录', 'qilingshop'));
        }

        if ($guest_id !== '') {
            $guest_phone_raw = sanitize_text_field(wp_unslash($_POST['guest_phone'] ?? ''));
            $guest_email_raw = sanitize_text_field(wp_unslash($_POST['guest_email'] ?? ''));

            $guest_contact_phone = $this->normalize_lookup_phone($guest_phone_raw);
            $guest_contact_email = $this->normalize_lookup_email($guest_email_raw);

            if ($guest_email_raw !== '' && $guest_contact_email === '') {
                qilingshop_json_error(__('请输入有效的邮箱', 'qilingshop'));
            }

            if ($guest_phone_raw !== '' && ($guest_contact_phone === '' || strlen($guest_contact_phone) < 6 || strlen($guest_contact_phone) > 20)) {
                qilingshop_json_error(__('请输入有效的手机号', 'qilingshop'));
            }

            if ($guest_contact_phone === '' && $guest_contact_email === '') {
                qilingshop_json_error(__('游客购买请填写手机号或邮箱', 'qilingshop'));
            }

            $guest_contact_info = [
                'phone' => $guest_contact_phone,
                'email' => $guest_contact_email,
            ];
            $guest_contact_storage = $this->build_guest_contact_storage($guest_contact_phone, $guest_contact_email);
        }

        if (!$user_id) {
            $upgrade_from = '';
        }

        $this->guard_order_write_request('direct_pay', [], 10, 60);

        $resource = QilingShop_Resource::instance();
        $sale_mode = $resource->get_sale_mode($post_id);
        if ($sale_mode === 'free') {
            qilingshop_json_error(__('该资源为免费资源，无需购买', 'qilingshop'));
        }
        if (!in_array($scope, ['view', 'download'], true)) {
            $scope = $sale_mode === 'view' ? 'view' : 'download';
        }
        if ($upgrade_from !== 'view') {
            $upgrade_from = '';
        }
        if ($upgrade_from === 'view' && $scope !== 'download') {
            $upgrade_from = '';
        }
        if ($scope !== 'download') {
            $download_index = 0;
        } elseif ($download_index < 0) {
            $download_index = QilingShop_Order::DOWNLOAD_ALL_INDEX;
        }
        if ($scope === 'download' && $download_index >= 0 && !$resource->download_index_exists($post_id, $download_index)) {
            qilingshop_json_error(__('下载项不存在', 'qilingshop'));
        }

        if ($user_id) {
            $purchase_guard = QilingShop_Order::instance()->normalize_resource_purchase_request($post_id, $user_id, $scope, $upgrade_from, $download_index);
            $scope = $purchase_guard['scope'];
            $upgrade_from = $purchase_guard['upgrade_from'];
            if (!empty($purchase_guard['blocked'])) {
                qilingshop_json_error($purchase_guard['message']);
            }
        } elseif ($guest_id !== '') {
            $guest_has_purchased = QilingShop_Order::instance()->guest_has_purchased(
                $post_id,
                $guest_id,
                $scope,
                $scope === 'download' ? $download_index : null
            );
            if ($guest_has_purchased) {
                $message = ($scope === 'download' && $download_index !== QilingShop_Order::DOWNLOAD_ALL_INDEX)
                    ? __('您已购买该下载项，无需重复购买', 'qilingshop')
                    : __('您已购买该资源，无需重复购买', 'qilingshop');
                qilingshop_json_error($message);
            }
        }

        $context = $scope === 'view' ? 'view' : 'download';
        $vip_only_purchase = $resource->is_vip_only_purchase($post_id, $context);
        $vip_only_access = $resource->is_vip_only_access($post_id, $context);
        if (($vip_only_purchase || $vip_only_access) && (!$user_id || !$resource->has_vip_access($post_id, $user_id, $context))) {
            if (!$user_id) {
                $message = $vip_only_access ? __('该资源仅限 VIP 访问，游客不可购买', 'qilingshop') : __('该资源仅限 VIP 购买，游客不可购买', 'qilingshop');
            } else {
                $message = $vip_only_access ? __('该资源仅限 VIP 访问', 'qilingshop') : __('该资源仅限 VIP 购买', 'qilingshop');
            }
            qilingshop_json_error($message);
        }
        
        $price_info = $resource->get_price($post_id, $user_id, $context);
        if ($upgrade_from === 'view') {
            $has_view = QilingShop_Order::instance()->user_has_purchased($post_id, $user_id, false, 'view');
            $has_download = QilingShop_Order::instance()->user_has_purchased($post_id, $user_id, false, 'download', $download_index);
            if (!$has_view || $has_download) {
                qilingshop_json_error(__('无需补差价购买', 'qilingshop'));
            }
            $view_price = $resource->get_price($post_id, $user_id, 'view');
            $price_info['points'] = max(0, $price_info['points'] - $view_price['points']);
            $price_info['rmb'] = max(0, $price_info['rmb'] - $view_price['rmb']);
            $price_info['original'] = max(0, $price_info['original'] - $view_price['original']);
        }
        
        if ($price_info['rmb'] < 0) {
            qilingshop_json_error(__('该资源价格异常', 'qilingshop'));
        }

        $sale_mode = $resource->get_sale_mode($post_id);
        if ($sale_mode !== 'free' && $price_info['rmb'] <= 0 && $price_info['points'] > 0) {
            qilingshop_json_error(__('该资源仅支持积分购买', 'qilingshop'));
        }
        if ($sale_mode !== 'free' && $price_info['rmb'] <= 0 && $price_info['points'] <= 0 && $upgrade_from !== 'view') {
            qilingshop_json_error(__('资源价格配置异常，请联系管理员', 'qilingshop'));
        }
        
        $original_price = $price_info['rmb'];
        $base_price = $resource->get_rmb_price($post_id, $context);
        if ($upgrade_from === 'view') {
            $view_base_price = $resource->get_rmb_price($post_id, 'view');
            $base_price = max(0, $base_price - $view_base_price);
        }
        $vip_discount_applied = ($user_id && $price_info['discount'] < 100 && $base_price > 0 && $original_price < $base_price);
        
        // 处理优惠券
        $discount = 0;
        if ($coupon_claim_id > 0 && $user_id && class_exists('QLS_Coupon')) {
            $coupon = QLS_Coupon::get_user_coupon_by_claim_id($coupon_claim_id, $user_id);
            if ($coupon && $coupon->status === 'unused') {
                $price_for_coupon = $original_price;
                $stack_with_vip = isset($coupon->stack_with_vip) ? (int) $coupon->stack_with_vip : 1;
                if (!$stack_with_vip && $vip_discount_applied) {
                    $price_for_coupon = $base_price;
                }
                $validation = QLS_Coupon::validate_coupon_for_order($coupon, 'resource', $price_for_coupon, [$post_id], $user_id);
                if ($validation['valid']) {
                    $discount = QLS_Coupon::calc_discount_amount($coupon, $price_for_coupon);
                    if (!$stack_with_vip && $vip_discount_applied) {
                        $coupon_price = max(0, $price_for_coupon - $discount);
                        if ($coupon_price >= $original_price) {
                            $discount = 0;
                            $price_for_coupon = $original_price;
                        } else {
                            $original_price = $price_for_coupon;
                        }
                    }
                }
            }
        }
        
        $final_price = $original_price - $discount;
        
        // 如果优惠后价格为0，直接完成订单
        if ($final_price <= 0) {
            $valid_zero_price = ($discount > 0 && $original_price > 0) || $upgrade_from === 'view';
            if (!$valid_zero_price) {
                qilingshop_json_error(__('订单金额异常，请联系管理员', 'qilingshop'));
            }

            $final_price = 0;
            $remark = [
                'coupon_claim_id' => $coupon_claim_id,
                'scope' => $scope,
                'upgrade_from' => $upgrade_from,
            ];
            if ($scope === 'download') {
                $remark['download_index'] = $download_index;
            }
            $payment_method = $discount > 0 ? 'coupon' : '';
            $order_no = qilingshop_security()->generate_order_no();
            $reserved_order_no = $order_no;
            if (!$this->reserve_coupon_for_order($coupon_claim_id, $order_no, 'resource', $original_price, $discount)) {
                qilingshop_json_error(__('优惠券已被使用或占用，请重新选择优惠券', 'qilingshop'));
            }
            $order_no = QilingShop_Order::instance()->create([
                'order_no'        => $order_no,
                'user_id'         => $user_id,
                'guest_id'        => $guest_id,
                'post_id'         => $post_id,
                'order_type'      => 'resource',
                'price_rmb'       => $original_price,
                'discount_amount' => $discount,
                'final_price'     => $final_price,
                'contact_info'    => $guest_contact_storage,
                'payment_method'  => $payment_method,
                'status'          => 0, // 待支付，后续统一走 mark_paid
                'download_index'  => $scope === 'download' ? $download_index : 0,
                'remark'          => wp_json_encode($remark),
            ]);
            
            if (!$order_no) {
                $this->release_coupon_reservation($coupon_claim_id, $reserved_order_no);
                qilingshop_json_error(__('创建订单失败', 'qilingshop'));
            }

            // 执行购买后处理（触发完成逻辑/优惠券标记等）
            $paid = QilingShop_Order::instance()->mark_paid($order_no, '', $payment_method ?: 'coupon');
            if (!$paid) {
                QilingShop_Order::instance()->cancel_failed_internal_order($order_no);
                qilingshop_json_error(__('购买失败，请稍后重试', 'qilingshop'));
            }
            
            // 游客关联
            $guest_binding_failed = false;
            if ($guest_id && !QilingShop_Guest::instance()->create_guest_order($order_no, $post_id, $guest_contact_info)) {
                $guest_binding_failed = true;
            }
            
            qilingshop_json_success([
                'order_no'             => $order_no,
                'paid'                 => true,
                'discount'             => $discount,
                'guest_binding_failed' => $guest_binding_failed,
            ], $guest_binding_failed ? __('购买成功，但游客订单关联失败，请在当前设备使用或联系管理员处理', 'qilingshop') : __('购买成功', 'qilingshop'));
        }
        
        // 创建订单（待支付）
        $remark = [
            'coupon_claim_id' => $coupon_claim_id,
            'scope' => $scope,
            'upgrade_from' => $upgrade_from,
        ];
        if ($scope === 'download') {
            $remark['download_index'] = $download_index;
        }
        $order_no = qilingshop_security()->generate_order_no();
        $reserved_order_no = $order_no;
        if (!$this->reserve_coupon_for_order($coupon_claim_id, $order_no, 'resource', $original_price, $discount)) {
            qilingshop_json_error(__('优惠券已被使用或占用，请重新选择优惠券', 'qilingshop'));
        }
        $order_no = QilingShop_Order::instance()->create([
            'order_no'        => $order_no,
            'user_id'         => $user_id,
            'guest_id'        => $guest_id,
            'post_id'         => $post_id,
            'order_type'      => 'resource',
            'price_rmb'       => $original_price,
            'discount_amount' => $discount,
            'final_price'     => $final_price,
            'contact_info'    => $guest_contact_storage,
            'download_index'  => $scope === 'download' ? $download_index : 0,
            'remark'          => wp_json_encode($remark),
        ]);
        
        if (!$order_no) {
            $this->release_coupon_reservation($coupon_claim_id, $reserved_order_no);
            qilingshop_json_error(__('创建订单失败', 'qilingshop'));
        }
        
        // 优惠券已预占，支付成功后再确认核销（coupon_claim_id已保存在remark中）
        
        // 游客关联
        $guest_binding_failed = false;
        if ($guest_id && !QilingShop_Guest::instance()->create_guest_order($order_no, $post_id, $guest_contact_info)) {
            $guest_binding_failed = true;
        }
        
        // 构建支付页面URL
        $fixed_title = get_option('qilingshop_fixed_order_title', '');
        $subject = !empty($fixed_title) ? $fixed_title : get_bloginfo('name');
        $pay_url = $this->build_payment_url($gateway_data, [
            'order'        => $order_no,
            'price'        => $final_price,
            'subject'      => $subject,
            'redirect_url' => $redirect_url,
        ]);

        if ($pay_url === '') {
            $pending_order = QilingShop_Order::instance()->get_by_order_no($order_no);
            if ($pending_order) {
                QilingShop_Order::instance()->delete_pending((int) $pending_order->id);
            }
            qilingshop_json_error(__('支付方式不可用', 'qilingshop'));
        }
        
        qilingshop_json_success([
            'order_no'             => $order_no,
            'pay_url'              => $pay_url,
            'discount'             => $discount,
            'guest_binding_failed' => $guest_binding_failed,
        ], $guest_binding_failed ? __('订单创建成功，但游客订单关联失败，请在当前设备完成支付或联系管理员处理', 'qilingshop') : '');
    }

    /**
     * 游客订单恢复
     */
    public function handle_guest_recovery() {
        $this->verify_nonce();
        
        $identifier = sanitize_text_field($_POST['identifier'] ?? '');
        
        if (empty($identifier)) {
            qilingshop_json_error(__('请输入订单号', 'qilingshop'));
        }

        $this->guard_order_write_request('guest_recovery', [], 10, 300);
        
        $result = QilingShop_Guest::instance()->recover_order($identifier);
        
        if ($result['success']) {
            qilingshop_json_success([
                'order_no' => $result['order_no'],
                'post_url' => get_permalink($result['post_id']),
            ], $result['message']);
        } else {
            qilingshop_json_error($result['message']);
        }
    }

    /**
     * 检查订单支付状态
     */
    public function handle_check_order() {
        $this->verify_nonce();

        $order_no = sanitize_text_field($_POST['order_no'] ?? '');
        if (!preg_match('/^[A-Z0-9]{8,64}$/', $order_no)) {
            wp_send_json_success(['paid' => false]);
        }

        $poll_token = sanitize_text_field($_POST['poll_token'] ?? '');
        if ($poll_token === '' || !wp_verify_nonce($poll_token, 'qilingshop_poll_' . $order_no)) {
            wp_send_json_success(['paid' => false]);
        }

        $order_type = sanitize_key($_POST['type'] ?? '');
        $gateway = sanitize_key($_POST['gateway'] ?? '');
        $user_id = get_current_user_id();
        global $wpdb;

        if (strpos($order_no, 'CZ') === 0 || $order_type === 'recharge') {
            $table = QilingShop_Database::instance()->get_table('recharge');
            if ($user_id > 0) {
                $status = $wpdb->get_var($wpdb->prepare(
                    "SELECT status FROM {$table} WHERE order_no = %s AND user_id = %d LIMIT 1",
                    $order_no,
                    $user_id
                ));
            } else {
                $status = $wpdb->get_var($wpdb->prepare(
                    "SELECT status FROM {$table} WHERE order_no = %s AND user_id = 0 LIMIT 1",
                    $order_no
                ));
            }
            $paid = ($status !== null && intval($status) === 1);
            if ($status !== null && !$paid && in_array($gateway, ['alipay', 'xhpay'], true)) {
                $paid = $this->recover_gateway_paid_order($order_no, $gateway);
            }
            wp_send_json_success(['paid' => $paid]);
        }

        if ((strpos($order_no, 'SHOP') === 0 || strpos($order_no, 'TUAN') === 0 || $order_type === 'shop_order') && function_exists('qls_shop_db')) {
            $table = qls_shop_db()->get_table('orders');
            if ($user_id > 0) {
                $status = $wpdb->get_var($wpdb->prepare(
                    "SELECT status FROM {$table} WHERE order_no = %s AND user_id = %d LIMIT 1",
                    $order_no,
                    $user_id
                ));
            } else {
                $status = $wpdb->get_var($wpdb->prepare(
                    "SELECT status FROM {$table} WHERE order_no = %s AND user_id = 0 LIMIT 1",
                    $order_no
                ));
            }
            $paid = ($status !== null && intval($status) >= 1 && intval($status) <= 3);
            if ($status !== null && !$paid && in_array($gateway, ['alipay', 'xhpay'], true)) {
                $paid = $this->recover_gateway_paid_order($order_no, $gateway);
            }
            wp_send_json_success(['paid' => $paid]);
        }

        $table = QilingShop_Database::instance()->get_table('orders');
        if ($user_id > 0) {
            $status = $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$table} WHERE order_no = %s AND user_id = %d LIMIT 1",
                $order_no,
                $user_id
            ));
        } else {
            $status = $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$table} WHERE order_no = %s AND user_id = 0 LIMIT 1",
                $order_no
            ));
        }

        $paid = ($status !== null && intval($status) === 1);
        if ($status !== null && !$paid && in_array($gateway, ['alipay', 'xhpay'], true)) {
            $paid = $this->recover_gateway_paid_order($order_no, $gateway);
        }

        wp_send_json_success(['paid' => $paid]);
    }

    /**
     * 前台订单查询（游客）
     *
     * 仅支持游客订单，默认校验“订单号 + 手机号/邮箱”。
     * 发卡订单查询密码开启后，也支持“手机号/邮箱 + 查询密码”。
     */
    public function handle_order_lookup() {
        $this->verify_nonce();

        $order_no = strtoupper(sanitize_text_field(wp_unslash($_POST['order_no'] ?? '')));
        $contact_raw = sanitize_text_field(wp_unslash($_POST['contact'] ?? ''));
        $query_password = trim(sanitize_text_field(wp_unslash($_POST['query_password'] ?? '')));
        $password_lookup_enabled = (bool) get_option('qls_shop_guest_query_password_enabled', false);

        if ($contact_raw === '') {
            qilingshop_json_error(__('请输入手机号或邮箱', 'qilingshop'));
        }

        if ($order_no === '' && (!$password_lookup_enabled || $query_password === '')) {
            qilingshop_json_error(__('请输入订单号，或填写查询密码', 'qilingshop'));
        }

        if ($order_no !== '' && !preg_match('/^[A-Z0-9]{8,64}$/', $order_no)) {
            qilingshop_json_error(__('请输入正确的订单号', 'qilingshop'));
        }

        $contact_type = strpos($contact_raw, '@') !== false ? 'email' : 'phone';
        if ($contact_type === 'email') {
            $contact_value = $this->normalize_lookup_email($contact_raw);
            if ($contact_value === '') {
                qilingshop_json_error(__('请输入有效的邮箱', 'qilingshop'));
            }
        } else {
            $contact_value = $this->normalize_lookup_phone($contact_raw);
            if ($contact_value === '' || strlen($contact_value) < 6 || strlen($contact_value) > 20) {
                qilingshop_json_error(__('请输入有效的手机号', 'qilingshop'));
            }
        }

        $mismatch_message = __('订单号或联系方式不匹配', 'qilingshop');

        if ($order_no === '') {
            $this->guard_order_lookup_request('PWD' . strtoupper(substr(md5($contact_value), 0, 12)), $contact_value . '|p:' . md5($query_password));

            $matched_orders = $this->find_shop_card_orders_by_contact_password($contact_type, $contact_value, $query_password);
            if (empty($matched_orders)) {
                qilingshop_json_error(__('联系方式或查询密码不匹配', 'qilingshop'));
            }

            $payloads = array_map([$this, 'build_shop_order_lookup_payload'], $matched_orders);
            if (count($payloads) === 1) {
                qilingshop_json_success($payloads[0]);
            }

            qilingshop_json_success([
                'scene'       => 'shop',
                'scene_label' => __('商城订单', 'qilingshop'),
                'orders'      => $payloads,
            ]);
        }

        $this->guard_order_lookup_request($order_no, $contact_value);

        // 商城订单（SHOP/TUAN）查询
        $is_shop_order = strpos($order_no, 'SHOP') === 0 || strpos($order_no, 'TUAN') === 0;
        if ($is_shop_order && function_exists('qls_shop_order')) {
            $shop_order = qls_shop_order()->get_by_order_no($order_no, true);
            if (!$shop_order || (int) ($shop_order->user_id ?? 0) > 0) {
                qilingshop_json_error($mismatch_message);
            }

            $contacts = $this->gather_shop_order_contacts($shop_order);
            if (!$this->lookup_contact_matches($contact_type, $contact_value, $contacts)) {
                qilingshop_json_error($mismatch_message);
            }

            qilingshop_json_success($this->build_shop_order_lookup_payload($shop_order));
        }

        // 虚拟资源订单查询
        $order = QilingShop_Order::instance()->get_by_order_no($order_no);
        if (!$order || (int) ($order->user_id ?? 0) > 0) {
            qilingshop_json_error($mismatch_message);
        }

        $contacts = $this->gather_resource_order_contacts($order_no, $order);
        if (!$this->lookup_contact_matches($contact_type, $contact_value, $contacts)) {
            qilingshop_json_error($mismatch_message);
        }

        $status = (int) ($order->status ?? 0);
        $status_text = QilingShop_Order::instance()->get_status_text($status);
        $amount_text = '';
        if ((float) ($order->price_points ?? 0) > 0) {
            $amount_text = qilingshop_format_points((float) $order->price_points);
        } else {
            $amount = (float) ($order->final_price ?? 0);
            if ($amount <= 0) {
                $amount = (float) ($order->price_rmb ?? 0);
            }
            $amount_text = qilingshop_format_price($amount);
        }

        $item_title = isset($order->post_title) ? (string) $order->post_title : '';
        if ($item_title === '' && !empty($order->post_id)) {
            $post_title = get_the_title((int) $order->post_id);
            if (is_string($post_title)) {
                $item_title = $post_title;
            }
        }

        $scope_label = QilingShop_Order::instance()->get_order_scope_label($order);
        $detail_url = '';
        if (!empty($order->post_id)) {
            $permalink = get_permalink((int) $order->post_id);
            if (is_string($permalink) && $permalink !== '') {
                $detail_url = $permalink;
            }
        }

        qilingshop_json_success([
            'scene'       => 'resource',
            'scene_label' => __('虚拟资源订单', 'qilingshop'),
            'order_no'    => $order_no,
            'status'      => $status,
            'status_text' => $status_text,
            'amount_text' => $amount_text,
            'created_at'  => isset($order->created_at) ? (string) $order->created_at : '',
            'paid_at'     => isset($order->paid_at) ? (string) $order->paid_at : '',
            'item_title'  => $item_title,
            'scope_label' => $scope_label,
            'detail_url'  => $detail_url,
        ]);
    }

    /**
     * 支付补单兜底：异步通知偶发失败时，主动查单并补记订单状态。
     *
     * @param string $order_no 订单号
     * @param string $gateway  网关标识（当前支持 alipay/xhpay）
     * @return bool
     */
    private function recover_gateway_paid_order($order_no, $gateway) {
        if (!class_exists('QilingShop_Payment') || !class_exists('QilingShop_REST_API')) {
            return false;
        }

        $gateway = sanitize_key((string) $gateway);
        if (!in_array($gateway, ['alipay', 'xhpay'], true)) {
            return false;
        }

        // 轮询接口节流，避免高频查单压垮网关接口
        $throttle_key = 'qilingshop_' . $gateway . '_q_' . md5((string) $order_no);
        if (get_transient($throttle_key)) {
            return false;
        }
        set_transient($throttle_key, 1, 8);

        $query = QilingShop_Payment::instance()->query_payment($order_no, $gateway);
        if (empty($query['success']) || empty($query['paid'])) {
            return false;
        }

        $paid_amount = isset($query['total_amount']) ? (float) $query['total_amount'] : 0;
        if ($paid_amount <= 0) {
            return false;
        }

        $transaction_id = sanitize_text_field((string) ($query['transaction_id'] ?? ''));
        $processed = QilingShop_REST_API::instance()->process_payment_success(
            $order_no,
            $paid_amount,
            $gateway,
            $transaction_id
        );

        return $processed === 'success';
    }

    /**
     * 安全下载 - 验证权限后返回真实下载地址
     */
    public function handle_get_download() {
        $this->verify_nonce();
        
        $token = sanitize_text_field($_POST['token'] ?? '');
        
        if (empty($token)) {
            qilingshop_json_error(__('无效的下载请求', 'qilingshop'));
        }
        
        // 解密下载令牌
        $data = qilingshop_security()->decrypt_download_url($token);
        
        if (!$data) {
            qilingshop_json_error(__('下载链接已失效', 'qilingshop'));
        }
        
        $post_id = intval($data['post_id']);
        $url = $data['url'];
        $time = intval($data['time'] ?? 0);
        $download_index = isset($data['index']) ? max(0, intval($data['index'])) : 0;
        
        // 检查链接是否过期（24小时）
        if ($time > 0 && (time() - $time) > 86400) {
            qilingshop_json_error(__('下载链接已过期，请刷新页面重试', 'qilingshop'));
        }
        
        $user_id = get_current_user_id();
        
        // 验证购买权限
        $has_access = false;
        
        $resource = QilingShop_Resource::instance();
        $sale_mode = $resource->get_sale_mode($post_id);
        $price = $resource->get_points_price($post_id, 'download');
        $price_rmb = $resource->get_rmb_price($post_id, 'download');
        $context = 'download';

        if ($resource->is_vip_only_access($post_id, $context)) {
            if (!$user_id || !$resource->has_vip_access($post_id, $user_id, $context)) {
                qilingshop_json_error(__('该资源仅限 VIP 下载', 'qilingshop'));
            }
        }
        if ($sale_mode === 'free') {
            $has_access = true;
        } elseif ($price <= 0 && $price_rmb <= 0 && $user_id) {
            $has_access = true;
        }
        
        // 检查是否已购买
        if (!$has_access && $user_id) {
            $has_access = QilingShop_Order::instance()->user_has_purchased($post_id, $user_id, false, 'download', $download_index);
        }
        
        // 检查 VIP 是否免费
        $is_vip_free = false;
        if (!$has_access && $user_id) {
            if ($resource->is_vip_free($post_id, $user_id, $context)) {
                $has_access = true;
                $is_vip_free = true;
            }
        }
        
        // 检查游客购买
        $guest_id = '';
        if (!$has_access && !$user_id && QilingShop_Guest::instance()->is_enabled()) {
            $guest_id = QilingShop_Guest::instance()->get_guest_id();
            $has_access = QilingShop_Order::instance()->guest_has_purchased($post_id, $guest_id, 'download', $download_index);
        }
        
        if (!$has_access) {
            qilingshop_json_error(__('您无权访问此资源', 'qilingshop'));
        }

        if ($is_vip_free && $user_id) {
            $limit = QilingShop_VIP::instance()->get_daily_download_limit($user_id);
            if ($limit === 0) {
                qilingshop_json_error(__('今日 VIP 下载次数已用完', 'qilingshop'));
            }
            if ($limit > 0) {
                global $wpdb;
                $db = QilingShop_Database::instance();
                $table = $db->get_table('downloads');
                $start = current_time('Y-m-d') . ' 00:00:00';
                $count = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND is_vip_free = 1 AND created_at >= %s",
                    $user_id,
                    $start
                ));
                if ($count >= $limit) {
                    qilingshop_json_error(__('今日 VIP 下载次数已用完', 'qilingshop'));
                }
            }
        }
        
        // 记录下载
        QilingShop_Resource::instance()->log_download($post_id, $user_id, $guest_id, $download_index, $is_vip_free);
        
        // 返回真实下载地址
        qilingshop_json_success([
            'url' => $url,
        ]);
    }

    /**
     * 提交提现申请
     */
    public function handle_submit_withdraw() {
        $this->verify_nonce();
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            qilingshop_json_error(__('请先登录', 'qilingshop'));
        }
        
        // 检查提现功能是否启用
        if (!get_option('qilingshop_withdraw_enabled', false)) {
            qilingshop_json_error(__('提现功能未启用', 'qilingshop'));
        }
        
        $amount = floatval($_POST['amount'] ?? 0);
        $account_type = sanitize_text_field($_POST['account_type'] ?? 'alipay');
        $account_name = sanitize_text_field($_POST['account_name'] ?? '');
        $account_no = sanitize_text_field($_POST['account_no'] ?? '');
        
        // 验证输入
        if ($amount <= 0) {
            qilingshop_json_error(__('请输入有效的提现金额', 'qilingshop'));
        }
        if (empty($account_name) || empty($account_no)) {
            qilingshop_json_error(__('请填写完整的收款信息', 'qilingshop'));
        }
        
        // 检查最低提现金额
        $min_amount = floatval(get_option('qilingshop_withdraw_min_amount', 100));
        if ($amount < $min_amount) {
            qilingshop_json_error(sprintf(__('最低提现金额为￥%s', 'qilingshop'), $min_amount));
        }
        
        // 检查可提现余额
        $points = QilingShop_Points::instance();
        $user_info = $points->get_user_info($user_id);
        $withdrawable = $user_info ? floatval($user_info->withdrawable_balance) : 0;
        
        if ($amount > $withdrawable) {
            qilingshop_json_error(__('可提现余额不足', 'qilingshop'));
        }
        
        // 计算手续费
        $fee_rate = floatval(get_option('qilingshop_withdraw_fee_rate', 0));
        $fee = $amount * $fee_rate / 100;
        $actual_amount = $amount - $fee;
        
        $db = QilingShop_Database::instance();
        global $wpdb;
        
        // 保存用户提现账户信息
        update_user_meta($user_id, '_qilingshop_withdraw_account_type', $account_type);
        update_user_meta($user_id, '_qilingshop_withdraw_account_name', $account_name);
        update_user_meta($user_id, '_qilingshop_withdraw_account_no', $account_no);

        $user_info_table = $db->get_table('user_info');
        $db->begin_transaction();

        try {
            $locked_balance = $wpdb->get_var($wpdb->prepare(
                "SELECT withdrawable_balance FROM {$user_info_table} WHERE user_id = %d FOR UPDATE",
                $user_id
            ));

            if ($locked_balance === null) {
                throw new Exception(__('用户信息不存在', 'qilingshop'));
            }

            if ((float) $locked_balance < $amount) {
                throw new Exception(__('可提现余额不足', 'qilingshop'));
            }

            $withdraw_id = $db->insert('withdrawals', [
                'user_id'       => $user_id,
                'amount'        => $amount,
                'fee'           => $fee,
                'actual_amount' => $actual_amount,
                'account_type'  => $account_type,
                'account_name'  => $account_name,
                'account_no'    => $account_no,
                'status'        => 0, // 待审核
                'ip_address'    => qilingshop_security()->get_client_ip(),
                'created_at'    => current_time('mysql'),
            ]);

            if (!$withdraw_id) {
                throw new Exception(__('提交失败，请重试', 'qilingshop'));
            }

            $affected = $wpdb->query($wpdb->prepare(
                "UPDATE {$user_info_table}
                 SET withdrawable_balance = withdrawable_balance - %f
                 WHERE user_id = %d AND withdrawable_balance >= %f",
                $amount,
                $user_id,
                $amount
            ));

            if ($affected !== 1) {
                throw new Exception(__('可提现余额不足', 'qilingshop'));
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            qilingshop_log('Submit withdraw failed: ' . $e->getMessage(), 'error', [
                'user_id' => $user_id,
                'amount'  => $amount,
            ]);
            qilingshop_json_error($e->getMessage());
        }

        // 清除缓存
        $points->clear_user_cache($user_id);
        if (class_exists('QilingShop_Affiliate')) {
            QilingShop_Affiliate::instance()->refresh_invite_stats_cache($user_id);
        }

        /**
         * 提现申请提交完成
         *
         * @param int   $withdraw_id  提现ID
         * @param int   $user_id      用户ID
         * @param float $amount       提现金额
         * @param float $fee          手续费
         * @param float $actual_amount 实际到账金额
         */
        do_action('qilingshop_withdraw_submitted', (int) $withdraw_id, (int) $user_id, (float) $amount, (float) $fee, (float) $actual_amount);
        
        qilingshop_json_success(null, __('提现申请已提交，请等待审核', 'qilingshop'));
    }

    /**
     * 获取邀请明细（懒加载）
     */
    public function handle_invite_list() {
        $this->verify_account_nonce();
        $this->require_login();

        $user_id = get_current_user_id();
        $paged = isset($_POST['paged']) ? max(1, intval($_POST['paged'])) : 1;
        $per_page = 10;

        $affiliate = QilingShop_Affiliate::instance();
        $invites = $affiliate->get_invite_list($user_id, [
            'limit'  => $per_page,
            'offset' => ($paged - 1) * $per_page,
        ]);
        $total = $affiliate->get_invite_total($user_id);
        $total_pages = (int) ceil($total / $per_page);

        $points_name = qilingshop_get_points_name();
        $base_url = '';
        if (!empty($_POST['base_url'])) {
            $base_url = esc_url_raw(wp_unslash($_POST['base_url']));
        }

        $html = qilingshop_get_template('account/partials/invite-list', [
            'invites' => $invites,
            'points_name' => $points_name,
            'paged' => $paged,
            'total_pages' => $total_pages,
            'base_url' => $base_url,
        ], false);

        qilingshop_json_success([
            'html' => $html,
            'total' => $total,
            'total_pages' => $total_pages,
        ]);
    }

    /**
     * 获取佣金明细（懒加载）
     */
    public function handle_commission_log() {
        $this->verify_account_nonce();
        $this->require_login();

        $user_id = get_current_user_id();
        $paged = isset($_POST['paged']) ? max(1, intval($_POST['paged'])) : 1;
        $per_page = 10;

        $affiliate = QilingShop_Affiliate::instance();
        $commissions = $affiliate->get_commission_log($user_id, [
            'limit'  => $per_page,
            'offset' => ($paged - 1) * $per_page,
        ]);
        $total = $affiliate->get_commission_total($user_id);
        $total_pages = (int) ceil($total / $per_page);

        $base_url = '';
        if (!empty($_POST['base_url'])) {
            $base_url = esc_url_raw(wp_unslash($_POST['base_url']));
        }

        $html = qilingshop_get_template('account/partials/commission-log', [
            'commissions' => $commissions,
            'paged' => $paged,
            'total_pages' => $total_pages,
            'base_url' => $base_url,
        ], false);

        qilingshop_json_success([
            'html' => $html,
            'total' => $total,
            'total_pages' => $total_pages,
        ]);
    }

    /**
     * 保存收货地址
     */
    public function handle_save_address() {
        $this->verify_nonce();
        $this->require_login();
        
        $data = [
            'user_id'  => get_current_user_id(),
            'name'     => sanitize_text_field($_POST['name'] ?? ''),
            'phone'    => sanitize_text_field($_POST['phone'] ?? ''),
            'province' => sanitize_text_field($_POST['province'] ?? ''),
            'city'     => sanitize_text_field($_POST['city'] ?? ''),
            'district' => sanitize_text_field($_POST['district'] ?? ''),
            'address'  => sanitize_textarea_field($_POST['address'] ?? ''),
            'is_default' => isset($_POST['is_default']) ? 1 : 0,
        ];
        
        if (empty($data['name']) || empty($data['phone']) || empty($data['address'])) {
            qilingshop_json_error(__('请填写完整信息', 'qilingshop'));
        }

        $user_id = (int) $data['user_id'];
        $address_id = intval($_POST['address_id'] ?? 0);
        $lock_name = $this->build_user_address_lock_name($user_id);

        if (!$this->acquire_named_lock($lock_name, 5)) {
            qilingshop_json_error(__('地址保存处理中，请勿重复提交', 'qilingshop'));
        }
        
        $db = $this->get_shop_db();
        if (!$db) {
            $this->release_named_lock($lock_name);
            qilingshop_json_error(__('商城地址服务不可用', 'qilingshop'));
        }

        $result = false;
        $error_message = '';
        
        try {
            $wpdb = $db->get_wpdb();
            $wpdb->query('START TRANSACTION');

            // 如果设为默认，取消其他默认地址
            if ($data['is_default']) {
                $reset_default = $db->update('user_addresses', ['is_default' => 0], ['user_id' => $user_id]);
                if ($reset_default === false) {
                    throw new Exception(__('保存失败', 'qilingshop'));
                }
            }
            
            if ($address_id > 0) {
                // 更新
                // 验证权属
                $exists = $db->get_row('user_addresses', ['id' => $address_id, 'user_id' => $user_id]);
                if (!$exists) {
                    throw new Exception(__('地址不存在', 'qilingshop'));
                } else {
                    $result = $db->update('user_addresses', $data, ['id' => $address_id, 'user_id' => $user_id]);
                    if ($result === false) {
                        throw new Exception(__('保存失败', 'qilingshop'));
                    }
                }
            } else {
                // 如果是第一条地址，自动设为默认
                $count = $db->count('user_addresses', ['user_id' => $user_id]);
                if ($count == 0) {
                    $data['is_default'] = 1;
                }
                
                $result = $db->insert('user_addresses', $data);
                $address_id = $result;
                if (!$result) {
                    throw new Exception(__('保存失败', 'qilingshop'));
                }
            }
            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $db->get_wpdb()->query('ROLLBACK');
            $error_message = $e->getMessage() ?: __('保存失败', 'qilingshop');
        } finally {
            $this->release_named_lock($lock_name);
        }

        if ($error_message !== '') {
            qilingshop_json_error($error_message);
        }
        
        if ($result !== false) {
            qilingshop_json_success(['id' => $address_id], __('保存成功', 'qilingshop'));
        } else {
            qilingshop_json_error(__('保存失败', 'qilingshop'));
        }
    }

    /**
     * 删除收货地址
     */
    public function handle_delete_address() {
        $this->verify_nonce();
        $this->require_login();
        
        $user_id = get_current_user_id();
        $address_id = intval($_POST['address_id'] ?? 0);
        $lock_name = $this->build_user_address_lock_name($user_id);
        
        if (!$address_id) {
            qilingshop_json_error(__('参数错误', 'qilingshop'));
        }
        
        $db = $this->get_shop_db();
        if (!$db) {
            qilingshop_json_error(__('商城地址服务不可用', 'qilingshop'));
        }

        if (!$this->acquire_named_lock($lock_name, 5)) {
            qilingshop_json_error(__('地址处理中，请勿重复提交', 'qilingshop'));
        }
        $result = false;
        $error_message = '';
        
        try {
            // 验证权属
            $exists = $db->get_row('user_addresses', ['id' => $address_id, 'user_id' => $user_id]);
            if (!$exists) {
                $error_message = __('地址不存在', 'qilingshop');
            } else {
                $result = $db->delete('user_addresses', ['id' => $address_id, 'user_id' => $user_id]);
            }
        } finally {
            $this->release_named_lock($lock_name);
        }

        if ($error_message !== '') {
            qilingshop_json_error($error_message);
        }
        
        if ($result !== false && (int) $result >= 1) {
            qilingshop_json_success(null, __('删除成功', 'qilingshop'));
        } else {
            qilingshop_json_error(__('删除失败', 'qilingshop'));
        }
    }

    /**
     * 获取指定下载项。
     *
     * @param int $post_id 文章 ID。
     * @param int $download_index 下载项索引。
     * @return array|null
     */
    private function get_pancheck_download_item($post_id, $download_index) {
        $urls = QilingShop_Resource::instance()->get_download_urls((int) $post_id);
        foreach ((array) $urls as $index => $item) {
            $item_index = isset($item['index']) ? (int) $item['index'] : (int) $index;
            if ($item_index === (int) $download_index) {
                return $item;
            }
        }

        return null;
    }

    /**
     * 判断当前用户是否有权检测指定下载项。
     *
     * @param int $post_id 文章 ID。
     * @param int $user_id 用户 ID。
     * @param int $download_index 下载项索引。
     * @return bool
     */
    private function user_can_pancheck_download_item($post_id, $user_id, $download_index) {
        $post_id = (int) $post_id;
        $user_id = (int) $user_id;
        $download_index = max(0, (int) $download_index);

        if ($post_id <= 0 || $user_id <= 0) {
            return false;
        }

        $resource = QilingShop_Resource::instance();
        if ($resource->get_sale_mode($post_id) === 'free') {
            return true;
        }

        $price_info = $resource->get_price($post_id, $user_id, 'download');
        if ((float) ($price_info['points'] ?? 0) <= 0 && (float) ($price_info['rmb'] ?? 0) <= 0) {
            return true;
        }

        return QilingShop_Order::instance()->user_has_purchased($post_id, $user_id, true, 'download', $download_index);
    }

    /**
     * 网盘链接检测
     */
    public function handle_pancheck() {
        $this->verify_nonce();

        if (!is_user_logged_in()) {
            qilingshop_json_error(__('请先登录', 'qilingshop'));
        }
        
        // 检查功能是否启用
        if (!get_option('qilingshop_pancheck_enabled', false)) {
            qilingshop_json_error(__('检测功能未启用', 'qilingshop'));
        }

        $user_id = (int) get_current_user_id();
        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        $post_id = isset($_POST['post_id']) ? absint(wp_unslash($_POST['post_id'])) : 0;
        $download_index = isset($_POST['download_index']) ? max(0, (int) wp_unslash($_POST['download_index'])) : 0;
        $token_url = '';

        if (!empty($token)) {
            $data = qilingshop_security()->decrypt_download_url($token);
            if (!$data || empty($data['url']) || empty($data['post_id'])) {
                qilingshop_json_error(__('链接不能为空或失效', 'qilingshop'));
            }

            $token_post_id = (int) $data['post_id'];
            $token_index = isset($data['index']) ? max(0, (int) $data['index']) : 0;
            if ($post_id > 0 && $post_id !== $token_post_id) {
                qilingshop_json_error(__('链接不能为空或失效', 'qilingshop'));
            }
            $post_id = $token_post_id;
            $download_index = $token_index;
            $token_url = (string) $data['url'];
        }

        if ($post_id <= 0) {
            qilingshop_json_error(__('参数错误', 'qilingshop'));
        }

        if (!$this->user_can_pancheck_download_item($post_id, $user_id, $download_index)) {
            qilingshop_json_error(__('购买后可检测链接有效性', 'qilingshop'));
        }

        $item = $this->get_pancheck_download_item($post_id, $download_index);
        if (!$item || empty($item['url'])) {
            qilingshop_json_error(__('链接不能为空或失效', 'qilingshop'));
        }

        $url = (string) $item['url'];
        if ($token_url !== '' && !hash_equals($url, $token_url)) {
            qilingshop_json_error(__('链接不能为空或失效', 'qilingshop'));
        }

        if (!QilingShop_Pancheck::is_supported($url)) {
            qilingshop_json_error(__('当前网盘不支持检测', 'qilingshop'));
        }

        $code = isset($item['code']) ? (string) $item['code'] : '';
        $rate_key = 'pancheck:user:' . $user_id . ':' . md5(strtolower($url) . '|' . $code);
        if (!qilingshop_security()->rate_limit($rate_key, 3, 60)) {
            qilingshop_json_error(__('检测过于频繁，请稍后再试', 'qilingshop'));
        }

        $global_key = 'pancheck:user:' . $user_id . ':global';
        if (!qilingshop_security()->rate_limit($global_key, 12, 300)) {
            qilingshop_json_error(__('检测次数过多，请稍后再试', 'qilingshop'));
        }
        
        $result = QilingShop_Pancheck::check($url, $code);
        
        if ($result['success']) {
            qilingshop_json_success([
                'status'  => $result['status'],
                'message' => $result['message'],
            ]);
        } else {
            qilingshop_json_error($result['message']);
        }
    }
}
