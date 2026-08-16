/**
 * FCHub Multi-Currency — Cached Context Recovery
 *
 * Repairs currency state when an edge cache serves HTML generated for another
 * guest preference. The existing currency cookie remains the only preference
 * store; this script merely asks the uncached context endpoint to resolve it.
 */
(() => {
	const config = window.fchubMcConfig || {};

	function normalizeCode(value) {
		const code = typeof value === "string" ? value.trim().toUpperCase() : "";
		return /^[A-Z]{3}$/.test(code) ? code : "";
	}

	function allowedCodes() {
		return Array.isArray(config.allowedCurrencyCodes)
			? config.allowedCurrencyCodes.map(normalizeCode).filter(Boolean)
			: [];
	}

	function readCookie(name) {
		if (!name) return "";

		for (const part of document.cookie.split(";")) {
			const separator = part.indexOf("=");
			const key = separator >= 0 ? part.slice(0, separator).trim() : part.trim();
			if (key !== name) continue;

			try {
				return decodeURIComponent(separator >= 0 ? part.slice(separator + 1) : "");
			} catch {
				return "";
			}
		}

		return "";
	}

	function hasExplicitUrlPreference(codes) {
		if (config.urlParamEnabled !== true || !config.urlParamKey) return false;

		const params = new URLSearchParams(window.location.search || "");
		const urlCode = normalizeCode(params.get(config.urlParamKey));
		return urlCode !== "" && codes.includes(urlCode);
	}

	function recoveryRequest() {
		if (config.cookiePersistenceEnabled !== true) return null;
		if (config.resolverSource === "user_meta") return null;
		if (config.isLoggedIn === true && config.accountPersistenceEnabled === true) return null;

		const codes = allowedCodes();
		if (hasExplicitUrlPreference(codes)) return null;

		const rawCookie = readCookie(config.cookieName);
		const cookieCode = normalizeCode(rawCookie);
		const validCookieCode = codes.includes(cookieCode) ? cookieCode : "";
		if (validCookieCode !== "") {
			return validCookieCode === normalizeCode(config.displayCurrency)
				? null
				: { currency: validCookieCode, cookieSnapshot: rawCookie };
		}

		return ["cookie", "url_param"].includes(config.resolverSource)
			? { currency: "", cookieSnapshot: rawCookie }
			: null;
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
			name: context.displayCurrencyName || code,
			symbol: context.symbol || code,
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
	}

	function isValidContext(context) {
		if (!context || typeof context !== "object") return false;
		const codes = allowedCodes();
		const displayCode = normalizeCode(context.displayCurrency);
		const baseCode = normalizeCode(context.baseCurrency);
		const rate = Number(context.rate);

		return (
			codes.includes(displayCode) && codes.includes(baseCode) && Number.isFinite(rate) && rate > 0
		);
	}

	async function recover() {
		const request = recoveryRequest();
		if (!request) return config;
		const currency = request.currency;

		const restUrl = String(config.restUrl || "/wp-json/fchub-mc/v1").replace(/\/+$/, "");
		const endpoint = currency
			? `${restUrl}/context?currency=${encodeURIComponent(currency)}`
			: `${restUrl}/context`;
		const controller = typeof AbortController === "function" ? new AbortController() : null;
		const timeout = controller ? setTimeout(() => controller.abort(), 15000) : null;

		try {
			const response = await fetch(endpoint, {
				method: "GET",
				credentials: "same-origin",
				cache: "no-store",
				headers: { Accept: "application/json" },
				...(controller ? { signal: controller.signal } : {}),
			});
			const payload = await response.json();
			const context = payload?.data?.context;

			if (!response.ok || !isValidContext(context)) {
				throw new Error(`Currency context recovery failed (HTTP ${response.status || 0}).`);
			}
			if (readCookie(config.cookieName) !== request.cookieSnapshot) {
				return config;
			}

			Object.assign(config, context);
			applyContext(context);
		} catch (error) {
			console.warn("[fchub-mc] Currency context recovery failed:", error);
		} finally {
			if (timeout !== null) clearTimeout(timeout);
		}

		return config;
	}

	window.fchubMcContextReady = recover();
})();
