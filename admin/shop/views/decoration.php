<?php
/**
 * 店铺装修页面
 */
if (!defined('ABSPATH')) exit;
?>

<div class="wrap qilingshop-admin-page qls-decoration-wrap">
    <h1 class="wp-heading-inline"><?php _e('店铺装修', 'qilingshop'); ?></h1>
    
    <div class="qls-decoration-editor">
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
                    <div class="phone-status-bar">
                        <span>9:41</span>
                        <div class="status-icons">
                            <span class="icon">signal</span>
                            <span class="icon">wifi</span>
                            <span class="icon">battery</span>
                        </div>
                    </div>
                    <div class="shop-header-mock">
                        <span class="menu-icon">≡</span>
                        <span class="shop-name"><?php echo esc_html(get_option('qls_shop_name', 'Shop')); ?></span>
                        <span class="cart-icon">🛒</span>
                    </div>
                    
                    <div class="qls-canvas" id="qls-canvas">
                        <div class="qls-empty-tip"><?php _e('从左侧拖拽组件到此处', 'qilingshop'); ?></div>
                    </div>
                </div>
            </div>
            
            <div class="qls-dec-actions">
                <button type="button" class="button button-primary button-hero" id="qls-save-decoration">
                    <?php _e('保存装修', 'qilingshop'); ?>
                </button>
            </div>
        </div>

        <!-- 3. 右侧：设置面板 -->
        <div class="qls-dec-settings">
            <h3><?php _e('组件设置', 'qilingshop'); ?></h3>
            <div id="qls-settings-form">
                <p class="qls-no-selection"><?php _e('请点击画布中的组件进行设置', 'qilingshop'); ?></p>
            </div>
        </div>
    </div>
</div>
