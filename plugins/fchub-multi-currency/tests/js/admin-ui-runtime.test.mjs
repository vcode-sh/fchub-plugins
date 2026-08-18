import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { loadAdminRuntime, pageState } from "./admin-runtime-fixture.mjs";

// UTC "YYYY-MM-DD HH:MM:SS", the shape the rates REST payload uses.
function utcStamp(msAgo) {
	return new Date(Date.now() - msAgo).toISOString().slice(0, 19).replace("T", " ");
}

describe("tab strip owns its active state", () => {
	it("drives tabs from activeTab with a class-bound underline instead of el-tabs", () => {
		const { page } = loadAdminRuntime();

		assert.doesNotMatch(page.template, /<el-tabs/);
		assert.doesNotMatch(page.template, /<el-tab-pane/);
		assert.match(page.template, /v-for="tab in tabs"/);
		assert.match(page.template, /activeTab === tab\.name/);
		assert.match(page.template, /@click="activeTab = tab\.name"/);
		assert.match(page.template, /v-show="activeTab === 'general'"/);
		assert.match(page.template, /v-show="activeTab === 'diagnostics'"/);
	});

	it("keeps lazy-loading diagnostics on first activation only", () => {
		const { page } = loadAdminRuntime();
		let loads = 0;
		const state = {
			diagnostics: {},
			loadDiagnostics() {
				loads++;
			},
		};

		page.watch.activeTab.call(state, "diagnostics");
		assert.equal(loads, 1);

		state.diagnostics = { plugin_version: "1.0.0" };
		page.watch.activeTab.call(state, "diagnostics");
		assert.equal(loads, 1);
	});
});

describe("base currency row states its value", () => {
	it("renders the base code as a plugin-owned read-only value, not an el-tag", () => {
		const { page } = loadAdminRuntime();
		const template = page.components.GeneralSettings.template;

		assert.doesNotMatch(template, /<el-tag/);
		assert.match(template, /fchub-mc-value[^>]*>\{\{ settings\.base_currency \}\}/);
	});
});

describe("switcher preview reports real rate freshness", () => {
	it("derives the badge text from the newest fetched_at", () => {
		const { window } = loadAdminRuntime();
		const preview = window.FchubMcSwitcherPreview;
		const ctx = {
			rates: [
				{ base_currency: "PLN", quote_currency: "EUR", rate: "0.23500000", fetched_at: utcStamp(26 * 3600 * 1000) },
				{ base_currency: "PLN", quote_currency: "USD", rate: "0.25100000", fetched_at: utcStamp(2 * 3600 * 1000) },
			],
		};

		assert.equal(preview.computed.rateAgeText.call(ctx), "Rates updated 2 hours ago");
	});

	it("uses singular units and a one-minute floor", () => {
		const { window } = loadAdminRuntime();
		const preview = window.FchubMcSwitcherPreview;
		const at = (msAgo) => ({ rates: [{ fetched_at: utcStamp(msAgo) }] });

		assert.equal(preview.computed.rateAgeText.call(at(30 * 1000)), "Rates updated 1 minute ago");
		assert.equal(preview.computed.rateAgeText.call(at(3600 * 1000)), "Rates updated 1 hour ago");
		assert.equal(preview.computed.rateAgeText.call(at(3 * 86400 * 1000)), "Rates updated 3 days ago");
	});

	it("drops the badge line instead of faking it when no rates exist", () => {
		const { window } = loadAdminRuntime();
		const preview = window.FchubMcSwitcherPreview;

		assert.equal(preview.computed.rateAgeText.call({ rates: [] }), null);
		assert.equal(
			preview.computed.hasFooter.call({
				s: { show_rate_badge: "yes", show_rate_value: "no", show_context_note: "no" },
				rateAgeText: null,
				rateLine: null,
			}),
			false,
		);
		assert.doesNotMatch(preview.template, /Rates updated 2 hours ago/);
	});

	it("derives the rate line and checkout note from the rates payload", () => {
		const { window } = loadAdminRuntime();
		const preview = window.FchubMcSwitcherPreview;
		const ctx = {
			rates: [{ base_currency: "EUR", quote_currency: "USD", rate: "1.12000000", fetched_at: utcStamp(1000) }],
		};

		assert.equal(preview.computed.rateLine.call(ctx), "1 EUR = 1.12000000 USD");
		assert.match(preview.computed.contextNote.call(ctx), /EUR/);
		assert.doesNotMatch(preview.template, /1 PLN = 0\.2350 EUR/);
		assert.doesNotMatch(preview.template, /Checkout charged in PLN\./);
	});
});

describe("diagnostics rows state their values", () => {
	it("renders every status with plugin-owned pills instead of el-tag", () => {
		const { page } = loadAdminRuntime();
		const template = page.components.DiagnosticsView.template;

		assert.doesNotMatch(template, /<el-tag/);
		assert.match(template, /fchub-mc-pill/);
		assert.match(template, /bcmath_available \? i18n\.available : i18n\.missing/);
		assert.match(template, /diagnostics\.fluentcart_version/);
		assert.match(template, /stale_rates\.length : 0/);
		assert.match(template, /val \? i18n\.on : i18n\.off/);
	});

	it("keeps the rates table status out of el-tag too", () => {
		const { page } = loadAdminRuntime();

		assert.doesNotMatch(page.components.RateSettings.template, /<el-tag/);
	});
});

describe("freshness badge gates say who owns what", () => {
	it("checkout copy declares the global gate", () => {
		const { page } = loadAdminRuntime();

		assert.match(page.components.CheckoutSettings.template, /Global gate/);
	});

	it("switcher footer toggle references the global gate", () => {
		const { page } = loadAdminRuntime();

		assert.match(page.components.SwitcherSettings.template, /Checkout tab/);
	});
});

describe("diagnostics CRM quick action", () => {
	it("offers the create button only while fields are missing", () => {
		const { page } = loadAdminRuntime();
		const view = page.components.DiagnosticsView;

		assert.match(view.template, /create-crm-fields/);
		assert.equal(
			view.computed.hasMissingCrmFields.call({
				diagnostics: { fluentcrm_active: true, fluentcrm_fields_status: { a: true, b: false, c: null } },
			}),
			true,
		);
		assert.equal(
			view.computed.hasMissingCrmFields.call({
				diagnostics: { fluentcrm_active: true, fluentcrm_fields_status: { a: true, b: true } },
			}),
			false,
			"Nothing to create once every field exists.",
		);
		assert.equal(
			view.computed.hasMissingCrmFields.call({
				diagnostics: { fluentcrm_active: false, fluentcrm_fields_status: { a: false } },
			}),
			false,
			"No FluentCRM, no button.",
		);
	});

	it("posts the quick action and folds the fresh status into diagnostics", async () => {
		const created = { preferred_currency: true, last_order_display_currency: true, last_order_fx_rate: true };
		const { page, requests } = loadAdminRuntime({
			responses: [{ body: { data: { fluentcrm_fields_status: created } } }],
		});
		const state = pageState({
			diagnostics: { plugin_version: "1.4.7", fluentcrm_fields_status: { preferred_currency: false } },
		});

		await page.methods.createCrmFields.call(state);

		assert.equal(requests.length, 1);
		assert.match(requests[0].url, /admin\/diagnostics\/crm-fields$/);
		assert.equal(requests[0].options.method, "POST");
		assert.deepEqual(state.diagnostics.fluentcrm_fields_status, created);
		assert.equal(state.diagnostics.plugin_version, "1.4.7", "The rest of the diagnostics payload survives the merge.");
	});
});

describe("default display currency offers follow-the-base first", () => {
	it("prepends a recommended same-as-base option for the empty value", () => {
		const { page } = loadAdminRuntime({
			adminConfig: {
				currency_catalogue: [
					{ code: "EUR", name: "Euro", flag: "🇪🇺" },
					{ code: "PLN", name: "Polish Złoty", flag: "🇵🇱" },
				],
			},
		});
		const view = page.components.GeneralSettings;

		const options = view.computed.defaultDisplayOptions.call({
			settings: {
				base_currency: "EUR",
				display_currencies: [{ code: "PLN" }],
			},
		});

		assert.equal(options[0].code, "__follow_base__", "A sentinel, because el-select renders an empty value as the placeholder.");
		assert.match(options[0].label, /Same as base currency \(EUR\)/);
		assert.match(options[0].label, /recommended/i);
		assert.equal(options.some((o) => o.code === "PLN"), true, "Concrete currencies stay available.");
	});

	it("maps the sentinel to an empty stored value and back", () => {
		const { page } = loadAdminRuntime();
		const view = page.components.GeneralSettings;
		const state = { settings: { base_currency: "EUR", default_display_currency: "" } };

		assert.equal(
			view.computed.defaultDisplayModel.get.call(state),
			"__follow_base__",
			"An empty stored value selects the follow-the-base option.",
		);

		view.computed.defaultDisplayModel.set.call(state, "PLN");
		assert.equal(state.settings.default_display_currency, "PLN");

		view.computed.defaultDisplayModel.set.call(state, "__follow_base__");
		assert.equal(state.settings.default_display_currency, "", "The sentinel never reaches storage.");
	});
});

describe("every admin surface string flows through wp.i18n", () => {
	const mark = (text) => `«${text}»`;

	it("builds each component template through the injected translator", () => {
		const { window } = loadAdminRuntime({ translate: mark });
		const sentinels = {
			GeneralSettings: "Multi-Currency Enabled",
			CurrencySettings: "Search and add a currency…",
			SwitcherSettings: "Default Preset",
			RateSettings: "Rate Provider",
			CheckoutSettings: "Checkout Disclosure",
			CrmSettings: "FluentCRM Sync",
			DiagnosticsView: "Switcher Events",
		};

		for (const [component, sentinel] of Object.entries(sentinels)) {
			const template = window.FchubMcAdmin.components[component].template;
			assert.ok(
				template.includes(mark(sentinel)),
				`${component} must pass "${sentinel}" through wp.i18n.__`,
			);
		}

		assert.ok(
			window.FchubMcSwitcherPreview.template.includes(mark("Search currency")),
			"the preview placeholder must pass through wp.i18n.__",
		);
	});

	it("translates the page chrome: tabs, title, and the save action", () => {
		const { page } = loadAdminRuntime({ translate: mark });
		const data = page.data();

		for (const tab of data.tabs) {
			assert.match(tab.label, /^«.*»$/, `tab "${tab.name}" label must be translated`);
		}
		assert.equal(data.i18n.save, mark("Save"));
		assert.equal(data.i18n.saving, mark("Saving..."));
		assert.ok(page.template.includes(mark("Multi-Currency settings")), "the tablist aria-label must be translated");
		assert.doesNotMatch(page.template, /"Save"|"Saving\.\.\."/, "no raw save labels left in the template");
	});
});
