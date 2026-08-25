<?php
/**
 * Task Center Template
 *
 * @package QilingShop
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user_id = get_current_user_id();
$tasks = isset( $tasks ) && is_array( $tasks ) ? $tasks : array();
$shop_url = function_exists( 'qls_shop_public' ) ? qls_shop_public()->get_shop_url() : home_url( '/' );
$shop_center_url = function_exists( 'qls_shop_public' ) ? qls_shop_public()->get_page_url( 'shop-center' ) : '';
$total_tasks = count( $tasks );
$done_count = 0;
$pending_count = 0;
$locked_count = 0;
$reward_preview = array();
$claim_result = isset( $_GET['qls_task_claim_result'] ) ? sanitize_key( wp_unslash( $_GET['qls_task_claim_result'] ) ) : '';
$claim_code = isset( $_GET['qls_task_claim_code'] ) ? sanitize_key( wp_unslash( $_GET['qls_task_claim_code'] ) ) : '';
$claim_notice = '';

foreach ( $tasks as $task_item ) {
    $status = isset( $task_item['status'] ) ? (string) $task_item['status'] : '';
    if ( $status === 'done' ) {
        $done_count++;
    } elseif ( $status === 'pending' || $status === 'waiting' ) {
        $pending_count++;
    } elseif ( $status === 'locked' || $status === 'inactive' ) {
        $locked_count++;
    }

    if ( ! empty( $task_item['reward'] ) ) {
        $reward_preview[] = (string) $task_item['reward'];
    }
}

$reward_preview = array_unique( array_filter( $reward_preview ) );
$completion_rate = $total_tasks > 0 ? (int) round( ( $done_count / $total_tasks ) * 100 ) : 0;

if ( $claim_result !== '' ) {
    $claim_messages = array(
        'claimed'          => __( '领取成功，奖励已到账。', 'qilingshop' ),
        'already_claimed'  => __( '该任务奖励已领取，无需重复操作。', 'qilingshop' ),
        'not_completed'    => __( '任务尚未完成，暂不可领取。', 'qilingshop' ),
        'disabled'         => __( '该任务未启用。', 'qilingshop' ),
        'reward_not_set'   => __( '该任务奖励未配置。', 'qilingshop' ),
        'invalid_nonce'    => __( '请求已过期，请刷新页面后重试。', 'qilingshop' ),
        'not_logged_in'    => __( '请先登录后再领取任务奖励。', 'qilingshop' ),
        'invalid_task'     => __( '任务不存在或暂不可领取。', 'qilingshop' ),
        'add_points_failed'=> __( '发放奖励失败，请稍后重试。', 'qilingshop' ),
    );
    if ( isset( $claim_messages[ $claim_code ] ) ) {
        $claim_notice = $claim_messages[ $claim_code ];
    } elseif ( $claim_result === 'success' ) {
        $claim_notice = __( '操作成功。', 'qilingshop' );
    } else {
        $claim_notice = __( '操作失败，请稍后重试。', 'qilingshop' );
    }
}
?>

<div class="qls-shop-wrapper qls-task-center-page">
    <div class="qls-container">
        <div class="qls-task-center">
            <?php do_action( 'qilingshop_task_center_before', $user_id, $tasks ); ?>

    <nav class="qls-breadcrumb qls-task-breadcrumb" aria-label="<?php esc_attr_e( '面包屑', 'qilingshop' ); ?>">
        <a href="<?php echo esc_url( $shop_url ); ?>"><?php esc_html_e( '商城', 'qilingshop' ); ?></a>
        <span class="sep">›</span>
        <?php if ( ! empty( $shop_center_url ) ) : ?>
            <a href="<?php echo esc_url( $shop_center_url ); ?>"><?php esc_html_e( '个人中心', 'qilingshop' ); ?></a>
            <span class="sep">›</span>
        <?php endif; ?>
        <span class="current"><?php esc_html_e( '任务中心', 'qilingshop' ); ?></span>
    </nav>

    <header class="qls-task-center-header">
        <div class="qls-task-center-title">
            <span class="qls-task-center-eyebrow"><?php esc_html_e( '营销任务', 'qilingshop' ); ?></span>
            <h1><?php esc_html_e( '任务中心', 'qilingshop' ); ?></h1>
            <p><?php esc_html_e( '完成任务即可领取奖励，更多任务将陆续上线。', 'qilingshop' ); ?></p>
            <div class="qls-task-center-tags">
                <span><?php esc_html_e( '限时福利', 'qilingshop' ); ?></span>
                <span><?php esc_html_e( '奖励可叠加', 'qilingshop' ); ?></span>
                <span><?php esc_html_e( '进度实时更新', 'qilingshop' ); ?></span>
            </div>
        </div>
        <?php if ( ! $user_id ) : ?>
            <a class="qls-task-center-login" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">
                <?php esc_html_e( '登录查看任务', 'qilingshop' ); ?>
            </a>
        <?php endif; ?>
    </header>

    <?php if ( $claim_notice !== '' ) : ?>
        <div class="qls-task-claim-notice <?php echo $claim_result === 'success' ? 'is-success' : 'is-error'; ?>">
            <?php echo esc_html( $claim_notice ); ?>
        </div>
    <?php endif; ?>

    <section class="qls-task-overview">
        <div class="qls-task-overview-card">
            <span><?php esc_html_e( '完成进度', 'qilingshop' ); ?></span>
            <strong><?php echo (int) $completion_rate; ?>%</strong>
        </div>
        <div class="qls-task-overview-card">
            <span><?php esc_html_e( '待完成', 'qilingshop' ); ?></span>
            <strong><?php echo (int) $pending_count; ?></strong>
        </div>
        <div class="qls-task-overview-card">
            <span><?php esc_html_e( '已完成', 'qilingshop' ); ?></span>
            <strong><?php echo (int) $done_count; ?></strong>
        </div>
        <div class="qls-task-overview-card">
            <span><?php esc_html_e( '待解锁', 'qilingshop' ); ?></span>
            <strong><?php echo (int) $locked_count; ?></strong>
        </div>
    </section>

    <?php if ( ! empty( $reward_preview ) ) : ?>
        <section class="qls-task-reward-strip">
            <strong><?php esc_html_e( '本期奖励', 'qilingshop' ); ?></strong>
            <div class="qls-task-reward-list">
                <?php foreach ( $reward_preview as $reward_text ) : ?>
                    <span><?php echo esc_html( $reward_text ); ?></span>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <div class="qls-task-list">
        <?php if ( empty( $tasks ) ) : ?>
            <div class="qls-task-empty"><?php esc_html_e( '暂无任务', 'qilingshop' ); ?></div>
        <?php else : ?>
            <?php foreach ( $tasks as $task ) : ?>
                <?php
                $status = $task['status'] ?? 'inactive';
                $status_text = $task['status_text'] ?? '';
                $action_label = $task['action_label'] ?? '';
                $action_url = $task['action_url'] ?? '';
                ?>
                <div class="qls-task-card status-<?php echo esc_attr( $status ); ?>">
                    <div class="qls-task-head">
                        <div class="qls-task-title">
                            <h3><?php echo esc_html( $task['title'] ?? '' ); ?></h3>
                            <?php if ( $status_text ) : ?>
                                <span class="qls-task-badge"><?php echo esc_html( $status_text ); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="qls-task-reward">
                            <span><?php esc_html_e( '奖励', 'qilingshop' ); ?></span>
                            <strong><?php echo esc_html( $task['reward'] ?? '' ); ?></strong>
                        </div>
                    </div>
                    <div class="qls-task-body">
                        <p><?php echo esc_html( $task['desc'] ?? '' ); ?></p>
                    </div>
                    <?php if ( $action_label && $action_url ) : ?>
                        <div class="qls-task-action">
                            <a class="qls-task-btn" href="<?php echo esc_url( $action_url ); ?>">
                                <?php echo esc_html( $action_label ); ?>
                            </a>
                        </div>
                    <?php else : ?>
                        <div class="qls-task-action qls-task-action-muted">
                            <span><?php esc_html_e( '当前状态无需操作', 'qilingshop' ); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
            <?php do_action( 'qilingshop_task_center_after', $user_id, $tasks ); ?>
        </div>
    </div>
</div>
