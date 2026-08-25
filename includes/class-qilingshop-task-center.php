<?php
/**
 * 任务中心（营销任务入口）
 *
 * @package QilingShop
 * @since   2.0.9
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QilingShop_Task_Center {

    const TASK_REWARD_STATUS_PROCESSING = 0;
    const TASK_REWARD_STATUS_COMPLETED = 1;

    const EXTERNAL_TASK_META_KEY = 'qilingshop_external_task_runtime';
    const EXTERNAL_TASK_LOCK_KEY = 'qilingshop_external_task_lock';
    const TASK_INTERVAL_FAST = 300;    // 5分钟
    const TASK_INTERVAL_NORMAL = 600;  // 10分钟
    const TASK_INTERVAL_HOURLY = 3600; // 1小时
    const TASK_INTERVAL_BIRTHDAY = 21600; // 6小时
    const TASK_LOCK_TTL = 600; // 10分钟

    /**
     * 单例实例
     *
     * @var QilingShop_Task_Center|null
     */
    private static $instance = null;
    private $runtime_lock_token = '';

    /**
     * 获取单例实例
     *
     * @return QilingShop_Task_Center
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 构造
     */
    private function __construct() {
        add_shortcode( 'qilingshop_task_center', array( $this, 'render_task_center' ) );
        add_action( 'init', array( $this, 'ensure_task_center_page' ), 20 );
        add_action( 'init', array( $this, 'ensure_task_check_key' ), 20 );
        add_action( 'init', array( $this, 'unschedule_wp_cron' ), 30 );
        add_action( 'init', array( $this, 'handle_task_claim' ), 35 );
        add_action( 'init', array( $this, 'handle_external_trigger' ), 40 );
        add_action( 'qilingshop_daily_task_check', array( $this, 'run_daily_tasks' ) );
        add_action( 'qilingshop_payment_recovery_remind', array( $this, 'run_payment_recovery_remind_task' ) );
    }

    /**
     * 任务中心短代码
     *
     * @return string
     */
    public function render_task_center() {
        if ( is_admin() ) {
            return '<div class="qls-shortcode-placeholder" style="padding:20px;background:#f0f0f1;text-align:center;border:1px dashed #ccc;">[qilingshop_task_center] ' . __( '任务中心', 'qilingshop' ) . '</div>';
        }

        wp_enqueue_style(
            'qilingshop-task-center',
            QILINGSHOP_URL . 'static/css/task-center.css',
            array(),
            function_exists( 'qilingshop_get_assets_version' ) ? qilingshop_get_assets_version() : QILINGSHOP_VERSION
        );

        $user_id = get_current_user_id();
        $tasks = $this->get_tasks( $user_id );

        ob_start();
        $template = QILINGSHOP_PATH . 'templates/task-center.php';
        if ( file_exists( $template ) ) {
            include $template;
        } else {
            echo '<p>Task Center Template Not Found.</p>';
        }
        return ob_get_clean();
    }

    /**
     * 获取任务列表
     *
     * @param int $user_id
     * @return array
     */
    public function get_tasks( $user_id ) {
        $tasks = array();

        $tasks[] = $this->get_birthday_task( $user_id );
        $tasks[] = $this->get_first_invite_task( $user_id );
        $tasks[] = $this->get_first_resource_order_task( $user_id );
        $tasks[] = $this->get_first_shop_paid_task( $user_id );

        /**
         * 扩展任务中心任务
         *
         * @param array $tasks
         * @param int   $user_id
         */
        $tasks = apply_filters( 'qilingshop_task_center_tasks', $tasks, $user_id );

        return array_values( array_filter( $tasks ) );
    }

    /**
     * 生日券任务
     *
     * @param int $user_id
     * @return array
     */
    private function get_birthday_task( $user_id ) {
        $enabled = (bool) get_option( 'qilingshop_birthday_coupon_enabled', false );
        $coupon_id = (int) get_option( 'qilingshop_birthday_coupon_id', 0 );
        $birthday = $user_id ? get_user_meta( $user_id, 'qilingshop_birthday', true ) : '';
        if ( $birthday === '' && $user_id ) {
            $birthday = (string) get_user_meta( $user_id, 'birthday', true );
        }
        $status = 'inactive';
        $status_text = __( '未启用', 'qilingshop' );
        $desc = __( '生日当天自动发放优惠券', 'qilingshop' );
        $action_label = '';
        $action_url = '';

        if ( ! $enabled ) {
            $status = 'inactive';
            $status_text = __( '未启用', 'qilingshop' );
        } elseif ( $coupon_id <= 0 ) {
            $status = 'inactive';
            $status_text = __( '未配置生日券', 'qilingshop' );
        } elseif ( ! $user_id ) {
            $status = 'locked';
            $status_text = __( '请先登录', 'qilingshop' );
            $action_label = __( '立即登录', 'qilingshop' );
            $action_url = wp_login_url( $this->get_task_center_url() );
        } elseif ( empty( $birthday ) ) {
            $status = 'locked';
            $status_text = __( '未设置生日', 'qilingshop' );
            $action_label = __( '去完善生日', 'qilingshop' );
            $action_url = $this->get_account_url( 'profile' );
        } else {
            $current_year = (string) current_time( 'Y' );
            $sent_year = (string) get_user_meta( $user_id, 'qilingshop_birthday_coupon_sent_' . $coupon_id, true );
            $today_md = date( 'm-d', current_time( 'timestamp' ) );
            $birthday_md = (string) get_user_meta( $user_id, 'qilingshop_birthday_md', true );
            if ( $birthday_md === '' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $birthday ) ) {
                $birthday_md = substr( $birthday, 5, 5 );
            }

            if ( $sent_year === $current_year ) {
                $status = 'done';
                $status_text = __( '已发放', 'qilingshop' );
            } elseif ( $birthday_md === $today_md ) {
                $status = 'pending';
                $status_text = __( '今日发放', 'qilingshop' );
            } else {
                $status = 'waiting';
                $status_text = __( '等待生日', 'qilingshop' );
            }
        }

        return array(
            'id' => 'birthday_coupon',
            'title' => __( '生日礼遇', 'qilingshop' ),
            'desc' => $desc,
            'reward' => __( '生日券', 'qilingshop' ),
            'status' => $status,
            'status_text' => $status_text,
            'action_label' => $action_label,
            'action_url' => $action_url,
        );
    }

    /**
     * 首次邀请成功任务
     *
     * @param int $user_id
     * @return array
     */
    private function get_first_invite_task( $user_id ) {
        $task_id = 'first_invite';
        $meta = $this->get_claim_task_meta( $task_id );
        $reward_points = $this->get_task_reward_points( $task_id );

        $status = 'inactive';
        $status_text = __( '未启用', 'qilingshop' );
        $action_label = '';
        $action_url = '';

        if ( ! $this->is_task_enabled( $task_id ) ) {
            $status = 'inactive';
            $status_text = __( '未启用', 'qilingshop' );
        } elseif ( $reward_points <= 0 ) {
            $status = 'inactive';
            $status_text = __( '未配置奖励', 'qilingshop' );
        } elseif ( ! $user_id ) {
            $status = 'locked';
            $status_text = __( '请先登录', 'qilingshop' );
            $action_label = __( '立即登录', 'qilingshop' );
            $action_url = wp_login_url( $this->get_task_center_url() );
        } elseif ( $this->has_task_reward_claimed( $user_id, $task_id ) ) {
            $status = 'done';
            $status_text = __( '已领取', 'qilingshop' );
        } elseif ( $this->has_completed_first_invite( $user_id ) ) {
            $status = 'pending';
            $status_text = __( '可领取', 'qilingshop' );
            $action_label = __( '领取奖励', 'qilingshop' );
            $action_url = $this->get_task_claim_url( $task_id );
        } else {
            $status = 'waiting';
            $status_text = __( '未完成', 'qilingshop' );
            $action_label = __( '去邀请好友', 'qilingshop' );
            $action_url = $this->get_account_url( 'qls-invite' );
        }

        return array(
            'id' => $task_id,
            'title' => $meta['title'],
            'desc' => __( '成功邀请 1 位好友注册即可领取奖励', 'qilingshop' ),
            'reward' => $this->format_task_reward( $reward_points ),
            'status' => $status,
            'status_text' => $status_text,
            'action_label' => $action_label,
            'action_url' => $action_url,
        );
    }

    /**
     * 首次资源购买任务
     *
     * @param int $user_id
     * @return array
     */
    private function get_first_resource_order_task( $user_id ) {
        $task_id = 'first_resource_order';
        $meta = $this->get_claim_task_meta( $task_id );
        $reward_points = $this->get_task_reward_points( $task_id );

        $status = 'inactive';
        $status_text = __( '未启用', 'qilingshop' );
        $action_label = '';
        $action_url = '';

        if ( ! $this->is_task_enabled( $task_id ) ) {
            $status = 'inactive';
            $status_text = __( '未启用', 'qilingshop' );
        } elseif ( $reward_points <= 0 ) {
            $status = 'inactive';
            $status_text = __( '未配置奖励', 'qilingshop' );
        } elseif ( ! $user_id ) {
            $status = 'locked';
            $status_text = __( '请先登录', 'qilingshop' );
            $action_label = __( '立即登录', 'qilingshop' );
            $action_url = wp_login_url( $this->get_task_center_url() );
        } elseif ( $this->has_task_reward_claimed( $user_id, $task_id ) ) {
            $status = 'done';
            $status_text = __( '已领取', 'qilingshop' );
        } elseif ( $this->has_completed_first_resource_order( $user_id ) ) {
            $status = 'pending';
            $status_text = __( '可领取', 'qilingshop' );
            $action_label = __( '领取奖励', 'qilingshop' );
            $action_url = $this->get_task_claim_url( $task_id );
        } else {
            $status = 'waiting';
            $status_text = __( '未完成', 'qilingshop' );
            $action_label = __( '去购买资源', 'qilingshop' );
            $action_url = $this->get_shop_url();
        }

        return array(
            'id' => $task_id,
            'title' => $meta['title'],
            'desc' => __( '首次完成资源购买后可领取奖励', 'qilingshop' ),
            'reward' => $this->format_task_reward( $reward_points ),
            'status' => $status,
            'status_text' => $status_text,
            'action_label' => $action_label,
            'action_url' => $action_url,
        );
    }

    /**
     * 首次商城下单支付任务
     *
     * @param int $user_id
     * @return array
     */
    private function get_first_shop_paid_task( $user_id ) {
        $task_id = 'first_shop_paid';
        $meta = $this->get_claim_task_meta( $task_id );
        $reward_points = $this->get_task_reward_points( $task_id );

        $status = 'inactive';
        $status_text = __( '未启用', 'qilingshop' );
        $action_label = '';
        $action_url = '';

        if ( ! $this->is_task_enabled( $task_id ) ) {
            $status = 'inactive';
            $status_text = __( '未启用', 'qilingshop' );
        } elseif ( $reward_points <= 0 ) {
            $status = 'inactive';
            $status_text = __( '未配置奖励', 'qilingshop' );
        } elseif ( ! $user_id ) {
            $status = 'locked';
            $status_text = __( '请先登录', 'qilingshop' );
            $action_label = __( '立即登录', 'qilingshop' );
            $action_url = wp_login_url( $this->get_task_center_url() );
        } elseif ( $this->has_task_reward_claimed( $user_id, $task_id ) ) {
            $status = 'done';
            $status_text = __( '已领取', 'qilingshop' );
        } elseif ( $this->has_completed_first_shop_paid( $user_id ) ) {
            $status = 'pending';
            $status_text = __( '可领取', 'qilingshop' );
            $action_label = __( '领取奖励', 'qilingshop' );
            $action_url = $this->get_task_claim_url( $task_id );
        } else {
            $status = 'waiting';
            $status_text = __( '未完成', 'qilingshop' );
            $action_label = __( '去商城下单', 'qilingshop' );
            $action_url = $this->get_shop_url();
        }

        return array(
            'id' => $task_id,
            'title' => $meta['title'],
            'desc' => __( '首次在商城完成支付后可领取奖励', 'qilingshop' ),
            'reward' => $this->format_task_reward( $reward_points ),
            'status' => $status,
            'status_text' => $status_text,
            'action_label' => $action_label,
            'action_url' => $action_url,
        );
    }

    /**
     * 任务奖励领取入口
     *
     * URL: ?qls_task_claim=first_invite&_wpnonce=xxx
     *
     * @return void
     */
    public function handle_task_claim() {
        if ( empty( $_GET['qls_task_claim'] ) ) {
            return;
        }

        $task_id = sanitize_key( wp_unslash( $_GET['qls_task_claim'] ) );
        $redirect_base = remove_query_arg(
            array( 'qls_task_claim', '_wpnonce', 'qls_task_claim_result', 'qls_task_claim_code' ),
            $this->get_task_center_url()
        );

        if ( ! is_user_logged_in() ) {
            $target = add_query_arg(
                array(
                    'qls_task_claim_result' => 'error',
                    'qls_task_claim_code' => 'not_logged_in',
                ),
                $redirect_base
            );
            wp_safe_redirect( wp_login_url( $target ) );
            exit;
        }

        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( $task_id === '' || ! wp_verify_nonce( $nonce, 'qls_task_claim_' . $task_id ) ) {
            $target = add_query_arg(
                array(
                    'qls_task_claim_result' => 'error',
                    'qls_task_claim_code' => 'invalid_nonce',
                ),
                $redirect_base
            );
            wp_safe_redirect( $target );
            exit;
        }

        $result = $this->claim_task_reward( get_current_user_id(), $task_id );
        $target = add_query_arg(
            array(
                'qls_task_claim_result' => ! empty( $result['success'] ) ? 'success' : 'error',
                'qls_task_claim_code' => sanitize_key( $result['code'] ?? 'unknown' ),
            ),
            $redirect_base
        );

        wp_safe_redirect( $target );
        exit;
    }

    /**
     * 领取任务奖励
     *
     * @param int    $user_id
     * @param string $task_id
     * @return array
     */
    private function claim_task_reward( $user_id, $task_id ) {
        $user_id = (int) $user_id;
        $task_id = sanitize_key( $task_id );
        $meta = $this->get_claim_task_meta( $task_id );

        if ( $user_id <= 0 ) {
            return array( 'success' => false, 'code' => 'not_logged_in' );
        }

        if ( empty( $meta ) ) {
            return array( 'success' => false, 'code' => 'invalid_task' );
        }

        if ( ! $this->is_task_enabled( $task_id ) ) {
            return array( 'success' => false, 'code' => 'disabled' );
        }

        $reward_points = $this->get_task_reward_points( $task_id );
        if ( $reward_points <= 0 ) {
            return array( 'success' => false, 'code' => 'reward_not_set' );
        }

        if ( $this->has_task_reward_claimed( $user_id, $task_id ) ) {
            return array( 'success' => true, 'code' => 'already_claimed' );
        }

        $checker = $meta['checker'] ?? null;
        $completed = is_callable( $checker ) ? (bool) call_user_func( $checker, $user_id ) : false;
        if ( ! $completed ) {
            return array( 'success' => false, 'code' => 'not_completed' );
        }

        $claim_state = $this->acquire_task_reward_claim( $user_id, $task_id );
        if ( $claim_state === 'completed' ) {
            return array( 'success' => true, 'code' => 'already_claimed' );
        }
        if ( $claim_state !== 'acquired' ) {
            return array( 'success' => false, 'code' => 'processing' );
        }

        $points = QilingShop_Points::instance();
        $added = $points->add_points(
            $user_id,
            $reward_points,
            $meta['source'],
            sprintf( __( '任务奖励：%s', 'qilingshop' ), $meta['title'] ),
            1
        );

        if ( ! $added ) {
            $this->release_task_reward_claim( $user_id, $task_id );
            return array( 'success' => false, 'code' => 'add_points_failed' );
        }

        if ( ! $this->complete_task_reward_claim( $user_id, $task_id ) ) {
            return array( 'success' => false, 'code' => 'claim_finalize_failed' );
        }

        do_action(
            'qilingshop_send_notification',
            array(
                'user_id' => $user_id,
                'title'   => __( '任务奖励已到账', 'qilingshop' ),
                'content' => sprintf(
                    __( '任务“%s”已领取，获得 %s。', 'qilingshop' ),
                    $meta['title'],
                    $this->format_task_reward( $reward_points )
                ),
                'type'    => 'success',
                'scene'   => 'qilingshop_task_reward',
                'link'    => $this->get_account_url( 'qls-points' ),
            )
        );

        do_action(
            'qilingshop_growth_task_completed',
            $user_id,
            $task_id,
            array(
                'source_id' => absint( $user_id ) + absint( crc32( $task_id ) ),
            )
        );

        return array( 'success' => true, 'code' => 'claimed' );
    }

    /**
     * 获取任务元信息
     *
     * @param string $task_id
     * @return array
     */
    private function get_claim_task_meta( $task_id ) {
        $task_id = sanitize_key( $task_id );
        $tasks = array(
            'first_invite' => array(
                'title' => __( '首次邀请成功', 'qilingshop' ),
                'source' => 'task_first_invite',
                'enabled_option' => 'qilingshop_task_first_invite_enabled',
                'points_option' => 'qilingshop_task_first_invite_points',
                'checker' => array( $this, 'has_completed_first_invite' ),
            ),
            'first_resource_order' => array(
                'title' => __( '首次资源购买', 'qilingshop' ),
                'source' => 'task_first_resource_order',
                'enabled_option' => 'qilingshop_task_first_resource_order_enabled',
                'points_option' => 'qilingshop_task_first_resource_order_points',
                'checker' => array( $this, 'has_completed_first_resource_order' ),
            ),
            'first_shop_paid' => array(
                'title' => __( '首次商城下单支付', 'qilingshop' ),
                'source' => 'task_first_shop_paid',
                'enabled_option' => 'qilingshop_task_first_shop_paid_enabled',
                'points_option' => 'qilingshop_task_first_shop_paid_points',
                'checker' => array( $this, 'has_completed_first_shop_paid' ),
            ),
        );

        return $tasks[ $task_id ] ?? array();
    }

    /**
     * 任务是否启用
     *
     * @param string $task_id
     * @return bool
     */
    private function is_task_enabled( $task_id ) {
        $meta = $this->get_claim_task_meta( $task_id );
        if ( empty( $meta['enabled_option'] ) ) {
            return false;
        }
        return (bool) get_option( $meta['enabled_option'], false );
    }

    /**
     * 获取任务奖励积分
     *
     * @param string $task_id
     * @return float
     */
    private function get_task_reward_points( $task_id ) {
        $meta = $this->get_claim_task_meta( $task_id );
        if ( empty( $meta['points_option'] ) ) {
            return 0;
        }
        return max( 0, (float) get_option( $meta['points_option'], 0 ) );
    }

    /**
     * 是否已领取任务奖励
     *
     * @param int    $user_id
     * @param string $task_id
     * @return bool
     */
    private function has_task_reward_claimed( $user_id, $task_id ) {
        global $wpdb;

        $table = $this->get_task_reward_claims_table();
        $record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT status FROM {$table} WHERE user_id = %d AND task_id = %s LIMIT 1",
                (int) $user_id,
                sanitize_key( $task_id )
            )
        );
        if ( $record && (int) $record->status === self::TASK_REWARD_STATUS_COMPLETED ) {
            return true;
        }

        $meta = $this->get_claim_task_meta( $task_id );
        if ( empty( $meta['source'] ) ) {
            return false;
        }
        return QilingShop_Points::instance()->has_points_log( (int) $user_id, $meta['source'], 1 );
    }

    /**
     * 获取任务奖励领取记录表
     *
     * @return string
     */
    private function get_task_reward_claims_table() {
        global $wpdb;
        return $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'task_reward_claims';
    }

    /**
     * 抢占任务奖励领取权
     *
     * @param int    $user_id
     * @param string $task_id
     * @return string acquired|completed|processing|error
     */
    private function acquire_task_reward_claim( $user_id, $task_id ) {
        global $wpdb;

        $user_id = (int) $user_id;
        $task_id = sanitize_key( $task_id );
        if ( $user_id <= 0 || $task_id === '' ) {
            return 'error';
        }

        $table = $this->get_task_reward_claims_table();
        $now = current_time( 'mysql' );
        $timeout = 300;

        $wpdb->query( 'START TRANSACTION' );

        try {
            $record = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE user_id = %d AND task_id = %s LIMIT 1 FOR UPDATE",
                    $user_id,
                    $task_id
                )
            );

            if ( $record && (int) $record->status === self::TASK_REWARD_STATUS_COMPLETED ) {
                $wpdb->query( 'COMMIT' );
                return 'completed';
            }

            if ( $record && (int) $record->status === self::TASK_REWARD_STATUS_PROCESSING ) {
                if ( $this->has_task_reward_points_log( $user_id, $task_id ) ) {
                    $updated = $wpdb->update(
                        $table,
                        array(
                            'status'       => self::TASK_REWARD_STATUS_COMPLETED,
                            'completed_at' => $now,
                            'updated_at'   => $now,
                        ),
                        array( 'id' => (int) $record->id )
                    );
                    if ( $updated === false ) {
                        throw new Exception( 'Failed to finalize existing task reward claim' );
                    }
                    $wpdb->query( 'COMMIT' );
                    return 'completed';
                }

                $claimed_at = ! empty( $record->claimed_at ) ? strtotime( (string) $record->claimed_at ) : 0;
                $is_stale = $claimed_at > 0 && ( current_time( 'timestamp' ) - $claimed_at ) >= $timeout;
                if ( ! $is_stale ) {
                    $wpdb->query( 'COMMIT' );
                    return 'processing';
                }

                $updated = $wpdb->update(
                    $table,
                    array(
                        'claimed_at'   => $now,
                        'completed_at' => null,
                        'updated_at'   => $now,
                    ),
                    array(
                        'id'     => (int) $record->id,
                        'status' => self::TASK_REWARD_STATUS_PROCESSING,
                    )
                );
                if ( $updated === false ) {
                    throw new Exception( 'Failed to refresh stale task reward claim' );
                }
                $wpdb->query( 'COMMIT' );
                return 'acquired';
            }

            if ( ! $record ) {
                $inserted = $wpdb->insert(
                    $table,
                    array(
                        'user_id'      => $user_id,
                        'task_id'      => $task_id,
                        'status'       => self::TASK_REWARD_STATUS_PROCESSING,
                        'claimed_at'   => $now,
                        'completed_at' => null,
                        'created_at'   => $now,
                        'updated_at'   => $now,
                    )
                );

                if ( ! $inserted ) {
                    $duplicate = stripos( (string) $wpdb->last_error, 'Duplicate entry' ) !== false;
                    if ( ! $duplicate ) {
                        throw new Exception( 'Failed to create task reward claim' );
                    }

                    $record = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT * FROM {$table} WHERE user_id = %d AND task_id = %s LIMIT 1 FOR UPDATE",
                            $user_id,
                            $task_id
                        )
                    );

                    if ( ! $record ) {
                        throw new Exception( 'Failed to reload duplicated task reward claim' );
                    }

                    if ( (int) $record->status === self::TASK_REWARD_STATUS_COMPLETED ) {
                        $wpdb->query( 'COMMIT' );
                        return 'completed';
                    }

                    $wpdb->query( 'COMMIT' );
                    return 'processing';
                }
            }

            $wpdb->query( 'COMMIT' );
            return 'acquired';
        } catch ( Exception $e ) {
            $wpdb->query( 'ROLLBACK' );
            qilingshop_log(
                'Task reward acquire failed: ' . $e->getMessage(),
                'error',
                array(
                    'user_id' => $user_id,
                    'task_id' => $task_id,
                )
            );
            return 'error';
        }
    }

    /**
     * 释放任务奖励领取权
     *
     * @param int    $user_id
     * @param string $task_id
     * @return void
     */
    private function release_task_reward_claim( $user_id, $task_id ) {
        global $wpdb;

        $user_id = (int) $user_id;
        $task_id = sanitize_key( $task_id );
        if ( $user_id <= 0 || $task_id === '' ) {
            return;
        }

        if ( $this->has_task_reward_points_log( $user_id, $task_id ) ) {
            $this->complete_task_reward_claim( $user_id, $task_id );
            return;
        }

        $table = $this->get_task_reward_claims_table();
        $wpdb->delete(
            $table,
            array(
                'user_id' => $user_id,
                'task_id' => $task_id,
                'status'  => self::TASK_REWARD_STATUS_PROCESSING,
            )
        );
    }

    /**
     * 标记任务奖励领取完成
     *
     * @param int    $user_id
     * @param string $task_id
     * @return bool
     */
    private function complete_task_reward_claim( $user_id, $task_id ) {
        global $wpdb;

        $user_id = (int) $user_id;
        $task_id = sanitize_key( $task_id );
        if ( $user_id <= 0 || $task_id === '' ) {
            return false;
        }

        $table = $this->get_task_reward_claims_table();
        $updated = $wpdb->update(
            $table,
            array(
                'status'       => self::TASK_REWARD_STATUS_COMPLETED,
                'completed_at' => current_time( 'mysql' ),
                'updated_at'   => current_time( 'mysql' ),
            ),
            array(
                'user_id' => $user_id,
                'task_id' => $task_id,
            )
        );

        return $updated !== false;
    }

    /**
     * 是否已存在任务奖励积分流水
     *
     * @param int    $user_id
     * @param string $task_id
     * @return bool
     */
    private function has_task_reward_points_log( $user_id, $task_id ) {
        $meta = $this->get_claim_task_meta( $task_id );
        if ( empty( $meta['source'] ) ) {
            return false;
        }

        return QilingShop_Points::instance()->has_points_log( (int) $user_id, $meta['source'], 1 );
    }

    /**
     * 获取任务领取链接
     *
     * @param string $task_id
     * @return string
     */
    private function get_task_claim_url( $task_id ) {
        $url = add_query_arg(
            array(
                'qls_task_claim' => sanitize_key( $task_id ),
            ),
            $this->get_task_center_url()
        );
        return wp_nonce_url( $url, 'qls_task_claim_' . sanitize_key( $task_id ) );
    }

    /**
     * 格式化任务奖励显示
     *
     * @param float $points
     * @return string
     */
    private function format_task_reward( $points ) {
        $points = max( 0, (float) $points );
        $points_name = function_exists( 'qilingshop_get_points_name' ) ? qilingshop_get_points_name() : __( '积分', 'qilingshop' );
        if ( $points <= 0 ) {
            return __( '未配置奖励', 'qilingshop' );
        }
        return number_format_i18n( $points, 0 ) . ' ' . $points_name;
    }

    /**
     * 判断是否完成首次邀请成功
     *
     * @param int $user_id
     * @return bool
     */
    private function has_completed_first_invite( $user_id ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return false;
        }
        $user_info = QilingShop_Points::instance()->get_user_info( $user_id );
        return ! empty( $user_info ) && (int) ( $user_info->invite_count ?? 0 ) > 0;
    }

    /**
     * 判断是否完成首次资源购买
     *
     * @param int $user_id
     * @return bool
     */
    private function has_completed_first_resource_order( $user_id ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 || ! class_exists( 'QilingShop_Order' ) ) {
            return false;
        }

        $db = QilingShop_Database::instance();
        $wpdb = $db->get_wpdb();
        $table = $db->get_table( 'orders' );
        $paid = QilingShop_Order::STATUS_PAID;
        $refunded = QilingShop_Order::STATUS_REFUNDED;

        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE user_id = %d
                   AND (order_type = %s OR (order_type = '' AND post_id > 0))
                   AND status IN (%d, %d)",
                $user_id,
                'resource',
                $paid,
                $refunded
            )
        );

        return $count > 0;
    }

    /**
     * 判断是否完成首次商城下单支付
     *
     * @param int $user_id
     * @return bool
     */
    private function has_completed_first_shop_paid( $user_id ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 || ! class_exists( 'QLS_Shop_Database' ) ) {
            return false;
        }

        $db = QLS_Shop_Database::instance();
        $wpdb = $db->get_wpdb();
        $table = $db->get_table( 'orders' );
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE user_id = %d
                   AND paid_at IS NOT NULL
                   AND paid_at <> %s",
                $user_id,
                '0000-00-00 00:00:00'
            )
        );

        return $count > 0;
    }

    /**
     * 获取商城首页 URL
     *
     * @return string
     */
    private function get_shop_url() {
        if ( function_exists( 'qls_shop_public' ) ) {
            $shop_url = qls_shop_public()->get_shop_url();
            if ( ! empty( $shop_url ) ) {
                return $shop_url;
            }
        }
        return home_url( '/' );
    }

    /**
     * 每日任务调度
     */
    public function run_daily_tasks( $force = false ) {
        $force = (bool) $force;
        $result = array(
            'timestamp' => current_time( 'mysql' ),
            'tasks'     => array(),
        );

        if ( ! $this->acquire_external_task_lock() ) {
            $result['locked'] = true;
            $result['message'] = __( 'task runner is locked', 'qilingshop' );
            return $result;
        }

        try {
            // 生日券：每 6 小时检查一次即可
            if ( $this->should_run_external_task( 'birthday_coupon', self::TASK_INTERVAL_BIRTHDAY, $force ) ) {
                $this->maybe_send_birthday_coupons();
                $this->mark_external_task_runtime( 'birthday_coupon' );
                $result['tasks']['birthday_coupon'] = 'ran';
            } else {
                $result['tasks']['birthday_coupon'] = 'skipped';
            }

            // VIP 到期 / 游客清理 / 积分有效期：每小时执行
            if ( $this->should_run_external_task( 'points_vip_guest_maintenance', self::TASK_INTERVAL_HOURLY, $force ) ) {
                do_action( 'qilingshop_daily_vip_check' );
                do_action( 'qilingshop_daily_guest_cleanup' );
                do_action( 'qilingshop_daily_points_maintenance' );
                $this->mark_external_task_runtime( 'points_vip_guest_maintenance' );
                $result['tasks']['points_vip_guest_maintenance'] = 'ran';
            } else {
                $result['tasks']['points_vip_guest_maintenance'] = 'skipped';
            }

            // 商城订单自动取消：建议 10 分钟执行
            if ( $this->should_run_external_task( 'shop_auto_cancel', self::TASK_INTERVAL_NORMAL, $force ) ) {
                do_action( 'qls_shop_auto_cancel_orders' );
                $this->mark_external_task_runtime( 'shop_auto_cancel' );
                $result['tasks']['shop_auto_cancel'] = 'ran';
            } else {
                $result['tasks']['shop_auto_cancel'] = 'skipped';
            }

            // 支付补单对账：建议 10 分钟执行（外部触发，内部按任务节流）
            if ( $this->should_run_external_task( 'payment_reconcile', self::TASK_INTERVAL_NORMAL, $force ) ) {
                $result['payment_reconcile'] = $this->reconcile_paid_orders( 200 );
                $this->mark_external_task_runtime( 'payment_reconcile' );
                $result['tasks']['payment_reconcile'] = 'ran';
            } else {
                $result['tasks']['payment_reconcile'] = 'skipped';
            }

            // 支付挽回通知（未支付订单召回）：建议 10 分钟执行，可独立开关
            if ( ! $this->is_payment_recovery_remind_enabled() ) {
                $result['tasks']['payment_recovery_remind'] = 'disabled';
            } elseif ( $this->should_run_external_task( 'payment_recovery_remind', self::TASK_INTERVAL_NORMAL, $force ) ) {
                $result['payment_recovery_remind'] = $this->run_payment_recovery_remind_task( 200 );
                $this->mark_external_task_runtime( 'payment_recovery_remind' );
                $result['tasks']['payment_recovery_remind'] = 'ran';
            } else {
                $result['tasks']['payment_recovery_remind'] = 'skipped';
            }

            // 好友助力：过期清理 + 待支付对账，建议 10 分钟执行
            if ( $this->should_run_external_task( 'assist_campaign_maintenance', self::TASK_INTERVAL_NORMAL, $force ) ) {
                $expired = 0;
                $reconciled = 0;
                $activities_down = 0;
                if ( function_exists( 'qls_assist' ) ) {
                    $activities_down = (int) qls_assist()->process_expired_activities( 200 );
                    $expired = (int) qls_assist()->process_expired_campaigns( 200 );
                    $reconciled = (int) qls_assist()->reconcile_pending_campaign_orders( 200 );
                }
                $this->mark_external_task_runtime( 'assist_campaign_maintenance' );
                $result['tasks']['assist_campaign_maintenance'] = 'ran';
                $result['assist_campaign_maintenance'] = array(
                    'activities_down' => $activities_down,
                    'expired' => $expired,
                    'reconciled' => $reconciled,
                );
            } else {
                $result['tasks']['assist_campaign_maintenance'] = 'skipped';
            }

            // 团购过期检查：建议 5 分钟执行
            if ( $this->should_run_external_task( 'group_expire_check', self::TASK_INTERVAL_FAST, $force ) ) {
                if ( class_exists( 'QLS_Group_Cron' ) ) {
                    QLS_Group_Cron::instance()->check_expired_groups();
                } else {
                    do_action( 'qls_shop_check_expired_groups' );
                }
                $this->mark_external_task_runtime( 'group_expire_check' );
                $result['tasks']['group_expire_check'] = 'ran';
            } else {
                $result['tasks']['group_expire_check'] = 'skipped';
            }
        } finally {
            $this->release_external_task_lock();
        }

        return $result;
    }

    /**
     * 支付补单对账（已支付但业务未完成）
     *
     * @param int $limit
     * @return array
     */
    private function reconcile_paid_orders( $limit = 200 ) {
        $limit = max( 20, min( 500, absint( $limit ) ) );
        $per_bucket = max( 20, (int) ceil( $limit / 3 ) );

        return array(
            'recharge' => $this->reconcile_paid_recharge_orders( $per_bucket ),
            'resource_vip' => $this->reconcile_paid_resource_vip_orders( $per_bucket ),
            'shop' => $this->reconcile_paid_shop_orders( $per_bucket ),
        );
    }

    /**
     * 对账充值订单：已支付但未到账积分
     *
     * @param int $limit
     * @return array
     */
    private function reconcile_paid_recharge_orders( $limit = 80 ) {
        $result = array(
            'scanned' => 0,
            'repaired' => 0,
            'failed' => 0,
        );

        if ( ! class_exists( 'QilingShop_Recharge' ) ) {
            return $result;
        }

        $db = QilingShop_Database::instance();
        $recharge_table = $db->get_table( 'recharge' );
        $limit = max( 1, absint( $limit ) );

        global $wpdb;
        $orders = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.order_no, r.payment_no
                 FROM {$recharge_table} r
                 WHERE r.status = %d
                   AND (r.paid_handled IS NULL OR r.paid_handled <> %d)
                 ORDER BY r.id DESC
                 LIMIT %d",
                (int) QilingShop_Recharge::STATUS_PAID,
                1,
                $limit
            )
        );

        if ( empty( $orders ) ) {
            return $result;
        }

        $result['scanned'] = count( $orders );
        $recharge = QilingShop_Recharge::instance();
        foreach ( $orders as $order ) {
            $ok = $recharge->complete(
                sanitize_text_field( (string) ( $order->order_no ?? '' ) ),
                sanitize_text_field( (string) ( $order->payment_no ?? '' ) )
            );
            if ( $ok ) {
                $result['repaired']++;
            } else {
                $result['failed']++;
            }
        }

        return $result;
    }

    /**
     * 对账资源/VIP订单：已支付但未生成下载权限或未升级 VIP
     *
     * @param int $limit
     * @return array
     */
    private function reconcile_paid_resource_vip_orders( $limit = 80 ) {
        $result = array(
            'scanned' => 0,
            'repaired' => 0,
            'failed' => 0,
        );

        if ( ! class_exists( 'QilingShop_Order' ) ) {
            return $result;
        }

        $db = QilingShop_Database::instance();
        $orders_table = $db->get_table( 'orders' );
        $limit = max( 1, absint( $limit ) );

        global $wpdb;
        $orders = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT o.order_no, o.payment_no, o.payment_method
                 FROM {$orders_table} o
                 WHERE o.status = %d
                   AND (o.paid_handled IS NULL OR o.paid_handled <> %d)
                 ORDER BY o.id DESC
                 LIMIT %d",
                (int) QilingShop_Order::STATUS_PAID,
                1,
                $limit
            )
        );

        if ( empty( $orders ) ) {
            return $result;
        }

        $result['scanned'] = count( $orders );
        $order_manager = QilingShop_Order::instance();
        foreach ( $orders as $order ) {
            $ok = $order_manager->mark_paid(
                sanitize_text_field( (string) ( $order->order_no ?? '' ) ),
                sanitize_text_field( (string) ( $order->payment_no ?? '' ) ),
                sanitize_text_field( (string) ( $order->payment_method ?? '' ) )
            );
            if ( $ok ) {
                $result['repaired']++;
            } else {
                $result['failed']++;
            }
        }

        return $result;
    }

    /**
     * 对账商城订单：已支付但支付后处理未完成
     *
     * @param int $limit
     * @return array
     */
    private function reconcile_paid_shop_orders( $limit = 80 ) {
        $result = array(
            'scanned' => 0,
            'repaired' => 0,
            'failed' => 0,
        );

        if ( ! function_exists( 'qls_shop_order' ) || ! class_exists( 'QLS_Shop_Order' ) ) {
            return $result;
        }

        global $wpdb;
        $shop_prefix = defined( 'QLS_SHOP_TABLE_PREFIX' ) ? QLS_SHOP_TABLE_PREFIX : 'qls_shop_';
        $orders_table = $wpdb->prefix . $shop_prefix . 'orders';
        $limit = max( 1, absint( $limit ) );

        $orders = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT o.order_no, o.payment_no, o.payment_method
                 FROM {$orders_table} o
                 WHERE o.status IN (%d, %d, %d)
                   AND (o.paid_handled IS NULL OR o.paid_handled <> %d)
                 ORDER BY o.id DESC
                 LIMIT %d",
                (int) QLS_Shop_Order::STATUS_PAID,
                (int) QLS_Shop_Order::STATUS_SHIPPED,
                (int) QLS_Shop_Order::STATUS_COMPLETED,
                1,
                $limit
            )
        );

        if ( empty( $orders ) ) {
            return $result;
        }

        $result['scanned'] = count( $orders );
        $shop_order_manager = qls_shop_order();
        foreach ( $orders as $order ) {
            $ok = $shop_order_manager->mark_paid(
                sanitize_text_field( (string) ( $order->order_no ?? '' ) ),
                sanitize_text_field( (string) ( $order->payment_no ?? '' ) ),
                sanitize_text_field( (string) ( $order->payment_method ?? '' ) )
            );
            if ( $ok ) {
                $result['repaired']++;
            } else {
                $result['failed']++;
            }
        }

        return $result;
    }

    /**
     * 获取外部任务执行锁
     *
     * @return bool
     */
    private function acquire_external_task_lock() {
        $now = current_time( 'timestamp' );
        $token = wp_generate_password( 20, false, false );
        $payload = array(
            'token'   => $token,
            'expires' => $now + self::TASK_LOCK_TTL,
        );

        if ( add_option( self::EXTERNAL_TASK_LOCK_KEY, $payload, '', 'no' ) ) {
            $this->runtime_lock_token = $token;
            return true;
        }

        $existing = get_option( self::EXTERNAL_TASK_LOCK_KEY, array() );
        $expires = isset( $existing['expires'] ) ? absint( $existing['expires'] ) : 0;
        if ( $expires > $now ) {
            return false;
        }

        delete_option( self::EXTERNAL_TASK_LOCK_KEY );
        if ( add_option( self::EXTERNAL_TASK_LOCK_KEY, $payload, '', 'no' ) ) {
            $this->runtime_lock_token = $token;
            return true;
        }

        return false;
    }

    /**
     * 释放外部任务执行锁
     *
     * @return void
     */
    private function release_external_task_lock() {
        if ( $this->runtime_lock_token === '' ) {
            return;
        }

        $existing = get_option( self::EXTERNAL_TASK_LOCK_KEY, array() );
        $token = isset( $existing['token'] ) ? (string) $existing['token'] : '';
        if ( hash_equals( $token, $this->runtime_lock_token ) ) {
            delete_option( self::EXTERNAL_TASK_LOCK_KEY );
        }

        $this->runtime_lock_token = '';
    }

    /**
     * 是否应当执行外部任务
     *
     * @param string $task_key
     * @param int    $interval
     * @param bool   $force
     * @return bool
     */
    private function should_run_external_task( $task_key, $interval, $force = false ) {
        if ( $force ) {
            return true;
        }

        $interval = max( 60, absint( $interval ) );
        $runtime = get_option( self::EXTERNAL_TASK_META_KEY, array() );
        $last_run = isset( $runtime[ $task_key ] ) ? absint( $runtime[ $task_key ] ) : 0;
        if ( $last_run <= 0 ) {
            return true;
        }

        $now = current_time( 'timestamp' );
        return ( $now - $last_run ) >= $interval;
    }

    /**
     * 记录外部任务执行时间
     *
     * @param string $task_key
     * @return void
     */
    private function mark_external_task_runtime( $task_key ) {
        $runtime = get_option( self::EXTERNAL_TASK_META_KEY, array() );
        if ( ! is_array( $runtime ) ) {
            $runtime = array();
        }

        $runtime[ $task_key ] = current_time( 'timestamp' );
        update_option( self::EXTERNAL_TASK_META_KEY, $runtime );
    }

    /**
     * 未支付订单召回通知任务是否启用
     *
     * @return bool
     */
    private function is_payment_recovery_remind_enabled() {
        return (bool) get_option( 'qilingshop_task_payment_recovery_remind_enabled', false );
    }

    /**
     * 执行未支付订单召回通知（资源/VIP/充值/商城）
     *
     * @param int $limit
     * @return array
     */
    public function run_payment_recovery_remind_task( $limit = 200 ) {
        $limit = max( 30, min( 500, absint( $limit ) ) );
        $delay_minutes = $this->get_payment_recovery_delay_minutes();
        $lookback_days = $this->get_payment_recovery_lookback_days();
        $channels = $this->get_payment_recovery_notify_channels();

        $result = array(
            'delay_minutes' => $delay_minutes,
            'lookback_days' => $lookback_days,
            'channels'      => $channels,
            'resource_vip'  => array( 'scanned' => 0, 'notified' => 0, 'site_sent' => 0, 'email_sent' => 0, 'skipped' => 0 ),
            'recharge'      => array( 'scanned' => 0, 'notified' => 0, 'site_sent' => 0, 'email_sent' => 0, 'skipped' => 0 ),
            'shop'          => array( 'scanned' => 0, 'notified' => 0, 'site_sent' => 0, 'email_sent' => 0, 'skipped' => 0 ),
            'notified'      => 0,
        );

        if ( empty( $channels ) ) {
            $result['message'] = __( '未配置召回通知方式', 'qilingshop' );
            return $result;
        }

        if ( ! $this->is_payment_recovery_user_notify_enabled() ) {
            $result['message'] = __( '支付召回的用户通知开关已关闭，任务已跳过。', 'qilingshop' );
            return $result;
        }

        $site_enabled = ! in_array( 'site', $channels, true ) || $this->is_payment_recovery_site_notify_enabled();
        $email_enabled = in_array( 'email', $channels, true );
        if ( ! $site_enabled && ! $email_enabled ) {
            $result['message'] = __( '站内通知场景未开启，且未启用邮件通知，召回任务已跳过。', 'qilingshop' );
            return $result;
        }

        // 一次性回填历史召回通知记录，避免历史已通知订单再次触发召回
        $this->maybe_backfill_payment_recovery_notice_history();

        $cutoff_time = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $delay_minutes * 60 ) );
        $window_start_time = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $lookback_days * DAY_IN_SECONDS ) );
        $per_bucket = max( 10, (int) ceil( $limit / 3 ) );

        $result['resource_vip'] = $this->remind_pending_resource_vip_orders( $per_bucket, $cutoff_time, $window_start_time, $channels );
        $result['recharge'] = $this->remind_pending_recharge_orders( $per_bucket, $cutoff_time, $window_start_time, $channels );
        $result['shop'] = $this->remind_pending_shop_orders( $per_bucket, $cutoff_time, $window_start_time, $channels );
        $result['notified'] = (int) $result['resource_vip']['notified'] + (int) $result['recharge']['notified'] + (int) $result['shop']['notified'];

        return $result;
    }

    /**
     * 获取召回通知方式（site/email，可多选）
     *
     * @return array
     */
    private function get_payment_recovery_notify_channels() {
        $raw = get_option( 'qilingshop_task_payment_recovery_notify_channels', array( 'site', 'email' ) );
        if ( ! is_array( $raw ) ) {
            $raw = array( $raw );
        }

        $channels = array();
        foreach ( $raw as $channel ) {
            $channel = sanitize_key( (string) $channel );
            if ( in_array( $channel, array( 'site', 'email' ), true ) ) {
                $channels[] = $channel;
            }
        }

        return array_values( array_unique( $channels ) );
    }

    /**
     * 获取召回触发时延（分钟）
     *
     * @return int
     */
    private function get_payment_recovery_delay_minutes() {
        $minutes = absint( get_option( 'qilingshop_task_payment_recovery_delay_minutes', 30 ) );
        return max( 5, min( 10080, $minutes ) );
    }

    /**
     * 获取召回扫描回溯天数（仅扫描最近N天订单）
     *
     * @return int
     */
    private function get_payment_recovery_lookback_days() {
        $days = absint( get_option( 'qilingshop_task_payment_recovery_lookback_days', 7 ) );
        return max( 1, min( 90, $days ) );
    }

    /**
     * 扫描并提醒未支付资源/VIP订单
     *
     * @param int    $limit
     * @param string $cutoff_time
     * @param string $window_start_time
     * @param array  $channels
     * @return array
     */
    private function remind_pending_resource_vip_orders( $limit, $cutoff_time, $window_start_time, $channels ) {
        $stats = array( 'scanned' => 0, 'notified' => 0, 'site_sent' => 0, 'email_sent' => 0, 'skipped' => 0 );

        if ( ! class_exists( 'QilingShop_Order' ) || ! class_exists( 'QilingShop_Database' ) ) {
            return $stats;
        }

        $db = QilingShop_Database::instance();
        $orders_table = $db->get_table( 'orders' );
        global $wpdb;
        $orders = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT order_no, user_id, order_type, payment_method, price_rmb, final_price, created_at
                 FROM {$orders_table}
                 WHERE status = %d
                   AND user_id > 0
                   AND created_at <= %s
                   AND created_at >= %s
                   AND (CASE WHEN final_price > 0 THEN final_price ELSE price_rmb END) > 0
                 ORDER BY id DESC
                 LIMIT %d",
                (int) QilingShop_Order::STATUS_PENDING,
                $cutoff_time,
                $window_start_time,
                max( 1, absint( $limit ) )
            )
        );

        if ( empty( $orders ) ) {
            return $stats;
        }

        foreach ( $orders as $order ) {
            $stats['scanned']++;
            $order_no = sanitize_text_field( (string) ( $order->order_no ?? '' ) );
            if ( $order_no === '' ) {
                $stats['skipped']++;
                continue;
            }

            $amount = (float) ( ( (float) ( $order->final_price ?? 0 ) > 0 ) ? $order->final_price : $order->price_rmb );
            if ( $amount <= 0 ) {
                $stats['skipped']++;
                continue;
            }

            $order_kind = (string) ( $order->order_type ?? '' ) === 'vip' ? __( 'VIP订单', 'qilingshop' ) : __( '资源订单', 'qilingshop' );
            $title = __( '订单待支付提醒', 'qilingshop' );
            $content = sprintf(
                __( '您的%s（订单号：%s）尚未支付，待支付金额：¥%s。', 'qilingshop' ),
                $order_kind,
                $order_no,
                number_format( $amount, 2, '.', '' )
            );
            $link = $this->build_resource_vip_pending_order_pay_link( $order );
            $email = $this->get_user_email_by_id( (int) ( $order->user_id ?? 0 ) );
            $subject = sprintf( __( '[%s] 订单待支付提醒', 'qilingshop' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
            $lines = array(
                __( '订单类型', 'qilingshop' ) => $order_kind,
                __( '订单号', 'qilingshop' )   => $order_no,
                __( '待支付金额', 'qilingshop' ) => '¥' . number_format( $amount, 2, '.', '' ),
                __( '下单时间', 'qilingshop' ) => (string) ( $order->created_at ?? '' ),
                __( '支付入口', 'qilingshop' ) => $link,
            );

            $this->dispatch_payment_recovery_notification( 'resource_vip', $order_no, (int) ( $order->user_id ?? 0 ), $title, $content, $link, $email, $subject, $lines, $channels, $stats );
        }

        return $stats;
    }

    /**
     * 扫描并提醒未支付充值订单
     *
     * @param int    $limit
     * @param string $cutoff_time
     * @param string $window_start_time
     * @param array  $channels
     * @return array
     */
    private function remind_pending_recharge_orders( $limit, $cutoff_time, $window_start_time, $channels ) {
        $stats = array( 'scanned' => 0, 'notified' => 0, 'site_sent' => 0, 'email_sent' => 0, 'skipped' => 0 );

        if ( ! class_exists( 'QilingShop_Recharge' ) || ! class_exists( 'QilingShop_Database' ) ) {
            return $stats;
        }

        $db = QilingShop_Database::instance();
        $recharge_table = $db->get_table( 'recharge' );
        global $wpdb;
        $orders = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT order_no, user_id, amount, final_amount, payment_method, created_at
                 FROM {$recharge_table}
                 WHERE status = %d
                   AND user_id > 0
                   AND created_at <= %s
                   AND created_at >= %s
                   AND (CASE WHEN final_amount > 0 THEN final_amount ELSE amount END) > 0
                 ORDER BY id DESC
                 LIMIT %d",
                (int) QilingShop_Recharge::STATUS_PENDING,
                $cutoff_time,
                $window_start_time,
                max( 1, absint( $limit ) )
            )
        );

        if ( empty( $orders ) ) {
            return $stats;
        }

        foreach ( $orders as $order ) {
            $stats['scanned']++;
            $order_no = sanitize_text_field( (string) ( $order->order_no ?? '' ) );
            if ( $order_no === '' ) {
                $stats['skipped']++;
                continue;
            }

            $amount = (float) ( ( (float) ( $order->final_amount ?? 0 ) > 0 ) ? $order->final_amount : $order->amount );
            if ( $amount <= 0 ) {
                $stats['skipped']++;
                continue;
            }

            $title = __( '充值待支付提醒', 'qilingshop' );
            $content = sprintf(
                __( '您的充值订单（订单号：%s）尚未支付，待支付金额：¥%s。', 'qilingshop' ),
                $order_no,
                number_format( $amount, 2, '.', '' )
            );
            $link = $this->build_recharge_pending_order_pay_link( $order );
            $email = $this->get_user_email_by_id( (int) ( $order->user_id ?? 0 ) );
            $subject = sprintf( __( '[%s] 充值待支付提醒', 'qilingshop' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
            $lines = array(
                __( '订单类型', 'qilingshop' ) => __( '积分充值', 'qilingshop' ),
                __( '订单号', 'qilingshop' )   => $order_no,
                __( '待支付金额', 'qilingshop' ) => '¥' . number_format( $amount, 2, '.', '' ),
                __( '下单时间', 'qilingshop' ) => (string) ( $order->created_at ?? '' ),
                __( '支付入口', 'qilingshop' ) => $link,
            );

            $this->dispatch_payment_recovery_notification( 'recharge', $order_no, (int) ( $order->user_id ?? 0 ), $title, $content, $link, $email, $subject, $lines, $channels, $stats );
        }

        return $stats;
    }

    /**
     * 扫描并提醒未支付商城订单
     *
     * @param int    $limit
     * @param string $cutoff_time
     * @param string $window_start_time
     * @param array  $channels
     * @return array
     */
    private function remind_pending_shop_orders( $limit, $cutoff_time, $window_start_time, $channels ) {
        $stats = array( 'scanned' => 0, 'notified' => 0, 'site_sent' => 0, 'email_sent' => 0, 'skipped' => 0 );

        if ( ! class_exists( 'QLS_Shop_Order' ) ) {
            return $stats;
        }

        global $wpdb;
        $shop_prefix = defined( 'QLS_SHOP_TABLE_PREFIX' ) ? QLS_SHOP_TABLE_PREFIX : 'qls_shop_';
        $orders_table = $wpdb->prefix . $shop_prefix . 'orders';
        $orders = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT order_no, user_id, final_amount, payment_method, created_at
                 FROM {$orders_table}
                 WHERE status = %d
                   AND user_id > 0
                   AND created_at <= %s
                   AND created_at >= %s
                   AND final_amount > 0
                 ORDER BY id DESC
                 LIMIT %d",
                (int) QLS_Shop_Order::STATUS_PENDING,
                $cutoff_time,
                $window_start_time,
                max( 1, absint( $limit ) )
            )
        );

        if ( empty( $orders ) ) {
            return $stats;
        }

        foreach ( $orders as $order ) {
            $stats['scanned']++;
            $order_no = sanitize_text_field( (string) ( $order->order_no ?? '' ) );
            if ( $order_no === '' ) {
                $stats['skipped']++;
                continue;
            }

            $amount = (float) ( $order->final_amount ?? 0 );
            if ( $amount <= 0 ) {
                $stats['skipped']++;
                continue;
            }

            $title = __( '商城订单待支付提醒', 'qilingshop' );
            $content = sprintf(
                __( '您的商城订单（订单号：%s）尚未支付，待支付金额：¥%s。', 'qilingshop' ),
                $order_no,
                number_format( $amount, 2, '.', '' )
            );
            $link = add_query_arg(
                array(
                    'pay'   => 'shop',
                    'order' => $order_no,
                ),
                home_url( '/' )
            );
            $email = $this->get_user_email_by_id( (int) ( $order->user_id ?? 0 ) );
            $subject = sprintf( __( '[%s] 商城订单待支付提醒', 'qilingshop' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
            $lines = array(
                __( '订单类型', 'qilingshop' ) => __( '实物商城订单', 'qilingshop' ),
                __( '订单号', 'qilingshop' )   => $order_no,
                __( '待支付金额', 'qilingshop' ) => '¥' . number_format( $amount, 2, '.', '' ),
                __( '下单时间', 'qilingshop' ) => (string) ( $order->created_at ?? '' ),
                __( '支付入口', 'qilingshop' ) => $link,
            );

            $this->dispatch_payment_recovery_notification( 'shop', $order_no, (int) ( $order->user_id ?? 0 ), $title, $content, $link, $email, $subject, $lines, $channels, $stats );
        }

        return $stats;
    }

    /**
     * 分发单条召回通知
     *
     * @param string $bucket
     * @param string $order_no
     * @param int    $user_id
     * @param string $title
     * @param string $content
     * @param string $link
     * @param string $email
     * @param string $subject
     * @param array  $lines
     * @param array  $channels
     * @param array  $stats
     * @return void
     */
    private function dispatch_payment_recovery_notification( $bucket, $order_no, $user_id, $title, $content, $link, $email, $subject, $lines, $channels, &$stats ) {
        $sent_any = false;
        $bucket = sanitize_key( (string) $bucket );
        $order_no = sanitize_text_field( (string) $order_no );
        $user_id = (int) $user_id;

        if ( ! $this->is_payment_recovery_user_notify_enabled() ) {
            $stats['skipped']++;
            return;
        }

        if ( $this->has_payment_recovery_notice_sent( $bucket, $order_no, 'all' ) ) {
            $stats['skipped']++;
            return;
        }

        if ( in_array( 'site', $channels, true ) && $user_id > 0 && $this->is_payment_recovery_site_notify_enabled() ) {
            if ( $this->send_payment_recovery_site_notification( $user_id, $title, $content, $link ) ) {
                $stats['site_sent']++;
                $sent_any = true;
            }
        }

        if ( in_array( 'email', $channels, true ) && $email !== '' ) {
            $mail_sent = $this->send_payment_recovery_email( $email, $subject, $lines );
            if ( $mail_sent ) {
                $stats['email_sent']++;
                $sent_any = true;
            }
        }

        if ( $sent_any ) {
            $this->mark_payment_recovery_notice_sent( $bucket, $order_no, 'all' );
            $stats['notified']++;
        } else {
            $stats['skipped']++;
        }
    }

    /**
     * 发送召回邮件
     *
     * @param string $to
     * @param string $subject
     * @param array  $lines
     * @return bool
     */
    private function send_payment_recovery_email( $to, $subject, $lines ) {
        $to = sanitize_email( $to );
        if ( $to === '' ) {
            return false;
        }

        $subject = wp_strip_all_tags( (string) $subject );
        if ( ! is_array( $lines ) ) {
            $lines = array();
        }

        $formatted = array();
        foreach ( $lines as $label => $value ) {
            $clean_value = trim( wp_strip_all_tags( (string) $value ) );
            if ( $clean_value === '' ) {
                continue;
            }
            $formatted[] = wp_strip_all_tags( (string) $label ) . '：' . $clean_value;
        }

        if ( empty( $formatted ) ) {
            return false;
        }

        $message = implode( "\n", $formatted );
        $headers = array( 'Content-Type: text/plain; charset=UTF-8' );

        if ( function_exists( 'developer_starter_build_html_email_template' ) ) {
            $pay_url = $this->extract_first_valid_url_from_lines( $lines );
            $html_message = developer_starter_build_html_email_template(
                array(
                    'title'       => $subject,
                    'intro'       => __( '您有一笔订单仍待支付，请尽快完成支付。', 'qilingshop' ),
                    'lines'       => $lines,
                    'button_text' => __( '立即去支付', 'qilingshop' ),
                    'button_url'  => $pay_url,
                    'notice'      => __( '若按钮无法点击，请复制邮件中的支付链接到浏览器打开。', 'qilingshop' ),
                )
            );
            if ( is_string( $html_message ) && trim( $html_message ) !== '' ) {
                $message = $html_message;
                $headers = array( 'Content-Type: text/html; charset=UTF-8' );
            }
        }

        return (bool) wp_mail( $to, $subject, $message, $headers );
    }

    /**
     * 从字段列表中提取首个合法 URL
     *
     * @param array $lines 字段列表
     * @return string
     */
    private function extract_first_valid_url_from_lines( $lines ) {
        if ( ! is_array( $lines ) ) {
            return '';
        }

        foreach ( $lines as $value ) {
            if ( is_array( $value ) ) {
                foreach ( $value as $item ) {
                    $url = trim( (string) $item );
                    if ( $url !== '' && wp_http_validate_url( $url ) ) {
                        return $url;
                    }
                }
                continue;
            }

            $url = trim( (string) $value );
            if ( $url !== '' && wp_http_validate_url( $url ) ) {
                return $url;
            }
        }

        return '';
    }

    /**
     * 资源/VIP待支付订单支付链接
     *
     * @param object $order
     * @return string
     */
    private function build_resource_vip_pending_order_pay_link( $order ) {
        $account_orders_url = $this->get_account_url( 'qls-orders' );
        if ( ! is_object( $order ) ) {
            return $account_orders_url;
        }

        $amount = (float) ( ( (float) ( $order->final_price ?? 0 ) > 0 ) ? $order->final_price : $order->price_rmb );
        return $this->build_direct_payment_entry_url(
            (string) ( $order->order_no ?? '' ),
            $amount,
            (string) ( $order->payment_method ?? '' ),
            $account_orders_url
        );
    }

    /**
     * 充值待支付订单支付链接
     *
     * @param object $order
     * @return string
     */
    private function build_recharge_pending_order_pay_link( $order ) {
        $account_points_url = $this->get_account_url( 'qls-points' );
        if ( ! is_object( $order ) ) {
            return $account_points_url;
        }

        $amount = (float) ( ( (float) ( $order->final_amount ?? 0 ) > 0 ) ? $order->final_amount : $order->amount );
        return $this->build_direct_payment_entry_url(
            (string) ( $order->order_no ?? '' ),
            $amount,
            (string) ( $order->payment_method ?? '' ),
            $account_points_url
        );
    }

    /**
     * 构建统一支付入口链接
     *
     * @param string $order_no
     * @param float  $amount
     * @param string $payment_method
     * @param string $fallback_url
     * @return string
     */
    private function build_direct_payment_entry_url( $order_no, $amount, $payment_method, $fallback_url ) {
        $order_no = sanitize_text_field( (string) $order_no );
        $fallback_url = esc_url_raw( (string) $fallback_url );
        if ( $order_no === '' || $amount <= 0 || ! class_exists( 'QilingShop_Payment' ) ) {
            return $fallback_url;
        }

        $payment = QilingShop_Payment::instance();
        $original_method = sanitize_key( (string) $payment_method );
        $gateway = $payment->get_gateway_entry_slug( $original_method );
        if ( $gateway === '' || ! $payment->is_gateway_enabled( $gateway ) ) {
            $enabled_gateways = array_keys( $payment->get_enabled_gateways() );
            $gateway = ! empty( $enabled_gateways ) ? $payment->get_gateway_entry_slug( $enabled_gateways[0] ) : '';
        }

        if ( $gateway === '' ) {
            return $fallback_url;
        }

        $subject = (string) get_option( 'qilingshop_fixed_order_title', '' );
        if ( $subject === '' ) {
            $subject = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        }

        $args = array(
            'order'        => $order_no,
            'price'        => number_format( (float) $amount, 2, '.', '' ),
            'subject'      => $subject,
            'redirect_url' => $fallback_url,
        );

        if ( $gateway === 'alipay' ) {
            if ( $original_method === 'alipay_qr' ) {
                $args['method'] = 'f2f';
            } elseif ( $original_method === 'alipay_page' ) {
                $args['method'] = 'page';
            }
        }

        return qilingshop_get_payment_entry_url( $gateway, $args );
    }

    /**
     * 根据用户ID获取邮箱
     *
     * @param int $user_id
     * @return string
     */
    private function get_user_email_by_id( $user_id ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return '';
        }

        $user = get_userdata( $user_id );
        if ( ! $user || empty( $user->user_email ) ) {
            return '';
        }

        return sanitize_email( (string) $user->user_email );
    }

    /**
     * 站内通知场景开关是否开启（依赖启灵主题）
     *
     * @return bool
     */
    private function is_payment_recovery_site_notify_enabled() {
        if ( function_exists( 'developer_starter_site_notify_enabled' )
            && ! developer_starter_site_notify_enabled( 'qilingshop_payment_recovery', true )
        ) {
            return false;
        }

        return function_exists( 'developer_starter_add_user_notification' ) || has_action( 'developer_starter_add_notification' );
    }

    /**
     * 支付召回是否允许通知用户（统一通知开关）
     *
     * @return bool
     */
    private function is_payment_recovery_user_notify_enabled() {
        $raw = get_option( 'qilingshop_notify_payment_recovery_user_enabled', null );
        if ( $raw === null ) {
            return true;
        }
        if ( is_bool( $raw ) ) {
            return $raw;
        }
        if ( is_numeric( $raw ) ) {
            return (int) $raw === 1;
        }

        $raw = strtolower( trim( (string) $raw ) );
        if ( in_array( $raw, array( '1', 'true', 'yes', 'on' ), true ) ) {
            return true;
        }
        if ( in_array( $raw, array( '0', 'false', 'no', 'off', '' ), true ) ) {
            return false;
        }

        return true;
    }

    /**
     * 发送支付召回站内通知（仅在可确认可投递时返回 true）
     *
     * @param int    $user_id
     * @param string $title
     * @param string $content
     * @param string $link
     * @return bool
     */
    private function send_payment_recovery_site_notification( $user_id, $title, $content, $link ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return false;
        }

        $args = array(
            'type'  => 'warning',
            'scene' => 'qilingshop_payment_recovery',
            'link'  => $link,
            'meta'  => array(
                'source' => 'qilingshop',
                'scene'  => 'qilingshop_payment_recovery',
            ),
        );

        if ( function_exists( 'developer_starter_add_user_notification' ) ) {
            $notice_id = (int) developer_starter_add_user_notification( $user_id, $title, $content, $args );
            return $notice_id > 0;
        }

        if ( has_action( 'developer_starter_add_notification' ) ) {
            do_action( 'developer_starter_add_notification', $user_id, $title, $content, $args );
            return true;
        }

        return false;
    }

    /**
     * 是否已发送过召回通知
     *
     * @param string $bucket
     * @param string $order_no
     * @param string $channel
     * @return bool
     */
    private function has_payment_recovery_notice_sent( $bucket, $order_no, $channel ) {
        $key = $this->get_payment_recovery_notice_key( $bucket, $order_no, 'all' );

        // 新逻辑：永久去重（仅通知一次）
        if ( (string) get_option( $key, '' ) === '1' ) {
            return true;
        }

        $legacy_channels = array( 'site', 'email', sanitize_key( (string) $channel ) );

        foreach ( array_unique( $legacy_channels ) as $legacy_channel ) {
            if ( $legacy_channel === '' || $legacy_channel === 'all' ) {
                continue;
            }
            $legacy_key = $this->get_payment_recovery_notice_key( $bucket, $order_no, $legacy_channel );
            if ( (string) get_option( $legacy_key, '' ) === '1' ) {
                add_option( $key, '1', '', 'no' );
                return true;
            }
        }

        // 兼容旧逻辑：若命中旧 transient，立即升级为永久去重标记
        if ( (bool) get_transient( $key ) ) {
            add_option( $key, '1', '', 'no' );
            delete_transient( $key );
            return true;
        }

        foreach ( array_unique( $legacy_channels ) as $legacy_channel ) {
            if ( $legacy_channel === '' || $legacy_channel === 'all' ) {
                continue;
            }
            $legacy_key = $this->get_payment_recovery_notice_key( $bucket, $order_no, $legacy_channel );
            if ( (bool) get_transient( $legacy_key ) ) {
                add_option( $key, '1', '', 'no' );
                delete_transient( $legacy_key );
                return true;
            }
        }

        return false;
    }

    /**
     * 标记召回通知已发送
     *
     * @param string $bucket
     * @param string $order_no
     * @param string $channel
     * @return void
     */
    private function mark_payment_recovery_notice_sent( $bucket, $order_no, $channel ) {
        $key = $this->get_payment_recovery_notice_key( $bucket, $order_no, 'all' );
        if ( (string) get_option( $key, '' ) === '1' ) {
            return;
        }

        add_option( $key, '1', '', 'no' );
        delete_transient( $key );
    }

    /**
     * 一次性回填历史“订单待支付提醒”站内通知到永久去重键
     *
     * @return void
     */
    private function maybe_backfill_payment_recovery_notice_history() {
        $done_option = 'qilingshop_task_payment_recovery_backfill_done';
        if ( (bool) get_option( $done_option, false ) ) {
            return;
        }

        global $wpdb;
        if ( ! $wpdb || ! isset( $wpdb->prefix ) ) {
            return;
        }

        $table = $wpdb->prefix . 'developer_starter_notifications';
        $table_exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $table_exists !== $table ) {
            update_option( $done_option, true, false );
            return;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT title, content, meta
                 FROM {$table}
                 WHERE content LIKE %s
                   AND (title = %s OR meta LIKE %s)
                 ORDER BY id DESC
                 LIMIT %d",
                '%订单号%',
                __( '订单待支付提醒', 'qilingshop' ),
                '%qilingshop_payment_recovery%',
                5000
            )
        );

        if ( empty( $rows ) ) {
            update_option( $done_option, true, false );
            return;
        }

        foreach ( $rows as $row ) {
            $content = (string) ( $row->content ?? '' );
            if ( $content === '' ) {
                continue;
            }

            if ( ! preg_match( '/订单号[：:]\s*([A-Z0-9]{8,64})/u', $content, $matches ) ) {
                continue;
            }

            $order_no = sanitize_text_field( (string) $matches[1] );
            if ( $order_no === '' ) {
                continue;
            }

            $bucket = $this->resolve_payment_recovery_bucket_from_history( $order_no, $content );
            if ( $bucket === '' ) {
                continue;
            }

            $key = $this->get_payment_recovery_notice_key( $bucket, $order_no, 'all' );
            if ( (string) get_option( $key, '' ) !== '1' ) {
                add_option( $key, '1', '', 'no' );
            }
        }

        update_option( $done_option, true, false );
    }

    /**
     * 从历史通知文本推断召回桶
     *
     * @param string $order_no
     * @param string $content
     * @return string
     */
    private function resolve_payment_recovery_bucket_from_history( $order_no, $content ) {
        $order_no = sanitize_text_field( (string) $order_no );
        $content = (string) $content;

        if ( strpos( $order_no, 'CZ' ) === 0 || strpos( $content, '充值订单' ) !== false ) {
            return 'recharge';
        }
        if ( strpos( $order_no, 'SHOP' ) === 0 || strpos( $order_no, 'TUAN' ) === 0 || strpos( $content, '商城订单' ) !== false ) {
            return 'shop';
        }
        return 'resource_vip';
    }

    /**
     * 召回通知去重键
     *
     * @param string $bucket
     * @param string $order_no
     * @param string $channel
     * @return string
     */
    private function get_payment_recovery_notice_key( $bucket, $order_no, $channel ) {
        $raw = sanitize_key( (string) $bucket ) . '|' . sanitize_text_field( (string) $order_no ) . '|' . sanitize_key( (string) $channel );
        return 'qls_prn_' . md5( $raw );
    }

    /**
     * 确保外部触发密钥存在
     */
    public function ensure_task_check_key() {
        $key = (string) get_option( 'qilingshop_task_check_key', '' );
        if ( $key === '' ) {
            $key = wp_generate_password( 24, false, false );
            update_option( 'qilingshop_task_check_key', $key );
        }
    }

    /**
     * 禁用 WP Cron 的任务中心调度
     */
    public function unschedule_wp_cron() {
        $timestamp = wp_next_scheduled( 'qilingshop_daily_task_check' );
        while ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'qilingshop_daily_task_check' );
            $timestamp = wp_next_scheduled( 'qilingshop_daily_task_check' );
        }

        // 统一禁用 WP Cron，全部改外部触发
        $legacy_hooks = array(
            'qilingshop_daily_vip_check',
            'qilingshop_daily_guest_cleanup',
            'qilingshop_daily_points_maintenance',
            'qls_shop_auto_cancel_orders',
            'qls_shop_check_expired_groups',
        );

        foreach ( $legacy_hooks as $hook ) {
            $hook_timestamp = wp_next_scheduled( $hook );
            while ( $hook_timestamp ) {
                wp_unschedule_event( $hook_timestamp, $hook );
                $hook_timestamp = wp_next_scheduled( $hook );
            }
        }
    }

    /**
     * 外部监控触发入口
     * URL: ?qilingshop_task_check=1&key=xxx
     */
    public function handle_external_trigger() {
        if ( empty( $_GET['qilingshop_task_check'] ) ) {
            return;
        }

	    $key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
	    $saved_key = (string) get_option( 'qilingshop_task_check_key', '' );
	    if ( $saved_key === '' || ! hash_equals( $saved_key, $key ) ) {
	        wp_die( 'invalid key', 'Task Check', array( 'response' => 403 ) );
	    }

        $force = isset( $_GET['force'] ) && (string) $_GET['force'] === '1';
        $result = $this->run_daily_tasks( $force );

        if ( isset( $_GET['plain'] ) && (string) $_GET['plain'] === '1' ) {
            wp_die( 'ok', 'Task Check', array( 'response' => 200 ) );
        }

        wp_send_json_success( $result );
    }

    /**
     * 发送生日券
     */
    private function maybe_send_birthday_coupons() {
        $enabled = (bool) get_option( 'qilingshop_birthday_coupon_enabled', false );
        $coupon_id = (int) get_option( 'qilingshop_birthday_coupon_id', 0 );

        if ( ! $enabled || $coupon_id <= 0 ) {
            return;
        }

        global $wpdb;
        $today_md = date( 'm-d', current_time( 'timestamp' ) );
        $user_ids_md = $wpdb->get_col( $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s",
            'qilingshop_birthday_md',
            $today_md
        ) );
        $user_ids_full = $wpdb->get_col( $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s) AND RIGHT(meta_value, 5) = %s",
            'qilingshop_birthday',
            'birthday',
            $today_md
        ) );
        $user_ids = array_values( array_unique( array_filter( array_map( 'intval', array_merge( (array) $user_ids_md, (array) $user_ids_full ) ) ) ) );

        if ( empty( $user_ids ) ) {
            return;
        }

        if ( ! class_exists( 'QLS_Coupon' ) ) {
            require_once QILINGSHOP_PATH . 'includes/shop/class-qls-coupon.php';
        }

        $coupon_manager = QLS_Coupon::instance();
        $current_year = (string) current_time( 'Y' );

        foreach ( $user_ids as $user_id ) {
            $user_id = (int) $user_id;
            if ( $user_id <= 0 ) {
                continue;
            }

            $gift_upgrade = class_exists( 'QilingShop_Growth_Benefits' )
                ? QilingShop_Growth_Benefits::instance()->get_birthday_gift_upgrade( $user_id )
                : array( 'coupon_id' => 0, 'extra_growth' => 0, 'label' => '' );
            $send_coupon_id = ! empty( $gift_upgrade['coupon_id'] ) ? (int) $gift_upgrade['coupon_id'] : $coupon_id;

            $sent_year = (string) get_user_meta( $user_id, 'qilingshop_birthday_coupon_sent_' . $send_coupon_id, true );
            if ( $sent_year === $current_year ) {
                continue;
            }

            $result = $coupon_manager->claim( $send_coupon_id, $user_id );
            if ( ! empty( $result['success'] ) ) {
                update_user_meta( $user_id, 'qilingshop_birthday_coupon_sent_' . $send_coupon_id, $current_year );
                if ( $send_coupon_id !== $coupon_id ) {
                    update_user_meta( $user_id, 'qilingshop_birthday_coupon_sent_' . $coupon_id, $current_year );
                }

                if ( ! empty( $gift_upgrade['extra_growth'] ) && class_exists( 'QilingShop_Growth' ) && QilingShop_Growth::instance()->is_enabled() ) {
                    QilingShop_Growth::instance()->add_growth(
                        $user_id,
                        (float) $gift_upgrade['extra_growth'],
                        'birthday_gift_upgrade',
                        __( '生日礼包升级成长值', 'qilingshop' ),
                        (int) current_time( 'Ymd' )
                    );
                }

                do_action( 'qilingshop_send_notification', array(
                    'user_id' => $user_id,
                    'title'   => __( '生日券已发放', 'qilingshop' ),
                    'content' => ! empty( $gift_upgrade['label'] ) ? (string) $gift_upgrade['label'] : __( '生日快乐，生日券已到账！', 'qilingshop' ),
                    'type'    => 'success',
                    'scene'   => 'qilingshop_birthday_coupon',
                    'link'    => $this->get_coupon_center_url(),
                ) );
            }
        }
    }

    /**
     * 创建任务中心页面
     */
    public function ensure_task_center_page() {
        $definition = QLS_Shop_Page_Manager::get_task_center_page_definition();
        if ( QLS_Shop_Page_Manager::has_valid_page( $definition ) ) {
            return;
        }

        $result = QLS_Shop_Page_Manager::ensure_page( $definition );
        $page_id = isset( $result['id'] ) ? (int) $result['id'] : 0;
        if ( $page_id > 0 ) {
            $this->maybe_set_fullscreen_template( $page_id );
        }
    }

    /**
     * 设置全屏模板
     *
     * @param int $page_id
     */
    private function maybe_set_fullscreen_template( $page_id ) {
        $template = locate_template( array( 'templates/template-fullscreen.php' ) );
        if ( ! empty( $template ) ) {
            update_post_meta( $page_id, '_wp_page_template', 'templates/template-fullscreen.php' );
        }
    }

    /**
     * 获取任务中心页面URL
     *
     * @return string
     */
    private function get_task_center_url() {
        $page_id = (int) get_option( 'qilingshop_task_center_page_id', 0 );
        return $page_id ? get_permalink( $page_id ) : home_url( '/' );
    }

    /**
     * 获取会员中心URL
     *
     * @param string $tab
     * @return string
     */
    private function get_account_url( $tab = '' ) {
        if ( function_exists( 'developer_starter_get_frontend_account_url' ) ) {
            return (string) developer_starter_get_frontend_account_url( $tab );
        }

        $account_page_id = (int) get_option( 'developer_starter_account_page_id', 0 );
        if ( ! $account_page_id || ! get_post( $account_page_id ) ) {
            $account_page = get_pages( array(
                'meta_key'   => '_wp_page_template',
                'meta_value' => 'templates/template-account.php',
                'number'     => 1,
            ) );
            if ( ! empty( $account_page ) ) {
                $account_page_id = $account_page[0]->ID;
                update_option( 'developer_starter_account_page_id', $account_page_id );
            }
        }

        $url = $account_page_id ? get_permalink( $account_page_id ) : admin_url( 'profile.php' );
        if ( $tab ) {
            $url = add_query_arg( 'tab', sanitize_key( $tab ), $url );
        }
        return $url;
    }

    /**
     * 获取优惠券中心URL
     *
     * @return string
     */
    private function get_coupon_center_url() {
        $page_id = (int) get_option( 'qls_shop_page_coupon_center', 0 );
        return $page_id ? get_permalink( $page_id ) : home_url( '/' );
    }

    /**
     * 推荐外部访问频率（分钟）
     *
     * @return int
     */
    public function get_recommended_check_interval_minutes() {
        return 5;
    }

    /**
     * 外部任务说明
     *
     * @return array
     */
    public function get_external_task_plan() {
        return array(
            array(
                'task'     => __( '团购过期检查与失败退款', 'qilingshop' ),
                'interval' => __( '每 5 分钟', 'qilingshop' ),
                'detail'   => __( '检查过期团购并触发退款到可提现余额（团购退款任务）。', 'qilingshop' ),
            ),
            array(
                'task'     => __( '商城未支付订单自动取消', 'qilingshop' ),
                'interval' => __( '每 10 分钟', 'qilingshop' ),
                'detail'   => __( '超时未支付订单自动取消并恢复库存（订单巡检任务）。', 'qilingshop' ),
            ),
            array(
                'task'     => __( '支付补单对账（充值/资源/VIP/商城）', 'qilingshop' ),
                'interval' => __( '每 10 分钟', 'qilingshop' ),
                'detail'   => __( '扫描已支付但未完成到账的订单并自动补偿，避免扫码成功后积分/VIP/资源未到账。', 'qilingshop' ),
            ),
            array(
                'task'     => __( '未支付订单召回通知（可选）', 'qilingshop' ),
                'interval' => __( '每 10 分钟', 'qilingshop' ),
                'detail'   => __( '扫描待支付订单并触发召回通知（站内/邮件，仅登录用户订单）。仅在后台开启“订单召回通知”后执行。', 'qilingshop' ),
            ),
            array(
                'task'     => __( '好友助力过期检查与订单对账', 'qilingshop' ),
                'interval' => __( '每 10 分钟', 'qilingshop' ),
                'detail'   => __( '活动到期自动下架、助力单过期处理、待支付对账与库存锁回收。', 'qilingshop' ),
            ),
            array(
                'task'     => __( 'VIP 到期检查 / 游客清理 / 积分过期维护', 'qilingshop' ),
                'interval' => __( '每 1 小时', 'qilingshop' ),
                'detail'   => __( '统一执行 VIP 到期、游客清理、积分提醒与积分过期处理。', 'qilingshop' ),
            ),
            array(
                'task'     => __( '生日券发放任务', 'qilingshop' ),
                'interval' => __( '每 6 小时', 'qilingshop' ),
                'detail'   => __( '生日当天自动发券，支持重复调用去重（低频营销任务）。', 'qilingshop' ),
            ),
        );
    }

    /**
     * 获取外部触发URL
     *
     * @return string
     */
    public function get_task_check_url() {
        $key = (string) get_option( 'qilingshop_task_check_key', '' );
        if ( $key === '' ) {
            return '';
        }
        return add_query_arg(
            array(
                'qilingshop_task_check' => 1,
                'key' => $key,
            ),
            home_url( '/' )
        );
    }
}
