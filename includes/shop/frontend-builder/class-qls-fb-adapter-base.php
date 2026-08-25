<?php
/**
 * 前台装修适配器基类
 *
 * 将插件的 QLS_Module_Base 子类桥接为主题 Module_Base 子类，
 * 使其能被主题的 Frontend_Builder / Module_Manager 识别和管理。
 *
 * @package QilingShop
 * @since   2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

use Developer_Starter\Modules\Module_Base;

abstract class QLS_FB_Adapter_Base extends Module_Base {

    /**
     * 插件原始模块实例（QLS_Module_Base 子类）
     *
     * @var QLS_Module_Base|null
     */
    protected $qls_module = null;

    /**
     * 子类必须返回对应的 QLS_Module_Base 实例
     *
     * @return QLS_Module_Base
     */
    abstract protected function create_qls_module();

    /**
     * 获取或懒加载插件原始模块实例
     *
     * @return QLS_Module_Base
     */
    protected function get_qls_module() {
        if ($this->qls_module === null) {
            $this->qls_module = $this->create_qls_module();
        }
        return $this->qls_module;
    }

    /**
     * 将插件模块的 get_settings_fields()（分组嵌套格式）
     * 转换为主题 Module_Base 的 get_fields()（扁平数组格式）
     *
     * 插件格式:
     *   [ 'group_key' => [ 'title' => '...', 'fields' => [ 'field_id' => [ 'type' => '...', 'label' => '...' ] ] ] ]
     *
     * 主题格式:
     *   [ [ 'id' => '...', 'label' => '...', 'type' => '...', 'default' => '...', 'options' => [...] ] ]
     *
     * @return array
     */
    public function get_fields() {
        $module = $this->get_qls_module();
        $grouped_fields = $module->get_settings_fields();
        $defaults = $module->get_defaults();
        $flat_fields = [];

        foreach ($grouped_fields as $group_key => $group) {
            if (empty($group['fields']) || !is_array($group['fields'])) {
                continue;
            }

            foreach ($group['fields'] as $field_id => $field_config) {
                // 跳过 repeater 类型（如轮播 slides），前台装修面板暂不支持嵌套 repeater
                if (isset($field_config['type']) && $field_config['type'] === 'repeater') {
                    continue;
                }

                $flat_field = [
                    'id'      => $field_id,
                    'label'   => $field_config['label'] ?? $field_id,
                    'type'    => $this->map_field_type($field_config['type'] ?? 'text'),
                    'default' => $defaults[$field_id] ?? '',
                    'options' => $field_config['options'] ?? [],
                ];

                // 如果有描述，附加到 label 中
                if (!empty($field_config['desc'])) {
                    $flat_field['desc'] = $field_config['desc'];
                }

                $flat_fields[] = $flat_field;
            }
        }

        return $flat_fields;
    }

    /**
     * 获取模块默认数据
     *
     * @return array
     */
    public function get_default_data() {
        return $this->get_qls_module()->get_defaults();
    }

    /**
     * 渲染模块（主题 Frontend_Builder 调用入口）
     *
     * 主题的 render() 直接输出 HTML（echo），
     * 插件的 render() 返回 HTML 字符串。
     *
     * @param array $data 模块配置数据
     */
    public function render($data = []) {
        $module = $this->get_qls_module();

        // 合并默认值
        $defaults = $module->get_defaults();
        $merged = array_merge($defaults, $data);

        // 调用插件模块的 render()，直接 echo 返回的 HTML
        echo $module->render($merged);
    }

    /**
     * 将插件的字段类型映射为主题支持的字段类型
     *
     * @param string $type 插件字段类型
     * @return string 主题字段类型
     */
    protected function map_field_type($type) {
        $map = [
            'text'     => 'text',
            'number'   => 'number',
            'select'   => 'select',
            'color'    => 'text',
            'image'    => 'image',
            'textarea' => 'textarea',
            'checkbox' => 'checkbox',
            'radio'    => 'select',
            'repeater' => 'textarea', // fallback
        ];

        return $map[$type] ?? 'text';
    }
}
