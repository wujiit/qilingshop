<?php
/**
 * 独立下载页面模板
 * 
 * 用户购买资源后点击下载跳转到此页面
 * 显示下载链接、提取码、隐藏内容和下载提示
 *
 * @package QilingShop
 */

if (!defined('ABSPATH')) exit;
// 获取文章ID
$post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;

if (!$post_id || !get_post($post_id)) {
    wp_die(__('资源不存在', 'qilingshop'));
}

if (!function_exists('qilingshop_points_resource_enabled') || !qilingshop_points_resource_enabled($post_id)) {
    wp_die(__('资源下载未启用', 'qilingshop'));
}

// 验证用户权限
$user_id = get_current_user_id();
$resource = QilingShop_Resource::instance();
$order = QilingShop_Order::instance();
$context = 'download';

// 检查是否已购买或VIP免费
if ($resource->is_vip_only_access($post_id, $context) && (!$user_id || !$resource->has_vip_access($post_id, $user_id, $context))) {
    wp_die(__('该资源仅限 VIP 下载', 'qilingshop'));
}
$has_purchased = $order->user_has_purchased($post_id, $user_id, false, 'download');
if ($user_id && $resource->is_vip_free($post_id, $user_id, $context)) {
    $has_purchased = true;
}

$sale_mode = $resource->get_sale_mode($post_id);
$price = $resource->get_points_price($post_id, 'download');
$price_rmb = $resource->get_rmb_price($post_id, 'download');

// 检查是否为免费资源
if ($sale_mode === 'free') {
    $has_purchased = true;
} elseif ($price <= 0 && $price_rmb <= 0 && $user_id) {
    $has_purchased = true;
}

// 游客检查
if (!$user_id && !($sale_mode !== 'free' && $price <= 0 && $price_rmb <= 0) && QilingShop_Guest::instance()->is_enabled()) {
    $guest_id = QilingShop_Guest::instance()->get_guest_id();
    $has_purchased = QilingShop_Order::instance()->guest_has_purchased($post_id, $guest_id, 'download');
}

$require_login_only = (!$has_purchased && !$user_id && $sale_mode !== 'free' && $price <= 0 && $price_rmb <= 0);
if (get_option('qilingshop_resource_price_login_required', false) && !$has_purchased && !$user_id && $sale_mode !== 'free') {
    $require_login_only = true;
}

if (!$has_purchased && !$require_login_only) {
    wp_safe_redirect(get_permalink($post_id));
    exit;
}

// 获取数据
// 获取数据
global $post, $wp_query;
$post = get_post($post_id);
$urls = $resource->get_download_urls($post_id);
$is_paid = !($sale_mode === 'free' || ($price <= 0 && $price_rmb <= 0));
// 如果销售模式是免费资源，强制视为非付费（使用免费等待时间）
if ($resource->get_sale_mode($post_id) === 'free') {
    $is_paid = false;
}
$is_vip_free = $user_id && $resource->is_vip_free($post_id, $user_id, $context);
if ($is_vip_free) {
    $is_paid = false;
}
$hidden_content = $resource->get_hidden_content($post_id);
$tips_download = get_option('qilingshop_tips_download', '');
$security = qilingshop_security();

// 修复：强制设置全局查询标志，避免主题误判为首页导致菜单和布局异常
$wp_query->is_home = false;
$wp_query->is_front_page = false;
$wp_query->is_page = true;
$wp_query->is_singular = true;
$wp_query->is_archive = false;
$wp_query->queried_object = $post;
$wp_query->queried_object_id = $post_id;
$wp_query->posts = array($post);
$wp_query->post_count = 1;
$wp_query->found_posts = 1;

setup_postdata($post);

get_header();
?>

<div class="qilingshop-download-page">
    <div class="download-page-container">
        <div class="download-page-header">
            <h1 class="download-page-title"><?php echo esc_html($post->post_title); ?></h1>
            <a href="<?php echo get_permalink($post_id); ?>" class="back-to-post">&larr; <?php _e('返回文章', 'qilingshop'); ?></a>
        </div>
        
        <?php if ($require_login_only): ?>
            <div class="download-login-only">
                <p class="download-login-text"><?php _e('请先登录', 'qilingshop'); ?></p>
                <a href="<?php echo wp_login_url(get_permalink($post_id)); ?>" class="download-login-btn qls-login-trigger">
                    <?php _e('立即登录', 'qilingshop'); ?>
                </a>
            </div>
        <?php else: ?>
            <?php if ($tips_download): ?>
            <div class="download-tips-box">
                <?php echo wp_kses_post($tips_download); ?>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($urls)): ?>
            <div class="download-section">
                <h3><span class="section-icon">📥</span> <?php _e('下载地址', 'qilingshop'); ?></h3>
                <div class="download-list">
                    <?php foreach ($urls as $index => $url): 
                        $download_index = isset($url['index']) ? (int) $url['index'] : (int) $index;
                        $item_can_download = false;
                        if ($sale_mode === 'free' || ($price <= 0 && $price_rmb <= 0 && $user_id) || $is_vip_free) {
                            $item_can_download = true;
                        } elseif ($user_id) {
                            $item_can_download = $order->user_has_purchased($post_id, $user_id, false, 'download', $download_index);
                        } elseif (QilingShop_Guest::instance()->is_enabled()) {
                            $guest_id = QilingShop_Guest::instance()->get_guest_id();
                            $item_can_download = QilingShop_Order::instance()->guest_has_purchased($post_id, $guest_id, 'download', $download_index);
                        }
                        $token = $security->encrypt_download_url($url['url'], $post_id, $download_index);
                    ?>
                    <div class="download-item">
                        <div class="download-item-info">
                            <span class="download-name"><?php echo esc_html($url['name']); ?></span>
                            <?php if ($item_can_download && !empty($url['code'])): ?>
                            <span class="download-code">
                                <?php _e('提取码：', 'qilingshop'); ?>
                                <code class="copyable" data-copy="<?php echo esc_attr($url['code']); ?>"><?php echo esc_html($url['code']); ?></code>
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php if (!$item_can_download): ?>
                        <span class="download-btn disabled" aria-disabled="true">
                            <?php _e('未购买此下载项', 'qilingshop'); ?>
                        </span>
                        <?php else: ?>
                        <button type="button" class="download-btn qls-download-content-tb-secure-download" 
                           data-token="<?php echo esc_attr($token); ?>" 
                           data-post-id="<?php echo $post_id; ?>"
                           data-is-paid="<?php echo $is_paid ? 1 : 0; ?>">
                             <span class="btn-icon">⬇</span> <?php _e('点击下载', 'qilingshop'); ?>
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($hidden_content): ?>
            <div class="hidden-content-section">
                <h3><span class="section-icon">🔓</span> <?php _e('隐藏内容', 'qilingshop'); ?></h3>
                <div class="hidden-content">
                    <?php echo wp_kses_post($hidden_content); ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>



<script>
// 复制提取码
document.querySelectorAll('.copyable').forEach(function(el) {
    el.addEventListener('click', function() {
        navigator.clipboard.writeText(this.dataset.copy).then(function() {
            alert('<?php _e('提取码已复制', 'qilingshop'); ?>');
        });
    });
});
</script>

<?php
get_footer();
