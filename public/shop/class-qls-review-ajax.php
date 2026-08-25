<?php
/**
 * 商品评价 AJAX 处理器
 * 
 * 处理评价提交、获取、点赞等AJAX请求
 *
 * @package QilingShop
 * @since   2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_Review_Ajax {
    
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
        // 提交评价（需登录）
        add_action('wp_ajax_qls_submit_review', [$this, 'submit_review']);
        
        // 获取评价列表（公开）
        add_action('wp_ajax_qls_get_reviews', [$this, 'get_reviews']);
        add_action('wp_ajax_nopriv_qls_get_reviews', [$this, 'get_reviews']);
        
        // 点赞评价（需登录）
        add_action('wp_ajax_qls_like_review', [$this, 'like_review']);
        
        // 上传评价图片（需登录）
        add_action('wp_ajax_qls_upload_review_image', [$this, 'upload_review_image']);
        
        // 检查是否可评价（需登录）
        add_action('wp_ajax_qls_check_can_review', [$this, 'check_can_review']);
        
        // 获取订单项评价状态（需登录）
        add_action('wp_ajax_qls_get_order_review_status', [$this, 'get_order_review_status']);
        
        // 获取用户对某订单的评价详情（需登录）
        add_action('wp_ajax_qls_get_user_order_reviews', [$this, 'get_user_order_reviews']);
    }

    /**
     * 验证Nonce
     */
    private function verify_nonce() {
        $nonce = isset($_REQUEST['nonce']) ? $_REQUEST['nonce'] : '';
        if (!wp_verify_nonce($nonce, 'qls_shop_nonce')) {
            wp_send_json_error(['message' => __('安全验证失败', 'qilingshop')]);
        }
    }

    /**
     * 公共读取接口防护（nonce + 频率限制）
     *
     * @param string $scope 限流作用域
     * @param int    $max 每时间窗最大请求数
     * @param int    $interval 时间窗（秒）
     * @return void
     */
    private function guard_public_read_request($scope, $max = 90, $interval = 60) {
        $this->verify_nonce();

        if (!function_exists('qilingshop_security')) {
            return;
        }

        $ip = qilingshop_security()->get_client_ip();
        $rate_key = 'qls_review_public_' . sanitize_key((string) $scope) . '_' . md5($ip);
        $allowed = qilingshop_security()->rate_limit($rate_key, (int) $max, (int) $interval);
        if (!$allowed) {
            wp_send_json_error(['message' => __('请求过于频繁，请稍后重试', 'qilingshop')], 429);
        }
    }

    /**
     * 登录写接口限流，避免评价图片接口被用来刷媒体库。
     *
     * @param string $scope    限流作用域
     * @param int    $max      时间窗内最大次数
     * @param int    $interval 时间窗（秒）
     * @return void
     */
    private function guard_logged_write_request($scope, $max = 20, $interval = 300) {
        if (!function_exists('qilingshop_security')) {
            return;
        }

        $user_id = get_current_user_id();
        $ip = qilingshop_security()->get_client_ip();
        $scope = sanitize_key((string) $scope);
        $keys = [
            'qls_review_write_u_' . $scope . '_' . md5((string) $user_id),
            'qls_review_write_ip_' . $scope . '_' . md5((string) $ip),
        ];

        foreach ($keys as $rate_key) {
            if (!qilingshop_security()->rate_limit($rate_key, (int) $max, (int) $interval)) {
                wp_send_json_error(['message' => __('请求过于频繁，请稍后重试', 'qilingshop')], 429);
            }
        }
    }

    /**
     * 获取评价图片上限。
     *
     * @return int
     */
    private function get_review_image_max() {
        return max(0, min(20, (int) get_option('qls_shop_review_image_max', 9)));
    }

    /**
     * 校验评价图片上传所属订单项。
     *
     * @param int $order_item_id 订单项 ID。
     * @param int $user_id       用户 ID。
     * @return object|WP_Error
     */
    private function validate_review_image_upload_item($order_item_id, $user_id) {
        global $wpdb;

        $order_item_id = absint($order_item_id);
        $user_id = absint($user_id);
        if (!$order_item_id || !$user_id) {
            return new WP_Error('invalid_order_item', __('请先选择要评价的商品', 'qilingshop'));
        }

        $can_review = qls_review()->can_review($order_item_id, $user_id);
        if (is_wp_error($can_review)) {
            return $can_review;
        }

        $items_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'order_items';
        $orders_table = $wpdb->prefix . QLS_SHOP_TABLE_PREFIX . 'orders';

        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT i.id AS order_item_id, i.order_id, i.product_id, i.sku_id, o.user_id AS order_user_id
             FROM {$items_table} i
             INNER JOIN {$orders_table} o ON o.id = i.order_id
             WHERE i.id = %d
             LIMIT 1",
            $order_item_id
        ));

        if (!$item) {
            return new WP_Error('item_not_found', __('订单项不存在', 'qilingshop'));
        }

        return $item;
    }

    /**
     * 统计一个订单已上传的评价图片数量。
     *
     * @param int $order_id 订单 ID。
     * @param int $user_id  用户 ID。
     * @return int
     */
    private function get_review_order_image_upload_count($order_id, $user_id) {
        global $wpdb;

        $order_id = absint($order_id);
        $user_id = absint($user_id);
        if (!$order_id || !$user_id) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} order_meta
                ON order_meta.post_id = p.ID
               AND order_meta.meta_key = '_qls_review_order_id'
               AND order_meta.meta_value = %d
             INNER JOIN {$wpdb->postmeta} user_meta
                ON user_meta.post_id = p.ID
               AND user_meta.meta_key = '_qls_review_user_id'
               AND user_meta.meta_value = %d
             WHERE p.post_type = 'attachment'
               AND p.post_status <> 'trash'",
            $order_id,
            $user_id
        ));
    }

    /**
     * 只保留当前用户为当前订单项上传的评价图片。
     *
     * @param array $images        图片 URL。
     * @param int   $order_item_id 订单项 ID。
     * @param int   $user_id       用户 ID。
     * @return array
     */
    private function filter_review_images_for_order_item($images, $order_item_id, $user_id) {
        $order_item_id = absint($order_item_id);
        $user_id = absint($user_id);
        $max_images = $this->get_review_image_max();
        if (!$order_item_id || !$user_id || $max_images <= 0 || !is_array($images)) {
            return [];
        }

        $filtered = [];
        foreach ($images as $image_url) {
            $url = esc_url_raw((string) $image_url);
            if ($url === '') {
                continue;
            }

            $attachment_id = (int) attachment_url_to_postid($url);
            if ($attachment_id <= 0) {
                continue;
            }

            if ((int) get_post_meta($attachment_id, '_qls_review_order_item_id', true) !== $order_item_id) {
                continue;
            }

            if ((int) get_post_meta($attachment_id, '_qls_review_user_id', true) !== $user_id) {
                continue;
            }

            $attachment_url = wp_get_attachment_url($attachment_id);
            if ($attachment_url) {
                $filtered[] = esc_url_raw($attachment_url);
            }

            if (count($filtered) >= $max_images) {
                break;
            }
        }

        return array_values(array_unique($filtered));
    }

    /**
     * 提交评价
     */
    public function submit_review() {
        $this->verify_nonce();
        
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('请先登录', 'qilingshop')]);
        }
        
        // 检查评价功能是否启用
        if (!get_option('qls_shop_review_enabled', true)) {
            wp_send_json_error(['message' => __('评价功能已关闭', 'qilingshop')]);
        }
        
        $user_id = get_current_user_id();
        
        $data = [
            'order_id'      => isset($_POST['order_id']) ? intval($_POST['order_id']) : 0,
            'order_item_id' => isset($_POST['order_item_id']) ? intval($_POST['order_item_id']) : 0,
            'product_id'    => isset($_POST['product_id']) ? intval($_POST['product_id']) : 0,
            'sku_id'        => isset($_POST['sku_id']) ? intval($_POST['sku_id']) : 0,
            'user_id'       => $user_id,
            'rating'        => isset($_POST['rating']) ? intval($_POST['rating']) : 5,
            'content'       => isset($_POST['content']) ? sanitize_textarea_field($_POST['content']) : '',
            'images'        => isset($_POST['images']) ? array_map('esc_url_raw', (array) wp_unslash($_POST['images'])) : [],
            'sku_info'      => isset($_POST['sku_info']) ? sanitize_text_field($_POST['sku_info']) : '',
            'is_anonymous'  => isset($_POST['is_anonymous']) ? (bool)$_POST['is_anonymous'] : false,
        ];
        $data['images'] = $this->filter_review_images_for_order_item($data['images'], $data['order_item_id'], $user_id);
        
        $result = qls_review()->create($data);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        // 计算获得的积分
        $base_points = intval(get_option('qls_shop_review_points_reward', 10));
        $image_bonus = !empty($data['images']) ? intval(get_option('qls_shop_review_image_bonus', 5)) : 0;
        $total_points = $base_points + $image_bonus;
        
        $auto_approve = get_option('qls_shop_review_auto_approve', false);
        
        wp_send_json_success([
            'message' => $auto_approve 
                ? __('评价提交成功', 'qilingshop') 
                : __('评价提交成功，等待审核', 'qilingshop'),
            'review_id'    => $result,
            'points_earned'=> $auto_approve ? $total_points : 0,
        ]);
    }

    /**
     * 获取评价列表
     */
    public function get_reviews() {
        $this->guard_public_read_request('get_reviews');

        $product_id = isset($_REQUEST['product_id']) ? intval($_REQUEST['product_id']) : 0;
        
        if (!$product_id) {
            wp_send_json_error(['message' => __('商品编号无效', 'qilingshop')]);
        }
        
        $args = [
            'page'      => isset($_REQUEST['page']) ? intval($_REQUEST['page']) : 1,
            'per_page'  => isset($_REQUEST['per_page']) ? intval($_REQUEST['per_page']) : 10,
            'rating'    => isset($_REQUEST['rating']) ? sanitize_text_field($_REQUEST['rating']) : null,
            'has_image' => isset($_REQUEST['has_image']) && $_REQUEST['has_image'] === 'true',
        ];
        
        $result = qls_review()->get_by_product($product_id, $args);
        
        // 获取统计信息
        $stats = qls_review()->get_stats($product_id);
        
        wp_send_json_success([
            'reviews' => $result['items'],
            'total'   => $result['total'],
            'page'    => $result['page'],
            'per_page'=> $result['per_page'],
            'total_pages' => $result['total_pages'],
            'stats'   => $stats,
        ]);
    }

    /**
     * 点赞评价
     */
    public function like_review() {
        $this->verify_nonce();
        
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('请先登录', 'qilingshop')]);
        }
        
        $review_id = isset($_POST['review_id']) ? intval($_POST['review_id']) : 0;
        
        if (!$review_id) {
            wp_send_json_error(['message' => __('评价ID无效', 'qilingshop')]);
        }
        
        $user_id = get_current_user_id();
        $result = qls_review()->like($review_id, $user_id);
        
        if ($result) {
            $review = qls_review()->get($review_id);
            wp_send_json_success([
                'message'    => __('点赞成功', 'qilingshop'),
                'like_count' => $review ? $review->like_count : 0,
            ]);
        } else {
            wp_send_json_error(['message' => __('您已点赞过', 'qilingshop')]);
        }
    }

    /**
     * 上传评价图片
     */
    public function upload_review_image() {
        $this->verify_nonce();
        
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('请先登录', 'qilingshop')]);
        }

        $this->guard_logged_write_request('upload_image', 20, 300);

        $user_id = get_current_user_id();
        $order_item_id = isset($_POST['order_item_id']) ? absint($_POST['order_item_id']) : 0;
        $item = $this->validate_review_image_upload_item($order_item_id, $user_id);
        if (is_wp_error($item)) {
            wp_send_json_error(['message' => $item->get_error_message()]);
        }

        $max_images = $this->get_review_image_max();
        if ($max_images <= 0) {
            wp_send_json_error(['message' => __('评价图片上传已关闭', 'qilingshop')]);
        }

        $uploaded_count = $this->get_review_order_image_upload_count((int) $item->order_id, $user_id);
        if ($uploaded_count >= $max_images) {
            wp_send_json_error(['message' => sprintf(__('每个订单最多上传%d张评价图片', 'qilingshop'), $max_images)]);
        }
        
        if (empty($_FILES['image'])) {
            wp_send_json_error(['message' => __('请选择图片', 'qilingshop')]);
        }
        
        $file = $_FILES['image'];
        if (!empty($file['error'])) {
            wp_send_json_error(['message' => __('图片上传失败，请重试', 'qilingshop')]);
        }

        $file_size = isset($file['size']) ? (int) $file['size'] : 0;
        if ($file_size <= 0) {
            wp_send_json_error(['message' => __('无效的图片文件', 'qilingshop')]);
        }
        
        // 验证文件大小 (最大 2MB)
        $max_size = 2 * 1024 * 1024;
        if ($file_size > $max_size) {
            wp_send_json_error(['message' => __('图片大小不能超过 2MB', 'qilingshop')]);
        }
        
        // 使用真实文件类型检测，防止伪装的图片格式
        $allowed_types = ['image/jpeg', 'image/png'];
        $allowed_extensions = ['jpg', 'jpeg', 'png'];
        
        // 检测真实MIME类型（优先使用fileinfo扩展，缺失时降级）
        $real_mime = '';
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $real_mime = $finfo->file($file['tmp_name']);
        } elseif (function_exists('mime_content_type')) {
            $real_mime = mime_content_type($file['tmp_name']);
        }
        
        if ($real_mime && !in_array($real_mime, $allowed_types, true)) {
            wp_send_json_error(['message' => __('只支持 JPG、PNG 格式图片', 'qilingshop')]);
        }
        
        // 验证文件扩展名
        $file_name = isset($file['name']) && is_scalar($file['name']) ? sanitize_file_name((string) $file['name']) : '';
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_extensions, true)) {
            wp_send_json_error(['message' => __('文件扩展名不合法', 'qilingshop')]);
        }
        
        // 验证图片是否可以正常解析（防止伪装的非图片文件）
        $image_info = @getimagesize($file['tmp_name']);
        if ($image_info === false) {
            wp_send_json_error(['message' => __('无效的图片文件', 'qilingshop')]);
        }
        
        // 再次确认图片类型与声明一致
        if (!in_array($image_info['mime'], $allowed_types, true)) {
            wp_send_json_error(['message' => __('图片格式不匹配', 'qilingshop')]);
        }

        $file_type = wp_check_filetype_and_ext($file['tmp_name'], $file_name, [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
        ]);
        if (empty($file_type['ext']) || empty($file_type['type']) || !in_array($file_type['type'], $allowed_types, true)) {
            wp_send_json_error(['message' => __('图片格式不匹配', 'qilingshop')]);
        }
        
        // 使用 WordPress 媒体上传
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        
        $attachment_id = media_handle_upload('image', 0);
        
        if (is_wp_error($attachment_id)) {
            wp_send_json_error(['message' => $attachment_id->get_error_message()]);
        }

        update_post_meta($attachment_id, '_qls_review_order_id', (int) $item->order_id);
        update_post_meta($attachment_id, '_qls_review_order_item_id', $order_item_id);
        update_post_meta($attachment_id, '_qls_review_user_id', $user_id);
        update_post_meta($attachment_id, '_qls_review_uploaded_at', current_time('mysql'));

        if ($this->get_review_order_image_upload_count((int) $item->order_id, $user_id) > $max_images) {
            wp_delete_attachment($attachment_id, true);
            wp_send_json_error(['message' => sprintf(__('每个订单最多上传%d张评价图片', 'qilingshop'), $max_images)]);
        }

        $url = wp_get_attachment_url($attachment_id);
        
        wp_send_json_success([
            'url'           => $url,
            'attachment_id' => (int) $attachment_id,
        ]);
    }

    /**
     * 检查是否可评价
     */
    public function check_can_review() {
        $this->verify_nonce();
        
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('请先登录', 'qilingshop')]);
        }
        
        $order_item_id = isset($_REQUEST['order_item_id']) ? intval($_REQUEST['order_item_id']) : 0;
        
        if (!$order_item_id) {
            wp_send_json_error(['message' => __('订单项ID无效', 'qilingshop')]);
        }
        
        $user_id = get_current_user_id();
        $result = qls_review()->can_review($order_item_id, $user_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        wp_send_json_success(['can_review' => true]);
    }

    /**
     * 获取订单的评价状态
     */
    public function get_order_review_status() {
        $this->verify_nonce();
        
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('请先登录', 'qilingshop')]);
        }
        
        $order_id = isset($_REQUEST['order_id']) ? intval($_REQUEST['order_id']) : 0;
        
        if (!$order_id) {
            wp_send_json_error(['message' => __('订单ID无效', 'qilingshop')]);
        }
        
        global $wpdb;
        $prefix = $wpdb->prefix . 'qls_shop_';
        
        // 验证订单归属
        $order_owner = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$prefix}orders WHERE id = %d LIMIT 1",
            $order_id
        ));

        if (!$order_owner || intval($order_owner) !== get_current_user_id()) {
            wp_send_json_error(['message' => __('订单不存在', 'qilingshop')]);
        }

        // 获取订单项的评价状态
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT id, product_id, sku_id, product_title, sku_attrs, image, is_reviewed 
             FROM {$prefix}order_items 
             WHERE order_id = %d",
            $order_id
        ));
        
        $user_id = get_current_user_id();
        $status = [];
        
        foreach ($items as $item) {
            $can_review = qls_review()->can_review($item->id, $user_id);
            
            // 获取商品图片
            $image = '';
            if (!empty($item->image)) {
                $img_data = json_decode($item->image, true);
                if (is_array($img_data) && !empty($img_data['url'])) {
                    $image = $img_data['url'];
                } elseif (is_string($item->image) && filter_var($item->image, FILTER_VALIDATE_URL)) {
                    $image = $item->image;
                }
            }
            // 如果订单项没有图片，从商品获取
            if (empty($image) && !empty($item->product_id)) {
                $product = qls_product()->get($item->product_id);
                if ($product && !empty($product->main_image)) {
                    $img_data = is_string($product->main_image) ? json_decode($product->main_image, true) : $product->main_image;
                    if (is_array($img_data) && !empty($img_data['url'])) {
                        $image = $img_data['url'];
                    } elseif (is_string($product->main_image)) {
                        $image = $product->main_image;
                    }
                }
            }
            
            $status[] = [
                'order_item_id' => $item->id,
                'product_id'    => $item->product_id,
                'sku_id'        => $item->sku_id,
                'product_title' => $item->product_title,
                'sku_attrs'     => $item->sku_attrs ? json_decode($item->sku_attrs, true) : [],
                'image'         => $image,
                'is_reviewed'   => (bool)$item->is_reviewed,
                'can_review'    => !is_wp_error($can_review),
                'reason'        => is_wp_error($can_review) ? $can_review->get_error_message() : '',
            ];
        }
        
        wp_send_json_success(['items' => $status]);
    }

    /**
     * 获取用户对某订单的评价详情
     */
    public function get_user_order_reviews() {
        $this->verify_nonce();
        
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('请先登录', 'qilingshop')]);
        }
        
        $order_id = isset($_REQUEST['order_id']) ? intval($_REQUEST['order_id']) : 0;
        
        if (!$order_id) {
            wp_send_json_error(['message' => __('订单ID无效', 'qilingshop')]);
        }
        
        global $wpdb;
        $prefix = $wpdb->prefix . 'qls_shop_';
        $user_id = get_current_user_id();
        
        // 验证订单属于当前用户
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$prefix}orders WHERE id = %d AND user_id = %d",
            $order_id, $user_id
        ));
        
        if (!$order) {
            wp_send_json_error(['message' => __('订单不存在', 'qilingshop')]);
        }
        
        // 获取该订单的所有评价
        $reviews = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, oi.product_title, oi.image as item_image
             FROM {$prefix}reviews r
             LEFT JOIN {$prefix}order_items oi ON r.order_item_id = oi.id
             WHERE r.order_id = %d AND r.user_id = %d
             ORDER BY r.created_at DESC",
            $order_id, $user_id
        ));
        
        $result = [];
        foreach ($reviews as $review) {
            // 解析图片
            $images = [];
            if (!empty($review->images)) {
                $images = json_decode($review->images, true) ?: [];
            }
            
            // 检查评价是否被删除（status = 2 表示隐藏）
            $is_hidden = ($review->status == 2);
            
            $result[] = [
                'id'            => $review->id,
                'product_title' => $review->product_title,
                'rating'        => $review->rating,
                'content'       => $review->content,
                'images'        => $images,
                'created_at'    => date('Y-m-d H:i', strtotime($review->created_at)),
                'status'        => $review->status,
                'is_hidden'     => $is_hidden,
                'admin_reply'   => $review->admin_reply,
                'reply_time'    => $review->reply_time ? date('Y-m-d H:i', strtotime($review->reply_time)) : null,
            ];
        }
        
        wp_send_json_success(['reviews' => $result]);
    }
}

/**
 * 获取评价AJAX处理器实例
 * 
 * @return QLS_Review_Ajax
 */
function qls_review_ajax() {
    return QLS_Review_Ajax::instance();
}
