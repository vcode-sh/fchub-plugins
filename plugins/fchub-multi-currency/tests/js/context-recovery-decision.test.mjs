import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { recoveryContext, runRecovery } from "./context-recovery-fixture.mjs";

describe("cached page recovery decisions", () => {
	it("uses the readable preference cookie and a cache-safe internal GET contract", async () => {
		const result = await runRecovery({
			cookie: "another=value; fchub_mc_currency=EUR",
			config: { urlParamKey: "money" },
		});

		assert.equal(result.fetchCalls.length, 1);
		assert.equal(
			result.fetchCalls[0].url,
			"https://shop.test/wp-json/fchub-mc/v1/context?currency=EUR",
		);
		assert.equal(result.fetchCalls[0].options.method, "GET");
		assert.equal(result.fetchCalls[0].options.cache, "no-store");
		assert.equal(result.fetchCalls[0].options.credentials, "same-origin");
		assert.equal(result.config.displayCurrency, "EUR");
		assert.equal(result.config.rate, 0.92);
	});

	it("does not recover when the current URL, account, or valid cookie already owns the context", async () => {
		const cases = [
			{ cookie: "", config: {} },
			{ cookie: "fchub_mc_currency=USD", config: {} },
			{ cookie: "fchub_mc_currency=NOPE", config: {} },
			{ cookie: "fchub_mc_currency=EUR", config: { cookiePersistenceEnabled: false } },
			{ cookie: "fchub_mc_currency=EUR", search: "?money=USD", config: { urlParamKey: "money" } },
			{ cookie: "fchub_mc_currency=EUR", config: { resolverSource: "user_meta" } },
			{ cookie: "fchub_mc_currency=EUR", config: { isLoggedIn: true } },
		];

		for (const testCase of cases) {
			const result = await runRecovery(testCase);
			assert.equal(result.fetchCalls.length, 0, JSON.stringify(testCase));
		}
	});

	it("does not trust a cached URL source after the parameter has gone", async () => {
		const result = await runRecovery({
			cookie: "fchub_mc_currency=EUR",
			config: { resolverSource: "url_param" },
		});

		assert.equal(result.fetchCalls.length, 1);
		assert.match(result.fetchCalls[0].url, /currency=EUR$/);
		assert.equal(result.config.displayCurrency, "EUR");
	});

	it("repairs cookie-derived HTML when the browser cookie is malformed or disabled", async () => {
		for (const cookie of ["fchub_mc_currency=NOPE", "fchub_mc_currency=%E0%A4%A"]) {
			const result = await runRecovery({
				cookie,
				config: { displayCurrency: "EUR", resolverSource: "cookie" },
				responseContext: recoveryContext({
					rate: 1,
					displayCurrency: "USD",
					displayCurrencyName: "US Dollar",
					symbol: "$",
					position: "left",
					isBaseDisplay: true,
					resolverSource: "default",
				}),
			});

			assert.equal(result.fetchCalls[0]?.url, "https://shop.test/wp-json/fchub-mc/v1/context", cookie);
			assert.equal(result.config.displayCurrency, "USD", cookie);
		}
	});

	it("repairs URL-derived HTML when neither the URL nor a cookie still selects it", async () => {
		const result = await runRecovery({
			cookie: "",
			config: { displayCurrency: "EUR", resolverSource: "url_param" },
			responseContext: recoveryContext({
				rate: 1,
				displayCurrency: "USD",
				displayCurrencyName: "US Dollar",
				symbol: "$",
				position: "left",
				isBaseDisplay: true,
				resolverSource: "default",
			}),
		});

		assert.equal(result.fetchCalls[0]?.url, "https://shop.test/wp-json/fchub-mc/v1/context");
		assert.equal(result.config.displayCurrency, "USD");
	});

	it("applies a selected base currency even though its rate is one", async () => {
		const result = await runRecovery({
			cookie: "fchub_mc_currency=USD",
			config: { displayCurrency: "EUR" },
			responseContext: recoveryContext({
				rate: 1,
				displayCurrency: "USD",
				displayCurrencyName: "US Dollar",
				symbol: "$",
				position: "left",
				isBaseDisplay: true,
			}),
		});

		assert.equal(result.fetchCalls.length, 1);
		assert.equal(result.config.displayCurrency, "USD");
		assert.equal(result.config.rate, 1);
		assert.equal(result.config.isBaseDisplay, true);
	});

	it("repairs cookie-derived cached HTML for a visitor who has no currency cookie", async () => {
		const result = await runRecovery({
			cookie: "",
			config: { displayCurrency: "EUR", resolverSource: "cookie" },
			responseContext: recoveryContext({
				rate: 1,
				displayCurrency: "USD",
				displayCurrencyName: "US Dollar",
				symbol: "$",
				position: "left",
				isBaseDisplay: true,
				resolverSource: "default",
			}),
		});

		assert.equal(result.fetchCalls[0]?.url, "https://shop.test/wp-json/fchub-mc/v1/context");
		assert.equal(result.config.displayCurrency, "USD");
	});

	it("rejects a malformed response without corrupting the page state", async () => {
		const result = await runRecovery({
			cookie: "fchub_mc_currency=EUR",
			responseContext: recoveryContext({ displayCurrency: "JPY", rate: 0 }),
		});

		assert.equal(result.config.displayCurrency, "USD");
		assert.equal(result.warnings.length, 1);
	});

	it("does not apply a response after the preference cookie changes in flight", async () => {
		const result = await runRecovery({
			cookie: "fchub_mc_currency=EUR",
			onFetch: ({ document }) => {
				document.cookie = "fchub_mc_currency=USD";
			},
		});

		assert.equal(result.fetchCalls.length, 1);
		assert.equal(result.config.displayCurrency, "USD");
		assert.equal(result.warnings.length, 0);
	});
});
