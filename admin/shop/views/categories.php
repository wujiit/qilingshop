<?php
/**
 * 分类管理视图
 */
if (!defined('ABSPATH')) exit;
$category_product_count_map = isset($category_product_count_map) && is_array($category_product_count_map) ? $category_product_count_map : [];
?>
<div class="wrap qilingshop-admin-page qls-shop-wrap">
    <h1><?php _e('分类管理', 'qilingshop'); ?></h1>
    
    <?php if (isset($_GET['message'])): ?>
    <div class="notice notice-success is-dismissible">
        <p><?php _e('操作成功', 'qilingshop'); ?></p>
    </div>
    <?php endif; ?>
    
    <div class="qls-categories-layout">
        <!-- 添加/编辑表单 -->
        <div class="qls-category-form-wrap">
            <h2><?php echo $edit_category ? __('编辑分类', 'qilingshop') : __('添加分类', 'qilingshop'); ?></h2>
            
            <form method="post">
                <?php wp_nonce_field('qls_save_category', 'qls_category_nonce'); ?>
                
                <?php if ($edit_category): ?>
                <input type="hidden" name="category_id" value="<?php echo esc_attr($edit_category->id); ?>">
                <?php endif; ?>
                
                <div class="form-field form-required">
                    <label for="name"><?php _e('分类名称', 'qilingshop'); ?></label>
                    <input type="text" name="name" id="name" aria-required="true" value="<?php echo esc_attr($edit_category->name ?? ''); ?>">
                    <p class="description"><?php _e('这将是它在站点上显示的名字', 'qilingshop'); ?></p>
                </div>

                <div class="form-field">
                    <label for="slug"><?php _e('别名', 'qilingshop'); ?></label>
                    <input type="text" name="slug" id="slug" value="<?php echo esc_attr($edit_category->slug ?? ''); ?>">
                    <p class="description"><?php _e('“别名”是在链接中使用的别称，通常为小写，只能包含字母、数字和连字符（-）', 'qilingshop'); ?></p>
                </div>

                <div class="form-field">
                    <label for="parent_id"><?php _e('父分类', 'qilingshop'); ?></label>
                    <select name="parent_id" id="parent_id">
                        <option value="0"><?php _e('无（顶级分类）', 'qilingshop'); ?></option>
                        <?php foreach ($categories as $cat): ?>
                            <?php if ($edit_category && $cat->id == $edit_category->id) continue; ?>
                            <option value="<?php echo esc_attr($cat->id); ?>" <?php selected($edit_category->parent_id ?? 0, $cat->id); ?>>
                                <?php echo esc_html($cat->display_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php _e('分类目录和标签不同，它可以有层级关系', 'qilingshop'); ?></p>
                </div>

                <div class="form-field">
                    <label><?php _e('分类图片', 'qilingshop'); ?></label>
                    <div class="qls-media-upload small">
                        <div class="qls-media-preview">
                            <?php if (!empty($edit_category->image)): ?>
                                <img src="<?php echo esc_url($edit_category->image); ?>" alt="">
                            <?php endif; ?>
                        </div>
                        <input type="hidden" name="image" value="<?php echo esc_attr($edit_category->image ?? ''); ?>">
                        <button type="button" class="button qls-upload-btn" data-target="image"><?php _e('选择图片', 'qilingshop'); ?></button>
                        <button type="button" class="button qls-remove-btn" data-target="image"><?php _e('移除', 'qilingshop'); ?></button>
                    </div>
                </div>

                <div class="form-field">
                    <label for="description"><?php _e('描述', 'qilingshop'); ?></label>
                    <textarea name="description" id="description" rows="5" cols="40"><?php echo esc_textarea($edit_category->description ?? ''); ?></textarea>
                    <p class="description"><?php _e('描述会作为页面搜索摘要使用', 'qilingshop'); ?></p>
                </div>

                <div class="form-field">
                    <label for="seo_keywords"><?php _e('搜索关键词', 'qilingshop'); ?></label>
                    <input type="text" name="seo_keywords" id="seo_keywords" value="<?php echo esc_attr($edit_category->seo_keywords ?? ''); ?>">
                    <p class="description"><?php _e('多个关键词用半角逗号分隔', 'qilingshop'); ?></p>
                </div>

                <div class="form-field">
                    <label for="sort_order"><?php _e('排序', 'qilingshop'); ?></label>
                    <input type="number" name="sort_order" id="sort_order" class="qls-w-100" value="<?php echo esc_attr($edit_category->sort_order ?? 0); ?>">
                </div>

                <div class="form-field">
                    <label for="status"><?php _e('状态', 'qilingshop'); ?></label>
                    <select name="status" id="status">
                        <option value="1" <?php selected($edit_category->status ?? 1, 1); ?>><?php _e('启用', 'qilingshop'); ?></option>
                        <option value="0" <?php selected($edit_category->status ?? 1, 0); ?>><?php _e('禁用', 'qilingshop'); ?></option>
                    </select>
                </div>
                
                <p class="submit">
                    <button type="submit" class="button button-primary"><?php _e('保存分类', 'qilingshop'); ?></button>
                    <?php if ($edit_category): ?>
                    <a href="<?php echo admin_url('admin.php?page=qls-categories'); ?>" class="button"><?php _e('取消', 'qilingshop'); ?></a>
                    <?php endif; ?>
                </p>
            </form>
        </div>
        
        <!-- 分类列表 -->
        <div class="qls-categories-list-wrap">
            <h2><?php _e('分类列表', 'qilingshop'); ?></h2>
            
            <table class="wp-list-table qls-ui-table widefat fixed striped">
                <thead>
                    <tr>
                        <th class="column-name"><?php _e('名称', 'qilingshop'); ?></th>
                        <th class="column-slug"><?php _e('别名', 'qilingshop'); ?></th>
                        <th class="column-count"><?php _e('商品数', 'qilingshop'); ?></th>
                        <th class="column-status"><?php _e('状态', 'qilingshop'); ?></th>
                        <th class="column-actions"><?php _e('操作', 'qilingshop'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($categories)): ?>
                    <tr>
                        <td colspan="5" class="no-items"><?php _e('暂无分类', 'qilingshop'); ?></td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($categories as $cat): ?>
                    <tr>
                        <td class="column-name">
                            <strong><?php echo esc_html($cat->display_name); ?></strong>
                        </td>
                        <td class="column-slug"><?php echo esc_html($cat->slug); ?></td>
                        <td class="column-count"><?php echo esc_html((int) ($category_product_count_map[(int) $cat->id] ?? 0)); ?></td>
                        <td class="column-status">
                            <?php if ($cat->status): ?>
                            <span class="status-badge success"><?php _e('启用', 'qilingshop'); ?></span>
                            <?php else: ?>
                            <span class="status-badge"><?php _e('禁用', 'qilingshop'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="column-actions">
                            <a href="<?php echo admin_url('admin.php?page=qls-categories&edit=' . $cat->id); ?>"><?php _e('编辑', 'qilingshop'); ?></a> |
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=qls-categories&action=delete&id=' . $cat->id), 'delete_category_' . $cat->id); ?>" class="delete-link"><?php _e('删除', 'qilingshop'); ?></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
