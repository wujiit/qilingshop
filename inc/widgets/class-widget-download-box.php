<?php
/**
 * 侧边栏下载框小工具
 *
 * @package QilingShop
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Widget_Download_Box extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'qilingshop_download_box',
            $this->get_widget_name(),
            ['description' => $this->get_widget_description()]
        );
    }

    private function get_widget_name() {
        return (did_action('init') || doing_action('init'))
            ? __('启灵商城 - 资源下载框', 'qilingshop')
            : '启灵商城 - 资源下载框';
    }

    private function get_widget_description() {
        return (did_action('init') || doing_action('init'))
            ? __('在侧边栏显示资源购买/下载框。只有当文章包含付费资源或下载链接时才会显示。', 'qilingshop')
            : '在侧边栏显示资源购买/下载框。只有当文章包含付费资源或下载链接时才会显示。';
    }

    /**
     * 前端输出
     */
    public function widget($args, $instance) {
        if (!is_singular()) {
            return;
        }

        global $post;
        if (!$post) return;
        if (!qilingshop_points_resource_enabled($post->ID)) {
            return;
        }

        $position = get_option('qilingshop_download_box_position', 'bottom');
        if (!in_array($position, ['sidebar', 'bottom_sidebar', 'top_sidebar'], true)) {
            return;
        }

        // 检查是否需要显示
        $resource = QilingShop_Resource::instance();
        $sale_mode = $resource->get_sale_mode($post->ID);
        $price_context = $sale_mode === 'view' ? 'view' : 'download';
        $has_price = $resource->get_points_price($post->ID, $price_context) > 0;
        $has_download = $resource->has_download_urls($post->ID);
        $is_view_mode = ($sale_mode === 'view');
        
        // 如果没有价格也没有下载链接，且不是付费查看模式，则不显示
        if (!$has_price && !$has_download && !$is_view_mode) {
            return;
        }

        echo $args['before_widget'];

        if (!empty($instance['title'])) {
            echo $args['before_title'] . apply_filters('widget_title', $instance['title']) . $args['after_title'];
        }

        // 调用公共类渲染方法，强制使用 sidebar 模式
        $public = QilingShop_Public::instance();
        $user_id = get_current_user_id();
        
        // 判断是否已购买
        $has_purchased = QilingShop_Order::instance()->user_has_purchased($post->ID, $user_id, false, 'download');
        $has_view_purchase = $user_id ? QilingShop_Order::instance()->user_has_purchased($post->ID, $user_id, false, 'view') : false;
        if ($user_id && $resource->is_vip_free($post->ID, $user_id, 'download')) {
            $has_purchased = true;
        }

        // 免费资源且未登录（如果设置了）
        $is_login_free = ($sale_mode !== 'free' && $resource->get_points_price($post->ID, $price_context) <= 0 && $user_id);
        if ($is_login_free) $has_purchased = true;
        if ($sale_mode === 'free') $has_purchased = true;

        // 访问限制：VIP仅限访问是最终约束，免费或已购资源也不能绕过。
        if ($resource->is_vip_only_access($post->ID, 'download') && (!$user_id || !$resource->has_vip_access($post->ID, $user_id, 'download'))) {
            $has_purchased = false;
        }

        if ($has_purchased) {
            // 已购买：显示下载框
            if ($has_download) {
                echo $public->render_download_box($post->ID, true, 'sidebar');
            }
        } else {
            if ($has_view_purchase && $has_download) {
                echo $public->render_buy_box($post->ID, 'sidebar', 'download');
                echo $args['after_widget'];
                return;
            }
            // 未购买：显示购买框
            $target_scope = $sale_mode === 'view' ? 'view' : 'download';
            echo $public->render_buy_box($post->ID, 'sidebar', $target_scope);
        }

        echo $args['after_widget'];
    }

    /**
     * 后台表单
     */
    public function form($instance) {
        $title = !empty($instance['title']) ? $instance['title'] : '';
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php _e('标题:', 'qilingshop'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($title); ?>">
        </p>
        <?php
    }

    /**
     * 保存设置
     */
    public function update($new_instance, $old_instance) {
        $instance = [];
        $instance['title'] = (!empty($new_instance['title'])) ? sanitize_text_field($new_instance['title']) : '';
        return $instance;
    }
}
