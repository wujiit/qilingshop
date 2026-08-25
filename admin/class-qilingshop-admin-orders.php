<?php
/**
 * 订单管理后台
 */
if (!defined('ABSPATH')) exit;

class QilingShop_Admin_Orders {
    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_qilingshop_order_action', [$this, 'handle_ajax']);
    }

    public function render() {
        $db = QilingShop_Database::instance();
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $status = isset($_GET['status']) ? intval($_GET['status']) : -1;

        // 处理删除
        if (isset($_GET['delete']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_order')) {
            $order_id = intval($_GET['delete']);
            $deleted = QilingShop_Order::instance()->delete_pending($order_id);
            if ($deleted) {
                echo '<div class="notice notice-success"><p>' . __('订单已删除', 'qilingshop') . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . __('仅支持删除待支付订单，或订单正在处理中。', 'qilingshop') . '</p></div>';
            }
        }

        // 批量删除待支付订单
        if (isset($_POST['clear_pending']) && wp_verify_nonce($_POST['_wpnonce'], 'clear_pending_orders')) {
            $order_rows = $db->get_results('orders', [
                'where'   => ['status' => QilingShop_Order::STATUS_PENDING],
                'orderby' => 'id',
                'order'   => 'ASC',
                'limit'   => 500,
            ]);
            $recharge_rows = $db->get_results('recharge', [
                'where'   => ['status' => QilingShop_Recharge::STATUS_PENDING],
                'orderby' => 'id',
                'order'   => 'ASC',
                'limit'   => 500,
            ]);

            $deleted_orders = 0;
            foreach ($order_rows as $row) {
                if (QilingShop_Order::instance()->delete_pending((int) $row->id)) {
                    $deleted_orders++;
                }
            }

            $deleted_recharges = 0;
            foreach ($recharge_rows as $row) {
                if (QilingShop_Recharge::instance()->delete_pending((int) $row->id)) {
                    $deleted_recharges++;
                }
            }

            echo '<div class="notice notice-success"><p>' . sprintf(
                __('待支付订单已清理：资源订单 %d 笔，充值订单 %d 笔。', 'qilingshop'),
                $deleted_orders,
                $deleted_recharges
            ) . '</p></div>';
        }

        $where = [];
        if ($status >= 0) $where['status'] = $status;

        $total = $db->count('orders', $where);
        $orders = $db->get_results('orders', [
            'where'   => $where,
            'orderby' => 'id',
            'order'   => 'DESC',
            'limit'   => $per_page,
            'offset'  => ($paged - 1) * $per_page,
        ]);
        ?>
        <div class="wrap qilingshop-admin-page qilingshop-vr-page qilingshop-orders-page">
            <h1><?php _e('订单管理', 'qilingshop'); ?></h1>
            
            <div class="qls-admin-orders-toolbar">
                <ul class="subsubsub">
                    <li><a href="?page=qilingshop-orders" <?php echo $status < 0 ? 'class="current"' : ''; ?>><?php _e('全部', 'qilingshop'); ?></a> |</li>
                    <li><a href="?page=qilingshop-orders&status=0" <?php echo $status === 0 ? 'class="current"' : ''; ?>><?php _e('待支付', 'qilingshop'); ?></a> |</li>
                    <li><a href="?page=qilingshop-orders&status=1" <?php echo $status === 1 ? 'class="current"' : ''; ?>><?php _e('已完成', 'qilingshop'); ?></a></li>
                </ul>

                <form method="post" class="qls-admin-orders-actions-form">
                    <?php wp_nonce_field('clear_pending_orders'); ?>
                    <button type="submit" name="clear_pending" class="button" onclick="return confirm('<?php _e('确定删除所有待支付订单？', 'qilingshop'); ?>');"><?php _e('清理待支付订单', 'qilingshop'); ?></button>
                </form>
            </div>
            
            <table class="wp-list-table qls-ui-table widefat fixed striped qls-admin-orders-table">
                <thead>
                    <tr>
                        <th><?php _e('订单号', 'qilingshop'); ?></th>
                        <th><?php _e('用户', 'qilingshop'); ?></th>
                        <th><?php _e('资源', 'qilingshop'); ?></th>
                        <th><?php _e('授权', 'qilingshop'); ?></th>
                        <th><?php _e('金额', 'qilingshop'); ?></th>
                        <th><?php _e('支付方式', 'qilingshop'); ?></th>
                        <th><?php _e('状态', 'qilingshop'); ?></th>
                        <th><?php _e('时间', 'qilingshop'); ?></th>
                        <th><?php _e('操作', 'qilingshop'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): 
                        $user = $order->user_id ? get_user_by('ID', $order->user_id) : null;
                        $growth_highlight = ($order->user_id && class_exists('QilingShop_Growth_Benefits')) ? QilingShop_Growth_Benefits::instance()->get_order_highlight((int) $order->user_id) : null;
                    ?>
                    <tr<?php echo $growth_highlight ? ' style="background:' . esc_attr($growth_highlight['color']) . '"' : ''; ?>>
                        <td><?php echo esc_html($order->order_no); ?><?php if ($growth_highlight): ?> <span class="qls-growth-order-highlight"><?php echo esc_html($growth_highlight['label']); ?></span><?php endif; ?></td>
                        <td><?php echo $user ? esc_html($user->user_login) : ($order->guest_id ? __('游客', 'qilingshop') : '-'); ?></td>
                        <td><a href="<?php echo get_permalink($order->post_id); ?>"><?php echo esc_html($order->post_title ?: $order->post_id); ?></a></td>
                        <td>
                            <?php
                            $scope_label = QilingShop_Order::instance()->get_order_scope_label($order);
                            echo $scope_label !== '' ? esc_html($scope_label) : '-';
                            ?>
                        </td>
                        <td><?php echo $order->price_points > 0 ? qilingshop_format_points($order->price_points) : qilingshop_format_price($order->price_rmb); ?></td>
                        <td><?php echo esc_html($order->payment_method ?: '-'); ?></td>
                        <td><?php echo QilingShop_Order::instance()->get_status_text($order->status); ?></td>
                        <td><?php echo esc_html($order->paid_at ?: $order->created_at); ?></td>
                        <td>
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=qilingshop-orders&delete=' . $order->id), 'delete_order'); ?>" class="qls-admin-link-danger" onclick="return confirm('<?php _e('确定删除此订单？', 'qilingshop'); ?>');"><?php _e('删除', 'qilingshop'); ?></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
            $total_pages = ceil($total / $per_page);
            if ($total_pages > 1) {
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo paginate_links(['base' => add_query_arg('paged', '%#%'), 'current' => $paged, 'total' => $total_pages]);
                echo '</div></div>';
            }
            ?>
        </div>
        <?php
    }

    public function handle_ajax() {
        check_ajax_referer('qilingshop_admin', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error();
        wp_send_json_success();
    }
}

