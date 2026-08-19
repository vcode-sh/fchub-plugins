/**
 * FCHub Multi-Currency — Display Currency Bootstrap
 *
 * Decides which currency this visitor sees, from the currency table the page
 * already carries. The page itself is the same bytes for everyone, so nobody but
 * the browser can answer this — and the browser can answer it without a request.
 *
 * Exposes `window.fchubMc.currentCurrency()` and `.setCurrency(code)`.
 */
(() => {
	const config = window.fchubMcConfig || {};
	const storageKey = config.cookieName || "fchub_mc_currency";
	let current = "";

	function normalizeCode(value) {
		const code = typeof value === "string" ? value.trim().toUpperCase() : "";
		return /^[A-Z]{3}$/.test(code) ? code : "";
	}

	function offered(code) {
		return code !== "" && Object.hasOwn(config.currencyTable || {}, code);
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

	/** Blocked storage is not an error; it is one fewer place to look for a preference. */
	function optional(read) {
		try {
			return read() || "";
		} catch {
			return "";
		}
	}

	function forget() {
		optional(() => window.localStorage.removeItem(storageKey));
	}

	function remember(code) {
		const days = Math.max(1, Math.min(365, Number(config.cookieLifetimeDays) || 90));
		const entry = JSON.stringify({ currency: code, expiresAt: Date.now() + days * 86400000 });
		optional(() => window.localStorage.setItem(storageKey, entry));
	}

	/** Reads the saved choice, migrating the bare code older versions wrote. */
	function storedCurrency() {
		const raw = optional(() => window.localStorage.getItem(storageKey));
		if (!raw) return "";

		const legacy = normalizeCode(raw);
		if (offered(legacy)) {
			remember(legacy);
			return legacy;
		}

		try {
			const saved = JSON.parse(raw);
			const code = normalizeCode(saved?.currency);
			if (offered(code) && Number(saved?.expiresAt) > Date.now()) return code;
		} catch {
			// Unreadable data is cleared below along with the expired and withdrawn.
		}

		forget();
		return "";
	}

	/**
	 * An explicit link wins for the page it was followed to, and only that page.
	 * Adopting it would let anyone rewrite a stranger's saved preference by
	 * sending them a URL.
	 */
	function urlCurrency() {
		if (config.urlParamEnabled !== true || !config.urlParamKey) return "";

		const params = new URLSearchParams(window.location.search || "");
		const code = normalizeCode(params.get(config.urlParamKey));
		return offered(code) ? code : "";
	}

	function accountCurrency() {
		if (config.isLoggedIn !== true) return "";

		const code = normalizeCode(config.accountCurrency);
		return offered(code) ? code : "";
	}

	/**
	 * A first-visit guess from the visitor's own clock: the timezone implies a
	 * country, the country an offered currency. Nothing leaves the browser and
	 * nothing is stored — a guess must never outrank a remembered choice, and
	 * an explicit switch replaces it through the normal persistence path. The
	 * catch also covers hosts without Intl at all.
	 */
	function localeCurrency() {
		if (config.geoEnabled !== true) return "";

		let zone = "";
		try {
			zone = Intl.DateTimeFormat().resolvedOptions().timeZone || "";
		} catch {
			return "";
		}

		const code = normalizeCode(config.localeCurrencies?.[zone]);
		return offered(code) ? code : "";
	}

	/**
	 * Mirrors the server's resolver order, with one browser-only extra: the
	 * locale hint sits between the cookie and the default because only the
	 * browser knows the visitor's timezone. The server resolving for REST or
	 * the no-JS form never sees a locale guess, and that is the design — a
	 * hint is a per-paint answer, never shared state.
	 */
	function resolve() {
		if (config.cookiePersistenceEnabled !== true) forget();

		const cookie = normalizeCode(readCookie(config.cookieName));
		const fallback = normalizeCode(config.defaultCurrency);
		const base = normalizeCode(config.baseCurrency);

		return (
			urlCurrency() ||
			storedCurrency() ||
			accountCurrency() ||
			(offered(cookie) ? cookie : "") ||
			localeCurrency() ||
			(offered(fallback) ? fallback : "") ||
			(offered(base) ? base : "")
		);
	}

	/**
	 * Hide amounts only where they are about to change.
	 *
	 * A visitor already on the base currency has nothing to convert. Neither does a
	 * store with no usable rate at all — its table is empty, so there is no currency
	 * to resolve to and nothing will ever arrive to lower the shield.
	 */
	function shieldPrices(code) {
		const converts =
			code !== "" &&
			code !== normalizeCode(config.baseCurrency) &&
			config.projectionEnabled === true;
		document.documentElement.classList.toggle("fchub-mc-pending", converts);
	}

	current = resolve();
	shieldPrices(current);

	window.fchubMc = {
		currentCurrency: () => current,
		/**
		 * Writes a currency's facts into the live config and reports whether the
		 * store offers it. The one place that merge happens, so a runtime that is
		 * not loaded cannot take the answer with it.
		 */
		select: (value) => {
			const code = normalizeCode(value);
			if (!offered(code)) return false;

			Object.assign(config, config.currencyTable[code], {
				displayCurrency: code,
				isBaseDisplay: code === normalizeCode(config.baseCurrency),
			});
			return true;
		},
		setCurrency: (value) => {
			const code = normalizeCode(value);
			if (!offered(code)) return false;

			current = code;
			if (config.cookiePersistenceEnabled === true) remember(code);
			return true;
		},
	};
})();
