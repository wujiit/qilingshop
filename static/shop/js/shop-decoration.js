jQuery(document).ready(function ($) {
    if (typeof qlsDecoration === 'undefined') return;

    function text(key, fallback) {
        var i18n = qlsDecoration.i18n || {};
        return i18n[key] || fallback || key;
    }

    const App = {
        modules: qlsDecoration.modules || {},
        layout: qlsDecoration.saved_layout || [],
        activeBlockId: null,

        init: function () {
            this.renderModuleList();
            this.renderCanvas();
            this.initDragAndDrop();
            this.bindEvents();
        },

        // 1. 渲染左侧模块列表
        renderModuleList: function () {
            const $container = $('#qls-module-source');
            Object.values(this.modules).forEach(mod => {
                const html = `
                    <div class="qls-module-item" data-type="${mod.id}">
                        <span class="dashicons ${mod.icon}"></span>
                        <span>${mod.name}</span>
                    </div>
                `;
                $container.append(html);
            });
        },

        // 2. 渲染中间画布
        renderCanvas: function () {
            const $canvas = $('#qls-canvas');
            $canvas.empty();

            if (this.layout.length === 0) {
                $canvas.html('<div class="qls-empty-tip">' + text('empty_tip', '将模块拖到这里') + '</div>');
                return;
            }

            this.layout.forEach(block => {
                this.appendBlockToCanvas(block, $canvas);
            });
        },

        appendBlockToCanvas: function (block, $container) {
            const modDef = this.modules[block.type];
            if (!modDef) return;

            // 简化预览内容
            let previewHtml = `<span class="qls-preview-title">${block.settings.title || modDef.name}</span>`;
            if (block.settings.source) {
                previewHtml += `<span class="qls-preview-desc">${text('source_label', '来源')}: ${block.settings.source}</span>`;
            }

            const html = `
                <div class="qls-module-block" data-id="${block.id}" data-type="${block.type}">
                    <div class="qls-block-tools">
                        <div class="qls-block-btn remove" title="${text('remove', '移除')}">&times;</div>
                    </div>
                    <div class="qls-block-preview">
                        ${previewHtml}
                    </div>
                </div>
            `;
            $container.append(html);
        },

        // 3. 拖拽排序逻辑
        initDragAndDrop: function () {
            const self = this;

            // 左侧模块可拖拽
            $('.qls-module-item').draggable({
                connectToSortable: "#qls-canvas",
                helper: "clone",
                revert: "invalid",
                zIndex: 100
            });

            // 画布可排序
            $('#qls-canvas').sortable({
                placeholder: "sortable-placeholder",
                forcePlaceholderSize: true,
                receive: function (event, ui) {
                    // 从左侧拖入新模块时初始化配置
                    const type = ui.helper.data('type');
                    const newId = 'mod_' + Math.random().toString(36).substr(2, 9);
                    const modDef = self.modules[type];

                    // Default settings clone
                    const defaultSettings = JSON.parse(JSON.stringify(modDef.defaults));

                    const newBlock = {
                        id: newId,
                        type: type,
                        settings: defaultSettings
                    };

                    self.layout.push(newBlock);

                    // 将 jQuery UI 插入的侧栏克隆项替换为真实模块。
                    // 延迟到 receive 完成后处理，确保 DOM 与布局状态同步。
                    setTimeout(() => {
                        // receive 后克隆节点已进入 DOM，可在此处统一替换。
                        const $dropped = $(this).find('.qls-module-item');

                        const modDef = self.modules[type];
                        let previewHtml = `<span class="qls-preview-title">${defaultSettings.title || modDef.name}</span>`;

                        const blockHtml = `
                            <div class="qls-module-block active" data-id="${newId}" data-type="${type}">
                                <div class="qls-block-tools">
                                    <div class="qls-block-btn remove" title="${text('remove', '移除')}">&times;</div>
                                </div>
                                <div class="qls-block-preview">
                                    ${previewHtml}
                                </div>
                            </div>
                        `;

                        $dropped.replaceWith(blockHtml);

                        // 更新布局顺序
                        self.syncLayoutFromDOM();
                        self.updateHiddenInput();

                        // 选中新模块
                        self.selectBlock(newId);
                    }, 0);
                },
                update: function (event, ui) {
                    if (ui.sender) return; // 已由接收流程处理
                    self.syncLayoutFromDOM();
                    self.updateHiddenInput();
                }
            });
        },

        // Sync the internal layout array order with DOM order
        // 接收新模块时已写入数据，这里只同步排序。
        syncLayoutFromDOM: function () {
            const newLayout = [];
            const self = this;

            $('#qls-canvas .qls-module-block').each(function () {
                const id = $(this).data('id');
                // Find data in old layout or default (though new items are handled in receive)
                // If it's a new item just replaced in receive, we need to ensure we find it.
                // In 'receive', we pushed to self.layout.
                const existing = self.layout.find(b => b.id === id);
                if (existing) {
                    newLayout.push(existing);
                }
            });

            this.layout = newLayout;
            this.updateHiddenInput(); // 同步到隐藏字段
        },

        // 4. 事件绑定
        bindEvents: function () {
            const self = this;

            // 点击模块进行选择
            $(document).on('click', '.qls-module-block', function () {
                const id = $(this).data('id');
                self.selectBlock(id);
            });

            // 移除模块
            $(document).on('click', '.qls-block-btn.remove', function (e) {
                e.stopPropagation();
                const $block = $(this).closest('.qls-module-block');
                const id = $block.data('id');

                if (confirm(text('confirm_remove_module', '确定移除此模块？'))) {
                    $block.remove();
                    self.layout = self.layout.filter(b => b.id !== id);
                    if (self.activeBlockId === id) {
                        $('#qls-settings-form').html('<p class="qls-no-selection">' + text('no_selection', '请选择画布中的模块进行配置') + '</p>');
                        self.activeBlockId = null;
                    }
                    if (self.layout.length === 0) {
                        $('#qls-canvas').html('<div class="qls-empty-tip">' + text('empty_tip', '将模块拖到这里') + '</div>');
                    }
                    self.updateHiddenInput();
                }
            });

            // 表单变更后更新数据和预览
            $(document).on('input change', '.qls-input-field', function () {
                if (!self.activeBlockId) return;

                const name = $(this).data('name');
                const value = $(this).val();

                const block = self.layout.find(b => b.id === self.activeBlockId);
                if (block) {
                    block.settings[name] = value;

                    // Update Preview Text if it's title
                    if (name === 'title') {
                        $(`.qls-module-block[data-id="${block.id}"] .qls-preview-title`).text(value || self.modules[block.type].name);
                    }
                    self.updateHiddenInput();
                }
            });
        },

        // 更新隐藏字段，供文章保存时提交
        updateHiddenInput: function () {
            const json = JSON.stringify(this.layout);
            $('#qls-shop-layout-data').val(json);
        },

        // 5. 选择模块并渲染设置项
        selectBlock: function (id) {
            this.activeBlockId = id;

            // 高亮当前模块
            $('.qls-module-block').removeClass('active');
            $(`.qls-module-block[data-id="${id}"]`).addClass('active');

            // 渲染表单
            const block = this.layout.find(b => b.id === id);
            if (!block) return;

            const modDef = this.modules[block.type];
            const fieldsDef = modDef.fields;

            const $form = $('#qls-settings-form');
            $form.empty();

            if (!fieldsDef) return;

            Object.keys(fieldsDef).forEach(groupKey => {
                const group = fieldsDef[groupKey];
                let groupHtml = `<div class="qls-setting-group"><h4>${group.title}</h4>`;

                Object.keys(group.fields).forEach(paramKey => {
                    const field = group.fields[paramKey];
                    const value = block.settings[paramKey] !== undefined ? block.settings[paramKey] : '';

                    groupHtml += this.renderField(paramKey, field, value);
                });

                groupHtml += '</div>';
                $form.append(groupHtml);
            });
        },

        renderField: function (name, field, value) {
            let inputHtml = '';

            switch (field.type) {
                case 'text':
                case 'number':
                    inputHtml = `<input type="${field.type}" class="qls-input-field" data-name="${name}" value="${this.escapeHtml(value)}">`;
                    break;
                case 'select':
                    let optionsHtml = '';
                    if (Array.isArray(field.options)) {
                        // Simple array not supported by PHP struct above, usually assoc array
                    } else {
                        Object.keys(field.options).forEach(optVal => {
                            const selected = String(optVal) === String(value) ? 'selected' : '';
                            optionsHtml += `<option value="${optVal}" ${selected}>${field.options[optVal]}</option>`;
                        });
                    }
                    inputHtml = `<select class="qls-input-field" data-name="${name}">${optionsHtml}</select>`;
                    break;
            }

            return `
                <div class="qls-form-row">
                    <label>${field.label}</label>
                    ${inputHtml}
                    ${field.desc ? `<span class="qls-form-desc">${field.desc}</span>` : ''}
                </div>
            `;
        },

        escapeHtml: function (text) {
            if (!text) return "";
            return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }
    };

    App.init();
});
