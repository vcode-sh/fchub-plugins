/**
 * FCHub Multi-Currency — Currency Switcher Widget
 *
 * Custom dropdown with flag emojis, ARIA listbox, keyboard navigation.
 *
 * Fires: fchub_mc:context_changed, fchub_mc:context_switch_failed
 */
(() => {
	const config = window.fchubMcConfig || {};
	const restUrl = config.restUrl || "/wp-json/fchub-mc/v1";
	const nonce = config.nonce || "";
	const flagBaseUrl = config.flagBaseUrl || "";

	const currencyFlagMap = {
		USD: "us",
		EUR: "eu",
		GBP: "gb",
		JPY: "jp",
		CHF: "ch",
		CAD: "ca",
		AUD: "au",
		NZD: "nz",
		SEK: "se",
		NOK: "no",
		DKK: "dk",
		PLN: "pl",
		CZK: "cz",
		HUF: "hu",
		RON: "ro",
		BGN: "bg",
		HRK: "hr",
		ISK: "is",
		TRY: "tr",
		RUB: "ru",
		UAH: "ua",
		BRL: "br",
		MXN: "mx",
		ARS: "ar",
		CLP: "cl",
		COP: "co",
		PEN: "pe",
		CNY: "cn",
		HKD: "hk",
		SGD: "sg",
		TWD: "tw",
		KRW: "kr",
		INR: "in",
		IDR: "id",
		MYR: "my",
		PHP: "ph",
		THB: "th",
		VND: "vn",
		AED: "ae",
		SAR: "sa",
		QAR: "qa",
		KWD: "kw",
		BHD: "bh",
		OMR: "om",
		ILS: "il",
		EGP: "eg",
		ZAR: "za",
		NGN: "ng",
		KES: "ke",
		GHS: "gh",
	};

	function buildFlagImg(currencyCode) {
		const country = currencyFlagMap[currencyCode.toUpperCase()];
		if (!country || !flagBaseUrl) return null;
		const img = document.createElement("img");
		img.src = `${flagBaseUrl + country}.svg`;
		img.alt = currencyCode;
		img.className = "fchub-mc-flag";
		img.width = 20;
		img.height = 15;
		return img;
	}

	let idCounter = 0;

	// Translated server-side and shipped with the other rendered sentences; a
	// literal here would be the one English string in a translated plugin.
	const UNAVAILABLE_ERROR =
		config.presentationTemplates?.currencyUnavailable || "That currency is not available.";

	/**
	 * Tells assistive technology that every price on the page just changed.
	 *
	 * A switch used to reload, and a screen reader announces a page load. It does
	 * not any more: the dropdown closes, every amount silently becomes a different
	 * number, and nothing says so. The region is created up front and written to
	 * later, because a live region populated in the same tick it appears in is not
	 * reliably announced.
	 */
	function announceCurrency(currencyCode) {
		const template = config.presentationTemplates?.currencySwitched;
		if (!template) return;

		const region = document.getElementById("fchub-mc-announcer");
		if (!region) return;

		const name = config.currencyTable?.[currencyCode]?.displayCurrencyName || currencyCode;
		region.textContent = template.replace("%s", name);
	}

	/** One polite region for the page, in place before anything needs to say something. */
	function ensureAnnouncer() {
		if (document.getElementById("fchub-mc-announcer")) return;

		const region = document.createElement("div");
		region.id = "fchub-mc-announcer";
		region.className = "fchub-mc-announcer";
		region.setAttribute("aria-live", "polite");
		region.setAttribute("aria-atomic", "true");
		document.body.appendChild(region);
	}

	function clearLoadingState(root) {
		if (root) {
			root.classList.remove("fchub-mc-switcher--loading");
		}

		document.querySelectorAll(".fchub-mc-switcher--loading").forEach((el) => {
			el.classList.remove("fchub-mc-switcher--loading");
		});
	}

	function clearError(root) {
		if (!root) return;
		const notice = root.querySelector("[data-fchub-mc-error]");
		if (notice) {
			notice.remove();
		}
	}

	function showError(root, message) {
		if (!root) return;
		let notice = root.querySelector("[data-fchub-mc-error]");
		if (!notice) {
			notice = document.createElement("span");
			notice.className = "fchub-mc-switcher__error";
			notice.setAttribute("role", "status");
			notice.setAttribute("data-fchub-mc-error", "");
			root.appendChild(notice);
		}
		notice.textContent = message;
	}

	function failSwitch(currencyCode, message, options, status, code) {
		const root = options.root || null;

		console.warn("[fchub-mc] Currency switch failed:", message);
		clearLoadingState(root);

		if (typeof options.onFailure === "function") {
			options.onFailure();
		}

		showError(root, message);

		window.dispatchEvent(
			new CustomEvent("fchub_mc:context_switch_failed", {
				detail: {
					currency: currencyCode,
					message: message,
					status: status || 0,
					code: code || "",
				},
			}),
		);
	}

	/**
	 * Applies the visitor's choice immediately, then tells the server about it.
	 *
	 * The browser already holds every rate the page offers, so nothing here needs
	 * a round trip to be correct. The POST that follows keeps the cookie in step
	 * for the places the browser cannot reach — order metadata, emails, CRM — and
	 * a failure there means the preference will not outlive this session, not that
	 * the visitor is looking at the wrong prices. Undoing a correct switch to
	 * report a cookie problem would be the worse lie.
	 */
	function switchCurrency(currencyCode, options) {
		const settings = options || {};
		const root = settings.root || null;
		clearError(root);

		const code = String(currencyCode || "")
			.trim()
			.toUpperCase();
		if (window.fchubMcApplyCurrency?.(code) !== true) {
			failSwitch(code, UNAVAILABLE_ERROR, settings, 0, "currency_unavailable");
			return Promise.resolve();
		}

		window.fchubMc?.setCurrency(code);
		announceCurrency(code);
		clearLoadingState(root);
		window.dispatchEvent(
			new CustomEvent("fchub_mc:context_changed", { detail: { currency: code } }),
		);

		return persistPreference(code);
	}

	/** Fire-and-forget: the visitor is already looking at the right prices. */
	function persistPreference(currencyCode) {
		const headers = { "Content-Type": "application/json" };
		if (config.isLoggedIn === true && nonce) {
			headers["X-WP-Nonce"] = nonce;
		}

		return fetch(`${restUrl}/context`, {
			method: "POST",
			credentials: "same-origin",
			cache: "no-store",
			headers,
			body: JSON.stringify({ currency: currencyCode }),
		})
			.then((response) => (response.ok ? null : response.json().catch(() => null)))
			.then((payload) => {
				if (payload === null) return;
				console.warn("[fchub-mc] Currency preference not stored:", readMessage(payload, 0));
			})
			.catch((error) => {
				console.warn("[fchub-mc] Currency preference not stored:", error?.message || "");
			});
	}

	/**
	 * Where the dropdown opens, as two independent questions.
	 *
	 * Each asks the same three things — what did the shortcode ask for, how much room
	 * is there, and does the preferred side actually fit — but about a different axis.
	 * Answering both in one function meant nine branches and no name for either.
	 */
	function preferredSide(root, startClass, endClass, startName, endName) {
		if (root.classList.contains(startClass)) return startName;
		if (root.classList.contains(endClass)) return endName;
		return "auto";
	}

	function chooseVertical(root, rootRect, dropdownHeight, padding) {
		const preferred = preferredSide(
			root,
			"fchub-mc-switcher--direction-up",
			"fchub-mc-switcher--direction-down",
			"up",
			"down",
		);
		const room = {
			down: window.innerHeight - rootRect.bottom - padding,
			up: rootRect.top - padding,
		};

		if (preferred === "auto") return room.down >= room.up ? "down" : "up";

		// Honour the request unless it plainly does not fit and the other side does.
		const opposite = preferred === "up" ? "down" : "up";
		const doesNotFit = room[preferred] < dropdownHeight && room[opposite] > room[preferred];
		return doesNotFit ? opposite : preferred;
	}

	function chooseLeft(root, rootRect, width, padding) {
		const preferred = preferredSide(
			root,
			"fchub-mc-switcher--dropdown-start",
			"fchub-mc-switcher--dropdown-end",
			"start",
			"end",
		);
		const start = rootRect.left;
		const end = rootRect.right - width;
		const overflow = (left) =>
			Math.max(0, padding - left) + Math.max(0, left + width - (window.innerWidth - padding));

		const first = preferred === "end" ? end : start;
		const alternate = preferred === "start" ? end : preferred === "end" ? start : end;
		const chosen =
			preferred === "auto" || overflow(alternate) < overflow(first) ? alternate : first;

		return Math.min(
			Math.max(chosen, padding),
			Math.max(padding, window.innerWidth - padding - width),
		);
	}

	function initSwitcher(root) {
		if (root.hasAttribute("data-fchub-mc-enhanced")) {
			return;
		}
		root.setAttribute("data-fchub-mc-enhanced", "");

		const trigger = root.querySelector("[data-fchub-mc-trigger]");
		const dropdown = root.querySelector("[data-fchub-mc-dropdown]");
		const searchInput = root.querySelector("[data-fchub-mc-search]");
		const listbox = dropdown.querySelector("[role='listbox']");
		const options = () => [...listbox.querySelectorAll("[role='option']")];
		const viewportPadding = 12;

		// ARIA linkage
		const uid = `fchub-mc-${++idCounter}`;
		const listboxId = `${uid}-listbox`;
		listbox.id = listboxId;
		trigger.setAttribute("role", "combobox");
		trigger.setAttribute("aria-haspopup", "listbox");
		trigger.setAttribute("aria-expanded", "false");
		trigger.setAttribute("aria-controls", listboxId);

		let activeIndex = -1;

		function resetDropdownFit() {
			dropdown.style.left = "";
			dropdown.style.right = "";
			dropdown.style.top = "";
			dropdown.style.bottom = "";
			dropdown.style.maxWidth = "";
		}

		function applyDropdownFit() {
			resetDropdownFit();
			dropdown.style.maxWidth = `calc(100vw - ${viewportPadding * 2}px)`;

			const rootRect = root.getBoundingClientRect();
			const dropdownRect = dropdown.getBoundingClientRect();

			if (chooseVertical(root, rootRect, dropdownRect.height, viewportPadding) === "up") {
				dropdown.style.top = "auto";
				dropdown.style.bottom = "calc(100% + 4px)";
			} else {
				dropdown.style.top = "calc(100% + 4px)";
				dropdown.style.bottom = "auto";
			}

			const left = chooseLeft(root, rootRect, dropdownRect.width, viewportPadding);
			dropdown.style.left = `${left - rootRect.left}px`;
			dropdown.style.right = "auto";
		}

		function getActiveIndex() {
			const items = options().filter((option) => option.style.display !== "none");
			const idx = items.findIndex((o) => o.getAttribute("aria-selected") === "true");
			return idx >= 0 ? idx : 0;
		}

		function open() {
			dropdown.hidden = false;
			root.classList.add("fchub-mc-switcher--open");
			trigger.setAttribute("aria-expanded", "true");
			activeIndex = getActiveIndex();
			setActiveOption(activeIndex);
			applyDropdownFit();
		}

		function close() {
			dropdown.hidden = true;
			root.classList.remove("fchub-mc-switcher--open");
			trigger.setAttribute("aria-expanded", "false");
			trigger.removeAttribute("aria-activedescendant");
			resetDropdownFit();
			trigger.focus();
		}

		function toggle() {
			if (dropdown.hidden) {
				open();
			} else {
				close();
			}
		}

		function setActiveOption(index) {
			const items = options().filter((option) => option.style.display !== "none");
			if (items.length === 0) return;
			activeIndex = ((index % items.length) + items.length) % items.length;
			for (const item of items) {
				item.classList.remove("fchub-mc-switcher__option--focused");
			}
			const target = items[activeIndex];
			target.classList.add("fchub-mc-switcher__option--focused");
			target.id = `${uid}-option-${activeIndex}`;
			trigger.setAttribute("aria-activedescendant", target.id);
			target.scrollIntoView({ block: "nearest" });
		}

		const TRIGGER_PART_SELECTORS = [
			".fchub-mc-switcher__flag",
			".fchub-mc-switcher__code",
			".fchub-mc-switcher__symbol",
			".fchub-mc-switcher__name",
		];

		function captureTriggerState() {
			const parts = {};
			for (const selector of TRIGGER_PART_SELECTORS) {
				const part = trigger.querySelector(selector);
				if (part) {
					parts[selector] = part.innerHTML;
				}
			}
			return parts;
		}

		// Restores markup this widget captured from itself a moment earlier — server-escaped
		// option content, never anything the visitor typed.
		function restoreTriggerState(parts) {
			for (const selector of Object.keys(parts)) {
				const part = trigger.querySelector(selector);
				if (part) {
					part.innerHTML = parts[selector];
				}
			}
		}

		/** Moves the active marker from whichever option held it to this one. */
		function markActive(target) {
			const previous = listbox.querySelector(".fchub-mc-switcher__option--active");
			if (previous) {
				previous.classList.remove("fchub-mc-switcher__option--active");
				previous.setAttribute("aria-selected", "false");
			}

			target.classList.add("fchub-mc-switcher__option--active");
			target.setAttribute("aria-selected", "true");
			return previous;
		}

		/** Copies the chosen option's flag and labels onto the closed trigger. */
		function paintTrigger(target, value) {
			const flag = trigger.querySelector(".fchub-mc-switcher__flag");
			if (flag) {
				const image = buildFlagImg(value);
				if (image) {
					flag.textContent = "";
					flag.appendChild(image);
				} else {
					const optionFlag = target.querySelector(".fchub-mc-switcher__flag");
					if (optionFlag) flag.innerHTML = optionFlag.innerHTML;
				}
			}

			for (const part of ["code", "symbol", "name"]) {
				const destination = trigger.querySelector(`.fchub-mc-switcher__${part}`);
				const source = target.querySelector(`.fchub-mc-switcher__option-${part}`);
				if (destination && source) destination.textContent = source.textContent;
			}
		}

		function selectOption(index) {
			const items = options().filter((option) => option.style.display !== "none");
			const target = items[index];
			if (!target) return;

			const value = target.dataset.value;
			const previousTriggerState = captureTriggerState();
			const currentActive = markActive(target);
			paintTrigger(target, value);

			close();
			switchCurrency(value, {
				root: root,
				onFailure: () => {
					target.classList.remove("fchub-mc-switcher__option--active");
					target.setAttribute("aria-selected", "false");
					if (currentActive) {
						currentActive.classList.add("fchub-mc-switcher__option--active");
						currentActive.setAttribute("aria-selected", "true");
					}
					restoreTriggerState(previousTriggerState);
					trigger.focus();
				},
			});
		}

		// Trigger events
		trigger.addEventListener("click", (e) => {
			e.stopPropagation();
			toggle();
		});

		trigger.addEventListener("keydown", (e) => {
			switch (e.key) {
				case "Enter":
				case " ":
				case "ArrowDown":
					e.preventDefault();
					if (dropdown.hidden) {
						open();
					}
					break;
				case "Escape":
					if (!dropdown.hidden) {
						e.preventDefault();
						close();
					}
					break;
			}
		});

		// Listbox keyboard navigation
		listbox.addEventListener("keydown", (e) => {
			const items = options();
			switch (e.key) {
				case "ArrowDown":
					e.preventDefault();
					setActiveOption(activeIndex + 1);
					break;
				case "ArrowUp":
					e.preventDefault();
					setActiveOption(activeIndex - 1);
					break;
				case "Home":
					e.preventDefault();
					setActiveOption(0);
					break;
				case "End":
					e.preventDefault();
					setActiveOption(items.length - 1);
					break;
				case "Enter":
				case " ":
					e.preventDefault();
					selectOption(activeIndex);
					break;
				case "Escape":
					e.preventDefault();
					close();
					break;
				case "Tab":
					close();
					break;
			}
		});

		// Option click
		listbox.addEventListener("click", (e) => {
			const option = e.target.closest("[role='option']");
			if (option && option.style.display === "none") {
				return;
			}
			if (!option) return;
			const items = options().filter((item) => item.style.display !== "none");
			const idx = items.indexOf(option);
			if (idx >= 0) {
				selectOption(idx);
			}
		});

		// Close on click outside
		document.addEventListener("click", (e) => {
			if (!dropdown.hidden && !root.contains(e.target)) {
				close();
			}
		});

		// Close on focus leaving the widget
		root.addEventListener("focusout", () => {
			requestAnimationFrame(() => {
				if (!root.contains(document.activeElement) && !dropdown.hidden) {
					close();
				}
			});
		});

		if (searchInput) {
			searchInput.addEventListener("input", () => {
				const query = searchInput.value.trim().toLowerCase();
				for (const option of options()) {
					const text = option.textContent ? option.textContent.toLowerCase() : "";
					option.style.display = query === "" || text.includes(query) ? "" : "none";
				}
				activeIndex = getActiveIndex();
				if (!dropdown.hidden) {
					applyDropdownFit();
				}
			});
		}

		window.addEventListener("resize", () => {
			if (!dropdown.hidden) {
				applyDropdownFit();
			}
		});

		window.addEventListener(
			"scroll",
			() => {
				if (!dropdown.hidden) {
					applyDropdownFit();
				}
			},
			true,
		);
	}

	function initAll() {
		ensureAnnouncer();
		const widgets = document.querySelectorAll("[data-fchub-mc-switcher]");
		for (const widget of widgets) {
			initSwitcher(widget);
		}

		document.querySelectorAll("[data-fchub-mc-button-switcher]").forEach((root) => {
			if (root.hasAttribute("data-fchub-mc-enhanced")) {
				return;
			}
			root.setAttribute("data-fchub-mc-enhanced", "");
			root.addEventListener("click", (event) => {
				const button = event.target.closest("[data-value]");
				if (!button) {
					return;
				}

				event.preventDefault();
				const previousActive = [...root.querySelectorAll(".is-active")];
				for (const activeButton of previousActive) {
					activeButton.classList.remove("is-active");
				}
				button.classList.add("is-active");
				switchCurrency(button.dataset.value || "", {
					root: root,
					onFailure: () => {
						button.classList.remove("is-active");
						for (const activeButton of previousActive) {
							activeButton.classList.add("is-active");
						}
					},
				});
			});
		});
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", initAll);
	} else {
		initAll();
	}

	window.fchubMcSwitchCurrency = switchCurrency;
	window.fchubMcInitSwitchers = initAll;
})();
