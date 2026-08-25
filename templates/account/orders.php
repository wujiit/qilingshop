<?php
/**
 * 个人中心 - 订单记录
 * 
 * 可用变量: $user_id, $current_user
 *
 * @package QilingShop
 */

if (!defined('ABSPATH')) exit;

$order = QilingShop_Order::instance();
$paged = isset($_GET['opaged']) ? max(1, intval($_GET['opaged'])) : 1;
$per_page = 10;
$order_type = isset($_GET['otype']) ? sanitize_key($_GET['otype']) : '';
$allowed_types = ['resource', 'vip'];
if (!in_array($order_type, $allowed_types, true)) {
    $order_type = '';
}

$orders = $order->get_user_orders($user_id, [
    'limit'  => $per_page,
    'offset' => ($paged - 1) * $per_page,
    'type'   => $order_type,
]);

$count_where = [];
if ($order_type !== '') {
    $count_where['order_type'] = $order_type;
}
$total = $order->get_user_orders_count($user_id, $count_where);
$total_pages = ceil($total / $per_page);
?>

<div class="qls-account-section qls-orders-section">
    <h3 class="qls-section-title"><?php _e('积分订单', 'qilingshop'); ?></h3>

    <div class="qls-filter-bar">
        <?php
        $base_url = add_query_arg('tab', 'qls-orders', get_permalink());
        $filters = [
            '' => __('全部', 'qilingshop'),
            'resource' => __('资源订单', 'qilingshop'),
            'vip' => __('VIP订单', 'qilingshop'),
        ];
        foreach ($filters as $key => $label):
            $url = $key === '' ? $base_url : add_query_arg('otype', $key, $base_url);
            $active = ($order_type === $key) ? 'active' : '';
        ?>
        <a href="<?php echo esc_url($url); ?>" class="qls-filter-pill <?php echo esc_attr($active); ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
    </div>
    
    <?php if (!empty($orders)): ?>
    <div class="qls-orders-table-wrapper">
        <table class="qls-orders-table">
            <thead>
                <tr>
                    <th><?php _e('订单号', 'qilingshop'); ?></th>
                    <th><?php _e('类型', 'qilingshop'); ?></th>
                    <th><?php _e('商品', 'qilingshop'); ?></th>
                    <th><?php _e('授权', 'qilingshop'); ?></th>
                    <th><?php _e('金额', 'qilingshop'); ?></th>
                    <th><?php _e('支付方式', 'qilingshop'); ?></th>
                    <th><?php _e('状态', 'qilingshop'); ?></th>
                    <th><?php _e('时间', 'qilingshop'); ?></th>
                    <th><?php _e('操作', 'qilingshop'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $o): ?>
                <tr>
                    <td class="qls-order-no" data-label="<?php esc_attr_e('订单号', 'qilingshop'); ?>">
                        <code><?php echo esc_html($o->order_no); ?></code>
                        <?php if (!empty($o->payment_no)): ?>
                            <div class="qls-order-sub"><?php echo esc_html($o->payment_no); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="qls-order-type" data-label="<?php esc_attr_e('类型', 'qilingshop'); ?>">
                        <?php
                        $type_labels = [
                            'resource' => __('资源', 'qilingshop'),
                            'vip'      => __('VIP', 'qilingshop'),
                        ];
                        $type_label = $type_labels[$o->order_type] ?? $o->order_type;
                        ?>
                        <span class="qls-order-type-tag qls-order-type-<?php echo esc_attr($o->order_type); ?>">
                            <?php echo esc_html($type_label); ?>
                        </span>
                    </td>
                    <td class="qls-order-title" data-label="<?php esc_attr_e('商品', 'qilingshop'); ?>">
                        <?php if ($o->post_id): ?>
                            <a href="<?php echo get_permalink($o->post_id); ?>" target="_blank">
                                <?php echo esc_html($o->post_title ?: get_the_title($o->post_id)); ?>
                            </a>
                        <?php else: ?>
                            <?php echo esc_html($o->post_title ?: '-'); ?>
                        <?php endif; ?>
                        <?php
                        if ($o->order_type === 'vip') {
                            $remark = !empty($o->remark) ? json_decode($o->remark, true) : [];
                            $duration = isset($remark['duration']) ? (int) $remark['duration'] : 0;
                            $upgrade_from = isset($remark['upgrade_from']) ? (int) $remark['upgrade_from'] : 0;
                            $duration_text = $duration >= 999999 ? __('永久', 'qilingshop') : sprintf(__('%d天', 'qilingshop'), $duration);
                            $upgrade_text = $upgrade_from > 0 ? __('补差价升级', 'qilingshop') : __('开通/续费', 'qilingshop');
                            echo '<div class="qls-order-sub">' . esc_html($upgrade_text . ' · ' . $duration_text) . '</div>';
                        }
                        ?>
                    </td>
                    <td class="qls-order-scope" data-label="<?php esc_attr_e('授权', 'qilingshop'); ?>">
                        <?php
                        if ($o->order_type === 'vip') {
                            $scope_label = __('会员开通', 'qilingshop');
                        } else {
                            $scope_label = QilingShop_Order::instance()->get_order_scope_label($o);
                        }
                        echo $scope_label !== '' ? esc_html($scope_label) : '-';
                        ?>
                    </td>
                    <td class="qls-order-amount" data-label="<?php esc_attr_e('金额', 'qilingshop'); ?>">
                        <?php
                        $points_name = qilingshop_get_points_name();
                        if ($o->price_rmb > 0) {
                            $final_price = ($o->final_price > 0) ? (float) $o->final_price : (float) $o->price_rmb;
                            echo '<div class="qls-amount-main">¥' . number_format($final_price, 2) . '</div>';
                            if ($o->discount_amount > 0) {
                                echo '<div class="qls-amount-sub">' . sprintf(__('原价 ¥%s', 'qilingshop'), number_format((float) $o->price_rmb, 2)) . '</div>';
                                echo '<div class="qls-amount-sub qls-amount-discount">-' . number_format((float) $o->discount_amount, 2) . '</div>';
                            }
                        } elseif ($o->price_points > 0) {
                            $final_points = ($o->final_price > 0) ? (float) $o->final_price : (float) $o->price_points;
                            echo '<div class="qls-amount-main">' . number_format($final_points, 0) . ' ' . esc_html($points_name) . '</div>';
                            if ($o->discount_amount > 0) {
                                echo '<div class="qls-amount-sub">' . sprintf(__('原价 %s %s', 'qilingshop'), number_format((float) $o->price_points, 0), esc_html($points_name)) . '</div>';
                                echo '<div class="qls-amount-sub qls-amount-discount">-' . number_format((float) $o->discount_amount, 0) . ' ' . esc_html($points_name) . '</div>';
                            }
                        } else {
                            echo '<div class="qls-amount-main">' . esc_html(__('免费', 'qilingshop')) . '</div>';
                        }
                        ?>
                    </td>
                    <td class="qls-order-method" data-label="<?php esc_attr_e('支付方式', 'qilingshop'); ?>">
                        <?php
                        $methods = [
                            'points' => __('积分', 'qilingshop'),
                            'alipay' => __('支付宝', 'qilingshop'),
                            'alipay_qr' => __('支付宝扫码', 'qilingshop'),
                            'alipay_page' => __('支付宝网页', 'qilingshop'),
                            'wechat' => __('微信', 'qilingshop'),
                            'xhpay' => __('虎皮椒 V3', 'qilingshop'),
                            'epay' => __('易支付', 'qilingshop'),
                            'paypal' => 'PayPal',
                            'stripe' => __('Stripe', 'qilingshop'),
                            'coupon' => __('优惠券', 'qilingshop'),
                            'code' => __('兑换码', 'qilingshop'),
                        ];
                        $method_label = $methods[$o->payment_method] ?? $o->payment_method;
                        if (empty($method_label) && $o->price_rmb <= 0 && $o->price_points <= 0) {
                            $method_label = __('免费', 'qilingshop');
                        }
                        echo esc_html($method_label ?: '-');
                        ?>
                    </td>
                    <td class="qls-order-status" data-label="<?php esc_attr_e('状态', 'qilingshop'); ?>">
                        <?php
                        $status_class = '';
                        $status_text = '';
                        switch ($o->status) {
                            case 0:
                                $status_class = 'pending';
                                $status_text = __('待支付', 'qilingshop');
                                break;
                            case 1:
                                $status_class = 'paid';
                                $status_text = __('已完成', 'qilingshop');
                                break;
                            case 2:
                                $status_class = 'cancelled';
                                $status_text = __('已取消', 'qilingshop');
                                break;
                            case 3:
                                $status_class = 'refunded';
                                $status_text = __('已退款', 'qilingshop');
                                break;
                        }
                        ?>
                        <span class="qls-status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                    </td>
                    <td class="qls-order-time" data-label="<?php esc_attr_e('时间', 'qilingshop'); ?>">
                        <?php echo date_i18n('Y-m-d H:i', strtotime($o->created_at)); ?>
                    </td>
                    <td class="qls-order-actions" data-label="<?php esc_attr_e('操作', 'qilingshop'); ?>">
                        <?php if ($o->status == 0 && $o->price_rmb > 0): ?>
                            <?php
                            // 生成支付链接（限制为受支持且已启用的网关）
                            $payment_manager = QilingShop_Payment::instance();
                            $original_method = sanitize_key((string) ($o->payment_method ?: 'alipay'));
                            if ($original_method === 'wechat_miniapp') {
                                $original_method = 'alipay';
                            }
                            $gateway = $payment_manager->get_gateway_entry_slug($original_method);
                            if ($gateway === '' || !$payment_manager->is_gateway_enabled($gateway)) {
                                $enabled_gateways = [];
                                foreach (array_keys($payment_manager->get_enabled_gateways()) as $enabled_gateway) {
                                    $enabled_gateway = sanitize_key((string) $enabled_gateway);
                                    if ($enabled_gateway !== 'wechat_miniapp') {
                                        $enabled_gateways[] = $enabled_gateway;
                                    }
                                }
                                $gateway = !empty($enabled_gateways) ? $payment_manager->get_gateway_entry_slug($enabled_gateways[0]) : '';
                            }
                            $alipay_method = '';
                            if ($original_method === 'alipay_qr') {
                                $alipay_method = 'f2f';
                            } elseif ($original_method === 'alipay_page') {
                                $alipay_method = 'page';
                            }

                            $fixed_title = get_option('qilingshop_fixed_order_title', '');
                            $subject = !empty($fixed_title) ? $fixed_title : get_bloginfo('name');
                            
                            // 使用优惠后的价格（如果有）
                            $pay_price = isset($o->final_price) && $o->final_price > 0 ? $o->final_price : $o->price_rmb;

                            $pay_url = '';
                            if ($gateway !== '') {
                                $pay_args = [
                                    'order'        => $o->order_no,
                                    'price'        => $pay_price,
                                    'subject'      => $subject,
                                    'redirect_url' => get_permalink($o->post_id) ?: home_url(),
                                ];
                                if ($gateway === 'alipay' && $alipay_method !== '') {
                                    $pay_args['method'] = $alipay_method;
                                }
                                $pay_url = qilingshop_get_payment_entry_url($gateway, $pay_args);
                            }
                            ?>
                            <?php if (!empty($pay_url)): ?>
                            <a href="<?php echo esc_url($pay_url); ?>" class="qls-btn qls-btn-primary qls-btn-sm"><?php _e('去支付', 'qilingshop'); ?></a>
                            <?php else: ?>
                            <span class="qls-text-muted"><?php _e('支付方式不可用', 'qilingshop'); ?></span>
                            <?php endif; ?>
                        <?php elseif ($o->status == 1 && $o->post_id): ?>
                            <a href="<?php echo get_permalink($o->post_id); ?>" class="qls-btn qls-btn-sm"><?php _e('查看', 'qilingshop'); ?></a>
                        <?php else: ?>
                            <span class="qls-text-muted">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <?php if ($total_pages > 1): ?>
    <div class="qls-pagination">
        <?php
        $base_url = add_query_arg('tab', 'qls-orders', get_permalink());
        if ($order_type !== '') {
            $base_url = add_query_arg('otype', $order_type, $base_url);
        }
        for ($i = 1; $i <= $total_pages; $i++):
            $url = add_query_arg('opaged', $i, $base_url);
        ?>
        <a href="<?php echo esc_url($url); ?>" class="qls-page-link <?php echo $i === $paged ? 'active' : ''; ?>">
            <?php echo $i; ?>
        </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    
    <?php else: ?>
    <div class="qls-empty-state">
        <div class="qls-empty-icon">📦</div>
        <p><?php _e('暂无订单记录', 'qilingshop'); ?></p>
    </div>
    <?php endif; ?>
</div>
