import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { describe, it } from "node:test";
import vm from "node:vm";

const contextSource = readFileSync(
	new URL("../../assets/js/currency-context.js", import.meta.url),
	"utf8",
);

/**
 * The page as PHP ships it: config carries the currency table but no
 * `displayCurrency`, because that answer belongs to the browser. These tests
 * pin what the label runtime does at load — the moment the projection runtime
 * is absent (store with js_projection off) and nothing has called select() yet.
 */
function element({ tag = "span", text = "", attributes = {}, children = {} } = {}) {
	const attrs = new Map(Object.entries(attributes));
	const classes = new Set();
	return {
		tagName: tag.toUpperCase(),
		textContent: text,
		innerHTML: text,
		classList: {
			toggle: (name, force) => {
				const on = force === undefined ? !classes.has(name) : force;
				on ? classes.add(name) : classes.delete(name);
			},
			add: (name) => classes.add(name),
			remove: (name) => classes.delete(name),
			contains: (name) => classes.has(name),
		},
		getAttribute: (name) => attrs.get(name) ?? null,
		setAttribute: (name, value) => attrs.set(name, String(value)),
		querySelector(selector) {
			return children[selector] ?? null;
		},
		querySelectorAll(selector) {
			const found = children[selector];
			return Array.isArray(found) ? found : found ? [found] : [];
		},
	};
}

function serverRenderedSwitcher() {
	const codeLabel = element({ text: "USD" });
	const nameLabel = element({ text: "US Dollar" });
	const usdOption = element({ attributes: { "data-value": "USD", "aria-selected": "true" } });
	const eurOption = element({ attributes: { "data-value": "EUR", "aria-selected": "false" } });
	const trigger = element({
		children: {
			".fchub-mc-switcher__code": codeLabel,
			".fchub-mc-switcher__name": nameLabel,
		},
	});
	const root = element({
		attributes: { "data-fchub-mc-switcher": "" },
		children: {
			"[data-fchub-mc-trigger]": trigger,
			"[role='option'][data-value]": [usdOption, eurOption],
		},
	});

	return { root, codeLabel, nameLabel, usdOption, eurOption };
}

function loadContextRuntime({ config, fchubMc }) {
	const dom = serverRenderedSwitcher();
	const documentSelectors = {
		"[data-fchub-mc-switcher]": [dom.root],
	};

	const sandbox = {
		window: { fchubMcConfig: config, fchubMc },
		document: {
			readyState: "complete",
			addEventListener() {},
			querySelectorAll: (selector) => documentSelectors[selector] ?? [],
		},
		console,
	};

	vm.runInNewContext(contextSource, sandbox, { filename: "currency-context.js" });

	return { dom, sandbox };
}

describe("label runtime at page load, without the projection runtime", () => {
	it("leaves the server-rendered switcher untouched when nothing is resolved", () => {
		const { dom } = loadContextRuntime({
			config: { currencyTable: {}, baseCurrency: "USD" },
			fchubMc: {
				currentCurrency: () => "",
				select: () => false,
			},
		});

		assert.equal(dom.codeLabel.textContent, "USD");
		assert.equal(dom.nameLabel.textContent, "US Dollar");
		assert.equal(dom.usdOption.getAttribute("aria-selected"), "true");
	});

	it("paints the currency the bootstrap resolved, so a stored preference survives a reload", () => {
		const config = {
			baseCurrency: "USD",
			currencyTable: {
				USD: { symbol: "$", displayCurrencyName: "US Dollar" },
				EUR: { symbol: "€", displayCurrencyName: "Euro" },
			},
		};

		const { dom } = loadContextRuntime({
			config,
			fchubMc: {
				currentCurrency: () => "EUR",
				select: (code) => {
					if (!Object.hasOwn(config.currencyTable, code)) return false;
					Object.assign(config, config.currencyTable[code], {
						displayCurrency: code,
						isBaseDisplay: code === "USD",
					});
					return true;
				},
			},
		});

		assert.equal(dom.codeLabel.textContent, "EUR");
		assert.equal(dom.eurOption.getAttribute("aria-selected"), "true");
		assert.equal(dom.usdOption.getAttribute("aria-selected"), "false");
	});
});

/**
 * The guarantee issue #72's reporter verified by hand: the currency a visitor
 * chose rides inside FluentCart's own checkout form. Two moments matter — the
 * page paint, and the capture-phase submit listener that corrects the field
 * an instant before FluentCart serialises the form. Break either and orders
 * record the cache-warmer's currency, not the customer's.
 */
describe("checkout field carries the browser's currency", () => {
	function checkoutField(initial = "") {
		return { value: initial };
	}

	function loadWithSubmitCapture({ config, fchubMc, documentFields = [] }) {
		let submitListener = null;
		const sandbox = {
			window: { fchubMcConfig: config, fchubMc },
			document: {
				readyState: "complete",
				addEventListener(type, handler, capture) {
					if (type === "submit" && capture === true) submitListener = handler;
				},
				querySelectorAll: (selector) =>
					selector === "[data-fchub-mc-checkout-currency]" ? documentFields : [],
			},
			console,
		};

		vm.runInNewContext(contextSource, sandbox, { filename: "currency-context.js" });

		return { sandbox, submit: (target) => submitListener?.({ target }) };
	}

	function configWithTable() {
		const config = {
			baseCurrency: "USD",
			currencyTable: {
				USD: { symbol: "$", displayCurrencyName: "US Dollar" },
				EUR: { symbol: "€", displayCurrencyName: "Euro" },
				PLN: { symbol: "zł", displayCurrencyName: "Polish Złoty" },
			},
		};
		const fchubMc = {
			currentCurrency: () => "EUR",
			select: (code) => {
				if (!Object.hasOwn(config.currencyTable, code)) return false;
				Object.assign(config, config.currencyTable[code], {
					displayCurrency: code,
					isBaseDisplay: code === "USD",
				});
				return true;
			},
		};
		return { config, fchubMc };
	}

	it("stamps the resolved currency into checkout fields at paint time", () => {
		const { config, fchubMc } = configWithTable();
		const field = checkoutField("USD");

		loadWithSubmitCapture({ config, fchubMc, documentFields: [field] });

		assert.equal(field.value, "EUR", "The server-rendered base value is corrected on paint.");
	});

	it("corrects the submitted form's field an instant before FluentCart reads it", () => {
		const { config, fchubMc } = configWithTable();
		const { submit } = loadWithSubmitCapture({ config, fchubMc });

		fchubMc.select("PLN");
		const staleField = checkoutField("EUR");
		const form = {
			querySelectorAll: (selector) =>
				selector === "[data-fchub-mc-checkout-currency]" ? [staleField] : [],
		};

		submit(form);

		assert.equal(staleField.value, "PLN", "The order records what the customer is looking at.");
	});

	it("leaves the field alone when nothing valid is selected", () => {
		const { config, fchubMc } = configWithTable();
		config.displayCurrency = undefined;
		fchubMc.currentCurrency = () => "";
		const { submit } = loadWithSubmitCapture({ config, fchubMc });

		const field = checkoutField("USD");
		submit({
			querySelectorAll: (selector) =>
				selector === "[data-fchub-mc-checkout-currency]" ? [field] : [],
		});

		assert.equal(field.value, "USD", "No resolved currency, no rewrite.");
	});
});
