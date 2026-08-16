import { readFileSync } from "node:fs";
import vm from "node:vm";

const source = readFileSync(new URL("../../admin/multi-currency-admin.js", import.meta.url), "utf8");

export function loadAdminRuntime({ responses = [] } = {}) {
	const requests = [];
	let routeFilter;
	const window = {
		fchubMcAdmin: {
			nonce: "nonce",
			rest_url: "https://shop.test/wp-json/fchub-mc/v1/",
			currency_catalogue: [],
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

	vm.runInNewContext(source, {
		document,
		fetch,
		requestAnimationFrame() {},
		window,
	}, { filename: "multi-currency-admin.js" });

	const routes = routeFilter({ settings: { children: [] } });
	const page = routes.settings.children[0].component;

	return { page, requests };
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
