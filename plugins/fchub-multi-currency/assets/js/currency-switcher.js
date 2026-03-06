/**
 * FCHub Multi-Currency — Currency Switcher Widget
 *
 * Custom dropdown with flag emojis, ARIA listbox, keyboard navigation.
 *
 * Fires: fchub_mc:context_changed
 */
(() => {
	const config = window.fchubMcConfig || {};
	const restUrl = config.restUrl || "/wp-json/fchub-mc/v1";
	const nonce = config.nonce || "";

	let idCounter = 0;

	function switchCurrency(currencyCode) {
		return fetch(`${restUrl}/context`, {
			method: "POST",
			headers: {
				"Content-Type": "application/json",
				"X-WP-Nonce": nonce,
			},
			body: JSON.stringify({ currency: currencyCode }),
		})
			.then((response) => response.json())
			.then((data) => {
				document.dispatchEvent(
					new CustomEvent("fchub_mc:context_changed", {
						detail: { currency: currencyCode, response: data },
					}),
				);
				window.location.reload();
			})
			.catch((err) => {
				console.warn("[fchub-mc] Currency switch failed:", err);
				document.querySelectorAll(".fchub-mc-switcher--loading").forEach((el) => {
					el.classList.remove("fchub-mc-switcher--loading");
				});
			});
	}

	function initSwitcher(root) {
		if (root.hasAttribute("data-fchub-mc-enhanced")) {
			return;
		}
		root.setAttribute("data-fchub-mc-enhanced", "");

		const trigger = root.querySelector("[data-fchub-mc-trigger]");
		const dropdown = root.querySelector("[data-fchub-mc-dropdown]");
		const listbox = dropdown.querySelector("[role='listbox']");
		const options = () => [...listbox.querySelectorAll("[role='option']")];

		// ARIA linkage
		const uid = `fchub-mc-${++idCounter}`;
		const listboxId = `${uid}-listbox`;
		listbox.id = listboxId;
		trigger.setAttribute("role", "combobox");
		trigger.setAttribute("aria-haspopup", "listbox");
		trigger.setAttribute("aria-expanded", "false");
		trigger.setAttribute("aria-controls", listboxId);

		let activeIndex = -1;

		function getActiveIndex() {
			const items = options();
			const idx = items.findIndex((o) => o.getAttribute("aria-selected") === "true");
			return idx >= 0 ? idx : 0;
		}

		function open() {
			dropdown.hidden = false;
			root.classList.add("fchub-mc-switcher--open");
			trigger.setAttribute("aria-expanded", "true");
			activeIndex = getActiveIndex();
			setActiveOption(activeIndex);
		}

		function close() {
			dropdown.hidden = true;
			root.classList.remove("fchub-mc-switcher--open");
			trigger.setAttribute("aria-expanded", "false");
			trigger.removeAttribute("aria-activedescendant");
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
			const items = options();
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

		function selectOption(index) {
			const items = options();
			const target = items[index];
			if (!target) return;

			const value = target.dataset.value;
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
			const optionFlag = target.querySelector(".fchub-mc-switcher__flag");
			const optionCode = target.querySelector(".fchub-mc-switcher__option-code");
			if (triggerFlag && optionFlag) {
				triggerFlag.textContent = optionFlag.textContent;
			}
			if (triggerCode && optionCode) {
				triggerCode.textContent = optionCode.textContent;
			}

			close();
			root.classList.add("fchub-mc-switcher--loading");
			switchCurrency(value);
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
			if (!option) return;
			const items = options();
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
	}

	function initAll() {
		const widgets = document.querySelectorAll("[data-fchub-mc-switcher]");
		for (const widget of widgets) {
			initSwitcher(widget);
		}
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", initAll);
	} else {
		initAll();
	}

	window.fchubMcSwitchCurrency = switchCurrency;
	window.fchubMcInitSwitchers = initAll;
})();
