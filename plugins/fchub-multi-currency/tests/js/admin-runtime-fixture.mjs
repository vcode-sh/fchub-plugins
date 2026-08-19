import { readFileSync } from "node:fs";
import vm from "node:vm";

// The admin surface ships as plain browser scripts; mirror the WordPress
// dependency chain: preview widget, then the per-tab components, then the
// entry file that consumes them.
const SOURCE_FILES = [
	"switcher-preview.js",
	"components/general-settings.js",
	"components/currency-settings.js",
	"components/switcher-settings.js",
	"components/rate-settings.js",
	"components/checkout-settings.js",
	"components/crm-settings.js",
	"components/diagnostics-view.js",
	"multi-currency-page.js",
];

// Mirrors wp.i18n.sprintf closely enough for the admin surface: positional
// (%1$s) and sequential (%s, %d) placeholders.
function sprintf(format, ...args) {
	let cursor = 0;
	return format.replace(/%(\d+\$)?[sd]/g, (_match, position) => {
		const index = position ? Number(position.slice(0, -1)) - 1 : cursor++;
		return String(args[index]);
	});
}

export function loadAdminRuntime({ responses = [], adminConfig = {}, translate = (text) => text } = {}) {
	const requests = [];
	let routeFilter;
	const window = {
		wp: {
			i18n: {
				__: (text, _domain) => translate(text),
				_n: (single, plural, count, _domain) => translate(count === 1 ? single : plural),
				sprintf,
			},
		},
		fchubMcAdmin: {
			nonce: "nonce",
			rest_url: "https://shop.test/wp-json/fchub-mc/v1/",
			currency_catalogue: [],
			...adminConfig,
		},
		fluent_cart_admin: {
			hooks: {
				addFilter(_hook, _namespace, callback) {
					routeFilter = callback;
				},
			},
		},
		location: { hash: "" },
		addEventListener() {},
	};
	const document = {
		readyState: "complete",
		head: { appendChild() {} },
		createElement() {
			return { style: {}, textContent: "" };
		},
		querySelector() {
			return null;
		},
	};
	const fetch = async (url, options) => {
		requests.push({ url, options });
		const response = responses.shift() ?? { data: {} };
		return {
			ok: response.ok ?? true,
			json: async () => response.body ?? response,
		};
	};

	const context = vm.createContext({
		document,
		fetch,
		requestAnimationFrame() {},
		window,
	});
	for (const file of SOURCE_FILES) {
		const source = readFileSync(new URL(`../../admin/${file}`, import.meta.url), "utf8");
		vm.runInContext(source, context, { filename: file });
	}

	const routes = routeFilter({ settings: { children: [] } });
	const page = routes.settings.children[0].component;

	return { page, requests, window };
}

export function pageState(overrides = {}) {
	return {
		$message: {
			error() {},
			success() {},
		},
		loadRates() {},
		manualRates: {},
		manualRatesSaving: false,
		quoteCurrencies: [],
		rates: [],
		ratesLoading: false,
		savedRateProvider: "",
		settings: {},
		...overrides,
	};
}
