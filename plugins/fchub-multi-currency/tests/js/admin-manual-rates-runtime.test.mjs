import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { loadAdminRuntime, pageState } from "./admin-runtime-fixture.mjs";

describe("FluentCart admin currency ownership", () => {
	it("shows the FluentCart base currency without a second editable selector", () => {
		const { page } = loadAdminRuntime();
		const template = page.components.GeneralSettings.template;

		assert.doesNotMatch(template, /v-model="settings\.base_currency"/);
		assert.match(template, /Managed in FluentCart/);
	});
});

describe("manual rate workflow", () => {
	it("loads the exact configured quote set and current decimal strings", async () => {
		const { page } = loadAdminRuntime({
			responses: [{
				data: {
					base_currency: "EUR",
					provider: "manual",
					quote_currencies: ["USD", "GBP"],
					rates: [
						{ quote_currency: "USD", rate: "1.12000000" },
					],
				},
			}],
		});
		const state = pageState();

		await page.methods.loadRates.call(state);

		assert.deepEqual(state.quoteCurrencies, ["USD", "GBP"]);
		assert.deepEqual(JSON.parse(JSON.stringify(state.manualRates)), { USD: "1.12000000", GBP: "" });
		assert.equal(state.savedRateProvider, "manual");
		assert.equal(state.ratesLoading, false);
	});

	it("posts decimal strings and reports real saving state", async () => {
		let resolveResponse;
		const pending = new Promise((resolve) => {
			resolveResponse = resolve;
		});
		const { page, requests } = loadAdminRuntime({
			responses: [{
				body: pending,
			}],
		});
		const state = pageState({
			manualRates: { USD: "1.12000000", GBP: "0.86000000" },
		});

		const saving = page.methods.saveManualRates.call(state);
		assert.equal(state.manualRatesSaving, true);

		resolveResponse({ data: { status: true, rates: [] } });
		await saving;

		assert.equal(state.manualRatesSaving, false);
		assert.equal(requests[0].url, "https://shop.test/wp-json/fchub-mc/v1/admin/rates/manual");
		assert.deepEqual(JSON.parse(requests[0].options.body), {
			rates: { USD: "1.12000000", GBP: "0.86000000" },
		});
	});

	it("discloses manual inputs while keeping remote refresh controls out of manual mode", () => {
		const { page } = loadAdminRuntime();
		const template = page.components.RateSettings.template;

		assert.match(template, /manualRates\[code\]/);
		assert.match(template, /settings\.rate_provider !== 'manual'/);
		assert.match(template, /save-manual-rates/);
	});
});
