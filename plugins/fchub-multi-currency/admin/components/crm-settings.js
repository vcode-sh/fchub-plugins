/**
 * FCHub Multi-Currency — CRM tab.
 *
 * What syncs to FluentCRM and FluentCommunity: currency tags, custom field
 * mapping and community user-meta sync.
 */

(() => {
	const { __ } = window.wp.i18n;
	window.FchubMcAdmin = window.FchubMcAdmin || {};
	window.FchubMcAdmin.components = window.FchubMcAdmin.components || {};
	const components = window.FchubMcAdmin.components;

	components.CrmSettings = {
		name: "CrmSettings",
		props: { settings: { type: Object, required: true } },
		template:
			'\
<div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">' +
			__("FluentCRM Sync", "fchub-multi-currency") +
			'</span>\
            <div class="form-note">' +
			__(
				"Tag contacts and update custom fields based on currency preference.",
				"fchub-multi-currency",
			) +
			'</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-radio-group v-model="settings.fluentcrm_enabled">\
                <el-radio label="' +
			__("Enabled", "fchub-multi-currency") +
			'" value="yes" />\
                <el-radio label="' +
			__("Disabled", "fchub-multi-currency") +
			'" value="no" />\
            </el-radio-group>\
        </div>\
    </div>\
    <div v-if="settings.fluentcrm_enabled === \'yes\'">\
        <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
        <div class="fchub-mc-row">\
            <div class="setting-html-wrapper">\
                <span class="setting-label">' +
			__("Auto-create Tags", "fchub-multi-currency") +
			'</span>\
                <div class="form-note">' +
			__("Automatically create tags for each currency.", "fchub-multi-currency") +
			'</div>\
            </div>\
            <div class="setting-fields-inner">\
                <el-radio-group v-model="settings.fluentcrm_auto_create_tags">\
                    <el-radio label="' +
			__("Yes", "fchub-multi-currency") +
			'" value="yes" />\
                    <el-radio label="' +
			__("No", "fchub-multi-currency") +
			'" value="no" />\
                </el-radio-group>\
            </div>\
        </div>\
        <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
        <div class="fchub-mc-row">\
            <div class="setting-html-wrapper">\
                <span class="setting-label">' +
			__("Tag Prefix", "fchub-multi-currency") +
			'</span>\
                <div class="form-note">' +
			__("Tags created as {prefix}{CODE}, e.g. currency:EUR", "fchub-multi-currency") +
			'</div>\
            </div>\
            <div class="setting-fields-inner">\
                <el-input v-model="settings.fluentcrm_tag_prefix" placeholder="currency:" style="max-width:200px" autocomplete="one-time-code" />\
            </div>\
        </div>\
        <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
        <div class="fchub-mc-row">\
            <div class="setting-html-wrapper">\
                <span class="setting-label">' +
			__("Preferred Currency Field", "fchub-multi-currency") +
			'</span>\
            </div>\
            <div class="setting-fields-inner">\
                <el-input v-model="settings.fluentcrm_field_preferred" style="max-width:300px" autocomplete="one-time-code" />\
            </div>\
        </div>\
        <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
        <div class="fchub-mc-row">\
            <div class="setting-html-wrapper">\
                <span class="setting-label">' +
			__("Last Order Currency Field", "fchub-multi-currency") +
			'</span>\
            </div>\
            <div class="setting-fields-inner">\
                <el-input v-model="settings.fluentcrm_field_last_order" style="max-width:300px" autocomplete="one-time-code" />\
            </div>\
        </div>\
        <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
        <div class="fchub-mc-row">\
            <div class="setting-html-wrapper">\
                <span class="setting-label">' +
			__("Last Order FX Rate Field", "fchub-multi-currency") +
			'</span>\
            </div>\
            <div class="setting-fields-inner">\
                <el-input v-model="settings.fluentcrm_field_last_rate" style="max-width:300px" autocomplete="one-time-code" />\
            </div>\
        </div>\
    </div>\
    <div class="setting-html-wrapper" style="margin-top:8px"><hr class="settings-divider"></div>\
    <h3 style="font-size:14px;font-weight:600;margin:16px 0 12px">' +
			__("FluentCommunity", "fchub-multi-currency") +
			'</h3>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">' +
			__("FluentCommunity Sync", "fchub-multi-currency") +
			'</span>\
            <div class="form-note">' +
			__("Sync currency preference to FluentCommunity user meta.", "fchub-multi-currency") +
			'</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-radio-group v-model="settings.fluentcommunity_enabled">\
                <el-radio label="' +
			__("Enabled", "fchub-multi-currency") +
			'" value="yes" />\
                <el-radio label="' +
			__("Disabled", "fchub-multi-currency") +
			'" value="no" />\
            </el-radio-group>\
        </div>\
    </div>\
</div>',
	};
})();
