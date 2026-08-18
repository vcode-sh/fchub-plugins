/**
 * FCHub Multi-Currency — Exchange Rates tab.
 *
 * Where exchange rates come from and how fresh they are: provider choice,
 * refresh/staleness policy, rounding, manual-rate entry and the current
 * rates table. Rate actions are emitted to the page, which owns the API.
 */

(() => {
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
		template:
			'\
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
    <div v-if="settings.rate_provider !== \'manual\'">\
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
                <el-option label="Truncate (no rounding)" value="none" />\
                <el-option label="Round half up (standard)" value="half_up" />\
                <el-option label="Round half down" value="half_down" />\
                <el-option label="Always round up" value="ceil" />\
                <el-option label="Always round down" value="floor" />\
            </el-select>\
        </div>\
    </div>\
    <div v-if="settings.rate_provider === \'manual\'" style="margin-top:24px">\
        <h3 style="font-size:16px;font-weight:500;margin:0 0 12px">Manual Rates</h3>\
        <div v-if="savedRateProvider !== \'manual\'" class="form-note fchub-mc-form-note--warning">Save the provider change before entering manual rates.</div>\
        <div v-else-if="!quoteCurrencies.length" class="form-note">Add and save at least one display currency first.</div>\
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
            <h3 style="font-size:16px;font-weight:500;margin:0">Current Rates</h3>\
            <el-button v-if="settings.rate_provider === \'manual\'" size="small" type="primary" @click="$emit(\'save-manual-rates\')" :loading="manualRatesSaving" :disabled="savedRateProvider !== \'manual\' || !quoteCurrencies.length">Save Manual Rates</el-button>\
            <el-button v-else size="small" @click="$emit(\'refresh-rates\')" :loading="ratesLoading">Refresh Now</el-button>\
        </div>\
        <el-table v-if="rates.length" :data="rates" stripe size="small">\
            <el-table-column prop="base_currency" label="Base" width="80" />\
            <el-table-column prop="quote_currency" label="Quote" width="80" />\
            <el-table-column prop="rate" label="Rate" />\
            <el-table-column prop="provider" label="Provider" width="160" />\
            <el-table-column prop="fetched_at" label="Fetched" width="180" />\
            <el-table-column label="Status" width="80" align="center">\
                <template v-slot="scope">\
                    <span :class="[\'fchub-mc-pill\', scope.row.is_stale ? \'fchub-mc-pill--danger\' : \'fchub-mc-pill--success\']">{{ scope.row.is_stale ? "Stale" : "OK" }}</span>\
                </template>\
            </el-table-column>\
        </el-table>\
        <div v-else-if="!ratesLoading" style="padding:40px;text-align:center;color:#909399">\
            {{ settings.rate_provider === "manual" ? "No manual rates saved yet." : "No exchange rates yet. Add display currencies and trigger a refresh." }}\
        </div>\
    </div>\
</div>',
	};
})();
