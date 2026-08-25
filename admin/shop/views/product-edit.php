<?php
/**
 * 商品编辑视图
 */
if (!defined('ABSPATH')) exit;

$is_edit = !empty($product);
$page_title = $is_edit ? __('编辑商品', 'qilingshop') : __('添加商品', 'qilingshop');
$vip_levels = isset($vip_levels) && is_array($vip_levels) ? $vip_levels : [];
$has_vip_levels = !empty($vip_levels);
?>
<div class="wrap qilingshop-admin-page qls-shop-wrap">
    <h1><?php echo esc_html($page_title); ?></h1>
    
    <?php if (isset($_GET['message']) && $_GET['message'] === 'saved'): ?>
    <div class="notice notice-success is-dismissible">
        <p><?php _e('商品已保存', 'qilingshop'); ?></p>
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['message']) && $_GET['message'] === 'sku_deleted'): ?>
    <div class="notice notice-success is-dismissible">
        <p><?php _e('SKU 已删除', 'qilingshop'); ?></p>
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['message']) && $_GET['message'] === 'sku_delete_failed'): ?>
    <div class="notice notice-error is-dismissible">
        <p><?php _e('SKU 删除失败，请重试', 'qilingshop'); ?></p>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($save_error)): ?>
    <div class="notice notice-error is-dismissible">
        <p><?php echo esc_html($save_error); ?></p>
    </div>
    <?php endif; ?>
    
    <form method="post" class="qls-product-form" id="qls-product-form">
        <?php wp_nonce_field('qls_save_product', 'qls_product_nonce'); ?>
        
        <div class="qls-form-columns">
            <!-- 左侧主内容 -->
            <div class="qls-form-main">
                <!-- 基本信息 -->
                <div class="qls-form-section">
                    <h2><?php _e('基本信息', 'qilingshop'); ?></h2>
                    
                    <table class="form-table qls-ui-form-table">
                        <tr>
                            <th><label for="title"><?php _e('商品标题', 'qilingshop'); ?> <span class="required">*</span></label></th>
                            <td>
                                <input type="text" name="title" id="title" class="large-text" required
                                       value="<?php echo esc_attr($product->title ?? ''); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="subtitle"><?php _e('副标题', 'qilingshop'); ?></label></th>
                            <td>
                                <input type="text" name="subtitle" id="subtitle" class="large-text"
                                       value="<?php echo esc_attr($product->subtitle ?? ''); ?>">
                                <p class="description"><?php _e('简短的商品卖点描述', 'qilingshop'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="slug"><?php _e('链接别名', 'qilingshop'); ?></label></th>
                            <td>
                                <input type="text" name="slug" id="slug" class="regular-text"
                                       value="<?php echo esc_attr($product->slug ?? ''); ?>">
                                <p class="description"><?php _e('留空自动生成', 'qilingshop'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- 商品图片/视频 -->
                <div class="qls-form-section">
                    <h2><?php _e('商品图片/视频', 'qilingshop'); ?></h2>
                    <p class="description"><?php _e('第一张图片为商品封面，支持添加多张图片和视频。建议使用 1:1 比例（推荐 1200×1200px，至少 800×800px），可获得最佳展示效果。', 'qilingshop'); ?></p>
                    
                    <div class="qls-gallery-upload" id="product-gallery">
                        <div class="qls-gallery-items">
                            <?php 
                            $gallery = $product->gallery ?? [];
                            if (!empty($gallery)):
                            foreach ($gallery as $gi => $media): 
                            $media_url = is_array($media) ? ($media['url'] ?? '') : $media;
                            $media_type = is_array($media) ? ($media['type'] ?? 'image') : 'image';
                            $media_cover = is_array($media) ? ($media['cover'] ?? '') : '';
                            ?>
                            <div class="qls-gallery-item <?php echo $gi === 0 ? 'is-cover' : ''; ?>" data-type="<?php echo esc_attr($media_type); ?>">
                                <?php if ($media_type === 'video'): ?>
                                <div class="qls-video-thumb">
                                    <?php if ($media_cover): ?>
                                    <img src="<?php echo esc_url($media_cover); ?>" alt="">
                                    <?php else: ?>
                                    <span class="dashicons dashicons-video-alt3"></span>
                                    <?php endif; ?>
                                </div>
                                <input type="hidden" name="gallery[<?php echo $gi; ?>][url]" value="<?php echo esc_attr($media_url); ?>">
                                <input type="hidden" name="gallery[<?php echo $gi; ?>][type]" value="video">
                                <input type="hidden" name="gallery[<?php echo $gi; ?>][cover]" value="<?php echo esc_attr($media_cover); ?>">
                                <?php else: ?>
                                <img src="<?php echo esc_url($media_url); ?>" alt="">
                                <input type="hidden" name="gallery[<?php echo $gi; ?>][url]" value="<?php echo esc_attr($media_url); ?>">
                                <input type="hidden" name="gallery[<?php echo $gi; ?>][type]" value="image">
                                <?php endif; ?>
                                <span class="qls-remove-item">&times;</span>
                                <?php if ($gi === 0): ?><span class="qls-cover-badge"><?php _e('封面', 'qilingshop'); ?></span><?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="qls-gallery-actions">
                            <button type="button" class="button qls-add-gallery-image"><?php _e('添加图片', 'qilingshop'); ?></button>
                            <button type="button" class="button qls-add-gallery-video"><?php _e('添加视频', 'qilingshop'); ?></button>
                        </div>
                        <p class="description"><?php _e('拖拽可排序，第一个为封面图', 'qilingshop'); ?></p>
                    </div>
                </div>
                
                <!-- 商品规格 -->
                <div class="qls-form-section">
                    <h2><?php _e('商品规格', 'qilingshop'); ?></h2>
                    <p class="description"><?php _e('设置规格（如颜色）、规格值（如白色）、版本值（如128G），规格值可上传图片', 'qilingshop'); ?></p>
                    
                    <div id="qls-attributes-container">
                        <?php if (!empty($product->attributes)): ?>
                        <?php foreach ($product->attributes as $index => $attr): ?>
                        <div class="qls-attribute-row" data-index="<?php echo $index; ?>">
                            <div class="qls-attr-header">
                                <input type="hidden" name="attributes[<?php echo $index; ?>][id]" value="<?php echo esc_attr($attr->id); ?>">
                                <input type="text" name="attributes[<?php echo $index; ?>][name]" class="attr-name" placeholder="<?php _e('规格名称（如：颜色）', 'qilingshop'); ?>" value="<?php echo esc_attr($attr->name); ?>">
                                <button type="button" class="button qls-remove-attr"><?php _e('删除规格', 'qilingshop'); ?></button>
                            </div>
                            <div class="qls-attr-values">
                                <?php if (!empty($attr->values)): ?>
                                <?php foreach ($attr->values as $vi => $val): ?>
                                <div class="qls-attr-value-row" data-value-index="<?php echo $vi; ?>">
                                    <div class="qls-value-main">
                                        <input type="hidden" name="attributes[<?php echo $index; ?>][values][<?php echo $vi; ?>][id]" value="<?php echo esc_attr($val->id); ?>">
                                        <input type="text" name="attributes[<?php echo $index; ?>][values][<?php echo $vi; ?>][value]" class="qls-value-input" placeholder="<?php _e('规格值（如：白色）', 'qilingshop'); ?>" value="<?php echo esc_attr($val->value); ?>">
                                        
                                        <!-- 规格值图片 -->
                                        <div class="qls-value-image">
                                            <?php $val_image = $val->image ?? ''; ?>
                                            <div class="qls-value-image-preview">
                                                <?php if ($val_image): ?>
                                                <img src="<?php echo esc_url($val_image); ?>" alt="">
                                                <?php endif; ?>
                                            </div>
                                            <input type="hidden" name="attributes[<?php echo $index; ?>][values][<?php echo $vi; ?>][image]" value="<?php echo esc_attr($val_image); ?>">
                                            <button type="button" class="button button-small qls-upload-value-image"><?php _e('图片', 'qilingshop'); ?></button>
                                        </div>
                                        
                                        <span class="qls-remove-value">&times;</span>
                                    </div>
                                    
                                    <!-- 版本值 -->
                                    <div class="qls-versions-container">
                                        <label><?php _e('版本值：', 'qilingshop'); ?></label>
                                        <div class="qls-versions-list">
                                            <?php 
                                            $versions = $val->versions ?? [];
                                            if (!empty($versions)):
                                            foreach ($versions as $vvi => $version): 
                                            ?>
                                            <span class="qls-version-tag">
                                                <input type="text" name="attributes[<?php echo $index; ?>][values][<?php echo $vi; ?>][versions][]" value="<?php echo esc_attr($version); ?>" placeholder="<?php _e('如：128G', 'qilingshop'); ?>">
                                                <span class="qls-remove-version">&times;</span>
                                            </span>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                        <button type="button" class="button button-small qls-add-version"><?php _e('+版本', 'qilingshop'); ?></button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="button button-small qls-add-value"><?php _e('添加规格值', 'qilingshop'); ?></button>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="qls-attr-actions">
                        <button type="button" class="button" id="qls-add-attribute"><?php _e('添加规格', 'qilingshop'); ?></button>
                        <button type="button" class="button button-primary" id="qls-generate-skus"><?php _e('生成SKU组合', 'qilingshop'); ?></button>
                    </div>
                </div>
                
                <!-- SKU列表 -->
                <div class="qls-form-section">
                    <h2><?php _e('SKU管理', 'qilingshop'); ?></h2>
                    
                    <div id="qls-skus-container">
                        <?php if (!empty($product->skus)): ?>
                        
                        <!-- 批量设置工具栏 -->
                        <div class="qls-batch-settings qls-batch-settings-toolbar">
                            <strong><?php _e('批量设置：', 'qilingshop'); ?></strong>
                            <input type="number" id="batch-price" class="small-text" placeholder="<?php _e('价格', 'qilingshop'); ?>" step="0.01" min="0">
                            <input type="number" id="batch-sale-price" class="small-text" placeholder="<?php _e('促销价', 'qilingshop'); ?>" step="0.01" min="0">
                            <input type="number" id="batch-points-price" class="small-text" placeholder="<?php _e('积分价', 'qilingshop'); ?>" step="0.01" min="0">
                            <?php if ($has_vip_levels): ?>
                                <span class="qls-batch-vip-prices">
                                    <?php foreach ($vip_levels as $level): ?>
                                        <input type="number"
                                               class="small-text batch-vip-price"
                                               data-level-id="<?php echo esc_attr($level->id); ?>"
                                               placeholder="<?php echo esc_attr($level->level_name); ?>"
                                               step="0.01"
                                               min="0">
                                    <?php endforeach; ?>
                                </span>
                            <?php endif; ?>
                            <input type="number" id="batch-stock" class="small-text" placeholder="<?php _e('库存', 'qilingshop'); ?>" min="0">
                            <input type="number" id="batch-weight" class="small-text" placeholder="<?php _e('重量', 'qilingshop'); ?>" step="0.01" min="0">
                            <button type="button" class="button" id="qls-batch-apply"><?php _e('一键应用', 'qilingshop'); ?></button>
                        </div>

                        <table class="wp-list-table qls-ui-table widefat fixed qls-skus-table">
                            <thead>
                                <tr>
                                    <th><?php _e('规格', 'qilingshop'); ?></th>
                                    <th><?php _e('SKU编码', 'qilingshop'); ?> <span class="required">*</span></th>
                                    <th><?php _e('价格', 'qilingshop'); ?> <span class="required">*</span></th>
                                    <th><?php _e('促销价', 'qilingshop'); ?></th>
                                    <th><?php _e('积分价', 'qilingshop'); ?></th>
                                    <?php if ($has_vip_levels): ?>
                                    <th><?php _e('VIP价', 'qilingshop'); ?></th>
                                    <?php endif; ?>
                                    <th><?php _e('库存', 'qilingshop'); ?></th>
                                    <th><?php _e('重量(g)', 'qilingshop'); ?></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($product->skus as $si => $sku): ?>
                                <tr class="qls-sku-row">
                                    <td>
                                        <input type="hidden" name="skus[<?php echo $si; ?>][id]" value="<?php echo esc_attr($sku->id); ?>">
                                        <input type="hidden" name="skus[<?php echo $si; ?>][attr_values]" value="<?php echo esc_attr(wp_json_encode($sku->attr_values)); ?>">
                                        <?php 
                                        if (!empty($sku->attr_values)) {
                                            echo esc_html(implode(' / ', array_values($sku->attr_values)));
                                        } else {
                                            _e('默认', 'qilingshop');
                                        }
                                        ?>
                                    </td>
                                    <td><input type="text" name="skus[<?php echo $si; ?>][sku_code]" class="small-text" value="<?php echo esc_attr($sku->sku_code); ?>"></td>
                                    <td><input type="number" name="skus[<?php echo $si; ?>][price]" class="small-text" step="0.01" min="0" value="<?php echo esc_attr($sku->price); ?>"></td>
                                    <td><input type="number" name="skus[<?php echo $si; ?>][sale_price]" class="small-text" step="0.01" min="0" value="<?php echo esc_attr($sku->sale_price); ?>"></td>
                                    <td><input type="number" name="skus[<?php echo $si; ?>][points_price]" class="small-text" step="0.01" min="0" value="<?php echo esc_attr($sku->points_price); ?>"></td>
                                    <?php if ($has_vip_levels): ?>
                                    <td class="qls-sku-vip-prices-cell">
                                        <div class="qls-sku-vip-prices">
                                            <?php
                                            $sku_vip_prices = isset($sku->vip_prices) && is_array($sku->vip_prices) ? $sku->vip_prices : [];
                                            foreach ($vip_levels as $level):
                                                $level_id = (int) $level->id;
                                                $vip_price_value = isset($sku_vip_prices[$level_id]) ? $sku_vip_prices[$level_id] : '';
                                            ?>
                                                <label>
                                                    <span><?php echo esc_html($level->level_name); ?></span>
                                                    <input type="number"
                                                           name="skus[<?php echo $si; ?>][vip_prices][<?php echo esc_attr($level_id); ?>]"
                                                           class="small-text"
                                                           step="0.01"
                                                           min="0"
                                                           value="<?php echo esc_attr($vip_price_value); ?>">
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <?php endif; ?>
                                    <td><input type="number" name="skus[<?php echo $si; ?>][stock]" class="small-text" min="0" value="<?php echo esc_attr($sku->stock); ?>"></td>
                                    <td><input type="number" name="skus[<?php echo $si; ?>][weight]" class="small-text" step="0.01" min="0" value="<?php echo esc_attr($sku->weight); ?>"></td>
                                    <td>
                                        <?php
                                        $delete_sku_url = wp_nonce_url(
                                            admin_url('admin.php?page=qls-product-edit&id=' . absint($product_id) . '&delete_sku=' . absint($sku->id)),
                                            'delete_sku_' . absint($sku->id)
                                        );
                                        ?>
                                        <a href="<?php echo esc_url($delete_sku_url); ?>" class="qls-remove-sku dashicons dashicons-trash" data-sku-id="<?php echo esc_attr($sku->id); ?>"></a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <!-- 无规格时的简单价格输入 -->
                        <div class="qls-simple-price">
                            <table class="form-table qls-ui-form-table">
                                <tr>
                                    <th><label><?php _e('SKU编码', 'qilingshop'); ?> <span class="required">*</span></label></th>
                                    <td>
                                        <input type="text" name="sku_code" class="regular-text" placeholder="<?php _e('选填，留空自动生成', 'qilingshop'); ?>" value="">
                                    </td>
                                </tr>
                                <tr>
                                    <th><label><?php _e('价格', 'qilingshop'); ?> <span class="required">*</span></label></th>
                                    <td><input type="number" name="price" class="regular-text" step="0.01" min="0" value="0"></td>
                                </tr>
                                <tr>
                                    <th><label><?php _e('促销价', 'qilingshop'); ?></label></th>
                                    <td><input type="number" name="sale_price" class="regular-text" step="0.01" min="0"></td>
                                </tr>
                                <tr>
                                    <th><label><?php _e('积分价', 'qilingshop'); ?></label></th>
                                    <td><input type="number" name="points_price" class="regular-text" step="0.01" min="0"></td>
                                </tr>
                                <?php if ($has_vip_levels): ?>
                                <tr>
                                    <th><label><?php _e('VIP价', 'qilingshop'); ?></label></th>
                                    <td>
                                        <div class="qls-sku-vip-prices qls-simple-vip-prices">
                                            <?php foreach ($vip_levels as $level): ?>
                                                <label>
                                                    <span><?php echo esc_html($level->level_name); ?></span>
                                                    <input type="number"
                                                           name="vip_prices[<?php echo esc_attr((int) $level->id); ?>]"
                                                           class="regular-text"
                                                           step="0.01"
                                                           min="0">
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <th><label><?php _e('库存', 'qilingshop'); ?></label></th>
                                    <td><input type="number" name="stock" class="regular-text" min="0" value="0"></td>
                                </tr>
                                <tr>
                                    <th><label><?php _e('重量(g)', 'qilingshop'); ?></label></th>
                                    <td><input type="number" name="weight" class="regular-text" step="0.01" min="0" value="0"></td>
                                </tr>
                            </table>
                        </div>
                        <?php endif; ?>
                </div>
            </div>

                <!-- 商品特色 (Tags) -->
                <div class="qls-form-section">
                    <h2><?php _e('商品特色', 'qilingshop'); ?></h2>
                    <p class="description"><?php _e('输入特色标签并按回车添加，如：年度旗舰、超长续航、好评如潮', 'qilingshop'); ?></p>
                    
                    <div class="qls-features-input-box">
                        <div class="qls-features-list" id="qls-features-list">
                            <?php 
                            $product_tags = [];
                            if ($product_id > 0) {
                                $product_tags = qls_product()->get_tags($product_id);
                            }
                            if (!empty($product_tags)):
                                foreach ($product_tags as $tag):
                            ?>
                            <span class="qls-feature-tag">
                                <input type="hidden" name="product_tags[]" value="<?php echo esc_attr($tag->name); ?>">
                                <span class="tag-name"><?php echo esc_html($tag->name); ?></span>
                                <span class="tag-remove">&times;</span>
                            </span>
                            <?php 
                                endforeach;
                            endif;
                            ?>
                        </div>
                        <input type="text" id="qls-feature-input" class="large-text" placeholder="<?php _e('输入关键词按回车添加...', 'qilingshop'); ?>">
                    </div>
                </div>
                
                <!-- 商品参数 -->
                <div class="qls-form-section">
                    <h2><?php _e('商品参数', 'qilingshop'); ?></h2>
                    <p class="description"><?php _e('设置商品的品牌、型号、尺寸等参数信息。点击预设参数名可快速添加。', 'qilingshop'); ?></p>
                    
                    <?php if (!empty($param_templates)): ?>
                    <div class="qls-param-quick-btns">
                        <span><?php _e('快速添加：', 'qilingshop'); ?></span>
                        <?php foreach ($param_templates as $tpl): ?>
                        <button type="button" class="button button-small qls-add-param-tpl" data-name="<?php echo esc_attr($tpl->name); ?>"><?php echo esc_html($tpl->name); ?></button>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <table class="qls-params-table widefat" id="qls-params-table">
                        <thead>
                            <tr>
                                <th class="qls-w-30p"><?php _e('参数名', 'qilingshop'); ?></th>
                                <th class="qls-w-60p"><?php _e('参数值', 'qilingshop'); ?></th>
                                <th class="qls-w-10p"><?php _e('操作', 'qilingshop'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="qls-params-container">
                            <?php if (!empty($product->params)): ?>
                            <?php foreach ($product->params as $pi => $param): ?>
                            <tr class="qls-param-row">
                                <td>
                                    <input type="text" name="params[<?php echo $pi; ?>][name]" class="widefat qls-param-name" value="<?php echo esc_attr($param['name']); ?>" placeholder="<?php _e('参数名', 'qilingshop'); ?>">
                                </td>
                                <td>
                                    <input type="text" name="params[<?php echo $pi; ?>][value]" class="widefat" value="<?php echo esc_attr($param['value']); ?>" placeholder="<?php _e('参数值', 'qilingshop'); ?>">
                                </td>
                                <td>
                                    <span class="qls-remove-param dashicons dashicons-trash" title="<?php _e('删除', 'qilingshop'); ?>"></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    
                    <div class="qls-param-actions">
                        <button type="button" class="button" id="qls-add-param"><?php _e('+ 添加自定义参数', 'qilingshop'); ?></button>
                    </div>
                </div>
                
                <!-- 商品类型与虚拟商品设置 -->
                <div class="qls-form-section">
                    <h2><?php _e('商品类型', 'qilingshop'); ?></h2>
                    <?php 
                    $product_type = $product->product_type ?? 'physical';
                    $virtual_type = $product->virtual_type ?? 'download';
                    $virtual_content = $product->virtual_content ?? [];
                    ?>
                    <div class="qls-product-type-select qls-mb-15">
                        <label class="qls-mr-20">
                            <input type="radio" name="product_type" value="physical" <?php checked($product_type, 'physical'); ?>>
                            <?php _e('实物商品', 'qilingshop'); ?>
                        </label>
                        <label>
                            <input type="radio" name="product_type" value="virtual" <?php checked($product_type, 'virtual'); ?>>
                            <?php _e('虚拟商品', 'qilingshop'); ?>
                        </label>
                        <p class="description"><?php _e('虚拟商品无需发货，支付后自动完成订单', 'qilingshop'); ?></p>
                    </div>
                    
                    <!-- 虚拟商品设置（仅虚拟商品显示） -->
                    <div class="qls-virtual-settings qls-virtual-settings-panel<?php echo $product_type === 'virtual' ? '' : ' qls-hidden'; ?>" id="qls-virtual-settings">
                        <h3 class="qls-mt-0"><?php _e('虚拟商品设置', 'qilingshop'); ?></h3>
                        
                        <div class="qls-virtual-type-select">
                            <label for="virtual_type"><strong><?php _e('交付类型', 'qilingshop'); ?></strong></label>
                            <select name="virtual_type" id="virtual_type" class="qls-ml-10">
                                <option value="download" <?php selected($virtual_type, 'download'); ?>><?php _e('下载链接', 'qilingshop'); ?></option>
                                <option value="card" <?php selected($virtual_type, 'card'); ?>><?php _e('卡号卡密', 'qilingshop'); ?></option>
                                <option value="custom" <?php selected($virtual_type, 'custom'); ?>><?php _e('自定义内容', 'qilingshop'); ?></option>
                            </select>
                        </div>
                        
                        <!-- 下载链接类型 -->
                        <div class="qls-virtual-content-area<?php echo $virtual_type === 'download' ? '' : ' qls-hidden'; ?>" id="virtual-download">
                            <table class="form-table qls-ui-form-table">
                                <tr>
                                    <th><label><?php _e('下载链接', 'qilingshop'); ?></label></th>
                                    <td>
                                        <input type="url" name="virtual_content[download_url]" class="large-text" 
                                               value="<?php echo esc_attr($virtual_content['download_url'] ?? ''); ?>" 
                                               placeholder="<?php _e('https://...', 'qilingshop'); ?>">
                                    </td>
                                </tr>
                                <tr>
                                    <th><label><?php _e('提取码（选填）', 'qilingshop'); ?></label></th>
                                    <td>
                                        <input type="text" name="virtual_content[download_code]" class="regular-text" 
                                               value="<?php echo esc_attr($virtual_content['download_code'] ?? ''); ?>" 
                                               placeholder="<?php _e('如：abc123', 'qilingshop'); ?>">
                                    </td>
                                </tr>
                            </table>
                        </div>
                        
                        <!-- 卡号卡密类型 -->
                        <div class="qls-virtual-content-area<?php echo $virtual_type === 'card' ? '' : ' qls-hidden'; ?>" id="virtual-card">
                            <?php if ($is_edit && $product_type === 'virtual' && $virtual_type === 'card'): ?>
                            <div class="qls-card-stats qls-card-stats-highlight">
                                <?php 
                                $available_count = qls_card_inventory()->get_product_available_count($product->id);
                                ?>
                                <p class="qls-m-0-mb-10">
                                    <strong><?php _e('可用库存：', 'qilingshop'); ?></strong>
                                    <span class="qls-card-available-count"><?php echo $available_count; ?></span> <?php _e('张', 'qilingshop'); ?>
                                </p>
                                
                                <?php 
                                // 获取已导入的卡密列表（最多显示20条）
                                $existing_cards = qls_card_inventory()->get_list(0, ['limit' => 20]);
                                // 如果没有SKU级别的卡密，尝试获取产品级别
                                if (empty($existing_cards) && !empty($product->skus)) {
                                    foreach ($product->skus as $sku) {
                                        $existing_cards = qls_card_inventory()->get_list($sku->id, ['limit' => 20]);
                                        if (!empty($existing_cards)) break;
                                    }
                                }
                                
                                if (!empty($existing_cards)): 
                                ?>
                                <details class="qls-mt-10">
                                    <summary class="qls-card-summary">
                                        <?php _e('查看已导入的卡密', 'qilingshop'); ?> (<?php echo count($existing_cards); ?>条)
                                    </summary>
                                    <div class="qls-card-list-wrap">
                                        <table class="widefat qls-ui-table qls-card-list-table">
                                            <thead>
                                                <tr>
                                                    <th><?php _e('卡号', 'qilingshop'); ?></th>
                                                    <th><?php _e('卡密', 'qilingshop'); ?></th>
                                                    <th><?php _e('状态', 'qilingshop'); ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($existing_cards as $card): ?>
                                                <tr>
                                                    <td><code><?php echo esc_html($card->card_no); ?></code></td>
                                                    <td><code><?php echo esc_html($card->card_secret ?: '-'); ?></code></td>
                                                    <td>
                                                        <?php if ($card->status == 0): ?>
                                                        <span class="qls-text-success"><?php _e('可用', 'qilingshop'); ?></span>
                                                        <?php elseif ($card->status == 1): ?>
                                                        <span class="qls-text-warning"><?php _e('已售', 'qilingshop'); ?></span>
                                                        <?php else: ?>
                                                        <span class="qls-text-gray"><?php _e('已撤回', 'qilingshop'); ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </details>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            
                            <table class="form-table qls-ui-form-table">
                                <tr>
                                    <th><label><?php _e('批量导入卡密', 'qilingshop'); ?></label></th>
                                    <td>
                                        <textarea name="virtual_content[cards_import]" class="large-text" rows="8" 
                                                  placeholder="<?php _e("每行一条，支持格式：\n卡号\n卡号----卡密\n卡号,卡密", 'qilingshop'); ?>"></textarea>
                                        <p class="description"><?php _e('保存商品时将自动导入卡密到库存。已导入的卡密不会重复添加。', 'qilingshop'); ?></p>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        
                        <!-- 自定义内容类型 -->
                        <div class="qls-virtual-content-area<?php echo $virtual_type === 'custom' ? '' : ' qls-hidden'; ?>" id="virtual-custom">
                            <table class="form-table qls-ui-form-table">
                                <tr>
                                    <th><label><?php _e('自定义内容', 'qilingshop'); ?></label></th>
                                    <td>
                                        <textarea name="virtual_content[custom_content]" class="large-text" rows="6" 
                                                  placeholder="<?php _e('购买后用户将看到此内容，如教程链接、使用说明等', 'qilingshop'); ?>"><?php echo esc_textarea($virtual_content['custom_content'] ?? ''); ?></textarea>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- 新人专项活动 -->
                <?php
                $new_user_special_enabled = !empty($product->new_user_special_enabled);
                $new_user_special_price = isset($product->new_user_special_price) ? (float) $product->new_user_special_price : 0;
                ?>
                <div class="qls-form-section qls-new-user-special-section">
                    <h2><?php _e('新人专项活动', 'qilingshop'); ?></h2>
                    <p class="description"><?php _e('开启后，符合首单资格的用户可按新人专项价购买，且仅限购 1 件。退款后资格不会恢复。', 'qilingshop'); ?></p>
                    <table class="form-table qls-ui-form-table">
                        <tr>
                            <th><label><?php _e('启用新人专项', 'qilingshop'); ?></label></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="new_user_special_enabled" id="new_user_special_enabled" value="1" <?php checked($new_user_special_enabled); ?>>
                                    <?php _e('该商品加入新人专题并使用新人专享价', 'qilingshop'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr id="qls-new-user-price-row" class="<?php echo $new_user_special_enabled ? '' : 'qls-hidden'; ?>">
                            <th><label for="new_user_special_price"><?php _e('新人专享价', 'qilingshop'); ?></label></th>
                            <td>
                                <input type="number" step="0.01" min="0" name="new_user_special_price" id="new_user_special_price" class="regular-text"
                                       value="<?php echo esc_attr($new_user_special_price > 0 ? number_format($new_user_special_price, 2, '.', '') : ''); ?>"
                                       placeholder="<?php esc_attr_e('例如：1.00', 'qilingshop'); ?>">
                                <p class="description"><?php _e('仅对新人首单资格用户生效，结算时由后端强制校验。', 'qilingshop'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- 团购设置（仅实物商品显示） -->
                <?php 
                $group_rule = null;
                $group_enabled = false;
                if (!empty($product) && $product->id > 0) {
                    $group_rule = qls_group()->get_latest_rule_by_product($product->id);
                    $group_enabled = $group_rule && (int) $group_rule->status === QLS_Group::RULE_ENABLED;
                }
                ?>
                <div class="qls-form-section qls-group-settings-main<?php echo $product_type === 'virtual' ? ' qls-hidden' : ''; ?>" id="qls-group-settings-main">
                    <h2 class="qls-group-settings-title">
                        <span>🔥</span>
                        <?php _e('团购设置', 'qilingshop'); ?>
                    </h2>
                    <p class="description"><?php _e('设置多人拼团优惠价格，吸引更多用户参与', 'qilingshop'); ?></p>
                    
                    <table class="form-table qls-ui-form-table">
                        <tr>
                            <th><label><?php _e('开启团购', 'qilingshop'); ?></label></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="group_enabled" value="1" <?php checked($group_enabled); ?>>
                                    <?php _e('开启后支持多人拼团购买', 'qilingshop'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                    
                    <div class="qls-group-fields qls-group-fields-panel<?php echo $group_enabled ? '' : ' qls-hidden'; ?>">
                        <table class="form-table qls-ui-form-table">
                            <tr>
                                <th>
                                    <label><?php _e('团购价格', 'qilingshop'); ?> <span class="required">*</span></label>
                                </th>
                                <td>
                                    <input type="number" name="group_price" step="0.01" min="0" class="regular-text"
                                           value="<?php echo esc_attr($group_rule->group_price ?? ''); ?>" 
                                           placeholder="<?php _e('设置团购优惠价格', 'qilingshop'); ?>">
                                    <p class="description"><?php _e('团购价应低于商品售价，以吸引用户参团', 'qilingshop'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th>
                                    <label><?php _e('成团人数', 'qilingshop'); ?> <span class="required">*</span></label>
                                </th>
                                <td>
                                    <input type="number" name="group_size" min="2" max="100" class="small-text"
                                           value="<?php echo esc_attr($group_rule->group_size ?? 2); ?>">
                                    <span><?php _e('人', 'qilingshop'); ?></span>
                                    <p class="description"><?php _e('达到此人数即成团，最少2人', 'qilingshop'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th>
                                    <label><?php _e('团购库存', 'qilingshop'); ?></label>
                                </th>
                                <td>
                                    <input type="number" name="group_stock" min="0" class="small-text"
                                           value="<?php echo esc_attr($group_rule->group_stock ?? 0); ?>">
                                    <p class="description"><?php _e('填 0 使用商品规格库存', 'qilingshop'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th>
                                    <label><?php _e('成团时限', 'qilingshop'); ?></label>
                                </th>
                                <td>
                                    <input type="number" name="time_limit" min="1" max="720" class="small-text"
                                           value="<?php echo esc_attr($group_rule->time_limit ?? 24); ?>">
                                    <span><?php _e('小时', 'qilingshop'); ?></span>
                                    <p class="description"><?php _e('开团后多少小时内需成团，超时自动退款', 'qilingshop'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th>
                                    <label><?php _e('购买限制', 'qilingshop'); ?></label>
                                </th>
                                <td>
                                    <input type="number" name="limit_per_user" min="0" class="small-text"
                                           value="<?php echo esc_attr($group_rule->limit_per_user ?? 0); ?>">
                                    <span><?php _e('次/人', 'qilingshop'); ?></span>
                                    <p class="description"><?php _e('单用户可参与此商品团购的次数，0表示不限制', 'qilingshop'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th>
                                    <label><?php _e('活动时间', 'qilingshop'); ?></label>
                                </th>
                                <td>
                                    <input type="datetime-local" name="group_start_time"
                                           value="<?php echo $group_rule && $group_rule->start_time ? date('Y-m-d\TH:i', strtotime($group_rule->start_time)) : ''; ?>">
                                    <span><?php esc_html_e('至', 'qilingshop'); ?></span>
                                    <input type="datetime-local" name="group_end_time"
                                           value="<?php echo $group_rule && $group_rule->end_time ? date('Y-m-d\TH:i', strtotime($group_rule->end_time)) : ''; ?>">
                                    <p class="description"><?php _e('留空表示长期有效', 'qilingshop'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <!-- 商品详情 -->
                <div class="qls-form-section">
                    <h2><?php _e('商品详情', 'qilingshop'); ?></h2>
                    <?php 
                    wp_editor(
                        $product->content ?? '',
                        'product_content',
                        [
                            'textarea_name' => 'content',
                            'textarea_rows' => 15,
                            'media_buttons' => true,
                        ]
                    );
                    ?>
                </div>
            </div>
            
            <!-- 右侧边栏 -->
            <div class="qls-form-sidebar">
                <!-- 发布 -->
                <div class="qls-form-section qls-publish-box">
                    <h3><?php _e('发布', 'qilingshop'); ?></h3>
                    
                    <div class="qls-status-select">
                        <label for="status"><?php _e('状态', 'qilingshop'); ?></label>
                        <select name="status" id="status">
                            <option value="0" <?php selected($product->status ?? 0, 0); ?>><?php _e('下架', 'qilingshop'); ?></option>
                            <option value="1" <?php selected($product->status ?? 0, 1); ?>><?php _e('上架', 'qilingshop'); ?></option>
                        </select>
                    </div>
                    
                    <div class="qls-is-hot">
                        <label>
                            <input type="checkbox" name="is_hot" value="1" <?php checked($product->is_hot ?? 0); ?>>
                            <?php _e('设为热门商品', 'qilingshop'); ?>
                        </label>
                    </div>

                    <div class="qls-decoration-flags">
                        <p class="description"><?php _e('小程序装修标识', 'qilingshop'); ?></p>
                        <label>
                            <input type="checkbox" name="activity_recommend_enabled" value="1" <?php checked($product->activity_recommend_enabled ?? 0); ?>>
                            <?php _e('活动推荐', 'qilingshop'); ?>
                        </label>
                        <label>
                            <input type="checkbox" name="group_display_enabled" value="1" <?php checked($product->group_display_enabled ?? 0); ?>>
                            <?php _e('拼团展示', 'qilingshop'); ?>
                        </label>
                        <label>
                            <input type="checkbox" name="assist_display_enabled" value="1" <?php checked($product->assist_display_enabled ?? 0); ?>>
                            <?php _e('助力展示', 'qilingshop'); ?>
                        </label>
                    </div>

                    <div class="qls-sort-order">
                        <label for="sort_order"><?php _e('排序', 'qilingshop'); ?></label>
                        <input type="number" name="sort_order" id="sort_order" class="small-text" value="<?php echo esc_attr($product->sort_order ?? 0); ?>">
                    </div>
                    
                    <div class="qls-publish-actions">
                        <button type="submit" class="button button-primary button-large"><?php _e('保存商品', 'qilingshop'); ?></button>
                    </div>
                </div>
                
                <!-- 分类 -->
                <div class="qls-form-section">
                    <h3><?php _e('商品分类', 'qilingshop'); ?></h3>
                    <select name="category_id" class="qls-category-select">
                        <option value="0"><?php _e('选择分类', 'qilingshop'); ?></option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo esc_attr($cat->id); ?>" <?php selected($product->category_id ?? 0, $cat->id); ?>>
                            <?php echo esc_html($cat->display_name); ?>
                        </option>
                        <?php endforeach; ?>
                </select>
                
                <!-- 运费规则（仅实物商品显示） -->
                <?php $product_type = $product->product_type ?? 'physical'; ?>
                <div class="qls-form-section qls-shipping-section<?php echo $product_type === 'physical' ? '' : ' qls-hidden'; ?>" id="qls-shipping-section">
                    <h3><?php _e('运费规则', 'qilingshop'); ?></h3>
                    <select name="shipping_rule_id" class="qls-shipping-select">
                        <option value="0"><?php _e('使用默认规则', 'qilingshop'); ?></option>
                        <?php foreach ($shipping_rules as $rule): ?>
                        <option value="<?php echo esc_attr($rule->id); ?>" <?php selected($product->shipping_rule_id ?? 0, $rule->id); ?>>
                            <?php echo esc_html($rule->name); ?>
                            (<?php echo esc_html(qls_shipping()->get_rule_description($rule)); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- 服务标签 -->
                <div class="qls-form-section">
                    <h3><?php _e('服务标签', 'qilingshop'); ?></h3>
                    <div class="qls-service-tags-list">
                        <?php 
                        $selected_tags = $product->service_tags ?? [];
                        foreach ($service_tags as $tag): 
                            $is_checked = false;
                            if (empty($product)) {
                                // 新建商品：使用默认设置
                                $is_checked = !empty($tag->is_default);
                            } else {
                                // 编辑商品：使用保存的设置
                                $is_checked = in_array($tag->id, $selected_tags);
                            }
                        ?>
                        <label class="qls-tag-checkbox">
                            <input type="checkbox" name="service_tags[]" value="<?php echo esc_attr($tag->id); ?>"
                                   <?php checked($is_checked); ?>>
                            <?php echo esc_html($tag->name); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    // SKU 删除：点击垃圾桶立即删除数据库中的 SKU（并阻断其他旧处理器）
    $(document).on('click.qlsProductSkuDelete', '.qls-remove-sku', function(e) {
        e.preventDefault();
        e.stopImmediatePropagation();

        var $row = $(this).closest('.qls-sku-row');
        var skuId = parseInt($row.find('input[name$="[id]"]').val(), 10) || parseInt($(this).data('sku-id'), 10) || 0;
        if (skuId <= 0) {
            $row.remove();
            return false;
        }

        var ajaxUrl = (window.qlsShopAdmin && window.qlsShopAdmin.ajaxUrl) ? window.qlsShopAdmin.ajaxUrl : (window.ajaxurl || '');
        var nonce = (window.qlsShopAdmin && window.qlsShopAdmin.nonce) ? window.qlsShopAdmin.nonce : '<?php echo esc_js(wp_create_nonce('qls_shop_admin')); ?>';
        if (!ajaxUrl || !nonce) {
            alert('<?php echo esc_js(__('删除SKU失败，请刷新页面后重试', 'qilingshop')); ?>');
            return false;
        }

        $.post(ajaxUrl, {
            action: 'qls_shop_delete_sku',
            nonce: nonce,
            sku_id: skuId
        }).done(function(res) {
            if (res && res.success) {
                $row.remove();
                return;
            }
            var msg = (res && res.data && res.data.message) ? res.data.message : '<?php echo esc_js(__('删除SKU失败，请稍后重试', 'qilingshop')); ?>';
            alert(msg);
        }).fail(function() {
            alert('<?php echo esc_js(__('删除SKU失败，请稍后重试', 'qilingshop')); ?>');
        });

        return false;
    });

    // 通过回车添加特色
    $('#qls-feature-input').on('keydown', function(e) {
        if (e.keyCode === 13) { // Enter
            e.preventDefault();
            addFeatureTag($(this).val());
            $(this).val('');
        }
    });

    // 移除特色
    $(document).on('click', '.qls-feature-tag .tag-remove', function() {
        $(this).closest('.qls-feature-tag').remove();
    });

    // 批量设置SKU
    $(document).on('click', '#qls-batch-apply', function() {
        var price = $('#batch-price').val();
        var salePrice = $('#batch-sale-price').val();
        var pointsPrice = $('#batch-points-price').val();
        var stock = $('#batch-stock').val();
        var weight = $('#batch-weight').val();
        var vipPrices = [];
        $('.batch-vip-price').each(function() {
            vipPrices.push({
                levelId: parseInt($(this).data('level-id'), 10) || 0,
                value: $(this).val()
            });
        });

        $('.qls-skus-table tbody tr').each(function() {
            if (price !== '') $(this).find('input[name$="[price]"]').val(price);
            if (salePrice !== '') $(this).find('input[name$="[sale_price]"]').val(salePrice);
            if (pointsPrice !== '') $(this).find('input[name$="[points_price]"]').val(pointsPrice);
            vipPrices.forEach(function(item) {
                if (item.levelId > 0 && item.value !== '') {
                    $(this).find('input[name$="[vip_prices][' + item.levelId + ']"]').val(item.value);
                }
            }, this);
            if (stock !== '') $(this).find('input[name$="[stock]"]').val(stock);
            if (weight !== '') $(this).find('input[name$="[weight]"]').val(weight);
        });
        
        // 提示成功（可选，或者直接看到数值变化）
    });

    function addFeatureTag(name) {
        name = $.trim(name);
        if (!name) return;
        
        var exists = false;
        $('#qls-features-list input[name="product_tags[]"]').each(function() {
            if ($(this).val() === name) exists = true;
        });
        
        if (exists) return;

        var html = '<span class="qls-feature-tag">' +
                   '<input type="hidden" name="product_tags[]" value="' + name + '">' +
                   '<span class="tag-name">' + name + '</span>' +
                   '<span class="tag-remove">&times;</span>' +
                   '</span>';
        $('#qls-features-list').append(html);
    }

    // 商品类型切换
    $('input[name="product_type"]').on('change', function() {
        var type = $(this).val();
        if (type === 'virtual') {
            $('#qls-virtual-settings').slideDown();
            $('#qls-shipping-section').slideUp();
            $('#qls-group-settings-main').slideUp();  // 虚拟商品隐藏团购
        } else {
            $('#qls-virtual-settings').slideUp();
            $('#qls-shipping-section').slideDown();
            $('#qls-group-settings-main').slideDown(); // 实物商品显示团购
        }
    });

    // 虚拟商品交付类型切换
    $('#virtual_type').on('change', function() {
        var type = $(this).val();
        $('.qls-virtual-content-area').hide();
        $('#virtual-' + type).show();
    });

    // 团购开关切换
    $('input[name="group_enabled"]').on('change', function() {
        if ($(this).is(':checked')) {
            $('.qls-group-fields').slideDown();
        } else {
            $('.qls-group-fields').slideUp();
        }
    });

    // 新人专项活动开关
    $('#new_user_special_enabled').on('change', function() {
        if ($(this).is(':checked')) {
            $('#qls-new-user-price-row').slideDown();
        } else {
            $('#qls-new-user-price-row').slideUp();
        }
    });
});
</script>
