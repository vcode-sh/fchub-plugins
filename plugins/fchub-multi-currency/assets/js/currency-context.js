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

	const templates = config.presentationTemplates || {};

	/** Matches esc_html(), so a JS-rendered surface is byte-identical to the PHP one. */
	function escapeHtml(value) {
		return String(value ?? "")
			.replaceAll("&", "&amp;")
			.replaceAll("<", "&lt;")
			.replaceAll(">", "&gt;")
			.replaceAll('"', "&quot;")
			.replaceAll("'", "&#039;");
	}

	/** Fills the `%1$s`-style placeholders WordPress translators rely on. */
	function fill(template, values) {
		let index = 0;
		return String(template || "").replace(/%(?:(\d+)\$)?s/g, (_match, position) =>
			String(values[position ? Number(position) - 1 : index++] ?? ""),
		);
	}

	function entryFor(code) {
		return config.currencyTable?.[code] || {};
	}

	function renderCurrent(code, mode) {
		const entry = entryFor(code);
		const wrap = (inner) => `<span class="fchub-mc-inline-current">${inner}</span>`;
		const text = (value) =>
			`<span class="fchub-mc-inline-current__text">${escapeHtml(value)}</span>`;

		switch (mode) {
			case "code":
				return wrap(escapeHtml(code));
			case "symbol":
				return wrap(escapeHtml(entry.symbol));
			case "name":
				return wrap(escapeHtml(entry.displayCurrencyName));
			case "flag_name":
				return wrap((entry.flag || "") + text(entry.displayCurrencyName));
			case "symbol_code":
				return wrap(text(entry.symbol) + text(code));
			default:
				return wrap((entry.flag || "") + text(code));
		}
	}

	function renderRate(code, precision, format) {
		const digits = Math.max(0, Math.min(8, Number(precision) || 0));
		const rate = Number(entryFor(code).rate || 1).toFixed(digits);
		const template = format === "sentence" ? templates.rateSentence : templates.rate;

		return `<span class="fchub-mc-inline-rate">${escapeHtml(fill(template, [config.baseCurrency, rate, code]))}</span>`;
	}

	function renderNotice(code, mode) {
		if (mode === "checkout") {
			const disclosure = entryFor(code).disclosureText;
			return disclosure ? `<span class="fchub-mc-inline-notice">${disclosure}</span>` : "";
		}

		const template = mode === "full" ? templates.noticeFull : templates.noticeCompact;
		return `<span class="fchub-mc-inline-notice">${escapeHtml(fill(template, [code, config.baseCurrency]))}</span>`;
	}

	function switcherParts(code) {
		const isBase = code === config.baseCurrency;
		const context = (inner) => `<span class="fchub-mc-rate-context">${inner}</span>`;

		return {
			rateBadge: entryFor(code).rateBadge || "",
			rateValue: context(
				escapeHtml(
					isBase
						? templates.switcherRateBase
						: fill(templates.rate, [config.baseCurrency, entryFor(code).rate, code]),
				),
			),
			contextNote: context(
				escapeHtml(
					isBase
						? templates.switcherContextBase
						: fill(templates.switcherContext, [config.baseCurrency]),
				),
			),
		};
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
				context.displayCurrencyName ||
				selected?.querySelector(".fchub-mc-switcher__option-name")?.textContent ||
				code,
			symbol:
				context.symbol ||
				selected?.querySelector(".fchub-mc-switcher__option-symbol")?.textContent ||
				code,
		};
		for (const [part, value] of Object.entries(textParts)) {
			const element = trigger.querySelector(`.fchub-mc-switcher__${part}`);
			if (element) element.textContent = value;
		}

		const flag = trigger.querySelector(".fchub-mc-switcher__flag");
		if (!flag) return;

		const selectedFlag = selected?.querySelector(".fchub-mc-switcher__flag");
		flag.innerHTML = entryFor(code).flag || selectedFlag?.innerHTML || "";
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
		syncSwitcherFooter(root, switcherParts(context.displayCurrency));
	}

	function syncContextBlocks(context) {
		const code = context.displayCurrency;
		const isBase = code === config.baseCurrency;

		for (const root of document.querySelectorAll("[data-fchub-mc-context-current]")) {
			root.innerHTML = renderCurrent(
				code,
				root.getAttribute("data-fchub-mc-context-current") || "flag_code",
			);
		}

		for (const root of document.querySelectorAll("[data-fchub-mc-context-rate]")) {
			const hide = enabled(root, "data-fchub-mc-hide-when-base") && isBase;
			root.innerHTML = hide
				? ""
				: renderRate(
						code,
						root.getAttribute("data-fchub-mc-rate-precision") || "4",
						root.getAttribute("data-fchub-mc-context-rate") || "compact",
					);
		}

		for (const root of document.querySelectorAll("[data-fchub-mc-context-notice]")) {
			const hide = enabled(root, "data-fchub-mc-hide-when-base") && isBase;
			root.innerHTML = hide
				? ""
				: renderNotice(code, root.getAttribute("data-fchub-mc-context-notice") || "compact");
		}
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
