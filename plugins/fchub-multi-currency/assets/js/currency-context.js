/**
 * FCHub Multi-Currency — Currency Surfaces
 *
 * Paints the currency the browser resolved onto every surface that names one:
 * both switcher variants, the context blocks, and the hidden checkout field.
 *
 * It owns no decision. `currency-bootstrap.js` decides which currency this
 * visitor sees; this only makes the page say so.
 */
(() => {
	const config = window.fchubMcConfig || {};

	function normalizeCode(value) {
		const code = typeof value === "string" ? value.trim().toUpperCase() : "";
		return /^[A-Z]{3}$/.test(code) ? code : "";
	}

	function syncCheckoutCurrencyFields(root) {
		if (!root || typeof root.querySelectorAll !== "function") return;

		const code = normalizeCode(config.displayCurrency);
		if (!Object.hasOwn(config.currencyTable || {}, code)) return;

		for (const field of root.querySelectorAll("[data-fchub-mc-checkout-currency]")) {
			field.value = code;
		}
	}

	function enabled(root, attribute) {
		return root.getAttribute(attribute) === "1";
	}

	function syncOption(option, code) {
		const active = normalizeCode(option.getAttribute("data-value")) === code;
		option.classList.toggle("fchub-mc-switcher__option--active", active);
		option.setAttribute("aria-selected", active ? "true" : "false");

		const check = option.querySelector(".fchub-mc-switcher__option-check");
		if (check) check.textContent = active ? "✓" : "";

		return active;
	}

	function syncTrigger(trigger, context, selected) {
		if (!trigger) return;

		const code = context.displayCurrency;
		const textParts = {
			code,
			name:
				context.displayCurrencyName
				|| selected?.querySelector(".fchub-mc-switcher__option-name")?.textContent
				|| code,
			symbol:
				context.symbol
				|| selected?.querySelector(".fchub-mc-switcher__option-symbol")?.textContent
				|| code,
		};
		for (const [part, value] of Object.entries(textParts)) {
			const element = trigger.querySelector(`.fchub-mc-switcher__${part}`);
			if (element) element.textContent = value;
		}

		const flag = trigger.querySelector(".fchub-mc-switcher__flag");
		const recoveredFlag = context.presentation?.flag;
		if (flag && typeof recoveredFlag === "string") {
			flag.innerHTML = recoveredFlag;
			return;
		}

		const selectedFlag = selected?.querySelector(".fchub-mc-switcher__flag");
		if (flag && selectedFlag) flag.innerHTML = selectedFlag.innerHTML;
	}

	function syncSwitcherFooter(root, parts) {
		const footer = root.querySelector("[data-fchub-mc-switcher-footer]");
		if (!footer || !parts) return;

		footer.innerHTML = [
			enabled(root, "data-fchub-mc-show-rate-badge") ? parts.rateBadge : "",
			enabled(root, "data-fchub-mc-show-rate-value") ? parts.rateValue : "",
			enabled(root, "data-fchub-mc-show-context-note") ? parts.contextNote : "",
		].join("");
		footer.hidden = footer.innerHTML === "";
	}

	function syncSwitcher(root, context) {
		let selected = null;
		for (const option of root.querySelectorAll("[role='option'][data-value]")) {
			const active = syncOption(option, context.displayCurrency);
			if (active) selected = option;
		}

		syncTrigger(root.querySelector("[data-fchub-mc-trigger]"), context, selected);
		syncSwitcherFooter(root, context.presentation?.switcher);
	}

	function syncCurrentBlocks(presentation) {
		for (const root of document.querySelectorAll("[data-fchub-mc-context-current]")) {
			const mode = root.getAttribute("data-fchub-mc-context-current") || "flag_code";
			if (typeof presentation.current?.[mode] === "string") {
				root.innerHTML = presentation.current[mode];
			}
		}
	}

	function syncRateBlocks(context, presentation) {
		for (const root of document.querySelectorAll("[data-fchub-mc-context-rate]")) {
			const hide = enabled(root, "data-fchub-mc-hide-when-base") && context.isBaseDisplay;
			const format = root.getAttribute("data-fchub-mc-context-rate") || "compact";
			const precision = root.getAttribute("data-fchub-mc-rate-precision") || "4";
			const fragment = presentation.rate?.[format]?.[precision];
			if (hide) root.innerHTML = "";
			else if (typeof fragment === "string") root.innerHTML = fragment;
		}
	}

	function syncNoticeBlocks(context, presentation) {
		for (const root of document.querySelectorAll("[data-fchub-mc-context-notice]")) {
			const hide = enabled(root, "data-fchub-mc-hide-when-base") && context.isBaseDisplay;
			const mode = root.getAttribute("data-fchub-mc-context-notice") || "compact";
			const fragment = presentation.notice?.[mode];
			if (hide) root.innerHTML = "";
			else if (typeof fragment === "string") root.innerHTML = fragment;
		}
	}

	function syncContextBlocks(context) {
		const presentation = context.presentation;
		if (!presentation) return;

		syncCurrentBlocks(presentation);
		syncRateBlocks(context, presentation);
		syncNoticeBlocks(context, presentation);
	}

	function applyContext(context) {
		if (!context || typeof context !== "object") return;

		for (const root of document.querySelectorAll("[data-fchub-mc-switcher]")) {
			syncSwitcher(root, context);
		}

		for (const root of document.querySelectorAll("[data-fchub-mc-button-switcher]")) {
			for (const button of root.querySelectorAll("[data-value]")) {
				const active = normalizeCode(button.getAttribute("data-value")) === context.displayCurrency;
				button.classList.toggle("is-active", active);
			}
		}

		syncContextBlocks(context);
		syncCheckoutCurrencyFields(document);
	}

	// Capture runs before FluentCart's form handler constructs FormData.
	document.addEventListener("submit", (event) => syncCheckoutCurrencyFields(event.target), true);
	window.fchubMcSyncLabels = applyContext;

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", () => applyContext(config), { once: true });
	} else {
		applyContext(config);
	}
})();
