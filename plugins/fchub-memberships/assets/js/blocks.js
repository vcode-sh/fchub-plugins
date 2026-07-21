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

(function (wp) {
    if (!wp.plugins || !wp.data || !wp.editor) {
        return;
    }

    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var useEffect = wp.element.useEffect;
    var useState = wp.element.useState;
    var __ = wp.i18n.__;
    var components = wp.components;
    var PluginDocumentSettingPanel = wp.editor.PluginDocumentSettingPanel;
    var PluginSidebar = wp.editor.PluginSidebar;
    var PluginSidebarMoreMenuItem = wp.editor.PluginSidebarMoreMenuItem;

    function validateCta(text, url) {
        var hasText = String(text || '').trim() !== '';
        var hasUrl = String(url || '').trim() !== '';
        return hasText === hasUrl
            ? ''
            : __('Add both the button label and destination, or leave both empty.', 'fchub-memberships');
    }

    function statusLabel(effective) {
        if (!effective || !effective.protected || effective.mode === 'public') {
            return __('Public', 'fchub-memberships');
        }
        if (effective.mode === 'direct') {
            return __('Protected directly', 'fchub-memberships');
        }
        if (effective.mode === 'mixed') {
            return __('Protected by direct and inherited rules', 'fchub-memberships');
        }
        return __('Protected by inherited rules', 'fchub-memberships');
    }

    function planSelectionMode(planIds) {
        return Array.isArray(planIds) && planIds.length ? 'specific' : 'any';
    }

    function filterPlans(plans, query) {
        var needle = String(query || '').trim().toLocaleLowerCase();
        if (!needle) {
            return plans;
        }
        return plans.filter(function (plan) {
            return String(plan.label || '').toLocaleLowerCase().includes(needle);
        });
    }

    function togglePlanId(planIds, planId, checked) {
        var current = (planIds || []).map(Number);
        if (checked) {
            return current.indexOf(Number(planId)) === -1 ? current.concat([Number(planId)]) : current;
        }
        return current.filter(function (id) { return id !== Number(planId); });
    }

    function selectedPlanSummary(config) {
        if (planSelectionMode(config.plan_ids) === 'any') {
            return __('Any active membership plan', 'fchub-memberships');
        }
        var namesById = {};
        (config.plans || []).forEach(function (plan) { namesById[Number(plan.id)] = plan.label; });
        return config.plan_ids.map(function (id) {
            return namesById[Number(id)] || __('Unknown plan', 'fchub-memberships') + ' #' + id;
        }).join(', ');
    }

    function currentEffective(config) {
        var effective = config.effective || { protected: false, mode: 'public', sources: [] };
        var inherited = (effective.sources || []).filter(function (source) { return source.type !== 'direct'; });
        if (config.enabled) {
            return {
                protected: true,
                mode: inherited.length ? 'mixed' : 'direct',
                sources: [{
                    type: 'direct',
                    label: __('Direct protection', 'fchub-memberships'),
                    detail: selectedPlanSummary(config)
                }].concat(inherited)
            };
        }
        return {
            protected: inherited.length > 0,
            mode: inherited.length ? 'inherited' : 'public',
            sources: inherited
        };
    }

    function Status(props) {
        var effective = currentEffective(props.config);
        return el(
            'div',
            { className: 'fchub-protection-status fchub-protection-status--' + effective.mode },
            el('span', { className: 'fchub-protection-status__dot', 'aria-hidden': true }),
            el(
                'span',
                null,
                el('strong', null, statusLabel(effective)),
                el(
                    'small',
                    null,
                    effective.protected
                        ? __('Visitors without access will see your configured fallback.', 'fchub-memberships')
                        : __('Everyone can view this content.', 'fchub-memberships')
                )
            )
        );
    }

    function SourceList(props) {
        var sources = currentEffective(props.config).sources || [];
        if (!sources.length) {
            return el('p', { className: 'fchub-protection-empty' }, __('No protection rules apply to this content.', 'fchub-memberships'));
        }

        return el(
            'div',
            { className: 'fchub-protection-sources' },
            sources.map(function (source, index) {
                return el(
                    'div',
                    { className: 'fchub-protection-source', key: source.type + index },
                    el(
                        'div',
                        null,
                        el('strong', null, source.label),
                        el('small', null, source.detail || '')
                    ),
                    source.manage_url && el(
                        'a',
                        { href: source.manage_url, className: 'fchub-protection-source__link' },
                        __('Manage', 'fchub-memberships')
                    )
                );
            })
        );
    }

    function Preview(props) {
        var config = props.config;
        var selectedPlans = (config.plans || [])
            .filter(function (plan) { return (config.plan_ids || []).indexOf(plan.id) !== -1; })
            .map(function (plan) { return plan.label; });
        var message = config.restriction_message || config.fallback_message || __('This content is available to members.', 'fchub-memberships');
        message = message
            .replaceAll('{plan_names}', selectedPlans.length ? selectedPlans.join(', ') : __('any active plan', 'fchub-memberships'))
            .replaceAll('{user_name}', __('Preview member', 'fchub-memberships'))
            .replaceAll('{login_url}', '#')
            .replaceAll('{pricing_url}', '#');

        return el(
            'div',
            { className: 'fchub-protection-preview' },
            el('span', { className: 'fchub-protection-preview__eyebrow' }, __('Visitor preview', 'fchub-memberships')),
            config.teaser_mode !== 'none' && el(
                'p',
                { className: 'fchub-protection-preview__teaser' },
                config.teaser_mode === 'custom' && config.custom_teaser
                    ? config.custom_teaser
                    : __('A preview of the protected content will appear here.', 'fchub-memberships')
            ),
            el('p', { className: 'fchub-protection-preview__message' }, message),
            config.cta_text && config.cta_url && el(
                'span',
                { className: 'fchub-protection-preview__button' },
                config.cta_text
            )
        );
    }

    function PlanPicker(props) {
        var config = props.config;
        var update = props.update;
        var plans = config.plans || [];
        var mode = planSelectionMode(config.plan_ids);
        var searchState = useState('');
        var query = searchState[0];
        var setQuery = searchState[1];
        var visiblePlans = filterPlans(plans, query);

        function setMode(nextMode) {
            if (nextMode === 'any') {
                update({ plan_ids: [] });
                return;
            }
            if (!(config.plan_ids || []).length && plans.length) {
                update({ plan_ids: [Number(plans[0].id)] });
            }
        }

        return el(
            'div',
            { className: 'fchub-plan-picker' },
            el(components.RadioControl, {
                label: __('Plan access', 'fchub-memberships'),
                selected: mode,
                options: [
                    { label: __('Any active plan', 'fchub-memberships'), value: 'any' },
                    { label: __('Specific plans', 'fchub-memberships'), value: 'specific', disabled: plans.length === 0 }
                ],
                onChange: setMode
            }),
            mode === 'any'
                ? el(
                    'div',
                    { className: 'fchub-plan-picker__explanation' },
                    el('strong', null, __('Open to every active member', 'fchub-memberships')),
                    el('span', null, __('Access is granted by any active membership plan.', 'fchub-memberships'))
                )
                : el(
                    Fragment,
                    null,
                    el(
                        'div',
                        { className: 'fchub-plan-picker__heading' },
                        el('strong', null, __('Active plans', 'fchub-memberships')),
                        el('span', null, String((config.plan_ids || []).length) + ' ' + __('selected', 'fchub-memberships'))
                    ),
                    plans.length > 6 && el(components.SearchControl, {
                        label: __('Search plans', 'fchub-memberships'),
                        placeholder: __('Search plans', 'fchub-memberships'),
                        value: query,
                        onChange: setQuery
                    }),
                    visiblePlans.length
                        ? el(
                            'div',
                            { className: 'fchub-plan-picker__list', role: 'group', 'aria-label': __('Specific plans', 'fchub-memberships') },
                            visiblePlans.map(function (plan) {
                                var checked = (config.plan_ids || []).map(Number).indexOf(Number(plan.id)) !== -1;
                                return el(
                                    'div',
                                    { className: 'fchub-plan-picker__option', key: plan.id },
                                    el(components.CheckboxControl, {
                                        label: plan.label,
                                        checked: checked,
                                        onChange: function (isChecked) {
                                            update({ plan_ids: togglePlanId(config.plan_ids, plan.id, isChecked) });
                                        }
                                    })
                                );
                            })
                        )
                        : el(
                            'p',
                            { className: 'fchub-plan-picker__empty' },
                            __('No plans match this search.', 'fchub-memberships')
                        )
                )
        );
    }

    function ProtectionControls(props) {
        var config = props.config;
        var update = props.update;
        var ctaError = validateCta(config.cta_text, config.cta_url);
        var tokens = ['{plan_names}', '{login_url}', '{pricing_url}', '{user_name}'];

        return el(
            Fragment,
            null,
            el(
                components.PanelBody,
                { title: __('Who can access', 'fchub-memberships'), initialOpen: true },
                el(SourceList, { config: config }),
                el('div', { className: 'fchub-protection-divider' }),
                config.plans && config.plans.length
                    ? el(PlanPicker, { config: config, update: update })
                    : el(components.Notice, { status: 'warning', isDismissible: false }, __('Create an active plan before limiting access to specific plans.', 'fchub-memberships'))
            ),
            el(
                components.PanelBody,
                { title: __('Visitor experience', 'fchub-memberships'), initialOpen: true },
                el(components.SelectControl, {
                    label: __('Teaser', 'fchub-memberships'),
                    value: config.teaser_mode || 'none',
                    options: [
                        { label: __('Restriction message only', 'fchub-memberships'), value: 'none' },
                        { label: __('Post excerpt', 'fchub-memberships'), value: 'excerpt' },
                        { label: __('Content before the More block', 'fchub-memberships'), value: 'more_tag' },
                        { label: __('First number of words', 'fchub-memberships'), value: 'words' },
                        { label: __('Custom teaser', 'fchub-memberships'), value: 'custom' }
                    ],
                    onChange: function (value) { update({ teaser_mode: value }); }
                }),
                config.teaser_mode === 'words' && el(components.TextControl, {
                    label: __('Preview length', 'fchub-memberships'),
                    help: __('Between 1 and 500 words.', 'fchub-memberships'),
                    type: 'number',
                    min: 1,
                    max: 500,
                    value: config.teaser_word_count || 50,
                    onChange: function (value) { update({ teaser_word_count: Math.max(1, Math.min(500, parseInt(value || '50', 10))) }); }
                }),
                config.teaser_mode === 'custom' && el(components.TextareaControl, {
                    label: __('Custom teaser', 'fchub-memberships'),
                    value: config.custom_teaser || '',
                    onChange: function (value) { update({ custom_teaser: value }); }
                }),
                el(components.TextareaControl, {
                    label: __('Restriction message', 'fchub-memberships'),
                    help: config.restriction_message
                        ? __('This overrides the global message for this content.', 'fchub-memberships')
                        : __('Leave empty to use the global restriction message.', 'fchub-memberships'),
                    value: config.restriction_message || '',
                    onChange: function (value) { update({ restriction_message: value }); }
                }),
                el(
                    'div',
                    { className: 'fchub-protection-tokens', 'aria-label': __('Available message tokens', 'fchub-memberships') },
                    tokens.map(function (token) {
                        return el(components.Button, {
                            key: token,
                            variant: 'tertiary',
                            size: 'compact',
                            onClick: function () { update({ restriction_message: (config.restriction_message || '') + token }); }
                        }, token);
                    })
                ),
                el('div', { className: 'fchub-protection-field-group' },
                    el('strong', { className: 'fchub-protection-field-group__title' }, __('Optional call to action', 'fchub-memberships')),
                    el('p', { className: 'fchub-protection-field-group__help' }, __('Shown below the restriction message. Add both fields to enable it.', 'fchub-memberships')),
                    el(components.TextControl, {
                        label: __('Button label', 'fchub-memberships'),
                        value: config.cta_text || '',
                        onChange: function (value) { update({ cta_text: value }); }
                    }),
                    el(components.TextControl, {
                        label: __('Destination', 'fchub-memberships'),
                        help: __('Use a site path such as /pricing or a full URL.', 'fchub-memberships'),
                        value: config.cta_url || '',
                        onChange: function (value) { update({ cta_url: value }); }
                    }),
                    ctaError && el(components.Notice, { status: 'error', isDismissible: false }, ctaError)
                )
            ),
            el(
                components.PanelBody,
                { title: __('Preview', 'fchub-memberships'), initialOpen: true },
                el(Preview, { config: config })
            )
        );
    }

    function ProtectionEditorPlugin() {
        var config = wp.data.useSelect(function (select) {
            return select('core/editor').getEditedPostAttribute('fchub_membership_protection');
        }, []);
        var editorActions = wp.data.useDispatch('core/editor');
        var editPostActions = wp.data.useDispatch('core/edit-post');

        if (!config || !PluginDocumentSettingPanel || !PluginSidebar) {
            return null;
        }

        var ctaError = validateCta(config.cta_text, config.cta_url);
        useEffect(function () {
            if (!editorActions.lockPostSaving || !editorActions.unlockPostSaving) {
                return undefined;
            }
            if (ctaError) {
                editorActions.lockPostSaving('fchub-membership-protection');
            } else {
                editorActions.unlockPostSaving('fchub-membership-protection');
            }
            return function () { editorActions.unlockPostSaving('fchub-membership-protection'); };
        }, [ctaError]);

        function update(patch) {
            editorActions.editPost({
                fchub_membership_protection: Object.assign({}, config, patch)
            });
        }

        function openSidebar() {
            if (editPostActions.openGeneralSidebar) {
                editPostActions.openGeneralSidebar('fchub-membership-protection/membership-protection-sidebar');
            }
        }

        return el(
            Fragment,
            null,
            el(
                PluginDocumentSettingPanel,
                {
                    name: 'membership-protection-summary',
                    title: __('Membership Protection', 'fchub-memberships'),
                    className: 'fchub-protection-summary'
                },
                el(Status, { config: config }),
                el(components.ToggleControl, {
                    label: __('Add direct protection to this content', 'fchub-memberships'),
                    help: currentEffective(config).mode === 'inherited' && !config.enabled
                        ? __('Inherited rules still apply when direct protection is off.', 'fchub-memberships')
                        : __('This setting is saved with the post.', 'fchub-memberships'),
                    checked: Boolean(config.enabled),
                    onChange: function (enabled) { update({ enabled: enabled }); }
                }),
                el(components.Button, {
                    variant: 'secondary',
                    className: 'fchub-protection-summary__button',
                    onClick: openSidebar
                }, __('Edit protection', 'fchub-memberships'))
            ),
            PluginSidebarMoreMenuItem && el(
                PluginSidebarMoreMenuItem,
                { target: 'membership-protection-sidebar', icon: 'lock' },
                __('Membership Protection', 'fchub-memberships')
            ),
            el(
                PluginSidebar,
                {
                    name: 'membership-protection-sidebar',
                    title: __('Membership Protection', 'fchub-memberships'),
                    icon: 'lock',
                    className: 'fchub-protection-sidebar'
                },
                el('div', { className: 'fchub-protection-sidebar__intro' },
                    el(Status, { config: config }),
                    el(components.ToggleControl, {
                        label: __('Direct protection', 'fchub-memberships'),
                        help: __('Saved when you save or publish the post.', 'fchub-memberships'),
                        checked: Boolean(config.enabled),
                        onChange: function (enabled) { update({ enabled: enabled }); }
                    })
                ),
                config.enabled
                    ? el(ProtectionControls, { config: config, update: update })
                    : el(
                        'div',
                        { className: 'fchub-protection-sidebar__disabled' },
                        el(SourceList, { config: config }),
                        el('p', null, currentEffective(config).protected
                            ? __('This content remains protected by inherited rules. Enable direct protection to customise its visitor experience.', 'fchub-memberships')
                            : __('Enable direct protection to choose plans and configure the visitor experience.', 'fchub-memberships'))
                    )
            )
        );
    }

    window.fchubMembershipProtectionUI = {
        validateCta: validateCta,
        statusLabel: statusLabel,
        currentEffective: currentEffective,
        planSelectionMode: planSelectionMode,
        filterPlans: filterPlans,
        togglePlanId: togglePlanId,
        selectedPlanSummary: selectedPlanSummary
    };

    wp.plugins.registerPlugin('fchub-membership-protection', {
        icon: 'lock',
        render: ProtectionEditorPlugin
    });
}(window.wp));
