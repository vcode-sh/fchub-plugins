/**
 * FCHub Multi-Currency — CRM tab.
 *
 * What syncs to FluentCRM and FluentCommunity: currency tags, custom field
 * mapping and community user-meta sync.
 */

(() => {
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
})();
