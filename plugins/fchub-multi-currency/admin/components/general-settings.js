/**
 * FCHub Multi-Currency — General tab.
 *
 * How the store detects and remembers a visitor's currency: master switch,
 * default display currency, URL/cookie/account persistence, uninstall policy.
 * Attaches to window.FchubMcAdmin.components for the entry file to consume.
 */

(() => {
	window.FchubMcAdmin = window.FchubMcAdmin || {};
	window.FchubMcAdmin.components = window.FchubMcAdmin.components || {};
	const components = window.FchubMcAdmin.components;

	const catalogue = window.fchubMcAdmin?.currency_catalogue || [];

	// Storage keeps "" for "follow the base currency"; the select needs a
	// non-empty value because el-select renders an empty one as the placeholder.
	const FOLLOW_BASE = "__follow_base__";

	components.GeneralSettings = {
		name: "GeneralSettings",
		props: { settings: { type: Object, required: true } },
		data: () => ({ catalogue: catalogue }),
		computed: {
			// First entry is "follow the base currency" — the shipped default and
			// the right answer for almost every store, so it leads the list and
			// says so.
			defaultDisplayOptions: function () {
				var base = this.settings.base_currency;
				var display = this.settings.display_currencies || [];
				var codes = {};
				if (base) codes[base] = true;
				display.forEach((d) => {
					codes[d.code] = true;
				});

				var options = catalogue
					.filter((c) => codes[c.code])
					.map((c) => ({ code: c.code, label: `${c.flag} ${c.code} — ${c.name}` }));
				options.unshift({
					code: FOLLOW_BASE,
					label: `Same as base currency (${base || "store base"}) — recommended`,
				});
				return options;
			},
			defaultDisplayModel: {
				get: function () {
					return this.settings.default_display_currency || FOLLOW_BASE;
				},
				set: function (value) {
					this.settings.default_display_currency = value === FOLLOW_BASE ? "" : value;
				},
			},
		},
		template:
			'\
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
            <div class="form-note">Managed in FluentCart Store Setup. Checkout and payment gateways use this currency.</div>\
        </div>\
        <div class="setting-fields-inner">\
            <strong class="fchub-mc-value">{{ settings.base_currency }}</strong>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Default Display Currency</span>\
            <div class="form-note">Currency shown to visitors before any preference is detected.</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-select v-model="defaultDisplayModel" filterable placeholder="Select currency" style="max-width:320px">\
                <el-option v-for="c in defaultDisplayOptions" :key="c.code" :label="c.label" :value="c.code" />\
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
            <div class="form-note">Remember a guest currency in a cookie and matching browser-storage fallback. Disable this and logged-out visitors cannot keep a currency choice at all — the switch is reported as failed rather than silently ignored.</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-radio-group v-model="settings.cookie_enabled">\
                <el-radio label="Enabled" value="yes" />\
                <el-radio label="Disabled" value="no" />\
            </el-radio-group>\
            <div v-if="settings.cookie_enabled !== \'yes\'" class="form-note fchub-mc-form-note--warning">Currency switching is now a logged-in-only feature. Guests will be told their preference could not be saved.</div>\
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
            <span class="setting-label">Account Persistence</span>\
            <div class="form-note">Remember currency preference in the logged-in user account. Disable this if you want logged-in sessions to respect the default display currency on every fresh visit.</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-radio-group v-model="settings.account_persistence_enabled">\
                <el-radio label="Enabled" value="yes" />\
                <el-radio label="Disabled" value="no" />\
            </el-radio-group>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-mc-row fchub-mc-coming-soon-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Geolocation <span class="fchub-mc-badge-soon">Coming Soon</span></span>\
            <div class="form-note">Automatically detect visitor currency based on their country. Requires a geolocation provider.</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-radio-group v-model="settings.geo_enabled" disabled>\
                <el-radio label="Enabled" value="yes" />\
                <el-radio label="Disabled" value="no" />\
            </el-radio-group>\
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
})();
