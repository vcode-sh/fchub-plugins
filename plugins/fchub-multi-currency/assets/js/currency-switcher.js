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
		USD: "us", EUR: "eu", GBP: "gb", JPY: "jp", CHF: "ch", CAD: "ca",
		AUD: "au", NZD: "nz", SEK: "se", NOK: "no", DKK: "dk", PLN: "pl",
		CZK: "cz", HUF: "hu", RON: "ro", BGN: "bg", HRK: "hr", ISK: "is",
		TRY: "tr", RUB: "ru", UAH: "ua", BRL: "br", MXN: "mx", ARS: "ar",
		CLP: "cl", COP: "co", PEN: "pe", CNY: "cn", HKD: "hk", SGD: "sg",
		TWD: "tw", KRW: "kr", INR: "in", IDR: "id", MYR: "my", PHP: "ph",
		THB: "th", VND: "vn", AED: "ae", SAR: "sa", QAR: "qa", KWD: "kw",
		BHD: "bh", OMR: "om", ILS: "il", EGP: "eg", ZAR: "za", NGN: "ng",
		KES: "ke", GHS: "gh",
	};

	function buildFlagImg(currencyCode) {
		const country = currencyFlagMap[currencyCode.toUpperCase()];
		if (!country || !flagBaseUrl) return null;
		const img = document.createElement("img");
		img.src = flagBaseUrl + country + ".svg";
		img.alt = currencyCode;
		img.className = "fchub-mc-flag";
		img.width = 20;
		img.height = 15;
		return img;
	}

	let idCounter = 0;

	const FALLBACK_ERROR = "Currency preference could not be saved.";

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

	/**
	 * Pulls the most useful message out of either envelope: the plugin's own
	 * `{data: {message}}` or WordPress's REST error `{code, message, data: {status}}`.
	 */
	function readMessage(payload, status) {
		const data = payload && typeof payload === "object" ? payload.data : null;

		if (data && typeof data.message === "string" && data.message !== "") {
			return data.message;
		}

		if (payload && typeof payload.message === "string" && payload.message !== "") {
			return payload.message;
		}

		return status ? `${FALLBACK_ERROR} (HTTP ${status})` : FALLBACK_ERROR;
	}

	/**
	 * The stable outcome slug the server sends alongside the message — e.g.
	 * "persistence_unavailable", "module_disabled", "rate_limited". Branch on
	 * this, never on the message, which is translated. Falls back to WordPress's
	 * own top-level `code` when the REST layer answers before we do.
	 */
	function readCode(payload) {
		const data = payload && typeof payload === "object" ? payload.data : null;

		if (data && typeof data.code === "string" && data.code !== "") {
			return data.code;
		}

		if (payload && typeof payload.code === "string" && payload.code !== "") {
			return payload.code;
		}

		return "";
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
	 * Posts the preference and only reloads once the server confirms it stored something.
	 * A 403 (plugin disabled), 409 (nothing to persist for this visitor), 422 (bad currency)
	 * or 429 (rate limited) leaves the page alone and surfaces the server's message.
	 */
	function switchCurrency(currencyCode, options) {
		const settings = options || {};
		clearError(settings.root || null);

		return fetch(`${restUrl}/context`, {
			method: "POST",
			headers: {
				"Content-Type": "application/json",
				"X-WP-Nonce": nonce,
			},
			body: JSON.stringify({ currency: currencyCode }),
		})
			.then((response) =>
				response
					.json()
					.catch(() => null)
					.then((payload) => ({ response, payload })),
			)
			.then(({ response, payload }) => {
				const data = payload && typeof payload === "object" ? payload.data : null;
				const persisted = data?.persisted !== false;

				if (!response.ok || !persisted) {
					failSwitch(
						currencyCode,
						readMessage(payload, response.status),
						settings,
						response.status,
						readCode(payload),
					);
					return;
				}

				window.dispatchEvent(
					new CustomEvent("fchub_mc:context_changed", {
						detail: { currency: currencyCode, response: payload },
					}),
				);
				window.location.reload();
			})
			.catch((err) => {
				failSwitch(currencyCode, err?.message || FALLBACK_ERROR, settings, 0, "network_error");
			});
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
			const preferredHorizontal = root.classList.contains("fchub-mc-switcher--dropdown-start")
				? "start"
				: root.classList.contains("fchub-mc-switcher--dropdown-end")
					? "end"
					: "auto";
			const preferredVertical = root.classList.contains("fchub-mc-switcher--direction-up")
				? "up"
				: root.classList.contains("fchub-mc-switcher--direction-down")
					? "down"
					: "auto";

			const availableDown = window.innerHeight - rootRect.bottom - viewportPadding;
			const availableUp = rootRect.top - viewportPadding;

			let vertical = preferredVertical === "auto"
				? (availableDown >= availableUp ? "down" : "up")
				: preferredVertical;
			if (preferredVertical === "down" && availableDown < dropdownRect.height && availableUp > availableDown) {
				vertical = "up";
			}
			if (preferredVertical === "up" && availableUp < dropdownRect.height && availableDown > availableUp) {
				vertical = "down";
			}

			if (vertical === "up") {
				dropdown.style.top = "auto";
				dropdown.style.bottom = "calc(100% + 4px)";
			} else {
				dropdown.style.top = "calc(100% + 4px)";
				dropdown.style.bottom = "auto";
			}

			const width = dropdownRect.width;
			const candidateStart = rootRect.left;
			const candidateEnd = rootRect.right - width;

			function overflowScore(left) {
				const overflowLeft = Math.max(0, viewportPadding - left);
				const overflowRight = Math.max(0, left + width - (window.innerWidth - viewportPadding));
				return overflowLeft + overflowRight;
			}

			let left = preferredHorizontal === "start"
				? candidateStart
				: preferredHorizontal === "end"
					? candidateEnd
					: candidateStart;
			const alternateLeft = preferredHorizontal === "start"
				? candidateEnd
				: preferredHorizontal === "end"
					? candidateStart
					: candidateEnd;
			if (preferredHorizontal === "auto" || overflowScore(alternateLeft) < overflowScore(left)) {
				left = alternateLeft;
			}

			const clampedLeft = Math.min(
				Math.max(left, viewportPadding),
				Math.max(viewportPadding, window.innerWidth - viewportPadding - width),
			);

			dropdown.style.left = `${clampedLeft - rootRect.left}px`;
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

		function selectOption(index) {
			const items = options().filter((option) => option.style.display !== "none");
			const target = items[index];
			if (!target) return;

			const value = target.dataset.value;
			const previousTriggerState = captureTriggerState();
			const currentActive = listbox.querySelector(".fchub-mc-switcher__option--active");
			if (currentActive) {
				currentActive.classList.remove("fchub-mc-switcher__option--active");
				currentActive.setAttribute("aria-selected", "false");
			}
			target.classList.add("fchub-mc-switcher__option--active");
			target.setAttribute("aria-selected", "true");

			// Update trigger display
			const triggerFlag = trigger.querySelector(".fchub-mc-switcher__flag");
			const triggerCode = trigger.querySelector(".fchub-mc-switcher__code");
			const triggerSymbol = trigger.querySelector(".fchub-mc-switcher__symbol");
			const triggerName = trigger.querySelector(".fchub-mc-switcher__name");
			const optionCode = target.querySelector(".fchub-mc-switcher__option-code");
			const optionSymbol = target.querySelector(".fchub-mc-switcher__option-symbol");
			const optionName = target.querySelector(".fchub-mc-switcher__option-name");
			if (triggerFlag) {
				const flagImg = buildFlagImg(value);
				if (flagImg) {
					triggerFlag.textContent = "";
					triggerFlag.appendChild(flagImg);
				} else {
					const optionFlag = target.querySelector(".fchub-mc-switcher__flag");
					if (optionFlag) {
						triggerFlag.innerHTML = optionFlag.innerHTML;
					}
				}
			}
			if (triggerCode && optionCode) {
				triggerCode.textContent = optionCode.textContent;
			}
			if (triggerSymbol && optionSymbol) {
				triggerSymbol.textContent = optionSymbol.textContent;
			}
			if (triggerName && optionName) {
				triggerName.textContent = optionName.textContent;
			}

			close();
			root.classList.add("fchub-mc-switcher--loading");
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

		window.addEventListener("scroll", () => {
			if (!dropdown.hidden) {
				applyDropdownFit();
			}
		}, true);
	}

	function initAll() {
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
