<?php
/**
 * 店铺装修 - 仿主题 Builder 模式
 */
if (!defined('ABSPATH')) exit;

// 获取当前页面已保存的布局数据
$saved_blocks = $saved_layout; // 来自类变量
$modules = $this->get_available_modules();
$module_count = !empty($saved_blocks) ? count($saved_blocks) : 0;

wp_nonce_field('qls_save_decoration_meta', 'qls_decoration_nonce');
?>
<div class="qls-dsm-wrap">
    <!-- Toolbar -->
    <div class="qls-dsm-toolbar" id="qls-dsm-toolbar">
        <?php foreach ($modules as $key => $mod): ?>
            <button type="button" class="qls-dsm-add-btn" data-type="<?php echo esc_attr($key); ?>">
                <?php if(!empty($mod['icon'])): ?>
                    <span class="dashicons <?php echo esc_attr($mod['icon']); ?>"></span>
                <?php endif; ?>
                <?php echo esc_html($mod['name']); ?>
            </button>
        <?php endforeach; ?>
    </div>

    <!-- List -->
    <div class="qls-dsm-list" id="qls-dsm-list">
        <?php 
        $idx = 0;
        if (!empty($saved_blocks)): 
            foreach ($saved_blocks as $block): 
                $type = $block['type'];
                if (!isset($modules[$type])) continue;
                $settings = $block['settings'] ?? [];
                
                // 渲染模块项
                $this->render_builder_item($idx, $type, $modules[$type], $settings);
                $idx++;
            endforeach;
        endif; 
        ?>
        <?php if($idx === 0): ?>
            <div class="qls-dsm-empty-tip"><?php _e('点击上方按钮添加模块进行装修', 'qilingshop'); ?></div>
        <?php endif; ?>
    </div>
</div>

<script>
jQuery(document).ready(function($){
    var idx = <?php echo $module_count > 0 ? $module_count : 0; ?>;

    // 检查空状态提示
    function checkEmpty() {
        if ($('#qls-dsm-list').children('.qls-dsm-item').length > 0) {
            $('.qls-dsm-empty-tip').hide();
        } else {
            $('.qls-dsm-empty-tip').show();
        }
    }

    // 添加模块
    $('.qls-dsm-add-btn').on('click', function(e){
        e.preventDefault();
        var type = $(this).data('type');
        var $btn = $(this);
        if (!type || $btn.data('loading')) return;

        $btn.data('loading', 1).prop('disabled', true).addClass('is-loading');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'qls_render_builder_item',
                nonce: $('#qls_decoration_nonce').val(),
                type: type,
                idx: idx
            },
            success: function(res) {
                if (res && res.success && res.data) {
                    var $item = $(res.data);
                    $item.addClass('open');
                    $('#qls-dsm-list').append($item);
                    checkEmpty();
                    idx++;
                    
                    // Re-init sortables on new elements
                    if($.fn.sortable) {
                        $('.qls-dsm-repeater-list').sortable({
                            axis: 'y',
                            opacity: 0.8,
                            handle: 'label'
                        });
                    }
                } else {
                    alert('<?php echo esc_js(__('模块加载失败，请稍后重试。', 'qilingshop')); ?>');
                }
            },
            error: function() {
                alert('<?php echo esc_js(__('网络错误，请稍后重试。', 'qilingshop')); ?>');
            },
            complete: function() {
                $btn.removeData('loading').prop('disabled', false).removeClass('is-loading');
            }
        });
    });

    // 切换模块
    $(document).on('click', '.qls-dsm-item-header', function(e){
        // Prevent toggling if clicking remove button
        if($(e.target).closest('.qls-dsm-remove').length) return;
        
        // 切换状态类
        $(this).closest('.qls-dsm-item').toggleClass('open');
    });

    // 移除模块
    $(document).on('click', '.qls-dsm-remove', function(e){
        e.preventDefault();
        e.stopPropagation(); // Stop bubbling to header click
        if(confirm('<?php _e('确定删除此模块吗？', 'qilingshop'); ?>')){
            $(this).closest('.qls-dsm-item').remove();
            checkEmpty();
        }
    });

    // 媒体上传
    $(document).on('click', '.qls-upload-btn', function(e){
        e.preventDefault();
        var $btn = $(this);
        var $input = $btn.prev('input');
        
        // 创建媒体选择窗口
        var frame = wp.media({
            title: '<?php _e('选择图片', 'qilingshop'); ?>',
            button: { text: '<?php _e('使用此图片', 'qilingshop'); ?>' },
            multiple: false
        });

        frame.on('select', function(){
            var attachment = frame.state().get('selection').first().toJSON();
            $input.val(attachment.url);
        });

        frame.open();
    });

    // 重复项：添加项目
    $(document).on('click', '.qls-dsm-add-repeater-btn', function(e){
        e.preventDefault();
        var $btn = $(this);
        var $list = $btn.siblings('.qls-dsm-repeater-list');
        var fields = $btn.data('fields'); // JSON object
        var namePrefix = $btn.data('name-prefix'); // e.g. qls_modules[0][settings][slides]
        
        // Calculate index
        var index = $list.children('.qls-dsm-repeater-item').length;
        
        // Build HTML
        var html = '<div class="qls-dsm-repeater-item"><span class="qls-dsm-repeater-remove dashicons dashicons-no-alt"></span>';
        
        $.each(fields, function(key, field){
            var fieldName = namePrefix + '[' + index + '][' + key + ']';
            var val = field.default || '';
            
            html += '<div class="qls-dsm-field">';
            html += '<label>' + field.label + '</label>';
            
            if(field.type === 'image') {
                html += '<div class="qls-image-uploader">';
                html += '<input type="text" name="' + fieldName + '" value="" class="qls-image-url large-text">';
                html += '<button type="button" class="button qls-upload-btn"><?php _e('选择图片', 'qilingshop'); ?></button>';
                html += '</div>';
            } else if(field.type === 'select') {
                html += '<select name="' + fieldName + '">';
                $.each(field.options, function(k, v){
                     html += '<option value="' + k + '">' + v + '</option>';
                });
                html += '</select>';
            } else if(field.type === 'textarea') {
                 html += '<textarea name="' + fieldName + '" rows="3"></textarea>';
            } else {
                 html += '<input type="text" name="' + fieldName + '" value="">';
            }
            
            if(field.desc) {
                html += '<p class="description">' + field.desc + '</p>';
            }
            html += '</div>';
        });
        
        html += '</div>';
        
        $list.append(html);
    });

    // 重复项：移除项目
    $(document).on('click', '.qls-dsm-repeater-remove', function(){
        if(confirm('<?php _e('确定删除此项吗？', 'qilingshop'); ?>')) {
            $(this).closest('.qls-dsm-repeater-item').remove();
        }
    });

    // 排序
    if($.fn.sortable) {
        $('#qls-dsm-list').sortable({
            handle: '.qls-dsm-handle',
            placeholder: 'ui-state-highlight',
            forcePlaceholderSize: true,
            axis: 'y',
            opacity: 0.8
        });
        
        // 重复项排序
        $('.qls-dsm-repeater-list').sortable({
            axis: 'y',
            opacity: 0.8,
            handle: 'label', 
            stop: function(event, ui) {}
        });
    }
});
</script>
