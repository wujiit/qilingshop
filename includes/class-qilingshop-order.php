<?php
/**
 * 订单管理类
 *
 * @package QilingShop
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Order {
    const DOWNLOAD_ALL_INDEX = -1;

    /**
     * 单例实例
     */
    private static $instance = null;

    /**
     * 数据库实例
     */
    private $db;

    /**
     * 订单状态常量
     */
    const STATUS_PENDING = 0;
    const STATUS_PAID = 1;
    const STATUS_CANCELLED = 2;
    const STATUS_REFUNDED = 3;

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
     * 创建订单
     */
    public function create($data) {
        $defaults = [
            'order_no'      => qilingshop_security()->generate_order_no(),
            'user_id'       => get_current_user_id(),
            'guest_id'      => '',
            'post_id'       => 0,
            'post_title'    => '',
            'order_type'    => 'resource',
            'price_points'  => 0,
            'price_rmb'     => 0,
            'discount_amount' => 0,
            'final_price'   => 0,
            'payment_method'=> '',
            'status'        => self::STATUS_PENDING,
            'ip_address'    => qilingshop_security()->get_client_ip(),
            'user_agent'    => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
            'created_at'    => current_time('mysql'),
        ];

        $data = wp_parse_args($data, $defaults);

        // 获取文章标题
        if ($data['post_id'] && empty($data['post_title'])) {
            $data['post_title'] = get_the_title($data['post_id']);
        }

        /**
         * 订单创建前
         */
        $data = apply_filters('qilingshop_before_create_order', $data);

        $order_id = $this->db->insert('orders', $data);

        if ($order_id) {
            do_action('qilingshop_order_created', $order_id, $data);
        }

        return $order_id ? $data['order_no'] : false;
    }

    /**
     * 根据订单号获取订单
     */
    public function get_by_order_no($order_no) {
        return $this->db->get_row('orders', ['order_no' => $order_no]);
    }

    /**
     * 事务内按订单号锁定订单
     *
     * @param string $order_no
     * @return object|null
     */
    private function get_by_order_no_for_update($order_no) {
        global $wpdb;

        $table = $this->db->get_table('orders');
        $sql = $this->db->prepare(
            "SELECT * FROM {$table} WHERE order_no = %s LIMIT 1 FOR UPDATE",
            $order_no
        );

        return $wpdb->get_row($sql);
    }

    /**
     * 获取数据库级命名锁，串行化高冲突购买动作。
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
     * 构造资源购买互斥锁名。
     *
     * @param int $user_id
     * @param int $post_id
     * @return string
     */
    private function build_resource_purchase_lock_name($user_id, $post_id, $download_index = null) {
        $download_index = $this->normalize_download_index($download_index, true);
        if ($download_index === null) {
            $download_index = self::DOWNLOAD_ALL_INDEX;
        }
        return 'qlsres_' . md5((int) $user_id . ':' . (int) $post_id . ':' . (int) $download_index);
    }

    /**
     * 构造虚拟资源订单写操作互斥锁名。
     *
     * @param int $order_id
     * @return string
     */
    private function build_order_write_lock_name($order_id) {
        return 'qlsvord_' . md5((string) absint($order_id));
    }

    /**
     * 释放虚拟订单占用的商城优惠券。
     *
     * @param object $order
     * @param bool   $use_transaction
     * @return bool
     */
    private function release_coupon_reservation_for_order($order, $use_transaction = true, $restore_used = false) {
        if (!class_exists('QLS_Coupon') || !is_object($order) || empty($order->remark)) {
            return true;
        }

        $remark_data = json_decode((string) $order->remark, true);
        $coupon_claim_id = is_array($remark_data) ? absint($remark_data['coupon_claim_id'] ?? 0) : 0;
        if ($coupon_claim_id <= 0 || (float) ($order->discount_amount ?? 0) <= 0) {
            return true;
        }

        if ($restore_used && method_exists('QLS_Coupon', 'release_or_restore_for_order')) {
            return QLS_Coupon::release_or_restore_for_order($coupon_claim_id, (string) $order->order_no, $use_transaction);
        }

        return QLS_Coupon::release_reservation($coupon_claim_id, (string) $order->order_no, $use_transaction);
    }

    /**
     * 根据 ID 获取订单
     */
    public function get($order_id) {
        return $this->db->get_by_id('orders', $order_id);
    }

    /**
     * 安全删除待支付订单。
     *
     * 仅允许删除待支付订单，避免与支付回调/权益发放并发冲突。
     *
     * @param int $order_id
     * @return bool
     */
    public function delete_pending($order_id) {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return false;
        }

        $order = $this->get($order_id);
        if (!$order) {
            return false;
        }

        $lock_name = $this->build_order_write_lock_name($order_id);
        if (!$this->acquire_named_lock($lock_name, 5)) {
            return false;
        }

        try {
            $this->db->begin_transaction();

            $latest = $this->get($order_id);
            if (!$latest || (int) $latest->status !== self::STATUS_PENDING) {
                $this->db->rollback();
                return false;
            }

            if (!$this->release_coupon_reservation_for_order($latest, false)) {
                throw new Exception('Failed to release coupon reservation');
            }

            $deleted = $this->db->delete('orders', [
                'id'     => $order_id,
                'status' => self::STATUS_PENDING,
            ]);

            if ($deleted === false || (int) $deleted !== 1) {
                throw new Exception('Failed to delete pending order');
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            qilingshop_log('Delete pending resource order failed: ' . $e->getMessage(), 'error', [
                'order_id' => $order_id,
            ]);
            return false;
        } finally {
            $this->release_named_lock($lock_name);
        }
    }

    /**
     * 取消未完成的 0 元内部订单，并回滚本订单优惠券占用。
     *
     * @param string $order_no
     * @return bool
     */
    public function cancel_failed_internal_order($order_no) {
        $order_no = sanitize_text_field((string) $order_no);
        if ($order_no === '') {
            return false;
        }

        $order = $this->get_by_order_no($order_no);
        if (!$order || empty($order->id)) {
            return false;
        }

        $lock_name = $this->build_order_write_lock_name((int) $order->id);
        if (!$this->acquire_named_lock($lock_name, 5)) {
            return false;
        }

        try {
            $this->db->begin_transaction();

            $latest = $this->get_by_order_no($order_no);
            if (!$latest || (int) ($latest->paid_handled ?? 0) === 1 || (float) ($latest->final_price ?? 0) > 0) {
                $this->db->rollback();
                return false;
            }

            if (!$this->release_coupon_reservation_for_order($latest, false, true)) {
                throw new Exception('Failed to release coupon reservation');
            }

            $updated = $this->db->update('orders', [
                'status'  => self::STATUS_CANCELLED,
                'paid_at' => null,
            ], [
                'id'           => (int) $latest->id,
                'paid_handled' => 0,
            ]);

            if ($updated === false || (int) $updated !== 1) {
                throw new Exception('Failed to cancel failed internal order');
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            qilingshop_log('Cancel failed internal order failed: ' . $e->getMessage(), 'error', [
                'order_no' => $order_no,
            ]);
            return false;
        } finally {
            $this->release_named_lock($lock_name);
        }
    }

    /**
     * 标记订单为已支付
     */
    public function mark_paid($order_no, $payment_no = '', $payment_method = '') {
        $order = $this->get_by_order_no($order_no);

        if (!$order) {
            return false;
        }

        $lock_name = $this->build_order_write_lock_name((int) $order->id);
        if (!$this->acquire_named_lock($lock_name, 5)) {
            return false;
        }

        try {
            $order = $this->get_by_order_no($order_no);
            if (!$order) {
                return false;
            }

            if ((int) $order->status === self::STATUS_PAID) {
                return $this->finalize_paid_order($order, $order_no, $payment_method);
            }

            // 已取消/已退款订单不允许再次标记支付
            if ((int) $order->status !== self::STATUS_PENDING) {
                return false;
            }

            $update_data = [
                'status'         => self::STATUS_PAID,
                'paid_at'        => current_time('mysql'),
            ];

            if ($payment_no) {
                $update_data['payment_no'] = $payment_no;
            }
            if ($payment_method) {
                $update_data['payment_method'] = $payment_method;
            }

            $updated = $this->db->update('orders', $update_data, [
                'order_no' => $order_no,
                'status'   => self::STATUS_PENDING,
            ]);

            if ($updated === 0) {
                $latest = $this->get_by_order_no($order_no);
                if ($latest && (int) $latest->status === self::STATUS_PAID) {
                    return $this->finalize_paid_order($latest, $order_no, $payment_method);
                }
                return false;
            }

            if ($updated !== false) {
                return $this->finalize_paid_order($order, $order_no, $payment_method);
            }

            return false;
        } finally {
            $this->release_named_lock($lock_name);
        }
    }

    /**
     * 订单支付成功后的幂等收尾
     *
     * @param object $order
     * @param string $order_no
     * @param string $payment_method
     * @return bool
     */
    private function finalize_paid_order($order, $order_no, $payment_method = '') {
        if (!$order || empty($order->id)) {
            return false;
        }

        $handled_order_id = 0;

        $this->db->begin_transaction();

        try {
            $locked_order = $this->get_by_order_no_for_update($order_no);
            if (!$locked_order) {
                throw new Exception('Order not found when finalizing payment');
            }

            if ((int) ($locked_order->paid_handled ?? 0) === 1) {
                $this->db->commit();
                return true;
            }

            if (class_exists('QLS_Coupon') && !empty($locked_order->remark)) {
                $remark_data = json_decode($locked_order->remark, true);
                $coupon_claim_id = $remark_data['coupon_claim_id'] ?? 0;
                if ($coupon_claim_id > 0 && (float) $locked_order->discount_amount > 0) {
                    $coupon_used = QLS_Coupon::mark_as_used(
                        $coupon_claim_id,
                        $order_no,
                        $locked_order->order_type,
                        $locked_order->price_rmb,
                        $locked_order->discount_amount,
                        false
                    );
                    if (!$coupon_used) {
                        throw new Exception('Failed to mark coupon as used');
                    }
                }
            }

            $handled = $this->handle_paid_order($locked_order);
            if (!$handled) {
                throw new Exception('Failed to handle paid order');
            }

            $updated = $this->db->update('orders', [
                'paid_handled'    => 1,
                'paid_handled_at' => current_time('mysql'),
            ], [
                'order_no'      => $order_no,
                'paid_handled'  => 0,
            ]);

            if ($updated === false || (int) $updated !== 1) {
                throw new Exception('Failed to mark order as handled');
            }

            $handled_order_id = (int) $locked_order->id;
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            qilingshop_log('Finalize paid order failed: ' . $e->getMessage(), 'error', [
                'order_no' => $order_no,
            ]);
            return false;
        }

        if ($handled_order_id > 0) {
            do_action('qilingshop_order_paid', $handled_order_id, $payment_method);
        }

        return true;
    }

    /**
     * 处理已支付订单
     */
    private function handle_paid_order($order) {
        if (!$order || empty($order->id)) {
            return false;
        }

        // 根据订单类型处理
        if ($order->order_type === 'vip') {
            // VIP购买：解析remark获取level_id并升级VIP
            $remark_data = !empty($order->remark) ? json_decode($order->remark, true) : [];
            $level_id = $remark_data['level_id'] ?? 0;
            
            if ($level_id > 0 && $order->user_id > 0) {
                $already_logged = $this->db->get_row('vip_log', [
                    'user_id'  => (int) $order->user_id,
                    'order_no' => (string) $order->order_no,
                ]);
                if ($already_logged) {
                    do_action('qilingshop_order_completed', $order->id);
                    return true;
                }

                // 调用VIP升级
                $upgrade_result = QilingShop_VIP::instance()->upgrade(
                    $order->user_id,
                    $level_id,
                    'rmb',
                    $order->final_price ?: $order->price_rmb,
                    $order->order_no
                );
                if (empty($upgrade_result['success'])) {
                    return false;
                }
            }
        } elseif (($order->order_type === 'resource' || (empty($order->order_type) && !empty($order->post_id))) && $order->post_id) {
            $download_exists = $this->db->get_row('downloads', [
                'order_no'       => (string) $order->order_no,
                'post_id'        => (int) $order->post_id,
                'download_index' => (int) $order->download_index,
            ]);
            if ($download_exists) {
                do_action('qilingshop_order_completed', $order->id);
                return true;
            }

            // 资源购买：记录下载权限
            $download_id = $this->db->insert('downloads', [
                'user_id'        => $order->user_id,
                'guest_id'       => $order->guest_id,
                'post_id'        => $order->post_id,
                'order_no'       => $order->order_no,
                'download_index' => $order->download_index,
                'ip_address'     => $order->ip_address,
                'created_at'     => current_time('mysql'),
            ]);
            if (!$download_id) {
                return false;
            }
        }

        // 订单返积分（资源订单）
        if (!$this->maybe_reward_order_points($order)) {
            return false;
        }

        // 处理推广提成
        if ($order->user_id > 0) {
            $commission_ok = QilingShop_Affiliate::instance()->process_commission(
                $order->user_id,
                $order->price_rmb ?: qilingshop_points_to_rmb($order->price_points),
                $order->order_type ?: 'resource',
                $order->order_no
            );
            if (!$commission_ok) {
                $queued = QilingShop_Affiliate::instance()->queue_commission_retry(
                    (int) $order->user_id,
                    (float) ($order->price_rmb ?: qilingshop_points_to_rmb($order->price_points)),
                    (string) ($order->order_type ?: 'resource'),
                    (string) $order->order_no,
                    'resource_vip_order'
                );
                qilingshop_log(
                    $queued ? 'Affiliate commission queued for retry after resource/vip order' : 'Affiliate commission retry queue failed after resource/vip order',
                    'error',
                    [
                    'order_no' => (string) $order->order_no,
                    'user_id'  => (int) $order->user_id,
                    ]
                );
            }
        }

        do_action('qilingshop_order_completed', $order->id);
        return true;
    }

    /**
     * 订单返积分（资源订单）
     *
     * @param object $order
     */
    private function maybe_reward_order_points($order) {
        if (!$order || empty($order->user_id)) {
            return true;
        }
        $is_resource_order = ($order->order_type === 'resource') || (empty($order->order_type) && !empty($order->post_id));
        if (!$is_resource_order) {
            return true;
        }
        if (!get_option('qilingshop_order_points_rebate_enabled', false)) {
            return true;
        }
        $paid_amount = (float) ($order->final_price ?? 0);
        if ($paid_amount <= 0) {
            return true;
        }
        $post_id = (int) ($order->post_id ?? 0);
        $category_ids = [];
        if ($post_id > 0) {
            $terms = wp_get_post_terms($post_id, 'category', ['fields' => 'ids']);
            if (!is_wp_error($terms) && !empty($terms)) {
                $category_ids = $terms;
            }
        }
        $rate = qilingshop_get_order_rebate_rate('resource', $category_ids);
        $points = qilingshop_calculate_rebate_points($paid_amount, $rate);
        if ($points <= 0) {
            return true;
        }

        $points_manager = QilingShop_Points::instance();
        if ($points_manager->has_points_log($order->user_id, 'order_rebate', (int) $order->id)) {
            return true;
        }

        return $points_manager->add_points(
            $order->user_id,
            $points,
            'order_rebate',
            sprintf(__('订单返积分：%s', 'qilingshop'), $order->order_no),
            (int) $order->id
        );
    }

    private function refund_failed_points_purchase($user_id, $points, $post_id, $description) {
        $refunded = QilingShop_Points::instance()->add_points(
            (int) $user_id,
            (float) $points,
            'refund',
            (string) $description,
            (int) $post_id
        );

        if (!$refunded && function_exists('qilingshop_log')) {
            qilingshop_log('Resource points refund failed', 'error', [
                'user_id' => (int) $user_id,
                'post_id' => (int) $post_id,
                'points'  => (float) $points,
            ]);
        }

        return (bool) $refunded;
    }

    /**
     * 用积分购买资源
     */
    public function purchase_with_points($user_id, $post_id, $download_index = null, $scope = 'download', $upgrade_from = '') {
        if (is_string($download_index)) {
            $legacy_scope = strtolower(trim($download_index));
            if (in_array($legacy_scope, ['view', 'download', 'any'], true) && ($scope === '' || $scope === 'download')) {
                $scope = $legacy_scope;
                $download_index = null;
            }
        }

        $resource = QilingShop_Resource::instance();
        $sale_mode = $resource->get_sale_mode($post_id);
        if ($sale_mode === 'free') {
            return ['success' => false, 'message' => __('该资源为免费资源，无需购买', 'qilingshop')];
        }
        $scope = $this->normalize_scope($scope);
        if ($scope === 'any') {
            $scope = 'download';
        }
        $upgrade_from = $upgrade_from === 'view' ? 'view' : '';
        $download_index = $this->normalize_download_index($download_index, true);
        if ($download_index === null) {
            $download_index = self::DOWNLOAD_ALL_INDEX;
        }

        $lock_name = $this->build_resource_purchase_lock_name($user_id, $post_id, $download_index);
        if (!$this->acquire_named_lock($lock_name, 5)) {
            return ['success' => false, 'message' => __('请求处理中，请勿重复提交', 'qilingshop')];
        }

        try {
            $purchase_guard = $this->normalize_resource_purchase_request($post_id, $user_id, $scope, $upgrade_from, $download_index);
            $scope = $purchase_guard['scope'];
            $upgrade_from = $purchase_guard['upgrade_from'];
            if (!empty($purchase_guard['blocked'])) {
                return ['success' => false, 'message' => $purchase_guard['message']];
            }

            $context = $scope === 'view' ? 'view' : 'download';
            if ($scope === 'download' && $download_index >= 0 && !$resource->download_index_exists($post_id, $download_index)) {
                return ['success' => false, 'message' => __('下载项不存在', 'qilingshop')];
            }

            $vip_only_purchase = $resource->is_vip_only_purchase($post_id, $context);
            $vip_only_access = $resource->is_vip_only_access($post_id, $context);
            if (($vip_only_purchase || $vip_only_access) && !$resource->has_vip_access($post_id, $user_id, $context)) {
                $message = $vip_only_access ? __('该资源仅限 VIP 访问', 'qilingshop') : __('该资源仅限 VIP 购买', 'qilingshop');
                return ['success' => false, 'message' => $message];
            }

            if ($upgrade_from === 'view' && $scope !== 'download') {
                $upgrade_from = '';
            }

            // 获取资源价格
            $price_info = $resource->get_price($post_id, $user_id, $context);

            if ($upgrade_from === 'view') {
                $has_view = $this->user_has_purchased($post_id, $user_id, false, 'view');
                $has_download = $this->user_has_purchased($post_id, $user_id, false, 'download', $download_index);
                if (!$has_view || $has_download) {
                    return ['success' => false, 'message' => __('无需补差价购买', 'qilingshop')];
                }
                $view_price = $resource->get_price($post_id, $user_id, 'view');
                $price_info['points'] = max(0, $price_info['points'] - $view_price['points']);
                $price_info['rmb'] = max(0, $price_info['rmb'] - $view_price['rmb']);
                $price_info['original'] = max(0, $price_info['original'] - $view_price['original']);
            }

            if (!$price_info) {
                return ['success' => false, 'message' => __('获取价格失败', 'qilingshop')];
            }

            if ($price_info['points'] <= 0 && $price_info['rmb'] > 0) {
                return ['success' => false, 'message' => __('该资源仅支持人民币购买', 'qilingshop')];
            }

            // 检查是否免费
            if ($price_info['points'] <= 0 && $price_info['rmb'] <= 0) {
                if ($upgrade_from !== 'view') {
                    return ['success' => false, 'message' => __('资源价格配置异常，请联系管理员', 'qilingshop')];
                }

                // 免费资源，直接创建完成订单
                $order_no = $this->create([
                    'user_id'      => $user_id,
                    'post_id'      => $post_id,
                    'order_type'   => 'resource',
                    'price_points' => 0,
                    'final_price'  => 0,
                    'status'       => self::STATUS_PAID,
                    'payment_method' => 'free',
                    'paid_at'      => current_time('mysql'),
                    'download_index' => $scope === 'download' ? $download_index : 0,
                    'remark'       => wp_json_encode($this->build_resource_order_remark($scope, $upgrade_from, $download_index)),
                ]);

                if (!$order_no || !$this->finalize_internal_paid_order($order_no, 'free')) {
                    return [
                        'success' => false,
                        'message' => __('订单处理失败，请联系管理员补单', 'qilingshop'),
                    ];
                }

                return [
                    'success'  => true,
                    'message'  => __('获取成功', 'qilingshop'),
                    'order_no' => $order_no,
                ];
            }

            // 检查余额
            $balance = QilingShop_Points::instance()->get_balance($user_id);
            if ($balance < $price_info['points']) {
                return [
                    'success' => false,
                    'message' => __('积分余额不足，请先充值', 'qilingshop'),
                ];
            }

            // 扣除积分
            $deducted = QilingShop_Points::instance()->deduct_points(
                $user_id,
                $price_info['points'],
                'purchase',
                sprintf(__('购买资源：%s', 'qilingshop'), get_the_title($post_id)),
                $post_id
            );

            if (!$deducted) {
                return ['success' => false, 'message' => __('扣费失败，请重试', 'qilingshop')];
            }

            // 创建已完成订单
            $order_no = $this->create([
                'user_id'         => $user_id,
                'post_id'         => $post_id,
                'order_type'      => 'resource',
                'price_points'    => $price_info['points'],
                'discount_amount' => $price_info['original'] - $price_info['points'],
                'final_price'     => $price_info['points'],
                'status'          => self::STATUS_PAID,
                'payment_method'  => 'points',
                'paid_at'         => current_time('mysql'),
                'download_index'  => $scope === 'download' ? $download_index : 0,
                'author_id'       => get_post_field('post_author', $post_id),
                'remark'          => wp_json_encode($this->build_resource_order_remark($scope, $upgrade_from, $download_index)),
            ]);

            if (!$order_no) {
                $refund_ok = $this->refund_failed_points_purchase(
                    $user_id,
                    $price_info['points'],
                    $post_id,
                    sprintf(__('订单创建失败退还积分：%s', 'qilingshop'), get_the_title($post_id))
                );
                return [
                    'success' => false,
                    'message' => $refund_ok ? __('创建订单失败', 'qilingshop') : __('创建订单失败，积分回退失败，请联系管理员处理', 'qilingshop'),
                ];
            }

            if (!$this->finalize_internal_paid_order($order_no, 'points')) {
                $cancelled = $this->cancel_failed_points_order($order_no);
                $refund_ok = $cancelled && $this->refund_failed_points_purchase(
                        $user_id,
                        $price_info['points'],
                        $post_id,
                        sprintf(__('订单处理失败退还积分：%s', 'qilingshop'), get_the_title($post_id))
                    );
                return [
                    'success' => false,
                    'message' => $refund_ok ? __('订单处理失败，积分已退还', 'qilingshop') : __('订单处理失败，请联系管理员处理', 'qilingshop'),
                ];
            }

            return [
                'success'  => true,
                'message'  => __('购买成功', 'qilingshop'),
                'order_no' => $order_no,
            ];
        } finally {
            $this->release_named_lock($lock_name);
        }
    }

    /**
     * 免费/积分直付订单没有网关回调，需在本请求内完成幂等收尾。
     *
     * @param string $order_no
     * @param string $payment_method
     * @return bool
     */
    private function finalize_internal_paid_order($order_no, $payment_method = '') {
        $order = $this->get_by_order_no($order_no);
        if (!$order || empty($order->id)) {
            return false;
        }

        if ((int) ($order->paid_handled ?? 0) !== 1) {
            if (!$this->handle_paid_order($order)) {
                return false;
            }
        }

        $now = current_time('mysql');
        $update_data = [
            'paid_handled'    => 1,
            'paid_handled_at' => $now,
        ];
        if (empty($order->paid_at)) {
            $update_data['paid_at'] = $now;
        }
        if ($payment_method !== '' && empty($order->payment_method)) {
            $update_data['payment_method'] = $payment_method;
        }

        $updated = $this->db->update('orders', $update_data, [
            'order_no' => $order_no,
        ]);

        return $updated !== false;
    }

    /**
     * 收尾失败时先取消订单并撤销已写入的下载权限，防止对账再次补发。
     */
    private function cancel_failed_points_order($order_no) {
        $this->db->begin_transaction();

        try {
            $order = $this->get_by_order_no_for_update($order_no);
            if (!$order || (int) ($order->paid_handled ?? 0) === 1 || (string) ($order->payment_method ?? '') !== 'points') {
                throw new Exception('Points order is not cancellable');
            }

            $deleted = $this->db->delete('downloads', ['order_no' => (string) $order_no]);
            if ($deleted === false) {
                throw new Exception('Failed to revoke download permission');
            }

            $updated = $this->db->update('orders', [
                'status' => self::STATUS_CANCELLED,
                'paid_at' => null,
                'paid_handled' => 0,
                'paid_handled_at' => null,
            ], [
                'id' => (int) $order->id,
                'paid_handled' => 0,
            ]);
            if ($updated === false || (int) $updated !== 1) {
                throw new Exception('Failed to cancel points order');
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            qilingshop_log('Cancel failed points order failed: ' . $e->getMessage(), 'error', [
                'order_no' => (string) $order_no,
            ]);
            return false;
        }
    }

    /**
     * 规范资源购买请求，避免已购资源重复扣费。
     *
     * 规则：
     * - 已拥有 download 权限：任何 view/download 购买都直接拦截。
     * - 已拥有 view 权限：再次购买 view 直接拦截；购买 download 自动视为补差价升级。
     *
     * @param int    $post_id
     * @param int    $user_id
     * @param string $scope
     * @param string $upgrade_from
     * @return array{scope:string,upgrade_from:string,existing_scope:string,blocked:bool,message:string}
     */
    public function normalize_resource_purchase_request($post_id, $user_id, $scope = 'download', $upgrade_from = '', $download_index = null) {
        $post_id = absint($post_id);
        $user_id = absint($user_id);
        $scope = $this->normalize_scope($scope);
        if ($scope === 'any') {
            $scope = 'download';
        }
        $upgrade_from = $upgrade_from === 'view' ? 'view' : '';
        $download_index = $this->normalize_download_index($download_index, true);
        if ($scope === 'download' && $download_index === null) {
            $download_index = self::DOWNLOAD_ALL_INDEX;
        }

        $result = [
            'scope' => $scope,
            'upgrade_from' => $upgrade_from,
            'existing_scope' => '',
            'blocked' => false,
            'message' => '',
        ];

        if ($post_id <= 0 || $user_id <= 0) {
            return $result;
        }

        $existing_scope = $this->get_user_purchase_scope($post_id, $user_id, $scope === 'download' ? $download_index : null);
        $result['existing_scope'] = $existing_scope;

        if ($existing_scope === 'download') {
            $result['blocked'] = true;
            $result['message'] = ($scope === 'download' && $download_index !== self::DOWNLOAD_ALL_INDEX)
                ? __('您已购买该下载项，无需重复购买', 'qilingshop')
                : __('您已购买该资源，无需重复购买', 'qilingshop');
            return $result;
        }

        if ($existing_scope === 'view') {
            if ($scope === 'view') {
                $result['blocked'] = true;
                $result['message'] = __('您已购买该资源，无需重复购买', 'qilingshop');
                return $result;
            }

            if ($scope === 'download') {
                $result['upgrade_from'] = 'view';
            }
        }

        if ($result['upgrade_from'] === 'view' && $scope !== 'download') {
            $result['upgrade_from'] = '';
        }

        return $result;
    }

    /**
     * 检查用户是否已购买资源
     */
    public function user_has_purchased($post_id, $user_id = null, $include_vip_free = true, $scope = 'any', $download_index = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        $scope = $this->normalize_scope($scope);
        $download_index = $this->normalize_download_index($download_index, true);

        if (!$user_id) {
            // 游客
            // 如果没有启用免登录购买，游客不可能购买过任何东西
            if (!get_option('qilingshop_guest_buy_enabled', false)) {
                return false;
            }
            
            // 检查游客购买
            $guest_id = qilingshop_security()->generate_guest_id();
            return $this->guest_has_purchased($post_id, $guest_id, $scope, $download_index);
        }

        if ($include_vip_free) {
            $resource = QilingShop_Resource::instance();
            if ($scope === 'any') {
                if ($resource->is_vip_free($post_id, $user_id, 'download') || $resource->is_vip_free($post_id, $user_id, 'view')) {
                    return true;
                }
            } else {
                $context = $scope === 'view' ? 'view' : 'download';
                if ($resource->is_vip_free($post_id, $user_id, $context)) {
                    return true;
                }
            }
        }

        $orders = $this->db->get_results('orders', [
            'where'   => [
                'user_id' => $user_id,
                'post_id' => $post_id,
                'status'  => self::STATUS_PAID,
            ],
            'orderby' => 'id',
            'order'   => 'DESC',
            'limit'   => -1,
        ]);

        if (empty($orders)) {
            return false;
        }

        foreach ($orders as $order) {
            if ($this->order_matches_scope($order, $scope, $download_index)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 获取用户购买范围
     *
     * @return string download|view|''
     */
    public function get_user_purchase_scope($post_id, $user_id = null, $download_index = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }
        $download_index = $this->normalize_download_index($download_index, true);

        if (!$user_id) {
            return '';
        }

        $orders = $this->db->get_results('orders', [
            'where'   => [
                'user_id' => $user_id,
                'post_id' => $post_id,
                'status'  => self::STATUS_PAID,
            ],
            'orderby' => 'id',
            'order'   => 'DESC',
            'limit'   => -1,
        ]);

        if (empty($orders)) {
            return '';
        }

        $has_view = false;
        foreach ($orders as $order) {
            $order_scope = $this->parse_order_scope($order);
            if ($order_scope === 'download' && $this->order_matches_download_index($order, $download_index)) {
                return 'download';
            }
            if ($order_scope === 'view') {
                $has_view = true;
            }
        }

        return $has_view ? 'view' : '';
    }

    /**
     * 检查游客是否已购买
     */
    public function guest_has_purchased($post_id, $guest_id, $scope = 'any', $download_index = null) {
        // 如果没有启用免登录购买，直接返回false
        if (!get_option('qilingshop_guest_buy_enabled', false)) {
            return false;
        }

        $scope = $this->normalize_scope($scope);
        $download_index = $this->normalize_download_index($download_index, true);

        $guest_ids = [];
        if (!empty($guest_id)) {
            $guest_ids[] = (string) $guest_id;
        }
        if (function_exists('qilingshop_security')) {
            $guest_ids = array_merge($guest_ids, qilingshop_security()->get_guest_id_candidates());
        }
        $guest_ids = array_values(array_unique(array_filter(array_map('strval', $guest_ids))));

        // 先按 guest_id（含兼容候选）查询
        if (!empty($guest_ids)) {
            $orders = $this->db->get_results('orders', [
                'where' => [
                    'guest_id' => $guest_ids,
                    'post_id'  => $post_id,
                    'status'   => self::STATUS_PAID,
                ],
                'limit' => -1,
            ]);
            if (!empty($orders)) {
                foreach ($orders as $order) {
                    if ($this->order_matches_scope($order, $scope, $download_index)) {
                        return true;
                    }
                }
            }
        }

        // 再按设备指纹（cookie token + UA）回溯 guest_orders 兼容历史标识
        if ($this->guest_has_purchased_by_device_fingerprint($post_id, $scope, $download_index)) {
            return true;
        }

        return false;
    }

    /**
     * 通过设备指纹（cookie token + UA）校验游客购买记录。
     *
     * @param int $post_id
     * @param string $scope
     * @return bool
     */
    private function guest_has_purchased_by_device_fingerprint($post_id, $scope, $download_index = null) {
        if (!function_exists('qilingshop_security')) {
            return false;
        }

        $cookie_token = qilingshop_security()->get_guest_cookie_token();
        if ($cookie_token === '') {
            return false;
        }
        $ua_hash = (string) qilingshop_security()->get_user_agent_hash();
        $current_guest_id = (string) qilingshop_security()->generate_guest_id();

        $guest_orders = $this->db->get_results('guest_orders', [
            'where' => [
                'post_id'       => $post_id,
                'cookie_token'  => $cookie_token,
            ],
            'orderby' => 'id',
            'order'   => 'DESC',
            'limit'   => -1,
        ]);

        if (empty($guest_orders)) {
            return false;
        }

        foreach ($guest_orders as $guest_order) {
            $stored_ua = isset($guest_order->user_agent_hash) ? (string) $guest_order->user_agent_hash : '';
            if ($stored_ua !== '' && !hash_equals($stored_ua, $ua_hash)) {
                continue;
            }

            $order = $this->get_by_order_no((string) $guest_order->order_no);
            if (!$order || (int) $order->status !== self::STATUS_PAID) {
                continue;
            }
            if (!$this->order_matches_scope($order, $scope, $download_index)) {
                continue;
            }

            // 兼容迁移：命中历史记录后，回写为当前设备 guest_id。
            if ($current_guest_id !== '') {
                if ((string) $order->guest_id !== $current_guest_id) {
                    $this->db->update('orders', ['guest_id' => $current_guest_id], ['id' => (int) $order->id]);
                }
                if ((string) ($guest_order->guest_id ?? '') !== $current_guest_id) {
                    $this->db->update('guest_orders', ['guest_id' => $current_guest_id], ['id' => (int) $guest_order->id]);
                }
            }

            return true;
        }

        return false;
    }

    private function normalize_scope($scope) {
        if (!is_string($scope)) {
            return 'any';
        }
        $scope = strtolower(trim($scope));
        if (!in_array($scope, ['any', 'view', 'download'], true)) {
            return 'any';
        }
        return $scope;
    }

    private function normalize_download_index($download_index, $allow_all = false) {
        if ($download_index === null || $download_index === '') {
            return null;
        }

        $download_index = (int) $download_index;
        if ($allow_all && $download_index < 0) {
            return self::DOWNLOAD_ALL_INDEX;
        }

        return max(0, $download_index);
    }

    private function build_resource_order_remark($scope, $upgrade_from = '', $download_index = null) {
        $remark = [
            'scope'        => $scope,
            'upgrade_from' => $upgrade_from,
        ];

        if ($scope === 'download') {
            $download_index = $this->normalize_download_index($download_index, true);
            $remark['download_index'] = $download_index === null ? self::DOWNLOAD_ALL_INDEX : $download_index;
        }

        return $remark;
    }

    private function parse_order_scope($order) {
        $scope = '';
        if (!empty($order->remark)) {
            $data = json_decode($order->remark, true);
            if (is_array($data) && isset($data['scope'])) {
                $scope = $data['scope'];
            }
        }
        if (!in_array($scope, ['view', 'download'], true)) {
            $scope = 'download';
        }
        return $scope;
    }

    private function parse_order_download_index($order) {
        $download_index = isset($order->download_index) ? (int) $order->download_index : 0;
        $explicit = false;

        if (!empty($order->remark)) {
            $data = json_decode($order->remark, true);
            if (is_array($data) && array_key_exists('download_index', $data)) {
                $explicit = true;
                $download_index = (int) $data['download_index'];
            }
        }

        if ($download_index < 0) {
            return self::DOWNLOAD_ALL_INDEX;
        }

        // 旧订单在前端未传 download_index 时都会落成 0，按整包授权兼容。
        if (!$explicit && $download_index === 0) {
            return self::DOWNLOAD_ALL_INDEX;
        }

        return max(0, $download_index);
    }

    private function order_matches_download_index($order, $download_index = null) {
        $download_index = $this->normalize_download_index($download_index, true);
        if ($download_index === null) {
            return true;
        }

        $order_index = $this->parse_order_download_index($order);
        if ($download_index === self::DOWNLOAD_ALL_INDEX) {
            return $order_index === self::DOWNLOAD_ALL_INDEX;
        }
        return $order_index === self::DOWNLOAD_ALL_INDEX || $order_index === $download_index;
    }

    private function order_matches_scope($order, $scope, $download_index = null) {
        if ($scope === 'any') {
            return true;
        }
        $order_scope = $this->parse_order_scope($order);
        if ($scope === 'download') {
            return $order_scope === 'download' && $this->order_matches_download_index($order, $download_index);
        }
        if ($scope === 'view') {
            return in_array($order_scope, ['view', 'download'], true);
        }
        return false;
    }

    /**
     * 获取订单授权范围信息
     *
     * @param object $order
     * @return array {scope: 'download'|'view'|'', upgrade_from: 'view'|'', download_index:int}
     */
    public function get_order_scope_info($order) {
        if (!is_object($order)) {
            return ['scope' => '', 'upgrade_from' => '', 'download_index' => self::DOWNLOAD_ALL_INDEX];
        }
        $is_resource = ($order->order_type === 'resource') || (empty($order->order_type) && !empty($order->post_id));
        if (!$is_resource) {
            return ['scope' => '', 'upgrade_from' => '', 'download_index' => self::DOWNLOAD_ALL_INDEX];
        }

        $scope = $this->parse_order_scope($order);
        $download_index = $scope === 'download' ? $this->parse_order_download_index($order) : self::DOWNLOAD_ALL_INDEX;
        $upgrade_from = '';
        if (!empty($order->remark)) {
            $data = json_decode($order->remark, true);
            if (is_array($data) && isset($data['upgrade_from']) && $data['upgrade_from'] === 'view') {
                $upgrade_from = 'view';
            }
        }
        if ($scope !== 'download') {
            $upgrade_from = '';
        }

        return ['scope' => $scope, 'upgrade_from' => $upgrade_from, 'download_index' => $download_index];
    }

    /**
     * 获取订单授权范围文案
     *
     * @param object $order
     * @return string
     */
    public function get_order_scope_label($order) {
        $info = $this->get_order_scope_info($order);
        if ($info['scope'] === '') {
            return '';
        }
        if ($info['scope'] === 'view') {
            return __('查看', 'qilingshop');
        }
        if ($info['upgrade_from'] === 'view') {
            return __('下载（补差价）', 'qilingshop');
        }
        if (isset($info['download_index']) && (int) $info['download_index'] >= 0) {
            return sprintf(__('下载项 %d（含查看）', 'qilingshop'), (int) $info['download_index'] + 1);
        }
        return __('下载（含查看）', 'qilingshop');
    }

    /**
     * 获取用户订单列表
     */
    public function get_user_orders($user_id, $args = []) {
        $defaults = [
            'limit'  => 20,
            'offset' => 0,
            'status' => '',
            'type'   => '',
        ];

        $args = wp_parse_args($args, $defaults);
        $where = ['user_id' => $user_id];

        if ($args['status'] !== '') {
            $where['status'] = $args['status'];
        }
        if (!empty($args['type'])) {
            $where['order_type'] = $args['type'];
        }

        return $this->db->get_results('orders', [
            'where'   => $where,
            'orderby' => 'id',
            'order'   => 'DESC',
            'limit'   => $args['limit'],
            'offset'  => $args['offset'],
        ]);
    }

    /**
     * 获取用户订单数量
     */
    public function get_user_orders_count($user_id, $where = []) {
        $where['user_id'] = $user_id;
        return $this->db->count('orders', $where);
    }

    /**
     * 获取订单状态文本
     */
    public function get_status_text($status) {
        $statuses = [
            self::STATUS_PENDING   => __('待支付', 'qilingshop'),
            self::STATUS_PAID      => __('已完成', 'qilingshop'),
            self::STATUS_CANCELLED => __('已取消', 'qilingshop'),
            self::STATUS_REFUNDED  => __('已退款', 'qilingshop'),
        ];

        return isset($statuses[$status]) ? $statuses[$status] : __('未知', 'qilingshop');
    }

    /**
     * 发送管理员通知
     */
    private function send_admin_notification($order) {
        $user = get_user_by('ID', $order->user_id);
        $post_title = $order->post_title ?: get_the_title($order->post_id);
        $amount = $order->price_rmb ?: qilingshop_points_to_rmb($order->price_points);
        
        $message = sprintf(
            __("用户购买通知\n\n用户：%s\n资源：%s\n金额：%s 元\n订单号：%s\n支付方式：%s\n时间：%s", 'qilingshop'),
            $user ? $user->user_login : ($order->user_id > 0 ? $order->user_id : sprintf(__('游客(%s)', 'qilingshop'), $order->guest_id)),
            $post_title,
            $amount,
            $order->order_no,
            $order->payment_method ? $order->payment_method : __('积分', 'qilingshop'),
            current_time('Y-m-d H:i:s')
        );

        wp_mail(
            get_option('admin_email'),
            sprintf(__('[%s] 资源购买通知', 'qilingshop'), get_bloginfo('name')),
            $message
        );
    }
}
