<?php
/**
 * 模块基类
 *
 * @package QilingShop
 * @since   2.0.2
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class QLS_Module_Base {

    /**
     * 模块默认属性
     */
    protected $default_attributes = [];

    /**
     * 获取模块默认属性
     */
    abstract protected function get_defaults();

    /**
     * 渲染模块内容 (子类实现)
     */
    abstract protected function render_content($atts);

    /**
     * 渲染入口
     */
    public function render($atts = []) {
        $defaults = $this->get_defaults();
        $attributes = shortcode_atts($defaults, $atts);
        
        ob_start();
        try {
            $this->render_content($attributes);
        } catch (Exception $e) {
            echo '<!-- Module Error: ' . esc_html($e->getMessage()) . ' -->';
        }
        return ob_get_clean();
    }

    /**
     * 辅助方法：生成类名
     */
    protected function get_class_names($base, $modifiers = []) {
        $classes = [$base];
        foreach ($modifiers as $key => $val) {
            if ($val) {
                if (is_bool($val)) {
                    $classes[] = $base . '--' . $key;
                } else {
                    $classes[] = $base . '--' . $key . '-' . $val;
                }
            }
        }
        return implode(' ', $classes);
    }
}
