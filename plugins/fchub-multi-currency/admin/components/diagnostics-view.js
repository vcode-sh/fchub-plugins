/**
 * FCHub Multi-Currency — Diagnostics tab.
 *
 * Whether the plugin is healthy right now: versions, dependencies, rate
 * freshness, feature flags and switcher event counts. Read-only; every row
 * states its value with a plugin-owned pill so nothing renders blank.
 */

(() => {
	window.FchubMcAdmin = window.FchubMcAdmin || {};
	window.FchubMcAdmin.components = window.FchubMcAdmin.components || {};
	const components = window.FchubMcAdmin.components;

	components.DiagnosticsView = {
		name: "DiagnosticsView",
		props: {
			diagnostics: { type: Object, default: () => ({}) },
			loading: { type: Boolean, default: false },
			crmFieldsCreating: { type: Boolean, default: false },
		},
		emits: ["create-crm-fields"],
		computed: {
			fluentcartDetected: function () {
				var version = this.diagnostics.fluentcart_version;
				return Boolean(version) && version !== "not installed";
			},
			// The quick action only makes sense while FluentCRM is active and at
			// least one of the three sync fields genuinely does not exist yet.
			hasMissingCrmFields: function () {
				if (!this.diagnostics.fluentcrm_active) return false;
				var status = this.diagnostics.fluentcrm_fields_status || {};
				return Object.values(status).some((exists) => exists === false);
			},
		},
		template:
			'\
<div v-loading="loading">\
    <div v-if="diagnostics.plugin_version" class="fchub-mc-diag-grid">\
        <div class="fct-card fct-card-border">\
            <div class="fct-card-body">\
                <h4 style="margin:0 0 12px;font-size:14px;font-weight:500">Plugin</h4>\
                <div class="fchub-mc-diag-row"><span>Version</span><span>{{ diagnostics.plugin_version }}</span></div>\
                <div class="fchub-mc-diag-row"><span>DB Version</span><span>{{ diagnostics.db_version }}</span></div>\
                <div class="fchub-mc-diag-row"><span>Base Currency</span><span>{{ diagnostics.base_currency }}</span></div>\
                <div class="fchub-mc-diag-row"><span>PHP</span><span>{{ diagnostics.php_version }}</span></div>\
                <div class="fchub-mc-diag-row"><span>bcmath</span><span :class="[\'fchub-mc-pill\', diagnostics.bcmath_available ? \'fchub-mc-pill--success\' : \'fchub-mc-pill--danger\']">{{ diagnostics.bcmath_available ? "Available" : "Missing" }}</span></div>\
            </div>\
        </div>\
        <div class="fct-card fct-card-border">\
            <div class="fct-card-body">\
                <h4 style="margin:0 0 12px;font-size:14px;font-weight:500">Dependencies</h4>\
                <div class="fchub-mc-diag-row"><span>FluentCart</span><span :class="[\'fchub-mc-pill\', fluentcartDetected ? \'fchub-mc-pill--success\' : \'fchub-mc-pill--danger\']">{{ fluentcartDetected ? diagnostics.fluentcart_version : "Missing" }}</span></div>\
                <div class="fchub-mc-diag-row"><span>FluentCRM</span><span :class="[\'fchub-mc-pill\', diagnostics.fluentcrm_active ? \'fchub-mc-pill--success\' : \'fchub-mc-pill--info\']">{{ diagnostics.fluentcrm_active ? "Active" : "Not found" }}</span></div>\
                <template v-if="diagnostics.fluentcrm_fields_status">\
                    <div style="margin-top:8px;font-size:12px;color:#909399;font-weight:500">FluentCRM Custom Fields</div>\
                    <div v-for="(exists, fieldKey) in diagnostics.fluentcrm_fields_status" :key="fieldKey" class="fchub-mc-diag-row">\
                        <span style="padding-left:8px">{{ fieldKey }}</span>\
                        <span v-if="exists === true" class="fchub-mc-pill fchub-mc-pill--success">Found</span>\
                        <span v-else-if="exists === false" class="fchub-mc-pill fchub-mc-pill--danger">Missing</span>\
                        <span v-else class="fchub-mc-pill fchub-mc-pill--info">Unknown</span>\
                    </div>\
                    <el-button v-if="hasMissingCrmFields" size="small" style="margin-top:10px" :loading="crmFieldsCreating" @click="$emit(\'create-crm-fields\')">\
                        Create missing fields\
                    </el-button>\
                </template>\
            </div>\
        </div>\
        <div class="fct-card fct-card-border">\
            <div class="fct-card-body">\
                <h4 style="margin:0 0 12px;font-size:14px;font-weight:500">Rates</h4>\
                <div class="fchub-mc-diag-row"><span>Total rates</span><span>{{ diagnostics.rate_count }}</span></div>\
                <div class="fchub-mc-diag-row"><span>Stale rates</span><span :class="[\'fchub-mc-pill\', diagnostics.stale_rates && diagnostics.stale_rates.length ? \'fchub-mc-pill--danger\' : \'fchub-mc-pill--success\']">{{ diagnostics.stale_rates ? diagnostics.stale_rates.length : 0 }}</span></div>\
            </div>\
        </div>\
        <div class="fct-card fct-card-border">\
            <div class="fct-card-body">\
                <h4 style="margin:0 0 12px;font-size:14px;font-weight:500">Feature Flags</h4>\
                <div v-for="(val, key) in diagnostics.feature_flags" :key="key" class="fchub-mc-diag-row">\
                    <span>{{ key }}</span>\
                    <span :class="[\'fchub-mc-pill\', val ? \'fchub-mc-pill--success\' : \'fchub-mc-pill--info\']">{{ val ? "On" : "Off" }}</span>\
                </div>\
            </div>\
        </div>\
        <div class="fct-card fct-card-border">\
            <div class="fct-card-body">\
                <h4 style="margin:0 0 12px;font-size:14px;font-weight:500">Switcher Events</h4>\
                <div v-for="(val, key) in diagnostics.event_counts" :key="key" class="fchub-mc-diag-row">\
                    <span>{{ key }}</span>\
                    <span>{{ val }}</span>\
                </div>\
                <div v-if="!diagnostics.event_counts || !Object.keys(diagnostics.event_counts).length" style="color:#909399;font-size:13px">\
                    No event data yet.\
                </div>\
            </div>\
        </div>\
        <div class="fct-card fct-card-border">\
            <div class="fct-card-body">\
                <h4 style="margin:0 0 12px;font-size:14px;font-weight:500">Top Switched Currencies</h4>\
                <div v-for="row in diagnostics.top_switched_currencies" :key="row.currency" class="fchub-mc-diag-row">\
                    <span>{{ row.currency }}</span>\
                    <span>{{ row.total }}</span>\
                </div>\
                <div v-if="!diagnostics.top_switched_currencies || !diagnostics.top_switched_currencies.length" style="color:#909399;font-size:13px">\
                    No switch data yet.\
                </div>\
            </div>\
        </div>\
    </div>\
    <div v-else-if="!loading" style="padding:40px;text-align:center;color:#909399">\
        No diagnostics data.\
    </div>\
</div>',
	};
})();
