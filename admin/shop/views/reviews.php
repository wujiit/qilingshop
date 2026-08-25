<?php
/**
 * 后台评价管理页面
 */
if (!defined('ABSPATH')) exit;

$status_labels = [
    0 => __('待审核', 'qilingshop'),
    1 => __('已通过', 'qilingshop'),
    2 => __('已隐藏', 'qilingshop'),
];

// 当前筛选状态
$current_status = isset($_GET['status']) && $_GET['status'] !== '' ? intval($_GET['status']) : null;
?>

<div class="wrap qilingshop-admin-page qls-admin-wrap qls-shop-wrap">
    <div class="qls-page-header">
        <h1 class="qls-page-title"><?php _e('评价管理', 'qilingshop'); ?></h1>
    </div>

    <?php if (isset($_GET['message'])): ?>
    <div class="notice notice-success is-dismissible">
        <p>
            <?php
            $messages = [
                'approved' => __('评价已通过审核', 'qilingshop'),
                'hidden'   => __('评价已隐藏', 'qilingshop'),
                'deleted'  => __('评价已删除', 'qilingshop'),
                'updated'  => __('操作成功', 'qilingshop'),
                'replied'  => __('回复成功', 'qilingshop'),
            ];
            echo esc_html($messages[$_GET['message']] ?? __('操作成功', 'qilingshop'));
            ?>
        </p>
    </div>
    <?php endif; ?>

    <div class="qls-review-stats">
        <div class="qls-review-stat-card">
            <span class="qls-review-stat-value qls-review-stat-pending"><?php echo esc_html($stats['pending']); ?></span>
            <span class="qls-review-stat-label"><?php _e('待审核', 'qilingshop'); ?></span>
        </div>
        <div class="qls-review-stat-card">
            <span class="qls-review-stat-value qls-review-stat-approved"><?php echo esc_html($stats['approved']); ?></span>
            <span class="qls-review-stat-label"><?php _e('已通过', 'qilingshop'); ?></span>
        </div>
        <div class="qls-review-stat-card">
            <span class="qls-review-stat-value qls-review-stat-hidden"><?php echo esc_html($stats['hidden']); ?></span>
            <span class="qls-review-stat-label"><?php _e('已隐藏', 'qilingshop'); ?></span>
        </div>
    </div>

    <ul class="qls-chip-nav" aria-label="<?php esc_attr_e('评价筛选', 'qilingshop'); ?>">
        <li>
            <a href="<?php echo esc_url(remove_query_arg(['status', 'paged'])); ?>" class="<?php echo $current_status === null ? 'current' : ''; ?>">
                <?php _e('全部', 'qilingshop'); ?>
                <span class="count">(<?php echo (int) ($stats['pending'] + $stats['approved'] + $stats['hidden']); ?>)</span>
            </a>
        </li>
        <li>
            <a href="<?php echo esc_url(add_query_arg(['status' => 0, 'paged' => 1])); ?>" class="<?php echo $current_status === 0 ? 'current' : ''; ?>">
                <?php _e('待审核', 'qilingshop'); ?>
                <span class="count">(<?php echo (int) $stats['pending']; ?>)</span>
            </a>
        </li>
        <li>
            <a href="<?php echo esc_url(add_query_arg(['status' => 1, 'paged' => 1])); ?>" class="<?php echo $current_status === 1 ? 'current' : ''; ?>">
                <?php _e('已通过', 'qilingshop'); ?>
                <span class="count">(<?php echo (int) $stats['approved']; ?>)</span>
            </a>
        </li>
        <li>
            <a href="<?php echo esc_url(add_query_arg(['status' => 2, 'paged' => 1])); ?>" class="<?php echo $current_status === 2 ? 'current' : ''; ?>">
                <?php _e('已隐藏', 'qilingshop'); ?>
                <span class="count">(<?php echo (int) $stats['hidden']; ?>)</span>
            </a>
        </li>
    </ul>

    <div class="qls-toolbar qls-toolbar-between">
        <form method="get" class="qls-search-form qls-toolbar-search">
            <input type="hidden" name="page" value="qls-shop-reviews">
            <?php if ($current_status !== null): ?>
            <input type="hidden" name="status" value="<?php echo $current_status; ?>">
            <?php endif; ?>
            <input type="search" name="s" value="<?php echo esc_attr($_GET['s'] ?? ''); ?>" placeholder="<?php esc_attr_e('搜索评价内容...', 'qilingshop'); ?>">
            <button type="submit" class="button"><?php _e('搜索', 'qilingshop'); ?></button>
        </form>
    </div>

    <table class="wp-list-table qls-ui-table widefat fixed striped qls-review-table">
        <thead>
            <tr>
                <th class="qls-w-60"><?php _e('ID', 'qilingshop'); ?></th>
                <th class="qls-w-200"><?php _e('商品', 'qilingshop'); ?></th>
                <th class="qls-w-100"><?php _e('用户', 'qilingshop'); ?></th>
                <th class="qls-w-80"><?php _e('评分', 'qilingshop'); ?></th>
                <th><?php _e('评价内容', 'qilingshop'); ?></th>
                <th class="qls-w-80"><?php _e('图片', 'qilingshop'); ?></th>
                <th class="qls-w-80"><?php _e('状态', 'qilingshop'); ?></th>
                <th class="qls-w-140"><?php _e('时间', 'qilingshop'); ?></th>
                <th class="qls-w-200"><?php _e('操作', 'qilingshop'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($reviews)): ?>
            <tr>
                <td colspan="9" class="qls-review-empty"><?php _e('暂无评价数据', 'qilingshop'); ?></td>
            </tr>
            <?php else: ?>
            <?php foreach ($reviews as $review): ?>
            <?php
            $review_status = (int) $review->status;
            $status_class = 'qls-review-status-hidden';
            if ($review_status === 0) {
                $status_class = 'qls-review-status-pending';
            } elseif ($review_status === 1) {
                $status_class = 'qls-review-status-approved';
            }
            ?>
            <tr>
                <td><?php echo esc_html($review->id); ?></td>
                <td>
                    <strong><?php echo esc_html($review->product_title ?: __('商品已删除', 'qilingshop')); ?></strong>
                    <?php if ($review->sku_info): ?>
                    <br><small class="qls-text-muted"><?php echo esc_html($review->sku_info); ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <?php echo esc_html($review->user_name ?: __('用户', 'qilingshop') . '#' . $review->user_id); ?>
                    <?php if ($review->is_anonymous): ?>
                    <br><small class="qls-review-anonymous"><?php _e('匿名', 'qilingshop'); ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="qls-review-stars">
                        <?php echo str_repeat('★', (int) $review->rating); ?><?php echo str_repeat('☆', 5 - (int) $review->rating); ?>
                    </span>
                </td>
                <td>
                    <div class="qls-review-content">
                        <?php echo esc_html($review->content); ?>
                    </div>
                    <?php if ($review->admin_reply): ?>
                    <div class="qls-review-reply-box">
                        <strong class="qls-review-reply-title"><?php _e('商家回复:', 'qilingshop'); ?></strong>
                        <?php echo esc_html($review->admin_reply); ?>
                        <br><small class="qls-text-muted"><?php echo esc_html(date('Y-m-d H:i', strtotime($review->reply_time))); ?></small>
                    </div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($review->images)): ?>
                    <a href="#" class="qls-view-images qls-review-images-link" data-images='<?php echo esc_attr(json_encode($review->images)); ?>'>
                        <span class="dashicons dashicons-format-gallery qls-review-images-icon"></span>
                        <span class="qls-review-images-count"><?php echo count($review->images); ?></span>
                    </a>
                    <?php else: ?>
                    <span class="qls-text-muted">-</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="qls-review-status-badge <?php echo esc_attr($status_class); ?>">
                        <?php echo esc_html($status_labels[$review_status] ?? __('未知', 'qilingshop')); ?>
                    </span>
                    <?php if ($review->is_top): ?>
                    <br><small class="qls-review-top-badge"><?php _e('置顶', 'qilingshop'); ?></small>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html(date('Y-m-d H:i', strtotime($review->created_at))); ?></td>
                <td>
                    <?php if ($review->status == 0): ?>
                    <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'approve', 'id' => $review->id]), 'review_action_' . $review->id)); ?>"
                       class="button button-small button-primary">
                        <?php _e('通过', 'qilingshop'); ?>
                    </a>
                    <?php endif; ?>

                    <?php if ($review->status == 1): ?>
                    <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'hide', 'id' => $review->id]), 'review_action_' . $review->id)); ?>"
                       class="button button-small">
                        <?php _e('隐藏', 'qilingshop'); ?>
                    </a>
                    <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'top', 'id' => $review->id]), 'review_action_' . $review->id)); ?>"
                       class="button button-small">
                        <?php echo $review->is_top ? __('取消置顶', 'qilingshop') : __('置顶', 'qilingshop'); ?>
                    </a>
                    <?php endif; ?>

                    <?php if ($review->status == 2): ?>
                    <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'approve', 'id' => $review->id]), 'review_action_' . $review->id)); ?>"
                       class="button button-small">
                        <?php _e('恢复', 'qilingshop'); ?>
                    </a>
                    <?php endif; ?>

                    <button type="button" class="button button-small qls-reply-btn" data-id="<?php echo esc_attr($review->id); ?>" data-reply="<?php echo esc_attr($review->admin_reply); ?>">
                        <?php echo $review->admin_reply ? __('编辑回复', 'qilingshop') : __('回复', 'qilingshop'); ?>
                    </button>

                    <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'delete', 'id' => $review->id]), 'review_action_' . $review->id)); ?>"
                       class="button button-small qls-review-delete-link"
                       onclick="return confirm('<?php esc_attr_e('确定要删除这条评价吗？', 'qilingshop'); ?>');">
                        <?php _e('删除', 'qilingshop'); ?>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($total_pages > 1): ?>
    <div class="tablenav bottom qls-review-pagination">
        <div class="tablenav-pages">
            <span class="displaying-num"><?php printf(__('共 %d 项', 'qilingshop'), (int) $total); ?></span>
            <?php
            echo paginate_links([
                'base'      => add_query_arg('paged', '%#%'),
                'format'    => '',
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
                'total'     => $total_pages,
                'current'   => $args['page'],
            ]);
            ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<div id="qls-reply-modal" class="qls-modal qls-hidden">
    <div class="qls-modal-content qls-review-reply-modal">
        <h3 class="qls-review-modal-title"><?php _e('回复评价', 'qilingshop'); ?></h3>
        <form method="post">
            <?php wp_nonce_field('review_reply', 'review_reply_nonce'); ?>
            <input type="hidden" name="review_id" id="reply-review-id" value="">
            <textarea name="admin_reply" id="reply-content" rows="5" class="widefat qls-review-reply-textarea" placeholder="<?php esc_attr_e('输入回复内容...', 'qilingshop'); ?>"></textarea>
            <div class="qls-review-reply-actions">
                <button type="button" class="button qls-reply-cancel"><?php _e('取消', 'qilingshop'); ?></button>
                <button type="submit" class="button button-primary"><?php _e('提交回复', 'qilingshop'); ?></button>
            </div>
        </form>
    </div>
</div>

<div id="qls-images-modal" class="qls-review-images-modal qls-hidden">
    <span class="qls-images-close qls-review-images-close">&times;</span>
    <div id="qls-images-container" class="qls-review-images-container"></div>
</div>

<script>
jQuery(document).ready(function($) {
    function showModal($modal) {
        $modal.removeClass('qls-hidden').css('display', 'flex').hide().fadeIn(200, function() {
            $(this).css('display', 'flex');
        });
    }

    function hideModal($modal) {
        $modal.fadeOut(200, function() {
            $(this).addClass('qls-hidden').css('display', '');
        });
    }

    function showOverlay($overlay) {
        $overlay.removeClass('qls-hidden').hide().fadeIn(200);
    }

    function hideOverlay($overlay) {
        $overlay.fadeOut(200, function() {
            $(this).addClass('qls-hidden').css('display', '');
        });
    }

    $('.qls-reply-btn').on('click', function() {
        var id = $(this).data('id');
        var reply = $(this).data('reply') || '';
        $('#reply-review-id').val(id);
        $('#reply-content').val(reply);
        showModal($('#qls-reply-modal'));
    });

    $('.qls-reply-cancel').on('click', function() {
        hideModal($('#qls-reply-modal'));
    });

    $('#qls-reply-modal').on('click', function(e) {
        if (e.target === this) {
            hideModal($(this));
        }
    });

    $('.qls-view-images').on('click', function(e) {
        e.preventDefault();
        var images = $(this).data('images');
        if (!images || !images.length) {
            return;
        }

        var html = '';
        $.each(images, function(i, url) {
            html += '<div class="qls-review-image-card">';
            html += '<img src="' + url + '" class="qls-review-image-preview" alt="">';
            html += '</div>';
        });
        $('#qls-images-container').html(html);
        showOverlay($('#qls-images-modal'));
    });

    $('#qls-images-container').on('click', '.qls-review-image-preview', function() {
        window.open(this.src);
    });

    $('#qls-images-modal, .qls-images-close').on('click', function(e) {
        if (e.target === this || $(e.target).hasClass('qls-images-close')) {
            hideOverlay($('#qls-images-modal'));
        }
    });
});
</script>
