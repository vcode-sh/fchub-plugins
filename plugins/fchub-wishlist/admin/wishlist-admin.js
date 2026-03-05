/**
 * FCHub Wishlist — Admin settings page for FluentCart's SPA.
 *
 * Registers a /settings/wishlist child route via the fluent_cart_routes filter
 * and injects a sidebar nav item into the settings page, matching FluentCart's
 * native settings page pattern (identical to multi-currency).
 *
 * No build step required — uses Options API with runtime template strings
 * and FluentCart's globally registered Element Plus components.
 */

(function () {
    'use strict';

    /* ------------------------------------------------------------------ */
    /*  REST helper                                                        */
    /* ------------------------------------------------------------------ */

    var config = window.fchubWishlistAdmin || {};

    function restUrl(path) {
        return (config.rest_url || '/wp-json/fchub-wishlist/v1/') + path;
    }

    function request(method, path, body) {
        var opts = {
            method: method === 'PUT' ? 'POST' : method,
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': config.nonce || '',
            },
            credentials: 'same-origin',
        };
        if (method === 'PUT') {
            opts.headers['X-HTTP-Method-Override'] = 'PUT';
        }
        if (body) {
            opts.body = JSON.stringify(body);
        }
        return fetch(restUrl(path), opts).then(function (res) {
            return res.json().then(function (json) {
                if (!res.ok) throw json;
                return json.data || json;
            });
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Sub-components                                                     */
    /* ------------------------------------------------------------------ */

    var GeneralSettings = {
        name: 'GeneralSettings',
        props: { settings: { type: Object, required: true } },
        template: '\
<div>\
    <div class="fchub-wl-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Wishlist Enabled</span>\
            <div class="form-note">Master switch for the wishlist feature across the store.</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-radio-group v-model="settings.enabled">\
                <el-radio label="Enabled" value="yes" />\
                <el-radio label="Disabled" value="no" />\
            </el-radio-group>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-wl-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Guest Wishlist</span>\
            <div class="form-note">Allow non-logged-in visitors to use wishlists via cookies.</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-radio-group v-model="settings.guest_wishlist_enabled">\
                <el-radio label="Enabled" value="yes" />\
                <el-radio label="Disabled" value="no" />\
            </el-radio-group>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-wl-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Auto-remove Purchased Items</span>\
            <div class="form-note">Automatically remove items from the wishlist when purchased.</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-radio-group v-model="settings.auto_remove_purchased">\
                <el-radio label="Yes" value="yes" />\
                <el-radio label="No" value="no" />\
            </el-radio-group>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-wl-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Max Items per Wishlist</span>\
        </div>\
        <div class="setting-fields-inner">\
            <el-input-number v-model="settings.max_items_per_list" :min="1" :max="500" />\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-wl-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Guest Cleanup (days)</span>\
            <div class="form-note">Guest wishlists older than this will be removed by the daily cleanup job.</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-input-number v-model="settings.guest_cleanup_days" :min="7" :max="365" />\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-wl-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Remove Data on Uninstall</span>\
            <div class="form-note">If enabled, all wishlist tables and settings will be removed when the plugin is uninstalled.</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-radio-group v-model="settings.uninstall_remove_data">\
                <el-radio label="No (keep data)" value="no" />\
                <el-radio label="Yes (delete all)" value="yes" />\
            </el-radio-group>\
        </div>\
    </div>\
</div>',
    };

    var UiSettings = {
        name: 'UiSettings',
        props: { settings: { type: Object, required: true } },
        template: '\
<div>\
    <div class="fchub-wl-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Show Heart on Product Cards</span>\
        </div>\
        <div class="setting-fields-inner">\
            <el-radio-group v-model="settings.show_on_product_cards">\
                <el-radio label="Yes" value="yes" />\
                <el-radio label="No" value="no" />\
            </el-radio-group>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-wl-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Show Button on Single Product</span>\
        </div>\
        <div class="setting-fields-inner">\
            <el-radio-group v-model="settings.show_on_single_product">\
                <el-radio label="Yes" value="yes" />\
                <el-radio label="No" value="no" />\
            </el-radio-group>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-wl-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Icon Style</span>\
        </div>\
        <div class="setting-fields-inner">\
            <el-select v-model="settings.icon_style" style="max-width:200px">\
                <el-option label="Heart" value="heart" />\
                <el-option label="Bookmark" value="bookmark" />\
                <el-option label="Star" value="star" />\
            </el-select>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-wl-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Button Text (Add)</span>\
        </div>\
        <div class="setting-fields-inner">\
            <el-input v-model="settings.button_text" style="max-width:320px" />\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-wl-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Button Text (Remove)</span>\
        </div>\
        <div class="setting-fields-inner">\
            <el-input v-model="settings.button_text_remove" style="max-width:320px" />\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-wl-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Counter Badge in Header</span>\
            <div class="form-note">Show a wishlist item count badge via the [fchub_wishlist_count] shortcode or wp_footer hook.</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-radio-group v-model="settings.counter_badge_enabled">\
                <el-radio label="Yes" value="yes" />\
                <el-radio label="No" value="no" />\
            </el-radio-group>\
        </div>\
    </div>\
</div>',
    };

    var FluentCrmSettings = {
        name: 'FluentCrmSettings',
        props: { settings: { type: Object, required: true } },
        template: '\
<div>\
    <div class="fchub-wl-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">FluentCRM Tag Sync</span>\
            <div class="form-note">When enabled, tags are automatically applied/removed in FluentCRM based on wishlist activity.</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-radio-group v-model="settings.fluentcrm_enabled">\
                <el-radio label="Enabled" value="yes" />\
                <el-radio label="Disabled" value="no" />\
            </el-radio-group>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-wl-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Tag Prefix</span>\
            <div class="form-note">Prefix for auto-created tags (e.g. \u201cwishlist:active\u201d).</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-input v-model="settings.fluentcrm_tag_prefix" placeholder="wishlist:" style="max-width:200px" autocomplete="one-time-code" />\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-wl-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Auto-create Tags</span>\
            <div class="form-note">Automatically create tags in FluentCRM if they do not already exist.</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-radio-group v-model="settings.fluentcrm_auto_create_tags">\
                <el-radio label="Yes" value="yes" />\
                <el-radio label="No" value="no" />\
            </el-radio-group>\
        </div>\
    </div>\
</div>',
    };

    var StatsView = {
        name: 'StatsView',
        props: {
            stats: { type: Object, required: true },
            loading: { type: Boolean, default: false },
        },
        template: '\
<div v-loading="loading">\
    <div class="fchub-stats-grid">\
        <div class="fct-card fct-card-border">\
            <div class="fct-card-body" style="text-align:center">\
                <div class="fchub-stat-value">{{ stats.total_items }}</div>\
                <div class="fchub-stat-label">Total Wishlisted Items</div>\
            </div>\
        </div>\
        <div class="fct-card fct-card-border">\
            <div class="fct-card-body" style="text-align:center">\
                <div class="fchub-stat-value">{{ stats.total_wishlists }}</div>\
                <div class="fchub-stat-label">Active Wishlists</div>\
            </div>\
        </div>\
        <div class="fct-card fct-card-border">\
            <div class="fct-card-body" style="text-align:center">\
                <div class="fchub-stat-value">{{ stats.active_wishlists }}</div>\
                <div class="fchub-stat-label">Active (last 30 days)</div>\
            </div>\
        </div>\
        <div class="fct-card fct-card-border">\
            <div class="fct-card-body" style="text-align:center">\
                <div class="fchub-stat-value">{{ stats.average_items }}</div>\
                <div class="fchub-stat-label">Avg Items per Wishlist</div>\
            </div>\
        </div>\
    </div>\
    <div v-if="stats.most_wishlisted && stats.most_wishlisted.length" style="margin-top:20px">\
        <h3 style="font-size:16px;font-weight:500;margin:0 0 12px">Most Wishlisted Products</h3>\
        <el-table :data="stats.most_wishlisted" stripe size="small">\
            <el-table-column label="Product">\
                <template v-slot="scope">\
                    <a :href="\'admin.php?page=fluent-cart#/products/\' + scope.row.product_id" style="color:var(--el-color-primary);text-decoration:none">{{ scope.row.product_title || \'(Deleted)\' }}</a>\
                </template>\
            </el-table-column>\
            <el-table-column prop="wishlist_count" label="Times Wishlisted" width="160" align="center" />\
        </el-table>\
    </div>\
    <div v-else-if="!loading" style="padding:40px;text-align:center;color:#909399">\
        No wishlist data yet.\
    </div>\
</div>',
    };

    /* ------------------------------------------------------------------ */
    /*  Main page component                                                */
    /* ------------------------------------------------------------------ */

    var WishlistPage = {
        name: 'WishlistPage',
        components: {
            GeneralSettings: GeneralSettings,
            UiSettings: UiSettings,
            FluentCrmSettings: FluentCrmSettings,
            StatsView: StatsView,
        },
        data: function () {
            return {
                activeTab: 'general',
                loading: true,
                saving: false,
                statsLoading: true,
                settings: {
                    enabled: 'yes',
                    guest_wishlist_enabled: 'yes',
                    auto_remove_purchased: 'yes',
                    max_items_per_list: 100,
                    show_on_product_cards: 'yes',
                    show_on_single_product: 'yes',
                    icon_style: 'heart',
                    button_text: 'Add to Wishlist',
                    button_text_remove: 'Remove from Wishlist',
                    counter_badge_enabled: 'yes',
                    email_reminder_enabled: 'no',
                    email_reminder_days: 14,
                    guest_cleanup_days: 30,
                    fluentcrm_enabled: 'yes',
                    fluentcrm_tag_prefix: 'wishlist:',
                    fluentcrm_auto_create_tags: 'yes',
                    uninstall_remove_data: 'no',
                },
                stats: {
                    total_items: 0,
                    total_wishlists: 0,
                    active_wishlists: 0,
                    average_items: 0,
                    most_wishlisted: [],
                },
            };
        },
        mounted: function () {
            this.loadSettings();
            this.loadStats();
            this.changeTitle('Wishlist');
            document.addEventListener('keydown', this.onKeyDown);
        },
        beforeUnmount: function () {
            document.removeEventListener('keydown', this.onKeyDown);
        },
        methods: {
            loadSettings: function () {
                var vm = this;
                vm.loading = true;
                request('GET', 'admin/settings')
                    .then(function (data) {
                        Object.assign(vm.settings, data);
                    })
                    .catch(function () {
                        vm.$message.error('Failed to load settings.');
                    })
                    .finally(function () {
                        vm.loading = false;
                    });
            },
            loadStats: function () {
                var vm = this;
                vm.statsLoading = true;
                request('GET', 'admin/stats')
                    .then(function (data) {
                        Object.assign(vm.stats, data);
                    })
                    .finally(function () {
                        vm.statsLoading = false;
                    });
            },
            saveSettings: function () {
                var vm = this;
                vm.saving = true;
                request('PUT', 'admin/settings', vm.settings)
                    .then(function (data) {
                        Object.assign(vm.settings, data);
                        vm.$message.success('Settings saved.');
                    })
                    .catch(function () {
                        vm.$message.error('Failed to save settings.');
                    })
                    .finally(function () {
                        vm.saving = false;
                    });
            },
            onKeyDown: function (e) {
                if ((e.metaKey || e.ctrlKey) && e.key === 's') {
                    e.preventDefault();
                    this.saveSettings();
                }
            },
        },
        template: '\
<div class="setting-wrap fchub-wishlist-page">\
    <div class="fct-setting-header">\
        <div class="fct-setting-header-content">\
            <h3 class="fct-setting-head-title">Wishlist</h3>\
        </div>\
        <div class="fct-setting-header-action">\
            <el-button type="primary" size="small" :loading="saving" @click="saveSettings">\
                <span v-if="!saving" class="cmd">\u2318S</span>\
                {{ saving ? "Saving..." : "Save" }}\
            </el-button>\
        </div>\
    </div>\
    <div class="setting-wrap-inner">\
        <el-tabs v-model="activeTab">\
            <el-tab-pane label="General" name="general">\
                <div class="form-section"><div class="fct-card"><div class="fct-card-body" v-loading="loading">\
                    <general-settings :settings="settings" />\
                </div></div></div>\
            </el-tab-pane>\
            <el-tab-pane label="Display" name="display">\
                <div class="form-section"><div class="fct-card"><div class="fct-card-body" v-loading="loading">\
                    <ui-settings :settings="settings" />\
                </div></div></div>\
            </el-tab-pane>\
            <el-tab-pane label="FluentCRM" name="fluentcrm">\
                <div class="form-section"><div class="fct-card"><div class="fct-card-body" v-loading="loading">\
                    <fluent-crm-settings :settings="settings" />\
                </div></div></div>\
            </el-tab-pane>\
            <el-tab-pane label="Statistics" name="stats">\
                <div class="form-section"><div class="fct-card"><div class="fct-card-body">\
                    <stats-view :stats="stats" :loading="statsLoading" />\
                </div></div></div>\
            </el-tab-pane>\
        </el-tabs>\
    </div>\
</div>',
    };

    /* ------------------------------------------------------------------ */
    /*  Route registration                                                 */
    /* ------------------------------------------------------------------ */

    window.fluent_cart_admin.hooks.addFilter(
        'fluent_cart_routes',
        'fchub_wishlist',
        function (routes) {
            if (routes.settings && routes.settings.children) {
                routes.settings.children.push({
                    name: 'fchub_wishlist',
                    path: 'wishlist',
                    component: WishlistPage,
                    meta: {
                        active_menu: 'settings',
                        title: 'Wishlist',
                    },
                });
            }
            return routes;
        }
    );

    /* ------------------------------------------------------------------ */
    /*  Inject "Wishlist" into the settings sidebar (DOM)                   */
    /*  Inserts a nav item into the settings sidebar, matching the same    */
    /*  pattern used by multi-currency and other FluentCart settings pages. */
    /* ------------------------------------------------------------------ */

    var WL_HASH = '#/settings/wishlist';
    var WL_ICON = '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 17.5s-7-4.35-7-9.15A3.82 3.82 0 0 1 6.85 4.5c1.52 0 2.5.77 3.15 1.58C10.65 5.27 11.63 4.5 13.15 4.5A3.82 3.82 0 0 1 17 8.35c0 4.8-7 9.15-7 9.15z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    var WL_CHEVRON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 14" fill="none"><path d="M1 13L6.29289 7.70711C6.62623 7.37377 6.79289 7.20711 6.79289 7C6.79289 6.79289 6.62623 6.62623 6.29289 6.29289L1 1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    function isWishlistRoute() {
        return window.location.hash.indexOf('/settings/wishlist') !== -1;
    }

    function updateActiveState(navItem) {
        if (isWishlistRoute()) {
            navItem.classList.add('fct-settings-nav-item-active');
        } else {
            navItem.classList.remove('fct-settings-nav-item-active');
        }
    }

    function injectSettingsSidebarItem() {
        var navList = document.querySelector('.fct-settings-nav');
        if (!navList) return false;

        if (navList.querySelector('.fct-settings-nav-item-wishlist')) return true;

        var navItem = document.createElement('li');
        navItem.className = 'fct-settings-nav-item fct-settings-nav-item-wishlist';
        if (isWishlistRoute()) {
            navItem.classList.add('fct-settings-nav-item-active');
        }

        var link = document.createElement('a');
        link.className = 'fct-settings-nav-link';
        link.href = WL_HASH;

        var iconDiv = document.createElement('div');
        iconDiv.className = 'icon';
        iconDiv.innerHTML = WL_ICON;

        var labelSpan = document.createElement('span');
        labelSpan.className = 'fct-settings-nav-link-text';
        labelSpan.textContent = 'Wishlist';

        var chevronDiv = document.createElement('div');
        chevronDiv.className = 'icon fct-settings-nav-link-icon';
        chevronDiv.innerHTML = WL_CHEVRON;
        labelSpan.appendChild(chevronDiv);

        link.appendChild(iconDiv);
        link.appendChild(labelSpan);
        navItem.appendChild(link);

        // Insert before "Tax & Duties"
        var inserted = false;
        var items = navList.querySelectorAll(':scope > .fct-settings-nav-item');
        for (var i = 0; i < items.length; i++) {
            var text = items[i].querySelector('.fct-settings-nav-link-text');
            if (text && text.firstChild && text.firstChild.textContent.trim() === 'Tax & Duties') {
                navList.insertBefore(navItem, items[i]);
                inserted = true;
                break;
            }
        }
        if (!inserted) {
            navList.appendChild(navItem);
        }

        window.addEventListener('hashchange', function () {
            updateActiveState(navItem);
        });

        return true;
    }

    function tryInjectSidebar() {
        if (!injectSettingsSidebarItem()) {
            requestAnimationFrame(tryInjectSidebar);
        }
    }

    function onHashChange() {
        if (window.location.hash.indexOf('#/settings') === 0) {
            tryInjectSidebar();
        }
    }

    window.addEventListener('hashchange', onHashChange);

    if (window.location.hash.indexOf('#/settings') === 0) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                tryInjectSidebar();
            });
        } else {
            tryInjectSidebar();
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Minimal CSS                                                        */
    /* ------------------------------------------------------------------ */

    var style = document.createElement('style');
    style.textContent = [
        '.fchub-wishlist-page .form-section { padding: 0; }',
        '.fchub-wl-row { display: grid; gap: 0.5rem; grid-template-columns: 1fr; padding: 4px 0; }',
        '@media (min-width: 1024px) { .fchub-wl-row { grid-template-columns: repeat(3, minmax(0, 1fr)); } .fchub-wl-row .setting-fields-inner { grid-column: span 2 / span 2; } }',
        '.fchub-wishlist-page .cmd { display: inline-block; font-size: 11px; margin-right: 4px; padding: 1px 5px; border: 1px solid rgba(255,255,255,.3); border-radius: 3px; line-height: 1; }',
        '.fchub-stats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; }',
        '.fchub-stat-value { font-size: 32px; font-weight: 600; line-height: 1.2; }',
        '.fchub-stat-label { font-size: 13px; color: #909399; margin-top: 4px; }',
    ].join('\n');
    document.head.appendChild(style);
})();
