/**
 * FCHub Thank You — Admin settings for FluentCart product pages.
 *
 * Sidebar widget + modal dialog for configuring per-product
 * thank-you page redirects. Supports pages, posts, CPTs, and custom URLs.
 *
 * No build step required — Options API with runtime template strings.
 */

(function () {
    'use strict';

    /* ------------------------------------------------------------------ */
    /*  REST helper                                                        */
    /* ------------------------------------------------------------------ */

    var config = window.fchubThankYouData || {};

    function restUrl(path) {
        return (config.restUrl || '/wp-json/fchub-thank-you/v1/') + path;
    }

    function request(method, path, body) {
        var opts = {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': config.nonce || '',
            },
            credentials: 'same-origin',
        };
        if (body) {
            opts.body = JSON.stringify(body);
        }
        return fetch(restUrl(path), opts).then(function (res) {
            return res.json().then(function (json) {
                if (!res.ok) {
                    var msg = (json && json.message) || 'Request failed: ' + res.status;
                    throw new Error(msg);
                }
                return json.data || json;
            });
        });
    }

    function searchContent(postType, term) {
        var params = 'post_type=' + encodeURIComponent(postType);
        if (term) {
            params += '&s=' + encodeURIComponent(term);
        }
        return request('GET', 'search?' + params);
    }

    function fetchPostTypes() {
        return request('GET', 'post-types');
    }

    /* ------------------------------------------------------------------ */
    /*  Type labels                                                        */
    /* ------------------------------------------------------------------ */

    var TYPE_LABELS = {
        page: 'Page',
        post: 'Post',
        cpt: 'Custom Post Type',
        url: 'Custom URL',
    };

    /* ------------------------------------------------------------------ */
    /*  ConfigDialog component                                             */
    /* ------------------------------------------------------------------ */

    var ConfigDialog = {
        name: 'ConfigDialog',
        props: {
            visible: { type: Boolean, default: false },
            settings: { type: Object, required: true },
        },
        emits: ['update:visible', 'save'],
        data: function () {
            return {
                form: {
                    type: 'url',
                    target_id: null,
                    url: '',
                    post_type: '',
                },
                searchResults: [],
                searchLoading: false,
                postTypes: [],
                postTypesLoading: false,
                saving: false,
                searchTimer: null,
            };
        },
        computed: {
            dialogVisible: {
                get: function () { return this.visible; },
                set: function (val) { this.$emit('update:visible', val); },
            },
            needsContentPicker: function () {
                return this.form.type === 'page' || this.form.type === 'post' || this.form.type === 'cpt';
            },
            effectivePostType: function () {
                if (this.form.type === 'page') return 'page';
                if (this.form.type === 'post') return 'post';
                if (this.form.type === 'cpt') return this.form.post_type;
                return '';
            },
        },
        watch: {
            visible: function (val) {
                if (val) {
                    this.syncFromSettings();
                    if (this.postTypes.length === 0) {
                        this.loadPostTypes();
                    }
                }
            },
            'form.type': function () {
                this.searchResults = [];
                this.form.target_id = null;
            },
            'form.post_type': function () {
                this.searchResults = [];
                this.form.target_id = null;
            },
        },
        methods: {
            syncFromSettings: function () {
                this.form.type = this.settings.type || 'url';
                this.form.target_id = this.settings.target_id || null;
                this.form.url = this.settings.url || '';
                this.form.post_type = this.settings.post_type || '';

                // Pre-populate search results with current target if set
                if (this.form.target_id && this.settings.target_label) {
                    this.searchResults = [{
                        id: this.settings.target_id,
                        title: this.settings.target_label,
                        permalink: this.settings.target_permalink || '',
                        post_type: this.effectivePostType,
                    }];
                }
            },
            loadPostTypes: function () {
                var vm = this;
                vm.postTypesLoading = true;
                fetchPostTypes()
                    .then(function (types) { vm.postTypes = types; })
                    .catch(function () {})
                    .finally(function () { vm.postTypesLoading = false; });
            },
            remoteSearch: function (query) {
                var vm = this;
                var pt = vm.effectivePostType;
                if (!pt) return;

                if (vm.searchTimer) {
                    clearTimeout(vm.searchTimer);
                }
                vm.searchTimer = setTimeout(function () {
                    vm.searchLoading = true;
                    searchContent(pt, query)
                        .then(function (results) { vm.searchResults = results; })
                        .catch(function () { vm.searchResults = []; })
                        .finally(function () { vm.searchLoading = false; });
                }, 300);
            },
            onFocusSearch: function () {
                if (this.searchResults.length === 0 && this.effectivePostType) {
                    this.remoteSearch('');
                }
            },
            selectedPermalink: function () {
                var id = this.form.target_id;
                if (!id) return '';
                for (var i = 0; i < this.searchResults.length; i++) {
                    if (this.searchResults[i].id === id) {
                        return this.searchResults[i].permalink;
                    }
                }
                return this.settings.target_permalink || '';
            },
            onSave: function () {
                this.$emit('save', {
                    type: this.form.type,
                    target_id: this.needsContentPicker ? this.form.target_id : null,
                    url: this.form.type === 'url' ? this.form.url : '',
                    post_type: this.form.type === 'cpt' ? this.form.post_type : '',
                });
            },
            onCancel: function () {
                this.dialogVisible = false;
            },
        },
        template: '\
<el-dialog\
    v-model="dialogVisible"\
    title="Configure Thank You Redirect"\
    width="680px"\
    :close-on-click-modal="false"\
    append-to-body\
>\
    <div class="fchub-ty-dialog-body">\
        <div class="fchub-ty-field">\
            <label class="fchub-ty-field-label">Redirect type</label>\
            <el-radio-group v-model="form.type">\
                <el-radio-button value="page">Page</el-radio-button>\
                <el-radio-button value="post">Post</el-radio-button>\
                <el-radio-button value="cpt">Custom Post Type</el-radio-button>\
                <el-radio-button value="url">Custom URL</el-radio-button>\
            </el-radio-group>\
        </div>\
\
        <div v-if="form.type === \'cpt\'" class="fchub-ty-field">\
            <label class="fchub-ty-field-label">Post type</label>\
            <el-select\
                v-model="form.post_type"\
                placeholder="Select post type"\
                :loading="postTypesLoading"\
                style="width: 100%"\
            >\
                <el-option\
                    v-for="pt in postTypes"\
                    :key="pt.slug"\
                    :label="pt.label"\
                    :value="pt.slug"\
                />\
            </el-select>\
        </div>\
\
        <div v-if="needsContentPicker && effectivePostType" class="fchub-ty-field">\
            <label class="fchub-ty-field-label">Select content</label>\
            <el-select\
                v-model="form.target_id"\
                filterable\
                remote\
                :remote-method="remoteSearch"\
                placeholder="Search by title..."\
                :loading="searchLoading"\
                clearable\
                style="width: 100%"\
                @focus="onFocusSearch"\
            >\
                <el-option\
                    v-for="item in searchResults"\
                    :key="item.id"\
                    :label="item.title"\
                    :value="item.id"\
                >\
                    <span>{{ item.title }}</span>\
                    <span style="float: right; color: #999; font-size: 12px">ID: {{ item.id }}</span>\
                </el-option>\
            </el-select>\
            <div v-if="selectedPermalink()" class="fchub-ty-preview-link">\
                <a :href="selectedPermalink()" target="_blank" rel="noopener">Preview target &rarr;</a>\
            </div>\
        </div>\
\
        <div v-if="form.type === \'url\'" class="fchub-ty-field">\
            <label class="fchub-ty-field-label">Redirect URL</label>\
            <el-input\
                v-model="form.url"\
                placeholder="https://example.com/thank-you"\
                clearable\
            />\
        </div>\
    </div>\
\
    <template #footer>\
        <el-button @click="onCancel">Cancel</el-button>\
        <el-button type="primary" :loading="saving" @click="onSave">Save</el-button>\
    </template>\
</el-dialog>',
    };

    /* ------------------------------------------------------------------ */
    /*  SidebarWidget component                                            */
    /* ------------------------------------------------------------------ */

    var ThankYouPageSettings = {
        name: 'ThankYouPageSettings',
        components: { ConfigDialog: ConfigDialog },
        props: {
            data: { type: Object, required: true },
        },
        data: function () {
            return {
                enabled: false,
                settings: {
                    type: 'url',
                    target_id: null,
                    url: '',
                    post_type: '',
                    target_label: '',
                    target_permalink: '',
                },
                saving: false,
                loaded: false,
                dialogVisible: false,
            };
        },
        computed: {
            productId: function () {
                return this.data.editableProduct ? this.data.editableProduct.ID : null;
            },
            summaryText: function () {
                if (!this.enabled) return '';
                var s = this.settings;
                if (s.type === 'url' && s.url) return 'URL: ' + s.url;
                if ((s.type === 'page' || s.type === 'post' || s.type === 'cpt') && s.target_label) {
                    return (TYPE_LABELS[s.type] || s.type) + ': ' + s.target_label;
                }
                return 'Not configured';
            },
            previewUrl: function () {
                var s = this.settings;
                if (s.type === 'url' && s.url) return s.url;
                if (s.target_permalink) return s.target_permalink;
                return '';
            },
        },
        mounted: function () {
            if (this.productId) {
                this.loadSettings();
            }
        },
        methods: {
            loadSettings: function () {
                var vm = this;
                request('GET', 'product/' + vm.productId)
                    .then(function (data) {
                        vm.enabled = !!data.enabled;
                        vm.settings.type = data.type || 'url';
                        vm.settings.target_id = data.target_id || null;
                        vm.settings.url = data.url || '';
                        vm.settings.post_type = data.post_type || '';
                        vm.settings.target_label = data.target_label || '';
                        vm.settings.target_permalink = data.target_permalink || '';
                    })
                    .catch(function () {})
                    .finally(function () { vm.loaded = true; });
            },
            onToggle: function (val) {
                var vm = this;
                vm.enabled = val;
                vm.saving = true;
                request('POST', 'product/' + vm.productId, {
                    enabled: vm.enabled,
                    type: vm.settings.type,
                    target_id: vm.settings.target_id,
                    url: vm.settings.url,
                    post_type: vm.settings.post_type,
                })
                    .then(function () {
                        if (window.ElMessage) {
                            window.ElMessage({ type: 'success', message: 'Saved.' });
                        }
                    })
                    .catch(function (err) {
                        var msg = (err && err.message) || 'Could not save.';
                        if (window.ElMessage) {
                            window.ElMessage({ type: 'error', message: msg });
                        }
                    })
                    .finally(function () { vm.saving = false; });
            },
            openDialog: function () {
                this.dialogVisible = true;
            },
            onDialogSave: function (formData) {
                var vm = this;
                vm.saving = true;
                request('POST', 'product/' + vm.productId, {
                    enabled: vm.enabled,
                    type: formData.type,
                    target_id: formData.target_id,
                    url: formData.url,
                    post_type: formData.post_type,
                })
                    .then(function (data) {
                        vm.settings.type = data.type || 'url';
                        vm.settings.target_id = data.target_id || null;
                        vm.settings.url = data.url || '';
                        vm.settings.post_type = data.post_type || '';
                        vm.settings.target_label = data.target_label || '';
                        vm.settings.target_permalink = data.target_permalink || '';
                        vm.dialogVisible = false;
                        if (window.ElMessage) {
                            window.ElMessage({ type: 'success', message: 'Saved.' });
                        }
                    })
                    .catch(function (err) {
                        var msg = (err && err.message) || 'Could not save.';
                        if (window.ElMessage) {
                            window.ElMessage({ type: 'error', message: msg });
                        }
                    })
                    .finally(function () { vm.saving = false; });
            },
        },
        template: '\
<div class="fchub-ty-wrap">\
    <div class="fchub-ty-row">\
        <span class="fchub-ty-label">Enable custom redirect</span>\
        <el-switch :model-value="enabled" @change="onToggle" :disabled="!loaded" />\
    </div>\
    <transition name="el-fade-in-linear">\
        <div v-if="enabled && loaded" class="fchub-ty-details">\
            <div class="fchub-ty-summary">\
                <span class="fchub-ty-summary-text">{{ summaryText }}</span>\
                <a v-if="previewUrl" :href="previewUrl" target="_blank" rel="noopener" class="fchub-ty-preview">Preview</a>\
            </div>\
            <el-button size="small" @click="openDialog">Configure</el-button>\
        </div>\
    </transition>\
    <ConfigDialog\
        v-model:visible="dialogVisible"\
        :settings="settings"\
        @save="onDialogSave"\
    />\
</div>',
    };

    /* ------------------------------------------------------------------ */
    /*  Minimal CSS                                                        */
    /* ------------------------------------------------------------------ */

    var style = document.createElement('style');
    style.textContent = [
        '.fchub-ty-wrap { padding: 4px 0; }',
        '.fchub-ty-row { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }',
        '.fchub-ty-row:last-child { margin-bottom: 0; }',
        '.fchub-ty-label { font-weight: 500; min-width: 160px; }',
        '.fchub-ty-details { margin-top: 4px; }',
        '.fchub-ty-summary { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; font-size: 13px; }',
        '.fchub-ty-summary-text { color: #606266; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 240px; }',
        '.fchub-ty-preview { color: #409eff; font-size: 12px; white-space: nowrap; text-decoration: none; }',
        '.fchub-ty-preview:hover { text-decoration: underline; }',
        '.fchub-ty-dialog-body { padding: 0 4px; }',
        '.fchub-ty-field { margin-bottom: 20px; }',
        '.fchub-ty-field:last-child { margin-bottom: 0; }',
        '.fchub-ty-field-label { display: block; font-weight: 500; margin-bottom: 8px; font-size: 14px; }',
        '.fchub-ty-preview-link { margin-top: 6px; font-size: 12px; }',
        '.fchub-ty-preview-link a { color: #409eff; text-decoration: none; }',
        '.fchub-ty-preview-link a:hover { text-decoration: underline; }',
    ].join('\n');
    document.head.appendChild(style);

    /* ------------------------------------------------------------------ */
    /*  Sidebar widget registration                                        */
    /* ------------------------------------------------------------------ */

    window.fluent_cart_admin.hooks.addFilter(
        'single_product_page',
        'fchub_thank_you',
        function (widgets) {
            widgets.push({
                type: 'vue-component',
                use_card: true,
                title: 'Custom Thank You Page',
                component: ThankYouPageSettings,
            });
            return widgets;
        }
    );
})();
