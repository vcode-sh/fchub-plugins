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
	if (!rate || !Number.isFinite(rate) || rate === 1) return;

	// Display currency config
	const decimals = Math.max(0, Math.min(20, parseInt(cfg.decimals, 10) || 2));
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
