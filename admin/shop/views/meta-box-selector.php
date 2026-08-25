<?php
/**
 * 店铺模块设置 Meta Box
 * 
 * 简化版：仅提供模块选择和基本参数设置，无可视化拖拽。
 */
if (!defined('ABSPATH')) exit;

// 获取当前页面已保存的布局数据
$saved_blocks = get_post_meta($post->ID, '_qls_shop_layout', true);
if (!is_array($saved_blocks)) $saved_blocks = [];

// 获取可用模块
$modules = $this->get_available_modules();

wp_nonce_field('qls_save_decoration_meta', 'qls_decoration_nonce');
?>

<div class="qls-modules-box">
    <div class="qls-modules-list" id="qls-modules-list">
        <?php 
        if (!empty($saved_blocks)): 
            foreach ($saved_blocks as $index => $block): 
                $type = $block['type'];
                if (!isset($modules[$type])) continue;
                $module = $modules[$type];
                $settings = $block['settings'] ?? [];
        ?>
            <div class="qls-module-row" data-index="<?php echo $index; ?>">
                <div class="qls-module-header">
                    <span class="dashicons dashicons-sort qls-modules-handle"></span>
                    <span class="qls-module-title"><?php echo esc_html($module['name']); ?></span>
                    <button type="button" class="button-link remove-module text-danger">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                    <input type="hidden" name="qls_modules[<?php echo $index; ?>][type]" value="<?php echo esc_attr($type); ?>">
                </div>
                <div class="qls-module-settings">
                    <!-- 动态生成设置项 -->
                    <?php if (isset($module['fields'])): ?>
                        <?php foreach ($module['fields'] as $group_key => $group): ?>
                            <div class="qls-setting-group">
                                <strong><?php echo esc_html($group['title']); ?></strong>
                                <div class="qls-setting-fields">
                                    <?php foreach ($group['fields'] as $key => $field): 
                                        $val = $settings[$key] ?? ($module['defaults'][$key] ?? '');
                                        $field_name = "qls_modules[$index][settings][$key]";
                                    ?>
                                        <div class="qls-field-row">
                                            <label><?php echo esc_html($field['label']); ?></label>
                                            
                                            <?php if ($field['type'] === 'text' || $field['type'] === 'number'): ?>
                                                <input type="<?php echo $field['type']; ?>" 
                                                       name="<?php echo $field_name; ?>" 
                                                       value="<?php echo esc_attr($val); ?>" 
                                                       class="widefat">
                                                       
                                            <?php elseif ($field['type'] === 'select'): ?>
                                                <select name="<?php echo $field_name; ?>" class="widefat">
                                                    <?php foreach ($field['options'] as $opt_val => $opt_label): ?>
                                                        <option value="<?php echo esc_attr($opt_val); ?>" <?php selected($val, $opt_val); ?>>
                                                            <?php echo esc_html($opt_label); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            <?php endif; ?>
                                            
                                            <?php if (isset($field['desc'])): ?>
                                                <p class="description"><?php echo esc_html($field['desc']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php 
            endforeach; 
        endif; 
        ?>
    </div>

    <div class="qls-add-module-area">
        <select id="qls-select-module">
            <option value=""><?php _e('— 选择要添加的模块 —', 'qilingshop'); ?></option>
            <?php foreach ($modules as $key => $mod): ?>
                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($mod['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="button" id="qls-btn-add-module"><?php _e('添加模块', 'qilingshop'); ?></button>
    </div>
</div>

<script type="text/template" id="tmpl-qls-module">
    <div class="qls-module-row" data-index="{{index}}">
        <div class="qls-module-header">
            <span class="dashicons dashicons-sort qls-modules-handle"></span>
            <span class="qls-module-title">{{name}}</span>
            <button type="button" class="button-link remove-module text-danger">
                <span class="dashicons dashicons-trash"></span>
            </button>
            <input type="hidden" name="qls_modules[{{index}}][type]" value="{{type}}">
        </div>
        <div class="qls-module-settings">
            {{settings_html}}
        </div>
    </div>
</script>

<script>
jQuery(document).ready(function($) {
    // 排序
    $('#qls-modules-list').sortable({
        handle: '.qls-modules-handle',
        placeholder: 'ui-state-highlight',
        forcePlaceholderSize: true
    });

    // Remove
    $(document).on('click', '.remove-module', function() {
        if(confirm('<?php _e('确定删除此模块吗？', 'qilingshop'); ?>')) {
            $(this).closest('.qls-module-row').remove();
        }
    });

    // Add Module
    const modules = <?php echo json_encode($modules); ?>;
    
    $('#qls-btn-add-module').on('click', function() {
        const type = $('#qls-select-module').val();
        if (!type || !modules[type]) return;

        const mod = modules[type];
        const index = new Date().getTime(); // Simple unique ID
        
        let settingsHtml = '';
        
        if (mod.fields) {
            Object.values(mod.fields).forEach(group => {
                settingsHtml += `<div class="qls-setting-group"><strong>${group.title}</strong><div class="qls-setting-fields">`;
                
                Object.keys(group.fields).forEach(key => {
                    const field = group.fields[key];
                    const val = mod.defaults[key] || '';
                    const fieldName = `qls_modules[${index}][settings][${key}]`;
                    
                    settingsHtml += '<div class="qls-field-row">';
                    settingsHtml += `<label>${field.label}</label>`;
                    
                    if (field.type === 'text' || field.type === 'number') {
                        settingsHtml += `<input type="${field.type}" name="${fieldName}" value="${val}" class="widefat">`;
                    } else if (field.type === 'select') {
                        settingsHtml += `<select name="${fieldName}" class="widefat">`;
                        Object.keys(field.options).forEach(optKey => {
                            settingsHtml += `<option value="${optKey}">${field.options[optKey]}</option>`;
                        });
                        settingsHtml += `</select>`;
                    }
                    
                    if (field.desc) {
                        settingsHtml += `<p class="description">${field.desc}</p>`;
                    }
                    settingsHtml += '</div>';
                });
                
                settingsHtml += `</div></div>`;
            });
        }

        const tmpl = `
            <div class="qls-module-row" data-index="${index}">
                <div class="qls-module-header">
                    <span class="dashicons dashicons-sort qls-modules-handle"></span>
                    <span class="qls-module-title">${mod.name}</span>
                    <button type="button" class="button-link remove-module text-danger">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                    <input type="hidden" name="qls_modules[${index}][type]" value="${type}">
                </div>
                <div class="qls-module-settings">
                    ${settingsHtml}
                </div>
            </div>
        `;
        
        $('#qls-modules-list').append(tmpl);
    });
});
</script>
