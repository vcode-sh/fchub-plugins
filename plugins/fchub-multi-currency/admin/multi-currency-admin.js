/**
 * FCHub Multi-Currency — Admin settings page for FluentCart's SPA.
 *
 * Registers a /multi-currency route via the fluent_cart_routes filter so the UI
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

    var config = window.fchubMcAdmin || {};

    function restUrl(path) {
        return (config.rest_url || '/wp-json/fchub-mc/v1/') + path;
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
    <el-form-item label="Multi-Currency Enabled">\
        <el-select v-model="settings.enabled">\
            <el-option label="Yes" value="yes" />\
            <el-option label="No" value="no" />\
        </el-select>\
        <p class="fchub-mc-tip">Master switch for display-layer multi-currency across the store.</p>\
    </el-form-item>\
    <el-form-item label="Base Currency">\
        <el-input v-model="settings.base_currency" placeholder="USD" maxlength="3" style="max-width:120px" />\
        <p class="fchub-mc-tip">ISO 4217 code. All payments are settled in this currency.</p>\
    </el-form-item>\
    <el-form-item label="Default Display Currency">\
        <el-input v-model="settings.default_display_currency" placeholder="USD" maxlength="3" style="max-width:120px" />\
        <p class="fchub-mc-tip">Currency shown to visitors before any preference is detected.</p>\
    </el-form-item>\
    <el-form-item label="URL Parameter">\
        <el-select v-model="settings.url_param_enabled">\
            <el-option label="Yes" value="yes" />\
            <el-option label="No" value="no" />\
        </el-select>\
        <p class="fchub-mc-tip">Allow currency switching via URL (e.g. ?currency=EUR).</p>\
    </el-form-item>\
    <el-form-item v-if="settings.url_param_enabled === \'yes\'" label="URL Parameter Key">\
        <el-input v-model="settings.url_param_key" placeholder="currency" style="max-width:200px" />\
    </el-form-item>\
    <el-form-item label="Cookie Persistence">\
        <el-select v-model="settings.cookie_enabled">\
            <el-option label="Yes" value="yes" />\
            <el-option label="No" value="no" />\
        </el-select>\
    </el-form-item>\
    <el-form-item v-if="settings.cookie_enabled === \'yes\'" label="Cookie Lifetime (days)">\
        <el-input-number v-model="settings.cookie_lifetime_days" :min="1" :max="365" />\
    </el-form-item>\
    <el-form-item label="Remove Data on Uninstall">\
        <el-select v-model="settings.uninstall_remove_data">\
            <el-option label="No (keep data)" value="no" />\
            <el-option label="Yes (delete all)" value="yes" />\
        </el-select>\
        <p class="fchub-mc-tip">If enabled, all tables and settings will be removed when the plugin is uninstalled.</p>\
    </el-form-item>\
</el-form>',
    };

    var CurrencySettings = {
        name: 'CurrencySettings',
        props: { settings: { type: Object, required: true } },
        data: function () {
            return {
                newCurrency: { code: '', name: '', symbol: '', decimals: 2, position: 'left' },
            };
        },
        methods: {
            addCurrency: function () {
                if (!this.newCurrency.code || !this.newCurrency.name) return;
                if (!this.settings.display_currencies) {
                    this.settings.display_currencies = [];
                }
                this.settings.display_currencies.push({
                    code: this.newCurrency.code.toUpperCase(),
                    name: this.newCurrency.name,
                    symbol: this.newCurrency.symbol || this.newCurrency.code.toUpperCase(),
                    decimals: this.newCurrency.decimals,
                    position: this.newCurrency.position,
                });
                this.newCurrency = { code: '', name: '', symbol: '', decimals: 2, position: 'left' };
            },
            removeCurrency: function (index) {
                this.settings.display_currencies.splice(index, 1);
            },
        },
        template: '\
<div>\
    <el-table :data="settings.display_currencies || []" stripe size="small" style="margin-bottom:20px">\
        <el-table-column prop="code" label="Code" width="80" />\
        <el-table-column prop="name" label="Name" />\
        <el-table-column prop="symbol" label="Symbol" width="80" />\
        <el-table-column prop="decimals" label="Decimals" width="90" align="center" />\
        <el-table-column prop="position" label="Position" width="100" />\
        <el-table-column label="" width="80" align="center">\
            <template v-slot="scope">\
                <el-button type="danger" size="small" text @click="removeCurrency(scope.$index)">&times;</el-button>\
            </template>\
        </el-table-column>\
    </el-table>\
    <div class="fct-card fct-card-border" style="padding:16px">\
        <h4 style="margin:0 0 12px;font-size:14px;font-weight:500">Add Currency</h4>\
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">\
            <el-form-item label="Code" style="margin-bottom:0">\
                <el-input v-model="newCurrency.code" placeholder="EUR" maxlength="3" style="width:80px" />\
            </el-form-item>\
            <el-form-item label="Name" style="margin-bottom:0">\
                <el-input v-model="newCurrency.name" placeholder="Euro" style="width:160px" />\
            </el-form-item>\
            <el-form-item label="Symbol" style="margin-bottom:0">\
                <el-input v-model="newCurrency.symbol" placeholder="&euro;" style="width:80px" />\
            </el-form-item>\
            <el-form-item label="Decimals" style="margin-bottom:0">\
                <el-input-number v-model="newCurrency.decimals" :min="0" :max="4" style="width:90px" />\
            </el-form-item>\
            <el-form-item label="Position" style="margin-bottom:0">\
                <el-select v-model="newCurrency.position" style="width:120px">\
                    <el-option label="Left ($100)" value="left" />\
                    <el-option label="Right (100$)" value="right" />\
                    <el-option label="Left space ($ 100)" value="left_space" />\
                    <el-option label="Right space (100 $)" value="right_space" />\
                </el-select>\
            </el-form-item>\
            <el-button type="primary" @click="addCurrency" :disabled="!newCurrency.code || !newCurrency.name">Add</el-button>\
        </div>\
    </div>\
</div>',
    };

    var RateSettings = {
        name: 'RateSettings',
        props: {
            settings: { type: Object, required: true },
            rates: { type: Array, default: function () { return []; } },
            ratesLoading: { type: Boolean, default: false },
        },
        template: '\
<div>\
    <el-form label-position="top" style="max-width:560px">\
        <el-form-item label="Rate Provider">\
            <el-select v-model="settings.rate_provider">\
                <el-option label="ExchangeRate-API (free tier)" value="exchange_rate_api" />\
                <el-option label="Open Exchange Rates" value="open_exchange_rates" />\
                <el-option label="European Central Bank (free)" value="ecb" />\
                <el-option label="Manual rates" value="manual" />\
            </el-select>\
        </el-form-item>\
        <el-form-item v-if="settings.rate_provider !== \'ecb\' && settings.rate_provider !== \'manual\'" label="API Key">\
            <el-input v-model="settings.rate_provider_api_key" type="password" show-password />\
        </el-form-item>\
        <el-form-item label="Refresh Interval (hours)">\
            <el-input-number v-model="settings.rate_refresh_interval_hrs" :min="1" :max="168" />\
        </el-form-item>\
        <el-form-item label="Stale Threshold (hours)">\
            <el-input-number v-model="settings.stale_threshold_hrs" :min="1" :max="720" />\
            <p class="fchub-mc-tip">Rates older than this will trigger an admin warning.</p>\
        </el-form-item>\
        <el-form-item label="Stale Rate Fallback">\
            <el-select v-model="settings.stale_fallback">\
                <el-option label="Show base currency" value="base" />\
                <el-option label="Use last known rate" value="last_known" />\
            </el-select>\
        </el-form-item>\
        <el-form-item label="Rounding Mode">\
            <el-select v-model="settings.rounding_mode">\
                <el-option label="No rounding" value="none" />\
                <el-option label="Round half up (standard)" value="half_up" />\
                <el-option label="Round half down" value="half_down" />\
                <el-option label="Always round up" value="ceil" />\
                <el-option label="Always round down" value="floor" />\
            </el-select>\
        </el-form-item>\
    </el-form>\
    <div style="margin-top:20px">\
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">\
            <h3 style="font-size:16px;font-weight:500;margin:0">Current Rates</h3>\
            <el-button size="small" @click="$emit(\'refresh-rates\')" :loading="ratesLoading">Refresh Now</el-button>\
        </div>\
        <el-table v-if="rates.length" :data="rates" stripe size="small">\
            <el-table-column prop="base_currency" label="Base" width="80" />\
            <el-table-column prop="quote_currency" label="Quote" width="80" />\
            <el-table-column prop="rate" label="Rate" />\
            <el-table-column prop="provider" label="Provider" width="160" />\
            <el-table-column prop="fetched_at" label="Fetched" width="180" />\
            <el-table-column label="Status" width="80" align="center">\
                <template v-slot="scope">\
                    <el-tag :type="scope.row.is_stale ? \'danger\' : \'success\'" size="small">{{ scope.row.is_stale ? "Stale" : "OK" }}</el-tag>\
                </template>\
            </el-table-column>\
        </el-table>\
        <div v-else-if="!ratesLoading" style="padding:40px;text-align:center;color:#909399">\
            No exchange rates yet. Add display currencies and trigger a refresh.\
        </div>\
    </div>\
</div>',
    };

    var CheckoutSettings = {
        name: 'CheckoutSettings',
        props: { settings: { type: Object, required: true } },
        template: '\
<el-form label-position="top" style="max-width:560px">\
    <el-form-item label="Checkout Disclosure">\
        <el-select v-model="settings.checkout_disclosure_enabled">\
            <el-option label="Yes" value="yes" />\
            <el-option label="No" value="no" />\
        </el-select>\
        <p class="fchub-mc-tip">Show a notice at checkout that payment is processed in the base currency.</p>\
    </el-form-item>\
    <el-form-item v-if="settings.checkout_disclosure_enabled === \'yes\'" label="Disclosure Text">\
        <el-input v-model="settings.checkout_disclosure_text" type="textarea" :rows="3" />\
        <p class="fchub-mc-tip">Supports placeholders: {base_currency}, {display_currency}, {rate}</p>\
    </el-form-item>\
    <el-form-item label="Show Rate Freshness Badge">\
        <el-select v-model="settings.show_rate_freshness_badge">\
            <el-option label="Yes" value="yes" />\
            <el-option label="No" value="no" />\
        </el-select>\
    </el-form-item>\
</el-form>',
    };

    var CrmSettings = {
        name: 'CrmSettings',
        props: { settings: { type: Object, required: true } },
        template: '\
<el-form label-position="top" style="max-width:560px">\
    <h3 style="font-size:16px;font-weight:500;margin:0 0 16px">FluentCRM</h3>\
    <el-form-item label="FluentCRM Sync">\
        <el-select v-model="settings.fluentcrm_enabled">\
            <el-option label="Enabled" value="yes" />\
            <el-option label="Disabled" value="no" />\
        </el-select>\
        <p class="fchub-mc-tip">Tag contacts and update custom fields based on currency preference.</p>\
    </el-form-item>\
    <el-form-item v-if="settings.fluentcrm_enabled === \'yes\'" label="Auto-create Tags">\
        <el-select v-model="settings.fluentcrm_auto_create_tags">\
            <el-option label="Yes" value="yes" />\
            <el-option label="No" value="no" />\
        </el-select>\
    </el-form-item>\
    <el-form-item v-if="settings.fluentcrm_enabled === \'yes\'" label="Tag Prefix">\
        <el-input v-model="settings.fluentcrm_tag_prefix" placeholder="currency:" style="max-width:200px" />\
        <p class="fchub-mc-tip">Tags created as {prefix}{CODE}, e.g. currency:EUR</p>\
    </el-form-item>\
    <el-form-item v-if="settings.fluentcrm_enabled === \'yes\'" label="Preferred Currency Field">\
        <el-input v-model="settings.fluentcrm_field_preferred" style="max-width:300px" />\
    </el-form-item>\
    <el-form-item v-if="settings.fluentcrm_enabled === \'yes\'" label="Last Order Currency Field">\
        <el-input v-model="settings.fluentcrm_field_last_order" style="max-width:300px" />\
    </el-form-item>\
    <el-form-item v-if="settings.fluentcrm_enabled === \'yes\'" label="Last Order FX Rate Field">\
        <el-input v-model="settings.fluentcrm_field_last_rate" style="max-width:300px" />\
    </el-form-item>\
    <div style="border-top:1px solid var(--el-border-color);padding-top:20px;margin-top:20px">\
        <h3 style="font-size:16px;font-weight:500;margin:0 0 16px">FluentCommunity</h3>\
        <el-form-item label="FluentCommunity Sync">\
            <el-select v-model="settings.fluentcommunity_enabled">\
                <el-option label="Enabled" value="yes" />\
                <el-option label="Disabled" value="no" />\
            </el-select>\
            <p class="fchub-mc-tip">Sync currency preference to FluentCommunity user meta.</p>\
        </el-form-item>\
    </div>\
</el-form>',
    };

    var DiagnosticsView = {
        name: 'DiagnosticsView',
        props: {
            diagnostics: { type: Object, default: function () { return {}; } },
            loading: { type: Boolean, default: false },
        },
        template: '\
<div v-loading="loading">\
    <div v-if="diagnostics.plugin_version" class="fchub-mc-diag-grid">\
        <div class="fct-card fct-card-border">\
            <div class="fct-card-body">\
                <h4 style="margin:0 0 12px;font-size:14px;font-weight:500">Plugin</h4>\
                <div class="fchub-mc-diag-row"><span>Version</span><span>{{ diagnostics.plugin_version }}</span></div>\
                <div class="fchub-mc-diag-row"><span>DB Version</span><span>{{ diagnostics.db_version }}</span></div>\
                <div class="fchub-mc-diag-row"><span>Base Currency</span><span>{{ diagnostics.base_currency }}</span></div>\
                <div class="fchub-mc-diag-row"><span>PHP</span><span>{{ diagnostics.php_version }}</span></div>\
                <div class="fchub-mc-diag-row"><span>bcmath</span><el-tag :type="diagnostics.bcmath_available ? \'success\' : \'danger\'" size="small">{{ diagnostics.bcmath_available ? "Yes" : "No" }}</el-tag></div>\
            </div>\
        </div>\
        <div class="fct-card fct-card-border">\
            <div class="fct-card-body">\
                <h4 style="margin:0 0 12px;font-size:14px;font-weight:500">Dependencies</h4>\
                <div class="fchub-mc-diag-row"><span>FluentCart</span><el-tag :type="diagnostics.fluentcart_active ? \'success\' : \'danger\'" size="small">{{ diagnostics.fluentcart_active ? "Active" : "Missing" }}</el-tag></div>\
                <div class="fchub-mc-diag-row"><span>FluentCRM</span><el-tag :type="diagnostics.fluentcrm_active ? \'success\' : \'info\'" size="small">{{ diagnostics.fluentcrm_active ? "Active" : "Not found" }}</el-tag></div>\
            </div>\
        </div>\
        <div class="fct-card fct-card-border">\
            <div class="fct-card-body">\
                <h4 style="margin:0 0 12px;font-size:14px;font-weight:500">Rates</h4>\
                <div class="fchub-mc-diag-row"><span>Total rates</span><span>{{ diagnostics.rate_count }}</span></div>\
                <div class="fchub-mc-diag-row"><span>Stale rates</span><el-tag :type="diagnostics.stale_rates && diagnostics.stale_rates.length ? \'danger\' : \'success\'" size="small">{{ diagnostics.stale_rates ? diagnostics.stale_rates.length : 0 }}</el-tag></div>\
            </div>\
        </div>\
        <div class="fct-card fct-card-border">\
            <div class="fct-card-body">\
                <h4 style="margin:0 0 12px;font-size:14px;font-weight:500">Feature Flags</h4>\
                <div v-for="(val, key) in diagnostics.feature_flags" :key="key" class="fchub-mc-diag-row">\
                    <span>{{ key }}</span>\
                    <el-tag :type="val ? \'success\' : \'info\'" size="small">{{ val ? "On" : "Off" }}</el-tag>\
                </div>\
            </div>\
        </div>\
    </div>\
    <div v-else-if="!loading" style="padding:40px;text-align:center;color:#909399">\
        No diagnostics data.\
    </div>\
</div>',
    };

    /* ------------------------------------------------------------------ */
    /*  Main page component                                                */
    /* ------------------------------------------------------------------ */

    var MultiCurrencyPage = {
        name: 'MultiCurrencyPage',
        components: {
            GeneralSettings: GeneralSettings,
            CurrencySettings: CurrencySettings,
            RateSettings: RateSettings,
            CheckoutSettings: CheckoutSettings,
            CrmSettings: CrmSettings,
            DiagnosticsView: DiagnosticsView,
        },
        data: function () {
            return {
                activeTab: 'general',
                loading: true,
                saving: false,
                ratesLoading: false,
                diagLoading: false,
                settings: {},
                rates: [],
                diagnostics: {},
            };
        },
        mounted: function () {
            this.loadSettings();
            this.loadRates();
            this.changeTitle('Multi-Currency');
            document.addEventListener('keydown', this.onKeyDown);
        },
        beforeUnmount: function () {
            document.removeEventListener('keydown', this.onKeyDown);
        },
        watch: {
            activeTab: function (tab) {
                if (tab === 'diagnostics' && !this.diagnostics.plugin_version) {
                    this.loadDiagnostics();
                }
            },
        },
        methods: {
            loadSettings: function () {
                var vm = this;
                vm.loading = true;
                request('GET', 'admin/settings')
                    .then(function (data) {
                        vm.settings = data;
                    })
                    .catch(function () {
                        vm.$message.error('Failed to load settings.');
                    })
                    .finally(function () {
                        vm.loading = false;
                    });
            },
            loadRates: function () {
                var vm = this;
                vm.ratesLoading = true;
                request('GET', 'admin/rates')
                    .then(function (data) {
                        vm.rates = data.rates || [];
                    })
                    .finally(function () {
                        vm.ratesLoading = false;
                    });
            },
            loadDiagnostics: function () {
                var vm = this;
                vm.diagLoading = true;
                request('GET', 'admin/diagnostics')
                    .then(function (data) {
                        vm.diagnostics = data;
                    })
                    .finally(function () {
                        vm.diagLoading = false;
                    });
            },
            refreshRates: function () {
                var vm = this;
                vm.ratesLoading = true;
                request('POST', 'admin/rates/refresh')
                    .then(function () {
                        vm.$message.success('Rates refreshed.');
                        vm.loadRates();
                    })
                    .catch(function () {
                        vm.$message.error('Rate refresh failed.');
                        vm.ratesLoading = false;
                    });
            },
            saveSettings: function () {
                var vm = this;
                vm.saving = true;
                request('POST', 'admin/settings', vm.settings)
                    .then(function (data) {
                        vm.settings = data;
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
<div class="fchub-mc-page fct-layout-width">\
    <div class="page-heading-wrap">\
        <h1 class="page-title">Multi-Currency</h1>\
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
                    <div v-loading="loading" class="fchub-mc-settings-wrap">\
                        <general-settings :settings="settings" />\
                    </div>\
                </el-tab-pane>\
                <el-tab-pane label="Currencies" name="currencies">\
                    <div v-loading="loading">\
                        <currency-settings :settings="settings" />\
                    </div>\
                </el-tab-pane>\
                <el-tab-pane label="Exchange Rates" name="rates">\
                    <rate-settings :settings="settings" :rates="rates" :rates-loading="ratesLoading" @refresh-rates="refreshRates" />\
                </el-tab-pane>\
                <el-tab-pane label="Checkout" name="checkout">\
                    <div v-loading="loading" class="fchub-mc-settings-wrap">\
                        <checkout-settings :settings="settings" />\
                    </div>\
                </el-tab-pane>\
                <el-tab-pane label="CRM" name="crm">\
                    <div v-loading="loading" class="fchub-mc-settings-wrap">\
                        <crm-settings :settings="settings" />\
                    </div>\
                </el-tab-pane>\
                <el-tab-pane label="Diagnostics" name="diagnostics">\
                    <diagnostics-view :diagnostics="diagnostics" :loading="diagLoading" />\
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
        'fchub_multi_currency',
        function (routes) {
            routes.fchub_multi_currency = {
                name: 'fchub_multi_currency',
                path: '/multi-currency',
                component: MultiCurrencyPage,
                meta: {
                    active_menu: 'fchub_multi_currency',
                    title: 'Multi-Currency',
                },
            };
            return routes;
        }
    );

    /* ------------------------------------------------------------------ */
    /*  Inject "Multi-Currency" into the More dropdown (DOM)               */
    /*  FluentCart hard-codes the More children after the PHP filter runs, */
    /*  so we append the item client-side once the menu is in the DOM.     */
    /* ------------------------------------------------------------------ */

    function injectMoreMenuItem() {
        var moreMenu = document.querySelector('.fct_menu_item.has-child .fct_menu_child');
        if (!moreMenu || moreMenu.querySelector('.fct_menu_child_item_multi_currency')) return;

        var li = document.createElement('li');
        li.className = 'fct_menu_child_item fct_menu_child_item_multi_currency';
        var a = document.createElement('a');
        a.setAttribute('type', 'button');
        a.setAttribute('aria-label', 'Multi-Currency');
        var dashboardLink = document.querySelector('.fct_menu_item a[href*="fluent-cart"]');
        a.href = dashboardLink
            ? dashboardLink.href.split('#')[0] + '#/multi-currency'
            : 'admin.php?page=fluent-cart#/multi-currency';
        a.textContent = 'Multi-Currency';
        li.appendChild(a);
        moreMenu.appendChild(li);

        var offcanvas = document.querySelector('.fct-offcanvas-menu-list');
        if (offcanvas && !offcanvas.querySelector('[href*="#/multi-currency"]')) {
            var div = document.createElement('div');
            div.className = 'fct-offcanvas-menu-item';
            div.innerHTML = '<div class="fct-offcanvas-menu-label"><a href="' + a.href + '">Multi-Currency</a></div>';
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
        document.addEventListener('DOMContentLoaded', function () { tryInject(); });
    } else {
        tryInject();
    }

    /* ------------------------------------------------------------------ */
    /*  Minimal CSS                                                        */
    /* ------------------------------------------------------------------ */

    var style = document.createElement('style');
    style.textContent = [
        '.fchub-mc-settings-wrap { max-width: 560px; padding: 8px 0; }',
        '.fchub-mc-tip { font-size: 12px; color: #909399; margin: 4px 0 0; line-height: 1.4; }',
        '.fchub-mc-page .cmd { display: inline-block; font-size: 11px; margin-right: 4px; padding: 1px 5px; border: 1px solid rgba(255,255,255,.3); border-radius: 3px; line-height: 1; }',
        '.fchub-mc-diag-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }',
        '.fchub-mc-diag-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid var(--el-border-color-lighter); font-size: 13px; }',
        '.fchub-mc-diag-row:last-child { border-bottom: none; }',
    ].join('\n');
    document.head.appendChild(style);
})();
