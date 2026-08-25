<?php
/**
 * 好友助力活动配置
 */
if (!defined('ABSPATH')) {
    exit;
}

$current_status = isset($_GET['status']) ? (string) $_GET['status'] : '';
$all_count = isset($all_count) ? (int) $all_count : (int) $total;
$selected_product_id = (int) ($edit_activity->product_id ?? 0);
$selected_product_title = '';
$selected_product_price = '';
$selected_product_image = '';
$extract_product_image = function($media) {
    if (is_array($media)) {
        return !empty($media['url']) ? (string) $media['url'] : '';
    }
    if (!is_string($media) || $media === '') {
        return '';
    }
    $decoded = json_decode($media, true);
    if (is_array($decoded)) {
        return !empty($decoded['url']) ? (string) $decoded['url'] : '';
    }
    return $media;
};
if (!empty($selected_product)) {
    $selected_product_title = (string) $selected_product->title;
    $selected_product_image = $extract_product_image($selected_product->main_image ?? '');
    if ($selected_product_image === '' && !empty($selected_product->gallery) && is_array($selected_product->gallery)) {
        $selected_product_image = $extract_product_image(reset($selected_product->gallery));
    }
    $min_price = isset($selected_product->min_price) ? (float) $selected_product->min_price : 0.0;
    $max_price = isset($selected_product->max_price) ? (float) $selected_product->max_price : $min_price;
    $selected_product_price = $min_price === $max_price
        ? '¥' . number_format($min_price, 2)
        : '¥' . number_format($min_price, 2) . ' - ¥' . number_format($max_price, 2);
} elseif (!empty($edit_activity->product_title)) {
    $selected_product_title = (string) $edit_activity->product_title;
}
?>
<div class="wrap qilingshop-admin-page qls-shop-wrap">
    <div class="qls-page-header">
        <h1 class="qls-page-title"><?php _e('好友助力活动', 'qilingshop'); ?></h1>
    </div>

    <?php settings_errors('qls_assist_activities'); ?>

    <ul class="qls-chip-nav" aria-label="<?php esc_attr_e('助力活动筛选', 'qilingshop'); ?>">
        <li>
            <a href="<?php echo esc_url(admin_url('admin.php?page=qls-assist-activities')); ?>" class="<?php echo $current_status === '' ? 'current' : ''; ?>">
                <?php _e('全部', 'qilingshop'); ?>
                <span class="count">(<?php echo (int) $all_count; ?>)</span>
            </a>
        </li>
        <li>
            <a href="<?php echo esc_url(admin_url('admin.php?page=qls-assist-activities&status=' . QLS_Assist::ACTIVITY_ENABLED)); ?>" class="<?php echo $current_status === (string) QLS_Assist::ACTIVITY_ENABLED ? 'current' : ''; ?>">
                <?php _e('上架中', 'qilingshop'); ?>
                <span class="count">(<?php echo (int) $enabled_count; ?>)</span>
            </a>
        </li>
        <li>
            <a href="<?php echo esc_url(admin_url('admin.php?page=qls-assist-activities&status=' . QLS_Assist::ACTIVITY_DISABLED)); ?>" class="<?php echo $current_status === (string) QLS_Assist::ACTIVITY_DISABLED ? 'current' : ''; ?>">
                <?php _e('下架中', 'qilingshop'); ?>
                <span class="count">(<?php echo (int) $disabled_count; ?>)</span>
            </a>
        </li>
    </ul>

    <div class="qls-toolbar">
        <form method="get" class="qls-search-form qls-toolbar-search">
            <input type="hidden" name="page" value="qls-assist-activities">
            <?php if ($current_status !== ''): ?>
            <input type="hidden" name="status" value="<?php echo esc_attr($current_status); ?>">
            <?php endif; ?>
            <input type="search" name="s" value="<?php echo esc_attr($keyword); ?>" placeholder="<?php esc_attr_e('搜索活动名或商品', 'qilingshop'); ?>">
            <button type="submit" class="button"><?php _e('搜索', 'qilingshop'); ?></button>
        </form>
    </div>

    <div class="qls-settings-two-col qls-assist-activities-layout">
        <div class="qls-form-col qls-assist-form-col">
            <h2 class="qls-mt-0"><?php echo $edit_activity ? __('编辑助力活动', 'qilingshop') : __('新增助力活动', 'qilingshop'); ?></h2>
            <form method="post" class="qls-assist-activity-form">
                <?php wp_nonce_field('qls_assist_activity_action'); ?>
                <input type="hidden" name="qls_assist_activity_action" value="save">
                <input type="hidden" name="activity_id" value="<?php echo esc_attr($edit_activity->id ?? 0); ?>">

                <table class="form-table qls-ui-form-table">
                    <tr>
                        <th><label for="assist_name"><?php _e('活动名称', 'qilingshop'); ?></label></th>
                        <td><input type="text" name="name" id="assist_name" class="regular-text" required value="<?php echo esc_attr($edit_activity->name ?? ''); ?>"></td>
                    </tr>
	                    <tr>
	                        <th><label for="assist_product"><?php _e('助力商品', 'qilingshop'); ?></label></th>
	                        <td>
                                <input type="hidden" name="product_id" id="assist_product" value="<?php echo esc_attr($selected_product_id); ?>">
                                <div class="qls-assist-product-picker">
                                    <div class="qls-assist-selected-product <?php echo $selected_product_id > 0 ? 'has-product' : 'is-empty'; ?>">
                                        <div class="qls-assist-selected-thumb">
                                            <?php if ($selected_product_image !== ''): ?>
                                            <img src="<?php echo esc_url($selected_product_image); ?>" alt="">
                                            <?php else: ?>
                                            <span class="dashicons dashicons-products"></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="qls-assist-selected-info">
                                            <strong class="qls-assist-selected-title">
                                                <?php echo $selected_product_id > 0 ? esc_html('#' . $selected_product_id . ' ' . $selected_product_title) : esc_html__('还没有选择商品', 'qilingshop'); ?>
                                            </strong>
                                            <span class="qls-assist-selected-meta">
                                                <?php echo $selected_product_price !== '' ? esc_html($selected_product_price) : esc_html__('从商品库选择一个上架商品作为助力商品', 'qilingshop'); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <button type="button" class="button button-secondary" id="qls-assist-open-product-library">
                                        <?php echo $selected_product_id > 0 ? esc_html__('更换商品', 'qilingshop') : esc_html__('从商品库选择', 'qilingshop'); ?>
                                    </button>
                                </div>
                                <p class="description qls-assist-product-error" style="display:none;"><?php esc_html_e('请先选择一个助力商品。', 'qilingshop'); ?></p>
	                        </td>
	                    </tr>
                    <tr>
                        <th><label><?php _e('起始金额', 'qilingshop'); ?></label></th>
                        <td><input type="number" step="0.01" min="0" name="start_price" class="small-text" value="<?php echo esc_attr($edit_activity->start_price ?? 0); ?>"> <span class="description"><?php _e('填 0 自动取商品最低价', 'qilingshop'); ?></span></td>
                    </tr>
                    <tr>
                        <th><label><?php _e('最低金额', 'qilingshop'); ?></label></th>
                        <td><input type="number" step="0.01" min="0" name="min_price" class="small-text" value="<?php echo esc_attr($edit_activity->min_price ?? 0); ?>"></td>
                    </tr>
                    <tr>
                        <th><label><?php _e('单次助力减额', 'qilingshop'); ?></label></th>
                        <td>
                            <input type="number" step="0.01" min="0.01" name="help_min" class="small-text" value="<?php echo esc_attr($edit_activity->help_min ?? 0.1); ?>">
                            ~
                            <input type="number" step="0.01" min="0.01" name="help_max" class="small-text" value="<?php echo esc_attr($edit_activity->help_max ?? 1); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php _e('目标助力人数', 'qilingshop'); ?></label></th>
                        <td><input type="number" step="1" min="0" name="target_helpers" class="small-text" value="<?php echo esc_attr($edit_activity->target_helpers ?? 0); ?>"> <span class="description"><?php _e('0 表示仅按最低金额达成', 'qilingshop'); ?></span></td>
                    </tr>
                    <tr>
                        <th><label><?php _e('助力有效期', 'qilingshop'); ?></label></th>
                        <td>
                            <input type="number" step="1" min="1" name="expire_hours" class="small-text" value="<?php echo esc_attr($edit_activity->expire_hours ?? 24); ?>"> <?php _e('小时', 'qilingshop'); ?>
                            <span class="description"><?php _e('建议设置为 24 或 48 小时，超时后自动判定为助力失败。', 'qilingshop'); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php _e('助力库存', 'qilingshop'); ?></label></th>
                        <td><input type="number" step="1" min="1" name="stock_total" class="small-text" value="<?php echo esc_attr($edit_activity->stock_total ?? 1); ?>"></td>
                    </tr>
                    <tr>
                        <th><label><?php _e('活动开始', 'qilingshop'); ?></label></th>
                        <td><input type="datetime-local" name="start_time" value="<?php echo !empty($edit_activity->start_time) ? esc_attr(date('Y-m-d\TH:i', strtotime($edit_activity->start_time))) : ''; ?>"></td>
                    </tr>
                    <tr>
                        <th><label><?php _e('活动结束', 'qilingshop'); ?></label></th>
                        <td><input type="datetime-local" name="end_time" value="<?php echo !empty($edit_activity->end_time) ? esc_attr(date('Y-m-d\TH:i', strtotime($edit_activity->end_time))) : ''; ?>"></td>
                    </tr>
                    <tr>
                        <th><label><?php _e('状态', 'qilingshop'); ?></label></th>
                        <td><label><input type="checkbox" name="status" value="1" <?php checked(isset($edit_activity->status) ? (int) $edit_activity->status : 1, 1); ?>> <?php _e('上架活动', 'qilingshop'); ?></label></td>
                    </tr>
                    <tr>
                        <th><label><?php _e('退款回补库存', 'qilingshop'); ?></label></th>
                        <td><label><input type="checkbox" name="auto_restore_stock" value="1" <?php checked(isset($edit_activity->auto_restore_stock) ? (int) $edit_activity->auto_restore_stock : 1, 1); ?>> <?php _e('开启（推荐）', 'qilingshop'); ?></label></td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php echo $edit_activity ? esc_html__('更新活动', 'qilingshop') : esc_html__('创建活动', 'qilingshop'); ?></button>
                    <?php if ($edit_activity): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=qls-assist-activities')); ?>" class="button"><?php _e('取消编辑', 'qilingshop'); ?></a>
                    <?php endif; ?>
                </p>
            </form>
	        </div>
	    </div>

    <div id="qls-assist-product-modal" class="qls-modal qls-assist-product-modal qls-hidden" style="display:none;" aria-hidden="true">
        <div class="qls-assist-product-backdrop qls-modal-close"></div>
        <div class="qls-assist-product-dialog" role="dialog" aria-modal="true" aria-labelledby="qls-assist-product-modal-title">
            <div class="qls-assist-product-modal-head">
                <div>
                    <h2 id="qls-assist-product-modal-title"><?php esc_html_e('选择助力商品', 'qilingshop'); ?></h2>
                    <p><?php esc_html_e('搜索商品名称，点选商品后会自动带回活动表单。', 'qilingshop'); ?></p>
                </div>
                <button type="button" class="button-link qls-modal-close qls-assist-product-modal-close" aria-label="<?php esc_attr_e('关闭', 'qilingshop'); ?>">×</button>
            </div>
            <form id="qls-assist-product-search-form" class="qls-assist-product-search">
                <input type="search" id="qls-assist-product-search" placeholder="<?php esc_attr_e('搜索商品名称...', 'qilingshop'); ?>">
                <button type="submit" class="button button-primary"><?php esc_html_e('搜索', 'qilingshop'); ?></button>
            </form>
            <div id="qls-assist-product-results" class="qls-assist-product-results"></div>
            <div id="qls-assist-product-status" class="qls-assist-product-status"></div>
            <button type="button" id="qls-assist-product-load-more" class="button qls-assist-product-load-more" style="display:none;"><?php esc_html_e('加载更多', 'qilingshop'); ?></button>
        </div>
    </div>

	    <div class="qls-list-col qls-assist-list-block">
        <h2 class="qls-mt-0"><?php _e('活动列表', 'qilingshop'); ?></h2>
        <table class="wp-list-table qls-ui-table widefat fixed striped">
            <thead>
                <tr>
                    <th class="qls-w-70">ID</th>
                    <th><?php _e('活动/商品', 'qilingshop'); ?></th>
                    <th class="qls-w-120"><?php _e('价格区间', 'qilingshop'); ?></th>
                    <th class="qls-w-120"><?php _e('助力减额', 'qilingshop'); ?></th>
                    <th class="qls-w-150"><?php _e('库存(总/锁/售/可用)', 'qilingshop'); ?></th>
                    <th class="qls-w-90"><?php _e('状态', 'qilingshop'); ?></th>
                    <th class="qls-w-170"><?php _e('有效期', 'qilingshop'); ?></th>
                    <th class="qls-w-240"><?php _e('操作', 'qilingshop'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($activities)): ?>
                <tr>
                    <td colspan="8" class="no-items"><?php _e('暂无助力活动', 'qilingshop'); ?></td>
                </tr>
                <?php else: ?>
                <?php foreach ($activities as $activity): ?>
                <?php
                $is_enabled = (int) $activity->status === QLS_Assist::ACTIVITY_ENABLED;
                $is_expired = !empty($activity->end_time) && strtotime((string) $activity->end_time) <= current_time('timestamp');
                ?>
                <tr>
                    <td><code><?php echo (int) $activity->id; ?></code></td>
                    <td>
                        <strong><?php echo esc_html($activity->name); ?></strong>
                        <div class="qls-text-subtle">#<?php echo (int) $activity->product_id; ?> <?php echo esc_html($activity->product_title ?: __('商品已删除', 'qilingshop')); ?></div>
                    </td>
                    <td>¥<?php echo number_format((float) $activity->start_price, 2); ?><br>→ ¥<?php echo number_format((float) $activity->min_price, 2); ?></td>
                    <td><?php echo number_format((float) $activity->help_min, 2); ?> ~ <?php echo number_format((float) $activity->help_max, 2); ?></td>
                    <td><?php echo (int) $activity->stock_total; ?> / <?php echo (int) $activity->stock_locked; ?> / <?php echo (int) $activity->stock_sold; ?> / <strong><?php echo (int) $activity->stock_available; ?></strong></td>
                    <td>
                        <?php if ($is_enabled): ?>
                            <span class="qls-text-success"><?php esc_html_e('上架中', 'qilingshop'); ?></span>
                        <?php else: ?>
                            <span class="qls-text-muted"><?php esc_html_e('下架中', 'qilingshop'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $time_text = __('永久', 'qilingshop');
                        if (!empty($activity->start_time) || !empty($activity->end_time)) {
                            $start = !empty($activity->start_time) ? date('Y-m-d H:i', strtotime($activity->start_time)) : '...';
                            $end = !empty($activity->end_time) ? date('Y-m-d H:i', strtotime($activity->end_time)) : '...';
                            $time_text = $start . ' ~ ' . $end;
                        }
                        echo esc_html($time_text);
                        ?>
                    </td>
                    <td>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=qls-assist-activities&edit=' . (int) $activity->id)); ?>"><?php _e('编辑', 'qilingshop'); ?></a>
                        <?php if ($is_enabled): ?>
                        |
                        <form method="post"
                              class="qls-confirm-form qls-inline-form"
                              data-confirm-title="<?php echo esc_attr__('下架活动', 'qilingshop'); ?>"
                              data-confirm-message="<?php echo esc_attr__('确定将该活动切换为下架中吗？下架后前台大厅将不再显示，但已参与用户流程不受影响。', 'qilingshop'); ?>"
                              data-confirm-ok="<?php echo esc_attr__('确认下架', 'qilingshop'); ?>">
                            <?php wp_nonce_field('qls_assist_activity_action'); ?>
                            <input type="hidden" name="qls_assist_activity_action" value="disable">
                            <input type="hidden" name="activity_id" value="<?php echo (int) $activity->id; ?>">
                            <button type="submit" class="button-link"><?php _e('下架', 'qilingshop'); ?></button>
                        </form>
                        <?php else: ?>
                        |
                        <form method="post"
                              class="qls-confirm-form qls-inline-form"
                              data-confirm-title="<?php echo esc_attr__('重新上架', 'qilingshop'); ?>"
                              data-confirm-message="<?php echo esc_attr($is_expired ? __('将按填写的新结束时间重新上架。是否继续？', 'qilingshop') : __('确定重新上架该活动吗？', 'qilingshop')); ?>"
                              data-confirm-ok="<?php echo esc_attr__('确认上架', 'qilingshop'); ?>">
                            <?php wp_nonce_field('qls_assist_activity_action'); ?>
                            <input type="hidden" name="qls_assist_activity_action" value="enable">
                            <input type="hidden" name="activity_id" value="<?php echo (int) $activity->id; ?>">
                            <?php if ($is_expired): ?>
                            <input type="datetime-local" name="reopen_end_time" class="regular-text" required>
                            <?php endif; ?>
                            <button type="submit" class="button-link"><?php _e('重新上架', 'qilingshop'); ?></button>
                        </form>
                        <?php endif; ?>
                        |
                        <form method="post"
                              class="qls-confirm-form qls-inline-form"
                              data-confirm-title="<?php echo esc_attr__('删除活动', 'qilingshop'); ?>"
                              data-confirm-message="<?php echo esc_attr__('删除后不可恢复。系统会先校验是否还有用户参与中，有进行中活动将禁止删除。', 'qilingshop'); ?>"
                              data-confirm-ok="<?php echo esc_attr__('确认删除', 'qilingshop'); ?>">
                            <?php wp_nonce_field('qls_assist_activity_action'); ?>
                            <input type="hidden" name="qls_assist_activity_action" value="delete">
                            <input type="hidden" name="activity_id" value="<?php echo (int) $activity->id; ?>">
                            <button type="submit" class="button-link button-link-delete"><?php _e('删除', 'qilingshop'); ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <?php
                $total_pages = (int) ceil(max(1, $total) / $limit);
                if ($total_pages > 1) {
                    echo paginate_links([
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                        'total' => $total_pages,
                        'current' => $paged,
                    ]);
                }
                ?>
            </div>
        </div>
    </div>
</div>
