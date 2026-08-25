<?php
/**
 * 售后退款管理
 *
 * @package QilingShop
 * @since   2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_Shop_Refund {

    /**
     * 状态常量
     */
    const STATUS_PENDING  = 0; // 待审核
    const STATUS_APPROVED = 1; // 已通过
    const STATUS_REJECTED = 2; // 已驳回
    const STATUS_CANCELLED = 3; // 已撤销
    const STATUS_REFUNDED = 4; // 已退款
    const STATUS_RETURNED = 5; // 买家已退货
    const STATUS_RECEIVED = 6; // 商家已收货

    /**
     * 单例实例
     */
    private static $instance = null;

    /**
     * 数据库实例
     */
    private $db;

    /**
     * 表名
     */
    private $table;

    /**
     * 日志表
     */
    private $log_table;

    /**
     * 获取单例实例
     *
     * @return QLS_Shop_Refund
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->db = QLS_Shop_Database::instance();
        $this->table = $this->db->get_table('refunds');
        $this->log_table = $this->db->get_table('refund_logs');
    }

    /**
     * 事务内锁定退款记录
     *
     * @param int $refund_id
     * @return object|null
     */
    private function get_refund_for_update($refund_id) {
        $refund_id = (int) $refund_id;
        if ($refund_id <= 0) {
            return null;
        }

        $wpdb = $this->db->get_wpdb();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d LIMIT 1 FOR UPDATE",
            $refund_id
        ));
    }

    /**
     * 事务内锁定订单记录
     *
     * @param int $order_id
     * @return object|null
     */
    private function get_order_for_update($order_id) {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return null;
        }

        $wpdb = $this->db->get_wpdb();
        $table = $this->db->get_table('orders');

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d LIMIT 1 FOR UPDATE",
            $order_id
        ));
    }

    /**
     * 提交退款申请
     *
     * @param int    $order_id
     * @param int    $user_id
     * @param string $reason
     * @param array  $evidence_images
     * @return int|WP_Error
     */
    public function request_refund($order_id, $user_id, $reason = '', $evidence_images = []) {
        $order_id = (int) $order_id;
        $user_id = (int) $user_id;
        $reason = sanitize_textarea_field($reason);
        $evidence_images = $this->sanitize_image_urls($evidence_images);

        if ($order_id <= 0 || $user_id <= 0) {
            return new WP_Error('invalid_params', __('参数错误', 'qilingshop'));
        }

        if (trim($reason) === '') {
            return new WP_Error('missing_reason', __('请填写退货/退款原因', 'qilingshop'));
        }

        $this->db->begin_transaction();

        try {
            $order = $this->get_order_for_update($order_id);
            if (!$order) {
                throw new Exception(__('订单不存在', 'qilingshop'));
            }

            if ((int) $order->user_id !== $user_id) {
                throw new Exception(__('无权操作该订单', 'qilingshop'));
            }

            if (!empty($order->is_group_order)) {
                throw new Exception(__('团购订单暂不支持售后退款', 'qilingshop'));
            }

            if (!in_array((int) $order->status, [QLS_Shop_Order::STATUS_PAID, QLS_Shop_Order::STATUS_SHIPPED, QLS_Shop_Order::STATUS_COMPLETED], true)) {
                throw new Exception(__('当前订单状态无法申请退款', 'qilingshop'));
            }

            $active = $this->get_active_by_order($order_id);
            if ($active) {
                throw new Exception(__('该订单已有售后申请', 'qilingshop'));
            }

            $return_required = $this->order_requires_return($order_id, (int) $order->status) ? 1 : 0;

            $refund_id = $this->db->insert('refunds', [
                'order_id'            => $order_id,
                'order_no'            => $order->order_no,
                'user_id'             => $user_id,
                'amount'              => (float) $order->final_amount,
                'status'              => self::STATUS_PENDING,
                'reason'              => $reason,
                'evidence_images'     => wp_json_encode($evidence_images),
                'return_required'     => $return_required,
                'order_status_before' => (int) $order->status,
                'created_at'          => current_time('mysql'),
                'updated_at'          => current_time('mysql'),
            ]);

            if (!$refund_id) {
                throw new Exception(__('申请失败，请稍后重试', 'qilingshop'));
            }

            $updated = $this->db->update('orders', [
                'status' => QLS_Shop_Order::STATUS_REFUNDING,
            ], [
                'id'     => $order_id,
                'status' => (int) $order->status,
            ]);
            if ($updated === false || (int) $updated !== 1) {
                throw new Exception(__('申请失败，请稍后重试', 'qilingshop'));
            }

            if (!$this->log_action($refund_id, $order_id, $user_id, 'apply', $reason, $user_id, 'user')) {
                throw new Exception(__('售后日志写入失败', 'qilingshop'));
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return new WP_Error('apply_failed', $e->getMessage());
        }

        do_action('qls_shop_order_refund_applied', $order_id, $reason);

        return $refund_id;
    }

    /**
     * 用户撤销退款申请
     *
     * @param int $refund_id
     * @param int $user_id
     * @return true|WP_Error
     */
    public function cancel_refund($refund_id, $user_id) {
        $refund = $this->get($refund_id);
        if (!$refund) {
            return new WP_Error('refund_not_found', __('售后记录不存在', 'qilingshop'));
        }

        if ((int) $refund->user_id !== (int) $user_id) {
            return new WP_Error('no_permission', __('无权操作该售后申请', 'qilingshop'));
        }

        if (!in_array((int) $refund->status, [self::STATUS_PENDING, self::STATUS_APPROVED], true)) {
            return new WP_Error('invalid_status', __('当前状态无法撤销', 'qilingshop'));
        }

        $this->db->begin_transaction();

        try {
            $this->db->update('refunds', [
                'status'       => self::STATUS_CANCELLED,
                'cancelled_at' => current_time('mysql'),
                'updated_at'   => current_time('mysql'),
            ], ['id' => $refund_id]);

            $restore_status = (int) $refund->order_status_before;
            qls_shop_order()->update_status((int) $refund->order_id, $restore_status);

            if (!$this->log_action($refund_id, (int) $refund->order_id, (int) $refund->user_id, 'cancel', __('用户撤销申请', 'qilingshop'), (int) $user_id, 'user')) {
                throw new Exception(__('售后日志写入失败', 'qilingshop'));
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return new WP_Error('cancel_failed', $e->getMessage());
        }

        return true;
    }

    /**
     * 审核通过
     *
     * @param int    $refund_id
     * @param int    $admin_id
     * @param string $remark
     * @param string $return_address
     * @return true|WP_Error
     */
    public function approve_refund($refund_id, $admin_id, $remark = '', $return_address = '') {
        return $this->review_refund($refund_id, $admin_id, self::STATUS_APPROVED, $remark, $return_address);
    }

    /**
     * 驳回申请
     *
     * @param int    $refund_id
     * @param int    $admin_id
     * @param string $remark
     * @return true|WP_Error
     */
    public function reject_refund($refund_id, $admin_id, $remark = '') {
        return $this->review_refund($refund_id, $admin_id, self::STATUS_REJECTED, $remark);
    }

    /**
     * 买家提交退货物流。
     *
     * @param int    $refund_id
     * @param int    $user_id
     * @param string $shipping_company
     * @param string $tracking_no
     * @return true|WP_Error
     */
    public function submit_return_logistics($refund_id, $user_id, $shipping_company, $tracking_no) {
        $refund_id = (int) $refund_id;
        $user_id = (int) $user_id;
        $shipping_company = sanitize_text_field($shipping_company);
        $tracking_no = sanitize_text_field($tracking_no);

        if ($refund_id <= 0 || $user_id <= 0) {
            return new WP_Error('invalid_params', __('参数错误', 'qilingshop'));
        }

        if ($shipping_company === '' || $tracking_no === '') {
            return new WP_Error('missing_logistics', __('请填写退货物流公司和单号', 'qilingshop'));
        }

        $this->db->begin_transaction();

        try {
            $refund = $this->get_refund_for_update($refund_id);
            if (!$refund) {
                throw new Exception(__('售后记录不存在', 'qilingshop'));
            }

            if ((int) $refund->user_id !== $user_id) {
                throw new Exception(__('无权操作该售后申请', 'qilingshop'));
            }

            if ((int) ($refund->return_required ?? 0) !== 1) {
                throw new Exception(__('虚拟商品退款无需填写退货物流', 'qilingshop'));
            }

            if ((int) $refund->status !== self::STATUS_APPROVED) {
                throw new Exception(__('当前状态无法填写退货物流', 'qilingshop'));
            }

            $updated = $this->db->update('refunds', [
                'status'                  => self::STATUS_RETURNED,
                'return_shipping_company' => $shipping_company,
                'return_tracking_no'      => $tracking_no,
                'return_shipped_at'       => current_time('mysql'),
                'updated_at'              => current_time('mysql'),
            ], [
                'id'     => $refund_id,
                'status' => self::STATUS_APPROVED,
            ]);

            if ($updated === false || (int) $updated !== 1) {
                throw new Exception(__('退货物流提交失败，请稍后重试', 'qilingshop'));
            }

            $message = sprintf(__('买家已退货：%1$s %2$s', 'qilingshop'), $shipping_company, $tracking_no);
            if (!$this->log_action($refund_id, (int) $refund->order_id, (int) $refund->user_id, 'return_shipped', $message, $user_id, 'user')) {
                throw new Exception(__('售后日志写入失败', 'qilingshop'));
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return new WP_Error('return_logistics_failed', $e->getMessage());
        }

        return true;
    }

    /**
     * 管理员确认收到退货。
     *
     * @param int    $refund_id
     * @param int    $admin_id
     * @param string $remark
     * @return true|WP_Error
     */
    public function confirm_return_received($refund_id, $admin_id, $remark = '') {
        $refund_id = (int) $refund_id;
        $admin_id = (int) $admin_id;
        $remark = sanitize_textarea_field($remark);

        $this->db->begin_transaction();

        try {
            $refund = $this->get_refund_for_update($refund_id);
            if (!$refund) {
                throw new Exception(__('售后记录不存在', 'qilingshop'));
            }

            if ((int) ($refund->return_required ?? 0) !== 1) {
                throw new Exception(__('虚拟商品无需确认退货收货', 'qilingshop'));
            }

            if ((int) $refund->status !== self::STATUS_RETURNED) {
                throw new Exception(__('当前状态无法确认收货', 'qilingshop'));
            }

            $updated = $this->db->update('refunds', [
                'status'             => self::STATUS_RECEIVED,
                'admin_id'           => $admin_id,
                'admin_remark'       => $remark,
                'return_received_at' => current_time('mysql'),
                'updated_at'         => current_time('mysql'),
            ], [
                'id'     => $refund_id,
                'status' => self::STATUS_RETURNED,
            ]);

            if ($updated === false || (int) $updated !== 1) {
                throw new Exception(__('确认收货失败，请稍后重试', 'qilingshop'));
            }

            $message = $remark ? $remark : __('管理员确认收到退货', 'qilingshop');
            if (!$this->log_action($refund_id, (int) $refund->order_id, (int) $refund->user_id, 'return_received', $message, $admin_id, 'admin')) {
                throw new Exception(__('售后日志写入失败', 'qilingshop'));
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return new WP_Error('confirm_receive_failed', $e->getMessage());
        }

        return true;
    }

    /**
     * 执行退款（退到可提现余额）
     *
     * @param int    $refund_id
     * @param int    $admin_id
     * @param string $remark
     * @return true|WP_Error
     */
    public function confirm_refund($refund_id, $admin_id, $remark = '') {
        $refund_id = (int) $refund_id;
        $processing_timeout = 300;
        $refund = null;
        $order = null;
        $order_id = 0;
        $refund_mode = 'withdrawable_balance';
        $refund_no = '';

        $this->db->begin_transaction();

        try {
            $refund = $this->get_refund_for_update($refund_id);
            if (!$refund) {
                throw new Exception(__('售后记录不存在', 'qilingshop'));
            }

            if ((int) $refund->status === self::STATUS_REFUNDED || (int) ($refund->refund_handled ?? 0) === 1) {
                $this->db->commit();
                return true;
            }

            $return_required = (int) ($refund->return_required ?? 0) === 1;
            $required_status = $return_required ? self::STATUS_RECEIVED : self::STATUS_APPROVED;
            if ((int) $refund->status !== $required_status) {
                throw new Exception($return_required ? __('实物退货需确认收货后再退款', 'qilingshop') : __('当前状态无法退款', 'qilingshop'));
            }

            $order_id = (int) $refund->order_id;
            $order = $this->get_order_for_update($order_id);
            if (!$order) {
                throw new Exception(__('订单不存在', 'qilingshop'));
            }

            if ((int) $order->status === QLS_Shop_Order::STATUS_REFUNDED) {
                $this->db->update('refunds', [
                    'status'            => self::STATUS_REFUNDED,
                    'admin_id'          => (int) $admin_id,
                    'admin_remark'      => sanitize_textarea_field($remark),
                    'refunded_at'       => current_time('mysql'),
                    'refund_handled'    => 1,
                    'refund_handled_at' => current_time('mysql'),
                    'updated_at'        => current_time('mysql'),
                    'refund_mode'       => $this->resolve_refund_mode(),
                    'refund_no'         => !empty($refund->refund_no) ? sanitize_text_field((string) $refund->refund_no) : $this->generate_refund_no($refund_id),
                    'gateway_error'     => null,
                ], ['id' => $refund_id]);
                $this->db->commit();
                return true;
            }

            $handled_state = (int) ($refund->refund_handled ?? 0);
            $gateway_refund_status = sanitize_key((string) ($refund->gateway_refund_status ?? ''));
            if ($handled_state === 2 && $gateway_refund_status === 'local_finalize_failed') {
                $this->db->commit();
                return new WP_Error(
                    'refund_reconcile_required',
                    __('该退款资金已处理，但本地状态收尾失败，请先人工核对，勿重复退款', 'qilingshop')
                );
            }

            $refund_mode = $this->resolve_refund_mode();
            $refund_no = !empty($refund->refund_no) ? sanitize_text_field((string) $refund->refund_no) : $this->generate_refund_no($refund_id);

            if ($handled_state === 2) {
                $handled_at = !empty($refund->refund_handled_at) ? strtotime((string) $refund->refund_handled_at) : 0;
                $is_stale = $handled_at > 0 && (current_time('timestamp') - $handled_at) >= $processing_timeout;
                if (!$is_stale) {
                    $this->db->commit();
                    return true;
                }
            }

            $claim_data = [
                'refund_handled'    => 2,
                'refund_handled_at' => current_time('mysql'),
                'updated_at'        => current_time('mysql'),
                'refund_mode'       => $refund_mode,
                'refund_no'         => $refund_no,
                'gateway_refund_status' => null,
                'gateway_refund_no' => null,
                'gateway_response'  => null,
                'gateway_error'     => null,
            ];
            if ($refund_mode === 'gateway') {
                $claim_data['gateway_refund_status'] = 'processing';
            }

            $claimed = $this->db->update('refunds', $claim_data, [
                'id'             => $refund_id,
                'refund_handled' => $handled_state === 2 ? 2 : 0,
            ]);

            if ($claimed === false || (int) $claimed !== 1) {
                throw new Exception(__('退款处理中，请稍后刷新', 'qilingshop'));
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            $code = ($e->getMessage() === __('售后记录不存在', 'qilingshop')) ? 'refund_not_found' : 'refund_failed';
            return new WP_Error($code, $e->getMessage());
        }

        $this->log_action(
            $refund_id,
            $order_id,
            (int) ($refund->user_id ?? 0),
            'refund_start',
            $this->build_refund_audit_message(
                __('管理员发起最终退款', 'qilingshop'),
                $refund_mode,
                $order,
                [
                    'refund_no' => $refund_no,
                    'remark'    => $remark,
                ]
            ),
            (int) $admin_id,
            'admin'
        );

        $result = qls_shop_order()->confirm_refund($order_id, [
            'emit_hook'   => false,
            'refund_id'   => $refund_id,
            'refund_no'   => $refund_no,
            'refund_mode' => $refund_mode,
        ]);

        if (is_wp_error($result)) {
            $result_data = $result->get_error_data();
            $result_data = is_array($result_data) ? $result_data : [];

            $failure_update = [
                'refund_handled'    => !empty($result_data['cash_refunded']) ? 2 : 0,
                'refund_handled_at' => !empty($result_data['cash_refunded']) ? current_time('mysql') : null,
                'updated_at'        => current_time('mysql'),
                'refund_mode'       => $refund_mode,
                'refund_no'         => $refund_no,
                'gateway_error'     => sanitize_textarea_field($result->get_error_message()),
            ];

            if (!empty($result_data['gateway_refund_no'])) {
                $failure_update['gateway_refund_no'] = sanitize_text_field((string) $result_data['gateway_refund_no']);
            }

            if (!empty($result_data['gateway_refunded_at'])) {
                $failure_update['gateway_refunded_at'] = sanitize_text_field((string) $result_data['gateway_refunded_at']);
            }

            if (!empty($result_data['local_finalize_failed'])) {
                $failure_update['gateway_refund_status'] = 'local_finalize_failed';
            } elseif (!empty($result_data['gateway_status'])) {
                $failure_update['gateway_refund_status'] = sanitize_key((string) $result_data['gateway_status']);
            } elseif ($refund_mode === 'gateway') {
                $failure_update['gateway_refund_status'] = 'failed';
            }

            $gateway_response = $this->encode_gateway_payload($result_data['gateway_response'] ?? null);
            if ($gateway_response !== null) {
                $failure_update['gateway_response'] = $gateway_response;
            }

            $this->db->update('refunds', $failure_update, [
                'id'             => $refund_id,
                'refund_handled' => 2,
            ]);

            $failure_action = !empty($result_data['local_finalize_failed']) ? 'refund_reconcile' : 'refund_fail';
            $this->log_action(
                $refund_id,
                $order_id,
                (int) ($refund->user_id ?? 0),
                $failure_action,
                $this->build_refund_audit_message(
                    !empty($result_data['local_finalize_failed'])
                        ? __('退款资金已处理，但本地状态收尾失败', 'qilingshop')
                        : __('最终退款失败', 'qilingshop'),
                    $refund_mode,
                    $order,
                    [
                        'refund_no'         => $refund_no,
                        'gateway_status'    => $failure_update['gateway_refund_status'] ?? '',
                        'gateway_refund_no' => $failure_update['gateway_refund_no'] ?? '',
                        'gateway_response'  => $result_data['gateway_response'] ?? null,
                        'error'             => $result->get_error_message(),
                    ]
                ),
                (int) $admin_id,
                'admin'
            );

            return $result;
        }

        $final_refund_mode = sanitize_key((string) ($result['refund_mode'] ?? $refund_mode));
        if (!in_array($final_refund_mode, ['withdrawable_balance', 'gateway'], true)) {
            $final_refund_mode = $refund_mode;
        }

        $update_data = [
            'status'            => self::STATUS_REFUNDED,
            'admin_id'          => (int) $admin_id,
            'admin_remark'      => sanitize_textarea_field($remark),
            'refunded_at'       => current_time('mysql'),
            'refund_handled'    => 1,
            'refund_handled_at' => current_time('mysql'),
            'updated_at'        => current_time('mysql'),
            'refund_mode'       => $final_refund_mode,
            'refund_no'         => $refund_no,
            'gateway_refund_status' => null,
            'gateway_refund_no' => null,
            'gateway_response'  => null,
            'gateway_error'     => null,
            'refunded_amount'   => round((float) ($result['refunded_amount'] ?? $refund->amount ?? 0), 2),
        ];

        if ($final_refund_mode === 'gateway') {
            $update_data['gateway_refund_status'] = sanitize_key((string) ($result['gateway_status'] ?? 'success'));
        }

        if (!empty($result['gateway_refund_no'])) {
            $update_data['gateway_refund_no'] = sanitize_text_field((string) $result['gateway_refund_no']);
        }

        if (!empty($result['gateway_refunded_at'])) {
            $update_data['gateway_refunded_at'] = sanitize_text_field((string) $result['gateway_refunded_at']);
        }

        if (!empty($result['gateway_response'])) {
            $gateway_response = $this->encode_gateway_payload($result['gateway_response']);
            if ($gateway_response !== null) {
                $update_data['gateway_response'] = $gateway_response;
            }
        }

        $updated = $this->db->update('refunds', $update_data, [
            'id'             => $refund_id,
            'refund_handled' => 2,
        ]);
        if ($updated === false) {
            return new WP_Error('refund_failed', __('售后状态更新失败', 'qilingshop'));
        }

        $message = $this->build_refund_audit_message(
            __('确认退款成功', 'qilingshop'),
            $final_refund_mode,
            $order,
            [
                'refund_no'         => $refund_no,
                'gateway_status'    => $update_data['gateway_refund_status'] ?? '',
                'gateway_refund_no' => $update_data['gateway_refund_no'] ?? '',
                'gateway_response'  => $result['gateway_response'] ?? null,
                'remark'            => $remark,
            ]
        );
        if (!$this->log_action($refund_id, $order_id, (int) $refund->user_id, 'refund', $message, (int) $admin_id, 'admin')) {
            return new WP_Error('refund_failed', __('售后日志写入失败', 'qilingshop'));
        }

        do_action(
            'qls_shop_order_refunded',
            $order_id,
            (int) $refund_id,
            (string) $final_refund_mode,
            [
                'gateway_status'      => sanitize_key((string) ($update_data['gateway_refund_status'] ?? '')),
                'gateway_refund_no'   => sanitize_text_field((string) ($update_data['gateway_refund_no'] ?? '')),
                'gateway_refunded_at' => sanitize_text_field((string) ($update_data['gateway_refunded_at'] ?? '')),
                'refunded_amount'     => round((float) ($update_data['refunded_amount'] ?? 0), 2),
            ]
        );
        return true;
    }

    /**
     * 解析退款模式。
     *
     * @return string
     */
    private function resolve_refund_mode() {
        $mode = sanitize_key((string) get_option('qilingshop_shop_refund_mode', 'withdrawable_balance'));
        if (!in_array($mode, ['withdrawable_balance', 'gateway'], true)) {
            $mode = 'withdrawable_balance';
        }

        return $mode;
    }

    /**
     * 生成退款单号。
     *
     * @param int $refund_id 售后单 ID
     * @return string
     */
    private function generate_refund_no($refund_id) {
        return sprintf('RFD%s%06d', wp_date('YmdHis', current_time('timestamp')), max(0, (int) $refund_id));
    }

    /**
     * 将网关返回压缩为可持久化的 JSON 字符串。
     *
     * @param mixed $payload 返回数据
     * @return string|null
     */
    private function encode_gateway_payload($payload) {
        if ($payload === null || $payload === '') {
            return null;
        }

        if (is_scalar($payload)) {
            return (string) $payload;
        }

        $encoded = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded !== false ? $encoded : null;
    }

    /**
     * 获取退款记录
     *
     * @param int $refund_id
     * @return object|null
     */
    public function get($refund_id) {
        $refund_id = (int) $refund_id;
        if ($refund_id <= 0) {
            return null;
        }
        return $this->db->get_by_id('refunds', $refund_id);
    }

    /**
     * 获取售后日志。
     *
     * @param int $refund_id
     * @param int $limit
     * @return array
     */
    public function get_logs($refund_id, $limit = 20) {
        $refund_id = (int) $refund_id;
        $limit = max(1, min(100, (int) $limit));
        if ($refund_id <= 0) {
            return [];
        }

        $wpdb = $this->db->get_wpdb();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->log_table} WHERE refund_id = %d ORDER BY id DESC LIMIT %d",
            $refund_id,
            $limit
        ));
    }

    /**
     * 批量获取售后日志，减少后台列表页逐条查询。
     *
     * @param int[] $refund_ids
     * @param int   $limit
     * @return array<int, array>
     */
    public function get_logs_by_refunds($refund_ids, $limit = 20) {
        $refund_ids = array_values(array_unique(array_filter(array_map('intval', (array) $refund_ids))));
        $limit = max(1, min(100, (int) $limit));
        if (empty($refund_ids)) {
            return [];
        }

        $wpdb = $this->db->get_wpdb();
        $placeholders = implode(', ', array_fill(0, count($refund_ids), '%d'));
        $sql = "SELECT * FROM {$this->log_table} WHERE refund_id IN ({$placeholders}) ORDER BY refund_id ASC, id DESC";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $refund_ids));

        $log_map = [];
        foreach ((array) $rows as $row) {
            $refund_id = (int) ($row->refund_id ?? 0);
            if ($refund_id <= 0) {
                continue;
            }

            if (!isset($log_map[$refund_id])) {
                $log_map[$refund_id] = [];
            }

            if (count($log_map[$refund_id]) >= $limit) {
                continue;
            }

            $log_map[$refund_id][] = $row;
        }

        return $log_map;
    }

    /**
     * 获取订单最新售后记录
     *
     * @param int $order_id
     * @return object|null
     */
    public function get_by_order($order_id) {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return null;
        }
        $wpdb = $this->db->get_wpdb();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE order_id = %d ORDER BY id DESC LIMIT 1",
            $order_id
        ));
    }

    /**
     * 获取订单列表的售后记录
     *
     * @param array $order_ids
     * @return array
     */
    public function get_by_orders($order_ids) {
        $order_ids = array_filter(array_map('intval', (array) $order_ids));
        if (empty($order_ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($order_ids), '%d'));
        $wpdb = $this->db->get_wpdb();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE order_id IN ({$placeholders}) ORDER BY id DESC",
            $order_ids
        ));

        $map = [];
        foreach ($rows as $row) {
            if (!isset($map[$row->order_id])) {
                $map[$row->order_id] = $row;
            }
        }
        return $map;
    }

    /**
     * 获取后台列表
     *
     * @param array $args
     * @return array
     */
    public function get_list($args = []) {
        $defaults = [
            'status' => '',
            'keyword' => '',
            'limit' => 20,
            'offset' => 0,
        ];
        $args = wp_parse_args($args, $defaults);

        $where = [];
        $params = [];

        if ($args['status'] !== '') {
            $where[] = 'status = %d';
            $params[] = (int) $args['status'];
        }

        if (!empty($args['keyword'])) {
            $where[] = '(order_no LIKE %s)';
            $params[] = '%' . $this->db->get_wpdb()->esc_like($args['keyword']) . '%';
        }

        $sql = "SELECT * FROM {$this->table}";
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC';

        if ($args['limit'] > 0) {
            $sql .= $this->db->get_wpdb()->prepare(' LIMIT %d OFFSET %d', (int) $args['limit'], (int) $args['offset']);
        }

        if (!empty($params)) {
            $sql = $this->db->get_wpdb()->prepare($sql, $params);
        }

        return $this->db->get_wpdb()->get_results($sql);
    }

    /**
     * 获取数量
     *
     * @param string|int $status
     * @return int
     */
    public function get_count($status = '') {
        $wpdb = $this->db->get_wpdb();
        if ($status === '') {
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");
        }
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table} WHERE status = %d", (int) $status));
    }

    /**
     * 获取状态统计
     *
     * @return array
     */
    public function get_status_counts() {
        $wpdb = $this->db->get_wpdb();
        $rows = $wpdb->get_results("SELECT status, COUNT(*) as total FROM {$this->table} GROUP BY status");
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row->status] = (int) $row->total;
        }
        return $counts;
    }

    /**
     * 获取状态文本
     *
     * @param int $status
     * @return string
     */
    public function get_status_text($status) {
        $map = [
            self::STATUS_PENDING  => __('待审核', 'qilingshop'),
            self::STATUS_APPROVED => __('已通过', 'qilingshop'),
            self::STATUS_REJECTED => __('已驳回', 'qilingshop'),
            self::STATUS_CANCELLED => __('已撤销', 'qilingshop'),
            self::STATUS_REFUNDED => __('已退款', 'qilingshop'),
            self::STATUS_RETURNED => __('买家已退货', 'qilingshop'),
            self::STATUS_RECEIVED => __('已收货待退款', 'qilingshop'),
        ];
        return $map[$status] ?? __('未知', 'qilingshop');
    }

    /**
     * 获取售后记录状态文本。
     *
     * @param object $refund 售后记录。
     * @return string
     */
    public function get_refund_status_text($refund) {
        if (!is_object($refund) || !isset($refund->status)) {
            return __('未知', 'qilingshop');
        }

        $status = (int) $refund->status;
        if ($status === self::STATUS_APPROVED) {
            return !empty($refund->return_required) ? __('待买家退货', 'qilingshop') : __('待退款', 'qilingshop');
        }

        return $this->get_status_text($status);
    }

    /**
     * 获取前台展示用退款上下文。
     *
     * @param object $refund 售后记录。
     * @return array
     */
    public function get_display_meta($refund) {
        $default_mode = 'withdrawable_balance';
        $configured_mode = sanitize_key((string) get_option('qilingshop_shop_refund_mode', $default_mode));
        if (!in_array($configured_mode, ['withdrawable_balance', 'gateway'], true)) {
            $configured_mode = $default_mode;
        }

        if (!is_object($refund)) {
            return [
                'configured_mode'        => $configured_mode,
                'configured_mode_label'  => $this->get_refund_mode_text($configured_mode),
                'stored_mode'            => '',
                'stored_mode_label'      => '',
                'effective_mode'         => $configured_mode,
                'effective_mode_label'   => $this->get_refund_mode_text($configured_mode),
                'gateway_status'         => '',
                'gateway_status_label'   => '',
                'is_processing'          => false,
                'needs_reconcile'        => false,
            ];
        }

        $status = (int) ($refund->status ?? 0);
        $handled_state = (int) ($refund->refund_handled ?? 0);
        $gateway_status = sanitize_key((string) ($refund->gateway_refund_status ?? ''));

        $stored_mode = sanitize_key((string) ($refund->refund_mode ?? ''));
        if (!in_array($stored_mode, ['withdrawable_balance', 'gateway'], true)) {
            $stored_mode = '';
        }

        if ($stored_mode === '' && (
            $gateway_status !== ''
            || !empty($refund->gateway_refund_no)
            || !empty($refund->gateway_refunded_at)
        )) {
            $stored_mode = 'gateway';
        }

        $effective_mode = $configured_mode;
        if ($stored_mode !== '' && (
            $status === self::STATUS_REFUNDED
            || $handled_state === 1
            || in_array($gateway_status, ['processing', 'success', 'failed', 'local_finalize_failed'], true)
        )) {
            $effective_mode = $stored_mode;
        }

        return [
            'configured_mode'        => $configured_mode,
            'configured_mode_label'  => $this->get_refund_mode_text($configured_mode),
            'stored_mode'            => $stored_mode,
            'stored_mode_label'      => $stored_mode !== '' ? $this->get_refund_mode_text($stored_mode) : '',
            'effective_mode'         => $effective_mode,
            'effective_mode_label'   => $this->get_refund_mode_text($effective_mode),
            'gateway_status'         => $gateway_status,
            'gateway_status_label'   => $this->get_gateway_refund_status_text($gateway_status),
            'is_processing'          => ($handled_state === 2 && $gateway_status === 'processing'),
            'needs_reconcile'        => ($gateway_status === 'local_finalize_failed'),
        ];
    }

    /**
     * 解析售后凭证图片。
     *
     * @param object|array|string $refund_or_images 售后记录或图片字段。
     * @return array
     */
    public function get_evidence_images($refund_or_images) {
        if (is_object($refund_or_images)) {
            $refund_or_images = $refund_or_images->evidence_images ?? '';
        } elseif (is_array($refund_or_images) && isset($refund_or_images['evidence_images'])) {
            $refund_or_images = $refund_or_images['evidence_images'];
        }

        if (is_array($refund_or_images)) {
            return $this->sanitize_image_urls($refund_or_images);
        }

        $decoded = json_decode((string) $refund_or_images, true);
        return is_array($decoded) ? $this->sanitize_image_urls($decoded) : [];
    }

    private function review_refund($refund_id, $admin_id, $status, $remark = '', $return_address = '') {
        $refund = $this->get($refund_id);
        if (!$refund) {
            return new WP_Error('refund_not_found', __('售后记录不存在', 'qilingshop'));
        }

        if ((int) $refund->status !== self::STATUS_PENDING) {
            return new WP_Error('invalid_status', __('当前状态无法审核', 'qilingshop'));
        }

        $this->db->begin_transaction();

        try {
            $return_address = sanitize_textarea_field($return_address);
            if ((int) $status === self::STATUS_APPROVED && !empty($refund->return_required) && trim($return_address) === '') {
                throw new Exception(__('请填写退货地址', 'qilingshop'));
            }

            $update_data = [
                'status'      => (int) $status,
                'admin_id'    => (int) $admin_id,
                'admin_remark'=> sanitize_textarea_field($remark),
                'reviewed_at' => current_time('mysql'),
                'updated_at'  => current_time('mysql'),
            ];

            if ((int) $status === self::STATUS_APPROVED) {
                $update_data['return_address'] = !empty($refund->return_required) ? $return_address : null;
            }

            $this->db->update('refunds', $update_data, ['id' => $refund_id]);

            if ((int) $status === self::STATUS_REJECTED) {
                $restore_status = (int) $refund->order_status_before;
                qls_shop_order()->update_status((int) $refund->order_id, $restore_status);
            }

            $action = $status === self::STATUS_APPROVED ? 'approve' : 'reject';
            $message = $remark ? $remark : ($status === self::STATUS_APPROVED ? __('审核通过', 'qilingshop') : __('审核驳回', 'qilingshop'));
            if (!$this->log_action($refund_id, (int) $refund->order_id, (int) $refund->user_id, $action, $message, (int) $admin_id, 'admin')) {
                throw new Exception(__('售后日志写入失败', 'qilingshop'));
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            return new WP_Error('review_failed', $e->getMessage());
        }

        return true;
    }

    private function get_active_by_order($order_id) {
        $wpdb = $this->db->get_wpdb();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE order_id = %d AND status IN (%d, %d, %d, %d) ORDER BY id DESC LIMIT 1",
            (int) $order_id,
            self::STATUS_PENDING,
            self::STATUS_APPROVED,
            self::STATUS_RETURNED,
            self::STATUS_RECEIVED
        ));
    }

    /**
     * 判断订单是否需要实物退货。
     *
     * @param int $order_id 订单 ID。
     * @param int $order_status 订单申请售后前的状态。
     * @return bool
     */
    private function order_requires_return($order_id, $order_status = 0) {
        if (!in_array((int) $order_status, [QLS_Shop_Order::STATUS_SHIPPED, QLS_Shop_Order::STATUS_COMPLETED], true)) {
            return false;
        }

        $items = qls_shop_order()->get_items((int) $order_id);
        if (empty($items)) {
            return true;
        }

        foreach ($items as $item) {
            $product_id = isset($item->product_id) ? (int) $item->product_id : 0;
            if ($product_id <= 0) {
                return true;
            }

            if (!qls_product()->is_virtual($product_id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 清理图片 URL 列表。
     *
     * @param array $images 图片 URL。
     * @return array
     */
    private function sanitize_image_urls($images) {
        if (!is_array($images)) {
            return [];
        }

        $clean = [];
        foreach ($images as $image) {
            $url = esc_url_raw((string) $image);
            if ($url !== '') {
                $clean[] = $url;
            }
        }

        return array_values(array_slice(array_unique($clean), 0, 6));
    }

    /**
     * 构建退款审计日志文本。
     *
     * @param string      $title
     * @param string      $refund_mode
     * @param object|null $order
     * @param array       $details
     * @return string
     */
    private function build_refund_audit_message($title, $refund_mode, $order = null, $details = []) {
        $details = is_array($details) ? $details : [];
        $parts = [sanitize_text_field((string) $title)];
        $parts[] = sprintf(__('退款方式：%s', 'qilingshop'), $this->get_refund_mode_text($refund_mode));

        if (is_object($order)) {
            $parts[] = sprintf(
                __('支付方式：%s', 'qilingshop'),
                $this->get_payment_method_text((string) ($order->payment_method ?? ''))
            );

            $payment_version = sanitize_key((string) ($order->payment_channel_version ?? ''));
            if (in_array($payment_version, ['v2', 'v3'], true)) {
                $parts[] = sprintf(__('支付版本：%s', 'qilingshop'), strtoupper($payment_version));
            }
        }

        if (!empty($details['refund_no'])) {
            $parts[] = sprintf(__('退款单号：%s', 'qilingshop'), sanitize_text_field((string) $details['refund_no']));
        }

        if (!empty($details['gateway_status'])) {
            $parts[] = sprintf(__('网关状态：%s', 'qilingshop'), sanitize_key((string) $details['gateway_status']));
        }

        if (!empty($details['gateway_refund_no'])) {
            $parts[] = sprintf(__('网关退款单号：%s', 'qilingshop'), sanitize_text_field((string) $details['gateway_refund_no']));
        }

        if (!empty($details['error'])) {
            $parts[] = sprintf(__('失败原因：%s', 'qilingshop'), sanitize_textarea_field((string) $details['error']));
        }

        $gateway_summary = $this->summarize_refund_gateway_response($details['gateway_response'] ?? null);
        if ($gateway_summary !== '') {
            $parts[] = sprintf(__('网关摘要：%s', 'qilingshop'), $gateway_summary);
        }

        if (!empty($details['remark'])) {
            $parts[] = sprintf(__('备注：%s', 'qilingshop'), sanitize_textarea_field((string) $details['remark']));
        }

        return implode('；', array_filter($parts));
    }

    /**
     * 退款方式文本。
     *
     * @param string $refund_mode
     * @return string
     */
    public function get_refund_mode_text($refund_mode) {
        return $refund_mode === 'gateway'
            ? __('原路退回', 'qilingshop')
            : __('退回可提现余额', 'qilingshop');
    }

    /**
     * 网关退款状态文本。
     *
     * @param string $status
     * @return string
     */
    public function get_gateway_refund_status_text($status) {
        $status = sanitize_key((string) $status);
        if ($status === '') {
            return '';
        }

        $labels = [
            'processing'            => __('退款处理中', 'qilingshop'),
            'success'               => __('原路退款成功', 'qilingshop'),
            'failed'                => __('原路退款失败', 'qilingshop'),
            'local_finalize_failed' => __('资金已退回，但本地状态收尾失败', 'qilingshop'),
            'fallback_to_withdrawable_balance' => __('原路不支持，已自动退回可提现余额', 'qilingshop'),
        ];

        return $labels[$status] ?? strtoupper($status);
    }

    /**
     * 支付方式文本。
     *
     * @param string $payment_method
     * @return string
     */
    private function get_payment_method_text($payment_method) {
        $labels = [
            'alipay'         => __('支付宝', 'qilingshop'),
            'alipay_qr'      => __('支付宝扫码', 'qilingshop'),
            'alipay_page'    => __('支付宝网页', 'qilingshop'),
            'wechat'         => __('微信支付', 'qilingshop'),
            'wxpay'          => __('微信支付', 'qilingshop'),
            'weixin'         => __('微信支付', 'qilingshop'),
            'wechat_miniapp' => __('微信小程序支付', 'qilingshop'),
            'points'         => __('积分支付', 'qilingshop'),
            'balance'        => __('余额支付', 'qilingshop'),
        ];

        $payment_method = sanitize_key((string) $payment_method);
        if ($payment_method === '') {
            return __('未记录', 'qilingshop');
        }

        return $labels[$payment_method] ?? $payment_method;
    }

    /**
     * 压缩网关响应摘要，避免售后日志过长。
     *
     * @param mixed $payload
     * @return string
     */
    private function summarize_refund_gateway_response($payload) {
        if ($payload instanceof WP_Error) {
            return sanitize_text_field($payload->get_error_message());
        }

        if (is_scalar($payload) || $payload === null) {
            $text = trim((string) $payload);
            if ($text === '') {
                return '';
            }
            return strlen($text) > 200 ? substr($text, 0, 200) . '...' : $text;
        }

        if (!is_array($payload)) {
            return '';
        }

        $preferred_keys = [
            'code',
            'message',
            'status',
            'refund_id',
            'refund_status',
            'out_refund_no',
            'return_code',
            'return_msg',
            'result_code',
            'err_code',
            'err_code_des',
            'trade_no',
            'out_trade_no',
            'version',
        ];

        $summary = [];
        foreach ($preferred_keys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $value = $payload[$key];
            if (is_scalar($value) || $value === null) {
                $summary[] = $key . '=' . sanitize_text_field((string) $value);
            }
        }

        if (empty($summary)) {
            return '';
        }

        $text = implode(', ', array_slice($summary, 0, 6));
        return strlen($text) > 220 ? substr($text, 0, 220) . '...' : $text;
    }

    private function log_action($refund_id, $order_id, $user_id, $action, $message, $actor_id = 0, $actor_role = 'system') {
        $log_id = $this->db->insert('refund_logs', [
            'refund_id'  => (int) $refund_id,
            'order_id'   => (int) $order_id,
            'user_id'    => (int) $user_id,
            'actor_id'   => (int) $actor_id,
            'actor_role' => sanitize_text_field($actor_role),
            'action'     => sanitize_text_field($action),
            'message'    => sanitize_textarea_field($message),
            'created_at' => current_time('mysql'),
        ]);
        return (bool) $log_id;
    }
}

/**
 * 获取退款管理实例
 *
 * @return QLS_Shop_Refund
 */
function qls_shop_refund() {
    return QLS_Shop_Refund::instance();
}
