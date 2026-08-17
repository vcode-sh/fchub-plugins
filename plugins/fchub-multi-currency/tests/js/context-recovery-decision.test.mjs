import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { recoveryContext, runRecovery } from "./context-recovery-fixture.mjs";

function storedPreference(result) {
	return JSON.parse(result.storageValues.get("fchub_mc_currency"));
}

describe("cached page recovery decisions", () => {
	it("migrates a readable preference cookie and uses an explicit recovery URL", async () => {
		const result = await runRecovery({
			cookie: "another=value; fchub_mc_currency=EUR",
			config: { urlParamKey: "money" },
		});

		assert.equal(result.fetchCalls.length, 1);
		assert.match(
			result.fetchCalls[0].url,
			/^https:\/\/shop\.test\/wp-json\/fchub-mc\/v1\/context\?currency=EUR&_fchub_mc=\d+$/,
		);
		assert.equal(result.fetchCalls[0].options.method, "GET");
		assert.equal(result.fetchCalls[0].options.cache, "no-store");
		assert.equal(result.fetchCalls[0].options.credentials, "same-origin");
		assert.equal(result.config.displayCurrency, "EUR");
		assert.equal(result.config.rate, 0.92);
		assert.equal(storedPreference(result).currency, "EUR");
		assert.equal(storedPreference(result).expiresAt > Date.now(), true);
	});

	it("recovers the browser preference when Rocket-style cached HTML has no usable cookie", async () => {
		const result = await runRecovery({
			cookie: "",
			storage: { fchub_mc_currency: "eur" },
		});

		assert.equal(result.fetchCalls.length, 1);
		assert.match(
			result.fetchCalls[0].url,
			/^https:\/\/shop\.test\/wp-json\/fchub-mc\/v1\/context\?currency=EUR&_fchub_mc=\d+$/,
		);
		assert.equal(result.config.displayCurrency, "EUR");
		assert.equal(storedPreference(result).currency, "EUR");
	});

	it("cache-busts explicit recovery requests when an edge ignores no-store", async () => {
		const result = await runRecovery({
			storage: { fchub_mc_currency: "EUR" },
		});
		const recoveryUrl = new URL(result.fetchCalls[0].url);

		assert.equal(recoveryUrl.searchParams.get("currency"), "EUR");
		assert.match(recoveryUrl.searchParams.get("_fchub_mc") || "", /^\d+$/);
	});

	it("removes a confirmed one-shot switch URL without losing other URL state", async () => {
		for (const currency of ["EUR", "USD"]) {
			const result = await runRecovery({
				search: `?money=${currency}&utm_source=test`,
				storage: {
					fchub_mc_currency: JSON.stringify({
						currency,
						expiresAt: Date.now() + 60_000,
					}),
				},
				config: {
					displayCurrency: currency,
					resolverSource: "url_param",
					urlParamKey: "money",
				},
				responseContext:
					currency === "EUR"
						? recoveryContext()
						: recoveryContext({
								rate: 1,
								displayCurrency: "USD",
								displayCurrencyName: "US Dollar",
								symbol: "$",
								position: "left",
								isBaseDisplay: true,
								resolverSource: "cookie",
							}),
			});

			assert.equal(result.fetchCalls.length, 1, currency);
			assert.match(result.fetchCalls[0].url, new RegExp(`currency=${currency}&_fchub_mc=\\d+$`));
			assert.deepEqual(result.replacedUrls, ["https://shop.test/product?utm_source=test#plans"]);
			assert.deepEqual(result.window.history.state, { cartDrawer: "open" });
		}
	});

	it("revalidates a real currency link but keeps it unless browser storage confirms the choice", async () => {
		for (const storage of [
			{},
			{
				fchub_mc_currency: JSON.stringify({
					currency: "USD",
					expiresAt: Date.now() + 60_000,
				}),
			},
		]) {
			const result = await runRecovery({
				search: "?currency=EUR&utm_source=test",
				storage,
				config: { displayCurrency: "EUR", resolverSource: "url_param" },
			});

			assert.equal(result.fetchCalls.length, 1);
			assert.match(result.fetchCalls[0].url, /context\?currency=EUR&_fchub_mc=\d+$/);
			assert.deepEqual(result.replacedUrls, []);
			assert.equal(result.window.location.href, "https://shop.test/product?currency=EUR&utm_source=test#plans");
		}
	});

	it("repairs a confirmed switch URL when an edge serves the wrong currency HTML", async () => {
		const result = await runRecovery({
			search: "?currency=EUR&utm_source=test",
			storage: {
				fchub_mc_currency: JSON.stringify({
					currency: "EUR",
					expiresAt: Date.now() + 60_000,
				}),
			},
			config: { displayCurrency: "USD", resolverSource: "url_param" },
			responseContext: recoveryContext(),
		});

		assert.equal(result.fetchCalls.length, 1);
		assert.match(result.fetchCalls[0].url, /context\?currency=EUR&_fchub_mc=\d+$/);
		assert.equal(result.config.displayCurrency, "EUR");
		assert.deepEqual(result.replacedUrls, ["https://shop.test/product?utm_source=test#plans"]);
	});

	it("keeps a mismatched switch URL when explicit recovery fails", async () => {
		const result = await runRecovery({
			search: "?currency=EUR&utm_source=test",
			storage: {
				fchub_mc_currency: JSON.stringify({
					currency: "EUR",
					expiresAt: Date.now() + 60_000,
				}),
			},
			config: { displayCurrency: "USD", resolverSource: "url_param" },
			response: { ok: false, status: 503 },
		});

		assert.equal(result.fetchCalls.length, 1);
		assert.equal(result.config.displayCurrency, "USD");
		assert.deepEqual(result.replacedUrls, []);
		assert.equal(result.window.location.href, "https://shop.test/product?currency=EUR&utm_source=test#plans");
	});

	it("expires the mirrored preference on the same lifecycle as the cookie", async () => {
		const result = await runRecovery({
			storage: {
				fchub_mc_currency: JSON.stringify({ currency: "EUR", expiresAt: 1 }),
			},
		});

		assert.equal(result.fetchCalls.length, 0);
		assert.equal(result.storageValues.has("fchub_mc_currency"), false);
	});

	it("removes an existing browser mirror when guest persistence is disabled", async () => {
		const result = await runRecovery({
			storage: {
				fchub_mc_currency: JSON.stringify({
					currency: "EUR",
					expiresAt: Date.now() + 60_000,
				}),
			},
			config: { cookiePersistenceEnabled: false },
		});

		assert.equal(result.fetchCalls.length, 0);
		assert.equal(result.storageValues.has("fchub_mc_currency"), false);
		assert.equal(
			result.storageCalls.some((call) => call.method === "removeItem"),
			true,
		);
	});

	it("accepts a current structured preference without extending its expiry", async () => {
		const expiresAt = Date.now() + 60_000;
		const raw = JSON.stringify({ currency: "EUR", expiresAt });
		const result = await runRecovery({
			storage: { fchub_mc_currency: raw },
		});

		assert.match(result.fetchCalls[0]?.url, /currency=EUR&_fchub_mc=\d+$/);
		assert.equal(result.storageValues.get("fchub_mc_currency"), raw);
	});

	it("rejects a non-finite expiry instead of creating immortal browser state", async () => {
		const result = await runRecovery({
			storage: {
				fchub_mc_currency: JSON.stringify({ currency: "EUR", expiresAt: "Infinity" }),
			},
		});

		assert.equal(result.fetchCalls.length, 0);
		assert.equal(result.storageValues.has("fchub_mc_currency"), false);
	});

	it("stores a validated switch choice with the configured expiry", async () => {
		const before = Date.now();
		const result = await runRecovery({ config: { cookieLifetimeDays: 30 } });

		result.window.fchubMcPersistBrowserPreference("EUR");
		const stored = storedPreference(result);

		assert.equal(stored.currency, "EUR");
		assert.equal(stored.expiresAt >= before + 30 * 86400000, true);
		assert.equal(stored.expiresAt <= Date.now() + 30 * 86400000, true);
	});

	it("uses the mirrored browser preference when it disagrees with a stale cookie", async () => {
		const result = await runRecovery({
			cookie: "fchub_mc_currency=USD",
			storage: { fchub_mc_currency: "EUR" },
		});

		assert.match(result.fetchCalls[0]?.url, /currency=EUR&_fchub_mc=\d+$/);
		assert.equal(result.config.displayCurrency, "EUR");
	});

	it("uses the internal recovery contract even when public URL switching is disabled", async () => {
		const result = await runRecovery({
			storage: { fchub_mc_currency: "EUR" },
			config: { urlParamEnabled: false },
		});

		assert.match(result.fetchCalls[0]?.url, /currency=EUR&_fchub_mc=\d+$/);
		assert.equal(result.config.displayCurrency, "EUR");
	});

	it("does not recover when the account or valid browser preference already owns the context", async () => {
		const cases = [
			{ cookie: "", config: {} },
			{ cookie: "fchub_mc_currency=USD", config: {} },
			{ cookie: "fchub_mc_currency=NOPE", config: {} },
			{
				cookie: "fchub_mc_currency=EUR",
				storage: { fchub_mc_currency: "EUR" },
				config: { cookiePersistenceEnabled: false },
			},
			{
				cookie: "fchub_mc_currency=EUR",
				storage: { fchub_mc_currency: "EUR" },
				config: { resolverSource: "user_meta" },
			},
			{
				cookie: "fchub_mc_currency=EUR",
				storage: { fchub_mc_currency: "EUR" },
				config: { isLoggedIn: true },
			},
			{
				storage: { fchub_mc_currency: "USD" },
				config: { displayCurrency: "USD" },
			},
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
		assert.match(result.fetchCalls[0].url, /currency=EUR&_fchub_mc=\d+$/);
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

			assert.match(
				result.fetchCalls[0]?.url || "",
				/^https:\/\/shop\.test\/wp-json\/fchub-mc\/v1\/context\?_fchub_mc=\d+$/,
				cookie,
			);
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

		assert.match(
			result.fetchCalls[0]?.url || "",
			/^https:\/\/shop\.test\/wp-json\/fchub-mc\/v1\/context\?_fchub_mc=\d+$/,
		);
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

		assert.match(
			result.fetchCalls[0]?.url || "",
			/^https:\/\/shop\.test\/wp-json\/fchub-mc\/v1\/context\?_fchub_mc=\d+$/,
		);
		assert.equal(result.config.displayCurrency, "USD");
	});

	it("discards unsupported storage and falls back to the valid cookie", async () => {
		const result = await runRecovery({
			cookie: "fchub_mc_currency=EUR",
			storage: { fchub_mc_currency: "JPY" },
		});

		assert.match(result.fetchCalls[0]?.url, /currency=EUR&_fchub_mc=\d+$/);
		assert.equal(storedPreference(result).currency, "EUR");
		assert.equal(
			result.storageCalls.some((call) => call.method === "removeItem"),
			true,
		);
	});

	it("falls back to the cookie when local storage is unavailable", async () => {
		const result = await runRecovery({
			cookie: "fchub_mc_currency=EUR",
			storageError: new Error("storage blocked"),
		});

		assert.match(result.fetchCalls[0]?.url, /currency=EUR&_fchub_mc=\d+$/);
		assert.equal(result.config.displayCurrency, "EUR");
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

	it("does not apply a response after the mirrored preference changes in flight", async () => {
		const result = await runRecovery({
			storage: { fchub_mc_currency: "EUR" },
			onFetch: ({ localStorage }) => {
				localStorage.setItem("fchub_mc_currency", "USD");
			},
		});

		assert.equal(result.fetchCalls.length, 1);
		assert.equal(result.config.displayCurrency, "USD");
		assert.equal(result.warnings.length, 0);
	});

	it("submits the recovered browser currency through FluentCart checkout", async () => {
		const result = await runRecovery({
			storage: { fchub_mc_currency: "EUR" },
		});
		const field = { value: "USD" };
		const form = {
			querySelectorAll(selector) {
				return selector === "[data-fchub-mc-checkout-currency]" ? [field] : [];
			},
		};

		result.document.dispatchEvent({ type: "submit", target: form });

		assert.equal(field.value, "EUR");
		assert.equal(result.eventListeners.get("submit")?.[0]?.options, true);
	});
});
