/**
 * Guest Currency Reconciliation Tests (issue #72)
 *
 * Extracts and verifies the pure decision logic from currency-projection.js and
 * currency-switcher.js that backs the localStorage fallback: some hosts strip the
 * guest currency cookie on request paths their edge/WAF layer hasn't whitelisted,
 * so the server falls back to the default currency even though the visitor picked
 * something else. localStorage plus a client-side reconciliation fetch corrects
 * this without depending on the cookie round-tripping through that layer.
 *
 * These are re-implementations of the same logic (mirroring the pattern already
 * used in projection-bugs.test.mjs), not imports — the source files are plain
 * browser IIFEs with no module exports and rely on `window`/`fetch`/`localStorage`.
 *
 * Run with: node --test tests/js/reconciliation.test.mjs
 */

import { describe, it } from "node:test";
import assert from "node:assert/strict";

// ─── Extracted from currency-projection.js / currency-switcher.js ───

/**
 * Mirrors AllowedCurrencyCheck::isAllowedCurrency() server-side.
 */
function isAllowedCurrencyCode(cfg, code) {
	if (!code) return false;
	if (code === (cfg.baseCurrency || "").toUpperCase()) return true;
	const currencies = Array.isArray(cfg.currencies) ? cfg.currencies : [];
	return currencies.some(
		(currency) => currency && typeof currency === "object" && (currency.code || "").toUpperCase() === code,
	);
}

// Source values ContextModule's resolver chain never places below a cookie — see
// currency-projection.js for the full rationale.
const RECONCILABLE_SOURCES = ["cookie", "geo", "default"];

function isReconciliationCandidate(cfg, storedCurrency) {
	return (
		!!storedCurrency &&
		storedCurrency !== cfg.displayCurrency &&
		RECONCILABLE_SOURCES.indexOf(cfg.source || "") !== -1
	);
}

function needsProjection(cfg, state) {
	return !!(
		cfg.baseCurrency &&
		state.displayCode &&
		!state.isBaseDisplay &&
		state.displayCode !== cfg.baseCurrency &&
		state.rate &&
		Number.isFinite(state.rate) &&
		state.rate !== 1
	);
}

function shouldPersistToLocalStorage(cfg) {
	return !!cfg.guestLocalStorageEnabled;
}

// ─── isAllowedCurrencyCode ───────────────────────────────────────────

describe("isAllowedCurrencyCode", () => {
	const cfg = {
		baseCurrency: "USD",
		currencies: [{ code: "EUR" }, { code: "GBP" }],
	};

	it("allows the base currency even when it isn't in the display list", () => {
		assert.equal(isAllowedCurrencyCode(cfg, "USD"), true);
	});

	it("allows a currency present in the display list", () => {
		assert.equal(isAllowedCurrencyCode(cfg, "EUR"), true);
	});

	it("rejects a currency absent from both the base and the display list", () => {
		assert.equal(isAllowedCurrencyCode(cfg, "JPY"), false);
	});

	it("rejects an empty value", () => {
		assert.equal(isAllowedCurrencyCode(cfg, ""), false);
	});

	it("tolerates a malformed currencies array without throwing", () => {
		const malformed = { baseCurrency: "USD", currencies: [null, "not-an-object", 42] };
		assert.equal(isAllowedCurrencyCode(malformed, "EUR"), false);
	});

	it("tolerates currencies being missing entirely", () => {
		assert.equal(isAllowedCurrencyCode({ baseCurrency: "USD" }, "USD"), true);
		assert.equal(isAllowedCurrencyCode({ baseCurrency: "USD" }, "EUR"), false);
	});
});

// ─── isReconciliationCandidate: the guard that decides whether a saved ───
// ─── localStorage currency is even allowed to override the page ─────────

describe("isReconciliationCandidate", () => {
	it("is NOT a candidate when there is no stored currency", () => {
		const cfg = { displayCurrency: "USD", source: "default" };
		assert.equal(isReconciliationCandidate(cfg, null), false);
	});

	it("is NOT a candidate when the stored currency already matches the page", () => {
		const cfg = { displayCurrency: "EUR", source: "cookie" };
		assert.equal(isReconciliationCandidate(cfg, "EUR"), false);
	});

	it("IS a candidate when the source is 'default' (fallback) — the WAF-stripped-cookie symptom", () => {
		const cfg = { displayCurrency: "USD", source: "default" };
		assert.equal(isReconciliationCandidate(cfg, "EUR"), true);
	});

	it("IS a candidate when the source is 'cookie' but disagrees with a fresher localStorage value", () => {
		const cfg = { displayCurrency: "EUR", source: "cookie" };
		assert.equal(isReconciliationCandidate(cfg, "GBP"), true);
	});

	it("IS a candidate when the source is 'geo'", () => {
		const cfg = { displayCurrency: "USD", source: "geo" };
		assert.equal(isReconciliationCandidate(cfg, "EUR"), true);
	});

	it("is NEVER a candidate when the source is 'url_param' — an explicit link must win", () => {
		const cfg = { displayCurrency: "USD", source: "url_param" };
		assert.equal(isReconciliationCandidate(cfg, "EUR"), false);
	});

	it("is NEVER a candidate when the source is 'user_meta' — a signed-in visitor's saved preference must win", () => {
		const cfg = { displayCurrency: "USD", source: "user_meta" };
		assert.equal(isReconciliationCandidate(cfg, "EUR"), false);
	});
});

// ─── needsProjection: whether the (possibly reconciled) state calls for ───
// ─── price conversion at all ───────────────────────────────────────────

describe("needsProjection", () => {
	const cfg = { baseCurrency: "USD" };

	it("is false when display equals base", () => {
		assert.equal(
			needsProjection(cfg, { displayCode: "USD", isBaseDisplay: false, rate: 1 }),
			false,
		);
	});

	it("is false when isBaseDisplay is true even if codes differ", () => {
		assert.equal(
			needsProjection(cfg, { displayCode: "EUR", isBaseDisplay: true, rate: 0.92 }),
			false,
		);
	});

	it("is false when the rate is exactly 1", () => {
		assert.equal(
			needsProjection(cfg, { displayCode: "EUR", isBaseDisplay: false, rate: 1 }),
			false,
		);
	});

	it("is false when the rate is not finite (NaN from a malformed response)", () => {
		assert.equal(
			needsProjection(cfg, { displayCode: "EUR", isBaseDisplay: false, rate: Number.NaN }),
			false,
		);
	});

	it("is true for a valid, differing display currency with a real rate", () => {
		assert.equal(
			needsProjection(cfg, { displayCode: "EUR", isBaseDisplay: false, rate: 0.92 }),
			true,
		);
	});
});

// ─── shouldPersistToLocalStorage: cookie_enabled gates the guest fallback ───

describe("shouldPersistToLocalStorage", () => {
	it("persists when guestLocalStorageEnabled is true (cookie_enabled === 'yes')", () => {
		assert.equal(shouldPersistToLocalStorage({ guestLocalStorageEnabled: true }), true);
	});

	it("does NOT persist when guestLocalStorageEnabled is false (cookie_enabled === 'no') — no side door around a deliberate site setting", () => {
		assert.equal(shouldPersistToLocalStorage({ guestLocalStorageEnabled: false }), false);
	});

	it("does NOT persist when the field is absent (defensive default)", () => {
		assert.equal(shouldPersistToLocalStorage({}), false);
	});
});

// ─── End-to-end scenario: the exact bug from issue #72 ───────────────────

describe("Issue #72: cookie stripped by host edge/WAF layer for a guest", () => {
	it("reconciles a guest whose cookie never reached the server on this page load", () => {
		// The server-rendered page: WAF stripped the request Cookie header, so
		// CookieResolver saw nothing and the chain fell through to the default.
		const cfg = {
			baseCurrency: "USD",
			displayCurrency: "USD",
			source: "default",
			guestLocalStorageEnabled: true,
			currencies: [{ code: "EUR" }],
		};

		// The browser still has the guest's earlier choice in localStorage.
		const storedRaw = "eur";
		const storedCode = isAllowedCurrencyCode(cfg, storedRaw.toUpperCase()) ? storedRaw.toUpperCase() : null;

		assert.equal(storedCode, "EUR");
		assert.equal(isReconciliationCandidate(cfg, storedCode), true);

		// The REST fetch (GET /context?currency=EUR) resolves via the URL param,
		// independent of the cookie, and returns a real conversion.
		const reconciled = { displayCode: "EUR", isBaseDisplay: false, rate: 0.92 };
		assert.equal(needsProjection(cfg, reconciled), true);
	});

	it("does not touch a logged-in visitor's explicit account preference", () => {
		// Signed-in visitor: UserMetaResolver already won, so source is user_meta —
		// even if a stale localStorage value from a previous guest session on this
		// browser disagrees, it must not override the account preference.
		const cfg = {
			baseCurrency: "USD",
			displayCurrency: "GBP",
			source: "user_meta",
			guestLocalStorageEnabled: true,
			currencies: [{ code: "EUR" }, { code: "GBP" }],
		};

		assert.equal(isReconciliationCandidate(cfg, "EUR"), false);
	});

	it("does nothing on a host without the bug, where the cookie resolves correctly", () => {
		const cfg = {
			baseCurrency: "USD",
			displayCurrency: "EUR",
			source: "cookie",
			guestLocalStorageEnabled: true,
			currencies: [{ code: "EUR" }],
		};

		// localStorage agrees with the page already — nothing to reconcile.
		assert.equal(isReconciliationCandidate(cfg, "EUR"), false);
	});
});
