<?php
/**
 * 优惠券前台AJAX处理类
 * 
 * @package QilingShop
 * @since   2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_Coupon_Ajax {

    /**
     * 单例实例
     */
    private static $instance = null;

    /**
     * 获取单例实例
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
        // 领取优惠券
        add_action('wp_ajax_qls_claim_coupon', [$this, 'handle_claim_coupon']);
        
        // 验证优惠码
        add_action('wp_ajax_qls_validate_coupon', [$this, 'handle_validate_coupon']);
        add_action('wp_ajax_nopriv_qls_validate_coupon', [$this, 'handle_validate_coupon']);
        
        // 获取可用优惠券列表
        add_action('wp_ajax_qls_get_available_coupons', [$this, 'handle_get_available_coupons']);
        
        // 获取我的优惠券
        add_action('wp_ajax_qls_get_my_coupons', [$this, 'handle_get_my_coupons']);
        
        // 获取公开优惠券列表
        add_action('wp_ajax_qls_get_public_coupons', [$this, 'handle_get_public_coupons']);
        add_action('wp_ajax_nopriv_qls_get_public_coupons', [$this, 'handle_get_public_coupons']);
    }

    /**
     * 获取优惠券管理实例
     */
    private function get_coupon_manager() {
        if (!class_exists('QLS_Coupon')) {
            require_once QILINGSHOP_PATH . 'includes/shop/class-qls-coupon.php';
        }
        return QLS_Coupon::instance();
    }

    /**
     * 公共读取接口防护（nonce + 频率限制）
     *
     * @param string $scope 限流作用域
     * @param string $nonce_action nonce action
     * @param int    $max 每时间窗最大请求数
     * @param int    $interval 时间窗（秒）
     * @return void
     */
    private function guard_public_read_request($scope, $nonce_action = 'qls_coupon_nonce', $max = 60, $interval = 60) {
        $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, $nonce_action)) {
            wp_send_json_error(['message' => __('安全验证失败', 'qilingshop')], 403);
        }

        if (!function_exists('qilingshop_security')) {
            return;
        }

        $ip = qilingshop_security()->get_client_ip();
        $rate_key = 'qls_coupon_public_' . sanitize_key((string) $scope) . '_' . md5($ip);
        $allowed = qilingshop_security()->rate_limit($rate_key, (int) $max, (int) $interval);
        if (!$allowed) {
            wp_send_json_error(['message' => __('请求过于频繁，请稍后重试', 'qilingshop')], 429);
        }
    }

    /**
     * 处理领取优惠券
     */
    public function handle_claim_coupon() {
        check_ajax_referer('qls_coupon_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('请先登录', 'qilingshop')]);
        }

        $coupon_id = isset($_POST['coupon_id']) ? intval($_POST['coupon_id']) : 0;
        if (!$coupon_id) {
            wp_send_json_error(['message' => __('参数错误', 'qilingshop')]);
        }

        $coupon_manager = $this->get_coupon_manager();
        $result = $coupon_manager->claim($coupon_id, $user_id);

        if ($result['success']) {
            wp_send_json_success([
                'message' => $result['message'],
                'claim_id' => $result['claim_id'],
            ]);
        } else {
            wp_send_json_error(['message' => $result['message']]);
        }
    }

    /**
     * 处理验证优惠码
     */
    public function handle_validate_coupon() {
        check_ajax_referer('qls_coupon_nonce', 'nonce');

        $code = isset($_POST['code']) ? sanitize_text_field($_POST['code']) : '';
        $order_type = isset($_POST['order_type']) ? sanitize_key($_POST['order_type']) : '';
        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        $items = isset($_POST['items']) ? array_map('intval', (array)$_POST['items']) : [];

        if (empty($code)) {
            wp_send_json_error(['message' => __('请输入优惠码', 'qilingshop')]);
        }

        $coupon_manager = $this->get_coupon_manager();
        $coupon = $coupon_manager->get_by_code($code);

        if (!$coupon) {
            wp_send_json_error(['message' => __('优惠码不存在', 'qilingshop')]);
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('请先登录后使用优惠券', 'qilingshop')]);
        }

        // 检查用户是否已领取
        global $wpdb;
        $prefix = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX;
        $claim = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}coupon_claims WHERE coupon_id = %d AND user_id = %d AND status = 0 LIMIT 1",
            $coupon->id,
            $user_id
        ));

        if (!$claim) {
            // 尝试自动领取
            if ($user_id) {
                $claim_result = $coupon_manager->claim($coupon->id, $user_id);
                if (!$claim_result['success']) {
                    wp_send_json_error(['message' => $claim_result['message']]);
                }
                $claim_id = $claim_result['claim_id'];
            } else {
                wp_send_json_error(['message' => __('请先登录后使用优惠券', 'qilingshop')]);
            }
        } else {
            $claim_id = $claim->id;
        }

        // 验证优惠券是否可用
        $validation = $coupon_manager->validate($claim_id, $order_type, $amount, $items, (int) $user_id);

        if (!$validation['valid']) {
            wp_send_json_error(['message' => $validation['message']]);
        }

        // 计算优惠金额
        $discount = $coupon_manager->calculate_discount($claim_id, $amount);

        wp_send_json_success([
            'claim_id' => $claim_id,
            'coupon' => [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'name' => $coupon->name,
                'discount_type' => $coupon->discount_type,
                'discount_value' => $coupon->discount_value,
            ],
            'discount_amount' => $discount,
            'final_amount' => max(0, $amount - $discount),
        ]);
    }

    /**
     * 获取可用优惠券列表（包含不可用的优惠券，前端显示状态）
     */
    public function handle_get_available_coupons() {
        check_ajax_referer('qls_coupon_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('请先登录', 'qilingshop')]);
        }

        $order_type = isset($_POST['order_type']) ? sanitize_key($_POST['order_type']) : 'all';
        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        $items = isset($_POST['items']) ? array_map('intval', (array)$_POST['items']) : [];

        $coupon_manager = $this->get_coupon_manager();
        // 获取所有优惠券（包含不可用的）
        $coupons = $coupon_manager->get_available_coupons($user_id, $order_type, $amount, $items, true);

        $result = [];
        foreach ($coupons as $coupon) {
            $result[] = [
                'claim_id' => $coupon->id,
                'coupon_id' => $coupon->coupon_id,
                'code' => $coupon->code,
                'name' => $coupon->name,
                'description' => $coupon->description,
                'discount_type' => $coupon->discount_type,
                'discount_value' => $coupon->discount_value,
                'max_discount' => $coupon->max_discount,
                'min_amount' => $coupon->min_amount,
                'discount_amount' => $coupon->discount_amount,
                'expires_at' => $coupon->expires_at,
                'apply_scope' => $coupon->apply_scope ?? 'all',
                // 新增：可用状态和不可用原因
                'is_valid' => $coupon->is_valid ?? true,
                'invalid_reason' => $coupon->invalid_reason ?? '',
            ];
        }

        wp_send_json_success(['coupons' => $result]);
    }

    /**
     * 获取我的优惠券
     */
    public function handle_get_my_coupons() {
        check_ajax_referer('qls_coupon_nonce', 'nonce');

        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('请先登录', 'qilingshop')]);
        }

        $status = isset($_POST['status']) ? sanitize_key($_POST['status']) : 'all';

        $coupon_manager = $this->get_coupon_manager();
        $coupons = $coupon_manager->get_user_coupons($user_id, $status);

        $result = [];
        foreach ($coupons as $coupon) {
            $result[] = [
                'claim_id' => $coupon->id,
                'coupon_id' => $coupon->coupon_id,
                'code' => $coupon->code,
                'name' => $coupon->name,
                'description' => $coupon->description,
                'discount_type' => $coupon->discount_type,
                'discount_value' => $coupon->discount_value,
                'max_discount' => $coupon->max_discount,
                'min_amount' => $coupon->min_amount,
                'apply_scope' => $coupon->apply_scope,
                'status' => $coupon->status,
                'expires_at' => $coupon->expires_at,
                'used_at' => $coupon->used_at,
            ];
        }

        wp_send_json_success(['coupons' => $result]);
    }

    /**
     * 获取公开优惠券列表
     */
    public function handle_get_public_coupons() {
        $this->guard_public_read_request('get_public_coupons');

        $user_id = get_current_user_id();
        $limit = isset($_REQUEST['limit']) ? max(0, min(100, intval($_REQUEST['limit']))) : 0;
        
        $coupon_manager = $this->get_coupon_manager();
        $coupons = $coupon_manager->get_public_coupons($user_id, $limit);

        $result = [];
        foreach ($coupons as $coupon) {
            $result[] = [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'name' => $coupon->name,
                'description' => $coupon->description,
                'discount_type' => $coupon->discount_type,
                'discount_value' => $coupon->discount_value,
                'max_discount' => $coupon->max_discount,
                'min_amount' => $coupon->min_amount,
                'apply_scope' => $coupon->apply_scope,
                'claim_type' => $coupon->claim_type,
                'can_claim' => $coupon->can_claim,
                'claim_reason' => $coupon->claim_reason,
                'is_claimed' => $coupon->is_claimed,
                'end_time' => $coupon->end_time,
                'total_count' => $coupon->total_count,
                'claimed_count' => $coupon->claimed_count,
            ];
        }

        wp_send_json_success(['coupons' => $result]);
    }
}

/**
 * 获取优惠券AJAX实例
 */
function qls_coupon_ajax() {
    return QLS_Coupon_Ajax::instance();
}
