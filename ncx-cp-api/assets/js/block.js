(function (blocks, i18n, element, components) {
    if (!blocks || !i18n || !element || !components) {
        return;
    }

    var __ = i18n.__;
    var TextControl = components.TextControl;
    var Fragment = element.Fragment;

    blocks.registerBlockType('ncx/copyandpay-widget', {
        title: __('NCX CopyAndPay', 'ncx-cp-api'),
        icon: 'money-alt',
        category: 'widgets',
        description: __('Embed a COPYandPAY payment widget given an existing checkout ID.', 'ncx-cp-api'),
        attributes: {
            checkoutId: {
                type: 'string',
                default: ''
            },
            brands: {
                type: 'string',
                default: 'VISA MASTER'
            }
        },
        edit: function (props) {
            var attrs = props.attributes;

            return element.createElement(
                Fragment,
                null,
                element.createElement('p', {}, __('Render the COPYandPAY widget with your checkout identifier.', 'ncx-cp-api')),
                element.createElement(TextControl, {
                    label: __('Checkout ID', 'ncx-cp-api'),
                    value: attrs.checkoutId,
                    onChange: function (value) {
                        props.setAttributes({ checkoutId: value });
                    }
                }),
                element.createElement(TextControl, {
                    label: __('Brands (space separated)', 'ncx-cp-api'),
                    value: attrs.brands,
                    onChange: function (value) {
                        props.setAttributes({ brands: value });
                    }
                })
            );
        },
        save: function () {
            return null;
        }
    });
})(window.wp && window.wp.blocks, window.wp && window.wp.i18n, window.wp && window.wp.element, window.wp && window.wp.components);
