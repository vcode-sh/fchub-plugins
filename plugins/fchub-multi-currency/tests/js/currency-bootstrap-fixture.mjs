import { readFileSync } from "node:fs";
import vm from "node:vm";

const scriptPath = new URL("../../assets/js/currency-bootstrap.js", import.meta.url);

const TABLE = {
	USD: { rate: 1, symbol: "$" },
	EUR: { rate: 0.92, symbol: "€" },
};

export function baseConfig(overrides = {}) {
	return {
		currencyTable: TABLE,
		baseCurrency: "USD",
		defaultCurrency: "",
		accountCurrency: "",
		isLoggedIn: false,
		projectionEnabled: true,
		cookieName: "fchub_mc_currency",
		cookiePersistenceEnabled: true,
		cookieLifetimeDays: 90,
		urlParamEnabled: true,
		urlParamKey: "currency",
		...overrides,
	};
}

/**
 * Runs the bootstrap against a minimal fake window and returns what a page would
 * be able to observe afterwards.
 */
export function loadBootstrap({
	config = baseConfig(),
	search = "",
	cookie = "",
	storage = {},
	storageThrows = false,
	intlTimeZone = undefined,
} = {}) {
	const values = new Map(Object.entries(storage));
	const classes = new Set();
	const localStorage = {
		getItem(key) {
			if (storageThrows) throw new Error("blocked");
			return values.has(key) ? values.get(key) : null;
		},
		setItem(key, value) {
			if (storageThrows) throw new Error("blocked");
			values.set(key, String(value));
		},
		removeItem(key) {
			if (storageThrows) throw new Error("blocked");
			values.delete(key);
		},
	};

	const sandbox = {
		window: {
			fchubMcConfig: config,
			localStorage,
			location: { search },
		},
		document: {
			cookie,
			documentElement: {
				classList: {
					toggle: (name, force) => (force ? classes.add(name) : classes.delete(name)),
					contains: (name) => classes.has(name),
				},
			},
		},
		URLSearchParams,
	};

	// Only an explicit test opts into Intl; the default sandbox stays without
	// it, mirroring the exotic embedders the implementation must survive.
	if (intlTimeZone !== undefined) {
		sandbox.Intl = {
			DateTimeFormat: () => ({ resolvedOptions: () => ({ timeZone: intlTimeZone }) }),
		};
	}

	vm.runInNewContext(readFileSync(scriptPath, "utf8"), sandbox, { filename: scriptPath.pathname });

	return {
		api: sandbox.window.fchubMc,
		currency: sandbox.window.fchubMc.currentCurrency(),
		pending: classes.has("fchub-mc-pending"),
		storage: values,
	};
}
