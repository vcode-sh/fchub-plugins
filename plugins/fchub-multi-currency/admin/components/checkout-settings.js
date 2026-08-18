/**
 * FCHub Multi-Currency — Checkout tab.
 *
 * What buyers see about currency at checkout: the base-currency disclosure
 * notice and the global gate for the rates-updated freshness badge.
 */

(() => {
	const { __ } = window.wp.i18n;
	window.FchubMcAdmin = window.FchubMcAdmin || {};
	window.FchubMcAdmin.components = window.FchubMcAdmin.components || {};
	const components = window.FchubMcAdmin.components;

	components.CheckoutSettings = {
		name: "CheckoutSettings",
		props: { settings: { type: Object, required: true } },
		template:
			'\
<div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">' +
			__("Checkout Disclosure", "fchub-multi-currency") +
			'</span>\
            <div class="form-note">' +
			__(
				"Show a notice at checkout that payment is processed in the base currency.",
				"fchub-multi-currency",
			) +
			'</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-radio-group v-model="settings.checkout_disclosure_enabled">\
                <el-radio label="' +
			__("Enabled", "fchub-multi-currency") +
			'" value="yes" />\
                <el-radio label="' +
			__("Disabled", "fchub-multi-currency") +
			'" value="no" />\
            </el-radio-group>\
        </div>\
    </div>\
    <div v-if="settings.checkout_disclosure_enabled === \'yes\'">\
        <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
        <div class="fchub-mc-row">\
            <div class="setting-html-wrapper">\
                <span class="setting-label">' +
			__("Disclosure Text", "fchub-multi-currency") +
			'</span>\
                <div class="form-note">' +
			__(
				"Supports placeholders: {base_currency}, {display_currency}, {rate}",
				"fchub-multi-currency",
			) +
			'</div>\
            </div>\
            <div class="setting-fields-inner">\
                <el-input v-model="settings.checkout_disclosure_text" type="textarea" :rows="3" />\
            </div>\
        </div>\
    </div>\
    <div class="setting-html-wrapper"><hr class="settings-divider"></div>\
    <div class="fchub-mc-row">\
        <div class="setting-html-wrapper">\
            <span class="setting-label">' +
			__("Rate Freshness Badge", "fchub-multi-currency") +
			'</span>\
            <div class="form-note">' +
			__(
				"Global gate for the rates-updated badge. When disabled, no surface shows it — including the switcher dropdown footer.",
				"fchub-multi-currency",
			) +
			'</div>\
        </div>\
        <div class="setting-fields-inner">\
            <el-radio-group v-model="settings.show_rate_freshness_badge">\
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
