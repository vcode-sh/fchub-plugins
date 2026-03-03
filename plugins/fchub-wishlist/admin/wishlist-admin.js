/**
 * FCHub Wishlist — Admin settings page for FluentCart's SPA.
 *
 * Registers a /wishlist route via the fluent_cart_routes filter so the UI
 * lives inside FluentCart's admin shell (sidebar, header, dark-mode toggle).
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
<el-form label-position="top">\
    <el-form-item label="Wishlist Enabled">\
        <el-select v-model="settings.enabled">\
            <el-option label="Yes" value="yes" />\
            <el-option label="No" value="no" />\
        </el-select>\
    </el-form-item>\
    <el-form-item label="Guest Wishlist">\
        <el-select v-model="settings.guest_wishlist_enabled">\
            <el-option label="Yes" value="yes" />\
            <el-option label="No" value="no" />\
        </el-select>\
        <p class="fchub-tip">Allow non-logged-in visitors to use wishlists via cookies.</p>\
    </el-form-item>\
    <el-form-item label="Auto-remove Purchased Items">\
        <el-select v-model="settings.auto_remove_purchased">\
            <el-option label="Yes" value="yes" />\
            <el-option label="No" value="no" />\
        </el-select>\
        <p class="fchub-tip">Automatically remove items from the wishlist when purchased.</p>\
    </el-form-item>\
    <el-form-item label="Max Items per Wishlist">\
        <el-input-number v-model="settings.max_items_per_list" :min="1" :max="500" />\
    </el-form-item>\
    <el-form-item label="Guest Cleanup (days)">\
        <el-input-number v-model="settings.guest_cleanup_days" :min="7" :max="365" />\
        <p class="fchub-tip">Guest wishlists older than this will be removed by the daily cleanup job.</p>\
    </el-form-item>\
    <el-form-item label="Remove Data on Uninstall">\
        <el-select v-model="settings.uninstall_remove_data">\
            <el-option label="No (keep data)" value="no" />\
            <el-option label="Yes (delete all)" value="yes" />\
        </el-select>\
        <p class="fchub-tip">If enabled, all wishlist tables and settings will be removed when the plugin is uninstalled.</p>\
    </el-form-item>\
</el-form>',
    };

    var UiSettings = {
        name: 'UiSettings',
        props: { settings: { type: Object, required: true } },
        template: '\
<el-form label-position="top">\
    <el-form-item label="Show Heart on Product Cards">\
        <el-select v-model="settings.show_on_product_cards">\
            <el-option label="Yes" value="yes" />\
            <el-option label="No" value="no" />\
        </el-select>\
    </el-form-item>\
    <el-form-item label="Show Button on Single Product">\
        <el-select v-model="settings.show_on_single_product">\
            <el-option label="Yes" value="yes" />\
            <el-option label="No" value="no" />\
        </el-select>\
    </el-form-item>\
    <el-form-item label="Icon Style">\
        <el-select v-model="settings.icon_style">\
            <el-option label="Heart" value="heart" />\
            <el-option label="Bookmark" value="bookmark" />\
            <el-option label="Star" value="star" />\
        </el-select>\
    </el-form-item>\
    <el-form-item label="Button Text (Add)">\
        <el-input v-model="settings.button_text" />\
    </el-form-item>\
    <el-form-item label="Button Text (Remove)">\
        <el-input v-model="settings.button_text_remove" />\
    </el-form-item>\
    <el-form-item label="Counter Badge in Header">\
        <el-select v-model="settings.counter_badge_enabled">\
            <el-option label="Yes" value="yes" />\
            <el-option label="No" value="no" />\
        </el-select>\
        <p class="fchub-tip">Show a wishlist item count badge via the [fchub_wishlist_count] shortcode or wp_footer hook.</p>\
    </el-form-item>\
</el-form>',
    };

    var FluentCrmSettings = {
        name: 'FluentCrmSettings',
        props: { settings: { type: Object, required: true } },
        template: '\
<el-form label-position="top">\
    <el-form-item label="FluentCRM Tag Sync">\
        <el-select v-model="settings.fluentcrm_enabled">\
            <el-option label="Enabled" value="yes" />\
            <el-option label="Disabled" value="no" />\
        </el-select>\
        <p class="fchub-tip">When enabled, tags are automatically applied/removed in FluentCRM based on wishlist activity.</p>\
    </el-form-item>\
    <el-form-item label="Tag Prefix">\
        <el-input v-model="settings.fluentcrm_tag_prefix" placeholder="wishlist:" />\
        <p class="fchub-tip">Prefix for auto-created tags (e.g. &ldquo;wishlist:active&rdquo;).</p>\
    </el-form-item>\
    <el-form-item label="Auto-create Tags">\
        <el-select v-model="settings.fluentcrm_auto_create_tags">\
            <el-option label="Yes" value="yes" />\
            <el-option label="No" value="no" />\
        </el-select>\
        <p class="fchub-tip">Automatically create tags in FluentCRM if they do not already exist.</p>\
    </el-form-item>\
</el-form>',
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
<div class="fchub-wishlist-page fct-layout-width">\
    <div class="page-heading-wrap">\
        <h1 class="page-title">Wishlist</h1>\
        <div class="actions">\
            <el-button type="primary" :loading="saving" @click="saveSettings">\
                <span v-if="!saving" class="cmd block leading-none">\u2318S</span>\
                {{ saving ? "Saving..." : "Save Settings" }}\
            </el-button>\
        </div>\
    </div>\
    <div class="fct-card">\
        <div class="fct-card-body">\
            <el-tabs v-model="activeTab">\
                <el-tab-pane label="General" name="general">\
                    <div v-loading="loading" class="fchub-settings-wrap">\
                        <general-settings :settings="settings" />\
                    </div>\
                </el-tab-pane>\
                <el-tab-pane label="Display" name="display">\
                    <div v-loading="loading" class="fchub-settings-wrap">\
                        <ui-settings :settings="settings" />\
                    </div>\
                </el-tab-pane>\
                <el-tab-pane label="FluentCRM" name="fluentcrm">\
                    <div v-loading="loading" class="fchub-settings-wrap">\
                        <fluent-crm-settings :settings="settings" />\
                    </div>\
                </el-tab-pane>\
                <el-tab-pane label="Statistics" name="stats">\
                    <stats-view :stats="stats" :loading="statsLoading" />\
                </el-tab-pane>\
            </el-tabs>\
        </div>\
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
            routes.fchub_wishlist = {
                name: 'fchub_wishlist',
                path: '/wishlist',
                component: WishlistPage,
                meta: {
                    active_menu: 'fchub_wishlist',
                    title: 'Wishlist',
                },
            };
            return routes;
        }
    );

    /* ------------------------------------------------------------------ */
    /*  Inject "Wishlist" into the More dropdown (DOM)                     */
    /*  FluentCart hard-codes the More children after the PHP filter runs, */
    /*  so we append the item client-side once the menu is in the DOM.     */
    /* ------------------------------------------------------------------ */

    function injectMoreMenuItem() {
        var moreMenu = document.querySelector('.fct_menu_item.has-child .fct_menu_child');
        if (!moreMenu || moreMenu.querySelector('.fct_menu_child_item_wishlist')) return;

        var li = document.createElement('li');
        li.className = 'fct_menu_child_item fct_menu_child_item_wishlist';
        var a = document.createElement('a');
        a.setAttribute('type', 'button');
        a.setAttribute('aria-label', 'Wishlist');
        var dashboardLink = document.querySelector('.fct_menu_item a[href*="fluent-cart"]');
        a.href = dashboardLink
            ? dashboardLink.href.split('#')[0] + '#/wishlist'
            : 'admin.php?page=fluent-cart#/wishlist';
        a.textContent = 'Wishlist';
        li.appendChild(a);
        moreMenu.appendChild(li);

        // Also inject into the mobile off-canvas menu
        var offcanvas = document.querySelector('.fct-offcanvas-menu-list');
        if (offcanvas && !offcanvas.querySelector('[href*="#/wishlist"]')) {
            var div = document.createElement('div');
            div.className = 'fct-offcanvas-menu-item';
            div.innerHTML = '<div class="fct-offcanvas-menu-label"><a href="' + a.href + '">Wishlist</a></div>';
            offcanvas.appendChild(div);
        }
    }

    function tryInject() {
        if (document.querySelector('.fct_menu_item.has-child .fct_menu_child')) {
            injectMoreMenuItem();
        } else {
            requestAnimationFrame(tryInject);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { tryInject(1); });
    } else {
        tryInject(1);
    }

    /* ------------------------------------------------------------------ */
    /*  Minimal CSS                                                        */
    /* ------------------------------------------------------------------ */

    var style = document.createElement('style');
    style.textContent = [
        '.fchub-settings-wrap { max-width: 560px; padding: 8px 0; }',
        '.fchub-tip { font-size: 12px; color: #909399; margin: 4px 0 0; line-height: 1.4; }',
        '.fchub-stats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; }',
        '.fchub-stat-value { font-size: 32px; font-weight: 600; line-height: 1.2; }',
        '.fchub-stat-label { font-size: 13px; color: #909399; margin-top: 4px; }',
        '.fchub-wishlist-page .cmd { display: inline-block; font-size: 11px; margin-right: 4px; padding: 1px 5px; border: 1px solid rgba(255,255,255,.3); border-radius: 3px; line-height: 1; }',
    ].join('\n');
    document.head.appendChild(style);
})();
