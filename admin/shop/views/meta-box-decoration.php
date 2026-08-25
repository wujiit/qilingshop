<?php
/**
 * 店铺装修 Meta Box
 */
if (!defined('ABSPATH')) exit;

// $saved_layout is passed from render_decoration_meta_box
$layout_json = !empty($saved_layout) ? json_encode($saved_layout) : '[]';

wp_nonce_field('qls_save_decoration_meta', 'qls_decoration_nonce');
?>

<div class="qls-decoration-editor qls-meta-box-editor">
    <!-- Hidden Input for Saving -->
    <input type="hidden" name="qls_shop_layout_data" id="qls-shop-layout-data" value="<?php echo esc_attr($layout_json); ?>">

    <!-- 1. 左侧：组件库 -->
    <div class="qls-dec-sidebar">
        <h3><?php _e('组件库', 'qilingshop'); ?></h3>
        <div class="qls-module-list" id="qls-module-source">
            <!-- 由JS填充 -->
        </div>
    </div>

    <!-- 2. 中间：画布 (手机模拟器) -->
    <div class="qls-dec-canvas-area">
        <div class="qls-phone-mockup">
            <div class="phone-header">
                <span class="camera"></span>
                <span class="speaker"></span>
            </div>
            <div class="phone-screen">
                <div class="shop-header-mock">
                    <span class="shop-name"><?php echo esc_html(get_option('qls_shop_name', 'Shop')); ?></span>
                    <span class="cart-icon">🛒</span>
                </div>
                
                <div class="qls-canvas" id="qls-canvas">
                    <div class="qls-empty-tip"><?php _e('从左侧拖拽组件到此处', 'qilingshop'); ?></div>
                </div>
            </div>
        </div>
        
        <p class="qls-save-hint">
            <?php _e('调整完成后，请点击页面右上角的 <b>更新</b> 按钮保存修改。', 'qilingshop'); ?>
        </p>
    </div>

    <!-- 3. 右侧：设置面板 -->
    <div class="qls-dec-settings">
        <h3><?php _e('组件设置', 'qilingshop'); ?></h3>
        <div id="qls-settings-form">
            <p class="qls-no-selection"><?php _e('请点击画布中的组件进行设置', 'qilingshop'); ?></p>
        </div>
    </div>
</div>
