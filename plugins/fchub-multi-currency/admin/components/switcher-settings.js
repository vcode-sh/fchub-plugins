/**
 * FCHub Multi-Currency — Switcher tab.
 *
 * What the switcher widget looks like and contains by default: preset,
 * trigger/dropdown elements, search, favorites, layout geometry and the
 * dropdown footer context. Renders a live preview fed with real rates.
 */

(() => {
	const { __ } = window.wp.i18n;
	window.FchubMcAdmin = window.FchubMcAdmin || {};
	window.FchubMcAdmin.components = window.FchubMcAdmin.components || {};
	const components = window.FchubMcAdmin.components;

	components.SwitcherSettings = {
		name: "SwitcherSettings",
		components: { SwitcherPreview: window.FchubMcSwitcherPreview },
		props: {
			settings: { type: Object, required: true },
			rates: { type: Array, default: () => [] },
		},
		computed: {
			switcherDefaults: function () {
				if (!this.settings.switcher_defaults) {
					this.settings.switcher_defaults = {};
				}
				return this.settings.switcher_defaults;
			},
			favoriteCurrenciesCsv: {
				get: function () {
					return (this.switcherDefaults.favorite_currencies || []).join(", ");
				},
				set: function (value) {
					this.switcherDefaults.favorite_currencies = value
						.split(",")
						.map((item) => item.trim().toUpperCase())
						.filter((item) => /^[A-Z]{3}$/.test(item));
				},
			},
		},
		template:
			'\
<div>\
    <switcher-preview :settings="switcherDefaults" :currencies="settings.display_currencies" :rates="rates" />\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">' +
			__("Default Preset", "fchub-multi-currency") +
			'</span>\
            <div class="form-note">' +
			__(
				"Visual style used by switcher blocks that inherit global defaults.",
				"fchub-multi-currency",
			) +
			'</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-select v-model="switcherDefaults.preset" style="max-width:260px">\
                <el-option label="' +
			__("Default", "fchub-multi-currency") +
			'" value="default" />\
                <el-option label="' +
			__("Pill", "fchub-multi-currency") +
			'" value="pill" />\
                <el-option label="' +
			__("Minimal", "fchub-multi-currency") +
			'" value="minimal" />\
                <el-option label="' +
			__("Subtle", "fchub-multi-currency") +
			'" value="subtle" />\
                <el-option label="' +
			__("Glass", "fchub-multi-currency") +
			'" value="glass" />\
                <el-option label="' +
			__("Contrast", "fchub-multi-currency") +
			'" value="contrast" />\
            </el-select>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">' +
			__("Label Position", "fchub-multi-currency") +
			'</span>\
            <div class="form-note">' +
			__("Where the optional label should sit relative to the switcher.", "fchub-multi-currency") +
			'</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-select v-model="switcherDefaults.label_position" style="max-width:260px">\
                <el-option label="' +
			__("Before", "fchub-multi-currency") +
			'" value="before" />\
                <el-option label="' +
			__("After", "fchub-multi-currency") +
			'" value="after" />\
                <el-option label="' +
			__("Above", "fchub-multi-currency") +
			'" value="above" />\
                <el-option label="' +
			__("Below", "fchub-multi-currency") +
			'" value="below" />\
            </el-select>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <h3 style="font-size:14px;font-weight:600;margin:16px 0 12px">' +
			__("Trigger Content", "fchub-multi-currency") +
			'</h3>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">' +
			__("Trigger Elements", "fchub-multi-currency") +
			'</span>\
            <div class="form-note">' +
			__("Choose what appears in the closed switcher button.", "fchub-multi-currency") +
			'</div>\
        </div>\
        <div class="setting-fields-inner fchub-mc-switcher-toggle-grid">\
            <el-switch v-model="switcherDefaults.show_flag" active-value="yes" inactive-value="no" active-text="' +
			__("Flag", "fchub-multi-currency") +
			'" />\
            <el-switch v-model="switcherDefaults.show_code" active-value="yes" inactive-value="no" active-text="' +
			__("Code", "fchub-multi-currency") +
			'" />\
            <el-switch v-model="switcherDefaults.show_symbol" active-value="yes" inactive-value="no" active-text="' +
			__("Symbol", "fchub-multi-currency") +
			'" />\
            <el-switch v-model="switcherDefaults.show_name" active-value="yes" inactive-value="no" active-text="' +
			__("Name", "fchub-multi-currency") +
			'" />\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <h3 style="font-size:14px;font-weight:600;margin:16px 0 12px">' +
			__("Dropdown Content", "fchub-multi-currency") +
			'</h3>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">' +
			__("Option Elements", "fchub-multi-currency") +
			'</span>\
            <div class="form-note">' +
			__("Choose what each currency row displays inside the dropdown.", "fchub-multi-currency") +
			'</div>\
        </div>\
        <div class="setting-fields-inner fchub-mc-switcher-toggle-grid">\
            <el-switch v-model="switcherDefaults.show_option_flags" active-value="yes" inactive-value="no" active-text="' +
			__("Flags", "fchub-multi-currency") +
			'" />\
            <el-switch v-model="switcherDefaults.show_option_codes" active-value="yes" inactive-value="no" active-text="' +
			__("Codes", "fchub-multi-currency") +
			'" />\
            <el-switch v-model="switcherDefaults.show_option_symbols" active-value="yes" inactive-value="no" active-text="' +
			__("Symbols", "fchub-multi-currency") +
			'" />\
            <el-switch v-model="switcherDefaults.show_option_names" active-value="yes" inactive-value="no" active-text="' +
			__("Names", "fchub-multi-currency") +
			'" />\
            <el-switch v-model="switcherDefaults.show_active_indicator" active-value="yes" inactive-value="no" active-text="' +
			__("Active check", "fchub-multi-currency") +
			'" />\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">' +
			__("Search Mode", "fchub-multi-currency") +
			'</span>\
            <div class="form-note">' +
			__("Useful when you expose more than a handful of currencies.", "fchub-multi-currency") +
			'</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-radio-group v-model="switcherDefaults.search_mode">\
                <el-radio label="' +
			__("Off", "fchub-multi-currency") +
			'" value="off" />\
                <el-radio label="' +
			__("Inline search", "fchub-multi-currency") +
			'" value="inline" />\
            </el-radio-group>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">' +
			__("Favorite Currencies", "fchub-multi-currency") +
			'</span>\
            <div class="form-note">' +
			__(
				"Comma-separated ISO codes pinned above the rest, for example: EUR, USD, GBP",
				"fchub-multi-currency",
			) +
			'</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-input v-model="favoriteCurrenciesCsv" placeholder="EUR, USD, GBP" style="max-width:320px" autocomplete="one-time-code" />\
            <div style="margin-top:10px">\
                <el-switch v-model="switcherDefaults.show_favorites_first" active-value="yes" inactive-value="no" active-text="' +
			__("Show favorites first", "fchub-multi-currency") +
			'" />\
            </div>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">' +
			__("Layout Defaults", "fchub-multi-currency") +
			'</span>\
            <div class="form-note">' +
			__("Baseline geometry for inheriting switcher blocks.", "fchub-multi-currency") +
			'</div>\
        </div>\
        <div class="setting-fields-inner fchub-mc-switcher-select-grid">\
            <div class="fchub-mc-field">\
                <span class="fchub-mc-field__label">' +
			__("Size", "fchub-multi-currency") +
			'</span>\
                <el-select v-model="switcherDefaults.size">\
                    <el-option label="' +
			__("Small", "fchub-multi-currency") +
			'" value="sm" />\
                    <el-option label="' +
			__("Medium", "fchub-multi-currency") +
			'" value="md" />\
                    <el-option label="' +
			__("Large", "fchub-multi-currency") +
			'" value="lg" />\
                </el-select>\
            </div>\
            <div class="fchub-mc-field">\
                <span class="fchub-mc-field__label">' +
			__("Width", "fchub-multi-currency") +
			'</span>\
                <el-select v-model="switcherDefaults.width_mode">\
                    <el-option label="' +
			__("Auto width", "fchub-multi-currency") +
			'" value="auto" />\
                    <el-option label="' +
			__("Full width", "fchub-multi-currency") +
			'" value="full" />\
                </el-select>\
            </div>\
            <div class="fchub-mc-field">\
                <span class="fchub-mc-field__label">' +
			__("Dropdown position", "fchub-multi-currency") +
			'</span>\
                <el-select v-model="switcherDefaults.dropdown_position">\
                    <el-option label="' +
			__("Auto position", "fchub-multi-currency") +
			'" value="auto" />\
                    <el-option label="' +
			__("Start", "fchub-multi-currency") +
			'" value="start" />\
                    <el-option label="' +
			__("End", "fchub-multi-currency") +
			'" value="end" />\
                </el-select>\
            </div>\
            <div class="fchub-mc-field">\
                <span class="fchub-mc-field__label">' +
			__("Dropdown direction", "fchub-multi-currency") +
			'</span>\
                <el-select v-model="switcherDefaults.dropdown_direction">\
                    <el-option label="' +
			__("Auto direction", "fchub-multi-currency") +
			'" value="auto" />\
                    <el-option label="' +
			__("Down", "fchub-multi-currency") +
			'" value="down" />\
                    <el-option label="' +
			__("Up", "fchub-multi-currency") +
			'" value="up" />\
                </el-select>\
            </div>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">' +
			__("Footer Context", "fchub-multi-currency") +
			'</span>\
            <div class="form-note">' +
			__(
				"Optional trust-building context shown at the bottom of the dropdown. The freshness badge also requires the global Rate Freshness Badge gate on the Checkout tab.",
				"fchub-multi-currency",
			) +
			'</div>\
        </div>\
        <div class="setting-fields-inner fchub-mc-switcher-toggle-grid">\
            <el-switch v-model="switcherDefaults.show_rate_badge" active-value="yes" inactive-value="no" active-text="' +
			__("Freshness badge", "fchub-multi-currency") +
			'" />\
            <el-switch v-model="switcherDefaults.show_rate_value" active-value="yes" inactive-value="no" active-text="' +
			__("Rate value", "fchub-multi-currency") +
			'" />\
            <el-switch v-model="switcherDefaults.show_context_note" active-value="yes" inactive-value="no" active-text="' +
			__("Checkout context", "fchub-multi-currency") +
			'" />\
        </div>\
    </div>\
</div>',
	};
})();
