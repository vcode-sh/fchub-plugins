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
	storage = {},
	storageError = null,
	search = "",
	responseContext,
	response = {},
	selectors = {},
	onFetch,
} = {}) {
	const fetchCalls = [];
	const replacedUrls = [];
	const warnings = [];
	const storageCalls = [];
	const eventListeners = new Map();
	const storageValues = new Map(Object.entries(storage));
	const initialConfig = {
		restUrl: "https://shop.test/wp-json/fchub-mc/v1/",
		cookieName: "fchub_mc_currency",
		cookiePersistenceEnabled: true,
		cookieLifetimeDays: 90,
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
		addEventListener(type, listener, options) {
			const listeners = eventListeners.get(type) || [];
			listeners.push({ listener, options });
			eventListeners.set(type, listeners);
		},
		dispatchEvent(event) {
			for (const { listener } of eventListeners.get(event.type) || []) {
				listener(event);
			}
		},
		querySelectorAll(selector) {
			return selectors[selector] ?? [];
		},
	};
	const localStorage = {
		getItem(key) {
			storageCalls.push({ method: "getItem", key });
			if (storageError) throw storageError;
			return storageValues.has(key) ? storageValues.get(key) : null;
		},
		setItem(key, value) {
			storageCalls.push({ method: "setItem", key, value: String(value) });
			if (storageError) throw storageError;
			storageValues.set(key, String(value));
		},
		removeItem(key) {
			storageCalls.push({ method: "removeItem", key });
			if (storageError) throw storageError;
			storageValues.delete(key);
		},
	};
	const location = {
		href: `https://shop.test/product${search}#plans`,
		search,
	};
	const history = {
		state: { cartDrawer: "open" },
		replaceState(state, _title, url) {
			replacedUrls.push(url);
			this.state = state;
			const resolved = new URL(url);
			location.href = resolved.toString();
			location.search = resolved.search;
		},
	};
	const window = {
		fchubMcConfig: initialConfig,
		history,
		location,
		localStorage,
	};
	const sandbox = {
		window,
		document,
		URL,
		URLSearchParams,
		fetch: async (url, options) => {
			fetchCalls.push({ url, options });
			if (typeof onFetch === "function") {
				await onFetch({ document, localStorage, storageValues, window });
			}

			return {
				ok: true,
				status: 200,
				...response,
				json: async () => ({ data: { context: responseContext ?? recoveryContext() } }),
			};
		},
		console: { warn: (...args) => warnings.push(args) },
		localStorage,
	};

	vm.runInNewContext(readFileSync(scriptPath, "utf8"), sandbox, { filename: scriptPath.pathname });
	await window.fchubMcContextReady;

	return {
		config: initialConfig,
		document,
		eventListeners,
		fetchCalls,
		localStorage,
		replacedUrls,
		storageCalls,
		storageValues,
		warnings,
		window,
	};
}
