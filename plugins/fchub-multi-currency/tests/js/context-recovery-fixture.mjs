import { readFileSync } from "node:fs";
import vm from "node:vm";

const scriptPath = new URL("../../assets/js/currency-context.js", import.meta.url);

export class FakeClassList {
	constructor(...names) {
		this.names = new Set(names);
	}

	add(name) {
		this.names.add(name);
	}

	remove(name) {
		this.names.delete(name);
	}

	toggle(name, force) {
		if (force === undefined ? !this.names.has(name) : force) {
			this.names.add(name);
			return true;
		}

		this.names.delete(name);
		return false;
	}

	contains(name) {
		return this.names.has(name);
	}
}

export function element({ attributes = {}, children = {}, lists = {}, classes = [] } = {}) {
	return {
		attributes: { ...attributes },
		children,
		lists,
		classList: new FakeClassList(...classes),
		innerHTML: "",
		textContent: "",
		getAttribute(name) {
			return this.attributes[name] ?? null;
		},
		setAttribute(name, value) {
			this.attributes[name] = String(value);
		},
		querySelector(selector) {
			return this.children[selector] ?? null;
		},
		querySelectorAll(selector) {
			return this.lists[selector] ?? [];
		},
	};
}

export function recoveryContext(overrides = {}) {
	return {
		rate: 0.92,
		displayCurrency: "EUR",
		displayCurrencyName: "Euro",
		baseCurrency: "USD",
		decimals: 2,
		symbol: "€",
		position: "right_space",
		isBaseDisplay: false,
		resolverSource: "cookie",
		displayDecSep: ",",
		displayThousandSep: ".",
		disclosureEnabled: true,
		disclosureText: "Charged in USD.",
		presentation: {
			flag: '<img alt="EUR" src="eur.svg">',
			current: { symbol_code: "<span>€ EUR</span>" },
			rate: { compact: { 4: "<span>1 USD = 0.9200 EUR</span>" } },
			notice: { compact: "<span>Viewing EUR</span>" },
			switcher: {
				rateBadge: "<span>Fresh</span>",
				rateValue: "<span>1 USD = 0.92000000 EUR</span>",
				contextNote: "<span>Checkout in USD</span>",
			},
		},
		...overrides,
	};
}

export async function runRecovery({
	config = {},
	cookie = "",
	search = "",
	responseContext,
	response = {},
	selectors = {},
	onFetch,
} = {}) {
	const fetchCalls = [];
	const warnings = [];
	const initialConfig = {
		restUrl: "https://shop.test/wp-json/fchub-mc/v1/",
		cookieName: "fchub_mc_currency",
		cookiePersistenceEnabled: true,
		accountPersistenceEnabled: true,
		isLoggedIn: false,
		resolverSource: "default",
		urlParamEnabled: true,
		urlParamKey: "currency",
		allowedCurrencyCodes: ["USD", "EUR"],
		displayCurrency: "USD",
		baseCurrency: "USD",
		...config,
	};
	const document = {
		cookie,
		querySelectorAll(selector) {
			return selectors[selector] ?? [];
		},
	};
	const forbiddenStorage = new Proxy({}, {
		get() {
			throw new Error("Recovery must not create a second preference store");
		},
	});
	const window = {
		fchubMcConfig: initialConfig,
		location: { search },
		localStorage: forbiddenStorage,
	};
	const sandbox = {
		window,
		document,
		URLSearchParams,
		fetch: async (url, options) => {
			fetchCalls.push({ url, options });
			if (typeof onFetch === "function") await onFetch({ document, window });

			return {
				ok: true,
				status: 200,
				...response,
				json: async () => ({ data: { context: responseContext ?? recoveryContext() } }),
			};
		},
		console: { warn: (...args) => warnings.push(args) },
		localStorage: forbiddenStorage,
	};

	vm.runInNewContext(readFileSync(scriptPath, "utf8"), sandbox, { filename: scriptPath.pathname });
	await window.fchubMcContextReady;

	return { config: initialConfig, document, fetchCalls, warnings, window };
}
