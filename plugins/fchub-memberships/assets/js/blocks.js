(function (blocks, element, blockEditor, components, i18n) {
    var el = element.createElement;
    var __ = i18n.__;
    var InnerBlocks = blockEditor.InnerBlocks;
    var InspectorControls = blockEditor.InspectorControls;
    var PanelBody = components.PanelBody;
    var TextControl = components.TextControl;
    var TextareaControl = components.TextareaControl;
    var SelectControl = components.SelectControl;

    /**
     * fchub-memberships/restrict
     */
    blocks.registerBlockType('fchub-memberships/restrict', {
        title: __('Members Only', 'fchub-memberships'),
        description: __('Restrict content to members with specific plans.', 'fchub-memberships'),
        icon: 'lock',
        category: 'common',
        attributes: {
            plan_slugs: { type: 'string', default: '' },
            resource_type: { type: 'string', default: '' },
            resource_id: { type: 'string', default: '' },
            restriction_message: { type: 'string', default: '' }
        },
        supports: {
            html: false,
            align: false
        },

        edit: function (props) {
            var attributes = props.attributes;

            return el(
                element.Fragment,
                null,
                el(
                    InspectorControls,
                    null,
                    el(
                        PanelBody,
                        { title: __('Restriction Settings', 'fchub-memberships'), initialOpen: true },
                        el(TextControl, {
                            label: __('Plan Slugs', 'fchub-memberships'),
                            help: __('Comma-separated plan slugs that grant access.', 'fchub-memberships'),
                            value: attributes.plan_slugs,
                            onChange: function (val) { props.setAttributes({ plan_slugs: val }); }
                        }),
                        el(TextControl, {
                            label: __('Resource Type', 'fchub-memberships'),
                            help: __('Optional. Defaults to current post type.', 'fchub-memberships'),
                            value: attributes.resource_type,
                            onChange: function (val) { props.setAttributes({ resource_type: val }); }
                        }),
                        el(TextControl, {
                            label: __('Resource ID', 'fchub-memberships'),
                            help: __('Optional. Defaults to current post ID.', 'fchub-memberships'),
                            value: attributes.resource_id,
                            onChange: function (val) { props.setAttributes({ resource_id: val }); }
                        }),
                        el(TextareaControl, {
                            label: __('Restriction Message', 'fchub-memberships'),
                            help: __('Custom message shown to non-members.', 'fchub-memberships'),
                            value: attributes.restriction_message,
                            onChange: function (val) { props.setAttributes({ restriction_message: val }); }
                        })
                    )
                ),
                el(
                    'div',
                    {
                        className: 'fchub-block-restrict-wrapper',
                        style: {
                            border: '2px dashed #0073aa',
                            padding: '12px',
                            borderRadius: '4px',
                            position: 'relative'
                        }
                    },
                    el(
                        'div',
                        {
                            style: {
                                background: '#0073aa',
                                color: '#fff',
                                padding: '2px 8px',
                                borderRadius: '3px',
                                fontSize: '11px',
                                display: 'inline-block',
                                marginBottom: '8px'
                            }
                        },
                        __('Members Only', 'fchub-memberships') +
                        (attributes.plan_slugs ? ': ' + attributes.plan_slugs : '')
                    ),
                    el(InnerBlocks, null)
                )
            );
        },

        save: function () {
            return el(InnerBlocks.Content, null);
        }
    });

    /**
     * fchub-memberships/membership-status
     */
    blocks.registerBlockType('fchub-memberships/membership-status', {
        title: __('Membership Status', 'fchub-memberships'),
        description: __('Display the current user\'s membership status.', 'fchub-memberships'),
        icon: 'id-alt',
        category: 'common',
        attributes: {
            display: { type: 'string', default: 'compact' }
        },
        supports: {
            html: false
        },

        edit: function (props) {
            var attributes = props.attributes;

            return el(
                element.Fragment,
                null,
                el(
                    InspectorControls,
                    null,
                    el(
                        PanelBody,
                        { title: __('Display Settings', 'fchub-memberships'), initialOpen: true },
                        el(SelectControl, {
                            label: __('Display Mode', 'fchub-memberships'),
                            value: attributes.display,
                            options: [
                                { label: __('Compact', 'fchub-memberships'), value: 'compact' },
                                { label: __('Full', 'fchub-memberships'), value: 'full' }
                            ],
                            onChange: function (val) { props.setAttributes({ display: val }); }
                        })
                    )
                ),
                el(
                    'div',
                    {
                        className: 'fchub-block-status-placeholder',
                        style: {
                            border: '1px dashed #ccc',
                            padding: '20px',
                            textAlign: 'center',
                            background: '#f9f9f9',
                            borderRadius: '4px'
                        }
                    },
                    el('span', { className: 'dashicons dashicons-id-alt', style: { fontSize: '24px', marginBottom: '8px', display: 'block' } }),
                    el('p', { style: { margin: 0 } },
                        __('Membership Status', 'fchub-memberships') + ' (' + attributes.display + ')'
                    )
                )
            );
        },

        save: function () {
            return null;
        }
    });
}(
    window.wp.blocks,
    window.wp.element,
    window.wp.blockEditor,
    window.wp.components,
    window.wp.i18n
));
