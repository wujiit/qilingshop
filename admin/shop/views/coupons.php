<?php
/**
 * 优惠券管理视图
 */
if (!defined('ABSPATH')) exit;

$view = isset($view) ? $view : 'list';
$apply_scopes = [
    'all' => __('全站通用', 'qilingshop'),
    'resource' => __('文章资源', 'qilingshop'),
    'recharge' => __('积分充值', 'qilingshop'),
    'vip' => __('VIP会员', 'qilingshop'),
    'shop' => __('实物商城', 'qilingshop'),
];
$claim_types = [
    'public' => __('公开展示（需登录领取）', 'qilingshop'),
    'login' => __('登录用户', 'qilingshop'),
    'vip' => __('VIP用户', 'qilingshop'),
];
$coupon_page_slug = isset($coupon_page_slug) ? sanitize_key((string) $coupon_page_slug) : 'qls-shop-coupons';
$coupon_base_args = isset($coupon_base_args) && is_array($coupon_base_args) ? $coupon_base_args : [];
$coupon_embedded = !empty($coupon_embedded);
$coupon_message = isset($_GET['message']) ? sanitize_key((string) wp_unslash($_GET['message'])) : '';
$coupon_admin_url = static function($args = []) use ($coupon_page_slug, $coupon_base_args) {
    return add_query_arg(array_merge(['page' => $coupon_page_slug], $coupon_base_args, $args), admin_url('admin.php'));
};
?>
<div class="<?php echo $coupon_embedded ? 'qls-marketing-panel qls-coupons-embedded' : 'wrap qilingshop-admin-page qls-shop-wrap'; ?>">
    <div class="qls-page-header qls-page-header-inline">
        <?php if ($coupon_embedded): ?>
        <h2 class="wp-heading-inline qls-page-title"><?php _e('优惠券管理', 'qilingshop'); ?></h2>
        <?php else: ?>
        <h1 class="wp-heading-inline qls-page-title"><?php _e('优惠券管理', 'qilingshop'); ?></h1>
        <?php endif; ?>
        <?php if ($view === 'list'): ?>
        <a href="<?php echo esc_url($coupon_admin_url(['view' => 'add'])); ?>" class="page-title-action"><?php _e('添加优惠券', 'qilingshop'); ?></a>
        <?php else: ?>
        <a href="<?php echo esc_url($coupon_admin_url()); ?>" class="page-title-action"><?php _e('返回列表', 'qilingshop'); ?></a>
        <?php endif; ?>
    </div>

    <?php if (in_array($coupon_message, ['saved', 'deleted'], true)): ?>
    <div class="notice notice-success is-dismissible"><p>
        <?php 
        if ($coupon_message === 'saved') _e('保存成功', 'qilingshop');
        elseif ($coupon_message === 'deleted') _e('删除成功', 'qilingshop');
        ?>
    </p></div>
    <?php endif; ?>

    <?php if ($view === 'list'): ?>
    <!-- 筛选 -->
    <div class="qls-toolbar qls-toolbar-between">
        <form method="get" class="qls-filter-form qls-toolbar-search">
            <input type="hidden" name="page" value="<?php echo esc_attr($coupon_page_slug); ?>">
            <?php foreach ($coupon_base_args as $base_key => $base_value): ?>
            <input type="hidden" name="<?php echo esc_attr($base_key); ?>" value="<?php echo esc_attr($base_value); ?>">
            <?php endforeach; ?>
            <select name="scope">
                <option value=""><?php _e('全部类型', 'qilingshop'); ?></option>
                <?php foreach ($apply_scopes as $key => $label): ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected($args['apply_scope'] ?? '', $key); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status">
                <option value=""><?php _e('全部状态', 'qilingshop'); ?></option>
                <option value="1" <?php selected((string) ($args['status'] ?? '') === '1', true); ?>><?php _e('启用', 'qilingshop'); ?></option>
                <option value="0" <?php selected((string) ($args['status'] ?? '') === '0', true); ?>><?php _e('禁用', 'qilingshop'); ?></option>
            </select>
            <input type="search" name="s" value="<?php echo esc_attr($args['search'] ?? ''); ?>" placeholder="<?php _e('搜索优惠券名称或码...', 'qilingshop'); ?>">
            <button type="submit" class="button"><?php _e('筛选', 'qilingshop'); ?></button>
        </form>
    </div>

    <!-- 列表 -->
    <div class="qls-table-shell">
    <table class="wp-list-table qls-ui-table widefat fixed striped qls-coupons-table">
        <thead>
            <tr>
                <th class="column-id"><?php _e('ID', 'qilingshop'); ?></th>
                <th class="column-code"><?php _e('优惠码', 'qilingshop'); ?></th>
                <th class="column-name"><?php _e('名称', 'qilingshop'); ?></th>
                <th class="column-discount"><?php _e('优惠', 'qilingshop'); ?></th>
                <th class="column-scope"><?php _e('适用范围', 'qilingshop'); ?></th>
                <th class="column-condition"><?php _e('使用条件', 'qilingshop'); ?></th>
                <th class="column-count"><?php _e('领取/使用', 'qilingshop'); ?></th>
                <th class="column-time"><?php _e('有效期', 'qilingshop'); ?></th>
                <th class="column-status"><?php _e('状态', 'qilingshop'); ?></th>
                <th class="column-actions"><?php _e('操作', 'qilingshop'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($coupons)): ?>
            <tr>
                <td colspan="10" class="no-items"><?php _e('暂无优惠券', 'qilingshop'); ?></td>
            </tr>
            <?php else: ?>
            <?php foreach ($coupons as $c): ?>
            <tr>
                <td class="column-id"><code><?php echo esc_html((string) $c->id); ?></code></td>
                <td class="column-code">
                    <code><?php echo esc_html($c->code); ?></code>
                </td>
                <td class="column-name">
                    <strong><?php echo esc_html($c->name); ?></strong>
                    <?php if ($c->description): ?>
                    <div class="row-desc"><small><?php echo esc_html(mb_substr($c->description, 0, 30)); ?></small></div>
                    <?php endif; ?>
                </td>
                <td class="column-discount">
                    <?php if ($c->discount_type === 'fixed'): ?>
                    <span class="discount-value">¥<?php echo number_format($c->discount_value, 2); ?></span>
                    <?php else: ?>
                    <span class="discount-value"><?php echo $c->discount_value; ?>%<?php if ($c->max_discount): ?><small><?php printf(esc_html__('(最高¥%s)', 'qilingshop'), esc_html($c->max_discount)); ?></small><?php endif; ?></span>
                    <?php endif; ?>
                </td>
                <td class="column-scope">
                    <?php echo esc_html($apply_scopes[$c->apply_scope] ?? $c->apply_scope); ?>
                </td>
                <td class="column-condition">
                    <?php if ($c->min_amount > 0): ?>
                    <?php printf(__('满¥%s可用', 'qilingshop'), number_format($c->min_amount, 2)); ?>
                    <?php else: ?>
                    <?php _e('无门槛', 'qilingshop'); ?>
                    <?php endif; ?>
                </td>
                <td class="column-count">
                    <?php echo $c->claimed_count; ?> / <?php echo $c->used_count; ?>
                    <?php if ($c->total_count > 0): ?>
                    <small><?php printf(esc_html__('(限%s张)', 'qilingshop'), esc_html($c->total_count)); ?></small>
                    <?php endif; ?>
                </td>
                <td class="column-time">
                    <?php if ($c->valid_type === 'days'): ?>
                    <?php printf(__('领取后%d天', 'qilingshop'), $c->valid_days); ?>
                    <?php else: ?>
                    <?php 
                    if ($c->start_time && $c->end_time) {
                        echo date('m/d', strtotime($c->start_time)) . ' - ' . date('m/d', strtotime($c->end_time));
                    } elseif ($c->end_time) {
                        printf(__('至 %s', 'qilingshop'), date('m/d', strtotime($c->end_time)));
                    } else {
                        _e('永久有效', 'qilingshop');
                    }
                    ?>
                    <?php endif; ?>
                </td>
                <td class="column-status">
                    <?php if ($c->status): ?>
                    <span class="status-badge status-active"><?php _e('启用', 'qilingshop'); ?></span>
                    <?php else: ?>
                    <span class="status-badge status-inactive"><?php _e('禁用', 'qilingshop'); ?></span>
                    <?php endif; ?>
                </td>
                <td class="column-actions">
                    <a href="<?php echo esc_url($coupon_admin_url(['view' => 'edit', 'id' => (int) $c->id])); ?>"><?php _e('编辑', 'qilingshop'); ?></a> |
                    <a href="<?php echo esc_url(wp_nonce_url($coupon_admin_url(['action' => 'delete', 'id' => (int) $c->id]), 'delete_coupon_' . (int) $c->id)); ?>" 
                       onclick="return confirm('<?php _e('确定删除此优惠券？', 'qilingshop'); ?>')" 
                       class="delete"><?php _e('删除', 'qilingshop'); ?></a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- 分页 -->
    <div class="qls-table-pagination tablenav bottom">
        <div class="tablenav-pages">
            <?php
            $total_pages = ceil($total / 20);
            $current_page = (($args['offset'] ?? 0) / 20) + 1;
            
            if ($total_pages > 1) {
                $pagination_args = [
                    'scope' => $args['apply_scope'] ?? '',
                    's'     => $args['search'] ?? '',
                    'paged' => '%#%',
                ];
                if (($args['status'] ?? null) !== null) {
                    $pagination_args['status'] = (string) $args['status'];
                }
                echo paginate_links([
                    'base'      => $coupon_admin_url($pagination_args),
                    'format'    => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'total'     => $total_pages,
                    'current'   => $current_page,
                ]);
            }
            ?>
        </div>
    </div>
    </div>

    <?php else: ?>
    <!-- 添加/编辑表单 -->
    <form method="post" class="qls-coupon-form">
        <?php wp_nonce_field('qls_save_coupon', 'qls_coupon_nonce'); ?>
        <input type="hidden" name="coupon_id" value="<?php echo esc_attr($coupon->id ?? 0); ?>">

        <div class="qls-form-grid">
            <!-- 基础信息 -->
            <div class="qls-form-section">
                <h3><?php _e('基础信息', 'qilingshop'); ?></h3>
                
                <table class="form-table qls-ui-form-table">
                    <tr>
                        <th><label for="name"><?php _e('优惠券名称', 'qilingshop'); ?></label></th>
                        <td>
                            <input type="text" name="name" id="name" class="regular-text" 
                                   value="<?php echo esc_attr($coupon->name ?? ''); ?>" required>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="code"><?php _e('优惠码', 'qilingshop'); ?></label></th>
                        <td>
                            <input type="text" name="code" id="code" class="regular-text" 
                                   value="<?php echo esc_attr($coupon->code ?? ''); ?>" 
                                   placeholder="<?php _e('留空自动生成', 'qilingshop'); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="description"><?php _e('描述', 'qilingshop'); ?></label></th>
                        <td>
                            <textarea name="description" id="description" rows="3" class="large-text"><?php echo esc_textarea($coupon->description ?? ''); ?></textarea>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- 优惠设置 -->
            <div class="qls-form-section">
                <h3><?php _e('优惠设置', 'qilingshop'); ?></h3>
                
                <table class="form-table qls-ui-form-table">
                    <tr>
                        <th><label><?php _e('优惠类型', 'qilingshop'); ?></label></th>
                        <td>
                            <label>
                                <input type="radio" name="discount_type" value="fixed" <?php checked(($coupon->discount_type ?? 'fixed'), 'fixed'); ?>>
                                <?php _e('固定金额', 'qilingshop'); ?>
                            </label>
                            <label class="qls-ml-20">
                                <input type="radio" name="discount_type" value="percent" <?php checked(($coupon->discount_type ?? ''), 'percent'); ?>>
                                <?php _e('百分比折扣', 'qilingshop'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="discount_value"><?php _e('优惠数值', 'qilingshop'); ?></label></th>
                        <td>
                            <input type="number" name="discount_value" id="discount_value" step="0.01" min="0" 
                                   value="<?php echo esc_attr($coupon->discount_value ?? ''); ?>" class="small-text" required>
                            <span class="description" id="discount-hint"><?php _e('元 或 %', 'qilingshop'); ?></span>
                        </td>
                    </tr>
                    <tr class="max-discount-row<?php echo ($coupon->discount_type ?? '') !== 'percent' ? ' qls-hidden' : ''; ?>">
                        <th><label for="max_discount"><?php _e('最高优惠', 'qilingshop'); ?></label></th>
                        <td>
                            <input type="number" name="max_discount" id="max_discount" step="0.01" min="0" 
                                   value="<?php echo esc_attr($coupon->max_discount ?? ''); ?>" class="small-text">
                            <span class="description"><?php _e('元（百分比折扣时限定，0表示不限）', 'qilingshop'); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('叠加策略', 'qilingshop'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="stack_with_vip" value="1" <?php checked(($coupon->stack_with_vip ?? 1) == 0); ?>>
                                <?php _e('不可与VIP折扣叠加（自动取更优价格）', 'qilingshop'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="min_amount"><?php _e('最低消费', 'qilingshop'); ?></label></th>
                        <td>
                            <input type="number" name="min_amount" id="min_amount" step="0.01" min="0" 
                                   value="<?php echo esc_attr($coupon->min_amount ?? 0); ?>" class="small-text">
                            <span class="description"><?php _e('元（0表示无门槛）', 'qilingshop'); ?></span>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- 适用范围 -->
            <div class="qls-form-section">
                <h3><?php _e('适用范围', 'qilingshop'); ?></h3>
                
                <table class="form-table qls-ui-form-table">
                    <tr>
                        <th><label for="apply_scope"><?php _e('使用场景', 'qilingshop'); ?></label></th>
                        <td>
                            <select name="apply_scope" id="apply_scope">
                                <?php foreach ($apply_scopes as $key => $label): ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected(($coupon->apply_scope ?? 'all'), $key); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr class="apply-items-row qls-hidden">
                        <th><label for="apply_items"><?php _e('指定商品/文章', 'qilingshop'); ?></label></th>
                        <td>
                            <input type="text" name="apply_items" id="apply_items" class="regular-text" 
                                   value="<?php echo esc_attr(is_array($coupon->apply_items ?? null) ? implode(',', $coupon->apply_items) : ''); ?>"
                                   placeholder="<?php _e('多个ID用逗号分隔，留空表示全部', 'qilingshop'); ?>">
                        </td>
                    </tr>
                    <tr class="apply-categories-row qls-hidden">
                        <th><label><?php _e('指定分类', 'qilingshop'); ?></label></th>
                        <td>
                            <?php
                            $selected_categories = is_array($coupon->apply_categories ?? null) ? $coupon->apply_categories : [];
                            $resource_categories = get_terms([
                                'taxonomy'   => 'category',
                                'hide_empty' => false,
                            ]);
                            $shop_categories = function_exists('qls_category') ? qls_category()->get_flat_tree() : [];
                            ?>
                            <div class="apply-categories-resource qls-hidden">
                                <select name="apply_categories_resource[]" multiple class="regular-text">
                                    <?php if (!is_wp_error($resource_categories) && !empty($resource_categories)) : ?>
                                        <?php foreach ($resource_categories as $cat) : ?>
                                            <option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected(in_array($cat->term_id, $selected_categories, true)); ?>>
                                                <?php echo esc_html($cat->name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <option value=""><?php _e('暂无分类', 'qilingshop'); ?></option>
                                    <?php endif; ?>
                                </select>
                                <p class="description"><?php _e('资源分类（文章分类）', 'qilingshop'); ?></p>
                            </div>
                            <div class="apply-categories-shop qls-hidden">
                                <select name="apply_categories_shop[]" multiple class="regular-text">
                                    <?php if (!empty($shop_categories)) : ?>
                                        <?php foreach ($shop_categories as $cat) : ?>
                                            <option value="<?php echo esc_attr($cat->id); ?>" <?php selected(in_array((int) $cat->id, $selected_categories, true)); ?>>
                                                <?php echo esc_html($cat->display_name ?? $cat->name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <option value=""><?php _e('暂无商品分类', 'qilingshop'); ?></option>
                                    <?php endif; ?>
                                </select>
                                <p class="description"><?php _e('商城分类', 'qilingshop'); ?></p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php _e('可使用的VIP等级', 'qilingshop'); ?></label></th>
                        <td>
                            <?php
                            $vip_levels = QilingShop_VIP::instance()->get_levels(true);
                            $use_levels = is_array($coupon->use_vip_levels ?? null)
                                ? $coupon->use_vip_levels
                                : [];
                            if (!empty($vip_levels)):
                            foreach ($vip_levels as $level):
                            ?>
                            <label class="qls-checkbox-inline">
                                <input type="checkbox" name="use_vip_levels[]"
                                       value="<?php echo esc_attr($level->id); ?>"
                                       <?php checked(in_array($level->id, $use_levels, true)); ?>>
                                <?php echo esc_html($level->level_name); ?>
                            </label>
                            <?php endforeach; ?>
                            <p class="description"><?php _e('勾选后仅这些VIP等级可使用，留空表示不限制', 'qilingshop'); ?></p>
                            <?php else: ?>
                            <p class="description"><?php _e('暂无VIP等级，请先在VIP设置中创建等级', 'qilingshop'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- 有效期 -->
            <div class="qls-form-section">
                <h3><?php _e('有效期设置', 'qilingshop'); ?></h3>
                
                <table class="form-table qls-ui-form-table">
                    <tr>
                        <th><label><?php _e('有效期类型', 'qilingshop'); ?></label></th>
                        <td>
                            <label>
                                <input type="radio" name="valid_type" value="fixed" <?php checked(($coupon->valid_type ?? 'fixed'), 'fixed'); ?>>
                                <?php _e('固定日期范围', 'qilingshop'); ?>
                            </label>
                            <label class="qls-ml-20">
                                <input type="radio" name="valid_type" value="days" <?php checked(($coupon->valid_type ?? ''), 'days'); ?>>
                                <?php _e('领取后N天有效', 'qilingshop'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr class="fixed-date-row<?php echo ($coupon->valid_type ?? 'fixed') !== 'fixed' ? ' qls-hidden' : ''; ?>">
                        <th><label><?php _e('有效期限', 'qilingshop'); ?></label></th>
                        <td>
                            <input type="datetime-local" name="start_time" value="<?php echo esc_attr(!empty($coupon->start_time) ? date('Y-m-d\TH:i', strtotime($coupon->start_time)) : ''); ?>">
                            <span>~</span>
                            <input type="datetime-local" name="end_time" value="<?php echo esc_attr(!empty($coupon->end_time) ? date('Y-m-d\TH:i', strtotime($coupon->end_time)) : ''); ?>">
                        </td>
                    </tr>
                    <tr class="valid-days-row<?php echo ($coupon->valid_type ?? 'fixed') !== 'days' ? ' qls-hidden' : ''; ?>">
                        <th><label for="valid_days"><?php _e('有效天数', 'qilingshop'); ?></label></th>
                        <td>
                            <input type="number" name="valid_days" id="valid_days" min="1" 
                                   value="<?php echo esc_attr($coupon->valid_days ?? ''); ?>" class="small-text">
                            <span class="description"><?php _e('天', 'qilingshop'); ?></span>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- 领取设置 -->
            <div class="qls-form-section">
                <h3><?php _e('领取设置', 'qilingshop'); ?></h3>
                
                <table class="form-table qls-ui-form-table">
                    <tr>
                        <th><label for="claim_type"><?php _e('领取权限', 'qilingshop'); ?></label></th>
                        <td>
                            <select name="claim_type" id="claim_type">
                                <?php foreach ($claim_types as $key => $label): ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected(($coupon->claim_type ?? 'public'), $key); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr class="vip-level-row<?php echo ($coupon->claim_type ?? '') !== 'vip' ? ' qls-hidden' : ''; ?>">
                        <th><label><?php _e('可领取的VIP等级', 'qilingshop'); ?></label></th>
                        <td>
                            <?php 
                            // 获取VIP等级列表
                            $vip_levels = QilingShop_VIP::instance()->get_levels(true);
                            $selected_levels = !empty($coupon->allowed_vip_levels) ? (array)json_decode($coupon->allowed_vip_levels, true) : [];
                            // 兼容旧版 min_vip_level 字段
                            if (empty($selected_levels) && !empty($coupon->min_vip_level)) {
                                foreach ($vip_levels as $level) {
                                    if ($level->id >= $coupon->min_vip_level) {
                                        $selected_levels[] = $level->id;
                                    }
                                }
                            }
                            
                            if (!empty($vip_levels)):
                            foreach ($vip_levels as $level): 
                            ?>
                            <label class="qls-checkbox-inline">
                                <input type="checkbox" name="allowed_vip_levels[]" 
                                       value="<?php echo esc_attr($level->id); ?>" 
                                       <?php checked(in_array($level->id, $selected_levels)); ?>>
                                <?php echo esc_html($level->level_name); ?>
                            </label>
                            <?php endforeach; ?>
                            <p class="description"><?php _e('勾选允许领取此优惠券的VIP等级，不勾选则所有VIP用户都可领取', 'qilingshop'); ?></p>
                            <?php else: ?>
                            <p class="description"><?php _e('暂无VIP等级，请先在VIP设置中创建等级', 'qilingshop'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="total_count"><?php _e('发放总量', 'qilingshop'); ?></label></th>
                        <td>
                            <input type="number" name="total_count" id="total_count" min="-1" 
                                   value="<?php echo esc_attr($coupon->total_count ?? -1); ?>" class="small-text">
                            <span class="description"><?php _e('-1表示无限制', 'qilingshop'); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="per_user_limit"><?php _e('每人限领', 'qilingshop'); ?></label></th>
                        <td>
                            <input type="number" name="per_user_limit" id="per_user_limit" min="1" 
                                   value="<?php echo esc_attr($coupon->per_user_limit ?? 1); ?>" class="small-text">
                            <span class="description"><?php _e('张', 'qilingshop'); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('新人首单券', 'qilingshop'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="first_order_only" id="first_order_only" value="1" <?php checked((int) ($coupon->first_order_only ?? 0), 1); ?>>
                                <?php _e('仅允许首单用户使用（仅登录用户）', 'qilingshop'); ?>
                            </label>
                            <p class="description"><?php _e('开启后会在领取和下单两处校验；且该券自动按“每人限领 1 张”处理。', 'qilingshop'); ?></p>
                        </td>
                    </tr>
                    <tr class="first-order-scope-row<?php echo ((int) ($coupon->first_order_only ?? 0) === 1) ? '' : ' qls-hidden'; ?>">
                        <th><label for="first_order_scope"><?php _e('首单判断范围', 'qilingshop'); ?></label></th>
                        <td>
                            <?php
                            $first_order_scope_raw = sanitize_key((string) ($coupon->first_order_scope ?? 'same_scope'));
                            $first_order_scope = in_array($first_order_scope_raw, ['same_scope', 'all'], true) ? $first_order_scope_raw : 'same_scope';
                            ?>
                            <select name="first_order_scope" id="first_order_scope">
                                <option value="same_scope" <?php selected($first_order_scope, 'same_scope'); ?>><?php _e('按当前券适用场景判断首单', 'qilingshop'); ?></option>
                                <option value="all" <?php selected($first_order_scope, 'all'); ?>><?php _e('按全站任意消费判断首单', 'qilingshop'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- 状态 -->
            <div class="qls-form-section">
                <h3><?php _e('状态设置', 'qilingshop'); ?></h3>
                
                <table class="form-table qls-ui-form-table">
                    <tr>
                        <th><?php _e('启用状态', 'qilingshop'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="status" value="1" <?php checked(($coupon->status ?? 1), 1); ?>>
                                <?php _e('启用此优惠券', 'qilingshop'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('前台展示', 'qilingshop'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="is_visible" value="1" <?php checked(($coupon->is_visible ?? 1), 1); ?>>
                                <?php _e('在优惠券中心显示', 'qilingshop'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sort_order"><?php _e('排序', 'qilingshop'); ?></label></th>
                        <td>
                            <input type="number" name="sort_order" id="sort_order" min="0" 
                                   value="<?php echo esc_attr($coupon->sort_order ?? 0); ?>" class="small-text">
                            <span class="description"><?php _e('数字越小越靠前', 'qilingshop'); ?></span>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <p class="submit">
            <button type="submit" class="button button-primary button-large"><?php _e('保存优惠券', 'qilingshop'); ?></button>
        </p>
    </form>
    <?php endif; ?>
</div>

<script>
jQuery(function($) {
    // 优惠类型切换
    $('input[name="discount_type"]').on('change', function() {
        if ($(this).val() === 'percent') {
            $('.max-discount-row').show();
            $('#discount-hint').text('%');
        } else {
            $('.max-discount-row').hide();
            $('#discount-hint').text('<?php echo esc_js(__('元', 'qilingshop')); ?>');
        }
    });

    // 有效期类型切换
    $('input[name="valid_type"]').on('change', function() {
        if ($(this).val() === 'days') {
            $('.fixed-date-row').hide();
            $('.valid-days-row').show();
        } else {
            $('.fixed-date-row').show();
            $('.valid-days-row').hide();
        }
    });

    // 领取权限切换
    $('#claim_type').on('change', function() {
        if ($(this).val() === 'vip') {
            $('.vip-level-row').show();
        } else {
            $('.vip-level-row').hide();
        }
    });

    // 新人首单券开关
    $('#first_order_only').on('change', function() {
        if ($(this).is(':checked')) {
            $('.first-order-scope-row').show();
            $('#per_user_limit').val(1);
        } else {
            $('.first-order-scope-row').hide();
        }
    });

    // 适用范围切换 - 只有文章资源和实物商城才显示指定商品/文章
    $('#apply_scope').on('change', function() {
        var val = $(this).val();
        if (val === 'resource' || val === 'shop') {
            $('.apply-items-row').show();
            $('.apply-categories-row').show();
        } else {
            $('.apply-items-row').hide();
            $('.apply-categories-row').hide();
        }
        if (val === 'resource') {
            $('.apply-categories-resource').show();
            $('.apply-categories-shop').hide();
        } else if (val === 'shop') {
            $('.apply-categories-resource').hide();
            $('.apply-categories-shop').show();
        } else {
            $('.apply-categories-resource').hide();
            $('.apply-categories-shop').hide();
        }
    });
    $('#first_order_only').trigger('change');
    // 触发初始状态
    $('#apply_scope').trigger('change');
});
</script>
