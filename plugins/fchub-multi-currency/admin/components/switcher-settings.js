/**
 * FCHub Multi-Currency — Switcher tab.
 *
 * What the switcher widget looks like and contains by default: preset,
 * trigger/dropdown elements, search, favorites, layout geometry and the
 * dropdown footer context. Renders a live preview fed with real rates.
 */

(() => {
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
            <span class="setting-label">Default Preset</span>\
            <div class="form-note">Visual style used by switcher blocks that inherit global defaults.</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-select v-model="switcherDefaults.preset" style="max-width:260px">\
                <el-option label="Default" value="default" />\
                <el-option label="Pill" value="pill" />\
                <el-option label="Minimal" value="minimal" />\
                <el-option label="Subtle" value="subtle" />\
                <el-option label="Glass" value="glass" />\
                <el-option label="Contrast" value="contrast" />\
            </el-select>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Label Position</span>\
            <div class="form-note">Where the optional label should sit relative to the switcher.</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-select v-model="switcherDefaults.label_position" style="max-width:260px">\
                <el-option label="Before" value="before" />\
                <el-option label="After" value="after" />\
                <el-option label="Above" value="above" />\
                <el-option label="Below" value="below" />\
            </el-select>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <h3 style="font-size:14px;font-weight:600;margin:16px 0 12px">Trigger Content</h3>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Trigger Elements</span>\
            <div class="form-note">Choose what appears in the closed switcher button.</div>\
        </div>\
        <div class="setting-fields-inner fchub-mc-switcher-toggle-grid">\
            <el-switch v-model="switcherDefaults.show_flag" active-value="yes" inactive-value="no" active-text="Flag" />\
            <el-switch v-model="switcherDefaults.show_code" active-value="yes" inactive-value="no" active-text="Code" />\
            <el-switch v-model="switcherDefaults.show_symbol" active-value="yes" inactive-value="no" active-text="Symbol" />\
            <el-switch v-model="switcherDefaults.show_name" active-value="yes" inactive-value="no" active-text="Name" />\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <h3 style="font-size:14px;font-weight:600;margin:16px 0 12px">Dropdown Content</h3>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Option Elements</span>\
            <div class="form-note">Choose what each currency row displays inside the dropdown.</div>\
        </div>\
        <div class="setting-fields-inner fchub-mc-switcher-toggle-grid">\
            <el-switch v-model="switcherDefaults.show_option_flags" active-value="yes" inactive-value="no" active-text="Flags" />\
            <el-switch v-model="switcherDefaults.show_option_codes" active-value="yes" inactive-value="no" active-text="Codes" />\
            <el-switch v-model="switcherDefaults.show_option_symbols" active-value="yes" inactive-value="no" active-text="Symbols" />\
            <el-switch v-model="switcherDefaults.show_option_names" active-value="yes" inactive-value="no" active-text="Names" />\
            <el-switch v-model="switcherDefaults.show_active_indicator" active-value="yes" inactive-value="no" active-text="Active check" />\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Search Mode</span>\
            <div class="form-note">Useful when you expose more than a handful of currencies.</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-radio-group v-model="switcherDefaults.search_mode">\
                <el-radio label="Off" value="off" />\
                <el-radio label="Inline search" value="inline" />\
            </el-radio-group>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Favorite Currencies</span>\
            <div class="form-note">Comma-separated ISO codes pinned above the rest, for example: EUR, USD, GBP</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-input v-model="favoriteCurrenciesCsv" placeholder="EUR, USD, GBP" style="max-width:320px" autocomplete="one-time-code" />\
            <div style="margin-top:10px">\
                <el-switch v-model="switcherDefaults.show_favorites_first" active-value="yes" inactive-value="no" active-text="Show favorites first" />\
            </div>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Layout Defaults</span>\
            <div class="form-note">Baseline geometry for inheriting switcher blocks.</div>\
        </div>\
        <div class="setting-fields-inner fchub-mc-switcher-select-grid">\
            <div class="fchub-mc-field">\
                <span class="fchub-mc-field__label">Size</span>\
                <el-select v-model="switcherDefaults.size">\
                    <el-option label="Small" value="sm" />\
                    <el-option label="Medium" value="md" />\
                    <el-option label="Large" value="lg" />\
                </el-select>\
            </div>\
            <div class="fchub-mc-field">\
                <span class="fchub-mc-field__label">Width</span>\
                <el-select v-model="switcherDefaults.width_mode">\
                    <el-option label="Auto width" value="auto" />\
                    <el-option label="Full width" value="full" />\
                </el-select>\
            </div>\
            <div class="fchub-mc-field">\
                <span class="fchub-mc-field__label">Dropdown position</span>\
                <el-select v-model="switcherDefaults.dropdown_position">\
                    <el-option label="Auto position" value="auto" />\
                    <el-option label="Start" value="start" />\
                    <el-option label="End" value="end" />\
                </el-select>\
            </div>\
            <div class="fchub-mc-field">\
                <span class="fchub-mc-field__label">Dropdown direction</span>\
                <el-select v-model="switcherDefaults.dropdown_direction">\
                    <el-option label="Auto direction" value="auto" />\
                    <el-option label="Down" value="down" />\
                    <el-option label="Up" value="up" />\
                </el-select>\
            </div>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">Footer Context</span>\
            <div class="form-note">Optional trust-building context shown at the bottom of the dropdown. The freshness badge also requires the global Rate Freshness Badge gate on the Checkout tab.</div>\
        </div>\
        <div class="setting-fields-inner fchub-mc-switcher-toggle-grid">\
            <el-switch v-model="switcherDefaults.show_rate_badge" active-value="yes" inactive-value="no" active-text="Freshness badge" />\
            <el-switch v-model="switcherDefaults.show_rate_value" active-value="yes" inactive-value="no" active-text="Rate value" />\
            <el-switch v-model="switcherDefaults.show_context_note" active-value="yes" inactive-value="no" active-text="Checkout context" />\
        </div>\
    </div>\
</div>',
	};
})();
