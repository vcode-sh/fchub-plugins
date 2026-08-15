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

// ─── Source invariant: applyResolvedCurrency() moves the dropdown's ───
// ─── checkmark glyph, not just the trigger label and --active class ───
//
// Confirmed live (issue #72): after reconciliation corrects a stale-cached
// page, the switcher's trigger label and the projected prices both correctly
// show the reconciled currency, but opening the dropdown still shows the
// *stale* currency as checked. Root cause: CurrencySwitcherRenderer bakes the
// visible checkmark into each option's own .fchub-mc-switcher__option-check
// span as static content at render time (see show_active_indicator) — it
// isn't driven by the --active class or aria-selected, so moving those two
// alone (which applyResolvedCurrency already did) leaves the actual glyph
// behind on whichever option the page was served with as active.

describe("Source invariant: applyResolvedCurrency() moves the option-check glyph", () => {
	const match = switcherSource.match(
		/function applyResolvedCurrency\(currencyCode\) \{([\s\S]*?)\n\tif \(document\.readyState/,
	);

	it("could find applyResolvedCurrency()'s body in currency-switcher.js", () => {
		assert.ok(match, "could not find applyResolvedCurrency(...) in currency-switcher.js");
	});

	const body = match ? match[1] : "";

	it("clears the outgoing active option's checkmark", () => {
		assert.match(
			body,
			/option-check["']\)[\s\S]{0,80}?\.textContent\s*=\s*["']["']/,
			"applyResolvedCurrency() must clear the previous active option's .fchub-mc-switcher__option-check content, or the stale currency keeps showing as checked",
		);
	});

	it("sets the incoming target option's checkmark", () => {
		assert.match(
			body,
			/option-check["']\)[\s\S]{0,80}?\.textContent\s*=\s*["'][^"']+["']/,
			"applyResolvedCurrency() must set the target option's .fchub-mc-switcher__option-check content to the checkmark glyph",
		);
	});
});

// ─── Source invariant: the switcher trigger stays neutral during a ───
// ─── reconciliation candidate window, not just prices ───────────────
//
// Confirmed live (issue #72): prices are correctly hidden behind
// .fchub-mc-projecting while a reconciliation is in flight, but the
// switcher's own trigger label is server-rendered with whatever currency the
// page was served with — exactly the value in question — and was displaying
// it, uncorrected, for the whole multi-second window until reconcile()
// settled. Fixed by reusing the exact dimmed/non-interactive state
// currency-switcher.js already applies the moment a visitor picks a new
// currency (.fchub-mc-switcher--loading), applied only when there's an
// actual mismatch to investigate (reconciliationCandidate), not on every
// page load that merely needs price conversion.

describe("Source invariant: the switcher trigger is suppressed during reconciliation, not just prices", () => {
	it("applies the loading state only when reconciliationCandidate is true, alongside the FOUC class", () => {
		const match = projectionSource.match(
			/classList\.add\(["']fchub-mc-projecting["']\);([\s\S]{0,600}?)function init\(\)/,
		);

		assert.ok(match, "could not find the FOUC-class-then-init() region in currency-projection.js");
		assert.match(
			match[1],
			/if\s*\(reconciliationCandidate\)\s*\{\s*setSwitcherLoading\(true\)/,
			"the switcher trigger must be marked loading in the same synchronous pass that hides prices, gated on reconciliationCandidate — not unconditionally, since a page that only needs price conversion never has the wrong currency in the trigger",
		);
	});

	it("clears the loading state once reconcile() settles, targeting the trigger via [data-fchub-mc-switcher]", () => {
		const setterMatch = projectionSource.match(
			/function setSwitcherLoading\(isLoading\) \{([\s\S]*?)\n\t\}/,
		);
		assert.ok(setterMatch, "could not find setSwitcherLoading(...) in currency-projection.js");
		assert.match(
			setterMatch[1],
			/data-fchub-mc-switcher\]["']\)[\s\S]*?fchub-mc-switcher--loading/,
			"setSwitcherLoading() must toggle .fchub-mc-switcher--loading on [data-fchub-mc-switcher] elements",
		);

		const finallyMatch = projectionSource.match(/reconcile\(\)\.finally\(\(\) => \{([\s\S]{0,1000})/);
		assert.ok(finallyMatch, "could not find reconcile().finally(...) in currency-projection.js");
		assert.match(
			finallyMatch[1],
			/setSwitcherLoading\(false\)/,
			"reconcile()'s finally() must clear the switcher loading state once it settles (success, no-op, or failure) — otherwise the trigger stays dimmed forever",
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
