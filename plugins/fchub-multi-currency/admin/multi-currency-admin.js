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

    var catalogue = config.currency_catalogue || [];
    var catalogueMap = {};
    catalogue.forEach(function (c) { catalogueMap[c.code] = c; });

    var GeneralSettings = {
        name: 'GeneralSettings',
        props: { settings: { type: Object, required: true } },
        data: function () {
            return { catalogue: catalogue };
        },
        computed: {
            defaultDisplayOptions: function () {
                var base = this.settings.base_currency;
                var display = this.settings.display_currencies || [];
                var codes = {};
                if (base) codes[base] = true;
                display.forEach(function (d) { codes[d.code] = true; });
                return catalogue.filter(function (c) { return codes[c.code]; });
            },
        },
        template: '\
<div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Multi-Currency Enabled</span>\
            <div class="form-note">Master switch for display-layer multi-currency across the store.</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-radio-group v-model="settings.enabled">\
                <el-radio label="Enabled" value="yes" />\
                <el-radio label="Disabled" value="no" />\
            </el-radio-group>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Base Currency</span>\
            <div class="form-note">ISO 4217 code. All payments are settled in this currency.</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-select v-model="settings.base_currency" filterable placeholder="Select currency" style="max-width:320px">\
                <el-option v-for="c in catalogue" :key="c.code" :label="c.flag + \' \' + c.code + \' \\u2014 \' + c.name" :value="c.code" />\
            </el-select>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Default Display Currency</span>\
            <div class="form-note">Currency shown to visitors before any preference is detected.</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-select v-model="settings.default_display_currency" filterable placeholder="Select currency" style="max-width:320px">\
                <el-option v-for="c in defaultDisplayOptions" :key="c.code" :label="c.flag + \' \' + c.code + \' \\u2014 \' + c.name" :value="c.code" />\
            </el-select>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">URL Parameter</span>\
            <div class="form-note">Allow currency switching via URL (e.g. ?currency=EUR).</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-radio-group v-model="settings.url_param_enabled">\
                <el-radio label="Enabled" value="yes" />\
                <el-radio label="Disabled" value="no" />\
            </el-radio-group>\
        </div>\
    </div>\
    <div v-if="settings.url_param_enabled === \'yes\'">\
        <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
        <div class="fchub-mc-row">\
            <div class="setting-html-wrapper">\
                <span class="setting-label">URL Parameter Key</span>\
            </div>\
            <div class="setting-fields-inner">\
                <el-input v-model="settings.url_param_key" placeholder="currency" style="max-width:200px" autocomplete="one-time-code" />\
            </div>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Cookie Persistence</span>\
            <div class="form-note">Remember visitor currency preference in browser cookies.</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-radio-group v-model="settings.cookie_enabled">\
                <el-radio label="Enabled" value="yes" />\
                <el-radio label="Disabled" value="no" />\
            </el-radio-group>\
        </div>\
    </div>\
    <div v-if="settings.cookie_enabled === \'yes\'">\
        <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
        <div class="fchub-mc-row">\
            <div class="setting-html-wrapper">\
                <span class="setting-label">Cookie Lifetime (days)</span>\
            </div>\
            <div class="setting-fields-inner">\
                <el-input-number v-model="settings.cookie_lifetime_days" :min="1" :max="365" />\
            </div>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Remove Data on Uninstall</span>\
            <div class="form-note">If enabled, all tables and settings will be removed when the plugin is uninstalled.</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-radio-group v-model="settings.uninstall_remove_data">\
                <el-radio label="Keep data" value="no" />\
                <el-radio label="Delete all" value="yes" />\
            </el-radio-group>\
        </div>\
    </div>\
</div>',
    };

    var CurrencySettings = {
        name: 'CurrencySettings',
        props: { settings: { type: Object, required: true } },
        data: function () {
            return { pickerValue: '', catalogueMap: catalogueMap };
        },
        computed: {
            availableCurrencies: function () {
                var added = {};
                (this.settings.display_currencies || []).forEach(function (d) { added[d.code] = true; });
                return catalogue.filter(function (c) { return !added[c.code]; });
            },
        },
        methods: {
            onPick: function (code) {
                if (!code) return;
                var entry = catalogueMap[code];
                if (!entry) return;
                if (!this.settings.display_currencies) {
                    this.settings.display_currencies = [];
                }
                this.settings.display_currencies.push({
                    code: entry.code,
                    name: entry.name,
                    symbol: entry.symbol,
                    decimals: entry.decimals,
                    position: 'left',
                });
                this.pickerValue = '';
            },
            removeCurrency: function (index) {
                this.settings.display_currencies.splice(index, 1);
            },
        },
        template: '\
<div>\
    <div style="margin-bottom:20px">\
        <el-select\
            v-model="pickerValue"\
            filterable\
            placeholder="Search and add a currency\u2026"\
            style="width:100%;max-width:420px"\
            @change="onPick"\
        >\
            <el-option\
                v-for="c in availableCurrencies"\
                :key="c.code"\
                :label="c.flag + \' \' + c.code + \' \\u2014 \' + c.name"\
                :value="c.code"\
            />\
        </el-select>\
    </div>\
    <el-table v-if="settings.display_currencies && settings.display_currencies.length" :data="settings.display_currencies" stripe size="small">\
        <el-table-column label="Currency" min-width="180">\
            <template v-slot="scope">\
                <span class="fchub-mc-flag">{{ (catalogueMap[scope.row.code] || {}).flag }}</span>\
                <strong>{{ scope.row.code }}</strong>\
                <span style="margin-left:4px;color:#909399">{{ scope.row.name }}</span>\
            </template>\
        </el-table-column>\
        <el-table-column label="Symbol" width="90" align="center">\
            <template v-slot="scope">\
                <span v-html="scope.row.symbol"></span>\
            </template>\
        </el-table-column>\
        <el-table-column prop="decimals" label="Decimals" width="90" align="center" />\
        <el-table-column label="Position" width="180">\
            <template v-slot="scope">\
                <el-select v-model="scope.row.position" size="small">\
                    <el-option label="Left ($100)" value="left" />\
                    <el-option label="Right (100$)" value="right" />\
                    <el-option label="Left space ($ 100)" value="left_space" />\
                    <el-option label="Right space (100 $)" value="right_space" />\
                </el-select>\
            </template>\
        </el-table-column>\
        <el-table-column label="" width="60" align="center">\
            <template v-slot="scope">\
                <el-button type="danger" size="small" text @click="removeCurrency(scope.$index)">&times;</el-button>\
            </template>\
        </el-table-column>\
    </el-table>\
    <div v-else style="padding:40px;text-align:center;color:#909399">\
        No display currencies added yet. Use the picker above to add currencies.\
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
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Rate Provider</span>\
            <div class="form-note">Service used to fetch exchange rates.</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-select v-model="settings.rate_provider">\
                <el-option label="ExchangeRate-API (free tier)" value="exchange_rate_api" />\
                <el-option label="Open Exchange Rates" value="open_exchange_rates" />\
                <el-option label="European Central Bank (free)" value="ecb" />\
                <el-option label="Manual rates" value="manual" />\
            </el-select>\
        </div>\
    </div>\
    <div v-if="settings.rate_provider !== \'ecb\' && settings.rate_provider !== \'manual\'">\
        <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
        <div class="fchub-mc-row">\
            <div class="setting-html-wrapper">\
                <span class="setting-label">API Key</span>\
            </div>\
            <div class="setting-fields-inner">\
                <el-input v-model="settings.rate_provider_api_key" type="password" show-password autocomplete="one-time-code" />\
            </div>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Refresh Interval (hours)</span>\
            <div class="form-note">How often to fetch new exchange rates.</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-input-number v-model="settings.rate_refresh_interval_hrs" :min="1" :max="168" />\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Stale Threshold (hours)</span>\
            <div class="form-note">Rates older than this will trigger an admin warning.</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-input-number v-model="settings.stale_threshold_hrs" :min="1" :max="720" />\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Stale Rate Fallback</span>\
            <div class="form-note">What to do when rates are outdated beyond the threshold.</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-radio-group v-model="settings.stale_fallback">\
                <el-radio label="Show base currency" value="base" />\
                <el-radio label="Use last known rate" value="last_known" />\
            </el-radio-group>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Rounding Mode</span>\
            <div class="form-note">How converted prices are rounded.</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-select v-model="settings.rounding_mode">\
                <el-option label="No rounding" value="none" />\
                <el-option label="Round half up (standard)" value="half_up" />\
                <el-option label="Round half down" value="half_down" />\
                <el-option label="Always round up" value="ceil" />\
                <el-option label="Always round down" value="floor" />\
            </el-select>\
        </div>\
    </div>\
    <div style="margin-top:24px">\
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
<div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Checkout Disclosure</span>\
            <div class="form-note">Show a notice at checkout that payment is processed in the base currency.</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-radio-group v-model="settings.checkout_disclosure_enabled">\
                <el-radio label="Enabled" value="yes" />\
                <el-radio label="Disabled" value="no" />\
            </el-radio-group>\
        </div>\
    </div>\
    <div v-if="settings.checkout_disclosure_enabled === \'yes\'">\
        <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
        <div class="fchub-mc-row">\
            <div class="setting-html-wrapper">\
                <span class="setting-label">Disclosure Text</span>\
                <div class="form-note">Supports placeholders: {base_currency}, {display_currency}, {rate}</div>\
            </div>\
            <div class="setting-fields-inner">\
                <el-input v-model="settings.checkout_disclosure_text" type="textarea" :rows="3" />\
            </div>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Rate Freshness Badge</span>\
            <div class="form-note">Display a badge indicating when rates were last updated.</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-radio-group v-model="settings.show_rate_freshness_badge">\
                <el-radio label="Enabled" value="yes" />\
                <el-radio label="Disabled" value="no" />\
            </el-radio-group>\
        </div>\
    </div>\
</div>',
    };

    var CrmSettings = {
        name: 'CrmSettings',
        props: { settings: { type: Object, required: true } },
        template: '\
<div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">FluentCRM Sync</span>\
            <div class="form-note">Tag contacts and update custom fields based on currency preference.</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-radio-group v-model="settings.fluentcrm_enabled">\
                <el-radio label="Enabled" value="yes" />\
                <el-radio label="Disabled" value="no" />\
            </el-radio-group>\
        </div>\
    </div>\
    <div v-if="settings.fluentcrm_enabled === \'yes\'">\
        <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
        <div class="fchub-mc-row">\
            <div class="setting-html-wrapper">\
                <span class="setting-label">Auto-create Tags</span>\
                <div class="form-note">Automatically create tags for each currency.</div>\
            </div>\
            <div class="setting-fields-inner">\
                <el-radio-group v-model="settings.fluentcrm_auto_create_tags">\
                    <el-radio label="Yes" value="yes" />\
                    <el-radio label="No" value="no" />\
                </el-radio-group>\
            </div>\
        </div>\
        <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
        <div class="fchub-mc-row">\
            <div class="setting-html-wrapper">\
                <span class="setting-label">Tag Prefix</span>\
                <div class="form-note">Tags created as {prefix}{CODE}, e.g. currency:EUR</div>\
            </div>\
            <div class="setting-fields-inner">\
                <el-input v-model="settings.fluentcrm_tag_prefix" placeholder="currency:" style="max-width:200px" autocomplete="one-time-code" />\
            </div>\
        </div>\
        <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
        <div class="fchub-mc-row">\
            <div class="setting-html-wrapper">\
                <span class="setting-label">Preferred Currency Field</span>\
            </div>\
            <div class="setting-fields-inner">\
                <el-input v-model="settings.fluentcrm_field_preferred" style="max-width:300px" autocomplete="one-time-code" />\
            </div>\
        </div>\
        <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
        <div class="fchub-mc-row">\
            <div class="setting-html-wrapper">\
                <span class="setting-label">Last Order Currency Field</span>\
            </div>\
            <div class="setting-fields-inner">\
                <el-input v-model="settings.fluentcrm_field_last_order" style="max-width:300px" autocomplete="one-time-code" />\
            </div>\
        </div>\
        <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
        <div class="fchub-mc-row">\
            <div class="setting-html-wrapper">\
                <span class="setting-label">Last Order FX Rate Field</span>\
            </div>\
            <div class="setting-fields-inner">\
                <el-input v-model="settings.fluentcrm_field_last_rate" style="max-width:300px" autocomplete="one-time-code" />\
            </div>\
        </div>\
    </div>\
    <div class="setting-html-wrapper" style="margin-top:8px"><hr class="settings-divider"></div>\
    <h3 style="font-size:14px;font-weight:600;margin:16px 0 12px">FluentCommunity</h3>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">FluentCommunity Sync</span>\
            <div class="form-note">Sync currency preference to FluentCommunity user meta.</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-radio-group v-model="settings.fluentcommunity_enabled">\
                <el-radio label="Enabled" value="yes" />\
                <el-radio label="Disabled" value="no" />\
            </el-radio-group>\
        </div>\
    </div>\
</div>',
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
                <div class="fchub-mc-diag-row"><span>FluentCart</span><el-tag :type="diagnostics.fluentcart_version && diagnostics.fluentcart_version !== \'not installed\' ? \'success\' : \'danger\'" size="small">{{ diagnostics.fluentcart_version && diagnostics.fluentcart_version !== \'not installed\' ? diagnostics.fluentcart_version : "Missing" }}</el-tag></div>\
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
                catalogue: catalogue,
                catalogueMap: catalogueMap,
            };
        },
        mounted: function () {
            this.loadSettings();
            this.loadRates();
            if (typeof this.changeTitle === 'function') {
                this.changeTitle('Multi-Currency');
            }
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
                        vm.settings = data.settings || data;
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
                        vm.settings = data.settings || data;
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
<div class="setting-wrap fchub-mc-page">\
    <div class="fct-setting-header">\
        <div class="fct-setting-header-content">\
            <h3 class="fct-setting-head-title">Multi-Currency</h3>\
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
            <el-tab-pane label="Currencies" name="currencies">\
                <div class="form-section"><div class="fct-card"><div class="fct-card-body" v-loading="loading">\
                    <currency-settings :settings="settings" />\
                </div></div></div>\
            </el-tab-pane>\
            <el-tab-pane label="Exchange Rates" name="rates">\
                <div class="form-section"><div class="fct-card"><div class="fct-card-body">\
                    <rate-settings :settings="settings" :rates="rates" :rates-loading="ratesLoading" @refresh-rates="refreshRates" />\
                </div></div></div>\
            </el-tab-pane>\
            <el-tab-pane label="Checkout" name="checkout">\
                <div class="form-section"><div class="fct-card"><div class="fct-card-body" v-loading="loading">\
                    <checkout-settings :settings="settings" />\
                </div></div></div>\
            </el-tab-pane>\
            <el-tab-pane label="CRM" name="crm">\
                <div class="form-section"><div class="fct-card"><div class="fct-card-body" v-loading="loading">\
                    <crm-settings :settings="settings" />\
                </div></div></div>\
            </el-tab-pane>\
            <el-tab-pane label="Diagnostics" name="diagnostics">\
                <diagnostics-view :diagnostics="diagnostics" :loading="diagLoading" />\
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
        'fchub_multi_currency',
        function (routes) {
            if (routes.settings && routes.settings.children) {
                routes.settings.children.push({
                    name: 'multi_currency',
                    path: 'multi-currency',
                    component: MultiCurrencyPage,
                    meta: {
                        active_menu: 'settings',
                        title: 'Multi-Currency',
                    },
                });
            }
            return routes;
        }
    );

    /* ------------------------------------------------------------------ */
    /*  Inject "Multi-Currency" into the settings sidebar (DOM)            */
    /*  Inserts a tab item before "Tax & Duties" so it groups logically   */
    /*  with other financial settings.                                     */
    /* ------------------------------------------------------------------ */

    var MC_HASH = '#/settings/multi-currency';
    var MC_ICON = '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="10" r="8.25" stroke="currentColor" stroke-width="1.5"/><path d="M2 10h16" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M10 2a12.24 12.24 0 0 1 3.2 8 12.24 12.24 0 0 1-3.2 8 12.24 12.24 0 0 1-3.2-8A12.24 12.24 0 0 1 10 2z" stroke="currentColor" stroke-width="1.5"/></svg>';
    var MC_CHEVRON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 14" fill="none"><path d="M1 13L6.29289 7.70711C6.62623 7.37377 6.79289 7.20711 6.79289 7C6.79289 6.79289 6.62623 6.62623 6.29289 6.29289L1 1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    function isMultiCurrencyRoute() {
        return window.location.hash.indexOf('/settings/multi-currency') !== -1;
    }

    function updateActiveState(navItem) {
        if (isMultiCurrencyRoute()) {
            navItem.classList.add('fct-settings-nav-item-active');
        } else {
            navItem.classList.remove('fct-settings-nav-item-active');
        }
    }

    function injectSettingsSidebarItem() {
        var navList = document.querySelector('.fct-settings-nav');
        if (!navList) return false;

        if (navList.querySelector('.fct-settings-nav-item-multi-currency')) return true;

        // Build: <li class="fct-settings-nav-item">
        var navItem = document.createElement('li');
        navItem.className = 'fct-settings-nav-item fct-settings-nav-item-multi-currency';
        if (isMultiCurrencyRoute()) {
            navItem.classList.add('fct-settings-nav-item-active');
        }

        // Build: <a class="fct-settings-nav-link" href="#/settings/multi-currency">
        var link = document.createElement('a');
        link.className = 'fct-settings-nav-link';
        link.href = MC_HASH;

        // Icon wrapper: <div class="icon">SVG</div>
        var iconDiv = document.createElement('div');
        iconDiv.className = 'icon';
        iconDiv.innerHTML = MC_ICON;

        // Label + chevron: <span class="fct-settings-nav-link-text">Multi-Currency <div class="icon fct-settings-nav-link-icon">chevron</div></span>
        var labelSpan = document.createElement('span');
        labelSpan.className = 'fct-settings-nav-link-text';
        labelSpan.textContent = 'Multi-Currency';

        var chevronDiv = document.createElement('div');
        chevronDiv.className = 'icon fct-settings-nav-link-icon';
        chevronDiv.innerHTML = MC_CHEVRON;
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

        // Active state tracking
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

    // Start injection when navigating to settings
    function onHashChange() {
        if (window.location.hash.indexOf('#/settings') === 0) {
            tryInjectSidebar();
        }
    }

    window.addEventListener('hashchange', onHashChange);

    if (window.location.hash.indexOf('#/settings') === 0) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () { tryInjectSidebar(); });
        } else {
            tryInjectSidebar();
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Minimal CSS                                                        */
    /* ------------------------------------------------------------------ */

    var style = document.createElement('style');
    style.textContent = [
        '.fchub-mc-page .form-section { padding: 0; }',
        '.fchub-mc-row { display: grid; gap: 0.5rem; grid-template-columns: 1fr; padding: 4px 0; }',
        '@media (min-width: 1024px) { .fchub-mc-row { grid-template-columns: repeat(3, minmax(0, 1fr)); } .fchub-mc-row .setting-fields-inner { grid-column: span 2 / span 2; } }',
        '.fchub-mc-page .cmd { display: inline-block; font-size: 11px; margin-right: 4px; padding: 1px 5px; border: 1px solid rgba(255,255,255,.3); border-radius: 3px; line-height: 1; }',
        '.fchub-mc-diag-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }',
        '.fchub-mc-diag-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid var(--el-border-color-lighter); font-size: 13px; }',
        '.fchub-mc-diag-row:last-child { border-bottom: none; }',
        '.fchub-mc-flag { font-size: 1.2em; margin-right: 6px; vertical-align: middle; }',
    ].join('\n');
    document.head.appendChild(style);
})();
