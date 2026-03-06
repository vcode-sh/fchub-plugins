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
 * Fires: fchub_mc:prices_projected (after each projection pass)
 */
(() => {
	const cfg = window.fchubMcConfig || {};
	const rate = parseFloat(cfg.rate || "1");

	// Bail out if no conversion needed
	if (!cfg.displayCurrency || !cfg.baseCurrency) return;
	if (cfg.isBaseDisplay) return;
	if (cfg.displayCurrency === cfg.baseCurrency) return;
	if (!rate || rate === 1 || Number.isNaN(rate)) return;

	// Display currency config
	const decimals = parseInt(cfg.decimals || "2", 10);
	const symbol = cfg.symbol || cfg.displayCurrency;
	const position = cfg.position || "left";
	const roundingMode = cfg.roundingMode || "half_up";
	const displayCode = cfg.displayCurrency;
	const baseCode = cfg.baseCurrencyCode || cfg.baseCurrency;

	// Base currency parsing config
	const baseSign = cfg.baseCurrencySign || "$";
	const baseDecSep = cfg.baseDecimalSep || ".";
	const baseThousandSep = cfg.baseThousandSep || ",";

	// Display currency formatting config (for output)
	const displayDecSep = cfg.displayDecSep || ".";
	const displayThousandSep = cfg.displayThousandSep || ",";

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
		"[data-fluent-cart-checkout-estimated-total]",
		"[data-fluent-cart-checkout-subtotal]",
		".shipping-method-amount",
		// Pricing table
		".fluent-cart-pricing-table-variant-price",
		".fluent-cart-pricing-table-variant-compare-price",
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
			case "none":
				return amount;
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
	 * Find the deepest element that contains the price text.
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
		return el;
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
				const textAmount = parseBasePrice(node.textContent.trim());
				if (!Number.isNaN(textAmount)) {
					node.textContent = ` ${formatPrice(applyRounding(textAmount * rate))} `;
					count++;
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
			if (anchor.parentNode.querySelector(`[${DISCLOSURE_ATTR}]`)) return;
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
		const rawText = target.textContent;
		if (!looksLikePrice(rawText)) return 0;

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

			btn.setAttribute(ATTR_PROJECTED, "1");
		}
	}

	/**
	 * Main projection: find all price elements and convert them.
	 */
	function projectPrices(root) {
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
	 */
	function listenForFluentCartEvents() {
		const events = [
			"fluentCartFragmentsReplaced",
			"fluentCartNotifySummaryViewUpdated",
			"fluentCartNotifyCartDrawerItemChanged",
			"fchub_mc:context_changed",
		];

		for (const eventName of events) {
			document.addEventListener(eventName, () => {
				setTimeout(() => {
					clearProjectionMarkers();
					projectPrices();
				}, 100);
			});
		}
	}

	// Add FOUC prevention class immediately
	document.documentElement.classList.add("fchub-mc-projecting");

	// Initial projection
	function init() {
		projectPrices();
		observeDynamicUpdates();
		listenForFluentCartEvents();
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", init);
	} else {
		init();
	}

	// Expose for programmatic use
	window.fchubMcProjectPrices = projectPrices;
})();
