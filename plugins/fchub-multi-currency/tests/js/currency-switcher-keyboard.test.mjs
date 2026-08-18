import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { describe, it } from "node:test";
import vm from "node:vm";

const switcherSource = readFileSync(
	new URL("../../assets/js/currency-switcher.js", import.meta.url),
	"utf8",
);

/**
 * Keyboard contract for the dropdown switcher.
 *
 * Focus stays on the trigger (or in the search box) the whole time —
 * aria-activedescendant pattern — so every key a visitor presses lands there,
 * never on the listbox. These tests dispatch keys exactly where a keyboard
 * user's focus really is.
 */
function makeElement({ attributes = {}, display = "" } = {}) {
	const attrs = new Map(Object.entries(attributes));
	const classes = new Set();
	const listeners = new Map();
	const el = {
		id: "",
		value: "",
		textContent: "",
		innerHTML: "",
		style: { display, left: "", right: "", top: "", bottom: "", maxWidth: "" },
		classList: {
			add: (name) => classes.add(name),
			remove: (name) => classes.delete(name),
			toggle: (name, force) => {
				const on = force === undefined ? !classes.has(name) : force;
				on ? classes.add(name) : classes.delete(name);
			},
			contains: (name) => classes.has(name),
		},
		dataset: {},
		children: {},
		hasAttribute: (name) => attrs.has(name),
		getAttribute: (name) => attrs.get(name) ?? null,
		setAttribute: (name, value) => attrs.set(name, String(value)),
		removeAttribute: (name) => attrs.delete(name),
		querySelector(selector) {
			return this.children[selector] ?? null;
		},
		querySelectorAll(selector) {
			const found = this.children[selector];
			return Array.isArray(found) ? found : found ? [found] : [];
		},
		addEventListener(type, handler) {
			if (!listeners.has(type)) listeners.set(type, []);
			listeners.get(type).push(handler);
		},
		dispatch(type, event = {}) {
			const prepared = {
				key: "",
				target: el,
				preventDefault() {
					prepared.defaultPrevented = true;
				},
				stopPropagation() {},
				defaultPrevented: false,
				...event,
			};
			for (const handler of listeners.get(type) ?? []) {
				handler(prepared);
			}
			return prepared;
		},
		focus() {},
		scrollIntoView() {},
		getBoundingClientRect: () => ({ top: 100, bottom: 130, left: 10, right: 150, width: 140, height: 30 }),
		closest: () => null,
	};
	return el;
}

function makeOption(code) {
	const option = makeElement({ attributes: { role: "option", "data-value": code, "aria-selected": "false" } });
	option.dataset.value = code;
	option.textContent = code;
	return option;
}

function buildSwitcher({ withSearch = false } = {}) {
	const options = ["USD", "EUR", "PLN"].map(makeOption);
	const listbox = makeElement({ attributes: { role: "listbox" } });
	listbox.children["[role='option']"] = options;

	const dropdown = makeElement();
	dropdown.hidden = true;
	dropdown.children["[role='listbox']"] = listbox;

	const trigger = makeElement();
	const search = withSearch ? makeElement() : null;

	const root = makeElement();
	root.children["[data-fchub-mc-trigger]"] = trigger;
	root.children["[data-fchub-mc-dropdown]"] = dropdown;
	if (search) root.children["[data-fchub-mc-search]"] = search;

	return { root, trigger, dropdown, listbox, options, search };
}

function loadSwitcherRuntime(widgets) {
	const applied = [];
	const sandbox = {
		window: {
			fchubMcConfig: {
				restUrl: "https://shop.test/wp-json/fchub-mc/v1",
				isLoggedIn: false,
				currencyTable: { USD: {}, EUR: {}, PLN: {} },
			},
			fchubMc: { setCurrency: () => true, select: () => true },
			fchubMcApplyCurrency: (code) => {
				applied.push(code);
				return true;
			},
			dispatchEvent() {},
			addEventListener() {},
			innerHeight: 800,
			innerWidth: 1200,
		},
		document: {
			readyState: "complete",
			addEventListener() {},
			querySelectorAll: (selector) =>
				selector === "[data-fchub-mc-switcher]" ? widgets : [],
			getElementById: () => null,
			body: { appendChild() {} },
			createElement: () => makeElement(),
		},
		fetch: () => Promise.resolve({ ok: true, json: () => Promise.resolve({}) }),
		console: { warn() {}, error() {} },
		CustomEvent: class {
			constructor(type, options = {}) {
				this.type = type;
				this.detail = options.detail;
			}
		},
	};

	vm.runInNewContext(switcherSource, sandbox, { filename: "currency-switcher.js" });

	return { applied };
}

describe("dropdown keyboard navigation from the trigger", () => {
	it("moves the active option with ArrowDown while the dropdown is open", () => {
		const widget = buildSwitcher();
		loadSwitcherRuntime([widget.root]);

		widget.trigger.dispatch("keydown", { key: "Enter" });
		assert.equal(widget.dropdown.hidden, false, "Enter on the trigger opens the dropdown");

		widget.trigger.dispatch("keydown", { key: "ArrowDown" });

		assert.equal(
			widget.options[1].classList.contains("fchub-mc-switcher__option--focused"),
			true,
			"ArrowDown moves the visual focus to the second option",
		);
		assert.equal(
			widget.trigger.getAttribute("aria-activedescendant"),
			widget.options[1].id,
			"aria-activedescendant follows the active option",
		);
	});

	it("selects the active option with Enter", () => {
		const widget = buildSwitcher();
		const { applied } = loadSwitcherRuntime([widget.root]);

		widget.trigger.dispatch("keydown", { key: "Enter" });
		widget.trigger.dispatch("keydown", { key: "ArrowDown" });
		widget.trigger.dispatch("keydown", { key: "Enter" });

		assert.deepEqual(applied, ["EUR"], "Enter applies the option the arrows landed on");
		assert.equal(widget.dropdown.hidden, true, "the dropdown closes after selecting");
	});

	it("jumps to the last option with End and the first with Home", () => {
		const widget = buildSwitcher();
		loadSwitcherRuntime([widget.root]);

		widget.trigger.dispatch("keydown", { key: "Enter" });
		widget.trigger.dispatch("keydown", { key: "End" });
		assert.equal(widget.options[2].classList.contains("fchub-mc-switcher__option--focused"), true);

		widget.trigger.dispatch("keydown", { key: "Home" });
		assert.equal(widget.options[0].classList.contains("fchub-mc-switcher__option--focused"), true);
	});
});

describe("dropdown keyboard navigation from the search box", () => {
	it("navigates and selects without leaving the search box", () => {
		const widget = buildSwitcher({ withSearch: true });
		const { applied } = loadSwitcherRuntime([widget.root]);

		widget.trigger.dispatch("keydown", { key: "Enter" });
		widget.search.dispatch("keydown", { key: "ArrowDown" });
		widget.search.dispatch("keydown", { key: "Enter" });

		assert.deepEqual(applied, ["EUR"]);
		assert.equal(widget.dropdown.hidden, true);
	});

	it("leaves typing keys alone so the visitor can actually search", () => {
		const widget = buildSwitcher({ withSearch: true });
		loadSwitcherRuntime([widget.root]);

		widget.trigger.dispatch("keydown", { key: "Enter" });
		const spaceEvent = widget.search.dispatch("keydown", { key: " " });
		const homeEvent = widget.search.dispatch("keydown", { key: "Home" });

		assert.equal(spaceEvent.defaultPrevented, false, "Space types a space");
		assert.equal(homeEvent.defaultPrevented, false, "Home moves the caret");
	});
});
