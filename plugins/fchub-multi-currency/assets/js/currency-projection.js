/**
 * FCHub Multi-Currency — Price Projection
 *
 * Converts FluentCart storefront prices from base currency to the visitor's
 * selected display currency. Works by:
 *   1. Finding price elements via FluentCart's native CSS classes / data attributes
 *   2. Parsing the formatted base-currency amount (handles "From" prefixes, ranges)
 *   3. Converting via the exchange rate
 *   4. Re-rendering with the display currency symbol & formatting
 *
 * All converted prices are approximate (display-only). The customer is charged
 * in the store's base currency. Totals are prefixed with ≈ and a disclosure
 * notice is injected on checkout and cart drawer.
 *
 * Handles dynamic content (cart updates, checkout fragments) via MutationObserver
 * and FluentCart custom events.
 *
 * Also reconciles the page's baked-in currency context against a currency the
 * visitor previously saved to localStorage (see currency-switcher.js). This
 * covers hosts whose edge/WAF layer strips the guest currency cookie on request
 * paths it hasn't whitelisted (issue #72): the server then resolves the page to
 * the default currency even though the visitor picked something else. Since
 * REST paths on such hosts are typically exempted, reconciliation fetches
 * GET {restUrl}/context?currency=<code> — which resolves via the URL query
 * string, not the cookie — and re-projects with the corrected context.
 *
 * Fires: fchub_mc:prices_projected (after each projection pass),
 *        fchub_mc:context_reconciled (after a successful reconciliation)
 */
(() => {
	const cfg = window.fchubMcConfig || {};

	// TEMPORARY diagnostic logging for issue #72's environment-discrepancy
	// investigation (production shows a 3-phase sequence — correct, then wrong,
	// then correct again — staging shows only 2 phases with the same deployed
	// code). Writes to sessionStorage, which — unlike an in-memory variable —
	// survives the location.reload() this same flow triggers, so the real
	// switch → reload → reconcile sequence can be read back afterward with
	// accurate timestamps, instead of depending on polling fast enough to
	// catch it live. Not meant to ship long-term — remove this and the
	// matching block in currency-switcher.js once the discrepancy is understood.
	const DEBUG_LOG_KEY = "fchub_mc_debug_trace";
	function debugLog(event, extra) {
		try {
			const raw = window.sessionStorage.getItem(DEBUG_LOG_KEY);
			const trace = raw ? JSON.parse(raw) : [];
			trace.push(Object.assign({ event, t: Date.now() }, extra || {}));
			window.sessionStorage.setItem(DEBUG_LOG_KEY, JSON.stringify(trace));
		} catch {
			// ignore — diagnostic only
		}
	}

	// Mirrors the cookie name (Constants::COOKIE_KEY) — see currency-switcher.js,
	// which writes this after a successful guest switch.
	const STORAGE_KEY = "fchub_mc_currency";

	// Source values ContextModule's resolver chain never places below a cookie:
	// an explicit ?currency= link (url_param) or a signed-in visitor's saved
	// account preference (user_meta). A localStorage value must never
	// second-guess either — only the sources a stripped cookie could have
	// caused the page to fall through to.
	const RECONCILABLE_SOURCES = ["cookie", "geo", "default"];

	// Worst-case wait for reconcile()'s fetch before giving up and leaving the
	// page on whatever currency it was served with. This is inherently a guess
	// about hosts we don't control, so it's sized off a real measurement rather
	// than the happy path: confirmed live on a Rocket.net staging tier (issue
	// #72) that this endpoint can take several seconds under ordinary page-load
	// contention — a concurrent, unrelated admin-ajax.php call on the same page
	// load alone took 5.4s fighting for the same PHP-FPM worker pool, and this
	// endpoint's own fetch was still in flight past the previous 4s bound when
	// it was aborted. 12s clears that observed worst case with real headroom for
	// queuing to compound further under heavier load, while staying short enough
	// that a visitor isn't waiting a long time for a client-side correction to a
	// cached page. Note this bounds the network wait only — this file leaves
	// the switcher trigger and prices alike completely untouched throughout
	// this whole duration, exactly as a logged-in visitor's page load already
	// does, until reconcile() actually settles; see the reconciliation branch
	// of init() below.
	const RECONCILE_TIMEOUT_MS = 12000;

	// Mutable projection parameters — reassigned in place by reconcile() below if
	// it finds the cached/baked-in context stale. Every function in this file
	// that formats or converts a price closes over these bindings by reference,
	// so reassigning them here is enough to make a later re-projection pass use
	// the corrected values without threading state through every function.
	let rate = parseFloat(cfg.rate || "1");
	let decimals = Math.max(0, Math.min(20, parseInt(cfg.decimals, 10) || 2));
	let symbol = cfg.symbol || cfg.displayCurrency;
	let position = cfg.position || "left";
	let displayCode = cfg.displayCurrency;
	let isBaseDisplay = !!cfg.isBaseDisplay;
	let displayDecSep = cfg.displayDecSep || ".";
	let displayThousandSep = cfg.displayThousandSep || ",";

	// Site-wide settings — unaffected by which currency ends up resolved.
	const roundingMode = cfg.roundingMode || "half_up";
	const baseCode = cfg.baseCurrencyCode || cfg.baseCurrency;

	// Base currency parsing config — describes how prices are already written in
	// the server-rendered HTML, so this never changes on reconciliation either.
	const baseSign = cfg.baseCurrencySign || "$";
	const baseDecSep = cfg.baseDecimalSep || ".";
	const baseThousandSep = cfg.baseThousandSep || ",";

	/**
	 * Whether the current (possibly reconciled) state calls for price conversion.
	 */
	function needsProjection() {
		return !!(
			cfg.baseCurrency &&
			displayCode &&
			!isBaseDisplay &&
			displayCode !== cfg.baseCurrency &&
			rate &&
			Number.isFinite(rate) &&
			rate !== 1
		);
	}

	/**
	 * Mirrors AllowedCurrencyCheck::isAllowedCurrency() server-side: a value read
	 * back from localStorage is untrusted input until checked against the base
	 * currency and the enabled display currencies baked into this page load.
	 */
	function isAllowedCurrencyCode(code) {
		if (!code) return false;
		if (code === (cfg.baseCurrency || "").toUpperCase()) return true;
		const currencies = Array.isArray(cfg.currencies) ? cfg.currencies : [];
		return currencies.some(
			(currency) => currency && typeof currency === "object" && (currency.code || "").toUpperCase() === code,
		);
	}

	function readStoredCurrency() {
		if (!cfg.guestLocalStorageEnabled) return null;
		try {
			const stored = window.localStorage.getItem(STORAGE_KEY);
			if (!stored) return null;
			const code = stored.toUpperCase();
			return isAllowedCurrencyCode(code) ? code : null;
		} catch {
			return null;
		}
	}

	// Computed once up front, before the bail-out below, so a guest whose saved
	// currency disagrees with this (possibly stale) page load isn't skipped by
	// the "nothing to project" fast path.
	const storedCurrencyAtLoad = readStoredCurrency();
	const reconciliationCandidate =
		!!storedCurrencyAtLoad &&
		storedCurrencyAtLoad !== cfg.displayCurrency &&
		RECONCILABLE_SOURCES.indexOf(cfg.source || "") !== -1;

	debugLog("script_start", {
		cfgDisplayCurrency: cfg.displayCurrency,
		cfgSource: cfg.source,
		cfgIsBaseDisplay: cfg.isBaseDisplay,
		cfgGuestLocalStorageEnabled: cfg.guestLocalStorageEnabled,
		rawLocalStorageValue: (() => {
			try {
				return window.localStorage.getItem(STORAGE_KEY);
			} catch {
				return null;
			}
		})(),
		storedCurrencyAtLoad,
		reconciliationCandidate,
		triggerCodeAtScriptStart: document.querySelector(".fchub-mc-switcher__code")?.textContent ?? null,
		// The fchub_mc_currency cookie is httpOnly (PreferenceRepository::saveCookie),
		// so its value is never visible here — this only shows what else (if
		// anything) document.cookie exposes.
		visibleCookies: document.cookie,
	});

	// Bail out entirely if there's nothing to do — no conversion needed on the
	// page as served, and no reason to suspect that's wrong. Preserves the
	// original zero-cost fast path for genuinely single-currency stores.
	if (!needsProjection() && !reconciliationCandidate) {
		debugLog("bailed_out_no_projection_no_reconciliation");
		return;
	}

	// Flag to suppress MutationObserver during our own DOM changes
	let projecting = false;

	// Selectors for FluentCart price elements
	const PRICE_SELECTORS = [
		// Product cards (shop page / grid)
		".fct-item-price",
		".fct-compare-price",
		".fct-min-price",
		".fct-max-price",
		// Single product variant prices
		".fct-product-item-price",
		// Cart drawer
		"[data-fluent-cart-cart-list-item-price]",
		"[data-fluent-cart-cart-list-item-total-price]",
		"[data-fluent-cart-cart-total-price]",
		// Cart page
		"[data-fluent-cart-cart-total]",
		".fct-cart-item-price",
		".fct-cart-item-total",
		// Checkout summary
		".fct_summary_value",
		".fct_line_item_price",
		".fct_line_item_total",
		".fct_promo_price",
		".fct_item_payment_info",
		"[data-fluent-cart-checkout-estimated-total]",
		"[data-fluent-cart-checkout-subtotal]",
		".shipping-method-amount",
		// Pricing table
		".fluent-cart-pricing-table-variant-price",
		".fluent-cart-pricing-table-variant-compare-price",
		// Modal checkout
		".fct-modal-cs-line-price",
		// Coupon discount
		".fct_coupon_price",
		".fct-coupon-price",
		// Thank you / receipt page
		".fct-thank-you-page-order-items-total-value",
		".fct-thank-you-page-order-items-list-price-inner",
		".fct-thank-you-page-order-items-list-payment-info",
		// Explicit opt-in elements
		"[data-fchub-mc-base]",
	].join(",");

	// Elements that represent totals — these get the ≈ prefix
	const TOTAL_SELECTORS = [
		"[data-fluent-cart-cart-total-price]",
		"[data-fluent-cart-cart-total]",
		"[data-fluent-cart-checkout-estimated-total]",
	].join(",");

	// Selector for the price filter currency sign (shop sidebar)
	const CURRENCY_SIGN_SELECTOR = ".fct-shop-currency-sign";

	// Build regex to strip the base currency sign (escaped for regex)
	const escSign = baseSign.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
	const escCode = baseCode.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
	const stripRegex = new RegExp(`(${escSign}|${escCode})`, "g");

	// Regex to match a formatted base-currency price within a larger string.
	// Captures the full price token (sign/code + digits + separators + decimals)
	// so we can replace just that portion while preserving surrounding text.
	const escThousandSep = baseThousandSep
		? baseThousandSep.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")
		: "";
	const escDecSep = baseDecSep.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
	const basePriceRegex = new RegExp(
		`(?:${escSign}|${escCode})?\\s*\\d[\\d${escThousandSep}]*(?:${escDecSep}\\d+)?\\s*(?:${escSign}|${escCode})?`,
	);

	const ATTR_PROJECTED = "data-fchub-mc-projected";
	const ATTR_BASE = "data-fchub-mc-base";
	const ATTR_ORIGINAL = "data-fchub-mc-original";
	const ATTR_PREFIX = "data-fchub-mc-prefix";

	const DISCLOSURE_CLASS = "fchub-mc-disclosure";
	const DISCLOSURE_ATTR = "data-fchub-mc-disclosure";

	/**
	 * Check if an element matches a total selector (gets ≈ prefix).
	 */
	function isTotal(el) {
		try {
			return el.matches(TOTAL_SELECTORS);
		} catch {
			return false;
		}
	}

	/**
	 * Extract a text prefix (e.g. "From ", "Starting at ") from a price string.
	 * Returns { prefix: string, priceText: string }.
	 */
	function extractPrefix(text) {
		const match = text.match(/^([^\d]*?\s+)(\S*\d.*)$/);
		if (match) {
			const stripped = match[1].replace(stripRegex, "").trim();
			if (stripped.length > 0) {
				return { prefix: stripped + " ", priceText: match[2] };
			}
		}
		return { prefix: "", priceText: text };
	}

	/**
	 * Check if a price string is a range (e.g. "11.00zł – 12.30zł").
	 */
	function parseRange(text) {
		const match = text.match(/^(.+?)\s*([–—\-~])\s*(.+)$/);
		if (match && looksLikePrice(match[1]) && looksLikePrice(match[3])) {
			return {
				low: match[1].trim(),
				high: match[3].trim(),
				separator: match[2],
			};
		}
		return null;
	}

	/**
	 * Parse a formatted base-currency string into a float.
	 */
	function parseBasePrice(text) {
		if (!text) return NaN;

		let cleaned = text.trim();
		cleaned = cleaned.replace(stripRegex, "").trim();
		cleaned = cleaned.replace(/\u00a0/g, " ").trim();
		cleaned = cleaned.replace(/\s/g, "");

		if (!cleaned) return NaN;

		if (baseDecSep === ",") {
			cleaned = cleaned.replace(/\./g, "").replace(",", ".");
		} else {
			cleaned = cleaned.replace(/,/g, "");
		}

		const value = parseFloat(cleaned);
		return Number.isNaN(value) ? NaN : value;
	}

	/**
	 * Apply rounding based on configured mode.
	 */
	function applyRounding(amount) {
		const factor = 10 ** decimals;
		const scaled = amount * factor;

		switch (roundingMode) {
			case "ceil":
				return Math.ceil(scaled) / factor;
			case "floor":
				return Math.floor(scaled) / factor;
			case "half_down": {
				const floored = Math.floor(scaled);
				return ((scaled - floored) > 0.5 ? Math.ceil(scaled) : floored) / factor;
			}
			case "none": {
				const truncated = Math.trunc(scaled);
				return truncated / factor;
			}
			default:
				return Math.round(scaled) / factor;
		}
	}

	/**
	 * Format a number with thousand separators.
	 */
	function formatNumber(amount) {
		const fixed = amount.toFixed(decimals);
		const parts = fixed.split(".");
		let intPart = parts[0];
		const decPart = parts[1] || "";

		if (displayThousandSep) {
			intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, displayThousandSep);
		}

		if (decimals === 0) {
			return intPart;
		}

		return intPart + displayDecSep + decPart;
	}

	/**
	 * Format a converted amount with the display currency symbol.
	 */
	function formatPrice(amount) {
		const num = formatNumber(amount);

		switch (position) {
			case "left":
				return symbol + num;
			case "right":
				return num + symbol;
			case "left_space":
				return `${symbol} ${num}`;
			case "right_space":
				return `${num} ${symbol}`;
			default:
				return symbol + num;
		}
	}

	/**
	 * Check if text contains at least one digit.
	 */
	function looksLikePrice(text) {
		return /\d/.test(text);
	}

	/**
	 * Check if an element has mixed content (child elements like <sup>,
	 * <span class="repeat-interval">) that would be destroyed by setting
	 * textContent on the parent.
	 */
	function hasMixedContent(el) {
		if (el.querySelector("sup")) return true;
		if (el.querySelector("span.repeat-interval")) return true;
		return false;
	}

	/**
	 * Find the deepest element that contains the price text.
	 * For elements with mixed content (e.g. <sup>$</sup>12.00<span>...),
	 * returns a { textNode, mixed: true } wrapper so the caller can modify
	 * only the text node instead of clobbering innerHTML.
	 */
	function findPriceTarget(el) {
		const spans = el.querySelectorAll("span[aria-hidden]");
		if (spans.length === 1 && looksLikePrice(spans[0].textContent)) {
			return spans[0];
		}
		const dels = el.querySelectorAll("del");
		if (dels.length === 1 && looksLikePrice(dels[0].textContent)) {
			return dels[0];
		}

		// Drill into styled child spans that FluentCart uses for price formatting
		// (e.g. .fct_line_item_total inside .fct_line_item_price). Setting textContent
		// on the parent would destroy these inner elements and lose their styling.
		const styledChild = el.querySelector(".fct_line_item_total, .fct_summary_value, .fct_coupon_price");
		if (styledChild && looksLikePrice(styledChild.textContent)) {
			return styledChild;
		}

		// If the element has child markup we'd destroy with textContent,
		// find the bare text node that holds the numeric price
		if (hasMixedContent(el)) {
			for (const node of el.childNodes) {
				if (node.nodeType === 3 && looksLikePrice(node.textContent)) {
					return { textNode: node, mixed: true };
				}
			}
		}

		return el;
	}

	/**
	 * Replace only the price portion in a text string, preserving any suffix
	 * like "per month, until cancel". Returns the modified string, or null
	 * if no price was found.
	 */
	function replaceInlinePrice(text) {
		const match = text.match(basePriceRegex);
		if (!match) return null;

		const baseAmount = parseBasePrice(match[0]);
		if (Number.isNaN(baseAmount)) return null;

		const converted = formatPrice(applyRounding(baseAmount * rate));

		// Preserve whitespace that the regex captured around the price
		const leading = match[0].match(/^\s*/)[0];
		const trailing = match[0].match(/\s*$/)[0];
		return text.replace(match[0], leading + converted + trailing);
	}

	/**
	 * Replace ALL base-currency prices in a text string (single pass).
	 * Handles text with multiple prices like "300.00zł per year + 100.00zł setup fee".
	 * Skips bare numbers (e.g. "12" in "12 cycles") that aren't prices.
	 */
	function replaceAllInlinePrices(text) {
		const globalRegex = new RegExp(basePriceRegex.source, "g");
		let changed = false;
		const result = text.replace(globalRegex, (match) => {
			// Skip bare numbers without currency indicator or decimal portion
			// to avoid converting "12" in "for 12 cycles"
			stripRegex.lastIndex = 0;
			if (!stripRegex.test(match) && !/\d[.,]\d/.test(match.trim())) {
				return match;
			}

			const baseAmount = parseBasePrice(match);
			if (Number.isNaN(baseAmount)) return match;

			const converted = formatPrice(applyRounding(baseAmount * rate));
			const leading = match.match(/^\s*/)[0];
			const trailing = match.match(/\s*$/)[0];
			changed = true;
			return leading + converted + trailing;
		});
		return changed ? result : null;
	}

	/**
	 * Project a single variant price container (.fct-product-item-price).
	 */
	function projectVariantPrice(el) {
		if (el.getAttribute(ATTR_PROJECTED)) return 0;

		let count = 0;

		if (!el.getAttribute(ATTR_ORIGINAL)) {
			el.setAttribute(ATTR_ORIGINAL, el.innerHTML);
		}

		// clearProjectionMarkers restores innerHTML from the initial render, which may
		// contain stale is-hidden classes. Sync child variation-content visibility with
		// the parent's current state so FluentCart's tab toggling isn't undone.
		const parentHidden = el.classList.contains("is-hidden");
		for (const child of el.querySelectorAll(".fluent-cart-product-variation-content")) {
			child.classList.toggle("is-hidden", parentHidden);
		}

		const del = el.querySelector("del");
		if (del && looksLikePrice(del.textContent)) {
			const compareAmount = parseBasePrice(del.textContent.trim());
			if (!Number.isNaN(compareAmount)) {
				del.textContent = formatPrice(applyRounding(compareAmount * rate));
				count++;
			}
		}

		const childNodes = el.childNodes;
		for (let j = 0; j < childNodes.length; j++) {
			const node = childNodes[j];
			if (node.nodeType === 3 && looksLikePrice(node.textContent)) {
				const converted = replaceInlinePrice(node.textContent);
				if (converted !== null) {
					node.textContent = converted;
					count++;
				}
			}
		}

		// Subscription/installment products wrap price text in a child
		// .fct-product-payment-type div. Text nodes there can contain multiple prices
		// (e.g. "300.00zł per year for 12 cycles + 100.00zł one-time setup fee").
		const paymentType = el.querySelector(".fct-product-payment-type");
		if (paymentType) {
			for (const node of paymentType.childNodes) {
				if (node.nodeType === 3 && looksLikePrice(node.textContent)) {
					const converted = replaceAllInlinePrices(node.textContent);
					if (converted !== null) {
						node.textContent = converted;
						count++;
					}
				}
			}
		}

		if (count > 0) {
			el.setAttribute(ATTR_PROJECTED, "1");
			// Mark child compare-price elements as projected to prevent double-conversion
			const childCompare = el.querySelectorAll(".fct-compare-price");
			for (const cp of childCompare) {
				cp.setAttribute(ATTR_PROJECTED, "1");
			}
		}
		return count;
	}

	/**
	 * Project a price range (e.g. "11.00zł – 12.30zł" → "€2.57 – €2.87").
	 */
	function projectRange(text) {
		const extracted = extractPrefix(text);
		const range = parseRange(extracted.priceText);
		if (!range) return null;

		const lowAmount = parseBasePrice(range.low);
		const highAmount = parseBasePrice(range.high);
		if (Number.isNaN(lowAmount) || Number.isNaN(highAmount)) return null;

		const convertedLow = applyRounding(lowAmount * rate);
		const convertedHigh = applyRounding(highAmount * rate);

		return (
			(extracted.prefix || "") +
			formatPrice(convertedLow) +
			` ${range.separator} ` +
			formatPrice(convertedHigh)
		);
	}

	/**
	 * Inject a disclosure notice about approximate pricing.
	 * Placed after checkout order summary and inside cart drawer.
	 */
	function injectDisclosures() {
		// Cleared and re-injected on every call (cheap — a handful of small divs)
		// rather than skipped-if-present, so a reconciled context (which may change
		// disclosureEnabled/disclosureText, since the template can embed the display
		// currency and rate — see CheckoutDisclosureService) doesn't leave stale text.
		document.querySelectorAll(`[${DISCLOSURE_ATTR}]`).forEach((el) => el.remove());

		if (cfg.disclosureEnabled === false) return;
		const text =
			cfg.disclosureText ||
			`Prices shown in ${displayCode} are approximate. You will be charged in ${baseCode}.`;

		const makeNotice = (extraClass) => {
			const el = document.createElement("div");
			el.className = DISCLOSURE_CLASS + (extraClass ? ` ${extraClass}` : "");
			el.setAttribute(DISCLOSURE_ATTR, "1");
			el.textContent = text;
			return el;
		};

		const injectAfter = (anchor, extraClass) => {
			if (!anchor || !anchor.parentNode) return;
			try {
				anchor.insertAdjacentElement("afterend", makeNotice(extraClass));
			} catch {
				// DOM race with FluentCart's reactive rendering — safe to ignore
			}
		};

		// Checkout: after the summary box (between order summary and "Leave a Note")
		const summaryBox = document.querySelector(".fct_summary_box");
		if (summaryBox) {
			injectAfter(summaryBox, "");
		}

		// Cart drawer: after the total wrapper, before the checkout button
		const drawerTotalWrapper = document.querySelector(
			".fct-cart-drawer-footer [data-fluent-cart-cart-total-wrapper]",
		);
		injectAfter(drawerTotalWrapper, "fchub-mc-disclosure--drawer");

		// Cart page: after the cart total wrapper (skip if we already injected for drawer)
		const cartTotalWrapper = document.querySelector("[data-fluent-cart-cart-total-wrapper]");
		if (cartTotalWrapper && cartTotalWrapper !== drawerTotalWrapper) {
			injectAfter(cartTotalWrapper, "");
		}
	}

	/**
	 * Project a single standard price element (not variant, not range).
	 * Returns 1 if projected, 0 otherwise.
	 */
	function projectSinglePrice(el) {
		const target = findPriceTarget(el);

		// Mixed content (e.g. <sup>$</sup>12.00<span class="repeat-interval">...)
		// Modify only the text node containing the price, preserving sibling markup.
		if (target.mixed) {
			const node = target.textNode;
			if (!el.getAttribute(ATTR_ORIGINAL)) {
				el.setAttribute(ATTR_ORIGINAL, el.innerHTML);
			}
			const converted = replaceInlinePrice(node.textContent);
			if (converted === null) return 0;

			// Replace <sup> currency sign with display currency sign
			const sup = el.querySelector("sup");
			if (sup) {
				sup.textContent = symbol;
			}

			node.textContent = converted;
			el.setAttribute(ATTR_PROJECTED, "1");
			return 1;
		}

		const rawText = target.textContent;
		if (!looksLikePrice(rawText)) return 0;

		// For elements with subscription text (e.g. ".fct_item_payment_info"),
		// replace only the price portion, preserving suffix like "per month, until cancel"
		if (el.classList.contains("fct_item_payment_info")) {
			if (!el.getAttribute(ATTR_ORIGINAL)) {
				el.setAttribute(ATTR_ORIGINAL, el.innerHTML);
			}
			const converted = replaceInlinePrice(rawText);
			if (converted === null) return 0;
			target.textContent = converted;
			el.setAttribute(ATTR_PROJECTED, "1");
			return 1;
		}

		// Thank you page payment info — may contain multiple inline prices
		if (el.classList.contains("fct-thank-you-page-order-items-list-payment-info")) {
			if (!el.getAttribute(ATTR_ORIGINAL)) {
				el.setAttribute(ATTR_ORIGINAL, el.innerHTML);
			}
			const converted = replaceAllInlinePrices(rawText);
			if (converted === null) return 0;
			target.textContent = converted;
			el.setAttribute(ATTR_PROJECTED, "1");
			return 1;
		}

		let baseAmount;
		let prefix = "";

		const explicitBase = el.getAttribute(ATTR_BASE);
		if (explicitBase) {
			baseAmount = parseFloat(explicitBase);
		} else {
			const rangeResult = projectRange(rawText.trim());
			if (rangeResult) {
				if (!el.getAttribute(ATTR_ORIGINAL)) {
					el.setAttribute(ATTR_ORIGINAL, el.innerHTML);
				}
				target.textContent = rangeResult;
				el.setAttribute(ATTR_PROJECTED, "range");
				return 1;
			}

			const extracted = extractPrefix(rawText.trim());
			prefix = extracted.prefix;
			baseAmount = parseBasePrice(extracted.priceText);
		}

		if (Number.isNaN(baseAmount)) return 0;

		if (!el.getAttribute(ATTR_ORIGINAL)) {
			el.setAttribute(ATTR_ORIGINAL, el.innerHTML);
		}

		const converted = applyRounding(baseAmount * rate);
		const formattedPrice = formatPrice(converted);
		const approxPrefix = isTotal(el) ? "\u2248 " : "";

		if (prefix) {
			target.textContent = prefix + approxPrefix + formattedPrice;
			el.setAttribute(ATTR_PREFIX, prefix);
		} else {
			target.textContent = approxPrefix + formattedPrice;
		}

		el.setAttribute(ATTR_PROJECTED, converted.toString());

		// When the target is a styled child element (e.g. .fct_line_item_total
		// inside .fct_line_item_price), mark it as projected too so the selector
		// loop doesn't re-process it and attempt a double-conversion.
		if (target !== el && target.setAttribute) {
			target.setAttribute(ATTR_PROJECTED, "1");
		}

		return 1;
	}

	/**
	 * Project currency signs in the shop price filter sidebar.
	 */
	function projectCurrencySigns(root) {
		for (const sign of root.querySelectorAll(CURRENCY_SIGN_SELECTOR)) {
			if (sign.getAttribute(ATTR_PROJECTED)) continue;
			sign.setAttribute(ATTR_ORIGINAL, sign.textContent);
			sign.textContent = symbol;
			sign.setAttribute(ATTR_PROJECTED, "1");
		}
	}

	/**
	 * Project variant button data-item-price / data-compare-price attributes.
	 */
	function projectVariantButtons(root) {
		const buttons = root.querySelectorAll("[data-fluent-cart-product-variant][data-item-price]");
		for (const btn of buttons) {
			if (btn.getAttribute(ATTR_PROJECTED)) continue;

			const itemPrice = parseBasePrice(btn.getAttribute("data-item-price"));
			if (!Number.isNaN(itemPrice)) {
				btn.setAttribute("data-item-price", formatPrice(applyRounding(itemPrice * rate)));
			}

			const comparePrice = btn.getAttribute("data-compare-price");
			if (comparePrice) {
				const cp = parseBasePrice(comparePrice);
				if (!Number.isNaN(cp)) {
					btn.setAttribute("data-compare-price", formatPrice(applyRounding(cp * rate)));
				}
			}

			// Convert visible price text in variant button spans
			const priceSpan = btn.querySelector(".fct-product-variant-item-price span");
			if (priceSpan && looksLikePrice(priceSpan.textContent)) {
				const amount = parseBasePrice(priceSpan.textContent);
				if (!Number.isNaN(amount)) {
					priceSpan.textContent = formatPrice(applyRounding(amount * rate));
				}
			}

			const compareSpan = btn.querySelector(".fct-product-variant-compare-price span");
			if (compareSpan && looksLikePrice(compareSpan.textContent)) {
				const amount = parseBasePrice(compareSpan.textContent);
				if (!Number.isNaN(amount)) {
					compareSpan.textContent = formatPrice(applyRounding(amount * rate));
				}
			}

			btn.setAttribute(ATTR_PROJECTED, "1");
		}
	}

	/**
	 * Project price filter input values in the shop sidebar.
	 */
	function projectPriceFilterInputs(root) {
		for (const input of root.querySelectorAll(".fc_price_range_input")) {
			// Store original base-currency value on first encounter
			if (!input.getAttribute(ATTR_BASE)) {
				input.setAttribute(ATTR_BASE, input.value);
			}

			const baseVal = parseFloat(input.getAttribute(ATTR_BASE));
			if (Number.isNaN(baseVal)) continue;

			const converted = applyRounding(baseVal * rate);
			input.value = formatNumber(converted);
		}
	}

	/**
	 * Project pricing table payment type labels (sibling of the price wrap).
	 * Contains spans with inline prices like "300.00zł per year for 12 cycles".
	 */
	function projectPricingTablePaymentTypes(root) {
		const elements = root.querySelectorAll(
			".fluent-cart-pricing-table-variant-payment-type",
		);
		for (const el of elements) {
			if (el.getAttribute(ATTR_PROJECTED)) continue;

			if (!el.getAttribute(ATTR_ORIGINAL)) {
				el.setAttribute(ATTR_ORIGINAL, el.innerHTML);
			}

			let count = 0;
			for (const span of el.querySelectorAll("span")) {
				const converted = replaceAllInlinePrices(span.textContent);
				if (converted !== null) {
					span.textContent = converted;
					count++;
				}
			}

			if (count > 0) {
				el.setAttribute(ATTR_PROJECTED, "1");
			}
		}
	}

	/**
	 * Main projection: find all price elements and convert them.
	 */
	function projectPrices(root) {
		if (!needsProjection()) {
			// Reached when reconciliation (see below) concluded the visitor is
			// actually in the base currency after all. Any previously-projected
			// elements were already restored by the clearProjectionMarkers() call
			// that precedes reconciliation's re-projection, so there's nothing to
			// convert here — just clean up anything currency-dependent and reveal
			// the page.
			injectDisclosures();
			document.documentElement.classList.remove("fchub-mc-projecting");
			return 0;
		}

		root = root || document;
		projecting = true;

		let projected = 0;

		for (const el of root.querySelectorAll(PRICE_SELECTORS)) {
			if (el.getAttribute(ATTR_PROJECTED)) continue;

			if (el.classList.contains("fct-product-item-price")) {
				projected += projectVariantPrice(el);
			} else {
				projected += projectSinglePrice(el);
			}
		}

		projectCurrencySigns(root);
		projectVariantButtons(root);
		projectPriceFilterInputs(root);
		projectPricingTablePaymentTypes(root);

		if (projected > 0) {
			document.dispatchEvent(
				new CustomEvent("fchub_mc:prices_projected", {
					detail: { rate, currency: displayCode, count: projected },
				}),
			);
		}

		injectDisclosures();
		document.documentElement.classList.remove("fchub-mc-projecting");

		setTimeout(() => {
			projecting = false;
		}, 0);
	}

	/**
	 * Restore original HTML and clear projection markers.
	 */
	function clearProjectionMarkers(root) {
		root = root || document;
		const elements = root.querySelectorAll(`[${ATTR_PROJECTED}]`);
		for (const el of elements) {
			const original = el.getAttribute(ATTR_ORIGINAL);
			if (original) {
				el.innerHTML = original;
			}
			el.removeAttribute(ATTR_PROJECTED);
			el.removeAttribute(ATTR_ORIGINAL);
			el.removeAttribute(ATTR_PREFIX);
		}
	}

	/**
	 * Set up a MutationObserver to re-project when FluentCart
	 * dynamically updates price elements (cart drawer, AJAX, etc.).
	 */
	function observeDynamicUpdates() {
		if (typeof MutationObserver === "undefined") return;

		let debounceTimer;

		const observer = new MutationObserver((mutations) => {
			if (projecting) return;

			let needsReproject = false;

			for (const m of mutations) {
				if (
					m.type === "attributes" &&
					m.attributeName &&
					m.attributeName.indexOf("data-fchub-mc") === 0
				) {
					continue;
				}
				if (m.addedNodes.length > 0 || m.type === "characterData") {
					needsReproject = true;
					break;
				}
			}

			if (needsReproject) {
				clearTimeout(debounceTimer);
				debounceTimer = setTimeout(() => {
					clearProjectionMarkers();
					projectPrices();
				}, 50);
			}
		});

		observer.observe(document.body, {
			childList: true,
			subtree: true,
			characterData: true,
		});
	}

	/**
	 * Listen for FluentCart's custom events that signal content updates.
	 *
	 * FluentCart dispatches all its custom events on `window` (not `document`),
	 * and custom events do NOT bubble from window to document.
	 */
	function listenForFluentCartEvents() {
		let eventDebounceTimer;

		const reproject = (delay) => {
			clearTimeout(eventDebounceTimer);
			eventDebounceTimer = setTimeout(() => {
				clearProjectionMarkers();
				projectPrices();
			}, delay);
		};

		const windowEvents = [
			"fluentCartFragmentsReplaced",
			"fluentCartNotifySummaryViewUpdated",
			"fluentCartNotifyCartDrawerItemChanged",
			"fluentCartCheckoutDataChanged",
		];

		for (const eventName of windowEvents) {
			window.addEventListener(eventName, () => reproject(100));
		}

		const delayedWindowEvents = [
			"fluentCartSingleProductModalOpened",
			"fluentCartSingleProductVariationChanged",
		];

		for (const eventName of delayedWindowEvents) {
			window.addEventListener(eventName, () => reproject(200));
		}

		window.addEventListener("fchub_mc:context_changed", () => reproject(100));
	}

	/**
	 * Re-resolves the currency context via the REST API for the localStorage
	 * currency found at load, and applies it in place if the server actually
	 * agrees it differs from what the page was served with. A network failure,
	 * timeout, or a server response that turns out to match the page already
	 * leaves the mutable projection state untouched.
	 *
	 * Bounded to RECONCILE_TIMEOUT_MS so a slow/hung request can't leave the
	 * page hidden behind the FOUC-prevention class indefinitely.
	 *
	 * cache: "no-store" is load-bearing, not a nicety — confirmed live on issue
	 * #72 (Rocket.net staging): without it, once a given ?currency=X URL had
	 * been fetched once in a browser profile, the browser silently served that
	 * same response from its own HTTP cache on every later reconciliation
	 * attempt instead of re-checking the server, so the page kept settling back
	 * on the stale currency even though the endpoint itself was resolving
	 * correctly. The server sends Cache-Control: no-store too (see
	 * ContextController::noStore()), but a response already sitting in the
	 * browser's cache from before that shipped would still be reused without
	 * this — the request has to actually ask.
	 */
	function reconcile() {
		const restUrl = cfg.restUrl || "/wp-json/fchub-mc/v1";
		const hasAbortController = typeof AbortController !== "undefined";
		const controller = hasAbortController ? new AbortController() : null;
		const timeoutId = hasAbortController ? setTimeout(() => controller.abort(), RECONCILE_TIMEOUT_MS) : null;

		return fetch(`${restUrl}/context?currency=${encodeURIComponent(storedCurrencyAtLoad)}`, {
			cache: "no-store",
			headers: { Accept: "application/json" },
			signal: controller ? controller.signal : undefined,
		})
			.then((response) => {
				debugLog("reconcile_response_received", { status: response.status, ok: response.ok });
				return response.ok ? response.json() : null;
			})
			.then((payload) => {
				const data = payload && typeof payload === "object" ? payload.data : null;
				if (!data || !data.display_currency) {
					debugLog("reconcile_no_usable_data");
					return false;
				}

				// The server may itself decline the stored value (e.g. it was removed
				// from the currency list since it was saved) and resolve to whatever
				// the rest of the chain lands on — only apply it as a price-projection
				// change if it's actually different from what's already on the page.
				if (data.display_currency === displayCode && !!data.is_base_display === isBaseDisplay) {
					debugLog("reconcile_server_agrees_no_change", {
						serverDisplayCurrency: data.display_currency,
						serverSource: data.source,
					});

					// The server just confirmed the page's original server-rendered
					// value was already correct. The trigger was never touched by
					// anything on this pass — it's showing that same value already —
					// so there's nothing to correct. No price-projection state
					// changes here either, since displayCode/rate/etc. were already
					// right.
					return false;
				}

				debugLog("reconcile_applying_change", {
					fromDisplayCode: displayCode,
					toDisplayCode: data.display_currency,
					serverSource: data.source,
					triggerCodeBeforeApply: document.querySelector(".fchub-mc-switcher__code")?.textContent ?? null,
				});

				rate = parseFloat(data.rate || "1");
				decimals = Math.max(0, Math.min(20, parseInt(data.decimals, 10) || 2));
				symbol = data.symbol || data.display_currency;
				position = data.position || "left";
				displayCode = data.display_currency;
				isBaseDisplay = !!data.is_base_display;
				displayDecSep = data.display_decimal_separator || ".";
				displayThousandSep = data.display_thousand_separator || ",";
				cfg.disclosureEnabled = !!data.disclosure_enabled;
				cfg.disclosureText = data.disclosure_text || null;

				clearProjectionMarkers();

				if (typeof window.fchubMcSyncSwitcherDisplay === "function") {
					window.fchubMcSyncSwitcherDisplay(data.display_currency);
				}
				debugLog("switcher_sync_returned", {
					appliedCurrency: data.display_currency,
					triggerCodeAfterApply: document.querySelector(".fchub-mc-switcher__code")?.textContent ?? null,
				});

				window.dispatchEvent(
					new CustomEvent("fchub_mc:context_reconciled", {
						detail: { currency: data.display_currency, source: data.source || "" },
					}),
				);

				return true;
			})
			.catch((err) => {
				debugLog("reconcile_error", { message: err?.message ?? String(err) });
				return false;
			})
			.finally(() => {
				if (timeoutId) clearTimeout(timeoutId);
				debugLog("reconcile_settled");
			});
	}

	// Add FOUC prevention class immediately — but only for the plain
	// (non-reconciliation) path. Prices must not be touched at all while a
	// reconciliation is pending: no hide, no dim, nothing — confirmed against
	// how a normal (non-reconciling) page already behaves, where
	// .fchub-mc-projecting does one hide-then-reveal cycle and nothing more.
	// They stay exactly as the server rendered them until reconcile() settles
	// and projects them once, directly to the final values, in a single
	// atomic pass — see the reconciliation branch of init() below.
	//
	// Deliberately no else branch here, and no switcher-trigger UI state of
	// any kind added anywhere else in this file (issue #72 follow-up). A
	// currency switch is a two-phase action: selectOption() in
	// currency-switcher.js optimistically updates the trigger and applies
	// .fchub-mc-switcher--loading itself before POSTing, then reloads the
	// page on success — a hard navigation, which this script only ever sees
	// the far side of. The browser drops all DOM focus on that navigation,
	// so whatever focus/hover-driven styling a theme applies to the trigger
	// (confirmed live: this site's theme styles a plain CSS `button:focus`
	// rule, unrelated to this plugin) is already gone by the time the fresh
	// page paints, logged in or out — that part isn't this file's to
	// replicate or restore. A logged-in reload has nothing left to wait for,
	// so it settles into that same fresh, unfocused, undimmed look
	// instantly. Re-applying .fchub-mc-switcher--loading here and holding it
	// for the length of the reconcile() fetch used to be exactly what made a
	// logged-out reload look different: it kept the trigger visibly dimmed
	// for several more seconds with nothing left to dim it *toward* (no
	// focus, no theme highlight), reading as a stuck/flashing control
	// instead of a settled one. Leaving the trigger alone here reproduces
	// the logged-in look exactly, because it's the same DOM in the same
	// unfocused post-navigation state either way.
	if (!reconciliationCandidate) {
		document.documentElement.classList.add("fchub-mc-projecting");
	}

	function init() {
		if (!reconciliationCandidate) {
			debugLog("init_no_reconciliation_branch");
			projectPrices();
			observeDynamicUpdates();
			listenForFluentCartEvents();
			return;
		}

		debugLog("init_reconciliation_branch");

		// Deliberately do nothing to prices here — no projectPrices() call, no
		// hide/dim class, nothing. They stay exactly as the server rendered
		// them (unconverted base-currency figures) until reconcile() settles
		// below. Its finally() is the only place prices get touched during
		// this whole window: clearProjectionMarkers() (in its own .then(),
		// above — a no-op here since nothing has been projected/marked yet)
		// and this first-ever projectPrices() call happen back to back in the
		// same synchronous pass, so the visible change is one atomic swap
		// straight from untouched-old to converted-new — never an
		// intermediate hidden, dimmed, or partially-updated state.
		reconcile().finally(() => {
			// fchubMcSyncSwitcherDisplay() (called from within reconcile()'s own
			// success path, before this finally() runs) has already updated the
			// trigger's content by this point if reconciliation found a change.
			// Nothing here needs to reveal or restore it — the trigger was never
			// hidden, dimmed, or otherwise held back by this file to begin with.
			debugLog("reconcile_finally_projecting", {
				triggerCodeAtThisPoint: document.querySelector(".fchub-mc-switcher__code")?.textContent ?? null,
			});
			projectPrices();
			observeDynamicUpdates();
			listenForFluentCartEvents();
			debugLog("init_reconciliation_branch_complete", {
				triggerCodeFinal: document.querySelector(".fchub-mc-switcher__code")?.textContent ?? null,
			});
		});
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", init);
	} else {
		init();
	}

	// Expose for programmatic use
	window.fchubMcProjectPrices = projectPrices;
})();
