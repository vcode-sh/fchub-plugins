import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { describe, it } from "node:test";
import vm from "node:vm";

import { FakeClassList, recoveryContext, runRecovery } from "./context-recovery-fixture.mjs";

const contextSource = readFileSync(
	new URL("../../assets/js/currency-context.js", import.meta.url),
	"utf8",
);
const switcherSource = readFileSync(
	new URL("../../assets/js/currency-switcher.js", import.meta.url),
	"utf8",
);

async function runSwitch({
	config = {},
	response = { ok: true, status: 200 },
	payload,
	historyError,
	storageError,
	currency = "USD",
} = {}) {
	const fetchCalls = [];
	const events = [];
	const historyStates = [];
	const replacedUrls = [];
	const storageCalls = [];
	const storageValues = new Map();
	let reloads = 0;
	const location = {
		href: "https://shop.test/product?money=EUR&utm_source=test#plans",
		search: "?money=EUR&utm_source=test",
		reload() {
			reloads++;
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
	const window = {
		fchubMcConfig: {
			restUrl: "https://shop.test/wp-json/fchub-mc/v1",
			nonce: "nonce",
			cookieName: "fchub_mc_currency",
			cookiePersistenceEnabled: true,
			cookieLifetimeDays: 90,
			allowedCurrencyCodes: ["USD", "EUR"],
			isLoggedIn: false,
			urlParamEnabled: true,
			urlParamKey: "money",
			...config,
		},
		location,
		localStorage,
		history: {
			state: { cartDrawer: "open" },
			replaceState(state, _title, url) {
				if (historyError) throw historyError;
				location.href = String(url);
				historyStates.push(state);
				replacedUrls.push(location.href);
			},
		},
		dispatchEvent(event) {
			events.push(event);
		},
		addEventListener() {},
	};
	const document = {
		cookie: "",
		readyState: "complete",
		documentElement: { classList: new FakeClassList() },
		querySelectorAll() {
			return [];
		},
		addEventListener() {},
	};
	const sandbox = {
		window,
		document,
		URL,
		URLSearchParams,
		CustomEvent: class CustomEvent {
			constructor(type, options = {}) {
				this.type = type;
				this.detail = options.detail;
			}
		},
		fetch: async (url, options) => {
			fetchCalls.push({ url, options });
			if (options.method === "GET") {
				return {
					ok: true,
					status: 200,
					json: async () => ({
						data: { context: recoveryContext({ resolverSource: "url_param" }) },
					}),
				};
			}

			return {
				...response,
				json: async () => payload ?? { data: { persisted: true } },
			};
		},
		console: { warn() {} },
		requestAnimationFrame(callback) {
			callback();
		},
	};

	vm.runInNewContext(contextSource, sandbox, { filename: "currency-context.js" });
	await window.fchubMcContextReady;
	vm.runInNewContext(switcherSource, sandbox, { filename: "currency-switcher.js" });
	await window.fchubMcSwitchCurrency(currency, {});

	return {
		events,
		fetchCalls,
		historyStates,
		href: location.href,
		reloads,
		replacedUrls,
		storageCalls,
		storageValues,
	};
}

describe("currency switch reload", () => {
	it("reloads a guest through the configured URL resolver after the preference is saved", async () => {
		const result = await runSwitch();

		assert.equal(result.reloads, 1);
		assert.deepEqual(
			result.replacedUrls,
			["https://shop.test/product?money=USD&utm_source=test#plans"],
		);
		assert.equal(result.href, "https://shop.test/product?money=USD&utm_source=test#plans");
		assert.equal(JSON.parse(result.storageValues.get("fchub_mc_currency")).currency, "USD");
		assert.equal(result.events.at(-1)?.type, "fchub_mc:context_changed");
		assert.equal(result.fetchCalls.every((call) => call.options.cache === "no-store"), true);
		assert.deepEqual(result.historyStates, [{ cartDrawer: "open" }]);
	});

	it("omits a cached REST nonce for logged-out visitors", async () => {
		const result = await runSwitch({ config: { nonce: "expired-public-page-nonce" } });
		const request = result.fetchCalls.find((call) => call.options.method === "POST");

		assert.equal(Object.hasOwn(request.options.headers, "X-WP-Nonce"), false);
		assert.equal(result.reloads, 1);
	});

	it("keeps REST cookie authentication for logged-in visitors", async () => {
		const result = await runSwitch({ config: { isLoggedIn: true, nonce: "current-account-nonce" } });
		const request = result.fetchCalls.find((call) => call.options.method === "POST");

		assert.equal(request.options.headers["X-WP-Nonce"], "current-account-nonce");
		assert.deepEqual(result.replacedUrls, ["https://shop.test/product?utm_source=test#plans"]);
		assert.equal(JSON.parse(result.storageValues.get("fchub_mc_currency")).currency, "USD");
	});

	it("does not treat a bare 2xx response as persistence confirmation", async () => {
		const result = await runSwitch({ payload: { data: { code: "preference_saved" } } });

		assert.equal(result.reloads, 0);
		assert.deepEqual(result.replacedUrls, []);
		assert.equal(result.events.at(-1)?.type, "fchub_mc:context_switch_failed");
	});

	it("does not reload a shared guest URL when the explicit resolver URL cannot be prepared", async () => {
		const result = await runSwitch({ historyError: new Error("history unavailable") });

		assert.equal(result.reloads, 0);
		assert.equal(result.href, "https://shop.test/product?money=EUR&utm_source=test#plans");
		assert.deepEqual(result.events.map((event) => event.type), ["fchub_mc:context_switch_failed"]);
	});

	it("leaves the URL alone when public URL preference resolution is disabled", async () => {
		const result = await runSwitch({ config: { urlParamEnabled: false } });

		assert.equal(result.reloads, 1);
		assert.deepEqual(result.replacedUrls, []);
		assert.equal(result.href, "https://shop.test/product?money=EUR&utm_source=test#plans");
		assert.equal(JSON.parse(result.storageValues.get("fchub_mc_currency")).currency, "USD");
	});

	it("does not let unavailable local storage block a successful guest switch", async () => {
		const result = await runSwitch({ storageError: new Error("storage blocked") });

		assert.equal(result.reloads, 1);
		assert.deepEqual(
			result.replacedUrls,
			["https://shop.test/product?money=USD&utm_source=test#plans"],
		);
	});

	it("does not create browser persistence when cookie persistence is disabled", async () => {
		const result = await runSwitch({
			config: { cookiePersistenceEnabled: false, isLoggedIn: true },
		});

		assert.equal(result.reloads, 1);
		assert.equal(result.storageCalls.some((call) => call.method === "setItem"), false);
	});

	it("does not alter the URL or reload after an unsuccessful save", async () => {
		const result = await runSwitch({
			response: { ok: false, status: 409 },
			payload: { data: { persisted: false, code: "persistence_unavailable", message: "Not saved." } },
		});

		assert.equal(result.reloads, 0);
		assert.deepEqual(result.replacedUrls, []);
		assert.equal(result.href, "https://shop.test/product?money=EUR&utm_source=test#plans");
		assert.equal(result.events.at(-1)?.type, "fchub_mc:context_switch_failed");
		assert.equal(result.storageCalls.some((call) => call.method === "setItem"), false);
	});

	it("carries a confirmed guest choice through the next shared-cache hit", async () => {
		const switched = await runSwitch({ currency: "EUR" });
		const recovered = await runRecovery({
			cookie: "",
			storage: Object.fromEntries(switched.storageValues),
		});

		assert.match(recovered.fetchCalls[0]?.url, /currency=EUR&_fchub_mc=\d+$/);
		assert.equal(recovered.config.displayCurrency, "EUR");
	});
});
