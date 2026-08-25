<?php
/**
 * 商城首页模板 - 完全模块化版本
 * 
 * 不再有硬编码的分类导航，所有内容由模块系统控制
 */
if (!defined('ABSPATH')) exit;

// 获取当前页面的装修布局数据
$shop_layout = get_post_meta(get_the_ID(), '_qls_shop_layout', true);

// 兼容旧数据或全局设置
if (empty($shop_layout) || !is_array($shop_layout)) {
    $shop_layout = get_option('qls_shop_home_layout', []);
}

// 模块类型到类名的映射
$module_class_map = [
    'product_list'   => 'QLS_Module_Product_List',
    'hero_carousel'  => 'QLS_Module_Hero_Carousel',
    'category_nav'   => 'QLS_Module_Category_Nav',
    'coupon'         => 'QLS_Module_Coupon',
    'group'          => 'QLS_Module_Group',
    'assist'         => 'QLS_Module_Assist',
    'new_user_zone'  => 'QLS_Module_New_User_Zone',
    // 未来可扩展更多模块
];

// 渲染页面头部
qls_shop_public()->get_shop_header('', true);
?>

<div class="qls-shop-wrapper qls-shop-home-page">
    <div class="qls-container">
        <?php 
        if (!empty($shop_layout) && is_array($shop_layout)) {
            $loaded_module_files = [];
            $module_instances = [];
            foreach ($shop_layout as $block) {
                if (empty($block['type'])) continue;
                
                $type = $block['type'];
                $settings = $block['settings'] ?? [];
                
                // 尝试加载对应的模块类
                if (isset($module_class_map[$type])) {
                    $class_name = $module_class_map[$type];
                    $file_name = 'class-qls-module-' . str_replace('_', '-', $type) . '.php';
                    $file_path = QILINGSHOP_PATH . 'includes/shop/modules/' . $file_name;
                    
                    if (!isset($loaded_module_files[$file_path])) {
                        $loaded_module_files[$file_path] = file_exists($file_path);
                    }

                    if ($loaded_module_files[$file_path]) {
                        require_once $file_path;
                        if (class_exists($class_name)) {
                            if (!isset($module_instances[$class_name])) {
                                $module_instances[$class_name] = new $class_name();
                            }
                            $module = $module_instances[$class_name];
                            echo $module->render($settings);
                        }
                    }
                }
            }
        } else {
            // 没有任何装修数据时的默认内容
            if (current_user_can('manage_options')) {
                echo '<div class="qls-notice" style="padding:40px;text-align:center;background:#fff;border:2px dashed #ccc;margin:20px 0;">';
                echo '<p style="font-size:16px;color:#666;">' . __('商城首页尚未配置装修模块', 'qilingshop') . '</p>';
                echo '<p><a href="' . esc_url(admin_url('post.php?post=' . get_the_ID() . '&action=edit')) . '" class="button button-primary">' . __('去装修', 'qilingshop') . '</a></p>';
                echo '</div>';
            } else {
                // 非管理员看到的默认内容 - 使用商品列表短代码
                echo do_shortcode('[qls_product_list title="' . __('热门商品', 'qilingshop') . '" source="hot" limit="8"]');
                echo do_shortcode('[qls_product_list title="' . __('最新上架', 'qilingshop') . '" source="latest" limit="12"]');
            }
        }
        ?>

        <?php qls_shop_public()->render_service_showcase('home_bottom'); ?>
    </div>
</div>

<?php qls_shop_public()->get_shop_footer(); ?>
