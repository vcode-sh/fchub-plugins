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

// ─── Extracted from currency-switcher.js: buildReloadUrl() ────────────────
// ─── and currency-projection.js: stripUrlParamFromAddressBar() ────────────
//
// Issue #72 follow-up: switching currency used to always window.location.reload(),
// which for a guest whose cookie doesn't reliably reach the server on that reload
// meant a second, separate client-side reconciliation fetch was the only way the
// freshly loaded page ever saw the right currency — two sequential slow round-trips
// where a signed-in visitor only ever pays one. UrlParamResolver is Priority 1 in
// the resolver chain (above the cookie, above a signed-in visitor's saved
// preference), and reads $_GET directly on any request — including a reload — so
// appending the switched-to currency as that URL param lets the reload's own
// server-render resolve it correctly in one round-trip, matching the logged-in
// timing exactly. RECONCILABLE_SOURCES excluding "url_param" (already covered
// above) is what keeps the restored Part 1 loading UI from firing for this path.

function buildReloadUrl(currentHref, currencyCode, urlParamEnabled, urlParamKey) {
	if (!urlParamEnabled) return null;
	const paramKey = urlParamKey || "currency";
	try {
		const url = new URL(currentHref);
		url.searchParams.set(paramKey, currencyCode);
		return url.toString();
	} catch {
		return null;
	}
}

function computeStrippedUrl(currentHref, source, urlParamKey) {
	if (source !== "url_param" || !urlParamKey) return null;
	try {
		const url = new URL(currentHref);
		if (!url.searchParams.has(urlParamKey)) return null;
		url.searchParams.delete(urlParamKey);
		return url.toString();
	} catch {
		return null;
	}
}

describe("buildReloadUrl", () => {
	it("returns null when urlParamEnabled is false — callers must fall back to a plain reload()", () => {
		assert.equal(buildReloadUrl("https://example.com/shop/", "EUR", false, "currency"), null);
	});

	it("appends the currency param when the URL has no existing query string", () => {
		const url = buildReloadUrl("https://example.com/shop/", "EUR", true, "currency");
		assert.equal(url, "https://example.com/shop/?currency=EUR");
	});

	it("overwrites, not duplicates, a currency param already present from a previous switch", () => {
		const url = buildReloadUrl("https://example.com/shop/?currency=USD", "EUR", true, "currency");
		assert.equal(url, "https://example.com/shop/?currency=EUR");
		assert.equal((url.match(/currency=/g) || []).length, 1, "must set the param in place, never append a second copy");
	});

	it("overwrites a currency param from an incoming ?currency= link the same way", () => {
		const url = buildReloadUrl("https://example.com/shop/?currency=GBP&ref=newsletter", "EUR", true, "currency");
		const parsed = new URL(url);
		assert.equal(parsed.searchParams.get("currency"), "EUR");
		assert.equal(parsed.searchParams.get("ref"), "newsletter");
	});

	it("preserves unrelated existing query params and the hash", () => {
		const url = buildReloadUrl("https://example.com/shop/?sort=price#reviews", "EUR", true, "currency");
		const parsed = new URL(url);
		assert.equal(parsed.searchParams.get("sort"), "price");
		assert.equal(parsed.searchParams.get("currency"), "EUR");
		assert.equal(parsed.hash, "#reviews");
	});

	it("uses the configured urlParamKey instead of hardcoding \"currency\"", () => {
		const url = buildReloadUrl("https://example.com/shop/", "EUR", true, "curr");
		const parsed = new URL(url);
		assert.equal(parsed.searchParams.get("curr"), "EUR");
		assert.equal(parsed.searchParams.has("currency"), false);
	});

	it("defaults to \"currency\" when urlParamKey is falsy but enabled is true", () => {
		const url = buildReloadUrl("https://example.com/shop/", "EUR", true, "");
		const parsed = new URL(url);
		assert.equal(parsed.searchParams.get("currency"), "EUR");
	});
});

describe("computeStrippedUrl (stripUrlParamFromAddressBar's pure logic)", () => {
	it("does nothing when the resolved source isn't url_param — must never touch a cookie/geo/default-resolved page's URL", () => {
		assert.equal(computeStrippedUrl("https://example.com/shop/?currency=EUR", "cookie", "currency"), null);
	});

	it("does nothing when the param isn't actually present in the URL", () => {
		assert.equal(computeStrippedUrl("https://example.com/shop/", "url_param", "currency"), null);
	});

	it("strips the param when source is url_param and the param is present", () => {
		const url = computeStrippedUrl("https://example.com/shop/?currency=EUR", "url_param", "currency");
		assert.equal(url, "https://example.com/shop/");
	});

	it("preserves other query params and the path when stripping", () => {
		const url = computeStrippedUrl("https://example.com/shop/?sort=price&currency=EUR#reviews", "url_param", "currency");
		const parsed = new URL(url);
		assert.equal(parsed.searchParams.has("currency"), false);
		assert.equal(parsed.searchParams.get("sort"), "price");
		assert.equal(parsed.hash, "#reviews");
	});
});

// ─── End-to-end: the two scenarios this file must never let bleed into ────
// ─── each other — a currency switch vs. arriving at a stale cached page ───

describe("Issue #72 follow-up: switching currency vs. arriving at a stale cached page are handled differently, on purpose", () => {
	it("a currency switch's reload lands with source url_param — not a reconciliation candidate, no restored loading UI, no second fetch", () => {
		const reloadUrl = buildReloadUrl("https://example.com/shop/", "EUR", true, "currency");
		assert.equal(reloadUrl, "https://example.com/shop/?currency=EUR");

		// The server-render this reload triggers resolves via UrlParamResolver
		// (Priority 1), so the freshly loaded page's own cfg.source is "url_param".
		const cfg = { displayCurrency: "EUR", source: "url_param" };
		assert.equal(
			isReconciliationCandidate(cfg, "EUR"),
			false,
			"a switch's own reload must never be treated as a reconciliation candidate — it already resolved correctly server-side in one round-trip",
		);
	});

	it("arriving at a stale cached page still lands with a reconcilable source — the restored Part 1 loading UI still applies", () => {
		// A guest arrives at a full-page cache HIT (or a WAF-stripped-cookie
		// request): the server never saw the visitor's real preference, source
		// falls through to cookie/geo/default, and localStorage disagrees.
		const cfg = { displayCurrency: "USD", source: "default" };
		assert.equal(
			isReconciliationCandidate(cfg, "EUR"),
			true,
			"this scenario is exactly what Part 1's restored setSwitcherLoading()/syncTriggerToCode() and the reconciliation fetch exist for — switching currency being fixed to a single round-trip must not regress this path",
		);
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
	it("applies the loading state only when reconciliationCandidate is true, in the else branch of the FOUC-class decision", () => {
		const match = projectionSource.match(
			/if\s*\(!reconciliationCandidate\)\s*\{[\s\S]{0,300}?\}\s*else\s*\{([\s\S]{0,900}?)\n\t\}/,
		);

		assert.ok(match, "could not find the `if (!reconciliationCandidate) { ... } else { ... }` FOUC-class/loading-state block in currency-projection.js");
		assert.match(
			match[1],
			/setSwitcherLoading\(true\)/,
			"the switcher trigger must be marked loading in the else branch (reconciliationCandidate true) of the same decision that decides whether to hide prices — not unconditionally, since a page that only needs price conversion never has the wrong currency in the trigger",
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

// ─── Source invariant: the trigger shows the visitor's own choice — ───
// ─── never a stale value — for the entire reconciliation wait ─────────
//
// Confirmed live via the sessionStorage trace on production (issue #72):
// setSwitcherLoading(true) correctly dimmed the trigger immediately, but its
// TEXT stayed the page's stale server-rendered value (e.g. "AUD") for the
// whole ~5.6s wait, only flipping to the correct value ("CHF") the instant
// reconcile() finally resolved. A dimmed-but-wrong value is still a wrong
// value on screen. Per the site owner's explicit, non-negotiable spec: the
// trigger must show the visitor's own localStorage choice immediately and
// throughout, never the stale cfg value and never blank — only prices (which
// are genuinely invalid until reconciled) may be hidden during this window.

describe("Source invariant: the trigger syncs to the visitor's choice immediately, not just prices", () => {
	it("syncs the trigger to storedCurrencyAtLoad in the same synchronous pass that applies the loading state", () => {
		const match = projectionSource.match(
			/if\s*\(!reconciliationCandidate\)\s*\{[\s\S]{0,300}?\}\s*else\s*\{([\s\S]{0,900}?)\n\t\}/,
		);
		assert.ok(match, "could not find the `if (!reconciliationCandidate) { ... } else { ... }` block in currency-projection.js");
		assert.match(
			match[1],
			/setSwitcherLoading\(true\)[\s\S]*?syncTriggerToCode\(storedCurrencyAtLoad\)/,
			"the trigger must be synced to storedCurrencyAtLoad (the visitor's own choice) immediately when the loading state is applied — not left showing the stale cfg-baked value until reconcile() resolves",
		);
	});

	it("syncTriggerToCode() does not depend on currency-switcher.js having executed yet", () => {
		// Confirmed live: script tag order on this site prints
		// currency-projection.js before currency-switcher.js, both
		// synchronous — window.fchubMcSyncSwitcherDisplay is not reliably
		// defined at the point syncTriggerToCode() must run. It has to be
		// self-contained.
		const match = projectionSource.match(/function syncTriggerToCode\(code\) \{([\s\S]*?)\n\t\}/);
		assert.ok(match, "could not find syncTriggerToCode(...) in currency-projection.js");
		assert.doesNotMatch(
			match[1],
			/fchubMcSyncSwitcherDisplay/,
			"syncTriggerToCode() must not depend on window.fchubMcSyncSwitcherDisplay — that global isn't reliably defined this early given this site's actual script order",
		);
		assert.match(
			match[1],
			/data-fchub-mc-switcher\]["']\)/,
			"syncTriggerToCode() must locate the trigger itself via [data-fchub-mc-switcher], not rely on another script having already found it",
		);
	});

	it("reverts the optimistic sync if the server confirms the original page value was correct after all", () => {
		const match = projectionSource.match(
			/data\.display_currency === displayCode[\s\S]{0,1200}?return false;/,
		);
		assert.ok(match, "could not find the 'server agrees, no change' branch in currency-projection.js");
		assert.match(
			match[0],
			/data\.display_currency\s*!==\s*storedCurrencyAtLoad[\s\S]*?fchubMcSyncSwitcherDisplay/,
			"when the server confirms the page's original value was right all along, and that differs from the optimistic localStorage guess already shown, the trigger must be corrected back — the visitor's guess must never silently win over what the server actually says",
		);
	});
});

// ─── Source invariant: prices are left completely untouched during a ───
// ─── reconciliation — no hide, no dim, no transition of any kind ────────
//
// Confirmed directly by the site owner against how a normal (non-reconciling)
// page already behaves (issue #72). Two earlier approaches were both
// rejected on direct observation: hiding prices for the whole wait (reads as
// "finished" partway through a wait that just started) and dimming them
// (still a visible transition on prices themselves). Final spec: prices get
// no CSS treatment and no projection pass at all until reconcile() settles,
// at which point they go directly from untouched-old to converted-new in a
// single pass — exactly mirroring how .fchub-mc-projecting already behaves
// for a plain page with no reconciliation involved: one hide-then-reveal
// cycle, nothing more, no intermediate state a visitor can perceive.

describe("Source invariant: prices are untouched (no hide, no dim) during reconciliation", () => {
	it("does not add .fchub-mc-projecting when reconciliationCandidate is true — only for the plain path", () => {
		const match = projectionSource.match(
			/if\s*\(!reconciliationCandidate\)\s*\{([\s\S]{0,200}?)\}\s*else\s*\{/,
		);
		assert.ok(match, "could not find the `if (!reconciliationCandidate) { ... } else { ... }` FOUC-class block in currency-projection.js");
		assert.match(
			match[1],
			/classList\.add\(["']fchub-mc-projecting["']\)/,
			".fchub-mc-projecting must only be added for the plain (non-reconciliation) path — adding it unconditionally would hide prices for the whole reconciliation wait again",
		);
	});

	it("does not call projectPrices() (or any other price-touching class) in the reconciliation branch before reconcile() settles", () => {
		const match = projectionSource.match(/debugLog\("init_reconciliation_branch"\);([\s\S]{0,900}?)reconcile\(\)\.finally/);
		assert.ok(match, "could not find the reconciliation branch of init() in currency-projection.js, or something now runs between it and reconcile().finally");

		// Strip // comment lines first — the region's own explanatory comment
		// legitimately mentions "projectPrices()" and the class names in prose
		// (to say they DON'T happen here), which would otherwise false-positive
		// against a naive substring/regex check.
		const codeOnly = match[1]
			.split("\n")
			.filter((line) => !line.trim().startsWith("//"))
			.join("\n");

		assert.doesNotMatch(
			codeOnly,
			/projectPrices\(\)|classList\.add\(["']fchub-mc-(projecting|reconciling)["']\)/,
			"nothing must touch prices between entering the reconciliation branch and reconcile() settling — no projection pass, no hide class, no dim class. Prices must stay exactly as server-rendered until the atomic swap in finally()",
		);
	});

	it("projects prices for the first time only in reconcile()'s finally(), once it has actually settled", () => {
		const finallyMatch = projectionSource.match(/reconcile\(\)\.finally\(\(\) => \{([\s\S]{0,1000})/);
		assert.ok(finallyMatch, "could not find reconcile().finally(...) in currency-projection.js");
		assert.match(
			finallyMatch[1],
			/\bprojectPrices\(\)/,
			"finally() must call projectPrices() — this is the one and only point prices are touched during a reconciliation, converting them directly from whatever the server rendered to the final settled values",
		);
	});

	it("no .fchub-mc-reconciling class exists anywhere (JS or CSS) — the dimming approach was fully removed, not just reduced", () => {
		assert.doesNotMatch(
			projectionSource,
			/fchub-mc-reconciling/,
			"currency-projection.js must not reference .fchub-mc-reconciling — dimming was rejected outright, not just toned down",
		);
		assert.doesNotMatch(
			switcherCssSource,
			/fchub-mc-reconciling/,
			"currency-switcher.css must not define .fchub-mc-reconciling — dimming was rejected outright, not just toned down",
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

// ─── Source invariant: RECONCILABLE_SOURCES excludes url_param ────────────
//
// This is what keeps a currency switch's own reload (Part 2, issue #72
// follow-up) from ever being treated as a reconciliation candidate: once it
// lands with source url_param, the restored Part 1 loading UI and the
// second reconciliation fetch must both stay off. Reads the real array
// literal rather than trusting the hand-mirrored copy at the top of this
// file, which would only prove the copy still agrees with itself.

describe("Source invariant: RECONCILABLE_SOURCES excludes url_param and user_meta", () => {
	it("currency-projection.js's real RECONCILABLE_SOURCES array is exactly [\"cookie\", \"geo\", \"default\"]", () => {
		const match = projectionSource.match(/const RECONCILABLE_SOURCES = (\[[^\]]*\]);/);
		assert.ok(match, "could not find the RECONCILABLE_SOURCES array literal in currency-projection.js");

		const sources = JSON.parse(match[1].replace(/'/g, '"'));
		assert.deepEqual(
			sources,
			["cookie", "geo", "default"],
			"RECONCILABLE_SOURCES must stay exactly these three — url_param (an explicit link or this file's own switch-triggered reload) and user_meta (a signed-in visitor's saved preference) must never be second-guessed by a stale localStorage value",
		);
	});
});

// ─── Source invariant: switching currency navigates via a URL-param ───────
// ─── reload, not window.location.reload(), when the resolver is enabled ───
//
// Confirmed by reading ContextModule::buildResolverChain() (Priority 1) and
// switchCurrency() together — a currency switch used to always
// window.location.reload(), forcing the freshly loaded page to fall back on
// whatever the guest cookie resolved to (or a second reconciliation fetch if
// it didn't reach the server), where a signed-in visitor only ever paid one
// round-trip. Appending the switched-to currency as the URL param
// UrlParamResolver reads makes the reload itself resolve correctly,
// server-side, in the same single request.

describe("Source invariant: switchCurrency() reloads via a URL-param URL, not a bare reload()", () => {
	it("defines buildReloadUrl() using the URL API, not string concatenation", () => {
		const match = switcherSource.match(/function buildReloadUrl\(currencyCode\) \{([\s\S]*?)\n\t\}/);
		assert.ok(match, "could not find buildReloadUrl(...) in currency-switcher.js");
		assert.match(
			match[1],
			/new URL\(/,
			"buildReloadUrl() must use the URL API to parse the current location — string concatenation can't correctly handle both an absent query string and an already-present param from a previous switch or an incoming link",
		);
		assert.match(
			match[1],
			/searchParams\.set\(/,
			"buildReloadUrl() must use URLSearchParams.set() — set() overwrites a same-named param in place, where append() would duplicate it on a second switch",
		);
		assert.match(
			match[1],
			/if\s*\(!config\.urlParamEnabled\)\s*return null;/,
			"buildReloadUrl() must return null when urlParamEnabled is false, so callers fall back to a plain reload() instead of relying on a resolver that isn't in the chain",
		);
	});

	it("switchCurrency()'s success branch calls buildReloadUrl() and falls back to reload() only when it returns null", () => {
		const match = switcherSource.match(/persistToLocalStorage\(currencyCode\);([\s\S]{0,700}?)\n\t\t\t\}\)/);
		assert.ok(match, "could not find switchCurrency()'s success branch in currency-switcher.js");
		assert.match(
			match[1],
			/const reloadUrl = buildReloadUrl\(currencyCode\);/,
			"switchCurrency() must build the reload URL from the currency that was just confirmed persisted",
		);
		assert.match(
			match[1],
			/if\s*\(reloadUrl\)\s*\{\s*window\.location\.href\s*=\s*reloadUrl;\s*\}\s*else\s*\{\s*window\.location\.reload\(\);\s*\}/,
			"switchCurrency() must navigate to the URL-param reload URL when buildReloadUrl() returns one, and fall back to window.location.reload() only when it returns null (urlParamEnabled is false)",
		);
	});
});

// ─── Source invariant: the currency param is stripped back out of the ─────
// ─── address bar once a url_param-resolved page has settled ───────────────

describe("Source invariant: stripUrlParamFromAddressBar() only touches the address bar for a url_param resolution, via replaceState", () => {
	it("is gated on cfg.source === \"url_param\" and never navigates", () => {
		const match = projectionSource.match(/function stripUrlParamFromAddressBar\(\) \{([\s\S]*?)\n\t\}/);
		assert.ok(match, "could not find stripUrlParamFromAddressBar(...) in currency-projection.js");
		assert.match(
			match[1],
			/cfg\.source\s*!==\s*["']url_param["']/,
			'stripUrlParamFromAddressBar() must be gated on cfg.source === "url_param" — it must never rewrite the URL of a page that resolved via cookie, geo, default, or a signed-in visitor\'s saved preference',
		);
		assert.match(
			match[1],
			/window\.history\.replaceState\(/,
			"stripUrlParamFromAddressBar() must use history.replaceState() — the one API that changes the visible URL with no navigation and no flicker",
		);
		assert.doesNotMatch(
			match[1],
			/location\.href\s*=[^=]|location\.assign\(|location\.replace\(/,
			"stripUrlParamFromAddressBar() must never navigate (assigning location.href, or location.assign/replace) — that would defeat the entire point of a single-round-trip switch. Reading window.location.href (e.g. to build the URL) is fine; only navigating away is forbidden",
		);
	});

	it("is called from init()'s non-reconciliation branch — the only branch a url_param resolution can ever reach", () => {
		// Anchored on this exact debugLog call, unique to init()'s non-
		// reconciliation branch — the bare `if (!reconciliationCandidate)`
		// text also opens the unrelated top-level FOUC-class block earlier in
		// the file, which does not call stripUrlParamFromAddressBar() at all.
		const match = projectionSource.match(/debugLog\("init_no_reconciliation_branch"\);([\s\S]{0,200}?)\n\t\t\}/);
		assert.ok(match, "could not find init()'s non-reconciliation branch in currency-projection.js");
		assert.match(
			match[1],
			/stripUrlParamFromAddressBar\(\);/,
			"init()'s non-reconciliation branch must call stripUrlParamFromAddressBar() — a url_param resolution is never a reconciliation candidate (see RECONCILABLE_SOURCES above), so this is the only branch it ever reaches",
		);
	});
});
