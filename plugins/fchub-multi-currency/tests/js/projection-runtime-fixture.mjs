import { readFileSync } from "node:fs";
import vm from "node:vm";

const source = readFileSync(new URL("../../assets/js/currency-projection.js", import.meta.url), "utf8");

export class FakeClassList {
	constructor(names = []) {
		this.names = new Set(names);
	}

	add(name) {
		this.names.add(name);
	}

	remove(name) {
		this.names.delete(name);
	}

	contains(name) {
		return this.names.has(name);
	}

	toggle(name, force) {
		if (force === undefined ? !this.names.has(name) : force) {
			this.names.add(name);
			return true;
		}

		this.names.delete(name);
		return false;
	}
}

export function textNode(text) {
	return { nodeType: 3, textContent: text };
}

export function projectionElement({
	attributes = {},
	children = {},
	classes = [],
	childNodes = [],
	innerHTML,
	lists = {},
	text = "",
	total = false,
	value = "",
} = {}) {
	const storedAttributes = new Map(Object.entries(attributes));
	let markup = innerHTML ?? text;
	let content = text;

	return {
		classList: new FakeClassList(classes),
		childNodes,
		get innerHTML() {
			return markup;
		},
		set innerHTML(value) {
			markup = value;
			content = value;
		},
		get textContent() {
			return content;
		},
		set textContent(value) {
			content = value;
			markup = value;
		},
		value,
		getAttribute(name) {
			return storedAttributes.get(name) ?? null;
		},
		setAttribute(name, nextValue) {
			storedAttributes.set(name, String(nextValue));
		},
		removeAttribute(name) {
			storedAttributes.delete(name);
		},
		matches() {
			return total;
		},
		querySelector(selector) {
			return children[selector] ?? null;
		},
		querySelectorAll(selector) {
			return lists[selector] ?? [];
		},
	};
}

export async function runProjection({ config = {}, priceElements = [], selectors = {} } = {}) {
	const classes = new Set();
	const documentEvents = [];
	const timers = [];
	const windowListeners = new Map();
	const document = {
		body: {},
		readyState: "complete",
		documentElement: {
			classList: {
				add: (name) => classes.add(name),
				remove: (name) => classes.delete(name),
			},
		},
		addEventListener() {},
		createElement() {
			return projectionElement();
		},
		dispatchEvent(event) {
			documentEvents.push(event);
		},
		querySelector() {
			return null;
		},
		querySelectorAll(selector) {
			if (selector.includes(".fct-item-price") && selector.includes("[data-fchub-mc-base]")) {
				return priceElements;
			}
			if (selector === "[data-fchub-mc-projected]") {
				return priceElements.filter((el) => el.getAttribute("data-fchub-mc-projected"));
			}
			return selectors[selector] ?? [];
		},
	};
	// The runtime takes its display settings from the currency table now, the way a
	// page does, so the fixture describes the same world rather than a shortcut.
	const settings = {
			baseCurrency: "USD",
			baseCurrencyCode: "USD",
			baseCurrencySign: "$",
			baseDecimalSep: ".",
			baseThousandSep: ",",
			decimals: 2,
			disclosureEnabled: false,
			displayCurrency: "EUR",
			displayDecSep: ".",
			displayThousandSep: ",",
			isBaseDisplay: false,
			position: "left",
			rate: "2",
			roundingMode: "half_up",
			symbol: "€",
			...config,
	};
	const displayCurrency = settings.displayCurrency;
	const window = {
		fchubMc: {
			currentCurrency: () => displayCurrency,
			// The bootstrap owns writing a currency's facts into the live config; the
			// projection runtime asks it rather than merging the table itself.
			select: (code) => {
				const entry = window.fchubMcConfig.currencyTable[code];
				if (!entry) return false;

				Object.assign(window.fchubMcConfig, entry, {
					displayCurrency: code,
					isBaseDisplay: code === window.fchubMcConfig.baseCurrency,
				});
				return true;
			},
		},
		fchubMcConfig: {
			...settings,
			baseCurrency: settings.baseCurrency,
			currencyTable: { [displayCurrency]: settings, ...(settings.currencyTable || {}) },
		},
		addEventListener(name, callback) {
			const listeners = windowListeners.get(name) ?? [];
			listeners.push(callback);
			windowListeners.set(name, listeners);
		},
	};
	const observers = [];
	class FakeMutationObserver {
		constructor(callback) {
			this.callback = callback;
			this.observed = [];
			observers.push(this);
		}

		observe(target, options) {
			this.observed.push({ target, options });
		}

		disconnect() {}

		deliver(mutations) {
			this.callback(mutations);
		}
	}

	const execution = vm.runInNewContext(source, {
		CustomEvent: class CustomEvent {
			constructor(type, options = {}) {
				this.type = type;
				this.detail = options.detail;
			}
		},
		MutationObserver: FakeMutationObserver,
		clearTimeout(id) {
			const timer = timers.find((candidate) => candidate.id === id);
			if (timer) timer.cancelled = true;
		},
		document,
		setTimeout(callback, delay) {
			const timer = { callback, cancelled: false, delay, id: timers.length + 1 };
			timers.push(timer);
			return timer.id;
		},
		window,
	}, { filename: "currency-projection.js" });

	await execution;

	return { classes, documentEvents, observers, timers, window, windowListeners };
}

export async function projectRenderedPrice({ config, price }) {
	const projected = projectionElement({ classes: ["fct-item-price"], text: price });
	const runtime = await runProjection({ config, priceElements: [projected] });

	return { ...runtime, projected };
}
