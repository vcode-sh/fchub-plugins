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
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import path from "node:path";

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

// ─── Source invariants: reconcile()'s fetch must never be cache-servable ───
//
// Confirmed live on issue #72 (Rocket.net staging): every layer resolved the
// right currency (localStorage held it, GET /context?currency=X returned it,
// even the app's own fetch() got a 200) but the page still settled back on the
// stale currency, because the browser silently served the reconciliation
// fetch from its own HTTP cache once that exact ?currency=X URL had been
// fetched once — nothing told it not to. cache: "no-store" is what fixes
// that, and it's a one-word regression a future edit could silently drop
// without any of the pure-logic tests above ever noticing (they don't touch
// fetch() at all). Read the real shipped file rather than re-implementing
// the call, so this actually fails if the source regresses — a hand-mirrored
// copy of the fetch call would only prove the copy still has it.

const here = path.dirname(fileURLToPath(import.meta.url));
const projectionSource = readFileSync(path.join(here, "..", "..", "assets", "js", "currency-projection.js"), "utf8");
const switcherSource = readFileSync(path.join(here, "..", "..", "assets", "js", "currency-switcher.js"), "utf8");
const switcherCssSource = readFileSync(path.join(here, "..", "..", "assets", "css", "currency-switcher.css"), "utf8");

describe("Source invariant: currency-projection.js reconcile() fetch is not cacheable", () => {
	it("reconcile()'s fetch call to /context?currency= sets cache: \"no-store\"", () => {
		// Anchored on the query string, which only reconcile()'s fetch call
		// builds — this can't accidentally match the switcher's POST fetch.
		const match = projectionSource.match(/fetch\(`\$\{restUrl\}\/context\?currency=[^`]*`,\s*\{([^}]*)\}/s);

		assert.ok(match, "could not find reconcile()'s fetch(...) call in currency-projection.js");
		assert.match(
			match[1],
			/cache:\s*["']no-store["']/,
			'reconcile()\'s fetch options must include cache: "no-store" — see issue #72 (Rocket.net cache regression)',
		);
	});
});

describe("Source invariant: currency-switcher.js POST /context fetch is not cacheable", () => {
	it('switchCurrency()\'s fetch call sets cache: "no-store"', () => {
		const match = switcherSource.match(/fetch\(`\$\{restUrl\}\/context`,\s*\{([^}]*(?:\{[^}]*\}[^}]*)*)\}/s);

		assert.ok(match, "could not find switchCurrency()'s fetch(...) call in currency-switcher.js");
		assert.match(
			match[1],
			/cache:\s*["']no-store["']/,
			'switchCurrency()\'s fetch options must include cache: "no-store", for consistency with the server\'s Cache-Control: no-store on this same endpoint',
		);
	});
});

// ─── Source invariants: reconcile()'s timeout and the CSS FOUC fallback ───
//
// Confirmed live on a Rocket.net staging tier (issue #72): reconcile()'s fetch
// was still in flight past the previous 4s AbortController bound, because it
// competes with the rest of the page's own PHP-driven requests for the same
// PHP-FPM worker pool — a concurrent, unrelated admin-ajax.php call on the same
// page load alone took 5.4s. Separately, the CSS safety-net fallback that
// force-reveals prices if JS never removes .fchub-mc-projecting was a flat 2s,
// firing WHILE that same legitimate reconciliation was still in flight and
// force-revealing raw, unconverted base-currency prices regardless of whether
// JS was working correctly. Both numbers are guesses about hosts this project
// doesn't control, so what's actually checkable and worth guarding here isn't
// "is 12000 the right number" (nobody can prove that in a unit test) but the
// two invariants a regression could silently break: the timeout has real
// headroom over the observed worst case, and the CSS fallback can never win a
// race against a reconcile() call that's behaving normally.

function readReconcileTimeoutMs() {
	const match = projectionSource.match(/RECONCILE_TIMEOUT_MS\s*=\s*(\d+)/);
	assert.ok(match, "could not find RECONCILE_TIMEOUT_MS in currency-projection.js");
	return Number(match[1]);
}

describe("Source invariant: reconcile()'s timeout clears observed real-world PHP latency", () => {
	it("RECONCILE_TIMEOUT_MS has real headroom over the 5.4s observed live on issue #72", () => {
		const timeoutMs = readReconcileTimeoutMs();

		// Not "greater than 5400" — that would clear the one measurement with no
		// margin at all. Queuing for a starved PHP-FPM pool compounds under
		// heavier load than the single sample taken live, so the bound needs
		// headroom above it, not just past it.
		assert.ok(
			timeoutMs >= 10000,
			`RECONCILE_TIMEOUT_MS (${timeoutMs}ms) should keep meaningful headroom over the 5.4s worst case observed live (issue #72), not just clear it`,
		);
	});
});

describe("Source invariant: the CSS FOUC fallback never races an in-flight reconciliation", () => {
	it("fchub-mc-fouc-fallback's animation-delay is longer than RECONCILE_TIMEOUT_MS, with real margin", () => {
		const jsTimeoutMs = readReconcileTimeoutMs();

		const cssMatch = switcherCssSource.match(/animation:\s*fchub-mc-fouc-fallback\s+0s\s+([\d.]+)s\s+forwards/);
		assert.ok(cssMatch, "could not find the fchub-mc-fouc-fallback animation-delay in currency-switcher.css");
		const cssDelayMs = Number(cssMatch[1]) * 1000;

		assert.ok(
			cssDelayMs > jsTimeoutMs,
			`CSS FOUC fallback (${cssDelayMs}ms) must fire after reconcile()'s own worst-case bound (${jsTimeoutMs}ms) — otherwise it force-reveals raw base-currency prices while a legitimate reconciliation is still in flight (issue #72)`,
		);
		assert.ok(
			cssDelayMs - jsTimeoutMs >= 2000,
			`CSS FOUC fallback (${cssDelayMs}ms) should clear RECONCILE_TIMEOUT_MS (${jsTimeoutMs}ms) with at least 2s of margin, not just technically come after it — the JS still has to run its .finally() callback and a full projectPrices() pass after the fetch settles`,
		);
	});
});
