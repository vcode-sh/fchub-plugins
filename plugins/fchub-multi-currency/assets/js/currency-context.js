/**
 * FCHub Multi-Currency — Cached Context Recovery
 *
 * Repairs currency state when an edge cache serves shared HTML. Guest choices
 * are mirrored in local storage because some hosts do not vary pages by cookie.
 */
(() => {
	const config = window.fchubMcConfig || {};
	const storageKey = config.cookieName || "fchub_mc_currency";
	const recoveryDisabledControls = new Set();
	let recoveryActive = false;
	let recoveryCurrency = "";

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

	function readStoredPreference() {
		try {
			return window.localStorage.getItem(storageKey) || "";
		} catch {
			return "";
		}
	}

	function removeStoredPreference() {
		try {
			window.localStorage.removeItem(storageKey);
		} catch {
			// Cookie persistence remains available when browser storage is blocked.
		}
	}

	function writeStoredPreference(currency) {
		const lifetimeDays = Math.max(1, Math.min(365, Number(config.cookieLifetimeDays) || 90));
		const value = JSON.stringify({
			currency,
			expiresAt: Date.now() + lifetimeDays * 86400000,
		});

		try {
			window.localStorage.setItem(storageKey, value);
		} catch {
			// Cookie persistence remains available when browser storage is blocked.
		}
	}

	function storedCurrency(codes) {
		const raw = readStoredPreference();
		if (!raw) return "";

		const legacyCode = normalizeCode(raw);
		if (codes.includes(legacyCode)) {
			writeStoredPreference(legacyCode);
			return legacyCode;
		}

		try {
			const value = JSON.parse(raw);
			const code = normalizeCode(value?.currency);
			const expiresAt = Number(value?.expiresAt);
			if (codes.includes(code) && Number.isFinite(expiresAt) && expiresAt > Date.now()) return code;
		} catch {
			// Invalid browser data is discarded below.
		}

		removeStoredPreference();
		return "";
	}

	function persistBrowserPreference(value) {
		const code = normalizeCode(value);
		if (config.cookiePersistenceEnabled !== true) {
			removeStoredPreference();
			return;
		}
		if (allowedCodes().includes(code)) writeStoredPreference(code);
	}

	function syncCheckoutCurrencyFields(root) {
		if (!root || typeof root.querySelectorAll !== "function") return;

		const code = normalizeCode(config.displayCurrency);
		if (!allowedCodes().includes(code)) return;

		for (const field of root.querySelectorAll("[data-fchub-mc-checkout-currency]")) {
			field.value = code;
		}
	}

	function explicitUrlCurrency(codes) {
		if (config.urlParamEnabled !== true || !config.urlParamKey) return "";

		const params = new URLSearchParams(window.location.search || "");
		const urlCode = normalizeCode(params.get(config.urlParamKey));
		return codes.includes(urlCode) ? urlCode : "";
	}

	function stripConfirmedSwitchUrl(codes, confirmedCurrency) {
		if (
			config.cookiePersistenceEnabled !== true ||
			config.urlParamEnabled !== true ||
			!config.urlParamKey ||
			typeof window.history?.replaceState !== "function"
		) {
			return;
		}

		try {
			const url = new URL(window.location.href);
			const urlCode = normalizeCode(url.searchParams.get(config.urlParamKey));
			const confirmedCode = normalizeCode(confirmedCurrency);
			if (
				!codes.includes(urlCode) ||
				confirmedCode !== urlCode ||
				normalizeCode(config.displayCurrency) !== urlCode ||
				storedCurrency(codes) !== urlCode
			) {
				return;
			}

			url.searchParams.delete(config.urlParamKey);
			window.history.replaceState(window.history.state, "", url.toString());
		} catch {
			// URL cleanup is cosmetic; the resolved currency remains authoritative.
		}
	}

	function recoveryRequest(codes) {
		if (config.cookiePersistenceEnabled !== true) {
			removeStoredPreference();
		}

		const urlCurrency = explicitUrlCurrency(codes);
		if (urlCurrency !== "") {
			return {
				currency: urlCurrency,
				cookieSnapshot: readCookie(config.cookieName),
				storageSnapshot: readStoredPreference(),
			};
		}

		if (config.cookiePersistenceEnabled !== true) return null;
		if (config.resolverSource === "user_meta") return null;
		if (config.isLoggedIn === true && config.accountPersistenceEnabled === true) return null;

		const validStoredCode = storedCurrency(codes);

		const rawCookie = readCookie(config.cookieName);
		const cookieCode = normalizeCode(rawCookie);
		const validCookieCode = codes.includes(cookieCode) ? cookieCode : "";
		if (!validStoredCode && validCookieCode) writeStoredPreference(validCookieCode);

		const currency = validStoredCode || validCookieCode;
		const snapshots = {
			cookieSnapshot: rawCookie,
			storageSnapshot: readStoredPreference(),
		};
		if (currency !== "") {
			return currency === normalizeCode(config.displayCurrency) ? null : { currency, ...snapshots };
		}

		return ["cookie", "url_param"].includes(config.resolverSource)
			? { currency: "", ...snapshots }
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

	function setRecoveryControlState(control, busy) {
		if (!control) return;

		if (busy) {
			if (control.disabled !== true) {
				control.disabled = true;
				recoveryDisabledControls.add(control);
			}
			return;
		}

		if (recoveryDisabledControls.has(control)) {
			control.disabled = false;
			recoveryDisabledControls.delete(control);
		}
	}

	function setRecoveryRootState(root, className, busy) {
		root.classList.toggle(className, busy);
		if (busy) root.setAttribute("aria-busy", "true");
		else root.removeAttribute("aria-busy");
	}

	function setRecoveryState(busy, currency = "") {
		recoveryActive = busy;
		if (busy && currency) recoveryCurrency = currency;
		config.recoveryPending = busy;
		document.documentElement.classList.toggle("fchub-mc-recovering", busy);

		for (const root of document.querySelectorAll("[data-fchub-mc-switcher]")) {
			setRecoveryRootState(root, "fchub-mc-switcher--loading", busy);
			setRecoveryControlState(root.querySelector("[data-fchub-mc-trigger]"), busy);
			if (busy && currency) syncSwitcher(root, { displayCurrency: currency });
		}

		for (const root of document.querySelectorAll("[data-fchub-mc-button-switcher]")) {
			setRecoveryRootState(root, "fchub-mc-selector-buttons--loading", busy);
			for (const button of root.querySelectorAll("[data-value]")) {
				setRecoveryControlState(button, busy);
				if (busy && currency) {
					button.classList.toggle(
						"is-active",
						normalizeCode(button.getAttribute("data-value")) === currency,
					);
				}
			}
		}
	}

	function completeRecovery() {
		if (!recoveryActive) return;
		setRecoveryState(false);
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
		const codes = allowedCodes();
		const request = recoveryRequest(codes);
		if (!request) return config;
		const currency = request.currency;
		setRecoveryState(true, currency);

		const restUrl = String(config.restUrl || "/wp-json/fchub-mc/v1").replace(/\/+$/, "");
		const explicitCurrency = currency ? `currency=${encodeURIComponent(currency)}&` : "";
		const endpoint = `${restUrl}/context?${explicitCurrency}_fchub_mc=${Date.now()}`;
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
			if (
				readCookie(config.cookieName) !== request.cookieSnapshot ||
				readStoredPreference() !== request.storageSnapshot
			) {
				applyContext(config);
				return config;
			}

			Object.assign(config, context);
			applyContext(context);
			stripConfirmedSwitchUrl(codes, currency);
		} catch (error) {
			applyContext(config);
			console.warn("[fchub-mc] Currency context recovery failed:", error);
		} finally {
			if (timeout !== null) clearTimeout(timeout);
			recoveryCurrency = normalizeCode(config.displayCurrency);
			if (config.projectionEnabled !== true) completeRecovery();
		}

		return config;
	}

	// Capture runs before FluentCart's form handler constructs FormData.
	document.addEventListener("submit", (event) => syncCheckoutCurrencyFields(event.target), true);
	window.fchubMcPersistBrowserPreference = persistBrowserPreference;
	window.fchubMcCompleteRecovery = completeRecovery;
	window.fchubMcContextReady = recover();
	if (document.readyState === "loading") {
		document.addEventListener(
			"DOMContentLoaded",
			() => {
				applyContext(config);
				if (recoveryActive) setRecoveryState(true, recoveryCurrency);
			},
			{ once: true },
		);
	}
})();
