/**
 * FCHub Multi-Currency — Exchange Rates tab.
 *
 * Where exchange rates come from and how fresh they are: provider choice,
 * refresh/staleness policy, rounding, manual-rate entry and the current
 * rates table. Rate actions are emitted to the page, which owns the API.
 */

(() => {
	const { __ } = window.wp.i18n;
	window.FchubMcAdmin = window.FchubMcAdmin || {};
	window.FchubMcAdmin.components = window.FchubMcAdmin.components || {};
	const components = window.FchubMcAdmin.components;

	components.RateSettings = {
		name: "RateSettings",
		props: {
			settings: { type: Object, required: true },
			rates: { type: Array, default: () => [] },
			ratesLoading: { type: Boolean, default: false },
			manualRates: { type: Object, required: true },
			manualRatesSaving: { type: Boolean, default: false },
			quoteCurrencies: { type: Array, default: () => [] },
			savedRateProvider: { type: String, default: "" },
		},
		data: () => ({
			i18n: {
				stale: __("Stale", "fchub-multi-currency"),
				ok: __("OK", "fchub-multi-currency"),
				noManualRates: __("No manual rates saved yet.", "fchub-multi-currency"),
				noRates: __(
					"No exchange rates yet. Add display currencies and trigger a refresh.",
					"fchub-multi-currency",
				),
			},
		}),
		template:
			'\
<div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">' +
			__("Rate Provider", "fchub-multi-currency") +
			'</span>\
            <div class="form-note">' +
			__("Service used to fetch exchange rates.", "fchub-multi-currency") +
			'</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-select v-model="settings.rate_provider">\
                <el-option label="' +
			__("ExchangeRate-API (free tier)", "fchub-multi-currency") +
			'" value="exchange_rate_api" />\
                <el-option label="' +
			__("Open Exchange Rates", "fchub-multi-currency") +
			'" value="open_exchange_rates" />\
                <el-option label="' +
			__("European Central Bank (free)", "fchub-multi-currency") +
			'" value="ecb" />\
                <el-option label="' +
			__("Manual rates", "fchub-multi-currency") +
			'" value="manual" />\
            </el-select>\
        </div>\
    </div>\
    <div v-if="settings.rate_provider !== \'ecb\' && settings.rate_provider !== \'manual\'">\
        <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
        <div class="fchub-mc-row">\
            <div class="setting-html-wrapper">\
                <span class="setting-label">' +
			__("API Key", "fchub-multi-currency") +
			'</span>\
            </div>\
            <div class="setting-fields-inner">\
                <el-input v-model="settings.rate_provider_api_key" type="password" show-password autocomplete="one-time-code" />\
            </div>\
        </div>\
    </div>\
    <div v-if="settings.rate_provider !== \'manual\'">\
        <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
        <div class="fchub-mc-row">\
            <div class="setting-html-wrapper">\
                <span class="setting-label">' +
			__("Refresh Interval (hours)", "fchub-multi-currency") +
			'</span>\
                <div class="form-note">' +
			__("How often to fetch new exchange rates.", "fchub-multi-currency") +
			'</div>\
            </div>\
            <div class="setting-fields-inner">\
                <el-input-number v-model="settings.rate_refresh_interval_hrs" :min="1" :max="168" />\
            </div>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">' +
			__("Stale Threshold (hours)", "fchub-multi-currency") +
			'</span>\
            <div class="form-note">' +
			__("Rates older than this will trigger an admin warning.", "fchub-multi-currency") +
			'</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-input-number v-model="settings.stale_threshold_hrs" :min="1" :max="720" />\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">' +
			__("Stale Rate Fallback", "fchub-multi-currency") +
			'</span>\
            <div class="form-note">' +
			__("What to do when rates are outdated beyond the threshold.", "fchub-multi-currency") +
			'</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-radio-group v-model="settings.stale_fallback">\
                <el-radio label="' +
			__("Show base currency", "fchub-multi-currency") +
			'" value="base" />\
                <el-radio label="' +
			__("Use last known rate", "fchub-multi-currency") +
			'" value="last_known" />\
            </el-radio-group>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">' +
			__("Rounding Mode", "fchub-multi-currency") +
			'</span>\
            <div class="form-note">' +
			__("How converted prices are rounded.", "fchub-multi-currency") +
			'</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-select v-model="settings.rounding_mode">\
                <el-option label="' +
			__("Truncate (no rounding)", "fchub-multi-currency") +
			'" value="none" />\
                <el-option label="' +
			__("Round half up (standard)", "fchub-multi-currency") +
			'" value="half_up" />\
                <el-option label="' +
			__("Round half down", "fchub-multi-currency") +
			'" value="half_down" />\
                <el-option label="' +
			__("Always round up", "fchub-multi-currency") +
			'" value="ceil" />\
                <el-option label="' +
			__("Always round down", "fchub-multi-currency") +
			'" value="floor" />\
            </el-select>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">' +
			__("Charm Rounding", "fchub-multi-currency") +
			'</span>\
            <div class="form-note">' +
			__(
				"Round converted display prices to selling endings. Display-only, like every converted price; checkout still charges the base currency exactly.",
				"fchub-multi-currency",
			) +
			'</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-select v-model="settings.charm_rounding" style="max-width:320px">\
                <el-option label="' +
			__("No charm rounding", "fchub-multi-currency") +
			'" value="none" />\
                <el-option label="' +
			__("Whole amounts (35)", "fchub-multi-currency") +
			'" value="whole" />\
                <el-option label="' +
			__("…99 endings (34.99)", "fchub-multi-currency") +
			'" value="ending_99" />\
                <el-option label="' +
			__("…95 endings (34.95)", "fchub-multi-currency") +
			'" value="ending_95" />\
                <el-option label="' +
			__("Nearest 5 (35)", "fchub-multi-currency") +
			'" value="nearest_5" />\
                <el-option label="' +
			__("Nearest 10 (30)", "fchub-multi-currency") +
			'" value="nearest_10" />\
            </el-select>\
        </div>\
    </div>\
    <div v-if="settings.rate_provider === \'manual\'" style="margin-top:24px">\
        <h3 style="font-size:16px;font-weight:500;margin:0 0 12px">' +
			__("Manual Rates", "fchub-multi-currency") +
			'</h3>\
        <div v-if="savedRateProvider !== \'manual\'" class="form-note fchub-mc-form-note--warning">' +
			__("Save the provider change before entering manual rates.", "fchub-multi-currency") +
			'</div>\
        <div v-else-if="!quoteCurrencies.length" class="form-note">' +
			__("Add and save at least one display currency first.", "fchub-multi-currency") +
			'</div>\
        <div v-else>\
            <div v-for="code in quoteCurrencies" :key="code" class="fchub-mc-manual-rate-row">\
                <span>1 {{ settings.base_currency }} =</span>\
                <el-input v-model="manualRates[code]" inputmode="decimal" autocomplete="off" placeholder="0.00000000" />\
                <span>{{ code }}</span>\
            </div>\
        </div>\
    </div>\
    <div style="margin-top:24px">\
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">\
            <h3 style="font-size:16px;font-weight:500;margin:0">' +
			__("Current Rates", "fchub-multi-currency") +
			'</h3>\
            <el-button v-if="settings.rate_provider === \'manual\'" size="small" type="primary" @click="$emit(\'save-manual-rates\')" :loading="manualRatesSaving" :disabled="savedRateProvider !== \'manual\' || !quoteCurrencies.length">' +
			__("Save Manual Rates", "fchub-multi-currency") +
			'</el-button>\
            <el-button v-else size="small" @click="$emit(\'refresh-rates\')" :loading="ratesLoading">' +
			__("Refresh Now", "fchub-multi-currency") +
			'</el-button>\
        </div>\
        <el-table v-if="rates.length" :data="rates" stripe size="small">\
            <el-table-column prop="base_currency" label="' +
			__("Base", "fchub-multi-currency") +
			'" width="80" />\
            <el-table-column prop="quote_currency" label="' +
			__("Quote", "fchub-multi-currency") +
			'" width="80" />\
            <el-table-column prop="rate" label="' +
			__("Rate", "fchub-multi-currency") +
			'" />\
            <el-table-column prop="provider" label="' +
			__("Provider", "fchub-multi-currency") +
			'" width="160" />\
            <el-table-column prop="fetched_at" label="' +
			__("Fetched", "fchub-multi-currency") +
			'" width="180" />\
            <el-table-column label="' +
			__("Status", "fchub-multi-currency") +
			'" width="80" align="center">\
                <template v-slot="scope">\
                    <span :class="[\'fchub-mc-pill\', scope.row.is_stale ? \'fchub-mc-pill--danger\' : \'fchub-mc-pill--success\']">{{ scope.row.is_stale ? i18n.stale : i18n.ok }}</span>\
                </template>\
            </el-table-column>\
        </el-table>\
        <div v-else-if="!ratesLoading" style="padding:40px;text-align:center;color:#909399">\
            {{ settings.rate_provider === "manual" ? i18n.noManualRates : i18n.noRates }}\
        </div>\
    </div>\
</div>',
	};
})();
