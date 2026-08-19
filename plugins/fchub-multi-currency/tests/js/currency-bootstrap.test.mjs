import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { baseConfig, loadBootstrap } from "./currency-bootstrap-fixture.mjs";

const day = 86400000;
const entry = (currency, offsetMs = day) => JSON.stringify({ currency, expiresAt: Date.now() + offsetMs });

describe("currency bootstrap precedence", () => {
	it("prefers an explicit URL currency without adopting it as the visitor's preference", () => {
		const { currency, storage } = loadBootstrap({ search: "?currency=EUR" });

		assert.equal(currency, "EUR");
		assert.equal(storage.size, 0, "A shared link must not rewrite the recipient's saved choice.");
	});

	it("ignores the URL when the store has that resolver switched off", () => {
		const config = baseConfig({ urlParamEnabled: false });

		assert.equal(loadBootstrap({ config, search: "?currency=EUR" }).currency, "USD");
	});

	it("ignores a URL currency the store does not offer", () => {
		const { currency } = loadBootstrap({ search: "?currency=JPY", cookie: "fchub_mc_currency=EUR" });

		assert.equal(currency, "EUR");
	});

	it("prefers a stored choice over the cookie", () => {
		const { currency } = loadBootstrap({
			storage: { fchub_mc_currency: entry("EUR") },
			cookie: "fchub_mc_currency=USD",
		});

		assert.equal(currency, "EUR");
	});

	it("prefers a stored choice over the account preference, so a fresh switch is not undone", () => {
		const config = baseConfig({ isLoggedIn: true, accountCurrency: "USD" });
		const { currency } = loadBootstrap({ config, storage: { fchub_mc_currency: entry("EUR") } });

		assert.equal(currency, "EUR");
	});

	it("believes an account preference only from a page that cannot have been cached for someone else", () => {
		const guest = loadBootstrap({ config: baseConfig({ accountCurrency: "EUR" }) });
		assert.equal(guest.currency, "USD");

		const member = loadBootstrap({ config: baseConfig({ accountCurrency: "EUR", isLoggedIn: true }) });
		assert.equal(member.currency, "EUR");
	});

	it("falls back to the configured default before the base currency", () => {
		const config = baseConfig({ defaultCurrency: "EUR" });

		assert.equal(loadBootstrap({ config }).currency, "EUR");
	});

	it("resolves the base currency when the visitor has expressed nothing", () => {
		assert.equal(loadBootstrap({}).currency, "USD");
	});
});

describe("currency bootstrap storage handling", () => {
	it("discards an expired entry and falls through to the cookie", () => {
		const { currency, storage } = loadBootstrap({
			storage: { fchub_mc_currency: entry("EUR", -day) },
			cookie: "fchub_mc_currency=USD",
		});

		assert.equal(currency, "USD");
		assert.equal(storage.size, 0, "Expired entries are cleared, not left to rot.");
	});

	it("discards a stored currency the store has since removed", () => {
		const { currency } = loadBootstrap({ storage: { fchub_mc_currency: entry("PLN") } });

		assert.equal(currency, "USD");
	});

	it("discards unparseable storage rather than throwing", () => {
		const { currency } = loadBootstrap({ storage: { fchub_mc_currency: "{not json" } });

		assert.equal(currency, "USD");
	});

	it("migrates a bare legacy code into a dated entry", () => {
		const { currency, storage } = loadBootstrap({ storage: { fchub_mc_currency: "EUR" } });

		assert.equal(currency, "EUR");
		assert.match(storage.get("fchub_mc_currency"), /"expiresAt"/);
	});

	it("falls back to the cookie when storage is blocked entirely", () => {
		const { currency } = loadBootstrap({ storageThrows: true, cookie: "fchub_mc_currency=EUR" });

		assert.equal(currency, "EUR");
	});

	it("reads a cookie that shares a prefix with another cookie", () => {
		const { currency } = loadBootstrap({ cookie: "fchub_mc_currency_other=USD; fchub_mc_currency=EUR" });

		assert.equal(currency, "EUR");
	});
});

describe("currency bootstrap setCurrency", () => {
	it("records a valid choice and reports it immediately", () => {
		const { api, storage } = loadBootstrap({});

		assert.equal(api.setCurrency("EUR"), true);
		assert.equal(api.currentCurrency(), "EUR");
		assert.match(storage.get("fchub_mc_currency"), /"EUR"/);
	});

	it("refuses a currency the store does not offer and keeps the current one", () => {
		const { api, storage } = loadBootstrap({});

		assert.equal(api.setCurrency("JPY"), false);
		assert.equal(api.currentCurrency(), "USD");
		assert.equal(storage.size, 0);
	});

	it("keeps the choice for this page but writes nothing when the store forbids persistence", () => {
		const config = baseConfig({ cookiePersistenceEnabled: false });
		const { api, storage } = loadBootstrap({ config });

		assert.equal(api.setCurrency("EUR"), true);
		assert.equal(api.currentCurrency(), "EUR");
		assert.equal(storage.size, 0);
	});

	it("clears a stored preference the store no longer permits", () => {
		const config = baseConfig({ cookiePersistenceEnabled: false });
		const { currency, storage } = loadBootstrap({ config, storage: { fchub_mc_currency: entry("EUR") } });

		assert.equal(currency, "USD");
		assert.equal(storage.size, 0);
	});
});

describe("currency bootstrap price shield", () => {
	it("hides prices only for a visitor whose amounts actually need converting", () => {
		assert.equal(loadBootstrap({ search: "?currency=EUR" }).pending, true);
		assert.equal(loadBootstrap({}).pending, false, "A base-currency visitor waits for nothing.");
	});

	/**
	 * A store with no usable rate has an empty table, so there is no currency to
	 * resolve to. Raising the shield there hid every price behind a two-second CSS
	 * fallback on a fresh install, where nothing was ever going to be converted.
	 */
	it("raises no shield when the store has no currency to offer", () => {
		const config = baseConfig({ currencyTable: {} });
		const run = loadBootstrap({ config });

		assert.equal(run.currency, "");
		assert.equal(run.pending, false);
	});

	it("never hides prices in a store that has projection switched off", () => {
		const config = baseConfig({ projectionEnabled: false });

		assert.equal(loadBootstrap({ config, search: "?currency=EUR" }).pending, false);
	});
});

describe("locale hint on a true first visit", () => {
	const geoConfig = (overrides = {}) =>
		baseConfig({
			geoEnabled: true,
			localeCurrencies: { "Europe/Berlin": "EUR" },
			...overrides,
		});

	it("resolves the offered currency implied by the visitor's timezone", () => {
		const { currency } = loadBootstrap({
			intlTimeZone: "Europe/Berlin",
			config: geoConfig(),
		});

		assert.equal(currency, "EUR");
	});

	it("never outranks a remembered choice", () => {
		const { currency } = loadBootstrap({
			intlTimeZone: "Europe/Berlin",
			cookie: "fchub_mc_currency=USD",
			config: geoConfig(),
		});

		assert.equal(currency, "USD", "The cookie is a real preference; the timezone is a guess.");
	});

	it("stays silent when detection is off, the zone is unmapped, or Intl is absent", () => {
		const off = loadBootstrap({ intlTimeZone: "Europe/Berlin", config: geoConfig({ geoEnabled: false }) });
		assert.equal(off.currency, "USD");

		const unmapped = loadBootstrap({ intlTimeZone: "Pacific/Kiritimati", config: geoConfig() });
		assert.equal(unmapped.currency, "USD");

		const noIntl = loadBootstrap({ config: geoConfig() });
		assert.equal(noIntl.currency, "USD", "A sandbox without Intl mirrors an exotic embedder; the guard swallows it.");
	});

	it("ignores a mapped currency the store no longer offers", () => {
		const { currency } = loadBootstrap({
			intlTimeZone: "Europe/Warsaw",
			config: geoConfig({ localeCurrencies: { "Europe/Warsaw": "PLN" } }),
		});

		assert.equal(currency, "USD", "A stale cached map must not resolve to a currency without a rate.");
	});

	it("raises the price shield for a locale-resolved non-base currency", () => {
		const { pending } = loadBootstrap({
			intlTimeZone: "Europe/Berlin",
			config: geoConfig(),
		});

		assert.equal(pending, true, "A first paint that will convert must not flash base prices.");
	});
});
