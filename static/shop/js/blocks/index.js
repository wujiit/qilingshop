(function (wp, settings) {
    var registerBlockType = wp.blocks.registerBlockType;
    var el = wp.element.createElement;
    var ServerSideRender = wp.serverSideRender;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody = wp.components.PanelBody;
    var TextControl = wp.components.TextControl;
    var SelectControl = wp.components.SelectControl;
    var RangeControl = wp.components.RangeControl;
    var i18n = settings && settings.i18n ? settings.i18n : {};

    function text(key) {
        return i18n[key] || key;
    }

    registerBlockType('qls-shop/product-list', {
        title: text('productList'),
        icon: 'grid-view',
        category: 'qiling-shop',
        attributes: {
            title: { type: 'string', default: text('hotProducts') },
            source: { type: 'string', default: 'latest' },
            limit: { type: 'number', default: 8 },
            columns: { type: 'number', default: 4 }
        },
        edit: function (props) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;

            return [
                el(InspectorControls, { key: 'inspector' },
                    el(PanelBody, { title: text('listSettings'), initialOpen: true },
                        el(TextControl, {
                            label: text('title'),
                            value: attributes.title,
                            onChange: function (val) { setAttributes({ title: val }); }
                        }),
                        el(SelectControl, {
                            label: text('productSource'),
                            value: attributes.source,
                            options: [
                                { label: text('latestProducts'), value: 'latest' },
                                { label: text('salesRanking'), value: 'sales' },
                                { label: text('hotRecommendation'), value: 'hot' },
                                { label: text('pointsProducts'), value: 'points' }
                            ],
                            onChange: function (val) { setAttributes({ source: val }); }
                        }),
                        el(RangeControl, {
                            label: text('displayCount'),
                            value: attributes.limit,
                            min: 1,
                            max: 20,
                            onChange: function (val) { setAttributes({ limit: val }); }
                        }),
                        el(RangeControl, {
                            label: text('columns'),
                            value: attributes.columns,
                            min: 2,
                            max: 6,
                            onChange: function (val) { setAttributes({ columns: val }); }
                        })
                    )
                ),
                el(ServerSideRender, {
                    key: 'preview',
                    block: 'qls-shop/product-list',
                    attributes: attributes
                })
            ];
        },
        save: function () {
            return null; // Dynamic block
        }
    });
})(window.wp, window.qlsShopBlocks || {});
