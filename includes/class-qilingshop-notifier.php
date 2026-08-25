<?php
/**
 * 统一通知中心（邮件 / 飞书 / 钉钉 / 短信）
 *
 * @package QilingShop
 * @since   2.0.7
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QilingShop_Notifier {

    /**
     * 单例实例
     *
     * @var QilingShop_Notifier|null
     */
    private static $instance = null;

    /**
     * 主题短信管理器缓存。
     *
     * @var object|false|null
     */
    private $theme_sms_manager = null;

    /**
     * 获取单例实例
     *
     * @return QilingShop_Notifier
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * 构造函数
     */
    private function __construct() {
        $this->register_hooks();
    }

    /**
     * 注册业务通知钩子
     */
    private function register_hooks() {
        add_action( 'qilingshop_recharge_completed', array( $this, 'notify_recharge_completed' ), 10, 3 );
        add_action( 'qilingshop_order_completed', array( $this, 'notify_resource_order_completed' ), 10, 1 );
        add_action( 'qilingshop_vip_upgraded', array( $this, 'notify_vip_upgraded' ), 10, 4 );
        add_action( 'qilingshop_vip_expiring', array( $this, 'notify_vip_expiring' ), 10, 3 );
        add_action( 'qilingshop_vip_expired', array( $this, 'notify_vip_expired' ), 10, 3 );
        add_action( 'qilingshop_growth_level_changed', array( $this, 'notify_growth_level_changed' ), 10, 3 );
        add_action( 'qilingshop_after_checkin', array( $this, 'notify_checkin' ), 10, 3 );
        add_action( 'qilingshop_invite_registered', array( $this, 'notify_invite_registered' ), 10, 2 );
        add_action( 'qilingshop_affiliate_commission_paid', array( $this, 'notify_affiliate_commission_paid' ), 10, 3 );
        add_action( 'qilingshop_author_commission_processed', array( $this, 'notify_author_commission_processed' ), 10, 3 );
        add_action( 'qilingshop_withdraw_submitted', array( $this, 'notify_withdraw_submitted' ), 10, 5 );
        add_action( 'qilingshop_withdraw_approved', array( $this, 'notify_withdraw_approved' ), 10, 6 );
        add_action( 'qilingshop_withdraw_rejected', array( $this, 'notify_withdraw_rejected' ), 10, 4 );
        add_action( 'qilingshop_send_notification', array( $this, 'notify_custom_message' ), 10, 1 );

        add_action( 'qls_shop_order_paid', array( $this, 'notify_shop_order_paid' ), 10, 2 );
        add_action( 'qls_shop_order_shipped', array( $this, 'notify_shop_order_shipped' ), 10, 3 );
        add_action( 'qls_shop_order_status_changed', array( $this, 'notify_shop_order_status_changed' ), 10, 3 );
        add_action( 'qls_shop_order_cancelled', array( $this, 'notify_shop_order_cancelled' ), 10, 2 );
        add_action( 'qls_shop_order_refund_applied', array( $this, 'notify_shop_order_refund_applied' ), 10, 2 );
        add_action( 'qls_shop_order_refunded', array( $this, 'notify_shop_order_refunded' ), 10, 4 );
        add_action( 'qls_shop_ticket_created', array( $this, 'notify_shop_ticket_created' ), 10, 2 );
        add_action( 'qls_shop_ticket_user_replied', array( $this, 'notify_shop_ticket_user_replied' ), 10, 2 );
        add_action( 'qls_shop_ticket_admin_replied', array( $this, 'notify_shop_ticket_admin_replied' ), 10, 4 );
    }

    /**
     * 充值完成通知
     *
     * @param int   $user_id 用户ID
     * @param float $amount  充值金额
     * @param int   $points  到账积分
     */
    public function notify_recharge_completed( $user_id, $amount, $points ) {
        $site_name = $this->get_site_name();
        $subject   = sprintf( __( '[%s] 积分充值通知', 'qilingshop' ), $site_name );

        $lines = array(
            __( '用户', 'qilingshop' )     => $this->get_user_display( $user_id ),
            __( '用户ID', 'qilingshop' )   => (int) $user_id,
            __( '充值金额', 'qilingshop' ) => sprintf( '%.2f', (float) $amount ),
            __( '到账积分', 'qilingshop' ) => (int) $points,
            __( '时间', 'qilingshop' )     => current_time( 'Y-m-d H:i:s' ),
        );

        $this->send_scene_notification(
            'recharge',
            __( '积分充值通知', 'qilingshop' ),
            $subject,
            $lines,
            'admin_email_recharge'
        );

        // 站内通知（用户）
        $title = __( '积分充值成功', 'qilingshop' );
        $content = sprintf(
            __( '充值金额：%s 元，到账积分：%s。', 'qilingshop' ),
            sprintf( '%.2f', (float) $amount ),
            (int) $points
        );
        $this->send_site_notification( (int) $user_id, $title, $content, 'success', $this->get_account_tab_url( 'qls-points' ), 'qilingshop_recharge' );
    }

    /**
     * 资源订单完成通知
     *
     * @param int $order_id 订单ID
     */
    public function notify_resource_order_completed( $order_id ) {
        if ( ! class_exists( 'QilingShop_Order' ) ) {
            return;
        }

        $order = QilingShop_Order::instance()->get( (int) $order_id );
        if ( ! $order ) {
            return;
        }
        if ( ! empty( $order->order_type ) && $order->order_type !== 'resource' ) {
            return;
        }

        $site_name = $this->get_site_name();
        $subject   = sprintf( __( '[%s] 资源购买通知', 'qilingshop' ), $site_name );
        $amount    = ! empty( $order->price_rmb ) ? (float) $order->price_rmb : 0.0;

        if ( $amount <= 0 && function_exists( 'qilingshop_points_to_rmb' ) ) {
            $amount = (float) qilingshop_points_to_rmb( (float) $order->price_points );
        }

        $resource_title = ! empty( $order->post_title ) ? $order->post_title : get_the_title( (int) $order->post_id );

        $lines = array(
            __( '用户', 'qilingshop' )     => $this->get_user_display( (int) $order->user_id, $order->guest_id ?? '' ),
            __( '资源', 'qilingshop' )     => $resource_title,
            __( '订单号', 'qilingshop' )   => $order->order_no,
            __( '金额(元)', 'qilingshop' ) => sprintf( '%.2f', $amount ),
            __( '支付方式', 'qilingshop' ) => ! empty( $order->payment_method ) ? $order->payment_method : __( '积分', 'qilingshop' ),
            __( '时间', 'qilingshop' )     => current_time( 'Y-m-d H:i:s' ),
        );

        $this->send_scene_notification(
            'order',
            __( '资源购买通知', 'qilingshop' ),
            $subject,
            $lines,
            'admin_email_order'
        );

        if ( ! empty( $order->user_id ) ) {
            $title = __( '资源购买成功', 'qilingshop' );
            $content = sprintf(
                __( '资源：%s；订单号：%s；金额：%s 元。', 'qilingshop' ),
                $resource_title,
                (string) $order->order_no,
                sprintf( '%.2f', $amount )
            );
            $this->send_site_notification( (int) $order->user_id, $title, $content, 'success', $this->get_account_tab_url( 'qls-orders' ), 'qilingshop_resource_order' );
        }

        $resource_url = '';
        if ( ! empty( $order->post_id ) ) {
            $permalink = get_permalink( (int) $order->post_id );
            if ( is_string( $permalink ) && $permalink !== '' ) {
                $resource_url = $permalink;
            }
        }

        $user_email_lines = $lines;
        $user_email_lines[ __( '用户', 'qilingshop' ) ] = ! empty( $order->user_id )
            ? $this->get_user_display( (int) $order->user_id )
            : __( '游客', 'qilingshop' );

        $this->send_user_order_email(
            'order',
            $this->resolve_resource_order_email( $order ),
            sprintf( __( '[%s] 资源购买成功', 'qilingshop' ), $site_name ),
            __( '您的资源订单已支付完成，请查看以下订单信息。', 'qilingshop' ),
            $user_email_lines,
            $resource_url,
            __( '查看资源', 'qilingshop' )
        );
    }

    /**
     * VIP 升级通知
     *
     * @param int    $user_id     用户ID
     * @param int    $level_id    新等级ID
     * @param int    $old_level   旧等级ID
     * @param string $new_expires 新过期时间
     */
    public function notify_vip_upgraded( $user_id, $level_id, $old_level, $new_expires ) {
        $site_name      = $this->get_site_name();
        $subject        = sprintf( __( '[%s] VIP 升级通知', 'qilingshop' ), $site_name );
        $new_level_name = $this->get_vip_level_name( (int) $level_id );
        $old_level_name = $this->get_vip_level_name( (int) $old_level );

        $lines = array(
            __( '用户', 'qilingshop' )       => $this->get_user_display( $user_id ),
            __( '用户ID', 'qilingshop' )     => (int) $user_id,
            __( '原等级', 'qilingshop' )     => $old_level_name,
            __( '新等级', 'qilingshop' )     => $new_level_name,
            __( '有效期至', 'qilingshop' )   => (string) $new_expires,
            __( '升级时间', 'qilingshop' )   => current_time( 'Y-m-d H:i:s' ),
        );

        $this->send_scene_notification(
            'vip',
            __( 'VIP 升级通知', 'qilingshop' ),
            $subject,
            $lines
        );

        $title = __( 'VIP 开通/升级成功', 'qilingshop' );
        $content = sprintf(
            __( '新等级：%s；有效期至：%s。', 'qilingshop' ),
            $new_level_name,
            (string) $new_expires
        );
        $this->send_site_notification( (int) $user_id, $title, $content, 'success', $this->get_account_tab_url( 'qls-vip' ), 'qilingshop_vip_upgraded' );
    }

    /**
     * VIP 到期提醒（发送给用户）
     *
     * @param int    $user_id   用户ID
     * @param string $expires   到期日期
     * @param int    $days_left 剩余天数
     */
    public function notify_vip_expiring( $user_id, $expires, $days_left ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return;
        }

        $scene_key = 'vip_expiring';
        $site_name = $this->get_site_name();
        $subject   = sprintf( __( '[%s] VIP 到期提醒', 'qilingshop' ), $site_name );

        $lines = array(
            __( '用户', 'qilingshop' )       => $this->get_user_display( $user_id ),
            __( '有效期至', 'qilingshop' )   => (string) $expires,
            __( '剩余天数', 'qilingshop' )   => (int) $days_left,
            __( '时间', 'qilingshop' )       => current_time( 'Y-m-d H:i:s' ),
        );

        $this->send_scene_notification(
            $scene_key,
            __( 'VIP 到期提醒通知', 'qilingshop' ),
            $subject,
            $lines
        );

        if ( ! $this->is_scene_role_enabled( $scene_key, 'user' ) ) {
            return;
        }

        // 站内通知（用户）
        $title = __( 'VIP 即将到期', 'qilingshop' );
        $content = sprintf(
            __( '您的 VIP 将于 %s 到期，剩余 %d 天。', 'qilingshop' ),
            (string) $expires,
            (int) $days_left
        );
        $this->send_site_notification( (int) $user_id, $title, $content, 'warning', $this->get_account_tab_url( 'qls-vip' ), 'qilingshop_vip_expiring' );

        // 邮件通知（若有邮箱）
        $user = get_user_by( 'ID', $user_id );
        if ( $user && ! empty( $user->user_email ) ) {
            $this->send_user_email( $user->user_email, $subject, $lines );
        }
    }

    /**
     * VIP 已过期通知
     *
     * @param int    $user_id     用户ID
     * @param int    $old_level   旧等级ID
     * @param string $old_expires 旧过期时间
     */
    public function notify_vip_expired( $user_id, $old_level, $old_expires = '' ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return;
        }

        $scene_key = 'vip_expired';
        $old_level_name = $this->get_vip_level_name( (int) $old_level );
        $lines = array(
            __( '用户', 'qilingshop' )       => $this->get_user_display( $user_id ),
            __( '过期前等级', 'qilingshop' ) => $old_level_name,
            __( '过期时间', 'qilingshop' )   => $old_expires !== '' ? (string) $old_expires : '-',
            __( '时间', 'qilingshop' )       => current_time( 'Y-m-d H:i:s' ),
        );
        $this->send_scene_notification(
            $scene_key,
            __( 'VIP 已过期通知', 'qilingshop' ),
            sprintf( __( '[%s] VIP 已过期通知', 'qilingshop' ), $this->get_site_name() ),
            $lines
        );

        if ( ! $this->is_scene_role_enabled( $scene_key, 'user' ) ) {
            return;
        }

        $title = __( 'VIP 已过期', 'qilingshop' );
        if ( ! empty( $old_expires ) ) {
            $content = sprintf(
                __( '您的 VIP 已于 %s 过期。', 'qilingshop' ),
                (string) $old_expires
            );
        } else {
            $content = __( '您的 VIP 已过期。', 'qilingshop' );
        }

        $this->send_site_notification( $user_id, $title, $content, 'warning', $this->get_account_tab_url( 'qls-vip' ), 'qilingshop_vip_expired' );
    }

    public function notify_growth_level_changed( $user_id, $old_level_id, $new_level_id ) {
        $user_id = (int) $user_id;
        $new_level_id = (int) $new_level_id;
        if ( $user_id <= 0 || $new_level_id <= 0 || ! class_exists( 'QilingShop_Growth' ) ) {
            return;
        }

        $growth = QilingShop_Growth::instance();
        $new_level = $growth->get_level( $new_level_id );
        if ( ! $new_level ) {
            return;
        }

        $title = __( '成长等级已更新', 'qilingshop' );
        $content = sprintf( __( '您的成长等级已更新为：%s。', 'qilingshop' ), (string) $new_level->level_name );
        $this->send_site_notification( $user_id, $title, $content, 'success', $this->get_account_tab_url( 'qls-points' ), 'qilingshop_growth_level_changed' );
    }

    /**
     * 签到成功通知
     *
     * @param int   $user_id          用户ID
     * @param float $points_earned    获得积分
     * @param int   $consecutive_days 连续签到天数
     */
    public function notify_checkin( $user_id, $points_earned, $consecutive_days ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return;
        }

        $scene_key = 'checkin';
        $points_text = function_exists( 'qilingshop_format_points' )
            ? qilingshop_format_points( (float) $points_earned )
            : (string) $points_earned;

        $lines = array(
            __( '用户', 'qilingshop' )     => $this->get_user_display( $user_id ),
            __( '获得积分', 'qilingshop' ) => $points_text,
            __( '连续天数', 'qilingshop' ) => (int) $consecutive_days,
            __( '时间', 'qilingshop' )     => current_time( 'Y-m-d H:i:s' ),
        );
        $this->send_scene_notification(
            $scene_key,
            __( '用户签到通知', 'qilingshop' ),
            sprintf( __( '[%s] 用户签到通知', 'qilingshop' ), $this->get_site_name() ),
            $lines
        );

        $title = __( '签到成功', 'qilingshop' );
        $content = sprintf(
            __( '获得 %s，连续签到 %d 天。', 'qilingshop' ),
            $points_text,
            (int) $consecutive_days
        );

        $this->send_site_notification( $user_id, $title, $content, 'success', $this->get_account_tab_url( 'qls-points' ), 'qilingshop_checkin' );
    }

    /**
     * 邀请注册通知
     *
     * @param int $inviter_id 邀请人ID
     * @param int $invitee_id 被邀请人ID
     */
    public function notify_invite_registered( $inviter_id, $invitee_id ) {
        $inviter_id = (int) $inviter_id;
        if ( $inviter_id <= 0 ) {
            return;
        }

        $scene_key = 'invite_registered';
        $invitee_label = '#' . (int) $invitee_id;
        $invitee = get_user_by( 'ID', (int) $invitee_id );
        if ( $invitee ) {
            $invitee_label = $invitee->display_name;
        }

        $lines = array(
            __( '邀请人', 'qilingshop' )   => $this->get_user_display( $inviter_id ),
            __( '被邀请用户', 'qilingshop' ) => $invitee_label,
            __( '时间', 'qilingshop' )     => current_time( 'Y-m-d H:i:s' ),
        );
        $this->send_scene_notification(
            $scene_key,
            __( '邀请注册通知', 'qilingshop' ),
            sprintf( __( '[%s] 邀请注册通知', 'qilingshop' ), $this->get_site_name() ),
            $lines
        );

        $title = __( '邀请成功', 'qilingshop' );
        $content = sprintf(
            __( '您邀请的用户 %s 已成功注册。', 'qilingshop' ),
            $invitee_label
        );

        $this->send_site_notification( $inviter_id, $title, $content, 'success', $this->get_account_tab_url( 'qls-invite' ), 'qilingshop_invite_registered' );
    }

    /**
     * 推广佣金到账通知
     *
     * @param int    $user_id 用户ID
     * @param float  $amount  佣金金额
     * @param string $source  来源
     */
    public function notify_affiliate_commission_paid( $user_id, $amount, $source ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return;
        }

        $scene_key = 'affiliate_commission';
        $source = (string) $source;
        $source_map = array(
            'resource' => __( '资源购买', 'qilingshop' ),
            'order'    => __( '商城订单', 'qilingshop' ),
        );
        $source_label = isset( $source_map[ $source ] ) ? $source_map[ $source ] : $source;

        $lines = array(
            __( '用户', 'qilingshop' )   => $this->get_user_display( $user_id ),
            __( '来源', 'qilingshop' )   => $source_label,
            __( '佣金金额', 'qilingshop' ) => sprintf( '%.2f', (float) $amount ),
            __( '时间', 'qilingshop' )   => current_time( 'Y-m-d H:i:s' ),
        );
        $this->send_scene_notification(
            $scene_key,
            __( '推广佣金到账通知', 'qilingshop' ),
            sprintf( __( '[%s] 推广佣金到账通知', 'qilingshop' ), $this->get_site_name() ),
            $lines
        );

        $title = __( '推广佣金到账', 'qilingshop' );
        $content = sprintf(
            __( '来源：%s；佣金：¥%s。', 'qilingshop' ),
            $source_label,
            sprintf( '%.2f', (float) $amount )
        );

        $this->send_site_notification( $user_id, $title, $content, 'success', $this->get_account_tab_url( 'qls-invite' ), 'qilingshop_affiliate_commission' );
    }

    /**
     * 作者提成到账通知
     *
     * @param int    $author_id  作者ID
     * @param float  $commission 提成金额
     * @param object $order      订单对象
     */
    public function notify_author_commission_processed( $author_id, $commission, $order ) {
        $author_id = (int) $author_id;
        if ( $author_id <= 0 ) {
            return;
        }

        $scene_key = 'author_commission';
        $post_title = '';
        if ( is_object( $order ) ) {
            if ( ! empty( $order->post_title ) ) {
                $post_title = (string) $order->post_title;
            } elseif ( ! empty( $order->post_id ) ) {
                $post_title = get_the_title( (int) $order->post_id );
            }
        }

        if ( $post_title === '' ) {
            $post_title = __( '资源', 'qilingshop' );
        }

        $order_no = '';
        if ( is_object( $order ) && ! empty( $order->order_no ) ) {
            $order_no = (string) $order->order_no;
        }

        $lines = array(
            __( '作者', 'qilingshop' ) => $this->get_user_display( $author_id ),
            __( '资源', 'qilingshop' ) => $post_title,
            __( '提成金额', 'qilingshop' ) => sprintf( '%.2f', (float) $commission ),
            __( '订单号', 'qilingshop' ) => $order_no !== '' ? $order_no : '-',
            __( '时间', 'qilingshop' ) => current_time( 'Y-m-d H:i:s' ),
        );
        $this->send_scene_notification(
            $scene_key,
            __( '作者提成到账通知', 'qilingshop' ),
            sprintf( __( '[%s] 作者提成到账通知', 'qilingshop' ), $this->get_site_name() ),
            $lines
        );

        $title = __( '销售提成到账', 'qilingshop' );
        $content = sprintf(
            __( '资源：%s；提成：¥%s。', 'qilingshop' ),
            $post_title,
            sprintf( '%.2f', (float) $commission )
        );

        $this->send_site_notification( $author_id, $title, $content, 'success', $this->get_account_tab_url( 'qls-commission' ), 'qilingshop_author_commission' );
    }

    /**
     * 提现申请通知
     *
     * @param int   $withdraw_id  提现ID
     * @param int   $user_id      用户ID
     * @param float $amount       提现金额
     * @param float $fee          手续费
     * @param float $actual_amount 实际到账金额
     */
    public function notify_withdraw_submitted( $withdraw_id, $user_id, $amount, $fee, $actual_amount ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return;
        }

        $scene_key = 'withdraw_submitted';
        $lines = array(
            __( '用户', 'qilingshop' ) => $this->get_user_display( $user_id ),
            __( '提现单号', 'qilingshop' ) => '#' . (int) $withdraw_id,
            __( '申请金额', 'qilingshop' ) => sprintf( '%.2f', (float) $amount ),
            __( '手续费', 'qilingshop' ) => sprintf( '%.2f', (float) $fee ),
            __( '预计到账', 'qilingshop' ) => sprintf( '%.2f', (float) $actual_amount ),
            __( '时间', 'qilingshop' ) => current_time( 'Y-m-d H:i:s' ),
        );
        $this->send_scene_notification(
            $scene_key,
            __( '提现申请通知', 'qilingshop' ),
            sprintf( __( '[%s] 提现申请通知', 'qilingshop' ), $this->get_site_name() ),
            $lines
        );

        $title = __( '提现申请已提交', 'qilingshop' );
        $content = sprintf(
            __( '申请金额：¥%s；手续费：¥%s；预计到账：¥%s。', 'qilingshop' ),
            sprintf( '%.2f', (float) $amount ),
            sprintf( '%.2f', (float) $fee ),
            sprintf( '%.2f', (float) $actual_amount )
        );

        $this->send_site_notification( $user_id, $title, $content, 'info', $this->get_account_tab_url( 'qls-withdraw' ), 'qilingshop_withdraw_submitted' );
    }

    /**
     * 提现通过通知
     *
     * @param int    $user_id       用户ID
     * @param int    $withdraw_id   提现ID
     * @param float  $amount        提现金额
     * @param float  $fee           手续费
     * @param float  $actual_amount 实际到账金额
     * @param string $admin_note    管理员备注
     */
    public function notify_withdraw_approved( $user_id, $withdraw_id, $amount, $fee, $actual_amount, $admin_note = '' ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return;
        }

        $scene_key = 'withdraw_approved';
        $lines = array(
            __( '用户', 'qilingshop' ) => $this->get_user_display( $user_id ),
            __( '提现单号', 'qilingshop' ) => '#' . (int) $withdraw_id,
            __( '提现金额', 'qilingshop' ) => sprintf( '%.2f', (float) $amount ),
            __( '手续费', 'qilingshop' ) => sprintf( '%.2f', (float) $fee ),
            __( '到账金额', 'qilingshop' ) => sprintf( '%.2f', (float) $actual_amount ),
            __( '管理员备注', 'qilingshop' ) => $admin_note !== '' ? (string) $admin_note : '-',
            __( '时间', 'qilingshop' ) => current_time( 'Y-m-d H:i:s' ),
        );
        $this->send_scene_notification(
            $scene_key,
            __( '提现通过通知', 'qilingshop' ),
            sprintf( __( '[%s] 提现通过通知', 'qilingshop' ), $this->get_site_name() ),
            $lines
        );

        $title = __( '提现已打款', 'qilingshop' );
        $content = sprintf(
            __( '提现金额：¥%s；到账金额：¥%s。', 'qilingshop' ),
            sprintf( '%.2f', (float) $amount ),
            sprintf( '%.2f', (float) $actual_amount )
        );

        if ( $admin_note !== '' ) {
            $content .= ' ' . sprintf( __( '备注：%s', 'qilingshop' ), (string) $admin_note );
        }

        $this->send_site_notification( $user_id, $title, $content, 'success', $this->get_account_tab_url( 'qls-withdraw' ), 'qilingshop_withdraw_approved' );
    }

    /**
     * 提现拒绝通知
     *
     * @param int    $user_id     用户ID
     * @param int    $withdraw_id 提现ID
     * @param float  $amount      提现金额
     * @param string $admin_note  管理员备注
     */
    public function notify_withdraw_rejected( $user_id, $withdraw_id, $amount, $admin_note = '' ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return;
        }

        $scene_key = 'withdraw_rejected';
        $lines = array(
            __( '用户', 'qilingshop' ) => $this->get_user_display( $user_id ),
            __( '提现单号', 'qilingshop' ) => '#' . (int) $withdraw_id,
            __( '提现金额', 'qilingshop' ) => sprintf( '%.2f', (float) $amount ),
            __( '拒绝原因', 'qilingshop' ) => $admin_note !== '' ? (string) $admin_note : '-',
            __( '时间', 'qilingshop' ) => current_time( 'Y-m-d H:i:s' ),
        );
        $this->send_scene_notification(
            $scene_key,
            __( '提现拒绝通知', 'qilingshop' ),
            sprintf( __( '[%s] 提现拒绝通知', 'qilingshop' ), $this->get_site_name() ),
            $lines
        );

        $title = __( '提现未通过', 'qilingshop' );
        $content = sprintf(
            __( '提现金额：¥%s 已退回至您的可提现余额。', 'qilingshop' ),
            sprintf( '%.2f', (float) $amount )
        );

        if ( $admin_note !== '' ) {
            $content .= ' ' . sprintf( __( '原因：%s', 'qilingshop' ), (string) $admin_note );
        }

        $this->send_site_notification( $user_id, $title, $content, 'warning', $this->get_account_tab_url( 'qls-withdraw' ), 'qilingshop_withdraw_rejected' );
    }

    /**
     * 通用通知（用于外部钩子）
     *
     * @param array $payload 通知数据
     */
    public function notify_custom_message( $payload ) {
        if ( empty( $payload ) || ! is_array( $payload ) ) {
            return;
        }

        $user_id = isset( $payload['user_id'] ) ? (int) $payload['user_id'] : 0;
        if ( $user_id <= 0 ) {
            return;
        }

        $title = isset( $payload['title'] ) ? (string) $payload['title'] : '';
        if ( $title === '' ) {
            return;
        }

        $content = isset( $payload['content'] ) ? (string) $payload['content'] : '';
        $raw_type = isset( $payload['type'] ) ? (string) $payload['type'] : 'info';
        $link = isset( $payload['link'] ) ? (string) $payload['link'] : '';

        $type = 'info';
        if ( in_array( $raw_type, array( 'info', 'success', 'warning', 'error' ), true ) ) {
            $type = $raw_type;
        } else {
            $normalized = strtolower( $raw_type );
            if ( strpos( $normalized, 'fail' ) !== false || strpos( $normalized, 'reject' ) !== false || strpos( $normalized, 'refund' ) !== false ) {
                $type = 'warning';
            } elseif ( strpos( $normalized, 'success' ) !== false || strpos( $normalized, 'paid' ) !== false ) {
                $type = 'success';
            }
        }
        $scene = '';
        if ( isset( $payload['scene'] ) ) {
            $scene = sanitize_key( (string) $payload['scene'] );
        } elseif ( ! in_array( $raw_type, array( 'info', 'success', 'warning', 'error' ), true ) ) {
            $scene = sanitize_key( (string) $raw_type );
        }

        $scene_key = $this->normalize_scene_key( $scene );
        if ( $scene_key !== '' ) {
            $lines = array(
                __( '用户', 'qilingshop' ) => $this->get_user_display( $user_id ),
                __( '通知标题', 'qilingshop' ) => $title,
                __( '通知内容', 'qilingshop' ) => $content,
                __( '场景', 'qilingshop' ) => $scene_key,
                __( '时间', 'qilingshop' ) => current_time( 'Y-m-d H:i:s' ),
            );
            $this->send_scene_notification(
                $scene_key,
                __( '业务事件通知', 'qilingshop' ),
                sprintf( __( '[%s] 业务事件通知：%s', 'qilingshop' ), $this->get_site_name(), $title ),
                $lines
            );
        }

        $this->send_site_notification( $user_id, $title, $content, $type, $link, $scene );
    }

    /**
     * 站内通知（依赖启灵主题通知系统）
     *
     * @param int    $user_id
     * @param string $title
     * @param string $content
     * @param string $type info|success|warning|error
     * @param string $link
     * @return bool
     */
    private function send_site_notification( $user_id, $title, $content = '', $type = 'info', $link = '', $scene = '' ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return false;
        }

        $scene = sanitize_key( (string) $scene );
        $scene_key = $this->normalize_scene_key( $scene );
        if ( $scene_key !== '' && ! $this->is_scene_role_enabled( $scene_key, 'user' ) ) {
            return false;
        }

        if ( $scene === '' ) {
            $scene = $scene_key;
        }

        $args = array(
            'type' => $type,
            'link' => $link,
            'scene' => $scene,
            'meta' => array(
                'source' => 'qilingshop',
                'scene'  => $scene,
            ),
        );

        do_action( 'developer_starter_add_notification', $user_id, $title, $content, $args );
        return true;
    }

    /**
     * 获取个人中心标签页链接（启灵主题）
     *
     * @param string $tab
     * @return string
     */
    private function get_account_tab_url( $tab ) {
        $tab = sanitize_key( $tab );
        if ( $tab === '' ) {
            return '';
        }

        if ( function_exists( 'developer_starter_get_frontend_account_tab_url' ) ) {
            return (string) developer_starter_get_frontend_account_tab_url( $tab );
        }

        $account_url = '';
        $pages = get_pages( array(
            'meta_key'   => '_wp_page_template',
            'meta_value' => 'templates/template-account.php',
        ) );
        if ( ! empty( $pages ) ) {
            $account_url = get_permalink( $pages[0]->ID );
        }

        if ( ! $account_url ) {
            return '';
        }

        return add_query_arg( 'tab', $tab, $account_url );
    }

    /**
     * 实物订单用户站内通知
     *
     * @param string $scene   场景键
     * @param object $order   订单对象
     * @param string $title   通知标题
     * @param string $content 通知内容
     * @param string $type    通知类型
     * @return void
     */
    private function send_shop_user_notification( $scene, $order, $title, $content, $type = 'info' ) {
        if ( ! is_object( $order ) ) {
            return;
        }

        $user_id = (int) ( $order->user_id ?? 0 );
        if ( $user_id <= 0 ) {
            return;
        }

        $scene = sanitize_key( (string) $scene );
        $scene_alias = 'qilingshop_' . $scene;
        $this->send_site_notification(
            $user_id,
            (string) $title,
            (string) $content,
            (string) $type,
            $this->get_account_tab_url( 'qls-shop' ),
            $scene_alias
        );
    }

    /**
     * 实物订单发货短信通知（用户）。
     *
     * @param object $order       订单对象
     * @param string $company     物流公司
     * @param string $tracking_no 物流单号
     * @return void
     */
    private function send_shop_shipped_sms_notification( $order, $company, $tracking_no ) {
        if ( ! is_object( $order ) ) {
            return;
        }

        $scene = 'shop_shipped';
        if ( ! $this->is_scene_role_enabled( $scene, 'user' ) ) {
            return;
        }

        if ( ! $this->get_option_bool( 'qilingshop_notify_' . $scene . '_sms_enabled', false ) ) {
            return;
        }

        $template_code = sanitize_text_field( (string) get_option( 'qilingshop_notify_' . $scene . '_sms_template_code', '' ) );
        if ( $template_code === '' ) {
            return;
        }

        $sms_manager = $this->get_theme_sms_manager();
        if ( ! is_object( $sms_manager ) || ! method_exists( $sms_manager, 'send_custom_sms' ) ) {
            return;
        }

        $phone = $this->resolve_order_sms_phone( $order );
        if ( $phone === '' ) {
            return;
        }

        $params = array(
            'orderNo'    => $this->clip_sms_value( (string) ( $order->order_no ?? '' ), 24 ),
            'company'    => $this->clip_sms_value( (string) $company, 20 ),
            'trackingNo' => $this->clip_sms_value( (string) $tracking_no, 24 ),
            'status'     => $this->clip_sms_value( __( '已发货', 'qilingshop' ), 8 ),
            'site'       => $this->clip_sms_value( $this->get_site_name(), 20 ),
        );

        $params = (array) apply_filters( 'qilingshop_notify_shop_shipped_sms_params', $params, $order, $company, $tracking_no );
        $params = array_filter(
            $params,
            static function( $value ) {
                return trim( (string) $value ) !== '';
            }
        );

        $result = $sms_manager->send_custom_sms( $phone, $template_code, $params );

        if ( function_exists( 'qilingshop_log' ) && ( ! is_array( $result ) || empty( $result['success'] ) ) ) {
            qilingshop_log(
                'Send shop shipped sms failed',
                'warning',
                array(
                    'order_id'      => (int) ( $order->id ?? 0 ),
                    'order_no'      => (string) ( $order->order_no ?? '' ),
                    'phone'         => $phone,
                    'template_code' => $template_code,
                    'result'        => is_array( $result ) ? $result : array(),
                )
            );
        }

        do_action(
            'qilingshop_shop_shipped_sms_result',
            is_array( $result ) ? $result : array(),
            array(
                'order_id'      => (int) ( $order->id ?? 0 ),
                'order_no'      => (string) ( $order->order_no ?? '' ),
                'phone'         => $phone,
                'template_code' => $template_code,
                'params'        => $params,
            )
        );
    }

    /**
     * 获取启灵主题短信管理器实例。
     *
     * @return object|null
     */
    private function get_theme_sms_manager() {
        if ( $this->theme_sms_manager === false ) {
            return null;
        }

        if ( is_object( $this->theme_sms_manager ) ) {
            return $this->theme_sms_manager;
        }

        $class = '\\Developer_Starter\\Core\\SMS_Manager';
        if ( ! class_exists( $class ) ) {
            $this->theme_sms_manager = false;
            return null;
        }

        if ( is_callable( array( $class, 'is_enabled' ) ) ) {
            try {
                $enabled = (bool) call_user_func( array( $class, 'is_enabled' ) );
            } catch ( \Throwable $e ) {
                $enabled = false;
            }

            if ( ! $enabled ) {
                $this->theme_sms_manager = false;
                return null;
            }
        }

        $manager = apply_filters( 'qilingshop_theme_sms_manager_instance', null );
        if ( is_object( $manager ) && method_exists( $manager, 'send_custom_sms' ) ) {
            $this->theme_sms_manager = $manager;
            return $this->theme_sms_manager;
        }

        try {
            $reflector = new \ReflectionClass( $class );
            $manager   = $reflector->newInstanceWithoutConstructor();

            if ( $reflector->hasMethod( 'load_sdk' ) ) {
                $load_sdk = $reflector->getMethod( 'load_sdk' );
                $load_sdk->setAccessible( true );
                $load_sdk->invoke( $manager );
            }
        } catch ( \Throwable $e ) {
            $manager = null;
        }

        if ( ! is_object( $manager ) || ! method_exists( $manager, 'send_custom_sms' ) ) {
            try {
                $manager = new $class();
            } catch ( \Throwable $e ) {
                $this->theme_sms_manager = false;
                return null;
            }
        }

        if ( ! is_object( $manager ) || ! method_exists( $manager, 'send_custom_sms' ) ) {
            $this->theme_sms_manager = false;
            return null;
        }

        $this->theme_sms_manager = $manager;
        return $this->theme_sms_manager;
    }

    /**
     * 解析订单短信手机号（优先收货手机号）。
     *
     * @param object $order 订单对象
     * @return string
     */
    private function resolve_order_sms_phone( $order ) {
        if ( ! is_object( $order ) ) {
            return '';
        }

        $candidates = array();
        $candidates[] = (string) ( $order->receiver_phone ?? '' );

        $user_id = (int) ( $order->user_id ?? 0 );
        if ( $user_id > 0 ) {
            $candidates[] = (string) get_user_meta( $user_id, 'qiling_phone', true );
            $candidates[] = (string) get_user_meta( $user_id, 'phone', true );
        }

        foreach ( $candidates as $candidate ) {
            $normalized = $this->normalize_sms_phone( $candidate );
            if ( $normalized !== '' ) {
                return $normalized;
            }
        }

        return '';
    }

    /**
     * 标准化手机号。
     *
     * @param string $phone 原始手机号
     * @return string
     */
    private function normalize_sms_phone( $phone ) {
        $phone = preg_replace( '/[^0-9]/', '', (string) $phone );
        $phone = is_string( $phone ) ? $phone : '';

        $class = '\\Developer_Starter\\Core\\SMS_Manager';
        if ( $phone !== '' && is_callable( array( $class, 'validate_phone' ) ) ) {
            $normalized = call_user_func( array( $class, 'validate_phone' ), $phone );
            return is_string( $normalized ) ? $normalized : '';
        }

        if ( $phone === '' ) {
            return '';
        }

        if ( strlen( $phone ) === 13 && strpos( $phone, '86' ) === 0 ) {
            $phone = substr( $phone, 2 );
        }

        return preg_match( '/^1[3-9]\d{9}$/', $phone ) ? $phone : '';
    }

    /**
     * 短信参数值截断。
     *
     * @param string $value   文本
     * @param int    $max_len 最大长度
     * @return string
     */
    private function clip_sms_value( $value, $max_len = 20 ) {
        $value = trim( sanitize_text_field( (string) $value ) );
        if ( $value === '' ) {
            return '';
        }

        $max_len = max( 1, absint( $max_len ) );
        if ( function_exists( 'mb_substr' ) ) {
            return (string) mb_substr( $value, 0, $max_len );
        }

        return substr( $value, 0, $max_len );
    }

    /**
     * 实物订单支付成功通知
     *
     * @param int    $order_id        订单ID
     * @param string $payment_method  支付方式
     */
    public function notify_shop_order_paid( $order_id, $payment_method ) {
        $order = $this->get_shop_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $scene_key = 'shop_paid';
        $lines                    = $this->build_shop_order_lines( $order );
        $lines[ __( '事件', 'qilingshop' ) ] = __( '订单已付款', 'qilingshop' );
        $lines[ __( '支付方式', 'qilingshop' ) ] = ! empty( $payment_method ) ? $payment_method : ( ! empty( $order->payment_method ) ? $order->payment_method : '-' );
        $lines[ __( '时间', 'qilingshop' ) ] = current_time( 'Y-m-d H:i:s' );

        $this->send_scene_notification(
            $scene_key,
            __( '实物订单付款通知', 'qilingshop' ),
            sprintf( __( '[%s] 实物订单付款通知', 'qilingshop' ), $this->get_site_name() ),
            $lines,
            '',
            'shop'
        );

        $this->send_shop_user_notification(
            $scene_key,
            $order,
            __( '订单支付成功', 'qilingshop' ),
            sprintf(
                __( '订单号：%1$s；金额：¥%2$s。', 'qilingshop' ),
                (string) ( $order->order_no ?? '' ),
                sprintf( '%.2f', (float) ( $order->final_amount ?? 0 ) )
            ),
            'success'
        );

        $order_link = '';
        if ( (int) ( $order->user_id ?? 0 ) > 0 ) {
            $order_link = $this->get_account_tab_url( 'qls-shop' );
        } elseif ( function_exists( 'qilingshop_get_order_query_page_url' ) ) {
            $order_link = qilingshop_get_order_query_page_url( (string) ( $order->order_no ?? '' ) );
        }

        $this->send_user_order_email(
            $scene_key,
            $this->resolve_shop_order_email( $order ),
            sprintf( __( '[%s] 商城订单支付成功', 'qilingshop' ), $this->get_site_name() ),
            __( '您的商城订单已支付成功，请查看以下订单信息。', 'qilingshop' ),
            $lines,
            $order_link,
            __( '查看订单', 'qilingshop' )
        );
    }

    /**
     * 实物订单发货通知
     *
     * @param int    $order_id     订单ID
     * @param string $company      物流公司
     * @param string $tracking_no  物流单号
     */
    public function notify_shop_order_shipped( $order_id, $company, $tracking_no ) {
        $order = $this->get_shop_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $scene_key = 'shop_shipped';
        $lines = $this->build_shop_order_lines( $order );
        $lines[ __( '事件', 'qilingshop' ) ]   = __( '订单已发货', 'qilingshop' );
        $lines[ __( '物流公司', 'qilingshop' ) ] = $company ? $company : '-';
        $lines[ __( '物流单号', 'qilingshop' ) ] = $tracking_no ? $tracking_no : '-';
        $lines[ __( '时间', 'qilingshop' ) ]   = current_time( 'Y-m-d H:i:s' );

        $this->send_scene_notification(
            $scene_key,
            __( '实物订单发货通知', 'qilingshop' ),
            sprintf( __( '[%s] 实物订单发货通知', 'qilingshop' ), $this->get_site_name() ),
            $lines,
            '',
            'shop'
        );

        $tracking_text = '';
        if ( $company || $tracking_no ) {
            $tracking_text = sprintf(
                __( '；物流：%1$s %2$s', 'qilingshop' ),
                $company ? (string) $company : '-',
                $tracking_no ? (string) $tracking_no : '-'
            );
        }
        $this->send_shop_user_notification(
            $scene_key,
            $order,
            __( '订单已发货', 'qilingshop' ),
            sprintf(
                __( '订单号：%1$s%2$s。', 'qilingshop' ),
                (string) ( $order->order_no ?? '' ),
                $tracking_text
            ),
            'info'
        );

        $this->send_shop_shipped_sms_notification( $order, $company, $tracking_no );
    }

    /**
     * 实物订单状态变更通知（仅完成）
     *
     * @param int $order_id   订单ID
     * @param int $status     新状态
     * @param int $old_status 旧状态
     */
    public function notify_shop_order_status_changed( $order_id, $status, $old_status ) {
        if ( (int) $status !== 3 ) {
            return;
        }

        $order = $this->get_shop_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $scene_key = 'shop_completed';
        $lines = $this->build_shop_order_lines( $order );
        $lines[ __( '事件', 'qilingshop' ) ] = __( '订单已完成', 'qilingshop' );
        $lines[ __( '状态', 'qilingshop' ) ] = $this->get_shop_order_status_text( $status );
        $lines[ __( '时间', 'qilingshop' ) ] = current_time( 'Y-m-d H:i:s' );

        $this->send_scene_notification(
            $scene_key,
            __( '实物订单完成通知', 'qilingshop' ),
            sprintf( __( '[%s] 实物订单完成通知', 'qilingshop' ), $this->get_site_name() ),
            $lines,
            '',
            'shop'
        );

        $this->send_shop_user_notification(
            $scene_key,
            $order,
            __( '订单已完成', 'qilingshop' ),
            sprintf(
                __( '订单号：%s，感谢您的购买。', 'qilingshop' ),
                (string) ( $order->order_no ?? '' )
            ),
            'success'
        );
    }

    /**
     * 实物订单取消通知
     *
     * @param int    $order_id 订单ID
     * @param string $reason   取消原因
     */
    public function notify_shop_order_cancelled( $order_id, $reason ) {
        $order = $this->get_shop_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $scene_key = 'shop_cancelled';
        $lines = $this->build_shop_order_lines( $order );
        $lines[ __( '事件', 'qilingshop' ) ] = __( '订单已取消', 'qilingshop' );
        $lines[ __( '取消原因', 'qilingshop' ) ] = ! empty( $reason ) ? $reason : '-';
        $lines[ __( '时间', 'qilingshop' ) ] = current_time( 'Y-m-d H:i:s' );

        $this->send_scene_notification(
            $scene_key,
            __( '实物订单取消通知', 'qilingshop' ),
            sprintf( __( '[%s] 实物订单取消通知', 'qilingshop' ), $this->get_site_name() ),
            $lines,
            '',
            'shop'
        );

        $reason_text = $reason ? sprintf( __( '；原因：%s', 'qilingshop' ), (string) $reason ) : '';
        $this->send_shop_user_notification(
            $scene_key,
            $order,
            __( '订单已取消', 'qilingshop' ),
            sprintf(
                __( '订单号：%1$s%2$s。', 'qilingshop' ),
                (string) ( $order->order_no ?? '' ),
                $reason_text
            ),
            'warning'
        );
    }

    /**
     * 实物订单申请退款通知
     *
     * @param int    $order_id 订单ID
     * @param string $reason   退款原因
     */
    public function notify_shop_order_refund_applied( $order_id, $reason ) {
        $order = $this->get_shop_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $scene_key = 'shop_refund_applied';
        $lines = $this->build_shop_order_lines( $order );
        $lines[ __( '事件', 'qilingshop' ) ] = __( '订单申请退款', 'qilingshop' );
        $lines[ __( '退款原因', 'qilingshop' ) ] = ! empty( $reason ) ? $reason : '-';
        $lines[ __( '时间', 'qilingshop' ) ] = current_time( 'Y-m-d H:i:s' );

        $this->send_scene_notification(
            $scene_key,
            __( '实物订单退款申请通知', 'qilingshop' ),
            sprintf( __( '[%s] 实物订单退款申请通知', 'qilingshop' ), $this->get_site_name() ),
            $lines,
            '',
            'shop'
        );

        $reason_text = $reason ? sprintf( __( '；原因：%s', 'qilingshop' ), (string) $reason ) : '';
        $this->send_shop_user_notification(
            $scene_key,
            $order,
            __( '退款申请已提交', 'qilingshop' ),
            sprintf(
                __( '订单号：%1$s%2$s。', 'qilingshop' ),
                (string) ( $order->order_no ?? '' ),
                $reason_text
            ),
            'warning'
        );
    }

    /**
     * 实物订单退款完成通知
     *
     * @param int    $order_id      订单ID
     * @param int    $refund_id     售后单ID
     * @param string $refund_mode   退款方式
     * @param array  $refund_detail 退款详情
     */
    public function notify_shop_order_refunded( $order_id, $refund_id = 0, $refund_mode = '', $refund_detail = array() ) {
        $order = $this->get_shop_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $refund_detail = is_array( $refund_detail ) ? $refund_detail : array();
        $refund_mode_label = $this->get_shop_refund_mode_text( $refund_mode );
        if ( $refund_mode_label === '' ) {
            $refund_mode_label = __( '未记录', 'qilingshop' );
        }
        $gateway_status = $this->get_shop_refund_gateway_status_text( (string) ( $refund_detail['gateway_status'] ?? '' ) );
        $gateway_refund_no = sanitize_text_field( (string) ( $refund_detail['gateway_refund_no'] ?? '' ) );
        $gateway_refunded_at = sanitize_text_field( (string) ( $refund_detail['gateway_refunded_at'] ?? '' ) );

        $scene_key = 'shop_refunded';
        $lines = $this->build_shop_order_lines( $order );
        $lines[ __( '事件', 'qilingshop' ) ] = __( '订单已退款', 'qilingshop' );
        $lines[ __( '退款方式', 'qilingshop' ) ] = $refund_mode_label;
        if ( (int) $refund_id > 0 ) {
            $lines[ __( '售后单号', 'qilingshop' ) ] = '#' . (int) $refund_id;
        }
        if ( $gateway_status !== '' ) {
            $lines[ __( '原路状态', 'qilingshop' ) ] = $gateway_status;
        }
        if ( $gateway_refund_no !== '' ) {
            $lines[ __( '网关退款单号', 'qilingshop' ) ] = $gateway_refund_no;
        }
        if ( $gateway_refunded_at !== '' ) {
            $lines[ __( '网关退款时间', 'qilingshop' ) ] = $gateway_refunded_at;
        }
        $lines[ __( '时间', 'qilingshop' ) ] = current_time( 'Y-m-d H:i:s' );

        $this->send_scene_notification(
            $scene_key,
            __( '实物订单退款通知', 'qilingshop' ),
            sprintf( __( '[%s] 实物订单退款通知', 'qilingshop' ), $this->get_site_name() ),
            $lines,
            '',
            'shop'
        );

        $user_content = sprintf(
            __( '订单号：%1$s，退款已处理完成（%2$s）。', 'qilingshop' ),
            (string) ( $order->order_no ?? '' ),
            $refund_mode_label
        );
        if ( $gateway_status !== '' ) {
            $user_content .= ' ' . sprintf( __( '原路状态：%s。', 'qilingshop' ), $gateway_status );
        }
        if ( $gateway_refund_no !== '' ) {
            $user_content .= ' ' . sprintf( __( '退款单号：%s。', 'qilingshop' ), $gateway_refund_no );
        }

        $this->send_shop_user_notification(
            $scene_key,
            $order,
            __( '订单已退款', 'qilingshop' ),
            $user_content,
            'warning'
        );
    }

    /**
     * 新工单通知后台。
     *
     * @param int   $ticket_id   工单 ID。
     * @param array $ticket_data 工单数据。
     * @return void
     */
    public function notify_shop_ticket_created( $ticket_id, $ticket_data = array() ) {
        $ticket = $this->get_shop_ticket( $ticket_id );
        if ( ! $ticket ) {
            return;
        }

        $lines = $this->build_shop_ticket_lines( $ticket );
        $lines[ __( '事件', 'qilingshop' ) ] = __( '用户提交新工单', 'qilingshop' );
        $lines[ __( '时间', 'qilingshop' ) ] = current_time( 'Y-m-d H:i:s' );
        $lines[ __( '后台处理', 'qilingshop' ) ] = $this->get_shop_ticket_admin_url( $ticket );

        $this->send_scene_notification(
            'shop_ticket_created',
            __( '售后工单提醒', 'qilingshop' ),
            sprintf( __( '[%s] 新售后工单提醒', 'qilingshop' ), $this->get_site_name() ),
            $lines,
            '',
            'shop'
        );
    }

    /**
     * 用户追问通知后台。
     *
     * @param int $ticket_id 工单 ID。
     * @param int $user_id   用户 ID。
     * @return void
     */
    public function notify_shop_ticket_user_replied( $ticket_id, $user_id ) {
        $ticket = $this->get_shop_ticket( $ticket_id );
        if ( ! $ticket ) {
            return;
        }

        $lines = $this->build_shop_ticket_lines( $ticket );
        $lines[ __( '事件', 'qilingshop' ) ] = __( '用户补充工单回复', 'qilingshop' );
        $lines[ __( '回复用户', 'qilingshop' ) ] = $this->get_user_display( $user_id );
        $lines[ __( '时间', 'qilingshop' ) ] = current_time( 'Y-m-d H:i:s' );
        $lines[ __( '后台处理', 'qilingshop' ) ] = $this->get_shop_ticket_admin_url( $ticket );

        $this->send_scene_notification(
            'shop_ticket_user_replied',
            __( '售后工单有新回复', 'qilingshop' ),
            sprintf( __( '[%s] 售后工单新回复', 'qilingshop' ), $this->get_site_name() ),
            $lines,
            '',
            'shop'
        );
    }

    /**
     * 后台公开回复通知用户。
     *
     * @param int $ticket_id 工单 ID。
     * @param int $admin_id  管理员 ID。
     * @param int  $status    工单状态。
     * @param bool $user_visible_update 是否用户可见更新。
     * @return void
     */
    public function notify_shop_ticket_admin_replied( $ticket_id, $admin_id, $status, $user_visible_update = true ) {
        if ( ! $user_visible_update ) {
            return;
        }

        $ticket = $this->get_shop_ticket( $ticket_id );
        if ( ! $ticket || (int) ( $ticket->user_id ?? 0 ) <= 0 ) {
            return;
        }

        $scene_key = 'shop_ticket_admin_replied';
        $ticket_url = $this->get_shop_ticket_user_url( $ticket );
        $status_text = function_exists( 'qls_shop_ticket' )
            ? qls_shop_ticket()->get_status_text( (int) $status )
            : (string) $status;

        $this->send_site_notification(
            (int) $ticket->user_id,
            __( '工单有新回复', 'qilingshop' ),
            sprintf(
                __( '工单 %1$s 已更新，当前状态：%2$s。', 'qilingshop' ),
                (string) ( $ticket->ticket_no ?? '' ),
                $status_text
            ),
            'info',
            $ticket_url,
            'qilingshop_' . $scene_key
        );

        $user = get_user_by( 'id', (int) $ticket->user_id );
        if ( $user && ! empty( $user->user_email ) ) {
            $lines = $this->build_shop_ticket_lines( $ticket );
            $lines[ __( '事件', 'qilingshop' ) ] = __( '客服已回复工单', 'qilingshop' );
            $lines[ __( '客服', 'qilingshop' ) ] = $this->get_user_display( $admin_id );
            $lines[ __( '时间', 'qilingshop' ) ] = current_time( 'Y-m-d H:i:s' );

            $this->send_user_order_email(
                $scene_key,
                $user->user_email,
                sprintf( __( '[%s] 您的售后工单有新回复', 'qilingshop' ), $this->get_site_name() ),
                __( '您的售后工单已有客服回复，请查看以下信息。', 'qilingshop' ),
                $lines,
                $ticket_url,
                __( '查看工单', 'qilingshop' )
            );
        }
    }

    /**
     * 获取工单对象。
     *
     * @param int $ticket_id 工单 ID。
     * @return object|null
     */
    private function get_shop_ticket( $ticket_id ) {
        if ( ! function_exists( 'qls_shop_ticket' ) ) {
            return null;
        }

        return qls_shop_ticket()->get_ticket( absint( $ticket_id ) );
    }

    /**
     * 工单通知内容行。
     *
     * @param object $ticket 工单对象。
     * @return array
     */
    private function build_shop_ticket_lines( $ticket ) {
        $ticket_manager = function_exists( 'qls_shop_ticket' ) ? qls_shop_ticket() : null;
        $lines = array(
            __( '工单号', 'qilingshop' ) => (string) ( $ticket->ticket_no ?? '' ),
            __( '标题', 'qilingshop' )   => (string) ( $ticket->title ?? '' ),
            __( '类型', 'qilingshop' )   => $ticket_manager ? $ticket_manager->get_type_text( (string) ( $ticket->type ?? '' ) ) : (string) ( $ticket->type ?? '' ),
            __( '状态', 'qilingshop' )   => $ticket_manager ? $ticket_manager->get_status_text( (int) ( $ticket->status ?? 0 ) ) : (string) ( $ticket->status ?? '' ),
            __( '用户', 'qilingshop' )   => $this->get_user_display( (int) ( $ticket->user_id ?? 0 ) ),
            __( '用户ID', 'qilingshop' ) => (int) ( $ticket->user_id ?? 0 ),
        );

        if ( ! empty( $ticket->order_id ) && function_exists( 'qls_shop_order' ) ) {
            $order = qls_shop_order()->get( (int) $ticket->order_id, true );
            if ( $order && ! empty( $order->order_no ) ) {
                $lines[ __( '关联订单', 'qilingshop' ) ] = (string) $order->order_no;
            } else {
                $lines[ __( '关联订单', 'qilingshop' ) ] = '#' . (int) $ticket->order_id;
            }
        }

        return $lines;
    }

    /**
     * 工单后台处理链接。
     *
     * @param object $ticket 工单对象。
     * @return string
     */
    private function get_shop_ticket_admin_url( $ticket ) {
        return admin_url( 'admin.php?page=qls-shop-tickets&ticket_id=' . (int) ( $ticket->id ?? 0 ) );
    }

    /**
     * 工单用户查看链接。
     *
     * @param object $ticket 工单对象。
     * @return string
     */
    private function get_shop_ticket_user_url( $ticket ) {
        $url = '';
        if ( function_exists( 'qls_shop_public' ) ) {
            $url = qls_shop_public()->get_page_url( 'my-tickets' );
        }

        if ( $url === '' ) {
            $url = $this->get_account_tab_url( 'qls-shop' );
        }

        if ( $url === '' ) {
            return '';
        }

        return add_query_arg( 'ticket_id', (int) ( $ticket->id ?? 0 ), $url );
    }

    /**
     * 发送场景通知（邮件 + 推送）
     *
     * @param string $scene         场景
     * @param string $title         标题
     * @param string $mail_subject  邮件主题
     * @param array  $lines         内容行
     * @param string $legacy_option 旧开关（不含前缀）
     * @param string $legacy_scene  旧场景键（不含前缀）
     */
    private function send_scene_notification( $scene, $title, $mail_subject, $lines, $legacy_option = '', $legacy_scene = '' ) {
        $scene = $this->normalize_scene_key( $scene );
        if ( $scene === '' || ! $this->is_scene_role_enabled( $scene, 'admin' ) ) {
            return;
        }

        $method = $this->get_notify_method( $scene, $legacy_option, $legacy_scene );

        if ( $method === 'none' ) {
            return;
        }

        if ( $this->method_has_email( $method ) ) {
            $this->send_email( $mail_subject, $lines );
        }

        if ( $this->method_has_push( $method ) ) {
            $this->send_push( $scene, $title, $lines, $legacy_scene );
        }
    }

    /**
     * 发送邮件通知
     *
     * @param string $subject 邮件主题
     * @param array  $lines   内容行
     */
    private function send_email( $subject, $lines ) {
        $to = sanitize_email( get_option( 'admin_email' ) );
        if ( empty( $to ) ) {
            return;
        }

        $message = $this->build_plain_text_message( $lines );
        wp_mail( $to, $subject, $message, array( 'Content-Type: text/plain; charset=UTF-8' ) );
    }

    /**
     * 发送用户邮件
     *
     * @param string $to
     * @param string $subject
     * @param array  $lines
     * @param string $intro
     * @param string $button_url
     * @param string $button_text
     * @return bool
     */
    private function send_user_email( $to, $subject, $lines, $intro = '', $button_url = '', $button_text = '' ) {
        $to = sanitize_email( $to );
        if ( empty( $to ) || ! is_email( $to ) ) {
            return false;
        }

        $subject = wp_strip_all_tags( (string) $subject );
        $message = $this->build_plain_text_message( $lines );
        if ( $message === '' ) {
            return false;
        }

        $headers = array( 'Content-Type: text/plain; charset=UTF-8' );
        $intro = trim( (string) $intro );
        if ( $intro === '' ) {
            $intro = __( '您有一条新的会员通知，请查看以下详情。', 'qilingshop' );
        }

        if ( function_exists( 'developer_starter_build_html_email_template' ) ) {
            $template_args = array(
                'title'  => $subject,
                'intro'  => $intro,
                'lines'  => $lines,
                'notice' => __( '本邮件由系统自动发送，请勿直接回复。', 'qilingshop' ),
            );

            $button_url = esc_url_raw( (string) $button_url );
            if ( $button_url !== '' && trim( (string) $button_text ) !== '' ) {
                $template_args['button_url'] = $button_url;
                $template_args['button_text'] = wp_strip_all_tags( (string) $button_text );
            }

            $html_message = developer_starter_build_html_email_template(
                $template_args
            );
            if ( is_string( $html_message ) && trim( $html_message ) !== '' ) {
                $message = $html_message;
                $headers = array( 'Content-Type: text/html; charset=UTF-8' );
            }
        }

        return (bool) wp_mail( $to, $subject, $message, $headers );
    }

    /**
     * 发送用户/游客订单邮件（受“通知用户”场景开关控制）。
     *
     * @param string $scene
     * @param string $to
     * @param string $subject
     * @param string $intro
     * @param array  $lines
     * @param string $button_url
     * @param string $button_text
     * @return bool
     */
    private function send_user_order_email( $scene, $to, $subject, $intro, $lines, $button_url = '', $button_text = '' ) {
        $scene_key = $this->normalize_scene_key( $scene );
        if ( $scene_key !== '' && ! $this->is_scene_role_enabled( $scene_key, 'user' ) ) {
            return false;
        }

        $to = sanitize_email( (string) $to );
        if ( $to === '' || ! is_email( $to ) ) {
            return false;
        }

        $sent = $this->send_user_email( $to, $subject, $lines, $intro, $button_url, $button_text );
        if ( ! $sent && function_exists( 'qilingshop_log' ) ) {
            qilingshop_log(
                'Send user order email failed',
                'warning',
                array(
                    'scene' => $scene_key,
                    'email' => $to,
                )
            );
        }

        return (bool) $sent;
    }

    /**
     * 解析文本或 JSON 中的邮箱地址。
     *
     * @param mixed $value
     * @return string
     */
    private function extract_email_from_text_value( $value ) {
        if ( is_array( $value ) || is_object( $value ) ) {
            return '';
        }

        $text = trim( (string) $value );
        if ( $text === '' ) {
            return '';
        }

        $decoded = json_decode( $text, true );
        if ( is_array( $decoded ) ) {
            foreach ( array( 'email', 'contact_email', 'receiver_email' ) as $key ) {
                if ( ! empty( $decoded[ $key ] ) ) {
                    $email = sanitize_email( (string) $decoded[ $key ] );
                    if ( $email !== '' && is_email( $email ) ) {
                        return $email;
                    }
                }
            }
        }

        if ( preg_match( '/([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/i', $text, $matches ) ) {
            $email = sanitize_email( (string) $matches[1] );
            if ( $email !== '' && is_email( $email ) ) {
                return $email;
            }
        }

        return '';
    }

    /**
     * 获取用户账号邮箱。
     *
     * @param int $user_id
     * @return string
     */
    private function resolve_user_email( $user_id ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return '';
        }

        $user = get_user_by( 'ID', $user_id );
        if ( ! $user || empty( $user->user_email ) ) {
            return '';
        }

        $email = sanitize_email( (string) $user->user_email );
        return $email !== '' && is_email( $email ) ? $email : '';
    }

    /**
     * 获取资源订单用户/游客收件邮箱。
     *
     * @param object $order
     * @return string
     */
    private function resolve_resource_order_email( $order ) {
        if ( ! is_object( $order ) ) {
            return '';
        }

        $email = $this->resolve_user_email( (int) ( $order->user_id ?? 0 ) );
        if ( $email !== '' ) {
            return $email;
        }

        $email = $this->extract_email_from_text_value( $order->contact_info ?? '' );
        if ( $email !== '' ) {
            return $email;
        }

        if ( ! empty( $order->order_no ) && class_exists( 'QilingShop_Database' ) ) {
            $guest_order = QilingShop_Database::instance()->get_row(
                'guest_orders',
                array(
                    'order_no' => (string) $order->order_no,
                )
            );
            if ( $guest_order && ! empty( $guest_order->contact_email ) ) {
                $email = sanitize_email( (string) $guest_order->contact_email );
                if ( $email !== '' && is_email( $email ) ) {
                    return $email;
                }
            }
        }

        return '';
    }

    /**
     * 获取商城订单用户/游客收件邮箱。
     *
     * @param object $order
     * @return string
     */
    private function resolve_shop_order_email( $order ) {
        if ( ! is_object( $order ) ) {
            return '';
        }

        $email = $this->resolve_user_email( (int) ( $order->user_id ?? 0 ) );
        if ( $email !== '' ) {
            return $email;
        }

        $email = $this->extract_email_from_text_value( $order->seller_remark ?? '' );
        if ( $email !== '' ) {
            return $email;
        }

        return $this->extract_email_from_text_value( $order->receiver_address ?? '' );
    }

    /**
     * 发送飞书/钉钉推送（支持多通道）
     *
     * @param string $scene 场景
     * @param string $title 标题
     * @param array  $lines 内容行
     * @param string $legacy_scene 旧场景键（不含前缀）
     */
    private function send_push( $scene, $title, $lines, $legacy_scene = '' ) {
        if ( ! function_exists( 'qilinghook_send' ) ) {
            return;
        }

        $channels = $this->get_push_channels( $scene, $legacy_scene );
        if ( empty( $channels ) ) {
            return;
        }

        $message = '[' . $this->get_site_name() . '] ' . wp_strip_all_tags( (string) $title );
        $body    = $this->build_plain_text_message( $lines );
        if ( $body !== '' ) {
            $message .= "\n\n" . $body;
        }

        $args = array(
            'type'   => 'text',
            'source' => 'qilingshop_' . sanitize_key( $scene ),
        );

        foreach ( $channels as $channel_id ) {
            qilinghook_send( $channel_id, $message, $args );
        }
    }

    /**
     * 获取通知方式
     *
     * @param string $scene         场景
     * @param string $legacy_option 旧开关（不含前缀）
     * @param string $legacy_scene  旧场景键（不含前缀）
     * @return string
     */
    private function get_notify_method( $scene, $legacy_option = '', $legacy_scene = '' ) {
        $scene = sanitize_key( $scene );
        $mode  = get_option( 'qilingshop_notify_' . $scene . '_method', '' );

        if ( ! in_array( $mode, array( 'none', 'email', 'push', 'both' ), true ) ) {
            $mode = '';
        }

        if ( $mode === '' && $legacy_scene !== '' ) {
            $legacy_scene = sanitize_key( $legacy_scene );
            $legacy_mode  = get_option( 'qilingshop_notify_' . $legacy_scene . '_method', '' );
            if ( in_array( $legacy_mode, array( 'none', 'email', 'push', 'both' ), true ) ) {
                $mode = $legacy_mode;
            }
        }

        // 新配置为空时，兼容旧的邮件开关。
        if ( $mode === '' ) {
            if ( ! empty( $legacy_option ) && get_option( 'qilingshop_' . $legacy_option, false ) ) {
                return 'email';
            }
            return 'none';
        }

        return $mode;
    }

    /**
     * 获取推送通道列表
     *
     * @param string $scene 场景
     * @param string $legacy_scene 旧场景键（不含前缀）
     * @return array
     */
    private function get_push_channels( $scene, $legacy_scene = '' ) {
        $scene = sanitize_key( $scene );
        $values = $this->parse_channels_option_value(
            get_option( 'qilingshop_notify_' . $scene . '_push_channel', array() )
        );

        if ( empty( $values ) && $legacy_scene !== '' ) {
            $legacy_scene = sanitize_key( $legacy_scene );
            $values = $this->parse_channels_option_value(
                get_option( 'qilingshop_notify_' . $legacy_scene . '_push_channel', array() )
            );
        }

        $channels = array();
        foreach ( $values as $channel_id ) {
            $clean_id = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $channel_id );
            if ( $clean_id !== '' ) {
                $channels[] = $clean_id;
            }
        }

        return array_values( array_unique( $channels ) );
    }

    /**
     * 解析通道配置选项值
     *
     * @param mixed $raw_channels 选项原始值
     * @return array
     */
    private function parse_channels_option_value( $raw_channels ) {
        if ( is_array( $raw_channels ) ) {
            return $raw_channels;
        }
        if ( $raw_channels === '' || $raw_channels === null ) {
            return array();
        }
        return array( $raw_channels );
    }

    /**
     * 将通知场景归一化为配置键
     *
     * @param string $scene 原始场景
     * @return string
     */
    private function normalize_scene_key( $scene ) {
        $scene = sanitize_key( (string) $scene );
        if ( $scene === '' ) {
            return '';
        }

        $aliases = array(
            'qilingshop_recharge'            => 'recharge',
            'qilingshop_resource_order'      => 'order',
            'qilingshop_vip_upgraded'        => 'vip',
            'qilingshop_vip_expiring'        => 'vip_expiring',
            'qilingshop_vip_expired'         => 'vip_expired',
            'qilingshop_checkin'             => 'checkin',
            'qilingshop_invite_registered'   => 'invite_registered',
            'qilingshop_invite_tier'         => 'invite_tier',
            'qilingshop_affiliate_commission'=> 'affiliate_commission',
            'qilingshop_author_commission'   => 'author_commission',
            'qilingshop_withdraw_submitted'  => 'withdraw_submitted',
            'qilingshop_withdraw_approved'   => 'withdraw_approved',
            'qilingshop_withdraw_rejected'   => 'withdraw_rejected',
            'qilingshop_shop_paid'           => 'shop_paid',
            'qilingshop_shop_shipped'        => 'shop_shipped',
            'qilingshop_shop_completed'      => 'shop_completed',
            'qilingshop_shop_cancelled'      => 'shop_cancelled',
            'qilingshop_shop_refund_applied' => 'shop_refund_applied',
            'qilingshop_shop_refunded'       => 'shop_refunded',
            'qilingshop_shop_ticket_created' => 'shop_ticket_created',
            'qilingshop_shop_ticket_user_replied' => 'shop_ticket_user_replied',
            'qilingshop_shop_ticket_admin_replied' => 'shop_ticket_admin_replied',
            'qilingshop_points_expiring'     => 'points_expiring',
            'qilingshop_points_expired'      => 'points_expired',
            'qilingshop_birthday_coupon'     => 'birthday_coupon',
            'qilingshop_growth_level_changed'=> 'growth_level_changed',
            'qilingshop_task_reward'         => 'task_reward',
            'qilingshop_payment_recovery'    => 'payment_recovery',
            'qls_group_success'              => 'group_success',
            'qls_group_failed'               => 'group_failed',
            'shop_order_paid'                => 'shop_paid',
            'shop_order_shipped'             => 'shop_shipped',
            'shop_order_completed'           => 'shop_completed',
            'shop_order_cancelled'           => 'shop_cancelled',
            'shop_order_refund_applied'      => 'shop_refund_applied',
            'shop_order_refunded'            => 'shop_refunded',
            'shop_ticket_created'            => 'shop_ticket_created',
            'shop_ticket_user_replied'       => 'shop_ticket_user_replied',
            'shop_ticket_admin_replied'      => 'shop_ticket_admin_replied',
        );

        if ( isset( $aliases[ $scene ] ) ) {
            return $aliases[ $scene ];
        }

        if ( strpos( $scene, 'qilingshop_' ) === 0 ) {
            $trimmed = substr( $scene, 11 );
            if ( isset( $aliases[ $trimmed ] ) ) {
                return $aliases[ $trimmed ];
            }
            $scene = $trimmed;
        }

        if ( strpos( $scene, 'qls_' ) === 0 ) {
            $trimmed = substr( $scene, 4 );
            if ( isset( $aliases[ $trimmed ] ) ) {
                return $aliases[ $trimmed ];
            }
            $scene = $trimmed;
        }

        return $scene;
    }

    /**
     * 场景角色通知开关
     *
     * @param string $scene 场景键
     * @param string $role  admin|user
     * @return bool
     */
    private function is_scene_role_enabled( $scene, $role = 'user' ) {
        $scene = $this->normalize_scene_key( $scene );
        if ( $scene === '' ) {
            return true;
        }

        $role = $role === 'admin' ? 'admin' : 'user';
        $settings = $this->get_notify_scene_settings();
        $default = $role !== 'admin';
        if ( isset( $settings[ $scene ]['default_' . $role] ) ) {
            $default = (bool) $settings[ $scene ]['default_' . $role];
        }

        return $this->get_option_bool( 'qilingshop_notify_' . $scene . '_' . $role . '_enabled', $default );
    }

    /**
     * 获取通知场景默认配置
     *
     * @return array
     */
    private function get_notify_scene_settings() {
        return array(
            'recharge'          => array( 'default_admin' => true,  'default_user' => true ),
            'order'             => array( 'default_admin' => true,  'default_user' => true ),
            'vip'               => array( 'default_admin' => true,  'default_user' => true ),
            'vip_expiring'      => array( 'default_admin' => false, 'default_user' => true ),
            'vip_expired'       => array( 'default_admin' => false, 'default_user' => true ),
            'checkin'           => array( 'default_admin' => false, 'default_user' => true ),
            'invite_registered' => array( 'default_admin' => false, 'default_user' => true ),
            'invite_tier'       => array( 'default_admin' => false, 'default_user' => true ),
            'affiliate_commission' => array( 'default_admin' => false, 'default_user' => true ),
            'author_commission' => array( 'default_admin' => false, 'default_user' => true ),
            'withdraw_submitted' => array( 'default_admin' => true, 'default_user' => true ),
            'withdraw_approved' => array( 'default_admin' => false, 'default_user' => true ),
            'withdraw_rejected' => array( 'default_admin' => false, 'default_user' => true ),
            'shop_paid'         => array( 'default_admin' => true,  'default_user' => true ),
            'shop_shipped'      => array( 'default_admin' => true,  'default_user' => true ),
            'shop_completed'    => array( 'default_admin' => true,  'default_user' => true ),
            'shop_cancelled'    => array( 'default_admin' => true,  'default_user' => true ),
            'shop_refund_applied' => array( 'default_admin' => true, 'default_user' => true ),
            'shop_refunded'     => array( 'default_admin' => true,  'default_user' => true ),
            'shop_ticket_created' => array( 'default_admin' => true, 'default_user' => false ),
            'shop_ticket_user_replied' => array( 'default_admin' => true, 'default_user' => false ),
            'shop_ticket_admin_replied' => array( 'default_admin' => false, 'default_user' => true ),
            'points_expiring'   => array( 'default_admin' => false, 'default_user' => true ),
            'points_expired'    => array( 'default_admin' => false, 'default_user' => true ),
            'birthday_coupon'   => array( 'default_admin' => false, 'default_user' => true ),
            'growth_level_changed' => array( 'default_admin' => false, 'default_user' => true ),
            'task_reward'       => array( 'default_admin' => false, 'default_user' => true ),
            'group_success'     => array( 'default_admin' => false, 'default_user' => true ),
            'group_failed'      => array( 'default_admin' => false, 'default_user' => true ),
            'payment_recovery'  => array( 'default_admin' => false, 'default_user' => true ),
        );
    }

    /**
     * 读取布尔选项（兼容旧值格式）
     *
     * @param string $option_name 选项名
     * @param bool   $default     默认值
     * @return bool
     */
    private function get_option_bool( $option_name, $default = false ) {
        $raw = get_option( (string) $option_name, null );
        if ( $raw === null ) {
            return (bool) $default;
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

        return (bool) $default;
    }

    /**
     * 是否包含邮件通知
     *
     * @param string $mode 通知方式
     * @return bool
     */
    private function method_has_email( $mode ) {
        return in_array( (string) $mode, array( 'email', 'both' ), true );
    }

    /**
     * 是否包含推送通知
     *
     * @param string $mode 通知方式
     * @return bool
     */
    private function method_has_push( $mode ) {
        return in_array( (string) $mode, array( 'push', 'both' ), true );
    }

    /**
     * 构建纯文本消息内容
     *
     * @param array $lines 内容行
     * @return string
     */
    private function build_plain_text_message( $lines ) {
        if ( empty( $lines ) || ! is_array( $lines ) ) {
            return '';
        }

        $formatted = array();
        foreach ( $lines as $label => $value ) {
            if ( is_array( $value ) ) {
                $value = implode( ', ', array_map( 'strval', $value ) );
            }

            $clean_value = trim( wp_strip_all_tags( (string) $value ) );
            if ( $clean_value === '' ) {
                continue;
            }

            if ( is_string( $label ) && $label !== '' && ! is_numeric( $label ) ) {
                $formatted[] = wp_strip_all_tags( $label ) . ': ' . $clean_value;
            } else {
                $formatted[] = $clean_value;
            }
        }

        return implode( "\n", $formatted );
    }

    /**
     * 获取站点名
     *
     * @return string
     */
    private function get_site_name() {
        return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
    }

    /**
     * 获取用户展示文本
     *
     * @param int    $user_id  用户ID
     * @param string $guest_id 游客标识
     * @return string
     */
    private function get_user_display( $user_id, $guest_id = '' ) {
        $user_id = (int) $user_id;
        if ( $user_id > 0 ) {
            $user = get_user_by( 'ID', $user_id );
            if ( $user ) {
                return $user->user_login . ' (#' . $user->ID . ')';
            }
            return '#' . $user_id;
        }

        if ( $guest_id !== '' ) {
            return sprintf( __( '游客(%s)', 'qilingshop' ), (string) $guest_id );
        }

        return __( '游客', 'qilingshop' );
    }

    /**
     * 获取 VIP 等级名称
     *
     * @param int $level_id 等级ID
     * @return string
     */
    private function get_vip_level_name( $level_id ) {
        $level_id = (int) $level_id;
        if ( $level_id <= 0 ) {
            return __( '无', 'qilingshop' );
        }

        if ( class_exists( 'QilingShop_VIP' ) ) {
            $level = QilingShop_VIP::instance()->get_level_by_id( $level_id );
            if ( $level && ! empty( $level->level_name ) ) {
                return (string) $level->level_name;
            }
        }

        return '#' . $level_id;
    }

    /**
     * 获取实物订单对象
     *
     * @param int $order_id 订单ID
     * @return object|null
     */
    private function get_shop_order( $order_id ) {
        $order_id = (int) $order_id;
        if ( $order_id <= 0 || ! class_exists( 'QLS_Shop_Order' ) ) {
            return null;
        }

        if ( function_exists( 'qls_shop_order' ) ) {
            return qls_shop_order()->get( $order_id, true );
        }

        return QLS_Shop_Order::instance()->get( $order_id, true );
    }

    /**
     * 构建实物订单通知基础字段
     *
     * @param object $order 订单对象
     * @return array
     */
    private function build_shop_order_lines( $order ) {
        $items_text = '-';
        if ( ! empty( $order->items ) && is_array( $order->items ) ) {
            $names = array();
            foreach ( $order->items as $item ) {
                if ( ! empty( $item->product_title ) ) {
                    $names[] = (string) $item->product_title;
                }
                if ( count( $names ) >= 3 ) {
                    break;
                }
            }
            if ( ! empty( $names ) ) {
                $items_text = implode( ' / ', $names );
                if ( count( $order->items ) > 3 ) {
                    $items_text .= ' ...';
                }
            }
        }

        return array(
            __( '订单号', 'qilingshop' )   => $order->order_no ?? '',
            __( '用户', 'qilingshop' )     => $this->get_user_display( (int) ( $order->user_id ?? 0 ) ),
            __( '订单金额(元)', 'qilingshop' ) => isset( $order->final_amount ) ? sprintf( '%.2f', (float) $order->final_amount ) : '0.00',
            __( '订单状态', 'qilingshop' ) => $this->get_shop_order_status_text( (int) ( $order->status ?? 0 ) ),
            __( '商品', 'qilingshop' )     => $items_text,
        );
    }

    /**
     * 获取实物订单状态文本
     *
     * @param int $status 状态值
     * @return string
     */
    private function get_shop_order_status_text( $status ) {
        if ( function_exists( 'qls_shop_order' ) && method_exists( qls_shop_order(), 'get_status_text' ) ) {
            return (string) qls_shop_order()->get_status_text( (int) $status );
        }

        $map = array(
            0 => __( '待付款', 'qilingshop' ),
            1 => __( '已付款', 'qilingshop' ),
            2 => __( '已发货', 'qilingshop' ),
            3 => __( '已完成', 'qilingshop' ),
            4 => __( '已取消', 'qilingshop' ),
            5 => __( '退款中', 'qilingshop' ),
            6 => __( '已退款', 'qilingshop' ),
        );

        return isset( $map[ (int) $status ] ) ? $map[ (int) $status ] : __( '未知', 'qilingshop' );
    }

    /**
     * 退款方式文本
     *
     * @param string $refund_mode
     * @return string
     */
    private function get_shop_refund_mode_text( $refund_mode ) {
        $refund_mode = sanitize_key( (string) $refund_mode );
        if ( $refund_mode === 'gateway' ) {
            return __( '原路退回', 'qilingshop' );
        }
        if ( $refund_mode === 'withdrawable_balance' ) {
            return __( '退回可提现余额', 'qilingshop' );
        }

        return '';
    }

    /**
     * 原路退款状态文本
     *
     * @param string $status
     * @return string
     */
    private function get_shop_refund_gateway_status_text( $status ) {
        $status = sanitize_key( (string) $status );
        if ( $status === '' ) {
            return '';
        }

        $labels = array(
            'processing'            => __( '退款处理中', 'qilingshop' ),
            'success'               => __( '原路退款成功', 'qilingshop' ),
            'failed'                => __( '原路退款失败', 'qilingshop' ),
            'local_finalize_failed' => __( '资金已退回，但本地状态收尾失败', 'qilingshop' ),
            'fallback_to_withdrawable_balance' => __( '原路不支持，已自动退回可提现余额', 'qilingshop' ),
        );

        return $labels[ $status ] ?? strtoupper( $status );
    }
}
