<?php
/**
 * 卡密管理视图。
 */
if (!defined('ABSPATH')) {
    exit;
}

$card_sku_options = isset($card_sku_options) && is_array($card_sku_options) ? $card_sku_options : [];
$stats = isset($stats) && is_array($stats) ? $stats : [];
$cards = isset($cards) && is_array($cards) ? $cards : [];
$total = isset($total) ? (int) $total : 0;
$per_page = isset($per_page) ? (int) $per_page : 30;
$paged = isset($paged) ? max(1, (int) $paged) : 1;
$selected_product_id = isset($selected_product_id) ? (int) $selected_product_id : 0;
$selected_sku_id = isset($selected_sku_id) ? (int) $selected_sku_id : 0;
$selected_status = isset($selected_status) ? (string) $selected_status : '';
$notice = isset($notice) && is_array($notice) ? $notice : null;

$product_groups = [];
foreach ($card_sku_options as $option) {
    $product_id = isset($option->product_id) ? (int) $option->product_id : 0;
    if ($product_id <= 0) {
        continue;
    }

    if (!isset($product_groups[$product_id])) {
        $product_groups[$product_id] = [
            'title' => !empty($option->product_title)
                ? (string) $option->product_title
                : sprintf(__('商品 #%d', 'qilingshop'), $product_id),
            'skus'  => [],
        ];
    }

    $product_groups[$product_id]['skus'][] = $option;
}

$has_card_products = !empty($product_groups);
$has_filters = $selected_product_id > 0 || $selected_sku_id > 0 || $selected_status !== '';
$current_target = ($selected_product_id > 0 && $selected_sku_id > 0) ? ($selected_product_id . ':' . $selected_sku_id) : '';

$render_target_options = function($selected = '') use ($product_groups) {
    foreach ($product_groups as $product_id => $group) {
        echo '<optgroup label="' . esc_attr($group['title']) . '">';
        foreach ($group['skus'] as $sku) {
            $value = (int) $product_id . ':' . (int) $sku->sku_id;
            $label = sprintf(
                __('%1$s（可用 %2$d / 已售 %3$d）', 'qilingshop'),
                (string) $sku->sku_label,
                (int) $sku->available_count,
                (int) $sku->sold_count
            );
            echo '<option value="' . esc_attr($value) . '" ' . selected($selected, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</optgroup>';
    }
};

$format_card_time = function($datetime) {
    $datetime = trim((string) $datetime);
    if ($datetime === '' || $datetime === '0000-00-00 00:00:00') {
        return '—';
    }

    return mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $datetime);
};
?>

<div class="wrap qilingshop-admin-page qls-shop-wrap qls-card-inventory-page">
    <div class="qls-page-header qls-page-header-inline">
        <div>
            <h1 class="wp-heading-inline qls-page-title"><?php _e('卡密管理', 'qilingshop'); ?></h1>
            <p class="qls-page-intro"><?php _e('集中管理虚拟商品卡密库存，支持按商品和 SKU 入库、生成与查询。', 'qilingshop'); ?></p>
        </div>
        <a href="<?php echo esc_url(admin_url('admin.php?page=qls-product-edit')); ?>" class="page-title-action"><?php _e('添加卡密商品', 'qilingshop'); ?></a>
    </div>

    <?php if (!empty($notice)): ?>
    <div class="notice <?php echo esc_attr($notice['class'] ?? 'notice-info'); ?> is-dismissible">
        <p><?php echo esc_html($notice['message'] ?? ''); ?></p>
    </div>
    <?php endif; ?>

    <?php if (!$has_card_products): ?>
    <div class="notice notice-info">
        <p><?php _e('还没有可管理的卡密商品。请先创建虚拟商品，并将交付类型设为“卡号卡密”。', 'qilingshop'); ?></p>
    </div>
    <?php endif; ?>

    <div class="qls-card-stat-grid">
        <div class="qls-card-stat">
            <span><?php _e('总卡密', 'qilingshop'); ?></span>
            <strong><?php echo esc_html(number_format_i18n($stats['total'] ?? 0)); ?></strong>
        </div>
        <div class="qls-card-stat">
            <span><?php _e('未售出', 'qilingshop'); ?></span>
            <strong><?php echo esc_html(number_format_i18n($stats['available'] ?? 0)); ?></strong>
        </div>
        <div class="qls-card-stat">
            <span><?php _e('已售出', 'qilingshop'); ?></span>
            <strong><?php echo esc_html(number_format_i18n($stats['sold'] ?? 0)); ?></strong>
        </div>
        <div class="qls-card-stat">
            <span><?php _e('已撤回', 'qilingshop'); ?></span>
            <strong><?php echo esc_html(number_format_i18n($stats['revoked'] ?? 0)); ?></strong>
        </div>
    </div>

    <form method="get" class="qls-card-filter qls-toolbar qls-toolbar-between">
        <input type="hidden" name="page" value="qls-card-inventory">
        <div class="qls-toolbar-search">
            <select name="product_id">
                <option value="0"><?php _e('全部卡密商品', 'qilingshop'); ?></option>
                <?php foreach ($product_groups as $product_id => $group): ?>
                <option value="<?php echo esc_attr($product_id); ?>" <?php selected($selected_product_id, (int) $product_id); ?>>
                    <?php echo esc_html($group['title']); ?>
                </option>
                <?php endforeach; ?>
            </select>

            <select name="sku_id">
                <option value="0"><?php _e('全部 SKU', 'qilingshop'); ?></option>
                <?php foreach ($product_groups as $group): ?>
                <optgroup label="<?php echo esc_attr($group['title']); ?>">
                    <?php foreach ($group['skus'] as $sku): ?>
                    <option value="<?php echo esc_attr($sku->sku_id); ?>" <?php selected($selected_sku_id, (int) $sku->sku_id); ?>>
                        <?php echo esc_html($sku->sku_label); ?>
                    </option>
                    <?php endforeach; ?>
                </optgroup>
                <?php endforeach; ?>
            </select>

            <select name="status">
                <option value=""><?php _e('全部状态', 'qilingshop'); ?></option>
                <option value="0" <?php selected($selected_status, '0'); ?>><?php _e('未售出', 'qilingshop'); ?></option>
                <option value="1" <?php selected($selected_status, '1'); ?>><?php _e('已售出', 'qilingshop'); ?></option>
                <option value="2" <?php selected($selected_status, '2'); ?>><?php _e('已撤回', 'qilingshop'); ?></option>
            </select>

            <button type="submit" class="button"><?php _e('筛选', 'qilingshop'); ?></button>
            <?php if ($has_filters): ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=qls-card-inventory')); ?>" class="button button-secondary"><?php _e('清除筛选', 'qilingshop'); ?></a>
            <?php endif; ?>
        </div>
    </form>

    <div class="qls-card-action-grid">
        <div class="qls-card-action-box">
            <h2><?php _e('批量导入卡密', 'qilingshop'); ?></h2>
            <p><?php _e('适合已有卡号卡密清单的场景，同一商品 SKU 下重复卡号会自动跳过。', 'qilingshop'); ?></p>
            <form method="post">
                <?php wp_nonce_field('qls_card_inventory_action', 'qls_card_inventory_nonce'); ?>
                <input type="hidden" name="qls_card_action" value="import">
                <p>
                    <label for="qls-card-import-target"><?php _e('入库商品 / SKU', 'qilingshop'); ?></label>
                    <select id="qls-card-import-target" name="card_target" class="widefat" required <?php disabled(!$has_card_products); ?>>
                        <option value=""><?php _e('请选择卡密商品和 SKU', 'qilingshop'); ?></option>
                        <?php $render_target_options($current_target); ?>
                    </select>
                </p>
                <p>
                    <label for="qls-card-import-text"><?php _e('卡密内容', 'qilingshop'); ?></label>
                    <textarea id="qls-card-import-text" name="cards_text" rows="8" class="large-text qls-card-textarea" placeholder="<?php esc_attr_e("每行一条，支持：\n卡号\n卡号----卡密\n卡号,卡密\n卡号\t卡密", 'qilingshop'); ?>" <?php disabled(!$has_card_products); ?>></textarea>
                </p>
                <button type="submit" class="button button-primary" <?php disabled(!$has_card_products); ?>><?php _e('导入卡密', 'qilingshop'); ?></button>
            </form>
        </div>

        <div class="qls-card-action-box">
            <h2><?php _e('自动生成卡密', 'qilingshop'); ?></h2>
            <p><?php _e('用于自动发卡库存预置；生成后只入库，不会主动发送给用户。', 'qilingshop'); ?></p>
            <form method="post" class="qls-card-generate-form">
                <?php wp_nonce_field('qls_card_inventory_action', 'qls_card_inventory_nonce'); ?>
                <input type="hidden" name="qls_card_action" value="generate">
                <p>
                    <label for="qls-card-generate-target"><?php _e('入库商品 / SKU', 'qilingshop'); ?></label>
                    <select id="qls-card-generate-target" name="card_target" class="widefat" required <?php disabled(!$has_card_products); ?>>
                        <option value=""><?php _e('请选择卡密商品和 SKU', 'qilingshop'); ?></option>
                        <?php $render_target_options($current_target); ?>
                    </select>
                </p>
                <div class="qls-card-generate-grid">
                    <p>
                        <label for="qls-card-quantity"><?php _e('生成数量', 'qilingshop'); ?></label>
                        <input id="qls-card-quantity" type="number" name="quantity" min="1" max="1000" value="50" <?php disabled(!$has_card_products); ?>>
                    </p>
                    <p>
                        <label for="qls-card-prefix"><?php _e('卡号前缀', 'qilingshop'); ?></label>
                        <input id="qls-card-prefix" type="text" name="card_prefix" maxlength="32" placeholder="<?php esc_attr_e('例如 VIP-', 'qilingshop'); ?>" <?php disabled(!$has_card_products); ?>>
                    </p>
                    <p>
                        <label for="qls-card-no-length"><?php _e('卡号随机位数', 'qilingshop'); ?></label>
                        <input id="qls-card-no-length" type="number" name="card_no_length" min="6" max="32" value="12" <?php disabled(!$has_card_products); ?>>
                    </p>
                    <p>
                        <label for="qls-card-secret-length"><?php _e('卡密随机位数', 'qilingshop'); ?></label>
                        <input id="qls-card-secret-length" type="number" name="card_secret_length" min="8" max="64" value="16" <?php disabled(!$has_card_products); ?>>
                    </p>
                </div>
                <button type="submit" class="button button-primary" <?php disabled(!$has_card_products); ?>><?php _e('生成卡密', 'qilingshop'); ?></button>
            </form>
        </div>
    </div>

    <div class="qls-table-shell qls-card-table-shell">
        <div class="tablenav top qls-table-toolbar">
            <div class="alignleft actions">
                <span class="qls-text-muted">
                    <?php printf(__('共 %s 条卡密记录', 'qilingshop'), esc_html(number_format_i18n($total))); ?>
                </span>
            </div>
            <div class="tablenav-pages">
                <?php
                $total_pages = $per_page > 0 ? (int) ceil($total / $per_page) : 0;
                if ($total_pages > 1) {
                    $pagination_args = [
                        'page' => 'qls-card-inventory',
                    ];
                    if ($selected_product_id > 0) {
                        $pagination_args['product_id'] = $selected_product_id;
                    }
                    if ($selected_sku_id > 0) {
                        $pagination_args['sku_id'] = $selected_sku_id;
                    }
                    if ($selected_status !== '') {
                        $pagination_args['status'] = $selected_status;
                    }
                    $pagination_args['paged'] = '%#%';

                    echo paginate_links([
                        'base'      => add_query_arg($pagination_args, admin_url('admin.php')),
                        'format'    => '',
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                        'total'     => $total_pages,
                        'current'   => $paged,
                    ]);
                }
                ?>
            </div>
        </div>

        <table class="wp-list-table widefat fixed striped qls-ui-table qls-card-inventory-table">
            <thead>
                <tr>
                    <th><?php _e('ID', 'qilingshop'); ?></th>
                    <th><?php _e('商品', 'qilingshop'); ?></th>
                    <th><?php _e('SKU', 'qilingshop'); ?></th>
                    <th><?php _e('卡号', 'qilingshop'); ?></th>
                    <th><?php _e('卡密', 'qilingshop'); ?></th>
                    <th><?php _e('状态', 'qilingshop'); ?></th>
                    <th><?php _e('关联订单', 'qilingshop'); ?></th>
                    <th><?php _e('售出时间', 'qilingshop'); ?></th>
                    <th><?php _e('创建时间', 'qilingshop'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($cards)): ?>
                    <?php foreach ($cards as $card): ?>
                    <?php $card_secret = isset($card->card_secret) ? trim((string) $card->card_secret) : ''; ?>
                    <tr>
                        <td><?php echo esc_html((int) $card->id); ?></td>
                        <td>
                            <?php if (!empty($card->product_title)): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=qls-product-edit&id=' . (int) $card->product_id)); ?>">
                                <?php echo esc_html($card->product_title); ?>
                            </a>
                            <?php else: ?>
                            <?php echo esc_html(sprintf(__('商品 #%d', 'qilingshop'), (int) $card->product_id)); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($card->sku_label ?? sprintf(__('SKU #%d', 'qilingshop'), (int) $card->sku_id)); ?></td>
                        <td><code><?php echo esc_html($card->card_no); ?></code></td>
                        <td><code><?php echo esc_html($card_secret !== '' ? $card_secret : '—'); ?></code></td>
                        <td>
                            <span class="status-badge <?php echo esc_attr(qls_card_inventory()->get_status_badge_class((int) $card->status)); ?>">
                                <?php echo esc_html(qls_card_inventory()->get_status_label((int) $card->status)); ?>
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($card->order_no)): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=qls-shop-orders&s=' . rawurlencode((string) $card->order_no))); ?>">
                                <?php echo esc_html($card->order_no); ?>
                            </a>
                            <?php else: ?>
                            —
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($format_card_time($card->sold_at ?? '')); ?></td>
                        <td><?php echo esc_html($format_card_time($card->created_at ?? '')); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                <tr>
                    <td colspan="9" class="qls-text-center"><?php _e('暂无卡密记录。', 'qilingshop'); ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
